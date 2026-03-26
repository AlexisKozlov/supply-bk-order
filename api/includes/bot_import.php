<?php
// ═══ Импорт данных через Telegram-бот ═══

require_once __DIR__ . '/../lib/SimpleXLSX.php';
require_once __DIR__ . '/../lib/SimpleXLS.php';

// Скачать файл из Telegram по file_id
function botDownloadFile($fileId) {
    global $BOT_TOKEN;
    $resp = json_decode(@file_get_contents("https://api.telegram.org/bot{$BOT_TOKEN}/getFile?" . http_build_query(['file_id' => $fileId])), true);
    $path = $resp['result']['file_path'] ?? null;
    if (!$path) return null;
    $url = "https://api.telegram.org/file/bot{$BOT_TOKEN}/{$path}";
    $tmp = tempnam(sys_get_temp_dir(), 'tg_import_');
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $tmpFile = $tmp . '.' . $ext;
    rename($tmp, $tmpFile);
    $data = @file_get_contents($url);
    if (!$data) { @unlink($tmpFile); return null; }
    file_put_contents($tmpFile, $data);
    return $tmpFile;
}

// Прочитать Excel в массив строк
function botReadExcel($filePath) {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        $xlsx = \Shuchkin\SimpleXLSX::parse($filePath);
        if (!$xlsx) return null;
        return $xlsx->rows(0);
    } elseif ($ext === 'xls') {
        $xls = \Shuchkin\SimpleXLS::parse($filePath);
        if (!$xls) return null;
        return $xls->rows(0);
    }
    return null;
}

// ═══ Парсинг реализации (restaurant_sales) ═══
function botParseSales($rows) {
    if (!$rows || count($rows) < 2) return [];
    // Ищем заголовок
    $headerIdx = null;
    $cols = [];
    foreach ($rows as $i => $row) {
        if ($i > 20) break;
        $joined = mb_strtolower(implode('|', array_map('strval', $row)));
        if (strpos($joined, 'группааналогов') !== false || strpos($joined, 'группа аналогов') !== false || strpos($joined, 'analog') !== false) {
            // Маппинг колонок
            foreach ($row as $ci => $cell) {
                $cl = mb_strtolower(trim(strval($cell)));
                if (strpos($cl, 'группа') !== false && strpos($cl, 'аналог') !== false) $cols['group'] = $ci;
                if ($cl === 'дата' || $cl === 'date') $cols['date'] = $ci;
                if (strpos($cl, 'продажи') !== false || strpos($cl, 'расход') !== false || strpos($cl, 'количество') !== false) $cols['qty'] = $ci;
                if (strpos($cl, 'мест хранения') !== false || strpos($cl, 'ресторан') !== false) $cols['rest_count'] = $ci;
            }
            if (isset($cols['group']) && isset($cols['date'])) { $headerIdx = $i; break; }
        }
    }
    if ($headerIdx === null || !isset($cols['group']) || !isset($cols['date'])) return [];

    $items = [];
    for ($i = $headerIdx + 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        $group = trim(strval($row[$cols['group']] ?? ''));
        $dateRaw = trim(strval($row[$cols['date']] ?? ''));
        $qty = floatval($row[$cols['qty'] ?? -1] ?? 0);
        $restCount = isset($cols['rest_count']) ? intval($row[$cols['rest_count']] ?? 0) : 0;
        if (!$group || !$dateRaw) continue;
        // Парсим дату
        $date = botParseDate($dateRaw);
        if (!$date) continue;
        $items[] = ['sale_date' => $date, 'analog_group' => $group, 'quantity' => $qty, 'restaurant_count' => $restCount];
    }
    return $items;
}

// ═══ Парсинг анализа запасов (analysis_data) ═══
function botParseAnalysis($rows) {
    if (!$rows || count($rows) < 2) return [];

    // Собираем все заголовки из первых 15 строк (multi-row headers)
    $allHeaders = []; // ci => lowercase text
    $headerEndIdx = null;
    for ($i = 0; $i < min(count($rows), 15); $i++) {
        $hasData = false;
        foreach ($rows[$i] as $ci => $cell) {
            $cl = mb_strtolower(trim(strval($cell)));
            if ($cl) {
                if (!isset($allHeaders[$ci])) $allHeaders[$ci] = '';
                $allHeaders[$ci] .= ' ' . $cl;
            }
        }
    }

    // Ищем колонки по собранным заголовкам
    $cols = [];
    foreach ($allHeaders as $ci => $text) {
        $text = trim($text);
        // ��ртикул / номенклатура / название товара
        if (!isset($cols['sku']) && preg_match('/артикул|арт\.|sku|article|номенклатур/', $text)) $cols['sku'] = $ci;
        // Остаток
        if (!isset($cols['stock']) && preg_match('/конечный остаток|остат|stock|свобод|доступ/', $text)) $cols['stock'] = $ci;
        // Расход
        if (!isset($cols['consumption']) && preg_match('/расход|потреблен|consumption|продаж/', $text)) $cols['consumption'] = $ci;
    }

    if (!isset($cols['sku']) || (!isset($cols['stock']) && !isset($cols['consumption']))) return [];

    // Определяем где начинаю��ся данные — первая строка где в колонке sku есть непустое значение
    $dataStart = null;
    for ($i = 0; $i < min(count($rows), 20); $i++) {
        $val = trim(strval($rows[$i][$cols['sku']] ?? ''));
        // Пропускаем заголовочные строки
        $valLower = mb_strtolower($val);
        if ($val && !preg_match('/артикул|номенклатур|склад|парамет|ведомость|отбор|количеств|условия/', $valLower)) {
            $dataStart = $i;
            break;
        }
    }
    if ($dataStart === null) return [];

    $items = [];
    for ($i = $dataStart; $i < count($rows); $i++) {
        $row = $rows[$i];
        $raw = trim(strval($row[$cols['sku']] ?? ''));
        if (!$raw) continue;
        // Пропускаем строки "Ито��о", "Всего" и т.д.
        if (preg_match('/^(итого|всего|total)/iu', $raw)) continue;

        // Извлекаем артикул — первые цифры из строки
        $sku = $raw;
        if (preg_match('/^(\d{4,})/', $raw, $m)) {
            $sku = $m[1];
        }

        $stock = isset($cols['stock']) ? floatval(str_replace([' ', "\xc2\xa0"], '', $row[$cols['stock']] ?? 0)) : 0;
        $consumption = isset($cols['consumption']) ? floatval(str_replace([' ', "\xc2\xa0"], '', $row[$cols['consumption']] ?? 0)) : 0;

        if ($stock == 0 && $consumption == 0) continue;

        if (isset($items[$sku])) {
            $items[$sku]['stock'] += $stock;
            $items[$sku]['consumption'] += $consumption;
        } else {
            $items[$sku] = ['sku' => $sku, 'stock' => $stock, 'consumption' => $consumption];
        }
    }
    return array_values($items);
}

// Нормализация заказчика
function botNormalizeCustomer($raw) {
    if (!$raw) return '';
    $lower = mb_strtolower($raw);
    $map = [
        'бургер бк' => 'Бургер БК',
        'воглия' => 'Воглия Матта',
        'додо' => 'Пицца Стар',
        'сбарро' => 'Пицца Стар',
    ];
    foreach ($map as $key => $val) {
        if (mb_strpos($lower, $key) !== false) return $val;
    }
    return trim($raw);
}

// Нормализация склада
function botNormalizeWarehouse($raw) {
    if (!$raw) return '';
    $lower = mb_strtolower($raw);
    if (mb_strpos($lower, 'шабаны') !== false) return 'Шабаны';
    if (mb_strpos($lower, 'прилесье 6') !== false) return 'Сухой сток';
    if (mb_strpos($lower, 'прилесье 1 охлажд') !== false) return 'Холод';
    if (mb_strpos($lower, 'прилесье 1 заморож') !== false) return 'Мороз';
    if (mb_strpos($lower, 'прилесье 1') !== false) return 'Холод';
    return trim($raw);
}

// ═══ Парсинг сроков годности (stock_malling) ═══
function botParseShelfLife($rows) {
    if (!$rows || count($rows) < 2) return [];
    $headerIdx = null;
    $cols = [];
    foreach ($rows as $i => $row) {
        if ($i > 15) break;
        $rowCols = [];
        $hits = 0;
        foreach ($row as $ci => $cell) {
            $cl = mb_strtolower(trim(strval($cell)));
            if (preg_match('/заказчик|покупатель|клиент/', $cl)) { $rowCols['customer'] = $ci; $hits++; }
            if (preg_match('/название склада|склад хранения/', $cl) || $cl === 'склад') { $rowCols['warehouse'] = $ci; $hits++; }
            if (preg_match('/наименование товар|наименование номенклатур|наименование продукт|^номенклатура/', $cl)) { $rowCols['product'] = $ci; $hits++; }
            if (preg_match('/годен до|срок годности|дата окончания/', $cl)) { $rowCols['expiry'] = $ci; $hits++; }
            if (preg_match('/дата производств|дата выработки|дата изготовлен/', $cl)) { $rowCols['production'] = $ci; $hits++; }
            if (preg_match('/причина блокировк|блокировк/', $cl)) $rowCols['block'] = $ci;
            if (preg_match('/статус годн/', $cl)) $rowCols['status'] = $ci;
            if (preg_match('/остат|количеств|кол-во/', $cl)) { $rowCols['qty'] = $ci; $hits++; }
        }
        if ($hits >= 3 && isset($rowCols['product'])) { $cols = $rowCols; $headerIdx = $i; break; }
    }
    if ($headerIdx === null) return [];

    $items = [];
    for ($i = $headerIdx + 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        $product = trim(strval($row[$cols['product']] ?? ''));
        if (!$product) continue;
        $items[] = [
            'customer' => botNormalizeCustomer(trim(strval($row[$cols['customer'] ?? -1] ?? ''))),
            'warehouse' => botNormalizeWarehouse(trim(strval($row[$cols['warehouse'] ?? -1] ?? ''))),
            'product_name' => $product,
            'production_date' => botParseDate(strval($row[$cols['production'] ?? -1] ?? '')),
            'expiry_date' => botParseDate(strval($row[$cols['expiry'] ?? -1] ?? '')),
            'block_reason' => trim(strval($row[$cols['block'] ?? -1] ?? '')) ?: null,
            'expiry_status' => trim(strval($row[$cols['status'] ?? -1] ?? '')) ?: null,
            'quantity' => floatval($row[$cols['qty'] ?? -1] ?? 0),
        ];
    }
    return $items;
}

// Парсинг даты (DD.MM.YYYY, YYYY-MM-DD, Excel serial)
function botParseDate($val) {
    $val = trim(strval($val));
    if (!$val) return null;
    // YYYY-MM-DD
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $val)) return substr($val, 0, 10);
    // DD.MM.YYYY
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', $val, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
    // DD/MM/YYYY
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $val, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
    // Excel serial number
    if (is_numeric($val) && $val > 30000 && $val < 60000) {
        $unix = ($val - 25569) * 86400;
        return date('Y-m-d', $unix);
    }
    return null;
}

// ═══ Обработка файла от закупщика ═══
function botHandleImport($chatId, $fileId, $importType, $user) {
    global $pdo;

    $filePath = botDownloadFile($fileId);
    if (!$filePath) {
        sendMessage($chatId, "❌ Не удалось скачать файл.");
        return;
    }

    $rows = botReadExcel($filePath);
    @unlink($filePath);
    if (!$rows) {
        sendMessage($chatId, "❌ Не удалось прочитать файл. Поддерживаются форматы .xlsx и .xls");
        return;
    }

    $userName = $user['name'] ?? 'bot';
    $entity = getUserEntity($user);

    if ($importType === 'sales') {
        $items = botParseSales($rows);
        if (empty($items)) {
            sendMessage($chatId, "❌ Не удалось распознать данные реализации.\n\nНужны колонки: Группа аналогов, Дата, Продажи/Расход.");
            return;
        }
        // Идентично replace_restaurant_sales на сайте — upsert в транзакции
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare("INSERT INTO restaurant_sales (sale_date, analog_group, quantity, restaurant_count) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), restaurant_count = VALUES(restaurant_count)");
            $cnt = 0;
            foreach ($items as $item) {
                if (!$item['sale_date'] || !$item['analog_group']) continue;
                $ins->execute([$item['sale_date'], $item['analog_group'], $item['quantity'], $item['restaurant_count']]);
                $cnt++;
            }
            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            sendMessage($chatId, "❌ Ошибка: " . $e->getMessage());
            return;
        }
        sendMessage($chatId, "✅ <b>Реализация загружена</b>\n\nЗаписей: <b>{$cnt}</b>", ['inline_keyboard' => [[['text' => '◂ Меню', 'callback_data' => 'cmd_menu']]]]);

    } elseif ($importType === 'analysis') {
        $items = botParseAnalysis($rows);
        if (empty($items)) {
            sendMessage($chatId, "❌ Не удалось распознать данные анализа.\n\nНужны колонки: Артикул (арт./sku), Остатки (остат./stock) и/или Расход (расход/consumption).");
            return;
        }
        if (!$entity) {
            sendMessage($chatId, "❌ Не выбрано юрлицо. Переключите через /entity.");
            return;
        }
        // Идентично replace_analysis_data на сайте — DELETE + INSERT
        $allowed = ['id','legal_entity','sku','stock','consumption','period_days','updated_by','updated_at'];
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM analysis_data WHERE legal_entity = ?")->execute([$entity]);
            foreach ($items as $item) {
                $row = [
                    'id' => $entity . '_' . $item['sku'],
                    'legal_entity' => $entity,
                    'sku' => $item['sku'],
                    'stock' => $item['stock'],
                    'consumption' => $item['consumption'],
                    'period_days' => 7,
                    'updated_by' => $userName,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                $row = array_intersect_key($row, array_flip($allowed));
                $cols = array_keys($row);
                $ph = implode(',', array_fill(0, count($cols), '?'));
                $cn = implode(',', array_map(fn($c) => "`$c`", $cols));
                $pdo->prepare("INSERT INTO analysis_data ({$cn}) VALUES ({$ph})")->execute(array_values($row));
            }
            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            sendMessage($chatId, "❌ Ошибка: " . $e->getMessage());
            return;
        }
        $short = getEntityShort($entity);
        sendMessage($chatId, "✅ <b>Анализ запасов загружен</b>\n\nЮрлицо: <b>{$short}</b>\nТоваров: <b>" . count($items) . "</b>", ['inline_keyboard' => [[['text' => '◂ Меню', 'callback_data' => 'cmd_menu']]]]);

    } elseif ($importType === 'shelf_life') {
        $items = botParseShelfLife($rows);
        if (empty($items)) {
            sendMessage($chatId, "❌ Не удалось распознать данные сроков годности.\n\nНужны колонки: Наименование, Годен до, Остатки.");
            return;
        }
        // Идентично replace_stock_malling — DELETE по customer + INSERT
        $customers = array_values(array_unique(array_filter(array_column($items, 'customer'))));
        $cnt = 0;
        $pdo->beginTransaction();
        try {
            if (!empty($customers)) {
                $ph = implode(',', array_fill(0, count($customers), '?'));
                $pdo->prepare("DELETE FROM stock_malling WHERE customer IN ({$ph})")->execute($customers);
            }
            $allowedCols = ['customer','warehouse','product_name','production_date','expiry_date','block_reason','expiry_status','quantity','uploaded_at','uploaded_by'];
            foreach ($items as $item) {
                $row = array_intersect_key(array_merge($item, ['uploaded_at' => date('Y-m-d H:i:s'), 'uploaded_by' => $userName]), array_flip($allowedCols));
                $cols = array_keys($row);
                $phRow = implode(',', array_fill(0, count($cols), '?'));
                $cn = implode(',', array_map(fn($c) => "`$c`", $cols));
                $pdo->prepare("INSERT INTO stock_malling ({$cn}) VALUES ({$phRow})")->execute(array_values($row));
                $cnt++;
            }
            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            sendMessage($chatId, "❌ Ошибка сохранения: " . $e->getMessage());
            return;
        }
        sendMessage($chatId, "✅ <b>Сроки годности загружены</b>\n\nЗаписей: <b>{$cnt}</b>", ['inline_keyboard' => [[['text' => '◂ Меню', 'callback_data' => 'cmd_menu']]]]);
    }
}
