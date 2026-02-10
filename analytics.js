import { supabase } from './supabase.js';

/* ===== ЗАГРУЗКА АНАЛИТИКИ ===== */
export async function getOrdersAnalytics(legalEntity, days) {
  const fromDate = new Date();
  fromDate.setDate(fromDate.getDate() - days);

  const { data, error } = await supabase
    .from('orders')
    .select(`
      id,
      created_at,
      order_items (
        product_name,
        qty_boxes
      )
    `)
    .gte('created_at', fromDate.toISOString());

  if (error) {
    console.error(error);
    return { orders: [] };
  }

  return { orders: data || [] };
}

/* ===== СВОДКА ===== */
export function buildSummary(orders) {
  let totalOrders = orders.length;
  let totalBoxes = 0;

  orders.forEach(order => {
    order.order_items.forEach(item => {
      totalBoxes += item.qty_boxes || 0;
    });
  });

  return {
    totalOrders,
    avgBoxes: totalOrders ? Math.round(totalBoxes / totalOrders) : 0
  };
}

/* ===== РЕНДЕР СВОДКИ ===== */
export function renderSummary(summary, container) {
  container.innerHTML += `
    <div class="analytics-card">
      <b>Сводка</b><br><br>
      Заказов: <b>${summary.totalOrders}</b><br>
      Средний заказ: <b>${summary.avgBoxes}</b> коробок
    </div>
  `;
}

/* ===== ТОП ТОВАРОВ ===== */
export function renderTopProducts(orders, container) {
  const map = {};

  orders.forEach(order => {
    order.order_items.forEach(item => {
      if (!map[item.product_name]) {
        map[item.product_name] = 0;
      }
      map[item.product_name] += item.qty_boxes || 0;
    });
  });

  const sorted = Object.entries(map)
    .sort((a, b) => b[1] - a[1])
    .slice(0, 10);

  let html = '<ul>';
  sorted.forEach(([name, qty]) => {
    html += `<li>${name}: <b>${qty}</b></li>`;
  });
  html += '</ul>';

  container.innerHTML = `
    <div class="analytics-card">
      <b>Топ товаров</b><br><br>
      ${html}
    </div>
  `;
}
