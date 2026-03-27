/**
 * Парсинг файлов сроков годности (Маллинг).
 * Используется и на странице «Сроки годности», и на странице «Импорт данных».
 */

import { CUSTOMER_MAP } from '@/lib/legalEntities.js';

// Маппинг складов на понятные названия
const WAREHOUSE_MAP = [
  { match: 'шабаны', name: 'Шабаны' },
  { match: 'прилесье 6', name: 'Сухой сток' },
  { match: 'прилесье 1 охлажд', name: 'Холод' },
  { match: 'прилесье 1 заморож', name: 'Мороз' },
  { match: 'прилесье 1', name: 'Холод' },
];

export function normalizeCustomer(raw) {
  if (!raw) return '';
  const lower = raw.trim().toLowerCase();
  for (const [key, val] of Object.entries(CUSTOMER_MAP)) {
    if (lower.includes(key)) return val;
  }
  return raw.trim();
}

export function normalizeWarehouse(raw) {
  if (!raw) return '';
  const lower = raw.trim().toLowerCase();
  for (const w of WAREHOUSE_MAP) {
    if (lower.includes(w.match)) return w.name;
  }
  return raw.trim();
}

/**
 * Парсинг файла сроков годности из Excel.
 * @param {File} file — файл .xlsx/.xls
 * @returns {Promise<Array>} — массив позиций (без uploaded_at/uploaded_by)
 */
export async function parseStockMalling(file) {
  const XLSX = await import('xlsx-js-style');
  const buffer = await file.arrayBuffer();
  const wb = XLSX.read(buffer, { type: 'array', cellDates: true });
  const ws = wb.Sheets[wb.SheetNames[0]];

  let maxRow = 0, maxCol = 0;
  for (const key of Object.keys(ws).filter(k => !k.startsWith('!'))) {
    const cell = XLSX.utils.decode_cell(key);
    if (cell.r > maxRow) maxRow = cell.r;
    if (cell.c > maxCol) maxCol = cell.c;
  }

  const rows = [];
  for (let r = 0; r <= maxRow; r++) {
    const row = [];
    for (let c = 0; c <= maxCol; c++) {
      const cell = ws[XLSX.utils.encode_cell({ r, c })];
      row.push(cell ? (cell.v !== undefined ? cell.v : '') : '');
    }
    rows.push(row);
  }

  const keywords = ['заказчик', 'склад', 'наименование', 'годен', 'дата производства', 'блокировк', 'остаток', 'статус'];
  let headerIdx = -1;
  for (let i = 0; i < Math.min(rows.length, 15); i++) {
    const cells = rows[i].map(c => String(c ?? '').toLowerCase().trim());
    if (cells.filter(cell => cell && keywords.some(kw => cell.includes(kw))).length >= 3) { headerIdx = i; break; }
  }
  if (headerIdx < 0) return [];

  const headers = rows[headerIdx].map(h => String(h ?? '').toLowerCase().trim());
  const findCol = (kws) => { for (const kw of kws) { const i = headers.findIndex(h => h.includes(kw)); if (i >= 0) return i; } return -1; };

  const colMap = {
    customer: findCol(['заказчик', 'наименования заказчика', 'покупатель', 'клиент']),
    warehouse: findCol(['название склада', 'склад хранения', 'склад']),
    product_name: findCol(['наименование товара', 'наименование номенклатуры', 'наименование продукт', 'номенклатура']),
    production_date: findCol(['дата производства', 'дата выработки', 'дата изготовления']),
    expiry_date: findCol(['годен до', 'срок годности', 'дата окончания']),
    block_reason: findCol(['причина блокировк', 'блокировк']),
    expiry_status: findCol(['статус годности', 'статус годн']),
    quantity: findCol(['остатки', 'остаток', 'количество', 'кол-во', 'кол.']),
  };

  if (colMap.product_name < 0) return [];

  const parseDate = (val) => {
    if (!val) return null;
    if (val instanceof Date) return isNaN(val.getTime()) ? null : val.toISOString().slice(0, 10);
    const s = String(val).trim();
    const m = s.match(/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/);
    if (m) return `${m[3]}-${m[2].padStart(2,'0')}-${m[1].padStart(2,'0')}`;
    const m2 = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (m2) return `${m2[1]}-${m2[2]}-${m2[3]}`;
    return null;
  };

  const parseNum = (val) => {
    if (val == null || val === '') return 0;
    const n = parseFloat(String(val).replace(/\s/g, '').replace(',', '.'));
    return isNaN(n) ? 0 : Math.round(n * 100) / 100;
  };

  const items = [];
  for (let i = headerIdx + 1; i < rows.length; i++) {
    const row = rows[i];
    const name = colMap.product_name >= 0 ? String(row[colMap.product_name] ?? '').trim() : '';
    if (!name) continue;
    const rawCustomer = colMap.customer >= 0 ? String(row[colMap.customer] ?? '').trim() : '';
    items.push({
      customer: normalizeCustomer(rawCustomer),
      warehouse: normalizeWarehouse(colMap.warehouse >= 0 ? String(row[colMap.warehouse] ?? '').trim() : ''),
      product_name: name,
      production_date: colMap.production_date >= 0 ? parseDate(row[colMap.production_date]) : null,
      expiry_date: colMap.expiry_date >= 0 ? parseDate(row[colMap.expiry_date]) : null,
      block_reason: colMap.block_reason >= 0 ? String(row[colMap.block_reason] ?? '').trim() || null : null,
      expiry_status: colMap.expiry_status >= 0 ? String(row[colMap.expiry_status] ?? '').trim() : '',
      quantity: colMap.quantity >= 0 ? parseNum(row[colMap.quantity]) : 0,
    });
  }

  // Агрегация дубликатов
  const agg = new Map();
  for (const item of items) {
    const key = [item.product_name, item.customer, item.warehouse, item.production_date, item.expiry_date].join('||');
    if (agg.has(key)) agg.get(key).quantity += item.quantity;
    else agg.set(key, { ...item });
  }
  return Array.from(agg.values());
}
