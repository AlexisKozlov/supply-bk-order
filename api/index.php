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

// ═══ ROLE TEMPLATES & PERMISSIONS ═══
$ROLE_TEMPLATES = [
    'admin' => ['order'=>'full','planning'=>'full','history'=>'full','plan-fact'=>'full','database'=>'full','delivery-schedule'=>'full','analytics'=>'full','calendar'=>'full','analysis'=>'full','shelf-life'=>'full','pricing'=>'full'],
    'user'  => ['order'=>'edit','planning'=>'edit','history'=>'edit','plan-fact'=>'edit','database'=>'edit','delivery-schedule'=>'edit','analytics'=>'view','calendar'=>'view','analysis'=>'edit','shelf-life'=>'edit','pricing'=>'edit'],
    'viewer' => ['order'=>'view','planning'=>'view','history'=>'view','plan-fact'=>'view','database'=>'view','delivery-schedule'=>'view','analytics'=>'view','calendar'=>'view','analysis'=>'view','shelf-life'=>'view','pricing'=>'view'],
];
$ACCESS_LEVELS = ['none'=>0,'view'=>1,'edit'=>2,'full'=>3];
$TABLE_TO_MODULE = [
    'orders'=>'order','order_items'=>'order',
    'plans'=>'planning',
    'products'=>'database','suppliers'=>'database','restaurants'=>'database','cards'=>'database',
    'delivery_schedule'=>'delivery-schedule',
    'analysis_data'=>'analysis','stock_1c'=>'analysis',
    'stock_malling'=>'shelf-life',
    'audit_log'=>'history','notifications'=>'history',
    'settings'=>'database','item_order'=>'order',
    'deficit_sessions'=>'order','deficit_results'=>'order','deficit_tokens'=>'order','deficit_restaurant_stock'=>'order',
    'stock_collections'=>'order','stock_collection_products'=>'order','stock_collection_data'=>'order','stock_collection_tokens'=>'order',
    'price_agreements'=>'pricing','product_prices'=>'pricing',
];

function resolvePermissions($role, $permissionsJson, $templates) {
    $base = $templates[$role] ?? $templates['user'];
    if ($role === 'admin') return $templates['admin'];
    if (!$permissionsJson) return $base;
    $overrides = is_string($permissionsJson) ? json_decode($permissionsJson, true) : $permissionsJson;
    if (!is_array($overrides)) return $base;
    // Только ключи, существующие в базовом шаблоне — без эскалации привилегий
    return array_merge($base, array_intersect_key($overrides, $base));
}

// Проверка доступа к юр. лицу: пользователь может работать только со своими юрлицами
function checkLegalEntityAccess($sessionUser, $legalEntity) {
    if (!$sessionUser) return true; // нет сессии (API-ключ) — пропускаем
    if (!$legalEntity) return false; // юрлицо обязательно для авторизованных пользователей
    if (($sessionUser['role'] ?? '') === 'admin') return true;
    $userEntities = $sessionUser['legal_entities'] ?? '';
    if (is_string($userEntities)) {
        $userEntities = json_decode($userEntities, true);
    }
    if (!is_array($userEntities) || empty($userEntities)) return false; // нет назначенных юрлиц — доступ закрыт
    return in_array($legalEntity, $userEntities);
}

// Таблицы, в которых есть поле legal_entity и нужна проверка доступа
$ENTITY_TABLES = ['orders','order_items','plans','item_order','analysis_data','stock_1c','product_adu','notifications','delivery_schedule','deficit_sessions','deficit_tokens','stock_collections','restaurants','price_agreements','product_prices'];

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
    // Fallback: прямое сравнение (для plain text паролей, безопасное по времени)
    if (hash_equals($storedHash, $inputPassword)) {
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
// Результат кэшируется — безопасно вызывать многократно за один запрос
$_sessionUserCache = ['done' => false, 'result' => null];
function getSessionUser($pdo) {
    global $_sessionUserCache;
    if ($_sessionUserCache['done']) return $_sessionUserCache['result'];
    $_sessionUserCache['done'] = true;

    $token = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
    if (!$token) { $_sessionUserCache['result'] = null; return null; }
    // Очистка протухших сессий — в ~1% запросов (вместо каждого)
    if (mt_rand(1, 100) === 1) {
        try { $pdo->exec("DELETE FROM user_sessions WHERE expires_at < NOW()"); } catch (PDOException $e) { /* не критично */ }
    }
    $s = $pdo->prepare("SELECT u.name, u.role, u.display_role, u.legal_entities, u.permissions, u.created_at FROM user_sessions s JOIN users u ON u.name = s.user_name WHERE s.token = ? AND s.expires_at > NOW()");
    $s->execute([$token]);
    $row = $s->fetch();
    if (!$row) { $_sessionUserCache['result'] = null; return null; }
    // Скользящая сессия: продлеваем на 7 дней, но не чаще раза в час (экономия UPDATE-запросов)
    static $sessionUpdated = false;
    if (!$sessionUpdated) {
        $s2 = $pdo->prepare("SELECT expires_at FROM user_sessions WHERE token = ?");
        $s2->execute([$token]);
        $exp = $s2->fetchColumn();
        if ($exp && strtotime($exp) - time() < 6 * 86400) { // осталось < 6 дней — продлить
            $pdo->prepare("UPDATE user_sessions SET expires_at = ? WHERE token = ?")->execute([date('Y-m-d H:i:s', strtotime('+7 days')), $token]);
        }
        $sessionUpdated = true;
    }
    $_sessionUserCache['result'] = $row;
    return $row;
}

function createSessionToken($pdo, $userName) {
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+7 days'));
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 512) : null;
    // Ограничиваем количество сессий на пользователя (макс 5)
    $pdo->prepare("DELETE FROM user_sessions WHERE user_name = ? AND id NOT IN (SELECT id FROM (SELECT id FROM user_sessions WHERE user_name = ? ORDER BY created_at DESC LIMIT 4) AS t)")->execute([$userName, $userName]);
    $pdo->prepare("INSERT INTO user_sessions (user_name, token, expires_at, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)")->execute([$userName, $token, $expires, $ip, $ua]);
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
        // Разбиваем по запятой, но не по экранированной \,
        $arr = preg_split('/(?<!\\\\),/', $inv);
        $arr = array_map(fn($x) => str_replace('\\,', ',', $x), $arr);
        $ph = implode(',', array_fill(0, count($arr), '?'));
        $where[] = "`$key` IN($ph)";
        $params = array_merge($params, $arr);
    }
    elseif (strpos($val,'ilike.')===0) {
        $raw = substr($val, 6);
        // Экранируем спецсимволы LIKE, затем заменяем * на %
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $raw);
        $where[] = "`$key` LIKE ? ESCAPE '\\\\'"; $params[] = str_replace('*', '%', $escaped);
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
    $su = getSessionUser($pdo);
    if ($su && $su['role'] !== 'admin') {
        $p = resolvePermissions($su['role'], $su['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$p['plan-fact'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);
    }
    $orderId = $_GET['order_id'] ?? '';
    if (!$orderId) respond(['error' => 'order_id required'], 400);
    // Проверка доступа к юрлицу заказа
    $orderChk = $pdo->prepare("SELECT legal_entity, act_file FROM orders WHERE id=?"); $orderChk->execute([$orderId]); $orderRow = $orderChk->fetch();
    if (!$orderRow) respond(['error' => 'Order not found'], 404);
    if ($su && !checkLegalEntityAccess($su, $orderRow['legal_entity'])) respond(['error' => 'Нет доступа к юр. лицу заказа'], 403);
    $old = $orderRow['act_file'];
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
    $su = getSessionUser($pdo);
    if ($su && $su['role'] !== 'admin') {
        $p = resolvePermissions($su['role'], $su['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$p['plan-fact'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);
    }

    $orderId = $_POST['order_id'] ?? '';
    if (!$orderId) respond(['error' => 'order_id required'], 400);

    // Проверяем существование заказа и доступ к юрлицу
    $chk = $pdo->prepare("SELECT id, legal_entity FROM orders WHERE id=?"); $chk->execute([$orderId]);
    $orderRow = $chk->fetch();
    if (!$orderRow) respond(['error' => 'Order not found'], 404);
    if ($su && !checkLegalEntityAccess($su, $orderRow['legal_entity'])) respond(['error' => 'Нет доступа к юр. лицу заказа'], 403);

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

// ═══ UPLOAD PSC FILE ═══
if ($endpoint === 'upload' && $subpoint === 'psc') {
    if ($method !== 'POST') respond(['error' => 'Method not allowed'], 405);
    if (!checkAuth($pdo)) respond(['error' => 'Unauthorized'], 401);
    $su = getSessionUser($pdo);
    if (!$su) respond(['error' => 'Требуется авторизация'], 401);
    $p = resolvePermissions($su['role'], $su['permissions'] ?? null, $ROLE_TEMPLATES);
    if (($ACCESS_LEVELS[$p['pricing'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);

    $agreementId = $_POST['agreement_id'] ?? '';
    if (!$agreementId) respond(['error' => 'agreement_id required'], 400);

    $chk = $pdo->prepare("SELECT id, legal_entity FROM price_agreements WHERE id=?"); $chk->execute([$agreementId]);
    $ag = $chk->fetch();
    if (!$ag) respond(['error' => 'Agreement not found'], 404);
    if (!checkLegalEntityAccess($su, $ag['legal_entity'])) respond(['error' => 'Нет доступа к юр. лицу'], 403);

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        respond(['error' => 'Upload failed'], 400);
    }
    $file = $_FILES['file'];
    $maxSize = 10 * 1024 * 1024;
    if ($file['size'] > $maxSize) respond(['error' => 'Файл слишком большой (макс 10МБ)'], 400);

    $allowedMime = ['application/pdf','image/jpeg','image/png','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/vnd.ms-excel'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowedMime)) respond(['error' => 'Допустимые форматы: PDF, JPEG, PNG, Excel'], 400);

    $ext = match($mime) {
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-excel' => 'xls',
        default => 'bin',
    };
    $filename = 'psc_' . intval($agreementId) . '_' . time() . '.' . $ext;
    $uploadDir = __DIR__ . '/uploads/psc/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
    $dest = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) respond(['error' => 'Save failed'], 500);

    // Удалить старый файл, если есть
    $s = $pdo->prepare("SELECT file_path FROM price_agreements WHERE id=?"); $s->execute([$agreementId]);
    $old = $s->fetchColumn();
    if ($old && file_exists(__DIR__ . '/' . $old)) unlink(__DIR__ . '/' . $old);

    $path = 'uploads/psc/' . $filename;
    $origName = mb_substr($file['name'], 0, 255);
    $pdo->prepare("UPDATE price_agreements SET file_path=?, file_name=? WHERE id=?")->execute([$path, $origName, $agreementId]);
    respond(['success' => true, 'path' => $path, 'file_name' => $origName]);
}

// ═══ DOWNLOAD PSC FILE ═══
if ($endpoint === 'uploads' && ($parts[1] ?? '') === 'psc' && isset($parts[2])) {
    if (!checkAuth($pdo)) respond(['error' => 'Unauthorized'], 401);
    $filename = basename($parts[2]);
    $filepath = __DIR__ . '/uploads/psc/' . $filename;
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
        $s = $pdo->prepare("SELECT id,name,password,role,display_role,legal_entities,permissions,created_at FROM users WHERE email=?");
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
        respond(['success'=>true,'user'=>['name'=>$u['name'],'role'=>$u['role']??'user','display_role'=>$displayRole,'legal_entities'=>$le,'permissions'=>$permsDecoded,'created_at'=>$u['created_at'] ?? null],'session_token'=>$sessionToken,'maintenance_mode'=>$maintenanceVal==='true','maintenance_message'=>$maintenanceMsg ?: null]);
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
        $group = (strpos($le, 'Пицца Стар') !== false) ? 'PS' : 'BK_VM';
        $s2 = $pdo->prepare("SELECT id, number, address, city FROM restaurants WHERE legal_entity_group = ? ORDER BY sort_order");
        $s2->execute([$group]);
        respond($s2->fetchAll());
    }
    if ($fn === 'deficit_submit_stock') {
        $tokenVal = $body['token_value'] ?? '';
        $restNum = $body['restaurant_num'] ?? '';
        $stockVal = floatval($body['stock_value'] ?? 0);
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
        $group = (strpos($le, 'Пицца Стар') !== false) ? 'PS' : 'BK_VM';
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
        $ins = $pdo->prepare("INSERT INTO stock_collection_data (collection_id, product_id, restaurant_number, stock, source, submitted_at) VALUES (?, ?, ?, ?, 'form', NOW()) ON DUPLICATE KEY UPDATE stock = VALUES(stock), submitted_at = NOW()");
        foreach ($items as $item) {
            $pid = intval($item['product_id'] ?? 0);
            $sv = floatval($item['stock'] ?? 0);
            if ($pid > 0) $ins->execute([$collId, $pid, $restNum, $sv]);
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
        respond(['valid' => true, 'user' => ['name' => $sessionUser['name'], 'role' => $sessionUser['role'] ?? 'user', 'display_role' => $sessionUser['display_role'] ?? null, 'legal_entities' => $le, 'permissions' => $permsDecoded2, 'created_at' => $sessionUser['created_at'] ?? null]]);
    }

    if ($fn === 'logout') {
        $token = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
        if ($token) {
            $pdo->prepare("DELETE FROM user_sessions WHERE token = ?")->execute([$token]);
        }
        respond(['success' => true]);
    }

    // --- Приватные RPC (требуют авторизацию) ---
    if (!checkAuth($pdo)) { respond(['error'=>'Unauthorized'], 401); }

    // Получаем имя авторизованного пользователя из сессии (для защиты от подмены user_name)
    $authUser = getSessionUser($pdo);
    $authUserName = $authUser ? $authUser['name'] : '';

    // ═══ DEFICIT: приватные RPC ═══
    if ($fn === 'deficit_create_token') {
        $le = $body['legal_entity'] ?? '';
        $pname = mb_substr($body['product_name'] ?? '', 0, 255);
        $uname = $authUserName ?: ($body['user_name'] ?? '');
        if (!$le || !$pname) respond(['error' => 'missing_params'], 400);
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
        if (!$le || !$name || empty($products)) respond(['error' => 'missing_params'], 400);
        if (!checkLegalEntityAccess($authUser, $le)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $s = $pdo->prepare("INSERT INTO stock_collections (legal_entity, name, created_by) VALUES (?, ?, ?)");
        $s->execute([$le, $name, $uname]);
        $collId = $pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO stock_collection_products (collection_id, product_name, product_sku, unit, sort_order) VALUES (?, ?, ?, ?, ?)");
        foreach ($products as $i => $p) {
            $pname = mb_substr($p['name'] ?? '', 0, 255);
            $psku = mb_substr($p['sku'] ?? '', 0, 50) ?: null;
            $punit = in_array($p['unit'] ?? '', ['boxes', 'pieces']) ? $p['unit'] : 'pieces';
            $ins->execute([$collId, $pname, $psku, $punit, $i]);
        }
        respond(['id' => $collId]);
    }
    if ($fn === 'sc_create_token') {
        $collId = intval($body['collection_id'] ?? 0);
        $uname = $authUserName ?: ($body['user_name'] ?? '');
        if (!$collId) respond(['error' => 'missing_params'], 400);
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
        if (!$collId) respond(['error' => 'missing_params'], 400);
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
        if (!$collId) respond(['error' => 'missing_params'], 400);
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
        if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'forbidden'], 403);
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
        if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'forbidden'], 403);
        $callerName = $caller['name'];
        $name = trim($body['name'] ?? '');
        $email = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';
        $role = $body['role'] ?? 'user';
        $displayRole = $body['display_role'] ?? null;
        $legalEntities = $body['legal_entities'] ?? '[]';
        $permissions = $body['permissions'] ?? null;
        if (!$name) respond(['success' => false, 'error' => 'name required'], 400);
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['success' => false, 'error' => 'invalid_email'], 400);
        if (!$password || mb_strlen($password) < 8) respond(['success' => false, 'error' => 'password required (min 8 chars)'], 400);
        if (!in_array($role, ['admin', 'user', 'viewer'])) $role = 'user';
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $permJson = ($permissions && is_array($permissions) && count($permissions) > 0) ? json_encode($permissions, JSON_UNESCAPED_UNICODE) : null;
        $id = uuid();
        try {
            $pdo->prepare("INSERT INTO users (id, name, email, password, role, display_role, legal_entities, permissions, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                ->execute([$id, $name, $email ?: null, $hash, $role, $displayRole, is_array($legalEntities) ? json_encode($legalEntities, JSON_UNESCAPED_UNICODE) : $legalEntities, $permJson]);
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
        if (array_key_exists('permissions', $body)) {
            $pv = $body['permissions'];
            $sets[] = "permissions=?";
            $params[] = ($pv && is_array($pv) && count($pv) > 0) ? json_encode($pv, JSON_UNESCAPED_UNICODE) : null;
        }
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
        // Удаляем активные сессии пользователя, чтобы он не мог продолжать работу
        if ($target) {
            $pdo->prepare("DELETE FROM user_sessions WHERE user_name=?")->execute([$target['name']]);
        }
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$userId]);
        respond(['success' => true]);
    }

    if ($fn === 'mark_notifications_read') {
        $ids = $body['ids'] ?? [];
        $user = $authUserName;
        if (!$user || empty($ids)) respond(['success' => false, 'error' => 'missing params']);
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
        $userName = $authUserName;
        if (!$id || !$userName) respond(['success' => false, 'error' => 'missing params'], 400);
        $pdo->prepare("UPDATE notifications SET deleted_by = JSON_ARRAY_APPEND(COALESCE(deleted_by, '[]'), '$', ?) WHERE id = ? AND (target_user IS NULL OR target_user = '' OR target_user = ? OR type = 'broadcast') AND NOT JSON_CONTAINS(COALESCE(deleted_by, '[]'), JSON_QUOTE(?))")->execute([$userName, $id, $userName, $userName]);
        respond(['success' => true]);
    }
    if ($fn === 'delete_all_notifications_for_user') {
        $userName = $authUserName;
        if (!$userName) respond(['success' => false, 'error' => 'missing params'], 400);
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
        if (!$sessionUser || $sessionUser['role'] !== 'admin') respond(['success' => false, 'error' => 'forbidden'], 403);
        $id = $body['id'] ?? null;
        if (!$id) respond(['success' => false, 'error' => 'id required'], 400);
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
        if (!is_array($items) || empty($items)) respond(['error' => 'items required'], 400);
        if (count($items) > 500) respond(['error' => 'Too many items (max 500)'], 400);
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
            respond(['error' => 'Transaction failed'], 500);
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
        if (!$legalEntity) respond(['error' => 'legal_entity required'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        if (!is_array($items)) respond(['error' => 'items must be array'], 400);
        if (empty($items)) respond(['error' => 'items cannot be empty'], 400);
        if (count($items) > 5000) respond(['error' => 'Too many items (max 5000)'], 400);
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

    if ($fn === 'replace_stock_malling') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация по сессии'], 401);
        $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['shelf-life'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
            respond(['error' => 'Недостаточно прав'], 403);
        }
        $items = $body['items'] ?? [];
        if (!is_array($items)) respond(['error' => 'items must be array'], 400);
        if (empty($items)) respond(['error' => 'items cannot be empty'], 400);
        if (count($items) > 5000) respond(['error' => 'Too many items (max 5000)'], 400);
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
            respond(['success' => true, 'count' => count($items)]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("replace_stock_malling error: " . $e->getMessage());
            respond(['error' => 'Transaction failed'], 500);
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
        if (!$orderId) respond(['error' => 'order_id required'], 400);
        // Проверяем доступ к юрлицу заказа
        $orderCheck = $pdo->prepare("SELECT legal_entity FROM orders WHERE id=?");
        $orderCheck->execute([$orderId]);
        $orderRow = $orderCheck->fetch();
        if (!$orderRow) respond(['error' => 'Заказ не найден'], 404);
        if (!checkLegalEntityAccess($caller, $orderRow['legal_entity'])) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        if (!is_array($items)) respond(['error' => 'items must be array'], 400);
        if (count($items) > 5000) respond(['error' => 'Too many items (max 5000)'], 400);
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

    if ($fn === 'delete_order') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация по сессии'], 401);
        $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['order'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['full']) {
            respond(['error' => 'Недостаточно прав'], 403);
        }
        $orderId = $body['order_id'] ?? '';
        if (!$orderId) respond(['error' => 'order_id required'], 400);
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
            respond(['error' => 'Transaction failed'], 500);
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
        if (!$legalEntity) respond(['error' => 'legal_entity required'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        if (!is_array($items)) respond(['error' => 'items must be array'], 400);
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
            respond(['error' => 'Transaction failed'], 500);
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
        respond(['success' => true, 'updated' => $count]);
    }

    // ─── Админские RPC (только admin) ───
    if ($fn === 'get_admin_stats') {
        $caller = getSessionUser($pdo);
        if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'forbidden'], 403);
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
        if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'forbidden'], 403);
        $s = $pdo->query("SELECT id, user_name, CONCAT(LEFT(token, 8), '…') AS token_prefix, created_at, expires_at, ip_address, user_agent FROM user_sessions WHERE expires_at > NOW() ORDER BY created_at DESC");
        respond($s->fetchAll());
    }

    if ($fn === 'terminate_session') {
        $caller = getSessionUser($pdo);
        if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'forbidden'], 403);
        $sessionId = $body['session_id'] ?? '';
        if (!$sessionId) respond(['success' => false, 'error' => 'session_id required'], 400);
        $pdo->prepare("DELETE FROM user_sessions WHERE id = ?")->execute([$sessionId]);
        respond(['success' => true]);
    }

    if ($fn === 'clear_error_logs') {
        $caller = getSessionUser($pdo);
        if (!$caller || $caller['role'] !== 'admin') respond(['success' => false, 'error' => 'forbidden'], 403);
        $olderThan = $body['older_than_days'] ?? null;
        try {
            if ($olderThan && intval($olderThan) > 0) {
                $pdo->prepare("DELETE FROM error_logs WHERE created_at < NOW() - INTERVAL ? DAY")->execute([intval($olderThan)]);
            } else {
                $pdo->exec("TRUNCATE TABLE error_logs");
            }
            respond(['success' => true]);
        } catch (PDOException $e) {
            respond(['success' => false, 'error' => 'Failed to clear logs'], 500);
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
        if (!$restaurantId) respond(['error' => 'restaurant_id required'], 400);
        if (!is_array($items)) respond(['error' => 'items must be array'], 400);
        if (count($items) > 500) respond(['error' => 'Too many items (max 500)'], 400);
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
            respond(['error' => 'Transaction failed'], 500);
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
        $imported = 0;
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO product_prices (sku, supplier, legal_entity, price, unit_type, agreement_id, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE price=VALUES(price), unit_type=VALUES(unit_type), agreement_id=VALUES(agreement_id), updated_by=VALUES(updated_by), updated_at=NOW()");
            foreach ($prices as $p) {
                $sku = trim($p['sku'] ?? '');
                $price = floatval($p['price'] ?? 0);
                $unitType = ($p['unit_type'] ?? 'piece') === 'box' ? 'box' : 'piece';
                if (!$sku || $price < 0) continue;
                $stmt->execute([$sku, $supplier, $le, $price, $unitType, $agreementId, $caller['name']]);
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
        $s = $pdo->prepare("SELECT * FROM price_agreements WHERE id=?"); $s->execute([$id]); $ag = $s->fetch();
        if (!$ag) respond(['error' => 'Протокол не найден'], 404);
        if (!checkLegalEntityAccess($caller, $ag['legal_entity'])) respond(['error' => 'Нет доступа к юр. лицу'], 403);
        if ($ag['status'] === 'active') respond(['error' => 'Протокол уже согласован'], 400);
        // Архивируем предыдущие активные протоколы для этого поставщика+юрлица
        $pdo->prepare("UPDATE price_agreements SET status='archived' WHERE supplier=? AND legal_entity=? AND status='active'")->execute([$ag['supplier'], $ag['legal_entity']]);
        // Активируем текущий
        $pdo->prepare("UPDATE price_agreements SET status='active', approved_by=?, approved_at=NOW() WHERE id=?")->execute([$caller['name'], $id]);
        respond(['success' => true]);
    }

    if ($fn === 'get_current_prices') {
        $caller = getSessionUser($pdo);
        if (!$caller && !checkApiKey($pdo)) respond(['error' => 'Unauthorized'], 401);
        $le = $body['legal_entity'] ?? ($_GET['legal_entity'] ?? '');
        if (strpos($le, 'eq.') === 0) $le = substr($le, 3);
        if (!$le) respond(['error' => 'Не указано юр. лицо'], 400);
        if ($caller && !checkLegalEntityAccess($caller, $le)) respond(['error' => 'Нет доступа к юр. лицу'], 403);
        $supplier = $body['supplier'] ?? ($_GET['supplier'] ?? '');
        $sql = "SELECT pp.sku, pp.price, pp.unit_type, pp.supplier, pp.agreement_id, pp.updated_at FROM product_prices pp WHERE pp.legal_entity=?";
        $params = [$le];
        if ($supplier) { $sql .= " AND pp.supplier=?"; $params[] = $supplier; }
        $s = $pdo->prepare($sql); $s->execute($params);
        respond($s->fetchAll());
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

    respond(['error'=>'Not found'], 404);
}

// ═══ API KEY ═══
if (!checkAuth($pdo)) { respond(['error'=>'Unauthorized'], 401); }

// ═══ REST ═══
$allowed = ['products','suppliers','orders','order_items','plans','item_order','settings','audit_log','stock_1c','search_logs','cards','users','analysis_data','notifications','restaurants','delivery_schedule','error_logs','changelog','product_adu','stock_malling','deficit_sessions','deficit_results','deficit_tokens','deficit_restaurant_stock','stock_collections','stock_collection_products','stock_collection_data','stock_collection_tokens','price_agreements','product_prices'];
// Защита: только чтение через REST, запись — через RPC
$readOnly = ['search_logs', 'users', 'error_logs', 'api_keys'];
// settings — только чтение и обновление (без delete/insert для защиты системных ключей)
$noInsertDelete = ['settings'];
// audit_log — только чтение и вставка (без update/delete для защиты целостности)
$appendOnly = ['audit_log'];
if (!in_array($endpoint, $allowed)) { respond(['error'=>'Not found'], 404); }
$table = $endpoint;

// RBAC: модульная проверка прав
$sessionUser = getSessionUser($pdo);
if ($method !== 'GET' && !$sessionUser) {
    respond(['error' => 'Требуется авторизация по сессии для операций записи'], 401);
}
if ($sessionUser) {
    $userRole = $sessionUser['role'] ?? 'user';
    if ($userRole !== 'admin') {
        $module = $TABLE_TO_MODULE[$table] ?? null;
        if ($module) {
            $perms = resolvePermissions($userRole, $sessionUser['permissions'] ?? null, $ROLE_TEMPLATES);
            $level = $ACCESS_LEVELS[$perms[$module] ?? 'none'] ?? 0;
            $requiredLevel = ($method === 'GET') ? $ACCESS_LEVELS['view'] : (($method === 'DELETE') ? $ACCESS_LEVELS['full'] : $ACCESS_LEVELS['edit']);
            if ($level < $requiredLevel) {
                respond(['error' => 'Недостаточно прав'], 403);
            }
        }
    }
}

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

// Проверка доступа к юр. лицу в REST-запросах
if ($sessionUser && in_array($table, $ENTITY_TABLES)) {
    $userRole = $sessionUser['role'] ?? 'user';
    $leFilt = $_GET['legal_entity'] ?? null;
    if ($leFilt) {
        // Извлекаем значение из фильтра eq.XXX
        $leVal = (strpos($leFilt, 'eq.') === 0) ? substr($leFilt, 3) : $leFilt;
        $leVal = urldecode($leVal);
        if (!checkLegalEntityAccess($sessionUser, $leVal)) {
            respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        }
    } elseif ($method === 'GET' && $userRole !== 'admin') {
        // Без фильтра по юрлицу неадмины не могут читать таблицы с юрлицами
        respond(['error' => 'Требуется фильтр legal_entity'], 400);
    }
    // Для операций записи проверяем legal_entity в теле запроса
    if ($method !== 'GET' && !empty($body)) {
        $bodyLE = $body['legal_entity'] ?? null;
        if ($bodyLE && !checkLegalEntityAccess($sessionUser, $bodyLE)) {
            respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        }
    }
}

// Белый список полей, доступных для фильтрации через GET-параметры
$filterWhitelist = [
    'products'    => ['id','sku','name','supplier','legal_entity','is_active','analog_group','category'],
    'suppliers'   => ['id','short_name','legal_entity','is_active','dlt','doc'],
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
    'error_logs'    => ['id','level','source','user_name','created_at'],
    'changelog'     => ['id','version','created_at'],
    'product_adu'   => ['id','sku','legal_entity'],
    'stock_malling' => ['id','customer','warehouse','product_name','expiry_date','expiry_status'],
    'search_logs'   => ['id','user_name','created_at'],
    'users'         => ['id','name','role'],
    'deficit_sessions'  => ['id','legal_entity','created_by','created_at'],
    'deficit_results'   => ['id','session_id','restaurant_number'],
    'deficit_tokens'    => ['id','legal_entity','created_by'],
    'deficit_restaurant_stock' => ['id','token_id','restaurant_number'],
    'stock_collections'       => ['id','legal_entity','status'],
    'stock_collection_products' => ['id','collection_id'],
    'stock_collection_data'   => ['id','collection_id','product_id','restaurant_number'],
    'stock_collection_tokens' => ['id','collection_id'],
    'price_agreements' => ['id','number','supplier','legal_entity','status','valid_from','valid_to','created_by','approved_by','created_at'],
    'product_prices'   => ['id','sku','supplier','legal_entity','agreement_id','updated_by','updated_at'],
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
        // Проверка доступа к юрлицу при запросе по ID
        if ($row && $sessionUser && $sessionUser['role'] !== 'admin' && in_array($table, $ENTITY_TABLES) && isset($row['legal_entity'])) {
            if (!checkLegalEntityAccess($sessionUser, $row['legal_entity'])) respond(['error' => 'Нет доступа'], 403);
        }
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
    $limit = isset($_GET['limit']) ? max(1, min(intval($_GET['limit']), 5000)) : 1000;
    $sql .= " LIMIT " . $limit;
    if (isset($_GET['offset'])) {
        $sql .= " OFFSET " . max(0, intval($_GET['offset']));
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
        if (!isset($rec['id']) && !in_array($table, ['audit_log','search_logs','api_keys','settings','notifications','delivery_schedule','restaurants','error_logs','changelog'])) $rec['id'] = uuid();
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
        $deletedBy = $sessionUser ? $sessionUser['name'] : 'unknown';
        try {
            foreach ($deletedIds as $did) {
                $pdo->prepare("INSERT INTO `audit_log` (`action`, `entity_type`, `entity_id`, `user_name`, `details`, `created_at`) VALUES (?, ?, ?, ?, '{}', NOW())")
                    ->execute([$table . '_deleted', $table, $did, $deletedBy]);
            }
        } catch (PDOException $e) { /* не блокируем ответ */ }
    }
    respond(['deleted' => $s->rowCount()]);
}

respond(['error'=>'Method not allowed'], 405);