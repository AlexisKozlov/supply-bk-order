/**
 * Модуль операций с заказами
 * Сохранение, копирование, очистка, загрузка заказов, порядок позиций
 */

import { orderState, currentUser } from './state.js';
import { supabase } from './supabase.js';
import { showToast, customConfirm } from './modals.js';
import { saveDraft, clearDraft } from './draft.js';

let editingOrderId = null;
const nf = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });

/** Записать действие в лог изменений */
async function auditLog(action, entityType, entityId, details = {}) {
  try {
    await supabase.from('audit_log').insert({
      action,
      entity_type: entityType,
      entity_id: entityId,
      user_name: currentUser?.name || null,
      details
    });
  } catch(e) { /* не блокируем основную работу */ }
}

function updateEditingIndicator() {
  let badge = document.getElementById('editingBadge');
  if (editingOrderId) {
    if (!badge) {
      badge = document.createElement('span');
      badge.id = 'editingBadge';
      badge.style.cssText = 'background:#fff3e0;color:#e65100;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;margin-left:8px;border:1px solid #ffcc80;';
      document.querySelector('#orderSection h2')?.appendChild(badge);
    }
    badge.textContent = '✏️ Редактирование';
    badge.onclick = () => {
      editingOrderId = null;
      updateEditingIndicator();
      showToast('Режим сброшен', 'Следующее сохранение создаст новый заказ', 'info');
    };
    badge.style.cursor = 'pointer';
    badge.title = 'Нажмите чтобы сбросить — следующее сохранение создаст новый заказ';
  } else if (badge) {
    badge.remove();
  }
}

/* ================= СОХРАНЕНИЕ/ВОССТАНОВЛЕНИЕ ПОРЯДКА В SUPABASE ================= */
export async function saveItemOrder() {
  const supplier = orderState.settings.supplier || 'all';
  const legalEntity = orderState.settings.legalEntity;


  const { error: deleteError } = await supabase
    .from('item_order')
    .delete()
    .eq('supplier', supplier)
    .eq('legal_entity', legalEntity);

  if (deleteError) {
    console.error('❌ Ошибка удаления старого порядка:', deleteError);
  }

  const orderData = orderState.items.map((item, index) => ({
    supplier,
    legal_entity: legalEntity,
    item_id: item.supabaseId || item.id,
    position: index
  }));


  if (orderData.length > 0) {
    const { error } = await supabase
      .from('item_order')
      .insert(orderData);

    if (error) {
      console.error('Ошибка сохранения порядка:', error);
    } else {
    }
  }
}

export async function restoreItemOrder() {
  const supplier = orderState.settings.supplier || 'all';
  const legalEntity = orderState.settings.legalEntity;

  const { data, error } = await supabase
    .from('item_order')
    .select('*')
    .eq('supplier', supplier)
    .eq('legal_entity', legalEntity)
    .order('position');

  if (error) {
    console.error('❌ Ошибка загрузки порядка:', error);
    return;
  }

  if (!data || data.length === 0) {
    return;
  }

  // Восстанавливаем порядок
  const sorted = [];
  data.forEach(orderItem => {
    const item = orderState.items.find(i =>
      (i.supabaseId || i.id) === orderItem.item_id
    );
    if (item) sorted.push(item);
  });

  // Добавляем новые товары которых не было в сохранённом порядке
  orderState.items.forEach(item => {
    if (!sorted.includes(item)) sorted.push(item);
  });

  if (sorted.length === orderState.items.length) {
    orderState.items = sorted;
  }
}

/* ================= ИНИЦИАЛИЗАЦИЯ ОПЕРАЦИЙ С ЗАКАЗАМИ ================= */
export function initOrderOperations(deps) {
  const { render, updateFinalSummary, saveStateToHistory, loadOrderHistory,
          loadSuppliers, safetyStockManager, addItem } = deps;

  const orderSection = document.getElementById('orderSection');
  const tbody = document.getElementById('items');

  /* ================= СОХРАНЕНИЕ ЗАКАЗА ================= */
  document.getElementById('saveOrder').addEventListener('click', async () => {
    if (!orderState.items.length) {
      showToast('Заказ пуст', 'Добавьте товары в заказ', 'error');
      return;
    }

    // Открываем модалку для ввода примечания
    const saveOrderModal = document.getElementById('saveOrderModal');
    const orderNoteInput = document.getElementById('orderNote');
    const confirmSaveBtn = document.getElementById('confirmSaveOrder');
    const cancelSaveBtn = document.getElementById('cancelSaveOrder');
    const closeSaveBtn = document.getElementById('closeSaveOrder');

    // Очищаем предыдущее примечание
    orderNoteInput.value = '';

    // Показываем модалку
    saveOrderModal.classList.remove('hidden');
    orderNoteInput.focus();

    // Промис для ожидания действия пользователя
    const waitForAction = () => new Promise((resolve) => {
      const handleSave = () => {
        cleanup();
        resolve({ confirmed: true, note: orderNoteInput.value.trim() });
      };

      const handleCancel = () => {
        cleanup();
        resolve({ confirmed: false, note: '' });
      };

      const cleanup = () => {
        confirmSaveBtn.removeEventListener('click', handleSave);
        cancelSaveBtn.removeEventListener('click', handleCancel);
        closeSaveBtn.removeEventListener('click', handleCancel);
        saveOrderModal.classList.add('hidden');
      };

      confirmSaveBtn.addEventListener('click', handleSave);
      cancelSaveBtn.addEventListener('click', handleCancel);
      closeSaveBtn.addEventListener('click', handleCancel);
    });

    const { confirmed, note } = await waitForAction();

    if (!confirmed) return;

    const allItems = orderState.items
      .map(item => {
        const qpb = item.qtyPerBox || 1;
        const boxes =
          orderState.settings.unit === 'boxes'
            ? item.finalOrder
            : item.finalOrder / qpb;

        return {
          sku: item.sku || null,
          name: item.name,
          qty_boxes: Math.ceil(Math.max(0, boxes)),
          qty_per_box: item.qtyPerBox || 1,
          consumption_period: item.consumptionPeriod || 0,
          stock: item.stock || 0,
          transit: item.transit || 0
        };
      });

    const itemsWithOrder = allItems.filter(i => i.qty_boxes > 0);

    if (!itemsWithOrder.length) {
      showToast('Нет позиций с количеством', 'Укажите количество для заказа', 'error');
      return;
    }

    const orderData = {
      supplier: orderState.settings.supplier || 'Свободный',
      delivery_date: orderState.settings.deliveryDate,
      today_date: orderState.settings.today,
      safety_days: orderState.settings.safetyDays,
      period_days: orderState.settings.periodDays,
      unit: orderState.settings.unit,
      legal_entity: orderState.settings.legalEntity,
      note: note || null,
      has_transit: orderState.settings.hasTransit || false,
      show_stock_column: orderState.settings.showStockColumn || false,
      created_by: currentUser?.name || null
    };

    let orderId;

    if (editingOrderId) {
      // РЕЖИМ РЕДАКТИРОВАНИЯ — UPDATE существующего заказа
      const { error } = await supabase
        .from('orders')
        .update(orderData)
        .eq('id', editingOrderId);

      if (error) {
        showToast('Ошибка обновления', 'Не удалось обновить заказ', 'error');
        console.error(error);
        return;
      }

      // Удаляем старые позиции
      await supabase.from('order_items').delete().eq('order_id', editingOrderId);
      orderId = editingOrderId;
    } else {
      // НОВЫЙ ЗАКАЗ — INSERT
      orderData.created_at = new Date().toISOString();
      const { data: order, error } = await supabase
        .from('orders')
        .insert(orderData)
        .select()
        .single();

      if (error) {
        showToast('Ошибка сохранения', 'Не удалось сохранить заказ', 'error');
        console.error(error);
        return;
      }
      orderId = order.id;
    }

    const items = allItems.map(i => ({
      order_id: orderId,
      ...i
    }));

    const { error: itemsError } = await supabase
      .from('order_items')
      .insert(items);

    if (itemsError) {
      showToast('Ошибка сохранения', 'Не удалось сохранить состав заказа', 'error');
      console.error(itemsError);
      return;
    }

    const actionLabel = editingOrderId ? 'Заказ обновлён' : 'Заказ сохранён';
    showToast(actionLabel, `Сохранено: ${itemsWithOrder.length} позиций с заказом`, 'success');
    
    // Лог изменений
    auditLog(
      editingOrderId ? 'order_updated' : 'order_created',
      'order',
      orderId,
      {
        supplier: orderState.settings.supplier,
        legal_entity: orderState.settings.legalEntity,
        items_count: itemsWithOrder.length,
        total_items: allItems.length
      }
    );
    
    editingOrderId = null;
    updateEditingIndicator();
    clearDraft();
    
    // Обнуляем все данные но оставляем товары в таблице
    orderState.items.forEach(item => {
      item.consumptionPeriod = 0;
      item.stock = 0;
      item.transit = 0;
      item.finalOrder = 0;
    });
    render();
    if (updateFinalSummary) updateFinalSummary();
    saveDraft();
    
    loadOrderHistory();
  });

  /* ================= КОПИРОВАНИЕ ЗАКАЗА ================= */
  document.getElementById('copyOrder').addEventListener('click', () => {
    if (!orderState.items.length) {
      showToast('Заказ пуст', 'Добавьте товары для копирования', 'error');
      return;
    }

    const deliveryDate = orderState.settings.deliveryDate
      ? orderState.settings.deliveryDate.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
      : '—';

    const lines = orderState.items
      .map(item => {
        const qpb = item.qtyPerBox || 1;
        const boxes =
          orderState.settings.unit === 'boxes'
            ? item.finalOrder
            : item.finalOrder / qpb;

        const pieces =
          orderState.settings.unit === 'pieces'
            ? item.finalOrder
            : item.finalOrder * qpb;

        const roundedBoxes = Math.ceil(boxes);
        const roundedPieces = Math.round(pieces);

        if (roundedBoxes <= 0) return null;

        const name = `${item.sku ? item.sku + ' ' : ''}${item.name}`;
        const unit = item.unitOfMeasure || 'шт';

        return `${name} (${nf.format(roundedPieces)} ${unit}) - ${roundedBoxes} коробок`;
      })
      .filter(Boolean);

    if (!lines.length) {
      showToast('Нет позиций', 'В заказе нет позиций с количеством', 'error');
      return;
    }

    const legalEntity = orderState.settings.legalEntity || 'Бургер БК';

    const text =
`Добрый день!
Просьба поставить для юр. лица ${legalEntity}, на дату - ${deliveryDate}:

${lines.join('\n')}

Спасибо!`;

    navigator.clipboard.writeText(text)
      .then(() => {
        showToast('Скопировано!', `${lines.length} позиций в буфере обмена`, 'success');
      })
      .catch(() => {
        showToast('Ошибка копирования', 'Не удалось скопировать заказ', 'error');
      });
  });

  /* ================= ОЧИСТКА ЗАКАЗА ================= */
  document.getElementById('clearOrder').addEventListener('click', async () => {
    if (!orderState.items.length) {
      showToast('Заказ пуст', 'Нет данных для очистки', 'error');
      return;
    }

    const confirmed = await customConfirm('Очистить данные заказа?', 'Расход, остаток, транзит и заказ будут сброшены. Товары останутся.');
    if (!confirmed) return;

    orderState.items.forEach(item => {
      item.consumptionPeriod = 0;
      item.stock = 0;
      item.transit = 0;
      item.finalOrder = 0;
    });

    editingOrderId = null;
    updateEditingIndicator();
    render();
    saveDraft();
    showToast('Данные очищены', 'Товары сохранены, данные сброшены', 'success');
  });

  /* ================= ЗАГРУЗКА ЗАКАЗА ИЗ ИСТОРИИ/КАЛЕНДАРЯ ================= */
  async function loadOrderIntoForm(order, legalEntity, isEditing = false) {
    // Показываем лоадер
    orderSection.classList.remove('hidden');
    tbody.innerHTML = `<tr><td colspan="11" style="text-align:center;padding:40px;"><div class="loading-spinner"></div><div style="margin-top:10px;color:var(--muted);">Загрузка заказа...</div></td></tr>`;

    orderState.items = [];
    orderState.settings.legalEntity = legalEntity;
    orderState.settings.supplier = order.supplier || '';
    orderState.settings.today = order.today_date ? new Date(order.today_date) : new Date();
    orderState.settings.deliveryDate = new Date(order.delivery_date);
    orderState.settings.safetyDays = order.safety_days || 0;
    orderState.settings.periodDays = order.period_days || 30;
    orderState.settings.unit = order.unit || 'pieces';
    orderState.settings.hasTransit = order.has_transit || false;

    document.getElementById('legalEntity').value = legalEntity;
    await loadSuppliers(legalEntity);
    document.getElementById('supplierFilter').value = orderState.settings.supplier;
    document.getElementById('today').value = orderState.settings.today.toISOString().slice(0, 10);
    document.getElementById('deliveryDate').value = orderState.settings.deliveryDate.toISOString().slice(0, 10);

    if (safetyStockManager) {
      safetyStockManager.setDeliveryDate(orderState.settings.deliveryDate);
      safetyStockManager.setDays(orderState.settings.safetyDays);
    }
    document.getElementById('periodDays').value = orderState.settings.periodDays;
    document.getElementById('unit').value = orderState.settings.unit;
    document.getElementById('hasTransit').value = orderState.settings.hasTransit ? 'true' : 'false';

    // Batch-загрузка данных о продуктах (вместо N отдельных запросов)
    const skus = (order.order_items || []).map(i => i.sku).filter(Boolean);
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

    for (const histItem of (order.order_items || [])) {
      const productData = histItem.sku ? productMap[histItem.sku] : null;

      const qtyPerBox = (productData && productData.qty_per_box) || histItem.qty_per_box || 1;

      addItem(productData || {
        sku: histItem.sku,
        name: histItem.name,
        qty_per_box: qtyPerBox,
        boxes_per_pallet: null
      }, true);

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

    // Режим редактирования
    editingOrderId = isEditing ? order.id : null;
    updateEditingIndicator();

    orderSection.classList.remove('hidden');
    render();
    updateFinalSummary();
    saveDraft();

    const mode = isEditing ? 'Редактирование' : 'Загружен';
    showToast(`Заказ: ${mode}`, `${order.supplier} — ${order.order_items?.length || 0} позиций`, 'success');
  }

  document.addEventListener('calendar:load-order', async (e) => {
    const { order, legalEntity } = e.detail;
    if (!order) return;
    const confirmed = await customConfirm('Загрузить заказ?', `${order.supplier} от ${new Date(order.delivery_date).toLocaleDateString('ru-RU')} — заменить текущий заказ?`);
    if (!confirmed) return;
    await loadOrderIntoForm(order, legalEntity, false);
  });

  // Редактирование из истории
  document.addEventListener('history:edit-order', async (e) => {
    const { order, legalEntity } = e.detail;
    if (!order) return;
    await loadOrderIntoForm(order, legalEntity, true);
  });
}