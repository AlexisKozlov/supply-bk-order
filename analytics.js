/**
 * Аналитика заказов — v1.6.4
 * График по дням с разбивкой по поставщикам (цвета)
 * Топ-10 товаров, распределение по поставщикам, сравнение периодов
 */

import { supabase } from './supabase.js';

/* ─── Палитра для поставщиков ─── */
const PALETTE = [
  '#F5A623','#4CAF50','#2196F3','#9C27B0',
  '#F44336','#00BCD4','#FF5722','#607D8B',
  '#E91E63','#795548'
];

/* ═══════════════ ДАННЫЕ ═══════════════ */

export async function getOrdersAnalytics(legalEntity, days = 30) {
  const now   = new Date();
  const start = new Date(now); start.setDate(start.getDate() - days);
  const prevS = new Date(start); prevS.setDate(prevS.getDate() - days);

  // Текущий период
  const { data: orders, error } = await supabase
    .from('orders')
    .select('id, delivery_date, supplier, created_at, order_items(qty_boxes, sku, name)')
    .eq('legal_entity', legalEntity)
    .gte('created_at', start.toISOString())
    .order('created_at', { ascending: true });

  if (error) { console.error(error); return null; }

  // Предыдущий период (для сравнения)
  const { data: prevOrders } = await supabase
    .from('orders')
    .select('id, order_items(qty_boxes)')
    .eq('legal_entity', legalEntity)
    .gte('created_at', prevS.toISOString())
    .lt('created_at', start.toISOString());

  return processData(orders || [], prevOrders || [], days);
}

function processData(orders, prevOrders, days) {
  // Собираем уникальных поставщиков для назначения цветов
  const supplierSet = [...new Set(orders.map(o => o.supplier || 'Без поставщика'))];
  const supplierColor = {};
  supplierSet.forEach((s, i) => { supplierColor[s] = PALETTE[i % PALETTE.length]; });

  // --- Данные по дням с разбивкой по поставщикам ---
  const dayMap = {};
  orders.forEach(order => {
    // Формируем ключ дня — ISO дата (для сортировки)
    const raw = new Date(order.created_at);
    const dayKey = raw.toISOString().slice(0, 10);
    const dayLabel = raw.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
    const sup = order.supplier || 'Без поставщика';
    const boxes = (order.order_items || []).reduce((s, i) => s + (i.qty_boxes || 0), 0);

    if (!dayMap[dayKey]) dayMap[dayKey] = { dayKey, dayLabel, total: 0, bySupplier: {} };
    dayMap[dayKey].total += boxes;
    dayMap[dayKey].bySupplier[sup] = (dayMap[dayKey].bySupplier[sup] || 0) + boxes;
  });

  // Заполняем пустые дни чтобы не было дыр
  const startD = new Date(); startD.setDate(startD.getDate() - days);
  const allDays = [];
  for (let i = 0; i < days; i++) {
    const d = new Date(startD); d.setDate(d.getDate() + i);
    const key = d.toISOString().slice(0, 10);
    const label = d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
    allDays.push(dayMap[key] || { dayKey: key, dayLabel: label, total: 0, bySupplier: {} });
  }
  // Убираем хвост нулевых дней в конце (сегодня ещё не завершён)
  while (allDays.length > 1 && allDays[allDays.length - 1].total === 0) allDays.pop();

  // --- Итоги по поставщикам ---
  const supMap = {};
  orders.forEach(o => {
    const sup = o.supplier || 'Без поставщика';
    const boxes = (o.order_items || []).reduce((s, i) => s + (i.qty_boxes || 0), 0);
    if (!supMap[sup]) supMap[sup] = { supplier: sup, orders: 0, boxes: 0, color: supplierColor[sup] };
    supMap[sup].orders++;
    supMap[sup].boxes += boxes;
  });

  // --- Топ-10 товаров ---
  const prodMap = {};
  orders.forEach(o => {
    (o.order_items || []).forEach(item => {
      const key = item.sku || item.name || '?';
      if (!prodMap[key]) prodMap[key] = { sku: item.sku, name: item.name, boxes: 0, orders: 0 };
      prodMap[key].boxes += item.qty_boxes || 0;
      prodMap[key].orders++;
    });
  });

  const totalBoxes  = orders.reduce((s, o) => s + (o.order_items||[]).reduce((ss,i)=>ss+(i.qty_boxes||0),0), 0);
  const totalOrders = orders.length;
  const prevBoxes   = prevOrders.reduce((s,o)=>s+(o.order_items||[]).reduce((ss,i)=>ss+(i.qty_boxes||0),0),0);
  const prevCount   = prevOrders.length;

  return {
    days:         allDays,
    suppliers:    Object.values(supMap).sort((a, b) => b.boxes - a.boxes),
    supplierColor,
    topProducts:  Object.values(prodMap).sort((a, b) => b.boxes - a.boxes).slice(0, 10),
    totals:       { orders: totalOrders, boxes: totalBoxes },
    prev:         { orders: prevCount,   boxes: prevBoxes  },
    deltaOrders:  prevCount > 0 ? Math.round((totalOrders - prevCount) / prevCount * 100) : null,
    deltaBoxes:   prevBoxes > 0 ? Math.round((totalBoxes  - prevBoxes) / prevBoxes  * 100) : null,
  };
}

/* ═══════════════ РЕНДЕР ═══════════════ */

export function renderAnalytics(data, container) {
  if (!data) {
    container.innerHTML = `<div style="padding:60px;text-align:center;color:#999;">Нет данных за выбранный период</div>`;
    return;
  }

  const nf = n => new Intl.NumberFormat('ru-RU').format(n);

  const badge = (val) => {
    if (val === null) return '';
    const up = val >= 0;
    return `<span style="
      font-size:12px;font-weight:600;padding:2px 8px;border-radius:10px;
      background:${up?'#e8f5e9':'#ffebee'};
      color:${up?'#2e7d32':'#c62828'};
    ">${up?'▲':'▼'} ${Math.abs(val)}%</span>`;
  };

  container.innerHTML = `
    <!-- ── КАРТОЧКИ ── -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px;">
      ${statCard('#F5A623','#E09615', nf(data.totals.orders), 'Всего заказов',
          data.deltaOrders !== null
            ? `vs прошлый период &nbsp;${badge(data.deltaOrders)}`
            : 'Нет данных за прошлый период')}
      ${statCard('#F5A623','#E09615', nf(data.totals.boxes), 'Всего коробок',
          data.deltaBoxes !== null
            ? `vs прошлый период &nbsp;${badge(data.deltaBoxes)}`
            : 'Нет данных за прошлый период')}
    </div>

    <!-- ── ГРАФИК ── -->
    <div style="background:white;border:1px solid #e8e3db;border-radius:10px;padding:20px 20px 12px;margin-bottom:20px;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
        <span style="font-size:16px;font-weight:700;color:#5A2D0C;">📅 Коробок по дням</span>
        <div style="display:flex;flex-wrap:wrap;gap:8px;" id="chartLegend"></div>
      </div>
      <div id="anDayChart" style="
        display:flex;align-items:flex-end;gap:3px;
        height:200px;
        overflow-x:auto;overflow-y:visible;
        padding-bottom:28px;
        position:relative;
      "></div>
    </div>

    <!-- ── ПОСТАВЩИКИ ── -->
    <div style="background:white;border:1px solid #e8e3db;border-radius:10px;padding:20px;margin-bottom:20px;">
      <div style="font-size:16px;font-weight:700;color:#5A2D0C;margin-bottom:16px;">🏢 Распределение по поставщикам</div>
      ${data.suppliers.length === 0
        ? '<div style="color:#999;text-align:center;padding:20px;">Нет данных</div>'
        : data.suppliers.map(s => {
          const pct = data.totals.boxes > 0 ? (s.boxes / data.totals.boxes * 100) : 0;
          return `
          <div style="display:grid;grid-template-columns:160px 1fr auto;gap:12px;align-items:center;padding:8px 0;border-bottom:1px solid #f5f0ea;">
            <div style="display:flex;align-items:center;gap:8px;">
              <span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:${s.color};flex-shrink:0;"></span>
              <span style="font-weight:600;color:#5A2D0C;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${s.supplier}</span>
            </div>
            <div style="height:16px;background:#f0ece5;border-radius:8px;overflow:hidden;">
              <div style="height:100%;width:${pct.toFixed(1)}%;background:${s.color};border-radius:8px;transition:width .5s;"></div>
            </div>
            <div style="text-align:right;white-space:nowrap;">
              <span style="font-weight:700;color:#5A2D0C;font-size:13px;">${nf(s.boxes)} кор.</span>
              <span style="font-size:12px;color:#888;margin-left:8px;">${s.orders} заказ. · ${pct.toFixed(1)}%</span>
            </div>
          </div>`;
        }).join('')}
    </div>

    <!-- ── СРАВНЕНИЕ ── -->
    <div style="background:white;border:1px solid #e8e3db;border-radius:10px;padding:20px;margin-bottom:20px;">
      <div style="font-size:16px;font-weight:700;color:#5A2D0C;margin-bottom:16px;">📊 Сравнение периодов</div>
      ${data.prev.orders === 0
        ? '<div style="color:#999;text-align:center;padding:20px;">Нет данных за предыдущий период</div>'
        : compareSections(data, nf)}
    </div>

    <!-- ── ТОП ТОВАРОВ ── -->
    <div style="background:white;border:1px solid #e8e3db;border-radius:10px;padding:20px;margin-bottom:8px;">
      <div style="font-size:16px;font-weight:700;color:#5A2D0C;margin-bottom:16px;">🔥 Топ-10 товаров по заказам</div>
      ${data.topProducts.length === 0
        ? '<div style="color:#999;text-align:center;padding:20px;">Нет данных</div>'
        : topProductsHTML(data.topProducts, nf)}
    </div>
  `;

  // Рисуем график и легенду
  renderStackedChart(data, document.getElementById('anDayChart'), document.getElementById('chartLegend'), nf);
}

/* ── Карточка статистики ── */
function statCard(c1, c2, val, label, sub) {
  return `
  <div style="
    background:linear-gradient(135deg,${c1},${c2});
    color:white;padding:20px 24px;border-radius:12px;
    box-shadow:0 4px 16px rgba(245,166,35,.35);
  ">
    <div style="font-size:36px;font-weight:800;line-height:1.1;margin-bottom:4px;">${val}</div>
    <div style="font-size:14px;opacity:.9;margin-bottom:${sub?'8px':'0'};">${label}</div>
    ${sub ? `<div style="font-size:12px;opacity:.85;">${sub}</div>` : ''}
  </div>`;
}

/* ── Сравнение периодов ── */
function compareSections(data, nf) {
  const maxO = Math.max(data.totals.orders, data.prev.orders, 1);
  const maxB = Math.max(data.totals.boxes,  data.prev.boxes,  1);
  return `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
      ${cmpGroup('Заказов', data.totals.orders, data.prev.orders, maxO, nf)}
      ${cmpGroup('Коробок', data.totals.boxes,  data.prev.boxes,  maxB, nf)}
    </div>`;
}
function cmpGroup(label, cur, prev, max, nf) {
  return `
  <div>
    <div style="font-size:13px;font-weight:600;color:#888;margin-bottom:10px;">${label}</div>
    ${cmpBar('Текущий', cur, max, '#F5A623', nf)}
    ${cmpBar('Прошлый', prev, max, '#d4b896', nf)}
  </div>`;
}
function cmpBar(label, val, max, color, nf) {
  const pct = max > 0 ? Math.round(val / max * 100) : 0;
  return `
  <div style="margin-bottom:8px;">
    <div style="display:flex;justify-content:space-between;font-size:12px;color:#666;margin-bottom:3px;">
      <span>${label}</span>
      <span style="font-weight:700;color:#5A2D0C;">${nf(val)}</span>
    </div>
    <div style="height:14px;background:#f0ece5;border-radius:7px;overflow:hidden;">
      <div style="height:100%;width:${pct}%;background:${color};border-radius:7px;transition:width .6s;"></div>
    </div>
  </div>`;
}

/* ── Топ товаров ── */
function topProductsHTML(products, nf) {
  const maxB = products[0].boxes;
  return products.map((p, i) => {
    const pct = maxB > 0 ? (p.boxes / maxB * 100).toFixed(1) : 0;
    const medal = ['🥇','🥈','🥉'][i] || null;
    const rankBg = i < 3 ? '#F5A623' : '#d4c5b0';
    return `
    <div style="display:grid;grid-template-columns:36px 1fr 130px;gap:12px;align-items:center;padding:9px 0;border-bottom:1px solid #f5f0ea;">
      <div style="
        width:32px;height:32px;background:${rankBg};color:white;
        border-radius:50%;display:flex;align-items:center;justify-content:center;
        font-weight:700;font-size:${medal?'16px':'13px'};flex-shrink:0;
      ">${medal || i+1}</div>
      <div style="overflow:hidden;">
        <div style="font-weight:600;color:#5A2D0C;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${p.name || p.sku || '—'}</div>
        ${p.sku ? `<div style="font-size:11px;color:#aaa;margin-top:1px;">${p.sku}</div>` : ''}
        <div style="height:6px;background:#f0ece5;border-radius:3px;overflow:hidden;margin-top:4px;">
          <div style="height:100%;width:${pct}%;background:linear-gradient(90deg,#4CAF50,#81C784);border-radius:3px;transition:width .5s;"></div>
        </div>
      </div>
      <div style="text-align:right;">
        <div style="font-weight:700;color:#5A2D0C;font-size:14px;">${nf(p.boxes)} кор.</div>
        <div style="font-size:12px;color:#888;">${p.orders} заказов</div>
      </div>
    </div>`;
  }).join('');
}

/* ── Stacked-bar chart по дням ── */
function renderStackedChart(data, chartEl, legendEl, nf) {
  if (!chartEl || data.days.length === 0) return;

  const CHART_H = 160; // рабочая высота в px
  const maxTotal = Math.max(...data.days.map(d => d.total), 1);
  const suppliers = data.suppliers.map(s => s.supplier);

  // Легенда
  if (legendEl) {
    legendEl.innerHTML = suppliers.map(s => `
      <div style="display:flex;align-items:center;gap:4px;font-size:12px;color:#5A2D0C;">
        <span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:${data.supplierColor[s]};"></span>
        ${s}
      </div>`).join('');
  }

  // Отрезаем дни если их слишком много — оставляем только дни с данными + несколько соседних
  const visibleDays = data.days.length <= 45 ? data.days : data.days.slice(-45);

  chartEl.innerHTML = visibleDays.map(day => {
    const totalH = Math.max(Math.round((day.total / maxTotal) * CHART_H), day.total > 0 ? 4 : 0);

    // Сегменты поставщиков снизу вверх
    let segments = '';
    let accH = 0;
    suppliers.forEach((sup, idx) => {
      const boxes = day.bySupplier[sup] || 0;
      if (boxes === 0) return;
      const h = Math.max(Math.round((boxes / maxTotal) * CHART_H), 2);
      const isBottom = idx === suppliers.length - 1 || accH === 0;
      const isTop    = accH + h >= totalH;
      const r = `${isTop?'4px 4px':'0 0'} ${isBottom?'0 0':'0 0'}`;
      segments += `<div style="
        height:${h}px;width:100%;
        background:${data.supplierColor[sup]};
        border-radius:${isTop?'4px 4px 0 0':'0'};
        flex-shrink:0;
      " title="${sup}: ${nf(boxes)} кор."></div>`;
      accH += h;
    });

    // Если нет сегментов — заглушка
    if (!segments && day.total === 0) {
      segments = `<div style="height:2px;width:100%;background:#f0ece5;border-radius:2px;"></div>`;
    }

    return `
    <div style="
      flex:1;min-width:${data.days.length > 20 ? 20 : 36}px;max-width:60px;
      display:flex;flex-direction:column;align-items:center;
    " title="${day.dayLabel}: ${nf(day.total)} кор.">
      ${day.total > 0 ? `<div style="font-size:9px;font-weight:700;color:#5A2D0C;margin-bottom:2px;white-space:nowrap;">${nf(day.total)}</div>` : '<div style="height:14px;"></div>'}
      <div style="
        width:85%;display:flex;flex-direction:column;justify-content:flex-end;
        height:${CHART_H}px;
      ">${segments}</div>
      <div style="
        width:100%;height:28px;
        display:flex;align-items:center;justify-content:center;
        font-size:10px;color:#999;
        border-top:2px solid #e8e3db;
        margin-top:2px;
      ">${day.dayLabel}</div>
    </div>`;
  }).join('');
}