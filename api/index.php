<?php
header('Content-Type: application/json; charset=utf-8');
$allowed_origin = getenv('CORS_ORIGIN') ?: '';
if ($allowed_origin) {
    header("Access-Control-Allow-Origin: $allowed_origin");
} else {
    // По умолчанию — только свой домен
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($origin && parse_url($origin, PHP_URL_HOST) === $host) {
        header("Access-Control-Allow-Origin: $origin");
    }
}
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-Session-Token');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// Load .env
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($k, $v) = explode('=', $line, 2);
            $_ENV[trim($k)] = trim($v);
        }
    }
}

$DB_HOST = $_ENV['DB_HOST'] ?? 'localhost';
$DB_NAME = $_ENV['DB_NAME'] ?? 'supply_bk';
$DB_USER = $_ENV['DB_USER'] ?? 'siteuser';
$DB_PASS = $_ENV['DB_PASS'] ?? '';

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('DB connection error: ' . $e->getMessage());
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = preg_replace('#^/api/#', '', $uri);
$uri = trim($uri, '/');
$parts = explode('/', $uri);
$endpoint = $parts[0] ?? '';
$subpoint = $parts[1] ?? null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = json_decode(file_get_contents('php://input'), true) ?? [];

function respond($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION); exit; }
function cleanNumeric($rows) {
    $decimal = ['qty_per_box'];
    foreach ($rows as &$r) { foreach ($decimal as $col) { if (isset($r[$col])) $r[$col] = +$r[$col]; } }
    return $rows;
}
function uuid() { return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', random_int(0,0xffff),random_int(0,0xffff),random_int(0,0xffff),random_int(0,0x0fff)|0x4000,random_int(0,0x3fff)|0x8000,random_int(0,0xffff),random_int(0,0xffff),random_int(0,0xffff)); }

function verifyAndMigratePassword($pdo, $userName, $inputPassword, $storedHash) {
    // Сначала проверяем bcrypt-хеш
    if (password_verify($inputPassword, $storedHash)) return true;
    // Fallback: прямое сравнение (для plain text паролей)
    if ($storedHash === $inputPassword) {
        // Ленивая миграция: хешируем и обновляем в БД
        $hash = password_hash($inputPassword, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password=? WHERE name=?")->execute([$hash, $userName]);
        return true;
    }
    return false;
}

function checkApiKey($pdo) {
    $k = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!$k) return false;
    $s = $pdo->prepare("SELECT id FROM api_keys WHERE api_key=? AND is_active='true'");
    $s->execute([$k]);
    return $s->fetch() !== false;
}

// Проверка авторизации: сначала сессионный токен, затем API-ключ (для серверных/машинных запросов)
function checkAuth($pdo) {
    if (getSessionUser($pdo)) return true;
    return checkApiKey($pdo);
}

// Получить пользователя по сессионному токену из заголовка X-Session-Token
function getSessionUser($pdo) {
    $token = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
    if (!$token) return null;
    // Удаляем протухшие сессии (раз в запрос — дёшево)
    $pdo->exec("DELETE FROM user_sessions WHERE expires_at < NOW()");
    $s = $pdo->prepare("SELECT u.name, u.role, u.display_role, u.legal_entities, u.created_at FROM user_sessions s JOIN users u ON u.name = s.user_name WHERE s.token = ? AND s.expires_at > NOW()");
    $s->execute([$token]);
    $row = $s->fetch();
    if (!$row) return null;
    return $row;
}

function createSessionToken($pdo, $userName) {
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
    // Ограничиваем количество сессий на пользователя (макс 5)
    $pdo->prepare("DELETE FROM user_sessions WHERE user_name = ? AND id NOT IN (SELECT id FROM (SELECT id FROM user_sessions WHERE user_name = ? ORDER BY created_at DESC LIMIT 4) AS t)")->execute([$userName, $userName]);
    $pdo->prepare("INSERT INTO user_sessions (user_name, token, expires_at) VALUES (?, ?, ?)")->execute([$userName, $token, $expires]);
    return $token;
}

function checkRateLimit($pdo, $ip, $maxAttempts = 10, $windowMinutes = 10) {
    // Очистка старых записей
    $pdo->prepare("DELETE FROM failed_login_attempts WHERE attempted_at < NOW() - INTERVAL ? MINUTE")->execute([$windowMinutes]);
    // Подсчёт попыток
    $s = $pdo->prepare("SELECT COUNT(*) as cnt FROM failed_login_attempts WHERE ip_address = ? AND attempted_at > NOW() - INTERVAL ? MINUTE");
    $s->execute([$ip, $windowMinutes]);
    $count = $s->fetch()['cnt'] ?? 0;
    return $count < $maxAttempts;
}

function recordFailedLogin($pdo, $ip, $userName = '') {
    $pdo->prepare("INSERT INTO failed_login_attempts (ip_address, user_name, attempted_at) VALUES (?, ?, NOW())")->execute([$ip, $userName]);
}

function parseFilter($key, $val, &$where, &$params, $pdo, $table) {
    // Валидация имени колонки — только буквы, цифры, подчёркивание
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) return;
    $val = urldecode($val);
    if (strpos($val,'eq.')===0) { $where[]="`$key`=?"; $params[]=substr($val,3); }
    elseif (strpos($val,'neq.')===0) { $where[]="`$key`!=?"; $params[]=substr($val,4); }
    elseif (strpos($val,'gte.')===0) { $where[]="`$key`>=?"; $params[]=substr($val,4); }
    elseif (strpos($val,'gt.')===0) { $where[]="`$key`>?"; $params[]=substr($val,3); }
    elseif (strpos($val,'lte.')===0) { $where[]="`$key`<=?"; $params[]=substr($val,4); }
    elseif (strpos($val,'lt.')===0) { $where[]="`$key`<?"; $params[]=substr($val,3); }
    elseif (strpos($val,'in.')===0) {
        $inv = str_replace(['in.(',')',' '], '', $val);
        $arr = explode(',', $inv);
        $ph = implode(',', array_fill(0, count($arr), '?'));
        $where[] = "`$key` IN($ph)";
        $params = array_merge($params, $arr);
    }
    elseif (strpos($val,'ilike.')===0) {
        $where[] = "`$key` LIKE ?"; $params[] = str_replace('*', '%', substr($val, 6));
    }
    elseif ($val === 'is.null') { $where[] = "`$key` IS NULL"; }
    elseif ($val === 'not.is.null') { $where[] = "`$key` IS NOT NULL"; }
    else { $where[]="`$key`=?"; $params[]=$val; }
}

function parseOr($orStr, &$where, &$params, $allowedFields = []) {
    $parts = preg_split('/,(?=[a-zA-Z_])/', $orStr);
    $orClauses = [];
    foreach ($parts as $part) {
        if (preg_match('/^(\w+)\.(eq|neq|gt|gte|lt|lte)\.(.+)$/', $part, $m)) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $m[1])) continue;
            if (!empty($allowedFields) && !in_array($m[1], $allowedFields)) continue;
            $ops = ['eq'=>'=','neq'=>'!=','gt'=>'>','gte'=>'>=','lt'=>'<','lte'=>'<='];
            $orClauses[] = "`{$m[1]}` {$ops[$m[2]]} ?";
            $params[] = $m[3];
        } elseif (preg_match('/^(\w+)\.ilike\.(.+)$/', $part, $m)) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $m[1])) continue;
            if (!empty($allowedFields) && !in_array($m[1], $allowedFields)) continue;
            $orClauses[] = "`{$m[1]}` LIKE ?";
            $params[] = str_replace(['%25','*'], '%', $m[2]);
        }
    }
    if ($orClauses) $where[] = '(' . implode(' OR ', $orClauses) . ')';
}

// Debug endpoint disabled in production

// ═══ DELETE ACT ═══
if ($endpoint === 'upload' && $subpoint === 'act' && $method === 'DELETE') {
    if (!checkAuth($pdo)) respond(['error' => 'Unauthorized'], 401);
    $orderId = $_GET['order_id'] ?? '';
    if (!$orderId) respond(['error' => 'order_id required'], 400);

    $s = $pdo->prepare("SELECT act_file FROM orders WHERE id=?"); $s->execute([$orderId]); $old = $s->fetchColumn();
    if ($old) {
        $filepath = __DIR__ . '/uploads/acts/' . basename($old);
        if (file_exists($filepath)) unlink($filepath);
        $pdo->prepare("UPDATE orders SET act_file=NULL WHERE id=?")->execute([$orderId]);
    }
    respond(['success' => true]);
}

// ═══ UPLOAD ACT ═══
if ($endpoint === 'upload' && $subpoint === 'act') {
    if ($method !== 'POST') respond(['error' => 'Method not allowed'], 405);
    if (!checkAuth($pdo)) respond(['error' => 'Unauthorized'], 401);

    $orderId = $_POST['order_id'] ?? '';
    if (!$orderId) respond(['error' => 'order_id required'], 400);

    // Проверяем существование заказа
    $chk = $pdo->prepare("SELECT id FROM orders WHERE id=?"); $chk->execute([$orderId]);
    if (!$chk->fetch()) respond(['error' => 'Order not found'], 404);

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES['file']['error'] ?? -1;
        respond(['error' => 'Upload failed', 'code' => $code], 400);
    }

    $file = $_FILES['file'];
    $maxSize = 10 * 1024 * 1024; // 10 MB
    if ($file['size'] > $maxSize) respond(['error' => 'File too large (max 10MB)'], 400);

    $allowed = ['application/pdf','image/jpeg','image/png','image/webp','image/heic'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowed)) respond(['error' => 'File type not allowed. Allowed: PDF, JPEG, PNG, WebP, HEIC'], 400);

    $ext = match($mime) {
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        default => 'bin',
    };
    $filename = 'act_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $orderId) . '_' . time() . '.' . $ext;
    $uploadDir = __DIR__ . '/uploads/acts/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
    $dest = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) respond(['error' => 'Save failed'], 500);

    // Удалить старый файл, если есть
    $s = $pdo->prepare("SELECT act_file FROM orders WHERE id=?"); $s->execute([$orderId]); $old = $s->fetchColumn();
    if ($old && file_exists($uploadDir . basename($old))) unlink($uploadDir . basename($old));

    $path = 'uploads/acts/' . $filename;
    $pdo->prepare("UPDATE orders SET act_file=? WHERE id=?")->execute([$path, $orderId]);
    respond(['success' => true, 'path' => $path]);
}

// ═══ DOWNLOAD ACT ═══
if ($endpoint === 'uploads' && ($parts[1] ?? '') === 'acts' && isset($parts[2])) {
    if (!checkAuth($pdo)) respond(['error' => 'Unauthorized'], 401);
    $filename = basename($parts[2]);
    $filepath = __DIR__ . '/uploads/acts/' . $filename;
    if (!file_exists($filepath)) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $filepath);
    finfo_close($finfo);
    $disposition = isset($_GET['download']) ? 'attachment' : 'inline';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $filename) . '"');
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);
    exit;
}

// ═══ SEARCH ═══
if ($endpoint === 'search_products') {
    if (!checkAuth($pdo)) { respond(['error'=>'Unauthorized'], 401); }
    $q = $_GET['q'] ?? '';
    $le = $_GET['legal_entity'] ?? '';
    $supplier = $_GET['supplier'] ?? '';
    $limit = min(intval($_GET['limit'] ?? 10), 100);
    
    if (mb_strlen($q, 'UTF-8') < 2) respond([]);
    
    $where = [];
    $params = [];
    
    // Поиск по SKU или имени (экранируем спецсимволы LIKE)
    $escaped_q = str_replace(['%', '_'], ['\\%', '\\_'], $q);
    $where[] = "(`sku` LIKE ? OR `name` LIKE ?)";
    $params[] = "%{$escaped_q}%";
    $params[] = "%{$escaped_q}%";
    
    // Фильтр по юр. лицу
    if ($le && (strpos($le, 'Пицца Стар') !== false || $le === 'Пицца Стар')) {
        $where[] = "`legal_entity` LIKE ?";
        $params[] = '%Пицца Стар%';
    } elseif ($le) {
        $where[] = "(`legal_entity` LIKE ? OR `legal_entity` LIKE ?)";
        $params[] = '%Бургер БК%';
        $params[] = '%Воглия Матта%';
    }
    
    // Фильтр по поставщику
    if ($supplier) {
        $where[] = "`supplier` = ?";
        $params[] = $supplier;
    }
    
    $where[] = "`is_active` = 1";
    $sql = "SELECT * FROM `products` WHERE " . implode(' AND ', $where) . " LIMIT " . $limit;
    $s = $pdo->prepare($sql);
    $s->execute($params);
    respond(cleanNumeric($s->fetchAll()));
}

// ═══ RPC (публичные — без API-ключа) ═══
if ($endpoint === 'rpc') {
    $fn = $subpoint ?? '';
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // --- Публичные RPC (доступны без авторизации) ---

    if ($fn === 'check_user_password') {
        $email = $body['user_email'] ?? ''; $pass = $body['user_password'] ?? '';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['success'=>false,'error'=>'invalid_email'], 400);
        if (!checkRateLimit($pdo, $clientIp)) respond(['success'=>false,'error'=>'too_many_attempts'], 429);
        $s = $pdo->prepare("SELECT id,name,password,role,display_role,legal_entities,created_at FROM users WHERE email=?");
        $s->execute([$email]); $u = $s->fetch();
        if (!$u) { recordFailedLogin($pdo, $clientIp, $email); respond(['success'=>false,'error'=>'invalid_credentials']); }
        if (!verifyAndMigratePassword($pdo, $u['name'], $pass, $u['password'])) { recordFailedLogin($pdo, $clientIp, $email); respond(['success'=>false,'error'=>'invalid_credentials']); }
        $le = $u['legal_entities'];
        $le = ($le && is_string($le)) ? (json_decode($le, true) ?? []) : [];
        $displayRole = $u['display_role'] ?? null;
        $sessionToken = createSessionToken($pdo, $u['name']);
        try { $pdo->prepare("INSERT INTO login_log (email, user_name, ip, created_at) VALUES (?, ?, ?, NOW())")->execute([$email, $u['name'], $clientIp]); } catch (PDOException $e) {}
        $mm = $pdo->prepare("SELECT `key`,`value` FROM settings WHERE `key` IN ('maintenance_mode','maintenance_message')"); $mm->execute();
        $mmRows = $mm->fetchAll(); $maintenanceVal = 'false'; $maintenanceMsg = '';
        foreach ($mmRows as $mr) { if ($mr['key'] === 'maintenance_mode') $maintenanceVal = $mr['value']; if ($mr['key'] === 'maintenance_message') $maintenanceMsg = $mr['value']; }
        respond(['success'=>true,'user'=>['name'=>$u['name'],'role'=>$u['role']??'user','display_role'=>$displayRole,'legal_entities'=>$le,'created_at'=>$u['created_at'] ?? null],'session_token'=>$sessionToken,'maintenance_mode'=>$maintenanceVal==='true','maintenance_message'=>$maintenanceMsg ?: null]);
    }
    if ($fn === 'check_legacy_password') {
        $pwd = $body['pwd'] ?? '';
        if (!checkRateLimit($pdo, $clientIp)) respond(['success'=>false,'error'=>'too_many_attempts'], 429);
        $s = $pdo->prepare("SELECT value FROM settings WHERE `key`='order_calculator_password'"); $s->execute();
        $stored = $s->fetchColumn();
        if ($stored) {
            $ok = password_verify($pwd, $stored) || $stored === $pwd;
            if ($ok) {
                if ($stored === $pwd) {
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
        if (!checkRateLimit($pdo, $clientIp, 60, 1)) respond(['success' => true]); // Тихий rate-limit
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

    // Валидация сессии — проверяет session_token и возвращает данные пользователя
    if ($fn === 'validate_session') {
        $sessionUser = getSessionUser($pdo);
        if (!$sessionUser) {
            // Fallback: проверяем API-ключ + user_name (обратная совместимость)
            $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
            if (!$apiKey) respond(['valid' => false]);
            $s = $pdo->prepare("SELECT id FROM api_keys WHERE api_key=? AND is_active='true'");
            $s->execute([$apiKey]);
            if (!$s->fetch()) respond(['valid' => false]);
            $userName = $body['user_name'] ?? '';
            if ($userName) {
                $s2 = $pdo->prepare("SELECT name, role, display_role, legal_entities, created_at FROM users WHERE name=?");
                $s2->execute([$userName]);
                $u = $s2->fetch();
                if (!$u) respond(['valid' => false]);
                $le = ($u['legal_entities'] && is_string($u['legal_entities'])) ? (json_decode($u['legal_entities'], true) ?? []) : [];
                // Выдаём сессионный токен при валидации для миграции
                $sessionToken = createSessionToken($pdo, $u['name']);
                respond(['valid' => true, 'session_token' => $sessionToken, 'user' => ['name' => $u['name'], 'role' => $u['role'] ?? 'user', 'display_role' => $u['display_role'] ?? null, 'legal_entities' => $le, 'created_at' => $u['created_at'] ?? null]]);
            }
            respond(['valid' => true]);
        }
        $le = ($sessionUser['legal_entities'] && is_string($sessionUser['legal_entities'])) ? (json_decode($sessionUser['legal_entities'], true) ?? []) : [];
        respond(['valid' => true, 'user' => ['name' => $sessionUser['name'], 'role' => $sessionUser['role'] ?? 'user', 'display_role' => $sessionUser['display_role'] ?? null, 'legal_entities' => $le, 'created_at' => $sessionUser['created_at'] ?? null]]);
    }

    // --- Приватные RPC (требуют авторизацию) ---
    if (!checkAuth($pdo)) { respond(['error'=>'Unauthorized'], 401); }

    if ($fn === 'get_user_list') {
        $s = $pdo->query("SELECT name, email FROM users ORDER BY name");
        respond($s->fetchAll());
    }
    if ($fn === 'change_user_password') {
        if (!checkRateLimit($pdo, $clientIp, 10, 10)) respond(['success'=>false,'error'=>'too_many_attempts'], 429);
        $name = $body['user_name'] ?? '';
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
        if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'forbidden'], 403);
        $callerName = $caller['name'];
        $name = trim($body['name'] ?? '');
        $email = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';
        $role = $body['role'] ?? 'user';
        $displayRole = $body['display_role'] ?? null;
        $legalEntities = $body['legal_entities'] ?? '[]';
        if (!$name) respond(['success' => false, 'error' => 'name required'], 400);
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['success' => false, 'error' => 'invalid_email'], 400);
        if (!$password || mb_strlen($password) < 8) respond(['success' => false, 'error' => 'password required (min 8 chars)'], 400);
        if (!in_array($role, ['admin', 'user', 'viewer'])) $role = 'user';
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $id = uuid();
        try {
            $pdo->prepare("INSERT INTO users (id, name, email, password, role, display_role, legal_entities, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
                ->execute([$id, $name, $email ?: null, $hash, $role, $displayRole, is_array($legalEntities) ? json_encode($legalEntities, JSON_UNESCAPED_UNICODE) : $legalEntities]);
        } catch (PDOException $e) {
            respond(['success' => false, 'error' => 'User already exists or DB error'], 400);
        }
        respond(['success' => true, 'user' => ['id' => $id, 'name' => $name, 'email' => $email ?: null, 'role' => $role, 'display_role' => $displayRole]]);
    }
    if ($fn === 'update_user') {
        $caller = getSessionUser($pdo);
        if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'forbidden'], 403);
        $callerName = $caller['name'];
        $userId = $body['user_id'] ?? '';
        if (!$userId) respond(['success' => false, 'error' => 'user_id required'], 400);
        $sets = []; $params = [];
        if (isset($body['name']) && trim($body['name'])) { $sets[] = "name=?"; $params[] = trim($body['name']); }
        if (array_key_exists('email', $body)) {
            $emailVal = trim($body['email']);
            if ($emailVal && !filter_var($emailVal, FILTER_VALIDATE_EMAIL)) respond(['success' => false, 'error' => 'invalid_email'], 400);
            $sets[] = "email=?"; $params[] = $emailVal ?: null;
        }
        if (isset($body['role']) && in_array($body['role'], ['admin', 'user', 'viewer'])) { $sets[] = "role=?"; $params[] = $body['role']; }
        if (array_key_exists('display_role', $body)) { $sets[] = "display_role=?"; $params[] = $body['display_role']; }
        if (array_key_exists('legal_entities', $body)) { $sets[] = "legal_entities=?"; $params[] = is_array($body['legal_entities']) ? json_encode($body['legal_entities'], JSON_UNESCAPED_UNICODE) : $body['legal_entities']; }
        $passwordChanged = false;
        if (isset($body['password']) && $body['password'] !== '') {
            if (mb_strlen($body['password']) < 8) respond(['success' => false, 'error' => 'password_too_short'], 400);
            $sets[] = "password=?"; $params[] = password_hash($body['password'], PASSWORD_BCRYPT);
            $passwordChanged = true;
        }
        if (empty($sets)) respond(['success' => false, 'error' => 'nothing to update'], 400);
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
        if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'forbidden'], 403);
        $callerName = $caller['name'];
        $userId = $body['user_id'] ?? '';
        if (!$userId) respond(['success' => false, 'error' => 'user_id required'], 400);
        // Не позволять удалить себя
        $s2 = $pdo->prepare("SELECT name FROM users WHERE id=?"); $s2->execute([$userId]); $target = $s2->fetch();
        if ($target && $target['name'] === $callerName) respond(['success' => false, 'error' => 'cannot delete yourself'], 400);
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$userId]);
        respond(['success' => true]);
    }

    if ($fn === 'mark_notifications_read') {
        $ids = $body['ids'] ?? [];
        $user = $body['user_name'] ?? '';
        if (!$user || empty($ids)) respond(['success' => false, 'error' => 'missing params']);
        $ids = array_slice($ids, 0, 100); // Лимит на количество ID
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE notifications SET read_by = JSON_ARRAY_APPEND(COALESCE(read_by, '[]'), '$', ?) WHERE id IN ($ph) AND NOT JSON_CONTAINS(COALESCE(read_by, '[]'), JSON_QUOTE(?))")->execute(array_merge([$user], $ids, [$user]));
        respond(['success' => true]);
    }
    if ($fn === 'heartbeat') {
        $sessionUser = getSessionUser($pdo);
        $userName = $sessionUser ? $sessionUser['name'] : ($body['user_name'] ?? '');
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
        $userName = $body['user_name'] ?? '';
        if (!$orderId) respond(['locked' => false]);
        $s = $pdo->prepare("SELECT user_name FROM user_presence WHERE editing_order_id = ? AND user_name != ? AND last_seen > NOW() - INTERVAL 2 MINUTE LIMIT 1");
        $s->execute([$orderId, $userName]);
        $row = $s->fetch();
        respond($row ? ['locked' => true, 'locked_by' => $row['user_name']] : ['locked' => false]);
    }
    if ($fn === 'unlock_order') {
        $userName = $body['user_name'] ?? '';
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
        if (!$sessionUser || $sessionUser['role'] !== 'admin') respond(['success' => false, 'error' => 'forbidden'], 403);
        $userName = $sessionUser['name'];
        $title = $body['title'] ?? 'Важное сообщение';
        $message = $body['message'] ?? '';
        if (!$message) respond(['success' => false, 'error' => 'missing params'], 400);
        $pdo->prepare("INSERT INTO notifications (type, title, message, created_by, read_by, created_at) VALUES ('broadcast', ?, ?, ?, '[]', NOW())")
            ->execute([mb_substr($title, 0, 255), mb_substr($message, 0, 2000), $userName]);
        $id = $pdo->lastInsertId();
        respond(['success' => true, 'id' => $id]);
    }
    if ($fn === 'delete_notification_for_user') {
        $id = $body['id'] ?? null;
        $userName = $body['user_name'] ?? '';
        if (!$id || !$userName) respond(['success' => false, 'error' => 'missing params'], 400);
        $pdo->prepare("UPDATE notifications SET deleted_by = JSON_ARRAY_APPEND(COALESCE(deleted_by, '[]'), '$', ?) WHERE id = ? AND NOT JSON_CONTAINS(COALESCE(deleted_by, '[]'), JSON_QUOTE(?))")->execute([$userName, $id, $userName]);
        respond(['success' => true]);
    }
    if ($fn === 'delete_all_notifications_for_user') {
        $userName = $body['user_name'] ?? '';
        if (!$userName) respond(['success' => false, 'error' => 'missing params'], 400);
        $pdo->prepare("UPDATE notifications SET deleted_by = JSON_ARRAY_APPEND(COALESCE(deleted_by, '[]'), '$', ?) WHERE (target_user = ? OR type = 'broadcast') AND NOT JSON_CONTAINS(COALESCE(deleted_by, '[]'), JSON_QUOTE(?))")->execute([$userName, $userName, $userName]);
        respond(['success' => true]);
    }
    if ($fn === 'get_active_broadcasts') {
        $userName = $body['user_name'] ?? '';
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
        if (!$sessionUser || $sessionUser['role'] !== 'admin') respond(['success' => false, 'error' => 'forbidden'], 403);
        $id = $body['id'] ?? null;
        if (!$id) respond(['success' => false, 'error' => 'id required'], 400);
        $pdo->prepare("DELETE FROM notifications WHERE id = ? AND type = 'broadcast'")->execute([$id]);
        respond(['success' => true]);
    }
    if ($fn === 'replace_analysis_data') {
        $legalEntity = $body['legal_entity'] ?? '';
        $items = $body['items'] ?? [];
        if (!$legalEntity) respond(['error' => 'legal_entity required'], 400);
        if (!is_array($items)) respond(['error' => 'items must be array'], 400);
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
            respond(['success' => true, 'count' => count($items)]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("replace_analysis_data error: " . $e->getMessage());
            respond(['error' => 'Transaction failed'], 500);
        }
    }

    if ($fn === 'replace_order_items') {
        $orderId = $body['order_id'] ?? '';
        $items = $body['items'] ?? [];
        if (!$orderId) respond(['error' => 'order_id required'], 400);
        if (!is_array($items)) respond(['error' => 'items must be array'], 400);
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM `order_items` WHERE `order_id`=?")->execute([$orderId]);
            if (count($items) > 0) {
                foreach ($items as $item) {
                    if (!isset($item['order_id'])) $item['order_id'] = $orderId;
                    if (!isset($item['id'])) $item['id'] = uuid();
                    foreach (array_keys($item) as $col) {
                        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
                            $pdo->rollBack();
                            respond(['error' => 'Invalid column name: '.$col], 400);
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
            respond(['error' => 'Transaction failed'], 500);
        }
    }

    if ($fn === 'replace_restaurant_schedule') {
        $restaurantId = $body['restaurant_id'] ?? null;
        $items = $body['items'] ?? [];
        if (!$restaurantId) respond(['error' => 'restaurant_id required'], 400);
        if (!is_array($items)) respond(['error' => 'items must be array'], 400);
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM `delivery_schedule` WHERE `restaurant_id`=?")->execute([$restaurantId]);
            foreach ($items as $item) {
                $day = intval($item['day_of_week'] ?? 0);
                $time = $item['delivery_time'] ?? null;
                $notes = $item['notes'] ?? null;
                if ($day < 1 || $day > 6) continue;
                $pdo->prepare("INSERT INTO `delivery_schedule` (`restaurant_id`, `day_of_week`, `delivery_time`, `notes`) VALUES (?, ?, ?, ?)")
                    ->execute([$restaurantId, $day, $time, $notes]);
            }
            $pdo->commit();
            respond(['success' => true]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("replace_restaurant_schedule error: " . $e->getMessage());
            respond(['error' => 'Transaction failed'], 500);
        }
    }

    respond(['error'=>'Not found'], 404);
}

// ═══ API KEY ═══
if (!checkAuth($pdo)) { respond(['error'=>'Unauthorized'], 401); }

// ═══ REST ═══
$allowed = ['products','suppliers','orders','order_items','plans','item_order','settings','audit_log','stock_1c','search_logs','cards','users','analysis_data','notifications','restaurants','delivery_schedule'];
// Защита: только чтение через REST, запись — через RPC
$readOnly = ['search_logs', 'users'];
// settings — только чтение и обновление (без delete/insert для защиты системных ключей)
$noInsertDelete = ['settings'];
// audit_log — только чтение и вставка (без update/delete для защиты целостности)
$appendOnly = ['audit_log'];
if (!in_array($endpoint, $allowed)) { respond(['error'=>'Not found'], 404); }
$table = $endpoint;

// Enforce read-only
if (in_array($table, $readOnly) && $method !== 'GET') {
    respond(['error' => 'This table is read-only via REST API'], 403);
}
// Enforce no insert/delete for settings
if (in_array($table, $noInsertDelete) && ($method === 'POST' || $method === 'DELETE')) {
    respond(['error' => 'Insert/delete not allowed for this table via REST'], 403);
}
// Enforce append-only (allow GET + POST, block PATCH/PUT/DELETE)
if (in_array($table, $appendOnly) && !in_array($method, ['GET', 'POST'])) {
    respond(['error' => 'Only read and insert allowed for this table'], 403);
}

// Белый список полей, доступных для фильтрации через GET-параметры
$filterWhitelist = [
    'products'    => ['id','sku','name','supplier','legal_entity','is_active','analog_group','category'],
    'suppliers'   => ['id','short_name','legal_entity','is_active'],
    'orders'      => ['id','supplier','legal_entity','delivery_date','created_at','created_by','unit','received_at'],
    'order_items' => ['id','order_id','sku','name'],
    'plans'       => ['id','supplier','legal_entity','created_at'],
    'item_order'  => ['supplier','legal_entity','item_id'],
    'settings'    => ['key'],
    'audit_log'   => ['entity_type','entity_id','action','user_name'],
    'stock_1c'    => ['sku','legal_entity'],
    'cards'       => ['id','sku','name','supplier','legal_entity','is_active'],
    'notifications'=> ['id','type','target_user','entity_type','entity_id','legal_entity'],
    'restaurants' => ['id','legal_entity','legal_entity_group'],
    'delivery_schedule' => ['id','restaurant_id','legal_entity'],
    'analysis_data' => ['id','legal_entity','sku'],
];

if ($method === 'GET') {
    $where = []; $params = [];
    $allowedFields = $filterWhitelist[$table] ?? [];
    foreach ($_GET as $k => $v) {
        if (in_array($k, ['select','order','limit','offset','or'])) continue;
        if (!empty($allowedFields) && !in_array($k, $allowedFields)) continue;
        parseFilter($k, $v, $where, $params, $pdo, $table);
    }
    if (isset($_GET['or'])) parseOr($_GET['or'], $where, $params, $allowedFields);

    if ($subpoint) {
        $s = $pdo->prepare("SELECT * FROM `$table` WHERE id=?"); $s->execute([$subpoint]); $row = $s->fetch();
        if ($row && $table === 'orders') { $s2 = $pdo->prepare("SELECT * FROM order_items WHERE order_id=?"); $s2->execute([$subpoint]); $row['order_items'] = $s2->fetchAll(); }
        respond($row ?: ['error'=>'not found'], $row ? 200 : 404);
    }

    $sel = preg_replace('/\s+/', ' ', trim($_GET['select'] ?? '*'));
    // Убираем пробел между table_name и (
    $sel = preg_replace('/(\w)\s+\(/', '$1(', $sel);
    $hasSubSelect = false; $subTable = ''; $subCols = '';
    if (preg_match('/(\w+)\(([^)]+)\)/', $sel, $m)) {
        $hasSubSelect = true; $subTable = $m[1]; $subCols = $m[2];
        // Валидация имени подтаблицы
        if (!preg_match('/^[a-zA-Z_]\w*$/', $subTable)) { $hasSubSelect = false; $subTable = ''; $subCols = ''; }
        // Валидация колонок подзапроса
        if ($subCols !== '*') {
            $subColsArr = array_map('trim', explode(',', $subCols));
            foreach ($subColsArr as $sc) { if (!preg_match('/^[a-zA-Z_]\w*$/', $sc)) { $hasSubSelect = false; break; } }
        }
        $sel = trim(preg_replace('/,?\s*\w+\([^)]+\)/', '', $sel), ', ');
        if (!$sel) $sel = '*';
    }
    // Валидация основных колонок SELECT + обёртка в обратные кавычки
    if ($sel !== '*') {
        $selCols = array_map('trim', explode(',', $sel));
        $valid = true;
        foreach ($selCols as $sc) { if (!preg_match('/^[a-zA-Z_]\w*$/', $sc)) { $valid = false; break; } }
        $sel = $valid ? implode(',', array_map(fn($c) => "`$c`", $selCols)) : '*';
    }

    // Поиск по товарам внутри заказов
    if ($table === 'orders' && isset($_GET['search']) && trim($_GET['search']) !== '') {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], trim($_GET['search']));
        $searchTerm = '%' . $escaped . '%';
        $where[] = "id IN (SELECT order_id FROM order_items WHERE name LIKE ? ESCAPE '\\\\' OR sku LIKE ? ESCAPE '\\\\')";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    $sql = "SELECT $sel FROM `$table`";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    if (isset($_GET['order'])) {
        $op = explode('.', $_GET['order']);
        // Валидация имени колонки ORDER BY
        if (preg_match('/^[a-zA-Z_]\w*$/', $op[0])) {
            $sql .= " ORDER BY `{$op[0]}` " . (($op[1]??'asc')==='desc'?'DESC':'ASC');
        }
    }
    if (isset($_GET['limit'])) $sql .= " LIMIT " . min(intval($_GET['limit']), 5000);
    if (isset($_GET['offset'])) {
        if (!isset($_GET['limit'])) $sql .= " LIMIT 5000";
        $sql .= " OFFSET " . intval($_GET['offset']);
    }

    try {
        $s = $pdo->prepare($sql); $s->execute($params); $data = $s->fetchAll();
    } catch (PDOException $e) {
        error_log("SELECT error [{$table}]: " . $e->getMessage());
        respond(['error' => 'Query failed'], 500);
    }

    if ($hasSubSelect && $subTable && in_array($subTable, $allowed) && !empty($data)) {
        $fk = $table === 'orders' ? 'order_id' : 'id';
        $ids = array_column($data, 'id');
        if ($ids) {
            // Убедимся что FK-колонка включена в SELECT подтаблицы
            $subSelCols = $subCols;
            $fkIncluded = ($subCols === '*');
            if (!$fkIncluded) {
                $subColsArr = array_map('trim', explode(',', $subCols));
                if (!in_array($fk, $subColsArr)) {
                    $subSelCols = "`$fk`," . $subCols;
                }
                $fkIncluded = in_array($fk, $subColsArr);
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $s2 = $pdo->prepare("SELECT $subSelCols FROM `$subTable` WHERE `$fk` IN ($ph)");
            $s2->execute($ids);
            $subRows = $s2->fetchAll();
            // Группируем по FK
            $grouped = [];
            foreach ($subRows as $sr) {
                $key = $sr[$fk];
                // Убрать FK из результата если он не был в оригинальном запросе
                if ($subCols !== '*' && !$fkIncluded) unset($sr[$fk]);
                // Скрыть пароль при подзапросе таблицы users
                if ($subTable === 'users') unset($sr['password']);
                $grouped[$key][] = $sr;
            }
            foreach ($data as &$row) {
                $row[$subTable] = $grouped[$row['id']] ?? [];
            }
        }
    }
    if ($table === 'products') $data = cleanNumeric($data);
    // Скрыть пароль при чтении users
    if ($table === 'users') { foreach ($data as &$r) { unset($r['password']); } }
    respond($data);
}

if ($method === 'POST') {
    if (!is_array($body) || count($body) === 0) respond(['error' => 'Empty body'], 400);
    // Запрет создания broadcast-уведомлений через REST (только через RPC send_broadcast)
    if ($table === 'notifications') {
        $recs_check = isset($body[0]) ? $body : [$body];
        foreach ($recs_check as $rc) { if (isset($rc['type']) && $rc['type'] === 'broadcast') respond(['error' => 'Use RPC send_broadcast'], 403); }
    }
    $recs = isset($body[0]) ? $body : [$body]; $ins = [];
    foreach ($recs as $rec) {
        if (!isset($rec['id']) && !in_array($table, ['audit_log','search_logs','api_keys','settings','notifications','delivery_schedule','restaurants'])) $rec['id'] = uuid();
        foreach (['items','details','legal_entities','sku_order','analogs','data'] as $jc) { if (isset($rec[$jc]) && is_array($rec[$jc])) $rec[$jc] = json_encode($rec[$jc], JSON_UNESCAPED_UNICODE); }
        // Валидация имён колонок
        foreach (array_keys($rec) as $col) { if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) respond(['error' => 'Invalid column name: '.$col], 400); }
        $cols = array_keys($rec); $ph = implode(',', array_fill(0, count($cols), '?')); $cn = implode(',', array_map(fn($c) => "`$c`", $cols));
        try {
            if ($table === 'analysis_data') {
                // Upsert: при дубле обновляем данные
                $upd = implode(',', array_map(fn($c) => "`$c`=VALUES(`$c`)", $cols));
                $s = $pdo->prepare("INSERT INTO `$table` ($cn) VALUES ($ph) ON DUPLICATE KEY UPDATE $upd");
            } else {
                $s = $pdo->prepare("INSERT INTO `$table` ($cn) VALUES ($ph)");
            }
            $s->execute(array_values($rec));
        } catch (PDOException $e) {
            error_log("INSERT error [{$table}]: " . $e->getMessage());
            respond(['error' => 'Insert failed'], 500);
        }
        $lid = $rec['id'] ?? $pdo->lastInsertId();
        $s2 = $pdo->prepare("SELECT * FROM `$table` WHERE id=?"); $s2->execute([$lid]); $r = $s2->fetch(); if ($r) $ins[] = $r;
    }
    if ($table === 'users') { foreach ($ins as &$r) { unset($r['password']); } }
    respond(count($ins) === 1 ? $ins[0] : $ins, 201);
}

if ($method === 'PATCH' || $method === 'PUT') {
    $where = []; $params = [];
    $allowedFields = $filterWhitelist[$table] ?? [];
    foreach ($_GET as $k => $v) { if (in_array($k, ['select','order','limit','offset','or'])) continue; if (!empty($allowedFields) && !in_array($k, $allowedFields)) continue; parseFilter($k, $v, $where, $params, $pdo, $table); }
    if (isset($_GET['or'])) parseOr($_GET['or'], $where, $params, $allowedFields);
    if ($subpoint) { $where = ["`id`=?"]; $params = [$subpoint]; }
    if (!$where) respond(['error'=>'No filters'], 400);
    if (!is_array($body) || count($body) === 0) respond(['error' => 'Empty body'], 400);
    foreach (['items','details','legal_entities','sku_order','analogs','data'] as $jc) { if (isset($body[$jc]) && is_array($body[$jc])) $body[$jc] = json_encode($body[$jc], JSON_UNESCAPED_UNICODE); }
    // Валидация имён колонок
    foreach (array_keys($body) as $col) { if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) respond(['error' => 'Invalid column name: '.$col], 400); }
    $set = []; $sp = [];
    foreach ($body as $c => $v) { $set[] = "`$c`=?"; $sp[] = $v; }
    $all = array_merge($sp, $params);
    try {
        $s = $pdo->prepare("UPDATE `$table` SET " . implode(',', $set) . " WHERE " . implode(' AND ', $where)); $s->execute($all);
    } catch (PDOException $e) {
        error_log("UPDATE error [{$table}]: " . $e->getMessage());
        respond(['error' => 'Update failed'], 500);
    }
    $s2 = $pdo->prepare("SELECT * FROM `$table` WHERE " . implode(' AND ', $where)); $s2->execute($params);
    $result = $s2->fetchAll();
    if ($table === 'users') { foreach ($result as &$r) { unset($r['password']); } }
    respond($result);
}

if ($method === 'DELETE') {
    $where = []; $params = [];
    if ($subpoint) { $where[] = "`id`=?"; $params[] = $subpoint; }
    else { $allowedFields = $filterWhitelist[$table] ?? []; foreach ($_GET as $k => $v) { if (in_array($k, ['select','order','limit','offset','or'])) continue; if (!empty($allowedFields) && !in_array($k, $allowedFields)) continue; parseFilter($k, $v, $where, $params, $pdo, $table); } if (isset($_GET['or'])) parseOr($_GET['or'], $where, $params, $allowedFields); }
    if (!$where) respond(['error'=>'No filters'], 400);
    // Запоминаем ID удаляемых записей для аудита
    $deletedIds = [];
    if (in_array($table, ['orders','plans','products','suppliers','restaurants'])) {
        try {
            $preS = $pdo->prepare("SELECT `id` FROM `$table` WHERE " . implode(' AND ', $where));
            $preS->execute($params);
            $deletedIds = array_column($preS->fetchAll(), 'id');
        } catch (PDOException $e) { /* не блокируем удаление */ }
    }
    try {
        $s = $pdo->prepare("DELETE FROM `$table` WHERE " . implode(' AND ', $where)); $s->execute($params);
    } catch (PDOException $e) {
        error_log("DELETE error [{$table}]: " . $e->getMessage());
        respond(['error' => 'Delete failed'], 500);
    }
    // Аудит-лог для удалений
    if ($s->rowCount() > 0 && !empty($deletedIds)) {
        try {
            foreach ($deletedIds as $did) {
                $pdo->prepare("INSERT INTO `audit_log` (`action`, `entity_type`, `entity_id`, `details`, `created_at`) VALUES (?, ?, ?, '{}', NOW())")
                    ->execute([$table . '_deleted', $table, $did]);
            }
        } catch (PDOException $e) { /* не блокируем ответ */ }
    }
    respond(['deleted' => $s->rowCount()]);
}

respond(['error'=>'Method not allowed'], 405);