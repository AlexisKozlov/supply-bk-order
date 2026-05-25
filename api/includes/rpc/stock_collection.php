<?php
/**
 * RPC: сбор остатков по ресторанам (sc_*).
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName,
 * $ROLE_TEMPLATES, $ACCESS_LEVELS, $clientIp.
 */

    if ($fn === 'sc_create_collection') {
        requireModuleAccess($authUser, 'stock-collection', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $le = $body['legal_entity'] ?? '';
        $name = mb_substr($body['name'] ?? '', 0, 255);
        $products = $body['products'] ?? []; // [{name, sku?, unit}]
        $uname = $authUserName ?: ($body['user_name'] ?? '');
        if (!$le || !$name || empty($products)) respond(['error' => 'Не все параметры указаны'], 400);
        if (!checkLegalEntityAccess($authUser, $le)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        if (count($products) > 5000) respond(['error' => 'Слишком много товаров (макс. 5000)'], 400);
        $hasNeedExpiry = dbColumnExists($pdo, 'stock_collection_products', 'need_expiry');
        $hasNote = dbColumnExists($pdo, 'stock_collection_products', 'note');
        $hasPrice = dbColumnExists($pdo, 'stock_collection_products', 'price');
        $pdo->beginTransaction();
        try {
            // legal_entity — кто создал (для аудита), legal_entity_group —
            // область видимости (BK+VM делят сборы, PS отдельно).
            $s = $pdo->prepare("INSERT INTO stock_collections (legal_entity, legal_entity_group, name, created_by) VALUES (?, ?, ?, ?)");
            $s->execute([$le, getEntityGroup($le), $name, $uname]);
            $collId = $pdo->lastInsertId();
            $productCols = ['collection_id', 'product_name', 'product_sku', 'unit'];
            if ($hasPrice) $productCols[] = 'price';
            if ($hasNeedExpiry) $productCols[] = 'need_expiry';
            $productCols[] = 'sort_order';
            if ($hasNote) $productCols[] = 'note';
            $productPlaceholders = implode(', ', array_fill(0, count($productCols), '?'));
            $ins = $pdo->prepare("INSERT INTO stock_collection_products (" . implode(', ', $productCols) . ") VALUES ({$productPlaceholders})");
            foreach ($products as $i => $p) {
                $pname = mb_substr($p['name'] ?? '', 0, 255);
                $psku = mb_substr($p['sku'] ?? '', 0, 50) ?: null;
                $punit = in_array($p['unit'] ?? '', ['boxes', 'pieces', 'kg', 'liters']) ? $p['unit'] : 'pieces';
                $pneedExpiry = !empty($p['need_expiry']) ? 1 : 0;
                $pnote = mb_substr($p['note'] ?? '', 0, 500) ?: null;
                $pprice = null;
                if ($hasPrice && isset($p['price']) && $p['price'] !== '' && $p['price'] !== null) {
                    $normalized = str_replace([',', ' '], ['.', ''], (string)$p['price']);
                    if (is_numeric($normalized)) $pprice = round((float)$normalized, 4);
                }
                $params = [$collId, $pname, $psku, $punit];
                if ($hasPrice) $params[] = $pprice;
                if ($hasNeedExpiry) $params[] = $pneedExpiry;
                $params[] = $i;
                if ($hasNote) $params[] = $pnote;
                $ins->execute($params);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('sc_create_collection error: ' . $e->getMessage());
            respond(['error' => 'Ошибка создания сбора'], 500);
        }
        // Уведомляем рестораны о новом сборе (только Telegram-бот, без публичной ссылки)
        scNotifyRestaurants($pdo, $collId, $name, count($products));

        auditLog($pdo, 'collection_created', 'stock_collection', $collId, $uname, ['legal_entity' => $le, 'name' => $name, 'products_count' => count($products)]);
        respond(['id' => $collId]);
    }

    // Повторная отправка уведомлений ресторанам о сборе
    if ($fn === 'sc_notify_restaurants') {
        requireModuleAccess($authUser, 'stock-collection', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $collId = intval($body['collection_id'] ?? 0);
        if (!$collId) respond(['error' => 'collection_id required'], 400);
        $col = $pdo->prepare("SELECT name, status FROM stock_collections WHERE id = ?");
        $col->execute([$collId]);
        $c = $col->fetch();
        if (!$c) respond(['error' => 'Не найден'], 404);
        if ($c['status'] !== 'active') respond(['error' => 'Сбор закрыт']);
        $products = $pdo->prepare("SELECT COUNT(*) FROM stock_collection_products WHERE collection_id = ?");
        $products->execute([$collId]);
        $cnt = $products->fetchColumn();
        $sent = scNotifyRestaurants($pdo, $collId, $c['name'], $cnt);
        respond(['success' => true, 'sent' => $sent]);
    }

    if ($fn === 'sc_save_prices') {
        requireModuleAccess($authUser, 'stock-collection', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $collId = intval($body['collection_id'] ?? 0);
        if (!$collId) respond(['error' => 'collection_id required'], 400);
        $prices = is_array($body['prices'] ?? null) ? $body['prices'] : [];

        // Проверка доступа к сбору по группе юрлиц.
        $collCheck = $pdo->prepare("SELECT legal_entity_group FROM stock_collections WHERE id = ?");
        $collCheck->execute([$collId]);
        $collRow = $collCheck->fetch();
        if (!$collRow) respond(['error' => 'Коллекция не найдена'], 404);
        if ($authUser && !checkLegalEntityGroupAccess($authUser, $collRow['legal_entity_group'])) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);

        $upd = $pdo->prepare("UPDATE stock_collection_products SET price = ? WHERE id = ? AND collection_id = ?");
        $updated = 0;
        foreach ($prices as $row) {
            $pid = intval($row['product_id'] ?? 0);
            if (!$pid) continue;
            // Пустая строка / null → стираем цену (NULL). Иначе принудительно DECIMAL.
            $raw = $row['price'] ?? null;
            if ($raw === '' || $raw === null) {
                $price = null;
            } else {
                // На фронте часто разделитель — запятая. Приводим к точке для float.
                $normalized = str_replace([',', ' '], ['.', ''], (string)$raw);
                if (!is_numeric($normalized)) continue;
                $price = round((float)$normalized, 4);
                if ($price < 0) continue;
            }
            $upd->execute([$price, $pid, $collId]);
            $updated += $upd->rowCount();
        }
        respond(['success' => true, 'updated' => $updated]);
    }

    if ($fn === 'sc_close_collection') {
        requireModuleAccess($authUser, 'stock-collection', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $collId = intval($body['collection_id'] ?? 0);
        if (!$collId) respond(['error' => 'Не все параметры указаны'], 400);
        // Доступ к сбору — на уровне группы юрлиц (BK_VM или PS).
        $collCheck = $pdo->prepare("SELECT legal_entity, legal_entity_group FROM stock_collections WHERE id = ?");
        $collCheck->execute([$collId]);
        $collRow = $collCheck->fetch();
        if (!$collRow) respond(['error' => 'Коллекция не найдена'], 404);
        if ($authUser && !checkLegalEntityGroupAccess($authUser, $collRow['legal_entity_group'])) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $pdo->prepare("UPDATE stock_collections SET status = 'closed', closed_at = NOW() WHERE id = ?")->execute([$collId]);
        auditLog($pdo, 'collection_closed', 'stock_collection', $collId, $authUserName, ['legal_entity' => $collRow['legal_entity']]);
        respond(['success' => true]);
    }
    if ($fn === 'sc_reopen_collection') {
        requireModuleAccess($authUser, 'stock-collection', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $collId = intval($body['collection_id'] ?? 0);
        $uname = $authUserName ?: ($body['user_name'] ?? '');
        if (!$collId) respond(['error' => 'Не все параметры указаны'], 400);
        $collCheck = $pdo->prepare("SELECT id, name, legal_entity, legal_entity_group, status FROM stock_collections WHERE id = ?");
        $collCheck->execute([$collId]);
        $collRow = $collCheck->fetch();
        if (!$collRow) respond(['error' => 'Коллекция не найдена'], 404);
        if ($authUser && !checkLegalEntityGroupAccess($authUser, $collRow['legal_entity_group'])) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        if ($collRow['status'] === 'active') respond(['success' => true, 'already_active' => true]);

        $pdo->prepare("UPDATE stock_collections SET status = 'active', closed_at = NULL WHERE id = ?")->execute([$collId]);
        auditLog($pdo, 'collection_reopened', 'stock_collection', $collId, $uname, ['legal_entity' => $collRow['legal_entity']]);
        respond(['success' => true]);
    }
    if ($fn === 'sc_delete_collection') {
        requireModuleAccess($authUser, 'stock-collection', 'full', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $collId = intval($body['collection_id'] ?? 0);
        if (!$collId) respond(['error' => 'Не все параметры указаны'], 400);
        $collCheck = $pdo->prepare("SELECT id, name, legal_entity, legal_entity_group FROM stock_collections WHERE id = ?");
        $collCheck->execute([$collId]);
        $collRow = $collCheck->fetch();
        if (!$collRow) respond(['error' => 'Коллекция не найдена'], 404);
        if ($authUser && !checkLegalEntityGroupAccess($authUser, $collRow['legal_entity_group'])) respond(['error' => 'Нет доступа'], 403);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM stock_collection_data WHERE collection_id = ?")->execute([$collId]);
            $pdo->prepare("DELETE FROM stock_collection_products WHERE collection_id = ?")->execute([$collId]);
            $pdo->prepare("DELETE FROM stock_collections WHERE id = ?")->execute([$collId]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(['error' => 'Ошибка удаления'], 500);
        }
        auditLog($pdo, 'collection_deleted', 'stock_collection', $collId, $authUserName, ['name' => $collRow['name']]);
        respond(['success' => true]);
    }

    if ($fn === 'sc_get_collection_data') {
        requireModuleAccess($authUser, 'stock-collection', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $collId = intval($body['collection_id'] ?? 0);
        if (!$collId) respond(['error' => 'Не все параметры указаны'], 400);
        // Доступ к сбору — на уровне группы юрлиц (BK+VM делят сборы, PS отдельно).
        $collCheck = $pdo->prepare("SELECT legal_entity_group FROM stock_collections WHERE id = ?");
        $collCheck->execute([$collId]);
        $collRow = $collCheck->fetch();
        if (!$collRow) respond(['error' => 'Коллекция не найдена'], 404);
        if ($authUser && !checkLegalEntityGroupAccess($authUser, $collRow['legal_entity_group'])) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        // Товары
        $hasNeedExpiry = dbColumnExists($pdo, 'stock_collection_products', 'need_expiry');
        $hasNote = dbColumnExists($pdo, 'stock_collection_products', 'note');
        $hasPrice = dbColumnExists($pdo, 'stock_collection_products', 'price');
        $productCols = ['id', 'product_name', 'product_sku', 'unit'];
        if ($hasPrice) $productCols[] = 'price';
        if ($hasNeedExpiry) $productCols[] = 'need_expiry';
        $productCols[] = 'sort_order';
        if ($hasNote) $productCols[] = 'note';
        $s = $pdo->prepare("SELECT " . implode(', ', $productCols) . " FROM stock_collection_products WHERE collection_id = ? ORDER BY sort_order");
        $s->execute([$collId]);
        $products = $s->fetchAll();
        // Данные
        $hasExpiryDate = dbColumnExists($pdo, 'stock_collection_data', 'expiry_date');
        $dataCols = ['id', 'product_id', 'restaurant_number'];
        if ($hasExpiryDate) $dataCols[] = 'expiry_date';
        $dataCols[] = 'stock';
        $dataCols[] = 'source';
        $dataCols[] = 'submitted_at';
        $orderBy = $hasExpiryDate
            ? 'ORDER BY restaurant_number, product_id, expiry_date, id'
            : 'ORDER BY restaurant_number, product_id, id';
        $s2 = $pdo->prepare("SELECT " . implode(', ', $dataCols) . " FROM stock_collection_data WHERE collection_id = ? {$orderBy}");
        $s2->execute([$collId]);
        $data = $s2->fetchAll();
        if (!$hasExpiryDate) {
            foreach ($data as &$row) {
                $row['expiry_date'] = null;
            }
            unset($row);
        }
        if (!$hasNeedExpiry) {
            foreach ($products as &$row) {
                $row['need_expiry'] = 0;
            }
            unset($row);
        }
        // Ответы по ресторанам
        $s3 = $pdo->prepare("SELECT DISTINCT restaurant_number FROM stock_collection_data WHERE collection_id = ?");
        $s3->execute([$collId]);
        $restaurants = array_column($s3->fetchAll(), 'restaurant_number');
        respond(['products' => $products, 'data' => $data, 'restaurants' => $restaurants]);
    }

    if ($fn === 'sc_save_collection_cell') {
        requireModuleAccess($authUser, 'stock-collection', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $collId = intval($body['collection_id'] ?? 0);
        $productId = intval($body['product_id'] ?? 0);
        $restaurantNumber = trim((string)($body['restaurant_number'] ?? ''));
        $batches = $body['batches'] ?? null;
        if ($batches === null && array_key_exists('stock', $body)) {
            $batches = [['stock' => $body['stock'], 'expiry_date' => $body['expiry_date'] ?? null]];
        }
        if (!$collId || !$productId || $restaurantNumber === '' || $batches === null) {
            respond(['error' => 'Не все параметры указаны'], 400);
        }

        $collCheck = $pdo->prepare("SELECT id, legal_entity, legal_entity_group, status FROM stock_collections WHERE id = ?");
        $collCheck->execute([$collId]);
        $collRow = $collCheck->fetch();
        if (!$collRow) respond(['error' => 'Сбор не найден'], 404);
        if ($collRow['status'] !== 'active') respond(['error' => 'Сбор закрыт'], 400);
        if (!checkLegalEntityGroupAccess($authUser, $collRow['legal_entity_group'])) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);

        $productCheck = $pdo->prepare("SELECT id, need_expiry FROM stock_collection_products WHERE id = ? AND collection_id = ?");
        $productCheck->execute([$productId, $collId]);
        $productRow = $productCheck->fetch();
        if (!$productRow) respond(['error' => 'Товар не входит в этот сбор'], 400);

        $group = $collRow['legal_entity_group'];
        $restaurantCheck = $pdo->prepare("SELECT id FROM restaurants WHERE number = ? AND legal_entity_group = ? LIMIT 1");
        $restaurantCheck->execute([$restaurantNumber, $group]);
        if (!$restaurantCheck->fetch()) respond(['error' => 'Ресторан не найден в выбранном юрлице'], 400);

        try {
            $hasExpiryDate = dbColumnExists($pdo, 'stock_collection_data', 'expiry_date');
            $normalized = rpcNormalizeStockCollectionBatches($batches, $hasExpiryDate);
            // Если ничего не введено — считаем, что остатков нет (0 без срока)
            if (!$normalized) {
                $normalized = [['expiry_date' => null, 'stock' => 0.0]];
            }
            if (!empty($productRow['need_expiry'])) {
                foreach ($normalized as $batch) {
                    // Срок обязателен только если остаток > 0
                    if (empty($batch['expiry_date']) && (float)$batch['stock'] > 0) {
                        respond(['error' => 'Для этой позиции нужно указать срок годности (или поставьте остаток 0)'], 400);
                    }
                }
            }
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM stock_collection_data WHERE collection_id = ? AND product_id = ? AND restaurant_number = ?")->execute([$collId, $productId, $restaurantNumber]);
            if ($hasExpiryDate) {
                $stmt = $pdo->prepare("
                    INSERT INTO stock_collection_data (collection_id, product_id, restaurant_number, expiry_date, stock, source, submitted_at)
                    VALUES (?, ?, ?, ?, ?, 'manual', NOW())
                ");
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO stock_collection_data (collection_id, product_id, restaurant_number, stock, source, submitted_at)
                    VALUES (?, ?, ?, ?, 'manual', NOW())
                ");
            }
            $savedIds = [];
            foreach ($normalized as $batch) {
                if ($hasExpiryDate) {
                    $stmt->execute([$collId, $productId, $restaurantNumber, $batch['expiry_date'], $batch['stock']]);
                } else {
                    $stmt->execute([$collId, $productId, $restaurantNumber, $batch['stock']]);
                }
                $savedIds[] = (int)$pdo->lastInsertId();
            }
            $pdo->commit();
            if ($hasExpiryDate) {
                $idStmt = $pdo->prepare("SELECT id, product_id, restaurant_number, expiry_date, stock, source, submitted_at FROM stock_collection_data WHERE collection_id = ? AND product_id = ? AND restaurant_number = ? ORDER BY expiry_date, id");
            } else {
                $idStmt = $pdo->prepare("SELECT id, product_id, restaurant_number, stock, source, submitted_at FROM stock_collection_data WHERE collection_id = ? AND product_id = ? AND restaurant_number = ? ORDER BY id");
            }
            $idStmt->execute([$collId, $productId, $restaurantNumber]);
            $item = $idStmt->fetchAll();
            if (!$hasExpiryDate) {
                foreach ($item as &$row) {
                    $row['expiry_date'] = null;
                }
                unset($row);
            }
            auditLog($pdo, 'stock_collection_cell_saved', 'stock_collection', $collId, $authUserName, [
                'product_id' => $productId,
                'restaurant_number' => $restaurantNumber,
                'batches' => $normalized,
                'source' => 'manual',
            ]);
            respond(['success' => true, 'items' => $item]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('sc_save_collection_cell error: ' . $e->getMessage());
            respond(['error' => 'Ошибка сохранения'], 500);
        }
    }
