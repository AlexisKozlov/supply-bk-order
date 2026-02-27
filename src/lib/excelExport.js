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

/**
 * Экспорт карточек товаров в Excel
 */
export async function exportProductsToExcel(products, legalEntity) {
  const XLSX = await import('xlsx');

  const headerRow = [
    'Артикул', 'Наименование', 'Поставщик', 'Шт/кор', 'Кор/пал',
    'Ед. измерения', 'Кратность', 'Группа аналогов', 'Хранение', 'Видимость',
  ];

  const dataRows = products.map(p => [
    p.sku || '',
    p.name || '',
    p.supplier || '',
    p.qty_per_box || '',
    p.boxes_per_pallet || '',
    p.unit_of_measure || 'шт',
    p.multiplicity || '',
    p.analog_group || '',
    p.category || '',
    (p.is_active === 0 || p.is_active === '0') ? 'Нет' : 'Да',
  ]);

  const ws = XLSX.utils.aoa_to_sheet([headerRow, ...dataRows]);
  ws['!cols'] = [
    { wch: 15 }, { wch: 40 }, { wch: 25 }, { wch: 8 }, { wch: 8 },
    { wch: 12 }, { wch: 10 }, { wch: 25 }, { wch: 12 }, { wch: 10 },
  ];

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Товары');

  const date = new Date().toLocaleDateString('ru-RU');
  const entity = (legalEntity || 'Все').replace(/[""«»]/g, '');
  XLSX.writeFile(wb, `Карточки_${entity}_${date}.xlsx`);
}

/**
 * Экспорт аналитических отчётов в Excel — 4 листа
 */
export async function exportAnalyticsToExcel(analyticsData, seasonalityData) {
  const XLSX = await import('xlsx');
  const nf = new Intl.NumberFormat('ru-RU');

  const wb = XLSX.utils.book_new();

  // Лист 1: Обзор (KPI)
  const overviewRows = [
    ['Аналитика заказов'],
    [`Период: ${analyticsData.period} дней`],
    [],
    ['Показатель', 'Текущий', 'Прошлый', 'Δ%'],
    ['Заказов', analyticsData.totals.orders, analyticsData.prev.orders, analyticsData.deltaOrders !== null ? analyticsData.deltaOrders + '%' : '—'],
    ['Коробок', Math.round(analyticsData.totals.boxes), Math.round(analyticsData.prev.boxes), analyticsData.deltaBoxes !== null ? analyticsData.deltaBoxes + '%' : '—'],
    ['Ср. кор/заказ', analyticsData.totals.orders ? Math.round(analyticsData.totals.boxes / analyticsData.totals.orders) : 0, '', ''],
  ];
  const wsOverview = XLSX.utils.aoa_to_sheet(overviewRows);
  wsOverview['!cols'] = [{ wch: 20 }, { wch: 15 }, { wch: 15 }, { wch: 10 }];
  XLSX.utils.book_append_sheet(wb, wsOverview, 'Обзор');

  // Лист 2: Топ товаров
  const prodRows = [
    ['Топ товаров'],
    [],
    ['#', 'SKU', 'Наименование', 'Коробок', 'Δ%', 'Прогноз'],
    ...analyticsData.topProducts.map((p, i) => [
      i + 1, p.sku || '', p.name || '', Math.round(p.boxes),
      p.deltaBoxes !== null ? p.deltaBoxes + '%' : '—',
      p.forecast,
    ]),
  ];
  const wsProds = XLSX.utils.aoa_to_sheet(prodRows);
  wsProds['!cols'] = [{ wch: 5 }, { wch: 15 }, { wch: 35 }, { wch: 12 }, { wch: 8 }, { wch: 12 }];
  XLSX.utils.book_append_sheet(wb, wsProds, 'Топ товаров');

  // Лист 3: Поставщики
  const supRows = [
    ['Поставщики'],
    [],
    ['Поставщик', 'Заказов', 'Коробок', 'Ср./заказ', 'Δ%', 'Дн. назад'],
    ...analyticsData.suppliers.map(s => [
      s.supplier, s.orders, Math.round(s.boxes),
      s.orders ? Math.round(s.boxes / s.orders) : 0,
      s.prevBoxes > 0 ? Math.round((s.boxes - s.prevBoxes) / s.prevBoxes * 100) + '%' : '—',
      s.daysAgo !== null ? s.daysAgo : '—',
    ]),
  ];
  const wsSups = XLSX.utils.aoa_to_sheet(supRows);
  wsSups['!cols'] = [{ wch: 30 }, { wch: 10 }, { wch: 12 }, { wch: 12 }, { wch: 8 }, { wch: 10 }];
  XLSX.utils.book_append_sheet(wb, wsSups, 'Поставщики');

  // Лист 4: Сезонность
  if (seasonalityData && seasonalityData.monthData) {
    const seasonRows = [
      ['Сезонность (12 мес.)'],
      [],
      ['Месяц', 'Коробок', 'Заказов', 'Скольз. среднее', 'YoY Δ%'],
      ...seasonalityData.monthData.map(m => [
        m.label, Math.round(m.boxes), m.orders,
        m.movingAvg !== null ? Math.round(m.movingAvg) : '—',
        m.yoyDelta !== null ? m.yoyDelta + '%' : '—',
      ]),
    ];
    const wsSeason = XLSX.utils.aoa_to_sheet(seasonRows);
    wsSeason['!cols'] = [{ wch: 15 }, { wch: 12 }, { wch: 10 }, { wch: 15 }, { wch: 10 }];
    XLSX.utils.book_append_sheet(wb, wsSeason, 'Сезонность');
  }

  XLSX.writeFile(wb, `Аналитика_${new Date().toLocaleDateString('ru-RU')}.xlsx`);
}
