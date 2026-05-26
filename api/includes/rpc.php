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
    require __DIR__ . "/rpc/email.php";

    // ════════════════════════════════════════════════════════════════════
    // Отправка прогнозного плана поставщику по email с портала
    // (account=order, Reply-To=order@). По аналогии с send_supplier_order_email.
    // Тема: «План для <supplier> от <ЮЛ> на <P1>—<Pn>».
    // Тело — короткий текст со списком периодов; детали в Excel-вложении.
    // ════════════════════════════════════════════════════════════════════

    require __DIR__ . '/rpc/users_rbac.php';

    // Одноразовый download-токен для скачивания файла. Заменяет
    // session_token в ?token=. Живёт 15 минут, пишется в download_tokens,
    // принимается uploads-обработчиками через ?dl=. Принимает file_path
    // (относительно api/uploads/) для аудита; реальная авторизация на
    // конкретный файл всё равно делается uploads.php.

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


    require __DIR__ . '/rpc/notifications.php';
    require __DIR__ . '/rpc/online.php';
    // (batch_update_received_qty ушёл в rpc/orders.php)
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

    require __DIR__ . '/rpc/warehouse_cells.php';

    // (replace_order_items / delete_order / replace_item_order / calculate_adu ушли в rpc/orders.php)


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
    require __DIR__ . '/rpc/orders.php';


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
