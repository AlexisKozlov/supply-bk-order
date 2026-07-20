<?php
/**
 * OCR-обвязка для модуля «Заявка на пропуск».
 *
 * Принимает путь к файлу скана накладной (JPG/PNG/PDF) и пытается извлечь
 * из него номер машины. Использует уже установленный Tesseract (см.
 * api/includes/ocr.php) с языками rus+eng.
 *
 * PDF поддерживается только если в системе есть pdftoppm, ghostscript или
 * convert (ImageMagick). Если их нет — возвращаем пустой результат с
 * пометкой why=pdf_unsupported. Закупщик откроет файл и впишет номер
 * руками.
 */

require_once __DIR__ . '/tit_parser.php';

/**
 * Безопасный путь к утилите: проверяет что бинарь существует и исполнимый.
 * Возвращает absolute path или null.
 */
function titWhich(string $bin): ?string
{
    $bin = preg_replace('/[^a-z0-9\-_]/i', '', $bin);
    foreach (['/usr/bin/', '/usr/local/bin/', '/opt/homebrew/bin/'] as $dir) {
        if (is_executable($dir . $bin)) return $dir . $bin;
    }
    return null;
}

/**
 * Прогоняет картинку (PNG/JPG/GIF) через Tesseract → возвращает распознанный текст.
 * Перед OCR делает простую предобработку (resize + grayscale + бинаризация),
 * которая улучшает распознавание сжатых фото с телефона.
 *
 * Возвращает строку (может быть пустой при ошибке).
 */
function titOcrImage(string $imagePath, int $targetSide = 3600, int $psm = 11): string
{
    if (!is_file($imagePath) || !is_readable($imagePath)) return '';

    $info = @getimagesize($imagePath);
    if (!$info) return '';
    [$w, $h, $type] = $info;
    if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF], true)) return '';

    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($imagePath),
        IMAGETYPE_PNG  => @imagecreatefrompng($imagePath),
        IMAGETYPE_GIF  => @imagecreatefromgif($imagePath),
        default        => null,
    };
    if (!$src) return '';

    // Поднимаем скан до ~3600px по большой стороне. Проверено на реальной ТТН
    // 740x1068 (≈90 dpi): на 2400px строка «гос. номер AT 7310-7» читалась как
    // «Make AT F310», на 3600px — точно. Лист А4 при 3600px ≈ 300 dpi, это
    // рабочий режим Tesseract.
    $maxSide = max($w, $h);
    if ($maxSide < $targetSide) {
        $scale = $targetSide / $maxSide;
        $newW = (int)($w * $scale);
        $newH = (int)($h * $scale);
        $dst = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagedestroy($src);
        $src = $dst;
        $w = $newW;
        $h = $newH;
    }

    // Только grayscale, без жёсткой бинаризации. На фото ТТН свет неровный
    // (тень от руки/стола), и фиксированный порог 140 убивает текст в тёмных
    // зонах — Tesseract отлично работает с серой картинкой.
    // Только grayscale. Фильтр контраста убран намеренно: на той же ТТН он
    // размывал тонкие цифры и номер переставал читаться.
    imagefilter($src, IMG_FILTER_GRAYSCALE);

    $processed = tempnam(sys_get_temp_dir(), 'tit_ocr_') . '.png';
    imagepng($src, $processed);
    imagedestroy($src);

    $outBase = tempnam(sys_get_temp_dir(), 'tit_ocr_out_');
    @unlink($outBase);
    $cmd = sprintf('tesseract %s %s -l rus+eng --psm %d 2>/dev/null',
        escapeshellarg($processed), escapeshellarg($outBase), $psm);
    exec($cmd, $_, $rc);
    $text = '';
    if (is_file($outBase . '.txt')) {
        $text = (string)@file_get_contents($outBase . '.txt');
        @unlink($outBase . '.txt');
    }
    @unlink($processed);
    return trim($text);
}

/**
 * Конвертирует первую страницу PDF в PNG, используя любой доступный
 * инструмент. Если ни один не нашёлся — возвращает null.
 */
function titPdfFirstPageToPng(string $pdfPath): ?string
{
    if (!is_file($pdfPath)) return null;
    $out = tempnam(sys_get_temp_dir(), 'tit_pdf_') . '.png';
    @unlink($out);

    // pdftoppm (poppler-utils) — лучший вариант
    if ($bin = titWhich('pdftoppm')) {
        $cmd = sprintf('%s -png -r 200 -f 1 -l 1 -singlefile %s %s 2>/dev/null',
            $bin, escapeshellarg($pdfPath), escapeshellarg(preg_replace('/\.png$/', '', $out)));
        exec($cmd, $_, $rc);
        if ($rc === 0 && is_file($out)) return $out;
    }
    // ghostscript
    if ($bin = titWhich('gs')) {
        $cmd = sprintf('%s -dBATCH -dNOPAUSE -sDEVICE=png16m -r200 -dFirstPage=1 -dLastPage=1 -sOutputFile=%s %s 2>/dev/null',
            $bin, escapeshellarg($out), escapeshellarg($pdfPath));
        exec($cmd, $_, $rc);
        if ($rc === 0 && is_file($out)) return $out;
    }
    // ImageMagick convert
    if ($bin = titWhich('convert')) {
        $cmd = sprintf('%s -density 200 %s[0] %s 2>/dev/null',
            $bin, escapeshellarg($pdfPath), escapeshellarg($out));
        exec($cmd, $_, $rc);
        if ($rc === 0 && is_file($out)) return $out;
    }
    return null;
}

/**
 * Главная функция: пытается извлечь номер машины из файла скана накладной.
 *
 * @return array{
 *   plates: array<int, array{plate: string, raw: string}>,
 *   text: string,
 *   why: string  // 'ok' | 'unsupported_format' | 'pdf_unsupported' | 'empty_text' | 'no_plate_found' | 'file_missing'
 * }
 */
function titOcrExtractPlate(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return ['plates' => [], 'text' => '', 'why' => 'file_missing'];
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $imagePath = null;
    $tmpPng = null;
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
        $imagePath = $path;
    } elseif ($ext === 'pdf') {
        $tmpPng = titPdfFirstPageToPng($path);
        if ($tmpPng === null) {
            return ['plates' => [], 'text' => '', 'why' => 'pdf_unsupported'];
        }
        $imagePath = $tmpPng;
    } else {
        return ['plates' => [], 'text' => '', 'why' => 'unsupported_format'];
    }

    // Проходы по возрастанию стоимости: psm 11 (разрозненный текст в ячейках
    // накладной) быстрее psm 6 примерно втрое и на реальных ТТН находит номер
    // не хуже. Тяжёлый psm 6 запускаем только если номер не нашёлся — так
    // обычное письмо обрабатывается быстрее, чем раньше, а сложное всё равно
    // дочитывается. Крон ограничен 600 сек, поэтому проходов ровно два.
    $text = '';
    $result = null;
    foreach ([11, 6] as $psm) {
        $passText = titOcrImage($imagePath, 3600, $psm);
        if ($passText === '') continue;
        $text = $passText;
        $found = titOcrPlatesFromText($passText);
        if ($found) { $result = $found; break; }
    }
    if ($tmpPng !== null) @unlink($tmpPng);

    if ($text === '') {
        return ['plates' => [], 'text' => '', 'why' => 'empty_text'];
    }
    if ($result === null) {
        return ['plates' => [], 'text' => $text, 'why' => 'no_plate_found'];
    }
    return ['plates' => $result, 'text' => $text, 'why' => 'ok'];
}

/**
 * Ищет номера машин в распознанном тексте накладной.
 * Возвращает список ['plate' => ..., 'raw' => ...] или пустой массив.
 */
function titOcrPlatesFromText(string $text): array
{
    // Сужаем зону поиска: ищем строки с якорями (автомобиль / гос. номер /
    // транспортное средство и т.п.). Если якорь нашёлся — берём кандидатов
    // ТОЛЬКО из этих строк. Это отсекает марки («ATEGO 1823»), номера
    // документов и прочий мусор, которым богаты накладные. Если якоря нет
    // совсем — fallback на весь текст (хуже, но лучше чем ничего).
    // Заодно берём пару строк до и после якоря — номер может быть в соседнем
    // ряду таблицы из-за переноса строк OCR'ом.
    $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
    $anchorRx = '/(автомоб|транспорт|гос[\.\s]*ном|тр[\.\s]*ср|грузоотправ|перевозчи|тягач|водител|марка|vehicle|tractor)/iu';
    $anchorLines = [];
    foreach ($lines as $i => $line) {
        if (preg_match($anchorRx, $line)) {
            for ($d = -1; $d <= 1; $d++) {
                if (isset($lines[$i + $d])) $anchorLines[$i + $d] = $lines[$i + $d];
            }
        }
    }
    ksort($anchorLines);
    $searchText = $anchorLines ? implode("\n", $anchorLines) : $text;

    // Тот же препроцессор склейки, что в парсере писем — на OCR-текст,
    // где номер может прийти как «АС 6668-5» (с пробелом).
    $searchText = titNormalizePlateSpacing($searchText);
    $candidates = titFindPlateCandidates($searchText);
    if (!$candidates) return [];

    // Уникализируем по нормализованному номеру, оставляем порядок появления.
    $seen = [];
    $uniq = [];
    foreach ($candidates as $c) {
        if (isset($seen[$c['plate']])) continue;
        $seen[$c['plate']] = true;
        $uniq[] = ['plate' => $c['plate'], 'raw' => $c['raw']];
    }
    return $uniq;
}
