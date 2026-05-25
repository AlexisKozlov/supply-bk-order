<?php
/**
 * RPC: чат с ресторанами.
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName,
 * $ROLE_TEMPLATES, $ACCESS_LEVELS, $clientIp.
 */

    if ($fn === 'chat_get_conversations') {
        requireModuleAccess($authUser, 'chat', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $status = $body['status'] ?? 'open';
        $legalEntity = $body['legal_entity'] ?? null;
        $entityGroup = $legalEntity ? getEntityGroup($legalEntity) : null;
        $where = ['cc.status = ?'];
        $params = [$status];
        if ($entityGroup) {
            $where[] = 'cc.legal_entity_group = ?';
            $params[] = $entityGroup;
        }
        $sql = "SELECT cc.*,
            (SELECT COUNT(*) FROM chat_messages cm WHERE cm.conversation_id = cc.id AND cm.is_read = 0 AND cm.direction = 'from_restaurant') as unread_count,
            (SELECT cm2.message_text FROM chat_messages cm2 WHERE cm2.conversation_id = cc.id ORDER BY cm2.id DESC LIMIT 1) as last_message
            FROM chat_conversations cc
            WHERE " . implode(' AND ', $where) . "
            ORDER BY cc.last_message_at DESC LIMIT 100";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        respond($st->fetchAll());
    }

    if ($fn === 'chat_get_messages') {
        requireModuleAccess($authUser, 'chat', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $convId = intval($body['conversation_id'] ?? 0);
        if (!$convId) respond(['error' => 'conversation_id required'], 400);
        // Доступ — на уровне группы юрлиц переписки.
        $accCheck = $pdo->prepare("SELECT legal_entity_group FROM chat_conversations WHERE id = ?");
        $accCheck->execute([$convId]);
        $convGroup = $accCheck->fetchColumn();
        if ($convGroup === false) respond(['error' => 'Диалог не найден'], 404);
        if (!checkLegalEntityGroupAccess($authUser, $convGroup)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        // Помечаем как прочитанные
        $pdo->prepare("UPDATE chat_messages SET is_read = 1 WHERE conversation_id = ? AND direction = 'from_restaurant' AND is_read = 0")->execute([$convId]);
        $st = $pdo->prepare("SELECT * FROM chat_messages WHERE conversation_id = ? ORDER BY created_at ASC LIMIT 500");
        $st->execute([$convId]);
        respond($st->fetchAll());
    }

    if ($fn === 'chat_send_message') {
        requireModuleAccess($authUser, 'chat', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $convId = intval($body['conversation_id'] ?? 0);
        $text = trim($body['message_text'] ?? '');
        if (!$convId || !$text) respond(['error' => 'conversation_id and message_text required'], 400);
        $caller = getSessionUser($pdo);
        $senderName = $caller['name'] ?? 'Закупки';

        // Доступ — на уровне группы юрлиц переписки.
        $accCheck = $pdo->prepare("SELECT legal_entity_group FROM chat_conversations WHERE id = ?");
        $accCheck->execute([$convId]);
        $convGroup = $accCheck->fetchColumn();
        if ($convGroup === false) respond(['error' => 'Диалог не найден'], 404);
        if (!checkLegalEntityGroupAccess($caller, $convGroup)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);

        $ins = $pdo->prepare("INSERT INTO chat_messages (conversation_id, direction, sender_name, message_text, is_read) VALUES (?, 'from_purchasing', ?, ?, 1)");
        $ins->execute([$convId, $senderName, $text]);
        $pdo->prepare("UPDATE chat_conversations SET last_message_at = NOW() WHERE id = ?")->execute([$convId]);

        // Отправляем в Telegram ресторану
        $conv = $pdo->prepare("SELECT restaurant_chat_id, restaurant_number FROM chat_conversations WHERE id = ?");
        $conv->execute([$convId]);
        $c = $conv->fetch();
        if ($c && $c['restaurant_chat_id']) {
            $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
            if ($botToken) {
                $rNum = htmlspecialchars((string)$c['restaurant_number'], ENT_QUOTES, 'UTF-8');
                $sName = htmlspecialchars((string)$senderName, ENT_QUOTES, 'UTF-8');
                $tgText = "📨 <b>Ответ от отдела закупок</b>\n";
                $tgText .= "🏪 Ресторан {$rNum}\n";
                $tgText .= "─────────────────────\n";
                $tgText .= htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . "\n";
                $tgText .= "─────────────────────\n";
                $tgText .= "<i>Ответил: {$sName}</i>";
                $payload = json_encode(['chat_id' => $c['restaurant_chat_id'], 'text' => $tgText, 'parse_mode' => 'HTML']);
                $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 5]);
                curl_exec($ch); curl_close($ch);
            }
        }
        respond(['success' => true, 'message_id' => $pdo->lastInsertId()]);
    }

    if ($fn === 'chat_close_conversation') {
        requireModuleAccess($authUser, 'chat', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $convId = intval($body['conversation_id'] ?? 0);
        if (!$convId) respond(['error' => 'conversation_id required'], 400);
        $caller = getSessionUser($pdo);
        $accCheck = $pdo->prepare("SELECT legal_entity_group FROM chat_conversations WHERE id = ?");
        $accCheck->execute([$convId]);
        $convGroup = $accCheck->fetchColumn();
        if ($convGroup === false) respond(['error' => 'Диалог не найден'], 404);
        if (!checkLegalEntityGroupAccess($caller, $convGroup)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        $pdo->prepare("UPDATE chat_conversations SET status = 'closed', closed_by = ?, closed_at = NOW() WHERE id = ?")
            ->execute([$caller['name'] ?? 'unknown', $convId]);
        respond(['success' => true]);
    }

    if ($fn === 'chat_reopen_conversation') {
        requireModuleAccess($authUser, 'chat', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $convId = intval($body['conversation_id'] ?? 0);
        if (!$convId) respond(['error' => 'conversation_id required'], 400);
        $accCheck = $pdo->prepare("SELECT legal_entity_group FROM chat_conversations WHERE id = ?");
        $accCheck->execute([$convId]);
        $convGroup = $accCheck->fetchColumn();
        if ($convGroup === false) respond(['error' => 'Диалог не найден'], 404);
        if (!checkLegalEntityGroupAccess($authUser, $convGroup)) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);
        $pdo->prepare("UPDATE chat_conversations SET status = 'open', closed_by = NULL, closed_at = NULL WHERE id = ?")->execute([$convId]);
        respond(['success' => true]);
    }

    if ($fn === 'chat_unread_total') {
        requireModuleAccess($authUser, 'chat', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $cnt = $pdo->query("SELECT COUNT(*) FROM chat_messages cm JOIN chat_conversations cc ON cc.id = cm.conversation_id WHERE cm.is_read = 0 AND cm.direction = 'from_restaurant' AND cc.status = 'open'")->fetchColumn();
        respond(['count' => intval($cnt)]);
    }

    if ($fn === 'chat_send_photo') {
        requireModuleAccess($authUser, 'chat', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $convId = intval($_POST['conversation_id'] ?? $body['conversation_id'] ?? 0);
        if (!$convId) respond(['error' => 'conversation_id required'], 400);
        if (empty($_FILES['photo'])) respond(['error' => 'Файл не выбран'], 400);

        $caller = getSessionUser($pdo);
        $senderName = $caller['name'] ?? 'Закупки';

        $conv = $pdo->prepare("SELECT restaurant_chat_id, restaurant_number, legal_entity_group FROM chat_conversations WHERE id = ?");
        $conv->execute([$convId]);
        $c = $conv->fetch();
        if (!$c) respond(['error' => 'Диалог не найден'], 404);
        if (!checkLegalEntityGroupAccess($caller, $c['legal_entity_group'])) respond(['error' => 'Нет доступа к данной группе юр. лиц'], 403);

        // Отправляем фото в Telegram ресторану и получаем file_id
        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        $photoFileId = null;
        if ($botToken && $c['restaurant_chat_id']) {
            $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendPhoto");
            $postData = [
                'chat_id' => $c['restaurant_chat_id'],
                'photo' => new CURLFile($_FILES['photo']['tmp_name'], $_FILES['photo']['type'], $_FILES['photo']['name']),
                'caption' => "📨 Фото от отдела закупок ({$senderName})",
            ];
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $postData, CURLOPT_TIMEOUT => 30]);
            $resp = json_decode(curl_exec($ch), true);
            curl_close($ch);
            if (isset($resp['result']['photo'])) {
                $photos = $resp['result']['photo'];
                $photoFileId = end($photos)['file_id'] ?? null;
            }
        }

        if (!$photoFileId) respond(['error' => 'Не удалось отправить фото'], 500);

        // Сохраняем в базу
        $ins = $pdo->prepare("INSERT INTO chat_messages (conversation_id, direction, sender_name, photo_file_id, is_read) VALUES (?, 'from_purchasing', ?, ?, 1)");
        $ins->execute([$convId, $senderName, $photoFileId]);
        $pdo->prepare("UPDATE chat_conversations SET last_message_at = NOW() WHERE id = ?")->execute([$convId]);

        respond(['success' => true, 'photo_file_id' => $photoFileId]);
    }

    if ($fn === 'chat_get_photo') {
        requireModuleAccess($authUser, 'chat', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $fileId = $body['file_id'] ?? ($_GET['file_id'] ?? '');
        if (!$fileId) respond(['error' => 'file_id required'], 400);
        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if (!$botToken) respond(['error' => 'Bot не настроен'], 500);
        $resp = tgHttpGet("https://api.telegram.org/bot{$botToken}/getFile?" . http_build_query(['file_id' => $fileId]));
        $data = json_decode($resp, true);
        $filePath = $data['result']['file_path'] ?? null;
        if (!$filePath) respond(['error' => 'File not found'], 404);
        // Скачиваем файл серверно — токен бота не уходит клиенту.
        $ch = curl_init("https://api.telegram.org/file/bot{$botToken}/" . $filePath);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false]);
        $bytes = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($bytes === false || $httpCode !== 200) respond(['error' => 'Не удалось загрузить фото'], 502);
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
        respond(['data_url' => 'data:' . $mime . ';base64,' . base64_encode($bytes)]);
    }

    // ═══ ПРОТОКОЛЫ СОВЕЩАНИЙ ═══

    // Подтянуть описание привязанной карточки задачника ко всем решениям,
    // у которых проставлен tasks_card_id. Объявлено ВЫШЕ обработчиков
    // get_protocol/get_carryover_tasks, потому что в PHP функции внутри
    // условного блока регистрируются только при достижении строки
    // объявления — иначе до неё вызов падает 500.
    function pdAttachCardDescription($pdo, &$decisions) {
        if (!is_array($decisions) || !$decisions) return;
        $cardIds = [];
        foreach ($decisions as $d) {
            $cid = isset($d['tasks_card_id']) ? (int)$d['tasks_card_id'] : 0;
            if ($cid) $cardIds[$cid] = true;
        }
        if (!$cardIds) {
            foreach ($decisions as &$d) $d['card_description'] = null;
            unset($d);
            return;
        }
        $ids = array_keys($cardIds);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $s = $pdo->prepare("SELECT id, description FROM tasks_cards WHERE id IN ($ph)");
        $s->execute($ids);
        $byCard = [];
        foreach ($s->fetchAll() as $r) $byCard[(int)$r['id']] = (string)($r['description'] ?? '');
        foreach ($decisions as &$d) {
            $cid = isset($d['tasks_card_id']) ? (int)$d['tasks_card_id'] : 0;
            $d['card_description'] = ($cid && isset($byCard[$cid]) && $byCard[$cid] !== '') ? $byCard[$cid] : null;
        }
        unset($d);
    }

    function pdAttachAssigneesProgress($pdo, &$decisions) {
        if (!is_array($decisions) || !$decisions) return;
        $decIds = [];
        foreach ($decisions as $d) {
            $id = isset($d['id']) ? (int)$d['id'] : 0;
            if ($id) $decIds[$id] = true;
        }
        if (!$decIds) {
            foreach ($decisions as &$d) { $d['assignees_progress'] = []; $d['card_id_for_me'] = null; }
            unset($d);
            return;
        }
        $ids = array_keys($decIds);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $s = $pdo->prepare("
            SELECT pdc.decision_id, pdc.user_name, pdc.card_id, c.is_done, c.description
            FROM protocol_decision_cards pdc
            JOIN tasks_cards c ON c.id = pdc.card_id
            WHERE pdc.decision_id IN ($ph)
            ORDER BY pdc.decision_id, pdc.created_at, pdc.card_id
        ");
        $s->execute($ids);
        $byDec = [];
        foreach ($s->fetchAll() as $r) {
            $did = (int)$r['decision_id'];
            $byDec[$did][] = [
                'user_name'   => $r['user_name'],
                'card_id'     => (int)$r['card_id'],
                'is_done'     => (int)$r['is_done'] === 1,
                'description' => (string)($r['description'] ?? ''),
            ];
        }
        $me = function_exists('getSessionUser') ? (getSessionUser($pdo)['name'] ?? null) : null;
        foreach ($decisions as &$d) {
            $did = isset($d['id']) ? (int)$d['id'] : 0;
            $list = $byDec[$did] ?? [];
            $d['assignees_progress'] = $list;
            $d['card_id_for_me'] = null;
            if ($me) {
                foreach ($list as $row) {
                    if ($row['user_name'] === $me) { $d['card_id_for_me'] = $row['card_id']; break; }
                }
            }
        }
        unset($d);
    }
