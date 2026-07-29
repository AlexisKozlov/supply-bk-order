<?php
/**
 * Загрузочные листы для ПРЦ (тесто, Пицца Стар).
 *
 * ПРЦ собирает заказ стопками. Стопка — это четверть паллеты, в неё входит
 * РОВНО 22 лотка, не больше. В лотке — сколько штук теста, столько же, сколько
 * стоит кратность в карточке товара (25 см — 10 шт, 30 см — 7, 35 см — 6).
 *
 * Правило раскладки:
 *   1) Каждый размер сначала набирает СВОИ полные стопки: 46 лотков теста
 *      25 см → 2 стопки по 22, в остатке 2 лотка.
 *   2) Остатки всех размеров идут в «сборные» стопки — там лежат разные
 *      размеры. Набралось 22 — стопка закрыта, дальше следующая.
 *
 * По одному листу на стопку: ПРЦ печатает их и клеит на стопки.
 */

/** Сколько лотков помещается в одну стопку. Величина физическая, не настройка. */
const SO_LS_TRAYS_PER_STACK = 22;

/**
 * Цвет стикера по размеру теста. ПРЦ клеит их, чтобы на складе и в машине
 * различать размеры не читая текст.
 */
function soLsStickerColor(string $sku, string $productName): ?string {
    $hay = $sku . ' ' . $productName;
    if (preg_match('/\b25\s*см/ui', $hay)) return 'жёлтый стикер';
    if (preg_match('/\b30\s*см/ui', $hay)) return 'зелёный стикер';
    if (preg_match('/\b35\s*см/ui', $hay)) return 'красный стикер';
    return null;
}

/**
 * Формируются ли для поставщика загрузочные листы.
 *
 * Пока это только ПРЦ (тесто, Пицца Стар) — раскладка по стопкам нужна
 * именно ему. Проверка вынесена в одну функцию: когда понадобится включать
 * её другим поставщикам, здесь появится галочка из настроек.
 */
function soLsSupplierEnabled(PDO $pdo, string $supplierId): bool {
    $st = $pdo->prepare("SELECT short_name FROM suppliers WHERE id = ?");
    $st->execute([$supplierId]);
    $name = (string)$st->fetchColumn();
    return $name !== '' && mb_stripos($name, 'ПРЦ') !== false;
}

/**
 * Раскладывает заявку одного ресторана по стопкам.
 *
 * @param array $items позиции: ['sku','product_name','qty' (штуки),'per_tray' (штук в лотке)]
 * @return array{
 *   items: array,        позиции с посчитанными лотками
 *   stacks: array,       стопки: ['mixed'=>bool, 'lines'=>[['sku','name','trays']], 'total'=>int]
 *   total_trays: int
 * }
 */
function soLsBuildStacks(array $items): array {
    $prepared = [];
    foreach ($items as $it) {
        $perTray = (int)($it['per_tray'] ?? 0);
        $qty     = (float)($it['qty'] ?? 0);
        if ($perTray <= 0 || $qty <= 0) continue;
        // Неполный лоток всё равно едет лотком — округляем вверх.
        $trays = (int)ceil($qty / $perTray);
        $prepared[] = [
            'sku'       => (string)$it['sku'],
            'name'      => (string)$it['product_name'],
            'per_tray'  => $perTray,
            'qty'       => $qty,
            'trays'     => $trays,
            'sticker'   => soLsStickerColor((string)$it['sku'], (string)$it['product_name']),
        ];
    }
    // Порядок размеров — как в справочнике (Т025, Т030, Т035), чтобы листы
    // печатались предсказуемо и совпадали с накладной.
    usort($prepared, fn($a, $b) => strcmp($a['sku'], $b['sku']));

    $stacks = [];
    $leftovers = [];
    foreach ($prepared as $p) {
        $full = intdiv($p['trays'], SO_LS_TRAYS_PER_STACK);
        for ($i = 0; $i < $full; $i++) {
            $stacks[] = [
                'mixed' => false,
                'lines' => [['sku' => $p['sku'], 'name' => $p['name'], 'per_tray' => $p['per_tray'], 'trays' => SO_LS_TRAYS_PER_STACK]],
                'total' => SO_LS_TRAYS_PER_STACK,
            ];
        }
        $rest = $p['trays'] % SO_LS_TRAYS_PER_STACK;
        if ($rest > 0) {
            $leftovers[] = ['sku' => $p['sku'], 'name' => $p['name'], 'per_tray' => $p['per_tray'], 'trays' => $rest];
        }
    }

    // Остатки складываем подряд: набралось 22 — стопка закрыта, размер при
    // необходимости делится между двумя сборными стопками.
    $current = ['mixed' => true, 'lines' => [], 'total' => 0];
    foreach ($leftovers as $lo) {
        $left = $lo['trays'];
        while ($left > 0) {
            $free = SO_LS_TRAYS_PER_STACK - $current['total'];
            $take = min($free, $left);
            $current['lines'][] = ['sku' => $lo['sku'], 'name' => $lo['name'], 'per_tray' => $lo['per_tray'], 'trays' => $take];
            $current['total'] += $take;
            $left -= $take;
            if ($current['total'] >= SO_LS_TRAYS_PER_STACK) {
                $stacks[] = $current;
                $current = ['mixed' => true, 'lines' => [], 'total' => 0];
            }
        }
    }
    if ($current['total'] > 0) $stacks[] = $current;

    // Сборная стопка из одного размера сборной не является — помечаем как
    // обычную, чтобы на листе не было лишнего слова «сборная».
    foreach ($stacks as &$st) {
        if ($st['mixed'] && count($st['lines']) === 1) $st['mixed'] = false;
    }
    unset($st);

    return [
        'items'       => $prepared,
        'stacks'      => $stacks,
        'total_trays' => array_sum(array_column($prepared, 'trays')),
    ];
}

/**
 * Собирает данные загрузочных листов за день: по ресторану — заявка, стопки,
 * реквизиты для шапки.
 *
 * @return array<int, array> список ресторанов с раскладкой
 */
function soLsCollectDay(PDO $pdo, string $supplierId, string $deliveryDate): array {
    // Штук в лотке берём из кратности карточки: у теста там как раз 10/7/6.
    $stmt = $pdo->prepare("
        SELECT o.restaurant_number,
               r.dodo_is_number, r.region, r.city, r.address,
               oi.sku, oi.product_name,
               COALESCE(oi.admin_qty, oi.quantity) AS qty,
               p.multiplicity AS per_tray
        FROM so_orders o
        JOIN so_order_items oi ON oi.order_id = o.id
        LEFT JOIN restaurants r ON r.number = o.restaurant_number
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE o.supplier_id = ? AND o.delivery_date = ? AND o.status != 'draft'
          AND COALESCE(oi.admin_qty, oi.quantity) > 0
        ORDER BY r.region, CAST(r.dodo_is_number AS UNSIGNED), o.restaurant_number, oi.sku");
    $stmt->execute([$supplierId, $deliveryDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byRest = [];
    foreach ($rows as $row) {
        $num = (string)$row['restaurant_number'];
        if (!isset($byRest[$num])) {
            $city = trim((string)($row['city'] ?? ''));
            $addr = trim((string)($row['address'] ?? ''));
            $byRest[$num] = [
                'restaurant_number' => $num,
                'dodo_is_number'    => trim((string)($row['dodo_is_number'] ?? '')),
                'region'            => trim((string)($row['region'] ?? '')),
                'address'           => $city !== '' && $addr !== '' ? "$city, $addr" : ($city ?: $addr),
                'items'             => [],
            ];
        }
        $byRest[$num]['items'][] = [
            'sku'          => $row['sku'],
            'product_name' => $row['product_name'],
            'qty'          => (float)$row['qty'],
            'per_tray'     => (int)$row['per_tray'],
        ];
    }

    $out = [];
    foreach ($byRest as $num => $rest) {
        $built = soLsBuildStacks($rest['items']);
        if (!$built['stacks']) continue;
        $rest['items']       = $built['items'];
        $rest['stacks']      = $built['stacks'];
        $rest['total_trays'] = $built['total_trays'];
        // Подпись ресторана: «Минск 26» — регион и номер в ДОДО ИС. Если номер
        // не заполнен, оставляем один регион: портальный номер (PS12) для ПРЦ
        // ничего не значит, они работают номерами ДОДО ИС.
        $rest['title'] = trim($rest['region'] . ' ' . $rest['dodo_is_number']);
        if ($rest['title'] === '') $rest['title'] = formatRestaurantNumber((int)$num);
        $out[] = $rest;
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────
// Генерация Excel
// ─────────────────────────────────────────────────────────────

/** Строк на одну печатную страницу (одна стопка = одна страница A4). */
/** Заливка и цвет для «Итого/шапка». */
const SO_LS_BROWN = '502314';
const SO_LS_SAND  = 'FFF3E0';

function soLsFmtDate(string $ymd): string {
    $d = DateTime::createFromFormat('Y-m-d', $ymd);
    return $d ? $d->format('d.m.Y') : $ymd;
}

/** Excel не принимает в имени листа : \ / ? * [ ] и больше 31 знака. */
function soLsSafeSheetName(string $name, array $used): string {
    $n = preg_replace('~[:\\\\/?*\[\]]~u', '-', $name);
    $n = trim(mb_substr($n, 0, 31));
    if ($n === '') $n = 'Лист';
    $base = $n; $i = 2;
    while (in_array(mb_strtolower($n), array_map('mb_strtolower', $used), true)) {
        $suffix = ' (' . $i . ')';
        $n = mb_substr($base, 0, 31 - mb_strlen($suffix)) . $suffix;
        $i++;
    }
    return $n;
}

/**
 * Рисует на листе одну страницу-стопку начиная со строки $top.
 * Возвращает номер строки после страницы.
 */
/** Цвет стикера одним словом для колонки: ЖЁЛТЫЙ / ЗЕЛЁНЫЙ / КРАСНЫЙ. */
function soLsStickerWord(?string $sticker): string {
    if (!$sticker) return '—';
    return mb_strtoupper(trim(str_replace('стикер', '', $sticker)));
}

/** Короткое наименование для листа: «Тесто для пиццы 25 см». */
function soLsShortName(string $name, string $sku): string {
    return preg_match('/(\d{2})\s*см/u', $name, $m) ? 'Тесто для пиццы ' . $m[1] . ' см' : ($name ?: $sku);
}

function soLsRenderStackPage($sheet, array $rest, array $stack, int $index, int $total, string $dateFmt, int $top): int {
    $B = \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN;
    $BM = \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM;
    $CENTER = \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER;
    $VCENTER = \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER;

    // Печать чёрно-белая, поэтому акценты — только размером, жирностью,
    // рамками и серой заливкой. Цвет не несёт смысла.
    $band = function (string $range, string $fill = null, string $border = null) use ($sheet, $VCENTER) {
        $st = $sheet->getStyle($range);
        $st->getAlignment()->setVertical($VCENTER);
        if ($fill) {
            $st->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
               ->getStartColor()->setRGB($fill);
        }
        if ($border) $st->getBorders()->getOutline()->setBorderStyle($border)->getColor()->setRGB('000000');
    };
    $text = function (string $cell, $val, int $size = 11, bool $bold = false, bool $center = false, string $color = '000000') use ($sheet, $CENTER, $VCENTER) {
        $sheet->setCellValue($cell, $val);
        $st = $sheet->getStyle($cell);
        $st->getFont()->setSize($size)->setBold($bold)->getColor()->setRGB($color);
        $st->getAlignment()->setVertical($VCENTER);
        if ($center) $st->getAlignment()->setHorizontal($CENTER);
    };

    $r = $top;

    // ── Ссылка назад: просто стрелка, без слова ──
    $sheet->setCellValue('D' . $r, '←');
    $sheet->getCell('D' . $r)->getHyperlink()->setUrl("sheet://'Навигация'!A1");
    $sheet->getStyle('D' . $r)->getFont()->setSize(14)->setBold(true)->getColor()->setRGB('000000');
    $sheet->getStyle('D' . $r)->getAlignment()->setHorizontal($CENTER);
    $r++;

    // ── Шапка: кому везём. Номер точки — крупно, его ищут глазами ──
    // Наверху адрес — по нему водитель и грузчик находят точку. Номер в
    // ДОДО ИС строкой ниже и мельче: он нужен для сверки, а не для поиска.
    $sheet->mergeCells("A{$r}:D{$r}");
    $text("A{$r}", $rest['address'], 28, true, true);
    $sheet->getRowDimension($r)->setRowHeight(44);
    $band("A{$r}:D{$r}", 'E8E8E8', $BM);
    $r++;

    $sheet->mergeCells("A{$r}:D{$r}");
    $text("A{$r}", $rest['title'], 17, false, true);
    $sheet->getRowDimension($r)->setRowHeight(26);
    $band("A{$r}:D{$r}", 'E8E8E8');
    $r++;

    $sheet->mergeCells("A{$r}:D{$r}");
    $text("A{$r}", 'Отгрузка ' . $dateFmt, 17, true, true);
    $sheet->getRowDimension($r)->setRowHeight(28);
    $band("A{$r}:D{$r}", 'E8E8E8', $BM);
    $r += 2;

    // ── Главный блок: что кладём в эту стопку. Читается с расстояния ──
    $stackTop = $r;
    $sheet->mergeCells("A{$r}:D{$r}");
    $text("A{$r}", ($stack['mixed'] ? 'СБОРНАЯ СТОПКА' : 'СТОПКА') . '  ' . $index . ' / ' . $total, 22, true, true);
    $sheet->getRowDimension($r)->setRowHeight(36);
    $band("A{$r}:D{$r}", '000000');
    $sheet->getStyle("A{$r}:D{$r}")->getFont()->getColor()->setRGB('FFFFFF');
    $r++;

    // Шапка колонок: в сборной стопке позиций несколько, и без подписей
    // непонятно, где количество, а где кодировка. Держим одинаково на всех
    // листах, чтобы вид не «прыгал».
    $sheet->mergeCells("A{$r}:B{$r}");
    $text("A{$r}", 'Наименование', 13, true, true, '444444');
    $text("C{$r}", 'Количество', 13, true, true, '444444');
    $text("D{$r}", 'Стикер', 13, true, true, '444444');
    $sheet->getRowDimension($r)->setRowHeight(21);
    $band("A{$r}:D{$r}", 'F2F2F2');
    $sheet->getStyle("A{$r}:D{$r}")->getBorders()->getBottom()->setBorderStyle($B)->getColor()->setRGB('000000');
    $r++;

    foreach ($stack['lines'] as $line) {
        // Полное название из карточки — чтобы на складе не путать позиции.
        $sheet->mergeCells("A{$r}:B{$r}");
        $text("A{$r}", $line['name'], 19, true, true);
        // «22 лотка» помещается крупно, «10 лотков» — длиннее, ему нужен
        // размер поменьше, иначе текст обрезается по краю колонки.
        $qtyText = $line['trays'] . ' ' . soLsTrayWord($line['trays']);
        $text("C{$r}", $qtyText, mb_strlen($qtyText) > 8 ? 30 : 36, true, true);
        // Печать чёрно-белая — кодировку пишем словом, а не цветом.
        $text("D{$r}", soLsStickerWord(soLsStickerColor($line['sku'], $line['name'])), 20, true, true);
        $sheet->getRowDimension($r)->setRowHeight(96);
        $sheet->getStyle("A{$r}:B{$r}")->getAlignment()->setWrapText(true);
        $band("A{$r}:D{$r}", null, $B);
        $r++;
    }

    if ($stack['mixed']) {
        $sheet->mergeCells("A{$r}:D{$r}");
        $text("A{$r}", 'ИТОГО В СТОПКЕ: ' . $stack['total'] . ' ' . soLsTrayWord($stack['total']), 20, true, true);
        $sheet->getRowDimension($r)->setRowHeight(32);
        $band("A{$r}:D{$r}", 'E8E8E8');
        $r++;
    }
    $sheet->getStyle("A{$stackTop}:D" . ($r - 1))->getBorders()->getOutline()
        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK)->getColor()->setRGB('000000');
    $r += 2;

    // ── Справка мелким: весь заказ и цветовая кодировка ──
    $text("A{$r}", 'ВСЯ ЗАЯВКА', 13, true, false, '444444');
    $r++;
    foreach ($rest['items'] as $it) {
        // Штуки («плюшки») на листе не показываем — ПРЦ считает лотками.
        $sheet->mergeCells("A{$r}:B{$r}");
        $text("A{$r}", soLsShortName($it['name'], $it['sku']), 16, true);
        $sheet->mergeCells("C{$r}:D{$r}");
        $text("C{$r}", $it['trays'] . ' ' . soLsTrayWord($it['trays'])
            . ($it['sticker'] ? ' · ' . $it['sticker'] : ''), 16);
        $sheet->getRowDimension($r)->setRowHeight(23);
        $sheet->getStyle("A{$r}:D{$r}")->getBorders()->getBottom()->setBorderStyle($B)->getColor()->setRGB('BBBBBB');
        $r++;
    }

    // ── Подпись листа ──
    // Раньше страница была ровно 32 строки, а подпись стояла
    // в последней. После укрупнения шрифтов такая страница перестала влезать
    // в A4, поэтому подпись идёт сразу за содержимым, а разрыв ставится по
    // фактическому концу страницы.
    $sheet->mergeCells("A{$r}:D{$r}");
    $text("A{$r}", 'Лист ' . $index . ' из ' . $total, 16, true, true);
    $sheet->getStyle("A{$r}:D{$r}")->getBorders()->getTop()->setBorderStyle($B)->getColor()->setRGB('000000');

    return $r + 1;
}

function soLsTrayWord(int $n): string {
    $n10 = $n % 10; $n100 = $n % 100;
    if ($n10 === 1 && $n100 !== 11) return 'лоток';
    if ($n10 >= 2 && $n10 <= 4 && ($n100 < 12 || $n100 > 14)) return 'лотка';
    return 'лотков';
}

/**
 * Строит книгу Excel с загрузочными листами за день.
 * Первый лист — навигация со ссылками на рестораны, дальше по листу на
 * ресторан; внутри листа — по печатной странице на каждую стопку.
 *
 * @return array{status:string, xlsx:?string, filename:string, restaurants:int, stacks:int}
 */
function soLsBuildDayXlsx(PDO $pdo, string $supplierId, string $deliveryDate): array {
    // PhpSpreadsheet в API не автозагружен — подключаем по месту, как в кегах.
    require_once __DIR__ . '/../../vendor/autoload.php';
    $out = ['status' => 'ok', 'xlsx' => null, 'filename' => '', 'restaurants' => 0, 'stacks' => 0];
    $day = soLsCollectDay($pdo, $supplierId, $deliveryDate);
    if (!$day) { $out['status'] = 'empty'; return $out; }

    $dateFmt = soLsFmtDate($deliveryDate);
    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

    // ── Лист «Навигация» ──
    $nav = $ss->getActiveSheet();
    $nav->setTitle('Навигация');
    $nav->setCellValue('A1', 'Загрузочные листы — ' . $dateFmt);
    $nav->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB(SO_LS_BROWN);
    $nav->mergeCells('A1:D1');
    $nav->setCellValue('A3', 'Ресторан');
    $nav->setCellValue('B3', 'Адрес');
    $nav->setCellValue('C3', 'Лотков');
    $nav->setCellValue('D3', 'Листов');
    $nav->getStyle('A3:D3')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $nav->getStyle('A3:D3')->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setRGB(SO_LS_BROWN);
    $nav->getColumnDimension('A')->setWidth(26);
    $nav->getColumnDimension('B')->setWidth(52);
    $nav->getColumnDimension('C')->setWidth(10);
    $nav->getColumnDimension('D')->setWidth(10);
    $nav->freezePane('A4');
    $nav->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
    $nav->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_PORTRAIT);

    $used = ['Навигация'];
    $navRow = 4;
    $totalStacks = 0;

    foreach ($day as $rest) {
        // Имя листа: «Минск 26 (04.08.2026)» — как просили: регион, номер в
        // ДОДО ИС и дата отгрузки в скобках.
        $sheetName = soLsSafeSheetName($rest['title'] . ' (' . $dateFmt . ')', $used);
        $used[] = $sheetName;

        $sheet = $ss->createSheet();
        $sheet->setTitle($sheetName);
        // Четыре равные колонки: макет строится на объединённых ячейках,
        // крупные надписи должны занимать всю ширину страницы A4.
        // Ширины подобраны под содержимое: A+B — название с переносом,
        // C — крупное количество («10 лотков» шире, чем «22 лотка»),
        // D — слово-стикер.
        $colWidths = ['A' => 23, 'B' => 23, 'C' => 34, 'D' => 20];
        foreach ($colWidths as $col => $w) $sheet->getColumnDimension($col)->setWidth($w);
        $sheet->setShowGridlines(false);

        $total = count($rest['stacks']);
        $totalStacks += $total;
        $top = 1;
        foreach ($rest['stacks'] as $i => $stack) {
            $next = soLsRenderStackPage($sheet, $rest, $stack, $i + 1, $total, $dateFmt, $top);
            // Разрыв страницы после каждой стопки, кроме последней.
            if ($i < $total - 1) {
                $sheet->setBreak('A' . ($next - 1), \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::BREAK_ROW);
            }
            $top = $next;
        }

        $ps = $sheet->getPageSetup();
        $ps->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_PORTRAIT);
        $ps->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
        $ps->setFitToWidth(1);
        $ps->setFitToHeight(0);
        // Блок уже ширины A4, поэтому без этого он прижимался к левому краю
        // и справа оставалось больше поля. Центрируем и делаем поля равными.
        $ps->setHorizontalCentered(true);
        // Листы вешают на верхний край лотка, и верх загибается — то, что
        // напечатано вверху, не читается. Поэтому содержимое ставим по
        // середине листа: страница теперь заканчивается сразу за подписью,
        // и центрирование срабатывает.
        $ps->setVerticalCentered(true);
        $sheet->getPageMargins()->setTop(0.4)->setBottom(0.4)->setLeft(0.35)->setRight(0.35);

        // Строка навигации
        $nav->setCellValue('A' . $navRow, $rest['title']);
        $nav->getCell('A' . $navRow)->getHyperlink()->setUrl("sheet://'" . str_replace("'", "''", $sheetName) . "'!A1");
        $nav->getStyle('A' . $navRow)->getFont()->setUnderline(true)->getColor()->setRGB('1155CC');
        $nav->setCellValue('B' . $navRow, $rest['address']);
        $nav->setCellValue('C' . $navRow, $rest['total_trays']);
        $nav->setCellValue('D' . $navRow, $total);
        $navRow++;
    }

    $nav->setCellValue('A' . $navRow, 'ИТОГО');
    $nav->setCellValue('C' . $navRow, array_sum(array_column($day, 'total_trays')));
    $nav->setCellValue('D' . $navRow, $totalStacks);
    $nav->getStyle('A' . $navRow . ':D' . $navRow)->getFont()->setBold(true);
    $ss->setActiveSheetIndex(0);

    $tmp = tempnam(sys_get_temp_dir(), 'so_ls_') . '.xlsx';
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save($tmp);
    $bin = file_get_contents($tmp);
    @unlink($tmp);
    $ss->disconnectWorksheets();

    $out['xlsx']        = $bin;
    $out['filename']    = 'Загрузочный лист — ' . $dateFmt . '.xlsx';
    $out['restaurants'] = count($day);
    $out['stacks']      = $totalStacks;
    return $out;
}
