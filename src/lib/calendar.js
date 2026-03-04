/**
 * Календарь поставок — данные (без рендера)
 * Бизнес-логика перенесена 1:1 из delivery-calendar.js
 */

import { db } from './apiClient.js';
import { toLocalDateStr } from './utils.js';

const PALETTE = [
  '#F5A623','#4CAF50','#2196F3','#9C27B0',
  '#F44336','#00BCD4','#FF5722','#607D8B',
  '#E91E63','#795548'
];

export const MONTH_NAMES = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
export const DAY_NAMES = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];

export async function loadCalendarData(year, month, legalEntity) {
  // month — 0-based (январь = 0), как в JS Date
  const from = new Date(year, month - 1, 1);
  const to = new Date(year, month + 2, 0);

  const { data, error } = await db
    .from('orders')
    .select('id, supplier, delivery_date, created_at, order_items(sku, name, qty_boxes)')
    .eq('legal_entity', legalEntity)
    .gte('delivery_date', toLocalDateStr(from))
    .lte('delivery_date', toLocalDateStr(to))
    .order('delivery_date', { ascending: true });

  if (error) { console.error('Calendar data error:', error); return { orders: [], supplierColors: {} }; }

  const orders = data || [];
  const suppliers = [...new Set(orders.map(o => o.supplier).filter(Boolean))];
  suppliers.sort((a, b) => a.localeCompare(b, 'ru'));
  const supplierColors = {};
  suppliers.forEach((s, i) => { supplierColors[s] = PALETTE[i % PALETTE.length]; });

  return { orders, supplierColors };
}

export function buildCalendarGrid(year, month, orders, supplierColors) {
  const today = new Date();
  today.setHours(0, 0, 0, 0);

  const firstDay = new Date(year, month, 1);
  const lastDay = new Date(year, month + 1, 0);
  const daysInMonth = lastDay.getDate();

  let startDay = firstDay.getDay();
  startDay = startDay === 0 ? 6 : startDay - 1;

  // Группировка заказов по дате
  const ordersByDate = {};
  orders.forEach(o => {
    const d = o.delivery_date?.slice(0, 10);
    if (!d) return;
    if (!ordersByDate[d]) ordersByDate[d] = [];
    ordersByDate[d].push(o);
  });

  // Последний заказ по поставщику
  const lastOrderBySupplier = {};
  orders.forEach(o => {
    const d = new Date(o.delivery_date);
    const sup = o.supplier;
    if (!sup) return;
    if (!lastOrderBySupplier[sup] || d > lastOrderBySupplier[sup]) {
      lastOrderBySupplier[sup] = d;
    }
  });

  // Ячейки календаря
  const cells = [];
  // Пустые ячейки в начале
  for (let i = 0; i < startDay; i++) {
    cells.push({ empty: true });
  }

  for (let day = 1; day <= daysInMonth; day++) {
    const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    const cellDate = new Date(year, month, day);
    const isToday = cellDate.getTime() === today.getTime();
    const isPast = cellDate < today && !isToday;
    const dayOrders = ordersByDate[dateStr] || [];

    cells.push({
      day,
      dateStr,
      isToday,
      isPast,
      orders: dayOrders.map(o => {
        const nonZeroItems = (o.order_items || []).filter(i => parseFloat(i.qty_boxes) > 0);
        return {
          id: o.id,
          supplier: o.supplier,
          color: supplierColors[o.supplier] || '#999',
          shortName: (o.supplier || '').length > 6 ? (o.supplier || '').slice(0, 5) + '…' : (o.supplier || ''),
          itemCount: nonZeroItems.length,
          items: nonZeroItems.slice(0, 10).map(i => ({ sku: i.sku, name: i.name, qty: Math.round(parseFloat(i.qty_boxes) || 0) })),
          deliveryDate: o.delivery_date,
        };
      }),
    });
  }

  // Легенда — ближайшие поставки
  const suppliers = Object.keys(supplierColors);
  const legend = suppliers.map(s => {
    const supplierOrders = orders.filter(o => o.supplier === s);
    let nextDelivery = null, lastDelivery = null;
    supplierOrders.forEach(o => {
      const d = new Date(o.delivery_date);
      if (d >= today) { if (!nextDelivery || d < nextDelivery) nextDelivery = d; }
      else { if (!lastDelivery || d > lastDelivery) lastDelivery = d; }
    });

    let daysLabel = '', sortKey = 999, show = false;

    if (lastDelivery) {
      const daysAgo = Math.round((today - lastDelivery) / 86400000);
      if (daysAgo <= 3) {
        daysLabel = daysAgo === 0 ? ' (сегодня)' : ` (${daysAgo}д назад)`;
        sortKey = -100 + daysAgo;
        show = true;
      }
    }

    if (nextDelivery && !show) {
      const daysUntil = Math.round((nextDelivery - today) / 86400000);
      if (daysUntil <= 10) {
        daysLabel = daysUntil === 0 ? ' (сегодня)' : ` (→${daysUntil}д)`;
        sortKey = daysUntil;
        show = true;
      }
    }

    return { name: s, color: supplierColors[s], daysLabel, sortKey, show };
  }).filter(l => l.show).sort((a, b) => a.sortKey - b.sortKey);

  // Просроченные
  const overdue = suppliers.filter(s => {
    const hasFuture = orders.some(o => o.supplier === s && new Date(o.delivery_date) >= today);
    if (hasFuture) return false;
    const lastDate = lastOrderBySupplier[s];
    if (!lastDate) return true;
    return Math.round((today - lastDate) / 86400000) > 14;
  }).map(s => {
    const lastDate = lastOrderBySupplier[s];
    const daysAgo = lastDate ? Math.round((today - lastDate) / 86400000) : null;
    return { name: s, daysAgo };
  });

  return { cells, legend, overdue, daysInMonth };
}
