<?php
/**
 * API модуля «Сбор заказа основной поставки» (sa_*).
 * Подключается из index.php ПОСЛЕ restaurant_orders.php (ro-функции уже определены).
 *
 * Маршруты (все требуют авторизованного ресторана через X-RO-Token / cookie):
 *   GET    sa/delivery-days     — ближайшие даты поставки с признаком наличия заказа
 *   GET    sa/products          — товары шаблона ресторана с остатками на складе
 *   GET    sa/stock-products    — товары из «Сроков годности» для импорта в заказ
 *   GET    sa/order?date=...    — сохранённый заказ ресторана на дату
 *   GET    sa/my-orders         — история заказов ресторана
 *   POST   sa/order             — сохранить / обновить заказ
 */

if ($endpoint !== 'sa') return;

// ═══ Хелпер ответа ═══

function saRespond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

// ═══ Разбор URI ═══

// uri на момент подключения: "sa/action/param" (уже без ведущего /api/)
$saParts = explode('/', $uri);
// $saParts[0] = "sa", $saParts[1] = action, $saParts[2] = optional param
$saAction = $saParts[1] ?? '';
$saParam  = $saParts[2] ?? null;

// ═══ GET sa/delivery-days ═══
// Ближайшие даты поставки ресторана; для каждой — признак наличия сохранённого заказа.
// Не зависит от ro_sessions — только расписание и sa_orders.

if ($saAction === 'delivery-days' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) saRespond(['error' => 'Не авторизован'], 401);

    $restNumber = (int)$rest['restaurant_number'];
    $group      = $rest['legal_entity_group'] ?? 'BK_VM';

    // Расписание основной доставки этого ресторана
    $ds = $pdo->prepare("
        SELECT ds.day_of_week, ds.delivery_time
        FROM delivery_schedule ds
        JOIN restaurants r ON r.id = ds.restaurant_id
        WHERE r.number = ?
          AND r.active = 1
          AND r.legal_entity_group = ?
          AND TRIM(COALESCE(ds.delivery_time, '')) <> ''
        ORDER BY ds.day_of_week
    ");
    $ds->execute([$restNumber, $group]);
    $schedule = $ds->fetchAll();

    $dayNames = [
        1 => 'Понедельник',
        2 => 'Вторник',
        3 => 'Среда',
        4 => 'Четверг',
        5 => 'Пятница',
        6 => 'Суббота',
        7 => 'Воскресенье',
    ];

    $today    = new DateTime(roGetTodayMinsk());
    $rangeStart = (clone $today)->modify('-7 days');
    $rangeEnd   = (clone $today)->modify('+45 days');

    // Заранее загружаем все sa_orders ресторана в нужном диапазоне — один запрос
    $ordersStmt = $pdo->prepare("
        SELECT delivery_date
        FROM sa_orders
        WHERE restaurant_number = ?
          AND delivery_date BETWEEN ? AND ?
    ");
    $ordersStmt->execute([
        $restNumber,
        $rangeStart->format('Y-m-d'),
        $rangeEnd->format('Y-m-d'),
    ]);
    $existingOrderDates = [];
    foreach ($ordersStmt->fetchAll() as $row) {
        $existingOrderDates[$row['delivery_date']] = true;
    }

    $deliveryDays = [];
    foreach ($schedule as $sch) {
        $dow  = (int)$sch['day_of_week'];
        $date = clone $rangeStart;
        $currentDow = (int)$date->format('N'); // 1=Пн
        $diff = $dow - $currentDow;
        if ($diff < 0) $diff += 7;
        $date->modify("+{$diff} days");

        while ($date <= $rangeEnd) {
            $dateStr      = $date->format('Y-m-d');
            $hasOrder     = isset($existingOrderDates[$dateStr]);

            $deliveryDays[] = [
                'date'          => $dateStr,
                'day_of_week'   => $dow,
                'day_name'      => $dayNames[$dow] ?? '',
                'delivery_time' => $sch['delivery_time'],
                'has_order'     => $hasOrder,
            ];

            $date->modify('+7 days');
        }
    }

    // Сортировка по дате
    usort($deliveryDays, function ($a, $b) { return strcmp($a['date'], $b['date']); });

    saRespond(['delivery_days' => $deliveryDays]);
}

// ═══ GET sa/products ═══
// Товары шаблона ресторана (ro_templates) + остатки со склада (bulk-запрос).

if ($saAction === 'products' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) saRespond(['error' => 'Не авторизован'], 401);

    $le = $rest['legal_entity'];

    // Шаблон с JOIN к products для external_code, analog_group, qty_per_box
    $tplStmt = $pdo->prepare("
        SELECT
            t.sku,
            t.product_name AS name,
            t.category,
            t.sort_order,
            COALESCE(NULLIF(t.multiplicity, 0), p.multiplicity, 1) AS multiplicity,
            p.external_code,
            p.analog_group,
            p.qty_per_box,
            p.weight_brutto,
            p.boxes_per_pallet
        FROM ro_templates t
        LEFT JOIN products p ON p.sku = t.sku AND p.legal_entity = ? AND p.is_active = 1
        WHERE t.legal_entity = ? AND t.is_active = 1
        ORDER BY t.sort_order, t.product_name
    ");
    $tplStmt->execute([$le, $le]);
    $templateRows = $tplStmt->fetchAll();

    if (empty($templateRows)) {
        saRespond(['products' => []]);
    }

    // Собираем SKU для bulk-запроса остатков
    $skuList = array_unique(array_column($templateRows, 'sku'));
    $placeholders = implode(',', array_fill(0, count($skuList), '?'));

    // Определяем «короткого» покупателя для stock_malling
    $customer = roShortCustomerName($le);

    // Bulk: суммарный остаток по ro_stock_balances (самая свежая дата)
    $balStock = []; // sku => qty
    if ($skuList) {
        // Находим последнюю дату для каждого SKU одним запросом
        $balParams = array_merge([$le], $skuList);
        $balDateStmt = $pdo->prepare("
            SELECT sku, MAX(balance_date) AS max_date
            FROM ro_stock_balances
            WHERE legal_entity = ?
              AND sku IN ($placeholders)
            GROUP BY sku
        ");
        $balDateStmt->execute($balParams);
        $maxDates = [];
        foreach ($balDateStmt->fetchAll() as $r) {
            $maxDates[$r['sku']] = $r['max_date'];
        }

        if ($maxDates) {
            // Загружаем суммы за последние даты через UNION-подход:
            // строим WHERE (sku=? AND balance_date=?) OR ...
            $balWhereParts = [];
            $balWhereParams = [$le];
            foreach ($maxDates as $sku => $date) {
                $balWhereParts[] = "(sku = ? AND balance_date = ?)";
                $balWhereParams[] = $sku;
                $balWhereParams[] = $date;
            }
            $balSumStmt = $pdo->prepare("
                SELECT sku, SUM(quantity) AS total_qty
                FROM ro_stock_balances
                WHERE legal_entity = ?
                  AND (" . implode(' OR ', $balWhereParts) . ")
                GROUP BY sku
            ");
            $balSumStmt->execute($balWhereParams);
            foreach ($balSumStmt->fetchAll() as $r) {
                $balStock[$r['sku']] = (float)$r['total_qty'];
            }
        }
    }

    // Bulk: суммарный остаток по stock_malling
    // Стратегия: загружаем все строки stock_malling для данного customer,
    // затем матчим к SKU через тот же механизм что в roGetStockForSku.
    $shelfStock = []; // sku => qty
    if ($customer && $skuList) {
        // Загружаем product-справочник (name, external_code) для SKU из шаблона
        $prodInfoStmt = $pdo->prepare("
            SELECT sku, name, external_code
            FROM products
            WHERE legal_entity = ? AND sku IN ($placeholders) AND is_active = 1
        ");
        $prodInfoStmt->execute(array_merge([$le], $skuList));
        $prodInfoBySku = [];
        foreach ($prodInfoStmt->fetchAll() as $p) {
            $prodInfoBySku[$p['sku']] = $p;
        }

        // Строим lookup: product_name → sku для матчинга строк stock_malling
        // Используем паттерны как в roGetStockForSku: name, sku-prefix, ext-prefix
        // Вместо per-sku запроса делаем один запрос по customer, потом матчим в PHP.
        $today = new DateTimeImmutable('today');
        $shelfStmt = $pdo->prepare("
            SELECT product_name, warehouse, quantity, expiry_date
            FROM stock_malling
            WHERE customer = ?
        ");
        $shelfStmt->execute([$customer]);
        $shelfRows = $shelfStmt->fetchAll();

        // Для каждой строки shelf пытаемся найти SKU
        foreach ($shelfRows as $row) {
            $qty = round((float)($row['quantity'] ?? 0), 2);
            if ($qty <= 0) continue;
            // Пропускаем просроченные
            $expiry = $row['expiry_date'] ?: null;
            if ($expiry) {
                $exp = DateTimeImmutable::createFromFormat('!Y-m-d', $expiry)
                    ?: new DateTimeImmutable($expiry);
                if ($exp < $today) continue;
            }

            $pName = trim((string)($row['product_name'] ?? ''));
            $matchedSku = null;

            // Пробуем распарсить формат "external - sku название"
            if (preg_match('/^\s*([^\s]+)\s+-\s+([^\s]+)\s+/u', $pName, $m)) {
                $tryExt = trim($m[1]);
                $trySku = trim($m[2]);
                if (isset($prodInfoBySku[$trySku])) {
                    $matchedSku = $trySku;
                } elseif ($tryExt) {
                    // ищем по external_code
                    foreach ($prodInfoBySku as $s => $pi) {
                        if (trim((string)($pi['external_code'] ?? '')) === $tryExt) {
                            $matchedSku = $s;
                            break;
                        }
                    }
                }
            }

            if (!$matchedSku) {
                // Прямой матч по имени или SKU-префиксу
                foreach ($prodInfoBySku as $sku => $pi) {
                    $prodName = trim((string)($pi['name'] ?? ''));
                    $extCode  = trim((string)($pi['external_code'] ?? ''));
                    if (
                        $pName === $prodName
                        || strpos($pName, $sku . ' ') === 0
                        || strpos($pName, $sku . ' - ') === 0
                        || ($extCode && strpos($pName, $extCode . ' ') === 0)
                        || ($extCode && strpos($pName, $extCode . ' - ') === 0)
                    ) {
                        $matchedSku = $sku;
                        break;
                    }
                }
            }

            if ($matchedSku) {
                $shelfStock[$matchedSku] = round(($shelfStock[$matchedSku] ?? 0) + $qty, 2);
            }
        }
    }

    // Формируем ответ: берём stock из более свежего источника
    // (упрощённо: если есть shelfStock — берём его; иначе — balStock;
    //  полная логика roGetStockForSku требует сравнения дат, что здесь дорого в bulk).
    // Для простоты: если shelfStock > 0 — используем его, иначе balStock.
    $products = [];
    foreach ($templateRows as $row) {
        $sku    = $row['sku'];
        $shelf  = $shelfStock[$sku] ?? null;
        $bal    = $balStock[$sku] ?? null;
        $stock  = $shelf !== null ? $shelf : ($bal !== null ? $bal : 0.0);

        $products[] = [
            'sku'              => $sku,
            'name'             => $row['name'],
            'category'         => $row['category'],
            'multiplicity'     => (int)$row['multiplicity'],
            'external_code'    => $row['external_code'] ?? null,
            'analog_group'     => $row['analog_group'] ?? null,
            'qty_per_box'      => $row['qty_per_box'] !== null ? (float)$row['qty_per_box'] : null,
            'weight_brutto'    => $row['weight_brutto'] !== null ? (float)$row['weight_brutto'] : null,
            'boxes_per_pallet' => $row['boxes_per_pallet'] !== null ? (float)$row['boxes_per_pallet'] : null,
            'stock'            => $stock,
        ];
    }

    saRespond(['products' => $products]);
}

// ═══ GET sa/search-products ═══
// Поиск товаров каталога по строке (?q=...) для ручного добавления позиций в заказ.

if ($saAction === 'search-products' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) saRespond(['error' => 'Не авторизован'], 401);

    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        saRespond(['products' => []]);
    }

    $le      = $rest['legal_entity'];
    $pattern = '%' . $q . '%';

    $stmt = $pdo->prepare("
        SELECT
            sku,
            name,
            category,
            COALESCE(NULLIF(multiplicity, 0), 1) AS multiplicity,
            external_code,
            analog_group,
            qty_per_box,
            weight_brutto,
            boxes_per_pallet
        FROM products
        WHERE legal_entity = ?
          AND is_active = 1
          AND (name LIKE ? OR sku LIKE ?)
        ORDER BY name
        LIMIT 50
    ");
    $stmt->execute([$le, $pattern, $pattern]);
    $rows = $stmt->fetchAll();

    $products = [];
    foreach ($rows as $row) {
        $products[] = [
            'sku'              => $row['sku'],
            'name'             => $row['name'],
            'category'         => $row['category'],
            'multiplicity'     => (int)$row['multiplicity'],
            'external_code'    => $row['external_code'] ?? null,
            'analog_group'     => $row['analog_group'] ?? null,
            'qty_per_box'      => $row['qty_per_box'] !== null ? (float)$row['qty_per_box'] : null,
            'weight_brutto'    => $row['weight_brutto'] !== null ? (float)$row['weight_brutto'] : null,
            'boxes_per_pallet' => $row['boxes_per_pallet'] !== null ? (float)$row['boxes_per_pallet'] : null,
            'stock'            => null,
        ];
    }

    saRespond(['products' => $products]);
}

// ═══ Товары из «Сроков годности» (stock_malling) для импорта позиций вне шаблона ═══
// Используется и кабинетом ресторана, и разделом отдела закупок.
function saStockProductsForEntity($pdo, $le) {
    $customer = roShortCustomerName($le);
    if (!$customer) return [];

    // Справочник товаров
    $prodStmt = $pdo->prepare("
        SELECT sku, external_code, name, analog_group, category
        FROM products
        WHERE legal_entity = ? AND is_active = 1
    ");
    $prodStmt->execute([$le]);
    $productsBySku      = [];
    $productsByExternal = [];
    $productsByName     = [];
    foreach ($prodStmt->fetchAll() as $p) {
        $sku  = trim((string)($p['sku'] ?? ''));
        $ext  = trim((string)($p['external_code'] ?? ''));
        $name = roNormalizeLookupText($p['name'] ?? '');
        if ($sku  !== '') $productsBySku[$sku]      = $p;
        if ($ext  !== '') $productsByExternal[$ext]  = $p;
        if ($name !== '') $productsByName[$name]     = $p;
    }

    // Загружаем все строки stock_malling для данного customer
    $shelfStmt = $pdo->prepare("
        SELECT product_name, warehouse, quantity, expiry_date, expiry_status
        FROM stock_malling
        WHERE customer = ?
        ORDER BY product_name, expiry_date IS NULL, expiry_date ASC
    ");
    $shelfStmt->execute([$customer]);

    $groups = [];
    $today  = new DateTimeImmutable('today');
    foreach ($shelfStmt->fetchAll() as $row) {
        $qty = round((float)($row['quantity'] ?? 0), 2);
        if ($qty <= 0) continue;
        $expiry = $row['expiry_date'] ?: null;
        if ($expiry) {
            $exp = DateTimeImmutable::createFromFormat('!Y-m-d', $expiry)
                ?: new DateTimeImmutable($expiry);
            if ($exp < $today) continue;
        }

        $product  = roFindProductForShelfRow($row['product_name'] ?? '', $productsBySku, $productsByExternal, $productsByName);
        $sku      = trim((string)($product['sku'] ?? ''));
        $external = trim((string)($product['external_code'] ?? ''));

        // Если не нашли SKU — пробуем разобрать название вручную
        if (!$sku && preg_match('/^\s*([^\s]+)\s+-\s+([^\s]+)\s+/u', (string)$row['product_name'], $m)) {
            $sku = trim($m[2]);
        }
        if (!$external && preg_match('/^\s*([^\s]+)\s+-\s+([^\s]+)\s+/u', (string)$row['product_name'], $m)) {
            $external = trim($m[1]);
        }

        $storage  = roWarehouseStorageMode($row['warehouse'] ?? '');
        // Категория из справочника, или из режима хранения
        $category = $product['category'] ?? $storage['label'] ?? '';

        $key = $sku ? 'sku:' . $sku : 'name:' . roNormalizeLookupText($row['product_name']);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'sku'          => $sku,
                'name'         => $product['name'] ?? preg_replace('/^\s*[^\s]+\s+-\s*[^\s]+\s+/u', '', (string)$row['product_name']),
                'external_code'=> $external,
                'analog_group' => $product['analog_group'] ?? '',
                'category'     => $category,
                'stock'        => 0.0,
            ];
        }
        $groups[$key]['stock'] = round($groups[$key]['stock'] + $qty, 2);
        // Если у позиции не было категории из справочника — уточняем из склада
        if ($groups[$key]['category'] === '' && $category !== '') {
            $groups[$key]['category'] = $category;
        }
    }

    $products = array_values($groups);
    // Сортируем: по категории, затем по имени
    usort($products, function ($a, $b) {
        $cmp = strcmp($a['category'] ?? '', $b['category'] ?? '');
        return $cmp !== 0 ? $cmp : strcmp($a['name'] ?? '', $b['name'] ?? '');
    });

    return $products;
}

// ═══ GET sa/stock-products — для кабинета ресторана ═══
if ($saAction === 'stock-products' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) saRespond(['error' => 'Не авторизован'], 401);
    saRespond(['products' => saStockProductsForEntity($pdo, $rest['legal_entity'])]);
}

// ═══ GET sa/order ═══
// Сохранённый заказ ресторана на конкретную дату (?date=YYYY-MM-DD).

if ($saAction === 'order' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) saRespond(['error' => 'Не авторизован'], 401);

    $dateParam = trim((string)($_GET['date'] ?? ''));
    if (!$dateParam || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
        saRespond(['error' => 'Укажите параметр date в формате YYYY-MM-DD'], 400);
    }

    $restNumber = (int)$rest['restaurant_number'];

    $orderStmt = $pdo->prepare("
        SELECT id, delivery_date, comment, created_at, updated_at, updated_by
        FROM sa_orders
        WHERE restaurant_number = ?
          AND delivery_date = ?
        LIMIT 1
    ");
    $orderStmt->execute([$restNumber, $dateParam]);
    $order = $orderStmt->fetch();

    if (!$order) {
        saRespond(['order' => null]);
    }

    $itemsStmt = $pdo->prepare("
        SELECT sku, product_name, external_code, analog_group, category, multiplicity, quantity
        FROM sa_order_items
        WHERE order_id = ?
        ORDER BY category, product_name
    ");
    $itemsStmt->execute([(int)$order['id']]);
    $items = $itemsStmt->fetchAll();

    // Приводим числовые поля
    foreach ($items as &$item) {
        $item['multiplicity'] = (int)$item['multiplicity'];
        $item['quantity']     = (float)$item['quantity'];
    }
    unset($item);

    saRespond([
        'order' => [
            'id'           => (int)$order['id'],
            'delivery_date'=> $order['delivery_date'],
            'comment'      => $order['comment'],
            'updated_at'   => $order['updated_at'],
            'updated_by'   => $order['updated_by'],
            'items'        => $items,
        ],
    ]);
}

// ═══ GET sa/my-orders ═══
// История заказов ресторана (список, последние 100).

if ($saAction === 'my-orders' && $method === 'GET') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) saRespond(['error' => 'Не авторизован'], 401);

    $restNumber = (int)$rest['restaurant_number'];

    $stmt = $pdo->prepare("
        SELECT
            o.id,
            o.delivery_date,
            o.comment,
            o.created_at,
            o.updated_at,
            COUNT(i.id)          AS item_count,
            COALESCE(SUM(i.quantity), 0) AS total_quantity
        FROM sa_orders o
        LEFT JOIN sa_order_items i ON i.order_id = o.id
        WHERE o.restaurant_number = ?
        GROUP BY o.id
        ORDER BY o.delivery_date DESC
        LIMIT 100
    ");
    $stmt->execute([$restNumber]);
    $orders = $stmt->fetchAll();

    foreach ($orders as &$row) {
        $row['id']             = (int)$row['id'];
        $row['item_count']     = (int)$row['item_count'];
        $row['total_quantity'] = (float)$row['total_quantity'];
    }
    unset($row);

    saRespond(['orders' => $orders]);
}

// ═══ POST sa/order ═══
// Сохранить / обновить заказ ресторана.

if ($saAction === 'order' && $method === 'POST') {
    $rest = roGetRestaurantSession($pdo);
    if (!$rest) saRespond(['error' => 'Не авторизован'], 401);

    $restNumber  = (int)$rest['restaurant_number'];
    $le          = $rest['legal_entity'];
    $leGroup     = $rest['legal_entity_group'] ?? 'BK_VM';

    // Валидация входных данных
    $deliveryDate = trim((string)($body['delivery_date'] ?? ''));
    $comment      = isset($body['comment']) ? mb_substr(trim((string)$body['comment']), 0, 500) : null;
    $items        = $body['items'] ?? null;

    if (!$deliveryDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
        saRespond(['error' => 'Укажите корректную дату доставки (delivery_date, формат YYYY-MM-DD)'], 400);
    }
    // Дополнительная проверка корректности даты
    $parsedDate = date_create_from_format('Y-m-d', $deliveryDate);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $deliveryDate) {
        saRespond(['error' => 'Некорректная дата доставки'], 400);
    }
    if (!is_array($items)) {
        saRespond(['error' => 'Поле items должно быть массивом'], 400);
    }

    // Фильтруем позиции с quantity > 0
    $validItems = [];
    foreach ($items as $item) {
        $qty = (float)($item['quantity'] ?? 0);
        if ($qty <= 0) continue;
        $validItems[] = [
            'sku'           => mb_substr(trim((string)($item['sku'] ?? '')), 0, 50),
            'product_name'  => mb_substr(trim((string)($item['product_name'] ?? '')), 0, 255),
            'category'      => mb_substr(trim((string)($item['category'] ?? '')), 0, 20),
            'quantity'      => $qty,
            'multiplicity'  => max(1, (int)($item['multiplicity'] ?? 1)),
            'external_code' => mb_substr(trim((string)($item['external_code'] ?? '')), 0, 20) ?: null,
            'analog_group'  => mb_substr(trim((string)($item['analog_group'] ?? '')), 0, 255) ?: null,
        ];
    }

    // Проверка кратности
    $multErrors = [];
    foreach ($validItems as $item) {
        if (roHasMultiplicityViolation($item['quantity'], $item['multiplicity'])) {
            $multErrors[] = [
                'sku'          => $item['sku'],
                'product_name' => $item['product_name'],
                'quantity'     => $item['quantity'],
                'multiplicity' => $item['multiplicity'],
            ];
        }
    }
    if (!empty($multErrors)) {
        saRespond([
            'error'       => 'Нарушена кратность у ' . count($multErrors) . ' позиций',
            'mult_errors' => $multErrors,
        ], 400);
    }

    // Транзакция: UPSERT sa_orders + пересохранить позиции
    try {
        $pdo->beginTransaction();

        // Проверяем наличие существующего заказа
        $existsStmt = $pdo->prepare("
            SELECT id, updated_by
            FROM sa_orders
            WHERE restaurant_number = ? AND delivery_date = ?
            LIMIT 1
        ");
        $existsStmt->execute([$restNumber, $deliveryDate]);
        $existing = $existsStmt->fetch();

        if ($existing) {
            $orderId = (int)$existing['id'];
            // Обновляем, НЕ трогаем updated_by (его ставит только отдел закупок)
            $updStmt = $pdo->prepare("
                UPDATE sa_orders
                SET comment = ?
                WHERE id = ?
            ");
            $updStmt->execute([$comment, $orderId]);
        } else {
            // Вставляем новый заказ
            $insStmt = $pdo->prepare("
                INSERT INTO sa_orders
                    (restaurant_number, legal_entity, legal_entity_group, delivery_date, comment)
                VALUES (?, ?, ?, ?, ?)
            ");
            $insStmt->execute([$restNumber, $le, $leGroup, $deliveryDate, $comment]);
            $orderId = (int)$pdo->lastInsertId();
        }

        // Удаляем старые позиции
        $pdo->prepare("DELETE FROM sa_order_items WHERE order_id = ?")->execute([$orderId]);

        // Вставляем новые позиции
        if ($validItems) {
            $itemStmt = $pdo->prepare("
                INSERT INTO sa_order_items
                    (order_id, sku, product_name, external_code, analog_group, category, multiplicity, quantity)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($validItems as $item) {
                $itemStmt->execute([
                    $orderId,
                    $item['sku'],
                    $item['product_name'],
                    $item['external_code'],
                    $item['analog_group'],
                    $item['category'],
                    $item['multiplicity'],
                    $item['quantity'],
                ]);
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('sa/order POST error: ' . $e->getMessage());
        saRespond(['error' => 'Ошибка сохранения заказа'], 500);
    }

    saRespond(['success' => true, 'order_id' => $orderId]);
}

// ═══════════════════════════════════════════════
// Маршруты для отдела закупок (требуется сессия основного приложения)
// ═══════════════════════════════════════════════

if ($saAction === 'admin') {
    // Проверяем авторизацию отдела закупок
    $sessionUser = getSessionUser($pdo);
    if (!$sessionUser) {
        if (!checkApiKey($pdo)) saRespond(['error' => 'Unauthorized'], 401);
    }

    // RBAC: проверяем доступ к модулю supply-assistant
    if ($sessionUser) {
        global $ROLE_TEMPLATES, $ACCESS_LEVELS;
        $userRole = $sessionUser['role'] ?? 'user';
        if ($userRole !== 'admin') {
            $perms = resolvePermissions($userRole, $sessionUser['permissions'] ?? null, $ROLE_TEMPLATES);
            $saRequiredLevel = ($method === 'GET') ? $ACCESS_LEVELS['view'] : $ACCESS_LEVELS['edit'];
            $saUserLevel = $ACCESS_LEVELS[$perms['supply-assistant'] ?? 'none'] ?? 0;
            if ($saUserLevel < $saRequiredLevel) {
                saRespond(['error' => 'Недостаточно прав для модуля «Сбор заказа основной поставки»'], 403);
            }
        }
    }

    $adminAction = $saParts[2] ?? '';
    $adminParam  = $saParts[3] ?? null;

    // ── GET sa/admin/orders ──────────────────────────────────────────────────
    // Список заказов с фильтрами: ?restaurant=, ?date_from=, ?date_to=, ?legal_entity=
    if ($adminAction === 'orders' && $method === 'GET') {
        $restaurant  = $_GET['restaurant'] ?? null;
        $dateFrom    = $_GET['date_from'] ?? null;
        $dateTo      = $_GET['date_to'] ?? null;
        $le          = $_GET['legal_entity'] ?? null;

        $where  = [];
        $params = [];

        if ($le) {
            $where[]  = 'o.legal_entity = ?';
            $params[] = $le;
        }
        if ($restaurant) {
            $where[]  = 'o.restaurant_number = ?';
            $params[] = (int)$restaurant;
        }
        if ($dateFrom) {
            $where[]  = 'o.delivery_date >= ?';
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $where[]  = 'o.delivery_date <= ?';
            $params[] = $dateTo;
        }

        $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $s = $pdo->prepare("
            SELECT
                o.id,
                o.restaurant_number,
                o.legal_entity,
                o.legal_entity_group,
                o.delivery_date,
                o.comment,
                o.updated_by,
                o.created_at,
                o.updated_at,
                r.city,
                r.address,
                COUNT(i.id)        AS item_count,
                COALESCE(SUM(i.quantity), 0) AS total_qty
            FROM sa_orders o
            LEFT JOIN restaurants r
                ON r.number = o.restaurant_number
               AND r.legal_entity_group = o.legal_entity_group
               AND r.active = 1
            LEFT JOIN sa_order_items i ON i.order_id = o.id
            $whereClause
            GROUP BY o.id
            ORDER BY o.delivery_date DESC
            LIMIT 500
        ");
        $s->execute($params);
        $orders = $s->fetchAll();

        saRespond(['orders' => $orders]);
    }

    // ── GET sa/admin/order/:id ───────────────────────────────────────────────
    // Детали одного заказа + позиции
    if ($adminAction === 'order' && $adminParam !== null && $method === 'GET') {
        $orderId = (int)$adminParam;

        $os = $pdo->prepare("
            SELECT o.*,
                   r.city,
                   r.address
            FROM sa_orders o
            LEFT JOIN restaurants r
                ON r.number = o.restaurant_number
               AND r.legal_entity_group = o.legal_entity_group
               AND r.active = 1
            WHERE o.id = ?
            LIMIT 1
        ");
        $os->execute([$orderId]);
        $order = $os->fetch();

        if (!$order) saRespond(['error' => 'Заказ не найден'], 404);

        $is = $pdo->prepare("
            SELECT * FROM sa_order_items
            WHERE order_id = ?
            ORDER BY category, product_name
        ");
        $is->execute([$orderId]);
        $items = $is->fetchAll();

        $order['items'] = $items;
        saRespond(['order' => $order]);
    }

    // ── PATCH sa/admin/order/:id ─────────────────────────────────────────────
    // Правка заказа отделом закупок: comment + позиции
    if ($adminAction === 'order' && $adminParam !== null && $method === 'PATCH') {
        $orderId = (int)$adminParam;
        $comment = $body['comment'] ?? null;
        $items   = $body['items'] ?? [];
        $updatedBy = $sessionUser['name'] ?? ($sessionUser['username'] ?? null);

        // Проверяем, что заказ существует
        $checkStmt = $pdo->prepare("SELECT id FROM sa_orders WHERE id = ? LIMIT 1");
        $checkStmt->execute([$orderId]);
        if (!$checkStmt->fetch()) saRespond(['error' => 'Заказ не найден'], 404);

        // Фильтруем и валидируем позиции
        $validItems = [];
        foreach ($items as $item) {
            $qty = (float)($item['quantity'] ?? 0);
            if ($qty <= 0) continue;
            $validItems[] = [
                'sku'           => mb_substr(trim((string)($item['sku'] ?? '')), 0, 50),
                'product_name'  => mb_substr(trim((string)($item['product_name'] ?? '')), 0, 255),
                'category'      => mb_substr(trim((string)($item['category'] ?? '')), 0, 20),
                'quantity'      => $qty,
                'multiplicity'  => max(1, (int)($item['multiplicity'] ?? 1)),
                'external_code' => mb_substr(trim((string)($item['external_code'] ?? '')), 0, 20) ?: null,
                'analog_group'  => mb_substr(trim((string)($item['analog_group'] ?? '')), 0, 255) ?: null,
            ];
        }

        // Проверка кратности
        $multErrors = [];
        foreach ($validItems as $item) {
            if (roHasMultiplicityViolation($item['quantity'], $item['multiplicity'])) {
                $multErrors[] = [
                    'sku'          => $item['sku'],
                    'product_name' => $item['product_name'],
                    'quantity'     => $item['quantity'],
                    'multiplicity' => $item['multiplicity'],
                ];
            }
        }
        if (!empty($multErrors)) {
            saRespond([
                'error'       => 'Нарушена кратность у ' . count($multErrors) . ' позиций',
                'mult_errors' => $multErrors,
            ], 400);
        }

        try {
            $pdo->beginTransaction();

            $updStmt = $pdo->prepare("
                UPDATE sa_orders
                SET comment    = ?,
                    updated_by = ?
                WHERE id = ?
            ");
            $updStmt->execute([$comment, $updatedBy, $orderId]);

            // Пересохраняем позиции
            $pdo->prepare("DELETE FROM sa_order_items WHERE order_id = ?")->execute([$orderId]);

            if ($validItems) {
                $itemStmt = $pdo->prepare("
                    INSERT INTO sa_order_items
                        (order_id, sku, product_name, external_code, analog_group, category, multiplicity, quantity)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($validItems as $item) {
                    $itemStmt->execute([
                        $orderId,
                        $item['sku'],
                        $item['product_name'],
                        $item['external_code'],
                        $item['analog_group'],
                        $item['category'],
                        $item['multiplicity'],
                        $item['quantity'],
                    ]);
                }
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('sa/admin/order PATCH error: ' . $e->getMessage());
            saRespond(['error' => 'Ошибка сохранения заказа'], 500);
        }

        saRespond(['success' => true]);
    }

    // ── DELETE sa/admin/order/:id ────────────────────────────────────────────
    // Удаление заказа (позиции каскадно удалятся по FK)
    if ($adminAction === 'order' && $adminParam !== null && $method === 'DELETE') {
        $orderId = (int)$adminParam;

        $checkStmt = $pdo->prepare("SELECT id FROM sa_orders WHERE id = ? LIMIT 1");
        $checkStmt->execute([$orderId]);
        if (!$checkStmt->fetch()) saRespond(['error' => 'Заказ не найден'], 404);

        $pdo->prepare("DELETE FROM sa_order_items WHERE order_id = ?")->execute([$orderId]);
        $pdo->prepare("DELETE FROM sa_orders WHERE id = ?")->execute([$orderId]);

        saRespond(['success' => true]);
    }

    // ── GET sa/admin/templates ───────────────────────────────────────────────
    // Список шаблона товаров. ?legal_entity= (обязателен), ?category= (необязателен)
    if ($adminAction === 'templates' && $method === 'GET') {
        $le       = $_GET['legal_entity'] ?? null;
        $category = $_GET['category'] ?? null;

        if (!$le) saRespond(['error' => 'Не указан legal_entity'], 400);

        $q      = "
            SELECT
                t.*,
                COALESCE(NULLIF(t.multiplicity, 0), p.multiplicity, 1) AS multiplicity,
                p.external_code,
                p.analog_group
            FROM ro_templates t
            LEFT JOIN products p
                ON p.sku = t.sku
               AND p.legal_entity = ?
               AND p.is_active = 1
            WHERE t.legal_entity = ?
              AND t.is_active = 1
        ";
        $params = [$le, $le];

        if ($category) {
            $q      .= ' AND t.category = ?';
            $params[] = $category;
        }

        $q .= ' ORDER BY t.sort_order, t.product_name';

        $s = $pdo->prepare($q);
        $s->execute($params);
        saRespond(['templates' => $s->fetchAll()]);
    }

    // ── GET sa/admin/stock-products ──────────────────────────────────────────
    // Товары из «Сроков годности» для импорта в шаблон (сторона отдела закупок).
    if ($adminAction === 'stock-products' && $method === 'GET') {
        $le = $_GET['legal_entity'] ?? null;
        if (!$le) saRespond(['error' => 'Не указан legal_entity'], 400);
        saRespond(['products' => saStockProductsForEntity($pdo, $le)]);
    }

    // ── POST sa/admin/templates ──────────────────────────────────────────────
    // Сохранить шаблон. Совместим с ro/admin/templates POST (action=save).
    if ($adminAction === 'templates' && $method === 'POST') {
        $action   = $body['action'] ?? 'save';
        $le       = $body['legal_entity'] ?? null;
        $category = $body['category'] ?? '';
        $items    = $body['items'] ?? [];

        if (!$le)       saRespond(['error' => 'Не указан legal_entity'], 400);
        if (!$category) saRespond(['error' => 'Не указана категория'], 400);

        if ($action === 'save') {
            // Удаляем старые позиции категории
            $pdo->prepare("DELETE FROM ro_templates WHERE legal_entity = ? AND category = ?")->execute([$le, $category]);

            $insert = $pdo->prepare("
                INSERT INTO ro_templates (legal_entity, category, sku, product_name, multiplicity, sort_order, is_active)
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ");
            foreach ($items as $i => $item) {
                $mult = intval($item['multiplicity'] ?? 0);
                $insert->execute([
                    $le,
                    $category,
                    $item['sku'] ?? '',
                    $item['product_name'] ?? '',
                    $mult > 0 ? $mult : 1,
                    $i,
                ]);
            }
            saRespond(['success' => true, 'count' => count($items)]);
        }

        saRespond(['error' => 'Unknown action'], 400);
    }

    saRespond(['error' => 'Not found'], 404);
}
