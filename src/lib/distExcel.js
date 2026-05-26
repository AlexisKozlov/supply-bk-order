/**
 * Excel-экспорт сессии распределения.
 *
 * Изолирован от UI: принимает только plain-данные и читалки статусов.
 * Стили подобраны под скан-чтение: зелёная отгрузка, красный крест,
 * шапка цвета bk-brown, итог жирным с подложкой.
 */

export async function exportDistExcel({
  sessionName,
  products,
  restaurants,
  getStatus,
  getQty,
  hasCustomQty,
  isVM,
  getShippedCount,
}) {
  const XLSX = (await import('xlsx-js-style')).default;

  const header1 = ['Ресторан', ...products.map(p => p.product_name || 'Товар')];
  const header2 = ['', ...products.map(p => `${p.default_qty} ${p.unit}`)];

  const dataRows = restaurants.map(r => {
    const row = [`${r.number} ${r.address || r.city}${isVM(r) ? ' (ВМ)' : ''}`];
    for (const p of products) {
      const s = getStatus(r.number, p.id);
      if (s === 1) {
        row.push(hasCustomQty(r.number, p.id) ? getQty(r.number, p.id) : '✓');
      } else if (s === 2) {
        row.push('✗');
      } else {
        row.push('');
      }
    }
    return row;
  });

  const totals = ['ИТОГО'];
  for (const p of products) totals.push(getShippedCount(p.id));

  const ws = XLSX.utils.aoa_to_sheet([header1, header2, ...dataRows, totals]);

  const border = {
    top: { style: 'thin', color: { rgb: 'CCCCCC' } },
    bottom: { style: 'thin', color: { rgb: 'CCCCCC' } },
    left: { style: 'thin', color: { rgb: 'CCCCCC' } },
    right: { style: 'thin', color: { rgb: 'CCCCCC' } },
  };
  const headerStyle = {
    font: { bold: true, sz: 11, name: 'Calibri', color: { rgb: 'FFFFFF' } },
    fill: { fgColor: { rgb: '502314' } },
    alignment: { horizontal: 'center', vertical: 'center', wrapText: true },
    border,
  };
  const subHeaderStyle = {
    font: { sz: 10, name: 'Calibri', color: { rgb: '6B5344' } },
    fill: { fgColor: { rgb: 'F5EBDC' } },
    alignment: { horizontal: 'center', vertical: 'center' },
    border,
  };
  const cellStyle = { font: { sz: 11, name: 'Calibri' }, alignment: { horizontal: 'center', vertical: 'center' }, border };
  const shippedStyle = { ...cellStyle, font: { ...cellStyle.font, color: { rgb: '2E7D32' }, bold: true }, fill: { fgColor: { rgb: 'E8F5E9' } } };
  const totalStyle = { font: { bold: true, sz: 11, name: 'Calibri', color: { rgb: '502314' } }, fill: { fgColor: { rgb: 'F5EBDC' } }, alignment: { horizontal: 'center', vertical: 'center' }, border };

  const range = XLSX.utils.decode_range(ws['!ref']);
  for (let R = range.s.r; R <= range.e.r; R++) {
    for (let C = range.s.c; C <= range.e.c; C++) {
      const addr = XLSX.utils.encode_cell({ r: R, c: C });
      const cell = ws[addr];
      if (!cell) continue;
      if (R === 0) cell.s = headerStyle;
      else if (R === 1) cell.s = subHeaderStyle;
      else if (R === range.e.r) cell.s = totalStyle;
      else if (C === 0) cell.s = { ...cellStyle, alignment: { horizontal: 'left' } };
      else if (cell.v === '✗') cell.s = { ...cellStyle, font: { ...cellStyle.font, color: { rgb: 'C62828' }, bold: true }, fill: { fgColor: { rgb: 'FFEBEE' } } };
      else if (cell.v === '✓' || (typeof cell.v === 'number' && cell.v > 0)) cell.s = shippedStyle;
      else cell.s = cellStyle;
    }
  }

  ws['!cols'] = [{ wch: 28 }, ...products.map(() => ({ wch: 16 }))];

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Распределение');
  XLSX.writeFile(wb, `Распределение_${sessionName.replace(/[^a-zA-Zа-яА-Я0-9]/g, '_')}.xlsx`);
}
