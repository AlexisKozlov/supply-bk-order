<?php
/**
 * RPC распознавания накладных через AI Vision.
 *
 * Принимает фото/скан/PDF накладной → возвращает массив строк товаров
 * {name, qty, price} для последующего ручного редактирования и копирования
 * в форму предзаказа 1С УТ.
 *
 * Поток:
 *   1. Файл (base64) — если PDF, конвертация первой страницы в PNG.
 *   2. OpenRouter / nemotron-nano-12b-v2-vl:free — распознавание таблицы.
 *   3. Парсинг JSON-ответа модели.
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 * Использует глобальные $pdo, $body, $fn, $authUser, $authUserName.
 */

if ($fn === 'invoice_recognize') {
    if (!$authUser) respond(['error' => 'Требуется авторизация'], 401);
    // AI Vision на сложных сканах — до 60 сек. Дефолтный max_execution_time
    // в php-fpm обычно 30, поднимаем чтобы не прерывало запрос внутри.
    set_time_limit(180);

    $fileB64 = (string)($body['file_b64'] ?? '');
    $mime    = (string)($body['mime']     ?? '');
    if ($fileB64 === '') respond(['error' => 'Файл не передан'], 400);

    $binary = base64_decode($fileB64, true);
    if ($binary === false) respond(['error' => 'Не удалось декодировать файл'], 400);

    // Лимит 8 МБ — этого хватает на типичный скан/фото накладной, отсекает
    // случайно загруженные huge-сканы и DoS-попытки.
    if (strlen($binary) > 8 * 1024 * 1024) respond(['error' => 'Файл слишком большой (>8 МБ)'], 400);

    // Если PDF — конвертация первой страницы в PNG. Используем pdftoppm —
    // он уже используется в TIT-модуле, проверен на проде.
    $imageBinary = $binary;
    $imageMime   = $mime ?: 'image/jpeg';
    if (str_contains($mime, 'pdf') || str_starts_with($binary, '%PDF')) {
        $tmpPdf = tempnam(sys_get_temp_dir(), 'inv_pdf_');
        file_put_contents($tmpPdf, $binary);
        $outBase = tempnam(sys_get_temp_dir(), 'inv_png_');
        @unlink($outBase);

        $pdftoppm = '/usr/bin/pdftoppm';
        if (!is_executable($pdftoppm)) $pdftoppm = 'pdftoppm';
        $cmd = sprintf('%s -png -r 200 -f 1 -l 1 -singlefile %s %s 2>&1',
            escapeshellcmd($pdftoppm),
            escapeshellarg($tmpPdf),
            escapeshellarg($outBase));
        exec($cmd, $_out, $rc);
        @unlink($tmpPdf);
        $pngPath = $outBase . '.png';
        if ($rc !== 0 || !is_file($pngPath)) {
            respond(['error' => 'Не удалось конвертировать PDF в изображение. Загрузите фото или скан в формате JPG/PNG.'], 500);
        }
        $imageBinary = file_get_contents($pngPath);
        @unlink($pngPath);
        $imageMime   = 'image/png';
    }

    // Префильтр: только jpg/png/webp — остальное Gemini/nemotron не примут.
    if (!in_array($imageMime, ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'], true)) {
        respond(['error' => 'Поддерживаются JPG, PNG и PDF. Получено: ' . $imageMime], 400);
    }

    $imgB64 = base64_encode($imageBinary);
    $imgUri = "data:{$imageMime};base64,{$imgB64}";

    $prompt = <<<TXT
Это белорусская товарно-транспортная накладная (ТТН).
В товарном разделе таблица с товарами.

Извлеки КАЖДУЮ строку из товарного раздела (не из шапки, не из сводки внизу).
Верни СТРОГО JSON-массив без markdown-обёртки и без объяснений.
Формат: [{"name": "полное название товара как в накладной", "qty": число, "price": число}]

Правила:
- "name" — полное название товара с характеристиками и страной (если есть).
- "qty" — количество из колонки "Количество" (десятичное число, точка как разделитель).
- "price" — цена за единицу из колонки "Цена" (десятичное число, точка как разделитель).
- Если в колонке "Количество" стоит "х" или "—" — игнорируй это, бери из соседних строк или верни 0.
- Не возвращай строки с итогами ("Всего", "Итого", "ВСЕГО НДС").
- Не возвращай пустые строки.
TXT;

    // OpenRouter — nemotron бесплатный, на тесте дал точные числа на белорусской ТТН.
    $orKey = $_ENV['OPENROUTER_API_KEY'] ?? getenv('OPENROUTER_API_KEY') ?: '';
    if (!$orKey) respond(['error' => 'Не настроен OPENROUTER_API_KEY'], 500);

    $models = [
        'nvidia/nemotron-nano-12b-v2-vl:free',
    ];

    $rawResponse = null;
    $lastError = null;
    foreach ($models as $model) {
        $payload = json_encode([
            'model' => $model,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text',      'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $imgUri]],
                ],
            ]],
            'max_tokens'  => 2048,
            'temperature' => 0.0,
        ]);
        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $orKey,
                'HTTP-Referer: ' . ($_ENV['SITE_URL'] ?? 'https://supply-department.online'),
                'X-Title: Supply Invoice Recognize',
            ],
            CURLOPT_TIMEOUT => 120,
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($http !== 200) {
            $lastError = "model={$model} http={$http} err={$err} resp=" . substr((string)$resp, 0, 200);
            error_log('[invoice] ' . $lastError);
            continue;
        }
        $d = json_decode((string)$resp, true);
        $text = $d['choices'][0]['message']['content'] ?? null;
        if (!$text) {
            $lastError = "model={$model} пустой ответ";
            continue;
        }
        $rawResponse = trim($text);
        break;
    }
    if ($rawResponse === null) {
        respond(['error' => 'AI не ответил: ' . ($lastError ?? 'unknown')], 502);
    }

    // Гарантированно убираем markdown-обёртку ```json … ``` если модель её
    // прилепила (instructed to не делать, но LLM иногда срывается).
    $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $rawResponse) ?? $rawResponse;
    // Иногда модель пишет «Вот результат:» перед JSON — обрежем до первого «[».
    $bracket = mb_strpos($clean, '[');
    if ($bracket !== false) $clean = mb_substr($clean, $bracket);
    // Аналогично хвост после последней «]».
    $lastBr = mb_strrpos($clean, ']');
    if ($lastBr !== false) $clean = mb_substr($clean, 0, $lastBr + 1);

    $parsed = json_decode($clean, true);
    if (!is_array($parsed)) {
        error_log('[invoice] не удалось распарсить JSON: ' . substr($rawResponse, 0, 500));
        respond(['error' => 'AI вернул неструктурированный ответ. Попробуйте другую фотографию.'], 502);
    }

    // Нормализация: gross sanity, удаляем явно битые строки.
    $rows = [];
    foreach ($parsed as $row) {
        if (!is_array($row)) continue;
        $name  = trim((string)($row['name']  ?? ''));
        $qtyR  = (string)($row['qty']        ?? '');
        $prcR  = (string)($row['price']      ?? '');
        if ($name === '') continue;
        $qty   = (float)str_replace([',', ' '], ['.', ''], $qtyR);
        $price = (float)str_replace([',', ' '], ['.', ''], $prcR);
        // Отсеиваем строки-итоги: те где совсем нет чисел.
        if ($qty <= 0 && $price <= 0) continue;
        $rows[] = [
            'name'  => $name,
            'qty'   => $qty,
            'price' => $price,
        ];
    }

    if (!$rows) respond(['error' => 'AI не нашёл ни одной строки товаров. Возможно, скан размытый или это не накладная.'], 422);

    respond(['rows' => $rows, 'count' => count($rows)]);
}
