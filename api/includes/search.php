<?php
/**
 * Поиск товаров.
 * Подключается из index.php.
 */
// ═══ SEARCH ═══
if ($endpoint === 'search_products') {
    if (!checkAuth($pdo)) { respond(['error'=>'Unauthorized'], 401); }
    $q = $_GET['q'] ?? '';
    $le = $_GET['legal_entity'] ?? '';
    $supplier = $_GET['supplier'] ?? '';
    $limit = min(intval($_GET['limit'] ?? 10), 100);

    // Проверка доступа к юрлицу
    $caller = getSessionUser($pdo);
    if ($caller && $le && !checkLegalEntityAccess($caller, $le)) respond(['error' => 'Нет доступа'], 403);

    if (mb_strlen($q, 'UTF-8') < 2) respond([]);
    
    $where = [];
    $params = [];
    
    // Поиск по SKU или имени (экранируем спецсимволы LIKE)
    $escaped_q = str_replace(['%', '_'], ['\\%', '\\_'], $q);
    $where[] = "(`sku` LIKE ? OR `name` LIKE ?)";
    $params[] = "%{$escaped_q}%";
    $params[] = "%{$escaped_q}%";
    
    // Фильтр по юр. лицу
    if ($le) {
        applyEntityGroupFilter($le, $where, $params);
    }
    
    // Фильтр по поставщику
    if ($supplier) {
        $where[] = "`supplier` = ?";
        $params[] = $supplier;
    }
    
    $sql = "SELECT * FROM `products` WHERE " . implode(' AND ', $where) . " LIMIT " . $limit;
    $s = $pdo->prepare($sql);
    $s->execute($params);
    respond(cleanNumeric($s->fetchAll()));
}
