/**
 * Парсинг файлов остатков и расхода ресторанов для распределения дефицита.
 *
 * Поддерживаемые форматы:
 * 1. Google-форма остатков: Время | Регион | "БК_Р51 Барановичи..." | Товар1 | Товар2 | ...
 * 2. Расход из 1С: "Ресторан 35" в столбце Склад + "Количество ТМЦ"
 * 3. Простой: 2 столбца (номер ресторана + значение)
 */

/**
 * Получить список листов из Excel-файла.
 * @param {File} file
 * @returns {Promise<string[]>}
 */
export async function getSheetNames(file) {
  const ext = file.name.split('.').pop().toLowerCase();
  if (ext === 'csv' || ext === 'tsv') return ['Sheet1'];
  const XLSX = await import('xlsx-js-style');
  const buffer = await file.arrayBuffer();
  const wb = XLSX.read(buffer, { type: 'array', bookSheets: true });
  return wb.SheetNames;
}

/**
 * Получить список колонок-товаров на листе (для выбора пользователем).
 * Возвращает массив { index, name } для каждого числового столбца.
 * @param {File} file
 * @param {string} [sheetName]
 * @returns {Promise<Array<{index: number, name: string}>>}
 */
export async function getProductColumns(file, sheetName) {
  const rows = await readFileRows(file, sheetName);
  if (!rows || rows.length < 2) return [];

  const { headerIdx, restCol } = detectLayout(rows);
  if (restCol === -1) return [];

  const headerRow = headerIdx >= 0 ? rows[headerIdx] : [];
  const dataStart = headerIdx >= 0 ? headerIdx + 1 : 0;
  const sampleRows = rows.slice(dataStart, dataStart + 15);

  const columns = [];
  const maxCols = Math.max(...rows.slice(dataStart, dataStart + 5).map(r => r.length), 0);

  for (let c = 0; c < maxCols; c++) {
    if (c === restCol) continue;

    // Проверяем что столбец числовой (>50% строк — числа)
    let numCount = 0;
    for (const row of sampleRows) {
      const v = row[c];
      if (typeof v === 'number') numCount++;
      else if (v && /^[\d][\d.,\s]*$/.test(String(v).trim())) numCount++;
    }
    if (numCount < sampleRows.length * 0.3) continue;

    // Пропускаем столбец с timestamp (серийный номер даты Excel > 40000)
    const firstVal = sampleRows[0]?.[c];
    if (typeof firstVal === 'number' && firstVal > 40000 && firstVal < 60000) continue;

    const name = headerRow[c] ? String(headerRow[c]).trim() : `Столбец ${c + 1}`;
    // Пропускаем служебные колонки
    const nameLower = name.toLowerCase();
    if (nameLower.includes('отметка времени') || nameLower === 'timestamp') continue;

    columns.push({ index: c, name });
  }

  return columns;
}

/**
 * Парсить файл (Excel/CSV) с данными ресторанов.
 * @param {File} file
 * @param {string} [sheetName] — имя листа (для Excel с несколькими листами)
 * @param {number} [columnIndex] — индекс столбца со значением (если не указан — автоопределение)
 * @returns {Promise<Array<{restaurantNumber: string, value: number}>>}
 */
export async function parseRestaurantFile(file, sheetName, columnIndex) {
  const rows = await readFileRows(file, sheetName);
  return extractRestaurantData(rows, columnIndex);
}

/**
 * Прочитать строки из файла.
 */
async function readFileRows(file, sheetName) {
  const ext = file.name.split('.').pop().toLowerCase();
  if (ext === 'csv' || ext === 'tsv') {
    return await parseCSV(file, ext === 'tsv' ? '\t' : null);
  }
  const XLSX = await import('xlsx-js-style');
  const buffer = await file.arrayBuffer();
  const wb = XLSX.read(buffer, { type: 'array' });
  const sheet = sheetName || wb.SheetNames[0];
  const ws = wb.Sheets[sheet];
  if (!ws) return [];
  return XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });
}

function parseCSV(file, delimiter) {
  return new Promise((resolve) => {
    const reader = new FileReader();
    reader.onload = (e) => {
      const text = e.target.result;
      const delim = delimiter || detectDelimiter(text);
      const lines = text.split('\n').filter(l => l.trim());
      resolve(lines.map(l => l.split(delim).map(c => c.trim().replace(/^["']|["']$/g, ''))));
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

function parseNum(val) {
  if (val === null || val === undefined || val === '') return 0;
  const s = String(val).replace(/\s/g, '').replace(',', '.');
  const n = parseFloat(s);
  return isNaN(n) ? 0 : Math.round(n * 100) / 100;
}

/**
 * Извлечь номер ресторана из текста.
 * Паттерны:
 * - "БК_Р51 Барановичи Центральный (Барановичи, Советская, 74А)" → "51"
 * - "BK_Р59 СитиМолл (Минск, Толстого, 1)" → "59"
 * - "Ресторан 35" → "35"
 * - "Р12 ЦУМ Минск" → "12"
 * - "35" → "35"
 */
function extractRestaurantNumber(text) {
  if (!text) return null;
  const s = String(text).trim();

  // "БК_Р51", "BK_Р59", "БК_Р-7" (с дефисом)
  let m = s.match(/[БбBb][КкKk][_\s-]*[РрRr][_\s-]*(\d{1,4})/i);
  if (m) return m[1];

  // "Р51", "R51", "Р-7"
  m = s.match(/[РрRr][_\s-]*(\d{1,4})/i);
  if (m) return m[1];

  // "Ресторан 35", "ресторан35"
  m = s.match(/ресторан[_\s-]*(\d{1,4})/i);
  if (m) return m[1];

  // Просто число (1-5 цифр)
  const clean = s.replace(/\s/g, '');
  if (/^\d{1,5}$/.test(clean)) return clean;

  return null;
}

/**
 * Определить layout файла: найти строку заголовков и столбец ресторанов.
 */
function detectLayout(rows) {
  if (!rows || rows.length < 1) return { headerIdx: -1, restCol: -1 };

  const headerKeywords = ['ресторан', 'номер', 'рест', 'точка', 'склад', 'restaurant', 'number'];
  const valueKeywords = ['остаток', 'расход', 'количество тмц', 'кол-во', 'количество', 'значение', 'stock', 'qty', 'value'];

  let headerIdx = -1;
  for (let i = 0; i < Math.min(rows.length, 15); i++) {
    const cells = rows[i].map(c => String(c ?? '').toLowerCase().trim());
    // Считаем только ячейки-заголовки: короткие (<50 символов) и без двоеточия (не описание)
    const headerCells = cells.filter(c => c && c.length < 50 && !c.includes(':'));
    const hasHeader = headerCells.some(c => headerKeywords.some(kw => c.includes(kw)));
    const hasValue = headerCells.some(c => valueKeywords.some(kw => c.includes(kw)));
    if (hasHeader || hasValue) {
      headerIdx = i;
      break;
    }
  }

  const dataStart = headerIdx >= 0 ? headerIdx + 1 : 0;
  const headers = headerIdx >= 0 ? rows[headerIdx].map(c => String(c ?? '').toLowerCase().trim()) : [];

  // Ищем столбец ресторана по заголовку
  let restCol = -1;
  if (headers.length > 0) {
    for (let c = 0; c < headers.length; c++) {
      const h = headers[c];
      if (h.includes('ресторан') || h.includes('склад') || h.includes('точка') || h.includes('объект')) {
        restCol = c;
        break;
      }
    }
  }

  // Если не нашли по заголовку — ищем по данным (где extractRestaurantNumber срабатывает)
  if (restCol === -1) {
    const sampleRows = rows.slice(dataStart, dataStart + 10);
    const maxCols = Math.max(...sampleRows.map(r => r.length), 0);
    let bestCol = -1;
    let bestCount = 0;
    for (let c = 0; c < maxCols; c++) {
      let count = 0;
      for (const row of sampleRows) {
        if (extractRestaurantNumber(row[c])) count++;
      }
      if (count > bestCount) { bestCount = count; bestCol = c; }
    }
    if (bestCount > 0) restCol = bestCol;
  }

  return { headerIdx, restCol, dataStart };
}

/**
 * Извлечь данные из строк.
 * @param {Array} rows — массив строк
 * @param {number} [forceValCol] — принудительно использовать этот столбец для значения
 */
function extractRestaurantData(rows, forceValCol) {
  if (!rows || rows.length < 1) return [];

  const { headerIdx, restCol, dataStart } = detectLayout(rows);
  if (restCol === -1) return [];

  const headers = headerIdx >= 0 ? rows[headerIdx].map(c => String(c ?? '').toLowerCase().trim()) : [];

  let valCol = typeof forceValCol === 'number' ? forceValCol : -1;

  // Автоопределение столбца значения (если не задан)
  if (valCol === -1 && headers.length > 0) {
    const valueKeywords = ['остаток', 'расход', 'количество тмц', 'кол-во', 'количество', 'значение'];
    for (let c = 0; c < headers.length; c++) {
      if (c === restCol) continue;
      const h = headers[c];
      if (valueKeywords.some(kw => h.includes(kw))) {
        valCol = c;
        break;
      }
    }
  }

  // Если не нашли по ключевым словам — ищем первый числовой столбец (кроме timestamp)
  if (valCol === -1) {
    const sampleRows = rows.slice(dataStart, dataStart + 10);
    const maxCols = Math.max(...sampleRows.map(r => r.length), 0);
    for (let c = 0; c < maxCols; c++) {
      if (c === restCol) continue;
      // Пропускаем timestamp (серийный номер > 40000)
      const firstVal = sampleRows[0]?.[c];
      if (typeof firstVal === 'number' && firstVal > 40000 && firstVal < 60000) continue;

      let numCount = 0;
      for (const row of sampleRows) {
        const v = row[c];
        if (typeof v === 'number') numCount++;
        else if (v && /^\d+[\d.,]*$/.test(String(v).trim())) numCount++;
      }
      if (numCount >= sampleRows.length * 0.5) {
        valCol = c;
        break;
      }
    }
  }

  // Fallback: если столбцов ровно 2
  const maxCols = Math.max(...rows.slice(dataStart, dataStart + 5).map(r => r.length), 0);
  if (valCol === -1 && maxCols === 2) {
    valCol = restCol === 0 ? 1 : 0;
  }

  if (valCol === -1) return [];

  const result = [];
  for (let i = dataStart; i < rows.length; i++) {
    const row = rows[i];
    if (!row || row.length < 2) continue;

    const restNum = extractRestaurantNumber(row[restCol]);
    if (!restNum) continue;

    const value = parseNum(row[valCol]);

    result.push({
      restaurantNumber: restNum,
      value,
    });
  }

  return result;
}

/**
 * Объединить данные из файла с остатками из публичной формы.
 * Приоритет: данные из формы перезаписывают данные из файла.
 */
export function mergeStockSources(fileData, formData) {
  const map = new Map();

  for (const entry of (fileData || [])) {
    map.set(entry.restaurantNumber, entry.value);
  }

  for (const entry of (formData || [])) {
    map.set(String(entry.restaurant_number), entry.stock);
  }

  return [...map.entries()].map(([restaurantNumber, value]) => ({
    restaurantNumber,
    value,
  }));
}
