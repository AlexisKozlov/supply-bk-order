<?php
/**
 * RPC: Telegram Bot Admin.
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName,
 * $ROLE_TEMPLATES, $ACCESS_LEVELS, $clientIp.
 */

    if ($fn === 'tg_admin_bot_info') {
        requireAdmin($authUser);
        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if (!$botToken) respond(['error' => 'Нет TELEGRAM_BOT_TOKEN'], 500);

        // getMe
        $me = json_decode(tgHttpGet("https://api.telegram.org/bot{$botToken}/getMe"), true);
        // getWebhookInfo
        $wh = json_decode(tgHttpGet("https://api.telegram.org/bot{$botToken}/getWebhookInfo"), true);

        respond([
            'bot' => $me['result'] ?? null,
            'webhook' => $wh['result'] ?? null,
        ]);
    }

    if ($fn === 'tg_admin_set_webhook') {
        requireAdmin($authUser);
        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if (!$botToken) respond(['error' => 'Нет TELEGRAM_BOT_TOKEN'], 500);
        $url = trim($body['url'] ?? '');
        $secret = trim($body['secret'] ?? '');

        $params = ['url' => $url];
        if ($secret) $params['secret_token'] = $secret;

        $ch = curl_init("https://api.telegram.org/bot{$botToken}/setWebhook");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 10,
        ]);
        $res = json_decode(curl_exec($ch), true); curl_close($ch);
        respond($res ?? ['error' => 'Нет ответа от Telegram']);
    }

    if ($fn === 'tg_admin_delete_webhook') {
        requireAdmin($authUser);
        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if (!$botToken) respond(['error' => 'Нет TELEGRAM_BOT_TOKEN'], 500);
        $res = json_decode(tgHttpGet("https://api.telegram.org/bot{$botToken}/deleteWebhook"), true);
        respond($res ?? ['error' => 'Нет ответа от Telegram']);
    }

    if ($fn === 'tg_admin_recent_questions') {
        requireAdmin($authUser);
        $rows = $pdo->query("SELECT user_name, question AS last_question, answer, asked_at AS last_question_at, legal_entity AS last_entity
            FROM tg_question_log
            ORDER BY asked_at DESC LIMIT 50")->fetchAll();
        respond(['questions' => $rows]);
    }

    if ($fn === 'tg_admin_stats') {
        requireAdmin($authUser);
        // Все пользователи с привязанным Telegram
        $linked = $pdo->query("SELECT u.name, u.email, u.role, u.display_role, u.telegram_chat_id, u.legal_entities,
            ts.daily_summary, ts.psc_expiry, ts.price_changed, ts.overdue_delivery,
            ts.data_updates, ts.expiring_items, ts.restaurant_sales, ts.low_stock,
            ts.correction_notifications, ts.chat_notifications,
            ts.so_deadline_summary,
            ts.last_question_at
            FROM users u
            LEFT JOIN telegram_settings ts ON ts.user_name = u.name
            WHERE u.telegram_chat_id IS NOT NULL
            ORDER BY u.name")->fetchAll();

        // Все пользователи без Telegram
        $unlinked = $pdo->query("SELECT name, email, role, display_role FROM users WHERE telegram_chat_id IS NULL ORDER BY name")->fetchAll();

        // Подписки ресторанов (с настройками уведомлений и статусом безопасности)
        $restaurantSubs = $pdo->query("SELECT vs.chat_id, vs.restaurant_number, vs.legal_entity_group, vs.created_at,
            vs.first_name, vs.username, vs.verified_at, vs.verified_via, vs.must_reverify_by,
            CASE
                WHEN vs.verified_at IS NOT NULL THEN 'verified'
                WHEN vs.must_reverify_by IS NOT NULL AND vs.must_reverify_by > NOW() THEN 'temporary'
                WHEN vs.must_reverify_by IS NOT NULL AND vs.must_reverify_by <= NOW() THEN 'expired'
                ELSE 'unverified'
            END AS verify_status,
            vs.notify_so_reminders, vs.notify_so_sessions, vs.notify_confirmations,
            vs.notify_stock_reminders, vs.notify_stock_sessions,
            r.address, r.city, r.region
            FROM ro_telegram_subs vs
            LEFT JOIN restaurants r
              ON r.number = vs.restaurant_number
             AND r.legal_entity_group COLLATE utf8mb4_unicode_ci = vs.legal_entity_group COLLATE utf8mb4_unicode_ci
            ORDER BY vs.legal_entity_group, CAST(vs.restaurant_number AS UNSIGNED), vs.created_at")->fetchAll();

        // Все рестораны для сравнения
        $allRests = $pdo->query("SELECT number, legal_entity_group, address, city, region
            FROM restaurants
            WHERE active=1 AND legal_entity_group IN ('BK_VM', 'PS')
            ORDER BY legal_entity_group, CAST(number AS UNSIGNED)")->fetchAll();

        // Лог напоминаний (последние 100)
        $reminders = $pdo->query("SELECT vrl.session_id, vrl.restaurant_number, vrl.delivery_date, vrl.reminder_type, vrl.sent_at,
            r.address, r.city
            FROM veg_reminder_log vrl
            LEFT JOIN restaurants r ON r.number = vrl.restaurant_number AND r.legal_entity_group = 'BK_VM'
            ORDER BY vrl.sent_at DESC LIMIT 100")->fetchAll();

        // Корректировки (за последние 7 дней)
        $corrStats = $pdo->query("SELECT
            SUM(status = 'pending') as pending,
            SUM(status = 'in_progress') as in_progress,
            SUM(status = 'approved') as approved,
            SUM(status = 'rejected') as rejected
            FROM order_corrections
            WHERE created_at > NOW() - INTERVAL 7 DAY")->fetch();

        respond([
            'linked_users' => $linked,
            'unlinked_users' => $unlinked,
            'restaurant_subs' => $restaurantSubs,
            'all_restaurants' => $allRests,
            'reminder_log' => $reminders,
            'correction_stats' => $corrStats ?: ['pending' => 0, 'in_progress' => 0, 'approved' => 0, 'rejected' => 0],
        ]);
    }

    if ($fn === 'tg_admin_send_message') {
        requireAdmin($authUser);
        $chatIds = $body['chat_ids'] ?? [];
        $message = trim($body['message'] ?? '');
        if (!$message || empty($chatIds)) respond(['error' => 'Нужен текст и получатели'], 400);

        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if (!$botToken) respond(['error' => 'Нет TELEGRAM_BOT_TOKEN'], 500);

        // curl_multi через хелпер: одновременная отправка вместо синхронного цикла.
        $sent = sendTelegramBulk($botToken, $chatIds, $message);
        // Логируем рассылку
        try {
            $sender = $body['sender'] ?? 'admin';
            $pdo->prepare("INSERT INTO tg_broadcast_log (sender, message, recipient_count) VALUES (?, ?, ?)")
                ->execute([$sender, mb_substr($message, 0, 1000), $sent]);
        } catch (Exception $e) { /* таблица может не существовать */ }
        respond(['success' => true, 'sent' => $sent, 'total' => count($chatIds)]);
    }

    if ($fn === 'tg_admin_broadcast_history') {
        requireAdmin($authUser);
        $rows = $pdo->query("SELECT id, sender, message, recipient_count, sent_at FROM tg_broadcast_log ORDER BY sent_at DESC LIMIT 50")->fetchAll();
        respond(['broadcasts' => $rows]);
    }

    if ($fn === 'tg_admin_send_restaurant_reminder') {
        requireAdmin($authUser);
        $restNumber = $body['restaurant_number'] ?? '';
        $message = trim($body['message'] ?? '');
        $group = $body['legal_entity_group'] ?? '';
        if (!$restNumber || !$message) respond(['error' => 'Укажите ресторан и текст'], 400);
        if (!in_array($group, ['BK_VM', 'PS'], true)) respond(['error' => 'Укажите группу юрлиц (BK_VM или PS)'], 400);

        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if (!$botToken) respond(['error' => 'Нет TELEGRAM_BOT_TOKEN'], 500);

        // Подписчики ресторана только из указанной группы юрлиц.
        // Номера BK_VM и PS могут совпадать (BK_VM использует 1..N, PS — 1001+),
        // фильтр через JOIN restaurants гарантирует, что мы пишем тем, кому надо.
        $subs = $pdo->prepare("SELECT DISTINCT s.chat_id FROM ro_telegram_subs s
            JOIN restaurants r ON r.number = s.restaurant_number AND r.legal_entity_group = ?
            WHERE s.restaurant_number = ?");
        $subs->execute([$group, $restNumber]);
        $chatIds = $subs->fetchAll(PDO::FETCH_COLUMN);

        if (empty($chatIds)) respond(['error' => 'Нет подписчиков у этого ресторана'], 400);

        $sent = sendTelegramBulk($botToken, $chatIds, $message);
        respond(['success' => true, 'sent' => $sent, 'total' => count($chatIds)]);
    }

    if ($fn === 'tg_admin_toggle_setting') {
        requireAdmin($authUser);
        $userName = $body['user_name'] ?? '';
        $field = $body['field'] ?? '';
        $allowed = ['daily_summary', 'psc_expiry', 'price_changed', 'overdue_delivery', 'data_updates', 'expiring_items', 'restaurant_sales', 'low_stock', 'correction_notifications', 'chat_notifications', 'so_deadline_summary'];
        if (!$userName || !in_array($field, $allowed)) respond(['error' => 'Неверные параметры'], 400);
        $pdo->prepare("UPDATE telegram_settings SET `$field` = NOT `$field` WHERE user_name = ?")->execute([$userName]);
        $newVal = $pdo->prepare("SELECT `$field` FROM telegram_settings WHERE user_name = ?");
        $newVal->execute([$userName]);
        $val = $newVal->fetchColumn();
        respond(['success' => true, 'value' => (bool)$val]);
    }

    if ($fn === 'tg_admin_toggle_rest_notif') {
        requireAdmin($authUser);
        $chatId = $body['chat_id'] ?? '';
        $field = $body['field'] ?? '';
        // restaurant_number обязателен — у одного chat_id могут быть подписки на несколько ресторанов;
        // без него UPDATE влиял бы сразу на все его подписки.
        $restNumber = $body['restaurant_number'] ?? '';
        $allowed = ['notify_so_reminders', 'notify_so_sessions', 'notify_confirmations', 'notify_stock_reminders', 'notify_stock_sessions'];
        if (!$chatId || !$restNumber || !in_array($field, $allowed)) respond(['error' => 'Неверные параметры'], 400);
        $pdo->prepare("UPDATE ro_telegram_subs SET `$field` = NOT `$field` WHERE chat_id = ? AND restaurant_number = ?")->execute([$chatId, $restNumber]);
        $newVal = $pdo->prepare("SELECT `$field` FROM ro_telegram_subs WHERE chat_id = ? AND restaurant_number = ? LIMIT 1");
        $newVal->execute([$chatId, $restNumber]);
        $val = $newVal->fetchColumn();
        respond(['success' => true, 'value' => (bool)$val]);
    }

    if ($fn === 'tg_admin_unlink_user') {
        requireAdmin($authUser);
        $userName = $body['user_name'] ?? '';
        if (!$userName) respond(['error' => 'Не указан пользователь'], 400);
        $pdo->prepare("UPDATE users SET telegram_chat_id = NULL WHERE name = ?")->execute([$userName]);
        respond(['success' => true]);
    }

    // Массовая отвязка просроченных подписок ресторанов: удаляем только те
    // строки, у которых дедлайн перепривязки уже прошёл и подтверждения нет.
    if ($fn === 'tg_admin_unlink_expired') {
        requireAdmin($authUser);
        $confirm = !empty($body['confirm']);
        $countSt = $pdo->query("
            SELECT COUNT(*) c
            FROM ro_telegram_subs
            WHERE verified_at IS NULL
              AND must_reverify_by IS NOT NULL
              AND must_reverify_by < NOW()
        ");
        $count = (int)($countSt->fetch()['c'] ?? 0);

        if (!$confirm) {
            respond(['count' => $count]);
        }

        $del = $pdo->exec("
            DELETE FROM ro_telegram_subs
            WHERE verified_at IS NULL
              AND must_reverify_by IS NOT NULL
              AND must_reverify_by < NOW()
        ");

        $caller = getSessionUser($pdo);
        $callerName = $caller['name'] ?? 'unknown';
        auditLog($pdo, 'tg_unlink_expired', 'ro_telegram_subs', '', $callerName, ['deleted' => $del]);

        respond(['success' => true, 'deleted' => $del]);
    }

    // ─── Мониторинг отправок бота (страница /admin?tab=tg-monitor) ───
    // Возвращает агрегаты из tg_send_log за последние 24 часа + срез по
    // заблокировавшим бота из users/ro_telegram_subs. Тяжёлых JOIN нет —
    // только индексированные SELECT по ts/ok.
    if ($fn === 'tg_admin_monitor') {
        requireAdmin($authUser);

        // Сводные счётчики за 24 часа
        $row = $pdo->query("
            SELECT
                COUNT(*) AS total_24h,
                SUM(CASE WHEN ok = 1 THEN 1 ELSE 0 END) AS ok_24h,
                SUM(CASE WHEN ok = 0 THEN 1 ELSE 0 END) AS fail_24h
            FROM tg_send_log
            WHERE ts > NOW() - INTERVAL 24 HOUR
        ")->fetch();
        $totals = [
            'total_24h' => (int)($row['total_24h'] ?? 0),
            'ok_24h'    => (int)($row['ok_24h'] ?? 0),
            'fail_24h'  => (int)($row['fail_24h'] ?? 0),
        ];

        // Разрезы за 24 часа: по методу
        $byMethod = $pdo->query("
            SELECT method, COUNT(*) AS total, SUM(CASE WHEN ok = 1 THEN 1 ELSE 0 END) AS ok_count
            FROM tg_send_log
            WHERE ts > NOW() - INTERVAL 24 HOUR
            GROUP BY method
            ORDER BY total DESC
            LIMIT 12
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($byMethod as &$m) {
            $m['total'] = (int)$m['total'];
            $m['ok_count'] = (int)$m['ok_count'];
            $m['fail_count'] = $m['total'] - $m['ok_count'];
        }
        unset($m);

        // Разрез по error_code (только ошибки)
        $byErrorCode = $pdo->query("
            SELECT error_code, COUNT(*) AS cnt
            FROM tg_send_log
            WHERE ts > NOW() - INTERVAL 24 HOUR
              AND ok = 0
              AND error_code IS NOT NULL
            GROUP BY error_code
            ORDER BY cnt DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($byErrorCode as &$e) {
            $e['error_code'] = (int)$e['error_code'];
            $e['cnt'] = (int)$e['cnt'];
        }
        unset($e);

        // Топ chat_id с ошибками за 24 часа (часто = заблокированные/удалённые)
        $topFailing = $pdo->query("
            SELECT chat_id, COUNT(*) AS cnt
            FROM tg_send_log
            WHERE ts > NOW() - INTERVAL 24 HOUR
              AND ok = 0
              AND chat_id IS NOT NULL
            GROUP BY chat_id
            ORDER BY cnt DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($topFailing as &$t) {
            $t['chat_id'] = (int)$t['chat_id'];
            $t['cnt'] = (int)$t['cnt'];
        }
        unset($t);

        // Последние 20 ошибок
        $lastFailures = $pdo->query("
            SELECT method, chat_id, http_code, error_code, error_text, ts
            FROM tg_send_log
            WHERE ts > NOW() - INTERVAL 24 HOUR AND ok = 0
            ORDER BY id DESC
            LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Поминутный таймлайн за час и почасовой за сутки
        $timeline24h = $pdo->query("
            SELECT DATE_FORMAT(ts, '%Y-%m-%d %H:00') AS bucket,
                   COUNT(*) AS total,
                   SUM(CASE WHEN ok = 0 THEN 1 ELSE 0 END) AS fail_count
            FROM tg_send_log
            WHERE ts > NOW() - INTERVAL 24 HOUR
            GROUP BY bucket
            ORDER BY bucket
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($timeline24h as &$tl) {
            $tl['total']      = (int)$tl['total'];
            $tl['fail_count'] = (int)$tl['fail_count'];
        }
        unset($tl);

        // Сколько пользователей сейчас заблокировало бота
        $blockedUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE tg_blocked_at IS NOT NULL")->fetchColumn();
        $blockedRoSubs = (int)$pdo->query("SELECT COUNT(*) FROM ro_telegram_subs WHERE tg_blocked_at IS NOT NULL")->fetchColumn();

        // Сколько AI-провайдеров сейчас в блокировке
        $aiBlocked = $pdo->query("
            SELECT provider, model, blocked_until, reason
            FROM tg_provider_block
            WHERE blocked_until > NOW()
            ORDER BY blocked_until DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        respond([
            'totals'         => $totals,
            'by_method'      => $byMethod,
            'by_error_code'  => $byErrorCode,
            'top_failing'    => $topFailing,
            'last_failures'  => $lastFailures,
            'timeline_24h'   => $timeline24h,
            'blocked'        => [
                'users'        => $blockedUsers,
                'ro_telegram'  => $blockedRoSubs,
            ],
            'ai_blocked'     => $aiBlocked,
            'generated_at'   => date('Y-m-d H:i:s'),
        ]);
    }
