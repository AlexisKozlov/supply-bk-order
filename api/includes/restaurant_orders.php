<?php
/**
 * API заказов ресторанов — временный модуль.
 * Подключается из index.php. Все переменные ($pdo, $endpoint, $subpoint, $method, $body) доступны через global.
 *
 * Маршруты:
 *   POST   ro/login           — логин ресторана
 *   POST   ro/logout          — выход
 *   POST   ro/validate        — проверка сессии
 *   GET    ro/my-info         — инфо о ресторане + текущая сессия + дедлайны
 *   GET    ro/products        — товары для формы (из шаблона или stock_malling)
 *   GET    ro/my-orders       — мои заказы (история)
 *   GET    ro/my-order/:date  — мой заказ на дату
 *   POST   ro/submit-order    — отправить заказ
 *   POST   ro/repeat-order    — повторить предыдущий заказ
 *
 *   Для закупщиков (требуется сессия основного приложения):
 *   GET    ro/admin/status         — статус заявок на дату
 *   GET    ro/admin/order/:id      — детали заказа
 *   PATCH  ro/admin/order/:id      — редактировать заказ
 *   POST   ro/admin/session        — создать/управлять сессией
 *   POST   ro/admin/extend-deadline — продлить дедлайн
 *   GET    ro/admin/export/:format — Excel-экспорт
 *   GET    ro/admin/templates      — шаблоны
 *   POST   ro/admin/templates      — сохранить шаблон
 *   POST   ro/admin/users          — управление учётками ресторанов
 *   GET    ro/admin/users          — список учёток
 */

if ($endpoint !== 'ro') return;

// ═══ Хелперы ═══

function roRespond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function roGetRestaurantSession($pdo) {
    $token = $_SERVER['HTTP_X_RO_TOKEN'] ?? '';
    if (!$token) return null;
    $s = $pdo->prepare("
        SELECT ru.id, ru.restaurant_number, ru.legal_entity, ru.session_active_until,
               r.region, r.city, r.address
        FROM ro_users ru
        LEFT JOIN restaurants r ON r.number = ru.restaurant_number AND r.active = 1
        WHERE ru.session_token = ? AND ru.is_active = 1
    ");
    $s->execute([$token]);
    $user = $s->fetch();
    if (!$user) return null;
    // Проверяем активность сессии (24 часа)
    if ($user['session_active_until'] && strtotime($user['session_active_until']) < time()) {
        return null;
    }
    return $user;
}

function roGetActiveSession($pdo) {
    $s = $pdo->query("SELECT * FROM ro_sessions WHERE status = 'active' AND week_end >= CURDATE() ORDER BY week_start DESC LIMIT 1");
    return $s->fetch() ?: null;
}

function roGetDeadlines($pdo, $sessionId, $deliveryDate) {
    // Сначала проверяем переопределения
    $s = $pdo->prepare("SELECT soft_deadline, hard_deadline FROM ro_deadline_overrides WHERE session_id = ? AND delivery_date = ?");
    $s->execute([$sessionId, $deliveryDate]);
    $override = $s->fetch();
    if ($override) {
        return [
            'soft' => $override['soft_deadline'],
            'hard' => $override['hard_deadline'],
            'edit_until' => '11:00:00',
        ];
    }
    // Стандартные дедлайны
    return [
        'soft' => '10:00:00',
        'hard' => '13:00:00',
        'edit_until' => '11:00:00',
    ];
}

function roGetDeadlineStatus($pdo, $sessionId, $deliveryDate) {
    $deadlines = roGetDeadlines($pdo, $sessionId, $deliveryDate);
    $tz = new DateTimeZone('Europe/Minsk');
    $now = new DateTime('now', $tz);
    $today = $now->format('Y-m-d');
    // Дедлайны относятся к дню ПЕРЕД доставкой (день подачи заявки)
    $orderDate = (new DateTime($deliveryDate))->modify('-1 day')->format('Y-m-d');

    if ($today < $orderDate) {
        // День подачи ещё не наступил — разрешаем подавать заранее
        return ['status' => 'open', 'deadlines' => $deadlines];
    }
    if ($today > $orderDate) {
        return ['status' => 'closed', 'deadlines' => $deadlines]; // День прошёл
    }
    // Сегодня = день подачи
    $currentTime = $now->format('H:i:s');
    if ($currentTime < $deadlines['soft']) {
        return ['status' => 'open', 'deadlines' => $deadlines];
    }
    if ($currentTime < $deadlines['hard']) {
        return ['status' => 'warning', 'deadlines' => $deadlines]; // Мягкий дедлайн прошёл
    }
    return ['status' => 'closed', 'deadlines' => $deadlines]; // Жёсткий дедлайн прошёл
}

function roCanEdit($pdo, $sessionId, $deliveryDate) {
    $deadlines = roGetDeadlines($pdo, $sessionId, $deliveryDate);
    $tz = new DateTimeZone('Europe/Minsk');
    $now = new DateTime('now', $tz);
    $today = $now->format('Y-m-d');
    $orderDate = (new DateTime($deliveryDate))->modify('-1 day')->format('Y-m-d');
    // До дня подачи — всегда можно
    if ($today < $orderDate) return true;
    // После дня подачи — нельзя
    if ($today > $orderDate) return false;
    // В день подачи — до 11:00
    return $now->format('H:i:s') < $deadlines['edit_until'];
}

function roGetLegalEntity($pdo, $restaurantNumber) {
    // Все рестораны используют товары Бургер БК (включая Воглия Матта)
    return 'ООО "Бургер БК"';
}

// ═══ Публичные маршруты (ресторанная авторизация) ═══

$roAction = $subpoint ?? '';
$roParts = explode('/', $uri);
// uri = "ro/action/param" → roParts = ["ro", "action", "param"]
$roParam = $roParts[2] ?? null;

// --- Авторизация через Telegram ---
if ($roAction === 'tg-auth' && $method === 'POST') {
    $tgToken = $body['tg_token'] ?? '';
    if (!$tgToken) {
        roRespond(['success' => false, 'error' => 'Токен не указан'], 400);
    }

    // Ищем токен
    $s = $pdo->prepare("SELECT id, telegram_chat_id FROM ro_tg_tokens WHERE token = ? AND expires_at > NOW() AND used = 0 LIMIT 1");
    $s->execute([$tgToken]);
    $tgAuth = $s->fetch();
    if (!$tgAuth) {
        roRespond(['success' => false, 'error' => 'Ссылка недействительна или истекла']);
    }

    // Помечаем токен использованным
    $pdo->prepare("UPDATE ro_tg_tokens SET used = 1 WHERE id = ?")->execute([$tgAuth['id']]);

    // Находим ресторан по подписке
    $s = $pdo->prepare("SELECT restaurant_number FROM veg_telegram_subs WHERE chat_id = ? LIMIT 1");
    $s->execute([$tgAuth['telegram_chat_id']]);
    $sub = $s->fetch();
    if (!$sub) {
        roRespond(['success' => false, 'error' => 'Вы не подписаны ни на один ресторан в боте']);
    }

    $restNum = $sub['restaurant_number'];

    // Проверяем, есть ли учётка ресторана
    $s = $pdo->prepare("SELECT id, restaurant_number, legal_entity FROM ro_users WHERE restaurant_number = ? AND is_active = 1");
    $s->execute([$restNum]);
    $user = $s->fetch();
    if (!$user) {
        roRespond(['success' => false, 'error' => "Учётная запись ресторана {$restNum} не найдена. Обратитесь в отдел закупок."]);
    }

    // Создаём сессию — аналогично обычному логину
    $token = bin2hex(random_bytes(32));
    $activeUntil = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $pdo->prepare("UPDATE ro_users SET session_token = ?, session_active_until = ?, last_login_at = NOW() WHERE id = ?")
        ->execute([$token, $activeUntil, $user['id']]);

    $r = $pdo->prepare("SELECT number, region, city, address FROM restaurants WHERE number = ? AND active = 1 LIMIT 1");
    $r->execute([$restNum]);
    $rest = $r->fetch();

    roRespond([
        'success' => true,
        'token' => $token,
        'restaurant' => [
            'number' => $restNum,
            'legal_entity' => $user['legal_entity'],
            'region' => $rest['region'] ?? '',
            'city' => $rest['city'] ?? '',
            'address' => $rest['address'] ?? '',
        ],
    ]);
}

// --- Логин ---
if ($roAction === 'login' && $method === 'POST') {
    $restNum = intval($body['restaurant_number'] ?? 0);
    $password = $body['password'] ?? '';
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if (!$restNum || !$password) {
        roRespond(['success' => false, 'error' => 'Введите номер ресторана и пароль'], 400);
    }

    if (!checkRateLimit($pdo, $clientIp, 15, 10)) {
        roRespond(['success' => false, 'error' => 'Слишком много попыток. Подождите 10 минут'], 429);
    }

    $s = $pdo->prepare("SELECT id, restaurant_number, password_hash, legal_entity, session_token, session_active_until FROM ro_users WHERE restaurant_number = ? AND is_active = 1");
    $s->execute([$restNum]);
    $user = $s->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        recordFailedLogin($pdo, $clientIp, "rest_{$restNum}");
        roRespond(['success' => false, 'error' => 'Неверный номер ресторана или пароль']);
    }

    // Проверяем: если кто-то сейчас активно работает (сессия активна менее 30 мин) — не даём войти
    // "session_active_until" обновляется при каждом действии
    if ($user['session_token'] && $user['session_active_until']) {
        $activeUntil = strtotime($user['session_active_until']);
        // Если сессия активна и была обновлена менее 5 минут назад — значит кто-то работает
        if ($activeUntil > time() && ($activeUntil - time()) > (23 * 3600 + 55 * 60)) {
            // session_active_until в будущем и до истечения осталось почти 24 часа — значит только что обновлена
            // Не блокируем — просто заменяем сессию
        }
    }

    // Создаём новую сессию
    $token = bin2hex(random_bytes(32));
    $activeUntil = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $pdo->prepare("UPDATE ro_users SET session_token = ?, session_active_until = ?, last_login_at = NOW() WHERE id = ?")
        ->execute([$token, $activeUntil, $user['id']]);

    // Инфо о ресторане
    $r = $pdo->prepare("SELECT number, region, city, address FROM restaurants WHERE number = ? AND active = 1 LIMIT 1");
    $r->execute([$restNum]);
    $rest = $r->fetch();

    roRespond([
        'success' => true,
        'token' => $token,
        'restaurant' => [
            'number' => $restNum,
            'legal_entity' => $user['legal_entity'],
            'region' => $rest['region'] ?? '',
            'city' => $rest['city'] ?? '',
            'address' => $rest['address'] ?? '',
        ],
    ]);
}

// --- Валидация сессии ---
if ($roAction === 'validate' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) {
        roRespond(['valid' => false]);
    }
    // Продлеваем сессию при каждой валидации
    $pdo->prepare("UPDATE ro_users SET session_active_until = ? WHERE restaurant_number = ?")
        ->execute([date('Y-m-d H:i:s', strtotime('+24 hours')), $rest['restaurant_number']]);
    roRespond(['valid' => true, 'restaurant' => [
        'number' => $rest['restaurant_number'],
        'legal_entity' => $rest['legal_entity'],
        'region' => $rest['region'] ?? '',
        'city' => $rest['city'] ?? '',
        'address' => $rest['address'] ?? '',
    ]]);
}

// --- Выход ---
if ($roAction === 'logout' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if ($rest) {
        $pdo->prepare("UPDATE ro_users SET session_token = NULL, session_active_until = NULL WHERE restaurant_number = ?")
            ->execute([$rest['restaurant_number']]);
    }
    roRespond(['success' => true]);
}

// --- Инфо: текущая сессия, расписание, дедлайны ---
if ($roAction === 'my-info' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);

    $session = roGetActiveSession($pdo);
    if (!$session) {
        roRespond(['session' => null, 'delivery_days' => []]);
    }

    // Расписание доставки этого ресторана
    $ds = $pdo->prepare("
        SELECT ds.day_of_week, ds.delivery_time
        FROM delivery_schedule ds
        JOIN restaurants r ON r.id = ds.restaurant_id
        WHERE r.number = ? AND r.active = 1
        ORDER BY ds.day_of_week
    ");
    $ds->execute([$rest['restaurant_number']]);
    $schedule = $ds->fetchAll();

    // Формируем дни доставки на эту неделю
    $dayNames = [1=>'Понедельник',2=>'Вторник',3=>'Среда',4=>'Четверг',5=>'Пятница',6=>'Суббота',7=>'Воскресенье'];
    $deliveryDays = [];

    $weekStart = new DateTime($session['week_start']);
    $weekEnd = new DateTime($session['week_end']);

    foreach ($schedule as $sch) {
        $dow = (int)$sch['day_of_week'];
        // Находим дату этого дня недели в рамках сессии
        $date = clone $weekStart;
        $currentDow = (int)$date->format('N'); // 1=Mon
        $diff = $dow - $currentDow;
        if ($diff < 0) $diff += 7;
        $date->modify("+{$diff} days");

        if ($date > $weekEnd) continue;

        $dateStr = $date->format('Y-m-d');
        $deadlineStatus = roGetDeadlineStatus($pdo, $session['id'], $dateStr);

        // Проверяем есть ли уже заказ
        $os = $pdo->prepare("SELECT id, status, submitted_at FROM ro_orders WHERE session_id = ? AND restaurant_number = ? AND delivery_date = ?");
        $os->execute([$session['id'], $rest['restaurant_number'], $dateStr]);
        $order = $os->fetch();

        $deliveryDays[] = [
            'date' => $dateStr,
            'day_of_week' => $dow,
            'day_name' => $dayNames[$dow] ?? '',
            'delivery_time' => $sch['delivery_time'],
            'deadline_status' => $deadlineStatus['status'],
            'deadlines' => $deadlineStatus['deadlines'],
            'can_edit' => roCanEdit($pdo, $session['id'], $dateStr),
            'order' => $order ? [
                'id' => (int)$order['id'],
                'status' => $order['status'],
                'submitted_at' => $order['submitted_at'],
            ] : null,
        ];
    }

    roRespond([
        'session' => [
            'id' => (int)$session['id'],
            'week_start' => $session['week_start'],
            'week_end' => $session['week_end'],
            'status' => $session['status'],
        ],
        'delivery_days' => $deliveryDays,
    ]);
}

// --- Товары для формы ---
if ($roAction === 'products' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);

    $category = $_GET['category'] ?? '';
    $search = $_GET['search'] ?? '';
    $le = $rest['legal_entity'];

    $products = [];

    if ($search) {
        // Поиск по всем товарам
        $like = "%{$search}%";
        $s = $pdo->prepare("SELECT sku, name, category, qty_per_box, multiplicity FROM products WHERE legal_entity = ? AND is_active = 1 AND (name LIKE ? OR sku LIKE ?) ORDER BY name LIMIT 50");
        $s->execute([$le, $like, $like]);
        $products = $s->fetchAll();
    } else {
        // Сначала из шаблона
        $tplQuery = "SELECT t.sku, t.product_name as name, t.category, t.sort_order, p.qty_per_box, p.multiplicity
            FROM ro_templates t
            LEFT JOIN products p ON p.sku = t.sku AND p.legal_entity = ?
            WHERE t.legal_entity = ? AND t.is_active = 1";
        $params = [$le, $le];
        if ($category) {
            $tplQuery .= " AND t.category = ?";
            $params[] = $category;
        }
        $tplQuery .= " ORDER BY t.sort_order, t.product_name";
        $s = $pdo->prepare($tplQuery);
        $s->execute($params);
        $products = $s->fetchAll();

        // Если шаблон пуст — берём из stock_malling (уникальные товары)
        if (empty($products)) {
            $smQuery = "SELECT DISTINCT p.name, p.sku, p.category, p.qty_per_box, p.multiplicity
                FROM stock_malling sm
                JOIN products p ON p.legal_entity = ? AND p.is_active = 1
                  AND (sm.product_name LIKE CONCAT(p.sku, ' %') OR p.name = sm.product_name OR p.sku = sm.product_name)
                WHERE 1=1";
            $params = [$le];
            if ($category) {
                $smQuery .= " AND p.category = ?";
                $params[] = $category;
            }
            $smQuery .= " ORDER BY p.category, p.name LIMIT 500";
            $s = $pdo->prepare($smQuery);
            $s->execute($params);
            $products = $s->fetchAll();
        }
    }

    roRespond(['products' => $products]);
}

// --- Мой заказ на дату ---
if ($roAction === 'my-order' && $method === 'GET' && $roParam) {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);

    $date = $roParam;
    $session = roGetActiveSession($pdo);
    if (!$session) roRespond(['order' => null]);

    $s = $pdo->prepare("SELECT id, status, submitted_at, updated_at, updated_by FROM ro_orders WHERE session_id = ? AND restaurant_number = ? AND delivery_date = ?");
    $s->execute([$session['id'], $rest['restaurant_number'], $date]);
    $order = $s->fetch();

    if (!$order) roRespond(['order' => null]);

    $items = $pdo->prepare("SELECT sku, product_name, category, quantity, comment FROM ro_order_items WHERE order_id = ? ORDER BY category, product_name");
    $items->execute([$order['id']]);

    roRespond([
        'order' => [
            'id' => (int)$order['id'],
            'status' => $order['status'],
            'submitted_at' => $order['submitted_at'],
            'updated_at' => $order['updated_at'],
            'updated_by' => $order['updated_by'],
            'items' => $items->fetchAll(),
        ],
    ]);
}

// --- Мои заказы (история) ---
if ($roAction === 'my-orders' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);

    $limit = min((int)($_GET['limit'] ?? 20), 50);
    $s = $pdo->prepare("
        SELECT o.id, o.delivery_date, o.status, o.submitted_at, o.updated_at,
               (SELECT COUNT(*) FROM ro_order_items WHERE order_id = o.id) as item_count,
               (SELECT SUM(quantity) FROM ro_order_items WHERE order_id = o.id) as total_qty
        FROM ro_orders o
        WHERE o.restaurant_number = ?
        ORDER BY o.delivery_date DESC
        LIMIT {$limit}
    ");
    $s->execute([$rest['restaurant_number']]);
    roRespond(['orders' => $s->fetchAll()]);
}

// --- Отправка заказа ---
if ($roAction === 'submit-order' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);

    $deliveryDate = $body['delivery_date'] ?? '';
    $items = $body['items'] ?? [];

    if (!$deliveryDate) roRespond(['error' => 'Не указана дата доставки'], 400);
    if (empty($items)) roRespond(['error' => 'Заказ пуст'], 400);

    $session = roGetActiveSession($pdo);
    if (!$session) roRespond(['error' => 'Нет активной сессии приёма заявок'], 400);

    // Проверяем дедлайн
    $dlStatus = roGetDeadlineStatus($pdo, $session['id'], $deliveryDate);
    if ($dlStatus['status'] === 'closed') {
        roRespond(['error' => 'Приём заявок на эту дату закрыт'], 403);
    }

    // Проверяем: есть ли уже заказ?
    $existingOrder = $pdo->prepare("SELECT id, status, submitted_at FROM ro_orders WHERE session_id = ? AND restaurant_number = ? AND delivery_date = ?");
    $existingOrder->execute([$session['id'], $rest['restaurant_number'], $deliveryDate]);
    $existing = $existingOrder->fetch();

    if ($existing) {
        // Обновляем — но проверяем, можно ли ещё редактировать
        if (!roCanEdit($pdo, $session['id'], $deliveryDate)) {
            roRespond(['error' => 'Время редактирования заказа истекло (до 11:00). Обратитесь в отдел закупок'], 403);
        }

        $orderId = $existing['id'];
        // Удаляем старые позиции
        $pdo->prepare("DELETE FROM ro_order_items WHERE order_id = ?")->execute([$orderId]);
        // Обновляем статус
        $pdo->prepare("UPDATE ro_orders SET status = 'submitted', submitted_at = NOW(), updated_at = NOW(), updated_by = ? WHERE id = ?")
            ->execute(["Ресторан {$rest['restaurant_number']}", $orderId]);
    } else {
        // Создаём новый заказ
        $le = $rest['legal_entity'];
        $pdo->prepare("INSERT INTO ro_orders (session_id, restaurant_number, delivery_date, status, submitted_at, updated_by, legal_entity) VALUES (?, ?, ?, 'submitted', NOW(), ?, ?)")
            ->execute([$session['id'], $rest['restaurant_number'], $deliveryDate, "Ресторан {$rest['restaurant_number']}", $le]);
        $orderId = $pdo->lastInsertId();
    }

    // Вставляем позиции
    $insertItem = $pdo->prepare("INSERT INTO ro_order_items (order_id, sku, product_name, category, quantity, comment) VALUES (?, ?, ?, ?, ?, ?)");
    $totalQty = 0;
    $totalItems = 0;
    foreach ($items as $item) {
        $qty = floatval($item['quantity'] ?? 0);
        if ($qty <= 0) continue;
        $insertItem->execute([
            $orderId,
            $item['sku'] ?? '',
            $item['product_name'] ?? '',
            $item['category'] ?? 'Сухой',
            $qty,
            $item['comment'] ?? null,
        ]);
        $totalQty += $qty;
        $totalItems++;
    }

    roRespond([
        'success' => true,
        'order_id' => (int)$orderId,
        'total_items' => $totalItems,
        'total_qty' => $totalQty,
    ]);
}

// --- Повторить предыдущий заказ ---
if ($roAction === 'repeat-order' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);

    $sourceOrderId = $body['source_order_id'] ?? null;
    $deliveryDate = $body['delivery_date'] ?? '';

    if (!$sourceOrderId || !$deliveryDate) roRespond(['error' => 'Не указан исходный заказ или дата'], 400);

    // Получаем позиции исходного заказа
    $s = $pdo->prepare("
        SELECT oi.sku, oi.product_name, oi.category, oi.quantity, oi.comment
        FROM ro_order_items oi
        JOIN ro_orders o ON o.id = oi.order_id
        WHERE o.id = ? AND o.restaurant_number = ?
    ");
    $s->execute([$sourceOrderId, $rest['restaurant_number']]);
    $items = $s->fetchAll();

    if (empty($items)) roRespond(['error' => 'Исходный заказ не найден или пуст'], 404);

    roRespond(['items' => $items]);
}

// ═══════════════════════════════════════════════
// Маршруты для закупщиков (требуется сессия основного приложения)
// ═══════════════════════════════════════════════

if (strpos($roAction, 'admin') === 0) {
    // Проверяем авторизацию закупщика
    $sessionUser = getSessionUser($pdo);
    if (!$sessionUser) {
        if (!checkApiKey($pdo)) roRespond(['error' => 'Unauthorized'], 401);
    }

    $adminAction = $roParts[2] ?? '';
    $adminParam = $roParts[3] ?? null;

    // --- Статус заявок ---
    if ($adminAction === 'status' && $method === 'GET') {
        $date = $_GET['date'] ?? date('Y-m-d', strtotime('+1 day'));
        $session = roGetActiveSession($pdo);
        if (!$session) roRespond(['session' => null, 'orders' => []]);

        $deadlineStatus = roGetDeadlineStatus($pdo, $session['id'], $date);

        // Все активные рестораны с расписанием на этот день
        $dow = (int)(new DateTime($date))->format('N');
        $rests = $pdo->prepare("
            SELECT r.number, r.region, r.city, r.address, r.legal_entity_group,
                   ds.delivery_time,
                   o.id as order_id, o.status as order_status, o.submitted_at,
                   (SELECT COUNT(*) FROM ro_order_items WHERE order_id = o.id) as item_count,
                   (SELECT SUM(quantity) FROM ro_order_items WHERE order_id = o.id) as total_qty
            FROM restaurants r
            JOIN delivery_schedule ds ON ds.restaurant_id = r.id AND ds.day_of_week = ?
            LEFT JOIN ro_orders o ON o.restaurant_number = r.number AND o.session_id = ? AND o.delivery_date = ?
            WHERE r.active = 1
            ORDER BY r.region, r.number
        ");
        $rests->execute([$dow, $session['id'], $date]);
        $restaurants = $rests->fetchAll();

        // Считаем статистику
        $total = count($restaurants);
        $submitted = 0;
        foreach ($restaurants as $r) {
            if ($r['order_status'] === 'submitted' || $r['order_status'] === 'edited' || $r['order_status'] === 'locked') {
                $submitted++;
            }
        }

        roRespond([
            'session' => [
                'id' => (int)$session['id'],
                'week_start' => $session['week_start'],
                'week_end' => $session['week_end'],
            ],
            'date' => $date,
            'deadline_status' => $deadlineStatus,
            'restaurants' => $restaurants,
            'stats' => [
                'total' => $total,
                'submitted' => $submitted,
                'pending' => $total - $submitted,
            ],
        ]);
    }

    // --- Детали заказа ---
    if ($adminAction === 'order' && $method === 'GET' && $adminParam) {
        $s = $pdo->prepare("SELECT o.*, r.city, r.address, r.region FROM ro_orders o LEFT JOIN restaurants r ON r.number = o.restaurant_number AND r.active = 1 WHERE o.id = ?");
        $s->execute([$adminParam]);
        $order = $s->fetch();
        if (!$order) roRespond(['error' => 'Заказ не найден'], 404);

        $items = $pdo->prepare("SELECT * FROM ro_order_items WHERE order_id = ? ORDER BY category, product_name");
        $items->execute([$order['id']]);

        $order['items'] = $items->fetchAll();
        roRespond(['order' => $order]);
    }

    // --- Редактирование заказа закупщиком ---
    if ($adminAction === 'order' && $method === 'PATCH' && $adminParam) {
        $orderId = (int)$adminParam;
        $items = $body['items'] ?? null;
        $status = $body['status'] ?? null;

        if ($items !== null) {
            // Обновляем позиции
            $pdo->prepare("DELETE FROM ro_order_items WHERE order_id = ?")->execute([$orderId]);
            $insert = $pdo->prepare("INSERT INTO ro_order_items (order_id, sku, product_name, category, quantity, comment) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($items as $item) {
                $qty = floatval($item['quantity'] ?? 0);
                if ($qty <= 0) continue;
                $insert->execute([$orderId, $item['sku'] ?? '', $item['product_name'] ?? '', $item['category'] ?? 'Сухой', $qty, $item['comment'] ?? null]);
            }
        }

        $updatedBy = $sessionUser ? $sessionUser['name'] : 'admin';
        $newStatus = $status ?: 'edited';
        $pdo->prepare("UPDATE ro_orders SET status = ?, updated_at = NOW(), updated_by = ? WHERE id = ?")
            ->execute([$newStatus, $updatedBy, $orderId]);

        roRespond(['success' => true]);
    }

    // --- Удаление заказа закупщиком ---
    if ($adminAction === 'order' && $method === 'DELETE' && $adminParam) {
        $orderId = (int)$adminParam;
        $pdo->prepare("DELETE FROM ro_order_items WHERE order_id = ?")->execute([$orderId]);
        $pdo->prepare("DELETE FROM ro_orders WHERE id = ?")->execute([$orderId]);
        roRespond(['success' => true]);
    }

    // --- Упр��вление сессией ---
    if ($adminAction === 'session' && $method === 'POST') {
        $action = $body['action'] ?? 'create';

        if ($action === 'create') {
            $weekStart = $body['week_start'] ?? date('Y-m-d', strtotime('monday this week'));
            $weekEnd = $body['week_end'] ?? date('Y-m-d', strtotime('saturday this week'));
            $createdBy = $sessionUser ? $sessionUser['name'] : 'system';

            // Закрываем старые сессии
            $pdo->exec("UPDATE ro_sessions SET status = 'closed' WHERE status = 'active'");

            $pdo->prepare("INSERT INTO ro_sessions (week_start, week_end, created_by) VALUES (?, ?, ?)")
                ->execute([$weekStart, $weekEnd, $createdBy]);

            roRespond(['success' => true, 'session_id' => (int)$pdo->lastInsertId()]);
        }

        if ($action === 'close') {
            $sessionId = $body['session_id'] ?? null;
            if ($sessionId) {
                $pdo->prepare("UPDATE ro_sessions SET status = 'closed' WHERE id = ?")->execute([$sessionId]);
            }
            roRespond(['success' => true]);
        }

        if ($action === 'auto') {
            // Автосоздание: если нет активной — создаём на текущую неделю
            $existing = roGetActiveSession($pdo);
            if ($existing) {
                roRespond(['success' => true, 'session' => $existing, 'created' => false]);
            }
            $weekStart = date('Y-m-d', strtotime('monday this week'));
            $weekEnd = date('Y-m-d', strtotime('saturday this week'));
            $pdo->prepare("INSERT INTO ro_sessions (week_start, week_end, created_by) VALUES (?, ?, 'auto')")
                ->execute([$weekStart, $weekEnd]);
            $newSession = $pdo->prepare("SELECT * FROM ro_sessions WHERE id = ?");
            $newSession->execute([$pdo->lastInsertId()]);
            roRespond(['success' => true, 'session' => $newSession->fetch(), 'created' => true]);
        }

        roRespond(['error' => 'Unknown action'], 400);
    }

    // --- Продление дедлайна ---
    if ($adminAction === 'extend-deadline' && $method === 'POST') {
        $sessionId = $body['session_id'] ?? null;
        $date = $body['delivery_date'] ?? '';
        $softDeadline = $body['soft_deadline'] ?? '14:00:00';
        $hardDeadline = $body['hard_deadline'] ?? '16:00:00';
        $createdBy = $sessionUser ? $sessionUser['name'] : 'admin';

        if (!$sessionId || !$date) roRespond(['error' => 'Не указана сессия или дата'], 400);

        $pdo->prepare("INSERT INTO ro_deadline_overrides (session_id, delivery_date, soft_deadline, hard_deadline, created_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE soft_deadline = VALUES(soft_deadline), hard_deadline = VALUES(hard_deadline), created_by = VALUES(created_by)")
            ->execute([$sessionId, $date, $softDeadline, $hardDeadline, $createdBy]);

        roRespond(['success' => true]);
    }

    // --- Шаблоны ---
    if ($adminAction === 'templates' && $method === 'GET') {
        $le = $_GET['legal_entity'] ?? 'ООО "Бургер БК"';
        $category = $_GET['category'] ?? null;

        $q = "SELECT t.*, COALESCE(p.multiplicity, 1) as multiplicity
            FROM ro_templates t
            LEFT JOIN products p ON p.sku = t.sku AND p.legal_entity = ?
            WHERE t.legal_entity = ?";
        $params = [$le, $le];
        if ($category) { $q .= " AND t.category = ?"; $params[] = $category; }
        $q .= " ORDER BY t.category, t.sort_order, t.product_name";
        $s = $pdo->prepare($q);
        $s->execute($params);
        roRespond(['templates' => $s->fetchAll()]);
    }

    if ($adminAction === 'templates' && $method === 'POST') {
        $action = $body['action'] ?? 'save';

        if ($action === 'save') {
            $items = $body['items'] ?? [];
            $le = $body['legal_entity'] ?? 'ООО "Бургер БК"';
            $category = $body['category'] ?? '';

            if (!$category) roRespond(['error' => 'Не указана категория'], 400);

            // Удаляем старые для этой категории + юрлица
            $pdo->prepare("DELETE FROM ro_templates WHERE legal_entity = ? AND category = ?")->execute([$le, $category]);

            $insert = $pdo->prepare("INSERT INTO ro_templates (legal_entity, category, sku, product_name, sort_order) VALUES (?, ?, ?, ?, ?)");
            $updateMult = $pdo->prepare("UPDATE products SET multiplicity = ? WHERE sku = ? AND legal_entity = ?");
            foreach ($items as $i => $item) {
                $insert->execute([$le, $category, $item['sku'] ?? '', $item['product_name'] ?? '', $i]);
                // Обновляем кратность в products если передана
                $mult = intval($item['multiplicity'] ?? 0);
                if ($mult > 0 && ($item['sku'] ?? '')) {
                    $updateMult->execute([$mult, $item['sku'], $le]);
                }
            }
            roRespond(['success' => true, 'count' => count($items)]);
        }

        if ($action === 'import-from-stock') {
            // Импорт из stock_malling
            $le = $body['legal_entity'] ?? 'ООО "Бургер БК"';
            $category = $body['category'] ?? '';

            $s = $pdo->prepare("
                SELECT DISTINCT p.sku, p.name as product_name
                FROM stock_malling sm
                JOIN products p ON p.legal_entity = ? AND p.is_active = 1
                  AND (sm.product_name LIKE CONCAT(p.sku, ' %') OR p.name = sm.product_name OR p.sku = sm.product_name)
                WHERE p.category = ?
                ORDER BY p.name
            ");
            $s->execute([$le, $category]);
            $products = $s->fetchAll();

            // Сохраняем как шаблон
            $pdo->prepare("DELETE FROM ro_templates WHERE legal_entity = ? AND category = ?")->execute([$le, $category]);
            $insert = $pdo->prepare("INSERT INTO ro_templates (legal_entity, category, sku, product_name, sort_order) VALUES (?, ?, ?, ?, ?)");
            foreach ($products as $i => $p) {
                $insert->execute([$le, $category, $p['sku'], $p['product_name'], $i]);
            }

            roRespond(['success' => true, 'count' => count($products), 'items' => $products]);
        }

        roRespond(['error' => 'Unknown action'], 400);
    }

    // --- Управление учётками ресторанов ---
    if ($adminAction === 'users' && $method === 'GET') {
        $s = $pdo->query("
            SELECT ru.id, ru.restaurant_number, ru.legal_entity, ru.is_active, ru.created_at, ru.last_login_at,
                   r.region, r.city, r.address
            FROM ro_users ru
            LEFT JOIN restaurants r ON r.number = ru.restaurant_number AND r.active = 1
            ORDER BY ru.restaurant_number
        ");
        roRespond(['users' => $s->fetchAll()]);
    }

    if ($adminAction === 'users' && $method === 'POST') {
        $action = $body['action'] ?? 'create';

        if ($action === 'create') {
            $restNum = (int)($body['restaurant_number'] ?? 0);
            $password = $body['password'] ?? '';
            if (!$restNum || !$password) roRespond(['error' => 'Не указан номер или пароль'], 400);

            $le = roGetLegalEntity($pdo, $restNum);
            $hash = password_hash($password, PASSWORD_BCRYPT);

            $pdo->prepare("INSERT INTO ro_users (restaurant_number, password_hash, legal_entity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), legal_entity = VALUES(legal_entity), is_active = 1")
                ->execute([$restNum, $hash, $le]);

            roRespond(['success' => true, 'restaurant_number' => $restNum]);
        }

        if ($action === 'create-bulk') {
            // Создать учётки для всех активных ресторанов
            $password = $body['password'] ?? '';
            if (!$password) roRespond(['error' => 'Не указан пароль'], 400);

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $rests = $pdo->query("SELECT number FROM restaurants WHERE active = 1 ORDER BY number");
            $created = 0;
            foreach ($rests->fetchAll() as $r) {
                $le = roGetLegalEntity($pdo, $r['number']);
                $pdo->prepare("INSERT INTO ro_users (restaurant_number, password_hash, legal_entity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), legal_entity = VALUES(legal_entity), is_active = 1")
                    ->execute([$r['number'], $hash, $le]);
                $created++;
            }
            roRespond(['success' => true, 'created' => $created]);
        }

        if ($action === 'toggle') {
            $restNum = (int)($body['restaurant_number'] ?? 0);
            $active = (int)($body['is_active'] ?? 1);
            $pdo->prepare("UPDATE ro_users SET is_active = ? WHERE restaurant_number = ?")->execute([$active, $restNum]);
            roRespond(['success' => true]);
        }

        if ($action === 'reset-password') {
            $restNum = (int)($body['restaurant_number'] ?? 0);
            $password = $body['password'] ?? '';
            if (!$restNum || !$password) roRespond(['error' => 'Не указан номер или пароль'], 400);
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE ro_users SET password_hash = ? WHERE restaurant_number = ?")->execute([$hash, $restNum]);
            roRespond(['success' => true]);
        }

        roRespond(['error' => 'Unknown action'], 400);
    }

    // --- Excel-экспорт ---
    if ($adminAction === 'export' && $method === 'GET') {
        $format = $adminParam ?? 'summary'; // summary, per-restaurant, all
        $date = $_GET['date'] ?? date('Y-m-d', strtotime('+1 day'));
        $session = roGetActiveSession($pdo);
        if (!$session) roRespond(['error' => 'Нет активной сессии'], 400);

        // Получаем все заказы на дату
        $dow = (int)(new DateTime($date))->format('N');
        $orders = $pdo->prepare("
            SELECT o.id, o.restaurant_number, o.status, o.submitted_at, o.legal_entity,
                   r.region, r.city, r.address,
                   ds.delivery_time
            FROM ro_orders o
            LEFT JOIN restaurants r ON r.number = o.restaurant_number AND r.active = 1
            LEFT JOIN delivery_schedule ds ON ds.restaurant_id = r.id AND ds.day_of_week = ?
            WHERE o.session_id = ? AND o.delivery_date = ? AND o.status != 'draft'
            ORDER BY o.restaurant_number
        ");
        $orders->execute([$dow, $session['id'], $date]);
        $ordersList = $orders->fetchAll();

        // Все позиции
        $orderIds = array_column($ordersList, 'id');
        $allItems = [];
        if (!empty($orderIds)) {
            $ph = implode(',', array_fill(0, count($orderIds), '?'));
            $items = $pdo->prepare("SELECT oi.*, o.restaurant_number FROM ro_order_items oi JOIN ro_orders o ON o.id = oi.order_id WHERE oi.order_id IN ({$ph}) ORDER BY oi.category, oi.product_name");
            $items->execute($orderIds);
            $allItems = $items->fetchAll();
        }

        roRespond([
            'date' => $date,
            'orders' => $ordersList,
            'items' => $allItems,
            'format' => $format,
        ]);
    }

    // --- Список всех сессий ---
    if ($adminAction === 'sessions' && $method === 'GET') {
        $s = $pdo->query("SELECT * FROM ro_sessions ORDER BY week_start DESC LIMIT 20");
        roRespond(['sessions' => $s->fetchAll()]);
    }

    roRespond(['error' => 'Not found'], 404);
}

// Если дошли сюда — неизвестный маршрут
roRespond(['error' => 'Not found'], 404);
