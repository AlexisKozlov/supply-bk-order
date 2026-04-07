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
            'edit_until' => $override['hard_deadline'],
        ];
    }
    // Стандартные дедлайны
    return [
        'soft' => '10:00:00',
        'hard' => '13:00:00',
        'edit_until' => '13:00:00',
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

    $s = $pdo->prepare("SELECT id, restaurant_number, password_hash, legal_entity, session_token, session_active_until, last_login_at FROM ro_users WHERE restaurant_number = ? AND is_active = 1");
    $s->execute([$restNum]);
    $user = $s->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        recordFailedLogin($pdo, $clientIp, "rest_{$restNum}");
        roRespond(['success' => false, 'error' => 'Неверный номер ресторана или пароль']);
    }

    // Проверяем: есть ли активная сессия (кто-то работает)
    $force = !empty($body['force']);
    if (!$force && $user['session_token'] && $user['session_active_until']) {
        $activeUntil = strtotime($user['session_active_until']);
        if ($activeUntil > time()) {
            // Сессия активна — вычисляем когда был последний вход
            $lastLogin = $user['last_login_at'] ?? null;
            $ago = '';
            if ($lastLogin) {
                $diff = time() - strtotime($lastLogin);
                if ($diff < 60) $ago = 'только что';
                elseif ($diff < 3600) $ago = floor($diff / 60) . ' мин. назад';
                elseif ($diff < 86400) $ago = floor($diff / 3600) . ' ч. назад';
                else $ago = floor($diff / 86400) . ' дн. назад';
            }
            roRespond([
                'success' => false,
                'error' => 'active_session',
                'active_session' => true,
                'last_login_at' => $lastLogin,
                'last_login_ago' => $ago,
            ]);
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

// --- Смена пароля ---
if ($roAction === 'change-password' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $oldPass = $body['old_password'] ?? '';
    $newPass = $body['new_password'] ?? '';
    if (!$oldPass || !$newPass) roRespond(['error' => 'Заполните оба поля'], 400);
    if (mb_strlen($newPass) < 4) roRespond(['error' => 'Новый пароль слишком короткий (минимум 4 символа)'], 400);
    // Проверяем старый пароль
    $s = $pdo->prepare("SELECT id, password_hash FROM ro_users WHERE restaurant_number = ? AND is_active = 1");
    $s->execute([$rest['restaurant_number']]);
    $user = $s->fetch();
    if (!$user || !password_verify($oldPass, $user['password_hash'])) {
        roRespond(['error' => 'Неверный текущий пароль']);
    }
    $newHash = password_hash($newPass, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE ro_users SET password_hash = ? WHERE id = ?")->execute([$newHash, $user['id']]);
    roRespond(['success' => true]);
}

// --- Проверка активного сбора остатков ---
if ($roAction === 'stock-collection-status' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $le = $rest['legal_entity'] ?: 'ООО "Бургер БК"';
    $group = getEntityGroup($le);
    // Ищем активные сборы для юрлица ресторана
    $where = ["sc.status = 'active'"];
    $params = [];
    if ($group === 'PS') {
        $where[] = "sc.legal_entity LIKE '%Пицца Стар%'";
    } else {
        $where[] = "(sc.legal_entity LIKE '%Бургер БК%' OR sc.legal_entity LIKE '%Воглия Матта%')";
    }
    $sql = "SELECT sc.id, sc.name, sc.created_at,
                (SELECT COUNT(DISTINCT scd.product_id) FROM stock_collection_data scd
                 JOIN stock_collection_products scp ON scp.id = scd.product_id AND scp.collection_id = sc.id
                 WHERE scd.restaurant_number = ?) as submitted_count,
                (SELECT COUNT(*) FROM stock_collection_products scp2 WHERE scp2.collection_id = sc.id) as total_products
            FROM stock_collections sc WHERE " . implode(' AND ', $where) . " ORDER BY sc.id DESC LIMIT 1";
    $s = $pdo->prepare($sql);
    $s->execute([$rest['restaurant_number']]);
    $collection = $s->fetch();
    if (!$collection) {
        roRespond(['active' => false]);
    }
    // Получаем токен для прямого доступа
    $t = $pdo->prepare("SELECT token FROM stock_collection_tokens WHERE collection_id = ? AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    $t->execute([$collection['id']]);
    $tok = $t->fetch();
    roRespond([
        'active' => true,
        'collection' => [
            'id' => (int)$collection['id'],
            'name' => $collection['name'],
            'submitted' => (int)$collection['submitted_count'] > 0,
            'submitted_count' => (int)$collection['submitted_count'],
            'total_products' => (int)$collection['total_products'],
            'token' => $tok['token'] ?? null,
        ],
    ]);
}

// --- Привязка Telegram: генерация токена ---
if ($roAction === 'telegram-link' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    // Проверяем, привязан ли уже Telegram
    $s = $pdo->prepare("SELECT telegram_chat_id FROM ro_users WHERE restaurant_number = ?");
    $s->execute([$rest['restaurant_number']]);
    $user = $s->fetch();
    if ($user && $user['telegram_chat_id']) {
        roRespond(['already_linked' => true, 'chat_id' => $user['telegram_chat_id']]);
    }
    // Генерируем токен привязки (6-значный код)
    $code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    // Сохраняем в ro_tg_tokens (переиспользуем таблицу)
    $pdo->prepare("INSERT INTO ro_tg_tokens (token, telegram_chat_id, expires_at, used) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 0)")
        ->execute([$code, 0]); // chat_id=0, т.к. пока не знаем; token = код
    // Запоминаем restaurant_number для этого кода
    $pdo->prepare("UPDATE ro_tg_tokens SET telegram_chat_id = ? WHERE token = ? AND used = 0")
        ->execute([-$rest['restaurant_number'], $code]); // используем отрицательное число как маркер «это код привязки»
    roRespond(['success' => true, 'code' => $code, 'expires_in' => 600]);
}

// --- Отвязка Telegram ---
if ($roAction === 'telegram-unlink' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $pdo->prepare("UPDATE ro_users SET telegram_chat_id = NULL WHERE restaurant_number = ?")
        ->execute([$rest['restaurant_number']]);
    roRespond(['success' => true]);
}

// --- Статус привязки Telegram ---
if ($roAction === 'telegram-status' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $s = $pdo->prepare("SELECT telegram_chat_id FROM ro_users WHERE restaurant_number = ?");
    $s->execute([$rest['restaurant_number']]);
    $user = $s->fetch();
    roRespond(['linked' => !empty($user['telegram_chat_id']), 'chat_id' => $user['telegram_chat_id'] ?? null]);
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

// --- Объединённая история (все источники) ---
if ($roAction === 'all-history' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $rn = $rest['restaurant_number'];
    $limit = min((int)($_GET['limit'] ?? 30), 100);
    $allOrders = [];

    // 1. Основная поставка (ro_orders)
    $s1 = $pdo->prepare("
        SELECT o.id, o.delivery_date, o.status, o.submitted_at,
               (SELECT COUNT(*) FROM ro_order_items WHERE order_id = o.id) as item_count,
               (SELECT SUM(quantity) FROM ro_order_items WHERE order_id = o.id) as total_qty
        FROM ro_orders o WHERE o.restaurant_number = ?
        ORDER BY o.delivery_date DESC LIMIT {$limit}
    ");
    $s1->execute([$rn]);
    foreach ($s1->fetchAll() as $r) {
        $r['source'] = 'delivery';
        $r['source_name'] = 'Основная поставка';
        $allOrders[] = $r;
    }

    // 2. Заявки поставщикам (so_orders)
    $s2 = $pdo->prepare("
        SELECT o.id, o.delivery_date, o.status, o.submitted_at, o.supplier_id,
               s.short_name as supplier_name,
               (SELECT COUNT(*) FROM so_order_items WHERE order_id = o.id) as item_count,
               (SELECT SUM(quantity) FROM so_order_items WHERE order_id = o.id) as total_qty
        FROM so_orders o
        LEFT JOIN suppliers s ON s.id = o.supplier_id
        WHERE o.restaurant_number = ?
        ORDER BY o.delivery_date DESC LIMIT {$limit}
    ");
    $s2->execute([$rn]);
    foreach ($s2->fetchAll() as $r) {
        $r['source'] = 'supplier';
        $r['source_name'] = $r['supplier_name'] ?: 'Поставщик';
        unset($r['supplier_name']);
        $allOrders[] = $r;
    }

    // 3. Планета Ресторанов / овощи (veg_orders)
    $s3 = $pdo->prepare("
        SELECT vs.id as session_id, vs.name as session_name, vo.delivery_date,
               vo.submitted_at,
               COUNT(*) as item_count,
               SUM(vo.quantity) as total_qty
        FROM veg_orders vo
        JOIN veg_sessions vs ON vs.id = vo.session_id
        WHERE vo.restaurant_number = ? AND vo.quantity > 0
        GROUP BY vo.session_id, vo.delivery_date
        ORDER BY vo.delivery_date DESC LIMIT {$limit}
    ");
    $s3->execute([$rn]);
    foreach ($s3->fetchAll() as $r) {
        $allOrders[] = [
            'id' => 'veg_' . $r['session_id'] . '_' . $r['delivery_date'],
            'delivery_date' => $r['delivery_date'],
            'status' => 'submitted',
            'submitted_at' => $r['submitted_at'],
            'item_count' => $r['item_count'],
            'total_qty' => $r['total_qty'],
            'source' => 'planeta',
            'source_name' => 'Планета Ресторанов',
        ];
    }

    // Сортировка по дате доставки
    usort($allOrders, function($a, $b) {
        return strcmp($b['delivery_date'], $a['delivery_date']);
    });
    $allOrders = array_slice($allOrders, 0, $limit);
    roRespond(['orders' => $allOrders]);
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
                   o.updated_at, o.updated_by,
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

        $items = $pdo->prepare("SELECT oi.*, p.weight_netto, p.weight_brutto, p.external_code, p.boxes_per_pallet
            FROM ro_order_items oi
            LEFT JOIN products p ON p.sku = oi.sku AND p.legal_entity = ?
            WHERE oi.order_id = ? ORDER BY oi.category, oi.product_name");
        $items->execute([$order['legal_entity'], $order['id']]);

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

    // --- Универсальный отчёт ---
    if ($adminAction === 'report' && $method === 'GET') {
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateTo = $_GET['date_to'] ?? date('Y-m-d', strtotime('+7 days'));
        $category = $_GET['category'] ?? '';
        $status = $_GET['status'] ?? '';
        $restaurants = $_GET['restaurants'] ?? ''; // comma-separated

        $where = ["o.delivery_date BETWEEN ? AND ?", "o.status != 'draft'"];
        $params = [$dateFrom, $dateTo];

        if ($status) {
            $where[] = "o.status = ?";
            $params[] = $status;
        }
        if ($restaurants) {
            $restNums = array_map('intval', explode(',', $restaurants));
            $ph = implode(',', array_fill(0, count($restNums), '?'));
            $where[] = "o.restaurant_number IN ({$ph})";
            $params = array_merge($params, $restNums);
        }

        $sql = "SELECT o.id, o.restaurant_number, o.delivery_date, o.status, o.session_id,
                       r.city, r.address
                FROM ro_orders o
                LEFT JOIN restaurants r ON r.number = o.restaurant_number AND r.active = 1
                WHERE " . implode(' AND ', $where) . "
                ORDER BY o.delivery_date, o.restaurant_number";
        $s = $pdo->prepare($sql);
        $s->execute($params);
        $orders = $s->fetchAll();

        $orderIds = array_column($orders, 'id');
        $items = [];
        if (!empty($orderIds)) {
            $ph = implode(',', array_fill(0, count($orderIds), '?'));
            $catWhere = '';
            $catParams = $orderIds;
            if ($category) {
                $catWhere = " AND oi.category = ?";
                $catParams[] = $category;
            }
            $st = $pdo->prepare("SELECT oi.order_id, oi.sku, oi.product_name, oi.category, oi.quantity, oi.comment
                FROM ro_order_items oi WHERE oi.order_id IN ({$ph}){$catWhere} ORDER BY oi.category, oi.product_name");
            $st->execute($catParams);
            $items = $st->fetchAll();
        }

        // Список ресторанов для фильтра
        $restList = $pdo->query("SELECT DISTINCT o.restaurant_number FROM ro_orders o WHERE o.status != 'draft' ORDER BY o.restaurant_number")->fetchAll(PDO::FETCH_COLUMN);

        // Список сессий
        $sessions = $pdo->query("SELECT id, week_start, week_end, status FROM ro_sessions ORDER BY id DESC LIMIT 20")->fetchAll();

        roRespond([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'orders' => $orders,
            'items' => $items,
            'restaurant_list' => $restList,
            'sessions' => $sessions,
        ]);
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
            $items = $pdo->prepare("SELECT oi.*, o.restaurant_number, p.weight_netto, p.weight_brutto, p.external_code, p.boxes_per_pallet
                FROM ro_order_items oi
                JOIN ro_orders o ON o.id = oi.order_id
                LEFT JOIN products p ON p.sku = oi.sku AND p.legal_entity = o.legal_entity
                WHERE oi.order_id IN ({$ph}) ORDER BY oi.category, oi.product_name");
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

    // --- Поиск товаров (для шаблонов) ---
    if ($adminAction === 'products' && $method === 'GET') {
        $search = $_GET['search'] ?? '';
        $le = $_GET['legal_entity'] ?? '';
        if (!$search || strlen($search) < 2 || !$le) roRespond(['products' => []]);
        $like = "%{$search}%";
        $s = $pdo->prepare("SELECT sku, name, category, qty_per_box, multiplicity FROM products WHERE legal_entity = ? AND is_active = 1 AND (name LIKE ? OR sku LIKE ?) ORDER BY name LIMIT 50");
        $s->execute([$le, $like, $like]);
        roRespond(['products' => $s->fetchAll()]);
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
