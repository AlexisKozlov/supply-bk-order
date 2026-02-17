import { orderState, currentUser, setCurrentUser, loadCurrentUser } from './state.js';
import { calculateItem } from './calculations.js';
import { supabase } from './supabase.js';
import { history } from './history.js';
import { SafetyStockManager } from './safety-stock.js';

import { showToast, customConfirm } from './modals.js';
import { loadDatabaseProducts, setupDatabaseSearch, openEditCardBySku, initDatabaseTabs, initDatabaseButtons, setupSupplierSearch, openNewSupplier } from './database.js';
import { renderTable, updateRow } from './table-renderer.js';
import { exportToExcel, canExportExcel } from './excel-export.js';
import { getOrdersAnalytics, renderAnalytics } from './analytics.js';
import { loadOrderHistory as loadHistory } from './order-history.js';
import { initPlanning } from './planning.js';
import { showImportDialog } from './import-stock.js';
import { initDeliveryCalendar } from './delivery-calendar.js';
import { initShareOrder } from './share-order.js';
import { saveDraft, loadDraft, clearDraft, isLoadingDraft } from './draft.js';
import { validateConsumptionData, resetConsumptionCache } from './data-validation.js';
import { initOrderOperations, saveItemOrder, restoreItemOrder } from './order-operations.js';
import { esc } from './utils.js';

/* ================= DOM ================= */
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
  const topbarBadge = document.getElementById('topbarEntity');
  const le = orderState.settings.legalEntity || 'Бургер БК';
  if (topbarBadge) topbarBadge.textContent = le;
}
const historyContainer = document.getElementById('orderHistory');
const historySupplier = document.getElementById('historySupplier');
const historyModal = document.getElementById('historyModal');

const manualModal = document.getElementById('manualModal');
const closeManualBtn = document.getElementById('closeManual');


const nf = new Intl.NumberFormat('ru-RU', {
  maximumFractionDigits: 0
});


/* showToast и customConfirm импортированы из modals.js */


loginBtn.addEventListener('click', doLogin);
loginPassword.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') doLogin();
});

const loginUserSelect = document.getElementById('loginUser');
const userBadge = document.getElementById('userBadge');
const userDropdown = document.getElementById('userDropdown');
const logoutBtn = document.getElementById('logoutBtn');

// Загружаем список пользователей при старте
(async function loadUserList() {
  try {
    const { data } = await supabase.from('users').select('name').order('name');
    if (data && loginUserSelect) {
      data.forEach(u => {
        const opt = document.createElement('option');
        opt.value = u.name;
        opt.textContent = u.name;
        loginUserSelect.appendChild(opt);
      });
    }
  } catch(e) { /* fallback — поле пароля работает без списка */ }
})();

// Восстанавливаем текущего пользователя
const storedUser = loadCurrentUser();
if (storedUser) {
  updateUserUI(storedUser);
}

function updateUserUI(user) {
  if (userBadge && user) {
    // Новый layout: обновляем элементы внутри sidebar-user
    const nameEl = document.getElementById('userNameDisplay');
    const avatarEl = document.getElementById('userAvatarLetters');
    if (nameEl) nameEl.textContent = user.name;
    if (avatarEl) avatarEl.textContent = user.name.split(' ').map(w => w[0]).join('').slice(0, 2);
    userBadge.classList.remove('hidden');
  }
  // Фильтруем юр.лица по доступным пользователю
  filterLegalEntities(user);
}

function filterLegalEntities(user) {
  const allowed = user?.legal_entities;
  if (!allowed || !allowed.length) return; // пустой = видит всё
  
  const selects = ['legalEntity', 'historyLegalEntity', 'planLegalEntity', 'm_legalEntity'];
  selects.forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    Array.from(el.options).forEach(opt => {
      if (opt.value && !allowed.includes(opt.value)) {
        opt.style.display = 'none';
        if (el.value === opt.value) el.value = allowed[0];
      } else {
        opt.style.display = '';
      }
    });
  });
  
  // Устанавливаем первое доступное юр.лицо
  const mainSelect = document.getElementById('legalEntity');
  if (mainSelect && allowed.length && !allowed.includes(mainSelect.value)) {
    mainSelect.value = allowed[0];
    mainSelect.dispatchEvent(new Event('change'));
  }
}

// Клик по имени — toggle dropdown
if (userBadge) {
  userBadge.addEventListener('click', (e) => {
    e.stopPropagation();
    userDropdown?.classList.toggle('hidden');
  });
}

// Закрытие dropdown при клике вне
document.addEventListener('click', () => {
  userDropdown?.classList.add('hidden');
});

async function afterLogin(user) {
  setCurrentUser(user);
  loginOverlay.style.display = 'none';
  updateUserUI(user);
  
  // Перезагружаем поставщиков для текущего юр.лица
  const le = document.getElementById('legalEntity').value;
  orderState.settings.legalEntity = le;
  await loadSuppliers(le);
  loadOrderHistory();
}

async function doLogin() {
  const selectedUser = loginUserSelect?.value;
  const pwd = loginPassword.value;
  
  if (!selectedUser) {
    showToast('Выберите пользователя', 'Укажите своё имя из списка', 'error');
    return;
  }
  if (!pwd) {
    showToast('Введите пароль', '', 'error');
    return;
  }
  
  try {
    const { data, error } = await supabase.rpc('check_user_password', {
      user_name: selectedUser,
      user_password: pwd
    });
    
    if (error || !data?.success) {
      const valid = await checkLegacyPassword(pwd);
      if (valid) {
        await afterLogin({ name: selectedUser || 'Пользователь', role: 'user' });
        return;
      }
      showToast('Ошибка входа', 'Неверный пароль', 'error');
      return;
    }
    
    await afterLogin(data.user);
  } catch(e) {
    const valid = await checkLegacyPassword(pwd);
    if (valid) {
      await afterLogin({ name: selectedUser || 'Пользователь', role: 'user' });
    } else {
      showToast('Ошибка входа', 'Неверный пароль', 'error');
    }
  }
}

async function checkLegacyPassword(pwd) {
  try {
    const { data } = await supabase
      .from('settings')
      .select('value')
      .eq('key', 'order_calculator_password')
      .single();
    if (data?.value) return pwd === data.value;
  } catch (e) { /* fallback */ }
  return pwd === '157';
}

// Выход
if (logoutBtn) {
  logoutBtn.addEventListener('click', () => {
    setCurrentUser(null);
    localStorage.removeItem('bk_logged_in');
    
    // Очищаем данные заказа
    orderState.items = [];
    orderState.settings.supplier = '';
    
    // Показываем все юр.лица (снимаем фильтр)
    ['legalEntity', 'historyLegalEntity', 'planLegalEntity', 'm_legalEntity'].forEach(id => {
      const el = document.getElementById(id);
      if (el) Array.from(el.options).forEach(opt => { opt.style.display = ''; });
    });
    
    // Очищаем поставщиков
    const supplierSelect = document.getElementById('supplierFilter');
    if (supplierSelect) {
      supplierSelect.innerHTML = '<option value="">Все / свободный</option>';
    }
    
    // Скрываем секцию заказа
    orderSection?.classList.add('hidden');
    
    // UI
    loginOverlay.style.display = '';
    if (userBadge) userBadge.classList.add('hidden');
    if (userDropdown) userDropdown.classList.add('hidden');
    loginPassword.value = '';
    if (loginUserSelect) loginUserSelect.value = '';
  });
}

/* ================= СМЕНА ПАРОЛЯ ================= */
const changePasswordBtn = document.getElementById('changePasswordBtn');
const changePasswordModal = document.getElementById('changePasswordModal');

if (changePasswordBtn && changePasswordModal) {
  changePasswordBtn.addEventListener('click', () => {
    userDropdown?.classList.add('hidden');
    changePasswordModal.classList.remove('hidden');
    document.getElementById('currentPassword').value = '';
    document.getElementById('newPassword').value = '';
    document.getElementById('confirmNewPassword').value = '';
    document.getElementById('currentPassword').focus();
  });

  document.getElementById('closeChangePassword')?.addEventListener('click', () => {
    changePasswordModal.classList.add('hidden');
  });
  document.getElementById('cancelChangePassword')?.addEventListener('click', () => {
    changePasswordModal.classList.add('hidden');
  });
  changePasswordModal.addEventListener('click', (e) => {
    if (e.target === changePasswordModal) changePasswordModal.classList.add('hidden');
  });

  document.getElementById('confirmChangePassword')?.addEventListener('click', async () => {
    const currentPwd = document.getElementById('currentPassword').value;
    const newPwd = document.getElementById('newPassword').value;
    const confirmPwd = document.getElementById('confirmNewPassword').value;

    if (!currentPwd || !newPwd) {
      showToast('Заполните все поля', '', 'error');
      return;
    }
    if (newPwd !== confirmPwd) {
      showToast('Пароли не совпадают', 'Новый пароль и подтверждение отличаются', 'error');
      return;
    }
    if (newPwd.length < 3) {
      showToast('Слишком короткий', 'Минимум 3 символа', 'error');
      return;
    }

    const userName = currentUser?.name;
    if (!userName) {
      showToast('Ошибка', 'Пользователь не определён', 'error');
      return;
    }

    try {
      const { data, error } = await supabase.rpc('change_user_password', {
        user_name: userName,
        old_password: currentPwd,
        new_password: newPwd
      });

      if (error || !data?.success) {
        showToast('Ошибка', data?.error || 'Не удалось сменить пароль', 'error');
        return;
      }

      showToast('Пароль изменён', '', 'success');
      changePasswordModal.classList.add('hidden');
    } catch(e) {
      showToast('Ошибка', 'Функция смены пароля недоступна', 'error');
    }
  });
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
  const oldUnit = orderState.settings.unit;
  const newUnit = e.target.value;
  orderState.settings.unit = newUnit;
  
  // Конвертируем данные при смене единиц
  if (oldUnit !== newUnit && orderState.items.length) {
    orderState.items.forEach(item => {
      const qpb = item.qtyPerBox || 1;
      if (oldUnit === 'pieces' && newUnit === 'boxes') {
        // шт → кор
        item.consumptionPeriod = item.consumptionPeriod ? Math.round(item.consumptionPeriod / qpb * 100) / 100 : 0;
        item.stock = item.stock ? Math.round(item.stock / qpb * 100) / 100 : 0;
        item.transit = item.transit ? Math.round(item.transit / qpb * 100) / 100 : 0;
        item.finalOrder = item.finalOrder ? Math.ceil(item.finalOrder / qpb) : 0;
      } else if (oldUnit === 'boxes' && newUnit === 'pieces') {
        // кор → шт
        item.consumptionPeriod = Math.round(item.consumptionPeriod * qpb);
        item.stock = Math.round(item.stock * qpb);
        item.transit = Math.round(item.transit * qpb);
        item.finalOrder = Math.round(item.finalOrder * qpb);
      }
    });
    // Полный ре-рендер чтобы инпуты обновились
    render();
  } else {
    rerenderAll();
  }
  
  resetConsumptionCache();
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
  render();
});

// #1 Мгновенное включение/отключение проверки данных
document.getElementById('dataValidation')?.addEventListener('change', () => {
  if (document.getElementById('dataValidation').value === 'true') {
    validateConsumptionData(tbody);
  } else {
    // Немедленно убираем все предупреждения
    tbody.querySelectorAll('.consumption-warning').forEach(el => {
      el.classList.remove('consumption-warning');
      el.title = '';
    });
  }
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
  supplierSelect.innerHTML = '<option value="">Все / свободный</option>';
  historySupplier.innerHTML = '<option value="">Все</option>';
  
  // Загружаем из таблицы suppliers (не products!)
  let query = supabase.from('suppliers').select('short_name, legal_entity');
  
  if (legalEntity === 'Пицца Стар') {
    query = query.eq('legal_entity', 'Пицца Стар');
  } else {
    query = query.in('legal_entity', ['Бургер БК', 'Воглия Матта']);
  }
  
  const { data, error } = await query.order('short_name');
  
  if (error || !data) {
    console.error('Ошибка загрузки поставщиков:', error);
    return;
  }
  
  data.forEach(s => {
    const opt1 = document.createElement('option');
    opt1.value = s.short_name;
    opt1.textContent = s.short_name;
    supplierSelect.appendChild(opt1);

    const opt2 = document.createElement('option');
    opt2.value = s.short_name;
    opt2.textContent = s.short_name;
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
  resetConsumptionCache(); // сбрасываем кеш проверки данных
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
  
  if (!supplier || supplier === '__new__') {
    showToast('Выберите поставщика', 'Поле обязательно для заполнения', 'error');
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

  // Всегда сохраняем в базу
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

  // Добавляем в заказ только если поставщик совпадает с текущим в параметрах
  const currentSupplier = orderState.settings.supplier;
  if (data.supplier && currentSupplier && data.supplier === currentSupplier) {
    addItem(data);
    showToast('Товар добавлен', 'Сохранён в базу и добавлен в текущий заказ', 'success');
  } else {
    showToast('Товар сохранён', 'Сохранён в базу данных (не добавлен в заказ — другой поставщик)', 'success');
  }

  manualModal.classList.add('hidden');
});

function clearManualForm() {
  document.getElementById('m_name').value = '';
  document.getElementById('m_sku').value = '';
  document.getElementById('m_box').value = '';
  document.getElementById('m_pallet').value = '';
}

/** Загрузить поставщиков в произвольный select */
async function populateSupplierSelect(selectEl, currentValue) {
  const { data } = await supabase.from('suppliers').select('short_name').order('short_name');
  selectEl.innerHTML = '<option value="">— Выберите поставщика —</option>';
  if (data) {
    data.forEach(s => {
      const opt = document.createElement('option');
      opt.value = s.short_name;
      opt.textContent = s.short_name;
      selectEl.appendChild(opt);
    });
  }
  const newOpt = document.createElement('option');
  newOpt.value = '__new__';
  newOpt.textContent = '＋ Новый поставщик...';
  selectEl.appendChild(newOpt);
  if (currentValue) selectEl.value = currentValue;
}

addManualBtn.addEventListener('click', async () => {
  clearManualForm();
  document.getElementById('m_legalEntity').value = orderState.settings.legalEntity;
  
  const supplierSelect = document.getElementById('m_supplier');
  await populateSupplierSelect(supplierSelect, orderState.settings.supplier || '');
  
  // Обработчик "Новый поставщик" в селекторе
  supplierSelect.onchange = () => {
    if (supplierSelect.value === '__new__') {
      supplierSelect.value = '';
      manualModal.style.display = 'none';
      
      openNewSupplier(document.getElementById('m_legalEntity').value);
      
      const supplierModal = document.getElementById('editSupplierModal');
      const obs = new MutationObserver(async () => {
        if (supplierModal.classList.contains('hidden')) {
          obs.disconnect();
          manualModal.style.display = '';
          const newName = document.getElementById('s_shortName').value.trim();
          await populateSupplierSelect(supplierSelect, newName || '');
        }
      });
      obs.observe(supplierModal, { attributes: true, attributeFilter: ['class'] });
    }
  };
  
  manualModal.classList.remove('hidden');
  document.getElementById('m_sku').focus();
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
    validateConsumptionData(tbody);
  }
}


/* ================= СЧЁТЧИК ПОЗИЦИЙ ================= */
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
    finalSummary.innerHTML = '<span style="color:var(--text-muted);font-size:12px;">Нет товаров с заказом</span>';
    return;
  }

  // Считаем итоги
  let totalBoxes = 0;
  let totalPallets = 0;
  const detailLines = [];

  itemsWithOrder.forEach(item => {
    let boxes, pieces;
    if (orderState.settings.unit === 'boxes') {
      boxes = item.finalOrder;
      pieces = item.finalOrder * (item.qtyPerBox || 1);
    } else {
      boxes = item.qtyPerBox ? Math.ceil(item.finalOrder / item.qtyPerBox) : 0;
      pieces = item.finalOrder;
    }
    boxes = Math.ceil(boxes);
    totalBoxes += boxes;

    if (item.boxesPerPallet && item.boxesPerPallet > 0) {
      totalPallets += boxes / item.boxesPerPallet;
    }

    const unit = item.unitOfMeasure || 'шт';
    detailLines.push(`<b>${item.sku ? esc(item.sku) + ' ' : ''}${esc(item.name)}</b> — ${nf.format(boxes)} кор. (${nf.format(Math.round(pieces))} ${unit})`);
  });

  totalPallets = Math.round(totalPallets * 100) / 100;

  // Компактная сводка
  finalSummary.innerHTML = `
    <span class="summary-stat"><strong>${itemsWithOrder.length}</strong> поз.</span>
    <span class="summary-divider">·</span>
    <span class="summary-stat"><strong>${nf.format(totalBoxes)}</strong> кор.</span>
    <span class="summary-divider">·</span>
    <span class="summary-stat"><strong>${totalPallets}</strong> пал.</span>
    <button class="btn btn-ghost btn-sm summary-details-btn" onclick="this.nextElementSibling.classList.toggle('hidden')">▾ Подробнее</button>
    <div class="summary-details-panel hidden">${detailLines.join('<br>')}</div>
  `;
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
initOrderOperations({
  render,
  updateFinalSummary,
  saveStateToHistory,
  loadOrderHistory,
  loadSuppliers,
  safetyStockManager,
  addItem
});
initModals();
initPlanning();
initDeliveryCalendar();
initShareOrder();

// Загрузка черновика после загрузки поставщиков
initSuppliers.then(async () => {
  await loadDraft({
    loadSuppliers,
    safetyStockManager,
    restoreItemOrder,
    render,
    updateEntityBadge,
    orderSection
  });
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
initDatabaseTabs();
initDatabaseButtons();
setupSupplierSearch();

// Пункт 6: при удалении карточки из базы — удаляем из текущего заказа
document.addEventListener('product:deleted', (e) => {
  const { sku, id } = e.detail;
  if (!orderState.items.length) return;
  const skuStr = sku ? String(sku).trim() : '';
  const before = orderState.items.length;
  orderState.items = orderState.items.filter(item => {
    // Фильтруем по SKU
    if (skuStr && String(item.sku || '').trim() === skuStr) return false;
    // Фильтруем по supabase ID (из базы)
    if (id && item.supabaseId && String(item.supabaseId) === String(id)) return false;
    return true;
  });
  if (orderState.items.length < before) {
    renderTable(orderState, { removeItem, openEditCardBySku });
    saveDraft();
    showToast('Товар удалён из заказа', `${skuStr || ''} убран из текущего заказа`, 'info');
  }
});

// При создании/удалении поставщика — обновляем селектор в параметрах заказа
document.addEventListener('suppliers:updated', async () => {
  const currentSupplier = orderState.settings.supplier;
  const le = document.getElementById('legalEntity').value;
  await loadSuppliers(le);
  // Восстанавливаем выбранного поставщика если он ещё существует
  if (currentSupplier) {
    const exists = [...supplierSelect.options].some(o => o.value === currentSupplier);
    if (exists) {
      supplierSelect.value = currentSupplier;
    } else {
      // Поставщик удалён — сбрасываем
      supplierSelect.value = '';
      orderState.settings.supplier = '';
    }
  }
});

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
    }, orderState.settings.legalEntity);
  });
}

/* ================= ЗАГРУЗКА ИЗ 1С ================= */
const load1cBtn = document.getElementById('load1cBtn');
if (load1cBtn) {
  load1cBtn.addEventListener('click', async () => {
    if (!orderState.items.length) {
      showToast('Нет товаров', 'Сначала добавьте товары в заказ', 'info');
      return;
    }

    const legalEntity = orderState.settings.legalEntity;
    const periodDays = orderState.settings.periodDays || 30;

    // Собираем SKU из текущего заказа
    const skus = orderState.items.map(i => i.sku).filter(Boolean);
    if (!skus.length) {
      showToast('Нет артикулов', 'У товаров в заказе нет SKU для сопоставления с 1С', 'error');
      return;
    }

    load1cBtn.disabled = true;
    load1cBtn.textContent = '⏳ Загрузка...';

    try {
      const { data, error } = await supabase
        .from('stock_1c')
        .select('sku, stock, consumption, period_days, updated_at')
        .eq('legal_entity', legalEntity)
        .in('sku', skus);

      if (error) {
        showToast('Ошибка', 'Не удалось загрузить данные из 1С', 'error');
        console.error(error);
        return;
      }

      if (!data?.length) {
        showToast('Нет данных', `В таблице stock_1c нет данных для «${legalEntity}»`, 'info');
        return;
      }

      // Карта SKU → данные 1С
      const stockMap = new Map(data.map(d => [d.sku, d]));

      // Проверяем свежесть данных
      let oldestUpdate = null;
      data.forEach(d => {
        const t = new Date(d.updated_at);
        if (!oldestUpdate || t < oldestUpdate) oldestUpdate = t;
      });

      const hoursAgo = oldestUpdate ? Math.round((Date.now() - oldestUpdate) / 3600000) : null;
      
      let filled = 0;
      const isBoxes = orderState.settings.unit === 'boxes';

      orderState.items.forEach(item => {
        const d = item.sku ? stockMap.get(item.sku) : null;
        if (!d) return;

        const qpb = item.qtyPerBox || 1;

        // Остаток: 1С отдаёт в штуках → конвертируем если нужно
        const stockUnits = d.stock || 0;
        item.stock = isBoxes ? Math.round(stockUnits / qpb * 100) / 100 : Math.round(stockUnits);

        // Расход: 1С отдаёт за period_days → пересчитываем на periodDays пользователя
        const consumptionUnits = d.consumption || 0;
        const srcDays = d.period_days || 30;
        const dailyConsumption = srcDays > 0 ? consumptionUnits / srcDays : 0;
        const adjustedConsumption = dailyConsumption * periodDays;
        item.consumptionPeriod = isBoxes
          ? Math.round(adjustedConsumption / qpb * 100) / 100
          : Math.round(adjustedConsumption);

        filled++;
      });

      render();
      saveDraft();
      saveStateToHistory();

      const freshLabel = hoursAgo !== null
        ? (hoursAgo < 1 ? 'только что' : `${hoursAgo} ч. назад`)
        : '';
      showToast(
        `Данные из 1С загружены`,
        `${filled} из ${orderState.items.length} позиций${freshLabel ? ' · обновлено ' + freshLabel : ''}`,
        'success'
      );
    } catch (e) {
      console.error(e);
      showToast('Ошибка', 'Таблица stock_1c не найдена. Выполните миграцию.', 'error');
    } finally {
      load1cBtn.disabled = false;
      load1cBtn.textContent = '📊 Из 1С';
    }
  });
}

/* ================= ПОДСТАВИТЬ ДАННЫЕ ИЗ ПРОШЛОГО ЗАКАЗА ================= */
const fillFromLastOrderBtn = document.getElementById('fillFromLastOrder');
if (fillFromLastOrderBtn) {
  fillFromLastOrderBtn.addEventListener('click', async () => {
    if (!orderState.items.length) {
      showToast('Нет товаров', 'Сначала добавьте товары в заказ', 'info');
      return;
    }
    
    const supplier = orderState.settings.supplier;
    if (!supplier) {
      showToast('Выберите поставщика', 'Для подстановки данных нужен выбранный поставщик', 'info');
      return;
    }
    
    fillFromLastOrderBtn.disabled = true;
    fillFromLastOrderBtn.textContent = '⏳ Загрузка...';
    
    try {
      // Загружаем последний заказ этому поставщику
      const { data, error } = await supabase
        .from('orders')
        .select('id, unit, period_days, order_items(sku, name, consumption_period, stock, transit, qty_per_box)')
        .eq('supplier', supplier)
        .eq('legal_entity', orderState.settings.legalEntity)
        .order('created_at', { ascending: false })
        .limit(1)
        .single();
      
      if (error || !data || !data.order_items?.length) {
        showToast('Нет данных', `Прошлых заказов для «${supplier}» не найдено`, 'info');
        return;
      }
      
      // Строим lookup по SKU из прошлого заказа
      const prevMap = new Map();
      data.order_items.forEach(item => {
        if (item.sku) prevMap.set(item.sku, item);
      });
      
      const prevUnit = data.unit || 'pieces';
      const currentUnit = orderState.settings.unit;
      let filled = 0;
      
      orderState.items.forEach(item => {
        if (!item.sku) return;
        const prev = prevMap.get(item.sku);
        if (!prev) return;
        
        let consumption = prev.consumption_period || 0;
        const qpb = item.qtyPerBox || 1;
        
        // Конвертация единиц: если прошлый заказ был в других единицах
        if (prevUnit === 'pieces' && currentUnit === 'boxes') {
          consumption = Math.round(consumption / qpb);
        } else if (prevUnit === 'boxes' && currentUnit === 'pieces') {
          consumption = Math.round(consumption * qpb);
        }
        
        // Подставляем только если поле пустое (=0) — не перезатираем уже введённые данные
        if (!item.consumptionPeriod && consumption) {
          item.consumptionPeriod = consumption;
          filled++;
        }
      });
      
      if (filled > 0) {
        render();
        saveDraft();
        saveStateToHistory();
        showToast('Расход подставлен', `Заполнено для ${filled} из ${orderState.items.length} товаров`, 'success');
      } else {
        showToast('Ничего не подставлено', 'Расход уже заполнен или совпадений с прошлым заказом нет', 'info');
      }
    } catch (err) {
      console.error('fillFromLastOrder error:', err);
      showToast('Ошибка', 'Не удалось загрузить прошлый заказ', 'error');
    } finally {
      fillFromLastOrderBtn.disabled = false;
      fillFromLastOrderBtn.textContent = '📋 Подставить расход';
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

/* ================= ПРЕДУПРЕЖДЕНИЕ ПРИ УХОДЕ СО СТРАНИЦЫ ================= */
window.addEventListener('beforeunload', (e) => {
  // Предупреждаем только если есть товары с заполненными данными
  const hasData = orderState.items.some(item => 
    item.consumptionPeriod > 0 || item.stock > 0 || item.finalOrder > 0
  );
  if (hasData) {
    e.preventDefault();
    e.returnValue = '';
  }
});
/* ================= МОБИЛЬНОЕ МЕНЮ (SIDEBAR) ================= */
const mobileMenuToggle = document.getElementById('mobileMenuToggle');
const sidebar = document.getElementById('sidebar');

if (mobileMenuToggle && sidebar) {
  mobileMenuToggle.addEventListener('click', (e) => {
    e.stopPropagation();
    sidebar.classList.toggle('open');
  });
  
  // Закрытие при клике по кнопке sidebar
  sidebar.querySelectorAll('.sidebar-item').forEach(btn => {
    btn.addEventListener('click', () => {
      sidebar.classList.remove('open');
    });
  });
  
  // Закрытие при клике вне sidebar
  document.addEventListener('click', (e) => {
    if (!sidebar.contains(e.target) && e.target !== mobileMenuToggle) {
      sidebar.classList.remove('open');
    }
  });
}

/* ================= PAGE-МОДАЛКИ (модалки как страницы) ================= */
(function() {
  const mainArea = document.querySelector('.main-area');
  if (!mainArea) return;

  const pageModalIds = ['historyModal', 'databaseModal', 'analyticsModal', 'planningModal', 'calendarModal'];
  
  // Перемещаем внутрь main-area и добавляем класс page-modal
  pageModalIds.forEach(id => {
    const modal = document.getElementById(id);
    if (modal) {
      modal.classList.add('page-modal');
      mainArea.appendChild(modal);
    }
  });

  const sidebarMap = {
    'menuHistory': 'historyModal',
    'menuDatabase': 'databaseModal',
    'menuAnalytics': 'analyticsModal',
    'menuPlanning': 'planningModal',
    'menuCalendar': 'calendarModal',
  };

  function closeAllPageModals(exceptId) {
    pageModalIds.forEach(id => {
      if (id !== exceptId) {
        document.getElementById(id)?.classList.add('hidden');
      }
    });
  }

  const pageTitles = {
    'historyModal': 'История',
    'databaseModal': 'База данных',
    'analyticsModal': 'Аналитика',
    'planningModal': 'Планирование',
    'calendarModal': 'Календарь',
  };

  function updateSidebarActive() {
    const items = document.querySelectorAll('.sidebar-item');
    let anyPageOpen = false;
    let openTitle = '';
    
    items.forEach(item => {
      const modalId = sidebarMap[item.id];
      if (modalId) {
        const modal = document.getElementById(modalId);
        if (modal && !modal.classList.contains('hidden')) {
          item.classList.add('active');
          anyPageOpen = true;
          openTitle = pageTitles[modalId] || '';
        } else {
          item.classList.remove('active');
        }
      }
    });

    const navOrder = document.getElementById('navOrder');
    if (navOrder) {
      navOrder.classList.toggle('active', !anyPageOpen);
    }

    // Обновляем topbar title
    const pageTitle = document.getElementById('pageTitle');
    if (pageTitle) {
      pageTitle.textContent = anyPageOpen ? openTitle : 'Новый заказ';
    }

    // Скрываем content-area и topbar когда page-modal открыта
    const contentArea = document.querySelector('.content-area');
    const topbar = document.querySelector('.topbar');
    if (contentArea) contentArea.style.display = anyPageOpen ? 'none' : '';
    if (topbar) topbar.style.display = anyPageOpen ? 'none' : '';
  }

  // КЛЮЧЕВОЙ ФИК: перехватываем клики sidebar ПЕРЕД модулями (capture phase)
  Object.entries(sidebarMap).forEach(([btnId, modalId]) => {
    const btn = document.getElementById(btnId);
    if (!btn) return;
    
    btn.addEventListener('click', () => {
      // Закрываем все другие page-модалки
      closeAllPageModals(modalId);
      // Обновляем sidebar через microtask (после того как модуль откроет модалку)
      setTimeout(updateSidebarActive, 50);
    }, true); // capture: true — срабатывает ДО обработчиков модулей
  });

  // Наблюдаем за изменениями классов модалок
  pageModalIds.forEach(id => {
    const modal = document.getElementById(id);
    if (!modal) return;
    const observer = new MutationObserver(() => updateSidebarActive());
    observer.observe(modal, { attributes: true, attributeFilter: ['class'] });
  });

  // Кнопка "Новый заказ"
  const navOrder = document.getElementById('navOrder');
  if (navOrder) {
    navOrder.addEventListener('click', () => {
      closeAllPageModals();
      updateSidebarActive();
    });
  }

  updateSidebarActive();
})();