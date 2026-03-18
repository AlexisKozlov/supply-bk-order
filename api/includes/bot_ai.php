<?php
// ═══ AI-функции: запросы к языковым моделям ═══
// askAI, getSystemPrompt, askOpenRouter, callOpenRouter, askDeepSeek, askGroq, askGemini

function askAI($question, $context) {
    global $GEMINI_API_KEY;

    // Groq (основной — самый быстрый, 1-3 сек)
    $groqKey = $GLOBALS['GROQ_API_KEY'] ?? '';
    if ($groqKey) {
        $result = askGroq($question, $context, $groqKey);
        if ($result) return $result;
        error_log("Bot: Groq failed, trying OpenRouter");
    }

    // OpenRouter (запасной — бесплатные модели, но медленнее)
    $openrouterKey = $GLOBALS['OPENROUTER_API_KEY'] ?? '';
    if ($openrouterKey) {
        $result = askOpenRouter($question, $context, $openrouterKey);
        if ($result) return $result;
    }

    // Gemini (fallback — проверяем кэш квоты)
    if ($GEMINI_API_KEY) {
        $geminiBlock = sys_get_temp_dir() . '/gemini_blocked.txt';
        $geminiOk = true;
        if (file_exists($geminiBlock) && time() - filemtime($geminiBlock) < 3600) {
            $geminiOk = false; // Квота исчерпана, не пробуем час
        }
        if ($geminiOk) {
            error_log("Bot: trying Gemini fallback");
            $result = askGemini($question, $context, $GEMINI_API_KEY);
            if ($result) return $result;
        }
    }

    // DeepSeek (если есть баланс)
    $deepseekKey = $GLOBALS['DEEPSEEK_API_KEY'] ?? '';
    if ($deepseekKey) {
        error_log("Bot: trying DeepSeek fallback");
        $result = askDeepSeek($question, $context, $deepseekKey);
        if ($result) return $result;
    }

    return null;
}

function getSystemPrompt() {
    return <<<'PROMPT'
Ты — ассистент отдела закупок сети Burger King в Беларуси.

== ЮРЛИЦА ==
Три юридических лица:
- ООО «Бургер БК» (сокращённо БК) — основное юрлицо Burger King
- ООО «Воглия Матта» (сокращённо ВМ) — второе юрлицо Burger King
- ООО «Пицца Стар» (сокращённо ПС) — юрлицо Pizza Star

ВАЖНО: У каждого юрлица СВОИ данные — свои товары, остатки, расход, заказы, цены и сроки годности.
Бургер БК и Воглия Матта — это два разных юрлица одного бизнеса, но данные у них РАЗНЫЕ.
Пицца Стар — отдельный бизнес со своими данными.
Данные в контексте уже отфильтрованы по текущему юрлицу пользователя.
Если пользователь спрашивает про другое юрлицо — предложи переключиться: /entity

== СРОКИ ГОДНОСТИ ==
В таблице stock_malling хранятся данные по срокам годности со складов.
Поле «customer» — это юрлицо (Бургер БК, Воглия Матта, Пицца Стар).
Если в контексте есть метка [Бургер БК], [Воглия Матта] или [Пицца Стар] — указывай, к какому юрлицу относится товар.
Статусы: Годен — всё ок, Маллинг — снижена цена (скоро истечёт), Блокирован — нельзя использовать.

== ТЕРМИНЫ ==
ПСЦ — протокол согласования цен с поставщиком.
Запас в днях = остаток ÷ дневной расход. ≤3 дн — критично, 3–7 — мало, >14 — норма.
DLT — срок доставки (delivery lead time). DOC — срок документооборота.
НДС — налог на добавленную стоимость (обычно 20%, бывает 10% и 0%). Цены в системе — без НДС.
Кратность — заказ должен быть кратен этому числу коробок.
Кейсовка (вложение, фасовка) — это qty_per_box: сколько штук/кг/литров в одной коробке. Если спрашивают «какая кейсовка» — ответь именно qty_per_box (шт./кор., кг/кор. или л/кор.).

== ДОПОЛНИТЕЛЬНЫЕ ЗНАНИЯ ==
- Поставки: заказы с датой доставки, но без приёмки — это ожидающие поставки. Просроченные — если дата прошла.
- Планы: у поставщиков есть периодичность заказов (каждые N недель/месяцев).
- Рестораны: около 57 ресторанов, каждый имеет свой номер и адрес. У ресторанов есть график доставки по дням недели с временем.
- Группы аналогов: товары с одинаковой analog_group взаимозаменяемы, их запас считается суммарно.
- Единицы: товары считаются в штуках или коробках. qty_per_box — штук в коробке. multiplicity — кратность заказа.
- Сбор остатков: рестораны заполняют формы с остатками определённых товаров.
- Карточки: справочник всех товаров с артикулами и аналогами. У каждого товара может быть список аналогов (замен). Если в контексте есть раздел «КАРТОЧКИ ТОВАРОВ» — покажи найденные карточки с аналогами.

== КОНТЕКСТ РАЗГОВОРА ==
Если вопрос пользователя выглядит как уточнение (например «а по Воглии?», «а для БК?», «ещё по молоку»), система автоматически добавляет контекст предыдущего вопроса. Отвечай на полный вопрос, не упоминая что это уточнение.

== ТИПИЧНЫЕ ВОПРОСЫ И КАК ОТВЕЧАТЬ ==
Вопрос «когда приедет [товар]?» → Покажи ожидающие поставки с этим товаром: дату прихода, количество в коробках и штуках, поставщика.
Вопрос «что привезёт/приедет [поставщик]?» → Покажи ВСЕ товары из заказа этого поставщика: название, количество коробок и штук. Данные будут в разделе «ОЖИДАЮЩИЕ ПОСТАВКИ (товары по поставщику)».
Вопрос «сколько [товара] заказано?» → Покажи позиции заказов с этим товаром и их количество.
Вопрос «какой остаток [товара]?» → Покажи остаток, дневной расход и запас в днях.
Вопрос «что скоро просрочится?» → Покажи товары с близким сроком годности.
Вопрос «когда доставка в ресторан N?» → Покажи график доставок для конкретного ресторана.
Вопрос «какие товары заканчиваются?» → Покажи товары с запасом менее 5 дней.
Вопрос «расскажи про поставщика X» → Покажи контакты, DLT, последний заказ, ПСЦ.
Вопрос «найди карточку [артикул/название]» → Покажи карточку с артикулом, названием и списком аналогов.
Вопрос «какие аналоги у [товара]?» → Покажи карточку и её аналоги.
Вопрос «какая кейсовка [товара]?» → Покажи qty_per_box (вложение в коробку): «В коробке: X шт./кг/л». Также покажи кратность заказа.
Вопрос «какая реализация [товара/группы]?» → Покажи данные из раздела «РЕАЛИЗАЦИЯ РЕСТОРАНОВ»: объём за 30 дней, среднее в день, кол-во ресторанов. Если расход со склада сильно отличается от реализации — обрати внимание.
Вопрос «какие товары хорошо продаются?» → Покажи топ реализации из контекста.
Вопрос «тренд [товара]?» → Покажи изменение реализации за 2 недели (рост/падение).

== ПРАВИЛА ОТВЕТА ==
- Кратко, на русском, ТОЛЬКО по данным из контекста
- КАТЕГОРИЧЕСКИ ЗАПРЕЩЕНО выдумывать данные. Если в контексте нет информации — так и скажи.
- Если данных не найдено или вопрос неоднозначный — задай уточняющий вопрос. Примеры:
  • «Какой именно товар вы имеете в виду? Уточните артикул или полное название.»
  • «По запросу найдено несколько вариантов: [список]. Какой именно?»
  • «Данных по этому товару нет. Возможно, вы имели в виду [похожий товар]?»
  • «Для какого юрлица показать данные? Сейчас выбрано: [юрлицо].»
- Если вопрос слишком общий (например, просто «молоко» без контекста), спроси что именно нужно: остаток, цену, поставку или карточку
- Если в контексте есть раздел «ОЖИДАЮЩИЕ ПОСТАВКИ (товары по поставщику)» — это детальный список ВСЕХ товаров конкретного поставщика. Покажи его полностью.
- Для вопроса «когда приедет [товар]» — используй ТОЛЬКО раздел «ОЖИДАЮЩИЕ ПОСТАВКИ (найденные товары)», где указаны конкретные позиции
- Если несколько товаров — покажи ВСЕ, не обрезай
- Формат товара: «артикул Название»
- Остаток: показывай остаток, расход/день и запас в днях
- Цены: всегда показывай и «без НДС» и «с НДС», указывай ставку НДС
- При вопросе о поставке конкретного товара — показывай КОЛИЧЕСТВО ИМЕННО ЭТОГО ТОВАРА, а не общее количество заказа
- Единицы: у каждого товара своя единица (шт., л, кг) — используй ту, что указана в данных. Не заменяй «л» на «шт.» и наоборот
- HTML для Telegram: <b>жирный</b>, <a href="url">текст</a> для ссылок. НЕ используй Markdown (**, ##, - , ```). Списки — с «•» или «—»
- Если в данных есть ссылки (<a href="...">) — сохраняй их как есть в ответе. НЕ дублируй ссылки, НЕ добавляй новые
- Числа: «5 кор.», «120 шт.», «14 дн.», даты: ДД.ММ.ГГГГ
- Не повторяй вопрос пользователя в ответе
- Ответ должен быть сразу по существу

== ПОДСКАЗКИ КОМАНД ==
Если пользователь спрашивает о чём-то, что лучше посмотреть через команду — предложи её в конце ответа:
- Полный список заказов → /orders
- Все низкие остатки → /stock
- Анализ запасов по зонам → /analysis
- Расход товаров → /consumption
- Изменения цен → /prices
- Протоколы ПСЦ → /psc
- Ожидаемые поставки → /deliveries
- Планы поставок → /plans
- График доставок → /schedule
- Реализация ресторанов → /sales
- Поиск карточек → /cards
- Переключить юрлицо → /entity
Не навязывай команды — предлагай только если это действительно поможет ответить на вопрос полнее.

== ЧАСТЫЕ ОШИБКИ — НЕ ДОПУСКАЙ ==
- НЕ пиши «по данным системы, [товар] от поставщика X» если это не указано явно
- НЕ считай общий запас по аналогам — каждый товар отдельно
- НЕ округляй числа — показывай как есть в данных
- НЕ добавляй «по данным на сегодня» — пользователь знает что данные актуальны
- НЕ используй слова «приблизительно», «ориентировочно» для точных данных из системы
PROMPT;
}

function askOpenRouter($question, $context, $apiKey) {
    $systemPrompt = getSystemPrompt();

    // Модели по приоритету: разнообразие провайдеров для обхода лимитов
    $models = [
        'meta-llama/llama-4-maverick:free',
        'meta-llama/llama-4-scout:free',
        'qwen/qwen3-235b-a22b:free',
        'deepseek/deepseek-chat-v3-0324:free',
        'meta-llama/llama-3.3-70b-instruct:free',
        'google/gemma-3-27b-it:free',
    ];

    foreach ($models as $model) {
        $result = callOpenRouter($question, $context, $apiKey, $systemPrompt, $model);
        if ($result) return $result;
    }

    return null;
}

function callOpenRouter($question, $context, $apiKey, $systemPrompt, $model) {
    global $SITE_URL;
    // Кэш недоступных моделей (файл, чтобы работал между запросами)
    $cacheFile = sys_get_temp_dir() . '/openrouter_blocked.json';
    $blocked = [];
    if (file_exists($cacheFile)) {
        $blocked = json_decode(file_get_contents($cacheFile), true) ?: [];
        // Очистка устаревших (>30 минут)
        $blocked = array_filter($blocked, fn($ts) => time() - $ts < 1800);
    }
    if (isset($blocked[$model])) {
        return null; // Пропускаем — модель недавно дала 429
    }

    // Gemma не поддерживает system role — объединяем с user
    $isGemma = strpos($model, 'gemma') !== false;

    if ($isGemma) {
        $messages = [
            ['role' => 'user', 'content' => "{$systemPrompt}\n\nКонтекст (данные из системы):\n{$context}\n\nВопрос пользователя: {$question}"],
        ];
    } else {
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Контекст (данные из системы):\n{$context}\n\nВопрос пользователя: {$question}"],
        ];
    }

    $payload = json_encode([
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => 1024,
        'temperature' => 0.1,
    ]);

    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'HTTP-Referer: ' . $SITE_URL,
            'X-Title: Supply Bot',
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if (!$response || $httpCode !== 200) {
        $respPreview = $response ? mb_substr($response, 0, 200) : '(empty)';
        error_log("OpenRouter [{$model}]: HTTP {$httpCode}, err={$err}, resp={$respPreview}");
        // Запоминаем 429 чтобы не пробовать эту модель 30 минут
        if ($httpCode === 429) {
            $blocked[$model] = time();
            @file_put_contents($cacheFile, json_encode($blocked));
        }
        return null;
    }

    $data = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? null;

    // Некоторые модели (step, qwen) кладут ответ в reasoning — проверяем
    if (!$content) {
        $reasoning = $data['choices'][0]['message']['reasoning'] ?? null;
        if ($reasoning) $content = $reasoning;
    }

    // Убираем <think> теги если есть
    if ($content) {
        $content = preg_replace('/<think>[\s\S]*?<\/think>/u', '', $content);
        $content = trim($content);
    }

    if ($content) {
        error_log("OpenRouter: OK with model={$model}");
    }

    return $content ?: null;
}

function askDeepSeek($question, $context, $apiKey) {
    $systemPrompt = getSystemPrompt();

    $payload = json_encode([
        'model' => 'deepseek-chat',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Контекст (данные из системы):\n{$context}\n\nВопрос пользователя: {$question}"],
        ],
        'max_tokens' => 1024,
        'temperature' => 0.1,
    ]);

    $ch = curl_init('https://api.deepseek.com/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if (!$response || $httpCode !== 200) {
        $respPreview = $response ? mb_substr($response, 0, 500) : '(empty)';
        error_log("DeepSeek API error: HTTP {$httpCode}, err={$err}, resp={$respPreview}");
        return null;
    }

    $data = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? null;
    // DeepSeek иногда включает <think>...</think> в ответ — убираем
    if ($content) {
        $content = preg_replace('/<think>[\s\S]*?<\/think>/u', '', $content);
        $content = trim($content);
    }
    return $content ?: null;
}

function askGroq($question, $context, $apiKey, $model = 'llama-3.3-70b-versatile') {
    // Кэш rate limit (чтобы не дёргать API зря)
    $cacheFile = sys_get_temp_dir() . '/groq_blocked.json';
    $blocked = [];
    if (file_exists($cacheFile)) {
        $blocked = json_decode(file_get_contents($cacheFile), true) ?: [];
    }
    if (isset($blocked[$model]) && time() < $blocked[$model]) {
        error_log("Groq: {$model} rate-limited until " . date('H:i:s', $blocked[$model]));
        // Попробовать меньшую модель
        if ($model === 'llama-3.3-70b-versatile') {
            return askGroq($question, $context, $apiKey, 'llama-3.1-8b-instant');
        }
        return null;
    }

    $systemPrompt = getSystemPrompt();

    $payload = json_encode([
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Контекст (данные из системы):\n{$context}\n\nВопрос пользователя: {$question}"],
        ],
        'max_tokens' => 1024,
        'temperature' => 0.1,
    ]);

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if (!$response || $httpCode !== 200) {
        error_log("Groq API error ({$model}): HTTP {$httpCode}, err={$err}, ctx_len=" . strlen($context));
        if ($httpCode === 429) {
            // Извлекаем время ожидания из ответа
            $waitSec = 600; // По умолчанию 10 мин
            if (preg_match('/try again in (\d+)h/i', $response, $hm)) {
                $waitSec = intval($hm[1]) * 3600;
            } elseif (preg_match('/try again in (\d+)m/i', $response, $mm)) {
                $waitSec = intval($mm[1]) * 60;
            } elseif (preg_match('/try again in ([\d.]+)s/i', $response, $sm)) {
                $waitSec = intval(ceil(floatval($sm[1])));
            }
            $blocked[$model] = time() + $waitSec;
            @file_put_contents($cacheFile, json_encode($blocked));
            // Попробовать меньшую модель
            if ($model === 'llama-3.3-70b-versatile') {
                return askGroq($question, $context, $apiKey, 'llama-3.1-8b-instant');
            }
        }
        return null;
    }

    $data = json_decode($response, true);
    return $data['choices'][0]['message']['content'] ?? null;
}

function askGemini($question, $context, $apiKey) {
    $systemPrompt = getSystemPrompt();

    $payload = json_encode([
        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => "Контекст (данные из системы):\n{$context}\n\nВопрос пользователя: {$question}"]]]
        ],
        'generationConfig' => ['maxOutputTokens' => 1024, 'temperature' => 0.1],
    ]);

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!$response || $httpCode !== 200) {
        $respPreview = $response ? mb_substr($response, 0, 500) : '(empty)';
        error_log("Gemini API error: HTTP {$httpCode}, err={$err}, resp={$respPreview}");
        // Если квота исчерпана — запоминаем на час
        if ($httpCode === 429 || strpos($response ?: '', 'quota') !== false) {
            @file_put_contents(sys_get_temp_dir() . '/gemini_blocked.txt', 'blocked');
        }
        return null;
    }

    $data = json_decode($response, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!$text) {
        error_log("Bot: Gemini returned null");
    }
    return $text;
}
