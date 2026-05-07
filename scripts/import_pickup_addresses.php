<?php
$envFile = '/var/www/bk-calc-secrets/.env';
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (strpos(trim($line), '#') === 0) continue;
    if (strpos($line, '=') !== false) {
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}
require __DIR__ . '/../api/lib/SimpleXLS.php';

$pdo = new PDO("mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4", $_ENV['DB_USER'], $_ENV['DB_PASS'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$xls = Shuchkin\SimpleXLS::parse(__DIR__ . '/../адреса.xls');
if (!$xls) { die('parse error: ' . Shuchkin\SimpleXLS::parseError() . "\n"); }

$updated = 0;
$skipped = 0;
$missing = [];

foreach ($xls->rows(0) as $row) {
    $name = trim((string)($row[2] ?? ''));
    $address = trim((string)($row[3] ?? ''));
    if (!$name || !$address) continue;
    if (!preg_match('/Ресторан\s*№(\d+)/u', $name, $m)) continue;
    $number = (int)$m[1];

    $st = $pdo->prepare("SELECT id, address FROM restaurants WHERE number = ? AND legal_entity_group = 'BK_VM' LIMIT 1");
    $st->execute([$number]);
    $rest = $st->fetch();
    if (!$rest) {
        $missing[] = $number;
        $skipped++;
        continue;
    }
    $pdo->prepare("UPDATE restaurants SET pickup_address = ? WHERE id = ?")->execute([$address, $rest['id']]);
    $updated++;
    echo "№$number → $address\n";
}

echo "\nИтог: обновлено $updated, не найдено в БД: " . count($missing);
if ($missing) echo " (номера: " . implode(',', $missing) . ")";
echo "\n";
