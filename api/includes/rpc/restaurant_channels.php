<?php
/**
 * RPC: как с рестораном можно связаться.
 *
 * До этого дыры в каналах связи были не видны нигде: ресторан №17 не получал
 * напоминаний с 12 июня, и узнать об этом можно было только из служебного
 * журнала. По Пицце Стар оказалось не подключено 49 точек из 51.
 *
 * Отдаёт по каждому активному ресторану группы три канала (Telegram,
 * уведомления в браузере, подтверждённая почта) плюс когда последний раз
 * заходили в кабинет. Разделяет два разных случая: «бота заблокировали»
 * (лечится звонком) и «не подключался ни разу» (лечится настройкой).
 *
 * Подключается из api/includes/rpc.php (приватный блок, после checkAuth).
 * Глобальные: $pdo, $body, $fn, $authUser, $ROLE_TEMPLATES, $ACCESS_LEVELS.
 */

if ($fn === 'restaurant_channels') {
    global $ROLE_TEMPLATES, $ACCESS_LEVELS;
    requireModuleAccess($authUser, 'restaurant-orders', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);

    $group = trim((string)($body['group'] ?? ''));
    if (!in_array($group, ['BK_VM', 'PS'], true)) {
        respond(['error' => 'Не указана группа юр. лиц'], 400);
    }
    if (!checkLegalEntityGroupAccess($authUser, $group)) {
        respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
    }

    $st = $pdo->prepare("
        SELECT r.number, r.city, r.address, r.region, r.legal_entity,
               (SELECT COUNT(*) FROM ro_telegram_subs t
                 WHERE t.restaurant_number = r.number
                   AND t.verified_at IS NOT NULL AND t.tg_blocked_at IS NULL) AS tg_alive,
               (SELECT COUNT(*) FROM ro_telegram_subs t
                 WHERE t.restaurant_number = r.number AND t.tg_blocked_at IS NOT NULL) AS tg_blocked,
               (SELECT COUNT(*) FROM ro_telegram_subs t
                 WHERE t.restaurant_number = r.number) AS tg_ever,
               (SELECT MAX(DATE(t.tg_blocked_at)) FROM ro_telegram_subs t
                 WHERE t.restaurant_number = r.number) AS tg_blocked_at,
               (SELECT COUNT(*) FROM push_subscriptions ps
                 WHERE ps.restaurant_number = r.number) AS push_count,
               (SELECT COUNT(*) FROM ro_users u
                 WHERE u.restaurant_number = r.number AND u.is_active = 1) AS accounts,
               (SELECT COUNT(*) FROM ro_users u
                 WHERE u.restaurant_number = r.number AND u.is_active = 1
                   AND u.email IS NOT NULL AND u.email <> '') AS mail_any,
               (SELECT COUNT(*) FROM ro_users u
                 WHERE u.restaurant_number = r.number AND u.is_active = 1
                   AND u.email_verified_at IS NOT NULL) AS mail_ok,
               (SELECT MAX(u.last_login_at) FROM ro_users u
                 WHERE u.restaurant_number = r.number) AS last_login
        FROM restaurants r
        WHERE r.active = 1 AND r.legal_entity_group = ?
        ORDER BY r.sort_order, r.number
    ");
    $st->execute([$group]);

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tgAlive   = (int)$r['tg_alive'];
        $tgBlocked = (int)$r['tg_blocked'];
        $tgEver    = (int)$r['tg_ever'];
        $push      = (int)$r['push_count'];
        $mailOk    = (int)$r['mail_ok'];
        $mailAny   = (int)$r['mail_any'];

        // Telegram: работает / заблокировали / не подключали.
        // «Частично» — когда часть подписчиков заблокировала бота, а часть нет
        // (обычно уволившийся сотрудник): напоминания доходят, но не всем.
        if ($tgAlive > 0)        $tg = $tgBlocked > 0 ? 'partial' : 'ok';
        elseif ($tgEver > 0)     $tg = 'blocked';
        else                     $tg = 'none';

        // Почта: подтверждена / указана но не подтверждена / нет.
        // Без подтверждения не работают ни вход по почте, ни сброс пароля.
        if ($mailOk > 0)         $mail = 'ok';
        elseif ($mailAny > 0)    $mail = 'unverified';
        else                     $mail = 'none';

        $rows[] = [
            'number'        => $r['number'],
            'display'       => formatRestaurantNumber($r['number']),
            'where'         => trim((string)($r['city'] ?? '') . ' ' . (string)($r['address'] ?? '')),
            'region'        => $r['region'],
            'legal_entity'  => $r['legal_entity'],
            'telegram'      => $tg,
            'tg_alive'      => $tgAlive,
            'tg_blocked'    => $tgBlocked,
            'tg_blocked_at' => $r['tg_blocked_at'],
            'push'          => $push > 0 ? 'ok' : 'none',
            'push_count'    => $push,
            'mail'          => $mail,
            'accounts'      => (int)$r['accounts'],
            'last_login'    => $r['last_login'],
            // Ни одного канала для напоминаний — до ресторана не достучаться.
            'unreachable'   => ($tgAlive === 0 && $push === 0),
        ];
    }

    respond([
        'restaurants' => $rows,
        'summary'     => [
            'total'       => count($rows),
            'telegram'    => count(array_filter($rows, fn($x) => $x['telegram'] === 'ok' || $x['telegram'] === 'partial')),
            'push'        => count(array_filter($rows, fn($x) => $x['push'] === 'ok')),
            'mail'        => count(array_filter($rows, fn($x) => $x['mail'] === 'ok')),
            'unreachable' => count(array_filter($rows, fn($x) => $x['unreachable'])),
            'blocked'     => count(array_filter($rows, fn($x) => $x['telegram'] === 'blocked' || $x['telegram'] === 'partial')),
        ],
    ]);
}
