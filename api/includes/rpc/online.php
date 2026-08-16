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
    // Заказы ресторанов и заявки поставщикам: в этих таблицах нет created_at,
    // дата подачи лежит в submitted_at. Раньше фильтр по периоду падал молча,
    // и обе цифры всегда показывали 0 для «недели» и «месяца».
    $submittedFilter = str_replace('created_at', 'submitted_at', $dateFilter);
    try { $s = $pdo->query("SELECT COUNT(*) as cnt FROM ro_orders WHERE 1=1" . $submittedFilter); $stats['ro_orders_total'] = (int)$s->fetch()['cnt']; } catch (Exception $e) { $stats['ro_orders_total'] = 0; }
    try { $s = $pdo->query("SELECT COUNT(*) as cnt FROM so_orders WHERE 1=1" . $submittedFilter); $stats['so_orders_total'] = (int)$s->fetch()['cnt']; } catch (Exception $e) { $stats['so_orders_total'] = 0; }
    // Протоколы цен
    try { $s = $pdo->query("SELECT COUNT(*) as cnt FROM price_agreements WHERE 1=1" . $dateFilter); $stats['price_agreements_total'] = (int)$s->fetch()['cnt']; } catch (Exception $e) { $stats['price_agreements_total'] = 0; }
    // Заказы по юрлицам
    $s = $pdo->query("SELECT legal_entity, COUNT(*) as cnt FROM orders WHERE 1=1" . $dateFilter . " GROUP BY legal_entity ORDER BY cnt DESC");
    $stats['orders_by_entity'] = $s->fetchAll();
    // Топ пользователей
    $s = $pdo->query("SELECT created_by as user_name, COUNT(*) as cnt FROM orders WHERE 1=1" . $dateFilter . " GROUP BY created_by ORDER BY cnt DESC LIMIT 10");
    $stats['top_users'] = $s->fetchAll();

    // Активность по дням за 30 дней: три потока заказов рядом — видно, что
    // портал живёт, и когда были провалы.
    $byDay = [];
    // У каждой таблицы своя колонка «когда появилось».
    $sources = [
        'orders' => ['orders', 'created_at'],
        'ro'     => ['ro_orders', 'submitted_at'],
        'so'     => ['so_orders', 'submitted_at'],
    ];
    foreach ($sources as $key => [$table, $col]) {
        try {
            $q = $pdo->query("SELECT DATE(`$col`) d, COUNT(*) c FROM `$table`
                              WHERE `$col` > NOW() - INTERVAL 30 DAY
                              GROUP BY DATE(`$col`)");
            foreach ($q as $row) {
                $d = $row['d'];
                if (!isset($byDay[$d])) $byDay[$d] = ['date' => $d, 'orders' => 0, 'ro' => 0, 'so' => 0];
                $byDay[$d][$key] = (int)$row['c'];
            }
        } catch (Exception $e) { /* таблицы может не быть — пропускаем поток */ }
    }
    ksort($byDay);
    $stats['by_day'] = array_values($byDay);

    // Сколько людей реально работало за период — по журналу действий.
    try {
        $s = $pdo->query("SELECT COUNT(DISTINCT user_name) c FROM audit_log WHERE 1=1" . $dateFilter);
        $stats['active_users'] = (int)$s->fetch()['c'];
    } catch (Exception $e) { $stats['active_users'] = 0; }

    respond($stats);
}

if ($fn === 'get_broadcast_audience') {
    // Сколько человек получит рассылку — показываем до отправки, чтобы
    // «отправить всем» не было прыжком в темноту.
    $caller = getSessionUser($pdo);
    if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
    $out = ['staff_cabinet' => 0, 'restaurant_cabinet' => 0, 'staff_telegram' => 0, 'restaurant_telegram' => 0];
    try {
        $out['staff_cabinet'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE disabled_at IS NULL")->fetchColumn();
    } catch (PDOException $e) {}
    try {
        $out['staff_telegram'] = (int)$pdo->query("
            SELECT COUNT(*) FROM users
            WHERE disabled_at IS NULL AND telegram_chat_id IS NOT NULL AND telegram_chat_id <> ''
        ")->fetchColumn();
    } catch (PDOException $e) {}
    try {
        $out['restaurant_cabinet'] = (int)$pdo->query("
            SELECT COUNT(*) FROM ro_users ru
            JOIN restaurants r ON r.number = ru.restaurant_number AND r.legal_entity_group = ru.legal_entity_group
            WHERE ru.is_active = 1 AND r.active = 1
        ")->fetchColumn();
    } catch (PDOException $e) {}
    try {
        // Заблокировавших бота не считаем — им сообщение не дойдёт.
        $out['restaurant_telegram'] = (int)$pdo->query("
            SELECT COUNT(DISTINCT chat_id) FROM ro_telegram_subs
            WHERE chat_id IS NOT NULL
              AND (verified_at IS NOT NULL OR (must_reverify_by IS NOT NULL AND must_reverify_by > NOW()))
              AND (tg_blocked_at IS NULL OR tg_blocked_at < NOW() - INTERVAL 30 DAY)
        ")->fetchColumn();
    } catch (PDOException $e) {}
    respond($out);
}

if ($fn === 'get_table_counts') {
    // Сколько строк в таблицах — для вкладки «Бэкап», чтобы объём выгрузки был
    // виден до нажатия. Имена таблиц берём ТОЛЬКО из белого списка.
    $caller = getSessionUser($pdo);
    if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
    $allowed = [
        'products', 'suppliers', 'orders', 'order_items', 'plans', 'settings',
        'audit_log', 'stock_1c', 'analysis_data', 'cards', 'restaurants', 'delivery_schedule',
    ];
    $want = $body['tables'] ?? $allowed;
    if (!is_array($want)) $want = $allowed;
    $out = [];
    foreach ($want as $name) {
        if (!in_array($name, $allowed, true)) continue;
        try {
            $out[$name] = (int)$pdo->query("SELECT COUNT(*) FROM `$name`")->fetchColumn();
        } catch (PDOException $e) {
            $out[$name] = null;
        }
    }
    respond($out);
}

if ($fn === 'get_sessions') {
    $caller = getSessionUser($pdo);
    if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
    // Токен наружу не отдаём: свою сессию помечаем здесь, на сервере.
    $myToken = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
    $s = $pdo->prepare("
        SELECT s.id, s.user_name, s.created_at, s.last_seen_at, s.expires_at,
               s.ip_address, s.user_agent,
               u.role, u.display_role,
               (s.token = ?) AS is_current,
               p.last_seen AS presence_last_seen,
               p.page      AS presence_page
        FROM user_sessions s
        LEFT JOIN users u ON u.name = s.user_name
        LEFT JOIN user_presence p ON p.user_name = s.user_name
        WHERE s.expires_at > NOW()
        ORDER BY COALESCE(s.last_seen_at, s.created_at) DESC
    ");
    $s->execute([$myToken]);
    $rows = $s->fetchAll();
    foreach ($rows as &$r) {
        $r['is_current']   = (int)$r['is_current'] === 1;
        $r['device_label'] = roMakeDeviceLabel($r['user_agent']);
        unset($r['user_agent']);
    }
    respond($rows);
}

if ($fn === 'get_ro_sessions') {
    // Сессии кабинетов ресторанов: одно устройство = одна строка.
    $caller = getSessionUser($pdo);
    if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
    $s = $pdo->query("
        SELECT s.id, s.created_at, s.last_seen_at, s.expires_at, s.remember,
               s.is_pwa, s.ip_address, s.device_label,
               ru.restaurant_number, ru.legal_entity, ru.legal_entity_group,
               ru.last_page, ru.is_active,
               r.city, r.address
        FROM ro_user_sessions s
        JOIN ro_users ru ON ru.id = s.ro_user_id
        LEFT JOIN restaurants r
          ON r.number = ru.restaurant_number
         AND r.legal_entity_group = ru.legal_entity_group
        WHERE s.expires_at > NOW()
        ORDER BY s.last_seen_at DESC
    ");
    respond($s->fetchAll());
}

if ($fn === 'terminate_session') {
    $caller = getSessionUser($pdo);
    if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
    $sessionId = $body['session_id'] ?? '';
    if (!$sessionId) respond(['success' => false, 'error' => 'Не указан ID сессии'], 400);
    // Свою сессию не рубим — иначе админ выкидывает сам себя одним кликом.
    $myToken = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
    $own = $pdo->prepare("SELECT 1 FROM user_sessions WHERE id = ? AND token = ?");
    $own->execute([$sessionId, $myToken]);
    if ($own->fetchColumn()) respond(['success' => false, 'error' => 'Это ваша текущая сессия'], 400);
    $pdo->prepare("DELETE FROM user_sessions WHERE id = ?")->execute([$sessionId]);
    auditLog($pdo, 'session_terminated', 'system', $sessionId, $caller['name']);
    respond(['success' => true]);
}

if ($fn === 'terminate_user_sessions') {
    // Завершить все сессии одного человека (свою текущую не трогаем).
    $caller = getSessionUser($pdo);
    if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
    $userName = trim((string)($body['user_name'] ?? ''));
    if ($userName === '') respond(['success' => false, 'error' => 'Не указан пользователь'], 400);
    $myToken = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
    $st = $pdo->prepare("DELETE FROM user_sessions WHERE user_name = ? AND token <> ?");
    $st->execute([$userName, $myToken]);
    $n = $st->rowCount();
    auditLog($pdo, 'session_terminated', 'system', $userName . ' (' . $n . ')', $caller['name']);
    respond(['success' => true, 'count' => $n]);
}

if ($fn === 'terminate_ro_session') {
    $caller = getSessionUser($pdo);
    if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
    $sessionId = (int)($body['session_id'] ?? 0);
    if ($sessionId <= 0) respond(['success' => false, 'error' => 'Не указан ID сессии'], 400);
    // Компанию берём ДО удаления — потом связи уже не будет.
    $roScope = null;
    try {
        $rs = $pdo->prepare("SELECT ru.restaurant_number, ru.legal_entity_group FROM ro_user_sessions s JOIN ro_users ru ON ru.id = s.ro_user_id WHERE s.id = ? LIMIT 1");
        $rs->execute([$sessionId]);
        if ($rr = $rs->fetch()) $roScope = roGetLegalEntity($pdo, $rr['restaurant_number'], $rr['legal_entity_group']) ?: ($rr['legal_entity_group'] ?: null);
    } catch (Exception $e) { /* не критично */ }
    $pdo->prepare("DELETE FROM ro_user_sessions WHERE id = ?")->execute([$sessionId]);
    auditLog($pdo, 'session_terminated', 'restaurant', (string)$sessionId, $caller['name'], null, null, $roScope);
    respond(['success' => true]);
}

if ($fn === 'terminate_ro_restaurant_sessions') {
    // Все устройства одного ресторана.
    $caller = getSessionUser($pdo);
    if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
    $number = (int)($body['restaurant_number'] ?? 0);
    $group  = ($body['legal_entity_group'] ?? '') === 'PS' ? 'PS' : 'BK_VM';
    if ($number <= 0) respond(['success' => false, 'error' => 'Не указан ресторан'], 400);
    $st = $pdo->prepare("
        DELETE s FROM ro_user_sessions s
        JOIN ro_users ru ON ru.id = s.ro_user_id
        WHERE ru.restaurant_number = ? AND ru.legal_entity_group = ?
    ");
    $st->execute([$number, $group]);
    $n = $st->rowCount();
    auditLog($pdo, 'session_terminated', 'restaurant', '№' . $number . ' (' . $n . ')', $caller['name'], null, null,
             roGetLegalEntity($pdo, $number, $group) ?: $group);
    respond(['success' => true, 'count' => $n]);
}

// Сколько записей журнала действий приходится на каждый тип. Нужен экрану
// «Журнал» в админке: он рисует счётчик у каждого раздела и прячет пустые.
// Раньше фронт для этого выкачивал 5000 записей и всё равно недосчитывал —
// сервер режет выдачу на 5000 строк, а записей уже 5422.
if ($fn === 'audit_group_counts') {
    $caller = getSessionUser($pdo);
    if (!$caller) respond(['error' => 'Требуется авторизация'], 401);

    $where = ['1=1'];
    $params = [];
    $from = trim((string)($body['date_from'] ?? ''));
    $to   = trim((string)($body['date_to'] ?? ''));
    if ($from !== '') { $where[] = 'created_at >= ?'; $params[] = $from; }
    if ($to !== '')   { $where[] = 'created_at <= ?'; $params[] = $to . ' 23:59:59'; }
    $user = trim((string)($body['user_name'] ?? ''));
    if ($user !== '') { $where[] = 'user_name = ?'; $params[] = $user; }
    $le = trim((string)($body['legal_entity'] ?? ''));
    if ($le !== '') { $where[] = 'legal_entity = ?'; $params[] = $le; }
    $q = trim((string)($body['search'] ?? ''));
    if (mb_strlen($q) >= 2) {
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $where[] = '(user_name LIKE ? OR details LIKE ? OR entity_id LIKE ?)';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    $sql = 'SELECT entity_type, COUNT(*) AS cnt FROM audit_log WHERE ' . implode(' AND ', $where) . ' GROUP BY entity_type';
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll() as $row) $out[$row['entity_type']] = (int)$row['cnt'];
        respond($out);
    } catch (Exception $e) {
        error_log('audit_group_counts error: ' . $e->getMessage());
        respond(['error' => 'Не удалось посчитать записи'], 500);
    }
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
