<?php
date_default_timezone_set('Europe/Minsk'); // Минск (+03:00) — совпадает с TZ MariaDB
/**
 * Импорт графика доставок Камако из Excel в supplier_schedules
 * Запуск: php api/import_kamako.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

// Загрузка .env
$envFile = '/var/www/bk-calc-secrets/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        putenv($line);
    }
}

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST') ?: 'localhost',
    getenv('DB_PORT') ?: '3306',
    getenv('DB_NAME') ?: 'bk_calc'
);
$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$SUPPLIER_ID = '998f1395-e2be-4ddc-8b6f-211e010cb95a'; // Камако

// Маппинг дней недели
$dayMap = ['пн'=>1,'вт'=>2,'ср'=>3,'чт'=>4,'пт'=>5,'сб'=>6,'вс'=>7];

// Парсинг Excel через Python (openpyxl)
$json = shell_exec('python3 -c "
import openpyxl, json
wb = openpyxl.load_workbook(\'/var/www/bk-calc/камако.xlsx\')
ws = wb[\'BurgerKing\']
rows = []
for row in ws.iter_rows(min_row=2, max_row=ws.max_row, values_only=True):
    num, addr, order_days, delivery_days = row
    if num is None or not isinstance(num, (int, float)):
        continue
    rows.append({
        \'number\': int(num),
        \'order_days\': str(order_days).strip() if order_days else \'\',
        \'delivery_days\': str(delivery_days).strip() if delivery_days else \'\'
    })
print(json.dumps(rows, ensure_ascii=False))
"');

$rows = json_decode($json, true);
if (!$rows) {
    echo "Ошибка парсинга Excel\n";
    exit(1);
}

// Загрузка ресторанов из БД
$restaurants = [];
$stmt = $pdo->query("SELECT id, number FROM restaurants");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $restaurants[(int)$r['number']] = (int)$r['id'];
}

$inserted = 0;
$skipped = 0;
$errors = [];

$insert = $pdo->prepare("
    INSERT INTO supplier_schedules (supplier_id, restaurant_id, order_day, delivery_day, updated_at, updated_by)
    VALUES (?, ?, ?, ?, NOW(), 'import_kamako')
    ON DUPLICATE KEY UPDATE delivery_day = VALUES(delivery_day), updated_at = NOW()
");

foreach ($rows as $row) {
    $restNum = $row['number'];
    if (!isset($restaurants[$restNum])) {
        $errors[] = "Ресторан #{$restNum} не найден в БД";
        continue;
    }
    $restId = $restaurants[$restNum];

    // Парсинг дней (может быть "пн/чт" → два расписания)
    $orderDays = array_map('trim', explode('/', $row['order_days']));
    $deliveryDays = array_map('trim', explode('/', $row['delivery_days']));

    if (count($orderDays) !== count($deliveryDays)) {
        $errors[] = "Ресторан #{$restNum}: несовпадение количества дней заказа и поставки";
        continue;
    }

    for ($i = 0; $i < count($orderDays); $i++) {
        $od = mb_strtolower($orderDays[$i]);
        $dd = mb_strtolower($deliveryDays[$i]);

        if (!isset($dayMap[$od]) || !isset($dayMap[$dd])) {
            $errors[] = "Ресторан #{$restNum}: неизвестный день '{$od}' или '{$dd}'";
            continue;
        }

        $insert->execute([$SUPPLIER_ID, $restId, $dayMap[$od], $dayMap[$dd]]);
        $inserted++;
    }
}

echo "Импорт завершён:\n";
echo "  Вставлено/обновлено: {$inserted}\n";
echo "  Ресторанов в файле: " . count($rows) . "\n";
if ($errors) {
    echo "  Ошибки (" . count($errors) . "):\n";
    foreach ($errors as $e) echo "    - {$e}\n";
}
