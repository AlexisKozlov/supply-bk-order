import { db } from './apiClient.js';
import { getQpb, getMultiplicity } from './utils.js';

/**
 * Сохранить или обновить заказ в БД.
 * @returns {Promise<{orderId, itemsCount, error}>}
 */
export async function saveOrder({ items, settings, editingOrderId, note, userName }) {
  // Приводим все позиции к физическим коробкам (формат хранения)
  const allItems = items.map(item => {
    const qpb  = getQpb(item);
    const mult = getMultiplicity(item);
    const physBoxes = settings.unit === 'boxes'
      ? item.finalOrder / mult
      : item.finalOrder / (qpb * mult);
    return {
      sku:                item.sku || null,
      name:               item.name,
      qty_boxes:          Math.round(Math.ceil(Math.max(0, physBoxes))),
      qty_per_box:        Math.round(item.qtyPerBox || 1),
      multiplicity:       mult,
      consumption_period: Math.round(item.consumptionPeriod || 0),
      stock:              Math.round(item.stock || 0),
      transit:            Math.round(item.transit || 0),
    };
  });

  const itemsWithOrder = allItems.filter(i => i.qty_boxes > 0);
  if (!itemsWithOrder.length) {
    return { error: 'Нет позиций с количеством. Укажите количество для заказа.' };
  }

  const orderData = {
    supplier:          settings.supplier || 'Свободный',
    delivery_date:     settings.deliveryDate ? settings.deliveryDate.toISOString().slice(0, 10) : null,
    today_date:        settings.today       ? settings.today.toISOString().slice(0, 10)        : null,
    safety_days:       settings.safetyDays  || 0,
    period_days:       settings.periodDays  || 30,
    unit:              settings.unit        || 'pieces',
    legal_entity:      settings.legalEntity,
    note:              note || null,
    has_transit:       String(settings.hasTransit || false),
    show_stock_column: String(settings.showStockColumn || false),
  };

  let orderId;
  let auditAction;
  let auditDetails;

  if (editingOrderId) {
    // Загружаем старый заказ для diff параметров
    const { data: oldOrder } = await db
      .from('orders')
      .select('delivery_date, today_date, safety_days, period_days, unit, note, supplier')
      .eq('id', editingOrderId)
      .single();

    // Загружаем старые позиции для diff
    const { data: oldItems } = await db
      .from('order_items')
      .select('sku, name, qty_boxes, consumption_period, stock')
      .eq('order_id', editingOrderId);

    const { error } = await db.from('orders').update(orderData).eq('id', editingOrderId);
    if (error) return { error: 'Не удалось обновить заказ: ' + error.message };

    await db.from('order_items').delete().eq('order_id', editingOrderId);
    orderId = editingOrderId;

    // Param diff
    const paramChanges = [];
    if (oldOrder) {
      const newDelivery = orderData.delivery_date || '';
      const oldDelivery = oldOrder.delivery_date || '';
      if (newDelivery !== oldDelivery) paramChanges.push({ label: 'Дата прихода', from: oldDelivery || '—', to: newDelivery || '—' });
      const newToday = orderData.today_date || '';
      const oldToday = oldOrder.today_date || '';
      if (newToday !== oldToday) paramChanges.push({ label: 'Сегодня', from: oldToday || '—', to: newToday || '—' });
      if ((oldOrder.safety_days || 0) !== (orderData.safety_days || 0)) paramChanges.push({ label: 'Запас', from: oldOrder.safety_days + ' дн.', to: orderData.safety_days + ' дн.' });
      if ((oldOrder.period_days || 30) !== (orderData.period_days || 30)) paramChanges.push({ label: 'Период', from: oldOrder.period_days + ' дн.', to: orderData.period_days + ' дн.' });
      if ((oldOrder.unit || 'pieces') !== (orderData.unit || 'pieces')) paramChanges.push({ label: 'Единица', from: oldOrder.unit, to: orderData.unit });
      if ((oldOrder.note || '') !== (note || '')) paramChanges.push({ label: 'Примечание', from: oldOrder.note || '—', to: note || '—' });
    }

    // Diff для аудит-лога
    if (oldItems) {
      const oldMap = new Map(oldItems.map(i => [i.sku || i.name, i]));
      const changes = [];
      allItems.forEach(newItem => {
        const key = newItem.sku || newItem.name;
        const old = oldMap.get(key);
        if (!old) {
          if (newItem.qty_boxes > 0) changes.push({ item: key, type: 'added', boxes: newItem.qty_boxes });
        } else {
          const diffs = [];
          if (old.qty_boxes !== newItem.qty_boxes) diffs.push(`заказ: ${old.qty_boxes}→${newItem.qty_boxes}`);
          if (old.consumption_period !== newItem.consumption_period) diffs.push(`расход: ${old.consumption_period}→${newItem.consumption_period}`);
          if (old.stock !== newItem.stock) diffs.push(`остаток: ${old.stock}→${newItem.stock}`);
          if (diffs.length) changes.push({ item: key, type: 'changed', diffs });
          oldMap.delete(key);
        }
      });
      oldMap.forEach((old, key) => {
        if (old.qty_boxes > 0) changes.push({ item: key, type: 'removed', boxes: old.qty_boxes });
      });
      auditAction = 'order_updated';
      auditDetails = { supplier: settings.supplier, changes, param_changes: paramChanges.length ? paramChanges : undefined };
    }
  } else {
    // Новый заказ
    orderData.created_by = userName || null;
    orderData.created_at = new Date().toISOString();
    const { data: order, error } = await db.from('orders').insert(orderData).select().single();
    if (error) return { error: 'Не удалось сохранить заказ: ' + error.message };
    orderId = order.id;
    auditAction  = 'order_created';
    auditDetails = { supplier: settings.supplier, legal_entity: settings.legalEntity, items_count: itemsWithOrder.length, total_items: allItems.length };
  }

  // Вставляем только позиции с заказом
  const { error: itemsError } = await db.from('order_items').insert(
    itemsWithOrder.map(i => ({ order_id: orderId, ...i }))
  );
  if (itemsError) return { error: 'Не удалось сохранить состав заказа: ' + itemsError.message };

  // Аудит-лог (не блокируем)
  try {
    await db.from('audit_log').insert({
      action:      auditAction,
      entity_type: 'order',
      entity_id:   orderId,
      user_name:   userName || null,
      details:     auditDetails,
    });
  } catch(e) { /* не блокируем */ }

  return { orderId, itemsCount: itemsWithOrder.length };
}
