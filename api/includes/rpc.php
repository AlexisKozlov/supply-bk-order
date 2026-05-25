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
    require __DIR__ . '/rpc/stock_collection.php';

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
    require __DIR__ . '/rpc/prices.php';

    // ═══ Тендеры: сохранить тендер целиком ═══
    require __DIR__ . '/rpc/tenders.php';

    // ═══ Рецептуры: импорт из JSON (парсинг на фронте) ═══
    require __DIR__ . '/rpc/pallets.php';
    require __DIR__ . '/rpc/recipes.php';

    // ═══ Паллетовка: импорт справочника ═══
    // (find_recipes_by_names ушёл в rpc/recipes.php вместе с остальными)

    // ═══ Рецептуры: поиск по именам (для автопривязки) ═══

    require __DIR__ . '/rpc/bug_reports.php';


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

    require __DIR__ . '/rpc/dist.php';

    // ═══ Telegram Bot Admin ═══
    // Все tg_admin_* — только для роли admin: рассылки, webhook, отвязки, статистика.

    require __DIR__ . '/rpc/tg_admin.php';

    // ═══ Корректировки заказов ═══

    require __DIR__ . '/rpc/corrections.php';

    // ═══ Оплаты поставщиков ═══

    require __DIR__ . '/rpc/payments.php';

    require __DIR__ . '/rpc/dashboard.php';

    // ═══ Чат с ресторанами ═══

    require __DIR__ . '/rpc/chat.php';

    require __DIR__ . '/rpc/protocols.php';

    require __DIR__ . '/rpc/surveys.php';

    // ═══ Модуль "График поставок" (supplier-schedule) ═══
    // Управление расписанием подачи заявок и доставок:
    // - supplier_schedules: связка поставщик↔ресторан + дни заказа/доставки
    // - supplier_schedule_deadlines: точечный дедлайн на (поставщик, ресторан, день)
    // - supplier_default_deadlines: дефолтные дедлайны на уровне поставщика (read-only здесь,
    //   редактируется в модуле "Заявки поставщикам" при register-supplier)

    require __DIR__ . '/rpc/schedules.php';

    respond(['error'=>'Not found'], 404);
}
