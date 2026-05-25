<?php
/**
 * RPC: корректировки заказов (correction_*).
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName,
 * $ROLE_TEMPLATES, $ACCESS_LEVELS, $clientIp.
 */

    if ($fn === 'correction_take_batch') {
        requireModuleAccess($authUser, 'corrections', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $ids = $body['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) respond(['error' => 'Нет идентификаторов'], 400);
        $ids = array_values(array_filter(array_map('intval', $ids), function($v) { return $v > 0; }));
        if (!$ids) respond(['error' => 'Нет валидных id'], 400);

        $caller = getSessionUser($pdo);
        $callerName = $caller['name'] ?? 'unknown';
        $callerChatId = $caller['telegram_chat_id'] ?? null;

        // Проверка доступа к группе юр.лиц (берём по первой записи).
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $accSt = $pdo->prepare("SELECT DISTINCT legal_entity_group FROM order_corrections WHERE id IN ($ph)");
        $accSt->execute($ids);
        $groups = $accSt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($groups as $g) {
            if (!checkLegalEntityGroupAccess($caller, $g)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        }

        // Атомарно — только из статуса pending.
        $upd = $pdo->prepare("UPDATE order_corrections SET status = 'in_progress', reviewer_chat_id = ?, reviewer_name = ? WHERE id IN ($ph) AND status = 'pending'");
        $upd->execute(array_merge([$callerChatId, $callerName], $ids));
        $taken = $upd->rowCount();

        // Освежаем TG-сообщения у закупок — там перерисуются текст и кнопки.
        try {
            require_once __DIR__ . '/bot_rest.php';
            corrUpdateAllReviewMessages($pdo, $ids);
        } catch (\Throwable $e) {
            error_log('[correction_take_batch] tg refresh failed: ' . $e->getMessage());
        }

        auditLog($pdo, 'correction_taken', 'correction', implode(',', $ids), $callerName, ['count' => $taken]);
        respond(['success' => true, 'taken' => $taken]);
    }

    if ($fn === 'correction_review') {
        requireModuleAccess($authUser, 'corrections', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['id'] ?? 0);
        $action = $body['action'] ?? '';
        $comment = trim($body['comment'] ?? '');
        if (!$id || !in_array($action, ['approve', 'reject'])) respond(['error' => 'Неверные параметры'], 400);

        $caller = getSessionUser($pdo);
        $callerName = $caller['name'] ?? 'unknown';

        // Доступ — на уровне группы юрлиц (BK_VM или PS).
        $accCheck = $pdo->prepare("SELECT legal_entity_group FROM order_corrections WHERE id = ?");
        $accCheck->execute([$id]);
        $corrGroup = $accCheck->fetchColumn();
        if ($corrGroup === false) respond(['error' => 'Корректировка не найдена'], 404);
        if (!checkLegalEntityGroupAccess($caller, $corrGroup)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);

        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        $callerChatId = $caller['telegram_chat_id'] ?? null;
        $upd = $pdo->prepare("UPDATE order_corrections SET status = ?, reviewer_chat_id = ?, reviewer_name = ?, review_comment = ?, reviewed_at = NOW() WHERE id = ? AND status IN ('pending', 'in_progress')");
        $upd->execute([$newStatus, $callerChatId, $callerName, $comment ?: null, $id]);
        if ($upd->rowCount() === 0) respond(['error' => 'Уже обработано']);

        $corr = $pdo->prepare("SELECT * FROM order_corrections WHERE id = ?");
        $corr->execute([$id]);
        $c = $corr->fetch();
        if (!$c) respond(['error' => 'Не найдено'], 404);

        // Определяем батч: для кабинетных — по batch_uuid, для TG-старых — по (restaurant, date, chat_id).
        if (!empty($c['batch_uuid'])) {
            $batchSt = $pdo->prepare("SELECT id FROM order_corrections WHERE batch_uuid = ? ORDER BY id");
            $batchSt->execute([$c['batch_uuid']]);
        } else {
            $batchSt = $pdo->prepare("SELECT id FROM order_corrections WHERE restaurant_number = ? AND delivery_date = ? AND restaurant_chat_id = ? ORDER BY id");
            $batchSt->execute([$c['restaurant_number'], $c['delivery_date'], $c['restaurant_chat_id']]);
        }
        $batchIds = array_map('intval', $batchSt->fetchAll(PDO::FETCH_COLUMN));

        // Освежаем сообщения у закупок (это работает даже если батч ещё не закрыт целиком),
        // и пытаемся отправить итог ресторану (функция сама вернётся, если ещё есть pending/in_progress).
        try {
            require_once __DIR__ . '/bot_rest.php';
            if ($batchIds) {
                corrUpdateAllReviewMessages($pdo, $batchIds);
                corrSendResultToRestaurant($pdo, $batchIds, $callerName);
            }
        } catch (\Throwable $e) {
            error_log('[correction_review] notify failed: ' . $e->getMessage());
        }

        auditLog($pdo, 'correction_reviewed', 'correction', $id, $callerName, ['action' => $action, 'restaurant' => $c['restaurant_number'], 'product' => $c['product_name']]);
        respond(['success' => true]);
    }

    if ($fn === 'correction_review_batch') {
        requireModuleAccess($authUser, 'corrections', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $ids = $body['ids'] ?? [];
        $action = $body['action'] ?? '';
        $comment = trim($body['comment'] ?? '');
        if (empty($ids) || !is_array($ids) || !in_array($action, ['approve', 'reject'])) respond(['error' => 'Неверные параметры'], 400);

        $caller = getSessionUser($pdo);
        $callerName = $caller['name'] ?? 'unknown';
        $callerChatId = $caller['telegram_chat_id'] ?? null;
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';

        // Доступ — все корректировки в батче должны быть в группе юрлиц пользователя.
        $intIds = array_map('intval', $ids);
        $ph = implode(',', array_fill(0, count($intIds), '?'));
        $accStmt = $pdo->prepare("SELECT DISTINCT legal_entity_group FROM order_corrections WHERE id IN ({$ph})");
        $accStmt->execute($intIds);
        $groups = $accStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$groups) respond(['error' => 'Корректировки не найдены'], 404);
        foreach ($groups as $g) {
            if (!checkLegalEntityGroupAccess($caller, $g)) respond(['error' => 'Нет доступа к одной из корректировок'], 403);
        }

        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("UPDATE order_corrections SET status = ?, reviewer_chat_id = ?, reviewer_name = ?, review_comment = ?, reviewed_at = NOW() WHERE id IN ({$ph}) AND status IN ('pending', 'in_progress')");
            $upd->execute(array_merge([$newStatus, $callerChatId, $callerName, $comment ?: null], $intIds));
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(['error' => 'Ошибка обновления'], 500);
        }

        // Перерисовываем TG-сообщения у закупок и отправляем итог ресторану
        // через единую функцию (push + TG всем верифицированным сотрудникам).
        try {
            require_once __DIR__ . '/bot_rest.php';
            // Группируем по batch_uuid (для кабинетных), и по (restaurant, date, chat_id) — для TG-старых.
            $first = $pdo->prepare("SELECT batch_uuid, restaurant_number, delivery_date, restaurant_chat_id FROM order_corrections WHERE id = ?");
            $first->execute([intval($ids[0])]);
            $c = $first->fetch();
            if ($c) {
                if (!empty($c['batch_uuid'])) {
                    $batchSt = $pdo->prepare("SELECT id FROM order_corrections WHERE batch_uuid = ? ORDER BY id");
                    $batchSt->execute([$c['batch_uuid']]);
                } else {
                    $batchSt = $pdo->prepare("SELECT id FROM order_corrections WHERE restaurant_number = ? AND delivery_date = ? AND restaurant_chat_id = ? ORDER BY id");
                    $batchSt->execute([$c['restaurant_number'], $c['delivery_date'], $c['restaurant_chat_id']]);
                }
                $batchIds = array_map('intval', $batchSt->fetchAll(PDO::FETCH_COLUMN));
                if ($batchIds) {
                    corrUpdateAllReviewMessages($pdo, $batchIds);
                    corrSendResultToRestaurant($pdo, $batchIds, $callerName, $comment ?: null);
                }
            }
        } catch (\Throwable $e) {
            error_log('[correction_review_batch] notify failed: ' . $e->getMessage());
        }

        auditLog($pdo, 'correction_reviewed', 'correction', implode(',', $ids), $callerName, ['action' => $action, 'count' => count($ids)]);
        respond(['success' => true, 'updated' => count($ids)]);
    }

    if ($fn === 'correction_delete') {
        requireModuleAccess($authUser, 'corrections', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $ids = $body['ids'] ?? [];
        if (empty($ids)) respond(['error' => 'Нет ID'], 400);
        $ids = array_map('intval', $ids);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        // Доступ — все корректировки в батче должны быть в группе юрлиц пользователя.
        $accStmt = $pdo->prepare("SELECT DISTINCT legal_entity_group FROM order_corrections WHERE id IN ({$ph})");
        $accStmt->execute($ids);
        $groups = $accStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$groups) respond(['error' => 'Корректировки не найдены'], 404);
        foreach ($groups as $g) {
            if (!checkLegalEntityGroupAccess($authUser, $g)) respond(['error' => 'Нет доступа к одной из корректировок'], 403);
        }
        $pdo->prepare("DELETE FROM order_corrections WHERE id IN ({$ph})")->execute($ids);
        respond(['success' => true]);
    }

    if ($fn === 'correction_clear_all') {
        requireModuleAccess($authUser, 'corrections', 'full', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $pdo->exec("DELETE FROM order_corrections");
        auditLog($pdo, 'corrections_cleared', 'correction', null, $authUserName, ['scope' => 'all']);
        respond(['success' => true]);
    }

    if ($fn === 'correction_clear_processed') {
        requireModuleAccess($authUser, 'corrections', 'full', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $cnt = $pdo->exec("DELETE FROM order_corrections WHERE status IN ('approved', 'rejected')");
        auditLog($pdo, 'corrections_cleared', 'correction', null, $authUserName, ['scope' => 'processed', 'count' => $cnt]);
        respond(['success' => true, 'deleted' => $cnt]);
    }

    if ($fn === 'correction_get_settings') {
        // Telegram-настройки уведомлений других пользователей — только для тех,
        // кто работает с корректировками (не отдавать API-ключам и роли viewer).
        requireModuleAccess($authUser, 'corrections', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $st = $pdo->query("SELECT u.name, ts.correction_notifications FROM users u JOIN telegram_settings ts ON ts.user_name = u.name WHERE u.telegram_chat_id IS NOT NULL ORDER BY u.name");
        respond($st->fetchAll());
    }

    if ($fn === 'correction_toggle_notification') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $userName = $body['user_name'] ?? '';
        if (!$userName) respond(['error' => 'user_name required'], 400);
        // Менять можно только свои настройки или админу — чужие.
        if ($userName !== ($caller['name'] ?? '') && ($caller['role'] ?? '') !== 'admin') {
            respond(['error' => 'Нет прав менять чужие настройки'], 403);
        }
        $pdo->prepare("UPDATE telegram_settings SET correction_notifications = NOT correction_notifications WHERE user_name = ?")->execute([$userName]);
        respond(['success' => true]);
    }
