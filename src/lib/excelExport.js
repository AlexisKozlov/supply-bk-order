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

/**
 * Экспорт графика доставки в Excel (как таблица на сайте)
 */
export async function exportScheduleToExcel(restaurants, scheduleByRestaurant, lastUpdate) {
  const XLSX = await import('xlsx-js-style');
  const date = new Date().toLocaleDateString('ru-RU');

  const colCount = 10; // №, Адрес, Дн, ПН-СБ(6), Комментарий
  const dayNames = ['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];

  // Цвета как на сайте
  const brown = '502314';
  const brownBg = 'F0EBE5';
  const greenBg = 'A5D6A7';
  const greenText = '1B5E20';
  const orangeBg = 'FF8732';
  const redText = 'D62300';
  const borderColor = 'E8E0D6';
  const stripeBg = 'FAF8F5';

  const thinBorder = { style: 'thin', color: { rgb: borderColor } };
  const borders = { top: thinBorder, bottom: thinBorder, left: thinBorder, right: thinBorder };

  // Стили
  const sHeader = {
    font: { bold: true, color: { rgb: 'FFFFFF' }, sz: 13, name: 'Calibri' },
    fill: { fgColor: { rgb: brown } },
    alignment: { horizontal: 'center', vertical: 'center' },
    border: { top: thinBorder, bottom: thinBorder, left: { style: 'thin', color: { rgb: '3A1A0E' } }, right: { style: 'thin', color: { rgb: '3A1A0E' } } },
  };
  const sGroup = {
    font: { bold: true, sz: 14, color: { rgb: brown }, name: 'Calibri' },
    fill: { fgColor: { rgb: brownBg } },
    alignment: { horizontal: 'center', vertical: 'center' },
    border: borders,
  };
  const sEmpty = (stripe) => ({
    fill: stripe ? { fgColor: { rgb: stripeBg } } : undefined,
    border: borders,
  });
  const sNum = (stripe) => ({
    font: { bold: true, sz: 15, color: { rgb: redText }, name: 'Calibri' },
    alignment: { horizontal: 'center', vertical: 'center' },
    fill: stripe ? { fgColor: { rgb: stripeBg } } : undefined,
    border: borders,
  });
  const sAddr = (stripe) => ({
    font: { bold: true, sz: 14, color: { rgb: brown }, name: 'Calibri' },
    alignment: { vertical: 'center' },
    fill: stripe ? { fgColor: { rgb: stripeBg } } : undefined,
    border: borders,
  });
  const sCnt = (stripe) => ({
    font: { bold: true, sz: 13, color: { rgb: '666666' }, name: 'Calibri' },
    alignment: { horizontal: 'center', vertical: 'center' },
    fill: stripe ? { fgColor: { rgb: stripeBg } } : undefined,
    border: borders,
  });
  const sDay = (hasTime, stripe) => ({
    font: hasTime ? { bold: true, sz: 13, color: { rgb: greenText }, name: 'Calibri' } : { sz: 13, color: { rgb: 'D5D0CA' }, name: 'Calibri' },
    fill: hasTime ? { fgColor: { rgb: greenBg } } : (stripe ? { fgColor: { rgb: stripeBg } } : undefined),
    alignment: { horizontal: 'center', vertical: 'center' },
    border: borders,
  });
  const sTotalsLabel = {
    font: { bold: true, sz: 13, color: { rgb: 'FFFFFF' }, name: 'Calibri' },
    fill: { fgColor: { rgb: brown } },
    alignment: { horizontal: 'right', vertical: 'center' },
    border: borders,
  };
  const sTotalVal = {
    font: { bold: true, sz: 14, color: { rgb: 'FFFFFF' }, name: 'Calibri' },
    fill: { fgColor: { rgb: orangeBg } },
    alignment: { horizontal: 'center', vertical: 'center' },
    border: borders,
  };
  const sTotalEmpty = {
    fill: { fgColor: { rgb: brown } },
    border: borders,
  };

  // Разделяем на Минск и регионы
  const minsk = restaurants.filter(r => r.region === 'Минск');
  const regions = restaurants.filter(r => r.region !== 'Минск');

  const ws = {};
  let row = 0;

  function setCell(r, c, val, style) {
    const ref = XLSX.utils.encode_cell({ r, c });
    ws[ref] = { v: val, t: typeof val === 'number' ? 'n' : 's', s: style };
  }

  // Строка 0: заголовок
  setCell(row, 0, `График доставки — ${date}`, {
    font: { bold: true, sz: 18, color: { rgb: brown }, name: 'Calibri' },
    alignment: { vertical: 'center' },
  });
  row++;

  // Строка 1: заголовки колонок
  const headers = ['№', 'Адрес', 'Дн', ...dayNames, 'Комментарий'];
  headers.forEach((h, c) => setCell(row, c, h, sHeader));
  row++;

  // Данные с группами
  function writeGroup(label, items) {
    // Строка группы
    for (let c = 0; c < colCount; c++) {
      setCell(row, c, c === 0 ? `${label} (${items.length})` : '', sGroup);
    }
    row++;

    // Строки ресторанов
    items.forEach((r, idx) => {
      const stripe = idx % 2 === 1;
      const rSched = scheduleByRestaurant.get(String(r.id));
      const cnt = rSched ? rSched.size : 0;

      setCell(row, 0, r.number || '', sNum(stripe));
      setCell(row, 1, r.address || '', sAddr(stripe));
      setCell(row, 2, cnt, sCnt(stripe));

      for (let d = 1; d <= 6; d++) {
        const time = rSched?.get(d)?.delivery_time || '';
        setCell(row, 2 + d, time || '—', sDay(!!time, stripe));
      }
      setCell(row, 9, r.notes || '', sEmpty(stripe));

      row++;
    });
  }

  if (minsk.length) writeGroup('Минск', minsk);
  if (regions.length) writeGroup('Регионы', regions);

  // Строка итогов
  setCell(row, 0, '', sTotalEmpty);
  setCell(row, 1, '', sTotalEmpty);
  setCell(row, 2, 'Доставок:', sTotalsLabel);
  for (let d = 1; d <= 6; d++) {
    let c = 0;
    for (const r of restaurants) {
      if (scheduleByRestaurant.get(String(r.id))?.get(d)?.delivery_time) c++;
    }
    setCell(row, 2 + d, c, sTotalVal);
  }
  setCell(row, 9, '', sTotalEmpty);
  row++;

  // Диапазон
  ws['!ref'] = XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: row - 1, c: colCount - 1 } });

  // Ширины колонок
  ws['!cols'] = [
    { wch: 7 },   // №
    { wch: 62 },  // Адрес
    { wch: 5 },   // Дн
    { wch: 21 }, { wch: 21 }, { wch: 21 }, { wch: 21 }, { wch: 21 }, { wch: 21 },
    { wch: 24 },  // Комментарий
  ];

  // Высота строк
  ws['!rows'] = [];
  ws['!rows'][0] = { hpt: 24 }; // Заголовок
  ws['!rows'][1] = { hpt: 22 }; // Шапка

  // Мержи
  ws['!merges'] = [
    { s: { r: 0, c: 0 }, e: { r: 0, c: colCount - 1 } }, // Заголовок
  ];
  // Мержи групп — динамически по реальным строкам
  let groupRow = 2; // после заголовка (0) и шапки (1)
  if (minsk.length) {
    ws['!merges'].push({ s: { r: groupRow, c: 0 }, e: { r: groupRow, c: colCount - 1 } });
    groupRow += 1 + minsk.length;
  }
  if (regions.length) {
    ws['!merges'].push({ s: { r: groupRow, c: 0 }, e: { r: groupRow, c: colCount - 1 } });
  }

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'График доставки');
  XLSX.writeFile(wb, `График_доставки_${date}.xlsx`);
}
