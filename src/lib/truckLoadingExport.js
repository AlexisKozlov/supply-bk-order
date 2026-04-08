/**
 * Excel-экспорт для модуля «Загрузка машин»
 */
import { EXCEL_HEADER_STYLE, EXCEL_SUBTOTAL_STYLE, EXCEL_TOTAL_STYLE } from '@/lib/roUtils.js';

export async function exportTruckLoading(trucks, orders, deliveryDate, truckStatsFn) {
  const XLSX = await import('xlsx-js-style');
  const wb = XLSX.utils.book_new();
  const dateStr = new Date(deliveryDate + 'T00:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' });

  // Карта заказов для быстрого доступа
  const orderMap = {};
  for (const o of orders) orderMap[o.order_id] = o;

  const MODE_LABELS = { dry: 'Сухой', cold: 'Холод', frozen: 'Мороз', any: 'Смешанный' };
  const HEADER = ['№', 'Ресторан', 'Город', 'Адрес', 'Режим', 'Паллеты', 'Вес, кг'];
  const COLS = [{ wch: 5 }, { wch: 10 }, { wch: 15 }, { wch: 35 }, { wch: 10 }, { wch: 10 }, { wch: 12 }];

  let totalPallets = 0;
  let totalWeight = 0;
  let truckCount = 0;

  for (let t = 0; t < trucks.length; t++) {
    const truck = trucks[t];
    const assignments = truck.assignments || [];
    if (!assignments.length) continue;
    truckCount++;

    const stats = truckStatsFn(truck);
    const name = truck.custom_name || (truck.vehicle_id ? `Машина ${t + 1}` : `Машина ${t + 1}`);
    const modeName = MODE_LABELS[truck.mode] || 'Смешанный';

    const rows = [];
    // Заголовок машины
    rows.push([`${name} (${modeName})`]);
    rows.push([`Паллеты: ${stats.pallets}/${truck.capacity_pallets} (${stats.percentPallets}%)  |  Вес: ${stats.weight}/${parseFloat(truck.capacity_kg).toFixed(0)} кг (${stats.percentWeight}%)`]);
    rows.push([]);
    rows.push(HEADER);

    for (let i = 0; i < assignments.length; i++) {
      const a = assignments[i];
      const order = orderMap[a.order_id];
      rows.push([
        i + 1,
        a.restaurant_number,
        order?.city || '',
        order?.address || '',
        a.category || modeName,
        +(parseFloat(a.pallets) || 0).toFixed(1),
        +(parseFloat(a.weight_kg) || 0).toFixed(1),
      ]);
    }

    // Итого по машине
    rows.push([]);
    rows.push(['', '', '', 'Итого:', '', stats.pallets, stats.weight]);

    totalPallets += stats.pallets;
    totalWeight += stats.weight;

    const ws = XLSX.utils.aoa_to_sheet(rows);
    // Стили
    // Заголовок машины (строки 0-1)
    for (let c = 0; c < HEADER.length; c++) {
      const cell0 = ws[XLSX.utils.encode_cell({ r: 0, c })];
      if (cell0) cell0.s = { font: { bold: true, sz: 14, color: { rgb: '502314' } } };
      const cell1 = ws[XLSX.utils.encode_cell({ r: 1, c })];
      if (cell1) cell1.s = { font: { sz: 11, color: { rgb: '8b7355' } } };
    }
    // Заголовок таблицы (строка 3)
    for (let c = 0; c < HEADER.length; c++) {
      const cell = ws[XLSX.utils.encode_cell({ r: 3, c })];
      if (cell) cell.s = EXCEL_HEADER_STYLE;
    }
    // Итого
    const totalRowIdx = rows.length - 1;
    for (let c = 0; c < HEADER.length; c++) {
      const cell = ws[XLSX.utils.encode_cell({ r: totalRowIdx, c })];
      if (cell) cell.s = EXCEL_SUBTOTAL_STYLE;
    }

    ws['!cols'] = COLS;
    ws['!merges'] = [
      { s: { r: 0, c: 0 }, e: { r: 0, c: HEADER.length - 1 } },
      { s: { r: 1, c: 0 }, e: { r: 1, c: HEADER.length - 1 } },
    ];

    const sheetName = `Машина ${t + 1}`.slice(0, 31);
    XLSX.utils.book_append_sheet(wb, ws, sheetName);
  }

  // Сводный лист
  if (truckCount > 1) {
    const summaryRows = [
      [`Загрузка машин — ${dateStr}`],
      [],
      ['Машина', 'Тип', 'Режим', 'Ресторанов', 'Паллеты', 'Загрузка %', 'Вес, кг', 'Загрузка %'],
    ];
    for (let t = 0; t < trucks.length; t++) {
      const truck = trucks[t];
      const a = truck.assignments || [];
      if (!a.length) continue;
      const stats = truckStatsFn(truck);
      const restNums = new Set(a.map(x => x.restaurant_number));
      summaryRows.push([
        `Машина ${t + 1}`,
        truck.custom_name || '',
        MODE_LABELS[truck.mode] || 'Смешанный',
        restNums.size,
        stats.pallets,
        stats.percentPallets + '%',
        stats.weight,
        stats.percentWeight + '%',
      ]);
    }
    summaryRows.push([]);
    summaryRows.push(['ИТОГО', '', '', '', totalPallets.toFixed(1), '', totalWeight.toFixed(1), '']);

    const ws = XLSX.utils.aoa_to_sheet(summaryRows);
    // Стили
    const cell0 = ws[XLSX.utils.encode_cell({ r: 0, c: 0 })];
    if (cell0) cell0.s = { font: { bold: true, sz: 14, color: { rgb: '502314' } } };
    for (let c = 0; c < 8; c++) {
      const cell = ws[XLSX.utils.encode_cell({ r: 2, c })];
      if (cell) cell.s = EXCEL_HEADER_STYLE;
    }
    const totalIdx = summaryRows.length - 1;
    for (let c = 0; c < 8; c++) {
      const cell = ws[XLSX.utils.encode_cell({ r: totalIdx, c })];
      if (cell) cell.s = EXCEL_TOTAL_STYLE;
    }
    ws['!cols'] = [{ wch: 12 }, { wch: 15 }, { wch: 12 }, { wch: 12 }, { wch: 10 }, { wch: 12 }, { wch: 12 }, { wch: 12 }];
    ws['!merges'] = [{ s: { r: 0, c: 0 }, e: { r: 0, c: 7 } }];
    XLSX.utils.book_append_sheet(wb, ws, 'Сводка');
  }

  XLSX.writeFile(wb, `Загрузка_машин_${deliveryDate}.xlsx`);
}
