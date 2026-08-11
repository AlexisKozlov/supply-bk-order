<?php
/**
 * RPC: единый «пульс» портала.
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName,
 * $ROLE_TEMPLATES, $ACCESS_LEVELS.
 *
 * Раньше открытая вкладка каждые полминуты стучалась шестью отдельными
 * запросами: отметка присутствия, проверка техработ, счётчик чата,
 * корректировки, неизвестные штрихкоды и объявления. За сутки набегало
 * около 15 тысяч обращений на ровном месте — 87% всей нагрузки API было
 * таким опросом. Теперь это один запрос: он и присутствие отмечает, и
 * возвращает всё, что нужно шапке.
 *
 * Счётчики считаются только по тем разделам, к которым у человека есть
 * доступ. Нет доступа — в ответе null, значок не рисуется.
 */

    if ($fn === 'portal_pulse') {
        if (!$authUser) respond(['error' => 'Не авторизован'], 401);

        $perms = resolvePermissions($authUser['role'] ?? 'user', $authUser['permissions'] ?? null, $ROLE_TEMPLATES);
        $can = function ($module) use ($perms, $ACCESS_LEVELS) {
            return ($ACCESS_LEVELS[$perms[$module] ?? 'none'] ?? 0) >= ($ACCESS_LEVELS['view'] ?? 1);
        };
        $isAdmin = ($authUser['role'] ?? '') === 'admin';

        // ── Присутствие: то же, что делал отдельный heartbeat ──
        if ($authUserName !== '') {
            $page = substr((string)($body['page'] ?? ''), 0, 100);
            $editing = $body['editing_order_id'] ?? null;
            $st = $pdo->prepare("
                INSERT INTO user_presence (user_name, page, last_seen, editing_order_id)
                VALUES (?, ?, NOW(), ?)
                ON DUPLICATE KEY UPDATE page = VALUES(page), last_seen = NOW(), editing_order_id = VALUES(editing_order_id)
            ");
            $st->execute([$authUserName, $page, $editing]);
        }

        // ── Техработы ──
        // Админ проходит сквозь режим техработ, поэтому ему и не считаем.
        $maintenance = null;
        if (!$isAdmin) {
            $st = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ('maintenance_mode','maintenance_message','maintenance_end_time')");
            $st->execute();
            $mm = 'false'; $msg = ''; $endTime = null;
            foreach ($st->fetchAll() as $r) {
                if ($r['key'] === 'maintenance_mode')    $mm      = $r['value'];
                if ($r['key'] === 'maintenance_message') $msg     = $r['value'];
                if ($r['key'] === 'maintenance_end_time') $endTime = $r['value'];
            }
            // Автовыключение по истечении таймера — как в check_maintenance.
            if ($mm === 'true' && $endTime) {
                $endTs = strtotime($endTime);
                if ($endTs && $endTs <= time()) {
                    $pdo->prepare("UPDATE settings SET value='false' WHERE `key`='maintenance_mode'")->execute();
                    $pdo->prepare("UPDATE settings SET value='' WHERE `key`='maintenance_end_time'")->execute();
                    $mm = 'false'; $msg = ''; $endTime = null;
                }
            }
            $maintenance = [
                'maintenance_mode'     => $mm === 'true',
                'maintenance_message'  => $msg ?: null,
                'maintenance_end_time' => $endTime ?: null,
            ];
        }

        // ── Значки в боковом меню ──
        $badges = ['chat' => null, 'corrections' => null, 'scan_unknown' => null];

        if ($can('chat')) {
            $badges['chat'] = (int)$pdo->query("
                SELECT COUNT(*) FROM chat_messages cm
                JOIN chat_conversations cc ON cc.id = cm.conversation_id
                WHERE cm.is_read = 0 AND cm.direction = 'from_restaurant' AND cc.status = 'open'
            ")->fetchColumn();
        }

        if ($can('corrections')) {
            // Раньше фронт тянул до 100 строк и считал их длину — здесь
            // достаточно числа.
            $badges['corrections'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM order_corrections WHERE status = 'pending'"
            )->fetchColumn();
        }

        if ($can('restaurant-orders')) {
            $badges['scan_unknown'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM ro_scan_unknown WHERE status = 'new'"
            )->fetchColumn();
        }

        // ── Объявления (то же, что get_active_broadcasts) ──
        $broadcasts = [];
        if ($authUserName !== '') {
            $su = $pdo->prepare("SELECT created_at FROM users WHERE name = ?");
            $su->execute([$authUserName]);
            $uRow = $su->fetch();
            $userCreated = $uRow['created_at'] ?? null;

            $sql = "SELECT id, title, message, created_by, created_at FROM notifications
                    WHERE type = 'broadcast'
                      AND created_at > NOW() - INTERVAL 24 HOUR
                      AND NOT JSON_CONTAINS(COALESCE(read_by, '[]'), JSON_QUOTE(?))
                      AND NOT JSON_CONTAINS(COALESCE(deleted_by, '[]'), JSON_QUOTE(?))";
            $params = [$authUserName, $authUserName];
            if ($userCreated) { $sql .= " AND created_at > ?"; $params[] = $userCreated; }
            $sql .= " ORDER BY created_at DESC LIMIT 5";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $broadcasts = $st->fetchAll();
        }

        respond([
            'maintenance' => $maintenance,
            'badges'      => $badges,
            'broadcasts'  => $broadcasts,
        ]);
    }
