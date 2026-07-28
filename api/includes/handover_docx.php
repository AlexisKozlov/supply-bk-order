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

    $cm = fn($v) => Converter::cmToTwip($v);

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

    $section = $word->addSection([
        'marginTop'    => Converter::cmToTwip(1.6),
        'marginBottom' => Converter::cmToTwip(1.6),
        'marginLeft'   => Converter::cmToTwip(1.9),
        'marginRight'  => Converter::cmToTwip(1.5),
    ]);

    // ── Шапка ──
    hoTableStyle($word, 'hoCover');
    $cover = $section->addTable('hoCover');
    $row = $cover->addRow();
    $cell = $row->addCell(Converter::cmToTwip(17), ['bgColor' => HO_BROWN]);
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
    hoTable($section, $word, ['Параметр', 'Значение'], $head, [5.5, 11.5]);

    $num = 0;

    // ── 1. Кто что принимает ──
    if ($people) {
        hoH1($section, ++$num, 'Кто что принимает');
        $rows = [];
        foreach ($people as $p) {
            $rows[] = [$p['name'], $p['zone'], $p['scope'], $p['contact']];
        }
        hoTable($section, $word, ['Ответственный', 'Что принимает', 'Поставщики и темы', 'Контакт'],
            $rows, [4.0, 4.0, 5.5, 3.5]);
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
            $rows, [2.4, 4.0, 3.4, 3.4, 3.8]);
    }

    // ── 3. Регулярные дела ──
    $weekly = array_values(array_filter($items['weekly'] ?? [], fn($i) => trim((string)$i['c2']) !== ''));
    if ($weekly) {
        hoH1($section, ++$num, 'Регулярные дела по дням недели');
        $rows = [];
        foreach ($weekly as $w) $rows[] = [$w['c1'], $w['c2'], $w['c3'], $w['c4']];
        hoTable($section, $word, ['День', 'Что нужно сделать', 'До какого времени', 'Кто отвечает'],
            $rows, [2.8, 7.5, 3.2, 3.5]);
    }

    // ── 4. Поставщики ──
    if ($suppliers) {
        $section->addPageBreak();
        hoH1($section, ++$num, 'Поставщики — что передаётся по каждому');
        foreach ($suppliers as $s) {
            hoH2($section, 'Поставщик: ' . $s['supplier_name']);
            $owner = $s['person_id'] ? ($peopleById[(int)$s['person_id']] ?? '—') : '—';
            hoTable($section, $word, ['Кто ведёт в отсутствие', 'Контакты поставщика'],
                [[$owner, $s['contacts']]], [5.0, 12.0]);

            $rows = [];
            foreach (($s['orders'] ?? []) as $o) {
                if (empty($o['items'])) {
                    $rows[] = [$o['legal_entity'], hoDate($o['date']), '— позиции не заполнены —', ''];
                    continue;
                }
                foreach ($o['items'] as $idx => $it) {
                    $rows[] = [
                        $idx === 0 ? $o['legal_entity'] : '',
                        $idx === 0 ? hoDate($o['date']) : '',
                        trim($it['sku'] . '  ' . $it['name']),
                        $it['qty'],
                    ];
                }
            }
            if ($rows) {
                $section->addText('Отправленные заявки', ['bold' => true, 'size' => 10],
                    ['spaceBefore' => 100, 'spaceAfter' => 40, 'keepNext' => true]);
                hoTable($section, $word, ['Юрлицо', 'Дата прихода', 'Товар', 'Количество'],
                    $rows, [3.0, 2.6, 8.4, 3.0]);
            }

            $cond = [];
            if (trim((string)$s['correction_rule']) !== '') $cond[] = ['Корректировка заявки', $s['correction_rule']];
            if (trim((string)$s['docs_rule']) !== '')       $cond[] = ['Документы перед поставкой', $s['docs_rule']];
            if ($cond) {
                $section->addText('Условия и сроки', ['bold' => true, 'size' => 10],
                    ['spaceBefore' => 100, 'spaceAfter' => 40, 'keepNext' => true]);
                hoTable($section, $word, ['Параметр', 'Значение'], $cond, [5.0, 12.0]);
            }

            if (trim((string)$s['attention']) !== '') {
                $section->addText('На что обратить внимание', ['bold' => true, 'size' => 10],
                    ['spaceBefore' => 100, 'spaceAfter' => 40, 'keepNext' => true]);
                $section->addText(htmlspecialchars($s['attention']), ['size' => 9.5, 'color' => HO_INK],
                    ['spaceAfter' => 140]);
            }
        }
    }

    // ── Прочие разделы ──
    $blocks = [
        ['topic',    'Отдельные темы',                ['Тема', 'Порядок работы', 'Кто ведёт'],                       [4.5, 9.0, 3.5]],
        ['payment',  'Оплаты, документы, растаможка', ['Поставка / документ', 'Что сделать', 'Срок', 'Кто отвечает'], [4.5, 6.0, 2.5, 4.0]],
        ['control',  'На контроле — незакрытые вопросы', ['Вопрос', 'Состояние', 'Что должно произойти', 'Кто ведёт', 'Когда напомнить'], [3.6, 4.0, 4.0, 2.7, 2.7]],
        ['escalate', 'К кому идти с вопросами',       ['Вопрос', 'К кому', 'Контакт'],                                [6.0, 5.5, 5.5]],
        ['file',     'Вложения к документу',          ['Файл', 'Зачем нужен'],                                       [7.0, 10.0]],
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
    hoTable($section, $word, ['Кто', 'ФИО', 'Дата', 'Подпись'], $sign, [4.5, 5.0, 3.0, 4.5]);

    $name = 'Передача дел ' . hoDate($doc['date_from']) . '.docx';
    $tmp = tempnam(sys_get_temp_dir(), 'ho_');
    $word->save($tmp, 'Word2007');
    return [$tmp, $name];
}
