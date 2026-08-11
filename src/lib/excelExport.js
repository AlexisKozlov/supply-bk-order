import { getQpb, getMultiplicity, toAccountingBoxes, toPhysicalBoxes, applyEntityGroupFilter, escapeHtml } from './utils.js';
import { formatRestaurantNumber, ENTITY_SHORT_NAMES } from './legalEntities.js';
import { db } from './apiClient.js';

/**
 * Подтягивает external_code для тех позиций, у которых его нет (загружены
 * из старого черновика, добавлены до релиза с новой логикой и т.д.).
 * Мутирует переданный массив items: добавляет externalCode где не было.
 */
async function enrichExternalCodes(items, legalEntity) {
  const missing = items.filter(it => it && it.sku && !it.externalCode);
  if (!missing.length) return;
  const skus = Array.from(new Set(missing.map(it => it.sku)));
  try {
    let q = db.from('products').select('sku, external_code').in('sku', skus);
    q = applyEntityGroupFilter(q, legalEntity);
    const { data } = await q;
    if (!data || !data.length) return;
    const map = Object.fromEntries(data.map(p => [p.sku, p.external_code || '']));
    for (const it of missing) {
      if (map[it.sku]) it.externalCode = map[it.sku];
    }
  } catch (e) {
    // Сетевые ошибки не должны мешать формированию Excel — просто отдадим без кодов.
    console.warn('[excelExport] enrichExternalCodes failed:', e);
  }
}

async function buildOrderWorkbook(items, settings, priceMap) {
  // Сначала добиваем external_code тем позициям, у которых его не было —
  // безопасно, мутируем только пустые поля.
  await enrichExternalCodes(items, settings.legalEntity);

  const XLSX = await import('xlsx-js-style');
  const nf = new Intl.NumberFormat('ru-RU');

  const supplier     = settings.supplier || 'Все';
  const deliveryDate = settings.deliveryDate?.toLocaleDateString('ru-RU') || '';
  const legalEntity  = settings.legalEntity || '';

  // Палитра
  const brown = '502314';
  const brownLight = 'F0EBE5';
  const orange = 'FF8732';
  const cream = 'FFF8F0';
  const borderClr = 'E0D6CC';
  const border = { style: 'thin', color: { rgb: borderClr } };
  const borders = { top: border, bottom: border, left: border, right: border };

  const sTitle = { font: { bold: true, sz: 16, color: { rgb: brown }, name: 'Calibri' }, alignment: { vertical: 'center' } };
  const sInfo = { font: { sz: 11, color: { rgb: '666666' }, name: 'Calibri' }, alignment: { vertical: 'center' } };
  const sInfoBold = { font: { bold: true, sz: 11, color: { rgb: brown }, name: 'Calibri' }, alignment: { vertical: 'center' } };
  const sHeader = {
    font: { bold: true, sz: 11, color: { rgb: 'FFFFFF' }, name: 'Calibri' },
    fill: { fgColor: { rgb: brown } },
    alignment: { horizontal: 'center', vertical: 'center' },
    border: borders,
  };
  const sHeaderLeft = { ...sHeader, alignment: { ...sHeader.alignment, horizontal: 'left' } };

  function sCell(stripe) {
    return {
      font: { sz: 11, name: 'Calibri' },
      fill: stripe ? { fgColor: { rgb: cream } } : undefined,
      alignment: { vertical: 'center' },
      border: borders,
    };
  }
  function sOrder(stripe) {
    return {
      font: { bold: true, sz: 11, color: { rgb: brown }, name: 'Calibri' },
      fill: stripe ? { fgColor: { rgb: cream } } : undefined,
      alignment: { horizontal: 'center', vertical: 'center' },
      border: borders,
    };
  }
  const sTotalLabel = {
    font: { bold: true, sz: 12, color: { rgb: 'FFFFFF' }, name: 'Calibri' },
    fill: { fgColor: { rgb: brown } },
    alignment: { horizontal: 'right', vertical: 'center' },
    border: borders,
  };
  const sTotalVal = {
    font: { bold: true, sz: 13, color: { rgb: 'FFFFFF' }, name: 'Calibri' },
    fill: { fgColor: { rgb: brown } },
    alignment: { horizontal: 'center', vertical: 'center' },
    border: borders,
  };

  function setCell(ws, r, c, val, style) {
    const ref = XLSX.utils.encode_cell({ r, c });
    ws[ref] = { v: val, t: typeof val === 'number' ? 'n' : 's', s: style };
  }

  const ws = {};
  let r = 0;

  // Заголовок
  setCell(ws, r, 0, `Заказ — ${supplier}`, sTitle);
  r++;
  setCell(ws, r, 0, `Дата прихода: ${deliveryDate}`, sInfo);
  r++;
  setCell(ws, r, 0, `Юр. лицо: ${legalEntity}`, sInfo);
  r += 2;

  // Шапка таблицы. Колонки: Внешний код | Наименование | Кор. | Штук | Паллеты.
  // Цены/Суммы в Excel не выводим — поставщику они не нужны.
  setCell(ws, r, 0, 'Внешний код', sHeader);
  setCell(ws, r, 1, 'Наименование', sHeaderLeft);
  setCell(ws, r, 2, 'Кор.', sHeader);
  setCell(ws, r, 3, 'Штук', sHeader);
  setCell(ws, r, 4, 'Паллеты', sHeader);
  r++;

  // Данные
  let totalBoxes = 0;
  let totalPieces = 0;
  let totalPallets = 0;
  let totalBoxesLeft = 0;
  let count = 0;
  items.forEach(item => {
    if (!item.finalOrder || item.finalOrder <= 0) return;
    const qpb  = getQpb(item);
    const accountingBoxes = toAccountingBoxes(item, item.finalOrder, settings.unit);
    const physBoxes = toPhysicalBoxes(item, item.finalOrder, settings.unit);
    const pieces = settings.unit === 'pieces' ? item.finalOrder : accountingBoxes * qpb;
    const piecesInt = Math.round(pieces);
    const nameWithSku = item.sku ? `${item.sku}  ${item.name || ''}` : (item.name || '');
    const stripe = count % 2 === 1;
    const bpp = item.boxesPerPallet || 0;
    const pallets = bpp > 0 ? Math.floor(physBoxes / bpp) : 0;
    const boxesLeft = bpp > 0 ? physBoxes % bpp : physBoxes;

    setCell(ws, r, 0, item.externalCode || '', sCell(stripe));
    setCell(ws, r, 1, nameWithSku, sCell(stripe));
    setCell(ws, r, 2, physBoxes, sOrder(stripe));
    setCell(ws, r, 3, piecesInt, sOrder(stripe));
    if (bpp > 0 && pallets > 0) {
      setCell(ws, r, 4, `${pallets} пал${boxesLeft ? ' + ' + boxesLeft + ' кор' : ''}`, sOrder(stripe));
    } else if (bpp > 0) {
      setCell(ws, r, 4, `${physBoxes} кор`, sOrder(stripe));
    } else {
      setCell(ws, r, 4, '—', sOrder(stripe));
    }
    totalBoxes += physBoxes;
    totalPieces += piecesInt;
    totalPallets += pallets;
    totalBoxesLeft += boxesLeft;
    count++;
    r++;
  });

  // Строка итого
  if (count > 0) {
    setCell(ws, r, 0, '', sTotalLabel);
    setCell(ws, r, 1, 'ИТОГО:', sTotalLabel);
    setCell(ws, r, 2, totalBoxes, sTotalVal);
    setCell(ws, r, 3, totalPieces, sTotalVal);
    const palletsSummary = totalPallets > 0
      ? `${totalPallets} пал${totalBoxesLeft ? ' + ' + totalBoxesLeft + ' кор' : ''}`
      : `${totalBoxes} кор`;
    setCell(ws, r, 4, palletsSummary, sTotalVal);
    r++;
  }

  // Диапазон, ширины, мержи
  const lastCol = 4;
  ws['!ref'] = XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: r - 1, c: lastCol } });
  ws['!cols'] = [
    { wch: 14 }, // Внешний код
    { wch: 55 }, // Наименование
    { wch: 10 }, // Кор.
    { wch: 10 }, // Штук
    { wch: 20 }, // Паллеты
  ];
  ws['!rows'] = [{ hpt: 24 }];
  ws['!merges'] = [
    { s: { r: 0, c: 0 }, e: { r: 0, c: lastCol } },
    { s: { r: 1, c: 0 }, e: { r: 1, c: lastCol } },
    { s: { r: 2, c: 0 }, e: { r: 2, c: lastCol } },
  ];

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Заказ');

  const dd = settings.deliveryDate || new Date();
  const fileDate = `${String(dd.getDate()).padStart(2,'0')}-${String(dd.getMonth()+1).padStart(2,'0')}-${dd.getFullYear()}`;
  const filename = `Заказ_${supplier}_${fileDate}.xlsx`;
  return { XLSX, wb, filename };
}

export async function exportToExcel(items, settings, priceMap) {
  const { XLSX, wb, filename } = await buildOrderWorkbook(items, settings, priceMap);
  XLSX.writeFile(wb, filename);
}

/**
 * Бинарный буфер заказа (для отправки во вложении письма).
 * Возвращает { buffer: Uint8Array, filename, mime }.
 */
export async function buildOrderXlsxBuffer(items, settings, priceMap) {
  const { XLSX, wb, filename } = await buildOrderWorkbook(items, settings, priceMap);
  const arr = XLSX.write(wb, { type: 'array', bookType: 'xlsx' });
  return {
    buffer: arr instanceof Uint8Array ? arr : new Uint8Array(arr),
    filename,
    mime: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  };
}

/**
 * Экспорт карточек товаров в Excel
 */
export async function exportProductsToExcel(products, legalEntity) {
  const XLSX = await import('xlsx-js-style');

  // Заголовки синхронизированы с ImportCardsModal.vue → COLUMN_MAP,
  // чтобы экспортированный файл можно было отредактировать и залить обратно.
  const headerRow = [
    'Артикул', 'Внешний код', 'Штрихкод', 'Наименование', 'Поставщик',
    'Коэффициент единицы для отчетов', 'Единица хранения',
    'Количество кор. в паллете', 'Кратность',
    'Вес нетто (кг)', 'Вес брутто (кг)',
    'Прослеживаемый', 'Активная',
    'Группа аналогов', 'Хранение',
  ];

  const dataRows = products.map(p => [
    p.sku || '',
    p.external_code || '',
    p.gtin || '',
    p.name || '',
    p.supplier || '',
    p.qty_per_box || '',
    p.unit_of_measure || 'шт',
    p.boxes_per_pallet || '',
    p.multiplicity || '',
    p.weight_netto || '',
    p.weight_brutto || '',
    (p.is_traceable === 1 || p.is_traceable === '1') ? 'Да' : 'Нет',
    (p.is_active === 0 || p.is_active === '0') ? 'Нет' : 'Да',
    p.analog_group || '',
    p.category || '',
  ]);

  const ws = XLSX.utils.aoa_to_sheet([headerRow, ...dataRows]);
  ws['!cols'] = [
    { wch: 14 }, { wch: 14 }, { wch: 16 }, { wch: 45 }, { wch: 25 },
    { wch: 14 }, { wch: 12 },
    { wch: 14 }, { wch: 10 },
    { wch: 12 }, { wch: 12 },
    { wch: 14 }, { wch: 10 },
    { wch: 25 }, { wch: 12 },
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

  ['№', 'Товар', 'Коробок', 'Заказов', 'Δ к прошл.', 'Прогноз'].forEach((h, c) => {
    setCell(ws2, r, c, h, c <= 1 ? sHeaderLeft : sHeader);
  });
  r++;

  analyticsData.topProducts.forEach((p, i) => {
    const stripe = i % 2 === 1;
    const productLabel = p.sku ? `${p.sku}  ${p.name || ''}` : (p.name || '—');
    setCell(ws2, r, 0, i + 1, sRank(i));
    setCell(ws2, r, 1, productLabel, { ...sCell(stripe), font: { bold: true, sz: 11, name: 'Calibri' } });
    setCell(ws2, r, 2, Math.round(p.boxes), sCellNum(stripe));
    setCell(ws2, r, 3, p.orders, sCellNum(stripe));
    if (p.deltaBoxes !== null) {
      setCell(ws2, r, 4, (p.deltaBoxes >= 0 ? '+' : '') + p.deltaBoxes + '%', sDelta(p.deltaBoxes, stripe));
    } else {
      setCell(ws2, r, 4, '—', sCell(stripe));
    }
    setCell(ws2, r, 5, '~' + p.forecast, { ...sCellNum(stripe), font: { ...sCellNum(stripe).font, color: { rgb: blue } } });
    r++;
  });

  setRef(ws2, r, 5);
  ws2['!cols'] = [{ wch: 5 }, { wch: 50 }, { wch: 12 }, { wch: 10 }, { wch: 12 }, { wch: 12 }];
  ws2['!rows'] = [{ hpt: 24 }];
  ws2['!merges'] = [{ s: { r: 0, c: 0 }, e: { r: 0, c: 5 } }];
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
  // ЛИСТ 5: ИЗМЕНЕНИЯ
  // ═══════════════════════════
  if (analyticsData.changes && analyticsData.changes.length) {
    const ws5 = {};
    r = 0;
    setCell(ws5, r, 0, `Изменения за ${period} дней`, sTitle);
    r += 2;

    ['Тип', 'Важность', 'Название', 'Описание', 'Подробности'].forEach((h, c) => {
      setCell(ws5, r, c, h, sHeader);
    });
    r++;

    const typeLabels = { disappeared: 'Пропал товар', low_stock: 'Заканчивается' };
    const sevLabels = { danger: 'Критично', warning: 'Внимание' };
    const sevColors = { danger: { bg: redBg, fg: red }, warning: { bg: 'FFF3E0', fg: 'E65100' } };

    analyticsData.changes.forEach((a, i) => {
      const stripe = i % 2 === 1;
      const sc = sevColors[a.severity] || sevColors.warning;
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
    XLSX.utils.book_append_sheet(wb, ws5, 'Изменения');
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
    fill: { fgColor: { rgb: brown } },
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

  // Среднее количество доставок по группе
  function groupAvg(items) {
    if (!items.length) return 0;
    let total = 0;
    for (const r of items) {
      const rSched = scheduleByRestaurant.get(String(r.id));
      total += rSched ? rSched.size : 0;
    }
    return (total / items.length).toFixed(1).replace(/\.0$/, '');
  }

  // Данные с группами
  function writeGroup(label, items) {
    // Строка группы
    for (let c = 0; c < colCount; c++) {
      setCell(row, c, c === 0 ? `${label} (${items.length}) — сред. ${groupAvg(items)} дост./нед.` : '', sGroup);
    }
    row++;

    // Строки ресторанов
    items.forEach((r, idx) => {
      const stripe = idx % 2 === 1;
      const rSched = scheduleByRestaurant.get(String(r.id));
      const cnt = rSched ? rSched.size : 0;

      setCell(row, 0, formatRestaurantNumber(r.number) || (r.number || ''), sNum(stripe));
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

  // ═══ Лист 2: Списочная часть по дням (3 сверху + 3 снизу, альбомная) ═══
  const ws2 = {};
  const colsPerDay = 3; // №, Адрес, Время
  const gapCols = 1;    // разделитель между днями
  const daysPerRow = 3; // 3 дня в ряду
  const totalCols2 = daysPerRow * colsPerDay + (daysPerRow - 1) * gapCols; // 11

  function setCell2(r, c, val, style) {
    const ref = XLSX.utils.encode_cell({ r, c });
    ws2[ref] = { v: val, t: typeof val === 'number' ? 'n' : 's', s: style };
  }

  // Собираем рестораны по дням
  const dayData = [];
  for (let d = 1; d <= 6; d++) {
    const dayRests = [];
    for (const r of restaurants) {
      const time = scheduleByRestaurant.get(String(r.id))?.get(d)?.delivery_time;
      if (time) dayRests.push({ ...r, delivery_time: time });
    }
    dayData.push(dayRests);
  }

  const ws2Merges = [];

  const sDayHeader = {
    font: { bold: true, sz: 12, color: { rgb: 'FFFFFF' }, name: 'Calibri' },
    fill: { fgColor: { rgb: brown } },
    alignment: { horizontal: 'center', vertical: 'center' },
    border: borders,
  };
  const sSubHeader = {
    font: { bold: true, sz: 10, color: { rgb: '666666' }, name: 'Calibri' },
    fill: { fgColor: { rgb: brownBg } },
    alignment: { horizontal: 'center', vertical: 'center' },
    border: borders,
  };

  // Строка 0: общий заголовок
  setCell2(0, 0, `Списочная часть графика — ${date}`, {
    font: { bold: true, sz: 16, color: { rgb: brown }, name: 'Calibri' },
  });
  ws2Merges.push({ s: { r: 0, c: 0 }, e: { r: 0, c: totalCols2 - 1 } });

  let curRow = 2; // начинаем после заголовка + пустой строки

  // Два ряда: дни 0-2 (ПН-СР) и дни 3-5 (ЧТ-СБ)
  for (let band = 0; band < 2; band++) {
    const bandDays = [band * 3, band * 3 + 1, band * 3 + 2]; // индексы в dayData
    const maxInBand = Math.max(...bandDays.map(di => dayData[di].length));

    // Заголовки дней
    for (let col = 0; col < daysPerRow; col++) {
      const di = bandDays[col];
      const startCol = col * (colsPerDay + gapCols);
      const label = `${dayNames[di]} (${dayData[di].length})`;
      setCell2(curRow, startCol, label, sDayHeader);
      setCell2(curRow, startCol + 1, '', sDayHeader);
      setCell2(curRow, startCol + 2, '', sDayHeader);
      ws2Merges.push({ s: { r: curRow, c: startCol }, e: { r: curRow, c: startCol + 2 } });
    }
    curRow++;

    // Подзаголовки
    for (let col = 0; col < daysPerRow; col++) {
      const startCol = col * (colsPerDay + gapCols);
      setCell2(curRow, startCol, '№', sSubHeader);
      setCell2(curRow, startCol + 1, 'Адрес', sSubHeader);
      setCell2(curRow, startCol + 2, 'Время', sSubHeader);
    }
    curRow++;

    // Данные
    for (let i = 0; i < maxInBand; i++) {
      const stripe = i % 2 === 1;
      for (let col = 0; col < daysPerRow; col++) {
        const di = bandDays[col];
        const startCol = col * (colsPerDay + gapCols);
        const r = dayData[di][i];
        if (r) {
          setCell2(curRow, startCol, formatRestaurantNumber(r.number) || (r.number || ''), sNum(stripe));
          setCell2(curRow, startCol + 1, r.address || '', { ...sAddr(stripe), font: { ...sAddr(stripe).font, sz: 11 } });
          setCell2(curRow, startCol + 2, r.delivery_time || '', sDay(true, stripe));
        } else {
          setCell2(curRow, startCol, '', sEmpty(stripe));
          setCell2(curRow, startCol + 1, '', sEmpty(stripe));
          setCell2(curRow, startCol + 2, '', sEmpty(stripe));
        }
      }
      curRow++;
    }

    curRow += 2; // отступ между верхним и нижним рядом
  }

  ws2['!ref'] = XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: Math.max(curRow - 1, 2), c: totalCols2 - 1 } });
  ws2['!merges'] = ws2Merges;

  // Ширины колонок
  const cols2 = [];
  for (let d = 0; d < daysPerRow; d++) {
    cols2.push({ wch: 5 });   // №
    cols2.push({ wch: 45 });  // Адрес
    cols2.push({ wch: 16 });  // Время
    if (d < daysPerRow - 1) cols2.push({ wch: 2 }); // разделитель
  }
  ws2['!cols'] = cols2;

  // Альбомная ориентация
  ws2['!pageSetup'] = { orientation: 'landscape', paperSize: 9, fitToWidth: 1, fitToHeight: 0 };
  ws2['!printOptions'] = { horizontalCentered: true };

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'График доставки');
  XLSX.utils.book_append_sheet(wb, ws2, 'Списочная часть');
  XLSX.writeFile(wb, `График_доставки_${date}.xlsx`);
}

/**
 * Заявка поставщику — Excel
 */
export async function exportSupplierOrder(settings, items, priceMap) {
  const XLSX = await import('xlsx-js-style');
  const nf = new Intl.NumberFormat('ru-RU');

  const supplier = settings.supplier || '';
  const legalEntity = settings.legalEntity || '';
  const deliveryDate = settings.deliveryDate
    ? settings.deliveryDate.toLocaleDateString('ru-RU')
    : '';
  const today = new Date().toLocaleDateString('ru-RU');
  const userName = settings.userName || '';

  // Палитра
  const darkGray = '333333';
  const borderClr = 'AAAAAA';
  const border = { style: 'thin', color: { rgb: borderClr } };
  const borders = { top: border, bottom: border, left: border, right: border };

  const sTitle = {
    font: { bold: true, sz: 14, color: { rgb: darkGray }, name: 'Calibri' },
    alignment: { horizontal: 'center', vertical: 'center' },
  };
  const sMeta = {
    font: { sz: 11, color: { rgb: '444444' }, name: 'Calibri' },
    alignment: { vertical: 'center' },
  };
  const sMetaBold = {
    font: { bold: true, sz: 11, color: { rgb: darkGray }, name: 'Calibri' },
    alignment: { vertical: 'center' },
  };
  const sHeader = {
    font: { bold: true, sz: 11, color: { rgb: darkGray }, name: 'Calibri' },
    fill: { fgColor: { rgb: 'F0F0F0' } },
    alignment: { horizontal: 'center', vertical: 'center' },
    border: borders,
  };
  const sHeaderLeft = { ...sHeader, alignment: { ...sHeader.alignment, horizontal: 'left' } };

  function sCell() {
    return {
      font: { sz: 11, name: 'Calibri' },
      alignment: { vertical: 'center' },
      border: borders,
    };
  }
  function sCellRight() {
    return {
      font: { sz: 11, name: 'Calibri' },
      alignment: { horizontal: 'right', vertical: 'center' },
      border: borders,
    };
  }
  const sTotalLabel = {
    font: { bold: true, sz: 11, color: { rgb: darkGray }, name: 'Calibri' },
    alignment: { horizontal: 'right', vertical: 'center' },
    border: borders,
  };
  const sTotalVal = {
    font: { bold: true, sz: 11, color: { rgb: darkGray }, name: 'Calibri' },
    alignment: { horizontal: 'right', vertical: 'center' },
    border: borders,
  };

  function setCell(ws, r, c, val, style) {
    const ref = XLSX.utils.encode_cell({ r, c });
    ws[ref] = { v: val, t: typeof val === 'number' ? 'n' : 's', s: style };
  }

  const hasPrices = priceMap && Object.keys(priceMap).length > 0;
  const lastCol = hasPrices ? 4 : 2;

  const ws = {};
  let r = 0;

  // Заголовок
  setCell(ws, r, 0, 'Заявка на поставку', sTitle);
  r++;
  setCell(ws, r, 0, `От: ${legalEntity}`, sMetaBold);
  r++;
  setCell(ws, r, 0, `Поставщик: ${supplier}`, sMetaBold);
  r++;
  setCell(ws, r, 0, `Дата поставки: ${deliveryDate}`, sMeta);
  r++;
  setCell(ws, r, 0, `Дата заявки: ${today}`, sMeta);
  r++;
  r++; // пустая строка

  // Шапка таблицы
  const headerRow = r;
  setCell(ws, r, 0, '№п/п', sHeader);
  setCell(ws, r, 1, 'Товар', sHeaderLeft);
  setCell(ws, r, 2, 'Кол-во (кор.)', sHeader);
  if (hasPrices) {
    setCell(ws, r, 3, 'Цена', sHeader);
    setCell(ws, r, 4, 'Сумма', sHeader);
  }
  r++;

  // Данные
  let totalBoxes = 0;
  let totalSum = 0;
  let idx = 0;

  items.forEach(item => {
    if (!item.finalOrder || item.finalOrder <= 0) return;
    const qpb = getQpb(item);
    // Единое округление (Math.ceil) — иначе физ. коробки в Excel-заявке
    // расходятся с тем, что сохранено в БД и показано в UI.
    const accountingBoxes = toAccountingBoxes(item, item.finalOrder, settings.unit);
    const physBoxes = toPhysicalBoxes(item, item.finalOrder, settings.unit);

    idx++;
    const productLabel = item.sku ? `${item.sku}  ${item.name || ''}` : (item.name || '');
    setCell(ws, r, 0, idx, sCellRight());
    setCell(ws, r, 1, productLabel, sCell());
    setCell(ws, r, 2, physBoxes, sCellRight());

    if (hasPrices) {
      const pi = priceMap[item.sku];
      if (pi) {
        const price = parseFloat(pi.price) || 0;
        const pieces = settings.unit === 'pieces' ? item.finalOrder : accountingBoxes * qpb;
        let lineSum = 0;
        if (pi.unit_type === 'box') lineSum = price * physBoxes;
        else if (pi.unit_type === 'thousand') lineSum = price * pieces / 1000;
        else lineSum = price * pieces;
        setCell(ws, r, 3, price, { ...sCellRight(), numFmt: '#,##0.00' });
        setCell(ws, r, 4, lineSum, { ...sCellRight(), numFmt: '#,##0.00' });
        totalSum += lineSum;
      } else {
        setCell(ws, r, 3, '', sCell());
        setCell(ws, r, 4, '', sCell());
      }
    }

    totalBoxes += physBoxes;
    r++;
  });

  // ИТОГО
  if (idx > 0) {
    setCell(ws, r, 0, '', sTotalLabel);
    setCell(ws, r, 1, 'ИТОГО:', sTotalLabel);
    setCell(ws, r, 2, totalBoxes, sTotalVal);
    if (hasPrices) {
      setCell(ws, r, 3, '', sTotalLabel);
      setCell(ws, r, 4, totalSum, { ...sTotalVal, numFmt: '#,##0.00' });
      r++;
      // НДС
      let totalVat = 0;
      items.forEach(item => {
        if (!item.finalOrder || item.finalOrder <= 0) return;
        const pi = priceMap[item.sku];
        if (!pi) return;
        const qpb_ = getQpb(item);
        const ab_ = toAccountingBoxes(item, item.finalOrder, settings.unit);
        const pb_ = toPhysicalBoxes(item, item.finalOrder, settings.unit);
        const pc_ = settings.unit === 'pieces' ? item.finalOrder : ab_ * qpb_;
        const pr_ = parseFloat(pi.price) || 0;
        let ls = 0;
        if (pi.unit_type === 'box') ls = pr_ * pb_;
        else if (pi.unit_type === 'thousand') ls = pr_ * pc_ / 1000;
        else ls = pr_ * pc_;
        totalVat += ls * ((pi.vat_rate ?? 20) / 100);
      });
      setCell(ws, r, 0, '', sTotalLabel);
      setCell(ws, r, 1, 'НДС:', sTotalLabel);
      setCell(ws, r, 2, '', sTotalLabel);
      setCell(ws, r, 3, '', sTotalLabel);
      setCell(ws, r, 4, totalVat, { ...sTotalVal, numFmt: '#,##0.00' });
      r++;
      setCell(ws, r, 0, '', sTotalLabel);
      setCell(ws, r, 1, 'ИТОГО С НДС:', sTotalLabel);
      setCell(ws, r, 2, '', sTotalLabel);
      setCell(ws, r, 3, '', sTotalLabel);
      setCell(ws, r, 4, totalSum + totalVat, { ...sTotalVal, numFmt: '#,##0.00' });
    }
    r++;
  }

  r++; // пустая строка
  setCell(ws, r, 0, `Контактное лицо: ${userName}`, sMeta);
  r++;

  // Диапазон, ширины, мержи
  ws['!ref'] = XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: r - 1, c: lastCol } });
  ws['!cols'] = hasPrices
    ? [{ wch: 5 }, { wch: 45 }, { wch: 12 }, { wch: 12 }, { wch: 15 }]
    : [{ wch: 5 }, { wch: 45 }, { wch: 12 }];
  ws['!rows'] = [{ hpt: 22 }];
  // Мержи для заголовочных строк
  for (let mr = 0; mr <= 4; mr++) {
    ws['!merges'] = ws['!merges'] || [];
    ws['!merges'].push({ s: { r: mr, c: 0 }, e: { r: mr, c: lastCol } });
  }
  // Мерж для контактного лица
  ws['!merges'].push({ s: { r: r - 1, c: 0 }, e: { r: r - 1, c: lastCol } });

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Заявка');

  const fileDate = today.replace(/\./g, '-');
  XLSX.writeFile(wb, `Заявка_${supplier}_${fileDate}.xlsx`);
}

/**
 * Заявка поставщику — печать (PDF через iframe + print)
 */

export function printSupplierOrder(settings, items, priceMap) {
  const hasPrices = priceMap && Object.keys(priceMap).length > 0;

  const filteredItems = items.filter(i => i.finalOrder > 0);
  let totalBoxes = 0;
  let totalSum = 0;

  const rows = filteredItems.map((item, idx) => {
    const qpb = getQpb(item);
    const accountingBoxes = toAccountingBoxes(item, item.finalOrder, settings.unit);
    const physBoxes = toPhysicalBoxes(item, item.finalOrder, settings.unit);
    totalBoxes += physBoxes;

    let priceTd = '';
    let sumTd = '';
    if (hasPrices) {
      const pi = priceMap[item.sku];
      if (pi) {
        const price = parseFloat(pi.price) || 0;
        const pieces = settings.unit === 'pieces' ? item.finalOrder : accountingBoxes * qpb;
        let lineSum = 0;
        if (pi.unit_type === 'box') lineSum = price * physBoxes;
        else if (pi.unit_type === 'thousand') lineSum = price * pieces / 1000;
        else lineSum = price * pieces;
        totalSum += lineSum;
        priceTd = `<td class="right">${price.toFixed(2)}</td>`;
        sumTd = `<td class="right">${lineSum.toFixed(2)}</td>`;
      } else {
        priceTd = `<td></td>`;
        sumTd = `<td></td>`;
      }
    }

    const productHtml = item.sku ? `<span style="color:#888;font-size:11px;">${escapeHtml(item.sku)}</span> ${escapeHtml(item.name)}` : escapeHtml(item.name);
    return `<tr>
      <td style="text-align:center;">${idx + 1}</td>
      <td>${productHtml}</td>
      <td class="right">${physBoxes}</td>
      ${priceTd}${sumTd}
    </tr>`;
  }).join('');

  const deliveryDate = settings.deliveryDate
    ? settings.deliveryDate.toLocaleDateString('ru-RU')
    : '';
  const today = new Date().toLocaleDateString('ru-RU');

  const priceHeaders = hasPrices ? '<th style="width:80px;text-align:right;">Цена</th><th style="width:90px;text-align:right;">Сумма</th>' : '';
  let totalVatPrint = 0;
  if (hasPrices) {
    filteredItems.forEach(item => {
      const qpb = getQpb(item);
      const accountingBoxes = toAccountingBoxes(item, item.finalOrder, settings.unit);
      const physBoxes = toPhysicalBoxes(item, item.finalOrder, settings.unit);
      const pi = priceMap[item.sku];
      if (!pi) return;
      const price = parseFloat(pi.price) || 0;
      const pieces = settings.unit === 'pieces' ? item.finalOrder : accountingBoxes * qpb;
      let lineSum = 0;
      if (pi.unit_type === 'box') lineSum = price * physBoxes;
      else if (pi.unit_type === 'thousand') lineSum = price * pieces / 1000;
      else lineSum = price * pieces;
      totalVatPrint += lineSum * ((pi.vat_rate ?? 20) / 100);
    });
  }
  const priceTotals = hasPrices ? `<td></td><td class="right">${totalSum.toFixed(2)}</td>` : '';
  const vatRow = hasPrices ? `<tr class="total"><td colspan="${hasPrices ? 3 : 2}" style="text-align:right;">НДС:</td><td class="right"></td><td class="right">${totalVatPrint.toFixed(2)}</td></tr>` : '';
  const totalWithVatRow = hasPrices ? `<tr class="total"><td colspan="${hasPrices ? 3 : 2}" style="text-align:right;">ИТОГО С НДС:</td><td class="right"></td><td class="right">${(totalSum + totalVatPrint).toFixed(2)}</td></tr>` : '';
  const colSpan = hasPrices ? 3 : 2;

  const html = `<!DOCTYPE html>
<html><head><meta charset="utf-8">
<title>Заявка - ${escapeHtml(settings.supplier)}</title>
<style>
  body { font-family: Arial, sans-serif; font-size: 13px; padding: 30px; }
  h2 { text-align: center; margin-bottom: 20px; }
  .meta { margin-bottom: 16px; }
  .meta div { margin-bottom: 4px; }
  table { width: 100%; border-collapse: collapse; margin-top: 12px; }
  th, td { border: 1px solid #999; padding: 6px 8px; }
  th { background: #f0f0f0; font-weight: bold; }
  .total { font-weight: bold; }
  .right { text-align: right; }
  .footer { margin-top: 24px; font-size: 12px; color: #666; }
  @media print { body { padding: 15px; } }
</style>
</head><body>
<h2>Заявка на поставку</h2>
<div class="meta">
  <div><b>От:</b> ${escapeHtml(settings.legalEntity)}</div>
  <div><b>Поставщик:</b> ${escapeHtml(settings.supplier)}</div>
  <div><b>Дата поставки:</b> ${escapeHtml(deliveryDate)}</div>
  <div><b>Дата заявки:</b> ${escapeHtml(today)}</div>
</div>
<table>
  <thead><tr>
    <th style="width:40px;">No</th>
    <th>Товар</th>
    <th style="width:80px;text-align:right;">Кол-во, кор.</th>
    ${priceHeaders}
  </tr></thead>
  <tbody>${rows}</tbody>
  <tfoot><tr class="total">
    <td colspan="${colSpan}" style="text-align:right;">ИТОГО:</td>
    <td class="right">${totalBoxes}</td>
    ${priceTotals}
  </tr>${vatRow}${totalWithVatRow}</tfoot>
</table>
<div class="footer">Контактное лицо: ${escapeHtml(settings.userName)}</div>
</body></html>`;

  const iframe = document.createElement('iframe');
  iframe.style.cssText = 'position:fixed;left:-9999px;width:0;height:0;';
  document.body.appendChild(iframe);
  iframe.contentDocument.write(html);
  iframe.contentDocument.close();
  setTimeout(() => {
    iframe.contentWindow.print();
    setTimeout(() => document.body.removeChild(iframe), 2000);
  }, 300);
}

// ═══════════════════════════════════════════════════════════════════
// План оплат для казначея. Формат (столбцы):
//   Неделя | Поставщик | Валюта | Условия оплаты | Планируемая дата
//   оплаты | Сумма в RUB с НДС | Юрлицо (БК/ВМ/ПС)
// Плюс итоговая строка суммы. Оформление — в стиле портала.
// rows: массив supplier_payments (supplier, currency, payment_date, amount, legal_entity).
// period: { year, month } (month 1-12).
// ═══════════════════════════════════════════════════════════════════
function isoWeekNumber(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  if (isNaN(d)) return '';
  const date = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
  const dayNum = (date.getUTCDay() + 6) % 7;
  date.setUTCDate(date.getUTCDate() - dayNum + 3);
  const firstThursday = new Date(Date.UTC(date.getUTCFullYear(), 0, 4));
  const firstDayNum = (firstThursday.getUTCDay() + 6) % 7;
  firstThursday.setUTCDate(firstThursday.getUTCDate() - firstDayNum + 3);
  return 1 + Math.round((date - firstThursday) / (7 * 24 * 3600 * 1000));
}

function entityShort(legalEntity) {
  if (ENTITY_SHORT_NAMES[legalEntity]) return ENTITY_SHORT_NAMES[legalEntity];
  const s = String(legalEntity || '');
  if (s.includes('Пицца')) return 'ПС';
  if (s.includes('Воглия')) return 'ВМ';
  if (s.includes('Бургер')) return 'БК';
  return s;
}

export async function exportPaymentPlanXlsx(rows, period) {
  const XLSX = await import('xlsx-js-style');

  const brown = '502314';
  const cream = 'FFF8F0';
  const borderClr = 'E0D6CC';
  const b = { style: 'thin', color: { rgb: borderClr } };
  const borders = { top: b, bottom: b, left: b, right: b };
  const money = '#,##0.00';

  const sTitle = { font: { bold: true, sz: 16, color: { rgb: brown }, name: 'Calibri' }, alignment: { vertical: 'center' } };
  const sHeader = {
    font: { bold: true, sz: 11, color: { rgb: 'FFFFFF' }, name: 'Calibri' },
    fill: { fgColor: { rgb: brown } },
    alignment: { horizontal: 'center', vertical: 'center', wrapText: true },
    border: borders,
  };
  const sHeaderLeft = { ...sHeader, alignment: { ...sHeader.alignment, horizontal: 'left' } };
  const cell = (stripe, extra = {}) => ({
    font: { sz: 11, name: 'Calibri' },
    fill: stripe ? { fgColor: { rgb: cream } } : undefined,
    alignment: { vertical: 'center', ...(extra.alignment || {}) },
    border: borders,
    ...(extra.font ? { font: { sz: 11, name: 'Calibri', ...extra.font } } : {}),
  });
  const sTotalLabel = {
    font: { bold: true, sz: 12, color: { rgb: 'FFFFFF' }, name: 'Calibri' },
    fill: { fgColor: { rgb: brown } },
    alignment: { horizontal: 'right', vertical: 'center' },
    border: borders,
  };
  const sTotalVal = {
    font: { bold: true, sz: 12, color: { rgb: 'FFFFFF' }, name: 'Calibri' },
    fill: { fgColor: { rgb: brown } },
    alignment: { horizontal: 'right', vertical: 'center' },
    border: borders,
  };

  const ws = {};
  const put = (r, c, v, s, z) => {
    const ref = XLSX.utils.encode_cell({ r, c });
    ws[ref] = { v, t: typeof v === 'number' ? 'n' : 's', s };
    if (z) ws[ref].z = z;
  };

  const mm = String(period.month).padStart(2, '0');
  const monthLabel = `${mm}.${period.year}`;

  let r = 0;
  put(r, 0, `План оплат — ${monthLabel}`, sTitle);
  r += 2;

  const headers = ['Неделя', 'Поставщик', 'Валюта', 'Условия оплаты', 'Планируемая дата оплаты', 'Сумма в RUB с НДС', 'Юрлицо'];
  headers.forEach((h, c) => put(r, c, h, c === 1 ? sHeaderLeft : sHeader));
  r++;

  // Сортировка: по дате оплаты → поставщик → юрлицо
  const sorted = [...rows].sort((a, b2) => {
    const d = String(a.payment_date || '').localeCompare(String(b2.payment_date || ''));
    if (d) return d;
    const s = String(a.supplier || '').localeCompare(String(b2.supplier || ''), 'ru');
    if (s) return s;
    return entityShort(a.legal_entity).localeCompare(entityShort(b2.legal_entity), 'ru');
  });

  let total = 0;
  sorted.forEach((p, i) => {
    const stripe = i % 2 === 1;
    const amount = Number(p.amount) || 0;
    total += amount;
    const [yy, moo, dd] = String(p.payment_date || '').split('-');
    const dateStr = dd ? `${dd}.${moo}.${yy}` : '';
    put(r, 0, isoWeekNumber(p.payment_date), cell(stripe, { alignment: { horizontal: 'center' } }));
    put(r, 1, p.supplier_full || p.supplier || '', cell(stripe, { alignment: { horizontal: 'left', wrapText: true } }));
    put(r, 2, p.currency || 'RUB', cell(stripe, { alignment: { horizontal: 'center' } }));
    put(r, 3, 'Факт', cell(stripe, { alignment: { horizontal: 'center' } }));
    put(r, 4, dateStr, cell(stripe, { alignment: { horizontal: 'center' } }));
    put(r, 5, amount, cell(stripe, { alignment: { horizontal: 'right' } }), money);
    put(r, 6, entityShort(p.legal_entity), cell(stripe, { alignment: { horizontal: 'center' }, font: { bold: true } }));
    r++;
  });

  // Итог
  put(r, 0, '', sTotalLabel);
  put(r, 1, '', sTotalLabel);
  put(r, 2, '', sTotalLabel);
  put(r, 3, '', sTotalLabel);
  put(r, 4, 'Итого:', sTotalLabel);
  put(r, 5, total, sTotalVal, money);
  put(r, 6, '', sTotalVal);
  r++;

  ws['!ref'] = XLSX.utils.encode_range({ r: 0, c: 0 }, { r: r - 1, c: 6 });
  ws['!cols'] = [
    { wch: 8 },   // Неделя
    { wch: 42 },  // Поставщик (полное наименование)
    { wch: 9 },   // Валюта
    { wch: 16 },  // Условия оплаты
    { wch: 22 },  // Планируемая дата оплаты
    { wch: 20 },  // Сумма
    { wch: 9 },   // Юрлицо
  ];
  ws['!merges'] = [{ s: { r: 0, c: 0 }, e: { r: 0, c: 6 } }];
  ws['!freeze'] = { xSplit: 0, ySplit: 3 };

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'План оплат');
  XLSX.writeFile(wb, `План оплат ${monthLabel}.xlsx`);
}

// ═══════════════════════════════════════════════════════════════════
// Таблица аналогов — выгрузка для ОК/бухгалтерии. Столбцы:
//   Артикул | Наименование | Учётная единица (шт/кг/л в упаковке) |
//   Поставщик | Группа аналогов
// rows: [{ sku, name, measure, supplier, group }]. Оформление — стиль портала.
// ═══════════════════════════════════════════════════════════════════
export async function exportAnalogsXlsx(rows) {
  const XLSX = await import('xlsx-js-style');
  const brown = '502314', cream = 'FFF8F0', borderClr = 'E0D6CC';
  const b = { style: 'thin', color: { rgb: borderClr } };
  const borders = { top: b, bottom: b, left: b, right: b };
  const sTitle = { font: { bold: true, sz: 16, color: { rgb: brown }, name: 'Calibri' }, alignment: { vertical: 'center' } };
  const sHeader = { font: { bold: true, sz: 11, color: { rgb: 'FFFFFF' }, name: 'Calibri' }, fill: { fgColor: { rgb: brown } }, alignment: { horizontal: 'center', vertical: 'center', wrapText: true }, border: borders };
  const sHeaderLeft = { ...sHeader, alignment: { ...sHeader.alignment, horizontal: 'left' } };
  const cell = (stripe, extra = {}) => ({ font: { sz: 11, name: 'Calibri', ...(extra.font || {}) }, fill: stripe ? { fgColor: { rgb: cream } } : undefined, alignment: { vertical: 'center', ...(extra.alignment || {}) }, border: borders });

  const ws = {};
  const put = (r, c, v, s) => { const ref = XLSX.utils.encode_cell({ r, c }); ws[ref] = { v, t: typeof v === 'number' ? 'n' : 's', s }; };

  let r = 0;
  put(r, 0, 'Таблица аналогов', sTitle); r += 2;
  ['Артикул', 'Наименование', 'Учётная единица', 'Поставщик', 'Группа аналогов'].forEach((h, c) => put(r, c, h, c === 1 ? sHeaderLeft : sHeader));
  r++;

  const sorted = [...rows].sort((a, b2) => {
    const g = String(a.group || 'яяя').localeCompare(String(b2.group || 'яяя'), 'ru');
    if (g) return g;
    return String(a.sku || '').localeCompare(String(b2.sku || ''), 'ru');
  });
  sorted.forEach((row, i) => {
    const stripe = i % 2 === 1;
    put(r, 0, row.sku || '', cell(stripe, { font: { bold: true, color: { rgb: 'B26A00' } } }));
    put(r, 1, row.name || '', cell(stripe, { alignment: { horizontal: 'left', wrapText: true } }));
    put(r, 2, row.measure || '', cell(stripe, { alignment: { horizontal: 'center' } }));
    put(r, 3, row.supplier || '', cell(stripe, { alignment: { horizontal: 'left' } }));
    put(r, 4, row.group || '', cell(stripe, { alignment: { horizontal: 'left' }, font: { bold: true, color: { rgb: brown } } }));
    r++;
  });

  ws['!ref'] = XLSX.utils.encode_range({ r: 0, c: 0 }, { r: r - 1, c: 4 });
  ws['!cols'] = [{ wch: 14 }, { wch: 48 }, { wch: 16 }, { wch: 24 }, { wch: 34 }];
  ws['!merges'] = [{ s: { r: 0, c: 0 }, e: { r: 0, c: 4 } }];
  ws['!freeze'] = { xSplit: 0, ySplit: 3 };
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Аналоги');
  const d = new Date().toISOString().slice(0, 10);
  XLSX.writeFile(wb, `Таблица аналогов ${d}.xlsx`);
}
