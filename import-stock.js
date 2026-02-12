/**
 * Импорт остатков из Excel/CSV
 * import-stock.js
 * 
 * Маппинг по артикулу (SKU) → автозаполнение остатков
 * Работает и для основного заказа, и для планирования
 */

import { showToast } from './modals.js';

/**
 * Парсинг файла Excel/CSV → массив объектов
 * Возвращает: [{ sku, stock, transit, consumption }, ...]
 */
async function parseFile(file) {
  const ext = file.name.split('.').pop().toLowerCase();

  if (ext === 'csv' || ext === 'tsv') {
    return parseCSV(file, ext === 'tsv' ? '\t' : detectDelimiter);
  }

  // Excel
  const XLSX = await import('https://cdn.sheetjs.com/xlsx-0.20.1/package/xlsx.mjs');
  const buffer = await file.arrayBuffer();
  const wb = XLSX.read(buffer, { type: 'array' });
  const ws = wb.Sheets[wb.SheetNames[0]];
  const rows = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });

  return mapRows(rows);
}

/**
 * Парсинг CSV
 */
function parseCSV(file, delimiterOrDetect) {
  return new Promise((resolve) => {
    const reader = new FileReader();
    reader.onload = (e) => {
      const text = e.target.result;
      const delimiter = typeof delimiterOrDetect === 'string'
        ? delimiterOrDetect
        : detectDelimiter(text);
      const lines = text.split('\n').filter(l => l.trim());
      const rows = lines.map(l => l.split(delimiter).map(c => c.trim().replace(/^["']|["']$/g, '')));
      resolve(mapRows(rows));
    };
    reader.readAsText(file, 'utf-8');
  });
}

function detectDelimiter(text) {
  const firstLine = text.split('\n')[0];
  if (firstLine.includes('\t')) return '\t';
  if (firstLine.includes(';')) return ';';
  return ',';
}

/**
 * Маппинг строк таблицы → данные
 * Ищет колонки по названиям заголовков (нечёткий поиск)
 */
function mapRows(rows) {
  if (rows.length < 2) return [];

  // Ищем строку заголовков (первую строку с текстовыми ячейками)
  let headerIdx = 0;
  for (let i = 0; i < Math.min(rows.length, 10); i++) {
    const textCells = rows[i].filter(c => typeof c === 'string' && c.length > 1).length;
    if (textCells >= 2) {
      headerIdx = i;
      break;
    }
  }

  const headers = rows[headerIdx].map(h => String(h).toLowerCase().trim());

  // Поиск индексов колонок по ключевым словам
  const colMap = {
    sku: findCol(headers, ['артикул', 'арт', 'sku', 'код', 'article', 'code', 'номенклатура']),
    name: findCol(headers, ['наименование', 'название', 'товар', 'name', 'product', 'номенклатура']),
    stock: findCol(headers, ['остаток', 'остатки', 'склад', 'stock', 'кол-во', 'количество', 'свобод', 'доступ']),
    transit: findCol(headers, ['транзит', 'в пути', 'transit', 'ожидаем', 'поставщик', 'резерв']),
    consumption: findCol(headers, ['расход', 'потребление', 'consumption', 'продажи', 'реализация', 'списание'])
  };

  // Если не нашли sku — пробуем name как ключ
  if (colMap.sku === -1 && colMap.name === -1) {
    return [];
  }

  // Парсим данные
  const data = [];
  for (let i = headerIdx + 1; i < rows.length; i++) {
    const row = rows[i];
    if (!row || row.length < 2) continue;

    const sku = colMap.sku >= 0 ? String(row[colMap.sku] || '').trim() : '';
    const name = colMap.name >= 0 ? String(row[colMap.name] || '').trim() : '';
    if (!sku && !name) continue;

    const entry = { sku, name };

    if (colMap.stock >= 0) entry.stock = parseNum(row[colMap.stock]);
    if (colMap.transit >= 0) entry.transit = parseNum(row[colMap.transit]);
    if (colMap.consumption >= 0) entry.consumption = parseNum(row[colMap.consumption]);

    data.push(entry);
  }

  return data;
}

function findCol(headers, keywords) {
  for (const kw of keywords) {
    const idx = headers.findIndex(h => h.includes(kw));
    if (idx >= 0) return idx;
  }
  return -1;
}

function parseNum(val) {
  if (val === null || val === undefined || val === '') return 0;
  // Поддержка "1 234,56" и "1234.56"
  const s = String(val).replace(/\s/g, '').replace(',', '.');
  const n = parseFloat(s);
  return isNaN(n) ? 0 : Math.round(n);
}

/**
 * Показать диалог импорта — универсальный
 * target: 'order' | 'planning'
 * callback: (matchedData) => void
 */
export function showImportDialog(target, items, callback) {
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = '.xlsx,.xls,.csv,.tsv';

  input.addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;

    try {
      const data = await parseFile(file);
      if (!data.length) {
        showToast('Пустой файл', 'Не удалось распознать данные', 'error');
        return;
      }

      // Маппинг по SKU
      const result = matchData(items, data, target);

      if (result.matched === 0) {
        showToast('Нет совпадений', `Из ${data.length} строк файла — 0 совпадений по артикулу`, 'error');
        showImportPreview(data, items, target, callback);
        return;
      }

      callback(result.items);
      showToast('Импорт завершён', `${result.matched} из ${items.length} товаров обновлены`, 'success');
    } catch (err) {
      console.error('Import error:', err);
      showToast('Ошибка импорта', err.message || 'Не удалось прочитать файл', 'error');
    }
  });

  input.click();
}

/**
 * Маппинг данных файла → items заказа/планирования
 */
function matchData(items, fileData, target) {
  let matched = 0;

  // Создаём lookup по SKU (нормализованный)
  const lookup = new Map();
  fileData.forEach(d => {
    if (d.sku) lookup.set(normSku(d.sku), d);
  });

  // Также по имени (fallback)
  const nameLookup = new Map();
  fileData.forEach(d => {
    if (d.name) nameLookup.set(d.name.toLowerCase().trim(), d);
  });

  const updatedItems = items.map(item => {
    // Сначала по SKU
    let match = item.sku ? lookup.get(normSku(item.sku)) : null;

    // Fallback по имени
    if (!match && item.name) {
      match = nameLookup.get(item.name.toLowerCase().trim());
    }

    if (!match) return item;

    matched++;
    const updated = { ...item };

    if (target === 'order') {
      if (match.stock !== undefined) updated.stock = match.stock;
      if (match.transit !== undefined) updated.transit = match.transit;
      if (match.consumption !== undefined) updated.consumptionPeriod = match.consumption;
    } else {
      // planning
      if (match.stock !== undefined) updated.stockOnHand = match.stock;
      if (match.transit !== undefined) updated.stockAtSupplier = match.transit;
      if (match.consumption !== undefined) updated.monthlyConsumption = match.consumption;
    }

    return updated;
  });

  return { items: updatedItems, matched };
}

function normSku(sku) {
  return String(sku).replace(/[\s\-\.]/g, '').toLowerCase();
}

/**
 * Превью импорта при 0 совпадений — показываем что нашли в файле
 */
function showImportPreview(fileData, items, target, callback) {
  // Показываем первые 5 строк из файла для диагностики
  const preview = fileData.slice(0, 5).map(d =>
    `${d.sku || '—'} | ${d.name || '—'} | ост: ${d.stock ?? '—'}`
  ).join('\n');

  console.log('Import preview (first 5 rows):\n' + preview);
  console.log('Items SKUs:', items.slice(0, 5).map(i => i.sku).join(', '));
}