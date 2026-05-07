<?php
// ═══ Вспомогательные функции бота: быстрые ответы, контекст разговора, обработчик свободного текста ═══
// getQuickReply, saveLastContext, saveQuestionAnswer, getLastContext, isFollowUp,
// selectRelevantLookups, handleFreeText, buildDirectAnswer

// ═══ Быстрые ответы на простые фразы ═══

function getQuickReply($text, $user) {
    $t = mb_strtolower(trim($text));
    $name = explode(' ', $user['name'])[1] ?? $user['name']; // Имя (без фамилии)

    // Приветствия — только если это ТОЛЬКО приветствие без содержательного вопроса
    if (preg_match('/^(привет|здравствуй\w*|добр(ый|ое|ая)\s+(утро|день|вечер)?|хай|хей|hello|hi|йо|салам|хелло)\b/u', $t)) {
        // Убираем приветствие и запятые/точки, проверяем остаток
        $rest = preg_replace('/^(привет\w*|здравствуй\w*|добр(ый|ое|ая)\s*(утро|день|вечер)?|хай|хей|hello|hi|йо|салам|хелло)[,!.\s]*/ui', '', $t);
        $rest = trim($rest);
        // Если после приветствия есть содержательный текст (>3 символов) — это вопрос, не шаблон
        if (mb_strlen($rest) > 3) {
            return null; // Пусть обработает ИИ целиком
        }
        $hour = (int)(new DateTime('now', new DateTimeZone('Europe/Minsk')))->format('H');
        $greeting = $hour < 12 ? 'Доброе утро' : ($hour < 18 ? 'Добрый день' : 'Добрый вечер');
        return "{$greeting}, <b>{$name}</b>! Чем могу помочь?\n\nЗадайте вопрос или выберите раздел в меню.";
    }

    // Благодарности
    if (preg_match('/^(спасибо|благодар|спс|thx|thanks|мерси)\b/u', $t)) {
        // Если после благодарности есть вопрос — передать в ИИ
        $rest = preg_replace('/^(спасибо|благодар\w*|спс|thx|thanks|мерси)[,!.\s]*/ui', '', $t);
        if (mb_strlen(trim($rest)) > 3) return null;
        return "Пожалуйста! Если ещё что-то нужно — спрашивайте.";
    }

    // Прощания
    if (preg_match('/^(пока|до свидания|до встречи|бай|bye|удачи|всего доброго)\b/u', $t)) {
        return "До связи, <b>{$name}</b>! Хорошего дня.";
    }

    // Как дела — только если дальше нет вопроса
    if (preg_match('/^как (дела|жизнь|поживаешь|сам)/u', $t)) {
        $rest = preg_replace('/^как (дела|жизнь|поживаешь|сам\w*)[,?!.\s]*/ui', '', $t);
        if (mb_strlen(trim($rest)) > 3) return null;
        return "Всё работает! Готов помочь с данными. Задайте вопрос или откройте меню.";
    }

    return null;
}

// ═══ Память разговора (последний контекст) ═══

function saveLastContext($userName, $question, $entity) {
    global $pdo;
    try {
        $pdo->prepare("UPDATE telegram_settings SET last_question = ?, last_entity = ?, last_question_at = NOW() WHERE user_name = ?")
            ->execute([mb_substr($question, 0, 500), $entity, $userName]);
    } catch (Exception $e) { /* колонка может не существовать */ }
    // Сохраняем в лог вопросов
    try {
        $pdo->prepare("INSERT INTO tg_question_log (user_name, question, legal_entity) VALUES (?, ?, ?)")
            ->execute([$userName, mb_substr($question, 0, 500), $entity]);
        return (int)$pdo->lastInsertId();
    } catch (Exception $e) { return null; }
}

function saveQuestionAnswer($logId, $answer) {
    global $pdo;
    if (!$logId || !$answer) return;
    try {
        $pdo->prepare("UPDATE tg_question_log SET answer = ? WHERE id = ?")
            ->execute([mb_substr($answer, 0, 4000), $logId]);
    } catch (Exception $e) { /* колонка может не существовать */ }
}

function getLastContext($userName) {
    global $pdo;
    try {
        $s = $pdo->prepare("SELECT last_question, last_entity, last_question_at FROM telegram_settings WHERE user_name = ?");
        $s->execute([$userName]);
        $row = $s->fetch();
        if (!$row || !$row['last_question']) return null;
        // Контекст актуален 10 минут
        $age = time() - strtotime($row['last_question_at']);
        if ($age > 600) return null;
        return $row;
    } catch (Exception $e) { return null; }
}

function isFollowUp($text) {
    $t = mb_strtolower(trim($text));
    // Короткие уточняющие фразы
    return preg_match('/^(а (по|для|у|в|на|что|как)|а если|ещё|еще|и (по|для|ещё|еще)|то же|тоже самое|аналогично|по (бк|вм|пс|бургер|воглия|пицца)|для (бк|вм|пс|бургер|воглия|пицца))/u', $t)
        && mb_strlen($t) < 100;
}

function selectRelevantLookups($q) {
    // Всегда запускаем поиск товара и карточек — они релевантны почти для любого вопроса
    $lookups = ['lookupProduct', 'lookupCards'];

    // Заказы
    if (preg_match('/заказ|состав|позиц|что заказ|заказыв|отправ/u', $q)) {
        $lookups[] = 'lookupOrders';
    }
    // Остатки по дням
    if (preg_match('/остат|запас|дней|заканч|кончает|мало|критич|нулев|ноль/u', $q)) {
        $lookups[] = 'lookupStockDays';
    }
    // Сроки годности
    if (preg_match('/срок|годн|истек|просроч|маллинг|блокир|хранен|expir/u', $q)) {
        $lookups[] = 'lookupShelfLife';
    }
    // Поставщики
    if (preg_match('/поставщик|контакт|телефон|email|whatsapp|telegram|менедж|dlt|срок доставк/u', $q)) {
        $lookups[] = 'lookupSupplier';
    }
    // График доставок
    if (preg_match('/график|расписан|ресторан|доставк.*рестор|рестор.*доставк|какой день/u', $q)) {
        $lookups[] = 'lookupSchedule';
    }
    // Поставки (ожидаемые)
    if (preg_match('/поставк|приед|привез|когда.*прие|ожидае|приход|доставк|привоз/u', $q)) {
        $lookups[] = 'lookupDeliveries';
    }
    // Планы
    if (preg_match('/план|периодич|частот|как часто|график заказ/u', $q)) {
        $lookups[] = 'lookupPlans';
    }
    // Цены
    if (preg_match('/цен|стоим|прайс|ндс|сколько стоит|почём|по чём/u', $q)) {
        $lookups[] = 'lookupPrices';
    }
    // Реализация ресторанов
    if (preg_match('/реализац|продаж|ресторан.*прода|спрос|популярн|тренд.*продаж|сезон/u', $q)) {
        $lookups[] = 'lookupSales';
    }

    // Если не нашли специфических тем — запускаем все (общий вопрос)
    if (count($lookups) <= 2) {
        $lookups = ['lookupProduct', 'lookupCards', 'lookupOrders', 'lookupStockDays',
                     'lookupShelfLife', 'lookupSupplier', 'lookupSchedule',
                     'lookupDeliveries', 'lookupPlans', 'lookupPrices', 'lookupSales'];
    }

    return array_unique($lookups);
}

function handleFreeText($chatId, $text, $user) {
    global $GEMINI_API_KEY, $GROQ_API_KEY, $DEEPSEEK_API_KEY, $OPENROUTER_API_KEY;

    // Без ключа — подсказка по командам
    if (!$GEMINI_API_KEY && !$GROQ_API_KEY && !$DEEPSEEK_API_KEY && !$OPENROUTER_API_KEY) {
        sendMessage($chatId, "Доступные команды:\n/menu — главное меню\n/restaurant — меню ресторана\n/cards — поиск карточек\n/today — сводка на сегодня\n/orders — заказы за 7 дней\n/deliveries — ближайшие поставки\n/plans — планирование\n/stock — критичные остатки\n/analysis — анализ запасов\n/consumption — топ расхода\n/prices — изменения цен\n/psc — протоколы\n/schedule — график доставок\n/sales — реализация ресторанов\n/export — выгрузки CSV\n/settings — настройки уведомлений");
        return;
    }

    // Проверяем, не follow-up ли это (уточняющий вопрос к предыдущему)
    $effectiveText = $text;
    if (isFollowUp($text)) {
        $lastCtx = getLastContext($user['name']);
        if ($lastCtx && $lastCtx['last_question']) {
            $effectiveText = $lastCtx['last_question'] . ' ' . $text;
            error_log("Bot: follow-up detected. Combined: {$effectiveText}");
        }
    }

    $entity = getUserEntity($user);

    // Сохраняем контекст для возможных follow-up вопросов
    $questionLogId = saveLastContext($user['name'], $effectiveText, $entity);

    // Отправляем «думает...» сообщение
    sendTyping($chatId);
    $thinkMsg = sendMessageAndGetId($chatId, "🔍 Ищу данные...");

    $answer = null;
    $aiStart = microtime(true);

    // === 1. Пробуем Gemini с инструментами (умный режим) ===
    try {
        $answer = askWithTools($effectiveText, $entity, $user['name']);
        if ($answer) {
            error_log("Bot: Gemini Tools OK in " . round(microtime(true) - $aiStart, 1) . "s");
        }
    } catch (Exception $e) {
        error_log("Bot askWithTools error: " . $e->getMessage());
    }

    // === 2. Fallback: старый способ (контекст + модели) ===
    if (!$answer) {
        error_log("Bot: Gemini Tools failed, falling back to legacy mode");
        if ($thinkMsg) {
            editMessage($chatId, $thinkMsg, "🤖 Формирую ответ...");
        }
        sendTyping($chatId);

        $context = gatherContext($user);
        $lookupContext = '';
        $q = mb_strtolower($effectiveText);
        $lookups = selectRelevantLookups($q);
        foreach ($lookups as $fn) {
            try {
                $result = $fn($effectiveText, $entity);
                if ($result) $lookupContext .= $result;
            } catch (Exception $e) {
                error_log("Bot {$fn} error: " . $e->getMessage());
            }
        }

        $totalLen = mb_strlen($context) + mb_strlen($lookupContext);
        if ($totalLen > 12000) {
            $maxGeneral = max(2000, 12000 - mb_strlen($lookupContext));
            $context = mb_substr($context, 0, $maxGeneral) . "\n…(сокращено)\n";
        }
        if (mb_strlen($lookupContext) > 10000) {
            $lookupContext = mb_substr($lookupContext, 0, 9500) . "\n…(данных больше, показаны основные)\n";
        }
        $context .= $lookupContext;

        try {
            $answer = askAI($effectiveText, $context);
        } catch (Exception $e) {
            error_log("Bot askAI error: " . $e->getMessage());
        }

        // Если ИИ недоступен — прямой ответ из данных
        if (!$answer) {
            $answer = buildDirectAnswer($lookupContext);
        }
    }

    $aiTime = round(microtime(true) - $aiStart, 1);
    error_log("Bot: AI response in {$aiTime}s, answer=" . ($answer ? strlen($answer) . ' bytes' : 'null'));

    // Сохраняем ответ в лог
    saveQuestionAnswer($questionLogId, $answer);

    // Удаляем сообщение-статус
    if ($thinkMsg) {
        deleteMessage($chatId, $thinkMsg);
    }

    $menuMarkup = ['inline_keyboard' => [[['text' => '◂ Меню', 'callback_data' => 'cmd_menu']]]];
    if ($answer) {
        if (mb_strlen($answer) > 4000) {
            $answer = mb_substr($answer, 0, 3990) . "\n\n…";
        }
        sendMessage($chatId, $answer, $menuMarkup);
    } else {
        $hint = "Не удалось обработать запрос.\n\nПопробуйте уточнить вопрос:\n";
        $hint .= "• Укажите <b>артикул</b> или <b>полное название</b> товара\n";
        $hint .= "• Добавьте контекст: «остаток», «цена», «когда приедет», «аналоги»\n";
        $hint .= "• Или используйте кнопки меню ниже";
        sendMessage($chatId, $hint, ['inline_keyboard' => [
            [['text' => '🔍 Поиск карточек', 'callback_data' => 'cmd_cards']],
            [['text' => '◂ Меню', 'callback_data' => 'cmd_menu']],
        ]]);
    }
}

// Прямой ответ из данных, когда ИИ недоступен
function buildDirectAnswer($context) {
    // Извлекаем секции с полезными данными (== НАЗВАНИЕ ==)
    $sections = [];
    if (preg_match_all('/\n== (.+?) ==\n([\s\S]*?)(?=\n== |\z)/', $context, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $title = trim($m[1]);
            $body = trim($m[2]);
            if ($body) {
                $sections[] = ['title' => $title, 'body' => $body];
            }
        }
    }

    if (empty($sections)) return null;

    // Форматируем для Telegram
    $text = "📋 <i>Данные из системы:</i>\n\n";
    foreach ($sections as $s) {
        $text .= "<b>{$s['title']}</b>\n";
        $body = $s['body'];
        // Убираем лишние отступы
        $body = preg_replace('/^    /m', '  ', $body);
        $body = preg_replace('/^  • /m', '• ', $body);
        $body = preg_replace('/^  - /m', '• ', $body);
        $body = preg_replace('/^- /m', '• ', $body);
        $text .= $body . "\n\n";
    }

    $text = trim($text);

    // Telegram ограничивает 4096 символов
    if (mb_strlen($text) > 4000) {
        $text = mb_substr($text, 0, 3990) . "\n\n…<i>обрезано</i>";
    }

    return $text;
}

/**
 * Проверка, что у пользователя бота достаточно прав для админских операций
 * (массовая рассылка, заливка файла-заказа, статистика подписок и т.п.).
 * При отказе сам редактирует сообщение и возвращает false — вызывающий
 * делает `break` без обработки команды.
 */
function botRequireAdmin(?array $user, int $chatId, int $msgId): bool {
    $role = $user['role'] ?? '';
    if (in_array($role, ['admin', 'manager'], true)) return true;
    editMessage($chatId, $msgId, '⛔ Недостаточно прав. Эта команда доступна только администраторам.', [
        'inline_keyboard' => [[['text' => '◂ Меню', 'callback_data' => 'cmd_menu']]],
    ]);
    return false;
}
