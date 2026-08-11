<?php
/**
 * RPC: личные настройки сотрудника.
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName.
 *
 * Всё здесь — только про себя: человек видит и меняет свои сеансы, свою
 * привязку Telegram и свою историю действий. Чужие данные эти методы не
 * отдают даже администратору — для админских задач есть отдельные RPC.
 */

    // ── Свои сеансы (устройства, где выполнен вход) ──
    if ($fn === 'my_sessions') {
        if (!$authUser) respond(['error' => 'Не авторизован'], 401);
        $current = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
        $st = $pdo->prepare("
            SELECT id, token, created_at, expires_at, ip_address, user_agent
            FROM user_sessions
            WHERE user_name = ? AND expires_at > NOW()
            ORDER BY created_at DESC
        ");
        $st->execute([$authUserName]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'         => (int)$r['id'],
                'created_at' => $r['created_at'],
                'expires_at' => $r['expires_at'],
                'ip'         => $r['ip_address'],
                'agent'      => $r['user_agent'],
                // Токен наружу не отдаём — только признак «это устройство».
                'current'    => ($current !== '' && hash_equals((string)$r['token'], (string)$current)),
            ];
        }
        respond(['sessions' => $out]);
    }

    // ── Закрыть сеанс: один по id или все, кроме текущего ──
    if ($fn === 'revoke_my_session') {
        if (!$authUser) respond(['error' => 'Не авторизован'], 401);
        $current = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
        $all = !empty($body['all']);

        if ($all) {
            // Текущее устройство оставляем: иначе человек выкидывает сам себя
            // и не понимает, что произошло.
            $st = $pdo->prepare("DELETE FROM user_sessions WHERE user_name = ? AND token <> ?");
            $st->execute([$authUserName, $current]);
            respond(['closed' => $st->rowCount()]);
        }

        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) respond(['error' => 'Не указан сеанс'], 400);
        // Чужой сеанс закрыть нельзя — фильтр по имени обязателен.
        $st = $pdo->prepare("DELETE FROM user_sessions WHERE id = ? AND user_name = ?");
        $st->execute([$id, $authUserName]);
        respond(['closed' => $st->rowCount()]);
    }

    // ── Отвязать Telegram ──
    // Раньше отвязать было нечем: подключить бота можно, а сменить телефон —
    // только через администратора.
    if ($fn === 'telegram_unlink') {
        if (!$authUser) respond(['error' => 'Не авторизован'], 401);
        $pdo->prepare("UPDATE users SET telegram_chat_id = NULL WHERE name = ?")->execute([$authUserName]);
        respond(['success' => true]);
    }

    // ── Проверочное сообщение в Telegram ──
    if ($fn === 'telegram_test') {
        if (!$authUser) respond(['error' => 'Не авторизован'], 401);
        $st = $pdo->prepare("SELECT telegram_chat_id FROM users WHERE name = ?");
        $st->execute([$authUserName]);
        $chatId = $st->fetchColumn();
        if (!$chatId) respond(['error' => 'Telegram не подключён'], 400);

        $token = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if (!$token) respond(['error' => 'Бот не настроен на сервере'], 500);

        // tg_client подключается лениво: в helpers.php он требуется внутри
        // функций отправки, а здесь мы зовём клиента напрямую.
        require_once __DIR__ . '/../tg_client.php';

        $res = tgClientSend((int)$chatId, "✅ Проверка связи\n\nЭто сообщение отправил портал закупок. Уведомления доходят.", [
            'token' => $token,
            'pdo'   => $pdo,
        ]);
        if (empty($res['ok'])) {
            // Частый случай — человек заблокировал бота: пишем честно, а не
            // «что-то пошло не так».
            $why = (int)($res['error_code'] ?? 0) === 403
                ? 'Бот заблокирован в Telegram — разблокируйте его и попробуйте снова'
                : ('Telegram не принял сообщение' . (!empty($res['description']) ? ': ' . $res['description'] : ''));
            respond(['error' => $why], 502);
        }
        respond(['success' => true]);
    }

    // ── Мои последние действия ──
    if ($fn === 'my_activity') {
        if (!$authUser) respond(['error' => 'Не авторизован'], 401);
        $limit = min(100, max(10, (int)($body['limit'] ?? 50)));
        $st = $pdo->prepare("
            SELECT action, entity_type, entity_id, legal_entity, details, created_at
            FROM audit_log
            WHERE user_name = ?
            ORDER BY id DESC
            LIMIT {$limit}
        ");
        $st->execute([$authUserName]);
        respond(['items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }
