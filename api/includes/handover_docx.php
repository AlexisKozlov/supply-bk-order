<?php
/**
 * Выгрузка документа «Передача дел» в Word.
 *
 * Оформление повторяет бумажный шаблон отдела: коричневая шапка, оранжевые
 * заголовки разделов, таблицы с тёмной строкой заголовков и «зеброй».
 * Пустые разделы в файл не попадают — документ не должен выглядеть брошенным.
 */

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;

const HO_BROWN  = '4A2013';
const HO_ORANGE = 'C25E12';
const HO_INK    = '2E1C10';
const HO_GREY   = '8A7F72';
const HO_LINE   = 'E4D9CB';
const HO_ZEBRA  = 'FBF6F0';

function hoDate($v) {
    if (!$v) return '';
    $ts = strtotime((string)$v);
    return $ts ? date('d.m.Y', $ts) : (string)$v;
}

function hoTableStyle(PhpWord $word, $name) {
    $word->addTableStyle($name, [
        'borderColor' => HO_LINE,
        'borderSize'  => 6,
        'cellMargin'  => 60,
        'alignment'   => Jc::CENTER,
    ]);
}

function hoHeadCell($row, $text, $width) {
    $cell = $row->addCell($width, ['bgColor' => HO_BROWN, 'valign' => 'center']);
    $cell->addText(htmlspecialchars($text), ['bold' => true, 'size' => 9, 'color' => 'FFFFFF'],
        ['spaceAfter' => 40, 'spaceBefore' => 40]);
}

function hoBodyCell($row, $text, $width, $zebra = false, $bold = false) {
    $style = ['valign' => 'top'];
    if ($zebra) $style['bgColor'] = HO_ZEBRA;
    $cell = $row->addCell($width, $style);
    $text = (string)$text;
    $isEmpty = trim($text) === '';
    $cell->addText($isEmpty ? ' ' : htmlspecialchars($text),
        ['size' => 9.5, 'color' => HO_INK, 'bold' => $bold],
        ['spaceAfter' => 40, 'spaceBefore' => 40]);
}

/** Таблица «шапка + строки». $widths — в сантиметрах. */
function hoTable($section, PhpWord $word, array $headers, array $rows, array $widths) {
    static $n = 0;
    $style = 'hoTable' . (++$n);
    hoTableStyle($word, $style);
    $table = $section->addTable($style);

    $cm = fn($v) => (int)round(Converter::cmToTwip($v));

    $hr = $table->addRow();
    foreach ($headers as $i => $h) hoHeadCell($hr, $h, $cm($widths[$i]));

    foreach ($rows as $ri => $row) {
        $tr = $table->addRow();
        foreach ($row as $i => $val) {
            hoBodyCell($tr, $val, $cm($widths[$i] ?? 3), $ri % 2 === 1);
        }
    }
    $section->addTextBreak(1, ['size' => 6]);
    return $table;
}

function hoH1($section, $num, $text) {
    $p = $section->addTextRun(['spaceBefore' => 320, 'spaceAfter' => 120, 'keepNext' => true]);
    if ($num !== null) $p->addText($num . '  ', ['bold' => true, 'size' => 15, 'color' => HO_ORANGE]);
    $p->addText(htmlspecialchars($text), ['bold' => true, 'size' => 15, 'color' => HO_BROWN]);
}

function hoH2($section, $text) {
    $section->addText(htmlspecialchars($text), ['bold' => true, 'size' => 11.5, 'color' => HO_ORANGE],
        ['spaceBefore' => 200, 'spaceAfter' => 60, 'keepNext' => true]);
}

/**
 * Плашка с названием поставщика на всю ширину. Раньше это была просто
 * оранжевая строка текста, и подряд идущие поставщики читались как одно
 * полотно: непонятно, где заканчивается один и начинается другой.
 */
function hoSupplierBand($section, PhpWord $word, int $index, string $name, string $owner) {
    static $n = 0;
    $style = 'hoBand' . (++$n);
    $word->addTableStyle($style, ['borderSize' => 0, 'cellMargin' => 110, 'alignment' => Jc::CENTER]);
    $table = $section->addTable($style);
    $row = $table->addRow((int)round(Converter::cmToTwip(1.05)));

    // Номер по порядку: с ним видно, сколько поставщиков и где ты в списке.
    $num = $row->addCell((int)round(Converter::cmToTwip(1.2)), ['bgColor' => HO_ORANGE, 'valign' => 'center']);
    $num->addText((string)$index, ['bold' => true, 'size' => 16, 'color' => 'FFFFFF'],
        ['alignment' => Jc::CENTER, 'spaceBefore' => 0, 'spaceAfter' => 0]);

    $left = $row->addCell((int)round(Converter::cmToTwip(11.9)), ['bgColor' => HO_BROWN, 'valign' => 'center']);
    $left->addText(mb_strtoupper(htmlspecialchars($name)),
        ['bold' => true, 'size' => 15, 'color' => 'FFFFFF'], ['spaceBefore' => 0, 'spaceAfter' => 0]);

    $right = $row->addCell((int)round(Converter::cmToTwip(6)), ['bgColor' => HO_BROWN, 'valign' => 'center']);
    $right->addText(($owner !== '' && $owner !== '—' ? 'ведёт ' . htmlspecialchars($owner) : 'ответственный не назначен'),
        ['size' => 10, 'bold' => true, 'color' => 'F3C892'],
        ['alignment' => Jc::END, 'spaceBefore' => 0, 'spaceAfter' => 0]);
    $section->addTextBreak(1, ['size' => 6]);
    return $table;
}

/** Подпись блока внутри карточки поставщика: мелкие капсы с отбивкой. */
function hoBlockLabel($section, PhpWord $word, string $text) {
    static $n = 0;
    $style = 'hoLbl' . (++$n);
    $word->addTableStyle($style, ['borderSize' => 0, 'cellMargin' => 70, 'alignment' => Jc::CENTER]);
    $t = $section->addTable($style);
    $cell = $t->addRow()->addCell((int)round(Converter::cmToTwip(19.1)), ['bgColor' => HO_ZEBRA]);
    $cell->addText(mb_strtoupper(htmlspecialchars($text)),
        ['bold' => true, 'size' => 9, 'color' => HO_BROWN],
        ['spaceBefore' => 0, 'spaceAfter' => 0, 'keepNext' => true]);
}

/** Тонкая линия-разделитель между поставщиками. */
function hoDivider($section, PhpWord $word) {
    static $n = 0;
    $style = 'hoDiv' . (++$n);
    $word->addTableStyle($style, [
        'borderTopSize' => 6, 'borderTopColor' => HO_LINE,
        'borderBottomSize' => 0, 'borderLeftSize' => 0, 'borderRightSize' => 0,
        'cellMargin' => 0, 'alignment' => Jc::CENTER,
    ]);
    $t = $section->addTable($style);
    $t->addRow(20)->addCell((int)round(Converter::cmToTwip(19.1)))->addText('', ['size' => 4]);
    $section->addTextBreak(1, ['size' => 6]);
}

function hoHint($section, $text) {
    $section->addText(htmlspecialchars($text), ['italic' => true, 'size' => 9, 'color' => HO_GREY],
        ['spaceAfter' => 120]);
}

/** Отдаёт готовый .docx в браузер. */
function hoExportDocx(PDO $pdo, array $full) {
    [$tmp, $name] = hoBuildDocx($pdo, $full);
    header_remove('Content-Type');
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="handover.docx"; '
        . "filename*=UTF-8''" . rawurlencode($name));
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    unlink($tmp);
    exit;
}

/** Собирает файл и возвращает [путь во временной папке, имя для скачивания]. */
function hoBuildDocx(PDO $pdo, array $full) {
    require_once __DIR__ . '/../../vendor/autoload.php';

    $doc       = $full['doc'];
    $people    = $full['people'];
    $suppliers = array_values(array_filter($full['suppliers'], fn($s) => !empty($s['included'])));
    $items     = $full['items'];

    $peopleById = [];
    foreach ($people as $p) $peopleById[(int)$p['id']] = $p['name'];

    $word = new PhpWord();
    $word->setDefaultFontName('Calibri');
    $word->setDefaultFontSize(10);

    // Поля уменьшены: таблицам с позициями было тесно, длинные названия
    // товаров переносились по три строки и раздел превращался в «полотно».
    // При этих полях полезная ширина листа A4 — 18 см (было 16,7).
    // Размеры задаём целыми twips: PhpWord считает их дробными
    // («11905.511811023622»), и часть программ на таком файле спотыкается.
    $section = $word->addSection([
        'pageSizeW'    => 11906,  // A4, 21 см
        'pageSizeH'    => 16838,  // A4, 29,7 см
        'marginTop'    => (int)round(Converter::cmToTwip(1.0)),
        'marginBottom' => (int)round(Converter::cmToTwip(1.0)),
        'marginLeft'   => (int)round(Converter::cmToTwip(1.0)),
        'marginRight'  => (int)round(Converter::cmToTwip(0.9)),
    ]);

    // ── Шапка ──
    hoTableStyle($word, 'hoCover');
    $cover = $section->addTable('hoCover');
    $row = $cover->addRow();
    $cell = $row->addCell((int)round(Converter::cmToTwip(19.1)), ['bgColor' => HO_BROWN]);
    $cell->addText(mb_strtoupper(htmlspecialchars($doc['title'])),
        ['bold' => true, 'size' => 19, 'color' => 'FFFFFF'], ['spaceBefore' => 220, 'spaceAfter' => 40]);
    $cell->addText('Отдел закупок  ·  портал supply-department.online',
        ['size' => 11, 'color' => 'E8C8AE'], ['spaceAfter' => 220]);
    $section->addTextBreak(1, ['size' => 6]);

    $head = [
        ['Кто передаёт дела', trim(($doc['author_name'] ?? '') . ($doc['author_role'] ? ', ' . $doc['author_role'] : ''))],
        ['Период отсутствия', 'с ' . hoDate($doc['date_from']) . ' по ' . hoDate($doc['date_to'])],
    ];
    if (!empty($doc['return_date'])) $head[] = ['Первый рабочий день', hoDate($doc['return_date'])];
    if (trim((string)$doc['emergency_note']) !== '') $head[] = ['Экстренная связь', $doc['emergency_note']];
    $head[] = ['Документ составлен', hoDate($doc['created_at'])];
    hoTable($section, $word, ['Параметр', 'Значение'], $head, [5.6, 13.5]);

    $num = 0;

    // ── 1. Кто что принимает ──
    if ($people) {
        hoH1($section, ++$num, 'Кто что принимает');
        $rows = [];
        foreach ($people as $p) {
            $rows[] = [$p['name'], $p['zone'], $p['scope'], $p['contact']];
        }
        hoTable($section, $word, ['Ответственный', 'Что принимает', 'Поставщики и темы', 'Контакт'],
            $rows, [4.4, 4.4, 6.5, 3.8]);
    }

    // ── 2. План приходов ──
    $plan = [];
    foreach ($suppliers as $s) {
        foreach (($s['orders'] ?? []) as $o) {
            $plan[] = [
                'date'     => $o['date'],
                'supplier' => $s['supplier_name'],
                'entity'   => $o['legal_entity'],
                'person'   => $s['person_id'] ? ($peopleById[(int)$s['person_id']] ?? '') : '',
                'note'     => $o['note'] ?? '',
            ];
        }
    }
    usort($plan, fn($a, $b) => [$a['date'], $a['supplier']] <=> [$b['date'], $b['supplier']]);
    if ($plan) {
        hoH1($section, ++$num, 'План приходов на период отсутствия');
        $rows = [];
        foreach ($plan as $p) {
            $rows[] = [hoDate($p['date']), $p['supplier'], $p['entity'], $p['person'], $p['note']];
        }
        hoTable($section, $word, ['Дата', 'Поставщик', 'Юрлицо', 'Кто принимает', 'Примечание'],
            $rows, [2.5, 4.6, 3.8, 3.8, 4.4]);
    }

    // ── 3. Регулярные дела ──
    $weekly = array_values(array_filter($items['weekly'] ?? [], fn($i) => trim((string)$i['c2']) !== ''));
    if ($weekly) {
        hoH1($section, ++$num, 'Регулярные дела по дням недели');
        $rows = [];
        foreach ($weekly as $w) $rows[] = [$w['c1'], $w['c2'], $w['c3'], $w['c4']];
        hoTable($section, $word, ['День', 'Что нужно сделать', 'До какого времени', 'Кто отвечает'],
            $rows, [2.9, 8.8, 3.6, 3.8]);
    }

    // ── 4. Поставщики ──
    if ($suppliers) {
        $section->addPageBreak();
        hoH1($section, ++$num, 'Поставщики — что передаётся по каждому');
        $supIndex = 0;
        foreach ($suppliers as $s) {
            $owner = $s['person_id'] ? ($peopleById[(int)$s['person_id']] ?? '—') : '—';
            if ($supIndex > 0) hoDivider($section, $word);
            hoSupplierBand($section, $word, ++$supIndex, $s['supplier_name'], $owner);

            if (trim((string)$s['contacts']) !== '') {
                hoBlockLabel($section, $word, 'Контакты поставщика');
                $section->addText(htmlspecialchars($s['contacts']), ['size' => 10, 'color' => HO_INK],
                    ['spaceAfter' => 60]);
            }

            // Заявки — отдельной таблицей на каждую дату прихода: в одной
            // сплошной таблице даты и юрлица терялись среди позиций.
            $orders = $s['orders'] ?? [];
            if ($orders) {
                hoBlockLabel($section, $word, 'Отправленные заявки');
                foreach ($orders as $o) {
                    $head = hoDate($o['date']) . '  ·  ' . $o['legal_entity'];
                    $section->addText(htmlspecialchars($head),
                        ['bold' => true, 'size' => 10.5, 'color' => HO_BROWN],
                        ['spaceBefore' => 120, 'spaceAfter' => 40, 'keepNext' => true]);
                    if (empty($o['items'])) {
                        $section->addText('позиции не заполнены',
                            ['italic' => true, 'size' => 9.5, 'color' => HO_GREY], ['spaceAfter' => 80]);
                        continue;
                    }
                    $rows = [];
                    foreach ($o['items'] as $it) {
                        $rows[] = [$it['sku'], $it['name'], $it['qty']];
                    }
                    hoTable($section, $word, ['Артикул', 'Товар', 'Количество'], $rows, [2.7, 11.4, 5.0]);
                }
            }

            $cond = [];
            if (trim((string)$s['correction_rule']) !== '') $cond[] = ['Корректировка заявки', $s['correction_rule']];
            if (trim((string)$s['docs_rule']) !== '')       $cond[] = ['Документы перед поставкой', $s['docs_rule']];
            if ($cond) {
                hoBlockLabel($section, $word, 'Условия и сроки');
                hoTable($section, $word, ['Параметр', 'Значение'], $cond, [5.1, 14.0]);
            }

            if (trim((string)$s['attention']) !== '') {
                hoBlockLabel($section, $word, 'На что обратить внимание');
                $section->addText(htmlspecialchars($s['attention']), ['size' => 10, 'color' => HO_INK],
                    ['spaceAfter' => 140]);
            }
        }
    }

    // ── Прочие разделы ──
    $blocks = [
        ['topic',    'Отдельные темы',                ['Тема', 'Порядок работы', 'Кто ведёт'],                       [4.8, 10.5, 3.8]],
        ['payment',  'Оплаты, документы, растаможка', ['Поставка / документ', 'Что сделать', 'Срок', 'Кто отвечает'], [4.8, 6.9, 2.8, 4.6]],
        ['control',  'На контроле — незакрытые вопросы', ['Вопрос', 'Состояние', 'Что должно произойти', 'Кто ведёт', 'Когда напомнить'], [4.0, 4.4, 4.6, 3.0, 3.1]],
        ['escalate', 'К кому идти с вопросами',       ['Вопрос', 'К кому', 'Контакт'],                                [6.7, 6.2, 6.2]],
        ['file',     'Вложения к документу',          ['Файл', 'Зачем нужен'],                                       [7.2, 11.9]],
    ];
    foreach ($blocks as [$kind, $title, $headers, $widths]) {
        $rows = [];
        foreach (($items[$kind] ?? []) as $it) {
            $vals = array_slice([$it['c1'], $it['c2'], $it['c3'], $it['c4'], $it['c5']], 0, count($headers));
            if (trim(implode('', $vals)) === '') continue;
            $rows[] = $vals;
        }
        if (!$rows) continue;
        hoH1($section, ++$num, $title);
        hoTable($section, $word, $headers, $rows, $widths);
    }

    // ── Подписи ──
    hoH1($section, null, 'Подписи');
    $section->addText('Документ прочитан, дела приняты:', ['size' => 10], ['spaceAfter' => 120]);
    $sign = [['Передал', $doc['author_name'], hoDate($doc['created_at']), '']];
    foreach ($people as $p) $sign[] = ['Принял', $p['name'], '', ''];
    hoTable($section, $word, ['Кто', 'ФИО', 'Дата', 'Подпись'], $sign, [4.8, 5.7, 3.2, 5.4]);

    $name = 'Передача дел ' . hoDate($doc['date_from']) . '.docx';
    $tmp = tempnam(sys_get_temp_dir(), 'ho_');
    $word->save($tmp, 'Word2007');
    return [$tmp, $name];
}
