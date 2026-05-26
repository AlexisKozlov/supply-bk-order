<?php
/**
 * RPC: онлайн-присутствие (heartbeat, блокировки заказов, списки онлайн)
 * + админская диагностика (статы, сессии, очистка логов).
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName.
 */

if ($fn === 'heartbeat') {
    $userName = $authUserName;
    $page = $body['page'] ?? '';
    $editingOrderId = $body['editing_order_id'] ?? null;
    if ($userName) {
        $s = $pdo->prepare("INSERT INTO user_presence (user_name, page, last_seen, editing_order_id) VALUES (?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE page=VALUES(page), last_seen=NOW(), editing_order_id=VALUES(editing_order_id)");
        $s->execute([$userName, substr($page, 0, 100), $editingOrderId]);
    }
    respond(['success' => true]);
}
if ($fn === 'check_order_lock') {
    $orderId = $body['order_id'] ?? '';
    $userName = $authUserName;
    if (!$orderId) respond(['locked' => false]);
    $s = $pdo->prepare("SELECT user_name FROM user_presence WHERE editing_order_id = ? AND user_name != ? AND last_seen > NOW() - INTERVAL 2 MINUTE LIMIT 1");
    $s->execute([$orderId, $userName]);
    $row = $s->fetch();
    respond($row ? ['locked' => true, 'locked_by' => $row['user_name']] : ['locked' => false]);
}
if ($fn === 'unlock_order') {
    $userName = $authUserName;
    if ($userName) {
        $pdo->prepare("UPDATE user_presence SET editing_order_id = NULL WHERE user_name = ?")->execute([$userName]);
    }
    respond(['success' => true]);
}
if ($fn === 'get_online_users') {
    requireAdmin($authUser);
    $s = $pdo->query("SELECT user_name, page, last_seen FROM user_presence WHERE last_seen > NOW() - INTERVAL 2 MINUTE ORDER BY last_seen DESC");
    respond($s->fetchAll());
}
if ($fn === 'get_online_restaurants') {
    // Список ресторанов «онлайн»: heartbeat-таймер кабинета шлёт ro/heartbeat
    // каждые 15с и кладёт в ro_users.last_seen_at = NOW() и last_page = текущая
    // страница. Считаем онлайном тех, у кого last_seen_at за последние 15 минут.
    requireAdmin($authUser);
    $s = $pdo->query("
        SELECT ru.restaurant_number,
               ru.legal_entity,
               ru.legal_entity_group,
               ru.last_page,
               r.city,
               r.address,
               ru.last_seen_at AS last_activity
        FROM ro_users ru
        LEFT JOIN restaurants r
          ON r.number = ru.restaurant_number
         AND r.legal_entity = ru.legal_entity COLLATE utf8mb4_general_ci
        WHERE ru.is_active = 1
          AND ru.last_seen_at IS NOT NULL
          AND ru.last_seen_at > NOW() - INTERVAL 15 MINUTE
        ORDER BY ru.last_seen_at DESC
    ");
    respond($s->fetchAll());
}

// ─── Админские RPC (только admin) ───
if ($fn === 'get_admin_stats') {
    $caller = getSessionUser($pdo);
    if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
    $period = $body['period'] ?? 'all';
    $dateFilter = '';
    if ($period === 'week') $dateFilter = " AND created_at > NOW() - INTERVAL 7 DAY";
    elseif ($period === 'month') $dateFilter = " AND created_at > NOW() - INTERVAL 30 DAY";

    $stats = [];
    // Заказы
    $s = $pdo->query("SELECT COUNT(*) as cnt FROM orders WHERE 1=1" . $dateFilter); $stats['orders_total'] = (int)$s->fetch()['cnt'];
    $s = $pdo->query("SELECT COUNT(*) as cnt FROM orders WHERE DATE(created_at) = CURDATE()"); $stats['orders_today'] = (int)$s->fetch()['cnt'];
    // Планы
    $s = $pdo->query("SELECT COUNT(*) as cnt FROM plans WHERE 1=1" . $dateFilter); $stats['plans_total'] = (int)$s->fetch()['cnt'];
    // Активные сессии
    $s = $pdo->query("SELECT COUNT(*) as cnt FROM user_sessions WHERE expires_at > NOW()"); $stats['active_sessions'] = (int)$s->fetch()['cnt'];
    // Товары, поставщики, пользователи
    $s = $pdo->query("SELECT COUNT(*) as cnt FROM products"); $stats['products_count'] = (int)$s->fetch()['cnt'];
    $s = $pdo->query("SELECT COUNT(*) as cnt FROM suppliers"); $stats['suppliers_count'] = (int)$s->fetch()['cnt'];
    $s = $pdo->query("SELECT COUNT(*) as cnt FROM users"); $stats['users_count'] = (int)$s->fetch()['cnt'];
    // Заказы ресторанов
    try { $s = $pdo->query("SELECT COUNT(*) as cnt FROM ro_orders WHERE 1=1" . $dateFilter); $stats['ro_orders_total'] = (int)$s->fetch()['cnt']; } catch (Exception $e) { $stats['ro_orders_total'] = 0; }
    // Заявки поставщикам
    try { $s = $pdo->query("SELECT COUNT(*) as cnt FROM so_orders WHERE 1=1" . $dateFilter); $stats['so_orders_total'] = (int)$s->fetch()['cnt']; } catch (Exception $e) { $stats['so_orders_total'] = 0; }
    // Протоколы цен
    try { $s = $pdo->query("SELECT COUNT(*) as cnt FROM price_agreements WHERE 1=1" . $dateFilter); $stats['price_agreements_total'] = (int)$s->fetch()['cnt']; } catch (Exception $e) { $stats['price_agreements_total'] = 0; }
    // Заказы по юрлицам
    $s = $pdo->query("SELECT legal_entity, COUNT(*) as cnt FROM orders WHERE 1=1" . $dateFilter . " GROUP BY legal_entity ORDER BY cnt DESC");
    $stats['orders_by_entity'] = $s->fetchAll();
    // Топ пользователей
    $s = $pdo->query("SELECT created_by as user_name, COUNT(*) as cnt FROM orders WHERE 1=1" . $dateFilter . " GROUP BY created_by ORDER BY cnt DESC LIMIT 10");
    $stats['top_users'] = $s->fetchAll();

    respond($stats);
}

if ($fn === 'get_sessions') {
    $caller = getSessionUser($pdo);
    if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
    $s = $pdo->query("SELECT id, user_name, CONCAT(LEFT(token, 8), '…') AS token_prefix, created_at, expires_at, ip_address, user_agent FROM user_sessions WHERE expires_at > NOW() ORDER BY created_at DESC");
    respond($s->fetchAll());
}

if ($fn === 'terminate_session') {
    $caller = getSessionUser($pdo);
    if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
    $sessionId = $body['session_id'] ?? '';
    if (!$sessionId) respond(['success' => false, 'error' => 'Не указан ID сессии'], 400);
    $pdo->prepare("DELETE FROM user_sessions WHERE id = ?")->execute([$sessionId]);
    auditLog($pdo, 'session_terminated', 'system', $sessionId, $caller['name']);
    respond(['success' => true]);
}

if ($fn === 'clear_error_logs') {
    $caller = getSessionUser($pdo);
    if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
    $olderThan = $body['older_than_days'] ?? null;
    try {
        if ($olderThan && intval($olderThan) > 0) {
            $pdo->prepare("DELETE FROM error_logs WHERE created_at < NOW() - INTERVAL ? DAY")->execute([intval($olderThan)]);
        } else {
            $pdo->exec("TRUNCATE TABLE error_logs");
        }
        respond(['success' => true]);
    } catch (PDOException $e) {
        respond(['success' => false, 'error' => 'Ошибка очистки логов'], 500);
    }
}
