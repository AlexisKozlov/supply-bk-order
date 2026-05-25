<?php
/**
 * RPC: опросы.
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName,
 * $ROLE_TEMPLATES, $ACCESS_LEVELS, $clientIp.
 */

    if ($fn === 'surveys_list') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'surveys', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $rows = $pdo->query("
            SELECT s.id, s.title, s.legal_entity_group, s.status, s.allow_comment,
                   s.remind_after_hours, s.sent_at, s.created_by, s.created_at, s.closed_at,
                   (SELECT COUNT(*) FROM survey_questions sq WHERE sq.survey_id = s.id) AS questions_count,
                   (SELECT COUNT(*) FROM survey_responses sr WHERE sr.survey_id = s.id) AS responses_count
            FROM surveys s
            ORDER BY s.created_at DESC
        ")->fetchAll();

        $targetCounts = surveyCountTargetsByGroup($pdo);
        foreach ($rows as &$row) {
            $group = $row['legal_entity_group'] ?? 'BK_VM';
            $row['target_restaurants_count'] = (int)($targetCounts[$group] ?? 0);
        }
        unset($row);

        respond($rows);
    }

    if ($fn === 'survey_get') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'surveys', 'view', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'id required'], 400);

        $survey = $pdo->prepare("SELECT * FROM surveys WHERE id = ?");
        $survey->execute([$id]);
        $s = $survey->fetch();
        if (!$s) respond(['error' => 'Не найдено'], 404);

        // Вопросы + опции одним запросом
        $qRowsStmt = $pdo->prepare("
            SELECT sq.id AS question_id, sq.text AS question_text, sq.type AS question_type, sq.sort_order AS question_sort,
                   sq.files_required AS question_files_required,
                   so.id AS option_id, so.text AS option_text, so.sort_order AS option_sort
            FROM survey_questions sq
            LEFT JOIN survey_options so ON so.question_id = sq.id
            WHERE sq.survey_id = ?
            ORDER BY sq.sort_order, sq.id, so.sort_order, so.id
        ");
        $qRowsStmt->execute([$id]);
        $questionsMap = [];
        foreach ($qRowsStmt->fetchAll() as $row) {
            $qid = (int)$row['question_id'];
            if (!isset($questionsMap[$qid])) {
                $questionsMap[$qid] = [
                    'id' => $qid,
                    'text' => $row['question_text'],
                    'type' => $row['question_type'] ?: 'choice',
                    'files_required' => (int)$row['question_files_required'] === 1,
                    'sort_order' => (int)$row['question_sort'],
                    'options' => [],
                ];
            }
            if (!empty($row['option_id'])) {
                $questionsMap[$qid]['options'][] = [
                    'id' => (int)$row['option_id'],
                    'text' => $row['option_text'],
                    'sort_order' => (int)$row['option_sort'],
                ];
            }
        }
        $s['questions'] = array_values($questionsMap);

        // Ответы (шапка) одним запросом
        $respStmt = $pdo->prepare("
            SELECT sr.id, sr.restaurant_number, sr.legal_entity_group, sr.comment, sr.submitted_at,
                   sr.telegram_chat_id, r.address, r.city
            FROM survey_responses sr
            LEFT JOIN restaurants r
              ON r.number = sr.restaurant_number
             AND r.legal_entity_group COLLATE utf8mb4_unicode_ci = sr.legal_entity_group COLLATE utf8mb4_unicode_ci
            WHERE sr.survey_id = ?
            ORDER BY sr.restaurant_number ASC, sr.submitted_at DESC
        ");
        $respStmt->execute([$id]);
        $respRows = $respStmt->fetchAll();

        // Детали ответов одним запросом
        $ansStmt = $pdo->prepare("
            SELECT sa.response_id, sa.question_id, sq.text AS question_text, sq.type,
                   sa.option_id, so.text AS option_text, sa.numeric_value, sa.text_value, sq.sort_order AS q_sort
            FROM survey_answers sa
            JOIN survey_responses sr ON sr.id = sa.response_id
            JOIN survey_questions sq ON sq.id = sa.question_id
            LEFT JOIN survey_options so ON so.id = sa.option_id
            WHERE sr.survey_id = ?
            ORDER BY sq.sort_order, sq.id
        ");
        $ansStmt->execute([$id]);
        $ansByResp = [];
        $optionCounts = [];
        foreach ($ansStmt->fetchAll() as $row) {
            $rid = (int)$row['response_id'];
            $ansByResp[$rid] ??= [];
            $ansByResp[$rid][] = [
                'question_id' => (int)$row['question_id'],
                'question_text' => $row['question_text'],
                'type' => $row['type'] ?: 'choice',
                'option_id' => $row['option_id'] !== null ? (int)$row['option_id'] : null,
                'option_text' => $row['option_text'],
                'numeric_value' => $row['numeric_value'] !== null ? (int)$row['numeric_value'] : null,
                'text_value' => $row['text_value'],
            ];
            if (($row['type'] ?? 'choice') === 'choice' && $row['option_id'] !== null) {
                $oid = (int)$row['option_id'];
                $optionCounts[$oid] = ($optionCounts[$oid] ?? 0) + 1;
            }
        }
        // Файлы по ответам — одним запросом.
        $filesByResp = [];
        $filesStmt = $pdo->prepare("
            SELECT response_id, id, question_id, file_path, file_name, mime_type, file_size, created_at
            FROM survey_response_files
            WHERE response_id IN (SELECT id FROM survey_responses WHERE survey_id = ?)
            ORDER BY id
        ");
        $filesStmt->execute([$id]);
        foreach ($filesStmt->fetchAll() as $row) {
            $rid = (int)$row['response_id'];
            $filesByResp[$rid] ??= [];
            $filesByResp[$rid][] = [
                'id'          => (int)$row['id'],
                'question_id' => (int)$row['question_id'],
                'file_name'   => $row['file_name'],
                'mime_type'   => $row['mime_type'],
                'file_size'   => (int)$row['file_size'],
                'created_at'  => $row['created_at'],
                'url'         => '/api/' . ltrim((string)$row['file_path'], '/'),
            ];
        }
        foreach ($respRows as &$r) {
            $r['answers'] = $ansByResp[(int)$r['id']] ?? [];
            $r['files'] = $filesByResp[(int)$r['id']] ?? [];
        }
        unset($r);
        $s['responses'] = $respRows;

        // Аналитика по вариантам / шкале
        $totalResponses = count($respRows);
        foreach ($s['questions'] as &$q) {
            if (($q['type'] ?? 'choice') === 'scale') {
                $scaleCounts = array_fill(1, 10, 0);
                $sum = 0;
                $totalForQ = 0;
                foreach ($ansByResp as $answers) {
                    foreach ($answers as $answer) {
                        if ((int)$answer['question_id'] !== (int)$q['id']) continue;
                        $score = (int)($answer['numeric_value'] ?? 0);
                        if ($score < 1 || $score > 10) continue;
                        $scaleCounts[$score]++;
                        $sum += $score;
                        $totalForQ++;
                    }
                }
                $q['options'] = [];
                for ($score = 1; $score <= 10; $score++) {
                    $cnt = (int)$scaleCounts[$score];
                    $q['options'][] = [
                        'id' => $score,
                        'text' => (string)$score,
                        'responses_count' => $cnt,
                        'responses_percent' => $totalForQ > 0 ? round($cnt * 100 / $totalForQ) : 0,
                    ];
                }
                $q['responses_total'] = $totalForQ;
                $q['average_score'] = $totalForQ > 0 ? round($sum / $totalForQ, 1) : null;
            } elseif (($q['type'] ?? 'choice') === 'text') {
                $totalForQ = 0;
                foreach ($ansByResp as $answers) {
                    foreach ($answers as $answer) {
                        if ((int)$answer['question_id'] === (int)$q['id'] && trim((string)($answer['text_value'] ?? '')) !== '') {
                            $totalForQ++;
                        }
                    }
                }
                $q['options'] = [];
                $q['responses_total'] = $totalForQ;
            } else {
                $totalForQ = 0;
                foreach ($q['options'] as $opt) {
                    $totalForQ += (int)($optionCounts[(int)$opt['id']] ?? 0);
                }
                foreach ($q['options'] as &$opt) {
                    $cnt = (int)($optionCounts[(int)$opt['id']] ?? 0);
                    $opt['responses_count'] = $cnt;
                    $opt['responses_percent'] = $totalForQ > 0 ? round($cnt * 100 / $totalForQ) : 0;
                }
                unset($opt);
                $q['responses_total'] = $totalForQ;
            }
        }
        unset($q);

        // Не ответили
        $answered = [];
        foreach ($respRows as $r) {
            $answered[(int)$r['restaurant_number']] = true;
        }
        $pendingRows = [];
        foreach (surveyGetTargetRestaurants($pdo, $s['legal_entity_group']) as $restaurant) {
            if (!isset($answered[(int)$restaurant['restaurant_number']])) {
                $pendingRows[] = $restaurant;
            }
        }
        $s['pending_restaurants'] = $pendingRows;
        $s['target_restaurants_count'] = count($pendingRows) + $totalResponses;

        respond($s);
    }

    if ($fn === 'survey_save') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'surveys', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $id    = intval($body['id'] ?? 0);
        $title = trim($body['title'] ?? '');
        $group = in_array($body['legal_entity_group'] ?? '', ['BK_VM','PS']) ? $body['legal_entity_group'] : 'BK_VM';
        $desc  = trim($body['description'] ?? '');
        $allowComment = isset($body['allow_comment']) ? (int)(bool)$body['allow_comment'] : 1;
        $remindHours  = max(1, intval($body['remind_after_hours'] ?? 24));
        $questions = is_array($body['questions'] ?? null) ? $body['questions'] : [];

        if (!$title) respond(['error' => 'Нужен заголовок'], 400);

        $normalizedQuestions = [];
        foreach ($questions as $q) {
            $qText = trim($q['text'] ?? '');
            if ($qText === '') continue;
            $qType = in_array($q['type'] ?? 'choice', ['choice', 'scale', 'text', 'files'], true) ? $q['type'] : 'choice';

            $normalizedOptions = [];
            if ($qType === 'choice') {
                foreach (($q['options'] ?? []) as $opt) {
                    $optText = trim($opt['text'] ?? '');
                    if ($optText !== '') {
                        $normalizedOptions[] = $optText;
                    }
                }

                if (count($normalizedOptions) < 2) {
                    respond(['error' => 'У вопроса с вариантами должно быть минимум 2 варианта ответа'], 400);
                }
            }

            $filesRequired = 1; // default ВКЛ
            if ($qType === 'files' && array_key_exists('files_required', $q)) {
                $filesRequired = (int)(bool)$q['files_required'];
            }

            $normalizedQuestions[] = [
                'text' => $qText,
                'type' => $qType,
                'files_required' => $filesRequired,
                'options' => $normalizedOptions,
            ];
        }

        if (empty($normalizedQuestions)) respond(['error' => 'Нужен хотя бы один вопрос'], 400);

        if ($id) {
            $chk = $pdo->prepare("SELECT status FROM surveys WHERE id = ?");
            $chk->execute([$id]);
            $row = $chk->fetch();
            if (!$row) respond(['error' => 'Не найдено'], 404);
            if ($row['status'] !== 'draft') respond(['error' => 'Редактировать можно только черновик'], 400);
        }

        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare("UPDATE surveys SET title=?, description=?, legal_entity_group=?, allow_comment=?, remind_after_hours=? WHERE id=?")
                    ->execute([$title, $desc, $group, $allowComment, $remindHours, $id]);
                $pdo->prepare("DELETE FROM survey_questions WHERE survey_id = ?")->execute([$id]);
            } else {
                $createdBy = trim((string)($caller['name'] ?? $caller['login'] ?? $caller['email'] ?? 'system'));
                if ($createdBy === '') $createdBy = 'system';
                $pdo->prepare("INSERT INTO surveys (title, description, legal_entity_group, allow_comment, remind_after_hours, created_by) VALUES (?,?,?,?,?,?)")
                    ->execute([$title, $desc, $group, $allowComment, $remindHours, $createdBy]);
                $id = (int)$pdo->lastInsertId();
            }

            foreach ($normalizedQuestions as $qi => $q) {
                $pdo->prepare("INSERT INTO survey_questions (survey_id, text, type, files_required, sort_order) VALUES (?,?,?,?,?)")
                    ->execute([$id, $q['text'], $q['type'], $q['files_required'] ?? 1, $qi]);
                $qId = (int)$pdo->lastInsertId();
                foreach ($q['options'] as $oi => $optText) {
                    $pdo->prepare("INSERT INTO survey_options (question_id, text, sort_order) VALUES (?,?,?)")
                        ->execute([$qId, $optText, $oi]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            respond(['error' => $e->getMessage()], 500);
        }

        respond(['success' => true, 'id' => $id]);
    }

    if ($fn === 'survey_send') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'surveys', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'id required'], 400);

        $survey = $pdo->prepare("SELECT * FROM surveys WHERE id = ?");
        $survey->execute([$id]);
        $s = $survey->fetch();
        if (!$s) respond(['error' => 'Не найдено'], 404);
        if ($s['status'] !== 'draft') respond(['error' => 'Можно разослать только черновик'], 400);

        $qCountStmt = $pdo->prepare("SELECT COUNT(*) FROM survey_questions WHERE survey_id = ?");
        $qCountStmt->execute([$id]);
        $qCount = (int)$qCountStmt->fetchColumn();
        if (!$qCount) respond(['error' => 'Нет вопросов'], 400);

        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if (!$botToken) respond(['error' => 'Нет TELEGRAM_BOT_TOKEN'], 500);

        $chatIds = surveyGetRecipientChatIds($pdo, $s['legal_entity_group']);

        if (empty($chatIds)) respond(['error' => 'Нет подписчиков в этой группе'], 400);

        $safeTitle = htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8');
        $sent = 0;

        foreach ($chatIds as $cid) {
            $text = "📋 <b>Опрос: {$safeTitle}</b>\n\n";
            if ($s['description']) $text .= htmlspecialchars($s['description'], ENT_QUOTES, 'UTF-8') . "\n\n";
            $text .= "Нажмите кнопку, чтобы пройти опрос.";

            $btns = ['inline_keyboard' => [
                [['text' => '📝 Пройти опрос', 'callback_data' => "srv_start_{$id}"]],
            ]];

            $data = json_encode(['chat_id' => $cid, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => json_encode($btns)]);
            $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $data, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 5]);
            $res = curl_exec($ch); curl_close($ch);
            $r = json_decode($res, true);
            if ($r && ($r['ok'] ?? false)) $sent++;
        }

        if ($sent <= 0) respond(['error' => 'Не удалось отправить опрос ни одному получателю'], 500);

        $pdo->prepare("UPDATE surveys SET status = 'active', sent_at = NOW() WHERE id = ?")->execute([$id]);

        respond(['success' => true, 'sent' => $sent, 'total' => count($chatIds)]);
    }

    if ($fn === 'survey_close') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'surveys', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'id required'], 400);
        $pdo->prepare("UPDATE surveys SET status = 'closed', closed_at = NOW() WHERE id = ?")->execute([$id]);
        respond(['success' => true]);
    }

    if ($fn === 'survey_response_delete') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'surveys', 'edit', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $responseId = intval($body['id'] ?? 0);
        $surveyId = intval($body['survey_id'] ?? 0);
        if (!$responseId) respond(['error' => 'id required'], 400);

        $stmt = $pdo->prepare("
            SELECT id, survey_id
            FROM survey_responses
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$responseId]);
        $response = $stmt->fetch();
        if (!$response) respond(['error' => 'Ответ не найден'], 404);
        if ($surveyId && (int)$response['survey_id'] !== $surveyId) {
            respond(['error' => 'Ответ не относится к этому опросу'], 400);
        }

        // Сначала собираем файлы — после CASCADE DELETE строки в БД исчезнут,
        // а на диске останутся «осиротевшими».
        $filesStmt = $pdo->prepare("SELECT file_path FROM survey_response_files WHERE response_id = ?");
        $filesStmt->execute([$responseId]);
        $filesToUnlink = $filesStmt->fetchAll(PDO::FETCH_COLUMN);

        $pdo->prepare("DELETE FROM survey_responses WHERE id = ?")->execute([$responseId]);

        foreach ($filesToUnlink as $rel) {
            $abs = __DIR__ . '/../' . ltrim((string)$rel, '/');
            if (is_file($abs)) @unlink($abs);
        }
        respond(['success' => true]);
    }

    if ($fn === 'survey_delete') {
        $caller = getSessionUser($pdo);
        if (!$caller) respond(['error' => 'Требуется авторизация'], 401);
        requireModuleAccess($caller, 'surveys', 'full', $ROLE_TEMPLATES, $ACCESS_LEVELS);

        $id = intval($body['id'] ?? 0);
        if (!$id) respond(['error' => 'id required'], 400);

        // Соберём пути файлов до CASCADE — иначе после DELETE строки исчезнут,
        // а файлы останутся болтаться на диске.
        $filesStmt = $pdo->prepare("SELECT file_path FROM survey_response_files WHERE survey_id = ?");
        $filesStmt->execute([$id]);
        $filesToUnlink = $filesStmt->fetchAll(PDO::FETCH_COLUMN);

        $pdo->prepare("DELETE FROM surveys WHERE id = ?")->execute([$id]);

        foreach ($filesToUnlink as $rel) {
            $abs = __DIR__ . '/../' . ltrim((string)$rel, '/');
            if (is_file($abs)) @unlink($abs);
        }
        respond(['success' => true]);
    }
