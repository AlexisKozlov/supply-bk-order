<?php
/**
 * Миграция plans: order_boxes в JSON items → учётные коробки (× multiplicity).
 * order_units (штуки) не трогаем.
 *
 * Запускать: php migrations/20260303_migrate_plans.php
 * ВАЖНО: выполнять при включённом режиме тех. работ, ПОСЛЕ SQL-миграции order_items.
 */

// Load .env
$envFile = __DIR__ . '/../api/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($k, $v) = explode('=', $line, 2);
            $_ENV[trim($k)] = trim($v);
        }
    }
}

$DB_HOST = $_ENV['DB_HOST'] ?? 'localhost';
$DB_NAME = $_ENV['DB_NAME'] ?? 'supply_bk';
$DB_USER = $_ENV['DB_USER'] ?? 'siteuser';
$DB_PASS = $_ENV['DB_PASS'] ?? '';

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    die("DB connection error: " . $e->getMessage() . "\n");
}

echo "=== Миграция plans: order_boxes → учётные коробки ===\n";

// Бэкап
echo "Создаю бэкап plans...\n";
$pdo->exec("CREATE TABLE IF NOT EXISTS plans_backup_20260303 AS SELECT * FROM plans");

// Загружаем все планы
$stmt = $pdo->query("SELECT id, items FROM plans");
$plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Планов: " . count($plans) . "\n";

$updated = 0;
$skipped = 0;

foreach ($plans as $plan) {
    $items = json_decode($plan['items'], true);
    if (!is_array($items)) {
        $skipped++;
        continue;
    }

    $changed = false;
    foreach ($items as &$item) {
        $mult = isset($item['multiplicity']) ? intval($item['multiplicity']) : 1;
        if ($mult <= 1) continue;

        // Обрабатываем plan (массив периодов)
        if (isset($item['plan']) && is_array($item['plan'])) {
            foreach ($item['plan'] as &$period) {
                if (isset($period['order_boxes']) && $period['order_boxes'] > 0) {
                    $period['order_boxes'] = $period['order_boxes'] * $mult;
                    $changed = true;
                }
                // order_units (штуки) НЕ трогаем
            }
            unset($period);
        }
    }
    unset($item);

    if ($changed) {
        $newItems = json_encode($items, JSON_UNESCAPED_UNICODE);
        $upd = $pdo->prepare("UPDATE plans SET items = ? WHERE id = ?");
        $upd->execute([$newItems, $plan['id']]);
        $updated++;
    } else {
        $skipped++;
    }
}

echo "Обновлено: $updated, пропущено: $skipped\n";
echo "=== Готово ===\n";
