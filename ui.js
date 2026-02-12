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
import { loadOrderHistory as loadHistory } from './order-history.js';
import { initPlanning } from './planning.js';
import { showImportDialog } from './import-stock.js';
import { initDeliveryCalendar } from './delivery-calendar.js';

let editingOrderId = null; // ID заказа при редактировании (null = новый)

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


loginBtn.addEventListener('click', doLogin);
loginPassword.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') doLogin();
});

function doLogin() {
  checkPassword(loginPassword.value).then(valid => {
    if (valid) {
      loginOverlay.style.display = 'none';
      localStorage.setItem('bk_logged_in', 'true');
      loadOrderHistory();
    } else {
      showToast('Ошибка входа', 'Неверный пароль', 'error');
    }
  });
}

async function checkPassword(pwd) {
  try {
    const { data, error } = await supabase
      .from('settings')
      .select('value')
      .eq('key', 'order_calculator_password')
      .single();
    if (data && data.value) return pwd === data.value;
  } catch (e) { /* fallback */ }
  // Fallback если Supabase недоступен
  return pwd === '157';
}


buildOrderBtn.addEventListener('click', () => {
  const ok = validateRequiredSettings();

  if (!ok) {
    showToast('Заполните обязательные поля', 'Укажите даты и запас безопасности', 'error');
    return;
  }

  orderSection.classList.remove('hidden');
  
  // Автофокус на поиск товаров
  setTimeout(() => {
    if (searchInput) searchInput.focus();
  }, 100);
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
    show_stock_column: orderState.settings.showStockColumn || false
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

  const items = itemsToSave.map(i => ({
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
  showToast(actionLabel, `Сохранено позиций: ${itemsToSave.length}`, 'success');
  editingOrderId = null; // Сбрасываем режим редактирования
  updateEditingIndicator();
  clearDraft();
  loadOrderHistory();
});

/* ================= ИСТОРИЯ ЗАКАЗОВ (модуль) ================= */
function getHistoryOpts() {
  return {
    historyContainer,
    historySupplier,
    callbacks: {
      addItem: (p, skipRender) => addItem(p, skipRender),
      render,
      saveDraft,
      safetyStockManager,
      orderSection,
      historyModal,
      loadSuppliers,
      updateFinalSummary
    }
  };
}

function loadOrderHistory() {
  loadHistory(getHistoryOpts());
}

/* ================= АВТОСОХРАНЕНИЕ ЧЕРНОВИКА ================= */
let saveDraftTimer = null;

function saveDraft() {
  // Debounce — не чаще 1 раза в 500мс
  clearTimeout(saveDraftTimer);
  saveDraftTimer = setTimeout(() => {
    const draft = {
      settings: orderState.settings,
      items: orderState.items,
      timestamp: new Date().toISOString()
    };
    localStorage.setItem('bk_draft', JSON.stringify(draft));
  }, 500);
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
    if (data.settings.safetyEndDate) {
      orderState.settings.safetyEndDate = new Date(data.settings.safetyEndDate);
    }
    orderState.settings.legalEntity = data.settings.legalEntity || 'Бургер БК';
    orderState.settings.supplier = data.settings.supplier || '';
    orderState.settings.periodDays = data.settings.periodDays || 30;
    orderState.settings.safetyDays = data.settings.safetyDays || 0;
    orderState.settings.unit = data.settings.unit || 'pieces';
    orderState.settings.hasTransit = data.settings.hasTransit || false;
    orderState.settings.showStockColumn = data.settings.showStockColumn || false;
    
    document.getElementById('legalEntity').value = orderState.settings.legalEntity;
    
    // Загружаем поставщиков для юр.лица, затем устанавливаем значение
    await loadSuppliers(orderState.settings.legalEntity);
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

// Товарный запас — с иконкой календаря внутри инпута
const safetyDaysInput = document.getElementById('safetyDays');
const safetyCalendarBtn = document.getElementById('safetyCalendarBtn');

let safetyStockManager = null;

if (safetyDaysInput) {
  safetyStockManager = new SafetyStockManager(
    safetyDaysInput,
    safetyCalendarBtn, // иконка календаря внутри инпута
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

  // safetyDays: проверяем что введено число (включая 0)
  const safetyValue = safetyEl.value.trim();
  const safetyNum = safetyValue.match(/^(\d+)/);
  if (!safetyNum) {
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
  
  const { data, error } = await query;
  
  if (error || !data) {
    console.error('Ошибка загрузки поставщиков:', error);
    return;
  }
  
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

const historyLegalEntity = document.getElementById('historyLegalEntity');
if (historyLegalEntity) {
  historyLegalEntity.addEventListener('change', loadOrderHistory);
}

const historyType = document.getElementById('historyType');
if (historyType) {
  historyType.addEventListener('change', loadOrderHistory);
}

supplierSelect.addEventListener('change', async () => {
  // Игнорируем событие при загрузке черновика
  if (isLoadingDraft) return;
  
  // Проверяем есть ли заполненные данные (расход/остаток/заказ)
  const hasFilledData = orderState.items.some(item => 
    item.consumptionPeriod > 0 || item.stock > 0 || item.transit > 0 || item.finalOrder > 0
  );
  
  if (hasFilledData) {
    const confirmed = await customConfirm(
      'Сменить поставщика?', 
      'Текущий заказ с заполненными данными будет сброшен'
    );
    if (!confirmed) {
      // Возвращаем прежнее значение
      supplierSelect.value = orderState.settings.supplier;
      return;
    }
  }
  
  orderState.settings.supplier = supplierSelect.value;
  consumptionCache = null; // сбрасываем кеш проверки данных
  orderState.items = [];
  render();
  saveDraft();

  if (!supplierSelect.value) return;

  // Блокируем select и показываем загрузку
  supplierSelect.disabled = true;
  tbody.innerHTML = '<tr><td colspan="11" style="text-align:center;padding:20px;"><div class="loading-spinner"></div><div>Загрузка товаров...</div></td></tr>';

  try {
    const { data } = await supabase
      .from('products')
      .select('*')
      .eq('supplier', supplierSelect.value);

    // Добавляем все товары без рендера
    data.forEach(p => addItem(p, true));
    
    // Восстанавливаем порядок из Supabase
    await restoreItemOrder();
    
    // Один рендер в конце
    render();
    saveDraft();
    saveStateToHistory();
  } catch (err) {
    console.error('Ошибка загрузки товаров:', err);
    showToast('Ошибка', 'Не удалось загрузить товары', 'error');
  } finally {
    supplierSelect.disabled = false;
  }
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
  let query = supabase
    .from('products')
    .select('*')
    .limit(10);

  // Фильтр по юр. лицу
  const currentLegalEntity = orderState.settings.legalEntity;
  if (currentLegalEntity === 'Пицца Стар') {
    query = query.eq('legal_entity', 'Пицца Стар');
  } else {
    query = query.in('legal_entity', ['Бургер БК', 'Воглия Матта']);
  }

  // если выбран поставщик — ищем только по нему
  if (supplierSelect.value) {
    query = query.eq('supplier', supplierSelect.value);
  }

  // Поиск одновременно по SKU и по имени
  query = query.or(`sku.ilike.%${q}%,name.ilike.%${q}%`);

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
      if (clearSearchBtn) clearSearchBtn.classList.add('hidden');
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

function clearManualForm() {
  document.getElementById('m_name').value = '';
  document.getElementById('m_sku').value = '';
  document.getElementById('m_supplier').value = '';
  document.getElementById('m_box').value = '';
  document.getElementById('m_pallet').value = '';
  document.getElementById('m_save').checked = true;
}

addManualBtn.addEventListener('click', () => {
  clearManualForm();
  document.getElementById('m_legalEntity').value = orderState.settings.legalEntity;
  // Подставляем текущего поставщика если выбран
  if (orderState.settings.supplier) {
    document.getElementById('m_supplier').value = orderState.settings.supplier;
  }
  manualModal.classList.remove('hidden');
  document.getElementById('m_name').focus();
});

closeManualBtn.addEventListener('click', () => {
  manualModal.classList.add('hidden');
});

manualCancelBtn.addEventListener('click', () => {
  manualModal.classList.add('hidden');
});


/* ================= ДОБАВЛЕНИЕ ================= */
function addItem(p, skipRender = false) {
  // Проверка дубликатов по SKU
  if (p.sku && !skipRender) {
    const existing = orderState.items.find(item => item.sku === p.sku);
    if (existing) {
      showToast('Уже в заказе', `${p.sku} ${p.name} уже добавлен`, 'info');
      return;
    }
  }

  orderState.items.push({
    id: crypto.randomUUID(),
    supabaseId: p.id,
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
  if (!skipRender) {
    render();
    saveDraft();
    saveStateToHistory();
  }
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

function applyHistoryState(state, toastMsg) {
  orderState.items = state.items;
  orderState.settings = state.settings;
  
  // Конвертируем строки обратно в Date объекты
  ['today', 'deliveryDate', 'safetyEndDate'].forEach(key => {
    if (orderState.settings[key] && typeof orderState.settings[key] === 'string') {
      orderState.settings[key] = new Date(orderState.settings[key]);
    }
  });
  
  render();
  
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
  showToast(toastMsg, '', 'info');
}

// Undo
if (undoBtn) {
  undoBtn.addEventListener('click', () => {
    updateHistoryButtons();
    const state = history.undo();
    if (state) applyHistoryState(state, 'Отменено');
  });
}

// Redo
if (redoBtn) {
  redoBtn.addEventListener('click', () => {
    updateHistoryButtons();
    const state = history.redo();
    if (state) applyHistoryState(state, 'Повторено');
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

/* ================= КОПИРОВАНИЕ ЗАКАЗА ================= */
copyOrderBtn.addEventListener('click', () => {
  if (!orderState.items.length) {
    showToast('Заказ пуст', 'Добавьте товары для копирования', 'error');
    return;
  }

  const deliveryDate = orderState.settings.deliveryDate
    ? orderState.settings.deliveryDate.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
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
    // Стрелка вправо — для number инпутов selectionStart не работает, проверяем через Tab-подобное поведение
    else if (e.key === 'ArrowRight') {
      // Пробуем получить позицию курсора (работает не везде для type=number)
      let atEnd = true;
      try { atEnd = input.selectionStart >= input.value.length; } catch(err) { /* OK */ }
      if (atEnd) {
        e.preventDefault();
        moveToCell(rowIndex, columnIndex + 1);
      }
    }
    // Стрелка влево
    else if (e.key === 'ArrowLeft') {
      let atStart = true;
      try { atStart = input.selectionStart === 0; } catch(err) { /* OK */ }
      if (atStart) {
        e.preventDefault();
        moveToCell(rowIndex, columnIndex - 1);
      }
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
  // Пустое состояние
  if (orderState.items.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="11" style="text-align:center;padding:40px 20px;color:#8a8a8a;">
          <div style="font-size:32px;margin-bottom:8px;">📦</div>
          <div style="font-size:14px;font-weight:600;margin-bottom:4px;">Нет товаров в заказе</div>
          <div style="font-size:13px;">Выберите поставщика или найдите товар через поиск</div>
        </td>
      </tr>`;
    updateItemsCounter();
    updateFinalSummary();
    return;
  }

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
    openProductForEdit: (sku) => {
      openEditCardBySku(sku, (updated) => {
        // Обновляем item в заказе после редактирования карточки
        const item = orderState.items.find(i => i.sku === sku);
        if (item) {
          item.name = updated.name || item.name;
          item.sku = updated.sku || item.sku;
          item.qtyPerBox = updated.qty_per_box || item.qtyPerBox;
          item.boxesPerPallet = updated.boxes_per_pallet || item.boxesPerPallet;
          item.unitOfMeasure = updated.unit_of_measure || item.unitOfMeasure;
          render();
          saveDraft();
        }
      });
    }
  });
  
  // Применяем видимость колонок после рендера
  toggleTransitColumn();
  toggleStockColumn();
  updateItemsCounter();
  updateFinalSummary();
  
  // #6 Проверка данных — подсветка аномального расхода
  if (document.getElementById('dataValidation')?.value === 'true') {
    validateConsumptionData();
  }
}

/* ================= #6 ПРОВЕРКА ДАННЫХ ================= */
let consumptionCache = null;

async function loadConsumptionHistory(supplier) {
  if (consumptionCache && consumptionCache.supplier === supplier) return consumptionCache.data;
  
  const legalEntity = orderState.settings.legalEntity || 'Бургер БК';
  const { data, error } = await supabase
    .from('orders')
    .select('order_items(sku, consumption_period)')
    .eq('legal_entity', legalEntity)
    .eq('supplier', supplier)
    .order('created_at', { ascending: false })
    .limit(2);
  
  const avgMap = new Map();
  if (!error && data) {
    const bySku = {};
    data.forEach(order => {
      (order.order_items || []).forEach(item => {
        if (!item.sku || !item.consumption_period) return;
        if (!bySku[item.sku]) bySku[item.sku] = [];
        bySku[item.sku].push(item.consumption_period);
      });
    });
    Object.entries(bySku).forEach(([sku, vals]) => {
      avgMap.set(sku, vals.reduce((a, b) => a + b, 0) / vals.length);
    });
  }
  
  consumptionCache = { supplier, data: avgMap };
  return avgMap;
}

async function validateConsumptionData() {
  const supplier = orderState.settings.supplier;
  if (!supplier) return;
  if (document.getElementById('dataValidation')?.value !== 'true') return;
  
  const avgMap = await loadConsumptionHistory(supplier);
  if (!avgMap.size) return;
  
  const rows = tbody.querySelectorAll('tr');
  orderState.items.forEach((item, idx) => {
    const row = rows[idx];
    if (!row) return;
    const consumptionInput = row.querySelector('input');
    if (!consumptionInput) return;
    
    if (!item.sku || !item.consumptionPeriod) {
      consumptionInput.classList.remove('consumption-warning');
      consumptionInput.title = '';
      return;
    }
    
    const avg = avgMap.get(item.sku);
    if (!avg) {
      consumptionInput.classList.remove('consumption-warning');
      consumptionInput.title = '';
      return;
    }
    
    const deviation = Math.abs(item.consumptionPeriod - avg) / avg;
    
    if (deviation > 0.25) {
      consumptionInput.classList.add('consumption-warning');
      consumptionInput.title = `⚠️ Расход сильно отличается от среднего (${nf.format(Math.round(avg))}), проверьте данные`;
    } else {
      consumptionInput.classList.remove('consumption-warning');
      consumptionInput.title = '';
    }
  });
}

// Экспортируем для вызова из table-renderer
window._validateConsumptionData = validateConsumptionData;

/* ================= СЧЁТЧИК ПОЗИЦИЙ ================= */
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

function updateItemsCounter() {
  const counter = document.getElementById('itemsCounter');
  if (!counter) return;
  const count = orderState.items.length;
  if (count === 0) {
    counter.textContent = '';
  } else {
    counter.textContent = `(${count} поз.)`;
  }
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

render();



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
initPlanning();
initDeliveryCalendar();

/* ================= ЗАГРУЗКА ЗАКАЗА ИЗ КАЛЕНДАРЯ ================= */
/* ═══════ ЗАГРУЗКА ЗАКАЗА ИЗ ИСТОРИИ/КАЛЕНДАРЯ ═══════ */

async function loadOrderIntoForm(order, legalEntity, isEditing = false) {
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

  if (safetyStockManager) safetyStockManager.setDays(orderState.settings.safetyDays);
  document.getElementById('periodDays').value = orderState.settings.periodDays;
  document.getElementById('unit').value = orderState.settings.unit;
  document.getElementById('hasTransit').value = orderState.settings.hasTransit ? 'true' : 'false';

  for (const histItem of (order.order_items || [])) {
    const { data: productData } = await supabase
      .from('products')
      .select('*')
      .eq('sku', histItem.sku)
      .single();

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

/* ================= ОБНОВЛЕНИЕ КАРТОЧКИ В ЗАКАЗЕ ================= */
window.addEventListener('product-card-updated', (e) => {
  const { sku, name, qty_per_box, boxes_per_pallet, unit_of_measure } = e.detail;
  if (!sku) return;
  
  let updated = false;
  orderState.items.forEach(item => {
    if (item.sku === sku) {
      item.name = name;
      item.qtyPerBox = qty_per_box;
      item.boxesPerPallet = boxes_per_pallet;
      item.unitOfMeasure = unit_of_measure;
      updated = true;
    }
  });
  
  if (updated) {
    render();
    saveDraft();
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

/* ================= ЗАКРЫТИЕ МОДАЛОК ПО ФОНУ ================= */
document.querySelectorAll('.modal').forEach(modal => {
  modal.addEventListener('click', (e) => {
    // Закрываем только если кликнули по самому overlay (не по modal-box)
    if (e.target === modal) {
      modal.classList.add('hidden');
    }
  });
});

/* ================= КЛАВИШИ ENTER/ESC ================= */
document.addEventListener('keydown', (e) => {
  // ESC — закрытие модалок
  if (e.key === 'Escape') {
    const saveOrderModal = document.getElementById('saveOrderModal');
    if (saveOrderModal && !saveOrderModal.classList.contains('hidden')) {
      saveOrderModal.classList.add('hidden');
    } else if (!manualModal.classList.contains('hidden')) {
      manualModal.classList.add('hidden');
    } else if (!editCardModal.classList.contains('hidden')) {
      editCardModal.classList.add('hidden');
    } else if (!databaseModal.classList.contains('hidden')) {
      databaseModal.classList.add('hidden');
    } else if (!historyModal.classList.contains('hidden')) {
      historyModal.classList.add('hidden');
    } else if (analyticsModal && !analyticsModal.classList.contains('hidden')) {
      analyticsModal.classList.add('hidden');
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

/* ================= ИМПОРТ ОСТАТКОВ ================= */
const importStockBtn = document.getElementById('importStockBtn');
if (importStockBtn) {
  importStockBtn.addEventListener('click', () => {
    if (!orderState.items.length) {
      showToast('Нет товаров', 'Сначала добавьте товары в заказ', 'info');
      return;
    }
    showImportDialog('order', orderState.items, (updatedItems) => {
      orderState.items = updatedItems;
      render();
      saveDraft();
      saveStateToHistory();
    });
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