/**
 * Модуль операций с заказами
 * Сохранение, копирование, очистка, загрузка заказов, порядок позиций
 */

import { orderState, currentUser } from './state.js';
import { supabase } from './supabase.js';
import { showToast, customConfirm } from './modals.js';
import { saveDraft, clearDraft } from './draft.js';
import { calculateItem } from './calculations.js';
import { getQpb, getMultiplicity } from './utils.js';

if (location.protocol !== 'https:') {
    console.warn('Clipboard API может не работать на HTTP. Рекомендуется использовать HTTPS.');
}

// Универсальная функция копирования с fallback
function copyToClipboard(text) {
    // Современный Clipboard API
    if (navigator.clipboard && navigator.clipboard.writeText) {
        return navigator.clipboard.writeText(text);
    }
    
    // Fallback для старых браузеров
    return new Promise((resolve, reject) => {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        
        try {
            textarea.select();
            textarea.setSelectionRange(0, 99999); // для мобильных устройств
            
            const successful = document.execCommand('copy');
            if (successful) {
                resolve();
            } else {
                reject(new Error('Fallback: execCommand failed'));
            }
        } catch (err) {
            reject(err);
        } finally {
            document.body.removeChild(textarea);
        }
    });
}

let editingOrderId = null;
let viewOnlyMode = false;
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
  const pageTitle = document.getElementById('pageTitle');
  
  // Снимаем блокировку просмотра по умолчанию
  document.querySelector('.content-area')?.classList.remove('view-only-mode');
  
  if (viewOnlyMode) {
    // Режим просмотра (из календаря)
    if (pageTitle) pageTitle.textContent = 'Просмотр заказа';
    
    if (!badge) {
      badge = document.createElement('span');
      badge.id = 'editingBadge';
      badge.className = 'editing-badge';
      const anchor = document.querySelector('.table-toolbar-title') || document.querySelector('#orderSection');
      anchor?.parentNode?.insertBefore(badge, anchor?.nextSibling);
    }
    badge.textContent = '👁 Просмотр';
    badge.style.cursor = 'pointer';
    badge.title = 'Нажмите чтобы закрыть просмотр и начать новый заказ';
    badge.onclick = async () => {
      const confirmed = await customConfirm(
        'Закрыть просмотр?',
        'Заказ будет очищен. Создастся новый пустой заказ.'
      );
      if (!confirmed) return;
      viewOnlyMode = false;
      orderState.items.length = 0;
      orderState.settings.supplier = '';
      const supplierSelect = document.getElementById('supplierFilter');
      if (supplierSelect) supplierSelect.value = '';
      updateEditingIndicator();
      document.dispatchEvent(new CustomEvent('order:reset'));
      showToast('Просмотр закрыт', 'Можно начать новый заказ', 'info');
    };
    
    // Блокируем все инпуты
    document.querySelector('.content-area')?.classList.add('view-only-mode');
    
  } else if (editingOrderId) {
    // Заголовок → "Редактирование заказа"
    if (pageTitle) pageTitle.textContent = 'Редактирование заказа';
    
    if (!badge) {
      badge = document.createElement('span');
      badge.id = 'editingBadge';
      badge.className = 'editing-badge';
      const anchor = document.querySelector('.table-toolbar-title') || document.querySelector('#orderSection');
      anchor?.parentNode?.insertBefore(badge, anchor?.nextSibling);
    }
    badge.textContent = '✏️ Редактирование';
    badge.onclick = async () => {
      const confirmed = await customConfirm(
        'Сбросить редактирование?',
        'Заказ будет очищен. Создастся новый пустой заказ.'
      );
      if (!confirmed) return;
      
      editingOrderId = null;
      
      // Полная очистка заказа
      orderState.items.length = 0;
      orderState.settings.supplier = '';
      
      // Сброс селектора поставщика на "Все / свободный"
      const supplierSelect = document.getElementById('supplierFilter');
      if (supplierSelect) supplierSelect.value = '';
      
      // Обновляем UI
      updateEditingIndicator();
      
      // Вызываем render и summary через событие
      document.dispatchEvent(new CustomEvent('order:reset'));
      
      showToast('Заказ сброшен', 'Можно начать новый заказ', 'info');
    };
    badge.style.cursor = 'pointer';
    badge.title = 'Нажмите чтобы сбросить редактирование и очистить заказ';
  } else {
    // Заголовок → "Новый заказ"
    if (pageTitle) pageTitle.textContent = 'Новый заказ';
    
    if (badge) badge.remove();
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
        const qpb = getQpb(item);
        const mult = getMultiplicity(item);
        // Приводим к физическим коробкам для хранения
        let physBoxes;
        if (orderState.settings.unit === 'boxes') {
          physBoxes = item.finalOrder / mult;
        } else {
          physBoxes = item.finalOrder / (qpb * mult);
        }

        return {
          sku: item.sku || null,
          name: item.name,
          qty_boxes: Math.ceil(Math.max(0, physBoxes)),
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
  delivery_date: orderState.settings.deliveryDate 
    ? orderState.settings.deliveryDate.toISOString().slice(0, 10) 
    : null,
  today_date: orderState.settings.today 
    ? orderState.settings.today.toISOString().slice(0, 10) 
    : null,
  safety_days: orderState.settings.safetyDays || 0,
  period_days: orderState.settings.periodDays || 30,
  unit: orderState.settings.unit || 'pieces',
  legal_entity: orderState.settings.legalEntity,
  note: note || null,
  has_transit: orderState.settings.hasTransit || false,
  show_stock_column: orderState.settings.showStockColumn || false
};

    let orderId;

    if (editingOrderId) {
      // РЕЖИМ РЕДАКТИРОВАНИЯ — загружаем старые позиции для diff
      const { data: oldItems } = await supabase
        .from('order_items')
        .select('sku, name, qty_boxes, consumption_period, stock')
        .eq('order_id', editingOrderId);
      
      // UPDATE заказа
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
      
      // Вычисляем diff для лога
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
        
        if (changes.length) {
          auditLog('order_updated', 'order', orderId, {
            supplier: orderState.settings.supplier,
            changes
          });
        }
      }
    } else {
      // НОВЫЙ ЗАКАЗ — INSERT
      orderData.created_by = currentUser?.name || null;
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
    
    // Лог изменений (создание нового заказа; update логируется выше с diff)
    if (!editingOrderId) {
      auditLog(
        'order_created',
        'order',
        orderId,
        {
          supplier: orderState.settings.supplier,
          legal_entity: orderState.settings.legalEntity,
          items_count: itemsWithOrder.length,
          total_items: allItems.length
        }
      );
    }
    
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
            const qpb = getQpb(item);
            const mult = getMultiplicity(item);
            
            // Физические коробки
            let physBoxes;
            if (orderState.settings.unit === 'boxes') {
                physBoxes = item.finalOrder / mult;
            } else {
                physBoxes = item.finalOrder / (qpb * mult);
            }
            
            // Штуки
            const pieces = orderState.settings.unit === 'pieces'
                ? item.finalOrder
                : item.finalOrder * qpb;

            const roundedBoxes = Math.ceil(physBoxes);
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

    // Универсальное копирование с fallback
    copyToClipboard(text).then(() => {
        showToast('Скопировано!', `${lines.length} позиций в буфере обмена`, 'success');
    }).catch(() => {
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
async function loadOrderIntoForm(order, legalEntity, isEditing = false, isViewOnly = false) {
    // ВРЕМЕННАЯ ОТЛАДКА - посмотрим, что приходит из истории
    console.log('🔥 ЗАГРУЗКА ЗАКАЗА ИЗ ИСТОРИИ:');
    console.log('🔥 order object:', order);
    console.log('🔥 order.today_date:', order.today_date);
    console.log('🔥 order.delivery_date:', order.delivery_date);
    console.log('🔥 order.order_items count:', order.order_items?.length || 0);
    if (order.order_items && order.order_items.length > 0) {
        console.log('🔥 Первый товар (сырой):', order.order_items[0]);
    }

    // Показываем лоадер
    orderSection.classList.remove('hidden');
    tbody.innerHTML = `<tr><td colspan="11" style="text-align:center;padding:40px;"><div class="loading-spinner"></div><div style="margin-top:10px;color:var(--muted);">Загрузка заказа...</div></td></tr>`;

    orderState.items = [];
    orderState.settings.legalEntity = legalEntity;
    orderState.settings.supplier = order.supplier || '';
    
    // ВАЖНО: устанавливаем today из заказа или текущую дату
    if (order.today_date) {
        orderState.settings.today = new Date(order.today_date);
    } else {
        orderState.settings.today = new Date(); // если нет в истории - сегодня
    }
    
    orderState.settings.deliveryDate = new Date(order.delivery_date);
    orderState.settings.safetyDays = order.safety_days || 0;
    orderState.settings.periodDays = order.period_days || 30;
    orderState.settings.unit = order.unit || 'pieces';
    orderState.settings.hasTransit = order.has_transit || false;

    // ОТЛАДКА: проверим, что установилось в settings
    console.log('✅ settings после установки:', {
        today: orderState.settings.today,
        today_str: orderState.settings.today ? orderState.settings.today.toISOString() : null,
        deliveryDate: orderState.settings.deliveryDate,
        periodDays: orderState.settings.periodDays,
        unit: orderState.settings.unit
    });

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
    const mult = (productData && productData.multiplicity) || 1;

    addItem(productData || {
        sku: histItem.sku,
        name: histItem.name,
        qty_per_box: qtyPerBox,
        boxes_per_pallet: null,
        multiplicity: mult
    }, true);

    const addedItem = orderState.items[orderState.items.length - 1];
    
    // ПРАВИЛЬНЫЙ ПАРСИНГ ЧИСЕЛ (убираем запятые)
    addedItem.consumptionPeriod = parseFloat(String(histItem.consumption_period || '0').replace(',', '.')) || 0;
    addedItem.stock = parseFloat(String(histItem.stock || '0').replace(',', '.')) || 0;
    addedItem.transit = parseFloat(String(histItem.transit || '0').replace(',', '.')) || 0;

    // Округляем до целых чисел, так как расход/остаток должны быть целыми
    addedItem.consumptionPeriod = Math.round(addedItem.consumptionPeriod);
    addedItem.stock = Math.round(addedItem.stock);
    addedItem.transit = Math.round(addedItem.transit);

    // qty_boxes в БД = физические коробки
    // Конвертируем обратно в текущие единицы
    const physBoxes = parseFloat(String(histItem.qty_boxes || '0').replace(',', '.')) || 0;
    const itemMult = getMultiplicity(addedItem);
    const itemQpb = getQpb(addedItem);
    if (orderState.settings.unit === 'boxes') {
        // Учётные коробки = физ. коробки × multiplicity
        addedItem.finalOrder = Math.round(physBoxes * itemMult);
    } else {
        // Штуки = физ. коробки × qpb × multiplicity
        addedItem.finalOrder = Math.round(physBoxes * itemQpb * itemMult);
    }
}

    // ОТЛАДКА: проверим первый товар после загрузки
    if (orderState.items.length > 0) {
        console.log('✅ Первый товар после загрузки:', orderState.items[0]);
        console.log('✅ dailyConsumption для первого товара:', 
            orderState.items[0].consumptionPeriod / (orderState.settings.periodDays || 30));
    }

    // Режим редактирования
    editingOrderId = isEditing ? order.id : null;
    
    // Режим просмотра из календаря
    viewOnlyMode = isViewOnly;
    updateEditingIndicator();

    orderSection.classList.remove('hidden');
    render();
    updateFinalSummary();
    saveDraft();

    // ========== УСИЛЕННОЕ ПРИНУДИТЕЛЬНОЕ ОБНОВЛЕНИЕ ==========
    console.log('🔄 Запуск принудительного обновления всех расчётов');
    
    // 1. Создаём глубокую копию массива с приведением чисел
    orderState.items = orderState.items.map(item => ({
        ...item,
        consumptionPeriod: Number(item.consumptionPeriod) || 0,
        stock: Number(item.stock) || 0,
        transit: Number(item.transit) || 0,
        finalOrder: Number(item.finalOrder) || 0
    }));

    // 2. Задержка для гарантии обновления DOM
    setTimeout(() => {
        const rows = document.querySelectorAll('#items tr');
        console.log(`🔄 Обновление ${rows.length} строк таблицы`);
        
        rows.forEach((row, index) => {
            if (orderState.items[index]) {
                // Получаем актуальный расчёт для товара
                const calc = calculateItem(orderState.items[index], orderState.settings);
                
                // 3. Прямое обновление ячейки "Хватит до"
                const dateCell = row.querySelector('.date');
                if (dateCell) {
                    if (calc.coverageDate && orderState.settings.deliveryDate) {
                        try {
                            const daysDiff = Math.ceil((calc.coverageDate - orderState.settings.deliveryDate) / 86400000);
                            const day = String(calc.coverageDate.getDate()).padStart(2, '0');
                            const month = String(calc.coverageDate.getMonth() + 1).padStart(2, '0');
                            const year = String(calc.coverageDate.getFullYear()).slice(-2);
                            dateCell.textContent = `${day}.${month}.${year} (${daysDiff} дн.)`;
                            console.log(`📅 Дата для ${orderState.items[index].sku || 'товара'}: ${dateCell.textContent}`);
                        } catch (e) {
                            console.error('Ошибка форматирования даты:', e);
                            dateCell.textContent = '-';
                        }
                    } else {
                        dateCell.textContent = '-';
                    }
                }
                
                // 4. Полное обновление строки
                updateRow(row, orderState.items[index], orderState.settings);
            }
        });
        
        // 5. Обновляем итоги
        updateFinalSummary();
        console.log('✅ Принудительное обновление завершено');
        
    }, 50);

    const mode = isEditing ? 'Редактирование' : 'Загружен';
    showToast(`Заказ: ${mode}`, `${order.supplier} — ${order.order_items?.length || 0} позиций`, 'success');
}

  document.addEventListener('calendar:load-order', async (e) => {
    const { order, legalEntity } = e.detail;
    if (!order) return;
    const confirmed = await customConfirm('Загрузить заказ?', `${order.supplier} от ${new Date(order.delivery_date).toLocaleDateString('ru-RU')} — заменить текущий заказ?`);
    if (!confirmed) return;
    await loadOrderIntoForm(order, legalEntity, false, true);
  });

  // Редактирование из истории
  document.addEventListener('history:edit-order', async (e) => {
    const { order, legalEntity } = e.detail;
    if (!order) return;
    await loadOrderIntoForm(order, legalEntity, true);
  });

  // Просмотр из истории (только чтение)
  document.addEventListener('history:view-order', async (e) => {
    const { order, legalEntity } = e.detail;
    if (!order) return;
    await loadOrderIntoForm(order, legalEntity, false, true);
  });
}