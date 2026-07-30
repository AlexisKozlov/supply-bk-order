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
 *   GET own-production/plan?from=&to=   — план производства по датам
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

/** Цеха: поставщики «Пицца Стар» с признаком собственного производства. */
function opGetWorkshops(PDO $pdo): array {
    require_once __DIR__ . '/so_loading_sheets.php';
    $st = $pdo->prepare("
        SELECT id, short_name, full_name, email, legal_entity
        FROM suppliers
        WHERE is_active = 1 AND legal_entity_group = 'PS'
        ORDER BY short_name");
    $st->execute();
    $out = [];
    foreach ($st->fetchAll() as $row) {
        if (!soLsSupplierEnabled($pdo, (string)$row['id'])) continue;
        $out[] = $row;
    }
    return $out;
}

/** Размеры теста из шаблона цеха: штук в лотке берём из кратности. */
function opGetSizes(PDO $pdo, string $supplierId): array {
    $st = $pdo->prepare("
        SELECT sku, product_name, multiplicity, sort_order
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
            'short_name'   => opShortSize((string)$row['product_name'], (string)$row['sku']),
            'per_tray'     => $perTray > 0 ? $perTray : 1,
        ];
    }
    return $out;
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
               oi.sku, oi.product_name, COALESCE(oi.admin_qty, oi.quantity) AS qty
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
                'trays'             => [],   // sku → лотки
                'total_pieces'      => 0,
                'total_trays'       => 0,
            ];
            if ($rests[$num]['title'] === '') $rests[$num]['title'] = formatRestaurantNumber((int)$num);
        }
        $sku = (string)$row['sku'];
        $qty = (float)$row['qty'];
        $rests[$num]['qty'][$sku] = ($rests[$num]['qty'][$sku] ?? 0) + $qty;
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
    opRespond(['suppliers' => opGetWorkshops($pdo)]);
}

// ─── Заказ на день ───
if ($opAction === 'day' && $method === 'GET') {
    $supplierId = trim((string)($_GET['supplier_id'] ?? ''));
    $date = trim((string)($_GET['date'] ?? ''));
    if ($supplierId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        opRespond(['error' => 'Нужны цех и дата (ГГГГ-ММ-ДД)'], 400);
    }
    $day = opCollectDay($pdo, $supplierId, $date);
    opRespond([
        'date'        => $date,
        'sizes'       => $day['sizes'],
        'restaurants' => $day['restaurants'],
        'totals'      => opDayTotals($day),
        'stacks_per_pallet' => OP_STACKS_PER_PALLET,
    ]);
}

// ─── План производства по датам ───
if ($opAction === 'plan' && $method === 'GET') {
    $supplierId = trim((string)($_GET['supplier_id'] ?? ''));
    $from = trim((string)($_GET['from'] ?? ''));
    $to   = trim((string)($_GET['to'] ?? ''));
    if ($supplierId === '') opRespond(['error' => 'Не указан цех'], 400);
    foreach ([$from, $to] as $d) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) opRespond(['error' => 'Нужны даты периода'], 400);
    }
    if ($to < $from) opRespond(['error' => 'Конец периода раньше начала'], 400);

    // Даты, на которые есть заявки цеху.
    $st = $pdo->prepare("
        SELECT DISTINCT delivery_date
        FROM so_orders
        WHERE supplier_id = ? AND delivery_date BETWEEN ? AND ?
          AND status IN ('submitted', 'locked')
        ORDER BY delivery_date");
    $st->execute([$supplierId, $from, $to]);
    $dates = $st->fetchAll(PDO::FETCH_COLUMN);

    $sizes = opGetSizes($pdo, $supplierId);
    $days = [];
    foreach ($dates as $date) {
        $day = opCollectDay($pdo, $supplierId, $date);
        $t = opDayTotals($day);
        $days[] = [
            'date'        => $date,
            'restaurants' => count($day['restaurants']),
            'by_sku'      => $t['by_sku'],
            'pieces'      => $t['pieces'],
            'trays'       => $t['trays'],
            'stacks'      => $t['stacks'],
            'pallets'     => $t['pallets'],
        ];
    }
    opRespond(['sizes' => $sizes, 'days' => $days, 'stacks_per_pallet' => OP_STACKS_PER_PALLET]);
}

// ─── Кабинет: что можно заказать и на какие даты ───
if ($opAction === 'my-form' && $method === 'GET') {
    $rest = opRestaurantSession($pdo);
    $workshops = opGetWorkshops($pdo);
    if (!$workshops) opRespond(['workshop' => null, 'dates' => [], 'sizes' => []]);
    $shop = $workshops[0];
    $dates = opRestaurantDates($pdo, (string)$shop['id'], (string)$rest['restaurant_number']);
    require_once __DIR__ . '/so_loading_sheets.php';
    opRespond([
        'workshop' => ['id' => $shop['id'], 'name' => $shop['short_name']],
        'dates'    => $dates,
        'sizes'    => opGetSizes($pdo, (string)$shop['id']),
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
    $st = $pdo->prepare("
        SELECT o.id, o.status, o.submitted_at, oi.sku, COALESCE(oi.admin_qty, oi.quantity) AS qty
        FROM so_orders o
        LEFT JOIN so_order_items oi ON oi.order_id = o.id
        WHERE o.supplier_id = ? AND o.restaurant_number = ? AND o.delivery_date = ?
          AND o.legal_entity = 'ООО \"Пицца Стар\"'");
    $st->execute([$supplierId, $rest['restaurant_number'], $date]);
    $rows = $st->fetchAll();
    $items = [];
    $status = null;
    $submittedAt = null;
    foreach ($rows as $row) {
        $status = $row['status'];
        $submittedAt = $row['submitted_at'];
        if ($row['sku'] !== null) $items[(string)$row['sku']] = (float)$row['qty'];
    }
    opRespond(['status' => $status, 'submitted_at' => $submittedAt, 'items' => $items]);
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

    // Дата должна быть в графике ресторана и приём по ней открыт.
    $dates = opRestaurantDates($pdo, $supplierId, (string)$rest['restaurant_number']);
    $allowed = null;
    foreach ($dates as $d) { if ($d['date'] === $date) { $allowed = $d; break; } }
    if (!$allowed) opRespond(['error' => 'На эту дату поставки нет'], 422);
    if (!empty($allowed['is_closed'])) {
        opRespond(['error' => 'Приём заявок на эту дату закрыт'
            . ($allowed['deadline_str'] ? ' (до ' . $allowed['deadline_str'] . ')' : '')], 422);
    }

    // Количества: только известные размеры, кратно лотку — цех печёт лотками.
    $sizes = opGetSizes($pdo, $supplierId);
    $bySku = [];
    foreach ($sizes as $s) $bySku[$s['sku']] = $s;
    $items = [];
    $errors = [];
    foreach ($rawItems as $sku => $qty) {
        $sku = (string)$sku;
        if (!isset($bySku[$sku])) continue;
        $q = (float)$qty;
        if ($q <= 0) continue;
        $perTray = (int)$bySku[$sku]['per_tray'];
        if ($perTray > 1 && fmod($q, $perTray) > 0.001) {
            $up = (int)ceil($q / $perTray) * $perTray;
            $errors[] = "{$bySku[$sku]['short_name']}: {$q} шт не делится на лоток ({$perTray} шт), ближайшее — {$up}";
            continue;
        }
        $items[] = [
            'sku'          => $sku,
            'product_name' => $bySku[$sku]['product_name'],
            'quantity'     => $q,
        ];
    }
    if ($errors) opRespond(['error' => implode('; ', $errors)], 422);

    $le = 'ООО "Пицца Стар"';
    try {
        $pdo->beginTransaction();
        $old = $pdo->prepare("SELECT id FROM so_orders WHERE supplier_id = ? AND restaurant_number = ? AND delivery_date = ? AND legal_entity = ?");
        $old->execute([$supplierId, $rest['restaurant_number'], $date, $le]);
        $orderId = $old->fetchColumn();
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
            $ins = $pdo->prepare("INSERT INTO so_order_items (order_id, product_id, sku, product_name, quantity) VALUES (?, '', ?, ?, ?)");
            foreach ($items as $it) $ins->execute([$orderId, $it['sku'], $it['product_name'], $it['quantity']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[own-production submit] ' . $e->getMessage());
        opRespond(['error' => 'Не удалось сохранить заявку'], 500);
    }

    opRespond(['success' => true, 'order_id' => (string)$orderId, 'items' => count($items)]);
}

opRespond(['error' => 'Неизвестный маршрут модуля «Собственное производство»'], 404);
