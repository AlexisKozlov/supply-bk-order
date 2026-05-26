<?php
/**
 * RPC: уведомления и широковещательные рассылки (broadcast).
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName.
 */

if ($fn === 'mark_notifications_read') {
    $ids = $body['ids'] ?? [];
    $user = $authUserName;
    if (!$user || empty($ids)) respond(['success' => false, 'error' => 'Не все параметры указаны']);
    $ids = array_slice($ids, 0, 100); // Лимит на количество ID
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("UPDATE notifications SET read_by = JSON_ARRAY_APPEND(COALESCE(read_by, '[]'), '$', ?) WHERE id IN ($ph) AND (target_user IS NULL OR target_user = '' OR target_user = ? OR type = 'broadcast') AND NOT JSON_CONTAINS(COALESCE(read_by, '[]'), JSON_QUOTE(?))")->execute(array_merge([$user], $ids, [$user, $user]));
    respond(['success' => true]);
}

if ($fn === 'send_broadcast') {
    $sessionUser = getSessionUser($pdo);
    if (!$sessionUser || $sessionUser['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
    $userName = $sessionUser['name'];
    $title = $body['title'] ?? 'Важное сообщение';
    $message = $body['message'] ?? '';
    $toStaffCabinet = !empty($body['to_staff_cabinet']);
    $toRestaurantCabinet = !empty($body['to_restaurants_cabinet']);
    $toStaffTelegram = !empty($body['to_staff_telegram']);
    $toRestaurantTelegram = !empty($body['to_restaurants_telegram']);
    if (!$message) respond(['success' => false, 'error' => 'Не все параметры указаны'], 400);
    if (!$toStaffCabinet && !$toRestaurantCabinet && !$toStaffTelegram && !$toRestaurantTelegram) {
        respond(['success' => false, 'error' => 'Не выбраны получатели'], 400);
    }

    $title = mb_substr($title, 0, 255);
    $message = mb_substr($message, 0, 2000);
    $broadcastGroup = uniqid('bc_', true);

    if ($toStaffCabinet) {
        $pdo->prepare("INSERT INTO notifications (type, title, message, created_by, broadcast_group, read_by, deleted_by, created_at) VALUES ('broadcast', ?, ?, ?, ?, '[]', '[]', NOW())")
            ->execute([$title, $message, $userName, $broadcastGroup]);
    }
    if ($toRestaurantCabinet) {
        $pdo->prepare("INSERT INTO notifications (type, title, message, created_by, broadcast_group, read_by, deleted_by, created_at) VALUES ('ro_broadcast', ?, ?, ?, ?, '[]', '[]', NOW())")
            ->execute([$title, $message, $userName, $broadcastGroup]);
    }

    $staffTelegramSent = 0;
    $restaurantTelegramSent = 0;
    $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
    if ($botToken) {
        $tgText = "📢 <b>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</b>\n\n" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "\n\n— " . htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
        if ($toStaffTelegram) {
            $s = $pdo->query("SELECT telegram_chat_id FROM users WHERE telegram_chat_id IS NOT NULL AND telegram_chat_id != ''");
            $chatIds = array_values(array_unique(array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN))));
            $staffTelegramSent = sendTelegramBulk($botToken, $chatIds, $tgText);
        }
        if ($toRestaurantTelegram) {
            $s = $pdo->query("
                SELECT telegram_chat_id AS chat_id
                FROM ro_users
                WHERE telegram_chat_id IS NOT NULL AND telegram_chat_id != ''
                UNION
                SELECT chat_id
                FROM ro_telegram_subs
                WHERE chat_id IS NOT NULL AND chat_id != ''
            ");
            $chatIds = array_values(array_unique(array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN))));
            $restaurantTelegramSent = sendTelegramBulk($botToken, $chatIds, $tgText);
        }
    }

    try {
        $pdo->prepare("
            INSERT INTO admin_broadcast_log
                (broadcast_group, sender, title, message, target_staff_cabinet, target_restaurant_cabinet, target_staff_telegram, target_restaurant_telegram, staff_telegram_sent, restaurant_telegram_sent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            $broadcastGroup,
            $userName,
            $title,
            $message,
            $toStaffCabinet ? 1 : 0,
            $toRestaurantCabinet ? 1 : 0,
            $toStaffTelegram ? 1 : 0,
            $toRestaurantTelegram ? 1 : 0,
            $staffTelegramSent,
            $restaurantTelegramSent,
        ]);
    } catch (Exception $e) {
        error_log('send_broadcast log error: ' . $e->getMessage());
    }

    auditLog($pdo, 'broadcast_sent', 'system', $broadcastGroup, $sessionUser['name'], [
        'title' => $title,
        'to_staff_cabinet' => $toStaffCabinet,
        'to_restaurants_cabinet' => $toRestaurantCabinet,
        'to_staff_telegram' => $toStaffTelegram,
        'to_restaurants_telegram' => $toRestaurantTelegram,
        'staff_telegram_sent' => $staffTelegramSent,
        'restaurant_telegram_sent' => $restaurantTelegramSent,
    ]);
    respond([
        'success' => true,
        'broadcast_group' => $broadcastGroup,
        'staff_telegram_sent' => $staffTelegramSent,
        'restaurant_telegram_sent' => $restaurantTelegramSent,
    ]);
}
if ($fn === 'delete_notification_for_user') {
    $id = $body['id'] ?? null;
    $userName = $authUserName;
    if (!$id || !$userName) respond(['success' => false, 'error' => 'Не все параметры указаны'], 400);
    $pdo->prepare("UPDATE notifications SET deleted_by = JSON_ARRAY_APPEND(COALESCE(deleted_by, '[]'), '$', ?) WHERE id = ? AND (target_user IS NULL OR target_user = '' OR target_user = ? OR type = 'broadcast') AND NOT JSON_CONTAINS(COALESCE(deleted_by, '[]'), JSON_QUOTE(?))")->execute([$userName, $id, $userName, $userName]);
    respond(['success' => true]);
}
if ($fn === 'delete_all_notifications_for_user') {
    $userName = $authUserName;
    if (!$userName) respond(['success' => false, 'error' => 'Не все параметры указаны'], 400);
    $pdo->prepare("UPDATE notifications SET deleted_by = JSON_ARRAY_APPEND(COALESCE(deleted_by, '[]'), '$', ?) WHERE (target_user IS NULL OR target_user = '' OR target_user = ? OR type = 'broadcast') AND NOT JSON_CONTAINS(COALESCE(deleted_by, '[]'), JSON_QUOTE(?))")->execute([$userName, $userName, $userName]);
    respond(['success' => true]);
}
if ($fn === 'get_active_broadcasts') {
    $userName = $authUserName;
    if (!$userName) respond([]);
    // Не показывать уведомления, отправленные до регистрации пользователя
    $su = $pdo->prepare("SELECT created_at FROM users WHERE name=?"); $su->execute([$userName]); $uRow = $su->fetch();
    $userCreated = $uRow['created_at'] ?? null;
    $sql = "SELECT id, title, message, created_by, created_at FROM notifications WHERE type='broadcast' AND created_at > NOW() - INTERVAL 24 HOUR AND NOT JSON_CONTAINS(COALESCE(read_by, '[]'), JSON_QUOTE(?)) AND NOT JSON_CONTAINS(COALESCE(deleted_by, '[]'), JSON_QUOTE(?))";
    $params = [$userName, $userName];
    if ($userCreated) {
        $sql .= " AND created_at > ?";
        $params[] = $userCreated;
    }
    $sql .= " ORDER BY created_at DESC LIMIT 5";
    $s = $pdo->prepare($sql);
    $s->execute($params);
    respond($s->fetchAll());
}
if ($fn === 'delete_broadcast') {
    $sessionUser = getSessionUser($pdo);
    if (!$sessionUser || $sessionUser['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
    $broadcastGroup = trim((string)($body['broadcast_group'] ?? ''));
    $id = $body['id'] ?? null;
    if ($broadcastGroup !== '') {
        $pdo->prepare("DELETE FROM notifications WHERE broadcast_group = ? AND type IN ('broadcast', 'ro_broadcast')")->execute([$broadcastGroup]);
        try {
            $pdo->prepare("DELETE FROM admin_broadcast_log WHERE broadcast_group = ?")->execute([$broadcastGroup]);
        } catch (Exception $e) {
            error_log('delete_broadcast log cleanup error: ' . $e->getMessage());
        }
        respond(['success' => true]);
    }
    if (!$id) respond(['success' => false, 'error' => 'Не указан ID'], 400);
    $pdo->prepare("DELETE FROM notifications WHERE id = ? AND type = 'broadcast'")->execute([$id]);
    respond(['success' => true]);
}
if ($fn === 'get_broadcast_history') {
    $sessionUser = getSessionUser($pdo);
    if (!$sessionUser || $sessionUser['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
    $limit = max(1, min((int)($body['limit'] ?? 20), 100));

    $rows = [];
    try {
        $s = $pdo->prepare("
            SELECT
                id,
                broadcast_group,
                sender,
                title,
                message,
                target_staff_cabinet,
                target_restaurant_cabinet,
                target_staff_telegram,
                target_restaurant_telegram,
                staff_telegram_sent,
                restaurant_telegram_sent,
                created_at,
                0 AS is_legacy
            FROM admin_broadcast_log
            ORDER BY created_at DESC
            LIMIT {$limit}
        ");
        $s->execute();
        $rows = $s->fetchAll();
    } catch (Exception $e) {
        error_log('get_broadcast_history admin_broadcast_log error: ' . $e->getMessage());
    }

    try {
        $legacyLimit = max($limit - count($rows), 0);
        if ($legacyLimit > 0) {
            $s = $pdo->prepare("
                SELECT
                    id,
                    CONCAT('legacy_', id) AS broadcast_group,
                    created_by AS sender,
                    title,
                    message,
                    1 AS target_staff_cabinet,
                    0 AS target_restaurant_cabinet,
                    0 AS target_staff_telegram,
                    0 AS target_restaurant_telegram,
                    0 AS staff_telegram_sent,
                    0 AS restaurant_telegram_sent,
                    created_at,
                    1 AS is_legacy
                FROM notifications
                WHERE type = 'broadcast'
                  AND (broadcast_group IS NULL OR broadcast_group = '')
                ORDER BY created_at DESC
                LIMIT {$legacyLimit}
            ");
            $s->execute();
            $rows = array_merge($rows, $s->fetchAll());
        }
    } catch (Exception $e) {
        error_log('get_broadcast_history legacy error: ' . $e->getMessage());
    }

    usort($rows, static function ($a, $b) {
        return strcmp((string)$b['created_at'], (string)$a['created_at']);
    });
    if (count($rows) > $limit) $rows = array_slice($rows, 0, $limit);
    respond($rows);
}
