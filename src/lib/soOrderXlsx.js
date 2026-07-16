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
 *   options       — { dropEmptyRows = false, showPalletWeight = false }
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
  const { dropEmptyRows = false, showPalletWeight = false } = options || {};

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

  // ═══ Заголовок таблицы ═══
  const header = ['№', 'Адрес'];
  for (const p of prods) header.push(p.is_grouped ? `${p.product_name}\nSKU ×${p.source_skus.length}` : (p.sku ? `${p.sku}\n${p.product_name}` : p.product_name));
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
      for (const p of prods) { if (!isSubmitted) { row.push(0); continue; } const v = getQty(r, p); row.push(v ? v.qty : 0); }
      let note = '';
      if (!isSubmitted) note = 'Не подал';
      else if (skipRest(r)) note = 'Не нужна';
      row.push(note);
      aoa.push(row);
      rowMeta.push({ type: 'data', row: r, isEven: i % 2 === 1, isSubmitted });
    }
    const subRow = [`Итого ${city}`, ''];
    for (const p of prods) {
      let sum = 0, hasAny = false;
      for (const r of group) { if (!r.order_status || r.order_status === 'draft') continue; const v = getQty(r, p); if (v) { sum += v.qty; hasAny = true; } }
      subRow.push(hasAny ? sum : '');
    }
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
  grandRow.push(''); aoa.push(grandRow); rowMeta.push({ type: 'total' });

  // ═══ Опция showPalletWeight: 4 строки коробки/паллеты/вес ═══
  const num = (val) => {
    if (val === null || val === undefined || val === '' || isNaN(val)) return NaN;
    return parseFloat(val);
  };
  // Округление до N знаков с удалением хвостовых нулей
  const round = (v, dec) => (v === null || v === undefined || isNaN(v)) ? '' : parseFloat(Number(v).toFixed(dec));

  if (showPalletWeight) {
    const boxesArr = [], palletArr = [], nettoArr = [], bruttoArr = [];
    for (let pi = 0; pi < prods.length; pi++) {
      const p = prods[pi];
      const qpb = num(p.qty_per_box);
      const bpp = num(p.boxes_per_pallet);
      const wn = num(p.weight_netto);
      const wb = num(p.weight_brutto);
      const pieces = totalPieces[pi] || 0;
      const boxes = (!isNaN(qpb) && qpb > 0) ? pieces / qpb : null;
      boxesArr.push(boxes === null ? '' : round(boxes, 2));
      palletArr.push((boxes !== null && !isNaN(bpp) && bpp > 0) ? round(boxes / bpp, 2) : '');
      nettoArr.push((boxes !== null && !isNaN(wn) && wn > 0) ? round(boxes * wn / 1000, 1) : '');
      bruttoArr.push((boxes !== null && !isNaN(wb) && wb > 0) ? round(boxes * wb / 1000, 1) : '');
    }
    const pushInfoRow = (label, values) => {
      const row = [label, ''];
      for (const v of values) row.push(v);
      row.push('');
      aoa.push(row);
      rowMeta.push({ type: 'palletinfo' });
    };
    pushInfoRow('Коробок', boxesArr);
    pushInfoRow('Паллет (доля)', palletArr);
    pushInfoRow('Вес нетто, кг', nettoArr);
    pushInfoRow('Вес брутто, кг', bruttoArr);
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

  ws['!cols'] = [{ wch: 8 }, { wch: 32 }, ...Array(prods.length).fill({ wch: 14 }), { wch: 20 }];
  ws['!rows'] = [{ hpx: 28 }, { hpx: 42 }];

  return ws;
}
