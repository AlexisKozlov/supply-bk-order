<?php
/**
 * RPC: цены, ПСЦ, прайс-листы.
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName,
 * $ROLE_TEMPLATES, $ACCESS_LEVELS, $clientIp.
 */

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
            $leRow = trim($p['legal_entity']);
            $grpRow = getEntityGroup($leRow);
            // Точное совпадение по юрлицу.
            $bySku[trim($p['sku']) . '|' . $leRow] = $p;
            if (!empty($p['external_code'])) $byExt[trim($p['external_code']) . '|' . $leRow] = $p;
            if (!empty($p['gtin'])) $byGtin[trim($p['gtin']) . '|' . $leRow] = $p;
            // Fallback в пределах группы юрлиц (BK_VM / PS) — ни в коем случае не глобально,
            // чтобы не склеить товары из чужой группы (напр. цены ВМ с поставщиком из ПС).
            $bySkuGrpKey = trim($p['sku']) . '|group|' . $grpRow;
            $bySku[$bySkuGrpKey] = $bySku[$bySkuGrpKey] ?? $p;
            if (!empty($p['external_code'])) {
                $byExtGrpKey = trim($p['external_code']) . '|group|' . $grpRow;
                $byExt[$byExtGrpKey] = $byExt[$byExtGrpKey] ?? $p;
            }
            if (!empty($p['gtin'])) {
                $byGtinGrpKey = trim($p['gtin']) . '|group|' . $grpRow;
                $byGtin[$byGtinGrpKey] = $byGtin[$byGtinGrpKey] ?? $p;
            }
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

        // Цены живут на уровне группы юрлиц (BK_VM или PS) — одна запись на группу.
        // Триггер trg_product_prices_le_group_ins сам выставит legal_entity_group
        // по legal_entity, переданному в INSERT. legal_entity сохраняется как
        // «через какое юрлицо импортировано» (для аудита).
        $group = getEntityGroup($le);
        $entities = getEntitiesInGroup($group);
        $leForInsert = $entities[0]; // например, «Бургер БК» для группы BK_VM
        $matched = 0;
        $skipped = [];
        $upsert = $pdo->prepare("INSERT INTO product_prices (sku, supplier, legal_entity, price, vat_rate, unit_type, price_type, currency, updated_by)
            VALUES (?, ?, ?, ?, 0, 'box', 'deposit', 'BYN', ?)
            ON DUPLICATE KEY UPDATE price=VALUES(price), unit_type='box', updated_by=VALUES(updated_by), updated_at=NOW()");

        try {
            $pdo->beginTransaction();
            foreach ($uniquePrices as $up) {
                $product = null;
                // Ищем продукт сначала по конкретным юрлицам группы, потом fallback по группе.
                foreach ($entities as $entLe) {
                    if ($up['ec'] && isset($byExt[$up['ec'] . '|' . $entLe])) { $product = $byExt[$up['ec'] . '|' . $entLe]; break; }
                    if ($up['gt'] && isset($byGtin[$up['gt'] . '|' . $entLe])) { $product = $byGtin[$up['gt'] . '|' . $entLe]; break; }
                    if ($up['sk'] && isset($bySku[$up['sk'] . '|' . $entLe])) { $product = $bySku[$up['sk'] . '|' . $entLe]; break; }
                }
                if (!$product && $up['ec']) $product = $byExt[$up['ec'] . '|group|' . $group] ?? null;
                if (!$product && $up['gt']) $product = $byGtin[$up['gt'] . '|group|' . $group] ?? null;
                if (!$product && $up['sk']) $product = $bySku[$up['sk'] . '|group|' . $group] ?? null;
                if (!$product) {
                    $skipped[] = ['external_code' => $up['ec'], 'gtin' => $up['gt'], 'sku' => $up['sk'], 'name' => $up['name'], 'price' => $up['price']];
                    continue;
                }
                $upsert->execute([
                    $product['sku'],
                    $product['supplier'] ?? '',
                    $leForInsert,
                    $up['price'],
                    $caller['name'] ?? 'admin',
                ]);
                $matched++;
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
        // Проверка что agreement_id принадлежит той же группе юрлиц.
        $group = getEntityGroup($le);
        if ($agreementId) {
            $agChk = $pdo->prepare("SELECT legal_entity_group FROM price_agreements WHERE id=?"); $agChk->execute([$agreementId]);
            $agGroup = $agChk->fetchColumn();
            if (!$agGroup || $agGroup !== $group) respond(['error' => 'Протокол не принадлежит указанному юр. лицу'], 400);
        }
        $imported = 0;
        try {
            $pdo->beginTransaction();
            $currency = in_array($body['currency'] ?? '', ['BYN', 'RUB']) ? $body['currency'] : 'BYN';
            // INSERT пишет одну запись на группу — UNIQUE по (sku, supplier, legal_entity_group, price_type)
            // обеспечит UPSERT даже если предыдущая запись была от соседнего юрлица той же группы.
            $stmt = $pdo->prepare("INSERT INTO product_prices (sku, supplier, legal_entity, price, vat_rate, unit_type, currency, agreement_id, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE price=VALUES(price), vat_rate=VALUES(vat_rate), unit_type=VALUES(unit_type), currency=VALUES(currency), agreement_id=VALUES(agreement_id), updated_by=VALUES(updated_by), updated_at=NOW()");
            $oldStmt = $pdo->prepare("SELECT price, currency FROM product_prices WHERE sku=? AND supplier=? AND legal_entity_group=? AND price_type='purchase'");
            $histStmt = $pdo->prepare("INSERT INTO price_history (sku, supplier, legal_entity, old_price, new_price, old_currency, new_currency, agreement_id, changed_by) VALUES (?,?,?,?,?,?,?,?,?)");
            foreach ($prices as $p) {
                $sku = trim($p['sku'] ?? '');
                $price = floatval($p['price'] ?? 0);
                $ut = $p['unit_type'] ?? 'piece';
                $unitType = in_array($ut, ['piece', 'box', 'thousand', 'kg', 'liter']) ? $ut : 'piece';
                $cur = in_array($p['currency'] ?? '', ['BYN', 'RUB']) ? $p['currency'] : $currency;
                $vat = floatval($p['vat_rate'] ?? 20);
                if (!$sku || $price < 0) continue;
                // Сохранить старую цену для истории (по группе).
                $oldStmt->execute([$sku, $supplier, $group]);
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
            if (!checkLegalEntityGroupAccess($caller, $ag['legal_entity_group'])) { $pdo->rollBack(); respond(['error' => 'Нет доступа к юр. лицу'], 403); }
            if ($ag['status'] === 'active') { $pdo->rollBack(); respond(['error' => 'Протокол уже согласован'], 400); }
            $docType = $ag['doc_type'] ?? 'psc';
            // ПСЦ архивирует предыдущие ПСЦ этого поставщика на уровне группы юрлиц.
            if ($docType === 'psc') {
                $pdo->prepare("UPDATE price_agreements SET status='archived' WHERE supplier=? AND legal_entity_group=? AND status='active' AND doc_type='psc'")->execute([$ag['supplier'], $ag['legal_entity_group']]);
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
        if (!checkLegalEntityGroupAccess($caller, $ag['legal_entity_group'])) respond(['error' => 'Нет доступа к юр. лицу'], 403);
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
        if (!checkLegalEntityGroupAccess($caller, $ag['legal_entity_group'])) respond(['error' => 'Нет доступа к юр. лицу'], 403);
        if ($ag['status'] !== 'archived') respond(['error' => 'Протокол не в архиве'], 400);
        $docType = $ag['doc_type'] ?? 'psc';
        $pdo->beginTransaction();
        try {
            // Архивируем текущий активный ПСЦ того же поставщика на уровне группы юрлиц.
            if ($docType === 'psc') {
                $pdo->prepare("UPDATE price_agreements SET status='archived' WHERE supplier=? AND legal_entity_group=? AND status='active' AND doc_type='psc'")->execute([$ag['supplier'], $ag['legal_entity_group']]);
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
        // Цены — на уровне группы юрлиц (BK_VM или PS), одна запись на группу.
        $group = getEntityGroup($le);
        $sql = "SELECT pp.id, pp.sku, pp.price, pp.vat_rate, pp.unit_type, pp.currency, pp.supplier, pp.agreement_id, pp.updated_at FROM product_prices pp WHERE pp.legal_entity_group=? AND pp.price_type='purchase'";
        $params = [$group];
        if ($supplier) { $sql .= " AND pp.supplier=?"; $params[] = $supplier; }
        $s = $pdo->prepare($sql); $s->execute($params);
        $rows = $s->fetchAll();
        // Залоговые цены (отдельная выборка — для колонки «Залог» в прайс-листе)
        $dep = $pdo->prepare("SELECT sku, price FROM product_prices WHERE legal_entity_group=? AND price_type='deposit'");
        $dep->execute([$group]);
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
        if (!checkLegalEntityGroupAccess($caller, $ag['legal_entity_group'])) respond(['error' => 'Нет доступа к юр. лицу'], 403);
        // Сначала транзакция БД, потом — удаление файла. Иначе при сбое каскада
        // файл уже удалён, а запись осталась.
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
        // Удалить файл с диска после успешного коммита.
        if ($ag['file_path']) {
            $fpBase = basename($ag['file_path']);
            if ($fpBase) {
                $fp = __DIR__ . '/../uploads/psc/' . $fpBase;
                if (file_exists($fp)) @unlink($fp);
            }
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
        // Цены и продукты живут на уровне группы юрлиц (BK_VM или PS).
        $group = getEntityGroup($le);
        $sql = "SELECT pp.id, pp.sku, pp.price, pp.updated_at, pp.updated_by,
                       COALESCE(p.name, '') AS name,
                       COALESCE(p.supplier, pp.supplier, '') AS supplier,
                       COALESCE(p.external_code, '') AS external_code,
                       COALESCE(p.gtin, '') AS gtin
                FROM product_prices pp
                LEFT JOIN products p ON p.sku = pp.sku AND p.legal_entity_group = pp.legal_entity_group AND p.is_active = 1
                WHERE pp.legal_entity_group = ? AND pp.price_type = 'deposit'
                ORDER BY p.name, pp.sku";
        $s = $pdo->prepare($sql); $s->execute([$group]);
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
        if (!$sku || !$le) respond(['error' => 'Не указан SKU или юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $le)) respond(['error' => 'Нет доступа'], 403);
        if ($price !== null && $price <= 0) respond(['error' => 'Цена должна быть > 0'], 400);

        // Цены живут на уровне группы — одна запись на группу.
        $group = getEntityGroup($le);
        $entities = getEntitiesInGroup($group);
        $leForInsert = $entities[0];

        // Поставщик товара (для NOT NULL supplier в product_prices)
        $supStmt = $pdo->prepare("SELECT supplier FROM products WHERE sku = ? AND legal_entity_group = ? AND is_active = 1 LIMIT 1");
        $supStmt->execute([$sku, $group]);
        $supplier = $supStmt->fetchColumn() ?: '';

        try {
            if ($price === null) {
                $pdo->prepare("DELETE FROM product_prices WHERE sku=? AND legal_entity_group=? AND price_type='deposit'")
                    ->execute([$sku, $group]);
            } else {
                $pdo->prepare("INSERT INTO product_prices (sku, supplier, legal_entity, price, vat_rate, unit_type, price_type, currency, updated_by)
                    VALUES (?, ?, ?, ?, 0, 'box', 'deposit', 'BYN', ?)
                    ON DUPLICATE KEY UPDATE price=VALUES(price), unit_type='box', updated_by=VALUES(updated_by), updated_at=NOW()")
                    ->execute([$sku, $supplier, $leForInsert, $price, $caller['name'] ?? 'admin']);
            }
        } catch (Exception $e) {
            error_log('set_deposit_price error: ' . $e->getMessage());
            respond(['error' => 'Ошибка сохранения: ' . $e->getMessage()], 500);
        }
        auditLog($pdo, $price === null ? 'deposit_price_deleted' : 'deposit_price_updated', 'product_prices', null, $caller['name'], ['sku' => $sku, 'price' => $price, 'group' => $group]);
        respond(['success' => true]);
    }

    if ($fn === 'delete_price') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'Не указан ID'], 400);
        $s = $pdo->prepare("SELECT * FROM product_prices WHERE id=?"); $s->execute([$id]); $row = $s->fetch();
        if (!$row) respond(['error' => 'Цена не найдена'], 404);
        // Доступ — на уровне группы юрлиц (цены общие на группу).
        if (!checkLegalEntityGroupAccess($caller, $row['legal_entity_group'])) respond(['error' => 'Нет доступа'], 403);
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
        // История цен — на уровне группы юрлиц.
        $group = getEntityGroup($le);
        $sql = "SELECT * FROM price_history WHERE sku=? AND legal_entity_group=?";
        $params = [$sku, $group];
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
        $sql .= " AND NOT EXISTS (SELECT 1 FROM product_prices pp WHERE pp.sku = p.sku AND pp.legal_entity_group = ? AND pp.price_type = 'purchase')";
        $params[] = getEntityGroup($le);
        $sql .= " ORDER BY p.supplier, p.name";
        $s = $pdo->prepare($sql); $s->execute($params);
        respond($s->fetchAll());
    }
