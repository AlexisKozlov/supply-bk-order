/**
 * Построение ОДНОГО листа Excel-заявки поставщику в стиле «Планеты Ресторанов».
 *
 * Чистый модуль: не трогает DOM/fs/сеть. Библиотека xlsx-js-style передаётся
 * первым аргументом — так один и тот же код работает и в браузере
 * (`await import('xlsx-js-style')`), и в Node.
 *
 * buildSoOrderSheet(XLSX, opts) -> ws (готовый лист для book_append_sheet)
 *
 * opts:
 *   supplierName  — короткое имя поставщика (для заголовка листа)
 *   dateFmt       — дата доставки в формате ДД.ММ.ГГГГ
 *   products      — массив отображаемых товаров (после buildDisplayProducts):
 *                   { sku, product_name, is_grouped, source_skus,
 *                     qty_per_box?, boxes_per_pallet?, weight_netto?, weight_brutto? }
 *   restaurants   — [{ number, city?/region?, address?, order_status }]
 *   items         — массив позиций заявок: { restaurant_number, sku, quantity, admin_qty }
 *   isAutoSubmitted — (row) => bool; в браузере передаётся существующая, в Node — () => false
 *   options       — { dropEmptyRows = false, palletMetrics = [] }
 *                   palletMetrics — какие показатели паллет/веса показывать; массив из
 *                   'boxes' | 'pallets' | 'netto' | 'brutto'. Порядок = порядок столбцов
 *                   справа и строк снизу. Пустой массив = показатели не показываем вообще
 *                   (ни правых столбцов, ни нижних строк).
 */
export function buildSoOrderSheet(XLSX, {
  supplierName = 'Поставщик',
  dateFmt = '',
  products = [],
  restaurants = [],
  items = [],
  isAutoSubmitted = () => false,
  options = {},
} = {}) {
  const { dropEmptyRows = false, palletMetrics = [] } = options || {};

  const prods = products || [];
  const rests = restaurants || [];

  // ═══ Стили (определяем один раз) ═══
  const border = { top: { style: 'thin', color: { rgb: 'BDBDBD' } }, bottom: { style: 'thin', color: { rgb: 'BDBDBD' } }, left: { style: 'thin', color: { rgb: 'BDBDBD' } }, right: { style: 'thin', color: { rgb: 'BDBDBD' } } };
  const titleStyle = { font: { bold: true, sz: 14, name: 'Calibri', color: { rgb: '2E7D32' } }, alignment: { horizontal: 'left', vertical: 'center' } };
  const headerStyle = { font: { bold: true, color: { rgb: 'FFFFFF' }, sz: 11, name: 'Calibri' }, fill: { fgColor: { rgb: '2E7D32' } }, alignment: { horizontal: 'center', vertical: 'center', wrapText: true }, border };
  const headerLeftStyle = { ...headerStyle, alignment: { horizontal: 'left', vertical: 'center', wrapText: true } };
  const cityStyle = { font: { bold: true, sz: 12, name: 'Calibri', color: { rgb: 'FFFFFF' } }, fill: { fgColor: { rgb: '5D4037' } }, alignment: { horizontal: 'left', vertical: 'center' }, border };
  const restStyle = { font: { bold: true, sz: 11, name: 'Calibri' }, alignment: { horizontal: 'center', vertical: 'center' }, border };
  const addrStyle = { font: { sz: 10, color: { rgb: '666666' }, name: 'Calibri' }, alignment: { horizontal: 'left', vertical: 'center' }, border };
  const qtyStyle = { font: { sz: 11, name: 'Calibri' }, alignment: { horizontal: 'center', vertical: 'center' }, border };
  const qtyFilledStyle = { ...qtyStyle, font: { bold: true, sz: 11, name: 'Calibri' }, fill: { fgColor: { rgb: 'E8F5E9' } } };
  const adminQtyStyle = { ...qtyStyle, font: { bold: true, sz: 11, name: 'Calibri', color: { rgb: 'D62700' } }, fill: { fgColor: { rgb: 'FFEBEE' } } };
  const autoQtyStyle = { ...qtyStyle, font: { bold: true, sz: 11, name: 'Calibri', color: { rgb: 'B91C1C' } } };
  const missQtyStyle = { font: { bold: true, sz: 11, name: 'Calibri', color: { rgb: 'B71C1C' } }, fill: { fgColor: { rgb: 'FFCDD2' } }, alignment: { horizontal: 'center', vertical: 'center' }, border };
  const missRestStyle = { font: { bold: true, sz: 11, name: 'Calibri', color: { rgb: 'B71C1C' } }, fill: { fgColor: { rgb: 'FFCDD2' } }, alignment: { horizontal: 'center', vertical: 'center' }, border };
  const missAddrStyle = { font: { sz: 10, bold: true, color: { rgb: 'B71C1C' }, name: 'Calibri' }, fill: { fgColor: { rgb: 'FFCDD2' } }, alignment: { horizontal: 'left', vertical: 'center' }, border };
  const missNoteStyle = { font: { sz: 10, italic: true, bold: true, name: 'Calibri', color: { rgb: 'B71C1C' } }, fill: { fgColor: { rgb: 'FFCDD2' } }, alignment: { horizontal: 'left', vertical: 'center' }, border };
  const noteStyle = { font: { sz: 10, italic: true, name: 'Calibri', color: { rgb: '555555' } }, alignment: { horizontal: 'left', vertical: 'center' }, border };
  const evenRowBg = { fgColor: { rgb: 'F5F5F5' } };
  const subtotalStyle = { font: { bold: true, sz: 11, name: 'Calibri', color: { rgb: '5D4037' } }, fill: { fgColor: { rgb: 'EFEBE9' } }, alignment: { horizontal: 'center', vertical: 'center' }, border };
  const subtotalLeftStyle = { ...subtotalStyle, alignment: { horizontal: 'left', vertical: 'center' } };
  const grandBorder = { top: { style: 'medium', color: { rgb: '2E7D32' } }, bottom: { style: 'medium', color: { rgb: '2E7D32' } }, left: border.left, right: border.right };
  const grandTotalStyle = { font: { bold: true, sz: 12, name: 'Calibri', color: { rgb: '2E7D32' } }, fill: { fgColor: { rgb: 'C8E6C9' } }, alignment: { horizontal: 'center', vertical: 'center' }, border: grandBorder };
  const grandTotalLeftStyle = { ...grandTotalStyle, alignment: { horizontal: 'left', vertical: 'center' } };

  // ═══ Lookup + getQty/skipRest (поведение как в исходном exportExcel) ═══
  const lookup = {};
  for (const it of (items || [])) lookup[`${it.restaurant_number}_${it.sku}`] = it;
  const getQty = (r, p) => {
    const skus = p?.source_skus?.length ? p.source_skus : [p?.sku];
    let found = false;
    let qty = 0;
    let hasAdmin = false;
    for (const sku of skus) {
      const it = lookup[`${r.number}_${sku}`];
      if (!it) continue;
      found = true;
      const admin = (it.admin_qty !== null && it.admin_qty !== undefined) ? parseFloat(it.admin_qty) : NaN;
      const q = parseFloat(it.quantity);
      if (!isNaN(admin)) {
        qty += admin;
        hasAdmin = true;
      } else if (!isNaN(q)) {
        qty += q;
      }
    }
    return found ? { qty, isAdmin: hasAdmin } : null;
  };
  const isSubmittedRest = (r) => !!r.order_status && r.order_status !== 'draft';
  const skipRest = (r) => {
    if (!r.order_status || r.order_status === 'draft') return false;
    return prods.every(p => { const v = getQty(r, p); return !v || v.qty === 0; });
  };

  // ═══ Группировка по городам ═══
  const sortedRests = rests.slice().sort((a, b) => {
    const ca = (a.city || '').localeCompare(b.city || '');
    return ca !== 0 ? ca : (parseInt(a.number) || 0) - (parseInt(b.number) || 0);
  });

  // Опция dropEmptyRows: убираем неподавших и подавших «пустых» (все нули)
  const includeRest = (r) => {
    if (!dropEmptyRows) return true;
    if (!isSubmittedRest(r)) return false; // не подал — убрать
    return !skipRest(r);                   // подал, но всё по нулям — убрать
  };
  const shownRests = sortedRests.filter(includeRest);

  const cityGroups = {}; const cityOrder = [];
  for (const r of shownRests) {
    const c = r.city || r.region || 'Без города';
    if (!cityGroups[c]) { cityGroups[c] = []; cityOrder.push(c); }
    cityGroups[c].push(r);
  }

  // ═══ Показатели паллет/веса (настраиваемый набор) ═══
  const num = (val) => {
    if (val === null || val === undefined || val === '' || isNaN(val)) return NaN;
    return parseFloat(val);
  };
  // Округление до N знаков с удалением хвостовых нулей
  const round = (v, dec) => (v === null || v === undefined || isNaN(v)) ? '' : parseFloat(Number(v).toFixed(dec));

  const METRIC_LABELS = { boxes: 'Коробок', pallets: 'Паллет', netto: 'Вес нетто, кг', brutto: 'Вес брутто, кг' };
  const METRIC_DEC = { boxes: 2, pallets: 2, netto: 1, brutto: 1 };
  // Оставляем только известные показатели, сохраняя порядок из palletMetrics
  const metrics = (Array.isArray(palletMetrics) ? palletMetrics : []).filter(m => METRIC_LABELS[m]);
  const M = metrics.length;

  // Атрибуты справочника по каждому товару (граммы веса на ОДНУ коробку)
  const prodAttrs = prods.map(p => ({
    qpb: num(p.qty_per_box),
    bpp: num(p.boxes_per_pallet),
    wn: num(p.weight_netto),
    wb: num(p.weight_brutto),
  }));

  // Итоги показателей по массиву штук на товар (штуки → коробки → паллеты/вес).
  // Товары без атрибутов дают вклад 0. Всё линейно по штукам, поэтому сумму
  // по ресторанам можно считать как сумму штук, а показатель — один раз в конце.
  const computeMetricTotals = (piecesArr) => {
    const acc = { boxes: 0, pallets: 0, netto: 0, brutto: 0 };
    for (let pi = 0; pi < prods.length; pi++) {
      const a = prodAttrs[pi];
      const pieces = piecesArr[pi] || 0;
      if (isNaN(a.qpb) || a.qpb <= 0) continue;
      const boxes = pieces / a.qpb;
      acc.boxes += boxes;
      if (!isNaN(a.bpp) && a.bpp > 0) acc.pallets += boxes / a.bpp;
      if (!isNaN(a.wn) && a.wn > 0) acc.netto += boxes * a.wn / 1000;
      if (!isNaN(a.wb) && a.wb > 0) acc.brutto += boxes * a.wb / 1000;
    }
    return acc;
  };
  // Ячейки правых столбцов (в порядке metrics) с округлением по каждому показателю
  const metricCells = (piecesArr) => {
    const t = computeMetricTotals(piecesArr);
    return metrics.map(m => round(t[m], METRIC_DEC[m]));
  };

  // ═══ Заголовок таблицы ═══
  // В шапку товара идёт единица измерения: без неё непонятно, в чём стоят
  // цифры в столбце — в штуках, килограммах или литрах.
  // Отдельной строкой, а не через запятую: название часто само содержит
  // фасовку («Майонез …, 1 кг»), и приписка в конце читалась бы как её часть.
  const unitLabel = (p) => {
    const u = String(p.unit_of_measure || '').trim();
    return u ? `\nед.: ${u}` : '';
  };
  const header = ['№', 'Адрес'];
  for (const p of prods) {
    const title = p.is_grouped
      ? `${p.product_name}\nSKU ×${p.source_skus.length}`
      : (p.sku ? `${p.sku}\n${p.product_name}` : p.product_name);
    header.push(title + unitLabel(p));
  }
  for (const m of metrics) header.push(METRIC_LABELS[m]);
  header.push('Пометка');

  const aoa = [[`Заявка ${supplierName} на ${dateFmt}`], header];
  const rowMeta = [];
  const merges = [{ s: { r: 0, c: 0 }, e: { r: 0, c: header.length - 1 } }];

  for (const city of cityOrder) {
    const group = cityGroups[city];
    const cityRow = [city]; for (let i = 1; i < header.length; i++) cityRow.push('');
    aoa.push(cityRow);
    merges.push({ s: { r: aoa.length - 1, c: 0 }, e: { r: aoa.length - 1, c: header.length - 1 } });
    rowMeta.push({ type: 'city' });
    for (let i = 0; i < group.length; i++) {
      const r = group[i];
      const isSubmitted = isSubmittedRest(r);
      const row = [r.number, r.address || ''];
      const piecesArr = [];
      for (const p of prods) { if (!isSubmitted) { row.push(0); piecesArr.push(0); continue; } const v = getQty(r, p); const q = v ? v.qty : 0; row.push(q); piecesArr.push(q); }
      if (M) {
        // Неподавший — пусто (0 в товарах = «не подал», не путаем с реальным 0)
        if (!isSubmitted) { for (let k = 0; k < M; k++) row.push(''); }
        else { for (const cell of metricCells(piecesArr)) row.push(cell); }
      }
      let note = '';
      if (!isSubmitted) note = 'Не подал';
      else if (skipRest(r)) note = 'Не нужна';
      row.push(note);
      aoa.push(row);
      rowMeta.push({ type: 'data', row: r, isEven: i % 2 === 1, isSubmitted });
    }
    const subRow = [`Итого ${city}`, ''];
    const cityPieces = [];
    for (const p of prods) {
      let sum = 0, hasAny = false;
      for (const r of group) { if (!r.order_status || r.order_status === 'draft') continue; const v = getQty(r, p); if (v) { sum += v.qty; hasAny = true; } }
      subRow.push(hasAny ? sum : '');
      cityPieces.push(sum);
    }
    if (M) { for (const cell of metricCells(cityPieces)) subRow.push(cell); }
    subRow.push(''); aoa.push(subRow); rowMeta.push({ type: 'subtotal' });
  }

  // ═══ ИТОГО (по показанным ресторанам) ═══
  const totalPieces = prods.map(() => 0);
  const grandRow = ['ИТОГО', ''];
  for (let pi = 0; pi < prods.length; pi++) {
    const p = prods[pi];
    let sum = 0, hasAny = false;
    for (const r of shownRests) { if (!r.order_status || r.order_status === 'draft') continue; const v = getQty(r, p); if (v) { sum += v.qty; hasAny = true; } }
    totalPieces[pi] = sum;
    grandRow.push(hasAny ? sum : '');
  }
  if (M) { for (const cell of metricCells(totalPieces)) grandRow.push(cell); }
  grandRow.push(''); aoa.push(grandRow); rowMeta.push({ type: 'total' });

  // ═══ Нижняя сводка по товарам: только выбранные показатели ═══
  if (M) {
    // Значения по каждому товару (не сумма): показатель на totalPieces товара
    const perProd = { boxes: [], pallets: [], netto: [], brutto: [] };
    for (let pi = 0; pi < prods.length; pi++) {
      const a = prodAttrs[pi];
      const pieces = totalPieces[pi] || 0;
      const boxes = (!isNaN(a.qpb) && a.qpb > 0) ? pieces / a.qpb : null;
      perProd.boxes.push(boxes === null ? '' : round(boxes, 2));
      perProd.pallets.push((boxes !== null && !isNaN(a.bpp) && a.bpp > 0) ? round(boxes / a.bpp, 2) : '');
      perProd.netto.push((boxes !== null && !isNaN(a.wn) && a.wn > 0) ? round(boxes * a.wn / 1000, 1) : '');
      perProd.brutto.push((boxes !== null && !isNaN(a.wb) && a.wb > 0) ? round(boxes * a.wb / 1000, 1) : '');
    }
    const pushInfoRow = (label, values) => {
      const row = [label, ''];
      for (const v of values) row.push(v);
      for (let k = 0; k < M; k++) row.push(''); // правые столбцы показателей — пусто
      row.push(''); // Пометка
      aoa.push(row);
      rowMeta.push({ type: 'palletinfo' });
    };
    for (const m of metrics) pushInfoRow(METRIC_LABELS[m], perProd[m]);
  }

  // ═══ Лист + применение стилей ═══
  const ws = XLSX.utils.aoa_to_sheet(aoa);
  ws['!merges'] = merges;

  const titleCell = ws[XLSX.utils.encode_cell({ r: 0, c: 0 })];
  if (titleCell) titleCell.s = titleStyle;
  for (let c = 0; c < header.length; c++) {
    const cell = ws[XLSX.utils.encode_cell({ r: 1, c })];
    if (cell) cell.s = c <= 1 ? headerLeftStyle : headerStyle;
  }
  for (let mi = 0; mi < rowMeta.length; mi++) {
    const ri = mi + 2; const meta = rowMeta[mi];
    if (meta.type === 'city') {
      for (let c = 0; c < header.length; c++) { const cell = ws[XLSX.utils.encode_cell({ r: ri, c })]; if (cell) cell.s = cityStyle; }
    } else if (meta.type === 'data') {
      const numCell = ws[XLSX.utils.encode_cell({ r: ri, c: 0 })];
      if (numCell) numCell.s = meta.isSubmitted ? { ...restStyle, ...(meta.isEven ? { fill: evenRowBg } : {}) } : missRestStyle;
      const aCell = ws[XLSX.utils.encode_cell({ r: ri, c: 1 })];
      if (aCell) aCell.s = meta.isSubmitted ? { ...addrStyle, ...(meta.isEven ? { fill: evenRowBg } : {}) } : missAddrStyle;
      for (let pi = 0; pi < prods.length; pi++) {
        const c = 2 + pi; const cell = ws[XLSX.utils.encode_cell({ r: ri, c })]; if (!cell) continue;
        if (!meta.isSubmitted) { cell.s = missQtyStyle; continue; }
        const v = getQty(meta.row, prods[pi]);
        if (v && isAutoSubmitted(meta.row)) cell.s = autoQtyStyle;
        else if (v && v.isAdmin) cell.s = adminQtyStyle;
        else if (v) cell.s = { ...qtyFilledStyle, ...(meta.isEven ? { fill: { fgColor: { rgb: 'E8F5E9' } } } : {}) };
        else cell.s = { ...qtyStyle, ...(meta.isEven ? { fill: evenRowBg } : {}) };
      }
      for (let k = 0; k < M; k++) {
        const c = 2 + prods.length + k; const cell = ws[XLSX.utils.encode_cell({ r: ri, c })]; if (!cell) continue;
        if (!meta.isSubmitted) { cell.s = missQtyStyle; continue; }
        cell.s = { ...qtyStyle, ...(meta.isEven ? { fill: evenRowBg } : {}) };
      }
      const nCell = ws[XLSX.utils.encode_cell({ r: ri, c: header.length - 1 })];
      if (nCell) nCell.s = meta.isSubmitted ? { ...noteStyle, ...(meta.isEven ? { fill: evenRowBg } : {}) } : missNoteStyle;
    } else if (meta.type === 'subtotal') {
      for (let c = 0; c < header.length; c++) { const cell = ws[XLSX.utils.encode_cell({ r: ri, c })]; if (cell) cell.s = c === 0 ? subtotalLeftStyle : subtotalStyle; }
    } else if (meta.type === 'total') {
      for (let c = 0; c < header.length; c++) { const cell = ws[XLSX.utils.encode_cell({ r: ri, c })]; if (cell) cell.s = c === 0 ? grandTotalLeftStyle : grandTotalStyle; }
    } else if (meta.type === 'palletinfo') {
      for (let c = 0; c < header.length; c++) { const cell = ws[XLSX.utils.encode_cell({ r: ri, c })]; if (cell) cell.s = c === 0 ? subtotalLeftStyle : subtotalStyle; }
    }
  }

  // Ширина столбца товара — по самому длинному слову в его шапке, чтобы
  // название не резалось посреди слова. Потолок 26 знаков: шире столбцы
  // делают лист неудобным, остаток дочитывается переносом строки.
  const PROD_MIN_W = 14, PROD_MAX_W = 26;
  const prodWidths = [];
  for (let i = 0; i < prods.length; i++) {
    const text = String(header[2 + i] || '');
    const longestWord = text.split(/\s|\n/).reduce((m, w) => Math.max(m, w.length), 0);
    prodWidths.push(Math.min(PROD_MAX_W, Math.max(PROD_MIN_W, longestWord + 2)));
  }
  ws['!cols'] = [
    { wch: 8 }, { wch: 32 },
    ...prodWidths.map(w => ({ wch: w })),
    ...Array(M).fill({ wch: 11 }), { wch: 20 },
  ];

  // Высота шапки — под реальное число строк переноса при выбранной ширине.
  // Раньше стояли фиксированные 42px: длинные названия переносились, но
  // нижние строки обрезались, и товар в отчёте было не опознать.
  let headerLines = 2;
  for (let i = 0; i < prods.length; i++) {
    const w = prodWidths[i];
    const lines = String(header[2 + i] || '').split('\n')
      .reduce((sum, part) => sum + Math.max(1, Math.ceil(part.length / w)), 0);
    headerLines = Math.max(headerLines, lines);
  }
  ws['!rows'] = [{ hpx: 28 }, { hpx: Math.min(140, 16 + headerLines * 14) }];

  return ws;
}
