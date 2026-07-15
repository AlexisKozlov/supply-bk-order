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
 * Маршруты для отдела закупок (сессия основного приложения):
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
 *   POST   so/admin/send-summary  — ручная отправка сводки подписчикам в Telegram
 */

require_once __DIR__ . '/so_deadline.php';

if ($endpoint !== 'so') return;

// ═══ Хелперы ═══

function soRespond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function soGetRestaurantSession($pdo) {
    // Сессии живут в ro_user_sessions (см. helpers.php roReadActiveSessionRow).
    $user = roReadActiveSessionRow($pdo);
    if (!$user) return null;
    $rest = roGetRestaurantRow($pdo, $user['restaurant_number'], $user['legal_entity_group'] ?? null);
    $user['restaurant_id'] = isset($rest['id']) ? (int)$rest['id'] : null;
    $user['region'] = $rest['region'] ?? '';
    $user['city'] = $rest['city'] ?? '';
    $user['address'] = $rest['address'] ?? '';
    if (empty($user['legal_entity_group']) && !empty($rest['legal_entity_group'])) {
        $user['legal_entity_group'] = $rest['legal_entity_group'];
    }
    return $user;
}

// Настройки поставщика: есть строка в so_supplier_settings или дефолты
function soGetSupplierSettings($pdo, $supplierId) {
    $s = $pdo->prepare("SELECT supplier_id, is_accepting_orders, auto_submit_previous, default_deadline_time, pause_message FROM so_supplier_settings WHERE supplier_id = ?");
    $s->execute([$supplierId]);
    $row = $s->fetch();
    if ($row) return $row;
    return [
        'supplier_id' => $supplierId,
        'is_accepting_orders' => 1,
        'auto_submit_previous' => 0,
        'default_deadline_time' => '14:00:00',
        'pause_message' => null,
    ];
}

/**
 * Собирает сводку заявок поставщика за день и готовый xlsx-бинарник.
 * Общая логика для Telegram-сводки, ручной email-отправки и крона.
 */
function soBuildSummaryXlsx(PDO $pdo, string $supplierId, string $deliveryDate): array {
    $out = [
        'status' => 'ok', 'supplier' => null, 'xlsx' => null, 'filename' => '',
        'date_fmt' => '', 'restaurants_count' => 0, 'submitted_count' => 0,
        'items_count' => 0, 'error' => null,
    ];

    $supRow = $pdo->prepare("SELECT short_name, legal_entity, legal_entity_group FROM suppliers WHERE id = ?");
    $supRow->execute([$supplierId]);
    $sup = $supRow->fetch();
    if (!$sup) { $out['status'] = 'no_schedule'; return $out; }
    $out['supplier'] = $sup;
    $supName = $sup['short_name'];
    $supplierGroup = $sup['legal_entity_group'] ?: getEntityGroup($sup['legal_entity'] ?? '');
    $supplierEntities = getEntitiesInGroup($supplierGroup);
    $entityPh = implode(',', array_fill(0, count($supplierEntities), '?'));

    $deadlineState = soCalculateDeadline($pdo, $supplierId, $deliveryDate);
    if (!empty($deadlineState['forced_closed'])) { $out['status'] = 'closed'; return $out; }

    $expectedRests = array_values(array_filter(
        soGetEffectiveScheduleRows($pdo, $supplierId, $deliveryDate, null, true),
        fn($row) => soDeliveryDateMatchesDow($deliveryDate, (int)$row['delivery_day'])
            && (($row['legal_entity_group'] ?? '') === $supplierGroup)
    ));
    usort($expectedRests, function ($a, $b) {
        $regionCmp = strcmp((string)($a['region'] ?? ''), (string)($b['region'] ?? ''));
        if ($regionCmp !== 0) return $regionCmp;
        return (int)($a['restaurant_number'] ?? 0) <=> (int)($b['restaurant_number'] ?? 0);
    });
    if (!$expectedRests) { $out['status'] = 'no_schedule'; return $out; }

    $expectedNums = array_values(array_unique(array_map('strval', array_column($expectedRests, 'restaurant_number'))));
    $expectedPh = implode(',', array_fill(0, count($expectedNums), '?'));

    $subStmt = $pdo->prepare("
        SELECT restaurant_number FROM so_orders
        WHERE supplier_id = ? AND delivery_date = ? AND status != 'draft'
          AND legal_entity IN ({$entityPh}) AND restaurant_number IN ({$expectedPh})");
    $subStmt->execute(array_merge([$supplierId, $deliveryDate], $supplierEntities, $expectedNums));
    $submittedNums = array_flip($subStmt->fetchAll(PDO::FETCH_COLUMN));

    $ordStmt = $pdo->prepare("
        SELECT o.restaurant_number, oi.sku, oi.product_name,
               COALESCE(oi.admin_qty, oi.quantity) AS qty
        FROM so_orders o JOIN so_order_items oi ON oi.order_id = o.id
        WHERE o.supplier_id = ? AND o.delivery_date = ? AND o.status != 'draft'
          AND o.legal_entity IN ({$entityPh}) AND o.restaurant_number IN ({$expectedPh})
          AND COALESCE(oi.admin_qty, oi.quantity) > 0");
    $ordStmt->execute(array_merge([$supplierId, $deliveryDate], $supplierEntities, $expectedNums));
    $orderRows = $ordStmt->fetchAll();

    $productsOrdered = []; $pivot = [];
    foreach ($orderRows as $row) {
        $sku = $row['sku'];
        if (!isset($productsOrdered[$sku])) $productsOrdered[$sku] = ['sku' => $sku, 'name' => $row['product_name']];
        $rn = $row['restaurant_number'];
        if (!isset($pivot[$rn])) $pivot[$rn] = [];
        $pivot[$rn][$sku] = ($pivot[$rn][$sku] ?? 0) + (float)$row['qty'];
    }
    uasort($productsOrdered, fn($a, $b) => strcmp($a['name'], $b['name']));

    $dateFmt = (new DateTime($deliveryDate))->format('d.m.Y');
    $out['date_fmt'] = $dateFmt;
    $out['restaurants_count'] = count($expectedRests);
    $out['submitted_count'] = count(array_intersect($expectedNums, array_keys($submittedNums)));
    $out['items_count'] = count($orderRows);
    $out['filename'] = "Заявка {$supName} на {$dateFmt}.xlsx";
    $out['products_map'] = $productsOrdered;
    $colTotals = [];
    foreach ($pivot as $rn => $pmap) {
        foreach ($pmap as $sku => $qty) $colTotals[$sku] = ($colTotals[$sku] ?? 0) + (float)$qty;
    }
    $out['col_totals'] = $colTotals;

    if (!$productsOrdered) { $out['status'] = 'empty'; return $out; }

    $productsOut = array_values($productsOrdered);
    $restaurantsOut = [];
    foreach ($expectedRests as $rest) {
        $rn = (string)($rest['restaurant_number'] ?? '');
        if ($rn === '') continue;
        $restaurantsOut[] = [
            'number' => (int)$rn, 'city' => $rest['city'] ?: '', 'region' => $rest['region'] ?: '',
            'address' => $rest['address'] ?: '', 'submitted' => isset($submittedNums[$rn]),
        ];
    }
    $itemsOut = new stdClass();
    foreach ($pivot as $rn => $pmap) {
        foreach ($pmap as $sku => $qty) $itemsOut->{"{$rn}_{$sku}"} = ['qty' => (float)$qty, 'is_admin' => false];
    }
    $payload = [
        'supplier_name' => $supName, 'delivery_date_fmt' => $dateFmt, 'sheet_name' => $supName,
        'products' => $productsOut, 'restaurants' => $restaurantsOut, 'items' => $itemsOut,
    ];

    $tmpJson = tempnam(sys_get_temp_dir(), 'so_json_');
    $tmpXlsx = tempnam(sys_get_temp_dir(), 'so_xlsx_') . '.xlsx';
    file_put_contents($tmpJson, json_encode($payload, JSON_UNESCAPED_UNICODE));
    $scriptPath = escapeshellarg(__DIR__ . '/../../scripts/build_so_order_xlsx.mjs');
    $cmd = 'node ' . $scriptPath . ' ' . escapeshellarg($tmpJson) . ' ' . escapeshellarg($tmpXlsx) . ' 2>&1';
    exec($cmd, $outLines, $rc);
    @unlink($tmpJson);
    if ($rc !== 0 || !file_exists($tmpXlsx)) {
        @unlink($tmpXlsx);
        error_log('[soBuildSummaryXlsx] node failed (rc=' . $rc . '): ' . implode("\n", $outLines));
        $out['status'] = 'xlsx_error';
        $out['error'] = implode(' ', $outLines);
        return $out;
    }
    $out['xlsx'] = file_get_contents($tmpXlsx);
    @unlink($tmpXlsx);
    return $out;
}

function soGetSupplierNotifyUsers($pdo, $supplierId) {
    $s = $pdo->prepare("
        SELECT user_name
        FROM so_supplier_summary_subscribers
        WHERE supplier_id = ?
        ORDER BY user_name
    ");
    $s->execute([$supplierId]);
    return $s->fetchAll(PDO::FETCH_COLUMN);
}

function soAutoLockOrders($pdo, $supplierId, $deliveryDate) {
    $pdo->prepare("
        UPDATE so_orders SET status = 'locked', updated_at = NOW()
        WHERE supplier_id = ? AND delivery_date = ? AND status = 'submitted'
    ")->execute([$supplierId, $deliveryDate]);
}

/**
 * Сохраняет список получателей итоговой сводки для поставщика.
 *
 * @throws InvalidArgumentException  Если переданы имена, которых нет в активных пользователях.
 *                                   Сообщение содержит список не найденных имён.
 *                                   Сохранение НЕ выполняется — либо все, либо ошибка.
 */
function soSaveSupplierNotifyUsers($pdo, $supplierId, $notifyUsers) {
    $names = [];
    if (is_array($notifyUsers)) {
        foreach ($notifyUsers as $userName) {
            $userName = trim((string)$userName);
            if ($userName !== '') {
                $names[$userName] = true;
            }
        }
    }
    $names = array_keys($names);

    if (empty($names)) {
        $pdo->prepare("DELETE FROM so_supplier_summary_subscribers WHERE supplier_id = ?")->execute([$supplierId]);
        return [];
    }

    // Валидация: все имена должны быть активными пользователями.
    $ph = implode(',', array_fill(0, count($names), '?'));
    $validStmt = $pdo->prepare("SELECT name FROM users WHERE name IN ({$ph}) AND active = 1");
    $validStmt->execute($names);
    $validNames = $validStmt->fetchAll(PDO::FETCH_COLUMN);

    $notFound = array_values(array_diff($names, $validNames));
    if (!empty($notFound)) {
        throw new InvalidArgumentException(
            'Не найдены пользователи: ' . implode(', ', $notFound)
        );
    }

    $pdo->prepare("DELETE FROM so_supplier_summary_subscribers WHERE supplier_id = ?")->execute([$supplierId]);

    $createdBy = $GLOBALS['sessionUser']['name'] ?? 'system';
    $ins = $pdo->prepare("
        INSERT INTO so_supplier_summary_subscribers (supplier_id, user_name, created_by)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE user_name = VALUES(user_name)
    ");
    foreach ($validNames as $userName) {
        $ins->execute([$supplierId, $userName, $createdBy]);
    }

    sort($validNames, SORT_NATURAL | SORT_FLAG_CASE);
    return $validNames;
}

// Контракт сохранён: ['status' => 'open'|'closed', 'deadline' => 'Y-m-d HH:MM'|null, 'forced_closed'? => true]
function soCheckDeadline($pdo, $supplierId, $deliveryDate) {
    $r = soCalculateDeadline($pdo, $supplierId, $deliveryDate);
    $out = ['status' => $r['status'], 'deadline' => $r['deadline_str']];
    if (!empty($r['forced_closed'])) $out['forced_closed'] = true;
    return $out;
}

function soDeadlineTimeLabel($deadline) {
    if (!$deadline) return '';
    $parts = explode(' ', (string)$deadline, 2);
    if (count($parts) === 2 && preg_match('/^\d{2}:\d{2}/', $parts[1])) {
        return substr($parts[1], 0, 5);
    }
    return preg_match('/^\d{2}:\d{2}/', (string)$deadline) ? substr((string)$deadline, 0, 5) : '';
}

function soDeliveryDateMatchesDow($deliveryDate, $deliveryDow) {
    if (!$deliveryDate || !$deliveryDow) return false;
    return (int)(new DateTime($deliveryDate))->format('N') === (int)$deliveryDow;
}

function soOrderDateByDeliveryDate($deliveryDate, $orderDow) {
    $deliveryObj = new DateTime($deliveryDate, new DateTimeZone('Europe/Minsk'));
    $deliveryDow = (int)$deliveryObj->format('N');
    $diff = $deliveryDow - (int)$orderDow;
    if ($diff <= 0) $diff += 7;
    return $deliveryObj->modify("-{$diff} days")->format('Y-m-d');
}

/**
 * Уведомить рестораны об изменении временного графика поставщика.
 * Шлёт Telegram (всем верифицированным подписчикам ресторана) + Push (PWA).
 * Если $tempItems пуст, шлёт «временный график снят».
 *
 * @return array {sent_tg, sent_push, restaurants}
 */
function soNotifyTempScheduleChanged($pdo, $supplierId, $dateFrom, $dateTo, $tempItems) {
    $stats = ['sent_tg' => 0, 'sent_push' => 0, 'restaurants' => 0];

    // Имя поставщика и группа
    $supSt = $pdo->prepare("SELECT short_name, legal_entity_group FROM suppliers WHERE id = ? LIMIT 1");
    $supSt->execute([$supplierId]);
    $sup = $supSt->fetch();
    if (!$sup) return $stats;
    $supName = $sup['short_name'] ?: 'Поставщик';
    $supGroup = $sup['legal_entity_group'] ?? 'BK_VM';

    // Все рестораны той же группы с расписанием для этого поставщика
    $restSt = $pdo->prepare("
        SELECT DISTINCT r.id, r.number, r.legal_entity_group
        FROM restaurants r
        JOIN supplier_schedules ss ON ss.restaurant_id = r.id AND ss.supplier_id = ? AND ss.is_active = 1
        WHERE r.active = 1 AND r.legal_entity_group = ?
        ORDER BY r.number
    ");
    $restSt->execute([$supplierId, $supGroup]);
    $restaurants = $restSt->fetchAll();
    if (!$restaurants) return $stats;

    // Дни недели (короткие)
    $dows = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];

    // Группируем items по restaurant_id → [{order_day, delivery_day}]
    $byRest = [];
    foreach ($tempItems as $it) {
        $rid = (int)($it['restaurant_id'] ?? 0);
        if (!$rid) continue;
        $byRest[$rid][] = [
            'order'    => (int)($it['order_day'] ?? 0),
            'delivery' => (int)($it['delivery_day'] ?? 0),
        ];
    }

    // Период в человекочитаемом виде
    $fmt = function ($iso) {
        if (!$iso) return '';
        $dt = DateTime::createFromFormat('Y-m-d', $iso);
        return $dt ? $dt->format('d.m') : $iso;
    };
    $periodStr = $fmt($dateFrom) . ' – ' . $fmt($dateTo);

    $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';

    foreach ($restaurants as $r) {
        $rid = (int)$r['id'];
        $rNum = (int)$r['number'];
        $rGroup = $r['legal_entity_group'];

        // Дни этого ресторана из присланного temp-набора (или ничего — значит снято)
        $items = $byRest[$rid] ?? [];
        $isRemoved = empty($items) || !$dateFrom || !$dateTo;

        if ($isRemoved) {
            $titleHtml = "📅 <b>{$supName}</b> — временный график снят.\nГрафик заявок возвращён к обычному.";
            $titlePlain = "{$supName}: временный график снят. График возвращён к обычному.";
        } else {
            $deliveryDays = [];
            $orderPairs = [];
            $seen = [];
            foreach ($items as $it) {
                if ($it['delivery'] >= 1 && $it['delivery'] <= 7) {
                    if (!in_array($it['delivery'], $deliveryDays, true)) $deliveryDays[] = $it['delivery'];
                }
                $key = $it['order'] . '-' . $it['delivery'];
                if (!isset($seen[$key]) && $it['order'] >= 1 && $it['delivery'] >= 1) {
                    $orderPairs[] = $dows[$it['order']] . '→' . $dows[$it['delivery']];
                    $seen[$key] = true;
                }
            }
            sort($deliveryDays);
            $deliveryList = implode(', ', array_map(fn($d) => $dows[$d] ?? '?', $deliveryDays));
            $orderList = implode(', ', $orderPairs);

            $titleHtml  = "📅 <b>{$supName}</b> — временный график на {$periodStr}\n";
            $titleHtml .= "Дни поставки: <b>{$deliveryList}</b>\n";
            $titleHtml .= "Подача заявок: {$orderList}";
            $titlePlain = "{$supName}: временный график {$periodStr}. Дни поставки: {$deliveryList}. Подача: {$orderList}";
        }

        // Telegram — всем верифицированным подписчикам ресторана
        if ($botToken) {
            $tgSt = $pdo->prepare("
                SELECT chat_id
                FROM ro_telegram_subs
                WHERE restaurant_number = ? AND legal_entity_group = ?
                  AND verified_at IS NOT NULL AND chat_id IS NOT NULL
            ");
            $tgSt->execute([$rNum, $rGroup]);
            $chatIds = $tgSt->fetchAll(PDO::FETCH_COLUMN);
            $replyMarkup = json_encode([
                'inline_keyboard' => [[
                    ['text' => 'Открыть напоминания', 'url' => 'https://supply-department.online/restaurant/reminders'],
                ]],
            ]);
            foreach ($chatIds as $chatId) {
                $ok = sendTelegramMessage($botToken, $chatId, $titleHtml . "\n\n<i>Подробности в кабинете.</i>", 'HTML');
                if ($ok) $stats['sent_tg']++;
                // лёгкая защита от rate-limit Telegram
                usleep(50000);
            }
        }

        // Push (PWA)
        try {
            $pushSent = pushSendToRestaurant($pdo, $rNum, $rGroup, [
                'title' => "{$supName}: временный график",
                'body'  => $titlePlain,
                'url'   => '/restaurant/reminders',
                'tag'   => "temp-schedule-{$supplierId}",
            ]);
            $stats['sent_push'] += (int)$pushSent;
        } catch (Throwable $e) {
            error_log('[temp-schedule push] rest=' . $rNum . ' err=' . $e->getMessage());
        }

        $stats['restaurants']++;
    }

    return $stats;
}

// soGetTempSchedulePeriod / soGetEffectiveScheduleRows перенесены в so_deadline.php
// (общий файл, загружаемый всеми точками входа, включая telegram-бота).

function soGetScheduleDatesInRange($pdo, $supplierId, $dateFrom, $dateTo, $restaurantId = null) {
    if (!$supplierId || !$dateFrom || !$dateTo) return [];

    $tz = new DateTimeZone('Europe/Minsk');
    $from = new DateTime($dateFrom, $tz);
    $to = new DateTime($dateTo, $tz);
    $dates = [];
    while ($from <= $to) {
        $dateStr = $from->format('Y-m-d');
        $deliveryDow = (int)$from->format('N');
        $rows = soGetEffectiveScheduleRows($pdo, $supplierId, $dateStr, $restaurantId, false);
        foreach ($rows as $row) {
            if ((int)$row['delivery_day'] !== $deliveryDow) continue;
            $dates[$dateStr] = [
                'delivery_date' => $dateStr,
                'delivery_day' => $deliveryDow,
                'order_day' => (int)$row['order_day'],
                'order_date' => soOrderDateByDeliveryDate($dateStr, (int)$row['order_day']),
            ];
            break;
        }
        $from->modify('+1 day');
    }
    return array_values($dates);
}

function soRestaurantHasDeliveryDate($pdo, $restaurantId, $supplierId, $deliveryDate) {
    if (!$restaurantId || !$supplierId || !$deliveryDate) return false;
    $rows = soGetEffectiveScheduleRows($pdo, $supplierId, $deliveryDate, (int)$restaurantId, false);
    foreach ($rows as $row) {
        if (soDeliveryDateMatchesDow($deliveryDate, (int)$row['delivery_day'])) return true;
    }
    return false;
}

function soGetAllowedEntityGroups($sessionUser) {
    if (!$sessionUser || ($sessionUser['role'] ?? '') === 'admin') {
        return ['BK_VM', 'PS'];
    }
    $entities = $sessionUser['legal_entities'] ?? [];
    if (is_string($entities)) {
        $entities = json_decode($entities, true) ?: [];
    }
    if (!is_array($entities) || empty($entities)) {
        return [];
    }
    $groups = [];
    foreach ($entities as $entity) {
        $groups[getEntityGroup($entity)] = true;
    }
    return array_keys($groups);
}

function soRequireAdminEntityGroupAccess($sessionUser, $legalEntity) {
    if (!$legalEntity || !$sessionUser || ($sessionUser['role'] ?? '') === 'admin') {
        return;
    }
    $allowedGroups = soGetAllowedEntityGroups($sessionUser);
    $entityGroup = getEntityGroup($legalEntity);
    if (!in_array($entityGroup, $allowedGroups, true)) {
        soRespond(['error' => 'Нет доступа к данной группе юрлиц'], 403);
    }
}

function soAppendAllowedSupplierGroupFilter($sessionUser, $requestedLegalEntity, &$where, &$params, $column = 's.legal_entity_group') {
    if ($requestedLegalEntity) {
        soRequireAdminEntityGroupAccess($sessionUser, $requestedLegalEntity);
        $where[] = $column . ' = ?';
        $params[] = getEntityGroup($requestedLegalEntity);
        return;
    }

    $allowedGroups = soGetAllowedEntityGroups($sessionUser);
    if (empty($allowedGroups)) {
        soRespond(['error' => 'Нет доступа к группе юрлиц'], 403);
    }

    if (count($allowedGroups) === 1) {
        $where[] = $column . ' = ?';
        $params[] = $allowedGroups[0];
        return;
    }

    $ph = implode(',', array_fill(0, count($allowedGroups), '?'));
    $where[] = $column . " IN ({$ph})";
    foreach ($allowedGroups as $group) {
        $params[] = $group;
    }
}

function soAppendAllowedOrderEntityFilter($sessionUser, &$where, &$params, $column = 'o.legal_entity') {
    if (!$sessionUser || ($sessionUser['role'] ?? '') === 'admin') {
        return;
    }
    $entities = $sessionUser['legal_entities'] ?? [];
    if (is_string($entities)) {
        $entities = json_decode($entities, true) ?: [];
    }
    if (!is_array($entities) || empty($entities)) {
        soRespond(['error' => 'Нет доступа к юр. лицам'], 403);
    }
    $entities = array_values(array_unique($entities));
    $ph = implode(',', array_fill(0, count($entities), '?'));
    $where[] = $column . " IN ({$ph})";
    foreach ($entities as $entity) {
        $params[] = $entity;
    }
}

function soRequireAdminSupplierAccess($pdo, $sessionUser, $supplierId) {
    if (!$supplierId) {
        soRespond(['error' => 'Не указан поставщик'], 400);
    }

    $s = $pdo->prepare("SELECT id, short_name, legal_entity, legal_entity_group, is_active, so_enabled FROM suppliers WHERE id = ?");
    $s->execute([$supplierId]);
    $supplier = $s->fetch();
    if (!$supplier || (int)($supplier['is_active'] ?? 0) !== 1) {
        soRespond(['error' => 'Поставщик не найден'], 404);
    }

    if ($sessionUser && ($sessionUser['role'] ?? '') !== 'admin') {
        $allowedGroups = soGetAllowedEntityGroups($sessionUser);
        $supplierGroup = $supplier['legal_entity_group'] ?: getEntityGroup($supplier['legal_entity'] ?? '');
        if (!in_array($supplierGroup, $allowedGroups, true)) {
            soRespond(['error' => 'Нет доступа к этому поставщику'], 403);
        }
    }

    return $supplier;
}

// ═══ Парсинг маршрута ═══

$soParts = explode('/', $uri);
// uri = "so/action/param1/param2"
$soAction = $soParts[1] ?? '';
$soParam1 = $soParts[2] ?? null;
$soParam2 = $soParts[3] ?? null;
$soParam3 = $soParts[4] ?? null;

$dayNames = [1=>'ПН', 2=>'ВТ', 3=>'СР', 4=>'ЧТ', 5=>'ПТ', 6=>'СБ', 7=>'ВС'];
$dayNamesFull = [1=>'Понедельник',2=>'Вторник',3=>'Среда',4=>'Четверг',5=>'Пятница',6=>'Суббота',7=>'Воскресенье'];

// ═══════════════════════════════════════════════
// Маршруты для ресторанов
// ════��══════════════════════════════════════════

// --- Список поставщиков с графиком ---
if ($soAction === 'suppliers' && $method === 'GET') {
    $rest = soGetRestaurantSession($pdo);
    if (!$rest) soRespond(['error' => 'Не авторизован'], 401);

    $restGroup = getEntityGroup($rest['legal_entity'] ?? '');
    $restGroupEntities = getEntitiesInGroup($restGroup);
    $restGroupEntityPh = implode(',', array_fill(0, count($restGroupEntities), '?'));

    // 1. Все поставщики + их расписание для этого ресторана — один запрос
    $suppStmt = $pdo->prepare("
        SELECT s.id, s.short_name, s.full_name, ss.order_day, ss.delivery_day
        FROM supplier_schedules ss
        JOIN suppliers s ON s.id = ss.supplier_id AND s.is_active = 1 AND s.so_enabled = 1
        WHERE ss.restaurant_id = ? AND ss.is_active = 1
          AND (
            s.legal_entity_group = ?
            OR (COALESCE(s.legal_entity_group, '') = '' AND s.legal_entity IN ({$restGroupEntityPh}))
          )
        ORDER BY s.short_name, ss.order_day
    ");
    $suppStmt->execute(array_merge([$rest['restaurant_id'], $restGroup], $restGroupEntities));

    // Группируем: поставщик → расписание
    $suppliersMap = [];
    foreach ($suppStmt->fetchAll() as $row) {
        $sid = $row['id'];
        if (!isset($suppliersMap[$sid])) {
            $suppliersMap[$sid] = ['id' => $sid, 'name' => $row['short_name'], 'full_name' => $row['full_name'], 'schedule' => []];
        }
        $suppliersMap[$sid]['schedule'][] = ['order_day' => (int)$row['order_day'], 'delivery_day' => (int)$row['delivery_day']];
    }

    if (empty($suppliersMap)) {
        soRespond(['suppliers' => []]);
    }

    $supplierIds = array_keys($suppliersMap);
    $ph = implode(',', array_fill(0, count($supplierIds), '?'));

    // 2. Настройки всех поставщиков — один запрос
    $settingsRows = $pdo->prepare("SELECT supplier_id, is_accepting_orders, auto_submit_previous, default_deadline_time, pause_message FROM so_supplier_settings WHERE supplier_id IN ({$ph})");
    $settingsRows->execute($supplierIds);
    $settingsMap = [];
    foreach ($settingsRows->fetchAll() as $r) {
        $settingsMap[$r['supplier_id']] = $r;
    }

    // 3. Правила дедлайнов — один запрос
    $rulesRows = $pdo->prepare("SELECT supplier_id, delivery_dow, deadline_dow, deadline_time FROM supplier_default_deadlines WHERE supplier_id IN ({$ph})");
    $rulesRows->execute($supplierIds);
    $rulesMap = [];
    foreach ($rulesRows->fetchAll() as $r) {
        $rulesMap[$r['supplier_id']][(int)$r['delivery_dow']] = $r;
    }

    // 4. Переопределения дедлайнов на ближайшие 3 недели — один запрос
    $tz = new DateTimeZone('Europe/Minsk');
    $today = (new DateTime('now', $tz))->setTime(0, 0, 0);
    $rangeStart = $today->format('Y-m-d');
    $rangeEnd = (clone $today)->modify('+21 days')->format('Y-m-d');
    $overParams = array_merge($supplierIds, [$rangeStart, $rangeEnd]);
    $overRows = $pdo->prepare("SELECT supplier_id, delivery_date, deadline_date, deadline_time, is_closed FROM so_deadline_overrides WHERE supplier_id IN ({$ph}) AND delivery_date BETWEEN ? AND ?");
    $overRows->execute($overParams);
    $overridesMap = [];
    foreach ($overRows->fetchAll() as $r) {
        $overridesMap[$r['supplier_id']][$r['delivery_date']] = $r;
    }

    // 5. Существующие заявки ресторана по всем поставщикам — один запрос
    $ordParams = array_merge($supplierIds, [$rest['restaurant_number'], $rangeStart]);
    $ordRows = $pdo->prepare("
        SELECT o.supplier_id, o.delivery_date, o.id, o.status, o.submitted_at,
               (SELECT COUNT(*) FROM so_order_items WHERE order_id = o.id AND COALESCE(admin_qty, quantity) > 0) as item_count
        FROM so_orders o
        WHERE o.supplier_id IN ({$ph}) AND o.restaurant_number = ? AND o.delivery_date >= ?
    ");
    $ordRows->execute($ordParams);
    $ordersMap = [];
    foreach ($ordRows->fetchAll() as $r) {
        $ordersMap[$r['supplier_id']][$r['delivery_date']] = $r;
    }

    // Проверка дедлайна без запросов в БД — используем ядро soCalculateDeadlineCore
    $checkDeadline = function($sid, $deliveryDate) use ($tz, $rulesMap, $overridesMap, $settingsMap) {
        $override = $overridesMap[$sid][$deliveryDate] ?? null;
        $deliveryDow = (int)(new DateTime($deliveryDate))->format('N');
        $rule = $rulesMap[$sid][$deliveryDow] ?? null;
        $default = $settingsMap[$sid]['default_deadline_time'] ?? '14:00:00';
        $r = soCalculateDeadlineCore($override, $rule, $default, $deliveryDate, $tz);
        return [
            'status' => $r['is_closed'] ? 'closed' : 'open',
            'deadline' => $r['deadline_str'],
        ];
    };

    $result = [];
    foreach ($suppliersMap as $sid => $sup) {
        $settings = $settingsMap[$sid] ?? ['is_accepting_orders' => 1, 'default_deadline_time' => '14:00:00', 'pause_message' => null];
        $isAccepting = (int)($settings['is_accepting_orders'] ?? 1) === 1;

        $scheduleFormatted = [];
        foreach ($sup['schedule'] as $sc) {
            $scheduleFormatted[] = [
                'order_day' => $sc['order_day'],
                'order_day_name' => $dayNames[$sc['order_day']] ?? '',
                'delivery_day' => $sc['delivery_day'],
                'delivery_day_name' => $dayNames[$sc['delivery_day']] ?? '',
            ];
        }

        $availableDates = [];
        if ($isAccepting) {
            $supplierDates = soGetScheduleDatesInRange($pdo, $sid, $rangeStart, $rangeEnd, $rest['restaurant_id']);
            foreach ($supplierDates as $dateInfo) {
                $deliveryDateStr = $dateInfo['delivery_date'];
                $deliveryDow = (int)$dateInfo['delivery_day'];
                $orderDow = (int)$dateInfo['order_day'];
                $deadlineInfo = $checkDeadline($sid, $deliveryDateStr);
                $order = $ordersMap[$sid][$deliveryDateStr] ?? null;
                if ($deadlineInfo['status'] === 'closed' && !$order) continue;
                $availableDates[] = [
                    'order_date'       => $dateInfo['order_date'],
                    'order_day_name'   => $dayNamesFull[$orderDow] ?? '',
                    'delivery_date'    => $deliveryDateStr,
                    'delivery_day_name'=> $dayNamesFull[$deliveryDow] ?? '',
                    'deadline'         => $deadlineInfo['deadline'],
                    'deadline_status'  => $deadlineInfo['status'],
                    'order' => $order ? [
                        'id'           => (int)$order['id'],
                        'status'       => $order['status'],
                        'submitted_at' => $order['submitted_at'],
                        'item_count'   => (int)$order['item_count'],
                        'is_skip'      => ((int)$order['item_count']) === 0,
                    ] : null,
                ];
            }

            // Убираем дубли по delivery_date
            $seen = [];
            $availableDates = array_values(array_filter($availableDates, function ($d) use (&$seen) {
                if (isset($seen[$d['delivery_date']])) return false;
                $seen[$d['delivery_date']] = true;
                return true;
            }));
            usort($availableDates, fn($a, $b) => strcmp($a['delivery_date'], $b['delivery_date']));
            // Ресторану показываем только три ближайшие доступные даты.
            $availableDates = array_slice($availableDates, 0, 3);
        }

        $result[] = [
            'id'                 => $sid,
            'name'               => $sup['name'],
            'full_name'          => $sup['full_name'],
            'schedule'           => $scheduleFormatted,
            'temporary_schedule' => (($period = soGetTempSchedulePeriod($pdo, $sid)) ? [
                'date_from' => $period['date_from'],
                'date_to' => $period['date_to'],
            ] : null),
            'is_accepting_orders'=> $isAccepting,
            'pause_message'      => $settings['pause_message'] ?? null,
            'available_dates'    => $availableDates,
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

    soRespond(['products' => $products]);
}

// --- Мой заказ на дату ---
if ($soAction === 'my-order' && $method === 'GET' && $soParam1 && $soParam2) {
    $rest = soGetRestaurantSession($pdo);
    if (!$rest) soRespond(['error' => 'Не авторизован'], 401);

    $supplierId = $soParam1;
    $deliveryDate = $soParam2;

    $le = roGetLegalEntity($pdo, $rest['restaurant_number'], $rest['legal_entity_group'] ?? null);
    $s = $pdo->prepare("SELECT id, status, submitted_at, updated_at FROM so_orders WHERE supplier_id = ? AND restaurant_number = ? AND delivery_date = ? AND legal_entity = ?");
    $s->execute([$supplierId, $rest['restaurant_number'], $deliveryDate, $le]);
    $order = $s->fetch();

    // Предыдущая заявка — последняя submitted/locked этого ресторана у этого поставщика.
    // Отдаём только если текущая ещё не подана (нет заявки или статус draft).
    $previousOrder = null;
    $currentSubmitted = $order && in_array($order['status'], ['submitted', 'locked'], true);
    if (!$currentSubmitted) {
        $prev = $pdo->prepare("
            SELECT id, delivery_date, submitted_at, status
            FROM so_orders
            WHERE supplier_id = ? AND restaurant_number = ? AND legal_entity = ?
              AND status IN ('submitted','locked')
              AND delivery_date < ?
            ORDER BY delivery_date DESC
            LIMIT 1
        ");
        $prev->execute([$supplierId, $rest['restaurant_number'], $le, $deliveryDate]);
        $prevRow = $prev->fetch();
        if ($prevRow) {
            $prevItems = $pdo->prepare("SELECT sku, product_name, COALESCE(admin_qty, quantity) AS quantity FROM so_order_items WHERE order_id = ? AND COALESCE(admin_qty, quantity) > 0 ORDER BY product_name");
            $prevItems->execute([$prevRow['id']]);
            $previousOrder = [
                'id' => (int)$prevRow['id'],
                'delivery_date' => $prevRow['delivery_date'],
                'submitted_at' => $prevRow['submitted_at'],
                'items' => $prevItems->fetchAll(),
            ];
        }
    }

    if (!$order) soRespond(['order' => null, 'previous_order' => $previousOrder]);

    // quantity — исходное значение от ресторана, admin_qty — правка отдела закупок (если была)
    $items = $pdo->prepare("SELECT product_id, sku, product_name, quantity, admin_qty FROM so_order_items WHERE order_id = ? AND COALESCE(admin_qty, quantity) > 0 ORDER BY product_name");
    $items->execute([$order['id']]);

    soRespond([
        'order' => [
            'id' => (int)$order['id'],
            'status' => $order['status'],
            'submitted_at' => $order['submitted_at'],
            'items' => $items->fetchAll(),
        ],
        'previous_order' => $previousOrder,
    ]);
}

// --- История заявок ---
if ($soAction === 'my-orders' && $method === 'GET') {
    $rest = soGetRestaurantSession($pdo);
    if (!$rest) soRespond(['error' => 'Не авторизован'], 401);

    $supplierId = $_GET['supplier_id'] ?? '';
    $limit = min((int)($_GET['limit'] ?? 20), 50);

    $myOrdersLe = roGetLegalEntity($pdo, $rest['restaurant_number'], $rest['legal_entity_group'] ?? null);
    $where = "o.restaurant_number = ? AND o.legal_entity = ?";
    $params = [$rest['restaurant_number'], $myOrdersLe];
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
        $deadlineTime = soDeadlineTimeLabel($dlStatus['deadline']);
        soRespond(['error' => 'Приём заявок на эту дату закрыт' . ($deadlineTime ? " (дедлайн {$deadlineTime})" : '')], 403);
    }

    if (!soRestaurantHasDeliveryDate($pdo, $rest['restaurant_id'] ?? null, $supplierId, $deliveryDate)) {
        soRespond(['error' => 'На эту дату у ресторана нет поставки от этого поставщика'], 403);
    }

    // Валидация кратности и минимума по шаблону поставщика
    if (!empty($items)) {
        $tplCheck = $pdo->prepare("SELECT sku, multiplicity, min_qty FROM so_templates WHERE supplier_id = ? AND legal_entity = ? AND is_active = 1");
        $tplCheck->execute([$supplierId, $rest['legal_entity']]);
        $tplMap = [];
        foreach ($tplCheck->fetchAll() as $t) {
            $tplMap[$t['sku']] = $t;
        }
        $valErrors = [];
        foreach ($items as $item) {
            $qty = floatval($item['quantity'] ?? 0);
            $sku = $item['sku'] ?? '';
            if ($qty <= 0) continue;
            if (!isset($tplMap[$sku])) {
                $valErrors[] = "{$sku}: товара нет в шаблоне поставщика";
                continue;
            }
            $mult = floatval($tplMap[$sku]['multiplicity'] ?? 0);
            $min  = floatval($tplMap[$sku]['min_qty'] ?? 0);
            if ($mult > 0) {
                $rem = fmod($qty, $mult);
                if ($rem > 0.001 && abs($rem - $mult) > 0.001) {
                    $valErrors[] = "{$sku}: количество {$qty} должно быть кратно {$mult}";
                }
            }
            if ($min > 0 && $qty < $min) {
                $valErrors[] = "{$sku}: минимум {$min}, указано {$qty}";
            }
        }
        if (!empty($valErrors)) {
            soRespond(['error' => 'Ошибки в заявке: ' . implode('; ', $valErrors)], 422);
        }
    }

    // legal_entity ресторана — единый источник истины (restaurants.legal_entity).
    // Один ресторан = одно юрлицо, поэтому ищем существующую заявку строго в этом юрлице.
    $le = roGetLegalEntity($pdo, $rest['restaurant_number'], $rest['legal_entity_group'] ?? null);

    // Проверяем: есть ли уже заявка под этим юрлицом?
    $existing = $pdo->prepare("SELECT id, status FROM so_orders WHERE supplier_id = ? AND restaurant_number = ? AND delivery_date = ? AND legal_entity = ?");
    $existing->execute([$supplierId, $rest['restaurant_number'], $deliveryDate, $le]);
    $existingOrder = $existing->fetch();

    // Если заявка заблокирована — отказываем без перезаписи
    if ($existingOrder && $existingOrder['status'] === 'locked') {
        soRespond(['error' => 'Приём закрыт, заявка заблокирована'], 403);
    }

    $pdo->beginTransaction();
    try {
        // Сохраняем правки отдела закупок по SKU, чтобы повторная подача рестораном их не затёрла.
        $preservedAdminQty = [];
        $prevStatus = $existingOrder['status'] ?? null;
        if ($existingOrder) {
            $orderId = $existingOrder['id'];
            $existingItems = $pdo->prepare("SELECT sku, admin_qty FROM so_order_items WHERE order_id = ? AND admin_qty IS NOT NULL");
            $existingItems->execute([$orderId]);
            foreach ($existingItems->fetchAll() as $row) {
                $preservedAdminQty[$row['sku']] = $row['admin_qty'];
            }
            $pdo->prepare("DELETE FROM so_order_items WHERE order_id = ?")->execute([$orderId]);
            // Если заявка ранее была отредактирована закупщиком (status='edited'),
            // оставляем этот статус: факт правки не должен теряться при повторной подаче рестораном.
            $newStatus = ($prevStatus === 'edited') ? 'edited' : 'submitted';
            $pdo->prepare("UPDATE so_orders SET status = ?, submitted_at = NOW(), updated_at = NOW() WHERE id = ?")
                ->execute([$newStatus, $orderId]);
        } else {
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

        // Вставляем позиции (UNIQUE KEY order_id+sku гарантирует отсутствие дублей).
        // Если у этого SKU был admin_qty — сохраняем его, чтобы повторная подача не затёрла.
        $insertItem = $pdo->prepare("INSERT INTO so_order_items (order_id, product_id, sku, product_name, quantity, admin_qty) VALUES (?, ?, ?, ?, ?, ?)");
        $totalQty = 0;
        $totalItems = 0;
        foreach ($aggregated as $item) {
            $insertItem->execute([
                $orderId,
                $item['product_id'],
                $item['sku'],
                $item['product_name'],
                $item['quantity'],
                $preservedAdminQty[$item['sku']] ?? null,
            ]);
            $totalQty += $item['quantity'];
            $totalItems++;
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        soRespond(['error' => 'Ошибка сохранения заявки'], 500);
    }

    // ── Подготовка уведомления (до отправки ответа клиенту) ──────────────────
    // Формируем сообщение здесь, пока $aggregated, $skipDelivery, $totalItems доступны.
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

    // Подтягиваем единицы измерения из products по sku (используем агрегированные позиции)
    $skus = [];
    foreach ($aggregated as $it) {
        $sk = trim((string)($it['sku'] ?? ''));
        if ($sk !== '') $skus[$sk] = true;
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
    $restaurantLabel = roFormatRestaurantTelegramLabel(
        $rest['restaurant_number'],
        $rest['city'] ?? '',
        $rest['address'] ?? '',
        $rest['legal_entity_group'] ?? null
    );
    $lines[] = "🏪 <b>Ресторан:</b> " . $esc($restaurantLabel);
    $lines[] = "🏪 <b>Поставщик:</b> " . $esc($supplierName);
    $lines[] = "📅 <b>Доставка:</b> {$deliveryDateFmt}";
    if (!$isSkip) {
        $lines[] = "📋 <b>Позиций:</b> {$totalItems}";
        $lines[] = '';
        $lines[] = '<b>Состав:</b>';
        foreach ($aggregated as $it) {
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

    $tgMsg = implode("\n", $lines);
    if (mb_strlen($tgMsg) > 3900) {
        $tgMsg = mb_substr($tgMsg, 0, 3900) . "\n\n…(сообщение обрезано)";
    }

    // ── Отправляем ответ клиенту, затем — уведомления в Telegram ────────────
    // На PHP-FPM fastcgi_finish_request() закрывает соединение с клиентом,
    // позволяя продолжить выполнение (отправку TG-сообщений) уже без блокировки HTTP.
    // На Apache/mod_php функция отсутствует — уведомления отправляются синхронно как раньше.
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'order_id' => (int)$orderId,
        'total_items' => $totalItems,
        'total_qty' => $totalQty,
    ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // ── Уведомление в Telegram (после отдачи ответа клиенту) ────────────────
    try {
        roNotifyRestaurant($pdo, $rest['restaurant_number'], $tgMsg, $rest['legal_entity_group'] ?? 'BK_VM');
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
                'supplier_id' => $supplierId,
                'supplier' => $supNameForLog,
                'delivery_date' => $deliveryDate,
                'items_count' => $totalItems,
                'total_qty' => $totalQty,
            ]
        );
    } catch (Exception $e) { /* не критично */ }

    exit;
}

// ═══════════════════════════════════════════════
// Маршруты для отдела закупок (admin)
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
        $where = ["s.is_active = 1", "s.so_enabled = 1"];
        $params = [];
        soAppendAllowedSupplierGroupFilter($sessionUser, $legalEntity, $where, $params);
        // Показываем только подключённых к SO-модулю поставщиков (so_enabled=1),
        // остальные доступны через мастер «+ Подключить поставщика».
        $s = $pdo->prepare("
            SELECT s.id, s.short_name, s.full_name, s.legal_entity, s.legal_entity_group,
                   COUNT(DISTINCT ss.restaurant_id) as restaurant_count,
                   COALESCE(sst.is_accepting_orders, 1) as is_accepting_orders
            FROM suppliers s
            LEFT JOIN supplier_schedules ss ON ss.supplier_id = s.id AND ss.is_active = 1
            LEFT JOIN so_supplier_settings sst ON sst.supplier_id = s.id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY s.id
            ORDER BY s.short_name
        ");
        $s->execute($params);
        soRespond(['suppliers' => $s->fetchAll()]);
    }

    // --- Список поставщиков группы, ещё НЕ подключённых к SO-модулю ---
    // Используется в мастере «Подключить поставщика»: выпадающий список
    // среди тех, кого можно активировать.
    if ($adminAction === 'available-suppliers' && $method === 'GET') {
        $legalEntity = $_GET['legal_entity'] ?? null;
        $where = ["is_active = 1", "so_enabled = 0"];
        $params = [];
        soAppendAllowedSupplierGroupFilter($sessionUser, $legalEntity, $where, $params, 'legal_entity_group');
        $s = $pdo->prepare("
            SELECT id, short_name, full_name, legal_entity, legal_entity_group
            FROM suppliers
            WHERE " . implode(' AND ', $where) . "
            ORDER BY short_name
        ");
        $s->execute($params);
        soRespond(['suppliers' => $s->fetchAll()]);
    }

    // --- Отключение поставщика от SO-модуля (не удаление, просто скрыть) ---
    // Каскадно гасим расписание и шаблоны: без этого крон и бот продолжат
    // показывать поставщика, т.к. они фильтруют по supplier_schedules.is_active.
    if ($adminAction === 'disconnect-supplier' && $method === 'POST') {
        $supplierId = $body['supplier_id'] ?? '';
        $supplier = soRequireAdminSupplierAccess($pdo, $sessionUser, $supplierId);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE suppliers SET so_enabled = 0 WHERE id = ?")->execute([$supplierId]);
            $pdo->prepare("UPDATE supplier_schedules SET is_active = 0 WHERE supplier_id = ?")->execute([$supplierId]);
            $pdo->prepare("DELETE FROM so_supplier_temp_schedule_periods WHERE supplier_id = ?")->execute([$supplierId]);
            $pdo->prepare("UPDATE so_templates SET is_active = 0 WHERE supplier_id = ?")->execute([$supplierId]);
            $pdo->commit();
        } catch (InvalidArgumentException $e) {
            $pdo->rollBack();
            soRespond(['error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        try {
            $by = resolveActorName($pdo, $sessionUser);
            auditLog($pdo, 'so_supplier_disconnected', 'supplier', $supplierId, $by, [
                'supplier_id' => $supplierId,
            ]);
        } catch (Exception $e) { /* не критично */ }
        soRespond(['success' => true]);
    }

    // --- Подключение поставщика к SO-модулю (мастер) ---
    // Принимает всё в одном запросе: расписание, шаблон товаров,
    // дедлайны, режим приёма, подписчиков-получателей уведомлений.
    // Сохраняет всё в транзакции и ставит so_enabled = 1.
    if ($adminAction === 'register-supplier' && $method === 'POST') {
        $supplierId = $body['supplier_id'] ?? '';
        $supplier = soRequireAdminSupplierAccess($pdo, $sessionUser, $supplierId);

        // Валидация входных данных
        $schedules     = $body['schedules']     ?? []; // [{restaurant_id, order_day, delivery_day}]
        $templates     = $body['templates']     ?? []; // [{legal_entity, items: [{sku, product_name, multiplicity, min_qty, sort_order}]}]
        $deadlineRules = $body['deadline_rules'] ?? []; // [{delivery_dow, deadline_dow, deadline_time}]
        $acceptance    = $body['acceptance']    ?? ['is_accepting_orders' => 1, 'default_deadline_time' => '14:00:00', 'pause_message' => null];
        $notifyUsers   = $body['notify_users']  ?? []; // [user_name, ...]

        $updatedBy = resolveActorName($pdo, $sessionUser);

        try {
            $pdo->beginTransaction();

            // 1) Активируем флаг
            $pdo->prepare("UPDATE suppliers SET so_enabled = 1 WHERE id = ?")->execute([$supplierId]);

            // 2) Настройки приёма заявок
            $acceptingFlag  = !empty($acceptance['is_accepting_orders']) ? 1 : 0;
            $autoSubmitFlag = !empty($acceptance['auto_submit_previous']) ? 1 : 0;
            $defaultDeadline = $acceptance['default_deadline_time'] ?? '14:00:00';
            $pauseMessage    = $acceptance['pause_message'] ?? null;
            $pdo->prepare("
                INSERT INTO so_supplier_settings (supplier_id, is_accepting_orders, auto_submit_previous, default_deadline_time, pause_message, updated_by)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    is_accepting_orders = VALUES(is_accepting_orders),
                    auto_submit_previous = VALUES(auto_submit_previous),
                    default_deadline_time = VALUES(default_deadline_time),
                    pause_message = VALUES(pause_message),
                    updated_at = NOW(),
                    updated_by = VALUES(updated_by)
            ")->execute([$supplierId, $acceptingFlag, $autoSubmitFlag, $defaultDeadline, $pauseMessage, $updatedBy]);

            // 3) Расписание (деактивируем старые, вставляем новые)
            $pdo->prepare("UPDATE supplier_schedules SET is_active = 0, updated_at = NOW(), updated_by = ? WHERE supplier_id = ?")
                ->execute([$updatedBy, $supplierId]);
            if (!empty($schedules)) {
                $schUp = $pdo->prepare("
                    INSERT INTO supplier_schedules (supplier_id, restaurant_id, order_day, delivery_day, is_active, updated_at, updated_by)
                    VALUES (?, ?, ?, ?, 1, NOW(), ?)
                    ON DUPLICATE KEY UPDATE
                        order_day = VALUES(order_day),
                        delivery_day = VALUES(delivery_day),
                        is_active = 1,
                        updated_at = NOW(),
                        updated_by = VALUES(updated_by)
                ");
                foreach ($schedules as $sch) {
                    $restId = (int)($sch['restaurant_id'] ?? 0);
                    if (!$restId) continue;
                    $schUp->execute([
                        $supplierId, $restId,
                        (int)($sch['order_day'] ?? 1),
                        (int)($sch['delivery_day'] ?? 2),
                        $updatedBy,
                    ]);
                }
            }

            // 4) Правила дедлайнов
            $pdo->prepare("DELETE FROM supplier_default_deadlines WHERE supplier_id = ?")->execute([$supplierId]);
            if (!empty($deadlineRules)) {
                $drIns = $pdo->prepare("INSERT INTO supplier_default_deadlines (supplier_id, delivery_dow, deadline_dow, deadline_time) VALUES (?, ?, ?, ?)");
                foreach ($deadlineRules as $rule) {
                    $dow = (int)($rule['delivery_dow'] ?? 0);
                    if (!in_array($dow, [1,2,3,4,5,6,7], true)) continue;
                    $ddow = (int)($rule['deadline_dow'] ?? $dow);
                    if (!in_array($ddow, [1,2,3,4,5,6,7], true)) $ddow = $dow;
                    $dt = $rule['deadline_time'] ?? '14:00:00';
                    if (preg_match('/^(\d{1,2}):(\d{2})$/', $dt, $m)) {
                        $dt = sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
                    } elseif (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $dt)) {
                        $dt = '14:00:00';
                    }
                    $drIns->execute([
                        $supplierId, $dow, $ddow, $dt,
                    ]);
                }
            }

            // 5) Шаблоны товаров — per legal_entity (одна группа → может быть несколько юрлиц, напр. БК+ВМ)
            if (!empty($templates)) {
                foreach ($templates as $tpl) {
                    $le = $tpl['legal_entity'] ?? null;
                    if (!$le) continue;
                    soRequireAdminEntityGroupAccess($sessionUser, $le);
                    $items = $tpl['items'] ?? [];
                    $pdo->prepare("DELETE FROM so_templates WHERE supplier_id = ? AND legal_entity = ?")
                        ->execute([$supplierId, $le]);
                    if (!empty($items)) {
                        $tIns = $pdo->prepare("INSERT INTO so_templates (supplier_id, legal_entity, sku, product_name, multiplicity, min_qty, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
                        foreach ($items as $i => $it) {
                            $sku = trim($it['sku'] ?? '');
                            $pname = trim($it['product_name'] ?? '');
                            if (!$sku || !$pname) continue;
                            $tIns->execute([
                                $supplierId, $le, $sku, $pname,
                                isset($it['multiplicity']) && $it['multiplicity'] !== '' ? floatval($it['multiplicity']) : null,
                                isset($it['min_qty']) && $it['min_qty'] !== '' ? floatval($it['min_qty']) : null,
                                (int)($it['sort_order'] ?? $i),
                            ]);
                        }
                    }
                }
            }

            // 6) Подписчики итоговой сводки — отдельные для конкретного поставщика
            soSaveSupplierNotifyUsers($pdo, $supplierId, $notifyUsers);

            $pdo->commit();
            soRespond(['success' => true, 'supplier' => $supplier]);
        } catch (InvalidArgumentException $e) {
            $pdo->rollBack();
            soRespond(['error' => $e->getMessage()], 400);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('register-supplier error: ' . $e->getMessage());
            soRespond(['error' => 'Ошибка подключения: ' . $e->getMessage()], 500);
        }
    }

    // --- Настройки поставщика (GET) ---
    if ($adminAction === 'settings' && $method === 'GET') {
        $supplierId = $_GET['supplier_id'] ?? '';
        $supplier = soRequireAdminSupplierAccess($pdo, $sessionUser, $supplierId);
        $settings = soGetSupplierSettings($pdo, $supplierId);
        // Список разовых переопределений дедлайна
        $overridesList = [];
        try {
            $ov = $pdo->prepare("SELECT delivery_date, deadline_date, deadline_time, is_closed, created_by, created_at FROM so_deadline_overrides WHERE supplier_id = ? AND delivery_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) ORDER BY delivery_date");
            $ov->execute([$supplierId]);
            $overridesList = $ov->fetchAll();
        } catch (PDOException $e) {
            // is_closed колонка не существует — миграция не применена, читаем без неё
            $ov = $pdo->prepare("SELECT delivery_date, NULL as deadline_date, deadline_time, 0 as is_closed, created_by, created_at FROM so_deadline_overrides WHERE supplier_id = ? AND delivery_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) ORDER BY delivery_date");
            $ov->execute([$supplierId]);
            $overridesList = $ov->fetchAll();
        }
        soRespond([
            'settings' => $settings,
            'overrides' => $overridesList,
            'notify_users' => soGetSupplierNotifyUsers($pdo, $supplierId),
        ]);
    }

    // --- Обновить настройки поставщика (POST) ---
    if ($adminAction === 'settings' && $method === 'POST') {
        $supplierId = $body['supplier_id'] ?? '';
        soRequireAdminSupplierAccess($pdo, $sessionUser, $supplierId);
        $updatedBy = resolveActorName($pdo, $sessionUser);

        $isAccepting = isset($body['is_accepting_orders']) ? ((int)!!$body['is_accepting_orders']) : 1;
        $autoSubmitPrev = !empty($body['auto_submit_previous']) ? 1 : 0;
        $defaultDl = $body['default_deadline_time'] ?? '14:00:00';
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $defaultDl, $m)) {
            $defaultDl = sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
        } elseif (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $defaultDl)) {
            $defaultDl = '14:00:00';
        }
        $pauseMsg = $body['pause_message'] ?? null;
        $notifyUsers = array_key_exists('notify_users', $body) ? ($body['notify_users'] ?? []) : null;

        $pdo->prepare("INSERT INTO so_supplier_settings (supplier_id, is_accepting_orders, auto_submit_previous, default_deadline_time, pause_message, updated_by)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              is_accepting_orders = VALUES(is_accepting_orders),
              auto_submit_previous = VALUES(auto_submit_previous),
              default_deadline_time = VALUES(default_deadline_time),
              pause_message = VALUES(pause_message),
              updated_by = VALUES(updated_by)")
            ->execute([$supplierId, $isAccepting, $autoSubmitPrev, $defaultDl, $pauseMsg, $updatedBy]);

        if ($notifyUsers !== null) {
            try {
                soSaveSupplierNotifyUsers($pdo, $supplierId, $notifyUsers);
            } catch (InvalidArgumentException $e) {
                soRespond(['error' => $e->getMessage()], 400);
            }
        }

        soRespond([
            'success' => true,
            'settings' => soGetSupplierSettings($pdo, $supplierId),
            'notify_users' => soGetSupplierNotifyUsers($pdo, $supplierId),
        ]);
    }

    // --- Сводка заявок по поставщику + дате ---
    if ($adminAction === 'status' && $method === 'GET') {
        $supplierId = $_GET['supplier_id'] ?? '';
        $date = $_GET['date'] ?? '';

        $supplier = soRequireAdminSupplierAccess($pdo, $sessionUser, $supplierId);

        $settings = soGetSupplierSettings($pdo, $supplierId);

        $tz = new DateTimeZone('Europe/Minsk');
        $today = (new DateTime('now', $tz))->setTime(0, 0, 0);
        $rangeStart = $today->format('Y-m-d');
        $rangeEnd = (clone $today)->modify('+21 days')->format('Y-m-d');
        $weekDates = soGetScheduleDatesInRange($pdo, $supplierId, $rangeStart, $rangeEnd);

        if (!$date) {
            $date = !empty($weekDates) ? $weekDates[0]['delivery_date'] : $today->format('Y-m-d');
        }

        // Все рестораны, у которых поставка в этот день (без session_id)
        $supplierGroup = $supplier['legal_entity_group'] ?: getEntityGroup($supplier['legal_entity'] ?? '');
        $supplierEntities = getEntitiesInGroup($supplierGroup);
        $entityPh = implode(',', array_fill(0, count($supplierEntities), '?'));
        $effectiveRows = soGetEffectiveScheduleRows($pdo, $supplierId, $date, null, true);
        $deliveryDow = (int)(new DateTime($date))->format('N');
        $orderRowsStmt = $pdo->prepare("
            SELECT o.id as order_id, o.restaurant_number, o.status as order_status, o.submitted_at,
                   CASE WHEN asl.id IS NULL THEN 0 ELSE 1 END AS is_auto_submitted,
                   asl.source_order_id AS auto_source_order_id,
                   src.delivery_date AS auto_source_delivery_date,
                   COALESCE(oi.item_count, 0) AS item_count,
                   oi.total_qty
            FROM so_orders o
            LEFT JOIN so_auto_submit_log asl ON asl.new_order_id = o.id
            LEFT JOIN so_orders src ON src.id = asl.source_order_id
            LEFT JOIN (
                SELECT soi.order_id,
                       SUM(CASE WHEN COALESCE(soi.admin_qty, soi.quantity) > 0 THEN 1 ELSE 0 END) AS item_count,
                       SUM(COALESCE(soi.admin_qty, soi.quantity)) AS total_qty
                FROM so_order_items soi
                JOIN so_orders so ON so.id = soi.order_id
                WHERE so.supplier_id = ? AND so.delivery_date = ? AND so.legal_entity IN ({$entityPh})
                GROUP BY soi.order_id
            ) oi ON oi.order_id = o.id
            WHERE o.supplier_id = ? AND o.delivery_date = ? AND o.legal_entity IN ({$entityPh})
        ");
        $orderRowsStmt->execute(array_merge([$supplierId, $date], $supplierEntities, [$supplierId, $date], $supplierEntities));
        $ordersByRestaurant = [];
        foreach ($orderRowsStmt->fetchAll() as $orderRow) {
            $ordersByRestaurant[(string)$orderRow['restaurant_number']] = $orderRow;
        }

        $restaurants = [];
        foreach ($effectiveRows as $row) {
            if ((int)$row['delivery_day'] !== $deliveryDow) continue;
            $orderRow = $ordersByRestaurant[(string)$row['restaurant_number']] ?? [];

            $restaurants[] = [
                'number' => $row['restaurant_number'],
                'region' => $row['region'],
                'city' => $row['city'],
                'address' => $row['address'],
                'legal_entity_group' => $row['legal_entity_group'],
                'order_day' => (int)$row['order_day'],
                'order_id' => $orderRow['order_id'] ?? null,
                'order_status' => $orderRow['order_status'] ?? null,
                'submitted_at' => $orderRow['submitted_at'] ?? null,
                'is_auto_submitted' => isset($orderRow['is_auto_submitted']) ? (int)$orderRow['is_auto_submitted'] : 0,
                'auto_source_order_id' => $orderRow['auto_source_order_id'] ?? null,
                'auto_source_delivery_date' => $orderRow['auto_source_delivery_date'] ?? null,
                'item_count' => isset($orderRow['item_count']) ? (int)$orderRow['item_count'] : 0,
                'total_qty' => $orderRow['total_qty'] ?? null,
            ];
        }
        usort($restaurants, function ($a, $b) {
            $regionCmp = strcmp((string)($a['region'] ?? ''), (string)($b['region'] ?? ''));
            if ($regionCmp !== 0) return $regionCmp;
            return (int)($a['number'] ?? 0) <=> (int)($b['number'] ?? 0);
        });

        $total = count($restaurants);
        $submitted = 0;
        foreach ($restaurants as $r) {
            if ($r['order_status'] === 'submitted' || $r['order_status'] === 'locked') $submitted++;
        }

        $weekDates = array_map(function ($item) use ($dayNames, $dayNamesFull) {
            $dow = (int)$item['delivery_day'];
            return [
                'date' => $item['delivery_date'],
                'day_name' => $dayNames[$dow] ?? '',
                'day_name_full' => $dayNamesFull[$dow] ?? '',
            ];
        }, $weekDates);

        // Все позиции заявок для этой даты
        $itemsStmt = $pdo->prepare("
            SELECT o.restaurant_number, o.delivery_date,
                   oi.sku, oi.product_name, oi.quantity, oi.admin_qty, oi.id as item_id, o.id as order_id
            FROM so_orders o
            JOIN so_order_items oi ON oi.order_id = o.id
            WHERE o.supplier_id = ? AND o.delivery_date = ? AND o.legal_entity IN ({$entityPh})
        ");
        $itemsStmt->execute(array_merge([$supplierId, $date], $supplierEntities));
        $orderItems = $itemsStmt->fetchAll();

        // Товары для матрицы:
        // 1. текущий шаблон
        // 2. плюс реальные SKU из заявок на выбранную дату, которых в шаблоне уже нет
        // Это нужно для старых заявок Планеты и других исторических данных, чтобы
        // отдел закупок видел позиции, даже если шаблон потом поменяли.
        $tplStmt = $pdo->prepare("
            SELECT DISTINCT t.sku, t.product_name, t.sort_order, t.multiplicity, t.product_id
            FROM so_templates t
            WHERE t.supplier_id = ? AND t.legal_entity IN ({$entityPh}) AND t.is_active = 1
            ORDER BY t.sort_order, t.product_name
        ");
        $tplStmt->execute(array_merge([$supplierId], $supplierEntities));
        $products = $tplStmt->fetchAll();

        $productMap = [];
        $maxSortOrder = 0;
        foreach ($products as $idx => $product) {
            $sku = (string)($product['sku'] ?? '');
            if ($sku === '') continue;
            $productMap[$sku] = $product;
            $sortOrder = isset($product['sort_order']) ? (int)$product['sort_order'] : ($idx * 10);
            if ($sortOrder > $maxSortOrder) {
                $maxSortOrder = $sortOrder;
            }
        }

        foreach ($orderItems as $item) {
            $sku = (string)($item['sku'] ?? '');
            if ($sku === '' || isset($productMap[$sku])) continue;
            $maxSortOrder += 10;
            $productMap[$sku] = [
                'sku' => $sku,
                'product_name' => $item['product_name'] ?: $sku,
                'sort_order' => $maxSortOrder,
                'multiplicity' => null,
                'product_id' => null,
                'is_legacy' => 1,
            ];
        }

        $products = array_values($productMap);
        usort($products, function ($a, $b) {
            $sortA = isset($a['sort_order']) ? (int)$a['sort_order'] : 0;
            $sortB = isset($b['sort_order']) ? (int)$b['sort_order'] : 0;
            if ($sortA !== $sortB) return $sortA <=> $sortB;
            return strcmp((string)($a['product_name'] ?? ''), (string)($b['product_name'] ?? ''));
        });

        // Дедлайн для этой даты
        $deadlineInfo = soCheckDeadline($pdo, $supplierId, $date);

        // Автоматически переводим заявки в «закрыто» после дедлайна
        if ($deadlineInfo['status'] === 'closed') {
            soAutoLockOrders($pdo, $supplierId, $date);
            // Обновляем статус в уже загруженных ресторанах без повторного запроса
            foreach ($restaurants as &$r) {
                if (($r['order_status'] ?? '') === 'submitted') {
                    $r['order_status'] = 'locked';
                }
            }
            unset($r);
        }

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
        $submittedFrom = $_GET['submitted_from'] ?? date('Y-m-d', strtotime('-7 days'));
        $submittedTo = $_GET['submitted_to'] ?? date('Y-m-d');
        $deliveryFrom = $_GET['delivery_from'] ?? '';
        $deliveryTo = $_GET['delivery_to'] ?? '';
        $statusFilter = trim((string)($_GET['status'] ?? ''));
        $query = trim((string)($_GET['query'] ?? ''));
        $skipOnly = !empty($_GET['skip_only']);

        $where = "1=1";
        $params = [];
        if ($supplierId) {
            soRequireAdminSupplierAccess($pdo, $sessionUser, $supplierId);
            $where .= " AND o.supplier_id = ?";
            $params[] = $supplierId;
        }
        $orderWhereParts = [];
        $orderWhereParams = [];
        soAppendAllowedOrderEntityFilter($sessionUser, $orderWhereParts, $orderWhereParams, 'o.legal_entity');
        if (!empty($orderWhereParts)) {
            $where .= " AND " . implode(' AND ', $orderWhereParts);
            $params = array_merge($params, $orderWhereParams);
        }
        if ($submittedFrom && preg_match('/^\d{4}-\d{2}-\d{2}$/', $submittedFrom)) {
            $where .= " AND o.submitted_at >= ?";
            $params[] = $submittedFrom . ' 00:00:00';
        }
        if ($submittedTo && preg_match('/^\d{4}-\d{2}-\d{2}$/', $submittedTo)) {
            $where .= " AND o.submitted_at < ?";
            $params[] = date('Y-m-d', strtotime($submittedTo . ' +1 day')) . ' 00:00:00';
        }
        if ($deliveryFrom) {
            $where .= " AND o.delivery_date >= ?";
            $params[] = $deliveryFrom;
        }
        if ($deliveryTo) {
            $where .= " AND o.delivery_date <= ?";
            $params[] = $deliveryTo;
        }
        if ($statusFilter !== '') {
            $where .= " AND o.status = ?";
            $params[] = $statusFilter;
        }
        if ($query !== '') {
            $where .= " AND (CAST(o.restaurant_number AS CHAR) LIKE ? OR COALESCE(r.address, '') LIKE ? OR COALESCE(r.city, '') LIKE ? OR COALESCE(r.region, '') LIKE ?)";
            $like = '%' . $query . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($skipOnly) {
            $where .= " AND NOT EXISTS (
                SELECT 1
                FROM so_order_items soi
                WHERE soi.order_id = o.id AND COALESCE(soi.admin_qty, soi.quantity) > 0
            )";
        }

        $s = $pdo->prepare("
            SELECT o.id, o.delivery_date, o.order_date, o.restaurant_number, o.status, o.submitted_at, o.supplier_id,
                   s.short_name as supplier_name,
                   r.region, r.city, r.address, r.legal_entity_group,
                   CASE WHEN asl.id IS NULL THEN 0 ELSE 1 END AS is_auto_submitted,
                   asl.source_order_id AS auto_source_order_id,
                   src.delivery_date AS auto_source_delivery_date,
                   (SELECT COUNT(*) FROM so_order_items WHERE order_id = o.id AND COALESCE(admin_qty, quantity) > 0) as item_count,
                   (SELECT SUM(COALESCE(admin_qty, quantity)) FROM so_order_items WHERE order_id = o.id) as total_qty
            FROM so_orders o
            JOIN suppliers s ON s.id = o.supplier_id
            LEFT JOIN so_auto_submit_log asl ON asl.new_order_id = o.id
            LEFT JOIN so_orders src ON src.id = asl.source_order_id
            LEFT JOIN restaurants r
              ON r.number = o.restaurant_number
             AND r.active = 1
             AND r.legal_entity_group = o.legal_entity_group
            WHERE {$where}
            ORDER BY o.submitted_at DESC, o.restaurant_number
        ");
        $s->execute($params);
        soRespond(['orders' => $s->fetchAll()]);
    }

    // --- Детали заявки ---
    if ($adminAction === 'order' && $method === 'GET' && $adminParam) {
        $s = $pdo->prepare("
            SELECT o.*, s.short_name as supplier_name, r.region, r.city, r.address, r.legal_entity_group,
                   CASE WHEN asl.id IS NULL THEN 0 ELSE 1 END AS is_auto_submitted,
                   asl.source_order_id AS auto_source_order_id,
                   src.delivery_date AS auto_source_delivery_date
            FROM so_orders o
            JOIN suppliers s ON s.id = o.supplier_id
            LEFT JOIN so_auto_submit_log asl ON asl.new_order_id = o.id
            LEFT JOIN so_orders src ON src.id = asl.source_order_id
            LEFT JOIN restaurants r
              ON r.number = o.restaurant_number
             AND r.active = 1
             AND r.legal_entity_group = o.legal_entity_group
            WHERE o.id = ?
        ");
        $s->execute([$adminParam]);
        $order = $s->fetch();
        if (!$order) soRespond(['error' => 'Заявка не найдена'], 404);
        // Проверка доступа к юр. лицу заявки
        if ($sessionUser && !checkLegalEntityAccess($sessionUser, $order['legal_entity'] ?? '')) {
            soRespond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        }

        // Скрываем пустые позиции (quantity=0 и admin_qty не выставлен) — это мусор от старых правок.
        $items = $pdo->prepare("SELECT * FROM so_order_items WHERE order_id = ? AND (quantity > 0 OR admin_qty > 0) ORDER BY product_name");
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

        // Валидация статуса
        $allowedStatuses = ['draft', 'submitted', 'locked', 'edited', 'cancelled'];
        if ($status !== null && !in_array($status, $allowedStatuses, true)) {
            soRespond(['error' => 'Недопустимый статус'], 422);
        }

        $updatedBy = resolveActorName($pdo, $sessionUser);

        $pdo->beginTransaction();
        try {
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

            if ($status !== null) {
                $pdo->prepare("UPDATE so_orders SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$status, $orderId]);
            } else {
                $pdo->prepare("UPDATE so_orders SET updated_at = NOW() WHERE id = ?")->execute([$orderId]);
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            soRespond(['error' => 'Ошибка сохранения заявки: ' . $e->getMessage()], 500);
        }

        // Уведомляем ресторан в Telegram (с итоговым составом)
        $oi = $pdo->prepare("
            SELECT o.restaurant_number, o.delivery_date, o.legal_entity,
                   r.city, r.address, r.legal_entity_group,
                   s.short_name as supplier_name
            FROM so_orders o
            JOIN suppliers s ON s.id = o.supplier_id
            LEFT JOIN restaurants r
              ON r.number = o.restaurant_number
             AND r.active = 1
             AND r.legal_entity_group = o.legal_entity_group
            WHERE o.id = ?
        ");
        $oi->execute([$orderId]);
        $orderInfo = $oi->fetch();
        if ($orderInfo) {
            try {
                $dowNames = [0=>'Вс',1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб'];
                $dow = (int)date('w', strtotime($orderInfo['delivery_date']));
                $dateStr = ($dowNames[$dow] ?? '') . ', ' . date('d.m', strtotime($orderInfo['delivery_date']));
                $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $fmt = function($q) { $s = number_format((float)$q, 1, '.', ''); return rtrim(rtrim($s, '0'), '.'); };
                $updItems = $pdo->prepare("
                    SELECT oi.sku, oi.product_name, COALESCE(oi.admin_qty, oi.quantity) as qty,
                           p.unit_of_measure
                    FROM so_order_items oi
                    LEFT JOIN products p ON p.sku = oi.sku
                    WHERE oi.order_id = ? AND COALESCE(oi.admin_qty, oi.quantity) > 0
                    ORDER BY oi.product_name
                ");
                $updItems->execute([$orderId]);
                $finalItems = $updItems->fetchAll();
                $lines = ["✏️ <b>Заявка изменена отделом закупок</b>"];
                $lines[] = '';
                $restaurantLabel = roFormatRestaurantTelegramLabel(
                    $orderInfo['restaurant_number'],
                    $orderInfo['city'] ?? '',
                    $orderInfo['address'] ?? '',
                    $orderInfo['legal_entity_group'] ?? getEntityGroup($orderInfo['legal_entity'] ?? '')
                );
                $lines[] = "🏪 <b>Ресторан:</b> " . $esc($restaurantLabel);
                $lines[] = "🏪 <b>Поставщик:</b> " . $esc($orderInfo['supplier_name']);
                $lines[] = "📅 <b>Доставка:</b> {$dateStr}";
                if (empty($finalItems)) {
                    $lines[] = '';
                    $lines[] = '<i>Все позиции удалены.</i>';
                } else {
                    $lines[] = "📋 <b>Позиций:</b> " . count($finalItems);
                    $lines[] = '';
                    $lines[] = '<b>Итоговый состав:</b>';
                    foreach ($finalItems as $it) {
                        $unit = $esc($it['unit_of_measure'] ?? '');
                        $unitStr = $unit !== '' ? " {$unit}" : '';
                        $lines[] = "• <code>" . $esc($it['sku']) . "</code> " . $esc($it['product_name']) . " — <b>" . $fmt($it['qty']) . $unitStr . "</b>";
                    }
                }
                $msg = implode("\n", $lines);
                if (mb_strlen($msg) > 3900) $msg = mb_substr($msg, 0, 3900) . "\n\n…(обрезано)";
                roNotifyRestaurant($pdo, $orderInfo['restaurant_number'], $msg, $orderInfo['legal_entity_group'] ?? getEntityGroup($orderInfo['legal_entity'] ?? ''));
            } catch (Exception $e) { /* не критично */ }
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
                       o.legal_entity,
                       o.restaurant_number, o.delivery_date,
                       r.city, r.address, r.legal_entity_group,
                       s.short_name as supplier_name
                FROM so_order_items oi
                JOIN so_orders o ON o.id = oi.order_id
                JOIN suppliers s ON s.id = o.supplier_id
                LEFT JOIN restaurants r
                  ON r.number = o.restaurant_number
                 AND r.active = 1
                 AND r.legal_entity_group = o.legal_entity_group
                WHERE oi.id = ?
            ");
            $cur->execute([$itemId]);
            $info = $cur->fetch();
            if (!$info) {
                soRespond(['error' => 'Позиция не найдена'], 404);
            }
            soRequireAdminEntityGroupAccess($sessionUser, $info['legal_entity'] ?? '');
            $oldVal = ($info['admin_qty'] !== null) ? (float)$info['admin_qty'] : (float)$info['quantity'];
            $orderId = (int)$info['order_id'];
            $notify = [
                'restaurant_number' => $info['restaurant_number'],
                'legal_entity' => $info['legal_entity'],
                'legal_entity_group' => $info['legal_entity_group'] ?? getEntityGroup($info['legal_entity'] ?? ''),
                'city' => $info['city'] ?? '',
                'address' => $info['address'] ?? '',
                'supplier_name' => $info['supplier_name'],
                'delivery_date' => $info['delivery_date'],
                'sku' => $info['sku'],
                'product_name' => $info['product_name'],
                'old_val' => $oldVal,
                'new_val' => $val,
            ];
            $pdo->prepare("UPDATE so_order_items SET admin_qty = ? WHERE id = ?")->execute([$val, $itemId]);
            $reload = false;
        } elseif ($restNum && $deliveryDate && $sku && $suppId) {
            soRequireAdminSupplierAccess($pdo, $sessionUser, $suppId);
            // Ищем заказ ресторана
            $orderStmt = $pdo->prepare("SELECT id, legal_entity FROM so_orders WHERE supplier_id = ? AND restaurant_number = ? AND delivery_date = ?");
            $orderStmt->execute([$suppId, $restNum, $deliveryDate]);
            $order = $orderStmt->fetch();

            // Создание заявки и позиции — в транзакции, иначе при сбое
            // INSERT-позиции остаётся пустая заявка-сирота.
            $pdo->beginTransaction();
            try {
                if (!$order) {
                    // Создаём заказ за ресторан; юрлицо берём только из данных ресторана — не из тела запроса
                    $le = roGetLegalEntity($pdo, $restNum);
                    if (!$le) {
                        soRespond(['error' => 'Не определено юрлицо ресторана'], 400);
                    }
                    soRequireAdminEntityGroupAccess($sessionUser, $le);
                    $pdo->prepare("INSERT INTO so_orders (restaurant_number, supplier_id, delivery_date, order_date, status, submitted_at, legal_entity)
                        VALUES (?, ?, ?, CURDATE(), 'submitted', NOW(), ?)")
                        ->execute([$restNum, $suppId, $deliveryDate, $le]);
                    $orderId = $pdo->lastInsertId();
                } else {
                    $le = $order['legal_entity'] ?: roGetLegalEntity($pdo, $restNum);
                    soRequireAdminEntityGroupAccess($sessionUser, $le);
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
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                soRespond(['error' => 'Ошибка сохранения правки'], 500);
            }

            // Подтянем название поставщика для уведомления
            $sn = $pdo->prepare("SELECT short_name FROM suppliers WHERE id = ?");
            $sn->execute([$suppId]);
            $supplierName = $sn->fetchColumn() ?: 'поставщику';

            $notify = [
                'restaurant_number' => $restNum,
                'legal_entity' => $le,
                'legal_entity_group' => getEntityGroup($le),
                'city' => '',
                'address' => '',
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

        // Уведомление в Telegram о ручной правке отделом закупок
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

                $by = resolveActorName($pdo, $sessionUser, 'отдел закупок');
                $deliveryFmt = (new DateTime($notify['delivery_date']))->format('d.m.Y');
                $oldStr = $notify['old_val'] > 0 ? ($fmt($notify['old_val']) . $unitStr) : '—';
                $newStr = $notify['new_val'] !== null && $notify['new_val'] > 0 ? ($fmt($notify['new_val']) . $unitStr) : '—';

                $lines = [];
                $lines[] = '✏️ <b>Отдел закупок изменил заявку</b>';
                $lines[] = '';
                if (($notify['city'] ?? '') === '' && ($notify['address'] ?? '') === '') {
                    $restRow = roGetRestaurantRow($pdo, $notify['restaurant_number'], $notify['legal_entity_group'] ?? null);
                    $notify['city'] = $restRow['city'] ?? '';
                    $notify['address'] = $restRow['address'] ?? '';
                }
                $restaurantLabel = roFormatRestaurantTelegramLabel(
                    $notify['restaurant_number'],
                    $notify['city'] ?? '',
                    $notify['address'] ?? '',
                    $notify['legal_entity_group'] ?? getEntityGroup($notify['legal_entity'] ?? '')
                );
                $lines[] = '🏪 <b>Ресторан:</b> ' . $esc($restaurantLabel);
                $lines[] = '🏪 <b>Поставщик:</b> ' . $esc($notify['supplier_name']);
                $lines[] = '📅 <b>Доставка:</b> ' . $deliveryFmt;
                $lines[] = '';
                $lines[] = '<code>' . $esc($notify['sku']) . '</code> ' . $esc($notify['product_name']);
                $lines[] = $oldStr . ' → <b>' . $newStr . '</b>';
                $lines[] = '';
                $lines[] = '<i>Изменил: ' . $esc($by) . '</i>';

                $msg = implode("\n", $lines);
                roNotifyRestaurant($pdo, $notify['restaurant_number'], $msg, $notify['legal_entity_group'] ?? getEntityGroup($notify['legal_entity'] ?? ''));
            } catch (Exception $e) {
                // Уведомление не критично
            }
        }

        // Аудит ручной правки количества
        if ($notify) {
            try {
                $byName = resolveActorName($pdo, $sessionUser, 'отдел закупок');
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
        $oi = $pdo->prepare("SELECT o.restaurant_number, o.delivery_date, o.legal_entity, o.status, s.short_name as supplier_name FROM so_orders o JOIN suppliers s ON s.id = o.supplier_id WHERE o.id = ?");
        $oi->execute([$orderId]);
        $orderInfo = $oi->fetch();
        if (!$orderInfo) soRespond(['error' => 'Заявка не найдена'], 404);
        // Проверка доступа к юр. лицу заявки
        if ($sessionUser && !checkLegalEntityAccess($sessionUser, $orderInfo['legal_entity'] ?? '')) {
            soRespond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        }
        // Нельзя удалить заблокированную заявку
        if (($orderInfo['status'] ?? '') === 'locked') {
            soRespond(['error' => 'Заблокированную заявку удалить нельзя. Сначала снимите блокировку.'], 403);
        }

        $pdo->prepare("DELETE FROM so_order_items WHERE order_id = ?")->execute([$orderId]);
        $pdo->prepare("DELETE FROM so_orders WHERE id = ?")->execute([$orderId]);

        $by = resolveActorName($pdo, $sessionUser);

        // Уведомляем ресторан
        if ($orderInfo) {
            $dowNames = [0=>'Вс',1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб'];
            $dow = (int)date('w', strtotime($orderInfo['delivery_date']));
            $dateStr = ($dowNames[$dow] ?? '') . ', ' . date('d.m', strtotime($orderInfo['delivery_date']));
            roNotifyRestaurant($pdo, $orderInfo['restaurant_number'],
                "❌ Рест. {$orderInfo['restaurant_number']} — заявка {$orderInfo['supplier_name']} на {$dateStr} удалена ({$by}).",
                getEntityGroup($orderInfo['legal_entity'] ?? ''));
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
        soRequireAdminSupplierAccess($pdo, $sessionUser, $supplierId);

        $s = $pdo->prepare("
            SELECT ss.id, ss.order_day, ss.delivery_day, ss.is_active,
                   r.number as restaurant_number, r.region, r.city, r.address
            FROM supplier_schedules ss
            JOIN restaurants r ON r.id = ss.restaurant_id AND r.active = 1
            WHERE ss.supplier_id = ?
            ORDER BY r.region, CAST(r.number AS UNSIGNED), ss.order_day
        ");
        $s->execute([$supplierId]);
        $schedules = $s->fetchAll();
        $tempPeriod = soGetTempSchedulePeriod($pdo, $supplierId);
        $temporarySchedule = null;
        if ($tempPeriod) {
            $tsi = $pdo->prepare("
                SELECT ssi.id, ssi.order_day, ssi.delivery_day, ssi.is_active,
                       r.number as restaurant_number, r.region, r.city, r.address
                FROM so_supplier_temp_schedule_items ssi
                JOIN restaurants r ON r.id = ssi.restaurant_id AND r.active = 1
                WHERE ssi.period_id = ?
                ORDER BY r.region, CAST(r.number AS UNSIGNED), ssi.order_day
            ");
            $tsi->execute([(int)$tempPeriod['id']]);
            $temporarySchedule = [
                'date_from' => $tempPeriod['date_from'],
                'date_to' => $tempPeriod['date_to'],
                'items' => $tsi->fetchAll(),
            ];
        }
        // Также подгружаем правила дедлайнов
        $dr = $pdo->prepare("SELECT delivery_dow, deadline_dow, deadline_time FROM supplier_default_deadlines WHERE supplier_id = ? ORDER BY delivery_dow");
        $dr->execute([$supplierId]);
        // lockVersion — MAX(updated_at) по расписанию поставщика; используется для оптимистической блокировки в POST.
        $lvStmt = $pdo->prepare("SELECT MAX(updated_at) FROM supplier_schedules WHERE supplier_id = ?");
        $lvStmt->execute([$supplierId]);
        $lockVersion = $lvStmt->fetchColumn() ?: null;
        soRespond(['schedules' => $schedules, 'temporary_schedule' => $temporarySchedule, 'deadline_rules' => $dr->fetchAll(), 'lockVersion' => $lockVersion]);
    }

    // --- Сохранение графиков ---
    if ($adminAction === 'schedules' && $method === 'POST') {
        $supplierId = $body['supplier_id'] ?? '';
        $schedules = $body['schedules'] ?? [];
        $temporarySchedule = $body['temporary_schedule'] ?? null;

        soRequireAdminSupplierAccess($pdo, $sessionUser, $supplierId);

        // Оптимистическая блокировка: если фронт прислал lockVersion — сверяем с текущим MAX(updated_at).
        // Это защита от ситуации, когда двое одновременно открыли расписание и последний затёр первого.
        $clientLockVersion = array_key_exists('lockVersion', $body) ? ($body['lockVersion'] ?? null) : false;
        if ($clientLockVersion !== false && $clientLockVersion !== null) {
            $lvNow = $pdo->prepare("SELECT MAX(updated_at) FROM supplier_schedules WHERE supplier_id = ?");
            $lvNow->execute([$supplierId]);
            $currentLockVersion = $lvNow->fetchColumn() ?: null;
            if ($currentLockVersion !== null && $clientLockVersion !== $currentLockVersion) {
                soRespond(['error' => 'Расписание изменено другим пользователем. Перезагрузите страницу.'], 409);
            }
        }

        $updatedBy = resolveActorName($pdo, $sessionUser);

        $pdo->beginTransaction();
        try {
            // Сначала деактивируем все расписания поставщика, потом активируем присланные
            $pdo->prepare("UPDATE supplier_schedules SET is_active = 0, updated_at = NOW(), updated_by = ? WHERE supplier_id = ?")->execute([$updatedBy, $supplierId]);

            // Upsert
            $upsert = $pdo->prepare("
                INSERT INTO supplier_schedules (supplier_id, restaurant_id, order_day, delivery_day, is_active, updated_at, updated_by)
                VALUES (?, ?, ?, ?, ?, NOW(), ?)
                ON DUPLICATE KEY UPDATE
                    order_day = VALUES(order_day),
                    delivery_day = VALUES(delivery_day),
                    is_active = VALUES(is_active),
                    updated_at = NOW(),
                    updated_by = VALUES(updated_by)
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

            // Физически удаляем записи, не вошедшие в новый набор,
            // чтобы таблица не распухала от soft-off мусора.
            $pdo->prepare("DELETE FROM supplier_schedules WHERE supplier_id = ? AND is_active = 0")
                ->execute([$supplierId]);

            $tempDateFrom = trim((string)($temporarySchedule['date_from'] ?? ''));
            $tempDateTo = trim((string)($temporarySchedule['date_to'] ?? ''));
            $tempItems = is_array($temporarySchedule['items'] ?? null) ? $temporarySchedule['items'] : [];
            if (($tempDateFrom && !$tempDateTo) || (!$tempDateFrom && $tempDateTo)) {
                throw new InvalidArgumentException('Для временного графика нужно указать обе даты периода');
            }
            if ($tempDateFrom && $tempDateTo && $tempDateFrom > $tempDateTo) {
                throw new InvalidArgumentException('Дата окончания временного графика раньше даты начала');
            }

            if ($tempDateFrom && $tempDateTo && !empty($tempItems)) {
                $tempUpsert = $pdo->prepare("
                    INSERT INTO so_supplier_temp_schedule_periods (supplier_id, date_from, date_to, updated_at, updated_by)
                    VALUES (?, ?, ?, NOW(), ?)
                    ON DUPLICATE KEY UPDATE
                        date_from = VALUES(date_from),
                        date_to = VALUES(date_to),
                        updated_at = NOW(),
                        updated_by = VALUES(updated_by)
                ");
                $tempUpsert->execute([$supplierId, $tempDateFrom, $tempDateTo, $updatedBy]);

                $tempPeriod = soGetTempSchedulePeriod($pdo, $supplierId);
                $periodId = (int)($tempPeriod['id'] ?? 0);
                if ($periodId <= 0) {
                    throw new RuntimeException('Не удалось сохранить временный график');
                }

                $pdo->prepare("DELETE FROM so_supplier_temp_schedule_items WHERE period_id = ?")->execute([$periodId]);
                $tempItemIns = $pdo->prepare("
                    INSERT INTO so_supplier_temp_schedule_items (period_id, restaurant_id, order_day, delivery_day, is_active, updated_at, updated_by)
                    VALUES (?, ?, ?, ?, ?, NOW(), ?)
                ");
                foreach ($tempItems as $sch) {
                    $restId = $sch['restaurant_id'] ?? null;
                    if (!$restId) continue;
                    $tempItemIns->execute([
                        $periodId,
                        (int)$restId,
                        (int)($sch['order_day'] ?? 1),
                        (int)($sch['delivery_day'] ?? 2),
                        (int)($sch['is_active'] ?? 1),
                        $updatedBy,
                    ]);
                }
            } else {
                $existingTemp = soGetTempSchedulePeriod($pdo, $supplierId);
                if ($existingTemp) {
                    $pdo->prepare("DELETE FROM so_supplier_temp_schedule_periods WHERE id = ?")
                        ->execute([(int)$existingTemp['id']]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        soRespond(['success' => true, 'updated' => $count]);
    }

    // --- Только временный график (без перезаписи основного) ---
    if ($adminAction === 'temp-schedule' && $method === 'POST') {
        $supplierId = $body['supplier_id'] ?? '';
        soRequireAdminSupplierAccess($pdo, $sessionUser, $supplierId);

        $tempDateFrom = trim((string)($body['date_from'] ?? ''));
        $tempDateTo   = trim((string)($body['date_to']   ?? ''));
        $tempItems    = is_array($body['items'] ?? null) ? $body['items'] : [];

        if (($tempDateFrom && !$tempDateTo) || (!$tempDateFrom && $tempDateTo)) {
            soRespond(['error' => 'Для временного графика нужно указать обе даты периода'], 400);
        }
        if ($tempDateFrom && $tempDateTo && $tempDateFrom > $tempDateTo) {
            soRespond(['error' => 'Дата окончания временного графика раньше даты начала'], 400);
        }

        $updatedBy = resolveActorName($pdo, $sessionUser);
        $pdo->beginTransaction();
        try {
            if ($tempDateFrom && $tempDateTo && !empty($tempItems)) {
                $upsert = $pdo->prepare("
                    INSERT INTO so_supplier_temp_schedule_periods (supplier_id, date_from, date_to, updated_at, updated_by)
                    VALUES (?, ?, ?, NOW(), ?)
                    ON DUPLICATE KEY UPDATE
                        date_from = VALUES(date_from),
                        date_to = VALUES(date_to),
                        updated_at = NOW(),
                        updated_by = VALUES(updated_by)
                ");
                $upsert->execute([$supplierId, $tempDateFrom, $tempDateTo, $updatedBy]);

                $period = soGetTempSchedulePeriod($pdo, $supplierId);
                $periodId = (int)($period['id'] ?? 0);
                if ($periodId <= 0) throw new RuntimeException('Не удалось сохранить период');

                $pdo->prepare("DELETE FROM so_supplier_temp_schedule_items WHERE period_id = ?")->execute([$periodId]);
                $ins = $pdo->prepare("
                    INSERT INTO so_supplier_temp_schedule_items (period_id, restaurant_id, order_day, delivery_day, is_active, updated_at, updated_by)
                    VALUES (?, ?, ?, ?, ?, NOW(), ?)
                ");
                foreach ($tempItems as $sch) {
                    $restId = $sch['restaurant_id'] ?? null;
                    if (!$restId) continue;
                    $ins->execute([
                        $periodId,
                        (int)$restId,
                        (int)($sch['order_day'] ?? 1),
                        (int)($sch['delivery_day'] ?? 2),
                        (int)($sch['is_active'] ?? 1),
                        $updatedBy,
                    ]);
                }
            } else {
                // Если даты не указаны — удаляем существующий период (если есть)
                $existing = soGetTempSchedulePeriod($pdo, $supplierId);
                if ($existing) {
                    $pdo->prepare("DELETE FROM so_supplier_temp_schedule_periods WHERE id = ?")
                        ->execute([(int)$existing['id']]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            soRespond(['error' => 'Не удалось сохранить временный график: ' . $e->getMessage()], 500);
        }

        // Уведомление ресторанам (опционально, если notify=1)
        $notifyResult = ['sent_tg' => 0, 'sent_push' => 0, 'restaurants' => 0];
        if (!empty($body['notify'])) {
            try {
                require_once __DIR__ . '/push_send.php';
                $notifyResult = soNotifyTempScheduleChanged($pdo, $supplierId, $tempDateFrom, $tempDateTo, $tempItems);
            } catch (Throwable $e) {
                error_log('[temp-schedule notify] err=' . $e->getMessage());
            }
        }
        soRespond(['success' => true, 'notify' => $notifyResult]);
    }

    // --- Правила дедлайнов ---
    if ($adminAction === 'deadline-rules' && $method === 'GET') {
        $supplierId = $_GET['supplier_id'] ?? '';
        soRequireAdminSupplierAccess($pdo, $sessionUser, $supplierId);
        $s = $pdo->prepare("SELECT id, delivery_dow, deadline_dow, deadline_time FROM supplier_default_deadlines WHERE supplier_id = ? ORDER BY delivery_dow");
        $s->execute([$supplierId]);
        soRespond(['rules' => $s->fetchAll()]);
    }

    if ($adminAction === 'deadline-rules' && $method === 'POST') {
        $supplierId = $body['supplier_id'] ?? '';
        $rules = $body['rules'] ?? [];
        soRequireAdminSupplierAccess($pdo, $sessionUser, $supplierId);
        $by = resolveActorName($pdo, $sessionUser);
        // Очищаем и перезаписываем в транзакции
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM supplier_default_deadlines WHERE supplier_id = ?")->execute([$supplierId]);
            $ins = $pdo->prepare("INSERT INTO supplier_default_deadlines (supplier_id, delivery_dow, deadline_dow, deadline_time) VALUES (?, ?, ?, ?)");
            $inserted = 0;
            foreach ($rules as $r) {
                $dow = (int)($r['delivery_dow'] ?? 0);
                if (!in_array($dow, [1,2,3,4,5,6,7], true)) continue;
                $ddow = (int)($r['deadline_dow'] ?? $dow);
                if (!in_array($ddow, [1,2,3,4,5,6,7], true)) $ddow = $dow;
                $dt = $r['deadline_time'] ?? '14:00:00';
                if (preg_match('/^(\d{1,2}):(\d{2})$/', $dt, $m)) {
                    $dt = sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
                } elseif (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $dt)) {
                    $dt = '14:00:00';
                }
                $ins->execute([$supplierId, $dow, $ddow, $dt]);
                $inserted++;
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            soRespond(['error' => 'Ошибка сохранения правил дедлайнов: ' . $e->getMessage()], 500);
        }
        try {
            auditLog($pdo, 'so_deadline_rules_updated', 'supplier', $supplierId, $by, [
                'supplier_id' => $supplierId,
                'rules_count' => $inserted,
            ]);
        } catch (Exception $e) { /* не критично */ }
        soRespond(['success' => true, 'count' => $inserted]);
    }

    // --- Разовое продление дедлайна на конкретную дату доставки ---
    if ($adminAction === 'extend-deadline' && $method === 'POST') {
        $supplierId = $body['supplier_id'] ?? null;
        $deliveryDate = $body['delivery_date'] ?? '';
        $deadlineDate = $body['deadline_date'] ?? '';
        $deadlineTime = $body['deadline_time'] ?? '';

        if (!$supplierId || !$deliveryDate || !$deadlineTime) {
            soRespond(['error' => 'Не указаны поставщик, дата доставки или новое время'], 400);
        }
        soRequireAdminSupplierAccess($pdo, $sessionUser, $supplierId);
        if ($deadlineDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadlineDate)) {
            soRespond(['error' => 'Неверный формат даты дедлайна'], 400);
        }
        if (!$deadlineDate) {
            $currentDeadline = soCalculateDeadline($pdo, $supplierId, $deliveryDate);
            $deadlineDate = $currentDeadline['deadline'] ? substr($currentDeadline['deadline'], 0, 10) : date('Y-m-d', strtotime($deliveryDate . ' -1 day'));
        }
        // Нормализуем время к формату HH:MM:SS
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $deadlineTime, $m)) {
            $deadlineTime = sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
        } elseif (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $deadlineTime)) {
            soRespond(['error' => 'Неверный формат времени, используйте HH:MM'], 400);
        }

        $createdBy = resolveActorName($pdo, $sessionUser);
        $pdo->prepare("INSERT INTO so_deadline_overrides (supplier_id, delivery_date, deadline_date, deadline_time, is_closed, created_by) VALUES (?, ?, ?, ?, 0, ?)
                       ON DUPLICATE KEY UPDATE deadline_date = VALUES(deadline_date), deadline_time = VALUES(deadline_time), is_closed = 0, created_by = VALUES(created_by)")
            ->execute([$supplierId, $deliveryDate, $deadlineDate, $deadlineTime, $createdBy]);

        // Приём на эту дату снова открыт — снимаем авто-блокировку с заявок,
        // которые заблокировались после прошедшего дедлайна (только 'locked' → 'submitted';
        // отредактированные закупщиком 'edited' не трогаем).
        $pdo->prepare("UPDATE so_orders SET status = 'submitted', updated_at = NOW()
                       WHERE supplier_id = ? AND delivery_date = ? AND status = 'locked'")
            ->execute([$supplierId, $deliveryDate]);

        try {
            auditLog($pdo, 'so_deadline_extended', 'supplier', $supplierId, $createdBy, [
                'delivery_date' => $deliveryDate,
                'deadline_date' => $deadlineDate,
                'deadline_time' => $deadlineTime,
            ]);
        } catch (Exception $e) { /* не критично */ }

        soRespond(['success' => true, 'supplier_id' => $supplierId, 'delivery_date' => $deliveryDate, 'deadline_date' => $deadlineDate, 'deadline_time' => $deadlineTime]);
    }

    // --- Удалить разовое продление дедлайна ---
    if ($adminAction === 'remove-deadline-override' && $method === 'POST') {
        $supplierId = $body['supplier_id'] ?? null;
        $deliveryDate = $body['delivery_date'] ?? '';
        if (!$supplierId || !$deliveryDate) soRespond(['error' => 'Не указан поставщик или дата'], 400);
        soRequireAdminSupplierAccess($pdo, $sessionUser, $supplierId);
        $pdo->prepare("DELETE FROM so_deadline_overrides WHERE supplier_id = ? AND delivery_date = ?")
            ->execute([$supplierId, $deliveryDate]);
        soRespond(['success' => true]);
    }

    // --- Закрыть / открыть день доставки для подачи заявок ---
    if ($adminAction === 'close-day' && $method === 'POST') {
        $supplierId = $body['supplier_id'] ?? null;
        $deliveryDate = $body['delivery_date'] ?? '';
        $isClosed = isset($body['is_closed']) ? (int)(bool)$body['is_closed'] : 1;
        if (!$supplierId || !$deliveryDate) soRespond(['error' => 'Не указан поставщик или дата'], 400);
        soRequireAdminSupplierAccess($pdo, $sessionUser, $supplierId);
        $createdBy = resolveActorName($pdo, $sessionUser);
        if ($isClosed) {
            $pdo->prepare("INSERT INTO so_deadline_overrides (supplier_id, delivery_date, deadline_date, deadline_time, is_closed, created_by)
                           VALUES (?, ?, NULL, NULL, 1, ?)
                           ON DUPLICATE KEY UPDATE is_closed = 1, created_by = VALUES(created_by)")
                ->execute([$supplierId, $deliveryDate, $createdBy]);
        } else {
            // Снять принудительное закрытие — если была только закрытая запись, удаляем её
            $pdo->prepare("DELETE FROM so_deadline_overrides WHERE supplier_id = ? AND delivery_date = ? AND is_closed = 1 AND (deadline_time IS NULL OR deadline_time = '')")
                ->execute([$supplierId, $deliveryDate]);
            // Если была запись с продлением дедлайна — сбрасываем is_closed
            $pdo->prepare("UPDATE so_deadline_overrides SET is_closed = 0 WHERE supplier_id = ? AND delivery_date = ?")
                ->execute([$supplierId, $deliveryDate]);
        }

        try {
            auditLog($pdo, $isClosed ? 'so_day_closed' : 'so_day_reopened', 'supplier', $supplierId, $createdBy, [
                'delivery_date' => $deliveryDate,
            ]);
        } catch (Exception $e) { /* не критично */ }

        soRespond(['success' => true]);
    }

    // --- Шаблоны товаров ---
    if ($adminAction === 'templates' && $method === 'GET') {
        $supplierId = $_GET['supplier_id'] ?? '';
        $le = $_GET['legal_entity'] ?? '';
        if (!$le) soRespond(['error' => 'Не указано юрлицо'], 400);
        soRequireAdminSupplierAccess($pdo, $sessionUser, $supplierId);
        soRequireAdminEntityGroupAccess($sessionUser, $le);

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
        $le = $body['legal_entity'] ?? '';
        if (!$le) soRespond(['error' => 'Не указано юрлицо'], 400);
        $items = $body['items'] ?? [];

        soRequireAdminSupplierAccess($pdo, $sessionUser, $supplierId);
        soRequireAdminEntityGroupAccess($sessionUser, $le);

        $count = 0;
        $pdo->beginTransaction();
        try {
            // Деактивируем все текущие
            $pdo->prepare("UPDATE so_templates SET is_active = 0 WHERE supplier_id = ? AND legal_entity = ?")
                ->execute([$supplierId, $le]);

            $upsert = $pdo->prepare("
                INSERT INTO so_templates (supplier_id, legal_entity, product_id, sku, product_name, sort_order, multiplicity, min_qty, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE product_name = VALUES(product_name), sort_order = VALUES(sort_order),
                  multiplicity = VALUES(multiplicity), min_qty = VALUES(min_qty), is_active = 1, product_id = VALUES(product_id)
            ");

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

            // Физически удаляем SKU, выпавшие из шаблона.
            $pdo->prepare("DELETE FROM so_templates WHERE supplier_id = ? AND legal_entity = ? AND is_active = 0")
                ->execute([$supplierId, $le]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        try {
            $by = resolveActorName($pdo, $sessionUser);
            auditLog($pdo, 'so_template_saved', 'supplier', $supplierId, $by, [
                'legal_entity' => $le,
                'items_count' => $count,
            ]);
        } catch (Exception $e) { /* не критично */ }

        soRespond(['success' => true, 'count' => $count]);
    }

    // --- Ручная отправка сводки подписчикам ---
    if ($adminAction === 'send-summary' && $method === 'POST') {
        $supplierId = $body['supplier_id'] ?? '';
        $deliveryDate = $body['delivery_date'] ?? '';
        if (!$supplierId || !$deliveryDate) soRespond(['error' => 'Не указан поставщик или дата'], 400);
        soRequireAdminSupplierAccess($pdo, $sessionUser, $supplierId);

        $sum = soBuildSummaryXlsx($pdo, $supplierId, $deliveryDate);
        if ($sum['status'] === 'closed')      soRespond(['error' => 'Дата доставки закрыта'], 400);
        if ($sum['status'] === 'no_schedule') soRespond(['error' => 'Нет ресторанов в графике на этот день'], 400);
        if ($sum['status'] === 'xlsx_error')  soRespond(['error' => 'Не удалось сгенерировать Excel: ' . $sum['error']], 500);

        // Подписчики Telegram
        $subsStmt = $pdo->prepare("
            SELECT u.name, u.telegram_chat_id FROM so_supplier_summary_subscribers sss
            JOIN users u ON u.name = sss.user_name
            WHERE sss.supplier_id = ? AND u.telegram_chat_id IS NOT NULL AND u.telegram_chat_id != ''");
        $subsStmt->execute([$supplierId]);
        $subs = $subsStmt->fetchAll();
        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if (!$subs) soRespond(['error' => 'Нет подписчиков для этого поставщика'], 400);
        if (!$botToken) soRespond(['error' => 'Telegram Bot Token не настроен'], 500);

        $supName = $sum['supplier']['short_name'];
        $dateFmt = $sum['date_fmt'];
        $deliveryDow = (int)(new DateTime($deliveryDate))->format('N');
        $dayNames = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'];
        $dayShort = $dayNames[$deliveryDow] ?? '';
        $dedupKey = "so_summary_{$supplierId}_{$deliveryDate}";
        $perUser = $pdo->prepare("INSERT INTO tg_notification_log (notification_type, legal_entity, chat_id, notification_key) VALUES (?, '', ?, ?)");

        if ($sum['status'] === 'empty') {
            $caption = "⚠️ <b>Никто не подал заявку</b>\n"
                . "📦 Поставщик: <b>" . htmlspecialchars($supName, ENT_QUOTES) . "</b>\n"
                . "📅 Доставка: <b>{$dateFmt} ({$dayShort})</b>\n"
                . "🏪 Ресторанов по графику: <b>{$sum['restaurants_count']}</b>";
            $sentCount = 0;
            foreach ($subs as $sub) {
                $ok = sendTelegramMessage($botToken, $sub['telegram_chat_id'], $caption);
                $perUser->execute([$ok ? 'so_summary_sent' : 'so_summary_fail', $sub['telegram_chat_id'], $dedupKey]);
                if ($ok) $sentCount++;
            }
            $pdo->prepare("INSERT INTO tg_notification_log (notification_type, legal_entity, chat_id, notification_key) VALUES ('so_summary', '', 0, ?)")->execute([$dedupKey]);
            soRespond(['success' => true, 'sent' => $sentCount, 'total_subs' => count($subs), 'mode' => 'text_only']);
        }

        $missingCount = $sum['restaurants_count'] - $sum['submitted_count'];
        $caption = "🧾 <b>Заказ поставщику</b> (повторная отправка)\n"
            . "📦 Поставщик: <b>" . htmlspecialchars($supName, ENT_QUOTES) . "</b>\n"
            . "📅 Доставка: <b>{$dateFmt} ({$dayShort})</b>\n\n"
            . "✅ Подали: <b>{$sum['submitted_count']}</b> из <b>{$sum['restaurants_count']}</b>\n";
        if ($missingCount > 0) $caption .= "❌ Не подали: <b>{$missingCount}</b>\n";
        $colTotals = $sum['col_totals'];
        arsort($colTotals);
        $topProducts = array_slice($colTotals, 0, 5, true);
        if ($topProducts) {
            $caption .= "\n📊 <b>Итого по товарам:</b>\n";
            foreach ($topProducts as $sku => $tot) {
                if ($tot <= 0) continue;
                $name = $sum['products_map'][$sku]['name'] ?? $sku;
                $caption .= "• " . htmlspecialchars($name, ENT_QUOTES) . " — <b>" . rtrim(rtrim(number_format($tot, 2, '.', ''), '0'), '.') . "</b>\n";
            }
            if (count($colTotals) > 5) $caption .= "… и ещё " . (count($colTotals) - 5) . " позиций в файле";
        }

        $sentCount = 0;
        foreach ($subs as $sub) {
            $ok = sendTelegramDocument($botToken, $sub['telegram_chat_id'], $sum['filename'], $sum['xlsx'], $caption);
            $perUser->execute([$ok ? 'so_summary_sent' : 'so_summary_fail', $sub['telegram_chat_id'], $dedupKey]);
            if ($ok) $sentCount++;
        }
        $pdo->prepare("INSERT INTO tg_notification_log (notification_type, legal_entity, chat_id, notification_key) VALUES ('so_summary', '', 0, ?)")->execute([$dedupKey]);
        soRespond(['success' => true, 'sent' => $sentCount, 'total_subs' => count($subs)]);
    }

    // --- Экспорт ---
    if ($adminAction === 'export' && $method === 'GET') {
        $supplierId = $_GET['supplier_id'] ?? '';
        $date = $_GET['date'] ?? '';

        if (!$supplierId || !$date) soRespond(['error' => 'Не указан поставщик или дата'], 400);
        soRequireAdminSupplierAccess($pdo, $sessionUser, $supplierId);

        // Все заявки на эту дату (без session_id), только доступные юрлица
        $exportWhere = ['o.supplier_id = ?', 'o.delivery_date = ?'];
        $exportParams = [$supplierId, $date];
        soAppendAllowedOrderEntityFilter($sessionUser, $exportWhere, $exportParams, 'o.legal_entity');
        $s = $pdo->prepare("
            SELECT o.restaurant_number, o.status, o.submitted_at,
                   r.region, r.address,
                   oi.sku, oi.product_name, oi.quantity, oi.admin_qty,
                   COALESCE(oi.admin_qty, oi.quantity) as effective_qty,
                   CASE WHEN asl.id IS NULL THEN 0 ELSE 1 END AS is_auto_submitted,
                   asl.source_order_id AS auto_source_order_id,
                   src.delivery_date AS auto_source_delivery_date
            FROM so_orders o
            JOIN restaurants r ON r.number = o.restaurant_number AND r.active = 1
            JOIN so_order_items oi ON oi.order_id = o.id
            LEFT JOIN so_auto_submit_log asl ON asl.new_order_id = o.id
            LEFT JOIN so_orders src ON src.id = asl.source_order_id
            WHERE " . implode(' AND ', $exportWhere) . "
            ORDER BY r.region, r.number, oi.product_name
        ");
        $s->execute($exportParams);
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
