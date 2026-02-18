/**
 * Модуль для работы с историей заказов
 * Вся логика загрузки, отображения, копирования и удаления
 */

import { supabase } from './supabase.js';
import { showToast, customConfirm } from './modals.js';
import { orderState, currentUser } from './state.js';
import { esc } from './utils.js';

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
      created_by,
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
    
    const noteStr = order.note ? esc(order.note) : '';
    const authorName = order.created_by ? esc(order.created_by) : '';

    // Мета-строка: автор · дата создания · примечание
    const metaParts = [];
    if (authorName) metaParts.push(`<span style="color:#2e7d32;">👤 ${authorName}</span>`);
    if (createdStr) metaParts.push(`<span style="color:#8B7355;">${createdStr}</span>`);
    if (noteStr) metaParts.push(`<span style="color:#666;font-style:italic;">«${noteStr}»</span>`);
    const metaHtml = metaParts.length ? `<div class="history-meta">${metaParts.join(' · ')}</div>` : '';

    div.innerHTML = `
      <div class="history-header">
        <div class="history-title">
          <div><b>${date}</b> — ${esc(order.supplier)}</div>
          ${metaHtml}
        </div>
        <div class="history-actions">
          <button class="btn small edit-order-btn" title="Редактировать">✏️</button>
          <button class="btn small copy-order-btn" title="Скопировать">📋</button>
          <button class="btn small log-order-btn" title="Лог изменений">📝</button>
          <button class="btn small delete-order-btn" title="Удалить">🗑️</button>
        </div>
      </div>
      <div class="history-items hidden">
        ${order.order_items.map(i => {
          const productInfo = i.sku ? productMap[i.sku] : null;
          const qtyPerBox = i.qty_per_box || (productInfo ? productInfo.qty_per_box : null) || 1;
          const unit = productInfo ? productInfo.unit_of_measure : 'шт';
          const pieces = i.qty_boxes * qtyPerBox;
          return `<div>${i.sku ? esc(i.sku) + ' ' : ''}${esc(i.name)} — ${i.qty_boxes} коробок (${nf.format(pieces)} ${unit})</div>`;
        }).join('')}
      </div>
    `;

    const header = div.querySelector('.history-title');
    const editBtn = div.querySelector('.edit-order-btn');
    const copyBtn = div.querySelector('.copy-order-btn');
    const logBtn = div.querySelector('.log-order-btn');
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

    logBtn.onclick = async (e) => {
      e.stopPropagation();
      await showOrderLog(order.id, div);
    };

    deleteBtn.onclick = async (e) => {
      e.stopPropagation();
      await deleteOrder(order.id, opts);
    };

    historyContainer.appendChild(div);
  });
}

/**
 * Показать лог изменений для заказа
 */
async function showOrderLog(orderId, parentDiv) {
  const existing = parentDiv.querySelector('.audit-log-panel');
  if (existing) { existing.remove(); return; }
  
  const panel = document.createElement('div');
  panel.className = 'audit-log-panel';
  panel.innerHTML = '<div style="padding:8px;color:#999;font-size:12px;">Загрузка...</div>';
  parentDiv.appendChild(panel);
  
  try {
    const { data, error } = await supabase
      .from('audit_log')
      .select('*')
      .eq('entity_type', 'order')
      .eq('entity_id', orderId)
      .order('created_at', { ascending: false })
      .limit(20);
    
    if (error || !data?.length) {
      panel.innerHTML = '<div style="padding:8px;color:#999;font-size:12px;">Нет записей в логе</div>';
      return;
    }
    
    const actionLabels = {
      'order_created': '🆕 Создан',
      'order_updated': '✏️ Изменён',
      'order_deleted': '🗑️ Удалён'
    };
    
    panel.innerHTML = data.map(log => {
      const date = new Date(log.created_at);
      const dateStr = date.toLocaleDateString('ru-RU', { day:'2-digit', month:'2-digit', year:'2-digit' });
      const timeStr = date.toLocaleTimeString('ru-RU', { hour:'2-digit', minute:'2-digit' });
      const label = actionLabels[log.action] || log.action;
      const user = log.user_name ? esc(log.user_name) : '—';
      
      let detailsHtml = '';
      const changes = log.details?.changes;
      if (changes?.length) {
        detailsHtml = '<div style="margin-top:4px;padding-left:12px;">' + changes.map(c => {
          if (c.type === 'added') return `<div style="color:#2e7d32;">+ ${esc(c.item)}: ${c.boxes} кор</div>`;
          if (c.type === 'removed') return `<div style="color:#c62828;">− ${esc(c.item)}: ${c.boxes} кор</div>`;
          if (c.type === 'changed') return `<div style="color:#e65100;">~ ${esc(c.item)}: ${c.diffs.join(', ')}</div>`;
          return '';
        }).join('') + '</div>';
      } else if (log.details?.items_count) {
        detailsHtml = ` · <span style="color:#888;">${log.details.items_count} поз.</span>`;
      }
      
      return `<div style="padding:6px 0;border-bottom:1px solid #f0e8f5;font-size:12px;">
        <span style="color:#7b1fa2;font-weight:600;">${label}</span> · <b>${user}</b> · ${dateStr} ${timeStr}
        ${detailsHtml}
      </div>`;
    }).join('');
  } catch(e) {
    panel.innerHTML = '<div style="padding:8px;color:#c62828;font-size:12px;">Ошибка загрузки лога</div>';
  }
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

  // Batch-загрузка продуктов
  const skus = order.order_items.map(i => i.sku).filter(Boolean);
  let productMap = {};
  if (skus.length > 0) {
    const { data: productsData } = await supabase
      .from('products')
      .select('*')
      .in('sku', skus);
    if (productsData) {
      productMap = Object.fromEntries(productsData.map(p => [p.sku, p]));
    }
  }

  // Сортируем: заказанные сверху, нулевые снизу
  const sortedItems = [...order.order_items].sort((a, b) => (b.qty_boxes || 0) - (a.qty_boxes || 0));

  for (const histItem of sortedItems) {
    const productData = histItem.sku ? productMap[histItem.sku] : null;

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
  
  // Лог
  try {
    await supabase.from('audit_log').insert({
      action: 'order_deleted',
      entity_type: 'order',
      entity_id: orderId,
      user_name: currentUser?.name || null,
      details: {}
    });
  } catch(e) { /* не блокируем */ }
  
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

    // Мета-строка
    const metaParts = [];
    if (plan.created_by) metaParts.push(`<span style="color:#2e7d32;">👤 ${esc(plan.created_by)}</span>`);
    metaParts.push(`<span style="color:#8B7355;">${date}</span>`);
    metaParts.push(`<span>${items.length} поз. · ${nf.format(totalBoxes)} кор</span>`);

    const div = document.createElement('div');
    div.className = 'history-order';
    div.innerHTML = `
      <div class="history-header">
        <div class="history-title">
          <div><b>${esc(plan.supplier || '—')}</b> · ${periodLabel}${startLabel}</div>
          <div class="history-meta">${metaParts.join(' · ')}</div>
        </div>
        <div class="history-actions">
          <button class="btn small load-plan-btn" data-id="${plan.id}" title="Загрузить план">✏️</button>
          <button class="btn small log-plan-btn" data-id="${plan.id}" title="Лог изменений">📝</button>
          <button class="btn small delete-plan-btn" data-id="${plan.id}" title="Удалить план">🗑️</button>
        </div>
      </div>
    `;

    const loadBtn = div.querySelector('.load-plan-btn');
    loadBtn.onclick = () => {
      document.dispatchEvent(new CustomEvent('history:load-plan', { detail: { plan } }));
      document.getElementById('historyModal')?.classList.add('hidden');
    };

    const logBtn = div.querySelector('.log-plan-btn');
    logBtn.onclick = async (e) => {
      e.stopPropagation();
      await showPlanLog(plan.id, div);
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
      // Аудит
      try {
        await supabase.from('audit_log').insert({
          action: 'plan_deleted',
          entity_type: 'plan',
          entity_id: null,
          user_name: currentUser?.name || null,
          details: { supplier: plan.supplier }
        });
      } catch(e) { /* не блокируем */ }
      showToast('План удалён', '', 'success');
      loadPlanHistory(opts);
    };

    historyContainer.appendChild(div);
  });
}

/**
 * Показать лог изменений для плана
 */
async function showPlanLog(planId, parentDiv) {
  const existing = parentDiv.querySelector('.audit-log-panel');
  if (existing) { existing.remove(); return; }
  
  const panel = document.createElement('div');
  panel.className = 'audit-log-panel';
  panel.innerHTML = '<div style="padding:8px;color:#999;font-size:12px;">Загрузка...</div>';
  parentDiv.appendChild(panel);
  
  try {
    // Планы не имеют UUID entity_id, ищем по supplier + type
    const { data, error } = await supabase
      .from('audit_log')
      .select('*')
      .eq('entity_type', 'plan')
      .order('created_at', { ascending: false })
      .limit(30);
    
    // Фильтруем по supplier из details (т.к. entity_id null для планов)
    const filtered = (data || []).filter(log => {
      return log.details?.supplier && log.details.supplier === parentDiv.querySelector('.history-title b')?.textContent;
    });
    
    if (error || !filtered.length) {
      panel.innerHTML = '<div style="padding:8px;color:#999;font-size:12px;">Нет записей в логе</div>';
      return;
    }
    
    const actionLabels = {
      'plan_created': '🆕 Создан',
      'plan_updated': '✏️ Изменён',
      'plan_deleted': '🗑️ Удалён'
    };
    
    panel.innerHTML = filtered.slice(0, 10).map(log => {
      const date = new Date(log.created_at);
      const dateStr = date.toLocaleDateString('ru-RU', { day:'2-digit', month:'2-digit', year:'2-digit' });
      const timeStr = date.toLocaleTimeString('ru-RU', { hour:'2-digit', minute:'2-digit' });
      const label = actionLabels[log.action] || log.action;
      const user = log.user_name ? esc(log.user_name) : '—';
      const count = log.details?.items_count ? ` · ${log.details.items_count} поз.` : '';
      return `<div style="padding:4px 0;border-bottom:1px solid #f0e8f5;font-size:12px;">
        <span style="color:#7b1fa2;font-weight:600;">${label}</span> · <b>${user}</b> · ${dateStr} ${timeStr}${count}
      </div>`;
    }).join('');
  } catch(e) {
    panel.innerHTML = '<div style="padding:8px;color:#c62828;font-size:12px;">Ошибка загрузки лога</div>';
  }
}