<?php
date_default_timezone_set('Europe/Minsk'); // Минск (+03:00) — совпадает с TZ MariaDB
/**
 * Cron: импорт данных через email.
 *
 * Запуск каждые 5 минут под www-data:
 *   php /var/www/bk-calc/api/cron_email_import.php
 *
 * Логика:
 *   - IMAP-подключение к ящику import@ (см. .env IMAP_*).
 *   - Берём UNSEEN-сообщения, по каждому:
 *     • Message-Id: если уже в email_imports — помечаем Seen и пропускаем
 *       (защита от повторной обработки).
 *     • Отправитель не в email_import_senders → запись 'rejected',
 *       причина 'sender_not_whitelisted'. Письмо тоже помечаем Seen,
 *       чтобы не крутить его бесконечно.
 *     • Нет xlsx/xls/csv-вложения → запись 'rejected', 'no_attachment'.
 *     • Вложение больше лимита → 'rejected', 'too_large'.
 *     • Всё ок → файл сохраняем в api/uploads/email_imports/, запись
 *       'pending', ждём ручного применения через админку.
 *   - Письма ВСЕГДА помечаем как прочитанные после обработки. Лог в БД.
 *
 * Размер лимита — 10 МБ. Допустимые расширения вложений: xlsx, xls, csv.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/includes/cron_lock.php';
$lock = cronAcquireLock(__DIR__ . '/cron_email_import.lock', 600);
if (!$lock['fp']) { echo "Already running\n"; exit; }
$lockFp = $lock['fp'];
$killedStalePid = $lock['killed_pid'];
set_time_limit(120);
cronImapTimeouts();

$envFile = '/var/www/bk-calc-secrets/.env';
if (!file_exists($envFile)) exit("no .env\n");
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    if (!str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $_ENV[trim($k)] = trim($v);
}

$dsn = 'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost')
     . ';dbname=' . ($_ENV['DB_NAME'] ?? 'supply_bk') . ';charset=utf8mb4';
$pdo = new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$IMAP_HOST = $_ENV['IMAP_HOST'] ?? '';
$IMAP_PORT = (int)($_ENV['IMAP_PORT'] ?? 993);
$IMAP_USER = $_ENV['IMAP_USER'] ?? '';
$IMAP_PASS = $_ENV['IMAP_PASS'] ?? '';
if ($IMAP_HOST === '' || $IMAP_USER === '' || $IMAP_PASS === '') {
    exit("[email-import] IMAP не сконфигурирован в .env\n");
}

$UPLOAD_DIR = __DIR__ . '/uploads/email_imports';
$REL_DIR    = 'uploads/email_imports';
if (!is_dir($UPLOAD_DIR)) mkdir($UPLOAD_DIR, 0775, true);

$MAX_BYTES = 10 * 1024 * 1024; // 10 МБ
$ALLOWED_EXT = ['xlsx', 'xls', 'csv'];

$ts = fn() => date('Y-m-d H:i:s');
$log = function ($msg) use ($ts) { echo '[' . $ts() . '] ' . $msg . "\n"; };

if ($killedStalePid) {
    $log('ВНИМАНИЕ: снят зависший процесс PID ' . $killedStalePid . ' (висел дольше 10 мин)');
    error_log('[email-import] killed stale cron process ' . $killedStalePid);
}

$mboxRef = '{' . $IMAP_HOST . ':' . $IMAP_PORT . '/imap/ssl}INBOX';
$mbox = @imap_open($mboxRef, $IMAP_USER, $IMAP_PASS);
if (!$mbox) {
    $log('IMAP connect failed: ' . imap_last_error());
    exit(1);
}

$unseen = imap_search($mbox, 'UNSEEN');
if (!$unseen) {
    $log('новых писем нет');
    imap_close($mbox);
    exit;
}

$log('найдено новых писем: ' . count($unseen));

// Декодер MIME-строк в UTF-8.
function decodeMime($s) {
    if (!$s) return '';
    $parts = imap_mime_header_decode($s);
    $out = '';
    foreach ($parts as $p) {
        $text = $p->text;
        $charset = strtolower($p->charset);
        if ($charset && $charset !== 'utf-8' && $charset !== 'default') {
            $conv = @iconv($charset, 'UTF-8//IGNORE', $text);
            if ($conv !== false) $text = $conv;
        }
        $out .= $text;
    }
    return $out;
}

// Достать массив вложений из структуры письма.
// Возвращает массив ['filename' => ..., 'data' => binary, 'ext' => ...].
function collectAttachments($mbox, $msgNum, $structure, $partNum = '') {
    $out = [];

    $params = [];
    if (!empty($structure->parameters)) foreach ($structure->parameters as $p) $params[strtolower($p->attribute)] = $p->value;
    if (!empty($structure->dparameters)) foreach ($structure->dparameters as $p) $params[strtolower($p->attribute)] = $p->value;

    $filename = $params['filename'] ?? $params['name'] ?? '';
    $isAttachment = !empty($structure->disposition) && strtolower($structure->disposition) === 'attachment';
    // Иногда vложения помечены как inline или вообще без disposition — берём по filename.
    if (!$isAttachment && $filename === '') {
        // не вложение — спускаемся в parts
    }

    if ($filename !== '') {
        $filename = decodeMime($filename);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $data = imap_fetchbody($mbox, $msgNum, $partNum ?: '1');
        // Декодируем по encoding.
        $enc = $structure->encoding ?? 0;
        if ($enc == 3) $data = base64_decode($data);
        elseif ($enc == 4) $data = quoted_printable_decode($data);
        $out[] = ['filename' => $filename, 'data' => $data, 'ext' => $ext];
    }

    if (!empty($structure->parts)) {
        foreach ($structure->parts as $i => $sub) {
            $childPart = $partNum === '' ? ($i + 1) : ($partNum . '.' . ($i + 1));
            $out = array_merge($out, collectAttachments($mbox, $msgNum, $sub, (string)$childPart));
        }
    }
    return $out;
}

$insertStmt = $pdo->prepare("
    INSERT INTO email_imports
        (message_id, from_email, from_name, subject, received_at, type, legal_entity,
         file_name, file_path, size_bytes, status, notes)
    VALUES
        (:msg_id, :from_email, :from_name, :subject, :received_at, :type, :legal_entity,
         :file_name, :file_path, :size_bytes, :status, :notes)
");

$processed = 0;
$saved = 0;
$rejected = 0;
$savedItems = []; // для TG-сводки

foreach ($unseen as $msgNum) {
    $processed++;
    try {
        $header = imap_headerinfo($mbox, $msgNum);
        $rawHeader = imap_fetchheader($mbox, $msgNum);

        $messageId = null;
        if (preg_match('/^Message-ID:\s*(<[^>]+>)/mi', $rawHeader, $m)) {
            $messageId = trim($m[1]);
        }
        $fromEmail = '';
        $fromName  = '';
        if (!empty($header->from) && is_array($header->from)) {
            $first = $header->from[0];
            $fromEmail = strtolower(trim(($first->mailbox ?? '') . '@' . ($first->host ?? '')));
            $fromName  = decodeMime($first->personal ?? '');
        }
        $subject = decodeMime($header->subject ?? '');
        $receivedAt = isset($header->udate) ? date('Y-m-d H:i:s', $header->udate) : date('Y-m-d H:i:s');

        // Защита от повторов — Message-Id уже в БД.
        if ($messageId) {
            $dup = $pdo->prepare("SELECT id FROM email_imports WHERE message_id = ? LIMIT 1");
            $dup->execute([$messageId]);
            if ($dup->fetchColumn()) {
                $log("дубль: $messageId — пропуск, помечаем seen");
                imap_setflag_full($mbox, (string)$msgNum, '\\Seen');
                continue;
            }
        }

        // Проверка отправителя в whitelist.
        $sndStmt = $pdo->prepare("SELECT type, legal_entity FROM email_import_senders WHERE email = ? AND is_active = 1 LIMIT 1");
        $sndStmt->execute([$fromEmail]);
        $sender = $sndStmt->fetch();

        if (!$sender) {
            $insertStmt->execute([
                ':msg_id' => $messageId,
                ':from_email' => $fromEmail,
                ':from_name' => $fromName,
                ':subject' => mb_substr((string)$subject, 0, 500),
                ':received_at' => $receivedAt,
                ':type' => 'unknown',
                ':legal_entity' => null,
                ':file_name' => null,
                ':file_path' => null,
                ':size_bytes' => null,
                ':status' => 'rejected',
                ':notes' => 'sender_not_whitelisted',
            ]);
            $rejected++;
            $log("отправитель не в whitelist: $fromEmail");
            imap_setflag_full($mbox, (string)$msgNum, '\\Seen');
            continue;
        }

        $type = $sender['type'] ?: 'restaurant_sales';
        $legalEntity = $sender['legal_entity'] ?: null;

        // Достаём вложение.
        $structure = imap_fetchstructure($mbox, $msgNum);
        $atts = collectAttachments($mbox, $msgNum, $structure);
        $att = null;
        foreach ($atts as $a) {
            if (in_array($a['ext'], $ALLOWED_EXT, true) && strlen($a['data']) > 0) { $att = $a; break; }
        }

        if (!$att) {
            $insertStmt->execute([
                ':msg_id' => $messageId,
                ':from_email' => $fromEmail,
                ':from_name' => $fromName,
                ':subject' => mb_substr((string)$subject, 0, 500),
                ':received_at' => $receivedAt,
                ':type' => $type,
                ':legal_entity' => $legalEntity,
                ':file_name' => null,
                ':file_path' => null,
                ':size_bytes' => null,
                ':status' => 'rejected',
                ':notes' => 'no_attachment',
            ]);
            $rejected++;
            $log("нет подходящего вложения от $fromEmail");
            imap_setflag_full($mbox, (string)$msgNum, '\\Seen');
            continue;
        }

        if (strlen($att['data']) > $MAX_BYTES) {
            $insertStmt->execute([
                ':msg_id' => $messageId,
                ':from_email' => $fromEmail,
                ':from_name' => $fromName,
                ':subject' => mb_substr((string)$subject, 0, 500),
                ':received_at' => $receivedAt,
                ':type' => $type,
                ':legal_entity' => $legalEntity,
                ':file_name' => mb_substr((string)$att['filename'], 0, 255),
                ':file_path' => null,
                ':size_bytes' => strlen($att['data']),
                ':status' => 'rejected',
                ':notes' => 'too_large',
            ]);
            $rejected++;
            $log("слишком большое вложение от $fromEmail (" . strlen($att['data']) . " байт)");
            imap_setflag_full($mbox, (string)$msgNum, '\\Seen');
            continue;
        }

        // Сохраняем файл на диск с безопасным именем.
        $safeName = preg_replace('/[^a-z0-9]+/i', '_', pathinfo($att['filename'], PATHINFO_FILENAME));
        $safeName = mb_substr($safeName, 0, 60);
        $ext = $att['ext'];
        $random = bin2hex(random_bytes(6));
        $diskName = date('Ymd_His') . '_' . $random . ($safeName ? '_' . $safeName : '') . '.' . $ext;
        $diskPath = $UPLOAD_DIR . '/' . $diskName;
        file_put_contents($diskPath, $att['data']);
        chmod($diskPath, 0640);
        // Cron запускается от root → файл будет недоступен PHP-FPM (www-data).
        // Переводим владельца принудительно. Если уже www-data — chown вернёт ошибку,
        // её игнорируем.
        @chown($diskPath, 'www-data');
        @chgrp($diskPath, 'www-data');

        $relPath = $REL_DIR . '/' . $diskName;

        $insertStmt->execute([
            ':msg_id' => $messageId,
            ':from_email' => $fromEmail,
            ':from_name' => $fromName,
            ':subject' => mb_substr((string)$subject, 0, 500),
            ':received_at' => $receivedAt,
            ':type' => $type,
            ':legal_entity' => $legalEntity,
            ':file_name' => mb_substr((string)$att['filename'], 0, 255),
            ':file_path' => $relPath,
            ':size_bytes' => strlen($att['data']),
            ':status' => 'pending',
            ':notes' => null,
        ]);
        $saved++;
        $savedItems[] = [
            'from_email'   => $fromEmail,
            'from_name'    => $fromName,
            'subject'      => $subject,
            'type'         => $type,
            'legal_entity' => $legalEntity,
            'file_name'    => $att['filename'],
        ];
        $log("принято: $fromEmail / $type / {$att['filename']} → $relPath");

        imap_setflag_full($mbox, (string)$msgNum, '\\Seen');
    } catch (Throwable $e) {
        $log('ошибка на письме #' . $msgNum . ': ' . $e->getMessage());
        try { imap_setflag_full($mbox, (string)$msgNum, '\\Seen'); } catch (Throwable $_) {}
    }
}

imap_close($mbox);
$log("итог: обработано $processed, принято $saved, отклонено $rejected");

// TG-уведомление о принятых письмах — закупщикам/админам с правом
// модуля restaurant-sales уровня edit/full и привязанным Telegram.
if ($saved > 0) {
    $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
    if (!$botToken) { $log('TG: bot token не задан, уведомления пропущены'); }
    else {
        require_once __DIR__ . '/includes/tg_client.php';
        // Соберём текст сводки.
        $lines = [];
        foreach ($savedItems as $it) {
            $typeName = $it['type'] === 'restaurant_sales' ? 'реализация'
                      : ($it['type'] === 'stock_1c' ? 'остатки'
                      : ($it['type'] === 'shelf_life' ? 'остатки (сроки годности)'
                      : ($it['type'] === 'analysis' ? 'анализ запасов' : $it['type'])));
            $line = '• ' . htmlspecialchars($it['from_email'], ENT_QUOTES, 'UTF-8') . ' — ' . $typeName;
            if ($it['legal_entity']) $line .= ' / ' . htmlspecialchars($it['legal_entity'], ENT_QUOTES, 'UTF-8');
            if ($it['file_name']) $line .= ' (' . htmlspecialchars($it['file_name'], ENT_QUOTES, 'UTF-8') . ')';
            $lines[] = $line;
            if (count($lines) >= 10) break;
        }
        if ($saved > count($lines)) $lines[] = '… и ещё ' . ($saved - count($lines));

        $text = "📥 <b>Импорт по email</b>\n\nПринято писем: <b>{$saved}</b>\n\n" . implode("\n", $lines)
              . "\n\n<a href=\"" . ($_ENV['SITE_URL'] ?? 'https://supply-department.online') . "/admin?tab=email-imports\">Открыть очередь</a>";

        // Кому слать: admin OR permissions.restaurant-sales in (edit, full); telegram_chat_id обязателен.
        $users = $pdo->query("SELECT name, role, permissions, telegram_chat_id FROM users
                              WHERE telegram_chat_id IS NOT NULL AND telegram_chat_id <> ''
                                AND (tg_blocked_at IS NULL OR tg_blocked_at < NOW() - INTERVAL 30 DAY)")->fetchAll();
        $sentCount = 0;
        foreach ($users as $u) {
            $role = $u['role'] ?? 'user';
            $eligible = ($role === 'admin');
            if (!$eligible) {
                $perms = $u['permissions'] ? json_decode($u['permissions'], true) : null;
                if (is_array($perms)) {
                    $lvl = $perms['restaurant-sales'] ?? null;
                    if (in_array($lvl, ['edit', 'full'], true)) $eligible = true;
                }
            }
            if (!$eligible) continue;
            $chatId = (int)$u['telegram_chat_id'];
            if (!$chatId) continue;
            $r = tgClientSend($chatId, $text, [
                'disable_preview' => true,
                'token'           => $botToken,
                'timeout'         => 5,
                'connect_timeout' => 2,
                'pdo'             => $pdo,
            ]);
            if ($r['ok']) $sentCount++;
        }
        $log("TG: уведомлено получателей: $sentCount");
    }
}

