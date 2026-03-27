/**
 * Парсинг файлов реализации ресторанов (Qlik / 1С УТ).
 * Используется и на странице «Реализация ресторанов», и на странице «Импорт данных».
 */

/**
 * Парсинг файла реализации из Excel.
 * @param {File} file — файл .xlsx/.xls
 * @returns {Promise<Array>} — массив записей { sale_date, analog_group, quantity, restaurant_count }
 */
export async function parseSalesFile(file) {
  const mod = await import('xlsx-js-style');
  const XLSX = mod.default || mod;
  const buf = await file.arrayBuffer();
  const wb = XLSX.read(buf, { type: 'array' });
  const ws = wb.Sheets[wb.SheetNames[0]];
  const rows = XLSX.utils.sheet_to_json(ws, { header: 1, defval: null, raw: false });
  return parseQlik(rows) || parse1cUT(rows) || [];
}

// ═══ Qlik: колонки ГруппаАналогов, Дата, Расход/Продажи, Количество мест хранения ═══
function parseQlik(rows) {
  let colGroup = -1, colDate = -1, colRc = -1, headerIdx = -1;
  let colSales = -1, colConsumption = -1;
  for (let i = 0; i < Math.min(rows.length, 20); i++) {
    const row = rows[i];
    if (!row) continue;
    colGroup = -1; colDate = -1; colRc = -1; colSales = -1; colConsumption = -1;
    for (let j = 0; j < row.length; j++) {
      const h = String(row[j] || '').trim().toLowerCase();
      if (h.includes('группааналогов') || h.includes('группа аналогов')) colGroup = j;
      else if (h === 'дата') colDate = j;
      else if (h.includes('продажи')) colSales = j;
      else if (h.includes('расход')) colConsumption = j;
      else if (h.includes('мест хранения') || h.includes('количество мест')) colRc = j;
    }
    if (colGroup >= 0 && colDate >= 0 && (colSales >= 0 || colConsumption >= 0)) { headerIdx = i; break; }
  }
  if (headerIdx < 0) return null;

  const items = [];
  for (let i = headerIdx + 1; i < rows.length; i++) {
    const row = rows[i];
    if (!row) continue;
    const group = String(row[colGroup] || '').trim();
    if (!group || group === 'н.опр') continue;
    const qtyCol = colSales >= 0 ? colSales : colConsumption;
    const qty = Math.round(parseNum(row[qtyCol]) * 100) / 100;
    if (!qty) continue;
    const saleDate = excelDateToStr(row[colDate]);
    if (!saleDate) continue;
    const rc = colRc >= 0 ? (parseNum(row[colRc]) | 0) : 0;
    items.push({ sale_date: saleDate, analog_group: group, quantity: qty, restaurant_count: rc });
  }
  return items.length ? items : null;
}

// ═══ 1С УТ: сложная вложенная структура ═══
function parse1cUT(rows) {
  const items = [];
  let cur = null, skip = true;
  for (const row of rows) {
    const v = row[0];
    if (v == null) continue;
    const s = String(v).trim();
    if (!s || s === 'Регистратор.Дата' || s === 'Группа аналогов' || s === 'Параметры:') continue;
    if (s === 'Итого') break;
    const m = s.match(/^(\d{2})\.(\d{2})\.(\d{4})/);
    if (m) {
      if (cur && !skip) items.push({ sale_date: `${m[3]}-${m[2]}-${m[1]}`, analog_group: cur, quantity: Math.round((parseFloat(row[4])||0)*100)/100, restaurant_count: parseInt(row[5])||0 });
    } else if (row[4] != null && !isNaN(parseFloat(row[4]))) {
      if (skip) { skip = false; cur = null; continue; }
      cur = s.replace(/^[\s"]+|[\s"]+$/g, '');
    }
  }
  return items.length ? items : null;
}

function parseNum(v) {
  if (typeof v === 'number') return v;
  return parseFloat(String(v || '0').replace(/,/g, '')) || 0;
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
