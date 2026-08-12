<?php
/**
 * Поставка через склад.
 *
 * В регионы, куда поставщик не доезжает, товар едет на склад, а оттуда в
 * ресторан — с ближайшей основной поставкой. Из-за этого дата в заявке значила
 * разное для разных сторон: поставщик читал её как «когда привезти на склад»,
 * а ресторан — как «когда получу», и ошибался на несколько дней.
 *
 * Теперь дат две:
 *   - delivery_date              — приход на склад. По ней живут дедлайны,
 *                                  документы поставщику, письма и сводки.
 *   - restaurant_delivery_date   — когда ресторан реально получит товар.
 *                                  NULL = прямая поставка, даты совпадают.
 *
 * Какие пары «поставщик + ресторан» идут через склад — галочка
 * supplier_schedules.via_warehouse.
 */

/**
 * Дни основной поставки ресторана (1=Пн … 7=Вс). Кэш на запрос: расчёт зовётся
 * для каждой даты каждого поставщика.
 */
function whRestaurantDeliveryDows(PDO $pdo, int $restaurantId): array {
    static $cache = [];
    if (isset($cache[$restaurantId])) return $cache[$restaurantId];
    $st = $pdo->prepare("SELECT DISTINCT day_of_week FROM delivery_schedule WHERE restaurant_id = ? AND day_of_week BETWEEN 1 AND 7");
    $st->execute([$restaurantId]);
    $dows = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    sort($dows);
    return $cache[$restaurantId] = $dows;
}

/**
 * Когда ресторан получит товар, пришедший на склад в $warehouseDate.
 * Правило: ближайшая основная поставка СТРОГО после дня прихода.
 * Нет графика основной поставки — вернём null (покажем только складскую дату).
 */
function whReceiptDate(PDO $pdo, int $restaurantId, string $warehouseDate): ?string {
    $dows = whRestaurantDeliveryDows($pdo, $restaurantId);
    if (!$dows) return null;
    try {
        $d = new DateTime($warehouseDate);
    } catch (Exception $e) {
        return null;
    }
    for ($i = 1; $i <= 14; $i++) {
        $d->modify('+1 day');
        if (in_array((int)$d->format('N'), $dows, true)) return $d->format('Y-m-d');
    }
    return null;
}

/**
 * Рестораны этого поставщика, которые снабжаются через склад: id => true.
 */
function whWarehouseRestaurants(PDO $pdo, string $supplierId): array {
    static $cache = [];
    if (isset($cache[$supplierId])) return $cache[$supplierId];
    $st = $pdo->prepare("SELECT DISTINCT restaurant_id FROM supplier_schedules WHERE supplier_id = ? AND via_warehouse = 1");
    $st->execute([$supplierId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rid) $out[(int)$rid] = true;
    return $cache[$supplierId] = $out;
}

/** Идёт ли эта пара через склад. */
function whIsViaWarehouse(PDO $pdo, string $supplierId, int $restaurantId): bool {
    $map = whWarehouseRestaurants($pdo, $supplierId);
    return !empty($map[$restaurantId]);
}

/**
 * Дата получения для пары (или null, если поставка прямая либо посчитать нечем).
 */
function whReceiptForPair(PDO $pdo, string $supplierId, int $restaurantId, string $warehouseDate): ?string {
    if (!whIsViaWarehouse($pdo, $supplierId, $restaurantId)) return null;
    return whReceiptDate($pdo, $restaurantId, $warehouseDate);
}

/**
 * То же, но по номеру ресторана — для бота и кронов, где id под рукой нет.
 */
function whReceiptForRestaurantNumber(PDO $pdo, string $supplierId, int $restaurantNumber, ?string $group, string $warehouseDate): ?string {
    static $ids = [];
    $key = $restaurantNumber . '|' . ($group ?: '');
    if (!array_key_exists($key, $ids)) {
        $sql = "SELECT id FROM restaurants WHERE number = ?" . ($group ? " AND legal_entity_group = ?" : "") . " LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute($group ? [$restaurantNumber, $group] : [$restaurantNumber]);
        $ids[$key] = (int)$st->fetchColumn();
    }
    if (!$ids[$key]) return null;
    return whReceiptForPair($pdo, $supplierId, $ids[$key], $warehouseDate);
}
