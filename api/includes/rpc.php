<?php
/**
 * RPC-эндпоинты (публичные и приватные).
 * Подключается из index.php.
 */

function rpcNormalizeStockCollectionBatches($batches, $allowExpiry = true) {
    $out = [];
    if (!is_array($batches)) return $out;
    foreach ($batches as $batch) {
        if (!is_array($batch)) continue;
        $expiry = trim((string)($batch['expiry_date'] ?? ''));
        $stock = $batch['stock'] ?? null;
        if (!is_numeric($stock)) continue;
        $stockVal = round(floatval($stock), 2);
        if ($stockVal < 0 || $stockVal > 999999) continue;
        if ($allowExpiry && $expiry !== '') {
            $dt = DateTime::createFromFormat('Y-m-d', $expiry);
            if (!$dt || $dt->format('Y-m-d') !== $expiry) continue;
            $out[] = ['expiry_date' => $expiry, 'stock' => $stockVal];
        } else {
            $out[] = ['expiry_date' => null, 'stock' => $stockVal];
        }
    }
    return $out;
}

// ═══ RPC (публичные — без API-ключа) ═══
if ($endpoint === 'rpc') {
    $fn = $subpoint ?? '';
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // --- Публичные RPC (доступны без авторизации) ---

    if ($fn === 'check_user_password') {
        $email = $body['user_email'] ?? ''; $pass = $body['user_password'] ?? '';
        $acceptedDataRules = !empty($body['accepted_data_rules']);
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['success'=>false,'error'=>'invalid_email'], 400);
        if (!$acceptedDataRules) respond(['success'=>false,'error'=>'data_rules_required'], 400);
        if (!checkRateLimit($pdo, $clientIp)) respond(['success'=>false,'error'=>'too_many_attempts'], 429);
        // Account-level rate-limit: защита от distributed brute-force одного аккаунта.
        if (!checkAccountRateLimit($pdo, $email, 5, 10)) respond(['success'=>false,'error'=>'too_many_attempts'], 429);
        $s = $pdo->prepare("SELECT id,name,password,role,display_role,legal_entities,permissions,created_at,telegram_chat_id,hidden_modules FROM users WHERE email=?");
        $s->execute([$email]); $u = $s->fetch();
        if (!$u) { recordFailedLogin($pdo, $clientIp, $email); respond(['success'=>false,'error'=>'invalid_credentials']); }
        if (!verifyAndMigratePassword($pdo, $u['name'], $pass, $u['password'])) { recordFailedLogin($pdo, $clientIp, $email); respond(['success'=>false,'error'=>'invalid_credentials']); }
        $le = $u['legal_entities'];
        $le = ($le && is_string($le)) ? (json_decode($le, true) ?? []) : [];
        $displayRole = $u['display_role'] ?? null;
        $permsRaw = $u['permissions'] ?? null;
        $permsDecoded = ($permsRaw && is_string($permsRaw)) ? json_decode($permsRaw, true) : null;
        $sessionToken = createSessionToken($pdo, $u['name']);
        recordPortalConsent($pdo, 'staff', $email, $u['name']);
        try { $pdo->prepare("INSERT INTO login_log (email, user_name, ip, created_at) VALUES (?, ?, ?, NOW())")->execute([$email, $u['name'], $clientIp]); } catch (PDOException $e) {}
        $mm = $pdo->prepare("SELECT `key`,`value` FROM settings WHERE `key` IN ('maintenance_mode','maintenance_message')"); $mm->execute();
        $mmRows = $mm->fetchAll(); $maintenanceVal = 'false'; $maintenanceMsg = '';
        foreach ($mmRows as $mr) { if ($mr['key'] === 'maintenance_mode') $maintenanceVal = $mr['value']; if ($mr['key'] === 'maintenance_message') $maintenanceMsg = $mr['value']; }
        $hiddenMods = ($u['hidden_modules'] && is_string($u['hidden_modules'])) ? (json_decode($u['hidden_modules'], true) ?? []) : [];
        respond(['success'=>true,'user'=>['name'=>$u['name'],'role'=>$u['role']??'user','display_role'=>$displayRole,'legal_entities'=>$le,'permissions'=>$permsDecoded,'created_at'=>$u['created_at'] ?? null,'telegram_connected'=>!empty($u['telegram_chat_id']),'hidden_modules'=>$hiddenMods],'session_token'=>$sessionToken,'maintenance_mode'=>$maintenanceVal==='true','maintenance_message'=>$maintenanceMsg ?: null]);
    }
    if ($fn === 'health_check') {
        $status = 'ok';
        $checks = [];
        // Проверка БД
        try {
            $pdo->query("SELECT 1");
            $checks['database'] = 'ok';
        } catch (Exception $e) {
            $checks['database'] = 'error';
            $status = 'error';
        }
        // Проверка памяти
        $memFree = @file_get_contents('/proc/meminfo');
        if ($memFree && preg_match('/MemAvailable:\s+(\d+)/', $memFree, $m)) {
            $availMb = intval($m[1]) / 1024;
            $checks['memory_available_mb'] = round($availMb);
            if ($availMb < 100) { $checks['memory'] = 'warning'; $status = 'warning'; }
            else $checks['memory'] = 'ok';
        }
        // Проверка диска
        $diskFree = @disk_free_space('/');
        if ($diskFree !== false) {
            $diskFreeMb = round($diskFree / 1024 / 1024);
            $checks['disk_free_mb'] = $diskFreeMb;
            if ($diskFreeMb < 500) { $checks['disk'] = 'warning'; $status = 'warning'; }
            else $checks['disk'] = 'ok';
        }
        respond(['status' => $status, 'checks' => $checks, 'timestamp' => date('c')]);
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
    // ─── Поиск карточек: страница доступна только закупщикам и ресторанам ───
    // Раньше эти RPC были публичными — get_stock_skus отдавал остатки любого
    // юрлица без авторизации. Теперь требуется либо сессия закупщика (X-Session-Token),
    // либо сессия ресторана (X-RO-Token). Результат — массив контекста или null.
    $resolveCardSearchAuth = function() use ($pdo) {
        $supply = getSessionUser($pdo);
        if ($supply) {
            return [
                'kind' => 'supply',
                'name' => $supply['name'] ?? '',
                'restaurant_number' => null,
                'group' => null,
                'user' => $supply,
            ];
        }
        // restaurant_orders.php подключается из index.php ПОСЛЕ rpc.php — поэтому
        // функцию подгружаем лениво. require_once безопасен: блок с if ($endpoint===...)
        // в файле сам решит, исполнять ему логику или нет.
        if (!function_exists('roGetRestaurantSession')) {
            require_once __DIR__ . '/restaurant_orders.php';
        }
        $ro = function_exists('roGetRestaurantSession') ? roGetRestaurantSession($pdo) : null;
        if ($ro) {
            $group = $ro['legal_entity_group'] ?? 'BK_VM';
            return [
                'kind' => 'restaurant',
                'name' => 'ro:' . ($ro['restaurant_number'] ?? ''),
                'restaurant_number' => (string)($ro['restaurant_number'] ?? ''),
                'group' => $group,
                'user' => $ro,
            ];
        }
        return null;
    };
    $requireCardSearchAuth = function() use ($resolveCardSearchAuth) {
        $ctx = $resolveCardSearchAuth();
        if (!$ctx) respond(['error' => 'Требуется авторизация'], 401);
        return $ctx;
    };

    if ($fn === 'guest_heartbeat') {
        $requireCardSearchAuth();
        $sid = $body['session_id'] ?? '';
        $page = $body['page'] ?? 'search-cards';
        if ($sid && preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $sid)) {
            $s = $pdo->prepare("INSERT INTO guest_presence (session_id, page, last_seen) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE page=VALUES(page), last_seen=NOW()");
            $s->execute([$sid, substr($page, 0, 100)]);
        }
        respond(['success' => true]);
    }
    if ($fn === 'get_guest_count') {
        $requireCardSearchAuth();
        // Чистим старые записи (старше 5 минут)
        $pdo->exec("DELETE FROM guest_presence WHERE last_seen < NOW() - INTERVAL 5 MINUTE");
        $s = $pdo->query("SELECT COUNT(*) as cnt FROM guest_presence WHERE last_seen > NOW() - INTERVAL 1 MINUTE");
        respond($s->fetch());
    }
    if ($fn === 'log_card_search') {
        $ctx = $requireCardSearchAuth();
        if (!checkRateLimit($pdo, $clientIp, 30, 1)) respond(['success' => true]); // Тихий rate-limit: макс 30 поисков/мин
        $q = $body['query'] ?? '';
        $found = $body['found'] ?? false;
        $matchType = $body['match_type'] ?? null;
        $matchedId = $body['matched_card_id'] ?? null;
        // Юрлицо контекста: для ресторана — основное юрлицо его группы;
        // для закупщика — первое из его доступных (для аналитики, не критично).
        $logLegalEntity = null;
        if ($ctx['kind'] === 'restaurant') {
            $entities = getEntitiesInGroup($ctx['group']);
            $logLegalEntity = $entities[0] ?? null;
        } else {
            $userEnts = $ctx['user']['legal_entities'] ?? '';
            if (is_string($userEnts)) $userEnts = json_decode($userEnts, true);
            if (is_array($userEnts) && !empty($userEnts)) $logLegalEntity = $userEnts[0];
        }
        if ($q && mb_strlen($q) <= 200) {
            $s = $pdo->prepare("INSERT INTO search_logs (query, found, match_type, matched_card_id, searcher_kind, searcher_name, restaurant_number, legal_entity, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $s->execute([
                mb_substr($q, 0, 200),
                $found ? 1 : 0,
                $matchType ? mb_substr($matchType, 0, 50) : null,
                $matchedId,
                $ctx['kind'],
                mb_substr($ctx['name'], 0, 200),
                $ctx['restaurant_number'],
                $logLegalEntity ? mb_substr($logLegalEntity, 0, 100) : null,
            ]);
        }
        respond(['success' => true]);
    }
    if ($fn === 'get_cards') {
        $requireCardSearchAuth();
        $s = $pdo->query("SELECT id, name, analogs, updated_by, updated_at FROM cards ORDER BY name");
        respond($s->fetchAll());
    }
    if ($fn === 'get_cards_last_update') {
        $requireCardSearchAuth();
        $s = $pdo->prepare("SELECT `value` FROM settings WHERE `key`='last_update'"); $s->execute();
        $row = $s->fetch();
        respond($row ?: ['value' => null]);
    }

    // Артикулы на остатках (для поиска карточек).
    // Только для авторизованных. Ресторан получает остатки своей группы юрлиц.
    // Закупщик — указанное юрлицо, но с проверкой через checkLegalEntityAccess.
    if ($fn === 'get_stock_skus') {
        $ctx = $requireCardSearchAuth();
        $le = $body['legal_entity'] ?? $_GET['legal_entity'] ?? null;
        if ($ctx['kind'] === 'restaurant') {
            // Ресторан: разрешаем только юрлица его группы. Если ничего не передали — берём основное.
            $allowedEntities = getEntitiesInGroup($ctx['group']);
            if (!$le || !in_array($le, $allowedEntities, true)) {
                $le = $allowedEntities[0];
            }
        } else {
            // Закупщик: дефолт — «Бургер БК», но обязательно проверяем доступ.
            if (!$le) $le = 'ООО "Бургер БК"';
            $valid = array_merge(getEntitiesInGroup('BK_VM'), getEntitiesInGroup('PS'));
            if (!in_array($le, $valid, true)) $le = 'ООО "Бургер БК"';
            if (!checkLegalEntityAccess($ctx['user'], $le)) {
                respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
            }
        }
        $s = $pdo->prepare("SELECT a.sku, p.name, a.stock, COALESCE(p.qty_per_box, 1) as qty_per_box FROM analysis_data a LEFT JOIN products p ON p.sku = a.sku AND p.legal_entity = a.legal_entity AND p.is_active = 1 WHERE a.legal_entity = ? AND a.stock > 0");
        $s->execute([$le]);
        $rows = $s->fetchAll();
        $result = [];
        foreach ($rows as $r) {
            $qpb = floatval($r['qty_per_box']) ?: 1;
            $result[$r['sku']] = ['name' => $r['name'], 'stock' => round(floatval($r['stock']) / $qpb, 1)];
        }
        respond($result);
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

    // ═══ STOCK COLLECTION: публичная форма удалена.
    // Сбор остатков идёт только через ЛК ресторана (X-RO-Token, см. restaurant_orders.php)
    // и Telegram-бота (см. bot_rest.php). RPC sc_validate_token / sc_get_restaurants /
    // sc_submit_stock / sc_create_token больше не существуют.
    if (in_array($fn, ['sc_validate_token', 'sc_get_restaurants', 'sc_submit_stock', 'sc_create_token'], true)) {
        respond(['error' => 'Публичная ссылка для сбора остатков отключена. Используйте ЛК ресторана или Telegram-бот.'], 410);
    }

    if (str_starts_with($fn, 'veg_')) {
        respond(['error' => 'Старый модуль Планеты Ресторанов отключён. Используйте раздел «Заявки поставщикам».'], 410);
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
        $hiddenMods2 = ($sessionUser['hidden_modules'] && is_string($sessionUser['hidden_modules'])) ? (json_decode($sessionUser['hidden_modules'], true) ?? []) : [];
        respond(['valid' => true, 'user' => ['name' => $sessionUser['name'], 'email' => $sessionUser['email'] ?? null, 'role' => $sessionUser['role'] ?? 'user', 'display_role' => $sessionUser['display_role'] ?? null, 'legal_entities' => $le, 'permissions' => $permsDecoded2, 'created_at' => $sessionUser['created_at'] ?? null, 'telegram_connected' => !empty($sessionUser['telegram_chat_id']), 'hidden_modules' => $hiddenMods2]]);
    }

    if ($fn === 'save_hidden_modules') {
        $sessionUser = getSessionUser($pdo);
        if (!$sessionUser) respond(['error' => 'Требуется авторизация'], 401);
        $modules = $body['modules'] ?? [];
        if (!is_array($modules)) $modules = [];
        $json = count($modules) > 0 ? json_encode(array_values($modules), JSON_UNESCAPED_UNICODE) : null;
        $pdo->prepare("UPDATE users SET hidden_modules=? WHERE name=?")->execute([$json, $sessionUser['name']]);
        respond(['success' => true]);
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
        // Связанные изменения — в одной транзакции, чтобы при сбое не оставить
        // chat_id отвязанным от прежнего пользователя без привязки к новому.
        $pdo->beginTransaction();
        try {
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
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('confirm_telegram_link error: ' . $e->getMessage());
            respond(['error' => 'Ошибка привязки'], 500);
        }
        // Отправляем приветствие в Telegram
        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if ($botToken) {
            $tgMsg = "✅ Аккаунт <b>" . htmlspecialchars($sessionUser['name'], ENT_QUOTES, 'UTF-8') . "</b> привязан!\n\nТеперь вам доступны все команды бота.\nНажмите /start для меню.";
            sendTelegramMessage($botToken, $chatId, $tgMsg);
        }
        respond(['success' => true, 'user_name' => $sessionUser['name']]);
    }

    // ═══ Сброс пароля кабинета ресторана через Telegram (публичный) ═══

    if ($fn === 'request_password_reset') {
        $restaurantNumber = trim((string)($body['restaurant_number'] ?? ''));
        if (!$restaurantNumber) respond(['error' => 'Укажите номер ресторана'], 400);

        $parsed = parseRestaurantInput($restaurantNumber);
        if (!$parsed || !$parsed['number']) {
            respond(['error' => 'Неверный номер ресторана'], 400);
        }
        $normalizedNumber = (string)$parsed['number'];
        // Группа юрлиц обязательна для разделения BK_VM ↔ PS при пересечениях номеров.
        $normalizedGroup  = $parsed['group'];

        // Тихий троттлинг: не сообщаем о превышении, чтобы не дать перебирать
        // номера и не сигналить об их существовании. Лимиты:
        //   - не более 5 запросов с одного IP за 10 минут;
        //   - не более 3 запросов на ресторан за 10 минут.
        try {
            $ipStmt = $pdo->prepare("SELECT COUNT(*) FROM password_reset_logs WHERE ip_address = ? AND created_at > (NOW() - INTERVAL 10 MINUTE)");
            $ipStmt->execute([$clientIp]);
            $ipCount = (int)$ipStmt->fetchColumn();

            $restStmt = $pdo->prepare("SELECT COUNT(*) FROM password_reset_logs WHERE restaurant_number = ? AND created_at > (NOW() - INTERVAL 10 MINUTE)");
            $restStmt->execute([$normalizedNumber]);
            $restCount = (int)$restStmt->fetchColumn();

            if ($ipCount >= 5 || $restCount >= 3) {
                try {
                    $pdo->prepare("INSERT INTO password_reset_logs (restaurant_number, ip_address, created_at) VALUES (?, ?, NOW())")
                        ->execute([$normalizedNumber, $clientIp]);
                } catch (Exception $e) {}
                respond(['success' => true]);
            }
        } catch (Exception $e) {
            // Если таблицы нет/проблема с БД — не блокируем поток сброса.
        }

        // Существует ли ресторан в этой группе
        $restCheck = $pdo->prepare("SELECT id FROM restaurants WHERE number = ? AND legal_entity_group = ? AND active = 1 LIMIT 1");
        $restCheck->execute([$normalizedNumber, $normalizedGroup]);
        if (!$restCheck->fetch()) {
            // Не сообщаем о факте существования — для безопасности.
            try {
                $pdo->prepare("INSERT INTO password_reset_logs (restaurant_number, ip_address, created_at) VALUES (?, ?, NOW())")
                    ->execute([$normalizedNumber, $clientIp]);
            } catch (Exception $e) {}
            respond(['success' => true]);
        }

        // Есть ли подписчики в Telegram (в этой группе)
        $subCheck = $pdo->prepare("SELECT COUNT(*) FROM ro_telegram_subs WHERE restaurant_number = ? AND legal_entity_group = ? AND (verified_at IS NOT NULL OR (must_reverify_by IS NOT NULL AND must_reverify_by > NOW()))");
        $subCheck->execute([$normalizedNumber, $normalizedGroup]);
        $subCount = (int)$subCheck->fetchColumn();
        if ($subCount === 0) {
            try {
                $pdo->prepare("INSERT INTO password_reset_logs (restaurant_number, ip_address, created_at) VALUES (?, ?, NOW())")
                    ->execute([$normalizedNumber, $clientIp]);
            } catch (Exception $e) {}
            respond(['success' => true]);
        }

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // expires_at считаем в SQL, чтобы быть в одной таймзоне с created_at и
        // проверками верификации (PHP может быть в UTC, MySQL — в локальной).
        $pdo->prepare("INSERT INTO password_reset_codes (restaurant_number, legal_entity_group, code, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))")
            ->execute([$normalizedNumber, $normalizedGroup, $code]);

        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if ($botToken) {
            $subs = $pdo->prepare("SELECT DISTINCT chat_id FROM ro_telegram_subs WHERE restaurant_number = ? AND legal_entity_group = ? AND (verified_at IS NOT NULL OR (must_reverify_by IS NOT NULL AND must_reverify_by > NOW()))");
            $subs->execute([$normalizedNumber, $normalizedGroup]);
            $chatIds = $subs->fetchAll(PDO::FETCH_COLUMN);

            $displayNumber = formatRestaurantNumber($normalizedNumber);
            $msgText = "🔐 <b>Сброс пароля</b>\n\n";
            $msgText .= "Ваш код для сброса пароля ресторана <b>{$displayNumber}</b>:\n\n";
            $msgText .= "<b>{$code}</b>\n\n";
            $msgText .= "Код действителен 10 минут.\n";
            $msgText .= "Если вы не запрашивали сброс — проигнорируйте это сообщение.";

            foreach ($chatIds as $cid) {
                $payload = json_encode(['chat_id' => $cid, 'text' => $msgText, 'parse_mode' => 'HTML']);
                $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_TIMEOUT => 5,
                ]);
                curl_exec($ch);
                curl_close($ch);
            }
        }

        try {
            $pdo->prepare("INSERT INTO password_reset_logs (restaurant_number, ip_address, created_at) VALUES (?, ?, NOW())")
                ->execute([$normalizedNumber, $clientIp]);
        } catch (Exception $e) {}

        respond(['success' => true]);
    }

    if ($fn === 'verify_reset_code') {
        $restaurantNumber = trim((string)($body['restaurant_number'] ?? ''));
        $code = trim((string)($body['code'] ?? ''));

        if (!$restaurantNumber || !$code) {
            respond(['error' => 'Укажите номер ресторана и код'], 400);
        }

        $parsed = parseRestaurantInput($restaurantNumber);
        if (!$parsed || !$parsed['number']) {
            respond(['error' => 'Неверный номер ресторана'], 400);
        }
        $normalizedNumber = (string)$parsed['number'];
        $normalizedGroup  = $parsed['group'];

        // Лимит на перебор кода: 5 неудач в течение 10 минут — отказ.
        try {
            $failStmt = $pdo->prepare("SELECT COUNT(*) FROM password_reset_logs WHERE ip_address = ? AND created_at > (NOW() - INTERVAL 10 MINUTE)");
            $failStmt->execute([$clientIp]);
            if ((int)$failStmt->fetchColumn() >= 30) {
                respond(['error' => 'Слишком много попыток. Подождите 10 минут.'], 429);
            }
        } catch (Exception $e) {}

        // Проверки срока действия делаем в SQL, чтобы не зависеть от таймзоны PHP.
        // Поиск кода — строго в рамках пары (номер + группа), чтобы не подменить
        // другую группу при пересечении номеров.
        $stmt = $pdo->prepare("SELECT id, used_at, (expires_at < NOW()) AS is_expired FROM password_reset_codes WHERE restaurant_number = ? AND legal_entity_group = ? AND code = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$normalizedNumber, $normalizedGroup, $code]);
        $row = $stmt->fetch();

        if (!$row) {
            try {
                $pdo->prepare("INSERT INTO password_reset_logs (restaurant_number, ip_address, created_at) VALUES (?, ?, NOW())")
                    ->execute([$normalizedNumber, $clientIp]);
            } catch (Exception $e) {}
            respond(['error' => 'Неверный код'], 400);
        }

        if ($row['used_at'] !== null) {
            respond(['error' => 'Код уже использован'], 400);
        }

        if ((int)$row['is_expired'] === 1) {
            respond(['error' => 'Код истёк, запросите новый'], 400);
        }

        $resetToken = bin2hex(random_bytes(32));
        $pdo->prepare("UPDATE password_reset_codes SET reset_token = ?, used_at = NOW() WHERE id = ?")
            ->execute([$resetToken, $row['id']]);

        respond(['success' => true, 'reset_token' => $resetToken]);
    }

    if ($fn === 'reset_password') {
        $resetToken = trim((string)($body['reset_token'] ?? ''));
        $newPassword = $body['new_password'] ?? '';

        if (!$resetToken || !$newPassword) {
            respond(['error' => 'Укажите токен и новый пароль'], 400);
        }

        if (mb_strlen($newPassword) < 8) {
            respond(['error' => 'Пароль должен быть не менее 8 символов'], 400);
        }

        // Токен должен быть валидным и не старше 30 минут с момента активации.
        // Сравнение времени делаем в SQL, чтобы не зависеть от таймзоны PHP.
        $stmt = $pdo->prepare("SELECT id, restaurant_number, legal_entity_group, used_at, (used_at < (NOW() - INTERVAL 30 MINUTE)) AS is_expired FROM password_reset_codes WHERE reset_token = ? LIMIT 1");
        $stmt->execute([$resetToken]);
        $row = $stmt->fetch();

        if (!$row) {
            respond(['error' => 'Неверный токен'], 400);
        }

        if ($row['used_at'] === null) {
            respond(['error' => 'Токен не активирован'], 400);
        }

        if ((int)$row['is_expired'] === 1) {
            respond(['error' => 'Токен истёк, запросите новый код'], 400);
        }

        $restaurantNumber = $row['restaurant_number'];
        // Группа фиксирована тем, в какой группе создавался код. UPDATE,
        // DELETE и уведомления выполняются строго по паре, чтобы не задеть
        // одноимённый номер в другой группе.
        $resetGroup = $row['legal_entity_group'];

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $updateStmt = $pdo->prepare("UPDATE ro_users SET password_hash = ? WHERE restaurant_number = ? AND legal_entity_group = ? AND is_active = 1");
        $updateStmt->execute([$hash, $restaurantNumber, $resetGroup]);

        if ($updateStmt->rowCount() === 0) {
            respond(['error' => 'Ресторан не найден'], 404);
        }

        // Уведомление на email (если у учётки есть подтверждённый адрес).
        try {
            $uidStmt = $pdo->prepare("SELECT id FROM ro_users WHERE restaurant_number = ? AND legal_entity_group = ? LIMIT 1");
            $uidStmt->execute([$restaurantNumber, $resetGroup]);
            $userId = (int)$uidStmt->fetchColumn();
            if ($userId && function_exists('roSendAccountEmail')) {
                roSendAccountEmail($pdo, $userId, 'pwd_changed', [
                    'source' => 'через код из Telegram',
                    'ip'     => $clientIp ?? ($_SERVER['REMOTE_ADDR'] ?? null),
                ]);
            }
        } catch (Throwable $e) {}

        // Сбрасываем все коды по этому ресторану в этой группе.
        $pdo->prepare("DELETE FROM password_reset_codes WHERE restaurant_number = ? AND legal_entity_group = ?")
            ->execute([$restaurantNumber, $resetGroup]);

        // Сбрасываем активные сессии в кабинете, чтобы старая вкладка не работала.
        // Чистим и новую таблицу мультисессий, и legacy-колонки в ro_users.
        try {
            roRevokeAllSessionsForRestaurant($pdo, $restaurantNumber, $resetGroup);
            $pdo->prepare("UPDATE ro_users SET session_token = NULL, session_active_until = NULL WHERE restaurant_number = ? AND legal_entity_group = ?")
                ->execute([$restaurantNumber, $resetGroup]);
        } catch (Exception $e) {}

        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if ($botToken) {
            $subs = $pdo->prepare("SELECT DISTINCT chat_id FROM ro_telegram_subs WHERE restaurant_number = ? AND legal_entity_group = ? AND (verified_at IS NOT NULL OR (must_reverify_by IS NOT NULL AND must_reverify_by > NOW()))");
            $subs->execute([$restaurantNumber, $resetGroup]);
            $chatIds = $subs->fetchAll(PDO::FETCH_COLUMN);

            $displayNumber = formatRestaurantNumber($restaurantNumber);
            $msgText = "✅ <b>Пароль успешно изменён</b>\n\n";
            $msgText .= "Пароль для ресторана <b>{$displayNumber}</b> был успешно изменён.\n";
            $msgText .= "Теперь вы можете войти с новым паролем.";

            foreach ($chatIds as $cid) {
                $payload = json_encode(['chat_id' => $cid, 'text' => $msgText, 'parse_mode' => 'HTML']);
                $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_TIMEOUT => 5,
                ]);
                curl_exec($ch);
                curl_close($ch);
            }
        }

        respond(['success' => true]);
    }

    // ====================================================================
    // Сброс пароля сотрудников (через email).
    // Отдельный flow от ресторанного: длинная ссылка на email, не код в TG.
    // Таблицы: staff_password_reset_tokens, staff_password_reset_logs.
    // ====================================================================

    if ($fn === 'request_staff_password_reset') {
        $email = trim((string)($body['email'] ?? ''));
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['error' => 'Введите корректный email'], 400);
        }
        $email = mb_strtolower($email);

        // Тихий троттлинг — не палим лимиты, чтобы нельзя было перебирать.
        //   - не более 5 запросов с одного IP за 10 минут;
        //   - не более 1 запроса на email за 60 секунд.
        try {
            $ipStmt = $pdo->prepare("SELECT COUNT(*) FROM staff_password_reset_logs WHERE ip_address = ? AND created_at > (NOW() - INTERVAL 10 MINUTE)");
            $ipStmt->execute([$clientIp]);
            $ipCount = (int)$ipStmt->fetchColumn();

            $emStmt = $pdo->prepare("SELECT COUNT(*) FROM staff_password_reset_logs WHERE email = ? AND created_at > (NOW() - INTERVAL 1 MINUTE)");
            $emStmt->execute([$email]);
            $emCount = (int)$emStmt->fetchColumn();

            if ($ipCount >= 5 || $emCount >= 1) {
                $pdo->prepare("INSERT INTO staff_password_reset_logs (email, ip_address, result) VALUES (?, ?, 'rate_limited')")
                    ->execute([$email, $clientIp]);
                respond(['success' => true]);
            }
        } catch (Throwable $e) {}

        // Ищем пользователя. Не сообщаем, найден он или нет — отвечаем success в любом случае.
        $userStmt = $pdo->prepare("SELECT id, name, email FROM users WHERE email = ? LIMIT 1");
        $userStmt->execute([$email]);
        $user = $userStmt->fetch();

        if (!$user) {
            try {
                $pdo->prepare("INSERT INTO staff_password_reset_logs (email, ip_address, result) VALUES (?, ?, 'not_found')")
                    ->execute([$email, $clientIp]);
            } catch (Throwable $e) {}
            respond(['success' => true]);
        }

        // Сгенерировать одноразовый токен (256 бит энтропии).
        $token = bin2hex(random_bytes(32));
        $userAgent = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        try {
            $pdo->prepare("INSERT INTO staff_password_reset_tokens (user_id, email, token, expires_at, ip_address, user_agent) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), ?, ?)")
                ->execute([$user['id'], $email, $token, $clientIp, $userAgent]);
        } catch (Throwable $e) {
            error_log('[staff_pwd_reset] insert token failed: ' . $e->getMessage());
            respond(['success' => true]);
        }

        // Сборка и отправка письма.
        require_once __DIR__ . '/mail_send.php';
        require_once __DIR__ . '/mail_templates.php';

        $siteUrl  = rtrim($_ENV['SITE_URL'] ?? 'https://supply-department.online', '/');
        $resetUrl = $siteUrl . '/staff-reset-password?token=' . $token;
        $userName = trim((string)$user['name']);

        $intro = $userName !== '' ? "Здравствуйте, {$userName}!" : 'Здравствуйте!';
        $bodyHtml = '<p style="margin:0 0 12px;">Был получен запрос на сброс пароля для вашей учётной записи в системе Supply Department.</p>'
                  . '<p style="margin:0;">Нажмите кнопку ниже, чтобы задать новый пароль. Ссылка действительна <strong>30 минут</strong>.</p>';

        $html = renderMailHtml([
            'title'   => 'Сброс пароля',
            'preview' => 'Ссылка для сброса пароля действительна 30 минут',
            'intro'   => $intro,
            'body'    => $bodyHtml,
            'cta'     => ['text' => 'Сбросить пароль', 'url' => $resetUrl],
            'footer'  => 'Если вы не запрашивали сброс пароля — просто проигнорируйте это письмо, ваш текущий пароль останется без изменений.',
        ]);

        $sendResult = sendEmail($email, 'Сброс пароля — Supply Department', $html, true);

        try {
            $logRes = $sendResult['success'] ? 'sent' : 'not_found';
            $pdo->prepare("INSERT INTO staff_password_reset_logs (email, ip_address, result) VALUES (?, ?, ?)")
                ->execute([$email, $clientIp, $logRes]);
        } catch (Throwable $e) {}

        if (!$sendResult['success']) {
            error_log('[staff_pwd_reset] email send failed: ' . ($sendResult['error'] ?? 'unknown'));
        }
        respond(['success' => true]);
    }

    if ($fn === 'verify_staff_reset_token') {
        $token = trim((string)($body['token'] ?? ''));
        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            respond(['valid' => false, 'reason' => 'invalid'], 200);
        }

        $stmt = $pdo->prepare("SELECT id, email, used_at, (expires_at < NOW()) AS is_expired FROM staff_password_reset_tokens WHERE token = ? LIMIT 1");
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if (!$row) {
            try {
                $pdo->prepare("INSERT INTO staff_password_reset_logs (ip_address, result) VALUES (?, 'token_invalid')")->execute([$clientIp]);
            } catch (Throwable $e) {}
            respond(['valid' => false, 'reason' => 'invalid'], 200);
        }
        if ($row['used_at'] !== null) {
            respond(['valid' => false, 'reason' => 'used'], 200);
        }
        if ((int)$row['is_expired'] === 1) {
            respond(['valid' => false, 'reason' => 'expired'], 200);
        }

        // Возвращаем замаскированный email (для UX, чтобы пользователь понимал, для какого аккаунта меняет пароль).
        $emailParts = explode('@', $row['email']);
        $local = $emailParts[0] ?? '';
        $domain = $emailParts[1] ?? '';
        $maskedLocal = mb_strlen($local) <= 2 ? $local : mb_substr($local, 0, 1) . str_repeat('*', max(1, mb_strlen($local) - 2)) . mb_substr($local, -1);
        $maskedEmail = $maskedLocal . '@' . $domain;

        respond(['valid' => true, 'email' => $maskedEmail]);
    }

    if ($fn === 'reset_staff_password') {
        $token = trim((string)($body['token'] ?? ''));
        $newPassword = (string)($body['new_password'] ?? '');

        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            respond(['error' => 'Неверный токен'], 400);
        }
        if (mb_strlen($newPassword) < 8) {
            respond(['error' => 'Пароль должен быть не менее 8 символов'], 400);
        }

        $stmt = $pdo->prepare("SELECT id, user_id, email, used_at, (expires_at < NOW()) AS is_expired FROM staff_password_reset_tokens WHERE token = ? LIMIT 1");
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if (!$row) {
            try { $pdo->prepare("INSERT INTO staff_password_reset_logs (ip_address, result) VALUES (?, 'token_invalid')")->execute([$clientIp]); } catch (Throwable $e) {}
            respond(['error' => 'Ссылка недействительна'], 400);
        }
        if ($row['used_at'] !== null) {
            try { $pdo->prepare("INSERT INTO staff_password_reset_logs (email, ip_address, result) VALUES (?, ?, 'token_used')")->execute([$row['email'], $clientIp]); } catch (Throwable $e) {}
            respond(['error' => 'Ссылка уже была использована'], 400);
        }
        if ((int)$row['is_expired'] === 1) {
            try { $pdo->prepare("INSERT INTO staff_password_reset_logs (email, ip_address, result) VALUES (?, ?, 'token_expired')")->execute([$row['email'], $clientIp]); } catch (Throwable $e) {}
            respond(['error' => 'Срок действия ссылки истёк. Запросите новую.'], 400);
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);

        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $row['user_id']]);
            $pdo->prepare("UPDATE staff_password_reset_tokens SET used_at = NOW() WHERE id = ?")->execute([$row['id']]);
            // Инвалидируем все остальные неиспользованные токены этого пользователя.
            $pdo->prepare("UPDATE staff_password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
                ->execute([$row['user_id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[staff_pwd_reset] reset failed: ' . $e->getMessage());
            respond(['error' => 'Не удалось сменить пароль, попробуйте ещё раз'], 500);
        }

        try {
            $pdo->prepare("INSERT INTO staff_password_reset_logs (email, ip_address, result) VALUES (?, ?, 'reset_ok')")
                ->execute([$row['email'], $clientIp]);
        } catch (Throwable $e) {}

        respond(['success' => true]);
    }

    // --- Приватные RPC (требуют авторизацию) ---
    // Список RPC, доступных также по ресторанной сессии (cookie ro_session).
    // Каждый из этих эндпоинтов внутри ещё раз проверяет, кто звонит, через
    // $bugReportCaller() — лишнего доступа не выдаём.
    $RO_ALLOWED_RPC = ['create_bug_report', 'get_bug_reports', 'get_bug_report', 'get_bug_reports_count', 'reply_bug_report', 'create_download_token'];
    if (!checkAuth($pdo)) {
        $roAllowed = false;
        if (in_array($fn, $RO_ALLOWED_RPC, true)) {
            if (!function_exists('roGetRestaurantSession')) {
                require_once __DIR__ . '/restaurant_orders.php';
            }
            if (function_exists('roGetRestaurantSession') && roGetRestaurantSession($pdo)) {
                $roAllowed = true;
            }
        }
        if (!$roAllowed) respond(['error' => 'Unauthorized'], 401);
    }

    // Получаем имя авторизованного пользователя из сессии (для защиты от подмены user_name)
    $authUser = getSessionUser($pdo);
    $authUserName = $authUser ? $authUser['name'] : '';

    // Конфигурация RBAC — единый источник правды для фронтенда
    // ════════════════════════════════════════════════════════════════════
    // Отправка заявки поставщику по email с портала (с фирменного ящика
    // noreply@). Reply-To = info@, чтобы поставщик мог ответить нормально.
    // Логируется в order_email_log для аудита.
    // ════════════════════════════════════════════════════════════════════
    if ($fn === 'send_supplier_order_email') {
        if (!$authUser) respond(['error' => 'Требуется авторизация'], 401);

        $rawTo       = trim((string)($body['to'] ?? ''));
        $bodyText    = (string)($body['body_text'] ?? '');
        $supplier    = trim((string)($body['supplier'] ?? ''));
        $legalEntity = trim((string)($body['legal_entity'] ?? ''));
        $delivery    = trim((string)($body['delivery_date'] ?? ''));
        $itemsCount  = (int)($body['items_count'] ?? 0);

        if ($rawTo === '') respond(['error' => 'Не указан email получателя'], 400);
        if ($bodyText === '') respond(['error' => 'Пустое тело письма'], 400);

        // Парсим список адресов (запятая, точка с запятой, пробел).
        $recipients = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $rawTo)), function ($e) {
            return $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL);
        }));
        if (!$recipients) respond(['error' => 'Не указан корректный email получателя'], 400);
        if (count($recipients) > 10) respond(['error' => 'Слишком много адресов (максимум 10)'], 400);

        // В теме и заголовке письма используем полное наименование поставщика,
        // если оно заполнено в справочнике. Иначе — короткое (то, что пришло).
        // Заодно тянем cc_emails — постоянные получатели в копию.
        //
        // Ищем в пределах ГРУППЫ юрлиц (BK_VM / PS), а не точного юрлица:
        // карточки поставщиков обычно заведены только под одно юрлицо в группе
        // (как правило — основное), и заказ от другого юрлица группы должен
        // подтягивать тот же full_name. Без этого ВМ-заказы получали короткое
        // имя в теме, а БК-заказы — полное.
        $supplierDisplay = $supplier;
        $supplierCcRaw   = '';
        if ($supplier !== '') {
            $senderGroup = getEntityGroup($legalEntity);
            try {
                $sStmt = $pdo->prepare("
                    SELECT full_name, cc_emails
                    FROM suppliers
                    WHERE legal_entity_group = ?
                      AND (short_name = ? OR full_name = ?)
                      AND is_active = 1
                    ORDER BY (legal_entity = ?) DESC, id
                    LIMIT 1
                ");
                $sStmt->execute([$senderGroup, $supplier, $supplier, $legalEntity]);
                $sRow = $sStmt->fetch();
                if ($sRow) {
                    $full = trim((string)($sRow['full_name'] ?? ''));
                    if ($full !== '') $supplierDisplay = $full;
                    $supplierCcRaw = (string)($sRow['cc_emails'] ?? '');
                }
            } catch (Throwable $e) {}
        }

        $deliveryLabel = $delivery !== '' ? $delivery : '';
        // Тема: «Заказ от <юрлицо> для <supplier> на <дата>».
        // Юрлицо в теме важно — поставщик работает с несколькими нашими компаниями.
        $subjParts = ['Заказ'];
        if ($legalEntity !== '')    $subjParts[] = 'от ' . $legalEntity;
        if ($supplierDisplay !== '') $subjParts[] = 'для ' . $supplierDisplay;
        if ($deliveryLabel !== '')   $subjParts[] = 'на ' . $deliveryLabel;
        $subject = implode(' ', $subjParts);
        if (mb_strlen($subject) > 200) $subject = mb_substr($subject, 0, 200);

        require_once __DIR__ . '/mail_send.php';
        require_once __DIR__ . '/mail_templates.php';

        $siteUrl = rtrim($_ENV['SITE_URL'] ?? 'https://supply-department.online', '/');

        // Структурированные позиции для таблицы. Если фронт прислал items[] —
        // строим аккуратную таблицу, иначе fallback на текстовый body_text.
        $itemsRaw = isset($body['items']) && is_array($body['items']) ? $body['items'] : [];
        $esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
        $formatInt = function ($n) {
            $n = (float)$n;
            return number_format($n, ($n - floor($n) > 0.0001) ? 2 : 0, ',', ' ');
        };

        $itemsHtml = '';
        if (!empty($itemsRaw)) {
            // Outlook (Word-движок) игнорирует border-collapse/border-radius и многие CSS-свойства,
            // зато уважает border на каждой ячейке и mso-line-height-rule:exactly.
            // Поэтому: бордер у каждой td/th, фиксированный line-height в px против раздутых строк.
            $cellBase = 'padding:6px 10px;border:1px solid #d1d5db;line-height:18px;mso-line-height-rule:exactly;';
            $headBase = 'padding:6px 10px;border:1px solid #d1d5db;line-height:14px;mso-line-height-rule:exactly;background:#e9eef3;color:#1f2937;font-weight:700;font-size:12px;text-transform:uppercase;';
            $rowsHtml = '';
            $i = 0;
            foreach ($itemsRaw as $it) {
                if (!is_array($it)) continue;
                $i++;
                $sku    = $esc($it['sku']    ?? '');
                $name   = $esc($it['name']   ?? '');
                $boxes  = $formatInt($it['boxes']  ?? 0);
                $pieces = $formatInt($it['pieces'] ?? 0);
                $unit   = $esc($it['unit']   ?? 'шт');
                $rowBg  = ($i % 2 === 0) ? '#f6f8fa' : '#ffffff';
                $rowsHtml .=
                    '<tr>'
                  . '<td style="' . $cellBase . 'background:' . $rowBg . ';color:#6b7280;text-align:right;width:32px;">' . $i . '</td>'
                  . '<td style="' . $cellBase . 'background:' . $rowBg . ';color:#374151;white-space:nowrap;">' . $sku . '</td>'
                  . '<td style="' . $cellBase . 'background:' . $rowBg . ';color:#111827;">' . $name . '</td>'
                  . '<td style="' . $cellBase . 'background:' . $rowBg . ';color:#111827;text-align:right;white-space:nowrap;font-weight:700;">' . $boxes . ' кор.</td>'
                  . '<td style="' . $cellBase . 'background:' . $rowBg . ';color:#4b5563;text-align:right;white-space:nowrap;">' . $pieces . ' ' . $unit . '</td>'
                  . '</tr>';
            }
            $itemsHtml =
                '<table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;mso-table-lspace:0;mso-table-rspace:0;margin:24px 0 20px;font-family:Arial,sans-serif;font-size:14px;color:#1f2937;">'
              . '<thead><tr>'
              . '<th style="' . $headBase . 'text-align:right;">№</th>'
              . '<th style="' . $headBase . 'text-align:left;">Артикул</th>'
              . '<th style="' . $headBase . 'text-align:left;">Наименование</th>'
              . '<th style="' . $headBase . 'text-align:right;">Кол-во</th>'
              . '<th style="' . $headBase . 'text-align:right;">Штук</th>'
              . '</tr></thead>'
              . '<tbody>' . $rowsHtml . '</tbody>'
              . '</table>';
        } else {
            // fallback на старый текстовый блок — если items не прислали.
            $itemsHtml = '<div style="white-space:pre-wrap;margin-top:14px;font-size:14px;color:#1f2937;">'
                       . nl2br($esc($bodyText))
                       . '</div>';
        }

        // Минимализм без рамок и цветных блоков. Одна шапка-предложение,
        // таблица, две строки подписи. Дублирование заголовка убрано.
        $greetingLine = 'Здравствуйте!';
        $reqParts = ['Просьба отгрузить товар'];
        if ($legalEntity   !== '') $reqParts[] = 'для <strong>' . $esc($legalEntity) . '</strong>';
        if ($deliveryLabel !== '') $reqParts[] = 'с поставкой <strong>' . $esc($deliveryLabel) . '</strong>';
        $requestLine = implode(' ', $reqParts) . '.';

        $hasAttachment = !empty($body['attachment']) && is_array($body['attachment']);
        $attachLine = $hasAttachment
            ? '<div style="margin-top:18px;color:#4b5563;">Подробности — во вложении (Excel).</div>'
            : '';

        $html =
            '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
          . '<body style="margin:0;padding:0;background:#ffffff;color:#1f2937;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;font-size:14px;line-height:1.55;">'
          . '<div style="padding:24px 28px;max-width:760px;font-size:14px;">'
          . '<div style="margin-bottom:6px;">' . $greetingLine . '</div>'
          . '<div>' . $requestLine . '</div>'
          . $itemsHtml
          . $attachLine
          . '<div style="margin-top:22px;color:#1f2937;">Спасибо!</div>'
          . '</div>'
          . '</body></html>';

        // Slать с заказного ящика order@, Reply-To — туда же.
        $orderEmail = $_ENV['SMTP_ORDER_USER'] ?? 'order@supply-department.online';
        // getSessionUser() не возвращает id в массиве — достаём id и email
        // одним запросом по уникальному name (для CC отправителю и аудита).
        $senderEmail = '';
        $senderUserId = null;
        try {
            $eStmt = $pdo->prepare("SELECT id, email FROM users WHERE name = ? LIMIT 1");
            $eStmt->execute([$authUserName]);
            $senderRow = $eStmt->fetch();
            if ($senderRow) {
                $senderEmail  = trim((string)($senderRow['email'] ?? ''));
                $senderUserId = $senderRow['id'] ?? null;
            }
        } catch (Throwable $e) {}

        // Финальный список CC. Если фронт прислал свой `cc` (после
        // предпросмотра/правки в модалке) — берём как итог. Иначе собираем
        // сами: отправитель + cc_emails поставщика.
        $parseEmails = function ($raw) {
            if ($raw === null || $raw === '') return [];
            if (is_array($raw)) {
                $list = $raw;
            } else {
                $list = preg_split('/[,;\s]+/', (string)$raw);
            }
            $out = [];
            foreach ($list as $item) {
                $e = trim((string)$item);
                if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = $e;
            }
            return $out;
        };

        if (array_key_exists('cc', $body)) {
            $ccList = $parseEmails($body['cc']);
        } else {
            $ccList = [];
            if ($senderEmail !== '' && filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
                $ccList[] = $senderEmail;
            }
            foreach ($parseEmails($supplierCcRaw) as $e) $ccList[] = $e;
        }
        // Дедупликация (case-insensitive) и исключение тех, кто уже в To.
        $toLower = array_map('strtolower', $recipients);
        $seen = array_flip($toLower);
        $ccFinal = [];
        foreach ($ccList as $e) {
            $key = strtolower($e);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $ccFinal[] = $e;
        }
        if (count($ccFinal) > 10) $ccFinal = array_slice($ccFinal, 0, 10);

        $opts = ['account' => 'order', 'reply_to' => $orderEmail];
        if (!empty($ccFinal)) $opts['cc'] = $ccFinal;

        // Вложение от фронта (например, xlsx-заявка). Жёсткие лимиты по
        // размеру и расширению — чтобы не дать прицепить что попало.
        if ($hasAttachment) {
            $att = $body['attachment'];
            $fname = trim((string)($att['filename'] ?? 'order.xlsx'));
            $b64   = (string)($att['content_b64'] ?? '');
            $mime  = trim((string)($att['mime'] ?? '')) ?: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            // Безопасное имя файла.
            $fname = preg_replace('/[^\p{L}\p{N}\.\-_ ]+/u', '_', $fname);
            if (mb_strlen($fname) > 120) $fname = mb_substr($fname, 0, 120);
            // Размер декода — не больше 4 МБ.
            $decoded = base64_decode($b64, true);
            if ($decoded !== false && strlen($decoded) > 0 && strlen($decoded) <= 4 * 1024 * 1024) {
                $opts['attachments'] = [[
                    'filename'    => $fname,
                    'content_b64' => $b64,
                    'mime'        => $mime,
                ]];
            }
        }

        $sendResult = sendEmail($recipients, $subject, $html, true, $opts);

        $userId = $senderUserId;
        try {
            $pdo->prepare("INSERT INTO order_email_log (sender_user_id, sender_user_name, recipients, cc_recipients, subject, supplier, legal_entity, delivery_date, items_count, success, error_message, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([
                    $userId,
                    $authUserName,
                    implode(', ', $recipients),
                    !empty($ccFinal) ? implode(', ', $ccFinal) : null,
                    $subject,
                    $supplier !== '' ? $supplier : null,
                    $legalEntity !== '' ? $legalEntity : null,
                    $delivery !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivery) ? $delivery : null,
                    $itemsCount ?: null,
                    $sendResult['success'] ? 1 : 0,
                    $sendResult['success'] ? null : mb_substr((string)($sendResult['error'] ?? ''), 0, 500),
                    $clientIp ?? null,
                ]);
        } catch (Throwable $e) {
            error_log('[send_supplier_order_email] log insert failed: ' . $e->getMessage());
        }

        if (!$sendResult['success']) {
            respond(['error' => 'Не удалось отправить письмо: ' . ($sendResult['error'] ?? 'неизвестная ошибка')], 500);
        }
        respond(['success' => true, 'sent_to' => $recipients, 'cc' => $ccFinal]);
    }

    // ════════════════════════════════════════════════════════════════════
    // Отправка прогнозного плана поставщику по email с портала
    // (account=order, Reply-To=order@). По аналогии с send_supplier_order_email.
    // Тема: «План для <supplier> от <ЮЛ> на <P1>—<Pn>».
    // Тело — короткий текст со списком периодов; детали в Excel-вложении.
    // ════════════════════════════════════════════════════════════════════
    if ($fn === 'send_supplier_plan_email') {
        if (!$authUser) respond(['error' => 'Требуется авторизация'], 401);

        $rawTo        = trim((string)($body['to'] ?? ''));
        $bodyText     = (string)($body['body_text'] ?? '');
        $supplier     = trim((string)($body['supplier'] ?? ''));
        $legalEntity  = trim((string)($body['legal_entity'] ?? ''));
        $periodLabels = isset($body['period_labels']) && is_array($body['period_labels'])
            ? array_values(array_filter(array_map(static function ($p) { return trim((string)$p); }, $body['period_labels'])))
            : [];
        $itemsCount   = (int)($body['items_count'] ?? 0);

        if ($rawTo === '') respond(['error' => 'Не указан email получателя'], 400);

        $recipients = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $rawTo)), function ($e) {
            return $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL);
        }));
        if (!$recipients) respond(['error' => 'Не указан корректный email получателя'], 400);
        if (count($recipients) > 10) respond(['error' => 'Слишком много адресов (максимум 10)'], 400);

        // Полное имя поставщика и его cc_emails (по группе юрлиц).
        $supplierDisplay = $supplier;
        $supplierCcRaw   = '';
        if ($supplier !== '') {
            $senderGroup = getEntityGroup($legalEntity);
            try {
                $sStmt = $pdo->prepare("
                    SELECT full_name, cc_emails
                    FROM suppliers
                    WHERE legal_entity_group = ?
                      AND (short_name = ? OR full_name = ?)
                      AND is_active = 1
                    ORDER BY (legal_entity = ?) DESC, id
                    LIMIT 1
                ");
                $sStmt->execute([$senderGroup, $supplier, $supplier, $legalEntity]);
                $sRow = $sStmt->fetch();
                if ($sRow) {
                    $full = trim((string)($sRow['full_name'] ?? ''));
                    if ($full !== '') $supplierDisplay = $full;
                    $supplierCcRaw = (string)($sRow['cc_emails'] ?? '');
                }
            } catch (Throwable $e) {}
        }

        // Метка периодов: «P1—Pn» если ≥2, иначе одна строка.
        $periodLabelText = '';
        if (count($periodLabels) >= 2) {
            $periodLabelText = $periodLabels[0] . '—' . $periodLabels[count($periodLabels) - 1];
        } elseif (count($periodLabels) === 1) {
            $periodLabelText = $periodLabels[0];
        }

        $subjParts = ['План'];
        if ($supplierDisplay !== '') $subjParts[] = 'для ' . $supplierDisplay;
        if ($legalEntity !== '')     $subjParts[] = 'от ' . $legalEntity;
        if ($periodLabelText !== '') $subjParts[] = 'на ' . $periodLabelText;
        $subject = implode(' ', $subjParts);
        if (mb_strlen($subject) > 200) $subject = mb_substr($subject, 0, 200);

        require_once __DIR__ . '/mail_send.php';

        // HTML письма — минимализм, аналогично заявке. Без брендинга.
        $esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
        $greetingLine = 'Здравствуйте!';

        $intro = 'Направляем прогнозный план поставок';
        if ($supplierDisplay !== '') $intro .= ' для <strong>' . $esc($supplierDisplay) . '</strong>';
        if ($legalEntity !== '')     $intro .= ' от <strong>' . $esc($legalEntity) . '</strong>';
        $intro .= '.';

        $periodsHtml = '';
        if (!empty($periodLabels)) {
            $periodsHtml = '<div style="margin-top:10px;">Периоды: <strong>'
                . $esc(implode(', ', $periodLabels)) . '</strong>.</div>';
        }

        $hasAttachment = !empty($body['attachment']) && is_array($body['attachment']);
        $attachLine = $hasAttachment
            ? '<div style="margin-top:18px;color:#4b5563;">Детали — во вложении (Excel).</div>'
            : '';

        // Если фронт прислал текст — добавляем как «комментарий от отправителя».
        $extraText = '';
        if ($bodyText !== '') {
            $extraText = '<div style="white-space:pre-wrap;margin-top:18px;font-size:14px;color:#1f2937;">'
                       . nl2br($esc($bodyText)) . '</div>';
        }

        $html =
            '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
          . '<body style="margin:0;padding:0;background:#ffffff;color:#1f2937;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;font-size:14px;line-height:1.55;">'
          . '<div style="padding:24px 28px;max-width:760px;font-size:14px;">'
          . '<div style="margin-bottom:6px;">' . $greetingLine . '</div>'
          . '<div>' . $intro . '</div>'
          . $periodsHtml
          . $extraText
          . $attachLine
          . '<div style="margin-top:22px;color:#1f2937;">Спасибо!</div>'
          . '</div>'
          . '</body></html>';

        $orderEmail = $_ENV['SMTP_ORDER_USER'] ?? 'order@supply-department.online';
        $senderEmail = '';
        $senderUserId = null;
        try {
            $eStmt = $pdo->prepare("SELECT id, email FROM users WHERE name = ? LIMIT 1");
            $eStmt->execute([$authUserName]);
            $senderRow = $eStmt->fetch();
            if ($senderRow) {
                $senderEmail  = trim((string)($senderRow['email'] ?? ''));
                $senderUserId = $senderRow['id'] ?? null;
            }
        } catch (Throwable $e) {}

        $parseEmails = function ($raw) {
            if ($raw === null || $raw === '') return [];
            $list = is_array($raw) ? $raw : preg_split('/[,;\s]+/', (string)$raw);
            $out = [];
            foreach ($list as $item) {
                $e = trim((string)$item);
                if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = $e;
            }
            return $out;
        };

        if (array_key_exists('cc', $body)) {
            $ccList = $parseEmails($body['cc']);
        } else {
            $ccList = [];
            if ($senderEmail !== '' && filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
                $ccList[] = $senderEmail;
            }
            foreach ($parseEmails($supplierCcRaw) as $e) $ccList[] = $e;
        }
        $toLower = array_map('strtolower', $recipients);
        $seen = array_flip($toLower);
        $ccFinal = [];
        foreach ($ccList as $e) {
            $key = strtolower($e);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $ccFinal[] = $e;
        }
        if (count($ccFinal) > 10) $ccFinal = array_slice($ccFinal, 0, 10);

        $opts = ['account' => 'order', 'reply_to' => $orderEmail];
        if (!empty($ccFinal)) $opts['cc'] = $ccFinal;

        if ($hasAttachment) {
            $att = $body['attachment'];
            $fname = trim((string)($att['filename'] ?? 'plan.xlsx'));
            $b64   = (string)($att['content_b64'] ?? '');
            $mime  = trim((string)($att['mime'] ?? '')) ?: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            $fname = preg_replace('/[^\p{L}\p{N}\.\-_ ]+/u', '_', $fname);
            if (mb_strlen($fname) > 120) $fname = mb_substr($fname, 0, 120);
            $decoded = base64_decode($b64, true);
            if ($decoded !== false && strlen($decoded) > 0 && strlen($decoded) <= 4 * 1024 * 1024) {
                $opts['attachments'] = [[
                    'filename'    => $fname,
                    'content_b64' => $b64,
                    'mime'        => $mime,
                ]];
            }
        }

        $sendResult = sendEmail($recipients, $subject, $html, true, $opts);

        try {
            $pdo->prepare("INSERT INTO plan_email_log (sender_user_id, sender_user_name, recipients, cc_recipients, subject, supplier, legal_entity, period_labels, items_count, success, error_message, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([
                    $senderUserId,
                    $authUserName,
                    implode(', ', $recipients),
                    !empty($ccFinal) ? implode(', ', $ccFinal) : null,
                    $subject,
                    $supplier !== '' ? $supplier : null,
                    $legalEntity !== '' ? $legalEntity : null,
                    !empty($periodLabels) ? mb_substr(implode(', ', $periodLabels), 0, 500) : null,
                    $itemsCount ?: null,
                    $sendResult['success'] ? 1 : 0,
                    $sendResult['success'] ? null : mb_substr((string)($sendResult['error'] ?? ''), 0, 500),
                    $clientIp ?? null,
                ]);
        } catch (Throwable $e) {
            error_log('[send_supplier_plan_email] log insert failed: ' . $e->getMessage());
        }

        if (!$sendResult['success']) {
            respond(['error' => 'Не удалось отправить письмо: ' . ($sendResult['error'] ?? 'неизвестная ошибка')], 500);
        }
        respond(['success' => true, 'sent_to' => $recipients, 'cc' => $ccFinal]);
    }

    if ($fn === 'get_rbac_config') {
        respond([
            'modules' => array_keys($ROLE_TEMPLATES['admin']),
            'role_templates' => $ROLE_TEMPLATES,
            'access_levels' => $ACCESS_LEVELS,
        ]);
    }

    // Одноразовый download-токен для скачивания файла. Заменяет
    // session_token в ?token=. Живёт 15 минут, пишется в download_tokens,
    // принимается uploads-обработчиками через ?dl=. Принимает file_path
    // (относительно api/uploads/) для аудита; реальная авторизация на
    // конкретный файл всё равно делается uploads.php.
    if ($fn === 'create_download_token') {
        $filePath = trim((string)($body['file_path'] ?? ''));
        if ($filePath === '' || mb_strlen($filePath) > 512) respond(['error' => 'invalid file_path'], 400);
        if (strpos($filePath, '..') !== false || strpos($filePath, "\0") !== false) respond(['error' => 'invalid file_path'], 400);

        // Если staff-сессия есть — берём её.
        $issueAs = $authUserName ?: '';
        if (!$authUser) {
            // Ресторан тоже может получать токены, но только для путей uploads/bugs/*
            // (чтобы не дать ему доступ к чужим файлам через дыру в авторизации).
            if (strpos($filePath, 'uploads/bugs/') !== 0) {
                respond(['error' => 'Требуется авторизация'], 401);
            }
            if (!function_exists('roGetRestaurantSession')) {
                require_once __DIR__ . '/restaurant_orders.php';
            }
            $roSess = function_exists('roGetRestaurantSession') ? roGetRestaurantSession($pdo) : null;
            if (!$roSess) respond(['error' => 'Требуется авторизация'], 401);
            $issueAs = 'ro:' . ($roSess['restaurant_number'] ?? '');
        }

        // Ленивая чистка устаревших токенов: вместо отдельного cron-а удаляем
        // протухшие записи при каждом запросе. Дешёвая операция (индекс по expires_at).
        try { $pdo->prepare("DELETE FROM download_tokens WHERE expires_at < NOW() - INTERVAL 1 DAY")->execute(); } catch (Throwable $e) {}
        $token = bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO download_tokens (token, user_name, file_path, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL 15 MINUTE)")
            ->execute([$token, $issueAs, $filePath]);
        respond(['token' => $token, 'expires_in' => 15 * 60]);
    }

    // Сводка реализации ресторанов для трендов в заказе и планировании.
    // Возвращает агрегированные суммы, чтобы фронтенд не тянул тысячи строк restaurant_sales.
    if ($fn === 'get_restaurant_sales_summary') {
        $le = $body['legal_entity'] ?? '';
        $groups = $body['analog_groups'] ?? [];
        $periodDays = max(1, min((int)($body['period_days'] ?? 30), 365));
        if (!$le) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($authUser, $le)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        requireModuleAccess($authUser, 'restaurant-sales', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        if (!is_array($groups)) $groups = [];
        $groups = array_values(array_unique(array_filter(array_map(static function ($g) {
            $g = trim((string)$g);
            return $g !== '' ? mb_substr($g, 0, 255) : '';
        }, $groups))));
        if (!$groups) respond(['rows' => []]);
        $groups = array_slice($groups, 0, 300);

        $loadDays = max($periodDays, 28);
        $dateFrom = date('Y-m-d', strtotime("-{$loadDays} days"));
        $d14 = date('Y-m-d', strtotime('-14 days'));
        $d28 = date('Y-m-d', strtotime('-28 days'));
        $dPeriod = date('Y-m-d', strtotime("-{$periodDays} days"));
        $groupCode = getEntityGroup($le);
        $ph = implode(',', array_fill(0, count($groups), '?'));
        $sql = "
            SELECT
                analog_group,
                SUM(CASE WHEN sale_date >= ? THEN quantity ELSE 0 END) AS cur,
                SUM(CASE WHEN sale_date >= ? AND sale_date < ? THEN quantity ELSE 0 END) AS prev,
                SUM(CASE WHEN sale_date >= ? THEN quantity ELSE 0 END) AS total
            FROM restaurant_sales
            WHERE legal_entity_group = ?
              AND sale_date >= ?
              AND analog_group IN ($ph)
            GROUP BY analog_group
        ";
        $params = array_merge([$d14, $d28, $d14, $dPeriod, $groupCode, $dateFrom], $groups);
        $s = $pdo->prepare($sql);
        $s->execute($params);
        respond(['rows' => cleanNumeric($s->fetchAll())]);
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
        requireModuleAccess($authUser, 'stock-collection', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $le = $body['legal_entity'] ?? '';
        $name = mb_substr($body['name'] ?? '', 0, 255);
        $products = $body['products'] ?? []; // [{name, sku?, unit}]
        $uname = $authUserName ?: ($body['user_name'] ?? '');
        if (!$le || !$name || empty($products)) respond(['error' => 'Не все параметры указаны'], 400);
        if (!checkLegalEntityAccess($authUser, $le)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        if (count($products) > 5000) respond(['error' => 'Слишком много товаров (макс. 5000)'], 400);
        $hasNeedExpiry = dbColumnExists($pdo, 'stock_collection_products', 'need_expiry');
        $hasNote = dbColumnExists($pdo, 'stock_collection_products', 'note');
        $hasPrice = dbColumnExists($pdo, 'stock_collection_products', 'price');
        $pdo->beginTransaction();
        try {
            // legal_entity — кто создал (для аудита), legal_entity_group —
            // область видимости (BK+VM делят сборы, PS отдельно).
            $s = $pdo->prepare("INSERT INTO stock_collections (legal_entity, legal_entity_group, name, created_by) VALUES (?, ?, ?, ?)");
            $s->execute([$le, getEntityGroup($le), $name, $uname]);
            $collId = $pdo->lastInsertId();
            $productCols = ['collection_id', 'product_name', 'product_sku', 'unit'];
            if ($hasPrice) $productCols[] = 'price';
            if ($hasNeedExpiry) $productCols[] = 'need_expiry';
            $productCols[] = 'sort_order';
            if ($hasNote) $productCols[] = 'note';
            $productPlaceholders = implode(', ', array_fill(0, count($productCols), '?'));
            $ins = $pdo->prepare("INSERT INTO stock_collection_products (" . implode(', ', $productCols) . ") VALUES ({$productPlaceholders})");
            foreach ($products as $i => $p) {
                $pname = mb_substr($p['name'] ?? '', 0, 255);
                $psku = mb_substr($p['sku'] ?? '', 0, 50) ?: null;
                $punit = in_array($p['unit'] ?? '', ['boxes', 'pieces', 'kg', 'liters']) ? $p['unit'] : 'pieces';
                $pneedExpiry = !empty($p['need_expiry']) ? 1 : 0;
                $pnote = mb_substr($p['note'] ?? '', 0, 500) ?: null;
                $pprice = null;
                if ($hasPrice && isset($p['price']) && $p['price'] !== '' && $p['price'] !== null) {
                    $normalized = str_replace([',', ' '], ['.', ''], (string)$p['price']);
                    if (is_numeric($normalized)) $pprice = round((float)$normalized, 4);
                }
                $params = [$collId, $pname, $psku, $punit];
                if ($hasPrice) $params[] = $pprice;
                if ($hasNeedExpiry) $params[] = $pneedExpiry;
                $params[] = $i;
                if ($hasNote) $params[] = $pnote;
                $ins->execute($params);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('sc_create_collection error: ' . $e->getMessage());
            respond(['error' => 'Ошибка создания сбора'], 500);
        }
        // Уведомляем рестораны о новом сборе (только Telegram-бот, без публичной ссылки)
        scNotifyRestaurants($pdo, $collId, $name, count($products));

        auditLog($pdo, 'collection_created', 'stock_collection', $collId, $uname, ['legal_entity' => $le, 'name' => $name, 'products_count' => count($products)]);
        respond(['id' => $collId]);
    }

    // Повторная отправка уведомлений ресторанам о сборе
    if ($fn === 'sc_notify_restaurants') {
        requireModuleAccess($authUser, 'stock-collection', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $collId = intval($body['collection_id'] ?? 0);
        if (!$collId) respond(['error' => 'collection_id required'], 400);
        $col = $pdo->prepare("SELECT name, status FROM stock_collections WHERE id = ?");
        $col->execute([$collId]);
        $c = $col->fetch();
        if (!$c) respond(['error' => 'Не найден'], 404);
        if ($c['status'] !== 'active') respond(['error' => 'Сбор закрыт']);
        $products = $pdo->prepare("SELECT COUNT(*) FROM stock_collection_products WHERE collection_id = ?");
        $products->execute([$collId]);
        $cnt = $products->fetchColumn();
        $sent = scNotifyRestaurants($pdo, $collId, $c['name'], $cnt);
        respond(['success' => true, 'sent' => $sent]);
    }

    if ($fn === 'sc_save_prices') {
        requireModuleAccess($authUser, 'stock-collection', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $collId = intval($body['collection_id'] ?? 0);
        if (!$collId) respond(['error' => 'collection_id required'], 400);
        $prices = is_array($body['prices'] ?? null) ? $body['prices'] : [];

        // Проверка доступа к сбору по группе юрлиц.
        $collCheck = $pdo->prepare("SELECT legal_entity_group FROM stock_collections WHERE id = ?");
        $collCheck->execute([$collId]);
        $collRow = $collCheck->fetch();
        if (!$collRow) respond(['error' => 'Коллекция не найдена'], 404);
        if ($authUser && !checkLegalEntityGroupAccess($authUser, $collRow['legal_entity_group'])) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);

        $upd = $pdo->prepare("UPDATE stock_collection_products SET price = ? WHERE id = ? AND collection_id = ?");
        $updated = 0;
        foreach ($prices as $row) {
            $pid = intval($row['product_id'] ?? 0);
            if (!$pid) continue;
            // Пустая строка / null → стираем цену (NULL). Иначе принудительно DECIMAL.
            $raw = $row['price'] ?? null;
            if ($raw === '' || $raw === null) {
                $price = null;
            } else {
                // На фронте часто разделитель — запятая. Приводим к точке для float.
                $normalized = str_replace([',', ' '], ['.', ''], (string)$raw);
                if (!is_numeric($normalized)) continue;
                $price = round((float)$normalized, 4);
                if ($price < 0) continue;
            }
            $upd->execute([$price, $pid, $collId]);
            $updated += $upd->rowCount();
        }
        respond(['success' => true, 'updated' => $updated]);
    }

    if ($fn === 'sc_close_collection') {
        requireModuleAccess($authUser, 'stock-collection', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $collId = intval($body['collection_id'] ?? 0);
        if (!$collId) respond(['error' => 'Не все параметры указаны'], 400);
        // Доступ к сбору — на уровне группы юрлиц (BK_VM или PS).
        $collCheck = $pdo->prepare("SELECT legal_entity, legal_entity_group FROM stock_collections WHERE id = ?");
        $collCheck->execute([$collId]);
        $collRow = $collCheck->fetch();
        if (!$collRow) respond(['error' => 'Коллекция не найдена'], 404);
        if ($authUser && !checkLegalEntityGroupAccess($authUser, $collRow['legal_entity_group'])) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $pdo->prepare("UPDATE stock_collections SET status = 'closed', closed_at = NOW() WHERE id = ?")->execute([$collId]);
        auditLog($pdo, 'collection_closed', 'stock_collection', $collId, $authUserName, ['legal_entity' => $collRow['legal_entity']]);
        respond(['success' => true]);
    }
    if ($fn === 'sc_reopen_collection') {
        requireModuleAccess($authUser, 'stock-collection', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $collId = intval($body['collection_id'] ?? 0);
        $uname = $authUserName ?: ($body['user_name'] ?? '');
        if (!$collId) respond(['error' => 'Не все параметры указаны'], 400);
        $collCheck = $pdo->prepare("SELECT id, name, legal_entity, legal_entity_group, status FROM stock_collections WHERE id = ?");
        $collCheck->execute([$collId]);
        $collRow = $collCheck->fetch();
        if (!$collRow) respond(['error' => 'Коллекция не найдена'], 404);
        if ($authUser && !checkLegalEntityGroupAccess($authUser, $collRow['legal_entity_group'])) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        if ($collRow['status'] === 'active') respond(['success' => true, 'already_active' => true]);

        $pdo->prepare("UPDATE stock_collections SET status = 'active', closed_at = NULL WHERE id = ?")->execute([$collId]);
        auditLog($pdo, 'collection_reopened', 'stock_collection', $collId, $uname, ['legal_entity' => $collRow['legal_entity']]);
        respond(['success' => true]);
    }
    if ($fn === 'sc_delete_collection') {
        requireModuleAccess($authUser, 'stock-collection', 'full', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $collId = intval($body['collection_id'] ?? 0);
        if (!$collId) respond(['error' => 'Не все параметры указаны'], 400);
        $collCheck = $pdo->prepare("SELECT id, name, legal_entity, legal_entity_group FROM stock_collections WHERE id = ?");
        $collCheck->execute([$collId]);
        $collRow = $collCheck->fetch();
        if (!$collRow) respond(['error' => 'Коллекция не найдена'], 404);
        if ($authUser && !checkLegalEntityGroupAccess($authUser, $collRow['legal_entity_group'])) respond(['error' => 'Нет доступа'], 403);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM stock_collection_data WHERE collection_id = ?")->execute([$collId]);
            $pdo->prepare("DELETE FROM stock_collection_products WHERE collection_id = ?")->execute([$collId]);
            $pdo->prepare("DELETE FROM stock_collections WHERE id = ?")->execute([$collId]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(['error' => 'Ошибка удаления'], 500);
        }
        auditLog($pdo, 'collection_deleted', 'stock_collection', $collId, $authUserName, ['name' => $collRow['name']]);
        respond(['success' => true]);
    }

    if ($fn === 'sc_get_collection_data') {
        requireModuleAccess($authUser, 'stock-collection', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $collId = intval($body['collection_id'] ?? 0);
        if (!$collId) respond(['error' => 'Не все параметры указаны'], 400);
        // Доступ к сбору — на уровне группы юрлиц (BK+VM делят сборы, PS отдельно).
        $collCheck = $pdo->prepare("SELECT legal_entity_group FROM stock_collections WHERE id = ?");
        $collCheck->execute([$collId]);
        $collRow = $collCheck->fetch();
        if (!$collRow) respond(['error' => 'Коллекция не найдена'], 404);
        if ($authUser && !checkLegalEntityGroupAccess($authUser, $collRow['legal_entity_group'])) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        // Товары
        $hasNeedExpiry = dbColumnExists($pdo, 'stock_collection_products', 'need_expiry');
        $hasNote = dbColumnExists($pdo, 'stock_collection_products', 'note');
        $hasPrice = dbColumnExists($pdo, 'stock_collection_products', 'price');
        $productCols = ['id', 'product_name', 'product_sku', 'unit'];
        if ($hasPrice) $productCols[] = 'price';
        if ($hasNeedExpiry) $productCols[] = 'need_expiry';
        $productCols[] = 'sort_order';
        if ($hasNote) $productCols[] = 'note';
        $s = $pdo->prepare("SELECT " . implode(', ', $productCols) . " FROM stock_collection_products WHERE collection_id = ? ORDER BY sort_order");
        $s->execute([$collId]);
        $products = $s->fetchAll();
        // Данные
        $hasExpiryDate = dbColumnExists($pdo, 'stock_collection_data', 'expiry_date');
        $dataCols = ['id', 'product_id', 'restaurant_number'];
        if ($hasExpiryDate) $dataCols[] = 'expiry_date';
        $dataCols[] = 'stock';
        $dataCols[] = 'source';
        $dataCols[] = 'submitted_at';
        $orderBy = $hasExpiryDate
            ? 'ORDER BY restaurant_number, product_id, expiry_date, id'
            : 'ORDER BY restaurant_number, product_id, id';
        $s2 = $pdo->prepare("SELECT " . implode(', ', $dataCols) . " FROM stock_collection_data WHERE collection_id = ? {$orderBy}");
        $s2->execute([$collId]);
        $data = $s2->fetchAll();
        if (!$hasExpiryDate) {
            foreach ($data as &$row) {
                $row['expiry_date'] = null;
            }
            unset($row);
        }
        if (!$hasNeedExpiry) {
            foreach ($products as &$row) {
                $row['need_expiry'] = 0;
            }
            unset($row);
        }
        // Ответы по ресторанам
        $s3 = $pdo->prepare("SELECT DISTINCT restaurant_number FROM stock_collection_data WHERE collection_id = ?");
        $s3->execute([$collId]);
        $restaurants = array_column($s3->fetchAll(), 'restaurant_number');
        respond(['products' => $products, 'data' => $data, 'restaurants' => $restaurants]);
    }

    if ($fn === 'sc_save_collection_cell') {
        requireModuleAccess($authUser, 'stock-collection', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $collId = intval($body['collection_id'] ?? 0);
        $productId = intval($body['product_id'] ?? 0);
        $restaurantNumber = trim((string)($body['restaurant_number'] ?? ''));
        $batches = $body['batches'] ?? null;
        if ($batches === null && array_key_exists('stock', $body)) {
            $batches = [['stock' => $body['stock'], 'expiry_date' => $body['expiry_date'] ?? null]];
        }
        if (!$collId || !$productId || $restaurantNumber === '' || $batches === null) {
            respond(['error' => 'Не все параметры указаны'], 400);
        }

        $collCheck = $pdo->prepare("SELECT id, legal_entity, legal_entity_group, status FROM stock_collections WHERE id = ?");
        $collCheck->execute([$collId]);
        $collRow = $collCheck->fetch();
        if (!$collRow) respond(['error' => 'Сбор не найден'], 404);
        if ($collRow['status'] !== 'active') respond(['error' => 'Сбор закрыт'], 400);
        if (!checkLegalEntityGroupAccess($authUser, $collRow['legal_entity_group'])) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);

        $productCheck = $pdo->prepare("SELECT id, need_expiry FROM stock_collection_products WHERE id = ? AND collection_id = ?");
        $productCheck->execute([$productId, $collId]);
        $productRow = $productCheck->fetch();
        if (!$productRow) respond(['error' => 'Товар не входит в этот сбор'], 400);

        $group = $collRow['legal_entity_group'];
        $restaurantCheck = $pdo->prepare("SELECT id FROM restaurants WHERE number = ? AND legal_entity_group = ? LIMIT 1");
        $restaurantCheck->execute([$restaurantNumber, $group]);
        if (!$restaurantCheck->fetch()) respond(['error' => 'Ресторан не найден в выбранном юрлице'], 400);

        try {
            $hasExpiryDate = dbColumnExists($pdo, 'stock_collection_data', 'expiry_date');
            $normalized = rpcNormalizeStockCollectionBatches($batches, $hasExpiryDate);
            // Если ничего не введено — считаем, что остатков нет (0 без срока)
            if (!$normalized) {
                $normalized = [['expiry_date' => null, 'stock' => 0.0]];
            }
            if (!empty($productRow['need_expiry'])) {
                foreach ($normalized as $batch) {
                    // Срок обязателен только если остаток > 0
                    if (empty($batch['expiry_date']) && (float)$batch['stock'] > 0) {
                        respond(['error' => 'Для этой позиции нужно указать срок годности (или поставьте остаток 0)'], 400);
                    }
                }
            }
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM stock_collection_data WHERE collection_id = ? AND product_id = ? AND restaurant_number = ?")->execute([$collId, $productId, $restaurantNumber]);
            if ($hasExpiryDate) {
                $stmt = $pdo->prepare("
                    INSERT INTO stock_collection_data (collection_id, product_id, restaurant_number, expiry_date, stock, source, submitted_at)
                    VALUES (?, ?, ?, ?, ?, 'manual', NOW())
                ");
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO stock_collection_data (collection_id, product_id, restaurant_number, stock, source, submitted_at)
                    VALUES (?, ?, ?, ?, 'manual', NOW())
                ");
            }
            $savedIds = [];
            foreach ($normalized as $batch) {
                if ($hasExpiryDate) {
                    $stmt->execute([$collId, $productId, $restaurantNumber, $batch['expiry_date'], $batch['stock']]);
                } else {
                    $stmt->execute([$collId, $productId, $restaurantNumber, $batch['stock']]);
                }
                $savedIds[] = (int)$pdo->lastInsertId();
            }
            $pdo->commit();
            if ($hasExpiryDate) {
                $idStmt = $pdo->prepare("SELECT id, product_id, restaurant_number, expiry_date, stock, source, submitted_at FROM stock_collection_data WHERE collection_id = ? AND product_id = ? AND restaurant_number = ? ORDER BY expiry_date, id");
            } else {
                $idStmt = $pdo->prepare("SELECT id, product_id, restaurant_number, stock, source, submitted_at FROM stock_collection_data WHERE collection_id = ? AND product_id = ? AND restaurant_number = ? ORDER BY id");
            }
            $idStmt->execute([$collId, $productId, $restaurantNumber]);
            $item = $idStmt->fetchAll();
            if (!$hasExpiryDate) {
                foreach ($item as &$row) {
                    $row['expiry_date'] = null;
                }
                unset($row);
            }
            auditLog($pdo, 'stock_collection_cell_saved', 'stock_collection', $collId, $authUserName, [
                'product_id' => $productId,
                'restaurant_number' => $restaurantNumber,
                'batches' => $normalized,
                'source' => 'manual',
            ]);
            respond(['success' => true, 'items' => $item]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('sc_save_collection_cell error: ' . $e->getMessage());
            respond(['error' => 'Ошибка сохранения'], 500);
        }
    }

    // ═══ График возврата кег ═══

    if ($fn === 'kr_get_schedule') {
        requireModuleAccess($authUser, 'restaurant-orders', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        // Глобальный флаг по юрлицу (по умолчанию для группы BK_VM используем
        // запись «ООО Бургер БК», т.к. ВМ и БК делят одну настройку модуля).
        $legalEntity = 'ООО "Бургер БК"';
        $globalEnabled = true;
        try {
            $s = $pdo->prepare("SELECT keg_returns_enabled FROM ro_module_settings WHERE legal_entity = ? LIMIT 1");
            $s->execute([$legalEntity]);
            $row = $s->fetch();
            if ($row !== false) $globalEnabled = (int)$row['keg_returns_enabled'] === 1;
        } catch (Throwable $e) { /* колонки ещё нет */ }

        $rows = $pdo->query("
            SELECT id, number, region, city, address,
                   pickup_address, pickup_weekdays,
                   COALESCE(keg_returns_enabled, 1) AS keg_returns_enabled
            FROM restaurants
            WHERE active = 1 AND legal_entity_group = 'BK_VM'
            ORDER BY number
        ")->fetchAll();
        respond([
            'global_enabled' => $globalEnabled,
            'restaurants'    => $rows,
        ]);
    }

    if ($fn === 'kr_save_schedule_row') {
        requireModuleAccess($authUser, 'restaurant-orders', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) respond(['error' => 'Не указан ресторан'], 400);
        $pickupAddress = isset($body['pickup_address']) ? trim((string)$body['pickup_address']) : null;
        $pickupWeekdays = isset($body['pickup_weekdays']) ? max(0, min(127, (int)$body['pickup_weekdays'])) : null;
        $kegEnabled = isset($body['keg_returns_enabled']) ? ((int)!!$body['keg_returns_enabled']) : null;

        $sets = [];
        $vals = [];
        if ($pickupAddress !== null) { $sets[] = 'pickup_address = ?';   $vals[] = $pickupAddress; }
        if ($pickupWeekdays !== null) { $sets[] = 'pickup_weekdays = ?'; $vals[] = $pickupWeekdays; }
        if ($kegEnabled !== null)     { $sets[] = 'keg_returns_enabled = ?'; $vals[] = $kegEnabled; }
        if (!$sets) respond(['error' => 'Нечего сохранять'], 400);

        $vals[] = $id;
        $sql = "UPDATE restaurants SET " . implode(', ', $sets) . " WHERE id = ? AND active = 1";
        try {
            $pdo->prepare($sql)->execute($vals);
        } catch (Throwable $e) {
            error_log('kr_save_schedule_row error: ' . $e->getMessage());
            respond(['error' => 'Не удалось сохранить'], 500);
        }
        respond(['success' => true]);
    }

    if ($fn === 'kr_set_module_enabled') {
        requireModuleAccess($authUser, 'restaurant-orders', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $enabled = !empty($body['enabled']) ? 1 : 0;
        $legalEntity = 'ООО "Бургер БК"';
        try {
            $pdo->prepare("
                INSERT INTO ro_module_settings (legal_entity, legal_entity_group, restaurant_orders_enabled, keg_returns_enabled, updated_by)
                VALUES (?, 'BK_VM', 1, ?, ?)
                ON DUPLICATE KEY UPDATE keg_returns_enabled = VALUES(keg_returns_enabled), updated_by = VALUES(updated_by)
            ")->execute([$legalEntity, $enabled, $authUserName ?? 'system']);
        } catch (Throwable $e) {
            error_log('kr_set_module_enabled error: ' . $e->getMessage());
            respond(['error' => 'Не удалось сохранить'], 500);
        }
        respond(['success' => true, 'enabled' => (bool)$enabled]);
    }

    // ═══ Пользовательские UI-настройки (preferences) ═══
    // Хранятся как JSON в users.preferences. Используются для синхронизации
    // избранного в сайдбаре и других UI-предпочтений между устройствами.

    if ($fn === 'get_user_preferences') {
        if (!$authUserName) respond(['error' => 'Нет авторизации'], 401);
        try {
            $s = $pdo->prepare("SELECT preferences FROM users WHERE name = ? LIMIT 1");
            $s->execute([$authUserName]);
            $raw = $s->fetchColumn();
        } catch (Throwable $e) {
            // Если колонки ещё нет — миграция не применена.
            respond(['preferences' => new \stdClass()]);
        }
        $prefs = null;
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $prefs = $decoded;
        }
        if (!$prefs) $prefs = new \stdClass();
        respond(['preferences' => $prefs]);
    }

    if ($fn === 'set_user_preference') {
        if (!$authUserName) respond(['error' => 'Нет авторизации'], 401);
        $key = trim((string)($body['key'] ?? ''));
        if ($key === '') respond(['error' => 'Не указан ключ настройки'], 400);
        if (!preg_match('/^[a-zA-Z0-9_]{1,50}$/', $key)) {
            respond(['error' => 'Некорректный ключ настройки'], 400);
        }
        $value = $body['value'] ?? null;
        try {
            $s = $pdo->prepare("SELECT preferences FROM users WHERE name = ? LIMIT 1");
            $s->execute([$authUserName]);
            $raw = $s->fetchColumn();
            $prefs = $raw ? json_decode($raw, true) : null;
            if (!is_array($prefs)) $prefs = [];
            if ($value === null) {
                unset($prefs[$key]);
            } else {
                $prefs[$key] = $value;
            }
            $json = json_encode($prefs, JSON_UNESCAPED_UNICODE);
            // Жёсткое ограничение на размер JSON (16 КБ — с большим запасом).
            if (strlen($json) > 16 * 1024) {
                respond(['error' => 'Слишком большой объём настроек'], 413);
            }
            $pdo->prepare("UPDATE users SET preferences = ? WHERE name = ?")
                ->execute([$json, $authUserName]);
        } catch (Throwable $e) {
            error_log('set_user_preference error: ' . $e->getMessage());
            respond(['error' => 'Не удалось сохранить настройки'], 500);
        }
        respond(['success' => true]);
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
        auditLog($pdo, 'password_changed', 'user', $name, $name);
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
        if (!in_array($role, ['admin', 'manager', 'user', 'viewer'])) $role = 'user';
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $permJson = ($permissions && is_array($permissions) && count($permissions) > 0) ? json_encode($permissions, JSON_UNESCAPED_UNICODE) : null;
        $id = uuid();
        try {
            $pdo->prepare("INSERT INTO users (id, name, email, password, role, display_role, legal_entities, permissions, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                ->execute([$id, $name, $email ?: null, $hash, $role, $displayRole, is_array($legalEntities) ? json_encode($legalEntities, JSON_UNESCAPED_UNICODE) : $legalEntities, $permJson]);
        } catch (PDOException $e) {
            respond(['success' => false, 'error' => 'Пользователь уже существует или ошибка базы данных'], 400);
        }
        auditLog($pdo, 'user_created', 'user', $name, $caller['name'], ['role' => $role, 'display_role' => $displayRole]);
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
        if (isset($body['role']) && in_array($body['role'], ['admin', 'manager', 'user', 'viewer'])) { $sets[] = "role=?"; $params[] = $body['role']; }
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
        $changedFields = [];
        if (isset($body['role'])) $changedFields['role'] = $body['role'];
        if (array_key_exists('permissions', $body)) $changedFields['permissions'] = $body['permissions'];
        if (array_key_exists('legal_entities', $body)) $changedFields['legal_entities'] = $body['legal_entities'];
        if (array_key_exists('display_role', $body)) $changedFields['display_role'] = $body['display_role'];
        if ($passwordChanged) $changedFields['password'] = 'changed';
        auditLog($pdo, 'user_updated', 'user', $userId, $caller['name'], null, $changedFields);
        respond(['success' => true]);
    }
    if ($fn === 'delete_user') {
        $caller = getSessionUser($pdo);
        if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
        $callerName = $caller['name'];
        $userId = $body['user_id'] ?? '';
        if (!$userId) respond(['success' => false, 'error' => 'Не указан ID пользователя'], 400);
        // Не позволять удалить себя
        $s2 = $pdo->prepare("SELECT name, role FROM users WHERE id=?"); $s2->execute([$userId]); $target = $s2->fetch();
        if ($target && $target['name'] === $callerName) respond(['success' => false, 'error' => 'Нельзя удалить самого себя'], 400);
        // Защита от удаления последнего администратора: после удаления должен остаться хотя бы один admin
        if ($target && $target['role'] === 'admin') {
            $admCnt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if ((int)$admCnt <= 1) respond(['success' => false, 'error' => 'Нельзя удалить последнего администратора'], 400);
        }
        // Связанные DELETE — в одной транзакции, чтобы при сбое не остаться
        // с висящими сессиями уже удалённого пользователя.
        $pdo->beginTransaction();
        try {
            if ($target) {
                $pdo->prepare("DELETE FROM user_sessions WHERE user_name=?")->execute([$target['name']]);
                $pdo->prepare("DELETE FROM user_presence WHERE user_name=?")->execute([$target['name']]);
            }
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$userId]);
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('delete_user error: ' . $e->getMessage());
            respond(['success' => false, 'error' => 'Ошибка удаления'], 500);
        }
        auditLog($pdo, 'user_deleted', 'user', $target ? $target['name'] : $userId, $caller['name']);
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
        requireAdmin($authUser);
        $s = $pdo->query("SELECT user_name, page, last_seen FROM user_presence WHERE last_seen > NOW() - INTERVAL 2 MINUTE ORDER BY last_seen DESC");
        respond($s->fetchAll());
    }
    if ($fn === 'get_online_restaurants') {
        // Список ресторанов «онлайн»: heartbeat-таймер кабинета шлёт ro/heartbeat
        // каждые 15с и кладёт в ro_users.last_seen_at = NOW() и last_page = текущая
        // страница. Считаем онлайном тех, у кого last_seen_at за последние 15 минут.
        requireAdmin($authUser);
        $s = $pdo->query("
            SELECT ru.restaurant_number,
                   ru.legal_entity,
                   ru.legal_entity_group,
                   ru.last_page,
                   r.city,
                   r.address,
                   ru.last_seen_at AS last_activity
            FROM ro_users ru
            LEFT JOIN restaurants r
              ON r.number = ru.restaurant_number
             AND r.legal_entity = ru.legal_entity COLLATE utf8mb4_general_ci
            WHERE ru.is_active = 1
              AND ru.last_seen_at IS NOT NULL
              AND ru.last_seen_at > NOW() - INTERVAL 15 MINUTE
            ORDER BY ru.last_seen_at DESC
        ");
        respond($s->fetchAll());
    }
    if ($fn === 'send_broadcast') {
        $sessionUser = getSessionUser($pdo);
        if (!$sessionUser || $sessionUser['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
        $userName = $sessionUser['name'];
        $title = $body['title'] ?? 'Важное сообщение';
        $message = $body['message'] ?? '';
        $toStaffCabinet = !empty($body['to_staff_cabinet']);
        $toRestaurantCabinet = !empty($body['to_restaurants_cabinet']);
        $toStaffTelegram = !empty($body['to_staff_telegram']);
        $toRestaurantTelegram = !empty($body['to_restaurants_telegram']);
        if (!$message) respond(['success' => false, 'error' => 'Не все параметры указаны'], 400);
        if (!$toStaffCabinet && !$toRestaurantCabinet && !$toStaffTelegram && !$toRestaurantTelegram) {
            respond(['success' => false, 'error' => 'Не выбраны получатели'], 400);
        }

        $title = mb_substr($title, 0, 255);
        $message = mb_substr($message, 0, 2000);
        $broadcastGroup = uniqid('bc_', true);

        if ($toStaffCabinet) {
            $pdo->prepare("INSERT INTO notifications (type, title, message, created_by, broadcast_group, read_by, deleted_by, created_at) VALUES ('broadcast', ?, ?, ?, ?, '[]', '[]', NOW())")
                ->execute([$title, $message, $userName, $broadcastGroup]);
        }
        if ($toRestaurantCabinet) {
            $pdo->prepare("INSERT INTO notifications (type, title, message, created_by, broadcast_group, read_by, deleted_by, created_at) VALUES ('ro_broadcast', ?, ?, ?, ?, '[]', '[]', NOW())")
                ->execute([$title, $message, $userName, $broadcastGroup]);
        }

        $staffTelegramSent = 0;
        $restaurantTelegramSent = 0;
        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if ($botToken) {
            $tgText = "📢 <b>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</b>\n\n" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "\n\n— " . htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
            if ($toStaffTelegram) {
                $s = $pdo->query("SELECT telegram_chat_id FROM users WHERE telegram_chat_id IS NOT NULL AND telegram_chat_id != ''");
                $chatIds = array_values(array_unique(array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN))));
                $staffTelegramSent = sendTelegramBulk($botToken, $chatIds, $tgText);
            }
            if ($toRestaurantTelegram) {
                $s = $pdo->query("
                    SELECT telegram_chat_id AS chat_id
                    FROM ro_users
                    WHERE telegram_chat_id IS NOT NULL AND telegram_chat_id != ''
                    UNION
                    SELECT chat_id
                    FROM ro_telegram_subs
                    WHERE chat_id IS NOT NULL AND chat_id != ''
                ");
                $chatIds = array_values(array_unique(array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN))));
                $restaurantTelegramSent = sendTelegramBulk($botToken, $chatIds, $tgText);
            }
        }

        try {
            $pdo->prepare("
                INSERT INTO admin_broadcast_log
                    (broadcast_group, sender, title, message, target_staff_cabinet, target_restaurant_cabinet, target_staff_telegram, target_restaurant_telegram, staff_telegram_sent, restaurant_telegram_sent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $broadcastGroup,
                $userName,
                $title,
                $message,
                $toStaffCabinet ? 1 : 0,
                $toRestaurantCabinet ? 1 : 0,
                $toStaffTelegram ? 1 : 0,
                $toRestaurantTelegram ? 1 : 0,
                $staffTelegramSent,
                $restaurantTelegramSent,
            ]);
        } catch (Exception $e) {
            error_log('send_broadcast log error: ' . $e->getMessage());
        }

        auditLog($pdo, 'broadcast_sent', 'system', $broadcastGroup, $sessionUser['name'], [
            'title' => $title,
            'to_staff_cabinet' => $toStaffCabinet,
            'to_restaurants_cabinet' => $toRestaurantCabinet,
            'to_staff_telegram' => $toStaffTelegram,
            'to_restaurants_telegram' => $toRestaurantTelegram,
            'staff_telegram_sent' => $staffTelegramSent,
            'restaurant_telegram_sent' => $restaurantTelegramSent,
        ]);
        respond([
            'success' => true,
            'broadcast_group' => $broadcastGroup,
            'staff_telegram_sent' => $staffTelegramSent,
            'restaurant_telegram_sent' => $restaurantTelegramSent,
        ]);
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
        $pdo->prepare("UPDATE notifications SET deleted_by = JSON_ARRAY_APPEND(COALESCE(deleted_by, '[]'), '$', ?) WHERE (target_user IS NULL OR target_user = '' OR target_user = ? OR type = 'broadcast') AND NOT JSON_CONTAINS(COALESCE(deleted_by, '[]'), JSON_QUOTE(?))")->execute([$userName, $userName, $userName]);
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
        $broadcastGroup = trim((string)($body['broadcast_group'] ?? ''));
        $id = $body['id'] ?? null;
        if ($broadcastGroup !== '') {
            $pdo->prepare("DELETE FROM notifications WHERE broadcast_group = ? AND type IN ('broadcast', 'ro_broadcast')")->execute([$broadcastGroup]);
            try {
                $pdo->prepare("DELETE FROM admin_broadcast_log WHERE broadcast_group = ?")->execute([$broadcastGroup]);
            } catch (Exception $e) {
                error_log('delete_broadcast log cleanup error: ' . $e->getMessage());
            }
            respond(['success' => true]);
        }
        if (!$id) respond(['success' => false, 'error' => 'Не указан ID'], 400);
        $pdo->prepare("DELETE FROM notifications WHERE id = ? AND type = 'broadcast'")->execute([$id]);
        respond(['success' => true]);
    }
    if ($fn === 'get_broadcast_history') {
        $sessionUser = getSessionUser($pdo);
        if (!$sessionUser || $sessionUser['role'] !== 'admin') respond(['success' => false, 'error' => 'Нет прав доступа'], 403);
        $limit = max(1, min((int)($body['limit'] ?? 20), 100));

        $rows = [];
        try {
            $s = $pdo->prepare("
                SELECT
                    id,
                    broadcast_group,
                    sender,
                    title,
                    message,
                    target_staff_cabinet,
                    target_restaurant_cabinet,
                    target_staff_telegram,
                    target_restaurant_telegram,
                    staff_telegram_sent,
                    restaurant_telegram_sent,
                    created_at,
                    0 AS is_legacy
                FROM admin_broadcast_log
                ORDER BY created_at DESC
                LIMIT {$limit}
            ");
            $s->execute();
            $rows = $s->fetchAll();
        } catch (Exception $e) {
            error_log('get_broadcast_history admin_broadcast_log error: ' . $e->getMessage());
        }

        try {
            $legacyLimit = max($limit - count($rows), 0);
            if ($legacyLimit > 0) {
                $s = $pdo->prepare("
                    SELECT
                        id,
                        CONCAT('legacy_', id) AS broadcast_group,
                        created_by AS sender,
                        title,
                        message,
                        1 AS target_staff_cabinet,
                        0 AS target_restaurant_cabinet,
                        0 AS target_staff_telegram,
                        0 AS target_restaurant_telegram,
                        0 AS staff_telegram_sent,
                        0 AS restaurant_telegram_sent,
                        created_at,
                        1 AS is_legacy
                    FROM notifications
                    WHERE type = 'broadcast'
                      AND (broadcast_group IS NULL OR broadcast_group = '')
                    ORDER BY created_at DESC
                    LIMIT {$legacyLimit}
                ");
                $s->execute();
                $rows = array_merge($rows, $s->fetchAll());
            }
        } catch (Exception $e) {
            error_log('get_broadcast_history legacy error: ' . $e->getMessage());
        }

        usort($rows, static function ($a, $b) {
            return strcmp((string)$b['created_at'], (string)$a['created_at']);
        });
        if (count($rows) > $limit) $rows = array_slice($rows, 0, $limit);
        respond($rows);
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
        // Защита от случайной очистки: если все строки после whitelist-фильтра
        // оказались пустыми, DELETE сработает, а INSERT нет — и данные юрлица
        // потеряются. Проверяем заранее.
        $validItems = [];
        foreach ($items as $item) {
            $f = array_intersect_key($item, array_flip($allowed));
            if (!empty($f)) $validItems[] = $f;
        }
        if (empty($validItems)) respond(['error' => 'В позициях нет валидных полей'], 400);
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM `analysis_data` WHERE `legal_entity`=?")->execute([$legalEntity]);
            // Готовим один statement для всех записей
            $cols = array_keys($validItems[0]);
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $cn = implode(',', array_map(fn($c) => "`$c`", $cols));
            $stmt = $pdo->prepare("INSERT INTO `analysis_data` ($cn) VALUES ($ph)");
            foreach ($validItems as $item) {
                // Если в строке другой набор колонок — пропускаем, чтобы не ловить SQL-ошибку.
                if (array_keys($item) !== $cols) continue;
                $stmt->execute(array_values($item));
            }
            $pdo->commit();
            auditLog($pdo, 'data_imported', 'import', null, $caller['name'], ['type' => 'analysis_data', 'legal_entity' => $legalEntity, 'count' => count($validItems)]);
            notifyTelegramDataUpdate($pdo, 'analysis', $caller['name'], $legalEntity, count($validItems));
            respond(['success' => true, 'count' => count($validItems)]);
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
        if (($ACCESS_LEVELS[$perms['restaurant-sales'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
            respond(['error' => 'Недостаточно прав'], 403);
        }
        $items = $body['items'] ?? [];
        $legalEntity = $body['legal_entity'] ?? null;
        if (!is_array($items)) respond(['error' => 'Позиции должны быть массивом'], 400);
        if (empty($items)) respond(['error' => 'Список позиций пуст'], 400);
        if (count($items) > 500000) respond(['error' => 'Слишком много записей (макс. 500 000)'], 400);
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        // Проверяем, что у пользователя есть доступ к этому юрлицу
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к юр. лицу'], 403);
        // Реализация хранится на уровне группы (BK_VM/PS), а не конкретного юрлица —
        // для БК+ВМ это одна и та же выгрузка из 1С.
        $group = getEntityGroup($legalEntity);
        try {
            $pdo->beginTransaction();
            // Upsert: обновляем если уже есть запись за эту дату, товарную группу и группу юрлиц
            $stmt = $pdo->prepare("INSERT INTO `restaurant_sales` (`sale_date`, `legal_entity_group`, `analog_group`, `quantity`, `restaurant_count`)
                VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE `quantity`=VALUES(`quantity`), `restaurant_count`=VALUES(`restaurant_count`)");
            $inserted = 0;
            foreach ($items as $item) {
                $date = $item['sale_date'] ?? null;
                $ag = $item['analog_group'] ?? null;
                $qty = $item['quantity'] ?? 0;
                $rc = $item['restaurant_count'] ?? 0;
                if (!$date || !$ag) continue;
                $stmt->execute([$date, $group, $ag, $qty, $rc]);
                $inserted++;
            }
            $pdo->commit();
            auditLog($pdo, 'data_imported', 'import', null, $caller['name'], ['type' => 'restaurant_sales', 'count' => $inserted, 'legal_entity_group' => $group]);
            // TODO: уведомление в Telegram временно отключено
            // if (!empty($body['notify'])) {
            //     notifyTelegramRestaurantSales($pdo, $caller['name'], $items, $inserted);
            // }
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
        // Определяем юрлица в загружаемых данных и проверяем доступ
        $uploadedEntities = array_unique(array_filter(array_column($items, 'customer')));
        if ($caller['role'] !== 'admin') {
            foreach ($uploadedEntities as $ue) {
                if (!checkLegalEntityAccess($caller, $ue)) {
                    respond(['error' => "Нет доступа к юр. лицу: $ue"], 403);
                }
            }
        }
        try {
            $pdo->beginTransaction();
            // Удаляем только данные юрлиц, которые есть в загрузке (не трогаем чужие)
            if (!empty($uploadedEntities)) {
                $ph = implode(',', array_fill(0, count($uploadedEntities), '?'));
                $pdo->prepare("DELETE FROM `stock_malling` WHERE `customer` IN($ph)")->execute(array_values($uploadedEntities));
            }
            // Готовим один statement для всех записей
            $firstItem = array_intersect_key($items[0], array_flip($allowed));
            $smCols = array_keys($firstItem);
            $smPh = implode(',', array_fill(0, count($smCols), '?'));
            $smCn = implode(',', array_map(fn($c) => "`$c`", $smCols));
            $smStmt = $pdo->prepare("INSERT INTO `stock_malling` ($smCn) VALUES ($smPh)");
            foreach ($items as $item) {
                $item = array_intersect_key($item, array_flip($allowed));
                if (empty($item)) continue;
                $smStmt->execute(array_values($item));
            }
            $pdo->commit();
            auditLog($pdo, 'data_imported', 'import', null, $caller['name'], ['type' => 'stock_malling', 'count' => count($items)]);
            notifyTelegramDataUpdate($pdo, 'shelf_life', $caller['name'], '', count($items));
            notifyTelegramExpiringItems($pdo, $caller['name']);
            respond(['success' => true, 'count' => count($items)]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("replace_stock_malling error: " . $e->getMessage());
            respond(['error' => 'Ошибка сохранения данных'], 500);
        }
    }

    if ($fn === 'save_warehouse_cells') {
        requireModuleAccess($authUser, 'shelf-life', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $items = $body['items'] ?? [];
        if (!is_array($items) || empty($items)) respond(['error' => 'Нет данных'], 400);
        try {
            // Определяем дату загружаемого файла
            $uploadDate = $items[0]['report_date'] ?? '';
            if (!$uploadDate) respond(['error' => 'Нет даты в данных'], 400);

            // Для каждого юрлица проверяем: не старше ли загружаемая дата максимальной в базе
            $entities = array_unique(array_column($items, 'legal_entity'));
            // Не-админ имеет право грузить только в свои юрлица
            foreach ($entities as $entity) {
                if (!checkLegalEntityAccess($authUser, $entity)) {
                    respond(['error' => "Нет доступа к юр. лицу: {$entity}"], 403);
                }
            }
            $skippedEntities = [];
            foreach ($entities as $entity) {
                $maxSt = $pdo->prepare("SELECT MAX(report_date) FROM warehouse_cells WHERE legal_entity = ?");
                $maxSt->execute([$entity]);
                $maxDate = $maxSt->fetchColumn();
                if ($maxDate && $uploadDate < $maxDate) {
                    $skippedEntities[] = $entity;
                }
            }

            // Записываем только данные для юрлиц, где загружаемая дата >= максимальной
            $inserted = 0;
            $st = $pdo->prepare("INSERT INTO warehouse_cells (report_date, legal_entity, stock_type, cell_count) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE cell_count = VALUES(cell_count)");
            foreach ($items as $item) {
                if (in_array($item['legal_entity'], $skippedEntities)) continue;
                $st->execute([$item['report_date'], $item['legal_entity'], $item['stock_type'], intval($item['cell_count'])]);
                $inserted++;
            }
            $msg = $inserted > 0 ? 'success' : 'skipped';
            respond(['success' => true, 'count' => $inserted, 'skipped' => count($skippedEntities) > 0 ? $skippedEntities : null]);
        } catch (PDOException $e) {
            error_log("save_warehouse_cells error: " . $e->getMessage());
            respond(['error' => 'Ошибка сохранения'], 500);
        }
    }

    if ($fn === 'get_warehouse_cells_range') {
        requireModuleAccess($authUser, 'shelf-life', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $entity = $body['entity'] ?? '';
        $from = $body['date_from'] ?? '';
        $to = $body['date_to'] ?? '';
        if (!$entity || !$from || !$to) respond(['error' => 'Не указаны обязательные параметры'], 400);
        if (!checkLegalEntityAccess($authUser, $entity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        // Расширяем диапазон на +3 дня чтобы захватить понедельник для последних выходных месяца
        $st = $pdo->prepare("SELECT report_date, stock_type, cell_count, is_manual FROM warehouse_cells WHERE legal_entity=? AND report_date >= ? AND report_date <= DATE_ADD(?, INTERVAL 3 DAY) AND stock_type IN ('cold','frozen') ORDER BY report_date, stock_type");
        $st->execute([$entity, $from, $to]);
        respond($st->fetchAll(PDO::FETCH_ASSOC));
    }

    // ═══ Аналитика ячеек склада (страница /shelf-life/analytics) ═══
    // Возвращает дневные данные за выбранный период, фронт сам агрегирует
    // по неделям/месяцам в зависимости от выбранной гранулярности.
    if ($fn === 'cell_analytics_get') {
        requireModuleAccess($authUser, 'shelf-life', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $start = trim((string)($body['start'] ?? ''));
        $end   = trim((string)($body['end'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            respond(['error' => 'Укажите корректный диапазон дат'], 400);
        }
        // Защита от слишком длинных запросов: максимум 12 месяцев + 30 дней
        // (нужно немного запасу для «сравнения с предыдущим периодом» на фронте).
        $maxDays = 366 + 30;
        $diff = (strtotime($end) - strtotime($start)) / 86400;
        if ($diff < 0) respond(['error' => 'Конец периода раньше начала'], 400);
        if ($diff > $maxDays) respond(['error' => 'Слишком большой период (максимум 12 месяцев)'], 400);

        // Не-админу отдаём только его юрлица.
        // ВАЖНО: в warehouse_cells.legal_entity хранятся короткие имена
        // («Бургер БК»), а у пользователей legal_entities — полные («ООО "Бургер БК"»).
        // Поэтому добавляем сокращённые варианты к списку фильтра.
        $leWhere = '';
        $leArgs = [];
        if (($authUser['role'] ?? '') !== 'admin') {
            $userEntities = $authUser['legal_entities'] ?? '';
            if (is_string($userEntities)) $userEntities = json_decode($userEntities, true) ?: [];
            if (!is_array($userEntities) || empty($userEntities)) respond(['rows' => [], 'start' => $start, 'end' => $end]);
            $allForms = [];
            foreach ($userEntities as $e) {
                $allForms[] = $e;
                if (preg_match('/"([^"]+)"/u', $e, $m)) $allForms[] = $m[1]; // выдернуть из «ООО "X"» → «X»
            }
            $allForms = array_values(array_unique($allForms));
            $phLE = implode(',', array_fill(0, count($allForms), '?'));
            $leWhere = " AND legal_entity IN ($phLE)";
            $leArgs = $allForms;
        }

        $st = $pdo->prepare("
            SELECT report_date, legal_entity, stock_type, cell_count, is_manual
            FROM warehouse_cells
            WHERE report_date >= ? AND report_date <= ?{$leWhere}
            ORDER BY report_date, legal_entity, stock_type
        ");
        $st->execute(array_merge([$start, $end], $leArgs));
        $rows = $st->fetchAll();
        respond(['rows' => $rows, 'start' => $start, 'end' => $end]);
    }

    // ─── Аннотации событий на графике аналитики ячеек ───
    if ($fn === 'cell_annotations_list') {
        requireModuleAccess($authUser, 'shelf-life', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $start = trim((string)($body['start'] ?? ''));
        $end   = trim((string)($body['end'] ?? ''));
        $sql = "SELECT id, event_date, label, color, created_by, created_at FROM cell_chart_annotations";
        $args = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            $sql .= " WHERE event_date >= ? AND event_date <= ?";
            $args = [$start, $end];
        }
        $sql .= " ORDER BY event_date";
        $st = $pdo->prepare($sql);
        $st->execute($args);
        respond(['rows' => $st->fetchAll()]);
    }

    if ($fn === 'cell_annotation_save') {
        requireModuleAccess($authUser, 'shelf-life', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        if (!$authUserName) respond(['error' => 'Нет авторизации'], 401);
        $id = (int)($body['id'] ?? 0);
        $date = trim((string)($body['event_date'] ?? ''));
        $label = trim((string)($body['label'] ?? ''));
        $color = trim((string)($body['color'] ?? '#E76F51'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) respond(['error' => 'Дата некорректна'], 400);
        if ($label === '') respond(['error' => 'Пустая метка'], 400);
        if (mb_strlen($label) > 255) respond(['error' => 'Метка слишком длинная'], 400);
        if (!preg_match('/^#[0-9A-Fa-f]{3,8}$/', $color)) $color = '#E76F51';
        try {
            if ($id > 0) {
                $pdo->prepare("UPDATE cell_chart_annotations SET event_date = ?, label = ?, color = ? WHERE id = ?")
                    ->execute([$date, $label, $color, $id]);
            } else {
                $pdo->prepare("INSERT INTO cell_chart_annotations (event_date, label, color, created_by) VALUES (?, ?, ?, ?)")
                    ->execute([$date, $label, $color, $authUserName]);
                $id = (int)$pdo->lastInsertId();
            }
        } catch (Throwable $e) {
            error_log('cell_annotation_save: ' . $e->getMessage());
            respond(['error' => 'Не удалось сохранить'], 500);
        }
        respond(['success' => true, 'id' => $id]);
    }

    if ($fn === 'cell_annotation_delete') {
        requireModuleAccess($authUser, 'shelf-life', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        if (!$authUserName) respond(['error' => 'Нет авторизации'], 401);
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) respond(['error' => 'id обязателен'], 400);
        try {
            $pdo->prepare("DELETE FROM cell_chart_annotations WHERE id = ?")->execute([$id]);
        } catch (Throwable $e) {
            error_log('cell_annotation_delete: ' . $e->getMessage());
            respond(['error' => 'Не удалось удалить'], 500);
        }
        respond(['success' => true]);
    }

    if ($fn === 'get_warehouse_cells') {
        requireModuleAccess($authUser, 'shelf-life', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $days = intval($body['days'] ?? 90);
        if ($days > 365) $days = 365;
        // Не-админу отдаём только его юрлица.
        // ВАЖНО: warehouse_cells.legal_entity хранит короткие имена («Бургер БК»),
        // а user.legal_entities — полные («ООО "Бургер БК"»). Добавляем оба варианта.
        $leWhere = '';
        $leArgs = [];
        if (($authUser['role'] ?? '') !== 'admin') {
            $userEntities = $authUser['legal_entities'] ?? '';
            if (is_string($userEntities)) $userEntities = json_decode($userEntities, true) ?: [];
            if (!is_array($userEntities) || empty($userEntities)) respond([]);
            $allForms = [];
            foreach ($userEntities as $e) {
                $allForms[] = $e;
                if (preg_match('/"([^"]+)"/u', $e, $m)) $allForms[] = $m[1];
            }
            $allForms = array_values(array_unique($allForms));
            $phLE = implode(',', array_fill(0, count($allForms), '?'));
            $leWhere = " AND legal_entity IN ($phLE)";
            $leArgs = $allForms;
        }
        $st = $pdo->prepare("SELECT report_date, legal_entity, stock_type, cell_count, is_manual FROM warehouse_cells WHERE report_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY){$leWhere} ORDER BY report_date DESC, legal_entity, stock_type");
        $st->execute(array_merge([$days], $leArgs));
        respond($st->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($fn === 'upsert_warehouse_cell') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['shelf-life'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
            respond(['error' => 'Недостаточно прав'], 403);
        }
        $date = $body['report_date'] ?? '';
        $entity = $body['legal_entity'] ?? '';
        $type = $body['stock_type'] ?? '';
        $count = intval($body['cell_count'] ?? 0);
        if (!$date || !$entity || !$type) respond(['error' => 'Не указаны обязательные поля'], 400);
        if (!in_array($type, ['cold','frozen','dry','shabany'])) respond(['error' => 'Неверный тип хранения'], 400);
        $existing = $pdo->prepare("SELECT id FROM warehouse_cells WHERE report_date=? AND legal_entity=? AND stock_type=?");
        $existing->execute([$date, $entity, $type]);
        $row = $existing->fetch();
        if ($row) {
            $pdo->prepare("UPDATE warehouse_cells SET cell_count=?, is_manual=1, updated_by=? WHERE id=?")->execute([$count, $caller['name'], $row['id']]);
        } else {
            $pdo->prepare("INSERT INTO warehouse_cells (report_date, legal_entity, stock_type, cell_count, is_manual, updated_by) VALUES (?,?,?,?,1,?)")->execute([$date, $entity, $type, $count, $caller['name']]);
        }
        respond(['ok' => true]);
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
            // Удаляем зависимый платёж: иначе в supplier_payments оставались
            // «висячие» строки с несуществующим order_id, дашборд продолжал
            // их учитывать.
            $pdo->prepare("DELETE FROM `supplier_payments` WHERE `order_id`=?")->execute([$orderId]);
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
                $pdo->prepare("INSERT INTO `item_order` (`id`,`supplier`,`legal_entity`,`item_id`,`position`) VALUES (?,?,?,?,?)")
                    ->execute([uuid(), $supplier, $legalEntity, $item['item_id'], $item['position']]);
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
        // Заказы ресторанов
        try { $s = $pdo->query("SELECT COUNT(*) as cnt FROM ro_orders WHERE 1=1" . $dateFilter); $stats['ro_orders_total'] = (int)$s->fetch()['cnt']; } catch (Exception $e) { $stats['ro_orders_total'] = 0; }
        // Заявки поставщикам
        try { $s = $pdo->query("SELECT COUNT(*) as cnt FROM so_orders WHERE 1=1" . $dateFilter); $stats['so_orders_total'] = (int)$s->fetch()['cnt']; } catch (Exception $e) { $stats['so_orders_total'] = 0; }
        // Протоколы цен
        try { $s = $pdo->query("SELECT COUNT(*) as cnt FROM price_agreements WHERE 1=1" . $dateFilter); $stats['price_agreements_total'] = (int)$s->fetch()['cnt']; } catch (Exception $e) { $stats['price_agreements_total'] = 0; }
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
        auditLog($pdo, 'session_terminated', 'system', $sessionId, $caller['name']);
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

    // ═══ PRICING: импорт залоговых цен (xlsx с листами Сухой/Холод/Мороз) ═══
    if ($fn === 'import_deposit_prices') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $perms = resolvePermissions($caller['role'] ?? 'user', $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['pricing'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);

        // rows уже распарсены на фронте: [{external_code, gtin, sku, name, price}]
        $rows = $body['rows'] ?? [];
        if (empty($rows)) respond(['error' => 'Пустой список'], 400);

        // Юрлицо, из-под которого вызван импорт — определяет группу (BK_VM | PS).
        // Если фронт не прислал — берём первое юрлицо пользователя (fallback).
        $le = trim((string)($body['legal_entity'] ?? ''));
        if (!$le) {
            $userEntities = $caller['legal_entities'] ?? [];
            $le = is_array($userEntities) && count($userEntities) ? $userEntities[0] : 'ООО "Бургер БК"';
        }
        if (!checkLegalEntityAccess($caller, $le)) respond(['error' => 'Нет доступа к юрлицу'], 403);

        // Загружаем все активные товары для сопоставления
        $allProducts = $pdo->query("SELECT sku, supplier, legal_entity, external_code, gtin, name FROM products WHERE is_active = 1")->fetchAll();
        $bySku = [];
        $byExt = [];
        $byGtin = [];
        foreach ($allProducts as $p) {
            $leRow = trim($p['legal_entity']);
            $grpRow = getEntityGroup($leRow);
            // Точное совпадение по юрлицу.
            $bySku[trim($p['sku']) . '|' . $leRow] = $p;
            if (!empty($p['external_code'])) $byExt[trim($p['external_code']) . '|' . $leRow] = $p;
            if (!empty($p['gtin'])) $byGtin[trim($p['gtin']) . '|' . $leRow] = $p;
            // Fallback в пределах группы юрлиц (BK_VM / PS) — ни в коем случае не глобально,
            // чтобы не склеить товары из чужой группы (напр. цены ВМ с поставщиком из ПС).
            $bySkuGrpKey = trim($p['sku']) . '|group|' . $grpRow;
            $bySku[$bySkuGrpKey] = $bySku[$bySkuGrpKey] ?? $p;
            if (!empty($p['external_code'])) {
                $byExtGrpKey = trim($p['external_code']) . '|group|' . $grpRow;
                $byExt[$byExtGrpKey] = $byExt[$byExtGrpKey] ?? $p;
            }
            if (!empty($p['gtin'])) {
                $byGtinGrpKey = trim($p['gtin']) . '|group|' . $grpRow;
                $byGtin[$byGtinGrpKey] = $byGtin[$byGtinGrpKey] ?? $p;
            }
        }

        // Собираем уникальные (товар → цена) — в файле одна и та же цена повторяется для разных ресторанов
        $uniquePrices = [];
        foreach ($rows as $r) {
            $ec = trim((string)($r['external_code'] ?? ''));
            $gt = trim((string)($r['gtin'] ?? ''));
            $sk = trim((string)($r['sku'] ?? ''));
            $price = floatval($r['price'] ?? 0);
            if ($price <= 0) continue;
            // Ключ — комбинация идентификаторов
            $key = $ec ?: ($gt ?: $sk);
            if (!$key) continue;
            if (!isset($uniquePrices[$key])) {
                $uniquePrices[$key] = ['ec' => $ec, 'gt' => $gt, 'sk' => $sk, 'name' => $r['name'] ?? '', 'price' => $price];
            }
        }

        // Цены живут на уровне группы юрлиц (BK_VM или PS) — одна запись на группу.
        // Триггер trg_product_prices_le_group_ins сам выставит legal_entity_group
        // по legal_entity, переданному в INSERT. legal_entity сохраняется как
        // «через какое юрлицо импортировано» (для аудита).
        $group = getEntityGroup($le);
        $entities = getEntitiesInGroup($group);
        $leForInsert = $entities[0]; // например, «Бургер БК» для группы BK_VM
        $matched = 0;
        $skipped = [];
        $upsert = $pdo->prepare("INSERT INTO product_prices (sku, supplier, legal_entity, price, vat_rate, unit_type, price_type, currency, updated_by)
            VALUES (?, ?, ?, ?, 0, 'box', 'deposit', 'BYN', ?)
            ON DUPLICATE KEY UPDATE price=VALUES(price), unit_type='box', updated_by=VALUES(updated_by), updated_at=NOW()");

        try {
            $pdo->beginTransaction();
            foreach ($uniquePrices as $up) {
                $product = null;
                // Ищем продукт сначала по конкретным юрлицам группы, потом fallback по группе.
                foreach ($entities as $entLe) {
                    if ($up['ec'] && isset($byExt[$up['ec'] . '|' . $entLe])) { $product = $byExt[$up['ec'] . '|' . $entLe]; break; }
                    if ($up['gt'] && isset($byGtin[$up['gt'] . '|' . $entLe])) { $product = $byGtin[$up['gt'] . '|' . $entLe]; break; }
                    if ($up['sk'] && isset($bySku[$up['sk'] . '|' . $entLe])) { $product = $bySku[$up['sk'] . '|' . $entLe]; break; }
                }
                if (!$product && $up['ec']) $product = $byExt[$up['ec'] . '|group|' . $group] ?? null;
                if (!$product && $up['gt']) $product = $byGtin[$up['gt'] . '|group|' . $group] ?? null;
                if (!$product && $up['sk']) $product = $bySku[$up['sk'] . '|group|' . $group] ?? null;
                if (!$product) {
                    $skipped[] = ['external_code' => $up['ec'], 'gtin' => $up['gt'], 'sku' => $up['sk'], 'name' => $up['name'], 'price' => $up['price']];
                    continue;
                }
                $upsert->execute([
                    $product['sku'],
                    $product['supplier'] ?? '',
                    $leForInsert,
                    $up['price'],
                    $caller['name'] ?? 'admin',
                ]);
                $matched++;
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('import_deposit_prices error: ' . $e->getMessage());
            respond(['error' => 'Ошибка импорта: ' . $e->getMessage()], 500);
        }
        auditLog($pdo, 'deposit_prices_imported', 'product_prices', null, $caller['name'], ['matched' => $matched, 'skipped' => count($skipped)]);
        respond([
            'success' => true,
            'matched' => $matched,
            'unique_products' => count($uniquePrices),
            'skipped_count' => count($skipped),
            'skipped' => array_slice($skipped, 0, 100),
        ]);
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
        // Проверка что agreement_id принадлежит той же группе юрлиц.
        $group = getEntityGroup($le);
        if ($agreementId) {
            $agChk = $pdo->prepare("SELECT legal_entity_group FROM price_agreements WHERE id=?"); $agChk->execute([$agreementId]);
            $agGroup = $agChk->fetchColumn();
            if (!$agGroup || $agGroup !== $group) respond(['error' => 'Протокол не принадлежит указанному юр. лицу'], 400);
        }
        $imported = 0;
        try {
            $pdo->beginTransaction();
            $currency = in_array($body['currency'] ?? '', ['BYN', 'RUB']) ? $body['currency'] : 'BYN';
            // INSERT пишет одну запись на группу — UNIQUE по (sku, supplier, legal_entity_group, price_type)
            // обеспечит UPSERT даже если предыдущая запись была от соседнего юрлица той же группы.
            $stmt = $pdo->prepare("INSERT INTO product_prices (sku, supplier, legal_entity, price, vat_rate, unit_type, currency, agreement_id, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE price=VALUES(price), vat_rate=VALUES(vat_rate), unit_type=VALUES(unit_type), currency=VALUES(currency), agreement_id=VALUES(agreement_id), updated_by=VALUES(updated_by), updated_at=NOW()");
            $oldStmt = $pdo->prepare("SELECT price, currency FROM product_prices WHERE sku=? AND supplier=? AND legal_entity_group=? AND price_type='purchase'");
            $histStmt = $pdo->prepare("INSERT INTO price_history (sku, supplier, legal_entity, old_price, new_price, old_currency, new_currency, agreement_id, changed_by) VALUES (?,?,?,?,?,?,?,?,?)");
            foreach ($prices as $p) {
                $sku = trim($p['sku'] ?? '');
                $price = floatval($p['price'] ?? 0);
                $ut = $p['unit_type'] ?? 'piece';
                $unitType = in_array($ut, ['piece', 'box', 'thousand', 'kg', 'liter']) ? $ut : 'piece';
                $cur = in_array($p['currency'] ?? '', ['BYN', 'RUB']) ? $p['currency'] : $currency;
                $vat = floatval($p['vat_rate'] ?? 20);
                if (!$sku || $price < 0) continue;
                // Сохранить старую цену для истории (по группе).
                $oldStmt->execute([$sku, $supplier, $group]);
                $old = $oldStmt->fetch();
                $stmt->execute([$sku, $supplier, $le, $price, $vat, $unitType, $cur, $agreementId, $caller['name']]);
                // Записать в историю если цена изменилась или новая
                if (!$old || floatval($old['price']) != $price || ($old['currency'] ?? '') !== $cur) {
                    $histStmt->execute([$sku, $supplier, $le, $old ? $old['price'] : null, $price, $old ? $old['currency'] : null, $cur, $agreementId, $caller['name']]);
                }
                $imported++;
            }
            $pdo->commit();
            auditLog($pdo, 'price_imported', 'price_agreement', $agreementId, $caller['name'], ['legal_entity' => $le, 'supplier' => $supplier, 'count' => $imported]);
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
            if (!checkLegalEntityGroupAccess($caller, $ag['legal_entity_group'])) { $pdo->rollBack(); respond(['error' => 'Нет доступа к юр. лицу'], 403); }
            if ($ag['status'] === 'active') { $pdo->rollBack(); respond(['error' => 'Протокол уже согласован'], 400); }
            $docType = $ag['doc_type'] ?? 'psc';
            // ПСЦ архивирует предыдущие ПСЦ этого поставщика на уровне группы юрлиц.
            if ($docType === 'psc') {
                $pdo->prepare("UPDATE price_agreements SET status='archived' WHERE supplier=? AND legal_entity_group=? AND status='active' AND doc_type='psc'")->execute([$ag['supplier'], $ag['legal_entity_group']]);
            }
            $pdo->prepare("UPDATE price_agreements SET status='active', approved_by=?, approved_at=NOW() WHERE id=?")->execute([$caller['name'], $id]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('approve_agreement error: ' . $e->getMessage());
            respond(['error' => 'Ошибка согласования'], 500);
        }
        auditLog($pdo, 'agreement_approved', 'price_agreement', $id, $caller['name'], ['supplier' => $ag['supplier'], 'legal_entity' => $ag['legal_entity']]);
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
        if (!checkLegalEntityGroupAccess($caller, $ag['legal_entity_group'])) respond(['error' => 'Нет доступа к юр. лицу'], 403);
        if ($ag['status'] === 'archived') respond(['error' => 'Протокол уже в архиве'], 400);
        $pdo->prepare("UPDATE price_agreements SET status='archived' WHERE id=?")->execute([$id]);
        auditLog($pdo, 'agreement_archived', 'price_agreement', $id, $caller['name'], ['supplier' => $ag['supplier'], 'legal_entity' => $ag['legal_entity']]);
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
        if (!checkLegalEntityGroupAccess($caller, $ag['legal_entity_group'])) respond(['error' => 'Нет доступа к юр. лицу'], 403);
        if ($ag['status'] !== 'archived') respond(['error' => 'Протокол не в архиве'], 400);
        $docType = $ag['doc_type'] ?? 'psc';
        $pdo->beginTransaction();
        try {
            // Архивируем текущий активный ПСЦ того же поставщика на уровне группы юрлиц.
            if ($docType === 'psc') {
                $pdo->prepare("UPDATE price_agreements SET status='archived' WHERE supplier=? AND legal_entity_group=? AND status='active' AND doc_type='psc'")->execute([$ag['supplier'], $ag['legal_entity_group']]);
            }
            $pdo->prepare("UPDATE price_agreements SET status='active' WHERE id=?")->execute([$id]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(['error' => 'Ошибка восстановления'], 500);
        }
        auditLog($pdo, 'agreement_approved', 'price_agreement', $id, $caller['name'], ['supplier' => $ag['supplier'], 'legal_entity' => $ag['legal_entity'], 'action' => 'restore']);
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
        // Цены — на уровне группы юрлиц (BK_VM или PS), одна запись на группу.
        $group = getEntityGroup($le);
        $sql = "SELECT pp.id, pp.sku, pp.price, pp.vat_rate, pp.unit_type, pp.currency, pp.supplier, pp.agreement_id, pp.updated_at FROM product_prices pp WHERE pp.legal_entity_group=? AND pp.price_type='purchase'";
        $params = [$group];
        if ($supplier) { $sql .= " AND pp.supplier=?"; $params[] = $supplier; }
        $s = $pdo->prepare($sql); $s->execute($params);
        $rows = $s->fetchAll();
        // Залоговые цены (отдельная выборка — для колонки «Залог» в прайс-листе)
        $dep = $pdo->prepare("SELECT sku, price FROM product_prices WHERE legal_entity_group=? AND price_type='deposit'");
        $dep->execute([$group]);
        $depositMap = [];
        foreach ($dep->fetchAll() as $d) { $depositMap[$d['sku']] = (float)$d['price']; }
        // Получаем курс RUB→BYN
        $rateStmt = $pdo->prepare("SELECT value FROM settings WHERE `key`='rub_to_byn_rate'"); $rateStmt->execute();
        $rate = floatval($rateStmt->fetchColumn() ?: '0.0375');
        respond(['prices' => $rows, 'deposit_prices' => $depositMap, 'rub_to_byn_rate' => $rate]);
    }

    if ($fn === 'update_exchange_rate') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $perms = resolvePermissions($caller['role'] ?? 'user', $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['pricing'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);
        $rate = floatval($body['rate'] ?? 0);
        if ($rate <= 0 || $rate > 1) respond(['error' => 'Некорректный курс (ожидается число от 0 до 1)'], 400);
        $pdo->prepare("INSERT INTO settings (`key`, value) VALUES ('rub_to_byn_rate', ?) ON DUPLICATE KEY UPDATE value=?")->execute([(string)$rate, (string)$rate]);
        auditLog($pdo, 'exchange_rate_updated', 'system', 'rub_to_byn_rate', $caller['name'], ['rate' => $rate]);
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
        if (!checkLegalEntityGroupAccess($caller, $ag['legal_entity_group'])) respond(['error' => 'Нет доступа к юр. лицу'], 403);
        // Сначала транзакция БД, потом — удаление файла. Иначе при сбое каскада
        // файл уже удалён, а запись осталась.
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
        // Удалить файл с диска после успешного коммита.
        if ($ag['file_path']) {
            $fpBase = basename($ag['file_path']);
            if ($fpBase) {
                $fp = __DIR__ . '/../uploads/psc/' . $fpBase;
                if (file_exists($fp)) @unlink($fp);
            }
        }
        auditLog($pdo, 'agreement_deleted', 'price_agreement', $id, $caller['name'], ['supplier' => $ag['supplier'], 'legal_entity' => $ag['legal_entity']]);
        respond(['success' => true]);
    }

    // ═══ PRICING: полный список залоговых цен для вкладки ═══
    if ($fn === 'get_deposit_prices') {
        $caller = getSessionUser($pdo);
        if (!$caller && !checkApiKey($pdo)) respond(['error' => 'Требуется авторизация'], 401);
        $le = $body['legal_entity'] ?? '';
        if (!$le) respond(['error' => 'Не указано юр. лицо'], 400);
        if ($caller && !checkLegalEntityAccess($caller, $le)) respond(['error' => 'Нет доступа к юр. лицу'], 403);
        // Товар может не иметь записи в products — показываем имя из products при наличии.
        // Цены и продукты живут на уровне группы юрлиц (BK_VM или PS).
        $group = getEntityGroup($le);
        $sql = "SELECT pp.id, pp.sku, pp.price, pp.updated_at, pp.updated_by,
                       COALESCE(p.name, '') AS name,
                       COALESCE(p.supplier, pp.supplier, '') AS supplier,
                       COALESCE(p.external_code, '') AS external_code,
                       COALESCE(p.gtin, '') AS gtin
                FROM product_prices pp
                LEFT JOIN products p ON p.sku = pp.sku AND p.legal_entity_group = pp.legal_entity_group AND p.is_active = 1
                WHERE pp.legal_entity_group = ? AND pp.price_type = 'deposit'
                ORDER BY p.name, pp.sku";
        $s = $pdo->prepare($sql); $s->execute([$group]);
        respond(['prices' => $s->fetchAll()]);
    }

    // ═══ PRICING: обновить/удалить залоговую цену конкретного товара ═══
    if ($fn === 'set_deposit_price') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $perms = resolvePermissions($caller['role'] ?? 'user', $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['pricing'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);

        $sku = trim((string)($body['sku'] ?? ''));
        $le = trim((string)($body['legal_entity'] ?? ''));
        $price = isset($body['price']) && $body['price'] !== '' ? floatval($body['price']) : null; // null = удалить
        if (!$sku || !$le) respond(['error' => 'Не указан SKU или юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $le)) respond(['error' => 'Нет доступа'], 403);
        if ($price !== null && $price <= 0) respond(['error' => 'Цена должна быть > 0'], 400);

        // Цены живут на уровне группы — одна запись на группу.
        $group = getEntityGroup($le);
        $entities = getEntitiesInGroup($group);
        $leForInsert = $entities[0];

        // Поставщик товара (для NOT NULL supplier в product_prices)
        $supStmt = $pdo->prepare("SELECT supplier FROM products WHERE sku = ? AND legal_entity_group = ? AND is_active = 1 LIMIT 1");
        $supStmt->execute([$sku, $group]);
        $supplier = $supStmt->fetchColumn() ?: '';

        try {
            if ($price === null) {
                $pdo->prepare("DELETE FROM product_prices WHERE sku=? AND legal_entity_group=? AND price_type='deposit'")
                    ->execute([$sku, $group]);
            } else {
                $pdo->prepare("INSERT INTO product_prices (sku, supplier, legal_entity, price, vat_rate, unit_type, price_type, currency, updated_by)
                    VALUES (?, ?, ?, ?, 0, 'box', 'deposit', 'BYN', ?)
                    ON DUPLICATE KEY UPDATE price=VALUES(price), unit_type='box', updated_by=VALUES(updated_by), updated_at=NOW()")
                    ->execute([$sku, $supplier, $leForInsert, $price, $caller['name'] ?? 'admin']);
            }
        } catch (Exception $e) {
            error_log('set_deposit_price error: ' . $e->getMessage());
            respond(['error' => 'Ошибка сохранения: ' . $e->getMessage()], 500);
        }
        auditLog($pdo, $price === null ? 'deposit_price_deleted' : 'deposit_price_updated', 'product_prices', null, $caller['name'], ['sku' => $sku, 'price' => $price, 'group' => $group]);
        respond(['success' => true]);
    }

    if ($fn === 'delete_price') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'Не указан ID'], 400);
        $s = $pdo->prepare("SELECT * FROM product_prices WHERE id=?"); $s->execute([$id]); $row = $s->fetch();
        if (!$row) respond(['error' => 'Цена не найдена'], 404);
        // Доступ — на уровне группы юрлиц (цены общие на группу).
        if (!checkLegalEntityGroupAccess($caller, $row['legal_entity_group'])) respond(['error' => 'Нет доступа'], 403);
        $perms = resolvePermissions($caller['role'] ?? 'user', $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['pricing'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);
        $pdo->prepare("DELETE FROM product_prices WHERE id=?")->execute([$id]);
        auditLog($pdo, 'price_deleted', 'price_agreement', $id, $caller['name'], ['sku' => $row['sku'], 'supplier' => $row['supplier'], 'legal_entity' => $row['legal_entity']]);
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
        // История цен — на уровне группы юрлиц.
        $group = getEntityGroup($le);
        $sql = "SELECT * FROM price_history WHERE sku=? AND legal_entity_group=?";
        $params = [$sku, $group];
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
        applyEntityGroupFilter($le, $leWhere, $leParams, 'p.legal_entity_group');
        $sql .= " AND " . $leWhere[0];
        $params = array_merge($params, $leParams);
        if ($supplier) { $sql .= " AND p.supplier = ?"; $params[] = $supplier; }
        $sql .= " AND NOT EXISTS (SELECT 1 FROM product_prices pp WHERE pp.sku = p.sku AND pp.legal_entity_group = ? AND pp.price_type = 'purchase')";
        $params[] = getEntityGroup($le);
        $sql .= " ORDER BY p.supplier, p.name";
        $s = $pdo->prepare($sql); $s->execute($params);
        respond($s->fetchAll());
    }

    // ═══ Тендеры: сохранить тендер целиком ═══
    if ($fn === 'save_tender') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $perms = resolvePermissions($caller['role'] ?? 'user', $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        $tendersLevel = $ACCESS_LEVELS[$perms['tenders'] ?? 'none'] ?? 0;
        if ($tendersLevel < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);

        $tenderId = intval($body['id'] ?? 0);
        $name = trim($body['name'] ?? '');
        $description = $body['description'] ?? null;
        $le = $body['legal_entity'] ?? '';
        $statusInput = $body['status'] ?? 'draft';
        $allowedStatuses = ['draft', 'in_progress', 'completed', 'cancelled'];
        if (!in_array($statusInput, $allowedStatuses, true)) respond(['error' => 'Недопустимый статус'], 400);
        $deadline = $body['deadline'] ?? null;
        $winnerSupplierInput = $body['winner_supplier'] ?? null;
        $summary = $body['summary'] ?? null;
        $note = $body['note'] ?? null;
        $items = $body['items'] ?? [];
        $offers = $body['offers'] ?? [];

        if (!$name) respond(['error' => 'Укажите название тендера'], 400);
        if (!$le) respond(['error' => 'Не указано юрлицо'], 400);
        if (!checkLegalEntityAccess($caller, $le)) respond(['error' => 'Нет доступа к юр. лицу'], 403);

        // Закрытие тендера и выбор/смена победителя — только full (admin/manager)
        $oldStatus = null;
        $oldWinner = null;
        if ($tenderId) {
            $cur = $pdo->prepare("SELECT status, winner_supplier FROM tenders WHERE id=? AND legal_entity=?");
            $cur->execute([$tenderId, $le]);
            $curRow = $cur->fetch();
            if (!$curRow) respond(['error' => 'Тендер не найден'], 404);
            $oldStatus = $curRow['status'];
            $oldWinner = $curRow['winner_supplier'];
        }
        $isClosing = $statusInput === 'completed' && $oldStatus !== 'completed';
        $winnerChanged = ($winnerSupplierInput ?? '') !== ($oldWinner ?? '');
        if (($isClosing || $winnerChanged) && $tendersLevel < $ACCESS_LEVELS['full']) {
            respond(['error' => 'Закрытие тендера и выбор победителя — только для администратора/менеджера'], 403);
        }
        // При создании тендер всегда стартует как draft, без победителя
        $status = $tenderId ? $statusInput : 'draft';
        $winnerSupplier = $tenderId ? $winnerSupplierInput : null;

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
                $mc = isset($item['monthly_consumption']) && $item['monthly_consumption'] !== null ? floatval($item['monthly_consumption']) : null;
                $pdo->prepare("INSERT INTO tender_items (tender_id, name, sku, quantity, unit, monthly_consumption, sort_order, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$tenderId, $item['name'] ?? '', $item['sku'] ?? null, $item['quantity'] ?? null, $item['unit'] ?? null, $mc, $i, $item['note'] ?? null]);
                $itemIdMap[$i] = $pdo->lastInsertId();
            }

            // Предложения: удалить старые, вставить новые
            $pdo->prepare("DELETE FROM tender_offers WHERE tender_id=?")->execute([$tenderId]);
            foreach ($offers as $offer) {
                $pdo->prepare("INSERT INTO tender_offers (tender_id, supplier, delivery_days, payment_terms, conditions, note) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$tenderId, $offer['supplier'] ?? '', $offer['delivery_days'] ?? null, $offer['payment_terms'] ?? null, $offer['conditions'] ?? null, $offer['note'] ?? null]);
                $offerId = $pdo->lastInsertId();
                $prices = $offer['prices'] ?? [];
                $pricesRub = $offer['prices_rub'] ?? [];
                $pricesByn = $offer['prices_byn'] ?? [];
                foreach ($prices as $idx => $price) {
                    if (!isset($itemIdMap[$idx])) continue;
                    $priceRub = isset($pricesRub[$idx]) && $pricesRub[$idx] !== null ? floatval($pricesRub[$idx]) : null;
                    $priceByn = isset($pricesByn[$idx]) && $pricesByn[$idx] !== null ? floatval($pricesByn[$idx]) : null;
                    $pdo->prepare("INSERT INTO tender_offer_prices (offer_id, item_id, price, price_rub, price_byn) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$offerId, $itemIdMap[$idx], $price, $priceRub, $priceByn]);
                }
            }

            $pdo->commit();
            $isNew = !intval($body['id'] ?? 0);
            $auditDetails = ['name' => $name, 'legal_entity' => $le, 'status' => $status];
            if (!$isNew) {
                if ($oldStatus !== $status) $auditDetails['status_change'] = ['from' => $oldStatus, 'to' => $status];
                if (($oldWinner ?? '') !== ($winnerSupplier ?? '')) $auditDetails['winner_change'] = ['from' => $oldWinner, 'to' => $winnerSupplier];
            }
            auditLog($pdo, $isNew ? 'tender_created' : 'tender_updated', 'tender', $tenderId, $caller['name'], $auditDetails);
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
        requireModuleAccess($caller, 'tenders', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'Не указан ID'], 400);

        $s = $pdo->prepare("SELECT * FROM tenders WHERE id=?"); $s->execute([$id]);
        $tender = $s->fetch();
        if (!$tender) respond(['error' => 'Тендер не найден'], 404);
        if (!checkLegalEntityAccess($caller, $tender['legal_entity'])) respond(['error' => 'Нет доступа'], 403);

        // Позиции
        $s = $pdo->prepare("SELECT * FROM tender_items WHERE tender_id=? ORDER BY sort_order"); $s->execute([$id]);
        $items = $s->fetchAll();
        // Подтянуть расход из analysis_data по SKU (с учётом аналогов)
        $skus = array_filter(array_column($items, 'sku'));
        $consumptionMap = [];
        if (!empty($skus)) {
            // Найти группы аналогов для всех SKU позиций
            $ph = implode(',', array_fill(0, count($skus), '?'));
            $s = $pdo->prepare("SELECT sku, analog_group FROM products WHERE sku IN ($ph) AND analog_group IS NOT NULL AND analog_group != ''");
            $s->execute($skus);
            $skuToGroup = [];
            $groups = [];
            foreach ($s->fetchAll() as $row) {
                $skuToGroup[$row['sku']] = $row['analog_group'];
                $groups[$row['analog_group']] = true;
            }
            // Найти все SKU аналогов
            $allSkusForQuery = $skus;
            $groupToSkus = [];
            if (!empty($groups)) {
                $gph = implode(',', array_fill(0, count($groups), '?'));
                $s = $pdo->prepare("SELECT sku, analog_group FROM products WHERE analog_group IN ($gph)");
                $s->execute(array_keys($groups));
                foreach ($s->fetchAll() as $row) {
                    $groupToSkus[$row['analog_group']][] = $row['sku'];
                    $allSkusForQuery[] = $row['sku'];
                }
            }
            $allSkusForQuery = array_values(array_unique($allSkusForQuery));
            // Загрузить расход по всем SKU (основные + аналоги)
            $ph2 = implode(',', array_fill(0, count($allSkusForQuery), '?'));
            $s = $pdo->prepare("SELECT sku, consumption, period_days FROM analysis_data WHERE sku IN ($ph2) AND legal_entity = ?");
            $s->execute(array_merge($allSkusForQuery, [$tender['legal_entity']]));
            $adMap = [];
            foreach ($s->fetchAll() as $row) {
                $daily = ($row['period_days'] > 0) ? $row['consumption'] / $row['period_days'] : 0;
                $adMap[$row['sku']] = $daily;
            }
            // Суммировать расход: основной SKU + все аналоги из группы
            foreach ($skus as $sku) {
                $totalDaily = 0;
                if (isset($skuToGroup[$sku]) && isset($groupToSkus[$skuToGroup[$sku]])) {
                    foreach ($groupToSkus[$skuToGroup[$sku]] as $gs) {
                        $totalDaily += $adMap[$gs] ?? 0;
                    }
                } else {
                    $totalDaily = $adMap[$sku] ?? 0;
                }
                $consumptionMap[$sku] = $totalDaily > 0 ? round($totalDaily * 30, 1) : null;
            }
        }
        foreach ($items as &$item) {
            // Если сохранён ручной расход — использовать его, иначе подтянуть автоматически
            if ($item['monthly_consumption'] !== null) {
                $item['monthly_consumption'] = floatval($item['monthly_consumption']);
                $item['consumption_auto'] = $item['sku'] ? ($consumptionMap[$item['sku']] ?? null) : null;
            } else {
                $item['monthly_consumption'] = $item['sku'] ? ($consumptionMap[$item['sku']] ?? null) : null;
                $item['consumption_auto'] = $item['monthly_consumption'];
            }
        }
        unset($item);
        $tender['items'] = $items;

        // Предложения + цены: один запрос вместо N+1.
        // Раньше для каждого предложения делался отдельный SELECT в tender_offer_prices.
        // На тендере с 20 предложениями = 20 запросов; теперь — 2 (offers + одна выборка цен).
        $s = $pdo->prepare("SELECT id, tender_id, supplier, delivery_days, payment_terms, conditions, note, created_at FROM tender_offers WHERE tender_id=? ORDER BY id"); $s->execute([$id]);
        $offers = $s->fetchAll();
        if ($offers) {
            $offerIds = array_column($offers, 'id');
            $ph = implode(',', array_fill(0, count($offerIds), '?'));
            $sp = $pdo->prepare("SELECT offer_id, item_id, price, price_rub, price_byn FROM tender_offer_prices WHERE offer_id IN ($ph)");
            $sp->execute($offerIds);
            $pricesByOffer = [];
            foreach ($sp->fetchAll() as $row) {
                $oid = $row['offer_id'];
                unset($row['offer_id']);
                $pricesByOffer[$oid][] = $row;
            }
            foreach ($offers as &$offer) {
                $offer['prices'] = $pricesByOffer[$offer['id']] ?? [];
            }
            unset($offer);
        }
        $tender['offers'] = $offers;

        // Файлы КП
        $s = $pdo->prepare("SELECT id, supplier, file_name, file_path, uploaded_at FROM tender_files WHERE tender_id=? ORDER BY uploaded_at"); $s->execute([$id]);
        $tender['files'] = $s->fetchAll();

        // Курс валют
        $rateStmt = $pdo->prepare("SELECT value FROM settings WHERE `key`='rub_to_byn_rate'");
        $rateStmt->execute();
        $tender['rub_to_byn_rate'] = floatval($rateStmt->fetchColumn() ?: '0.0375');

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
        // Собираем пути файлов до DELETE (после CASCADE строки уже исчезнут),
        // но сами unlink делаем ПОСЛЕ успешного DELETE, чтобы не остаться с
        // удалёнными файлами и неудалённой записью.
        $fs = $pdo->prepare("SELECT file_path FROM tender_files WHERE tender_id=?"); $fs->execute([$id]);
        $filePaths = $fs->fetchAll(PDO::FETCH_COLUMN);
        // CASCADE удалит items, offers, offer_prices, files
        $pdo->prepare("DELETE FROM tenders WHERE id=?")->execute([$id]);
        foreach ($filePaths as $fp) {
            $fpath = __DIR__ . '/../uploads/tenders/' . basename($fp);
            if (file_exists($fpath)) @unlink($fpath);
        }
        auditLog($pdo, 'tender_deleted', 'tender', $id, $caller['name'], ['legal_entity' => $le]);
        respond(['success' => true]);
    }

    // ═══ Маркетинг: сохранить активность ═══
    if ($fn === 'save_marketing_activity') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $perms = resolvePermissions($caller['role'] ?? 'user', $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['marketing'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);

        $actId = intval($body['id'] ?? 0);
        $name = trim($body['name'] ?? '');
        $type = $body['type'] ?? 'promo';
        $status = $body['status'] ?? 'active';
        $dateFrom = $body['date_from'] ?? null;
        $dateTo = $body['date_to'] ?? null;
        $le = $body['legal_entity'] ?? '';
        $restaurantCount = isset($body['restaurant_count']) ? intval($body['restaurant_count']) : null;
        $note = $body['note'] ?? null;
        $stages = isset($body['stages']) && is_array($body['stages']) ? json_encode($body['stages'], JSON_UNESCAPED_UNICODE) : null;
        $items = $body['items'] ?? [];

        if (!$name) respond(['error' => 'Укажите название'], 400);
        if (!$le) respond(['error' => 'Не указано юрлицо'], 400);
        if (!checkLegalEntityAccess($caller, $le)) respond(['error' => 'Нет доступа к юр. лицу'], 403);

        $pdo->beginTransaction();
        try {
            if ($actId) {
                $pdo->prepare("UPDATE marketing_activities SET name=?, type=?, status=?, date_from=?, date_to=?, restaurant_count=?, note=?, stages=?, updated_at=NOW() WHERE id=? AND legal_entity=?")
                    ->execute([$name, $type, $status, $dateFrom, $dateTo, $restaurantCount, $note, $stages, $actId, $le]);
            } else {
                $pdo->prepare("INSERT INTO marketing_activities (name, type, status, date_from, date_to, legal_entity, restaurant_count, note, stages, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$name, $type, $status, $dateFrom, $dateTo, $le, $restaurantCount, $note, $stages, $caller['name'] ?? '']);
                $actId = $pdo->lastInsertId();
            }

            $pdo->prepare("DELETE FROM marketing_activity_items WHERE activity_id=?")->execute([$actId]);
            foreach ($items as $i => $item) {
                $auvPeriods = isset($item['auv_periods']) && is_array($item['auv_periods']) ? json_encode($item['auv_periods']) : null;
                $subItems = isset($item['sub_items']) && is_array($item['sub_items']) ? json_encode($item['sub_items'], JSON_UNESCAPED_UNICODE) : null;
                $pdo->prepare("INSERT INTO marketing_activity_items (activity_id, product_id, sku, name, calc_method, auv, auv_periods, sub_items, total_volume, fixed_qty, unit, sort_order, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([
                        $actId,
                        $item['product_id'] ?? null,
                        $item['sku'] ?? null,
                        $item['name'] ?? '',
                        $item['calc_method'] ?? 'auv',
                        $item['auv'] ?? null,
                        $auvPeriods,
                        $subItems,
                        $item['total_volume'] ?? null,
                        $item['fixed_qty'] ?? null,
                        $item['unit'] ?? 'шт',
                        $i,
                        $item['note'] ?? null,
                    ]);
            }

            $pdo->commit();
            $isNew = !intval($body['id'] ?? 0);
            auditLog($pdo, $isNew ? 'marketing_created' : 'marketing_updated', 'marketing', $actId, $caller['name'], ['name' => $name, 'legal_entity' => $le, 'type' => $type]);
            respond(['success' => true, 'id' => intval($actId)]);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('save_marketing_activity error: ' . $e->getMessage());
            respond(['error' => 'Ошибка сохранения'], 500);
        }
    }

    // ═══ Маркетинг: загрузить активность ═══
    if ($fn === 'get_marketing_activity') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'marketing', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'Не указан ID'], 400);

        $s = $pdo->prepare("SELECT * FROM marketing_activities WHERE id=?"); $s->execute([$id]);
        $act = $s->fetch();
        if (!$act) respond(['error' => 'Активность не найдена'], 404);
        if (!checkLegalEntityAccess($caller, $act['legal_entity'])) respond(['error' => 'Нет доступа'], 403);

        $s = $pdo->prepare("SELECT * FROM marketing_activity_items WHERE activity_id=? ORDER BY sort_order"); $s->execute([$id]);
        $act['items'] = $s->fetchAll();

        $s = $pdo->prepare("SELECT * FROM marketing_activity_files WHERE activity_id=? ORDER BY uploaded_at"); $s->execute([$id]);
        $act['files'] = $s->fetchAll();

        respond($act);
    }

    // ═══ Маркетинг: удалить активность ═══
    if ($fn === 'delete_marketing_activity') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $perms = resolvePermissions($caller['role'] ?? 'user', $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['marketing'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['full']) respond(['error' => 'Недостаточно прав'], 403);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'Не указан ID'], 400);
        $s = $pdo->prepare("SELECT legal_entity FROM marketing_activities WHERE id=?"); $s->execute([$id]);
        $le = $s->fetchColumn();
        if (!$le) respond(['error' => 'Не найдена'], 404);
        if (!checkLegalEntityAccess($caller, $le)) respond(['error' => 'Нет доступа'], 403);
        // Собираем пути до DELETE; unlink — после успешного DELETE.
        $fs = $pdo->prepare("SELECT file_path FROM marketing_activity_files WHERE activity_id=?"); $fs->execute([$id]);
        $filePaths = $fs->fetchAll(PDO::FETCH_COLUMN);
        $pdo->prepare("DELETE FROM marketing_activities WHERE id=?")->execute([$id]);
        foreach ($filePaths as $fp) {
            $fpath = __DIR__ . '/../uploads/marketing/' . basename($fp);
            if (file_exists($fpath)) @unlink($fpath);
        }
        auditLog($pdo, 'marketing_deleted', 'marketing', $id, $caller['name'], ['legal_entity' => $le]);
        respond(['success' => true]);
    }

    // ═══ Рецептуры: импорт из JSON (парсинг на фронте) ═══
    if ($fn === 'import_recipes') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $perms = resolvePermissions($caller['role'] ?? 'user', $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['marketing'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);

        $recipes = $body['recipes'] ?? [];
        $legalEntity = $body['legal_entity'] ?? null;
        if (empty($recipes)) respond(['error' => 'Нет данных для импорта'], 400);
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);

        $group = getEntityGroup($legalEntity);

        $pdo->beginTransaction();
        try {
            // Очистить старые рецептуры ТОЛЬКО этого юрлица (не трогаем чужие)
            $pdo->prepare("DELETE ri FROM recipe_ingredients ri JOIN recipes r ON r.id = ri.recipe_id WHERE r.legal_entity_group = ?")
                ->execute([$group]);
            $pdo->prepare("DELETE FROM recipes WHERE legal_entity_group = ?")->execute([$group]);

            $imported = 0;
            foreach ($recipes as $r) {
                $code = $r['code'] ?? null;
                $name = trim($r['name'] ?? '');
                if (!$name) continue;
                $thk = $r['thk'] ?? null;
                $brutto = $r['brutto'] ?? null;
                $qty = $r['qty'] ?? null;

                $pdo->prepare("INSERT INTO recipes (code, name, thk, legal_entity_group, brutto_total, qty_total) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$code, $name, $thk, $group, $brutto, $qty]);
                $recipeId = $pdo->lastInsertId();

                foreach (($r['ingredients'] ?? []) as $i => $ing) {
                    $pdo->prepare("INSERT INTO recipe_ingredients (recipe_id, sku, name, brutto, qty, sort_order) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$recipeId, $ing['sku'] ?? null, $ing['name'] ?? '', $ing['brutto'] ?? null, $ing['qty'] ?? null, $i]);
                }
                $imported++;
            }

            $pdo->commit();
            auditLog($pdo, 'recipe_imported', 'import', null, $caller['name'], ['count' => $imported]);
            respond(['success' => true, 'imported' => $imported]);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('import_recipes error: ' . $e->getMessage());
            respond(['error' => 'Ошибка импорта рецептур'], 500);
        }
    }

    // ═══ Рецептуры: получить ингредиенты для списка блюд ═══
    if ($fn === 'get_recipe_ingredients') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);

        $dishNames = $body['dish_names'] ?? [];
        $dishCodes = $body['dish_codes'] ?? [];
        $legalEntity = $body['legal_entity'] ?? null;
        if (empty($dishNames) && empty($dishCodes)) respond(['error' => 'Не указаны блюда'], 400);
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);

        $group = getEntityGroup($legalEntity);

        $recipes = [];
        if (!empty($dishCodes)) {
            $ph = implode(',', array_fill(0, count($dishCodes), '?'));
            $s = $pdo->prepare("SELECT * FROM recipes WHERE code IN ($ph) AND legal_entity_group = ?");
            $s->execute(array_merge($dishCodes, [$group]));
            $recipes = $s->fetchAll();
        } elseif (!empty($dishNames)) {
            $ph = implode(',', array_fill(0, count($dishNames), '?'));
            $s = $pdo->prepare("SELECT * FROM recipes WHERE name IN ($ph) AND legal_entity_group = ?");
            $s->execute(array_merge($dishNames, [$group]));
            $recipes = $s->fetchAll();
        }

        // Собрать все SKU ингредиентов одним запросом (раньше тут был N+1
        // по числу рецептов). JOIN с products фильтруется по группе юрлиц
        // рецепта, чтобы не подтянуть карточку из чужой группы.
        $allSkus = [];
        $recipeIngs = [];
        $recipeIds = array_column($recipes, 'id');
        if (!empty($recipeIds)) {
            $rph = implode(',', array_fill(0, count($recipeIds), '?'));
            $stIngs = $pdo->prepare("SELECT ri.*, p.analog_group, p.qty_per_box, p.unit_of_measure as product_unit, p.supplier as product_supplier
                FROM recipe_ingredients ri
                LEFT JOIN products p ON p.sku COLLATE utf8mb4_unicode_ci = ri.sku COLLATE utf8mb4_unicode_ci
                 AND p.legal_entity_group = ?
                 AND p.is_active = 1
                WHERE ri.recipe_id IN ({$rph}) ORDER BY ri.recipe_id, ri.sort_order");
            $stIngs->execute(array_merge([$group], $recipeIds));
            foreach ($stIngs->fetchAll() as $ing) {
                $recipeIngs[$ing['recipe_id']][] = $ing;
                if ($ing['sku'] && !$ing['analog_group']) $allSkus[] = $ing['sku'];
            }
        }

        // Для SKU без совпадения в products — искать через cards
        $cardAnalogMap = []; // sku → { analog_group, qty_per_box, product_unit, resolved_name }
        if (!empty($allSkus)) {
            $allSkus = array_values(array_unique($allSkus));
            // 1) Прямой поиск по cards.id
            $ph = implode(',', array_fill(0, count($allSkus), '?'));
            $s = $pdo->prepare("SELECT id, name, analogs FROM cards WHERE id COLLATE utf8mb4_unicode_ci IN ($ph)");
            $s->execute($allSkus);
            $cardRows = $s->fetchAll();
            $foundDirectly = [];
            foreach ($cardRows as $cr) {
                $foundDirectly[$cr['id']] = $cr;
            }
            // 2) Поиск через analogs JSON (SKU упомянут в массиве аналогов другой карточки)
            $notFound = array_diff($allSkus, array_keys($foundDirectly));
            $foundViaAnalogs = [];
            if (!empty($notFound)) {
                $s = $pdo->prepare("SELECT id, name, analogs FROM cards WHERE analogs IS NOT NULL");
                $s->execute();
                while ($cr = $s->fetch()) {
                    $analogs = json_decode($cr['analogs'], true);
                    if (!is_array($analogs)) continue;
                    foreach ($notFound as $sku) {
                        if (in_array($sku, $analogs) || in_array((string)$sku, $analogs)) {
                            $foundViaAnalogs[$sku] = $cr;
                        }
                    }
                }
            }
            // 3) Для найденных карточек — найти аналоги в products
            $allCardSkus = [];
            foreach ($foundDirectly + $foundViaAnalogs as $sku => $cr) {
                $analogs = json_decode($cr['analogs'], true) ?: [];
                $analogs[] = $cr['id']; // сама карточка тоже может быть в products
                foreach ($analogs as $a) $allCardSkus[] = (string)$a;
            }
            $allCardSkus = array_values(array_unique($allCardSkus));
            $productByCardSku = [];
            if (!empty($allCardSkus)) {
                $ph2 = implode(',', array_fill(0, count($allCardSkus), '?'));
                // Фильтруем по группе юрлиц рецепта, чтобы не затянуть карточку из чужой группы.
                $s = $pdo->prepare("SELECT sku, analog_group, qty_per_box, unit_of_measure, supplier FROM products WHERE sku COLLATE utf8mb4_unicode_ci IN ($ph2) AND legal_entity_group = ?");
                $s->execute(array_merge($allCardSkus, [$group]));
                while ($pr = $s->fetch()) $productByCardSku[$pr['sku']] = $pr;
            }
            // 4) Связать: recipe_sku → card → analog_skus → product
            foreach ($foundDirectly + $foundViaAnalogs as $origSku => $cr) {
                $analogs = json_decode($cr['analogs'], true) ?: [];
                $analogs[] = $cr['id'];
                foreach ($analogs as $a) {
                    if (isset($productByCardSku[(string)$a])) {
                        $pr = $productByCardSku[(string)$a];
                        $cardAnalogMap[$origSku] = [
                            'analog_group' => $pr['analog_group'],
                            'qty_per_box' => $pr['qty_per_box'],
                            'product_unit' => $pr['unit_of_measure'],
                            'resolved_sku' => $pr['sku'],
                            'supplier' => $pr['supplier'],
                        ];
                        break;
                    }
                }
                // Если не нашли в products — хотя бы имя карточки как группу
                if (!isset($cardAnalogMap[$origSku])) {
                    $cardAnalogMap[$origSku] = [
                        'analog_group' => $cr['name'],
                        'qty_per_box' => null,
                        'product_unit' => null,
                        'resolved_sku' => null,
                    ];
                }
            }
        }

        // Применить найденные аналоги к ингредиентам
        $result = [];
        foreach ($recipes as $r) {
            $ings = $recipeIngs[$r['id']] ?? [];
            foreach ($ings as &$ing) {
                if ($ing['sku'] && !$ing['analog_group'] && isset($cardAnalogMap[$ing['sku']])) {
                    $resolved = $cardAnalogMap[$ing['sku']];
                    $ing['analog_group'] = $resolved['analog_group'];
                    if (!$ing['qty_per_box'] && $resolved['qty_per_box']) $ing['qty_per_box'] = $resolved['qty_per_box'];
                    if (!$ing['product_unit'] && $resolved['product_unit']) $ing['product_unit'] = $resolved['product_unit'];
                    if ($resolved['resolved_sku']) {
                        $ing['original_sku'] = $ing['sku'];
                        $ing['sku'] = $resolved['resolved_sku'];
                    }
                    if (!empty($resolved['supplier'])) $ing['product_supplier'] = $resolved['supplier'];
                }
            }
            unset($ing);
            $r['ingredients'] = $ings;
            $result[] = $r;
        }

        respond(['recipes' => $result]);
    }

    // ═══ Маркетинг: рассчитать доли блюд по реализации ═══
    if ($fn === 'calc_dish_shares') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $recipeIds = $body['recipe_ids'] ?? [];
        $legalEntity = $body['legal_entity'] ?? null;
        if (empty($recipeIds)) respond(['error' => 'Не указаны блюда'], 400);
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $ph = implode(',', array_fill(0, count($recipeIds), '?'));

        // Загрузить ингредиенты всех блюд → найти analog_group для каждого
        $recipeAGs = []; // recipe_id → [analog_group, ...]
        $allAGs = []; // все analog_group всех блюд
        $s = $pdo->prepare("SELECT ri.recipe_id, p.analog_group
            FROM recipe_ingredients ri
            JOIN products p ON p.sku COLLATE utf8mb4_unicode_ci = ri.sku COLLATE utf8mb4_unicode_ci
            WHERE ri.recipe_id IN ($ph) AND p.analog_group IS NOT NULL");
        $s->execute($recipeIds);
        while ($row = $s->fetch()) {
            $recipeAGs[$row['recipe_id']][] = $row['analog_group'];
            $allAGs[] = $row['analog_group'];
        }

        // Найти уникальные ингредиенты для каждого блюда (которые есть только в одном блюде из списка)
        $agCount = array_count_values($allAGs); // сколько раз каждый AG встречается (в разных рецептах)
        // Подсчёт: сколько РАЗНЫХ рецептов содержат этот AG
        $agRecipeCount = [];
        foreach ($recipeAGs as $rid => $ags) {
            foreach (array_unique($ags) as $ag) {
                $agRecipeCount[$ag] = ($agRecipeCount[$ag] ?? 0) + 1;
            }
        }

        // Для каждого блюда: найти уникальный ингредиент → его реализацию
        $s2 = $pdo->prepare("SELECT id, name FROM recipes WHERE id IN ($ph)");
        $s2->execute($recipeIds);
        $recipes = $s2->fetchAll();

        $shares = [];
        $totalSales = 0;
        foreach ($recipes as $r) {
            $rid = $r['id'];
            $uniqueAGs = [];
            foreach (array_unique($recipeAGs[$rid] ?? []) as $ag) {
                if (($agRecipeCount[$ag] ?? 0) === 1) $uniqueAGs[] = $ag; // уникальный для этого блюда
            }

            $qty = 0;
            // Ищем реализацию уникальных ингредиентов в restaurant_sales (по группе юрлиц)
            if (!empty($uniqueAGs)) {
                $ph3 = implode(',', array_fill(0, count($uniqueAGs), '?'));
                $s = $pdo->prepare("SELECT SUM(quantity) as qty FROM restaurant_sales WHERE analog_group IN ($ph3) AND legal_entity_group = ?");
                $s->execute(array_merge($uniqueAGs, [getEntityGroup($legalEntity)]));
                $qty = floatval($s->fetchColumn() ?: 0);
            }
            // Fallback: analysis_data (тоже по юрлицу)
            if ($qty <= 0 && !empty($uniqueAGs)) {
                $ph3 = implode(',', array_fill(0, count($uniqueAGs), '?'));
                $s = $pdo->prepare("SELECT SUM(ad.consumption) as qty FROM analysis_data ad JOIN products p ON p.sku = ad.sku WHERE p.analog_group IN ($ph3) AND ad.legal_entity = ?");
                $s->execute(array_merge($uniqueAGs, [$legalEntity]));
                $qty = floatval($s->fetchColumn() ?: 0);
            }

            $shares[] = ['recipe_id' => intval($rid), 'name' => $r['name'], 'sales' => $qty, 'unique_ingredients' => $uniqueAGs];
            $totalSales += $qty;
        }

        foreach ($shares as &$sh) {
            $sh['share'] = $totalSales > 0 ? round($sh['sales'] / $totalSales, 4) : round(1 / count($shares), 4);
        }
        unset($sh);
        respond(['shares' => $shares, 'total_sales' => $totalSales]);
    }

    // ═══ Рецептуры: группы по категориям (сначала ручные, потом по префиксу) ═══
    if ($fn === 'get_recipe_groups') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $prefixes = $body['prefixes'] ?? [];
        $legalEntity = $body['legal_entity'] ?? null;
        if (empty($prefixes)) respond(['error' => 'Не указаны префиксы'], 400);
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);

        $group = getEntityGroup($legalEntity);

        // Загрузить все ручные группы с ключевыми словами (только этого юрлица)
        $s = $pdo->prepare("SELECT id, name, keywords FROM recipe_groups WHERE legal_entity_group = ?");
        $s->execute([$group]);
        $allGroups = $s->fetchAll(PDO::FETCH_ASSOC);

        // Нормализация для сравнения: lowercase, убрать лишние пробелы, пробелы вокруг точек/запятых
        function normGroupKey($s) {
            $s = mb_strtolower(trim($s));
            $s = preg_replace('/\s+/', ' ', $s);           // множественные пробелы → один
            $s = preg_replace('/\s*([.,])\s*/', '$1', $s); // убрать пробелы вокруг . и ,
            return $s;
        }

        $result = [];
        foreach ($prefixes as $prefix) {
            $prefix = trim($prefix);
            if (!$prefix) continue;
            $prefixNorm = normGroupKey($prefix);

            // 1. Ищем ручную группу: имя или ключевые слова (нормализованное сравнение)
            $matchedGroup = null;
            foreach ($allGroups as $g) {
                if (normGroupKey($g['name']) === $prefixNorm) { $matchedGroup = $g; break; }
                $kw = json_decode($g['keywords'] ?: '[]', true);
                if (is_array($kw)) {
                    foreach ($kw as $k) {
                        if (normGroupKey($k) === $prefixNorm) { $matchedGroup = $g; break 2; }
                    }
                }
            }

            if ($matchedGroup) {
                // Вернуть рецептуры из ручной группы (рецепты тоже фильтруем по юрлицу)
                $s = $pdo->prepare("SELECT r.id, r.code, r.name FROM recipe_group_items gi JOIN recipes r ON r.id = gi.recipe_id WHERE gi.group_id = ? AND r.legal_entity_group = ? ORDER BY r.name");
                $s->execute([$matchedGroup['id'], $group]);
                $result[$prefix] = $s->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Автоматический подбор по префиксу
                $s = $pdo->prepare("SELECT id, code, name FROM recipes WHERE name LIKE ? AND legal_entity_group = ? ORDER BY name");
                $s->execute([$prefix . '%', $group]);
                $result[$prefix] = $s->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        respond($result);
    }

    // ═══ Рецептуры: управление ручными группами ═══
    if ($fn === 'save_recipe_group') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'marketing', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = $body['id'] ?? null;
        $name = trim($body['name'] ?? '');
        $keywords = $body['keywords'] ?? [];
        $recipeIds = $body['recipe_ids'] ?? [];
        $legalEntity = $body['legal_entity'] ?? null;
        if (!$name) respond(['error' => 'Укажите название группы'], 400);
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);

        $group = getEntityGroup($legalEntity);

        $pdo->beginTransaction();
        try {
            if ($id) {
                // Проверяем, что группа принадлежит этому юрлицу
                $chk = $pdo->prepare("SELECT legal_entity_group FROM recipe_groups WHERE id = ?");
                $chk->execute([$id]);
                $existing = $chk->fetchColumn();
                if ($existing && $existing !== $group) {
                    $pdo->rollBack();
                    respond(['error' => 'Группа принадлежит другому юрлицу'], 403);
                }
                $pdo->prepare("UPDATE recipe_groups SET name=?, keywords=? WHERE id=?")->execute([$name, json_encode($keywords, JSON_UNESCAPED_UNICODE), $id]);
                $pdo->prepare("DELETE FROM recipe_group_items WHERE group_id=?")->execute([$id]);
            } else {
                $pdo->prepare("INSERT INTO recipe_groups (name, keywords, legal_entity_group) VALUES (?, ?, ?)")->execute([$name, json_encode($keywords, JSON_UNESCAPED_UNICODE), $group]);
                $id = $pdo->lastInsertId();
            }
            if (!empty($recipeIds)) {
                $ins = $pdo->prepare("INSERT INTO recipe_group_items (group_id, recipe_id) VALUES (?, ?)");
                foreach ($recipeIds as $rid) { $ins->execute([$id, $rid]); }
            }
            $pdo->commit();
            respond(['ok' => true, 'id' => intval($id)]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            respond(['error' => 'Ошибка сохранения группы'], 500);
        }
    }

    if ($fn === 'delete_recipe_group') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'marketing', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = $body['id'] ?? null;
        if (!$id) respond(['error' => 'Не указан ID'], 400);
        $accCheck = $pdo->prepare("SELECT legal_entity_group FROM recipe_groups WHERE id = ?");
        $accCheck->execute([$id]);
        $rgGroup = $accCheck->fetchColumn();
        if ($rgGroup === false) respond(['error' => 'Группа не найдена'], 404);
        if (!checkLegalEntityGroupAccess($caller, $rgGroup)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        $pdo->prepare("DELETE FROM recipe_groups WHERE id=?")->execute([$id]);
        respond(['ok' => true]);
    }

    if ($fn === 'get_recipe_groups_list') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $legalEntity = $_GET['legal_entity'] ?? $body['legal_entity'] ?? null;
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $group = getEntityGroup($legalEntity);
        $s = $pdo->prepare("SELECT g.id, g.name, g.keywords, COUNT(gi.id) as recipe_count FROM recipe_groups g LEFT JOIN recipe_group_items gi ON gi.group_id = g.id WHERE g.legal_entity_group = ? GROUP BY g.id ORDER BY g.name");
        $s->execute([$group]);
        $groups = $s->fetchAll(PDO::FETCH_ASSOC);
        // Один пакетный запрос вместо N+1: подтягиваем все рецепты всех групп
        // и раскладываем по group_id в PHP.
        $byGroup = [];
        if (!empty($groups)) {
            $groupIds = array_column($groups, 'id');
            $gph = implode(',', array_fill(0, count($groupIds), '?'));
            $sr = $pdo->prepare("SELECT gi.group_id, r.id, r.code, r.name
                FROM recipe_group_items gi
                JOIN recipes r ON r.id = gi.recipe_id
                WHERE gi.group_id IN ({$gph}) AND r.legal_entity_group = ?
                ORDER BY r.name");
            $sr->execute(array_merge($groupIds, [$group]));
            foreach ($sr->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $gid = $row['group_id'];
                unset($row['group_id']);
                $byGroup[$gid][] = $row;
            }
        }
        foreach ($groups as &$g) {
            $g['recipes'] = $byGroup[$g['id']] ?? [];
            $g['keywords'] = json_decode($g['keywords'] ?: '[]', true);
        }
        unset($g);
        respond($groups);
    }

    // ═══ Паллетовка: импорт справочника ═══
    if ($fn === 'import_pallet_reference') {
        requireModuleAccess($authUser, 'pallet-storage', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $items = $body['items'] ?? [];
        $legalEntity = $body['legal_entity'] ?? null;
        if (empty($items)) respond(['error' => 'Нет данных'], 400);
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($authUser, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $group = getEntityGroup($legalEntity);
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("INSERT INTO pallet_reference (legal_entity_group, name, storage_category, sku, pieces_per_block, blocks_per_box, boxes_per_pallet, pieces_per_pallet, box_length_mm, box_height_mm, box_width_mm, pallet_height_m, cell_coefficient)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE storage_category=VALUES(storage_category), sku=VALUES(sku), pieces_per_block=VALUES(pieces_per_block), blocks_per_box=VALUES(blocks_per_box), boxes_per_pallet=VALUES(boxes_per_pallet), pieces_per_pallet=VALUES(pieces_per_pallet), box_length_mm=VALUES(box_length_mm), box_height_mm=VALUES(box_height_mm), box_width_mm=VALUES(box_width_mm), pallet_height_m=VALUES(pallet_height_m), cell_coefficient=VALUES(cell_coefficient)");
            $count = 0;
            foreach ($items as $it) {
                $name = trim($it['name'] ?? '');
                if (!$name) continue;
                $L = intval($it['box_length_mm'] ?? 0);
                $H = intval($it['box_height_mm'] ?? 0);
                $W = intval($it['box_width_mm'] ?? 0);
                $bpp = intval($it['boxes_per_pallet'] ?? 0);
                $ppb = intval($it['pieces_per_block'] ?? 0);
                $bpb = intval($it['blocks_per_box'] ?? 1) ?: 1;
                $ppp = intval($it['pieces_per_pallet'] ?? 0);
                // Высота паллеты: (коробов_на_паллете × Д × В × Ш) / 10^9 / 0.96
                $palletH = ($bpp > 0 && $L > 0 && $H > 0 && $W > 0)
                    ? ($bpp * $L * $H * $W) / 1e9 / 0.96
                    : null;
                // Коэффициент ячейки
                $coeff = null;
                if ($palletH !== null) {
                    if ($palletH <= 0.30) $coeff = 0.25;
                    elseif ($palletH <= 0.85) $coeff = 0.5;
                    else $coeff = 1.0;
                }
                $st->execute([
                    $group,
                    $name, $it['storage_category'] ?? null, $it['sku'] ?? null,
                    $ppb ?: null, $bpb, $bpp ?: null, $ppp ?: null,
                    $L ?: null, $H ?: null, $W ?: null,
                    $palletH !== null ? round($palletH, 4) : null,
                    $coeff,
                ]);
                $count++;
            }
            $pdo->commit();
            respond(['ok' => true, 'count' => $count]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("import_pallet_reference error: " . $e->getMessage());
            respond(['error' => 'Ошибка импорта'], 500);
        }
    }

    // ═══ Паллетовка: загрузка справочника ═══
    if ($fn === 'get_pallet_reference') {
        requireModuleAccess($authUser, 'pallet-storage', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $legalEntity = $_GET['legal_entity'] ?? $body['legal_entity'] ?? null;
        if ($legalEntity && !checkLegalEntityAccess($authUser, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $group = $legalEntity ? getEntityGroup($legalEntity) : 'BK_VM';
        $st = $pdo->prepare("SELECT * FROM pallet_reference WHERE legal_entity_group = ? ORDER BY storage_category, name");
        $st->execute([$group]);
        respond($st->fetchAll(PDO::FETCH_ASSOC));
    }

    // ═══ Паллетовка: обновить поле (частота, кол-во коробок) ═══
    if ($fn === 'update_pallet_field') {
        requireModuleAccess($authUser, 'pallet-storage', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['id'] ?? 0);
        $field = $body['field'] ?? '';
        $value = $body['value'] ?? null;
        if (!$id) respond(['error' => 'Не указан ID'], 400);
        $allowed = ['delivery_frequency', 'incoming_boxes', 'input_unit'];
        if (!in_array($field, $allowed)) respond(['error' => 'Недопустимое поле'], 400);
        $pdo->prepare("UPDATE pallet_reference SET `$field` = ? WHERE id = ?")->execute([$value, $id]);
        respond(['ok' => true]);
    }

    // ═══ Паллетовка: расчёт заполненности ═══
    if ($fn === 'calc_pallet_occupancy') {
        requireModuleAccess($authUser, 'pallet-storage', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $legalEntity = $_GET['legal_entity'] ?? $body['legal_entity'] ?? null;
        if ($legalEntity && !checkLegalEntityAccess($authUser, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $group = $legalEntity ? getEntityGroup($legalEntity) : 'BK_VM';
        // Справочник — только выбранного юрлица
        $s = $pdo->prepare("SELECT * FROM pallet_reference WHERE legal_entity_group = ? ORDER BY storage_category, name");
        $s->execute([$group]);
        $ref = $s->fetchAll(PDO::FETCH_ASSOC);

        // Расчёт: справочник + ручной ввод коробок
        // Высота неполной паллеты пропорциональна заполнению
        function calcCoeff($h) {
            if ($h <= 0) return 0;
            if ($h <= 0.30) return 0.25;
            if ($h <= 0.85) return 0.5;
            return 1.0;
        }

        $results = [];
        foreach ($ref as $r) {
            $bpp = intval($r['boxes_per_pallet'] ?? 0) ?: intval($r['pieces_per_pallet'] ?? 0);
            $fullH = floatval($r['pallet_height_m'] ?? 0);
            $incomingBoxes = intval($r['incoming_boxes'] ?? 0);

            $freq = intval($r['delivery_frequency'] ?? 0);
            $inputUnit = $r['input_unit'] ?? 'boxes';
            $ppb = intval($r['pieces_per_block'] ?? 0);
            // Если ввод в штуках — пересчитать в коробки
            $totalBoxes = $incomingBoxes;
            if ($inputUnit === 'pieces' && $ppb > 0) {
                $totalBoxes = ceil($incomingBoxes / $ppb);
            }
            // Коробок за одну поставку: если указана частота — делим
            $boxesPerDelivery = ($freq > 1) ? ceil($totalBoxes / $freq) : $totalBoxes;

            $cells = 0;
            $actualH = 0;
            $actualCoeff = 0;

            if ($bpp > 0 && $boxesPerDelivery > 0) {
                $fullPallets = floor($boxesPerDelivery / $bpp);
                $remainder = $boxesPerDelivery % $bpp;

                if ($fullPallets > 0) {
                    // Есть полные паллеты → коэфф. 1 за каждую + неполная
                    $cells = $fullPallets * 1.0;
                    $actualH = $fullH;
                    $actualCoeff = 1.0;
                    if ($remainder > 0) {
                        $lastH = ($fullH > 0) ? $fullH * ($remainder / $bpp) : 0;
                        $cells += calcCoeff($lastH);
                    }
                } else {
                    // Только неполная паллета
                    $actualH = ($fullH > 0) ? $fullH * ($boxesPerDelivery / $bpp) : 0;
                    $actualCoeff = calcCoeff($actualH);
                    $cells = $actualCoeff;
                }
            }
            $totalPallets = ($bpp > 0 && $boxesPerDelivery > 0) ? $boxesPerDelivery / $bpp : 0;

            $results[] = [
                'ref_id' => intval($r['id']),
                'name' => $r['name'],
                'storage_category' => $r['storage_category'],
                'pieces_per_block' => intval($r['pieces_per_block'] ?? 0),
                'blocks_per_box' => intval($r['blocks_per_box'] ?? 1),
                'boxes_per_pallet' => $bpp,
                'box_length_mm' => intval($r['box_length_mm'] ?? 0),
                'box_height_mm' => intval($r['box_height_mm'] ?? 0),
                'box_width_mm' => intval($r['box_width_mm'] ?? 0),
                'incoming_boxes' => $incomingBoxes,
                'input_unit' => $inputUnit,
                'delivery_frequency' => $freq ?: null,
                'boxes_per_delivery' => $boxesPerDelivery,
                'pallets' => round($totalPallets, 2),
                'actual_height' => round($actualH, 4),
                'cell_coefficient' => $actualCoeff,
                'cells' => round($cells, 2),
                'delivery_frequency' => $r['delivery_frequency'],
            ];
        }
        respond($results);
    }

    // ═══ Рецептуры: поиск по именам (для автопривязки) ═══
    if ($fn === 'find_recipes_by_names') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $names = $body['names'] ?? [];
        $legalEntity = $body['legal_entity'] ?? '';
        if (empty($names)) respond(['error' => 'Не указаны имена'], 400);
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);

        // Загрузить все рецептуры группы пользователя (общие на BK_VM или PS).
        $group = getEntityGroup($legalEntity);
        $rs = $pdo->prepare("SELECT id, code, name FROM recipes WHERE legal_entity_group = ?");
        $rs->execute([$group]);
        $allRecipes = $rs->fetchAll(PDO::FETCH_ASSOC);

        // Построить индексы для быстрого поиска
        $byExact = [];       // точное совпадение (нижний регистр)
        $byNormalized = [];  // нормализованное (без точек, лишних пробелов, скобок)
        $allEntries = [];    // для нечёткого поиска

        function normalizeRecipeName($n) {
            $n = mb_strtolower(trim($n));
            $n = rtrim($n, '.');
            $n = preg_replace('/\s*\(.*?\)\s*/', ' ', $n); // убрать скобки
            $n = preg_replace('/\s+/', ' ', trim($n));      // лишние пробелы
            // Сокращения
            $n = str_replace(['мал.', 'бол.', 'газ.'], ['малый', 'большой', 'газированный'], $n);
            return $n;
        }

        foreach ($allRecipes as $r) {
            $lower = mb_strtolower(trim($r['name']));
            $norm = normalizeRecipeName($r['name']);
            $byExact[$lower] = $r;
            if (!isset($byNormalized[$norm])) $byNormalized[$norm] = $r;
            $allEntries[] = ['norm' => $norm, 'words' => preg_split('/\s+/', $norm), 'rec' => $r];
        }

        $result = [];
        foreach ($names as $name) {
            $name = trim($name);
            if (!$name) continue;
            $lower = mb_strtolower($name);
            $norm = normalizeRecipeName($name);

            // 1. Точное совпадение (без учёта регистра)
            if (isset($byExact[$lower])) { $result[$name] = $byExact[$lower]; continue; }

            // 2. Нормализованное совпадение
            if (isset($byNormalized[$norm])) { $result[$name] = $byNormalized[$norm]; continue; }

            // 3. Без точки / с точкой
            $noTrail = rtrim($lower, '.');
            if (isset($byExact[$noTrail])) { $result[$name] = $byExact[$noTrail]; continue; }
            if (isset($byExact[$noTrail . '.'])) { $result[$name] = $byExact[$noTrail . '.']; continue; }

            // 4. Поиск по вхождению (рецептура содержит запрос или наоборот)
            $found = null;
            $bestLen = PHP_INT_MAX;
            foreach ($allEntries as $e) {
                if (strpos($e['norm'], $norm) === 0) {
                    // Рецептура начинается с запроса — берём самую короткую (наиболее точную)
                    $len = mb_strlen($e['norm']);
                    if ($len < $bestLen) { $found = $e['rec']; $bestLen = $len; }
                }
            }
            if ($found) { $result[$name] = $found; continue; }

            // 5. Поиск по ключевым словам (все слова запроса содержатся в рецептуре)
            $queryWords = preg_split('/\s+/', $norm);
            $bestScore = 0;
            $bestMatch = null;
            foreach ($allEntries as $e) {
                $matched = 0;
                foreach ($queryWords as $qw) {
                    if (mb_strlen($qw) < 2) continue;
                    foreach ($e['words'] as $rw) {
                        if (strpos($rw, $qw) === 0 || strpos($qw, $rw) === 0) { $matched++; break; }
                    }
                }
                if ($matched === 0) continue;
                // Оценка: доля совпавших слов × штраф за лишние слова в рецептуре
                $score = $matched / max(count($queryWords), 1);
                $penalty = abs(count($e['words']) - count($queryWords));
                $score -= $penalty * 0.1;
                if ($score > $bestScore && $score >= 0.5) {
                    $bestScore = $score;
                    $bestMatch = $e['rec'];
                }
            }
            $result[$name] = $bestMatch;
        }
        respond(['recipes' => $result]);
    }

    // ═══ Баг-репорты: создать ═══
    // Универсальный caller для баг-репорта: закупка ИЛИ ресторан.
    // Возвращает ['name' => ..., 'role' => 'admin'|'user', 'legal_entity' => ?].
    // Для ресторана name = 'ro:<номер>', role = 'user' — отдельной адмки нет,
    // в /admin?tab=feedback закупка видит все обращения как админ.
    $bugReportCaller = function() use ($pdo) {
        $supply = getSessionUser($pdo);
        if ($supply) {
            return [
                'name' => $supply['name'] ?? 'unknown',
                'role' => $supply['role'] ?? 'user',
                'legal_entity' => '',
            ];
        }
        if (!function_exists('roGetRestaurantSession')) {
            require_once __DIR__ . '/restaurant_orders.php';
        }
        $ro = function_exists('roGetRestaurantSession') ? roGetRestaurantSession($pdo) : null;
        if ($ro) {
            $group = $ro['legal_entity_group'] ?? 'BK_VM';
            $le = $group === 'PS' ? 'ООО "Пицца Стар"' : 'ООО "Бургер БК"';
            return [
                'name' => 'ro:' . ($ro['restaurant_number'] ?? ''),
                'role' => 'user',
                'legal_entity' => $le,
            ];
        }
        return null;
    };

    if ($fn === 'create_bug_report') {
        $caller = $bugReportCaller();
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $title = trim($body['title'] ?? '');
        $description = trim($body['description'] ?? '');
        $screenshots = $body['screenshots'] ?? [];
        $actionLog = trim($body['action_log'] ?? '');
        $pageUrl = trim($body['page_url'] ?? '');
        // Юрлицо: у ресторана подставляем из сессии (фронту его передавать нечем),
        // у закупки уважаем то, что прислал фронт.
        $le = $caller['legal_entity'] !== '' ? $caller['legal_entity'] : ($body['legal_entity'] ?? '');
        $browserInfo = trim($body['browser_info'] ?? '');
        if (!$title) respond(['error' => 'Укажите тему сообщения'], 400);
        $stmt = $pdo->prepare("INSERT INTO bug_reports (title, description, screenshots, action_log, page_url, created_by, legal_entity, browser_info) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $description, json_encode($screenshots), $actionLog, $pageUrl, $caller['name'], $le, $browserInfo]);
        $id = $pdo->lastInsertId();
        respond(['success' => true, 'id' => intval($id)]);
    }

    // ═══ Баг-репорты: список (для админа — все, для юзера — свои) ═══
    if ($fn === 'get_bug_reports') {
        $caller = $bugReportCaller();
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
        $caller = $bugReportCaller();
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
        $caller = $bugReportCaller();
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
        $caller = $bugReportCaller();
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

    // Снэпшот цены закупки в позиции заказа. Берёт актуальную цену из
    // product_prices на момент сохранения и проставляет в каждую позицию.
    // Это нужно, чтобы исторические суммы заказов в дашборде/отчётах не
    // «ехали» при последующем изменении прайса.
    if (!function_exists('enrichItemsWithPrices')) {
        function enrichItemsWithPrices(PDO $pdo, array $items, string $supplier, string $legalEntity): array {
            $skus = array_values(array_filter(array_map(fn($i) => $i['sku'] ?? null, $items)));
            if (empty($skus) || !$supplier || !$legalEntity) return $items;
            $skus = array_values(array_unique($skus));
            $ph = implode(',', array_fill(0, count($skus), '?'));
            // Сначала пробуем точное совпадение supplier+legal_entity, затем
            // fallback на ту же legal_entity без supplier (если в прайсе записано
            // только по юрлицу). Берём самую свежую запись.
            // Цены живут на уровне группы юрлиц (BK_VM или PS).
            $group = getEntityGroup($legalEntity);
            $stmt = $pdo->prepare("
                SELECT sku, price, vat_rate, unit_type, currency
                FROM product_prices
                WHERE legal_entity_group = ? AND price_type = 'purchase' AND sku IN ($ph)
                ORDER BY (supplier = ?) DESC, updated_at DESC
            ");
            $stmt->execute(array_merge([$group], $skus, [$supplier]));
            $priceMap = [];
            foreach ($stmt->fetchAll() as $row) {
                if (!isset($priceMap[$row['sku']])) $priceMap[$row['sku']] = $row;
            }
            foreach ($items as &$item) {
                $sku = $item['sku'] ?? null;
                if ($sku && isset($priceMap[$sku])) {
                    $p = $priceMap[$sku];
                    $item['price']     = $p['price'];
                    $item['vat_rate']  = $p['vat_rate'];
                    $item['unit_type'] = $p['unit_type'];
                    $item['currency']  = $p['currency'];
                }
            }
            unset($item);
            return $items;
        }
    }

    // Нормализация числовых полей позиции заказа: защита от NaN/Infinity/
    // отрицательных значений и нелепо больших чисел. Раньше клиент мог
    // прислать qty_boxes=-5 или 1e308 — MySQL падал, либо сохранялся мусор.
    // ─ помещено единообразно: используется в create_order и update_order.
    if (!function_exists('sanitizeOrderItemNumbers')) {
        function sanitizeOrderItemNumbers(array $item): array {
            $clip = function ($v, $min, $max, $isInt) {
                if ($v === null || $v === '') return null;
                $n = is_numeric($v) ? (float)$v : 0.0;
                if (!is_finite($n)) $n = 0.0;
                if ($n < $min) $n = $min;
                if ($n > $max) $n = $max;
                return $isInt ? (int)$n : $n;
            };
            // qty_boxes: int 0..999_999
            if (array_key_exists('qty_boxes', $item))          $item['qty_boxes']          = $clip($item['qty_boxes'], 0, 999_999, true);
            if (array_key_exists('qty_per_box', $item))        $item['qty_per_box']        = $clip($item['qty_per_box'], 1, 999_999, true);
            if (array_key_exists('multiplicity', $item))       $item['multiplicity']       = $clip($item['multiplicity'], 1, 99_999, true);
            if (array_key_exists('boxes_per_pallet', $item))   $item['boxes_per_pallet']   = $clip($item['boxes_per_pallet'], 0, 99_999, true);
            if (array_key_exists('consumption_period', $item)) $item['consumption_period'] = $clip($item['consumption_period'], 0, 9_999_999, true);
            if (array_key_exists('stock', $item))              $item['stock']              = $clip($item['stock'], 0, 9_999_999, false);
            if (array_key_exists('transit', $item))            $item['transit']            = $clip($item['transit'], 0, 9_999_999, false);
            if (array_key_exists('final_order', $item))        $item['final_order']        = $clip($item['final_order'], 0, 9_999_999, false);
            if (array_key_exists('received_qty', $item))       $item['received_qty']       = $clip($item['received_qty'], 0, 9_999_999, false);
            // Снэпшот цены — защита от поддельных значений с фронта.
            if (array_key_exists('price', $item))               $item['price']              = $clip($item['price'], 0, 9_999_999, false);
            if (array_key_exists('vat_rate', $item))            $item['vat_rate']           = $clip($item['vat_rate'], 0, 100, false);
            return $item;
        }
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
        // Белый список полей позиции (price* — снэпшот цены на момент сохранения)
        $itemWhitelist = ['sku','name','qty_boxes','qty_per_box','boxes_per_pallet','multiplicity','consumption_period','stock','transit','final_order','manual_override','unit_of_measure','analog_group','category','sort_order','price','vat_rate','unit_type','currency'];
        // Снэпшотим цену закупки до начала транзакции — чтение прайса
        // не должно держать lock на orders.
        $items = enrichItemsWithPrices($pdo, $items, $order['supplier'] ?? '', $order['legal_entity'] ?? '');
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
                $item = sanitizeOrderItemNumbers($item);
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
        // Проверяем доступ к юрлицу заказа + статус приёмки.
        $orderCheck = $pdo->prepare("SELECT legal_entity, supplier, delivery_date, received_at FROM orders WHERE id=?");
        $orderCheck->execute([$orderId]);
        $orderRow = $orderCheck->fetch();
        if (!$orderRow) respond(['error' => 'Заказ не найден'], 404);
        if (!checkLegalEntityAccess($caller, $orderRow['legal_entity'])) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        // Принятый заказ редактировать нельзя — UI скрывает кнопки, но прямой
        // POST на update_order до этого фикса проходил.
        if (!empty($orderRow['received_at'])) {
            respond(['error' => 'Заказ уже принят, его нельзя редактировать'], 409);
        }
        $expectedUpdatedAt = $body['expected_updated_at'] ?? null;
        // Белый список полей заказа.
        // legal_entity убран — смена юрлица заказа портит данные (позиции
        // остаются от старой группы). Если действительно нужно — отдельный сценарий.
        $orderWhitelist = ['supplier','delivery_date','delivery_date_2','unit','note','details','cda_mode','safety_coef','today_date','safety_days','period_days','has_transit','show_stock_column'];
        $order = array_intersect_key($order, array_flip($orderWhitelist));
        $order['updated_at'] = date('Y-m-d H:i:s');
        // Белый список полей позиции (price* — снэпшот цены на момент сохранения)
        $itemWhitelist = ['sku','name','qty_boxes','qty_per_box','boxes_per_pallet','multiplicity','consumption_period','stock','transit','final_order','manual_override','unit_of_measure','received_qty','analog_group','category','sort_order','price','vat_rate','unit_type','currency'];
        $pdo->beginTransaction();
        try {
            // Блокируем строку и проверяем конкурентное редактирование внутри транзакции
            $lockS = $pdo->prepare("SELECT updated_at FROM orders WHERE id=? FOR UPDATE");
            $lockS->execute([$orderId]);
            $locked = $lockS->fetch();
            if ($expectedUpdatedAt && $locked['updated_at'] && $locked['updated_at'] !== $expectedUpdatedAt) {
                $pdo->rollBack();
                respond(['error' => 'Заказ был изменён другим пользователем'], 409);
            }
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
            // Заменяем позиции — сначала обогащаем актуальной ценой,
            // потом записываем. supplier для lookup берём из обновлённых
            // данных (если не пришёл — из текущего заказа).
            $items = enrichItemsWithPrices($pdo, $items, $order['supplier'] ?? ($orderRow['supplier'] ?? ''), $orderRow['legal_entity'] ?? '');
            $pdo->prepare("DELETE FROM `order_items` WHERE `order_id`=?")->execute([$orderId]);
            foreach ($items as $item) {
                $item = array_intersect_key($item, array_flip($itemWhitelist));
                $item = sanitizeOrderItemNumbers($item);
                $item['id'] = uuid();
                $item['order_id'] = $orderId;
                $cols = array_keys($item);
                $ph = implode(',', array_fill(0, count($cols), '?'));
                $cn = implode(',', array_map(fn($c) => "`$c`", $cols));
                $pdo->prepare("INSERT INTO `order_items` ($cn) VALUES ($ph)")->execute(array_values($item));
            }
            $pdo->commit();
            // Если поменялась дата доставки или поставщик — пересчитываем
            // существующий supplier_payment, иначе в дашборде оплат старая
            // payment_date/payment_due_date будут вечно.
            $supplierChanged = isset($order['supplier']) && $order['supplier'] !== ($orderRow['supplier'] ?? '');
            $deliveryChanged = isset($order['delivery_date']) && $order['delivery_date'] !== ($orderRow['delivery_date'] ?? '');
            if ($supplierChanged || $deliveryChanged) {
                try {
                    // Удаляем старый платёж — он будет пересоздан create_payment_if_needed.
                    $pdo->prepare("DELETE FROM supplier_payments WHERE order_id=?")->execute([$orderId]);
                    // Не вызываем сразу create_payment_if_needed, чтобы не дёргать RPC изнутри RPC.
                    // Достаточно того, что фронт после save вызовет его сам (PlanFactView),
                    // либо приёмщик при receive — также передёрнет платёж.
                } catch (PDOException $e) {
                    error_log("update_order: payment cleanup failed: " . $e->getMessage());
                }
            }
            respond(['success' => true, 'updated_at' => $order['updated_at']]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("update_order error: " . $e->getMessage());
            respond(['error' => 'Ошибка обновления заказа'], 500);
        }
    }


    // ═══ Распределение новинок (dist_*) ═══

    if ($fn === 'dist_get_sessions') {
        requireModuleAccess($authUser, 'distribution', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $legalEntity = $_GET['legal_entity'] ?? $body['legal_entity'] ?? null;
        if ($legalEntity && !checkLegalEntityAccess($authUser, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $group = $legalEntity ? getEntityGroup($legalEntity) : 'BK_VM';
        $s = $pdo->prepare("SELECT * FROM dist_sessions WHERE legal_entity_group = ? ORDER BY created_at DESC");
        $s->execute([$group]);
        respond($s->fetchAll());
    }

    if ($fn === 'dist_create_session') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $name = trim($body['name'] ?? '');
        $products = $body['products'] ?? [];
        $legalEntity = $body['legal_entity'] ?? null;
        if (!$name) respond(['error' => 'Название обязательно'], 400);
        if (empty($products)) respond(['error' => 'Добавьте хотя бы один товар'], 400);
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($authUser, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $group = getEntityGroup($legalEntity);
        $caller = getSessionUser($pdo);
        $s = $pdo->prepare("INSERT INTO dist_sessions (name, legal_entity_group, created_by) VALUES (?, ?, ?)");
        $s->execute([$name, $group, $caller['name'] ?? 'unknown']);
        $sessionId = $pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO dist_session_products (session_id, product_id, custom_name, custom_sku, default_qty, unit, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($products as $i => $p) {
            $productId = !empty($p['product_id']) ? $p['product_id'] : null;
            $customName = !empty($p['custom_name']) ? trim($p['custom_name']) : null;
            $customSku = !empty($p['custom_sku']) ? trim($p['custom_sku']) : null;
            $ins->execute([$sessionId, $productId, $customName, $customSku, $p['default_qty'] ?? 1, $p['unit'] ?? 'кор', $i]);
        }
        respond(['success' => true, 'session_id' => (int)$sessionId]);
    }

    if ($fn === 'dist_delete_session') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['session_id'] ?? 0);
        if (!$id) respond(['error' => 'session_id обязателен'], 400);
        $sg = $pdo->prepare("SELECT legal_entity_group FROM dist_sessions WHERE id=?"); $sg->execute([$id]);
        $sgVal = $sg->fetchColumn();
        if ($sgVal === false) respond(['error' => 'Сессия не найдена'], 404);
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        // CASCADE удалит session_products и entries
        $pdo->prepare("DELETE FROM dist_sessions WHERE id=?")->execute([$id]);
        respond(['success' => true]);
    }

    if ($fn === 'dist_close_session') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['session_id'] ?? 0);
        if (!$id) respond(['error' => 'session_id обязателен'], 400);
        $sg = $pdo->prepare("SELECT legal_entity_group FROM dist_sessions WHERE id=?"); $sg->execute([$id]);
        $sgVal = $sg->fetchColumn();
        if ($sgVal === false) respond(['error' => 'Сессия не найдена'], 404);
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        $pdo->prepare("UPDATE dist_sessions SET status='closed', closed_at=NOW() WHERE id=?")->execute([$id]);
        respond(['success' => true]);
    }

    if ($fn === 'dist_reopen_session') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['session_id'] ?? 0);
        if (!$id) respond(['error' => 'session_id обязателен'], 400);
        $sg = $pdo->prepare("SELECT legal_entity_group FROM dist_sessions WHERE id=?"); $sg->execute([$id]);
        $sgVal = $sg->fetchColumn();
        if ($sgVal === false) respond(['error' => 'Сессия не найдена'], 404);
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        $pdo->prepare("UPDATE dist_sessions SET status='active', closed_at=NULL WHERE id=?")->execute([$id]);
        respond(['success' => true]);
    }

    if ($fn === 'dist_get_session_data') {
        requireModuleAccess($authUser, 'distribution', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['session_id'] ?? 0);
        if (!$id) respond(['error' => 'session_id обязателен'], 400);
        // Сессия
        $s = $pdo->prepare("SELECT * FROM dist_sessions WHERE id=?");
        $s->execute([$id]);
        $session = $s->fetch();
        if (!$session) respond(['error' => 'Сессия не найдена'], 404);
        $sessionGroup = $session['legal_entity_group'] ?: 'BK_VM';
        if (!checkLegalEntityGroupAccess($authUser, $sessionGroup)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        // Товары сессии с данными из справочника
        $s = $pdo->prepare("SELECT sp.id, sp.product_id, sp.custom_name, sp.custom_sku, sp.default_qty, sp.unit, sp.sort_order,
            COALESCE(sp.custom_name, p.name) as product_name, COALESCE(sp.custom_sku, p.sku) as article, p.supplier
            FROM dist_session_products sp
            LEFT JOIN products p ON p.id = sp.product_id
            WHERE sp.session_id = ?
            ORDER BY sp.sort_order");
        $s->execute([$id]);
        $products = $s->fetchAll();
        $spIds = array_column($products, 'id');
        // Записи отгрузки
        $entries = [];
        if (!empty($spIds)) {
            $placeholders = implode(',', array_fill(0, count($spIds), '?'));
            $s = $pdo->prepare("SELECT * FROM dist_entries WHERE session_product_id IN ($placeholders)");
            $s->execute($spIds);
            $entries = $s->fetchAll();
        }
        // Рестораны — только той же группы, что и сессия
        $s = $pdo->prepare("SELECT id, number, address, city, region, legal_entity_group FROM restaurants WHERE active=1 AND legal_entity_group = ? ORDER BY CAST(number AS UNSIGNED)");
        $s->execute([$sessionGroup]);
        $restaurants = $s->fetchAll();
        // Дни доставки для каждого ресторана
        $restIds = array_column($restaurants, 'id');
        $deliveryDays = [];
        if (!empty($restIds)) {
            $ph = implode(',', array_fill(0, count($restIds), '?'));
            $s = $pdo->prepare("SELECT restaurant_id, day_of_week FROM delivery_schedule WHERE restaurant_id IN ($ph) ORDER BY day_of_week");
            $s->execute($restIds);
            foreach ($s->fetchAll() as $row) {
                $deliveryDays[$row['restaurant_id']][] = intval($row['day_of_week']);
            }
        }
        foreach ($restaurants as &$r) {
            $r['delivery_days'] = $deliveryDays[$r['id']] ?? [];
        }
        unset($r);
        // Примечания
        $s = $pdo->prepare("SELECT restaurant_number, note FROM dist_notes WHERE session_id=?");
        $s->execute([$id]);
        $notes = [];
        foreach ($s->fetchAll() as $n) $notes[$n['restaurant_number']] = $n['note'];
        respond([
            'session' => $session,
            'products' => $products,
            'entries' => $entries,
            'restaurants' => $restaurants,
            'notes' => $notes,
        ]);
    }

    if ($fn === 'dist_toggle_shipped') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $spId = intval($body['session_product_id'] ?? 0);
        $restNum = $body['restaurant_number'] ?? '';
        $shipped = isset($body['shipped']) ? (int)$body['shipped'] : 1;
        if (!$spId || !$restNum) respond(['error' => 'Не указан товар или ресторан'], 400);
        $sg = $pdo->prepare("SELECT s.legal_entity_group FROM dist_session_products sp JOIN dist_sessions s ON s.id=sp.session_id WHERE sp.id=?"); $sg->execute([$spId]);
        $sgVal = $sg->fetchColumn();
        if ($sgVal === false) respond(['error' => 'Позиция не найдена'], 404);
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        // Upsert
        $s = $pdo->prepare("INSERT INTO dist_entries (session_product_id, restaurant_number, shipped)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE shipped = VALUES(shipped), updated_at = NOW()");
        $s->execute([$spId, $restNum, $shipped]);
        respond(['success' => true]);
    }

    if ($fn === 'dist_update_qty') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $spId = intval($body['session_product_id'] ?? 0);
        $restNum = $body['restaurant_number'] ?? '';
        $qty = $body['qty'] ?? null;
        if (!$spId || !$restNum) respond(['error' => 'Не указан товар или ресторан'], 400);
        $sg = $pdo->prepare("SELECT s.legal_entity_group FROM dist_session_products sp JOIN dist_sessions s ON s.id=sp.session_id WHERE sp.id=?"); $sg->execute([$spId]);
        $sgVal = $sg->fetchColumn();
        if ($sgVal === false) respond(['error' => 'Позиция не найдена'], 404);
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        // Upsert
        $s = $pdo->prepare("INSERT INTO dist_entries (session_product_id, restaurant_number, qty)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE qty = VALUES(qty), updated_at = NOW()");
        $s->execute([$spId, $restNum, $qty]);
        respond(['success' => true]);
    }

    if ($fn === 'dist_add_products') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $sessionId = intval($body['session_id'] ?? 0);
        $products = $body['products'] ?? [];
        if (!$sessionId || empty($products)) respond(['error' => 'Нет данных'], 400);
        $sg = $pdo->prepare("SELECT legal_entity_group FROM dist_sessions WHERE id=?"); $sg->execute([$sessionId]);
        $sgVal = $sg->fetchColumn();
        if ($sgVal === false) respond(['error' => 'Сессия не найдена'], 404);
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        // Получаем текущий макс sort_order
        $s = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM dist_session_products WHERE session_id=?");
        $s->execute([$sessionId]);
        $maxOrder = (int)$s->fetchColumn();
        $ins = $pdo->prepare("INSERT INTO dist_session_products (session_id, product_id, custom_name, custom_sku, default_qty, unit, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($products as $i => $p) {
            $productId = !empty($p['product_id']) ? $p['product_id'] : null;
            $customName = !empty($p['custom_name']) ? trim($p['custom_name']) : null;
            $customSku = !empty($p['custom_sku']) ? trim($p['custom_sku']) : null;
            $ins->execute([$sessionId, $productId, $customName, $customSku, $p['default_qty'] ?? 1, $p['unit'] ?? 'кор', $maxOrder + $i + 1]);
        }
        respond(['success' => true]);
    }

    if ($fn === 'dist_remove_product') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $spId = intval($body['session_product_id'] ?? 0);
        if (!$spId) respond(['error' => 'Не указан товар'], 400);
        $sg = $pdo->prepare("SELECT s.legal_entity_group FROM dist_session_products sp JOIN dist_sessions s ON s.id=sp.session_id WHERE sp.id=?"); $sg->execute([$spId]);
        $sgVal = $sg->fetchColumn();
        if ($sgVal === false) respond(['error' => 'Позиция не найдена'], 404);
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        $pdo->prepare("DELETE FROM dist_session_products WHERE id=?")->execute([$spId]);
        respond(['success' => true]);
    }

    if ($fn === 'dist_save_note') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $sessionId = intval($body['session_id'] ?? 0);
        $restNum = $body['restaurant_number'] ?? '';
        $note = trim($body['note'] ?? '');
        if (!$sessionId || !$restNum) respond(['error' => 'Нет данных'], 400);
        $sg = $pdo->prepare("SELECT legal_entity_group FROM dist_sessions WHERE id=?"); $sg->execute([$sessionId]);
        $sgVal = $sg->fetchColumn();
        if ($sgVal === false) respond(['error' => 'Сессия не найдена'], 404);
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        $s = $pdo->prepare("INSERT INTO dist_notes (session_id, restaurant_number, note)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE note = VALUES(note)");
        $s->execute([$sessionId, $restNum, $note]);
        respond(['success' => true]);
    }

    if ($fn === 'dist_bulk_toggle') {
        requireModuleAccess($authUser, 'distribution', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $spId = intval($body['session_product_id'] ?? 0);
        $restaurantNumbers = $body['restaurant_numbers'] ?? [];
        $shipped = isset($body['shipped']) ? (int)$body['shipped'] : 1;
        if (!$spId || empty($restaurantNumbers)) respond(['error' => 'Нет данных'], 400);
        $sg = $pdo->prepare("SELECT s.legal_entity_group FROM dist_session_products sp JOIN dist_sessions s ON s.id=sp.session_id WHERE sp.id=?"); $sg->execute([$spId]);
        $sgVal = $sg->fetchColumn();
        if ($sgVal === false) respond(['error' => 'Позиция не найдена'], 404);
        if (!checkLegalEntityGroupAccess($authUser, $sgVal)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        $ins = $pdo->prepare("INSERT INTO dist_entries (session_product_id, restaurant_number, shipped)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE shipped = VALUES(shipped), updated_at = NOW()");
        foreach ($restaurantNumbers as $rn) {
            $ins->execute([$spId, $rn, $shipped]);
        }
        respond(['success' => true]);
    }

    // ═══ Telegram Bot Admin ═══
    // Все tg_admin_* — только для роли admin: рассылки, webhook, отвязки, статистика.

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

    // ═══ Корректировки заказов ═══

    if ($fn === 'correction_take_batch') {
        requireModuleAccess($authUser, 'corrections', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $ids = $body['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) respond(['error' => 'Нет идентификаторов'], 400);
        $ids = array_values(array_filter(array_map('intval', $ids), function($v) { return $v > 0; }));
        if (!$ids) respond(['error' => 'Нет валидных id'], 400);

        $caller = getSessionUser($pdo);
        $callerName = $caller['name'] ?? 'unknown';
        $callerChatId = $caller['telegram_chat_id'] ?? null;

        // Проверка доступа к группе юр.лиц (берём по первой записи).
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $accSt = $pdo->prepare("SELECT DISTINCT legal_entity_group FROM order_corrections WHERE id IN ($ph)");
        $accSt->execute($ids);
        $groups = $accSt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($groups as $g) {
            if (!checkLegalEntityGroupAccess($caller, $g)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        }

        // Атомарно — только из статуса pending.
        $upd = $pdo->prepare("UPDATE order_corrections SET status = 'in_progress', reviewer_chat_id = ?, reviewer_name = ? WHERE id IN ($ph) AND status = 'pending'");
        $upd->execute(array_merge([$callerChatId, $callerName], $ids));
        $taken = $upd->rowCount();

        // Освежаем TG-сообщения у закупок — там перерисуются текст и кнопки.
        try {
            require_once __DIR__ . '/bot_rest.php';
            corrUpdateAllReviewMessages($pdo, $ids);
        } catch (\Throwable $e) {
            error_log('[correction_take_batch] tg refresh failed: ' . $e->getMessage());
        }

        auditLog($pdo, 'correction_taken', 'correction', implode(',', $ids), $callerName, ['count' => $taken]);
        respond(['success' => true, 'taken' => $taken]);
    }

    if ($fn === 'correction_review') {
        requireModuleAccess($authUser, 'corrections', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['id'] ?? 0);
        $action = $body['action'] ?? '';
        $comment = trim($body['comment'] ?? '');
        if (!$id || !in_array($action, ['approve', 'reject'])) respond(['error' => 'Неверные параметры'], 400);

        $caller = getSessionUser($pdo);
        $callerName = $caller['name'] ?? 'unknown';

        // Доступ — на уровне группы юрлиц (BK_VM или PS).
        $accCheck = $pdo->prepare("SELECT legal_entity_group FROM order_corrections WHERE id = ?");
        $accCheck->execute([$id]);
        $corrGroup = $accCheck->fetchColumn();
        if ($corrGroup === false) respond(['error' => 'Корректировка не найдена'], 404);
        if (!checkLegalEntityGroupAccess($caller, $corrGroup)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);

        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        $callerChatId = $caller['telegram_chat_id'] ?? null;
        $upd = $pdo->prepare("UPDATE order_corrections SET status = ?, reviewer_chat_id = ?, reviewer_name = ?, review_comment = ?, reviewed_at = NOW() WHERE id = ? AND status IN ('pending', 'in_progress')");
        $upd->execute([$newStatus, $callerChatId, $callerName, $comment ?: null, $id]);
        if ($upd->rowCount() === 0) respond(['error' => 'Уже обработано']);

        $corr = $pdo->prepare("SELECT * FROM order_corrections WHERE id = ?");
        $corr->execute([$id]);
        $c = $corr->fetch();
        if (!$c) respond(['error' => 'Не найдено'], 404);

        // Определяем батч: для кабинетных — по batch_uuid, для TG-старых — по (restaurant, date, chat_id).
        if (!empty($c['batch_uuid'])) {
            $batchSt = $pdo->prepare("SELECT id FROM order_corrections WHERE batch_uuid = ? ORDER BY id");
            $batchSt->execute([$c['batch_uuid']]);
        } else {
            $batchSt = $pdo->prepare("SELECT id FROM order_corrections WHERE restaurant_number = ? AND delivery_date = ? AND restaurant_chat_id = ? ORDER BY id");
            $batchSt->execute([$c['restaurant_number'], $c['delivery_date'], $c['restaurant_chat_id']]);
        }
        $batchIds = array_map('intval', $batchSt->fetchAll(PDO::FETCH_COLUMN));

        // Освежаем сообщения у закупок (это работает даже если батч ещё не закрыт целиком),
        // и пытаемся отправить итог ресторану (функция сама вернётся, если ещё есть pending/in_progress).
        try {
            require_once __DIR__ . '/bot_rest.php';
            if ($batchIds) {
                corrUpdateAllReviewMessages($pdo, $batchIds);
                corrSendResultToRestaurant($pdo, $batchIds, $callerName);
            }
        } catch (\Throwable $e) {
            error_log('[correction_review] notify failed: ' . $e->getMessage());
        }

        auditLog($pdo, 'correction_reviewed', 'correction', $id, $callerName, ['action' => $action, 'restaurant' => $c['restaurant_number'], 'product' => $c['product_name']]);
        respond(['success' => true]);
    }

    if ($fn === 'correction_review_batch') {
        requireModuleAccess($authUser, 'corrections', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $ids = $body['ids'] ?? [];
        $action = $body['action'] ?? '';
        $comment = trim($body['comment'] ?? '');
        if (empty($ids) || !is_array($ids) || !in_array($action, ['approve', 'reject'])) respond(['error' => 'Неверные параметры'], 400);

        $caller = getSessionUser($pdo);
        $callerName = $caller['name'] ?? 'unknown';
        $callerChatId = $caller['telegram_chat_id'] ?? null;
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';

        // Доступ — все корректировки в батче должны быть в группе юрлиц пользователя.
        $intIds = array_map('intval', $ids);
        $ph = implode(',', array_fill(0, count($intIds), '?'));
        $accStmt = $pdo->prepare("SELECT DISTINCT legal_entity_group FROM order_corrections WHERE id IN ({$ph})");
        $accStmt->execute($intIds);
        $groups = $accStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$groups) respond(['error' => 'Корректировки не найдены'], 404);
        foreach ($groups as $g) {
            if (!checkLegalEntityGroupAccess($caller, $g)) respond(['error' => 'Нет доступа к одной из корректировок'], 403);
        }

        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("UPDATE order_corrections SET status = ?, reviewer_chat_id = ?, reviewer_name = ?, review_comment = ?, reviewed_at = NOW() WHERE id IN ({$ph}) AND status IN ('pending', 'in_progress')");
            $upd->execute(array_merge([$newStatus, $callerChatId, $callerName, $comment ?: null], $intIds));
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(['error' => 'Ошибка обновления'], 500);
        }

        // Перерисовываем TG-сообщения у закупок и отправляем итог ресторану
        // через единую функцию (push + TG всем верифицированным сотрудникам).
        try {
            require_once __DIR__ . '/bot_rest.php';
            // Группируем по batch_uuid (для кабинетных), и по (restaurant, date, chat_id) — для TG-старых.
            $first = $pdo->prepare("SELECT batch_uuid, restaurant_number, delivery_date, restaurant_chat_id FROM order_corrections WHERE id = ?");
            $first->execute([intval($ids[0])]);
            $c = $first->fetch();
            if ($c) {
                if (!empty($c['batch_uuid'])) {
                    $batchSt = $pdo->prepare("SELECT id FROM order_corrections WHERE batch_uuid = ? ORDER BY id");
                    $batchSt->execute([$c['batch_uuid']]);
                } else {
                    $batchSt = $pdo->prepare("SELECT id FROM order_corrections WHERE restaurant_number = ? AND delivery_date = ? AND restaurant_chat_id = ? ORDER BY id");
                    $batchSt->execute([$c['restaurant_number'], $c['delivery_date'], $c['restaurant_chat_id']]);
                }
                $batchIds = array_map('intval', $batchSt->fetchAll(PDO::FETCH_COLUMN));
                if ($batchIds) {
                    corrUpdateAllReviewMessages($pdo, $batchIds);
                    corrSendResultToRestaurant($pdo, $batchIds, $callerName, $comment ?: null);
                }
            }
        } catch (\Throwable $e) {
            error_log('[correction_review_batch] notify failed: ' . $e->getMessage());
        }

        auditLog($pdo, 'correction_reviewed', 'correction', implode(',', $ids), $callerName, ['action' => $action, 'count' => count($ids)]);
        respond(['success' => true, 'updated' => count($ids)]);
    }

    if ($fn === 'correction_delete') {
        requireModuleAccess($authUser, 'corrections', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $ids = $body['ids'] ?? [];
        if (empty($ids)) respond(['error' => 'Нет ID'], 400);
        $ids = array_map('intval', $ids);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        // Доступ — все корректировки в батче должны быть в группе юрлиц пользователя.
        $accStmt = $pdo->prepare("SELECT DISTINCT legal_entity_group FROM order_corrections WHERE id IN ({$ph})");
        $accStmt->execute($ids);
        $groups = $accStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$groups) respond(['error' => 'Корректировки не найдены'], 404);
        foreach ($groups as $g) {
            if (!checkLegalEntityGroupAccess($authUser, $g)) respond(['error' => 'Нет доступа к одной из корректировок'], 403);
        }
        $pdo->prepare("DELETE FROM order_corrections WHERE id IN ({$ph})")->execute($ids);
        respond(['success' => true]);
    }

    if ($fn === 'correction_clear_all') {
        requireModuleAccess($authUser, 'corrections', 'full', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $pdo->exec("DELETE FROM order_corrections");
        auditLog($pdo, 'corrections_cleared', 'correction', null, $authUserName, ['scope' => 'all']);
        respond(['success' => true]);
    }

    if ($fn === 'correction_clear_processed') {
        requireModuleAccess($authUser, 'corrections', 'full', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $cnt = $pdo->exec("DELETE FROM order_corrections WHERE status IN ('approved', 'rejected')");
        auditLog($pdo, 'corrections_cleared', 'correction', null, $authUserName, ['scope' => 'processed', 'count' => $cnt]);
        respond(['success' => true, 'deleted' => $cnt]);
    }

    if ($fn === 'correction_get_settings') {
        // Telegram-настройки уведомлений других пользователей — только для тех,
        // кто работает с корректировками (не отдавать API-ключам и роли viewer).
        requireModuleAccess($authUser, 'corrections', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $st = $pdo->query("SELECT u.name, ts.correction_notifications FROM users u JOIN telegram_settings ts ON ts.user_name = u.name WHERE u.telegram_chat_id IS NOT NULL ORDER BY u.name");
        respond($st->fetchAll());
    }

    if ($fn === 'correction_toggle_notification') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $userName = $body['user_name'] ?? '';
        if (!$userName) respond(['error' => 'user_name required'], 400);
        // Менять можно только свои настройки или админу — чужие.
        if ($userName !== ($caller['name'] ?? '') && ($caller['role'] ?? '') !== 'admin') {
            respond(['error' => 'Нет прав менять чужие настройки'], 403);
        }
        $pdo->prepare("UPDATE telegram_settings SET correction_notifications = NOT correction_notifications WHERE user_name = ?")->execute([$userName]);
        respond(['success' => true]);
    }

    // ═══ Оплаты поставщиков ═══

    if ($fn === 'create_payment_if_needed') {
        // Раньше тут был жёсткий plan-fact:edit. Из-за этого пользователь
        // с правами order:edit, но без plan-fact:edit, при приёмке заказа
        // получал тихий 403 (фронт ловит .catch и не показывает) — платёж
        // молча не создавался. Теперь достаточно order:edit: создание
        // платежа — это побочный эффект приёмки заказа, не требует
        // отдельных прав на сам модуль оплат.
        requireModuleAccess($authUser, 'order', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $orderId = $body['order_id'] ?? '';
        // Опорная дата для отсрочки — теперь дата поставки. Принимаем delivery_date,
        // оставляем чтение ttn_date для совместимости со старыми клиентами.
        $deliveryDate = trim((string)($body['delivery_date'] ?? $body['ttn_date'] ?? ''));
        if (!$orderId) respond(['error' => 'order_id required'], 400);

        // Получаем заказ и поставщика
        $order = $pdo->prepare("SELECT o.id, o.supplier, o.legal_entity, o.created_by, o.delivery_date,
            (SELECT SUM(oi.qty_boxes * COALESCE(oi.price, pp.price, 0)) FROM order_items oi LEFT JOIN product_prices pp ON pp.sku = oi.sku AND pp.legal_entity_group = o.legal_entity_group AND pp.price_type = 'purchase' WHERE oi.order_id = o.id) as total_amount
            FROM orders o WHERE o.id = ?");
        $order->execute([$orderId]);
        $o = $order->fetch();
        if (!$o) respond(['skip' => true]); // заказ не найден

        if (!$deliveryDate) {
            $deliveryDate = trim((string)($o['delivery_date'] ?? ''));
        }
        if (!$deliveryDate) respond(['skip' => true, 'reason' => 'delivery_date_required']);

        // Проверяем поставщика — российский + есть отсрочка
        $sup = $pdo->prepare("SELECT country, payment_delay_days FROM suppliers WHERE short_name = ? AND legal_entity = ?");
        $sup->execute([$o['supplier'], $o['legal_entity']]);
        $s = $sup->fetch();
        if (!$s) {
            // Пробуем без legal_entity
            $sup2 = $pdo->prepare("SELECT country, payment_delay_days FROM suppliers WHERE short_name = ? LIMIT 1");
            $sup2->execute([$o['supplier']]);
            $s = $sup2->fetch();
        }
        if (!$s || $s['country'] !== 'RU' || !$s['payment_delay_days']) respond(['skip' => true]);

        // Проверяем нет ли уже оплаты
        $exists = $pdo->prepare("SELECT id FROM supplier_payments WHERE order_id = ?");
        $exists->execute([$orderId]);
        if ($exists->fetch()) respond(['skip' => true, 'reason' => 'already_exists']);

        $delayDays = intval($s['payment_delay_days']);
        $dDate = new DateTime($deliveryDate);

        // Дата окончания отсрочки
        $dueDate = clone $dDate;
        $dueDate->modify("+{$delayDays} days");

        // Ближайший ВТ(2) или ЧТ(4) до или на dueDate (отсрочка — крайний срок)
        $payDate = clone $dueDate;
        while (true) {
            $dow = (int)$payDate->format('N');
            if ($dow === 2 || $dow === 4) break; // ВТ или ЧТ
            $payDate->modify('-1 day');
        }

        // Дедлайн заявки: предыдущий день 15:00
        $deadline = clone $payDate;
        $deadline->modify('-1 day');
        $deadline->setTime(15, 0, 0);

        $ins = $pdo->prepare("INSERT INTO supplier_payments (order_id, supplier, legal_entity, delivery_date, payment_delay_days, payment_due_date, payment_date, request_deadline, amount, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([
            $orderId, $o['supplier'], $o['legal_entity'], $deliveryDate,
            $delayDays, $dueDate->format('Y-m-d'), $payDate->format('Y-m-d'),
            $deadline->format('Y-m-d H:i:s'),
            $o['total_amount'] ?: null,
            $o['created_by'],
        ]);

        respond(['success' => true, 'payment_id' => $pdo->lastInsertId(), 'payment_date' => $payDate->format('Y-m-d')]);
    }

    // Ручное создание оплаты — для случаев когда заказ не проходил через портал.
    // Принимает supplier, legal_entity, delivery_date, amount (опц.).
    // Расчёт payment_date / request_deadline — по той же логике что и
    // create_payment_if_needed (отсрочка из карточки поставщика, ближайший ВТ/ЧТ).
    if ($fn === 'create_manual_payment') {
        requireModuleAccess($authUser, 'plan-fact', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $supplier     = trim((string)($body['supplier'] ?? ''));
        $legalEntity  = trim((string)($body['legal_entity'] ?? ''));
        $deliveryDate = trim((string)($body['delivery_date'] ?? ''));
        $amount       = $body['amount'] ?? null;
        $note         = trim((string)($body['note'] ?? ''));

        if ($supplier === '')     respond(['error' => 'Не указан поставщик'], 400);
        if ($legalEntity === '')  respond(['error' => 'Не указано юрлицо'], 400);
        if ($deliveryDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
            respond(['error' => 'Не указана корректная дата прихода'], 400);
        }
        if (!checkLegalEntityAccess($authUser, $legalEntity)) {
            respond(['error' => 'Нет доступа к юрлицу'], 403);
        }

        // Берём поставщика и его отсрочку. Ищем по группе юрлиц, чтобы можно
        // было создать оплату от любого юрлица группы (карточка поставщика
        // обычно заведена под одно из них).
        $group = getEntityGroup($legalEntity);
        $sStmt = $pdo->prepare("
            SELECT country, payment_delay_days
            FROM suppliers
            WHERE legal_entity_group = ?
              AND (short_name = ? OR full_name = ?)
              AND is_active = 1
            ORDER BY (legal_entity = ?) DESC, id
            LIMIT 1
        ");
        $sStmt->execute([$group, $supplier, $supplier, $legalEntity]);
        $s = $sStmt->fetch();
        if (!$s) respond(['error' => 'Поставщик не найден в карточках'], 404);
        if (!$s['payment_delay_days']) {
            respond(['error' => 'У поставщика не указана отсрочка платежа — заполните в карточке'], 400);
        }

        $delayDays = intval($s['payment_delay_days']);
        $dDate = new DateTime($deliveryDate);
        $dueDate = clone $dDate;
        $dueDate->modify("+{$delayDays} days");

        // Ближайший ВТ(2) или ЧТ(4) до или на dueDate.
        $payDate = clone $dueDate;
        while (true) {
            $dow = (int)$payDate->format('N');
            if ($dow === 2 || $dow === 4) break;
            $payDate->modify('-1 day');
        }

        $deadline = clone $payDate;
        $deadline->modify('-1 day');
        $deadline->setTime(15, 0, 0);

        $amountVal = ($amount !== null && $amount !== '' && is_numeric($amount)) ? (float)$amount : null;

        $ins = $pdo->prepare("
            INSERT INTO supplier_payments
              (order_id, supplier, legal_entity, delivery_date, payment_delay_days,
               payment_due_date, payment_date, request_deadline, amount, note, created_by)
            VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $supplier,
            $legalEntity,
            $deliveryDate,
            $delayDays,
            $dueDate->format('Y-m-d'),
            $payDate->format('Y-m-d'),
            $deadline->format('Y-m-d H:i:s'),
            $amountVal,
            $note !== '' ? $note : null,
            $authUserName,
        ]);

        respond([
            'success' => true,
            'payment_id' => $pdo->lastInsertId(),
            'payment_date' => $payDate->format('Y-m-d'),
            'request_deadline' => $deadline->format('Y-m-d H:i:s'),
        ]);
    }

    if ($fn === 'update_payment') {
        requireModuleAccess($authUser, 'plan-fact', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'id required'], 400);
        // Доступ — на уровне юрлица платежа.
        $payCheck = $pdo->prepare("SELECT legal_entity FROM supplier_payments WHERE id = ?");
        $payCheck->execute([$id]);
        $payLe = $payCheck->fetchColumn();
        if ($payLe === false) respond(['error' => 'Платёж не найден'], 404);
        if ($payLe && !checkLegalEntityAccess($authUser, $payLe)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $allowed = ['amount', 'status', 'note', 'payment_date', 'delivery_date', 'request_deadline'];
        $sets = []; $params = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) {
                $sets[] = "`{$f}` = ?";
                $params[] = $body[$f];
            }
        }

        // Если изменилась дата прихода и фронт явно не задал payment_date —
        // пересчитываем payment_due_date / payment_date / request_deadline по
        // той же логике, что и create_payment_if_needed (отсрочка из карточки
        // поставщика, ближайший ВТ/ЧТ до dueDate, дедлайн = пред. день 15:00).
        if (array_key_exists('delivery_date', $body) && !array_key_exists('payment_date', $body)) {
            $newDelivery = trim((string)$body['delivery_date']);
            if ($newDelivery !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDelivery)) {
                $spStmt = $pdo->prepare("SELECT supplier, legal_entity, payment_delay_days FROM supplier_payments WHERE id = ?");
                $spStmt->execute([$id]);
                $sp = $spStmt->fetch();
                if ($sp) {
                    $delayDays = (int)$sp['payment_delay_days'];
                    // Если в платеже отсрочки нет (старая запись) — берём из карточки.
                    if (!$delayDays) {
                        $grp = getEntityGroup($sp['legal_entity']);
                        $supSt = $pdo->prepare("
                            SELECT payment_delay_days FROM suppliers
                            WHERE legal_entity_group = ?
                              AND (short_name = ? OR full_name = ?)
                              AND is_active = 1
                            ORDER BY (legal_entity = ?) DESC, id
                            LIMIT 1
                        ");
                        $supSt->execute([$grp, $sp['supplier'], $sp['supplier'], $sp['legal_entity']]);
                        $delayDays = (int)($supSt->fetchColumn() ?: 0);
                    }
                    if ($delayDays > 0) {
                        $dDate = new DateTime($newDelivery);
                        $dueDate = clone $dDate; $dueDate->modify("+{$delayDays} days");
                        $payDate = clone $dueDate;
                        while (true) {
                            $dow = (int)$payDate->format('N');
                            if ($dow === 2 || $dow === 4) break;
                            $payDate->modify('-1 day');
                        }
                        $deadline = clone $payDate;
                        $deadline->modify('-1 day');
                        $deadline->setTime(15, 0, 0);
                        $sets[] = "`payment_due_date` = ?";    $params[] = $dueDate->format('Y-m-d');
                        $sets[] = "`payment_date` = ?";        $params[] = $payDate->format('Y-m-d');
                        $sets[] = "`request_deadline` = ?";    $params[] = $deadline->format('Y-m-d H:i:s');
                        if (!array_key_exists('payment_delay_days', $body)) {
                            $sets[] = "`payment_delay_days` = ?"; $params[] = $delayDays;
                        }
                    }
                }
            }
        }
        $caller = getSessionUser($pdo);
        if (($body['status'] ?? '') === 'paid') {
            $sets[] = "paid_by = ?"; $params[] = $caller['name'] ?? 'unknown';
            $sets[] = "paid_at = NOW()";
        }
        if (($body['status'] ?? '') === 'requested') {
            $sets[] = "paid_by = ?"; $params[] = $caller['name'] ?? 'unknown';
        }
        if (empty($sets)) respond(['error' => 'nothing to update'], 400);
        $params[] = $id;
        $pdo->prepare("UPDATE supplier_payments SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        respond(['success' => true]);
    }

    if ($fn === 'dashboard_kpi') {
        requireModuleAccess($authUser, 'dashboard', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $period = $body['period'] ?? 'week';
        $le = $body['legal_entity'] ?? null;
        // Кэш на 30 секунд по ключу (period + legal_entity + actor).
        // Дашборд делает 14 запросов к большим таблицам — без кэша при каждом
        // открытии страницы серверу больно. 30 сек хватает чтобы цифры
        // не были устаревшими на глаз, но при перезагрузке страницы или
        // переходе между табами выдаёт мгновенно.
        $cacheKey = 'dashboard_kpi_' . $period . '_' . ($le ?: '*') . '_' . ($authUserName ?: '_');
        $cached = cacheGet($cacheKey, 30);
        if ($cached !== null) respond($cached);
        $days = ['week' => 7, 'month' => 30, 'quarter' => 90][$period] ?? 7;
        $from = date('Y-m-d', strtotime("-{$days} days"));
        $prevFrom = date('Y-m-d', strtotime("-" . ($days * 2) . " days"));
        // Если фронт прислал юрлицо — проверяем доступ и фильтруем по нему.
        // Если не передал и не admin — фильтруем по всем юрлицам пользователя.
        // Admin без юрлица видит всё.
        if ($le) {
            if (!checkLegalEntityAccess($authUser, $le)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
            $leAnd = " AND legal_entity = ?";
            $leAndAlias = " AND o.legal_entity = ?";
            $leArgs = [$le];
        } elseif (($authUser['role'] ?? '') !== 'admin') {
            $userEntities = $authUser['legal_entities'] ?? '';
            if (is_string($userEntities)) $userEntities = json_decode($userEntities, true) ?: [];
            if (!is_array($userEntities) || empty($userEntities)) respond(['error' => 'У пользователя нет привязанных юрлиц'], 403);
            $phLE = implode(',', array_fill(0, count($userEntities), '?'));
            $leAnd = " AND legal_entity IN ($phLE)";
            $leAndAlias = " AND o.legal_entity IN ($phLE)";
            $leArgs = array_values($userEntities);
        } else {
            $leAnd = '';
            $leAndAlias = '';
            $leArgs = [];
        }

        $curOrders = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE created_at_new >= ?" . $leAnd);
        $curOrders->execute(array_merge([$from], $leArgs));
        $ordersCount = intval($curOrders->fetchColumn());

        // Заказы прошлый период
        $prevOrders = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE created_at_new >= ? AND created_at_new < ?" . $leAnd);
        $prevOrders->execute(array_merge([$prevFrom, $from], $leArgs));
        $prevCount = intval($prevOrders->fetchColumn());
        $ordersDelta = $prevCount > 0 ? round(($ordersCount - $prevCount) / $prevCount * 100) : 0;

        // Сумма (из order_items * product_prices)
        $amtSt = $pdo->prepare("SELECT COALESCE(SUM(oi.qty_boxes * COALESCE(oi.price, pp.price, 0)), 0) as total
            FROM orders o JOIN order_items oi ON oi.order_id = o.id
            LEFT JOIN product_prices pp ON pp.sku = oi.sku AND pp.legal_entity_group = o.legal_entity_group AND pp.price_type = 'purchase'
            WHERE o.created_at_new >= ?" . $leAndAlias);
        $amtSt->execute(array_merge([$from], $leArgs));
        $totalAmount = floatval($amtSt->fetchColumn());

        $prevAmtSt = $pdo->prepare("SELECT COALESCE(SUM(oi.qty_boxes * COALESCE(oi.price, pp.price, 0)), 0)
            FROM orders o JOIN order_items oi ON oi.order_id = o.id
            LEFT JOIN product_prices pp ON pp.sku = oi.sku AND pp.legal_entity_group = o.legal_entity_group AND pp.price_type = 'purchase'
            WHERE o.created_at_new >= ? AND o.created_at_new < ?" . $leAndAlias);
        $prevAmtSt->execute(array_merge([$prevFrom, $from], $leArgs));
        $prevAmount = floatval($prevAmtSt->fetchColumn());
        $amountDelta = $prevAmount > 0 ? round(($totalAmount - $prevAmount) / $prevAmount * 100) : 0;

        // Выполнение поставок
        $totalDel = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE delivery_date >= ? AND delivery_date <= CURDATE()" . $leAnd);
        $totalDel->execute(array_merge([$from], $leArgs));
        $totalDeliveries = intval($totalDel->fetchColumn());
        $receivedDel = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE delivery_date >= ? AND delivery_date <= CURDATE() AND received_at IS NOT NULL" . $leAnd);
        $receivedDel->execute(array_merge([$from], $leArgs));
        $received = intval($receivedDel->fetchColumn());
        $deliveredPct = $totalDeliveries > 0 ? round($received / $totalDeliveries * 100) : 100;

        // Просроченные
        $overdueCntSt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE delivery_date < CURDATE() AND received_at IS NULL AND delivery_date >= ?" . $leAnd);
        $overdueCntSt->execute(array_merge([$from], $leArgs));
        $overdue = $overdueCntSt->fetchColumn();

        // Низкий запас
        $lowStockSt = $pdo->prepare("SELECT COUNT(*) FROM analysis_data WHERE consumption > 0 AND stock > 0 AND stock / (consumption / GREATEST(period_days, 1)) <= 3" . $leAnd);
        $lowStockSt->execute($leArgs);
        $lowStock = $lowStockSt->fetchColumn();

        // Корректировки и чаты — общие BK+VM by design (см. shared_tables_bk_vm),
        // фильтр по юрлицу не применяем.
        $corrPending = $pdo->query("SELECT COUNT(*) FROM order_corrections WHERE status = 'pending'")->fetchColumn();
        $chatUnread = $pdo->query("SELECT COUNT(*) FROM chat_messages cm JOIN chat_conversations cc ON cc.id = cm.conversation_id WHERE cm.is_read = 0 AND cm.direction = 'from_restaurant' AND cc.status = 'open'")->fetchColumn();

        // Оплаты
        $paymentsUpSt = $pdo->prepare("SELECT COUNT(*) FROM supplier_payments WHERE status IN ('upcoming', 'request_due')" . $leAnd);
        $paymentsUpSt->execute($leArgs);
        $paymentsUp = $paymentsUpSt->fetchColumn();

        // Топ поставщиков
        $topSt = $pdo->prepare("SELECT o.supplier, SUM(oi.qty_boxes * COALESCE(oi.price, pp.price, 0)) as total
            FROM orders o JOIN order_items oi ON oi.order_id = o.id
            LEFT JOIN product_prices pp ON pp.sku = oi.sku AND pp.legal_entity_group = o.legal_entity_group AND pp.price_type = 'purchase'
            WHERE o.created_at_new >= ?" . $leAndAlias . "
            GROUP BY o.supplier ORDER BY total DESC LIMIT 10");
        $topSt->execute(array_merge([$from], $leArgs));
        $topSuppliers = $topSt->fetchAll();

        // Просроченные заказы (детали)
        $overdueSql = "SELECT id, supplier, delivery_date, DATEDIFF(CURDATE(), delivery_date) as days_overdue
            FROM orders
            WHERE delivery_date < CURDATE() AND received_at IS NULL AND delivery_date >= ?" . $leAnd;
        $overdueArgs = array_merge([$from], $leArgs);
        $overdueSql .= " ORDER BY delivery_date LIMIT 10";
        $overdueSt = $pdo->prepare($overdueSql);
        $overdueSt->execute($overdueArgs);
        $overdueOrders = $overdueSt->fetchAll();

        // Ближайшие оплаты
        $paysSql = "SELECT id, supplier, payment_date, amount, currency FROM supplier_payments WHERE status IN ('upcoming','request_due')" . $leAnd . " ORDER BY payment_date LIMIT 5";
        $paysSt = $pdo->prepare($paysSql);
        $paysSt->execute($leArgs);
        $upcomingPayments = $paysSt->fetchAll();

        // Тендеры
        $tenSt = $pdo->prepare("SELECT COUNT(*) FROM tenders WHERE status = 'collecting'" . $leAnd);
        $tenSt->execute($leArgs);
        $activeTenders = intval($tenSt->fetchColumn());
        // Сборы остатков
        $collSt = $pdo->prepare("SELECT COUNT(*) FROM stock_collections WHERE status = 'active'" . $leAnd);
        $collSt->execute($leArgs);
        $activeCollections = intval($collSt->fetchColumn());

        $result = [
            'ordersCount' => $ordersCount, 'ordersDelta' => $ordersDelta,
            'totalAmount' => round($totalAmount, 0), 'amountDelta' => $amountDelta,
            'deliveredPct' => $deliveredPct, 'overdueCount' => intval($overdue),
            'lowStockCount' => intval($lowStock), 'correctionsPending' => intval($corrPending),
            'chatUnread' => intval($chatUnread), 'paymentsUpcoming' => intval($paymentsUp),
            'topSuppliers' => $topSuppliers,
            'overdueOrders' => $overdueOrders, 'upcomingPayments' => $upcomingPayments,
            'activeTenders' => $activeTenders, 'activeCollections' => $activeCollections,
        ];
        cacheSet($cacheKey, $result);
        respond($result);
    }

    if ($fn === 'dashboard_critical_stock') {
        requireModuleAccess($authUser, 'dashboard', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $le = $body['legal_entity'] ?? null;
        // Сборка фильтра: явное юрлицо → одно; не-admin без юрлица → его список;
        // admin без юрлица → без ограничения.
        $leWhere = '';
        $leArgs = [];
        if ($le) {
            if (!checkLegalEntityAccess($authUser, $le)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
            $leWhere = " AND a.legal_entity = ?";
            $leArgs = [$le];
        } elseif (($authUser['role'] ?? '') !== 'admin') {
            $userEntities = $authUser['legal_entities'] ?? '';
            if (is_string($userEntities)) $userEntities = json_decode($userEntities, true) ?: [];
            if (!is_array($userEntities) || empty($userEntities)) respond(['error' => 'У пользователя нет привязанных юрлиц'], 403);
            $phLE = implode(',', array_fill(0, count($userEntities), '?'));
            $leWhere = " AND a.legal_entity IN ($phLE)";
            $leArgs = array_values($userEntities);
        }
        $st = $pdo->prepare("SELECT a.sku, p.analog_group, ROUND(a.stock / (a.consumption / GREATEST(a.period_days, 1)), 1) as days_of_stock
            FROM analysis_data a
            JOIN products p ON p.sku = a.sku AND p.legal_entity = a.legal_entity AND p.is_active = 1
            WHERE a.consumption > 0 AND a.stock > 0 AND a.stock / (a.consumption / GREATEST(a.period_days, 1)) <= 5 {$leWhere}
            ORDER BY days_of_stock ASC LIMIT 30");
        $st->execute($leArgs);
        $rows = $st->fetchAll();
        // Группируем по analog_group, берём минимум
        $groups = [];
        foreach ($rows as $r) {
            $g = $r['analog_group'] ?: $r['sku'];
            if (!isset($groups[$g]) || $r['days_of_stock'] < $groups[$g]) $groups[$g] = floatval($r['days_of_stock']);
        }
        $result = [];
        foreach ($groups as $name => $days) {
            $result[] = ['analog_group' => $name, 'days_of_stock' => $days];
        }
        usort($result, fn($a, $b) => $a['days_of_stock'] <=> $b['days_of_stock']);
        respond(array_slice($result, 0, 20));
    }

    if ($fn === 'get_pending_tasks_all') {
        requireModuleAccess($authUser, 'protocols', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        // Не-админу — только задачи протоколов из его юрлиц.
        $leWhere = '';
        $leArgs = [];
        if (($authUser['role'] ?? '') !== 'admin') {
            $userEntities = $authUser['legal_entities'] ?? '';
            if (is_string($userEntities)) $userEntities = json_decode($userEntities, true) ?: [];
            if (!is_array($userEntities) || empty($userEntities)) respond([]);
            $phLE = implode(',', array_fill(0, count($userEntities), '?'));
            $leWhere = " AND p.legal_entity IN ($phLE)";
            $leArgs = array_values($userEntities);
        }
        $st = $pdo->prepare("SELECT d.id, d.text, d.responsible_person, d.deadline, d.status, p.topic, p.meeting_date
            FROM protocol_decisions d
            JOIN meeting_protocols p ON p.id = d.protocol_id
            WHERE d.status IN ('pending', 'overdue'){$leWhere}
            ORDER BY CASE WHEN d.deadline IS NULL THEN 1 ELSE 0 END, d.deadline ASC
            LIMIT 20");
        $st->execute($leArgs);
        respond($st->fetchAll());
    }

    if ($fn === 'get_user_tg_settings') {
        $userName = $body['user_name'] ?? '';
        if (!$userName) respond(['error' => 'user_name required'], 400);
        // Свои настройки видит сам пользователь, чужие — только admin.
        if (($authUser['role'] ?? '') !== 'admin' && $userName !== $authUserName) {
            respond(['error' => 'Нет доступа к настройкам другого пользователя'], 403);
        }
        $st = $pdo->prepare("SELECT daily_summary, psc_expiry, price_changed, overdue_delivery, data_updates, expiring_items, restaurant_sales, low_stock, correction_notifications, chat_notifications FROM telegram_settings WHERE user_name = ?");
        $st->execute([$userName]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        respond($row ?: []);
    }

    // ═══ Чат с ресторанами ═══

    if ($fn === 'chat_get_conversations') {
        requireModuleAccess($authUser, 'chat', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $status = $body['status'] ?? 'open';
        $legalEntity = $body['legal_entity'] ?? null;
        $entityGroup = $legalEntity ? getEntityGroup($legalEntity) : null;
        $where = ['cc.status = ?'];
        $params = [$status];
        if ($entityGroup) {
            $where[] = 'cc.legal_entity_group = ?';
            $params[] = $entityGroup;
        }
        $sql = "SELECT cc.*,
            (SELECT COUNT(*) FROM chat_messages cm WHERE cm.conversation_id = cc.id AND cm.is_read = 0 AND cm.direction = 'from_restaurant') as unread_count,
            (SELECT cm2.message_text FROM chat_messages cm2 WHERE cm2.conversation_id = cc.id ORDER BY cm2.id DESC LIMIT 1) as last_message
            FROM chat_conversations cc
            WHERE " . implode(' AND ', $where) . "
            ORDER BY cc.last_message_at DESC LIMIT 100";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        respond($st->fetchAll());
    }

    if ($fn === 'chat_get_messages') {
        requireModuleAccess($authUser, 'chat', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $convId = intval($body['conversation_id'] ?? 0);
        if (!$convId) respond(['error' => 'conversation_id required'], 400);
        // Доступ — на уровне группы юрлиц переписки.
        $accCheck = $pdo->prepare("SELECT legal_entity_group FROM chat_conversations WHERE id = ?");
        $accCheck->execute([$convId]);
        $convGroup = $accCheck->fetchColumn();
        if ($convGroup === false) respond(['error' => 'Диалог не найден'], 404);
        if (!checkLegalEntityGroupAccess($authUser, $convGroup)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        // Помечаем как прочитанные
        $pdo->prepare("UPDATE chat_messages SET is_read = 1 WHERE conversation_id = ? AND direction = 'from_restaurant' AND is_read = 0")->execute([$convId]);
        $st = $pdo->prepare("SELECT * FROM chat_messages WHERE conversation_id = ? ORDER BY created_at ASC LIMIT 500");
        $st->execute([$convId]);
        respond($st->fetchAll());
    }

    if ($fn === 'chat_send_message') {
        requireModuleAccess($authUser, 'chat', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $convId = intval($body['conversation_id'] ?? 0);
        $text = trim($body['message_text'] ?? '');
        if (!$convId || !$text) respond(['error' => 'conversation_id and message_text required'], 400);
        $caller = getSessionUser($pdo);
        $senderName = $caller['name'] ?? 'Закупки';

        // Доступ — на уровне группы юрлиц переписки.
        $accCheck = $pdo->prepare("SELECT legal_entity_group FROM chat_conversations WHERE id = ?");
        $accCheck->execute([$convId]);
        $convGroup = $accCheck->fetchColumn();
        if ($convGroup === false) respond(['error' => 'Диалог не найден'], 404);
        if (!checkLegalEntityGroupAccess($caller, $convGroup)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);

        $ins = $pdo->prepare("INSERT INTO chat_messages (conversation_id, direction, sender_name, message_text, is_read) VALUES (?, 'from_purchasing', ?, ?, 1)");
        $ins->execute([$convId, $senderName, $text]);
        $pdo->prepare("UPDATE chat_conversations SET last_message_at = NOW() WHERE id = ?")->execute([$convId]);

        // Отправляем в Telegram ресторану
        $conv = $pdo->prepare("SELECT restaurant_chat_id, restaurant_number FROM chat_conversations WHERE id = ?");
        $conv->execute([$convId]);
        $c = $conv->fetch();
        if ($c && $c['restaurant_chat_id']) {
            $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
            if ($botToken) {
                $rNum = htmlspecialchars((string)$c['restaurant_number'], ENT_QUOTES, 'UTF-8');
                $sName = htmlspecialchars((string)$senderName, ENT_QUOTES, 'UTF-8');
                $tgText = "📨 <b>Ответ от отдела закупок</b>\n";
                $tgText .= "🏪 Ресторан {$rNum}\n";
                $tgText .= "─────────────────────\n";
                $tgText .= htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . "\n";
                $tgText .= "─────────────────────\n";
                $tgText .= "<i>Ответил: {$sName}</i>";
                $payload = json_encode(['chat_id' => $c['restaurant_chat_id'], 'text' => $tgText, 'parse_mode' => 'HTML']);
                $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 5]);
                curl_exec($ch); curl_close($ch);
            }
        }
        respond(['success' => true, 'message_id' => $pdo->lastInsertId()]);
    }

    if ($fn === 'chat_close_conversation') {
        requireModuleAccess($authUser, 'chat', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $convId = intval($body['conversation_id'] ?? 0);
        if (!$convId) respond(['error' => 'conversation_id required'], 400);
        $caller = getSessionUser($pdo);
        $accCheck = $pdo->prepare("SELECT legal_entity_group FROM chat_conversations WHERE id = ?");
        $accCheck->execute([$convId]);
        $convGroup = $accCheck->fetchColumn();
        if ($convGroup === false) respond(['error' => 'Диалог не найден'], 404);
        if (!checkLegalEntityGroupAccess($caller, $convGroup)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        $pdo->prepare("UPDATE chat_conversations SET status = 'closed', closed_by = ?, closed_at = NOW() WHERE id = ?")
            ->execute([$caller['name'] ?? 'unknown', $convId]);
        respond(['success' => true]);
    }

    if ($fn === 'chat_reopen_conversation') {
        requireModuleAccess($authUser, 'chat', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $convId = intval($body['conversation_id'] ?? 0);
        if (!$convId) respond(['error' => 'conversation_id required'], 400);
        $accCheck = $pdo->prepare("SELECT legal_entity_group FROM chat_conversations WHERE id = ?");
        $accCheck->execute([$convId]);
        $convGroup = $accCheck->fetchColumn();
        if ($convGroup === false) respond(['error' => 'Диалог не найден'], 404);
        if (!checkLegalEntityGroupAccess($authUser, $convGroup)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        $pdo->prepare("UPDATE chat_conversations SET status = 'open', closed_by = NULL, closed_at = NULL WHERE id = ?")->execute([$convId]);
        respond(['success' => true]);
    }

    if ($fn === 'chat_unread_total') {
        requireModuleAccess($authUser, 'chat', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $cnt = $pdo->query("SELECT COUNT(*) FROM chat_messages cm JOIN chat_conversations cc ON cc.id = cm.conversation_id WHERE cm.is_read = 0 AND cm.direction = 'from_restaurant' AND cc.status = 'open'")->fetchColumn();
        respond(['count' => intval($cnt)]);
    }

    if ($fn === 'chat_send_photo') {
        requireModuleAccess($authUser, 'chat', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $convId = intval($_POST['conversation_id'] ?? $body['conversation_id'] ?? 0);
        if (!$convId) respond(['error' => 'conversation_id required'], 400);
        if (empty($_FILES['photo'])) respond(['error' => 'Файл не выбран'], 400);

        $caller = getSessionUser($pdo);
        $senderName = $caller['name'] ?? 'Закупки';

        $conv = $pdo->prepare("SELECT restaurant_chat_id, restaurant_number, legal_entity_group FROM chat_conversations WHERE id = ?");
        $conv->execute([$convId]);
        $c = $conv->fetch();
        if (!$c) respond(['error' => 'Диалог не найден'], 404);
        if (!checkLegalEntityGroupAccess($caller, $c['legal_entity_group'])) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);

        // Отправляем фото в Telegram ресторану и получаем file_id
        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        $photoFileId = null;
        if ($botToken && $c['restaurant_chat_id']) {
            $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendPhoto");
            $postData = [
                'chat_id' => $c['restaurant_chat_id'],
                'photo' => new CURLFile($_FILES['photo']['tmp_name'], $_FILES['photo']['type'], $_FILES['photo']['name']),
                'caption' => "📨 Фото от отдела закупок ({$senderName})",
            ];
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $postData, CURLOPT_TIMEOUT => 30]);
            $resp = json_decode(curl_exec($ch), true);
            curl_close($ch);
            if (isset($resp['result']['photo'])) {
                $photos = $resp['result']['photo'];
                $photoFileId = end($photos)['file_id'] ?? null;
            }
        }

        if (!$photoFileId) respond(['error' => 'Не удалось отправить фото'], 500);

        // Сохраняем в базу
        $ins = $pdo->prepare("INSERT INTO chat_messages (conversation_id, direction, sender_name, photo_file_id, is_read) VALUES (?, 'from_purchasing', ?, ?, 1)");
        $ins->execute([$convId, $senderName, $photoFileId]);
        $pdo->prepare("UPDATE chat_conversations SET last_message_at = NOW() WHERE id = ?")->execute([$convId]);

        respond(['success' => true, 'photo_file_id' => $photoFileId]);
    }

    if ($fn === 'chat_get_photo') {
        requireModuleAccess($authUser, 'chat', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $fileId = $body['file_id'] ?? ($_GET['file_id'] ?? '');
        if (!$fileId) respond(['error' => 'file_id required'], 400);
        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if (!$botToken) respond(['error' => 'Bot не настроен'], 500);
        $resp = tgHttpGet("https://api.telegram.org/bot{$botToken}/getFile?" . http_build_query(['file_id' => $fileId]));
        $data = json_decode($resp, true);
        $filePath = $data['result']['file_path'] ?? null;
        if (!$filePath) respond(['error' => 'File not found'], 404);
        // Скачиваем файл серверно — токен бота не уходит клиенту.
        $ch = curl_init("https://api.telegram.org/file/bot{$botToken}/" . $filePath);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false]);
        $bytes = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($bytes === false || $httpCode !== 200) respond(['error' => 'Не удалось загрузить фото'], 502);
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
        respond(['data_url' => 'data:' . $mime . ';base64,' . base64_encode($bytes)]);
    }

    // ═══ ПРОТОКОЛЫ СОВЕЩАНИЙ ═══

    // Подтянуть описание привязанной карточки задачника ко всем решениям,
    // у которых проставлен tasks_card_id. Объявлено ВЫШЕ обработчиков
    // get_protocol/get_carryover_tasks, потому что в PHP функции внутри
    // условного блока регистрируются только при достижении строки
    // объявления — иначе до неё вызов падает 500.
    function pdAttachCardDescription($pdo, &$decisions) {
        if (!is_array($decisions) || !$decisions) return;
        $cardIds = [];
        foreach ($decisions as $d) {
            $cid = isset($d['tasks_card_id']) ? (int)$d['tasks_card_id'] : 0;
            if ($cid) $cardIds[$cid] = true;
        }
        if (!$cardIds) {
            foreach ($decisions as &$d) $d['card_description'] = null;
            unset($d);
            return;
        }
        $ids = array_keys($cardIds);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $s = $pdo->prepare("SELECT id, description FROM tasks_cards WHERE id IN ($ph)");
        $s->execute($ids);
        $byCard = [];
        foreach ($s->fetchAll() as $r) $byCard[(int)$r['id']] = (string)($r['description'] ?? '');
        foreach ($decisions as &$d) {
            $cid = isset($d['tasks_card_id']) ? (int)$d['tasks_card_id'] : 0;
            $d['card_description'] = ($cid && isset($byCard[$cid]) && $byCard[$cid] !== '') ? $byCard[$cid] : null;
        }
        unset($d);
    }

    function pdAttachAssigneesProgress($pdo, &$decisions) {
        if (!is_array($decisions) || !$decisions) return;
        $decIds = [];
        foreach ($decisions as $d) {
            $id = isset($d['id']) ? (int)$d['id'] : 0;
            if ($id) $decIds[$id] = true;
        }
        if (!$decIds) {
            foreach ($decisions as &$d) { $d['assignees_progress'] = []; $d['card_id_for_me'] = null; }
            unset($d);
            return;
        }
        $ids = array_keys($decIds);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $s = $pdo->prepare("
            SELECT pdc.decision_id, pdc.user_name, pdc.card_id, c.is_done, c.description
            FROM protocol_decision_cards pdc
            JOIN tasks_cards c ON c.id = pdc.card_id
            WHERE pdc.decision_id IN ($ph)
            ORDER BY pdc.decision_id, pdc.created_at, pdc.card_id
        ");
        $s->execute($ids);
        $byDec = [];
        foreach ($s->fetchAll() as $r) {
            $did = (int)$r['decision_id'];
            $byDec[$did][] = [
                'user_name'   => $r['user_name'],
                'card_id'     => (int)$r['card_id'],
                'is_done'     => (int)$r['is_done'] === 1,
                'description' => (string)($r['description'] ?? ''),
            ];
        }
        $me = function_exists('getSessionUser') ? (getSessionUser($pdo)['name'] ?? null) : null;
        foreach ($decisions as &$d) {
            $did = isset($d['id']) ? (int)$d['id'] : 0;
            $list = $byDec[$did] ?? [];
            $d['assignees_progress'] = $list;
            $d['card_id_for_me'] = null;
            if ($me) {
                foreach ($list as $row) {
                    if ($row['user_name'] === $me) { $d['card_id_for_me'] = $row['card_id']; break; }
                }
            }
        }
        unset($d);
    }

    if ($fn === 'get_protocols') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'protocols', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $legalEntity = $body['legal_entity'] ?? $_GET['legal_entity'] ?? null;
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к юр. лицу'], 403);
        // Один JOIN с агрегатами вместо двух коррелированных подзапросов на каждую строку.
        // На 500 протоколах раньше было 1000 подзапросов к protocol_decisions.
        $s = $pdo->prepare("SELECT p.*,
                COALESCE(d.cnt, 0) AS decisions_count,
                COALESCE(d.done_cnt, 0) AS decisions_done,
                s.name as series_name
            FROM meeting_protocols p
            LEFT JOIN meeting_protocol_series s ON s.id = p.series_id
            LEFT JOIN (
                SELECT protocol_id, COUNT(*) AS cnt, SUM(status = 'done') AS done_cnt
                FROM protocol_decisions
                GROUP BY protocol_id
            ) d ON d.protocol_id = p.id
            WHERE p.legal_entity = ?
            ORDER BY p.meeting_date DESC, p.created_at DESC LIMIT 500");
        $s->execute([$legalEntity]);
        respond($s->fetchAll());
    }

    if ($fn === 'get_protocol') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'protocols', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'id required'], 400);
        $s = $pdo->prepare("SELECT p.*, s.name as series_name, s.recurrence, s.agenda_template FROM meeting_protocols p LEFT JOIN meeting_protocol_series s ON s.id = p.series_id WHERE p.id = ?");
        $s->execute([$id]);
        $proto = $s->fetch();
        if (!$proto) respond(['error' => 'Протокол не найден'], 404);
        if (!checkLegalEntityAccess($caller, $proto['legal_entity'] ?? null)) respond(['error' => 'Нет доступа'], 403);
        // Решения + последний комментарий из чата привязанной карточки.
        $d = $pdo->prepare("SELECT * FROM protocol_decisions WHERE protocol_id = ? ORDER BY id");
        $d->execute([$id]);
        $proto['decisions'] = $d->fetchAll();
        pdAttachCardDescription($pdo, $proto['decisions']);
        pdAttachAssigneesProgress($pdo, $proto['decisions']);
        // Файлы
        $f = $pdo->prepare("SELECT id, file_name, file_path, uploaded_by, uploaded_at FROM meeting_protocol_files WHERE protocol_id = ? ORDER BY uploaded_at");
        $f->execute([$id]);
        $proto['files'] = $f->fetchAll();
        respond($proto);
    }

    function pdResponsibleToUsers($pdo, $responsiblePerson) {
        $names = array_values(array_filter(array_map('trim', explode(',', (string)$responsiblePerson))));
        if (!$names) return [];
        $ph = implode(',', array_fill(0, count($names), '?'));
        $s = $pdo->prepare("SELECT name FROM users WHERE name IN ($ph)");
        $s->execute($names);
        $exist = array_column($s->fetchAll(), 'name');
        return array_values(array_filter($names, fn($n) => in_array($n, $exist, true)));
    }

    function pdEnsureUserBoard($pdo, $userName) {
        $s = $pdo->prepare("SELECT id FROM tasks_boards WHERE owner_name = ? AND is_archived = 0 ORDER BY sort_order, id LIMIT 1");
        $s->execute([$userName]);
        $bid = (int)$s->fetchColumn();
        if ($bid) return $bid;
        $pdo->prepare("INSERT INTO tasks_boards (owner_name, title) VALUES (?, ?)")->execute([$userName, $userName]);
        $bid = (int)$pdo->lastInsertId();
        $cols = [
            ['Сделать',  '#90A4AE',    0, 0, 0],
            ['В работе', '#FFA726',    1, 0, 0],
            ['Архив',    '#9E9E9E', 9999, 0, 1],
        ];
        $ins = $pdo->prepare("INSERT INTO tasks_columns (board_id, title, color, sort_order, is_done_column, is_archive_column) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($cols as $c) $ins->execute([$bid, $c[0], $c[1], $c[2], $c[3], $c[4]]);
        return $bid;
    }

    function pdSyncDecisionToCard($pdo, $decId, $createdBy = 'system') {
        $s = $pdo->prepare("SELECT pd.id, pd.text, pd.responsible_person, pd.deadline, pd.status, pd.protocol_id, p.topic, p.meeting_date FROM protocol_decisions pd JOIN meeting_protocols p ON p.id = pd.protocol_id WHERE pd.id = ?");
        $s->execute([$decId]);
        $dec = $s->fetch();
        if (!$dec) return;
        $users = pdResponsibleToUsers($pdo, $dec['responsible_person']);
        $title = mb_substr((string)$dec['text'], 0, 255);
        $cardDue = $dec['deadline'] ? ($dec['deadline'] . ' 23:59:59') : null;
        $isDone = $dec['status'] === 'done' ? 1 : 0;
        $isArchived = $isDone ? 1 : 0;
        $completedAt = $isDone ? date('Y-m-d H:i:s') : null;
        $entityLabel = mb_substr('Протокол: ' . ($dec['topic'] ?? '') . ' от ' . ($dec['meeting_date'] ?? ''), 0, 255);

        $existingQ = $pdo->prepare("SELECT card_id, user_name FROM protocol_decision_cards WHERE decision_id = ?");
        $existingQ->execute([$decId]);
        $existing = [];
        foreach ($existingQ->fetchAll() as $r) $existing[$r['user_name']] = (int)$r['card_id'];

        $toCreate = array_diff($users, array_keys($existing));
        $toRemove = array_diff(array_keys($existing), $users);

        foreach ($toRemove as $userName) {
            $cardId = $existing[$userName];
            $bRes = $pdo->prepare("SELECT board_id FROM tasks_cards WHERE id = ?");
            $bRes->execute([$cardId]);
            $boardId = (int)$bRes->fetchColumn();
            if ($boardId) {
                $arc = $pdo->prepare("SELECT id FROM tasks_columns WHERE board_id = ? AND is_archive_column = 1 ORDER BY sort_order LIMIT 1");
                $arc->execute([$boardId]);
                $arcId = (int)$arc->fetchColumn();
                if ($arcId) {
                    $pdo->prepare("UPDATE tasks_cards SET column_id = ?, is_done = 1, is_archived = 1, completed_at = COALESCE(completed_at, NOW()) WHERE id = ?")->execute([$arcId, $cardId]);
                }
            }
            $pdo->prepare("DELETE FROM protocol_decision_cards WHERE decision_id = ? AND card_id = ?")->execute([$decId, $cardId]);
            unset($existing[$userName]);
        }

        $firstCardId = null;
        foreach ($users as $userName) {
            if (isset($existing[$userName])) {
                $cardId = $existing[$userName];
                $cur = $pdo->prepare("SELECT board_id, column_id, is_done, (SELECT is_done_column FROM tasks_columns WHERE id = c.column_id) AS in_done, (SELECT is_archive_column FROM tasks_columns WHERE id = c.column_id) AS in_archive FROM tasks_cards c WHERE id = ?");
                $cur->execute([$cardId]);
                $card = $cur->fetch();
                if (!$card) continue;
                $newColumnId = (int)$card['column_id'];
                if ($isDone && !(int)$card['in_archive']) {
                    $c2 = $pdo->prepare("SELECT id FROM tasks_columns WHERE board_id = ? AND is_archive_column = 1 ORDER BY sort_order LIMIT 1");
                    $c2->execute([$card['board_id']]);
                    $newColumnId = (int)$c2->fetchColumn() ?: $newColumnId;
                } elseif (!$isDone && ((int)$card['in_done'] || (int)$card['in_archive'])) {
                    $c2 = $pdo->prepare("SELECT id FROM tasks_columns WHERE board_id = ? AND is_done_column = 0 AND is_archive_column = 0 ORDER BY sort_order, id LIMIT 1");
                    $c2->execute([$card['board_id']]);
                    $newColumnId = (int)$c2->fetchColumn() ?: $newColumnId;
                }
                $pdo->prepare("UPDATE tasks_cards SET title=?, due_date=?, is_done=?, is_archived=?, completed_at=?, column_id=? WHERE id=?")
                    ->execute([$title, $cardDue, $isDone, $isArchived, $completedAt, $newColumnId, $cardId]);
            } else {
                $boardId = pdEnsureUserBoard($pdo, $userName);
                $colSql = $isDone
                    ? "SELECT id FROM tasks_columns WHERE board_id = ? AND is_archive_column = 1 ORDER BY sort_order LIMIT 1"
                    : "SELECT id FROM tasks_columns WHERE board_id = ? AND is_done_column = 0 AND is_archive_column = 0 ORDER BY sort_order, id LIMIT 1";
                $c = $pdo->prepare($colSql);
                $c->execute([$boardId]);
                $columnId = (int)$c->fetchColumn();
                if (!$columnId) continue;
                $so = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM tasks_cards WHERE column_id = ? AND parent_card_id IS NULL");
                $so->execute([$columnId]);
                $sortOrder = (int)$so->fetchColumn();
                $pdo->prepare("INSERT INTO tasks_cards (board_id, column_id, title, description, priority, due_date, sort_order, is_done, is_archived, created_by, completed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$boardId, $columnId, $title, null, 'medium', $cardDue, $sortOrder, $isDone, $isArchived, $createdBy, $completedAt]);
                $cardId = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO protocol_decision_cards (decision_id, card_id, user_name) VALUES (?, ?, ?)")
                    ->execute([$decId, $cardId, $userName]);
                $pdo->prepare("INSERT INTO tasks_relations (card_id, entity_type, entity_id, entity_label) VALUES (?, 'protocol', ?, ?)")
                    ->execute([$cardId, (string)$dec['protocol_id'], $entityLabel]);
                $existing[$userName] = $cardId;
            }
            if ($firstCardId === null) $firstCardId = $existing[$userName];
        }
        // Соисполнители: на каждой карточке записываем ОСТАЛЬНЫХ ответственных
        // по этому решению. Дубли на досках предотвращены фильтром в
        // tasks.php (external cards): если у пользователя уже есть СВОЯ копия
        // карточки по этому же protocol_decision_id, то «внешняя» копия с
        // чужой доски НЕ подтягивается.
        $allCardIds = array_values($existing);
        if ($allCardIds) {
            $phC = implode(',', array_fill(0, count($allCardIds), '?'));
            $pdo->prepare("DELETE FROM tasks_assignees WHERE card_id IN ($phC)")->execute($allCardIds);
            foreach ($existing as $ownerName => $cardId) {
                foreach ($users as $other) {
                    if ($other === $ownerName) continue;
                    $pdo->prepare("INSERT IGNORE INTO tasks_assignees (card_id, user_name) VALUES (?, ?)")
                        ->execute([$cardId, $other]);
                }
            }
        }
        if ($firstCardId) {
            $pdo->prepare("UPDATE protocol_decisions SET tasks_card_id = ? WHERE id = ?")->execute([$firstCardId, $decId]);
        } elseif (!$users) {
            $pdo->prepare("UPDATE protocol_decisions SET tasks_card_id = NULL WHERE id = ?")->execute([$decId]);
        }
    }

    function pdArchiveCardForDecision($pdo, $decisionId) {
        $cards = $pdo->prepare("SELECT card_id FROM protocol_decision_cards WHERE decision_id = ?");
        $cards->execute([$decisionId]);
        foreach ($cards->fetchAll() as $r) {
            $cardId = (int)$r['card_id'];
            $b = $pdo->prepare("SELECT board_id FROM tasks_cards WHERE id = ?");
            $b->execute([$cardId]);
            $boardId = (int)$b->fetchColumn();
            if (!$boardId) continue;
            $col = $pdo->prepare("SELECT id FROM tasks_columns WHERE board_id = ? AND is_archive_column = 1 ORDER BY sort_order LIMIT 1");
            $col->execute([$boardId]);
            $arcId = (int)$col->fetchColumn();
            if (!$arcId) continue;
            $pdo->prepare("UPDATE tasks_cards SET column_id = ?, is_done = 1, is_archived = 1, completed_at = COALESCE(completed_at, NOW()) WHERE id = ?")
                ->execute([$arcId, $cardId]);
        }
        $pdo->prepare("DELETE FROM protocol_decision_cards WHERE decision_id = ?")->execute([$decisionId]);
        $pdo->prepare("UPDATE protocol_decisions SET tasks_card_id = NULL WHERE id = ?")->execute([$decisionId]);
    }

    if ($fn === 'save_protocol') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        $id = intval($body['id'] ?? 0);
        // Проверяем права: редактировать может создатель или админ
        if ($id) {
            $existing = $pdo->prepare("SELECT created_by, status as old_status FROM meeting_protocols WHERE id = ?");
            $existing->execute([$id]);
            $row = $existing->fetch();
            if (!$row) respond(['error' => 'Протокол не найден'], 404);
            if ($row['created_by'] !== $caller['name'] && !in_array($caller['role'], ['admin', 'manager'])) {
                if (($ACCESS_LEVELS[$perms['protocols'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['full']) {
                    respond(['error' => 'Редактировать может только создатель или админ'], 403);
                }
            }
        } else {
            if (($ACCESS_LEVELS[$perms['protocols'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
                respond(['error' => 'Недостаточно прав'], 403);
            }
        }
        $meetingDate = $body['meeting_date'] ?? date('Y-m-d');
        $topic = trim($body['topic'] ?? '');
        $participants = $body['participants'] ?? [];
        $questions = trim($body['questions'] ?? '');
        $notes = trim($body['notes'] ?? '');
        $seriesId = $body['series_id'] ?: null;
        $status = $body['status'] ?? 'draft';
        $decisions = $body['decisions'] ?? [];
        $legalEntity = $body['legal_entity'] ?? null;
        if (!$topic) respond(['error' => 'Укажите тему совещания'], 400);
        // Для нового протокола юрлицо обязательно; для существующего — сохраняем исходное
        if (!$id && !$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!$id && !checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к юр. лицу'], 403);

        try {
            $pdo->beginTransaction();
            if ($id) {
                $pdo->prepare("UPDATE meeting_protocols SET series_id=?, meeting_date=?, topic=?, participants=?, questions=?, notes=?, status=?, updated_at=NOW() WHERE id=?")
                    ->execute([$seriesId, $meetingDate, $topic, json_encode($participants, JSON_UNESCAPED_UNICODE), $questions, $notes, $status, $id]);
            } else {
                $pdo->prepare("INSERT INTO meeting_protocols (series_id, meeting_date, topic, legal_entity, participants, questions, notes, status, created_by) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$seriesId, $meetingDate, $topic, $legalEntity, json_encode($participants, JSON_UNESCAPED_UNICODE), $questions, $notes, $status, $caller['name']]);
                $id = $pdo->lastInsertId();
            }
            // Синхронизируем решения
            $existingIds = [];
            $syncDecIds = [];
            foreach ($decisions as $dec) {
                $decId = intval($dec['id'] ?? 0);
                $decText = trim($dec['text'] ?? '');
                $responsible = trim($dec['responsible_person'] ?? '');
                $deadline = $dec['deadline'] ?: null;
                $decStatus = $dec['status'] ?? 'pending';
                $completedAt = $decStatus === 'done' ? ($dec['completed_at'] ?? date('Y-m-d H:i:s')) : null;
                if (!$decText) continue;
                if ($decId) {
                    $pdo->prepare("UPDATE protocol_decisions SET text=?, responsible_person=?, deadline=?, status=?, completed_at=? WHERE id=? AND protocol_id=?")
                        ->execute([$decText, $responsible, $deadline, $decStatus, $completedAt, $decId, $id]);
                    $existingIds[] = $decId;
                    $syncDecIds[] = $decId;
                } else {
                    $pdo->prepare("INSERT INTO protocol_decisions (protocol_id, text, responsible_person, deadline, status, completed_at) VALUES (?,?,?,?,?,?)")
                        ->execute([$id, $decText, $responsible, $deadline, $decStatus, $completedAt]);
                    $newDecId = (int)$pdo->lastInsertId();
                    $existingIds[] = $newDecId;
                    $syncDecIds[] = $newDecId;
                }
            }
            // Удаляем решения, которых больше нет
            if ($existingIds) {
                $ph = implode(',', array_fill(0, count($existingIds), '?'));
                $delQ = $pdo->prepare("SELECT id FROM protocol_decisions WHERE protocol_id = ? AND id NOT IN ($ph)");
                $delQ->execute(array_merge([$id], $existingIds));
                foreach ($delQ->fetchAll() as $rowDel) pdArchiveCardForDecision($pdo, (int)$rowDel['id']);
                $pdo->prepare("DELETE FROM protocol_decisions WHERE protocol_id = ? AND id NOT IN ($ph)")->execute(array_merge([$id], $existingIds));
            } else {
                $delQ = $pdo->prepare("SELECT id FROM protocol_decisions WHERE protocol_id = ?");
                $delQ->execute([$id]);
                foreach ($delQ->fetchAll() as $rowDel) pdArchiveCardForDecision($pdo, (int)$rowDel['id']);
                $pdo->prepare("DELETE FROM protocol_decisions WHERE protocol_id = ?")->execute([$id]);
            }
            $pdo->commit();
            foreach ($syncDecIds as $syncId) pdSyncDecisionToCard($pdo, $syncId, $caller['name']);

            // Telegram-уведомление участникам только при смене статуса на final
            $wasAlreadyFinal = isset($row) && ($row['old_status'] ?? '') === 'final';
            if ($status === 'final' && !$wasAlreadyFinal) {
                notifyProtocolParticipants($pdo, $id, $topic, $meetingDate, $participants, $caller['name']);
            }

            respond(['success' => true, 'id' => $id]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("save_protocol error: " . $e->getMessage());
            respond(['error' => 'Ошибка сохранения'], 500);
        }
    }

    if ($fn === 'delete_protocol') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'protocols', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'id required'], 400);
        $existing = $pdo->prepare("SELECT created_by, legal_entity FROM meeting_protocols WHERE id = ?");
        $existing->execute([$id]);
        $row = $existing->fetch();
        if (!$row) respond(['error' => 'Не найден'], 404);
        if (!checkLegalEntityAccess($caller, $row['legal_entity'] ?? null)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        if ($row['created_by'] !== $caller['name'] && !in_array($caller['role'], ['admin', 'manager'])) {
            respond(['error' => 'Удалить может только создатель или админ'], 403);
        }
        $pdo->prepare("DELETE FROM meeting_protocols WHERE id = ?")->execute([$id]);
        respond(['success' => true]);
    }

    if ($fn === 'update_decision_status') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'protocols', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $decId = intval($body['id'] ?? 0);
        $newStatus = $body['status'] ?? '';
        if (!$decId || !in_array($newStatus, ['pending', 'done', 'overdue'])) respond(['error' => 'Некорректные параметры'], 400);
        // Доступ — на уровне юрлица протокола, к которому относится решение.
        $accCheck = $pdo->prepare("SELECT p.legal_entity FROM protocol_decisions d JOIN meeting_protocols p ON p.id = d.protocol_id WHERE d.id = ?");
        $accCheck->execute([$decId]);
        $protLe = $accCheck->fetchColumn();
        if ($protLe === false) respond(['error' => 'Решение не найдено'], 404);
        if ($protLe && !checkLegalEntityAccess($caller, $protLe)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $completedAt = $newStatus === 'done' ? date('Y-m-d H:i:s') : null;
        $pdo->prepare("UPDATE protocol_decisions SET status = ?, completed_at = ? WHERE id = ?")->execute([$newStatus, $completedAt, $decId]);
        pdSyncDecisionToCard($pdo, $decId, $caller['name']);
        respond(['success' => true]);
    }

    if ($fn === 'update_decision_deadline') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'protocols', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $decId = intval($body['id'] ?? 0);
        $deadline = $body['deadline'] ?: null;
        if (!$decId) respond(['error' => 'id required'], 400);
        if ($deadline && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) respond(['error' => 'Некорректная дата'], 400);
        // Доступ — на уровне юрлица протокола, к которому относится решение.
        $accCheck = $pdo->prepare("SELECT p.legal_entity FROM protocol_decisions d JOIN meeting_protocols p ON p.id = d.protocol_id WHERE d.id = ?");
        $accCheck->execute([$decId]);
        $protLe = $accCheck->fetchColumn();
        if ($protLe === false) respond(['error' => 'Решение не найдено'], 404);
        if ($protLe && !checkLegalEntityAccess($caller, $protLe)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $pdo->prepare("UPDATE protocol_decisions SET deadline = ? WHERE id = ?")->execute([$deadline, $decId]);
        pdSyncDecisionToCard($pdo, $decId, $caller['name']);
        respond(['success' => true]);
    }

    // Серии совещаний
    if ($fn === 'get_carryover_tasks') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'protocols', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $seriesId = intval($body['series_id'] ?? 0);
        $excludeProtocolId = intval($body['exclude_protocol_id'] ?? 0);
        if (!$seriesId) respond([]);
        // Доступ — на уровне юрлица серии.
        $sLeStmt = $pdo->prepare("SELECT legal_entity FROM meeting_protocol_series WHERE id = ?");
        $sLeStmt->execute([$seriesId]);
        $sLe = $sLeStmt->fetchColumn();
        if ($sLe === false) respond([]);
        if ($sLe && !checkLegalEntityAccess($caller, $sLe)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);

        // Берём ровно один предыдущий протокол серии: с ближайшей более
        // ранней датой (при равных — с меньшим id), чтобы порядок «свежесть»
        // совпадал с UI-сортировкой. Возвращаем ВСЕ его задачи независимо
        // от статуса — пользователь сам решит, что закрывать/переносить.
        $prevSql = "SELECT id FROM meeting_protocols WHERE series_id = ?";
        $prevParams = [$seriesId];
        if ($excludeProtocolId) {
            $cur = $pdo->prepare("SELECT meeting_date FROM meeting_protocols WHERE id = ?");
            $cur->execute([$excludeProtocolId]);
            $curDate = $cur->fetchColumn();
            if ($curDate !== false) {
                $prevSql .= " AND (meeting_date < ? OR (meeting_date = ? AND id < ?))";
                $prevParams[] = $curDate;
                $prevParams[] = $curDate;
                $prevParams[] = $excludeProtocolId;
            } else {
                $prevSql .= " AND id != ?";
                $prevParams[] = $excludeProtocolId;
            }
        }
        $prevSql .= " ORDER BY meeting_date DESC, id DESC LIMIT 1";
        $ps = $pdo->prepare($prevSql);
        $ps->execute($prevParams);
        $prevProtoId = $ps->fetchColumn();
        if (!$prevProtoId) respond([]);

        $s = $pdo->prepare("
            SELECT d.id, d.text, d.responsible_person, d.deadline, d.status, d.protocol_id, d.tasks_card_id,
                   p.meeting_date, p.topic
            FROM protocol_decisions d
            JOIN meeting_protocols p ON p.id = d.protocol_id
            WHERE d.protocol_id = ?
            ORDER BY d.id
        ");
        $s->execute([$prevProtoId]);
        $decisions = $s->fetchAll();
        pdAttachCardDescription($pdo, $decisions);
        pdAttachAssigneesProgress($pdo, $decisions);
        respond($decisions);
    }

    if ($fn === 'get_protocol_series') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'protocols', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $legalEntity = $body['legal_entity'] ?? $_GET['legal_entity'] ?? null;
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $s = $pdo->prepare("SELECT s.*, (SELECT COUNT(*) FROM meeting_protocols p WHERE p.series_id = s.id) as protocols_count FROM meeting_protocol_series s WHERE s.legal_entity = ? ORDER BY s.name");
        $s->execute([$legalEntity]);
        respond($s->fetchAll());
    }

    if ($fn === 'save_protocol_series') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['protocols'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
            respond(['error' => 'Недостаточно прав'], 403);
        }
        $id = intval($body['id'] ?? 0);
        $name = trim($body['name'] ?? '');
        $recurrence = $body['recurrence'] ?? 'weekly';
        $agendaTemplate = $body['agenda_template'] ?? [];
        $legalEntity = $body['legal_entity'] ?? null;
        if (!$name) respond(['error' => 'Укажите название серии'], 400);
        if ($id) {
            // Обновление существующей: проверяем доступ к её фактическому legal_entity.
            $existing = $pdo->prepare("SELECT legal_entity FROM meeting_protocol_series WHERE id = ?");
            $existing->execute([$id]);
            $existingLe = $existing->fetchColumn();
            if ($existingLe === false) respond(['error' => 'Серия не найдена'], 404);
            if (!checkLegalEntityAccess($caller, $existingLe)) respond(['error' => 'Нет доступа к юр. лицу'], 403);
        } else {
            if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
            if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к юр. лицу'], 403);
        }
        $agendaJson = json_encode($agendaTemplate, JSON_UNESCAPED_UNICODE);
        if ($id) {
            $pdo->prepare("UPDATE meeting_protocol_series SET name=?, recurrence=?, agenda_template=? WHERE id=?")->execute([$name, $recurrence, $agendaJson, $id]);
        } else {
            $pdo->prepare("INSERT INTO meeting_protocol_series (name, legal_entity, recurrence, agenda_template, created_by) VALUES (?,?,?,?,?)")->execute([$name, $legalEntity, $recurrence, $agendaJson, $caller['name']]);
            $id = $pdo->lastInsertId();
        }
        respond(['success' => true, 'id' => $id]);
    }

    if ($fn === 'delete_protocol_series') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'protocols', 'full', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'id required'], 400);
        $pdo->prepare("DELETE FROM meeting_protocol_series WHERE id = ?")->execute([$id]);
        respond(['success' => true]);
    }

    if ($fn === 'get_users_list_short') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        // telegram_chat_id — персональные данные, отдаём только admin/manager
        // (нужны для упоминаний и Telegram-уведомлений). Остальным — без него.
        $isPriv = in_array($caller['role'] ?? '', ['admin', 'manager'], true);
        $cols = $isPriv ? "name, display_role, telegram_chat_id" : "name, display_role";
        $s = $pdo->query("SELECT {$cols} FROM users ORDER BY name");
        respond($s->fetchAll());
    }

    // ═══════════════════════════════════════════════════════════════
    // МОДУЛЬ ОПРОСОВ (surveys)
    // ═══════════════════════════════════════════════════════════════

    if (!function_exists('surveyGetTargetRestaurants')) {
        function surveyGetTargetRestaurants($pdo, $group) {
            $group = in_array($group, ['BK_VM', 'PS'], true) ? $group : 'BK_VM';
            $stmt = $pdo->prepare("
                SELECT r.number AS restaurant_number, r.legal_entity_group, r.address, r.city
                FROM restaurants r
                WHERE r.active = 1
                  AND r.legal_entity_group COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
                  AND (
                    EXISTS (
                        SELECT 1
                        FROM ro_users ru
                        WHERE ru.restaurant_number = r.number
                          AND ru.is_active = 1
                          AND ru.legal_entity_group COLLATE utf8mb4_unicode_ci = r.legal_entity_group COLLATE utf8mb4_unicode_ci
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM ro_telegram_subs vs
                        WHERE vs.restaurant_number = r.number
                    )
                  )
                ORDER BY r.number
            ");
            $stmt->execute([$group]);
            return $stmt->fetchAll();
        }
    }

    if (!function_exists('surveyCountTargetsByGroup')) {
        function surveyCountTargetsByGroup($pdo) {
            $rows = $pdo->query("
                SELECT r.legal_entity_group AS grp, COUNT(*) AS cnt
                FROM restaurants r
                WHERE r.active = 1
                  AND (
                    EXISTS (
                        SELECT 1
                        FROM ro_users ru
                        WHERE ru.restaurant_number = r.number
                          AND ru.is_active = 1
                          AND ru.legal_entity_group COLLATE utf8mb4_unicode_ci = r.legal_entity_group COLLATE utf8mb4_unicode_ci
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM ro_telegram_subs vs
                        WHERE vs.restaurant_number = r.number
                    )
                  )
                GROUP BY r.legal_entity_group
            ")->fetchAll();
            $out = ['BK_VM' => 0, 'PS' => 0];
            foreach ($rows as $r) {
                $g = $r['grp'] ?? '';
                if (isset($out[$g])) $out[$g] = (int)$r['cnt'];
            }
            return $out;
        }
    }

    if (!function_exists('surveyGetRecipientChatIds')) {
        function surveyGetRecipientChatIds($pdo, $group) {
            $group = in_array($group, ['BK_VM', 'PS'], true) ? $group : 'BK_VM';
            $chatIds = [];

            $roStmt = $pdo->prepare("
                SELECT DISTINCT rs.chat_id AS telegram_chat_id
                FROM ro_telegram_subs rs
                JOIN restaurants r
                  ON r.number = rs.restaurant_number
                 AND r.active = 1
                 AND r.legal_entity_group COLLATE utf8mb4_unicode_ci = rs.legal_entity_group COLLATE utf8mb4_unicode_ci
                WHERE rs.legal_entity_group COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
                  AND rs.chat_id IS NOT NULL
                  AND (rs.verified_at IS NOT NULL
                       OR (rs.must_reverify_by IS NOT NULL AND rs.must_reverify_by > NOW()))
            ");
            $roStmt->execute([$group]);
            foreach ($roStmt->fetchAll(PDO::FETCH_COLUMN) as $chatId) {
                $chatId = trim((string)$chatId);
                if ($chatId !== '') $chatIds[$chatId] = true;
            }

            return array_keys($chatIds);
        }
    }

    if ($fn === 'surveys_list') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'surveys', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $rows = $pdo->query("
            SELECT s.id, s.title, s.legal_entity_group, s.status, s.allow_comment,
                   s.remind_after_hours, s.sent_at, s.created_by, s.created_at, s.closed_at,
                   (SELECT COUNT(*) FROM survey_questions sq WHERE sq.survey_id = s.id) AS questions_count,
                   (SELECT COUNT(*) FROM survey_responses sr WHERE sr.survey_id = s.id) AS responses_count
            FROM surveys s
            ORDER BY s.created_at DESC
        ")->fetchAll();

        $targetCounts = surveyCountTargetsByGroup($pdo);
        foreach ($rows as &$row) {
            $group = $row['legal_entity_group'] ?? 'BK_VM';
            $row['target_restaurants_count'] = (int)($targetCounts[$group] ?? 0);
        }
        unset($row);

        respond($rows);
    }

    if ($fn === 'survey_get') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'surveys', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'id required'], 400);

        $survey = $pdo->prepare("SELECT * FROM surveys WHERE id = ?");
        $survey->execute([$id]);
        $s = $survey->fetch();
        if (!$s) respond(['error' => 'Не найдено'], 404);

        // Вопросы + опции одним запросом
        $qRowsStmt = $pdo->prepare("
            SELECT sq.id AS question_id, sq.text AS question_text, sq.type AS question_type, sq.sort_order AS question_sort,
                   sq.files_required AS question_files_required,
                   so.id AS option_id, so.text AS option_text, so.sort_order AS option_sort
            FROM survey_questions sq
            LEFT JOIN survey_options so ON so.question_id = sq.id
            WHERE sq.survey_id = ?
            ORDER BY sq.sort_order, sq.id, so.sort_order, so.id
        ");
        $qRowsStmt->execute([$id]);
        $questionsMap = [];
        foreach ($qRowsStmt->fetchAll() as $row) {
            $qid = (int)$row['question_id'];
            if (!isset($questionsMap[$qid])) {
                $questionsMap[$qid] = [
                    'id' => $qid,
                    'text' => $row['question_text'],
                    'type' => $row['question_type'] ?: 'choice',
                    'files_required' => (int)$row['question_files_required'] === 1,
                    'sort_order' => (int)$row['question_sort'],
                    'options' => [],
                ];
            }
            if (!empty($row['option_id'])) {
                $questionsMap[$qid]['options'][] = [
                    'id' => (int)$row['option_id'],
                    'text' => $row['option_text'],
                    'sort_order' => (int)$row['option_sort'],
                ];
            }
        }
        $s['questions'] = array_values($questionsMap);

        // Ответы (шапка) одним запросом
        $respStmt = $pdo->prepare("
            SELECT sr.id, sr.restaurant_number, sr.legal_entity_group, sr.comment, sr.submitted_at,
                   sr.telegram_chat_id, r.address, r.city
            FROM survey_responses sr
            LEFT JOIN restaurants r
              ON r.number = sr.restaurant_number
             AND r.legal_entity_group COLLATE utf8mb4_unicode_ci = sr.legal_entity_group COLLATE utf8mb4_unicode_ci
            WHERE sr.survey_id = ?
            ORDER BY sr.restaurant_number ASC, sr.submitted_at DESC
        ");
        $respStmt->execute([$id]);
        $respRows = $respStmt->fetchAll();

        // Детали ответов одним запросом
        $ansStmt = $pdo->prepare("
            SELECT sa.response_id, sa.question_id, sq.text AS question_text, sq.type,
                   sa.option_id, so.text AS option_text, sa.numeric_value, sa.text_value, sq.sort_order AS q_sort
            FROM survey_answers sa
            JOIN survey_responses sr ON sr.id = sa.response_id
            JOIN survey_questions sq ON sq.id = sa.question_id
            LEFT JOIN survey_options so ON so.id = sa.option_id
            WHERE sr.survey_id = ?
            ORDER BY sq.sort_order, sq.id
        ");
        $ansStmt->execute([$id]);
        $ansByResp = [];
        $optionCounts = [];
        foreach ($ansStmt->fetchAll() as $row) {
            $rid = (int)$row['response_id'];
            $ansByResp[$rid] ??= [];
            $ansByResp[$rid][] = [
                'question_id' => (int)$row['question_id'],
                'question_text' => $row['question_text'],
                'type' => $row['type'] ?: 'choice',
                'option_id' => $row['option_id'] !== null ? (int)$row['option_id'] : null,
                'option_text' => $row['option_text'],
                'numeric_value' => $row['numeric_value'] !== null ? (int)$row['numeric_value'] : null,
                'text_value' => $row['text_value'],
            ];
            if (($row['type'] ?? 'choice') === 'choice' && $row['option_id'] !== null) {
                $oid = (int)$row['option_id'];
                $optionCounts[$oid] = ($optionCounts[$oid] ?? 0) + 1;
            }
        }
        // Файлы по ответам — одним запросом.
        $filesByResp = [];
        $filesStmt = $pdo->prepare("
            SELECT response_id, id, question_id, file_path, file_name, mime_type, file_size, created_at
            FROM survey_response_files
            WHERE response_id IN (SELECT id FROM survey_responses WHERE survey_id = ?)
            ORDER BY id
        ");
        $filesStmt->execute([$id]);
        foreach ($filesStmt->fetchAll() as $row) {
            $rid = (int)$row['response_id'];
            $filesByResp[$rid] ??= [];
            $filesByResp[$rid][] = [
                'id'          => (int)$row['id'],
                'question_id' => (int)$row['question_id'],
                'file_name'   => $row['file_name'],
                'mime_type'   => $row['mime_type'],
                'file_size'   => (int)$row['file_size'],
                'created_at'  => $row['created_at'],
                'url'         => '/api/' . ltrim((string)$row['file_path'], '/'),
            ];
        }
        foreach ($respRows as &$r) {
            $r['answers'] = $ansByResp[(int)$r['id']] ?? [];
            $r['files'] = $filesByResp[(int)$r['id']] ?? [];
        }
        unset($r);
        $s['responses'] = $respRows;

        // Аналитика по вариантам / шкале
        $totalResponses = count($respRows);
        foreach ($s['questions'] as &$q) {
            if (($q['type'] ?? 'choice') === 'scale') {
                $scaleCounts = array_fill(1, 10, 0);
                $sum = 0;
                $totalForQ = 0;
                foreach ($ansByResp as $answers) {
                    foreach ($answers as $answer) {
                        if ((int)$answer['question_id'] !== (int)$q['id']) continue;
                        $score = (int)($answer['numeric_value'] ?? 0);
                        if ($score < 1 || $score > 10) continue;
                        $scaleCounts[$score]++;
                        $sum += $score;
                        $totalForQ++;
                    }
                }
                $q['options'] = [];
                for ($score = 1; $score <= 10; $score++) {
                    $cnt = (int)$scaleCounts[$score];
                    $q['options'][] = [
                        'id' => $score,
                        'text' => (string)$score,
                        'responses_count' => $cnt,
                        'responses_percent' => $totalForQ > 0 ? round($cnt * 100 / $totalForQ) : 0,
                    ];
                }
                $q['responses_total'] = $totalForQ;
                $q['average_score'] = $totalForQ > 0 ? round($sum / $totalForQ, 1) : null;
            } elseif (($q['type'] ?? 'choice') === 'text') {
                $totalForQ = 0;
                foreach ($ansByResp as $answers) {
                    foreach ($answers as $answer) {
                        if ((int)$answer['question_id'] === (int)$q['id'] && trim((string)($answer['text_value'] ?? '')) !== '') {
                            $totalForQ++;
                        }
                    }
                }
                $q['options'] = [];
                $q['responses_total'] = $totalForQ;
            } else {
                $totalForQ = 0;
                foreach ($q['options'] as $opt) {
                    $totalForQ += (int)($optionCounts[(int)$opt['id']] ?? 0);
                }
                foreach ($q['options'] as &$opt) {
                    $cnt = (int)($optionCounts[(int)$opt['id']] ?? 0);
                    $opt['responses_count'] = $cnt;
                    $opt['responses_percent'] = $totalForQ > 0 ? round($cnt * 100 / $totalForQ) : 0;
                }
                unset($opt);
                $q['responses_total'] = $totalForQ;
            }
        }
        unset($q);

        // Не ответили
        $answered = [];
        foreach ($respRows as $r) {
            $answered[(int)$r['restaurant_number']] = true;
        }
        $pendingRows = [];
        foreach (surveyGetTargetRestaurants($pdo, $s['legal_entity_group']) as $restaurant) {
            if (!isset($answered[(int)$restaurant['restaurant_number']])) {
                $pendingRows[] = $restaurant;
            }
        }
        $s['pending_restaurants'] = $pendingRows;
        $s['target_restaurants_count'] = count($pendingRows) + $totalResponses;

        respond($s);
    }

    if ($fn === 'survey_save') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'surveys', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $id    = intval($body['id'] ?? 0);
        $title = trim($body['title'] ?? '');
        $group = in_array($body['legal_entity_group'] ?? '', ['BK_VM','PS']) ? $body['legal_entity_group'] : 'BK_VM';
        $desc  = trim($body['description'] ?? '');
        $allowComment = isset($body['allow_comment']) ? (int)(bool)$body['allow_comment'] : 1;
        $remindHours  = max(1, intval($body['remind_after_hours'] ?? 24));
        $questions = is_array($body['questions'] ?? null) ? $body['questions'] : [];

        if (!$title) respond(['error' => 'Нужен заголовок'], 400);

        $normalizedQuestions = [];
        foreach ($questions as $q) {
            $qText = trim($q['text'] ?? '');
            if ($qText === '') continue;
            $qType = in_array($q['type'] ?? 'choice', ['choice', 'scale', 'text', 'files'], true) ? $q['type'] : 'choice';

            $normalizedOptions = [];
            if ($qType === 'choice') {
                foreach (($q['options'] ?? []) as $opt) {
                    $optText = trim($opt['text'] ?? '');
                    if ($optText !== '') {
                        $normalizedOptions[] = $optText;
                    }
                }

                if (count($normalizedOptions) < 2) {
                    respond(['error' => 'У вопроса с вариантами должно быть минимум 2 варианта ответа'], 400);
                }
            }

            $filesRequired = 1; // default ВКЛ
            if ($qType === 'files' && array_key_exists('files_required', $q)) {
                $filesRequired = (int)(bool)$q['files_required'];
            }

            $normalizedQuestions[] = [
                'text' => $qText,
                'type' => $qType,
                'files_required' => $filesRequired,
                'options' => $normalizedOptions,
            ];
        }

        if (empty($normalizedQuestions)) respond(['error' => 'Нужен хотя бы один вопрос'], 400);

        if ($id) {
            $chk = $pdo->prepare("SELECT status FROM surveys WHERE id = ?");
            $chk->execute([$id]);
            $row = $chk->fetch();
            if (!$row) respond(['error' => 'Не найдено'], 404);
            if ($row['status'] !== 'draft') respond(['error' => 'Редактировать можно только черновик'], 400);
        }

        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare("UPDATE surveys SET title=?, description=?, legal_entity_group=?, allow_comment=?, remind_after_hours=? WHERE id=?")
                    ->execute([$title, $desc, $group, $allowComment, $remindHours, $id]);
                $pdo->prepare("DELETE FROM survey_questions WHERE survey_id = ?")->execute([$id]);
            } else {
                $createdBy = trim((string)($caller['name'] ?? $caller['login'] ?? $caller['email'] ?? 'system'));
                if ($createdBy === '') $createdBy = 'system';
                $pdo->prepare("INSERT INTO surveys (title, description, legal_entity_group, allow_comment, remind_after_hours, created_by) VALUES (?,?,?,?,?,?)")
                    ->execute([$title, $desc, $group, $allowComment, $remindHours, $createdBy]);
                $id = (int)$pdo->lastInsertId();
            }

            foreach ($normalizedQuestions as $qi => $q) {
                $pdo->prepare("INSERT INTO survey_questions (survey_id, text, type, files_required, sort_order) VALUES (?,?,?,?,?)")
                    ->execute([$id, $q['text'], $q['type'], $q['files_required'] ?? 1, $qi]);
                $qId = (int)$pdo->lastInsertId();
                foreach ($q['options'] as $oi => $optText) {
                    $pdo->prepare("INSERT INTO survey_options (question_id, text, sort_order) VALUES (?,?,?)")
                        ->execute([$qId, $optText, $oi]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            respond(['error' => $e->getMessage()], 500);
        }

        respond(['success' => true, 'id' => $id]);
    }

    if ($fn === 'survey_send') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'surveys', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'id required'], 400);

        $survey = $pdo->prepare("SELECT * FROM surveys WHERE id = ?");
        $survey->execute([$id]);
        $s = $survey->fetch();
        if (!$s) respond(['error' => 'Не найдено'], 404);
        if ($s['status'] !== 'draft') respond(['error' => 'Можно разослать только черновик'], 400);

        $qCountStmt = $pdo->prepare("SELECT COUNT(*) FROM survey_questions WHERE survey_id = ?");
        $qCountStmt->execute([$id]);
        $qCount = (int)$qCountStmt->fetchColumn();
        if (!$qCount) respond(['error' => 'Нет вопросов'], 400);

        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if (!$botToken) respond(['error' => 'Нет TELEGRAM_BOT_TOKEN'], 500);

        $chatIds = surveyGetRecipientChatIds($pdo, $s['legal_entity_group']);

        if (empty($chatIds)) respond(['error' => 'Нет подписчиков в этой группе'], 400);

        $safeTitle = htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8');
        $sent = 0;

        foreach ($chatIds as $cid) {
            $text = "📋 <b>Опрос: {$safeTitle}</b>\n\n";
            if ($s['description']) $text .= htmlspecialchars($s['description'], ENT_QUOTES, 'UTF-8') . "\n\n";
            $text .= "Нажмите кнопку, чтобы пройти опрос.";

            $btns = ['inline_keyboard' => [
                [['text' => '📝 Пройти опрос', 'callback_data' => "srv_start_{$id}"]],
            ]];

            $data = json_encode(['chat_id' => $cid, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => json_encode($btns)]);
            $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $data, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 5]);
            $res = curl_exec($ch); curl_close($ch);
            $r = json_decode($res, true);
            if ($r && ($r['ok'] ?? false)) $sent++;
        }

        if ($sent <= 0) respond(['error' => 'Не удалось отправить опрос ни одному получателю'], 500);

        $pdo->prepare("UPDATE surveys SET status = 'active', sent_at = NOW() WHERE id = ?")->execute([$id]);

        respond(['success' => true, 'sent' => $sent, 'total' => count($chatIds)]);
    }

    if ($fn === 'survey_close') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'surveys', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'id required'], 400);
        $pdo->prepare("UPDATE surveys SET status = 'closed', closed_at = NOW() WHERE id = ?")->execute([$id]);
        respond(['success' => true]);
    }

    if ($fn === 'survey_response_delete') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'surveys', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $responseId = intval($body['id'] ?? 0);
        $surveyId = intval($body['survey_id'] ?? 0);
        if (!$responseId) respond(['error' => 'id required'], 400);

        $stmt = $pdo->prepare("
            SELECT id, survey_id
            FROM survey_responses
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$responseId]);
        $response = $stmt->fetch();
        if (!$response) respond(['error' => 'Ответ не найден'], 404);
        if ($surveyId && (int)$response['survey_id'] !== $surveyId) {
            respond(['error' => 'Ответ не относится к этому опросу'], 400);
        }

        // Сначала собираем файлы — после CASCADE DELETE строки в БД исчезнут,
        // а на диске останутся «осиротевшими».
        $filesStmt = $pdo->prepare("SELECT file_path FROM survey_response_files WHERE response_id = ?");
        $filesStmt->execute([$responseId]);
        $filesToUnlink = $filesStmt->fetchAll(PDO::FETCH_COLUMN);

        $pdo->prepare("DELETE FROM survey_responses WHERE id = ?")->execute([$responseId]);

        foreach ($filesToUnlink as $rel) {
            $abs = __DIR__ . '/../' . ltrim((string)$rel, '/');
            if (is_file($abs)) @unlink($abs);
        }
        respond(['success' => true]);
    }

    if ($fn === 'survey_delete') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'surveys', 'full', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'id required'], 400);

        // Соберём пути файлов до CASCADE — иначе после DELETE строки исчезнут,
        // а файлы останутся болтаться на диске.
        $filesStmt = $pdo->prepare("SELECT file_path FROM survey_response_files WHERE survey_id = ?");
        $filesStmt->execute([$id]);
        $filesToUnlink = $filesStmt->fetchAll(PDO::FETCH_COLUMN);

        $pdo->prepare("DELETE FROM surveys WHERE id = ?")->execute([$id]);

        foreach ($filesToUnlink as $rel) {
            $abs = __DIR__ . '/../' . ltrim((string)$rel, '/');
            if (is_file($abs)) @unlink($abs);
        }
        respond(['success' => true]);
    }

    // ═══ Модуль "График поставок" (supplier-schedule) ═══
    // Управление расписанием подачи заявок и доставок:
    // - supplier_schedules: связка поставщик↔ресторан + дни заказа/доставки
    // - supplier_schedule_deadlines: точечный дедлайн на (поставщик, ресторан, день)
    // - supplier_default_deadlines: дефолтные дедлайны на уровне поставщика (read-only здесь,
    //   редактируется в модуле "Заявки поставщикам" при register-supplier)

    if ($fn === 'list_supplier_schedules') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'supplier-schedule', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        // Группа юр.лица (BK_VM или PS) — обязательный контекст, чтобы не смешивать
        // данные разных групп (поставщики Пицца Стар не должны попадать к БК и наоборот).
        $group = trim((string)($body['legal_entity_group'] ?? ''));
        if (!in_array($group, ['BK_VM', 'PS'], true)) respond(['error' => 'Требуется legal_entity_group (BK_VM или PS)'], 400);

        // Фильтр по юр.лицам пользователя (legal_entities приходит JSON-строкой)
        $userEntities = $caller['legal_entities'] ?? null;
        if (is_string($userEntities)) $userEntities = json_decode($userEntities, true);
        $entityWhere = '';
        $entityParams = [];
        if (is_array($userEntities) && !empty($userEntities)) {
            $ph = implode(',', array_fill(0, count($userEntities), '?'));
            $entityWhere = " AND r.legal_entity IN ($ph) ";
            $entityParams = $userEntities;
        }

        $rows = $pdo->prepare("
            SELECT ss.id AS schedule_id, ss.supplier_id, ss.restaurant_id,
                   ss.order_day, ss.delivery_day, ss.is_active,
                   s.short_name AS supplier_name, s.so_enabled,
                   s.legal_entity_group AS supplier_group,
                   r.number AS restaurant_number, r.city AS restaurant_city, r.address AS restaurant_address,
                   r.legal_entity, r.legal_entity_group AS restaurant_group,
                   sd.deadline_time AS deadline_override,
                   sd.reminder_times AS reminder_times_override
            FROM supplier_schedules ss
            JOIN suppliers s ON s.id = ss.supplier_id
            JOIN restaurants r ON r.id = ss.restaurant_id
            LEFT JOIN supplier_schedule_deadlines sd
                   ON sd.supplier_id = ss.supplier_id
                  AND sd.restaurant_id = ss.restaurant_id
                  AND sd.order_day = ss.order_day
            WHERE s.is_active = 1 AND r.active = 1
              AND s.legal_entity_group = ?
              AND r.legal_entity_group = ?
              $entityWhere
            ORDER BY s.short_name, r.number, ss.order_day
        ");
        $rows->execute(array_merge([$group, $group], $entityParams));
        $schedules = $rows->fetchAll();

        // Дефолтные дедлайны: supplier_default_deadlines
        $supplierIds = array_values(array_unique(array_column($schedules, 'supplier_id')));
        $defaults = [];
        if ($supplierIds) {
            $ph = implode(',', array_fill(0, count($supplierIds), '?'));
            $s = $pdo->prepare("
                SELECT supplier_id, delivery_dow, deadline_dow, deadline_time, reminder_times
                FROM supplier_default_deadlines
                WHERE supplier_id IN ($ph)
                ORDER BY supplier_id, delivery_dow
            ");
            $s->execute($supplierIds);
            foreach ($s->fetchAll() as $r) {
                $defaults[$r['supplier_id']][] = $r;
            }
        }

        // Подписки ресторанов на напоминания + Telegram-подписчики (для индикатора в UI)
        // Структура: subscriptions[supplier_id][restaurant_id] = { is_enabled, telegram_enabled, tg_names: [..] }
        $subscriptions = [];
        if ($supplierIds) {
            $ph = implode(',', array_fill(0, count($supplierIds), '?'));
            $subStmt = $pdo->prepare("
                SELECT sub.id, sub.restaurant_id, sub.supplier_id,
                       sub.is_enabled, sub.telegram_enabled
                FROM restaurant_reminder_subscriptions sub
                WHERE sub.supplier_id IN ($ph)
            ");
            $subStmt->execute($supplierIds);
            $subById = [];
            foreach ($subStmt->fetchAll() as $r) {
                $subId = (int)$r['id'];
                $subById[$subId] = $r;
                $subscriptions[$r['supplier_id']][(int)$r['restaurant_id']] = [
                    'is_enabled' => (int)$r['is_enabled'] === 1,
                    'telegram_enabled' => (int)$r['telegram_enabled'] === 1,
                    'tg_names' => [],
                ];
            }
            // Имена tg-подписчиков
            if ($subById) {
                $sIds = array_keys($subById);
                $sph = implode(',', array_fill(0, count($sIds), '?'));
                $tgStmt = $pdo->prepare("
                    SELECT rrts.subscription_id, rts.first_name, rts.username
                    FROM restaurant_reminder_tg_subscribers rrts
                    JOIN ro_telegram_subs rts ON rts.id = rrts.ro_tg_sub_id
                    WHERE rrts.subscription_id IN ($sph) AND rrts.is_active = 1 AND rts.verified_at IS NOT NULL
                ");
                $tgStmt->execute($sIds);
                foreach ($tgStmt->fetchAll() as $t) {
                    $subInfo = $subById[(int)$t['subscription_id']];
                    $name = $t['first_name'] ?: ($t['username'] ? '@' . $t['username'] : 'tg');
                    $subscriptions[$subInfo['supplier_id']][(int)$subInfo['restaurant_id']]['tg_names'][] = $name;
                }
            }
        }

        respond([
            'rows' => $schedules,
            'default_deadlines' => $defaults,
            'subscriptions' => $subscriptions,
        ]);
    }

    if ($fn === 'list_supplier_schedule_directory') {
        // Справочники для модалки: поставщики и рестораны, доступные пользователю
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'supplier-schedule', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $group = trim((string)($body['legal_entity_group'] ?? ''));
        if (!in_array($group, ['BK_VM', 'PS'], true)) respond(['error' => 'Требуется legal_entity_group (BK_VM или PS)'], 400);

        $userEntities = $caller['legal_entities'] ?? null;
        if (is_string($userEntities)) $userEntities = json_decode($userEntities, true);
        $entityWhereR = '';
        $entityParamsR = [];
        if (is_array($userEntities) && !empty($userEntities)) {
            $ph = implode(',', array_fill(0, count($userEntities), '?'));
            $entityWhereR = " AND legal_entity IN ($ph) ";
            $entityParamsR = $userEntities;
        }

        $sup = $pdo->prepare("
            SELECT id, short_name, so_enabled, legal_entity_group
            FROM suppliers
            WHERE is_active = 1 AND legal_entity_group = ?
            ORDER BY short_name
        ");
        $sup->execute([$group]);
        $sup = $sup->fetchAll();

        $restStmt = $pdo->prepare("
            SELECT id, number, city, address, legal_entity, legal_entity_group
            FROM restaurants
            WHERE active = 1 AND legal_entity_group = ? $entityWhereR
            ORDER BY number
        ");
        $restStmt->execute(array_merge([$group], $entityParamsR));
        $rests = $restStmt->fetchAll();

        respond([
            'suppliers' => $sup,
            'restaurants' => $rests,
        ]);
    }

    // Подписки ресторанов на напоминания об ОСНОВНОЙ поставке — для индикатора
    // в DeliveryScheduleView (страница «График доставки»). Структура аналогична
    // subscriptions из list_supplier_schedules, но без supplier_id (у основной
    // поставки только одна подписка на ресторан).
    if ($fn === 'list_main_delivery_subscriptions') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'delivery-schedule', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $group = trim((string)($body['legal_entity_group'] ?? ''));
        if (!in_array($group, ['BK_VM', 'PS'], true)) respond(['error' => 'Требуется legal_entity_group (BK_VM или PS)'], 400);

        // Фильтр по доступным пользователю юр.лицам.
        $userEntities = $caller['legal_entities'] ?? null;
        if (is_string($userEntities)) $userEntities = json_decode($userEntities, true);
        $entityWhere = '';
        $entityParams = [];
        if (is_array($userEntities) && !empty($userEntities)) {
            $ph = implode(',', array_fill(0, count($userEntities), '?'));
            $entityWhere = " AND r.legal_entity IN ($ph) ";
            $entityParams = $userEntities;
        }

        $subStmt = $pdo->prepare("
            SELECT sub.id, sub.restaurant_id, sub.is_enabled, sub.telegram_enabled
            FROM restaurant_main_delivery_subscriptions sub
            JOIN restaurants r ON r.id = sub.restaurant_id
            WHERE r.legal_entity_group = ?
              AND r.active = 1
              $entityWhere
        ");
        $subStmt->execute(array_merge([$group], $entityParams));

        $subscriptions = [];
        $subById = [];
        foreach ($subStmt->fetchAll() as $r) {
            $subId = (int)$r['id'];
            $subById[$subId] = $r;
            $subscriptions[(int)$r['restaurant_id']] = [
                'is_enabled' => (int)$r['is_enabled'] === 1,
                'telegram_enabled' => (int)$r['telegram_enabled'] === 1,
                'tg_names' => [],
            ];
        }

        // Имена выбранных Telegram-подписчиков для каждой подписки.
        if ($subById) {
            $sIds = array_keys($subById);
            $sph = implode(',', array_fill(0, count($sIds), '?'));
            $tgStmt = $pdo->prepare("
                SELECT rmts.subscription_id, rts.first_name, rts.username
                FROM restaurant_main_delivery_tg_subscribers rmts
                JOIN ro_telegram_subs rts ON rts.id = rmts.ro_tg_sub_id
                WHERE rmts.subscription_id IN ($sph)
                  AND rmts.is_active = 1
                  AND rts.verified_at IS NOT NULL
            ");
            $tgStmt->execute($sIds);
            foreach ($tgStmt->fetchAll() as $t) {
                $subInfo = $subById[(int)$t['subscription_id']];
                $name = $t['first_name'] ?: ($t['username'] ? '@' . $t['username'] : 'tg');
                $subscriptions[(int)$subInfo['restaurant_id']]['tg_names'][] = $name;
            }
        }

        respond(['subscriptions' => $subscriptions]);
    }

    if ($fn === 'save_supplier_schedule_row') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'supplier-schedule', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $supplierId = trim((string)($body['supplier_id'] ?? ''));
        $restaurantId = (int)($body['restaurant_id'] ?? 0);
        $orderDay = (int)($body['order_day'] ?? 0);
        $deliveryDay = (int)($body['delivery_day'] ?? 0);
        $isActive = !empty($body['is_active']) ? 1 : 0;
        $deadlineTime = isset($body['deadline_time']) && $body['deadline_time'] !== '' ? $body['deadline_time'] : null;
        $reminderTimes = isset($body['reminder_times']) ? $body['reminder_times'] : null;

        if (!$supplierId || !$restaurantId
            || !in_array($orderDay, [1,2,3,4,5,6,7], true)
            || !in_array($deliveryDay, [1,2,3,4,5,6,7], true)) {
            respond(['error' => 'Некорректные данные'], 400);
        }

        // Валидация reminder_times: массив объектов { days_before:0..7, time:'HH:MM' }
        $reminderTimesJson = null;
        if ($reminderTimes !== null) {
            if (!is_array($reminderTimes)) respond(['error' => 'reminder_times должен быть массивом'], 400);
            $cleaned = [];
            foreach ($reminderTimes as $rt) {
                if (!is_array($rt)) continue;
                $db = (int)($rt['days_before'] ?? -1);
                $t = $rt['time'] ?? '';
                if ($db < 0 || $db > 7) respond(['error' => 'days_before должен быть 0..7'], 400);
                if (!preg_match('/^\d{1,2}:\d{2}$/', $t)) respond(['error' => 'Некорректный формат времени напоминания'], 400);
                $cleaned[] = ['days_before' => $db, 'time' => $t];
            }
            $reminderTimesJson = $cleaned ? json_encode($cleaned, JSON_UNESCAPED_UNICODE) : null;
        }

        // Проверка существования поставщика и доступа к юр.лицу ресторана
        $supStmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
        $supStmt->execute([$supplierId]);
        if (!$supStmt->fetchColumn()) respond(['error' => 'Поставщик не найден'], 404);

        $restStmt = $pdo->prepare("SELECT legal_entity FROM restaurants WHERE id = ?");
        $restStmt->execute([$restaurantId]);
        $rest = $restStmt->fetch();
        if (!$rest) respond(['error' => 'Ресторан не найден'], 404);
        if (!checkLegalEntityAccess($caller, $rest['legal_entity'])) {
            respond(['error' => 'Нет доступа к юр.лицу ресторана'], 403);
        }

        if ($deadlineTime !== null) {
            // Принимаем HH:MM или HH:MM:SS
            if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $deadlineTime)) {
                respond(['error' => 'Некорректное время дедлайна'], 400);
            }
            if (strlen($deadlineTime) === 5) $deadlineTime .= ':00';
        }

        $updatedBy = resolveActorName($pdo, $caller);

        $pdo->prepare("
            INSERT INTO supplier_schedules (supplier_id, restaurant_id, order_day, delivery_day, is_active, updated_at, updated_by)
            VALUES (?, ?, ?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE
                delivery_day = VALUES(delivery_day),
                is_active = VALUES(is_active),
                updated_at = NOW(),
                updated_by = VALUES(updated_by)
        ")->execute([$supplierId, $restaurantId, $orderDay, $deliveryDay, $isActive, $updatedBy]);

        if ($deadlineTime !== null || $reminderTimesJson !== null) {
            $pdo->prepare("
                INSERT INTO supplier_schedule_deadlines (supplier_id, restaurant_id, order_day, deadline_time, reminder_times, updated_at, updated_by)
                VALUES (?, ?, ?, ?, ?, NOW(), ?)
                ON DUPLICATE KEY UPDATE
                    deadline_time = VALUES(deadline_time),
                    reminder_times = VALUES(reminder_times),
                    updated_at = NOW(),
                    updated_by = VALUES(updated_by)
            ")->execute([$supplierId, $restaurantId, $orderDay, $deadlineTime, $reminderTimesJson, $updatedBy]);
        } else {
            $pdo->prepare("DELETE FROM supplier_schedule_deadlines WHERE supplier_id = ? AND restaurant_id = ? AND order_day = ?")
                ->execute([$supplierId, $restaurantId, $orderDay]);
        }

        respond(['success' => true]);
    }

    if ($fn === 'save_supplier_default_deadline') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'supplier-schedule', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $supplierId = trim((string)($body['supplier_id'] ?? ''));
        $deliveryDow = (int)($body['delivery_dow'] ?? 0);
        $deadlineDow = (int)($body['deadline_dow'] ?? 0);
        $deadlineTime = $body['deadline_time'] ?? '';
        $reminderTimes = $body['reminder_times'] ?? null;

        if (!$supplierId
            || !in_array($deliveryDow, [1,2,3,4,5,6,7], true)
            || !in_array($deadlineDow, [1,2,3,4,5,6,7], true)
            || !preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $deadlineTime)) {
            respond(['error' => 'Некорректные данные'], 400);
        }
        if (strlen($deadlineTime) === 5) $deadlineTime .= ':00';

        // Валидация reminder_times
        $reminderTimesJson = null;
        if ($reminderTimes !== null) {
            if (!is_array($reminderTimes)) respond(['error' => 'reminder_times должен быть массивом'], 400);
            $cleaned = [];
            foreach ($reminderTimes as $rt) {
                if (!is_array($rt)) continue;
                $db = (int)($rt['days_before'] ?? -1);
                $t = $rt['time'] ?? '';
                if ($db < 0 || $db > 7) respond(['error' => 'days_before должен быть 0..7'], 400);
                if (!preg_match('/^\d{1,2}:\d{2}$/', $t)) respond(['error' => 'Некорректный формат времени'], 400);
                $cleaned[] = ['days_before' => $db, 'time' => $t];
            }
            $reminderTimesJson = $cleaned ? json_encode($cleaned, JSON_UNESCAPED_UNICODE) : null;
        }

        $supStmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
        $supStmt->execute([$supplierId]);
        if (!$supStmt->fetchColumn()) respond(['error' => 'Поставщик не найден'], 404);

        // Уникальный ключ — (supplier_id, delivery_dow). Перезаписываем правило.
        $exists = $pdo->prepare("SELECT id FROM supplier_default_deadlines WHERE supplier_id = ? AND delivery_dow = ?");
        $exists->execute([$supplierId, $deliveryDow]);
        $id = $exists->fetchColumn();
        if ($id) {
            $pdo->prepare("UPDATE supplier_default_deadlines SET deadline_dow = ?, deadline_time = ?, reminder_times = ? WHERE id = ?")
                ->execute([$deadlineDow, $deadlineTime, $reminderTimesJson, $id]);
        } else {
            $pdo->prepare("INSERT INTO supplier_default_deadlines (supplier_id, delivery_dow, deadline_dow, deadline_time, reminder_times) VALUES (?, ?, ?, ?, ?)")
                ->execute([$supplierId, $deliveryDow, $deadlineDow, $deadlineTime, $reminderTimesJson]);
        }
        respond(['success' => true]);
    }

    if ($fn === 'delete_supplier_default_deadline') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'supplier-schedule', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $supplierId = trim((string)($body['supplier_id'] ?? ''));
        $deliveryDow = (int)($body['delivery_dow'] ?? 0);
        if (!$supplierId || !in_array($deliveryDow, [1,2,3,4,5,6,7], true)) {
            respond(['error' => 'Некорректные данные'], 400);
        }
        $pdo->prepare("DELETE FROM supplier_default_deadlines WHERE supplier_id = ? AND delivery_dow = ?")
            ->execute([$supplierId, $deliveryDow]);
        respond(['success' => true]);
    }

    if ($fn === 'delete_supplier_schedule_row') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'supplier-schedule', 'full', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $supplierId = trim((string)($body['supplier_id'] ?? ''));
        $restaurantId = (int)($body['restaurant_id'] ?? 0);
        $orderDay = (int)($body['order_day'] ?? 0);
        if (!$supplierId || !$restaurantId || !$orderDay) {
            respond(['error' => 'Некорректные данные'], 400);
        }

        // Проверка доступа к юр.лицу ресторана
        $restStmt = $pdo->prepare("SELECT legal_entity FROM restaurants WHERE id = ?");
        $restStmt->execute([$restaurantId]);
        $rest = $restStmt->fetch();
        if ($rest && !checkLegalEntityAccess($caller, $rest['legal_entity'])) {
            respond(['error' => 'Нет доступа'], 403);
        }

        $pdo->prepare("DELETE FROM supplier_schedules WHERE supplier_id = ? AND restaurant_id = ? AND order_day = ?")
            ->execute([$supplierId, $restaurantId, $orderDay]);
        $pdo->prepare("DELETE FROM supplier_schedule_deadlines WHERE supplier_id = ? AND restaurant_id = ? AND order_day = ?")
            ->execute([$supplierId, $restaurantId, $orderDay]);

        respond(['success' => true]);
    }

    respond(['error'=>'Not found'], 404);
}
