<?php
/**
 * RPC: заказы, расчёты, обогащение цен (orders).
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName,
 * $ROLE_TEMPLATES, $ACCESS_LEVELS, $clientIp.
 */

// chunk 1: batch_update_received_qty
    if ($fn === 'batch_update_received_qty') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация по сессии'], 401);
        $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['plan-fact'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
            respond(['error' => 'Недостаточно прав'], 403);
        }
        $items = $body['items'] ?? [];
        if (!is_array($items) || empty($items)) respond(['error' => 'Не указаны позиции'], 400);
        if (count($items) > 500) respond(['error' => 'Слишком много записей (макс. 500)'], 400);
        // Собираем ID позиций и проверяем доступ к юрлицам заказов
        $itemIds = array_filter(array_map(fn($i) => $i['id'] ?? null, $items));
        if (!empty($itemIds) && $caller['role'] !== 'admin') {
            $ph = implode(',', array_fill(0, count($itemIds), '?'));
            $chk = $pdo->prepare("SELECT DISTINCT o.legal_entity FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.id IN ($ph)");
            $chk->execute(array_values($itemIds));
            $entities = $chk->fetchAll(PDO::FETCH_COLUMN);
            foreach ($entities as $le) {
                if (!checkLegalEntityAccess($caller, $le)) respond(['error' => 'Нет доступа к юр. лицу заказа'], 403);
            }
        }
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE `order_items` SET `received_qty` = ? WHERE `id` = ?");
            foreach ($items as $item) {
                $id = $item['id'] ?? null;
                if (!$id) continue;
                $qty = array_key_exists('received_qty', $item) ? $item['received_qty'] : null;
                $stmt->execute([$qty, $id]);
            }
            $pdo->commit();
            respond(['success' => true, 'count' => count($items)]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("batch_update_received_qty error: " . $e->getMessage());
            respond(['error' => 'Ошибка сохранения данных'], 500);
        }
    }

// chunk 2: replace/delete/replace_item/calculate_adu
    if ($fn === 'replace_order_items') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация по сессии'], 401);
        $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['order'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
            respond(['error' => 'Недостаточно прав'], 403);
        }
        $orderId = $body['order_id'] ?? '';
        $items = $body['items'] ?? [];
        if (!$orderId) respond(['error' => 'Не указан ID заказа'], 400);
        // Проверяем доступ к юрлицу заказа
        $orderCheck = $pdo->prepare("SELECT legal_entity FROM orders WHERE id=?");
        $orderCheck->execute([$orderId]);
        $orderRow = $orderCheck->fetch();
        if (!$orderRow) respond(['error' => 'Заказ не найден'], 404);
        if (!checkLegalEntityAccess($caller, $orderRow['legal_entity'])) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        if (!is_array($items)) respond(['error' => 'Позиции должны быть массивом'], 400);
        if (count($items) > 5000) respond(['error' => 'Слишком много записей (макс. 5000)'], 400);
        try {
            $pdo->beginTransaction();
            // Блокируем заказ от параллельных изменений
            $lockStmt = $pdo->prepare("SELECT id FROM `orders` WHERE id=? FOR UPDATE");
            $lockStmt->execute([$orderId]);
            if (!$lockStmt->fetch()) { $pdo->rollBack(); respond(['error' => 'Заказ не найден'], 404); }
            $pdo->prepare("DELETE FROM `order_items` WHERE `order_id`=?")->execute([$orderId]);
            if (count($items) > 0) {
                $oiWhitelist = ['id','order_id','sku','name','qty_boxes','qty_per_box','boxes_per_pallet','multiplicity','consumption_period','stock','transit','final_order','manual_override','unit_of_measure','received_qty','analog_group','category'];
                foreach ($items as $item) {
                    if (!isset($item['order_id'])) $item['order_id'] = $orderId;
                    if (!isset($item['id'])) $item['id'] = uuid();
                    // Фильтруем по writeWhitelist
                    if (!empty($oiWhitelist)) $item = array_intersect_key($item, array_flip($oiWhitelist));
                    foreach (array_keys($item) as $col) {
                        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
                            $pdo->rollBack();
                            respond(['error' => 'Недопустимое имя колонки: '.$col], 400);
                        }
                    }
                    $cols = array_keys($item);
                    $ph = implode(',', array_fill(0, count($cols), '?'));
                    $cn = implode(',', array_map(fn($c) => "`$c`", $cols));
                    $pdo->prepare("INSERT INTO `order_items` ($cn) VALUES ($ph)")->execute(array_values($item));
                }
            }
            $pdo->commit();
            respond(['success' => true, 'count' => count($items)]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("replace_order_items error: " . $e->getMessage());
            respond(['error' => 'Ошибка сохранения данных'], 500);
        }
    }

    if ($fn === 'delete_order') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация по сессии'], 401);
        $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['order'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['full']) {
            respond(['error' => 'Недостаточно прав'], 403);
        }
        $orderId = $body['order_id'] ?? '';
        if (!$orderId) respond(['error' => 'Не указан ID заказа'], 400);
        // Проверяем, что пользователь имеет доступ к юрлицу заказа
        $orderCheck = $pdo->prepare("SELECT legal_entity FROM orders WHERE id=?");
        $orderCheck->execute([$orderId]);
        $orderRow = $orderCheck->fetch();
        if (!$orderRow) respond(['error' => 'Заказ не найден'], 404);
        if (!checkLegalEntityAccess($caller, $orderRow['legal_entity'])) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        try {
            $pdo->beginTransaction();
            // Удаляем зависимый платёж: иначе в supplier_payments оставались
            // «висячие» строки с несуществующим order_id, дашборд продолжал
            // их учитывать.
            $pdo->prepare("DELETE FROM `supplier_payments` WHERE `order_id`=?")->execute([$orderId]);
            $pdo->prepare("DELETE FROM `order_items` WHERE `order_id`=?")->execute([$orderId]);
            $pdo->prepare("DELETE FROM `orders` WHERE `id`=?")->execute([$orderId]);
            $pdo->commit();
            respond(['success' => true]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("delete_order error: " . $e->getMessage());
            respond(['error' => 'Ошибка сохранения данных'], 500);
        }
    }

    if ($fn === 'replace_item_order') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация по сессии'], 401);
        $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['order'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
            respond(['error' => 'Недостаточно прав'], 403);
        }
        $supplier = $body['supplier'] ?? '';
        $legalEntity = $body['legal_entity'] ?? '';
        $items = $body['items'] ?? [];
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        if (!is_array($items)) respond(['error' => 'Позиции должны быть массивом'], 400);
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM `item_order` WHERE `supplier`=? AND `legal_entity`=?")->execute([$supplier, $legalEntity]);
            foreach ($items as $item) {
                $pdo->prepare("INSERT INTO `item_order` (`id`,`supplier`,`legal_entity`,`item_id`,`position`) VALUES (?,?,?,?,?)")
                    ->execute([uuid(), $supplier, $legalEntity, $item['item_id'], $item['position']]);
            }
            $pdo->commit();
            respond(['success' => true, 'count' => count($items)]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("replace_item_order error: " . $e->getMessage());
            respond(['error' => 'Ошибка сохранения данных'], 500);
        }
    }

    // ─── RPC: calculate_adu — расчёт среднего суточного расхода ───
    if ($fn === 'calculate_adu') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация по сессии'], 401);
        $legalEntity = $body['legal_entity'] ?? '';
        if (!$legalEntity) respond(['error' => 'legal_entity обязателен'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $supplier = $body['supplier'] ?? null;
        $lookbackDays = intval($body['lookback_days'] ?? 90);
        if ($lookbackDays < 7) $lookbackDays = 90;

        // Загружаем order_items за последние N дней
        $sql = "SELECT oi.sku, oi.consumption_period, oi.qty_per_box, o.period_days, o.unit, o.delivery_date
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                WHERE o.legal_entity = ?
                  AND o.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  AND oi.sku IS NOT NULL AND oi.sku != ''
                  AND oi.consumption_period > 0";
        $params = [$legalEntity, $lookbackDays];
        if ($supplier) {
            $sql .= " AND o.supplier = ?";
            $params[] = $supplier;
        }
        $s = $pdo->prepare($sql);
        $s->execute($params);
        $rows = $s->fetchAll();

        // Группируем по SKU → массив суточных расходов (в штуках/день)
        $skuData = [];
        foreach ($rows as $r) {
            $sku = $r['sku'];
            $period = intval($r['period_days']) ?: 30;
            $consumption = floatval($r['consumption_period']);
            $qpb = intval($r['qty_per_box']) ?: 1;
            $unit = $r['unit'] ?? 'pieces';
            // Конвертируем в штуки
            $consumptionPcs = ($unit === 'boxes') ? $consumption * $qpb : $consumption;
            $daily = $period > 0 ? $consumptionPcs / $period : 0;
            if ($daily > 0) {
                if (!isset($skuData[$sku])) $skuData[$sku] = ['dailies' => [], 'lastDate' => null];
                $skuData[$sku]['dailies'][] = $daily;
                $dd = $r['delivery_date'];
                if ($dd && (!$skuData[$sku]['lastDate'] || $dd > $skuData[$sku]['lastDate'])) {
                    $skuData[$sku]['lastDate'] = $dd;
                }
            }
        }

        // Считаем ADU и CV, upsert в product_adu
        $upsertSql = "INSERT INTO product_adu (sku, legal_entity, adu, cv, sample_count, last_order_date)
                      VALUES (?, ?, ?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE adu=VALUES(adu), cv=VALUES(cv), sample_count=VALUES(sample_count), last_order_date=VALUES(last_order_date)";
        $upsertStmt = $pdo->prepare($upsertSql);
        $count = 0;
        $pdo->beginTransaction();
        try {
            foreach ($skuData as $sku => $info) {
                $dailies = $info['dailies'];
                $n = count($dailies);
                $mean = array_sum($dailies) / $n;
                $cv = 0;
                if ($n > 1 && $mean > 0) {
                    $variance = 0;
                    foreach ($dailies as $d) $variance += ($d - $mean) ** 2;
                    $stddev = sqrt($variance / ($n - 1));
                    $cv = round($stddev / $mean, 3);
                }
                $upsertStmt->execute([$sku, $legalEntity, round($mean, 2), $cv, $n, $info['lastDate']]);
                $count++;
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('calculate_adu error: ' . $e->getMessage());
            respond(['error' => 'Ошибка расчёта ADU'], 500);
        }
        respond(['success' => true, 'updated' => $count]);
    }

// chunk 3: helpers + create_order + update_order
    if (!function_exists('enrichItemsWithPrices')) {
        function enrichItemsWithPrices(PDO $pdo, array $items, string $supplier, string $legalEntity): array {
            $skus = array_values(array_filter(array_map(fn($i) => $i['sku'] ?? null, $items)));
            if (empty($skus) || !$supplier || !$legalEntity) return $items;
            $skus = array_values(array_unique($skus));
            $ph = implode(',', array_fill(0, count($skus), '?'));
            // Сначала пробуем точное совпадение supplier+legal_entity, затем
            // fallback на ту же legal_entity без supplier (если в прайсе записано
            // только по юрлицу). Берём самую свежую запись.
            // Цены живут на уровне группы юрлиц (BK_VM или PS).
            $group = getEntityGroup($legalEntity);
            $stmt = $pdo->prepare("
                SELECT sku, price, vat_rate, unit_type, currency
                FROM product_prices
                WHERE legal_entity_group = ? AND price_type = 'purchase' AND sku IN ($ph)
                ORDER BY (supplier = ?) DESC, updated_at DESC
            ");
            $stmt->execute(array_merge([$group], $skus, [$supplier]));
            $priceMap = [];
            foreach ($stmt->fetchAll() as $row) {
                if (!isset($priceMap[$row['sku']])) $priceMap[$row['sku']] = $row;
            }
            foreach ($items as &$item) {
                $sku = $item['sku'] ?? null;
                if ($sku && isset($priceMap[$sku])) {
                    $p = $priceMap[$sku];
                    $item['price']     = $p['price'];
                    $item['vat_rate']  = $p['vat_rate'];
                    $item['unit_type'] = $p['unit_type'];
                    $item['currency']  = $p['currency'];
                }
            }
            unset($item);
            return $items;
        }
    }

    // Нормализация числовых полей позиции заказа: защита от NaN/Infinity/
    // отрицательных значений и нелепо больших чисел. Раньше клиент мог
    // прислать qty_boxes=-5 или 1e308 — MySQL падал, либо сохранялся мусор.
    // ─ помещено единообразно: используется в create_order и update_order.
    if (!function_exists('sanitizeOrderItemNumbers')) {
        function sanitizeOrderItemNumbers(array $item): array {
            $clip = function ($v, $min, $max, $isInt) {
                if ($v === null || $v === '') return null;
                $n = is_numeric($v) ? (float)$v : 0.0;
                if (!is_finite($n)) $n = 0.0;
                if ($n < $min) $n = $min;
                if ($n > $max) $n = $max;
                return $isInt ? (int)$n : $n;
            };
            // qty_boxes: int 0..999_999
            if (array_key_exists('qty_boxes', $item))          $item['qty_boxes']          = $clip($item['qty_boxes'], 0, 999_999, true);
            if (array_key_exists('qty_per_box', $item))        $item['qty_per_box']        = $clip($item['qty_per_box'], 1, 999_999, true);
            if (array_key_exists('multiplicity', $item))       $item['multiplicity']       = $clip($item['multiplicity'], 1, 99_999, true);
            if (array_key_exists('boxes_per_pallet', $item))   $item['boxes_per_pallet']   = $clip($item['boxes_per_pallet'], 0, 99_999, true);
            if (array_key_exists('consumption_period', $item)) $item['consumption_period'] = $clip($item['consumption_period'], 0, 9_999_999, true);
            if (array_key_exists('stock', $item))              $item['stock']              = $clip($item['stock'], 0, 9_999_999, false);
            if (array_key_exists('transit', $item))            $item['transit']            = $clip($item['transit'], 0, 9_999_999, false);
            if (array_key_exists('final_order', $item))        $item['final_order']        = $clip($item['final_order'], 0, 9_999_999, false);
            if (array_key_exists('received_qty', $item))       $item['received_qty']       = $clip($item['received_qty'], 0, 9_999_999, false);
            // Снэпшот цены — защита от поддельных значений с фронта.
            if (array_key_exists('price', $item))               $item['price']              = $clip($item['price'], 0, 9_999_999, false);
            if (array_key_exists('vat_rate', $item))            $item['vat_rate']           = $clip($item['vat_rate'], 0, 100, false);
            return $item;
        }
    }

    if ($fn === 'create_order') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация по сессии'], 401);
        $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['order'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
            respond(['error' => 'Недостаточно прав'], 403);
        }
        $order = $body['order'] ?? [];
        $items = $body['items'] ?? [];
        if (empty($order) || empty($items)) respond(['error' => 'Не указаны данные заказа или позиции'], 400);
        $le = $order['legal_entity'] ?? '';
        if (!$le) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $le)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        if (count($items) > 5000) respond(['error' => 'Слишком много позиций (макс. 5000)'], 400);
        // Белый список полей заказа
        $orderWhitelist = ['supplier','legal_entity','delivery_date','delivery_date_2','unit','note','details','created_by','cda_mode','safety_coef','today_date','safety_days','period_days','has_transit','show_stock_column'];
        $order = array_intersect_key($order, array_flip($orderWhitelist));
        $order['id'] = uuid();
        $order['created_at'] = date('Y-m-d H:i:s');
        $order['created_by'] = $caller['name'];
        // Белый список полей позиции (price* — снэпшот цены на момент сохранения)
        $itemWhitelist = ['sku','name','qty_boxes','qty_per_box','boxes_per_pallet','multiplicity','consumption_period','stock','transit','final_order','manual_override','unit_of_measure','analog_group','category','sort_order','price','vat_rate','unit_type','currency'];
        // Снэпшотим цену закупки до начала транзакции — чтение прайса
        // не должно держать lock на orders.
        $items = enrichItemsWithPrices($pdo, $items, $order['supplier'] ?? '', $order['legal_entity'] ?? '');
        $pdo->beginTransaction();
        try {
            // Вставляем заказ
            foreach (array_keys($order) as $col) { if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) { $pdo->rollBack(); respond(['error' => 'Недопустимое имя колонки'], 400); } }
            $cols = array_keys($order);
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $cn = implode(',', array_map(fn($c) => "`$c`", $cols));
            $pdo->prepare("INSERT INTO `orders` ($cn) VALUES ($ph)")->execute(array_values($order));
            // Вставляем позиции
            foreach ($items as $item) {
                $item = array_intersect_key($item, array_flip($itemWhitelist));
                $item = sanitizeOrderItemNumbers($item);
                $item['id'] = uuid();
                $item['order_id'] = $order['id'];
                $cols = array_keys($item);
                $ph = implode(',', array_fill(0, count($cols), '?'));
                $cn = implode(',', array_map(fn($c) => "`$c`", $cols));
                $pdo->prepare("INSERT INTO `order_items` ($cn) VALUES ($ph)")->execute(array_values($item));
            }
            $pdo->commit();
            respond(['success' => true, 'id' => $order['id']]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("create_order error: " . $e->getMessage());
            respond(['error' => 'Ошибка создания заказа'], 500);
        }
    }

    if ($fn === 'update_order') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация по сессии'], 401);
        $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['order'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
            respond(['error' => 'Недостаточно прав'], 403);
        }
        $orderId = $body['order_id'] ?? '';
        $order = $body['order'] ?? [];
        $items = $body['items'] ?? [];
        if (!$orderId || empty($order)) respond(['error' => 'Не указаны данные заказа'], 400);
        if (count($items) > 5000) respond(['error' => 'Слишком много позиций (макс. 5000)'], 400);
        // Проверяем доступ к юрлицу заказа + статус приёмки.
        $orderCheck = $pdo->prepare("SELECT legal_entity, supplier, delivery_date, received_at FROM orders WHERE id=?");
        $orderCheck->execute([$orderId]);
        $orderRow = $orderCheck->fetch();
        if (!$orderRow) respond(['error' => 'Заказ не найден'], 404);
        if (!checkLegalEntityAccess($caller, $orderRow['legal_entity'])) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        // Принятый заказ редактировать нельзя — UI скрывает кнопки, но прямой
        // POST на update_order до этого фикса проходил.
        if (!empty($orderRow['received_at'])) {
            respond(['error' => 'Заказ уже принят, его нельзя редактировать'], 409);
        }
        $expectedUpdatedAt = $body['expected_updated_at'] ?? null;
        // Белый список полей заказа.
        // legal_entity убран — смена юрлица заказа портит данные (позиции
        // остаются от старой группы). Если действительно нужно — отдельный сценарий.
        $orderWhitelist = ['supplier','delivery_date','delivery_date_2','unit','note','details','cda_mode','safety_coef','today_date','safety_days','period_days','has_transit','show_stock_column'];
        $order = array_intersect_key($order, array_flip($orderWhitelist));
        $order['updated_at'] = date('Y-m-d H:i:s');
        // Белый список полей позиции (price* — снэпшот цены на момент сохранения)
        $itemWhitelist = ['sku','name','qty_boxes','qty_per_box','boxes_per_pallet','multiplicity','consumption_period','stock','transit','final_order','manual_override','unit_of_measure','received_qty','analog_group','category','sort_order','price','vat_rate','unit_type','currency'];
        $pdo->beginTransaction();
        try {
            // Блокируем строку и проверяем конкурентное редактирование внутри транзакции
            $lockS = $pdo->prepare("SELECT updated_at FROM orders WHERE id=? FOR UPDATE");
            $lockS->execute([$orderId]);
            $locked = $lockS->fetch();
            if ($expectedUpdatedAt && $locked['updated_at'] && $locked['updated_at'] !== $expectedUpdatedAt) {
                $pdo->rollBack();
                respond(['error' => 'Заказ был изменён другим пользователем'], 409);
            }
            // Блокируем заказ
            $lockStmt = $pdo->prepare("SELECT id FROM `orders` WHERE id=? FOR UPDATE");
            $lockStmt->execute([$orderId]);
            if (!$lockStmt->fetch()) { $pdo->rollBack(); respond(['error' => 'Заказ не найден'], 404); }
            // Обновляем параметры заказа
            $set = []; $sp = [];
            foreach ($order as $c => $v) {
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $c)) { $pdo->rollBack(); respond(['error' => 'Недопустимое имя колонки'], 400); }
                $set[] = "`$c`=?"; $sp[] = $v;
            }
            if (!empty($set)) {
                $sp[] = $orderId;
                $pdo->prepare("UPDATE `orders` SET " . implode(',', $set) . " WHERE id=?")->execute($sp);
            }
            // Заменяем позиции — сначала обогащаем актуальной ценой,
            // потом записываем. supplier для lookup берём из обновлённых
            // данных (если не пришёл — из текущего заказа).
            $items = enrichItemsWithPrices($pdo, $items, $order['supplier'] ?? ($orderRow['supplier'] ?? ''), $orderRow['legal_entity'] ?? '');
            $pdo->prepare("DELETE FROM `order_items` WHERE `order_id`=?")->execute([$orderId]);
            foreach ($items as $item) {
                $item = array_intersect_key($item, array_flip($itemWhitelist));
                $item = sanitizeOrderItemNumbers($item);
                $item['id'] = uuid();
                $item['order_id'] = $orderId;
                $cols = array_keys($item);
                $ph = implode(',', array_fill(0, count($cols), '?'));
                $cn = implode(',', array_map(fn($c) => "`$c`", $cols));
                $pdo->prepare("INSERT INTO `order_items` ($cn) VALUES ($ph)")->execute(array_values($item));
            }
            $pdo->commit();
            // Если поменялась дата доставки или поставщик — пересчитываем
            // существующий supplier_payment, иначе в дашборде оплат старая
            // payment_date/payment_due_date будут вечно.
            $supplierChanged = isset($order['supplier']) && $order['supplier'] !== ($orderRow['supplier'] ?? '');
            $deliveryChanged = isset($order['delivery_date']) && $order['delivery_date'] !== ($orderRow['delivery_date'] ?? '');
            if ($supplierChanged || $deliveryChanged) {
                try {
                    // Удаляем старый платёж — он будет пересоздан create_payment_if_needed.
                    $pdo->prepare("DELETE FROM supplier_payments WHERE order_id=?")->execute([$orderId]);
                    // Не вызываем сразу create_payment_if_needed, чтобы не дёргать RPC изнутри RPC.
                    // Достаточно того, что фронт после save вызовет его сам (PlanFactView),
                    // либо приёмщик при receive — также передёрнет платёж.
                } catch (PDOException $e) {
                    error_log("update_order: payment cleanup failed: " . $e->getMessage());
                }
            }
            respond(['success' => true, 'updated_at' => $order['updated_at']]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("update_order error: " . $e->getMessage());
            respond(['error' => 'Ошибка обновления заказа'], 500);
        }
    }
