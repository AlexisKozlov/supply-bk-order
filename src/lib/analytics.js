/**
 * Аналитика заказов — данные
 * MariaDB через apiClient (db-совместимый QueryBuilder)
 */

import { db } from './apiClient.js';
import { toLocalDateStr, applyEntityFilter } from './utils.js';

const PALETTE = [
  '#F5A623','#4CAF50','#2196F3','#9C27B0',
  '#F44336','#00BCD4','#FF5722','#607D8B',
  '#E91E63','#795548'
];

export async function getOrdersAnalytics(legalEntity, days = 30) {
  const now   = new Date();
  const start = new Date(now); start.setDate(start.getDate() - days);
  const prevS = new Date(start); prevS.setDate(prevS.getDate() - days);

  // Оба периода запрашиваем параллельно
  const [currentResult, prevResult] = await Promise.all([
    db.from('orders')
      .select('id, delivery_date, supplier, created_at, received_at, received_by, order_items(qty_boxes, received_qty, sku, name)')
      .eq('legal_entity', legalEntity)
      .gte('created_at', start.toISOString())
      .order('created_at', { ascending: true }),
    db.from('orders')
      .select('id, supplier, order_items(qty_boxes, sku, name)')
      .eq('legal_entity', legalEntity)
      .gte('created_at', prevS.toISOString())
      .lt('created_at', start.toISOString()),
  ]);

  if (currentResult.error) { console.error(currentResult.error); return null; }

  return processData(currentResult.data || [], prevResult.data || [], days);
}

function parseBoxes(item) {
  return parseFloat(String(item.qty_boxes || '0').replace(',', '.')) || 0;
}

function sumBoxes(orderItems) {
  return (orderItems || []).reduce((s, i) => s + parseBoxes(i), 0);
}

function processData(orders, prevOrders, days) {
  const supplierSet = [...new Set(orders.map(o => o.supplier || 'Без поставщика'))];
  const supplierColor = {};
  supplierSet.forEach((s, i) => { supplierColor[s] = PALETTE[i % PALETTE.length]; });

  // === По дням ===
  const dayMap = {};
  orders.forEach(order => {
    const raw = new Date(order.created_at);
    const dayKey = toLocalDateStr(raw);
    const dayLabel = raw.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
    const sup = order.supplier || 'Без поставщика';
    const boxes = sumBoxes(order.order_items);
    if (!dayMap[dayKey]) dayMap[dayKey] = { dayKey, dayLabel, total: 0, bySupplier: {} };
    dayMap[dayKey].total += boxes;
    dayMap[dayKey].bySupplier[sup] = (dayMap[dayKey].bySupplier[sup] || 0) + boxes;
  });

  const startD = new Date(); startD.setDate(startD.getDate() - days);
  const allDays = [];
  for (let i = 0; i < days; i++) {
    const d = new Date(startD); d.setDate(d.getDate() + i);
    const key = toLocalDateStr(d);
    const label = d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
    allDays.push(dayMap[key] || { dayKey: key, dayLabel: label, total: 0, bySupplier: {} });
  }
  while (allDays.length > 1 && allDays[allDays.length - 1].total === 0) allDays.pop();

  // === По поставщикам ===
  const supMap = {};
  orders.forEach(o => {
    const sup = o.supplier || 'Без поставщика';
    const boxes = sumBoxes(o.order_items);
    if (!supMap[sup]) supMap[sup] = { supplier: sup, orders: 0, boxes: 0, color: supplierColor[sup], lastDate: null };
    supMap[sup].orders++;
    supMap[sup].boxes += boxes;
    // Используем дату доставки (если есть) или дату создания
    const d = o.delivery_date ? new Date(o.delivery_date + 'T00:00:00') : new Date(o.created_at);
    if (!supMap[sup].lastDate || d > supMap[sup].lastDate) supMap[sup].lastDate = d;
  });
  // Прошлый период для поставщиков
  const prevSupMap = {};
  prevOrders.forEach(o => {
    const sup = o.supplier || 'Без поставщика';
    const boxes = sumBoxes(o.order_items);
    if (!prevSupMap[sup]) prevSupMap[sup] = { orders: 0, boxes: 0 };
    prevSupMap[sup].orders++;
    prevSupMap[sup].boxes += boxes;
  });
  // Добавить дельты и дней назад
  const suppliers = Object.values(supMap).sort((a, b) => b.boxes - a.boxes).map(s => ({
    ...s,
    prevBoxes: prevSupMap[s.supplier]?.boxes || 0,
    prevOrders: prevSupMap[s.supplier]?.orders || 0,
    daysAgo: s.lastDate ? Math.round((Date.now() - s.lastDate.getTime()) / 86400000) : null,
  }));

  // === Топ товаров с прошлым периодом ===
  const prodMap = {};
  orders.forEach(o => {
    (o.order_items || []).forEach(item => {
      const key = item.sku || item.name || '?';
      if (!prodMap[key]) prodMap[key] = { sku: item.sku, name: item.name, boxes: 0, orders: 0 };
      prodMap[key].boxes += parseBoxes(item);
      prodMap[key].orders++;
    });
  });
  const prevProdMap = {};
  prevOrders.forEach(o => {
    (o.order_items || []).forEach(item => {
      const key = item.sku || item.name || '?';
      if (!prevProdMap[key]) prevProdMap[key] = { boxes: 0, orders: 0 };
      prevProdMap[key].boxes += parseBoxes(item);
      prevProdMap[key].orders++;
    });
  });
  // Реальное кол-во дней с заказами для более точного среднего
  const activeDays = new Set(orders.map(o => toLocalDateStr(new Date(o.created_at)))).size || 1;
  const topProducts = Object.values(prodMap).sort((a, b) => b.boxes - a.boxes).slice(0, 10).map(p => {
    const key = p.sku || p.name || '?';
    const prev = prevProdMap[key] || { boxes: 0, orders: 0 };
    // Среднее на основе дней с заказами + учёт обоих периодов для прогноза
    const totalBoxesBoth = p.boxes + prev.boxes;
    const totalDaysBoth = days * 2;
    const avgPerDay = totalDaysBoth > 0 ? totalBoxesBoth / totalDaysBoth : 0;
    return {
      ...p,
      prevBoxes: prev.boxes,
      avgPerDay: Math.round(avgPerDay * 10) / 10,
      forecast: Math.round(avgPerDay * days),
      deltaBoxes: prev.boxes > 0 ? Math.round((p.boxes - prev.boxes) / prev.boxes * 100) : null,
    };
  });

  // === Изменения за период ===
  const changes = [];

  // 1. Товар перестали заказывать — был в прошлом периоде (≥3 заказа), нет в текущем
  for (const [key, prev] of Object.entries(prevProdMap)) {
    if (prev.orders >= 3 && !prodMap[key]) {
      changes.push({
        type: 'disappeared', icon: '🔴', severity: 'danger',
        title: prev.name || key,
        text: `Перестали заказывать — за прошлые ${days} дн. было ${prev.orders} заказов (${Math.round(prev.boxes)} кор.)`,
        detail: `За текущие ${days} дней — ноль заказов`,
        sku: key,
      });
    }
  }
  // Также: был регулярным (≥2 заказа), текущий объём упал на 80%+
  for (const [key, cur] of Object.entries(prodMap)) {
    const prev = prevProdMap[key];
    if (prev && prev.orders >= 2 && prev.boxes > 0) {
      const drop = Math.round((1 - cur.boxes / prev.boxes) * 100);
      if (drop >= 80) {
        changes.push({
          type: 'disappeared', icon: '🟡', severity: 'warning',
          title: cur.name || key,
          text: `Почти перестали заказывать — объём упал на ${drop}%`,
          detail: `Было ${Math.round(prev.boxes)} кор. (${prev.orders} зак.), стало ${Math.round(cur.boxes)} кор. (${cur.orders} зак.)`,
          sku: key,
        });
      }
    }
  }

  // Сортировка: danger сначала, потом warning
  const sevOrder = { danger: 0, warning: 1 };
  changes.sort((a, b) => (sevOrder[a.severity] || 9) - (sevOrder[b.severity] || 9));

  // === Общие итоги ===
  const totalBoxes  = orders.reduce((s, o) => s + sumBoxes(o.order_items), 0);
  const totalOrders = orders.length;
  const prevBoxes   = prevOrders.reduce((s, o) => s + sumBoxes(o.order_items), 0);
  const prevCount   = prevOrders.length;

  // === План-Факт (расширенный) ===
  const receivedOrders = orders.filter(o => o.received_at);
  const pendingOrders = orders.filter(o => !o.received_at);
  let planBoxes = 0, factBoxes = 0, discrepancyItems = 0, totalReceivedItems = 0;

  // По поставщикам
  const pfSupMap = {};
  // По товарам — расхождения
  const pfProdMap = {};
  // По дням — тренд выполнения
  const pfDayMap = {};

  receivedOrders.forEach(o => {
    const sup = o.supplier || 'Без поставщика';
    if (!pfSupMap[sup]) pfSupMap[sup] = { supplier: sup, plan: 0, fact: 0, orders: 0, discrepancies: 0, items: 0, color: supplierColor[sup] || PALETTE[0] };
    pfSupMap[sup].orders++;

    const recDate = toLocalDateStr(new Date(o.received_at));
    if (!pfDayMap[recDate]) pfDayMap[recDate] = { plan: 0, fact: 0 };

    (o.order_items || []).forEach(item => {
      const plan = parseBoxes(item);
      const fact = item.received_qty != null ? parseFloat(String(item.received_qty).replace(',', '.')) || 0 : plan;
      planBoxes += plan;
      factBoxes += fact;
      totalReceivedItems++;
      pfSupMap[sup].plan += plan;
      pfSupMap[sup].fact += fact;
      pfSupMap[sup].items++;
      pfDayMap[recDate].plan += plan;
      pfDayMap[recDate].fact += fact;

      const isDiscrepancy = Math.round(fact) !== Math.round(plan);
      if (isDiscrepancy) {
        discrepancyItems++;
        pfSupMap[sup].discrepancies++;
        const key = item.sku || item.name || '?';
        if (!pfProdMap[key]) pfProdMap[key] = { sku: item.sku, name: item.name, plan: 0, fact: 0, count: 0, delta: 0 };
        pfProdMap[key].plan += plan;
        pfProdMap[key].fact += fact;
        pfProdMap[key].delta += (fact - plan);
        pfProdMap[key].count++;
      }
    });
  });

  const fulfillmentPct = planBoxes > 0 ? Math.round(factBoxes / planBoxes * 100) : null;

  // Поставщики с % выполнения, сортировка по кол-ву расхождений
  const pfSuppliers = Object.values(pfSupMap).map(s => ({
    ...s,
    plan: Math.round(s.plan),
    fact: Math.round(s.fact),
    fulfillmentPct: s.plan > 0 ? Math.round(s.fact / s.plan * 100) : 100,
    discrepancyPct: s.items > 0 ? Math.round(s.discrepancies / s.items * 100) : 0,
  })).sort((a, b) => b.discrepancies - a.discrepancies);

  // Топ товаров с расхождениями
  const pfDiscrepancyProducts = Object.values(pfProdMap).sort((a, b) => Math.abs(b.delta) - Math.abs(a.delta)).slice(0, 10).map(p => ({
    ...p,
    plan: Math.round(p.plan),
    fact: Math.round(p.fact),
    delta: Math.round(p.delta),
  }));

  // Тренд выполнения по дням
  const pfDayKeys = Object.keys(pfDayMap).sort();
  const pfDayTrend = pfDayKeys.map(k => {
    const d = new Date(k + 'T00:00:00');
    return {
      date: k,
      label: d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' }),
      plan: Math.round(pfDayMap[k].plan),
      fact: Math.round(pfDayMap[k].fact),
      pct: pfDayMap[k].plan > 0 ? Math.round(pfDayMap[k].fact / pfDayMap[k].plan * 100) : 100,
    };
  });

  const planFact = {
    receivedOrders: receivedOrders.length,
    pendingOrders: pendingOrders.length,
    planBoxes: Math.round(planBoxes),
    factBoxes: Math.round(factBoxes),
    fulfillmentPct,
    discrepancyItems,
    totalReceivedItems,
    discrepancyPct: totalReceivedItems > 0 ? Math.round(discrepancyItems / totalReceivedItems * 100) : 0,
    suppliers: pfSuppliers,
    discrepancyProducts: pfDiscrepancyProducts,
    dayTrend: pfDayTrend,
  };

  return {
    days:         allDays,
    daysInMonth:  new Date(new Date().getFullYear(), new Date().getMonth() + 1, 0).getDate(),
    suppliers,
    supplierColor,
    topProducts,
    changes,
    planFact,
    totals:       { orders: totalOrders, boxes: totalBoxes },
    prev:         { orders: prevCount,   boxes: prevBoxes  },
    deltaOrders:  prevCount > 0 ? Math.round((totalOrders - prevCount) / prevCount * 100) : null,
    deltaBoxes:   prevBoxes > 0 ? Math.round((totalBoxes  - prevBoxes) / prevBoxes  * 100) : null,
    period:       days,
  };
}

/**
 * Сезонность: заказы за 24 месяца, скользящее среднее, YoY
 */
export async function getSeasonalityData(legalEntity) {
  const now = new Date();
  const start = new Date(now.getFullYear() - 2, now.getMonth(), 1);

  const { data: orders, error } = await db
    .from('orders')
    .select('id, created_at, order_items(qty_boxes)')
    .eq('legal_entity', legalEntity)
    .gte('created_at', start.toISOString())
    .order('created_at', { ascending: true });

  if (error || !orders) return null;

  // Группировка по месяцам
  const monthMap = {};
  orders.forEach(o => {
    const d = new Date(o.created_at);
    const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    if (!monthMap[key]) monthMap[key] = { boxes: 0, orders: 0 };
    monthMap[key].orders++;
    monthMap[key].boxes += sumBoxes(o.order_items);
  });

  // Собрать 12 месяцев
  const monthData = [];
  const monthNames = ['Янв','Фев','Мар','Апр','Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'];
  for (let i = 0; i < 12; i++) {
    const d = new Date(now.getFullYear(), now.getMonth() - 11 + i, 1);
    const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    const label = `${monthNames[d.getMonth()]} ${String(d.getFullYear()).slice(2)}`;
    const data = monthMap[key] || { boxes: 0, orders: 0 };

    // YoY — тот же месяц прошлого года
    const prevKey = `${d.getFullYear() - 1}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    const prevData = monthMap[prevKey];
    let yoyDelta = null;
    if (prevData && prevData.boxes > 0) {
      yoyDelta = Math.round((data.boxes - prevData.boxes) / prevData.boxes * 100);
    }

    monthData.push({ key, label, ...data, movingAvg: null, yoyDelta });
  }

  // Скользящее среднее за 3 месяца
  for (let i = 0; i < monthData.length; i++) {
    if (i >= 2) {
      const avg = (monthData[i].boxes + monthData[i-1].boxes + monthData[i-2].boxes) / 3;
      monthData[i].movingAvg = Math.round(avg);
    }
  }

  const maxBoxes = Math.max(...monthData.map(m => m.boxes), 1);

  return { monthData, maxBoxes };
}

/**
 * Прогноз расхода товаров: взвешенное среднее, тренд, sparkline, статус запаса
 */
export async function getForecastData(legalEntity) {
  const now = new Date();
  const start60 = new Date(now); start60.setDate(start60.getDate() - 60);

  // 3 параллельных запроса: заказы за 60 дней, остатки, сезонность (12 мес.)
  // Для рабочих данных (orders, analysis_data) фильтруем строго по одному юрлицу
  let ordersQuery = db.from('orders')
    .select('id, created_at, supplier, order_items(qty_boxes, sku, name)')
    .eq('legal_entity', legalEntity)
    .gte('created_at', start60.toISOString())
    .order('created_at', { ascending: true });

  let stockQuery = db.from('analysis_data')
    .select('sku, stock, consumption, period_days')
    .eq('legal_entity', legalEntity);

  // Для справочников (products) — общий фильтр по группе юрлиц
  let productsQuery = db.from('products')
    .select('sku, name, qty_per_box, supplier, analog_group, unit_of_measure');
  productsQuery = applyEntityFilter(productsQuery, legalEntity);

  // Реализация ресторанов за 365 дней (для сезонности и прогноза)
  const start365 = new Date(now); start365.setDate(start365.getDate() - 365);
  let salesQuery = db.from('restaurant_sales')
    .select('sale_date, analog_group, quantity, restaurant_count')
    .gte('sale_date', start365.toISOString().slice(0, 10))
    .order('sale_date', { ascending: true })
    .limit(500000);

  const [ordersRes, stockRes, productsRes, salesRes] = await Promise.all([
    ordersQuery, stockQuery, productsQuery, salesQuery,
  ]);

  if (ordersRes.error || stockRes.error || productsRes.error || salesRes.error) {
    console.error('Analytics data load error', ordersRes.error, stockRes.error, productsRes.error, salesRes.error);
  }
  const orders = ordersRes.data || [];
  const stockRows = stockRes.data || [];
  const products = productsRes.data || [];
  const salesRows = salesRes.data || [];

  // Карта товаров для qty_per_box и supplier
  const productMap = {};
  products.forEach(p => {
    if (p.sku) productMap[p.sku] = p;
  });

  // SKU → analog_group и подсчёт SKU в каждой группе
  const skuToGroup = {};
  const groupSkuCount = {}; // { group: count } — сколько активных SKU в каждой группе
  products.forEach(p => {
    if (p.sku && p.analog_group) {
      skuToGroup[p.sku] = p.analog_group;
      groupSkuCount[p.analog_group] = (groupSkuCount[p.analog_group] || 0) + 1;
    }
  });

  // Реализация ресторанов по analog_group и дням
  const salesByGroup = {}; // { group: { days: {date: qty}, total, dayCount } }
  salesRows.forEach(r => {
    const g = r.analog_group;
    if (!g) return;
    if (!salesByGroup[g]) salesByGroup[g] = { days: {}, total: 0, dayCount: 0 };
    const qty = parseFloat(r.quantity) || 0;
    const dateKey = r.sale_date?.split('T')[0] || r.sale_date;
    if (!salesByGroup[g].days[dateKey]) {
      salesByGroup[g].days[dateKey] = 0;
      salesByGroup[g].dayCount++;
    }
    salesByGroup[g].days[dateKey] += qty;
    salesByGroup[g].total += qty;
  });

  // Группы с реализацией за последние 3 дня (актуальные)
  const recentSalesGroups = new Set();
  for (let i = 0; i < 3; i++) {
    const d = new Date(now); d.setDate(d.getDate() - i);
    const dk = toLocalDateStr(d);
    for (const [g, sd] of Object.entries(salesByGroup)) {
      if (sd.days[dk] && sd.days[dk] > 0) recentSalesGroups.add(g);
    }
  }

  // Карта остатков и расхода из analysis_data (всё в штуках)
  const stockMap = {};
  stockRows.forEach(r => {
    if (r.sku) {
      const stock = parseFloat(String(r.stock || '0').replace(',', '.')) || 0;
      const consumption = parseFloat(String(r.consumption || '0').replace(',', '.')) || 0;
      const periodDays = parseInt(r.period_days) || 30;
      const dailyConsumption = periodDays > 0 ? consumption / periodDays : 0;
      stockMap[r.sku] = { stock, dailyConsumption };
    }
  });

  // === Группировка расхода по товарам и дням ===
  const prodDayMap = {}; // { sku: { dayStr: boxes } }
  const supplierSet = new Set();

  orders.forEach(order => {
    const dayStr = toLocalDateStr(new Date(order.created_at));
    const sup = order.supplier || '';
    if (sup) supplierSet.add(sup);
    (order.order_items || []).forEach(item => {
      const key = item.sku || item.name;
      if (!key) return;
      const boxes = parseFloat(String(item.qty_boxes || '0').replace(',', '.')) || 0;
      if (!prodDayMap[key]) prodDayMap[key] = { sku: item.sku, name: item.name, days: {}, orderSupplier: sup };
      if (!prodDayMap[key].days[dayStr]) prodDayMap[key].days[dayStr] = 0;
      prodDayMap[key].days[dayStr] += boxes;
    });
  });

  // === Сезонный коэффициент по группе аналогов (на основе реализации ресторанов) ===
  // Группируем реализацию по group и месяцу: { group: { 'YYYY-MM': totalQty } }
  const groupMonthMap = {};
  salesRows.forEach(r => {
    const g = r.analog_group;
    if (!g) return;
    const dateKey = r.sale_date?.split('T')[0] || r.sale_date;
    const d = new Date(dateKey);
    const mKey = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    const qty = parseFloat(r.quantity) || 0;
    if (!groupMonthMap[g]) groupMonthMap[g] = {};
    if (!groupMonthMap[g][mKey]) groupMonthMap[g][mKey] = 0;
    groupMonthMap[g][mKey] += qty;
  });

  // Текущий и прошлогодний месяц
  const curMonthKey = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
  const lastYearMonthKey = `${now.getFullYear() - 1}-${String(now.getMonth() + 1).padStart(2, '0')}`;
  const dayOfMonth = now.getDate();
  const daysInMonth = new Date(now.getFullYear(), now.getMonth() + 1, 0).getDate();

  // Сезонность для группы аналогов (по реализации ресторанов)
  function getGroupSeason(group) {
    const months = group ? groupMonthMap[group] : null;
    if (!months) return { seasonCoeff: 1, yoyChange: null };
    // Полные месяцы (исключаем текущий неполный)
    const fullMonths = Object.entries(months).filter(([k]) => k !== curMonthKey);
    if (fullMonths.length < 2) return { seasonCoeff: 1, yoyChange: null };
    const avgMonth = fullMonths.reduce((s, [, v]) => s + v, 0) / fullMonths.length;
    // Прошлогодний тот же месяц vs среднее
    const lastYear = months[lastYearMonthKey] ?? null;
    let seasonCoeff = 1;
    if (lastYear != null && avgMonth > 0) {
      seasonCoeff = lastYear / avgMonth;
    }
    // Год к году: текущий (экстраполированный) vs прошлый год
    const curRaw = months[curMonthKey] || 0;
    const curFull = dayOfMonth >= 5 ? curRaw * daysInMonth / dayOfMonth : 0;
    let yoyChange = null;
    if (lastYear != null && lastYear > 0 && curFull > 0) {
      yoyChange = Math.round((curFull - lastYear) / lastYear * 100);
    }
    return { seasonCoeff: Math.max(0.5, Math.min(2, seasonCoeff)), yoyChange };
  }

  // === Все даты за 60 дней ===
  const allDates = [];
  for (let i = 59; i >= 0; i--) {
    const d = new Date(now); d.setDate(d.getDate() - i);
    allDates.push(toLocalDateStr(d));
  }

  // === Собираем все товары: из справочника + из заказов ===
  const forecastItems = [];
  const allSuppliers = [...supplierSet].sort();

  // Все SKU из справочника (активные товары)
  const allSkus = new Set(Object.keys(productMap));
  // Добавляем SKU из заказов (на случай если товар удалён из справочника)
  for (const key of Object.keys(prodDayMap)) {
    if (prodDayMap[key].sku) allSkus.add(prodDayMap[key].sku);
  }

  for (const sku of allSkus) {
    const prod = prodDayMap[sku] || null;
    const productInfo = productMap[sku] || {};
    const qtyPerBox = productInfo.qty_per_box || 1;
    const unit = productInfo.unit_of_measure || 'шт';
    const supplier = productInfo.supplier || (prod ? prod.orderSupplier : '') || '';
    const prodName = productInfo.name || (prod ? prod.name : '') || sku;

    // Дневные значения заказов за 60 дней
    const dailyValues = prod ? allDates.map(d => prod.days[d] || 0) : allDates.map(() => 0);

    // Тренд по заказам
    const last7 = dailyValues.slice(-7);
    const avg7 = last7.reduce((s, v) => s + v, 0) / 7;
    const prev7 = dailyValues.slice(-14, -7);
    const avgPrev7 = prev7.reduce((s, v) => s + v, 0) / 7;
    let trend = 'stable';
    if (avgPrev7 > 0) {
      const change = (avg7 - avgPrev7) / avgPrev7;
      if (change > 0.15) trend = 'up';
      else if (change < -0.15) trend = 'down';
    } else if (avg7 > 0) {
      trend = 'up';
    }

    // Sparkline: последние 14 дней (по дням)
    const sparkline = dailyValues.slice(-14);

    const hasStockData = sku in stockMap;
    const analysisData = hasStockData ? stockMap[sku] : null;

    // --- Прогноз на основе реализации ресторанов (если есть) ---
    const group = skuToGroup[sku];
    const salesData = group ? salesByGroup[group] : null;
    // Кол-во SKU в группе — реализация делится поровну между ними
    const skusInGroup = group ? (groupSkuCount[group] || 1) : 1;
    let salesAvgPerDay = 0; // шт/день по реализации (доля этого SKU)
    let hasSalesData = false;
    let salesTrend = null;
    if (salesData && salesData.dayCount >= 7) {
      hasSalesData = true;
      // Взвешенное среднее: 7 дн (50%), 8-30 дн (30%), 31-90 дн (20%)
      const salesDates90 = [];
      for (let i = 89; i >= 0; i--) {
        const d = new Date(now); d.setDate(d.getDate() - i);
        salesDates90.push(toLocalDateStr(d));
      }
      const sLast7 = salesDates90.slice(-7).reduce((s, d) => s + (salesData.days[d] || 0), 0);
      const sMid23 = salesDates90.slice(-30, -7).reduce((s, d) => s + (salesData.days[d] || 0), 0);
      const sOld60 = salesDates90.slice(0, -30).reduce((s, d) => s + (salesData.days[d] || 0), 0);
      const sAvg7 = sLast7 / 7;
      const sMidDays = salesDates90.slice(-30, -7).length || 1;
      const sOldDays = salesDates90.slice(0, -30).length || 1;
      const sAvgMid = sMidDays > 0 ? sMid23 / sMidDays : sAvg7;
      const sAvgOld = sOldDays > 0 ? sOld60 / sOldDays : sAvgMid;
      // Делим на кол-во SKU в группе: реализация — это итого по всей группе
      salesAvgPerDay = (sAvg7 * 0.5 + sAvgMid * 0.3 + sAvgOld * 0.2) / skusInGroup;
      // Тренд по реализации (тренд общий, не делим)
      const sPrev7 = salesDates90.slice(-14, -7).reduce((s, d) => s + (salesData.days[d] || 0), 0);
      const sPrevAvg = sPrev7 / 7;
      if (sPrevAvg > 0) {
        const ch = (sAvg7 - sPrevAvg) / sPrevAvg;
        salesTrend = ch > 0.15 ? 'up' : ch < -0.15 ? 'down' : 'stable';
      } else if (sAvg7 > 0) {
        salesTrend = 'up';
      }
    }

    // Расход и остаток — всё в исходных единицах (шт/кг/л)
    const dailyConsumptionPieces = analysisData ? analysisData.dailyConsumption : 0;
    // Если есть реализация ресторанов — используем её; иначе analysis_data
    const effectiveDaily = hasSalesData ? salesAvgPerDay : dailyConsumptionPieces;
    const stockPieces = analysisData ? analysisData.stock : null;

    let daysOfStock = null;
    let stockStatus = 'unknown';
    if (hasStockData) {
      if (effectiveDaily > 0) {
        daysOfStock = Math.round(stockPieces / effectiveDaily);
      } else {
        daysOfStock = stockPieces > 0 ? 999 : 0;
      }
      stockStatus = 'ok';
      if (daysOfStock <= 3) stockStatus = 'critical';
      else if (daysOfStock <= 7) stockStatus = 'warning';
    }

    // Тренд: предпочитаем данные реализации, иначе по заказам
    const effectiveTrend = salesTrend || trend;

    // Сезонность по группе аналогов (данные реализации ресторанов за год)
    const season = getGroupSeason(group);
    const adjCoeff = season.seasonCoeff;
    const adjDaily = effectiveDaily * adjCoeff;

    // Пропускаем товары без данных (ни реализации, ни расхода, ни остатков)
    if (!hasSalesData && !analysisData && !prod) continue;
    // Пропускаем группы аналогов без актуальной реализации (за последние 3 дня)
    if (group && !recentSalesGroups.has(group)) continue;

    forecastItems.push({
      sku: sku,
      name: prodName,
      supplier,
      unit,
      qtyPerBox,
      // Расход/день в исходных единицах (шт/кг/л)
      avgPerDay: Math.round(effectiveDaily * 100) / 100,
      // Прогноз = дневной расход × дней × сезонный коэффициент
      forecast7: Math.round(adjDaily * 7 * 10) / 10,
      forecast14: Math.round(adjDaily * 14 * 10) / 10,
      forecast30: Math.round(adjDaily * 30 * 10) / 10,
      hasConsumptionData: effectiveDaily > 0,
      dataSource: hasSalesData ? 'restaurant_sales' : (dailyConsumptionPieces > 0 ? 'analysis_data' : 'orders'),
      trend: effectiveTrend,
      sparkline,
      // Сезонность
      seasonCoeff: Math.round(adjCoeff * 100) / 100,
      yoyChange: season.yoyChange,
      stock: hasStockData ? Math.round(stockPieces * 10) / 10 : null,
      daysOfStock,
      stockStatus,
    });
  }

  // Сортировка по умолчанию: критичные сверху, потом по расходу
  forecastItems.sort((a, b) => {
    const statusOrder = { critical: 0, warning: 1, ok: 2, unknown: 3 };
    const sa = statusOrder[a.stockStatus] ?? 9;
    const sb = statusOrder[b.stockStatus] ?? 9;
    if (sa !== sb) return sa - sb;
    return b.avgPerDay - a.avgPerDay;
  });

  // === Группировка по analog_group ===
  const groupMap = {}; // { groupName: { items: [...] } }
  const noGroup = []; // товары без analog_group
  for (const item of forecastItems) {
    const g = item.sku ? skuToGroup[item.sku] : null;
    if (g) {
      if (!groupMap[g]) groupMap[g] = { name: g, items: [] };
      groupMap[g].items.push(item);
    } else {
      noGroup.push(item);
    }
  }

  const statusOrder = { critical: 0, warning: 1, ok: 2, unknown: 3 };
  const forecastGroups = [];

  for (const g of Object.values(groupMap)) {
    const items = g.items;
    const avgPerDay = items.reduce((s, i) => s + i.avgPerDay, 0);
    const forecast7 = items.reduce((s, i) => s + i.forecast7, 0);
    const forecast14 = items.reduce((s, i) => s + i.forecast14, 0);
    const forecast30 = items.reduce((s, i) => s + i.forecast30, 0);
    const hasAnyStock = items.some(i => i.stock !== null);
    const stockTotal = hasAnyStock ? items.reduce((s, i) => s + (i.stock || 0), 0) : null;
    // Дни запаса группы = суммарный остаток / суммарный расход (в коробках)
    let daysOfStock = null;
    let stockStatus = 'unknown';
    if (hasAnyStock) {
      if (avgPerDay > 0) {
        daysOfStock = Math.round(stockTotal / avgPerDay);
      } else {
        daysOfStock = stockTotal > 0 ? 999 : 0;
      }
      stockStatus = 'ok';
      if (daysOfStock <= 3) stockStatus = 'critical';
      else if (daysOfStock <= 7) stockStatus = 'warning';
    }
    // Тренд: если хоть один растёт — up; если хоть один падает и никто не растёт — down
    const hasUp = items.some(i => i.trend === 'up');
    const hasDown = items.some(i => i.trend === 'down');
    const trend = hasUp ? 'up' : hasDown ? 'down' : 'stable';
    const hasConsumptionData = items.some(i => i.hasConsumptionData);
    const dataSource = items.some(i => i.dataSource === 'restaurant_sales') ? 'restaurant_sales'
      : items.some(i => i.dataSource === 'analysis_data') ? 'analysis_data' : 'orders';
    // Поставщики в группе
    const suppliers = [...new Set(items.map(i => i.supplier).filter(Boolean))];
    // Sparkline: суммируем по дням
    const sparkline = (items[0]?.sparkline || []).map((_, di) => items.reduce((s, it) => s + ((it.sparkline || [])[di] || 0), 0));

    // Единица измерения группы — берём от первого товара (обычно одинаковая)
    const groupUnit = items[0]?.unit || 'шт';

    forecastGroups.push({
      name: g.name,
      isGroup: true,
      items,
      unit: groupUnit,
      suppliers,
      supplier: suppliers.join(', '),
      avgPerDay: Math.round(avgPerDay * 100) / 100,
      forecast7: Math.round(forecast7 * 10) / 10,
      forecast14: Math.round(forecast14 * 10) / 10,
      forecast30: Math.round(forecast30 * 10) / 10,
      hasConsumptionData,
      dataSource,
      trend,
      sparkline,
      stock: stockTotal !== null ? Math.round(stockTotal * 10) / 10 : null,
      daysOfStock,
      stockStatus,
      // Сезонность: средний коэффициент и YoY по группе
      seasonCoeff: items.reduce((s, i) => s + (i.seasonCoeff || 1), 0) / items.length,
      yoyChange: (() => {
        const withYoy = items.filter(i => i.yoyChange !== null);
        if (!withYoy.length) return null;
        return Math.round(withYoy.reduce((s, i) => s + i.yoyChange, 0) / withYoy.length);
      })(),
      lastYearBoxes: (() => {
        const withLy = items.filter(i => i.lastYearBoxes !== null);
        if (!withLy.length) return null;
        return Math.round(withLy.reduce((s, i) => s + i.lastYearBoxes, 0) * 10) / 10;
      })(),
    });
  }

  // Товары без группы — каждый как отдельная «группа» из 1 элемента
  for (const item of noGroup) {
    forecastGroups.push({
      ...item,
      name: item.name || item.sku,
      isGroup: false,
      items: [item],
      suppliers: item.supplier ? [item.supplier] : [],
    });
  }

  // Сортировка групп: критичные сверху, потом по расходу
  forecastGroups.sort((a, b) => {
    const sa = statusOrder[a.stockStatus] ?? 9;
    const sb = statusOrder[b.stockStatus] ?? 9;
    if (sa !== sb) return sa - sb;
    return b.avgPerDay - a.avgPerDay;
  });

  // KPI
  const withStock = forecastItems.filter(i => i.stockStatus !== 'unknown');
  const deficitItems = withStock.filter(i => i.stockStatus === 'critical' || i.stockStatus === 'warning');
  const noStockData = forecastItems.filter(i => i.stockStatus === 'unknown');
  const totalForecast7 = forecastItems.reduce((s, i) => s + i.forecast7, 0);
  const totalForecast14 = forecastItems.reduce((s, i) => s + i.forecast14, 0);
  const totalForecast30 = forecastItems.reduce((s, i) => s + i.forecast30, 0);

  return {
    items: forecastItems,
    groups: forecastGroups,
    suppliers: allSuppliers,
    seasonCoeff: 1, // сезонный коэфф. теперь считается по каждому SKU отдельно
    kpi: {
      totalProducts: forecastItems.length,
      totalGroups: forecastGroups.length,
      withStockCount: withStock.length,
      noStockCount: noStockData.length,
      deficitCount: deficitItems.length,
      criticalCount: forecastItems.filter(i => i.stockStatus === 'critical').length,
      totalForecast7: Math.round(totalForecast7),
      totalForecast14: Math.round(totalForecast14),
      totalForecast30: Math.round(totalForecast30),
    },
  };
}
