import { supabase } from './supabase.js';

/* ===== ЗАГРУЗКА ===== */
export async function getOrdersAnalytics(legalEntity, days) {
  const to = new Date();
  const from = new Date();
  from.setDate(to.getDate() - days);

  let q = supabase
    .from('orders')
    .select(`
      id,
      created_at,
      legal_entity,
      order_items (
        product_name:name,
        qty_boxes
      )
    `)
    .gte('created_at', from.toISOString())
    .lte('created_at', to.toISOString());

  if (legalEntity) q = q.eq('legal_entity', legalEntity);

  const { data, error } = await q;
  if (error) {
    console.error(error);
    return { orders: [] };
  }

  return { orders: data || [] };
}

/* ===== СРАВНЕНИЕ ПЕРИОДОВ ===== */
export function comparePeriods(current, previous) {
  const cur = current.length;
  const prev = previous.length;

  const delta = prev === 0 ? 0 : Math.round(((cur - prev) / prev) * 100);
  return { cur, prev, delta };
}

/* ===== АГРЕГАЦИЯ ТОВАРОВ ===== */
export function aggregateProducts(orders) {
  const map = {};

  orders.forEach(o => {
    o.order_items.forEach(i => {
      if (!map[i.product_name]) {
        map[i.product_name] = 0;
      }
      map[i.product_name] += i.qty_boxes || 0;
    });
  });

  return map;
}
