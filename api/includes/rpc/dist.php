<?php
/**
 * RPC: дистрибуция (dist_*).
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName,
 * $ROLE_TEMPLATES, $ACCESS_LEVELS, $clientIp.
 */

    // Запись в историю модуля распределения (таблица dist_history).
    // Отдельный журнал: кто, когда и что нажал в сессии. Ошибку журнала
    // глотаем — она не должна ронять само действие пользователя.
    if (!function_exists('distHistoryLog')) {
        function distHistoryLog($pdo, $sessionId, $group, $action, $userName, $opts = []) {
            try {
                $pdo->prepare("INSERT INTO dist_history
                    (session_id, legal_entity_group, action, session_product_id, restaurant_number, old_value, new_value, detail, user_name)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([
                        (int)$sessionId,
                        (string)$group,
                        $action,
                        isset($opts['spId']) ? (int)$opts['spId'] : null,
                        $opts['restNum'] ?? null,
                        $opts['old'] ?? null,
                        $opts['new'] ?? null,
                        $opts['detail'] ?? null,
                        $userName,
                    ]);
            } catch (Exception $e) {
                error_log('distHistoryLog error: ' . $e->getMessage());
            }
        }
    }
    // Читаемое значение статуса клетки для истории.
    if (!function_exists('distShippedLabel')) {
        function distShippedLabel($v) {
            $v = (int)$v;
            if ($v === 1) return '✓ отгружено';
            if ($v === 2) return '✗ не нужно';
            return '—';
        }
    }

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
        distHistoryLog($pdo, $sessionId, $group, 'session_created', $authUserName, [
            'detail' => $name . ' (' . count($products) . ' тов.)',
        ]);
        respond(['success' => true, 'session_id' => (int)$sessionId]);
    }

    if ($fn === 'dist_delete_session') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['session_id'] ?? 0);
        if (!$id) respond(['error' => 'session_id обязателен'], 400);
        $sg = $pdo->prepare("SELECT legal_entity_group, name FROM dist_sessions WHERE id=?"); $sg->execute([$id]);
        $sgRow = $sg->fetch();
        if ($sgRow === false) respond(['error' => 'Сессия не найдена'], 404);
        $sgVal = $sgRow['legal_entity_group'];
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        // История пишется ДО удаления (записи dist_history не привязаны FK,
        // поэтому переживут удаление сессии).
        distHistoryLog($pdo, $id, $sgVal ?: 'BK_VM', 'session_deleted', $authUserName, [
            'detail' => $sgRow['name'],
        ]);
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
        distHistoryLog($pdo, $id, $sgVal ?: 'BK_VM', 'session_closed', $authUserName);
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
        distHistoryLog($pdo, $id, $sgVal ?: 'BK_VM', 'session_reopened', $authUserName);
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

    // Поле version защищает от молчаливой потери чужих правок: фронт
    // присылает version, который у него был, бэк сверяет — если в БД
    // уже выше, значит кто-то нас опередил, отвечаем 409 + актуальное
    // значение. Если version не прислали — работаем как раньше (для
    // bulk-операций и переходного периода).
    if ($fn === 'dist_toggle_shipped') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $spId = intval($body['session_product_id'] ?? 0);
        $restNum = $body['restaurant_number'] ?? '';
        $shipped = isset($body['shipped']) ? (int)$body['shipped'] : 1;
        $expectedVersion = isset($body['version']) ? (int)$body['version'] : null;
        if (!$spId || !$restNum) respond(['error' => 'Не указан товар или ресторан'], 400);
        $sg = $pdo->prepare("SELECT s.legal_entity_group, s.id AS session_id FROM dist_session_products sp JOIN dist_sessions s ON s.id=sp.session_id WHERE sp.id=?"); $sg->execute([$spId]);
        $sgRow = $sg->fetch();
        if ($sgRow === false) respond(['error' => 'Позиция не найдена'], 404);
        $sgVal = $sgRow['legal_entity_group'];
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);

        // Прежнее значение — для истории «было → стало».
        $prevStmt = $pdo->prepare("SELECT shipped FROM dist_entries WHERE session_product_id=? AND restaurant_number=?");
        $prevStmt->execute([$spId, $restNum]);
        $prevShipped = $prevStmt->fetchColumn();
        $prevShipped = $prevShipped === false ? 0 : (int)$prevShipped;

        if ($expectedVersion !== null) {
            $cur = $pdo->prepare("SELECT shipped, qty, version, updated_by FROM dist_entries WHERE session_product_id=? AND restaurant_number=?");
            $cur->execute([$spId, $restNum]);
            $row = $cur->fetch();
            if ($row && (int)$row['version'] !== $expectedVersion) {
                respond([
                    'error' => 'conflict',
                    'message' => 'Другой пользователь изменил эту клетку',
                    'current' => ['shipped' => (int)$row['shipped'], 'qty' => $row['qty'], 'version' => (int)$row['version'], 'updated_by' => $row['updated_by']],
                ], 409);
            }
        }

        $s = $pdo->prepare("INSERT INTO dist_entries (session_product_id, restaurant_number, shipped, created_by, updated_by, version)
            VALUES (?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE shipped = VALUES(shipped), updated_at = NOW(), updated_by = VALUES(updated_by), version = version + 1");
        $s->execute([$spId, $restNum, $shipped, $authUserName, $authUserName]);

        if ($prevShipped !== (int)$shipped) {
            distHistoryLog($pdo, $sgRow['session_id'], $sgVal ?: 'BK_VM', 'cell_shipped', $authUserName, [
                'spId' => $spId, 'restNum' => $restNum,
                'old' => distShippedLabel($prevShipped), 'new' => distShippedLabel($shipped),
            ]);
        }

        $vs = $pdo->prepare("SELECT version FROM dist_entries WHERE session_product_id=? AND restaurant_number=?");
        $vs->execute([$spId, $restNum]);
        respond(['success' => true, 'version' => (int)$vs->fetchColumn()]);
    }

    if ($fn === 'dist_update_qty') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $spId = intval($body['session_product_id'] ?? 0);
        $restNum = $body['restaurant_number'] ?? '';
        $qty = $body['qty'] ?? null;
        $expectedVersion = isset($body['version']) ? (int)$body['version'] : null;
        if (!$spId || !$restNum) respond(['error' => 'Не указан товар или ресторан'], 400);
        $sg = $pdo->prepare("SELECT s.legal_entity_group, s.id AS session_id FROM dist_session_products sp JOIN dist_sessions s ON s.id=sp.session_id WHERE sp.id=?"); $sg->execute([$spId]);
        $sgRow = $sg->fetch();
        if ($sgRow === false) respond(['error' => 'Позиция не найдена'], 404);
        $sgVal = $sgRow['legal_entity_group'];
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);

        // Прежнее количество — для истории «было → стало».
        $prevStmt = $pdo->prepare("SELECT qty FROM dist_entries WHERE session_product_id=? AND restaurant_number=?");
        $prevStmt->execute([$spId, $restNum]);
        $prevQtyRaw = $prevStmt->fetchColumn();
        $prevQty = ($prevQtyRaw === false || $prevQtyRaw === null || trim((string)$prevQtyRaw) === '') ? null : (string)$prevQtyRaw;

        if ($expectedVersion !== null) {
            $cur = $pdo->prepare("SELECT shipped, qty, version, updated_by FROM dist_entries WHERE session_product_id=? AND restaurant_number=?");
            $cur->execute([$spId, $restNum]);
            $row = $cur->fetch();
            if ($row && (int)$row['version'] !== $expectedVersion) {
                respond([
                    'error' => 'conflict',
                    'message' => 'Другой пользователь изменил эту клетку',
                    'current' => ['shipped' => (int)$row['shipped'], 'qty' => $row['qty'], 'version' => (int)$row['version'], 'updated_by' => $row['updated_by']],
                ], 409);
            }
        }

        $s = $pdo->prepare("INSERT INTO dist_entries (session_product_id, restaurant_number, qty, created_by, updated_by, version)
            VALUES (?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE qty = VALUES(qty), updated_at = NOW(), updated_by = VALUES(updated_by), version = version + 1");
        $s->execute([$spId, $restNum, $qty, $authUserName, $authUserName]);

        $newQty = ($qty === null || trim((string)$qty) === '') ? null : (string)$qty;
        if ($prevQty !== $newQty) {
            distHistoryLog($pdo, $sgRow['session_id'], $sgVal ?: 'BK_VM', 'cell_qty', $authUserName, [
                'spId' => $spId, 'restNum' => $restNum,
                'old' => $prevQty === null ? 'стандарт' : $prevQty,
                'new' => $newQty === null ? 'стандарт' : $newQty,
            ]);
        }

        $vs = $pdo->prepare("SELECT version FROM dist_entries WHERE session_product_id=? AND restaurant_number=?");
        $vs->execute([$spId, $restNum]);
        respond(['success' => true, 'version' => (int)$vs->fetchColumn()]);
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
        $addDetail = count($products) === 1
            ? trim(($products[0]['custom_sku'] ?? '') . ' ' . ($products[0]['custom_name'] ?? 'товар'))
            : (count($products) . ' тов.');
        distHistoryLog($pdo, $sessionId, $sgVal ?: 'BK_VM', 'product_added', $authUserName, ['detail' => $addDetail]);
        respond(['success' => true]);
    }

    if ($fn === 'dist_remove_product') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $spId = intval($body['session_product_id'] ?? 0);
        if (!$spId) respond(['error' => 'Не указан товар'], 400);
        $sg = $pdo->prepare("SELECT s.legal_entity_group, s.id AS session_id,
                COALESCE(sp.custom_sku, p.sku) AS article, COALESCE(sp.custom_name, p.name) AS pname
            FROM dist_session_products sp
            JOIN dist_sessions s ON s.id=sp.session_id
            LEFT JOIN products p ON p.id = sp.product_id
            WHERE sp.id=?"); $sg->execute([$spId]);
        $sgRow = $sg->fetch();
        if ($sgRow === false) respond(['error' => 'Позиция не найдена'], 404);
        $sgVal = $sgRow['legal_entity_group'];
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        distHistoryLog($pdo, $sgRow['session_id'], $sgVal ?: 'BK_VM', 'product_removed', $authUserName, [
            'detail' => trim(($sgRow['article'] ?? '') . ' ' . ($sgRow['pname'] ?? '')),
        ]);
        $pdo->prepare("DELETE FROM dist_session_products WHERE id=?")->execute([$spId]);
        respond(['success' => true]);
    }

    // Массовая установка статусов/qty при импорте из Excel. Один многострочный
    // INSERT — на 5000 строк это в десятки раз быстрее N отдельных запросов.
    // entries: [{session_product_id, restaurant_number, shipped?, qty?}, ...]
    // Если не передан shipped — оставляем как есть (через COALESCE).
    if ($fn === 'dist_bulk_set_qty') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $sessionId = intval($body['session_id'] ?? 0);
        $entries = $body['entries'] ?? [];
        if (!$sessionId || empty($entries)) respond(['error' => 'Нет данных'], 400);
        if (count($entries) > 5000) respond(['error' => 'Слишком много записей (макс. 5000)'], 400);
        $sg = $pdo->prepare("SELECT legal_entity_group FROM dist_sessions WHERE id=?"); $sg->execute([$sessionId]);
        $sgVal = $sg->fetchColumn();
        if ($sgVal === false) respond(['error' => 'Сессия не найдена'], 404);
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);

        // Проверяем, что все session_product_id принадлежат этой сессии (защита от подмены чужих).
        $spIds = array_unique(array_map(fn($e) => (int)$e['session_product_id'], $entries));
        if (in_array(0, $spIds, true)) respond(['error' => 'Невалидный session_product_id'], 400);
        $ph = implode(',', array_fill(0, count($spIds), '?'));
        $ck = $pdo->prepare("SELECT COUNT(*) FROM dist_session_products WHERE session_id=? AND id IN ($ph)");
        $ck->execute(array_merge([$sessionId], array_values($spIds)));
        if ((int)$ck->fetchColumn() !== count($spIds)) respond(['error' => 'Некоторые позиции не принадлежат этой сессии'], 400);

        // Разделяем на 2 группы — те что меняют статус, и те что только qty.
        // Это нужно потому что в одном multi-row INSERT нельзя выборочно
        // обновлять колонки по строкам: UPDATE-часть применяется ко всем.
        $withStatus = []; // INSERT ... shipped и qty
        $qtyOnly = [];    // INSERT ... shipped=0 для NEW, в UPDATE shipped НЕ ТРОГАЕМ
        foreach ($entries as $e) {
            $spId = (int)($e['session_product_id'] ?? 0);
            $rn = (string)($e['restaurant_number'] ?? '');
            $qty = $e['qty'] ?? null;
            $hasShipped = array_key_exists('shipped', $e) && $e['shipped'] !== null;
            if (!$spId || $rn === '') continue;
            if ($hasShipped) {
                $withStatus[] = [$spId, $rn, (int)$e['shipped'], $qty];
            } else {
                $qtyOnly[] = [$spId, $rn, $qty];
            }
        }
        if (empty($withStatus) && empty($qtyOnly)) respond(['error' => 'Нет валидных записей'], 400);

        $totalUpdated = 0;
        if (!empty($withStatus)) {
            $ph = []; $params = [];
            foreach ($withStatus as [$spId, $rn, $shipped, $qty]) {
                $ph[] = '(?, ?, ?, ?, ?, ?, 1)';
                array_push($params, $spId, $rn, $shipped, $qty, $authUserName, $authUserName);
            }
            $sql = "INSERT INTO dist_entries (session_product_id, restaurant_number, shipped, qty, created_by, updated_by, version) VALUES "
                . implode(',', $ph)
                . " ON DUPLICATE KEY UPDATE shipped = VALUES(shipped), qty = VALUES(qty), updated_at = NOW(), updated_by = VALUES(updated_by), version = version + 1";
            $pdo->prepare($sql)->execute($params);
            $totalUpdated += count($withStatus);
        }
        if (!empty($qtyOnly)) {
            // Только qty: для новых строк shipped=0 (нейтрально). При обновлении
            // существующих не трогаем shipped — пользователь сам поставит ✓ когда
            // отгрузит. Это просит UX: «хочу залить количество отдельно от статуса».
            $ph = []; $params = [];
            foreach ($qtyOnly as [$spId, $rn, $qty]) {
                $ph[] = '(?, ?, 0, ?, ?, ?, 1)';
                array_push($params, $spId, $rn, $qty, $authUserName, $authUserName);
            }
            $sql = "INSERT INTO dist_entries (session_product_id, restaurant_number, shipped, qty, created_by, updated_by, version) VALUES "
                . implode(',', $ph)
                . " ON DUPLICATE KEY UPDATE qty = VALUES(qty), updated_at = NOW(), updated_by = VALUES(updated_by), version = version + 1";
            $pdo->prepare($sql)->execute($params);
            $totalUpdated += count($qtyOnly);
        }
        distHistoryLog($pdo, $sessionId, $sgVal ?: 'BK_VM', 'cell_bulk_import', $authUserName, [
            'detail' => 'Импорт из Excel: ' . $totalUpdated . ' клеток',
        ]);
        respond(['success' => true, 'updated' => $totalUpdated]);
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
        $s = $pdo->prepare("INSERT INTO dist_notes (session_id, restaurant_number, note, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE note = VALUES(note), updated_by = VALUES(updated_by)");
        $s->execute([$sessionId, $restNum, $note, $authUserName, $authUserName]);
        distHistoryLog($pdo, $sessionId, $sgVal ?: 'BK_VM', 'note_saved', $authUserName, [
            'restNum' => $restNum,
            'detail' => $note === '' ? 'очищено' : mb_substr($note, 0, 200),
        ]);
        respond(['success' => true]);
    }

    // Массовая отметка отгрузки для группы ресторанов. Один многострочный
    // INSERT вместо N круговых трипов (было до ~57 запросов в сети, стало 1).
    if ($fn === 'dist_bulk_toggle') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $spId = intval($body['session_product_id'] ?? 0);
        $restaurantNumbers = $body['restaurant_numbers'] ?? [];
        $shipped = isset($body['shipped']) ? (int)$body['shipped'] : 1;
        if (!$spId || empty($restaurantNumbers)) respond(['error' => 'Нет данных'], 400);
        if (count($restaurantNumbers) > 5000) respond(['error' => 'Слишком много ресторанов в одном запросе (макс. 5000)'], 400);
        $sg = $pdo->prepare("SELECT s.legal_entity_group, s.id AS session_id FROM dist_session_products sp JOIN dist_sessions s ON s.id=sp.session_id WHERE sp.id=?"); $sg->execute([$spId]);
        $sgRow = $sg->fetch();
        if ($sgRow === false) respond(['error' => 'Позиция не найдена'], 404);
        $sgVal = $sgRow['legal_entity_group'];
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);

        $placeholders = [];
        $params = [];
        foreach ($restaurantNumbers as $rn) {
            $placeholders[] = '(?, ?, ?, ?, ?, 1)';
            array_push($params, $spId, (string)$rn, $shipped, $authUserName, $authUserName);
        }
        $sql = "INSERT INTO dist_entries (session_product_id, restaurant_number, shipped, created_by, updated_by, version) VALUES "
            . implode(',', $placeholders)
            . " ON DUPLICATE KEY UPDATE shipped = VALUES(shipped), updated_at = NOW(), updated_by = VALUES(updated_by), version = version + 1";
        $pdo->prepare($sql)->execute($params);
        distHistoryLog($pdo, $sgRow['session_id'], $sgVal ?: 'BK_VM', 'cell_bulk_shipped', $authUserName, [
            'spId' => $spId,
            'new' => distShippedLabel($shipped),
            'detail' => count($restaurantNumbers) . ' ресторанов',
        ]);
        respond(['success' => true, 'updated' => count($restaurantNumbers)]);
    }

    // Сводка распределения: по всем АКТИВНЫМ сессиям группы юр. лиц собирает
    // неотгруженные позиции с кол-вом > 0 + рестораны с их днями доставки.
    // Группировка (день → хранение → ресторан → товары) делается на фронте.
    if ($fn === 'dist_get_overview') {
        requireModuleAccess($authUser, 'distribution', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $legalEntity = $_GET['legal_entity'] ?? $body['legal_entity'] ?? null;
        if ($legalEntity && !checkLegalEntityAccess($authUser, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $group = $legalEntity ? getEntityGroup($legalEntity) : 'BK_VM';

        // Активные сессии группы
        $s = $pdo->prepare("SELECT id, name FROM dist_sessions WHERE legal_entity_group = ? AND status = 'active' ORDER BY created_at");
        $s->execute([$group]);
        $sessions = $s->fetchAll();

        if (empty($sessions)) {
            respond(['sessions' => [], 'products' => [], 'entries' => [], 'restaurants' => []]);
        }
        $sessionIds = array_column($sessions, 'id');
        $ph = implode(',', array_fill(0, count($sessionIds), '?'));

        // Товары всех активных сессий (это «строки» матрицы). Распределение
        // идёт по ВСЕМ ресторанам группы: пустая клетка = «ещё надо отдать».
        $prodStmt = $pdo->prepare("
            SELECT sp.id AS session_product_id, sp.session_id, sp.unit, sp.default_qty,
                   COALESCE(sp.custom_name, p.name) AS product_name,
                   COALESCE(sp.custom_sku, p.sku) AS article,
                   p.external_code, p.category
            FROM dist_session_products sp
            LEFT JOIN products p ON p.id = sp.product_id
            WHERE sp.session_id IN ($ph)
        ");
        $prodStmt->execute(array_values($sessionIds));
        $products = $prodStmt->fetchAll();

        // Дообогащение «ручных» товаров: позиция добавлена кастомно (без привязки
        // к карточке), но в справочнике есть товар с таким же артикулом — тогда
        // подтягиваем внешний код и условие хранения по артикулу.
        $needSku = [];
        foreach ($products as $r) {
            if (empty($r['external_code']) && !empty($r['article'])) $needSku[(string)$r['article']] = true;
        }
        if (!empty($needSku)) {
            $skuList = array_keys($needSku);
            $sph = implode(',', array_fill(0, count($skuList), '?'));
            $pl = $pdo->prepare("SELECT sku, external_code, category FROM products
                                 WHERE legal_entity_group = ? AND sku IN ($sph) AND external_code IS NOT NULL AND external_code <> ''");
            $pl->execute(array_merge([$group], $skuList));
            $skuMap = [];
            foreach ($pl->fetchAll() as $p) {
                if (!isset($skuMap[$p['sku']])) $skuMap[$p['sku']] = $p; // первый по артикулу
            }
            foreach ($products as &$r) {
                if (empty($r['external_code']) && !empty($r['article']) && isset($skuMap[(string)$r['article']])) {
                    $r['external_code'] = $skuMap[(string)$r['article']]['external_code'];
                    if (empty($r['category'])) $r['category'] = $skuMap[(string)$r['article']]['category'];
                }
            }
            unset($r);
        }

        // Отметки по клеткам: ✓ отгружено (shipped=1) / ✗ не нужно (shipped=2)
        // и переопределения количества. Пустых клеток тут нет — их фронт считает
        // «надо отдать» (по стандартному количеству товара).
        $spIds = array_column($products, 'session_product_id');
        $entries = [];
        if (!empty($spIds)) {
            $eph = implode(',', array_fill(0, count($spIds), '?'));
            $es = $pdo->prepare("SELECT session_product_id, restaurant_number, shipped, qty
                                 FROM dist_entries
                                 WHERE session_product_id IN ($eph)
                                   AND (shipped <> 0 OR (qty IS NOT NULL AND TRIM(qty) NOT IN ('', '0')))");
            $es->execute(array_values($spIds));
            $entries = $es->fetchAll();
        }

        // Рестораны группы + дни доставки. dist_entries ссылается на номер
        // ресторана, а delivery_schedule — на id; связываем через restaurants.
        $rs = $pdo->prepare("SELECT id, number, address, city FROM restaurants WHERE active = 1 AND legal_entity_group = ? ORDER BY CAST(number AS UNSIGNED)");
        $rs->execute([$group]);
        $restaurants = $rs->fetchAll();

        $restIds = array_column($restaurants, 'id');
        $deliveryByNumber = [];
        if (!empty($restIds)) {
            $rph = implode(',', array_fill(0, count($restIds), '?'));
            $ds = $pdo->prepare("SELECT restaurant_id, day_of_week FROM delivery_schedule WHERE restaurant_id IN ($rph) ORDER BY day_of_week");
            $ds->execute(array_values($restIds));
            $byId = [];
            foreach ($ds->fetchAll() as $d) $byId[$d['restaurant_id']][] = intval($d['day_of_week']);
            foreach ($restaurants as $r) {
                $deliveryByNumber[(string)$r['number']] = $byId[$r['id']] ?? [];
            }
        }
        $out = [];
        foreach ($restaurants as $r) {
            $out[] = [
                'number'        => $r['number'],
                'address'       => $r['address'],
                'city'          => $r['city'],
                'delivery_days' => $deliveryByNumber[(string)$r['number']] ?? [],
            ];
        }

        respond(['sessions' => $sessions, 'products' => $products, 'entries' => $entries, 'restaurants' => $out]);
    }

    // История действий по одной сессии распределения. Отдаётся порциями
    // (свежие сверху). Товар/ресторан подтягиваются на чтении — так строка
    // остаётся читаемой даже если позицию потом удалили из сессии.
    if ($fn === 'dist_get_history') {
        requireModuleAccess($authUser, 'distribution', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $sessionId = intval($body['session_id'] ?? 0);
        if (!$sessionId) respond(['error' => 'session_id обязателен'], 400);
        $sg = $pdo->prepare("SELECT legal_entity_group FROM dist_sessions WHERE id=?"); $sg->execute([$sessionId]);
        $sgVal = $sg->fetchColumn();
        if ($sgVal === false) respond(['error' => 'Сессия не найдена'], 404);
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);

        $limit = intval($body['limit'] ?? 100);
        if ($limit < 1) $limit = 100;
        if ($limit > 500) $limit = 500;
        $offset = intval($body['offset'] ?? 0);
        if ($offset < 0) $offset = 0;

        $tot = $pdo->prepare("SELECT COUNT(*) FROM dist_history WHERE session_id=?");
        $tot->execute([$sessionId]);
        $total = (int)$tot->fetchColumn();

        $hs = $pdo->prepare("SELECT h.id, h.action, h.session_product_id, h.restaurant_number,
                    h.old_value, h.new_value, h.detail, h.user_name, h.created_at,
                    COALESCE(sp.custom_sku, p.sku) AS article,
                    COALESCE(sp.custom_name, p.name) AS product_name
                FROM dist_history h
                LEFT JOIN dist_session_products sp ON sp.id = h.session_product_id
                LEFT JOIN products p ON p.id = sp.product_id
                WHERE h.session_id = ?
                ORDER BY h.id DESC
                LIMIT " . (int)$limit . " OFFSET " . (int)$offset);
        $hs->execute([$sessionId]);
        respond(['rows' => $hs->fetchAll(), 'total' => $total]);
    }
