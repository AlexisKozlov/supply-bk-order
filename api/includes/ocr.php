<?php
/**
 * OCR-эндпоинт: распознавание текста со скриншотов через серверный Tesseract.
 * POST /api/ocr — принимает изображение (multipart/form-data, поле "image").
 * Возвращает { text: "распознанный текст" }.
 */

if ($endpoint === 'ocr' && $method === 'POST') {
    if (!checkAuth($pdo)) { respond(['error' => 'Unauthorized'], 401); }

    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        respond(['error' => 'Файл не загружен'], 400);
    }

    $tmpFile = $_FILES['image']['tmp_name'];
    $maxSize = 10 * 1024 * 1024; // 10 MB
    if ($_FILES['image']['size'] > $maxSize) {
        respond(['error' => 'Файл слишком большой (макс. 10 МБ)'], 400);
    }

    // Validate image
    $imageInfo = @getimagesize($tmpFile);
    if (!$imageInfo) {
        respond(['error' => 'Файл не является изображением'], 400);
    }

    // Защита от pixel-bomb: маленький JPEG может декодироваться в гигабайты
    // памяти. Лимит 10 МБ на размер файла этого не закрывает. Проверяем размер
    // картинки в пикселях ДО декодирования.
    $origW = (int)$imageInfo[0];
    $origH = (int)$imageInfo[1];
    if ($origW <= 0 || $origH <= 0) {
        respond(['error' => 'Не удалось определить размеры изображения'], 400);
    }
    // Жёсткий лимит на исходник: 9 Мпикс (≈ 3000×3000). Этого хватает для
    // скриншотов накладных и упаковочных листов. Раньше было 25 Мпикс, что
    // после ×3 масштабирования давало пик памяти ~900 МБ — при параллельных
    // запросах php-fpm ловил OOM.
    $origPixels = $origW * $origH;
    if ($origPixels > 9_000_000) {
        respond(['error' => 'Изображение слишком большое (>9 Мпикс ≈ 3000×3000). Уменьшите его перед загрузкой.'], 400);
    }
    // Адаптивный масштаб: для маленьких картинок ×3 (нужно для мелкого текста),
    // для средних ×2, для крупных — без увеличения. Так пик памяти стабильный.
    $scaleAdaptive = ($origPixels < 1_000_000) ? 3 : (($origPixels < 4_000_000) ? 2 : 1);
    // Поднимаем memory_limit на этот запрос, чтобы исключить OOM на крайних
    // случаях (PHP-FPM по умолчанию 128M часто не хватает).
    @ini_set('memory_limit', '256M');

    // Preprocess: scale up 3x, add padding, convert to high-contrast B&W
    $src = null;
    switch ($imageInfo[2]) {
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($tmpFile); break;
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($tmpFile); break;
        case IMAGETYPE_BMP:  $src = @imagecreatefrombmp($tmpFile); break;
        case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($tmpFile); break;
        case IMAGETYPE_GIF:  $src = @imagecreatefromgif($tmpFile); break;
    }

    $processedFile = $tmpFile; // fallback to original if GD fails
    if ($src) {
        $origW = imagesx($src);
        $origH = imagesy($src);
        $scale = $scaleAdaptive;
        $pad = 60;
        $newW = $origW * $scale + $pad * 2;
        $newH = $origH * $scale + $pad * 2;

        $dst = imagecreatetruecolor($newW, $newH);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);
        imagecopyresampled($dst, $src, $pad, $pad, 0, 0, $origW * $scale, $origH * $scale, $origW, $origH);
        imagedestroy($src);

        // Convert to high-contrast B&W
        imagefilter($dst, IMG_FILTER_GRAYSCALE);
        imagefilter($dst, IMG_FILTER_CONTRAST, -50);
        // Threshold to pure B&W
        for ($y = 0; $y < $newH; $y++) {
            for ($x = 0; $x < $newW; $x++) {
                $rgb = imagecolorat($dst, $x, $y);
                $gray = $rgb & 0xFF;
                $bw = $gray < 140 ? 0x000000 : 0xFFFFFF;
                imagesetpixel($dst, $x, $y, $bw);
            }
        }

        $processedFile = tempnam(sys_get_temp_dir(), 'ocr_img_') . '.png';
        imagepng($dst, $processedFile);
        imagedestroy($dst);
    }

    $outputFile = tempnam(sys_get_temp_dir(), 'ocr_') . '.txt';
    $escapedImg = escapeshellarg($processedFile);
    $escapedOut = escapeshellarg(preg_replace('/\.txt$/', '', $outputFile));

    // Run Tesseract: Russian + English, PSM 6 (uniform block of text)
    $cmd = "tesseract {$escapedImg} {$escapedOut} -l rus+eng --psm 6 2>&1";
    exec($cmd, $output, $returnCode);

    // Cleanup processed image
    if ($processedFile !== $tmpFile) {
        @unlink($processedFile);
    }

    if ($returnCode !== 0) {
        @unlink($outputFile);
        error_log("Tesseract error: " . implode("\n", $output));
        respond(['error' => 'Ошибка распознавания'], 500);
    }

    $text = '';
    if (file_exists($outputFile)) {
        $text = file_get_contents($outputFile);
        @unlink($outputFile);
    }

    respond(['text' => trim($text)]);
}
