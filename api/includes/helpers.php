<?php
/**
 * Вспомогательные функции: ответы, авторизация, фильтры, RBAC.
 * Подключается из index.php. Все переменные доступны через global.
 */
require_once __DIR__ . '/legal_entities.php';

// ═══ Telegram уведомления ═══
function sendTelegramMessage($botToken, $chatId, $text, $parseMode = 'HTML') {
    if (!$botToken || !$chatId) return false;
    $payload = json_encode([
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => $parseMode,
        'disable_notification' => false,
    ]);
    $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $result = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200;
}

function sendTelegramBulk($botToken, $chatIds, $text, $parseMode = 'HTML') {
    if (!$botToken || empty($chatIds)) return 0;
    $mh = curl_multi_init();
    $handles = [];
    foreach ($chatIds as $chatId) {
        $payload = json_encode([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
            'disable_notification' => false,
        ]);
        $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    // Выполняем все запросы параллельно
    $running = null;
    do { curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh); } while ($running > 0);
    $sent = 0;
    foreach ($handles as $ch) {
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) $sent++;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $sent;
}

function notifyTelegramDataUpdate($pdo, $type, $userName, $legalEntity = '', $count = 0) {
    $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
    if (!$botToken) return;

    $tz = new DateTimeZone('Europe/Minsk');
    $time = (new DateTime('now', $tz))->format('H:i');

    $typeNames = [
        'analysis' => '📊 Анализ запасов',
        'shelf_life' => '⏰ Сроки годности',
    ];
    $label = $typeNames[$type] ?? $type;
    $leInfo = $legalEntity ? " ({$legalEntity})" : '';

    $text = "<b>{$label}</b> — данные обновлены{$leInfo}\n";
    $text .= "👤 {$userName} в {$time}\n";
    $text .= "📝 Загружено записей: {$count}";

    $s = $pdo->query("SELECT telegram_chat_id FROM users WHERE telegram_chat_id IS NOT NULL AND telegram_chat_id != ''");
    $chatIds = $s->fetchAll(PDO::FETCH_COLUMN);
    sendTelegramBulk($botToken, $chatIds, $text);
}

// ═══ ROLE TEMPLATES & PERMISSIONS ═══
$ROLE_TEMPLATES = [
    'admin' => ['order'=>'full','planning'=>'full','history'=>'full','plan-fact'=>'full','database'=>'full','delivery-schedule'=>'full','analytics'=>'full','calendar'=>'full','analysis'=>'full','shelf-life'=>'full','pricing'=>'full','tenders'=>'full'],
    'user'  => ['order'=>'edit','planning'=>'edit','history'=>'edit','plan-fact'=>'edit','database'=>'edit','delivery-schedule'=>'edit','analytics'=>'view','calendar'=>'view','analysis'=>'edit','shelf-life'=>'edit','pricing'=>'edit','tenders'=>'edit'],
    'viewer' => ['order'=>'view','planning'=>'view','history'=>'view','plan-fact'=>'view','database'=>'view','delivery-schedule'=>'view','analytics'=>'view','calendar'=>'view','analysis'=>'view','shelf-life'=>'view','pricing'=>'view','tenders'=>'view'],
];
$ACCESS_LEVELS = ['none'=>0,'view'=>1,'edit'=>2,'full'=>3];
$TABLE_TO_MODULE = [
    'orders'=>'order','order_items'=>'order',
    'plans'=>'planning',
    'products'=>'database','suppliers'=>'database','restaurants'=>'database','cards'=>'database',
    'delivery_schedule'=>'delivery-schedule',
    'analysis_data'=>'analysis','stock_1c'=>'analysis','restaurant_sales'=>'analysis',
    'stock_malling'=>'shelf-life',
    'audit_log'=>'history','notifications'=>'history',
    'settings'=>'database','item_order'=>'order',
    'deficit_sessions'=>'order','deficit_results'=>'order','deficit_tokens'=>'order','deficit_restaurant_stock'=>'order',
    'stock_collections'=>'order','stock_collection_products'=>'order','stock_collection_data'=>'order','stock_collection_tokens'=>'order',
    'price_agreements'=>'pricing','product_prices'=>'pricing','price_history'=>'pricing',
    'tenders'=>'tenders','tender_items'=>'tenders','tender_offers'=>'tenders','tender_offer_prices'=>'tenders','tender_files'=>'tenders',
];

// Таблицы, в которых есть поле legal_entity и нужна проверка доступа
$ENTITY_TABLES = ['orders','order_items','plans','item_order','analysis_data','stock_1c','product_adu','notifications','deficit_sessions','deficit_tokens','stock_collections','price_agreements','product_prices','price_history','tenders','bug_reports'];

function resolvePermissions($role, $permissionsJson, $templates) {
    $base = $templates[$role] ?? $templates['user'];
    if ($role === 'admin') return $templates['admin'];
    if (!$permissionsJson) return $base;
    $overrides = is_string($permissionsJson) ? json_decode($permissionsJson, true) : $permissionsJson;
    if (!is_array($overrides)) return $base;
    return array_merge($base, array_intersect_key($overrides, $base));
}

function checkLegalEntityAccess($sessionUser, $legalEntity) {
    if (!$sessionUser) return true;
    if (!$legalEntity) return false;
    if (($sessionUser['role'] ?? '') === 'admin') return true;
    $userEntities = $sessionUser['legal_entities'] ?? '';
    if (is_string($userEntities)) {
        $userEntities = json_decode($userEntities, true);
    }
    if (!is_array($userEntities) || empty($userEntities)) return false;
    return in_array($legalEntity, $userEntities);
}

// ═══ Утилиты ═══
function respond($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION); exit; }
function cleanNumeric($rows) {
    $decimal = ['qty_per_box'];
    foreach ($rows as &$r) { foreach ($decimal as $col) { if (isset($r[$col])) $r[$col] = +$r[$col]; } }
    return $rows;
}
function uuid() { return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', random_int(0,0xffff),random_int(0,0xffff),random_int(0,0xffff),random_int(0,0x0fff)|0x4000,random_int(0,0x3fff)|0x8000,random_int(0,0xffff),random_int(0,0xffff),random_int(0,0xffff)); }

function verifyAndMigratePassword($pdo, $userName, $inputPassword, $storedHash) {
    if (password_verify($inputPassword, $storedHash)) return true;
    if (hash_equals($storedHash, $inputPassword)) {
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

function checkAuth($pdo) {
    if (getSessionUser($pdo)) return true;
    return checkApiKey($pdo);
}

$_sessionUserCache = ['done' => false, 'result' => null];
function getSessionUser($pdo) {
    global $_sessionUserCache;
    if ($_sessionUserCache['done']) return $_sessionUserCache['result'];
    $_sessionUserCache['done'] = true;

    $token = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
    // $_GET['token'] разрешён только для скачивания файлов
    if (!$token && isset($_GET['token'])) {
        global $endpoint;
        if (($endpoint ?? '') === 'uploads') {
            $token = $_GET['token'];
        }
    }
    if (!$token) { $_sessionUserCache['result'] = null; return null; }
    if (mt_rand(1, 100) === 1) {
        try { $pdo->exec("DELETE FROM user_sessions WHERE expires_at < NOW()"); } catch (PDOException $e) { /* не критично */ }
    }
    $s = $pdo->prepare("SELECT u.name, u.role, u.display_role, u.legal_entities, u.permissions, u.created_at, u.telegram_chat_id FROM user_sessions s JOIN users u ON u.name = s.user_name WHERE s.token = ? AND s.expires_at > NOW()");
    $s->execute([$token]);
    $row = $s->fetch();
    if (!$row) { $_sessionUserCache['result'] = null; return null; }
    static $sessionUpdated = false;
    if (!$sessionUpdated) {
        $s2 = $pdo->prepare("SELECT expires_at FROM user_sessions WHERE token = ?");
        $s2->execute([$token]);
        $exp = $s2->fetchColumn();
        if ($exp && strtotime($exp) - time() < 6 * 86400) {
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
    $pdo->prepare("DELETE FROM user_sessions WHERE user_name = ? AND id NOT IN (SELECT id FROM (SELECT id FROM user_sessions WHERE user_name = ? ORDER BY created_at DESC LIMIT 4) AS t)")->execute([$userName, $userName]);
    $pdo->prepare("INSERT INTO user_sessions (user_name, token, expires_at, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)")->execute([$userName, $token, $expires, $ip, $ua]);
    return $token;
}

function checkRateLimit($pdo, $ip, $maxAttempts = 10, $windowMinutes = 10) {
    $pdo->prepare("DELETE FROM failed_login_attempts WHERE attempted_at < NOW() - INTERVAL ? MINUTE")->execute([$windowMinutes]);
    $s = $pdo->prepare("SELECT COUNT(*) as cnt FROM failed_login_attempts WHERE ip_address = ? AND attempted_at > NOW() - INTERVAL ? MINUTE");
    $s->execute([$ip, $windowMinutes]);
    $count = $s->fetch()['cnt'] ?? 0;
    return $count < $maxAttempts;
}

function recordFailedLogin($pdo, $ip, $userName = '') {
    $pdo->prepare("INSERT INTO failed_login_attempts (ip_address, user_name, attempted_at) VALUES (?, ?, NOW())")->execute([$ip, $userName]);
}

function parseFilter($key, $val, &$where, &$params, $pdo, $table) {
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
        $arr = preg_split('/(?<!\\\\),/', $inv);
        $arr = array_map(fn($x) => str_replace('\\,', ',', $x), $arr);
        $ph = implode(',', array_fill(0, count($arr), '?'));
        $where[] = "`$key` IN($ph)";
        $params = array_merge($params, $arr);
    }
    elseif (strpos($val,'ilike.')===0) {
        $raw = substr($val, 6);
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
            $orClauses[] = "`{$m[1]}` LIKE ? ESCAPE '\\\\'";
            $likeVal = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $m[2]);
            $params[] = str_replace(['%25','*'], '%', $likeVal);
        }
    }
    if ($orClauses) $where[] = '(' . implode(' OR ', $orClauses) . ')';
}
