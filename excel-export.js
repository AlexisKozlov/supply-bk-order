/**
 * Модуль экспорта заказа в Excel
 */

export async function exportToExcel(orderState) {
  // Динамический импорт SheetJS
  const XLSX = await import('https://cdn.sheetjs.com/xlsx-0.20.1/package/xlsx.mjs');
  
  const nf = new Intl.NumberFormat('ru-RU');
  
  // Заголовок с параметрами
  const supplier = orderState.settings.supplier || 'Все';
  const deliveryDate = orderState.settings.deliveryDate?.toLocaleDateString('ru-RU') || '';
  const legalEntity = orderState.settings.legalEntity || '';
  
  const headerRows = [
    [`Поставщик: ${supplier}`],
    [`Дата прихода: ${deliveryDate}`],
    [`Юридическое лицо: ${legalEntity}`],
    [], // Пустая строка
    ['Наименование', 'Заказ (коробки)'] // Заголовки таблицы
  ];
  
  // Подготовка данных - только наименование и заказ
  const dataRows = orderState.items.map(item => {
    const boxes = orderState.settings.unit === 'boxes' 
      ? item.finalOrder 
      : Math.ceil(item.finalOrder / (item.qtyPerBox || 1));
    
    return [
      item.name || '',
      nf.format(boxes)
    ];
  });
  
  // Объединяем всё
  const allRows = [...headerRows, ...dataRows];
  
  // Создание листа
  const ws = XLSX.utils.aoa_to_sheet(allRows);
  
  // Настройка ширины колонок
  ws['!cols'] = [
    { wch: 50 }, // Наименование
    { wch: 20 }  // Заказ
  ];
  
  // Объединение ячеек для заголовка
  ws['!merges'] = [
    { s: { r: 0, c: 0 }, e: { r: 0, c: 1 } }, // Поставщик
    { s: { r: 1, c: 0 }, e: { r: 1, c: 1 } }, // Дата прихода
    { s: { r: 2, c: 0 }, e: { r: 2, c: 1 } }  // Юр. лицо
  ];
  
  // Стили для заголовков (жирный шрифт)
  ['A1', 'A2', 'A3', 'A5', 'B5'].forEach(cell => {
    if (ws[cell]) {
      ws[cell].s = {
        font: { bold: true }
      };
    }
  });
  
  // Создание книги
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Заказ');
  
  // Формирование имени файла
  const date = new Date().toISOString().slice(0, 10);
  const filename = `Заказ_${supplier}_${date}.xlsx`;
  
  // Сохранение файла
  XLSX.writeFile(wb, filename);
  
  return { success: true, filename };
}

export function canExportExcel(orderState) {
  return orderState.items.length > 0;
}