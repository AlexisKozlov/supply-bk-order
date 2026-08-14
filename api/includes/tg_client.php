<?php
/**
 * Единая точка отправки запросов в Telegram Bot API.
 *
 * Все cron-задачи, webhook-бот и обёртки вроде sendTelegramMessage из
 * helpers.php теперь ходят через tg_client. Раньше каждый файл собирал
 * свой http-вызов: одни на @file_get_contents с подавлением ошибок
 * (без проверки доставки), другие через cURL с разными таймаутами и
 * форматами ответа. Это давало молчаливые потери сообщений и пять
 * разных мест, где приходилось чинить одну и ту же логику.
 *
 * Единый результат вызова — массив:
 *   [
 *     'ok'           => bool,          // успешно ли (HTTP 200 + Telegram ok=true)
 *     'http_code'    => int,           // HTTP статус
 *     'error_code'   => ?int,          // Telegram error_code (400/403/429/…)
 *     'description'  => ?string,       // Telegram описание ошибки
 *     'result'       => ?array,        // raw result-объект из ответа (например message_id)
 *     'curl_error'   => ?string,       // транспортная ошибка cURL (timeout, dns и т.п.)
 *   ]
 *
 * Все ошибки пишутся в error_log с префиксом «[tg-client]» — единый
 * формат для grep/мониторинга.
 */

/**
 * Является ли результат вызова признаком «пользователь больше недоступен»?
 * Покрывает три случая, которые Telegram сообщает кодами и описаниями:
 *   403 Forbidden: bot was blocked by the user
 *   400 Bad Request: chat not found
 *   400 Bad Request: user is deactivated
 * Остальные ошибки (429 — лимиты, 500 — сбой Telegram, таймауты сети) НЕ
 * считаются блокировкой — повторим в следующий раз.
 */
function tgIsBlockingError(array $result): bool
{
    $code = $result['error_code'] ?? null;
    if ($code === 403) return true;
    if ($code === 400) {
        $desc = strtolower((string)($result['description'] ?? ''));
        if (str_contains($desc, 'chat not found'))       return true;
        if (str_contains($desc, 'user is deactivated'))  return true;
        if (str_contains($desc, 'bot was blocked'))      return true;
    }
    return false;
}

/**
 * Запись в журнал отправок. Вызывается из tgClientCall и tgClientSendDocument
 * после каждого запроса в Telegram API. Используется страницей мониторинга в
 * админке (/admin?tab=tg-monitor) и для отладки.
 *
 * Защита от рекурсии: если сам INSERT упадёт, логирование молча игнорируется
 * (чтобы ошибка в логе не превратилась в фатал самого клиента).
 *
 * Если в опциях передали 'no_log' => true (см. cleanup-крон), запись пропускается.
 */
function tgLogSend(string $method, $chatId, array $result, array $opts = []): void
{
    if (!empty($opts['no_log'])) return;
    try {
        $pdo = $opts['pdo'] ?? ($GLOBALS['pdo'] ?? null);
        if (!($pdo instanceof PDO)) return;
        $errText = $result['description'] ?? $result['curl_error'] ?? null;
        // Начало сообщения: при разборе жалоб «пришла ерунда / ничего не
        // пришло» по одному методу и коду ошибки не понять, о каком экране речь.
        $preview = $opts['log_text'] ?? null;
        if ($preview !== null) {
            $preview = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$preview)));
            $preview = mb_substr($preview, 0, 200);
        }
        $pdo->prepare("
            INSERT INTO tg_send_log (method, chat_id, ok, http_code, error_code, error_text, text_preview)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $method,
            $chatId ? (int)$chatId : null,
            !empty($result['ok']) ? 1 : 0,
            (int)($result['http_code'] ?? 0),
            isset($result['error_code']) ? (int)$result['error_code'] : null,
            $errText !== null ? mb_substr((string)$errText, 0, 255) : null,
            $preview,
        ]);
    } catch (Throwable $e) {
        // молча — иначе попытка логирования сломает клиент
    }
}

/**
 * Помечает chat_id как «заблокировал бота» в обеих таблицах с подписками:
 * users.telegram_chat_id и ro_telegram_subs.chat_id. Дата ставится только
 * если её ещё нет — чтобы знать момент первой блокировки.
 *
 * Сбрасывается обратно в null в api/telegram_bot.php при любом входящем
 * сообщении/нажатии кнопки от этого chat_id.
 *
 * Тихо проглатывает любые ошибки — это побочное действие, не должно
 * мешать основному вызову.
 */
function tgMarkChatBlocked(PDO $pdo, $chatId, ?string $reason = null): void
{
    if (!$chatId) return;
    try {
        // tg_blocked_at — дата ПЕРВОЙ блокировки, её показывают закупкам
        // в «Каналах связи», поэтому ставим только один раз.
        // tg_blocked_confirmed_at обновляем каждый раз: по ней считается,
        // когда пробовать снова. Без этого окно «через 30 дней попробуем»
        // открывалось навсегда и портал слал в закрытую дверь бесконечно.
        $pdo->prepare("UPDATE users SET tg_blocked_at = COALESCE(tg_blocked_at, NOW()), tg_blocked_confirmed_at = NOW() WHERE telegram_chat_id = ?")
            ->execute([(string)$chatId]);
        $pdo->prepare("UPDATE ro_telegram_subs SET tg_blocked_at = COALESCE(tg_blocked_at, NOW()), tg_blocked_confirmed_at = NOW() WHERE chat_id = ?")
            ->execute([(int)$chatId]);
        error_log("[tg-client] marked chat={$chatId} blocked" . ($reason ? " ({$reason})" : ''));
    } catch (Throwable $e) {
        error_log("[tg-client] mark blocked failed chat={$chatId}: " . $e->getMessage());
    }
}

/**
 * Достаёт bot token из опций, глобального скоупа или $_ENV.
 * Возвращает пустую строку, если токен не настроен — вызывающий
 * сам решит, считать это ошибкой или тихо пропустить (как раньше).
 */
function tgClientResolveToken(array $opts): string
{
    if (!empty($opts['token'])) return (string)$opts['token'];
    if (isset($GLOBALS['BOT_TOKEN']) && $GLOBALS['BOT_TOKEN']) return (string)$GLOBALS['BOT_TOKEN'];
    return (string)($_ENV['TELEGRAM_BOT_TOKEN'] ?? getenv('TELEGRAM_BOT_TOKEN') ?: '');
}

/**
 * Базовый вызов любого метода Telegram Bot API.
 * Используется внутренне всеми public-функциями ниже.
 *
 * @param string $method   например 'sendMessage', 'editMessageText'
 * @param array  $params   JSON-сериализуемые параметры запроса
 * @param array  $opts     ['token' => ?, 'timeout' => ?, 'connect_timeout' => ?]
 */
/**
 * Заблокировал ли этот чат бота.
 *
 * Зачем: метку блокировки ставит tgMarkChatBlocked, но учитывали её только
 * напоминания о заявках (cron_delivery_reminders). Остальные 20+ мест слали
 * как ни в чём не бывало — за неделю 58 сообщений ушло в никуда, последнее
 * в тот же день. Чинить каждый запрос по отдельности бессмысленно: в новом
 * коде фильтр снова забудут. Поэтому проверка живёт здесь, в единственной
 * точке отправки.
 *
 * Через 30 дней пробуем снова: человек мог разблокировать бота, а входящего
 * сообщения от него так и не было (метку сбрасывает telegram_bot.php).
 *
 * Ответ кэшируется на время процесса — рассылка по сотне чатов не должна
 * превращаться в сотню запросов к базе.
 */
function tgChatIsBlocked(PDO $pdo, $chatId): bool
{
    static $cache = [];
    $key = (string)$chatId;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $st = $pdo->prepare("
            SELECT 1 FROM ro_telegram_subs
            WHERE chat_id = ? AND tg_blocked_confirmed_at IS NOT NULL
              AND tg_blocked_confirmed_at > NOW() - INTERVAL 30 DAY
            UNION ALL
            SELECT 1 FROM users
            WHERE telegram_chat_id = ? AND tg_blocked_confirmed_at IS NOT NULL
              AND tg_blocked_confirmed_at > NOW() - INTERVAL 30 DAY
            LIMIT 1
        ");
        $st->execute([(int)$chatId, (string)$chatId]);
        $cache[$key] = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        // Не смогли проверить — лучше отправить, чем молча потерять сообщение.
        $cache[$key] = false;
    }
    return $cache[$key];
}

function tgClientCall(string $method, array $params, array $opts = []): array
{
    $token = tgClientResolveToken($opts);
    if ($token === '') {
        return [
            'ok' => false, 'http_code' => 0,
            'error_code' => null, 'description' => 'no_token',
            'result' => null, 'curl_error' => null,
        ];
    }

    // Не долбим тех, кто заблокировал бота: сообщение всё равно не дойдёт,
    // а Telegram считает такие попытки за нами. Проверяем только отправку
    // (send*): ответ на нажатие кнопки приходит от активного человека.
    if (str_starts_with($method, 'send') && !empty($params['chat_id'])) {
        $pdoCheck = $opts['pdo'] ?? ($GLOBALS['pdo'] ?? null);
        if ($pdoCheck instanceof PDO && tgChatIsBlocked($pdoCheck, $params['chat_id'])) {
            $res = [
                'ok' => false, 'http_code' => 0, 'error_code' => null,
                'description' => 'skipped_blocked', 'result' => null, 'curl_error' => null,
            ];
            tgLogSend($method, $params['chat_id'], $res, $opts);
            return $res;
        }
    }

    $timeout        = (int)($opts['timeout'] ?? 10);
    $connectTimeout = (int)($opts['connect_timeout'] ?? 3);

    $url = "https://api.telegram.org/bot{$token}/{$method}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
    ]);
    $raw      = curl_exec($ch);
    $curlErr  = curl_error($ch) ?: null;
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $chatHint = isset($params['chat_id']) ? " chat={$params['chat_id']}" : '';

    if ($raw === false || $curlErr) {
        error_log("[tg-client] {$method}{$chatHint} curl_error: " . ($curlErr ?? 'unknown'));
        return [
            'ok' => false, 'http_code' => $httpCode,
            'error_code' => null, 'description' => null,
            'result' => null, 'curl_error' => $curlErr,
        ];
    }

    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        error_log("[tg-client] {$method}{$chatHint} bad_response http={$httpCode}: " . substr((string)$raw, 0, 200));
        return [
            'ok' => false, 'http_code' => $httpCode,
            'error_code' => null, 'description' => 'bad_response',
            'result' => null, 'curl_error' => null,
        ];
    }

    $ok = !empty($data['ok']);
    if (!$ok) {
        $code = isset($data['error_code']) ? (int)$data['error_code'] : null;
        $desc = (string)($data['description'] ?? 'no_description');
        // «message is not modified» — не ошибка: человек нажал кнопку, а экран
        // получился тот же самый. В журнал отправок (tg_send_log) запись всё
        // равно попадёт, а error_log не засоряем.
        if (stripos($desc, 'message is not modified') === false) {
            error_log("[tg-client] {$method}{$chatHint} http={$httpCode} error_code={$code}: {$desc}");
        }
    }

    $result = [
        'ok'          => $ok,
        'http_code'   => $httpCode,
        'error_code'  => isset($data['error_code']) ? (int)$data['error_code'] : null,
        'description' => isset($data['description']) ? (string)$data['description'] : null,
        'result'      => isset($data['result']) && is_array($data['result']) ? $data['result'] : null,
        'curl_error'  => null,
    ];

    // Авто-маркировка заблокированных чатов. Если вызывающий передал PDO
    // в опциях — сами обновим обе таблицы при характерных ошибках. Так
    // одна правка на 8 файлов снимает повторные попытки слать в пустоту.
    if (!empty($opts['pdo']) && $opts['pdo'] instanceof PDO
        && isset($params['chat_id']) && tgIsBlockingError($result)) {
        tgMarkChatBlocked($opts['pdo'], $params['chat_id'], $result['description'] ?? null);
    }

    // Журнал отправок — для /admin?tab=tg-monitor.
    tgLogSend($method, $params['chat_id'] ?? null, $result, $opts);

    return $result;
}

/**
 * Telegram отвергает КЛАВИАТУРУ ЦЕЛИКОМ, если хоть у одной кнопки
 * callback_data длиннее 64 байт — сообщение уходит без кнопок, и человек
 * упирается в тупик. Так уже ломались кнопки по ресторанам Пицца Стар
 * (номера с 1001 длиннее обычных).
 *
 * Здесь такую кнопку убираем и пишем в лог: остальные кнопки продолжат
 * работать, а причина будет видна разработчику.
 */
function tgSanitizeMarkup($markup)
{
    if (!is_array($markup) || empty($markup['inline_keyboard'])) return $markup;

    $rows = [];
    foreach ($markup['inline_keyboard'] as $row) {
        if (!is_array($row)) continue;
        $keep = [];
        foreach ($row as $btn) {
            $data = $btn['callback_data'] ?? null;
            if (is_string($data) && strlen($data) > 64) {
                error_log('[tg] кнопка отброшена: callback_data ' . strlen($data) . ' байт — «'
                    . substr($data, 0, 80) . '» (текст: ' . ($btn['text'] ?? '') . ')');
                continue;
            }
            $keep[] = $btn;
        }
        if ($keep) $rows[] = $keep;
    }
    $markup['inline_keyboard'] = $rows;
    return $markup;
}

/**
 * Предел Telegram на длину сообщения. Всё, что длиннее, API отклоняет
 * целиком — сообщение просто не доходит («text is too long»).
 */
const TG_MAX_TEXT = 4096;

/**
 * Режет длинный текст на части, стараясь рвать по абзацам, затем по строкам,
 * в последнюю очередь — по словам. HTML-разметку не ломаем грубо: если в
 * куске остался незакрытый тег, отдаём кусок как есть — Telegram переварит
 * простые случаи, а сложные всё равно приходят из наших же шаблонов.
 *
 * @return string[] Список кусков, каждый не длиннее $limit.
 */
const TG_MAX_PARTS = 3;

/**
 * Длина текста так, как её считает Telegram — в единицах UTF-16.
 * Эмодзи и прочие редкие знаки занимают ДВЕ единицы, а mb_strlen
 * считает их за одну. Из-за этого сообщение на «4090 символов» с эмодзи
 * Telegram отвергал с «text is too long», и человек не видел ответа.
 */
function tgTextLen(string $text): int
{
    return (int)(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2);
}

function tgSplitText(string $text, int $limit = TG_MAX_TEXT, int $maxParts = TG_MAX_PARTS): array
{
    // Режем по символам, а лимит у Telegram — в единицах UTF-16. Пересчитываем
    // лимит по фактической доле «двойных» знаков в этом тексте.
    $chars = mb_strlen($text);
    $units = tgTextLen($text);
    if ($chars > 0 && $units > $chars) {
        $limit = max(1000, (int)floor($limit * $chars / $units));
    }
    if ($chars <= $limit) return [$text];

    $parts = [];
    $rest = $text;
    // Оставляем запас под пометку «продолжение».
    $room = $limit - 20;

    while (mb_strlen($rest) > $limit) {
        $chunk = mb_substr($rest, 0, $room);
        $cut = mb_strrpos($chunk, "\n\n");
        if ($cut === false || $cut < $room * 0.5) $cut = mb_strrpos($chunk, "\n");
        if ($cut === false || $cut < $room * 0.5) $cut = mb_strrpos($chunk, ' ');
        if ($cut === false || $cut < 1) $cut = $room;

        $parts[] = rtrim(mb_substr($rest, 0, $cut));
        $rest = ltrim(mb_substr($rest, $cut));
    }
    if ($rest !== '') $parts[] = $rest;

    // Больше трёх сообщений подряд — это уже не ответ, а лавина. Обрезаем и
    // честно говорим, что показано не всё.
    if (count($parts) > $maxParts) {
        $parts = array_slice($parts, 0, $maxParts);
        $parts[$maxParts - 1] = rtrim($parts[$maxParts - 1])
            . "\n\n<i>…показано не всё — полный список в портале.</i>";
    }

    return $parts;
}

/**
 * Послать текстовое сообщение.
 *
 * $opts:
 *   parse_mode             — 'HTML' (default), 'MarkdownV2', '' для plain
 *   reply_markup           — массив (inline_keyboard / keyboard и т.п.), будет json-encoded
 *   disable_preview        — bool, отключить превью ссылок
 *   disable_notification   — bool, тихое сообщение
 *   reply_to_message_id    — int
 *   token, timeout         — переопределение, если нужно
 */
function tgClientSend($chatId, string $text, array $opts = []): array
{
    if (!$chatId) {
        return ['ok' => false, 'http_code' => 0, 'error_code' => null, 'description' => 'no_chat_id', 'result' => null, 'curl_error' => null];
    }
    // Длинный текст Telegram не принимает вовсе — режем на части и шлём
    // подряд. Кнопки вешаем на последнюю, чтобы они были внизу переписки.
    $chunks = tgSplitText($text);
    $last = count($chunks) - 1;
    $result = null;
    foreach ($chunks as $i => $chunk) {
        $params = [
            'chat_id' => $chatId,
            'text'    => $chunk,
        ];
        $parseMode = $opts['parse_mode'] ?? 'HTML';
        if ($parseMode !== '') $params['parse_mode'] = $parseMode;
        if (!empty($opts['reply_markup']) && $i === $last) $params['reply_markup'] = tgSanitizeMarkup(is_array($opts['reply_markup']) ? $opts['reply_markup'] : json_decode((string)$opts['reply_markup'], true));
        if (!empty($opts['disable_preview']))      $params['disable_web_page_preview'] = true;
        if (!empty($opts['disable_notification'])) $params['disable_notification']     = true;
        if (!empty($opts['reply_to_message_id']) && $i === 0) $params['reply_to_message_id'] = (int)$opts['reply_to_message_id'];

        $result = tgClientCall('sendMessage', $params, ['log_text' => $chunk] + $opts);
        // Дальше слать нет смысла: чат недоступен или бот заблокирован.
        if (!$result['ok'] && in_array((int)($result['error_code'] ?? 0), [403, 400], true)) break;
    }

    return $result;
}

/**
 * Редактировать текст ранее отправленного сообщения.
 */
function tgClientEdit($chatId, $messageId, string $text, array $opts = []): array
{
    if (!$chatId || !$messageId) {
        return ['ok' => false, 'http_code' => 0, 'error_code' => null, 'description' => 'no_chat_id_or_message_id', 'result' => null, 'curl_error' => null];
    }
    // Редактировать можно только одно сообщение: в него кладём первый кусок,
    // остальное досылаем следом. Иначе длинный ответ не доходил совсем.
    $chunks = tgSplitText($text);
    $tail = array_slice($chunks, 1);

    $params = [
        'chat_id'    => $chatId,
        'message_id' => (int)$messageId,
        'text'       => $chunks[0],
    ];
    $parseMode = $opts['parse_mode'] ?? 'HTML';
    if ($parseMode !== '') $params['parse_mode'] = $parseMode;
    // Кнопки должны остаться под последним куском переписки.
    if (!empty($opts['reply_markup']) && !$tail) $params['reply_markup'] = tgSanitizeMarkup(is_array($opts['reply_markup']) ? $opts['reply_markup'] : json_decode((string)$opts['reply_markup'], true));
    if (!empty($opts['disable_preview'])) $params['disable_web_page_preview'] = true;

    $result = tgClientCall('editMessageText', $params, ['log_text' => $chunks[0]] + $opts);

    foreach ($tail as $i => $chunk) {
        $sendOpts = $opts;
        // Кнопки — только на самом последнем сообщении.
        if ($i !== count($tail) - 1) unset($sendOpts['reply_markup']);
        tgClientSend($chatId, $chunk, $sendOpts);
    }

    return $result;
}

/**
 * Сменить только кнопки на ранее отправленном сообщении.
 * Передайте $markup = null или [] чтобы убрать кнопки.
 */
function tgClientEditReplyMarkup($chatId, $messageId, $markup = null, array $opts = []): array
{
    if (!$chatId || !$messageId) {
        return ['ok' => false, 'http_code' => 0, 'error_code' => null, 'description' => 'no_chat_id_or_message_id', 'result' => null, 'curl_error' => null];
    }
    $params = [
        'chat_id'    => $chatId,
        'message_id' => (int)$messageId,
    ];
    if ($markup === null || $markup === []) {
        $params['reply_markup'] = ['inline_keyboard' => []];
    } else {
        $params['reply_markup'] = tgSanitizeMarkup(is_array($markup) ? $markup : json_decode((string)$markup, true));
    }
    return tgClientCall('editMessageReplyMarkup', $params, $opts);
}

/**
 * Ответить на нажатие inline-кнопки (callback). Если не вызвать —
 * у пользователя будет крутиться «часики» на кнопке до таймаута.
 */
function tgClientAnswerCallback(string $callbackId, string $text = '', bool $showAlert = false, array $opts = []): array
{
    $params = ['callback_query_id' => $callbackId];
    if ($text !== '') $params['text'] = $text;
    if ($showAlert)   $params['show_alert'] = true;
    return tgClientCall('answerCallbackQuery', $params, $opts);
}

/**
 * Удалить сообщение бота из чата.
 */
function tgClientDelete($chatId, $messageId, array $opts = []): array
{
    if (!$chatId || !$messageId) {
        return ['ok' => false, 'http_code' => 0, 'error_code' => null, 'description' => 'no_chat_id_or_message_id', 'result' => null, 'curl_error' => null];
    }
    return tgClientCall('deleteMessage', [
        'chat_id'    => $chatId,
        'message_id' => (int)$messageId,
    ], $opts);
}

/**
 * Показать пользователю индикатор «печатает…» в чате.
 */
function tgClientTyping($chatId, array $opts = []): array
{
    if (!$chatId) {
        return ['ok' => false, 'http_code' => 0, 'error_code' => null, 'description' => 'no_chat_id', 'result' => null, 'curl_error' => null];
    }
    return tgClientCall('sendChatAction', [
        'chat_id' => $chatId,
        'action'  => $opts['action'] ?? 'typing',
    ], $opts);
}

/**
 * Послать файл (xlsx, csv, pdf и т.п.) как документ.
 * $content — бинарное содержимое; временно пишем на диск, чтобы
 * передать через CURLFile (так Telegram надёжно принимает мультипарт).
 *
 * $opts:
 *   caption       — подпись под файлом (HTML)
 *   parse_mode    — для caption, default HTML
 *   mime          — MIME-тип, default application/octet-stream
 *   reply_markup  — кнопки под документом
 *   timeout       — default 30 (документы тяжелее текста)
 */
function tgClientSendDocument($chatId, string $filename, string $content, array $opts = []): array
{
    if (!$chatId) {
        return ['ok' => false, 'http_code' => 0, 'error_code' => null, 'description' => 'no_chat_id', 'result' => null, 'curl_error' => null];
    }
    $token = tgClientResolveToken($opts);
    if ($token === '') {
        return ['ok' => false, 'http_code' => 0, 'error_code' => null, 'description' => 'no_token', 'result' => null, 'curl_error' => null];
    }

    $mime    = (string)($opts['mime'] ?? 'application/octet-stream');
    $timeout = (int)($opts['timeout'] ?? 30);

    // Временный файл на диске под передачу мультипартом. cURL читает его
    // потоково и удаляет идентификатор после curl_exec — поэтому unlink
    // после. Если содержимое пустое — Telegram отдаст 400, но дёргать его
    // всё равно нет смысла.
    $tmp = tempnam(sys_get_temp_dir(), 'tg_doc_');
    if ($tmp === false) {
        return ['ok' => false, 'http_code' => 0, 'error_code' => null, 'description' => 'tempnam_failed', 'result' => null, 'curl_error' => null];
    }
    file_put_contents($tmp, $content);

    $post = [
        'chat_id'  => $chatId,
        'document' => new CURLFile($tmp, $mime, $filename),
    ];
    if (!empty($opts['caption'])) {
        $post['caption']    = (string)$opts['caption'];
        $post['parse_mode'] = (string)($opts['parse_mode'] ?? 'HTML');
    }
    if (!empty($opts['reply_markup'])) {
        $post['reply_markup'] = json_encode(
            tgSanitizeMarkup(is_array($opts['reply_markup']) ? $opts['reply_markup'] : json_decode((string)$opts['reply_markup'], true)),
            JSON_UNESCAPED_UNICODE
        );
    }

    $url = "https://api.telegram.org/bot{$token}/sendDocument";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $raw      = curl_exec($ch);
    $curlErr  = curl_error($ch) ?: null;
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmp);

    if ($raw === false || $curlErr) {
        error_log("[tg-client] sendDocument chat={$chatId} file={$filename} curl_error: " . ($curlErr ?? 'unknown'));
        return ['ok' => false, 'http_code' => $httpCode, 'error_code' => null, 'description' => null, 'result' => null, 'curl_error' => $curlErr];
    }

    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        error_log("[tg-client] sendDocument chat={$chatId} file={$filename} bad_response http={$httpCode}: " . substr((string)$raw, 0, 200));
        return ['ok' => false, 'http_code' => $httpCode, 'error_code' => null, 'description' => 'bad_response', 'result' => null, 'curl_error' => null];
    }

    $ok = !empty($data['ok']);
    if (!$ok) {
        $code = isset($data['error_code']) ? (int)$data['error_code'] : null;
        $desc = (string)($data['description'] ?? 'no_description');
        error_log("[tg-client] sendDocument chat={$chatId} file={$filename} http={$httpCode} error_code={$code}: {$desc}");
    }

    $result = [
        'ok'          => $ok,
        'http_code'   => $httpCode,
        'error_code'  => isset($data['error_code']) ? (int)$data['error_code'] : null,
        'description' => isset($data['description']) ? (string)$data['description'] : null,
        'result'      => isset($data['result']) && is_array($data['result']) ? $data['result'] : null,
        'curl_error'  => null,
    ];
    // Авто-маркировка — как в tgClientCall, см. там подробный комментарий.
    if (!empty($opts['pdo']) && $opts['pdo'] instanceof PDO && tgIsBlockingError($result)) {
        tgMarkChatBlocked($opts['pdo'], $chatId, $result['description'] ?? null);
    }
    // Журнал.
    tgLogSend('sendDocument', $chatId, $result, $opts);
    return $result;
}

/**
 * Массовая рассылка одного текста списку chat_id. Уважает лимит
 * Telegram ~30 msg/sec для broadcast-сообщений: между вызовами стоит
 * sleep 35мс (по умолчанию). Параметр $opts['rate_delay_us'] позволяет
 * подкрутить под себя.
 *
 * Возвращает: ['sent' => int, 'failed' => int, 'blocked' => int[]] —
 * blocked — список chat_id, которые вернули 403 (бот заблокирован).
 * Вызывающий сам решает, что с этим списком делать.
 */
function tgClientSendBulk(array $chatIds, string $text, array $opts = []): array
{
    $sent = 0;
    $failed = 0;
    $blocked = [];
    $delay = (int)($opts['rate_delay_us'] ?? 35000); // 35мс ≈ 28 msg/sec
    foreach ($chatIds as $cid) {
        $r = tgClientSend($cid, $text, $opts);
        if ($r['ok']) {
            $sent++;
        } else {
            $failed++;
            if ($r['error_code'] === 403) $blocked[] = $cid;
        }
        if ($delay > 0) usleep($delay);
    }
    return ['sent' => $sent, 'failed' => $failed, 'blocked' => $blocked];
}
