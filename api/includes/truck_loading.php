<?php
/**
 * API модуля «Загрузка машин» (truck loading).
 * Подключается из index.php. Все переменные ($pdo, $endpoint, $subpoint, $method, $body) доступны.
 *
 * Маршруты:
 *   GET    tl/vehicles                — список типов машин
 *   POST   tl/vehicles                — создать/обновить тип машины
 *   DELETE tl/vehicles/:id            — удалить (деактивировать) тип машины
 *   GET    tl/orders?date=YYYY-MM-DD  — заказы ресторанов на дату с агрегацией
 *   GET    tl/plan?date=YYYY-MM-DD    — загрузить план на дату
 *   POST   tl/plan                    — создать/обновить план
 *   DELETE tl/plan/:id                — удалить план
 *   PATCH  tl/plan/:id/status         — изменить статус плана
 *   POST   tl/auto-assign             — автоматическое распределение (bin-packing)
 */

if ($endpoint !== 'tl') return;

// ═══ Хелперы ═══

function tlRespond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

// ═══ Авторизация и RBAC ═══

$sessionUser = getSessionUser($pdo);
if (!$sessionUser) {
    if (!checkApiKey($pdo)) tlRespond(['error' => 'Unauthorized'], 401);
}
if ($sessionUser) {
    global $ROLE_TEMPLATES, $ACCESS_LEVELS;
    $userRole = $sessionUser['role'] ?? 'user';
    // Доступ к модулю только для admin и manager — защита по роли независимо от прав
    if ($userRole !== 'admin' && $userRole !== 'manager') {
        tlRespond(['error' => 'Модуль доступен только администратору и менеджеру'], 403);
    }
    if ($userRole !== 'admin') {
        $perms = resolvePermissions($userRole, $sessionUser['permissions'] ?? null, $ROLE_TEMPLATES);
        $tlRequired = ($method === 'GET') ? $ACCESS_LEVELS['view'] : $ACCESS_LEVELS['edit'];
        $tlLevel = $ACCESS_LEVELS[$perms['truck-loading'] ?? 'none'] ?? 0;
        if ($tlLevel < $tlRequired) tlRespond(['error' => 'Недостаточно прав'], 403);
    }
}

// Разбор URL: tl/{action}/{param1}/{param2}
$parts = explode('/', trim($uri, '/'));
// parts[0] = 'tl', parts[1] = action, parts[2] = param1, parts[3] = param2
$tlAction = $parts[1] ?? '';
$tlParam1 = $parts[2] ?? null;
$tlParam2 = $parts[3] ?? null;


// ════════════════════════════════════════════
// GET tl/vehicles — список активных типов машин
// ════════════════════════════════════════════
if ($tlAction === 'vehicles' && $method === 'GET') {
    $s = $pdo->query("SELECT id, name, capacity_pallets, capacity_kg, sort_order FROM tl_vehicles WHERE is_active = 1 ORDER BY sort_order, name");
    tlRespond(['vehicles' => $s->fetchAll()]);
}

// ════════════════════════════════════════════
// POST tl/vehicles — создать/обновить тип машины
// ════════════════════════════════════════════
if ($tlAction === 'vehicles' && $method === 'POST') {
    // Проверка прав на edit
    if ($sessionUser) {
        global $ROLE_TEMPLATES, $ACCESS_LEVELS;
        $userRole = $sessionUser['role'] ?? 'user';
        if ($userRole !== 'admin') {
            $perms = resolvePermissions($userRole, $sessionUser['permissions'] ?? null, $ROLE_TEMPLATES);
            $lvl = $ACCESS_LEVELS[$perms['truck-loading'] ?? 'none'] ?? 0;
            if ($lvl < $ACCESS_LEVELS['edit']) tlRespond(['error' => 'Недостаточно прав'], 403);
        }
    }

    $name = trim($body['name'] ?? '');
    $capacityPallets = floatval($body['capacity_pallets'] ?? 0);
    $capacityKg = floatval($body['capacity_kg'] ?? 0);
    $sortOrder = intval($body['sort_order'] ?? 0);

    if (!$name) tlRespond(['error' => 'Не указано название машины'], 400);

    $id = $body['id'] ?? null;
    $pdo->beginTransaction();
    try {
        if ($id) {
            $s = $pdo->prepare("UPDATE tl_vehicles SET name = ?, capacity_pallets = ?, capacity_kg = ?, sort_order = ? WHERE id = ?");
            $s->execute([$name, $capacityPallets, $capacityKg, $sortOrder, $id]);
        } else {
            $s = $pdo->prepare("INSERT INTO tl_vehicles (name, capacity_pallets, capacity_kg, sort_order) VALUES (?, ?, ?, ?)");
            $s->execute([$name, $capacityPallets, $capacityKg, $sortOrder]);
            $id = $pdo->lastInsertId();
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        tlRespond(['error' => 'Ошибка сохранения машины: ' . $e->getMessage()], 500);
    }

    tlRespond(['success' => true, 'id' => (int)$id]);
}

// ════════════════════════════════════════════
// DELETE tl/vehicles/:id — деактивировать тип машины
// ════════════════════════════════════════════
if ($tlAction === 'vehicles' && $method === 'DELETE' && $tlParam1) {
    // Требует full
    if ($sessionUser) {
        global $ROLE_TEMPLATES, $ACCESS_LEVELS;
        $userRole = $sessionUser['role'] ?? 'user';
        if ($userRole !== 'admin') {
            $perms = resolvePermissions($userRole, $sessionUser['permissions'] ?? null, $ROLE_TEMPLATES);
            $lvl = $ACCESS_LEVELS[$perms['truck-loading'] ?? 'none'] ?? 0;
            if ($lvl < $ACCESS_LEVELS['full']) tlRespond(['error' => 'Недостаточно прав'], 403);
        }
    }

    $vehicleId = intval($tlParam1);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE tl_vehicles SET is_active = 0 WHERE id = ?")->execute([$vehicleId]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        tlRespond(['error' => 'Ошибка удаления машины: ' . $e->getMessage()], 500);
    }
    tlRespond(['success' => true]);
}


// ════════════════════════════════════════════
// Хелпер: получить заказы ресторанов на дату
// ════════════════════════════════════════════
function tlGetOrdersForDate($pdo, $date) {
    // Все заказы на дату из всех подходящих активных сессий.
    // Сессии теперь ведутся отдельно по группам юрлиц, поэтому брать только одну
    // "последнюю активную" сессию нельзя — иначе модуль перестаёт видеть часть заказов.

    // Порядок сортировки юрлиц — единственная точка правки при добавлении нового юрлица
    $legalEntityOrder = ['ООО "Бургер БК"', 'ООО "Воглия Матта"', 'ООО "Пицца Стар"'];
    $legalEntityField = 'FIELD(o.legal_entity, ' . implode(', ', array_map(function($e) {
        return $pdo->quote($e);
    }, $legalEntityOrder)) . ')';

    $s = $pdo->prepare("
        SELECT o.id as order_id, o.restaurant_number, o.status, o.legal_entity,
               o.legal_entity_group,
               r.city, r.address, r.region
        FROM ro_orders o
        INNER JOIN ro_sessions rs
            ON rs.id = o.session_id
            AND rs.status = 'active'
            AND rs.week_start <= ?
            AND rs.week_end >= ?
        LEFT JOIN restaurants r
            ON r.number = o.restaurant_number
            AND r.active = 1
            AND r.legal_entity_group = o.legal_entity_group
        WHERE o.delivery_date = ? AND o.status != 'draft'
        ORDER BY {$legalEntityField}, o.restaurant_number
    ");
    $s->execute([$date, $date, $date]);
    $orders = $s->fetchAll();

    if (empty($orders)) return [];

    $orderIds = array_map(function($order) {
        return (int)$order['order_id'];
    }, $orders);
    $ph = implode(',', array_fill(0, count($orderIds), '?'));
    $itemsStmt = $pdo->prepare("
        SELECT oi.id as item_id, oi.order_id, oi.sku, oi.product_name, oi.category, oi.quantity,
               COALESCE(p.weight_brutto, 0) as weight_brutto,
               COALESCE(p.boxes_per_pallet, 0) as boxes_per_pallet,
               COALESCE(p.multiplicity, 1) as multiplicity
        FROM ro_order_items oi
        JOIN ro_orders o ON o.id = oi.order_id
        LEFT JOIN products p
            ON p.sku = oi.sku
            AND p.legal_entity = o.legal_entity
            AND p.is_active = 1
        WHERE oi.order_id IN ({$ph})
        ORDER BY oi.order_id, oi.category, oi.product_name
    ");
    $itemsStmt->execute($orderIds);
    $itemsByOrder = [];
    foreach ($itemsStmt->fetchAll() as $item) {
        $itemsByOrder[(int)$item['order_id']][] = $item;
    }

    $result = [];

    foreach ($orders as $order) {
        $orderId = $order['order_id'];
        $orderItems = $itemsByOrder[(int)$orderId] ?? [];

        $categories = [];
        $itemsList = [];
        $totalWeight = 0;
        $totalPallets = 0;

        foreach ($orderItems as $item) {
            $qty = floatval($item['quantity']);
            $weightBrutto = floatval($item['weight_brutto']); // в граммах
            $bpp = floatval($item['boxes_per_pallet']);
            $mult = floatval($item['multiplicity']);

            // Штучный товар (multiplicity > 1): количество в штуках → коробки
            $boxes = $qty;
            if ($mult > 1) {
                $boxes = $qty / $mult;
            }

            $itemWeight = $qty * $weightBrutto / 1000; // в кг (вес считаем по штукам)
            $itemPallets = ($bpp > 0) ? $boxes / $bpp : 0;

            $cat = $item['category'] ?: 'Сухой';

            // Агрегация по категории
            if (!isset($categories[$cat])) {
                $categories[$cat] = ['weight' => 0, 'pallets_raw' => 0, 'items_count' => 0];
            }
            $categories[$cat]['weight'] += $itemWeight;
            $categories[$cat]['pallets_raw'] += $itemPallets;
            $categories[$cat]['items_count']++;

            $totalWeight += $itemWeight;

            $itemsList[] = [
                'item_id' => (int)$item['item_id'],
                'sku' => $item['sku'],
                'product_name' => $item['product_name'],
                'category' => $cat,
                'quantity' => (int)$qty,
                'weight' => round($itemWeight, 2),
                'pallets' => round($itemPallets, 4),
                'boxes_per_pallet' => (int)$bpp,
            ];
        }

        // Округление паллет по категориям: дробная часть ≤ 0.2 → вниз, > 0.2 → вверх.
        // Если в категории есть товар (raw > 0) — минимум 1 паллета.
        $catResult = [];
        foreach ($categories as $catName => $catData) {
            $raw = $catData['pallets_raw'];
            if ($raw > 0) {
                $frac = $raw - floor($raw);
                $palletsRounded = ($frac > 0.2) ? ceil($raw) : floor($raw);
                if ($palletsRounded < 1) $palletsRounded = 1;
            } else {
                $palletsRounded = 0;
            }
            $totalPallets += $palletsRounded;
            $catResult[$catName] = [
                'weight' => round($catData['weight'], 2),
                'pallets' => (int)$palletsRounded,
                'items_count' => $catData['items_count'],
            ];
        }

        $result[] = [
            'order_id' => (int)$orderId,
            'restaurant_number' => (int)$order['restaurant_number'],
            'legal_entity' => $order['legal_entity'] ?? '',
            'legal_entity_group' => $order['legal_entity_group'] ?? 'BK_VM',
            'city' => $order['city'] ?? '',
            'address' => $order['address'] ?? '',
            'region' => $order['region'] ?? '',
            'total_weight' => round($totalWeight, 2),
            'total_pallets' => (int)$totalPallets,
            'categories' => $catResult,
            'items' => $itemsList,
        ];
    }

    return $result;
}


// ════════════════════════════════════════════
// GET tl/orders?date=YYYY-MM-DD
// ════════════════════════════════════════════
if ($tlAction === 'orders' && $method === 'GET') {
    $date = $_GET['date'] ?? '';
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        tlRespond(['error' => 'Не указана дата (date=YYYY-MM-DD)'], 400);
    }

    $orders = tlGetOrdersForDate($pdo, $date);
    tlRespond(['orders' => $orders]);
}


// ════════════════════════════════════════════
// GET tl/plan?date=YYYY-MM-DD
// ════════════════════════════════════════════
if ($tlAction === 'plan' && $method === 'GET') {
    $date = $_GET['date'] ?? '';
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        tlRespond(['error' => 'Не указана дата (date=YYYY-MM-DD)'], 400);
    }

    $s = $pdo->prepare("SELECT id, delivery_date, allow_mixed_modes, status, note, created_by, created_at, updated_at FROM tl_plans WHERE delivery_date = ?");
    $s->execute([$date]);
    $plan = $s->fetch();

    if (!$plan) {
        tlRespond(['plan' => null]);
    }

    $planId = $plan['id'];

    // Машины плана
    $trucks = $pdo->prepare("
        SELECT id, vehicle_id, custom_name, capacity_pallets, capacity_kg, mode, sort_order
        FROM tl_trucks
        WHERE plan_id = ?
        ORDER BY sort_order, id
    ");
    $trucks->execute([$planId]);
    $truckList = $trucks->fetchAll();

    // Назначения для каждой машины
    foreach ($truckList as &$truck) {
        $assignments = $pdo->prepare("
            SELECT id, assign_type, order_id, category, order_item_id, restaurant_number, pallets, weight_kg, sort_order
            FROM tl_assignments
            WHERE truck_id = ?
            ORDER BY sort_order, id
        ");
        $assignments->execute([$truck['id']]);
        $truck['assignments'] = $assignments->fetchAll();

        // Приведение типов
        $truck['id'] = (int)$truck['id'];
        $truck['vehicle_id'] = $truck['vehicle_id'] ? (int)$truck['vehicle_id'] : null;
        $truck['capacity_pallets'] = floatval($truck['capacity_pallets']);
        $truck['capacity_kg'] = floatval($truck['capacity_kg']);
        $truck['sort_order'] = (int)$truck['sort_order'];

        foreach ($truck['assignments'] as &$a) {
            $a['id'] = (int)$a['id'];
            $a['order_id'] = $a['order_id'] ? (int)$a['order_id'] : null;
            $a['order_item_id'] = $a['order_item_id'] ? (int)$a['order_item_id'] : null;
            $a['restaurant_number'] = $a['restaurant_number'] ? (int)$a['restaurant_number'] : null;
            $a['pallets'] = floatval($a['pallets']);
            $a['weight_kg'] = floatval($a['weight_kg']);
            $a['sort_order'] = (int)$a['sort_order'];
        }
        unset($a);
    }
    unset($truck);

    $plan['id'] = (int)$plan['id'];
    $plan['allow_mixed_modes'] = (bool)$plan['allow_mixed_modes'];
    $plan['trucks'] = $truckList;

    tlRespond(['plan' => $plan]);
}


// ════════════════════════════════════════════
// POST tl/plan — создать/обновить план
// ════════════════════════════════════════════
if ($tlAction === 'plan' && $method === 'POST') {
    $deliveryDate = $body['delivery_date'] ?? '';
    if (!$deliveryDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
        tlRespond(['error' => 'Не указана дата доставки'], 400);
    }

    $allowMixed = $body['allow_mixed_modes'] ?? false;
    $note = $body['note'] ?? '';
    $trucks = $body['trucks'] ?? [];
    $createdBy = resolveActorName($pdo, $sessionUser, 'api');

    try {
        $pdo->beginTransaction();

        // Найти существующий план (upsert)
        $s = $pdo->prepare("SELECT id FROM tl_plans WHERE delivery_date = ?");
        $s->execute([$deliveryDate]);
        $existing = $s->fetch();

        if ($existing) {
            $planId = $existing['id'];
            // Проверить статус: подтверждённый план нельзя перезаписывать
            $statusCheck = $pdo->prepare("SELECT status FROM tl_plans WHERE id = ?");
            $statusCheck->execute([$planId]);
            $currentStatus = $statusCheck->fetchColumn();
            if ($currentStatus === 'confirmed') {
                $pdo->rollBack();
                tlRespond(['error' => 'План подтверждён, верните в черновик перед изменением'], 409);
            }
            // Удалить старые машины (CASCADE удалит assignments)
            $pdo->prepare("DELETE FROM tl_trucks WHERE plan_id = ?")->execute([$planId]);
            // Обновить план
            $pdo->prepare("UPDATE tl_plans SET allow_mixed_modes = ?, note = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$allowMixed ? 1 : 0, $note, $planId]);
        } else {
            $pdo->prepare("INSERT INTO tl_plans (delivery_date, allow_mixed_modes, status, note, created_by, created_at, updated_at) VALUES (?, ?, 'draft', ?, ?, NOW(), NOW())")
                ->execute([$deliveryDate, $allowMixed ? 1 : 0, $note, $createdBy]);
            $planId = $pdo->lastInsertId();
        }

        // Проверка двойной отгрузки: собрать все order_id из входящих назначений
        $incomingOrderIds = [];
        foreach ($trucks as $truck) {
            foreach ($truck['assignments'] ?? [] as $a) {
                if (!empty($a['order_id'])) {
                    $incomingOrderIds[] = intval($a['order_id']);
                }
            }
        }
        $incomingOrderIds = array_unique($incomingOrderIds);

        if (!empty($incomingOrderIds)) {
            $phCheck = implode(',', array_fill(0, count($incomingOrderIds), '?'));
            $dupParams = array_merge($incomingOrderIds, [$planId]);
            $dupStmt = $pdo->prepare("
                SELECT a.order_id, p.delivery_date
                FROM tl_assignments a
                JOIN tl_trucks t ON t.id = a.truck_id
                JOIN tl_plans p ON p.id = t.plan_id
                WHERE p.status = 'confirmed'
                  AND p.id != ?
                  AND a.order_id IN ({$phCheck})
            ");
            // Параметры: сначала planId, потом order_ids
            $dupStmtParams = array_merge([$planId], $incomingOrderIds);
            $dupStmt->execute($dupStmtParams);
            $dups = $dupStmt->fetchAll();
            if (!empty($dups)) {
                $pdo->rollBack();
                $dupInfo = [];
                foreach ($dups as $d) {
                    $dupInfo[] = '#' . $d['order_id'] . ' (план на ' . $d['delivery_date'] . ')';
                }
                tlRespond(['error' => 'Заказы уже в подтверждённом плане: ' . implode(', ', array_unique($dupInfo))], 409);
            }
        }

        // Вставить машины и назначения
        $insertTruck = $pdo->prepare("
            INSERT INTO tl_trucks (plan_id, vehicle_id, custom_name, capacity_pallets, capacity_kg, mode, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $insertAssignment = $pdo->prepare("
            INSERT INTO tl_assignments (truck_id, assign_type, order_id, category, order_item_id, restaurant_number, pallets, weight_kg, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($trucks as $truckIdx => $truck) {
            $insertTruck->execute([
                $planId,
                $truck['vehicle_id'] ?? null,
                $truck['custom_name'] ?? null,
                floatval($truck['capacity_pallets'] ?? 0),
                floatval($truck['capacity_kg'] ?? 0),
                $truck['mode'] ?? 'any',
                $truck['sort_order'] ?? $truckIdx,
            ]);
            $truckId = $pdo->lastInsertId();

            $assignments = $truck['assignments'] ?? [];
            foreach ($assignments as $aIdx => $a) {
                $insertAssignment->execute([
                    $truckId,
                    $a['assign_type'] ?? 'order',
                    $a['order_id'] ?? null,
                    $a['category'] ?? null,
                    $a['order_item_id'] ?? null,
                    $a['restaurant_number'] ?? null,
                    floatval($a['pallets'] ?? 0),
                    floatval($a['weight_kg'] ?? 0),
                    $a['sort_order'] ?? $aIdx,
                ]);
            }
        }

        $pdo->commit();
        tlRespond(['success' => true, 'plan_id' => (int)$planId]);

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('tl/plan POST error: ' . $e->getMessage());
        tlRespond(['error' => 'Ошибка сохранения плана'], 500);
    }
}


// ════════════════════════════════════════════
// DELETE tl/plan/:id — удалить план
// ════════════════════════════════════════════
if ($tlAction === 'plan' && $method === 'DELETE' && $tlParam1 && !$tlParam2) {
    // Требует full
    if ($sessionUser) {
        global $ROLE_TEMPLATES, $ACCESS_LEVELS;
        $userRole = $sessionUser['role'] ?? 'user';
        if ($userRole !== 'admin') {
            $perms = resolvePermissions($userRole, $sessionUser['permissions'] ?? null, $ROLE_TEMPLATES);
            $lvl = $ACCESS_LEVELS[$perms['truck-loading'] ?? 'none'] ?? 0;
            if ($lvl < $ACCESS_LEVELS['full']) tlRespond(['error' => 'Недостаточно прав'], 403);
        }
    }

    $planId = intval($tlParam1);

    // Нельзя удалить подтверждённый план
    $statusChk = $pdo->prepare("SELECT status FROM tl_plans WHERE id = ?");
    $statusChk->execute([$planId]);
    $planStatus = $statusChk->fetchColumn();
    if ($planStatus === 'confirmed') {
        tlRespond(['error' => 'Подтверждённый план удалить нельзя. Сначала верните его в черновик.'], 409);
    }

    try {
        $pdo->beginTransaction();
        // Удалить назначения через машины
        $pdo->prepare("DELETE a FROM tl_assignments a JOIN tl_trucks t ON a.truck_id = t.id WHERE t.plan_id = ?")->execute([$planId]);
        // Удалить машины
        $pdo->prepare("DELETE FROM tl_trucks WHERE plan_id = ?")->execute([$planId]);
        // Удалить план
        $pdo->prepare("DELETE FROM tl_plans WHERE id = ?")->execute([$planId]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('tl/plan DELETE error: ' . $e->getMessage());
        tlRespond(['error' => 'Ошибка удаления плана'], 500);
    }

    tlRespond(['success' => true]);
}


// ════════════════════════════════════════════
// PATCH tl/plan/:id/status — изменить статус
// ════════════════════════════════════════════
if ($tlAction === 'plan' && $method === 'PATCH' && $tlParam1 && $tlParam2 === 'status') {
    $planId = intval($tlParam1);
    $newStatus = $body['status'] ?? '';

    // Допустимые статусы (по ENUM в tl_plans)
    if (!in_array($newStatus, ['draft', 'confirmed'])) {
        tlRespond(['error' => 'Некорректный статус (допустимо: draft, confirmed)'], 400);
    }

    // Подтверждение плана требует уровня full
    if ($newStatus === 'confirmed' && $sessionUser) {
        global $ROLE_TEMPLATES, $ACCESS_LEVELS;
        $userRole = $sessionUser['role'] ?? 'user';
        if ($userRole !== 'admin') {
            $perms = resolvePermissions($userRole, $sessionUser['permissions'] ?? null, $ROLE_TEMPLATES);
            $lvl = $ACCESS_LEVELS[$perms['truck-loading'] ?? 'none'] ?? 0;
            if ($lvl < $ACCESS_LEVELS['full']) {
                tlRespond(['error' => 'Подтверждение плана доступно только с правом full'], 403);
            }
        }
    }

    $pdo->prepare("UPDATE tl_plans SET status = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$newStatus, $planId]);

    tlRespond(['success' => true]);
}


// ════════════════════════════════════════════
// POST tl/auto-assign — автоматическое распределение
// ════════════════════════════════════════════
if ($tlAction === 'auto-assign' && $method === 'POST') {
    $date = $body['date'] ?? '';
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        tlRespond(['error' => 'Не указана дата'], 400);
    }

    $vehicleSpecs = $body['vehicles'] ?? []; // [{id, count}]
    $allowMixed = (bool)($body['allow_mixed'] ?? false);

    if (empty($vehicleSpecs)) {
        tlRespond(['error' => 'Не указаны типы машин'], 400);
    }

    // Загрузить характеристики типов машин
    $vehicleIds = array_map(function($v) { return intval($v['id']); }, $vehicleSpecs);
    $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));
    $s = $pdo->prepare("SELECT id, name, capacity_pallets, capacity_kg FROM tl_vehicles WHERE id IN ($placeholders) AND is_active = 1");
    $s->execute($vehicleIds);
    $vehicleMap = [];
    foreach ($s->fetchAll() as $v) {
        $vehicleMap[$v['id']] = $v;
    }

    // Развернуть в список доступных машин с учётом count, отсортировать по capacity
    $availableVehicles = [];
    foreach ($vehicleSpecs as $spec) {
        $vid = intval($spec['id']);
        $count = intval($spec['count'] ?? 1);
        if (!isset($vehicleMap[$vid])) continue;
        for ($i = 0; $i < $count; $i++) {
            $availableVehicles[] = [
                'vehicle_id' => $vid,
                'name' => $vehicleMap[$vid]['name'],
                'capacity_pallets' => floatval($vehicleMap[$vid]['capacity_pallets']),
                'capacity_kg' => floatval($vehicleMap[$vid]['capacity_kg']),
            ];
        }
    }

    // Отсортировать по вместимости (от большей к меньшей)
    usort($availableVehicles, function($a, $b) {
        return $b['capacity_pallets'] <=> $a['capacity_pallets'];
    });

    // Все типы машин для создания новых (отсортированы по capacity ASC — для выбора наименьшей подходящей)
    $allVehicleTypes = array_values($vehicleMap);
    usort($allVehicleTypes, function($a, $b) {
        return floatval($a['capacity_pallets']) <=> floatval($b['capacity_pallets']);
    });

    // Загрузить заказы
    $orders = tlGetOrdersForDate($pdo, $date);
    if (empty($orders)) {
        tlRespond(['trucks' => []]);
    }

    // Сформировать единицы загрузки
    $units = [];

    foreach ($orders as $order) {
        if ($allowMixed) {
            // Единица = целый заказ
            $units[] = [
                'assign_type' => 'order',
                'order_id' => $order['order_id'],
                'category' => null,
                'restaurant_number' => $order['restaurant_number'],
                'pallets' => $order['total_pallets'],
                'weight_kg' => $order['total_weight'],
                'mode' => 'any',
            ];
        } else {
            // Единица = заказ по категории
            foreach ($order['categories'] as $catName => $catData) {
                if ($catName === 'Сухой') $mode = 'dry';
                elseif ($catName === 'Холод') $mode = 'cold';
                elseif ($catName === 'Мороз') $mode = 'frozen';
                else {
                    $mode = 'any';
                    error_log('truck_loading: unknown category mode for ' . $catName);
                }

                $units[] = [
                    'assign_type' => 'category',
                    'order_id' => $order['order_id'],
                    'category' => $catName,
                    'restaurant_number' => $order['restaurant_number'],
                    'pallets' => $catData['pallets'],
                    'weight_kg' => $catData['weight'],
                    'mode' => $mode,
                ];
            }
        }
    }

    // First Fit Decreasing: отсортировать единицы по убыванию паллет
    usort($units, function($a, $b) {
        return $b['pallets'] <=> $a['pallets'];
    });

    // Машины-контейнеры
    $resultTrucks = [];

    foreach ($units as $unit) {
        $placed = false;

        // Попробовать разместить в существующую машину
        foreach ($resultTrucks as &$truck) {
            // Проверка совместимости режимов
            if (!$allowMixed && $truck['mode'] !== 'any' && $unit['mode'] !== 'any' && $truck['mode'] !== $unit['mode']) {
                continue;
            }

            $remainingPallets = $truck['capacity_pallets'] - $truck['used_pallets'];
            $remainingKg = $truck['capacity_kg'] - $truck['used_kg'];

            if ($unit['pallets'] <= $remainingPallets && $unit['weight_kg'] <= $remainingKg) {
                $truck['assignments'][] = [
                    'assign_type' => $unit['assign_type'],
                    'order_id' => $unit['order_id'],
                    'category' => $unit['category'],
                    'order_item_id' => null,
                    'restaurant_number' => $unit['restaurant_number'],
                    'pallets' => $unit['pallets'],
                    'weight_kg' => $unit['weight_kg'],
                    'sort_order' => count($truck['assignments']),
                ];
                $truck['used_pallets'] += $unit['pallets'];
                $truck['used_kg'] += $unit['weight_kg'];

                // Установить mode если ещё any
                if ($truck['mode'] === 'any' && $unit['mode'] !== 'any') {
                    $truck['mode'] = $unit['mode'];
                }

                $placed = true;
                break;
            }
        }
        unset($truck);

        if (!$placed) {
            // Попробовать взять из доступного пула
            $newVehicle = null;

            if (!empty($availableVehicles)) {
                // Найти наименьшую подходящую из пула
                foreach ($availableVehicles as $avIdx => $av) {
                    if ($unit['pallets'] <= $av['capacity_pallets'] && $unit['weight_kg'] <= $av['capacity_kg']) {
                        $newVehicle = $av;
                        array_splice($availableVehicles, $avIdx, 1);
                        break;
                    }
                }
                // Если не нашли подходящую — берём самую большую
                if (!$newVehicle && !empty($availableVehicles)) {
                    $newVehicle = array_shift($availableVehicles);
                }
            }

            // Если пул пуст — создать новую машину (наименьший подходящий тип)
            if (!$newVehicle) {
                foreach ($allVehicleTypes as $vt) {
                    if ($unit['pallets'] <= floatval($vt['capacity_pallets']) && $unit['weight_kg'] <= floatval($vt['capacity_kg'])) {
                        $newVehicle = [
                            'vehicle_id' => (int)$vt['id'],
                            'name' => $vt['name'],
                            'capacity_pallets' => floatval($vt['capacity_pallets']),
                            'capacity_kg' => floatval($vt['capacity_kg']),
                        ];
                        break;
                    }
                }
                // Если даже самая большая не подходит — берём самую большую
                if (!$newVehicle && !empty($allVehicleTypes)) {
                    $biggest = end($allVehicleTypes);
                    $newVehicle = [
                        'vehicle_id' => (int)$biggest['id'],
                        'name' => $biggest['name'],
                        'capacity_pallets' => floatval($biggest['capacity_pallets']),
                        'capacity_kg' => floatval($biggest['capacity_kg']),
                    ];
                }
            }

            if (!$newVehicle) {
                // Нет типов машин вообще — ошибка
                tlRespond(['error' => 'Нет доступных типов машин'], 400);
            }

            $resultTrucks[] = [
                'vehicle_id' => (int)$newVehicle['vehicle_id'],
                'custom_name' => null,
                'capacity_pallets' => $newVehicle['capacity_pallets'],
                'capacity_kg' => $newVehicle['capacity_kg'],
                'mode' => (!$allowMixed && $unit['mode'] !== 'any') ? $unit['mode'] : 'any',
                'used_pallets' => $unit['pallets'],
                'used_kg' => $unit['weight_kg'],
                'sort_order' => count($resultTrucks),
                'assignments' => [
                    [
                        'assign_type' => $unit['assign_type'],
                        'order_id' => $unit['order_id'],
                        'category' => $unit['category'],
                        'order_item_id' => null,
                        'restaurant_number' => $unit['restaurant_number'],
                        'pallets' => $unit['pallets'],
                        'weight_kg' => $unit['weight_kg'],
                        'sort_order' => 0,
                    ],
                ],
            ];
        }
    }

    // Очистить служебные поля перед ответом
    foreach ($resultTrucks as &$truck) {
        unset($truck['used_pallets'], $truck['used_kg']);
    }
    unset($truck);

    tlRespond(['trucks' => $resultTrucks]);
}


// Если ни один маршрут не сработал
tlRespond(['error' => 'Неизвестный маршрут'], 404);
