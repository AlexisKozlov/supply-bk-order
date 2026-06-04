<?php
// ═══ Ресторанный FAQ-режим бота для рабочих групп ═══
//
// Когда бота добавляют в общую рабочую группу с ресторанами, он отвечает
// ТОЛЬКО на вопросы про процессы и инструкции (как оформить возврат кег,
// как войти в кабинет, куда жаловаться и т.п.). У этого режима НЕТ доступа
// к данным (остатки, цены, заказы, чужие рестораны) — это общая группа,
// персональных данных в ней быть не должно.
//
// Триггер: бот отвечает только когда его @упомянули или ответили на его
// сообщение (privacy mode в боте оставлен включённым). Обычный «трёп» в
// группе бот игнорирует.

/**
 * Кэшированный getMe — нужен, чтобы понять, упомянули ли бота в группе.
 * Кэш в tg_state (chat_id=0, mode='getme') на сутки. Возвращает
 * ['id'=>int, 'username'=>string] либо null.
 */
function botGetMe(): ?array
{
    global $BOT_TOKEN;
    $cached = tgStateGet(0, 'getme');
    if ($cached && !empty($cached['username'])) {
        return ['id' => (int)($cached['id'] ?? 0), 'username' => (string)$cached['username']];
    }
    if (!$BOT_TOKEN) return null;
    $ch = curl_init("https://api.telegram.org/bot{$BOT_TOKEN}/getMe");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = $resp ? json_decode($resp, true) : null;
    if (empty($data['ok']) || empty($data['result']['username'])) return null;
    $me = ['id' => (int)$data['result']['id'], 'username' => (string)$data['result']['username']];
    tgStateSet(0, 'getme', $me, 86400);
    return $me;
}

/**
 * Обработка сообщения, пришедшего из группы/супергруппы.
 * Бот отвечает только если к нему обратились (упоминание или ответ на его
 * сообщение). Во всех остальных случаях молчит.
 */
function handleGroupMessage($chatId, array $msg): void
{
    global $pdo, $DEEPSEEK_API_KEY, $GROQ_API_KEY;

    $me = botGetMe();
    if (!$me) return; // без username не понять, обращаются ли к нам — молчим
    $botUsername = $me['username'];
    $botId = $me['id'];

    $text = trim($msg['text'] ?? $msg['caption'] ?? '');
    if ($text === '') return;

    // Кто написал: сотрудник отдела закупок или гость (ресторан).
    $sender = faqIdentifyGroupSender($msg['from'] ?? []);

    // Контекст обсуждения ДО текущего сообщения (чтобы понимать ветку).
    $threadBefore = faqThreadText($chatId);
    // Текущее сообщение записываем в скользящий буфер — ВСЕГДА, даже если к
    // боту не обращались (бот «видит» весь чат для контекста).
    faqThreadAppend($chatId, $sender['name'], $text);

    // Обучение: если СОТРУДНИК закупок ответил (reply) на чьё-то сообщение —
    // запоминаем пару «вопрос → ответ» и переиспользуем в похожих вопросах.
    // Доверяем только сотрудникам (определяются по users.telegram_chat_id).
    $reply = $msg['reply_to_message'] ?? null;
    if ($sender['is_staff'] && $reply) {
        $replyFromId  = (int)($reply['from']['id'] ?? 0);
        $repliedText  = trim($reply['text'] ?? $reply['caption'] ?? '');
        // Не учимся на ответах самого бота (в reply там его ответ, не вопрос).
        if ($replyFromId !== $botId && mb_strlen($repliedText) >= 5 && mb_strlen($text) >= 3) {
            faqLearnCapture($chatId, $repliedText, $text, $sender['name'], $sender['role']);
        }
    }

    // ── Обращаются ли к боту? ───────────────────────────────────────────
    $addressed = false;

    // 1. Ответ на сообщение самого бота.
    $replyFrom = $msg['reply_to_message']['from'] ?? null;
    if ($replyFrom && !empty($replyFrom['is_bot']) && (int)($replyFrom['id'] ?? 0) === $botId) {
        $addressed = true;
    }

    // 2. Упоминание @username в тексте (entities типа mention / bot_command),
    //    либо text_mention напрямую на id бота.
    $entities = $msg['entities'] ?? $msg['caption_entities'] ?? [];
    foreach ($entities as $ent) {
        $type = $ent['type'] ?? '';
        if ($type === 'text_mention') {
            if ((int)($ent['user']['id'] ?? 0) === $botId) { $addressed = true; break; }
            continue;
        }
        if ($type === 'mention' || $type === 'bot_command') {
            $off = (int)($ent['offset'] ?? 0);
            $len = (int)($ent['length'] ?? 0);
            $token = mb_substr($text, $off, $len);
            if (mb_stripos($token, '@' . $botUsername) !== false) { $addressed = true; break; }
        }
    }

    if (!$addressed) return; // обычное сообщение в группе — игнорируем

    // ── Чистим вопрос от упоминаний и команд ────────────────────────────
    // Убираем @username и токены вида /cmd@username, чтобы модель видела
    // только сам вопрос.
    $question = preg_replace('/\/[a-zA-Z0-9_]+@' . preg_quote($botUsername, '/') . '/u', ' ', $text) ?? $text;
    $question = preg_replace('/@' . preg_quote($botUsername, '/') . '\b/iu', ' ', $question) ?? $question;
    $question = trim(preg_replace('/\s+/u', ' ', $question) ?? $question);

    if ($question === '' || mb_strlen($question) < 2) {
        tgClientSend($chatId, "Привет! Спросите меня про работу с порталом — например: <i>«Как оформить возврат кег?»</i> или <i>«Как войти в кабинет ресторана?»</i>", [
            'reply_to_message_id' => $msg['message_id'] ?? null,
            'pdo' => $pdo,
        ]);
        return;
    }

    // ── Rate-limit на группу: не более 30 вопросов в час ────────────────
    $rlKey = 'tggroup_' . $chatId;
    try {
        $rlSt = $pdo->prepare("SELECT COUNT(*) FROM tg_question_log WHERE user_name = ? AND asked_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $rlSt->execute([$rlKey]);
        if ((int)$rlSt->fetchColumn() >= 30) {
            tgClientSend($chatId, "⏳ Слишком много вопросов за последний час. Попробуйте чуть позже.", [
                'reply_to_message_id' => $msg['message_id'] ?? null,
                'pdo' => $pdo,
            ]);
            return;
        }
        $pdo->prepare("INSERT INTO tg_question_log (user_name, question, legal_entity) VALUES (?, ?, '')")
            ->execute([$rlKey, mb_substr($question, 0, 500)]);
    } catch (Throwable $e) { /* лог-таблицы может не быть — продолжаем */ }

    sendTyping($chatId);

    // Накопленные ответы отдела закупок по похожим вопросам.
    $learnedText = faqLearnSearch($chatId, $question);

    // Юрлицо определяем из вопроса (по умолчанию Бургер БК; «по ВМ»/«по ПС» —
    // переключают). Остатки у юрлиц разные, поэтому это важно.
    $ent = faqDetectEntity($question);

    // Режим с данными (остатки/номенклатура/аналоги) + контекст ветки и
    // накопленные знания. Если не вышло — обычный FAQ по инструкциям.
    $answer = askRestaurantFaqWithTools($question, $ent['entity'], $ent['label'], $threadBefore, $learnedText);
    if (!$answer) {
        $answer = askRestaurantFaq($question, [], $DEEPSEEK_API_KEY ?? '', $GROQ_API_KEY ?? '');
    }

    if (!$answer) {
        $answer = "Не получилось сейчас ответить. Подробная инструкция по возврату кег есть в кабинете ресторана (раздел «Возврат кег» → «Как это работает»). По остальным вопросам обратитесь в отдел закупок.";
    }

    // Ответ бота тоже кладём в буфер обсуждения — для контекста следующих.
    faqThreadAppend($chatId, 'Бот', strip_tags($answer));

    $res = tgClientSend($chatId, $answer, [
        'reply_to_message_id' => $msg['message_id'] ?? null,
        'pdo' => $pdo,
    ]);
    // Если модель прислала кривой HTML — Telegram отвергает сообщение (400).
    // В группе это значит «ответа не будет вообще», поэтому повторяем
    // обычным текстом без разметки.
    if (empty($res['ok']) && (int)($res['error_code'] ?? 0) === 400) {
        $plain = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $answer)));
        tgClientSend($chatId, $plain, [
            'reply_to_message_id' => $msg['message_id'] ?? null,
            'parse_mode' => '',
            'pdo' => $pdo,
        ]);
    }
}

/**
 * Responder FAQ: DeepSeek (основной) → Groq (запасной), БЕЗ инструментов и
 * БЕЗ доступа к данным. Отвечает только по встроенной базе знаний.
 */
function askRestaurantFaq(string $question, array $history, string $deepseekKey, string $groqKey): ?string
{
    $systemPrompt = getRestaurantFaqSystemPrompt();

    $messages = [['role' => 'system', 'content' => $systemPrompt]];
    foreach ($history as $h) {
        if (isset($h['role'], $h['content'])) $messages[] = ['role' => $h['role'], 'content' => $h['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $question];

    // 1. DeepSeek
    if ($deepseekKey) {
        $ans = faqCallOpenAiCompatible('https://api.deepseek.com/chat/completions', 'deepseek-v4-flash', $deepseekKey, $messages);
        if ($ans) return botMdToHtml($ans);
    }

    // 2. Groq (запасной)
    if ($groqKey && (!function_exists('tgProviderBlocked') || !tgProviderBlocked('groq', 'llama-3.3-70b-versatile'))) {
        $ans = faqCallOpenAiCompatible('https://api.groq.com/openai/v1/chat/completions', 'llama-3.3-70b-versatile', $groqKey, $messages);
        if ($ans) return botMdToHtml($ans);
    }

    return null;
}

/**
 * Тонкий вызов OpenAI-совместимого чата (DeepSeek/Groq) без инструментов.
 */
function faqCallOpenAiCompatible(string $url, string $model, string $apiKey, array $messages): ?string
{
    $payload = json_encode([
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => 900,
        'temperature' => 0.2,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!$resp || $httpCode !== 200) {
        error_log("FAQ {$model}: HTTP {$httpCode}, err={$err}");
        return null;
    }
    $data = json_decode($resp, true);
    $content = $data['choices'][0]['message']['content'] ?? null;
    if ($content) {
        $content = preg_replace('/<think>[\s\S]*?<\/think>/u', '', $content);
        $content = trim($content);
    }
    return $content ?: null;
}

/**
 * Системный промпт FAQ-режима: персона + жёсткие правила безопасности +
 * встроенная база знаний (собрана из материалов репозитория).
 */
function getRestaurantFaqSystemPrompt(): string
{
    $kb = getRestaurantFaqKB();
    return <<<PROMPT
Ты — справочный помощник в общем рабочем чате ресторанов сети Burger King в Беларуси.
Ты помогаешь сотрудникам ресторанов разобраться, КАК пользоваться порталом отдела закупок:
возврат кег, вход в кабинет, привязка Telegram, сбор остатков, как сообщить об ошибке.

== ГЛАВНОЕ ПРАВИЛО БЕЗОПАСНОСТИ ==
У тебя НЕТ доступа к данным. Ты НЕ знаешь и НЕ называешь:
- остатки, цены, расход, заказы, поставки, сроки годности;
- данные по конкретному ресторану или поставщику;
- любые цифры из системы.
Это ОБЩИЙ чат — в нём не должно быть персональных данных ресторанов.
Если спрашивают такие данные — НЕ выдумывай и НЕ показывай. Вежливо ответь, что
эти данные есть в личном кабинете ресторана, и предложи посмотреть там или
обратиться в отдел закупок. Никогда не придумывай числа.

== О ЧЁМ ОТВЕЧАЕШЬ ==
Только по инструкциям и процессам из раздела «БАЗА ЗНАНИЙ» ниже.
Если вопрос не про портал и не про процессы (личная переписка, посторонние темы) —
коротко скажи, что помогаешь только по работе с порталом, и предложи задать вопрос
по теме.

== КАК ОТВЕЧАТЬ ==
- По-русски, простым языком, коротко и по делу. Сотрудники ресторанов — не айтишники.
- Только по фактам из базы знаний. Если в базе знаний нет ответа — честно скажи,
  что точной инструкции нет, и предложи обратиться в отдел закупок. НЕ выдумывай.
- Формат для Telegram: <b>жирный</b> для важного, списки через «•». НЕ используй
  Markdown (**, ##, таблицы, ```).
- Не повторяй вопрос. Отвечай сразу по существу.

== БАЗА ЗНАНИЙ ==
{$kb}
PROMPT;
}

/**
 * База знаний для ресторанов — собрана из материалов репозитория
 * (памятка по возврату кег + общие сведения о портале). Только процессы и
 * инструкции, без данных.
 */
function getRestaurantFaqKB(): string
{
    return <<<'KB'
--- ПОРТАЛ ОТДЕЛА ЗАКУПОК: ЛИЧНЫЙ КАБИНЕТ РЕСТОРАНА ---
В кабинете ресторана доступно: возврат кег, остатки склада, сбор остатков,
скачивание файла заказа, «Сообщить об ошибке».

Вход в кабинет:
• В поле входа вводят НОМЕР ресторана или EMAIL и пароль. Система сама понимает
  по символу @, что введён email.
• Можно входить с нескольких устройств. При входе с нового устройства приходит
  уведомление в Telegram.

Забыли пароль (точная инструкция):
• На странице входа, под формой и справа, есть небольшая ТЕКСТОВАЯ ССЫЛКА
  (не кнопка!) «Забыли пароль?». Если не видите — это серая ссылка прямо под
  полем пароля, в правом углу.
• Нажмите её — откроется страница «Сброс пароля» с двумя вкладками:
  — Вкладка «По email»: введите email кабинета и нажмите «Отправить ссылку».
    На почту придёт ссылка для сброса пароля. Работает, только если email был
    заранее указан и подтверждён в кабинете.
  — Вкладка «По Telegram»: введите номер ресторана (например, 24 или PS01) и
    нажмите «Отправить код в Telegram». Код придёт в бот, к которому привязан
    ресторан. Если бот не подключён — используйте email или обратитесь к закупщику.

Привязка Telegram:
• В кабинете: Профиль → Telegram → «Получить код». Бот — @supplyportal_bot.
• Боту отправляют полученный 6-значный код — после этого приходят уведомления
  (о дедлайнах, маршрутизации возврата кег и др.).
• Если уведомление не пришло — проверьте, что вы подписаны на @supplyportal_bot.

Файл заказа:
• Когда отдел закупок загружает новый файл заказа, он автоматически приходит
  подписчикам в Telegram-бот. Также его можно скачать в боте.

Сообщить об ошибке:
• В кабинете ресторана есть кнопка «Сообщить об ошибке» — через неё можно
  отправить обращение в отдел закупок и приложить фото. Ответ закупки придёт
  туда же, со значком уведомления.

--- ВОЗВРАТ КЕГ ---
Модуль «Возврат кег» в кабинете ресторана: оформить возврат пустых кег,
напечатать ТТН на бланке и передать водителю.

ВАЖНО про экземпляры ТТН:
• Экземпляры № 1, 3 и 4 — отдаются водителю.
• Экземпляр № 2 — остаётся ресторану.
• Обязательно взять подписи водителя.

Как оформить возврат, шаг за шагом:
1. Войти в кабинет ресторана, открыть раздел «Возврат кег».
2. Нажать «Новая заявка».
3. Заполнить основную информацию:
   • Серия и номер ТТН — 2 буквы (например, «АА») и ровно 7 цифр.
   • Управляющий рестораном — ФИО (например, «Иванов И.И.»).
   • Дата возврата — выбирается из дней приёма вашего ресторана (по графику).
4. Указать кеги к возврату — выбрать позиции из каталога и проставить количество.
5. Нажать «Сформировать заявку». Пока не прошёл дедлайн, заявку можно редактировать.
6. Распечатать ТТН на бланке (кнопка «Распечатать») заранее, до дедлайна — чтобы
   убедиться, что бланк не испорчен и номер на нём совпадает с фактическим.
7. Дождаться уведомления о маршрутизации в боте @supplyportal_bot — там будут
   указаны водитель и машина.
8. Вписать от руки в распечатанный бланк водителя, машину и «товар принял к
   перевозке», затем передать бланк водителю.
Водителя и машину НЕ нужно указывать при создании заявки — они приходят в
уведомлении о маршрутизации, их вписывают от руки в уже распечатанный бланк.

Важные сроки (привязаны ко дню НАКАНУНЕ возврата — это последний рабочий день
перед датой приёма ресторана, выходные пропускаются):
• До 10:00 — можно свободно редактировать: кеги, номер ТТН, данные.
• С 10:00 до 15:00 — можно только заменить испорченный бланк.
• После 15:00 — изменения невозможны, заявка ушла логисту.
Пример: возврат во вторник → все сроки считаются по понедельнику.

Если испортили бланк:
• В заявке есть кнопка замены бланка — указать новый номер взамен испорченного.
  Доступна до 15:00 в день дедлайна. Все замены сохраняются в истории.

Частые вопросы по возврату кег:
• Не вижу нужный день для возврата — дни приёма заданы графиком вашего ресторана.
• Не пришло уведомление в Telegram — проверьте подписку на @supplyportal_bot.
• Модуль недоступен — возможно, для вашего ресторана возврат кег ещё не включён;
  уточните в отделе закупок.
KB;
}

// ═══════════════════════════════════════════════════════════════════════
//  Доступ к данным в группе: ТОЛЬКО остатки, номенклатура, аналоги.
//  НЕ отдаём: цены, заказы поставщикам, заявки, ожидаемые поставки,
//  расход по ресторанам, данные чужих юрлиц (кроме запрошенного).
// ═══════════════════════════════════════════════════════════════════════

/**
 * Определяет юрлицо из текста вопроса. По умолчанию — Бургер БК.
 * «воглия»/«вм» → Воглия Матта; «пицца стар»/«пс»/«додо» → Пицца Стар.
 */
function faqDetectEntity(string $question): array
{
    $q = ' ' . mb_strtolower($question, 'UTF-8') . ' ';
    if (preg_match('/пицца\s*стар|pizza\s*star|додо|dodo/u', $q)
        || preg_match('/(^|[^а-яёa-z0-9])пс([^а-яёa-z0-9]|$)/u', $q)) {
        return ['entity' => 'ООО "Пицца Стар"', 'label' => 'Пицца Стар'];
    }
    if (preg_match('/воглия|матта|voglia/u', $q)
        || preg_match('/(^|[^а-яёa-z0-9])вм([^а-яёa-z0-9]|$)/u', $q)) {
        return ['entity' => 'ООО "Воглия Матта"', 'label' => 'Воглия Матта'];
    }
    return ['entity' => 'ООО "Бургер БК"', 'label' => 'Бургер БК'];
}

/** Определения 3 безопасных инструментов (формат function-calling). */
function faqToolDefinitions(): array
{
    return [
        ['type' => 'function', 'function' => [
            'name' => 'get_stock',
            'description' => 'Остаток товара на складе: наличие, запас в днях, дневной расход. Для вопросов «какой остаток», «есть ли на складе», «сколько осталось».',
            'parameters' => ['type' => 'object', 'properties' => [
                'query' => ['type' => 'string', 'description' => 'Артикул или название товара'],
            ], 'required' => ['query']],
        ]],
        ['type' => 'function', 'function' => [
            'name' => 'get_nomenclature',
            'description' => 'Карточка товара из справочника: артикул, название, кейсовка (сколько в коробке), кратность заказа, единица измерения. Для вопросов про номенклатуру, артикул, кейсовку/вложение, кратность. Поставщика не возвращает.',
            'parameters' => ['type' => 'object', 'properties' => [
                'query' => ['type' => 'string', 'description' => 'Артикул или название товара'],
            ], 'required' => ['query']],
        ]],
        ['type' => 'function', 'function' => [
            'name' => 'get_analogs',
            'description' => 'Аналоги — взаимозаменяемые товары из той же группы аналогов. Для вопросов «какие аналоги», «чем заменить товар».',
            'parameters' => ['type' => 'object', 'properties' => [
                'query' => ['type' => 'string', 'description' => 'Артикул или название товара'],
            ], 'required' => ['query']],
        ]],
    ];
}

function faqExecuteTool(string $name, array $args, string $entity): string
{
    $q = (string)($args['query'] ?? '');
    switch ($name) {
        case 'get_stock':        return faqToolStock($q, $entity);
        case 'get_nomenclature': return faqToolNomenclature($q, $entity);
        case 'get_analogs':      return faqToolAnalogs($q, $entity);
        default:                 return 'Неизвестный инструмент.';
    }
}

/** Условия поиска по артикулу (4+ цифр) или словам названия (3+ букв). */
function faqBuildSearch(string $query, string $nameCol, string $skuCol, array &$params, ?string $extraNameCol = null): string
{
    $conds = [];
    if (preg_match_all('/\b(\d{4,})\b/', $query, $m)) {
        foreach ($m[1] as $sku) { $conds[] = "$skuCol LIKE ?"; $params[] = "%{$sku}%"; }
    } else {
        $words = preg_split('/[\s,.;:!?()"]+/u', mb_strtolower($query, 'UTF-8'));
        foreach ($words as $w) {
            $w = trim($w);
            if (mb_strlen($w) >= 3) {
                if ($extraNameCol) { $conds[] = "($nameCol LIKE ? OR $extraNameCol LIKE ?)"; $params[] = "%{$w}%"; $params[] = "%{$w}%"; }
                else               { $conds[] = "$nameCol LIKE ?"; $params[] = "%{$w}%"; }
            }
        }
    }
    if (!$conds) { $conds[] = "$nameCol LIKE ?"; $params[] = "%" . trim($query) . "%"; }
    return '(' . implode(' OR ', $conds) . ')';
}

/** Остатки на складе по одному юрлицу. Без цен и истории заказов. */
function faqToolStock(string $query, string $entity): string
{
    global $pdo;
    $query = trim($query);
    if ($query === '') return 'Уточните товар: артикул или название.';
    $params = [];
    $where = faqBuildSearch($query, 'p.name', 'a.sku', $params);
    $sql = "SELECT a.sku, p.name, a.stock, a.consumption, a.period_days,
                   COALESCE(p.unit_of_measure,'шт') uom
            FROM analysis_data a
            LEFT JOIN products p ON p.sku = a.sku AND p.legal_entity = a.legal_entity AND p.is_active = 1
            WHERE a.legal_entity = ? AND {$where}
            ORDER BY a.stock DESC LIMIT 15";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$entity], $params));
    $rows = $st->fetchAll();
    if (!$rows) return "Остатков по запросу «{$query}» не найдено (юрлицо {$entity}).";
    $out = "Остатки на складе (юрлицо {$entity}):\n";
    foreach ($rows as $r) {
        $u = function_exists('getUomLabel') ? getUomLabel($r['uom'] ?? 'шт') : ($r['uom'] ?? 'шт');
        $daily = ($r['period_days'] > 0) ? round($r['consumption'] / $r['period_days'], 1) : 0;
        $days  = $daily > 0 ? round($r['stock'] / $daily) : '∞';
        $name  = $r['name'] ? $r['sku'] . ' ' . $r['name'] : $r['sku'];
        $out  .= "• {$name}: остаток {$r['stock']} {$u}";
        if ($daily > 0) $out .= ", расход {$daily} {$u}/день, запас ~{$days} дн.";
        $out .= "\n";
    }
    return $out;
}

/** Номенклатура (справочник) по группе юрлица. Без цен. */
function faqToolNomenclature(string $query, string $entity): string
{
    global $pdo;
    $query = trim($query);
    if ($query === '') return 'Уточните товар: артикул или название.';
    $ents  = getEntitiesInGroup(getEntityGroup($entity));
    $entPh = implode(',', array_fill(0, count($ents), '?'));
    $params = [];
    $where = faqBuildSearch($query, 'p.name', 'p.sku', $params, 'p.analog_group');
    // Поставщика НЕ выбираем и НЕ показываем — данные о поставщиках закрыты.
    $sql = "SELECT p.sku, p.name, p.qty_per_box, p.multiplicity,
                   COALESCE(p.unit_of_measure,'шт') uom, p.analog_group
            FROM products p
            WHERE p.is_active = 1 AND p.legal_entity IN ({$entPh}) AND {$where}
            ORDER BY p.name LIMIT 15";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge($ents, $params));
    $rows = $st->fetchAll();
    if (!$rows) return "Номенклатуры по запросу «{$query}» не найдено.";
    $out = "Номенклатура:\n";
    foreach ($rows as $r) {
        $u = $r['uom'];
        $perBox = $u === 'л' ? 'л/кор.' : ($u === 'кг' ? 'кг/кор.' : 'шт./кор.');
        $out .= "• <b>{$r['sku']} {$r['name']}</b> — "
              . "кейсовка: {$r['qty_per_box']} {$perBox}, кратность: {$r['multiplicity']}, ед.: {$u}";
        if (!empty($r['analog_group'])) $out .= ", группа аналогов: {$r['analog_group']}";
        $out .= "\n";
    }
    return $out;
}

/** Аналоги — все товары из той же группы аналогов. */
function faqToolAnalogs(string $query, string $entity): string
{
    global $pdo;
    $query = trim($query);
    if ($query === '') return 'Уточните товар: артикул или название.';
    $ents  = getEntitiesInGroup(getEntityGroup($entity));
    $entPh = implode(',', array_fill(0, count($ents), '?'));
    $params = [];
    $where = faqBuildSearch($query, 'p.name', 'p.sku', $params);
    $st = $pdo->prepare("SELECT DISTINCT p.analog_group FROM products p
            WHERE p.is_active = 1 AND p.legal_entity IN ({$entPh})
              AND p.analog_group IS NOT NULL AND p.analog_group <> '' AND {$where}
            LIMIT 5");
    $st->execute(array_merge($ents, $params));
    $groups = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$groups) return "Группа аналогов по запросу «{$query}» не найдена. Возможно, у товара нет аналогов.";
    $out = '';
    foreach ($groups as $ag) {
        $s2 = $pdo->prepare("SELECT DISTINCT p.sku, p.name, COALESCE(p.unit_of_measure,'шт') uom
                FROM products p
                WHERE p.is_active = 1 AND p.legal_entity IN ({$entPh}) AND p.analog_group = ?
                ORDER BY p.name LIMIT 30");
        $s2->execute(array_merge($ents, [$ag]));
        $members = $s2->fetchAll();
        $out .= "Группа аналогов «{$ag}» (взаимозаменяемые товары):\n";
        foreach ($members as $mb2) $out .= "• {$mb2['sku']} {$mb2['name']}\n";
        $out .= "\n";
    }
    return $out;
}

/**
 * FAQ-ответ с доступом к данным: DeepSeek + 3 безопасных инструмента.
 * Юрлицо для остатков уже определено ($entity). При неудаче возвращает null
 * (вызывающий откатится на обычный FAQ по инструкциям).
 */
function askRestaurantFaqWithTools(string $question, string $entity, string $entityLabel, string $threadText = '', string $learnedText = ''): ?string
{
    $apiKey = $GLOBALS['DEEPSEEK_API_KEY'] ?? ($_ENV['DEEPSEEK_API_KEY'] ?? '');
    if (!$apiKey) return null;

    $kb = getRestaurantFaqKB();
    $today = date('d.m.Y');
    $sys = <<<P
Ты — справочный помощник в общем рабочем чате ресторанов сети Burger King в Беларуси.
Помогаешь по работе с порталом отдела закупок и даёшь СПРАВОЧНЫЕ данные по складу.

== ЧТО ТЕБЕ ДОСТУПНО ==
Три инструмента (вызывай их для вопросов про товары):
- get_stock — остаток товара на складе (наличие, запас в днях, расход).
- get_nomenclature — карточка товара: артикул, название, кейсовка, кратность, единица.
- get_analogs — аналоги (взаимозаменяемые товары).
Данные показываются по юрлицу: <b>{$entityLabel}</b>. Если пользователь имел в виду
другое юрлицо — он может написать «по ВМ» (Воглия Матта) или «по ПС» (Пицца Стар).

== ЧЕГО У ТЕБЯ НЕТ (НИКОГДА НЕ ПОКАЗЫВАЙ) ==
- Цены и стоимость товаров.
- Поставщиков: какой поставщик у товара, контакты, кто поставляет. НЕ называй
  поставщиков, даже если знаешь название из контекста.
- Заказы поставщикам, заявки, ожидаемые/прошлые поставки, кто что заказал.
- Расход и продажи по конкретным ресторанам.
- Данные других юрлиц, кроме запрошенного.
Если просят цену, поставщика, заказы, поставки — вежливо скажи, что этой информации
у тебя нет, она в личном кабинете ресторана или у отдела закупок. НЕ выдумывай.

== ПРАВИЛА ==
- Отвечай по-русски, коротко, простым языком (сотрудники ресторанов — не айтишники).
- Для вопросов про остаток/наличие/номенклатуру/кейсовку/кратность/аналоги — СНАЧАЛА
  вызови нужный инструмент, потом ответь по полученным данным. Не выдумывай.
- Если инструмент ничего не нашёл — так и скажи, предложи уточнить артикул или название.
- Для вопросов про процессы (возврат кег, вход в кабинет, привязка Telegram, сброс
  пароля, как сообщить об ошибке) — отвечай по разделу «БАЗА ЗНАНИЙ», без инструментов.
- Если вопрос не про портал и не про товары — скажи, что помогаешь только по работе
  с порталом.
- Формат для Telegram: <b>жирный</b> для важного, списки через «•». НЕ используй
  Markdown (**, ##, таблицы, ```). Не повторяй вопрос, отвечай сразу по существу.
- Минимум вызовов инструментов (1–2). База данных — MySQL, схему БД не запрашивай.

== НАКОПЛЕННЫЕ ЗНАНИЯ ==
Если ниже придёт блок «РАНЕЕ ОТДЕЛ ЗАКУПОК ОТВЕЧАЛ» — это проверенные ответы
сотрудников на похожие вопросы. Если такой ответ подходит — опирайся на него и
передавай его суть, НЕ добавляя домыслов сверх того, что написал сотрудник.
Если ответ не подходит к вопросу — игнорируй его. Блок «НЕДАВНИЕ СООБЩЕНИЯ В
ГРУППЕ» — контекст обсуждения, отвечай на ПОСЛЕДНИЙ вопрос с учётом контекста.

Сегодня: {$today}

== БАЗА ЗНАНИЙ ==
{$kb}
P;

    // Собираем пользовательское сообщение: контекст ветки + накопленные знания
    // + сам вопрос. Группа многоучастниковая, поэтому контекст — текстом, а не
    // ролями (нельзя пометить каждого участника как user/assistant).
    $userMsg = '';
    if (trim($threadText) !== '') {
        $userMsg .= "НЕДАВНИЕ СООБЩЕНИЯ В ГРУППЕ (контекст):\n" . mb_substr($threadText, 0, 2500) . "\n\n";
    }
    if (trim($learnedText) !== '') {
        $userMsg .= "РАНЕЕ ОТДЕЛ ЗАКУПОК ОТВЕЧАЛ НА ПОХОЖЕЕ:\n" . mb_substr($learnedText, 0, 2000) . "\n\n";
    }
    $userMsg .= "Вопрос: {$question}";

    $tools = faqToolDefinitions();
    $messages = [
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user', 'content' => $userMsg],
    ];

    // Цикл вызова инструментов. На последней итерации инструменты не даём —
    // модель обязана дать текстовый ответ.
    $maxIter = 4;
    for ($i = 0; $i < $maxIter; $i++) {
        $payload = [
            'model' => 'deepseek-chat',
            'messages' => $messages,
            'max_tokens' => 1200,
            'temperature' => 0.2,
        ];
        if ($i < $maxIter - 1) $payload['tools'] = $tools;
        $ch = curl_init('https://api.deepseek.com/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if (!$response || $httpCode !== 200) {
            error_log("FAQ Tools: HTTP {$httpCode}, err={$err}");
            return null;
        }
        $data = json_decode($response, true);
        $choice = $data['choices'][0] ?? null;
        if (!$choice) return null;
        $m = $choice['message'];
        $finish = $choice['finish_reason'] ?? '';

        if ($finish === 'tool_calls' && !empty($m['tool_calls'])) {
            $messages[] = $m;
            $seen = [];
            foreach ($m['tool_calls'] as $tc) {
                $tName = $tc['function']['name'] ?? '';
                $tArgs = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
                $key = $tName . json_encode($tArgs);
                if (isset($seen[$key])) {
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $tc['id'], 'content' => $seen[$key]];
                    continue;
                }
                $res = faqExecuteTool($tName, $tArgs, $entity);
                if (mb_strlen($res) > 5000) $res = mb_substr($res, 0, 4800) . "\n…(показаны основные)";
                $seen[$key] = $res;
                $messages[] = ['role' => 'tool', 'tool_call_id' => $tc['id'], 'content' => $res];
                error_log("FAQ tool: {$tName}(" . json_encode($tArgs, JSON_UNESCAPED_UNICODE) . ") => " . mb_strlen($res) . "b");
            }
            continue;
        }

        $answer = $m['content'] ?? '';
        $answer = preg_replace('/<think>[\s\S]*?<\/think>/u', '', $answer);
        $answer = trim($answer);
        return $answer !== '' ? botMdToHtml($answer) : null;
    }
    return null;
}

// ═══════════════════════════════════════════════════════════════════════
//  Контекст группы (бот читает весь чат) и обучение на ответах закупки.
// ═══════════════════════════════════════════════════════════════════════

/**
 * Кто написал в группе: сотрудник отдела закупок (есть в users по
 * telegram_chat_id) или гость (ресторан/посторонний).
 */
function faqIdentifyGroupSender(array $from): array
{
    global $pdo;
    $fid  = (int)($from['id'] ?? 0);
    $name = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
    if ($name === '') $name = (string)($from['username'] ?? 'Гость');
    if ($fid > 0) {
        try {
            $st = $pdo->prepare("SELECT name, role FROM users WHERE telegram_chat_id = ? LIMIT 1");
            $st->execute([(string)$fid]);
            $u = $st->fetch();
            if ($u) return ['name' => $u['name'], 'role' => $u['role'], 'is_staff' => true];
        } catch (Throwable $e) { /* нет таблицы/связи — считаем гостем */ }
    }
    return ['name' => mb_substr($name, 0, 64), 'role' => 'guest', 'is_staff' => false];
}

/** Добавляет сообщение в скользящий буфер обсуждения группы (последние 14). */
function faqThreadAppend($chatId, string $label, string $text): void
{
    if (!function_exists('tgStateGet') || !function_exists('tgStateSet')) return;
    $text = trim(mb_substr($text, 0, 400));
    if ($text === '') return;
    $st = tgStateGet($chatId, 'group_thread');
    $msgs = (is_array($st) && !empty($st['msgs']) && is_array($st['msgs'])) ? $st['msgs'] : [];
    $msgs[] = ['w' => mb_substr($label, 0, 40), 't' => $text];
    if (count($msgs) > 14) $msgs = array_slice($msgs, -14);
    tgStateSet($chatId, 'group_thread', ['msgs' => $msgs], 6 * 3600);
}

/** Текст последних сообщений обсуждения группы (для контекста). */
function faqThreadText($chatId): string
{
    if (!function_exists('tgStateGet')) return '';
    $st = tgStateGet($chatId, 'group_thread');
    if (!is_array($st) || empty($st['msgs']) || !is_array($st['msgs'])) return '';
    $lines = [];
    foreach ($st['msgs'] as $m) {
        $w = $m['w'] ?? '?';
        $t = $m['t'] ?? '';
        if ($t !== '') $lines[] = "[{$w}]: {$t}";
    }
    return implode("\n", $lines);
}

/** Запоминает пару «вопрос → ответ сотрудника» (с защитой от дублей). */
function faqLearnCapture($groupId, string $question, string $answer, ?string $name, ?string $role): void
{
    global $pdo;
    try {
        $q = mb_substr($question, 0, 2000);
        $a = mb_substr($answer, 0, 4000);
        $chk = $pdo->prepare("SELECT id FROM bot_learned_qa WHERE group_id = ? AND question = ? AND answer = ? LIMIT 1");
        $chk->execute([(int)$groupId, $q, $a]);
        if ($chk->fetchColumn()) return;
        $pdo->prepare("INSERT INTO bot_learned_qa (group_id, question, answer, author_name, author_role) VALUES (?, ?, ?, ?, ?)")
            ->execute([(int)$groupId, $q, $a, $name, $role]);
        error_log("FAQ learn: запомнен ответ от {$name} (группа {$groupId})");
    } catch (Throwable $e) {
        error_log("FAQ learn capture failed: " . $e->getMessage());
    }
}

/** Ищет накопленные ответы закупки по ключевым словам вопроса (топ-3). */
function faqLearnSearch($groupId, string $question): string
{
    global $pdo;
    try {
        $words = preg_split('/[\s,.;:!?()"]+/u', mb_strtolower($question, 'UTF-8'));
        $stop = ['как','что','где','когда','почему','можно','нужно','надо','это','для','при','или',
                 'есть','нет','мне','вам','наш','этот','такой','быть','чтобы','какой','какая','какие'];
        $conds = []; $params = [(int)$groupId];
        foreach ($words as $w) {
            $w = trim($w);
            if (mb_strlen($w) >= 4 && !in_array($w, $stop, true)) {
                $conds[] = "question LIKE ?";
                $params[] = "%{$w}%";
                if (count($conds) >= 6) break;
            }
        }
        if (!$conds) return '';
        $sql = "SELECT question, answer FROM bot_learned_qa
                WHERE group_id = ? AND (" . implode(' OR ', $conds) . ")
                ORDER BY created_at DESC LIMIT 3";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();
        if (!$rows) return '';
        $out = '';
        foreach ($rows as $r) {
            $q = mb_substr(trim($r['question']), 0, 200);
            $a = mb_substr(trim($r['answer']), 0, 600);
            $out .= "Вопрос: {$q}\nОтвет: {$a}\n\n";
        }
        return trim($out);
    } catch (Throwable $e) {
        return '';
    }
}
