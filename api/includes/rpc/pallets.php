<?php
/**
 * RPC: паллетный справочник и расчёт занятости.
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName,
 * $ROLE_TEMPLATES, $ACCESS_LEVELS, $clientIp.
 */

    if ($fn === 'import_pallet_reference') {
        requireModuleAccess($authUser, 'pallet-storage', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $items = $body['items'] ?? [];
        $legalEntity = $body['legal_entity'] ?? null;
        if (empty($items)) respond(['error' => 'Нет данных'], 400);
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($authUser, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $group = getEntityGroup($legalEntity);
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("INSERT INTO pallet_reference (legal_entity_group, name, storage_category, sku, pieces_per_block, blocks_per_box, boxes_per_pallet, pieces_per_pallet, box_length_mm, box_height_mm, box_width_mm, pallet_height_m, cell_coefficient)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE storage_category=VALUES(storage_category), sku=VALUES(sku), pieces_per_block=VALUES(pieces_per_block), blocks_per_box=VALUES(blocks_per_box), boxes_per_pallet=VALUES(boxes_per_pallet), pieces_per_pallet=VALUES(pieces_per_pallet), box_length_mm=VALUES(box_length_mm), box_height_mm=VALUES(box_height_mm), box_width_mm=VALUES(box_width_mm), pallet_height_m=VALUES(pallet_height_m), cell_coefficient=VALUES(cell_coefficient)");
            $count = 0;
            foreach ($items as $it) {
                $name = trim($it['name'] ?? '');
                if (!$name) continue;
                $L = intval($it['box_length_mm'] ?? 0);
                $H = intval($it['box_height_mm'] ?? 0);
                $W = intval($it['box_width_mm'] ?? 0);
                $bpp = intval($it['boxes_per_pallet'] ?? 0);
                $ppb = intval($it['pieces_per_block'] ?? 0);
                $bpb = intval($it['blocks_per_box'] ?? 1) ?: 1;
                $ppp = intval($it['pieces_per_pallet'] ?? 0);
                // Высота паллеты: (коробов_на_паллете × Д × В × Ш) / 10^9 / 0.96
                $palletH = ($bpp > 0 && $L > 0 && $H > 0 && $W > 0)
                    ? ($bpp * $L * $H * $W) / 1e9 / 0.96
                    : null;
                // Коэффициент ячейки
                $coeff = null;
                if ($palletH !== null) {
                    if ($palletH <= 0.30) $coeff = 0.25;
                    elseif ($palletH <= 0.85) $coeff = 0.5;
                    else $coeff = 1.0;
                }
                $st->execute([
                    $group,
                    $name, $it['storage_category'] ?? null, $it['sku'] ?? null,
                    $ppb ?: null, $bpb, $bpp ?: null, $ppp ?: null,
                    $L ?: null, $H ?: null, $W ?: null,
                    $palletH !== null ? round($palletH, 4) : null,
                    $coeff,
                ]);
                $count++;
            }
            $pdo->commit();
            respond(['ok' => true, 'count' => $count]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("import_pallet_reference error: " . $e->getMessage());
            respond(['error' => 'Ошибка импорта'], 500);
        }
    }

    // ═══ Паллетовка: загрузка справочника ═══
    if ($fn === 'get_pallet_reference') {
        requireModuleAccess($authUser, 'pallet-storage', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $legalEntity = $_GET['legal_entity'] ?? $body['legal_entity'] ?? null;
        if ($legalEntity && !checkLegalEntityAccess($authUser, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $group = $legalEntity ? getEntityGroup($legalEntity) : 'BK_VM';
        $st = $pdo->prepare("SELECT * FROM pallet_reference WHERE legal_entity_group = ? ORDER BY storage_category, name");
        $st->execute([$group]);
        respond($st->fetchAll(PDO::FETCH_ASSOC));
    }

    // ═══ Паллетовка: обновить поле (частота, кол-во коробок) ═══
    if ($fn === 'update_pallet_field') {
        requireModuleAccess($authUser, 'pallet-storage', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['id'] ?? 0);
        $field = $body['field'] ?? '';
        $value = $body['value'] ?? null;
        if (!$id) respond(['error' => 'Не указан ID'], 400);
        $allowed = ['delivery_frequency', 'incoming_boxes', 'input_unit'];
        if (!in_array($field, $allowed)) respond(['error' => 'Недопустимое поле'], 400);
        $pdo->prepare("UPDATE pallet_reference SET `$field` = ? WHERE id = ?")->execute([$value, $id]);
        respond(['ok' => true]);
    }

    // ═══ Паллетовка: расчёт заполненности ═══
    if ($fn === 'calc_pallet_occupancy') {
        requireModuleAccess($authUser, 'pallet-storage', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $legalEntity = $_GET['legal_entity'] ?? $body['legal_entity'] ?? null;
        if ($legalEntity && !checkLegalEntityAccess($authUser, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $group = $legalEntity ? getEntityGroup($legalEntity) : 'BK_VM';
        // Справочник — только выбранного юрлица
        $s = $pdo->prepare("SELECT * FROM pallet_reference WHERE legal_entity_group = ? ORDER BY storage_category, name");
        $s->execute([$group]);
        $ref = $s->fetchAll(PDO::FETCH_ASSOC);

        // Расчёт: справочник + ручной ввод коробок
        // Высота неполной паллеты пропорциональна заполнению
        function calcCoeff($h) {
            if ($h <= 0) return 0;
            if ($h <= 0.30) return 0.25;
            if ($h <= 0.85) return 0.5;
            return 1.0;
        }

        $results = [];
        foreach ($ref as $r) {
            $bpp = intval($r['boxes_per_pallet'] ?? 0) ?: intval($r['pieces_per_pallet'] ?? 0);
            $fullH = floatval($r['pallet_height_m'] ?? 0);
            $incomingBoxes = intval($r['incoming_boxes'] ?? 0);

            $freq = intval($r['delivery_frequency'] ?? 0);
            $inputUnit = $r['input_unit'] ?? 'boxes';
            $ppb = intval($r['pieces_per_block'] ?? 0);
            // Если ввод в штуках — пересчитать в коробки
            $totalBoxes = $incomingBoxes;
            if ($inputUnit === 'pieces' && $ppb > 0) {
                $totalBoxes = ceil($incomingBoxes / $ppb);
            }
            // Коробок за одну поставку: если указана частота — делим
            $boxesPerDelivery = ($freq > 1) ? ceil($totalBoxes / $freq) : $totalBoxes;

            $cells = 0;
            $actualH = 0;
            $actualCoeff = 0;

            if ($bpp > 0 && $boxesPerDelivery > 0) {
                $fullPallets = floor($boxesPerDelivery / $bpp);
                $remainder = $boxesPerDelivery % $bpp;

                if ($fullPallets > 0) {
                    // Есть полные паллеты → коэфф. 1 за каждую + неполная
                    $cells = $fullPallets * 1.0;
                    $actualH = $fullH;
                    $actualCoeff = 1.0;
                    if ($remainder > 0) {
                        $lastH = ($fullH > 0) ? $fullH * ($remainder / $bpp) : 0;
                        $cells += calcCoeff($lastH);
                    }
                } else {
                    // Только неполная паллета
                    $actualH = ($fullH > 0) ? $fullH * ($boxesPerDelivery / $bpp) : 0;
                    $actualCoeff = calcCoeff($actualH);
                    $cells = $actualCoeff;
                }
            }
            $totalPallets = ($bpp > 0 && $boxesPerDelivery > 0) ? $boxesPerDelivery / $bpp : 0;

            $results[] = [
                'ref_id' => intval($r['id']),
                'name' => $r['name'],
                'storage_category' => $r['storage_category'],
                'pieces_per_block' => intval($r['pieces_per_block'] ?? 0),
                'blocks_per_box' => intval($r['blocks_per_box'] ?? 1),
                'boxes_per_pallet' => $bpp,
                'box_length_mm' => intval($r['box_length_mm'] ?? 0),
                'box_height_mm' => intval($r['box_height_mm'] ?? 0),
                'box_width_mm' => intval($r['box_width_mm'] ?? 0),
                'incoming_boxes' => $incomingBoxes,
                'input_unit' => $inputUnit,
                'delivery_frequency' => $freq ?: null,
                'boxes_per_delivery' => $boxesPerDelivery,
                'pallets' => round($totalPallets, 2),
                'actual_height' => round($actualH, 4),
                'cell_coefficient' => $actualCoeff,
                'cells' => round($cells, 2),
                'delivery_frequency' => $r['delivery_frequency'],
            ];
        }
        respond($results);
    }
