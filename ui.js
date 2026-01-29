import { orderState } from './state.js';
import { calculateItem } from './calculations.js';
import { supabase } from './supabase.js';

/* ================= DOM ================= */
const copyOrderBtn = document.getElementById('copyOrder');
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
const saveOrderBtn = document.getElementById('saveOrder');
const historyContainer = document.getElementById('orderHistory');
const historySupplier = document.getElementById('historySupplier');
const historyModal = document.getElementById('historyModal');

const manualModal = document.getElementById('manualModal');
const closeManualBtn = document.getElementById('closeManual');


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
    info: 'ℹ️',
    warning: '⚠️'
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

/* ================= MODAL CONFIRMATIONS ================= */
function showConfirmModal(title, message, onConfirm, onCancel) {
  // Создаём модалку подтверждения
  const modal = document.createElement('div');
  modal.className = 'modal';
  modal.innerHTML = `
    <div class="modal-box confirm-modal">
      <div class="modal-header">
        <h2>${title}</h2>
      </div>
      <div class="confirm-message">${message}</div>
      <div class="actions">
        <button class="btn primary confirm-yes">Да</button>
        <button class="btn secondary confirm-no">Отмена</button>
      </div>
    </div>
  `;

  document.body.appendChild(modal);

  const yesBtn = modal.querySelector('.confirm-yes');
  const noBtn = modal.querySelector('.confirm-no');

  yesBtn.addEventListener('click', () => {
    modal.remove();
    if (onConfirm) onConfirm();
  });

  noBtn.addEventListener('click', () => {
    modal.remove();
    if (onCancel) onCancel();
  });

  // Закрытие по клику вне модалки
  modal.addEventListener('click', (e) => {
    if (e.target === modal) {
      modal.remove();
      if (onCancel) onCancel();
    }
  });
}

/* ================= LOGIN ================= */
loginBtn.addEventListener('click', () => {
  if (loginPassword.value === '157') {
    loginOverlay.classList.add('hidden'); // Используем класс вместо style.display
    sessionStorage.setItem('bk_logged_in', 'true'); // sessionStorage вместо localStorage
    loadOrderHistory();
    showToast('Вход выполнен', 'Добро пожаловать!', 'success');
  } else {
    showToast('Ошибка входа', 'Неверный пароль', 'error');
  }
});

// Проверка сохраненного входа при загрузке - ТОЛЬКО sessionStorage
if (sessionStorage.getItem('bk_logged_in') === 'true') {
  loginOverlay.classList.add('hidden');
  loadOrderHistory();
}


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

  // Красивое модальное подтверждение
  showConfirmModal(
    '💾 Сохранить заказ?',
    'Заказ будет сохранён в историю. Продолжить?',
    async () => {
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
            qtyperbox: item.qtyPerBox || 1  // ИСПРАВЛЕНО: qtyperbox вместо qty_per_box
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
          supplier: document.getElementById('supplierFilter').value || 'Свободный',
          delivery_date: orderState.settings.deliveryDate,
          safety_days: orderState.settings.safetyDays,
          period_days: orderState.settings.periodDays,
          unit: orderState.settings.unit
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
    }
  );
});

async function loadOrderHistory() {
  historyContainer.innerHTML = '<div style="text-align:center;padding:20px;"><div class="loading-spinner"></div><div>Загрузка...</div></div>';

  let query = supabase
    .from('orders')
    .select(`
  id,
  delivery_date,
  supplier,
  order_items (
    sku,
    name,
    qty_boxes
  )
`)
    .order('created_at', { ascending: false });

  if (historySupplier.value) {
    query = query.eq('supplier', historySupplier.value);
  }

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
    
    // Восстановление настроек
    if (data.settings.today) {
      orderState.settings.today = new Date(data.settings.today);
      document.getElementById('today').value = orderState.settings.today.toISOString().slice(0, 10);
    }
    if (data.settings.deliveryDate) {
      orderState.settings.deliveryDate = new Date(data.settings.deliveryDate);
      document.getElementById('deliveryDate').value = orderState.settings.deliveryDate.toISOString().slice(0, 10);
    }
    orderState.settings.periodDays = data.settings.periodDays || 30;
    orderState.settings.safetyDays = data.settings.safetyDays || 0;
    orderState.settings.unit = data.settings.unit || 'pieces';
    
    document.getElementById('periodDays').value = orderState.settings.periodDays;
    document.getElementById('safetyDays').value = orderState.settings.safetyDays;
    document.getElementById('unit').value = orderState.settings.unit;
    
    // Восстановление товаров
    orderState.items = data.items || [];
    
    if (orderState.items.length > 0) {
      orderSection.classList.remove('hidden');
      render();
      
      const draftDate = new Date(data.timestamp).toLocaleString('ru-RU');
      showToast('Черновик загружен', `Восстановлено из ${draftDate}`, 'info');
      return true;
    }
    
  } catch (e) {
    console.error('Ошибка загрузки черновика:', e);
  }
  
  return false;
}

function clearDraft() {
  localStorage.removeItem('bk_draft');
}


function renderOrderHistory(orders) {
  historyContainer.innerHTML = '';

  if (!orders.length) {
    historyContainer.innerHTML = 'История пуста';
    return;
  }

  orders.forEach(order => {
    const div = document.createElement('div');
    div.className = 'history-order';

    const date = new Date(order.delivery_date).toLocaleDateString();

    div.innerHTML = `
      <div class="history-header">
        <span>📦 <b>${order.supplier}</b> — ${date}</span>
        <button class="btn small secondary" data-order-id="${order.id}">Загрузить</button>
      </div>
      <div class="history-items">
        ${order.order_items.map(i => `
          <div>${i.sku ? i.sku + ' ' : ''}${i.name} — ${i.qty_boxes} кор.</div>
        `).join('')}
      </div>
    `;

    div.querySelector('button').addEventListener('click', () => {
      loadOrder(order);
    });

    historyContainer.appendChild(div);
  });

  historySupplier.addEventListener('change', loadOrderHistory);
}

async function loadOrder(order) {
  showConfirmModal(
    '📥 Загрузить заказ из истории?',
    'Текущий заказ будет заменён. Продолжить?',
    async () => {
      const { data, error } = await supabase
        .from('order_items')
        .select('*')
        .eq('order_id', order.id);

      if (error) {
        showToast('Ошибка загрузки', 'Не удалось загрузить заказ', 'error');
        console.error(error);
        return;
      }

      orderState.items = data.map(i => ({
        id: Date.now() + Math.random(),
        sku: i.sku,
        name: i.name,
        consumptionPeriod: 0,
        stock: 0,
        transit: 0,
        qtyPerBox: i.qtyperbox || 1,
        boxesPerPallet: null,
        finalOrder: i.qty_boxes,
        isManual: false
      }));

      document.getElementById('supplierFilter').value = order.supplier || '';
      document.getElementById('deliveryDate').value = order.delivery_date;
      orderState.settings.deliveryDate = new Date(order.delivery_date);

      orderSection.classList.remove('hidden');
      render();
      historyModal.classList.add('hidden');
      
      showToast('Заказ загружен', 'Заказ успешно загружен из истории', 'success');
      saveDraft();
    }
  );
}

/* ================= КОПИРОВАНИЕ ================= */
copyOrderBtn.addEventListener('click', () => {
  const text = orderState.items
    .filter(item => item.finalOrder > 0)
    .map(item => {
      const boxes =
        orderState.settings.unit === 'boxes'
          ? item.finalOrder
          : Math.ceil(item.finalOrder / item.qtyPerBox);

      return `${item.sku}\t${item.name}\t${nf.format(boxes)}`;
    })
    .join('\n');

  if (!text) {
    showToast('Заказ пуст', 'Нет товаров для копирования', 'error');
    return;
  }

  navigator.clipboard.writeText(text).then(() => {
    showToast('Скопировано', 'Заказ скопирован в буфер обмена', 'success');
  });
});

/* ================= SUPPLIERS ================= */
async function loadSuppliers() {
  const { data, error } = await supabase
    .from('suppliers')
    .select('name')
    .order('name');

  if (error) {
    console.error(error);
    return;
  }

  data.forEach(s => {
    const opt = document.createElement('option');
    opt.value = s.name;
    opt.textContent = s.name;
    supplierSelect.appendChild(opt);

    const opt2 = document.createElement('option');
    opt2.value = s.name;
    opt2.textContent = s.name;
    historySupplier.appendChild(opt2);
  });
}

loadSuppliers();

/* ================= MANUAL ITEM ================= */
addManualBtn.addEventListener('click', () => {
  manualModal.classList.remove('hidden');
});

closeManualBtn.addEventListener('click', () => {
  manualModal.classList.add('hidden');
});

manualCancelBtn.addEventListener('click', () => {
  manualModal.classList.add('hidden');
});

manualAddBtn.addEventListener('click', async () => {
  const name = document.getElementById('m_name').value.trim();

  if (!name) {
    showToast('Укажите название', 'Название товара обязательно', 'error');
    return;
  }

  const item = {
    id: Date.now(),
    sku: document.getElementById('m_sku').value.trim() || null,
    name,
    consumptionPeriod: 0,
    stock: 0,
    transit: 0,
    qtyPerBox: +document.getElementById('m_box').value || 1,
    boxesPerPallet: +document.getElementById('m_pallet').value || null,
    finalOrder: 0,
    isManual: true
  };

  const saveToDb = document.getElementById('m_save').checked;

  if (saveToDb) {
    const { error } = await supabase.from('products').insert({
      sku: item.sku,
      name: item.name,
      supplier: document.getElementById('m_supplier').value.trim() || null,
      qty_per_box: item.qtyPerBox,
      boxes_per_pallet: item.boxesPerPallet
    });

    if (error) {
      console.error(error);
      showToast('Ошибка', 'Не удалось сохранить в базу', 'error');
    } else {
      showToast('Сохранено', 'Товар добавлен в базу данных', 'success');
    }
  }

  orderState.items.push(item);
  render();
  saveDraft();

  manualModal.classList.add('hidden');

  // Очистка формы
  document.getElementById('m_name').value = '';
  document.getElementById('m_sku').value = '';
  document.getElementById('m_supplier').value = '';
  document.getElementById('m_box').value = '';
  document.getElementById('m_pallet').value = '';
  document.getElementById('m_save').checked = false;
  
  showToast('Товар добавлен', name, 'success');
});

/* ================= SEARCH ================= */
let searchTimeout;
searchInput.addEventListener('input', async e => {
  clearTimeout(searchTimeout);
  const term = e.target.value.trim();

  if (!term) {
    searchResults.innerHTML = '';
    return;
  }

  searchTimeout = setTimeout(async () => {
    const sup = supplierSelect.value;
    let query = supabase
      .from('products')
      .select('*')
      .or(`sku.ilike.%${term}%,name.ilike.%${term}%`);

    if (sup) {
      query = query.eq('supplier', sup);
    }

    const { data, error } = await query.limit(10);

    if (error) {
      console.error(error);
      return;
    }

    searchResults.innerHTML = data
      .map(
        p =>
          `<div data-product='${JSON.stringify(p)}'>${p.sku} ${p.name}</div>`
      )
      .join('');

    searchResults.querySelectorAll('div').forEach(div => {
      div.addEventListener('click', () => {
        const product = JSON.parse(div.getAttribute('data-product'));
        addProduct(product);
        searchInput.value = '';
        searchResults.innerHTML = '';
      });
    });
  }, 300);
});

function addProduct(product) {
  const exists = orderState.items.find(i => i.sku === product.sku);
  if (exists) {
    showToast('Товар уже в заказе', product.name, 'warning');
    return;
  }

  orderState.items.push({
    id: Date.now(),
    sku: product.sku,
    name: product.name,
    consumptionPeriod: 0,
    stock: 0,
    transit: 0,
    qtyPerBox: product.qty_per_box || 1,
    boxesPerPallet: product.boxes_per_pallet || null,
    finalOrder: 0,
    isManual: false
  });

  render();
  saveDraft();
  showToast('Товар добавлен', product.name, 'success');
}

function removeItem(id) {
  showConfirmModal(
    '🗑️ Удалить товар?',
    'Товар будет удалён из заказа',
    () => {
      orderState.items = orderState.items.filter(i => i.id !== id);
      render();
      saveDraft();
      showToast('Товар удалён', '', 'info');
    }
  );
}

/* ================= SETTINGS ================= */
document.getElementById('today').addEventListener('change', e => {
  orderState.settings.today = e.target.value ? new Date(e.target.value) : null;
  rerenderAll();
  saveDraft();
});

document.getElementById('deliveryDate').addEventListener('change', e => {
  orderState.settings.deliveryDate = e.target.value
    ? new Date(e.target.value)
    : null;
  rerenderAll();
  saveDraft();
});

document.getElementById('periodDays').addEventListener('input', e => {
  orderState.settings.periodDays = +e.target.value || 0;
  rerenderAll();
  saveDraft();
});

document.getElementById('safetyDays').addEventListener('input', e => {
  orderState.settings.safetyDays = +e.target.value || 0;
  rerenderAll();
  saveDraft();
});

document.getElementById('unit').addEventListener('change', e => {
  orderState.settings.unit = e.target.value;
  rerenderAll();
  saveDraft();
});

function validateRequiredSettings() {
  let ok = true;

  const todayInput = document.getElementById('today');
  const deliveryInput = document.getElementById('deliveryDate');
  const safetyInput = document.getElementById('safetyDays');

  if (!todayInput.value) {
    todayInput.classList.add('required');
    ok = false;
  } else {
    todayInput.classList.remove('required');
  }

  if (!deliveryInput.value) {
    deliveryInput.classList.add('required');
    ok = false;
  } else {
    deliveryInput.classList.remove('required');
  }

  if (!safetyInput.value) {
    safetyInput.classList.add('required');
    ok = false;
  } else {
    safetyInput.classList.remove('required');
  }

  return ok;
}

/* ================= NAVIGATION ================= */
function setupExcelNavigation(input, rowIndex, colIndex) {
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === 'ArrowDown') {
      e.preventDefault();
      const nextRow = tbody.querySelectorAll('tr')[rowIndex + 1];
      if (nextRow) {
        const inputs = nextRow.querySelectorAll('input');
        inputs[colIndex]?.focus();
      }
    }

    if (e.key === 'ArrowUp') {
      e.preventDefault();
      const prevRow = tbody.querySelectorAll('tr')[rowIndex - 1];
      if (prevRow) {
        const inputs = prevRow.querySelectorAll('input');
        inputs[colIndex]?.focus();
      }
    }

    if (e.key === 'ArrowRight') {
      e.preventDefault();
      const row = tbody.querySelectorAll('tr')[rowIndex];
      const inputs = row.querySelectorAll('input');
      inputs[colIndex + 1]?.focus();
    }

    if (e.key === 'ArrowLeft') {
      e.preventDefault();
      const row = tbody.querySelectorAll('tr')[rowIndex];
      const inputs = row.querySelectorAll('input');
      inputs[colIndex - 1]?.focus();
    }
  });
}

/* ================= RENDER ================= */
function render() {
  tbody.innerHTML = '';

  orderState.items.forEach((item, rowIndex) => {
    const tr = document.createElement('tr');
    if (item.isManual) tr.classList.add('manual');

    tr.innerHTML = `
      <td class="item-name">
        ${item.sku ? `<b>${item.sku}</b>` : ''}
        ${item.name}
      </td>
      <td><input type="number" value="${item.consumptionPeriod || 0}" min="0" /></td>
      <td><input type="number" value="${item.stock || 0}" min="0" /></td>
      <td><input type="number" value="${item.transit || 0}" min="0" /></td>
      <td class="calc">0</td>
      <td class="order-cell">
        <input class="order-pieces" type="number" value="0" min="0" /> /
        <input class="order-boxes" type="number" value="0" min="0" />
      </td>
      <td class="date">-</td>
      <td class="pallets">
        <div class="pallet-info">-</div>
        <button class="btn small secondary">📦 Округлить</button>
      </td>
      <td class="status">-</td>
      <td>
        <button class="btn small secondary">🗑️</button>
      </td>
    `;

    const inputs = tr.querySelectorAll('input:not(.order-pieces):not(.order-boxes)');
    const orderPiecesInput = tr.querySelector('.order-pieces');
    const orderBoxesInput = tr.querySelector('.order-boxes');
    const roundBtn = tr.querySelector('button');
    const deleteBtn = tr.querySelectorAll('button')[1];

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

    // Колонка 3 (штуки): Заказ в штуках
    orderPiecesInput.addEventListener('input', e => {
      syncOrderInputs(true);
      updateRow(tr, item);
      saveDraft();
    });
    setupExcelNavigation(orderPiecesInput, rowIndex, 3);

    // Колонка 4 (коробки): Заказ в коробках
    orderBoxesInput.addEventListener('input', e => {
      syncOrderInputs(false);
      updateRow(tr, item);
      saveDraft();
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

tr.querySelector('.calc').textContent = calcText;

  // ИСПРАВЛЕНО: Добавляем количество дней в скобках
  const dateCell = tr.querySelector('.date');
  if (calc.coverageDate) {
    const dateStr = calc.coverageDate.toLocaleDateString();
    
    // Вычисляем количество дней запаса
    const today = orderState.settings.today || new Date();
    const available = (item.stock + (item.transit || 0)) + (item.finalOrder || 0);
    const daily = orderState.settings.periodDays 
      ? item.consumptionPeriod / orderState.settings.periodDays 
      : 0;
    const daysOfStock = daily > 0 ? Math.floor(available / daily) : 0;
    
    dateCell.textContent = `${dateStr} (${daysOfStock} дн.)`;
  } else {
    dateCell.textContent = '-';
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
// ===== СТАТУС НАЛИЧИЯ =====
const statusCell = tr.querySelector('.status');

if (!orderState.settings.deliveryDate || !item.consumptionPeriod) {
  statusCell.textContent = '-';
  statusCell.className = 'status';
  return;
}

const daily =
  orderState.settings.periodDays
    ? item.consumptionPeriod / orderState.settings.periodDays
    : 0;

if (!daily || !calc.coverageDate) {
  statusCell.textContent = '-';
  statusCell.className = 'status';
  return;
}

if (calc.coverageDate < orderState.settings.deliveryDate) {
  const deficitDays = Math.ceil(
    (orderState.settings.deliveryDate - calc.coverageDate) / 86400000
  );

  const deficitUnits = deficitDays * daily;

  const deficitText =
    orderState.settings.unit === 'boxes'
      ? `${Math.ceil(deficitUnits)} кор.`
      : `${Math.ceil(deficitUnits)} шт`;

  statusCell.textContent =
    `❌ Не хватает ${deficitDays} дн. (${deficitText})`;

  statusCell.className = 'status status-bad';
} else {
  statusCell.textContent = '✅ Хватает';
  statusCell.className = 'status status-good';
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
  const itemsWithOrder = orderState.items.filter(item => item.finalOrder > 0);
  
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

  return `
  <div>
    <b>${item.sku ? item.sku + ' ' : ''}${item.name}</b>
    — ${nf.format(Math.ceil(boxes))} коробок (${nf.format(Math.round(pieces))} шт)
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

// Загрузка черновика при старте
loadDraft();

// Предупреждение перед закрытием страницы
window.addEventListener('beforeunload', (e) => {
  if (orderState.items.length > 0) {
    e.preventDefault();
    e.returnValue = '';
  }
});