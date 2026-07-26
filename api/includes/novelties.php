<?php
/**
 * Модуль «Новинки» — сторона закупщика (портал).
 *
 * Подключается из api/index.php ПОСЛЕ restaurant_orders.php (переиспользует
 * roGetSessionUserGroups). Работает с эндпоинтом `novelties`.
 *
 * Идея: товар считается новинкой NOVELTY_DAYS дней с даты появления в
 * справочнике (products.created_at). Список новинок берётся из products по
 * дате. Таблица product_novelties хранит только редакторские данные закупщика:
 * описание, фото, дату старта продаж, признак «скрыто» и переопределение срока
 * показа (show_until). Видимость — по бизнес-группе (BK_VM / PS).
 *
 * Маршруты:
 *   GET    novelties[?group=BK_VM|PS]        — список для закупщика
 *   POST   novelties/:product_id             — сохранить описание/дату/скрытие/срок
 *   POST   novelties/:product_id/photo       — загрузить фото (multipart, поле file)
 *   DELETE novelties/:product_id/photo       — удалить фото
 */

if (($endpoint ?? '') !== 'novelties') return;

require_once __DIR__ . '/helpers.php';

if (!defined('NOVELTY_DAYS')) define('NOVELTY_DAYS', 21);

// Срок показа новинки: показываем от даты появления и до эффективного конца
// (переопределённый show_until или created_at + NOVELTY_DAYS дней).
function noveltyEffectiveEndSql() {
    return "COALESCE(n.show_until, p.created_at + INTERVAL " . NOVELTY_DAYS . " DAY)";
}

$novUser = getSessionUser($pdo);
if (!$novUser) respond(['error' => 'Требуется авторизация'], 401);

global $ROLE_TEMPLATES, $ACCESS_LEVELS;

$novProductId = $parts[1] ?? null;      // UUID товара
$novSub       = $parts[2] ?? null;      // 'photo' и т.п.

// Группы, доступные закупщику (BK_VM / PS). admin — обе.
$novGroups = roGetSessionUserGroups($novUser);

// ─── GET novelties — список для закупщика ───
if ($method === 'GET' && !$novProductId) {
    requireModuleAccess($novUser, 'novelties', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
    if (!$novGroups) respond(['items' => []]);

    $groups = $novGroups;
    $reqGroup = isset($_GET['group']) ? trim((string)$_GET['group']) : '';
    if ($reqGroup !== '') {
        if (!in_array($reqGroup, $novGroups, true)) respond(['error' => 'Нет доступа к группе'], 403);
        $groups = [$reqGroup];
    }

    $ph = implode(',', array_fill(0, count($groups), '?'));
    $end = noveltyEffectiveEndSql();
    $sql = "
        SELECT p.id AS product_id, p.sku, p.name, p.external_code,
               p.legal_entity, p.legal_entity_group, p.created_at,
               n.description, n.sales_start_date, n.photo_path,
               n.is_hidden, n.show_until, n.updated_by, n.updated_at,
               $end AS effective_end
        FROM products p
        LEFT JOIN product_novelties n ON n.product_id = p.id
        WHERE p.is_active = 1
          AND p.legal_entity_group IN ($ph)
          AND (
                p.created_at >= NOW() - INTERVAL " . NOVELTY_DAYS . " DAY
             OR (n.product_id IS NOT NULL AND (n.show_until IS NULL OR n.show_until >= NOW()))
          )
        ORDER BY p.created_at DESC, p.name
        LIMIT 500
    ";
    $s = $pdo->prepare($sql);
    $s->execute($groups);
    $rows = $s->fetchAll();

    $now = new DateTimeImmutable('now');
    $items = [];
    foreach ($rows as $r) {
        $isHidden = (int)($r['is_hidden'] ?? 0) === 1;
        $end = $r['effective_end'] ? new DateTimeImmutable($r['effective_end']) : null;
        $isCurrent = !$isHidden && $end && $end >= $now;
        $items[] = [
            'product_id'       => $r['product_id'],
            'sku'              => $r['sku'],
            'name'             => $r['name'],
            'external_code'    => $r['external_code'],
            'legal_entity'     => $r['legal_entity'],
            'legal_entity_group' => $r['legal_entity_group'],
            'created_at'       => $r['created_at'],
            'description'      => $r['description'],
            'sales_start_date' => $r['sales_start_date'],
            'photo_url'        => $r['photo_path'] ? '/api/' . ltrim($r['photo_path'], '/') : null,
            'is_hidden'        => $isHidden,
            'show_until'       => $r['show_until'],
            'effective_end'    => $r['effective_end'],
            'is_current'       => $isCurrent,
            'updated_by'       => $r['updated_by'],
            'updated_at'       => $r['updated_at'],
        ];
    }
    respond(['items' => $items, 'novelty_days' => NOVELTY_DAYS]);
}

// ─── GET novelties/search?q= — поиск любого товара группы для ручного добавления ───
// Позволяет закупщику добавить в новинки и старую карточку (создана давно, а в
// работу пошла позже). Ищем по названию, артикулу и внешнему коду.
if ($method === 'GET' && $novProductId === 'search') {
    requireModuleAccess($novUser, 'novelties', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2 || !$novGroups) respond(['items' => []]);
    // Ищем только в группе, которую сейчас смотрит закупщик (если передана и
    // доступна), иначе — во всех доступных группах. Так нельзя добавить товар
    // чужого бизнеса.
    $groups = $novGroups;
    $reqGroup = isset($_GET['group']) ? trim((string)$_GET['group']) : '';
    if ($reqGroup !== '') {
        if (!in_array($reqGroup, $novGroups, true)) respond(['error' => 'Нет доступа к группе'], 403);
        $groups = [$reqGroup];
    }
    $ph = implode(',', array_fill(0, count($groups), '?'));
    $like = '%' . $q . '%';
    $sql = "
        SELECT id AS product_id, sku, name, external_code, legal_entity_group
        FROM products
        WHERE is_active = 1
          AND legal_entity_group IN ($ph)
          AND (name LIKE ? OR sku LIKE ? OR external_code LIKE ?)
        ORDER BY name
        LIMIT 25
    ";
    $s = $pdo->prepare($sql);
    $s->execute(array_merge($groups, [$like, $like, $like]));
    respond(['items' => $s->fetchAll()]);
}

// Дальше — операции над конкретным товаром. Проверяем товар и доступ к группе.
if ($novProductId) {
    $ps = $pdo->prepare("SELECT id, name, legal_entity_group FROM products WHERE id = ? LIMIT 1");
    $ps->execute([$novProductId]);
    $novProduct = $ps->fetch();
    if (!$novProduct) respond(['error' => 'Товар не найден'], 404);
    if (!in_array($novProduct['legal_entity_group'], $novGroups, true)) {
        respond(['error' => 'Нет доступа к товару'], 403);
    }
}

// ─── POST novelties/:id/photo — загрузка фото ───
if ($method === 'POST' && $novProductId && $novSub === 'photo') {
    requireModuleAccess($novUser, 'novelties', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);

    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        respond(['error' => 'Файл не передан'], 400);
    }
    if ((int)($_FILES['file']['size'] ?? 0) > 8 * 1024 * 1024) {
        respond(['error' => 'Файл слишком большой (макс. 8 МБ)'], 400);
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES['file']['tmp_name']);
    finfo_close($finfo);
    if (!isset($allowed[$mime])) respond(['error' => 'Недопустимый формат (только JPG, PNG, WEBP)'], 400);
    $ext = $allowed[$mime];

    $uploadDir = __DIR__ . '/../uploads/novelties/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
    $filename = 'nov_' . substr(preg_replace('/[^a-f0-9]/', '', (string)$novProductId), 0, 8)
              . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = $uploadDir . $filename;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
        respond(['error' => 'Не удалось сохранить файл'], 500);
    }
    $path = 'uploads/novelties/' . $filename;

    // Старое фото — удаляем с диска.
    $old = $pdo->prepare("SELECT photo_path FROM product_novelties WHERE product_id = ?");
    $old->execute([$novProductId]);
    $oldPath = $old->fetchColumn();
    if ($oldPath) {
        $oldAbs = __DIR__ . '/../' . ltrim((string)$oldPath, '/');
        if (is_file($oldAbs) && strpos(realpath($oldAbs) ?: '', realpath($uploadDir)) === 0) @unlink($oldAbs);
    }

    $uname = $novUser['name'] ?? null;
    $pdo->prepare("
        INSERT INTO product_novelties (product_id, photo_path, updated_by)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE photo_path = VALUES(photo_path), updated_by = VALUES(updated_by)
    ")->execute([$novProductId, $path, $uname]);

    respond(['success' => true, 'photo_url' => '/api/' . $path]);
}

// ─── DELETE novelties/:id/photo — удалить фото ───
if ($method === 'DELETE' && $novProductId && $novSub === 'photo') {
    requireModuleAccess($novUser, 'novelties', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
    $old = $pdo->prepare("SELECT photo_path FROM product_novelties WHERE product_id = ?");
    $old->execute([$novProductId]);
    $oldPath = $old->fetchColumn();
    if ($oldPath) {
        $uploadDir = __DIR__ . '/../uploads/novelties/';
        $oldAbs = __DIR__ . '/../' . ltrim((string)$oldPath, '/');
        if (is_file($oldAbs) && strpos(realpath($oldAbs) ?: '', realpath($uploadDir)) === 0) @unlink($oldAbs);
    }
    $pdo->prepare("UPDATE product_novelties SET photo_path = NULL, updated_by = ? WHERE product_id = ?")
        ->execute([$novUser['name'] ?? null, $novProductId]);
    respond(['success' => true]);
}

// ─── POST novelties/:id — сохранить редакторские данные ───
if ($method === 'POST' && $novProductId && !$novSub) {
    requireModuleAccess($novUser, 'novelties', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);

    $description = trim((string)($body['description'] ?? ''));
    $description = $description === '' ? null : mb_substr($description, 0, 4000);

    $salesStart = trim((string)($body['sales_start_date'] ?? ''));
    if ($salesStart !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $salesStart)) {
        respond(['error' => 'Некорректная дата старта'], 400);
    }
    $salesStart = $salesStart === '' ? null : $salesStart;

    $isHidden = !empty($body['is_hidden']) ? 1 : 0;

    // show_until принимаем как дату YYYY-MM-DD (конец дня) либо пусто (сброс).
    $showUntil = trim((string)($body['show_until'] ?? ''));
    if ($showUntil !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $showUntil)) {
        respond(['error' => 'Некорректная дата показа'], 400);
    }
    $showUntilVal = $showUntil === '' ? null : ($showUntil . ' 23:59:59');

    $uname = $novUser['name'] ?? null;
    $pdo->prepare("
        INSERT INTO product_novelties
            (product_id, description, sales_start_date, is_hidden, show_until, updated_by)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            description = VALUES(description),
            sales_start_date = VALUES(sales_start_date),
            is_hidden = VALUES(is_hidden),
            show_until = VALUES(show_until),
            updated_by = VALUES(updated_by)
    ")->execute([$novProductId, $description, $salesStart, $isHidden, $showUntilVal, $uname]);

    respond(['success' => true]);
}

respond(['error' => 'Неизвестный маршрут novelties'], 404);
