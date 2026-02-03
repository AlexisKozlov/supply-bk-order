import { orderState } from './state.js';
import { calculateItem } from './calculations.js';
import { supabase } from './supabase.js';

/* ================= DOM ================= */
const copyOrderBtn = document.getElementById('copyOrder');
const clearOrderBtn = document.getElementById('clearOrder');
const tbody = document.getElementById('items');
const supplierSelect = document.getElementById('supplierFilter');
const finalSummary = document.getElementById('finalSummary');

const addManualBtn = document.getElementById('addManual');
const manualAddBtn = document.getElementById('m_add');
const manualCancelBtn = document.getElementById('m_cancel');
const searchInput = document.getElementById('productSearch');
const searchResults = document.getElementById('searchResults');
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
const historyModal = document.getElementById('historyModal');

const manualModal = document.getElementById('manualModal');
const closeManualBtn = document.getElementById('closeManual');

let isLoadingDraft = false;

const nf = new Intl.NumberFormat('ru-RU', {
  maximumFractionDigits: 0
});

/* ================= TOAST NOTIFICATIONS ================= */
function createToastContainer() {
  if (!document.querySelector('.toast-container')) {
    const container = document.createElement('div');
    container.className = 'toast-container';
    document.body.appendChild(container);
  }
}

function showToast(title, message, type = 'info') {
  createToastContainer();
  
  const icons = {
    success: '✅',
    error: '❌',
    info: 'ℹ️'
  };

  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;
  toast.innerHTML = `
    <div class="toast-icon">${icons[type]}</div>
    <div class="toast-content">
      <div class="toast-title">${title}</div>
      ${message ? `<div class="toast-message">${message}</div>` : ''}
    </div>
    <button class="toast-close">✖</button>
  `;

  const container = document.querySelector('.toast-container');
  container.appendChild(toast);

  toast.querySelector('.toast-close').addEventListener('click', () => {
    toast.remove();
  });

  setTimeout(() => {
    toast.remove();
  }, 4000);
}

/* ================= CUSTOM CONFIRM ================= */
function customConfirm(title, message) {
  return new Promise((resolve) => {
    const modal = document.getElementById('confirmModal');
    const titleEl = document.getElementById('confirmTitle');
    const messageEl = document.getElementById('confirmMessage');
    const yesBtn = document.getElementById('confirmYes');
    const noBtn = document.getElementById('confirmNo');
    const closeBtn = document.getElementById('closeConfirm');

    titleEl.textContent = title;
    messageEl.textContent = message;
    modal.classList.remove('hidden');

    const cleanup = (result) => {
      modal.classList.add('hidden');
      yesBtn.replaceWith(yesBtn.cloneNode(true));
      noBtn.replaceWith(noBtn.cloneNode(true));
      closeBtn.replaceWith(closeBtn.cloneNode(true));
      resolve(result);
    };

    document.getElementById('confirmYes').addEventListener('click', () => cleanup(true));
    document.getElementById('confirmNo').addEventListener('click', () => cleanup(false));
    document.getElementById('closeConfirm').addEventListener('click', () => cleanup(false));
  });
}

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

  // Подтверждение сохранения
  const confirmed = await customConfirm('Сохранить заказ?', 'Заказ будет сохранен в историю');
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
      legal_entity: orderState.settings.legalEntity
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
  supplier,
  legal_entity,
  safety_days,
  period_days,
  unit,
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
    .order('created_at', { ascending: false });

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

function loadDraft() {
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
    
    document.getElementById('legalEntity').value = orderState.settings.legalEntity;
    document.getElementById('supplierFilter').value = orderState.settings.supplier;
    document.getElementById('periodDays').value = orderState.settings.periodDays;
    document.getElementById('safetyDays').value = orderState.settings.safetyDays;
    document.getElementById('unit').value = orderState.settings.unit;
    document.getElementById('hasTransit').value = orderState.settings.hasTransit ? 'true' : 'false';
    
    // Восстановление товаров
    orderState.items = data.items || [];
    
    // Сбрасываем флаг
    isLoadingDraft = false;
    updateEntityBadge();
    
    if (orderState.items.length > 0) {
      orderSection.classList.remove('hidden');
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

    div.innerHTML = `
      <div class="history-header">
        <span><b>${date}</b> — ${order.supplier} (${legalEntity})</span>
        <div class="history-actions">
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
      orderState.settings.deliveryDate = new Date(order.delivery_date);
      orderState.settings.safetyDays = order.safety_days || 0;
      orderState.settings.periodDays = order.period_days || 30;
      orderState.settings.unit = order.unit || 'pieces';

      document.getElementById('legalEntity').value = legalEntity;
      document.getElementById('deliveryDate').value = orderState.settings.deliveryDate.toISOString().slice(0, 10);
      document.getElementById('safetyDays').value = orderState.settings.safetyDays;
      document.getElementById('periodDays').value = orderState.settings.periodDays;
      document.getElementById('unit').value = orderState.settings.unit;

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
    orderState.settings[key] = isDate
      ? new Date(e.target.value)
      : +e.target.value || 0;
    rerenderAll();
    validateRequiredSettings();
    saveDraft(); // Автосохранение
  });
}

bindSetting('today', 'today', true);
bindSetting('deliveryDate', 'deliveryDate', true);
bindSetting('periodDays', 'periodDays');
bindSetting('safetyDays', 'safetyDays');


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
  saveDraft();
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
});

/* ================= ПОИСК ПО КАРТОЧКАМ ================= */
let searchTimer = null;

if (searchInput) {
  searchInput.addEventListener('input', () => {
    const q = searchInput.value.trim();
    clearTimeout(searchTimer);

    if (q.length < 2) {
      searchResults.innerHTML = '';
      return;
    }

    searchTimer = setTimeout(() => searchProducts(q), 300);
  });
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

  if (!name) {
    showToast('Введите наименование', 'Поле обязательно для заполнения', 'error');
    return;
  }

  const product = {
    name,
    sku: document.getElementById('m_sku').value || null,
    supplier: document.getElementById('m_supplier').value || null,
    legal_entity: document.getElementById('m_legalEntity').value,
    qty_per_box: +document.getElementById('m_box').value || 1,
    boxes_per_pallet: +document.getElementById('m_pallet').value || null,
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
}

/* ================= УДАЛЕНИЕ ТОВАРА ================= */
async function removeItem(itemId) {
  const confirmed = await customConfirm('Удалить товар?', 'Товар будет удален из текущего заказа');
  if (confirmed) {
    orderState.items = orderState.items.filter(item => item.id !== itemId);
    render();
    saveDraft();
    showToast('Товар удален', '', 'success');
  }
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
  tbody.innerHTML = '';

  orderState.items.forEach((item, rowIndex) => {
    const tr = document.createElement('tr');

  tr.innerHTML = `
  <td class="item-name">
  ${item.sku ? `<b>${item.sku}</b> ` : ''}${item.name}
  <div class="shortage-info hidden"></div>
</td>
  <td><input type="number" value="${item.consumptionPeriod}"></td>
  <td><input type="number" value="${item.stock}"></td>
  <td class="transit-col"><input type="number" value="${item.transit || 0}"></td>
  <td class="calc">
    <div class="calc-value">0</div>
    <button class="btn small calc-to-order" style="margin-top:4px;font-size:11px;padding:4px 8px;">→ В заказ</button>
  </td>
  <td class="order-cell">
    <input type="number" class="order-pieces" value="0" style="width:70px;"> / 
    <input type="number" class="order-boxes" value="0" style="width:70px;">
  </td>
  <td class="date">-</td>
  <td class="pallets">
    <div class="pallet-info">-</div>
    <button class="btn small round-to-pallet">Округлить</button>
  </td>
  <td><button class="btn small delete-item" style="background:#d32f2f;color:white;" title="Удалить">🗑️</button></td>
`;

 const inputs = tr.querySelectorAll('input[type="number"]');
    const orderPiecesInput = tr.querySelector('.order-pieces');
    const orderBoxesInput = tr.querySelector('.order-boxes');
    const calcToOrderBtn = tr.querySelector('.calc-to-order');
    const roundBtn = tr.querySelector('.round-to-pallet');
    const deleteBtn = tr.querySelector('.delete-item');

    // Автовыделение для расхода/остатка/транзита если значение = 0
    [inputs[0], inputs[1], inputs[2]].forEach(input => {
      input.addEventListener('focus', (e) => {
        if (e.target.value === '0') {
          e.target.select();
        }
      });
    });

    // Функция синхронизации штук и коробок
    function syncOrderInputs(fromPieces) {
      if (fromPieces) {
        // Изменили штуки - пересчитываем коробки
        const pieces = +orderPiecesInput.value || 0;
        const boxes = item.qtyPerBox ? Math.ceil(pieces / item.qtyPerBox) : 0;
        orderBoxesInput.value = boxes;
        
        // Сохраняем в зависимости от выбранных единиц
        if (orderState.settings.unit === 'pieces') {
          item.finalOrder = pieces;
        } else {
          item.finalOrder = boxes;
        }
      } else {
        // Изменили коробки - пересчитываем штуки
        const boxes = +orderBoxesInput.value || 0;
        const pieces = boxes * (item.qtyPerBox || 1);
        orderPiecesInput.value = pieces;
        
        // Сохраняем в зависимости от выбранных единиц
        if (orderState.settings.unit === 'pieces') {
          item.finalOrder = pieces;
        } else {
          item.finalOrder = boxes;
        }
      }
    }

    // Инициализация значений при рендере
    if (orderState.settings.unit === 'pieces') {
      orderPiecesInput.value = item.finalOrder || 0;
      orderBoxesInput.value = item.qtyPerBox ? Math.ceil((item.finalOrder || 0) / item.qtyPerBox) : 0;
    } else {
      orderBoxesInput.value = item.finalOrder || 0;
      orderPiecesInput.value = (item.finalOrder || 0) * (item.qtyPerBox || 1);
    }

    // Колонка 0: Расход
    inputs[0].addEventListener('input', e => {
      item.consumptionPeriod = +e.target.value || 0;
      updateRow(tr, item);
      saveDraft();
    });
    setupExcelNavigation(inputs[0], rowIndex, 0);

    // Колонка 1: Остаток
    inputs[1].addEventListener('input', e => {
      item.stock = +e.target.value || 0;
      updateRow(tr, item);
      saveDraft();
    });
    setupExcelNavigation(inputs[1], rowIndex, 1);

    // Колонка 2: Транзит
    inputs[2].addEventListener('input', e => {
      item.transit = +e.target.value || 0;
      updateRow(tr, item);
      saveDraft();
    });
    setupExcelNavigation(inputs[2], rowIndex, 2);

    // Кнопка "→ В заказ" - копирует расчет в заказ
    calcToOrderBtn.addEventListener('click', () => {
      const calc = calculateItem(item, orderState.settings);
      if (calc.calculatedOrder > 0) {
        item.finalOrder = Math.round(calc.calculatedOrder);
        
        // Обновляем оба поля ввода
        if (orderState.settings.unit === 'pieces') {
          orderPiecesInput.value = item.finalOrder;
          orderBoxesInput.value = item.qtyPerBox ? Math.ceil(item.finalOrder / item.qtyPerBox) : 0;
        } else {
          orderBoxesInput.value = item.finalOrder;
          orderPiecesInput.value = item.finalOrder * (item.qtyPerBox || 1);
        }
        
        updateRow(tr, item);
        updateFinalSummary();
        saveDraft();
        showToast('Добавлено в заказ', '', 'success');
      }
    });

    // Колонка 3 (штуки): Заказ в штуках
    orderPiecesInput.addEventListener('input', e => {
      syncOrderInputs(true);
      updateRow(tr, item);
      saveDraft();
      updateFinalSummary();
    });
    setupExcelNavigation(orderPiecesInput, rowIndex, 3);

    // Колонка 4 (коробки): Заказ в коробках
    orderBoxesInput.addEventListener('input', e => {
      syncOrderInputs(false);
      updateRow(tr, item);
      saveDraft();
      updateFinalSummary();
    });
    setupExcelNavigation(orderBoxesInput, rowIndex, 4);

    roundBtn.addEventListener('click', () => {
      roundToPallet(item);
      // После округления обновляем оба поля
      if (orderState.settings.unit === 'pieces') {
        orderPiecesInput.value = item.finalOrder;
        orderBoxesInput.value = item.qtyPerBox ? Math.ceil(item.finalOrder / item.qtyPerBox) : 0;
      } else {
        orderBoxesInput.value = item.finalOrder;
        orderPiecesInput.value = item.finalOrder * (item.qtyPerBox || 1);
      }
      updateRow(tr, item);
      saveDraft();
    });

    deleteBtn.addEventListener('click', () => {
      removeItem(item.id);
    });

    tbody.appendChild(tr);
    updateRow(tr, item);
  });

  updateFinalSummary();
  toggleTransitColumn();
}

function updateRow(tr, item) {
  const calc = calculateItem(item, orderState.settings);

let calcText = nf.format(Math.round(calc.calculatedOrder));

if (
  orderState.settings.unit === 'pieces' &&
  item.qtyPerBox
) {
  const boxes = calc.calculatedOrder / item.qtyPerBox;
  calcText += ` (${nf.format(Math.ceil(boxes))} кор.)`;
}

const calcValueEl = tr.querySelector('.calc-value');
if (calcValueEl) {
  calcValueEl.textContent = calcText;
}

  // Вычисляем количество дней запаса ПОСЛЕ даты поставки
  const dailyConsumption = orderState.settings.periodDays ? item.consumptionPeriod / orderState.settings.periodDays : 0;
  
  if (dailyConsumption > 0 && orderState.settings.today && orderState.settings.deliveryDate) {
    // Дни до поставки
    const daysUntilDelivery = Math.ceil((orderState.settings.deliveryDate - orderState.settings.today) / 86400000);
    
    // Расход до поставки
    const consumedBeforeDelivery = dailyConsumption * daysUntilDelivery;
    
    // Остаток на момент поставки
    const totalStock = item.stock + (item.transit || 0);
    const stockAtDelivery = Math.max(0, totalStock - consumedBeforeDelivery);
    
    // Запас после поставки
    const availableAfterDelivery = stockAtDelivery + (item.finalOrder || 0);
    
    // Дни запаса после поставки
    const daysOfStockAfterDelivery = Math.floor(availableAfterDelivery / dailyConsumption);
    
    // Дата окончания запаса
    const coverageDate = new Date(orderState.settings.deliveryDate.getTime() + daysOfStockAfterDelivery * 86400000);
    
    tr.querySelector('.date').textContent = 
      `${coverageDate.toLocaleDateString()} (${daysOfStockAfterDelivery} дн.)`;
  } else {
    tr.querySelector('.date').textContent = '-';
  }

  if (item.boxesPerPallet && item.finalOrder > 0) {
    const boxes =
      orderState.settings.unit === 'boxes'
        ? item.finalOrder
        : item.finalOrder / item.qtyPerBox;

    const pallets = Math.floor(boxes / item.boxesPerPallet);
    const boxesLeft = Math.ceil(boxes % item.boxesPerPallet);

    tr.querySelector('.pallet-info').textContent =
  `${nf.format(pallets)} пал. + ${nf.format(boxesLeft)} кор. (${nf.format(item.boxesPerPallet)} кор./пал.)`
  } else {
    tr.querySelector('.pallet-info').textContent = '-';
  }

// ===== ПРОВЕРКА ДЕФИЦИТА ДО ПОСТАВКИ (БЕЗ ЗАКАЗА) =====
const shortageInfo = tr.querySelector('.shortage-info');

if (orderState.settings.deliveryDate && item.consumptionPeriod && dailyConsumption > 0) {
  // Считаем ТОЛЬКО с остатком и транзитом, БЕЗ заказа
  const totalStock = item.stock + (item.transit || 0);
  const daysUntilDelivery = Math.ceil((orderState.settings.deliveryDate - orderState.settings.today) / 86400000);
  const consumedBeforeDelivery = dailyConsumption * daysUntilDelivery;
  
  // Если не хватает до поставки
  if (totalStock < consumedBeforeDelivery) {
    const deficit = consumedBeforeDelivery - totalStock;
    const deficitDays = Math.ceil(deficit / dailyConsumption);
    
    const unit = item.unitOfMeasure || 'шт';
    let deficitText;
    
    if (orderState.settings.unit === 'boxes') {
      // расход и остаток введены в коробках → deficit тоже в коробках
      deficitText = `${Math.ceil(deficit)} кор.`;
    } else if (item.qtyPerBox) {
      // расход и остаток в штуках → deficit в штуках, коробки в скобках
      const deficitBoxes = Math.ceil(deficit / item.qtyPerBox);
      deficitText = `${Math.ceil(deficit)} ${unit} (${deficitBoxes} кор.)`;
    } else {
      deficitText = `${Math.ceil(deficit)} ${unit}`;
    }
    
    shortageInfo.textContent = `⚠️ Не хватит: ${deficitText} | Дефицит: ${deficitDays} дн.`;
    shortageInfo.classList.remove('hidden');
    tr.classList.add('shortage-warning');
  } else {
    shortageInfo.classList.add('hidden');
    tr.classList.remove('shortage-warning');
  }
} else {
  shortageInfo.classList.add('hidden');
  tr.classList.remove('shortage-warning');
}

  updateFinalSummary();
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
    .forEach((tr, i) => updateRow(tr, orderState.items[i]));
}

render();



function initModals() {
  const openHistoryBtn = document.getElementById('openHistory');
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
initSuppliers.then(() => {
  loadDraft();
  updateEntityBadge(); // fallback если черновика нет
});

// Предупреждение перед закрытием страницы
window.addEventListener('beforeunload', (e) => {
  if (orderState.items.length > 0) {
    e.preventDefault();
    e.returnValue = '';
  }
});