<?php
/**
 * RPC-эндпоинты (публичные и приватные).
 * Подключается из index.php.
 */
// ═══ RPC (публичные — без API-ключа) ═══
if ($endpoint === 'rpc') {
    $fn = $subpoint ?? '';
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // --- Публичные RPC (доступны без авторизации) ---

    if ($fn === 'check_user_password') {
        $email = $body['user_email'] ?? ''; $pass = $body['user_password'] ?? '';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['success'=>false,'error'=>'invalid_email'], 400);
        if (!checkRateLimit($pdo, $clientIp)) respond(['success'=>false,'error'=>'too_many_attempts'], 429);
        $s = $pdo->prepare("SELECT id,name,password,role,display_role,legal_entities,permissions,created_at,telegram_chat_id FROM users WHERE email=?");
        $s->execute([$email]); $u = $s->fetch();
        if (!$u) { recordFailedLogin($pdo, $clientIp, $email); respond(['success'=>false,'error'=>'invalid_credentials']); }
        if (!verifyAndMigratePassword($pdo, $u['name'], $pass, $u['password'])) { recordFailedLogin($pdo, $clientIp, $email); respond(['success'=>false,'error'=>'invalid_credentials']); }
        $le = $u['legal_entities'];
        $le = ($le && is_string($le)) ? (json_decode($le, true) ?? []) : [];
        $displayRole = $u['display_role'] ?? null;
        $permsRaw = $u['permissions'] ?? null;
        $permsDecoded = ($permsRaw && is_string($permsRaw)) ? json_decode($permsRaw, true) : null;
        $sessionToken = createSessionToken($pdo, $u['name']);
        try { $pdo->prepare("INSERT INTO login_log (email, user_name, ip, created_at) VALUES (?, ?, ?, NOW())")->execute([$email, $u['name'], $clientIp]); } catch (PDOException $e) {}
        $mm = $pdo->prepare("SELECT `key`,`value` FROM settings WHERE `key` IN ('maintenance_mode','maintenance_message')"); $mm->execute();
        $mmRows = $mm->fetchAll(); $maintenanceVal = 'false'; $maintenanceMsg = '';
        foreach ($mmRows as $mr) { if ($mr['key'] === 'maintenance_mode') $maintenanceVal = $mr['value']; if ($mr['key'] === 'maintenance_message') $maintenanceMsg = $mr['value']; }
        respond(['success'=>true,'user'=>['name'=>$u['name'],'role'=>$u['role']??'user','display_role'=>$displayRole,'legal_entities'=>$le,'permissions'=>$permsDecoded,'created_at'=>$u['created_at'] ?? null,'telegram_connected'=>!empty($u['telegram_chat_id'])],'session_token'=>$sessionToken,'maintenance_mode'=>$maintenanceVal==='true','maintenance_message'=>$maintenanceMsg ?: null]);
    }
    if ($fn === 'check_legacy_password') {
        $pwd = $body['pwd'] ?? '';
        if (!checkRateLimit($pdo, $clientIp)) respond(['success'=>false,'error'=>'too_many_attempts'], 429);
        $s = $pdo->prepare("SELECT value FROM settings WHERE `key`='order_calculator_password'"); $s->execute();
        $stored = $s->fetchColumn();
        if ($stored) {
            $ok = password_verify($pwd, $stored) || hash_equals($stored, $pwd);
            if ($ok) {
                if (hash_equals($stored, $pwd)) {
                    $hash = password_hash($pwd, PASSWORD_BCRYPT);
                    $pdo->prepare("UPDATE settings SET value=? WHERE `key`='order_calculator_password'")->execute([$hash]);
                }
                respond(['success'=>true]);
            }
            recordFailedLogin($pdo, $clientIp, '_legacy');
        }
        respond(['success'=>false]);
    }
    if ($fn === 'check_maintenance') {
        $s = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ('maintenance_mode','maintenance_message','maintenance_end_time')"); $s->execute();
        $rows = $s->fetchAll(); $mm = 'false'; $msg = ''; $endTime = null;
        foreach ($rows as $r) {
            if ($r['key'] === 'maintenance_mode') $mm = $r['value'];
            if ($r['key'] === 'maintenance_message') $msg = $r['value'];
            if ($r['key'] === 'maintenance_end_time') $endTime = $r['value'];
        }
        // Автовыключение: если таймер истёк — выключаем техработы
        if ($mm === 'true' && $endTime) {
            $endTs = strtotime($endTime);
            if ($endTs && $endTs <= time()) {
                $pdo->prepare("UPDATE settings SET value='false' WHERE `key`='maintenance_mode'")->execute();
                $pdo->prepare("UPDATE settings SET value='' WHERE `key`='maintenance_end_time'")->execute();
                respond(['maintenance_mode' => false, 'maintenance_message' => null, 'maintenance_end_time' => null]);
            }
        }
        respond(['maintenance_mode' => $mm === 'true', 'maintenance_message' => $msg ?: null, 'maintenance_end_time' => $endTime ?: null]);
    }
    // Гостевые эндпоинты (публичная страница поиска карточек)
    if ($fn === 'guest_heartbeat') {
        $sid = $body['session_id'] ?? '';
        $page = $body['page'] ?? 'search-cards';
        if ($sid && preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $sid)) {
            $s = $pdo->prepare("INSERT INTO guest_presence (session_id, page, last_seen) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE page=VALUES(page), last_seen=NOW()");
            $s->execute([$sid, substr($page, 0, 100)]);
        }
        respond(['success' => true]);
    }
    if ($fn === 'get_guest_count') {
        // Чистим старые записи (старше 5 минут)
        $pdo->exec("DELETE FROM guest_presence WHERE last_seen < NOW() - INTERVAL 5 MINUTE");
        $s = $pdo->query("SELECT COUNT(*) as cnt FROM guest_presence WHERE last_seen > NOW() - INTERVAL 1 MINUTE");
        respond($s->fetch());
    }
    if ($fn === 'log_card_search') {
        if (!checkRateLimit($pdo, $clientIp, 30, 1)) respond(['success' => true]); // Тихий rate-limit: макс 30 поисков/мин
        $q = $body['query'] ?? '';
        $found = $body['found'] ?? false;
        $matchType = $body['match_type'] ?? null;
        $matchedId = $body['matched_card_id'] ?? null;
        if ($q && mb_strlen($q) <= 200) {
            $s = $pdo->prepare("INSERT INTO search_logs (query, found, match_type, matched_card_id, created_at) VALUES (?, ?, ?, ?, NOW())");
            $s->execute([mb_substr($q, 0, 200), $found ? 1 : 0, $matchType ? mb_substr($matchType, 0, 50) : null, $matchedId]);
        }
        respond(['success' => true]);
    }
    if ($fn === 'get_cards') {
        $s = $pdo->query("SELECT id, name, analogs FROM cards ORDER BY name");
        respond($s->fetchAll());
    }
    if ($fn === 'get_cards_last_update') {
        $s = $pdo->prepare("SELECT `value` FROM settings WHERE `key`='last_update'"); $s->execute();
        $row = $s->fetch();
        respond($row ?: ['value' => null]);
    }

    // ═══ DEFICIT: публичные RPC (форма сбора остатков — legacy) ═══
    if ($fn === 'deficit_validate_token') {
        $tokenVal = $body['token_value'] ?? '';
        if (!$tokenVal || !preg_match('/^[a-f0-9]{64}$/', $tokenVal)) respond(['error' => 'invalid_token']);
        $s = $pdo->prepare("SELECT id, legal_entity, product_name, expires_at FROM deficit_tokens WHERE token = ?");
        $s->execute([$tokenVal]);
        $row = $s->fetch();
        if (!$row) respond(['error' => 'not_found', 'expired' => true]);
        if (strtotime($row['expires_at']) < time()) respond(['error' => 'expired', 'expired' => true]);
        respond(['id' => $row['id'], 'legal_entity' => $row['legal_entity'], 'product_name' => $row['product_name']]);
    }
    if ($fn === 'deficit_get_restaurants') {
        $tokenVal = $body['token_value'] ?? '';
        if (!$tokenVal || !preg_match('/^[a-f0-9]{64}$/', $tokenVal)) respond(['error' => 'invalid_token']);
        $s = $pdo->prepare("SELECT legal_entity FROM deficit_tokens WHERE token = ? AND expires_at > NOW()");
        $s->execute([$tokenVal]);
        $row = $s->fetch();
        if (!$row) respond(['error' => 'expired']);
        $le = $row['legal_entity'];
        $group = getEntityGroup($le);
        $s2 = $pdo->prepare("SELECT id, number, address, city FROM restaurants WHERE legal_entity_group = ? ORDER BY sort_order");
        $s2->execute([$group]);
        respond($s2->fetchAll());
    }
    if ($fn === 'deficit_submit_stock') {
        $tokenVal = $body['token_value'] ?? '';
        $restNum = $body['restaurant_num'] ?? '';
        $stockVal = floatval($body['stock_value'] ?? 0);
        if ($stockVal < 0 || $stockVal > 999999) respond(['error' => 'invalid_stock_value'], 400);
        if (!$tokenVal || !preg_match('/^[a-f0-9]{64}$/', $tokenVal)) respond(['error' => 'invalid_token']);
        if (!$restNum || !preg_match('/^\d{1,5}$/', $restNum)) respond(['error' => 'invalid_restaurant']);
        if (!checkRateLimit($pdo, $clientIp, 60, 5)) respond(['error' => 'too_many_attempts'], 429);
        $s = $pdo->prepare("SELECT id FROM deficit_tokens WHERE token = ? AND expires_at > NOW()");
        $s->execute([$tokenVal]);
        $tok = $s->fetch();
        if (!$tok) respond(['error' => 'expired']);
        $s2 = $pdo->prepare("INSERT INTO deficit_restaurant_stock (token_id, restaurant_number, stock, submitted_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE stock = VALUES(stock), submitted_at = NOW()");
        $s2->execute([$tok['id'], $restNum, $stockVal]);
        respond(['success' => true]);
    }

    // ═══ STOCK COLLECTION: публичные RPC (новая форма) ═══
    if ($fn === 'sc_validate_token') {
        $tokenVal = $body['token_value'] ?? '';
        if (!$tokenVal || !preg_match('/^[a-f0-9]{64}$/', $tokenVal)) respond(['error' => 'invalid_token']);
        $s = $pdo->prepare("SELECT t.id as token_id, t.collection_id, t.expires_at, c.legal_entity, c.name as collection_name FROM stock_collection_tokens t JOIN stock_collections c ON c.id = t.collection_id WHERE t.token = ?");
        $s->execute([$tokenVal]);
        $row = $s->fetch();
        if (!$row) respond(['error' => 'not_found', 'expired' => true]);
        if (strtotime($row['expires_at']) < time()) respond(['error' => 'expired', 'expired' => true]);
        // Товары
        $s2 = $pdo->prepare("SELECT id, product_name, product_sku, unit, sort_order FROM stock_collection_products WHERE collection_id = ? ORDER BY sort_order");
        $s2->execute([$row['collection_id']]);
        $products = $s2->fetchAll();
        // Рестораны, которые уже ответили
        $s3 = $pdo->prepare("SELECT DISTINCT restaurant_number FROM stock_collection_data WHERE collection_id = ?");
        $s3->execute([$row['collection_id']]);
        $submitted = array_column($s3->fetchAll(), 'restaurant_number');
        respond(['collection_id' => $row['collection_id'], 'legal_entity' => $row['legal_entity'], 'collection_name' => $row['collection_name'], 'products' => $products, 'submitted_restaurants' => $submitted]);
    }
    if ($fn === 'sc_get_restaurants') {
        $tokenVal = $body['token_value'] ?? '';
        if (!$tokenVal || !preg_match('/^[a-f0-9]{64}$/', $tokenVal)) respond(['error' => 'invalid_token']);
        $s = $pdo->prepare("SELECT c.legal_entity FROM stock_collection_tokens t JOIN stock_collections c ON c.id = t.collection_id WHERE t.token = ? AND t.expires_at > NOW()");
        $s->execute([$tokenVal]);
        $row = $s->fetch();
        if (!$row) respond(['error' => 'expired']);
        $le = $row['legal_entity'];
        $group = getEntityGroup($le);
        $s2 = $pdo->prepare("SELECT id, number, address, city FROM restaurants WHERE legal_entity_group = ? ORDER BY sort_order");
        $s2->execute([$group]);
        respond($s2->fetchAll());
    }
    if ($fn === 'sc_submit_stock') {
        $tokenVal = $body['token_value'] ?? '';
        $restNum = $body['restaurant_num'] ?? '';
        $items = $body['items'] ?? []; // [{product_id, stock}]
        if (!$tokenVal || !preg_match('/^[a-f0-9]{64}$/', $tokenVal)) respond(['error' => 'invalid_token']);
        if (!$restNum || !preg_match('/^\d{1,5}$/', $restNum)) respond(['error' => 'invalid_restaurant']);
        if (!checkRateLimit($pdo, $clientIp, 60, 5)) respond(['error' => 'too_many_attempts'], 429);
        $s = $pdo->prepare("SELECT collection_id FROM stock_collection_tokens WHERE token = ? AND expires_at > NOW()");
        $s->execute([$tokenVal]);
        $tok = $s->fetch();
        if (!$tok) respond(['error' => 'expired']);
        $collId = $tok['collection_id'];
        // Загружаем допустимые product_id для данной коллекции
        $validPids = $pdo->prepare("SELECT id FROM stock_collection_products WHERE collection_id = ?");
        $validPids->execute([$collId]);
        $allowedPids = array_column($validPids->fetchAll(), 'id');
        $allowedSet = array_flip($allowedPids);
        $ins = $pdo->prepare("INSERT INTO stock_collection_data (collection_id, product_id, restaurant_number, stock, source, submitted_at) VALUES (?, ?, ?, ?, 'form', NOW()) ON DUPLICATE KEY UPDATE stock = VALUES(stock), submitted_at = NOW()");
        $pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                $pid = intval($item['product_id'] ?? 0);
                $sv = floatval($item['stock'] ?? 0);
                if ($pid > 0 && isset($allowedSet[$pid])) $ins->execute([$collId, $pid, $restNum, $sv]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('sc_submit_stock error: ' . $e->getMessage());
            respond(['error' => 'save_failed'], 500);
        }
        respond(['success' => true]);
    }

    // Логирование ошибок фронтенда (публичный, без авторизации)
    if ($fn === 'log_frontend_error') {
        $level = $body['level'] ?? 'error';
        if (!in_array($level, ['error', 'warning', 'info'])) $level = 'error';
        $message = mb_substr($body['message'] ?? 'Unknown error', 0, 5000);
        $stack = isset($body['stack']) ? mb_substr($body['stack'], 0, 10000) : null;
        $userName = $body['user_name'] ?? null;
        $url = isset($body['url']) ? mb_substr($body['url'], 0, 2048) : null;
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 512) : null;
        if (!checkRateLimit($pdo, $clientIp, 10, 1)) respond(['success' => true]); // Тихий rate-limit: макс 10 ошибок/мин
        try {
            $pdo->prepare("INSERT INTO error_logs (level, source, message, stack, user_name, url, user_agent) VALUES (?, 'frontend', ?, ?, ?, ?, ?)")
                ->execute([$level, $message, $stack, $userName, $url, $ua]);
        } catch (PDOException $e) { /* таблица может не существовать */ }
        respond(['success' => true]);
    }

    // Список обновлений (публичный)
    if ($fn === 'get_changelog') {
        try {
            $limit = max(1, min(intval($body['limit'] ?? 50), 200));
            $s = $pdo->prepare("SELECT id, version, title, description, created_by, created_at FROM changelog ORDER BY created_at DESC LIMIT " . $limit);
            $s->execute();
            respond($s->fetchAll());
        } catch (PDOException $e) {
            error_log("get_changelog error: " . $e->getMessage());
            respond([]);
        }
    }

    // Валидация сессии — проверяет session_token и возвращает данные пользователя
    if ($fn === 'validate_session') {
        $sessionUser = getSessionUser($pdo);
        if (!$sessionUser) {
            respond(['valid' => false]);
        }
        $le = ($sessionUser['legal_entities'] && is_string($sessionUser['legal_entities'])) ? (json_decode($sessionUser['legal_entities'], true) ?? []) : [];
        $permsRaw2 = $sessionUser['permissions'] ?? null;
        $permsDecoded2 = ($permsRaw2 && is_string($permsRaw2)) ? json_decode($permsRaw2, true) : null;
        respond(['valid' => true, 'user' => ['name' => $sessionUser['name'], 'role' => $sessionUser['role'] ?? 'user', 'display_role' => $sessionUser['display_role'] ?? null, 'legal_entities' => $le, 'permissions' => $permsDecoded2, 'created_at' => $sessionUser['created_at'] ?? null, 'telegram_connected' => !empty($sessionUser['telegram_chat_id'])]]);
    }

    if ($fn === 'logout') {
        $token = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
        if ($token) {
            $pdo->prepare("DELETE FROM user_sessions WHERE token = ?")->execute([$token]);
        }
        respond(['success' => true]);
    }

    // Проверка токена привязки Telegram (публичный — нужен до авторизации на странице)
    if ($fn === 'get_telegram_link') {
        $token = $body['token'] ?? '';
        if (!$token) respond(['error' => 'no_token']);
        $s = $pdo->prepare("SELECT telegram_chat_id, telegram_username FROM telegram_link_tokens WHERE token = ? AND expires_at > NOW()");
        $s->execute([$token]);
        $row = $s->fetch();
        if (!$row) respond(['error' => 'expired_token']);
        respond(['valid' => true, 'telegram_username' => $row['telegram_username'] ?? null]);
    }

    // Привязка Telegram к аккаунту (требует авторизацию)
    if ($fn === 'confirm_telegram_link') {
        $sessionUser = getSessionUser($pdo);
        if (!$sessionUser) respond(['error' => 'Требуется авторизация'], 401);
        $token = $body['token'] ?? '';
        if (!$token) respond(['error' => 'no_token']);
        $s = $pdo->prepare("SELECT telegram_chat_id, telegram_username FROM telegram_link_tokens WHERE token = ? AND expires_at > NOW()");
        $s->execute([$token]);
        $row = $s->fetch();
        if (!$row) respond(['error' => 'invalid_or_expired_token']);
        $chatId = $row['telegram_chat_id'];
        // Убираем этот chat_id у других пользователей (если был привязан к кому-то ещё)
        $pdo->prepare("UPDATE users SET telegram_chat_id = NULL WHERE telegram_chat_id = ?")->execute([$chatId]);
        // Привязываем к текущему пользователю
        $pdo->prepare("UPDATE users SET telegram_chat_id = ? WHERE name = ?")->execute([$chatId, $sessionUser['name']]);
        // Создаём настройки бота
        $pdo->prepare("INSERT IGNORE INTO telegram_settings (user_name) VALUES (?)")->execute([$sessionUser['name']]);
        // Удаляем использованный токен
        $pdo->prepare("DELETE FROM telegram_link_tokens WHERE token = ?")->execute([$token]);
        // Очищаем просроченные токены заодно
        $pdo->prepare("DELETE FROM telegram_link_tokens WHERE expires_at < NOW()")->execute();
        // Отправляем приветствие в Telegram
        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if ($botToken) {
            $tgMsg = "✅ Аккаунт <b>{$sessionUser['name']}</b> привязан!\n\nТеперь вам доступны все команды бота.\nНажмите /start для меню.";
            sendTelegramMessage($botToken, $chatId, $tgMsg);
        }
        respond(['success' => true, 'user_name' => $sessionUser['name']]);
    }

    // --- Приватные RPC (требуют авторизацию) ---
    if (!checkAuth($pdo)) { respond(['error'=>'Unauthorized'], 401); }

    // Получаем имя авторизованного пользователя из сессии (для защиты от подмены user_name)
    $authUser = getSessionUser($pdo);
    $authUserName = $authUser ? $authUser['name'] : '';

    // Конфигурация RBAC — единый источник правды для фронтенда
    if ($fn === 'get_rbac_config') {
        respond([
            'modules' => array_keys($ROLE_TEMPLATES['admin']),
            'role_templates' => $ROLE_TEMPLATES,
            'access_levels' => $ACCESS_LEVELS,
        ]);
    }

    // ═══ DEFICIT: приватные RPC ═══
    if ($fn === 'deficit_create_token') {
        $le = $body['legal_entity'] ?? '';
        $pname = mb_substr($body['product_name'] ?? '', 0, 255);
        $uname = $authUserName ?: ($body['user_name'] ?? '');
        if (!$le || !$pname) respond(['error' => 'Не все параметры указаны'], 400);
        if (!checkLegalEntityAccess($authUser, $le)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $token = bin2hex(random_bytes(32)); // 64 hex chars
        $expires = date('Y-m-d H:i:s', strtotime('+48 hours'));
        $s = $pdo->prepare("INSERT INTO deficit_tokens (token, legal_entity, product_name, created_by, expires_at) VALUES (?, ?, ?, ?, ?)");
        $s->execute([$token, $le, $pname, $uname, $expires]);
        respond(['token' => $token, 'token_id' => $pdo->lastInsertId(), 'expires_at' => $expires]);
    }

    // ═══ STOCK COLLECTION: приватные RPC ═══
    if ($fn === 'sc_create_collection') {
        $le = $body['legal_entity'] ?? '';
        $name = mb_substr($body['name'] ?? '', 0, 255);
        $products = $body['products'] ?? []; // [{name, sku?, unit}]
        $uname = $authUserName ?: ($body['user_name'] ?? '');
        if (!$le || !$name || empty($products)) respond(['error' => 'Не все параметры указаны'], 400);
        if (!checkLegalEntityAccess($authUser, $le)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        if (count($products) > 5000) respond(['error' => 'Слишком много товаров (макс. 5000)'], 400);
        $pdo->beginTransaction();
        try {
            $s = $pdo->prepare("INSERT INTO stock_collections (legal_entity, name, created_by) VALUES (?, ?, ?)");
            $s->execute([$le, $name, $uname]);
            $collId = $pdo->lastInsertId();
            $ins = $pdo->prepare("INSERT INTO stock_collection_products (collection_id, product_name, product_sku, unit, sort_order) VALUES (?, ?, ?, ?, ?)");
            foreach ($products as $i => $p) {
                $pname = mb_substr($p['name'] ?? '', 0, 255);
                $psku = mb_substr($p['sku'] ?? '', 0, 50) ?: null;
                $punit = in_array($p['unit'] ?? '', ['boxes', 'pieces', 'kg', 'liters']) ? $p['unit'] : 'pieces';
                $ins->execute([$collId, $pname, $psku, $punit, $i]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('sc_create_collection error: ' . $e->getMessage());
            respond(['error' => 'Ошибка создания сбора'], 500);
        }
        respond(['id' => $collId]);
    }
    if ($fn === 'sc_create_token') {
        $collId = intval($body['collection_id'] ?? 0);
        $uname = $authUserName ?: ($body['user_name'] ?? '');
        if (!$collId) respond(['error' => 'Не все параметры указаны'], 400);
        // Проверяем доступ к юрлицу коллекции
        $collCheck = $pdo->prepare("SELECT legal_entity FROM stock_collections WHERE id = ?");
        $collCheck->execute([$collId]);
        $collRow = $collCheck->fetch();
        if (!$collRow) respond(['error' => 'Коллекция не найдена'], 404);
        if ($authUser && !checkLegalEntityAccess($authUser, $collRow['legal_entity'])) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+48 hours'));
        $s = $pdo->prepare("INSERT INTO stock_collection_tokens (collection_id, token, created_by, expires_at) VALUES (?, ?, ?, ?)");
        $s->execute([$collId, $token, $uname, $expires]);
        respond(['token' => $token, 'token_id' => $pdo->lastInsertId(), 'expires_at' => $expires]);
    }
    if ($fn === 'sc_close_collection') {
        $collId = intval($body['collection_id'] ?? 0);
        if (!$collId) respond(['error' => 'Не все параметры указаны'], 400);
        // Проверяем доступ к юрлицу коллекции
        $collCheck = $pdo->prepare("SELECT legal_entity FROM stock_collections WHERE id = ?");
        $collCheck->execute([$collId]);
        $collRow = $collCheck->fetch();
        if (!$collRow) respond(['error' => 'Коллекция не найдена'], 404);
        if ($authUser && !checkLegalEntityAccess($authUser, $collRow['legal_entity'])) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $pdo->prepare("UPDATE stock_collections SET status = 'closed', closed_at = NOW() WHERE id = ?")->execute([$collId]);
        respond(['success' => true]);
    }
    if ($fn === 'sc_get_collection_data') {
        $collId = intval($body['collection_id'] ?? 0);
        if (!$collId) respond(['error' => 'Не все параметры указаны'], 400);
        // Проверяем доступ к юрлицу коллекции
        $collCheck = $pdo->prepare("SELECT legal_entity FROM stock_collections WHERE id = ?");
        $collCheck->execute([$collId]);
        $collRow = $collCheck->fetch();
        if (!$collRow) respond(['error' => 'Коллекция не найдена'], 404);
        if ($authUser && !checkLegalEntityAccess($authUser, $collRow['legal_entity'])) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        // Товары
        $s = $pdo->prepare("SELECT id, product_name, product_sku, unit, sort_order FROM stock_collection_products WHERE collection_id = ? ORDER BY sort_order");
        $s->execute([$collId]);
        $products = $s->fetchAll();
        // Данные
        $s2 = $pdo->prepare("SELECT id, product_id, restaurant_number, stock, source, submitted_at FROM stock_collection_data WHERE collection_id = ? ORDER BY restaurant_number");
        $s2->execute([$collId]);
        $data = $s2->fetchAll();
        // Ответы по ресторанам
        $s3 = $pdo->prepare("SELECT DISTINCT restaurant_number FROM stock_collection_data WHERE collection_id = ?");
        $s3->execute([$collId]);
        $restaurants = array_column($s3->fetchAll(), 'restaurant_number');
        respond(['products' => $products, 'data' => $data, 'restaurants' => $restaurants]);
    }

    if ($fn === 'get_user_list') {
        $caller = getSessionUser($pdo);
        if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
        $s = $pdo->query("SELECT name, email FROM users ORDER BY name");
        respond($s->fetchAll());
    }
    if ($fn === 'change_user_password') {
        if (!checkRateLimit($pdo, $clientIp, 10, 10)) respond(['success'=>false,'error'=>'too_many_attempts'], 429);
        $name = $authUserName; // Менять можно только свой пароль
        $oldPwd = $body['old_password'] ?? '';
        $newPwd = $body['new_password'] ?? '';
        if (!$name || !$oldPwd || !$newPwd) respond(['success'=>false,'error'=>'missing params'], 400);
        if (mb_strlen($newPwd) < 8) respond(['success'=>false,'error'=>'password_too_short'], 400);
        $s = $pdo->prepare("SELECT password FROM users WHERE name=?"); $s->execute([$name]); $u = $s->fetch();
        if (!$u) { recordFailedLogin($pdo, $clientIp, $name); respond(['success'=>false,'error'=>'user_not_found']); }
        if (!verifyAndMigratePassword($pdo, $name, $oldPwd, $u['password'])) { recordFailedLogin($pdo, $clientIp, $name); respond(['success'=>false,'error'=>'wrong_password']); }
        $pdo->prepare("UPDATE users SET password=? WHERE name=?")->execute([password_hash($newPwd, PASSWORD_BCRYPT), $name]);
        $pdo->prepare("DELETE FROM user_sessions WHERE user_name=?")->execute([$name]);
        respond(['success'=>true]);
    }
    // ─── Управление пользователями (только admin) ───
    if ($fn === 'create_user') {
        $caller = getSessionUser($pdo);
        if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
        $callerName = $caller['name'];
        $name = trim($body['name'] ?? '');
        $email = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';
        $role = $body['role'] ?? 'user';
        $displayRole = $body['display_role'] ?? null;
        $legalEntities = $body['legal_entities'] ?? '[]';
        $permissions = $body['permissions'] ?? null;
        if (!$name) respond(['success' => false, 'error' => 'Не указано имя'], 400);
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['success' => false, 'error' => 'Неверный формат email'], 400);
        if (!$password || mb_strlen($password) < 8) respond(['success' => false, 'error' => 'Пароль обязателен (минимум 8 символов)'], 400);
        if (!in_array($role, ['admin', 'user', 'viewer'])) $role = 'user';
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $permJson = ($permissions && is_array($permissions) && count($permissions) > 0) ? json_encode($permissions, JSON_UNESCAPED_UNICODE) : null;
        $id = uuid();
        try {
            $pdo->prepare("INSERT INTO users (id, name, email, password, role, display_role, legal_entities, permissions, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                ->execute([$id, $name, $email ?: null, $hash, $role, $displayRole, is_array($legalEntities) ? json_encode($legalEntities, JSON_UNESCAPED_UNICODE) : $legalEntities, $permJson]);
        } catch (PDOException $e) {
            respond(['success' => false, 'error' => 'Пользователь уже существует или ошибка базы данных'], 400);
        }
        respond(['success' => true, 'user' => ['id' => $id, 'name' => $name, 'email' => $email ?: null, 'role' => $role, 'display_role' => $displayRole]]);
    }
    if ($fn === 'update_user') {
        $caller = getSessionUser($pdo);
        if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
        $callerName = $caller['name'];
        $userId = $body['user_id'] ?? '';
        if (!$userId) respond(['success' => false, 'error' => 'Не указан ID пользователя'], 400);
        $sets = []; $params = [];
        if (isset($body['name']) && trim($body['name'])) { $sets[] = "name=?"; $params[] = trim($body['name']); }
        if (array_key_exists('email', $body)) {
            $emailVal = trim($body['email']);
            if ($emailVal && !filter_var($emailVal, FILTER_VALIDATE_EMAIL)) respond(['success' => false, 'error' => 'Неверный формат email'], 400);
            $sets[] = "email=?"; $params[] = $emailVal ?: null;
        }
        if (isset($body['role']) && in_array($body['role'], ['admin', 'user', 'viewer'])) { $sets[] = "role=?"; $params[] = $body['role']; }
        if (array_key_exists('display_role', $body)) { $sets[] = "display_role=?"; $params[] = $body['display_role']; }
        if (array_key_exists('legal_entities', $body)) { $sets[] = "legal_entities=?"; $params[] = is_array($body['legal_entities']) ? json_encode($body['legal_entities'], JSON_UNESCAPED_UNICODE) : $body['legal_entities']; }
        if (array_key_exists('permissions', $body)) {
            $pv = $body['permissions'];
            $sets[] = "permissions=?";
            $params[] = ($pv && is_array($pv) && count($pv) > 0) ? json_encode($pv, JSON_UNESCAPED_UNICODE) : null;
        }
        $passwordChanged = false;
        if (isset($body['password']) && $body['password'] !== '') {
            if (mb_strlen($body['password']) < 8) respond(['success' => false, 'error' => 'Пароль слишком короткий (минимум 8 символов)'], 400);
            $sets[] = "password=?"; $params[] = password_hash($body['password'], PASSWORD_BCRYPT);
            $passwordChanged = true;
        }
        if (empty($sets)) respond(['success' => false, 'error' => 'Нечего обновлять'], 400);
        $params[] = $userId;
        $pdo->prepare("UPDATE users SET " . implode(',', $sets) . " WHERE id=?")->execute($params);
        if ($passwordChanged) {
            $s = $pdo->prepare("SELECT name FROM users WHERE id=?"); $s->execute([$userId]); $target = $s->fetch();
            if ($target) $pdo->prepare("DELETE FROM user_sessions WHERE user_name=?")->execute([$target['name']]);
        }
        respond(['success' => true]);
    }
    if ($fn === 'delete_user') {
        $caller = getSessionUser($pdo);
        if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
        $callerName = $caller['name'];
        $userId = $body['user_id'] ?? '';
        if (!$userId) respond(['success' => false, 'error' => 'Не указан ID пользователя'], 400);
        // Не позволять удалить себя
        $s2 = $pdo->prepare("SELECT name FROM users WHERE id=?"); $s2->execute([$userId]); $target = $s2->fetch();
        if ($target && $target['name'] === $callerName) respond(['success' => false, 'error' => 'Нельзя удалить самого себя'], 400);
        // Удаляем активные сессии пользователя, чтобы он не мог продолжать работу
        if ($target) {
            $pdo->prepare("DELETE FROM user_sessions WHERE user_name=?")->execute([$target['name']]);
            $pdo->prepare("DELETE FROM user_presence WHERE user_name=?")->execute([$target['name']]);
        }
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$userId]);
        respond(['success' => true]);
    }

    if ($fn === 'mark_notifications_read') {
        $ids = $body['ids'] ?? [];
        $user = $authUserName;
        if (!$user || empty($ids)) respond(['success' => false, 'error' => 'Не все параметры указаны']);
        $ids = array_slice($ids, 0, 100); // Лимит на количество ID
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE notifications SET read_by = JSON_ARRAY_APPEND(COALESCE(read_by, '[]'), '$', ?) WHERE id IN ($ph) AND (target_user IS NULL OR target_user = '' OR target_user = ? OR type = 'broadcast') AND NOT JSON_CONTAINS(COALESCE(read_by, '[]'), JSON_QUOTE(?))")->execute(array_merge([$user], $ids, [$user, $user]));
        respond(['success' => true]);
    }
    if ($fn === 'heartbeat') {
        $userName = $authUserName;
        $page = $body['page'] ?? '';
        $editingOrderId = $body['editing_order_id'] ?? null;
        if ($userName) {
            $s = $pdo->prepare("INSERT INTO user_presence (user_name, page, last_seen, editing_order_id) VALUES (?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE page=VALUES(page), last_seen=NOW(), editing_order_id=VALUES(editing_order_id)");
            $s->execute([$userName, substr($page, 0, 100), $editingOrderId]);
        }
        respond(['success' => true]);
    }
    if ($fn === 'check_order_lock') {
        $orderId = $body['order_id'] ?? '';
        $userName = $authUserName;
        if (!$orderId) respond(['locked' => false]);
        $s = $pdo->prepare("SELECT user_name FROM user_presence WHERE editing_order_id = ? AND user_name != ? AND last_seen > NOW() - INTERVAL 2 MINUTE LIMIT 1");
        $s->execute([$orderId, $userName]);
        $row = $s->fetch();
        respond($row ? ['locked' => true, 'locked_by' => $row['user_name']] : ['locked' => false]);
    }
    if ($fn === 'unlock_order') {
        $userName = $authUserName;
        if ($userName) {
            $pdo->prepare("UPDATE user_presence SET editing_order_id = NULL WHERE user_name = ?")->execute([$userName]);
        }
        respond(['success' => true]);
    }
    if ($fn === 'get_online_users') {
        $s = $pdo->query("SELECT user_name, page, last_seen FROM user_presence WHERE last_seen > NOW() - INTERVAL 2 MINUTE ORDER BY last_seen DESC");
        respond($s->fetchAll());
    }
    if ($fn === 'send_broadcast') {
        $sessionUser = getSessionUser($pdo);
        if (!$sessionUser || $sessionUser['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
        $userName = $sessionUser['name'];
        $title = $body['title'] ?? 'Важное сообщение';
        $message = $body['message'] ?? '';
        $sendTelegram = $body['send_telegram'] ?? true;
        if (!$message) respond(['success' => false, 'error' => 'Не все параметры указаны'], 400);
        $pdo->prepare("INSERT INTO notifications (type, title, message, created_by, read_by, created_at) VALUES ('broadcast', ?, ?, ?, '[]', NOW())")
            ->execute([mb_substr($title, 0, 255), mb_substr($message, 0, 2000), $userName]);
        $id = $pdo->lastInsertId();

        // Отправка в Telegram всем привязанным пользователям
        $tgSent = 0;
        if ($sendTelegram) {
            $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
            if ($botToken) {
                $s = $pdo->query("SELECT telegram_chat_id FROM users WHERE telegram_chat_id IS NOT NULL AND telegram_chat_id != ''");
                $chatIds = $s->fetchAll(PDO::FETCH_COLUMN);
                $tgTitle = mb_substr($title, 0, 255);
                $tgMessage = mb_substr($message, 0, 2000);
                $tgText = "📢 <b>{$tgTitle}</b>\n\n{$tgMessage}\n\n— {$userName}";
                $tgSent = sendTelegramBulk($botToken, $chatIds, $tgText);
            }
        }

        respond(['success' => true, 'id' => $id, 'telegram_sent' => $tgSent]);
    }
    if ($fn === 'delete_notification_for_user') {
        $id = $body['id'] ?? null;
        $userName = $authUserName;
        if (!$id || !$userName) respond(['success' => false, 'error' => 'Не все параметры указаны'], 400);
        $pdo->prepare("UPDATE notifications SET deleted_by = JSON_ARRAY_APPEND(COALESCE(deleted_by, '[]'), '$', ?) WHERE id = ? AND (target_user IS NULL OR target_user = '' OR target_user = ? OR type = 'broadcast') AND NOT JSON_CONTAINS(COALESCE(deleted_by, '[]'), JSON_QUOTE(?))")->execute([$userName, $id, $userName, $userName]);
        respond(['success' => true]);
    }
    if ($fn === 'delete_all_notifications_for_user') {
        $userName = $authUserName;
        if (!$userName) respond(['success' => false, 'error' => 'Не все параметры указаны'], 400);
        $pdo->prepare("UPDATE notifications SET deleted_by = JSON_ARRAY_APPEND(COALESCE(deleted_by, '[]'), '$', ?) WHERE (target_user = ? OR type = 'broadcast') AND NOT JSON_CONTAINS(COALESCE(deleted_by, '[]'), JSON_QUOTE(?))")->execute([$userName, $userName, $userName]);
        respond(['success' => true]);
    }
    if ($fn === 'get_active_broadcasts') {
        $userName = $authUserName;
        if (!$userName) respond([]);
        // Не показывать уведомления, отправленные до регистрации пользователя
        $su = $pdo->prepare("SELECT created_at FROM users WHERE name=?"); $su->execute([$userName]); $uRow = $su->fetch();
        $userCreated = $uRow['created_at'] ?? null;
        $sql = "SELECT id, title, message, created_by, created_at FROM notifications WHERE type='broadcast' AND created_at > NOW() - INTERVAL 24 HOUR AND NOT JSON_CONTAINS(COALESCE(read_by, '[]'), JSON_QUOTE(?)) AND NOT JSON_CONTAINS(COALESCE(deleted_by, '[]'), JSON_QUOTE(?))";
        $params = [$userName, $userName];
        if ($userCreated) {
            $sql .= " AND created_at > ?";
            $params[] = $userCreated;
        }
        $sql .= " ORDER BY created_at DESC LIMIT 5";
        $s = $pdo->prepare($sql);
        $s->execute($params);
        respond($s->fetchAll());
    }
    if ($fn === 'delete_broadcast') {
        $sessionUser = getSessionUser($pdo);
        if (!$sessionUser || $sessionUser['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
        $id = $body['id'] ?? null;
        if (!$id) respond(['success' => false, 'error' => 'Не указан ID'], 400);
        $pdo->prepare("DELETE FROM notifications WHERE id = ? AND type = 'broadcast'")->execute([$id]);
        respond(['success' => true]);
    }
    if ($fn === 'batch_update_received_qty') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация по сессии'], 401);
        $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['plan-fact'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
            respond(['error' => 'Недостаточно прав'], 403);
        }
        $items = $body['items'] ?? [];
        if (!is_array($items) || empty($items)) respond(['error' => 'Не указаны позиции'], 400);
        if (count($items) > 500) respond(['error' => 'Слишком много записей (макс. 500)'], 400);
        // Собираем ID позиций и проверяем доступ к юрлицам заказов
        $itemIds = array_filter(array_map(fn($i) => $i['id'] ?? null, $items));
        if (!empty($itemIds) && $caller['role'] !== 'admin') {
            $ph = implode(',', array_fill(0, count($itemIds), '?'));
            $chk = $pdo->prepare("SELECT DISTINCT o.legal_entity FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.id IN ($ph)");
            $chk->execute(array_values($itemIds));
            $entities = $chk->fetchAll(PDO::FETCH_COLUMN);
            foreach ($entities as $le) {
                if (!checkLegalEntityAccess($caller, $le)) respond(['error' => 'Нет доступа к юр. лицу заказа'], 403);
            }
        }
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE `order_items` SET `received_qty` = ? WHERE `id` = ?");
            foreach ($items as $item) {
                $id = $item['id'] ?? null;
                if (!$id) continue;
                $qty = array_key_exists('received_qty', $item) ? $item['received_qty'] : null;
                $stmt->execute([$qty, $id]);
            }
            $pdo->commit();
            respond(['success' => true, 'count' => count($items)]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("batch_update_received_qty error: " . $e->getMessage());
            respond(['error' => 'Ошибка сохранения данных'], 500);
        }
    }
    if ($fn === 'replace_analysis_data') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация по сессии'], 401);
        $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['analysis'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
            respond(['error' => 'Недостаточно прав'], 403);
        }
        $legalEntity = $body['legal_entity'] ?? '';
        $items = $body['items'] ?? [];
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        if (!is_array($items)) respond(['error' => 'Позиции должны быть массивом'], 400);
        if (empty($items)) respond(['error' => 'Список позиций пуст'], 400);
        if (count($items) > 5000) respond(['error' => 'Слишком много записей (макс. 5000)'], 400);
        $allowed = ['id','legal_entity','sku','stock','consumption','period_days','updated_by','updated_at'];
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM `analysis_data` WHERE `legal_entity`=?")->execute([$legalEntity]);
            foreach ($items as $item) {
                $item = array_intersect_key($item, array_flip($allowed));
                if (empty($item)) continue;
                $cols = array_keys($item);
                $ph = implode(',', array_fill(0, count($cols), '?'));
                $cn = implode(',', array_map(fn($c) => "`$c`", $cols));
                $pdo->prepare("INSERT INTO `analysis_data` ($cn) VALUES ($ph)")->execute(array_values($item));
            }
            $pdo->commit();
            notifyTelegramDataUpdate($pdo, 'analysis', $caller['name'], $legalEntity, count($items));
            respond(['success' => true, 'count' => count($items)]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("replace_analysis_data error: " . $e->getMessage());
            respond(['error' => 'Ошибка сохранения данных'], 500);
        }
    }

    if ($fn === 'replace_restaurant_sales') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация по сессии'], 401);
        $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['analysis'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
            respond(['error' => 'Недостаточно прав'], 403);
        }
        $items = $body['items'] ?? [];
        if (!is_array($items)) respond(['error' => 'Позиции должны быть массивом'], 400);
        if (empty($items)) respond(['error' => 'Список позиций пуст'], 400);
        if (count($items) > 500000) respond(['error' => 'Слишком много записей (макс. 500 000)'], 400);
        try {
            $pdo->beginTransaction();
            // Upsert: обновляем если уже есть запись за эту дату и группу
            $stmt = $pdo->prepare("INSERT INTO `restaurant_sales` (`sale_date`, `analog_group`, `quantity`, `restaurant_count`)
                VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `quantity`=VALUES(`quantity`), `restaurant_count`=VALUES(`restaurant_count`)");
            $inserted = 0;
            foreach ($items as $item) {
                $date = $item['sale_date'] ?? null;
                $group = $item['analog_group'] ?? null;
                $qty = $item['quantity'] ?? 0;
                $rc = $item['restaurant_count'] ?? 0;
                if (!$date || !$group) continue;
                $stmt->execute([$date, $group, $qty, $rc]);
                $inserted++;
            }
            $pdo->commit();
            respond(['success' => true, 'count' => $inserted]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("replace_restaurant_sales error: " . $e->getMessage());
            respond(['error' => 'Ошибка сохранения данных'], 500);
        }
    }

    if ($fn === 'replace_stock_malling') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация по сессии'], 401);
        $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['shelf-life'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
            respond(['error' => 'Недостаточно прав'], 403);
        }
        $items = $body['items'] ?? [];
        if (!is_array($items)) respond(['error' => 'Позиции должны быть массивом'], 400);
        if (empty($items)) respond(['error' => 'Список позиций пуст'], 400);
        if (count($items) > 5000) respond(['error' => 'Слишком много записей (макс. 5000)'], 400);
        $allowed = ['customer','warehouse','product_name','production_date','expiry_date','block_reason','expiry_status','quantity','uploaded_at','uploaded_by'];
        try {
            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM `stock_malling`");
            foreach ($items as $item) {
                $item = array_intersect_key($item, array_flip($allowed));
                if (empty($item)) continue;
                $cols = array_keys($item);
                $ph = implode(',', array_fill(0, count($cols), '?'));
                $cn = implode(',', array_map(fn($c) => "`$c`", $cols));
                $pdo->prepare("INSERT INTO `stock_malling` ($cn) VALUES ($ph)")->execute(array_values($item));
            }
            $pdo->commit();
            notifyTelegramDataUpdate($pdo, 'shelf_life', $caller['name'], '', count($items));
            respond(['success' => true, 'count' => count($items)]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("replace_stock_malling error: " . $e->getMessage());
            respond(['error' => 'Ошибка сохранения данных'], 500);
        }
    }

    if ($fn === 'replace_order_items') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация по сессии'], 401);
        $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['order'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
            respond(['error' => 'Недостаточно прав'], 403);
        }
        $orderId = $body['order_id'] ?? '';
        $items = $body['items'] ?? [];
        if (!$orderId) respond(['error' => 'Не указан ID заказа'], 400);
        // Проверяем доступ к юрлицу заказа
        $orderCheck = $pdo->prepare("SELECT legal_entity FROM orders WHERE id=?");
        $orderCheck->execute([$orderId]);
        $orderRow = $orderCheck->fetch();
        if (!$orderRow) respond(['error' => 'Заказ не найден'], 404);
        if (!checkLegalEntityAccess($caller, $orderRow['legal_entity'])) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        if (!is_array($items)) respond(['error' => 'Позиции должны быть массивом'], 400);
        if (count($items) > 5000) respond(['error' => 'Слишком много записей (макс. 5000)'], 400);
        try {
            $pdo->beginTransaction();
            // Блокируем заказ от параллельных изменений
            $lockStmt = $pdo->prepare("SELECT id FROM `orders` WHERE id=? FOR UPDATE");
            $lockStmt->execute([$orderId]);
            if (!$lockStmt->fetch()) { $pdo->rollBack(); respond(['error' => 'Заказ не найден'], 404); }
            $pdo->prepare("DELETE FROM `order_items` WHERE `order_id`=?")->execute([$orderId]);
            if (count($items) > 0) {
                $oiWhitelist = ['id','order_id','sku','name','qty_boxes','qty_per_box','boxes_per_pallet','multiplicity','consumption_period','stock','transit','final_order','manual_override','unit_of_measure','received_qty','analog_group','category'];
                foreach ($items as $item) {
                    if (!isset($item['order_id'])) $item['order_id'] = $orderId;
                    if (!isset($item['id'])) $item['id'] = uuid();
                    // Фильтруем по writeWhitelist
                    if (!empty($oiWhitelist)) $item = array_intersect_key($item, array_flip($oiWhitelist));
                    foreach (array_keys($item) as $col) {
                        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
                            $pdo->rollBack();
                            respond(['error' => 'Недопустимое имя колонки: '.$col], 400);
                        }
                    }
                    $cols = array_keys($item);
                    $ph = implode(',', array_fill(0, count($cols), '?'));
                    $cn = implode(',', array_map(fn($c) => "`$c`", $cols));
                    $pdo->prepare("INSERT INTO `order_items` ($cn) VALUES ($ph)")->execute(array_values($item));
                }
            }
            $pdo->commit();
            respond(['success' => true, 'count' => count($items)]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("replace_order_items error: " . $e->getMessage());
            respond(['error' => 'Ошибка сохранения данных'], 500);
        }
    }

    if ($fn === 'delete_order') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация по сессии'], 401);
        $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['order'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['full']) {
            respond(['error' => 'Недостаточно прав'], 403);
        }
        $orderId = $body['order_id'] ?? '';
        if (!$orderId) respond(['error' => 'Не указан ID заказа'], 400);
        // Проверяем, что пользователь имеет доступ к юрлицу заказа
        $orderCheck = $pdo->prepare("SELECT legal_entity FROM orders WHERE id=?");
        $orderCheck->execute([$orderId]);
        $orderRow = $orderCheck->fetch();
        if (!$orderRow) respond(['error' => 'Заказ не найден'], 404);
        if (!checkLegalEntityAccess($caller, $orderRow['legal_entity'])) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM `order_items` WHERE `order_id`=?")->execute([$orderId]);
            $pdo->prepare("DELETE FROM `orders` WHERE `id`=?")->execute([$orderId]);
            $pdo->commit();
            respond(['success' => true]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("delete_order error: " . $e->getMessage());
            respond(['error' => 'Ошибка сохранения данных'], 500);
        }
    }

    if ($fn === 'replace_item_order') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация по сессии'], 401);
        $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['order'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
            respond(['error' => 'Недостаточно прав'], 403);
        }
        $supplier = $body['supplier'] ?? '';
        $legalEntity = $body['legal_entity'] ?? '';
        $items = $body['items'] ?? [];
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        if (!is_array($items)) respond(['error' => 'Позиции должны быть массивом'], 400);
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM `item_order` WHERE `supplier`=? AND `legal_entity`=?")->execute([$supplier, $legalEntity]);
            foreach ($items as $item) {
                $pdo->prepare("INSERT INTO `item_order` (`supplier`,`legal_entity`,`item_id`,`position`) VALUES (?,?,?,?)")
                    ->execute([$supplier, $legalEntity, $item['item_id'], $item['position']]);
            }
            $pdo->commit();
            respond(['success' => true, 'count' => count($items)]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("replace_item_order error: " . $e->getMessage());
            respond(['error' => 'Ошибка сохранения данных'], 500);
        }
    }

    // ─── RPC: calculate_adu — расчёт среднего суточного расхода ───
    if ($fn === 'calculate_adu') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация по сессии'], 401);
        $legalEntity = $body['legal_entity'] ?? '';
        if (!$legalEntity) respond(['error' => 'legal_entity обязателен'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $supplier = $body['supplier'] ?? null;
        $lookbackDays = intval($body['lookback_days'] ?? 90);
        if ($lookbackDays < 7) $lookbackDays = 90;

        // Загружаем order_items за последние N дней
        $sql = "SELECT oi.sku, oi.consumption_period, oi.qty_per_box, o.period_days, o.unit, o.delivery_date
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                WHERE o.legal_entity = ?
                  AND o.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  AND oi.sku IS NOT NULL AND oi.sku != ''
                  AND oi.consumption_period > 0";
        $params = [$legalEntity, $lookbackDays];
        if ($supplier) {
            $sql .= " AND o.supplier = ?";
            $params[] = $supplier;
        }
        $s = $pdo->prepare($sql);
        $s->execute($params);
        $rows = $s->fetchAll();

        // Группируем по SKU → массив суточных расходов (в штуках/день)
        $skuData = [];
        foreach ($rows as $r) {
            $sku = $r['sku'];
            $period = intval($r['period_days']) ?: 30;
            $consumption = floatval($r['consumption_period']);
            $qpb = intval($r['qty_per_box']) ?: 1;
            $unit = $r['unit'] ?? 'pieces';
            // Конвертируем в штуки
            $consumptionPcs = ($unit === 'boxes') ? $consumption * $qpb : $consumption;
            $daily = $period > 0 ? $consumptionPcs / $period : 0;
            if ($daily > 0) {
                if (!isset($skuData[$sku])) $skuData[$sku] = ['dailies' => [], 'lastDate' => null];
                $skuData[$sku]['dailies'][] = $daily;
                $dd = $r['delivery_date'];
                if ($dd && (!$skuData[$sku]['lastDate'] || $dd > $skuData[$sku]['lastDate'])) {
                    $skuData[$sku]['lastDate'] = $dd;
                }
            }
        }

        // Считаем ADU и CV, upsert в product_adu
        $upsertSql = "INSERT INTO product_adu (sku, legal_entity, adu, cv, sample_count, last_order_date)
                      VALUES (?, ?, ?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE adu=VALUES(adu), cv=VALUES(cv), sample_count=VALUES(sample_count), last_order_date=VALUES(last_order_date)";
        $upsertStmt = $pdo->prepare($upsertSql);
        $count = 0;
        $pdo->beginTransaction();
        try {
            foreach ($skuData as $sku => $info) {
                $dailies = $info['dailies'];
                $n = count($dailies);
                $mean = array_sum($dailies) / $n;
                $cv = 0;
                if ($n > 1 && $mean > 0) {
                    $variance = 0;
                    foreach ($dailies as $d) $variance += ($d - $mean) ** 2;
                    $stddev = sqrt($variance / ($n - 1));
                    $cv = round($stddev / $mean, 3);
                }
                $upsertStmt->execute([$sku, $legalEntity, round($mean, 2), $cv, $n, $info['lastDate']]);
                $count++;
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('calculate_adu error: ' . $e->getMessage());
            respond(['error' => 'Ошибка расчёта ADU'], 500);
        }
        respond(['success' => true, 'updated' => $count]);
    }

    // ─── Админские RPC (только admin) ───
    if ($fn === 'get_admin_stats') {
        $caller = getSessionUser($pdo);
        if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
        $period = $body['period'] ?? 'all';
        $dateFilter = '';
        if ($period === 'week') $dateFilter = " AND created_at > NOW() - INTERVAL 7 DAY";
        elseif ($period === 'month') $dateFilter = " AND created_at > NOW() - INTERVAL 30 DAY";

        $stats = [];
        // Заказы
        $s = $pdo->query("SELECT COUNT(*) as cnt FROM orders WHERE 1=1" . $dateFilter); $stats['orders_total'] = (int)$s->fetch()['cnt'];
        $s = $pdo->query("SELECT COUNT(*) as cnt FROM orders WHERE DATE(created_at) = CURDATE()"); $stats['orders_today'] = (int)$s->fetch()['cnt'];
        // Планы
        $s = $pdo->query("SELECT COUNT(*) as cnt FROM plans WHERE 1=1" . $dateFilter); $stats['plans_total'] = (int)$s->fetch()['cnt'];
        // Активные сессии
        $s = $pdo->query("SELECT COUNT(*) as cnt FROM user_sessions WHERE expires_at > NOW()"); $stats['active_sessions'] = (int)$s->fetch()['cnt'];
        // Товары, поставщики, пользователи
        $s = $pdo->query("SELECT COUNT(*) as cnt FROM products"); $stats['products_count'] = (int)$s->fetch()['cnt'];
        $s = $pdo->query("SELECT COUNT(*) as cnt FROM suppliers"); $stats['suppliers_count'] = (int)$s->fetch()['cnt'];
        $s = $pdo->query("SELECT COUNT(*) as cnt FROM users"); $stats['users_count'] = (int)$s->fetch()['cnt'];
        // Заказы по юрлицам
        $s = $pdo->query("SELECT legal_entity, COUNT(*) as cnt FROM orders WHERE 1=1" . $dateFilter . " GROUP BY legal_entity ORDER BY cnt DESC");
        $stats['orders_by_entity'] = $s->fetchAll();
        // Топ пользователей
        $s = $pdo->query("SELECT created_by as user_name, COUNT(*) as cnt FROM orders WHERE 1=1" . $dateFilter . " GROUP BY created_by ORDER BY cnt DESC LIMIT 10");
        $stats['top_users'] = $s->fetchAll();

        respond($stats);
    }

    if ($fn === 'get_sessions') {
        $caller = getSessionUser($pdo);
        if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
        $s = $pdo->query("SELECT id, user_name, CONCAT(LEFT(token, 8), '…') AS token_prefix, created_at, expires_at, ip_address, user_agent FROM user_sessions WHERE expires_at > NOW() ORDER BY created_at DESC");
        respond($s->fetchAll());
    }

    if ($fn === 'terminate_session') {
        $caller = getSessionUser($pdo);
        if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
        $sessionId = $body['session_id'] ?? '';
        if (!$sessionId) respond(['success' => false, 'error' => 'Не указан ID сессии'], 400);
        $pdo->prepare("DELETE FROM user_sessions WHERE id = ?")->execute([$sessionId]);
        respond(['success' => true]);
    }

    if ($fn === 'clear_error_logs') {
        $caller = getSessionUser($pdo);
        if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
        $olderThan = $body['older_than_days'] ?? null;
        try {
            if ($olderThan && intval($olderThan) > 0) {
                $pdo->prepare("DELETE FROM error_logs WHERE created_at < NOW() - INTERVAL ? DAY")->execute([intval($olderThan)]);
            } else {
                $pdo->exec("TRUNCATE TABLE error_logs");
            }
            respond(['success' => true]);
        } catch (PDOException $e) {
            respond(['success' => false, 'error' => 'Ошибка очистки логов'], 500);
        }
    }

    if ($fn === 'replace_restaurant_schedule') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация по сессии'], 401);
        $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['delivery-schedule'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
            respond(['error' => 'Недостаточно прав'], 403);
        }
        $restaurantId = $body['restaurant_id'] ?? null;
        $items = $body['items'] ?? [];
        if (!$restaurantId) respond(['error' => 'Не указан ID ресторана'], 400);
        if (!is_array($items)) respond(['error' => 'Позиции должны быть массивом'], 400);
        if (count($items) > 500) respond(['error' => 'Слишком много записей (макс. 500)'], 400);
        // Проверка доступа к юрлицу ресторана
        if ($caller['role'] !== 'admin') {
            $rChk = $pdo->prepare("SELECT legal_entity FROM restaurants WHERE id=?"); $rChk->execute([$restaurantId]); $rRow = $rChk->fetch();
            if (!$rRow) respond(['error' => 'Ресторан не найден'], 404);
            if (!checkLegalEntityAccess($caller, $rRow['legal_entity'])) respond(['error' => 'Нет доступа к юр. лицу ресторана'], 403);
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM `delivery_schedule` WHERE `restaurant_id`=?")->execute([$restaurantId]);
            foreach ($items as $item) {
                $day = intval($item['day_of_week'] ?? 0);
                $time = $item['delivery_time'] ?? null;
                $notes = $item['notes'] ?? null;
                if ($day < 1 || $day > 7) continue;
                $pdo->prepare("INSERT INTO `delivery_schedule` (`restaurant_id`, `day_of_week`, `delivery_time`, `notes`) VALUES (?, ?, ?, ?)")
                    ->execute([$restaurantId, $day, $time, $notes]);
            }
            $pdo->commit();
            respond(['success' => true]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("replace_restaurant_schedule error: " . $e->getMessage());
            respond(['error' => 'Ошибка сохранения данных'], 500);
        }
    }

    // ═══ PRICING: импорт цен, согласование ПСЦ ═══
    if ($fn === 'import_prices') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $le = $body['legal_entity'] ?? '';
        $supplier = $body['supplier'] ?? '';
        $prices = $body['prices'] ?? []; // [{sku, price, unit_type}]
        $agreementId = $body['agreement_id'] ?? null;
        if (!$le || !$supplier || empty($prices)) respond(['error' => 'Не указаны обязательные поля'], 400);
        if (!checkLegalEntityAccess($caller, $le)) respond(['error' => 'Нет доступа к юр. лицу'], 403);
        $perms = resolvePermissions($caller['role'] ?? 'user', $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['pricing'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);
        // Проверка что agreement_id принадлежит тому же юрлицу
        if ($agreementId) {
            $agChk = $pdo->prepare("SELECT legal_entity FROM price_agreements WHERE id=?"); $agChk->execute([$agreementId]);
            $agLE = $agChk->fetchColumn();
            if (!$agLE || $agLE !== $le) respond(['error' => 'Протокол не принадлежит указанному юр. лицу'], 400);
        }
        $imported = 0;
        try {
            $pdo->beginTransaction();
            $currency = in_array($body['currency'] ?? '', ['BYN', 'RUB']) ? $body['currency'] : 'BYN';
            $stmt = $pdo->prepare("INSERT INTO product_prices (sku, supplier, legal_entity, price, vat_rate, unit_type, currency, agreement_id, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE price=VALUES(price), vat_rate=VALUES(vat_rate), unit_type=VALUES(unit_type), currency=VALUES(currency), agreement_id=VALUES(agreement_id), updated_by=VALUES(updated_by), updated_at=NOW()");
            $oldStmt = $pdo->prepare("SELECT price, currency FROM product_prices WHERE sku=? AND supplier=? AND legal_entity=?");
            $histStmt = $pdo->prepare("INSERT INTO price_history (sku, supplier, legal_entity, old_price, new_price, old_currency, new_currency, agreement_id, changed_by) VALUES (?,?,?,?,?,?,?,?,?)");
            foreach ($prices as $p) {
                $sku = trim($p['sku'] ?? '');
                $price = floatval($p['price'] ?? 0);
                $ut = $p['unit_type'] ?? 'piece';
                $unitType = in_array($ut, ['piece', 'box', 'thousand', 'kg', 'liter']) ? $ut : 'piece';
                $cur = in_array($p['currency'] ?? '', ['BYN', 'RUB']) ? $p['currency'] : $currency;
                $vat = floatval($p['vat_rate'] ?? 20);
                if (!$sku || $price < 0) continue;
                // Сохранить старую цену для истории
                $oldStmt->execute([$sku, $supplier, $le]);
                $old = $oldStmt->fetch();
                $stmt->execute([$sku, $supplier, $le, $price, $vat, $unitType, $cur, $agreementId, $caller['name']]);
                // Записать в историю если цена изменилась или новая
                if (!$old || floatval($old['price']) != $price || ($old['currency'] ?? '') !== $cur) {
                    $histStmt->execute([$sku, $supplier, $le, $old ? $old['price'] : null, $price, $old ? $old['currency'] : null, $cur, $agreementId, $caller['name']]);
                }
                $imported++;
            }
            $pdo->commit();
            respond(['success' => true, 'imported' => $imported]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("import_prices error: " . $e->getMessage());
            respond(['error' => 'Ошибка импорта'], 500);
        }
    }

    if ($fn === 'approve_agreement') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'Не указан ID протокола'], 400);
        $perms = resolvePermissions($caller['role'] ?? 'user', $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['pricing'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['full']) respond(['error' => 'Только полный доступ может согласовывать ПСЦ'], 403);
        $pdo->beginTransaction();
        try {
            $s = $pdo->prepare("SELECT * FROM price_agreements WHERE id=? FOR UPDATE"); $s->execute([$id]); $ag = $s->fetch();
            if (!$ag) { $pdo->rollBack(); respond(['error' => 'Протокол не найден'], 404); }
            if (!checkLegalEntityAccess($caller, $ag['legal_entity'])) { $pdo->rollBack(); respond(['error' => 'Нет доступа к юр. лицу'], 403); }
            if ($ag['status'] === 'active') { $pdo->rollBack(); respond(['error' => 'Протокол уже согласован'], 400); }
            $docType = $ag['doc_type'] ?? 'psc';
            // ПСЦ архивирует предыдущие ПСЦ этого поставщика; спецификации — не архивируют ничего
            if ($docType === 'psc') {
                $pdo->prepare("UPDATE price_agreements SET status='archived' WHERE supplier=? AND legal_entity=? AND status='active' AND doc_type='psc'")->execute([$ag['supplier'], $ag['legal_entity']]);
            }
            $pdo->prepare("UPDATE price_agreements SET status='active', approved_by=?, approved_at=NOW() WHERE id=?")->execute([$caller['name'], $id]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('approve_agreement error: ' . $e->getMessage());
            respond(['error' => 'Ошибка согласования'], 500);
        }
        respond(['success' => true]);
    }

    if ($fn === 'archive_agreement') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'Не указан ID протокола'], 400);
        $perms = resolvePermissions($caller['role'] ?? 'user', $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['pricing'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);
        $s = $pdo->prepare("SELECT * FROM price_agreements WHERE id=?"); $s->execute([$id]); $ag = $s->fetch();
        if (!$ag) respond(['error' => 'Протокол не найден'], 404);
        if (!checkLegalEntityAccess($caller, $ag['legal_entity'])) respond(['error' => 'Нет доступа к юр. лицу'], 403);
        if ($ag['status'] === 'archived') respond(['error' => 'Протокол уже в архиве'], 400);
        $pdo->prepare("UPDATE price_agreements SET status='archived' WHERE id=?")->execute([$id]);
        respond(['success' => true]);
    }

    if ($fn === 'restore_agreement') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'Не указан ID протокола'], 400);
        $perms = resolvePermissions($caller['role'] ?? 'user', $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['pricing'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);
        $s = $pdo->prepare("SELECT * FROM price_agreements WHERE id=?"); $s->execute([$id]); $ag = $s->fetch();
        if (!$ag) respond(['error' => 'Протокол не найден'], 404);
        if (!checkLegalEntityAccess($caller, $ag['legal_entity'])) respond(['error' => 'Нет доступа к юр. лицу'], 403);
        if ($ag['status'] !== 'archived') respond(['error' => 'Протокол не в архиве'], 400);
        $pdo->prepare("UPDATE price_agreements SET status='active' WHERE id=?")->execute([$id]);
        respond(['success' => true]);
    }

    if ($fn === 'get_current_prices') {
        $caller = getSessionUser($pdo);
        if (!$caller && !checkApiKey($pdo)) respond(['error' => 'Требуется авторизация'], 401);
        $le = $body['legal_entity'] ?? ($_GET['legal_entity'] ?? '');
        if (strpos($le, 'eq.') === 0) $le = substr($le, 3);
        if (!$le) respond(['error' => 'Не указано юр. лицо'], 400);
        if ($caller && !checkLegalEntityAccess($caller, $le)) respond(['error' => 'Нет доступа к юр. лицу'], 403);
        $supplier = $body['supplier'] ?? ($_GET['supplier'] ?? '');
        $sql = "SELECT pp.id, pp.sku, pp.price, pp.vat_rate, pp.unit_type, pp.currency, pp.supplier, pp.agreement_id, pp.updated_at FROM product_prices pp WHERE pp.legal_entity=?";
        $params = [$le];
        if ($supplier) { $sql .= " AND pp.supplier=?"; $params[] = $supplier; }
        $s = $pdo->prepare($sql); $s->execute($params);
        $rows = $s->fetchAll();
        // Получаем курс RUB→BYN
        $rateStmt = $pdo->prepare("SELECT value FROM settings WHERE `key`='rub_to_byn_rate'"); $rateStmt->execute();
        $rate = floatval($rateStmt->fetchColumn() ?: '0.0375');
        respond(['prices' => $rows, 'rub_to_byn_rate' => $rate]);
    }

    if ($fn === 'update_exchange_rate') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $perms = resolvePermissions($caller['role'] ?? 'user', $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['pricing'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);
        $rate = floatval($body['rate'] ?? 0);
        if ($rate <= 0 || $rate > 1) respond(['error' => 'Некорректный курс (ожидается число от 0 до 1)'], 400);
        $pdo->prepare("INSERT INTO settings (`key`, value) VALUES ('rub_to_byn_rate', ?) ON DUPLICATE KEY UPDATE value=?")->execute([(string)$rate, (string)$rate]);
        respond(['success' => true, 'rate' => $rate]);
    }

    if ($fn === 'delete_agreement') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'Не указан ID'], 400);
        $perms = resolvePermissions($caller['role'] ?? 'user', $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['pricing'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['full']) respond(['error' => 'Недостаточно прав'], 403);
        $s = $pdo->prepare("SELECT * FROM price_agreements WHERE id=?"); $s->execute([$id]); $ag = $s->fetch();
        if (!$ag) respond(['error' => 'Протокол не найден'], 404);
        if (!checkLegalEntityAccess($caller, $ag['legal_entity'])) respond(['error' => 'Нет доступа к юр. лицу'], 403);
        // Удалить файл с диска
        if ($ag['file_path']) {
            $fpBase = basename($ag['file_path']);
            if ($fpBase) {
                $fp = __DIR__ . '/../uploads/psc/' . $fpBase;
                if (file_exists($fp)) @unlink($fp);
            }
        }
        // Обнулить ссылки и удалить запись — в транзакции
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE product_prices SET agreement_id=NULL WHERE agreement_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM price_agreements WHERE id=?")->execute([$id]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('delete_agreement error: ' . $e->getMessage());
            respond(['error' => 'Ошибка удаления'], 500);
        }
        respond(['success' => true]);
    }

    if ($fn === 'delete_price') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'Не указан ID'], 400);
        $s = $pdo->prepare("SELECT * FROM product_prices WHERE id=?"); $s->execute([$id]); $row = $s->fetch();
        if (!$row) respond(['error' => 'Цена не найдена'], 404);
        if (!checkLegalEntityAccess($caller, $row['legal_entity'])) respond(['error' => 'Нет доступа'], 403);
        $perms = resolvePermissions($caller['role'] ?? 'user', $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['pricing'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);
        $pdo->prepare("DELETE FROM product_prices WHERE id=?")->execute([$id]);
        respond(['success' => true]);
    }

    if ($fn === 'get_price_history') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $sku = $body['sku'] ?? '';
        $le = $body['legal_entity'] ?? '';
        $supplier = $body['supplier'] ?? '';
        if (!$sku || !$le) respond(['error' => 'Не указаны обязательные поля'], 400);
        if (!checkLegalEntityAccess($caller, $le)) respond(['error' => 'Нет доступа'], 403);
        $sql = "SELECT * FROM price_history WHERE sku=? AND legal_entity=?";
        $params = [$sku, $le];
        if ($supplier) { $sql .= " AND supplier=?"; $params[] = $supplier; }
        $sql .= " ORDER BY changed_at DESC LIMIT 20";
        $s = $pdo->prepare($sql); $s->execute($params);
        respond($s->fetchAll());
    }

    if ($fn === 'get_products_without_prices') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $le = $body['legal_entity'] ?? '';
        $supplier = $body['supplier'] ?? '';
        if (!$le) respond(['error' => 'Не указаны обязательные поля'], 400);
        if (!checkLegalEntityAccess($caller, $le)) respond(['error' => 'Нет доступа'], 403);
        // Товары для группы юрлиц, у которых нет цены (опционально по поставщику)
        $params = [];
        $sql = "SELECT p.sku, p.name, p.supplier FROM products p WHERE p.is_active = 1";
        $leWhere = []; $leParams = [];
        applyEntityGroupFilter($le, $leWhere, $leParams, 'p.legal_entity');
        $sql .= " AND " . $leWhere[0];
        $params = array_merge($params, $leParams);
        if ($supplier) { $sql .= " AND p.supplier = ?"; $params[] = $supplier; }
        $sql .= " AND NOT EXISTS (SELECT 1 FROM product_prices pp WHERE pp.sku COLLATE utf8mb4_general_ci = p.sku AND pp.legal_entity COLLATE utf8mb4_general_ci = ?)";
        $params[] = $le;
        $sql .= " ORDER BY p.supplier, p.name";
        $s = $pdo->prepare($sql); $s->execute($params);
        respond($s->fetchAll());
    }

    // ═══ Тендеры: сохранить тендер целиком ═══
    if ($fn === 'save_tender') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $perms = resolvePermissions($caller['role'] ?? 'user', $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['tenders'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);

        $tenderId = intval($body['id'] ?? 0);
        $name = trim($body['name'] ?? '');
        $description = $body['description'] ?? null;
        $le = $body['legal_entity'] ?? '';
        $status = $body['status'] ?? 'draft';
        $deadline = $body['deadline'] ?? null;
        $winnerSupplier = $body['winner_supplier'] ?? null;
        $summary = $body['summary'] ?? null;
        $note = $body['note'] ?? null;
        $items = $body['items'] ?? [];
        $offers = $body['offers'] ?? [];

        if (!$name) respond(['error' => 'Укажите название тендера'], 400);
        if (!$le) respond(['error' => 'Не указано юрлицо'], 400);
        if (!checkLegalEntityAccess($caller, $le)) respond(['error' => 'Нет доступа к юр. лицу'], 403);

        $pdo->beginTransaction();
        try {
            if ($tenderId) {
                $pdo->prepare("UPDATE tenders SET name=?, description=?, status=?, deadline=?, winner_supplier=?, summary=?, note=?, updated_at=NOW() WHERE id=? AND legal_entity=?")
                    ->execute([$name, $description, $status, $deadline, $winnerSupplier, $summary, $note, $tenderId, $le]);
            } else {
                $pdo->prepare("INSERT INTO tenders (name, description, legal_entity, status, deadline, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$name, $description, $le, $status, $deadline, $note, $caller['name'] ?? '']);
                $tenderId = $pdo->lastInsertId();
            }

            // Позиции: удалить старые, вставить новые
            $pdo->prepare("DELETE FROM tender_items WHERE tender_id=?")->execute([$tenderId]);
            $itemIdMap = [];
            foreach ($items as $i => $item) {
                $pdo->prepare("INSERT INTO tender_items (tender_id, name, quantity, unit, sort_order, note) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$tenderId, $item['name'] ?? '', $item['quantity'] ?? null, $item['unit'] ?? null, $i, $item['note'] ?? null]);
                $itemIdMap[$i] = $pdo->lastInsertId();
            }

            // Предложения: удалить старые, вставить новые
            $pdo->prepare("DELETE FROM tender_offers WHERE tender_id=?")->execute([$tenderId]);
            foreach ($offers as $offer) {
                $pdo->prepare("INSERT INTO tender_offers (tender_id, supplier, delivery_days, payment_terms, conditions, note) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$tenderId, $offer['supplier'] ?? '', $offer['delivery_days'] ?? null, $offer['payment_terms'] ?? null, $offer['conditions'] ?? null, $offer['note'] ?? null]);
                $offerId = $pdo->lastInsertId();
                $prices = $offer['prices'] ?? [];
                foreach ($prices as $idx => $price) {
                    if (!isset($itemIdMap[$idx])) continue;
                    $pdo->prepare("INSERT INTO tender_offer_prices (offer_id, item_id, price) VALUES (?, ?, ?)")
                        ->execute([$offerId, $itemIdMap[$idx], $price]);
                }
            }

            $pdo->commit();
            respond(['success' => true, 'id' => intval($tenderId)]);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('save_tender error: ' . $e->getMessage());
            respond(['error' => 'Ошибка сохранения тендера'], 500);
        }
    }

    // ═══ Тендеры: загрузить тендер со всеми данными ═══
    if ($fn === 'get_tender') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'Не указан ID'], 400);

        $s = $pdo->prepare("SELECT * FROM tenders WHERE id=?"); $s->execute([$id]);
        $tender = $s->fetch();
        if (!$tender) respond(['error' => 'Тендер не найден'], 404);
        if (!checkLegalEntityAccess($caller, $tender['legal_entity'])) respond(['error' => 'Нет доступа'], 403);

        // Позиции
        $s = $pdo->prepare("SELECT * FROM tender_items WHERE tender_id=? ORDER BY sort_order"); $s->execute([$id]);
        $tender['items'] = $s->fetchAll();

        // Предложения + цены
        $s = $pdo->prepare("SELECT id, tender_id, supplier, delivery_days, payment_terms, conditions, note, created_at FROM tender_offers WHERE tender_id=? ORDER BY id"); $s->execute([$id]);
        $offers = $s->fetchAll();
        foreach ($offers as &$offer) {
            $s2 = $pdo->prepare("SELECT item_id, price FROM tender_offer_prices WHERE offer_id=?"); $s2->execute([$offer['id']]);
            $offer['prices'] = $s2->fetchAll();
        }
        $tender['offers'] = $offers;

        // Файлы КП
        $s = $pdo->prepare("SELECT id, supplier, file_name, file_path, uploaded_at FROM tender_files WHERE tender_id=? ORDER BY uploaded_at"); $s->execute([$id]);
        $tender['files'] = $s->fetchAll();

        respond($tender);
    }

    // ═══ Тендеры: удалить тендер ═══
    if ($fn === 'delete_tender') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $perms = resolvePermissions($caller['role'] ?? 'user', $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['tenders'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['full']) respond(['error' => 'Недостаточно прав'], 403);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'Не указан ID'], 400);
        $s = $pdo->prepare("SELECT legal_entity FROM tenders WHERE id=?"); $s->execute([$id]);
        $le = $s->fetchColumn();
        if (!$le) respond(['error' => 'Тендер не найден'], 404);
        if (!checkLegalEntityAccess($caller, $le)) respond(['error' => 'Нет доступа'], 403);
        // Удалить файлы КП с диска
        $fs = $pdo->prepare("SELECT file_path FROM tender_files WHERE tender_id=?"); $fs->execute([$id]);
        while ($fp = $fs->fetchColumn()) {
            $fpath = __DIR__ . '/../uploads/tenders/' . basename($fp);
            if (file_exists($fpath)) unlink($fpath);
        }
        // CASCADE удалит items, offers, offer_prices, files
        $pdo->prepare("DELETE FROM tenders WHERE id=?")->execute([$id]);
        respond(['success' => true]);
    }

    // ═══ Баг-репорты: создать ═══
    if ($fn === 'create_bug_report') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $title = trim($body['title'] ?? '');
        $description = trim($body['description'] ?? '');
        $screenshots = $body['screenshots'] ?? [];
        $actionLog = trim($body['action_log'] ?? '');
        $pageUrl = trim($body['page_url'] ?? '');
        $le = $body['legal_entity'] ?? '';
        $browserInfo = trim($body['browser_info'] ?? '');
        if (!$title) respond(['error' => 'Укажите тему сообщения'], 400);
        $stmt = $pdo->prepare("INSERT INTO bug_reports (title, description, screenshots, action_log, page_url, created_by, legal_entity, browser_info) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $description, json_encode($screenshots), $actionLog, $pageUrl, $caller['name'], $le, $browserInfo]);
        $id = $pdo->lastInsertId();
        respond(['success' => true, 'id' => intval($id)]);
    }

    // ═══ Баг-репорты: список (для админа — все, для юзера — свои) ═══
    if ($fn === 'get_bug_reports') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $isAdmin = ($caller['role'] ?? '') === 'admin';
        if ($isAdmin) {
            $s = $pdo->prepare("SELECT br.*, (SELECT COUNT(*) FROM bug_report_replies WHERE report_id=br.id) as reply_count FROM bug_reports br ORDER BY FIELD(br.status,'new','in_progress','resolved','closed'), br.created_at DESC");
            $s->execute();
        } else {
            $s = $pdo->prepare("SELECT br.*, (SELECT COUNT(*) FROM bug_report_replies WHERE report_id=br.id) as reply_count FROM bug_reports br WHERE br.created_by=? ORDER BY br.created_at DESC");
            $s->execute([$caller['name']]);
        }
        $rows = $s->fetchAll();
        foreach ($rows as &$r) {
            $r['screenshots'] = json_decode($r['screenshots'] ?: '[]', true);
        }
        respond(['reports' => $rows]);
    }

    // ═══ Баг-репорты: получить один с ответами ═══
    if ($fn === 'get_bug_report') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'Не указан ID'], 400);
        $isAdmin = ($caller['role'] ?? '') === 'admin';
        $s = $pdo->prepare("SELECT * FROM bug_reports WHERE id=?"); $s->execute([$id]);
        $report = $s->fetch();
        if (!$report) respond(['error' => 'Не найдено'], 404);
        if (!$isAdmin && $report['created_by'] !== $caller['name']) respond(['error' => 'Нет доступа'], 403);
        $report['screenshots'] = json_decode($report['screenshots'] ?: '[]', true);
        $rs = $pdo->prepare("SELECT * FROM bug_report_replies WHERE report_id=? ORDER BY created_at ASC"); $rs->execute([$id]);
        $replies = $rs->fetchAll();
        respond(['report' => $report, 'replies' => $replies]);
    }

    // ═══ Баг-репорты: ответить ═══
    if ($fn === 'reply_bug_report') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $reportId = intval($body['report_id'] ?? 0);
        $message = trim($body['message'] ?? '');
        if (!$reportId || !$message) respond(['error' => 'Укажите ID и сообщение'], 400);
        $s = $pdo->prepare("SELECT * FROM bug_reports WHERE id=?"); $s->execute([$reportId]);
        $report = $s->fetch();
        if (!$report) respond(['error' => 'Не найдено'], 404);
        $isAdmin = ($caller['role'] ?? '') === 'admin';
        if (!$isAdmin && $report['created_by'] !== $caller['name']) respond(['error' => 'Нет доступа'], 403);
        $pdo->prepare("INSERT INTO bug_report_replies (report_id, message, created_by, is_admin) VALUES (?, ?, ?, ?)")
            ->execute([$reportId, $message, $caller['name'], $isAdmin ? 1 : 0]);
        // Если админ ответил — статус «в работе»
        if ($isAdmin && $report['status'] === 'new') {
            $pdo->prepare("UPDATE bug_reports SET status='in_progress' WHERE id=?")->execute([$reportId]);
        }
        respond(['success' => true]);
    }

    // ═══ Баг-репорты: сменить статус (только админ) ═══
    if ($fn === 'update_bug_report_status') {
        $caller = getSessionUser($pdo);
        if (!$caller || ($caller['role'] ?? '') !== 'admin') respond(['error' => 'Только для администратора'], 403);
        $id = intval($body['id'] ?? 0);
        $status = $body['status'] ?? '';
        if (!$id || !in_array($status, ['new','in_progress','resolved','closed'])) respond(['error' => 'Неверные параметры'], 400);
        $pdo->prepare("UPDATE bug_reports SET status=? WHERE id=?")->execute([$status, $id]);
        respond(['success' => true]);
    }

    // ═══ Баг-репорты: удалить (только админ) ═══
    if ($fn === 'delete_bug_report') {
        $caller = getSessionUser($pdo);
        if (!$caller || ($caller['role'] ?? '') !== 'admin') respond(['error' => 'Только для администратора'], 403);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'Не указан ID'], 400);
        // Удалить скриншоты с диска
        $s = $pdo->prepare("SELECT screenshots FROM bug_reports WHERE id=?"); $s->execute([$id]);
        $row = $s->fetch();
        if ($row && $row['screenshots']) {
            $paths = json_decode($row['screenshots'], true) ?: [];
            foreach ($paths as $p) {
                $fp = __DIR__ . '/../uploads/bugs/' . basename($p);
                if (file_exists($fp)) @unlink($fp);
            }
        }
        $pdo->prepare("DELETE FROM bug_reports WHERE id=?")->execute([$id]);
        respond(['success' => true]);
    }

    // ═══ Баг-репорты: количество новых (для бейджа админа) ═══
    if ($fn === 'get_bug_reports_count') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $isAdmin = ($caller['role'] ?? '') === 'admin';
        if ($isAdmin) {
            $s = $pdo->prepare("SELECT COUNT(*) FROM bug_reports WHERE status IN ('new','in_progress')"); $s->execute();
            $count = intval($s->fetchColumn());
            $newCount = intval($pdo->query("SELECT COUNT(*) FROM bug_reports WHERE status='new'")->fetchColumn());
        } else {
            // Для обычного пользователя: количество непрочитанных ответов админа
            $s = $pdo->prepare("SELECT COUNT(DISTINCT br.id) FROM bug_reports br JOIN bug_report_replies brr ON brr.report_id=br.id WHERE br.created_by=? AND brr.is_admin=1 AND brr.created_at > br.updated_at");
            $s->execute([$caller['name']]);
            $count = intval($s->fetchColumn());
            $newCount = $count;
        }
        respond(['count' => $count, 'new_count' => $newCount]);
    }

    if ($fn === 'create_order') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация по сессии'], 401);
        $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['order'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
            respond(['error' => 'Недостаточно прав'], 403);
        }
        $order = $body['order'] ?? [];
        $items = $body['items'] ?? [];
        if (empty($order) || empty($items)) respond(['error' => 'Не указаны данные заказа или позиции'], 400);
        $le = $order['legal_entity'] ?? '';
        if (!$le) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $le)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        if (count($items) > 5000) respond(['error' => 'Слишком много позиций (макс. 5000)'], 400);
        // Белый список полей заказа
        $orderWhitelist = ['supplier','legal_entity','delivery_date','delivery_date_2','unit','note','details','created_by','cda_mode','safety_coef','today_date','safety_days','period_days','has_transit','show_stock_column'];
        $order = array_intersect_key($order, array_flip($orderWhitelist));
        $order['id'] = uuid();
        $order['created_at'] = date('Y-m-d H:i:s');
        $order['created_by'] = $caller['name'];
        // Белый список полей позиции
        $itemWhitelist = ['sku','name','qty_boxes','qty_per_box','boxes_per_pallet','multiplicity','consumption_period','stock','transit','final_order','manual_override','unit_of_measure','analog_group','category'];
        $pdo->beginTransaction();
        try {
            // Вставляем заказ
            foreach (array_keys($order) as $col) { if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) { $pdo->rollBack(); respond(['error' => 'Недопустимое имя колонки'], 400); } }
            $cols = array_keys($order);
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $cn = implode(',', array_map(fn($c) => "`$c`", $cols));
            $pdo->prepare("INSERT INTO `orders` ($cn) VALUES ($ph)")->execute(array_values($order));
            // Вставляем позиции
            foreach ($items as $item) {
                $item = array_intersect_key($item, array_flip($itemWhitelist));
                $item['id'] = uuid();
                $item['order_id'] = $order['id'];
                $cols = array_keys($item);
                $ph = implode(',', array_fill(0, count($cols), '?'));
                $cn = implode(',', array_map(fn($c) => "`$c`", $cols));
                $pdo->prepare("INSERT INTO `order_items` ($cn) VALUES ($ph)")->execute(array_values($item));
            }
            $pdo->commit();
            respond(['success' => true, 'id' => $order['id']]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("create_order error: " . $e->getMessage());
            respond(['error' => 'Ошибка создания заказа'], 500);
        }
    }

    if ($fn === 'update_order') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация по сессии'], 401);
        $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['order'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
            respond(['error' => 'Недостаточно прав'], 403);
        }
        $orderId = $body['order_id'] ?? '';
        $order = $body['order'] ?? [];
        $items = $body['items'] ?? [];
        if (!$orderId || empty($order)) respond(['error' => 'Не указаны данные заказа'], 400);
        if (count($items) > 5000) respond(['error' => 'Слишком много позиций (макс. 5000)'], 400);
        // Проверяем доступ к юрлицу заказа
        $orderCheck = $pdo->prepare("SELECT legal_entity, updated_at FROM orders WHERE id=?");
        $orderCheck->execute([$orderId]);
        $orderRow = $orderCheck->fetch();
        if (!$orderRow) respond(['error' => 'Заказ не найден'], 404);
        if (!checkLegalEntityAccess($caller, $orderRow['legal_entity'])) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        // Проверка конкурентного редактирования
        $expectedUpdatedAt = $body['expected_updated_at'] ?? null;
        if ($expectedUpdatedAt && $orderRow['updated_at'] && $orderRow['updated_at'] !== $expectedUpdatedAt) {
            respond(['error' => 'Заказ был изменён другим пользователем'], 409);
        }
        // Белый список полей заказа
        $orderWhitelist = ['supplier','legal_entity','delivery_date','delivery_date_2','unit','note','details','cda_mode','safety_coef','today_date','safety_days','period_days','has_transit','show_stock_column'];
        $order = array_intersect_key($order, array_flip($orderWhitelist));
        $order['updated_at'] = date('Y-m-d H:i:s');
        // Белый список полей позиции
        $itemWhitelist = ['sku','name','qty_boxes','qty_per_box','boxes_per_pallet','multiplicity','consumption_period','stock','transit','final_order','manual_override','unit_of_measure','received_qty','analog_group','category'];
        $pdo->beginTransaction();
        try {
            // Блокируем заказ
            $lockStmt = $pdo->prepare("SELECT id FROM `orders` WHERE id=? FOR UPDATE");
            $lockStmt->execute([$orderId]);
            if (!$lockStmt->fetch()) { $pdo->rollBack(); respond(['error' => 'Заказ не найден'], 404); }
            // Обновляем параметры заказа
            $set = []; $sp = [];
            foreach ($order as $c => $v) {
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $c)) { $pdo->rollBack(); respond(['error' => 'Недопустимое имя колонки'], 400); }
                $set[] = "`$c`=?"; $sp[] = $v;
            }
            if (!empty($set)) {
                $sp[] = $orderId;
                $pdo->prepare("UPDATE `orders` SET " . implode(',', $set) . " WHERE id=?")->execute($sp);
            }
            // Заменяем позиции
            $pdo->prepare("DELETE FROM `order_items` WHERE `order_id`=?")->execute([$orderId]);
            foreach ($items as $item) {
                $item = array_intersect_key($item, array_flip($itemWhitelist));
                $item['id'] = uuid();
                $item['order_id'] = $orderId;
                $cols = array_keys($item);
                $ph = implode(',', array_fill(0, count($cols), '?'));
                $cn = implode(',', array_map(fn($c) => "`$c`", $cols));
                $pdo->prepare("INSERT INTO `order_items` ($cn) VALUES ($ph)")->execute(array_values($item));
            }
            $pdo->commit();
            respond(['success' => true, 'updated_at' => $order['updated_at']]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("update_order error: " . $e->getMessage());
            respond(['error' => 'Ошибка обновления заказа'], 500);
        }
    }

    respond(['error'=>'Not found'], 404);
}
