/**
 * reconcile1cUt.js
 * Сверка движений товара между выгрузками 1С (БУХ) и 1С УТ.
 * Всё парсится в браузере через xlsx-js-style, бэкенд не нужен.
 *
 * Логика сверки:
 *  - Перемещения сверяются ПО НОМЕРУ (обе системы ссылаются на один номер).
 *  - Прочие документы (поступления, возвраты, списания) по номеру не сопоставимы
 *    (в 1С номера 001-…, в УТ 00UT-…), поэтому сверяются ПО ИТОГАМ, сгруппированным
 *    по типу. Типы в системах называются по-разному и приводятся к общему через
 *    normalizeDocType (напр. «Поступление ТМЦ» = «Приобретение товаров и услуг»).
 *  - Учитывается начальный остаток: конечный = начальный + приход − расход, а
 *    «расхождение остатка» = конечный УТ − конечный 1С (как «Количество (расх)»
 *    в файле расхождений).
 */

// ─── Приватные вспомогательные функции ────────────────────────────────────────

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

function parseNum(val) {
  if (val == null || val === '') return 0;
  const n = parseFloat(String(val).replace(/\s/g, '').replace(',', '.'));
  return isNaN(n) ? 0 : Math.round(n * 100) / 100;
}

const round2 = n => Math.round(n * 100) / 100;

function extractMoveNumber(text) {
  // Номер документа = токен прямо перед « от <дата>». Работает для любого
  // формата: «001-4819019010001-604660», короткого «0333836», «00UT-…».
  const s = String(text == null ? '' : text);
  const m = s.match(/(\S+)\s+от\s/);
  if (m) return m[1];
  const m2 = s.match(/\d{3}-\d+-\d+/) || s.match(/00UT-\S+/);
  return m2 ? m2[0] : null;
}

// Дата документа из « от DD.MM.YYYY [ЧЧ:ММ:СС] ». ВНИМАНИЕ: « от » с пробелами —
// `\bот` не работает с кириллицей (в JS `\b` только для ASCII).
function extractDate(text) {
  const m = String(text == null ? '' : text).match(/ от\s+(\d{2}\.\d{2}\.\d{4})(?:\s+(\d{1,2}:\d{2}(?::\d{2})?))?/);
  return m ? { date: m[1], time: m[2] || '' } : { date: '', time: '' };
}

// Тип документа = текст до номера/даты («Перемещение (розница, общепит)»,
// «Поступление ТМЦ», «Возврат товаров поставщику» …).
function docTypeFromText(text) {
  const s = String(text == null ? '' : text).trim();
  return s.replace(/\s+\S*\d.*$/, '').trim() || s;
}

// Приведение типов документов 1С и УТ к общей категории (разные названия —
// один смысл). Чтобы прочие документы сверялись «тип к типу».
const DOC_CANON = [
  { canon: 'Перемещение',            rx: [/перемещени/i] },
  { canon: 'Поступление / Приобретение', rx: [/поступлени/i, /приобретени/i] },
  { canon: 'Возврат поставщику',     rx: [/возврат.*поставщ/i] },
  { canon: 'Возврат от клиента',     rx: [/возврат.*(клиент|покупател)/i] },
  { canon: 'Реализация / Продажа',   rx: [/реализаци/i, /продаж/i] },
  { canon: 'Списание',               rx: [/списани/i] },
  { canon: 'Оприходование',          rx: [/оприходов/i] },
  { canon: 'Инвентаризация',         rx: [/инвентар/i] },
  { canon: 'Корректировка',          rx: [/корректир/i] },
  { canon: 'Сборка / Разборка',      rx: [/сборк|разбор/i] },
];
function normalizeDocType(type) {
  const s = String(type || '');
  for (const e of DOC_CANON) if (e.rx.some(rx => rx.test(s))) return e.canon;
  return s.trim() || '(без типа)';
}

// Период отчёта из шапки: «с 01.04.2026 по 30.04.2026» или «01.04.2026 - 30.04.2026».
function findPeriod(rows) {
  for (const r of rows.slice(0, 8)) {
    for (const c of r) {
      const s = String(c);
      let m = s.match(/(\d{2}\.\d{2}\.\d{4})\s*(?:по|[-–])\s*(\d{2}\.\d{2}\.\d{4})/i);
      if (m) return `${m[1]} — ${m[2]}`;
    }
  }
  return '';
}

async function loadXLSX() {
  const mod = await import('xlsx-js-style');
  return mod.read ? mod : (mod.default || mod);
}

async function readRows(file) {
  const XLSX = await loadXLSX();
  const buffer = await file.arrayBuffer();
  const wb = XLSX.read(buffer, { type: 'array', cellDates: true });
  const ws = wb.Sheets[wb.SheetNames[0]];
  return { rows: getSheetRows(XLSX, ws), XLSX };
}

// ─── 1. Парсинг файла расхождений ────────────────────────────────────────────

/**
 * Парсит выгрузку «расхождений» из УТ.
 * @returns {Promise<{ok:boolean, items?:Array, error?:string}>}
 * items: [{ sku, name, unit, qtyUt, qtyBuh, diff }]
 */
export async function parseRashozhdeniya(file) {
  try {
    const { rows } = await readRows(file);

    const hasHeader = rows.slice(0, 6).some(r =>
      r.some(cell => String(cell).includes('Количество (расх)'))
    );
    if (!hasHeader) {
      return { ok: false, error: 'Не похоже на файл расхождений (нет колонки «Количество (расх)»)' };
    }

    // Имя склада из строки «Отбор: … Склад Равно "…"» — чтобы исключить строку-итог склада.
    let warehouseName = '';
    for (const r of rows.slice(0, 6)) {
      for (const c of r) {
        const m = String(c).match(/Склад\s+Равно\s+[«"]?([^»"]+)/i);
        if (m) { warehouseName = m[1].trim(); break; }
      }
      if (warehouseName) break;
    }

    const isTotalRow = (a) => {
      const t = a.trim();
      if (!t) return true;
      if (/^(итого|склад|номенклатура|ед изм|ед\. изм)$/i.test(t)) return true;
      if (/^распределительный центр/i.test(t)) return true;
      if (warehouseName && t === warehouseName) return true;
      return false;
    };

    const items = [];
    for (const row of rows) {
      const cellA = String(row[0] ?? '');
      const cellH = row[7];

      if (isTotalRow(cellA)) continue;
      if (cellH === '' || cellH == null) continue;
      const diff = parseNum(cellH);
      if (diff === 0) continue;

      const firstToken = cellA.trim().split(/\s+/)[0] ?? '';
      const spaceIdx = cellA.indexOf(' ');
      const name = spaceIdx >= 0 ? cellA.slice(spaceIdx + 1).trim() : '';

      items.push({
        sku: firstToken,
        name,
        unit:   String(row[4] ?? '').trim(),
        qtyUt:  parseNum(row[5]),
        qtyBuh: parseNum(row[6]),
        diff,
      });
    }

    return { ok: true, items };
  } catch (e) {
    return { ok: false, error: `Ошибка чтения файла расхождений: ${e.message}` };
  }
}

// ─── 2. Парсинг движений 1С (БУХ) ────────────────────────────────────────────

/**
 * «Расшифровка движений ТМЦ» из 1С. Документ — ячейка с « от ».
 * Приход = Дебет Кол-во (+1), расход = Кредит Кол-во (+3).
 * Начальный остаток — строка «Сальдо на начало периода», Дебет Кол-во (кол. 3).
 */
export async function parse1cMovements(file) {
  try {
    const { rows } = await readRows(file);

    const hasHeader = rows.slice(0, 8).some(r =>
      r.some(cell => String(cell).includes('Кредит') || String(cell).includes('Расшифровка движений'))
    );
    if (!hasHeader) return { ok: false, error: 'Не похоже на выгрузку движений 1С' };

    const peremescheniya = new Map();
    const others = [];
    let totalPrihod = 0, totalRashod = 0, nachalo = 0;

    for (const row of rows) {
      // начальный остаток
      if (row.some(v => /Сальдо на начало/i.test(String(v ?? '')))) {
        nachalo = parseNum(row[3]); // Дебет Кол-во
        continue;
      }

      const docIdx = row.findIndex(v => / от /.test(String(v ?? '')));
      if (docIdx < 0) continue;

      const docText = String(row[docIdx]);
      const prihod = parseNum(row[docIdx + 1]); // Дебет Кол-во
      const rashod = parseNum(row[docIdx + 3]); // Кредит Кол-во
      if (prihod === 0 && rashod === 0) continue;

      totalPrihod += prihod;
      totalRashod += rashod;

      const number = extractMoveNumber(docText);
      const dt = extractDate(docText);
      if (/Перемещени/i.test(docText) && number) {
        const cur = peremescheniya.get(number) ?? { prihod: 0, rashod: 0, date: dt.date, time: dt.time };
        cur.prihod += prihod; cur.rashod += rashod;
        if (!cur.date) { cur.date = dt.date; cur.time = dt.time; }
        peremescheniya.set(number, cur);
      } else {
        others.push({ type: docTypeFromText(docText), number: number || '', prihod, rashod, date: dt.date });
      }
    }
    for (const v of peremescheniya.values()) { v.prihod = round2(v.prihod); v.rashod = round2(v.rashod); }

    if (peremescheniya.size === 0 && others.length === 0) {
      return { ok: false, error: 'Не похоже на выгрузку движений 1С (документы не найдены)' };
    }

    return {
      ok: true, peremescheniya, others, period: findPeriod(rows),
      totals: { prihod: round2(totalPrihod), rashod: round2(totalRashod), nachalo: round2(nachalo) },
    };
  } catch (e) {
    return { ok: false, error: `Ошибка чтения файла 1С: ${e.message}` };
  }
}

// ─── 3. Парсинг движений УТ ──────────────────────────────────────────────────

/**
 * «Ведомость по товарам на складах» из УТ. Документ = колонка A с « от ».
 * Приход = [14], Расход = [15], Начальный остаток = [12] (первая строка-итог).
 */
export async function parseUtMovements(file) {
  try {
    const { rows } = await readRows(file);

    const hasHeader = rows.slice(0, 10).some(r =>
      r.some(cell => String(cell).includes('Ведомость по товарам') || String(cell).includes('Расход'))
    );
    if (!hasHeader) return { ok: false, error: 'Не похоже на выгрузку движений УТ' };

    const peremescheniya = new Map();
    const others = [];
    let totalPrihod = 0, totalRashod = 0, nachalo = null;

    for (const row of rows) {
      const docText = String(row[0] ?? '');

      // Начальный остаток — первая строка-итог (склад/номенклатура), не документ.
      if (nachalo === null && !/ от /.test(docText) && typeof row[12] === 'number') {
        nachalo = parseNum(row[12]);
      }

      if (!/ от /.test(docText)) continue; // только документы

      const prihod = parseNum(row[14]);
      const rashod = parseNum(row[15]);
      if (prihod === 0 && rashod === 0) continue;

      totalPrihod += prihod;
      totalRashod += rashod;

      const number = extractMoveNumber(docText);
      const dt = extractDate(docText);
      if (/Перемещени/i.test(docText) && number) {
        const cur = peremescheniya.get(number) ?? { prihod: 0, rashod: 0, date: dt.date, time: dt.time };
        cur.prihod += prihod; cur.rashod += rashod;
        if (!cur.date) { cur.date = dt.date; cur.time = dt.time; }
        peremescheniya.set(number, cur);
      } else {
        others.push({ type: docTypeFromText(docText), number: number || '', prihod, rashod, date: dt.date });
      }
    }
    for (const v of peremescheniya.values()) { v.prihod = round2(v.prihod); v.rashod = round2(v.rashod); }

    if (peremescheniya.size === 0 && others.length === 0) {
      return { ok: false, error: 'Не похоже на выгрузку движений УТ (документы не найдены)' };
    }

    return {
      ok: true, peremescheniya, others, period: findPeriod(rows),
      totals: { prihod: round2(totalPrihod), rashod: round2(totalRashod), nachalo: round2(nachalo || 0) },
    };
  } catch (e) {
    return { ok: false, error: `Ошибка чтения файла УТ: ${e.message}` };
  }
}

// ─── 4. Сравнение ─────────────────────────────────────────────────────────────

export function compareMovements(p1c, pUt) {
  const EPS = 0.005;
  const m1 = p1c.peremescheniya, m2 = pUt.peremescheniya;
  const all = new Set([...m1.keys(), ...m2.keys()]);

  const matched = [], qtyDiff = [], onlyIn1c = [], onlyInUt = [], dateMismatch = [];
  for (const number of all) {
    const a = m1.get(number), b = m2.get(number);
    if (a && b) {
      const dDiff = !!(a.date && b.date && a.date !== b.date); // разный день
      if (Math.abs(a.prihod - b.prihod) < EPS && Math.abs(a.rashod - b.rashod) < EPS) {
        matched.push({ number, prihod: a.prihod, rashod: a.rashod, date1c: a.date, dateUt: b.date, dateDiff: dDiff });
        if (dDiff) dateMismatch.push({ number, prihod: a.prihod, rashod: a.rashod, date1c: a.date, dateUt: b.date });
      } else {
        qtyDiff.push({
          number,
          prihod1c: a.prihod, rashod1c: a.rashod, prihodUt: b.prihod, rashodUt: b.rashod,
          diffPrihod: round2(a.prihod - b.prihod), diffRashod: round2(a.rashod - b.rashod),
          date1c: a.date, dateUt: b.date, dateDiff: dDiff,
        });
      }
    } else if (a) {
      onlyIn1c.push({ number, prihod: a.prihod, rashod: a.rashod, date: a.date, likelySame: false });
    } else {
      onlyInUt.push({ number, prihod: b.prihod, rashod: b.rashod, date: b.date, likelySame: false });
    }
  }
  // Пометка «вероятно тот же документ, другой номер»: одинаковые приход+расход в
  // «только в 1С» и «только в УТ» — почти наверняка одно перемещение с разной нумерацией.
  for (const a of onlyIn1c) {
    const m = onlyInUt.find(b => !b.likelySame && Math.abs(a.prihod - b.prihod) < EPS && Math.abs(a.rashod - b.rashod) < EPS);
    if (m) { a.likelySame = true; m.likelySame = true; }
  }
  const byNum = (x, y) => String(x.number).localeCompare(String(y.number));
  [matched, qtyDiff, onlyIn1c, onlyInUt, dateMismatch].forEach(a => a.sort(byNum));

  // прочие документы — по итогам, сгруппированы по общей категории (1С↔УТ)
  const groupByCanon = list => {
    const m = new Map();
    for (const d of list) {
      const cat = normalizeDocType(d.type);
      const cur = m.get(cat) ?? { prihod: 0, rashod: 0 };
      cur.prihod += d.prihod; cur.rashod += d.rashod;
      m.set(cat, cur);
    }
    return m;
  };
  const g1 = groupByCanon(p1c.others), g2 = groupByCanon(pUt.others);
  const cats = new Set([...g1.keys(), ...g2.keys()]);
  const byCategory = [...cats].map(category => {
    const a = g1.get(category) ?? { prihod: 0, rashod: 0 };
    const b = g2.get(category) ?? { prihod: 0, rashod: 0 };
    return {
      category,
      prihod1c: round2(a.prihod), rashod1c: round2(a.rashod),
      prihodUt: round2(b.prihod), rashodUt: round2(b.rashod),
      diffPrihod: round2(a.prihod - b.prihod), diffRashod: round2(a.rashod - b.rashod),
    };
  }).sort((x, y) => x.category.localeCompare(y.category));

  const sumList = list => list.reduce((s, d) => ({ prihod: s.prihod + d.prihod, rashod: s.rashod + d.rashod }), { prihod: 0, rashod: 0 });
  const o1 = sumList(p1c.others), o2 = sumList(pUt.others);
  const others = {
    byCategory,
    list1c: p1c.others, listUt: pUt.others,
    total1c: { prihod: round2(o1.prihod), rashod: round2(o1.rashod) },
    totalUt: { prihod: round2(o2.prihod), rashod: round2(o2.rashod) },
    diffPrihod: round2(o1.prihod - o2.prihod),
    diffRashod: round2(o1.rashod - o2.rashod),
  };

  const n1 = p1c.totals.nachalo ?? 0, n2 = pUt.totals.nachalo ?? 0;
  const konec1c = round2(n1 + p1c.totals.prihod - p1c.totals.rashod);
  const konecUt = round2(n2 + pUt.totals.prihod - pUt.totals.rashod);
  const totals = {
    nachalo1c: n1, nachaloUt: n2, nachaloDiff: round2(n2 - n1),
    prihod1c: p1c.totals.prihod, rashod1c: p1c.totals.rashod,
    prihodUt: pUt.totals.prihod, rashodUt: pUt.totals.rashod,
    konec1c, konecUt,
    diffPrihod: round2(p1c.totals.prihod - pUt.totals.prihod),
    diffRashod: round2(p1c.totals.rashod - pUt.totals.rashod),
    // расхождение остатка (УТ − 1С) по конечным — как «Количество (расх)» в файле расхождений
    ostatokDiff: round2(konecUt - konec1c),
  };

  const counts = {
    matched: matched.length, qtyDiff: qtyDiff.length,
    onlyIn1c: onlyIn1c.length, onlyInUt: onlyInUt.length,
    dateDiff: dateMismatch.length, total: all.size,
  };

  return { moves: { matched, qtyDiff, onlyIn1c, onlyInUt, dateMismatch, counts }, others, totals, period: p1c.period || pUt.period || '' };
}

// ─── 5. Экспорт в Excel (3 листа) ────────────────────────────────────────────

const ST = {
  title:   { font: { bold: true, sz: 14 } },
  sub:     { font: { italic: true, color: { rgb: '8C7B6E' } } },
  h2:      { font: { bold: true, sz: 11 }, fill: { fgColor: { rgb: 'F2E9DC' } } },
  head:    { font: { bold: true, color: { rgb: 'FFFFFF' } }, fill: { fgColor: { rgb: '502314' } }, alignment: { horizontal: 'center' } },
  bad:     { fill: { fgColor: { rgb: 'FCE3DC' } } },
  warn:    { fill: { fgColor: { rgb: 'FFF1D6' } } },
  ok:      { fill: { fgColor: { rgb: 'E7F6E9' } } },
  bold:    { font: { bold: true } },
};
const cell = (v, s) => (s ? { v, s } : { v });
const sign = n => (n > 0 ? `+${n}` : `${n}`);

export async function exportReconcileExcel(productName, compareResult) {
  const XLSX = await loadXLSX();
  const { moves, others, totals, period } = compareResult;
  const wb = XLSX.utils.book_new();

  // ── Лист 1: Итог ──
  const itog = [
    [cell(productName, ST.title)],
    [cell(period ? `Период: ${period}` : '', ST.sub)],
    [''],
    [cell('Остатки и обороты', ST.h2)],
    [cell('Показатель', ST.head), cell('1С', ST.head), cell('УТ', ST.head), cell('Разница (1С−УТ)', ST.head)],
    [cell('Начальный остаток', ST.bold), totals.nachalo1c, totals.nachaloUt, cell(sign(totals.nachaloDiff), totals.nachaloDiff ? ST.bad : null)],
    [cell('Приход', ST.bold), totals.prihod1c, totals.prihodUt, cell(sign(totals.diffPrihod), totals.diffPrihod ? ST.bad : null)],
    [cell('Расход', ST.bold), totals.rashod1c, totals.rashodUt, cell(sign(totals.diffRashod), totals.diffRashod ? ST.bad : null)],
    [cell('Конечный остаток', ST.bold), totals.konec1c, totals.konecUt, cell(sign(round2(totals.konec1c - totals.konecUt)), (totals.konec1c - totals.konecUt) ? ST.bad : null)],
    [cell('Расхождение остатка (УТ−1С)', ST.bold), '', '', cell(sign(totals.ostatokDiff), totals.ostatokDiff ? ST.bad : ST.ok)],
    [''],
    [cell('Перемещения (по номеру)', ST.h2)],
    [cell('Совпало', ST.bold), moves.counts.matched],
    [cell('Разное количество', ST.bold), cell(moves.counts.qtyDiff, moves.counts.qtyDiff ? ST.warn : null)],
    [cell('Только в 1С', ST.bold), cell(moves.counts.onlyIn1c, moves.counts.onlyIn1c ? ST.bad : null)],
    [cell('Только в УТ', ST.bold), cell(moves.counts.onlyInUt, moves.counts.onlyInUt ? ST.bad : null)],
    [cell('Совпало по кол-ву, но разная дата', ST.bold), cell(moves.counts.dateDiff, moves.counts.dateDiff ? ST.warn : null)],
    [''],
    [cell('Прочие документы по типам', ST.h2)],
    [cell('Тип', ST.head), cell('Приход 1С', ST.head), cell('Расход 1С', ST.head), cell('Приход УТ', ST.head), cell('Расход УТ', ST.head), cell('Разн. расх.', ST.head)],
  ];
  for (const c of others.byCategory) {
    const st = c.diffPrihod || c.diffRashod ? ST.bad : null;
    itog.push([cell(c.category, st), c.prihod1c, c.rashod1c, c.prihodUt, c.rashodUt, cell(sign(c.diffRashod), st)]);
  }
  const wsItog = XLSX.utils.aoa_to_sheet(itog);
  wsItog['!cols'] = [{ wch: 34 }, { wch: 13 }, { wch: 13 }, { wch: 13 }, { wch: 13 }, { wch: 13 }];
  XLSX.utils.book_append_sheet(wb, wsItog, 'Итог');

  // ── Лист 2: Перемещения ──
  const comm = r => (r.dateDiff ? 'дата 1С/УТ отличается' : '');
  const perem = [
    [cell('Номер перемещения', ST.head), cell('Статус', ST.head), cell('Приход 1С', ST.head), cell('Расход 1С', ST.head), cell('Приход УТ', ST.head), cell('Расход УТ', ST.head), cell('Разница расхода', ST.head), cell('Дата 1С', ST.head), cell('Дата УТ', ST.head), cell('Комментарий', ST.head)],
  ];
  for (const r of moves.matched)  perem.push([r.number, 'совпало', r.prihod, r.rashod, r.prihod, r.rashod, 0, cell(r.date1c, r.dateDiff ? ST.warn : null), cell(r.dateUt, r.dateDiff ? ST.warn : null), cell(comm(r), r.dateDiff ? ST.warn : null)]);
  for (const r of moves.qtyDiff)  perem.push([cell(r.number, ST.warn), cell('разное количество', ST.warn), r.prihod1c, r.rashod1c, r.prihodUt, r.rashodUt, cell(r.diffRashod, ST.warn), r.date1c, r.dateUt, comm(r)]);
  for (const r of moves.onlyIn1c) perem.push([cell(r.number, ST.bad), cell(r.likelySame ? 'только в 1С (вероятно тот же, другой №)' : 'только в 1С', ST.bad), r.prihod, r.rashod, '', '', cell(r.rashod, ST.bad), r.date, '', '']);
  for (const r of moves.onlyInUt) perem.push([cell(r.number, ST.bad), cell(r.likelySame ? 'только в УТ (вероятно тот же, другой №)' : 'только в УТ', ST.bad), '', '', r.prihod, r.rashod, cell(-r.rashod, ST.bad), '', r.date, '']);
  const wsPerem = XLSX.utils.aoa_to_sheet(perem);
  wsPerem['!cols'] = [{ wch: 30 }, { wch: 34 }, { wch: 10 }, { wch: 10 }, { wch: 10 }, { wch: 10 }, { wch: 14 }, { wch: 12 }, { wch: 12 }, { wch: 22 }];
  XLSX.utils.book_append_sheet(wb, wsPerem, 'Перемещения');

  // ── Лист 3: Прочие документы (детально) ──
  const proch = [
    [cell('Система', ST.head), cell('Тип документа', ST.head), cell('Номер', ST.head), cell('Дата', ST.head), cell('Приход', ST.head), cell('Расход', ST.head)],
  ];
  for (const d of others.list1c) proch.push(['1С', d.type, d.number, d.date || '', d.prihod, d.rashod]);
  for (const d of others.listUt) proch.push(['УТ', d.type, d.number, d.date || '', d.prihod, d.rashod]);
  const wsProch = XLSX.utils.aoa_to_sheet(proch);
  wsProch['!cols'] = [{ wch: 8 }, { wch: 34 }, { wch: 32 }, { wch: 12 }, { wch: 11 }, { wch: 11 }];
  XLSX.utils.book_append_sheet(wb, wsProch, 'Прочие документы');

  const safeName = String(productName).replace(/[\\/:*?"<>|]/g, '_').slice(0, 80);
  await writeXlsxFrozen(XLSX, wb, `Сверка ${safeName}.xlsx`);
}

/**
 * Пишет xlsx с закреплённой первой строкой (шапкой) на листах данных
 * (всех, кроме «Итог» — там шапка не в первой строке).
 * xlsx-js-style сам не умеет freeze panes, поэтому дописываем <pane> прямо в
 * готовый файл через fflate. При любой ошибке откатываемся на обычное скачивание.
 */
async function writeXlsxFrozen(XLSX, wb, filename) {
  try {
    const fflate = await import('fflate');
    const buf = XLSX.write(wb, { bookType: 'xlsx', type: 'array' });
    const files = fflate.unzipSync(new Uint8Array(buf));
    const FROZEN = '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
                 + '<selection pane="bottomLeft" activeCell="A2" sqref="A2"/>';
    for (const path of Object.keys(files)) {
      const m = path.match(/^xl\/worksheets\/sheet(\d+)\.xml$/);
      if (!m || m[1] === '1') continue; // лист 1 = «Итог»
      let xml = fflate.strFromU8(files[path]);
      xml = xml.replace(/<sheetView([^>]*)\/>/, `<sheetView$1>${FROZEN}</sheetView>`);
      files[path] = fflate.strToU8(xml);
    }
    const zipped = fflate.zipSync(files);
    const blob = new Blob([zipped], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  } catch (e) {
    XLSX.writeFile(wb, filename); // запасной путь без заморозки
  }
}
