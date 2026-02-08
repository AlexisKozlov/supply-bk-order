import { orderState } from './state.js';
import { calculateItem } from './calculations.js';
import { supabase } from './supabase.js';
import { setupCalculator } from './calculator.js';
import { history } from './history.js';
import { SafetyStockManager } from './safety-stock.js';

import { showToast, customConfirm } from './modals.js';
import { loadOrderHistory } from './order-history.js';
import { loadDatabaseProducts, setupDatabaseSearch } from './database.js';
import { renderTable } from './table-renderer.js';

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
const menuHistoryBtn = document.getElementById('menuHistory');
const databaseModal = document.getElementById('databaseModal');
const closeDatabaseBtn = document.getElementById('closeDatabase');
const dbLegalEntitySelect = document.getElementById('dbLegalEntity');
const dbSearchInput = document.getElementById('dbSearch');
const clearDbSearchBtn = document.getElementById('clearDbSearch');
const databaseList = document.getElementById('databaseList');

const editCardModal = document.getElementById('editCardModal');
const closeEditCardBtn = document.getElementById('closeEditCard');
let currentEditingProduct = null; // ID товара который редактируем
const buildOrderBtn = document.getElementById('buildOrder');
const orderSection = document.getElementById('orderSection');
const loginOverlay = document.getElementById('loginOverlay');
const loginBtn = document.getElementById('loginBtn');
const loginPassword = document.getElementById('loginPassword');

/* ================= BADGE ЮР. ЛИЦА ================= */
function updateEntityBadge() {
  const badge = document.getElementById('entityBadge');
  if (badge) badge.textContent = orderState.settings.legalEntity || 'Бургер БК';
}
const saveOrderBtn = document.getElementById('saveOrder');
const historyContainer = document.getElementById('orderHistory');
const historySupplier = document.getElementById('historySupplier');
const closeHistoryBtn = document.getElementById('closeHistory');
const historyModal = document.getElementById('historyModal');

const manualModal = document.getElementById('manualModal');
const closeManualBtn = document.getElementById('closeManual');

let isLoadingDraft = false;

const nf = new Intl.NumberFormat('ru-RU', {
  maximumFractionDigits: 0
});



/* ================= ОБРАБОТЧИКИ ИСТОРИИ ================= */
menuHistoryBtn.addEventListener('click', () => {
  historyModal.classList.remove('hidden');
  loadOrderHistory(orderState, historySupplier, historyContainer);
});

closeHistoryBtn.addEventListener('click', () => {
  historyModal.classList.add('hidden');
});

historySupplier.addEventListener('change', () => {
  loadOrderHistory(orderState, historySupplier, historyContainer);
});

/* showToast и customConfirm импортированы из modals.js */


loginBtn.addEventListener('click', () => {
  if (loginPassword.value === '157') {
    loginOverlay.style.display = 'none';
    localStorage.setItem('bk_logged_in', 'true');
    loadOrderHistory(orderState, historySupplier, historyContainer);
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
      safety_days: orderState.settings.safetyDays,
      period_days: orderState.settings.periodDays,
      unit: orderState.settings.unit,
      legal_entity: orderState.settings.legalEntity,
      note: note || null, // Примечание
      created_at: new Date().toISOString() // Дата и время создания
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
  loadOrderHistory(orderState, historySupplier, historyContainer);
});


// loadOrderHistory импортирована из order-history.js

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



// renderOrderHistory импортирована из order-history.js

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