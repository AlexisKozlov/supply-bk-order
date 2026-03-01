/**
 * Импорт остатков из Excel/CSV
 * Маппинг по артикулу (SKU) → автозаполнение остатков
 * Работает и для заказа, и для планирования
 */

import { db } from '@/lib/apiClient.js';
import { debug, getQpb } from '@/lib/utils.js';

const LEGAL_ENTITY_MAP = {
  'сбарро':              'ООО "Пицца Стар"',
  'додо':                'ООО "Пицца Стар"',
  'пицца стар':          'ООО "Пицца Стар"',
  'ооо "пицца стар"':    'ООО "Пицца Стар"',
  'ооо пицца стар':      'ООО "Пицца Стар"',
  'бургер бк':           'ООО "Бургер БК"',
  'ооо "бургер бк"':     'ООО "Бургер БК"',
  'ооо бургер бк':       'ООО "Бургер БК"',
  'воглия матта':        'ООО "Воглия Матта"',
  'ооо "воглия матта"':  'ООО "Воглия Матта"',
  'ооо воглия матта':    'ООО "Воглия Матта"',
};

const HEADER_KEYWORDS = [
  'артикул', 'наименование', 'остат', 'расход', 'sku', 'stock',
  'название', 'товар', 'кол-во', 'количество', 'транзит',
  'организация', 'юр', 'номенклатура', 'заказчик',
  'единица измерения', 'штрих-код', 'годен', 'склад',
  'приход', 'конечный', 'начальный'
];

function mapLegalEntity(raw) {
  if (!raw) return null;
  const norm = raw.toLowerCase().replace(/[«»""]/g, '"').trim();
  for (const [key, value] of Object.entries(LEGAL_ENTITY_MAP)) {
    if (norm.includes(key)) return value;
  }
  return null;
}

function findHeaderRow(rows) {
  // Try to find a row with >=2 keyword matches
  for (let i = 0; i < Math.min(rows.length, 20); i++) {
    const cells = rows[i].map(c => String(c ?? '').toLowerCase().trim());
    const hits = cells.filter(cell => cell && HEADER_KEYWORDS.some(kw => cell.includes(kw))).length;
    if (hits >= 2) return i;
  }
  // Fallback: any row with >= 1 keyword and >=3 text cells
  for (let i = 0; i < Math.min(rows.length, 20); i++) {
    const cells = rows[i].map(c => String(c ?? '').toLowerCase().trim());
    const hasKw = cells.some(cell => cell && HEADER_KEYWORDS.some(kw => cell.includes(kw)));
    const textCells = rows[i].filter(c => typeof c === 'string' && c.length > 1).length;
    if (hasKw && textCells >= 3) return i;
  }
  // Fallback: first row with >=3 text cells
  for (let i = 0; i < Math.min(rows.length, 20); i++) {
    if (rows[i].filter(c => typeof c === 'string' && c.length > 1).length >= 3) return i;
  }
  return 0;
}

/**
 * Merge two header rows — used when headers span multiple rows (e.g. Остатки за месяц)
 * Fills empty cells in row1 with values from row2
 */
function mergeHeaderRows(row1, row2) {
  const maxLen = Math.max(row1.length, row2.length);
  const merged = [];
  for (let i = 0; i < maxLen; i++) {
    const v1 = String(row1[i] ?? '').trim();
    const v2 = String(row2[i] ?? '').trim();
    // If row1 is empty but row2 has value, use row2
    if (!v1 && v2) merged.push(v2);
    // If both have values, combine
    else if (v1 && v2 && v1 !== v2) merged.push(v1 + ' ' + v2);
    else merged.push(v1);
  }
  return merged;
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
  const s = String(val).replace(/\s/g, '').replace(',', '.');
  const n = parseFloat(s);
  return isNaN(n) ? 0 : Math.round(n * 10) / 10;
}

function normSku(sku) {
  // Normalize for matching: lowercase, strip whitespace/separators
  let s = String(sku).replace(/[\s\-\_\.]/g, '').toLowerCase();
  // Strip known prefixes: DDI_, BK_ etc
  s = s.replace(/^(ddi|bk|art|sku)/, '');
  return s;
}

function extractSkuFromText(text) {
  text = text.trim().replace(/,\s*,\s*$/, '').replace(/\s+,\s*$/, '').trim();
  // Remove \xa0 (non-breaking space)
  text = text.replace(/\u00a0/g, ' ').trim();

  // Pattern: "100183 Чай Richard..." or "51030-1 Сахар..." or "2012Д10 Мука..." or "75980059 Соус..."
  // SKU: alphanumeric code at start, possibly with - _ . and Cyrillic letters (e.g. 2012Д10)
  // Must contain at least one digit
  const match = text.match(/^([A-Za-zА-Яа-яЁё0-9][-_A-Za-zА-Яа-яЁё0-9.]{0,20})\s+(.{3,})$/);
  if (match) {
    const code = match[1];
    const rest = match[2].trim();
    // Code must have at least one digit and NOT be purely Cyrillic words
    if (/\d/.test(code) && !/^[А-Яа-яЁё]+$/.test(code)) {
      return { sku: code, name: rest };
    }
  }
  // Pattern: "Name (SKU)"
  const bm = text.match(/^(.+?)\s*\(([A-Za-zА-Яа-яЁё0-9][-_A-Za-zА-Яа-яЁё0-9.]{1,14})\)\s*$/);
  if (bm && /\d/.test(bm[2])) return { sku: bm[2], name: bm[1].trim() };
  return { sku: '', name: text };
}

function mapRows(rows, legalEntity) {
  if (rows.length < 2) return [];
  const headerIdx = findHeaderRow(rows);

  // Try merging current header row with next row (handles Остатки за месяц multi-row headers)
  // But ONLY if next row really looks like a header, not data
  let headers = rows[headerIdx].map(h => String(h ?? '').toLowerCase().trim());
  let headerRowsCount = 1;
  const nextRow = rows[headerIdx + 1];
  if (nextRow) {
    const nextCells = nextRow.map(h => String(h ?? '').toLowerCase().trim());
    const nextHits = nextCells.filter(h => h && HEADER_KEYWORDS.some(kw => h.includes(kw))).length;
    // Count how many cells are numbers (data indicator)
    const numericCells = nextRow.filter(v => typeof v === 'number' || (typeof v === 'string' && /^\d+[\d.,]*$/.test(v.trim()))).length;
    // Only merge if >=3 keyword hits AND few numbers (headers don't have many numbers)
    if (nextHits >= 3 && numericCells <= 2) {
      headers = mergeHeaderRows(headers, nextCells);
      headerRowsCount = 2;
    }
  }

  const colMap = {
    sku: findCol(headers, ['артикул', 'арт', 'sku', 'article']),
    name: findCol(headers, ['наименование товара', 'наименование', 'название', 'номенклатура', 'товар', 'name', 'product']),
    stock: findCol(headers, ['остатки, кол', 'остатки', 'конечный остаток', 'остаток', 'stock', 'свобод', 'доступ']),
    transit: findCol(headers, ['транзит', 'в пути', 'transit', 'ожидаем', 'резерв']),
    consumption: findCol(headers, ['расход', 'потребление', 'consumption', 'продажи', 'реализация', 'списание']),
    legalEntity: findCol(headers, ['заказчик', 'короткое наименован', 'юр лицо', 'юр. лицо', 'юридическое лицо', 'организация', 'legal entity', 'компания', 'фирма']),
  };

  if (colMap.sku === -1 && colMap.name === -1) return [];

  // Determine data start row
  let dataStart = headerIdx + headerRowsCount;

  const allData = [];
  for (let i = dataStart; i < rows.length; i++) {
    const row = rows[i];
    if (!row || row.length < 2) continue;

    let sku = colMap.sku >= 0 ? String(row[colMap.sku] ?? '').trim() : '';
    let name = colMap.name >= 0 ? String(row[colMap.name] ?? '').trim() : '';

    // Handle "Номенклатура, Характеристика, Серия" format: "100045 Молоко стерилизованное..."
    // Extract sku from start of name if sku column is empty or same as name
    if (!sku && name) {
      const e = extractSkuFromText(name); sku = e.sku; if (e.name) name = e.name;
    } else if (sku && !name) {
      const e = extractSkuFromText(sku); if (e.name) { name = e.name; sku = e.sku; }
    } else if (colMap.sku === colMap.name && sku) {
      const e = extractSkuFromText(sku); sku = e.sku; name = e.name || sku;
    } else if (sku && name) {
      // We have sku from column, but name may also start with sku (1C format: "100045 Молоко...")
      // Clean sku prefix from name for better readability
      const e = extractSkuFromText(name);
      if (e.sku && e.name) name = e.name;
    }

    // Clean up name: remove trailing ", , " patterns from 1C exports
    name = name.replace(/,\s*,\s*$/, '').replace(/\s+,\s*$/, '').trim();

    if (!sku && !name) continue;
    // Skip sub-header rows (e.g. warehouse names like "Распределительный центр")
    if (!sku && colMap.sku >= 0) {
      const rawSku = String(row[colMap.sku] ?? '').trim();
      // If sku col has text but no digits — likely a section header
      if (rawSku && !/\d/.test(rawSku) && rawSku.length > 15) continue;
    }

    const entry = { sku, name };
    if (colMap.stock >= 0) entry.stock = parseNum(row[colMap.stock]);
    if (colMap.transit >= 0) entry.transit = parseNum(row[colMap.transit]);
    if (colMap.consumption >= 0) entry.consumption = parseNum(row[colMap.consumption]);
    if (colMap.legalEntity >= 0) entry._rawEntity = String(row[colMap.legalEntity] || '').trim();
    allData.push(entry);
  }

  let data = allData;
  if (colMap.legalEntity >= 0 && legalEntity && allData.length > 0) {
    const filtered = allData.filter(e => { const m = mapLegalEntity(e._rawEntity); return !m || m === legalEntity; });
    if (filtered.length > 0) data = filtered;
  }
  return aggregateByProduct(data);
}

function aggregateByProduct(data) {
  const map = new Map();
  for (const entry of data) {
    const key = entry.sku ? normSku(entry.sku) : entry.name.toLowerCase().trim();
    if (map.has(key)) {
      const ex = map.get(key);
      if (entry.stock !== undefined) ex.stock = (ex.stock || 0) + entry.stock;
      if (entry.transit !== undefined) ex.transit = (ex.transit || 0) + entry.transit;
      if (entry.consumption !== undefined) ex.consumption = (ex.consumption || 0) + entry.consumption;
    } else {
      map.set(key, { ...entry });
    }
  }
  return Array.from(map.values());
}

// ─── Парсинг файлов ────────────────────────────────────────────────────────

async function parseFile(file, legalEntity) {
  const ext = file.name.split('.').pop().toLowerCase();
  if (ext === 'csv' || ext === 'tsv') return parseCSV(file, ext === 'tsv' ? '\t' : null, legalEntity);

  const XLSX = await import('xlsx');
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
  return mapRows(rows, legalEntity);
}

function parseCSV(file, delimiter, legalEntity) {
  return new Promise((resolve) => {
    const reader = new FileReader();
    reader.onload = (e) => {
      const text = e.target.result;
      const delim = delimiter || detectDelimiter(text);
      const lines = text.split('\n').filter(l => l.trim());
      const rows = lines.map(l => l.split(delim).map(c => c.trim().replace(/^["']|["']$/g, '')));
      resolve(mapRows(rows, legalEntity));
    };
    reader.onerror = () => resolve([]);
    reader.readAsText(file, 'utf-8');
  });
}

function detectDelimiter(text) {
  const first = text.split('\n')[0];
  if (first.includes('\t')) return '\t';
  if (first.includes(';')) return ';';
  return ',';
}

// ─── Маппинг данных файла → items ──────────────────────────────────────────

async function matchData(items, fileData, target, unit) {
  let matched = 0;

  // Build SKU lookup with multiple normalization variants
  const skuLookup = new Map();
  fileData.forEach(d => {
    if (!d.sku) return;
    const n = normSku(d.sku);
    skuLookup.set(n, d);
    // Also index without leading zeros for cross-matching (e.g. "001355" → "1355")
    const noZeros = n.replace(/^0+(\d+)/, '$1');
    if (noZeros !== n) skuLookup.set(noZeros, d);
  });

  // Build name lookup
  const nameLookup = new Map();
  fileData.forEach(d => { if (d.name) nameLookup.set(d.name.toLowerCase().trim(), d); });

  // Word-based index for fuzzy name matching
  const wordIndex = fileData.map(d => {
    const words = `${d.sku || ''} ${d.name || ''}`.toLowerCase()
      .replace(/[,."'()\/\\]/g, ' ').split(/\s+/).filter(w => w.length > 2);
    return { words, data: d };
  });

  // Track used file entries for fuzzy matches to prevent double-matching
  const usedFuzzy = new Set();
  // Track which file entries got matched
  const matchedFileEntries = new Set();

  const updatedItems = items.map(item => {
    let match = null;
    let isFuzzy = false;

    // 1. Exact SKU match (normalized, with leading-zero tolerance)
    if (item.sku) {
      const n = normSku(item.sku);
      match = skuLookup.get(n);
      // Also try without leading zeros
      if (!match) {
        const noZeros = n.replace(/^0+(\d+)/, '$1');
        if (noZeros !== n) match = skuLookup.get(noZeros);
      }
    }

    // 2. Exact name match (skip for analysis — only SKU matching)
    if (!match && item.name && target !== 'analysis') match = nameLookup.get(item.name.toLowerCase().trim());

    // 3. Word-overlap fuzzy name match (≥60% words overlap, ≥2 words; skip for analysis)
    if (!match && item.name && target !== 'analysis') {
      const itemWords = `${item.sku || ''} ${item.name}`.toLowerCase()
        .replace(/[,."'()\/\\]/g, ' ').split(/\s+/).filter(w => w.length > 2);
      if (itemWords.length >= 2) {
        let bestScore = 0, bestMatch = null;
        for (const entry of wordIndex) {
          if (usedFuzzy.has(entry.data)) continue;
          let overlap = 0;
          for (const w of itemWords) {
            if (entry.words.some(ew => ew === w || (w.length > 4 && ew.includes(w)) || (ew.length > 4 && w.includes(ew)))) {
              overlap++;
            }
          }
          const score = overlap / itemWords.length;
          if (overlap >= 2 && score >= 0.6 && score > bestScore) {
            bestScore = score;
            bestMatch = entry.data;
          }
        }
        if (bestMatch) { match = bestMatch; isFuzzy = true; }
      }
    }

    if (!match) return item;
    if (isFuzzy) usedFuzzy.add(match);
    matchedFileEntries.add(match);
    matched++;
    const updated = { ...item };
    if (target === 'order') {
      if (match.stock !== undefined) updated.stock = match.stock;
      if (match.transit !== undefined) updated.transit = match.transit;
      if (match.consumption !== undefined) updated.consumptionPeriod = match.consumption;
    } else if (target === 'analysis') {
      if (match.stock !== undefined) updated.stock = match.stock;
      if (match.consumption !== undefined) updated.consumption = match.consumption;
    } else {
      if (match.stock !== undefined) updated.stockOnHand = match.stock;
      if (match.transit !== undefined) updated.stockAtSupplier = match.transit;
      if (match.consumption !== undefined) updated.monthlyConsumption = match.consumption;
    }
    return updated;
  });
  // ─── Фаза аналогов (только для order и planning) ──────────────────────
  const analogMerges = [];
  if (target !== 'analysis') {
    try {
      // 1. Собрать SKU всех позиций заказа/плана
      const itemSkus = new Set(items.map(i => i.sku).filter(Boolean));

      // 2. Запросить analog_group для всех SKU позиций
      if (itemSkus.size > 0) {
        const skuList = [...itemSkus];
        const { data: products } = await db.from('products')
          .select('sku,name,analog_group')
          .in('sku', skuList);

        if (products?.length) {
          // Карта SKU → analog_group
          const skuToGroup = new Map();
          const skuToName = new Map();
          const groups = new Set();
          for (const p of products) {
            if (p.analog_group) {
              skuToGroup.set(p.sku, p.analog_group);
              skuToName.set(p.sku, p.name);
              groups.add(p.analog_group);
            }
          }
          // 3. Запросить все продукты с analog_group и отфильтровать на клиенте
          // (in() не работает с запятыми в значениях, напр. «Стакан Пепси 0,5л»)
          if (groups.size > 0) {
            const { data: allAnalogProducts } = await db.from('products')
              .select('sku,name,analog_group,qty_per_box')
              .neq('analog_group', '');
            const groupProducts = (allAnalogProducts || []).filter(p => groups.has(p.analog_group));

            // Карта group → [sku, ...], SKU → qtyPerBox
            const groupToSkus = new Map();
            const analogSkuToName = new Map();
            const analogSkuToQpb = new Map();
            if (groupProducts.length) {
              for (const p of groupProducts) {
                if (!groupToSkus.has(p.analog_group)) groupToSkus.set(p.analog_group, []);
                groupToSkus.get(p.analog_group).push(p.sku);
                analogSkuToName.set(p.sku, p.name);
                analogSkuToQpb.set(p.sku, p.qty_per_box || 1);
              }
            }

            // 4. Для каждого item — собрать доступные аналоги (без применения)
            for (let idx = 0; idx < updatedItems.length; idx++) {
              const item = updatedItems[idx];
              if (!item.sku) continue;
              const group = skuToGroup.get(item.sku);
              if (!group) continue;
              const analogs = groupToSkus.get(group);
              if (!analogs) continue;

              const foundAnalogs = [];
              for (const analogSku of analogs) {
                if (analogSku === item.sku) continue;
                if (itemSkus.has(analogSku)) continue;

                const normKey = normSku(analogSku);
                const fileEntry = skuLookup.get(normKey)
                  || skuLookup.get(normKey.replace(/^0+(\d+)/, '$1'));
                if (!fileEntry) continue;

                const analogQpb = analogSkuToQpb.get(analogSku) || 1;
                const itemQpb = item.qtyPerBox || 1;
                const needConvert = unit === 'boxes' && analogQpb !== itemQpb && itemQpb > 0;
                const ratio = needConvert ? analogQpb / itemQpb : 1;
                const convertVal = (v) => Math.round(v * ratio * 10) / 10;

                foundAnalogs.push({
                  sku: analogSku,
                  name: analogSkuToName.get(analogSku) || '',
                  stock: convertVal(fileEntry.stock ?? 0),
                  transit: convertVal(fileEntry.transit ?? 0),
                  consumption: convertVal(fileEntry.consumption ?? 0),
                  _fileEntry: fileEntry,
                  checked: true,
                });
              }

              if (foundAnalogs.length > 0) {
                analogMerges.push({
                  itemSku: item.sku,
                  itemName: item.name || skuToName.get(item.sku) || item.sku,
                  itemIdx: idx,
                  analogs: foundAnalogs,
                });
              }
            }
          }
        }
      }
    } catch (err) {
      console.warn('[importStock] Ошибка при обработке аналогов:', err);
    }
  }
  // Пост-обработка дублей аналогов (один аналог → несколько товаров)
  const seenAnalogs = new Map();
  for (const merge of analogMerges) {
    for (const a of merge.analogs) {
      if (seenAnalogs.has(a.sku)) {
        a.shared = true;
        a.sharedWith = seenAnalogs.get(a.sku);
        a.checked = false;
      } else {
        seenAnalogs.set(a.sku, merge.itemSku);
      }
    }
  }
  // Collect file entries that didn't match any item (only with SKU)
  const unmatchedFile = fileData.filter(d => d.sku && !matchedFileEntries.has(d)).map(d => ({
    sku: d.sku,
    name: d.name || '',
    stock: d.stock ?? 0,
    consumption: d.consumption ?? 0,
  }));
  return { items: updatedItems, matched, unmatchedFile, analogMerges };
}

/**
 * Применить выбранные аналоги к позициям заказа/плана.
 * Вызывается из View после подтверждения пользователем в модалке.
 * @param {Array} storeItems — реактивные позиции из стора (orderStore.items / items.value)
 * @param {Array} analogMerges — массив из результата импорта (с checked-флагами)
 * @param {'order'|'planning'} target
 */
export function applyAnalogMerges(storeItems, analogMerges, target) {
  let applied = 0;
  for (const merge of analogMerges) {
    const item = merge.itemSku
      ? storeItems.find(i => i.sku === merge.itemSku)
      : storeItems[merge.itemIdx];
    if (!item) continue;
    for (const a of merge.analogs) {
      if (!a.checked) continue;
      if (target === 'order') {
        if (a.stock) item.stock = (item.stock || 0) + a.stock;
        if (a.transit) item.transit = (item.transit || 0) + a.transit;
        if (a.consumption) item.consumptionPeriod = (item.consumptionPeriod || 0) + a.consumption;
      } else {
        if (a.stock) item.stockOnHand = (item.stockOnHand || 0) + a.stock;
        if (a.transit) item.stockAtSupplier = (item.stockAtSupplier || 0) + a.transit;
        if (a.consumption) item.monthlyConsumption = (item.monthlyConsumption || 0) + a.consumption;
      }
      applied++;
    }
  }
  return applied;
}

// ─── Загрузка расхода/остатков из analysis_data ────────────────────────────

/**
 * Загрузить расход и остатки из таблицы analysis_data (страница «Анализ запасов»).
 * @param {'order'|'planning'} target
 * @param {Array} items — текущие позиции
 * @param {string} legalEntity — юр. лицо
 * @param {string} unit — 'boxes' | 'pieces'
 * @param {number} targetPeriodDays — период расхода (в днях), под который пересчитывать
 * @returns {Promise<{matched, total, updatedAt, updatedBy, analogMerges}>}
 */
export async function loadFromAnalysis(target, items, legalEntity, unit, targetPeriodDays = 30) {
  // 1. Загрузить analysis_data по конкретному юрлицу (не по группе — каждое юрлицо импортирует свои данные)
  const { data, error } = await db.from('analysis_data')
    .select('sku, stock, consumption, period_days, updated_by, updated_at')
    .eq('legal_entity', legalEntity);

  if (error) throw new Error('Не удалось загрузить данные анализа');

  // 2. Построить карту sku → данные
  const adMap = new Map();
  if (data?.length) {
    data.forEach(d => {
      if (!d.sku) return;
      adMap.set(d.sku, d);
      // Индекс без ведущих нулей
      const noZeros = normSku(d.sku);
      adMap.set(noZeros, d);
    });
  }

  // 3. Определить дату обновления (самая свежая)
  let updatedAt = null;
  let updatedBy = '';
  if (data?.length) {
    data.forEach(d => {
      const t = d.updated_at ? new Date(d.updated_at) : null;
      if (t && (!updatedAt || t > updatedAt)) {
        updatedAt = t;
        updatedBy = d.updated_by || '';
      }
    });
  }

  // 4. Заполнить позиции
  let matched = 0;
  const matchedSkus = new Set();

  items.forEach(item => {
    if (!item.sku) return;
    const d = adMap.get(item.sku) || adMap.get(normSku(item.sku));
    if (!d) return;
    matched++;
    matchedSkus.add(d.sku);

    const srcPeriodDays = d.period_days || 30;
    // Если период совпадает — берём как есть, без потерь от округления
    const consumptionPcs = srcPeriodDays === targetPeriodDays
      ? Math.round((d.consumption || 0) * 10) / 10
      : Math.round(((d.consumption || 0) / srcPeriodDays) * targetPeriodDays * 10) / 10;
    const stockPcs = Math.round((d.stock || 0) * 10) / 10;
    const qpb = getQpb(item);

    if (target === 'order') {
      // unit=boxes → пересчёт через qtyPerBox (штук в упаковке)
      item.consumptionPeriod = unit === 'boxes'
        ? Math.round(consumptionPcs / qpb * 10) / 10
        : consumptionPcs;
      item.stock = unit === 'boxes'
        ? Math.round(stockPcs / qpb * 10) / 10
        : stockPcs;
    } else {
      // planning: monthlyConsumption — расход за выбранный период в текущих единицах
      item.monthlyConsumption = unit === 'boxes'
        ? Math.round(consumptionPcs / qpb * 100) / 100
        : consumptionPcs;
      // stockOnHand — всегда в штуках (PlanningView хранит так)
      item.stockOnHand = stockPcs;
    }
  });

  // 5. Фаза аналогов
  const analogMerges = [];
  try {
    const itemSkus = new Set(items.map(i => i.sku).filter(Boolean));
    if (itemSkus.size > 0) {
      const skuList = [...itemSkus];
      const { data: products } = await db.from('products')
        .select('sku,name,analog_group')
        .in('sku', skuList);

      if (products?.length) {
        const skuToGroup = new Map();
        const skuToName = new Map();
        const groups = new Set();
        for (const p of products) {
          if (p.analog_group) {
            skuToGroup.set(p.sku, p.analog_group);
            skuToName.set(p.sku, p.name);
            groups.add(p.analog_group);
          }
        }

        if (groups.size > 0) {
          const { data: allAnalogProducts } = await db.from('products')
            .select('sku,name,analog_group,qty_per_box')
            .neq('analog_group', '');
          const groupProducts = (allAnalogProducts || []).filter(p => groups.has(p.analog_group));

          const groupToSkus = new Map();
          const analogSkuToName = new Map();
          const analogSkuToQpb = new Map();
          for (const p of groupProducts) {
            if (!groupToSkus.has(p.analog_group)) groupToSkus.set(p.analog_group, []);
            groupToSkus.get(p.analog_group).push(p.sku);
            analogSkuToName.set(p.sku, p.name);
            analogSkuToQpb.set(p.sku, p.qty_per_box || 1);
          }

          for (let idx = 0; idx < items.length; idx++) {
            const item = items[idx];
            if (!item.sku) continue;
            const group = skuToGroup.get(item.sku);
            if (!group) continue;
            const analogs = groupToSkus.get(group);
            if (!analogs) continue;

            const foundAnalogs = [];
            for (const analogSku of analogs) {
              if (analogSku === item.sku) continue;
              if (itemSkus.has(analogSku)) continue;

              // Ищем аналог в данных analysis_data
              const analogData = adMap.get(analogSku) || adMap.get(normSku(analogSku));
              if (!analogData) continue;

              // analysis_data хранит всё в штуках — пересчитываем в единицы товара
              const itemQpb = item.qtyPerBox || 1;
              const analogPeriodDays = analogData.period_days || 30;
              const analogDaily = analogPeriodDays > 0 ? (analogData.consumption || 0) / analogPeriodDays : 0;
              const analogConsumptionPcs = Math.round(analogDaily * targetPeriodDays * 10) / 10;
              const analogStockPcs = Math.round((analogData.stock ?? 0) * 10) / 10;

              if (target === 'order') {
                foundAnalogs.push({
                  sku: analogSku,
                  name: analogSkuToName.get(analogSku) || '',
                  stock: unit === 'boxes' ? Math.round(analogStockPcs / itemQpb * 10) / 10 : analogStockPcs,
                  consumption: unit === 'boxes' ? Math.round(analogConsumptionPcs / itemQpb * 10) / 10 : analogConsumptionPcs,
                  checked: true,
                });
              } else {
                foundAnalogs.push({
                  sku: analogSku,
                  name: analogSkuToName.get(analogSku) || '',
                  stock: analogStockPcs,
                  consumption: unit === 'boxes' ? Math.round(analogConsumptionPcs / itemQpb * 10) / 10 : analogConsumptionPcs,
                  checked: true,
                });
              }
            }

            if (foundAnalogs.length > 0) {
              analogMerges.push({
                itemSku: item.sku,
                itemName: item.name || skuToName.get(item.sku) || item.sku,
                itemIdx: idx,
                analogs: foundAnalogs,
              });
            }
          }
        }
      }
    }
  } catch (err) {
    console.warn('[loadFromAnalysis] Ошибка при обработке аналогов:', err);
  }

  // 6. Пост-обработка: если один аналог предлагается нескольким товарам —
  //    пометить как общий и снять галочку у всех кроме первого
  const seenAnalogSkus = new Map(); // analogSku → первый itemSku
  for (const merge of analogMerges) {
    for (const a of merge.analogs) {
      if (seenAnalogSkus.has(a.sku)) {
        a.shared = true;
        a.sharedWith = seenAnalogSkus.get(a.sku);
        a.checked = false; // по умолчанию выключен у второго+ товара
      } else {
        seenAnalogSkus.set(a.sku, merge.itemSku);
      }
    }
  }

  return {
    matched,
    total: items.length,
    updatedAt,
    updatedBy,
    analogMerges,
  };
}

// ─── Публичный API ─────────────────────────────────────────────────────────

/**
 * Открыть диалог импорта файла.
 * @param {'order'|'planning'} target
 * @param {Array} items — текущие позиции
 * @param {string} legalEntity
 * @param {string} [unit] — текущая единица измерения ('boxes'|'pieces') для конвертации аналогов
 * @returns {Promise<{items, matched, total}|null>}
 */
export function importFromFile(target, items, legalEntity, unit) {
  return new Promise((resolve) => {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = '.xlsx,.xls,.csv,.tsv';

    let handled = false;

    input.addEventListener('change', async (e) => {
      handled = true;
      const file = e.target.files[0];
      if (!file) { resolve(null); return; }
      try {
        const data = await parseFile(file, legalEntity);
        if (!data.length) {
          resolve({ items, matched: 0, total: 0, error: `Не удалось распознать товары в файле "${file.name}". Проверьте формат файла.` });
          return;
        }
        const result = await matchData(items, data, target, unit);
        debug(`[importStock] File: ${file.name}, parsed: ${data.length} items, matched: ${result.matched}/${items.length}, legalEntity: ${legalEntity}`);
        if (result.matched < items.length) {
          const unmatched = items.filter((item, idx) => {
            const updated = result.items[idx];
            return updated.stock === item.stock && updated.consumptionPeriod === item.consumptionPeriod
              && updated.stockOnHand === item.stockOnHand;
          });
          if (unmatched.length > 0) {
            debug(`[importStock] Unmatched items (${unmatched.length}):`);
            unmatched.slice(0, 10).forEach(i => debug(`  sku=${i.sku || '?'} name="${i.name || '?'}"`));
          }
          if (data.length > 0) {
            debug(`[importStock] File sample:`);
            data.slice(0, 5).forEach(d => debug(`  sku=${d.sku || '?'} name="${(d.name || '?').slice(0, 40)}" stock=${d.stock ?? '-'}`));
          }
        }
        resolve({ items: result.items, matched: result.matched, total: data.length, unmatchedFile: result.unmatchedFile || [], analogMerges: result.analogMerges || [] });
      } catch (err) {
        console.error('[importStock] Error:', err);
        resolve({ items, matched: 0, total: 0, error: err.message || 'Не удалось прочитать файл' });
      }
    });

    // Detect cancel: when window regains focus and no file was selected
    const onFocus = () => {
      setTimeout(() => {
        if (!handled) resolve(null);
        window.removeEventListener('focus', onFocus);
      }, 300);
    };
    window.addEventListener('focus', onFocus);

    input.click();
  });
}
