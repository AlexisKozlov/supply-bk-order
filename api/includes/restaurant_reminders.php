<?php
/**
 * API напоминаний кабинета ресторана.
 *
 * Маршруты:
 *   GET    restaurant-reminders/list       — список поставщиков для ресторана с подписками и tg-подписчиками
 *   POST   restaurant-reminders/set        — изменить подписку: { supplier_id, is_enabled, portal_enabled, telegram_enabled }
 *   POST   restaurant-reminders/tg-add     — добавить tg-подписчика: { supplier_id, display_name } → { id, link_code, link_url }
 *   POST   restaurant-reminders/tg-remove  — удалить tg-подписчика: { id }
 *
 * Все эндпоинты требуют ресторанной сессии (X-RO-Token). Привязка chat_id
 * к подписчику делается отдельно из Telegram-бота по link_code на этапе 4.
 */

if ($endpoint !== 'restaurant-reminders') return;

function rrRespond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Авторизация по ресторанной сессии (X-RO-Token).
$rrUser = roGetRestaurantSession($pdo);
if (!$rrUser) rrRespond(['error' => 'Требуется авторизация ресторана'], 401);
$rrRestaurantId = (int)$rrUser['id'];

// Получаем restaurant_id из таблицы restaurants по номеру.
$rrRestRow = roGetRestaurantRow($pdo, $rrUser['restaurant_number'], $rrUser['legal_entity_group'] ?? null);
if (!$rrRestRow) rrRespond(['error' => 'Ресторан не найден'], 404);
$rrRestPk = (int)$rrRestRow['id'];

if ($subpoint === 'list' && $method === 'GET') {
    // Список верифицированных Telegram-подписчиков ресторана
    $tgStmt = $pdo->prepare("
        SELECT id, chat_id, first_name, username, verified_at
        FROM ro_telegram_subs
        WHERE restaurant_number = ?
          AND legal_entity_group = ?
          AND verified_at IS NOT NULL
        ORDER BY first_name, username
    ");
    $tgStmt->execute([$rrUser['restaurant_number'], $rrUser['legal_entity_group'] ?? 'BK_VM']);
    $availableTg = [];
    foreach ($tgStmt->fetchAll() as $r) {
        $availableTg[] = [
            'id'         => (int)$r['id'],
            'name'       => $r['first_name'] ?: ('@' . ($r['username'] ?: 'tg')),
            'username'   => $r['username'] ? '@' . $r['username'] : '',
        ];
    }

    // Загружаем расписания только ЛОКАЛЬНЫХ поставщиков (so_enabled=0).
    // У so-поставщиков (Камако/Планета/...) свои автоматические напоминания
    // через основной модуль "Заявки поставщикам".
    $sched = $pdo->prepare("
        SELECT ss.supplier_id, ss.order_day, ss.delivery_day, ss.is_active,
               s.short_name AS supplier_name, s.so_enabled,
               sd.deadline_time AS deadline_override
        FROM supplier_schedules ss
        JOIN suppliers s ON s.id = ss.supplier_id
        LEFT JOIN supplier_schedule_deadlines sd
               ON sd.supplier_id = ss.supplier_id
              AND sd.restaurant_id = ss.restaurant_id
              AND sd.order_day = ss.order_day
        WHERE ss.restaurant_id = ?
          AND ss.is_active = 1
          AND s.is_active = 1
          AND s.so_enabled = 0
        ORDER BY s.short_name, ss.order_day
    ");
    $sched->execute([$rrRestPk]);
    $rows = $sched->fetchAll();

    // Группируем по поставщику
    $bySupplier = [];
    $supplierIds = [];
    foreach ($rows as $r) {
        $sid = $r['supplier_id'];
        if (!isset($bySupplier[$sid])) {
            $bySupplier[$sid] = [
                'supplier_id'     => $sid,
                'supplier_name'   => $r['supplier_name'],
                'so_enabled'      => (int)$r['so_enabled'] === 1,
                'days'            => [],
                'subscription'    => null,
                'selected_tg_ids' => [],
            ];
            $supplierIds[] = $sid;
        }
        $bySupplier[$sid]['days'][] = [
            'order_day'        => (int)$r['order_day'],
            'delivery_day'     => (int)$r['delivery_day'],
            'deadline_override'=> $r['deadline_override'],
        ];
    }

    // Дефолтные дедлайны поставщиков
    if ($supplierIds) {
        $ph = implode(',', array_fill(0, count($supplierIds), '?'));
        $ds = $pdo->prepare("
            SELECT supplier_id, delivery_dow, deadline_dow, deadline_time
            FROM supplier_default_deadlines
            WHERE supplier_id IN ($ph)
        ");
        $ds->execute($supplierIds);
        $defaults = [];
        foreach ($ds->fetchAll() as $r) $defaults[$r['supplier_id']][] = $r;
        foreach ($bySupplier as $sid => &$grp) {
            $grp['default_deadlines'] = $defaults[$sid] ?? [];
        }
        unset($grp);

        // Подписки
        $ss = $pdo->prepare("
            SELECT id, supplier_id, is_enabled, portal_enabled, telegram_enabled
            FROM restaurant_reminder_subscriptions
            WHERE restaurant_id = ? AND supplier_id IN ($ph)
        ");
        $ss->execute(array_merge([$rrRestPk], $supplierIds));
        $subsById = [];
        foreach ($ss->fetchAll() as $r) {
            $bySupplier[$r['supplier_id']]['subscription'] = [
                'id'              => (int)$r['id'],
                'is_enabled'      => (int)$r['is_enabled'] === 1,
                'portal_enabled'  => (int)$r['portal_enabled'] === 1,
                'telegram_enabled'=> (int)$r['telegram_enabled'] === 1,
            ];
            $subsById[(int)$r['id']] = $r['supplier_id'];
        }

        // Какие из доступных tg-подписчиков выбраны для каждой подписки
        if ($subsById) {
            $subIds = array_keys($subsById);
            $sph = implode(',', array_fill(0, count($subIds), '?'));
            $ts = $pdo->prepare("
                SELECT subscription_id, ro_tg_sub_id
                FROM restaurant_reminder_tg_subscribers
                WHERE subscription_id IN ($sph) AND is_active = 1
            ");
            $ts->execute($subIds);
            foreach ($ts->fetchAll() as $r) {
                $sid = $subsById[(int)$r['subscription_id']] ?? null;
                if (!$sid) continue;
                $bySupplier[$sid]['selected_tg_ids'][] = (int)$r['ro_tg_sub_id'];
            }
        }
    }
    // Подстраховка: для всех групп без selected_tg_ids — пустой массив
    foreach ($bySupplier as &$g) {
        if (!isset($g['selected_tg_ids'])) $g['selected_tg_ids'] = [];
    }
    unset($g);

    rrRespond([
        'restaurant' => [
            'id'     => $rrRestPk,
            'number' => $rrUser['restaurant_number'],
            'city'   => $rrRestRow['city'] ?? '',
        ],
        'available_tg' => $availableTg,
        'groups' => array_values($bySupplier),
    ]);
}

if ($subpoint === 'set' && $method === 'POST') {
    $supplierId = trim((string)($body['supplier_id'] ?? ''));
    if (!$supplierId) rrRespond(['error' => 'supplier_id обязателен'], 400);

    // Проверка: у этого ресторана действительно есть расписание с этим поставщиком
    $check = $pdo->prepare("SELECT 1 FROM supplier_schedules WHERE restaurant_id = ? AND supplier_id = ? LIMIT 1");
    $check->execute([$rrRestPk, $supplierId]);
    if (!$check->fetchColumn()) rrRespond(['error' => 'У ресторана нет расписания с этим поставщиком'], 404);

    $isEnabled       = isset($body['is_enabled'])       ? (!empty($body['is_enabled'])       ? 1 : 0) : 1;
    // Канал "в кабинете" не настраивается отдельно — он всегда работает при is_enabled=1.
    // Поле portal_enabled оставлено в БД для совместимости и возможной фильтрации в cron.
    $portalEnabled   = $isEnabled;
    $telegramEnabled = isset($body['telegram_enabled']) ? (!empty($body['telegram_enabled']) ? 1 : 0) : 0;
    $updatedBy = 'ro:' . $rrUser['restaurant_number'];

    $pdo->prepare("
        INSERT INTO restaurant_reminder_subscriptions
            (restaurant_id, supplier_id, is_enabled, portal_enabled, telegram_enabled, updated_at, updated_by)
        VALUES (?, ?, ?, ?, ?, NOW(), ?)
        ON DUPLICATE KEY UPDATE
            is_enabled = VALUES(is_enabled),
            portal_enabled = VALUES(portal_enabled),
            telegram_enabled = VALUES(telegram_enabled),
            updated_at = NOW(),
            updated_by = VALUES(updated_by)
    ")->execute([$rrRestPk, $supplierId, $isEnabled, $portalEnabled, $telegramEnabled, $updatedBy]);

    rrRespond(['success' => true]);
}

if ($subpoint === 'tg-set' && $method === 'POST') {
    // Выбрать, какие из верифицированных Telegram-сотрудников ресторана
    // получают напоминания по конкретному поставщику.
    // Принимает: { supplier_id, ro_tg_sub_ids: [int, ...] } — полный новый список.
    $supplierId = trim((string)($body['supplier_id'] ?? ''));
    $ids = $body['ro_tg_sub_ids'] ?? [];
    if (!$supplierId) rrRespond(['error' => 'supplier_id обязателен'], 400);
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_unique(array_map('intval', $ids)));

    // Проверка: у этого ресторана есть расписание с этим поставщиком
    $check = $pdo->prepare("SELECT 1 FROM supplier_schedules WHERE restaurant_id = ? AND supplier_id = ? LIMIT 1");
    $check->execute([$rrRestPk, $supplierId]);
    if (!$check->fetchColumn()) rrRespond(['error' => 'У ресторана нет расписания с этим поставщиком'], 404);

    // Проверка: все переданные ro_tg_sub_id принадлежат этому ресторану и верифицированы
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $own = $pdo->prepare("
            SELECT COUNT(*) FROM ro_telegram_subs
            WHERE id IN ($ph)
              AND restaurant_number = ?
              AND legal_entity_group = ?
              AND verified_at IS NOT NULL
        ");
        $own->execute(array_merge($ids, [$rrUser['restaurant_number'], $rrUser['legal_entity_group'] ?? 'BK_VM']));
        if ((int)$own->fetchColumn() !== count($ids)) {
            rrRespond(['error' => 'Один из подписчиков не принадлежит ресторану'], 403);
        }
    }

    // Получаем или создаём подписку (мастер-запись на пару ресторан-поставщик)
    $sub = $pdo->prepare("SELECT id FROM restaurant_reminder_subscriptions WHERE restaurant_id = ? AND supplier_id = ?");
    $sub->execute([$rrRestPk, $supplierId]);
    $subId = $sub->fetchColumn();
    if (!$subId) {
        $pdo->prepare("INSERT INTO restaurant_reminder_subscriptions
                       (restaurant_id, supplier_id, is_enabled, portal_enabled, telegram_enabled, updated_by)
                       VALUES (?, ?, 1, 1, ?, ?)")
            ->execute([$rrRestPk, $supplierId, $ids ? 1 : 0, 'ro:' . $rrUser['restaurant_number']]);
        $subId = (int)$pdo->lastInsertId();
    } else if ($ids) {
        // Если выбрали кого-то — автоматически включаем телеграм-канал
        $pdo->prepare("UPDATE restaurant_reminder_subscriptions SET telegram_enabled = 1, updated_at = NOW() WHERE id = ?")
            ->execute([$subId]);
    }

    // Полная замена списка подписчиков на пару
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM restaurant_reminder_tg_subscribers WHERE subscription_id = ?")
            ->execute([$subId]);
        if ($ids) {
            $ins = $pdo->prepare("INSERT INTO restaurant_reminder_tg_subscribers (subscription_id, ro_tg_sub_id, is_active) VALUES (?, ?, 1)");
            foreach ($ids as $id) $ins->execute([$subId, $id]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        rrRespond(['error' => 'Ошибка сохранения: ' . $e->getMessage()], 500);
    }
    rrRespond(['success' => true]);
}

if ($subpoint === 'today' && $method === 'GET') {
    // Активные напоминания для ресторана на сегодня (для баннера в кабинете).
    // Возвращает строки с дедлайном, который ещё не прошёл и по которому нет
    // отметки «Сделал заказ».
    $tz = new DateTimeZone('Europe/Minsk');
    $now = new DateTime('now', $tz);
    $today = $now->format('Y-m-d');
    $todayDow = (int)$now->format('N'); // 1=Пн ... 7=Вс

    // Расписания на сегодня + дедлайны + информация о подписке + ack
    // (только локальные поставщики — у so свои автоматические напоминания)
    $rows = $pdo->prepare("
        SELECT ss.supplier_id, ss.order_day, ss.delivery_day,
               s.short_name AS supplier_name, s.so_enabled,
               sd.deadline_time AS deadline_override,
               sub.id AS subscription_id, sub.is_enabled, sub.portal_enabled,
               ack.id AS ack_id, ack.acknowledged_at, ack.acknowledged_by
        FROM supplier_schedules ss
        JOIN suppliers s ON s.id = ss.supplier_id
        LEFT JOIN supplier_schedule_deadlines sd
               ON sd.supplier_id = ss.supplier_id AND sd.restaurant_id = ss.restaurant_id AND sd.order_day = ss.order_day
        LEFT JOIN restaurant_reminder_subscriptions sub
               ON sub.restaurant_id = ss.restaurant_id AND sub.supplier_id = ss.supplier_id
        LEFT JOIN reminder_acknowledgements ack
               ON ack.restaurant_id = ss.restaurant_id AND ack.supplier_id = ss.supplier_id
              AND ack.target_date = ? AND ack.order_day = ss.order_day
        WHERE ss.restaurant_id = ? AND ss.is_active = 1 AND ss.order_day = ?
          AND s.is_active = 1 AND s.so_enabled = 0
        ORDER BY s.short_name
    ");
    $rows->execute([$today, $rrRestPk, $todayDow]);
    $list = $rows->fetchAll();

    // Подтянем дефолты поставщиков (для тех у кого нет override)
    $supplierIds = array_values(array_unique(array_column($list, 'supplier_id')));
    $defaultsByPair = []; // [supplier_id][delivery_dow] = time
    if ($supplierIds) {
        $ph = implode(',', array_fill(0, count($supplierIds), '?'));
        $ds = $pdo->prepare("SELECT supplier_id, delivery_dow, deadline_time FROM supplier_default_deadlines WHERE supplier_id IN ($ph)");
        $ds->execute($supplierIds);
        foreach ($ds->fetchAll() as $d) {
            $defaultsByPair[$d['supplier_id']][(int)$d['delivery_dow']] = $d['deadline_time'];
        }
    }

    $items = [];
    foreach ($list as $r) {
        $deadline = $r['deadline_override']
            ?: ($defaultsByPair[$r['supplier_id']][(int)$r['delivery_day']] ?? null);
        $isEnabled = (int)$r['is_enabled'] === 1;
        $isAcked = !empty($r['ack_id']);
        $deadlineDt = $deadline ? DateTime::createFromFormat('Y-m-d H:i:s', $today . ' ' . $deadline, $tz) : null;
        $isExpired = $deadlineDt && $now > $deadlineDt;
        $items[] = [
            'supplier_id'      => $r['supplier_id'],
            'supplier_name'    => $r['supplier_name'],
            'so_enabled'       => (int)$r['so_enabled'] === 1,
            'order_day'        => (int)$r['order_day'],
            'delivery_day'     => (int)$r['delivery_day'],
            'deadline_time'    => $deadline,
            'is_subscribed'    => $isEnabled,
            'is_acknowledged'  => $isAcked,
            'acknowledged_at'  => $r['acknowledged_at'] ?? null,
            'acknowledged_by'  => $r['acknowledged_by'] ?? null,
            'is_expired'       => $isExpired,
        ];
    }
    rrRespond([
        'today' => $today,
        'today_dow' => $todayDow,
        'items' => $items,
    ]);
}

if ($subpoint === 'acknowledge' && $method === 'POST') {
    $supplierId = trim((string)($body['supplier_id'] ?? ''));
    $orderDay = (int)($body['order_day'] ?? 0);
    if (!$supplierId) rrRespond(['error' => 'supplier_id обязателен'], 400);

    $tz = new DateTimeZone('Europe/Minsk');
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    if (!$orderDay) $orderDay = (int)(new DateTime('now', $tz))->format('N');

    // Проверка: у ресторана есть такая связка на этот день
    $check = $pdo->prepare("SELECT 1 FROM supplier_schedules WHERE restaurant_id = ? AND supplier_id = ? AND order_day = ? LIMIT 1");
    $check->execute([$rrRestPk, $supplierId, $orderDay]);
    if (!$check->fetchColumn()) rrRespond(['error' => 'Нет расписания на этот день'], 404);

    $by = 'ro:' . $rrUser['restaurant_number'];
    $minskNow = (new DateTime('now', new DateTimeZone('Europe/Minsk')))->format('Y-m-d H:i:s');
    $pdo->prepare("
        INSERT INTO reminder_acknowledgements (restaurant_id, supplier_id, target_date, order_day, acknowledged_by, acknowledged_at, source)
        VALUES (?, ?, ?, ?, ?, ?, 'portal')
        ON DUPLICATE KEY UPDATE
            acknowledged_by = VALUES(acknowledged_by),
            acknowledged_at = VALUES(acknowledged_at),
            source = VALUES(source)
    ")->execute([$rrRestPk, $supplierId, $today, $orderDay, $by, $minskNow]);

    rrRespond(['success' => true]);
}

if ($subpoint === 'unacknowledge' && $method === 'POST') {
    // Откатить «Сделал» — на случай если нажали по ошибке.
    $supplierId = trim((string)($body['supplier_id'] ?? ''));
    $orderDay = (int)($body['order_day'] ?? 0);
    if (!$supplierId) rrRespond(['error' => 'supplier_id обязателен'], 400);
    $tz = new DateTimeZone('Europe/Minsk');
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    if (!$orderDay) $orderDay = (int)(new DateTime('now', $tz))->format('N');

    $pdo->prepare("DELETE FROM reminder_acknowledgements WHERE restaurant_id = ? AND supplier_id = ? AND target_date = ? AND order_day = ?")
        ->execute([$rrRestPk, $supplierId, $today, $orderDay]);
    rrRespond(['success' => true]);
}

rrRespond(['error' => 'Метод не найден'], 404);
