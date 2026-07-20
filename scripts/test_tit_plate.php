<?php
/**
 * Проверка разбора номера машины в «Заявках на пропуск» (tit_*).
 *
 * Ручной прогон после правок в tit_parser / tit_normalize / tit_ocr:
 *     php scripts/test_tit_plate.php
 *
 * Проверяет синтетические строки и два реальных вложения из письма Суперпак
 * (email_log 100). Распознавание сканов небыстрое — прогон занимает ~2 минуты.
 */
require '/var/www/bk-calc/api/includes/tit_parser.php';
require '/var/www/bk-calc/api/includes/tit_ocr.php';

$fails = 0;
function check(string $name, $got, $want) {
    global $fails;
    $ok = $got === $want;
    if (!$ok) $fails++;
    printf("%s %s\n   получили: %s\n   ожидали:  %s\n", $ok ? 'OK  ' : 'ПЛОХО', $name,
        json_encode($got, JSON_UNESCAPED_UNICODE), json_encode($want, JSON_UNESCAPED_UNICODE));
}

function plates(string $text): array {
    $c = titFindPlateCandidates(titNormalizePlateSpacing($text));
    return array_column($c, 'plate');
}

// ── Баг: дата и обрывок буквы с соседней строки склеивались в «номер» ──
check('дата + буквы на следующей строке', plates("220075. в, Минск,\n\n09.2022\n\nет\n"), []);
check('дата и буквы на одной строке',      plates("накладная от 09.2022 ет"), []);
check('год как номер без региона',         plates("отгружено 2022 ET"), []);

// ── Настоящие номера должны продолжать распознаваться ──
check('старый формат с пробелом',   plates('Машина АС 6668-5'), ['AC66685']);
check('старый формат слитно',       plates('авто BB10185'), ['BB10185']);
check('новый формат',               plates('Газель 1234 AB-7'), ['1234AB7']);
check('новый формат задом наперёд', plates('Газель 1197-5 BA'), ['1197BA5']);
check('многосегментный',            plates('BB-10-18-5'), ['BB10185']);

// ── Реальные вложения из письма Суперпак (email_log 100) ──
$dir = '/var/www/bk-calc/api/uploads/tit_attachments/';
$expect = [
    '100_1784531879_bd6bb872_IMG_9235.jpeg' => ['AT73107'], // в накладной «гос. номер AT 7310-7»
    '100_1784531879_f557118b_IMG_9236.jpeg' => [],          // номера на листе нет
];
foreach ($expect as $f => $want) {
    if (!is_file($dir . $f)) { echo "ПРОПУСК (нет файла): $f\n"; continue; }
    $r = titOcrExtractPlate($dir . $f);
    check("накладная $f", array_column($r['plates'], 'plate'), $want);
}

echo $fails === 0 ? "\nВСЁ ХОРОШО\n" : "\nПРОВАЛОВ: $fails\n";
exit($fails === 0 ? 0 : 1);
