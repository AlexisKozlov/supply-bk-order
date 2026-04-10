<?php
// ═══ Lookup-функции: поиск данных из БД для контекста ═══
// getUomLabel, stemRu, matchesKeywords, extractNumber
// gatherContext, lookupProduct, productFullInfo, lookupOrders,
// lookupStockDays, lookupShelfLife, lookupSupplier, lookupSchedule,
// lookupDeliveries, lookupPlans, lookupPrices, lookupSales, lookupCards

// ═══ Вспомогательные функции ═══

// Единица измерения → подпись для вывода
function getUomLabel($uom) {
    return $uom === 'л' ? 'л' : ($uom === 'кг' ? 'кг' : 'шт.');
}

// Стемирование русского слова (обрезка окончаний для поиска)
function stemRu($word) {
    if (mb_strlen($word) < 3) return $word;
    // 3-буквенные окончания (прилагательные, существительные)
    $stem = preg_replace('/(ого|его|ому|ему|ной|ным|ном|ную|ных|ами|ями|ями|ого|ому|ыми|ими|нем|нём|тся|ний|ние|ний|ния|нию|ией|ием|иям|ями|ать|ять|ить|еть|уть|ыть|ять|ось|ась)$/u', '', $word);
    if ($stem !== $word && mb_strlen($stem) >= 3) return $stem;
    // 2-буквенные окончания
    $stem = preg_replace('/(ов|ев|ей|ий|ой|ый|ая|яя|ое|ые|ие|ам|ям|ах|ях|ом|ем|ём|ую|юю|ых|их|ми|ки|ка|ку|ко|ке|ок|ек|ёк|ик|ть|ся|ны|на|но|ну|не|ни)$/u', '', $word);
    if ($stem !== $word && mb_strlen($stem) >= 3) return $stem;
    // 1-буквенные окончания
    $stem = preg_replace('/[аеёиоуыэюя]$/u', '', $word);
    return mb_strlen($stem) >= 3 ? $stem : $word;
}

// Проверка: содержит ли текст хотя бы одно из ключевых слов
function matchesKeywords($text, $keywords) {
    foreach ($keywords as $kw) {
        if (mb_strpos($text, $kw) !== false) return true;
    }
    return false;
}

// Распознавание числительных (цифрами и словами)
function extractNumber($text) {
    // Сначала ищем цифры
    if (preg_match('/(\d+)/u', $text, $m)) {
        return intval($m[1]);
    }
    // Числительные словами
    $words = [
        'один'=>1,'одного'=>1,'одной'=>1,
        'два'=>2,'двух'=>2,'двум'=>2,
        'три'=>3,'трёх'=>3,'трех'=>3,'трём'=>3,'трем'=>3,
        'четыре'=>4,'четырёх'=>4,'четырех'=>4,
        'пять'=>5,'пяти'=>5,
        'шесть'=>6,'шести'=>6,
        'семь'=>7,'семи'=>7,
        'восемь'=>8,'восьми'=>8,
        'девять'=>9,'девяти'=>9,
        'десять'=>10,'десяти'=>10,
        'одиннадцать'=>11,'двенадцать'=>12,'тринадцать'=>13,'четырнадцать'=>14,
        'пятнадцать'=>15,'двадцать'=>20,'тридцать'=>30,
    ];
    $lower = mb_strtolower($text);
    foreach ($words as $word => $num) {
        if (mb_strpos($lower, $word) !== false) return $num;
    }
    return null;
}

// ═══ Основные lookup-функции ═══

function gatherContext($user) {
    global $pdo;
    $entity = getUserEntity($user);
    $context = "Пользователь: {$user['name']}, роль: {$user['role']}";
    if ($entity) $context .= "\nТекущее юрлицо: {$entity} (все данные ниже — для этого юрлица)";
    $allEntities = implode(', ', $user['legal_entities']);
    $context .= "\nДоступные юрлица: {$allEntities}";
    $context .= "\nСегодня: " . date('d.m.Y, l') . "\n\n";

    $params = [];
    if ($entity) { $params[] = $entity; }

    // Общая статистика
    $sql = "SELECT COUNT(*) as cnt FROM products WHERE is_active = 1" . ($entity ? " AND legal_entity = ?" : "");
    $s = $pdo->prepare($sql); $s->execute($params);
    $prodCount = $s->fetch()['cnt'];
    $context .= "Всего активных товаров: {$prodCount}\n";

    $sql = "SELECT COUNT(DISTINCT supplier) as cnt FROM products WHERE is_active = 1" . ($entity ? " AND legal_entity = ?" : "");
    $s = $pdo->prepare($sql); $s->execute($params);
    $suppCount = $s->fetch()['cnt'];
    $context .= "Поставщиков: {$suppCount}\n";

    // Сводка по заказам
    $sql = "SELECT COUNT(*) as cnt FROM orders o WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" . ($entity ? " AND o.legal_entity = ?" : "");
    $s = $pdo->prepare($sql); $s->execute($params);
    $orderStats = $s->fetch();
    $context .= "Заказов за 7 дней: {$orderStats['cnt']}\n";

    // Последние 5 заказов
    $sql = "SELECT o.supplier, o.created_by, o.created_at, o.delivery_date,
                   (SELECT SUM(oi.qty_boxes) FROM order_items oi WHERE oi.order_id = o.id) as boxes
            FROM orders o WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)";
    if ($entity) { $sql .= " AND o.legal_entity = ?"; }
    $sql .= " ORDER BY o.created_at DESC LIMIT 5";
    $s = $pdo->prepare($sql); $s->execute($params);
    $recent = $s->fetchAll();
    if ($recent) {
        $context .= "\nПоследние заказы:\n";
        foreach ($recent as $r) {
            $context .= "- {$r['supplier']}: {$r['boxes']} кор., создан " . date('d.m', strtotime($r['created_at'])) . " ({$r['created_by']}), приход " . ($r['delivery_date'] ? date('d.m', strtotime($r['delivery_date'])) : '—') . "\n";
        }
    }

    // Низкие остатки (из analysis_data)
    $sql = "SELECT a.sku, p.name, a.stock FROM analysis_data a
            LEFT JOIN products p ON p.sku COLLATE utf8mb4_general_ci = a.sku COLLATE utf8mb4_general_ci AND p.legal_entity COLLATE utf8mb4_general_ci = a.legal_entity COLLATE utf8mb4_general_ci
            WHERE a.stock <= 5 AND a.stock >= 0";
    if ($entity) { $sql .= " AND a.legal_entity = ?"; }
    $sql .= " ORDER BY a.stock ASC LIMIT 10";
    $s = $pdo->prepare($sql); $s->execute($params);
    $lowStock = $s->fetchAll();
    if ($lowStock) {
        $context .= "\nНизкие остатки (≤5):\n";
        foreach ($lowStock as $ls) {
            $name = $ls['name'] ? $ls['sku'] . ' ' . $ls['name'] : $ls['sku'];
            $context .= "- {$name}: {$ls['stock']}\n";
        }
    }

    // Протоколы
    $sql = "SELECT number, supplier, valid_to, DATEDIFF(valid_to, CURDATE()) as days_left
            FROM price_agreements WHERE status = 'active'";
    if ($entity) { $sql .= " AND legal_entity = ?"; }
    $sql .= " ORDER BY valid_to ASC LIMIT 10";
    $s = $pdo->prepare($sql); $s->execute($params);
    $psc = $s->fetchAll();
    if ($psc) {
        $context .= "\nАктивные протоколы (ПСЦ):\n";
        foreach ($psc as $p) {
            $context .= "- {$p['number']} ({$p['supplier']}): до " . date('d.m.Y', strtotime($p['valid_to'])) . ", осталось {$p['days_left']} дн.\n";
        }
    }

    // Последние изменения цен (только действующие)
    $sql = "SELECT ph.sku, p.name as product_name, ph.supplier, ph.old_price, ph.new_price, ph.changed_at
            FROM price_history ph
            LEFT JOIN products p ON p.sku = ph.sku COLLATE utf8mb4_general_ci AND p.legal_entity = ph.legal_entity COLLATE utf8mb4_general_ci
            WHERE EXISTS (SELECT 1 FROM product_prices pp WHERE pp.sku = ph.sku COLLATE utf8mb4_general_ci AND pp.legal_entity = ph.legal_entity COLLATE utf8mb4_general_ci)";
    if ($entity) { $sql .= " AND ph.legal_entity = ?"; }
    $sql .= " ORDER BY ph.changed_at DESC LIMIT 10";
    $s = $pdo->prepare($sql); $s->execute($params);
    $prices = $s->fetchAll();
    if ($prices) {
        $context .= "\nПоследние изменения цен:\n";
        foreach ($prices as $pc) {
            $name = $pc['product_name'] ?: $pc['sku'];
            $context .= "- {$name} ({$pc['supplier']}): {$pc['old_price']} → {$pc['new_price']} BYN (" . date('d.m', strtotime($pc['changed_at'])) . ")\n";
        }
    }

    // Топ расхода
    $sql = "SELECT a.sku, p.name, a.consumption, a.period_days, COALESCE(p.unit_of_measure, 'шт') as uom FROM analysis_data a
            LEFT JOIN products p ON p.sku COLLATE utf8mb4_general_ci = a.sku COLLATE utf8mb4_general_ci AND p.legal_entity COLLATE utf8mb4_general_ci = a.legal_entity COLLATE utf8mb4_general_ci
            WHERE a.consumption > 0";
    if ($entity) { $sql .= " AND a.legal_entity = ?"; }
    $sql .= " ORDER BY (a.consumption / GREATEST(a.period_days, 1)) DESC LIMIT 10";
    $s = $pdo->prepare($sql); $s->execute($params);
    $consumption = $s->fetchAll();
    if ($consumption) {
        $context .= "\nТоп расхода:\n";
        foreach ($consumption as $c) {
            $daily = round($c['consumption'] / max($c['period_days'], 1), 1);
            $name = $c['name'] ? $c['sku'] . ' ' . $c['name'] : $c['sku'];
            $cu = $c['uom'] ?? 'шт';
            $cuLabel = getUomLabel($cu);
            $context .= "- {$name}: {$daily} {$cuLabel}/день\n";
        }
    }

    // Сроки годности — скоро истекающие (ближайшие 14 дней)
    $customerName = null;
    if ($entity) {
        if (strpos($entity, 'Бургер') !== false) $customerName = 'Бургер БК';
        elseif (strpos($entity, 'Воглия') !== false) $customerName = 'Воглия Матта';
        elseif (strpos($entity, 'Пицца') !== false) $customerName = 'Пицца Стар';
    }
    $shelfSql = "SELECT product_name, customer, warehouse, expiry_date, quantity, expiry_status,
                        DATEDIFF(expiry_date, CURDATE()) as days_left
                 FROM stock_malling
                 WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)";
    $shelfParams = [];
    if ($customerName) { $shelfSql .= " AND customer = ?"; $shelfParams[] = $customerName; }
    $shelfSql .= " ORDER BY expiry_date ASC LIMIT 10";
    $s = $pdo->prepare($shelfSql); $s->execute($shelfParams);
    $expiring = $s->fetchAll();
    if ($expiring) {
        $context .= "\nСроки годности (истекают в ближайшие 14 дней):\n";
        foreach ($expiring as $ex) {
            $date = date('d.m.Y', strtotime($ex['expiry_date']));
            $custLabel = $ex['customer'] ? " [{$ex['customer']}]" : '';
            $context .= "- {$ex['product_name']}{$custLabel}: до {$date} ({$ex['days_left']} дн.), {$ex['quantity']} шт., склад: {$ex['warehouse']}\n";
        }
    }

    // Заблокированные / просроченные
    $blockedSql = "SELECT product_name, customer, expiry_status, block_reason, quantity
                   FROM stock_malling
                   WHERE (expiry_status != 'Годен' OR expiry_date < CURDATE() OR block_reason IS NOT NULL)";
    $blockedParams = [];
    if ($customerName) { $blockedSql .= " AND customer = ?"; $blockedParams[] = $customerName; }
    $blockedSql .= " LIMIT 10";
    $s = $pdo->prepare($blockedSql); $s->execute($blockedParams);
    $blocked = $s->fetchAll();
    if ($blocked) {
        $context .= "\nЗаблокированные/просроченные на складе:\n";
        foreach ($blocked as $b) {
            $reason = $b['block_reason'] ?: $b['expiry_status'];
            $custLabel = $b['customer'] ? " [{$b['customer']}]" : '';
            $context .= "- {$b['product_name']}{$custLabel}: {$b['quantity']} шт., статус: {$reason}\n";
        }
    }

    // Ожидаемые поставки (заказы без факт. прихода)
    $delivSql = "SELECT o.supplier, o.delivery_date, o.created_at,
                        (SELECT SUM(oi.qty_boxes) FROM order_items oi WHERE oi.order_id = o.id) as boxes,
                        DATEDIFF(CURDATE(), o.delivery_date) as overdue_days
                 FROM orders o
                 WHERE o.received_at IS NULL";
    $delivParams = [];
    if ($entity) { $delivSql .= " AND o.legal_entity = ?"; $delivParams[] = $entity; }
    $delivSql .= " ORDER BY o.delivery_date ASC LIMIT 10";
    $s = $pdo->prepare($delivSql); $s->execute($delivParams);
    $deliveries = $s->fetchAll();
    if ($deliveries) {
        $context .= "\nОжидаемые поставки:\n";
        foreach ($deliveries as $d) {
            $dd = $d['delivery_date'] ? date('d.m', strtotime($d['delivery_date'])) : '—';
            $status = '';
            if ($d['delivery_date'] && $d['overdue_days'] > 0) {
                $status = " ⚠️ просрочена на {$d['overdue_days']} дн.";
            }
            $context .= "- {$d['supplier']}: {$d['boxes']} кор., ожид. {$dd}{$status}\n";
        }
    }

    // Планы поставок
    $planSql = "SELECT pl.supplier, pl.period_type, pl.period_count, pl.note, pl.updated_at
                FROM plans pl";
    $planParams = [];
    if ($entity) { $planSql .= " WHERE pl.legal_entity = ?"; $planParams[] = $entity; }
    $planSql .= " ORDER BY pl.supplier ASC LIMIT 15";
    $s = $pdo->prepare($planSql); $s->execute($planParams);
    $plans = $s->fetchAll();
    if ($plans) {
        $periodLabels = ['weeks' => 'нед.', 'months' => 'мес.'];
        $context .= "\nПланы поставок:\n";
        foreach ($plans as $pl) {
            $period = ($pl['period_count'] ?? 3) . ' ' . ($periodLabels[$pl['period_type']] ?? $pl['period_type']);
            $note = $pl['note'] ? " ({$pl['note']})" : '';
            $context .= "- {$pl['supplier']}: каждые {$period}{$note}\n";
        }
    }

    return $context;
}

// Поиск товара по артикулу или названию и сбор всех данных по нему
function lookupProduct($question, $entity) {
    global $pdo;
    // Определяем, вопрос ли это о поставке — тогда не нужна история заказов
    $q = mb_strtolower($question);
    $skipHistory = (bool) preg_match('/поставк|приед|привез|когда.*прие|ожидае|приход|привоз/u', $q);

    // Извлечь артикулы (числа 4+ цифр) из вопроса
    $skus = [];
    if (preg_match_all('/\b(\d{4,})\b/', $question, $m)) {
        $skus = $m[1];
    }

    // Извлечь возможные названия товаров:
    // 1) в кавычках
    $searchTerms = [];
    if (preg_match_all('/[«""]([^»""]+)[»""]/', $question, $m)) {
        $searchTerms = array_merge($searchTerms, $m[1]);
    }

    // 2) ключевые слова из вопроса (существительные 3+ букв, кроме стоп-слов)
    if (empty($skus) && empty($searchTerms)) {
        $stopWords = ['какой','какая','какие','каков','сколько','покажи','найди','расскажи','подскажи',
            'остаток','остатки','расход','заказ','заказы','цена','цены','товар','товары','продукт',
            'есть','нет','где','что','как','для','это','еще','ещё','уже','очень','можно','нужно','надо',
            'мне','наш','наши','весь','все','только','сейчас','когда','был','была','будет','день',
            'дней','штук','коробок','последний','сегодня','вчера','завтра','про','информация','инфо',
            'данные','скажи','ответь','дай','группа','аналог','аналоги','поставщик',
            'состав','заказа','заказов','последнего','покаж',
            'приедет','приедут','привезут','поставка','поставки','ожидает','доставка','приход',
            'литров','литр','кило','килограмм','штуки','коробки','упаковок','палет',
            'кейсовка','кейсовки','упаковка','фасовка','вложение','вложенность'];
        $words = preg_split('/[\s,.\-!?:;]+/u', mb_strtolower($question));
        foreach ($words as $w) {
            $w = trim($w);
            if (mb_strlen($w) >= 3 && !in_array($w, $stopWords) && !is_numeric($w)) {
                $stem = stemRu($w);
                $searchTerms[] = $stem;
            }
        }
    }

    if (empty($skus) && empty($searchTerms)) return '';

    $context = "\n== НАЙДЕННЫЕ ТОВАРЫ ==\n";
    $found = false;

    $eFilter = $entity ? " AND p.legal_entity = ?" : "";
    $eParams = $entity ? [$entity] : [];

    // Поиск по артикулам
    foreach ($skus as $sku) {
        $sql = "SELECT p.sku, p.name, p.supplier, p.qty_per_box, p.multiplicity, p.unit_of_measure, p.legal_entity, p.analog_group
                FROM products p WHERE p.sku = ? AND p.is_active = 1" . $eFilter . " LIMIT 3";
        $s = $pdo->prepare($sql);
        $s->execute(array_merge([$sku], $eParams));
        $products = $s->fetchAll();

        foreach ($products as $prod) {
            $found = true;
            $context .= productFullInfo($prod, $entity, $skipHistory);
        }

        if (empty($products)) {
            // Поиск по LIKE
            $sql = "SELECT p.sku, p.name, p.supplier, p.qty_per_box, p.multiplicity, p.unit_of_measure, p.legal_entity, p.analog_group
                    FROM products p WHERE p.sku LIKE ? AND p.is_active = 1" . $eFilter . " LIMIT 5";
            $s = $pdo->prepare($sql);
            $s->execute(array_merge(["%{$sku}%"], $eParams));
            $products = $s->fetchAll();
            foreach ($products as $prod) {
                $found = true;
                $context .= productFullInfo($prod, $entity, $skipHistory);
            }
        }
    }

    // Поиск по названию (и по группе аналогов) — приоритет товарам с остатками
    $foundSkus = [];

    // Если несколько слов — сначала ищем товары, содержащие ВСЕ слова (точный поиск)
    if (count($searchTerms) > 1) {
        // Строим SQL с AND для всех терминов
        $nameConditions = [];
        $nameParams = [];
        foreach ($searchTerms as $term) {
            $nameConditions[] = "(p.name LIKE ? OR p.analog_group LIKE ?)";
            $nameParams[] = "%{$term}%";
            $nameParams[] = "%{$term}%";
        }
        $allCondition = implode(' AND ', $nameConditions);

        // С данными анализа
        $sql = "SELECT p.sku, p.name, p.supplier, p.qty_per_box, p.multiplicity, p.unit_of_measure, p.legal_entity, p.analog_group
                FROM products p
                INNER JOIN analysis_data a ON a.sku COLLATE utf8mb4_general_ci = p.sku COLLATE utf8mb4_general_ci
                    AND a.legal_entity COLLATE utf8mb4_general_ci = p.legal_entity COLLATE utf8mb4_general_ci
                WHERE {$allCondition} AND p.is_active = 1" . $eFilter . "
                ORDER BY a.stock DESC LIMIT 10";
        $s = $pdo->prepare($sql);
        $s->execute(array_merge($nameParams, $eParams));
        $products = $s->fetchAll();

        // Без данных анализа
        if (empty($products)) {
            $sql = "SELECT p.sku, p.name, p.supplier, p.qty_per_box, p.multiplicity, p.unit_of_measure, p.legal_entity, p.analog_group
                    FROM products p WHERE {$allCondition} AND p.is_active = 1" . $eFilter . " LIMIT 10";
            $s = $pdo->prepare($sql);
            $s->execute(array_merge($nameParams, $eParams));
            $products = $s->fetchAll();
        }

        foreach ($products as $prod) {
            $key = $prod['sku'] . '|' . $prod['legal_entity'];
            if (isset($foundSkus[$key])) continue;
            $foundSkus[$key] = true;
            $found = true;
            $context .= productFullInfo($prod, $entity, $skipHistory);
        }
    }

    // Если точный поиск не дал результатов — ищем по отдельным словам
    if (!$found) {
        foreach ($searchTerms as $term) {
            $sql = "SELECT p.sku, p.name, p.supplier, p.qty_per_box, p.multiplicity, p.unit_of_measure, p.legal_entity, p.analog_group
                    FROM products p
                    INNER JOIN analysis_data a ON a.sku COLLATE utf8mb4_general_ci = p.sku COLLATE utf8mb4_general_ci
                        AND a.legal_entity COLLATE utf8mb4_general_ci = p.legal_entity COLLATE utf8mb4_general_ci
                    WHERE (p.name LIKE ? OR p.analog_group LIKE ?) AND p.is_active = 1" . $eFilter . "
                    ORDER BY a.stock DESC
                    LIMIT 10";
            $s = $pdo->prepare($sql);
            $s->execute(array_merge(["%{$term}%", "%{$term}%"], $eParams));
            $products = $s->fetchAll();

            if (empty($products)) {
                $sql = "SELECT p.sku, p.name, p.supplier, p.qty_per_box, p.multiplicity, p.unit_of_measure, p.legal_entity, p.analog_group
                        FROM products p WHERE (p.name LIKE ? OR p.analog_group LIKE ?) AND p.is_active = 1" . $eFilter . " LIMIT 10";
                $s = $pdo->prepare($sql);
                $s->execute(array_merge(["%{$term}%", "%{$term}%"], $eParams));
                $products = $s->fetchAll();
            }

            foreach ($products as $prod) {
                $key = $prod['sku'] . '|' . $prod['legal_entity'];
                if (isset($foundSkus[$key])) continue;
                $foundSkus[$key] = true;
                $found = true;
                $context .= productFullInfo($prod, $entity, $skipHistory);
            }
        }
    }

    return $found ? $context : '';
}

function productFullInfo($prod, $entity, $skipHistory = false) {
    global $pdo, $SITE_URL;
    $sku = $prod['sku'];
    $le = $prod['legal_entity'];
    $uom = $prod['unit_of_measure'] ?? 'шт';
    $uomLabel = getUomLabel($uom);
    $uomPerBox = $uom === 'л' ? 'л/кор.' : ($uom === 'кг' ? 'кг/кор.' : 'шт./кор.');
    $info = "\n<b>{$sku} {$prod['name']}</b>\n";
    $info .= "  Поставщик: {$prod['supplier']}, {$uomPerBox}: {$prod['qty_per_box']}, кратность: {$prod['multiplicity']}\n";
    if (!empty($prod['analog_group'])) {
        $info .= "  Группа аналогов: {$prod['analog_group']}\n";
    }

    // Остаток из analysis_data
    $s = $pdo->prepare("SELECT stock, consumption, period_days FROM analysis_data WHERE sku = ? AND legal_entity = ? LIMIT 1");
    $s->execute([$sku, $le]);
    $ad = $s->fetch();
    if ($ad) {
        $daily = $ad['period_days'] > 0 ? round($ad['consumption'] / $ad['period_days'], 1) : 0;
        $daysLeft = $daily > 0 ? round($ad['stock'] / $daily) : '∞';
        $info .= "  Остаток: {$ad['stock']} {$uomLabel}, расход: {$ad['consumption']} за {$ad['period_days']} дн. ({$daily} {$uomLabel}/день)\n";
        $info .= "  Запас на: ~{$daysLeft} дней\n";
    } else {
        $info .= "  Остаток/расход: нет данных\n";
    }

    // Реализация ресторанов (по группе аналогов)
    if (!empty($prod['analog_group'])) {
        $lastDateS = $pdo->query("SELECT MAX(sale_date) FROM restaurant_sales")->fetchColumn();
        if ($lastDateS) {
            $cut30 = date('Y-m-d', strtotime($lastDateS . ' -29 days'));
            $sR = $pdo->prepare("SELECT ROUND(SUM(quantity)) as total, ROUND(AVG(quantity)) as avg_day, ROUND(AVG(restaurant_count)) as avg_rc, MAX(sale_date) as last_sale FROM restaurant_sales WHERE analog_group = ? AND sale_date >= ?");
            $sR->execute([$prod['analog_group'], $cut30]);
            $sales = $sR->fetch();
            if ($sales && $sales['total'] > 0) {
                $info .= "  Реализация (30д): {$sales['total']} (ср. {$sales['avg_day']}/день, {$sales['avg_rc']} рест.)\n";
                // Сравниваем расход склада и реализацию
                if ($ad && $ad['period_days'] > 0) {
                    $warehouseMonthly = round($ad['consumption'] / $ad['period_days'] * 30);
                    if ($warehouseMonthly > 0 && $sales['total'] > 0) {
                        $diff = round(($warehouseMonthly - $sales['total']) / $sales['total'] * 100);
                        if (abs($diff) > 15) {
                            $dir = $diff > 0 ? 'больше' : 'меньше';
                            $info .= "  ⚠️ Расход со склада на " . abs($diff) . "% {$dir} реализации\n";
                        }
                    }
                }
            }
        }
    }

    // Текущая цена (только закупочная)
    $s = $pdo->prepare("SELECT price, currency, vat_rate, unit_type FROM product_prices WHERE sku = ? AND legal_entity = ? AND price_type = 'purchase' LIMIT 1");
    $s->execute([$sku, $le]);
    $price = $s->fetch();
    if ($price) {
        $vat = $price['vat_rate'] ?? 20;
        $priceWithVat = round($price['price'] * (1 + $vat / 100), 2);
        $unitLabels = ['piece'=>'шт','box'=>'кор','thousand'=>'тыс/шт','kg'=>'кг','liter'=>'л'];
        $unit = $unitLabels[$price['unit_type']] ?? $price['unit_type'];
        $info .= "  Цена: {$price['price']} {$price['currency']}/{$unit} (без НДС), НДС {$vat}%, с НДС: {$priceWithVat} {$price['currency']}\n";
    }

    // Ожидающие поставки с этим товаром
    $s = $pdo->prepare("SELECT o.id as order_id, o.supplier, o.delivery_date, oi.qty_boxes,
                               DATEDIFF(o.delivery_date, CURDATE()) as days_until
                        FROM order_items oi
                        JOIN orders o ON o.id = oi.order_id
                        WHERE oi.sku = ? AND o.legal_entity = ? AND o.received_at IS NULL AND o.delivery_date IS NOT NULL
                        ORDER BY o.delivery_date ASC LIMIT 5");
    $s->execute([$sku, $le]);
    $pending = $s->fetchAll();
    if ($pending) {
        $info .= "  Ожидается поставка:\n";
        foreach ($pending as $p) {
            $dd = date('d.m', strtotime($p['delivery_date']));
            $pcs = $p['qty_boxes'] * max($prod['qty_per_box'], 1);
            $when = $p['days_until'] > 0 ? "через {$p['days_until']} дн." : ($p['days_until'] == 0 ? 'сегодня' : 'просрочена');
            $orderUrl = "{$SITE_URL}/order?orderId={$p['order_id']}&mode=view";
            $info .= "    {$dd}: {$p['qty_boxes']} кор. ({$pcs} {$uomLabel}) — {$p['supplier']}, {$when} (<a href=\"{$orderUrl}\">заказ</a>)\n";
        }
    }

    // Последние заказы с этим товаром (пропускаем если не нужна история)
    if (!$skipHistory) {
        $s = $pdo->prepare("SELECT o.id as order_id, o.supplier, o.created_at, o.delivery_date, oi.qty_boxes
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                WHERE oi.sku = ? AND o.legal_entity = ?
                ORDER BY o.created_at DESC LIMIT 3");
        $s->execute([$sku, $le]);
        $orders = $s->fetchAll();
        if ($orders) {
            $info .= "  Последние заказы:\n";
            foreach ($orders as $ord) {
                $date = date('d.m', strtotime($ord['created_at']));
                $delivery = $ord['delivery_date'] ? date('d.m', strtotime($ord['delivery_date'])) : '—';
                $orderUrl = "{$SITE_URL}/order?orderId={$ord['order_id']}&mode=view";
                $info .= "    {$date}: {$ord['qty_boxes']} кор. ({$ord['supplier']}), приход {$delivery} (<a href=\"{$orderUrl}\">заказ</a>)\n";
            }
        }
    }

    return $info;
}

// Поиск заказов по ключевым словам (поставщик, номер) и подгрузка состава
function lookupOrders($question, $entity) {
    global $pdo;
    $q = mb_strtolower($question);

    // Определяем, спрашивают ли про заказы
    $orderKeywords = ['заказ', 'состав', 'позиц', 'что заказ', 'заказыв', 'отправ', 'отправл'];
    $isOrderQuestion = false;
    foreach ($orderKeywords as $kw) {
        if (mb_strpos($q, $kw) !== false) { $isOrderQuestion = true; break; }
    }
    if (!$isOrderQuestion) return '';

    // Ищем поставщика в вопросе
    $eFilter = $entity ? " AND o.legal_entity = ?" : "";
    $eParams = $entity ? [$entity] : [];

    // Получаем список поставщиков
    $sql = "SELECT DISTINCT supplier FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)" . str_replace('o.', '', $eFilter);
    $s = $pdo->prepare($sql); $s->execute($eParams);
    $suppliers = $s->fetchAll(PDO::FETCH_COLUMN);

    $matchedSupplier = null;
    foreach ($suppliers as $sup) {
        if (mb_stripos($q, mb_strtolower($sup)) !== false) {
            $matchedSupplier = $sup;
            break;
        }
        // Поиск по части имени поставщика
        $supWords = preg_split('/[\s\-()]+/u', $sup);
        foreach ($supWords as $sw) {
            if (mb_strlen($sw) >= 4 && mb_stripos($q, mb_strtolower($sw)) !== false) {
                $matchedSupplier = $sup;
                break 2;
            }
        }
    }

    // Определяем кол-во заказов для показа
    $limit = 3;
    if (preg_match('/последни[й]/u', $q)) $limit = 1; // "последний заказ" — один
    elseif (preg_match('/последни[еымх]/u', $q)) $limit = 5; // "последние заказы" — несколько

    // Загружаем заказы
    $sql = "SELECT o.id, o.supplier, o.created_by, o.created_at, o.delivery_date
            FROM orders o WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)" . $eFilter;
    $params = $eParams;
    if ($matchedSupplier) {
        $sql .= " AND o.supplier = ?";
        $params[] = $matchedSupplier;
    }
    $sql .= " ORDER BY o.created_at DESC LIMIT " . intval($limit);
    $s = $pdo->prepare($sql); $s->execute($params);
    $orders = $s->fetchAll();

    if (!$orders) return '';

    $context = "\n== НАЙДЕННЫЕ ЗАКАЗЫ ==\n";
    foreach ($orders as $o) {
        $date = date('d.m.Y', strtotime($o['created_at']));
        $delivery = $o['delivery_date'] ? date('d.m.Y', strtotime($o['delivery_date'])) : '—';
        $context .= "\nЗаказ #{$o['id']} — {$o['supplier']}, создан {$date}, приход {$delivery}, автор: {$o['created_by']}\n";
        $context .= "Состав:\n";

        $s2 = $pdo->prepare("SELECT oi.sku, oi.name, oi.qty_boxes, oi.qty_per_box, oi.consumption_period, oi.stock, oi.transit,
                COALESCE(p.unit_of_measure, 'шт') as uom
                FROM order_items oi
                LEFT JOIN orders ord ON ord.id = oi.order_id
                LEFT JOIN products p ON p.sku = oi.sku AND p.legal_entity = ord.legal_entity
                WHERE oi.order_id = ? ORDER BY oi.name");
        $s2->execute([$o['id']]);
        $items = $s2->fetchAll();
        foreach ($items as $it) {
            $pcs = $it['qty_boxes'] * max($it['qty_per_box'], 1);
            $u = $it['uom'] ?? 'шт';
            $uLabel = getUomLabel($u);
            $context .= "  - {$it['sku']} {$it['name']}: {$it['qty_boxes']} кор. ({$pcs} {$uLabel})";
            if ($it['stock'] > 0) $context .= ", остаток: {$it['stock']}";
            if ($it['transit'] > 0) $context .= ", транзит: {$it['transit']}";
            $context .= "\n";
        }
        $context .= "Итого: " . count($items) . " позиций\n";
    }

    return $context;
}

// Анализ запасов по дням — товары с критическим запасом
function lookupStockDays($question, $entity) {
    global $pdo;
    $q = mb_strtolower($question);

    // Определяем спрашивают ли про запас в днях
    $daysKeywords = ['дней','дня','день','запас','хватит','закончится','кончится','кончается','заканчив','критич','мало','нехватк','дефицит'];
    $isDaysQuestion = false;
    foreach ($daysKeywords as $kw) {
        if (mb_strpos($q, $kw) !== false) { $isDaysQuestion = true; break; }
    }
    if (!$isDaysQuestion) return '';

    // Извлечь число дней из вопроса (по умолчанию 7)
    // Ищем число перед словом "дн" (дней/дня/день), чтобы не спутать с артикулом
    $maxDays = 7;
    if (preg_match('/(\d+)\s*(?:дн|день)/ui', $question, $dm)) {
        $maxDays = intval($dm[1]);
    } elseif (preg_match('/(?:на|менее|меньше|до)\s+(\d{1,3})\b/ui', $question, $dm)) {
        $maxDays = intval($dm[1]);
    }

    $sql = "SELECT a.sku, p.name, a.stock, a.consumption, a.period_days, p.supplier,
                   COALESCE(p.unit_of_measure, 'шт') as uom,
                   ROUND(a.stock / (a.consumption / GREATEST(a.period_days, 1))) as days_left
            FROM analysis_data a
            LEFT JOIN products p ON p.sku COLLATE utf8mb4_general_ci = a.sku COLLATE utf8mb4_general_ci
                AND p.legal_entity COLLATE utf8mb4_general_ci = a.legal_entity COLLATE utf8mb4_general_ci
            WHERE a.consumption > 0 AND a.stock > 0";
    $params = [];
    if ($entity) { $sql .= " AND a.legal_entity = ?"; $params[] = $entity; }
    $sql .= " HAVING days_left <= " . intval($maxDays) . " ORDER BY days_left ASC LIMIT 20";
    $s = $pdo->prepare($sql); $s->execute($params);
    $items = $s->fetchAll();

    if (!$items) return "\n== АНАЛИЗ ЗАПАСОВ ==\nТоваров с запасом ≤ {$maxDays} дней не найдено.\n";

    $context = "\n== ТОВАРЫ С ЗАПАСОМ ≤ {$maxDays} ДНЕЙ ==\n";
    foreach ($items as $i) {
        $daily = round($i['consumption'] / max($i['period_days'], 1), 1);
        $name = $i['name'] ? $i['sku'] . ' ' . $i['name'] : $i['sku'];
        $u = $i['uom'] ?? 'шт';
        $uLabel = getUomLabel($u);
        $context .= "- {$name}: остаток {$i['stock']} {$uLabel}, расход {$daily} {$uLabel}/день, запас на ~{$i['days_left']} дн.";
        if ($i['supplier']) $context .= " ({$i['supplier']})";
        $context .= "\n";
    }
    return $context;
}

// Поиск по срокам годности (stock_malling)
function lookupShelfLife($question, $entity) {
    global $pdo;
    $q = mb_strtolower($question);

    $shelfKeywords = ['срок','годн','годност','истек','просроч','expir','маллинг','склад','хранен','блокир'];
    $isShelfQuestion = false;
    foreach ($shelfKeywords as $kw) {
        if (mb_strpos($q, $kw) !== false) { $isShelfQuestion = true; break; }
    }
    if (!$isShelfQuestion) return '';

    // Маппинг юрлица → customer в stock_malling
    $customerFilter = '';
    $customerParams = [];
    if ($entity) {
        // Определяем короткое имя для фильтра customer
        $customerName = null;
        if (strpos($entity, 'Бургер') !== false) $customerName = 'Бургер БК';
        elseif (strpos($entity, 'Воглия') !== false) $customerName = 'Воглия Матта';
        elseif (strpos($entity, 'Пицца') !== false) $customerName = 'Пицца Стар';
        if ($customerName) {
            $customerFilter = ' AND customer = ?';
            $customerParams = [$customerName];
        }
    }

    // Извлечь ключевые слова для поиска конкретного товара
    $stopShelf = ['срок','годн','годности','годност','истек','просроч','хранен','склад','какой','какая','какие','покажи','сколько','осталось',
                  'бургер','воглия','матта','пицца','стар','юрлиц','юрлица','лицо','лица'];
    $words = preg_split('/[\s,.\-!?:;]+/u', mb_strtolower($question));
    $productTerms = [];
    foreach ($words as $w) {
        $w = trim($w);
        if (mb_strlen($w) >= 3 && !in_array($w, $stopShelf) && !is_numeric($w)) {
            $productTerms[] = stemRu($w);
        }
    }

    $context = "\n== СРОКИ ГОДНОСТИ ==\n";
    if ($entity) $context .= "(юрлицо: {$entity})\n";
    $found = false;

    // Поиск по конкретному товару
    if (!empty($productTerms)) {
        foreach ($productTerms as $term) {
            $sql = "SELECT product_name, customer, warehouse, expiry_date, quantity, expiry_status, block_reason,
                           DATEDIFF(expiry_date, CURDATE()) as days_left
                    FROM stock_malling
                    WHERE product_name LIKE ? AND expiry_date >= CURDATE()" . $customerFilter . "
                    ORDER BY expiry_date ASC LIMIT 15";
            $s = $pdo->prepare($sql); $s->execute(array_merge(["%{$term}%"], $customerParams));
            $items = $s->fetchAll();
            if ($items) {
                $found = true;
                foreach ($items as $i) {
                    $date = date('d.m.Y', strtotime($i['expiry_date']));
                    $status = $i['block_reason'] ?: $i['expiry_status'];
                    $custLabel = $i['customer'] ? " [{$i['customer']}]" : '';
                    $context .= "- {$i['product_name']}{$custLabel}: годен до {$date} ({$i['days_left']} дн.), {$i['quantity']} шт., склад: {$i['warehouse']}, статус: {$status}\n";
                }
            }
        }
    }

    // Если не искали конкретный товар или не нашли — показать скоро истекающие
    if (!$found) {
        $daysAhead = extractNumber($question) ?? 14;

        $sql = "SELECT product_name, customer, warehouse, expiry_date, quantity, expiry_status, block_reason,
                       DATEDIFF(expiry_date, CURDATE()) as days_left
                FROM stock_malling
                WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY) AND expiry_date >= CURDATE()" . $customerFilter . "
                ORDER BY expiry_date ASC LIMIT 20";
        $s = $pdo->prepare($sql); $s->execute(array_merge([$daysAhead], $customerParams));
        $items = $s->fetchAll();
        if ($items) {
            $found = true;
            $context .= "Истекают в ближайшие {$daysAhead} дней:\n";
            foreach ($items as $i) {
                $date = date('d.m.Y', strtotime($i['expiry_date']));
                $custLabel = $i['customer'] ? " [{$i['customer']}]" : '';
                $context .= "- {$i['product_name']}{$custLabel}: годен до {$date} ({$i['days_left']} дн.), {$i['quantity']} шт., склад: {$i['warehouse']}\n";
            }
        }

        // Заблокированные / просроченные
        $sql2 = "SELECT product_name, customer, warehouse, expiry_date, quantity, expiry_status, block_reason,
                       DATEDIFF(expiry_date, CURDATE()) as days_left
                FROM stock_malling
                WHERE (expiry_status != 'Годен' OR expiry_date < CURDATE() OR block_reason IS NOT NULL)" . $customerFilter . "
                ORDER BY expiry_date ASC LIMIT 15";
        $s2 = $pdo->prepare($sql2);
        $s2->execute($customerParams);
        $blocked = $s2->fetchAll();
        if ($blocked) {
            $found = true;
            $context .= "\nЗаблокированные / просроченные:\n";
            foreach ($blocked as $b) {
                $date = date('d.m.Y', strtotime($b['expiry_date']));
                $reason = $b['block_reason'] ?: $b['expiry_status'];
                $custLabel = $b['customer'] ? " [{$b['customer']}]" : '';
                $context .= "- {$b['product_name']}{$custLabel}: {$date}, {$b['quantity']} шт., статус: {$reason}\n";
            }
        }
    }

    if (!$found) return "\n== СРОКИ ГОДНОСТИ ==\nДанных по срокам годности не найдено.\n";
    return $context;
}

// Поиск информации по поставщику
function lookupSupplier($question, $entity) {
    global $pdo;
    $q = mb_strtolower($question);

    $supplierKeywords = ['поставщик','поставщ','контакт','телефон','email','dlt','срок документ'];
    $isSupplierQ = false;
    foreach ($supplierKeywords as $kw) {
        if (mb_strpos($q, $kw) !== false) { $isSupplierQ = true; break; }
    }

    // Также проверяем, упоминается ли конкретный поставщик
    $params = [];
    $eFilter = '';
    if ($entity) { $eFilter = " AND legal_entity = ?"; $params[] = $entity; }

    $s = $pdo->prepare("SELECT short_name, full_name, telegram, whatsapp, email, dlt, doc FROM suppliers WHERE 1=1" . $eFilter);
    $s->execute($params);
    $allSuppliers = $s->fetchAll();

    $matched = [];
    foreach ($allSuppliers as $sup) {
        $name = mb_strtolower($sup['short_name'] ?? '');
        if ($name && mb_strpos($q, $name) !== false) {
            $matched[] = $sup;
            continue;
        }
        // Поиск по частям имени
        $words = preg_split('/[\s\-()]+/u', $name);
        foreach ($words as $w) {
            if (mb_strlen($w) >= 4 && mb_strpos($q, $w) !== false) {
                $matched[] = $sup;
                break;
            }
        }
    }

    if (empty($matched) && !$isSupplierQ) return '';

    $context = "\n== ПОСТАВЩИКИ ==\n";

    if (!empty($matched)) {
        foreach ($matched as $sup) {
            $context .= "\n<b>{$sup['short_name']}</b>";
            if ($sup['full_name']) $context .= " ({$sup['full_name']})";
            $context .= "\n";
            if ($sup['email']) $context .= "  Email: {$sup['email']}\n";
            if ($sup['telegram']) $context .= "  Telegram: {$sup['telegram']}\n";
            if ($sup['whatsapp']) $context .= "  WhatsApp: {$sup['whatsapp']}\n";
            if ($sup['dlt']) $context .= "  DLT (срок доставки): {$sup['dlt']} дн.\n";
            if ($sup['doc']) $context .= "  Срок документов: {$sup['doc']} дн.\n";

            // Кол-во товаров этого поставщика
            $s2 = $pdo->prepare("SELECT COUNT(*) as cnt FROM products WHERE supplier = ? AND is_active = 1" . $eFilter);
            $s2->execute(array_merge([$sup['short_name']], $params));
            $cnt = $s2->fetch()['cnt'];
            $context .= "  Товаров: {$cnt}\n";

            // Планы
            $planParams = [$sup['short_name']];
            $planFilter = "";
            if ($entity) { $planFilter = " AND legal_entity = ?"; $planParams[] = $entity; }
            $s3 = $pdo->prepare("SELECT note, start_date, period_type, period_count FROM plans WHERE supplier = ?" . $planFilter . " ORDER BY created_at DESC LIMIT 1");
            $s3->execute($planParams);
            $plan = $s3->fetch();
            if ($plan) {
                $context .= "  Последний план: {$plan['note']}, период: {$plan['period_count']} {$plan['period_type']}\n";
            }

            // Последний заказ
            $s4 = $pdo->prepare("SELECT created_at, delivery_date, (SELECT SUM(qty_boxes) FROM order_items WHERE order_id = o.id) as boxes FROM orders o WHERE o.supplier = ?" . str_replace('legal_entity', 'o.legal_entity', $planFilter) . " ORDER BY o.created_at DESC LIMIT 1");
            $s4->execute($planParams);
            $lastOrder = $s4->fetch();
            if ($lastOrder) {
                $context .= "  Последний заказ: " . date('d.m.Y', strtotime($lastOrder['created_at'])) . ", {$lastOrder['boxes']} кор.\n";
            }

            // ПСЦ
            $s5 = $pdo->prepare("SELECT number, valid_to, DATEDIFF(valid_to, CURDATE()) as days_left FROM price_agreements WHERE supplier = ? AND status = 'active'" . $planFilter . " LIMIT 1");
            $s5->execute($planParams);
            $psc = $s5->fetch();
            if ($psc) {
                $context .= "  ПСЦ: {$psc['number']}, до " . date('d.m.Y', strtotime($psc['valid_to'])) . " ({$psc['days_left']} дн.)\n";
            }
        }
    } elseif ($isSupplierQ) {
        // Список всех поставщиков
        $context .= "Всего поставщиков: " . count($allSuppliers) . "\n";
        foreach (array_slice($allSuppliers, 0, 20) as $sup) {
            $context .= "- {$sup['short_name']}";
            if ($sup['dlt']) $context .= " (DLT: {$sup['dlt']} дн.)";
            $context .= "\n";
        }
    }

    return $context;
}

// Поиск по графику доставок / ресторанам
function lookupSchedule($question, $entity) {
    global $pdo;
    $q = mb_strtolower($question);

    $schedKeywords = ['график','доставк','ресторан','рестор','адрес','когда доставк','какой день','расписан'];
    $isSchedQ = false;
    foreach ($schedKeywords as $kw) {
        if (mb_strpos($q, $kw) !== false) { $isSchedQ = true; break; }
    }
    if (!$isSchedQ) return '';

    $dayNames = [1=>'Понедельник',2=>'Вторник',3=>'Среда',4=>'Четверг',5=>'Пятница',6=>'Суббота',7=>'Воскресенье'];
    $context = "\n== ГРАФИК ДОСТАВОК ==\n";

    // Ищем номер ресторана
    $restNum = null;
    if (preg_match('/(?:ресторан|рест|#)\s*(\d+)/iu', $question, $m)) {
        $restNum = intval($m[1]);
    } elseif (preg_match('/\b(\d{1,3})\b/', $question, $m)) {
        // Одно-трёхзначное число может быть номером ресторана
        $num = intval($m[1]);
        if ($num >= 1 && $num <= 200) $restNum = $num;
    }

    if ($restNum) {
        $s = $pdo->prepare("SELECT r.id, r.number, r.address, r.region FROM restaurants r WHERE r.number = ? AND r.active = 1 LIMIT 1");
        $s->execute([$restNum]);
        $rest = $s->fetch();
        if ($rest) {
            $context .= "Ресторан #{$rest['number']} — {$rest['address']} ({$rest['region']})\n";
            $s2 = $pdo->prepare("SELECT day_of_week, delivery_time FROM delivery_schedule WHERE restaurant_id = ? AND delivery_time IS NOT NULL ORDER BY day_of_week");
            $s2->execute([$rest['id']]);
            $sched = $s2->fetchAll();
            if ($sched) {
                $context .= "Доставки:\n";
                foreach ($sched as $sc) {
                    $context .= "  {$dayNames[$sc['day_of_week']]}: {$sc['delivery_time']}\n";
                }
            } else {
                $context .= "Доставки не назначены\n";
            }
            return $context;
        }
    }

    // Ищем по адресу
    $words = preg_split('/[\s,.\-!?:;]+/u', $q);
    $addrTerms = [];
    $stopSched = ['график','доставк','доставки','ресторан','какой','день','когда','расписан','покажи','адрес'];
    foreach ($words as $w) {
        if (mb_strlen($w) >= 3 && !in_array($w, $stopSched) && !is_numeric($w)) $addrTerms[] = $w;
    }

    if (!empty($addrTerms)) {
        foreach ($addrTerms as $term) {
            $s = $pdo->prepare("SELECT r.id, r.number, r.address, r.region FROM restaurants r WHERE (r.address LIKE ? OR r.city LIKE ?) AND r.active = 1 LIMIT 5");
            $s->execute(["%{$term}%", "%{$term}%"]);
            $rests = $s->fetchAll();
            foreach ($rests as $rest) {
                $context .= "\nРесторан #{$rest['number']} — {$rest['address']} ({$rest['region']})\n";
                $s2 = $pdo->prepare("SELECT day_of_week, delivery_time FROM delivery_schedule WHERE restaurant_id = ? AND delivery_time IS NOT NULL ORDER BY day_of_week");
                $s2->execute([$rest['id']]);
                $sched = $s2->fetchAll();
                if ($sched) {
                    foreach ($sched as $sc) {
                        $context .= "  {$dayNames[$sc['day_of_week']]}: {$sc['delivery_time']}\n";
                    }
                }
            }
            if (!empty($rests)) return $context;
        }
    }

    // Общая сводка
    $s = $pdo->prepare("SELECT ds.day_of_week, COUNT(*) as cnt FROM delivery_schedule ds JOIN restaurants r ON r.id = ds.restaurant_id AND r.active = 1 WHERE ds.delivery_time IS NOT NULL GROUP BY ds.day_of_week ORDER BY ds.day_of_week");
    $s->execute();
    $summary = $s->fetchAll();
    if ($summary) {
        $context .= "Доставки по дням недели:\n";
        foreach ($summary as $row) {
            $context .= "  {$dayNames[$row['day_of_week']]}: {$row['cnt']} ресторанов\n";
        }
    }
    return $context;
}

// Поиск по ожидающим поставкам
function lookupDeliveries($question, $entity) {
    global $pdo, $SITE_URL;
    $q = mb_strtolower($question);

    $delivKeywords = ['поставк','ожида','приход','приёмк','приемк','привез','в пути','просроч','задерж',
        'когда приед','когда будет','когда приве','заказан','доставк','ожидаем','едет','везут'];
    $isDelivQ = false;
    foreach ($delivKeywords as $kw) {
        if (mb_strpos($q, $kw) !== false) { $isDelivQ = true; break; }
    }
    if (!$isDelivQ) return '';

    // Извлекаем поисковые слова для товара (убираем стоп-слова)
    $stopWords = ['когда','приедет','приедут','привезут','привезёт','привезет','будет','будут','поставка','поставки','ожидает',
        'ожидается','приход','сколько','какой','какая','какие','заказ','заказано','нужно','есть',
        'пришло','привезли','уже','ещё','еще','скоро','ожидаем','завтра','послезавтра','сегодня',
        'товар','товары','товаров','позиции','позиций','продукт','продукты','продуктов',
        'понедельник','вторник','среда','четверг','пятница','суббота','воскресенье'];
    $words = preg_split('/[\s,.\-!?:;]+/u', $q);
    $searchTerms = [];
    foreach ($words as $w) {
        $w = trim($w);
        if (mb_strlen($w) >= 3 && !in_array($w, $stopWords) && !is_numeric($w)) {
            $searchTerms[] = stemRu($w);
        }
    }

    // Парсим дату из вопроса (завтра, послезавтра, день недели)
    $filterDate = null;
    if (mb_strpos($q, 'сегодня') !== false) {
        $filterDate = date('Y-m-d');
    } elseif (mb_strpos($q, 'послезавтра') !== false) {
        $filterDate = date('Y-m-d', strtotime('+2 days'));
    } elseif (mb_strpos($q, 'завтра') !== false) {
        $filterDate = date('Y-m-d', strtotime('+1 day'));
    } else {
        $dayMap = ['понедельник'=>1,'вторник'=>2,'среду'=>3,'среда'=>3,'четверг'=>4,'пятницу'=>5,'пятница'=>5,'субботу'=>6,'суббота'=>6,'воскресенье'=>7];
        foreach ($dayMap as $dayName => $dayNum) {
            if (mb_strpos($q, $dayName) !== false) {
                $today = date('N'); // 1=пн, 7=вс
                $diff = $dayNum - $today;
                if ($diff <= 0) $diff += 7;
                $filterDate = date('Y-m-d', strtotime("+{$diff} days"));
                break;
            }
        }
    }

    // Загружаем ожидающие заказы
    $sql = "SELECT o.id, o.supplier, o.delivery_date, o.created_at, o.created_by,
                   DATEDIFF(CURDATE(), o.delivery_date) as days_overdue
            FROM orders o WHERE o.received_at IS NULL AND o.delivery_date IS NOT NULL";
    $params = [];
    if ($entity) { $sql .= " AND o.legal_entity = ?"; $params[] = $entity; }
    if ($filterDate) { $sql .= " AND o.delivery_date = ?"; $params[] = $filterDate; }
    $sql .= " ORDER BY o.delivery_date ASC LIMIT 20";
    $s = $pdo->prepare($sql); $s->execute($params);
    $orders = $s->fetchAll();

    if (!$orders) return "\n== ПОСТАВКИ ==\nОжидающих поставок нет.\n";

    // Если есть поисковые слова — ищем конкретный товар в позициях заказов
    $hasProductSearch = !empty($searchTerms);
    $orderIds = array_column($orders, 'id');

    if ($hasProductSearch && $orderIds) {
        // Сначала проверяем: совпадают ли поисковые слова с именем поставщика
        $supplierMatchedOrders = [];
        foreach ($orders as $o) {
            $supplierLower = mb_strtolower($o['supplier']);
            $supplierStemmed = stemRu($supplierLower);
            foreach ($searchTerms as $term) {
                if (mb_strpos($supplierLower, $term) !== false || mb_strpos($supplierStemmed, $term) !== false) {
                    $supplierMatchedOrders[] = $o['id'];
                    break;
                }
            }
        }

        // Загружаем позиции всех ожидающих заказов
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $s2 = $pdo->prepare("SELECT oi.order_id, oi.sku, oi.name, oi.qty_boxes, oi.qty_per_box,
                                    COALESCE(p.unit_of_measure, 'шт') as uom
                             FROM order_items oi
                             LEFT JOIN orders ord ON ord.id = oi.order_id
                             LEFT JOIN products p ON p.sku = oi.sku AND p.legal_entity = ord.legal_entity
                             WHERE oi.order_id IN ({$placeholders})");
        $s2->execute($orderIds);
        $allItems = $s2->fetchAll();

        // Если поставщик найден — показываем все товары его заказов
        if ($supplierMatchedOrders) {
            $context = "\n== ОЖИДАЮЩИЕ ПОСТАВКИ (товары по поставщику) ==\n";
            foreach ($orders as $o) {
                if (!in_array($o['id'], $supplierMatchedOrders)) continue;
                $delivery = date('d.m.Y', strtotime($o['delivery_date']));
                $overdue = $o['days_overdue'];
                $status = $overdue > 0 ? "просрочена на {$overdue} дн." : ($overdue == 0 ? 'сегодня' : 'через ' . abs($overdue) . ' дн.');
                $orderUrl = "{$SITE_URL}/order?orderId={$o['id']}&mode=view";
                $context .= "\n<b>{$o['supplier']}</b> — приход {$delivery} ({$status}) (<a href=\"{$orderUrl}\">открыть</a>)\n";
                $orderItems = array_filter($allItems, fn($item) => $item['order_id'] == $o['id']);
                $totalBoxes = 0;
                foreach ($orderItems as $item) {
                    $u = $item['uom'] ?? 'шт';
                    $itemUom = getUomLabel($u);
                    $pcs = $item['qty_per_box'] ? $item['qty_boxes'] * $item['qty_per_box'] : '';
                    $pcsInfo = $pcs ? " ({$pcs} {$itemUom})" : '';
                    $context .= "  • {$item['name']}: {$item['qty_boxes']} кор.{$pcsInfo}\n";
                    $totalBoxes += $item['qty_boxes'];
                }
                $context .= "  <b>Итого: {$totalBoxes} кор., " . count($orderItems) . " поз.</b>\n";
            }
            return $context;
        }

        // Ищем совпадения по товару — товар должен содержать ВСЕ поисковые слова
        $matchedItems = [];
        foreach ($allItems as $item) {
            $haystack = mb_strtolower(($item['sku'] ?? '') . ' ' . ($item['name'] ?? ''));
            $matchedAll = true;
            foreach ($searchTerms as $term) {
                if (mb_strpos($haystack, $term) === false) { $matchedAll = false; break; }
            }
            if ($matchedAll) {
                $matchedItems[] = $item;
            }
        }
        // Если по всем словам ничего — ищем хотя бы по одному
        if (empty($matchedItems)) {
            foreach ($allItems as $item) {
                $haystack = mb_strtolower(($item['sku'] ?? '') . ' ' . ($item['name'] ?? ''));
                foreach ($searchTerms as $term) {
                    if (mb_strpos($haystack, $term) !== false) { $matchedItems[] = $item; break; }
                }
            }
        }

        if ($matchedItems) {
            // Группируем по заказу
            $byOrder = [];
            foreach ($matchedItems as $mi) {
                $byOrder[$mi['order_id']][] = $mi;
            }

            $context = "\n== ОЖИДАЮЩИЕ ПОСТАВКИ (найденные товары) ==\n";
            foreach ($orders as $o) {
                if (!isset($byOrder[$o['id']])) continue;
                $delivery = date('d.m.Y', strtotime($o['delivery_date']));
                $overdue = $o['days_overdue'];
                $status = $overdue > 0 ? "просрочена на {$overdue} дн." : ($overdue == 0 ? 'сегодня' : 'через ' . abs($overdue) . ' дн.');
                $orderUrl = "{$SITE_URL}/order?orderId={$o['id']}&mode=view";
                $context .= "\n{$o['supplier']} — приход {$delivery} ({$status}) (<a href=\"{$orderUrl}\">открыть</a>)\n";
                foreach ($byOrder[$o['id']] as $item) {
                    $u = $item['uom'] ?? 'шт';
                    $itemUom = getUomLabel($u);
                    $pcs = $item['qty_per_box'] ? $item['qty_boxes'] * $item['qty_per_box'] : '';
                    $pcsInfo = $pcs ? " ({$pcs} {$itemUom})" : '';
                    $context .= "  • {$item['sku']} {$item['name']}: {$item['qty_boxes']} кор.{$pcsInfo}\n";
                }
            }
            return $context;
        } else {
            // Искали конкретный товар, но не нашли ни в одном заказе
            $searchStr = implode(' ', $searchTerms);
            return "\n== ОЖИДАЮЩИЕ ПОСТАВКИ ==\nТовар «{$searchStr}» НЕ НАЙДЕН ни в одном ожидающем заказе. Всего ожидается " . count($orders) . " поставок.\n";
        }
    }

    // Общий список поставок (без фильтра по товару) — одним запросом
    $orderIds = array_column($orders, 'id');
    $boxesByOrder = [];
    if ($orderIds) {
        $ph = implode(',', array_fill(0, count($orderIds), '?'));
        $s3 = $pdo->prepare("SELECT order_id, SUM(qty_boxes) as boxes, COUNT(*) as items FROM order_items WHERE order_id IN ({$ph}) GROUP BY order_id");
        $s3->execute($orderIds);
        foreach ($s3->fetchAll() as $r) $boxesByOrder[$r['order_id']] = $r;
    }

    $context = "\n== ОЖИДАЮЩИЕ ПОСТАВКИ ==\n";
    foreach ($orders as $o) {
        $delivery = date('d.m.Y', strtotime($o['delivery_date']));
        $overdue = $o['days_overdue'];
        $status = $overdue > 0 ? "просрочена на {$overdue} дн." : ($overdue == 0 ? 'сегодня' : 'через ' . abs($overdue) . ' дн.');
        $info = $boxesByOrder[$o['id']] ?? ['boxes' => 0, 'items' => 0];
        $orderUrl = "{$SITE_URL}/order?orderId={$o['id']}&mode=view";
        $context .= "- {$o['supplier']}: приход {$delivery} ({$status}), {$info['boxes']} кор., {$info['items']} поз. (<a href=\"{$orderUrl}\">открыть</a>)\n";
    }
    return $context;
}

// Поиск по планам
function lookupPlans($question, $entity) {
    global $pdo;
    $q = mb_strtolower($question);

    $planKeywords = ['план','планир','период','частот','как часто','интервал','когда заказ'];
    $isPlanQ = false;
    foreach ($planKeywords as $kw) {
        if (mb_strpos($q, $kw) !== false) { $isPlanQ = true; break; }
    }
    if (!$isPlanQ) return '';

    $sql = "SELECT supplier, period_type, period_count, note, created_by, updated_at
            FROM plans WHERE 1=1";
    $params = [];
    if ($entity) { $sql .= " AND legal_entity = ?"; $params[] = $entity; }
    $sql .= " ORDER BY updated_at DESC LIMIT 10";
    $s = $pdo->prepare($sql); $s->execute($params);
    $plans = $s->fetchAll();

    if (!$plans) return "\n== ПЛАНЫ ==\nПланов поставок нет.\n";

    $periodLabels = ['weeks' => 'нед.', 'months' => 'мес.'];
    $context = "\n== ПЛАНЫ ПОСТАВОК ==\n";
    foreach ($plans as $p) {
        $period = ($p['period_count'] ?? 3) . ' ' . ($periodLabels[$p['period_type']] ?? $p['period_type']);
        $context .= "- {$p['supplier']}: каждые {$period}";
        if ($p['note']) $context .= " ({$p['note']})";
        $context .= "\n";
    }
    return $context;
}

// Поиск цен с НДС
function lookupPrices($question, $entity) {
    global $pdo;
    $q = mb_strtolower($question);

    $priceKeywords = ['цен','стоимост','прайс','сколько стоит','ндс','налог','vat'];
    $isPriceQ = false;
    foreach ($priceKeywords as $kw) {
        if (mb_strpos($q, $kw) !== false) { $isPriceQ = true; break; }
    }
    if (!$isPriceQ) return '';

    // Ищем товар в вопросе
    $skus = [];
    if (preg_match_all('/\b(\d{4,})\b/', $question, $m)) $skus = $m[1];

    $searchTerms = [];
    if (preg_match_all('/[«""]([^»""]+)[»""]/', $question, $m)) $searchTerms = $m[1];

    if (empty($skus) && empty($searchTerms)) {
        $stopPrice = ['цен','цена','цены','стоимост','прайс','сколько','стоит','ндс','налог','покажи','какая','какой','какие'];
        $words = preg_split('/[\s,.\-!?:;]+/u', mb_strtolower($question));
        foreach ($words as $w) {
            $w = trim($w);
            if (mb_strlen($w) >= 3 && !in_array($w, $stopPrice) && !is_numeric($w)) {
                $searchTerms[] = stemRu($w);
            }
        }
    }

    if (empty($skus) && empty($searchTerms)) return '';

    $context = "\n== ЦЕНЫ ==\n";
    $found = false;
    $eFilter = $entity ? " AND pp.legal_entity = ?" : "";
    $eParams = $entity ? [$entity] : [];

    foreach ($skus as $sku) {
        $sql = "SELECT pp.sku, p.name, pp.price, pp.vat_rate, pp.currency, pp.unit_type, pp.supplier
                FROM product_prices pp
                LEFT JOIN products p ON p.sku = pp.sku AND p.legal_entity = pp.legal_entity
                WHERE pp.price_type = 'purchase' AND pp.sku = ?" . $eFilter . " LIMIT 5";
        $s = $pdo->prepare($sql); $s->execute(array_merge([$sku], $eParams));
        foreach ($s->fetchAll() as $row) {
            $found = true;
            $vat = $row['vat_rate'] ?? 20;
            $priceWithVat = round($row['price'] * (1 + $vat / 100), 2);
            $unitLabels = ['piece'=>'шт','box'=>'кор','thousand'=>'тыс/шт','kg'=>'кг','liter'=>'л'];
            $unit = $unitLabels[$row['unit_type']] ?? $row['unit_type'];
            $context .= "- {$row['sku']} {$row['name']}: {$row['price']} {$row['currency']}/{$unit} (без НДС), НДС {$vat}%, с НДС: {$priceWithVat} {$row['currency']} — {$row['supplier']}\n";
        }
    }

    foreach ($searchTerms as $term) {
        $sql = "SELECT pp.sku, p.name, pp.price, pp.vat_rate, pp.currency, pp.unit_type, pp.supplier
                FROM product_prices pp
                LEFT JOIN products p ON p.sku = pp.sku AND p.legal_entity = pp.legal_entity
                WHERE pp.price_type = 'purchase' AND p.name LIKE ?" . $eFilter . " LIMIT 10";
        $s = $pdo->prepare($sql); $s->execute(array_merge(["%{$term}%"], $eParams));
        foreach ($s->fetchAll() as $row) {
            $found = true;
            $vat = $row['vat_rate'] ?? 20;
            $priceWithVat = round($row['price'] * (1 + $vat / 100), 2);
            $unitLabels = ['piece'=>'шт','box'=>'кор','thousand'=>'тыс/шт','kg'=>'кг','liter'=>'л'];
            $unit = $unitLabels[$row['unit_type']] ?? $row['unit_type'];
            $context .= "- {$row['sku']} {$row['name']}: {$row['price']} {$row['currency']}/{$unit} (без НДС), НДС {$vat}%, с НДС: {$priceWithVat} {$row['currency']} — {$row['supplier']}\n";
        }
    }

    return $found ? $context : '';
}

// Реализация ресторанов — для AI-вопросов
function lookupSales($question, $entity) {
    global $pdo;
    $q = mb_strtolower($question);

    $salesKw = ['реализац','продаж','ресторан','спрос','популярн','рост','падени','тренд','сезон'];
    $isSalesQ = false;
    foreach ($salesKw as $kw) {
        if (mb_strpos($q, $kw) !== false) { $isSalesQ = true; break; }
    }
    if (!$isSalesQ) return '';

    $lastDate = $pdo->query("SELECT MAX(sale_date) FROM restaurant_sales")->fetchColumn();
    if (!$lastDate) return '';

    $context = "\n== РЕАЛИЗАЦИЯ РЕСТОРАНОВ ==\n";
    $context .= "Данные до {$lastDate}.\n";
    $found = false;

    // Ищем конкретную группу аналогов в вопросе
    $searchTerms = [];
    if (preg_match_all('/[«""]([^»""]+)[»""]/', $question, $m)) {
        $searchTerms = $m[1];
    }
    if (empty($searchTerms)) {
        $stopWords = ['реализация','продажи','ресторан','ресторанов','покажи','расскажи','какой','какая','какие','сколько','как','группа','аналог','тренд','рост','падение','топ','популярн','спрос'];
        $words = preg_split('/[\s,.\-!?:;]+/u', $q);
        foreach ($words as $w) {
            if (mb_strlen($w) >= 3 && !in_array($w, $stopWords) && !is_numeric($w)) {
                $searchTerms[] = $w;
            }
        }
    }

    $cut30 = date('Y-m-d', strtotime($lastDate . ' -29 days'));

    // Если есть поисковые слова — ищем конкретные группы
    if ($searchTerms) {
        foreach ($searchTerms as $term) {
            $s = $pdo->prepare("
                SELECT analog_group, ROUND(SUM(quantity)) as total, ROUND(AVG(quantity)) as avg_day,
                       ROUND(AVG(restaurant_count)) as avg_rc, COUNT(DISTINCT sale_date) as days_cnt
                FROM restaurant_sales WHERE analog_group LIKE ? AND sale_date >= ?
                GROUP BY analog_group HAVING total > 0 ORDER BY total DESC LIMIT 5
            ");
            $s->execute(["%{$term}%", $cut30]);
            $groups = $s->fetchAll();
            foreach ($groups as $g) {
                $found = true;
                $context .= "Группа «{$g['analog_group']}»: {$g['total']} за 30 дн. (ср. {$g['avg_day']}/день, {$g['avg_rc']} рест., {$g['days_cnt']} дн. с продажами)\n";
            }
        }
    }

    // Топ-10 по объёму (всегда добавляем для контекста)
    $s = $pdo->prepare("
        SELECT analog_group, ROUND(SUM(quantity)) as total, ROUND(AVG(quantity)) as avg_day
        FROM restaurant_sales WHERE sale_date >= ?
        GROUP BY analog_group HAVING total > 0 ORDER BY total DESC LIMIT 10
    ");
    $s->execute([$cut30]);
    $top = $s->fetchAll();
    if ($top) {
        $found = true;
        $context .= "\nТоп-10 по реализации за 30 дней:\n";
        foreach ($top as $i => $r) {
            $n = $i + 1;
            $context .= "{$n}. {$r['analog_group']}: {$r['total']} (ср. {$r['avg_day']}/день)\n";
        }
    }

    // Тренд: рост и падение
    $cut14 = date('Y-m-d', strtotime($lastDate . ' -13 days'));
    $cut28 = date('Y-m-d', strtotime($lastDate . ' -27 days'));
    $s = $pdo->prepare("
        SELECT analog_group,
               SUM(CASE WHEN sale_date >= ? THEN quantity ELSE 0 END) as cur,
               SUM(CASE WHEN sale_date >= ? AND sale_date < ? THEN quantity ELSE 0 END) as prev
        FROM restaurant_sales WHERE sale_date >= ?
        GROUP BY analog_group HAVING cur > 0 AND prev > 0
        ORDER BY (cur - prev) / prev DESC
    ");
    $s->execute([$cut14, $cut28, $cut14, $cut28]);
    $all = $s->fetchAll();
    if ($all) {
        $context .= "\nБольше всего выросли за 2 недели:\n";
        $cnt = 0;
        foreach ($all as $r) {
            $pct = round(($r['cur'] - $r['prev']) / $r['prev'] * 100);
            if ($pct > 5) { $context .= "• {$r['analog_group']}: +{$pct}%\n"; $cnt++; }
            if ($cnt >= 5) break;
        }
        $context .= "Больше всего упали:\n";
        $cnt = 0;
        $reversed = array_reverse($all);
        foreach ($reversed as $r) {
            $pct = round(($r['cur'] - $r['prev']) / $r['prev'] * 100);
            if ($pct < -5) { $context .= "• {$r['analog_group']}: {$pct}%\n"; $cnt++; }
            if ($cnt >= 5) break;
        }
    }

    return $found ? $context : '';
}

// Поиск по карточкам товаров (как страница «Поиск карточек»)
function lookupCards($question, $entity) {
    global $pdo;
    $q = mb_strtolower($question);

    // Определяем — спрашивают ли про карточку
    $cardKeywords = ['карточ','артикул','найди товар','найди карточ','поиск товар','что за товар',
        'какой товар','что это за','номер товар','код товар','аналог','замен','чем замени'];
    $isCardQ = matchesKeywords($q, $cardKeywords);

    // Также ищем если в вопросе есть артикул (5+ цифр)
    $hasArticle = preg_match('/\b\d{5,}(?:-\d+)?\b/', $question);

    if (!$isCardQ && !$hasArticle) return '';

    // Нормализация (как на фронтенде)
    $normalize = function($s) {
        $s = mb_strtolower($s);
        $s = str_replace('ё', 'е', $s);
        return preg_replace('/[^а-яa-z0-9]/u', '', $s);
    };

    // Извлекаем поисковые слова
    $stopWords = ['карточка','карточки','карточку','артикул','артикула','найди','покажи','какой','какая',
        'товар','товара','товары','что','это','номер','код','поиск','где','как',
        'аналог','аналоги','аналога','аналогов','замена','замены','замену','заменить','чем','заменили'];
    $searchTerms = [];
    // Сначала артикулы
    if (preg_match_all('/\b(\d{5,}(?:-\d+)?)\b/', $question, $m)) {
        $searchTerms = $m[1];
    }
    // Потом текстовые слова
    $words = preg_split('/[\s,.\-!?:;]+/u', $q);
    $textTerms = [];
    foreach ($words as $w) {
        $w = trim($w);
        if (mb_strlen($w) >= 3 && !in_array($w, $stopWords) && !is_numeric($w)) {
            $textTerms[] = stemRu($w);
        }
    }

    if (empty($searchTerms) && empty($textTerms)) return '';

    // Загружаем карточки
    $s = $pdo->prepare("SELECT id, name, analogs FROM cards ORDER BY name");
    $s->execute();
    $allCards = $s->fetchAll();
    if (!$allCards) return '';

    $results = [];

    // Поиск по артикулу
    foreach ($searchTerms as $article) {
        foreach ($allCards as $c) {
            if ((string)$c['id'] === $article) {
                $results[$c['id']] = ['card' => $c, 'reason' => 'найдено по артикулу'];
                break;
            }
            // Проверяем аналоги
            $analogs = $c['analogs'] ? json_decode($c['analogs'], true) : [];
            if (!is_array($analogs)) $analogs = array_filter(array_map('trim', explode(',', $c['analogs'])));
            if (in_array($article, $analogs)) {
                $results[$c['id']] = ['card' => $c, 'reason' => "найдено по аналогу (арт. {$article})"];
            }
        }
    }

    // Текстовый поиск по названию
    if (empty($results) && !empty($textTerms)) {
        $searchQuery = implode(' ', $textTerms);
        $normQuery = $normalize($searchQuery);

        foreach ($allCards as $c) {
            $normId = $normalize($c['id']);
            $normName = $normalize($c['name']);
            $normFull = $normId . $normName;

            $analogs = $c['analogs'] ? json_decode($c['analogs'], true) : [];
            if (!is_array($analogs)) $analogs = array_filter(array_map('trim', explode(',', $c['analogs'])));

            // Точное совпадение артикула
            if ($normId === $normQuery) {
                $results[$c['id']] = ['card' => $c, 'reason' => 'точное совпадение'];
                continue;
            }
            // Совпадение по артикулу + названию
            if (mb_strpos($normFull, $normQuery) !== false) {
                $results[$c['id']] = ['card' => $c, 'reason' => 'найдено по названию'];
                continue;
            }
            // Частичное совпадение артикула
            if (mb_strpos($normId, $normQuery) !== false) {
                $results[$c['id']] = ['card' => $c, 'reason' => 'часть артикула'];
                continue;
            }
            // Совпадение по аналогу
            foreach ($analogs as $a) {
                if (mb_strpos($normalize($a), $normQuery) !== false) {
                    $results[$c['id']] = ['card' => $c, 'reason' => 'найдено по аналогу'];
                    break;
                }
            }
            // Частичное совпадение названия
            if (mb_strpos($normName, $normQuery) !== false) {
                $results[$c['id']] = ['card' => $c, 'reason' => 'найдено по названию'];
            }

            if (count($results) >= 10) break; // Ограничиваем результаты
        }
    }

    if (empty($results)) return '';

    $context = "\n== КАРТОЧКИ ТОВАРОВ ==\n";
    foreach ($results as $r) {
        $c = $r['card'];
        $analogs = $c['analogs'] ? json_decode($c['analogs'], true) : [];
        if (!is_array($analogs)) $analogs = array_filter(array_map('trim', explode(',', $c['analogs'])));
        $analogStr = !empty($analogs) ? ' | аналоги: ' . implode(', ', array_slice($analogs, 0, 5)) : '';
        $context .= "- {$c['id']} {$c['name']}{$analogStr} ({$r['reason']})\n";
    }

    return $context;
}
