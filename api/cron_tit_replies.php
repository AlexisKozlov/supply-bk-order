<?php
date_default_timezone_set('Europe/Minsk'); // Минск (+03:00) — совпадает с TZ MariaDB
/**
 * Cron: приём ответов поставщиков для модуля «Заявка на пропуск».
 *
 * Запуск каждые 5 минут (см. tit_settings.imap_poll_minutes):
 *   php /var/www/bk-calc/api/cron_tit_replies.php
 *
 * Логика:
 *   - IMAP-подключение к ящику исходящих заказов (по умолчанию order@).
 *     Креды берутся из TIT_IMAP_* в .env, с fallback на SMTP_ORDER_*.
 *   - UNSEEN-сообщения → по каждому:
 *     • Проверяем дубликат по Message-Id (UNIQUE в tit_email_log).
 *     • Привязываем к заявке через In-Reply-To / References — ищем
 *       совпадение с tit_requests.outgoing_message_id.
 *     • Тело письма пропускаем через titParseReplyBody — получаем пары
 *       (plate, phone). Вложения JPG/PNG/PDF сохраняем и прогоняем через
 *       OCR, если на скане распознался номер — добавляем как отдельную
 *       машину со source=EMAIL_OCR.
 *     • Каждая найденная пара/одиночный номер → новая строка в
 *       tit_vehicles (НЕ перезаписываем существующие — история сохраняется).
 *     • Если заявка нашлась — обновляем статус → DATA_RECEIVED, шлём
 *       Telegram создателю заявки.
 *     • Если не нашлась — пишем в tit_email_log со status=UNMATCHED,
 *       закупщик потом привяжет руками.
 *   - Письмо помечаем Seen после обработки.
 *
 * Идемпотентность: один и тот же Message-Id обрабатывается ровно один раз
 * (UNIQUE на tit_email_log.message_id + INSERT IGNORE).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/includes/cron_lock.php';
$lock = cronAcquireLock(__DIR__ . '/cron_tit_replies.lock', 900);
if (!$lock['fp']) { echo "Already running\n"; exit; }
$lockFp = $lock['fp'];
$killedStalePid = $lock['killed_pid'];
set_time_limit(180);
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
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/tit_normalize.php';
require_once __DIR__ . '/includes/tit_parser.php';
require_once __DIR__ . '/includes/tit_ocr.php';
require_once __DIR__ . '/includes/tit_helpers.php';

$IMAP_HOST = $_ENV['TIT_IMAP_HOST'] ?? ($_ENV['IMAP_HOST'] ?? 'imap.hoster.by');
$IMAP_PORT = (int)($_ENV['TIT_IMAP_PORT'] ?? 993);
$IMAP_USER = $_ENV['TIT_IMAP_USER'] ?? ($_ENV['SMTP_ORDER_USER'] ?? 'order@supply-department.online');
$IMAP_PASS = $_ENV['TIT_IMAP_PASS'] ?? ($_ENV['SMTP_ORDER_PASS'] ?? '');
if ($IMAP_HOST === '' || $IMAP_USER === '' || $IMAP_PASS === '') {
    exit("[tit-replies] IMAP не сконфигурирован (TIT_IMAP_* / SMTP_ORDER_* в .env)\n");
}

$MAX_ATTACHMENT = 10 * 1024 * 1024; // 10 МБ — как и в основном email-import
$ATTACHMENT_DIR = __DIR__ . '/uploads/tit_attachments';
if (!is_dir($ATTACHMENT_DIR)) @mkdir($ATTACHMENT_DIR, 0775, true);

$ts = fn() => date('Y-m-d H:i:s');
$log = function ($msg) use ($ts) { echo '[' . $ts() . '] ' . $msg . "\n"; };

if ($killedStalePid) {
    $log('ВНИМАНИЕ: снят зависший процесс PID ' . $killedStalePid . ' (висел дольше 15 мин)');
    error_log('[tit-replies] killed stale cron process ' . $killedStalePid);
}

$mboxRef = '{' . $IMAP_HOST . ':' . $IMAP_PORT . '/imap/ssl}INBOX';
$mbox = @imap_open($mboxRef, $IMAP_USER, $IMAP_PASS);
if (!$mbox) { $log('IMAP connect failed: ' . imap_last_error()); exit(1); }

$unseen = imap_search($mbox, 'UNSEEN');
if (!$unseen) { $log('новых писем нет'); imap_close($mbox); exit; }

// Лимит писем за один прогон — защита от зависания в понедельник утром,
// когда за выходные накопится сотни писем + у каждого OCR на 2-5 сек.
// Хвост остаётся UNSEEN и подберётся следующим тиком через 5 минут.
// FIFO обеспечивается естественным порядком sequence-номеров от imap_search:
// старые письма имеют меньшие номера и идут первыми.
$batchLimit = max(1, (int)($_ENV['TIT_REPLIES_BATCH_LIMIT'] ?? 50));
$totalUnseen = count($unseen);
if ($totalUnseen > $batchLimit) {
    $unseen = array_slice($unseen, 0, $batchLimit);
    $log("новых писем: {$totalUnseen}, в этот прогон возьмём первые {$batchLimit}");
} else {
    $log("новых писем: {$totalUnseen}");
}

// ── Утилиты ──
function decodeMimeStr(?string $s): string {
    if (!$s) return '';
    $parts = imap_mime_header_decode($s);
    $out = '';
    foreach ($parts as $p) {
        $t = $p->text;
        $cs = strtolower($p->charset);
        if ($cs && $cs !== 'utf-8' && $cs !== 'default') {
            $conv = @iconv($cs, 'UTF-8//IGNORE', $t);
            if ($conv !== false) $t = $conv;
        }
        $out .= $t;
    }
    return $out;
}
function decodeBodyByEncoding(string $data, int $enc): string {
    if ($enc === 3) return base64_decode($data) ?: '';
    if ($enc === 4) return quoted_printable_decode($data);
    return $data;
}
function charsetParam($structure): string {
    $params = [];
    if (!empty($structure->parameters)) foreach ($structure->parameters as $p) $params[strtolower($p->attribute)] = $p->value;
    return strtolower($params['charset'] ?? '');
}

/**
 * Рекурсивно собирает текстовое тело письма (предпочитает text/plain) и список вложений.
 * @return array{text: string, attachments: array<int, array{filename: string, ext: string, data: string}>}
 */
function walkMessage($mbox, $msgNum, $structure, $partNum = ''): array {
    $out = ['text' => '', 'attachments' => []];
    $type = $structure->type ?? 0;
    $subtype = strtolower($structure->subtype ?? '');
    $params = [];
    if (!empty($structure->parameters)) foreach ($structure->parameters as $p) $params[strtolower($p->attribute)] = $p->value;
    if (!empty($structure->dparameters)) foreach ($structure->dparameters as $p) $params[strtolower($p->attribute)] = $p->value;
    $filename = decodeMimeStr($params['filename'] ?? ($params['name'] ?? ''));
    $disposition = strtolower($structure->disposition ?? '');
    $isAttachment = $disposition === 'attachment' || ($filename !== '' && $type !== 0);

    if ($isAttachment && $filename !== '') {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'gif'];
        if (in_array($ext, $allowed, true)) {
            $raw = imap_fetchbody($mbox, $msgNum, $partNum ?: '1');
            $data = decodeBodyByEncoding($raw, (int)($structure->encoding ?? 0));
            if ($data !== '' && strlen($data) <= 10 * 1024 * 1024) {
                $out['attachments'][] = ['filename' => $filename, 'ext' => $ext, 'data' => $data];
            }
        }
    } elseif ($type === 0 && $subtype === 'plain' && !$isAttachment) {
        $raw = imap_fetchbody($mbox, $msgNum, $partNum ?: '1');
        $body = decodeBodyByEncoding($raw, (int)($structure->encoding ?? 0));
        $cs = strtolower($params['charset'] ?? '');
        if ($cs && $cs !== 'utf-8') {
            $conv = @iconv($cs, 'UTF-8//IGNORE', $body);
            if ($conv !== false) $body = $conv;
        }
        $out['text'] .= ($out['text'] !== '' ? "\n" : '') . $body;
    } elseif ($type === 0 && $subtype === 'html' && !$isAttachment && $out['text'] === '') {
        // fallback на text/html — превращаем в простой текст
        $raw = imap_fetchbody($mbox, $msgNum, $partNum ?: '1');
        $body = decodeBodyByEncoding($raw, (int)($structure->encoding ?? 0));
        $cs = strtolower($params['charset'] ?? '');
        if ($cs && $cs !== 'utf-8') {
            $conv = @iconv($cs, 'UTF-8//IGNORE', $body);
            if ($conv !== false) $body = $conv;
        }
        // Убираем теги, оставляем переносы.
        $body = preg_replace('/<br\s*\/?>/i', "\n", $body) ?? $body;
        $body = preg_replace('/<\/(p|div|tr|li|h\d)>/i', "\n", $body) ?? $body;
        $out['text'] = trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    if (!empty($structure->parts)) {
        foreach ($structure->parts as $i => $sub) {
            $child = $partNum === '' ? (string)($i + 1) : ($partNum . '.' . ($i + 1));
            $r = walkMessage($mbox, $msgNum, $sub, $child);
            if ($r['text'] !== '' && $out['text'] === '') $out['text'] = $r['text'];
            foreach ($r['attachments'] as $a) $out['attachments'][] = $a;
        }
    }
    return $out;
}

function extractReplyHeaders(string $header): array {
    $msgId = '';
    $inReplyTo = '';
    $references = [];
    if (preg_match('/^Message-ID:\s*(<[^>]+>)/im', $header, $m)) $msgId = trim($m[1]);
    if (preg_match('/^In-Reply-To:\s*(<[^>]+>)/im', $header, $m)) $inReplyTo = trim($m[1]);
    if (preg_match('/^References:\s*(.+)$/im', $header, $m)) {
        if (preg_match_all('/<[^>]+>/', $m[1], $r)) $references = array_map('trim', $r[0]);
    }
    return ['message_id' => $msgId, 'in_reply_to' => $inReplyTo, 'references' => $references];
}

$processed = 0;
$matched = 0;
$skippedDup = 0;
$unmatchedSaved = 0;

foreach ($unseen as $msgNum) {
    try {
        $rawHeader = imap_fetchheader($mbox, $msgNum);
        $overview = imap_fetch_overview($mbox, (string)$msgNum, 0)[0] ?? null;
        $hdr = extractReplyHeaders($rawHeader);
        $msgId = $hdr['message_id'] !== '' ? $hdr['message_id'] : ('<no-msg-id-' . $msgNum . '-' . md5($rawHeader) . '@local>');

        // Защита от повторной обработки — UNIQUE на tit_email_log.message_id.
        $dup = $pdo->prepare("SELECT id FROM tit_email_log WHERE message_id = ? LIMIT 1");
        $dup->execute([$msgId]);
        if ($dup->fetchColumn()) {
            imap_setflag_full($mbox, (string)$msgNum, '\\Seen');
            $skippedDup++;
            continue;
        }

        $fromEmail = '';
        $fromName = '';
        if ($overview) {
            $fromEmail = isset($overview->from) ? decodeMimeStr($overview->from) : '';
            // overview->from обычно «Имя <email>» — попробуем выделить
            if (preg_match('/<([^>]+)>/', $fromEmail, $em)) { $fromName = trim(str_replace($em[0], '', $fromEmail)); $fromEmail = trim($em[1]); }
        }
        $subject = $overview && isset($overview->subject) ? decodeMimeStr($overview->subject) : '';
        $receivedAt = $overview && isset($overview->date) ? date('Y-m-d H:i:s', strtotime($overview->date)) : date('Y-m-d H:i:s');

        // Привязка к заявке: в первую очередь по In-Reply-To, потом по любому Reference.
        $requestId = null;
        $tryIds = array_filter(array_unique(array_merge([$hdr['in_reply_to']], $hdr['references'])));
        if ($tryIds) {
            $ph = implode(',', array_fill(0, count($tryIds), '?'));
            $f = $pdo->prepare("SELECT id FROM tit_requests WHERE outgoing_message_id IN ($ph) ORDER BY id DESC LIMIT 1");
            $f->execute(array_values($tryIds));
            $requestId = $f->fetchColumn() ?: null;
        }
        // Запасной путь: по теме письма (дата поставки) + адресу отправителя.
        // Нужен для писем, у которых почтовик поставщика не проставил
        // In-Reply-To, и для старых заявок без сохранённого Message-Id.
        if (!$requestId) {
            $requestId = titMatchRequestByEmail($pdo, $fromEmail, $subject);
        }

        $structure = imap_fetchstructure($mbox, $msgNum);
        $parsed = walkMessage($mbox, $msgNum, $structure);
        $bodyText = (string)$parsed['text'];
        $bodyExcerpt = mb_substr($bodyText, 0, 2000);

        // Парсим тело — список пар
        $pairs = $bodyText !== '' ? titParseReplyBody($bodyText) : [];

        // Сохраняем вложения во временные файлы (имя — uniqid), прогоняем OCR.
        // Финальный префикс будет «{$emailLogId}_…» — он известен только после
        // INSERT в tit_email_log, поэтому делаем rename ниже. До правки префикс
        // был msgNum — а UI ищет вложения по emailId glob'ом, из-за чего файлы
        // были недоступны для скачивания.
        $tmpAttachments = []; // [['tmp_disk' => ..., 'safe_name' => ...], ...]
        $ocrPlates = [];
        foreach ($parsed['attachments'] as $att) {
            $safeName = preg_replace('/[^a-zA-Zа-яА-Я0-9\.\-_]+/u', '_', $att['filename']);
            $tmpDisk = $ATTACHMENT_DIR . '/tmp_' . bin2hex(random_bytes(6)) . '_' . $safeName;
            if (@file_put_contents($tmpDisk, $att['data']) !== false) {
                $tmpAttachments[] = ['tmp_disk' => $tmpDisk, 'safe_name' => $safeName];
                $ocrResult = titOcrExtractPlate($tmpDisk);
                if (!empty($ocrResult['plates'])) {
                    foreach ($ocrResult['plates'] as $p) $ocrPlates[] = $p;
                }
            }
        }

        // Логируем письмо
        // Номер машины мог не распознаться (в письме только телефон) — тогда
        // берём номер из накладной, если OCR его нашёл.
        $parsedPlate = ($pairs[0]['plate'] ?? '') !== ''
            ? $pairs[0]['plate']
            : ($ocrPlates[0]['plate'] ?? null);
        $parsedPhone = ($pairs[0]['phone'] ?? '') !== '' ? $pairs[0]['phone'] : null;
        $parsedVia = 'NONE';
        if ($pairs && $ocrPlates) $parsedVia = 'BOTH';
        elseif ($pairs) $parsedVia = 'EMAIL_TEXT';
        elseif ($ocrPlates) $parsedVia = 'EMAIL_OCR';

        $emailLogIns = $pdo->prepare("
            INSERT INTO tit_email_log
                (request_id, message_id, from_email, from_name, subject, received_at,
                 body_excerpt, has_attachment, attachment_path, parsed_plate, parsed_phone, parsed_via, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $emailLogIns->execute([
            $requestId,
            $msgId,
            $fromEmail,
            $fromName,
            mb_substr($subject, 0, 500),
            $receivedAt,
            $bodyExcerpt,
            $tmpAttachments ? 1 : 0,
            null, // attachment_path выставим ниже rename'ом, после получения emailLogId
            $parsedPlate,
            $parsedPhone,
            $parsedVia,
            $requestId ? 'MATCHED' : 'UNMATCHED',
        ]);
        $emailLogId = (int)$pdo->lastInsertId();

        // Финализируем имена вложений: префикс — emailLogId, чтобы поиск glob'ом
        // в UI (rpc/tit.php → tit_email_attachment) находил файлы. attachment_path
        // в логе пишем первого вложения.
        $savedAttachmentPath = null;
        foreach ($tmpAttachments as $att) {
            $finalName = $emailLogId . '_' . time() . '_' . substr(md5($att['safe_name']), 0, 8) . '_' . $att['safe_name'];
            $finalDisk = $ATTACHMENT_DIR . '/' . $finalName;
            if (@rename($att['tmp_disk'], $finalDisk)) {
                if (!$savedAttachmentPath) $savedAttachmentPath = $finalDisk;
            } else {
                // rename не удался — оставим временный файл, чтобы OCR/контент не потерялся
                if (!$savedAttachmentPath) $savedAttachmentPath = $att['tmp_disk'];
            }
        }
        if ($savedAttachmentPath) {
            $pdo->prepare("UPDATE tit_email_log SET attachment_path = ? WHERE id = ?")
                ->execute([$savedAttachmentPath, $emailLogId]);
        }

        if ($requestId) {
            // Достаём supplier_id и created_by для уведомления + памяти
            $reqInfo = $pdo->prepare("SELECT supplier_id, supplier_name, legal_entity_group, created_by, status FROM tit_requests WHERE id = ?");
            $reqInfo->execute([$requestId]);
            $req = $reqInfo->fetch() ?: [];

            // Добавляем машины из тела (EMAIL_TEXT) — только если такого номера
            // ещё нет в заявке. Защита от дубля при повторном ответе поставщика
            // (та же машина в нескольких письмах) — иначе закупщик видит копии.
            $existsStmt = $pdo->prepare("SELECT id FROM tit_vehicles WHERE request_id = ? AND plate = ? AND deleted_at IS NULL LIMIT 1");
            foreach ($pairs as $pair) {
                // Пара без номера машины (распознали только телефон водителя) —
                // машину не заводим, телефон уже сохранён в карточке письма.
                if ($pair['plate'] === '') continue;
                $existsStmt->execute([$requestId, $pair['plate']]);
                if ($existsStmt->fetchColumn()) continue;
                $pdo->prepare("
                    INSERT INTO tit_vehicles
                        (request_id, plate, plate_raw, phone, phone_raw, source, email_log_id, needs_review)
                    VALUES (?, ?, ?, ?, ?, 'EMAIL_TEXT', ?, 1)
                ")->execute([
                    $requestId, $pair['plate'], $pair['plate_raw'],
                    $pair['phone'], $pair['phone_raw'], $emailLogId,
                ]);
                titRememberSupplierDefaults($pdo, $req['supplier_id'] ?? null, $pair['plate'], $pair['phone']);
            }

            // Добавляем машины из накладной (EMAIL_OCR) — только если их ещё нет в этой заявке
            foreach ($ocrPlates as $op) {
                $exists = $pdo->prepare("SELECT id FROM tit_vehicles WHERE request_id = ? AND plate = ? AND deleted_at IS NULL LIMIT 1");
                $exists->execute([$requestId, $op['plate']]);
                if ($exists->fetchColumn()) continue;
                $pdo->prepare("
                    INSERT INTO tit_vehicles
                        (request_id, plate, plate_raw, source, email_log_id, needs_review)
                    VALUES (?, ?, ?, 'EMAIL_OCR', ?, 1)
                ")->execute([$requestId, $op['plate'], $op['raw'], $emailLogId]);
                titRememberSupplierDefaults($pdo, $req['supplier_id'] ?? null, $op['plate'], null);
            }

            // Статус заявки → DATA_RECEIVED (если ещё была WAITING). Пара без
            // номера машины (нашли только телефон) данными не считается —
            // заявка остаётся в ожидании, закупщик дособерёт руками.
            $hasPlates = (bool)$ocrPlates;
            foreach ($pairs as $pair) { if ($pair['plate'] !== '') { $hasPlates = true; break; } }
            if (($req['status'] ?? '') === 'WAITING' && $hasPlates) {
                $pdo->prepare("UPDATE tit_requests SET status = 'DATA_RECEIVED', updated_at = NOW() WHERE id = ?")
                    ->execute([$requestId]);
            }

            $matched++;

            // Уведомление закупщику
            $supplierName = htmlspecialchars((string)($req['supplier_name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $plateForMsg = $pairs[0]['plate'] ?? ($ocrPlates[0]['plate'] ?? '');
            $what = $pairs ? 'данные машины' : ($ocrPlates ? 'скан накладной с распознанным номером' : 'ответ от поставщика');
            $msg = '🚛 <b>' . $supplierName . '</b>: пришли ' . $what
                . ($plateForMsg ? ' — <code>' . htmlspecialchars($plateForMsg, ENT_QUOTES, 'UTF-8') . '</code>' : '')
                . "\nЗаявка: " . ($_ENV['SITE_URL'] ?? 'https://supply-department.online') . '/tit-requests?id=' . $requestId;
            titNotifyStaff($pdo, $req['created_by'] ?? null, $msg);
        } else {
            $unmatchedSaved++;
        }

        imap_setflag_full($mbox, (string)$msgNum, '\\Seen');
        $processed++;
    } catch (Throwable $e) {
        $log('msg ' . $msgNum . ' error: ' . $e->getMessage());
        // НЕ помечаем Seen — попробуем ещё раз на следующем запуске
    }
}

imap_close($mbox);
$remaining = max(0, $totalUnseen - count($unseen));
$tail = $remaining > 0 ? " | в очереди ещё {$remaining}" : '';
$log("обработано: {$processed}/{$totalUnseen} | привязано: {$matched} | без привязки: {$unmatchedSaved} | дубликатов: {$skippedDup}{$tail}");
