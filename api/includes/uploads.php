<?php
/**
 * Обработчики загрузки и скачивания файлов.
 * Подключается из index.php. Используются глобальные переменные:
 *   $pdo, $endpoint, $subpoint, $parts, $method, $ROLE_TEMPLATES, $ACCESS_LEVELS
 */

// Санитайзер имени файла для заголовка Content-Disposition: убирает кавычки и
// управляющие символы (CR/LF), чтобы исключить инъекцию HTTP-заголовков.
function sanitizeHeaderFilename($name) {
    $name = preg_replace('/[\x00-\x1F\x7F"\\\\]/', '', (string)$name);
    return mb_substr($name, 0, 255);
}

function canDownloadRestaurantInfoFile($pdo, $fileId) {
    if (checkAuth($pdo)) {
        $su = getSessionUser($pdo);
        if ($su) {
            global $ROLE_TEMPLATES, $ACCESS_LEVELS;
            $p = resolvePermissions($su['role'] ?? 'user', $su['permissions'] ?? null, $ROLE_TEMPLATES);
            if (($ACCESS_LEVELS[$p['restaurant-orders'] ?? 'none'] ?? 0) >= $ACCESS_LEVELS['view']) return true;
        }
    }
    $token = $_SERVER['HTTP_X_RO_TOKEN'] ?? '';
    if (!$token) return false;
    $s = $pdo->prepare("
        SELECT restaurant_number, legal_entity_group, session_active_until
        FROM ro_users
        WHERE session_token = ? AND is_active = 1
        LIMIT 1
    ");
    $s->execute([$token]);
    $rest = $s->fetch();
    if (!$rest) return false;
    if (!empty($rest['session_active_until']) && strtotime($rest['session_active_until']) < time()) return false;
    $group = $rest['legal_entity_group'] ?: (((int)$rest['restaurant_number'] >= 1000) ? 'PS' : 'BK_VM');
    $q = $pdo->prepare("
        SELECT p.id
        FROM ro_cabinet_post_files f
        JOIN ro_cabinet_posts p ON p.id = f.post_id
        WHERE f.id = ?
          AND p.is_published = 1
          AND p.deleted_at IS NULL
          AND (
            p.target_mode = 'all'
            OR (p.target_mode = 'group' AND p.target_group = ?)
            OR EXISTS (
              SELECT 1 FROM ro_cabinet_post_restaurants rcp
              WHERE rcp.post_id = p.id
                AND rcp.restaurant_number = ?
                AND rcp.legal_entity_group = ?
            )
          )
        LIMIT 1
    ");
    $q->execute([(int)$fileId, $group, (int)$rest['restaurant_number'], $group]);
    return (bool)$q->fetchColumn();
}

// ═══ DOWNLOAD RESTAURANT INFO FILE ═══
if ($endpoint === 'uploads' && ($parts[1] ?? '') === 'restaurant_info' && isset($parts[2])) {
    $filename = basename($parts[2]);
    $filepath = __DIR__ . '/../uploads/restaurant_info/' . $filename;
    if (!file_exists($filepath)) { http_response_code(404); echo json_encode(['error' => 'Файл не найден']); exit; }
    $safeName = str_replace(['%', '_'], ['\\%', '\\_'], $filename);
    $s = $pdo->prepare("
        SELECT id, file_name, mime_type
        FROM ro_cabinet_post_files
        WHERE file_path LIKE ? ESCAPE '\\\\'
        LIMIT 1
    ");
    $s->execute(['%' . $safeName]);
    $file = $s->fetch();
    if (!$file) respond(['error' => 'Файл не найден'], 404);
    if (!canDownloadRestaurantInfoFile($pdo, (int)$file['id'])) respond(['error' => 'Нет доступа'], 403);

    $mime = $file['mime_type'] ?: 'application/octet-stream';
    $disposition = isset($_GET['download']) ? 'attachment' : 'inline';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . $disposition . '; filename="' . sanitizeHeaderFilename($file['file_name'] ?: $filename) . '"');
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);
    exit;
}

// ═══ DELETE ACT ═══
if ($endpoint === 'upload' && $subpoint === 'act' && $method === 'DELETE') {
    if (!checkAuth($pdo)) respond(['error' => 'Требуется авторизация'], 401);
    $su = getSessionUser($pdo);
    if ($su && $su['role'] !== 'admin') {
        $p = resolvePermissions($su['role'], $su['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$p['plan-fact'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);
    }
    $orderId = $_GET['order_id'] ?? '';
    if (!$orderId) respond(['error' => 'Не указан ID заказа'], 400);
    $orderChk = $pdo->prepare("SELECT legal_entity, act_file FROM orders WHERE id=?"); $orderChk->execute([$orderId]); $orderRow = $orderChk->fetch();
    if (!$orderRow) respond(['error' => 'Заказ не найден'], 404);
    if ($su && !checkLegalEntityAccess($su, $orderRow['legal_entity'])) respond(['error' => 'Нет доступа к юр. лицу заказа'], 403);
    $old = $orderRow['act_file'];
    if ($old) {
        $filepath = __DIR__ . '/../uploads/acts/' . basename($old);
        if (file_exists($filepath)) unlink($filepath);
        $pdo->prepare("UPDATE orders SET act_file=NULL WHERE id=?")->execute([$orderId]);
    }
    respond(['success' => true]);
}

// ═══ UPLOAD ACT ═══
if ($endpoint === 'upload' && $subpoint === 'act') {
    if ($method !== 'POST') respond(['error' => 'Метод не поддерживается'], 405);
    if (!checkAuth($pdo)) respond(['error' => 'Требуется авторизация'], 401);
    $su = getSessionUser($pdo);
    if ($su && $su['role'] !== 'admin') {
        $p = resolvePermissions($su['role'], $su['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$p['plan-fact'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);
    }

    $orderId = $_POST['order_id'] ?? '';
    if (!$orderId) respond(['error' => 'Не указан ID заказа'], 400);

    $chk = $pdo->prepare("SELECT id, legal_entity FROM orders WHERE id=?"); $chk->execute([$orderId]);
    $orderRow = $chk->fetch();
    if (!$orderRow) respond(['error' => 'Заказ не найден'], 404);
    if ($su && !checkLegalEntityAccess($su, $orderRow['legal_entity'])) respond(['error' => 'Нет доступа к юр. лицу заказа'], 403);

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES['file']['error'] ?? -1;
        respond(['error' => 'Ошибка загрузки файла', 'code' => $code], 400);
    }

    $file = $_FILES['file'];
    $maxSize = 10 * 1024 * 1024;
    if ($file['size'] > $maxSize) respond(['error' => 'Файл слишком большой (макс. 10 МБ)'], 400);

    $allowed = ['application/pdf','image/jpeg','image/png','image/webp','image/heic'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowed)) respond(['error' => 'Недопустимый формат файла. Разрешены: PDF, JPEG, PNG, WebP, HEIC'], 400);

    $ext = match($mime) {
        'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
        'image/webp' => 'webp', 'image/heic' => 'heic', default => 'bin',
    };
    $filename = 'act_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $orderId) . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $uploadDir = __DIR__ . '/../uploads/acts/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
    $dest = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) respond(['error' => 'Ошибка сохранения файла'], 500);

    $s = $pdo->prepare("SELECT act_file FROM orders WHERE id=?"); $s->execute([$orderId]); $old = $s->fetchColumn();
    if ($old && file_exists($uploadDir . basename($old))) unlink($uploadDir . basename($old));

    $path = 'uploads/acts/' . $filename;
    $pdo->prepare("UPDATE orders SET act_file=? WHERE id=?")->execute([$path, $orderId]);
    respond(['success' => true, 'path' => $path]);
}

// ═══ DOWNLOAD ACT ═══
if ($endpoint === 'uploads' && ($parts[1] ?? '') === 'acts' && isset($parts[2])) {
    if (!checkAuth($pdo)) respond(['error' => 'Требуется авторизация'], 401);
    $filename = basename($parts[2]);
    $filepath = __DIR__ . '/../uploads/acts/' . $filename;
    if (!file_exists($filepath)) { http_response_code(404); echo json_encode(['error' => 'Файл не найден']); exit; }
    $caller = getSessionUser($pdo);
    if ($caller) {
        $safeName = str_replace(['%', '_'], ['\\%', '\\_'], $filename);
        $actS = $pdo->prepare("SELECT legal_entity FROM orders WHERE act_file LIKE ? ESCAPE '\\\\'"); $actS->execute(['%' . $safeName]);
        $actRow = $actS->fetch();
        // Файл-сирота (нет записи в orders) — не отдаём, чтобы нельзя было скачать угаданное имя
        if (!$actRow) respond(['error' => 'Файл не найден'], 404);
        if (!checkLegalEntityAccess($caller, $actRow['legal_entity'])) respond(['error' => 'Нет доступа'], 403);
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $filepath);
    finfo_close($finfo);
    $disposition = isset($_GET['download']) ? 'attachment' : 'inline';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . $disposition . '; filename="' . sanitizeHeaderFilename($filename) . '"');
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);
    exit;
}

// ═══ UPLOAD PSC FILE ═══
if ($endpoint === 'upload' && $subpoint === 'psc') {
    if ($method !== 'POST') respond(['error' => 'Метод не поддерживается'], 405);
    if (!checkAuth($pdo)) respond(['error' => 'Требуется авторизация'], 401);
    $su = getSessionUser($pdo);
    if (!$su) respond(['error' => 'Требуется авторизация'], 401);
    $p = resolvePermissions($su['role'], $su['permissions'] ?? null, $ROLE_TEMPLATES);
    if (($ACCESS_LEVELS[$p['pricing'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);

    $agreementId = $_POST['agreement_id'] ?? '';
    if (!$agreementId) respond(['error' => 'Не указан ID соглашения'], 400);

    $chk = $pdo->prepare("SELECT id, legal_entity, legal_entity_group FROM price_agreements WHERE id=?"); $chk->execute([$agreementId]);
    $ag = $chk->fetch();
    if (!$ag) respond(['error' => 'Соглашение не найдено'], 404);
    if (!checkLegalEntityGroupAccess($su, $ag['legal_entity_group'])) respond(['error' => 'Нет доступа к юр. лицу'], 403);

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        respond(['error' => 'Ошибка загрузки файла'], 400);
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
        'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-excel' => 'xls', default => 'bin',
    };
    $filename = 'psc_' . intval($agreementId) . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $uploadDir = __DIR__ . '/../uploads/psc/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
    $dest = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) respond(['error' => 'Ошибка сохранения файла'], 500);

    $s = $pdo->prepare("SELECT file_path FROM price_agreements WHERE id=?"); $s->execute([$agreementId]);
    $old = $s->fetchColumn();
    $oldBase = basename($old);
    if ($old && $oldBase && file_exists(__DIR__ . '/../uploads/psc/' . $oldBase)) unlink(__DIR__ . '/../uploads/psc/' . $oldBase);

    $path = 'uploads/psc/' . $filename;
    $origName = mb_substr($file['name'], 0, 255);
    $pdo->prepare("UPDATE price_agreements SET file_path=?, file_name=? WHERE id=?")->execute([$path, $origName, $agreementId]);
    respond(['success' => true, 'path' => $path, 'file_name' => $origName]);
}

// ═══ DOWNLOAD PSC FILE ═══
if ($endpoint === 'uploads' && ($parts[1] ?? '') === 'psc' && isset($parts[2])) {
    if (!checkAuth($pdo)) respond(['error' => 'Требуется авторизация'], 401);
    $filename = basename($parts[2]);
    $filepath = __DIR__ . '/../uploads/psc/' . $filename;
    if (!file_exists($filepath)) { http_response_code(404); echo json_encode(['error' => 'Файл не найден']); exit; }
    $caller = getSessionUser($pdo);
    if ($caller) {
        $safeName = str_replace(['%', '_'], ['\\%', '\\_'], $filename);
        $fchk = $pdo->prepare("SELECT legal_entity_group FROM price_agreements WHERE file_path LIKE ? ESCAPE '\\\\'");
        $fchk->execute(['%' . $safeName]);
        $flg = $fchk->fetchColumn();
        if ($flg === false) respond(['error' => 'Файл не найден'], 404);
        if ($flg && !checkLegalEntityGroupAccess($caller, $flg)) respond(['error' => 'Нет доступа'], 403);
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $filepath);
    finfo_close($finfo);
    $disposition = isset($_GET['download']) ? 'attachment' : 'inline';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . $disposition . '; filename="' . sanitizeHeaderFilename($filename) . '"');
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);
    exit;
}

// ═══ UPLOAD TENDER KP ═══
if ($endpoint === 'upload' && $subpoint === 'tender-kp') {
    if ($method === 'DELETE') {
        if (!checkAuth($pdo)) respond(['error' => 'Требуется авторизация'], 401);
        $su = getSessionUser($pdo);
        if (!$su) respond(['error' => 'Требуется авторизация'], 401);
        $p = resolvePermissions($su['role'], $su['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$p['tenders'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);
        $fileId = intval($_GET['file_id'] ?? 0);
        if (!$fileId) respond(['error' => 'Не указан ID файла'], 400);
        $frow = $pdo->prepare("SELECT tf.*, t.legal_entity FROM tender_files tf JOIN tenders t ON tf.tender_id=t.id WHERE tf.id=?");
        $frow->execute([$fileId]); $frow = $frow->fetch();
        if (!$frow) respond(['error' => 'Файл не найден'], 404);
        if (!checkLegalEntityAccess($su, $frow['legal_entity'])) respond(['error' => 'Нет доступа'], 403);
        $filepath = __DIR__ . '/../uploads/tenders/' . basename($frow['file_path']);
        if (file_exists($filepath)) unlink($filepath);
        $pdo->prepare("DELETE FROM tender_files WHERE id=?")->execute([$fileId]);
        respond(['success' => true]);
    }
    if ($method !== 'POST') respond(['error' => 'Метод не поддерживается'], 405);
    if (!checkAuth($pdo)) respond(['error' => 'Требуется авторизация'], 401);
    $su = getSessionUser($pdo);
    if (!$su) respond(['error' => 'Требуется авторизация'], 401);
    $p = resolvePermissions($su['role'], $su['permissions'] ?? null, $ROLE_TEMPLATES);
    if (($ACCESS_LEVELS[$p['tenders'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);

    $tenderId = intval($_POST['tender_id'] ?? 0);
    $supplier = trim($_POST['supplier'] ?? '');
    if (!$tenderId) respond(['error' => 'Не указан ID тендера'], 400);
    if (!$supplier) respond(['error' => 'Не указан поставщик'], 400);

    $chk = $pdo->prepare("SELECT id, legal_entity FROM tenders WHERE id=?"); $chk->execute([$tenderId]);
    $tRow = $chk->fetch();
    if (!$tRow) respond(['error' => 'Тендер не найден'], 404);
    if (!checkLegalEntityAccess($su, $tRow['legal_entity'])) respond(['error' => 'Нет доступа к юр. лицу'], 403);

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        respond(['error' => 'Ошибка загрузки файла'], 400);
    }
    $file = $_FILES['file'];
    $maxSize = 10 * 1024 * 1024;
    if ($file['size'] > $maxSize) respond(['error' => 'Файл слишком большой (макс 10МБ)'], 400);

    $allowedMime = [
        'application/pdf','image/jpeg','image/png','image/webp',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/msword',
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowedMime)) respond(['error' => 'Допустимые форматы: PDF, JPEG, PNG, WebP, Excel, Word'], 400);

    $ext = match($mime) {
        'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/msword' => 'doc', default => 'bin',
    };
    $filename = 'tkp_' . $tenderId . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $uploadDir = __DIR__ . '/../uploads/tenders/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
    $dest = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) respond(['error' => 'Ошибка сохранения файла'], 500);

    $origName = mb_substr($file['name'], 0, 255);
    $pdo->prepare("INSERT INTO tender_files (tender_id, supplier, file_name, file_path) VALUES (?, ?, ?, ?)")
        ->execute([$tenderId, $supplier, $origName, $filename]);
    $insertId = $pdo->lastInsertId();
    respond(['success' => true, 'id' => intval($insertId), 'file_name' => $origName, 'file_path' => $filename]);
}

// ═══ DOWNLOAD TENDER KP ═══
if ($endpoint === 'uploads' && ($parts[1] ?? '') === 'tenders' && isset($parts[2])) {
    if (!checkAuth($pdo)) respond(['error' => 'Требуется авторизация'], 401);
    $filename = basename($parts[2]);
    $filepath = __DIR__ . '/../uploads/tenders/' . $filename;
    if (!file_exists($filepath)) { http_response_code(404); echo json_encode(['error' => 'Файл не найден']); exit; }
    $caller = getSessionUser($pdo);
    if ($caller) {
        $fchk = $pdo->prepare("SELECT t.legal_entity FROM tender_files tf JOIN tenders t ON tf.tender_id=t.id WHERE tf.file_path=?");
        $fchk->execute([$filename]);
        $fle = $fchk->fetchColumn();
        if ($fle === false) respond(['error' => 'Файл не найден'], 404);
        if ($fle && !checkLegalEntityAccess($caller, $fle)) respond(['error' => 'Нет доступа'], 403);
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $filepath);
    finfo_close($finfo);
    $disposition = isset($_GET['download']) ? 'attachment' : 'inline';
    $origName = $filename;
    if (isset($_GET['download'])) {
        $nm = $pdo->prepare("SELECT file_name FROM tender_files WHERE file_path=?"); $nm->execute([$filename]);
        $origName = $nm->fetchColumn() ?: $filename;
    }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . $disposition . '; filename="' . sanitizeHeaderFilename($origName) . '"');
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);
    exit;
}

// ═══ UPLOAD/DELETE PROTOCOL FILE ═══
if ($endpoint === 'upload' && $subpoint === 'protocol-file') {
    if ($method === 'DELETE') {
        if (!checkAuth($pdo)) respond(['error' => 'Требуется авторизация'], 401);
        $su = getSessionUser($pdo);
        if (!$su) respond(['error' => 'Требуется авторизация'], 401);
        $fileId = intval($_GET['file_id'] ?? 0);
        if (!$fileId) respond(['error' => 'Не указан ID файла'], 400);
        $frow = $pdo->prepare("SELECT * FROM meeting_protocol_files WHERE id=?");
        $frow->execute([$fileId]); $frow = $frow->fetch();
        if (!$frow) respond(['error' => 'Файл не найден'], 404);
        // Удалять может создатель протокола или админ
        $proto = $pdo->prepare("SELECT created_by FROM meeting_protocols WHERE id=?");
        $proto->execute([$frow['protocol_id']]); $proto = $proto->fetch();
        if ($proto && $proto['created_by'] !== $su['name'] && $su['role'] !== 'admin') respond(['error' => 'Нет прав на удаление'], 403);
        $filepath = __DIR__ . '/../uploads/protocols/' . basename($frow['file_path']);
        if (file_exists($filepath)) unlink($filepath);
        $pdo->prepare("DELETE FROM meeting_protocol_files WHERE id=?")->execute([$fileId]);
        respond(['success' => true]);
    }
    if ($method !== 'POST') respond(['error' => 'Метод не поддерживается'], 405);
    if (!checkAuth($pdo)) respond(['error' => 'Требуется авторизация'], 401);
    $su = getSessionUser($pdo);
    if (!$su) respond(['error' => 'Требуется авторизация'], 401);
    $p = resolvePermissions($su['role'], $su['permissions'] ?? null, $ROLE_TEMPLATES);
    if (($ACCESS_LEVELS[$p['protocols'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);
    $protocolId = intval($_POST['protocol_id'] ?? 0);
    if (!$protocolId) respond(['error' => 'Не указан ID протокола'], 400);
    $chk = $pdo->prepare("SELECT id FROM meeting_protocols WHERE id=?"); $chk->execute([$protocolId]);
    if (!$chk->fetch()) respond(['error' => 'Протокол не найден'], 404);
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) respond(['error' => 'Ошибка загрузки файла'], 400);
    $file = $_FILES['file'];
    if ($file['size'] > 10 * 1024 * 1024) respond(['error' => 'Файл слишком большой (макс 10МБ)'], 400);
    $allowedMime = ['application/pdf','image/jpeg','image/png','image/webp','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/msword','text/plain'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($finfo, $file['tmp_name']); finfo_close($finfo);
    if (!in_array($mime, $allowedMime)) respond(['error' => 'Допустимые форматы: PDF, JPEG, PNG, WebP, Excel, Word, TXT'], 400);
    $ext = match($mime) { 'application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx','application/vnd.ms-excel'=>'xls','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx','application/msword'=>'doc','text/plain'=>'txt',default=>'bin' };
    $filename = 'proto_' . $protocolId . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $uploadDir = __DIR__ . '/../uploads/protocols/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) respond(['error' => 'Ошибка сохранения файла'], 500);
    $origName = mb_substr($file['name'], 0, 255);
    $pdo->prepare("INSERT INTO meeting_protocol_files (protocol_id, file_name, file_path, uploaded_by) VALUES (?, ?, ?, ?)")
        ->execute([$protocolId, $origName, $filename, $su['name']]);
    respond(['success' => true, 'id' => intval($pdo->lastInsertId()), 'file_name' => $origName, 'file_path' => $filename, 'uploaded_by' => $su['name']]);
}

// ═══ DOWNLOAD PROTOCOL FILE ═══
if ($endpoint === 'uploads' && ($parts[1] ?? '') === 'protocols' && isset($parts[2])) {
    // Поддержка токена через query-параметр (для открытия в новой вкладке)
    if (isset($_GET['token'])) $_SERVER['HTTP_X_SESSION_TOKEN'] = $_GET['token'];
    if (!checkAuth($pdo)) respond(['error' => 'Требуется авторизация'], 401);
    $filename = basename($parts[2]);
    $filepath = __DIR__ . '/../uploads/protocols/' . $filename;
    if (!file_exists($filepath)) { http_response_code(404); echo json_encode(['error' => 'Файл не найден']); exit; }
    $finfo = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($finfo, $filepath); finfo_close($finfo);
    $disposition = isset($_GET['download']) ? 'attachment' : 'inline';
    $origName = $filename;
    if (isset($_GET['download'])) { $nm = $pdo->prepare("SELECT file_name FROM meeting_protocol_files WHERE file_path=?"); $nm->execute([$filename]); $origName = $nm->fetchColumn() ?: $filename; }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . $disposition . '; filename="' . sanitizeHeaderFilename($origName) . '"');
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);
    exit;
}

// ═══ UPLOAD MARKETING FILE ═══
if ($endpoint === 'upload' && $subpoint === 'marketing-file') {
    if ($method === 'DELETE') {
        if (!checkAuth($pdo)) respond(['error' => 'Требуется авторизация'], 401);
        $su = getSessionUser($pdo);
        if (!$su) respond(['error' => 'Требуется авторизация'], 401);
        $p = resolvePermissions($su['role'], $su['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$p['marketing'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);
        $fileId = intval($_GET['file_id'] ?? 0);
        if (!$fileId) respond(['error' => 'Не указан ID файла'], 400);
        $frow = $pdo->prepare("SELECT f.*, a.legal_entity FROM marketing_activity_files f JOIN marketing_activities a ON f.activity_id=a.id WHERE f.id=?");
        $frow->execute([$fileId]); $frow = $frow->fetch();
        if (!$frow) respond(['error' => 'Файл не найден'], 404);
        if (!checkLegalEntityAccess($su, $frow['legal_entity'])) respond(['error' => 'Нет доступа'], 403);
        $filepath = __DIR__ . '/../uploads/marketing/' . basename($frow['file_path']);
        if (file_exists($filepath)) unlink($filepath);
        $pdo->prepare("DELETE FROM marketing_activity_files WHERE id=?")->execute([$fileId]);
        respond(['success' => true]);
    }
    if ($method !== 'POST') respond(['error' => 'Метод не поддерживается'], 405);
    if (!checkAuth($pdo)) respond(['error' => 'Требуется авторизация'], 401);
    $su = getSessionUser($pdo);
    if (!$su) respond(['error' => 'Требуется авторизация'], 401);
    $p = resolvePermissions($su['role'], $su['permissions'] ?? null, $ROLE_TEMPLATES);
    if (($ACCESS_LEVELS[$p['marketing'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);

    $activityId = intval($_POST['activity_id'] ?? 0);
    if (!$activityId) respond(['error' => 'Не указан ID активности'], 400);

    $chk = $pdo->prepare("SELECT id, legal_entity FROM marketing_activities WHERE id=?"); $chk->execute([$activityId]);
    $aRow = $chk->fetch();
    if (!$aRow) respond(['error' => 'Активность не найдена'], 404);
    if (!checkLegalEntityAccess($su, $aRow['legal_entity'])) respond(['error' => 'Нет доступа'], 403);

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) respond(['error' => 'Ошибка загрузки'], 400);
    $file = $_FILES['file'];
    if ($file['size'] > 10 * 1024 * 1024) respond(['error' => 'Файл слишком большой (макс 10МБ)'], 400);

    $allowedMime = [
        'application/pdf','image/jpeg','image/png','image/webp',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/msword',
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowedMime)) respond(['error' => 'Допустимые форматы: PDF, JPEG, PNG, WebP, Excel, Word'], 400);

    $ext = match($mime) {
        'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/msword' => 'doc', default => 'bin',
    };
    $filename = 'mkt_' . $activityId . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $uploadDir = __DIR__ . '/../uploads/marketing/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
    $dest = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) respond(['error' => 'Ошибка сохранения'], 500);

    $origName = mb_substr($file['name'], 0, 255);
    $pdo->prepare("INSERT INTO marketing_activity_files (activity_id, file_name, file_path) VALUES (?, ?, ?)")
        ->execute([$activityId, $origName, $filename]);
    $insertId = $pdo->lastInsertId();
    respond(['success' => true, 'id' => intval($insertId), 'file_name' => $origName, 'file_path' => $filename]);
}

// ═══ DOWNLOAD MARKETING FILE ═══
if ($endpoint === 'uploads' && ($parts[1] ?? '') === 'marketing' && isset($parts[2])) {
    if (!checkAuth($pdo)) respond(['error' => 'Требуется авторизация'], 401);
    $filename = basename($parts[2]);
    $filepath = __DIR__ . '/../uploads/marketing/' . $filename;
    if (!file_exists($filepath)) { http_response_code(404); echo json_encode(['error' => 'Файл не найден']); exit; }
    $caller = getSessionUser($pdo);
    if ($caller) {
        $fchk = $pdo->prepare("SELECT a.legal_entity FROM marketing_activity_files f JOIN marketing_activities a ON f.activity_id=a.id WHERE f.file_path=?");
        $fchk->execute([$filename]);
        $fle = $fchk->fetchColumn();
        if ($fle === false) respond(['error' => 'Файл не найден'], 404);
        if ($fle && !checkLegalEntityAccess($caller, $fle)) respond(['error' => 'Нет доступа'], 403);
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $filepath);
    finfo_close($finfo);
    $disposition = isset($_GET['download']) ? 'attachment' : 'inline';
    $origName = $filename;
    if (isset($_GET['download'])) {
        $nm = $pdo->prepare("SELECT file_name FROM marketing_activity_files WHERE file_path=?"); $nm->execute([$filename]);
        $origName = $nm->fetchColumn() ?: $filename;
    }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . $disposition . '; filename="' . sanitizeHeaderFilename($origName) . '"');
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);
    exit;
}

// ═══ UPLOAD BUG REPORT SCREENSHOT ═══
if ($endpoint === 'upload' && $subpoint === 'bug-screenshot') {
    if ($method !== 'POST') respond(['error' => 'Метод не поддерживается'], 405);
    if (!checkAuth($pdo)) respond(['error' => 'Требуется авторизация'], 401);

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        respond(['error' => 'Ошибка загрузки файла'], 400);
    }
    $file = $_FILES['file'];
    $maxSize = 10 * 1024 * 1024;
    if ($file['size'] > $maxSize) respond(['error' => 'Файл слишком большой (макс. 10 МБ)'], 400);

    $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowed)) respond(['error' => 'Разрешены только изображения (JPEG, PNG, WebP, GIF)'], 400);

    $ext = match($mime) {
        'image/jpeg' => 'jpg', 'image/png' => 'png',
        'image/webp' => 'webp', 'image/gif' => 'gif', default => 'bin',
    };
    $filename = 'bug_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $uploadDir = __DIR__ . '/../uploads/bugs/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
    $dest = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) respond(['error' => 'Ошибка сохранения файла'], 500);
    respond(['success' => true, 'path' => 'uploads/bugs/' . $filename]);
}

// ═══ DOWNLOAD BUG SCREENSHOT ═══
if ($endpoint === 'uploads' && ($parts[1] ?? '') === 'bugs' && isset($parts[2])) {
    if (!checkAuth($pdo)) respond(['error' => 'Требуется авторизация'], 401);
    $filename = basename($parts[2]);
    $filepath = __DIR__ . '/../uploads/bugs/' . $filename;
    if (!file_exists($filepath)) { http_response_code(404); echo json_encode(['error' => 'Файл не найден']); exit; }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $filepath);
    finfo_close($finfo);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . sanitizeHeaderFilename($filename) . '"');
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);
    exit;
}
