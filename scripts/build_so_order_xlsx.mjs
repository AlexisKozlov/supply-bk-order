#!/usr/bin/env node
/**
 * Генератор Excel-заказа поставщику в стиле «Планеты Ресторанов».
 *
 * Тонкая обёртка над общим модулем src/lib/soOrderXlsx.js: тот же код строит
 * лист и в браузере (скачивание из UI), и здесь на сервере (отправка поставщику
 * по Telegram/почте). Обёртка только читает входной JSON, приводит серверный
 * «плоский» формат payload к контракту модуля, собирает книгу и пишет буфер.
 *
 * Использование: node scripts/build_so_order_xlsx.mjs <json_path> <out_xlsx_path>
 *
 * Входной JSON (готовит PHP soBuildSummaryXlsx):
 * {
 *   "supplier_name": "Камако",
 *   "delivery_date_fmt": "13.04.2026",
 *   "sheet_name": "Камако",
 *   "products": [
 *     {"sku": "...", "name": "...",
 *      "qty_per_box": 10, "boxes_per_pallet": 40,
 *      "weight_netto": 5000, "weight_brutto": 5200}   // вес ОДНОЙ коробки, граммы
 *   ],
 *   "restaurants": [
 *     {"number": 1, "city": "Минск", "region": "Минск", "address": "...", "submitted": true}
 *   ],
 *   "items": {                       // ключ "{number}_{sku}"
 *     "1_SKU1": { "qty": 10, "is_admin": false }
 *   },
 *   "options": {
 *     "drop_empty_rows": false,      // убрать неподавших и «пустые» рестораны
 *     "pallet_metrics": []           // какие показатели паллет/веса показывать:
 *                                    // ['boxes','pallets','netto','brutto']; []=не показывать
 *   }
 * }
 *
 * Товары приходят ПЛОСКИМИ (без is_grouped/source_skus) — модуль это переживает
 * (передаём is_grouped:false). Неподавшие рестораны выделяются красной строкой.
 */

import XLSX from 'xlsx-js-style';
import fs from 'fs';
import { buildSoOrderSheet } from '../src/lib/soOrderXlsx.js';

const [, , jsonPath, outPath] = process.argv;
if (!jsonPath || !outPath) {
    console.error('Usage: build_so_order_xlsx.mjs <json> <out>');
    process.exit(2);
}

const data = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));
const supplierName = data.supplier_name || 'Поставщик';
const dateFmt = data.delivery_date_fmt || '';
// Имя листа Excel не может содержать : \ / ? * [ ] — иначе XLSX.write падает.
// Чистим их (заменяем на пробел), схлопываем пробелы, обрезаем до 28 символов.
// Если после чистки пусто — берём запасное имя, лист не может быть безымянным.
const rawSheetName = String(data.sheet_name || supplierName || '');
const sheetName = (rawSheetName.replace(/[:\\/?*[\]]/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 28)) || 'Заявка';

// ── Опции: PHP шлёт snake_case, модуль ждёт camelCase; принимаем оба вида. ──
const rawOptions = data.options || {};
const rawMetrics = rawOptions.palletMetrics ?? rawOptions.pallet_metrics ?? [];
const options = {
    dropEmptyRows: !!(rawOptions.dropEmptyRows ?? rawOptions.drop_empty_rows ?? false),
    palletMetrics: Array.isArray(rawMetrics) ? rawMetrics : [],
};

// ── Товары: name → product_name, плоские (без группировки). ──
const products = (data.products || []).map(p => ({
    sku: p.sku,
    product_name: p.product_name ?? p.name ?? '',
    is_grouped: false,
    qty_per_box: p.qty_per_box,
    boxes_per_pallet: p.boxes_per_pallet,
    weight_netto: p.weight_netto,
    weight_brutto: p.weight_brutto,
}));

// ── Рестораны: флаг submitted → order_status (модуль смотрит order_status). ──
const restaurants = (data.restaurants || []).map(r => ({
    number: r.number,
    city: r.city,
    region: r.region,
    address: r.address,
    order_status: r.order_status ?? (r.submitted ? 'submitted' : 'draft'),
}));

// ── Позиции: объект {"{rn}_{sku}": {qty, is_admin}} → массив контракта модуля.
// Ключ = номер ресторана + "_" + sku; sku может содержать "_", поэтому режем
// по ПЕРВОМУ подчёркиванию (номер ресторана — число, без "_"). ──
const rawItems = data.items || {};
const items = [];
for (const key of Object.keys(rawItems)) {
    const idx = key.indexOf('_');
    if (idx < 0) continue;
    const rn = key.slice(0, idx);
    const sku = key.slice(idx + 1);
    const v = rawItems[key] || {};
    const isAdmin = !!(v.is_admin ?? v.isAdmin ?? false);
    const qty = v.qty;
    items.push({
        restaurant_number: rn,
        sku,
        quantity: qty,
        admin_qty: isAdmin ? qty : null,
    });
}

// ── Один лист общим модулем; на сервере авто-подача не подсвечивается. ──
const ws = buildSoOrderSheet(XLSX, {
    supplierName,
    dateFmt,
    products,
    restaurants,
    items,
    isAutoSubmitted: () => false,
    options,
});

const wb = XLSX.utils.book_new();
XLSX.utils.book_append_sheet(wb, ws, sheetName);
const buf = XLSX.write(wb, { type: 'buffer', bookType: 'xlsx' });
fs.writeFileSync(outPath, buf);
console.log(`OK ${outPath} ${buf.length}`);
