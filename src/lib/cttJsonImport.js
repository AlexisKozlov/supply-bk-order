import { getEntityGroupCode } from './legalEntities.js';

function normalizeSku(value) {
  return String(value ?? '').trim();
}

function normalizeGtin(value) {
  return String(value ?? '').replace(/\s+/g, '').trim();
}

function normalizeHeader(value) {
  return String(value ?? '').toLowerCase().replace(/\s+/g, ' ').trim();
}

function normalizeQty(value) {
  const raw = String(value ?? '').replace(',', '.').trim();
  const num = Number(raw);
  if (!Number.isFinite(num) || num <= 0) return '';
  return String(num).replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
}

function normalizePrice(value) {
  const raw = String(value ?? '').replace(/\s+/g, '').replace(',', '.').trim();
  const num = Number(raw);
  if (!Number.isFinite(num) || num < 0) return '';
  return Number(num.toFixed(4));
}

function normalizeWeightTons(value) {
  const raw = String(value ?? '').replace(/\s+/g, '').replace(',', '.').trim();
  const num = Number(raw);
  if (!Number.isFinite(num) || num <= 0) return '';
  return num.toFixed(6).replace(/\.?0+$/, '');
}

function normalizeStorage(value) {
  const v = String(value ?? '').trim().toLowerCase();
  if (!v) return 'Сухой';
  if (v.includes('мороз')) return 'Мороз';
  if (v.includes('холод')) return 'Холод';
  if (v.includes('сух')) return 'Сухой';
  return String(value ?? '').trim() || 'Сухой';
}

function formatCttWeight(weightBruttoGrams) {
  const tons = Number(weightBruttoGrams || 0) / 1000000;
  if (!Number.isFinite(tons) || tons <= 0) return '0';
  return tons.toFixed(6).replace(/\.?0+$/, '');
}

function sanitizeFileBase(name) {
  return String(name || 'preorder')
    .replace(/\.[^.]+$/, '')
    .trim();
}

function findCol(headers, keywords) {
  return headers.findIndex(header => keywords.some(keyword => header.includes(keyword)));
}

function findCttHeaderRow(rows) {
  for (let i = 0; i < Math.min(rows.length, 40); i++) {
    const headers = (rows[i] || []).map(normalizeHeader);
    const hasTtn = findCol(headers, ['номер ттн', 'ттн']) >= 0;
    const hasGtin = findCol(headers, ['штри', 'gtin', 'штрих']) >= 0;
    const hasQty = findCol(headers, ['кол-во', 'количество', 'qty']) >= 0;
    const hasPrice = findCol(headers, ['цена в валюте', 'цена']) >= 0;
    if (hasTtn && hasGtin && hasQty && hasPrice) return i;
  }
  return -1;
}

function buildCttColumnMap(headerRow) {
  const headers = (headerRow || []).map(normalizeHeader);
  return {
    ttn: findCol(headers, ['номер ттн', 'ттн']),
    gtin: findCol(headers, ['штри', 'gtin', 'штрих']),
    quantity: findCol(headers, ['кол-во', 'количество', 'qty']),
    storage: findCol(headers, ['склад']),
    price: findCol(headers, ['цена в валюте', 'цена']),
    weight: findCol(headers, ['брутто', 'вес брутто', 'gross']),
  };
}

function extractCttRows(rows) {
  const headerIdx = findCttHeaderRow(rows);
  if (headerIdx < 0) return [];
  const col = buildCttColumnMap(rows[headerIdx]);
  const result = [];

  for (let i = headerIdx + 1; i < rows.length; i++) {
    const row = rows[i] || [];
    const ttn = String(row[col.ttn] ?? '').trim();
    const gtin = normalizeGtin(row[col.gtin]);
    const qty = normalizeQty(row[col.quantity]);
    const price = normalizePrice(row[col.price]);
    const weight = col.weight >= 0 ? normalizeWeightTons(row[col.weight]) : '';
    if (!ttn && !gtin && !qty) continue;
    if (!ttn || !gtin || !qty) {
      result.push({
        ttn,
        gtin,
        quantity: qty,
        price,
        weight,
        storage: col.storage >= 0 ? String(row[col.storage] ?? '').trim() : '',
        _invalid: true,
        _reason: !ttn ? 'Не указан номер ТТН' : (!gtin ? 'Не указан GTIN' : 'Не указано количество'),
      });
      continue;
    }
    result.push({
      ttn,
      gtin,
      quantity: qty,
      price,
      weight,
      storage: col.storage >= 0 ? String(row[col.storage] ?? '').trim() : '',
    });
  }

  return result;
}

function extractLegacyRows(rows) {
  const result = [];
  for (const row of rows) {
    const sku = normalizeSku(row?.[0]);
    const name = String(row?.[2] ?? '').trim();
    const gtin = normalizeGtin(row?.[3]);
    const qty = normalizeQty(row?.[4]);
    if (!sku || !name || !qty) continue;
    result.push({
      sku,
      source_name: name,
      gtin,
      quantity: qty,
      unit: String(row?.[5] ?? '').trim(),
    });
  }
  return result;
}

function aggregateSourceRows(rows) {
  const aggregated = new Map();
  for (const row of rows) {
    if (row._invalid) {
      aggregated.set(`invalid|${aggregated.size}`, { ...row });
      continue;
    }
    const key = `${row.ttn || ''}|${row.gtin || ''}|${row.sku || ''}|${row.price ?? ''}`;
    const existing = aggregated.get(key);
    if (!existing) {
      aggregated.set(key, { ...row });
      continue;
    }
    const sum = Number(existing.quantity || 0) + Number(row.quantity || 0);
    existing.quantity = normalizeQty(sum);
  }
  return Array.from(aggregated.values());
}

export async function parseCttPreorderXlsx(file) {
  const XLSXModule = await import('xlsx-js-style');
  const XLSX = XLSXModule.default || XLSXModule;
  const buffer = await file.arrayBuffer();
  const workbook = XLSX.read(buffer, { type: 'array' });
  if (!workbook.SheetNames.length) throw new Error('В файле нет листов');

  let sheetName = '';
  let parsed = [];
  let bestValidCount = 0;
  let parsedFromCttSheet = false;
  for (const name of workbook.SheetNames) {
    const rows = XLSX.utils.sheet_to_json(workbook.Sheets[name], { header: 1, defval: '' });
    const cttRows = extractCttRows(rows);
    const validCount = cttRows.filter(row => !row._invalid).length;
    const isBetter = validCount > bestValidCount
      || (validCount === bestValidCount && validCount > 0 && normalizeHeader(name).includes('лист3'));
    if (isBetter) {
      sheetName = name;
      parsed = cttRows;
      bestValidCount = validCount;
      parsedFromCttSheet = true;
    }
  }

  if (!parsed.length) {
    sheetName = workbook.SheetNames[0];
    const rows = XLSX.utils.sheet_to_json(workbook.Sheets[sheetName], { header: 1, defval: '' });
    parsed = extractLegacyRows(rows);
    parsedFromCttSheet = false;
  }

  if (!parsed.length) {
    throw new Error('Не удалось распознать строки. Ожидаю лист с колонками: Номер ТТН, Штри/GTIN, Кол-во, Цена');
  }
  return {
    sheetName,
    rows: parsedFromCttSheet ? parsed : aggregateSourceRows(parsed),
  };
}

export async function resolveCttPreorderRows({ rows, legalEntity, db, preorderLabel = 'Предзаказ' }) {
  if (!legalEntity) throw new Error('Выберите юр. лицо в боковом меню');
  if (!rows?.length) return { items: [], unmatched: [], stats: { parsed: 0, converted: 0, unmatched: 0, missing_price: 0, missing_weight: 0 } };

  const validRows = [];
  const unmatched = [];
  for (const row of rows) {
    if (row._invalid) unmatched.push({ ...row, reason: row._reason || 'Строка заполнена не полностью' });
    else validRows.push(row);
  }

  const rowsNeedProduct = validRows.filter(row => !row.weight);
  const gtins = [...new Set(rowsNeedProduct.map(r => r.gtin).filter(Boolean))];
  const skus = [...new Set(rowsNeedProduct.map(r => r.sku).filter(Boolean))];

  const productMapByGtin = new Map();
  const productMapBySku = new Map();

  if (gtins.length) {
    const { data, error } = await db
      .from('products')
      .select('sku,name,gtin,category,weight_brutto,legal_entity')
      .eq('legal_entity', legalEntity)
      .eq('is_active', 1)
      .in('gtin', gtins);
    if (error) throw new Error(error);
    for (const row of data || []) {
      const gtin = normalizeGtin(row.gtin);
      const sku = normalizeSku(row.sku);
      if (gtin && !productMapByGtin.has(gtin)) productMapByGtin.set(gtin, row);
      if (sku && !productMapBySku.has(sku)) productMapBySku.set(sku, row);
    }
  }

  if (skus.length) {
    const missingSkus = skus.filter(sku => !productMapBySku.has(sku));
    if (missingSkus.length) {
      const { data, error } = await db
        .from('products')
        .select('sku,name,gtin,category,weight_brutto,legal_entity')
        .eq('legal_entity', legalEntity)
        .eq('is_active', 1)
        .in('sku', missingSkus);
      if (error) throw new Error(error);
      for (const row of data || []) {
        const gtin = normalizeGtin(row.gtin);
        const sku = normalizeSku(row.sku);
        if (gtin && !productMapByGtin.has(gtin)) productMapByGtin.set(gtin, row);
        if (sku && !productMapBySku.has(sku)) productMapBySku.set(sku, row);
      }
    }
  }

  const matchedSkus = [...new Set(
    rowsNeedProduct
      .map(row => {
        const product = (row.gtin && productMapByGtin.get(row.gtin)) || productMapBySku.get(row.sku);
        return product?.sku ? normalizeSku(product.sku) : '';
      })
      .filter(Boolean)
  )];

  const priceBySku = new Map();
  if (matchedSkus.length) {
    const { data, error } = await db
      .from('product_prices')
      .select('sku,price,price_type')
      .eq('legal_entity_group', getEntityGroupCode(legalEntity))
      .in('sku', matchedSkus)
      .in('price_type', ['purchase', 'deposit']);
    if (error) throw new Error(error);
    for (const row of data || []) {
      const sku = normalizeSku(row.sku);
      if (!sku) continue;
      if (!priceBySku.has(sku)) priceBySku.set(sku, {});
      priceBySku.get(sku)[row.price_type] = Number(row.price || 0);
    }
  }

  const items = [];
  let missingPrice = 0;
  let missingWeight = 0;

  for (const row of validRows) {
    if (row.weight) {
      const price = row.price !== '' ? Number(row.price) : 0;
      if (price <= 0) missingPrice++;
      items.push({
        o: preorderLabel,
        r: row.ttn ? `ТТН ${row.ttn}` : preorderLabel,
        s: normalizeStorage(row.storage),
        g: row.gtin,
        n: row.gtin,
        q: row.quantity,
        w: row.weight,
        p: Number(price.toFixed(2)),
      });
      continue;
    }

    const product = (row.gtin && productMapByGtin.get(row.gtin)) || productMapBySku.get(row.sku);
    if (!product) {
      unmatched.push({ ...row, reason: 'Не найден в справочнике товаров' });
      continue;
    }

    const resolvedSku = normalizeSku(product.sku);
    const resolvedGtin = normalizeGtin(product.gtin || row.gtin);
    if (!resolvedGtin) {
      unmatched.push({ ...row, resolved_sku: resolvedSku, reason: 'У товара нет GTIN в базе' });
      continue;
    }

    const prices = priceBySku.get(resolvedSku) || {};
    const price = row.price !== '' ? Number(row.price) : (Number.isFinite(prices.purchase) ? prices.purchase : (Number.isFinite(prices.deposit) ? prices.deposit : 0));
    if (price <= 0) missingPrice++;
    const rowWeightBrutto = Number(product.weight_brutto || 0) * Number(row.quantity || 0);
    if (rowWeightBrutto <= 0) missingWeight++;

    items.push({
      o: preorderLabel,
      r: row.ttn ? `ТТН ${row.ttn}` : preorderLabel,
      s: normalizeStorage(product.category),
      g: resolvedGtin,
      n: String(product.name || row.source_name || '').trim(),
      q: row.quantity,
      w: formatCttWeight(rowWeightBrutto),
      p: Number(price.toFixed(2)),
    });
  }

  items.sort((a, b) => [a.r, a.s, a.n].join('|').localeCompare([b.r, b.s, b.n].join('|'), 'ru'));

  return {
    items,
    unmatched,
    stats: {
      parsed: rows.length,
      converted: items.length,
      unmatched: unmatched.length,
      missing_price: missingPrice,
      missing_weight: missingWeight,
    },
  };
}

export function buildCttPreorderFilename(fileName) {
  const base = sanitizeFileBase(fileName);
  const slug = base
    .toLowerCase()
    .replace(/[^a-zа-я0-9]+/gi, '-')
    .replace(/^-+|-+$/g, '');
  const date = new Date().toISOString().slice(0, 10);
  return `data-preorder-${slug || 'file'}-${date}.json`;
}

export function buildCttPreorderLabel(fileName) {
  const base = sanitizeFileBase(fileName);
  return `Предзаказ ${base || 'файл'}`;
}
