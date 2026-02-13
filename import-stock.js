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
async function parseFile(file, legalEntity) {
  const ext = file.name.split('.').pop().toLowerCase();

  if (ext === 'csv' || ext === 'tsv') {
    return parseCSV(file, ext === 'tsv' ? '\t' : detectDelimiter, legalEntity);
  }

  // Excel
  const XLSX = await import('https://cdn.sheetjs.com/xlsx-0.20.1/package/xlsx.mjs');
  const buffer = await file.arrayBuffer();
  const wb = XLSX.read(buffer, { type: 'array' });
  const ws = wb.Sheets[wb.SheetNames[0]];
  const rows = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });

  return mapRows(rows, legalEntity);
}

/**
 * Парсинг CSV
 */
function parseCSV(file, delimiterOrDetect, legalEntity) {
  return new Promise((resolve) => {
    const reader = new FileReader();
    reader.onload = (e) => {
      const text = e.target.result;
      const delimiter = typeof delimiterOrDetect === 'string'
        ? delimiterOrDetect
        : detectDelimiter(text);
      const lines = text.split('\n').filter(l => l.trim());
      const rows = lines.map(l => l.split(delimiter).map(c => c.trim().replace(/^["']|["']$/g, '')));
      resolve(mapRows(rows, legalEntity));
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
/** Маппинг юр. лиц из файла склада → юр. лица приложения */
const LEGAL_ENTITY_MAP = {
  'сбарро':          'Пицца Стар',
  'додо':            'Пицца Стар',
  'пицца стар':      'Пицца Стар',
  'бургер бк':       'Бургер БК',
  'ооо "бургер бк"': 'Бургер БК',
  'ооо бургер бк':   'Бургер БК',
  'воглия матта':    'Воглия Матта',
};

function mapLegalEntity(raw) {
  if (!raw) return null;
  const norm = raw.toLowerCase().replace(/[«»""]/g, '"').trim();
  for (const [key, value] of Object.entries(LEGAL_ENTITY_MAP)) {
    if (norm.includes(key)) return value;
  }
  return null;
}

function mapRows(rows, legalEntity) {
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
    name: findCol(headers, ['наименование товара', 'наименование', 'название', 'товар', 'name', 'product', 'номенклатура']),
    stock: findCol(headers, ['остатки, кол', 'остатки', 'остаток', 'склад', 'stock', 'кол-во', 'количество', 'свобод', 'доступ']),
    transit: findCol(headers, ['транзит', 'в пути', 'transit', 'ожидаем', 'поставщик', 'резерв']),
    consumption: findCol(headers, ['расход', 'потребление', 'consumption', 'продажи', 'реализация', 'списание']),
    legalEntity: findCol(headers, ['юр лицо', 'юр. лицо', 'юридическое лицо', 'организация', 'legal entity', 'компания', 'фирма'])
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

    let sku = colMap.sku >= 0 ? String(row[colMap.sku] || '').trim() : '';
    let name = colMap.name >= 0 ? String(row[colMap.name] || '').trim() : '';

    // Если sku и name — одна и та же колонка, или только одна из них
    // Пробуем извлечь артикул из текста
    if (!sku && name) {
      const extracted = extractSkuFromText(name);
      sku = extracted.sku;
      if (extracted.name) name = extracted.name;
    } else if (sku && !name) {
      const extracted = extractSkuFromText(sku);
      if (extracted.name) {
        name = extracted.name;
        sku = extracted.sku;
      }
    } else if (colMap.sku === colMap.name && sku) {
      // Одна колонка — и sku и name ссылаются на неё
      const extracted = extractSkuFromText(sku);
      sku = extracted.sku;
      name = extracted.name || sku;
    }

    if (!sku && !name) continue;

    // Фильтрация по юр. лицу (если колонка найдена и legalEntity передан)
    if (colMap.legalEntity >= 0 && legalEntity) {
      const rawEntity = String(row[colMap.legalEntity] || '').trim();
      const mapped = mapLegalEntity(rawEntity);
      if (mapped && mapped !== legalEntity) continue;
    }

    const entry = { sku, name };

    if (colMap.stock >= 0) entry.stock = parseNum(row[colMap.stock]);
    if (colMap.transit >= 0) entry.transit = parseNum(row[colMap.transit]);
    if (colMap.consumption >= 0) entry.consumption = parseNum(row[colMap.consumption]);

    data.push(entry);
  }

  return data;
}

/**
 * Извлекает артикул из строки вида:
 * "12345 Бургер Классик" → { sku: "12345", name: "Бургер Классик" }
 * "АРТ-001 Соус BBQ 1кг" → { sku: "АРТ-001", name: "Соус BBQ 1кг" }
 * "Котлета говяжья 150г" → { sku: "", name: "Котлета говяжья 150г" }
 */
function extractSkuFromText(text) {
  text = text.trim();
  
  // Паттерн 1: начинается с артикула (цифры, возможно с буквами/дефисами) + пробел + наименование
  // Примеры: "12345 Товар", "АРТ-001 Товар", "SKU001 Товар"
  const match = text.match(/^([A-Za-zА-Яа-я]{0,4}[\-]?\d{2,}[\-\d]*)\s+(.+)$/);
  if (match) {
    return { sku: match[1].trim(), name: match[2].trim() };
  }

  // Паттерн 2: артикул в начале — чисто цифровой
  const numMatch = text.match(/^(\d{3,})\s+(.+)$/);
  if (numMatch) {
    return { sku: numMatch[1], name: numMatch[2].trim() };
  }

  // Паттерн 3: наименование потом артикул в скобках: "Товар (12345)"
  const bracketsMatch = text.match(/^(.+?)\s*\((\d{3,})\)\s*$/);
  if (bracketsMatch) {
    return { sku: bracketsMatch[2], name: bracketsMatch[1].trim() };
  }

  // Не удалось разделить
  return { sku: '', name: text };
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
export function showImportDialog(target, items, callback, legalEntity) {
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = '.xlsx,.xls,.csv,.tsv';

  input.addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;

    try {
      const data = await parseFile(file, legalEntity);
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

  // Lookup по нормализованному SKU
  const skuLookup = new Map();
  fileData.forEach(d => {
    if (d.sku) skuLookup.set(normSku(d.sku), d);
  });

  // Lookup по имени (точное)
  const nameLookup = new Map();
  fileData.forEach(d => {
    if (d.name) nameLookup.set(d.name.toLowerCase().trim(), d);
  });

  // Lookup по SKU внутри name колонки файла (когда артикул+наименование в одной ячейке)
  // и по name внутри name колонки файла (частичное совпадение)
  const nameContainsLookup = [];
  fileData.forEach(d => {
    const combined = `${d.sku} ${d.name}`.toLowerCase();
    nameContainsLookup.push({ combined, data: d });
  });

  const updatedItems = items.map(item => {
    let match = null;

    // 1. Точное совпадение по SKU
    if (item.sku) {
      match = skuLookup.get(normSku(item.sku));
    }

    // 2. Точное совпадение по имени
    if (!match && item.name) {
      match = nameLookup.get(item.name.toLowerCase().trim());
    }

    // 3. SKU товара содержится в строке файла (или наоборот)
    if (!match && item.sku) {
      const normItemSku = normSku(item.sku);
      const found = nameContainsLookup.find(e =>
        normSku(e.combined).includes(normItemSku) ||
        (e.data.sku && normItemSku.includes(normSku(e.data.sku)))
      );
      if (found) match = found.data;
    }

    // 4. Наименование товара содержится в строке файла
    if (!match && item.name) {
      const normName = item.name.toLowerCase().trim();
      const found = nameContainsLookup.find(e =>
        e.combined.includes(normName) || normName.includes(e.data.name?.toLowerCase()?.trim())
      );
      if (found) match = found.data;
    }

    if (!match) return item;

    matched++;
    const updated = { ...item };

    if (target === 'order') {
      if (match.stock !== undefined) updated.stock = match.stock;
      if (match.transit !== undefined) updated.transit = match.transit;
      if (match.consumption !== undefined) updated.consumptionPeriod = match.consumption;
    } else {
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