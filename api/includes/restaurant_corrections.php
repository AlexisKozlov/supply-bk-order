<?php
/**
 * API корректировок основной поставки для кабинета ресторана.
 *
 * Все маршруты требуют ресторанной сессии (X-RO-Token / cookie).
 * Принимаются только корректировки до дедлайна (см. corrGetNextDeliveries).
 *
 * Маршруты:
 *   GET    restaurant-corrections/deliveries          — ближайшие даты поставок (дедлайн ещё впереди)
 *   GET    restaurant-corrections/list?date=YYYY-MM-DD — корректировки ресторана на дату, сгруппированы по батчам
 *   GET    restaurant-corrections/products?q=...      — поиск товаров для автодополнения (топ 20)
 *   POST   restaurant-corrections/save                — создать новый батч  { delivery_date, items[], comment? }
 *   POST   restaurant-corrections/update              — изменить pending-батч { batch_uuid, items[], comment? }
 *   POST   restaurant-corrections/cancel              — отозвать pending-батч { batch_uuid }
 *
 * Синхронизация с Telegram:
 *   — Уведомление закупкам в TG отправляется единой функцией corrNotifyPurchasersBatch
 *     (та же, что вызывается из бота). Текст и кнопки «Принять / Отклонить» идентичные.
 *   — При edit/cancel сообщения у закупок перерисовываются через corrUpdateAllReviewMessages.
 */

if ($endpoint !== 'restaurant-corrections') return;

require_once __DIR__ . '/bot_rest.php';

function rcRespond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function rcGenUuid() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ═══ Авторизация ресторана ═══
$rcUser = roGetRestaurantSession($pdo);
if (!$rcUser) rcRespond(['error' => 'Требуется авторизация ресторана'], 401);
$rcRestNum = (int)$rcUser['restaurant_number'];
$rcGroup = $rcUser['legal_entity_group'] ?? 'BK_VM';

// ═══ Параметры лимитов и валидации ═══
const RC_MAX_ITEMS = 30;
const RC_MAX_COMMENT_LEN = 1000;
const RC_MAX_NAME_LEN = 255;
const RC_MAX_QTY = 99999;
const RC_VALID_UNITS = ['кор.', 'шт.'];
const RC_VALID_ACTIONS = ['add', 'remove'];

function rcValidateDeliveryDate($pdo, $restNum, $date) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    $deliveries = corrGetNextDeliveries($pdo, $restNum, 10);
    foreach ($deliveries as $d) {
        if ($d['date'] === $date) return $d;
    }
    return false;
}

function rcNormalizeItems($items) {
    if (!is_array($items)) return [['error' => 'Список позиций должен быть массивом']];
    if (!count($items)) return [['error' => 'Нет ни одной позиции']];
    if (count($items) > RC_MAX_ITEMS) return [['error' => 'Слишком много позиций (макс ' . RC_MAX_ITEMS . ')']];
    $clean = [];
    foreach ($items as $i => $raw) {
        if (!is_array($raw)) return [['error' => "Позиция #" . ($i + 1) . " неверного формата"]];
        $action = trim((string)($raw['action'] ?? ''));
        if (!in_array($action, RC_VALID_ACTIONS, true)) {
            return [['error' => "Позиция #" . ($i + 1) . ": действие должно быть add или remove"]];
        }
        $sku = trim((string)($raw['sku'] ?? '-'));
        if ($sku === '') $sku = '-';
        $sku = mb_substr($sku, 0, 50);
        $name = trim((string)($raw['name'] ?? ''));
        if ($name === '') return [['error' => "Позиция #" . ($i + 1) . ": укажите название товара"]];
        if (mb_strlen($name) > RC_MAX_NAME_LEN) $name = mb_substr($name, 0, RC_MAX_NAME_LEN);
        $qty = (float)($raw['qty'] ?? 0);
        if ($qty <= 0) return [['error' => "Позиция #" . ($i + 1) . ": количество должно быть больше нуля"]];
        if ($qty > RC_MAX_QTY) return [['error' => "Позиция #" . ($i + 1) . ": слишком большое количество"]];
        $unit = trim((string)($raw['unit'] ?? 'кор.'));
        if (!in_array($unit, RC_VALID_UNITS, true)) $unit = 'кор.';
        $clean[] = ['action' => $action, 'sku' => $sku, 'name' => $name, 'qty' => $qty, 'unit' => $unit];
    }
    return $clean;
}

// ═══════════════════════════════════════════════════════════════
// GET restaurant-corrections/deliveries
// Возвращает ближайшие даты поставки, на которые ещё можно подать корректировку.
// ═══════════════════════════════════════════════════════════════
if ($subpoint === 'deliveries' && $method === 'GET') {
    $deliveries = corrGetNextDeliveries($pdo, $rcRestNum, 5);
    $out = [];
    foreach ($deliveries as $d) {
        $out[] = [
            'date' => $d['date'],
            'date_fmt' => $d['date_fmt'],
            'deadline' => $d['deadline']->format('Y-m-d H:i:s'),
            'deadline_fmt' => $d['deadline_fmt'],
            'delivery_time' => $d['time'],
        ];
    }
    rcRespond(['deliveries' => $out]);
}

// ═══════════════════════════════════════════════════════════════
// GET restaurant-corrections/list?date=YYYY-MM-DD
// Список корректировок ресторана на дату, сгруппированы по батчам.
// Если date не задан — все корректировки ресторана (история, до 100 батчей).
// ═══════════════════════════════════════════════════════════════
if ($subpoint === 'list' && $method === 'GET') {
    $date = trim((string)($_GET['date'] ?? ''));
    $params = [$rcRestNum];
    $sql = "SELECT * FROM order_corrections WHERE restaurant_number = ?";
    if ($date !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) rcRespond(['error' => 'Неверный формат даты'], 400);
        $sql .= " AND delivery_date = ?";
        $params[] = $date;
    }
    $sql .= " ORDER BY created_at DESC, id ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    // Группируем по batch: либо по batch_uuid (новые), либо по
    // (restaurant_chat_id, delivery_date, created_at - в окне 30 сек) для TG-записей.
    $batches = [];
    $order = [];
    foreach ($rows as $r) {
        if (!empty($r['batch_uuid'])) {
            $key = 'uuid:' . $r['batch_uuid'];
        } else {
            // Старый TG-формат: батч определяется чатом + датой + ближайшим временем.
            $key = 'tg:' . ($r['restaurant_chat_id'] ?? '0') . ':' . $r['delivery_date'] . ':' . substr($r['created_at'], 0, 16);
        }
        if (!isset($batches[$key])) {
            $batches[$key] = [
                'batch_uuid' => $r['batch_uuid'] ?: null,
                'source' => $r['submitter_source'],
                'delivery_date' => $r['delivery_date'],
                'submitter_name' => $r['submitter_name'],
                'comment' => $r['submitter_comment'],
                'created_at' => $r['created_at'],
                'items' => [],
                'has_pending_only' => true,
            ];
            $order[] = $key;
        }
        $batches[$key]['items'][] = [
            'id' => (int)$r['id'],
            'action' => $r['action'],
            'sku' => $r['product_sku'],
            'name' => $r['product_name'],
            'qty' => (float)$r['quantity'],
            'unit' => $r['unit_of_measure'],
            'status' => $r['status'],
            'reviewer_name' => $r['reviewer_name'],
            'review_comment' => $r['review_comment'],
            'reviewed_at' => $r['reviewed_at'],
        ];
        if ($r['status'] !== 'pending') $batches[$key]['has_pending_only'] = false;
    }
    $out = [];
    foreach ($order as $k) {
        $b = $batches[$k];
        $b['can_edit'] = ($b['source'] === 'cabinet' && !empty($b['batch_uuid']) && $b['has_pending_only']);
        $b['can_cancel'] = $b['can_edit'];
        unset($b['has_pending_only']);
        $out[] = $b;
    }
    rcRespond(['batches' => $out]);
}

// ═══════════════════════════════════════════════════════════════
// GET restaurant-corrections/products?q=...
// Поиск товаров для автодополнения. Возвращает топ 20.
// ═══════════════════════════════════════════════════════════════
if ($subpoint === 'products' && $method === 'GET') {
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) rcRespond(['products' => []]);
    // Точное совпадение по SKU вперёд, потом частичное по name.
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $st = $pdo->prepare("
        SELECT sku, name, unit_of_measure
        FROM products
        WHERE is_active = 1
          AND legal_entity_group = ?
          AND (sku = ? OR sku LIKE ? OR name LIKE ?)
        ORDER BY (sku = ?) DESC, (sku LIKE ?) DESC, name ASC
        LIMIT 20
    ");
    $exact = $q;
    $skuLike = $q . '%';
    $st->execute([$rcGroup, $exact, $skuLike, $like, $exact, $skuLike]);
    $rows = $st->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'sku' => $r['sku'],
            'name' => $r['name'],
            'default_unit' => ($r['unit_of_measure'] === 'шт' || $r['unit_of_measure'] === 'шт.') ? 'шт.' : 'кор.',
        ];
    }
    rcRespond(['products' => $out]);
}

// ═══════════════════════════════════════════════════════════════
// POST restaurant-corrections/save
// Body: { delivery_date, items: [{action, sku?, name, qty, unit}], comment? }
// ═══════════════════════════════════════════════════════════════
if ($subpoint === 'save' && $method === 'POST') {
    $date = trim((string)($body['delivery_date'] ?? ''));
    $delivery = rcValidateDeliveryDate($pdo, $rcRestNum, $date);
    if (!$delivery) rcRespond(['error' => 'Дедлайн на эту дату прошёл или дата вне расписания'], 400);

    $itemsRaw = $body['items'] ?? [];
    $items = rcNormalizeItems($itemsRaw);
    if (isset($items[0]['error'])) rcRespond(['error' => $items[0]['error']], 400);

    $comment = trim((string)($body['comment'] ?? ''));
    if (mb_strlen($comment) > RC_MAX_COMMENT_LEN) $comment = mb_substr($comment, 0, RC_MAX_COMMENT_LEN);
    if ($comment === '') $comment = null;

    $batchUuid = rcGenUuid();
    $submitterName = 'Кабинет р.' . $rcRestNum;

    $corrIds = [];
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("
            INSERT INTO order_corrections
            (restaurant_number, restaurant_chat_id, legal_entity_group, submitter_name, submitter_source,
             delivery_date, action, product_sku, product_name, quantity, unit_of_measure,
             submitter_comment, batch_uuid)
            VALUES (?, NULL, ?, ?, 'cabinet', ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($items as $it) {
            $ins->execute([
                $rcRestNum, $rcGroup, $submitterName, $date,
                $it['action'], $it['sku'], $it['name'], $it['qty'], $it['unit'],
                $comment, $batchUuid,
            ]);
            $corrIds[] = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('rcSave failed: ' . $e->getMessage());
        rcRespond(['error' => 'Не удалось сохранить корректировку'], 500);
    }

    auditLog($pdo, 'correction_submit_cabinet', 'order_corrections', $corrIds[0] ?? 0,
        'ro:' . $rcRestNum, ['batch_uuid' => $batchUuid, 'count' => count($items), 'date' => $date]);

    // Уведомление закупкам в Telegram — та же функция, что и из бота.
    try {
        corrNotifyPurchasersBatch($pdo, $corrIds, $rcRestNum, $date, $submitterName);
    } catch (Exception $e) {
        // Уведомление неблокирующее: запись сохранена, в TG не ушло — закупки увидят на портале.
        error_log('rcSave notify failed: ' . $e->getMessage());
    }

    rcRespond(['success' => true, 'batch_uuid' => $batchUuid, 'count' => count($items)]);
}

// ═══════════════════════════════════════════════════════════════
// POST restaurant-corrections/update
// Body: { batch_uuid, items: [...], comment? }
// Перезаписывает позиции батча. Возможен только пока ВСЕ позиции в статусе pending.
// ═══════════════════════════════════════════════════════════════
if ($subpoint === 'update' && $method === 'POST') {
    $batchUuid = trim((string)($body['batch_uuid'] ?? ''));
    if (!preg_match('/^[0-9a-f-]{36}$/i', $batchUuid)) rcRespond(['error' => 'Неверный batch_uuid'], 400);

    $itemsRaw = $body['items'] ?? [];
    $items = rcNormalizeItems($itemsRaw);
    if (isset($items[0]['error'])) rcRespond(['error' => $items[0]['error']], 400);

    $comment = trim((string)($body['comment'] ?? ''));
    if (mb_strlen($comment) > RC_MAX_COMMENT_LEN) $comment = mb_substr($comment, 0, RC_MAX_COMMENT_LEN);
    if ($comment === '') $comment = null;

    $pdo->beginTransaction();
    try {
        // Атомарно фиксируем строки батча и проверяем статусы.
        $st = $pdo->prepare("SELECT id, status, delivery_date, restaurant_number, notify_messages FROM order_corrections WHERE batch_uuid = ? FOR UPDATE");
        $st->execute([$batchUuid]);
        $rows = $st->fetchAll();
        if (!$rows) { $pdo->rollBack(); rcRespond(['error' => 'Корректировка не найдена'], 404); }
        $first = $rows[0];
        if ((int)$first['restaurant_number'] !== $rcRestNum) {
            $pdo->rollBack();
            rcRespond(['error' => 'Корректировка не принадлежит ресторану'], 403);
        }
        foreach ($rows as $r) {
            if ($r['status'] !== 'pending') {
                $pdo->rollBack();
                rcRespond(['error' => 'Корректировка уже взята в работу, изменить нельзя'], 409);
            }
        }

        // Проверим дедлайн ещё раз
        $delivery = rcValidateDeliveryDate($pdo, $rcRestNum, $first['delivery_date']);
        if (!$delivery) {
            $pdo->rollBack();
            rcRespond(['error' => 'Дедлайн на эту дату прошёл'], 400);
        }

        $deliveryDate = $first['delivery_date'];
        $savedNotifyMessages = $first['notify_messages']; // сохраняем для нового first-row

        // Удаляем старые позиции, вставляем новые с тем же batch_uuid.
        $pdo->prepare("DELETE FROM order_corrections WHERE batch_uuid = ?")->execute([$batchUuid]);

        $submitterName = 'Кабинет р.' . $rcRestNum;
        $ins = $pdo->prepare("
            INSERT INTO order_corrections
            (restaurant_number, restaurant_chat_id, legal_entity_group, submitter_name, submitter_source,
             delivery_date, action, product_sku, product_name, quantity, unit_of_measure,
             submitter_comment, batch_uuid, notify_messages)
            VALUES (?, NULL, ?, ?, 'cabinet', ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $newIds = [];
        foreach ($items as $idx => $it) {
            // notify_messages кладём только в первую новую строку — этого достаточно для corrUpdateAllReviewMessages.
            $nm = ($idx === 0) ? $savedNotifyMessages : null;
            $ins->execute([
                $rcRestNum, $rcGroup, $submitterName, $deliveryDate,
                $it['action'], $it['sku'], $it['name'], $it['qty'], $it['unit'],
                $comment, $batchUuid, $nm,
            ]);
            $newIds[] = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('rcUpdate failed: ' . $e->getMessage());
        rcRespond(['error' => 'Не удалось изменить корректировку'], 500);
    }

    auditLog($pdo, 'correction_edit_cabinet', 'order_corrections', $newIds[0] ?? 0,
        'ro:' . $rcRestNum, ['batch_uuid' => $batchUuid, 'count' => count($items)]);

    // Перерисуем сообщения у закупок в TG (если они были).
    try {
        if ($savedNotifyMessages) {
            corrUpdateAllReviewMessages($pdo, $newIds);
        }
    } catch (Exception $e) {
        error_log('rcUpdate notify failed: ' . $e->getMessage());
    }

    rcRespond(['success' => true, 'batch_uuid' => $batchUuid, 'count' => count($items)]);
}

// ═══════════════════════════════════════════════════════════════
// POST restaurant-corrections/cancel
// Body: { batch_uuid }
// ═══════════════════════════════════════════════════════════════
if ($subpoint === 'cancel' && $method === 'POST') {
    $batchUuid = trim((string)($body['batch_uuid'] ?? ''));
    if (!preg_match('/^[0-9a-f-]{36}$/i', $batchUuid)) rcRespond(['error' => 'Неверный batch_uuid'], 400);

    $pdo->beginTransaction();
    $batchIds = [];
    try {
        $st = $pdo->prepare("SELECT id, status, restaurant_number FROM order_corrections WHERE batch_uuid = ? FOR UPDATE");
        $st->execute([$batchUuid]);
        $rows = $st->fetchAll();
        if (!$rows) { $pdo->rollBack(); rcRespond(['error' => 'Корректировка не найдена'], 404); }
        if ((int)$rows[0]['restaurant_number'] !== $rcRestNum) {
            $pdo->rollBack();
            rcRespond(['error' => 'Корректировка не принадлежит ресторану'], 403);
        }
        foreach ($rows as $r) {
            if ($r['status'] !== 'pending') {
                $pdo->rollBack();
                rcRespond(['error' => 'Корректировка уже взята в работу, отменить нельзя'], 409);
            }
            $batchIds[] = (int)$r['id'];
        }
        $pdo->prepare("UPDATE order_corrections SET status = 'cancelled' WHERE batch_uuid = ?")->execute([$batchUuid]);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('rcCancel failed: ' . $e->getMessage());
        rcRespond(['error' => 'Не удалось отменить корректировку'], 500);
    }

    auditLog($pdo, 'correction_cancel_cabinet', 'order_corrections', $batchIds[0] ?? 0,
        'ro:' . $rcRestNum, ['batch_uuid' => $batchUuid]);

    // Перерисуем сообщения у закупок: TG увидит, что строки в статусе cancelled.
    try {
        corrUpdateAllReviewMessages($pdo, $batchIds);
    } catch (Exception $e) {
        error_log('rcCancel notify failed: ' . $e->getMessage());
    }

    rcRespond(['success' => true]);
}

rcRespond(['error' => 'Метод не найден'], 404);
