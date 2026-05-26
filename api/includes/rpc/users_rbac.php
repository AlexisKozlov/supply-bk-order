<?php
/**
 * RPC: RBAC, пользовательские настройки и управление пользователями (admin).
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUserName, $clientIp,
 * $ROLE_TEMPLATES, $ACCESS_LEVELS.
 */

if ($fn === 'get_rbac_config') {
    respond([
        'modules' => array_keys($ROLE_TEMPLATES['admin']),
        'role_templates' => $ROLE_TEMPLATES,
        'access_levels' => $ACCESS_LEVELS,
    ]);
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
