<?php
// ═══ Опросы для ресторанов через Telegram-бот ═══

// Состояние опроса — в tg_state с TTL 2 часа (7200 сек), как было через
// filemtime у файлов /tmp/survey_*.json.

function surveyLoadState($chatId) {
    return tgStateGet($chatId, 'survey');
}

function surveySaveState($chatId, $state) {
    tgStateSet($chatId, 'survey', $state, 7200);
}

function surveyClearState($chatId) {
    tgStateClear($chatId, 'survey');
}

function surveyGetActiveSurvey($surveyId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM surveys WHERE id = ? AND status = 'active'");
    $stmt->execute([$surveyId]);
    return $stmt->fetch();
}

function surveyGetChatRestaurants($chatId, $group) {
    global $pdo;

    $restaurants = [];

    foreach (botGetSubscribedRestaurants($pdo, $chatId) as $sub) {
        $subGroup = $sub['legal_entity_group'] ?: botGetRestaurantGroupByNumber($pdo, $sub['restaurant_number']);
        if ($subGroup !== $group) continue;
        $key = $subGroup . ':' . $sub['restaurant_number'];
        $restaurants[$key] = [
            'restaurant_number' => (int)$sub['restaurant_number'],
            'legal_entity_group' => $subGroup,
            'address' => $sub['address'] ?? '',
            'city' => $sub['city'] ?? '',
        ];
    }

    // Раньше мы читали ro_users.telegram_chat_id, но это поле теперь не источник правды
    // (один сотрудник может иметь свою привязку, а у учётки колонка одна на всех).
    // Берём подписки текущего chat_id из ro_telegram_subs.
    $roUsers = $pdo->prepare("
        SELECT rs.restaurant_number, rs.legal_entity_group, r.address, r.city
        FROM ro_telegram_subs rs
        LEFT JOIN restaurants r
          ON r.number = rs.restaurant_number
         AND r.legal_entity_group COLLATE utf8mb4_unicode_ci = rs.legal_entity_group COLLATE utf8mb4_unicode_ci
         AND r.active = 1
        WHERE rs.chat_id = ?
          AND rs.legal_entity_group COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
          AND (rs.verified_at IS NOT NULL
               OR (rs.must_reverify_by IS NOT NULL AND rs.must_reverify_by > NOW()))
    ");
    $roUsers->execute([(int)$chatId, $group]);

    foreach ($roUsers->fetchAll() as $row) {
        $rowGroup = $row['legal_entity_group'] ?: $group;
        $key = $rowGroup . ':' . $row['restaurant_number'];
        if (!isset($restaurants[$key])) {
            $restaurants[$key] = [
                'restaurant_number' => (int)$row['restaurant_number'],
                'legal_entity_group' => $rowGroup,
                'address' => $row['address'] ?? '',
                'city' => $row['city'] ?? '',
            ];
        }
    }

    usort($restaurants, function ($a, $b) {
        return ((int)$a['restaurant_number']) <=> ((int)$b['restaurant_number']);
    });

    return array_values($restaurants);
}

function surveyGetPendingRestaurants($surveyId, $chatId, $group) {
    global $pdo;

    $allRestaurants = surveyGetChatRestaurants($chatId, $group);
    if (!$allRestaurants) return [];

    $answeredStmt = $pdo->prepare("
        SELECT restaurant_number
        FROM survey_responses
        WHERE survey_id = ?
          AND legal_entity_group COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
    ");
    $answeredStmt->execute([$surveyId, $group]);
    $answered = array_flip(array_map('intval', $answeredStmt->fetchAll(PDO::FETCH_COLUMN)));

    $pending = [];
    foreach ($allRestaurants as $restaurant) {
        if (!isset($answered[(int)$restaurant['restaurant_number']])) {
            $pending[] = $restaurant;
        }
    }

    return $pending;
}

function surveyShowRestaurantPicker($chatId, $msgId, $survey, $restaurants) {
    $safeTitle = htmlspecialchars($survey['title'], ENT_QUOTES, 'UTF-8');
    $text = "📋 <b>{$safeTitle}</b>\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    $text .= "Выберите ресторан, за который хотите ответить:";

    $buttons = [];
    foreach ($restaurants as $restaurant) {
        $label = botFormatSubscribedRestaurant($restaurant['restaurant_number'], $restaurant['legal_entity_group']);
        $addr = trim(($restaurant['city'] ?? '') . ' ' . ($restaurant['address'] ?? ''));
        if ($addr !== '') {
            $label .= ' • ' . mb_substr($addr, 0, 28);
        }
        $buttons[] = [[
            'text' => $label,
            'callback_data' => "srv_rest_{$survey['id']}_{$restaurant['restaurant_number']}",
        ]];
    }
    $buttons[] = [['text' => '◂ Меню', 'callback_data' => 'rest_my_subs']];

    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $buttons]);
}

function surveyStartForRestaurant($chatId, $msgId, $survey, $restaurant) {
    global $pdo;

    $questionStmt = $pdo->prepare("
        SELECT id
        FROM survey_questions
        WHERE survey_id = ?
        ORDER BY sort_order, id
        LIMIT 1
    ");
    $questionStmt->execute([$survey['id']]);
    $firstQuestionId = (int)$questionStmt->fetchColumn();

    if (!$firstQuestionId) {
        editMessage($chatId, $msgId, "❌ Опрос не содержит вопросов.", ['inline_keyboard' => [
            [['text' => '◂ Меню', 'callback_data' => 'rest_my_subs']],
        ]]);
        return;
    }

    $state = [
        'survey_id' => (int)$survey['id'],
        'survey_title' => $survey['title'],
        'legal_entity_group' => $survey['legal_entity_group'],
        'restaurant_number' => (int)$restaurant['restaurant_number'],
        'allow_comment' => (int)$survey['allow_comment'],
        'answers' => [],
        'step' => 'question',
    ];
    surveySaveState($chatId, $state);

    surveyShowQuestion($chatId, $msgId, (int)$survey['id'], $firstQuestionId, $state);
}

function surveyStart($chatId, $msgId, $surveyId) {
    $survey = surveyGetActiveSurvey($surveyId);
    if (!$survey) {
        editMessage($chatId, $msgId, "❌ Этот опрос уже закрыт или не найден.", ['inline_keyboard' => [
            [['text' => '◂ Меню', 'callback_data' => 'rest_my_subs']],
        ]]);
        return;
    }

    $allRestaurants = surveyGetChatRestaurants($chatId, $survey['legal_entity_group']);
    if (!$allRestaurants) {
        editMessage($chatId, $msgId, "❌ Для этого опроса у вас не найдено ресторанов нужного юрлица.", ['inline_keyboard' => [
            [['text' => '◂ Меню', 'callback_data' => 'rest_my_subs']],
        ]]);
        return;
    }

    $pendingRestaurants = surveyGetPendingRestaurants($surveyId, $chatId, $survey['legal_entity_group']);
    if (!$pendingRestaurants) {
        editMessage($chatId, $msgId, "✅ По всем вашим ресторанам в этом опросе ответы уже записаны.", ['inline_keyboard' => [
            [['text' => '◂ Меню', 'callback_data' => 'rest_my_subs']],
        ]]);
        return;
    }

    if (count($pendingRestaurants) > 1) {
        surveyShowRestaurantPicker($chatId, $msgId, $survey, $pendingRestaurants);
        return;
    }

    surveyStartForRestaurant($chatId, $msgId, $survey, $pendingRestaurants[0]);
}

function surveySelectRestaurant($chatId, $msgId, $surveyId, $restaurantNumber) {
    $survey = surveyGetActiveSurvey($surveyId);
    if (!$survey) {
        editMessage($chatId, $msgId, "❌ Этот опрос уже закрыт или не найден.", ['inline_keyboard' => [
            [['text' => '◂ Меню', 'callback_data' => 'rest_my_subs']],
        ]]);
        return;
    }

    $pendingRestaurants = surveyGetPendingRestaurants($surveyId, $chatId, $survey['legal_entity_group']);
    foreach ($pendingRestaurants as $restaurant) {
        if ((int)$restaurant['restaurant_number'] === (int)$restaurantNumber) {
            surveyStartForRestaurant($chatId, $msgId, $survey, $restaurant);
            return;
        }
    }

    surveyStart($chatId, $msgId, $surveyId);
}

function surveyShowQuestion($chatId, $msgId, $surveyId, $questionId, $state) {
    global $pdo;

    $questionStmt = $pdo->prepare("
        SELECT id, text, type, sort_order
        FROM survey_questions
        WHERE id = ? AND survey_id = ?
    ");
    $questionStmt->execute([$questionId, $surveyId]);
    $question = $questionStmt->fetch();
    if (!$question) return;

    $type = $question['type'] ?: 'choice';
    $options = [];
    if ($type === 'choice') {
        $optionsStmt = $pdo->prepare("
            SELECT id, text
            FROM survey_options
            WHERE question_id = ?
            ORDER BY sort_order, id
        ");
        $optionsStmt->execute([$questionId]);
        $options = $optionsStmt->fetchAll();
    }

    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM survey_questions WHERE survey_id = ?");
    $totalStmt->execute([$surveyId]);
    $total = (int)$totalStmt->fetchColumn();

    $numStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM survey_questions
        WHERE survey_id = ?
          AND (
                sort_order < ?
             OR (sort_order = ? AND id <= ?)
          )
    ");
    $numStmt->execute([$surveyId, $question['sort_order'], $question['sort_order'], $questionId]);
    $num = (int)$numStmt->fetchColumn();

    $safeTitle = htmlspecialchars($state['survey_title'], ENT_QUOTES, 'UTF-8');
    $safeQuestion = htmlspecialchars($question['text'], ENT_QUOTES, 'UTF-8');
    $restaurantLabel = botFormatSubscribedRestaurant($state['restaurant_number'], $state['legal_entity_group']);

    $text = "📋 <b>{$safeTitle}</b>\n";
    $text .= "🏪 {$restaurantLabel}\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "Вопрос {$num} из {$total}:\n\n";
    $text .= "<b>{$safeQuestion}</b>";

    $buttons = [];
    if ($type === 'scale') {
        for ($i = 1; $i <= 10; $i += 5) {
            $row = [];
            for ($score = $i; $score < $i + 5; $score++) {
                $row[] = [
                    'text' => (string)$score,
                    'callback_data' => "srv_ans_{$surveyId}_{$questionId}_{$score}",
                ];
            }
            $buttons[] = $row;
        }
    } elseif ($type === 'text') {
        $state['step'] = 'text_question';
        $state['current_question_id'] = (int)$questionId;
        $state['question_msg_id'] = $msgId;
        surveySaveState($chatId, $state);
        $text .= "\n\nНапишите ответ сообщением.";
    } else {
        foreach ($options as $option) {
            $buttons[] = [[
                'text' => $option['text'],
                'callback_data' => "srv_ans_{$surveyId}_{$questionId}_{$option['id']}",
            ]];
        }
    }

    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $buttons]);
}

function surveyNextQuestionId($surveyId, $questionId) {
    global $pdo;
    $nextStmt = $pdo->prepare("
        SELECT id
        FROM survey_questions
        WHERE survey_id = ?
          AND (
                sort_order > (SELECT sort_order FROM survey_questions WHERE id = ?)
             OR (
                    sort_order = (SELECT sort_order FROM survey_questions WHERE id = ?)
                AND id > ?
             )
          )
        ORDER BY sort_order, id
        LIMIT 1
    ");
    $nextStmt->execute([$surveyId, $questionId, $questionId, $questionId]);
    return (int)$nextStmt->fetchColumn();
}

function surveyContinueAfterAnswer($chatId, $msgId, $surveyId, $questionId, $state) {
    $nextId = surveyNextQuestionId($surveyId, $questionId);

    if ($nextId) {
        surveyShowQuestion($chatId, $msgId, $surveyId, $nextId, $state);
        return;
    }

    if (!empty($state['allow_comment'])) {
        surveyAskComment($chatId, $msgId, $state);
    } else {
        surveyFinish($chatId, $msgId, $state, null);
    }
}

function surveyProcessAnswer($chatId, $msgId, $surveyId, $questionId, $optionId) {
    global $pdo;

    $state = surveyLoadState($chatId);
    if (!$state || (int)$state['survey_id'] !== (int)$surveyId) {
        surveyStart($chatId, $msgId, $surveyId);
        return;
    }

    $questionStmt = $pdo->prepare("SELECT type FROM survey_questions WHERE id = ? AND survey_id = ?");
    $questionStmt->execute([$questionId, $surveyId]);
    $type = $questionStmt->fetchColumn();
    if (!$type) return;

    if ($type === 'scale') {
        $score = (int)$optionId;
        if ($score < 1 || $score > 10) return;
        $state['answers'][(string)$questionId] = ['type' => 'scale', 'numeric_value' => $score];
    } else {
        $checkStmt = $pdo->prepare("
            SELECT so.id
            FROM survey_options so
            JOIN survey_questions sq ON sq.id = so.question_id
            WHERE so.id = ?
              AND so.question_id = ?
              AND sq.survey_id = ?
        ");
        $checkStmt->execute([$optionId, $questionId, $surveyId]);
        if (!$checkStmt->fetch()) return;
        $state['answers'][(string)$questionId] = ['type' => 'choice', 'option_id' => (int)$optionId];
    }
    surveySaveState($chatId, $state);

    surveyContinueAfterAnswer($chatId, $msgId, $surveyId, $questionId, $state);
}

function surveyAskComment($chatId, $msgId, $state) {
    $state['step'] = 'comment';
    $state['comment_msg_id'] = $msgId;
    surveySaveState($chatId, $state);

    $safeTitle = htmlspecialchars($state['survey_title'], ENT_QUOTES, 'UTF-8');
    $restaurantLabel = botFormatSubscribedRestaurant($state['restaurant_number'], $state['legal_entity_group']);

    $text = "📋 <b>{$safeTitle}</b>\n";
    $text .= "🏪 {$restaurantLabel}\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    $text .= "Если нужно, напишите комментарий.\n<i>Или нажмите «Пропустить».</i>";

    editMessage($chatId, $msgId, $text, ['inline_keyboard' => [
        [['text' => 'Пропустить', 'callback_data' => "srv_skip_comment_{$state['survey_id']}"]],
    ]]);
}

function surveyFinish($chatId, $msgId, $state, $comment) {
    global $pdo;

    $surveyId = (int)$state['survey_id'];
    $restaurantNumber = (int)$state['restaurant_number'];
    $group = $state['legal_entity_group'] ?? 'BK_VM';

    $dupStmt = $pdo->prepare("
        SELECT id
        FROM survey_responses
        WHERE survey_id = ?
          AND restaurant_number = ?
          AND legal_entity_group COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
    ");
    $dupStmt->execute([$surveyId, $restaurantNumber, $group]);
    if ($dupStmt->fetch()) {
        surveyClearState($chatId);
        editMessage($chatId, $msgId, "✅ Ответ по этому ресторану уже был записан.", ['inline_keyboard' => [
            [['text' => '◂ Меню', 'callback_data' => 'rest_my_subs']],
        ]]);
        return;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO survey_responses (survey_id, restaurant_number, legal_entity_group, telegram_chat_id, comment)
            VALUES (?,?,?,?,?)
        ")->execute([$surveyId, $restaurantNumber, $group, $chatId, $comment !== null && trim($comment) !== '' ? trim($comment) : null]);
        $responseId = (int)$pdo->lastInsertId();

        foreach ($state['answers'] as $questionId => $answer) {
            if (!is_array($answer)) {
                $answer = ['type' => 'choice', 'option_id' => (int)$answer];
            }
            $type = $answer['type'] ?? 'choice';
            $pdo->prepare("
                INSERT INTO survey_answers (response_id, question_id, option_id, numeric_value, text_value)
                VALUES (?,?,?,?,?)
            ")->execute([
                $responseId,
                (int)$questionId,
                $type === 'choice' ? (int)($answer['option_id'] ?? 0) : null,
                $type === 'scale' ? (int)($answer['numeric_value'] ?? 0) : null,
                $type === 'text' ? trim((string)($answer['text_value'] ?? '')) : null,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[survey] finish error: ' . $e->getMessage());
        editMessage($chatId, $msgId, "⚠️ Ошибка при сохранении. Попробуйте ещё раз.");
        return;
    }

    surveyClearState($chatId);

    $pendingRestaurants = surveyGetPendingRestaurants($surveyId, $chatId, $group);
    $safeTitle = htmlspecialchars($state['survey_title'], ENT_QUOTES, 'UTF-8');

    if ($pendingRestaurants) {
        $buttons = [];
        if (count($pendingRestaurants) === 1) {
            $nextRestaurant = $pendingRestaurants[0];
            $buttons[] = [[
                'text' => 'Ответить за ' . botFormatSubscribedRestaurant($nextRestaurant['restaurant_number'], $nextRestaurant['legal_entity_group']),
                'callback_data' => "srv_rest_{$surveyId}_{$nextRestaurant['restaurant_number']}",
            ]];
        } else {
            $buttons[] = [[
                'text' => 'Выбрать ещё ресторан',
                'callback_data' => "srv_start_{$surveyId}",
            ]];
        }
        $buttons[] = [['text' => '◂ Меню', 'callback_data' => 'rest_my_subs']];

        editMessage($chatId, $msgId, "✅ <b>Спасибо!</b>\n\nОтвет по опросу «{$safeTitle}» записан.\nУ вас ещё остались рестораны без ответа.", ['inline_keyboard' => $buttons]);
        return;
    }

    editMessage($chatId, $msgId, "✅ <b>Спасибо!</b>\n\nОтветы по опросу «{$safeTitle}» записаны.", ['inline_keyboard' => [
        [['text' => '◂ Меню', 'callback_data' => 'rest_my_subs']],
    ]]);
}

function surveyProcessComment($chatId, $text, $userMsgId) {
    $state = surveyLoadState($chatId);
    if (!$state) return false;

    if (($state['step'] ?? '') === 'text_question') {
        $answerText = trim($text);
        if ($answerText === '') return true;
        $questionId = (int)($state['current_question_id'] ?? 0);
        $msgId = $state['question_msg_id'] ?? null;
        if (!$questionId || !$msgId) return false;
        if ($userMsgId) @deleteMessage($chatId, $userMsgId);
        $state['answers'][(string)$questionId] = ['type' => 'text', 'text_value' => $answerText];
        $state['step'] = 'question';
        unset($state['current_question_id'], $state['question_msg_id']);
        surveySaveState($chatId, $state);
        surveyContinueAfterAnswer($chatId, $msgId, (int)$state['survey_id'], $questionId, $state);
        return true;
    }

    if (($state['step'] ?? '') !== 'comment') return false;

    $msgId = $state['comment_msg_id'] ?? null;
    if ($userMsgId) @deleteMessage($chatId, $userMsgId);
    surveyFinish($chatId, $msgId, $state, trim($text));
    return true;
}
