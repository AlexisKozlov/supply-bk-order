<?php
/**
 * RPC: оплаты поставщикам.
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName,
 * $ROLE_TEMPLATES, $ACCESS_LEVELS, $clientIp.
 */

    if ($fn === 'create_payment_if_needed') {
        // Раньше тут был жёсткий plan-fact:edit. Из-за этого пользователь
        // с правами order:edit, но без plan-fact:edit, при приёмке заказа
        // получал тихий 403 (фронт ловит .catch и не показывает) — платёж
        // молча не создавался. Теперь достаточно order:edit: создание
        // платежа — это побочный эффект приёмки заказа, не требует
        // отдельных прав на сам модуль оплат.
        requireModuleAccess($authUser, 'order', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $orderId = $body['order_id'] ?? '';
        // Опорная дата для отсрочки — теперь дата поставки. Принимаем delivery_date,
        // оставляем чтение ttn_date для совместимости со старыми клиентами.
        $deliveryDate = trim((string)($body['delivery_date'] ?? $body['ttn_date'] ?? ''));
        if (!$orderId) respond(['error' => 'order_id required'], 400);

        // Получаем заказ и поставщика
        $order = $pdo->prepare("SELECT o.id, o.supplier, o.legal_entity, o.created_by, o.delivery_date,
            (SELECT SUM(oi.qty_boxes * COALESCE(oi.price, pp.price, 0)) FROM order_items oi LEFT JOIN product_prices pp ON pp.sku = oi.sku AND pp.legal_entity_group = o.legal_entity_group AND pp.price_type = 'purchase' WHERE oi.order_id = o.id) as total_amount
            FROM orders o WHERE o.id = ?");
        $order->execute([$orderId]);
        $o = $order->fetch();
        if (!$o) respond(['skip' => true]); // заказ не найден

        if (!$deliveryDate) {
            $deliveryDate = trim((string)($o['delivery_date'] ?? ''));
        }
        if (!$deliveryDate) respond(['skip' => true, 'reason' => 'delivery_date_required']);

        // Проверяем поставщика — российский + есть отсрочка
        $sup = $pdo->prepare("SELECT country, payment_delay_days FROM suppliers WHERE short_name = ? AND legal_entity = ?");
        $sup->execute([$o['supplier'], $o['legal_entity']]);
        $s = $sup->fetch();
        if (!$s) {
            // Пробуем без legal_entity
            $sup2 = $pdo->prepare("SELECT country, payment_delay_days FROM suppliers WHERE short_name = ? LIMIT 1");
            $sup2->execute([$o['supplier']]);
            $s = $sup2->fetch();
        }
        if (!$s || $s['country'] !== 'RU' || !$s['payment_delay_days']) respond(['skip' => true]);

        // Проверяем нет ли уже оплаты
        $exists = $pdo->prepare("SELECT id FROM supplier_payments WHERE order_id = ?");
        $exists->execute([$orderId]);
        if ($exists->fetch()) respond(['skip' => true, 'reason' => 'already_exists']);

        $delayDays = intval($s['payment_delay_days']);
        $dDate = new DateTime($deliveryDate);

        // Дата окончания отсрочки
        $dueDate = clone $dDate;
        $dueDate->modify("+{$delayDays} days");

        // Ближайший ВТ(2) или ЧТ(4) до или на dueDate (отсрочка — крайний срок)
        $payDate = clone $dueDate;
        while (true) {
            $dow = (int)$payDate->format('N');
            if ($dow === 2 || $dow === 4) break; // ВТ или ЧТ
            $payDate->modify('-1 day');
        }

        // Дедлайн заявки: предыдущий день 15:00
        $deadline = clone $payDate;
        $deadline->modify('-1 day');
        $deadline->setTime(15, 0, 0);

        $ins = $pdo->prepare("INSERT INTO supplier_payments (order_id, supplier, legal_entity, delivery_date, payment_delay_days, payment_due_date, payment_date, request_deadline, amount, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([
            $orderId, $o['supplier'], $o['legal_entity'], $deliveryDate,
            $delayDays, $dueDate->format('Y-m-d'), $payDate->format('Y-m-d'),
            $deadline->format('Y-m-d H:i:s'),
            $o['total_amount'] ?: null,
            $o['created_by'],
        ]);

        respond(['success' => true, 'payment_id' => $pdo->lastInsertId(), 'payment_date' => $payDate->format('Y-m-d')]);
    }

    // Ручное создание оплаты — для случаев когда заказ не проходил через портал.
    // Принимает supplier, legal_entity, delivery_date, amount (опц.).
    // Расчёт payment_date / request_deadline — по той же логике что и
    // create_payment_if_needed (отсрочка из карточки поставщика, ближайший ВТ/ЧТ).
    if ($fn === 'create_manual_payment') {
        requireModuleAccess($authUser, 'plan-fact', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $supplier     = trim((string)($body['supplier'] ?? ''));
        $legalEntity  = trim((string)($body['legal_entity'] ?? ''));
        $deliveryDate = trim((string)($body['delivery_date'] ?? ''));
        $amount       = $body['amount'] ?? null;
        $note         = trim((string)($body['note'] ?? ''));

        if ($supplier === '')     respond(['error' => 'Не указан поставщик'], 400);
        if ($legalEntity === '')  respond(['error' => 'Не указано юрлицо'], 400);
        if ($deliveryDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
            respond(['error' => 'Не указана корректная дата прихода'], 400);
        }
        if (!checkLegalEntityAccess($authUser, $legalEntity)) {
            respond(['error' => 'Нет доступа к юрлицу'], 403);
        }

        // Берём поставщика и его отсрочку. Ищем по группе юрлиц, чтобы можно
        // было создать оплату от любого юрлица группы (карточка поставщика
        // обычно заведена под одно из них).
        $group = getEntityGroup($legalEntity);
        $sStmt = $pdo->prepare("
            SELECT country, payment_delay_days
            FROM suppliers
            WHERE legal_entity_group = ?
              AND (short_name = ? OR full_name = ?)
              AND is_active = 1
            ORDER BY (legal_entity = ?) DESC, id
            LIMIT 1
        ");
        $sStmt->execute([$group, $supplier, $supplier, $legalEntity]);
        $s = $sStmt->fetch();
        if (!$s) respond(['error' => 'Поставщик не найден в карточках'], 404);
        if (!$s['payment_delay_days']) {
            respond(['error' => 'У поставщика не указана отсрочка платежа — заполните в карточке'], 400);
        }

        $delayDays = intval($s['payment_delay_days']);
        $dDate = new DateTime($deliveryDate);
        $dueDate = clone $dDate;
        $dueDate->modify("+{$delayDays} days");

        // Ближайший ВТ(2) или ЧТ(4) до или на dueDate.
        $payDate = clone $dueDate;
        while (true) {
            $dow = (int)$payDate->format('N');
            if ($dow === 2 || $dow === 4) break;
            $payDate->modify('-1 day');
        }

        $deadline = clone $payDate;
        $deadline->modify('-1 day');
        $deadline->setTime(15, 0, 0);

        $amountVal = ($amount !== null && $amount !== '' && is_numeric($amount)) ? (float)$amount : null;

        $ins = $pdo->prepare("
            INSERT INTO supplier_payments
              (order_id, supplier, legal_entity, delivery_date, payment_delay_days,
               payment_due_date, payment_date, request_deadline, amount, note, created_by)
            VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $supplier,
            $legalEntity,
            $deliveryDate,
            $delayDays,
            $dueDate->format('Y-m-d'),
            $payDate->format('Y-m-d'),
            $deadline->format('Y-m-d H:i:s'),
            $amountVal,
            $note !== '' ? $note : null,
            $authUserName,
        ]);

        respond([
            'success' => true,
            'payment_id' => $pdo->lastInsertId(),
            'payment_date' => $payDate->format('Y-m-d'),
            'request_deadline' => $deadline->format('Y-m-d H:i:s'),
        ]);
    }

    if ($fn === 'update_payment') {
        requireModuleAccess($authUser, 'plan-fact', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'id required'], 400);
        // Доступ — на уровне юрлица платежа.
        $payCheck = $pdo->prepare("SELECT legal_entity FROM supplier_payments WHERE id = ?");
        $payCheck->execute([$id]);
        $payLe = $payCheck->fetchColumn();
        if ($payLe === false) respond(['error' => 'Платёж не найден'], 404);
        if ($payLe && !checkLegalEntityAccess($authUser, $payLe)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $allowed = ['amount', 'status', 'note', 'payment_date', 'delivery_date', 'request_deadline'];
        $sets = []; $params = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) {
                $sets[] = "`{$f}` = ?";
                $params[] = $body[$f];
            }
        }

        // Если изменилась дата прихода и фронт явно не задал payment_date —
        // пересчитываем payment_due_date / payment_date / request_deadline по
        // той же логике, что и create_payment_if_needed (отсрочка из карточки
        // поставщика, ближайший ВТ/ЧТ до dueDate, дедлайн = пред. день 15:00).
        if (array_key_exists('delivery_date', $body) && !array_key_exists('payment_date', $body)) {
            $newDelivery = trim((string)$body['delivery_date']);
            if ($newDelivery !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDelivery)) {
                $spStmt = $pdo->prepare("SELECT supplier, legal_entity, payment_delay_days FROM supplier_payments WHERE id = ?");
                $spStmt->execute([$id]);
                $sp = $spStmt->fetch();
                if ($sp) {
                    $delayDays = (int)$sp['payment_delay_days'];
                    // Если в платеже отсрочки нет (старая запись) — берём из карточки.
                    if (!$delayDays) {
                        $grp = getEntityGroup($sp['legal_entity']);
                        $supSt = $pdo->prepare("
                            SELECT payment_delay_days FROM suppliers
                            WHERE legal_entity_group = ?
                              AND (short_name = ? OR full_name = ?)
                              AND is_active = 1
                            ORDER BY (legal_entity = ?) DESC, id
                            LIMIT 1
                        ");
                        $supSt->execute([$grp, $sp['supplier'], $sp['supplier'], $sp['legal_entity']]);
                        $delayDays = (int)($supSt->fetchColumn() ?: 0);
                    }
                    if ($delayDays > 0) {
                        $dDate = new DateTime($newDelivery);
                        $dueDate = clone $dDate; $dueDate->modify("+{$delayDays} days");
                        $payDate = clone $dueDate;
                        while (true) {
                            $dow = (int)$payDate->format('N');
                            if ($dow === 2 || $dow === 4) break;
                            $payDate->modify('-1 day');
                        }
                        $deadline = clone $payDate;
                        $deadline->modify('-1 day');
                        $deadline->setTime(15, 0, 0);
                        $sets[] = "`payment_due_date` = ?";    $params[] = $dueDate->format('Y-m-d');
                        $sets[] = "`payment_date` = ?";        $params[] = $payDate->format('Y-m-d');
                        $sets[] = "`request_deadline` = ?";    $params[] = $deadline->format('Y-m-d H:i:s');
                        if (!array_key_exists('payment_delay_days', $body)) {
                            $sets[] = "`payment_delay_days` = ?"; $params[] = $delayDays;
                        }
                    }
                }
            }
        }
        $caller = getSessionUser($pdo);
        if (($body['status'] ?? '') === 'paid') {
            $sets[] = "paid_by = ?"; $params[] = $caller['name'] ?? 'unknown';
            $sets[] = "paid_at = NOW()";
        }
        if (($body['status'] ?? '') === 'requested') {
            $sets[] = "paid_by = ?"; $params[] = $caller['name'] ?? 'unknown';
        }
        if (empty($sets)) respond(['error' => 'nothing to update'], 400);
        $params[] = $id;
        $pdo->prepare("UPDATE supplier_payments SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

        // Каскад orders.delivery_date — только если фронт явно попросил
        // cascade_delivery_to_order=true. Это решение остаётся за пользователем
        // (см. модалку подтверждения в PaymentsView).
        $cascadedOrderId = null;
        if (!empty($body['cascade_delivery_to_order']) && array_key_exists('delivery_date', $body)) {
            $newDelivery = trim((string)$body['delivery_date']);
            if ($newDelivery !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDelivery)) {
                $oidStmt = $pdo->prepare("SELECT order_id FROM supplier_payments WHERE id = ?");
                $oidStmt->execute([$id]);
                $oid = $oidStmt->fetchColumn();
                if ($oid) {
                    $pdo->prepare("UPDATE orders SET delivery_date = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$newDelivery, $oid]);
                    $cascadedOrderId = $oid;
                }
            }
        }

        respond(['success' => true, 'cascaded_order_id' => $cascadedOrderId]);
    }
