<?php
/**
 * API модуля «Собственное производство» (ПРЦ, тесто для «Пицца Стар»).
 *
 * Отдельное место для работы с производственным цехом: раньше это жило
 * внутри «Заявок поставщикам», где каждая доработка требовала обходить
 * логику обычных поставщиков.
 *
 * Заявки рестораны по-прежнему подают в кабинете, как всем поставщикам —
 * модуль читает те же so_orders, но считает по-своему: штуки → лотки →
 * стопки → паллеты.
 *
 * Маршруты:
 *   GET own-production/suppliers        — цеха (поставщики с признаком ПРЦ)
 *   GET own-production/day?date=        — заказ на день: рестораны × размеры
 */

// Проверка $endpoint — ниже, перед маршрутами: функции нужны и другим файлам.

/** Сколько стопок в паллете. Стопка — четверть паллеты. */
const OP_STACKS_PER_PALLET = 4;

function opRespond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

/**
 * Доступ к модулю. Правит закупка, смотрят сотрудники цеха.
 * Модуль про «Пицца Стар», поэтому нужен доступ к этому юрлицу.
 */
function opRequireUser($pdo, string $need = 'view') {
    $u = getSessionUser($pdo);
    if (!$u) opRespond(['error' => 'Требуется авторизация'], 401);
    global $ROLE_TEMPLATES, $ACCESS_LEVELS;
    $role = $u['role'] ?? 'user';
    if ($role !== 'admin') {
        $perms = resolvePermissions($role, $u['permissions'] ?? null, $ROLE_TEMPLATES);
        $level = $ACCESS_LEVELS[$perms['own-production'] ?? 'none'] ?? 0;
        if ($level < ($ACCESS_LEVELS[$need] ?? 1)) {
            opRespond(['error' => 'Нет доступа к модулю «Собственное производство»'], 403);
        }
        // Юрлицо: цех работает на «Пицца Стар».
        $entities = $u['legal_entities'] ?? [];
        if (is_string($entities)) $entities = json_decode($entities, true) ?: [];
        $hasPs = false;
        foreach ((array)$entities as $e) {
            if (getEntityGroup($e) === 'PS') { $hasPs = true; break; }
        }
        if (!$hasPs) opRespond(['error' => 'Модуль доступен только по «Пицца Стар»'], 403);
    }
    return $u;
}

/**
 * Цеха: поставщики «Пицца Стар» с признаком собственного производства.
 * Если у сотрудника есть привязка к своим поставщикам (внешние работники
 * цеха), список сужается до неё.
 */
function opGetWorkshops(PDO $pdo, $sessionUser = null): array {
    require_once __DIR__ . '/so_loading_sheets.php';
    require_once __DIR__ . '/supplier_orders.php';
    $scope = $sessionUser ? soUserSupplierScope($sessionUser) : [];
    // Проверка цеха дёргает этот список на каждом запросе — держим ответ
    // в памяти запроса, чтобы не ходить в базу дважды.
    static $cache = [];
    $key = implode(',', $scope);
    if (isset($cache[$key])) return $cache[$key];
    $st = $pdo->prepare("
        SELECT id, short_name, full_name, email, legal_entity
        FROM suppliers
        WHERE is_active = 1 AND legal_entity_group = 'PS'
        ORDER BY short_name");
    $st->execute();
    $out = [];
    foreach ($st->fetchAll() as $row) {
        if ($scope && !in_array((string)$row['id'], $scope, true)) continue;
        if (!soLsSupplierEnabled($pdo, (string)$row['id'])) continue;
        $out[] = $row;
    }
    return $cache[$key] = $out;
}

/**
 * Цех из запроса. Без проверки по прямой ссылке можно было бы подставить
 * любого поставщика и прочитать чужие заявки — идентификатор приходит от
 * браузера, а не из сессии.
 */
function opRequireWorkshop(PDO $pdo, string $supplierId, $sessionUser = null): array {
    if ($supplierId !== '') {
        foreach (opGetWorkshops($pdo, $sessionUser) as $w) {
            if ((string)$w['id'] === $supplierId) return $w;
        }
    }
    opRespond(['error' => 'Цех не найден или недоступен'], 403);
}

/**
 * Размеры теста из шаблона цеха: штук в лотке берём из кратности.
 * План перебирает все даты периода, поэтому список запоминаем на время
 * запроса — иначе один и тот же справочник читался бы десятки раз.
 */
function opGetSizes(PDO $pdo, string $supplierId): array {
    static $cache = [];
    if (isset($cache[$supplierId])) return $cache[$supplierId];
    $st = $pdo->prepare("
        SELECT sku, product_name, product_id, multiplicity, sort_order
        FROM so_templates
        WHERE supplier_id = ? AND legal_entity = 'ООО \"Пицца Стар\"' AND is_active = 1
        ORDER BY sort_order, product_name");
    $st->execute([$supplierId]);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $perTray = (int)round((float)$row['multiplicity']);
        $out[] = [
            'sku'          => (string)$row['sku'],
            'product_name' => (string)$row['product_name'],
            // Без карточки товара загрузочные листы не узнают, сколько штук
            // в лотке: они берут кратность именно из products.
            'product_id'   => (string)($row['product_id'] ?? ''),
            'short_name'   => opShortSize((string)$row['product_name'], (string)$row['sku']),
            'per_tray'     => $perTray > 0 ? $perTray : 1,
        ];
    }
    return $cache[$supplierId] = $out;
}

/** «Тесто для пиццы дрожжевое охлажденное 30 см, 7 шт/лоток» → «30 см». */
function opShortSize(string $name, string $sku): string {
    return preg_match('/(\d{2})\s*см/u', $name, $m) ? $m[1] . ' см' : $sku;
}

/** Лотки из штук: неполный лоток всё равно едет лотком. */
function opTrays(float $pieces, int $perTray): int {
    if ($perTray <= 0 || $pieces <= 0) return 0;
    return (int)ceil($pieces / $perTray);
}

/**
 * Заказ на дату: по ресторанам и по размерам теста.
 * Возвращает рестораны с количествами, лотками, стопками и паллетами.
 */
function opCollectDay(PDO $pdo, string $supplierId, string $date): array {
    require_once __DIR__ . '/so_loading_sheets.php';
    $sizes = opGetSizes($pdo, $supplierId);
    $perTrayBySku = [];
    foreach ($sizes as $s) $perTrayBySku[$s['sku']] = $s['per_tray'];

    $st = $pdo->prepare("
        SELECT o.id AS order_id, o.restaurant_number, o.status, o.submitted_at,
               r.dodo_is_number, r.city, r.region, r.address,
               oi.sku, oi.product_name, oi.batch_no,
               COALESCE(oi.admin_qty, oi.quantity) AS qty
        FROM so_orders o
        JOIN so_order_items oi ON oi.order_id = o.id
        JOIN restaurants r ON r.number = o.restaurant_number AND r.legal_entity_group = 'PS'
        WHERE o.supplier_id = ? AND o.delivery_date = ?
          AND o.status IN ('submitted', 'locked')
          AND COALESCE(oi.admin_qty, oi.quantity) > 0
        ORDER BY r.city, CAST(r.dodo_is_number AS UNSIGNED), o.restaurant_number");
    $st->execute([$supplierId, $date]);

    $rests = [];
    foreach ($st->fetchAll() as $row) {
        $num = (string)$row['restaurant_number'];
        if (!isset($rests[$num])) {
            $city = trim((string)($row['city'] ?? ''));
            $addr = trim((string)($row['address'] ?? ''));
            $dodo = trim((string)($row['dodo_is_number'] ?? ''));
            $rests[$num] = [
                'restaurant_number' => $num,
                'dodo_is_number'    => $dodo,
                'city'              => $city,
                'region'            => trim((string)($row['region'] ?? '')),
                'title'             => trim(($city !== '' ? $city : trim((string)$row['region'])) . ' ' . $dodo),
                'address'           => $city !== '' && $addr !== '' ? "$city, $addr" : ($city ?: $addr),
                'order_id'          => (string)$row['order_id'],
                'status'            => (string)$row['status'],
                'qty'               => [],   // sku → штуки
                'qty_by_batch'      => [],   // партия → sku → штуки
                'trays'             => [],   // sku → лотки
                'total_pieces'      => 0,
                'total_trays'       => 0,
            ];
            if ($rests[$num]['title'] === '') $rests[$num]['title'] = formatRestaurantNumber((int)$num);
        }
        $sku = (string)$row['sku'];
        $qty = (float)$row['qty'];
        $batch = (int)($row['batch_no'] ?: 1);
        $rests[$num]['qty'][$sku] = ($rests[$num]['qty'][$sku] ?? 0) + $qty;
        $rests[$num]['qty_by_batch'][$batch][$sku] = ($rests[$num]['qty_by_batch'][$batch][$sku] ?? 0) + $qty;
    }

    // Лотки, стопки и паллеты считаем после сбора всех позиций ресторана.
    foreach ($rests as &$rest) {
        $items = [];
        foreach ($rest['qty'] as $sku => $qty) {
            $perTray = $perTrayBySku[$sku] ?? 0;
            $trays = opTrays($qty, $perTray);
            $rest['trays'][$sku] = $trays;
            $rest['total_pieces'] += $qty;
            $rest['total_trays'] += $trays;
            $items[] = ['sku' => $sku, 'product_name' => $sku, 'qty' => $qty, 'per_tray' => $perTray];
        }
        // soLsBuildStacks отдаёт структуру ['items','stacks','total_trays'],
        // поэтому считаем длину именно вложенного списка стопок.
        $built = soLsBuildStacks($items);
        $rest['stacks'] = count($built['stacks'] ?? []);
        $rest['pallets'] = $rest['stacks'] > 0
            ? round($rest['stacks'] / OP_STACKS_PER_PALLET, 2)
            : 0;
    }
    unset($rest);

    return ['sizes' => $sizes, 'restaurants' => array_values($rests)];
}

/** Итоги по дню: по размерам и общие. */
function opDayTotals(array $day): array {
    $bySku = [];
    $pieces = 0;
    $trays = 0;
    $stacks = 0;
    foreach ($day['restaurants'] as $r) {
        foreach ($r['qty'] as $sku => $qty) {
            if (!isset($bySku[$sku])) $bySku[$sku] = ['pieces' => 0, 'trays' => 0];
            $bySku[$sku]['pieces'] += $qty;
            $bySku[$sku]['trays'] += $r['trays'][$sku] ?? 0;
        }
        $pieces += $r['total_pieces'];
        $trays += $r['total_trays'];
        $stacks += $r['stacks'];
    }
    return [
        'by_sku'  => $bySku,
        'pieces'  => $pieces,
        'trays'   => $trays,
        'stacks'  => $stacks,
        'pallets' => $stacks > 0 ? round($stacks / OP_STACKS_PER_PALLET, 2) : 0,
    ];
}


// ═══════════════════════ Партии теста ═══════════════════════
// Сырое тесто должно созреть, поэтому изготавливают его заранее и не в один
// день с поставкой. Для ресторанов с одной поставкой в неделю объём делят на
// две партии разных дней изготовления, иначе к концу недели тесто перестоит.

/** Дни недели по-русски: 1=пн … 7=вс. */
const OP_DOW_SHORT = [1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 7 => 'вс'];
const OP_DOW_FULL = [1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг',
                     5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье'];

/**
 * График изготовления цеха: день поставки → партии.
 * Экрана настройки у графика больше нет — таблица пустая, и партия всегда
 * получается одна, в день поставки. Чтение оставлено: кабинет ресторана
 * спрашивает партии на каждую дату, и если график когда-нибудь заполнят
 * (вручную или новым экраном), деление заработает без правок кода.
 * @return array dow => [ ['batch_no' => 1, 'production_dow' => 5], ... ]
 */
function opGetProductionSchedule(PDO $pdo, string $supplierId): array {
    $st = $pdo->prepare("SELECT delivery_dow, batch_no, production_dow
                         FROM op_production_schedule
                         WHERE supplier_id = ?
                         ORDER BY delivery_dow, batch_no");
    $st->execute([$supplierId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int)$r['delivery_dow']][] = [
            'batch_no'       => (int)$r['batch_no'],
            'production_dow' => (int)$r['production_dow'],
        ];
    }
    return $out;
}

/**
 * Дата изготовления: ближайший нужный день недели ДО даты поставки.
 * Совпадение дня недели означает «неделей раньше» — в день поставки тесто
 * уже должно быть готово.
 */
function opProductionDate(string $deliveryDate, int $productionDow): string {
    $d = new DateTime($deliveryDate);
    $back = ((int)$d->format('N') - $productionDow + 7) % 7;
    if ($back === 0) $back = 7;
    return $d->modify("-{$back} days")->format('Y-m-d');
}

/**
 * Партии для конкретной даты поставки.
 * Если график не заполнен — одна партия в день поставки: модуль работает
 * и без настройки, просто без разделения.
 */
function opBatchesForDate(array $schedule, string $deliveryDate): array {
    $dow = (int)(new DateTime($deliveryDate))->format('N');
    $rows = $schedule[$dow] ?? [];
    if (!$rows) {
        return [['batch_no' => 1, 'production_date' => $deliveryDate, 'production_dow' => $dow]];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'batch_no'        => $r['batch_no'],
            'production_dow'  => $r['production_dow'],
            'production_date' => opProductionDate($deliveryDate, $r['production_dow']),
        ];
    }
    return $out;
}

/**
 * Партии, которые видит и заказывает ресторан.
 *
 * Правило простое: одна поставка в неделю — две партии, чаще — одна.
 * Раньше вторая партия бралась только из графика изготовления, а его никогда
 * не заполняли, поэтому деления не было ни у кого. График как экран убран,
 * поэтому число партий считаем по частоте поставки, а даты изготовления
 * подставляем, только если график всё-таки заполнен (напрямую в базе).
 *
 * @param bool $splitAllowed поставка ровно раз в неделю
 */
function opBatchesForRestaurant(array $schedule, string $deliveryDate, bool $splitAllowed): array {
    $batches = opBatchesForDate($schedule, $deliveryDate);
    $scheduled = !empty($schedule[(int)(new DateTime($deliveryDate))->format('N')]);
    if (!$splitAllowed) {
        $batches = [$batches[0]];
    } elseif (count($batches) < 2) {
        // Вторая партия без графика: цех сам решает, когда её делать —
        // ресторану важно лишь разделить объём на начало и конец недели.
        $batches[] = [
            'batch_no'        => 2,
            'production_dow'  => $batches[0]['production_dow'],
            'production_date' => $batches[0]['production_date'],
        ];
    }
    foreach ($batches as &$b) $b['scheduled'] = $scheduled;
    unset($b);
    return $batches;
}

/** Сколько дней поставки в неделю у ресторана от этого цеха. */
function opRestaurantDeliveryDows(PDO $pdo, string $supplierId, string $restNum): array {
    require_once __DIR__ . '/so_deadline.php';
    $rows = soGetEffectiveScheduleRows($pdo, $supplierId, null, null, true);
    $dows = [];
    foreach ($rows as $row) {
        if ((string)($row['restaurant_number'] ?? '') !== $restNum) continue;
        $dows[(int)$row['delivery_day']] = true;
    }
    ksort($dows);
    return array_keys($dows);
}

// ═══════════════════════ Кабинет ресторана ═══════════════════════
// Ресторан заказывает тесто в разделе «Заказы» своего кабинета — отдельным
// пунктом, а не среди обычных поставщиков. Заявка при этом ложится в те же
// so_orders: у закупки остаётся история, отчёты и сводки.

/** Сессия ресторана «Пицца Стар». Другим юрлицам модуль не нужен. */
function opRestaurantSession(PDO $pdo) {
    require_once __DIR__ . '/supplier_orders.php';
    $rest = soGetRestaurantSession($pdo);
    if (!$rest) opRespond(['error' => 'Не авторизован'], 401);
    if (($rest['legal_entity_group'] ?? '') !== 'PS') {
        opRespond(['error' => 'Модуль доступен только ресторанам «Пицца Стар»'], 403);
    }
    return $rest;
}

/** Ближайшие даты поставки цеха для ресторана: график + дедлайн. */
function opRestaurantDates(PDO $pdo, string $supplierId, string $restNum, int $daysAhead = 21): array {
    require_once __DIR__ . '/so_deadline.php';
    $tz = new DateTimeZone('Europe/Minsk');
    $today = new DateTime('now', $tz);

    // Дни недели, когда цех возит этот ресторан.
    $rows = soGetEffectiveScheduleRows($pdo, $supplierId, null, null, true);
    $dows = [];
    foreach ($rows as $row) {
        if ((string)($row['restaurant_number'] ?? '') !== $restNum) continue;
        $dows[(int)$row['delivery_day']] = true;
    }
    if (!$dows) return [];

    $out = [];
    for ($i = 0; $i <= $daysAhead; $i++) {
        $d = (clone $today)->modify("+{$i} days");
        $dow = (int)$d->format('N');
        if (!isset($dows[$dow])) continue;
        $date = $d->format('Y-m-d');
        $dl = soCalculateDeadline($pdo, $supplierId, $date);
        $out[] = [
            'date'         => $date,
            'is_closed'    => !empty($dl['is_closed']),
            'deadline_str' => $dl['deadline_str'] ?? null,
        ];
    }
    return $out;
}

// ═══════════════════════ Отчёт цеху на неделю ═══════════════════════
// Цех работает по бумажной книге: три дня на лист (пн-вт-ср и чт-пт-сб),
// внутри дня строка на ресторан, а если поставка одна в неделю — две строки,
// по строке на партию. Колонки повторяют привычную таблицу цеха:
// на каждый размер штуки и лотки, справа всего лотков и паллет.

/** Понедельник недели, в которую попадает дата. */
function opWeekMonday(string $date): string {
    $d = new DateTime($date);
    $back = (int)$d->format('N') - 1;
    return $d->modify("-{$back} days")->format('Y-m-d');
}

/** Строки одного дня: ресторан целиком или по партиям. */
function opWeekRows(array $day): array {
    $perTray = [];
    foreach ($day['sizes'] as $s) $perTray[$s['sku']] = (int)$s['per_tray'];

    $rows = [];
    foreach ($day['restaurants'] as $r) {
        $batches = [];
        foreach (array_keys($r['qty_by_batch'] ?? []) as $b) {
            if ((int)$b > 0) $batches[] = (int)$b;
        }
        sort($batches);
        $parts = count($batches) > 1
            ? array_map(fn($b) => ['no' => $b, 'qty' => $r['qty_by_batch'][$b]], $batches)
            : [['no' => 0, 'qty' => $r['qty']]];

        foreach ($parts as $p) {
            $trays = [];
            $traysTotal = 0;
            foreach ($day['sizes'] as $s) {
                $q = (float)($p['qty'][$s['sku']] ?? 0);
                $t = opTrays($q, $perTray[$s['sku']] ?? 0);
                $trays[$s['sku']] = $t;
                $traysTotal += $t;
            }
            $rows[] = [
                'title'  => $r['title'] . ($p['no'] ? ' — ' . $p['no'] . '-я партия' : ''),
                'qty'    => $p['qty'],
                'trays'  => $trays,
                'total_trays' => $traysTotal,
            ];
        }
    }
    return $rows;
}

/**
 * Книга «Заказ теста на неделю»: два листа по три дня.
 * @return array{status:string, xlsx:?string, filename:string}
 */
function opBuildWeekXlsx(PDO $pdo, string $supplierId, string $anyDate): array {
    require_once __DIR__ . '/../../vendor/autoload.php';
    require_once __DIR__ . '/so_loading_sheets.php';

    $monday = opWeekMonday($anyDate);
    $sizes = opGetSizes($pdo, $supplierId);
    if (!$sizes) return ['status' => 'empty', 'xlsx' => null, 'filename' => ''];

    // Данные по всем дням недели: пустые дни в лист не попадут.
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $date = (new DateTime($monday))->modify("+{$i} days")->format('Y-m-d');
        $day = opCollectDay($pdo, $supplierId, $date);
        if (!$day['restaurants']) continue;
        $days[$date] = $day;
    }
    if (!$days) return ['status' => 'empty', 'xlsx' => null, 'filename' => ''];

    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ss->removeSheetByIndex(0);

    $CENTER = \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER;
    $THIN = \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN;
    $SOLID = \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID;

    // Колонки: A — ресторан, дальше по паре на размер (штуки и лотки).
    // Ни паллет, ни «всего лотков» цех не просил — он считает по размерам.
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(1 + count($sizes) * 2);

    $sheets = [
        ['Пн-Вт-Ср', [1, 2, 3]],
        ['Чт-Пт-Сб', [4, 5, 6, 7]],   // воскресенье попадает сюда, если вдруг есть
    ];

    foreach ($sheets as [$title, $dows]) {
        $sheetDays = [];
        foreach ($days as $date => $day) {
            if (in_array((int)(new DateTime($date))->format('N'), $dows, true)) $sheetDays[$date] = $day;
        }

        $ws = $ss->createSheet();
        $ws->setTitle($title);
        $ws->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        $ws->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
        $ws->getColumnDimension('A')->setWidth(34);
        for ($c = 2; $c <= 2 + count($sizes) * 2; $c++) {
            $ws->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c))->setWidth(11);
        }

        if (!$sheetDays) {
            $ws->setCellValue('A1', 'На эти дни заявок нет');
            $ws->getStyle('A1')->getFont()->setItalic(true)->getColor()->setRGB('8A7F72');
            continue;
        }

        $row = 1;
        // Итог по листу — копим по ходу.
        $sheetQty = [];
        $sheetTrays = [];

        foreach ($sheetDays as $date => $day) {
            $dt = new DateTime($date);
            $ws->setCellValue("A{$row}", OP_DOW_FULL[(int)$dt->format('N')] . ', ' . soLsFmtDate($date));
            $ws->mergeCells("A{$row}:{$lastCol}{$row}");
            $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(13)->getColor()->setRGB('FFFFFF');
            $ws->getStyle("A{$row}:{$lastCol}{$row}")->getFill()->setFillType($SOLID)->getStartColor()->setRGB(SO_LS_BROWN);
            $ws->getRowDimension($row)->setRowHeight(22);
            $row++;

            // Шапка колонок
            $ws->setCellValue("A{$row}", 'Ресторан');
            $col = 2;
            foreach ($sizes as $s) {
                $c1 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                $c2 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1);
                $ws->setCellValue("{$c1}{$row}", $s['short_name'] . ', шт');
                $ws->setCellValue("{$c2}{$row}", 'лотков');
                $col += 2;
            }

            $ws->getStyle("A{$row}:{$lastCol}{$row}")->getFont()->setBold(true)->getColor()->setRGB('502314');
            $ws->getStyle("A{$row}:{$lastCol}{$row}")->getFill()->setFillType($SOLID)->getStartColor()->setRGB('F4EDE4');
            $ws->getStyle("A{$row}:{$lastCol}{$row}")->getAlignment()->setHorizontal($CENTER)->setWrapText(true);
            $ws->getStyle("A{$row}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
            $headRow = $row;
            $row++;

            $dayQty = [];
            $dayTrays = [];
            $zebra = false;

            foreach (opWeekRows($day) as $r) {
                $ws->setCellValue("A{$row}", $r['title']);
                $col = 2;
                foreach ($sizes as $s) {
                    $c1 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                    $c2 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1);
                    $q = (float)($r['qty'][$s['sku']] ?? 0);
                    $t = (int)($r['trays'][$s['sku']] ?? 0);
                    $ws->setCellValue("{$c1}{$row}", $q > 0 ? $q : null);
                    $ws->setCellValue("{$c2}{$row}", $t > 0 ? $t : null);
                    $dayQty[$s['sku']] = ($dayQty[$s['sku']] ?? 0) + $q;
                    $dayTrays[$s['sku']] = ($dayTrays[$s['sku']] ?? 0) + $t;
                    $col += 2;
                }
                if ($zebra) {
                    $ws->getStyle("A{$row}:{$lastCol}{$row}")->getFill()->setFillType($SOLID)->getStartColor()->setRGB('FBF8F4');
                }
                $zebra = !$zebra;
                $row++;
            }

            // Итог дня
            $ws->setCellValue("A{$row}", 'Итого за день');
            $col = 2;
            foreach ($sizes as $s) {
                $c1 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                $c2 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1);
                $ws->setCellValue("{$c1}{$row}", $dayQty[$s['sku']] ?: null);
                $ws->setCellValue("{$c2}{$row}", $dayTrays[$s['sku']] ?: null);
                $sheetQty[$s['sku']] = ($sheetQty[$s['sku']] ?? 0) + ($dayQty[$s['sku']] ?? 0);
                $sheetTrays[$s['sku']] = ($sheetTrays[$s['sku']] ?? 0) + ($dayTrays[$s['sku']] ?? 0);
                $col += 2;
            }
            $ws->getStyle("A{$row}:{$lastCol}{$row}")->getFont()->setBold(true);
            $ws->getStyle("A{$row}:{$lastCol}{$row}")->getFill()->setFillType($SOLID)->getStartColor()->setRGB('F4EDE4');

            // Рамки на блок дня
            $ws->getStyle("A{$headRow}:{$lastCol}{$row}")->getBorders()->getAllBorders()
                ->setBorderStyle($THIN)->getColor()->setRGB('D8CCBD');
            $ws->getStyle("B{$headRow}:{$lastCol}{$row}")->getAlignment()->setHorizontal($CENTER);
            // Штуки и лотки — целые: иначе «160.0» в каждой ячейке.
            $firstDataRow = $headRow + 1;
            $ws->getStyle("B{$firstDataRow}:{$lastCol}{$row}")->getNumberFormat()->setFormatCode('#,##0');
            $row += 2;
        }

        // Итог по листу
        $ws->setCellValue("A{$row}", 'ВСЕГО (' . mb_strtolower($title) . ')');
        $col = 2;
        foreach ($sizes as $s) {
            $c1 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $c2 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1);
            $ws->setCellValue("{$c1}{$row}", $sheetQty[$s['sku']] ?: null);
            $ws->setCellValue("{$c2}{$row}", $sheetTrays[$s['sku']] ?: null);
            $col += 2;
        }

        $ws->getStyle("A{$row}:{$lastCol}{$row}")->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('FFFFFF');
        $ws->getStyle("A{$row}:{$lastCol}{$row}")->getFill()->setFillType($SOLID)->getStartColor()->setRGB(SO_LS_BROWN);
        $ws->getStyle("B{$row}:{$lastCol}{$row}")->getAlignment()->setHorizontal($CENTER);
        $ws->getStyle("B{$row}:{$lastCol}{$row}")->getNumberFormat()->setFormatCode('#,##0');
        $ws->getRowDimension($row)->setRowHeight(20);
    }

    $ss->setActiveSheetIndex(0);
    $tmp = tempnam(sys_get_temp_dir(), 'opweek');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save($tmp);
    $data = file_get_contents($tmp);
    @unlink($tmp);
    $ss->disconnectWorksheets();

    $sunday = (new DateTime($monday))->modify('+5 days')->format('Y-m-d');
    return [
        'status'   => 'ok',
        'xlsx'     => $data,
        'filename' => 'Тесто ' . soLsFmtDate($monday) . '-' . soLsFmtDate($sunday) . '.xlsx',
    ];
}

// ═══════════════════════ Маршруты ═══════════════════════

if (($endpoint ?? '') !== 'own-production') return;

$opParts = explode('/', trim($uri, '/'));
$opAction = $opParts[1] ?? '';

// Маршруты кабинета ходят под сессией ресторана, а не сотрудника портала:
// у них своя проверка внутри (opRestaurantSession).
const OP_CABINET_ACTIONS = ['my-form', 'my-order', 'submit'];
$opUser = in_array($opAction, OP_CABINET_ACTIONS, true) ? null : opRequireUser($pdo);

// ─── Цеха ───
if ($opAction === 'suppliers' && $method === 'GET') {
    opRespond(['suppliers' => opGetWorkshops($pdo, $opUser)]);
}

// ─── Заказ на день ───
if ($opAction === 'day' && $method === 'GET') {
    $supplierId = trim((string)($_GET['supplier_id'] ?? ''));
    $date = trim((string)($_GET['date'] ?? ''));
    if ($supplierId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        opRespond(['error' => 'Нужны цех и дата (ГГГГ-ММ-ДД)'], 400);
    }
    opRequireWorkshop($pdo, $supplierId, $opUser);
    $day = opCollectDay($pdo, $supplierId, $date);
    opRespond([
        'date'        => $date,
        'sizes'       => $day['sizes'],
        'restaurants' => $day['restaurants'],
        'totals'      => opDayTotals($day),
        'stacks_per_pallet' => OP_STACKS_PER_PALLET,
    ]);
}

// ─── Отчёт цеху: неделя выбранной даты, два листа по три дня ───
if ($opAction === 'week-export' && $method === 'GET') {
    $supplierId = trim((string)($_GET['supplier_id'] ?? ''));
    $date = trim((string)($_GET['date'] ?? ''));
    if ($supplierId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        opRespond(['error' => 'Нужны цех и дата (ГГГГ-ММ-ДД)'], 400);
    }
    opRequireWorkshop($pdo, $supplierId, $opUser);
    $book = opBuildWeekXlsx($pdo, $supplierId, $date);
    if ($book['status'] !== 'ok') opRespond(['error' => 'На эту неделю заявок нет'], 404);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $book['filename'] . '"; filename*=UTF-8\'\'' . rawurlencode($book['filename']));
    header('Content-Length: ' . strlen($book['xlsx']));
    echo $book['xlsx'];
    exit;
}

// ─── Кабинет: что можно заказать и на какие даты ───
if ($opAction === 'my-form' && $method === 'GET') {
    $rest = opRestaurantSession($pdo);
    $workshops = opGetWorkshops($pdo);
    if (!$workshops) opRespond(['workshop' => null, 'dates' => [], 'sizes' => []]);
    $shop = $workshops[0];
    $dates = opRestaurantDates($pdo, (string)$shop['id'], (string)$rest['restaurant_number']);
    require_once __DIR__ . '/so_loading_sheets.php';

    // Две партии нужны только там, где поставка одна в неделю: у остальных
    // тесто и так приезжает часто, делить нечего.
    $dows = opRestaurantDeliveryDows($pdo, (string)$shop['id'], (string)$rest['restaurant_number']);
    $splitAllowed = count($dows) === 1;
    $schedule = opGetProductionSchedule($pdo, (string)$shop['id']);
    foreach ($dates as &$d) {
        $batches = opBatchesForRestaurant($schedule, $d['date'], $splitAllowed);
        foreach ($batches as &$b) {
            // Дату изготовления показываем, только если она реально задана
            // графиком: иначе подпись врала бы про день, который никто не выбирал.
            $b['label'] = empty($b['scheduled']) ? ''
                : 'изготовление ' . OP_DOW_SHORT[$b['production_dow']] . ' '
                  . (new DateTime($b['production_date']))->format('d.m');
        }
        unset($b);
        $d['batches'] = $batches;
    }
    unset($d);

    opRespond([
        'workshop' => ['id' => $shop['id'], 'name' => $shop['short_name']],
        'dates'    => $dates,
        'sizes'    => opGetSizes($pdo, (string)$shop['id']),
        'split_allowed'     => $splitAllowed,
        'trays_per_stack'   => SO_LS_TRAYS_PER_STACK,
        'stacks_per_pallet' => OP_STACKS_PER_PALLET,
    ]);
}

// ─── Кабинет: мой заказ на дату ───
if ($opAction === 'my-order' && $method === 'GET') {
    $rest = opRestaurantSession($pdo);
    $supplierId = trim((string)($_GET['supplier_id'] ?? ''));
    $date = trim((string)($_GET['date'] ?? ''));
    if ($supplierId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        opRespond(['error' => 'Нужны цех и дата'], 400);
    }
    opRequireWorkshop($pdo, $supplierId);
    $st = $pdo->prepare("
        SELECT o.id, o.status, o.submitted_at, oi.sku, oi.batch_no,
               COALESCE(oi.admin_qty, oi.quantity) AS qty
        FROM so_orders o
        LEFT JOIN so_order_items oi ON oi.order_id = o.id
        WHERE o.supplier_id = ? AND o.restaurant_number = ? AND o.delivery_date = ?
          AND o.legal_entity = 'ООО \"Пицца Стар\"'");
    $st->execute([$supplierId, $rest['restaurant_number'], $date]);
    $rows = $st->fetchAll();
    $items = [];        // sku → всего штук (совместимость)
    $batches = [];      // sku → партия → штук
    $status = null;
    $submittedAt = null;
    foreach ($rows as $row) {
        $status = $row['status'];
        $submittedAt = $row['submitted_at'];
        if ($row['sku'] === null) continue;
        $sku = (string)$row['sku'];
        $batch = (int)($row['batch_no'] ?: 1);
        $items[$sku] = ($items[$sku] ?? 0) + (float)$row['qty'];
        $batches[$sku][$batch] = ($batches[$sku][$batch] ?? 0) + (float)$row['qty'];
    }
    opRespond(['status' => $status, 'submitted_at' => $submittedAt,
               'items' => $items, 'batches' => $batches]);
}

// ─── Кабинет: подать заявку ───
if ($opAction === 'submit' && $method === 'POST') {
    $rest = opRestaurantSession($pdo);
    require_once __DIR__ . '/so_deadline.php';
    $supplierId = trim((string)($body['supplier_id'] ?? ''));
    $date = trim((string)($body['delivery_date'] ?? ''));
    $rawItems = is_array($body['items'] ?? null) ? $body['items'] : [];
    if ($supplierId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        opRespond(['error' => 'Нужны цех и дата поставки'], 400);
    }
    // Через этот маршрут заказывают только тесто: чужого поставщика в заявку
    // не пропускаем, у него своя механика подачи.
    opRequireWorkshop($pdo, $supplierId);

    // Дата должна быть в графике ресторана и приём по ней открыт.
    $dates = opRestaurantDates($pdo, $supplierId, (string)$rest['restaurant_number']);
    $allowed = null;
    foreach ($dates as $d) { if ($d['date'] === $date) { $allowed = $d; break; } }
    if (!$allowed) opRespond(['error' => 'На эту дату поставки нет'], 422);
    if (!empty($allowed['is_closed'])) {
        opRespond(['error' => 'Приём заявок на эту дату закрыт'
            . ($allowed['deadline_str'] ? ' (до ' . $allowed['deadline_str'] . ')' : '')], 422);
    }

    // Количества: только известные размеры, кратно лотку — цех работает лотками.
    // Формат позиций: либо {sku: штук}, либо {sku: {партия: штук}} — вторая
    // форма приходит от ресторанов с одной поставкой в неделю.
    $sizes = opGetSizes($pdo, $supplierId);
    $bySku = [];
    foreach ($sizes as $s) $bySku[$s['sku']] = $s;

    // Разрешённые партии этой даты: чужой номер в заявку не пропускаем.
    $dows = opRestaurantDeliveryDows($pdo, $supplierId, (string)$rest['restaurant_number']);
    $splitAllowed = count($dows) === 1;
    $schedule = opGetProductionSchedule($pdo, $supplierId);
    $dateBatches = opBatchesForRestaurant($schedule, $date, $splitAllowed);
    $allowedBatches = [];
    foreach ($dateBatches as $b) $allowedBatches[(int)$b['batch_no']] = true;
    $firstBatch = (int)$dateBatches[0]['batch_no'];

    $items = [];
    $errors = [];
    foreach ($rawItems as $sku => $value) {
        $sku = (string)$sku;
        if (!isset($bySku[$sku])) continue;
        $perTray = (int)$bySku[$sku]['per_tray'];
        $parts = is_array($value) ? $value : [$firstBatch => $value];
        foreach ($parts as $batchNo => $qty) {
            $batchNo = (int)$batchNo ?: $firstBatch;
            if (!isset($allowedBatches[$batchNo])) {
                $errors[] = "{$bySku[$sku]['short_name']}: партии {$batchNo} на эту дату нет";
                continue;
            }
            $q = (float)$qty;
            if ($q <= 0) continue;
            if ($perTray > 1 && fmod($q, $perTray) > 0.001) {
                $up = (int)ceil($q / $perTray) * $perTray;
                $errors[] = "{$bySku[$sku]['short_name']}: {$q} шт не делится на лоток ({$perTray} шт), ближайшее — {$up}";
                continue;
            }
            $items[] = [
                'sku'          => $sku,
                'product_name' => $bySku[$sku]['product_name'],
                'product_id'   => $bySku[$sku]['product_id'] ?? '',
                'quantity'     => $q,
                'batch_no'     => $batchNo,
            ];
        }
    }
    if ($errors) opRespond(['error' => implode('; ', $errors)], 422);

    $le = 'ООО "Пицца Стар"';
    try {
        $pdo->beginTransaction();
        $old = $pdo->prepare("SELECT id FROM so_orders WHERE supplier_id = ? AND restaurant_number = ? AND delivery_date = ? AND legal_entity = ?");
        $old->execute([$supplierId, $rest['restaurant_number'], $date, $le]);
        $orderId = $old->fetchColumn();
        $existingOrderId = $orderId;   // была заявка или подаём впервые — для текста уведомления
        if ($orderId) {
            $pdo->prepare("DELETE FROM so_order_items WHERE order_id = ?")->execute([$orderId]);
            $pdo->prepare("UPDATE so_orders SET status = 'submitted', submitted_at = NOW(), updated_at = NOW() WHERE id = ?")
                ->execute([$orderId]);
        } else {
            $pdo->prepare("INSERT INTO so_orders (supplier_id, restaurant_number, delivery_date, order_date, status, submitted_at, legal_entity)
                           VALUES (?, ?, ?, CURDATE(), 'submitted', NOW(), ?)")
                ->execute([$supplierId, $rest['restaurant_number'], $date, $le]);
            $orderId = $pdo->lastInsertId();
        }
        if ($items) {
            $ins = $pdo->prepare("INSERT INTO so_order_items (order_id, product_id, sku, product_name, quantity, batch_no)
                                  VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($items as $it) {
                $ins->execute([$orderId, $it['product_id'], $it['sku'], $it['product_name'],
                               $it['quantity'], $it['batch_no']]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[own-production submit] ' . $e->getMessage());
        opRespond(['error' => 'Не удалось сохранить заявку'], 500);
    }

    // ── Ответ клиенту, потом уведомления ───────────────────────────────────
    // Как у обычных заявок: ресторан должен видеть подтверждение, иначе
    // непонятно, дошла заявка или нет. Ошибки уведомлений подачу не ломают.
    $isNew = empty($existingOrderId);
    http_response_code(200);
    echo json_encode(['success' => true, 'order_id' => (string)$orderId, 'items' => count($items)],
        JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

    $shopName = '';
    try {
        $ns = $pdo->prepare("SELECT short_name FROM suppliers WHERE id = ?");
        $ns->execute([$supplierId]);
        $shopName = (string)($ns->fetchColumn() ?: 'Цех');
    } catch (Throwable $e) { $shopName = 'Цех'; }
    $dateFmt = (new DateTime($date))->format('d.m.Y');

    // Telegram: подробный состав, лотками — ресторан заказывает именно лотки.
    try {
        $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $lines = [$isNew ? '✅ <b>Заявка на тесто отправлена</b>' : '✏️ <b>Заявка на тесто обновлена</b>', ''];
        $lines[] = '🏪 <b>Цех:</b> ' . $esc($shopName);
        $lines[] = '📅 <b>Доставка:</b> ' . $dateFmt;
        if ($items) {
            $lines[] = '';
            $lines[] = '<b>Состав:</b>';
            $multi = count($allowedBatches) > 1;
            foreach ($items as $it) {
                $per = (int)$bySku[$it['sku']]['per_tray'];
                $trays = $per > 0 ? (int)round($it['quantity'] / $per) : 0;
                $batch = $multi ? ' (' . $it['batch_no'] . '-я партия)' : '';
                $lines[] = '• ' . $esc($bySku[$it['sku']]['short_name']) . $batch
                    . ' — <b>' . $trays . ' лотк.</b> (' . (int)$it['quantity'] . ' шт)';
            }
        } else {
            $lines[] = '';
            $lines[] = '<i>Тесто на эту дату не нужно.</i>';
        }
        roNotifyRestaurant($pdo, $rest['restaurant_number'], implode("\n", $lines), 'PS');
    } catch (Throwable $e) { /* уведомление не критично */ }

    // Push — коротко, для тех, у кого стоит приложение.
    try {
        require_once __DIR__ . '/push_send.php';
        pushSendToRestaurant($pdo, (int)$rest['restaurant_number'], 'PS', [
            'title' => $shopName !== '' ? $shopName : 'Тесто',
            'body'  => $items
                ? ($isNew ? "Заявка на тесто на {$dateFmt} принята." : "Заявка на тесто на {$dateFmt} обновлена.")
                : "{$dateFmt}: отмечено, что тесто не нужно.",
            'url'   => '/restaurant/orders/production',
            'tag'   => "op-confirm-{$supplierId}-{$date}",
        ]);
    } catch (Throwable $e) { /* push не критичен */ }
    exit;
}

opRespond(['error' => 'Неизвестный маршрут модуля «Собственное производство»'], 404);
