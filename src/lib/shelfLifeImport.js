/**
 * Парсинг файлов сроков годности (Маллинг).
 * Используется и на странице «Сроки годности», и на странице «Импорт данных».
 */

import { CUSTOMER_MAP } from '@/lib/legalEntities.js';
import { toLocalDateStr } from '@/lib/utils.js';

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

function getSheetRows(XLSX, ws) {
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
  return rows;
}

function parseDate(val) {
  if (!val) return null;
  if (val instanceof Date) return isNaN(val.getTime()) ? null : toLocalDateStr(val);
  const s = String(val).trim();
  const m = s.match(/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/);
  if (m) return `${m[3]}-${m[2].padStart(2,'0')}-${m[1].padStart(2,'0')}`;
  const m2 = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (m2) return `${m2[1]}-${m2[2]}-${m2[3]}`;
  return null;
}

function parseNum(val) {
  if (val == null || val === '') return 0;
  const n = parseFloat(String(val).replace(/\s/g, '').replace(',', '.'));
  return isNaN(n) ? 0 : Math.round(n * 100) / 100;
}

function findCol(headers, kws) {
  for (const kw of kws) {
    const i = headers.findIndex(h => h === kw);
    if (i >= 0) return i;
  }
  for (const kw of kws) {
    const i = headers.findIndex(h => h && h.includes(kw));
    if (i >= 0) return i;
  }
  return -1;
}

function warehouseFromStockSheet(sheetName) {
  const lower = String(sheetName || '').toLowerCase();
  if (lower.includes('п6')) return 'Сухой сток';
  if (lower.includes('п1')) return 'Холод/Мороз';
  return sheetName || '';
}

export function extractStockReportDateFromName(fileName) {
  const fullDateMatch = String(fileName || '').match(/(\d{2})[.\-_](\d{2})[.\-_](\d{2,4})/);
  if (fullDateMatch) {
    const y = fullDateMatch[3].length === 2 ? '20' + fullDateMatch[3] : fullDateMatch[3];
    return `${y}-${fullDateMatch[2]}-${fullDateMatch[1]}`;
  }
  const shortDateMatch = String(fileName || '').match(/(\d{2})[.\-_](\d{2})(?![.\-_\d])/);
  if (shortDateMatch) {
    return `${new Date().getFullYear()}-${shortDateMatch[2]}-${shortDateMatch[1]}`;
  }
  return '';
}

function normalizeStorageCategory(category) {
  const lower = String(category || '').toLowerCase();
  if (lower.includes('мороз') || lower.includes('заморож') || lower.includes('frozen')) return 'Мороз';
  if (lower.includes('холод') || lower.includes('охлажд') || lower.includes('cold')) return 'Холод';
  if (lower.includes('сух') || lower.includes('dry')) return 'Сухой сток';
  return '';
}

function storageCategoryToType(category) {
  const normalized = normalizeStorageCategory(category);
  if (normalized === 'Мороз') return 'frozen';
  if (normalized === 'Холод') return 'cold';
  if (normalized === 'Сухой сток') return 'dry';
  return null;
}

function extractSkuFromStockProduct(value) {
  const s = String(value || '').trim();
  const byDash = s.match(/^\s*\S+\s+-\s+([A-Za-zА-Яа-яЁё0-9_./-]+)/);
  if (byDash) return byDash[1].trim();
  const firstToken = s.match(/^\s*([A-Za-zА-Яа-яЁё0-9_./-]+)/);
  return firstToken ? firstToken[1].trim() : '';
}

function resolveP1Warehouse(productName, options = {}) {
  const sku = extractSkuFromStockProduct(productName);
  const key = sku || String(productName || '').trim();
  const manual = options.manualStorageCategories || {};
  const productCategories = options.productCategories || {};
  const category = normalizeStorageCategory(manual[key] || manual[sku] || productCategories[sku]);
  if (category === 'Холод' || category === 'Мороз') return { warehouse: category, sku, key, needsChoice: false };
  return { warehouse: 'Холод/Мороз', sku, key, needsChoice: true };
}

function parseModernStockSheet(rows, sheetName, options = {}) {
  let headerIdx = -1;
  let colMap = null;

  for (let i = 0; i < Math.min(rows.length, 15); i++) {
    const headers = rows[i].map(h => String(h ?? '').toLowerCase().trim());
    const owner = findCol(headers, ['владелец товара', 'владелец']);
    const cell = findCol(headers, ['ячейка']);
    const product = findCol(headers, ['товар']);
    const production = findCol(headers, ['дата производства', 'дата выработки', 'дата изготовления']);
    const expiry = findCol(headers, ['годен до', 'срок годности', 'дата окончания']);
    const quantity = findCol(headers, ['итог', 'остатки', 'остаток', 'количество', 'кол-во', 'кол.']);

    if (owner >= 0 && cell >= 0 && product >= 0 && expiry >= 0 && quantity >= 0) {
      headerIdx = i;
      colMap = { owner, cell, product, production, expiry, quantity };
      break;
    }
  }

  if (headerIdx < 0) return null;

  const sheetWarehouse = warehouseFromStockSheet(sheetName);
  const isMixedP1 = String(sheetName || '').toLowerCase().includes('п1');
  const items = [];
  for (let i = headerIdx + 1; i < rows.length; i++) {
    const row = rows[i];
    const name = String(row[colMap.product] ?? '').trim();
    if (!name) continue;
    const customer = normalizeCustomer(String(row[colMap.owner] ?? '').trim());
    const storage = isMixedP1
      ? resolveP1Warehouse(name, options)
      : { warehouse: sheetWarehouse, sku: extractSkuFromStockProduct(name), key: '', needsChoice: false };
    items.push({
      customer,
      warehouse: storage.warehouse,
      product_name: name,
      production_date: colMap.production >= 0 ? parseDate(row[colMap.production]) : null,
      expiry_date: parseDate(row[colMap.expiry]),
      block_reason: null,
      expiry_status: 'Годен',
      quantity: parseNum(row[colMap.quantity]),
      _storage_sku: storage.sku,
      _storage_key: storage.key,
      _needs_storage_choice: storage.needsChoice,
    });
  }
  return items;
}

/**
 * Парсинг файла сроков годности из Excel.
 * @param {File} file — файл .xlsx/.xls
 * @returns {Promise<Array>} — массив позиций (без uploaded_at/uploaded_by)
 */
export async function parseStockMalling(file, options = {}) {
  const XLSX = await import('xlsx-js-style');
  const buffer = await file.arrayBuffer();
  const wb = XLSX.read(buffer, { type: 'array', cellDates: true });

  const items = [];
  let usedModernFormat = false;

  for (const sheetName of wb.SheetNames) {
    const ws = wb.Sheets[sheetName];
    const rows = getSheetRows(XLSX, ws);
    const modernItems = parseModernStockSheet(rows, sheetName, options);
    if (modernItems) {
      usedModernFormat = true;
      items.push(...modernItems);
    }
  }

  if (usedModernFormat) {
    return aggregateStockItems(items);
  }

  const ws = wb.Sheets[wb.SheetNames[0]];
  const rows = getSheetRows(XLSX, ws);

  const keywords = ['заказчик', 'склад', 'наименование', 'годен', 'дата производства', 'блокировк', 'остаток', 'статус'];
  let headerIdx = -1;
  for (let i = 0; i < Math.min(rows.length, 15); i++) {
    const cells = rows[i].map(c => String(c ?? '').toLowerCase().trim());
    if (cells.filter(cell => cell && keywords.some(kw => cell.includes(kw))).length >= 3) { headerIdx = i; break; }
  }
  if (headerIdx < 0) return [];

  const headers = rows[headerIdx].map(h => String(h ?? '').toLowerCase().trim());

  const colMap = {
    customer: findCol(headers, ['заказчик', 'наименования заказчика', 'покупатель', 'клиент']),
    warehouse: findCol(headers, ['название склада', 'склад хранения', 'склад']),
    product_name: findCol(headers, ['наименование товара', 'наименование номенклатуры', 'наименование продукт', 'номенклатура']),
    production_date: findCol(headers, ['дата производства', 'дата выработки', 'дата изготовления']),
    expiry_date: findCol(headers, ['годен до', 'срок годности', 'дата окончания']),
    block_reason: findCol(headers, ['причина блокировк', 'блокировк']),
    expiry_status: findCol(headers, ['статус годности', 'статус годн']),
    quantity: findCol(headers, ['остатки', 'остаток', 'количество', 'кол-во', 'кол.']),
  };

  if (colMap.product_name < 0) return [];

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

  return aggregateStockItems(items);
}

function aggregateStockItems(items) {
  // Агрегация дубликатов
  const agg = new Map();
  for (const item of items) {
    const key = [item.product_name, item.customer, item.warehouse, item.production_date, item.expiry_date].join('||');
    if (agg.has(key)) agg.get(key).quantity += item.quantity;
    else agg.set(key, { ...item });
  }
  return Array.from(agg.values());
}

// Маппинг склада → тип стока для warehouse_cells
const STOCK_TYPE_MAP = [
  { match: 'шабаны', type: 'shabany' },
  { match: 'прилесье 6', type: 'dry' },
  { match: 'прилесье 1 охлажд', type: 'cold' },
  { match: 'прилесье 1 заморож', type: 'frozen' },
  { match: 'прилесье 1', type: 'cold' },
];

function warehouseToStockType(raw) {
  const lower = (raw || '').toLowerCase();
  for (const w of STOCK_TYPE_MAP) {
    if (lower.includes(w.match)) return w.type;
  }
  if (lower.includes('п6') || lower.includes('сух')) return 'dry';
  return null;
}

function parseCellStatsFromModernStock(rows, sheetName, counts, options = {}) {
  const stockType = warehouseToStockType(sheetName);
  const isMixedP1 = String(sheetName || '').toLowerCase().includes('п1');
  if (!stockType && !isMixedP1) return false;

  let headerIdx = -1;
  let colCell = -1, colCustomer = -1, colProduct = -1;
  for (let i = 0; i < Math.min(rows.length, 15); i++) {
    const cells = rows[i].map(c => String(c ?? '').toLowerCase().trim());
    const ci = findCol(cells, ['ячейка']);
    const ui = findCol(cells, ['владелец товара', 'владелец', 'заказчик']);
    const pi = findCol(cells, ['товар']);
    if (ci >= 0 && ui >= 0) {
      headerIdx = i; colCell = ci; colCustomer = ui; colProduct = pi;
      break;
    }
  }
  if (headerIdx < 0) return false;

  for (let i = headerIdx + 1; i < rows.length; i++) {
    const row = rows[i];
    const cellNum = String(row[colCell] || '').trim();
    if (!cellNum) continue;
    const entity = normalizeCustomer(String(row[colCustomer] || '').trim());
    if (!entity) continue;
    let rowStockType = stockType;
    if (!rowStockType && isMixedP1 && colProduct >= 0) {
      const storage = resolveP1Warehouse(String(row[colProduct] || '').trim(), options);
      rowStockType = storageCategoryToType(storage.warehouse);
    }
    if (!rowStockType) continue;
    const key = entity + '||' + rowStockType;
    if (!counts[key]) counts[key] = new Set();
    counts[key].add(cellNum);
  }

  return true;
}

/**
 * Извлечение статистики ячеек/паллет из файла остатков склада.
 * @param {File} file — тот же файл, что грузится в сроки годности
 * @returns {Promise<{cells: Array, reportDate: string|null}>}
 *   cells: [{legal_entity, stock_type, cell_count}]
 *   reportDate: дата из названия файла или null
 */
export async function parseCellStats(file, options = {}) {
  // Дата из названия файла, fallback — сегодня
  let reportDate = options.reportDate || extractStockReportDateFromName(file.name);
  if (!reportDate) {
    const d = new Date();
    reportDate = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
  }

  const XLSX = await import('xlsx-js-style');
  const buffer = await file.arrayBuffer();
  const wb = XLSX.read(buffer, { type: 'array' });

  const counts = {}; // key: "entity||type" -> Set of cell numbers
  let usedModernFormat = false;
  for (const sheetName of wb.SheetNames) {
    const ws = wb.Sheets[sheetName];
    const rows = getSheetRows(XLSX, ws);
    if (parseCellStatsFromModernStock(rows, sheetName, counts, options)) {
      usedModernFormat = true;
    }
  }

  if (usedModernFormat) {
    const result = Object.entries(counts).map(([key, set]) => {
      const [legal_entity, stock_type] = key.split('||');
      return { legal_entity, stock_type, cell_count: set.size };
    });
    return { cells: result, reportDate };
  }

  const ws = wb.Sheets[wb.SheetNames[0]];
  const rows = getSheetRows(XLSX, ws);

  // Ищем заголовки
  let headerIdx = -1;
  let colCell = -1, colWarehouse = -1, colCustomer = -1;
  for (let i = 0; i < Math.min(rows.length, 15); i++) {
    const cells = rows[i].map(c => String(c ?? '').toLowerCase().trim());
    const ci = cells.findIndex(h => h.includes('ячейк'));
    const wi = cells.findIndex(h => h.includes('склад'));
    const ui = cells.findIndex(h => h.includes('заказчик'));
    if (ci >= 0 && wi >= 0) {
      headerIdx = i; colCell = ci; colWarehouse = wi; colCustomer = ui;
      break;
    }
  }
  if (headerIdx < 0) return { cells: [], reportDate };

  // Считаем уникальные ячейки по юрлицу + тип стока
  for (let i = headerIdx + 1; i < rows.length; i++) {
    const row = rows[i];
    const cellNum = String(row[colCell] || '').trim();
    if (!cellNum) continue;
    const rawWarehouse = String(row[colWarehouse] || '').trim();
    const stockType = warehouseToStockType(rawWarehouse);
    if (!stockType) continue;
    const rawCustomer = colCustomer >= 0 ? String(row[colCustomer] || '').trim() : '';
    const entity = normalizeCustomer(rawCustomer);
    if (!entity) continue;
    const key = entity + '||' + stockType;
    if (!counts[key]) counts[key] = new Set();
    counts[key].add(cellNum);
  }

  const result = Object.entries(counts).map(([key, set]) => {
    const [legal_entity, stock_type] = key.split('||');
    return { legal_entity, stock_type, cell_count: set.size };
  });

  return { cells: result, reportDate };
}
