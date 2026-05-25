<?php
/**
 * RPC: дистрибуция (dist_*).
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName,
 * $ROLE_TEMPLATES, $ACCESS_LEVELS, $clientIp.
 */

    if ($fn === 'dist_get_sessions') {
        requireModuleAccess($authUser, 'distribution', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $legalEntity = $_GET['legal_entity'] ?? $body['legal_entity'] ?? null;
        if ($legalEntity && !checkLegalEntityAccess($authUser, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $group = $legalEntity ? getEntityGroup($legalEntity) : 'BK_VM';
        $s = $pdo->prepare("SELECT * FROM dist_sessions WHERE legal_entity_group = ? ORDER BY created_at DESC");
        $s->execute([$group]);
        respond($s->fetchAll());
    }

    if ($fn === 'dist_create_session') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $name = trim($body['name'] ?? '');
        $products = $body['products'] ?? [];
        $legalEntity = $body['legal_entity'] ?? null;
        if (!$name) respond(['error' => 'Название обязательно'], 400);
        if (empty($products)) respond(['error' => 'Добавьте хотя бы один товар'], 400);
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($authUser, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $group = getEntityGroup($legalEntity);
        $caller = getSessionUser($pdo);
        $s = $pdo->prepare("INSERT INTO dist_sessions (name, legal_entity_group, created_by) VALUES (?, ?, ?)");
        $s->execute([$name, $group, $caller['name'] ?? 'unknown']);
        $sessionId = $pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO dist_session_products (session_id, product_id, custom_name, custom_sku, default_qty, unit, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($products as $i => $p) {
            $productId = !empty($p['product_id']) ? $p['product_id'] : null;
            $customName = !empty($p['custom_name']) ? trim($p['custom_name']) : null;
            $customSku = !empty($p['custom_sku']) ? trim($p['custom_sku']) : null;
            $ins->execute([$sessionId, $productId, $customName, $customSku, $p['default_qty'] ?? 1, $p['unit'] ?? 'кор', $i]);
        }
        respond(['success' => true, 'session_id' => (int)$sessionId]);
    }

    if ($fn === 'dist_delete_session') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['session_id'] ?? 0);
        if (!$id) respond(['error' => 'session_id обязателен'], 400);
        $sg = $pdo->prepare("SELECT legal_entity_group FROM dist_sessions WHERE id=?"); $sg->execute([$id]);
        $sgVal = $sg->fetchColumn();
        if ($sgVal === false) respond(['error' => 'Сессия не найдена'], 404);
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        // CASCADE удалит session_products и entries
        $pdo->prepare("DELETE FROM dist_sessions WHERE id=?")->execute([$id]);
        respond(['success' => true]);
    }

    if ($fn === 'dist_close_session') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['session_id'] ?? 0);
        if (!$id) respond(['error' => 'session_id обязателен'], 400);
        $sg = $pdo->prepare("SELECT legal_entity_group FROM dist_sessions WHERE id=?"); $sg->execute([$id]);
        $sgVal = $sg->fetchColumn();
        if ($sgVal === false) respond(['error' => 'Сессия не найдена'], 404);
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        $pdo->prepare("UPDATE dist_sessions SET status='closed', closed_at=NOW() WHERE id=?")->execute([$id]);
        respond(['success' => true]);
    }

    if ($fn === 'dist_reopen_session') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['session_id'] ?? 0);
        if (!$id) respond(['error' => 'session_id обязателен'], 400);
        $sg = $pdo->prepare("SELECT legal_entity_group FROM dist_sessions WHERE id=?"); $sg->execute([$id]);
        $sgVal = $sg->fetchColumn();
        if ($sgVal === false) respond(['error' => 'Сессия не найдена'], 404);
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        $pdo->prepare("UPDATE dist_sessions SET status='active', closed_at=NULL WHERE id=?")->execute([$id]);
        respond(['success' => true]);
    }

    if ($fn === 'dist_get_session_data') {
        requireModuleAccess($authUser, 'distribution', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['session_id'] ?? 0);
        if (!$id) respond(['error' => 'session_id обязателен'], 400);
        // Сессия
        $s = $pdo->prepare("SELECT * FROM dist_sessions WHERE id=?");
        $s->execute([$id]);
        $session = $s->fetch();
        if (!$session) respond(['error' => 'Сессия не найдена'], 404);
        $sessionGroup = $session['legal_entity_group'] ?: 'BK_VM';
        if (!checkLegalEntityGroupAccess($authUser, $sessionGroup)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        // Товары сессии с данными из справочника
        $s = $pdo->prepare("SELECT sp.id, sp.product_id, sp.custom_name, sp.custom_sku, sp.default_qty, sp.unit, sp.sort_order,
            COALESCE(sp.custom_name, p.name) as product_name, COALESCE(sp.custom_sku, p.sku) as article, p.supplier
            FROM dist_session_products sp
            LEFT JOIN products p ON p.id = sp.product_id
            WHERE sp.session_id = ?
            ORDER BY sp.sort_order");
        $s->execute([$id]);
        $products = $s->fetchAll();
        $spIds = array_column($products, 'id');
        // Записи отгрузки
        $entries = [];
        if (!empty($spIds)) {
            $placeholders = implode(',', array_fill(0, count($spIds), '?'));
            $s = $pdo->prepare("SELECT * FROM dist_entries WHERE session_product_id IN ($placeholders)");
            $s->execute($spIds);
            $entries = $s->fetchAll();
        }
        // Рестораны — только той же группы, что и сессия
        $s = $pdo->prepare("SELECT id, number, address, city, region, legal_entity_group FROM restaurants WHERE active=1 AND legal_entity_group = ? ORDER BY CAST(number AS UNSIGNED)");
        $s->execute([$sessionGroup]);
        $restaurants = $s->fetchAll();
        // Дни доставки для каждого ресторана
        $restIds = array_column($restaurants, 'id');
        $deliveryDays = [];
        if (!empty($restIds)) {
            $ph = implode(',', array_fill(0, count($restIds), '?'));
            $s = $pdo->prepare("SELECT restaurant_id, day_of_week FROM delivery_schedule WHERE restaurant_id IN ($ph) ORDER BY day_of_week");
            $s->execute($restIds);
            foreach ($s->fetchAll() as $row) {
                $deliveryDays[$row['restaurant_id']][] = intval($row['day_of_week']);
            }
        }
        foreach ($restaurants as &$r) {
            $r['delivery_days'] = $deliveryDays[$r['id']] ?? [];
        }
        unset($r);
        // Примечания
        $s = $pdo->prepare("SELECT restaurant_number, note FROM dist_notes WHERE session_id=?");
        $s->execute([$id]);
        $notes = [];
        foreach ($s->fetchAll() as $n) $notes[$n['restaurant_number']] = $n['note'];
        respond([
            'session' => $session,
            'products' => $products,
            'entries' => $entries,
            'restaurants' => $restaurants,
            'notes' => $notes,
        ]);
    }

    if ($fn === 'dist_toggle_shipped') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $spId = intval($body['session_product_id'] ?? 0);
        $restNum = $body['restaurant_number'] ?? '';
        $shipped = isset($body['shipped']) ? (int)$body['shipped'] : 1;
        if (!$spId || !$restNum) respond(['error' => 'Не указан товар или ресторан'], 400);
        $sg = $pdo->prepare("SELECT s.legal_entity_group FROM dist_session_products sp JOIN dist_sessions s ON s.id=sp.session_id WHERE sp.id=?"); $sg->execute([$spId]);
        $sgVal = $sg->fetchColumn();
        if ($sgVal === false) respond(['error' => 'Позиция не найдена'], 404);
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        // Upsert
        $s = $pdo->prepare("INSERT INTO dist_entries (session_product_id, restaurant_number, shipped)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE shipped = VALUES(shipped), updated_at = NOW()");
        $s->execute([$spId, $restNum, $shipped]);
        respond(['success' => true]);
    }

    if ($fn === 'dist_update_qty') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $spId = intval($body['session_product_id'] ?? 0);
        $restNum = $body['restaurant_number'] ?? '';
        $qty = $body['qty'] ?? null;
        if (!$spId || !$restNum) respond(['error' => 'Не указан товар или ресторан'], 400);
        $sg = $pdo->prepare("SELECT s.legal_entity_group FROM dist_session_products sp JOIN dist_sessions s ON s.id=sp.session_id WHERE sp.id=?"); $sg->execute([$spId]);
        $sgVal = $sg->fetchColumn();
        if ($sgVal === false) respond(['error' => 'Позиция не найдена'], 404);
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        // Upsert
        $s = $pdo->prepare("INSERT INTO dist_entries (session_product_id, restaurant_number, qty)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE qty = VALUES(qty), updated_at = NOW()");
        $s->execute([$spId, $restNum, $qty]);
        respond(['success' => true]);
    }

    if ($fn === 'dist_add_products') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $sessionId = intval($body['session_id'] ?? 0);
        $products = $body['products'] ?? [];
        if (!$sessionId || empty($products)) respond(['error' => 'Нет данных'], 400);
        $sg = $pdo->prepare("SELECT legal_entity_group FROM dist_sessions WHERE id=?"); $sg->execute([$sessionId]);
        $sgVal = $sg->fetchColumn();
        if ($sgVal === false) respond(['error' => 'Сессия не найдена'], 404);
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        // Получаем текущий макс sort_order
        $s = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM dist_session_products WHERE session_id=?");
        $s->execute([$sessionId]);
        $maxOrder = (int)$s->fetchColumn();
        $ins = $pdo->prepare("INSERT INTO dist_session_products (session_id, product_id, custom_name, custom_sku, default_qty, unit, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($products as $i => $p) {
            $productId = !empty($p['product_id']) ? $p['product_id'] : null;
            $customName = !empty($p['custom_name']) ? trim($p['custom_name']) : null;
            $customSku = !empty($p['custom_sku']) ? trim($p['custom_sku']) : null;
            $ins->execute([$sessionId, $productId, $customName, $customSku, $p['default_qty'] ?? 1, $p['unit'] ?? 'кор', $maxOrder + $i + 1]);
        }
        respond(['success' => true]);
    }

    if ($fn === 'dist_remove_product') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $spId = intval($body['session_product_id'] ?? 0);
        if (!$spId) respond(['error' => 'Не указан товар'], 400);
        $sg = $pdo->prepare("SELECT s.legal_entity_group FROM dist_session_products sp JOIN dist_sessions s ON s.id=sp.session_id WHERE sp.id=?"); $sg->execute([$spId]);
        $sgVal = $sg->fetchColumn();
        if ($sgVal === false) respond(['error' => 'Позиция не найдена'], 404);
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        $pdo->prepare("DELETE FROM dist_session_products WHERE id=?")->execute([$spId]);
        respond(['success' => true]);
    }

    if ($fn === 'dist_save_note') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $sessionId = intval($body['session_id'] ?? 0);
        $restNum = $body['restaurant_number'] ?? '';
        $note = trim($body['note'] ?? '');
        if (!$sessionId || !$restNum) respond(['error' => 'Нет данных'], 400);
        $sg = $pdo->prepare("SELECT legal_entity_group FROM dist_sessions WHERE id=?"); $sg->execute([$sessionId]);
        $sgVal = $sg->fetchColumn();
        if ($sgVal === false) respond(['error' => 'Сессия не найдена'], 404);
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        $s = $pdo->prepare("INSERT INTO dist_notes (session_id, restaurant_number, note)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE note = VALUES(note)");
        $s->execute([$sessionId, $restNum, $note]);
        respond(['success' => true]);
    }

    if ($fn === 'dist_bulk_toggle') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $spId = intval($body['session_product_id'] ?? 0);
        $restaurantNumbers = $body['restaurant_numbers'] ?? [];
        $shipped = isset($body['shipped']) ? (int)$body['shipped'] : 1;
        if (!$spId || empty($restaurantNumbers)) respond(['error' => 'Нет данных'], 400);
        $sg = $pdo->prepare("SELECT s.legal_entity_group FROM dist_session_products sp JOIN dist_sessions s ON s.id=sp.session_id WHERE sp.id=?"); $sg->execute([$spId]);
        $sgVal = $sg->fetchColumn();
        if ($sgVal === false) respond(['error' => 'Позиция не найдена'], 404);
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        $ins = $pdo->prepare("INSERT INTO dist_entries (session_product_id, restaurant_number, shipped)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE shipped = VALUES(shipped), updated_at = NOW()");
        foreach ($restaurantNumbers as $rn) {
            $ins->execute([$spId, $rn, $shipped]);
        }
        respond(['success' => true]);
    }
