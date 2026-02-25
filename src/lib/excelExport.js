import { getQpb, getMultiplicity } from './utils.js';

export async function exportToExcel(items, settings) {
  const XLSX = await import('xlsx');
  const nf = new Intl.NumberFormat('ru-RU');

  const supplier     = settings.supplier || 'Все';
  const deliveryDate = settings.deliveryDate?.toLocaleDateString('ru-RU') || '';
  const legalEntity  = settings.legalEntity || '';

  const headerRows = [
    [`Поставщик: ${supplier}`],
    [`Дата прихода: ${deliveryDate}`],
    [`Юридическое лицо: ${legalEntity}`],
    [],
    ['Наименование', 'Заказ'],
  ];

  const dataRows = items.map(item => {
    const qpb  = getQpb(item);
    const mult = getMultiplicity(item);
    const physBoxes = settings.unit === 'boxes'
      ? Math.ceil(item.finalOrder / mult)
      : Math.ceil(item.finalOrder / (qpb * mult));
    if (physBoxes <= 0) return null;
    const pieces = settings.unit === 'pieces' ? item.finalOrder : item.finalOrder * qpb;
    const unit = item.unitOfMeasure || 'шт';
    const nameWithSku = item.sku ? `${item.sku} ${item.name || ''}` : (item.name || '');
    return [nameWithSku, `${nf.format(physBoxes)} кор (${nf.format(Math.round(pieces))} ${unit})`];
  }).filter(Boolean);

  const allRows = [...headerRows, ...dataRows];
  const ws = XLSX.utils.aoa_to_sheet(allRows);

  ws['!cols'] = [{ wch: 50 }, { wch: 20 }];
  ws['!merges'] = [
    { s: { r: 0, c: 0 }, e: { r: 0, c: 1 } },
    { s: { r: 1, c: 0 }, e: { r: 1, c: 1 } },
    { s: { r: 2, c: 0 }, e: { r: 2, c: 1 } },
  ];

  ['A1', 'A2', 'A3', 'A5', 'B5'].forEach(cell => {
    if (ws[cell]) ws[cell].s = { font: { bold: true } };
  });

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Заказ');

  const dd = settings.deliveryDate || new Date();
  const fileDate = `${String(dd.getDate()).padStart(2,'0')}-${String(dd.getMonth()+1).padStart(2,'0')}-${dd.getFullYear()}`;
  XLSX.writeFile(wb, `Заказ_${supplier}_${fileDate}.xlsx`);
}
