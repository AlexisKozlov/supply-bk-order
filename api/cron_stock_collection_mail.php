<?php
date_default_timezone_set('Europe/Minsk'); // Минск (+03:00) — совпадает с TZ MariaDB
/**
 * Cron: письма ресторанам о сборе остатков.
 *
 * Два режима запуска:
 *
 *   1. По расписанию (без аргументов), из crontab каждые 5 минут:
 *        php /var/www/bk-calc/api/cron_stock_collection_mail.php
 *      — дорассылает письма о старте сбора (если веб-запрос не смог запустить
 *        рассылку фоном),
 *      — за сутки и за 2 часа до дедлайна напоминает тем, кто ещё не сдал.
 *      Ночью не шлём: только с 07:00 до 22:00.
 *
 *   2. Точечный запуск (его делает портал сразу после создания сбора и по
 *      кнопке «Напомнить»):
 *        php ... --collection=123 --kind=start|manual|reminder_24h|reminder_2h
 *      Окна по времени в этом режиме не проверяются — закупщик действует
 *      осознанно.
 *
 * Кому и что именно уходит — см. api/includes/stock_collection_mail.php.
 * Повторы отсекает журнал stock_collection_mail_log.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$opts = getopt('', ['collection::', 'kind::']);
$jobCollection = isset($opts['collection']) ? (int)$opts['collection'] : 0;
$jobKind       = isset($opts['kind']) ? (string)$opts['kind'] : '';
$targeted      = $jobCollection > 0 && $jobKind !== '';

require_once __DIR__ . '/includes/cron_lock.php';
$lockName = $targeted
    ? sprintf('cron_sc_mail_%d_%s.lock', $jobCollection, preg_replace('/[^a-z0-9_]/', '', $jobKind))
    : 'cron_stock_collection_mail.lock';
$lock = cronAcquireLock(__DIR__ . '/' . $lockName, 900);
if (!$lock['fp']) { echo "Already running\n"; exit; }
set_time_limit(900);

$envFile = '/var/www/bk-calc-secrets/.env';
if (!file_exists($envFile)) exit("no .env\n");
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#')) continue;
    [$k, $v] = explode('=', $line, 2);
    $_ENV[trim($k)] = trim($v);
}

$dsn = 'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'supply_bk') . ';charset=utf8mb4';
$pdo = new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

require_once __DIR__ . '/includes/stock_collection_mail.php';

function scMailLog(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

// ── Режим 1: точечная рассылка по заданию из портала ───────────────────────
if ($targeted) {
    $res = scSendCollectionEmails($pdo, $jobCollection, $jobKind);
    scMailLog(sprintf('job collection=%d kind=%s → отправлено %d, ошибок %d',
        $jobCollection, $jobKind, $res['sent'], $res['failed']));
    exit;
}

// ── Режим 2: плановый проход ───────────────────────────────────────────────
$hour = (int)date('G');
if ($hour < 7 || $hour >= 22) { exit; } // ночью рестораны не дёргаем

$totals = ['sent' => 0, 'failed' => 0];

// Старт сбора: подчищаем за фоновым запуском из портала. Только сборы, где
// рассылку действительно заказывали (mail_start_requested), и не старше суток —
// иначе под автоматику попадёт любой давно висящий активный сбор.
$fresh = $pdo->query("
    SELECT id FROM stock_collections
    WHERE status = 'active' AND mail_start_requested = 1
      AND created_at >= NOW() - INTERVAL 24 HOUR
")->fetchAll(PDO::FETCH_COLUMN);
foreach ($fresh as $id) {
    $res = scSendCollectionEmails($pdo, (int)$id, 'start');
    if ($res['sent'] || $res['failed']) {
        scMailLog(sprintf('start collection=%d → отправлено %d, ошибок %d', $id, $res['sent'], $res['failed']));
        $totals['sent'] += $res['sent'];
        $totals['failed'] += $res['failed'];
    }
}

// Напоминания до дедлайна.
//
// Окна не пересекаются: 24-часовое напоминание живёт до отметки «за 2 часа»,
// дальше работает только двухчасовое. Поэтому сбор, созданный незадолго до
// дедлайна, не получит сразу оба письма.
$windows = [
    'reminder_24h' => "sc.deadline_at > NOW() + INTERVAL 2 HOUR AND sc.deadline_at <= NOW() + INTERVAL 24 HOUR",
    'reminder_2h'  => "sc.deadline_at > NOW() AND sc.deadline_at <= NOW() + INTERVAL 2 HOUR",
];
foreach ($windows as $kind => $cond) {
    $rows = $pdo->query("
        SELECT sc.id FROM stock_collections sc
        WHERE sc.status = 'active' AND sc.deadline_at IS NOT NULL AND {$cond}
    ")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $id) {
        // Не наслаиваем напоминание на только что отправленное письмо о старте.
        $res = scSendCollectionEmails($pdo, (int)$id, $kind, 3);
        if ($res['sent'] || $res['failed']) {
            scMailLog(sprintf('%s collection=%d → отправлено %d, ошибок %d', $kind, $id, $res['sent'], $res['failed']));
            $totals['sent'] += $res['sent'];
            $totals['failed'] += $res['failed'];
        }
    }
}

if ($totals['sent'] || $totals['failed']) {
    scMailLog(sprintf('итого: отправлено %d, ошибок %d', $totals['sent'], $totals['failed']));
}
