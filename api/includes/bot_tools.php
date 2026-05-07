<?php
// ═══ AI с Tool Use для Telegram-бота (Gemini + Groq) ═══
// askWithTools — основная функция: отправляет вопрос, модель сама выбирает какие данные запросить

function getToolsSystemPrompt() {
    return <<<'PROMPT'
Ты — ассистент отдела закупок сети Burger King в Беларуси. Работаешь в Telegram-боте.

== ЮРЛИЦА ==
Три юридических лица:
- ООО «Бургер БК» (БК) — основное юрлицо Burger King
- ООО «Воглия Матта» (ВМ) — второе юрлицо Burger King
- ООО «Пицца Стар» (ПС) — юрлицо Pizza Star
У каждого СВОИ данные (товары, остатки, расход, заказы, цены). Данные в инструментах уже отфильтрованы по текущему юрлицу.

== ТЕРМИНЫ ==
ПСЦ — протокол согласования цен. Запас в днях = остаток ÷ дневной расход (≤3 критично, 3–7 мало, >14 норма).
DLT — срок доставки. DOC — срок документооборота. НДС — обычно 20%. Кратность — заказ кратен N коробок.
Кейсовка (qty_per_box) — штук/кг/л в коробке.

== ИНСТРУМЕНТЫ ==
Используй инструменты чтобы получить данные. Можешь вызвать несколько инструментов подряд. Не угадывай данные — всегда запрашивай через инструменты. Если нужна информация из нескольких источников — вызови все нужные инструменты.

== ПРАВИЛА ОТВЕТА ==
- Кратко, на русском, ТОЛЬКО по данным из инструментов
- ЗАПРЕЩЕНО выдумывать данные. Нет данных — так и скажи
- HTML для Telegram: <b>жирный</b>, <a href="url">текст</a>. НЕ используй Markdown
- Числа: «5 кор.», «120 шт.», «14 дн.», даты: ДД.ММ.ГГГГ
- Цены: всегда «без НДС» и «с НДС», указывай ставку
- Не повторяй вопрос. Ответ сразу по существу
- Если вопрос неоднозначный — уточни
- Если тема лучше раскрыта командой — предложи одну подходящую команду: /menu, /restaurant, /cards, /today, /orders, /deliveries, /plans, /stock, /analysis, /consumption, /prices, /psc, /schedule, /sales, /export, /settings
PROMPT;
}

function getToolDefinitions() {
    return [
        [
            'name' => 'search_product',
            'description' => 'Поиск товара по артикулу (SKU) или названию. Возвращает остатки, расход, запас в днях, цену, поставщика, кейсовку, ожидаемые поставки, последние заказы, реализацию ресторанов.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Артикул (число) или название товара для поиска']
                ],
                'required' => ['query']
            ]
        ],
        [
            'name' => 'get_stock_critical',
            'description' => 'Список товаров с критическим запасом (мало дней осталось). Можно указать порог в днях.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'max_days' => ['type' => 'integer', 'description' => 'Максимум дней запаса (по умолчанию 7)', 'default' => 7]
                ],
            ]
        ],
        [
            'name' => 'get_orders',
            'description' => 'Список заказов за последние N дней. Можно фильтровать по поставщику. Показывает состав заказа (все позиции).',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'supplier' => ['type' => 'string', 'description' => 'Название поставщика (необязательно)'],
                    'days' => ['type' => 'integer', 'description' => 'За сколько дней (по умолчанию 14)', 'default' => 14],
                    'limit' => ['type' => 'integer', 'description' => 'Сколько заказов показать (по умолчанию 5)', 'default' => 5]
                ],
            ]
        ],
        [
            'name' => 'get_deliveries',
            'description' => 'Ожидаемые поставки (заказы без приёмки). Можно фильтровать по поставщику или товару.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'supplier' => ['type' => 'string', 'description' => 'Поставщик (необязательно)'],
                    'product' => ['type' => 'string', 'description' => 'Товар для поиска в составе (необязательно)']
                ],
            ]
        ],
        [
            'name' => 'get_prices',
            'description' => 'Цены на товары и последние изменения цен. Можно искать по товару или поставщику.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Товар, артикул или поставщик (необязательно — без параметра покажет последние изменения)']
                ],
            ]
        ],
        [
            'name' => 'get_shelf_life',
            'description' => 'Сроки годности товаров на складе. Показывает истекающие, просроченные и заблокированные. Можно искать конкретный товар.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Название товара (необязательно)'],
                    'days' => ['type' => 'integer', 'description' => 'Показать истекающие в ближайшие N дней (по умолчанию 14)', 'default' => 14]
                ],
            ]
        ],
        [
            'name' => 'get_supplier_info',
            'description' => 'Информация о поставщике: контакты, DLT, DOC, последний заказ, ПСЦ, товары.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Название поставщика']
                ],
                'required' => ['name']
            ]
        ],
        [
            'name' => 'get_schedule',
            'description' => 'График доставок в рестораны. Можно указать номер ресторана или день недели.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'restaurant' => ['type' => 'string', 'description' => 'Номер или адрес ресторана (необязательно)'],
                    'day' => ['type' => 'string', 'description' => 'День недели (необязательно)']
                ],
            ]
        ],
        [
            'name' => 'get_plans',
            'description' => 'Планы поставок: периодичность заказов по поставщикам.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'supplier' => ['type' => 'string', 'description' => 'Поставщик (необязательно)']
                ],
            ]
        ],
        [
            'name' => 'get_psc',
            'description' => 'Протоколы согласования цен (ПСЦ): активные, истекающие, архивные.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'supplier' => ['type' => 'string', 'description' => 'Поставщик (необязательно)'],
                    'status' => ['type' => 'string', 'description' => 'Статус: active, expired, all (по умолчанию active)', 'default' => 'active']
                ],
            ]
        ],
        [
            'name' => 'get_sales',
            'description' => 'Реализация ресторанов: продажи по товарам/группам за 30 дней, тренды.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Товар или группа (необязательно — без параметра покажет топ)']
                ],
            ]
        ],
        [
            'name' => 'search_card',
            'description' => 'Поиск карточки товара: артикул, название, аналоги (замены).',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Артикул или название']
                ],
                'required' => ['query']
            ]
        ],
        [
            'name' => 'get_tenders',
            'description' => 'Список тендеров: статус, позиции, предложения поставщиков.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'description' => 'Статус: active, completed, all (по умолчанию active)', 'default' => 'active']
                ],
            ]
        ],
        [
            'name' => 'get_summary',
            'description' => 'Общая сводка на сегодня: количество товаров, поставщиков, заказов, ожидающие поставки, критические остатки, просроченные ПСЦ.',
            'input_schema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
            ]
        ],
        [
            'name' => 'run_sql',
            'description' => 'Выполнить произвольный SELECT-запрос к базе данных. Используй только когда другие инструменты не подходят. Доступные таблицы: products, analysis_data, orders, order_items, suppliers, plans, price_agreements, product_prices, price_history, stock_malling, restaurants, delivery_schedule, restaurant_sales, cards, tenders, tender_items, tender_offers. ТОЛЬКО SELECT, лимит 50 строк. ЗАПРЕЩЕНО: users, ro_users, user_sessions, ro_telegram_subs, password_reset_codes, ro_tg_tokens, api_keys, audit_log, bug_reports, information_schema, mysql.*; колонки password, password_hash, session_token, token, reset_token, api_key; UNION. На такие запросы сервер вернёт ошибку.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'sql' => ['type' => 'string', 'description' => 'SQL SELECT запрос'],
                    'params' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Параметры для prepared statement (необязательно)']
                ],
                'required' => ['sql']
            ]
        ]
    ];
}

// Выполнение tool call — возвращает текст с результатами
function executeTool($toolName, $input, $entity) {
    global $pdo, $SITE_URL;

    switch ($toolName) {
        case 'search_product':
            return toolSearchProduct($input['query'] ?? '', $entity);
        case 'get_stock_critical':
            return toolStockCritical($input['max_days'] ?? 7, $entity);
        case 'get_orders':
            return toolGetOrders($input['supplier'] ?? null, $input['days'] ?? 14, $input['limit'] ?? 5, $entity);
        case 'get_deliveries':
            return toolGetDeliveries($input['supplier'] ?? null, $input['product'] ?? null, $entity);
        case 'get_prices':
            return toolGetPrices($input['query'] ?? null, $entity);
        case 'get_shelf_life':
            return toolShelfLife($input['query'] ?? null, $input['days'] ?? 14, $entity);
        case 'get_supplier_info':
            return toolSupplierInfo($input['name'] ?? '', $entity);
        case 'get_schedule':
            return toolSchedule($input['restaurant'] ?? null, $input['day'] ?? null, $entity);
        case 'get_plans':
            return toolPlans($input['supplier'] ?? null, $entity);
        case 'get_psc':
            return toolPsc($input['supplier'] ?? null, $input['status'] ?? 'active', $entity);
        case 'get_sales':
            return toolSales($input['query'] ?? null);
        case 'search_card':
            return toolSearchCard($input['query'] ?? '');
        case 'get_tenders':
            return toolTenders($input['status'] ?? 'active', $entity);
        case 'get_summary':
            return toolSummary($entity);
        case 'run_sql':
            return toolRunSql($input['sql'] ?? '', $input['params'] ?? [], $entity);
        default:
            return "Неизвестный инструмент: {$toolName}";
    }
}

// ═══ Tool implementations ═══

function toolSearchProduct($query, $entity) {
    // Переиспользуем существующую логику из bot_lookup.php
    $result = lookupProduct($query, $entity);
    return $result ?: "Товар «{$query}» не найден.";
}

function toolStockCritical($maxDays, $entity) {
    global $pdo;
    $sql = "SELECT a.sku, p.name, a.stock, a.consumption, a.period_days, p.supplier,
                   COALESCE(p.unit_of_measure, 'шт') as uom,
                   ROUND(a.stock / (a.consumption / GREATEST(a.period_days, 1))) as days_left
            FROM analysis_data a
            LEFT JOIN products p ON p.sku = a.sku AND p.legal_entity = a.legal_entity AND p.is_active = 1
            WHERE a.consumption > 0 AND a.stock > 0";
    $params = [];
    if ($entity) { $sql .= " AND a.legal_entity = ?"; $params[] = $entity; }
    $sql .= " HAVING days_left <= " . intval($maxDays) . " ORDER BY days_left ASC LIMIT 30";
    $s = $pdo->prepare($sql); $s->execute($params);
    $items = $s->fetchAll();
    if (!$items) return "Товаров с запасом ≤ {$maxDays} дней не найдено.";

    $result = "Товары с запасом ≤ {$maxDays} дней:\n";
    foreach ($items as $i) {
        $daily = round($i['consumption'] / max($i['period_days'], 1), 1);
        $name = $i['name'] ? $i['sku'] . ' ' . $i['name'] : $i['sku'];
        $u = getUomLabel($i['uom'] ?? 'шт');
        $result .= "• {$name}: остаток {$i['stock']} {$u}, расход {$daily} {$u}/день, запас ~{$i['days_left']} дн. ({$i['supplier']})\n";
    }
    return $result;
}

function toolGetOrders($supplier, $days, $limit, $entity) {
    global $pdo, $SITE_URL;
    $sql = "SELECT o.id, o.supplier, o.created_by, o.created_at, o.delivery_date, o.received_at
            FROM orders o WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
    $params = [$days];
    if ($entity) { $sql .= " AND o.legal_entity = ?"; $params[] = $entity; }
    if ($supplier) {
        $sql .= " AND o.supplier LIKE ?"; $params[] = "%{$supplier}%";
    }
    $sql .= " ORDER BY o.created_at DESC LIMIT " . min(intval($limit), 10);
    $s = $pdo->prepare($sql); $s->execute($params);
    $orders = $s->fetchAll();
    if (!$orders) return "Заказов не найдено" . ($supplier ? " по поставщику «{$supplier}»" : "") . " за {$days} дней.";

    $result = '';
    foreach ($orders as $o) {
        $date = date('d.m.Y', strtotime($o['created_at']));
        $delivery = $o['delivery_date'] ? date('d.m.Y', strtotime($o['delivery_date'])) : '—';
        $status = $o['received_at'] ? '✅ принят' : '⏳ ожидает';
        $url = "{$SITE_URL}/order?orderId={$o['id']}&mode=view";
        $result .= "\nЗаказ #{$o['id']} — {$o['supplier']}, создан {$date}, приход {$delivery}, {$status}, автор: {$o['created_by']}\nСостав:\n";

        $s2 = $pdo->prepare("SELECT oi.sku, oi.name, oi.qty_boxes, oi.qty_per_box, COALESCE(p.unit_of_measure, 'шт') as uom
                FROM order_items oi
                LEFT JOIN orders ord ON ord.id = oi.order_id
                LEFT JOIN products p ON p.sku = oi.sku AND p.legal_entity = ord.legal_entity AND p.is_active = 1
                WHERE oi.order_id = ? ORDER BY oi.name");
        $s2->execute([$o['id']]);
        $items = $s2->fetchAll();
        foreach ($items as $it) {
            $pcs = $it['qty_boxes'] * max($it['qty_per_box'], 1);
            $u = getUomLabel($it['uom']);
            $result .= "  {$it['sku']} {$it['name']}: {$it['qty_boxes']} кор. ({$pcs} {$u})\n";
        }
        $result .= "Итого: " . count($items) . " позиций. Ссылка: {$url}\n";
    }
    return $result;
}

function toolGetDeliveries($supplier, $product, $entity) {
    global $pdo, $SITE_URL;
    $sql = "SELECT o.id, o.supplier, o.delivery_date, o.created_at,
                   DATEDIFF(CURDATE(), o.delivery_date) as overdue_days
            FROM orders o WHERE o.received_at IS NULL";
    $params = [];
    if ($entity) { $sql .= " AND o.legal_entity = ?"; $params[] = $entity; }
    if ($supplier) { $sql .= " AND o.supplier LIKE ?"; $params[] = "%{$supplier}%"; }
    $sql .= " ORDER BY o.delivery_date ASC LIMIT 15";
    $s = $pdo->prepare($sql); $s->execute($params);
    $orders = $s->fetchAll();
    if (!$orders) return "Ожидающих поставок не найдено.";

    $result = '';
    foreach ($orders as $o) {
        $dd = $o['delivery_date'] ? date('d.m.Y', strtotime($o['delivery_date'])) : '—';
        $overdue = ($o['delivery_date'] && $o['overdue_days'] > 0) ? " ⚠️ просрочена на {$o['overdue_days']} дн." : '';
        $url = "{$SITE_URL}/order?orderId={$o['id']}&mode=view";
        $result .= "\n{$o['supplier']} — приход {$dd}{$overdue}\n";

        $itemSql = "SELECT oi.sku, oi.name, oi.qty_boxes, oi.qty_per_box, COALESCE(p.unit_of_measure, 'шт') as uom
                FROM order_items oi
                LEFT JOIN orders ord ON ord.id = oi.order_id
                LEFT JOIN products p ON p.sku = oi.sku AND p.legal_entity = ord.legal_entity AND p.is_active = 1
                WHERE oi.order_id = ?";
        $itemParams = [$o['id']];
        if ($product) {
            $itemSql .= " AND (oi.name LIKE ? OR oi.sku LIKE ?)";
            $itemParams[] = "%{$product}%"; $itemParams[] = "%{$product}%";
        }
        $itemSql .= " ORDER BY oi.name";
        $s2 = $pdo->prepare($itemSql); $s2->execute($itemParams);
        $items = $s2->fetchAll();
        foreach ($items as $it) {
            $pcs = $it['qty_boxes'] * max($it['qty_per_box'], 1);
            $u = getUomLabel($it['uom']);
            $result .= "  {$it['sku']} {$it['name']}: {$it['qty_boxes']} кор. ({$pcs} {$u})\n";
        }
        if (!$items && $product) $result .= "  (товар «{$product}» не найден в этом заказе)\n";
    }
    return $result;
}

function toolGetPrices($query, $entity) {
    global $pdo;
    if ($query) {
        // Поиск цен по товару
        $sql = "SELECT pp.sku, p.name, pp.price, pp.currency, pp.vat_rate, pp.unit_type, p.supplier
                FROM product_prices pp
                LEFT JOIN products p ON p.sku = pp.sku AND p.legal_entity = pp.legal_entity AND p.is_active = 1
                WHERE pp.price_type = 'purchase' AND (pp.sku LIKE ? OR p.name LIKE ? OR p.supplier LIKE ?)";
        $params = ["%{$query}%", "%{$query}%", "%{$query}%"];
        if ($entity) { $sql .= " AND pp.legal_entity = ?"; $params[] = $entity; }
        $sql .= " LIMIT 20";
        $s = $pdo->prepare($sql); $s->execute($params);
        $prices = $s->fetchAll();
        if (!$prices) return "Цены по запросу «{$query}» не найдены.";
        $result = '';
        $unitLabels = ['piece'=>'шт','box'=>'кор','thousand'=>'тыс/шт','kg'=>'кг','liter'=>'л'];
        foreach ($prices as $p) {
            $vat = $p['vat_rate'] ?? 20;
            $withVat = round($p['price'] * (1 + $vat / 100), 2);
            $unit = $unitLabels[$p['unit_type']] ?? $p['unit_type'];
            $result .= "• {$p['sku']} {$p['name']}: {$p['price']} {$p['currency']}/{$unit} (без НДС), НДС {$vat}%, с НДС: {$withVat}. Поставщик: {$p['supplier']}\n";
        }
        return $result;
    }
    // Последние изменения цен
    $sql = "SELECT ph.sku, p.name, ph.supplier, ph.old_price, ph.new_price, ph.changed_at
            FROM price_history ph
            LEFT JOIN products p ON p.sku = ph.sku AND p.legal_entity = ph.legal_entity AND p.is_active = 1";
    $params = [];
    if ($entity) { $sql .= " WHERE ph.legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY ph.changed_at DESC LIMIT 15";
    $s = $pdo->prepare($sql); $s->execute($params);
    $changes = $s->fetchAll();
    if (!$changes) return "Изменений цен не найдено.";
    $result = "Последние изменения цен:\n";
    foreach ($changes as $c) {
        $name = $c['name'] ?: $c['sku'];
        $date = date('d.m.Y', strtotime($c['changed_at']));
        $diff = $c['new_price'] - $c['old_price'];
        $sign = $diff > 0 ? '↑' : '↓';
        $result .= "• {$name} ({$c['supplier']}): {$c['old_price']} → {$c['new_price']} BYN ({$sign}) — {$date}\n";
    }
    return $result;
}

function toolShelfLife($query, $days, $entity) {
    global $pdo;
    $customerFilter = '';
    $customerParams = [];
    if ($entity) {
        $customerName = null;
        if (strpos($entity, 'Бургер') !== false) $customerName = 'Бургер БК';
        elseif (strpos($entity, 'Воглия') !== false) $customerName = 'Воглия Матта';
        elseif (strpos($entity, 'Пицца') !== false) $customerName = 'Пицца Стар';
        if ($customerName) { $customerFilter = ' AND customer = ?'; $customerParams = [$customerName]; }
    }
    $result = '';
    if ($query) {
        $sql = "SELECT product_name, customer, warehouse, expiry_date, quantity, expiry_status, block_reason,
                       DATEDIFF(expiry_date, CURDATE()) as days_left
                FROM stock_malling WHERE product_name LIKE ?" . $customerFilter . " ORDER BY expiry_date ASC LIMIT 20";
        $s = $pdo->prepare($sql); $s->execute(array_merge(["%{$query}%"], $customerParams));
        $items = $s->fetchAll();
        if (!$items) return "Товар «{$query}» не найден в данных по срокам годности.";
        foreach ($items as $i) {
            $date = date('d.m.Y', strtotime($i['expiry_date']));
            $status = $i['block_reason'] ?: $i['expiry_status'];
            $result .= "• {$i['product_name']} [{$i['customer']}]: до {$date} ({$i['days_left']} дн.), {$i['quantity']} шт., склад: {$i['warehouse']}, статус: {$status}\n";
        }
        return $result;
    }
    // Истекающие в ближайшие N дней
    $sql = "SELECT product_name, customer, warehouse, expiry_date, quantity, expiry_status,
                   DATEDIFF(expiry_date, CURDATE()) as days_left
            FROM stock_malling
            WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)" . $customerFilter . "
            ORDER BY expiry_date ASC LIMIT 25";
    $s = $pdo->prepare($sql); $s->execute(array_merge([$days], $customerParams));
    $expiring = $s->fetchAll();
    if ($expiring) {
        $result .= "Истекают в ближайшие {$days} дней:\n";
        foreach ($expiring as $e) {
            $date = date('d.m.Y', strtotime($e['expiry_date']));
            $result .= "• {$e['product_name']} [{$e['customer']}]: до {$date} ({$e['days_left']} дн.), {$e['quantity']} шт., склад: {$e['warehouse']}\n";
        }
    }
    // Просроченные/заблокированные
    $sql2 = "SELECT product_name, customer, expiry_status, block_reason, quantity, expiry_date
            FROM stock_malling WHERE (expiry_status != 'Годен' OR expiry_date < CURDATE() OR block_reason IS NOT NULL)" . $customerFilter . " LIMIT 15";
    $s2 = $pdo->prepare($sql2); $s2->execute($customerParams);
    $blocked = $s2->fetchAll();
    if ($blocked) {
        $result .= "\nЗаблокированные/просроченные:\n";
        foreach ($blocked as $b) {
            $reason = $b['block_reason'] ?: $b['expiry_status'];
            $result .= "• {$b['product_name']} [{$b['customer']}]: {$b['quantity']} шт., статус: {$reason}\n";
        }
    }
    return $result ?: "Нет данных по срокам годности.";
}

function toolSupplierInfo($name, $entity) {
    // Переиспользуем lookupSupplier
    $result = lookupSupplier("поставщик {$name}", $entity);
    return $result ?: "Поставщик «{$name}» не найден.";
}

function toolSchedule($restaurant, $day, $entity) {
    $result = lookupSchedule("график" . ($restaurant ? " ресторан {$restaurant}" : '') . ($day ? " {$day}" : ''), $entity);
    return $result ?: "Данные по графику не найдены.";
}

function toolPlans($supplier, $entity) {
    $result = lookupPlans("план" . ($supplier ? " {$supplier}" : ''), $entity);
    return $result ?: "Планы поставок не найдены.";
}

function toolPsc($supplier, $status, $entity) {
    global $pdo;
    $sql = "SELECT number, supplier, type, valid_from, valid_to, status, DATEDIFF(valid_to, CURDATE()) as days_left
            FROM price_agreements WHERE 1=1";
    $params = [];
    if ($status !== 'all') { $sql .= " AND status = ?"; $params[] = $status; }
    if ($entity) { $sql .= " AND legal_entity = ?"; $params[] = $entity; }
    if ($supplier) { $sql .= " AND supplier LIKE ?"; $params[] = "%{$supplier}%"; }
    $sql .= " ORDER BY valid_to ASC LIMIT 20";
    $s = $pdo->prepare($sql); $s->execute($params);
    $psc = $s->fetchAll();
    if (!$psc) return "Протоколы ПСЦ не найдены.";
    $result = '';
    foreach ($psc as $p) {
        $from = date('d.m.Y', strtotime($p['valid_from']));
        $to = date('d.m.Y', strtotime($p['valid_to']));
        $statusLabel = $p['status'] === 'active' ? '✅ активен' : ($p['status'] === 'expired' ? '❌ истёк' : $p['status']);
        $result .= "• {$p['number']} — {$p['supplier']}: {$from}–{$to}, {$statusLabel}";
        if ($p['days_left'] !== null && $p['status'] === 'active') {
            $result .= ", осталось {$p['days_left']} дн.";
        }
        $result .= "\n";
    }
    return $result;
}

function toolSales($query) {
    $result = lookupSales("реализация" . ($query ? " {$query}" : ''), null);
    return $result ?: "Данные по реализации не найдены.";
}

function toolSearchCard($query) {
    $result = lookupCards("карточка {$query}", null);
    return $result ?: "Карточка «{$query}» не найдена.";
}

function toolTenders($status, $entity) {
    global $pdo;
    $sql = "SELECT id, name, status, created_at, created_by FROM tenders WHERE 1=1";
    $params = [];
    if ($status !== 'all') { $sql .= " AND status = ?"; $params[] = $status; }
    if ($entity) { $sql .= " AND legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY created_at DESC LIMIT 10";
    try {
        $s = $pdo->prepare($sql); $s->execute($params);
        $tenders = $s->fetchAll();
    } catch (Exception $e) {
        return "Таблица тендеров недоступна.";
    }
    if (!$tenders) return "Тендеров не найдено.";
    $result = '';
    foreach ($tenders as $t) {
        $date = date('d.m.Y', strtotime($t['created_at']));
        $result .= "• #{$t['id']} {$t['name']} — {$t['status']}, создан {$date} ({$t['created_by']})\n";
        // Позиции
        try {
            $s2 = $pdo->prepare("SELECT sku, product_name, quantity FROM tender_items WHERE tender_id = ? LIMIT 10");
            $s2->execute([$t['id']]);
            $items = $s2->fetchAll();
            foreach ($items as $it) {
                $result .= "  {$it['sku']} {$it['product_name']}: {$it['quantity']}\n";
            }
        } catch (Exception $e) {}
        // Предложения
        try {
            $s3 = $pdo->prepare("SELECT supplier, COUNT(*) as cnt FROM tender_offers WHERE tender_id = ? GROUP BY supplier");
            $s3->execute([$t['id']]);
            $offers = $s3->fetchAll();
            if ($offers) {
                $result .= "  Предложения: " . implode(', ', array_map(fn($o) => "{$o['supplier']} ({$o['cnt']} поз.)", $offers)) . "\n";
            }
        } catch (Exception $e) {}
    }
    return $result;
}

function toolSummary($entity) {
    global $pdo;
    $params = $entity ? [$entity] : [];
    $ef = $entity ? " AND legal_entity = ?" : "";
    $efW = $entity ? " WHERE legal_entity = ?" : "";

    $prodCount = $pdo->prepare("SELECT COUNT(*) FROM products WHERE is_active = 1" . $ef);
    $prodCount->execute($params);
    $result = "Активных товаров: " . $prodCount->fetchColumn() . "\n";

    $suppCount = $pdo->prepare("SELECT COUNT(DISTINCT supplier) FROM products WHERE is_active = 1" . $ef);
    $suppCount->execute($params);
    $result .= "Поставщиков: " . $suppCount->fetchColumn() . "\n";

    $ordCount = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" . str_replace('legal_entity', 'legal_entity', $ef));
    $ordCount->execute($params);
    $result .= "Заказов за 7 дней: " . $ordCount->fetchColumn() . "\n";

    $pendCount = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE received_at IS NULL" . $ef);
    $pendCount->execute($params);
    $result .= "Ожидающих поставок: " . $pendCount->fetchColumn() . "\n";

    // Просроченные поставки
    $overdueCount = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE received_at IS NULL AND delivery_date < CURDATE()" . $ef);
    $overdueCount->execute($params);
    $overdue = $overdueCount->fetchColumn();
    if ($overdue > 0) $result .= "⚠️ Просроченных поставок: {$overdue}\n";

    // Критичные остатки
    $critSql = "SELECT COUNT(*) FROM analysis_data a WHERE a.consumption > 0 AND a.stock > 0 AND a.stock / (a.consumption / GREATEST(a.period_days, 1)) <= 3";
    if ($entity) $critSql .= " AND a.legal_entity = ?";
    $s = $pdo->prepare($critSql); $s->execute($params);
    $critCount = $s->fetchColumn();
    if ($critCount > 0) $result .= "🔴 Товаров с запасом ≤3 дней: {$critCount}\n";

    // ПСЦ скоро истекают
    $pscSql = "SELECT COUNT(*) FROM price_agreements WHERE status = 'active' AND DATEDIFF(valid_to, CURDATE()) <= 14";
    if ($entity) $pscSql .= " AND legal_entity = ?";
    $s = $pdo->prepare($pscSql); $s->execute($params);
    $pscExpiring = $s->fetchColumn();
    if ($pscExpiring > 0) $result .= "📋 ПСЦ истекают в ближайшие 14 дней: {$pscExpiring}\n";

    $result .= "\nДата: " . date('d.m.Y H:i') . ", юрлицо: " . ($entity ?: 'все');
    return $result;
}

function toolRunSql($sql, $params, $entity) {
    global $pdo;
    // Безопасность: только SELECT
    $sqlClean = trim($sql);
    if (!preg_match('/^SELECT\b/i', $sqlClean)) return "Разрешены только SELECT-запросы.";
    if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|GRANT|REVOKE|CALL|SET)\b/i', $sqlClean)) return "Запрещённая операция.";
    if (preg_match('/INTO\s+(OUTFILE|DUMPFILE)\b|LOAD_FILE\s*\(/i', $sqlClean)) return "Запрещённая операция.";
    // Защита от функций, которые могут повесить сервер
    if (preg_match('/\b(SLEEP|BENCHMARK|GET_LOCK|RELEASE_LOCK|WAIT_FOR_EXECUTED_GTID_SET)\s*\(/i', $sqlClean)) return "Запрещённая функция.";

    // UNION может «приклеить» к легальному запросу секрет (SELECT 1, password FROM users).
    if (preg_match('/\bUNION\b/i', $sqlClean)) return "UNION в запросах запрещён.";

    // Системные таблицы — выгружают структуру и сами хеши через INFORMATION_SCHEMA / mysql.user.
    if (preg_match('/\b(information_schema|mysql|performance_schema|sys)\s*\./i', $sqlClean)) {
        return "Запрос к системным таблицам запрещён.";
    }

    // Blacklist таблиц с конфиденциальными данными (хеши паролей, активные
    // токены сессий/binding-кодов, аудит). Через бот доступ к ним не нужен.
    $forbiddenTables = [
        'users', 'ro_users', 'user_sessions', 'ro_telegram_subs',
        'password_reset_codes', 'password_reset_logs', 'ro_tg_tokens',
        'api_keys', 'audit_log', 'bug_reports',
    ];
    foreach ($forbiddenTables as $t) {
        if (preg_match('/\b' . preg_quote($t, '/') . '\b/i', $sqlClean)) {
            error_log("toolRunSql blocked: table '$t' in SQL: " . $sqlClean);
            return "Запрос обращается к закрытой таблице. Эти данные нельзя получать через бот.";
        }
    }

    // Blacklist опасных колонок (дополнительная страховка на случай, если
    // когда-то добавят таблицу с такими полями и забудут обновить список выше).
    $forbiddenColumns = ['password', 'password_hash', 'session_token', 'reset_token', 'api_key', 'must_reverify_by'];
    foreach ($forbiddenColumns as $c) {
        if (preg_match('/\b' . preg_quote($c, '/') . '\b/i', $sqlClean)) {
            error_log("toolRunSql blocked: column '$c' in SQL: " . $sqlClean);
            return "Запрос обращается к закрытой колонке.";
        }
    }
    // 'token' проверяем отдельно с границами без буквенно-цифровых символов,
    // чтобы не задеть невинные поля (next_token, tokenizer и т.п.).
    if (preg_match('/(^|[^a-zA-Z_])token($|[^a-zA-Z_])/i', $sqlClean)) {
        error_log("toolRunSql blocked: column 'token' in SQL: " . $sqlClean);
        return "Запрос обращается к закрытой колонке (token).";
    }

    // Добавляем LIMIT если нет
    if (!preg_match('/\bLIMIT\b/i', $sqlClean)) $sqlClean .= ' LIMIT 50';

    try {
        // Ограничение времени запроса — 10 секунд
        $pdo->exec("SET SESSION max_statement_time = 10");
        $s = $pdo->prepare($sqlClean);
        $s->execute($params ?: []);
        $rows = $s->fetchAll();
        // Восстанавливаем общий таймаут
        $pdo->exec("SET SESSION max_statement_time = 30");
        if (!$rows) return "Запрос не вернул результатов.";
        // Форматируем как текст
        $result = '';
        $cols = array_keys($rows[0]);
        $result .= implode(' | ', $cols) . "\n";
        foreach ($rows as $r) {
            $result .= implode(' | ', array_values($r)) . "\n";
        }
        return $result;
    } catch (Exception $e) {
        $pdo->exec("SET SESSION max_statement_time = 30");
        error_log("toolRunSql error: " . $e->getMessage());
        return "Ошибка выполнения запроса.";
    }
}

// ═══ Главная функция: пробуем Gemini, потом Groq с tool use ═══

function askWithTools($question, $entity, $userName) {
    // 1. Gemini
    $result = askGeminiWithTools($question, $entity, $userName);
    if ($result) return $result;

    // 2. Groq (Llama)
    $result = askGroqWithTools($question, $entity, $userName);
    if ($result) return $result;

    return null;
}

function askGeminiWithTools($question, $entity, $userName) {
    global $GEMINI_API_KEY;
    $apiKey = $GEMINI_API_KEY ?: ($_ENV['GEMINI_API_KEY'] ?? '');
    if (!$apiKey) return null;

    // Проверяем кэш квоты Gemini
    $geminiBlock = sys_get_temp_dir() . '/gemini_tools_blocked.txt';
    if (file_exists($geminiBlock) && time() - filemtime($geminiBlock) < 3600) {
        error_log("Gemini tools: blocked by quota cache");
        return null;
    }

    $systemPrompt = getToolsSystemPrompt();
    $systemPrompt .= "\n\nПользователь: {$userName}";
    if ($entity) $systemPrompt .= "\nТекущее юрлицо: {$entity}";
    $systemPrompt .= "\nСегодня: " . date('d.m.Y, l');

    // Конвертируем инструменты в формат Gemini
    $toolDefs = getToolDefinitions();
    $geminiFunctions = [];
    foreach ($toolDefs as $tool) {
        $fn = [
            'name' => $tool['name'],
            'description' => $tool['description'],
            'parameters' => $tool['input_schema'],
        ];
        $geminiFunctions[] = $fn;
    }

    $contents = [
        ['role' => 'user', 'parts' => [['text' => $question]]]
    ];

    // Цикл tool use (до 5 итераций)
    for ($i = 0; $i < 5; $i++) {
        $payload = [
            'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => $contents,
            'tools' => [['function_declarations' => $geminiFunctions]],
            'generationConfig' => ['maxOutputTokens' => 2048, 'temperature' => 0.1],
        ];

        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";
        $response = callGeminiToolsApi($url, $payload);
        if (!$response) return null;

        $candidate = $response['candidates'][0] ?? null;
        if (!$candidate) return null;

        $parts = $candidate['content']['parts'] ?? [];
        $functionCalls = [];
        $textParts = [];

        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                $functionCalls[] = $part['functionCall'];
            }
            if (isset($part['text'])) {
                $textParts[] = $part['text'];
            }
        }

        // Если модель вызывает инструменты
        if (!empty($functionCalls)) {
            // Добавляем ответ модели в историю
            $contents[] = ['role' => 'model', 'parts' => $parts];

            // Выполняем все function calls и формируем ответы
            $responseParts = [];
            foreach ($functionCalls as $fc) {
                $toolName = $fc['name'];
                $toolArgs = $fc['args'] ?? [];
                $toolResult = executeTool($toolName, $toolArgs, $entity);
                // Обрезаем очень длинные результаты
                if (mb_strlen($toolResult) > 8000) {
                    $toolResult = mb_substr($toolResult, 0, 7500) . "\n…(данных больше, показаны основные)";
                }
                $responseParts[] = [
                    'functionResponse' => [
                        'name' => $toolName,
                        'response' => ['result' => $toolResult]
                    ]
                ];
                error_log("Gemini tool: {$toolName}(" . json_encode($toolArgs, JSON_UNESCAPED_UNICODE) . ") => " . mb_strlen($toolResult) . " bytes");
            }
            $contents[] = ['role' => 'user', 'parts' => $responseParts];
            continue;
        }

        // Финальный ответ
        $answer = implode("\n", $textParts);
        // Убираем Markdown, если модель его использовала
        $answer = preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $answer);
        $answer = preg_replace('/^### (.+)$/mu', '<b>$1</b>', $answer);
        $answer = preg_replace('/^## (.+)$/mu', '<b>$1</b>', $answer);
        $answer = preg_replace('/^# (.+)$/mu', '<b>$1</b>', $answer);
        $answer = preg_replace('/```[\s\S]*?```/u', '', $answer); // убираем code blocks
        return trim($answer) ?: null;
    }

    return null;
}

function callGeminiToolsApi($url, $payload) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if (!$response || $httpCode !== 200) {
        $respPreview = $response ? mb_substr($response, 0, 500) : '(empty)';
        error_log("Gemini Tools API error: HTTP {$httpCode}, err={$err}, resp={$respPreview}");
        // Если квота исчерпана — запоминаем на час
        if ($httpCode === 429 || strpos($response ?: '', 'quota') !== false) {
            @file_put_contents(sys_get_temp_dir() . '/gemini_tools_blocked.txt', 'blocked');
        }
        return null;
    }

    return json_decode($response, true);
}

// ═══ Отбор релевантных инструментов (для Groq с лимитом токенов) ═══

function selectRelevantTools($question) {
    $q = mb_strtolower($question);
    $allTools = getToolDefinitions();
    $toolsByName = [];
    foreach ($allTools as $t) $toolsByName[$t['name']] = $t;

    // Всегда включаем search_product и get_summary
    $selected = ['search_product'];

    // По ключевым словам
    if (preg_match('/остат|запас|дней|заканч|кончает|мало|критич|дефицит/u', $q))
        $selected[] = 'get_stock_critical';
    if (preg_match('/заказ|состав|позиц|отправ/u', $q))
        $selected[] = 'get_orders';
    if (preg_match('/поставк|приед|привез|когда.*прие|ожидае|приход/u', $q))
        $selected[] = 'get_deliveries';
    if (preg_match('/цен|стоим|прайс|ндс|сколько стоит/u', $q))
        $selected[] = 'get_prices';
    if (preg_match('/срок|годн|истек|просроч|маллинг|блокир/u', $q))
        $selected[] = 'get_shelf_life';
    if (preg_match('/поставщик|контакт|dlt|кто постав/u', $q))
        $selected[] = 'get_supplier_info';
    if (preg_match('/график|расписан|ресторан|доставк/u', $q))
        $selected[] = 'get_schedule';
    if (preg_match('/план|периодич|частот/u', $q))
        $selected[] = 'get_plans';
    if (preg_match('/псц|протокол|согласован/u', $q))
        $selected[] = 'get_psc';
    if (preg_match('/реализац|продаж|тренд/u', $q))
        $selected[] = 'get_sales';
    if (preg_match('/карточк|аналог|замен/u', $q))
        $selected[] = 'search_card';
    if (preg_match('/тендер/u', $q))
        $selected[] = 'get_tenders';
    if (preg_match('/сводк|итог|общ|дашборд|сегодня/u', $q))
        $selected[] = 'get_summary';

    // Если ничего специфичного — добавляем основные
    if (count($selected) <= 1) {
        $selected = array_merge($selected, ['get_stock_critical', 'get_deliveries', 'get_prices', 'get_summary']);
    }

    // Максимум 6 инструментов для Groq
    $selected = array_unique(array_slice($selected, 0, 6));

    $result = [];
    foreach ($selected as $name) {
        if (isset($toolsByName[$name])) $result[] = $toolsByName[$name];
    }
    return $result;
}

// ═══ Groq с tool use (Llama модели) ═══

function askGroqWithTools($question, $entity, $userName) {
    $apiKey = $GLOBALS['GROQ_API_KEY'] ?? '';
    if (!$apiKey) return null;

    // Кэш rate limit
    $cacheFile = sys_get_temp_dir() . '/groq_tools_blocked.json';
    $blocked = [];
    if (file_exists($cacheFile)) {
        $blocked = json_decode(file_get_contents($cacheFile), true) ?: [];
    }
    $model = 'llama-3.3-70b-versatile';
    if (isset($blocked[$model]) && time() < $blocked[$model]) {
        error_log("Groq tools: {$model} rate-limited");
        return null;
    }

    $systemPrompt = getToolsSystemPrompt();
    $systemPrompt .= "\n\nПользователь: {$userName}";
    if ($entity) $systemPrompt .= "\nТекущее юрлицо: {$entity}";
    $systemPrompt .= "\nСегодня: " . date('d.m.Y, l');

    // Отбираем только релевантные инструменты (Groq имеет жёсткий лимит 12k TPM)
    $toolDefs = selectRelevantTools($question);
    $groqTools = [];
    foreach ($toolDefs as $tool) {
        $params = $tool['input_schema'];
        if (isset($params['required']) && empty($params['required'])) unset($params['required']);
        $groqTools[] = [
            'type' => 'function',
            'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => $params,
            ]
        ];
    }

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $question],
    ];

    // Цикл tool use (до 5 итераций)
    for ($i = 0; $i < 5; $i++) {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'tools' => $groqTools,
            'max_tokens' => 2048,
            'temperature' => 0.1,
        ];

        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if (!$response || $httpCode !== 200) {
            $respPreview = $response ? mb_substr($response, 0, 500) : '(empty)';
            error_log("Groq Tools API error: HTTP {$httpCode}, err={$err}, resp={$respPreview}");
            // Если 400 на повторном вызове — модель запуталась, пробуем без tools
            if ($httpCode === 400 && $i > 0) {
                error_log("Groq Tools: model confused on iteration {$i}, retrying without tools");
                $noToolPayload = [
                    'model' => $model,
                    'messages' => $messages,
                    'max_tokens' => 2048,
                    'temperature' => 0.1,
                ];
                $ch2 = curl_init('https://api.groq.com/openai/v1/chat/completions');
                curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($noToolPayload, JSON_UNESCAPED_UNICODE),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
                    CURLOPT_TIMEOUT => 15]);
                $resp2 = curl_exec($ch2); $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE); curl_close($ch2);
                if ($code2 === 200) {
                    $d2 = json_decode($resp2, true);
                    $answer = $d2['choices'][0]['message']['content'] ?? '';
                    // Очистка от артефактов function calling
                    $answer = preg_replace('/<function=.*?<\/function>/us', '', $answer);
                    $answer = preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $answer);
                    $answer = trim($answer);
                    return $answer ?: null;
                }
            }
            if ($httpCode === 429) {
                // Если wait < 5 сек — подождать и повторить
                $shortWait = 0;
                if (preg_match('/try again in ([\d.]+)s/i', $response, $sm)) {
                    $shortWait = floatval($sm[1]);
                }
                if ($shortWait > 0 && $shortWait <= 5 && $i < 3) {
                    usleep(intval($shortWait * 1000000) + 200000);
                    continue; // повторить итерацию
                }
                $waitSec = 600;
                if (preg_match('/try again in (\d+)m/i', $response, $mm)) $waitSec = intval($mm[1]) * 60;
                elseif ($shortWait > 0) $waitSec = intval(ceil($shortWait));
                $blocked[$model] = time() + $waitSec;
                @file_put_contents($cacheFile, json_encode($blocked));
            }
            return null;
        }

        $data = json_decode($response, true);
        $choice = $data['choices'][0] ?? null;
        if (!$choice) return null;

        $msg = $choice['message'];
        $finishReason = $choice['finish_reason'] ?? '';

        // Если модель вызывает инструменты
        if ($finishReason === 'tool_calls' && !empty($msg['tool_calls'])) {
            $messages[] = $msg; // добавляем ответ ассистента

            // Дедупликация — одинаковые вызовы пропускаем
            $seen = [];
            foreach ($msg['tool_calls'] as $tc) {
                $toolName = $tc['function']['name'];
                $toolArgs = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
                $key = $toolName . json_encode($toolArgs);
                if (isset($seen[$key])) {
                    // Дубль — возвращаем тот же результат
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $tc['id'], 'content' => $seen[$key]];
                    continue;
                }
                $toolResult = executeTool($toolName, $toolArgs, $entity);
                // Groq имеет жёсткий лимит токенов — агрессивно обрезаем
                if (mb_strlen($toolResult) > 3000) {
                    $toolResult = mb_substr($toolResult, 0, 2800) . "\n…(показаны основные данные)";
                }
                $seen[$key] = $toolResult;
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $tc['id'],
                    'content' => $toolResult,
                ];
                error_log("Groq tool: {$toolName}(" . json_encode($toolArgs, JSON_UNESCAPED_UNICODE) . ") => " . mb_strlen($toolResult) . " bytes");
            }
            continue;
        }

        // Финальный ответ
        $answer = $msg['content'] ?? '';
        // Убираем <think> если есть
        $answer = preg_replace('/<think>[\s\S]*?<\/think>/u', '', $answer);
        // Конвертируем Markdown в HTML
        $answer = preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $answer);
        $answer = preg_replace('/^### (.+)$/mu', '<b>$1</b>', $answer);
        $answer = preg_replace('/^## (.+)$/mu', '<b>$1</b>', $answer);
        $answer = preg_replace('/^# (.+)$/mu', '<b>$1</b>', $answer);
        return trim($answer) ?: null;
    }

    return null;
}
