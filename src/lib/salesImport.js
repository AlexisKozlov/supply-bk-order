/**
 * Парсинг файлов реализации ресторанов (Qlik / 1С УТ).
 * Используется и на странице «Реализация ресторанов», и на странице «Импорт данных».
 */

function normalizeAnalogGroup(s) {
  let t = String(s ?? '').trim();
  if (!t) return t;
  const lastOpen = t.lastIndexOf('«');
  const lastClose = t.lastIndexOf('»');
  if (lastOpen > lastClose) t += '»';
  const dq = (t.match(/"/g) || []).length;
  if (dq % 2 === 1) t += '"';
  return t;
}

/**
 * Парсинг файла реализации из Excel.
 * @param {File} file — файл .xlsx/.xls
 * @param {Object} [skuToGroup] — карта артикул→группа аналогов (если передана, маппит по артикулу)
 * @returns {Promise<{items: Array, skuMapped: number}>} — массив записей + кол-во замапленных по артикулу
 */
export async function parseSalesFile(file, skuToGroup) {
  const mod = await import('xlsx-js-style');
  const XLSX = mod.default || mod;
  const buf = await file.arrayBuffer();
  const wb = XLSX.read(buf, { type: 'array' });
  const ws = wb.Sheets[wb.SheetNames[0]];
  const rows = XLSX.utils.sheet_to_json(ws, { header: 1, defval: null, raw: false });
  const result = parseQlik(rows, skuToGroup) || parse1cUT(rows) || { items: [], skuMapped: 0 };
  return result;
}

// ═══ Qlik: колонки ГруппаАналогов, Дата, Расход/Продажи, Количество мест хранения ═══
function parseQlik(rows, skuToGroup) {
  let colGroup = -1, colDate = -1, colRc = -1, headerIdx = -1;
  let colSales = -1, colConsumption = -1, colSku = -1;
  for (let i = 0; i < Math.min(rows.length, 20); i++) {
    const row = rows[i];
    if (!row) continue;
    colGroup = -1; colDate = -1; colRc = -1; colSales = -1; colConsumption = -1; colSku = -1;
    for (let j = 0; j < row.length; j++) {
      const h = String(row[j] || '').trim().toLowerCase();
      if (h.includes('группааналогов') || h.includes('группа аналогов')) colGroup = j;
      else if (h === 'дата') colDate = j;
      else if (h.includes('продажи')) colSales = j;
      else if (h.includes('расход')) colConsumption = j;
      else if (h.includes('мест хранения') || h.includes('количество мест')) colRc = j;
      else if (h === 'артикул' || h === 'sku' || h === 'код' || h === 'код номенклатуры') colSku = j;
    }
    // Группа аналогов не обязательна, если есть артикул и карта маппинга
    const hasGroup = colGroup >= 0 || (colSku >= 0 && skuToGroup);
    if (hasGroup && colDate >= 0 && (colSales >= 0 || colConsumption >= 0)) { headerIdx = i; break; }
  }
  if (headerIdx < 0) return null;

  const items = [];
  let skuMapped = 0;
  for (let i = headerIdx + 1; i < rows.length; i++) {
    const row = rows[i];
    if (!row) continue;
    let group = colGroup >= 0 ? normalizeAnalogGroup(row[colGroup]) : '';
    // Если есть артикул и карта маппинга — подставляем группу аналогов по артикулу
    if (colSku >= 0 && skuToGroup) {
      const sku = String(row[colSku] || '').trim();
      if (sku) {
        // Пробуем точное совпадение, потом с/без префикса BK_
        const match = skuToGroup[sku]
          || skuToGroup['BK_' + sku]
          || (sku.startsWith('BK_') && skuToGroup[sku.slice(3)])
          || null;
        if (match) {
          group = normalizeAnalogGroup(match);
          skuMapped++;
        }
      }
    }
    if (!group || group === 'н.опр') continue;
    const qtyCol = colSales >= 0 ? colSales : colConsumption;
    const qty = Math.round(parseNum(row[qtyCol]) * 100) / 100;
    if (!qty) continue;
    const saleDate = excelDateToStr(row[colDate]);
    if (!saleDate) continue;
    const rc = colRc >= 0 ? (parseNum(row[colRc]) | 0) : 0;
    items.push({ sale_date: saleDate, analog_group: group, quantity: qty, restaurant_count: rc });
  }
  if (!items.length) return null;
  // Агрегация: суммируем quantity по (date + group), берём max restaurant_count
  const agg = new Map();
  for (const it of items) {
    const key = it.sale_date + '||' + it.analog_group;
    if (agg.has(key)) {
      const ex = agg.get(key);
      ex.quantity = Math.round((ex.quantity + it.quantity) * 100) / 100;
      if (it.restaurant_count > ex.restaurant_count) ex.restaurant_count = it.restaurant_count;
    } else {
      agg.set(key, { ...it });
    }
  }
  return { items: Array.from(agg.values()), skuMapped };
}

// ═══ 1С УТ: сложная вложенная структура ═══
function parse1cUT(rows) {
  const header = find1cUTHeader(rows);
  if (!header) return null;

  const items = [];
  let cur = null;
  for (let i = header.rowIdx + 1; i < rows.length; i++) {
    const row = rows[i];
    if (!row) continue;
    const v = row[0];
    if (v == null) continue;
    const s = String(v).trim();
    if (!s || s === 'Регистратор.Дата' || s === 'Группа аналогов' || s === 'Параметры:') continue;
    if (s === 'Итого') break;
    const m = s.match(/^(\d{2})\.(\d{2})\.(\d{4})/);
    if (m) {
      if (!cur) continue;
      const qty = Math.round(parseNum(row[header.qtyCol]) * 100) / 100;
      if (!qty) continue;
      items.push({
        sale_date: `${m[3]}-${m[2]}-${m[1]}`,
        analog_group: cur,
        quantity: qty,
        restaurant_count: header.countCol >= 0 ? parseNum(row[header.countCol]) | 0 : 0,
      });
    } else if (row[header.qtyCol] != null && parseNum(row[header.qtyCol]) > 0) {
      cur = normalizeAnalogGroup(s.replace(/^[\s"]+|[\s"]+$/g, ''));
      if (!cur || cur === 'н.опр') cur = null;
    }
  }
  if (!items.length) return null;
  return { items: aggregateSalesItems(items), skuMapped: 0 };
}

function find1cUTHeader(rows) {
  for (let i = 0; i < Math.min(rows.length, 30); i++) {
    const row = rows[i];
    if (!row) continue;
    const first = String(row[0] || '').trim().toLowerCase();
    let qtyCol = -1;
    let countCol = -1;
    for (let c = 0; c < row.length; c++) {
      const h = String(row[c] || '').trim().toLowerCase();
      if (h.includes('количество тмц') || h.includes('кол-во тмц') || h === 'количество') qtyCol = c;
      if (h.includes('количество записей') || h.includes('кол-во записей')) countCol = c;
    }
    if ((first.includes('группа аналогов') || first.includes('группааналогов')) && qtyCol >= 0) {
      return { rowIdx: i, qtyCol, countCol };
    }
  }
  return null;
}

function aggregateSalesItems(items) {
  const agg = new Map();
  for (const it of items) {
    const key = it.sale_date + '||' + it.analog_group;
    if (agg.has(key)) {
      const ex = agg.get(key);
      ex.quantity = Math.round((ex.quantity + it.quantity) * 100) / 100;
      if (it.restaurant_count > ex.restaurant_count) ex.restaurant_count = it.restaurant_count;
    } else {
      agg.set(key, { ...it });
    }
  }
  return Array.from(agg.values());
}

function parseNum(v) {
  if (typeof v === 'number') return v;
  let s = String(v || '0').trim().replace(/\s+/g, '');
  if (s.includes(',') && s.includes('.')) {
    s = s.replace(/,/g, '');
  } else if (/^-?\d+,\d{3}$/.test(s)) {
    s = s.replace(/,/g, '');
  } else {
    s = s.replace(',', '.');
  }
  return parseFloat(s) || 0;
}

function excelDateToStr(v) {
  if (v instanceof Date) {
    const dd = String(v.getUTCDate()).padStart(2, '0');
    const mm = String(v.getUTCMonth() + 1).padStart(2, '0');
    return `${v.getUTCFullYear()}-${mm}-${dd}`;
  }
  if (typeof v === 'number') {
    const d = new Date((v - 25569) * 86400000);
    const dd = String(d.getUTCDate()).padStart(2, '0');
    const mm = String(d.getUTCMonth() + 1).padStart(2, '0');
    return `${d.getUTCFullYear()}-${mm}-${dd}`;
  }
  const s = String(v).trim();
  const m = s.match(/^(\d{2})\.(\d{2})\.(\d{4})/);
  if (m) return `${m[3]}-${m[2]}-${m[1]}`;
  const m2 = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (m2) return s.slice(0, 10);
  return null;
}
