/**
 * Аналитика заказов — данные
 * MariaDB через apiClient (db-совместимый QueryBuilder)
 */

import { db } from './apiClient.js';
import { toLocalDateStr } from './utils.js';

const PALETTE = [
  '#F5A623','#4CAF50','#2196F3','#9C27B0',
  '#F44336','#00BCD4','#FF5722','#607D8B',
  '#E91E63','#795548'
];

export async function getOrdersAnalytics(legalEntity, days = 30) {
  const now   = new Date();
  const start = new Date(now); start.setDate(start.getDate() - days);
  const prevS = new Date(start); prevS.setDate(prevS.getDate() - days);

  // Текущий период
  const { data: orders, error } = await db
    .from('orders')
    .select('id, delivery_date, supplier, created_at, order_items(qty_boxes, sku, name)')
    .eq('legal_entity', legalEntity)
    .gte('created_at', start.toISOString())
    .order('created_at', { ascending: true });

  if (error) { console.error(error); return null; }

  // Прошлый период (с товарами для сравнения)
  const { data: prevOrders } = await db
    .from('orders')
    .select('id, supplier, order_items(qty_boxes, sku, name)')
    .eq('legal_entity', legalEntity)
    .gte('created_at', prevS.toISOString())
    .lt('created_at', start.toISOString());

  return processData(orders || [], prevOrders || [], days);
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
    const d = new Date(o.created_at);
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
  const topProducts = Object.values(prodMap).sort((a, b) => b.boxes - a.boxes).slice(0, 10).map(p => {
    const key = p.sku || p.name || '?';
    const prev = prevProdMap[key] || { boxes: 0, orders: 0 };
    const avgPerDay = days > 0 ? p.boxes / days : 0;
    return {
      ...p,
      prevBoxes: prev.boxes,
      avgPerDay: Math.round(avgPerDay * 10) / 10,
      forecast: Math.round(avgPerDay * days),
      deltaBoxes: prev.boxes > 0 ? Math.round((p.boxes - prev.boxes) / prev.boxes * 100) : null,
    };
  });

  // === Аномалии ===
  const anomalies = [];

  // 1. Резкий рост/падение расхода товара
  topProducts.forEach(p => {
    if (p.deltaBoxes !== null && p.deltaBoxes >= 30) {
      anomalies.push({
        type: 'spike', icon: '📈', severity: 'warning',
        title: p.name || p.sku,
        text: `Расход вырос на ${p.deltaBoxes}%`,
        detail: `${p.boxes} кор vs ${p.prevBoxes} кор в прошлом периоде`,
      });
    }
    if (p.deltaBoxes !== null && p.deltaBoxes <= -20) {
      anomalies.push({
        type: 'drop', icon: '📉', severity: 'warning',
        title: p.name || p.sku,
        text: `Расход упал на ${Math.abs(p.deltaBoxes)}%`,
        detail: `${p.boxes} кор vs ${p.prevBoxes} кор в прошлом периоде`,
      });
    }
  });

  // 2. Поставщик давно без заказа (> 2x средний интервал)
  suppliers.forEach(s => {
    if (s.orders >= 2 && s.daysAgo !== null) {
      const avgInterval = days / s.orders;
      if (s.daysAgo > avgInterval * 2) {
        anomalies.push({
          type: 'supplier', icon: '⚠️', severity: 'danger',
          title: s.supplier,
          text: `Нет заказов ${s.daysAgo} дней`,
          detail: `Обычный интервал: ~${Math.round(avgInterval)} дней`,
        });
      }
    }
  });

  // 3. Необычно большой/маленький заказ
  const orderSizes = orders.map(o => {
    const d = new Date(o.created_at);
    return {
      id: o.id,
      supplier: o.supplier || 'Без поставщика',
      boxes: sumBoxes(o.order_items),
      date: d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' }),
    };
  });
  if (orderSizes.length >= 3) {
    const mean = orderSizes.reduce((s, o) => s + o.boxes, 0) / orderSizes.length;
    const std = Math.sqrt(orderSizes.reduce((s, o) => s + (o.boxes - mean) ** 2, 0) / orderSizes.length);
    if (std > 0) {
      orderSizes.forEach(o => {
        const z = (o.boxes - mean) / std;
        if (z > 2) {
          anomalies.push({
            type: 'outlier', icon: '⚡', severity: 'info',
            title: `${o.supplier} (${o.date})`,
            text: `Необычно большой заказ: ${Math.round(o.boxes)} кор`,
            detail: `Среднее: ${Math.round(mean)} кор. Нажмите чтобы открыть`,
            orderId: o.id,
          });
        } else if (z < -2) {
          anomalies.push({
            type: 'outlier', icon: '⚡', severity: 'info',
            title: `${o.supplier} (${o.date})`,
            text: `Необычно маленький заказ: ${Math.round(o.boxes)} кор`,
            detail: `Среднее: ${Math.round(mean)} кор. Нажмите чтобы открыть`,
            orderId: o.id,
          });
        }
      });
    }
  }

  // Сортировка аномалий: danger → warning → info
  const sevOrder = { danger: 0, warning: 1, info: 2 };
  anomalies.sort((a, b) => (sevOrder[a.severity] || 9) - (sevOrder[b.severity] || 9));

  // === Общие итоги ===
  const totalBoxes  = orders.reduce((s, o) => s + sumBoxes(o.order_items), 0);
  const totalOrders = orders.length;
  const prevBoxes   = prevOrders.reduce((s, o) => s + sumBoxes(o.order_items), 0);
  const prevCount   = prevOrders.length;

  return {
    days:         allDays,
    daysInMonth:  new Date(new Date().getFullYear(), new Date().getMonth() + 1, 0).getDate(),
    suppliers,
    supplierColor,
    topProducts,
    anomalies,
    totals:       { orders: totalOrders, boxes: totalBoxes },
    prev:         { orders: prevCount,   boxes: prevBoxes  },
    deltaOrders:  prevCount > 0 ? Math.round((totalOrders - prevCount) / prevCount * 100) : null,
    deltaBoxes:   prevBoxes > 0 ? Math.round((totalBoxes  - prevBoxes) / prevBoxes  * 100) : null,
    period:       days,
  };
}

/**
 * Сезонность: заказы за 12 месяцев, скользящее среднее, YoY
 */
export async function getSeasonalityData(legalEntity) {
  const now = new Date();
  const start = new Date(now.getFullYear() - 1, now.getMonth(), 1);

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
