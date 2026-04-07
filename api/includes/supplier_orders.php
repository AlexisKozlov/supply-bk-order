<?php
/**
 * API заявок поставщикам — универсальный модуль.
 * Подключается из index.php. Переменные ($pdo, $endpoint, $subpoint, $method, $body, $uri) через global.
 *
 * Маршруты для ресторанов (авторизация через ro_users / X-RO-Token):
 *   GET    so/suppliers           — список поставщиков с графиком для ресторана
 *   GET    so/products/:suppId    — товары по поставщику (шаблон)
 *   GET    so/my-orders           — история заявок
 *   GET    so/my-order/:suppId/:date — моя заявка на дату
 *   POST   so/submit-order        — отправить заявку
 *
 * Маршруты для закупщиков (сессия основного приложения):
 *   GET    so/admin/status        — сводка заявок (по поставщику + дате)
 *   GET    so/admin/orders        — список заявок по дням
 *   GET    so/admin/order/:id     — детали заявки
 *   PATCH  so/admin/order/:id     — редактировать заявку
 *   DELETE so/admin/order/:id     — удалить заявку
 *   POST   so/admin/session       — управление сессиями
 *   GET    so/admin/sessions      — список сессий
 *   GET    so/admin/schedules     — графики поставок
 *   POST   so/admin/schedules     — сохранить графики
 *   GET    so/admin/templates     — шаблоны товаров
 *   POST   so/admin/templates     — сохранить шаблон
 *   GET    so/admin/export        — Excel-экспорт
 */

if ($endpoint !== 'so') return;

// ═══ Хелперы ═══

function soRespond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function soGetRestaurantSession($pdo) {
    $token = $_SERVER['HTTP_X_RO_TOKEN'] ?? '';
    if (!$token) return null;
    $s = $pdo->prepare("
        SELECT ru.id, ru.restaurant_number, ru.legal_entity, ru.session_active_until,
               r.id as restaurant_id, r.region, r.city, r.address
        FROM ro_users ru
        LEFT JOIN restaurants r ON r.number = ru.restaurant_number AND r.active = 1
        WHERE ru.session_token = ? AND ru.is_active = 1
    ");
    $s->execute([$token]);
    $user = $s->fetch();
    if (!$user) return null;
    if ($user['session_active_until'] && strtotime($user['session_active_until']) < time()) return null;
    return $user;
}

function soGetActiveSession($pdo, $supplierId) {
    $s = $pdo->prepare("SELECT * FROM so_sessions WHERE supplier_id = ? AND status = 'active' AND week_end >= CURDATE() ORDER BY week_start DESC LIMIT 1");
    $s->execute([$supplierId]);
    return $s->fetch() ?: null;
}

function soCheckDeadline($pdo, $session, $orderDate) {
    // orderDate — дата, когда ресторан должен подать заявку (по графику)
    $tz = new DateTimeZone('Europe/Minsk');
    $now = new DateTime('now', $tz);
    $today = $now->format('Y-m-d');

    // Получаем дедлайн
    $deadlineTime = $session['deadline_time'] ?? '14:00:00';

    // Проверяем переопределение
    $s = $pdo->prepare("SELECT deadline_time FROM so_deadline_overrides WHERE session_id = ? AND delivery_date = ?");
    $s->execute([$session['id'], $orderDate]);
    $override = $s->fetch();
    if ($override) $deadlineTime = $override['deadline_time'];

    if ($today < $orderDate) return ['status' => 'open', 'deadline' => $deadlineTime];
    if ($today > $orderDate) return ['status' => 'closed', 'deadline' => $deadlineTime];

    // Сегодня = день подачи
    $currentTime = $now->format('H:i:s');
    if ($currentTime < $deadlineTime) {
        return ['status' => 'open', 'deadline' => $deadlineTime];
    }
    return ['status' => 'closed', 'deadline' => $deadlineTime];
}

// ═══ Парсинг маршрута ═══

$soParts = explode('/', $uri);
// uri = "so/action/param1/param2"
$soAction = $soParts[1] ?? '';
$soParam1 = $soParts[2] ?? null;
$soParam2 = $soParts[3] ?? null;
$soParam3 = $soParts[4] ?? null;

$dayNames = [1=>'ПН', 2=>'ВТ', 3=>'СР', 4=>'ЧТ', 5=>'ПТ', 6=>'С��', 7=>'ВС'];
$dayNamesFull = [1=>'Понедельник',2=>'Вторник',3=>'Среда',4=>'Четверг',5=>'Пятница',6=>'Суббота',7=>'Воскресенье'];

// ═══════════════════════════════════════════════
// Маршруты для ресторанов
// ════��══════════════════════════════════════════

// --- Список поставщиков с графиком ---
if ($soAction === 'suppliers' && $method === 'GET') {
    $rest = soGetRestaurantSession($pdo);
    if (!$rest) soRespond(['error' => 'Не авторизован'], 401);

    $s = $pdo->prepare("
        SELECT DISTINCT s.id, s.short_name, s.full_name
        FROM so_supplier_schedules ss
        JOIN suppliers s ON s.id = ss.supplier_id AND s.is_active = 1
        WHERE ss.restaurant_id = ? AND ss.is_active = 1
        ORDER BY s.short_name
    ");
    $s->execute([$rest['restaurant_id']]);
    $suppliers = $s->fetchAll();

    // Для каждого поставщика — график и активные сессии
    $result = [];
    foreach ($suppliers as $sup) {
        // График
        $sch = $pdo->prepare("SELECT order_day, delivery_day FROM so_supplier_schedules WHERE supplier_id = ? AND restaurant_id = ? AND is_active = 1 ORDER BY order_day");
        $sch->execute([$sup['id'], $rest['restaurant_id']]);
        $schedule = $sch->fetchAll();

        $scheduleFormatted = [];
        foreach ($schedule as $sc) {
            $scheduleFormatted[] = [
                'order_day' => (int)$sc['order_day'],
                'order_day_name' => $dayNames[(int)$sc['order_day']] ?? '',
                'delivery_day' => (int)$sc['delivery_day'],
                'delivery_day_name' => $dayNames[(int)$sc['delivery_day']] ?? '',
            ];
        }

        // Активная сессия
        $session = soGetActiveSession($pdo, $sup['id']);

        // Доступные даты заказа на эту неделю
        $availableDates = [];
        if ($session) {
            $weekStart = new DateTime($session['week_start']);
            $weekEnd = new DateTime($session['week_end']);

            foreach ($schedule as $sc) {
                $orderDow = (int)$sc['order_day'];
                $deliveryDow = (int)$sc['delivery_day'];

                // Находим дату дня заказа в рамках сессии
                $orderDateObj = clone $weekStart;
                $currentDow = (int)$orderDateObj->format('N');
                $diff = $orderDow - $currentDow;
                if ($diff < 0) $diff += 7;
                $orderDateObj->modify("+{$diff} days");

                // Дата поставки
                $deliveryDateObj = clone $weekStart;
                $diff2 = $deliveryDow - $currentDow;
                if ($diff2 < 0) $diff2 += 7;
                $deliveryDateObj->modify("+{$diff2} days");
                // Если день поставки <= день заказа по номеру, поставка на следующей неделе
                if ($deliveryDow <= $orderDow) {
                    $deliveryDateObj->modify('+7 days');
                }

                if ($orderDateObj > $weekEnd) continue;

                $orderDateStr = $orderDateObj->format('Y-m-d');
                $deliveryDateStr = $deliveryDateObj->format('Y-m-d');

                $deadlineInfo = soCheckDeadline($pdo, $session, $orderDateStr);

                // Проверяем есть ли уже заявка
                $os = $pdo->prepare("SELECT id, status, submitted_at FROM so_orders WHERE session_id = ? AND restaurant_number = ? AND delivery_date = ?");
                $os->execute([$session['id'], $rest['restaurant_number'], $deliveryDateStr]);
                $order = $os->fetch();

                $availableDates[] = [
                    'order_date' => $orderDateStr,
                    'order_day_name' => $dayNamesFull[$orderDow] ?? '',
                    'delivery_date' => $deliveryDateStr,
                    'delivery_day_name' => $dayNamesFull[$deliveryDow] ?? '',
                    'deadline' => $deadlineInfo['deadline'],
                    'deadline_status' => $deadlineInfo['status'],
                    'order' => $order ? [
                        'id' => (int)$order['id'],
                        'status' => $order['status'],
                        'submitted_at' => $order['submitted_at'],
                    ] : null,
                ];
            }
        }

        $result[] = [
            'id' => $sup['id'],
            'name' => $sup['short_name'],
            'full_name' => $sup['full_name'],
            'schedule' => $scheduleFormatted,
            'session' => $session ? [
                'id' => (int)$session['id'],
                'week_start' => $session['week_start'],
                'week_end' => $session['week_end'],
            ] : null,
            'available_dates' => $availableDates,
        ];
    }

    soRespond(['suppliers' => $result]);
}

// --- Товары по поставщику (из шаблона) ---
if ($soAction === 'products' && $method === 'GET' && $soParam1) {
    $rest = soGetRestaurantSession($pdo);
    if (!$rest) soRespond(['error' => 'Не авторизован'], 401);

    $supplierId = $soParam1;
    $le = $rest['legal_entity'];

    // Из шаблона
    $s = $pdo->prepare("
        SELECT t.id, t.product_id, t.sku, t.product_name, t.sort_order,
               COALESCE(t.multiplicity, p.multiplicity) as multiplicity,
               t.min_qty,
               p.qty_per_box, p.unit_of_measure
        FROM so_templates t
        LEFT JOIN products p ON p.id = t.product_id
        WHERE t.supplier_id = ? AND t.legal_entity = ? AND t.is_active = 1
        ORDER BY t.sort_order, t.product_name
    ");
    $s->execute([$supplierId, $le]);
    $products = $s->fetchAll();

    // Если шаблон пуст — берём товары этого поставщика из products
    if (empty($products)) {
        $s = $pdo->prepare("
            SELECT id as product_id, sku, name as product_name, qty_per_box, unit_of_measure
            FROM products
            WHERE supplier = (SELECT short_name FROM suppliers WHERE id = ?)
              AND legal_entity = ? AND is_active = 1
            ORDER BY name
        ");
        $s->execute([$supplierId, $le]);
        $products = $s->fetchAll();
    }

    soRespond(['products' => $products]);
}

// --- Мой заказ на дату ---
if ($soAction === 'my-order' && $method === 'GET' && $soParam1 && $soParam2) {
    $rest = soGetRestaurantSession($pdo);
    if (!$rest) soRespond(['error' => 'Не авторизован'], 401);

    $supplierId = $soParam1;
    $deliveryDate = $soParam2;

    $session = soGetActiveSession($pdo, $supplierId);
    if (!$session) soRespond(['order' => null]);

    $s = $pdo->prepare("SELECT id, status, submitted_at, updated_at FROM so_orders WHERE session_id = ? AND restaurant_number = ? AND delivery_date = ?");
    $s->execute([$session['id'], $rest['restaurant_number'], $deliveryDate]);
    $order = $s->fetch();

    if (!$order) soRespond(['order' => null]);

    $items = $pdo->prepare("SELECT product_id, sku, product_name, quantity FROM so_order_items WHERE order_id = ? ORDER BY product_name");
    $items->execute([$order['id']]);

    soRespond([
        'order' => [
            'id' => (int)$order['id'],
            'status' => $order['status'],
            'submitted_at' => $order['submitted_at'],
            'items' => $items->fetchAll(),
        ],
    ]);
}

// --- История заявок ---
if ($soAction === 'my-orders' && $method === 'GET') {
    $rest = soGetRestaurantSession($pdo);
    if (!$rest) soRespond(['error' => 'Не авторизован'], 401);

    $supplierId = $_GET['supplier_id'] ?? '';
    $limit = min((int)($_GET['limit'] ?? 20), 50);

    $where = "o.restaurant_number = ?";
    $params = [$rest['restaurant_number']];
    if ($supplierId) {
        $where .= " AND o.supplier_id = ?";
        $params[] = $supplierId;
    }

    $s = $pdo->prepare("
        SELECT o.id, o.delivery_date, o.order_date, o.status, o.submitted_at, o.supplier_id,
               s.short_name as supplier_name,
               (SELECT COUNT(*) FROM so_order_items WHERE order_id = o.id) as item_count,
               (SELECT SUM(quantity) FROM so_order_items WHERE order_id = o.id) as total_qty
        FROM so_orders o
        JOIN suppliers s ON s.id = o.supplier_id
        WHERE {$where}
        ORDER BY o.delivery_date DESC
        LIMIT {$limit}
    ");
    $s->execute($params);
    soRespond(['orders' => $s->fetchAll()]);
}

// --- Отправить заявку ---
if ($soAction === 'submit-order' && $method === 'POST') {
    $rest = soGetRestaurantSession($pdo);
    if (!$rest) soRespond(['error' => 'Не авторизован'], 401);

    $supplierId = $body['supplier_id'] ?? '';
    $deliveryDate = $body['delivery_date'] ?? '';
    $orderDate = $body['order_date'] ?? '';
    $items = $body['items'] ?? [];

    if (!$supplierId || !$deliveryDate) soRespond(['error' => 'Не указан поставщик или дата доставки'], 400);
    if (empty($items)) soRespond(['error' => 'Заявка пуста'], 400);

    $session = soGetActiveSession($pdo, $supplierId);
    if (!$session) soRespond(['error' => 'Нет активной сессии приёма заявок для этого поставщика'], 400);

    // Проверяем дедлайн
    $dlStatus = soCheckDeadline($pdo, $session, $orderDate ?: date('Y-m-d'));
    if ($dlStatus['status'] === 'closed') {
        soRespond(['error' => 'Приём заявок на эту дату закрыт (дедлайн ' . substr($dlStatus['deadline'], 0, 5) . ')'], 403);
    }

    // Проверяем: есть ли уже заявка?
    $existing = $pdo->prepare("SELECT id FROM so_orders WHERE session_id = ? AND restaurant_number = ? AND delivery_date = ?");
    $existing->execute([$session['id'], $rest['restaurant_number'], $deliveryDate]);
    $existingOrder = $existing->fetch();

    if ($existingOrder) {
        $orderId = $existingOrder['id'];
        $pdo->prepare("DELETE FROM so_order_items WHERE order_id = ?")->execute([$orderId]);
        $pdo->prepare("UPDATE so_orders SET status = 'submitted', submitted_at = NOW(), updated_at = NOW() WHERE id = ?")
            ->execute([$orderId]);
    } else {
        $le = $rest['legal_entity'];
        $pdo->prepare("INSERT INTO so_orders (session_id, restaurant_number, supplier_id, delivery_date, order_date, status, submitted_at, legal_entity) VALUES (?, ?, ?, ?, ?, 'submitted', NOW(), ?)")
            ->execute([$session['id'], $rest['restaurant_number'], $supplierId, $deliveryDate, $orderDate ?: date('Y-m-d'), $le]);
        $orderId = $pdo->lastInsertId();
    }

    // Вставляем позиции
    $insertItem = $pdo->prepare("INSERT INTO so_order_items (order_id, product_id, sku, product_name, quantity) VALUES (?, ?, ?, ?, ?)");
    $totalQty = 0;
    $totalItems = 0;
    foreach ($items as $item) {
        $qty = floatval($item['quantity'] ?? 0);
        if ($qty <= 0) continue;
        $insertItem->execute([
            $orderId,
            $item['product_id'] ?? '',
            $item['sku'] ?? '',
            $item['product_name'] ?? '',
            $qty,
        ]);
        $totalQty += $qty;
        $totalItems++;
    }

    soRespond([
        'success' => true,
        'order_id' => (int)$orderId,
        'total_items' => $totalItems,
        'total_qty' => $totalQty,
    ]);
}

// ═══════════════════════════════════════════════
// Маршруты для закупщиков (admin)
// ═══════════════════════════════════════════════

if ($soAction === 'admin') {
    $sessionUser = getSessionUser($pdo);
    if (!$sessionUser) {
        if (!checkApiKey($pdo)) soRespond(['error' => 'Unauthorized'], 401);
    }

    $adminAction = $soParam1 ?? '';
    $adminParam = $soParam2 ?? null;
    $adminParam2 = $soParam3 ?? null;

    // --- Список поставщиков с активными расписаниями ---
    if ($adminAction === 'suppliers' && $method === 'GET') {
        $s = $pdo->query("
            SELECT s.id, s.short_name, s.full_name,
                   COUNT(DISTINCT ss.restaurant_id) as restaurant_count,
                   (SELECT COUNT(*) FROM so_sessions ses WHERE ses.supplier_id = s.id AND ses.status = 'active') as has_active_session
            FROM suppliers s
            JOIN so_supplier_schedules ss ON ss.supplier_id = s.id AND ss.is_active = 1
            WHERE s.is_active = 1
            GROUP BY s.id
            ORDER BY s.short_name
        ");
        soRespond(['suppliers' => $s->fetchAll()]);
    }

    // --- Сводка заявок по поставщику + дате ---
    if ($adminAction === 'status' && $method === 'GET') {
        $supplierId = $_GET['supplier_id'] ?? '';
        $date = $_GET['date'] ?? '';
        $sessionIdParam = $_GET['session_id'] ?? '';

        if (!$supplierId) soRespond(['error' => 'Не указан поставщик'], 400);

        if ($sessionIdParam) {
            $ss = $pdo->prepare("SELECT * FROM so_sessions WHERE id = ? AND supplier_id = ?");
            $ss->execute([$sessionIdParam, $supplierId]);
            $session = $ss->fetch() ?: null;
        } else {
            $session = soGetActiveSession($pdo, $supplierId);
        }
        if (!$session) soRespond(['session' => null, 'restaurants' => [], 'stats' => ['total' => 0, 'submitted' => 0, 'pending' => 0]]);

        // Если дата не указана, берём ближайший день поставки
        if (!$date) {
            $tz = new DateTimeZone('Europe/Minsk');
            $now = new DateTime('now', $tz);
            $todayDow = (int)$now->format('N');
            // Ищем ближайший день поставки
            $s = $pdo->prepare("SELECT DISTINCT delivery_day FROM so_supplier_schedules WHERE supplier_id = ? AND is_active = 1 ORDER BY delivery_day");
            $s->execute([$supplierId]);
            $deliveryDays = array_column($s->fetchAll(), 'delivery_day');
            $targetDow = null;
            foreach ($deliveryDays as $dd) {
                if ((int)$dd >= $todayDow) { $targetDow = (int)$dd; break; }
            }
            if ($targetDow === null && !empty($deliveryDays)) $targetDow = (int)$deliveryDays[0];
            if ($targetDow !== null) {
                $diff = $targetDow - $todayDow;
                if ($diff < 0) $diff += 7;
                $date = (clone $now)->modify("+{$diff} days")->format('Y-m-d');
            } else {
                $date = date('Y-m-d');
            }
        }

        $deliveryDow = (int)(new DateTime($date))->format('N');

        // Все рестораны, у которых поставка в этот день
        $rests = $pdo->prepare("
            SELECT r.number, r.region, r.city, r.address,
                   ss.order_day,
                   o.id as order_id, o.status as order_status, o.submitted_at,
                   (SELECT COUNT(*) FROM so_order_items WHERE order_id = o.id) as item_count,
                   (SELECT SUM(quantity) FROM so_order_items WHERE order_id = o.id) as total_qty
            FROM so_supplier_schedules ss
            JOIN restaurants r ON r.id = ss.restaurant_id AND r.active = 1
            LEFT JOIN so_orders o ON o.restaurant_number = r.number AND o.session_id = ? AND o.delivery_date = ?
            WHERE ss.supplier_id = ? AND ss.delivery_day = ? AND ss.is_active = 1
            ORDER BY r.region, r.number
        ");
        $rests->execute([$session['id'], $date, $supplierId, $deliveryDow]);
        $restaurants = $rests->fetchAll();

        $total = count($restaurants);
        $submitted = 0;
        foreach ($restaurants as $r) {
            if ($r['order_status'] === 'submitted' || $r['order_status'] === 'locked') $submitted++;
        }

        // Все даты поставок на неделю (для навигации)
        $weekDates = [];
        $weekStart = new DateTime($session['week_start']);
        $weekEnd = new DateTime($session['week_end']);
        $schStmt = $pdo->prepare("SELECT DISTINCT delivery_day FROM so_supplier_schedules WHERE supplier_id = ? AND is_active = 1 ORDER BY delivery_day");
        $schStmt->execute([$supplierId]);
        $deliveryDaysAll = array_column($schStmt->fetchAll(), 'delivery_day');
        foreach ($deliveryDaysAll as $dd) {
            $dd = (int)$dd;
            $dObj = clone $weekStart;
            $cur = (int)$dObj->format('N');
            $d = $dd - $cur;
            if ($d < 0) $d += 7;
            $dObj->modify("+{$d} days");
            // Если доставка < заказа, это следующая неделя
            $dateStr = $dObj->format('Y-m-d');
            $weekDates[] = [
                'date' => $dateStr,
                'day_name' => $dayNames[$dd] ?? '',
                'day_name_full' => $dayNamesFull[$dd] ?? '',
            ];
        }

        // Товары из шаблонов (все юрлица)
        $tplStmt = $pdo->prepare("
            SELECT DISTINCT t.sku, t.product_name, t.sort_order, t.multiplicity, t.product_id
            FROM so_templates t
            WHERE t.supplier_id = ? AND t.is_active = 1
            ORDER BY t.sort_order, t.product_name
        ");
        $tplStmt->execute([$supplierId]);
        $products = $tplStmt->fetchAll();

        // Все позиции заявок для этой сессии + даты (для сводной таблицы)
        $itemsStmt = $pdo->prepare("
            SELECT o.restaurant_number, o.delivery_date,
                   oi.sku, oi.product_name, oi.quantity, oi.admin_qty, oi.id as item_id, o.id as order_id
            FROM so_orders o
            JOIN so_order_items oi ON oi.order_id = o.id
            WHERE o.session_id = ? AND o.delivery_date = ?
        ");
        $itemsStmt->execute([$session['id'], $date]);
        $orderItems = $itemsStmt->fetchAll();

        soRespond([
            'session' => [
                'id' => (int)$session['id'],
                'week_start' => $session['week_start'],
                'week_end' => $session['week_end'],
                'deadline_time' => $session['deadline_time'],
            ],
            'date' => $date,
            'restaurants' => $restaurants,
            'products' => $products,
            'order_items' => $orderItems,
            'stats' => ['total' => $total, 'submitted' => $submitted, 'pending' => $total - $submitted],
            'week_dates' => $weekDates,
        ]);
    }

    // --- Список заявок по дням ---
    if ($adminAction === 'orders' && $method === 'GET') {
        $supplierId = $_GET['supplier_id'] ?? '';
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
        $dateTo = $_GET['date_to'] ?? date('Y-m-d', strtotime('+7 days'));

        $where = "1=1";
        $params = [];
        if ($supplierId) {
            $where .= " AND o.supplier_id = ?";
            $params[] = $supplierId;
        }
        $where .= " AND o.delivery_date BETWEEN ? AND ?";
        $params[] = $dateFrom;
        $params[] = $dateTo;

        $s = $pdo->prepare("
            SELECT o.id, o.delivery_date, o.order_date, o.restaurant_number, o.status, o.submitted_at, o.supplier_id,
                   s.short_name as supplier_name,
                   r.region, r.city, r.address,
                   (SELECT COUNT(*) FROM so_order_items WHERE order_id = o.id) as item_count,
                   (SELECT SUM(quantity) FROM so_order_items WHERE order_id = o.id) as total_qty
            FROM so_orders o
            JOIN suppliers s ON s.id = o.supplier_id
            LEFT JOIN restaurants r ON r.number = o.restaurant_number AND r.active = 1
            WHERE {$where}
            ORDER BY o.delivery_date, o.restaurant_number
        ");
        $s->execute($params);
        soRespond(['orders' => $s->fetchAll()]);
    }

    // --- Детали заявки ---
    if ($adminAction === 'order' && $method === 'GET' && $adminParam) {
        $s = $pdo->prepare("
            SELECT o.*, s.short_name as supplier_name, r.region, r.city, r.address
            FROM so_orders o
            JOIN suppliers s ON s.id = o.supplier_id
            LEFT JOIN restaurants r ON r.number = o.restaurant_number AND r.active = 1
            WHERE o.id = ?
        ");
        $s->execute([$adminParam]);
        $order = $s->fetch();
        if (!$order) soRespond(['error' => 'Заявка не найдена'], 404);

        $items = $pdo->prepare("SELECT * FROM so_order_items WHERE order_id = ? ORDER BY product_name");
        $items->execute([$order['id']]);
        $order['items'] = $items->fetchAll();

        soRespond(['order' => $order]);
    }

    // --- Редактирование заявки ---
    if ($adminAction === 'order' && $method === 'PATCH' && $adminParam) {
        $orderId = (int)$adminParam;
        $items = $body['items'] ?? null;
        $status = $body['status'] ?? null;

        if ($items !== null) {
            $pdo->prepare("DELETE FROM so_order_items WHERE order_id = ?")->execute([$orderId]);
            $insert = $pdo->prepare("INSERT INTO so_order_items (order_id, product_id, sku, product_name, quantity) VALUES (?, ?, ?, ?, ?)");
            foreach ($items as $item) {
                $qty = floatval($item['quantity'] ?? 0);
                if ($qty <= 0) continue;
                $insert->execute([$orderId, $item['product_id'] ?? '', $item['sku'] ?? '', $item['product_name'] ?? '', $qty]);
            }
        }

        $updatedBy = $sessionUser ? $sessionUser['name'] : 'admin';
        if ($status) {
            $pdo->prepare("UPDATE so_orders SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$status, $orderId]);
        } else {
            $pdo->prepare("UPDATE so_orders SET updated_at = NOW() WHERE id = ?")->execute([$orderId]);
        }

        soRespond(['success' => true]);
    }

    // --- Обновление admin_qty для позиции ---
    if ($adminAction === 'update-qty' && $method === 'POST') {
        $itemId = $body['item_id'] ?? null;
        $adminQty = $body['admin_qty'] ?? null;
        // Альтернативный путь: создать запись если нет заказа (админ заполняет за ресторан)
        $restNum = $body['restaurant_number'] ?? null;
        $deliveryDate = $body['delivery_date'] ?? null;
        $sku = $body['sku'] ?? null;
        $productName = $body['product_name'] ?? null;
        $sessionId = $body['session_id'] ?? null;
        $suppId = $body['supplier_id'] ?? null;

        $val = ($adminQty !== null && $adminQty !== '') ? (float)$adminQty : null;

        if ($itemId) {
            // Обновляем существующую позицию
            $pdo->prepare("UPDATE so_order_items SET admin_qty = ? WHERE id = ?")->execute([$val, $itemId]);
            soRespond(['success' => true]);
        } elseif ($restNum && $deliveryDate && $sku && $sessionId && $suppId) {
            // Ищем заказ ресторана
            $orderStmt = $pdo->prepare("SELECT id FROM so_orders WHERE session_id = ? AND restaurant_number = ? AND delivery_date = ?");
            $orderStmt->execute([$sessionId, $restNum, $deliveryDate]);
            $order = $orderStmt->fetch();

            if (!$order) {
                // Создаём заказ за ресторан
                $createdBy = $sessionUser ? $sessionUser['name'] : 'admin';
                $le = $body['legal_entity'] ?? 'ООО "Бургер БК"';
                $pdo->prepare("INSERT INTO so_orders (session_id, restaurant_number, supplier_id, delivery_date, order_date, status, submitted_at, legal_entity)
                    VALUES (?, ?, ?, ?, CURDATE(), 'submitted', NOW(), ?)")
                    ->execute([$sessionId, $restNum, $suppId, $deliveryDate, $le]);
                $orderId = $pdo->lastInsertId();
            } else {
                $orderId = $order['id'];
            }

            // Ищем позицию по SKU
            $existingItem = $pdo->prepare("SELECT id FROM so_order_items WHERE order_id = ? AND sku = ?");
            $existingItem->execute([$orderId, $sku]);
            $item = $existingItem->fetch();

            if ($item) {
                $pdo->prepare("UPDATE so_order_items SET admin_qty = ? WHERE id = ?")->execute([$val, $item['id']]);
            } else {
                // Создаём новую позицию (админ добавил количество для товара, которого не было в заказе)
                $pdo->prepare("INSERT INTO so_order_items (order_id, product_id, sku, product_name, quantity, admin_qty) VALUES (?, ?, ?, ?, 0, ?)")
                    ->execute([$orderId, $body['product_id'] ?? '', $sku, $productName ?? '', $val]);
            }

            soRespond(['success' => true, 'reload' => true]);
        } else {
            soRespond(['error' => 'Недостаточно данных'], 400);
        }
    }

    // --- Удаление заявки ---
    if ($adminAction === 'order' && $method === 'DELETE' && $adminParam) {
        $pdo->prepare("DELETE FROM so_order_items WHERE order_id = ?")->execute([(int)$adminParam]);
        $pdo->prepare("DELETE FROM so_orders WHERE id = ?")->execute([(int)$adminParam]);
        soRespond(['success' => true]);
    }

    // --- Управление сессиями ---
    if ($adminAction === 'session' && $method === 'POST') {
        $action = $body['action'] ?? 'create';
        $supplierId = $body['supplier_id'] ?? '';

        if (!$supplierId) soRespond(['error' => 'Не указан поставщик'], 400);

        if ($action === 'create' || $action === 'auto') {
            // Автосоздание: если нет активной — создаём
            $existing = soGetActiveSession($pdo, $supplierId);
            if ($existing && $action === 'auto') {
                soRespond(['success' => true, 'session' => $existing, 'created' => false]);
            }

            // Ближайший ПН — ВС (если сегодня ВС — начинаем с завтра)
            $tz = new DateTimeZone('Europe/Minsk');
            $now = new DateTime('now', $tz);
            $dow = (int)$now->format('N');
            $mondayOffset = ($dow === 7) ? 1 : (1 - $dow); // Если ВС → +1, иначе назад к ПН
            $monday = (clone $now)->modify("{$mondayOffset} days");
            $sunday = (clone $monday)->modify('+6 days');
            $weekStart = $body['week_start'] ?? $monday->format('Y-m-d');
            $weekEnd = $body['week_end'] ?? $sunday->format('Y-m-d');
            $deadlineTime = $body['deadline_time'] ?? '14:00:00';
            $createdBy = $sessionUser ? $sessionUser['name'] : 'system';

            // Закрываем старые сессии этого поставщика
            $pdo->prepare("UPDATE so_sessions SET status = 'closed' WHERE supplier_id = ? AND status = 'active'")
                ->execute([$supplierId]);

            $pdo->prepare("INSERT INTO so_sessions (supplier_id, week_start, week_end, deadline_time, created_by) VALUES (?, ?, ?, ?, ?)")
                ->execute([$supplierId, $weekStart, $weekEnd, $deadlineTime, $createdBy]);

            $newSession = $pdo->prepare("SELECT * FROM so_sessions WHERE id = ?");
            $newSession->execute([$pdo->lastInsertId()]);
            soRespond(['success' => true, 'session' => $newSession->fetch(), 'created' => true]);
        }

        if ($action === 'close') {
            $sessionId = $body['session_id'] ?? null;
            if ($sessionId) {
                $pdo->prepare("UPDATE so_sessions SET status = 'closed' WHERE id = ?")->execute([$sessionId]);
            }
            soRespond(['success' => true]);
        }

        if ($action === 'update') {
            $sessionId = $body['session_id'] ?? null;
            if (!$sessionId) soRespond(['error' => 'Не указана сессия'], 400);
            $sets = [];
            $params = [];
            if (isset($body['deadline_time'])) { $sets[] = 'deadline_time = ?'; $params[] = $body['deadline_time']; }
            if (isset($body['week_start'])) { $sets[] = 'week_start = ?'; $params[] = $body['week_start']; }
            if (isset($body['week_end'])) { $sets[] = 'week_end = ?'; $params[] = $body['week_end']; }
            if (empty($sets)) soRespond(['error' => 'Нечего обновлять'], 400);
            $params[] = $sessionId;
            $pdo->prepare("UPDATE so_sessions SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
            soRespond(['success' => true]);
        }

        if ($action === 'reopen') {
            $sessionId = $body['session_id'] ?? null;
            if (!$sessionId) soRespond(['error' => 'Не указана сессия'], 400);
            $pdo->prepare("UPDATE so_sessions SET status = 'active' WHERE id = ?")->execute([$sessionId]);
            soRespond(['success' => true]);
        }

        if ($action === 'delete') {
            $sessionId = $body['session_id'] ?? null;
            if (!$sessionId) soRespond(['error' => 'Не указана сессия'], 400);
            $pdo->prepare("DELETE FROM so_order_items WHERE order_id IN (SELECT id FROM so_orders WHERE session_id = ?)")->execute([$sessionId]);
            $pdo->prepare("DELETE FROM so_orders WHERE session_id = ?")->execute([$sessionId]);
            $pdo->prepare("DELETE FROM so_deadline_overrides WHERE session_id = ?")->execute([$sessionId]);
            $pdo->prepare("DELETE FROM so_sessions WHERE id = ?")->execute([$sessionId]);
            soRespond(['success' => true]);
        }

        soRespond(['error' => 'Unknown action'], 400);
    }

    // --- Список сессий ---
    if ($adminAction === 'sessions' && $method === 'GET') {
        $supplierId = $_GET['supplier_id'] ?? '';
        $where = "1=1";
        $params = [];
        if ($supplierId) {
            $where .= " AND ss.supplier_id = ?";
            $params[] = $supplierId;
        }
        $s = $pdo->prepare("
            SELECT ss.*, s.short_name as supplier_name,
                   (SELECT COUNT(*) FROM so_orders WHERE session_id = ss.id) as order_count
            FROM so_sessions ss
            JOIN suppliers s ON s.id = ss.supplier_id
            WHERE {$where}
            ORDER BY ss.created_at DESC
            LIMIT 50
        ");
        $s->execute($params);
        soRespond(['sessions' => $s->fetchAll()]);
    }

    // --- Графики поставок ---
    if ($adminAction === 'schedules' && $method === 'GET') {
        $supplierId = $_GET['supplier_id'] ?? '';
        if (!$supplierId) soRespond(['error' => 'Не указан поставщик'], 400);

        $s = $pdo->prepare("
            SELECT ss.id, ss.order_day, ss.delivery_day, ss.is_active,
                   r.number as restaurant_number, r.region, r.city, r.address
            FROM so_supplier_schedules ss
            JOIN restaurants r ON r.id = ss.restaurant_id
            WHERE ss.supplier_id = ?
            ORDER BY r.region, r.number, ss.order_day
        ");
        $s->execute([$supplierId]);
        soRespond(['schedules' => $s->fetchAll()]);
    }

    // --- Сохранение графиков ---
    if ($adminAction === 'schedules' && $method === 'POST') {
        $supplierId = $body['supplier_id'] ?? '';
        $schedules = $body['schedules'] ?? [];

        if (!$supplierId) soRespond(['error' => 'Не указан поставщик'], 400);

        $updatedBy = $sessionUser ? $sessionUser['name'] : 'admin';

        // Upsert
        $upsert = $pdo->prepare("
            INSERT INTO so_supplier_schedules (supplier_id, restaurant_id, order_day, delivery_day, is_active, updated_at, updated_by)
            VALUES (?, ?, ?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE delivery_day = VALUES(delivery_day), is_active = VALUES(is_active), updated_at = NOW(), updated_by = VALUES(updated_by)
        ");

        $count = 0;
        foreach ($schedules as $sch) {
            $restId = $sch['restaurant_id'] ?? null;
            if (!$restId) continue;
            $upsert->execute([
                $supplierId,
                $restId,
                (int)($sch['order_day'] ?? 1),
                (int)($sch['delivery_day'] ?? 2),
                (int)($sch['is_active'] ?? 1),
                $updatedBy,
            ]);
            $count++;
        }

        soRespond(['success' => true, 'updated' => $count]);
    }

    // --- Шаблоны товаров ---
    if ($adminAction === 'templates' && $method === 'GET') {
        $supplierId = $_GET['supplier_id'] ?? '';
        $le = $_GET['legal_entity'] ?? 'ООО "Бургер БК"';
        if (!$supplierId) soRespond(['error' => 'Не указан поставщик'], 400);

        $s = $pdo->prepare("
            SELECT t.*, p.name as original_name, p.qty_per_box
            FROM so_templates t
            LEFT JOIN products p ON p.id = t.product_id
            WHERE t.supplier_id = ? AND t.legal_entity = ?
            ORDER BY t.sort_order, t.product_name
        ");
        $s->execute([$supplierId, $le]);
        soRespond(['templates' => $s->fetchAll()]);
    }

    // --- Сохранение шаблона ---
    if ($adminAction === 'templates' && $method === 'POST') {
        $supplierId = $body['supplier_id'] ?? '';
        $le = $body['legal_entity'] ?? 'ООО "Бургер БК"';
        $items = $body['items'] ?? [];

        if (!$supplierId) soRespond(['error' => 'Не указан поставщик'], 400);

        // Деактивируем все текущие
        $pdo->prepare("UPDATE so_templates SET is_active = 0 WHERE supplier_id = ? AND legal_entity = ?")
            ->execute([$supplierId, $le]);

        $upsert = $pdo->prepare("
            INSERT INTO so_templates (supplier_id, legal_entity, product_id, sku, product_name, sort_order, multiplicity, min_qty, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE product_name = VALUES(product_name), sort_order = VALUES(sort_order),
              multiplicity = VALUES(multiplicity), min_qty = VALUES(min_qty), is_active = 1, product_id = VALUES(product_id)
        ");

        $count = 0;
        foreach ($items as $i => $item) {
            $mult = isset($item['multiplicity']) && $item['multiplicity'] !== '' ? (float)$item['multiplicity'] : null;
            $minQty = isset($item['min_qty']) && $item['min_qty'] !== '' ? (float)$item['min_qty'] : null;
            $upsert->execute([
                $supplierId,
                $le,
                $item['product_id'] ?? null,
                $item['sku'] ?? '',
                $item['product_name'] ?? '',
                $item['sort_order'] ?? ($i * 10),
                $mult,
                $minQty,
            ]);
            $count++;
        }

        soRespond(['success' => true, 'count' => $count]);
    }

    // --- Экспорт ---
    if ($adminAction === 'export' && $method === 'GET') {
        $supplierId = $_GET['supplier_id'] ?? '';
        $date = $_GET['date'] ?? '';

        if (!$supplierId || !$date) soRespond(['error' => 'Не указан поставщик или дата'], 400);

        $session = soGetActiveSession($pdo, $supplierId);
        if (!$session) soRespond(['error' => 'Нет активной сессии'], 400);

        $deliveryDow = (int)(new DateTime($date))->format('N');

        // Все заявки на эту дату
        $s = $pdo->prepare("
            SELECT o.restaurant_number, o.status, o.submitted_at,
                   r.region, r.address,
                   oi.sku, oi.product_name, oi.quantity, oi.admin_qty,
                   COALESCE(oi.admin_qty, oi.quantity) as effective_qty
            FROM so_orders o
            JOIN restaurants r ON r.number = o.restaurant_number AND r.active = 1
            JOIN so_order_items oi ON oi.order_id = o.id
            WHERE o.session_id = ? AND o.delivery_date = ?
            ORDER BY r.region, r.number, oi.product_name
        ");
        $s->execute([$session['id'], $date]);
        $rows = $s->fetchAll();

        // Сводка по товарам (используем admin_qty если есть)
        $summary = [];
        foreach ($rows as $row) {
            $key = $row['sku'];
            if (!isset($summary[$key])) {
                $summary[$key] = ['sku' => $row['sku'], 'product_name' => $row['product_name'], 'total_qty' => 0, 'restaurant_count' => 0];
            }
            $summary[$key]['total_qty'] += (float)$row['effective_qty'];
            $summary[$key]['restaurant_count']++;
        }

        soRespond([
            'orders' => $rows,
            'summary' => array_values($summary),
            'date' => $date,
        ]);
    }
}
