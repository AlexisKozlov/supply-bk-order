import { orderState } from './state.js';
import { calculateItem } from './calculations.js';
import { supabase } from './supabase.js';
import { setupCalculator } from './calculator.js';
import { history } from './history.js';
import { SafetyStockManager } from './safety-stock.js';

import { showToast, customConfirm } from './modals.js';
import { loadDatabaseProducts, setupDatabaseSearch, openEditCardBySku } from './database.js';
import { renderTable, updateRow } from './table-renderer.js';
import { exportToExcel, canExportExcel } from './excel-export.js';
import { getOrdersAnalytics, renderAnalytics } from './analytics.js';

/* ================= DOM ================= */
const copyOrderBtn = document.getElementById('copyOrder');
const clearOrderBtn = document.getElementById('clearOrder');
const undoBtn = document.getElementById('undoBtn');
const redoBtn = document.getElementById('redoBtn');
const allToOrderBtn = document.getElementById('allToOrderBtn');
const tbody = document.getElementById('items');
const supplierSelect = document.getElementById('supplierFilter');
const finalSummary = document.getElementById('finalSummary');

const addManualBtn = document.getElementById('addManual');
const manualAddBtn = document.getElementById('m_add');
const manualCancelBtn = document.getElementById('m_cancel');
const searchInput = document.getElementById('productSearch');
const searchResults = document.getElementById('searchResults');
const clearSearchBtn = document.getElementById('clearSearch');

/* ================= НОВЫЕ DOM-ПЕРЕМЕННЫЕ ================= */
const menuDatabaseBtn = document.getElementById('menuDatabase');
const databaseModal = document.getElementById('databaseModal');
const closeDatabaseBtn = document.getElementById('closeDatabase');
const dbLegalEntitySelect = document.getElementById('dbLegalEntity');
const dbSearchInput = document.getElementById('dbSearch');
const clearDbSearchBtn = document.getElementById('clearDbSearch');
const databaseList = document.getElementById('databaseList');

const editCardModal = document.getElementById('editCardModal');
const closeEditCardBtn = document.getElementById('closeEditCard');
const confirmModal = document.getElementById('confirmModal');
let currentEditingProduct = null; // ID товара который редактируем
const buildOrderBtn = document.getElementById('buildOrder');
const orderSection = document.getElementById('orderSection');
const loginOverlay = document.getElementById('loginOverlay');
const loginBtn = document.getElementById('loginBtn');
const loginPassword = document.getElementById('loginPassword');

/* ================= DOM ДЛЯ НОВЫХ ФУНКЦИЙ v1.6.0 ================= */
const exportExcelBtn = document.getElementById('exportExcelBtn');
const menuAnalyticsBtn = document.getElementById('menuAnalytics');
const analyticsModal = document.getElementById('analyticsModal');
const closeAnalyticsBtn = document.getElementById('closeAnalytics');
const analyticsPeriodSelect = document.getElementById('analyticsPeriod');
const refreshAnalyticsBtn = document.getElementById('refreshAnalytics');
const analyticsContainer = document.getElementById('analyticsContainer');

/* ================= BADGE ЮР. ЛИЦА ================= */
function updateEntityBadge() {
  const badge = document.getElementById('entityBadge');
  if (badge) badge.textContent = orderState.settings.legalEntity || 'Бургер БК';
}
const saveOrderBtn = document.getElementById('saveOrder');
const historyContainer = document.getElementById('orderHistory');
const historySupplier = document.getElementById('historySupplier');
const historyModal = document.getElementById('historyModal');

const manualModal = document.getElementById('manualModal');
const closeManualBtn = document.getElementById('closeManual');

let isLoadingDraft = false;

const nf = new Intl.NumberFormat('ru-RU', {
  maximumFractionDigits: 0
});


/* showToast и customConfirm импортированы из modals.js */


loginBtn.addEventListener('click', () => {
  if (loginPassword.value === '157') {
    loginOverlay.style.display = 'none';
    localStorage.setItem('bk_logged_in', 'true');
    loadOrderHistory();
  } else {
    showToast('Ошибка входа', 'Неверный пароль', 'error');
  }
});


buildOrderBtn.addEventListener('click', () => {
  const ok = validateRequiredSettings();

  if (!ok) {
    showToast('Заполните обязательные поля', 'Укажите даты и запас безопасности', 'error');
    return;
  }

  orderSection.classList.remove('hidden');
});

saveOrderBtn.addEventListener('click', async () => {
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

  const itemsToSave = orderState.items
    .map(item => {
      const boxes =
        orderState.settings.unit === 'boxes'
          ? item.finalOrder
          : item.finalOrder / item.qtyPerBox;

      return {
        sku: item.sku || null,
        name: item.name,
        qty_boxes: Math.ceil(boxes),
        qty_per_box: item.qtyPerBox || 1,
        consumption_period: item.consumptionPeriod || 0,
        stock: item.stock || 0,
        transit: item.transit || 0
      };
    })
    .filter(i => i.qty_boxes > 0);

  if (!itemsToSave.length) {
    showToast('Нет позиций с количеством', 'Укажите количество для заказа', 'error');
    return;
  }

  const { data: order, error } = await supabase
    .from('orders')
    .insert({
      supplier: orderState.settings.supplier || 'Свободный',
      delivery_date: orderState.settings.deliveryDate,
      today_date: orderState.settings.today, // Сохраняем дату "сегодня"
      safety_days: orderState.settings.safetyDays,
      period_days: orderState.settings.periodDays,
      unit: orderState.settings.unit,
      legal_entity: orderState.settings.legalEntity,
      note: note || null,
      created_at: new Date().toISOString(),
      has_transit: orderState.settings.hasTransit || false,
      show_stock_column: orderState.settings.showStockColumn || false
    })
    .select()
    .single();

  if (error) {
    showToast('Ошибка сохранения', 'Не удалось сохранить заказ', 'error');
    console.error(error);
    return;
  }

  const items = itemsToSave.map(i => ({
    order_id: order.id,
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

  showToast('Заказ сохранён', `Сохранено позиций: ${itemsToSave.length}`, 'success');
  clearDraft(); // Очистка черновика после сохранения
  loadOrderHistory();
});

async function loadOrderHistory() {
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
    .order('delivery_date', { ascending: false }); // Сортировка по дате поставки (новые первые)

  if (historySupplier.value) {
    query = query.eq('supplier', historySupplier.value);
  }

  // Фильтр по юр. лицу
  const currentLegalEntity = orderState.settings.legalEntity || document.getElementById('legalEntity').value;
  query = query.eq('legal_entity', currentLegalEntity);

  const { data, error } = await query;

  if (error) {
    historyContainer.innerHTML = 'Ошибка загрузки истории';
    console.error(error);
    return;
  }

  renderOrderHistory(data);
}

/* ================= АВТОСОХРАНЕНИЕ ЧЕРНОВИКА ================= */
function saveDraft() {
  const draft = {
    settings: orderState.settings,
    items: orderState.items,
    timestamp: new Date().toISOString()
  };
  localStorage.setItem('bk_draft', JSON.stringify(draft));
}

async function loadDraft() {
  const draft = localStorage.getItem('bk_draft');
  if (!draft) return false;

  try {
    const data = JSON.parse(draft);
    
    // Устанавливаем флаг чтобы не срабатывало событие change поставщика
    isLoadingDraft = true;
    
    // Восстановление настроек
    if (data.settings.today) {
      orderState.settings.today = new Date(data.settings.today);
      document.getElementById('today').value = orderState.settings.today.toISOString().slice(0, 10);
    }
    if (data.settings.deliveryDate) {
      orderState.settings.deliveryDate = new Date(data.settings.deliveryDate);
      document.getElementById('deliveryDate').value = orderState.settings.deliveryDate.toISOString().slice(0, 10);
    }
    orderState.settings.legalEntity = data.settings.legalEntity || 'Бургер БК';
    orderState.settings.supplier = data.settings.supplier || '';
    orderState.settings.periodDays = data.settings.periodDays || 30;
    orderState.settings.safetyDays = data.settings.safetyDays || 0;
    orderState.settings.unit = data.settings.unit || 'pieces';
    orderState.settings.hasTransit = data.settings.hasTransit || false;
    orderState.settings.showStockColumn = data.settings.showStockColumn || false;
    
    document.getElementById('legalEntity').value = orderState.settings.legalEntity;
    document.getElementById('supplierFilter').value = orderState.settings.supplier;
    document.getElementById('periodDays').value = orderState.settings.periodDays;
    
    // Устанавливаем товарный запас
    if (safetyStockManager) {
      safetyStockManager.setDays(orderState.settings.safetyDays);
    }
    
    document.getElementById('unit').value = orderState.settings.unit;
    document.getElementById('hasTransit').value = orderState.settings.hasTransit ? 'true' : 'false';
    document.getElementById('showStockColumn').value = orderState.settings.showStockColumn ? 'true' : 'false';
    
    // Восстановление товаров
    orderState.items = data.items || [];
    
    // Сбрасываем флаг
    isLoadingDraft = false;
    updateEntityBadge();
    
    if (orderState.items.length > 0) {
      orderSection.classList.remove('hidden');
      
      // Восстанавливаем порядок из Supabase
      await restoreItemOrder();
      
      render();
      
      const draftDate = new Date(data.timestamp).toLocaleString('ru-RU');
      showToast('Черновик загружен', `Восстановлено из ${draftDate}`, 'info');
      return true;
    }
    
  } catch (e) {
    isLoadingDraft = false;
    console.error('Ошибка загрузки черновика:', e);
  }
  
  return false;
}

function clearDraft() {
  localStorage.removeItem('bk_draft');
}


async function renderOrderHistory(orders) {
  historyContainer.innerHTML = '';

  if (!orders.length) {
    historyContainer.innerHTML = 'История пуста';
    return;
  }

  // Получаем все SKU для подтягивания данных из products
  const allSkus = [...new Set(
    orders.flatMap(o => o.order_items.map(i => i.sku)).filter(Boolean)
  )];

  // Загружаем данные о товарах из products
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
    
    // Форматируем дату и время создания
    const createdAt = order.created_at ? new Date(order.created_at) : null;
    const createdDateStr = createdAt 
      ? createdAt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' })
      : '';
    const createdTimeStr = createdAt 
      ? createdAt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
      : '';
    const createdStr = createdAt ? `${createdDateStr} ${createdTimeStr}` : '';
    
    // Примечание
    const noteStr = order.note ? ` (${order.note})` : '';

    div.innerHTML = `
      <div class="history-header">
        <span><b>${date}</b> — ${order.supplier} (${legalEntity})${noteStr}</span>
        <div class="history-actions">
          ${createdStr ? `<span style="font-size:11px;color:#8B7355;margin-right:8px;">📅 ${createdStr}</span>` : ''}
          <button class="btn small copy-order-btn" style="background:var(--orange);color:var(--brown);" title="Скопировать заказ">📋</button>
          <button class="btn small delete-order-btn" style="background:#d32f2f;color:white;" title="Удалить заказ">🗑️</button>
        </div>
      </div>
      <div class="history-items hidden">
        ${order.order_items.map(i => {
          // Используем данные из order_items, если есть, иначе из products
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

    // Копирование заказа из истории
    copyBtn.onclick = async (e) => {
      e.stopPropagation();
      const confirmed = await customConfirm('Скопировать заказ?', 'Текущий заказ будет заменен данными из истории');
      if (!confirmed) return;

      // Очищаем текущий заказ
      orderState.items = [];

      // Восстанавливаем параметры заказа
      orderState.settings.legalEntity = legalEntity;
      orderState.settings.today = order.today_date ? new Date(order.today_date) : new Date();
      orderState.settings.deliveryDate = new Date(order.delivery_date);
      orderState.settings.safetyDays = order.safety_days || 0;
      orderState.settings.periodDays = order.period_days || 30;
      orderState.settings.unit = order.unit || 'pieces';
      orderState.settings.hasTransit = order.has_transit || false;
      orderState.settings.showStockColumn = order.show_stock_column || false;

      document.getElementById('legalEntity').value = legalEntity;
      document.getElementById('today').value = orderState.settings.today.toISOString().slice(0, 10);
      document.getElementById('deliveryDate').value = orderState.settings.deliveryDate.toISOString().slice(0, 10);
      
      // Устанавливаем товарный запас
      if (safetyStockManager) {
        safetyStockManager.setDays(orderState.settings.safetyDays);
      }
      
      document.getElementById('periodDays').value = orderState.settings.periodDays;
      document.getElementById('unit').value = orderState.settings.unit;
      document.getElementById('hasTransit').value = orderState.settings.hasTransit ? 'true' : 'false';
      document.getElementById('showStockColumn').value = orderState.settings.showStockColumn ? 'true' : 'false';

      // Загружаем товары из истории
      for (const histItem of order.order_items) {
        // Пытаемся найти товар в базе products
        const { data: productData } = await supabase
          .from('products')
          .select('*')
          .eq('sku', histItem.sku)
          .single();

        if (productData) {
          addItem(productData);
          const addedItem = orderState.items[orderState.items.length - 1];
          
          // Восстанавливаем все данные из истории
          addedItem.consumptionPeriod = histItem.consumption_period || 0;
          addedItem.stock = histItem.stock || 0;
          addedItem.transit = histItem.transit || 0;
          
          // Устанавливаем finalOrder из истории
          if (orderState.settings.unit === 'boxes') {
            addedItem.finalOrder = histItem.qty_boxes;
          } else {
            const qtyPerBox = histItem.qty_per_box || productData.qty_per_box || 1;
            addedItem.finalOrder = histItem.qty_boxes * qtyPerBox;
          }
        } else {
          // Если товар не найден в products, создаем вручную
          addItem({
            sku: histItem.sku,
            name: histItem.name,
            qty_per_box: histItem.qty_per_box || 1,
            boxes_per_pallet: null
          });
          const addedItem = orderState.items[orderState.items.length - 1];
          
          // Восстанавливаем все данные из истории
          addedItem.consumptionPeriod = histItem.consumption_period || 0;
          addedItem.stock = histItem.stock || 0;
          addedItem.transit = histItem.transit || 0;
          
          if (orderState.settings.unit === 'boxes') {
            addedItem.finalOrder = histItem.qty_boxes;
          } else {
            addedItem.finalOrder = histItem.qty_boxes * (histItem.qty_per_box || 1);
          }
        }
      }

      orderSection.classList.remove('hidden');
      render();
      saveDraft();
      historyModal.classList.add('hidden');
      showToast('Заказ скопирован', `Загружено ${order.order_items.length} товаров`, 'success');
    };

    deleteBtn.onclick = async (e) => {
      e.stopPropagation();
      const confirmed = await customConfirm('Удалить заказ?', 'Заказ будет удален из истории безвозвратно');
      if (!confirmed) return;

      // Сначала удаляем позиции заказа
      const { error: itemsErr } = await supabase
        .from('order_items')
        .delete()
        .eq('order_id', order.id);

      if (itemsErr) {
        showToast('Ошибка удаления', 'Не удалось удалить позиции заказа', 'error');
        console.error(itemsErr);
        return;
      }

      const { error } = await supabase
        .from('orders')
        .delete()
        .eq('id', order.id);

      if (error) {
        showToast('Ошибка удаления', 'Не удалось удалить заказ', 'error');
        console.error(error);
        return;
      }

      showToast('Заказ удален', '', 'success');
      loadOrderHistory();
    };

    historyContainer.appendChild(div);
  });
}


/* ================= ДАТА СЕГОДНЯ ================= */
const today = new Date();
document.getElementById('today').value = today.toISOString().slice(0, 10);
orderState.settings.today = today;

/* ================= НАСТРОЙКИ ================= */
function bindSetting(id, key, isDate = false) {
  const el = document.getElementById(id);
  if (!el) return;

  el.addEventListener('input', e => {
    const newValue = isDate ? new Date(e.target.value) : +e.target.value || 0;
    
    // Валидация дат
    if (isDate && key === 'deliveryDate') {
      const today = orderState.settings.today || new Date();
      if (newValue < today) {
        showToast('Некорректная дата', 'Дата прихода не может быть раньше сегодняшней', 'error');
        e.target.value = orderState.settings.deliveryDate?.toISOString().slice(0, 10) || '';
        return;
      }
    }
    
    orderState.settings[key] = newValue;
    rerenderAll();
    validateRequiredSettings();
    saveDraft(); // Автосохранение
  });
}

bindSetting('today', 'today', true);
bindSetting('deliveryDate', 'deliveryDate', true);
bindSetting('periodDays', 'periodDays');

// Товарный запас - с календарём и двусторонней связью
const safetyDaysInput = document.getElementById('safetyDays');
const safetyCalendarBtn = document.getElementById('safetyCalendarBtn');

let safetyStockManager = null;

if (safetyDaysInput && safetyCalendarBtn) {
  safetyStockManager = new SafetyStockManager(
    safetyDaysInput,
    safetyCalendarBtn,
    (data) => {
      // Callback при изменении
      orderState.settings.safetyDays = data.days;
      orderState.settings.safetyEndDate = data.endDate;
      rerenderAll();
      validateRequiredSettings();
      saveDraft();
    }
  );
  
  // Обновляем товарный запас при изменении ДАТЫ ПРИХОДА
  // Обновляем товарный запас при изменении ДАТЫ ПРИХОДА
  document.getElementById('deliveryDate').addEventListener('change', () => {
    if (orderState.settings.deliveryDate && safetyStockManager) {
      // ВАЖНО: Сбрасываем товарный запас при изменении даты прихода
      // Пользователь должен заново выставить дни ПОСЛЕ новой даты прихода
      orderState.settings.safetyDays = 0;
      safetyStockManager.setDays(0);
      safetyStockManager.setDeliveryDate(orderState.settings.deliveryDate);
      saveDraft();
    }
  });
  
  // Инициализация начального значения
  if (orderState.settings.safetyDays) {
    safetyStockManager.setDays(orderState.settings.safetyDays);
  }
  if (orderState.settings.deliveryDate) {
    safetyStockManager.setDeliveryDate(orderState.settings.deliveryDate);
  }
}


document.getElementById('legalEntity').addEventListener('change', async e => {
  // Игнорируем при загрузке черновика
  if (isLoadingDraft) return;
  
  orderState.settings.legalEntity = e.target.value;
  updateEntityBadge();
  
  // Обнуляем заказ при смене юр. лица
  orderState.items = [];
  orderState.settings.supplier = '';
  
  // Перезагружаем поставщиков для нового юр. лица
  await loadSuppliers(e.target.value);
  
  render();
  saveDraft();
  loadOrderHistory(); // Обновляем историю при смене юр. лица
});

document.getElementById('unit').addEventListener('change', e => {
  orderState.settings.unit = e.target.value;
  rerenderAll();
  saveDraft();
});

// Переключение видимости колонки транзит
document.getElementById('hasTransit').addEventListener('change', e => {
  orderState.settings.hasTransit = e.target.value === 'true';
  toggleTransitColumn();
  toggleStockColumn();
  saveDraft();
});

document.getElementById('showStockColumn').addEventListener('change', e => {
  orderState.settings.showStockColumn = e.target.value === 'true';
  toggleStockColumn();
  saveDraft();
  render(); // перерисовываем таблицу
});

function toggleTransitColumn() {
  const hasTransit = orderState.settings.hasTransit;
  const transitCols = document.querySelectorAll('.transit-col');
  
  transitCols.forEach(col => {
    if (hasTransit) {
      col.classList.remove('hidden');
    } else {
      col.classList.add('hidden');
    }
  });
}

function toggleStockColumn() {
  const showStock = orderState.settings.showStockColumn;
  const stockCols = document.querySelectorAll('.stock-col');
  
  stockCols.forEach(col => {
    if (showStock) {
      col.classList.remove('hidden');
    } else {
      col.classList.add('hidden');
    }
  });
}

function validateRequiredSettings() {
  const todayEl = document.getElementById('today');
  const deliveryEl = document.getElementById('deliveryDate');
  const safetyEl = document.getElementById('safetyDays');

  let valid = true;

  if (!todayEl.value) {
    todayEl.classList.add('required');
    valid = false;
  } else todayEl.classList.remove('required');

  if (!deliveryEl.value) {
    deliveryEl.classList.add('required');
    valid = false;
  } else deliveryEl.classList.remove('required');

  if (safetyEl.value === '' || safetyEl.value === null) {
    safetyEl.classList.add('required');
    valid = false;
  } else safetyEl.classList.remove('required');

  return valid;
}


/* ================= ПОСТАВЩИКИ ================= */
async function loadSuppliers(legalEntity) {
  // Очищаем текущие опции (кроме первой "Все / свободный")
  supplierSelect.innerHTML = '<option value="">Все / свободный</option>';
  historySupplier.innerHTML = '<option value="">Все</option>';
  
  // Загружаем поставщиков для текущего юр. лица
  // Бургер БК и Воглия Матта - общие поставщики
  let query = supabase.from('products').select('supplier, legal_entity');
  
  if (legalEntity === 'Пицца Стар') {
    query = query.eq('legal_entity', 'Пицца Стар');
  } else {
    // Для Бургер БК и Воглия Матта - показываем оба
    query = query.in('legal_entity', ['Бургер БК', 'Воглия Матта']);
  }
  
  const { data } = await query;
  const suppliers = [...new Set(data.map(p => p.supplier).filter(Boolean))];
  
  // СОРТИРОВКА ПО АЛФАВИТУ
  suppliers.sort((a, b) => a.localeCompare(b, 'ru'));

  suppliers.forEach(s => {
    // основной фильтр
    const opt1 = document.createElement('option');
    opt1.value = s;
    opt1.textContent = s;
    supplierSelect.appendChild(opt1);

    // фильтр истории
    const opt2 = document.createElement('option');
    opt2.value = s;
    opt2.textContent = s;
    historySupplier.appendChild(opt2);
  });
}

// Инициализация при загрузке
const initSuppliers = loadSuppliers(orderState.settings.legalEntity);

historySupplier.addEventListener('change', loadOrderHistory);

supplierSelect.addEventListener('change', async () => {
  // Игнорируем событие при загрузке черновика
  if (isLoadingDraft) return;
  
  orderState.settings.supplier = supplierSelect.value;
  orderState.items = [];
  render();
  saveDraft();

  if (!supplierSelect.value) return;

  const { data } = await supabase
    .from('products')
    .select('*')
    .eq('supplier', supplierSelect.value);

  data.forEach(addItem);
  
  // Восстанавливаем порядок из Supabase
  await restoreItemOrder();
  
  // Перерисовываем с учётом порядка
  render();
  saveDraft();
});

/* ================= ПОИСК ПО КАРТОЧКАМ ================= */
let searchTimer = null;

if (searchInput) {
  searchInput.addEventListener('input', () => {
    const q = searchInput.value.trim();
    clearTimeout(searchTimer);

    // Показываем/скрываем крестик
    if (clearSearchBtn) {
      if (q.length > 0) {
        clearSearchBtn.classList.remove('hidden');
      } else {
        clearSearchBtn.classList.add('hidden');
      }
    }

    if (q.length < 2) {
      searchResults.innerHTML = '';
      return;
    }

    searchTimer = setTimeout(() => searchProducts(q), 300);
  });

  // Обработчик крестика очистки
  if (clearSearchBtn) {
    clearSearchBtn.addEventListener('click', () => {
      searchInput.value = '';
      searchResults.innerHTML = '';
      clearSearchBtn.classList.add('hidden');
      searchInput.focus();
    });
  }
}

async function searchProducts(q) {
  const isSku = /^[0-9A-Za-z-]+$/.test(q);

  let query = supabase
    .from('products')
    .select('*')
    .limit(10);

  // Фильтр по юр. лицу
  const currentLegalEntity = orderState.settings.legalEntity;
  if (currentLegalEntity === 'Пицца Стар') {
    query = query.eq('legal_entity', 'Пицца Стар');
  } else {
    // Для Бургер БК и Воглия Матта - показываем оба
    query = query.in('legal_entity', ['Бургер БК', 'Воглия Матта']);
  }

  // если выбран поставщик — ищем только по нему
  if (supplierSelect.value) {
    query = query.eq('supplier', supplierSelect.value);
  }

  query = isSku
    ? query.ilike('sku', `%${q}%`)
    : query.ilike('name', `%${q}%`);

  const { data, error } = await query;

  if (error) {
    console.error('Ошибка поиска:', error);
    return;
  }

  searchResults.innerHTML = '';

  if (!data.length) {
    searchResults.innerHTML =
      '<div style="color:#999">Ничего не найдено</div>';
    return;
  }

  data.forEach(p => {
    const div = document.createElement('div');
    div.textContent = `${p.sku} ${p.name}`;
    div.addEventListener('click', () => {
      addItem(p);
      searchResults.innerHTML = '';
      searchInput.value = '';
    });
    searchResults.appendChild(div);
  });
}

/* ================= РУЧНОЙ ТОВАР ================= */
manualAddBtn.addEventListener('click', async () => {
  const name = document.getElementById('m_name').value.trim();
  const sku = document.getElementById('m_sku').value.trim();
  const supplier = document.getElementById('m_supplier').value.trim();
  const qtyPerBox = document.getElementById('m_box').value.trim();
  const boxesPerPallet = document.getElementById('m_pallet').value.trim();

  // Проверка всех обязательных полей
  if (!name) {
    showToast('Введите наименование', 'Поле обязательно для заполнения', 'error');
    return;
  }
  
  if (!sku) {
    showToast('Введите артикул', 'Поле обязательно для заполнения', 'error');
    return;
  }
  
  if (!supplier) {
    showToast('Введите поставщика', 'Поле обязательно для заполнения', 'error');
    return;
  }
  
  if (!qtyPerBox || +qtyPerBox <= 0) {
    showToast('Введите штук в коробке', 'Поле обязательно и должно быть больше 0', 'error');
    return;
  }
  
  if (!boxesPerPallet || +boxesPerPallet <= 0) {
    showToast('Введите коробов на паллете', 'Поле обязательно и должно быть больше 0', 'error');
    return;
  }

  const product = {
    name,
    sku: sku || null,
    supplier: supplier || null,
    legal_entity: document.getElementById('m_legalEntity').value,
    qty_per_box: +qtyPerBox,
    boxes_per_pallet: +boxesPerPallet,
    unit_of_measure: document.getElementById('m_unit').value || 'шт'
  };

  if (document.getElementById('m_save').checked) {
    const { data, error } = await supabase
      .from('products')
      .insert(product)
      .select()
      .single();

    if (error) {
      showToast('Ошибка сохранения', 'Не удалось сохранить товар в базу', 'error');
      console.error(error);
      return;
    }

    addItem(data);
    showToast('Товар добавлен', 'Товар сохранён в базе данных', 'success');
  } else {
    addItem(product);
    showToast('Товар добавлен', 'Товар добавлен в текущий заказ', 'success');
  }

  manualModal.classList.add('hidden');
});

addManualBtn.addEventListener('click', () => {
  // Устанавливаем текущее юр. лицо по умолчанию
  document.getElementById('m_legalEntity').value = orderState.settings.legalEntity;
  manualModal.classList.remove('hidden');
});

closeManualBtn.addEventListener('click', () => {
  manualModal.classList.add('hidden');
});

manualCancelBtn.addEventListener('click', () => {
  manualModal.classList.add('hidden');
});


/* ================= ДОБАВЛЕНИЕ ================= */
function addItem(p) {
  orderState.items.push({
    id: crypto.randomUUID(),
    supabaseId: p.id, // НАСТОЯЩИЙ ID из Supabase для редактирования
    sku: p.sku || '',
    name: p.name,
    consumptionPeriod: 0,
    stock: 0,
    transit: 0,
    qtyPerBox: p.qty_per_box || 1,
    boxesPerPallet: p.boxes_per_pallet || null,
    unitOfMeasure: p.unit_of_measure || 'шт',
    finalOrder: 0
  });
  render();
  saveDraft();
  saveStateToHistory(); // Сохраняем ПОСЛЕ изменения
}

/* ================= УДАЛЕНИЕ ТОВАРА ================= */
async function removeItem(itemId) {
  const confirmed = await customConfirm('Удалить товар?', 'Товар будет удален из текущего заказа');
  if (confirmed) {
    orderState.items = orderState.items.filter(item => item.id !== itemId);
    render();
    saveDraft();
    saveStateToHistory(); // Сохраняем ПОСЛЕ изменения
    showToast('Товар удален', '', 'success');
  }
}

/* ================= ИСТОРИЯ ИЗМЕНЕНИЙ (UNDO/REDO) ================= */
function saveStateToHistory() {
  history.push({
    items: orderState.items,
    settings: orderState.settings
  });
  updateHistoryButtons();
}

// Debounced версия для сохранения при вводе в поля
let saveHistoryTimeout = null;
function saveStateToHistoryDebounced(delay = 1000) {
  clearTimeout(saveHistoryTimeout);
  saveHistoryTimeout = setTimeout(() => {
    saveStateToHistory();
  }, delay);
}

function updateHistoryButtons() {
  if (undoBtn) undoBtn.disabled = !history.canUndo();
  if (redoBtn) redoBtn.disabled = !history.canRedo();
}

// Undo
if (undoBtn) {
  undoBtn.addEventListener('click', () => {
    // Принудительно обновляем состояние кнопок перед действием
    updateHistoryButtons();
    
    const state = history.undo();
    if (state) {
      orderState.items = state.items;
      orderState.settings = state.settings;
      
      // Конвертируем строки обратно в Date объекты
      if (orderState.settings.today && typeof orderState.settings.today === 'string') {
        orderState.settings.today = new Date(orderState.settings.today);
      }
      if (orderState.settings.deliveryDate && typeof orderState.settings.deliveryDate === 'string') {
        orderState.settings.deliveryDate = new Date(orderState.settings.deliveryDate);
      }
      if (orderState.settings.safetyEndDate && typeof orderState.settings.safetyEndDate === 'string') {
        orderState.settings.safetyEndDate = new Date(orderState.settings.safetyEndDate);
      }
      
      // Обновляем интерфейс
      render();
      
      // Обновляем поля параметров
      if (orderState.settings.today) {
        document.getElementById('today').value = orderState.settings.today.toISOString().slice(0, 10);
      }
      if (orderState.settings.deliveryDate) {
        document.getElementById('deliveryDate').value = orderState.settings.deliveryDate.toISOString().slice(0, 10);
      }
      if (safetyStockManager && orderState.settings.deliveryDate) {
        safetyStockManager.setDeliveryDate(orderState.settings.deliveryDate);
        safetyStockManager.setDays(orderState.settings.safetyDays);
      }
      
      saveDraft();
      updateHistoryButtons();
      showToast('Отменено', '', 'info');
    }
  });
}

// Redo
if (redoBtn) {
  redoBtn.addEventListener('click', () => {
    // Принудительно обновляем состояние кнопок перед действием
    updateHistoryButtons();
    
    const state = history.redo();
    if (state) {
      orderState.items = state.items;
      orderState.settings = state.settings;
      
      // Конвертируем строки обратно в Date объекты
      if (orderState.settings.today && typeof orderState.settings.today === 'string') {
        orderState.settings.today = new Date(orderState.settings.today);
      }
      if (orderState.settings.deliveryDate && typeof orderState.settings.deliveryDate === 'string') {
        orderState.settings.deliveryDate = new Date(orderState.settings.deliveryDate);
      }
      if (orderState.settings.safetyEndDate && typeof orderState.settings.safetyEndDate === 'string') {
        orderState.settings.safetyEndDate = new Date(orderState.settings.safetyEndDate);
      }
      
      // Обновляем интерфейс
      render();
      
      // Обновляем поля параметров
      if (orderState.settings.today) {
        document.getElementById('today').value = orderState.settings.today.toISOString().slice(0, 10);
      }
      if (orderState.settings.deliveryDate) {
        document.getElementById('deliveryDate').value = orderState.settings.deliveryDate.toISOString().slice(0, 10);
      }
      if (safetyStockManager && orderState.settings.deliveryDate) {
        safetyStockManager.setDeliveryDate(orderState.settings.deliveryDate);
        safetyStockManager.setDays(orderState.settings.safetyDays);
      }
      
      saveDraft();
      updateHistoryButtons();
      showToast('Повторено', '', 'info');
    }
  });
}

// Горячие клавиши Ctrl+Z и Ctrl+Y
document.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
    e.preventDefault();
    if (undoBtn && !undoBtn.disabled) undoBtn.click();
  }
  if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || (e.key === 'z' && e.shiftKey))) {
    e.preventDefault();
    if (redoBtn && !redoBtn.disabled) redoBtn.click();
  }
});

// В заказ всё
if (allToOrderBtn) {
  allToOrderBtn.addEventListener('click', () => {
    if (!orderState.items.length) {
      showToast('Заказ пуст', 'Добавьте товары в заказ', 'error');
      return;
    }
    
    let count = 0;
    orderState.items.forEach(item => {
      const calc = calculateItem(item, orderState.settings);
      if (calc.calculatedOrder > 0) {
        item.finalOrder = Math.round(calc.calculatedOrder);
        count++;
      }
    });
    
    if (count > 0) {
      render();
      saveDraft();
      saveStateToHistory(); // Сохраняем ПОСЛЕ изменения
      showToast('Готово', `Расчёт перенесён в заказ для ${count} товаров`, 'success');
    } else {
      showToast('Нет данных', 'Нет товаров с расчётом для переноса', 'info');
    }
  });
}

/* ================= ПЕРЕСТАНОВКА ТОВАРОВ ================= */
function swapItems(fromIndex, toIndex) {
  const items = orderState.items;
  const [movedItem] = items.splice(fromIndex, 1);
  items.splice(toIndex, 0, movedItem);
  render();
  saveDraft();
}

/* ================= КОПИРОВАНИЕ ЗАКАЗА ================= */
copyOrderBtn.addEventListener('click', () => {
  if (!orderState.items.length) {
    showToast('Заказ пуст', 'Добавьте товары для копирования', 'error');
    return;
  }

  const deliveryDate = orderState.settings.deliveryDate
    ? orderState.settings.deliveryDate.toLocaleDateString()
    : '—';

  const lines = orderState.items
    .map(item => {
      const boxes =
        orderState.settings.unit === 'boxes'
          ? item.finalOrder
          : item.finalOrder / item.qtyPerBox;

      const pieces = 
        orderState.settings.unit === 'pieces'
          ? item.finalOrder
          : item.finalOrder * item.qtyPerBox;

      const roundedBoxes = Math.ceil(boxes);
      const roundedPieces = Math.round(pieces);

      if (roundedBoxes <= 0) return null;

      const name = `${item.sku ? item.sku + ' ' : ''}${item.name}`;
      const unit = item.unitOfMeasure || 'шт';

      return `${name} (${roundedPieces} ${unit}) - ${roundedBoxes} коробок`;
    })
    .filter(Boolean);

  if (!lines.length) {
    showToast('Нет позиций', 'В заказе нет позиций с количеством', 'error');
    return;
  }

  const legalEntity = orderState.settings.legalEntity || 'Бургер БК';
  
  const text =
`Добрый день!

Юр. лицо: ${legalEntity}

Просьба поставить:

${lines.join('\n')}

Дата прихода: ${deliveryDate}

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
clearOrderBtn.addEventListener('click', async () => {
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

  render();
  saveDraft();
  saveStateToHistory(); // Сохраняем ПОСЛЕ изменения
  showToast('Данные очищены', 'Товары сохранены, данные сброшены', 'success');
});


/* ================= EXCEL-НАВИГАЦИЯ ================= */
function setupExcelNavigation(input, rowIndex, columnIndex) {
  input.addEventListener('keydown', (e) => {
    // Enter или стрелка вниз
    if (e.key === 'Enter' || e.key === 'ArrowDown') {
      e.preventDefault();
      moveToCell(rowIndex + 1, columnIndex);
    }
    // Стрелка вверх
    else if (e.key === 'ArrowUp') {
      e.preventDefault();
      moveToCell(rowIndex - 1, columnIndex);
    }
    // Стрелка вправо (только если курсор в конце)
    else if (e.key === 'ArrowRight' && input.selectionStart === input.value.length) {
      e.preventDefault();
      moveToCell(rowIndex, columnIndex + 1);
    }
    // Стрелка влево (только если курсор в начале)
    else if (e.key === 'ArrowLeft' && input.selectionStart === 0) {
      e.preventDefault();
      moveToCell(rowIndex, columnIndex - 1);
    }
  });
}

function moveToCell(rowIndex, columnIndex) {
  const rows = tbody.querySelectorAll('tr');
  
  // Проверка границ (теперь 4 колонки: расход, остаток, транзит, заказ-штуки, заказ-коробки)
  if (rowIndex < 0 || rowIndex >= rows.length) return;
  if (columnIndex < 0 || columnIndex > 4) return;
  
  const targetRow = rows[rowIndex];
  const inputs = targetRow.querySelectorAll('input[type="number"]');
  
  if (inputs[columnIndex]) {
    inputs[columnIndex].focus();
    inputs[columnIndex].select();
  }
}


/* ================= ТАБЛИЦА ================= */
function render() {
  renderTable(orderState, tbody, {
    saveDraft,
    saveStateToHistoryDebounced,
    saveStateToHistory,
    updateFinalSummary,
    removeItem,
    setupExcelNavigation,
    roundToPallet,
    saveItemOrder,
    render,
    openProductForEdit: openEditCardBySku
  });
  
  // Применяем видимость колонок после рендера
  toggleTransitColumn();
  toggleStockColumn();
}

/* ================= ОКРУГЛЕНИЕ ================= */
function roundToPallet(item) {
  if (!item.boxesPerPallet) return;

  const boxes =
    orderState.settings.unit === 'boxes'
      ? item.finalOrder
      : item.finalOrder / item.qtyPerBox;

  const pallets = Math.ceil(boxes / item.boxesPerPallet);
  const roundedBoxes = pallets * item.boxesPerPallet;

  item.finalOrder =
    orderState.settings.unit === 'boxes'
      ? roundedBoxes
      : roundedBoxes * item.qtyPerBox;
}

/* ================= ИТОГ В КОРОБКАХ ================= */
function updateFinalSummary() {
  const itemsWithOrder = orderState.items.filter(item => {
    let boxes;
    if (orderState.settings.unit === 'boxes') {
      boxes = item.finalOrder;
    } else {
      boxes = item.qtyPerBox ? Math.ceil(item.finalOrder / item.qtyPerBox) : 0;
    }
    return boxes >= 1;
  });
  
  if (itemsWithOrder.length === 0) {
    finalSummary.innerHTML = '<div style="color:#8a8a8a;text-align:center;">Нет товаров с заказом</div>';
    return;
  }
  
  finalSummary.innerHTML = itemsWithOrder.map(item => {
    let boxes, pieces;
    
    if (orderState.settings.unit === 'boxes') {
      boxes = item.finalOrder;
      pieces = item.finalOrder * (item.qtyPerBox || 1);
    } else {
      boxes = item.qtyPerBox ? Math.ceil(item.finalOrder / item.qtyPerBox) : 0;
      pieces = item.finalOrder;
    }
    
    const unit = item.unitOfMeasure || 'шт';

  return `
  <div>
    <b>${item.sku ? item.sku + ' ' : ''}${item.name}</b>
    — ${nf.format(Math.ceil(boxes))} коробок (${nf.format(Math.round(pieces))} ${unit})
  </div>
`;
  }).join('');
}

/* ================= ПЕРЕРИСОВКА ================= */
function rerenderAll() {
  document
    .querySelectorAll('#items tr')
    .forEach((tr, i) => {
      if (orderState.items[i]) {
        updateRow(tr, orderState.items[i], orderState.settings);
      }
    });
}




function initModals() {
  const openHistoryBtn = document.getElementById('menuHistory');
  const closeHistoryBtn = document.getElementById('closeHistory');
  const historyModal = document.getElementById('historyModal');

  if (!openHistoryBtn || !closeHistoryBtn || !historyModal) {
    console.error('История заказов: элементы не найдены');
    return;
  }

  openHistoryBtn.addEventListener('click', () => {
    historyModal.classList.remove('hidden');
    loadOrderHistory();
  });

  closeHistoryBtn.addEventListener('click', () => {
    historyModal.classList.add('hidden');
  });
}

render();
initModals();

// Загрузка черновика после загрузки поставщиков
initSuppliers.then(async () => {
  await loadDraft();
  updateEntityBadge(); // fallback если черновика нет
  
  // Сохраняем начальное состояние для undo/redo
  saveStateToHistory();
});

// Предупреждение перед закрытием страницы
window.addEventListener('beforeunload', (e) => {
  if (orderState.items.length > 0) {
    e.preventDefault();
    e.returnValue = '';
  }
});


/* ================= БАЗА ДАННЫХ ================= */
menuDatabaseBtn.addEventListener('click', () => {
  databaseModal.classList.remove('hidden');
  dbLegalEntitySelect.value = orderState.settings.legalEntity;
  loadDatabaseProducts(dbLegalEntitySelect, databaseList);
});

closeDatabaseBtn.addEventListener('click', () => {
  databaseModal.classList.add('hidden');
  dbSearchInput.value = '';
  if (clearDbSearchBtn) clearDbSearchBtn.classList.add('hidden');
});

dbLegalEntitySelect.addEventListener('change', () => {
  loadDatabaseProducts(dbLegalEntitySelect, databaseList);
});

setupDatabaseSearch(dbSearchInput, clearDbSearchBtn, databaseList);

/* ================= КЛАВИШИ ENTER/ESC ================= */
document.addEventListener('keydown', (e) => {
  // ESC — закрытие модалок
  if (e.key === 'Escape') {
    if (!manualModal.classList.contains('hidden')) {
      manualModal.classList.add('hidden');
    } else if (!editCardModal.classList.contains('hidden')) {
      editCardModal.classList.add('hidden');
      currentEditingProduct = null;
    } else if (!databaseModal.classList.contains('hidden')) {
      databaseModal.classList.add('hidden');
    } else if (!historyModal.classList.contains('hidden')) {
      historyModal.classList.add('hidden');
    } else if (!confirmModal.classList.contains('hidden')) {
      confirmModal.classList.add('hidden');
    }
  }
  
  // ENTER — сохранение/подтверждение (только если фокус НЕ на input)
  if (e.key === 'Enter' && !e.shiftKey && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'SELECT') {
    if (!manualModal.classList.contains('hidden')) {
      e.preventDefault();
      document.getElementById('m_add').click();
    } else if (!editCardModal.classList.contains('hidden')) {
      e.preventDefault();
      document.getElementById('e_save').click();
    } else if (!confirmModal.classList.contains('hidden')) {
      e.preventDefault();
      document.getElementById('confirmYes').click();
    }
  }
});
/* ================= СОХРАНЕНИЕ/ВОССТАНОВЛЕНИЕ ПОРЯДКА В SUPABASE ================= */
async function saveItemOrder() {
  const supplier = orderState.settings.supplier || 'all';
  const legalEntity = orderState.settings.legalEntity;
  
  console.log('💾 Сохранение порядка:', { supplier, legalEntity, items: orderState.items.length });
  
  // Удаляем старый порядок для этого поставщика/юр.лица
  const { error: deleteError } = await supabase
    .from('item_order')
    .delete()
    .eq('supplier', supplier)
    .eq('legal_entity', legalEntity);
  
  if (deleteError) {
    console.error('❌ Ошибка удаления старого порядка:', deleteError);
  }
  
  // Сохраняем новый порядок
  const orderData = orderState.items.map((item, index) => ({
    supplier,
    legal_entity: legalEntity,
    item_id: item.supabaseId || item.id,
    position: index
  }));
  
  console.log('📊 Данные для сохранения:', orderData);
  
  if (orderData.length > 0) {
    const { error } = await supabase
      .from('item_order')
      .insert(orderData);
    
    if (error) {
      console.error('Ошибка сохранения порядка:', error);
    } else {
      console.log('✅ Порядок сохранён в Supabase для всех пользователей');
    }
  }
}

async function restoreItemOrder() {
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

/* ================= ЭКСПОРТ В EXCEL ================= */
if (exportExcelBtn) {
  exportExcelBtn.addEventListener('click', async () => {
    if (!canExportExcel(orderState)) {
      showToast('Нет данных', 'Добавьте товары в заказ', 'info');
      return;
    }
    
    try {
      showToast('Экспорт...', 'Подготовка файла Excel', 'info');
      const result = await exportToExcel(orderState);
      if (result.success) {
        showToast('Готово!', `Файл ${result.filename} загружен`, 'success');
      }
    } catch (error) {
      console.error('Ошибка экспорта:', error);
      showToast('Ошибка', 'Не удалось экспортировать в Excel', 'error');
    }
  });
}

/* ================= АНАЛИТИКА ================= */
async function loadAnalytics() {
  const period = parseInt(analyticsPeriodSelect?.value || '30');
  const legalEntity = orderState.settings.legalEntity || 'Бургер БК';
  
  if (analyticsContainer) {
    analyticsContainer.innerHTML = `
      <div style="text-align:center;padding:60px;color:#999;">
        <div class="loading-spinner"></div>
        <div style="margin-top:14px;">Загрузка данных...</div>
      </div>`;
  }
  
  try {
    const analytics = await getOrdersAnalytics(legalEntity, period);
    if (analyticsContainer) renderAnalytics(analytics, analyticsContainer);
  } catch (error) {
    console.error('Ошибка загрузки аналитики:', error);
    if (analyticsContainer) {
      analyticsContainer.innerHTML = '<div style="padding:40px;text-align:center;color:#c62828;">Ошибка загрузки данных. Проверьте консоль.</div>';
    }
  }
}

if (menuAnalyticsBtn) {
  menuAnalyticsBtn.addEventListener('click', async () => {
    if (analyticsModal) {
      analyticsModal.classList.remove('hidden');
      await loadAnalytics();
    }
  });
}

if (closeAnalyticsBtn) {
  closeAnalyticsBtn.addEventListener('click', () => {
    if (analyticsModal) analyticsModal.classList.add('hidden');
  });
}

if (refreshAnalyticsBtn) {
  refreshAnalyticsBtn.addEventListener('click', async () => {
    await loadAnalytics();
  });
}

if (analyticsPeriodSelect) {
  analyticsPeriodSelect.addEventListener('change', async () => {
    await loadAnalytics();
  });
}