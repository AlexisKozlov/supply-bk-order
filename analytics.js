/**
 * Модуль аналитики заказов — v1.6.3
 */

import { supabase } from './supabase.js';

/* =============== ПОЛУЧЕНИЕ ДАННЫХ =============== */

export async function getOrdersAnalytics(legalEntity, days = 30) {
  const startDate = new Date();
  startDate.setDate(startDate.getDate() - days);

  const { data: orders, error } = await supabase
    .from('orders')
    .select(`
      id,
      delivery_date,
      supplier,
      created_at,
      order_items ( qty_boxes, qty_per_box, sku, name )
    `)
    .eq('legal_entity', legalEntity)
    .gte('created_at', startDate.toISOString())
    .order('created_at', { ascending: true });

  if (error) { console.error(error); return null; }

  const prevStart = new Date(startDate);
  prevStart.setDate(prevStart.getDate() - days);
  const { data: prevOrders } = await supabase
    .from('orders')
    .select('id, order_items ( qty_boxes )')
    .eq('legal_entity', legalEntity)
    .gte('created_at', prevStart.toISOString())
    .lt('created_at', startDate.toISOString());

  return processData(orders, prevOrders || []);
}

function processData(orders, prevOrders) {
  const byDay = {};
  const bySupplier = {};
  const byProduct = {};
  let totalBoxes = 0;
  const uniqueSkus = new Set();

  orders.forEach(order => {
    const date = new Date(order.created_at).toLocaleDateString('ru-RU');
    const supplier = order.supplier || 'Без поставщика';
    const boxes = order.order_items.reduce((s, i) => s + (i.qty_boxes || 0), 0);
    totalBoxes += boxes;

    if (!byDay[date]) byDay[date] = { date, orders: 0, boxes: 0 };
    byDay[date].orders++;
    byDay[date].boxes += boxes;

    if (!bySupplier[supplier]) bySupplier[supplier] = { supplier, orders: 0, boxes: 0 };
    bySupplier[supplier].orders++;
    bySupplier[supplier].boxes += boxes;

    order.order_items.forEach(item => {
      const key = item.sku || item.name;
      if (key) uniqueSkus.add(key);
      if (!byProduct[key]) byProduct[key] = { sku: item.sku, name: item.name, boxes: 0, orders: 0 };
      byProduct[key].boxes += item.qty_boxes || 0;
      byProduct[key].orders++;
    });
  });

  const prevBoxes = prevOrders.reduce((s, o) =>
    s + (o.order_items || []).reduce((ss, i) => ss + (i.qty_boxes || 0), 0), 0);
  const prevCount = prevOrders.length;
  const totalOrders = orders.length;
  const delta = prevBoxes > 0 ? Math.round((totalBoxes - prevBoxes) / prevBoxes * 100) : null;
  const deltaOrders = prevCount > 0 ? Math.round((totalOrders - prevCount) / prevCount * 100) : null;

  return {
    byDay: Object.values(byDay),
    bySupplier: Object.values(bySupplier).sort((a, b) => b.boxes - a.boxes),
    topProducts: Object.values(byProduct).sort((a, b) => b.boxes - a.boxes).slice(0, 10),
    totals: { orders: totalOrders, boxes: totalBoxes,
      avg: totalOrders > 0 ? Math.round(totalBoxes / totalOrders) : 0,
      uniqueProducts: uniqueSkus.size },
    prev: { boxes: prevBoxes, orders: prevCount },
    delta, deltaOrders
  };
}

/* =============== РЕНДЕР =============== */

export function renderAnalytics(data, container) {
  if (!data) {
    container.innerHTML = `<div style="padding:40px;text-align:center;color:#999;">Нет данных за выбранный период</div>`;
    return;
  }
  const nf = new Intl.NumberFormat('ru-RU');

  const deltaBadge = (val) => {
    if (val === null) return '';
    const cls = val >= 0 ? 'color:#2e7d32;background:#e8f5e9' : 'color:#c62828;background:#ffebee';
    const sign = val >= 0 ? '▲' : '▼';
    return `<span style="font-size:12px;padding:2px 6px;border-radius:10px;${cls}">${sign} ${Math.abs(val)}%</span>`;
  };

  container.innerHTML = `
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px;">
      ${card('#F5A623','#ff8c42', nf.format(data.totals.orders), 'Заказов', data.deltaOrders !== null ? `vs прошлый ${deltaBadge(data.deltaOrders)}` : '')}
      ${card('#F5A623','#ff8c42', nf.format(data.totals.boxes), 'Коробок', data.delta !== null ? `vs прошлый ${deltaBadge(data.delta)}` : '')}
      ${card('#8B4513','#a0522d', nf.format(data.totals.avg), 'Среднее кор/заказ', '')}
      ${card('#8B4513','#a0522d', nf.format(data.totals.uniqueProducts), 'Уникальных товаров', '')}
    </div>

    <div style="background:white;border:1px solid #e8e3db;border-radius:10px;padding:20px;margin-bottom:20px;">
      <div style="font-size:16px;font-weight:700;color:#5A2D0C;margin-bottom:16px;">📅 Заказы по дням</div>
      <div id="anDayChart" style="
        display:flex;
        align-items:flex-end;
        gap:6px;
        height:200px;
        padding-bottom:28px;
        position:relative;
        overflow-x:auto;
        overflow-y:visible;
      "></div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
      <div style="background:white;border:1px solid #e8e3db;border-radius:10px;padding:20px;">
        <div style="font-size:16px;font-weight:700;color:#5A2D0C;margin-bottom:16px;">🏢 По поставщикам</div>
        ${data.bySupplier.length === 0
          ? '<div style="color:#999;text-align:center;padding:20px;">Нет данных</div>'
          : data.bySupplier.map(s => {
              const pct = data.totals.boxes > 0 ? (s.boxes / data.totals.boxes * 100).toFixed(1) : 0;
              return `<div style="margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                  <span style="font-weight:600;color:#5A2D0C;font-size:14px;">${s.supplier}</span>
                  <span style="font-size:12px;color:#888;">${nf.format(s.boxes)} кор. · ${s.orders} заказов · ${pct}%</span>
                </div>
                <div style="height:10px;background:#f0ece5;border-radius:5px;overflow:hidden;">
                  <div style="height:100%;width:${pct}%;background:linear-gradient(90deg,#F5A623,#ff8c42);border-radius:5px;transition:width .5s;"></div>
                </div>
              </div>`;
            }).join('')}
      </div>

      <div style="background:white;border:1px solid #e8e3db;border-radius:10px;padding:20px;">
        <div style="font-size:16px;font-weight:700;color:#5A2D0C;margin-bottom:16px;">📊 Сравнение периодов</div>
        ${cmpBlock(data, nf)}
      </div>
    </div>

    <div style="background:white;border:1px solid #e8e3db;border-radius:10px;padding:20px;">
      <div style="font-size:16px;font-weight:700;color:#5A2D0C;margin-bottom:16px;">🔥 Топ-10 товаров</div>
      ${data.topProducts.length === 0
        ? '<div style="color:#999;text-align:center;padding:20px;">Нет данных</div>'
        : data.topProducts.map((p, i) => {
            const maxB = data.topProducts[0].boxes;
            const pct = maxB > 0 ? (p.boxes / maxB * 100).toFixed(1) : 0;
            const medal = i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : `${i+1}`;
            return `<div style="display:grid;grid-template-columns:36px 1fr 140px;gap:10px;align-items:center;padding:8px 0;border-bottom:1px solid #f5f0ea;">
              <div style="width:32px;height:32px;background:${i<3?'#F5A623':'#d4c5b0'};color:white;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;">${i<3?medal:i+1}</div>
              <div>
                <div style="font-weight:600;color:#5A2D0C;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${p.name || p.sku || '—'}</div>
                <div style="height:8px;background:#f0ece5;border-radius:4px;overflow:hidden;margin-top:4px;">
                  <div style="height:100%;width:${pct}%;background:linear-gradient(90deg,#4CAF50,#66bb6a);border-radius:4px;transition:width .5s;"></div>
                </div>
              </div>
              <div style="text-align:right;">
                <div style="font-weight:700;color:#5A2D0C;font-size:14px;">${nf.format(p.boxes)} кор.</div>
                <div style="font-size:12px;color:#888;">${p.orders} заказов</div>
              </div>
            </div>`;
          }).join('')}
    </div>
  `;

  renderDayChart(data.byDay, document.getElementById('anDayChart'), nf);
}

function card(c1, c2, val, label, sub) {
  return `
  <div style="background:linear-gradient(135deg,${c1},${c2});color:white;padding:18px;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,.12);text-align:center;">
    <div style="font-size:28px;font-weight:800;margin-bottom:4px;">${val}</div>
    <div style="font-size:13px;opacity:.9;margin-bottom:${sub?'6px':'0'};">${label}</div>
    ${sub ? `<div>${sub}</div>` : ''}
  </div>`;
}

function cmpBlock(data, nf) {
  if (data.prev.orders === 0) {
    return '<div style="color:#999;text-align:center;padding:20px;">Нет данных за предыдущий период</div>';
  }
  const maxO = Math.max(data.totals.orders, data.prev.orders);
  const maxB = Math.max(data.totals.boxes, data.prev.boxes);
  return `
    <div style="margin-bottom:16px;">
      <div style="font-size:13px;color:#888;margin-bottom:8px;">Заказов</div>
      ${cmpBar('Текущий', data.totals.orders, maxO, '#F5A623', nf)}
      ${cmpBar('Прошлый', data.prev.orders, maxO, '#d4c5b0', nf)}
    </div>
    <div>
      <div style="font-size:13px;color:#888;margin-bottom:8px;">Коробок</div>
      ${cmpBar('Текущий', data.totals.boxes, maxB, '#F5A623', nf)}
      ${cmpBar('Прошлый', data.prev.boxes, maxB, '#d4c5b0', nf)}
    </div>`;
}

function cmpBar(label, val, max, color, nf) {
  const pct = max > 0 ? Math.round(val / max * 100) : 0;
  return `<div style="margin-bottom:8px;">
    <div style="display:flex;justify-content:space-between;font-size:12px;color:#666;margin-bottom:3px;">
      <span>${label}</span><span style="font-weight:600;">${nf.format(val)}</span>
    </div>
    <div style="height:14px;background:#f0ece5;border-radius:7px;overflow:hidden;">
      <div style="height:100%;width:${pct}%;background:${color};border-radius:7px;transition:width .6s;"></div>
    </div>
  </div>`;
}

function renderDayChart(data, container, nf) {
  if (!container || data.length === 0) return;
  const maxBoxes = Math.max(...data.map(d => d.boxes), 1);
  const CHART_H = 160;

  container.innerHTML = data.map(d => {
    const barH = Math.max(Math.round((d.boxes / maxBoxes) * CHART_H), 4);
    const tooltip = `${d.date}: ${nf.format(d.boxes)} кор., ${d.orders} заказ.`;
    return `
    <div title="${tooltip}" style="
      flex:1;min-width:36px;max-width:60px;
      display:flex;flex-direction:column;align-items:center;
      gap:0;cursor:pointer;
    ">
      <div style="font-size:10px;font-weight:700;color:#5A2D0C;margin-bottom:3px;white-space:nowrap;">${nf.format(d.boxes)}</div>
      <div style="
        width:85%;height:${barH}px;
        background:linear-gradient(to top,#F5A623,#ffb84d);
        border-radius:4px 4px 0 0;
        transition:all .2s;
        position:relative;
      " onmouseover="this.style.background='linear-gradient(to top,#e69500,#ffcf7a)';this.style.transform='translateY(-2px)'"
         onmouseout="this.style.background='linear-gradient(to top,#F5A623,#ffb84d)';this.style.transform='none'"></div>
      <div style="
        width:85%;height:28px;
        display:flex;align-items:center;justify-content:center;
        font-size:10px;color:#888;
        border-top:2px solid #e8e3db;
      ">${d.date.slice(0, 5)}</div>
    </div>`;
  }).join('');
}