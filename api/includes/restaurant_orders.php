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
 *   GET    ro/admin/export/:format — выгрузка заказов (Excel / JSON)
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

function roInferGroupFromRestaurantNumber($restaurantNumber) {
    return ((int)$restaurantNumber >= 1000) ? 'PS' : 'BK_VM';
}

function roNormalizeLegalEntityGroup($group, $restaurantNumber = null) {
    $g = strtoupper(trim((string)$group));
    if ($g === 'PS' || $g === 'BK_VM') return $g;
    return roInferGroupFromRestaurantNumber($restaurantNumber);
}

function roGetRestaurantRow($pdo, $restaurantNumber, $group = null) {
    $resolvedGroup = roNormalizeLegalEntityGroup($group, $restaurantNumber);
    $s = $pdo->prepare("
        SELECT id, number, region, city, address, legal_entity_group
        FROM restaurants
        WHERE number = ? AND active = 1 AND legal_entity_group = ?
        LIMIT 1
    ");
    $s->execute([(int)$restaurantNumber, $resolvedGroup]);
    return $s->fetch() ?: null;
}

function roGetRestaurantSession($pdo) {
    $token = $_SERVER['HTTP_X_RO_TOKEN'] ?? '';
    if (!$token) return null;
    $s = $pdo->prepare("
        SELECT ru.id, ru.restaurant_number, ru.legal_entity, ru.legal_entity_group, ru.session_active_until
        FROM ro_users ru
        WHERE ru.session_token = ? AND ru.is_active = 1
    ");
    $s->execute([$token]);
    $user = $s->fetch();
    if (!$user) return null;
    // Проверяем активность сессии (3 часа неактивности)
    if ($user['session_active_until'] && strtotime($user['session_active_until']) < time()) {
        return null;
    }
    // Продлеваем сессию при каждом запросе (сброс таймера неактивности)
    $pdo->prepare("UPDATE ro_users SET session_active_until = ? WHERE id = ?")
        ->execute([date('Y-m-d H:i:s', strtotime('+3 hours')), $user['id']]);
    $rest = roGetRestaurantRow($pdo, $user['restaurant_number'], $user['legal_entity_group'] ?? null);
    $user['region'] = $rest['region'] ?? '';
    $user['city'] = $rest['city'] ?? '';
    $user['address'] = $rest['address'] ?? '';
    if (empty($user['legal_entity_group']) && !empty($rest['legal_entity_group'])) {
        $user['legal_entity_group'] = $rest['legal_entity_group'];
    }
    return $user;
}

function roGetActiveSession($pdo, $group = 'BK_VM') {
    $group = $group ?: 'BK_VM';
    if (!roSessionsSupportGroups($pdo)) {
        if ($group !== 'BK_VM') return null;
        $s = $pdo->query("
            SELECT *,
                   'BK_VM' AS effective_legal_entity_group
            FROM ro_sessions
            WHERE status = 'active'
              AND week_end >= CURDATE()
            ORDER BY week_start DESC
            LIMIT 1
        ");
        $session = $s->fetch() ?: null;
        if ($session) $session['legal_entity_group'] = 'BK_VM';
        return $session;
    }
    if ($group === 'BK_VM') {
        $s = $pdo->prepare("
            SELECT *,
                   CASE
                       WHEN legal_entity_group IS NULL OR legal_entity_group = '' THEN 'BK_VM'
                       ELSE legal_entity_group
                   END AS effective_legal_entity_group
            FROM ro_sessions
            WHERE (legal_entity_group = ? OR legal_entity_group IS NULL OR legal_entity_group = '')
              AND status = 'active'
              AND week_end >= CURDATE()
            ORDER BY week_start DESC
            LIMIT 1
        ");
        $s->execute([$group]);
    } else {
        $s = $pdo->prepare("
            SELECT *,
                   legal_entity_group AS effective_legal_entity_group
            FROM ro_sessions
            WHERE legal_entity_group = ?
              AND status = 'active'
              AND week_end >= CURDATE()
            ORDER BY week_start DESC
            LIMIT 1
        ");
        $s->execute([$group]);
    }
    $session = $s->fetch() ?: null;
    if ($session && empty($session['legal_entity_group']) && !empty($session['effective_legal_entity_group'])) {
        $session['legal_entity_group'] = $session['effective_legal_entity_group'];
    }
    return $session;
}

function roFormatCttRestaurantLabel($restaurantNumber, $city, $address) {
    $number = (int)$restaurantNumber;
    $city = trim((string)$city);
    $address = trim((string)$address);
    if ($address === '') return (string)$number;
    if ($city !== '' && mb_strtolower($city) !== 'минск' && mb_stripos($address, $city) === false) {
        return $number . ' — г. ' . $city . ', ' . $address;
    }
    return $number . ' — ' . $address;
}

function roFormatCttWeight($weightBruttoGrams) {
    $kg = ((float)$weightBruttoGrams) / 1000;
    $formatted = rtrim(rtrim(number_format($kg, 6, '.', ''), '0'), '.');
    return $formatted !== '' ? $formatted : '0';
}

function roGetCttPrefixByGroup($group) {
    return strtoupper((string)$group) === 'PS' ? 'DODO' : 'BK';
}

function roSessionsSupportGroups($pdo) {
    static $supported = null;
    if ($supported !== null) return $supported;
    try {
        $s = $pdo->query("SHOW COLUMNS FROM ro_sessions LIKE 'legal_entity_group'");
        $supported = (bool)$s->fetch();
    } catch (Exception $e) {
        $supported = false;
    }
    return $supported;
}

function roGetSessionById($pdo, $sessionId) {
    if (!roSessionsSupportGroups($pdo)) {
        $s = $pdo->prepare("SELECT *, 'BK_VM' AS legal_entity_group FROM ro_sessions WHERE id = ? LIMIT 1");
        $s->execute([(int)$sessionId]);
        return $s->fetch() ?: null;
    }
    $s = $pdo->prepare("SELECT * FROM ro_sessions WHERE id = ? LIMIT 1");
    $s->execute([(int)$sessionId]);
    return $s->fetch() ?: null;
}

function roIsDateOpen($pdo, $sessionId, $deliveryDate) {
    $s = $pdo->prepare("SELECT is_open FROM ro_deadline_overrides WHERE session_id = ? AND delivery_date = ?");
    $s->execute([$sessionId, $deliveryDate]);
    $row = $s->fetch();
    return $row && $row['is_open'];
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
    // Приём открыт только если админ явно открыл эту дату
    if (!roIsDateOpen($pdo, $sessionId, $deliveryDate)) {
        $deadlines = roGetDeadlines($pdo, $sessionId, $deliveryDate);
        return ['status' => 'not_open', 'deadlines' => $deadlines];
    }

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
    // Если дата не открыта — редактировать нельзя
    if (!roIsDateOpen($pdo, $sessionId, $deliveryDate)) return false;

    $deadlines = roGetDeadlines($pdo, $sessionId, $deliveryDate);
    $tz = new DateTimeZone('Europe/Minsk');
    $now = new DateTime('now', $tz);
    $today = $now->format('Y-m-d');
    $orderDate = (new DateTime($deliveryDate))->modify('-1 day')->format('Y-m-d');
    // До дня подачи — всегда можно
    if ($today < $orderDate) return true;
    // После дня подачи — нельзя
    if ($today > $orderDate) return false;
    // В день подачи — до edit_until
    return $now->format('H:i:s') < $deadlines['edit_until'];
}

function roGetLegalEntity($pdo, $restaurantNumber, $group = null) {
    // Если группу не передали — смотрим её в таблице restaurants.
    // Может быть до двух записей (одна в BK_VM, одна в PS) — берём первую;
    // пока PS-рестораны не заведены, это безопасно.
    if ($group === null) {
        $s = $pdo->prepare("SELECT legal_entity_group FROM restaurants WHERE number = ? AND active = 1 LIMIT 1");
        $s->execute([(int)$restaurantNumber]);
        $group = $s->fetchColumn() ?: 'BK_VM';
    }
    if ($group === 'PS') {
        return 'ООО "Пицца Стар"';
    }
    // Группа BK_VM: исторически ресторан 3 = Воглия Матта, остальные = Бургер БК
    if ((int)$restaurantNumber === 3) {
        return 'ООО "Воглия Матта"';
    }
    return 'ООО "Бургер БК"';
}

function roGetTodayMinsk() {
    $tz = new DateTimeZone('Europe/Minsk');
    return (new DateTime('now', $tz))->format('Y-m-d');
}

function roRestaurantHasDeliveryDate($pdo, $restaurantNumber, $legalEntityGroup, $deliveryDate) {
    if (!$deliveryDate) return false;
    $dow = (int)(new DateTime($deliveryDate))->format('N');
    $group = $legalEntityGroup ?: 'BK_VM';
    $s = $pdo->prepare("
        SELECT 1
        FROM delivery_schedule ds
        JOIN restaurants r ON r.id = ds.restaurant_id
        WHERE r.number = ?
          AND r.active = 1
          AND r.legal_entity_group = ?
          AND ds.day_of_week = ?
        LIMIT 1
    ");
    $s->execute([(int)$restaurantNumber, $group, $dow]);
    return (bool)$s->fetchColumn();
}

/**
 * Запись события в журнал изменений заказов ресторанов.
 * Вызывается из всех мест, где меняется состояние ro_orders/ro_order_items.
 * @param array $e ['order_id', 'restaurant_number', 'delivery_date', 'action',
 *                  'actor_name', 'actor_type', 'sku', 'product_name',
 *                  'old_value', 'new_value', 'details' (array|null)]
 */
function roLogAudit($pdo, $e) {
    try {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip && strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
        $pdo->prepare("INSERT INTO ro_audit_log
            (order_id, restaurant_number, delivery_date, action, actor_name, actor_type, actor_ip, sku, product_name, old_value, new_value, details)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                $e['order_id'] ?? null,
                $e['restaurant_number'] ?? null,
                $e['delivery_date'] ?? null,
                $e['action'],
                $e['actor_name'] ?? null,
                $e['actor_type'] ?? 'system',
                $ip,
                $e['sku'] ?? null,
                $e['product_name'] ?? null,
                isset($e['old_value']) && $e['old_value'] !== null ? (string)$e['old_value'] : null,
                isset($e['new_value']) && $e['new_value'] !== null ? (string)$e['new_value'] : null,
                isset($e['details']) && $e['details'] !== null ? json_encode($e['details'], JSON_UNESCAPED_UNICODE) : null,
            ]);
    } catch (Exception $ex) {
        // Лог не критичен — не ломаем основной запрос
        error_log('roLogAudit failed: ' . $ex->getMessage());
    }
}

function roNotifyRestaurant($pdo, $restaurantNumber, $message) {
    $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
    if (!$botToken) return;
    $group = func_num_args() >= 4 ? roNormalizeLegalEntityGroup(func_get_arg(3), $restaurantNumber) : null;
    // Ищем telegram_chat_id из ro_users или veg_telegram_subs
    if ($group) {
        $s = $pdo->prepare("SELECT telegram_chat_id FROM ro_users WHERE restaurant_number = ? AND legal_entity_group = ? AND telegram_chat_id > 0");
        $s->execute([(int)$restaurantNumber, $group]);
    } else {
        $s = $pdo->prepare("SELECT telegram_chat_id FROM ro_users WHERE restaurant_number = ? AND telegram_chat_id > 0");
        $s->execute([(int)$restaurantNumber]);
    }
    $chatId = $s->fetchColumn();
    if (!$chatId) {
        $s2 = $pdo->prepare("SELECT chat_id FROM veg_telegram_subs WHERE restaurant_number = ? LIMIT 1");
        $s2->execute([$restaurantNumber]);
        $chatId = $s2->fetchColumn();
    }
    if ($chatId) {
        sendTelegramMessage($botToken, $chatId, $message);
    }
}

function roAggregateOrderItems($items) {
    $aggregated = [];
    foreach ($items as $item) {
        $qty = floatval($item['quantity'] ?? 0);
        if ($qty <= 0) continue;
        $sku = trim((string)($item['sku'] ?? ''));
        if ($sku === '') continue;
        if (!isset($aggregated[$sku])) {
            $aggregated[$sku] = [
                'sku' => $sku,
                'product_name' => $item['product_name'] ?? '',
                'category' => $item['category'] ?? 'Сухой',
                'quantity' => 0,
                'comment' => $item['comment'] ?? null,
            ];
        }
        $aggregated[$sku]['quantity'] += $qty;
        if (!empty($item['comment']) && empty($aggregated[$sku]['comment'])) {
            $aggregated[$sku]['comment'] = $item['comment'];
        }
    }
    return $aggregated;
}

function roHasMultiplicityViolation($qty, $multiplicity) {
    $qty = floatval($qty);
    $multiplicity = floatval($multiplicity);
    if ($qty <= 0 || $multiplicity <= 1) return false;
    $ratio = $qty / $multiplicity;
    return abs($ratio - round($ratio)) > 0.0001;
}

function roFindMultiplicityViolations($pdo, $legalEntity, $aggregatedItems) {
    if (!$legalEntity || empty($aggregatedItems)) return [];
    $skus = array_values(array_unique(array_filter(array_keys($aggregatedItems), fn($sku) => $sku !== '')));
    if (empty($skus)) return [];

    $ph = implode(',', array_fill(0, count($skus), '?'));
    $params = array_merge([$legalEntity], $skus);
    $s = $pdo->prepare("
        SELECT sku, name, COALESCE(multiplicity, 1) AS multiplicity
        FROM products
        WHERE legal_entity = ?
          AND is_active = 1
          AND sku IN ({$ph})
    ");
    $s->execute($params);

    $productMap = [];
    foreach ($s->fetchAll() as $row) {
        $productMap[$row['sku']] = $row;
    }

    $violations = [];
    foreach ($aggregatedItems as $sku => $item) {
        $product = $productMap[$sku] ?? null;
        $multiplicity = floatval($product['multiplicity'] ?? 1);
        $quantity = floatval($item['quantity'] ?? 0);
        if (!roHasMultiplicityViolation($quantity, $multiplicity)) continue;
        $violations[] = [
            'sku' => $sku,
            'product_name' => $product['name'] ?? ($item['product_name'] ?? ''),
            'quantity' => $quantity,
            'multiplicity' => $multiplicity,
        ];
    }

    return $violations;
}

function roFormatMultiplicityValue($value) {
    $num = floatval($value);
    if (abs($num - round($num)) < 0.0001) return (string)intval(round($num));
    return rtrim(rtrim(number_format($num, 3, '.', ''), '0'), '.');
}

function roRespondMultiplicityError($violations) {
    if (empty($violations)) return;
    $first = $violations[0];
    $message = 'Товар ' . $first['sku'] . ' «' . $first['product_name'] . '»: количество '
        . roFormatMultiplicityValue($first['quantity'])
        . ' должно быть кратно '
        . roFormatMultiplicityValue($first['multiplicity']);
    if (count($violations) > 1) {
        $message .= '. Некратных позиций: ' . count($violations);
    }
    roRespond(['error' => $message], 400);
}

function roGetSessionUserGroups($sessionUser) {
    if (!$sessionUser) return [];
    if (($sessionUser['role'] ?? '') === 'admin') return ['BK_VM', 'PS'];
    $userEntities = $sessionUser['legal_entities'] ?? '';
    if (is_string($userEntities)) {
        $userEntities = json_decode($userEntities, true);
    }
    if (!is_array($userEntities) || empty($userEntities)) return [];
    $groups = [];
    foreach ($userEntities as $entity) {
        $group = getEntityGroup($entity);
        if ($group && !in_array($group, $groups, true)) $groups[] = $group;
    }
    return $groups;
}

function roGetAllowedLegalEntities($sessionUser) {
    $entities = [];
    foreach (roGetSessionUserGroups($sessionUser) as $group) {
        foreach (getEntitiesInGroup($group) as $entity) {
            if (!in_array($entity, $entities, true)) $entities[] = $entity;
        }
    }
    return $entities;
}

function roEnsureGroupAccess($sessionUser, $group) {
    if (!$sessionUser) return;
    if (($sessionUser['role'] ?? '') === 'admin') return;
    $allowed = roGetSessionUserGroups($sessionUser);
    if (!$group || !in_array($group, $allowed, true)) {
        roRespond(['error' => 'Нет доступа к данным этого юрлица'], 403);
    }
}

function roEnsureRestaurantAccess($pdo, $sessionUser, $restaurantNumber, $group = null) {
    $resolvedGroup = roNormalizeLegalEntityGroup($group, $restaurantNumber);
    if (!$sessionUser) return;
    if (($sessionUser['role'] ?? '') === 'admin') return;
    $s = $pdo->prepare("SELECT legal_entity_group FROM restaurants WHERE number = ? AND legal_entity_group = ? AND active = 1 LIMIT 1");
    $s->execute([(int)$restaurantNumber, $resolvedGroup]);
    $group = $s->fetchColumn();
    if (!$group) {
        roRespond(['error' => 'Ресторан не найден'], 404);
    }
    roEnsureGroupAccess($sessionUser, $group);
}

function roApplyAllowedGroupsSql($sessionUser, &$where, &$params, $expr) {
    if (!$sessionUser) return;
    if (($sessionUser['role'] ?? '') === 'admin') return;
    $groups = roGetSessionUserGroups($sessionUser);
    if (empty($groups)) {
        $where[] = '1=0';
        return;
    }
    $ph = implode(',', array_fill(0, count($groups), '?'));
    $where[] = "{$expr} IN ({$ph})";
    foreach ($groups as $group) $params[] = $group;
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
    $s = $pdo->prepare("SELECT id, telegram_chat_id, restaurant_number, legal_entity_group FROM ro_tg_tokens WHERE token = ? AND expires_at > NOW() AND used = 0 LIMIT 1");
    $s->execute([$tgToken]);
    $tgAuth = $s->fetch();
    if (!$tgAuth) {
        roRespond(['success' => false, 'error' => 'Ссылка недействительна или истекла']);
    }

    // Помечаем токен использованным
    $pdo->prepare("UPDATE ro_tg_tokens SET used = 1 WHERE id = ?")->execute([$tgAuth['id']]);

    // Если в токене явно указан ресторан (например, выбран в меню Камако) — используем его
    $restNum = $tgAuth['restaurant_number'] ?? null;
    if (!$restNum) {
        // Иначе берём первую подписку этого чата
        $s = $pdo->prepare("SELECT restaurant_number FROM veg_telegram_subs WHERE chat_id = ? LIMIT 1");
        $s->execute([$tgAuth['telegram_chat_id']]);
        $sub = $s->fetch();
        if (!$sub) {
            roRespond(['success' => false, 'error' => 'Вы не подписаны ни на один ресторан в боте']);
        }
        $restNum = $sub['restaurant_number'];
    }
    $restGroup = roNormalizeLegalEntityGroup($tgAuth['legal_entity_group'] ?? null, $restNum);

    // Проверяем, есть ли учётка ресторана
    $s = $pdo->prepare("SELECT id, restaurant_number, legal_entity, legal_entity_group FROM ro_users WHERE restaurant_number = ? AND legal_entity_group = ? AND is_active = 1");
    $s->execute([$restNum, $restGroup]);
    $user = $s->fetch();
    if (!$user) {
        roRespond(['success' => false, 'error' => "Учётная запись ресторана {$restNum} не найдена. Обратитесь в отдел закупок."]);
    }

    // Создаём сессию — аналогично обычному логину
    $token = bin2hex(random_bytes(32));
    $activeUntil = date('Y-m-d H:i:s', strtotime('+3 hours'));
    $pdo->prepare("UPDATE ro_users SET session_token = ?, session_active_until = ?, last_login_at = NOW() WHERE id = ?")
        ->execute([$token, $activeUntil, $user['id']]);

    $rest = roGetRestaurantRow($pdo, $restNum, $restGroup);
    if (!$rest) {
        roRespond(['success' => false, 'error' => "Ресторан {$restNum} не найден или отключён"]);
    }

    roRespond([
        'success' => true,
        'token' => $token,
        'restaurant' => [
            'number' => $restNum,
            'legal_entity' => $user['legal_entity'],
            'legal_entity_group' => $rest['legal_entity_group'] ?? $restGroup,
            'region' => $rest['region'] ?? '',
            'city' => $rest['city'] ?? '',
            'address' => $rest['address'] ?? '',
        ],
    ]);
}

// --- Логин ---
if ($roAction === 'login' && $method === 'POST') {
    $restNum = intval($body['restaurant_number'] ?? 0);
    $restGroup = roNormalizeLegalEntityGroup($body['legal_entity_group'] ?? null, $restNum);
    $password = $body['password'] ?? '';
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if (!$restNum || !$password) {
        roRespond(['success' => false, 'error' => 'Введите номер ресторана и пароль'], 400);
    }

    if (!checkRateLimit($pdo, $clientIp, 15, 10)) {
        roRespond(['success' => false, 'error' => 'Слишком много попыток. Подождите 10 минут'], 429);
    }

    $s = $pdo->prepare("SELECT id, restaurant_number, password_hash, legal_entity, legal_entity_group, session_token, session_active_until, last_login_at FROM ro_users WHERE restaurant_number = ? AND legal_entity_group = ? AND is_active = 1");
    $s->execute([$restNum, $restGroup]);
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
    $activeUntil = date('Y-m-d H:i:s', strtotime('+3 hours'));
    $pdo->prepare("UPDATE ro_users SET session_token = ?, session_active_until = ?, last_login_at = NOW() WHERE id = ?")
        ->execute([$token, $activeUntil, $user['id']]);

    // Инфо о ресторане
    $rest = roGetRestaurantRow($pdo, $restNum, $restGroup);
    if (!$rest) {
        roRespond(['success' => false, 'error' => "Ресторан {$restNum} не найден или отключён"]);
    }

    roRespond([
        'success' => true,
        'token' => $token,
        'restaurant' => [
            'number' => $restNum,
            'legal_entity' => $user['legal_entity'],
            'legal_entity_group' => $rest['legal_entity_group'] ?? $restGroup,
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
    roRespond(['valid' => true, 'restaurant' => [
        'number' => $rest['restaurant_number'],
        'legal_entity' => $rest['legal_entity'],
        'legal_entity_group' => $rest['legal_entity_group'] ?? 'BK_VM',
        'region' => $rest['region'] ?? '',
        'city' => $rest['city'] ?? '',
        'address' => $rest['address'] ?? '',
    ]]);
}

// --- Выход ---
if ($roAction === 'logout' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if ($rest) {
        $pdo->prepare("UPDATE ro_users SET session_token = NULL, session_active_until = NULL WHERE id = ?")
            ->execute([$rest['id']]);
    }
    roRespond(['success' => true]);
}

// --- Смена пароля ---
if ($roAction === 'change-password' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!checkRateLimit($pdo, $clientIp, 10, 10)) {
        roRespond(['error' => 'Слишком много попыток. Подождите 10 минут'], 429);
    }
    $oldPass = $body['old_password'] ?? '';
    $newPass = $body['new_password'] ?? '';
    if (!$oldPass || !$newPass) roRespond(['error' => 'Заполните оба поля'], 400);
    if (mb_strlen($newPass) < 4) roRespond(['error' => 'Новый пароль слишком короткий (минимум 4 символа)'], 400);
    // Проверяем старый пароль
    $s = $pdo->prepare("SELECT id, password_hash FROM ro_users WHERE id = ? AND is_active = 1");
    $s->execute([$rest['id']]);
    $user = $s->fetch();
    if (!$user || !password_verify($oldPass, $user['password_hash'])) {
        recordFailedLogin($pdo, $clientIp, "ro_chpass_{$rest['restaurant_number']}");
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
    applyEntityTextFilter($group, $where, $params, 'sc.legal_entity');
    $sql = "SELECT sc.id, sc.name, sc.created_at,
                (SELECT COUNT(DISTINCT scd.product_id) FROM stock_collection_data scd
                 JOIN stock_collection_products scp ON scp.id = scd.product_id AND scp.collection_id = sc.id
                 WHERE scd.restaurant_number = ?) as submitted_count,
                (SELECT COUNT(*) FROM stock_collection_products scp2 WHERE scp2.collection_id = sc.id) as total_products
            FROM stock_collections sc WHERE " . implode(' AND ', $where) . " ORDER BY sc.id DESC LIMIT 1";
    $s = $pdo->prepare($sql);
    $s->execute(array_merge([$rest['restaurant_number']], $params));
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
            'submitted' => ((int)$collection['total_products'] > 0) && ((int)$collection['submitted_count'] >= (int)$collection['total_products']),
            'submitted_count' => (int)$collection['submitted_count'],
            'total_products' => (int)$collection['total_products'],
            'token' => $tok['token'] ?? null,
        ],
    ]);
}

// --- Данные активного сбора остатков для ресторана (товары + его значения) ---
if ($roAction === 'stock-collection-data' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $le = $rest['legal_entity'] ?: 'ООО "Бургер БК"';
    $group = getEntityGroup($le);
    $where = ["sc.status = 'active'"];
    $params = [];
    applyEntityTextFilter($group, $where, $params, 'sc.legal_entity');
    $s = $pdo->prepare("SELECT id, name, created_at FROM stock_collections sc WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT 1");
    $s->execute($params);
    $coll = $s->fetch();
    if (!$coll) roRespond(['active' => false]);

    // Товары сбора
    $p = $pdo->prepare("SELECT id, product_name, product_sku, unit, sort_order, note FROM stock_collection_products WHERE collection_id = ? ORDER BY sort_order, id");
    $p->execute([$coll['id']]);
    $products = $p->fetchAll();

    // Ранее сохранённые значения этого ресторана
    $d = $pdo->prepare("SELECT product_id, stock, submitted_at FROM stock_collection_data WHERE collection_id = ? AND restaurant_number = ?");
    $d->execute([$coll['id'], $rest['restaurant_number']]);
    $values = [];
    $lastSubmittedAt = null;
    foreach ($d->fetchAll() as $row) {
        $values[(int)$row['product_id']] = (float)$row['stock'];
        if (!$lastSubmittedAt || $row['submitted_at'] > $lastSubmittedAt) {
            $lastSubmittedAt = $row['submitted_at'];
        }
    }

    roRespond([
        'active' => true,
        'collection' => [
            'id' => (int)$coll['id'],
            'name' => $coll['name'],
        ],
        'products' => $products,
        'values' => $values,
        'last_submitted_at' => $lastSubmittedAt,
    ]);
}

// --- Сохранение остатков ресторана (из личного кабинета) ---
if ($roAction === 'stock-collection-submit' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $collId = intval($body['collection_id'] ?? 0);
    $items = $body['items'] ?? [];
    if ($collId <= 0) roRespond(['error' => 'Не указан сбор'], 400);
    if (!is_array($items)) roRespond(['error' => 'Некорректные данные'], 400);

    // Проверяем, что сбор активен и принадлежит юрлицу ресторана
    $le = $rest['legal_entity'] ?: 'ООО "Бургер БК"';
    $group = getEntityGroup($le);
    $check = $pdo->prepare("SELECT id, legal_entity FROM stock_collections WHERE id = ? AND status = 'active'");
    $check->execute([$collId]);
    $coll = $check->fetch();
    if (!$coll) roRespond(['error' => 'Сбор не найден или уже закрыт'], 404);
    $collGroup = getEntityGroup($coll['legal_entity']);
    if ($collGroup !== $group) roRespond(['error' => 'Сбор не для вашего юрлица'], 403);

    // Загружаем допустимые product_id для этой коллекции
    $validPids = $pdo->prepare("SELECT id FROM stock_collection_products WHERE collection_id = ?");
    $validPids->execute([$collId]);
    $allowedSet = array_flip(array_column($validPids->fetchAll(), 'id'));

    $ins = $pdo->prepare("INSERT INTO stock_collection_data (collection_id, product_id, restaurant_number, stock, source, submitted_at) VALUES (?, ?, ?, ?, 'form', NOW()) ON DUPLICATE KEY UPDATE stock = VALUES(stock), submitted_at = NOW()");
    $pdo->beginTransaction();
    try {
        $saved = 0;
        foreach ($items as $item) {
            $pid = intval($item['product_id'] ?? 0);
            $sv = floatval($item['stock'] ?? 0);
            if ($sv < 0 || $sv > 999999) continue;
            if ($pid > 0 && isset($allowedSet[$pid])) {
                $ins->execute([$collId, $pid, $rest['restaurant_number'], $sv]);
                $saved++;
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('ro stock-collection-submit error: ' . $e->getMessage());
        roRespond(['error' => 'Ошибка сохранения'], 500);
    }
    roRespond(['success' => true, 'saved' => $saved]);
}

// --- Привязка Telegram: генерация токена ---
if ($roAction === 'telegram-link' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    // Проверяем, привязан ли уже Telegram
    $s = $pdo->prepare("SELECT telegram_chat_id FROM ro_users WHERE id = ?");
    $s->execute([$rest['id']]);
    $user = $s->fetch();
    if ($user && $user['telegram_chat_id']) {
        roRespond(['already_linked' => true, 'chat_id' => $user['telegram_chat_id']]);
    }
    // Генерируем токен привязки (6-значный код)
    $code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    // Сохраняем в ro_tg_tokens (переиспользуем таблицу)
    $pdo->prepare("INSERT INTO ro_tg_tokens (token, telegram_chat_id, restaurant_number, legal_entity_group, expires_at, used) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 0)")
        ->execute([$code, 0, (string)$rest['restaurant_number'], $rest['legal_entity_group'] ?? 'BK_VM']);
    roRespond(['success' => true, 'code' => $code, 'expires_in' => 600]);
}

// --- Отвязка Telegram ---
if ($roAction === 'telegram-unlink' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $pdo->prepare("UPDATE ro_users SET telegram_chat_id = NULL WHERE id = ?")
        ->execute([$rest['id']]);
    roRespond(['success' => true]);
}

// --- Статус привязки Telegram ---
if ($roAction === 'telegram-status' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $s = $pdo->prepare("SELECT telegram_chat_id FROM ro_users WHERE id = ?");
    $s->execute([$rest['id']]);
    $user = $s->fetch();
    roRespond(['linked' => !empty($user['telegram_chat_id']), 'chat_id' => $user['telegram_chat_id'] ?? null]);
}

// --- Инфо: текущая сессия, расписание, дедлайны ---
if ($roAction === 'my-info' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);

    $group = $rest['legal_entity_group'] ?? 'BK_VM';
    $session = roGetActiveSession($pdo, $group);
    if (!$session) {
        roRespond(['session' => null, 'delivery_days' => []]);
    }

    // Расписание доставки этого ресторана
    $ds = $pdo->prepare("
        SELECT ds.day_of_week, ds.delivery_time
        FROM delivery_schedule ds
        JOIN restaurants r ON r.id = ds.restaurant_id
        WHERE r.number = ? AND r.active = 1
          AND r.legal_entity_group = ?
        ORDER BY ds.day_of_week
    ");
    $ds->execute([$rest['restaurant_number'], $rest['legal_entity_group'] ?? 'BK_VM']);
    $schedule = $ds->fetchAll();

    // Формируем дни доставки в рамках сессии (может быть несколько недель)
    $dayNames = [1=>'Понедельник',2=>'Вторник',3=>'Среда',4=>'Четверг',5=>'Пятница',6=>'Суббота',7=>'Воскресенье'];
    $deliveryDays = [];

    $weekStart = new DateTime($session['week_start']);
    $weekEnd = new DateTime($session['week_end']);

    foreach ($schedule as $sch) {
        $dow = (int)$sch['day_of_week'];
        // Находим первую дату этого дня недели в рамках сессии
        $date = clone $weekStart;
        $currentDow = (int)$date->format('N'); // 1=Mon
        $diff = $dow - $currentDow;
        if ($diff < 0) $diff += 7;
        $date->modify("+{$diff} days");

        // Перебираем все вхождения этого дня недели в рамках сессии
        while ($date <= $weekEnd) {
            $dateStr = $date->format('Y-m-d');

            // Проверяем есть ли уже заказ
            $os = $pdo->prepare("SELECT id, status, submitted_at FROM ro_orders WHERE session_id = ? AND restaurant_number = ? AND delivery_date = ?");
            $os->execute([$session['id'], $rest['restaurant_number'], $dateStr]);
            $order = $os->fetch();

            $dateOpen = roIsDateOpen($pdo, $session['id'], $dateStr);
            $today = roGetTodayMinsk();

            // Показываем дату если: приём открыт ИЛИ уже есть заказ ИЛИ дата сегодня/в будущем
            // (даже если приём закрыт — ресторан должен видеть свой график)
            if ($dateOpen || $order || $dateStr >= $today) {
                $deadlineStatus = roGetDeadlineStatus($pdo, $session['id'], $dateStr);

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

            $date->modify('+7 days');
        }
    }

    // Сортируем по дате
    usort($deliveryDays, function($a, $b) { return strcmp($a['date'], $b['date']); });

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
            LEFT JOIN products p ON p.sku = t.sku AND p.legal_entity = ? AND p.is_active = 1
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
    $session = roGetActiveSession($pdo, $rest['legal_entity_group'] ?? 'BK_VM');
    if (!$session) roRespond(['order' => null]);

    $s = $pdo->prepare("SELECT id, status, submitted_at, updated_at, updated_by, comment FROM ro_orders WHERE session_id = ? AND restaurant_number = ? AND delivery_date = ?");
    $s->execute([$session['id'], $rest['restaurant_number'], $date]);
    $order = $s->fetch();

    if (!$order) roRespond(['order' => null]);

    $items = $pdo->prepare("
        SELECT oi.sku, oi.product_name, oi.category, oi.quantity, oi.comment,
               COALESCE(p.multiplicity, 1) AS multiplicity
        FROM ro_order_items oi
        LEFT JOIN products p ON p.sku = oi.sku AND p.legal_entity = ? AND p.is_active = 1
        WHERE oi.order_id = ?
        ORDER BY oi.category, oi.product_name
    ");
    $items->execute([$rest['legal_entity'], $order['id']]);

    roRespond([
        'order' => [
            'id' => (int)$order['id'],
            'status' => $order['status'],
            'submitted_at' => $order['submitted_at'],
            'updated_at' => $order['updated_at'],
            'updated_by' => $order['updated_by'],
            'comment' => $order['comment'] ?? null,
            'items' => $items->fetchAll(),
        ],
    ]);
}

// --- Мои заказы (история) ---
if ($roAction === 'my-orders' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);

    $limit = min((int)($_GET['limit'] ?? 20), 50);
    $group = $rest['legal_entity_group'] ?? 'BK_VM';
    $where = ["o.restaurant_number = ?"];
    $params = [$rest['restaurant_number']];
    applyEntityTextFilter($group, $where, $params, 'o.legal_entity');
    $s = $pdo->prepare("
        SELECT o.id, o.delivery_date, o.status, o.submitted_at, o.updated_at,
               (SELECT COUNT(*) FROM ro_order_items WHERE order_id = o.id) as item_count,
               (SELECT SUM(quantity) FROM ro_order_items WHERE order_id = o.id) as total_qty
        FROM ro_orders o
        WHERE " . implode(' AND ', $where) . "
        ORDER BY o.delivery_date DESC
        LIMIT {$limit}
    ");
    $s->execute($params);
    roRespond(['orders' => $s->fetchAll()]);
}

// --- Объединённая история (все источники) ---
if ($roAction === 'all-history' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) roRespond(['error' => 'Не авторизован'], 401);
    $rn = $rest['restaurant_number'];
    $group = $rest['legal_entity_group'] ?? 'BK_VM';
    $entities = getEntitiesInGroup($group);
    $entityPh = implode(',', array_fill(0, count($entities), '?'));
    $limit = min((int)($_GET['limit'] ?? 30), 100);
    $allOrders = [];

    // 1. Основная поставка (ro_orders) — с суммой залога
    $s1 = $pdo->prepare("
        SELECT o.id, o.delivery_date, o.status, o.submitted_at,
               (SELECT COUNT(*) FROM ro_order_items WHERE order_id = o.id) as item_count,
               (SELECT SUM(quantity) FROM ro_order_items WHERE order_id = o.id) as total_qty,
               (SELECT SUM(oi.quantity * COALESCE(pp.price, 0))
                  FROM ro_order_items oi
                  LEFT JOIN product_prices pp ON pp.sku = oi.sku AND pp.legal_entity = o.legal_entity AND pp.price_type = 'deposit'
                  WHERE oi.order_id = o.id) as total_deposit
        FROM ro_orders o WHERE o.restaurant_number = ? AND o.legal_entity IN ({$entityPh})
        ORDER BY o.delivery_date DESC LIMIT {$limit}
    ");
    $s1->execute(array_merge([$rn], $entities));
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
        WHERE o.restaurant_number = ? AND o.legal_entity IN ({$entityPh})
        ORDER BY o.delivery_date DESC LIMIT {$limit}
    ");
    $s2->execute(array_merge([$rn], $entities));
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
          AND COALESCE(vs.legal_entity_group, 'BK_VM') = ?
        GROUP BY vo.session_id, vo.delivery_date
        ORDER BY vo.delivery_date DESC LIMIT {$limit}
    ");
    $s3->execute([$rn, $group]);
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
    $comment = $body['comment'] ?? null;

    if (!$deliveryDate) roRespond(['error' => 'Не указана дата доставки'], 400);
    if (empty($items)) roRespond(['error' => 'Заказ пуст'], 400);

    $session = roGetActiveSession($pdo, $rest['legal_entity_group'] ?? 'BK_VM');
    if (!$session) roRespond(['error' => 'Нет активной сессии приёма заявок'], 400);

    if (!roRestaurantHasDeliveryDate($pdo, $rest['restaurant_number'], $rest['legal_entity_group'] ?? 'BK_VM', $deliveryDate)) {
        roRespond(['error' => 'На эту дату у ресторана не запланирована поставка'], 400);
    }

    // Проверяем дедлайн
    $dlStatus = roGetDeadlineStatus($pdo, $session['id'], $deliveryDate);
    if ($dlStatus['status'] === 'closed' || $dlStatus['status'] === 'not_open') {
        roRespond(['error' => 'Приём заявок на эту дату закрыт'], 403);
    }

    $aggregated = roAggregateOrderItems($items);
    if (empty($aggregated)) roRespond(['error' => 'Заказ пуст'], 400);
    roRespondMultiplicityError(roFindMultiplicityViolations($pdo, $rest['legal_entity'], $aggregated));

    // Проверяем: есть ли уже заказ?
    $existingOrder = $pdo->prepare("SELECT id, status, submitted_at FROM ro_orders WHERE session_id = ? AND restaurant_number = ? AND delivery_date = ?");
    $existingOrder->execute([$session['id'], $rest['restaurant_number'], $deliveryDate]);
    $existing = $existingOrder->fetch();

    // Запоминаем старые позиции для diff в журнале
    $oldItemsAudit = [];
    if ($existing) {
        $oldSt = $pdo->prepare("SELECT sku, product_name, quantity FROM ro_order_items WHERE order_id = ?");
        $oldSt->execute([$existing['id']]);
        foreach ($oldSt->fetchAll() as $oi) {
            $oldItemsAudit[$oi['sku']] = ['name' => $oi['product_name'], 'qty' => floatval($oi['quantity'])];
        }
    }

    $pdo->beginTransaction();
    try {
        if ($existing) {
            // Обновляем — но проверяем, можно ли ещё редактировать
            if (!roCanEdit($pdo, $session['id'], $deliveryDate)) {
                $pdo->rollBack();
                roRespond(['error' => 'Время редактирования заказа истекло. Обратитесь в отдел закупок'], 403);
            }

            $orderId = $existing['id'];
            // Удаляем старые позиции
            $pdo->prepare("DELETE FROM ro_order_items WHERE order_id = ?")->execute([$orderId]);
            // Обновляем статус и комментарий
            $pdo->prepare("UPDATE ro_orders SET status = 'submitted', updated_at = NOW(), updated_by = ?, comment = ? WHERE id = ?")
                ->execute(["Ресторан {$rest['restaurant_number']}", $comment, $orderId]);
        } else {
            // Создаём новый заказ
            $le = $rest['legal_entity'];
            $pdo->prepare("INSERT INTO ro_orders (session_id, restaurant_number, delivery_date, status, submitted_at, updated_by, legal_entity, comment) VALUES (?, ?, ?, 'submitted', NOW(), ?, ?, ?)")
                ->execute([$session['id'], $rest['restaurant_number'], $deliveryDate, "Ресторан {$rest['restaurant_number']}", $le, $comment]);
            $orderId = $pdo->lastInsertId();
        }

        // Вставляем позиции (UNIQUE KEY на order_id+sku гарантирует отсутствие дублей)
        $insertItem = $pdo->prepare("INSERT INTO ro_order_items (order_id, sku, product_name, category, quantity, comment) VALUES (?, ?, ?, ?, ?, ?)");
        $totalQty = 0;
        $totalItems = 0;
        foreach ($aggregated as $item) {
            $insertItem->execute([
                $orderId,
                $item['sku'],
                $item['product_name'],
                $item['category'],
                $item['quantity'],
                $item['comment'],
            ]);
            $totalQty += $item['quantity'];
            $totalItems++;
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        roRespond(['error' => 'Ошибка сохранения заказа'], 500);
    }

    // ═══ Журнал: создание/обновление заявки рестораном ═══
    $newItemsAudit = [];
    foreach ($aggregated as $sku => $it) {
        $newItemsAudit[$sku] = ['name' => $it['product_name'] ?? '', 'qty' => floatval($it['quantity'] ?? 0)];
    }
    $actorNameRo = "Ресторан {$rest['restaurant_number']}";
    if (!$existing) {
        // Создание — одна запись-сводка (как раньше)
        roLogAudit($pdo, [
            'order_id' => $orderId,
            'restaurant_number' => $rest['restaurant_number'],
            'delivery_date' => $deliveryDate,
            'action' => 'order_created',
            'actor_name' => $actorNameRo,
            'actor_type' => 'restaurant',
            'new_value' => $totalItems . ' поз. / ' . $totalQty . ' кор.',
            'details' => [
                'total_items' => $totalItems,
                'total_qty' => $totalQty,
                'comment' => $comment,
            ],
        ]);
    } else {
        // Обновление — построчный diff, чтобы в журнале были видны конкретные изменения
        foreach ($newItemsAudit as $sku => $ni) {
            if (!isset($oldItemsAudit[$sku])) {
                roLogAudit($pdo, [
                    'order_id' => $orderId,
                    'restaurant_number' => $rest['restaurant_number'],
                    'delivery_date' => $deliveryDate,
                    'action' => 'item_added',
                    'actor_name' => $actorNameRo,
                    'actor_type' => 'restaurant',
                    'sku' => $sku,
                    'product_name' => $ni['name'],
                    'new_value' => (string)$ni['qty'],
                ]);
            }
        }
        foreach ($newItemsAudit as $sku => $ni) {
            if (isset($oldItemsAudit[$sku]) && abs($oldItemsAudit[$sku]['qty'] - $ni['qty']) > 0.001) {
                roLogAudit($pdo, [
                    'order_id' => $orderId,
                    'restaurant_number' => $rest['restaurant_number'],
                    'delivery_date' => $deliveryDate,
                    'action' => 'item_changed',
                    'actor_name' => $actorNameRo,
                    'actor_type' => 'restaurant',
                    'sku' => $sku,
                    'product_name' => $ni['name'],
                    'old_value' => (string)$oldItemsAudit[$sku]['qty'],
                    'new_value' => (string)$ni['qty'],
                ]);
            }
        }
        foreach ($oldItemsAudit as $sku => $oi) {
            if (!isset($newItemsAudit[$sku])) {
                roLogAudit($pdo, [
                    'order_id' => $orderId,
                    'restaurant_number' => $rest['restaurant_number'],
                    'delivery_date' => $deliveryDate,
                    'action' => 'item_deleted',
                    'actor_name' => $actorNameRo,
                    'actor_type' => 'restaurant',
                    'sku' => $sku,
                    'product_name' => $oi['name'],
                    'old_value' => (string)$oi['qty'],
                ]);
            }
        }
    }

    // Уведомление в Telegram о принятой/обновлённой заявке
    try {
        $isNew = !$existing;
        $deliveryDateFmt = (new DateTime($deliveryDate))->format('d.m.Y');

        // Группируем позиции по категориям
        $byCat = [];
        foreach ($aggregated as $it) {
            $q = floatval($it['quantity'] ?? 0);
            if ($q <= 0) continue;
            $cat = $it['category'] ?? 'Сухой';
            if (!isset($byCat[$cat])) $byCat[$cat] = [];
            $byCat[$cat][] = [
                'sku' => $it['sku'] ?? '',
                'name' => $it['product_name'] ?? '',
                'qty' => $q,
            ];
        }

        $fmtQty = function($q) {
            $s = number_format((float)$q, 1, '.', '');
            return rtrim(rtrim($s, '0'), '.');
        };
        $esc = function($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        };

        $catIcons = ['Сухой' => '📦', 'Холод' => '🧊', 'Мороз' => '❄️'];

        $title = $isNew ? '✅ <b>Заявка отправлена</b>' : '✏️ <b>Заявка обновлена</b>';
        $lines = [];
        $lines[] = $title;
        $lines[] = '';
        $lines[] = "📅 <b>Доставка:</b> {$deliveryDateFmt}";
        $lines[] = "📋 <b>Позиций:</b> {$totalItems}   📦 <b>Всего:</b> " . $fmtQty($totalQty) . " кор.";

        foreach (['Сухой', 'Холод', 'Мороз'] as $cat) {
            if (empty($byCat[$cat])) continue;
            $catItems = $byCat[$cat];
            $catQty = array_sum(array_column($catItems, 'qty'));
            $icon = $catIcons[$cat] ?? '•';
            $lines[] = '';
            $lines[] = "{$icon} <b>{$cat}</b> — " . count($catItems) . " поз., " . $fmtQty($catQty) . " кор.";
            foreach ($catItems as $ci) {
                $sku = $esc($ci['sku']);
                $name = $esc($ci['name']);
                $lines[] = "• <code>{$sku}</code> {$name} — <b>" . $fmtQty($ci['qty']) . "</b>";
            }
        }
        if ($comment !== null && $comment !== '') {
            $lines[] = '';
            $lines[] = "💬 <i>" . $esc($comment) . "</i>";
        }

        $msg = implode("\n", $lines);
        // Лимит Telegram ~4096 символов
        if (mb_strlen($msg) > 3900) {
            $msg = mb_substr($msg, 0, 3900) . "\n\n…(сообщение обрезано)";
        }

        roNotifyRestaurant($pdo, $rest['restaurant_number'], $msg, $rest['legal_entity_group'] ?? 'BK_VM');
    } catch (Exception $e) {
        // Уведомление не критично — игнорируем ошибку
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
        SELECT oi.sku, oi.product_name, oi.category, oi.quantity, oi.comment,
               COALESCE(p.multiplicity, 1) AS multiplicity
        FROM ro_order_items oi
        JOIN ro_orders o ON o.id = oi.order_id
        LEFT JOIN products p ON p.sku = oi.sku AND p.legal_entity = o.legal_entity AND p.is_active = 1
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

    // RBAC: проверяем доступ к модулю restaurant-orders
    if ($sessionUser) {
        global $ROLE_TEMPLATES, $ACCESS_LEVELS;
        $userRole = $sessionUser['role'] ?? 'user';
        if ($userRole !== 'admin') {
            $perms = resolvePermissions($userRole, $sessionUser['permissions'] ?? null, $ROLE_TEMPLATES);
            $roRequiredLevel = ($method === 'GET') ? $ACCESS_LEVELS['view'] : $ACCESS_LEVELS['edit'];
            $roUserLevel = $ACCESS_LEVELS[$perms['restaurant-orders'] ?? 'none'] ?? 0;
            if ($roUserLevel < $roRequiredLevel) {
                roRespond(['error' => 'Недостаточно прав для модуля «Заказы ресторанов»'], 403);
            }
        }
    }

    $adminAction = $roParts[2] ?? '';
    $adminParam = $roParts[3] ?? null;

    // --- Статус заявок ---
    if ($adminAction === 'status' && $method === 'GET') {
        $date = $_GET['date'] ?? date('Y-m-d', strtotime('+1 day'));
        $legalEntity = $_GET['legal_entity'] ?? null;
        $entityGroup = $legalEntity ? getEntityGroup($legalEntity) : null;
        if ($entityGroup) {
            roEnsureGroupAccess($sessionUser, $entityGroup);
        } else {
            $allowedGroups = roGetSessionUserGroups($sessionUser);
            $entityGroup = $allowedGroups[0] ?? 'BK_VM';
            roEnsureGroupAccess($sessionUser, $entityGroup);
        }

        $session = roGetActiveSession($pdo, $entityGroup);
        if (!$session) roRespond(['session' => null, 'orders' => []]);

        $deadlineStatus = roGetDeadlineStatus($pdo, $session['id'], $date);

        // Все активные рестораны группы юрлиц с расписанием на этот день.
        // JOIN с ro_orders также привязан к legal_entity_group, чтобы заказ
        // БК-ресторана с номером 1 не показался в списке ПС-ресторанов.
        $dow = (int)(new DateTime($date))->format('N');
        $rests = $pdo->prepare("
            SELECT r.number, r.region, r.city, r.address, r.legal_entity_group,
                   ds.delivery_time,
                   o.id as order_id, o.status as order_status, o.submitted_at, o.comment as order_comment,
                   o.updated_at, o.updated_by,
                   (SELECT COUNT(*) FROM ro_order_items WHERE order_id = o.id) as item_count,
                   (SELECT SUM(quantity) FROM ro_order_items WHERE order_id = o.id) as total_qty
            FROM restaurants r
            JOIN delivery_schedule ds ON ds.restaurant_id = r.id AND ds.day_of_week = ?
            LEFT JOIN ro_orders o
                ON o.restaurant_number = r.number
                AND o.session_id = ?
                AND o.delivery_date = ?
                AND (CASE WHEN o.legal_entity LIKE '%Пицца Стар%' THEN 'PS' ELSE 'BK_VM' END) = r.legal_entity_group
            WHERE r.active = 1 AND r.legal_entity_group = ?
            ORDER BY r.region, r.number
        ");
        $rests->execute([$dow, $session['id'], $date, $entityGroup]);
        $restaurants = $rests->fetchAll();

        // Подгружаем вес и паллеты для всех заказов
        $orderIds = array_values(array_filter(array_column($restaurants, 'order_id')));
        $weightData = [];
        if (!empty($orderIds)) {
            $ph = implode(',', array_fill(0, count($orderIds), '?'));
            $ws = $pdo->prepare("
                SELECT oi.order_id, oi.category,
                       SUM(oi.quantity * COALESCE(p.weight_brutto, 0)) as total_weight,
                       SUM(CASE WHEN p.boxes_per_pallet > 0
                           THEN (CASE WHEN COALESCE(p.multiplicity, 1) > 1
                                 THEN (oi.quantity / p.multiplicity) / p.boxes_per_pallet
                                 ELSE oi.quantity / p.boxes_per_pallet END)
                           ELSE 0 END) as raw_pallets
                FROM ro_order_items oi
                JOIN ro_orders o ON o.id = oi.order_id
                LEFT JOIN products p ON p.sku = oi.sku AND p.legal_entity = o.legal_entity AND p.is_active = 1
                WHERE oi.order_id IN ({$ph})
                GROUP BY oi.order_id, oi.category
            ");
            $ws->execute($orderIds);
            foreach ($ws->fetchAll() as $row) {
                $oid = $row['order_id'];
                if (!isset($weightData[$oid])) {
                    $weightData[$oid] = ['total_weight' => 0, 'pallets' => 0];
                }
                $weightData[$oid]['total_weight'] += (float)$row['total_weight'];
                // Округление: дробная часть ≤ 0.2 → вниз, > 0.2 → вверх.
                // Если товар есть (raw > 0) — минимум 1 паллета.
                $rawP = (float)$row['raw_pallets'];
                if ($rawP > 0) {
                    $frac = $rawP - floor($rawP);
                    $rounded = ($frac > 0.2) ? ceil($rawP) : floor($rawP);
                    if ($rounded < 1) $rounded = 1;
                    $weightData[$oid]['pallets'] += $rounded;
                }
            }
        }
        // Добавляем к каждому ресторану
        foreach ($restaurants as &$r) {
            $oid = $r['order_id'];
            $r['total_weight'] = $oid && isset($weightData[$oid]) ? round($weightData[$oid]['total_weight']) : null;
            $r['pallets'] = $oid && isset($weightData[$oid]) ? $weightData[$oid]['pallets'] : null;
        }
        unset($r);

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
                'legal_entity_group' => $session['legal_entity_group'] ?? $entityGroup,
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
        $s = $pdo->prepare("SELECT o.*, r.city, r.address, r.region FROM ro_orders o LEFT JOIN restaurants r ON r.number = o.restaurant_number AND r.active = 1 AND r.legal_entity_group = (CASE WHEN o.legal_entity LIKE '%Пицца Стар%' THEN 'PS' ELSE 'BK_VM' END) WHERE o.id = ?");
        $s->execute([$adminParam]);
        $order = $s->fetch();
        if (!$order) roRespond(['error' => 'Заказ не найден'], 404);
        // Проверка доступа к юр. лицу заказа: закупщик одной группы не должен видеть чужие заказы
        if ($sessionUser && !checkLegalEntityAccess($sessionUser, $order['legal_entity'] ?? '')) {
            roRespond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        }

        $items = $pdo->prepare("SELECT oi.*, p.weight_netto, p.weight_brutto, p.external_code, p.gtin, p.boxes_per_pallet, COALESCE(p.multiplicity, 1) as multiplicity, COALESCE(p.is_traceable, 0) as is_traceable,
                   (SELECT pp.price FROM product_prices pp WHERE pp.sku = oi.sku AND pp.legal_entity = ? AND pp.price_type = 'deposit' ORDER BY pp.updated_at DESC LIMIT 1) AS deposit_price
            FROM ro_order_items oi
            LEFT JOIN products p ON p.sku = oi.sku AND p.legal_entity = ? AND p.is_active = 1
            WHERE oi.order_id = ? ORDER BY oi.category, oi.product_name");
        $items->execute([$order['legal_entity'], $order['legal_entity'], $order['id']]);

        $order['items'] = $items->fetchAll();
        roRespond(['order' => $order]);
    }

    // --- Редактирование заказа закупщиком ---
    if ($adminAction === 'order' && $method === 'PATCH' && $adminParam) {
        $orderId = (int)$adminParam;
        $items = $body['items'] ?? null;
        $status = $body['status'] ?? null;
        $deliveryDate = $body['delivery_date'] ?? null;

        // Запоминаем старые позиции/состояние для сравнения и аудита
        $oldItems = [];
        $oldOrderSt = $pdo->prepare("SELECT restaurant_number, delivery_date, status, legal_entity FROM ro_orders WHERE id = ?");
        $oldOrderSt->execute([$orderId]);
        $oldOrder = $oldOrderSt->fetch() ?: [];
        // Проверка доступа к юр. лицу заказа
        if ($sessionUser && $oldOrder && !checkLegalEntityAccess($sessionUser, $oldOrder['legal_entity'] ?? '')) {
            roRespond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        }
        if ($items !== null) {
            $oldSt = $pdo->prepare("SELECT sku, product_name, quantity FROM ro_order_items WHERE order_id = ?");
            $oldSt->execute([$orderId]);
            foreach ($oldSt->fetchAll() as $oi) {
                $oldItems[$oi['sku']] = ['name' => $oi['product_name'], 'qty' => floatval($oi['quantity'])];
            }
        }

        $aggregated = $items !== null ? roAggregateOrderItems($items) : [];
        if ($items !== null) {
            roRespondMultiplicityError(roFindMultiplicityViolations($pdo, $oldOrder['legal_entity'] ?? '', $aggregated));
        }

        $pdo->beginTransaction();
        try {
            if ($items !== null) {
                // Обновляем позиции с агрегацией по SKU (на случай, если фронт
                // прислал один и тот же товар несколькими строками).
                $pdo->prepare("DELETE FROM ro_order_items WHERE order_id = ?")->execute([$orderId]);
                $insert = $pdo->prepare("INSERT INTO ro_order_items (order_id, sku, product_name, category, quantity, comment) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($aggregated as $ag) {
                    $insert->execute([$orderId, $ag['sku'], $ag['product_name'], $ag['category'], $ag['quantity'], $ag['comment']]);
                }
            }

            if ($deliveryDate) {
                $pdo->prepare("UPDATE ro_orders SET delivery_date = ? WHERE id = ?")
                    ->execute([$deliveryDate, $orderId]);
            }

            $updatedBy = $sessionUser ? $sessionUser['name'] : 'admin';
            $newStatus = $status ?: 'edited';
            $pdo->prepare("UPDATE ro_orders SET status = ?, updated_at = NOW(), updated_by = ? WHERE id = ?")
                ->execute([$newStatus, $updatedBy, $orderId]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            roRespond(['error' => 'Ошибка сохранения'], 500);
        }

        // ═══ Журнал: правка заказа закупщиком ═══
        $actorName = $sessionUser ? $sessionUser['name'] : 'admin';
        if ($items !== null) {
            $newItemsAudit = [];
            foreach (roAggregateOrderItems($items) as $sku => $it) {
                $newItemsAudit[$sku] = ['name' => $it['product_name'] ?? '', 'qty' => floatval($it['quantity'] ?? 0)];
            }
            // Добавленные
            foreach ($newItemsAudit as $sku => $ni) {
                if (!isset($oldItems[$sku])) {
                    roLogAudit($pdo, [
                        'order_id' => $orderId,
                        'restaurant_number' => $oldOrder['restaurant_number'] ?? null,
                        'delivery_date' => $oldOrder['delivery_date'] ?? null,
                        'action' => 'item_added',
                        'actor_name' => $actorName,
                        'actor_type' => 'admin',
                        'sku' => $sku,
                        'product_name' => $ni['name'],
                        'new_value' => (string)$ni['qty'],
                    ]);
                }
            }
            // Изменённые
            foreach ($newItemsAudit as $sku => $ni) {
                if (isset($oldItems[$sku]) && abs($oldItems[$sku]['qty'] - $ni['qty']) > 0.001) {
                    roLogAudit($pdo, [
                        'order_id' => $orderId,
                        'restaurant_number' => $oldOrder['restaurant_number'] ?? null,
                        'delivery_date' => $oldOrder['delivery_date'] ?? null,
                        'action' => 'item_changed',
                        'actor_name' => $actorName,
                        'actor_type' => 'admin',
                        'sku' => $sku,
                        'product_name' => $ni['name'],
                        'old_value' => (string)$oldItems[$sku]['qty'],
                        'new_value' => (string)$ni['qty'],
                    ]);
                }
            }
            // Удалённые
            foreach ($oldItems as $sku => $oi2) {
                if (!isset($newItemsAudit[$sku])) {
                    roLogAudit($pdo, [
                        'order_id' => $orderId,
                        'restaurant_number' => $oldOrder['restaurant_number'] ?? null,
                        'delivery_date' => $oldOrder['delivery_date'] ?? null,
                        'action' => 'item_deleted',
                        'actor_name' => $actorName,
                        'actor_type' => 'admin',
                        'sku' => $sku,
                        'product_name' => $oi2['name'],
                        'old_value' => (string)$oi2['qty'],
                    ]);
                }
            }
        }
        if ($status && isset($oldOrder['status']) && $oldOrder['status'] !== $status) {
            roLogAudit($pdo, [
                'order_id' => $orderId,
                'restaurant_number' => $oldOrder['restaurant_number'] ?? null,
                'delivery_date' => $oldOrder['delivery_date'] ?? null,
                'action' => 'status_changed',
                'actor_name' => $actorName,
                'actor_type' => 'admin',
                'old_value' => $oldOrder['status'],
                'new_value' => $status,
            ]);
        }
        if ($deliveryDate && isset($oldOrder['delivery_date']) && $oldOrder['delivery_date'] !== $deliveryDate) {
            roLogAudit($pdo, [
                'order_id' => $orderId,
                'restaurant_number' => $oldOrder['restaurant_number'] ?? null,
                'delivery_date' => $deliveryDate,
                'action' => 'delivery_date_changed',
                'actor_name' => $actorName,
                'actor_type' => 'admin',
                'old_value' => $oldOrder['delivery_date'],
                'new_value' => $deliveryDate,
            ]);
        }

        // Уведомляем ресторан в Telegram с деталями изменений
        $orderInfo = $pdo->prepare("SELECT restaurant_number, delivery_date FROM ro_orders WHERE id = ?");
        $orderInfo->execute([$orderId]);
        $oi = $orderInfo->fetch();
        if ($oi) {
            $dayNames = [0=>'Воскресенье',1=>'Понедельник',2=>'Вторник',3=>'Среда',4=>'Четверг',5=>'Пятница',6=>'Суббота'];
            $dow = (int)date('w', strtotime($oi['delivery_date']));
            $dayName = $dayNames[$dow] ?? '';
            $dateStr = $dayName . ', ' . date('d.m', strtotime($oi['delivery_date']));
            $restNum = $oi['restaurant_number'];

            $msg = "📝 Ресторан {$restNum} — заказ на {$dateStr}\n";
            $msg .= "Изменён: {$updatedBy}\n";

            // Формируем список изменений
            if ($items !== null) {
                $newItems = [];
                foreach (roAggregateOrderItems($items) as $sku => $item) {
                    $newItems[$sku] = ['name' => $item['product_name'] ?? '', 'qty' => floatval($item['quantity'] ?? 0)];
                }

                $changes = [];
                // Добавленные
                foreach ($newItems as $sku => $ni) {
                    if (!isset($oldItems[$sku])) {
                        $changes[] = "  ➕ {$ni['name']} — {$ni['qty']} кор.";
                    }
                }
                // Изменённые
                foreach ($newItems as $sku => $ni) {
                    if (isset($oldItems[$sku]) && abs($oldItems[$sku]['qty'] - $ni['qty']) > 0.001) {
                        $oldQ = $oldItems[$sku]['qty'];
                        $diff = $ni['qty'] - $oldQ;
                        $arrow = $diff > 0 ? '↑' : '↓';
                        $changes[] = "  ✏️ {$ni['name']}: {$oldQ} → {$ni['qty']} ({$arrow}" . abs($diff) . ")";
                    }
                }
                // Удалённые
                foreach ($oldItems as $sku => $oi2) {
                    if (!isset($newItems[$sku])) {
                        $changes[] = "  ❌ {$oi2['name']} — убрано";
                    }
                }

                if (!empty($changes)) {
                    $msg .= "\nИзменения:\n" . implode("\n", $changes);
                }

                // Итого
                $totalItems = count($newItems);
                $totalQty = array_sum(array_column($newItems, 'qty'));
                $msg .= "\n\nИтого: {$totalItems} поз., {$totalQty} кор.";
            }

            if ($deliveryDate) {
                $newDow = (int)date('w', strtotime($deliveryDate));
                $newDayName = $dayNames[$newDow] ?? '';
                $newDateStr = $newDayName . ', ' . date('d.m', strtotime($deliveryDate));
                $msg .= "\n📅 Дата доставки изменена на {$newDateStr}";
            }

            roNotifyRestaurant($pdo, $restNum, $msg, getEntityGroup($oldOrder['legal_entity'] ?? '') === 'PS' ? 'PS' : 'BK_VM');
        }

        roRespond(['success' => true]);
    }

    // --- Удаление заказа закупщиком ---
    if ($adminAction === 'order' && $method === 'DELETE' && $adminParam) {
        $orderId = (int)$adminParam;
        // Сохраняем инфо для уведомления и журнала до удаления
        $orderInfo = $pdo->prepare("SELECT restaurant_number, delivery_date, legal_entity FROM ro_orders WHERE id = ?");
        $orderInfo->execute([$orderId]);
        $oi = $orderInfo->fetch();
        if (!$oi) roRespond(['error' => 'Заказ не найден'], 404);
        // Проверка доступа к юр. лицу заказа
        if ($sessionUser && !checkLegalEntityAccess($sessionUser, $oi['legal_entity'] ?? '')) {
            roRespond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        }

        // Запоминаем позиции для журнала
        $delItemsSt = $pdo->prepare("SELECT sku, product_name, quantity FROM ro_order_items WHERE order_id = ?");
        $delItemsSt->execute([$orderId]);
        $delItems = $delItemsSt->fetchAll();

        $pdo->prepare("DELETE FROM ro_order_items WHERE order_id = ?")->execute([$orderId]);
        $pdo->prepare("DELETE FROM ro_orders WHERE id = ?")->execute([$orderId]);

        // ═══ Журнал: удаление заказа целиком ═══
        if ($oi) {
            $actorName = $sessionUser ? $sessionUser['name'] : 'admin';
            $totalQty = array_sum(array_map(fn($r) => floatval($r['quantity']), $delItems));
            roLogAudit($pdo, [
                'order_id' => $orderId,
                'restaurant_number' => $oi['restaurant_number'],
                'delivery_date' => $oi['delivery_date'],
                'action' => 'order_deleted',
                'actor_name' => $actorName,
                'actor_type' => 'admin',
                'old_value' => count($delItems) . ' поз. / ' . $totalQty . ' кор.',
                'details' => ['items' => $delItems],
            ]);
        }

        // Уведомляем ресторан
        if ($oi) {
            $dayNames = [0=>'Воскресенье',1=>'Понедельник',2=>'Вторник',3=>'Среда',4=>'Четверг',5=>'Пятница',6=>'Суббота'];
            $dow = (int)date('w', strtotime($oi['delivery_date']));
            $dayName = $dayNames[$dow] ?? '';
            $dateStr = $dayName . ', ' . date('d.m', strtotime($oi['delivery_date']));
            $restNum = $oi['restaurant_number'];
            $by = $sessionUser ? $sessionUser['name'] : 'admin';
            roNotifyRestaurant($pdo, $restNum,
                "❌ Ресторан {$restNum} — заказ на {$dateStr} удалён ({$by}). Если это ошибка — свяжитесь с нами.",
                getEntityGroup($oi['legal_entity'] ?? '') === 'PS' ? 'PS' : 'BK_VM');
        }

        roRespond(['success' => true]);
    }

    // --- Удаление отдельной позиции из заказа ---
    if ($adminAction === 'item' && $method === 'DELETE' && $adminParam) {
        $itemId = (int)$adminParam;
        // Проверяем существование (сначала по id)
        $check = $pdo->prepare("SELECT oi.id, oi.sku, oi.product_name, oi.quantity, o.restaurant_number, o.delivery_date, o.id as order_id, o.legal_entity
            FROM ro_order_items oi JOIN ro_orders o ON o.id = oi.order_id WHERE oi.id = ?");
        $check->execute([$itemId]);
        $item = $check->fetch();

        // Фоллбэк: заказ мог быть пересохранён (все позиции пересоздаются с новыми id).
        // Ищем по паре (order_id, sku) — её передаём в query-параметрах для устойчивости.
        if (!$item) {
            $fbOrderId = $_GET['order_id'] ?? null;
            $fbSku = $_GET['sku'] ?? null;
            if ($fbOrderId && $fbSku) {
                $fb = $pdo->prepare("SELECT oi.id, oi.sku, oi.product_name, oi.quantity, o.restaurant_number, o.delivery_date, o.id as order_id, o.legal_entity
                    FROM ro_order_items oi JOIN ro_orders o ON o.id = oi.order_id
                    WHERE oi.order_id = ? AND oi.sku = ?");
                $fb->execute([$fbOrderId, $fbSku]);
                $item = $fb->fetch();
            }
        }

        if (!$item) roRespond(['error' => 'Позиция не найдена'], 404);
        if ($sessionUser && !checkLegalEntityAccess($sessionUser, $item['legal_entity'] ?? '')) {
            roRespond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        }

        $pdo->prepare("DELETE FROM ro_order_items WHERE id = ?")->execute([$item['id']]);

        // ═══ Журнал: удаление одной позиции закупщиком ═══
        $actorName = $sessionUser ? $sessionUser['name'] : 'admin';
        roLogAudit($pdo, [
            'order_id' => $item['order_id'],
            'restaurant_number' => $item['restaurant_number'],
            'delivery_date' => $item['delivery_date'],
            'action' => 'item_deleted',
            'actor_name' => $actorName,
            'actor_type' => 'admin',
            'sku' => $item['sku'],
            'product_name' => $item['product_name'],
            'old_value' => (string)$item['quantity'],
        ]);

        // Если в заказе больше нет позиций — удаляем сам заказ
        $remaining = $pdo->prepare("SELECT COUNT(*) FROM ro_order_items WHERE order_id = ?");
        $remaining->execute([$item['order_id']]);
        $orderDeleted = false;
        if ((int)$remaining->fetchColumn() === 0) {
            $pdo->prepare("DELETE FROM ro_orders WHERE id = ?")->execute([$item['order_id']]);
            $orderDeleted = true;
            roLogAudit($pdo, [
                'order_id' => $item['order_id'],
                'restaurant_number' => $item['restaurant_number'],
                'delivery_date' => $item['delivery_date'],
                'action' => 'order_deleted',
                'actor_name' => $actorName,
                'actor_type' => 'admin',
                'old_value' => 'последняя позиция удалена',
            ]);
        }

        roRespond(['success' => true, 'order_deleted' => $orderDeleted]);
    }

    // --- Управление сессией ---
    if ($adminAction === 'session' && $method === 'POST') {
        $action = $body['action'] ?? 'create';
        $entityGroup = $body['legal_entity_group'] ?? null;
        if (!$entityGroup) {
            $legalEntity = $body['legal_entity'] ?? null;
            $entityGroup = $legalEntity ? getEntityGroup($legalEntity) : null;
        }
        if (!$entityGroup) {
            $allowedGroups = roGetSessionUserGroups($sessionUser);
            $entityGroup = $allowedGroups[0] ?? 'BK_VM';
        }
        roEnsureGroupAccess($sessionUser, $entityGroup);

        if ($action === 'create') {
            $weekStart = $body['week_start'] ?? date('Y-m-d', strtotime('monday this week'));
            $weekEnd = $body['week_end'] ?? date('Y-m-d', strtotime('saturday this week'));
            $createdBy = $sessionUser ? $sessionUser['name'] : 'system';

            if (roSessionsSupportGroups($pdo)) {
                // Закрываем старые сессии только этой группы
                $pdo->prepare("UPDATE ro_sessions SET status = 'closed' WHERE status = 'active' AND legal_entity_group = ?")
                    ->execute([$entityGroup]);

                $pdo->prepare("INSERT INTO ro_sessions (week_start, week_end, legal_entity_group, created_by) VALUES (?, ?, ?, ?)")
                    ->execute([$weekStart, $weekEnd, $entityGroup, $createdBy]);
            } else {
                if ($entityGroup !== 'BK_VM') {
                    roRespond(['error' => 'Для Пицца Стар нужно сначала применить миграцию базы'], 400);
                }
                $pdo->exec("UPDATE ro_sessions SET status = 'closed' WHERE status = 'active'");
                $pdo->prepare("INSERT INTO ro_sessions (week_start, week_end, created_by) VALUES (?, ?, ?)")
                    ->execute([$weekStart, $weekEnd, $createdBy]);
            }

            roRespond(['success' => true, 'session_id' => (int)$pdo->lastInsertId()]);
        }

        if ($action === 'close') {
            $sessionId = $body['session_id'] ?? null;
            if ($sessionId) {
                $session = roGetSessionById($pdo, $sessionId);
                if (!$session) roRespond(['error' => 'Сессия не найдена'], 404);
                roEnsureGroupAccess($sessionUser, $session['legal_entity_group'] ?? 'BK_VM');
                $pdo->prepare("UPDATE ro_sessions SET status = 'closed' WHERE id = ?")->execute([$sessionId]);
            }
            roRespond(['success' => true]);
        }

        if ($action === 'auto') {
            // Автосоздание: если нет активной — создаём на текущую неделю
            $existing = roGetActiveSession($pdo, $entityGroup);
            if ($existing) {
                roRespond(['success' => true, 'session' => $existing, 'created' => false]);
            }
            $weekStart = date('Y-m-d', strtotime('monday this week'));
            $weekEnd = date('Y-m-d', strtotime('saturday this week'));
            if (roSessionsSupportGroups($pdo)) {
                $pdo->prepare("INSERT INTO ro_sessions (week_start, week_end, legal_entity_group, created_by) VALUES (?, ?, ?, 'auto')")
                    ->execute([$weekStart, $weekEnd, $entityGroup]);
            } else {
                if ($entityGroup !== 'BK_VM') {
                    roRespond(['error' => 'Для Пицца Стар нужно сначала применить миграцию базы'], 400);
                }
                $pdo->prepare("INSERT INTO ro_sessions (week_start, week_end, created_by) VALUES (?, ?, 'auto')")
                    ->execute([$weekStart, $weekEnd]);
            }
            $newSession = $pdo->prepare("SELECT * FROM ro_sessions WHERE id = ?");
            $newSession->execute([$pdo->lastInsertId()]);
            roRespond(['success' => true, 'session' => $newSession->fetch(), 'created' => true]);
        }

        roRespond(['error' => 'Unknown action'], 400);
    }

    // --- Открыть / закрыть приём на дату ---
    if ($adminAction === 'toggle-date' && $method === 'POST') {
        $sessionId = $body['session_id'] ?? null;
        $date = $body['delivery_date'] ?? '';
        $isOpen = isset($body['is_open']) ? ($body['is_open'] ? 1 : 0) : 1;
        $createdBy = $sessionUser ? $sessionUser['name'] : 'admin';

        if (!$sessionId || !$date) roRespond(['error' => 'Не указана сессия или дата'], 400);
        $session = roGetSessionById($pdo, $sessionId);
        if (!$session) roRespond(['error' => 'Сессия не найдена'], 404);
        roEnsureGroupAccess($sessionUser, $session['legal_entity_group'] ?? 'BK_VM');

        // Если открываем дату за пределами сессии — расширяем сессию
        if ($isOpen) {
            if ($date < $session['week_start']) {
                $pdo->prepare("UPDATE ro_sessions SET week_start = ? WHERE id = ?")->execute([$date, $sessionId]);
            }
            if ($date > $session['week_end']) {
                $pdo->prepare("UPDATE ro_sessions SET week_end = ? WHERE id = ?")->execute([$date, $sessionId]);
            }
        }

        $pdo->prepare("INSERT INTO ro_deadline_overrides (session_id, delivery_date, is_open, created_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE is_open = VALUES(is_open), created_by = VALUES(created_by)")
            ->execute([$sessionId, $date, $isOpen, $createdBy]);

        roRespond(['success' => true, 'is_open' => (bool)$isOpen]);
    }

    // --- Список открытых дат сессии ---
    if ($adminAction === 'open-dates' && $method === 'GET') {
        $sessionId = $_GET['session_id'] ?? null;
        if (!$sessionId) {
            $legalEntity = $_GET['legal_entity'] ?? null;
            $entityGroup = $legalEntity ? getEntityGroup($legalEntity) : null;
            if (!$entityGroup) {
                $allowedGroups = roGetSessionUserGroups($sessionUser);
                $entityGroup = $allowedGroups[0] ?? 'BK_VM';
            }
            roEnsureGroupAccess($sessionUser, $entityGroup);
            $session = roGetActiveSession($pdo, $entityGroup);
            $sessionId = $session ? $session['id'] : null;
        }
        if (!$sessionId) roRespond(['dates' => []]);
        $session = roGetSessionById($pdo, $sessionId);
        if (!$session) roRespond(['dates' => []]);
        roEnsureGroupAccess($sessionUser, $session['legal_entity_group'] ?? 'BK_VM');

        $s = $pdo->prepare("SELECT delivery_date, is_open, soft_deadline, hard_deadline FROM ro_deadline_overrides WHERE session_id = ? ORDER BY delivery_date");
        $s->execute([$sessionId]);
        roRespond(['dates' => $s->fetchAll()]);
    }

    // --- Продление дедлайна ---
    if ($adminAction === 'extend-deadline' && $method === 'POST') {
        $sessionId = $body['session_id'] ?? null;
        $date = $body['delivery_date'] ?? '';
        $softDeadline = $body['soft_deadline'] ?? '14:00:00';
        $hardDeadline = $body['hard_deadline'] ?? '16:00:00';
        $createdBy = $sessionUser ? $sessionUser['name'] : 'admin';

        if (!$sessionId || !$date) roRespond(['error' => 'Не указана сессия или дата'], 400);
        $session = roGetSessionById($pdo, $sessionId);
        if (!$session) roRespond(['error' => 'Сессия не найдена'], 404);
        roEnsureGroupAccess($sessionUser, $session['legal_entity_group'] ?? 'BK_VM');

        $pdo->prepare("INSERT INTO ro_deadline_overrides (session_id, delivery_date, is_open, soft_deadline, hard_deadline, created_by) VALUES (?, ?, 1, ?, ?, ?) ON DUPLICATE KEY UPDATE soft_deadline = VALUES(soft_deadline), hard_deadline = VALUES(hard_deadline), created_by = VALUES(created_by)")
            ->execute([$sessionId, $date, $softDeadline, $hardDeadline, $createdBy]);

        roRespond(['success' => true]);
    }

    // --- Шаблоны ---
    if ($adminAction === 'templates' && $method === 'GET') {
        $le = $_GET['legal_entity'] ?? 'ООО "Бургер БК"';
        $category = $_GET['category'] ?? null;
        roEnsureGroupAccess($sessionUser, getEntityGroup($le));

        $q = "SELECT t.*, COALESCE(p.multiplicity, 1) as multiplicity
            FROM ro_templates t
            LEFT JOIN products p ON p.sku = t.sku AND p.legal_entity = ? AND p.is_active = 1
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
            roEnsureGroupAccess($sessionUser, getEntityGroup($le));

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
            // Импорт из ro_stock_balances (вкладка «Остатки склада»).
            // Берём последнюю загруженную дату остатков для данного юр. лица
            // и только те позиции, у которых есть остаток (quantity > 0),
            // а категория товара совпадает с выбранной.
            $le = $body['legal_entity'] ?? 'ООО "Бургер БК"';
            $category = $body['category'] ?? '';
            roEnsureGroupAccess($sessionUser, getEntityGroup($le));

            // Последняя дата остатков для юрлица
            $dateStmt = $pdo->prepare("SELECT MAX(balance_date) FROM ro_stock_balances WHERE legal_entity = ?");
            $dateStmt->execute([$le]);
            $latestDate = $dateStmt->fetchColumn();
            if (!$latestDate) {
                roRespond(['error' => 'Нет данных об остатках склада для «' . $le . '». Сначала загрузите файл остатков на вкладке «Остатки склада».'], 400);
            }

            $s = $pdo->prepare("
                SELECT DISTINCT p.sku, p.name AS product_name, COALESCE(p.multiplicity, 1) AS multiplicity
                FROM ro_stock_balances sb
                JOIN products p ON p.sku = sb.sku AND p.legal_entity = sb.legal_entity AND p.is_active = 1
                WHERE sb.legal_entity = ?
                  AND sb.balance_date = ?
                  AND sb.quantity > 0
                  AND p.category = ?
                ORDER BY p.name
            ");
            $s->execute([$le, $latestDate, $category]);
            $products = $s->fetchAll();

            // Сохраняем как шаблон
            $pdo->prepare("DELETE FROM ro_templates WHERE legal_entity = ? AND category = ?")->execute([$le, $category]);
            $insert = $pdo->prepare("INSERT INTO ro_templates (legal_entity, category, sku, product_name, sort_order) VALUES (?, ?, ?, ?, ?)");
            foreach ($products as $i => $p) {
                $insert->execute([$le, $category, $p['sku'], $p['product_name'], $i]);
            }

            roRespond(['success' => true, 'count' => count($products), 'items' => $products, 'balance_date' => $latestDate]);
        }

        roRespond(['error' => 'Unknown action'], 400);
    }

    // --- Управление учётками ресторанов ---
    // Источник истины — справочник ресторанов. ro_users только хранит пароль/сессию.
    if ($adminAction === 'users' && $method === 'GET') {
        // В выборку включаем legal_entity_group ресторана — нужно, чтобы
        // подставить правильное юрлицо (особенно для Пицца Стар, где у ресторана
        // может совпадать номер с БК).
        $usersWhere = ["r.active = 1"];
        $usersParams = [];
        roApplyAllowedGroupsSql($sessionUser, $usersWhere, $usersParams, "r.legal_entity_group");
        $s = $pdo->prepare("
            SELECT
                r.number AS restaurant_number,
                r.legal_entity_group,
                r.region,
                r.city,
                r.address,
                ru.id,
                ru.legal_entity,
                ru.is_active,
                ru.last_login_at,
                ru.telegram_chat_id,
                CASE WHEN ru.password_hash IS NULL OR ru.password_hash = '' THEN 0 ELSE 1 END AS has_password
            FROM restaurants r
            LEFT JOIN ro_users ru
                   ON ru.restaurant_number = r.number
                  AND ru.legal_entity_group COLLATE utf8mb4_general_ci = r.legal_entity_group
            WHERE " . implode(' AND ', $usersWhere) . "
            ORDER BY r.legal_entity_group, r.number
        ");
        $s->execute($usersParams);
        $rows = $s->fetchAll();
        // Подставим юрлицо для тех, у кого ещё нет учётки
        foreach ($rows as &$row) {
            if (empty($row['legal_entity'])) {
                $row['legal_entity'] = roGetLegalEntity($pdo, $row['restaurant_number'], $row['legal_entity_group']);
            }
            $row['is_active'] = (int)($row['is_active'] ?? 1);
            $row['has_password'] = (int)$row['has_password'];
        }
        unset($row);
        roRespond(['users' => $rows]);
    }

    if ($adminAction === 'users' && $method === 'POST') {
        $action = $body['action'] ?? 'create';

        if ($action === 'create') {
            $restNum = (int)($body['restaurant_number'] ?? 0);
            $restGroup = roNormalizeLegalEntityGroup($body['legal_entity_group'] ?? null, $restNum);
            $password = $body['password'] ?? '';
            if (!$restNum || !$password) roRespond(['error' => 'Не указан номер или пароль'], 400);
            roEnsureRestaurantAccess($pdo, $sessionUser, $restNum, $restGroup);

            $le = roGetLegalEntity($pdo, $restNum, $restGroup);
            $hash = password_hash($password, PASSWORD_BCRYPT);

            $pdo->prepare("INSERT INTO ro_users (restaurant_number, legal_entity_group, password_hash, legal_entity) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), legal_entity = VALUES(legal_entity), is_active = 1")
                ->execute([$restNum, $restGroup, $hash, $le]);

            roRespond(['success' => true, 'restaurant_number' => $restNum, 'legal_entity_group' => $restGroup]);
        }

        if ($action === 'create-bulk') {
            // Назначить пароль для ресторанов
            // mode = 'missing' (по умолчанию) — только тем, у кого ещё нет пароля
            // mode = 'all' — всем подряд (затирая существующие пароли)
            $password = $body['password'] ?? '';
            $mode = ($body['mode'] ?? 'missing') === 'all' ? 'all' : 'missing';
            if (!$password) roRespond(['error' => 'Не указан пароль'], 400);

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $restsWhere = ["active = 1"];
            $restsParams = [];
            roApplyAllowedGroupsSql($sessionUser, $restsWhere, $restsParams, 'legal_entity_group');
            $restsSql = "SELECT number, legal_entity_group FROM restaurants WHERE " . implode(' AND ', $restsWhere) . " ORDER BY legal_entity_group, number";
            $rests = $pdo->prepare($restsSql);
            $rests->execute($restsParams);
            $changed = 0;
            $insert = $pdo->prepare("INSERT INTO ro_users (restaurant_number, legal_entity_group, password_hash, legal_entity) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), legal_entity = VALUES(legal_entity), is_active = 1");
            $check = $pdo->prepare("SELECT password_hash FROM ro_users WHERE restaurant_number = ? AND legal_entity_group = ?");
            foreach ($rests->fetchAll() as $r) {
                if ($mode === 'missing') {
                    $check->execute([$r['number'], $r['legal_entity_group']]);
                    $existing = $check->fetchColumn();
                    if ($existing) continue;
                }
                $le = roGetLegalEntity($pdo, $r['number'], $r['legal_entity_group']);
                $insert->execute([$r['number'], $r['legal_entity_group'], $hash, $le]);
                $changed++;
            }
            roRespond(['success' => true, 'created' => $changed, 'mode' => $mode]);
        }

        if ($action === 'toggle') {
            $restNum = (int)($body['restaurant_number'] ?? 0);
            $restGroup = roNormalizeLegalEntityGroup($body['legal_entity_group'] ?? null, $restNum);
            $active = (int)($body['is_active'] ?? 1);
            roEnsureRestaurantAccess($pdo, $sessionUser, $restNum, $restGroup);
            $pdo->prepare("UPDATE ro_users SET is_active = ? WHERE restaurant_number = ? AND legal_entity_group = ?")->execute([$active, $restNum, $restGroup]);
            roRespond(['success' => true]);
        }

        if ($action === 'reset-password') {
            $restNum = (int)($body['restaurant_number'] ?? 0);
            $restGroup = roNormalizeLegalEntityGroup($body['legal_entity_group'] ?? null, $restNum);
            $password = $body['password'] ?? '';
            if (!$restNum || !$password) roRespond(['error' => 'Не указан номер или пароль'], 400);
            roEnsureRestaurantAccess($pdo, $sessionUser, $restNum, $restGroup);
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE ro_users SET password_hash = ? WHERE restaurant_number = ? AND legal_entity_group = ?")->execute([$hash, $restNum, $restGroup]);
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
        roApplyAllowedGroupsSql($sessionUser, $where, $params, "(CASE WHEN o.legal_entity LIKE '%Пицца Стар%' THEN 'PS' ELSE 'BK_VM' END)");

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
                LEFT JOIN restaurants r ON r.number = o.restaurant_number AND r.active = 1 AND r.legal_entity_group = (CASE WHEN o.legal_entity LIKE '%Пицца%' THEN 'PS' ELSE 'BK_VM' END)
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
            $st = $pdo->prepare("SELECT oi.id, oi.order_id, oi.sku, oi.product_name, oi.category, oi.quantity, oi.comment,
                       (SELECT pp.price FROM product_prices pp JOIN ro_orders o2 ON o2.id = oi.order_id
                           WHERE pp.sku = oi.sku AND pp.legal_entity = o2.legal_entity AND pp.price_type = 'deposit'
                           ORDER BY pp.updated_at DESC LIMIT 1) AS deposit_price
                FROM ro_order_items oi WHERE oi.order_id IN ({$ph}){$catWhere} ORDER BY oi.category, oi.product_name");
            $st->execute($catParams);
            $items = $st->fetchAll();
        }

        // Список ресторанов для фильтра
        $restWhere = ["o.status != 'draft'"];
        $restParams = [];
        roApplyAllowedGroupsSql($sessionUser, $restWhere, $restParams, "(CASE WHEN o.legal_entity LIKE '%Пицца Стар%' THEN 'PS' ELSE 'BK_VM' END)");
        $restSql = "SELECT DISTINCT o.restaurant_number FROM ro_orders o WHERE " . implode(' AND ', $restWhere) . " ORDER BY o.restaurant_number";
        $restStmt = $pdo->prepare($restSql);
        $restStmt->execute($restParams);
        $restList = $restStmt->fetchAll(PDO::FETCH_COLUMN);

        // Список сессий
        if (roSessionsSupportGroups($pdo)) {
            $sessionsWhere = [];
            $sessionsParams = [];
            roApplyAllowedGroupsSql($sessionUser, $sessionsWhere, $sessionsParams, 'legal_entity_group');
            $sessionsSql = "SELECT id, week_start, week_end, status, legal_entity_group FROM ro_sessions";
            if (!empty($sessionsWhere)) $sessionsSql .= " WHERE " . implode(' AND ', $sessionsWhere);
            $sessionsSql .= " ORDER BY id DESC LIMIT 20";
            $sessStmt = $pdo->prepare($sessionsSql);
            $sessStmt->execute($sessionsParams);
            $sessions = $sessStmt->fetchAll();
        } else {
            $sessions = $pdo->query("SELECT id, week_start, week_end, status, 'BK_VM' AS legal_entity_group FROM ro_sessions ORDER BY id DESC LIMIT 20")->fetchAll();
        }

        roRespond([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'orders' => $orders,
            'items' => $items,
            'restaurant_list' => $restList,
            'sessions' => $sessions,
        ]);
    }

    // --- Выгрузка заказов ---
    if ($adminAction === 'export' && $method === 'GET') {
        $format = $adminParam ?? 'summary'; // summary, per-restaurant, all, ctt-json
        $date = $_GET['date'] ?? date('Y-m-d', strtotime('+1 day'));
        $legalEntity = $_GET['legal_entity'] ?? null;
        $entityGroup = $legalEntity ? getEntityGroup($legalEntity) : null;
        if ($entityGroup) {
            roEnsureGroupAccess($sessionUser, $entityGroup);
        } else {
            $allowedGroups = roGetSessionUserGroups($sessionUser);
            $entityGroup = $allowedGroups[0] ?? 'BK_VM';
            roEnsureGroupAccess($sessionUser, $entityGroup);
        }
        $session = roGetActiveSession($pdo, $entityGroup);
        if (!$session) roRespond(['error' => 'Нет активной сессии'], 400);

        // Получаем все заказы на дату
        $dow = (int)(new DateTime($date))->format('N');
        $ordersSql = "
            SELECT o.id, o.restaurant_number, o.status, o.submitted_at, o.legal_entity,
                   r.region, r.city, r.address,
                   ds.delivery_time
            FROM ro_orders o
            LEFT JOIN restaurants r ON r.number = o.restaurant_number AND r.active = 1 AND r.legal_entity_group = (CASE WHEN o.legal_entity LIKE '%Пицца%' THEN 'PS' ELSE 'BK_VM' END)
            LEFT JOIN delivery_schedule ds ON ds.restaurant_id = r.id AND ds.day_of_week = ?
            WHERE o.session_id = ? AND o.delivery_date = ? AND o.status != 'draft'
        ";
        $ordersParams = [$dow, $session['id'], $date];
        if ($sessionUser && ($sessionUser['role'] ?? '') !== 'admin') {
            $allowedGroups = roGetSessionUserGroups($sessionUser);
            if (empty($allowedGroups)) roRespond(['error' => 'Нет доступа к данным этого юрлица'], 403);
            $ph = implode(',', array_fill(0, count($allowedGroups), '?'));
            $ordersSql .= " AND (CASE WHEN o.legal_entity LIKE '%Пицца Стар%' THEN 'PS' ELSE 'BK_VM' END) IN ({$ph})";
            $ordersParams = array_merge($ordersParams, $allowedGroups);
        }
        $ordersSql .= " ORDER BY o.restaurant_number";
        $orders = $pdo->prepare($ordersSql);
        $orders->execute($ordersParams);
        $ordersList = $orders->fetchAll();

        // Все позиции
        $orderIds = array_column($ordersList, 'id');
        $allItems = [];
        if (!empty($orderIds)) {
            $ph = implode(',', array_fill(0, count($orderIds), '?'));
            $items = $pdo->prepare("SELECT oi.*, o.restaurant_number, p.weight_netto, p.weight_brutto, p.external_code, p.gtin, p.boxes_per_pallet, COALESCE(p.multiplicity, 1) as multiplicity, COALESCE(p.is_traceable, 0) as is_traceable,
                       (SELECT pp.price FROM product_prices pp WHERE pp.sku = oi.sku AND pp.legal_entity = o.legal_entity AND pp.price_type = 'deposit' ORDER BY pp.updated_at DESC LIMIT 1) AS deposit_price,
                       (SELECT pp.price FROM product_prices pp WHERE pp.sku = oi.sku AND pp.legal_entity = o.legal_entity AND pp.price_type = 'purchase' ORDER BY pp.updated_at DESC LIMIT 1) AS purchase_price
                FROM ro_order_items oi
                JOIN ro_orders o ON o.id = oi.order_id
                LEFT JOIN products p ON p.sku = oi.sku AND p.legal_entity = o.legal_entity AND p.is_active = 1
                WHERE oi.order_id IN ({$ph}) AND oi.quantity > 0 ORDER BY oi.category, oi.product_name");
            $items->execute($orderIds);
            $allItems = $items->fetchAll();
        }

        if ($format === 'ctt-json') {
            $cttPrefix = roGetCttPrefixByGroup($entityGroup);
            $ordersById = [];
            foreach ($ordersList as $order) {
                $ordersById[(int)$order['id']] = $order;
            }

            $cttItems = [];
            $skippedMissingGtin = 0;
            $missingPurchasePrice = 0;
            foreach ($allItems as $item) {
                $gtin = trim((string)($item['gtin'] ?? ''));
                if ($gtin === '') {
                    $skippedMissingGtin++;
                    continue;
                }
                $order = $ordersById[(int)$item['order_id']] ?? null;
                if (!$order) continue;
                $restaurantNumber = (int)$order['restaurant_number'];
                $price = round((float)($item['purchase_price'] ?? 0), 2);
                if ($price <= 0) {
                    $missingPurchasePrice++;
                }
                $cttItems[] = [
                    'o' => $cttPrefix . '-' . $restaurantNumber,
                    'r' => roFormatCttRestaurantLabel($restaurantNumber, $order['city'] ?? '', $order['address'] ?? ''),
                    's' => trim((string)($item['category'] ?? '')),
                    'g' => $gtin,
                    'n' => trim((string)($item['product_name'] ?? '')),
                    'q' => (string)(0 + (float)($item['quantity'] ?? 0)),
                    'w' => roFormatCttWeight($item['weight_brutto'] ?? 0),
                    'p' => $price,
                ];
            }

            usort($cttItems, static function ($a, $b) {
                return [$a['o'], $a['s'], $a['n']] <=> [$b['o'], $b['s'], $b['n']];
            });

            roRespond([
                'date' => $date,
                'format' => $format,
                'filename' => 'data-' . strtolower($cttPrefix) . '-' . $date . '.json',
                'items' => $cttItems,
                'skipped_missing_gtin' => $skippedMissingGtin,
                'missing_purchase_price' => $missingPurchasePrice,
            ]);
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
        if ($le) roEnsureGroupAccess($sessionUser, getEntityGroup($le));
        if (!$search || strlen($search) < 2 || !$le) roRespond(['products' => []]);
        $like = "%{$search}%";
        $s = $pdo->prepare("SELECT sku, name, category, qty_per_box, multiplicity FROM products WHERE legal_entity = ? AND is_active = 1 AND (name LIKE ? OR sku LIKE ?) ORDER BY name LIMIT 50");
        $s->execute([$le, $like, $like]);
        roRespond(['products' => $s->fetchAll()]);
    }

    // --- Список всех сессий ---
    if ($adminAction === 'sessions' && $method === 'GET') {
        if (roSessionsSupportGroups($pdo)) {
            $where = [];
            $params = [];
            roApplyAllowedGroupsSql($sessionUser, $where, $params, 'legal_entity_group');
            $sql = "SELECT * FROM ro_sessions";
            if (!empty($where)) $sql .= " WHERE " . implode(' AND ', $where);
            $sql .= " ORDER BY week_start DESC LIMIT 20";
            $s = $pdo->prepare($sql);
            $s->execute($params);
            roRespond(['sessions' => $s->fetchAll()]);
        }
        roRespond(['sessions' => $pdo->query("SELECT *, 'BK_VM' AS legal_entity_group FROM ro_sessions ORDER BY week_start DESC LIMIT 20")->fetchAll()]);
    }

    // ═══ Журнал изменений (общий + по заказу) ═══

    // --- Общий журнал с фильтрами ---
    if ($adminAction === 'audit' && $method === 'GET' && !$adminParam) {
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;
        $restaurant = $_GET['restaurant'] ?? '';
        $actor = $_GET['actor'] ?? '';
        $action = $_GET['action'] ?? '';
        $search = trim($_GET['search'] ?? '');
        $legalEntity = $_GET['legal_entity'] ?? null;
        $entityGroup = $legalEntity ? getEntityGroup($legalEntity) : null;
        $limit = min((int)($_GET['limit'] ?? 200), 1000);
        $offset = max((int)($_GET['offset'] ?? 0), 0);
        if ($entityGroup) {
            roEnsureGroupAccess($sessionUser, $entityGroup);
        }

        $where = ['1=1'];
        $params = [];
        // Фильтр по дате поставки — то, что обычно ищут («все события по заказам на дату X»).
        if ($dateFrom) { $where[] = 'al.delivery_date >= ?'; $params[] = $dateFrom; }
        if ($dateTo)   { $where[] = 'al.delivery_date <= ?'; $params[] = $dateTo; }
        if ($restaurant !== '') { $where[] = 'al.restaurant_number = ?'; $params[] = (int)$restaurant; }
        if ($actor !== '')      { $where[] = 'al.actor_name LIKE ?';     $params[] = '%' . $actor . '%'; }
        if ($action !== '')     { $where[] = 'al.action = ?';            $params[] = $action; }
        if ($search !== '')     {
            $where[] = '(al.sku LIKE ? OR al.product_name LIKE ? OR al.old_value LIKE ? OR al.new_value LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }
        // Фильтр по группе юрлиц: событие относится либо к заказу этой группы,
        // либо к ресторану этой группы (для событий без order_id).
        if ($entityGroup) {
            $where[] = "((o.legal_entity IS NOT NULL AND (CASE WHEN o.legal_entity LIKE '%Пицца Стар%' THEN 'PS' ELSE 'BK_VM' END) = ?) OR (al.order_id IS NULL AND r.legal_entity_group = ?))";
            $params[] = $entityGroup;
            $params[] = $entityGroup;
        } elseif ($sessionUser && ($sessionUser['role'] ?? '') !== 'admin') {
            $allowedGroups = roGetSessionUserGroups($sessionUser);
            if (empty($allowedGroups)) {
                $where[] = '1=0';
            } else {
                $ph = implode(',', array_fill(0, count($allowedGroups), '?'));
                $where[] = "(
                    (o.legal_entity IS NOT NULL AND (CASE WHEN o.legal_entity LIKE '%Пицца Стар%' THEN 'PS' ELSE 'BK_VM' END) IN ({$ph}))
                    OR
                    (al.order_id IS NULL AND r.legal_entity_group IN ({$ph}))
                )";
                foreach ($allowedGroups as $group) $params[] = $group;
                foreach ($allowedGroups as $group) $params[] = $group;
            }
        }

        $sql = "SELECT al.id, al.order_id, al.restaurant_number, al.delivery_date, al.action, al.actor_name, al.actor_type,
                       al.sku, al.product_name, al.old_value, al.new_value, al.details, al.created_at
                FROM ro_audit_log al
                LEFT JOIN ro_orders o ON o.id = al.order_id
                LEFT JOIN restaurants r ON r.number = al.restaurant_number AND r.active = 1
                WHERE " . implode(' AND ', $where) . "
                ORDER BY al.created_at DESC, al.id DESC
                LIMIT {$limit} OFFSET {$offset}";
        $s = $pdo->prepare($sql);
        $s->execute($params);
        $rows = $s->fetchAll();

        // Total count для пагинации
        $countSql = "SELECT COUNT(*) FROM ro_audit_log al
                     LEFT JOIN ro_orders o ON o.id = al.order_id
                     LEFT JOIN restaurants r ON r.number = al.restaurant_number AND r.active = 1
                     WHERE " . implode(' AND ', $where);
        $cs = $pdo->prepare($countSql);
        $cs->execute($params);
        $total = (int)$cs->fetchColumn();

        roRespond(['events' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
    }

    // --- История одного заказа (по order_id или по restaurant+date) ---
    if ($adminAction === 'audit' && $method === 'GET' && $adminParam) {
        $orderId = (int)$adminParam;
        // Пытаемся взять restaurant_number + delivery_date этого заказа
        // (если он ещё существует), чтобы подтянуть события с null-order_id от удаления
        $meta = $pdo->prepare("SELECT restaurant_number, delivery_date, legal_entity FROM ro_orders WHERE id = ?");
        $meta->execute([$orderId]);
        $m = $meta->fetch();
        if ($m && $sessionUser && !checkLegalEntityAccess($sessionUser, $m['legal_entity'] ?? '')) {
            roRespond(['error' => 'Нет доступа к этому заказу'], 403);
        } elseif (!$m && $sessionUser && ($sessionUser['role'] ?? '') !== 'admin') {
            $auditMeta = $pdo->prepare("SELECT restaurant_number FROM ro_audit_log WHERE order_id = ? ORDER BY id DESC LIMIT 1");
            $auditMeta->execute([$orderId]);
            $auditRestaurant = $auditMeta->fetchColumn();
            if ($auditRestaurant) {
                roEnsureRestaurantAccess($pdo, $sessionUser, $auditRestaurant);
            }
        }

        $sql = "SELECT id, order_id, restaurant_number, delivery_date, action, actor_name, actor_type,
                       sku, product_name, old_value, new_value, details, created_at
                FROM ro_audit_log
                WHERE order_id = ?";
        $params = [$orderId];
        if ($m) {
            $sql .= " OR (restaurant_number = ? AND delivery_date = ?)";
            $params[] = $m['restaurant_number'];
            $params[] = $m['delivery_date'];
        }
        $sql .= " ORDER BY created_at DESC, id DESC LIMIT 500";
        $s = $pdo->prepare($sql);
        $s->execute($params);
        roRespond(['events' => $s->fetchAll()]);
    }

    // ═══ Остатки склада ═══

    // --- Загрузка остатков из Excel ---
    if ($adminAction === 'stock-upload' && $method === 'POST') {
        if (empty($_FILES['file'])) roRespond(['error' => 'Файл не загружен'], 400);
        $balanceDate = $_POST['balance_date'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $balanceDate)) roRespond(['error' => 'Неверный формат даты'], 400);
        $allowedEntities = roGetAllowedLegalEntities($sessionUser);
        $isFullAdmin = !$sessionUser || (($sessionUser['role'] ?? '') === 'admin');
        if (!$isFullAdmin && empty($allowedEntities)) {
            roRespond(['error' => 'Нет доступа к загрузке остатков'], 403);
        }

        require_once __DIR__ . '/../lib/SimpleXLSX.php';
        $filePath = $_FILES['file']['tmp_name'];
        $xlsx = \Shuchkin\SimpleXLSX::parse($filePath);
        if (!$xlsx) roRespond(['error' => 'Не удалось прочитать Excel файл'], 400);

        // Загружаем все товары из БД для сопоставления (включая неактивные/«невидимые» карточки).
        // ORDER BY is_active DESC: активные идут первыми и занимают ключ, неактивные заполняют
        // только те ключи, которых у активных нет — это исключает перетирание активных карточек.
        $productsStmt = $pdo->query("SELECT sku, external_code, name FROM products ORDER BY is_active DESC, id ASC");
        $productsBySku = [];
        $productsByExtCode = [];
        foreach ($productsStmt->fetchAll() as $p) {
            $sku = trim($p['sku']);
            if ($sku !== '' && !isset($productsBySku[$sku])) {
                $productsBySku[$sku] = $p;
            }
            $ext = trim($p['external_code'] ?? '');
            if ($ext !== '' && !isset($productsByExtCode[$ext])) {
                $productsByExtCode[$ext] = $p;
            }
        }

        $matched = 0;
        $skipped = 0;
        $rows = [];
        $unmatchedMap = []; // ключ: extCode|sku → ['external_code','sku','name','qty','warehouse','legal_entity']

        foreach ($xlsx->sheetNames() as $sheetIdx => $sheetName) {
            // Определяем тип склада (пропускаем листы с примерами заказов)
            if (mb_stripos($sheetName, 'Пример') !== false) continue;
            $warehouse = '';
            if (mb_stripos($sheetName, 'П6') !== false) $warehouse = 'Сухой';
            elseif (mb_stripos($sheetName, 'П1') !== false) $warehouse = 'Холод+Мороз';
            else continue;

            $sheetRows = $xlsx->rows($sheetIdx);
            if (empty($sheetRows)) continue;

            // Ищем заголовок
            $headerRow = -1;
            $colProduct = -1;
            $colOwner = -1;
            $colQty = -1;
            for ($r = 0; $r < min(10, count($sheetRows)); $r++) {
                foreach ($sheetRows[$r] as $c => $val) {
                    $v = mb_strtolower(trim((string)$val));
                    if ($v === '') continue;
                    // «Товар» (точное совпадение — не путать с «Владелец товара»)
                    if ($v === 'товар' || $v === 'номенклатура' || $v === 'наименование') $colProduct = $c;
                    if (mb_strpos($v, 'владелец') !== false) $colOwner = $c;
                    // Колонка количества: «Итог», «Итого», «Кол-во», «Количество», «Кол-во штук», «Остаток»
                    if (preg_match('/^итог|^кол[-\s]?во|^количеств|^остаток|штук/u', $v)) $colQty = $c;
                }
                if ($colProduct >= 0 && $colQty >= 0) { $headerRow = $r; break; }
            }
            if ($headerRow < 0) continue;

            for ($r = $headerRow + 1; $r < count($sheetRows); $r++) {
                $productStr = trim((string)($sheetRows[$r][$colProduct] ?? ''));
                $qty = (float)($sheetRows[$r][$colQty] ?? 0);
                if (!$productStr || $qty <= 0) continue;

                // Определяем юрлицо по владельцу
                $ownerStr = mb_strtolower(trim((string)($sheetRows[$r][$colOwner] ?? '')));
                $legalEntity = '';
                if (mb_strpos($ownerStr, 'воглия') !== false) {
                    $legalEntity = 'ООО "Воглия Матта"';
                } elseif (mb_strpos($ownerStr, 'бургер') !== false) {
                    $legalEntity = 'ООО "Бургер БК"';
                } elseif (mb_strpos($ownerStr, 'пицца стар') !== false || mb_strpos($ownerStr, 'додо') !== false) {
                    $legalEntity = 'ООО "Пицца Стар"';
                } else {
                    continue; // пропускаем ДоДо, Сбарро и т.д.
                }
                if (!$isFullAdmin && !in_array($legalEntity, $allowedEntities, true)) {
                    continue;
                }

                // Парсим: "внешний_код - SKU Название"
                if (!preg_match('/^(\S+)\s*-\s*(\S+)\s+(.+)$/', $productStr, $m)) continue;
                $extCode = trim($m[1]);
                $sku = trim($m[2]);
                $excelName = trim($m[3]);

                // Сопоставляем: сначала по SKU, потом по внешнему коду
                $foundProduct = $productsBySku[$sku] ?? $productsByExtCode[$extCode] ?? null;
                if ($foundProduct) {
                    $fSku = $foundProduct['sku'];
                    $key = $fSku . '|' . $legalEntity;
                    if (isset($rows[$key])) {
                        $rows[$key][2] += $qty;
                    } else {
                        $rows[$key] = [$fSku, $foundProduct['name'], $qty, $warehouse, $legalEntity, $balanceDate];
                    }
                    $matched++;
                } else {
                    $skipped++;
                    $umKey = $extCode . '|' . $sku . '|' . $legalEntity;
                    if (isset($unmatchedMap[$umKey])) {
                        $unmatchedMap[$umKey]['qty'] += $qty;
                    } else {
                        $unmatchedMap[$umKey] = [
                            'external_code' => $extCode,
                            'sku' => $sku,
                            'name' => $excelName,
                            'qty' => $qty,
                            'warehouse' => $warehouse,
                            'legal_entity' => $legalEntity,
                        ];
                    }
                }
            }
        }

        // Вставляем в БД
        if (!empty($rows)) {
            if ($isFullAdmin) {
                $pdo->prepare("DELETE FROM ro_stock_balances WHERE balance_date = ?")->execute([$balanceDate]);
            } else {
                $ph = implode(',', array_fill(0, count($allowedEntities), '?'));
                $deleteParams = array_merge([$balanceDate], $allowedEntities);
                $pdo->prepare("DELETE FROM ro_stock_balances WHERE balance_date = ? AND legal_entity IN ({$ph})")->execute($deleteParams);
            }
            $stmt = $pdo->prepare("INSERT INTO ro_stock_balances (sku, product_name, quantity, warehouse, legal_entity, balance_date) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($rows as $row) {
                $stmt->execute(array_values($row));
            }
        }

        $unmatched = array_values($unmatchedMap);
        usort($unmatched, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        roRespond([
            'success' => true,
            'matched' => $matched,
            'skipped' => $skipped,
            'date' => $balanceDate,
            'unmatched' => $unmatched,
        ]);
    }

    // --- Остатки с учётом заказов ---
    if ($adminAction === 'stock-balances' && $method === 'GET') {
        $balanceDate = $_GET['date'] ?? '';
        $deliveryDate = $_GET['delivery_date'] ?? '';
        $legalEntity = $_GET['legal_entity'] ?? '';
        if (!$balanceDate || !$deliveryDate) roRespond(['error' => 'Не указаны даты'], 400);
        if ($legalEntity) {
            roEnsureGroupAccess($sessionUser, getEntityGroup($legalEntity));
        }

        // Остатки на дату (с фильтром по юрлицу если указано)
        if ($legalEntity) {
            $s = $pdo->prepare("SELECT sku, product_name, quantity, warehouse, legal_entity FROM ro_stock_balances WHERE balance_date = ? AND legal_entity = ? ORDER BY warehouse, product_name");
            $s->execute([$balanceDate, $legalEntity]);
        } else {
            $balanceWhere = ['balance_date = ?'];
            $balanceParams = [$balanceDate];
            if ($sessionUser && ($sessionUser['role'] ?? '') !== 'admin') {
                $allowedEntities = roGetAllowedLegalEntities($sessionUser);
                if (empty($allowedEntities)) {
                    roRespond(['items' => [], 'balance_date' => $balanceDate, 'delivery_date' => $deliveryDate]);
                }
                $ph = implode(',', array_fill(0, count($allowedEntities), '?'));
                $balanceWhere[] = "legal_entity IN ({$ph})";
                foreach ($allowedEntities as $entity) $balanceParams[] = $entity;
            }
            $sql = "SELECT sku, product_name, quantity, warehouse, legal_entity
                    FROM ro_stock_balances
                    WHERE " . implode(' AND ', $balanceWhere) . "
                    ORDER BY legal_entity, warehouse, product_name";
            $s = $pdo->prepare($sql);
            $s->execute($balanceParams);
        }
        $balances = $s->fetchAll();

        // Карта поставщиков: sku|legal_entity -> supplier (активные карточки приоритетнее)
        $supplierMap = [];
        $supplierBySku = [];
        $balanceSkus = array_values(array_unique(array_column($balances, 'sku')));
        if (!empty($balanceSkus)) {
            $ph = implode(',', array_fill(0, count($balanceSkus), '?'));
            $qs = $pdo->prepare("SELECT sku, supplier, legal_entity FROM products WHERE sku IN ($ph) ORDER BY is_active DESC, id ASC");
            $qs->execute($balanceSkus);
            foreach ($qs->fetchAll() as $row) {
                $mk = $row['sku'] . '|' . $row['legal_entity'];
                if (!isset($supplierMap[$mk]) && !empty($row['supplier'])) {
                    $supplierMap[$mk] = $row['supplier'];
                }
                if (!isset($supplierBySku[$row['sku']]) && !empty($row['supplier'])) {
                    $supplierBySku[$row['sku']] = $row['supplier'];
                }
            }
        }

        // Суммарные заказы от даты остатков+1 до выбранной даты, с разбивкой по реальному юрлицу заказа.
        $ordersWhere = [
            'o.delivery_date > ?',
            'o.delivery_date <= ?',
            "o.status IN ('submitted','edited','locked')",
        ];
        $ordersParams = [$balanceDate, $deliveryDate];
        if ($legalEntity) {
            $ordersWhere[] = 'o.legal_entity = ?';
            $ordersParams[] = $legalEntity;
        } elseif ($sessionUser && ($sessionUser['role'] ?? '') !== 'admin') {
            $allowedEntities = roGetAllowedLegalEntities($sessionUser);
            if (empty($allowedEntities)) {
                roRespond(['items' => [], 'balance_date' => $balanceDate, 'delivery_date' => $deliveryDate]);
            }
            $ph = implode(',', array_fill(0, count($allowedEntities), '?'));
            $ordersWhere[] = "o.legal_entity IN ({$ph})";
            foreach ($allowedEntities as $entity) $ordersParams[] = $entity;
        }
        $s2 = $pdo->prepare("
            SELECT oi.sku,
                   o.legal_entity AS real_legal_entity,
                   SUM(oi.quantity) as total_ordered
            FROM ro_order_items oi
            JOIN ro_orders o ON o.id = oi.order_id
            WHERE " . implode(' AND ', $ordersWhere) . "
            GROUP BY oi.sku, real_legal_entity
        ");
        $s2->execute($ordersParams);
        $orders = [];
        foreach ($s2->fetchAll() as $row) {
            $orders[$row['sku'] . '|' . $row['real_legal_entity']] = (float)$row['total_ordered'];
        }

        $items = [];
        $seenSkus = [];
        foreach ($balances as $b) {
            $stockQty = (float)$b['quantity'];
            $le = $b['legal_entity'];
            $key = $b['sku'] . '|' . $le;
            $orderedQty = $orders[$key] ?? 0;
            $seenSkus[$key] = true;
            $supplier = $supplierMap[$key] ?? $supplierBySku[$b['sku']] ?? '';
            $items[] = [
                'sku' => $b['sku'],
                'product_name' => $b['product_name'],
                'supplier' => $supplier,
                'warehouse' => $b['warehouse'],
                'legal_entity' => $le,
                'stock_qty' => $stockQty,
                'ordered_qty' => $orderedQty,
                'remaining' => $stockQty - $orderedQty,
            ];
        }

        // Товары, которые заказаны, но которых нет в остатках
        foreach ($orders as $key => $orderedQty) {
            if (isset($seenSkus[$key])) continue;
            list($sku, $le) = explode('|', $key);
            if ($legalEntity && $le !== $legalEntity) continue;
            // Получаем название товара из БД (активная карточка приоритетнее)
            $ps = $pdo->prepare("SELECT name, category, supplier FROM products WHERE sku = ? ORDER BY is_active DESC, id ASC LIMIT 1");
            $ps->execute([$sku]);
            $prod = $ps->fetch();
            $prodName = $prod ? $prod['name'] : $sku;
            $warehouse = '';
            if ($prod) {
                $cat = $prod['category'] ?? '';
                if ($cat === 'Мороз' || $cat === 'Холод') $warehouse = 'Холод+Мороз';
                else $warehouse = 'Сухой';
            }
            $items[] = [
                'sku' => $sku,
                'product_name' => $prodName,
                'supplier' => $prod['supplier'] ?? '',
                'warehouse' => $warehouse,
                'legal_entity' => $le,
                'stock_qty' => 0,
                'ordered_qty' => $orderedQty,
                'remaining' => -$orderedQty,
            ];
        }

        roRespond(['items' => $items, 'balance_date' => $balanceDate, 'delivery_date' => $deliveryDate]);
    }

    // --- Доступные даты остатков ---
    if ($adminAction === 'stock-dates' && $method === 'GET') {
        $s = $pdo->query("SELECT DISTINCT balance_date FROM ro_stock_balances ORDER BY balance_date DESC LIMIT 30");
        $dates = array_column($s->fetchAll(), 'balance_date');
        roRespond(['dates' => $dates]);
    }

    roRespond(['error' => 'Not found'], 404);
}

// Если дошли сюда — неизвестный маршрут
roRespond(['error' => 'Not found'], 404);
