<?php
/**
 * Отправка уведомлений ресторанам о правках закупщика в закрытом сборе остатков.
 *
 * Запускать каждые 5 минут (строка crontab — в README ниже по коду).
 *
 * Сообщение уходит, когда правки по ресторану прекратились (см.
 * SC_EDIT_QUIET_MINUTES) — чтобы правка десяти позиций дала одно сообщение,
 * а не десять.
 */

require_once __DIR__ . '/includes/cron_lock.php';
$lock = cronAcquireLock(__DIR__ . '/cron_sc_edit_notify.lock', 300);
if (!$lock['fp']) { echo "Already running\n"; exit; }

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

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/sc_edit_notify.php';

$res = scSendEditNotifications($pdo, function (string $m) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n";
});

if ($res['sent'] || $res['skipped']) {
    echo '[' . date('Y-m-d H:i:s') . "] отправлено: {$res['sent']}, без привязанного бота: {$res['skipped']}\n";
}
