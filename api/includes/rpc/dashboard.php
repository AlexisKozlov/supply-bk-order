<?php
/**
 * RPC: дашборд.
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName,
 * $ROLE_TEMPLATES, $ACCESS_LEVELS, $clientIp.
 */

    if ($fn === 'dashboard_kpi') {
        requireModuleAccess($authUser, 'dashboard', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $period = $body['period'] ?? 'week';
        $le = $body['legal_entity'] ?? null;
        // Кэш на 30 секунд по ключу (period + legal_entity + actor).
        // Дашборд делает 14 запросов к большим таблицам — без кэша при каждом
        // открытии страницы серверу больно. 30 сек хватает чтобы цифры
        // не были устаревшими на глаз, но при перезагрузке страницы или
        // переходе между табами выдаёт мгновенно.
        $cacheKey = 'dashboard_kpi_' . $period . '_' . ($le ?: '*') . '_' . ($authUserName ?: '_');
        $cached = cacheGet($cacheKey, 30);
        if ($cached !== null) respond($cached);
        $days = ['week' => 7, 'month' => 30, 'quarter' => 90][$period] ?? 7;
        $from = date('Y-m-d', strtotime("-{$days} days"));
        $prevFrom = date('Y-m-d', strtotime("-" . ($days * 2) . " days"));
        // Если фронт прислал юрлицо — проверяем доступ и фильтруем по нему.
        // Если не передал и не admin — фильтруем по всем юрлицам пользователя.
        // Admin без юрлица видит всё.
        if ($le) {
            if (!checkLegalEntityAccess($authUser, $le)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
            $leAnd = " AND legal_entity = ?";
            $leAndAlias = " AND o.legal_entity = ?";
            $leArgs = [$le];
        } elseif (($authUser['role'] ?? '') !== 'admin') {
            $userEntities = $authUser['legal_entities'] ?? '';
            if (is_string($userEntities)) $userEntities = json_decode($userEntities, true) ?: [];
            if (!is_array($userEntities) || empty($userEntities)) respond(['error' => 'У пользователя нет привязанных юрлиц'], 403);
            $phLE = implode(',', array_fill(0, count($userEntities), '?'));
            $leAnd = " AND legal_entity IN ($phLE)";
            $leAndAlias = " AND o.legal_entity IN ($phLE)";
            $leArgs = array_values($userEntities);
        } else {
            $leAnd = '';
            $leAndAlias = '';
            $leArgs = [];
        }

        $curOrders = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE created_at_new >= ?" . $leAnd);
        $curOrders->execute(array_merge([$from], $leArgs));
        $ordersCount = intval($curOrders->fetchColumn());

        // Заказы прошлый период
        $prevOrders = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE created_at_new >= ? AND created_at_new < ?" . $leAnd);
        $prevOrders->execute(array_merge([$prevFrom, $from], $leArgs));
        $prevCount = intval($prevOrders->fetchColumn());
        $ordersDelta = $prevCount > 0 ? round(($ordersCount - $prevCount) / $prevCount * 100) : 0;

        // Сумма (из order_items * product_prices)
        $amtSt = $pdo->prepare("SELECT COALESCE(SUM(oi.qty_boxes * COALESCE(oi.price, pp.price, 0)), 0) as total
            FROM orders o JOIN order_items oi ON oi.order_id = o.id
            LEFT JOIN product_prices pp ON pp.sku = oi.sku AND pp.legal_entity_group = o.legal_entity_group AND pp.price_type = 'purchase'
            WHERE o.created_at_new >= ?" . $leAndAlias);
        $amtSt->execute(array_merge([$from], $leArgs));
        $totalAmount = floatval($amtSt->fetchColumn());

        $prevAmtSt = $pdo->prepare("SELECT COALESCE(SUM(oi.qty_boxes * COALESCE(oi.price, pp.price, 0)), 0)
            FROM orders o JOIN order_items oi ON oi.order_id = o.id
            LEFT JOIN product_prices pp ON pp.sku = oi.sku AND pp.legal_entity_group = o.legal_entity_group AND pp.price_type = 'purchase'
            WHERE o.created_at_new >= ? AND o.created_at_new < ?" . $leAndAlias);
        $prevAmtSt->execute(array_merge([$prevFrom, $from], $leArgs));
        $prevAmount = floatval($prevAmtSt->fetchColumn());
        $amountDelta = $prevAmount > 0 ? round(($totalAmount - $prevAmount) / $prevAmount * 100) : 0;

        // Выполнение поставок
        $totalDel = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE delivery_date >= ? AND delivery_date <= CURDATE()" . $leAnd);
        $totalDel->execute(array_merge([$from], $leArgs));
        $totalDeliveries = intval($totalDel->fetchColumn());
        $receivedDel = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE delivery_date >= ? AND delivery_date <= CURDATE() AND received_at IS NOT NULL" . $leAnd);
        $receivedDel->execute(array_merge([$from], $leArgs));
        $received = intval($receivedDel->fetchColumn());
        $deliveredPct = $totalDeliveries > 0 ? round($received / $totalDeliveries * 100) : 100;

        // Просроченные
        $overdueCntSt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE delivery_date < CURDATE() AND received_at IS NULL AND delivery_date >= ?" . $leAnd);
        $overdueCntSt->execute(array_merge([$from], $leArgs));
        $overdue = $overdueCntSt->fetchColumn();

        // Низкий запас
        $lowStockSt = $pdo->prepare("SELECT COUNT(*) FROM analysis_data WHERE consumption > 0 AND stock > 0 AND stock / (consumption / GREATEST(period_days, 1)) <= 3" . $leAnd);
        $lowStockSt->execute($leArgs);
        $lowStock = $lowStockSt->fetchColumn();

        // Корректировки и чаты — общие BK+VM by design (см. shared_tables_bk_vm),
        // фильтр по юрлицу не применяем.
        $corrPending = $pdo->query("SELECT COUNT(*) FROM order_corrections WHERE status = 'pending'")->fetchColumn();
        $chatUnread = $pdo->query("SELECT COUNT(*) FROM chat_messages cm JOIN chat_conversations cc ON cc.id = cm.conversation_id WHERE cm.is_read = 0 AND cm.direction = 'from_restaurant' AND cc.status = 'open'")->fetchColumn();

        // Оплаты
        $paymentsUpSt = $pdo->prepare("SELECT COUNT(*) FROM supplier_payments WHERE status IN ('upcoming', 'request_due')" . $leAnd);
        $paymentsUpSt->execute($leArgs);
        $paymentsUp = $paymentsUpSt->fetchColumn();

        // Топ поставщиков
        $topSt = $pdo->prepare("SELECT o.supplier, SUM(oi.qty_boxes * COALESCE(oi.price, pp.price, 0)) as total
            FROM orders o JOIN order_items oi ON oi.order_id = o.id
            LEFT JOIN product_prices pp ON pp.sku = oi.sku AND pp.legal_entity_group = o.legal_entity_group AND pp.price_type = 'purchase'
            WHERE o.created_at_new >= ?" . $leAndAlias . "
            GROUP BY o.supplier ORDER BY total DESC LIMIT 10");
        $topSt->execute(array_merge([$from], $leArgs));
        $topSuppliers = $topSt->fetchAll();

        // Просроченные заказы (детали)
        $overdueSql = "SELECT id, supplier, delivery_date, DATEDIFF(CURDATE(), delivery_date) as days_overdue
            FROM orders
            WHERE delivery_date < CURDATE() AND received_at IS NULL AND delivery_date >= ?" . $leAnd;
        $overdueArgs = array_merge([$from], $leArgs);
        $overdueSql .= " ORDER BY delivery_date LIMIT 10";
        $overdueSt = $pdo->prepare($overdueSql);
        $overdueSt->execute($overdueArgs);
        $overdueOrders = $overdueSt->fetchAll();

        // Ближайшие оплаты
        $paysSql = "SELECT id, supplier, payment_date, amount, currency FROM supplier_payments WHERE status IN ('upcoming','request_due')" . $leAnd . " ORDER BY payment_date LIMIT 5";
        $paysSt = $pdo->prepare($paysSql);
        $paysSt->execute($leArgs);
        $upcomingPayments = $paysSt->fetchAll();

        // Тендеры
        $tenSt = $pdo->prepare("SELECT COUNT(*) FROM tenders WHERE status = 'collecting'" . $leAnd);
        $tenSt->execute($leArgs);
        $activeTenders = intval($tenSt->fetchColumn());
        // Сборы остатков
        $collSt = $pdo->prepare("SELECT COUNT(*) FROM stock_collections WHERE status = 'active'" . $leAnd);
        $collSt->execute($leArgs);
        $activeCollections = intval($collSt->fetchColumn());

        $result = [
            'ordersCount' => $ordersCount, 'ordersDelta' => $ordersDelta,
            'totalAmount' => round($totalAmount, 0), 'amountDelta' => $amountDelta,
            'deliveredPct' => $deliveredPct, 'overdueCount' => intval($overdue),
            'lowStockCount' => intval($lowStock), 'correctionsPending' => intval($corrPending),
            'chatUnread' => intval($chatUnread), 'paymentsUpcoming' => intval($paymentsUp),
            'topSuppliers' => $topSuppliers,
            'overdueOrders' => $overdueOrders, 'upcomingPayments' => $upcomingPayments,
            'activeTenders' => $activeTenders, 'activeCollections' => $activeCollections,
        ];
        cacheSet($cacheKey, $result);
        respond($result);
    }

    if ($fn === 'dashboard_critical_stock') {
        requireModuleAccess($authUser, 'dashboard', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $le = $body['legal_entity'] ?? null;
        // Сборка фильтра: явное юрлицо → одно; не-admin без юрлица → его список;
        // admin без юрлица → без ограничения.
        $leWhere = '';
        $leArgs = [];
        if ($le) {
            if (!checkLegalEntityAccess($authUser, $le)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
            $leWhere = " AND a.legal_entity = ?";
            $leArgs = [$le];
        } elseif (($authUser['role'] ?? '') !== 'admin') {
            $userEntities = $authUser['legal_entities'] ?? '';
            if (is_string($userEntities)) $userEntities = json_decode($userEntities, true) ?: [];
            if (!is_array($userEntities) || empty($userEntities)) respond(['error' => 'У пользователя нет привязанных юрлиц'], 403);
            $phLE = implode(',', array_fill(0, count($userEntities), '?'));
            $leWhere = " AND a.legal_entity IN ($phLE)";
            $leArgs = array_values($userEntities);
        }
        $st = $pdo->prepare("SELECT a.sku, p.analog_group, ROUND(a.stock / (a.consumption / GREATEST(a.period_days, 1)), 1) as days_of_stock
            FROM analysis_data a
            JOIN products p ON p.sku = a.sku AND p.legal_entity = a.legal_entity AND p.is_active = 1
            WHERE a.consumption > 0 AND a.stock > 0 AND a.stock / (a.consumption / GREATEST(a.period_days, 1)) <= 5 {$leWhere}
            ORDER BY days_of_stock ASC LIMIT 30");
        $st->execute($leArgs);
        $rows = $st->fetchAll();
        // Группируем по analog_group, берём минимум
        $groups = [];
        foreach ($rows as $r) {
            $g = $r['analog_group'] ?: $r['sku'];
            if (!isset($groups[$g]) || $r['days_of_stock'] < $groups[$g]) $groups[$g] = floatval($r['days_of_stock']);
        }
        $result = [];
        foreach ($groups as $name => $days) {
            $result[] = ['analog_group' => $name, 'days_of_stock' => $days];
        }
        usort($result, fn($a, $b) => $a['days_of_stock'] <=> $b['days_of_stock']);
        respond(array_slice($result, 0, 20));
    }

    if ($fn === 'get_pending_tasks_all') {
        requireModuleAccess($authUser, 'protocols', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        // Не-админу — только задачи протоколов из его юрлиц.
        $leWhere = '';
        $leArgs = [];
        if (($authUser['role'] ?? '') !== 'admin') {
            $userEntities = $authUser['legal_entities'] ?? '';
            if (is_string($userEntities)) $userEntities = json_decode($userEntities, true) ?: [];
            if (!is_array($userEntities) || empty($userEntities)) respond([]);
            $phLE = implode(',', array_fill(0, count($userEntities), '?'));
            $leWhere = " AND p.legal_entity IN ($phLE)";
            $leArgs = array_values($userEntities);
        }
        $st = $pdo->prepare("SELECT d.id, d.text, d.responsible_person, d.deadline, d.status, p.topic, p.meeting_date
            FROM protocol_decisions d
            JOIN meeting_protocols p ON p.id = d.protocol_id
            WHERE d.status IN ('pending', 'overdue'){$leWhere}
            ORDER BY CASE WHEN d.deadline IS NULL THEN 1 ELSE 0 END, d.deadline ASC
            LIMIT 20");
        $st->execute($leArgs);
        respond($st->fetchAll());
    }

    if ($fn === 'get_user_tg_settings') {
        $userName = $body['user_name'] ?? '';
        if (!$userName) respond(['error' => 'user_name required'], 400);
        // Свои настройки видит сам пользователь, чужие — только admin.
        if (($authUser['role'] ?? '') !== 'admin' && $userName !== $authUserName) {
            respond(['error' => 'Нет доступа к настройкам другого пользователя'], 403);
        }
        $st = $pdo->prepare("SELECT daily_summary, psc_expiry, price_changed, overdue_delivery, data_updates, expiring_items, restaurant_sales, low_stock, correction_notifications, chat_notifications FROM telegram_settings WHERE user_name = ?");
        $st->execute([$userName]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        respond($row ?: []);
    }
