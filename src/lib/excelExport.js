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
    if (!item.finalOrder || item.finalOrder <= 0) return null;
    const qpb  = getQpb(item);
    const mult = getMultiplicity(item);
    const physBoxes = settings.unit === 'boxes'
      ? Math.ceil(item.finalOrder / mult)
      : Math.ceil(item.finalOrder / (qpb * mult));
    const pieces = settings.unit === 'pieces' ? item.finalOrder : physBoxes * qpb;
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
 * Экспорт аналитических отчётов в Excel — стилизованный, 4 листа
 */
export async function exportAnalyticsToExcel(analyticsData, seasonalityData) {
  const XLSX = await import('xlsx-js-style');
  const wb = XLSX.utils.book_new();

  // ═══ Палитра ═══
  const brown = '502314';
  const brownLight = 'F0EBE5';
  const orange = 'FF8732';
  const green = '2E7D32';
  const greenBg = 'E8F5E9';
  const red = 'D32F2F';
  const redBg = 'FFEBEE';
  const blue = '1565C0';
  const blueBg = 'E3F2FD';
  const grayBg = 'F5F5F5';
  const borderClr = 'E0D6CC';

  const border = { style: 'thin', color: { rgb: borderClr } };
  const borders = { top: border, bottom: border, left: border, right: border };

  // ═══ Стили ═══
  const sTitle = {
    font: { bold: true, sz: 16, color: { rgb: brown }, name: 'Calibri' },
    alignment: { vertical: 'center' },
  };
  const sSubtitle = {
    font: { sz: 11, color: { rgb: '888888' }, name: 'Calibri' },
  };
  const sHeader = {
    font: { bold: true, sz: 11, color: { rgb: 'FFFFFF' }, name: 'Calibri' },
    fill: { fgColor: { rgb: brown } },
    alignment: { horizontal: 'center', vertical: 'center', wrapText: true },
    border: borders,
  };
  const sHeaderLeft = { ...sHeader, alignment: { ...sHeader.alignment, horizontal: 'left' } };
  const sKpiLabel = {
    font: { bold: true, sz: 11, color: { rgb: brown }, name: 'Calibri' },
    fill: { fgColor: { rgb: brownLight } },
    alignment: { vertical: 'center' },
    border: borders,
  };
  const sKpiVal = {
    font: { bold: true, sz: 14, color: { rgb: brown }, name: 'Calibri' },
    alignment: { horizontal: 'center', vertical: 'center' },
    border: borders,
  };

  function sCell(stripe) {
    return {
      font: { sz: 11, name: 'Calibri' },
      fill: stripe ? { fgColor: { rgb: grayBg } } : undefined,
      alignment: { vertical: 'center' },
      border: borders,
    };
  }
  function sCellNum(stripe) {
    return {
      font: { bold: true, sz: 11, name: 'Calibri' },
      fill: stripe ? { fgColor: { rgb: grayBg } } : undefined,
      alignment: { horizontal: 'right', vertical: 'center' },
      border: borders,
      numFmt: '#,##0',
    };
  }
  function sDelta(val, stripe) {
    const isUp = val > 0;
    return {
      font: { bold: true, sz: 11, color: { rgb: isUp ? green : val < 0 ? red : '888888' }, name: 'Calibri' },
      fill: isUp ? { fgColor: { rgb: greenBg } } : val < 0 ? { fgColor: { rgb: redBg } } : (stripe ? { fgColor: { rgb: grayBg } } : undefined),
      alignment: { horizontal: 'center', vertical: 'center' },
      border: borders,
    };
  }
  function sRank(i) {
    const top3 = i < 3;
    return {
      font: { bold: true, sz: top3 ? 13 : 11, color: { rgb: top3 ? 'FFFFFF' : brown }, name: 'Calibri' },
      fill: { fgColor: { rgb: top3 ? orange : brownLight } },
      alignment: { horizontal: 'center', vertical: 'center' },
      border: borders,
    };
  }

  function setCell(ws, r, c, val, style) {
    const ref = XLSX.utils.encode_cell({ r, c });
    const t = typeof val === 'number' ? 'n' : 's';
    ws[ref] = { v: val, t, s: style };
  }
  function setRef(ws, maxRow, maxCol) {
    ws['!ref'] = XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: maxRow, c: maxCol } });
  }

  const date = new Date().toLocaleDateString('ru-RU');
  const period = analyticsData.period;

  // ═══════════════════════════
  // ЛИСТ 1: ОБЗОР
  // ═══════════════════════════
  const ws1 = {};
  let r = 0;
  setCell(ws1, r, 0, `Аналитика заказов — ${date}`, sTitle);
  r++;
  setCell(ws1, r, 0, `Период: ${period} дней  ·  Сравнение с предыдущими ${period} днями`, sSubtitle);
  r += 2;

  // KPI блок
  const kpis = [
    ['Заказов', analyticsData.totals.orders, analyticsData.prev.orders, analyticsData.deltaOrders],
    ['Коробок (всего)', Math.round(analyticsData.totals.boxes), Math.round(analyticsData.prev.boxes), analyticsData.deltaBoxes],
    ['Ср. коробок / заказ', analyticsData.totals.orders ? Math.round(analyticsData.totals.boxes / analyticsData.totals.orders) : 0, null, null],
  ];

  setCell(ws1, r, 0, 'Показатель', sHeader);
  setCell(ws1, r, 1, `Последние ${period} дн.`, sHeader);
  setCell(ws1, r, 2, `Предыдущие ${period} дн.`, sHeader);
  setCell(ws1, r, 3, 'Изменение', sHeader);
  r++;

  kpis.forEach(([label, cur, prev, delta]) => {
    setCell(ws1, r, 0, label, sKpiLabel);
    setCell(ws1, r, 1, cur, sKpiVal);
    setCell(ws1, r, 2, prev !== null ? prev : '', { ...sKpiVal, font: { ...sKpiVal.font, sz: 11, color: { rgb: '888888' } } });
    if (delta !== null) {
      setCell(ws1, r, 3, (delta >= 0 ? '+' : '') + delta + '%', sDelta(delta));
    } else {
      setCell(ws1, r, 3, '—', sCell(false));
    }
    r++;
  });

  // План-факт блок
  if (analyticsData.planFact && analyticsData.planFact.receivedOrders > 0) {
    const pf = analyticsData.planFact;
    r += 2;
    setCell(ws1, r, 0, 'Выполнение заказов (план-факт)', { ...sTitle, font: { ...sTitle.font, sz: 13 } });
    r += 2;
    setCell(ws1, r, 0, 'Показатель', sHeader);
    setCell(ws1, r, 1, 'Значение', sHeader);
    r++;
    const pfRows = [
      ['Принято заказов', pf.receivedOrders],
      ['Ожидают приёмки', pf.pendingOrders],
      ['План (коробок)', pf.planBoxes],
      ['Факт (коробок)', pf.factBoxes],
      ['Выполнение', pf.fulfillmentPct + '%'],
      ['Расхождений', pf.discrepancyItems + ' из ' + pf.totalReceivedItems + ' позиций'],
    ];
    pfRows.forEach(([label, val], i) => {
      const stripe = i % 2 === 1;
      setCell(ws1, r, 0, label, sKpiLabel);
      if (label === 'Выполнение') {
        const pct = pf.fulfillmentPct;
        setCell(ws1, r, 1, val, { ...sKpiVal, font: { ...sKpiVal.font, color: { rgb: pct >= 95 ? green : pct >= 80 ? 'E65100' : red } } });
      } else {
        setCell(ws1, r, 1, val, typeof val === 'number' ? sKpiVal : { ...sCell(false), alignment: { horizontal: 'center', vertical: 'center' } });
      }
      r++;
    });
  }

  setRef(ws1, r, 3);
  ws1['!cols'] = [{ wch: 28 }, { wch: 18 }, { wch: 18 }, { wch: 14 }];
  ws1['!rows'] = [{ hpt: 24 }];
  ws1['!merges'] = [
    { s: { r: 0, c: 0 }, e: { r: 0, c: 3 } },
    { s: { r: 1, c: 0 }, e: { r: 1, c: 3 } },
  ];
  XLSX.utils.book_append_sheet(wb, ws1, 'Обзор');

  // ═══════════════════════════
  // ЛИСТ 2: ТОП ТОВАРОВ
  // ═══════════════════════════
  const ws2 = {};
  r = 0;
  setCell(ws2, r, 0, `Топ товаров за ${period} дней`, sTitle);
  r += 2;

  ['№', 'Артикул', 'Наименование', 'Коробок', 'Заказов', 'Δ к прошл.', 'Прогноз'].forEach((h, c) => {
    setCell(ws2, r, c, h, c <= 2 ? sHeaderLeft : sHeader);
  });
  r++;

  analyticsData.topProducts.forEach((p, i) => {
    const stripe = i % 2 === 1;
    setCell(ws2, r, 0, i + 1, sRank(i));
    setCell(ws2, r, 1, p.sku || '—', sCell(stripe));
    setCell(ws2, r, 2, p.name || '—', { ...sCell(stripe), font: { bold: true, sz: 11, name: 'Calibri' } });
    setCell(ws2, r, 3, Math.round(p.boxes), sCellNum(stripe));
    setCell(ws2, r, 4, p.orders, sCellNum(stripe));
    if (p.deltaBoxes !== null) {
      setCell(ws2, r, 5, (p.deltaBoxes >= 0 ? '+' : '') + p.deltaBoxes + '%', sDelta(p.deltaBoxes, stripe));
    } else {
      setCell(ws2, r, 5, '—', sCell(stripe));
    }
    setCell(ws2, r, 6, '~' + p.forecast, { ...sCellNum(stripe), font: { ...sCellNum(stripe).font, color: { rgb: blue } } });
    r++;
  });

  setRef(ws2, r, 6);
  ws2['!cols'] = [{ wch: 5 }, { wch: 14 }, { wch: 40 }, { wch: 12 }, { wch: 10 }, { wch: 12 }, { wch: 12 }];
  ws2['!rows'] = [{ hpt: 24 }];
  ws2['!merges'] = [{ s: { r: 0, c: 0 }, e: { r: 0, c: 6 } }];
  XLSX.utils.book_append_sheet(wb, ws2, 'Топ товаров');

  // ═══════════════════════════
  // ЛИСТ 3: ПОСТАВЩИКИ
  // ═══════════════════════════
  const ws3 = {};
  r = 0;
  setCell(ws3, r, 0, `Поставщики за ${period} дней`, sTitle);
  r += 2;

  ['№', 'Поставщик', 'Заказов', 'Коробок', 'Ср./заказ', 'Δ к прошл.', 'Посл. заказ'].forEach((h, c) => {
    setCell(ws3, r, c, h, c <= 1 ? sHeaderLeft : sHeader);
  });
  r++;

  analyticsData.suppliers.forEach((s, i) => {
    const stripe = i % 2 === 1;
    const delta = s.prevBoxes > 0 ? Math.round((s.boxes - s.prevBoxes) / s.prevBoxes * 100) : null;
    setCell(ws3, r, 0, i + 1, sRank(i));
    setCell(ws3, r, 1, s.supplier, { ...sCell(stripe), font: { bold: true, sz: 11, name: 'Calibri' } });
    setCell(ws3, r, 2, s.orders, sCellNum(stripe));
    setCell(ws3, r, 3, Math.round(s.boxes), sCellNum(stripe));
    setCell(ws3, r, 4, s.orders ? Math.round(s.boxes / s.orders) : 0, sCellNum(stripe));
    if (delta !== null) {
      setCell(ws3, r, 5, (delta >= 0 ? '+' : '') + delta + '%', sDelta(delta, stripe));
    } else {
      setCell(ws3, r, 5, '—', sCell(stripe));
    }
    setCell(ws3, r, 6, s.daysAgo !== null ? s.daysAgo + ' дн. назад' : '—', sCell(stripe));
    r++;
  });

  setRef(ws3, r, 6);
  ws3['!cols'] = [{ wch: 5 }, { wch: 32 }, { wch: 10 }, { wch: 12 }, { wch: 12 }, { wch: 12 }, { wch: 16 }];
  ws3['!rows'] = [{ hpt: 24 }];
  ws3['!merges'] = [{ s: { r: 0, c: 0 }, e: { r: 0, c: 6 } }];
  XLSX.utils.book_append_sheet(wb, ws3, 'Поставщики');

  // ═══════════════════════════
  // ЛИСТ 4: СЕЗОННОСТЬ
  // ═══════════════════════════
  if (seasonalityData && seasonalityData.monthData) {
    const ws4 = {};
    r = 0;
    setCell(ws4, r, 0, 'Сезонность (12 месяцев)', sTitle);
    r += 2;

    ['Месяц', 'Коробок', 'Заказов', 'Скольз. среднее', 'Год к году'].forEach((h, c) => {
      setCell(ws4, r, c, h, sHeader);
    });
    r++;

    const maxBoxes = seasonalityData.maxBoxes || 1;
    seasonalityData.monthData.forEach((m, i) => {
      const stripe = i % 2 === 1;
      setCell(ws4, r, 0, m.label, { ...sCell(stripe), font: { bold: true, sz: 11, name: 'Calibri' } });
      setCell(ws4, r, 1, Math.round(m.boxes), {
        ...sCellNum(stripe),
        font: { bold: true, sz: 12, color: { rgb: orange }, name: 'Calibri' },
      });
      setCell(ws4, r, 2, m.orders, sCellNum(stripe));
      setCell(ws4, r, 3, m.movingAvg !== null ? Math.round(m.movingAvg) : '—',
        m.movingAvg !== null ? sCellNum(stripe) : sCell(stripe));
      if (m.yoyDelta !== null) {
        setCell(ws4, r, 4, (m.yoyDelta >= 0 ? '+' : '') + m.yoyDelta + '%', sDelta(m.yoyDelta, stripe));
      } else {
        setCell(ws4, r, 4, '—', sCell(stripe));
      }
      r++;
    });

    setRef(ws4, r, 4);
    ws4['!cols'] = [{ wch: 12 }, { wch: 14 }, { wch: 10 }, { wch: 16 }, { wch: 14 }];
    ws4['!rows'] = [{ hpt: 24 }];
    ws4['!merges'] = [{ s: { r: 0, c: 0 }, e: { r: 0, c: 4 } }];
    XLSX.utils.book_append_sheet(wb, ws4, 'Сезонность');
  }

  // ═══════════════════════════
  // ЛИСТ 5: АНОМАЛИИ
  // ═══════════════════════════
  if (analyticsData.anomalies && analyticsData.anomalies.length) {
    const ws5 = {};
    r = 0;
    setCell(ws5, r, 0, `Аномалии за ${period} дней`, sTitle);
    r += 2;

    ['Тип', 'Важность', 'Название', 'Описание', 'Подробности'].forEach((h, c) => {
      setCell(ws5, r, c, h, sHeader);
    });
    r++;

    const typeLabels = { spike: 'Рост расхода', drop: 'Падение расхода', supplier: 'Поставщик', outlier: 'Необычный заказ' };
    const sevLabels = { danger: 'Критично', warning: 'Внимание', info: 'Информация' };
    const sevColors = { danger: { bg: redBg, fg: red }, warning: { bg: 'FFF3E0', fg: 'E65100' }, info: { bg: grayBg, fg: '616161' } };

    analyticsData.anomalies.forEach((a, i) => {
      const stripe = i % 2 === 1;
      const sc = sevColors[a.severity] || sevColors.info;
      setCell(ws5, r, 0, typeLabels[a.type] || a.type, sCell(stripe));
      setCell(ws5, r, 1, sevLabels[a.severity] || '', {
        font: { bold: true, sz: 11, color: { rgb: sc.fg }, name: 'Calibri' },
        fill: { fgColor: { rgb: sc.bg } },
        alignment: { horizontal: 'center', vertical: 'center' },
        border: borders,
      });
      setCell(ws5, r, 2, a.title || '', { ...sCell(stripe), font: { bold: true, sz: 11, name: 'Calibri' } });
      setCell(ws5, r, 3, a.text || '', sCell(stripe));
      setCell(ws5, r, 4, a.detail || '', { ...sCell(stripe), font: { sz: 10, color: { rgb: '888888' }, name: 'Calibri' } });
      r++;
    });

    setRef(ws5, r, 4);
    ws5['!cols'] = [{ wch: 18 }, { wch: 12 }, { wch: 30 }, { wch: 50 }, { wch: 40 }];
    ws5['!rows'] = [{ hpt: 24 }];
    ws5['!merges'] = [{ s: { r: 0, c: 0 }, e: { r: 0, c: 4 } }];
    XLSX.utils.book_append_sheet(wb, ws5, 'Аномалии');
  }

  XLSX.writeFile(wb, `Аналитика_${date}.xlsx`);
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
