<?php
/**
 * RPC: тендеры и маркетинговые активности.
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName,
 * $ROLE_TEMPLATES, $ACCESS_LEVELS, $clientIp.
 */

    if ($fn === 'save_tender') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $perms = resolvePermissions($caller['role'] ?? 'user', $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        $tendersLevel = $ACCESS_LEVELS[$perms['tenders'] ?? 'none'] ?? 0;
        if ($tendersLevel < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);

        $tenderId = intval($body['id'] ?? 0);
        $name = trim($body['name'] ?? '');
        $description = $body['description'] ?? null;
        $le = $body['legal_entity'] ?? '';
        $statusInput = $body['status'] ?? 'draft';
        $allowedStatuses = ['draft', 'in_progress', 'completed', 'cancelled'];
        if (!in_array($statusInput, $allowedStatuses, true)) respond(['error' => 'Недопустимый статус'], 400);
        $deadline = $body['deadline'] ?? null;
        $winnerSupplierInput = $body['winner_supplier'] ?? null;
        $summary = $body['summary'] ?? null;
        $note = $body['note'] ?? null;
        $items = $body['items'] ?? [];
        $offers = $body['offers'] ?? [];

        if (!$name) respond(['error' => 'Укажите название тендера'], 400);
        if (!$le) respond(['error' => 'Не указано юрлицо'], 400);
        if (!checkLegalEntityAccess($caller, $le)) respond(['error' => 'Нет доступа к юр. лицу'], 403);

        // Закрытие тендера и выбор/смена победителя — только full (admin/manager)
        $oldStatus = null;
        $oldWinner = null;
        if ($tenderId) {
            $cur = $pdo->prepare("SELECT status, winner_supplier FROM tenders WHERE id=? AND legal_entity=?");
            $cur->execute([$tenderId, $le]);
            $curRow = $cur->fetch();
            if (!$curRow) respond(['error' => 'Тендер не найден'], 404);
            $oldStatus = $curRow['status'];
            $oldWinner = $curRow['winner_supplier'];
        }
        $isClosing = $statusInput === 'completed' && $oldStatus !== 'completed';
        $winnerChanged = ($winnerSupplierInput ?? '') !== ($oldWinner ?? '');
        if (($isClosing || $winnerChanged) && $tendersLevel < $ACCESS_LEVELS['full']) {
            respond(['error' => 'Закрытие тендера и выбор победителя — только для администратора/менеджера'], 403);
        }
        // При создании тендер всегда стартует как draft, без победителя
        $status = $tenderId ? $statusInput : 'draft';
        $winnerSupplier = $tenderId ? $winnerSupplierInput : null;

        $pdo->beginTransaction();
        try {
            if ($tenderId) {
                $pdo->prepare("UPDATE tenders SET name=?, description=?, status=?, deadline=?, winner_supplier=?, summary=?, note=?, updated_at=NOW() WHERE id=? AND legal_entity=?")
                    ->execute([$name, $description, $status, $deadline, $winnerSupplier, $summary, $note, $tenderId, $le]);
            } else {
                $pdo->prepare("INSERT INTO tenders (name, description, legal_entity, status, deadline, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$name, $description, $le, $status, $deadline, $note, $caller['name'] ?? '']);
                $tenderId = $pdo->lastInsertId();
            }

            // Позиции: удалить старые, вставить новые
            $pdo->prepare("DELETE FROM tender_items WHERE tender_id=?")->execute([$tenderId]);
            $itemIdMap = [];
            foreach ($items as $i => $item) {
                $mc = isset($item['monthly_consumption']) && $item['monthly_consumption'] !== null ? floatval($item['monthly_consumption']) : null;
                $pdo->prepare("INSERT INTO tender_items (tender_id, name, sku, quantity, unit, monthly_consumption, sort_order, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$tenderId, $item['name'] ?? '', $item['sku'] ?? null, $item['quantity'] ?? null, $item['unit'] ?? null, $mc, $i, $item['note'] ?? null]);
                $itemIdMap[$i] = $pdo->lastInsertId();
            }

            // Предложения: удалить старые, вставить новые
            $pdo->prepare("DELETE FROM tender_offers WHERE tender_id=?")->execute([$tenderId]);
            foreach ($offers as $offer) {
                $pdo->prepare("INSERT INTO tender_offers (tender_id, supplier, delivery_days, payment_terms, conditions, note) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$tenderId, $offer['supplier'] ?? '', $offer['delivery_days'] ?? null, $offer['payment_terms'] ?? null, $offer['conditions'] ?? null, $offer['note'] ?? null]);
                $offerId = $pdo->lastInsertId();
                $prices = $offer['prices'] ?? [];
                $pricesRub = $offer['prices_rub'] ?? [];
                $pricesByn = $offer['prices_byn'] ?? [];
                foreach ($prices as $idx => $price) {
                    if (!isset($itemIdMap[$idx])) continue;
                    $priceRub = isset($pricesRub[$idx]) && $pricesRub[$idx] !== null ? floatval($pricesRub[$idx]) : null;
                    $priceByn = isset($pricesByn[$idx]) && $pricesByn[$idx] !== null ? floatval($pricesByn[$idx]) : null;
                    $pdo->prepare("INSERT INTO tender_offer_prices (offer_id, item_id, price, price_rub, price_byn) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$offerId, $itemIdMap[$idx], $price, $priceRub, $priceByn]);
                }
            }

            $pdo->commit();
            $isNew = !intval($body['id'] ?? 0);
            $auditDetails = ['name' => $name, 'legal_entity' => $le, 'status' => $status];
            if (!$isNew) {
                if ($oldStatus !== $status) $auditDetails['status_change'] = ['from' => $oldStatus, 'to' => $status];
                if (($oldWinner ?? '') !== ($winnerSupplier ?? '')) $auditDetails['winner_change'] = ['from' => $oldWinner, 'to' => $winnerSupplier];
            }
            auditLog($pdo, $isNew ? 'tender_created' : 'tender_updated', 'tender', $tenderId, $caller['name'], $auditDetails);
            respond(['success' => true, 'id' => intval($tenderId)]);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('save_tender error: ' . $e->getMessage());
            respond(['error' => 'Ошибка сохранения тендера'], 500);
        }
    }

    // ═══ Тендеры: загрузить тендер со всеми данными ═══
    if ($fn === 'get_tender') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'tenders', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'Не указан ID'], 400);

        $s = $pdo->prepare("SELECT * FROM tenders WHERE id=?"); $s->execute([$id]);
        $tender = $s->fetch();
        if (!$tender) respond(['error' => 'Тендер не найден'], 404);
        if (!checkLegalEntityAccess($caller, $tender['legal_entity'])) respond(['error' => 'Нет доступа'], 403);

        // Позиции
        $s = $pdo->prepare("SELECT * FROM tender_items WHERE tender_id=? ORDER BY sort_order"); $s->execute([$id]);
        $items = $s->fetchAll();
        // Подтянуть расход из analysis_data по SKU (с учётом аналогов)
        $skus = array_filter(array_column($items, 'sku'));
        $consumptionMap = [];
        if (!empty($skus)) {
            // Найти группы аналогов для всех SKU позиций
            $ph = implode(',', array_fill(0, count($skus), '?'));
            $s = $pdo->prepare("SELECT sku, analog_group FROM products WHERE sku IN ($ph) AND analog_group IS NOT NULL AND analog_group != ''");
            $s->execute($skus);
            $skuToGroup = [];
            $groups = [];
            foreach ($s->fetchAll() as $row) {
                $skuToGroup[$row['sku']] = $row['analog_group'];
                $groups[$row['analog_group']] = true;
            }
            // Найти все SKU аналогов
            $allSkusForQuery = $skus;
            $groupToSkus = [];
            if (!empty($groups)) {
                $gph = implode(',', array_fill(0, count($groups), '?'));
                $s = $pdo->prepare("SELECT sku, analog_group FROM products WHERE analog_group IN ($gph)");
                $s->execute(array_keys($groups));
                foreach ($s->fetchAll() as $row) {
                    $groupToSkus[$row['analog_group']][] = $row['sku'];
                    $allSkusForQuery[] = $row['sku'];
                }
            }
            $allSkusForQuery = array_values(array_unique($allSkusForQuery));
            // Загрузить расход по всем SKU (основные + аналоги)
            $ph2 = implode(',', array_fill(0, count($allSkusForQuery), '?'));
            $s = $pdo->prepare("SELECT sku, consumption, period_days FROM analysis_data WHERE sku IN ($ph2) AND legal_entity = ?");
            $s->execute(array_merge($allSkusForQuery, [$tender['legal_entity']]));
            $adMap = [];
            foreach ($s->fetchAll() as $row) {
                $daily = ($row['period_days'] > 0) ? $row['consumption'] / $row['period_days'] : 0;
                $adMap[$row['sku']] = $daily;
            }
            // Суммировать расход: основной SKU + все аналоги из группы
            foreach ($skus as $sku) {
                $totalDaily = 0;
                if (isset($skuToGroup[$sku]) && isset($groupToSkus[$skuToGroup[$sku]])) {
                    foreach ($groupToSkus[$skuToGroup[$sku]] as $gs) {
                        $totalDaily += $adMap[$gs] ?? 0;
                    }
                } else {
                    $totalDaily = $adMap[$sku] ?? 0;
                }
                $consumptionMap[$sku] = $totalDaily > 0 ? round($totalDaily * 30, 1) : null;
            }
        }
        foreach ($items as &$item) {
            // Если сохранён ручной расход — использовать его, иначе подтянуть автоматически
            if ($item['monthly_consumption'] !== null) {
                $item['monthly_consumption'] = floatval($item['monthly_consumption']);
                $item['consumption_auto'] = $item['sku'] ? ($consumptionMap[$item['sku']] ?? null) : null;
            } else {
                $item['monthly_consumption'] = $item['sku'] ? ($consumptionMap[$item['sku']] ?? null) : null;
                $item['consumption_auto'] = $item['monthly_consumption'];
            }
        }
        unset($item);
        $tender['items'] = $items;

        // Предложения + цены: один запрос вместо N+1.
        // Раньше для каждого предложения делался отдельный SELECT в tender_offer_prices.
        // На тендере с 20 предложениями = 20 запросов; теперь — 2 (offers + одна выборка цен).
        $s = $pdo->prepare("SELECT id, tender_id, supplier, delivery_days, payment_terms, conditions, note, created_at FROM tender_offers WHERE tender_id=? ORDER BY id"); $s->execute([$id]);
        $offers = $s->fetchAll();
        if ($offers) {
            $offerIds = array_column($offers, 'id');
            $ph = implode(',', array_fill(0, count($offerIds), '?'));
            $sp = $pdo->prepare("SELECT offer_id, item_id, price, price_rub, price_byn FROM tender_offer_prices WHERE offer_id IN ($ph)");
            $sp->execute($offerIds);
            $pricesByOffer = [];
            foreach ($sp->fetchAll() as $row) {
                $oid = $row['offer_id'];
                unset($row['offer_id']);
                $pricesByOffer[$oid][] = $row;
            }
            foreach ($offers as &$offer) {
                $offer['prices'] = $pricesByOffer[$offer['id']] ?? [];
            }
            unset($offer);
        }
        $tender['offers'] = $offers;

        // Файлы КП
        $s = $pdo->prepare("SELECT id, supplier, file_name, file_path, uploaded_at FROM tender_files WHERE tender_id=? ORDER BY uploaded_at"); $s->execute([$id]);
        $tender['files'] = $s->fetchAll();

        // Курс валют
        $rateStmt = $pdo->prepare("SELECT value FROM settings WHERE `key`='rub_to_byn_rate'");
        $rateStmt->execute();
        $tender['rub_to_byn_rate'] = floatval($rateStmt->fetchColumn() ?: '0.0375');

        respond($tender);
    }

    // ═══ Тендеры: удалить тендер ═══
    if ($fn === 'delete_tender') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $perms = resolvePermissions($caller['role'] ?? 'user', $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['tenders'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['full']) respond(['error' => 'Недостаточно прав'], 403);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'Не указан ID'], 400);
        $s = $pdo->prepare("SELECT legal_entity FROM tenders WHERE id=?"); $s->execute([$id]);
        $le = $s->fetchColumn();
        if (!$le) respond(['error' => 'Тендер не найден'], 404);
        if (!checkLegalEntityAccess($caller, $le)) respond(['error' => 'Нет доступа'], 403);
        // Собираем пути файлов до DELETE (после CASCADE строки уже исчезнут),
        // но сами unlink делаем ПОСЛЕ успешного DELETE, чтобы не остаться с
        // удалёнными файлами и неудалённой записью.
        $fs = $pdo->prepare("SELECT file_path FROM tender_files WHERE tender_id=?"); $fs->execute([$id]);
        $filePaths = $fs->fetchAll(PDO::FETCH_COLUMN);
        // CASCADE удалит items, offers, offer_prices, files
        $pdo->prepare("DELETE FROM tenders WHERE id=?")->execute([$id]);
        foreach ($filePaths as $fp) {
            $fpath = __DIR__ . '/../uploads/tenders/' . basename($fp);
            if (file_exists($fpath)) @unlink($fpath);
        }
        auditLog($pdo, 'tender_deleted', 'tender', $id, $caller['name'], ['legal_entity' => $le]);
        respond(['success' => true]);
    }

    // ═══ Маркетинг: сохранить активность ═══
    if ($fn === 'save_marketing_activity') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $perms = resolvePermissions($caller['role'] ?? 'user', $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['marketing'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);

        $actId = intval($body['id'] ?? 0);
        $name = trim($body['name'] ?? '');
        $type = $body['type'] ?? 'promo';
        $status = $body['status'] ?? 'active';
        $dateFrom = $body['date_from'] ?? null;
        $dateTo = $body['date_to'] ?? null;
        $le = $body['legal_entity'] ?? '';
        $restaurantCount = isset($body['restaurant_count']) ? intval($body['restaurant_count']) : null;
        $note = $body['note'] ?? null;
        $stages = isset($body['stages']) && is_array($body['stages']) ? json_encode($body['stages'], JSON_UNESCAPED_UNICODE) : null;
        $items = $body['items'] ?? [];

        if (!$name) respond(['error' => 'Укажите название'], 400);
        if (!$le) respond(['error' => 'Не указано юрлицо'], 400);
        if (!checkLegalEntityAccess($caller, $le)) respond(['error' => 'Нет доступа к юр. лицу'], 403);

        $pdo->beginTransaction();
        try {
            if ($actId) {
                $pdo->prepare("UPDATE marketing_activities SET name=?, type=?, status=?, date_from=?, date_to=?, restaurant_count=?, note=?, stages=?, updated_at=NOW() WHERE id=? AND legal_entity=?")
                    ->execute([$name, $type, $status, $dateFrom, $dateTo, $restaurantCount, $note, $stages, $actId, $le]);
            } else {
                $pdo->prepare("INSERT INTO marketing_activities (name, type, status, date_from, date_to, legal_entity, restaurant_count, note, stages, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$name, $type, $status, $dateFrom, $dateTo, $le, $restaurantCount, $note, $stages, $caller['name'] ?? '']);
                $actId = $pdo->lastInsertId();
            }

            $pdo->prepare("DELETE FROM marketing_activity_items WHERE activity_id=?")->execute([$actId]);
            foreach ($items as $i => $item) {
                $auvPeriods = isset($item['auv_periods']) && is_array($item['auv_periods']) ? json_encode($item['auv_periods']) : null;
                $subItems = isset($item['sub_items']) && is_array($item['sub_items']) ? json_encode($item['sub_items'], JSON_UNESCAPED_UNICODE) : null;
                $pdo->prepare("INSERT INTO marketing_activity_items (activity_id, product_id, sku, name, calc_method, auv, auv_periods, sub_items, total_volume, fixed_qty, unit, sort_order, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([
                        $actId,
                        $item['product_id'] ?? null,
                        $item['sku'] ?? null,
                        $item['name'] ?? '',
                        $item['calc_method'] ?? 'auv',
                        $item['auv'] ?? null,
                        $auvPeriods,
                        $subItems,
                        $item['total_volume'] ?? null,
                        $item['fixed_qty'] ?? null,
                        $item['unit'] ?? 'шт',
                        $i,
                        $item['note'] ?? null,
                    ]);
            }

            $pdo->commit();
            $isNew = !intval($body['id'] ?? 0);
            auditLog($pdo, $isNew ? 'marketing_created' : 'marketing_updated', 'marketing', $actId, $caller['name'], ['name' => $name, 'legal_entity' => $le, 'type' => $type]);
            respond(['success' => true, 'id' => intval($actId)]);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('save_marketing_activity error: ' . $e->getMessage());
            respond(['error' => 'Ошибка сохранения'], 500);
        }
    }

    // ═══ Маркетинг: загрузить активность ═══
    if ($fn === 'get_marketing_activity') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'marketing', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'Не указан ID'], 400);

        $s = $pdo->prepare("SELECT * FROM marketing_activities WHERE id=?"); $s->execute([$id]);
        $act = $s->fetch();
        if (!$act) respond(['error' => 'Активность не найдена'], 404);
        if (!checkLegalEntityAccess($caller, $act['legal_entity'])) respond(['error' => 'Нет доступа'], 403);

        $s = $pdo->prepare("SELECT * FROM marketing_activity_items WHERE activity_id=? ORDER BY sort_order"); $s->execute([$id]);
        $act['items'] = $s->fetchAll();

        $s = $pdo->prepare("SELECT * FROM marketing_activity_files WHERE activity_id=? ORDER BY uploaded_at"); $s->execute([$id]);
        $act['files'] = $s->fetchAll();

        respond($act);
    }

    // ═══ Маркетинг: удалить активность ═══
    if ($fn === 'delete_marketing_activity') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $perms = resolvePermissions($caller['role'] ?? 'user', $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['marketing'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['full']) respond(['error' => 'Недостаточно прав'], 403);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'Не указан ID'], 400);
        $s = $pdo->prepare("SELECT legal_entity FROM marketing_activities WHERE id=?"); $s->execute([$id]);
        $le = $s->fetchColumn();
        if (!$le) respond(['error' => 'Не найдена'], 404);
        if (!checkLegalEntityAccess($caller, $le)) respond(['error' => 'Нет доступа'], 403);
        // Собираем пути до DELETE; unlink — после успешного DELETE.
        $fs = $pdo->prepare("SELECT file_path FROM marketing_activity_files WHERE activity_id=?"); $fs->execute([$id]);
        $filePaths = $fs->fetchAll(PDO::FETCH_COLUMN);
        $pdo->prepare("DELETE FROM marketing_activities WHERE id=?")->execute([$id]);
        foreach ($filePaths as $fp) {
            $fpath = __DIR__ . '/../uploads/marketing/' . basename($fp);
            if (file_exists($fpath)) @unlink($fpath);
        }
        auditLog($pdo, 'marketing_deleted', 'marketing', $id, $caller['name'], ['legal_entity' => $le]);
        respond(['success' => true]);
    }
