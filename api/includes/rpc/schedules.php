<?php
/**
 * RPC: расписания поставок и подписки (supplier_schedule + main_delivery).
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName,
 * $ROLE_TEMPLATES, $ACCESS_LEVELS, $clientIp.
 */

    if ($fn === 'list_supplier_schedules') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'supplier-schedule', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        // Группа юр.лица (BK_VM или PS) — обязательный контекст, чтобы не смешивать
        // данные разных групп (поставщики Пицца Стар не должны попадать к БК и наоборот).
        $group = trim((string)($body['legal_entity_group'] ?? ''));
        if (!in_array($group, ['BK_VM', 'PS'], true)) respond(['error' => 'Требуется legal_entity_group (BK_VM или PS)'], 400);

        // Фильтр по юр.лицам пользователя (legal_entities приходит JSON-строкой)
        $userEntities = $caller['legal_entities'] ?? null;
        if (is_string($userEntities)) $userEntities = json_decode($userEntities, true);
        $entityWhere = '';
        $entityParams = [];
        if (is_array($userEntities) && !empty($userEntities)) {
            $ph = implode(',', array_fill(0, count($userEntities), '?'));
            $entityWhere = " AND r.legal_entity IN ($ph) ";
            $entityParams = $userEntities;
        }

        $rows = $pdo->prepare("
            SELECT ss.id AS schedule_id, ss.supplier_id, ss.restaurant_id,
                   ss.order_day, ss.delivery_day, ss.is_active,
                   s.short_name AS supplier_name, s.so_enabled,
                   s.legal_entity_group AS supplier_group,
                   r.number AS restaurant_number, r.city AS restaurant_city, r.address AS restaurant_address,
                   r.legal_entity, r.legal_entity_group AS restaurant_group,
                   sd.deadline_time AS deadline_override,
                   sd.reminder_times AS reminder_times_override
            FROM supplier_schedules ss
            JOIN suppliers s ON s.id = ss.supplier_id
            JOIN restaurants r ON r.id = ss.restaurant_id
            LEFT JOIN supplier_schedule_deadlines sd
                   ON sd.supplier_id = ss.supplier_id
                  AND sd.restaurant_id = ss.restaurant_id
                  AND sd.order_day = ss.order_day
            WHERE s.is_active = 1 AND r.active = 1
              AND s.legal_entity_group = ?
              AND r.legal_entity_group = ?
              $entityWhere
            ORDER BY s.short_name, r.number, ss.order_day
        ");
        $rows->execute(array_merge([$group, $group], $entityParams));
        $schedules = $rows->fetchAll();

        // Дефолтные дедлайны: supplier_default_deadlines
        $supplierIds = array_values(array_unique(array_column($schedules, 'supplier_id')));
        $defaults = [];
        if ($supplierIds) {
            $ph = implode(',', array_fill(0, count($supplierIds), '?'));
            $s = $pdo->prepare("
                SELECT supplier_id, delivery_dow, deadline_dow, deadline_time, reminder_times
                FROM supplier_default_deadlines
                WHERE supplier_id IN ($ph)
                ORDER BY supplier_id, delivery_dow
            ");
            $s->execute($supplierIds);
            foreach ($s->fetchAll() as $r) {
                $defaults[$r['supplier_id']][] = $r;
            }
        }

        // Подписки ресторанов на напоминания + Telegram-подписчики (для индикатора в UI)
        // Структура: subscriptions[supplier_id][restaurant_id] = { is_enabled, telegram_enabled, tg_names: [..] }
        $subscriptions = [];
        if ($supplierIds) {
            $ph = implode(',', array_fill(0, count($supplierIds), '?'));
            $subStmt = $pdo->prepare("
                SELECT sub.id, sub.restaurant_id, sub.supplier_id,
                       sub.is_enabled, sub.telegram_enabled
                FROM restaurant_reminder_subscriptions sub
                WHERE sub.supplier_id IN ($ph)
            ");
            $subStmt->execute($supplierIds);
            $subById = [];
            foreach ($subStmt->fetchAll() as $r) {
                $subId = (int)$r['id'];
                $subById[$subId] = $r;
                $subscriptions[$r['supplier_id']][(int)$r['restaurant_id']] = [
                    'is_enabled' => (int)$r['is_enabled'] === 1,
                    'telegram_enabled' => (int)$r['telegram_enabled'] === 1,
                    'tg_names' => [],
                ];
            }
            // Имена tg-подписчиков
            if ($subById) {
                $sIds = array_keys($subById);
                $sph = implode(',', array_fill(0, count($sIds), '?'));
                $tgStmt = $pdo->prepare("
                    SELECT rrts.subscription_id, rts.first_name, rts.username
                    FROM restaurant_reminder_tg_subscribers rrts
                    JOIN ro_telegram_subs rts ON rts.id = rrts.ro_tg_sub_id
                    WHERE rrts.subscription_id IN ($sph) AND rrts.is_active = 1 AND rts.verified_at IS NOT NULL
                ");
                $tgStmt->execute($sIds);
                foreach ($tgStmt->fetchAll() as $t) {
                    $subInfo = $subById[(int)$t['subscription_id']];
                    $name = $t['first_name'] ?: ($t['username'] ? '@' . $t['username'] : 'tg');
                    $subscriptions[$subInfo['supplier_id']][(int)$subInfo['restaurant_id']]['tg_names'][] = $name;
                }
            }
        }

        respond([
            'rows' => $schedules,
            'default_deadlines' => $defaults,
            'subscriptions' => $subscriptions,
        ]);
    }

    if ($fn === 'list_supplier_schedule_directory') {
        // Справочники для модалки: поставщики и рестораны, доступные пользователю
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'supplier-schedule', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $group = trim((string)($body['legal_entity_group'] ?? ''));
        if (!in_array($group, ['BK_VM', 'PS'], true)) respond(['error' => 'Требуется legal_entity_group (BK_VM или PS)'], 400);

        $userEntities = $caller['legal_entities'] ?? null;
        if (is_string($userEntities)) $userEntities = json_decode($userEntities, true);
        $entityWhereR = '';
        $entityParamsR = [];
        if (is_array($userEntities) && !empty($userEntities)) {
            $ph = implode(',', array_fill(0, count($userEntities), '?'));
            $entityWhereR = " AND legal_entity IN ($ph) ";
            $entityParamsR = $userEntities;
        }

        $sup = $pdo->prepare("
            SELECT id, short_name, so_enabled, legal_entity_group, order_url
            FROM suppliers
            WHERE is_active = 1 AND legal_entity_group = ?
            ORDER BY short_name
        ");
        $sup->execute([$group]);
        $sup = $sup->fetchAll();

        $restStmt = $pdo->prepare("
            SELECT id, number, city, address, legal_entity, legal_entity_group
            FROM restaurants
            WHERE active = 1 AND legal_entity_group = ? $entityWhereR
            ORDER BY number
        ");
        $restStmt->execute(array_merge([$group], $entityParamsR));
        $rests = $restStmt->fetchAll();

        respond([
            'suppliers' => $sup,
            'restaurants' => $rests,
        ]);
    }

    // Подписки ресторанов на напоминания об ОСНОВНОЙ поставке — для индикатора
    // в DeliveryScheduleView (страница «График доставки»). Структура аналогична
    // subscriptions из list_supplier_schedules, но без supplier_id (у основной
    // поставки только одна подписка на ресторан).
    if ($fn === 'list_main_delivery_subscriptions') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'delivery-schedule', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $group = trim((string)($body['legal_entity_group'] ?? ''));
        if (!in_array($group, ['BK_VM', 'PS'], true)) respond(['error' => 'Требуется legal_entity_group (BK_VM или PS)'], 400);

        // Фильтр по доступным пользователю юр.лицам.
        $userEntities = $caller['legal_entities'] ?? null;
        if (is_string($userEntities)) $userEntities = json_decode($userEntities, true);
        $entityWhere = '';
        $entityParams = [];
        if (is_array($userEntities) && !empty($userEntities)) {
            $ph = implode(',', array_fill(0, count($userEntities), '?'));
            $entityWhere = " AND r.legal_entity IN ($ph) ";
            $entityParams = $userEntities;
        }

        $subStmt = $pdo->prepare("
            SELECT sub.id, sub.restaurant_id, sub.is_enabled, sub.telegram_enabled
            FROM restaurant_main_delivery_subscriptions sub
            JOIN restaurants r ON r.id = sub.restaurant_id
            WHERE r.legal_entity_group = ?
              AND r.active = 1
              $entityWhere
        ");
        $subStmt->execute(array_merge([$group], $entityParams));

        $subscriptions = [];
        $subById = [];
        foreach ($subStmt->fetchAll() as $r) {
            $subId = (int)$r['id'];
            $subById[$subId] = $r;
            $subscriptions[(int)$r['restaurant_id']] = [
                'is_enabled' => (int)$r['is_enabled'] === 1,
                'telegram_enabled' => (int)$r['telegram_enabled'] === 1,
                'tg_names' => [],
            ];
        }

        // Имена выбранных Telegram-подписчиков для каждой подписки.
        if ($subById) {
            $sIds = array_keys($subById);
            $sph = implode(',', array_fill(0, count($sIds), '?'));
            $tgStmt = $pdo->prepare("
                SELECT rmts.subscription_id, rts.first_name, rts.username
                FROM restaurant_main_delivery_tg_subscribers rmts
                JOIN ro_telegram_subs rts ON rts.id = rmts.ro_tg_sub_id
                WHERE rmts.subscription_id IN ($sph)
                  AND rmts.is_active = 1
                  AND rts.verified_at IS NOT NULL
            ");
            $tgStmt->execute($sIds);
            foreach ($tgStmt->fetchAll() as $t) {
                $subInfo = $subById[(int)$t['subscription_id']];
                $name = $t['first_name'] ?: ($t['username'] ? '@' . $t['username'] : 'tg');
                $subscriptions[(int)$subInfo['restaurant_id']]['tg_names'][] = $name;
            }
        }

        respond(['subscriptions' => $subscriptions]);
    }

    if ($fn === 'save_supplier_schedule_row') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'supplier-schedule', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $supplierId = trim((string)($body['supplier_id'] ?? ''));
        $restaurantId = (int)($body['restaurant_id'] ?? 0);
        $orderDay = (int)($body['order_day'] ?? 0);
        $deliveryDay = (int)($body['delivery_day'] ?? 0);
        $isActive = !empty($body['is_active']) ? 1 : 0;
        $deadlineTime = isset($body['deadline_time']) && $body['deadline_time'] !== '' ? $body['deadline_time'] : null;
        $reminderTimes = isset($body['reminder_times']) ? $body['reminder_times'] : null;

        if (!$supplierId || !$restaurantId
            || !in_array($orderDay, [1,2,3,4,5,6,7], true)
            || !in_array($deliveryDay, [1,2,3,4,5,6,7], true)) {
            respond(['error' => 'Некорректные данные'], 400);
        }

        // Валидация reminder_times: массив объектов { days_before:0..7, time:'HH:MM' }
        $reminderTimesJson = null;
        if ($reminderTimes !== null) {
            if (!is_array($reminderTimes)) respond(['error' => 'reminder_times должен быть массивом'], 400);
            $cleaned = [];
            foreach ($reminderTimes as $rt) {
                if (!is_array($rt)) continue;
                $db = (int)($rt['days_before'] ?? -1);
                $t = $rt['time'] ?? '';
                if ($db < 0 || $db > 7) respond(['error' => 'days_before должен быть 0..7'], 400);
                if (!preg_match('/^\d{1,2}:\d{2}$/', $t)) respond(['error' => 'Некорректный формат времени напоминания'], 400);
                $cleaned[] = ['days_before' => $db, 'time' => $t];
            }
            $reminderTimesJson = $cleaned ? json_encode($cleaned, JSON_UNESCAPED_UNICODE) : null;
        }

        // Проверка существования поставщика и доступа к юр.лицу ресторана
        $supStmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
        $supStmt->execute([$supplierId]);
        if (!$supStmt->fetchColumn()) respond(['error' => 'Поставщик не найден'], 404);

        $restStmt = $pdo->prepare("SELECT legal_entity FROM restaurants WHERE id = ?");
        $restStmt->execute([$restaurantId]);
        $rest = $restStmt->fetch();
        if (!$rest) respond(['error' => 'Ресторан не найден'], 404);
        if (!checkLegalEntityAccess($caller, $rest['legal_entity'])) {
            respond(['error' => 'Нет доступа к юр.лицу ресторана'], 403);
        }

        if ($deadlineTime !== null) {
            // Принимаем HH:MM или HH:MM:SS
            if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $deadlineTime)) {
                respond(['error' => 'Некорректное время дедлайна'], 400);
            }
            if (strlen($deadlineTime) === 5) $deadlineTime .= ':00';
        }

        $updatedBy = resolveActorName($pdo, $caller);

        $pdo->prepare("
            INSERT INTO supplier_schedules (supplier_id, restaurant_id, order_day, delivery_day, is_active, updated_at, updated_by)
            VALUES (?, ?, ?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE
                delivery_day = VALUES(delivery_day),
                is_active = VALUES(is_active),
                updated_at = NOW(),
                updated_by = VALUES(updated_by)
        ")->execute([$supplierId, $restaurantId, $orderDay, $deliveryDay, $isActive, $updatedBy]);

        if ($deadlineTime !== null || $reminderTimesJson !== null) {
            $pdo->prepare("
                INSERT INTO supplier_schedule_deadlines (supplier_id, restaurant_id, order_day, deadline_time, reminder_times, updated_at, updated_by)
                VALUES (?, ?, ?, ?, ?, NOW(), ?)
                ON DUPLICATE KEY UPDATE
                    deadline_time = VALUES(deadline_time),
                    reminder_times = VALUES(reminder_times),
                    updated_at = NOW(),
                    updated_by = VALUES(updated_by)
            ")->execute([$supplierId, $restaurantId, $orderDay, $deadlineTime, $reminderTimesJson, $updatedBy]);
        } else {
            $pdo->prepare("DELETE FROM supplier_schedule_deadlines WHERE supplier_id = ? AND restaurant_id = ? AND order_day = ?")
                ->execute([$supplierId, $restaurantId, $orderDay]);
        }

        respond(['success' => true]);
    }

    if ($fn === 'save_supplier_default_deadline') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'supplier-schedule', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $supplierId = trim((string)($body['supplier_id'] ?? ''));
        $deliveryDow = (int)($body['delivery_dow'] ?? 0);
        $deadlineDow = (int)($body['deadline_dow'] ?? 0);
        $deadlineTime = $body['deadline_time'] ?? '';
        $reminderTimes = $body['reminder_times'] ?? null;

        if (!$supplierId
            || !in_array($deliveryDow, [1,2,3,4,5,6,7], true)
            || !in_array($deadlineDow, [1,2,3,4,5,6,7], true)
            || !preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $deadlineTime)) {
            respond(['error' => 'Некорректные данные'], 400);
        }
        if (strlen($deadlineTime) === 5) $deadlineTime .= ':00';

        // Валидация reminder_times
        $reminderTimesJson = null;
        if ($reminderTimes !== null) {
            if (!is_array($reminderTimes)) respond(['error' => 'reminder_times должен быть массивом'], 400);
            $cleaned = [];
            foreach ($reminderTimes as $rt) {
                if (!is_array($rt)) continue;
                $db = (int)($rt['days_before'] ?? -1);
                $t = $rt['time'] ?? '';
                if ($db < 0 || $db > 7) respond(['error' => 'days_before должен быть 0..7'], 400);
                if (!preg_match('/^\d{1,2}:\d{2}$/', $t)) respond(['error' => 'Некорректный формат времени'], 400);
                $cleaned[] = ['days_before' => $db, 'time' => $t];
            }
            $reminderTimesJson = $cleaned ? json_encode($cleaned, JSON_UNESCAPED_UNICODE) : null;
        }

        $supStmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
        $supStmt->execute([$supplierId]);
        if (!$supStmt->fetchColumn()) respond(['error' => 'Поставщик не найден'], 404);

        // Уникальный ключ — (supplier_id, delivery_dow). Перезаписываем правило.
        $exists = $pdo->prepare("SELECT id FROM supplier_default_deadlines WHERE supplier_id = ? AND delivery_dow = ?");
        $exists->execute([$supplierId, $deliveryDow]);
        $id = $exists->fetchColumn();
        if ($id) {
            $pdo->prepare("UPDATE supplier_default_deadlines SET deadline_dow = ?, deadline_time = ?, reminder_times = ? WHERE id = ?")
                ->execute([$deadlineDow, $deadlineTime, $reminderTimesJson, $id]);
        } else {
            $pdo->prepare("INSERT INTO supplier_default_deadlines (supplier_id, delivery_dow, deadline_dow, deadline_time, reminder_times) VALUES (?, ?, ?, ?, ?)")
                ->execute([$supplierId, $deliveryDow, $deadlineDow, $deadlineTime, $reminderTimesJson]);
        }
        respond(['success' => true]);
    }

    if ($fn === 'delete_supplier_default_deadline') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'supplier-schedule', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $supplierId = trim((string)($body['supplier_id'] ?? ''));
        $deliveryDow = (int)($body['delivery_dow'] ?? 0);
        if (!$supplierId || !in_array($deliveryDow, [1,2,3,4,5,6,7], true)) {
            respond(['error' => 'Некорректные данные'], 400);
        }
        $pdo->prepare("DELETE FROM supplier_default_deadlines WHERE supplier_id = ? AND delivery_dow = ?")
            ->execute([$supplierId, $deliveryDow]);
        respond(['success' => true]);
    }

    if ($fn === 'delete_supplier_schedule_row') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'supplier-schedule', 'full', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $supplierId = trim((string)($body['supplier_id'] ?? ''));
        $restaurantId = (int)($body['restaurant_id'] ?? 0);
        $orderDay = (int)($body['order_day'] ?? 0);
        if (!$supplierId || !$restaurantId || !$orderDay) {
            respond(['error' => 'Некорректные данные'], 400);
        }

        // Проверка доступа к юр.лицу ресторана
        $restStmt = $pdo->prepare("SELECT legal_entity FROM restaurants WHERE id = ?");
        $restStmt->execute([$restaurantId]);
        $rest = $restStmt->fetch();
        if ($rest && !checkLegalEntityAccess($caller, $rest['legal_entity'])) {
            respond(['error' => 'Нет доступа'], 403);
        }

        $pdo->prepare("DELETE FROM supplier_schedules WHERE supplier_id = ? AND restaurant_id = ? AND order_day = ?")
            ->execute([$supplierId, $restaurantId, $orderDay]);
        $pdo->prepare("DELETE FROM supplier_schedule_deadlines WHERE supplier_id = ? AND restaurant_id = ? AND order_day = ?")
            ->execute([$supplierId, $restaurantId, $orderDay]);

        respond(['success' => true]);
    }
