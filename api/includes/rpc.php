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
        try { $pdo->prepare("INSERT INTO login_log (email, user_name, ip, created_at) VALUES (?, ?, ?, NOW())")->execute([$email, $u['name'], $clientIp]); } catch (PDOException $e) {}
        $mm = $pdo->prepare("SELECT `key`,`value` FROM settings WHERE `key` IN ('maintenance_mode','maintenance_message')"); $mm->execute();
        $mmRows = $mm->fetchAll(); $maintenanceVal = 'false'; $maintenanceMsg = '';
        foreach ($mmRows as $mr) { if ($mr['key'] === 'maintenance_mode') $maintenanceVal = $mr['value']; if ($mr['key'] === 'maintenance_message') $maintenanceMsg = $mr['value']; }
        $hiddenMods = ($u['hidden_modules'] && is_string($u['hidden_modules'])) ? (json_decode($u['hidden_modules'], true) ?? []) : [];
        respond(['success'=>true,'user'=>['name'=>$u['name'],'role'=>$u['role']??'user','display_role'=>$displayRole,'legal_entities'=>$le,'permissions'=>$permsDecoded,'created_at'=>$u['created_at'] ?? null,'telegram_connected'=>!empty($u['telegram_chat_id']),'hidden_modules'=>$hiddenMods],'session_token'=>$sessionToken,'maintenance_mode'=>$maintenanceVal==='true','maintenance_message'=>$maintenanceMsg ?: null]);
    }
    if ($fn === 'check_legacy_password') {
        $pwd = $body['pwd'] ?? '';
        if (!checkRateLimit($pdo, $clientIp)) respond(['success'=>false,'error'=>'too_many_attempts'], 429);
        $s = $pdo->prepare("SELECT value FROM settings WHERE `key`='order_calculator_password'"); $s->execute();
        $stored = $s->fetchColumn();
        if ($stored) {
            $isLegacyPlain = strncmp($stored, '$2', 2) !== 0;
            $ok = password_verify($pwd, $stored) || ($isLegacyPlain && hash_equals($stored, $pwd));
            if ($ok) {
                if ($isLegacyPlain) {
                    $hash = password_hash($pwd, PASSWORD_BCRYPT);
                    $pdo->prepare("UPDATE settings SET value=? WHERE `key`='order_calculator_password'")->execute([$hash]);
                    error_log("Legacy password migrated to bcrypt for setting: order_calculator_password");
                }
                respond(['success'=>true]);
            }
            recordFailedLogin($pdo, $clientIp, '_legacy');
        }
        respond(['success'=>false]);
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
        $s = $pdo->query("SELECT id, name, analogs, updated_by, updated_at FROM cards ORDER BY name");
        respond($s->fetchAll());
    }
    if ($fn === 'get_cards_last_update') {
        $s = $pdo->prepare("SELECT `value` FROM settings WHERE `key`='last_update'"); $s->execute();
        $row = $s->fetch();
        respond($row ?: ['value' => null]);
    }

    // Артикулы на остатках (для поиска карточек)
    if ($fn === 'get_stock_skus') {
        $s = $pdo->prepare("SELECT a.sku, p.name, a.stock, COALESCE(p.qty_per_box, 1) as qty_per_box FROM analysis_data a LEFT JOIN products p ON p.sku = a.sku AND p.legal_entity = a.legal_entity AND p.is_active = 1 WHERE a.legal_entity = ? AND a.stock > 0");
        $s->execute(['ООО "Бургер БК"']);
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
        $s2 = $pdo->prepare("SELECT id, product_name, product_sku, unit, sort_order, note FROM stock_collection_products WHERE collection_id = ? ORDER BY sort_order");
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
                if ($sv < 0 || $sv > 999999) continue;
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

    // ═══ VEG ORDER: публичные RPC (заказ овощей — форма ресторанов) ═══

    // Хелпер: dual-auth — работает через veg_token ИЛИ через ro_token (кабинет ресторанов)
    function vegResolveAuth($pdo, $body) {
        // Путь 1: токен-ссылка (старый способ)
        $tokenVal = $body['token_value'] ?? '';
        if ($tokenVal && preg_match('/^[a-f0-9]{64}$/', $tokenVal)) {
            $s = $pdo->prepare("SELECT t.session_id, t.expires_at, s.name as session_name, s.status, s.date_from, s.date_to
                FROM veg_tokens t JOIN veg_sessions s ON s.id = t.session_id WHERE t.token = ?");
            $s->execute([$tokenVal]);
            $row = $s->fetch();
            if (!$row) return ['error' => 'not_found', 'expired' => true];
            if (strtotime($row['expires_at']) < time()) return ['error' => 'expired', 'expired' => true];
            if ($row['status'] === 'closed') return ['error' => 'closed', 'expired' => true];
            return ['session_id' => $row['session_id'], 'session_name' => $row['session_name'],
                    'date_from' => $row['date_from'], 'date_to' => $row['date_to'], 'auth' => 'token'];
        }
        // Путь 2: ro_token (кабинет ресторана)
        $roToken = $_SERVER['HTTP_X_RO_TOKEN'] ?? '';
        if ($roToken) {
            $s = $pdo->prepare("SELECT ru.restaurant_number, ru.session_active_until
                FROM ro_users ru WHERE ru.session_token = ? AND ru.is_active = 1");
            $s->execute([$roToken]);
            $user = $s->fetch();
            if (!$user) return ['error' => 'invalid_token'];
            if ($user['session_active_until'] && strtotime($user['session_active_until']) < time()) return ['error' => 'expired'];
            // Находим активную veg-сессию
            $vs = $pdo->query("SELECT id, name, status, date_from, date_to FROM veg_sessions WHERE status = 'active' ORDER BY id DESC LIMIT 1");
            $sess = $vs->fetch();
            if (!$sess) return ['error' => 'no_active_session'];
            return ['session_id' => $sess['id'], 'session_name' => $sess['name'],
                    'date_from' => $sess['date_from'], 'date_to' => $sess['date_to'],
                    'restaurant_number' => $user['restaurant_number'], 'auth' => 'ro_token'];
        }
        return ['error' => 'no_auth'];
    }

    if ($fn === 'veg_validate_token') {
        $auth = vegResolveAuth($pdo, $body);
        if (isset($auth['error'])) respond($auth);
        // Товары сессии
        $s2 = $pdo->prepare("SELECT id, product_name, unit, multiplicity, sort_order FROM veg_session_products WHERE session_id = ? ORDER BY sort_order");
        $s2->execute([$auth['session_id']]);
        $products = $s2->fetchAll();
        $result = ['session_id' => $auth['session_id'], 'session_name' => $auth['session_name'], 'products' => $products];
        if (isset($auth['restaurant_number'])) $result['restaurant_number'] = $auth['restaurant_number'];
        respond($result);
    }
    if ($fn === 'veg_get_restaurants') {
        // Если передан tg_chat_id — возвращаем только рестораны, на которые подписан этот чат
        $tgChatId = isset($body['tg_chat_id']) ? trim((string)$body['tg_chat_id']) : '';
        if ($tgChatId !== '' && preg_match('/^-?\d+$/', $tgChatId)) {
            $subs = $pdo->prepare("SELECT DISTINCT restaurant_number FROM veg_telegram_subs WHERE chat_id = ?");
            $subs->execute([$tgChatId]);
            $allowedNums = array_column($subs->fetchAll(), 'restaurant_number');
            if (empty($allowedNums)) {
                respond([]);
            }
            $ph = implode(',', array_fill(0, count($allowedNums), '?'));
            $s = $pdo->prepare("SELECT id, number, address, city, region, legal_entity_group
                FROM restaurants WHERE active = 1 AND number IN ({$ph})
                ORDER BY legal_entity_group = 'BK_VM' DESC, sort_order, number");
            $s->execute($allowedNums);
            $rows = $s->fetchAll();
            // Убираем дубли по number (один номер может быть в нескольких юрлицах)
            $seen = [];
            $unique = [];
            foreach ($rows as $r) {
                if (!isset($seen[$r['number']])) {
                    $seen[$r['number']] = true;
                    $unique[] = $r;
                }
            }
            usort($unique, function($a, $b) { return intval($a['number']) - intval($b['number']); });
            respond($unique);
        }

        // Все активные рестораны (без фильтра по юрлицу — овощи для всех)
        // GROUP BY number — убираем дубли (один ресторан может быть в нескольких юрлицах)
        // Приоритет BK_VM (основная запись с полным адресом)
        $s = $pdo->prepare("SELECT id, number, address, city, region, legal_entity_group
            FROM restaurants WHERE active = 1 AND legal_entity_group = 'BK_VM'
            ORDER BY sort_order, number");
        $s->execute();
        $rows = $s->fetchAll();
        // Добавляем PS-рестораны, которых нет в BK_VM
        $existingNums = array_flip(array_column($rows, 'number'));
        $s2 = $pdo->prepare("SELECT id, number, address, city, region, legal_entity_group
            FROM restaurants WHERE active = 1 AND legal_entity_group != 'BK_VM'
            ORDER BY sort_order, number");
        $s2->execute();
        foreach ($s2->fetchAll() as $r) {
            if (!isset($existingNums[$r['number']])) $rows[] = $r;
        }
        usort($rows, function($a, $b) { return intval($a['number']) - intval($b['number']); });
        respond($rows);
    }
    if ($fn === 'veg_get_schedule') {
        $restNum = $body['restaurant_number'] ?? '';
        $tokenVal = $body['token_value'] ?? '';

        // Dual-auth: определяем сессию и ресторан
        $auth = vegResolveAuth($pdo, $body);
        if (isset($auth['error'])) respond($auth);
        // При ro_token ресторан берём из авторизации
        if (isset($auth['restaurant_number']) && !$restNum) $restNum = $auth['restaurant_number'];

        if (!$restNum || !preg_match('/^\d{1,5}$/', $restNum)) respond(['error' => 'invalid_restaurant']);
        $s = $pdo->prepare("SELECT day_of_week FROM veg_delivery_days WHERE restaurant_number = ? ORDER BY day_of_week");
        $s->execute([$restNum]);
        $days = array_column($s->fetchAll(), 'day_of_week');
        // Дедлайны из БД
        $dlRows = $pdo->query("SELECT delivery_dow, deadline_dow, deadline_time FROM veg_deadline_rules")->fetchAll();
        $deadlineRules = [];
        foreach ($dlRows as $r) $deadlineRules[(int)$r['delivery_dow']] = $r;

        $tz = new DateTimeZone('Europe/Minsk');
        $now = new DateTime('now', $tz);

        // Диапазон дат из сессии (через auth)
        $dateFrom = $auth['date_from'] ?? null;
        $dateTo = $auth['date_to'] ?? null;

        // Проверяем per-session конфиг дней (veg_session_day_config)
        $sessionId = $auth['session_id'];
        $dayConfig = []; // date → [restNums]
        $hasConfig = false;
        if ($sessionId) {
            $cfgSt = $pdo->prepare("SELECT delivery_date, restaurant_number FROM veg_session_day_config WHERE session_id = ?");
            $cfgSt->execute([$sessionId]);
            foreach ($cfgSt->fetchAll() as $c) {
                $dayConfig[$c['delivery_date']][] = $c['restaurant_number'];
            }
            $hasConfig = !empty($dayConfig);
        }

        // Функция расчёта дедлайна
        $calcDeadline = function($dateObj, $dow) use ($deadlineRules, $now) {
            $deadline = null; $deadlineStr = null; $expired = false;
            if (isset($deadlineRules[$dow])) {
                $rule = $deadlineRules[$dow];
                $deadline = clone $dateObj;
                $diff = $dow - (int)$rule['deadline_dow'];
                if ($diff <= 0) $diff += 7;
                $deadline->modify("-{$diff} days");
                $tp = explode(':', $rule['deadline_time']);
                $deadline->setTime((int)$tp[0], (int)($tp[1] ?? 0), 0);
                $deadlineStr = $deadline->format('Y-m-d H:i:s');
            }
            $expired = $deadline && $now >= $deadline;
            return [$deadlineStr, $expired];
        };

        $deliveries = [];
        if ($dateFrom && $dateTo) {
            $cursor = new DateTime($dateFrom, $tz);
            $end = new DateTime($dateTo, $tz);
            while ($cursor <= $end) {
                $dow = (int)$cursor->format('N');
                $dateStr = $cursor->format('Y-m-d');
                // Определяем доступ: per-session конфиг или глобальное расписание
                $canOrder = isset($dayConfig[$dateStr])
                    ? in_array($restNum, $dayConfig[$dateStr])
                    : in_array($dow, $days);
                if ($canOrder) {
                    [$deadlineStr, $expired] = $calcDeadline($cursor, $dow);
                    $deliveries[] = ['date' => $dateStr, 'day_of_week' => $dow, 'deadline' => $deadlineStr, 'expired' => $expired];
                }
                $cursor->modify('+1 day');
            }
        } elseif (!empty($days)) {
            // Обратная совместимость: без диапазона дат
            $currentDow = (int)$now->format('N');
            $daysUntilSaturday = max(0, 6 - $currentDow);
            for ($offset = 0; $offset <= $daysUntilSaturday && count($deliveries) < 2; $offset++) {
                $date = clone $now;
                $date->modify("+{$offset} days");
                $dow = (int)$date->format('N');
                if (in_array($dow, $days)) {
                    [$deadlineStr, $expired] = $calcDeadline($date, $dow);
                    if ($offset === 0 && $expired) continue;
                    $deliveries[] = ['date' => $date->format('Y-m-d'), 'day_of_week' => $dow, 'deadline' => $deadlineStr, 'expired' => $expired];
                }
            }
        }
        respond(['days' => array_map('intval', $days), 'deliveries' => $deliveries]);
    }
    if ($fn === 'veg_get_previous_orders') {
        $restNum = $body['restaurant_number'] ?? '';
        $auth = vegResolveAuth($pdo, $body);
        if (isset($auth['error'])) respond($auth);
        if (isset($auth['restaurant_number']) && !$restNum) $restNum = $auth['restaurant_number'];
        if (!$restNum || !preg_match('/^\d{1,5}$/', $restNum)) respond(['error' => 'invalid_restaurant']);
        $tok = ['session_id' => $auth['session_id']];
        // Найти предыдущую сессию с заказами этого ресторана (проверяем до 5 сессий назад)
        $prev = $pdo->prepare("SELECT id FROM veg_sessions WHERE id < ? ORDER BY id DESC LIMIT 5");
        $prev->execute([$tok['session_id']]);
        $prevSessions = $prev->fetchAll();
        $orders = [];
        foreach ($prevSessions as $ps) {
            $st = $pdo->prepare("
                SELECT sp.product_name, o.delivery_date, o.quantity, o.admin_qty
                FROM veg_orders o
                JOIN veg_session_products sp ON sp.id = o.product_id
                WHERE o.session_id = ? AND o.restaurant_number = ?
            ");
            $st->execute([$ps['id'], $restNum]);
            $orders = $st->fetchAll();
            if ($orders) break; // нашли сессию с заказами — используем её
        }
        respond(['orders' => $orders]);
    }
    if ($fn === 'veg_get_existing_orders') {
        $restNum = $body['restaurant_number'] ?? '';
        $auth = vegResolveAuth($pdo, $body);
        if (isset($auth['error'])) respond($auth);
        if (isset($auth['restaurant_number']) && !$restNum) $restNum = $auth['restaurant_number'];
        if (!$restNum || !preg_match('/^\d{1,5}$/', $restNum)) respond(['error' => 'invalid_restaurant']);
        $tok = ['session_id' => $auth['session_id']];
        $st = $pdo->prepare("SELECT product_id, delivery_date, quantity, admin_qty FROM veg_orders WHERE session_id = ? AND restaurant_number = ?");
        $st->execute([$tok['session_id'], $restNum]);
        respond(['orders' => $st->fetchAll()]);
    }
    if ($fn === 'veg_submit_order') {
        $restNum = $body['restaurant_number'] ?? '';
        $items = $body['items'] ?? []; // [{product_id, delivery_date, quantity}]
        $auth = vegResolveAuth($pdo, $body);
        if (isset($auth['error'])) respond($auth);
        if (isset($auth['restaurant_number']) && !$restNum) $restNum = $auth['restaurant_number'];
        if (!$restNum || !preg_match('/^\d{1,5}$/', $restNum)) respond(['error' => 'invalid_restaurant']);
        if (!checkRateLimit($pdo, $clientIp, 60, 5)) respond(['error' => 'too_many_attempts'], 429);
        $sessId = $auth['session_id'];
        // Проверяем что сессия активна
        $sc = $pdo->prepare("SELECT status FROM veg_sessions WHERE id = ?");
        $sc->execute([$sessId]);
        $sess = $sc->fetch();
        if (!$sess || $sess['status'] !== 'active') respond(['error' => 'session_closed']);
        // Допустимые product_id
        $validPids = $pdo->prepare("SELECT id FROM veg_session_products WHERE session_id = ?");
        $validPids->execute([$sessId]);
        $allowedSet = array_flip(array_column($validPids->fetchAll(), 'id'));
        // Проверка дедлайнов (серверная) — правила из БД
        $tz = new DateTimeZone('Europe/Minsk');
        $now = new DateTime('now', $tz);
        $dlRows = $pdo->query("SELECT delivery_dow, deadline_dow, deadline_time FROM veg_deadline_rules")->fetchAll();
        $deadlineRules = [];
        foreach ($dlRows as $r) $deadlineRules[(int)$r['delivery_dow']] = $r;

        $ins = $pdo->prepare("INSERT INTO veg_orders (session_id, product_id, restaurant_number, delivery_date, quantity, submitted_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), admin_qty = NULL, submitted_at = NOW()");
        $pdo->beginTransaction();
        try {
            // Даты, по которым ресторан отправляет форму (включая полностью пустые дни)
            $submittedDates = $body['submitted_dates'] ?? [];
            $clearDates = [];
            foreach ($submittedDates as $sd) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd)) continue;
                $delDt = new DateTime($sd, $tz);
                $dow = (int)$delDt->format('N');
                if (isset($deadlineRules[$dow])) {
                    $rule = $deadlineRules[$dow];
                    $deadlineDow = (int)$rule['deadline_dow'];
                    $deadline = clone $delDt;
                    $diff = $dow - $deadlineDow;
                    if ($diff <= 0) $diff += 7;
                    $deadline->modify("-{$diff} days");
                    $timeParts = explode(':', $rule['deadline_time']);
                    $deadline->setTime((int)$timeParts[0], (int)($timeParts[1] ?? 0), 0);
                    if ($now >= $deadline) continue;
                }
                $clearDates[$sd] = true;
            }

            // Удаляем старые записи без admin_qty для всех дат, по которым ресторан отправил форму
            // (если поле стёрто — запись удалится; если заполнено — перезапишется ниже)
            if ($clearDates) {
                $datePlaceholders = implode(',', array_fill(0, count($clearDates), '?'));
                $del = $pdo->prepare("DELETE FROM veg_orders WHERE session_id=? AND restaurant_number=? AND delivery_date IN ({$datePlaceholders}) AND (admin_qty IS NULL)");
                $del->execute(array_merge([$sessId, $restNum], array_keys($clearDates)));
            }

            foreach ($items as $item) {
                $pid = intval($item['product_id'] ?? 0);
                $delDate = $item['delivery_date'] ?? '';
                $qty = floatval($item['quantity'] ?? 0);
                if ($qty < 0 || $qty > 999999) continue;
                if ($pid <= 0 || !isset($allowedSet[$pid])) continue;
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $delDate)) continue;
                if (!isset($clearDates[$delDate])) continue;
                $ins->execute([$sessId, $pid, $restNum, $delDate, $qty]);
            }
            $pdo->commit();

            // Уведомление подписчикам в Telegram
            try {
                $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
                if ($botToken && $restNum) {
                    $subs = $pdo->prepare("SELECT chat_id FROM veg_telegram_subs WHERE restaurant_number=? AND notify_confirmations = 1");
                    $subs->execute([$restNum]);
                    $chatIds = $subs->fetchAll(PDO::FETCH_COLUMN);
                    if ($chatIds) {
                        // Собираем текст заявки
                        $pn = $pdo->prepare("SELECT id, product_name FROM veg_session_products WHERE session_id=?");
                        $pn->execute([$sessId]);
                        $prodNames = [];
                        foreach ($pn->fetchAll() as $vp) $prodNames[$vp['id']] = $vp['product_name'];
                        $lines = [];
                        foreach ($items as $it) {
                            $pid = intval($it['product_id'] ?? 0);
                            $qty = floatval($it['quantity'] ?? 0);
                            if (!isset($prodNames[$pid])) continue;
                            $dd = $it['delivery_date'] ?? '';
                            if ($qty > 0) {
                                $lines[] = "  • {$prodNames[$pid]}: {$qty} (доставка {$dd})";
                            }
                        }
                        {
                            // Получаем адрес ресторана
                            $ra = $pdo->prepare("SELECT address FROM restaurants WHERE number=? AND legal_entity_group='BK_VM' LIMIT 1");
                            $ra->execute([$restNum]);
                            $addr = $ra->fetchColumn() ?: $restNum;
                            $msgText = "✅ <b>Заявка на овощи отправлена</b>\n\n";
                            $msgText .= "🏪 Ресторан <b>{$restNum}</b> — {$addr}\n\n";
                            if ($lines) {
                                $msgText .= implode("\n", $lines);
                            } else {
                                $msgText .= "<i>Все товары: 0 (ничего не нужно)</i>";
                            }
                            // Логируем что уведомление отправлено
                            $pdo->prepare("INSERT IGNORE INTO veg_reminder_log (session_id, restaurant_number, delivery_date, reminder_type) VALUES (?, ?, CURDATE(), 'submitted')")
                                ->execute([$sessId, $restNum]);
                            foreach ($chatIds as $cid) {
                                $payload = json_encode(['chat_id' => $cid, 'text' => $msgText, 'parse_mode' => 'HTML']);
                                $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
                                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 5]);
                                curl_exec($ch); curl_close($ch);
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                error_log('veg_submit_order telegram notify error: ' . $e->getMessage());
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('veg_submit_order error: ' . $e->getMessage());
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
        $hiddenMods2 = ($sessionUser['hidden_modules'] && is_string($sessionUser['hidden_modules'])) ? (json_decode($sessionUser['hidden_modules'], true) ?? []) : [];
        respond(['valid' => true, 'user' => ['name' => $sessionUser['name'], 'role' => $sessionUser['role'] ?? 'user', 'display_role' => $sessionUser['display_role'] ?? null, 'legal_entities' => $le, 'permissions' => $permsDecoded2, 'created_at' => $sessionUser['created_at'] ?? null, 'telegram_connected' => !empty($sessionUser['telegram_chat_id']), 'hidden_modules' => $hiddenMods2]]);
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
            $tgMsg = "✅ Аккаунт <b>" . htmlspecialchars($sessionUser['name'], ENT_QUOTES, 'UTF-8') . "</b> привязан!\n\nТеперь вам доступны все команды бота.\nНажмите /start для меню.";
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
            $ins = $pdo->prepare("INSERT INTO stock_collection_products (collection_id, product_name, product_sku, unit, sort_order, note) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($products as $i => $p) {
                $pname = mb_substr($p['name'] ?? '', 0, 255);
                $psku = mb_substr($p['sku'] ?? '', 0, 50) ?: null;
                $punit = in_array($p['unit'] ?? '', ['boxes', 'pieces', 'kg', 'liters']) ? $p['unit'] : 'pieces';
                $pnote = mb_substr($p['note'] ?? '', 0, 500) ?: null;
                $ins->execute([$collId, $pname, $psku, $punit, $i, $pnote]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('sc_create_collection error: ' . $e->getMessage());
            respond(['error' => 'Ошибка создания сбора'], 500);
        }
        // Автоматически создаём токен (7 дней)
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+7 days'));
        $tokenStmt = $pdo->prepare("INSERT INTO stock_collection_tokens (collection_id, token, created_by, expires_at) VALUES (?, ?, ?, ?)");
        $tokenStmt->execute([$collId, $token, $uname, $expires]);

        // Уведомляем рестораны о новом сборе
        scNotifyRestaurants($pdo, $collId, $name, count($products));

        auditLog($pdo, 'collection_created', 'stock_collection', $collId, $uname, ['legal_entity' => $le, 'name' => $name, 'products_count' => count($products)]);
        respond(['id' => $collId, 'token' => $token, 'expires_at' => $expires]);
    }

    // Повторная отправка уведомлений ресторанам о сборе
    if ($fn === 'sc_notify_restaurants') {
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
        // Инвалидируем все токены этого сбора
        $pdo->prepare("UPDATE stock_collection_tokens SET expires_at = NOW() WHERE collection_id = ? AND expires_at > NOW()")->execute([$collId]);
        auditLog($pdo, 'collection_closed', 'stock_collection', $collId, $authUserName, ['legal_entity' => $collRow['legal_entity']]);
        respond(['success' => true]);
    }
    if ($fn === 'sc_delete_collection') {
        $collId = intval($body['collection_id'] ?? 0);
        if (!$collId) respond(['error' => 'Не все параметры указаны'], 400);
        $collCheck = $pdo->prepare("SELECT id, name, legal_entity FROM stock_collections WHERE id = ?");
        $collCheck->execute([$collId]);
        $collRow = $collCheck->fetch();
        if (!$collRow) respond(['error' => 'Коллекция не найдена'], 404);
        if ($authUser && !checkLegalEntityAccess($authUser, $collRow['legal_entity'])) respond(['error' => 'Нет доступа'], 403);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM stock_collection_data WHERE collection_id = ?")->execute([$collId]);
            $pdo->prepare("DELETE FROM stock_collection_tokens WHERE collection_id = ?")->execute([$collId]);
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
        $collId = intval($body['collection_id'] ?? 0);
        if (!$collId) respond(['error' => 'Не все параметры указаны'], 400);
        // Проверяем доступ к юрлицу коллекции
        $collCheck = $pdo->prepare("SELECT legal_entity FROM stock_collections WHERE id = ?");
        $collCheck->execute([$collId]);
        $collRow = $collCheck->fetch();
        if (!$collRow) respond(['error' => 'Коллекция не найдена'], 404);
        if ($authUser && !checkLegalEntityAccess($authUser, $collRow['legal_entity'])) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        // Товары
        $s = $pdo->prepare("SELECT id, product_name, product_sku, unit, sort_order, note FROM stock_collection_products WHERE collection_id = ? ORDER BY sort_order");
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
        $s2 = $pdo->prepare("SELECT name FROM users WHERE id=?"); $s2->execute([$userId]); $target = $s2->fetch();
        if ($target && $target['name'] === $callerName) respond(['success' => false, 'error' => 'Нельзя удалить самого себя'], 400);
        // Удаляем активные сессии пользователя, чтобы он не мог продолжать работу
        if ($target) {
            $pdo->prepare("DELETE FROM user_sessions WHERE user_name=?")->execute([$target['name']]);
            $pdo->prepare("DELETE FROM user_presence WHERE user_name=?")->execute([$target['name']]);
        }
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$userId]);
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
        $s = $pdo->query("SELECT user_name, page, last_seen FROM user_presence WHERE last_seen > NOW() - INTERVAL 2 MINUTE ORDER BY last_seen DESC");
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
                    FROM veg_telegram_subs
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
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM `analysis_data` WHERE `legal_entity`=?")->execute([$legalEntity]);
            // Готовим один statement для всех записей
            $firstItem = array_intersect_key($items[0], array_flip($allowed));
            $cols = array_keys($firstItem);
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $cn = implode(',', array_map(fn($c) => "`$c`", $cols));
            $stmt = $pdo->prepare("INSERT INTO `analysis_data` ($cn) VALUES ($ph)");
            foreach ($items as $item) {
                $item = array_intersect_key($item, array_flip($allowed));
                if (empty($item)) continue;
                $stmt->execute(array_values($item));
            }
            $pdo->commit();
            auditLog($pdo, 'data_imported', 'import', null, $caller['name'], ['type' => 'analysis_data', 'legal_entity' => $legalEntity, 'count' => count($items)]);
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
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $items = $body['items'] ?? [];
        if (!is_array($items) || empty($items)) respond(['error' => 'Нет данных'], 400);
        try {
            // Определяем дату загружаемого файла
            $uploadDate = $items[0]['report_date'] ?? '';
            if (!$uploadDate) respond(['error' => 'Нет даты в данных'], 400);

            // Для каждого юрлица проверяем: не старше ли загружаемая дата максимальной в базе
            $entities = array_unique(array_column($items, 'legal_entity'));
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
        $entity = $body['entity'] ?? '';
        $from = $body['date_from'] ?? '';
        $to = $body['date_to'] ?? '';
        if (!$entity || !$from || !$to) respond(['error' => 'Не указаны обязательные параметры'], 400);
        // Расширяем диапазон на +3 дня чтобы захватить понедельник для последних выходных месяца
        $st = $pdo->prepare("SELECT report_date, stock_type, cell_count, is_manual FROM warehouse_cells WHERE legal_entity=? AND report_date >= ? AND report_date <= DATE_ADD(?, INTERVAL 3 DAY) AND stock_type IN ('cold','frozen') ORDER BY report_date, stock_type");
        $st->execute([$entity, $from, $to]);
        respond($st->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($fn === 'get_warehouse_cells') {
        $days = intval($body['days'] ?? 90);
        if ($days > 365) $days = 365;
        $st = $pdo->prepare("SELECT report_date, legal_entity, stock_type, cell_count, is_manual FROM warehouse_cells WHERE report_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY) ORDER BY report_date DESC, legal_entity, stock_type");
        $st->execute([$days]);
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
            $key = trim($p['sku']) . '|' . trim($p['legal_entity']);
            $bySku[$key] = $p;
            if (!empty($p['external_code'])) $byExt[trim($p['external_code']) . '|' . trim($p['legal_entity'])] = $p;
            if (!empty($p['gtin'])) $byGtin[trim($p['gtin']) . '|' . trim($p['legal_entity'])] = $p;
            // Также без юрлица — fallback
            if (!empty($p['external_code'])) $byExt[trim($p['external_code'])] = $byExt[trim($p['external_code'])] ?? $p;
            if (!empty($p['gtin'])) $byGtin[trim($p['gtin'])] = $byGtin[trim($p['gtin'])] ?? $p;
            $bySku[trim($p['sku'])] = $bySku[trim($p['sku'])] ?? $p;
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

        // Список юрлиц в группе выбранного юрлица: для БК/ВМ — оба, для ПС — только ПС
        $entities = getEntitiesInGroup(getEntityGroup($le));
        $matched = 0;
        $skipped = [];
        $upsert = $pdo->prepare("INSERT INTO product_prices (sku, supplier, legal_entity, price, vat_rate, unit_type, price_type, currency, updated_by)
            VALUES (?, ?, ?, ?, 0, 'box', 'deposit', 'BYN', ?)
            ON DUPLICATE KEY UPDATE price=VALUES(price), unit_type='box', updated_by=VALUES(updated_by), updated_at=NOW()");

        try {
            $pdo->beginTransaction();
            foreach ($uniquePrices as $up) {
                $matchedAny = false;
                // Для каждого юрлица пытаемся найти продукт
                foreach ($entities as $le) {
                    $product = null;
                    if ($up['ec']) $product = $byExt[$up['ec'] . '|' . $le] ?? null;
                    if (!$product && $up['gt']) $product = $byGtin[$up['gt'] . '|' . $le] ?? null;
                    if (!$product && $up['sk']) $product = $bySku[$up['sk'] . '|' . $le] ?? null;
                    // Fallback без юрлица
                    if (!$product && $up['ec']) $product = $byExt[$up['ec']] ?? null;
                    if (!$product && $up['gt']) $product = $byGtin[$up['gt']] ?? null;
                    if (!$product && $up['sk']) $product = $bySku[$up['sk']] ?? null;
                    if (!$product) continue;
                    $upsert->execute([
                        $product['sku'],
                        $product['supplier'] ?? '',
                        $le,
                        $up['price'],
                        $caller['name'] ?? 'admin',
                    ]);
                    $matched++;
                    $matchedAny = true;
                }
                if (!$matchedAny) {
                    $skipped[] = ['external_code' => $up['ec'], 'gtin' => $up['gt'], 'sku' => $up['sk'], 'name' => $up['name'], 'price' => $up['price']];
                }
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
        if (!checkLegalEntityAccess($caller, $ag['legal_entity'])) respond(['error' => 'Нет доступа к юр. лицу'], 403);
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
        if (!checkLegalEntityAccess($caller, $ag['legal_entity'])) respond(['error' => 'Нет доступа к юр. лицу'], 403);
        if ($ag['status'] !== 'archived') respond(['error' => 'Протокол не в архиве'], 400);
        $docType = $ag['doc_type'] ?? 'psc';
        $pdo->beginTransaction();
        try {
            // Архивируем текущий активный ПСЦ того же поставщика (аналогично approve_agreement)
            if ($docType === 'psc') {
                $pdo->prepare("UPDATE price_agreements SET status='archived' WHERE supplier=? AND legal_entity=? AND status='active' AND doc_type='psc'")->execute([$ag['supplier'], $ag['legal_entity']]);
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
        $sql = "SELECT pp.id, pp.sku, pp.price, pp.vat_rate, pp.unit_type, pp.currency, pp.supplier, pp.agreement_id, pp.updated_at FROM product_prices pp WHERE pp.legal_entity=? AND pp.price_type='purchase'";
        $params = [$le];
        if ($supplier) { $sql .= " AND pp.supplier=?"; $params[] = $supplier; }
        $s = $pdo->prepare($sql); $s->execute($params);
        $rows = $s->fetchAll();
        // Залоговые цены (отдельная выборка — для колонки «Залог» в прайс-листе)
        $dep = $pdo->prepare("SELECT sku, price FROM product_prices WHERE legal_entity=? AND price_type='deposit'");
        $dep->execute([$le]);
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
        $sql = "SELECT pp.id, pp.sku, pp.price, pp.updated_at, pp.updated_by,
                       COALESCE(p.name, '') AS name,
                       COALESCE(p.supplier, pp.supplier, '') AS supplier,
                       COALESCE(p.external_code, '') AS external_code,
                       COALESCE(p.gtin, '') AS gtin
                FROM product_prices pp
                LEFT JOIN products p ON p.sku = pp.sku AND p.legal_entity = pp.legal_entity AND p.is_active = 1
                WHERE pp.legal_entity = ? AND pp.price_type = 'deposit'
                ORDER BY p.name, pp.sku";
        $s = $pdo->prepare($sql); $s->execute([$le]);
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
        $applyToGroup = !empty($body['apply_to_group']); // применять к обоим юрлицам группы BK/VM
        if (!$sku || !$le) respond(['error' => 'Не указан SKU или юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $le)) respond(['error' => 'Нет доступа'], 403);
        if ($price !== null && $price <= 0) respond(['error' => 'Цена должна быть > 0'], 400);

        // Определяем список юрлиц: если просили применить к группе — берём список
        // всех юрлиц из соответствующей группы (BK_VM или PS).
        $targets = $applyToGroup ? getEntitiesInGroup(getEntityGroup($le)) : [$le];

        // Поставщик товара (для NOT NULL supplier в product_prices)
        $supStmt = $pdo->prepare("SELECT supplier FROM products WHERE sku = ? AND legal_entity = ? LIMIT 1");
        $supStmt->execute([$sku, $le]);
        $supplier = $supStmt->fetchColumn() ?: '';

        try {
            $pdo->beginTransaction();
            foreach ($targets as $targetLe) {
                if ($price === null) {
                    $pdo->prepare("DELETE FROM product_prices WHERE sku=? AND legal_entity=? AND price_type='deposit'")
                        ->execute([$sku, $targetLe]);
                } else {
                    $pdo->prepare("INSERT INTO product_prices (sku, supplier, legal_entity, price, vat_rate, unit_type, price_type, currency, updated_by)
                        VALUES (?, ?, ?, ?, 0, 'box', 'deposit', 'BYN', ?)
                        ON DUPLICATE KEY UPDATE price=VALUES(price), unit_type='box', updated_by=VALUES(updated_by), updated_at=NOW()")
                        ->execute([$sku, $supplier, $targetLe, $price, $caller['name'] ?? 'admin']);
                }
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('set_deposit_price error: ' . $e->getMessage());
            respond(['error' => 'Ошибка сохранения: ' . $e->getMessage()], 500);
        }
        auditLog($pdo, $price === null ? 'deposit_price_deleted' : 'deposit_price_updated', 'product_prices', null, $caller['name'], ['sku' => $sku, 'price' => $price, 'entities' => $targets]);
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
        applyEntityGroupFilter($le, $leWhere, $leParams, 'p.legal_entity_group');
        $sql .= " AND " . $leWhere[0];
        $params = array_merge($params, $leParams);
        if ($supplier) { $sql .= " AND p.supplier = ?"; $params[] = $supplier; }
        $sql .= " AND NOT EXISTS (SELECT 1 FROM product_prices pp WHERE pp.sku = p.sku AND pp.legal_entity = ? AND pp.price_type = 'purchase')";
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
            auditLog($pdo, $isNew ? 'tender_created' : 'tender_updated', 'tender', $tenderId, $caller['name'], ['name' => $name, 'legal_entity' => $le, 'status' => $status]);
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

        // Предложения + цены
        $s = $pdo->prepare("SELECT id, tender_id, supplier, delivery_days, payment_terms, conditions, note, created_at FROM tender_offers WHERE tender_id=? ORDER BY id"); $s->execute([$id]);
        $offers = $s->fetchAll();
        foreach ($offers as &$offer) {
            $s2 = $pdo->prepare("SELECT item_id, price, price_rub, price_byn FROM tender_offer_prices WHERE offer_id=?"); $s2->execute([$offer['id']]);
            $offer['prices'] = $s2->fetchAll();
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
        // Удалить файлы КП с диска
        $fs = $pdo->prepare("SELECT file_path FROM tender_files WHERE tender_id=?"); $fs->execute([$id]);
        while ($fp = $fs->fetchColumn()) {
            $fpath = __DIR__ . '/../uploads/tenders/' . basename($fp);
            if (file_exists($fpath)) unlink($fpath);
        }
        // CASCADE удалит items, offers, offer_prices, files
        $pdo->prepare("DELETE FROM tenders WHERE id=?")->execute([$id]);
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
        // Удалить файлы с диска
        $fs = $pdo->prepare("SELECT file_path FROM marketing_activity_files WHERE activity_id=?"); $fs->execute([$id]);
        while ($fp = $fs->fetchColumn()) {
            $fpath = __DIR__ . '/../uploads/marketing/' . basename($fp);
            if (file_exists($fpath)) unlink($fpath);
        }
        $pdo->prepare("DELETE FROM marketing_activities WHERE id=?")->execute([$id]);
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

        $group = $legalEntity ? getEntityGroup($legalEntity) : 'BK_VM';

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

        $group = $legalEntity ? getEntityGroup($legalEntity) : 'BK_VM';

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

        // Собрать все SKU ингредиентов для массового поиска аналогов
        $allSkus = [];
        $recipeIngs = [];
        foreach ($recipes as $r) {
            $s = $pdo->prepare("SELECT ri.*, p.analog_group, p.qty_per_box, p.unit_of_measure as product_unit, p.supplier as product_supplier
                FROM recipe_ingredients ri
                LEFT JOIN products p ON p.sku COLLATE utf8mb4_unicode_ci = ri.sku COLLATE utf8mb4_unicode_ci
                WHERE ri.recipe_id=? ORDER BY ri.sort_order");
            $s->execute([$r['id']]);
            $ings = $s->fetchAll();
            $recipeIngs[$r['id']] = $ings;
            foreach ($ings as $ing) {
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
                $s = $pdo->prepare("SELECT sku, analog_group, qty_per_box, unit_of_measure, supplier FROM products WHERE sku COLLATE utf8mb4_unicode_ci IN ($ph2)");
                $s->execute($allCardSkus);
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

        $group = $legalEntity ? getEntityGroup($legalEntity) : 'BK_VM';

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
        $id = $body['id'] ?? null;
        $name = trim($body['name'] ?? '');
        $keywords = $body['keywords'] ?? [];
        $recipeIds = $body['recipe_ids'] ?? [];
        $legalEntity = $body['legal_entity'] ?? null;
        if (!$name) respond(['error' => 'Укажите название группы'], 400);

        $group = $legalEntity ? getEntityGroup($legalEntity) : 'BK_VM';

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
        $id = $body['id'] ?? null;
        if (!$id) respond(['error' => 'Не указан ID'], 400);
        $pdo->prepare("DELETE FROM recipe_groups WHERE id=?")->execute([$id]);
        respond(['ok' => true]);
    }

    if ($fn === 'get_recipe_groups_list') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $legalEntity = $_GET['legal_entity'] ?? $body['legal_entity'] ?? null;
        $group = $legalEntity ? getEntityGroup($legalEntity) : 'BK_VM';
        $s = $pdo->prepare("SELECT g.id, g.name, g.keywords, COUNT(gi.id) as recipe_count FROM recipe_groups g LEFT JOIN recipe_group_items gi ON gi.group_id = g.id WHERE g.legal_entity_group = ? GROUP BY g.id ORDER BY g.name");
        $s->execute([$group]);
        $groups = $s->fetchAll(PDO::FETCH_ASSOC);
        // Для каждой группы загрузить рецептуры (только этого юрлица)
        foreach ($groups as &$g) {
            $s = $pdo->prepare("SELECT r.id, r.code, r.name FROM recipe_group_items gi JOIN recipes r ON r.id = gi.recipe_id WHERE gi.group_id = ? AND r.legal_entity_group = ? ORDER BY r.name");
            $s->execute([$g['id'], $group]);
            $g['recipes'] = $s->fetchAll(PDO::FETCH_ASSOC);
            $g['keywords'] = json_decode($g['keywords'] ?: '[]', true);
        }
        respond($groups);
    }

    // ═══ Паллетовка: импорт справочника ═══
    if ($fn === 'import_pallet_reference') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $items = $body['items'] ?? [];
        $legalEntity = $body['legal_entity'] ?? null;
        if (empty($items)) respond(['error' => 'Нет данных'], 400);
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
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
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $legalEntity = $_GET['legal_entity'] ?? $body['legal_entity'] ?? null;
        $group = $legalEntity ? getEntityGroup($legalEntity) : 'BK_VM';
        $st = $pdo->prepare("SELECT * FROM pallet_reference WHERE legal_entity_group = ? ORDER BY storage_category, name");
        $st->execute([$group]);
        respond($st->fetchAll(PDO::FETCH_ASSOC));
    }

    // ═══ Паллетовка: обновить поле (частота, кол-во коробок) ═══
    if ($fn === 'update_pallet_field') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
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
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $legalEntity = $_GET['legal_entity'] ?? $body['legal_entity'] ?? null;
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
        if (empty($names)) respond(['error' => 'Не указаны имена'], 400);

        // Загрузить все рецептуры разом (обычно ~500 шт) вместо запроса на каждое имя
        $allRecipes = $pdo->query("SELECT id, code, name FROM recipes")->fetchAll(PDO::FETCH_ASSOC);

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
        $itemWhitelist = ['sku','name','qty_boxes','qty_per_box','boxes_per_pallet','multiplicity','consumption_period','stock','transit','final_order','manual_override','unit_of_measure','analog_group','category','sort_order'];
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
        $orderCheck = $pdo->prepare("SELECT legal_entity FROM orders WHERE id=?");
        $orderCheck->execute([$orderId]);
        $orderRow = $orderCheck->fetch();
        if (!$orderRow) respond(['error' => 'Заказ не найден'], 404);
        if (!checkLegalEntityAccess($caller, $orderRow['legal_entity'])) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $expectedUpdatedAt = $body['expected_updated_at'] ?? null;
        // Белый список полей заказа
        $orderWhitelist = ['supplier','legal_entity','delivery_date','delivery_date_2','unit','note','details','cda_mode','safety_coef','today_date','safety_days','period_days','has_transit','show_stock_column'];
        $order = array_intersect_key($order, array_flip($orderWhitelist));
        $order['updated_at'] = date('Y-m-d H:i:s');
        // Белый список полей позиции
        $itemWhitelist = ['sku','name','qty_boxes','qty_per_box','boxes_per_pallet','multiplicity','consumption_period','stock','transit','final_order','manual_override','unit_of_measure','received_qty','analog_group','category','sort_order'];
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

    // ═══ VEG ORDER: приватные RPC ═══
    if ($fn === 'veg_create_session') {
        $name = mb_substr($body['name'] ?? '', 0, 255);
        $products = $body['products'] ?? []; // [{name, unit}]
        $dayConfig = $body['day_config'] ?? []; // [{date, restaurants: [nums]}]
        $uname = $authUserName ?: ($body['user_name'] ?? '');
        $dateFrom = $body['date_from'] ?? null;
        $dateTo = $body['date_to'] ?? null;
        $legalEntity = $body['legal_entity'] ?? null;
        $entityGroup = $legalEntity ? getEntityGroup($legalEntity) : 'BK_VM';
        if (!$name || empty($products)) respond(['error' => 'Не все параметры указаны'], 400);
        if (count($products) > 200) respond(['error' => 'Слишком много товаров (макс. 200)'], 400);
        // Валидация дат
        if ($dateFrom && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = null;
        if ($dateTo && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = null;
        $pdo->beginTransaction();
        try {
            $s = $pdo->prepare("INSERT INTO veg_sessions (name, date_from, date_to, legal_entity_group, created_by) VALUES (?, ?, ?, ?, ?)");
            $s->execute([$name, $dateFrom, $dateTo, $entityGroup, $uname]);
            $sessId = $pdo->lastInsertId();
            $ins = $pdo->prepare("INSERT INTO veg_session_products (session_id, product_name, unit, multiplicity, sort_order) VALUES (?, ?, ?, ?, ?)");
            foreach ($products as $i => $p) {
                $pname = mb_substr($p['name'] ?? '', 0, 255);
                $punit = in_array($p['unit'] ?? '', ['kg', 'pcs']) ? $p['unit'] : 'kg';
                $pmult = isset($p['multiplicity']) && $p['multiplicity'] !== '' && $p['multiplicity'] !== null ? floatval($p['multiplicity']) : null;
                $ins->execute([$sessId, $pname, $punit, $pmult, $i]);
            }
            // Per-session конфиг дней (какие рестораны на какие дни)
            if (!empty($dayConfig)) {
                $dcIns = $pdo->prepare("INSERT INTO veg_session_day_config (session_id, delivery_date, restaurant_number) VALUES (?, ?, ?)");
                foreach ($dayConfig as $dc) {
                    $dcDate = $dc['date'] ?? '';
                    $dcRests = $dc['restaurants'] ?? [];
                    if (!$dcDate || empty($dcRests)) continue;
                    foreach ($dcRests as $rn) {
                        $dcIns->execute([$sessId, $dcDate, $rn]);
                    }
                }
            }

            $pdo->commit();

            // Автоматически создать токен со сроком до конца периода
            $autoToken = bin2hex(random_bytes(32));
            $tokenExpires = $dateTo ? ($dateTo . ' 23:59:59') : date('Y-m-d H:i:s', strtotime('+14 days'));
            $pdo->prepare("INSERT INTO veg_tokens (session_id, token, created_by, expires_at) VALUES (?, ?, ?, ?)")
                ->execute([$sessId, $autoToken, $uname, $tokenExpires]);

            // Уведомление подписчикам в Telegram с кнопками
            try {
                $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
                $siteUrl = $_ENV['SITE_URL'] ?? 'https://supply-department.online';
                if ($botToken) {
                    $allSubs = $pdo->query("SELECT DISTINCT chat_id FROM veg_telegram_subs WHERE notify_veg_sessions = 1")->fetchAll(PDO::FETCH_COLUMN);
                    if ($allSubs) {
                        $dateRange = '';
                        if ($dateFrom && $dateTo) {
                            $df = date('d.m', strtotime($dateFrom));
                            $dt = date('d.m', strtotime($dateTo));
                            $dateRange = "\n📅 Период: <b>{$df} — {$dt}</b>";
                        }
                        $prodList = [];
                        foreach ($products as $p) {
                            $pname = $p['name'] ?? '';
                            if ($pname) $prodList[] = $pname;
                        }
                        $prodLine = $prodList ? "\n📦 Товары: " . implode(', ', $prodList) : '';
                        $webLink = "{$siteUrl}/veg-order/{$autoToken}";
                        $msgText = "📢 <b>Открыт сбор заявок на овощи</b>\n\n";
                        $msgText .= "🗂 Сессия: <b>" . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "</b>";
                        $msgText .= $dateRange;
                        $msgText .= $prodLine;
                        $msgText .= "\n\n👇 Подайте заявку:";

                        $keyboard = json_encode(['inline_keyboard' => [
                            [['text' => '📝 Заполнить на сайте', 'url' => $webLink]],
                            [['text' => '🤖 Заполнить в боте', 'callback_data' => 'veg_order_' . $sessId . '_' . $autoToken]],
                        ]]);

                        foreach ($allSubs as $cid) {
                            $payload = json_encode([
                                'chat_id' => $cid,
                                'text' => $msgText,
                                'parse_mode' => 'HTML',
                                'reply_markup' => json_decode($keyboard),
                            ]);
                            $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
                            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 5]);
                            curl_exec($ch); curl_close($ch);
                        }
                    }
                }
            } catch (Exception $e) {
                error_log('veg_create_session telegram notify error: ' . $e->getMessage());
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('veg_create_session error: ' . $e->getMessage());
            respond(['error' => 'Ошибка создания сессии'], 500);
        }
        auditLog($pdo, 'veg_session_created', 'veg', $sessId, $uname, ['name' => $name, 'products_count' => count($products)]);
        respond(['id' => $sessId, 'token' => $autoToken]);
    }
    if ($fn === 'veg_create_token') {
        $sessId = intval($body['session_id'] ?? 0);
        $uname = $authUserName ?: ($body['user_name'] ?? '');
        if (!$sessId) respond(['error' => 'Не все параметры указаны'], 400);
        $sc = $pdo->prepare("SELECT id FROM veg_sessions WHERE id = ?");
        $sc->execute([$sessId]);
        if (!$sc->fetch()) respond(['error' => 'Сессия не найдена'], 404);
        $token = bin2hex(random_bytes(32));
        $expiresDate = $body['expires_date'] ?? '';
        if ($expiresDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresDate)) {
            $expires = $expiresDate . ' 23:59:59';
        } else {
            $expires = date('Y-m-d H:i:s', strtotime('+7 days'));
        }
        $s = $pdo->prepare("INSERT INTO veg_tokens (session_id, token, created_by, expires_at) VALUES (?, ?, ?, ?)");
        $s->execute([$sessId, $token, $uname, $expires]);
        respond(['token' => $token, 'token_id' => $pdo->lastInsertId(), 'expires_at' => $expires]);
    }
    if ($fn === 'veg_close_session') {
        $sessId = intval($body['session_id'] ?? 0);
        if (!$sessId) respond(['error' => 'Не все параметры указаны'], 400);
        $pdo->prepare("UPDATE veg_sessions SET status = 'closed', closed_at = NOW() WHERE id = ?")->execute([$sessId]);
        respond(['success' => true]);
    }
    if ($fn === 'veg_reopen_session') {
        $sessId = intval($body['session_id'] ?? 0);
        if (!$sessId) respond(['error' => 'Не все параметры указаны'], 400);
        $pdo->prepare("UPDATE veg_sessions SET status = 'active', closed_at = NULL WHERE id = ?")->execute([$sessId]);
        respond(['success' => true]);
    }
    if ($fn === 'veg_get_session_data') {
        $sessId = intval($body['session_id'] ?? 0);
        if (!$sessId) respond(['error' => 'Не все параметры указаны'], 400);
        // Даты сессии
        $sessMeta = $pdo->prepare("SELECT date_from, date_to FROM veg_sessions WHERE id = ?");
        $sessMeta->execute([$sessId]);
        $sessRow = $sessMeta->fetch();
        $dateFrom = $sessRow['date_from'] ?? null;
        $dateTo = $sessRow['date_to'] ?? null;
        // Товары
        $s = $pdo->prepare("SELECT id, product_name, unit, multiplicity, sort_order FROM veg_session_products WHERE session_id = ? ORDER BY sort_order");
        $s->execute([$sessId]);
        $products = $s->fetchAll();
        // Заявки
        $s2 = $pdo->prepare("SELECT id, product_id, restaurant_number, delivery_date, quantity, admin_note, admin_qty, submitted_at, source FROM veg_orders WHERE session_id = ? ORDER BY restaurant_number, delivery_date");
        $s2->execute([$sessId]);
        $orders = $s2->fetchAll();
        // Пометки по ресторанам
        $s3 = $pdo->prepare("SELECT restaurant_number, note FROM veg_restaurant_notes WHERE session_id = ?");
        $s3->execute([$sessId]);
        $notes = $s3->fetchAll();
        // Рестораны (все активные, без дублей, по номеру)
        $s4 = $pdo->prepare("SELECT id, number, address, city, region, legal_entity_group FROM restaurants WHERE active = 1 AND legal_entity_group = 'BK_VM' ORDER BY number");
        $s4->execute();
        $restaurants = $s4->fetchAll();
        $existNums = array_flip(array_column($restaurants, 'number'));
        $s4b = $pdo->prepare("SELECT id, number, address, city, region, legal_entity_group FROM restaurants WHERE active = 1 AND legal_entity_group != 'BK_VM' ORDER BY number");
        $s4b->execute();
        foreach ($s4b->fetchAll() as $r) {
            if (!isset($existNums[$r['number']])) $restaurants[] = $r;
        }
        usort($restaurants, function($a, $b) { return intval($a['number']) - intval($b['number']); });
        // Токены
        $s5 = $pdo->prepare("SELECT token, created_by, expires_at, created_at FROM veg_tokens WHERE session_id = ? ORDER BY created_at DESC");
        $s5->execute([$sessId]);
        $tokens = $s5->fetchAll();
        // Расписание доставки
        $s6 = $pdo->query("SELECT restaurant_number, day_of_week FROM veg_delivery_days ORDER BY restaurant_number");
        $schedRaw = $s6->fetchAll();
        $schedMap = [];
        foreach ($schedRaw as $r) {
            $rn = $r['restaurant_number'];
            if (!isset($schedMap[$rn])) $schedMap[$rn] = [];
            $schedMap[$rn][] = intval($r['day_of_week']);
        }
        // Предыдущая сессия — заказы (для не ответивших)
        $prevSess = $pdo->prepare("SELECT id FROM veg_sessions WHERE id < ? ORDER BY id DESC LIMIT 1");
        $prevSess->execute([$sessId]);
        $prevS = $prevSess->fetch();
        $prevOrders = [];
        if ($prevS) {
            $sp = $pdo->prepare("SELECT o.restaurant_number, sp.product_name, sp.unit, o.delivery_date, o.quantity, o.admin_qty FROM veg_orders o JOIN veg_session_products sp ON sp.id = o.product_id WHERE o.session_id = ? ORDER BY o.restaurant_number, o.delivery_date");
            $sp->execute([$prevS['id']]);
            $prevOrders = $sp->fetchAll();
        }
        respond(['products' => $products, 'orders' => $orders, 'notes' => $notes, 'restaurants' => $restaurants, 'tokens' => $tokens, 'schedule' => $schedMap, 'prev_orders' => $prevOrders, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
    }
    if ($fn === 'veg_get_stats') {
        // Статистика по ресторанам: участие в сессиях, пропуски дедлайнов
        $limitSessions = intval($body['limit'] ?? 10);
        if ($limitSessions < 1) $limitSessions = 10;
        if ($limitSessions > 50) $limitSessions = 50;

        // Последние N сессий
        $sessStmt = $pdo->prepare("SELECT id, name, date_from, date_to, created_at FROM veg_sessions ORDER BY id DESC LIMIT " . $limitSessions);
        $sessStmt->execute();
        $recentSessions = $sessStmt->fetchAll();
        $sessIds = array_column($recentSessions, 'id');
        if (!$sessIds) respond(['sessions' => [], 'stats' => []]);

        $placeholders = implode(',', array_fill(0, count($sessIds), '?'));

        // Все рестораны с расписанием доставки (активные)
        $restStmt = $pdo->prepare("SELECT number, address, city, region FROM restaurants WHERE active = 1 AND legal_entity_group = 'BK_VM' ORDER BY number");
        $restStmt->execute();
        $allRests = $restStmt->fetchAll();

        // Расписание доставки
        $schedStmt = $pdo->query("SELECT restaurant_number, day_of_week FROM veg_delivery_days");
        $restWithSchedule = [];
        foreach ($schedStmt->fetchAll() as $r) {
            $restWithSchedule[$r['restaurant_number']] = true;
        }

        // Заказы по сессиям и ресторанам
        // self = ресторан подал сам (quantity > 0), admin = админ внёс (quantity = 0, admin_qty IS NOT NULL)
        $ordStmt = $pdo->prepare("SELECT session_id, restaurant_number, MAX(CASE WHEN quantity > 0 THEN 1 ELSE 0 END) as has_self FROM veg_orders WHERE session_id IN ({$placeholders}) GROUP BY session_id, restaurant_number");
        $ordStmt->execute($sessIds);
        $orderMap = []; // key => 'self' | 'admin'
        foreach ($ordStmt->fetchAll() as $r) {
            $key = $r['session_id'] . '_' . $r['restaurant_number'];
            $orderMap[$key] = $r['has_self'] ? 'self' : 'admin';
        }

        // Собираем статистику
        $stats = [];
        foreach ($allRests as $rest) {
            $num = $rest['number'];
            if (!isset($restWithSchedule[$num])) continue; // нет расписания — не участвует
            $participated = 0;
            $missed = 0;
            foreach ($sessIds as $sid) {
                $key = $sid . '_' . $num;
                if (isset($orderMap[$key]) && $orderMap[$key] === 'self') {
                    $participated++;
                } else {
                    $missed++; // пропуск = нет заявки ИЛИ внёс админ
                }
            }
            $total = count($sessIds);
            $stats[] = [
                'number' => $num,
                'address' => $rest['address'],
                'city' => $rest['city'],
                'region' => $rest['region'],
                'total' => $total,
                'participated' => $participated,
                'missed' => $missed,
                'rate' => $total > 0 ? round($participated / $total * 100) : 0,
            ];
        }

        respond(['sessions_count' => count($sessIds), 'stats' => $stats]);
    }
    if ($fn === 'veg_update_order') {
        $orderId = intval($body['order_id'] ?? 0);
        $sessId = intval($body['session_id'] ?? 0);
        $restNum = $body['restaurant_number'] ?? '';
        $prodId = intval($body['product_id'] ?? 0);
        $delDate = $body['delivery_date'] ?? '';
        $adminQty = isset($body['admin_qty']) ? (is_null($body['admin_qty']) ? null : floatval($body['admin_qty'])) : 'skip';
        $adminNote = $body['admin_note'] ?? 'skip';

        if ($orderId) {
            // Получаем данные заказа до изменения (для уведомления)
            $oldOrder = $pdo->prepare("SELECT vo.restaurant_number, vo.delivery_date, vo.quantity, vo.admin_qty,
                sp.product_name, sp.unit
                FROM veg_orders vo
                JOIN veg_session_products sp ON sp.id = vo.product_id AND sp.session_id = vo.session_id
                WHERE vo.id = ?");
            $oldOrder->execute([$orderId]);
            $oldData = $oldOrder->fetch();

            // Обновление существующей записи
            $sets = []; $params = [];
            if ($adminQty !== 'skip') { $sets[] = 'admin_qty = ?'; $params[] = $adminQty; }
            if ($adminNote !== 'skip') { $sets[] = 'admin_note = ?'; $params[] = ($adminNote === null || $adminNote === '') ? null : mb_substr($adminNote, 0, 500); }
            if (empty($sets)) respond(['error' => 'Нет данных'], 400);
            $params[] = $orderId;
            $pdo->prepare("UPDATE veg_orders SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

            auditLog($pdo, 'veg_order_updated', 'veg', $orderId, $authUserName, ['restaurant' => $oldData['restaurant_number'] ?? '', 'product' => $oldData['product_name'] ?? '', 'admin_qty' => $adminQty]);

            // Уведомление в Telegram при изменении количества
            if ($oldData && $adminQty !== 'skip') {
                $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
                if ($botToken && $oldData['restaurant_number']) {
                    $rn = $oldData['restaurant_number'];
                    $subs = $pdo->prepare("SELECT chat_id FROM veg_telegram_subs WHERE restaurant_number=?");
                    $subs->execute([$rn]);
                    $chatIds = $subs->fetchAll(PDO::FETCH_COLUMN);
                    if ($chatIds) {
                        $oldVal = $oldData['admin_qty'] !== null ? $oldData['admin_qty'] : $oldData['quantity'];
                        $newVal = $adminQty !== null ? $adminQty : $oldData['quantity'];
                        $dateFmt = (new DateTime($oldData['delivery_date']))->format('d.m');
                        $tgText = "📝 <b>Изменение заявки</b>\n\n";
                        $tgText .= "🏪 Ресторан <b>{$rn}</b>\n";
                        $tgText .= "📅 Доставка: {$dateFmt}\n";
                        $tgText .= "🥬 {$oldData['product_name']}: <b>{$oldVal}</b> → <b>{$newVal}</b> {$oldData['unit']}\n\n";
                        $tgText .= "<i>Изменено отделом закупок</i>";
                        foreach ($chatIds as $cid) {
                            $d = json_encode(['chat_id' => $cid, 'text' => $tgText, 'parse_mode' => 'HTML']);
                            $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
                            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $d, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 5]);
                            curl_exec($ch); curl_close($ch);
                        }
                    }
                }
            }

            respond(['success' => true]);
        } elseif ($sessId && $restNum && $prodId && $delDate) {
            // Создание новой записи (админ добавляет вручную)
            $qty = ($adminQty !== 'skip' && $adminQty !== null) ? $adminQty : 0;
            $s = $pdo->prepare("INSERT INTO veg_orders (session_id, product_id, restaurant_number, delivery_date, quantity, admin_qty, submitted_at)
                VALUES (?, ?, ?, ?, 0, ?, NOW())
                ON DUPLICATE KEY UPDATE admin_qty = VALUES(admin_qty)");
            $s->execute([$sessId, $prodId, $restNum, $delDate, $qty]);

            // Уведомление в Telegram о добавлении позиции
            $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
            if ($botToken && $restNum) {
                $subs = $pdo->prepare("SELECT chat_id FROM veg_telegram_subs WHERE restaurant_number=?");
                $subs->execute([$restNum]);
                $chatIds = $subs->fetchAll(PDO::FETCH_COLUMN);
                if ($chatIds) {
                    $prodName = $pdo->prepare("SELECT product_name FROM veg_session_products WHERE id=? AND session_id=?");
                    $prodName->execute([$prodId, $sessId]);
                    $pn = $prodName->fetchColumn() ?: 'Товар';
                    $dateFmt = (new DateTime($delDate))->format('d.m');
                    $tgText = "📝 <b>Добавлена позиция в заявку</b>\n\n";
                    $tgText .= "🏪 Ресторан <b>{$restNum}</b>\n";
                    $tgText .= "📅 Доставка: {$dateFmt}\n";
                    $tgText .= "🥬 {$pn}: <b>{$qty}</b>\n\n";
                    $tgText .= "<i>Добавлено отделом закупок</i>";
                    foreach ($chatIds as $cid) {
                        $d = json_encode(['chat_id' => $cid, 'text' => $tgText, 'parse_mode' => 'HTML']);
                        $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
                        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $d, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 5]);
                        curl_exec($ch); curl_close($ch);
                    }
                }
            }

            respond(['success' => true, 'id' => $pdo->lastInsertId()]);
        } else {
            respond(['error' => 'Не все параметры указаны'], 400);
        }
    }
    if ($fn === 'veg_delete_restaurant_orders') {
        $sessId = intval($body['session_id'] ?? 0);
        $restNum = $body['restaurant_number'] ?? '';
        $delDate = $body['delivery_date'] ?? null;
        if (!$sessId || !$restNum) respond(['error' => 'Не все параметры указаны'], 400);
        if ($delDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $delDate)) {
            // Удалить заявку на конкретную дату
            $s = $pdo->prepare("DELETE FROM veg_orders WHERE session_id = ? AND restaurant_number = ? AND delivery_date = ?");
            $s->execute([$sessId, $restNum, $delDate]);
        } else {
            // Удалить все заявки ресторана
            $s = $pdo->prepare("DELETE FROM veg_orders WHERE session_id = ? AND restaurant_number = ?");
            $s->execute([$sessId, $restNum]);
            $s2 = $pdo->prepare("DELETE FROM veg_restaurant_notes WHERE session_id = ? AND restaurant_number = ?");
            $s2->execute([$sessId, $restNum]);
        }
        respond(['success' => true, 'deleted' => $s->rowCount()]);
    }
    if ($fn === 'veg_save_note') {
        $sessId = intval($body['session_id'] ?? 0);
        $restNum = $body['restaurant_number'] ?? '';
        $note = mb_substr($body['note'] ?? '', 0, 500);
        if (!$sessId || !$restNum) respond(['error' => 'Не все параметры указаны'], 400);
        $pdo->prepare("INSERT INTO veg_restaurant_notes (session_id, restaurant_number, note) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE note = VALUES(note)")
            ->execute([$sessId, $restNum, $note ?: null]);
        respond(['success' => true]);
    }
    if ($fn === 'veg_save_schedule') {
        $schedule = $body['schedule'] ?? []; // [{restaurant_number, days: [1,3,5]}]
        if (empty($schedule)) respond(['error' => 'Пустые данные'], 400);
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare("DELETE FROM veg_delivery_days WHERE restaurant_number = ?");
            $ins = $pdo->prepare("INSERT INTO veg_delivery_days (restaurant_number, day_of_week) VALUES (?, ?)");
            foreach ($schedule as $item) {
                $rn = $item['restaurant_number'] ?? '';
                $days = $item['days'] ?? [];
                if (!$rn || !preg_match('/^\d{1,5}$/', $rn)) continue;
                $del->execute([$rn]);
                foreach ($days as $d) {
                    $d = intval($d);
                    if ($d >= 1 && $d <= 7) {
                        $ins->execute([$rn, $d]);
                    }
                }
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('veg_save_schedule error: ' . $e->getMessage());
            respond(['error' => 'Ошибка сохранения'], 500);
        }
        respond(['success' => true]);
    }
    if ($fn === 'veg_get_deadlines') {
        $rows = $pdo->query("SELECT delivery_dow, deadline_dow, deadline_time FROM veg_deadline_rules ORDER BY delivery_dow")->fetchAll();
        respond($rows);
    }
    if ($fn === 'veg_save_deadlines') {
        $rules = $body['rules'] ?? []; // [{delivery_dow, deadline_dow, deadline_time}]
        if (empty($rules)) respond(['error' => 'Пустые данные'], 400);
        $pdo->beginTransaction();
        try {
            $pdo->exec("DELETE FROM veg_deadline_rules");
            $ins = $pdo->prepare("INSERT INTO veg_deadline_rules (delivery_dow, deadline_dow, deadline_time) VALUES (?, ?, ?)");
            foreach ($rules as $r) {
                $delDow = intval($r['delivery_dow'] ?? 0);
                $dlDow = intval($r['deadline_dow'] ?? 0);
                $dlTime = $r['deadline_time'] ?? '12:00';
                if ($delDow < 1 || $delDow > 7 || $dlDow < 1 || $dlDow > 7) continue;
                if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $dlTime)) $dlTime = '12:00:00';
                if (strlen($dlTime) === 5) $dlTime .= ':00';
                $ins->execute([$delDow, $dlDow, $dlTime]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(['error' => 'Ошибка сохранения'], 500);
        }
        respond(['success' => true]);
    }
    if ($fn === 'veg_get_summary_subscribers') {
        $s = $pdo->query("
            SELECT u.name, u.telegram_chat_id, COALESCE(ts.veg_deadline_summary, 0) AS subscribed
            FROM users u
            LEFT JOIN telegram_settings ts ON ts.user_name = u.name
            WHERE u.telegram_chat_id IS NOT NULL AND u.telegram_chat_id > 0
            ORDER BY u.name
        ");
        $rows = $s->fetchAll();
        foreach ($rows as &$r) { $r['subscribed'] = (int)$r['subscribed']; }
        respond($rows);
    }
    if ($fn === 'veg_set_summary_subscriber') {
        $name = trim($body['name'] ?? '');
        $on = !empty($body['subscribed']) ? 1 : 0;
        if ($name === '') respond(['error' => 'Не указано имя'], 400);
        try {
            $upd = $pdo->prepare("UPDATE telegram_settings SET veg_deadline_summary = ? WHERE user_name = ?");
            $upd->execute([$on, $name]);
            if ($upd->rowCount() === 0) {
                $ins = $pdo->prepare("INSERT INTO telegram_settings (user_name, veg_deadline_summary) VALUES (?, ?)");
                $ins->execute([$name, $on]);
            }
            respond(['success' => true]);
        } catch (Exception $e) {
            respond(['error' => 'Ошибка сохранения'], 500);
        }
    }
    if ($fn === 'veg_get_schedule_all') {
        $s = $pdo->prepare("SELECT restaurant_number, day_of_week FROM veg_delivery_days ORDER BY restaurant_number, day_of_week");
        $s->execute();
        $rows = $s->fetchAll();
        // Группируем по ресторану
        $result = [];
        foreach ($rows as $r) {
            $rn = $r['restaurant_number'];
            if (!isset($result[$rn])) $result[$rn] = [];
            $result[$rn][] = intval($r['day_of_week']);
        }
        respond($result);
    }

    // ═══ Распределение новинок (dist_*) ═══

    if ($fn === 'dist_get_sessions') {
        $legalEntity = $_GET['legal_entity'] ?? $body['legal_entity'] ?? null;
        $group = $legalEntity ? getEntityGroup($legalEntity) : 'BK_VM';
        $s = $pdo->prepare("SELECT * FROM dist_sessions WHERE legal_entity_group = ? ORDER BY created_at DESC");
        $s->execute([$group]);
        respond($s->fetchAll());
    }

    if ($fn === 'dist_create_session') {
        $name = trim($body['name'] ?? '');
        $products = $body['products'] ?? [];
        $legalEntity = $body['legal_entity'] ?? null;
        if (!$name) respond(['error' => 'Название обязательно'], 400);
        if (empty($products)) respond(['error' => 'Добавьте хотя бы один товар'], 400);
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
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
        $id = intval($body['session_id'] ?? 0);
        if (!$id) respond(['error' => 'session_id обязателен'], 400);
        // CASCADE удалит session_products и entries
        $pdo->prepare("DELETE FROM dist_sessions WHERE id=?")->execute([$id]);
        respond(['success' => true]);
    }

    if ($fn === 'dist_close_session') {
        $id = intval($body['session_id'] ?? 0);
        if (!$id) respond(['error' => 'session_id обязателен'], 400);
        $pdo->prepare("UPDATE dist_sessions SET status='closed', closed_at=NOW() WHERE id=?")->execute([$id]);
        respond(['success' => true]);
    }

    if ($fn === 'dist_reopen_session') {
        $id = intval($body['session_id'] ?? 0);
        if (!$id) respond(['error' => 'session_id обязателен'], 400);
        $pdo->prepare("UPDATE dist_sessions SET status='active', closed_at=NULL WHERE id=?")->execute([$id]);
        respond(['success' => true]);
    }

    if ($fn === 'dist_get_session_data') {
        $id = intval($body['session_id'] ?? 0);
        if (!$id) respond(['error' => 'session_id обязателен'], 400);
        // Сессия
        $s = $pdo->prepare("SELECT * FROM dist_sessions WHERE id=?");
        $s->execute([$id]);
        $session = $s->fetch();
        if (!$session) respond(['error' => 'Сессия не найдена'], 404);
        $sessionGroup = $session['legal_entity_group'] ?: 'BK_VM';
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
        $spId = intval($body['session_product_id'] ?? 0);
        $restNum = $body['restaurant_number'] ?? '';
        $shipped = isset($body['shipped']) ? (int)$body['shipped'] : 1;
        if (!$spId || !$restNum) respond(['error' => 'Не указан товар или ресторан'], 400);
        // Upsert
        $s = $pdo->prepare("INSERT INTO dist_entries (session_product_id, restaurant_number, shipped)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE shipped = VALUES(shipped), updated_at = NOW()");
        $s->execute([$spId, $restNum, $shipped]);
        respond(['success' => true]);
    }

    if ($fn === 'dist_update_qty') {
        $spId = intval($body['session_product_id'] ?? 0);
        $restNum = $body['restaurant_number'] ?? '';
        $qty = $body['qty'] ?? null;
        if (!$spId || !$restNum) respond(['error' => 'Не указан товар или ресторан'], 400);
        // Upsert
        $s = $pdo->prepare("INSERT INTO dist_entries (session_product_id, restaurant_number, qty)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE qty = VALUES(qty), updated_at = NOW()");
        $s->execute([$spId, $restNum, $qty]);
        respond(['success' => true]);
    }

    if ($fn === 'dist_add_products') {
        $sessionId = intval($body['session_id'] ?? 0);
        $products = $body['products'] ?? [];
        if (!$sessionId || empty($products)) respond(['error' => 'Нет данных'], 400);
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
        $spId = intval($body['session_product_id'] ?? 0);
        if (!$spId) respond(['error' => 'Не указан товар'], 400);
        $pdo->prepare("DELETE FROM dist_session_products WHERE id=?")->execute([$spId]);
        respond(['success' => true]);
    }

    if ($fn === 'dist_save_note') {
        $sessionId = intval($body['session_id'] ?? 0);
        $restNum = $body['restaurant_number'] ?? '';
        $note = trim($body['note'] ?? '');
        if (!$sessionId || !$restNum) respond(['error' => 'Нет данных'], 400);
        $s = $pdo->prepare("INSERT INTO dist_notes (session_id, restaurant_number, note)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE note = VALUES(note)");
        $s->execute([$sessionId, $restNum, $note]);
        respond(['success' => true]);
    }

    if ($fn === 'dist_bulk_toggle') {
        $spId = intval($body['session_product_id'] ?? 0);
        $restaurantNumbers = $body['restaurant_numbers'] ?? [];
        $shipped = isset($body['shipped']) ? (int)$body['shipped'] : 1;
        if (!$spId || empty($restaurantNumbers)) respond(['error' => 'Нет данных'], 400);
        $ins = $pdo->prepare("INSERT INTO dist_entries (session_product_id, restaurant_number, shipped)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE shipped = VALUES(shipped), updated_at = NOW()");
        foreach ($restaurantNumbers as $rn) {
            $ins->execute([$spId, $rn, $shipped]);
        }
        respond(['success' => true]);
    }

    // ═══ Telegram Bot Admin ═══

    if ($fn === 'tg_admin_bot_info') {
        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if (!$botToken) respond(['error' => 'Нет TELEGRAM_BOT_TOKEN'], 500);

        // getMe
        $me = json_decode(@file_get_contents("https://api.telegram.org/bot{$botToken}/getMe"), true);
        // getWebhookInfo
        $wh = json_decode(@file_get_contents("https://api.telegram.org/bot{$botToken}/getWebhookInfo"), true);

        respond([
            'bot' => $me['result'] ?? null,
            'webhook' => $wh['result'] ?? null,
        ]);
    }

    if ($fn === 'tg_admin_set_webhook') {
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
        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if (!$botToken) respond(['error' => 'Нет TELEGRAM_BOT_TOKEN'], 500);
        $res = json_decode(@file_get_contents("https://api.telegram.org/bot{$botToken}/deleteWebhook"), true);
        respond($res ?? ['error' => 'Нет ответа от Telegram']);
    }

    if ($fn === 'tg_admin_recent_questions') {
        $rows = $pdo->query("SELECT user_name, question AS last_question, answer, asked_at AS last_question_at, legal_entity AS last_entity
            FROM tg_question_log
            ORDER BY asked_at DESC LIMIT 50")->fetchAll();
        respond(['questions' => $rows]);
    }

    if ($fn === 'tg_admin_stats') {
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

        // Подписки ресторанов на овощи (с настройками уведомлений)
        $vegSubs = $pdo->query("SELECT vs.chat_id, vs.restaurant_number, vs.created_at,
            vs.first_name, vs.username,
            vs.notify_veg_reminders, vs.notify_veg_sessions, vs.notify_confirmations,
            vs.notify_stock_reminders, vs.notify_stock_sessions,
            r.address, r.city, r.region
            FROM veg_telegram_subs vs
            LEFT JOIN restaurants r ON r.number = vs.restaurant_number AND r.legal_entity_group = 'BK_VM'
            ORDER BY CAST(vs.restaurant_number AS UNSIGNED)")->fetchAll();

        // Все рестораны для сравнения
        $allRests = $pdo->query("SELECT number, address, city, region FROM restaurants WHERE active=1 AND legal_entity_group='BK_VM' ORDER BY CAST(number AS UNSIGNED)")->fetchAll();

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
            'veg_subs' => $vegSubs,
            'all_restaurants' => $allRests,
            'reminder_log' => $reminders,
            'correction_stats' => $corrStats ?: ['pending' => 0, 'in_progress' => 0, 'approved' => 0, 'rejected' => 0],
        ]);
    }

    if ($fn === 'tg_admin_send_message') {
        $chatIds = $body['chat_ids'] ?? [];
        $message = trim($body['message'] ?? '');
        if (!$message || empty($chatIds)) respond(['error' => 'Нужен текст и получатели'], 400);

        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if (!$botToken) respond(['error' => 'Нет TELEGRAM_BOT_TOKEN'], 500);

        $sent = 0;
        foreach ($chatIds as $cid) {
            $data = json_encode(['chat_id' => $cid, 'text' => $message, 'parse_mode' => 'HTML']);
            $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $data, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 5]);
            $res = curl_exec($ch); curl_close($ch);
            $r = json_decode($res, true);
            if ($r && ($r['ok'] ?? false)) $sent++;
        }
        // Логируем рассылку
        try {
            $sender = $body['sender'] ?? 'admin';
            $pdo->prepare("INSERT INTO tg_broadcast_log (sender, message, recipient_count) VALUES (?, ?, ?)")
                ->execute([$sender, mb_substr($message, 0, 1000), $sent]);
        } catch (Exception $e) { /* таблица может не существовать */ }
        respond(['success' => true, 'sent' => $sent, 'total' => count($chatIds)]);
    }

    if ($fn === 'tg_admin_broadcast_history') {
        $rows = $pdo->query("SELECT id, sender, message, recipient_count, sent_at FROM tg_broadcast_log ORDER BY sent_at DESC LIMIT 50")->fetchAll();
        respond(['broadcasts' => $rows]);
    }

    if ($fn === 'tg_admin_send_veg_reminder') {
        $restNumber = $body['restaurant_number'] ?? '';
        $message = trim($body['message'] ?? '');
        if (!$restNumber || !$message) respond(['error' => 'Укажите ресторан и текст'], 400);

        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if (!$botToken) respond(['error' => 'Нет TELEGRAM_BOT_TOKEN'], 500);

        // Найти всех подписчиков этого ресторана
        $subs = $pdo->prepare("SELECT DISTINCT chat_id FROM veg_telegram_subs WHERE restaurant_number = ?");
        $subs->execute([$restNumber]);
        $chatIds = $subs->fetchAll(PDO::FETCH_COLUMN);

        if (empty($chatIds)) respond(['error' => 'Нет подписчиков у этого ресторана'], 400);

        $sent = 0;
        foreach ($chatIds as $cid) {
            $data = json_encode(['chat_id' => $cid, 'text' => $message, 'parse_mode' => 'HTML']);
            $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $data, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 5]);
            $res = curl_exec($ch); curl_close($ch);
            $r = json_decode($res, true);
            if ($r && ($r['ok'] ?? false)) $sent++;
        }
        respond(['success' => true, 'sent' => $sent, 'total' => count($chatIds)]);
    }

    if ($fn === 'tg_admin_toggle_setting') {
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
        $chatId = $body['chat_id'] ?? '';
        $field = $body['field'] ?? '';
        $allowed = ['notify_veg_reminders', 'notify_veg_sessions', 'notify_confirmations', 'notify_stock_reminders', 'notify_stock_sessions'];
        if (!$chatId || !in_array($field, $allowed)) respond(['error' => 'Неверные параметры'], 400);
        $pdo->prepare("UPDATE veg_telegram_subs SET `$field` = NOT `$field` WHERE chat_id = ?")->execute([$chatId]);
        $newVal = $pdo->prepare("SELECT `$field` FROM veg_telegram_subs WHERE chat_id = ? LIMIT 1");
        $newVal->execute([$chatId]);
        $val = $newVal->fetchColumn();
        respond(['success' => true, 'value' => (bool)$val]);
    }

    if ($fn === 'tg_admin_unlink_user') {
        $userName = $body['user_name'] ?? '';
        if (!$userName) respond(['error' => 'Не указан пользователь'], 400);
        $pdo->prepare("UPDATE users SET telegram_chat_id = NULL WHERE name = ?")->execute([$userName]);
        respond(['success' => true]);
    }

    // ═══ Корректировки заказов ═══

    if ($fn === 'correction_review') {
        $id = intval($body['id'] ?? 0);
        $action = $body['action'] ?? '';
        $comment = trim($body['comment'] ?? '');
        if (!$id || !in_array($action, ['approve', 'reject'])) respond(['error' => 'Неверные параметры'], 400);

        $caller = getSessionUser($pdo);
        $callerName = $caller['name'] ?? 'unknown';

        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        $callerChatId = $caller['telegram_chat_id'] ?? null;
        $upd = $pdo->prepare("UPDATE order_corrections SET status = ?, reviewer_chat_id = ?, reviewer_name = ?, review_comment = ?, reviewed_at = NOW() WHERE id = ? AND status IN ('pending', 'in_progress')");
        $upd->execute([$newStatus, $callerChatId, $callerName, $comment ?: null, $id]);
        if ($upd->rowCount() === 0) respond(['error' => 'Уже обработано']);

        $corr = $pdo->prepare("SELECT * FROM order_corrections WHERE id = ?");
        $corr->execute([$id]);
        $c = $corr->fetch();
        if (!$c) respond(['error' => 'Не найдено'], 404);

        // Определяем батч — все позиции этого ресторана на эту дату от того же отправителя
        $batchSt = $pdo->prepare("SELECT * FROM order_corrections WHERE restaurant_number = ? AND delivery_date = ? AND restaurant_chat_id = ? ORDER BY id");
        $batchSt->execute([$c['restaurant_number'], $c['delivery_date'], $c['restaurant_chat_id']]);
        $batchItems = $batchSt->fetchAll();

        // Проверяем остались ли необработанные (pending или in_progress)
        $hasPending = false;
        foreach ($batchItems as $bi) { if ($bi['status'] === 'pending' || $bi['status'] === 'in_progress') { $hasPending = true; break; } }

        // Если все обработаны — отправляем сводку ресторану
        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if (!$hasPending && $botToken && $c['restaurant_chat_id']) {
            $dayNames = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'];
            $dow = (int)(new DateTime($c['delivery_date']))->format('N');
            $dateFmt = $dayNames[$dow] . ' ' . date('d.m', strtotime($c['delivery_date']));

            $text = "📋 <b>Результат корректировки заказа</b>\n";
            $text .= "🏪 Ресторан <b>{$c['restaurant_number']}</b> | Доставка: {$dateFmt}\n";
            $text .= "─────────────────────\n";
            foreach ($batchItems as $bi) {
                $uom = $bi['unit_of_measure'] ?: 'кор.';
                $qty = rtrim(rtrim(number_format(floatval($bi['quantity']), 2, '.', ''), '0'), '.') . " {$uom}";
                if ($bi['status'] === 'approved') {
                    $label = $bi['action'] === 'add' ? 'Добавлено' : 'Убрано';
                    $text .= "✅ <b>{$label}:</b> {$bi['product_name']} — {$qty}\n";
                } else {
                    $label = $bi['action'] === 'add' ? 'Добавить' : 'Убрать';
                    $text .= "❌ <b>Отклонено</b> ({$label}): {$bi['product_name']} — {$qty}\n";
                    if ($bi['review_comment']) $text .= "    Причина: {$bi['review_comment']}\n";
                }
            }
            $text .= "─────────────────────\n";
            $text .= "Обработал: {$callerName}";

            $payload = json_encode(['chat_id' => $c['restaurant_chat_id'], 'text' => $text, 'parse_mode' => 'HTML']);
            $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 5]);
            curl_exec($ch); curl_close($ch);

            // Обновляем сообщения закупщиков — перестраиваем с актуальными статусами
            $nm = json_decode($c['notify_messages'] ?? '{}', true);
            $messages = $nm['messages'] ?? [];
            $batchIdsNm = $nm['batch_ids'] ?? array_column($batchItems, 'id');
            if ($messages && $batchIdsNm) {
                // Перестраиваем текст и кнопки
                require_once __DIR__ . '/bot_veg.php';
                $msgData = corrBuildReviewMessage($pdo, $batchIdsNm);
                foreach ($messages as $m) {
                    $epayload = json_encode(['chat_id' => $m['chat_id'], 'message_id' => $m['message_id'], 'text' => $msgData['text'], 'parse_mode' => 'HTML', 'reply_markup' => json_encode($msgData['keyboard'])]);
                    $ch2 = curl_init("https://api.telegram.org/bot{$botToken}/editMessageText");
                    curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $epayload, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 5]);
                    curl_exec($ch2); curl_close($ch2);
                }
            }
        }

        auditLog($pdo, 'correction_reviewed', 'correction', $id, $callerName, ['action' => $action, 'restaurant' => $c['restaurant_number'], 'product' => $c['product_name']]);
        respond(['success' => true]);
    }

    if ($fn === 'correction_review_batch') {
        $ids = $body['ids'] ?? [];
        $action = $body['action'] ?? '';
        $comment = trim($body['comment'] ?? '');
        if (empty($ids) || !is_array($ids) || !in_array($action, ['approve', 'reject'])) respond(['error' => 'Неверные параметры'], 400);

        $caller = getSessionUser($pdo);
        $callerName = $caller['name'] ?? 'unknown';
        $callerChatId = $caller['telegram_chat_id'] ?? null;
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';

        $pdo->beginTransaction();
        try {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $upd = $pdo->prepare("UPDATE order_corrections SET status = ?, reviewer_chat_id = ?, reviewer_name = ?, review_comment = ?, reviewed_at = NOW() WHERE id IN ({$ph}) AND status IN ('pending', 'in_progress')");
            $upd->execute(array_merge([$newStatus, $callerChatId, $callerName, $comment ?: null], array_map('intval', $ids)));
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(['error' => 'Ошибка обновления'], 500);
        }

        // Берём первую корректировку для определения батча и отправки уведомления
        $first = $pdo->prepare("SELECT * FROM order_corrections WHERE id = ?");
        $first->execute([intval($ids[0])]);
        $c = $first->fetch();
        if ($c) {
            // Проверяем батч
            $batchSt = $pdo->prepare("SELECT * FROM order_corrections WHERE restaurant_number = ? AND delivery_date = ? AND restaurant_chat_id = ? ORDER BY id");
            $batchSt->execute([$c['restaurant_number'], $c['delivery_date'], $c['restaurant_chat_id']]);
            $batchItems = $batchSt->fetchAll();
            $hasPending = false;
            foreach ($batchItems as $bi) { if ($bi['status'] === 'pending' || $bi['status'] === 'in_progress') { $hasPending = true; break; } }

            $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
            if (!$hasPending && $botToken && $c['restaurant_chat_id']) {
                $dayNames = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'];
                $dow = (int)(new DateTime($c['delivery_date']))->format('N');
                $dateFmt = $dayNames[$dow] . ' ' . date('d.m', strtotime($c['delivery_date']));
                $text = "📋 <b>Результат корректировки заказа</b>\n";
                $text .= "🏪 Ресторан <b>{$c['restaurant_number']}</b> | Доставка: {$dateFmt}\n";
                $text .= "─────────────────────\n";
                foreach ($batchItems as $bi) {
                    $uom = $bi['unit_of_measure'] ?: 'кор.';
                    $qty = rtrim(rtrim(number_format(floatval($bi['quantity']), 2, '.', ''), '0'), '.') . " {$uom}";
                    if ($bi['status'] === 'approved') {
                        $label = $bi['action'] === 'add' ? 'Добавлено' : 'Убрано';
                        $text .= "✅ <b>{$label}:</b> {$bi['product_name']} — {$qty}\n";
                    } else {
                        $label = $bi['action'] === 'add' ? 'Добавить' : 'Убрать';
                        $text .= "❌ <b>Отклонено</b> ({$label}): {$bi['product_name']} — {$qty}\n";
                        if ($bi['review_comment']) $text .= "    Причина: {$bi['review_comment']}\n";
                    }
                }
                $text .= "─────────────────────\n";
                $text .= "Обработал: {$callerName}";
                $payload = json_encode(['chat_id' => $c['restaurant_chat_id'], 'text' => $text, 'parse_mode' => 'HTML']);
                $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 5]);
                curl_exec($ch); curl_close($ch);
            }
        }

        auditLog($pdo, 'correction_reviewed', 'correction', implode(',', $ids), $callerName, ['action' => $action, 'count' => count($ids)]);
        respond(['success' => true, 'updated' => count($ids)]);
    }

    if ($fn === 'correction_delete') {
        $perms = resolvePermissions($authUser['role'] ?? 'user', $authUser['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($perms['corrections'] ?? 'none') === 'view' || ($perms['corrections'] ?? 'none') === 'none') respond(['error' => 'Нет прав'], 403);
        $ids = $body['ids'] ?? [];
        if (empty($ids)) respond(['error' => 'Нет ID'], 400);
        $ids = array_map('intval', $ids);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM order_corrections WHERE id IN ({$ph})")->execute($ids);
        respond(['success' => true]);
    }

    if ($fn === 'correction_clear_all') {
        if (($authUser['role'] ?? '') !== 'admin') respond(['error' => 'Только для администратора'], 403);
        $pdo->exec("DELETE FROM order_corrections");
        auditLog($pdo, 'corrections_cleared', 'correction', null, $authUserName, ['scope' => 'all']);
        respond(['success' => true]);
    }

    if ($fn === 'correction_clear_processed') {
        if (($authUser['role'] ?? '') !== 'admin') respond(['error' => 'Только для администратора'], 403);
        $cnt = $pdo->exec("DELETE FROM order_corrections WHERE status IN ('approved', 'rejected')");
        auditLog($pdo, 'corrections_cleared', 'correction', null, $authUserName, ['scope' => 'processed', 'count' => $cnt]);
        respond(['success' => true, 'deleted' => $cnt]);
    }

    if ($fn === 'correction_get_settings') {
        $st = $pdo->query("SELECT u.name, ts.correction_notifications FROM users u JOIN telegram_settings ts ON ts.user_name = u.name WHERE u.telegram_chat_id IS NOT NULL ORDER BY u.name");
        respond($st->fetchAll());
    }

    if ($fn === 'correction_toggle_notification') {
        $userName = $body['user_name'] ?? '';
        if (!$userName) respond(['error' => 'user_name required'], 400);
        $pdo->prepare("UPDATE telegram_settings SET correction_notifications = NOT correction_notifications WHERE user_name = ?")->execute([$userName]);
        respond(['success' => true]);
    }

    // ═══ Оплаты поставщиков ═══

    if ($fn === 'create_payment_if_needed') {
        $orderId = $body['order_id'] ?? '';
        $ttnDate = trim((string)($body['ttn_date'] ?? $body['delivery_date'] ?? ''));
        if (!$orderId) respond(['error' => 'order_id required'], 400);

        // Получаем заказ и поставщика
        $order = $pdo->prepare("SELECT o.id, o.supplier, o.legal_entity, o.created_by, o.ttn_date,
            (SELECT SUM(oi.qty_boxes * COALESCE(pp.price, 0)) FROM order_items oi LEFT JOIN product_prices pp ON pp.sku = oi.sku AND pp.legal_entity = o.legal_entity AND pp.price_type = 'purchase' WHERE oi.order_id = o.id) as total_amount
            FROM orders o WHERE o.id = ?");
        $order->execute([$orderId]);
        $o = $order->fetch();
        if (!$o) respond(['skip' => true]); // заказ не найден

        if (!$ttnDate) {
            $ttnDate = trim((string)($o['ttn_date'] ?? ''));
        }
        if (!$ttnDate) respond(['skip' => true, 'reason' => 'ttn_date_required']);

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
        $dDate = new DateTime($ttnDate);

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
            $orderId, $o['supplier'], $o['legal_entity'], $ttnDate,
            $delayDays, $dueDate->format('Y-m-d'), $payDate->format('Y-m-d'),
            $deadline->format('Y-m-d H:i:s'),
            $o['total_amount'] ?: null,
            $o['created_by'],
        ]);

        respond(['success' => true, 'payment_id' => $pdo->lastInsertId(), 'payment_date' => $payDate->format('Y-m-d')]);
    }

    if ($fn === 'update_payment') {
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'id required'], 400);
        $allowed = ['amount', 'status', 'note', 'payment_date', 'delivery_date', 'request_deadline'];
        $sets = []; $params = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) {
                $sets[] = "`{$f}` = ?";
                $params[] = $body[$f];
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
        $period = $body['period'] ?? 'week';
        $le = $body['legal_entity'] ?? null;
        $days = ['week' => 7, 'month' => 30, 'quarter' => 90][$period] ?? 7;
        $from = date('Y-m-d', strtotime("-{$days} days"));
        $prevFrom = date('Y-m-d', strtotime("-" . ($days * 2) . " days"));
        $leWhere = $le ? " AND o.legal_entity = " . $pdo->quote($le) : '';
        $leWhereA = $le ? " AND legal_entity = " . $pdo->quote($le) : '';

        $curOrders = $pdo->prepare("SELECT COUNT(*) FROM orders o WHERE created_at_new >= ?" . ($le ? " AND legal_entity = ?" : ''));
        $curOrders->execute($le ? [$from, $le] : [$from]);
        $ordersCount = intval($curOrders->fetchColumn());

        // Заказы прошлый период
        $prevOrders = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE created_at_new >= ? AND created_at_new < ?");
        $prevOrders->execute([$prevFrom, $from]);
        $prevCount = intval($prevOrders->fetchColumn());
        $ordersDelta = $prevCount > 0 ? round(($ordersCount - $prevCount) / $prevCount * 100) : 0;

        // Сумма (из order_items * product_prices)
        $amtSt = $pdo->prepare("SELECT COALESCE(SUM(oi.qty_boxes * COALESCE(pp.price, 0)), 0) as total
            FROM orders o JOIN order_items oi ON oi.order_id = o.id
            LEFT JOIN product_prices pp ON pp.sku = oi.sku AND pp.legal_entity = o.legal_entity AND pp.price_type = 'purchase'
            WHERE o.created_at_new >= ?");
        $amtSt->execute([$from]);
        $totalAmount = floatval($amtSt->fetchColumn());

        $prevAmtSt = $pdo->prepare("SELECT COALESCE(SUM(oi.qty_boxes * COALESCE(pp.price, 0)), 0)
            FROM orders o JOIN order_items oi ON oi.order_id = o.id
            LEFT JOIN product_prices pp ON pp.sku = oi.sku AND pp.legal_entity = o.legal_entity AND pp.price_type = 'purchase'
            WHERE o.created_at_new >= ? AND o.created_at_new < ?");
        $prevAmtSt->execute([$prevFrom, $from]);
        $prevAmount = floatval($prevAmtSt->fetchColumn());
        $amountDelta = $prevAmount > 0 ? round(($totalAmount - $prevAmount) / $prevAmount * 100) : 0;

        // Выполнение поставок
        $totalDel = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE delivery_date >= ? AND delivery_date <= CURDATE()");
        $totalDel->execute([$from]);
        $totalDeliveries = intval($totalDel->fetchColumn());
        $receivedDel = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE delivery_date >= ? AND delivery_date <= CURDATE() AND received_at IS NOT NULL");
        $receivedDel->execute([$from]);
        $received = intval($receivedDel->fetchColumn());
        $deliveredPct = $totalDeliveries > 0 ? round($received / $totalDeliveries * 100) : 100;

        // Просроченные
        $overdue = $pdo->query("SELECT COUNT(*) FROM orders WHERE delivery_date < CURDATE() AND received_at IS NULL AND delivery_date >= '{$from}'")->fetchColumn();

        // Низкий запас
        $lowStock = $pdo->query("SELECT COUNT(*) FROM analysis_data WHERE consumption > 0 AND stock > 0 AND stock / (consumption / GREATEST(period_days, 1)) <= 3")->fetchColumn();

        // Корректировки
        $corrPending = $pdo->query("SELECT COUNT(*) FROM order_corrections WHERE status = 'pending'")->fetchColumn();

        // Чат непрочитанные
        $chatUnread = $pdo->query("SELECT COUNT(*) FROM chat_messages cm JOIN chat_conversations cc ON cc.id = cm.conversation_id WHERE cm.is_read = 0 AND cm.direction = 'from_restaurant' AND cc.status = 'open'")->fetchColumn();

        // Оплаты
        $paymentsUp = $pdo->query("SELECT COUNT(*) FROM supplier_payments WHERE status IN ('upcoming', 'request_due')")->fetchColumn();

        // Топ поставщиков
        $topSt = $pdo->prepare("SELECT o.supplier, SUM(oi.qty_boxes * COALESCE(pp.price, 0)) as total
            FROM orders o JOIN order_items oi ON oi.order_id = o.id
            LEFT JOIN product_prices pp ON pp.sku = oi.sku AND pp.legal_entity = o.legal_entity AND pp.price_type = 'purchase'
            WHERE o.created_at_new >= ?
            GROUP BY o.supplier ORDER BY total DESC LIMIT 10");
        $topSt->execute([$from]);
        $topSuppliers = $topSt->fetchAll();

        // Просроченные заказы (детали)
        $overdueSt = $pdo->query("SELECT id, supplier, delivery_date, DATEDIFF(CURDATE(), delivery_date) as days_overdue FROM orders WHERE delivery_date < CURDATE() AND received_at IS NULL AND delivery_date >= '{$from}'" . ($le ? " AND legal_entity = " . $pdo->quote($le) : '') . " ORDER BY delivery_date LIMIT 10");
        $overdueOrders = $overdueSt->fetchAll();

        // Ближайшие оплаты
        $paysSt = $pdo->query("SELECT id, supplier, payment_date, amount, currency FROM supplier_payments WHERE status IN ('upcoming','request_due') ORDER BY payment_date LIMIT 5");
        $upcomingPayments = $paysSt->fetchAll();

        // Тендеры
        $activeTenders = intval($pdo->query("SELECT COUNT(*) FROM tenders WHERE status = 'collecting'")->fetchColumn());
        // Сборы остатков
        $activeCollections = intval($pdo->query("SELECT COUNT(*) FROM stock_collections WHERE status = 'active'")->fetchColumn());

        respond([
            'ordersCount' => $ordersCount, 'ordersDelta' => $ordersDelta,
            'totalAmount' => round($totalAmount, 0), 'amountDelta' => $amountDelta,
            'deliveredPct' => $deliveredPct, 'overdueCount' => intval($overdue),
            'lowStockCount' => intval($lowStock), 'correctionsPending' => intval($corrPending),
            'chatUnread' => intval($chatUnread), 'paymentsUpcoming' => intval($paymentsUp),
            'topSuppliers' => $topSuppliers,
            'overdueOrders' => $overdueOrders, 'upcomingPayments' => $upcomingPayments,
            'activeTenders' => $activeTenders, 'activeCollections' => $activeCollections,
        ]);
    }

    if ($fn === 'dashboard_critical_stock') {
        $le = $body['legal_entity'] ?? null;
        $leWhere = $le ? " AND a.legal_entity = " . $pdo->quote($le) : '';
        $st = $pdo->query("SELECT a.sku, p.analog_group, ROUND(a.stock / (a.consumption / GREATEST(a.period_days, 1)), 1) as days_of_stock
            FROM analysis_data a
            JOIN products p ON p.sku = a.sku AND p.legal_entity = a.legal_entity AND p.is_active = 1
            WHERE a.consumption > 0 AND a.stock > 0 AND a.stock / (a.consumption / GREATEST(a.period_days, 1)) <= 5 {$leWhere}
            ORDER BY days_of_stock ASC LIMIT 30");
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
        $st = $pdo->query("SELECT d.id, d.text, d.responsible_person, d.deadline, d.status, p.topic, p.meeting_date
            FROM protocol_decisions d
            JOIN meeting_protocols p ON p.id = d.protocol_id
            WHERE d.status IN ('pending', 'overdue')
            ORDER BY CASE WHEN d.deadline IS NULL THEN 1 ELSE 0 END, d.deadline ASC
            LIMIT 20");
        respond($st->fetchAll());
    }

    if ($fn === 'get_user_tg_settings') {
        $userName = $body['user_name'] ?? '';
        if (!$userName) respond(['error' => 'user_name required'], 400);
        $st = $pdo->prepare("SELECT daily_summary, psc_expiry, price_changed, overdue_delivery, data_updates, expiring_items, restaurant_sales, low_stock, correction_notifications, chat_notifications FROM telegram_settings WHERE user_name = ?");
        $st->execute([$userName]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        respond($row ?: []);
    }

    // ═══ Чат с ресторанами ═══

    if ($fn === 'chat_get_conversations') {
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
        $convId = intval($body['conversation_id'] ?? 0);
        if (!$convId) respond(['error' => 'conversation_id required'], 400);
        // Помечаем как прочитанные
        $pdo->prepare("UPDATE chat_messages SET is_read = 1 WHERE conversation_id = ? AND direction = 'from_restaurant' AND is_read = 0")->execute([$convId]);
        $st = $pdo->prepare("SELECT * FROM chat_messages WHERE conversation_id = ? ORDER BY created_at ASC LIMIT 500");
        $st->execute([$convId]);
        respond($st->fetchAll());
    }

    if ($fn === 'chat_send_message') {
        $convId = intval($body['conversation_id'] ?? 0);
        $text = trim($body['message_text'] ?? '');
        if (!$convId || !$text) respond(['error' => 'conversation_id and message_text required'], 400);
        $caller = getSessionUser($pdo);
        $senderName = $caller['name'] ?? 'Закупки';

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
                $tgText = "📨 <b>Ответ от отдела закупок</b>\n";
                $tgText .= "🏪 Ресторан {$c['restaurant_number']}\n";
                $tgText .= "─────────────────────\n";
                $tgText .= htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . "\n";
                $tgText .= "─────────────────────\n";
                $tgText .= "<i>Ответил: {$senderName}</i>";
                $payload = json_encode(['chat_id' => $c['restaurant_chat_id'], 'text' => $tgText, 'parse_mode' => 'HTML']);
                $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 5]);
                curl_exec($ch); curl_close($ch);
            }
        }
        respond(['success' => true, 'message_id' => $pdo->lastInsertId()]);
    }

    if ($fn === 'chat_close_conversation') {
        $convId = intval($body['conversation_id'] ?? 0);
        if (!$convId) respond(['error' => 'conversation_id required'], 400);
        $caller = getSessionUser($pdo);
        $pdo->prepare("UPDATE chat_conversations SET status = 'closed', closed_by = ?, closed_at = NOW() WHERE id = ?")
            ->execute([$caller['name'] ?? 'unknown', $convId]);
        respond(['success' => true]);
    }

    if ($fn === 'chat_reopen_conversation') {
        $convId = intval($body['conversation_id'] ?? 0);
        if (!$convId) respond(['error' => 'conversation_id required'], 400);
        $pdo->prepare("UPDATE chat_conversations SET status = 'open', closed_by = NULL, closed_at = NULL WHERE id = ?")->execute([$convId]);
        respond(['success' => true]);
    }

    if ($fn === 'chat_unread_total') {
        $cnt = $pdo->query("SELECT COUNT(*) FROM chat_messages cm JOIN chat_conversations cc ON cc.id = cm.conversation_id WHERE cm.is_read = 0 AND cm.direction = 'from_restaurant' AND cc.status = 'open'")->fetchColumn();
        respond(['count' => intval($cnt)]);
    }

    if ($fn === 'chat_send_photo') {
        $convId = intval($_POST['conversation_id'] ?? $body['conversation_id'] ?? 0);
        if (!$convId) respond(['error' => 'conversation_id required'], 400);
        if (empty($_FILES['photo'])) respond(['error' => 'Файл не выбран'], 400);

        $caller = getSessionUser($pdo);
        $senderName = $caller['name'] ?? 'Закупки';

        $conv = $pdo->prepare("SELECT restaurant_chat_id, restaurant_number FROM chat_conversations WHERE id = ?");
        $conv->execute([$convId]);
        $c = $conv->fetch();
        if (!$c) respond(['error' => 'Диалог не найден'], 404);

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
        $fileId = $body['file_id'] ?? ($_GET['file_id'] ?? '');
        if (!$fileId) respond(['error' => 'file_id required'], 400);
        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        $resp = @file_get_contents("https://api.telegram.org/bot{$botToken}/getFile?" . http_build_query(['file_id' => $fileId]));
        $data = json_decode($resp, true);
        $filePath = $data['result']['file_path'] ?? null;
        if (!$filePath) respond(['error' => 'File not found'], 404);
        respond(['url' => "https://api.telegram.org/file/bot{$botToken}/{$filePath}"]);
    }

    // ═══ ПРОТОКОЛЫ СОВЕЩАНИЙ ═══

    if ($fn === 'get_protocols') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $legalEntity = $body['legal_entity'] ?? $_GET['legal_entity'] ?? null;
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к юр. лицу'], 403);
        $s = $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM protocol_decisions d WHERE d.protocol_id = p.id) as decisions_count, (SELECT COUNT(*) FROM protocol_decisions d WHERE d.protocol_id = p.id AND d.status = 'done') as decisions_done, s.name as series_name FROM meeting_protocols p LEFT JOIN meeting_protocol_series s ON s.id = p.series_id WHERE p.legal_entity = ? ORDER BY p.meeting_date DESC, p.created_at DESC LIMIT 500");
        $s->execute([$legalEntity]);
        respond($s->fetchAll());
    }

    if ($fn === 'get_protocol') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'id required'], 400);
        $s = $pdo->prepare("SELECT p.*, s.name as series_name, s.recurrence, s.agenda_template FROM meeting_protocols p LEFT JOIN meeting_protocol_series s ON s.id = p.series_id WHERE p.id = ?");
        $s->execute([$id]);
        $proto = $s->fetch();
        if (!$proto) respond(['error' => 'Протокол не найден'], 404);
        if (!checkLegalEntityAccess($caller, $proto['legal_entity'] ?? null)) respond(['error' => 'Нет доступа'], 403);
        // Решения
        $d = $pdo->prepare("SELECT * FROM protocol_decisions WHERE protocol_id = ? ORDER BY id");
        $d->execute([$id]);
        $proto['decisions'] = $d->fetchAll();
        // Файлы
        $f = $pdo->prepare("SELECT id, file_name, file_path, uploaded_by, uploaded_at FROM meeting_protocol_files WHERE protocol_id = ? ORDER BY uploaded_at");
        $f->execute([$id]);
        $proto['files'] = $f->fetchAll();
        respond($proto);
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
                } else {
                    $pdo->prepare("INSERT INTO protocol_decisions (protocol_id, text, responsible_person, deadline, status, completed_at) VALUES (?,?,?,?,?,?)")
                        ->execute([$id, $decText, $responsible, $deadline, $decStatus, $completedAt]);
                    $existingIds[] = $pdo->lastInsertId();
                }
            }
            // Удаляем решения, которых больше нет
            if ($existingIds) {
                $ph = implode(',', array_fill(0, count($existingIds), '?'));
                $pdo->prepare("DELETE FROM protocol_decisions WHERE protocol_id = ? AND id NOT IN ($ph)")->execute(array_merge([$id], $existingIds));
            } else {
                $pdo->prepare("DELETE FROM protocol_decisions WHERE protocol_id = ?")->execute([$id]);
            }
            $pdo->commit();

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
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'id required'], 400);
        $existing = $pdo->prepare("SELECT created_by FROM meeting_protocols WHERE id = ?");
        $existing->execute([$id]);
        $row = $existing->fetch();
        if (!$row) respond(['error' => 'Не найден'], 404);
        if ($row['created_by'] !== $caller['name'] && !in_array($caller['role'], ['admin', 'manager'])) {
            respond(['error' => 'Удалить может только создатель или админ'], 403);
        }
        $pdo->prepare("DELETE FROM meeting_protocols WHERE id = ?")->execute([$id]);
        respond(['success' => true]);
    }

    if ($fn === 'update_decision_status') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $decId = intval($body['id'] ?? 0);
        $newStatus = $body['status'] ?? '';
        if (!$decId || !in_array($newStatus, ['pending', 'done', 'overdue'])) respond(['error' => 'Некорректные параметры'], 400);
        $completedAt = $newStatus === 'done' ? date('Y-m-d H:i:s') : null;
        $pdo->prepare("UPDATE protocol_decisions SET status = ?, completed_at = ? WHERE id = ?")->execute([$newStatus, $completedAt, $decId]);
        respond(['success' => true]);
    }

    // Серии совещаний
    if ($fn === 'get_carryover_tasks') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $seriesId = intval($body['series_id'] ?? 0);
        $excludeProtocolId = intval($body['exclude_protocol_id'] ?? 0);
        if (!$seriesId) respond([]);
        // Находим незакрытые задачи из всех протоколов этой серии
        $sql = "SELECT d.id, d.text, d.responsible_person, d.deadline, d.status, d.protocol_id, p.meeting_date, p.topic
                FROM protocol_decisions d
                JOIN meeting_protocols p ON p.id = d.protocol_id
                WHERE p.series_id = ? AND d.status IN ('pending','overdue')";
        $params = [$seriesId];
        if ($excludeProtocolId) { $sql .= " AND d.protocol_id != ?"; $params[] = $excludeProtocolId; }
        $sql .= " ORDER BY p.meeting_date DESC, d.id";
        $s = $pdo->prepare($sql);
        $s->execute($params);
        respond($s->fetchAll());
    }

    if ($fn === 'get_protocol_series') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $legalEntity = $body['legal_entity'] ?? $_GET['legal_entity'] ?? null;
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
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
        if (!$id && !$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!$id && !checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к юр. лицу'], 403);
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
        if ($caller['role'] !== 'admin') respond(['error' => 'Только для админов'], 403);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'id required'], 400);
        $pdo->prepare("DELETE FROM meeting_protocol_series WHERE id = ?")->execute([$id]);
        respond(['success' => true]);
    }

    if ($fn === 'get_users_list_short') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $s = $pdo->query("SELECT name, display_role, telegram_chat_id FROM users ORDER BY name");
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
                        FROM veg_telegram_subs vs
                        WHERE vs.restaurant_number = r.number
                    )
                  )
                ORDER BY r.number
            ");
            $stmt->execute([$group]);
            return $stmt->fetchAll();
        }
    }

    if (!function_exists('surveyGetRecipientChatIds')) {
        function surveyGetRecipientChatIds($pdo, $group) {
            $group = in_array($group, ['BK_VM', 'PS'], true) ? $group : 'BK_VM';
            $chatIds = [];

            $roStmt = $pdo->prepare("
                SELECT DISTINCT ru.telegram_chat_id
                FROM ro_users ru
                JOIN restaurants r
                  ON r.number = ru.restaurant_number
                 AND r.active = 1
                 AND r.legal_entity_group COLLATE utf8mb4_unicode_ci = ru.legal_entity_group COLLATE utf8mb4_unicode_ci
                WHERE ru.legal_entity_group COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
                  AND ru.is_active = 1
                  AND ru.telegram_chat_id IS NOT NULL
            ");
            $roStmt->execute([$group]);
            foreach ($roStmt->fetchAll(PDO::FETCH_COLUMN) as $chatId) {
                $chatId = trim((string)$chatId);
                if ($chatId !== '') $chatIds[$chatId] = true;
            }

            $vegStmt = $pdo->prepare("
                SELECT DISTINCT vs.chat_id
                FROM veg_telegram_subs vs
                JOIN restaurants r
                  ON r.number = vs.restaurant_number
                 AND r.active = 1
                 AND r.legal_entity_group COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
                WHERE vs.chat_id IS NOT NULL
            ");
            $vegStmt->execute([$group]);
            foreach ($vegStmt->fetchAll(PDO::FETCH_COLUMN) as $chatId) {
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

        foreach ($rows as &$row) {
            $group = $row['legal_entity_group'] ?? 'BK_VM';
            $row['target_restaurants_count'] = count(surveyGetTargetRestaurants($pdo, $group));
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

        $questions = $pdo->prepare("SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY sort_order, id");
        $questions->execute([$id]);
        $qs = $questions->fetchAll();

        foreach ($qs as &$q) {
            $opts = $pdo->prepare("SELECT * FROM survey_options WHERE question_id = ? ORDER BY sort_order, id");
            $opts->execute([$q['id']]);
            $q['options'] = $opts->fetchAll();
        }
        $s['questions'] = $qs;

        // Ответы
        $responses = $pdo->prepare("
            SELECT sr.id, sr.restaurant_number, sr.legal_entity_group, sr.comment, sr.submitted_at,
                   sr.telegram_chat_id, r.address, r.city
            FROM survey_responses sr
            LEFT JOIN restaurants r
              ON r.number = sr.restaurant_number
             AND r.legal_entity_group COLLATE utf8mb4_unicode_ci = sr.legal_entity_group COLLATE utf8mb4_unicode_ci
            WHERE sr.survey_id = ?
            ORDER BY sr.restaurant_number ASC, sr.submitted_at DESC
        ");
        $responses->execute([$id]);
        $respRows = $responses->fetchAll();

        foreach ($respRows as &$r) {
            $ans = $pdo->prepare("
                SELECT sa.question_id, sq.text AS question_text, so.id AS option_id, so.text AS option_text
                FROM survey_answers sa
                JOIN survey_questions sq ON sq.id = sa.question_id
                JOIN survey_options so ON so.id = sa.option_id
                WHERE sa.response_id = ?
                ORDER BY sq.sort_order, sq.id
            ");
            $ans->execute([$r['id']]);
            $r['answers'] = $ans->fetchAll();
        }
        $s['responses'] = $respRows;

        $answeredStmt = $pdo->prepare("SELECT restaurant_number FROM survey_responses WHERE survey_id = ?");
        $answeredStmt->execute([$id]);
        $answered = [];
        foreach ($answeredStmt->fetchAll(PDO::FETCH_COLUMN) as $restaurantNumber) {
            $answered[(int)$restaurantNumber] = true;
        }

        $pendingRows = [];
        foreach (surveyGetTargetRestaurants($pdo, $s['legal_entity_group']) as $restaurant) {
            if (!isset($answered[(int)$restaurant['restaurant_number']])) {
                $pendingRows[] = $restaurant;
            }
        }
        $s['pending_restaurants'] = $pendingRows;

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

            $normalizedOptions = [];
            foreach (($q['options'] ?? []) as $opt) {
                $optText = trim($opt['text'] ?? '');
                if ($optText !== '') {
                    $normalizedOptions[] = $optText;
                }
            }

            if (count($normalizedOptions) < 2) {
                respond(['error' => 'У каждого вопроса должно быть минимум 2 варианта ответа'], 400);
            }

            $normalizedQuestions[] = [
                'text' => $qText,
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
                $pdo->prepare("INSERT INTO survey_questions (survey_id, text, sort_order) VALUES (?,?,?)")
                    ->execute([$id, $q['text'], $qi]);
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

    if ($fn === 'survey_delete') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'surveys', 'full', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'id required'], 400);
        $pdo->prepare("DELETE FROM surveys WHERE id = ?")->execute([$id]);
        respond(['success' => true]);
    }

    respond(['error'=>'Not found'], 404);
}
