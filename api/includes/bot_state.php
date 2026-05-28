<?php
/**
 * Состояние диалога с Telegram-ботом и блокировки AI-провайдеров.
 *
 * Заменяет файлы /tmp/{chat,corr,corr_data,cards_mode,import,sc,sc_data,
 * restord,soord,survey,rest_stock}_{chatId}.{txt,json} и /tmp/{gemini,
 * openrouter,groq}_blocked.* — все они теряли состояние при перезагрузке
 * сервера, имели race condition между параллельными webhook'ами и не давали
 * /menu надёжно очистить все режимы одним вызовом.
 *
 * Хранится в таблицах tg_state и tg_provider_block (миграция
 * 20260528_tg_state.sql).
 *
 * Все хелперы безопасны к отсутствию таблиц: при PDOException возвращают
 * null/false, чтобы webhook не падал и мог хотя бы дойти до сообщения
 * пользователю.
 */

/**
 * Прочитать payload текущего режима. Возвращает массив (или null если
 * режим не задан / истёк TTL / таблицы нет).
 *
 * Известные режимы:
 *   chat       — чат с отделом закупок
 *   corr       — корректировка заказа (раньше corr_ + corr_data_)
 *   cards      — режим сканера карточек (раньше cards_mode_)
 *   import     — режим загрузки файла заказа админом (раньше import_)
 *   sc         — сбор остатков (раньше sc_ + sc_data_)
 *   restord    — режим ввода заказа ресторана (раньше restord_)
 *   soord      — режим заказа поставщику (раньше soord_)
 *   survey     — режим прохождения опроса (раньше survey_)
 *   rest_stock — режим остатков склада (раньше rest_stock_)
 */
function tgStateGet($chatId, string $mode): ?array
{
    global $pdo;
    // chat_id=0 — служебный (используется для общесистемного дедупа,
    // например в tgNotifyAdminError). null/'' — точно невалидно.
    if ($chatId === null || $chatId === '') return null;
    try {
        // Сравнение TTL делаем на стороне БД: PHP в UTC, MySQL в +03:00,
        // strtotime по строке-без-TZ ошибётся на 3 часа. NOW() в MySQL и
        // expires_at в одной TZ — сравнение надёжное.
        $s = $pdo->prepare("
            SELECT payload, (expires_at IS NOT NULL AND expires_at < NOW()) AS is_expired
            FROM tg_state
            WHERE chat_id = ? AND mode = ?
            LIMIT 1
        ");
        $s->execute([(int)$chatId, $mode]);
        $row = $s->fetch();
        if (!$row) return null;
        if ((int)$row['is_expired'] === 1) {
            try { $pdo->prepare("DELETE FROM tg_state WHERE chat_id = ? AND mode = ?")->execute([(int)$chatId, $mode]); }
            catch (Throwable $e) {}
            return null;
        }
        if ($row['payload'] === null) return [];
        $decoded = json_decode((string)$row['payload'], true);
        return is_array($decoded) ? $decoded : [];
    } catch (Throwable $e) {
        error_log('[bot-state] get failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Сохранить payload текущего режима. Атомарный UPSERT — параллельные
 * webhook'и не сломают друг друга.
 *
 * @param int $ttlSeconds — через сколько секунд состояние считать
 *   неактуальным. 0 = без TTL (хранится до явной очистки).
 */
function tgStateSet($chatId, string $mode, $payload, int $ttlSeconds = 0): void
{
    global $pdo;
    // см. tgStateGet про chat_id=0.
    if ($chatId === null || $chatId === '') return;
    try {
        $payloadJson = is_array($payload) || is_object($payload)
            ? json_encode($payload, JSON_UNESCAPED_UNICODE)
            : (is_scalar($payload) ? json_encode((string)$payload) : null);
        // expires_at вычисляем в MySQL через NOW() + INTERVAL — иначе PHP в
        // UTC и MySQL в MSK расходятся на 3 часа, и TTL ведёт себя не так,
        // как ожидается (см. memory: tz_php_mysql_mismatch).
        if ($ttlSeconds > 0) {
            $pdo->prepare("
                INSERT INTO tg_state (chat_id, mode, payload, expires_at)
                VALUES (?, ?, ?, NOW() + INTERVAL ? SECOND)
                ON DUPLICATE KEY UPDATE payload = VALUES(payload), expires_at = VALUES(expires_at), updated_at = NOW()
            ")->execute([(int)$chatId, $mode, $payloadJson, $ttlSeconds]);
        } else {
            $pdo->prepare("
                INSERT INTO tg_state (chat_id, mode, payload, expires_at)
                VALUES (?, ?, ?, NULL)
                ON DUPLICATE KEY UPDATE payload = VALUES(payload), expires_at = NULL, updated_at = NOW()
            ")->execute([(int)$chatId, $mode, $payloadJson]);
        }
    } catch (Throwable $e) {
        error_log('[bot-state] set failed: ' . $e->getMessage());
    }
}

/**
 * Удалить состояние режима. Если $mode не указан — удалить ВСЕ режимы
 * этого chat_id (это нужно для /menu: одним вызовом снимаем все «застрявшие»
 * корректировки/опросы/чаты).
 */
function tgStateClear($chatId, ?string $mode = null): void
{
    global $pdo;
    if ($chatId === null || $chatId === '') return;
    try {
        if ($mode === null) {
            $pdo->prepare("DELETE FROM tg_state WHERE chat_id = ?")->execute([(int)$chatId]);
        } else {
            $pdo->prepare("DELETE FROM tg_state WHERE chat_id = ? AND mode = ?")->execute([(int)$chatId, $mode]);
        }
    } catch (Throwable $e) {
        error_log('[bot-state] clear failed: ' . $e->getMessage());
    }
}

/**
 * Возвращает true, если для chat_id есть запись режима (с непросроченным TTL).
 * Эквивалент проверки «file_exists($chatModeFile)» по старой логике.
 */
function tgStateExists($chatId, string $mode): bool
{
    return tgStateGet($chatId, $mode) !== null;
}

/**
 * Сколько секунд прошло с последнего обновления состояния. Возвращает
 * null если состояния нет. Нужно для редких случаев типа «считать чат
 * сессию активной 1 час» — TTL мы выставляем при tgStateSet, но
 * вызывающий код иногда хочет посмотреть «давно ли последнее
 * взаимодействие».
 */
function tgStateAge($chatId, string $mode): ?int
{
    global $pdo;
    if ($chatId === null || $chatId === '') return null;
    try {
        $s = $pdo->prepare("SELECT updated_at FROM tg_state WHERE chat_id = ? AND mode = ? LIMIT 1");
        $s->execute([(int)$chatId, $mode]);
        $upd = $s->fetchColumn();
        if (!$upd) return null;
        return max(0, time() - strtotime((string)$upd));
    } catch (Throwable $e) {
        return null;
    }
}

// ─── Блокировки AI-провайдеров ───

/**
 * Проверить, заблокирован ли провайдер сейчас. Заблокирован = blocked_until > NOW().
 * $model используется для groq (там несколько моделей с отдельными лимитами).
 */
function tgProviderBlocked(string $provider, string $model = ''): bool
{
    global $pdo;
    try {
        // Сравнение делаем в MySQL — см. tgStateGet про tz mismatch.
        $s = $pdo->prepare("SELECT 1 FROM tg_provider_block WHERE provider = ? AND model = ? AND blocked_until > NOW() LIMIT 1");
        $s->execute([$provider, $model]);
        return (bool)$s->fetchColumn();
    } catch (Throwable $e) {
        // если таблицы нет — считаем «не заблокирован» и пускаем дальше
        return false;
    }
}

/**
 * Пометить провайдер заблокированным до $ttlSeconds от сейчас. Атомарный
 * UPSERT — если запись уже есть, обновляем дату.
 */
function tgProviderBlock(string $provider, string $model = '', int $ttlSeconds = 3600, ?string $reason = null): void
{
    global $pdo;
    try {
        // Минимум 60 сек — защита от случайных коротких блокировок.
        // Время считаем в MySQL (одна TZ с blocked_until).
        $ttl = max(60, $ttlSeconds);
        $pdo->prepare("
            INSERT INTO tg_provider_block (provider, model, blocked_until, reason)
            VALUES (?, ?, NOW() + INTERVAL ? SECOND, ?)
            ON DUPLICATE KEY UPDATE blocked_until = VALUES(blocked_until), reason = VALUES(reason), updated_at = NOW()
        ")->execute([$provider, $model, $ttl, $reason]);
    } catch (Throwable $e) {
        error_log('[bot-state] provider block failed: ' . $e->getMessage());
    }
}

/**
 * Снять блокировку провайдера досрочно (например, при ручной разблокировке
 * админом). Сейчас не используется кодом, но полезно для отладки.
 */
function tgProviderUnblock(string $provider, string $model = ''): void
{
    global $pdo;
    try {
        $pdo->prepare("DELETE FROM tg_provider_block WHERE provider = ? AND model = ?")
            ->execute([$provider, $model]);
    } catch (Throwable $e) {}
}
