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

// Backward-compat: фронт по этому маркеру отличает «карточку основной поставки»
// от поставщика. В БД больше не используется — reminder_kind ENUM сделал это явно.
const MAIN_DELIVERY_SUPPLIER_ID = '00000000-0000-0000-0000-000000000000';

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
    //
    // Если у поставщика активен временный график (so_supplier_temp_schedule_periods
    // с date_to >= сегодня), берём дни из so_supplier_temp_schedule_items вместо
    // основного расписания. После окончания периода всё само вернётся к основному.
    $tempPeriods = [];
    foreach ($pdo->query("SELECT supplier_id, date_from, date_to FROM so_supplier_temp_schedule_periods WHERE date_to >= CURDATE()") as $p) {
        $tempPeriods[$p['supplier_id']] = [
            'date_from' => $p['date_from'],
            'date_to'   => $p['date_to'],
        ];
    }

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
    $rawRows = $sched->fetchAll();

    // Для поставщиков с активным периодом — берём строки из временного графика.
    // Остальные — как обычно.
    $tempRowsBySupplier = [];
    if ($tempPeriods) {
        $supIds = array_keys($tempPeriods);
        $ph = implode(',', array_fill(0, count($supIds), '?'));
        $tempStmt = $pdo->prepare("
            SELECT sp.supplier_id, ssi.order_day, ssi.delivery_day, ssi.is_active,
                   s.short_name AS supplier_name, s.so_enabled,
                   sd.deadline_time AS deadline_override
            FROM so_supplier_temp_schedule_items ssi
            JOIN so_supplier_temp_schedule_periods sp ON sp.id = ssi.period_id
            JOIN suppliers s ON s.id = sp.supplier_id
            LEFT JOIN supplier_schedule_deadlines sd
                   ON sd.supplier_id = sp.supplier_id
                  AND sd.restaurant_id = ssi.restaurant_id
                  AND sd.order_day = ssi.order_day
            WHERE ssi.restaurant_id = ? AND ssi.is_active = 1
              AND sp.supplier_id IN ($ph)
              AND s.is_active = 1 AND s.so_enabled = 0
            ORDER BY s.short_name, ssi.order_day
        ");
        $tempStmt->execute(array_merge([$rrRestPk], $supIds));
        foreach ($tempStmt->fetchAll() as $r) {
            $tempRowsBySupplier[$r['supplier_id']][] = $r;
        }
    }

    $rows = [];
    foreach ($rawRows as $r) {
        $sid = $r['supplier_id'];
        if (isset($tempPeriods[$sid])) {
            // Этого поставщика покрывает временный график — основные строки
            // не используем (они добавлены ниже из temp).
            continue;
        }
        $rows[] = $r;
    }
    foreach ($tempRowsBySupplier as $sid => $items) {
        foreach ($items as $r) $rows[] = $r;
    }

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
                'temp_period'     => isset($tempPeriods[$sid]) ? [
                    'date_from' => $tempPeriods[$sid]['date_from'],
                    'date_to'   => $tempPeriods[$sid]['date_to'],
                ] : null,
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

    // ─── Основная поставка ────────────────────────────────────────────────
    // Расписание из delivery_schedule (только строки, где закупка задала
    // дедлайн подачи заявки) + подписка ресторана + выбранные TG-получатели.
    $mainDays = [];
    $dsStmt = $pdo->prepare("
        SELECT day_of_week AS delivery_day, order_day, order_deadline, delivery_time
        FROM delivery_schedule
        WHERE restaurant_id = ? AND order_day IS NOT NULL AND order_deadline IS NOT NULL
        ORDER BY day_of_week
    ");
    $dsStmt->execute([$rrRestPk]);
    foreach ($dsStmt->fetchAll() as $r) {
        $mainDays[] = [
            'order_day'      => (int)$r['order_day'],
            'delivery_day'   => (int)$r['delivery_day'],
            'deadline_time'  => $r['order_deadline'],
            'delivery_time'  => $r['delivery_time'] ?: null,
        ];
    }

    $mainSub = null;
    $mainSelectedTg = [];
    $mainSubRow = $pdo->prepare("
        SELECT id, is_enabled, portal_enabled, telegram_enabled
        FROM restaurant_main_delivery_subscriptions
        WHERE restaurant_id = ?
    ");
    $mainSubRow->execute([$rrRestPk]);
    $mainSubData = $mainSubRow->fetch();
    if ($mainSubData) {
        $mainSub = [
            'id'               => (int)$mainSubData['id'],
            'is_enabled'       => (int)$mainSubData['is_enabled'] === 1,
            'portal_enabled'   => (int)$mainSubData['portal_enabled'] === 1,
            'telegram_enabled' => (int)$mainSubData['telegram_enabled'] === 1,
        ];
        $mtg = $pdo->prepare("
            SELECT ro_tg_sub_id
            FROM restaurant_main_delivery_tg_subscribers
            WHERE subscription_id = ? AND is_active = 1
        ");
        $mtg->execute([(int)$mainSubData['id']]);
        foreach ($mtg->fetchAll() as $r) {
            $mainSelectedTg[] = (int)$r['ro_tg_sub_id'];
        }
    }

    rrRespond([
        'restaurant' => [
            'id'     => $rrRestPk,
            'number' => $rrUser['restaurant_number'],
            'city'   => $rrRestRow['city'] ?? '',
        ],
        'available_tg' => $availableTg,
        'groups' => array_values($bySupplier),
        'main_delivery' => [
            'days'            => $mainDays,
            'subscription'    => $mainSub,
            'selected_tg_ids' => $mainSelectedTg,
        ],
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

    $prev = $pdo->prepare("SELECT is_enabled, telegram_enabled FROM restaurant_reminder_subscriptions WHERE restaurant_id = ? AND supplier_id = ?");
    $prev->execute([$rrRestPk, $supplierId]);
    $prevState = $prev->fetch();

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

    auditLog($pdo, 'reminder_sub_toggled', 'restaurant_reminder_subscriptions', $rrRestPk, $updatedBy,
        ['supplier_id' => $supplierId, 'restaurant_number' => $rrUser['restaurant_number']],
        [
            'is_enabled'       => ['from' => (int)($prevState['is_enabled'] ?? 0), 'to' => $isEnabled],
            'telegram_enabled' => ['from' => (int)($prevState['telegram_enabled'] ?? 0), 'to' => $telegramEnabled],
        ]
    );

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

if ($subpoint === 'main-set' && $method === 'POST') {
    // Подписка на напоминания об основной поставке (одна на ресторан).
    // Принимает: { is_enabled, telegram_enabled }
    $isEnabled       = isset($body['is_enabled'])       ? (!empty($body['is_enabled'])       ? 1 : 0) : 1;
    $portalEnabled   = $isEnabled;
    $telegramEnabled = isset($body['telegram_enabled']) ? (!empty($body['telegram_enabled']) ? 1 : 0) : 0;
    $updatedBy = 'ro:' . $rrUser['restaurant_number'];

    $prevMain = $pdo->prepare("SELECT is_enabled, telegram_enabled FROM restaurant_main_delivery_subscriptions WHERE restaurant_id = ?");
    $prevMain->execute([$rrRestPk]);
    $prevMainState = $prevMain->fetch();

    $pdo->prepare("
        INSERT INTO restaurant_main_delivery_subscriptions
            (restaurant_id, is_enabled, portal_enabled, telegram_enabled, updated_at, updated_by)
        VALUES (?, ?, ?, ?, NOW(), ?)
        ON DUPLICATE KEY UPDATE
            is_enabled = VALUES(is_enabled),
            portal_enabled = VALUES(portal_enabled),
            telegram_enabled = VALUES(telegram_enabled),
            updated_at = NOW(),
            updated_by = VALUES(updated_by)
    ")->execute([$rrRestPk, $isEnabled, $portalEnabled, $telegramEnabled, $updatedBy]);

    auditLog($pdo, 'reminder_main_toggled', 'restaurant_main_delivery_subscriptions', $rrRestPk, $updatedBy,
        ['restaurant_number' => $rrUser['restaurant_number']],
        [
            'is_enabled'       => ['from' => (int)($prevMainState['is_enabled'] ?? 0), 'to' => $isEnabled],
            'telegram_enabled' => ['from' => (int)($prevMainState['telegram_enabled'] ?? 0), 'to' => $telegramEnabled],
        ]
    );

    rrRespond(['success' => true]);
}

if ($subpoint === 'main-tg-set' && $method === 'POST') {
    // Выбрать получателей TG-уведомлений для основной поставки.
    // Принимает: { ro_tg_sub_ids: [int, ...] } — полный новый список.
    $ids = $body['ro_tg_sub_ids'] ?? [];
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_unique(array_map('intval', $ids)));

    // Проверка: все переданные TG-подписчики принадлежат ресторану и верифицированы
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

    // Получаем или создаём подписку
    $sub = $pdo->prepare("SELECT id FROM restaurant_main_delivery_subscriptions WHERE restaurant_id = ?");
    $sub->execute([$rrRestPk]);
    $subId = $sub->fetchColumn();
    if (!$subId) {
        $pdo->prepare("
            INSERT INTO restaurant_main_delivery_subscriptions
                (restaurant_id, is_enabled, portal_enabled, telegram_enabled, updated_by)
            VALUES (?, 1, 1, ?, ?)
        ")->execute([$rrRestPk, $ids ? 1 : 0, 'ro:' . $rrUser['restaurant_number']]);
        $subId = (int)$pdo->lastInsertId();
    } else if ($ids) {
        $pdo->prepare("
            UPDATE restaurant_main_delivery_subscriptions
            SET telegram_enabled = 1, updated_at = NOW()
            WHERE id = ?
        ")->execute([$subId]);
    }

    // Полная замена списка получателей
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM restaurant_main_delivery_tg_subscribers WHERE subscription_id = ?")
            ->execute([$subId]);
        if ($ids) {
            $ins = $pdo->prepare("
                INSERT INTO restaurant_main_delivery_tg_subscribers (subscription_id, ro_tg_sub_id, is_active)
                VALUES (?, ?, 1)
            ");
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
    // Активные напоминания для баннера в кабинете.
    // Возвращает items за сегодня + advance-напоминания: расписания, у которых
    // сегодня попадает в окно reminder_times для будущего order_day (до 7 дней).
    $tz = new DateTimeZone('Europe/Minsk');
    $now = new DateTime('now', $tz);
    $today = $now->format('Y-m-d');
    $todayDow = (int)$now->format('N'); // 1=Пн ... 7=Вс

    // helper: парсинг reminder_times из JSON
    $rrParseRt = function($raw) {
        if (!$raw) return [];
        $arr = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($arr)) return [];
        $out = [];
        foreach ($arr as $rt) {
            if (!is_array($rt)) continue;
            $db = (int)($rt['days_before'] ?? -1);
            $t  = $rt['time'] ?? '';
            if ($db < 0 || $db > 7 || !preg_match('/^\d{1,2}:\d{2}/', $t)) continue;
            $out[] = ['days_before' => $db, 'time' => substr($t, 0, 5)];
        }
        return $out;
    };

    // helper: должно ли сегодня показывать этот row (advance reminders)
    // Ограничение: показываем только «сегодня» и «завтра» (не дальше).
    $rrShouldShowToday = function($orderDow, $rtimes) use ($todayDow) {
        $diffToOrder = ($orderDow - $todayDow + 7) % 7;
        if ($diffToOrder === 0) return 0; // сегодня = день подачи
        if ($diffToOrder > 1) return false; // не показываем дальше чем «на завтра»
        foreach ($rtimes as $rt) {
            if ((int)$rt['days_before'] === $diffToOrder) return $diffToOrder;
        }
        return false;
    };

    // ─── Локальные поставщики ─────────────────────────────────────────────
    // Активные временные периоды графиков — если у поставщика есть период,
    // покрывающий дату поставки строки, берём её из so_supplier_temp_*,
    // а строку из основного расписания пропускаем. Так напоминания у
    // ресторана совпадают с тем, что показывает менеджер закупки.
    $tempPeriods = [];
    foreach ($pdo->query("SELECT supplier_id, date_from, date_to FROM so_supplier_temp_schedule_periods WHERE date_to >= CURDATE()") as $p) {
        $tempPeriods[$p['supplier_id']] = [
            'date_from' => $p['date_from'],
            'date_to'   => $p['date_to'],
        ];
    }

    $rows = $pdo->prepare("
        SELECT ss.supplier_id, ss.order_day, ss.delivery_day,
               s.short_name AS supplier_name, s.so_enabled,
               sd.deadline_time AS deadline_override,
               sd.reminder_times AS reminder_times_override,
               sub.id AS subscription_id, sub.is_enabled, sub.portal_enabled
        FROM supplier_schedules ss
        JOIN suppliers s ON s.id = ss.supplier_id
        LEFT JOIN supplier_schedule_deadlines sd
               ON sd.supplier_id = ss.supplier_id AND sd.restaurant_id = ss.restaurant_id AND sd.order_day = ss.order_day
        LEFT JOIN restaurant_reminder_subscriptions sub
               ON sub.restaurant_id = ss.restaurant_id AND sub.supplier_id = ss.supplier_id
        WHERE ss.restaurant_id = ? AND ss.is_active = 1
          AND s.is_active = 1 AND s.so_enabled = 0
        ORDER BY s.short_name
    ");
    $rows->execute([$rrRestPk]);
    $mainList = $rows->fetchAll();

    // Строки из временных графиков (только для поставщиков с активным периодом)
    $tempList = [];
    if ($tempPeriods) {
        $supIds = array_keys($tempPeriods);
        $ph = implode(',', array_fill(0, count($supIds), '?'));
        $tempStmt = $pdo->prepare("
            SELECT sp.supplier_id, ssi.order_day, ssi.delivery_day,
                   s.short_name AS supplier_name, s.so_enabled,
                   sd.deadline_time AS deadline_override,
                   sd.reminder_times AS reminder_times_override,
                   sub.id AS subscription_id, sub.is_enabled, sub.portal_enabled
            FROM so_supplier_temp_schedule_items ssi
            JOIN so_supplier_temp_schedule_periods sp ON sp.id = ssi.period_id
            JOIN suppliers s ON s.id = sp.supplier_id
            LEFT JOIN supplier_schedule_deadlines sd
                   ON sd.supplier_id = sp.supplier_id AND sd.restaurant_id = ssi.restaurant_id AND sd.order_day = ssi.order_day
            LEFT JOIN restaurant_reminder_subscriptions sub
                   ON sub.restaurant_id = ssi.restaurant_id AND sub.supplier_id = sp.supplier_id
            WHERE ssi.restaurant_id = ? AND ssi.is_active = 1
              AND sp.supplier_id IN ($ph)
              AND s.is_active = 1 AND s.so_enabled = 0
            ORDER BY s.short_name
        ");
        $tempStmt->execute(array_merge([$rrRestPk], $supIds));
        $tempList = $tempStmt->fetchAll();
    }

    // Оценка ближайшей даты поставки строки расписания
    $rrEstDelivery = function ($orderDay, $deliveryDay) use ($now, $todayDow) {
        $diffOrder = ($orderDay - $todayDow + 7) % 7;
        $dt = clone $now;
        $dt->modify("+{$diffOrder} days");
        $diffDeliv = ($deliveryDay - $orderDay + 7) % 7;
        if ($diffDeliv === 0) $diffDeliv = 7;
        $dt->modify("+{$diffDeliv} days");
        return $dt->format('Y-m-d');
    };

    $list = [];
    foreach ($mainList as $r) {
        $sid = $r['supplier_id'];
        if (isset($tempPeriods[$sid])) {
            $dd = $rrEstDelivery((int)$r['order_day'], (int)$r['delivery_day']);
            $p = $tempPeriods[$sid];
            if ($dd >= $p['date_from'] && $dd <= $p['date_to']) continue;
        }
        $list[] = $r;
    }
    foreach ($tempList as $r) {
        $sid = $r['supplier_id'];
        $p = $tempPeriods[$sid] ?? null;
        if (!$p) continue;
        $dd = $rrEstDelivery((int)$r['order_day'], (int)$r['delivery_day']);
        if ($dd >= $p['date_from'] && $dd <= $p['date_to']) {
            $list[] = $r;
        }
    }

    // Учёт активных временных графиков: если у поставщика есть период,
    // в который попадает дата поставки — заменяем строки основного расписания
    // на строки из so_supplier_temp_schedule_items.
    $tempPeriods = [];
    foreach ($pdo->query("SELECT supplier_id, date_from, date_to FROM so_supplier_temp_schedule_periods WHERE date_to >= CURDATE()") as $p) {
        $tempPeriods[$p['supplier_id']] = ['date_from' => $p['date_from'], 'date_to' => $p['date_to']];
    }
    if ($tempPeriods) {
        $supIdsT = array_keys($tempPeriods);
        $phT = implode(',', array_fill(0, count($supIdsT), '?'));
        $tempStmt = $pdo->prepare("
            SELECT sp.supplier_id, ssi.order_day, ssi.delivery_day,
                   s.short_name AS supplier_name, s.so_enabled,
                   sd.deadline_time AS deadline_override,
                   sd.reminder_times AS reminder_times_override,
                   sub.id AS subscription_id, sub.is_enabled, sub.portal_enabled
            FROM so_supplier_temp_schedule_items ssi
            JOIN so_supplier_temp_schedule_periods sp ON sp.id = ssi.period_id
            JOIN suppliers s ON s.id = sp.supplier_id
            LEFT JOIN supplier_schedule_deadlines sd
                   ON sd.supplier_id = sp.supplier_id AND sd.restaurant_id = ssi.restaurant_id AND sd.order_day = ssi.order_day
            LEFT JOIN restaurant_reminder_subscriptions sub
                   ON sub.restaurant_id = ssi.restaurant_id AND sub.supplier_id = sp.supplier_id
            WHERE ssi.restaurant_id = ? AND ssi.is_active = 1
              AND sp.supplier_id IN ($phT)
              AND s.is_active = 1 AND s.so_enabled = 0
            ORDER BY s.short_name
        ");
        $tempStmt->execute(array_merge([$rrRestPk], $supIdsT));
        $estimateDeliveryDate = function($orderDay, $deliveryDay) use ($now, $todayDow) {
            $diffOrder = ($orderDay - $todayDow + 7) % 7;
            $dt = clone $now;
            $dt->modify("+{$diffOrder} days");
            $diffDeliv = ($deliveryDay - $orderDay + 7) % 7;
            if ($diffDeliv === 0) $diffDeliv = 7;
            $dt->modify("+{$diffDeliv} days");
            return $dt->format('Y-m-d');
        };
        // Из основного списка убираем строки, попадающие в активный темп-период
        $list = array_values(array_filter($list, function($r) use ($tempPeriods, $estimateDeliveryDate) {
            $supId = $r['supplier_id'];
            if (!isset($tempPeriods[$supId])) return true;
            $dd = $estimateDeliveryDate((int)$r['order_day'], (int)$r['delivery_day']);
            $p = $tempPeriods[$supId];
            return !($dd >= $p['date_from'] && $dd <= $p['date_to']);
        }));
        // Из темпа добавляем строки, попадающие в свой период
        foreach ($tempStmt->fetchAll() as $r) {
            $p = $tempPeriods[$r['supplier_id']] ?? null;
            if (!$p) continue;
            $dd = $estimateDeliveryDate((int)$r['order_day'], (int)$r['delivery_day']);
            if ($dd >= $p['date_from'] && $dd <= $p['date_to']) {
                $list[] = $r;
            }
        }
    }

    // Дефолты поставщиков
    $supplierIds = array_values(array_unique(array_column($list, 'supplier_id')));
    $defaultsByPair = [];
    $defaultRtimesByPair = [];
    if ($supplierIds) {
        $ph = implode(',', array_fill(0, count($supplierIds), '?'));
        $ds = $pdo->prepare("SELECT supplier_id, delivery_dow, deadline_time, reminder_times FROM supplier_default_deadlines WHERE supplier_id IN ($ph)");
        $ds->execute($supplierIds);
        foreach ($ds->fetchAll() as $d) {
            $defaultsByPair[$d['supplier_id']][(int)$d['delivery_dow']] = $d['deadline_time'];
            $defaultRtimesByPair[$d['supplier_id']][(int)$d['delivery_dow']] = $d['reminder_times'];
        }
    }

    // Префетч ack-записей за окно [today, today+7]
    $tomorrow7 = (clone $now)->modify('+7 days')->format('Y-m-d');
    $ackQuery = $pdo->prepare("
        SELECT reminder_kind, supplier_id, target_date, order_day, acknowledged_at, acknowledged_by
        FROM reminder_acknowledgements
        WHERE restaurant_id = ? AND target_date BETWEEN ? AND ?
    ");
    $ackQuery->execute([$rrRestPk, $today, $tomorrow7]);
    $acks = [];
    foreach ($ackQuery->fetchAll() as $a) {
        // Ключ: kind|supplier|date|order_day. Для main_delivery supplier_id=''
        $key = $a['reminder_kind'] . '|' . $a['supplier_id'] . '|' . $a['target_date'] . '|' . (int)$a['order_day'];
        $acks[$key] = $a;
    }

    $items = [];
    foreach ($list as $r) {
        $deadline = $r['deadline_override']
            ?: ($defaultsByPair[$r['supplier_id']][(int)$r['delivery_day']] ?? null);
        $rtimes = $rrParseRt($r['reminder_times_override']
            ?: ($defaultRtimesByPair[$r['supplier_id']][(int)$r['delivery_day']] ?? null));

        $orderDow = (int)$r['order_day'];
        $diffToOrder = ($orderDow - $todayDow + 7) % 7;
        // Показываем строку, если она на сегодня ИЛИ today попадает в advance-окно
        $showDb = $rrShouldShowToday($orderDow, $rtimes);
        if ($diffToOrder !== 0 && $showDb === false) continue;

        $orderDate = (clone $now)->modify("+{$diffToOrder} days");
        $orderDateStr = $orderDate->format('Y-m-d');
        $isEnabled = (int)$r['is_enabled'] === 1;
        $ackKey = 'supplier|' . $r['supplier_id'] . '|' . $orderDateStr . '|' . $orderDow;
        $ack = $acks[$ackKey] ?? null;
        $deadlineDt = $deadline ? DateTime::createFromFormat('Y-m-d H:i:s', $orderDateStr . ' ' . $deadline, $tz) : null;
        $isExpired = $deadlineDt && $now > $deadlineDt;

        $items[] = [
            'supplier_id'      => $r['supplier_id'],
            'supplier_name'    => $r['supplier_name'],
            'so_enabled'       => (int)$r['so_enabled'] === 1,
            'order_day'        => $orderDow,
            'delivery_day'     => (int)$r['delivery_day'],
            'deadline_time'    => $deadline,
            'order_date'       => $orderDateStr,
            'days_before'      => $diffToOrder,
            'is_advance'       => $diffToOrder > 0,
            'is_subscribed'    => $isEnabled,
            'is_acknowledged'  => $ack !== null,
            'acknowledged_at'  => $ack['acknowledged_at'] ?? null,
            'acknowledged_by'  => $ack['acknowledged_by'] ?? null,
            'is_expired'       => $isExpired,
        ];
    }

    // ─── Основная поставка ────────────────────────────────────────────────
    $mainRow = $pdo->prepare("
        SELECT ds.order_day, ds.day_of_week AS delivery_day, ds.order_deadline, ds.reminder_times,
               sub.id AS subscription_id, sub.is_enabled, sub.portal_enabled
        FROM delivery_schedule ds
        LEFT JOIN restaurant_main_delivery_subscriptions sub ON sub.restaurant_id = ds.restaurant_id
        WHERE ds.restaurant_id = ?
          AND ds.order_day IS NOT NULL
          AND ds.order_deadline IS NOT NULL
        ORDER BY ds.day_of_week
    ");
    $mainRow->execute([$rrRestPk]);
    foreach ($mainRow->fetchAll() as $r) {
        $deadline = $r['order_deadline'];
        $rtimes = $rrParseRt($r['reminder_times']);
        $orderDow = (int)$r['order_day'];
        $diffToOrder = ($orderDow - $todayDow + 7) % 7;
        $showDb = $rrShouldShowToday($orderDow, $rtimes);
        if ($diffToOrder !== 0 && $showDb === false) continue;

        $orderDate = (clone $now)->modify("+{$diffToOrder} days");
        $orderDateStr = $orderDate->format('Y-m-d');
        $isEnabled = (int)$r['is_enabled'] === 1;
        $ackKey = 'main_delivery||' . $orderDateStr . '|' . $orderDow;
        $ack = $acks[$ackKey] ?? null;
        $deadlineDt = $deadline ? DateTime::createFromFormat('Y-m-d H:i:s', $orderDateStr . ' ' . $deadline, $tz) : null;
        $isExpired = $deadlineDt && $now > $deadlineDt;

        $items[] = [
            'supplier_id'      => MAIN_DELIVERY_SUPPLIER_ID,
            'supplier_name'    => 'Основная поставка',
            'so_enabled'       => false,
            'is_main_delivery' => true,
            'order_day'        => $orderDow,
            'delivery_day'     => (int)$r['delivery_day'],
            'deadline_time'    => $deadline,
            'order_date'       => $orderDateStr,
            'days_before'      => $diffToOrder,
            'is_advance'       => $diffToOrder > 0,
            'is_subscribed'    => $isEnabled,
            'is_acknowledged'  => $ack !== null,
            'acknowledged_at'  => $ack['acknowledged_at'] ?? null,
            'acknowledged_by'  => $ack['acknowledged_by'] ?? null,
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

    // target_date может быть в будущем (advance-ack для «завтрашней» заявки).
    $targetDate = $today;
    if (!empty($body['order_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['order_date'])) {
        $targetDate = $body['order_date'];
    }

    // reminder_kind: фронт по-прежнему может прислать legacy-UUID для main delivery.
    $isMain = ($supplierId === MAIN_DELIVERY_SUPPLIER_ID);
    $reminderKind = $isMain ? 'main_delivery' : 'supplier';
    $dbSupplierId = $isMain ? '' : $supplierId;

    if ($isMain) {
        $check = $pdo->prepare("SELECT 1 FROM delivery_schedule WHERE restaurant_id = ? AND order_day = ? AND order_deadline IS NOT NULL LIMIT 1");
        $check->execute([$rrRestPk, $orderDay]);
        $hasSchedule = (bool)$check->fetchColumn();
    } else {
        // Основное расписание поставщика
        $check = $pdo->prepare("SELECT 1 FROM supplier_schedules WHERE restaurant_id = ? AND supplier_id = ? AND order_day = ? LIMIT 1");
        $check->execute([$rrRestPk, $supplierId, $orderDay]);
        $hasSchedule = (bool)$check->fetchColumn();
        // Если в основном нет — смотрим временный график (so_supplier_temp_schedule_*).
        // Период задан по датам ПОСТАВКИ, поэтому считаем дату поставки от
        // даты заявки и сравниваем её с границами периода — так же, как /today.
        if (!$hasSchedule) {
            $tempCheck = $pdo->prepare("
                SELECT ssi.delivery_day, sp.date_from, sp.date_to
                FROM so_supplier_temp_schedule_items ssi
                JOIN so_supplier_temp_schedule_periods sp ON sp.id = ssi.period_id
                WHERE ssi.restaurant_id = ?
                  AND sp.supplier_id = ?
                  AND ssi.order_day = ?
                  AND ssi.is_active = 1
                  AND sp.date_to >= CURDATE()
            ");
            $tempCheck->execute([$rrRestPk, $supplierId, $orderDay]);
            foreach ($tempCheck->fetchAll() as $tr) {
                $deliveryDay = (int)$tr['delivery_day'];
                $diff = ($deliveryDay - $orderDay + 7) % 7;
                if ($diff === 0) $diff = 7;
                $deliveryDate = (new DateTime($targetDate))->modify("+{$diff} days")->format('Y-m-d');
                if ($deliveryDate >= $tr['date_from'] && $deliveryDate <= $tr['date_to']) {
                    $hasSchedule = true;
                    break;
                }
            }
        }
    }
    if (!$hasSchedule) rrRespond(['error' => 'Нет расписания на этот день'], 404);

    $by = 'ro:' . $rrUser['restaurant_number'];
    $minskNow = (new DateTime('now', new DateTimeZone('Europe/Minsk')))->format('Y-m-d H:i:s');
    $pdo->prepare("
        INSERT INTO reminder_acknowledgements (restaurant_id, reminder_kind, supplier_id, target_date, order_day, acknowledged_by, acknowledged_at, source)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'portal')
        ON DUPLICATE KEY UPDATE
            acknowledged_by = VALUES(acknowledged_by),
            acknowledged_at = VALUES(acknowledged_at),
            source = VALUES(source)
    ")->execute([$rrRestPk, $reminderKind, $dbSupplierId, $targetDate, $orderDay, $by, $minskNow]);

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
    $targetDate = $today;
    if (!empty($body['order_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['order_date'])) {
        $targetDate = $body['order_date'];
    }
    $isMain = ($supplierId === MAIN_DELIVERY_SUPPLIER_ID);
    $reminderKind = $isMain ? 'main_delivery' : 'supplier';
    $dbSupplierId = $isMain ? '' : $supplierId;

    $pdo->prepare("DELETE FROM reminder_acknowledgements WHERE restaurant_id = ? AND reminder_kind = ? AND supplier_id = ? AND target_date = ? AND order_day = ?")
        ->execute([$rrRestPk, $reminderKind, $dbSupplierId, $targetDate, $orderDay]);
    rrRespond(['success' => true]);
}

rrRespond(['error' => 'Метод не найден'], 404);
