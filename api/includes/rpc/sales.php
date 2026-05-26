<?php
/**
 * RPC: продажи ресторанов, аналитика и массовые импорты из 1С.
 *
 * Сюда отнесены:
 *  - get_restaurant_sales_summary — агрегаты продаж для трендов в заказе и планировании
 *  - replace_analysis_data        — массовая загрузка справочника analysis_data
 *  - replace_restaurant_sales     — массовая загрузка фактической реализации
 *  - replace_stock_malling        — массовая загрузка отчёта по срокам годности
 *  - replace_restaurant_schedule  — график доставки ресторана (массовая загрузка)
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName,
 * $ROLE_TEMPLATES, $ACCESS_LEVELS.
 */

// Сводка реализации ресторанов для трендов в заказе и планировании.
// Возвращает агрегированные суммы, чтобы фронтенд не тянул тысячи строк restaurant_sales.
if ($fn === 'get_restaurant_sales_summary') {
    $le = $body['legal_entity'] ?? '';
    $groups = $body['analog_groups'] ?? [];
    $periodDays = max(1, min((int)($body['period_days'] ?? 30), 365));
    if (!$le) respond(['error' => 'Не указано юр. лицо'], 400);
    if (!checkLegalEntityAccess($authUser, $le)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
    requireModuleAccess($authUser, 'restaurant-sales', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);

    if (!is_array($groups)) $groups = [];
    $groups = array_values(array_unique(array_filter(array_map(static function ($g) {
        $g = trim((string)$g);
        return $g !== '' ? mb_substr($g, 0, 255) : '';
    }, $groups))));
    if (!$groups) respond(['rows' => []]);
    $groups = array_slice($groups, 0, 300);

    $loadDays = max($periodDays, 28);
    $dateFrom = date('Y-m-d', strtotime("-{$loadDays} days"));
    $d14 = date('Y-m-d', strtotime('-14 days'));
    $d28 = date('Y-m-d', strtotime('-28 days'));
    $dPeriod = date('Y-m-d', strtotime("-{$periodDays} days"));
    $groupCode = getEntityGroup($le);
    $ph = implode(',', array_fill(0, count($groups), '?'));
    $sql = "
        SELECT
            analog_group,
            SUM(CASE WHEN sale_date >= ? THEN quantity ELSE 0 END) AS cur,
            SUM(CASE WHEN sale_date >= ? AND sale_date < ? THEN quantity ELSE 0 END) AS prev,
            SUM(CASE WHEN sale_date >= ? THEN quantity ELSE 0 END) AS total
        FROM restaurant_sales
        WHERE legal_entity_group = ?
          AND sale_date >= ?
          AND analog_group IN ($ph)
        GROUP BY analog_group
    ";
    $params = array_merge([$d14, $d28, $d14, $dPeriod, $groupCode, $dateFrom], $groups);
    $s = $pdo->prepare($sql);
    $s->execute($params);
    respond(['rows' => cleanNumeric($s->fetchAll())]);
}

if ($fn === 'replace_analysis_data') {
    $caller = getSessionUser($pdo);
    if (!$caller) respond(['error' => 'Требуется авторизация по сессии'], 401);
    $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
    if (($ACCESS_LEVELS[$perms['analysis'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
        respond(['error' => 'Недостаточно прав'], 403);
    }
    $legalEntity = $body['legal_entity'] ?? '';
    $items = $body['items'] ?? [];
    if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
    if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
    if (!is_array($items)) respond(['error' => 'Позиции должны быть массивом'], 400);
    if (empty($items)) respond(['error' => 'Список позиций пуст'], 400);
    if (count($items) > 5000) respond(['error' => 'Слишком много записей (макс. 5000)'], 400);
    $allowed = ['id','legal_entity','sku','stock','consumption','period_days','updated_by','updated_at'];
    // Защита от случайной очистки: если все строки после whitelist-фильтра
    // оказались пустыми, DELETE сработает, а INSERT нет — и данные юрлица
    // потеряются. Проверяем заранее.
    $validItems = [];
    foreach ($items as $item) {
        $f = array_intersect_key($item, array_flip($allowed));
        if (!empty($f)) $validItems[] = $f;
    }
    if (empty($validItems)) respond(['error' => 'В позициях нет валидных полей'], 400);
    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM `analysis_data` WHERE `legal_entity`=?")->execute([$legalEntity]);
        // Готовим один statement для всех записей
        $cols = array_keys($validItems[0]);
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $cn = implode(',', array_map(fn($c) => "`$c`", $cols));
        $stmt = $pdo->prepare("INSERT INTO `analysis_data` ($cn) VALUES ($ph)");
        foreach ($validItems as $item) {
            // Если в строке другой набор колонок — пропускаем, чтобы не ловить SQL-ошибку.
            if (array_keys($item) !== $cols) continue;
            $stmt->execute(array_values($item));
        }
        $pdo->commit();
        auditLog($pdo, 'data_imported', 'import', null, $caller['name'], ['type' => 'analysis_data', 'legal_entity' => $legalEntity, 'count' => count($validItems)]);
        notifyTelegramDataUpdate($pdo, 'analysis', $caller['name'], $legalEntity, count($validItems));
        respond(['success' => true, 'count' => count($validItems)]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("replace_analysis_data error: " . $e->getMessage());
        respond(['error' => 'Ошибка сохранения данных'], 500);
    }
}

if ($fn === 'replace_restaurant_sales') {
    $caller = getSessionUser($pdo);
    if (!$caller) respond(['error' => 'Требуется авторизация по сессии'], 401);
    $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
    if (($ACCESS_LEVELS[$perms['restaurant-sales'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
        respond(['error' => 'Недостаточно прав'], 403);
    }
    $items = $body['items'] ?? [];
    $legalEntity = $body['legal_entity'] ?? null;
    if (!is_array($items)) respond(['error' => 'Позиции должны быть массивом'], 400);
    if (empty($items)) respond(['error' => 'Список позиций пуст'], 400);
    if (count($items) > 500000) respond(['error' => 'Слишком много записей (макс. 500 000)'], 400);
    if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
    // Проверяем, что у пользователя есть доступ к этому юрлицу
    if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к юр. лицу'], 403);
    // Реализация хранится на уровне группы (BK_VM/PS), а не конкретного юрлица —
    // для БК+ВМ это одна и та же выгрузка из 1С.
    $group = getEntityGroup($legalEntity);
    try {
        $pdo->beginTransaction();
        // Upsert: обновляем если уже есть запись за эту дату, товарную группу и группу юрлиц
        $stmt = $pdo->prepare("INSERT INTO `restaurant_sales` (`sale_date`, `legal_entity_group`, `analog_group`, `quantity`, `restaurant_count`)
            VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE `quantity`=VALUES(`quantity`), `restaurant_count`=VALUES(`restaurant_count`)");
        $inserted = 0;
        foreach ($items as $item) {
            $date = $item['sale_date'] ?? null;
            $ag = $item['analog_group'] ?? null;
            $qty = $item['quantity'] ?? 0;
            $rc = $item['restaurant_count'] ?? 0;
            if (!$date || !$ag) continue;
            $stmt->execute([$date, $group, $ag, $qty, $rc]);
            $inserted++;
        }
        $pdo->commit();
        auditLog($pdo, 'data_imported', 'import', null, $caller['name'], ['type' => 'restaurant_sales', 'count' => $inserted, 'legal_entity_group' => $group]);
        // TODO: уведомление в Telegram временно отключено
        // if (!empty($body['notify'])) {
        //     notifyTelegramRestaurantSales($pdo, $caller['name'], $items, $inserted);
        // }
        respond(['success' => true, 'count' => $inserted]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("replace_restaurant_sales error: " . $e->getMessage());
        respond(['error' => 'Ошибка сохранения данных'], 500);
    }
}

if ($fn === 'replace_stock_malling') {
    $caller = getSessionUser($pdo);
    if (!$caller) respond(['error' => 'Требуется авторизация по сессии'], 401);
    $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
    if (($ACCESS_LEVELS[$perms['shelf-life'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
        respond(['error' => 'Недостаточно прав'], 403);
    }
    $items = $body['items'] ?? [];
    if (!is_array($items)) respond(['error' => 'Позиции должны быть массивом'], 400);
    if (empty($items)) respond(['error' => 'Список позиций пуст'], 400);
    if (count($items) > 5000) respond(['error' => 'Слишком много записей (макс. 5000)'], 400);
    $allowed = ['customer','warehouse','product_name','production_date','expiry_date','block_reason','expiry_status','quantity','uploaded_at','uploaded_by'];
    // Определяем юрлица в загружаемых данных и проверяем доступ
    $uploadedEntities = array_unique(array_filter(array_column($items, 'customer')));
    if ($caller['role'] !== 'admin') {
        foreach ($uploadedEntities as $ue) {
            if (!checkLegalEntityAccess($caller, $ue)) {
                respond(['error' => "Нет доступа к юр. лицу: $ue"], 403);
            }
        }
    }
    try {
        $pdo->beginTransaction();
        // Удаляем только данные юрлиц, которые есть в загрузке (не трогаем чужие)
        if (!empty($uploadedEntities)) {
            $ph = implode(',', array_fill(0, count($uploadedEntities), '?'));
            $pdo->prepare("DELETE FROM `stock_malling` WHERE `customer` IN($ph)")->execute(array_values($uploadedEntities));
        }
        // Готовим один statement для всех записей
        $firstItem = array_intersect_key($items[0], array_flip($allowed));
        $smCols = array_keys($firstItem);
        $smPh = implode(',', array_fill(0, count($smCols), '?'));
        $smCn = implode(',', array_map(fn($c) => "`$c`", $smCols));
        $smStmt = $pdo->prepare("INSERT INTO `stock_malling` ($smCn) VALUES ($smPh)");
        foreach ($items as $item) {
            $item = array_intersect_key($item, array_flip($allowed));
            if (empty($item)) continue;
            $smStmt->execute(array_values($item));
        }
        $pdo->commit();
        auditLog($pdo, 'data_imported', 'import', null, $caller['name'], ['type' => 'stock_malling', 'count' => count($items)]);
        notifyTelegramDataUpdate($pdo, 'shelf_life', $caller['name'], '', count($items));
        notifyTelegramExpiringItems($pdo, $caller['name']);
        respond(['success' => true, 'count' => count($items)]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("replace_stock_malling error: " . $e->getMessage());
        respond(['error' => 'Ошибка сохранения данных'], 500);
    }
}

if ($fn === 'replace_restaurant_schedule') {
    $caller = getSessionUser($pdo);
    if (!$caller) respond(['error' => 'Требуется авторизация по сессии'], 401);
    $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
    if (($ACCESS_LEVELS[$perms['delivery-schedule'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
        respond(['error' => 'Недостаточно прав'], 403);
    }
    $restaurantId = $body['restaurant_id'] ?? null;
    $items = $body['items'] ?? [];
    if (!$restaurantId) respond(['error' => 'Не указан ID ресторана'], 400);
    if (!is_array($items)) respond(['error' => 'Позиции должны быть массивом'], 400);
    if (count($items) > 500) respond(['error' => 'Слишком много записей (макс. 500)'], 400);
    // Проверка доступа к юрлицу ресторана
    if ($caller['role'] !== 'admin') {
        $rChk = $pdo->prepare("SELECT legal_entity FROM restaurants WHERE id=?"); $rChk->execute([$restaurantId]); $rRow = $rChk->fetch();
        if (!$rRow) respond(['error' => 'Ресторан не найден'], 404);
        if (!checkLegalEntityAccess($caller, $rRow['legal_entity'])) respond(['error' => 'Нет доступа к юр. лицу ресторана'], 403);
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM `delivery_schedule` WHERE `restaurant_id`=?")->execute([$restaurantId]);
        foreach ($items as $item) {
            $day = intval($item['day_of_week'] ?? 0);
            $time = $item['delivery_time'] ?? null;
            $notes = $item['notes'] ?? null;
            if ($day < 1 || $day > 7) continue;
            $pdo->prepare("INSERT INTO `delivery_schedule` (`restaurant_id`, `day_of_week`, `delivery_time`, `notes`) VALUES (?, ?, ?, ?)")
                ->execute([$restaurantId, $day, $time, $notes]);
        }
        $pdo->commit();
        respond(['success' => true]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("replace_restaurant_schedule error: " . $e->getMessage());
        respond(['error' => 'Ошибка сохранения данных'], 500);
    }
}
