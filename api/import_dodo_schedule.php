<?php
/**
 * Одноразовый импортёр графика доставок Додо (Пицца Стар).
 *
 * Читает графикдодо.xlsx в корне проекта, удаляет всех PS-ресторанов
 * (и их расписания каскадом), заливает заново из файла.
 *
 * Структура файла:
 *   A — № ресторана (становится 1000 + N в нашей БД)
 *   B — № ДОДО ИС (в restaurants.dodo_is_number)
 *   C — адрес (для регионов с префиксом «г. Брест, …»)
 *   D — телефон (игнорируем)
 *   E/F — ПН: доставка + тесто
 *   G/H — ВТ: доставка + тесто
 *   I/J — СР: доставка + тесто
 *   K/L — ЧТ: доставка + тесто
 *   M/N — ПТ: доставка + тесто
 *   O/P — СБ: доставка + тесто
 *
 * Города: Минск — по умолчанию (вся верхняя секция до строки «Регионы»),
 * потом адреса с префиксом «г. <Город>, …». Ряд с заголовком
 * «Минск» или «Регионы» — пропускаем.
 *
 * Запуск:
 *   php /var/www/bk-calc/api/import_dodo_schedule.php
 */

require_once __DIR__ . '/lib/SimpleXLSX.php';

// .env для доступа к БД
$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) { fwrite(STDERR, "No .env found\n"); exit(1); }
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#')) continue;
    [$key, $val] = explode('=', $line, 2);
    $_ENV[trim($key)] = trim($val);
}
$dsn = 'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'supply_bk') . ';charset=utf8mb4';
$pdo = new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$file = __DIR__ . '/../графикдодо.xlsx';
if (!file_exists($file)) { fwrite(STDERR, "File not found: $file\n"); exit(1); }

$xlsx = \Shuchkin\SimpleXLSX::parse($file);
if (!$xlsx) { fwrite(STDERR, "Parse error: " . \Shuchkin\SimpleXLSX::parseError() . "\n"); exit(1); }
$rows = $xlsx->rows(0);

// Ищем первую data-строку (после header'ов)
// Шапка: r1 — заголовок отчёта, r3 — названия колонок, r4 — подзаголовки дней,
// r5 — «Минск» (маркер города), r6+ — данные
$cityDefault = 'Минск';
$currentCity = $cityDefault;
$currentRegion = 'Минск'; // region нужен для сортировки/группировки в UI

$restaurants = []; // собираем список для вставки

// Парсер города из адреса: «г. Брест, ул. ...» → city='Брест', address='ул. ...'
function parseAddress($raw) {
    $raw = trim($raw ?? '');
    if ($raw === '') return ['city' => null, 'address' => ''];
    if (preg_match('/^г\.\s*([^,]+?)\s*,\s*(.+)$/u', $raw, $m)) {
        return ['city' => trim($m[1]), 'address' => trim($m[2])];
    }
    // Без префикса — адрес Минска
    return ['city' => null, 'address' => $raw];
}

// Основной парсинг. Пропускаем первые 5 строк (заголовки),
// идём с индекса 5 (6-я строка в Excel).
$regionHeaderSeen = false;
foreach ($rows as $i => $row) {
    if ($i < 5) continue; // header rows
    $a = trim((string)($row[0] ?? ''));
    $b = trim((string)($row[1] ?? ''));
    $c = trim((string)($row[2] ?? ''));

    // Пустая строка
    if ($a === '' && $b === '' && $c === '') continue;

    // Строка-заголовок города/региона в колонке C (например «Минск», «Регионы»)
    if ($a === '' && $b === '' && $c !== '') {
        $label = mb_strtolower($c);
        if ($label === 'регионы') { $regionHeaderSeen = true; continue; }
        if ($label === 'минск')  { continue; }
        continue;
    }

    // Должен быть хотя бы № ресторана
    if ($a === '') continue;
    $restNum = (int)$a;
    if (!$restNum) continue;

    $dodoIs = ($b !== '' && ctype_digit($b)) ? (int)$b : null;
    $addrInfo = parseAddress($c);
    $city = $addrInfo['city'] ?: 'Минск';
    $address = $addrInfo['address'];
    $region = ($city === 'Минск') ? 'Минск' : 'Регионы';

    // Расписания по дням: 6 дней × 2 колонки
    $schedules = [];
    $dayCols = [
        1 => [4, 5],   // ПН: E, F
        2 => [6, 7],   // ВТ: G, H
        3 => [8, 9],   // СР: I, J
        4 => [10, 11], // ЧТ: K, L
        5 => [12, 13], // ПТ: M, N
        6 => [14, 15], // СБ: O, P
    ];
    foreach ($dayCols as $dow => [$mainCol, $doughCol]) {
        $mainRaw  = trim((string)($row[$mainCol]  ?? ''));
        $doughRaw = trim((string)($row[$doughCol] ?? ''));
        if ($mainRaw === '' && $doughRaw === '') continue;
        $schedules[] = [
            'day_of_week'   => $dow,
            'delivery_time' => $mainRaw  !== '' ? $mainRaw  : null,
            'dough_time'    => $doughRaw !== '' ? $doughRaw : null,
        ];
    }

    $restaurants[] = [
        'number'         => 1000 + $restNum, // PS01..PS50 → 1001..1050
        'dodo_is_number' => $dodoIs,
        'city'           => $city,
        'region'         => $region,
        'address'        => $address,
        'schedules'      => $schedules,
    ];
}

if (empty($restaurants)) {
    fwrite(STDERR, "No restaurants parsed from file\n");
    exit(1);
}

echo "Распознано ресторанов: " . count($restaurants) . "\n";
echo "Строк расписания всего: " . array_sum(array_map(fn($r) => count($r['schedules']), $restaurants)) . "\n\n";

// ─── Импорт в БД ───
$pdo->beginTransaction();
try {
    // 1) Удаляем всех PS-ресторанов. CASCADE на delivery_schedule сработает.
    $del = $pdo->prepare("DELETE FROM restaurants WHERE legal_entity_group = 'PS'");
    $del->execute();
    $deleted = $del->rowCount();
    echo "Удалено старых PS-ресторанов: {$deleted}\n";

    // 2) Вставляем новые
    $ri = $pdo->prepare("
        INSERT INTO restaurants (number, dodo_is_number, region, city, address, legal_entity_group, active, sort_order)
        VALUES (?, ?, ?, ?, ?, 'PS', 1, ?)
    ");
    $si = $pdo->prepare("
        INSERT INTO delivery_schedule (restaurant_id, day_of_week, delivery_time, dough_time, updated_at, updated_by)
        VALUES (?, ?, ?, ?, NOW(), 'dodo-import')
    ");

    $insertedRests = 0;
    $insertedSchedules = 0;
    foreach ($restaurants as $idx => $r) {
        $ri->execute([
            $r['number'],
            $r['dodo_is_number'],
            $r['region'],
            $r['city'],
            $r['address'],
            $idx,
        ]);
        $restId = (int)$pdo->lastInsertId();
        $insertedRests++;

        foreach ($r['schedules'] as $sch) {
            $si->execute([
                $restId,
                $sch['day_of_week'],
                $sch['delivery_time'],
                $sch['dough_time'],
            ]);
            $insertedSchedules++;
        }
    }

    $pdo->commit();
    echo "Создано PS-ресторанов: {$insertedRests}\n";
    echo "Строк расписания создано: {$insertedSchedules}\n";
    echo "Готово.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Ошибка импорта: " . $e->getMessage() . "\n");
    exit(1);
}
