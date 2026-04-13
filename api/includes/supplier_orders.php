<?php
/**
 * API заявок поставщикам — универсальный модуль.
 * Подключается из index.php. Переменные ($pdo, $endpoint, $subpoint, $method, $body, $uri) через global.
 *
 * Постоянный режим: вместо сессий — флаг is_accepting_orders в so_supplier_settings.
 *
 * Маршруты для ресторанов (авторизация через ro_users / X-RO-Token):
 *   GET    so/suppliers           — список поставщиков с графиком для ресторана
 *   GET    so/products/:suppId    — товары по поставщику (шаблон)
 *   GET    so/my-orders           — история заявок
 *   GET    so/my-order/:suppId/:date — моя заявка на дату
 *   POST   so/submit-order        — отправить заявку
 *
 * Маршруты для закупщиков (сессия основного приложения):
 *   GET    so/admin/status        — сводка заявок (по поставщику + дате)
 *   GET    so/admin/orders        — список заявок по дням
 *   GET    so/admin/order/:id     — детали заявки
 *   PATCH  so/admin/order/:id     — редактировать заявку
 *   DELETE so/admin/order/:id     — удалить заявку
 *   GET    so/admin/settings      — настройки поставщика (вкл/выкл, дедлайн)
 *   POST   so/admin/settings      — обновить настройки
 *   GET    so/admin/schedules     — графики поставок
 *   POST   so/admin/schedules     — сохранить графики
 *   GET    so/admin/templates     — шаблоны товаров
 *   POST   so/admin/templates     — сохранить шаблон
 *   POST   so/admin/extend-deadline — разовое продление дедлайна
 *   GET    so/admin/export        — Excel-экспорт
 */

if ($endpoint !== 'so') return;

// ═══ Хелперы ═══

function soRespond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function soGetRestaurantSession($pdo) {
    $token = $_SERVER['HTTP_X_RO_TOKEN'] ?? '';
    if (!$token) return null;
    $s = $pdo->prepare("
        SELECT ru.id, ru.restaurant_number, ru.legal_entity, ru.session_active_until,
               r.id as restaurant_id, r.region, r.city, r.address
        FROM ro_users ru
        LEFT JOIN restaurants r ON r.number = ru.restaurant_number AND r.active = 1
        WHERE ru.session_token = ? AND ru.is_active = 1
    ");
    $s->execute([$token]);
    $user = $s->fetch();
    if (!$user) return null;
    if ($user['session_active_until'] && strtotime($user['session_active_until']) < time()) return null;
    // Продлеваем сессию при каждом запросе (сброс таймера неактивности)
    $pdo->prepare("UPDATE ro_users SET session_active_until = ? WHERE id = ?")
        ->execute([date('Y-m-d H:i:s', strtotime('+3 hours')), $user['id']]);
    return $user;
}

// Настройки поставщика: есть строка в so_supplier_settings или дефолты
function soGetSupplierSettings($pdo, $supplierId) {
    $s = $pdo->prepare("SELECT supplier_id, is_accepting_orders, default_deadline_time, pause_message FROM so_supplier_settings WHERE supplier_id = ?");
    $s->execute([$supplierId]);
    $row = $s->fetch();
    if ($row) return $row;
    return [
        'supplier_id' => $supplierId,
        'is_accepting_orders' => 1,
        'default_deadline_time' => '14:00:00',
        'pause_message' => null,
    ];
}

function soCheckDeadline($pdo, $supplierId, $deliveryDate) {
    $tz = new DateTimeZone('Europe/Minsk');
    $now = new DateTime('now', $tz);

    // 1. Переопределение на конкретную дату (по поставщику)
    $s = $pdo->prepare("SELECT deadline_time FROM so_deadline_overrides WHERE supplier_id = ? AND delivery_date = ?");
    $s->execute([$supplierId, $deliveryDate]);
    $override = $s->fetch();

    // 2. Правило по дню недели доставки
    $deliveryDow = (int)(new DateTime($deliveryDate))->format('N');
    $rule = null;
    if ($supplierId) {
        $r = $pdo->prepare("SELECT deadline_dow, deadline_time FROM so_deadline_rules WHERE supplier_id = ? AND delivery_dow = ?");
        $r->execute([$supplierId, $deliveryDow]);
        $rule = $r->fetch();
    }

    // 3. Вычисляем дату и время дедлайна
    if ($override) {
        $deadlineDate = (new DateTime($deliveryDate, $tz))->modify('-1 day');
        $deadlineTime = $override['deadline_time'];
    } elseif ($rule) {
        $deadlineDow = (int)$rule['deadline_dow'];
        $deadlineTime = $rule['deadline_time'];
        $deliveryObj = new DateTime($deliveryDate, $tz);
        $deadlineDate = clone $deliveryObj;
        $diff = $deliveryDow - $deadlineDow;
        if ($diff <= 0) $diff += 7;
        $deadlineDate->modify("-{$diff} days");
    } else {
        // Фоллбэк: дедлайн = день перед доставкой, по умолчанию из настроек поставщика
        $settings = soGetSupplierSettings($pdo, $supplierId);
        $deadlineDate = (new DateTime($deliveryDate, $tz))->modify('-1 day');
        $deadlineTime = $settings['default_deadline_time'] ?? '14:00:00';
    }

    $deadlineDT = new DateTime($deadlineDate->format('Y-m-d') . ' ' . $deadlineTime, $tz);
    $deadlineStr = $deadlineDate->format('Y-m-d') . ' ' . substr($deadlineTime, 0, 5);

    if ($now < $deadlineDT) {
        return ['status' => 'open', 'deadline' => $deadlineStr];
    }
    return ['status' => 'closed', 'deadline' => $deadlineStr];
}

// ═══ Парсинг маршрута ═══

$soParts = explode('/', $uri);
// uri = "so/action/param1/param2"
$soAction = $soParts[1] ?? '';
$soParam1 = $soParts[2] ?? null;
$soParam2 = $soParts[3] ?? null;
$soParam3 = $soParts[4] ?? null;

$dayNames = [1=>'ПН', 2=>'ВТ', 3=>'СР', 4=>'ЧТ', 5=>'ПТ', 6=>'С��', 7=>'ВС'];
$dayNamesFull = [1=>'Понедельник',2=>'Вторник',3=>'Среда',4=>'Четверг',5=>'Пятница',6=>'Суббота',7=>'Воскресенье'];

// ═══════════════════════════════════════════════
// Маршруты для ресторанов
// ════��══════════════════════════════════════════

// --- Список поставщиков с графиком ---
if ($soAction === 'suppliers' && $method === 'GET') {
    $rest = soGetRestaurantSession($pdo);
    if (!$rest) soRespond(['error' => 'Не авторизован'], 401);

    // Группа юрлиц ресторана — показываем только поставщиков своей группы,
    // даже если вдруг появится запись в so_supplier_schedules с чужим поставщиком.
    $restGroup = getEntityGroup($rest['legal_entity'] ?? '');

    $s = $pdo->prepare("
        SELECT DISTINCT s.id, s.short_name, s.full_name
        FROM so_supplier_schedules ss
        JOIN suppliers s ON s.id = ss.supplier_id AND s.is_active = 1
        WHERE ss.restaurant_id = ? AND ss.is_active = 1 AND s.legal_entity_group = ?
        ORDER BY s.short_name
    ");
    $s->execute([$rest['restaurant_id'], $restGroup]);
    $suppliers = $s->fetchAll();

    // Для каждого поставщика — график, настройки, ближайшие поставки
    $result = [];
    $tz = new DateTimeZone('Europe/Minsk');
    $today = new DateTime('now', $tz);
    $today->setTime(0, 0, 0);

    foreach ($suppliers as $sup) {
        // График
        $sch = $pdo->prepare("SELECT order_day, delivery_day FROM so_supplier_schedules WHERE supplier_id = ? AND restaurant_id = ? AND is_active = 1 ORDER BY order_day");
        $sch->execute([$sup['id'], $rest['restaurant_id']]);
        $schedule = $sch->fetchAll();

        $scheduleFormatted = [];
        foreach ($schedule as $sc) {
            $scheduleFormatted[] = [
                'order_day' => (int)$sc['order_day'],
                'order_day_name' => $dayNames[(int)$sc['order_day']] ?? '',
                'delivery_day' => (int)$sc['delivery_day'],
                'delivery_day_name' => $dayNames[(int)$sc['delivery_day']] ?? '',
            ];
        }

        // Настройки: приём вкл/выкл
        $settings = soGetSupplierSettings($pdo, $sup['id']);
        $isAccepting = (int)($settings['is_accepting_orders'] ?? 1) === 1;

        // Доступные поставки: следующие 2 цикла для каждого пункта графика
        $availableDates = [];
        if ($isAccepting) {
            $WEEKS_AHEAD = 2; // показываем поставки на текущую и следующую неделю
            foreach ($schedule as $sc) {
                $orderDow = (int)$sc['order_day'];
                $deliveryDow = (int)$sc['delivery_day'];

                // Базовая дата — понедельник текущей недели (по ISO)
                $weekStart = clone $today;
                $weekStart->modify('-' . ((int)$today->format('N') - 1) . ' days');

                for ($w = 0; $w < $WEEKS_AHEAD; $w++) {
                    $orderDateObj = (clone $weekStart)->modify('+' . ($orderDow - 1 + $w * 7) . ' days');
                    $deliveryDateObj = (clone $weekStart)->modify('+' . ($deliveryDow - 1 + $w * 7) . ' days');
                    // Если день поставки по номеру <= день заказа, поставка на следующей неделе относительно заказа
                    if ($deliveryDow <= $orderDow) {
                        $deliveryDateObj->modify('+7 days');
                    }

                    // Скрываем поставки, которые уже прошли (delivery_date < сегодня)
                    if ($deliveryDateObj < $today) continue;

                    $orderDateStr = $orderDateObj->format('Y-m-d');
                    $deliveryDateStr = $deliveryDateObj->format('Y-m-d');

                    $deadlineInfo = soCheckDeadline($pdo, $sup['id'], $deliveryDateStr);

                    // Существующая заявка (без привязки к сессии)
                    $os = $pdo->prepare("SELECT o.id, o.status, o.submitted_at,
                               (SELECT COUNT(*) FROM so_order_items WHERE order_id = o.id AND COALESCE(admin_qty, quantity) > 0) as item_count
                        FROM so_orders o
                        WHERE o.supplier_id = ? AND o.restaurant_number = ? AND o.delivery_date = ?");
                    $os->execute([$sup['id'], $rest['restaurant_number'], $deliveryDateStr]);
                    $order = $os->fetch();

                    // Если дедлайн прошёл и нет заявки — не показываем
                    if ($deadlineInfo['status'] === 'closed' && !$order) continue;

                    $availableDates[] = [
                        'order_date' => $orderDateStr,
                        'order_day_name' => $dayNamesFull[$orderDow] ?? '',
                        'delivery_date' => $deliveryDateStr,
                        'delivery_day_name' => $dayNamesFull[$deliveryDow] ?? '',
                        'deadline' => $deadlineInfo['deadline'],
                        'deadline_status' => $deadlineInfo['status'],
                        'order' => $order ? [
                            'id' => (int)$order['id'],
                            'status' => $order['status'],
                            'submitted_at' => $order['submitted_at'],
                            'item_count' => (int)$order['item_count'],
                            'is_skip' => ((int)$order['item_count']) === 0,
                        ] : null,
                    ];
                }
            }

            // Убираем дубли по delivery_date (если в графике несколько записей указывают на одну дату)
            $seen = [];
            $availableDates = array_values(array_filter($availableDates, function ($d) use (&$seen) {
                if (isset($seen[$d['delivery_date']])) return false;
                $seen[$d['delivery_date']] = true;
                return true;
            }));

            // Сортировка: ближайшая дата поставки первой (по возрастанию)
            usort($availableDates, function ($a, $b) {
                return strcmp($a['delivery_date'], $b['delivery_date']);
            });
        }

        $result[] = [
            'id' => $sup['id'],
            'name' => $sup['short_name'],
            'full_name' => $sup['full_name'],
            'schedule' => $scheduleFormatted,
            'is_accepting_orders' => $isAccepting,
            'pause_message' => $settings['pause_message'] ?? null,
            'available_dates' => $availableDates,
        ];
    }

    soRespond(['suppliers' => $result]);
}

// --- Товары по поставщику (из шаблона) ---
if ($soAction === 'products' && $method === 'GET' && $soParam1) {
    $rest = soGetRestaurantSession($pdo);
    if (!$rest) soRespond(['error' => 'Не авторизован'], 401);

    $supplierId = $soParam1;
    $le = $rest['legal_entity'];

    // Из шаблона
    $s = $pdo->prepare("
        SELECT t.id, t.product_id, t.sku, t.product_name, t.sort_order,
               COALESCE(t.multiplicity, p.multiplicity) as multiplicity,
               t.min_qty,
               p.qty_per_box, p.unit_of_measure
        FROM so_templates t
        LEFT JOIN products p ON p.id = t.product_id
        WHERE t.supplier_id = ? AND t.legal_entity = ? AND t.is_active = 1
        ORDER BY t.sort_order, t.product_name
    ");
    $s->execute([$supplierId, $le]);
    $products = $s->fetchAll();

    // Если шаблон пуст — берём товары этого поставщика из products
    if (empty($products)) {
        $s = $pdo->prepare("
            SELECT id as product_id, sku, name as product_name, qty_per_box, unit_of_measure
            FROM products
            WHERE supplier = (SELECT short_name FROM suppliers WHERE id = ?)
              AND legal_entity = ? AND is_active = 1
            ORDER BY name
        ");
        $s->execute([$supplierId, $le]);
        $products = $s->fetchAll();
    }

    soRespond(['products' => $products]);
}

// --- Мой заказ на дату ---
if ($soAction === 'my-order' && $method === 'GET' && $soParam1 && $soParam2) {
    $rest = soGetRestaurantSession($pdo);
    if (!$rest) soRespond(['error' => 'Не авторизован'], 401);

    $supplierId = $soParam1;
    $deliveryDate = $soParam2;

    $s = $pdo->prepare("SELECT id, status, submitted_at, updated_at FROM so_orders WHERE supplier_id = ? AND restaurant_number = ? AND delivery_date = ?");
    $s->execute([$supplierId, $rest['restaurant_number'], $deliveryDate]);
    $order = $s->fetch();

    if (!$order) soRespond(['order' => null]);

    // quantity — исходное значение от ресторана, admin_qty — правка закупщика (если была)
    $items = $pdo->prepare("SELECT product_id, sku, product_name, quantity, admin_qty FROM so_order_items WHERE order_id = ? AND COALESCE(admin_qty, quantity) > 0 ORDER BY product_name");
    $items->execute([$order['id']]);

    soRespond([
        'order' => [
            'id' => (int)$order['id'],
            'status' => $order['status'],
            'submitted_at' => $order['submitted_at'],
            'items' => $items->fetchAll(),
        ],
    ]);
}

// --- История заявок ---
if ($soAction === 'my-orders' && $method === 'GET') {
    $rest = soGetRestaurantSession($pdo);
    if (!$rest) soRespond(['error' => 'Не авторизован'], 401);

    $supplierId = $_GET['supplier_id'] ?? '';
    $limit = min((int)($_GET['limit'] ?? 20), 50);

    $where = "o.restaurant_number = ?";
    $params = [$rest['restaurant_number']];
    if ($supplierId) {
        $where .= " AND o.supplier_id = ?";
        $params[] = $supplierId;
    }

    $s = $pdo->prepare("
        SELECT o.id, o.delivery_date, o.order_date, o.status, o.submitted_at, o.supplier_id,
               s.short_name as supplier_name,
               (SELECT COUNT(*) FROM so_order_items WHERE order_id = o.id AND COALESCE(admin_qty, quantity) > 0) as item_count,
               (SELECT SUM(COALESCE(admin_qty, quantity)) FROM so_order_items WHERE order_id = o.id) as total_qty
        FROM so_orders o
        JOIN suppliers s ON s.id = o.supplier_id
        WHERE {$where}
        ORDER BY o.delivery_date DESC
        LIMIT ?
    ");
    $params[] = $limit;
    $s->execute($params);
    soRespond(['orders' => $s->fetchAll()]);
}

// --- Отправить заявку ---
if ($soAction === 'submit-order' && $method === 'POST') {
    $rest = soGetRestaurantSession($pdo);
    if (!$rest) soRespond(['error' => 'Не авторизован'], 401);

    $supplierId = $body['supplier_id'] ?? '';
    $deliveryDate = $body['delivery_date'] ?? '';
    $orderDate = $body['order_date'] ?? '';
    $items = $body['items'] ?? [];
    // Флаг «Поставка не нужна» — пустая заявка-отказ
    $skipDelivery = !empty($body['skip_delivery']);

    if (!$supplierId || !$deliveryDate) soRespond(['error' => 'Не указан поставщик или дата доставки'], 400);
    if (empty($items) && !$skipDelivery) soRespond(['error' => 'Заявка пуста'], 400);

    // Проверяем, что поставщик принимает заявки
    $settings = soGetSupplierSettings($pdo, $supplierId);
    if ((int)($settings['is_accepting_orders'] ?? 1) !== 1) {
        $msg = $settings['pause_message'] ?: 'Приём заявок для этого поставщика временно приостановлен';
        soRespond(['error' => $msg], 403);
    }

    // Проверяем дедлайн (по дате доставки)
    $dlStatus = soCheckDeadline($pdo, $supplierId, $deliveryDate);
    if ($dlStatus['status'] === 'closed') {
        soRespond(['error' => 'Приём заявок на эту дату закрыт (дедлайн ' . substr($dlStatus['deadline'], 0, 5) . ')'], 403);
    }

    // Проверяем: есть ли уже заявка?
    $existing = $pdo->prepare("SELECT id FROM so_orders WHERE supplier_id = ? AND restaurant_number = ? AND delivery_date = ?");
    $existing->execute([$supplierId, $rest['restaurant_number'], $deliveryDate]);
    $existingOrder = $existing->fetch();

    $pdo->beginTransaction();
    try {
        if ($existingOrder) {
            $orderId = $existingOrder['id'];
            $pdo->prepare("DELETE FROM so_order_items WHERE order_id = ?")->execute([$orderId]);
            $pdo->prepare("UPDATE so_orders SET status = 'submitted', submitted_at = NOW(), updated_at = NOW() WHERE id = ?")
                ->execute([$orderId]);
        } else {
            $le = $rest['legal_entity'];
            $pdo->prepare("INSERT INTO so_orders (restaurant_number, supplier_id, delivery_date, order_date, status, submitted_at, legal_entity) VALUES (?, ?, ?, ?, 'submitted', NOW(), ?)")
                ->execute([$rest['restaurant_number'], $supplierId, $deliveryDate, $orderDate ?: date('Y-m-d'), $le]);
            $orderId = $pdo->lastInsertId();
        }

        // Агрегируем позиции по SKU — защита от дублей, даже если фронт прислал
        // один товар несколькими строками.
        $aggregated = [];
        foreach ($items as $item) {
            $qty = floatval($item['quantity'] ?? 0);
            if ($qty <= 0) continue;
            $sku = $item['sku'] ?? '';
            if ($sku === '') continue;
            if (!isset($aggregated[$sku])) {
                $aggregated[$sku] = [
                    'product_id' => $item['product_id'] ?? '',
                    'sku' => $sku,
                    'product_name' => $item['product_name'] ?? '',
                    'quantity' => 0,
                ];
            }
            $aggregated[$sku]['quantity'] += $qty;
        }

        // Вставляем позиции (UNIQUE KEY order_id+sku гарантирует отсутствие дублей)
        $insertItem = $pdo->prepare("INSERT INTO so_order_items (order_id, product_id, sku, product_name, quantity) VALUES (?, ?, ?, ?, ?)");
        $totalQty = 0;
        $totalItems = 0;
        foreach ($aggregated as $item) {
            $insertItem->execute([
                $orderId,
                $item['product_id'],
                $item['sku'],
                $item['product_name'],
                $item['quantity'],
            ]);
            $totalQty += $item['quantity'];
            $totalItems++;
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        soRespond(['error' => 'Ошибка сохранения заявки'], 500);
    }

    // Уведомление в Telegram о принятой/обновлённой заявке
    try {
        $isNew = !$existingOrder;
        $deliveryDateFmt = (new DateTime($deliveryDate))->format('d.m.Y');

        // Название поставщика
        $sn = $pdo->prepare("SELECT short_name FROM suppliers WHERE id = ?");
        $sn->execute([$supplierId]);
        $supplierName = $sn->fetchColumn() ?: 'поставщику';

        $fmtQty = function($q) {
            $s = number_format((float)$q, 1, '.', '');
            return rtrim(rtrim($s, '0'), '.');
        };
        $esc = function($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        };

        // Подтягиваем единицы измерения из products по sku
        $skus = [];
        foreach ($items as $it) {
            $sk = trim((string)($it['sku'] ?? ''));
            if ($sk !== '' && floatval($it['quantity'] ?? 0) > 0) $skus[$sk] = true;
        }
        $unitBySku = [];
        if (!empty($skus)) {
            $skuList = array_keys($skus);
            $ph = implode(',', array_fill(0, count($skuList), '?'));
            $us = $pdo->prepare("SELECT sku, unit_of_measure FROM products WHERE sku IN ($ph)");
            $us->execute($skuList);
            foreach ($us->fetchAll() as $up) {
                $unitBySku[$up['sku']] = $up['unit_of_measure'] ?: '';
            }
        }

        $isSkip = $skipDelivery && $totalItems === 0;
        if ($isSkip) {
            $title = $isNew ? '🚫 <b>Поставка не нужна</b>' : '🚫 <b>Поставка отменена</b>';
        } else {
            $title = $isNew ? '✅ <b>Заявка отправлена</b>' : '✏️ <b>Заявка обновлена</b>';
        }
        $lines = [];
        $lines[] = $title;
        $lines[] = '';
        $lines[] = "🏪 <b>Поставщик:</b> " . $esc($supplierName);
        $lines[] = "📅 <b>Доставка:</b> {$deliveryDateFmt}";
        if (!$isSkip) {
            $lines[] = "📋 <b>Позиций:</b> {$totalItems}";
            $lines[] = '';
            $lines[] = '<b>Состав:</b>';
            foreach ($items as $it) {
                $q = floatval($it['quantity'] ?? 0);
                if ($q <= 0) continue;
                $sku = $esc($it['sku'] ?? '');
                $name = $esc($it['product_name'] ?? '');
                $unit = $esc($unitBySku[$it['sku'] ?? ''] ?? '');
                $unitStr = $unit !== '' ? " {$unit}" : '';
                $lines[] = "• <code>{$sku}</code> {$name} — <b>" . $fmtQty($q) . $unitStr . "</b>";
            }
        } else {
            $lines[] = '';
            $lines[] = '<i>Ресторан отметил, что поставка на эту дату не требуется.</i>';
        }

        $msg = implode("\n", $lines);
        if (mb_strlen($msg) > 3900) {
            $msg = mb_substr($msg, 0, 3900) . "\n\n…(сообщение обрезано)";
        }

        roNotifyRestaurant($pdo, $rest['restaurant_number'], $msg);
    } catch (Exception $e) {
        // Уведомление не критично — игнорируем ошибку
    }

    // Аудит
    try {
        $supNameForLog = $supplierName ?? null;
        $isSkipForLog = !empty($isSkip);
        $actionLog = $isSkipForLog
            ? 'so_order_skipped'
            : ($existingOrder ? 'so_order_updated' : 'so_order_submitted');
        auditLog($pdo, $actionLog, 'supplier_order', $orderId,
            'Ресторан ' . $rest['restaurant_number'],
            [
                'legal_entity' => $rest['legal_entity'] ?? '',
                'supplier_id' => (int)$supplierId,
                'supplier' => $supNameForLog,
                'delivery_date' => $deliveryDate,
                'items_count' => $totalItems,
                'total_qty' => $totalQty,
            ]
        );
    } catch (Exception $e) { /* не критично */ }

    soRespond([
        'success' => true,
        'order_id' => (int)$orderId,
        'total_items' => $totalItems,
        'total_qty' => $totalQty,
    ]);
}

// ═══════════════════════════════════════════════
// Маршруты для закупщиков (admin)
// ═══════════════════════════════════════════════

if ($soAction === 'admin') {
    $sessionUser = getSessionUser($pdo);
    if (!$sessionUser) {
        if (!checkApiKey($pdo)) soRespond(['error' => 'Unauthorized'], 401);
    }

    // RBAC: проверяем доступ к модулю supplier-orders
    if ($sessionUser) {
        global $ROLE_TEMPLATES, $ACCESS_LEVELS;
        $userRole = $sessionUser['role'] ?? 'user';
        if ($userRole !== 'admin') {
            $perms = resolvePermissions($userRole, $sessionUser['permissions'] ?? null, $ROLE_TEMPLATES);
            $soRequiredLevel = ($method === 'GET') ? $ACCESS_LEVELS['view'] : $ACCESS_LEVELS['edit'];
            $soUserLevel = $ACCESS_LEVELS[$perms['supplier-orders'] ?? 'none'] ?? 0;
            if ($soUserLevel < $soRequiredLevel) {
                soRespond(['error' => 'Недостаточно прав для модуля «Заявки поставщикам»'], 403);
            }
        }
    }

    $adminAction = $soParam1 ?? '';
    $adminParam = $soParam2 ?? null;
    $adminParam2 = $soParam3 ?? null;

    // --- Список поставщиков с активными расписаниями ---
    if ($adminAction === 'suppliers' && $method === 'GET') {
        $legalEntity = $_GET['legal_entity'] ?? null;
        $entityGroup = $legalEntity ? getEntityGroup($legalEntity) : 'BK_VM';
        $s = $pdo->prepare("
            SELECT s.id, s.short_name, s.full_name, s.legal_entity, s.legal_entity_group,
                   COUNT(DISTINCT ss.restaurant_id) as restaurant_count,
                   COALESCE(sst.is_accepting_orders, 1) as is_accepting_orders
            FROM suppliers s
            JOIN so_supplier_schedules ss ON ss.supplier_id = s.id AND ss.is_active = 1
            LEFT JOIN so_supplier_settings sst ON sst.supplier_id = s.id
            WHERE s.is_active = 1 AND s.legal_entity_group = ?
            GROUP BY s.id
            ORDER BY s.short_name
        ");
        $s->execute([$entityGroup]);
        soRespond(['suppliers' => $s->fetchAll()]);
    }

    // --- Настройки поставщика (GET) ---
    if ($adminAction === 'settings' && $method === 'GET') {
        $supplierId = $_GET['supplier_id'] ?? '';
        if (!$supplierId) soRespond(['error' => 'Не указан поставщик'], 400);
        $settings = soGetSupplierSettings($pdo, $supplierId);
        // Список разовых переопределений дедлайна
        $ov = $pdo->prepare("SELECT delivery_date, deadline_time, created_by, created_at FROM so_deadline_overrides WHERE supplier_id = ? AND delivery_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) ORDER BY delivery_date");
        $ov->execute([$supplierId]);
        soRespond([
            'settings' => $settings,
            'overrides' => $ov->fetchAll(),
        ]);
    }

    // --- Обновить настройки поставщика (POST) ---
    if ($adminAction === 'settings' && $method === 'POST') {
        $supplierId = $body['supplier_id'] ?? '';
        if (!$supplierId) soRespond(['error' => 'Не указан поставщик'], 400);
        $updatedBy = $sessionUser ? ($sessionUser['name'] ?? 'admin') : 'admin';

        $isAccepting = isset($body['is_accepting_orders']) ? ((int)!!$body['is_accepting_orders']) : 1;
        $defaultDl = $body['default_deadline_time'] ?? '14:00:00';
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $defaultDl, $m)) {
            $defaultDl = sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
        } elseif (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $defaultDl)) {
            $defaultDl = '14:00:00';
        }
        $pauseMsg = $body['pause_message'] ?? null;

        $pdo->prepare("INSERT INTO so_supplier_settings (supplier_id, is_accepting_orders, default_deadline_time, pause_message, updated_by)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              is_accepting_orders = VALUES(is_accepting_orders),
              default_deadline_time = VALUES(default_deadline_time),
              pause_message = VALUES(pause_message),
              updated_by = VALUES(updated_by)")
            ->execute([$supplierId, $isAccepting, $defaultDl, $pauseMsg, $updatedBy]);

        soRespond(['success' => true, 'settings' => soGetSupplierSettings($pdo, $supplierId)]);
    }

    // --- Сводка заявок по поставщику + дате ---
    if ($adminAction === 'status' && $method === 'GET') {
        $supplierId = $_GET['supplier_id'] ?? '';
        $date = $_GET['date'] ?? '';

        if (!$supplierId) soRespond(['error' => 'Не указан поставщик'], 400);

        $settings = soGetSupplierSettings($pdo, $supplierId);

        // Если дата не указана: открываем поставку, соответствующую сегодняшнему
        // дню подачи заявок. Если сегодня не день заказа — берём ближайший следующий
        // день заказа и его поставку.
        if (!$date) {
            $tz = new DateTimeZone('Europe/Minsk');
            $now = new DateTime('now', $tz);
            $todayDow = (int)$now->format('N');
            $s = $pdo->prepare("SELECT DISTINCT order_day, delivery_day FROM so_supplier_schedules WHERE supplier_id = ? AND is_active = 1 ORDER BY order_day, delivery_day");
            $s->execute([$supplierId]);
            $pairs = $s->fetchAll();

            $targetOrderDow = null;
            $targetDeliveryDow = null;
            // Сначала ищем пары с order_day >= сегодня
            foreach ($pairs as $p) {
                if ((int)$p['order_day'] >= $todayDow) {
                    $targetOrderDow = (int)$p['order_day'];
                    $targetDeliveryDow = (int)$p['delivery_day'];
                    break;
                }
            }
            // Если всё позади — берём первую пару (следующая неделя)
            if ($targetOrderDow === null && !empty($pairs)) {
                $targetOrderDow = (int)$pairs[0]['order_day'];
                $targetDeliveryDow = (int)$pairs[0]['delivery_day'];
            }

            if ($targetOrderDow !== null) {
                // сколько дней до дня заказа
                $orderDiff = $targetOrderDow - $todayDow;
                if ($orderDiff < 0) $orderDiff += 7;
                // сколько дней от дня заказа до дня поставки (0..6)
                $delivDiff = $targetDeliveryDow - $targetOrderDow;
                if ($delivDiff < 0) $delivDiff += 7;
                $date = (clone $now)->modify('+' . ($orderDiff + $delivDiff) . ' days')->format('Y-m-d');
            } else {
                $date = date('Y-m-d');
            }
        }

        $deliveryDow = (int)(new DateTime($date))->format('N');

        // Все рестораны, у которых поставка в этот день (без session_id)
        $rests = $pdo->prepare("
            SELECT r.number, r.region, r.city, r.address,
                   ss.order_day,
                   o.id as order_id, o.status as order_status, o.submitted_at,
                   (SELECT COUNT(*) FROM so_order_items WHERE order_id = o.id) as item_count,
                   (SELECT SUM(quantity) FROM so_order_items WHERE order_id = o.id) as total_qty
            FROM so_supplier_schedules ss
            JOIN restaurants r ON r.id = ss.restaurant_id AND r.active = 1
            LEFT JOIN so_orders o ON o.restaurant_number = r.number AND o.supplier_id = ? AND o.delivery_date = ?
            WHERE ss.supplier_id = ? AND ss.delivery_day = ? AND ss.is_active = 1
            ORDER BY r.region, r.number
        ");
        $rests->execute([$supplierId, $date, $supplierId, $deliveryDow]);
        $restaurants = $rests->fetchAll();

        $total = count($restaurants);
        $submitted = 0;
        foreach ($restaurants as $r) {
            if ($r['order_status'] === 'submitted' || $r['order_status'] === 'locked') $submitted++;
        }

        // Навигационные даты: ближайшие 2 цикла поставок по графику от сегодня
        $weekDates = [];
        $tz = new DateTimeZone('Europe/Minsk');
        $today = (new DateTime('now', $tz))->setTime(0, 0, 0);
        $weekStart = (clone $today)->modify('-' . ((int)$today->format('N') - 1) . ' days');
        $schStmt = $pdo->prepare("SELECT DISTINCT delivery_day FROM so_supplier_schedules WHERE supplier_id = ? AND is_active = 1 ORDER BY delivery_day");
        $schStmt->execute([$supplierId]);
        $deliveryDaysAll = array_column($schStmt->fetchAll(), 'delivery_day');
        $seenWD = [];
        foreach ([0, 1] as $w) {
            foreach ($deliveryDaysAll as $dd) {
                $dd = (int)$dd;
                $dObj = (clone $weekStart)->modify('+' . ($dd - 1 + $w * 7) . ' days');
                if ($dObj < $today) continue;
                $dateStr = $dObj->format('Y-m-d');
                if (isset($seenWD[$dateStr])) continue;
                $seenWD[$dateStr] = true;
                $weekDates[] = [
                    'date' => $dateStr,
                    'day_name' => $dayNames[$dd] ?? '',
                    'day_name_full' => $dayNamesFull[$dd] ?? '',
                ];
            }
        }
        usort($weekDates, fn($a, $b) => strcmp($a['date'], $b['date']));

        // Товары из шаблонов (все юрлица)
        $tplStmt = $pdo->prepare("
            SELECT DISTINCT t.sku, t.product_name, t.sort_order, t.multiplicity, t.product_id
            FROM so_templates t
            WHERE t.supplier_id = ? AND t.is_active = 1
            ORDER BY t.sort_order, t.product_name
        ");
        $tplStmt->execute([$supplierId]);
        $products = $tplStmt->fetchAll();

        // Все позиции заявок для этой даты
        $itemsStmt = $pdo->prepare("
            SELECT o.restaurant_number, o.delivery_date,
                   oi.sku, oi.product_name, oi.quantity, oi.admin_qty, oi.id as item_id, o.id as order_id
            FROM so_orders o
            JOIN so_order_items oi ON oi.order_id = o.id
            WHERE o.supplier_id = ? AND o.delivery_date = ?
        ");
        $itemsStmt->execute([$supplierId, $date]);
        $orderItems = $itemsStmt->fetchAll();

        // Дедлайн для этой даты
        $deadlineInfo = soCheckDeadline($pdo, $supplierId, $date);

        soRespond([
            'settings' => $settings,
            'date' => $date,
            'deadline' => $deadlineInfo['deadline'],
            'deadline_status' => $deadlineInfo['status'],
            'restaurants' => $restaurants,
            'products' => $products,
            'order_items' => $orderItems,
            'stats' => ['total' => $total, 'submitted' => $submitted, 'pending' => $total - $submitted],
            'week_dates' => $weekDates,
        ]);
    }

    // --- Список заявок по дням ---
    if ($adminAction === 'orders' && $method === 'GET') {
        $supplierId = $_GET['supplier_id'] ?? '';
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
        $dateTo = $_GET['date_to'] ?? date('Y-m-d', strtotime('+7 days'));

        $where = "1=1";
        $params = [];
        if ($supplierId) {
            $where .= " AND o.supplier_id = ?";
            $params[] = $supplierId;
        }
        $where .= " AND o.delivery_date BETWEEN ? AND ?";
        $params[] = $dateFrom;
        $params[] = $dateTo;

        $s = $pdo->prepare("
            SELECT o.id, o.delivery_date, o.order_date, o.restaurant_number, o.status, o.submitted_at, o.supplier_id,
                   s.short_name as supplier_name,
                   r.region, r.city, r.address,
                   (SELECT COUNT(*) FROM so_order_items WHERE order_id = o.id) as item_count,
                   (SELECT SUM(quantity) FROM so_order_items WHERE order_id = o.id) as total_qty
            FROM so_orders o
            JOIN suppliers s ON s.id = o.supplier_id
            LEFT JOIN restaurants r ON r.number = o.restaurant_number AND r.active = 1
            WHERE {$where}
            ORDER BY o.delivery_date, o.restaurant_number
        ");
        $s->execute($params);
        soRespond(['orders' => $s->fetchAll()]);
    }

    // --- Детали заявки ---
    if ($adminAction === 'order' && $method === 'GET' && $adminParam) {
        $s = $pdo->prepare("
            SELECT o.*, s.short_name as supplier_name, r.region, r.city, r.address
            FROM so_orders o
            JOIN suppliers s ON s.id = o.supplier_id
            LEFT JOIN restaurants r ON r.number = o.restaurant_number AND r.active = 1
            WHERE o.id = ?
        ");
        $s->execute([$adminParam]);
        $order = $s->fetch();
        if (!$order) soRespond(['error' => 'Заявка не найдена'], 404);
        // Проверка доступа к юр. лицу заявки
        if ($sessionUser && !checkLegalEntityAccess($sessionUser, $order['legal_entity'] ?? '')) {
            soRespond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        }

        $items = $pdo->prepare("SELECT * FROM so_order_items WHERE order_id = ? ORDER BY product_name");
        $items->execute([$order['id']]);
        $order['items'] = $items->fetchAll();

        soRespond(['order' => $order]);
    }

    // --- Редактирование заявки ---
    if ($adminAction === 'order' && $method === 'PATCH' && $adminParam) {
        $orderId = (int)$adminParam;
        $items = $body['items'] ?? null;
        $status = $body['status'] ?? null;

        // Проверка доступа к юр. лицу заявки
        if ($sessionUser) {
            $leSt = $pdo->prepare("SELECT legal_entity FROM so_orders WHERE id = ?");
            $leSt->execute([$orderId]);
            $orderLE = $leSt->fetchColumn();
            if ($orderLE === false) soRespond(['error' => 'Заявка не найдена'], 404);
            if (!checkLegalEntityAccess($sessionUser, $orderLE ?: '')) {
                soRespond(['error' => 'Нет доступа к данному юр. лицу'], 403);
            }
        }

        if ($items !== null) {
            // Агрегируем позиции по SKU на случай дублей в payload
            $aggregated = [];
            foreach ($items as $item) {
                $qty = floatval($item['quantity'] ?? 0);
                if ($qty <= 0) continue;
                $sku = $item['sku'] ?? '';
                if ($sku === '') continue;
                if (!isset($aggregated[$sku])) {
                    $aggregated[$sku] = [
                        'product_id' => $item['product_id'] ?? '',
                        'sku' => $sku,
                        'product_name' => $item['product_name'] ?? '',
                        'quantity' => 0,
                    ];
                }
                $aggregated[$sku]['quantity'] += $qty;
            }
            $pdo->prepare("DELETE FROM so_order_items WHERE order_id = ?")->execute([$orderId]);
            $insert = $pdo->prepare("INSERT INTO so_order_items (order_id, product_id, sku, product_name, quantity) VALUES (?, ?, ?, ?, ?)");
            foreach ($aggregated as $ag) {
                $insert->execute([$orderId, $ag['product_id'], $ag['sku'], $ag['product_name'], $ag['quantity']]);
            }
        }

        $updatedBy = $sessionUser ? $sessionUser['name'] : 'admin';
        if ($status) {
            $pdo->prepare("UPDATE so_orders SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$status, $orderId]);
        } else {
            $pdo->prepare("UPDATE so_orders SET updated_at = NOW() WHERE id = ?")->execute([$orderId]);
        }

        // Уведомляем ресторан в Telegram
        $oi = $pdo->prepare("SELECT o.restaurant_number, o.delivery_date, s.short_name as supplier_name FROM so_orders o JOIN suppliers s ON s.id = o.supplier_id WHERE o.id = ?");
        $oi->execute([$orderId]);
        $orderInfo = $oi->fetch();
        if ($orderInfo) {
            $dowNames = [0=>'Вс',1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб'];
            $dow = (int)date('w', strtotime($orderInfo['delivery_date']));
            $dateStr = ($dowNames[$dow] ?? '') . ', ' . date('d.m', strtotime($orderInfo['delivery_date']));
            roNotifyRestaurant($pdo, $orderInfo['restaurant_number'],
                "✏️ Рест. {$orderInfo['restaurant_number']} — заявка {$orderInfo['supplier_name']} на {$dateStr} изменена ({$updatedBy}).");
        }

        // Аудит
        try {
            $leSt2 = $pdo->prepare("SELECT legal_entity FROM so_orders WHERE id = ?");
            $leSt2->execute([$orderId]);
            $leForLog = $leSt2->fetchColumn() ?: '';
            auditLog($pdo, 'so_order_edited', 'supplier_order', $orderId, $updatedBy, [
                'legal_entity' => $leForLog,
                'supplier' => $orderInfo['supplier_name'] ?? null,
                'delivery_date' => $orderInfo['delivery_date'] ?? null,
                'restaurant_number' => $orderInfo['restaurant_number'] ?? null,
                'items_updated' => $items !== null,
                'status' => $status ?? null,
            ]);
        } catch (Exception $e) { /* не критично */ }

        soRespond(['success' => true]);
    }

    // --- Обновление admin_qty для позиции ---
    if ($adminAction === 'update-qty' && $method === 'POST') {
        $itemId = $body['item_id'] ?? null;
        $adminQty = $body['admin_qty'] ?? null;
        // Альтернативный путь: создать запись если нет заказа (админ заполняет за ресторан)
        $restNum = $body['restaurant_number'] ?? null;
        $deliveryDate = $body['delivery_date'] ?? null;
        $sku = $body['sku'] ?? null;
        $productName = $body['product_name'] ?? null;
        $suppId = $body['supplier_id'] ?? null;

        $val = ($adminQty !== null && $adminQty !== '') ? (float)$adminQty : null;

        // Данные для уведомления
        $notify = null;

        if ($itemId) {
            // Получаем текущее состояние ДО обновления
            $cur = $pdo->prepare("
                SELECT oi.order_id, oi.product_name, oi.sku, oi.quantity, oi.admin_qty,
                       o.restaurant_number, o.delivery_date, s.short_name as supplier_name
                FROM so_order_items oi
                JOIN so_orders o ON o.id = oi.order_id
                JOIN suppliers s ON s.id = o.supplier_id
                WHERE oi.id = ?
            ");
            $cur->execute([$itemId]);
            $info = $cur->fetch();
            if ($info) {
                $oldVal = ($info['admin_qty'] !== null) ? (float)$info['admin_qty'] : (float)$info['quantity'];
                $orderId = (int)$info['order_id'];
                $notify = [
                    'restaurant_number' => $info['restaurant_number'],
                    'supplier_name' => $info['supplier_name'],
                    'delivery_date' => $info['delivery_date'],
                    'sku' => $info['sku'],
                    'product_name' => $info['product_name'],
                    'old_val' => $oldVal,
                    'new_val' => $val,
                ];
            }
            $pdo->prepare("UPDATE so_order_items SET admin_qty = ? WHERE id = ?")->execute([$val, $itemId]);
            $reload = false;
        } elseif ($restNum && $deliveryDate && $sku && $suppId) {
            // Ищем заказ ресторана
            $orderStmt = $pdo->prepare("SELECT id FROM so_orders WHERE supplier_id = ? AND restaurant_number = ? AND delivery_date = ?");
            $orderStmt->execute([$suppId, $restNum, $deliveryDate]);
            $order = $orderStmt->fetch();

            if (!$order) {
                // Создаём заказ за ресторан
                $le = $body['legal_entity'] ?? roGetLegalEntity($pdo, $restNum);
                $pdo->prepare("INSERT INTO so_orders (restaurant_number, supplier_id, delivery_date, order_date, status, submitted_at, legal_entity)
                    VALUES (?, ?, ?, CURDATE(), 'submitted', NOW(), ?)")
                    ->execute([$restNum, $suppId, $deliveryDate, $le]);
                $orderId = $pdo->lastInsertId();
            } else {
                $orderId = $order['id'];
            }

            // Ищем позицию по SKU
            $existingItem = $pdo->prepare("SELECT id, quantity, admin_qty, product_name FROM so_order_items WHERE order_id = ? AND sku = ?");
            $existingItem->execute([$orderId, $sku]);
            $item = $existingItem->fetch();

            if ($item) {
                $oldVal = ($item['admin_qty'] !== null) ? (float)$item['admin_qty'] : (float)$item['quantity'];
                $pdo->prepare("UPDATE so_order_items SET admin_qty = ? WHERE id = ?")->execute([$val, $item['id']]);
            } else {
                $oldVal = 0;
                // Создаём новую позицию (админ добавил количество для товара, которого не было в заказе)
                $pdo->prepare("INSERT INTO so_order_items (order_id, product_id, sku, product_name, quantity, admin_qty) VALUES (?, ?, ?, ?, 0, ?)")
                    ->execute([$orderId, $body['product_id'] ?? '', $sku, $productName ?? '', $val]);
            }

            // Подтянем название поставщика для уведомления
            $sn = $pdo->prepare("SELECT short_name FROM suppliers WHERE id = ?");
            $sn->execute([$suppId]);
            $supplierName = $sn->fetchColumn() ?: 'поставщику';

            $notify = [
                'restaurant_number' => $restNum,
                'supplier_name' => $supplierName,
                'delivery_date' => $deliveryDate,
                'sku' => $sku,
                'product_name' => $item ? $item['product_name'] : ($productName ?? ''),
                'old_val' => $oldVal,
                'new_val' => $val,
            ];

            $reload = true;
        } else {
            soRespond(['error' => 'Недостаточно данных'], 400);
        }

        // Уведомление в Telegram о ручной правке закупщиком
        if ($notify) {
            try {
                // Единица измерения товара
                $us = $pdo->prepare("SELECT unit_of_measure FROM products WHERE sku = ? LIMIT 1");
                $us->execute([$notify['sku']]);
                $unit = $us->fetchColumn() ?: '';
                $unitStr = $unit ? ' ' . $unit : '';

                $fmt = function($v) {
                    if ($v === null) return null;
                    $s = number_format((float)$v, 1, '.', '');
                    return rtrim(rtrim($s, '0'), '.');
                };
                $esc = function($s) {
                    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                };

                $by = $sessionUser ? ($sessionUser['name'] ?? 'закупщик') : 'закупщик';
                $deliveryFmt = (new DateTime($notify['delivery_date']))->format('d.m.Y');
                $oldStr = $notify['old_val'] > 0 ? ($fmt($notify['old_val']) . $unitStr) : '—';
                $newStr = $notify['new_val'] !== null && $notify['new_val'] > 0 ? ($fmt($notify['new_val']) . $unitStr) : '—';

                $lines = [];
                $lines[] = '✏️ <b>Закупщик изменил заявку</b>';
                $lines[] = '';
                $lines[] = '🏪 <b>Поставщик:</b> ' . $esc($notify['supplier_name']);
                $lines[] = '📅 <b>Доставка:</b> ' . $deliveryFmt;
                $lines[] = '';
                $lines[] = '<code>' . $esc($notify['sku']) . '</code> ' . $esc($notify['product_name']);
                $lines[] = $oldStr . ' → <b>' . $newStr . '</b>';
                $lines[] = '';
                $lines[] = '<i>Изменил: ' . $esc($by) . '</i>';

                $msg = implode("\n", $lines);
                roNotifyRestaurant($pdo, $notify['restaurant_number'], $msg);
            } catch (Exception $e) {
                // Уведомление не критично
            }
        }

        // Аудит ручной правки количества
        if ($notify) {
            try {
                $byName = $sessionUser ? ($sessionUser['name'] ?? 'закупщик') : 'закупщик';
                auditLog($pdo, 'so_qty_adjusted', 'supplier_order', $orderId ?? null, $byName, [
                    'supplier' => $notify['supplier_name'] ?? null,
                    'restaurant_number' => $notify['restaurant_number'] ?? null,
                    'delivery_date' => $notify['delivery_date'] ?? null,
                    'sku' => $notify['sku'] ?? null,
                    'product_name' => $notify['product_name'] ?? null,
                    'old_val' => $notify['old_val'] ?? null,
                    'new_val' => $notify['new_val'] ?? null,
                ]);
            } catch (Exception $e) { /* не критично */ }
        }

        soRespond(['success' => true, 'reload' => !empty($reload)]);
    }

    // --- Удаление заявки ---
    if ($adminAction === 'order' && $method === 'DELETE' && $adminParam) {
        $orderId = (int)$adminParam;
        // Сохраняем инфо до удаления
        $oi = $pdo->prepare("SELECT o.restaurant_number, o.delivery_date, o.legal_entity, s.short_name as supplier_name FROM so_orders o JOIN suppliers s ON s.id = o.supplier_id WHERE o.id = ?");
        $oi->execute([$orderId]);
        $orderInfo = $oi->fetch();
        if (!$orderInfo) soRespond(['error' => 'Заявка не найдена'], 404);
        // Проверка доступа к юр. лицу заявки
        if ($sessionUser && !checkLegalEntityAccess($sessionUser, $orderInfo['legal_entity'] ?? '')) {
            soRespond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        }

        $pdo->prepare("DELETE FROM so_order_items WHERE order_id = ?")->execute([$orderId]);
        $pdo->prepare("DELETE FROM so_orders WHERE id = ?")->execute([$orderId]);

        $by = $sessionUser ? $sessionUser['name'] : 'admin';

        // Уведомляем ресторан
        if ($orderInfo) {
            $dowNames = [0=>'Вс',1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб'];
            $dow = (int)date('w', strtotime($orderInfo['delivery_date']));
            $dateStr = ($dowNames[$dow] ?? '') . ', ' . date('d.m', strtotime($orderInfo['delivery_date']));
            roNotifyRestaurant($pdo, $orderInfo['restaurant_number'],
                "❌ Рест. {$orderInfo['restaurant_number']} — заявка {$orderInfo['supplier_name']} на {$dateStr} удалена ({$by}).");
        }

        // Аудит
        try {
            auditLog($pdo, 'so_order_deleted', 'supplier_order', $orderId, $by, [
                'legal_entity' => $orderInfo['legal_entity'] ?? '',
                'supplier' => $orderInfo['supplier_name'] ?? null,
                'delivery_date' => $orderInfo['delivery_date'] ?? null,
                'restaurant_number' => $orderInfo['restaurant_number'] ?? null,
            ]);
        } catch (Exception $e) { /* не критично */ }

        soRespond(['success' => true]);
    }

    // Устаревшие маршруты управления сессиями удалены.
    // Постоянный режим: см. so/admin/settings (вкл/выкл приёма заявок).

    // --- Графики поставок ---
    if ($adminAction === 'schedules' && $method === 'GET') {
        $supplierId = $_GET['supplier_id'] ?? '';
        if (!$supplierId) soRespond(['error' => 'Не указан поставщик'], 400);

        $s = $pdo->prepare("
            SELECT ss.id, ss.order_day, ss.delivery_day, ss.is_active,
                   r.number as restaurant_number, r.region, r.city, r.address
            FROM so_supplier_schedules ss
            JOIN restaurants r ON r.id = ss.restaurant_id AND r.active = 1
            WHERE ss.supplier_id = ?
            ORDER BY r.region, CAST(r.number AS UNSIGNED), ss.order_day
        ");
        $s->execute([$supplierId]);
        $schedules = $s->fetchAll();
        // Также подгружаем правила дедлайнов
        $dr = $pdo->prepare("SELECT delivery_dow, deadline_dow, deadline_time FROM so_deadline_rules WHERE supplier_id = ? ORDER BY delivery_dow");
        $dr->execute([$supplierId]);
        soRespond(['schedules' => $schedules, 'deadline_rules' => $dr->fetchAll()]);
    }

    // --- Сохранение графиков ---
    if ($adminAction === 'schedules' && $method === 'POST') {
        $supplierId = $body['supplier_id'] ?? '';
        $schedules = $body['schedules'] ?? [];

        if (!$supplierId) soRespond(['error' => 'Не указан поставщик'], 400);

        $updatedBy = $sessionUser ? $sessionUser['name'] : 'admin';

        // Сначала деактивируем все расписания поставщика, потом активируем присланные
        $pdo->prepare("UPDATE so_supplier_schedules SET is_active = 0, updated_at = NOW(), updated_by = ? WHERE supplier_id = ?")->execute([$updatedBy, $supplierId]);

        // Upsert
        $upsert = $pdo->prepare("
            INSERT INTO so_supplier_schedules (supplier_id, restaurant_id, order_day, delivery_day, is_active, updated_at, updated_by)
            VALUES (?, ?, ?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE delivery_day = VALUES(delivery_day), is_active = VALUES(is_active), updated_at = NOW(), updated_by = VALUES(updated_by)
        ");

        $count = 0;
        foreach ($schedules as $sch) {
            $restId = $sch['restaurant_id'] ?? null;
            if (!$restId) continue;
            $upsert->execute([
                $supplierId,
                $restId,
                (int)($sch['order_day'] ?? 1),
                (int)($sch['delivery_day'] ?? 2),
                (int)($sch['is_active'] ?? 1),
                $updatedBy,
            ]);
            $count++;
        }

        soRespond(['success' => true, 'updated' => $count]);
    }

    // --- Правила дедлайнов ---
    if ($adminAction === 'deadline-rules' && $method === 'GET') {
        $supplierId = $_GET['supplier_id'] ?? '';
        if (!$supplierId) soRespond(['error' => 'Не указан поставщик'], 400);
        $s = $pdo->prepare("SELECT id, delivery_dow, deadline_dow, deadline_time FROM so_deadline_rules WHERE supplier_id = ? ORDER BY delivery_dow");
        $s->execute([$supplierId]);
        soRespond(['rules' => $s->fetchAll()]);
    }

    if ($adminAction === 'deadline-rules' && $method === 'POST') {
        $supplierId = $body['supplier_id'] ?? '';
        $rules = $body['rules'] ?? [];
        if (!$supplierId) soRespond(['error' => 'Не указан поставщик'], 400);
        // Очищаем и перезаписываем
        $pdo->prepare("DELETE FROM so_deadline_rules WHERE supplier_id = ?")->execute([$supplierId]);
        $ins = $pdo->prepare("INSERT INTO so_deadline_rules (supplier_id, delivery_dow, deadline_dow, deadline_time) VALUES (?, ?, ?, ?)");
        foreach ($rules as $r) {
            $ins->execute([$supplierId, (int)$r['delivery_dow'], (int)$r['deadline_dow'], $r['deadline_time'] ?? '14:00:00']);
        }
        soRespond(['success' => true, 'count' => count($rules)]);
    }

    // --- Разовое продление дедлайна на конкретную дату доставки ---
    if ($adminAction === 'extend-deadline' && $method === 'POST') {
        $supplierId = $body['supplier_id'] ?? null;
        $deliveryDate = $body['delivery_date'] ?? '';
        $deadlineTime = $body['deadline_time'] ?? '';

        if (!$supplierId || !$deliveryDate || !$deadlineTime) {
            soRespond(['error' => 'Не указаны поставщик, дата доставки или новое время'], 400);
        }
        // Нормализуем время к формату HH:MM:SS
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $deadlineTime, $m)) {
            $deadlineTime = sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
        } elseif (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $deadlineTime)) {
            soRespond(['error' => 'Неверный формат времени, используйте HH:MM'], 400);
        }

        $createdBy = $sessionUser ? ($sessionUser['name'] ?? 'admin') : 'admin';
        $pdo->prepare("INSERT INTO so_deadline_overrides (supplier_id, delivery_date, deadline_time, created_by) VALUES (?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE deadline_time = VALUES(deadline_time), created_by = VALUES(created_by)")
            ->execute([$supplierId, $deliveryDate, $deadlineTime, $createdBy]);

        soRespond(['success' => true, 'supplier_id' => $supplierId, 'delivery_date' => $deliveryDate, 'deadline_time' => $deadlineTime]);
    }

    // --- Удалить разовое продление дедлайна ---
    if ($adminAction === 'remove-deadline-override' && $method === 'POST') {
        $supplierId = $body['supplier_id'] ?? null;
        $deliveryDate = $body['delivery_date'] ?? '';
        if (!$supplierId || !$deliveryDate) soRespond(['error' => 'Не указан поставщик или дата'], 400);
        $pdo->prepare("DELETE FROM so_deadline_overrides WHERE supplier_id = ? AND delivery_date = ?")
            ->execute([$supplierId, $deliveryDate]);
        soRespond(['success' => true]);
    }

    // --- Шаблоны товаров ---
    if ($adminAction === 'templates' && $method === 'GET') {
        $supplierId = $_GET['supplier_id'] ?? '';
        $le = $_GET['legal_entity'] ?? 'ООО "Бургер БК"';
        if (!$supplierId) soRespond(['error' => 'Не указан поставщик'], 400);

        $s = $pdo->prepare("
            SELECT t.*, p.name as original_name, p.qty_per_box
            FROM so_templates t
            LEFT JOIN products p ON p.id = t.product_id
            WHERE t.supplier_id = ? AND t.legal_entity = ?
            ORDER BY t.sort_order, t.product_name
        ");
        $s->execute([$supplierId, $le]);
        soRespond(['templates' => $s->fetchAll()]);
    }

    // --- Сохранение шаблона ---
    if ($adminAction === 'templates' && $method === 'POST') {
        $supplierId = $body['supplier_id'] ?? '';
        $le = $body['legal_entity'] ?? 'ООО "Бургер БК"';
        $items = $body['items'] ?? [];

        if (!$supplierId) soRespond(['error' => 'Не указан поставщик'], 400);

        // Деактивируем все текущие
        $pdo->prepare("UPDATE so_templates SET is_active = 0 WHERE supplier_id = ? AND legal_entity = ?")
            ->execute([$supplierId, $le]);

        $upsert = $pdo->prepare("
            INSERT INTO so_templates (supplier_id, legal_entity, product_id, sku, product_name, sort_order, multiplicity, min_qty, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE product_name = VALUES(product_name), sort_order = VALUES(sort_order),
              multiplicity = VALUES(multiplicity), min_qty = VALUES(min_qty), is_active = 1, product_id = VALUES(product_id)
        ");

        $count = 0;
        foreach ($items as $i => $item) {
            $mult = isset($item['multiplicity']) && $item['multiplicity'] !== '' ? (float)$item['multiplicity'] : null;
            $minQty = isset($item['min_qty']) && $item['min_qty'] !== '' ? (float)$item['min_qty'] : null;
            $upsert->execute([
                $supplierId,
                $le,
                $item['product_id'] ?? null,
                $item['sku'] ?? '',
                $item['product_name'] ?? '',
                $item['sort_order'] ?? ($i * 10),
                $mult,
                $minQty,
            ]);
            $count++;
        }

        soRespond(['success' => true, 'count' => $count]);
    }

    // --- Экспорт ---
    if ($adminAction === 'export' && $method === 'GET') {
        $supplierId = $_GET['supplier_id'] ?? '';
        $date = $_GET['date'] ?? '';

        if (!$supplierId || !$date) soRespond(['error' => 'Не указан поставщик или дата'], 400);

        // Все заявки на эту дату (без session_id)
        $s = $pdo->prepare("
            SELECT o.restaurant_number, o.status, o.submitted_at,
                   r.region, r.address,
                   oi.sku, oi.product_name, oi.quantity, oi.admin_qty,
                   COALESCE(oi.admin_qty, oi.quantity) as effective_qty
            FROM so_orders o
            JOIN restaurants r ON r.number = o.restaurant_number AND r.active = 1
            JOIN so_order_items oi ON oi.order_id = o.id
            WHERE o.supplier_id = ? AND o.delivery_date = ?
            ORDER BY r.region, r.number, oi.product_name
        ");
        $s->execute([$supplierId, $date]);
        $rows = $s->fetchAll();

        // Сводка по товарам (используем admin_qty если есть)
        $summary = [];
        foreach ($rows as $row) {
            $key = $row['sku'];
            if (!isset($summary[$key])) {
                $summary[$key] = ['sku' => $row['sku'], 'product_name' => $row['product_name'], 'total_qty' => 0, 'restaurant_count' => 0];
            }
            $summary[$key]['total_qty'] += (float)$row['effective_qty'];
            $summary[$key]['restaurant_count']++;
        }

        soRespond([
            'orders' => $rows,
            'summary' => array_values($summary),
            'date' => $date,
        ]);
    }
}
