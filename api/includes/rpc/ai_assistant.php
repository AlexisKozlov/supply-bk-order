<?php
/**
 * RPC: ИИ-ассистент закупок (веб-портал).
 *
 * Переиспользует движок Телеграм-бота: askDeepSeekWithTools() из bot_tools.php —
 * DeepSeek (платный, OpenAI-совместимый) с function-calling и 15 инструментами
 * к данным портала. Инструменты сами фильтруют по юрлицу и роли; run_sql доступен
 * только admin/manager.
 *
 * Подключается из api/includes/rpc.php (приватный блок, после checkAuth).
 * Глобальные: $pdo, $body, $fn, $authUser, $authUserName.
 *
 * Доступ: любой авторизованный сотрудник (checkAuth уже пройден выше).
 */

if ($fn === 'ai_assistant') {
    $question = trim((string)($body['question'] ?? ''));
    if ($question === '') respond(['error' => 'Пустой вопрос'], 400);
    if (mb_strlen($question) > 2000) $question = mb_substr($question, 0, 2000);

    // Юрлицо: фронт присылает текущее; иначе — основное БК.
    $entity = trim((string)($body['entity'] ?? ''));
    if ($entity === '') $entity = 'ООО "Бургер БК"';

    // История чата (последние пары) для многоходового диалога.
    $history = [];
    if (!empty($body['history']) && is_array($body['history'])) {
        foreach (array_slice($body['history'], -8) as $h) {
            if (!is_array($h)) continue;
            $history[] = ['role' => ($h['role'] ?? 'user'), 'content' => (string)($h['content'] ?? '')];
        }
    }

    // Движок бота (на случай если ещё не подключён в этом запросе).
    foreach (['bot_state.php', 'bot_lookup.php', 'bot_tools.php'] as $f) {
        $p = __DIR__ . '/../' . $f;
        if (is_file($p)) require_once $p;
    }
    if (!function_exists('askDeepSeekWithTools')) {
        respond(['error' => 'ИИ-движок недоступен'], 500);
    }

    $userName = $authUserName ?: 'Сотрудник';
    $answer = askDeepSeekWithTools($question, $entity, $userName, $authUser, $history);

    // Запасной провайдер с инструментами (Gemini), если DeepSeek не ответил.
    if (!$answer && function_exists('askWithTools')) {
        $answer = askWithTools($question, $entity, $userName, $authUser);
    }

    if (!$answer) {
        respond(['error' => 'ИИ временно недоступен, попробуйте ещё раз'], 503);
    }

    respond(['answer' => $answer]);
}
