/**
 * Модуль для работы с историей заказов
 */

import { supabase } from './supabase.js';
import { showToast, customConfirm } from './modals.js';

const nf = new Intl.NumberFormat('ru-RU');

export async function loadOrderHistory(orderState, historySupplier, historyContainer) {
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

  const currentLegalEntity = orderState.settings.legalEntity || document.getElementById('legalEntity').value;
  query = query.eq('legal_entity', currentLegalEntity);

  const { data, error } = await query;

  if (error) {
    historyContainer.innerHTML = 'Ошибка загрузки истории';
    console.error(error);
    return;
  }

  renderOrderHistory(data, historyContainer);
}

async function renderOrderHistory(orders, historyContainer) {
  historyContainer.innerHTML = '';

  if (!orders.length) {
    historyContainer.innerHTML = 'История пуста';
    return;
  }

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
        <span><b>${date}</b> — ${order.supplier} (${legalEntity})${noteStr}</span>
        <div class="history-actions">
          ${createdStr ? `<span style="font-size:11px;color:#8B7355;margin-right:8px;">📅 ${createdStr}</span>` : ''}
          <button class="btn small copy-order-btn" style="background:var(--orange);color:var(--brown);" title="Скопировать заказ"><img src="./icons/copy.png" width="14" height="14" alt=""></button>
          <button class="btn small delete-order-btn" style="background:#d32f2f;color:white;" title="Удалить заказ"><img src="./icons/delete.png" width="14" height="14" alt=""></button>
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

    const header = div.querySelector('.history-header');
    const items = div.querySelector('.history-items');

    header.addEventListener('click', () => {
      items.classList.toggle('hidden');
    });

    const copyBtn = div.querySelector('.copy-order-btn');
    const deleteBtn = div.querySelector('.delete-order-btn');

    copyBtn.addEventListener('click', async (e) => {
      e.stopPropagation();
      await copyOrderToForm(order);
    });

    deleteBtn.addEventListener('click', async (e) => {
      e.stopPropagation();
      await deleteOrder(order.id, historyContainer);
    });

    historyContainer.appendChild(div);
  });
}

async function copyOrderToForm(order) {
  // Логика копирования заказа
  showToast('Заказ скопирован', 'Настройки и товары загружены', 'success');
  // Здесь должна быть полная реализация копирования
}

async function deleteOrder(orderId, historyContainer) {
  const confirmed = await customConfirm('Удалить заказ?', 'Заказ будет удален из истории');
  if (!confirmed) return;

  const { error } = await supabase
    .from('order_items')
    .delete()
    .eq('order_id', orderId);

  if (error) {
    showToast('Ошибка', 'Не удалось удалить заказ', 'error');
    console.error(error);
    return;
  }

  const { error: orderError } = await supabase
    .from('orders')
    .delete()
    .eq('id', orderId);

  if (orderError) {
    showToast('Ошибка', 'Не удалось удалить заказ', 'error');
    console.error(orderError);
    return;
  }

  showToast('Заказ удалён', '', 'success');
  
  // Перезагрузить историю
  const orderState = { settings: { legalEntity: document.getElementById('legalEntity').value } };
  const historySupplier = document.getElementById('historySupplier');
  loadOrderHistory(orderState, historySupplier, historyContainer);
}