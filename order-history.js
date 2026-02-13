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
  const historyType = document.getElementById('historyType');
  
  // Если выбрано "Планирование" — загружаем планы
  if (historyType && historyType.value === 'plans') {
    return loadPlanHistory(opts);
  }
  
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
          <button class="btn small edit-order-btn" style="background:#e3f2fd;color:#1565c0;" title="Редактировать заказ">✏️</button>
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
    const editBtn = div.querySelector('.edit-order-btn');
    const copyBtn = div.querySelector('.copy-order-btn');
    const deleteBtn = div.querySelector('.delete-order-btn');

    header.style.cursor = 'pointer';
    header.onclick = () => {
      div.querySelector('.history-items').classList.toggle('hidden');
    };

    editBtn.onclick = async (e) => {
      e.stopPropagation();
      const confirmed = await customConfirm('Редактировать заказ?', 'Заказ будет загружен в форму. При сохранении — обновится поверх старого.');
      if (!confirmed) return;
      document.dispatchEvent(new CustomEvent('history:edit-order', {
        detail: { order, legalEntity }
      }));
      opts.callbacks.historyModal.classList.add('hidden');
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

/* ═══════ ИСТОРИЯ ПЛАНИРОВАНИЯ ═══════ */

async function loadPlanHistory(opts) {
  const { historyContainer } = opts;
  const historyLegalEntity = document.getElementById('historyLegalEntity');
  const historySupplier = document.getElementById('historySupplier');
  
  historyContainer.innerHTML = '<div style="text-align:center;padding:20px;"><div class="loading-spinner"></div><div>Загрузка...</div></div>';

  let query = supabase
    .from('plans')
    .select('*')
    .order('created_at', { ascending: false })
    .limit(50);

  const legalEntity = historyLegalEntity?.value;
  if (legalEntity) query = query.eq('legal_entity', legalEntity);
  if (historySupplier?.value) query = query.eq('supplier', historySupplier.value);

  const { data, error } = await query;

  if (error) {
    historyContainer.innerHTML = '<div style="padding:20px;color:var(--error);">Ошибка загрузки. Создайте таблицу plans в Supabase.</div>';
    console.error(error);
    return;
  }

  if (!data || !data.length) {
    historyContainer.innerHTML = '<div style="padding:20px;text-align:center;color:var(--muted);">Нет сохранённых планов</div>';
    return;
  }

  historyContainer.innerHTML = '';

  data.forEach(plan => {
    const items = plan.items || [];
    const date = new Date(plan.created_at).toLocaleDateString('ru-RU');
    const periodLabel = plan.period_type === 'weeks' ? `${plan.period_count} нед.` : `${plan.period_count} мес.`;
    const totalBoxes = items.reduce((sum, item) => {
      return sum + (item.plan || []).reduce((s, p) => s + (p.order_boxes || 0), 0);
    }, 0);

    const startDate = plan.start_date ? new Date(plan.start_date).toLocaleDateString('ru-RU', {day:'2-digit',month:'2-digit'}) : '';
    const startLabel = startDate ? ` с ${startDate}` : '';

    const div = document.createElement('div');
    div.className = 'history-card';
    div.innerHTML = `
      <div class="history-header" style="display:flex;justify-content:space-between;align-items:center;">
        <span>
          <b>${plan.supplier || '—'}</b> · ${date} · ${periodLabel}${startLabel}
          <span style="color:var(--muted);font-size:12px;">${items.length} позиций · ${nf.format(totalBoxes)} кор</span>
        </span>
        <div style="display:flex;gap:6px;">
          <button class="btn small load-plan-btn" data-id="${plan.id}"><img src="./icons/edit.svg" width="12" height="12" alt=""> Загрузить</button>
          <button class="btn small delete-plan-btn" data-id="${plan.id}" style="background:var(--error);color:white;"><img src="./icons/delete.svg" width="12" height="12" alt=""></button>
        </div>
      </div>
    `;

    const loadBtn = div.querySelector('.load-plan-btn');
    loadBtn.onclick = () => {
      document.dispatchEvent(new CustomEvent('history:load-plan', { detail: { plan } }));
      document.getElementById('historyModal')?.classList.add('hidden');
    };

    const deleteBtn = div.querySelector('.delete-plan-btn');
    deleteBtn.onclick = async () => {
      const confirmed = await customConfirm('Удалить план?', `${plan.supplier} от ${date}`);
      if (!confirmed) return;
      const { error: delErr } = await supabase.from('plans').delete().eq('id', plan.id);
      if (delErr) {
        showToast('Ошибка', 'Не удалось удалить', 'error');
        return;
      }
      showToast('План удалён', '', 'success');
      loadPlanHistory(opts);
    };

    historyContainer.appendChild(div);
  });
}