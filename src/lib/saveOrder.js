import { db } from './apiClient.js';
import { getQpb, getMultiplicity, toLocalDateStr } from './utils.js';

/**
 * Сохранить или обновить заказ в БД.
 * @returns {Promise<{orderId, itemsCount, error}>}
 */
export async function saveOrder({ items, settings, editingOrderId, note, userName, expectedUpdatedAt }) {
 try {
  // Приводим все позиции к учётным коробкам (формат хранения)
  const allItems = items.map(item => {
    const qpb  = getQpb(item) || 1;
    const mult = getMultiplicity(item) || 1;
    const accountingBoxes = settings.unit === 'boxes'
      ? item.finalOrder
      : item.finalOrder / qpb;
    return {
      sku:                item.sku || null,
      name:               item.name,
      qty_boxes:          Math.round(Math.max(0, accountingBoxes)),
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
    delivery_date:     toLocalDateStr(settings.deliveryDate),
    today_date:        toLocalDateStr(settings.today),
    safety_days:       settings.safetyDays  || 0,
    period_days:       settings.periodDays  || 30,
    unit:              settings.unit        || 'pieces',
    legal_entity:      settings.legalEntity,
    note:              note || null,
    has_transit:       settings.hasTransit ? 1 : 0,
    show_stock_column: settings.showStockColumn ? 1 : 0,
    cda_mode:          settings.cdaMode ? 1 : 0,
    safety_coef:       settings.safetyCoef ?? 1.0,
  };

  let orderId;
  let auditAction;
  let auditDetails;
  let oldOrder = null;

  if (editingOrderId) {
    // Загружаем старый заказ для diff параметров
    ({ data: oldOrder } = await db
      .from('orders')
      .select('delivery_date, today_date, safety_days, period_days, unit, note, supplier, created_by, updated_at')
      .eq('id', editingOrderId)
      .single());

    if (!oldOrder) return { error: 'Заказ не найден — возможно, он был удалён' };

    // Проверка на одновременное редактирование
    if (expectedUpdatedAt && oldOrder.updated_at && oldOrder.updated_at !== expectedUpdatedAt) {
      return { error: 'Заказ был изменён другим пользователем. Закройте и откройте заказ заново, чтобы увидеть актуальную версию.' };
    }

    // Загружаем старые позиции для diff
    const { data: oldItems } = await db
      .from('order_items')
      .select('sku, name, qty_boxes, consumption_period, stock')
      .eq('order_id', editingOrderId);

    const { error } = await db.from('orders').update(orderData).eq('id', editingOrderId);
    if (error) return { error: 'Не удалось обновить заказ: ' + error };

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
      const diffKey = (i) => i.sku ? `sku:${i.sku}` : `name:${i.name}`;
      const oldMap = new Map(oldItems.map(i => [diffKey(i), i]));
      const changes = [];
      allItems.forEach(newItem => {
        const key = diffKey(newItem);
        const old = oldMap.get(key);
        if (!old) {
          if (newItem.qty_boxes > 0) changes.push({ item: key, type: 'added', boxes: newItem.qty_boxes });
        } else {
          if (old.qty_boxes > 0 && newItem.qty_boxes === 0) {
            // Позиция обнулена — считаем как удалённую
            changes.push({ item: key, type: 'removed', boxes: old.qty_boxes });
          } else {
            const diffs = [];
            if (old.qty_boxes !== newItem.qty_boxes) diffs.push(`заказ: ${old.qty_boxes}→${newItem.qty_boxes}`);
            if (old.consumption_period !== newItem.consumption_period) diffs.push(`расход: ${old.consumption_period}→${newItem.consumption_period}`);
            if (old.stock !== newItem.stock) diffs.push(`остаток: ${old.stock}→${newItem.stock}`);
            if (diffs.length) changes.push({ item: key, type: 'changed', diffs });
          }
          oldMap.delete(key);
        }
      });
      oldMap.forEach((old, key) => {
        if (old.qty_boxes > 0) changes.push({ item: key, type: 'removed', boxes: old.qty_boxes });
      });
      auditAction = 'order_updated';
      auditDetails = { supplier: settings.supplier, changes, param_changes: paramChanges.length ? paramChanges : undefined };
    } else {
      auditAction = 'order_updated';
      auditDetails = { supplier: settings.supplier, note: 'Не удалось загрузить старые позиции для diff' };
    }
  } else {
    // Новый заказ
    orderData.created_by = userName || null;
    orderData.created_at = new Date().toISOString();
    const { data: order, error } = await db.from('orders').insert(orderData).select().single();
    if (error) return { error: 'Не удалось сохранить заказ: ' + error };
    orderId = order.id;
    auditAction  = 'order_created';
    auditDetails = { supplier: settings.supplier, legal_entity: settings.legalEntity, items_count: itemsWithOrder.length, total_items: allItems.length };
  }

  // Вставляем позиции (при обновлении — атомарно через транзакцию)
  const orderItems = itemsWithOrder.map(i => ({ order_id: orderId, ...i }));
  if (editingOrderId) {
    const { data: rpcResult, error: rpcError } = await db.rpc('replace_order_items', {
      order_id: orderId,
      items: orderItems,
    });
    if (rpcError || (rpcResult && rpcResult.error)) {
      // Откатываем параметры заказа к предыдущим значениям
      if (oldOrder) {
        await db.from('orders').update({
          delivery_date: oldOrder.delivery_date, today_date: oldOrder.today_date,
          safety_days: oldOrder.safety_days, period_days: oldOrder.period_days,
          unit: oldOrder.unit, note: oldOrder.note, supplier: oldOrder.supplier,
        }).eq('id', editingOrderId);
      }
      return { error: 'Не удалось сохранить состав заказа: ' + (rpcError || rpcResult?.error) };
    }
  } else {
    const { error: itemsError } = await db.from('order_items').insert(orderItems);
    if (itemsError) {
      // Откатываем: удаляем заказ без позиций
      await db.from('orders').delete().eq('id', orderId);
      return { error: 'Не удалось сохранить состав заказа: ' + itemsError };
    }
  }

  // Аудит-лог (не блокируем)
  try {
    await db.from('audit_log').insert({
      action:      auditAction,
      entity_type: 'order',
      entity_id:   orderId,
      user_name:   userName || null,
      details:     auditDetails,
    });
  } catch(e) { console.warn('[saveOrder] audit log:', e); }

  // Уведомление только при редактировании чужого заказа
  if (editingOrderId && oldOrder?.created_by && oldOrder.created_by !== userName) {
    try {
      const changes = auditDetails?.changes || [];
      const paramChanges = auditDetails?.param_changes || [];
      const lines = [];
      if (paramChanges.length) {
        paramChanges.forEach(p => lines.push(`${p.label}: ${p.from} → ${p.to}`));
      }
      changes.forEach(c => {
        if (c.type === 'added') lines.push(`+ ${c.item} (${c.boxes} кор.)`);
        else if (c.type === 'removed') lines.push(`− ${c.item} (${c.boxes} кор.)`);
        else if (c.type === 'changed') lines.push(`${c.item}: ${c.diffs.join(', ')}`);
      });
      await db.from('notifications').insert({
        type: 'order',
        title: `${userName} изменил ваш заказ: ${settings.supplier}`,
        message: lines.join('\n') || 'Изменения в заказе',
        entity_type: 'order',
        entity_id: orderId,
        legal_entity: settings.legalEntity,
        created_by: userName || null,
        target_user: oldOrder.created_by,
        read_by: userName ? JSON.stringify([userName]) : '[]',
      });
    } catch(e) { console.warn('[saveOrder] notification:', e); }
  }

  return { orderId, itemsCount: itemsWithOrder.length };
 } catch (e) {
  console.error('[saveOrder] unexpected error:', e);
  return { error: 'Непредвиденная ошибка при сохранении: ' + (e.message || e) };
 }
}
