/**
 * Модуль для работы с историей заказов
 * Вся логика загрузки, отображения, копирования и удаления
 */

import { supabase } from './supabase.js';
import { showToast, customConfirm } from './modals.js';
import { orderState } from './state.js';

const nf = new Intl.NumberFormat('ru-RU');

/**
 * Загрузить и отобразить историю заказов
 * @param {Object} opts - { historyContainer, historySupplier, callbacks }
 * callbacks: { addItem, render, saveDraft, safetyStockManager, orderSection, historyModal }
 */
export async function loadOrderHistory(opts) {
  const { historyContainer, historySupplier, callbacks } = opts;
  const historyLegalEntity = document.getElementById('historyLegalEntity');
  
  historyContainer.innerHTML = '<div style="text-align:center;padding:20px;"><div class="loading-spinner"></div><div>Загрузка...</div></div>';

  let query = supabase
    .from('orders')
    .select(`
      id,
      delivery_date,
      today_date,
      supplier,
      legal_entity,
      safety_days,
      period_days,
      unit,
      note,
      created_at,
      has_transit,
      show_stock_column,
      order_items (
        sku,
        name,
        qty_boxes,
        qty_per_box,
        consumption_period,
        stock,
        transit
      )
    `)
    .order('delivery_date', { ascending: false });

  if (historySupplier.value) {
    query = query.eq('supplier', historySupplier.value);
  }

  // Фильтр по юр.лицу — из селектора в модалке или из текущего состояния
  const filterLegalEntity = historyLegalEntity && historyLegalEntity.value 
    ? historyLegalEntity.value 
    : (orderState.settings.legalEntity || document.getElementById('legalEntity').value);
  
  if (filterLegalEntity) {
    query = query.eq('legal_entity', filterLegalEntity);
  }

  const { data, error } = await query;

  if (error) {
    historyContainer.innerHTML = 'Ошибка загрузки истории';
    console.error(error);
    return;
  }

  await renderOrderHistory(data, opts);
}

/**
 * Рендер списка заказов
 */
async function renderOrderHistory(orders, opts) {
  const { historyContainer } = opts;
  
  historyContainer.innerHTML = '';

  if (!orders.length) {
    historyContainer.innerHTML = 'История пуста';
    return;
  }

  // Получаем все SKU для подтягивания данных из products
  const allSkus = [...new Set(
    orders.flatMap(o => o.order_items.map(i => i.sku)).filter(Boolean)
  )];

  const { data: productsData } = await supabase
    .from('products')
    .select('sku, qty_per_box, unit_of_measure')
    .in('sku', allSkus);

  const productMap = {};
  if (productsData) {
    productsData.forEach(p => {
      productMap[p.sku] = {
        qty_per_box: p.qty_per_box,
        unit_of_measure: p.unit_of_measure || 'шт'
      };
    });
  }

  orders.forEach(order => {
    const div = document.createElement('div');
    div.className = 'history-order';

    const date = new Date(order.delivery_date).toLocaleDateString();
    const legalEntity = order.legal_entity || 'Бургер БК';
    
    const createdAt = order.created_at ? new Date(order.created_at) : null;
    const createdDateStr = createdAt 
      ? createdAt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' })
      : '';
    const createdTimeStr = createdAt 
      ? createdAt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
      : '';
    const createdStr = createdAt ? `${createdDateStr} ${createdTimeStr}` : '';
    
    const noteStr = order.note ? ` (${order.note})` : '';

    div.innerHTML = `
      <div class="history-header">
        <span><b>${date}</b> — ${order.supplier}${noteStr}</span>
        <div class="history-actions">
          ${createdStr ? `<span style="font-size:11px;color:#8B7355;margin-right:8px;">📅 ${createdStr}</span>` : ''}
          <button class="btn small copy-order-btn" style="background:var(--orange);color:var(--brown);" title="Скопировать заказ">📋</button>
          <button class="btn small delete-order-btn" style="background:#d32f2f;color:white;" title="Удалить заказ">🗑️</button>
        </div>
      </div>
      <div class="history-items hidden">
        ${order.order_items.map(i => {
          const productInfo = i.sku ? productMap[i.sku] : null;
          const qtyPerBox = i.qty_per_box || (productInfo ? productInfo.qty_per_box : null) || 1;
          const unit = productInfo ? productInfo.unit_of_measure : 'шт';
          const pieces = i.qty_boxes * qtyPerBox;
          return `<div>${i.sku ? i.sku + ' ' : ''}${i.name} — ${i.qty_boxes} коробок (${nf.format(pieces)} ${unit})</div>`;
        }).join('')}
      </div>
    `;

    const header = div.querySelector('.history-header span');
    const copyBtn = div.querySelector('.copy-order-btn');
    const deleteBtn = div.querySelector('.delete-order-btn');

    header.style.cursor = 'pointer';
    header.onclick = () => {
      div.querySelector('.history-items').classList.toggle('hidden');
    };

    copyBtn.onclick = async (e) => {
      e.stopPropagation();
      await copyOrderToForm(order, legalEntity, opts);
    };

    deleteBtn.onclick = async (e) => {
      e.stopPropagation();
      await deleteOrder(order.id, opts);
    };

    historyContainer.appendChild(div);
  });
}

/**
 * Копирование заказа из истории в форму
 */
async function copyOrderToForm(order, legalEntity, opts) {
  const { callbacks } = opts;
  const { addItem, render, saveDraft, safetyStockManager, orderSection, historyModal } = callbacks;
  
  const confirmed = await customConfirm('Скопировать заказ?', 'Текущий заказ будет заменен данными из истории');
  if (!confirmed) return;

  orderState.items = [];

  orderState.settings.legalEntity = legalEntity;
  orderState.settings.supplier = order.supplier || '';
  orderState.settings.today = order.today_date ? new Date(order.today_date) : new Date();
  orderState.settings.deliveryDate = new Date(order.delivery_date);
  orderState.settings.safetyDays = order.safety_days || 0;
  orderState.settings.periodDays = order.period_days || 30;
  orderState.settings.unit = order.unit || 'pieces';
  orderState.settings.hasTransit = order.has_transit || false;
  orderState.settings.showStockColumn = order.show_stock_column || false;

  document.getElementById('legalEntity').value = legalEntity;
  
  // Загружаем поставщиков для юр. лица, затем устанавливаем значение
  if (callbacks.loadSuppliers) {
    await callbacks.loadSuppliers(legalEntity);
  }
  document.getElementById('supplierFilter').value = orderState.settings.supplier;
  document.getElementById('today').value = orderState.settings.today.toISOString().slice(0, 10);
  document.getElementById('deliveryDate').value = orderState.settings.deliveryDate.toISOString().slice(0, 10);
  
  if (safetyStockManager) {
    safetyStockManager.setDays(orderState.settings.safetyDays);
  }
  
  document.getElementById('periodDays').value = orderState.settings.periodDays;
  document.getElementById('unit').value = orderState.settings.unit;
  document.getElementById('hasTransit').value = orderState.settings.hasTransit ? 'true' : 'false';
  document.getElementById('showStockColumn').value = orderState.settings.showStockColumn ? 'true' : 'false';

  for (const histItem of order.order_items) {
    const { data: productData } = await supabase
      .from('products')
      .select('*')
      .eq('sku', histItem.sku)
      .single();

    const qtyPerBox = (productData && productData.qty_per_box) || histItem.qty_per_box || 1;

    if (productData) {
      addItem(productData, true);
    } else {
      addItem({
        sku: histItem.sku,
        name: histItem.name,
        qty_per_box: qtyPerBox,
        boxes_per_pallet: null
      }, true);
    }
    
    const addedItem = orderState.items[orderState.items.length - 1];
    addedItem.consumptionPeriod = histItem.consumption_period || 0;
    addedItem.stock = histItem.stock || 0;
    addedItem.transit = histItem.transit || 0;
    
    if (orderState.settings.unit === 'boxes') {
      addedItem.finalOrder = histItem.qty_boxes;
    } else {
      addedItem.finalOrder = histItem.qty_boxes * qtyPerBox;
    }
  }

  orderSection.classList.remove('hidden');
  render();
  if (callbacks.updateFinalSummary) callbacks.updateFinalSummary();
  saveDraft();
  historyModal.classList.add('hidden');
  showToast('Заказ скопирован', `Загружено ${order.order_items.length} товаров`, 'success');
}

/**
 * Удаление заказа из истории
 */
async function deleteOrder(orderId, opts) {
  const confirmed = await customConfirm('Удалить заказ?', 'Заказ будет удален из истории безвозвратно');
  if (!confirmed) return;

  // Сначала позиции
  const { error: itemsErr } = await supabase
    .from('order_items')
    .delete()
    .eq('order_id', orderId);

  if (itemsErr) {
    showToast('Ошибка удаления', 'Не удалось удалить позиции заказа', 'error');
    console.error(itemsErr);
    return;
  }

  // Затем заказ
  const { error } = await supabase
    .from('orders')
    .delete()
    .eq('id', orderId);

  if (error) {
    showToast('Ошибка удаления', 'Не удалось удалить заказ', 'error');
    console.error(error);
    return;
  }

  showToast('Заказ удалён', '', 'success');
  loadOrderHistory(opts);
}