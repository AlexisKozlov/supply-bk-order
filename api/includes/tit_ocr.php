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
function titOcrImage(string $imagePath): string
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

    // Поднимаем фото с телефона до ~2400px по большой стороне. Tesseract
    // ловит мелкий текст ТТН только при высоте строки ~30px (≈300 dpi).
    // На 1600px из 9-12 МП фото буквы получаются по 12-15px → много мусора.
    $maxSide = max($w, $h);
    if ($maxSide < 2400) {
        $scale = 2400 / $maxSide;
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
    imagefilter($src, IMG_FILTER_GRAYSCALE);
    // Лёгкий контраст помогает на бледных сканах.
    imagefilter($src, IMG_FILTER_CONTRAST, -20);

    $processed = tempnam(sys_get_temp_dir(), 'tit_ocr_') . '.png';
    imagepng($src, $processed);
    imagedestroy($src);

    // Прогоняем дважды: psm 6 (uniform block) хорошо ловит сплошной текст,
    // psm 11 (sparse text) — разрозненные надписи в ячейках накладной.
    // Результаты конкатенируем — парсер плиты потом сам найдёт совпадения.
    $combined = '';
    foreach ([6, 11] as $psm) {
        $outBase = tempnam(sys_get_temp_dir(), 'tit_ocr_out_');
        @unlink($outBase);
        $cmd = sprintf('tesseract %s %s -l rus+eng --psm %d 2>/dev/null',
            escapeshellarg($processed), escapeshellarg($outBase), $psm);
        exec($cmd, $_, $rc);
        if (is_file($outBase . '.txt')) {
            $combined .= ($combined !== '' ? "\n\n" : '') . (string)@file_get_contents($outBase . '.txt');
            @unlink($outBase . '.txt');
        }
    }
    @unlink($processed);
    return trim($combined);
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
    $text = '';
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
        $text = titOcrImage($path);
    } elseif ($ext === 'pdf') {
        $png = titPdfFirstPageToPng($path);
        if ($png === null) {
            return ['plates' => [], 'text' => '', 'why' => 'pdf_unsupported'];
        }
        $text = titOcrImage($png);
        @unlink($png);
    } else {
        return ['plates' => [], 'text' => '', 'why' => 'unsupported_format'];
    }

    if ($text === '') {
        return ['plates' => [], 'text' => '', 'why' => 'empty_text'];
    }

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
    if (!$candidates) {
        return ['plates' => [], 'text' => $text, 'why' => 'no_plate_found'];
    }

    // Уникализируем по нормализованному номеру, оставляем порядок появления.
    $seen = [];
    $uniq = [];
    foreach ($candidates as $c) {
        if (isset($seen[$c['plate']])) continue;
        $seen[$c['plate']] = true;
        $uniq[] = ['plate' => $c['plate'], 'raw' => $c['raw']];
    }
    return ['plates' => $uniq, 'text' => $text, 'why' => 'ok'];
}
