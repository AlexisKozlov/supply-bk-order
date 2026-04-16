function normalizeSku(value) {
  return String(value ?? '').trim();
}

function normalizeGtin(value) {
  return String(value ?? '').replace(/\s+/g, '').trim();
}

function normalizeQty(value) {
  const raw = String(value ?? '').replace(',', '.').trim();
  const num = Number(raw);
  if (!Number.isFinite(num) || num <= 0) return '';
  return String(num).replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
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

function extractRows(rows) {
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
    const key = `${row.gtin || ''}|${row.sku}`;
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
  const sheetName = workbook.SheetNames[0];
  if (!sheetName) throw new Error('В файле нет листов');
  const sheet = workbook.Sheets[sheetName];
  const rows = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '' });
  const parsed = extractRows(rows);
  if (!parsed.length) {
    throw new Error('Не удалось распознать строки. Ожидаю колонки: артикул, товар, GTIN, количество');
  }
  return {
    sheetName,
    rows: aggregateSourceRows(parsed),
  };
}

export async function resolveCttPreorderRows({ rows, legalEntity, db, preorderLabel = 'Предзаказ' }) {
  if (!legalEntity) throw new Error('Выберите юр. лицо в боковом меню');
  if (!rows?.length) return { items: [], unmatched: [], stats: { parsed: 0, converted: 0, unmatched: 0, missing_price: 0 } };

  const gtins = [...new Set(rows.map(r => r.gtin).filter(Boolean))];
  const skus = [...new Set(rows.map(r => r.sku).filter(Boolean))];

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
    rows
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
      .eq('legal_entity', legalEntity)
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
  const unmatched = [];
  let missingPrice = 0;

  for (const row of rows) {
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
    const price = Number.isFinite(prices.purchase) ? prices.purchase : (Number.isFinite(prices.deposit) ? prices.deposit : 0);
    if (price <= 0) missingPrice++;

    items.push({
      o: 'PREORDER',
      r: preorderLabel,
      s: normalizeStorage(product.category),
      g: resolvedGtin,
      n: String(product.name || row.source_name || '').trim(),
      q: row.quantity,
      w: formatCttWeight(product.weight_brutto),
      p: Number(price.toFixed(2)),
    });
  }

  items.sort((a, b) => [a.o, a.s, a.n].join('|').localeCompare([b.o, b.s, b.n].join('|'), 'ru'));

  return {
    items,
    unmatched,
    stats: {
      parsed: rows.length,
      converted: items.length,
      unmatched: unmatched.length,
      missing_price: missingPrice,
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
