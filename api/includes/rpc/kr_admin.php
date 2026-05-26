<?php
/**
 * RPC: график возврата кег (kr_*).
 *
 * Управляет настройкой адресов и расписания возврата кег по ресторанам
 * группы BK_VM, а также глобальным флагом модуля.
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName,
 * $ROLE_TEMPLATES, $ACCESS_LEVELS.
 */

if ($fn === 'kr_get_schedule') {
    requireModuleAccess($authUser, 'restaurant-orders', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
    // Глобальный флаг по юрлицу (по умолчанию для группы BK_VM используем
    // запись «ООО Бургер БК», т.к. ВМ и БК делят одну настройку модуля).
    $legalEntity = 'ООО "Бургер БК"';
    $globalEnabled = true;
    try {
        $s = $pdo->prepare("SELECT keg_returns_enabled FROM ro_module_settings WHERE legal_entity = ? LIMIT 1");
        $s->execute([$legalEntity]);
        $row = $s->fetch();
        if ($row !== false) $globalEnabled = (int)$row['keg_returns_enabled'] === 1;
    } catch (Throwable $e) { /* колонки ещё нет */ }

    $rows = $pdo->query("
        SELECT id, number, region, city, address,
               pickup_address, pickup_weekdays,
               COALESCE(keg_returns_enabled, 1) AS keg_returns_enabled
        FROM restaurants
        WHERE active = 1 AND legal_entity_group = 'BK_VM'
        ORDER BY number
    ")->fetchAll();
    respond([
        'global_enabled' => $globalEnabled,
        'restaurants'    => $rows,
    ]);
}

if ($fn === 'kr_save_schedule_row') {
    requireModuleAccess($authUser, 'restaurant-orders', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) respond(['error' => 'Не указан ресторан'], 400);
    $pickupAddress = isset($body['pickup_address']) ? trim((string)$body['pickup_address']) : null;
    $pickupWeekdays = isset($body['pickup_weekdays']) ? max(0, min(127, (int)$body['pickup_weekdays'])) : null;
    $kegEnabled = isset($body['keg_returns_enabled']) ? ((int)!!$body['keg_returns_enabled']) : null;

    $sets = [];
    $vals = [];
    if ($pickupAddress !== null) { $sets[] = 'pickup_address = ?';   $vals[] = $pickupAddress; }
    if ($pickupWeekdays !== null) { $sets[] = 'pickup_weekdays = ?'; $vals[] = $pickupWeekdays; }
    if ($kegEnabled !== null)     { $sets[] = 'keg_returns_enabled = ?'; $vals[] = $kegEnabled; }
    if (!$sets) respond(['error' => 'Нечего сохранять'], 400);

    $vals[] = $id;
    $sql = "UPDATE restaurants SET " . implode(', ', $sets) . " WHERE id = ? AND active = 1";
    try {
        $pdo->prepare($sql)->execute($vals);
    } catch (Throwable $e) {
        error_log('kr_save_schedule_row error: ' . $e->getMessage());
        respond(['error' => 'Не удалось сохранить'], 500);
    }
    respond(['success' => true]);
}

if ($fn === 'kr_set_module_enabled') {
    requireModuleAccess($authUser, 'restaurant-orders', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
    $enabled = !empty($body['enabled']) ? 1 : 0;
    $legalEntity = 'ООО "Бургер БК"';
    try {
        $pdo->prepare("
            INSERT INTO ro_module_settings (legal_entity, legal_entity_group, restaurant_orders_enabled, keg_returns_enabled, updated_by)
            VALUES (?, 'BK_VM', 1, ?, ?)
            ON DUPLICATE KEY UPDATE keg_returns_enabled = VALUES(keg_returns_enabled), updated_by = VALUES(updated_by)
        ")->execute([$legalEntity, $enabled, $authUserName ?? 'system']);
    } catch (Throwable $e) {
        error_log('kr_set_module_enabled error: ' . $e->getMessage());
        respond(['error' => 'Не удалось сохранить'], 500);
    }
    respond(['success' => true, 'enabled' => (bool)$enabled]);
}
