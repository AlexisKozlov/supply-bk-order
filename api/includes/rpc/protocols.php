<?php
/**
 * RPC: протоколы согласования.
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName,
 * $ROLE_TEMPLATES, $ACCESS_LEVELS, $clientIp.
 */

    if ($fn === 'get_protocols') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'protocols', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $legalEntity = $body['legal_entity'] ?? $_GET['legal_entity'] ?? null;
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к юр. лицу'], 403);
        // Один JOIN с агрегатами вместо двух коррелированных подзапросов на каждую строку.
        // На 500 протоколах раньше было 1000 подзапросов к protocol_decisions.
        $s = $pdo->prepare("SELECT p.*,
                COALESCE(d.cnt, 0) AS decisions_count,
                COALESCE(d.done_cnt, 0) AS decisions_done,
                s.name as series_name
            FROM meeting_protocols p
            LEFT JOIN meeting_protocol_series s ON s.id = p.series_id
            LEFT JOIN (
                SELECT protocol_id, COUNT(*) AS cnt, SUM(status = 'done') AS done_cnt
                FROM protocol_decisions
                GROUP BY protocol_id
            ) d ON d.protocol_id = p.id
            WHERE p.legal_entity = ?
            ORDER BY p.meeting_date DESC, p.created_at DESC LIMIT 500");
        $s->execute([$legalEntity]);
        respond($s->fetchAll());
    }

    if ($fn === 'get_protocol') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'protocols', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'id required'], 400);
        $s = $pdo->prepare("SELECT p.*, s.name as series_name, s.recurrence, s.agenda_template FROM meeting_protocols p LEFT JOIN meeting_protocol_series s ON s.id = p.series_id WHERE p.id = ?");
        $s->execute([$id]);
        $proto = $s->fetch();
        if (!$proto) respond(['error' => 'Протокол не найден'], 404);
        if (!checkLegalEntityAccess($caller, $proto['legal_entity'] ?? null)) respond(['error' => 'Нет доступа'], 403);
        // Решения + последний комментарий из чата привязанной карточки.
        $d = $pdo->prepare("SELECT * FROM protocol_decisions WHERE protocol_id = ? ORDER BY id");
        $d->execute([$id]);
        $proto['decisions'] = $d->fetchAll();
        pdAttachCardDescription($pdo, $proto['decisions']);
        pdAttachAssigneesProgress($pdo, $proto['decisions']);
        // Файлы
        $f = $pdo->prepare("SELECT id, file_name, file_path, uploaded_by, uploaded_at FROM meeting_protocol_files WHERE protocol_id = ? ORDER BY uploaded_at");
        $f->execute([$id]);
        $proto['files'] = $f->fetchAll();
        respond($proto);
    }

    function pdResponsibleToUsers($pdo, $responsiblePerson) {
        $names = array_values(array_filter(array_map('trim', explode(',', (string)$responsiblePerson))));
        if (!$names) return [];
        $ph = implode(',', array_fill(0, count($names), '?'));
        $s = $pdo->prepare("SELECT name FROM users WHERE name IN ($ph)");
        $s->execute($names);
        $exist = array_column($s->fetchAll(), 'name');
        return array_values(array_filter($names, fn($n) => in_array($n, $exist, true)));
    }

    function pdEnsureUserBoard($pdo, $userName) {
        $s = $pdo->prepare("SELECT id FROM tasks_boards WHERE owner_name = ? AND is_archived = 0 ORDER BY sort_order, id LIMIT 1");
        $s->execute([$userName]);
        $bid = (int)$s->fetchColumn();
        if ($bid) return $bid;
        $pdo->prepare("INSERT INTO tasks_boards (owner_name, title) VALUES (?, ?)")->execute([$userName, $userName]);
        $bid = (int)$pdo->lastInsertId();
        $cols = [
            ['Сделать',  '#90A4AE',    0, 0, 0],
            ['В работе', '#FFA726',    1, 0, 0],
            ['Архив',    '#9E9E9E', 9999, 0, 1],
        ];
        $ins = $pdo->prepare("INSERT INTO tasks_columns (board_id, title, color, sort_order, is_done_column, is_archive_column) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($cols as $c) $ins->execute([$bid, $c[0], $c[1], $c[2], $c[3], $c[4]]);
        return $bid;
    }

    function pdSyncDecisionToCard($pdo, $decId, $createdBy = 'system') {
        $s = $pdo->prepare("SELECT pd.id, pd.text, pd.responsible_person, pd.deadline, pd.status, pd.protocol_id, p.topic, p.meeting_date FROM protocol_decisions pd JOIN meeting_protocols p ON p.id = pd.protocol_id WHERE pd.id = ?");
        $s->execute([$decId]);
        $dec = $s->fetch();
        if (!$dec) return;
        $users = pdResponsibleToUsers($pdo, $dec['responsible_person']);
        $title = mb_substr((string)$dec['text'], 0, 255);
        $cardDue = $dec['deadline'] ? ($dec['deadline'] . ' 23:59:59') : null;
        $isDone = $dec['status'] === 'done' ? 1 : 0;
        $isArchived = $isDone ? 1 : 0;
        $completedAt = $isDone ? date('Y-m-d H:i:s') : null;
        $entityLabel = mb_substr('Протокол: ' . ($dec['topic'] ?? '') . ' от ' . ($dec['meeting_date'] ?? ''), 0, 255);

        $existingQ = $pdo->prepare("SELECT card_id, user_name FROM protocol_decision_cards WHERE decision_id = ?");
        $existingQ->execute([$decId]);
        $existing = [];
        foreach ($existingQ->fetchAll() as $r) $existing[$r['user_name']] = (int)$r['card_id'];

        $toCreate = array_diff($users, array_keys($existing));
        $toRemove = array_diff(array_keys($existing), $users);

        foreach ($toRemove as $userName) {
            $cardId = $existing[$userName];
            $bRes = $pdo->prepare("SELECT board_id FROM tasks_cards WHERE id = ?");
            $bRes->execute([$cardId]);
            $boardId = (int)$bRes->fetchColumn();
            if ($boardId) {
                $arc = $pdo->prepare("SELECT id FROM tasks_columns WHERE board_id = ? AND is_archive_column = 1 ORDER BY sort_order LIMIT 1");
                $arc->execute([$boardId]);
                $arcId = (int)$arc->fetchColumn();
                if ($arcId) {
                    $pdo->prepare("UPDATE tasks_cards SET column_id = ?, is_done = 1, is_archived = 1, completed_at = COALESCE(completed_at, NOW()) WHERE id = ?")->execute([$arcId, $cardId]);
                }
            }
            $pdo->prepare("DELETE FROM protocol_decision_cards WHERE decision_id = ? AND card_id = ?")->execute([$decId, $cardId]);
            unset($existing[$userName]);
        }

        $firstCardId = null;
        foreach ($users as $userName) {
            if (isset($existing[$userName])) {
                $cardId = $existing[$userName];
                $cur = $pdo->prepare("SELECT board_id, column_id, is_done, (SELECT is_done_column FROM tasks_columns WHERE id = c.column_id) AS in_done, (SELECT is_archive_column FROM tasks_columns WHERE id = c.column_id) AS in_archive FROM tasks_cards c WHERE id = ?");
                $cur->execute([$cardId]);
                $card = $cur->fetch();
                if (!$card) continue;
                $newColumnId = (int)$card['column_id'];
                if ($isDone && !(int)$card['in_archive']) {
                    $c2 = $pdo->prepare("SELECT id FROM tasks_columns WHERE board_id = ? AND is_archive_column = 1 ORDER BY sort_order LIMIT 1");
                    $c2->execute([$card['board_id']]);
                    $newColumnId = (int)$c2->fetchColumn() ?: $newColumnId;
                } elseif (!$isDone && ((int)$card['in_done'] || (int)$card['in_archive'])) {
                    $c2 = $pdo->prepare("SELECT id FROM tasks_columns WHERE board_id = ? AND is_done_column = 0 AND is_archive_column = 0 ORDER BY sort_order, id LIMIT 1");
                    $c2->execute([$card['board_id']]);
                    $newColumnId = (int)$c2->fetchColumn() ?: $newColumnId;
                }
                $pdo->prepare("UPDATE tasks_cards SET title=?, due_date=?, is_done=?, is_archived=?, completed_at=?, column_id=? WHERE id=?")
                    ->execute([$title, $cardDue, $isDone, $isArchived, $completedAt, $newColumnId, $cardId]);
            } else {
                $boardId = pdEnsureUserBoard($pdo, $userName);
                $colSql = $isDone
                    ? "SELECT id FROM tasks_columns WHERE board_id = ? AND is_archive_column = 1 ORDER BY sort_order LIMIT 1"
                    : "SELECT id FROM tasks_columns WHERE board_id = ? AND is_done_column = 0 AND is_archive_column = 0 ORDER BY sort_order, id LIMIT 1";
                $c = $pdo->prepare($colSql);
                $c->execute([$boardId]);
                $columnId = (int)$c->fetchColumn();
                if (!$columnId) continue;
                $so = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM tasks_cards WHERE column_id = ? AND parent_card_id IS NULL");
                $so->execute([$columnId]);
                $sortOrder = (int)$so->fetchColumn();
                $pdo->prepare("INSERT INTO tasks_cards (board_id, column_id, title, description, priority, due_date, sort_order, is_done, is_archived, created_by, completed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$boardId, $columnId, $title, null, 'medium', $cardDue, $sortOrder, $isDone, $isArchived, $createdBy, $completedAt]);
                $cardId = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO protocol_decision_cards (decision_id, card_id, user_name) VALUES (?, ?, ?)")
                    ->execute([$decId, $cardId, $userName]);
                $pdo->prepare("INSERT INTO tasks_relations (card_id, entity_type, entity_id, entity_label) VALUES (?, 'protocol', ?, ?)")
                    ->execute([$cardId, (string)$dec['protocol_id'], $entityLabel]);
                $existing[$userName] = $cardId;
            }
            if ($firstCardId === null) $firstCardId = $existing[$userName];
        }
        // Соисполнители: на каждой карточке записываем ОСТАЛЬНЫХ ответственных
        // по этому решению. Дубли на досках предотвращены фильтром в
        // tasks.php (external cards): если у пользователя уже есть СВОЯ копия
        // карточки по этому же protocol_decision_id, то «внешняя» копия с
        // чужой доски НЕ подтягивается.
        $allCardIds = array_values($existing);
        if ($allCardIds) {
            $phC = implode(',', array_fill(0, count($allCardIds), '?'));
            $pdo->prepare("DELETE FROM tasks_assignees WHERE card_id IN ($phC)")->execute($allCardIds);
            foreach ($existing as $ownerName => $cardId) {
                foreach ($users as $other) {
                    if ($other === $ownerName) continue;
                    $pdo->prepare("INSERT IGNORE INTO tasks_assignees (card_id, user_name) VALUES (?, ?)")
                        ->execute([$cardId, $other]);
                }
            }
        }
        if ($firstCardId) {
            $pdo->prepare("UPDATE protocol_decisions SET tasks_card_id = ? WHERE id = ?")->execute([$firstCardId, $decId]);
        } elseif (!$users) {
            $pdo->prepare("UPDATE protocol_decisions SET tasks_card_id = NULL WHERE id = ?")->execute([$decId]);
        }
    }

    function pdArchiveCardForDecision($pdo, $decisionId) {
        $cards = $pdo->prepare("SELECT card_id FROM protocol_decision_cards WHERE decision_id = ?");
        $cards->execute([$decisionId]);
        foreach ($cards->fetchAll() as $r) {
            $cardId = (int)$r['card_id'];
            $b = $pdo->prepare("SELECT board_id FROM tasks_cards WHERE id = ?");
            $b->execute([$cardId]);
            $boardId = (int)$b->fetchColumn();
            if (!$boardId) continue;
            $col = $pdo->prepare("SELECT id FROM tasks_columns WHERE board_id = ? AND is_archive_column = 1 ORDER BY sort_order LIMIT 1");
            $col->execute([$boardId]);
            $arcId = (int)$col->fetchColumn();
            if (!$arcId) continue;
            $pdo->prepare("UPDATE tasks_cards SET column_id = ?, is_done = 1, is_archived = 1, completed_at = COALESCE(completed_at, NOW()) WHERE id = ?")
                ->execute([$arcId, $cardId]);
        }
        $pdo->prepare("DELETE FROM protocol_decision_cards WHERE decision_id = ?")->execute([$decisionId]);
        $pdo->prepare("UPDATE protocol_decisions SET tasks_card_id = NULL WHERE id = ?")->execute([$decisionId]);
    }

    if ($fn === 'save_protocol') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        $id = intval($body['id'] ?? 0);
        // Проверяем права: редактировать может создатель или админ
        if ($id) {
            $existing = $pdo->prepare("SELECT created_by, status as old_status FROM meeting_protocols WHERE id = ?");
            $existing->execute([$id]);
            $row = $existing->fetch();
            if (!$row) respond(['error' => 'Протокол не найден'], 404);
            if ($row['created_by'] !== $caller['name'] && !in_array($caller['role'], ['admin', 'manager'])) {
                if (($ACCESS_LEVELS[$perms['protocols'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['full']) {
                    respond(['error' => 'Редактировать может только создатель или админ'], 403);
                }
            }
        } else {
            if (($ACCESS_LEVELS[$perms['protocols'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
                respond(['error' => 'Недостаточно прав'], 403);
            }
        }
        $meetingDate = $body['meeting_date'] ?? date('Y-m-d');
        $topic = trim($body['topic'] ?? '');
        $participants = $body['participants'] ?? [];
        $questions = trim($body['questions'] ?? '');
        $notes = trim($body['notes'] ?? '');
        $seriesId = $body['series_id'] ?: null;
        $status = $body['status'] ?? 'draft';
        $decisions = $body['decisions'] ?? [];
        $legalEntity = $body['legal_entity'] ?? null;
        if (!$topic) respond(['error' => 'Укажите тему совещания'], 400);
        // Для нового протокола юрлицо обязательно; для существующего — сохраняем исходное
        if (!$id && !$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!$id && !checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к юр. лицу'], 403);

        try {
            $pdo->beginTransaction();
            if ($id) {
                $pdo->prepare("UPDATE meeting_protocols SET series_id=?, meeting_date=?, topic=?, participants=?, questions=?, notes=?, status=?, updated_at=NOW() WHERE id=?")
                    ->execute([$seriesId, $meetingDate, $topic, json_encode($participants, JSON_UNESCAPED_UNICODE), $questions, $notes, $status, $id]);
            } else {
                $pdo->prepare("INSERT INTO meeting_protocols (series_id, meeting_date, topic, legal_entity, participants, questions, notes, status, created_by) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$seriesId, $meetingDate, $topic, $legalEntity, json_encode($participants, JSON_UNESCAPED_UNICODE), $questions, $notes, $status, $caller['name']]);
                $id = $pdo->lastInsertId();
            }
            // Синхронизируем решения
            $existingIds = [];
            $syncDecIds = [];
            foreach ($decisions as $dec) {
                $decId = intval($dec['id'] ?? 0);
                $decText = trim($dec['text'] ?? '');
                $responsible = trim($dec['responsible_person'] ?? '');
                $deadline = $dec['deadline'] ?: null;
                $decStatus = $dec['status'] ?? 'pending';
                $completedAt = $decStatus === 'done' ? ($dec['completed_at'] ?? date('Y-m-d H:i:s')) : null;
                if (!$decText) continue;
                if ($decId) {
                    $pdo->prepare("UPDATE protocol_decisions SET text=?, responsible_person=?, deadline=?, status=?, completed_at=? WHERE id=? AND protocol_id=?")
                        ->execute([$decText, $responsible, $deadline, $decStatus, $completedAt, $decId, $id]);
                    $existingIds[] = $decId;
                    $syncDecIds[] = $decId;
                } else {
                    $pdo->prepare("INSERT INTO protocol_decisions (protocol_id, text, responsible_person, deadline, status, completed_at) VALUES (?,?,?,?,?,?)")
                        ->execute([$id, $decText, $responsible, $deadline, $decStatus, $completedAt]);
                    $newDecId = (int)$pdo->lastInsertId();
                    $existingIds[] = $newDecId;
                    $syncDecIds[] = $newDecId;
                }
            }
            // Удаляем решения, которых больше нет
            if ($existingIds) {
                $ph = implode(',', array_fill(0, count($existingIds), '?'));
                $delQ = $pdo->prepare("SELECT id FROM protocol_decisions WHERE protocol_id = ? AND id NOT IN ($ph)");
                $delQ->execute(array_merge([$id], $existingIds));
                foreach ($delQ->fetchAll() as $rowDel) pdArchiveCardForDecision($pdo, (int)$rowDel['id']);
                $pdo->prepare("DELETE FROM protocol_decisions WHERE protocol_id = ? AND id NOT IN ($ph)")->execute(array_merge([$id], $existingIds));
            } else {
                $delQ = $pdo->prepare("SELECT id FROM protocol_decisions WHERE protocol_id = ?");
                $delQ->execute([$id]);
                foreach ($delQ->fetchAll() as $rowDel) pdArchiveCardForDecision($pdo, (int)$rowDel['id']);
                $pdo->prepare("DELETE FROM protocol_decisions WHERE protocol_id = ?")->execute([$id]);
            }
            $pdo->commit();
            foreach ($syncDecIds as $syncId) pdSyncDecisionToCard($pdo, $syncId, $caller['name']);

            // Telegram-уведомление участникам только при смене статуса на final
            $wasAlreadyFinal = isset($row) && ($row['old_status'] ?? '') === 'final';
            if ($status === 'final' && !$wasAlreadyFinal) {
                notifyProtocolParticipants($pdo, $id, $topic, $meetingDate, $participants, $caller['name']);
            }

            respond(['success' => true, 'id' => $id]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("save_protocol error: " . $e->getMessage());
            respond(['error' => 'Ошибка сохранения'], 500);
        }
    }

    if ($fn === 'delete_protocol') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'protocols', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'id required'], 400);
        $existing = $pdo->prepare("SELECT created_by, legal_entity FROM meeting_protocols WHERE id = ?");
        $existing->execute([$id]);
        $row = $existing->fetch();
        if (!$row) respond(['error' => 'Не найден'], 404);
        if (!checkLegalEntityAccess($caller, $row['legal_entity'] ?? null)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        if ($row['created_by'] !== $caller['name'] && !in_array($caller['role'], ['admin', 'manager'])) {
            respond(['error' => 'Удалить может только создатель или админ'], 403);
        }
        $pdo->prepare("DELETE FROM meeting_protocols WHERE id = ?")->execute([$id]);
        respond(['success' => true]);
    }

    if ($fn === 'update_decision_status') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'protocols', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $decId = intval($body['id'] ?? 0);
        $newStatus = $body['status'] ?? '';
        if (!$decId || !in_array($newStatus, ['pending', 'done', 'overdue'])) respond(['error' => 'Некорректные параметры'], 400);
        // Доступ — на уровне юрлица протокола, к которому относится решение.
        $accCheck = $pdo->prepare("SELECT p.legal_entity FROM protocol_decisions d JOIN meeting_protocols p ON p.id = d.protocol_id WHERE d.id = ?");
        $accCheck->execute([$decId]);
        $protLe = $accCheck->fetchColumn();
        if ($protLe === false) respond(['error' => 'Решение не найдено'], 404);
        if ($protLe && !checkLegalEntityAccess($caller, $protLe)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $completedAt = $newStatus === 'done' ? date('Y-m-d H:i:s') : null;
        $pdo->prepare("UPDATE protocol_decisions SET status = ?, completed_at = ? WHERE id = ?")->execute([$newStatus, $completedAt, $decId]);
        pdSyncDecisionToCard($pdo, $decId, $caller['name']);
        respond(['success' => true]);
    }

    if ($fn === 'update_decision_deadline') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'protocols', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $decId = intval($body['id'] ?? 0);
        $deadline = $body['deadline'] ?: null;
        if (!$decId) respond(['error' => 'id required'], 400);
        if ($deadline && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) respond(['error' => 'Некорректная дата'], 400);
        // Доступ — на уровне юрлица протокола, к которому относится решение.
        $accCheck = $pdo->prepare("SELECT p.legal_entity FROM protocol_decisions d JOIN meeting_protocols p ON p.id = d.protocol_id WHERE d.id = ?");
        $accCheck->execute([$decId]);
        $protLe = $accCheck->fetchColumn();
        if ($protLe === false) respond(['error' => 'Решение не найдено'], 404);
        if ($protLe && !checkLegalEntityAccess($caller, $protLe)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $pdo->prepare("UPDATE protocol_decisions SET deadline = ? WHERE id = ?")->execute([$deadline, $decId]);
        pdSyncDecisionToCard($pdo, $decId, $caller['name']);
        respond(['success' => true]);
    }

    // Серии совещаний
    if ($fn === 'get_carryover_tasks') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'protocols', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $seriesId = intval($body['series_id'] ?? 0);
        $excludeProtocolId = intval($body['exclude_protocol_id'] ?? 0);
        if (!$seriesId) respond([]);
        // Доступ — на уровне юрлица серии.
        $sLeStmt = $pdo->prepare("SELECT legal_entity FROM meeting_protocol_series WHERE id = ?");
        $sLeStmt->execute([$seriesId]);
        $sLe = $sLeStmt->fetchColumn();
        if ($sLe === false) respond([]);
        if ($sLe && !checkLegalEntityAccess($caller, $sLe)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);

        // Берём ровно один предыдущий протокол серии: с ближайшей более
        // ранней датой (при равных — с меньшим id), чтобы порядок «свежесть»
        // совпадал с UI-сортировкой. Возвращаем ВСЕ его задачи независимо
        // от статуса — пользователь сам решит, что закрывать/переносить.
        $prevSql = "SELECT id FROM meeting_protocols WHERE series_id = ?";
        $prevParams = [$seriesId];
        if ($excludeProtocolId) {
            $cur = $pdo->prepare("SELECT meeting_date FROM meeting_protocols WHERE id = ?");
            $cur->execute([$excludeProtocolId]);
            $curDate = $cur->fetchColumn();
            if ($curDate !== false) {
                $prevSql .= " AND (meeting_date < ? OR (meeting_date = ? AND id < ?))";
                $prevParams[] = $curDate;
                $prevParams[] = $curDate;
                $prevParams[] = $excludeProtocolId;
            } else {
                $prevSql .= " AND id != ?";
                $prevParams[] = $excludeProtocolId;
            }
        }
        $prevSql .= " ORDER BY meeting_date DESC, id DESC LIMIT 1";
        $ps = $pdo->prepare($prevSql);
        $ps->execute($prevParams);
        $prevProtoId = $ps->fetchColumn();
        if (!$prevProtoId) respond([]);

        $s = $pdo->prepare("
            SELECT d.id, d.text, d.responsible_person, d.deadline, d.status, d.protocol_id, d.tasks_card_id,
                   p.meeting_date, p.topic
            FROM protocol_decisions d
            JOIN meeting_protocols p ON p.id = d.protocol_id
            WHERE d.protocol_id = ?
            ORDER BY d.id
        ");
        $s->execute([$prevProtoId]);
        $decisions = $s->fetchAll();
        pdAttachCardDescription($pdo, $decisions);
        pdAttachAssigneesProgress($pdo, $decisions);
        respond($decisions);
    }

    if ($fn === 'get_protocol_series') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'protocols', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $legalEntity = $body['legal_entity'] ?? $_GET['legal_entity'] ?? null;
        if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
        if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к данному юр. лицу'], 403);
        $s = $pdo->prepare("SELECT s.*, (SELECT COUNT(*) FROM meeting_protocols p WHERE p.series_id = s.id) as protocols_count FROM meeting_protocol_series s WHERE s.legal_entity = ? ORDER BY s.name");
        $s->execute([$legalEntity]);
        respond($s->fetchAll());
    }

    if ($fn === 'save_protocol_series') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        $perms = resolvePermissions($caller['role'], $caller['permissions'] ?? null, $ROLE_TEMPLATES);
        if (($ACCESS_LEVELS[$perms['protocols'] ?? 'none'] ?? 0) < $ACCESS_LEVELS['edit']) {
            respond(['error' => 'Недостаточно прав'], 403);
        }
        $id = intval($body['id'] ?? 0);
        $name = trim($body['name'] ?? '');
        $recurrence = $body['recurrence'] ?? 'weekly';
        $agendaTemplate = $body['agenda_template'] ?? [];
        $legalEntity = $body['legal_entity'] ?? null;
        if (!$name) respond(['error' => 'Укажите название серии'], 400);
        if ($id) {
            // Обновление существующей: проверяем доступ к её фактическому legal_entity.
            $existing = $pdo->prepare("SELECT legal_entity FROM meeting_protocol_series WHERE id = ?");
            $existing->execute([$id]);
            $existingLe = $existing->fetchColumn();
            if ($existingLe === false) respond(['error' => 'Серия не найдена'], 404);
            if (!checkLegalEntityAccess($caller, $existingLe)) respond(['error' => 'Нет доступа к юр. лицу'], 403);
        } else {
            if (!$legalEntity) respond(['error' => 'Не указано юр. лицо'], 400);
            if (!checkLegalEntityAccess($caller, $legalEntity)) respond(['error' => 'Нет доступа к юр. лицу'], 403);
        }
        $agendaJson = json_encode($agendaTemplate, JSON_UNESCAPED_UNICODE);
        if ($id) {
            $pdo->prepare("UPDATE meeting_protocol_series SET name=?, recurrence=?, agenda_template=? WHERE id=?")->execute([$name, $recurrence, $agendaJson, $id]);
        } else {
            $pdo->prepare("INSERT INTO meeting_protocol_series (name, legal_entity, recurrence, agenda_template, created_by) VALUES (?,?,?,?,?)")->execute([$name, $legalEntity, $recurrence, $agendaJson, $caller['name']]);
            $id = $pdo->lastInsertId();
        }
        respond(['success' => true, 'id' => $id]);
    }

    if ($fn === 'delete_protocol_series') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'protocols', 'full', $ROLE_TEMPLATES, $ACCESS_LEVELS);
        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'id required'], 400);
        $pdo->prepare("DELETE FROM meeting_protocol_series WHERE id = ?")->execute([$id]);
        respond(['success' => true]);
    }

    if ($fn === 'get_users_list_short') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        // telegram_chat_id — персональные данные, отдаём только admin/manager
        // (нужны для упоминаний и Telegram-уведомлений). Остальным — без него.
        $isPriv = in_array($caller['role'] ?? '', ['admin', 'manager'], true);
        $cols = $isPriv ? "name, display_role, telegram_chat_id" : "name, display_role";
        $s = $pdo->query("SELECT {$cols} FROM users ORDER BY name");
        respond($s->fetchAll());
    }

    // ═══════════════════════════════════════════════════════════════
    // МОДУЛЬ ОПРОСОВ (surveys)
    // ═══════════════════════════════════════════════════════════════

    if (!function_exists('surveyGetTargetRestaurants')) {
        function surveyGetTargetRestaurants($pdo, $group) {
            $group = in_array($group, ['BK_VM', 'PS'], true) ? $group : 'BK_VM';
            $stmt = $pdo->prepare("
                SELECT r.number AS restaurant_number, r.legal_entity_group, r.address, r.city
                FROM restaurants r
                WHERE r.active = 1
                  AND r.legal_entity_group COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
                  AND (
                    EXISTS (
                        SELECT 1
                        FROM ro_users ru
                        WHERE ru.restaurant_number = r.number
                          AND ru.is_active = 1
                          AND ru.legal_entity_group COLLATE utf8mb4_unicode_ci = r.legal_entity_group COLLATE utf8mb4_unicode_ci
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM ro_telegram_subs vs
                        WHERE vs.restaurant_number = r.number
                    )
                  )
                ORDER BY r.number
            ");
            $stmt->execute([$group]);
            return $stmt->fetchAll();
        }
    }

    if (!function_exists('surveyCountTargetsByGroup')) {
        function surveyCountTargetsByGroup($pdo) {
            $rows = $pdo->query("
                SELECT r.legal_entity_group AS grp, COUNT(*) AS cnt
                FROM restaurants r
                WHERE r.active = 1
                  AND (
                    EXISTS (
                        SELECT 1
                        FROM ro_users ru
                        WHERE ru.restaurant_number = r.number
                          AND ru.is_active = 1
                          AND ru.legal_entity_group COLLATE utf8mb4_unicode_ci = r.legal_entity_group COLLATE utf8mb4_unicode_ci
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM ro_telegram_subs vs
                        WHERE vs.restaurant_number = r.number
                    )
                  )
                GROUP BY r.legal_entity_group
            ")->fetchAll();
            $out = ['BK_VM' => 0, 'PS' => 0];
            foreach ($rows as $r) {
                $g = $r['grp'] ?? '';
                if (isset($out[$g])) $out[$g] = (int)$r['cnt'];
            }
            return $out;
        }
    }

    if (!function_exists('surveyGetRecipientChatIds')) {
        function surveyGetRecipientChatIds($pdo, $group) {
            $group = in_array($group, ['BK_VM', 'PS'], true) ? $group : 'BK_VM';
            $chatIds = [];

            $roStmt = $pdo->prepare("
                SELECT DISTINCT rs.chat_id AS telegram_chat_id
                FROM ro_telegram_subs rs
                JOIN restaurants r
                  ON r.number = rs.restaurant_number
                 AND r.active = 1
                 AND r.legal_entity_group COLLATE utf8mb4_unicode_ci = rs.legal_entity_group COLLATE utf8mb4_unicode_ci
                WHERE rs.legal_entity_group COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
                  AND rs.chat_id IS NOT NULL
                  AND (rs.verified_at IS NOT NULL
                       OR (rs.must_reverify_by IS NOT NULL AND rs.must_reverify_by > NOW()))
            ");
            $roStmt->execute([$group]);
            foreach ($roStmt->fetchAll(PDO::FETCH_COLUMN) as $chatId) {
                $chatId = trim((string)$chatId);
                if ($chatId !== '') $chatIds[$chatId] = true;
            }

            return array_keys($chatIds);
        }
    }
