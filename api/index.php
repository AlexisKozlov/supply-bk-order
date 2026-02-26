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
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
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
    echo json_encode(['error' => 'DB: ' . $e->getMessage()]);
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
function uuid() { return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0x0fff)|0x4000,mt_rand(0,0x3fff)|0x8000,mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff)); }

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
    else { $where[]="`$key`=?"; $params[]=$val; }
}

function parseOr($orStr, &$where, &$params) {
    $parts = preg_split('/,(?=[a-zA-Z_])/', $orStr);
    $orClauses = [];
    foreach ($parts as $part) {
        if (preg_match('/^(\w+)\.(eq|neq|gt|gte|lt|lte)\.(.+)$/', $part, $m)) {
            $ops = ['eq'=>'=','neq'=>'!=','gt'=>'>','gte'=>'>=','lt'=>'<','lte'=>'<='];
            $orClauses[] = "`{$m[1]}` {$ops[$m[2]]} ?";
            $params[] = $m[3];
        } elseif (preg_match('/^(\w+)\.ilike\.(.+)$/', $part, $m)) {
            $orClauses[] = "`{$m[1]}` LIKE ?";
            $params[] = str_replace(['%25','*'], '%', $m[2]);
        }
    }
    if ($orClauses) $where[] = '(' . implode(' OR ', $orClauses) . ')';
}

// Debug endpoint disabled in production

// ═══ SEARCH ═══
if ($endpoint === 'search_products') {
    $q = $_GET['q'] ?? '';
    $le = $_GET['legal_entity'] ?? '';
    $supplier = $_GET['supplier'] ?? '';
    $limit = intval($_GET['limit'] ?? 10);
    
    if (strlen($q) < 2) respond([]);
    
    $where = [];
    $params = [];
    
    // Поиск по SKU или имени
    $where[] = "(`sku` LIKE ? OR `name` LIKE ?)";
    $params[] = "%{$q}%";
    $params[] = "%{$q}%";
    
    // Фильтр по юр. лицу
    if ($le === 'Пицца Стар') {
        $where[] = "`legal_entity` = ?";
        $params[] = 'Пицца Стар';
    } elseif ($le) {
        $where[] = "`legal_entity` IN (?, ?)";
        $params[] = 'Бургер БК';
        $params[] = 'Воглия Матта';
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

// ═══ RPC ═══
if ($endpoint === 'rpc') {
    $fn = $subpoint ?? '';
    if ($fn === 'get_user_list') {
        $s = $pdo->query("SELECT name FROM users ORDER BY name");
        respond($s->fetchAll());
    }
    if ($fn === 'check_user_password') {
        $name = $body['user_name'] ?? ''; $pass = $body['user_password'] ?? '';
        $s = $pdo->prepare("SELECT id,name,password,role,display_role,legal_entities FROM users WHERE name=?");
        $s->execute([$name]); $u = $s->fetch();
        if (!$u) respond(['success'=>false,'error'=>'user_not_found']);
        if (!verifyAndMigratePassword($pdo, $name, $pass, $u['password'])) respond(['success'=>false,'error'=>'wrong_password']);
        $s2 = $pdo->prepare("SELECT api_key FROM api_keys WHERE is_active='true' LIMIT 1"); $s2->execute();
        $le = $u['legal_entities'];
        $le = ($le && is_string($le)) ? (json_decode($le, true) ?? []) : [];
        $displayRole = $u['display_role'] ?? null;
        $mm = $pdo->prepare("SELECT `key`,`value` FROM settings WHERE `key` IN ('maintenance_mode','maintenance_message')"); $mm->execute();
        $mmRows = $mm->fetchAll(); $maintenanceVal = 'false'; $maintenanceMsg = '';
        foreach ($mmRows as $mr) { if ($mr['key'] === 'maintenance_mode') $maintenanceVal = $mr['value']; if ($mr['key'] === 'maintenance_message') $maintenanceMsg = $mr['value']; }
        respond(['success'=>true,'user'=>['name'=>$u['name'],'role'=>$u['role']??'user','display_role'=>$displayRole,'legal_entities'=>$le],'api_key'=>$s2->fetchColumn(),'maintenance_mode'=>$maintenanceVal==='true','maintenance_message'=>$maintenanceMsg ?: null]);
    }
    if ($fn === 'check_legacy_password') {
        $pwd = $body['pwd'] ?? '';
        $s = $pdo->prepare("SELECT value FROM settings WHERE `key`='order_calculator_password'"); $s->execute();
        $stored = $s->fetchColumn();
        if ($stored) {
            $ok = password_verify($pwd, $stored) || $stored === $pwd;
            if ($ok) {
                // Ленивая миграция legacy-пароля
                if ($stored === $pwd) {
                    $hash = password_hash($pwd, PASSWORD_BCRYPT);
                    $pdo->prepare("UPDATE settings SET value=? WHERE `key`='order_calculator_password'")->execute([$hash]);
                }
                $s2 = $pdo->prepare("SELECT api_key FROM api_keys WHERE is_active='true' LIMIT 1"); $s2->execute();
                respond(['success'=>true,'api_key'=>$s2->fetchColumn()]);
            }
        }
        respond(['success'=>false]);
    }
    if ($fn === 'change_user_password') {
        $name = $body['user_name'] ?? '';
        $oldPwd = $body['old_password'] ?? '';
        $newPwd = $body['new_password'] ?? '';
        $s = $pdo->prepare("SELECT password FROM users WHERE name=?"); $s->execute([$name]); $u = $s->fetch();
        if (!$u) respond(['success'=>false,'error'=>'user_not_found']);
        if (!verifyAndMigratePassword($pdo, $name, $oldPwd, $u['password'])) respond(['success'=>false,'error'=>'wrong_password']);
        $pdo->prepare("UPDATE users SET password=? WHERE name=?")->execute([password_hash($newPwd, PASSWORD_BCRYPT), $name]);
        respond(['success'=>true]);
    }
    if ($fn === 'mark_notifications_read') {
        $ids = $body['ids'] ?? [];
        $user = $body['user_name'] ?? '';
        if (!$user || empty($ids)) respond(['success' => false, 'error' => 'missing params']);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$user], $ids);
        $pdo->prepare("UPDATE notifications SET read_by = JSON_ARRAY_APPEND(COALESCE(read_by, '[]'), '$', ?) WHERE id IN ($ph) AND NOT JSON_CONTAINS(COALESCE(read_by, '[]'), JSON_QUOTE(?))")->execute(array_merge([$user], $ids, [$user]));
        respond(['success' => true]);
    }
    if ($fn === 'heartbeat') {
        $userName = $body['user_name'] ?? '';
        $page = $body['page'] ?? '';
        if ($userName) {
            $s = $pdo->prepare("INSERT INTO user_presence (user_name, page, last_seen) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE page=VALUES(page), last_seen=NOW()");
            $s->execute([$userName, $page]);
        }
        respond(['success' => true]);
    }
    if ($fn === 'get_online_users') {
        $s = $pdo->query("SELECT user_name, page, last_seen FROM user_presence WHERE last_seen > NOW() - INTERVAL 2 MINUTE ORDER BY last_seen DESC");
        respond($s->fetchAll());
    }
    if ($fn === 'check_maintenance') {
        $s = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ('maintenance_mode','maintenance_message')"); $s->execute();
        $rows = $s->fetchAll(); $mm = 'false'; $msg = '';
        foreach ($rows as $r) { if ($r['key'] === 'maintenance_mode') $mm = $r['value']; if ($r['key'] === 'maintenance_message') $msg = $r['value']; }
        respond(['maintenance_mode' => $mm === 'true', 'maintenance_message' => $msg ?: null]);
    }
    respond(['error'=>'Unknown RPC: '.$fn], 404);
}

// ═══ API KEY ═══
if (!checkApiKey($pdo)) { respond(['error'=>'Invalid API key'], 401); }

// ═══ REST ═══
$allowed = ['products','suppliers','orders','order_items','plans','item_order','settings','audit_log','stock_1c','search_logs','cards','users','analysis_data','notifications'];
// Защита: users — только чтение (без password), запись только через RPC
$readOnly = ['audit_log','search_logs'];
if (!in_array($endpoint, $allowed)) { respond(['error'=>'Not found: '.$endpoint], 404); }
$table = $endpoint;

if ($method === 'GET') {
    $where = []; $params = [];
    foreach ($_GET as $k => $v) {
        if (in_array($k, ['select','order','limit','offset','or'])) continue;
        parseFilter($k, $v, $where, $params, $pdo, $table);
    }
    if (isset($_GET['or'])) parseOr($_GET['or'], $where, $params);

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
    // Валидация основных колонок SELECT
    if ($sel !== '*') {
        $selCols = array_map('trim', explode(',', $sel));
        foreach ($selCols as $sc) { if (!preg_match('/^[a-zA-Z_]\w*$/', $sc)) { $sel = '*'; break; } }
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
    if (isset($_GET['limit'])) $sql .= " LIMIT " . intval($_GET['limit']);
    if (isset($_GET['offset'])) $sql .= " OFFSET " . intval($_GET['offset']);

    $s = $pdo->prepare($sql); $s->execute($params); $data = $s->fetchAll();

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
    if (empty($body)) respond(['error' => 'Empty body'], 400);
    $recs = isset($body[0]) ? $body : [$body]; $ins = [];
    foreach ($recs as $rec) {
        if (!isset($rec['id']) && !in_array($table, ['audit_log','search_logs','api_keys','settings'])) $rec['id'] = uuid();
        foreach (['items','details','legal_entities','sku_order','analogs','data'] as $jc) { if (isset($rec[$jc]) && is_array($rec[$jc])) $rec[$jc] = json_encode($rec[$jc], JSON_UNESCAPED_UNICODE); }
        // Валидация имён колонок
        foreach (array_keys($rec) as $col) { if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) respond(['error' => 'Invalid column name: '.$col], 400); }
        $cols = array_keys($rec); $ph = implode(',', array_fill(0, count($cols), '?')); $cn = implode(',', array_map(fn($c) => "`$c`", $cols));
        try {
            $s = $pdo->prepare("INSERT INTO `$table` ($cn) VALUES ($ph)"); $s->execute(array_values($rec));
        } catch (PDOException $e) {
            respond(['error' => $e->getMessage(), 'table' => $table, 'columns' => $cols], 500);
        }
        $lid = $rec['id'] ?? $pdo->lastInsertId();
        $s2 = $pdo->prepare("SELECT * FROM `$table` WHERE id=?"); $s2->execute([$lid]); $r = $s2->fetch(); if ($r) $ins[] = $r;
    }
    respond(count($ins) === 1 ? $ins[0] : $ins, 201);
}

if ($method === 'PATCH' || $method === 'PUT') {
    $where = []; $params = [];
    foreach ($_GET as $k => $v) { if (in_array($k, ['select','order','limit','offset','or'])) continue; parseFilter($k, $v, $where, $params, $pdo, $table); }
    if (isset($_GET['or'])) parseOr($_GET['or'], $where, $params);
    if ($subpoint) { $where = ["`id`=?"]; $params = [$subpoint]; }
    if (!$where) respond(['error'=>'No filters'], 400);
    if (empty($body)) respond(['error' => 'Empty body'], 400);
    foreach (['items','details','legal_entities','sku_order','analogs','data'] as $jc) { if (isset($body[$jc]) && is_array($body[$jc])) $body[$jc] = json_encode($body[$jc], JSON_UNESCAPED_UNICODE); }
    // Валидация имён колонок
    foreach (array_keys($body) as $col) { if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) respond(['error' => 'Invalid column name: '.$col], 400); }
    $set = []; $sp = [];
    foreach ($body as $c => $v) { $set[] = "`$c`=?"; $sp[] = $v; }
    $all = array_merge($sp, $params);
    try {
        $s = $pdo->prepare("UPDATE `$table` SET " . implode(',', $set) . " WHERE " . implode(' AND ', $where)); $s->execute($all);
    } catch (PDOException $e) {
        respond(['error' => $e->getMessage(), 'table' => $table], 500);
    }
    $s2 = $pdo->prepare("SELECT * FROM `$table` WHERE " . implode(' AND ', $where)); $s2->execute($params);
    respond($s2->fetchAll());
}

if ($method === 'DELETE') {
    $where = []; $params = [];
    if ($subpoint) { $where[] = "`id`=?"; $params[] = $subpoint; }
    else { foreach ($_GET as $k => $v) { if (in_array($k, ['select','order','limit','offset','or'])) continue; parseFilter($k, $v, $where, $params, $pdo, $table); } if (isset($_GET['or'])) parseOr($_GET['or'], $where, $params); }
    if (!$where) respond(['error'=>'No filters'], 400);
    $s = $pdo->prepare("DELETE FROM `$table` WHERE " . implode(' AND ', $where)); $s->execute($params);
    respond(['deleted' => $s->rowCount()]);
}

respond(['error'=>'Method not allowed'], 405);