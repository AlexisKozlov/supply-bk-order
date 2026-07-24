<?php
// Модуль «Аналоги»: импорт карточек из Excel в таблицу analog_cards.
// Формат файла — как в Google-таблице аналогов: колонка B=код, C=наименование
// (с ведущим кодом), D=мера, G=группа аналогов. Матчинг с products по SKU с
// нормализацией префикса BK_/ВК_. Обновление по коду (upsert), новые — добавляются.

if ($endpoint === 'analogs' && $subpoint === 'import' && $method === 'POST') {
    $sessionUser = getSessionUser($pdo);
    if (!$sessionUser) respond(['error' => 'Требуется авторизация'], 401);
    requireModuleAccess($sessionUser, 'analogs', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);

    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        respond(['error' => 'Файл не передан'], 400);
    }
    if ((int)($_FILES['file']['size'] ?? 0) > 20 * 1024 * 1024) {
        respond(['error' => 'Файл слишком большой (макс. 20 МБ)'], 400);
    }

    require_once __DIR__ . '/../lib/SimpleXLSX.php';
    $xlsx = \Shuchkin\SimpleXLSX::parse($_FILES['file']['tmp_name']);
    if (!$xlsx) respond(['error' => 'Не удалось прочитать Excel'], 400);
    $rows = $xlsx->rows(0);
    if (!$rows || count($rows) < 2) respond(['error' => 'В файле нет данных'], 400);

    $normSku = function ($c) {
        $c = trim((string)$c);
        return trim(preg_replace('/^(BK_|ВК_)/u', '', $c));
    };

    // Существующие карточки по коду
    $existing = [];
    foreach ($pdo->query("SELECT id, code FROM analog_cards") as $er) {
        $existing[$er['code']] = (int)$er['id'];
    }

    $findProd = $pdo->prepare("SELECT sku, legal_entity_group FROM products WHERE sku = ? LIMIT 1");
    $insStmt = $pdo->prepare("INSERT INTO analog_cards (code, sku, full_name, measure, analog_group, legal_entity_group, in_catalog, created_by) VALUES (?,?,?,?,?,?,?,?)");
    $updStmt = $pdo->prepare("UPDATE analog_cards SET sku=?, full_name=?, measure=?, analog_group=?, legal_entity_group=?, in_catalog=?, updated_by=? WHERE id=?");

    $uname = $sessionUser['name'] ?? 'import';
    $imported = 0; $new = 0; $updated = 0; $matched = 0;
    set_time_limit(120);
    $pdo->beginTransaction();
    try {
        foreach ($rows as $ri => $r) {
            if ($ri === 0) continue; // заголовок
            $code = trim((string)($r[1] ?? ''));
            $name = trim((string)($r[2] ?? ''));
            $measure = trim((string)($r[3] ?? ''));
            $group = trim((string)($r[6] ?? ''));
            if ($code === '' && $name === '') continue;

            // full_name: убрать ведущий код-токен из имени
            $fn = $name;
            $tok = strtok($name, ' ');
            if ($tok !== false) {
                $numCode = $normSku($code);
                if ($tok === $numCode || $tok === $code) $fn = trim(substr($name, strlen($tok)));
            }

            // матчинг с справочником. Справочник аналогов — только БК+ВМ,
            // поэтому legal_entity_group всегда BK_VM (даже если товар совпал с ПС).
            $sku = $normSku($code); $leg = 'BK_VM'; $inCat = 0;
            $findProd->execute([$code]); $p = $findProd->fetch();
            if (!$p) { $findProd->execute([$sku]); $p = $findProd->fetch(); }
            if ($p) { $inCat = 1; $sku = $p['sku']; $matched++; }

            if (isset($existing[$code])) {
                $updStmt->execute([$sku, $fn, $measure, $group ?: null, $leg, $inCat, $uname, $existing[$code]]);
                $updated++;
            } else {
                $insStmt->execute([$code, $sku, $fn, $measure, $group ?: null, $leg, $inCat, $uname]);
                $existing[$code] = (int)$pdo->lastInsertId();
                $new++;
            }
            $imported++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[analogs import] ' . $e->getMessage());
        respond(['error' => 'Ошибка импорта: ' . $e->getMessage()], 500);
    }

    respond(['success' => true, 'imported' => $imported, 'new' => $new, 'updated' => $updated, 'matched' => $matched]);
}
