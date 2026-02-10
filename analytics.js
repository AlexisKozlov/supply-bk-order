/**
 * Модуль аналитики заказов
 */

import { supabase } from './supabase.js';

/**
 * Получить статистику заказов за период
 */
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
      order_items (
        qty_boxes,
        qty_per_box
      )
    `)
    .eq('legal_entity', legalEntity)
    .gte('created_at', startDate.toISOString())
    .order('created_at', { ascending: true });
  
  if (error) {
    console.error('Ошибка загрузки статистики:', error);
    return null;
  }
  
  return processOrdersData(orders);
}

/**
 * Обработка данных заказов для графиков
 */
function processOrdersData(orders) {
  // График заказов по дням
  const ordersByDay = {};
  const ordersBySupplier = {};
  let totalBoxes = 0;
  let totalOrders = orders.length;
  
  orders.forEach(order => {
    const date = new Date(order.created_at).toLocaleDateString('ru-RU');
    const supplier = order.supplier || 'Без поставщика';
    
    // Подсчёт коробок
    const boxes = order.order_items.reduce((sum, item) => sum + (item.qty_boxes || 0), 0);
    totalBoxes += boxes;
    
    // По дням
    if (!ordersByDay[date]) {
      ordersByDay[date] = { date, orders: 0, boxes: 0 };
    }
    ordersByDay[date].orders++;
    ordersByDay[date].boxes += boxes;
    
    // По поставщикам
    if (!ordersBySupplier[supplier]) {
      ordersBySupplier[supplier] = { supplier, orders: 0, boxes: 0 };
    }
    ordersBySupplier[supplier].orders++;
    ordersBySupplier[supplier].boxes += boxes;
  });
  
  return {
    byDay: Object.values(ordersByDay),
    bySupplier: Object.values(ordersBySupplier).sort((a, b) => b.boxes - a.boxes),
    totals: {
      orders: totalOrders,
      boxes: totalBoxes,
      avgBoxesPerOrder: totalOrders > 0 ? Math.round(totalBoxes / totalOrders) : 0
    }
  };
}

/**
 * Получить топ товаров по расходу
 */
export async function getTopProducts(legalEntity, limit = 10) {
  const { data, error } = await supabase
    .from('order_items')
    .select(`
      sku,
      name,
      qty_boxes,
      orders!inner (
        legal_entity,
        created_at
      )
    `)
    .eq('orders.legal_entity', legalEntity)
    .order('created_at', { foreignTable: 'orders', ascending: false })
    .limit(100); // Берём последние 100 записей
  
  if (error) {
    console.error('Ошибка загрузки топ товаров:', error);
    return [];
  }
  
  // Агрегируем по SKU
  const productMap = {};
  data.forEach(item => {
    if (!productMap[item.sku]) {
      productMap[item.sku] = {
        sku: item.sku,
        name: item.name,
        totalBoxes: 0,
        orders: 0
      };
    }
    productMap[item.sku].totalBoxes += item.qty_boxes || 0;
    productMap[item.sku].orders++;
  });
  
  return Object.values(productMap)
    .sort((a, b) => b.totalBoxes - a.totalBoxes)
    .slice(0, limit);
}

/**
 * Рендер статистики в HTML
 */
export function renderAnalytics(analyticsData, container) {
  if (!analyticsData) {
    container.innerHTML = '<div style="padding:20px;text-align:center;">Нет данных за выбранный период</div>';
    return;
  }
  
  const nf = new Intl.NumberFormat('ru-RU');
  
  container.innerHTML = `
    <div class="analytics-container">
      <!-- Общая статистика -->
      <div class="stats-cards">
        <div class="stat-card">
          <div class="stat-value">${nf.format(analyticsData.totals.orders)}</div>
          <div class="stat-label">Всего заказов</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">${nf.format(analyticsData.totals.boxes)}</div>
          <div class="stat-label">Всего коробок</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">${nf.format(analyticsData.totals.avgBoxesPerOrder)}</div>
          <div class="stat-label">Среднее коробок/заказ</div>
        </div>
      </div>
      
      <!-- График по дням -->
      <div class="chart-section">
        <h3>Заказы по дням</h3>
        <div class="simple-chart" id="dayChart"></div>
      </div>
      
      <!-- График по поставщикам -->
      <div class="chart-section">
        <h3>Заказы по поставщикам</h3>
        <div class="suppliers-list">
          ${analyticsData.bySupplier.map(s => `
            <div class="supplier-row">
              <div class="supplier-name">${s.supplier}</div>
              <div class="supplier-stats">
                <span>${nf.format(s.orders)} заказов</span>
                <span>${nf.format(s.boxes)} коробок</span>
              </div>
              <div class="supplier-bar">
                <div class="supplier-bar-fill" style="width: ${(s.boxes / analyticsData.totals.boxes * 100).toFixed(1)}%"></div>
              </div>
            </div>
          `).join('')}
        </div>
      </div>
    </div>
  `;
  
  // Рисуем простой график по дням
  renderDayChart(analyticsData.byDay, document.getElementById('dayChart'));
}

/**
 * Простой график столбцами
 */
function renderDayChart(data, container) {
  if (data.length === 0) {
    container.innerHTML = '<div style="padding:20px;color:#999;">Нет данных</div>';
    return;
  }
  
  const maxBoxes = Math.max(...data.map(d => d.boxes));
  const nf = new Intl.NumberFormat('ru-RU');
  
  container.innerHTML = data.map(d => `
    <div class="chart-bar-container" title="${d.date}: ${nf.format(d.boxes)} коробок">
      <div class="chart-bar" style="height: ${(d.boxes / maxBoxes * 100).toFixed(1)}%">
        <span class="chart-value">${nf.format(d.boxes)}</span>
      </div>
      <div class="chart-label">${d.date.slice(0, 5)}</div>
    </div>
  `).join('');
}

/**
 * Рендер топ товаров
 */
export function renderTopProducts(products, container) {
  const nf = new Intl.NumberFormat('ru-RU');
  
  if (products.length === 0) {
    container.innerHTML = '<div style="padding:20px;text-align:center;color:#999;">Нет данных</div>';
    return;
  }
  
  const maxBoxes = Math.max(...products.map(p => p.totalBoxes));
  
  container.innerHTML = `
    <div class="top-products">
      ${products.map((p, i) => `
        <div class="product-row">
          <div class="product-rank">${i + 1}</div>
          <div class="product-info">
            <div class="product-name">${p.name}</div>
            <div class="product-sku">${p.sku}</div>
          </div>
          <div class="product-stats">
            <div class="product-boxes">${nf.format(p.totalBoxes)} кор.</div>
            <div class="product-orders">${p.orders} заказов</div>
          </div>
          <div class="product-bar">
            <div class="product-bar-fill" style="width: ${(p.totalBoxes / maxBoxes * 100).toFixed(1)}%"></div>
          </div>
        </div>
      `).join('')}
    </div>
  `;
}