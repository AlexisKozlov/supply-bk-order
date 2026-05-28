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
        $pdo->prepare("UPDATE users SET tg_blocked_at = NOW() WHERE telegram_chat_id = ? AND tg_blocked_at IS NULL")
            ->execute([(string)$chatId]);
        $pdo->prepare("UPDATE ro_telegram_subs SET tg_blocked_at = NOW() WHERE chat_id = ? AND tg_blocked_at IS NULL")
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
        error_log("[tg-client] {$method}{$chatHint} http={$httpCode} error_code={$code}: {$desc}");
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

    return $result;
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
    $params = [
        'chat_id' => $chatId,
        'text'    => $text,
    ];
    $parseMode = $opts['parse_mode'] ?? 'HTML';
    if ($parseMode !== '') $params['parse_mode'] = $parseMode;
    if (!empty($opts['reply_markup']))         $params['reply_markup']            = is_array($opts['reply_markup']) ? $opts['reply_markup'] : json_decode((string)$opts['reply_markup'], true);
    if (!empty($opts['disable_preview']))      $params['disable_web_page_preview'] = true;
    if (!empty($opts['disable_notification'])) $params['disable_notification']     = true;
    if (!empty($opts['reply_to_message_id']))  $params['reply_to_message_id']      = (int)$opts['reply_to_message_id'];

    return tgClientCall('sendMessage', $params, $opts);
}

/**
 * Редактировать текст ранее отправленного сообщения.
 */
function tgClientEdit($chatId, $messageId, string $text, array $opts = []): array
{
    if (!$chatId || !$messageId) {
        return ['ok' => false, 'http_code' => 0, 'error_code' => null, 'description' => 'no_chat_id_or_message_id', 'result' => null, 'curl_error' => null];
    }
    $params = [
        'chat_id'    => $chatId,
        'message_id' => (int)$messageId,
        'text'       => $text,
    ];
    $parseMode = $opts['parse_mode'] ?? 'HTML';
    if ($parseMode !== '') $params['parse_mode'] = $parseMode;
    if (!empty($opts['reply_markup']))    $params['reply_markup']             = is_array($opts['reply_markup']) ? $opts['reply_markup'] : json_decode((string)$opts['reply_markup'], true);
    if (!empty($opts['disable_preview'])) $params['disable_web_page_preview'] = true;

    return tgClientCall('editMessageText', $params, $opts);
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
        $params['reply_markup'] = is_array($markup) ? $markup : json_decode((string)$markup, true);
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
            is_array($opts['reply_markup']) ? $opts['reply_markup'] : json_decode((string)$opts['reply_markup'], true),
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
