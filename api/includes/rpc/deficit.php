<?php
/**
 * RPC: дефицит товаров (deficit_*).
 *
 * 3 публичных + 1 приватный RPC. Файл подключается дважды —
 * один раз в публичной зоне rpc.php (под 3 public-функции),
 * второй раз — в приватной (под deficit_create_token).
 * Защита у каждой функции своя: public смотрят на токен,
 * private — на checkLegalEntityAccess. Дубль require безопасен,
 * потому что в каждой зоне сработает только один из 4 if-блоков.
 *
 * Подключается из api/includes/rpc.php.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName, $clientIp.
 */

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
