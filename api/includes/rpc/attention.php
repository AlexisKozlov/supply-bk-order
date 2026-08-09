<?php
/**
 * RPC: «Требуют внимания» — сводка незакрытых дел по всем модулям.
 *
 * Зачем: работа, которую начали и не довели, раньше была видна только внутри
 * своего раздела. Никто не открывает семь разделов подряд, поэтому просроченные
 * решения совещаний висели по три месяца, а просроченный платёж заметили только
 * при проверке. Здесь всё в одном месте — блоком на дашборде.
 *
 * Правила:
 *  - каждый блок отдаётся ТОЛЬКО тому, у кого есть доступ к своему разделу
 *    (нет прав на «Оплаты» — нет ни строк, ни числа в счётчике);
 *  - рабочие данные фильтруются по юрлицам человека, как везде в портале;
 *  - задачи — личные: показываем только свои карточки (правило модуля задач),
 *    остальные блоки общие для отдела.
 *
 * Подключается из api/includes/rpc.php (приватный блок, после checkAuth).
 * Глобальные: $pdo, $body, $fn, $authUser, $ROLE_TEMPLATES, $ACCESS_LEVELS.
 */

if ($fn === 'attention_overview') {
    if (!$authUser) respond(['error' => 'Требуется авторизация'], 401);

    $perms = resolvePermissions($authUser['role'] ?? 'user', $authUser['permissions'] ?? null, $ROLE_TEMPLATES);
    $can = function (string $module) use ($perms, $ACCESS_LEVELS): bool {
        return ($ACCESS_LEVELS[$perms[$module] ?? 'none'] ?? 0) >= $ACCESS_LEVELS['view'];
    };

    // Юрлица, доступные человеку. Пустой запрошенный фильтр = все свои.
    $userEntities = $authUser['legal_entities'] ?? [];
    if (is_string($userEntities)) $userEntities = json_decode($userEntities, true) ?: [];
    if (!is_array($userEntities)) $userEntities = [];

    $wanted = trim((string)($body['legal_entity'] ?? ''));
    if ($wanted !== '') {
        if (!checkLegalEntityAccess($authUser, $wanted)) {
            respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        }
        $entities = [$wanted];
    } else {
        $entities = $userEntities;
    }
    // Админ без своих юрлиц видит всё.
    $allEntities = empty($entities) && ($authUser['role'] ?? '') === 'admin';

    // Группы юрлиц — для таблиц, которые хранят группу, а не юрлицо.
    $groups = [];
    foreach ($entities as $e) { $g = getEntityGroup($e); if ($g) $groups[$g] = true; }
    $groups = array_keys($groups);

    // Имя колонки может прийти с алиасом таблицы («r.legal_entity_group») —
    // кавычить надо каждую часть отдельно, иначе MySQL ищет колонку с точкой.
    $quoteCol = function (string $col): string {
        return implode('.', array_map(fn($p) => '`' . str_replace('`', '', $p) . '`', explode('.', $col)));
    };

    /** Кусок «legal_entity IN (...)» + параметры. */
    $entityIn = function (string $col) use ($entities, $allEntities, $quoteCol): array {
        if ($allEntities) return ['1', []];
        if (empty($entities)) return ['0', []];
        return [$quoteCol($col) . ' IN (' . implode(',', array_fill(0, count($entities), '?')) . ')', $entities];
    };
    $groupIn = function (string $col) use ($groups, $allEntities, $quoteCol): array {
        if ($allEntities) return ['1', []];
        if (empty($groups)) return ['0', []];
        return [$quoteCol($col) . ' IN (' . implode(',', array_fill(0, count($groups), '?')) . ')', $groups];
    };

    $rows = function (string $sql, array $params) use ($pdo): array {
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // Один упавший блок не должен ронять всю сводку — но и молчать нельзя.
            error_log('attention_overview block failed: ' . $e->getMessage());
            return [];
        }
    };

    $blocks = [];
    $me = (string)($authUser['name'] ?? '');

    // ── 1. Задачи и решения совещаний (только свои) ─────────────────────
    if ($can('tasks')) {
        $items = $rows("
            SELECT c.id,
                   c.title,
                   DATE(c.due_date)                      AS due,
                   DATEDIFF(CURDATE(), DATE(c.due_date))  AS days,
                   b.id                                   AS board_id,
                   (SELECT COUNT(*) FROM protocol_decision_cards pdc WHERE pdc.card_id = c.id) AS from_protocol
            FROM tasks_cards c
            JOIN tasks_boards b ON b.id = c.board_id
            WHERE c.is_done = 0 AND c.is_archived = 0
              AND c.due_date IS NOT NULL AND DATE(c.due_date) < CURDATE()
              AND (b.owner_name = ?
                   OR EXISTS (SELECT 1 FROM tasks_assignees a
                              WHERE a.card_id = c.id AND a.is_done = 0 AND a.user_name = ?))
            ORDER BY c.due_date
            LIMIT 50
        ", [$me, $me]);
        $blocks[] = [
            'key'    => 'tasks',
            'title'  => 'Задачи и решения совещаний',
            'hint'   => 'срок прошёл, не закрыто',
            'route'  => 'tasks',
            'action' => 'postpone',           // «перенести срок» — через API задач
            'count'  => count($items),
            'items'  => array_map(fn($r) => [
                'id'       => (int)$r['id'],
                'title'    => $r['title'],
                'subtitle' => $r['from_protocol'] ? 'из протокола совещания' : '',
                'date'     => $r['due'],
                'days'     => (int)$r['days'],
            ], $items),
        ];
    }

    // ── 2. Оплаты поставщикам ───────────────────────────────────────────
    if ($can('plan-fact')) {
        [$w, $p] = $entityIn('legal_entity');
        $items = $rows("
            SELECT id, supplier, amount, currency, payment_due_date AS due,
                   DATEDIFF(CURDATE(), payment_due_date) AS days
            FROM supplier_payments
            WHERE status <> 'paid' AND payment_due_date IS NOT NULL
              AND payment_due_date < CURDATE() AND $w
            ORDER BY payment_due_date
            LIMIT 50
        ", $p);
        $blocks[] = [
            'key'    => 'payments',
            'title'  => 'Оплаты поставщикам',
            'hint'   => 'дата платежа прошла, отметки об оплате нет',
            'route'  => 'payments',
            'action' => 'mark_paid',
            'count'  => count($items),
            'items'  => array_map(fn($r) => [
                'id'       => (int)$r['id'],
                'title'    => $r['supplier'],
                'subtitle' => number_format((float)$r['amount'], 2, ',', ' ') . ' ' . ($r['currency'] ?: ''),
                'date'     => $r['due'],
                'days'     => (int)$r['days'],
            ], $items),
        ];

        // ── 3. Приёмка поставок ─────────────────────────────────────────
        [$w2, $p2] = $entityIn('legal_entity');
        $items = $rows("
            SELECT id, supplier, delivery_date AS due,
                   DATEDIFF(CURDATE(), delivery_date) AS days
            FROM orders
            WHERE received_at IS NULL AND delivery_date IS NOT NULL
              AND delivery_date < CURDATE() - INTERVAL 2 DAY AND $w2
            ORDER BY delivery_date
            LIMIT 50
        ", $p2);
        $blocks[] = [
            'key'   => 'receiving',
            'title' => 'Приёмка поставок',
            'hint'  => 'поставка была больше 2 дней назад, приёмка не отмечена',
            'route' => 'plan-fact',
            'count' => count($items),
            'items' => array_map(fn($r) => [
                'id'       => $r['id'],
                'title'    => $r['supplier'],
                'subtitle' => 'поставка ' . $r['due'],
                'date'     => $r['due'],
                'days'     => (int)$r['days'],
            ], $items),
        ];
    }

    // ── 4. Тендеры ──────────────────────────────────────────────────────
    if ($can('tenders')) {
        [$w, $p] = $entityIn('legal_entity');
        $items = $rows("
            SELECT id, name, status, deadline AS due,
                   DATEDIFF(CURDATE(), deadline) AS days
            FROM tenders
            WHERE deadline IS NOT NULL AND deadline < CURDATE()
              AND status NOT IN ('completed','closed','archived','cancelled') AND $w
            ORDER BY deadline
            LIMIT 50
        ", $p);
        $blocks[] = [
            'key'   => 'tenders',
            'title' => 'Тендеры',
            'hint'  => 'срок подачи прошёл, тендер не закрыт',
            'route' => 'tenders',
            'count' => count($items),
            'items' => array_map(fn($r) => [
                'id'       => (int)$r['id'],
                'title'    => $r['name'],
                'subtitle' => 'статус: ' . $r['status'],
                'date'     => $r['due'],
                'days'     => (int)$r['days'],
            ], $items),
        ];
    }

    // ── 5. Маркетинговые акции ──────────────────────────────────────────
    if ($can('marketing')) {
        [$w, $p] = $entityIn('legal_entity');
        $items = $rows("
            SELECT id, name, status, date_to AS due,
                   DATEDIFF(CURDATE(), date_to) AS days
            FROM marketing_activities
            WHERE date_to IS NOT NULL AND date_to < CURDATE()
              AND status NOT IN ('completed','done','closed','archived','cancelled') AND $w
            ORDER BY date_to
            LIMIT 50
        ", $p);
        $blocks[] = [
            'key'   => 'marketing',
            'title' => 'Маркетинговые акции',
            'hint'  => 'акция закончилась, но помечена активной',
            'route' => 'marketing',
            'count' => count($items),
            'items' => array_map(fn($r) => [
                'id'       => (int)$r['id'],
                'title'    => $r['name'],
                'subtitle' => 'закончилась ' . $r['due'],
                'date'     => $r['due'],
                'days'     => (int)$r['days'],
            ], $items),
        ];
    }

    // ── 6. Сессии распределения ─────────────────────────────────────────
    if ($can('distribution')) {
        [$w, $p] = $groupIn('legal_entity_group');
        $items = $rows("
            SELECT id, name, DATE(created_at) AS due,
                   DATEDIFF(CURDATE(), DATE(created_at)) AS days
            FROM dist_sessions
            WHERE closed_at IS NULL AND created_at < NOW() - INTERVAL 30 DAY AND $w
            ORDER BY created_at
            LIMIT 50
        ", $p);
        $blocks[] = [
            'key'    => 'distribution',
            'title'  => 'Сессии распределения',
            'hint'   => 'открыта больше 30 дней',
            'route'  => 'distribution',
            'action' => 'close_session',
            'count'  => count($items),
            'items'  => array_map(fn($r) => [
                'id'       => (int)$r['id'],
                'title'    => $r['name'],
                'subtitle' => 'открыта с ' . $r['due'],
                'date'     => $r['due'],
                'days'     => (int)$r['days'],
            ], $items),
        ];
    }

    // ── 7. Опросы ───────────────────────────────────────────────────────
    if ($can('surveys')) {
        [$w, $p] = $groupIn('legal_entity_group');
        $items = $rows("
            SELECT s.id, s.title, DATE(s.created_at) AS due,
                   DATEDIFF(CURDATE(), DATE(s.created_at)) AS days,
                   (SELECT COUNT(*) FROM survey_responses r WHERE r.survey_id = s.id) AS answers
            FROM surveys s
            WHERE s.status = 'active' AND s.created_at < NOW() - INTERVAL 30 DAY AND $w
            ORDER BY s.created_at
            LIMIT 50
        ", $p);
        $blocks[] = [
            'key'    => 'surveys',
            'title'  => 'Опросы',
            'hint'   => 'открыт больше 30 дней',
            'route'  => 'surveys',
            'action' => 'close_survey',
            'count'  => count($items),
            'items'  => array_map(fn($r) => [
                'id'       => (int)$r['id'],
                'title'    => $r['title'],
                'subtitle' => 'ответов: ' . (int)$r['answers'],
                'date'     => $r['due'],
                'days'     => (int)$r['days'],
            ], $items),
        ];
    }

    // ── 8. Рестораны без связи ──────────────────────────────────────────
    // Не срок, а незакрытая настройка: до такой точки не дойдёт ни одно
    // напоминание — ни в Telegram, ни в браузер.
    if ($can('restaurant-orders')) {
        [$w, $p] = $groupIn('r.legal_entity_group');
        $items = $rows("
            SELECT r.number, r.city, r.address
            FROM restaurants r
            WHERE r.active = 1 AND $w
              AND NOT EXISTS (SELECT 1 FROM ro_telegram_subs t
                              WHERE t.restaurant_number = r.number
                                AND t.verified_at IS NOT NULL AND t.tg_blocked_at IS NULL)
              AND NOT EXISTS (SELECT 1 FROM push_subscriptions ps
                              WHERE ps.restaurant_number = r.number)
            ORDER BY r.sort_order, r.number
            LIMIT 100
        ", $p);
        $blocks[] = [
            'key'   => 'restaurants',
            'title' => 'Рестораны без связи',
            'hint'  => 'нет ни Telegram, ни уведомлений в браузере — напоминания не дойдут',
            'route' => 'restaurant-cabinet-manager',
            'count' => count($items),
            'items' => array_map(fn($r) => [
                'id'       => $r['number'],
                'title'    => formatRestaurantNumber($r['number']),
                'subtitle' => trim((string)($r['city'] ?? '') . ' ' . (string)($r['address'] ?? '')),
                'date'     => null,
                'days'     => null,
            ], $items),
        ];
    }

    $blocks = array_values(array_filter($blocks, fn($b) => $b['count'] > 0));
    respond([
        'total'  => array_sum(array_column($blocks, 'count')),
        'blocks' => $blocks,
    ]);
}
