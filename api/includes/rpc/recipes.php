<?php
/**
 * RPC: рецептуры и группы рецептов (включая find_recipes_by_names).
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName,
 * $ROLE_TEMPLATES, $ACCESS_LEVELS, $clientIp.
 */

    if ($fn === 'import_recipes') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $perms = resolvePermissions($caller['role'] ?? 'user', $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['marketing'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) respond(['error' => 'Недостаточно прав'], 403);

        $recipes = $body['recipes'] ?? [];
        $legalEntity = $body['legal_entity'] ?? null;
        if (empty($recipes)) respond(['error' => 'Нет данных для импорта'], 400);
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);

        $group = getEntityGroup($legalEntity);

        $pdo->beginTransaction();
        try {
            // Очистить старые рецептуры ТОЛЬКО этого юрлица (не трогаем чужие)
            $pdo->prepare("DELETE ri FROM recipe_ingredients ri JOIN recipes r ON r.id = ri.recipe_id WHERE r.legal_entity_group = ?")
                ->execute([$group]);
            $pdo->prepare("DELETE FROM recipes WHERE legal_entity_group = ?")->execute([$group]);

            $imported = 0;
            foreach ($recipes as $r) {
                $code = $r['code'] ?? null;
                $name = trim($r['name'] ?? '');
                if (!$name) continue;
                $thk = $r['thk'] ?? null;
                $brutto = $r['brutto'] ?? null;
                $qty = $r['qty'] ?? null;

                $pdo->prepare("INSERT INTO recipes (code, name, thk, legal_entity_group, brutto_total, qty_total) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$code, $name, $thk, $group, $brutto, $qty]);
                $recipeId = $pdo->lastInsertId();

                foreach (($r['ingredients'] ?? []) as $i => $ing) {
                    $pdo->prepare("INSERT INTO recipe_ingredients (recipe_id, sku, name, brutto, qty, sort_order) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$recipeId, $ing['sku'] ?? null, $ing['name'] ?? '', $ing['brutto'] ?? null, $ing['qty'] ?? null, $i]);
                }
                $imported++;
            }

            $pdo->commit();
            auditLog($pdo, 'recipe_imported', 'import', null, $caller['name'], ['count' => $imported]);
            respond(['success' => true, 'imported' => $imported]);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('import_recipes error: ' . $e->getMessage());
            respond(['error' => 'Ошибка импорта рецептур'], 500);
        }
    }

    // ═══ Рецептуры: получить ингредиенты для списка блюд ═══
    if ($fn === 'get_recipe_ingredients') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);

        $dishNames = $body['dish_names'] ?? [];
        $dishCodes = $body['dish_codes'] ?? [];
        $legalEntity = $body['legal_entity'] ?? null;
        if (empty($dishNames) && empty($dishCodes)) respond(['error' => 'Не указаны блюда'], 400);
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);

        $group = getEntityGroup($legalEntity);

        $recipes = [];
        if (!empty($dishCodes)) {
            $ph = implode(',', array_fill(0, count($dishCodes), '?'));
            $s = $pdo->prepare("SELECT * FROM recipes WHERE code IN ($ph) AND legal_entity_group = ?");
            $s->execute(array_merge($dishCodes, [$group]));
            $recipes = $s->fetchAll();
        } elseif (!empty($dishNames)) {
            $ph = implode(',', array_fill(0, count($dishNames), '?'));
            $s = $pdo->prepare("SELECT * FROM recipes WHERE name IN ($ph) AND legal_entity_group = ?");
            $s->execute(array_merge($dishNames, [$group]));
            $recipes = $s->fetchAll();
        }

        // Собрать все SKU ингредиентов одним запросом (раньше тут был N+1
        // по числу рецептов). JOIN с products фильтруется по группе юрлиц
        // рецепта, чтобы не подтянуть карточку из чужой группы.
        $allSkus = [];
        $recipeIngs = [];
        $recipeIds = array_column($recipes, 'id');
        if (!empty($recipeIds)) {
            $rph = implode(',', array_fill(0, count($recipeIds), '?'));
            $stIngs = $pdo->prepare("SELECT ri.*, p.analog_group, p.qty_per_box, p.unit_of_measure as product_unit, p.supplier as product_supplier
                FROM recipe_ingredients ri
                LEFT JOIN products p ON p.sku COLLATE utf8mb4_unicode_ci = ri.sku COLLATE utf8mb4_unicode_ci
                 AND p.legal_entity_group = ?
                 AND p.is_active = 1
                WHERE ri.recipe_id IN ({$rph}) ORDER BY ri.recipe_id, ri.sort_order");
            $stIngs->execute(array_merge([$group], $recipeIds));
            foreach ($stIngs->fetchAll() as $ing) {
                $recipeIngs[$ing['recipe_id']][] = $ing;
                if ($ing['sku'] && !$ing['analog_group']) $allSkus[] = $ing['sku'];
            }
        }

        // Для SKU без совпадения в products — искать через cards
        $cardAnalogMap = []; // sku → { analog_group, qty_per_box, product_unit, resolved_name }
        if (!empty($allSkus)) {
            $allSkus = array_values(array_unique($allSkus));
            // 1) Прямой поиск по cards.id
            $ph = implode(',', array_fill(0, count($allSkus), '?'));
            $s = $pdo->prepare("SELECT id, name, analogs FROM cards WHERE id COLLATE utf8mb4_unicode_ci IN ($ph)");
            $s->execute($allSkus);
            $cardRows = $s->fetchAll();
            $foundDirectly = [];
            foreach ($cardRows as $cr) {
                $foundDirectly[$cr['id']] = $cr;
            }
            // 2) Поиск через analogs JSON (SKU упомянут в массиве аналогов другой карточки)
            $notFound = array_diff($allSkus, array_keys($foundDirectly));
            $foundViaAnalogs = [];
            if (!empty($notFound)) {
                $s = $pdo->prepare("SELECT id, name, analogs FROM cards WHERE analogs IS NOT NULL");
                $s->execute();
                while ($cr = $s->fetch()) {
                    $analogs = json_decode($cr['analogs'], true);
                    if (!is_array($analogs)) continue;
                    foreach ($notFound as $sku) {
                        if (in_array($sku, $analogs) || in_array((string)$sku, $analogs)) {
                            $foundViaAnalogs[$sku] = $cr;
                        }
                    }
                }
            }
            // 3) Для найденных карточек — найти аналоги в products
            $allCardSkus = [];
            foreach ($foundDirectly + $foundViaAnalogs as $sku => $cr) {
                $analogs = json_decode($cr['analogs'], true) ?: [];
                $analogs[] = $cr['id']; // сама карточка тоже может быть в products
                foreach ($analogs as $a) $allCardSkus[] = (string)$a;
            }
            $allCardSkus = array_values(array_unique($allCardSkus));
            $productByCardSku = [];
            if (!empty($allCardSkus)) {
                $ph2 = implode(',', array_fill(0, count($allCardSkus), '?'));
                // Фильтруем по группе юрлиц рецепта, чтобы не затянуть карточку из чужой группы.
                $s = $pdo->prepare("SELECT sku, analog_group, qty_per_box, unit_of_measure, supplier FROM products WHERE sku COLLATE utf8mb4_unicode_ci IN ($ph2) AND legal_entity_group = ?");
                $s->execute(array_merge($allCardSkus, [$group]));
                while ($pr = $s->fetch()) $productByCardSku[$pr['sku']] = $pr;
            }
            // 4) Связать: recipe_sku → card → analog_skus → product
            foreach ($foundDirectly + $foundViaAnalogs as $origSku => $cr) {
                $analogs = json_decode($cr['analogs'], true) ?: [];
                $analogs[] = $cr['id'];
                foreach ($analogs as $a) {
                    if (isset($productByCardSku[(string)$a])) {
                        $pr = $productByCardSku[(string)$a];
                        $cardAnalogMap[$origSku] = [
                            'analog_group' => $pr['analog_group'],
                            'qty_per_box' => $pr['qty_per_box'],
                            'product_unit' => $pr['unit_of_measure'],
                            'resolved_sku' => $pr['sku'],
                            'supplier' => $pr['supplier'],
                        ];
                        break;
                    }
                }
                // Если не нашли в products — хотя бы имя карточки как группу
                if (!isset($cardAnalogMap[$origSku])) {
                    $cardAnalogMap[$origSku] = [
                        'analog_group' => $cr['name'],
                        'qty_per_box' => null,
                        'product_unit' => null,
                        'resolved_sku' => null,
                    ];
                }
            }
        }

        // Применить найденные аналоги к ингредиентам
        $result = [];
        foreach ($recipes as $r) {
            $ings = $recipeIngs[$r['id']] ?? [];
            foreach ($ings as &$ing) {
                if ($ing['sku'] && !$ing['analog_group'] && isset($cardAnalogMap[$ing['sku']])) {
                    $resolved = $cardAnalogMap[$ing['sku']];
                    $ing['analog_group'] = $resolved['analog_group'];
                    if (!$ing['qty_per_box'] && $resolved['qty_per_box']) $ing['qty_per_box'] = $resolved['qty_per_box'];
                    if (!$ing['product_unit'] && $resolved['product_unit']) $ing['product_unit'] = $resolved['product_unit'];
                    if ($resolved['resolved_sku']) {
                        $ing['original_sku'] = $ing['sku'];
                        $ing['sku'] = $resolved['resolved_sku'];
                    }
                    if (!empty($resolved['supplier'])) $ing['product_supplier'] = $resolved['supplier'];
                }
            }
            unset($ing);
            $r['ingredients'] = $ings;
            $result[] = $r;
        }

        respond(['recipes' => $result]);
    }

    // ═══ Маркетинг: рассчитать доли блюд по реализации ═══
    if ($fn === 'calc_dish_shares') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $recipeIds = $body['recipe_ids'] ?? [];
        $legalEntity = $body['legal_entity'] ?? null;
        if (empty($recipeIds)) respond(['error' => 'Не указаны блюда'], 400);
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $ph = implode(',', array_fill(0, count($recipeIds), '?'));

        // Загрузить ингредиенты всех блюд → найти analog_group для каждого
        $recipeAGs = []; // recipe_id → [analog_group, ...]
        $allAGs = []; // все analog_group всех блюд
        $s = $pdo->prepare("SELECT ri.recipe_id, p.analog_group
            FROM recipe_ingredients ri
            JOIN products p ON p.sku COLLATE utf8mb4_unicode_ci = ri.sku COLLATE utf8mb4_unicode_ci
            WHERE ri.recipe_id IN ($ph) AND p.analog_group IS NOT NULL");
        $s->execute($recipeIds);
        while ($row = $s->fetch()) {
            $recipeAGs[$row['recipe_id']][] = $row['analog_group'];
            $allAGs[] = $row['analog_group'];
        }

        // Найти уникальные ингредиенты для каждого блюда (которые есть только в одном блюде из списка)
        $agCount = array_count_values($allAGs); // сколько раз каждый AG встречается (в разных рецептах)
        // Подсчёт: сколько РАЗНЫХ рецептов содержат этот AG
        $agRecipeCount = [];
        foreach ($recipeAGs as $rid => $ags) {
            foreach (array_unique($ags) as $ag) {
                $agRecipeCount[$ag] = ($agRecipeCount[$ag] ?? 0) + 1;
            }
        }

        // Для каждого блюда: найти уникальный ингредиент → его реализацию
        $s2 = $pdo->prepare("SELECT id, name FROM recipes WHERE id IN ($ph)");
        $s2->execute($recipeIds);
        $recipes = $s2->fetchAll();

        $shares = [];
        $totalSales = 0;
        foreach ($recipes as $r) {
            $rid = $r['id'];
            $uniqueAGs = [];
            foreach (array_unique($recipeAGs[$rid] ?? []) as $ag) {
                if (($agRecipeCount[$ag] ?? 0) === 1) $uniqueAGs[] = $ag; // уникальный для этого блюда
            }

            $qty = 0;
            // Ищем реализацию уникальных ингредиентов в restaurant_sales (по группе юрлиц)
            if (!empty($uniqueAGs)) {
                $ph3 = implode(',', array_fill(0, count($uniqueAGs), '?'));
                $s = $pdo->prepare("SELECT SUM(quantity) as qty FROM restaurant_sales WHERE analog_group IN ($ph3) AND legal_entity_group = ?");
                $s->execute(array_merge($uniqueAGs, [getEntityGroup($legalEntity)]));
                $qty = floatval($s->fetchColumn() ?: 0);
            }
            // Fallback: analysis_data (тоже по юрлицу)
            if ($qty <= 0 && !empty($uniqueAGs)) {
                $ph3 = implode(',', array_fill(0, count($uniqueAGs), '?'));
                $s = $pdo->prepare("SELECT SUM(ad.consumption) as qty FROM analysis_data ad JOIN products p ON p.sku = ad.sku WHERE p.analog_group IN ($ph3) AND ad.legal_entity = ?");
                $s->execute(array_merge($uniqueAGs, [$legalEntity]));
                $qty = floatval($s->fetchColumn() ?: 0);
            }

            $shares[] = ['recipe_id' => intval($rid), 'name' => $r['name'], 'sales' => $qty, 'unique_ingredients' => $uniqueAGs];
            $totalSales += $qty;
        }

        foreach ($shares as &$sh) {
            $sh['share'] = $totalSales > 0 ? round($sh['sales'] / $totalSales, 4) : round(1 / count($shares), 4);
        }
        unset($sh);
        respond(['shares' => $shares, 'total_sales' => $totalSales]);
    }

    // ═══ Рецептуры: группы по категориям (сначала ручные, потом по префиксу) ═══
    if ($fn === 'get_recipe_groups') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $prefixes = $body['prefixes'] ?? [];
        $legalEntity = $body['legal_entity'] ?? null;
        if (empty($prefixes)) respond(['error' => 'Не указаны префиксы'], 400);
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);

        $group = getEntityGroup($legalEntity);

        // Загрузить все ручные группы с ключевыми словами (только этого юрлица)
        $s = $pdo->prepare("SELECT id, name, keywords FROM recipe_groups WHERE legal_entity_group = ?");
        $s->execute([$group]);
        $allGroups = $s->fetchAll(PDO::FETCH_ASSOC);

        // Нормализация для сравнения: lowercase, убрать лишние пробелы, пробелы вокруг точек/запятых
        function normGroupKey($s) {
            $s = mb_strtolower(trim($s));
            $s = preg_replace('/\s+/', ' ', $s);           // множественные пробелы → один
            $s = preg_replace('/\s*([.,])\s*/', '$1', $s); // убрать пробелы вокруг . и ,
            return $s;
        }

        $result = [];
        foreach ($prefixes as $prefix) {
            $prefix = trim($prefix);
            if (!$prefix) continue;
            $prefixNorm = normGroupKey($prefix);

            // 1. Ищем ручную группу: имя или ключевые слова (нормализованное сравнение)
            $matchedGroup = null;
            foreach ($allGroups as $g) {
                if (normGroupKey($g['name']) === $prefixNorm) { $matchedGroup = $g; break; }
                $kw = json_decode($g['keywords'] ?: '[]', true);
                if (is_array($kw)) {
                    foreach ($kw as $k) {
                        if (normGroupKey($k) === $prefixNorm) { $matchedGroup = $g; break 2; }
                    }
                }
            }

            if ($matchedGroup) {
                // Вернуть рецептуры из ручной группы (рецепты тоже фильтруем по юрлицу)
                $s = $pdo->prepare("SELECT r.id, r.code, r.name FROM recipe_group_items gi JOIN recipes r ON r.id = gi.recipe_id WHERE gi.group_id = ? AND r.legal_entity_group = ? ORDER BY r.name");
                $s->execute([$matchedGroup['id'], $group]);
                $result[$prefix] = $s->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Автоматический подбор по префиксу
                $s = $pdo->prepare("SELECT id, code, name FROM recipes WHERE name LIKE ? AND legal_entity_group = ? ORDER BY name");
                $s->execute([$prefix . '%', $group]);
                $result[$prefix] = $s->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        respond($result);
    }

    // ═══ Рецептуры: управление ручными группами ═══
    if ($fn === 'save_recipe_group') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'marketing', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = $body['id'] ?? null;
        $name = trim($body['name'] ?? '');
        $keywords = $body['keywords'] ?? [];
        $recipeIds = $body['recipe_ids'] ?? [];
        $legalEntity = $body['legal_entity'] ?? null;
        if (!$name) respond(['error' => 'Укажите название группы'], 400);
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);

        $group = getEntityGroup($legalEntity);

        $pdo->beginTransaction();
        try {
            if ($id) {
                // Проверяем, что группа принадлежит этому юрлицу
                $chk = $pdo->prepare("SELECT legal_entity_group FROM recipe_groups WHERE id = ?");
                $chk->execute([$id]);
                $existing = $chk->fetchColumn();
                if ($existing && $existing !== $group) {
                    $pdo->rollBack();
                    respond(['error' => 'Группа принадлежит другому юрлицу'], 403);
                }
                $pdo->prepare("UPDATE recipe_groups SET name=?, keywords=? WHERE id=?")->execute([$name, json_encode($keywords, JSON_UNESCAPED_UNICODE), $id]);
                $pdo->prepare("DELETE FROM recipe_group_items WHERE group_id=?")->execute([$id]);
            } else {
                $pdo->prepare("INSERT INTO recipe_groups (name, keywords, legal_entity_group) VALUES (?, ?, ?)")->execute([$name, json_encode($keywords, JSON_UNESCAPED_UNICODE), $group]);
                $id = $pdo->lastInsertId();
            }
            if (!empty($recipeIds)) {
                $ins = $pdo->prepare("INSERT INTO recipe_group_items (group_id, recipe_id) VALUES (?, ?)");
                foreach ($recipeIds as $rid) { $ins->execute([$id, $rid]); }
            }
            $pdo->commit();
            respond(['ok' => true, 'id' => intval($id)]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            respond(['error' => 'Ошибка сохранения группы'], 500);
        }
    }

    if ($fn === 'delete_recipe_group') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'marketing', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = $body['id'] ?? null;
        if (!$id) respond(['error' => 'Не указан ID'], 400);
        $accCheck = $pdo->prepare("SELECT legal_entity_group FROM recipe_groups WHERE id = ?");
        $accCheck->execute([$id]);
        $rgGroup = $accCheck->fetchColumn();
        if ($rgGroup === false) respond(['error' => 'Группа не найдена'], 404);
        if (!checkLegalEntityGroupAccess($caller, $rgGroup)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        $pdo->prepare("DELETE FROM recipe_groups WHERE id=?")->execute([$id]);
        respond(['ok' => true]);
    }

    if ($fn === 'get_recipe_groups_list') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $legalEntity = $_GET['legal_entity'] ?? $body['legal_entity'] ?? null;
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $group = getEntityGroup($legalEntity);
        $s = $pdo->prepare("SELECT g.id, g.name, g.keywords, COUNT(gi.id) as recipe_count FROM recipe_groups g LEFT JOIN recipe_group_items gi ON gi.group_id = g.id WHERE g.legal_entity_group = ? GROUP BY g.id ORDER BY g.name");
        $s->execute([$group]);
        $groups = $s->fetchAll(PDO::FETCH_ASSOC);
        // Один пакетный запрос вместо N+1: подтягиваем все рецепты всех групп
        // и раскладываем по group_id в PHP.
        $byGroup = [];
        if (!empty($groups)) {
            $groupIds = array_column($groups, 'id');
            $gph = implode(',', array_fill(0, count($groupIds), '?'));
            $sr = $pdo->prepare("SELECT gi.group_id, r.id, r.code, r.name
                FROM recipe_group_items gi
                JOIN recipes r ON r.id = gi.recipe_id
                WHERE gi.group_id IN ({$gph}) AND r.legal_entity_group = ?
                ORDER BY r.name");
            $sr->execute(array_merge($groupIds, [$group]));
            foreach ($sr->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $gid = $row['group_id'];
                unset($row['group_id']);
                $byGroup[$gid][] = $row;
            }
        }
        foreach ($groups as &$g) {
            $g['recipes'] = $byGroup[$g['id']] ?? [];
            $g['keywords'] = json_decode($g['keywords'] ?: '[]', true);
        }
        unset($g);
        respond($groups);
    }

    if ($fn === 'find_recipes_by_names') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $names = $body['names'] ?? [];
        $legalEntity = $body['legal_entity'] ?? '';
        if (empty($names)) respond(['error' => 'Не указаны имена'], 400);
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);

        // Загрузить все рецептуры группы пользователя (общие на BK_VM или PS).
        $group = getEntityGroup($legalEntity);
        $rs = $pdo->prepare("SELECT id, code, name FROM recipes WHERE legal_entity_group = ?");
        $rs->execute([$group]);
        $allRecipes = $rs->fetchAll(PDO::FETCH_ASSOC);

        // Построить индексы для быстрого поиска
        $byExact = [];       // точное совпадение (нижний регистр)
        $byNormalized = [];  // нормализованное (без точек, лишних пробелов, скобок)
        $allEntries = [];    // для нечёткого поиска

        function normalizeRecipeName($n) {
            $n = mb_strtolower(trim($n));
            $n = rtrim($n, '.');
            $n = preg_replace('/\s*\(.*?\)\s*/', ' ', $n); // убрать скобки
            $n = preg_replace('/\s+/', ' ', trim($n));      // лишние пробелы
            // Сокращения
            $n = str_replace(['мал.', 'бол.', 'газ.'], ['малый', 'большой', 'газированный'], $n);
            return $n;
        }

        foreach ($allRecipes as $r) {
            $lower = mb_strtolower(trim($r['name']));
            $norm = normalizeRecipeName($r['name']);
            $byExact[$lower] = $r;
            if (!isset($byNormalized[$norm])) $byNormalized[$norm] = $r;
            $allEntries[] = ['norm' => $norm, 'words' => preg_split('/\s+/', $norm), 'rec' => $r];
        }

        $result = [];
        foreach ($names as $name) {
            $name = trim($name);
            if (!$name) continue;
            $lower = mb_strtolower($name);
            $norm = normalizeRecipeName($name);

            // 1. Точное совпадение (без учёта регистра)
            if (isset($byExact[$lower])) { $result[$name] = $byExact[$lower]; continue; }

            // 2. Нормализованное совпадение
            if (isset($byNormalized[$norm])) { $result[$name] = $byNormalized[$norm]; continue; }

            // 3. Без точки / с точкой
            $noTrail = rtrim($lower, '.');
            if (isset($byExact[$noTrail])) { $result[$name] = $byExact[$noTrail]; continue; }
            if (isset($byExact[$noTrail . '.'])) { $result[$name] = $byExact[$noTrail . '.']; continue; }

            // 4. Поиск по вхождению (рецептура содержит запрос или наоборот)
            $found = null;
            $bestLen = PHP_INT_MAX;
            foreach ($allEntries as $e) {
                if (strpos($e['norm'], $norm) === 0) {
                    // Рецептура начинается с запроса — берём самую короткую (наиболее точную)
                    $len = mb_strlen($e['norm']);
                    if ($len < $bestLen) { $found = $e['rec']; $bestLen = $len; }
                }
            }
            if ($found) { $result[$name] = $found; continue; }

            // 5. Поиск по ключевым словам (все слова запроса содержатся в рецептуре)
            $queryWords = preg_split('/\s+/', $norm);
            $bestScore = 0;
            $bestMatch = null;
            foreach ($allEntries as $e) {
                $matched = 0;
                foreach ($queryWords as $qw) {
                    if (mb_strlen($qw) < 2) continue;
                    foreach ($e['words'] as $rw) {
                        if (strpos($rw, $qw) === 0 || strpos($qw, $rw) === 0) { $matched++; break; }
                    }
                }
                if ($matched === 0) continue;
                // Оценка: доля совпавших слов × штраф за лишние слова в рецептуре
                $score = $matched / max(count($queryWords), 1);
                $penalty = abs(count($e['words']) - count($queryWords));
                $score -= $penalty * 0.1;
                if ($score > $bestScore && $score >= 0.5) {
                    $bestScore = $score;
                    $bestMatch = $e['rec'];
                }
            }
            $result[$name] = $bestMatch;
        }
        respond(['recipes' => $result]);
    }
