#!/usr/bin/env node
/**
 * Генератор Excel-заказа поставщику в стиле «Планеты Ресторанов».
 *
 * Использование: node scripts/build_so_order_xlsx.mjs <json_path> <out_xlsx_path>
 *
 * Входной JSON:
 * {
 *   "supplier_name": "Камако",
 *   "delivery_date_fmt": "13.04.2026",
 *   "sheet_name": "Камако",
 *   "products": [ {"sku": "...", "name": "..."} ],
 *   "restaurants": [
 *     {"number": 1, "city": "Минск", "region": "Минск", "address": "...", "submitted": true}
 *   ],
 *   "items": {
 *     "1_SKU1": { "qty": 10, "is_admin": false }
 *   }
 * }
 *
 * На выходе пишется .xlsx в указанный путь. Неподавшие рестораны выделяются
 * красной строкой с нулями.
 */

import XLSX from 'xlsx-js-style';
import fs from 'fs';

const [, , jsonPath, outPath] = process.argv;
if (!jsonPath || !outPath) {
    console.error('Usage: build_so_order_xlsx.mjs <json> <out>');
    process.exit(2);
}

const data = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));
const supplierName = data.supplier_name || 'Поставщик';
const dateFmt = data.delivery_date_fmt || '';
const sheetName = (data.sheet_name || supplierName).slice(0, 28);
const prods = data.products || [];
const rests = data.restaurants || [];
const items = data.items || {};

// ═══ Стили ═══
const border = {
    top:    { style: 'thin', color: { rgb: 'BDBDBD' } },
    bottom: { style: 'thin', color: { rgb: 'BDBDBD' } },
    left:   { style: 'thin', color: { rgb: 'BDBDBD' } },
    right:  { style: 'thin', color: { rgb: 'BDBDBD' } },
};
const titleStyle = { font: { bold: true, sz: 14, name: 'Calibri', color: { rgb: '2E7D32' } }, alignment: { horizontal: 'left', vertical: 'center' } };
const headerStyle = { font: { bold: true, color: { rgb: 'FFFFFF' }, sz: 11, name: 'Calibri' }, fill: { fgColor: { rgb: '2E7D32' } }, alignment: { horizontal: 'center', vertical: 'center', wrapText: true }, border };
const headerLeftStyle = { ...headerStyle, alignment: { horizontal: 'left', vertical: 'center', wrapText: true } };
const cityStyle = { font: { bold: true, sz: 12, name: 'Calibri', color: { rgb: 'FFFFFF' } }, fill: { fgColor: { rgb: '5D4037' } }, alignment: { horizontal: 'left', vertical: 'center' }, border };
const restStyle = { font: { bold: true, sz: 11, name: 'Calibri' }, alignment: { horizontal: 'center', vertical: 'center' }, border };
const addrStyle = { font: { sz: 10, color: { rgb: '666666' }, name: 'Calibri' }, alignment: { horizontal: 'left', vertical: 'center' }, border };
const qtyStyle = { font: { sz: 11, name: 'Calibri' }, alignment: { horizontal: 'center', vertical: 'center' }, border };
const qtyFilledStyle = { ...qtyStyle, font: { bold: true, sz: 11, name: 'Calibri' }, fill: { fgColor: { rgb: 'E8F5E9' } } };
const adminQtyStyle = { ...qtyStyle, font: { bold: true, sz: 11, name: 'Calibri', color: { rgb: 'D62700' } }, fill: { fgColor: { rgb: 'FFEBEE' } } };
const noteStyle = { font: { sz: 10, italic: true, name: 'Calibri', color: { rgb: '555555' } }, alignment: { horizontal: 'left', vertical: 'center' }, border };
const evenRowBg = { fgColor: { rgb: 'F5F5F5' } };
const subtotalStyle = { font: { bold: true, sz: 11, name: 'Calibri', color: { rgb: '5D4037' } }, fill: { fgColor: { rgb: 'EFEBE9' } }, alignment: { horizontal: 'center', vertical: 'center' }, border };
const subtotalLeftStyle = { ...subtotalStyle, alignment: { horizontal: 'left', vertical: 'center' } };
const grandTotalStyle = {
    font: { bold: true, sz: 12, name: 'Calibri', color: { rgb: '2E7D32' } },
    fill: { fgColor: { rgb: 'C8E6C9' } },
    alignment: { horizontal: 'center', vertical: 'center' },
    border: {
        top:    { style: 'medium', color: { rgb: '2E7D32' } },
        bottom: { style: 'medium', color: { rgb: '2E7D32' } },
        left:   border.left,
        right:  border.right,
    },
};
const grandTotalLeftStyle = { ...grandTotalStyle, alignment: { horizontal: 'left', vertical: 'center' } };

// Красные — для неподавших ресторанов
const missQtyStyle = { font: { bold: true, sz: 11, name: 'Calibri', color: { rgb: 'B71C1C' } }, fill: { fgColor: { rgb: 'FFCDD2' } }, alignment: { horizontal: 'center', vertical: 'center' }, border };
const missRestStyle = { font: { bold: true, sz: 11, name: 'Calibri', color: { rgb: 'B71C1C' } }, fill: { fgColor: { rgb: 'FFCDD2' } }, alignment: { horizontal: 'center', vertical: 'center' }, border };
const missAddrStyle = { font: { sz: 10, bold: true, color: { rgb: 'B71C1C' }, name: 'Calibri' }, fill: { fgColor: { rgb: 'FFCDD2' } }, alignment: { horizontal: 'left', vertical: 'center' }, border };
const missNoteStyle = { font: { sz: 10, italic: true, bold: true, name: 'Calibri', color: { rgb: 'B71C1C' } }, fill: { fgColor: { rgb: 'FFCDD2' } }, alignment: { horizontal: 'left', vertical: 'center' }, border };

// ═══ Группировка по городам ═══
const sortedRests = rests.slice().sort((a, b) => {
    const ca = (a.city || '').localeCompare(b.city || '');
    if (ca !== 0) return ca;
    return (parseInt(a.number) || 0) - (parseInt(b.number) || 0);
});
const cityGroups = {};
const cityOrder = [];
for (const r of sortedRests) {
    const c = r.city || r.region || 'Без города';
    if (!cityGroups[c]) { cityGroups[c] = []; cityOrder.push(c); }
    cityGroups[c].push(r);
}

// ═══ AOA ═══
const header = ['№', 'Адрес'];
for (const p of prods) header.push(p.sku ? `${p.sku}\n${p.name}` : p.name);
header.push('Пометка');

const titleRow = [`Заявка ${supplierName} на ${dateFmt}`];
const aoa = [titleRow, header];
const rowMeta = [];
const merges = [{ s: { r: 0, c: 0 }, e: { r: 0, c: header.length - 1 } }];

function getQty(r, p) {
    const v = items[`${r.number}_${p.sku}`];
    return v || null;
}

for (const city of cityOrder) {
    const group = cityGroups[city];
    const cityRow = [city];
    for (let i = 1; i < header.length; i++) cityRow.push('');
    aoa.push(cityRow);
    merges.push({ s: { r: aoa.length - 1, c: 0 }, e: { r: aoa.length - 1, c: header.length - 1 } });
    rowMeta.push({ type: 'city' });

    for (let i = 0; i < group.length; i++) {
        const r = group[i];
        const row = [r.number, r.address || ''];
        for (const p of prods) {
            if (!r.submitted) { row.push(0); continue; }
            const v = getQty(r, p);
            row.push(v ? v.qty : 0);
        }
        row.push(r.submitted ? '' : 'Не подал');
        aoa.push(row);
        rowMeta.push({ type: 'data', row: r, isEven: i % 2 === 1, isSubmitted: !!r.submitted });
    }

    const subRow = [`Итого ${city}`, ''];
    for (const p of prods) {
        let sum = 0;
        for (const r of group) {
            if (!r.submitted) continue;
            const v = getQty(r, p);
            if (v) sum += v.qty;
        }
        subRow.push(sum);
    }
    subRow.push('');
    aoa.push(subRow);
    rowMeta.push({ type: 'subtotal' });
}

const grandRow = ['ИТОГО', ''];
for (const p of prods) {
    let sum = 0;
    for (const r of sortedRests) {
        if (!r.submitted) continue;
        const v = getQty(r, p);
        if (v) sum += v.qty;
    }
    grandRow.push(sum);
}
grandRow.push('');
aoa.push(grandRow);
rowMeta.push({ type: 'total' });

// ═══ Лист и стили ═══
const ws = XLSX.utils.aoa_to_sheet(aoa);
ws['!merges'] = merges;

const titleCell = ws[XLSX.utils.encode_cell({ r: 0, c: 0 })];
if (titleCell) titleCell.s = titleStyle;
for (let c = 0; c < header.length; c++) {
    const cell = ws[XLSX.utils.encode_cell({ r: 1, c })];
    if (cell) cell.s = c <= 1 ? headerLeftStyle : headerStyle;
}
for (let mi = 0; mi < rowMeta.length; mi++) {
    const r = mi + 2;
    const meta = rowMeta[mi];
    if (meta.type === 'city') {
        for (let c = 0; c < header.length; c++) { const cell = ws[XLSX.utils.encode_cell({ r, c })]; if (cell) cell.s = cityStyle; }
    } else if (meta.type === 'data') {
        const numCell = ws[XLSX.utils.encode_cell({ r, c: 0 })];
        if (numCell) numCell.s = meta.isSubmitted ? { ...restStyle, ...(meta.isEven ? { fill: evenRowBg } : {}) } : missRestStyle;
        const aCell = ws[XLSX.utils.encode_cell({ r, c: 1 })];
        if (aCell) aCell.s = meta.isSubmitted ? { ...addrStyle, ...(meta.isEven ? { fill: evenRowBg } : {}) } : missAddrStyle;
        for (let pi = 0; pi < prods.length; pi++) {
            const c = 2 + pi;
            const cell = ws[XLSX.utils.encode_cell({ r, c })];
            if (!cell) continue;
            if (!meta.isSubmitted) { cell.s = missQtyStyle; continue; }
            const v = getQty(meta.row, prods[pi]);
            if (v && v.is_admin) cell.s = adminQtyStyle;
            else if (v) cell.s = { ...qtyFilledStyle, ...(meta.isEven ? { fill: { fgColor: { rgb: 'E8F5E9' } } } : {}) };
            else cell.s = { ...qtyStyle, ...(meta.isEven ? { fill: evenRowBg } : {}) };
        }
        const nCell = ws[XLSX.utils.encode_cell({ r, c: header.length - 1 })];
        if (nCell) nCell.s = meta.isSubmitted ? { ...noteStyle, ...(meta.isEven ? { fill: evenRowBg } : {}) } : missNoteStyle;
    } else if (meta.type === 'subtotal') {
        for (let c = 0; c < header.length; c++) { const cell = ws[XLSX.utils.encode_cell({ r, c })]; if (cell) cell.s = c === 0 ? subtotalLeftStyle : subtotalStyle; }
    } else if (meta.type === 'total') {
        for (let c = 0; c < header.length; c++) { const cell = ws[XLSX.utils.encode_cell({ r, c })]; if (cell) cell.s = c === 0 ? grandTotalLeftStyle : grandTotalStyle; }
    }
}

ws['!cols'] = [
    { wch: 8 }, { wch: 32 },
    ...Array(prods.length).fill({ wch: 14 }),
    { wch: 20 },
];
ws['!rows'] = [{ hpx: 28 }, { hpx: 42 }];

const wb = XLSX.utils.book_new();
XLSX.utils.book_append_sheet(wb, ws, sheetName);
const buf = XLSX.write(wb, { type: 'buffer', bookType: 'xlsx' });
fs.writeFileSync(outPath, buf);
console.log(`OK ${outPath} ${buf.length}`);
