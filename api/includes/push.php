<?php
/**
 * Web Push API: подписка, отписка, публичный VAPID-ключ.
 *
 * Маршруты:
 *   GET    push/key         — публичный VAPID-ключ для подписки в браузере
 *   POST   push/subscribe   — сохранить subscription из браузера
 *                              { endpoint, keys: { p256dh, auth }, user_agent? }
 *   POST   push/unsubscribe — удалить подписку по endpoint
 *                              { endpoint }
 *
 * Привязка подписки:
 *   - если запрос от ресторана (X-RO-Token) → пишем restaurant_number + legal_entity_group
 *   - если запрос от пользователя системы (сессия) → пишем user_id
 *   - анонимная подписка не сохраняется (для безопасности)
 */

if ($endpoint !== 'push') return;

function pushRespond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($subpoint === 'key' && $method === 'GET') {
    $pub = $_ENV['VAPID_PUBLIC'] ?? '';
    if (!$pub) pushRespond(['error' => 'VAPID не настроен'], 500);
    pushRespond(['publicKey' => $pub]);
}

if ($subpoint === 'subscribe' && $method === 'POST') {
    $sub = $body['subscription'] ?? $body;
    $endpointUrl = trim((string)($sub['endpoint'] ?? ''));
    $p256dh = trim((string)($sub['keys']['p256dh'] ?? ''));
    $auth = trim((string)($sub['keys']['auth'] ?? ''));
    $userAgent = isset($body['user_agent']) ? substr((string)$body['user_agent'], 0, 250) : null;

    if (!$endpointUrl || !$p256dh || !$auth) {
        pushRespond(['error' => 'Некорректные данные подписки'], 400);
    }
    if (strpos($endpointUrl, 'https://') !== 0) {
        pushRespond(['error' => 'Endpoint должен быть HTTPS'], 400);
    }

    $restaurantNumber = null;
    $legalEntityGroup = null;
    $userId = null;

    // Кто подписывается? Ресторан или пользователь системы?
    $roUser = function_exists('roGetRestaurantSession') ? roGetRestaurantSession($pdo) : null;
    if ($roUser) {
        $restaurantNumber = (int)$roUser['restaurant_number'];
        $legalEntityGroup = $roUser['legal_entity_group'] ?? 'BK_VM';
    } else {
        $sysUser = function_exists('getSessionUser') ? getSessionUser($pdo) : null;
        if ($sysUser) {
            $userId = (int)$sysUser['id'];
        } else {
            pushRespond(['error' => 'Требуется авторизация'], 401);
        }
    }

    // INSERT, но если уже есть такой endpoint — обновим привязку (новая авторизация).
    $stmt = $pdo->prepare("
        INSERT INTO push_subscriptions
            (restaurant_number, legal_entity_group, user_id, endpoint, p256dh, auth, user_agent, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            restaurant_number = VALUES(restaurant_number),
            legal_entity_group = VALUES(legal_entity_group),
            user_id = VALUES(user_id),
            p256dh = VALUES(p256dh),
            auth = VALUES(auth),
            user_agent = VALUES(user_agent),
            last_used_at = NULL
    ");
    $stmt->execute([$restaurantNumber, $legalEntityGroup, $userId, $endpointUrl, $p256dh, $auth, $userAgent]);

    pushRespond(['success' => true]);
}

if ($subpoint === 'unsubscribe' && $method === 'POST') {
    $endpointUrl = trim((string)($body['endpoint'] ?? ''));
    if (!$endpointUrl) pushRespond(['error' => 'endpoint обязателен'], 400);

    $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?")
        ->execute([$endpointUrl]);
    pushRespond(['success' => true]);
}

pushRespond(['error' => 'Метод не найден'], 404);
