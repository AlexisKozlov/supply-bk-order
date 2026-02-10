/**
 * Модуль экспорта заказа в Excel
 */

export async function exportToExcel(orderState) {
  // Динамический импорт SheetJS
  const XLSX = await import('https://cdn.sheetjs.com/xlsx-0.20.1/package/xlsx.mjs');
  
  const nf = new Intl.NumberFormat('ru-RU');
  
  // Подготовка данных
  const data = orderState.items.map(item => {
    const boxes = orderState.settings.unit === 'boxes' 
      ? item.finalOrder 
      : Math.ceil(item.finalOrder / (item.qtyPerBox || 1));
    
    const pieces = orderState.settings.unit === 'boxes'
      ? item.finalOrder * (item.qtyPerBox || 1)
      : item.finalOrder;
    
    return {
      'SKU': item.sku || '',
      'Наименование': item.name || '',
      'Поставщик': item.supplier || '',
      'Расход (период)': nf.format(item.consumptionPeriod || 0),
      'Остаток': nf.format(item.stock || 0),
      'Транзит': nf.format(item.transit || 0),
      'Заказ (коробки)': nf.format(boxes),
      'Заказ (штуки)': nf.format(Math.round(pieces)),
      'Коробов на паллете': item.boxesPerPallet || '',
      'Штук в коробе': item.qtyPerBox || ''
    };
  });
  
  // Создание листа
  const ws = XLSX.utils.json_to_sheet(data);
  
  // Настройка ширины колонок
  const colWidths = [
    { wch: 12 }, // SKU
    { wch: 35 }, // Наименование
    { wch: 20 }, // Поставщик
    { wch: 15 }, // Расход
    { wch: 10 }, // Остаток
    { wch: 10 }, // Транзит
    { wch: 15 }, // Заказ (коробки)
    { wch: 15 }, // Заказ (штуки)
    { wch: 18 }, // Коробов на паллете
    { wch: 15 }  // Штук в коробе
  ];
  ws['!cols'] = colWidths;
  
  // Создание книги
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Заказ');
  
  // Добавление листа с параметрами
  const params = [
    ['Параметр', 'Значение'],
    ['Дата сегодня', orderState.settings.today?.toLocaleDateString('ru-RU') || ''],
    ['Дата прихода', orderState.settings.deliveryDate?.toLocaleDateString('ru-RU') || ''],
    ['Товарный запас (дни)', orderState.settings.safetyDays || 0],
    ['Период расчёта (дни)', orderState.settings.periodDays || 30],
    ['Единицы', orderState.settings.unit === 'boxes' ? 'Коробки' : 'Штуки'],
    ['Поставщик', orderState.settings.supplier || 'Все'],
    ['Юр. лицо', orderState.settings.legalEntity || ''],
    ['', ''],
    ['Итого позиций', data.length],
    ['Итого коробок', data.reduce((sum, item) => sum + parseInt(item['Заказ (коробки)'].replace(/\s/g, '')) || 0, 0)]
  ];
  
  const wsParams = XLSX.utils.aoa_to_sheet(params);
  wsParams['!cols'] = [{ wch: 25 }, { wch: 30 }];
  XLSX.utils.book_append_sheet(wb, wsParams, 'Параметры');
  
  // Формирование имени файла
  const date = new Date().toISOString().slice(0, 10);
  const supplier = orderState.settings.supplier || 'Все';
  const filename = `Заказ_${supplier}_${date}.xlsx`;
  
  // Сохранение файла
  XLSX.writeFile(wb, filename);
  
  return { success: true, filename };
}

export function canExportExcel(orderState) {
  return orderState.items.length > 0;
}