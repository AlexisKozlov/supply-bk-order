/**
 * Импорт остатков из Excel/CSV
 * Маппинг по артикулу (SKU) → автозаполнение остатков
 * Работает и для заказа, и для планирования
 */

const LEGAL_ENTITY_MAP = {
  'сбарро':          'Пицца Стар',
  'додо':            'Пицца Стар',
  'пицца стар':      'Пицца Стар',
  'бургер бк':       'Бургер БК',
  'ооо "бургер бк"': 'Бургер БК',
  'ооо бургер бк':   'Бургер БК',
  'воглия матта':    'Воглия Матта',
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
  return isNaN(n) ? 0 : Math.round(n);
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

function matchData(items, fileData, target) {
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
  return { items: updatedItems, matched };
}

// ─── Публичный API ─────────────────────────────────────────────────────────

/**
 * Открыть диалог импорта файла.
 * @param {'order'|'planning'} target
 * @param {Array} items — текущие позиции
 * @param {string} legalEntity
 * @returns {Promise<{items, matched, total}|null>}
 */
export function importFromFile(target, items, legalEntity) {
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
        const result = matchData(items, data, target);
        console.log(`[importStock] File: ${file.name}, parsed: ${data.length} items, matched: ${result.matched}/${items.length}, legalEntity: ${legalEntity}`);
        if (result.matched < items.length) {
          const unmatched = items.filter((item, idx) => {
            const updated = result.items[idx];
            return updated.stock === item.stock && updated.consumptionPeriod === item.consumptionPeriod
              && updated.stockOnHand === item.stockOnHand;
          });
          if (unmatched.length > 0) {
            console.log(`[importStock] Unmatched items (${unmatched.length}):`);
            unmatched.slice(0, 10).forEach(i => console.log(`  sku=${i.sku || '?'} name="${i.name || '?'}"`));
          }
          if (data.length > 0) {
            console.log(`[importStock] File sample:`);
            data.slice(0, 5).forEach(d => console.log(`  sku=${d.sku || '?'} name="${(d.name || '?').slice(0, 40)}" stock=${d.stock ?? '-'}`));
          }
        }
        resolve({ items: result.items, matched: result.matched, total: data.length });
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
