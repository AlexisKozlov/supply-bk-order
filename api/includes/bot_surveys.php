<?php
// ═══ Опросы для ресторанов через Telegram-бот ═══

function surveyGetStateFile($chatId) {
    return sys_get_temp_dir() . "/survey_{$chatId}.json";
}

function surveyLoadState($chatId) {
    $file = surveyGetStateFile($chatId);
    if (!file_exists($file)) return null;
    if (time() - filemtime($file) > 7200) {
        @unlink($file);
        return null;
    }
    $data = @json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function surveySaveState($chatId, $state) {
    @file_put_contents(surveyGetStateFile($chatId), json_encode($state));
}

function surveyClearState($chatId) {
    @unlink(surveyGetStateFile($chatId));
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

    $roUsers = $pdo->prepare("
        SELECT ru.restaurant_number, ru.legal_entity_group, r.address, r.city
        FROM ro_users ru
        LEFT JOIN restaurants r
          ON r.number = ru.restaurant_number
         AND r.legal_entity_group = ru.legal_entity_group
         AND r.active = 1
        WHERE ru.telegram_chat_id = ?
          AND ru.legal_entity_group = ?
          AND ru.is_active = 1
    ");
    $roUsers->execute([$chatId, $group]);

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
          AND legal_entity_group = ?
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
    $buttons[] = [['text' => '◂ Меню', 'callback_data' => 'veg_my_subs']];

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
            [['text' => '◂ Меню', 'callback_data' => 'veg_my_subs']],
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
            [['text' => '◂ Меню', 'callback_data' => 'veg_my_subs']],
        ]]);
        return;
    }

    $allRestaurants = surveyGetChatRestaurants($chatId, $survey['legal_entity_group']);
    if (!$allRestaurants) {
        editMessage($chatId, $msgId, "❌ Для этого опроса у вас не найдено ресторанов нужного юрлица.", ['inline_keyboard' => [
            [['text' => '◂ Меню', 'callback_data' => 'veg_my_subs']],
        ]]);
        return;
    }

    $pendingRestaurants = surveyGetPendingRestaurants($surveyId, $chatId, $survey['legal_entity_group']);
    if (!$pendingRestaurants) {
        editMessage($chatId, $msgId, "✅ По всем вашим ресторанам в этом опросе ответы уже записаны.", ['inline_keyboard' => [
            [['text' => '◂ Меню', 'callback_data' => 'veg_my_subs']],
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
            [['text' => '◂ Меню', 'callback_data' => 'veg_my_subs']],
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
        SELECT id, text, sort_order
        FROM survey_questions
        WHERE id = ? AND survey_id = ?
    ");
    $questionStmt->execute([$questionId, $surveyId]);
    $question = $questionStmt->fetch();
    if (!$question) return;

    $optionsStmt = $pdo->prepare("
        SELECT id, text
        FROM survey_options
        WHERE question_id = ?
        ORDER BY sort_order, id
    ");
    $optionsStmt->execute([$questionId]);
    $options = $optionsStmt->fetchAll();

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
    foreach ($options as $option) {
        $buttons[] = [[
            'text' => $option['text'],
            'callback_data' => "srv_ans_{$surveyId}_{$questionId}_{$option['id']}",
        ]];
    }

    editMessage($chatId, $msgId, $text, ['inline_keyboard' => $buttons]);
}

function surveyProcessAnswer($chatId, $msgId, $surveyId, $questionId, $optionId) {
    global $pdo;

    $state = surveyLoadState($chatId);
    if (!$state || (int)$state['survey_id'] !== (int)$surveyId) {
        surveyStart($chatId, $msgId, $surveyId);
        return;
    }

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

    $state['answers'][(string)$questionId] = (int)$optionId;
    surveySaveState($chatId, $state);

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
    $nextId = (int)$nextStmt->fetchColumn();

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
          AND legal_entity_group = ?
    ");
    $dupStmt->execute([$surveyId, $restaurantNumber, $group]);
    if ($dupStmt->fetch()) {
        surveyClearState($chatId);
        editMessage($chatId, $msgId, "✅ Ответ по этому ресторану уже был записан.", ['inline_keyboard' => [
            [['text' => '◂ Меню', 'callback_data' => 'veg_my_subs']],
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

        foreach ($state['answers'] as $questionId => $optionId) {
            $pdo->prepare("
                INSERT INTO survey_answers (response_id, question_id, option_id)
                VALUES (?,?,?)
            ")->execute([$responseId, (int)$questionId, (int)$optionId]);
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
        $buttons[] = [['text' => '◂ Меню', 'callback_data' => 'veg_my_subs']];

        editMessage($chatId, $msgId, "✅ <b>Спасибо!</b>\n\nОтвет по опросу «{$safeTitle}» записан.\nУ вас ещё остались рестораны без ответа.", ['inline_keyboard' => $buttons]);
        return;
    }

    editMessage($chatId, $msgId, "✅ <b>Спасибо!</b>\n\nОтветы по опросу «{$safeTitle}» записаны.", ['inline_keyboard' => [
        [['text' => '◂ Меню', 'callback_data' => 'veg_my_subs']],
    ]]);
}

function surveyProcessComment($chatId, $text, $userMsgId) {
    $state = surveyLoadState($chatId);
    if (!$state || ($state['step'] ?? '') !== 'comment') return false;

    $msgId = $state['comment_msg_id'] ?? null;
    if ($userMsgId) @deleteMessage($chatId, $userMsgId);
    surveyFinish($chatId, $msgId, $state, trim($text));
    return true;
}
