import { supabase } from './supabase.js';
import { orderState } from './state.js';
import { calculateItem } from './calculations.js';

/* ================= DOM ЭЛЕМЕНТЫ ================= */
const loginOverlay = document.getElementById('loginOverlay');
const loginPassword = document.getElementById('loginPassword');
const loginBtn = document.getElementById('loginBtn');
const buildOrderBtn = document.getElementById('buildOrder');
const orderSection = document.getElementById('orderSection');
const legalEntitySelect = document.getElementById('legalEntity');
const supplierFilter = document.getElementById('supplierFilter');
const todayInput = document.getElementById('today');
const deliveryDateInput = document.getElementById('deliveryDate');
const periodDaysInput = document.getElementById('periodDays');
const safetyDaysInput = document.getElementById('safetyDays');
const unitSelect = document.getElementById('unit');
const hasTransitSelect = document.getElementById('hasTransit');
const showStockColumnSelect = document.getElementById('showStockColumn');
const addManualBtn = document.getElementById('addManual');
const clearOrderBtn = document.getElementById('clearOrder');
const copyOrderBtn = document.getElementById('copyOrder');
const saveOrderBtn = document.getElementById('saveOrder');
const searchInput = document.getElementById('productSearch');
const searchResults = document.getElementById('searchResults');
const clearSearchBtn = document.getElementById('clearSearch');
const itemsTable = document.getElementById('items');
const finalSummary = document.getElementById('finalSummary');
const manualModal = document.getElementById('manualModal');
const closeManualBtn = document.getElementById('closeManual');
const historyModal = document.getElementById('historyModal');
const closeHistoryBtn = document.getElementById('closeHistory');
const historySupplierSelect = document.getElementById('historySupplier');
const orderHistory = document.getElementById('orderHistory');
const confirmModal = document.getElementById('confirmModal');
const closeConfirmBtn = document.getElementById('closeConfirm');
const confirmYesBtn = document.getElementById('confirmYes');
const confirmNoBtn = document.getElementById('confirmNo');
const entityBadge = document.getElementById('entityBadge');

// НОВЫЕ ЭЛЕМЕНТЫ v1.0.2 + v1.0.3
const menuHistoryBtn = document.getElementById('menuHistory');
const menuDatabaseBtn = document.getElementById('menuDatabase');
const databaseModal = document.getElementById('databaseModal');
const closeDatabaseBtn = document.getElementById('closeDatabase');
const dbLegalEntitySelect = document.getElementById('dbLegalEntity');
const dbSearchInput = document.getElementById('dbSearch');
const clearDbSearchBtn = document.getElementById('clearDbSearch');
const databaseList = document.getElementById('databaseList');
const editCardModal = document.getElementById('editCardModal');
const closeEditCardBtn = document.getElementById('closeEditCard');
const undoBtn = document.getElementById('undoBtn');
const redoBtn = document.getElementById('redoBtn');
const applyAllCalcBtn = document.getElementById('applyAllCalc');

let currentEditingProduct = null;
let historyStack = [];
let historyIndex = -1;
const MAX_HISTORY = 50;

let searchTimer = null;
let confirmResolve = null;

/* ================= ЛОГИН ================= */
loginBtn.addEventListener('click', () => {
  const pass = loginPassword.value;
  if (pass === 'bk2024') {
    localStorage.setItem('bk_logged_in', 'true');
    loginOverlay.style.display = 'none';
    showToast('Успех', 'Вы вошли в систему', 'success');
  } else {
    loginPassword.classList.add('required');
    setTimeout(() => loginPassword.classList.remove('required'), 300);
    showToast('Ошибка', 'Неверный пароль', 'error');
  }
});

loginPassword.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') loginBtn.click();
});

/* ================= НАСТРОЙКИ ================= */
function validateRequiredSettings() {
  const today = todayInput.value;
  const delivery = deliveryDateInput.value;
  const safety = orderState.settings.safetyDays;

  const fields = [
    { el: todayInput, val: today },
    { el: deliveryDateInput, val: delivery },
    { el: safetyDaysInput, val: safety }
  ];

  let allValid = true;

  fields.forEach(({ el, val }) => {
    if (!val) {
      el.classList.add('required');
      allValid = false;
      setTimeout(() => el.classList.remove('required'), 2000);
    }
  });

  if (!allValid) {
    showToast('Внимание', 'Заполните все обязательные поля', 'error');
  }

  return allValid;
}

buildOrderBtn.addEventListener('click', () => {
  orderState.settings.today = todayInput.value ? new Date(todayInput.value) : null;
  orderState.settings.deliveryDate = deliveryDateInput.value ? new Date(deliveryDateInput.value) : null;
  orderState.settings.periodDays = +periodDaysInput.value || 30;
  orderState.settings.unit = unitSelect.value;
  orderState.settings.supplier = supplierFilter.value;
  orderState.settings.legalEntity = legalEntitySelect.value;

  if (!validateRequiredSettings()) return;

  orderSection.classList.remove('hidden');
  saveDraft();
  render();
  
  showToast('Готово', 'Заказ сформирован', 'success');
});

legalEntitySelect.addEventListener('change', (e) => {
  orderState.settings.legalEntity = e.target.value;
  updateEntityBadge();
  initSuppliers();
  saveDraft();
});

supplierFilter.addEventListener('change', (e) => {
  orderState.settings.supplier = e.target.value;
  saveDraft();
});

unitSelect.addEventListener('change', (e) => {
  orderState.settings.unit = e.target.value;
  saveDraft();
  render();
});

hasTransitSelect.addEventListener('change', (e) => {
  orderState.settings.hasTransit = e.target.value === 'true';
  toggleTransitColumn();
  saveDraft();
});

showStockColumnSelect.addEventListener('change', (e) => {
  orderState.settings.showStockColumn = e.target.value === 'true';
  toggleStockColumn();
  saveDraft();
  render();
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

/* ================= ТОВАРНЫЙ ЗАПАС С ДАТОЙ ================= */
function updateSafetyDaysDisplay() {
  const safetyInput = document.getElementById('safetyDays');
  if (!safetyInput) return;
  
  const days = parseInt(safetyInput.value) || 0;
  orderState.settings.safetyDays = days;
  
  if (days > 0 && orderState.settings.today) {
    const endDate = new Date(orderState.settings.today);
    endDate.setDate(endDate.getDate() + days);
    const dateStr = endDate.toLocaleDateString('ru-RU', {day:'2-digit', month:'2-digit', year:'2-digit'});
    safetyInput.value = `${days} / до ${dateStr}`;
  } else {
    safetyInput.value = days || '';
  }
}

safetyDaysInput.addEventListener('input', (e) => {
  const val = e.target.value.replace(/[^\d]/g, '');
  orderState.settings.safetyDays = parseInt(val) || 0;
});

safetyDaysInput.addEventListener('focus', (e) => {
  e.target.value = orderState.settings.safetyDays || '';
});

safetyDaysInput.addEventListener('blur', () => {
  updateSafetyDaysDisplay();
  saveDraft();
});

todayInput.addEventListener('change', () => {
  orderState.settings.today = todayInput.value ? new Date(todayInput.value) : null;
  updateSafetyDaysDisplay();
  saveDraft();
});

deliveryDateInput.addEventListener('change', () => {
  orderState.settings.deliveryDate = deliveryDateInput.value ? new Date(deliveryDateInput.value) : null;
  saveDraft();
});

periodDaysInput.addEventListener('change', () => {
  orderState.settings.periodDays = +periodDaysInput.value || 30;
  saveDraft();
});

/* ================= ENTITY BADGE ================= */
function updateEntityBadge() {
  if (entityBadge) {
    entityBadge.textContent = orderState.settings.legalEntity;
  }
}

/* ================= DRAFT (ЧЕРНОВИК) ================= */
function saveDraft() {
  const draft = {
    settings: {
      legalEntity: orderState.settings.legalEntity,
      supplier: orderState.settings.supplier,
      today: orderState.settings.today?.toISOString(),
      deliveryDate: orderState.settings.deliveryDate?.toISOString(),
      periodDays: orderState.settings.periodDays,
      safetyDays: orderState.settings.safetyDays,
      unit: orderState.settings.unit,
      hasTransit: orderState.settings.hasTransit,
      showStockColumn: orderState.settings.showStockColumn
    },
    items: orderState.items
  };
  
  localStorage.setItem('bk_draft', JSON.stringify(draft));
}

function loadDraft() {
  const saved = localStorage.getItem('bk_draft');
  if (!saved) return;
  
  try {
    const data = JSON.parse(saved);
    
    orderState.settings.legalEntity = data.settings.legalEntity || 'Бургер БК';
    orderState.settings.supplier = data.settings.supplier || '';
    orderState.settings.today = data.settings.today ? new Date(data.settings.today) : null;
    orderState.settings.deliveryDate = data.settings.deliveryDate ? new Date(data.settings.deliveryDate) : null;
    orderState.settings.periodDays = data.settings.periodDays || 30;
    orderState.settings.safetyDays = data.settings.safetyDays || 0;
    orderState.settings.unit = data.settings.unit || 'pieces';
    orderState.settings.hasTransit = data.settings.hasTransit || false;
    orderState.settings.showStockColumn = data.settings.showStockColumn || false;
    
    legalEntitySelect.value = orderState.settings.legalEntity;
    supplierFilter.value = orderState.settings.supplier;
    todayInput.value = orderState.settings.today ? orderState.settings.today.toISOString().split('T')[0] : '';
    deliveryDateInput.value = orderState.settings.deliveryDate ? orderState.settings.deliveryDate.toISOString().split('T')[0] : '';
    periodDaysInput.value = orderState.settings.periodDays;
    updateSafetyDaysDisplay();
    unitSelect.value = orderState.settings.unit;
    hasTransitSelect.value = orderState.settings.hasTransit ? 'true' : 'false';
    showStockColumnSelect.value = orderState.settings.showStockColumn ? 'true' : 'false';
    
    orderState.items = data.items || [];
    
    if (orderState.items.length > 0) {
      orderSection.classList.remove('hidden');
      render();
    }
    
    updateEntityBadge();
    
  } catch (err) {
    console.error('Ошибка загрузки черновика:', err);
  }
}
/* ================= ПОСТАВЩИКИ ================= */
async function initSuppliers() {
  const { data, error } = await supabase
    .from('products')
    .select('supplier')
    .not('supplier', 'is', null);

  if (error) {
    console.error('Ошибка загрузки поставщиков:', error);
    return;
  }

  const unique = [...new Set(data.map(p => p.supplier))].filter(Boolean);
  unique.sort((a, b) => a.localeCompare(b, 'ru')); // СОРТИРОВКА ПО АЛФАВИТУ

  [supplierFilter, historySupplierSelect].forEach(select => {
    const current = select.value;
    select.innerHTML = '<option value="">Все / свободный</option>';
    unique.forEach(s => {
      const opt = document.createElement('option');
      opt.value = s;
      opt.textContent = s;
      select.appendChild(opt);
    });
    if (unique.includes(current)) select.value = current;
  });
}

/* ================= ПОИСК ТОВАРОВ ================= */
searchInput.addEventListener('input', () => {
  const q = searchInput.value.trim();
  clearTimeout(searchTimer);

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

if (clearSearchBtn) {
  clearSearchBtn.addEventListener('click', () => {
    searchInput.value = '';
    searchResults.innerHTML = '';
    clearSearchBtn.classList.add('hidden');
    searchInput.focus();
  });
}

async function searchProducts(q) {
  const legalEntity = orderState.settings.legalEntity;
  const supplier = orderState.settings.supplier;
  const isSku = /^[0-9A-Za-z-]+$/.test(q);

  let query = supabase
    .from('products')
    .select('*')
    .limit(10);

  if (legalEntity === 'Пицца Стар') {
    query = query.eq('legal_entity', 'Пицца Стар');
  } else {
    query = query.in('legal_entity', ['Бургер БК', 'Воглия Матта']);
  }

  if (supplier) {
    query = query.eq('supplier', supplier);
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
    searchResults.innerHTML = '<div style="color:#999;padding:12px;">Ничего не найдено</div>';
    return;
  }

  data.forEach(p => {
    const div = document.createElement('div');
    div.textContent = `${p.sku || ''} ${p.name}`;
    div.addEventListener('click', () => {
      addItem(p);
      searchResults.innerHTML = '';
      searchInput.value = '';
      if (clearSearchBtn) clearSearchBtn.classList.add('hidden');
    });
    searchResults.appendChild(div);
  });
}

function addItem(p) {
  const exists = orderState.items.find(item => 
    (item.supabaseId && item.supabaseId === p.id) || 
    (item.sku && item.sku === p.sku)
  );
  
  if (exists) {
    showToast('Внимание', 'Товар уже добавлен в заказ', 'info');
    return;
  }

  saveHistory();
  
  orderState.items.push({
    id: crypto.randomUUID(),
    supabaseId: p.id, // ID из Supabase для редактирования
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
  showToast('Добавлено', p.name, 'success');
}

/* ================= РУЧНОЙ ТОВАР ================= */
addManualBtn.addEventListener('click', () => {
  manualModal.classList.remove('hidden');
  document.getElementById('m_name').value = '';
  document.getElementById('m_sku').value = '';
  document.getElementById('m_supplier').value = '';
  document.getElementById('m_legalEntity').value = orderState.settings.legalEntity;
  document.getElementById('m_box').value = '';
  document.getElementById('m_pallet').value = '';
  document.getElementById('m_unit').value = 'шт';
  document.getElementById('m_save').checked = false;
  document.getElementById('m_name').focus();
});

closeManualBtn.addEventListener('click', () => {
  manualModal.classList.add('hidden');
});

document.getElementById('m_cancel').addEventListener('click', () => {
  manualModal.classList.add('hidden');
});

document.getElementById('m_add').addEventListener('click', async () => {
  const name = document.getElementById('m_name').value.trim();
  if (!name) {
    document.getElementById('m_name').classList.add('required');
    setTimeout(() => document.getElementById('m_name').classList.remove('required'), 300);
    return;
  }

  const product = {
    id: null,
    sku: document.getElementById('m_sku').value || null,
    name: name,
    supplier: document.getElementById('m_supplier').value || null,
    legal_entity: document.getElementById('m_legalEntity').value,
    qty_per_box: +document.getElementById('m_box').value || null,
    boxes_per_pallet: +document.getElementById('m_pallet').value || null,
    unit_of_measure: document.getElementById('m_unit').value
  };

  const shouldSave = document.getElementById('m_save').checked;

  if (shouldSave) {
    const { data, error } = await supabase
      .from('products')
      .insert([product])
      .select()
      .single();

    if (error) {
      console.error('Ошибка сохранения:', error);
      showToast('Ошибка', 'Не удалось сохранить в базу', 'error');
      return;
    }

    product.id = data.id;
    showToast('Успех', 'Товар сохранён в базу и добавлен в заказ', 'success');
  } else {
    product.id = crypto.randomUUID();
    showToast('Добавлено', 'Товар добавлен только в текущий заказ', 'info');
  }

  addItem(product);
  manualModal.classList.add('hidden');
});

/* ================= УДАЛЕНИЕ ТОВАРА ================= */
async function removeItem(itemId) {
  const confirmed = await customConfirm('Удалить товар?', 'Товар будет удалён из текущего заказа');
  if (confirmed) {
    saveHistory();
    orderState.items = orderState.items.filter(item => item.id !== itemId);
    render();
    saveDraft();
    showToast('Удалено', 'Товар удалён из заказа', 'success');
  }
}

/* ================= ПЕРЕСТАНОВКА СТРОК ================= */
function swapItems(fromIndex, toIndex) {
  saveHistory();
  const items = orderState.items;
  const [movedItem] = items.splice(fromIndex, 1);
  items.splice(toIndex, 0, movedItem);
  render();
  saveDraft();
  saveItemOrder();
}

function saveItemOrder() {
  const order = orderState.items.map(item => item.supabaseId || item.id);
  localStorage.setItem('bk_item_order_' + orderState.settings.supplier, JSON.stringify(order));
}

function restoreItemOrder() {
  const saved = localStorage.getItem('bk_item_order_' + orderState.settings.supplier);
  if (!saved) return;
  
  try {
    const order = JSON.parse(saved);
    const sorted = [];
    
    order.forEach(id => {
      const item = orderState.items.find(i => (i.supabaseId || i.id) === id);
      if (item) sorted.push(item);
    });
    
    orderState.items.forEach(item => {
      if (!sorted.includes(item)) sorted.push(item);
    });
    
    orderState.items = sorted;
  } catch (err) {
    console.error('Ошибка восстановления порядка:', err);
  }
}

/* ================= UNDO/REDO ================= */
function saveHistory() {
  if (historyIndex < historyStack.length - 1) {
    historyStack = historyStack.slice(0, historyIndex + 1);
  }
  
  const state = JSON.parse(JSON.stringify(orderState.items));
  historyStack.push(state);
  
  if (historyStack.length > MAX_HISTORY) {
    historyStack.shift();
  } else {
    historyIndex++;
  }
  
  updateUndoRedoButtons();
}

function undo() {
  if (historyIndex > 0) {
    historyIndex--;
    orderState.items = JSON.parse(JSON.stringify(historyStack[historyIndex]));
    render();
    saveDraft();
    updateUndoRedoButtons();
  }
}

function redo() {
  if (historyIndex < historyStack.length - 1) {
    historyIndex++;
    orderState.items = JSON.parse(JSON.stringify(historyStack[historyIndex]));
    render();
    saveDraft();
    updateUndoRedoButtons();
  }
}

function updateUndoRedoButtons() {
  undoBtn.disabled = historyIndex <= 0;
  redoBtn.disabled = historyIndex >= historyStack.length - 1;
}

undoBtn.addEventListener('click', undo);
redoBtn.addEventListener('click', redo);

document.addEventListener('keydown', (e) => {
  if (e.ctrlKey || e.metaKey) {
    if (e.key === 'z' && !undoBtn.disabled) {
      e.preventDefault();
      undo();
    } else if (e.key === 'y' && !redoBtn.disabled) {
      e.preventDefault();
      redo();
    }
  }
});

/* ================= ВСЁ В ЗАКАЗ ================= */
applyAllCalcBtn.addEventListener('click', () => {
  saveHistory();
  orderState.items.forEach(item => {
    const calc = calculateItem(item, orderState.settings);
    const calcOrder = calc.calculatedOrder || 0;
    item.finalOrder = calcOrder;
  });
  render();
  saveDraft();
  showToast('Успех', 'Все расчёты перенесены в заказ', 'success');
});

/* ================= ОЧИСТИТЬ ЗАКАЗ ================= */
clearOrderBtn.addEventListener('click', async () => {
  const confirmed = await customConfirm('Очистить заказ?', 'Все товары будут удалены из текущего заказа');
  if (confirmed) {
    saveHistory();
    orderState.items = [];
    render();
    saveDraft();
    showToast('Очищено', 'Заказ очищен', 'info');
  }
});
/* ================= RENDER ================= */
const nf = new Intl.NumberFormat('ru-RU');

function render() {
  const tbody = itemsTable;
  tbody.innerHTML = '';

  if (!orderState.items.length) {
    tbody.innerHTML = '<tr><td colspan="11" style="text-align:center;padding:40px;color:#999;">Добавьте товары для формирования заказа</td></tr>';
    updateFinalSummary();
    return;
  }

  restoreItemOrder();

  orderState.items.forEach((item, rowIndex) => {
    const tr = document.createElement('tr');
    tr.dataset.rowIndex = rowIndex;

    tr.innerHTML = `
      <td style="padding:4px;text-align:center;">
        <span class="drag-handle" draggable="true">⋮⋮</span>
      </td>
      <td class="item-name">
        ${item.sku ? `<b>${item.sku}</b> ` : ''}${item.name}
        <div class="shortage-info hidden"></div>
      </td>
      <td><input type="number" value="${item.consumptionPeriod}"></td>
      <td><input type="number" value="${item.stock}"></td>
      <td class="transit-col"><input type="number" value="${item.transit || 0}"></td>
      <td class="stock-col stock-display">-</td>
      <td class="calc">
        <div class="calc-value">0</div>
        <button class="btn small calc-to-order" style="margin-top:4px;font-size:11px;padding:4px 8px;">→ В заказ</button>
      </td>
      <td class="order-cell order-highlight">
        <input type="number" class="order-pieces" value="0" style="width:70px;"> / 
        <input type="number" class="order-boxes" value="0" style="width:70px;">
      </td>
      <td class="date">-</td>
      <td class="pallets">
        <div class="pallet-info">-</div>
        <button class="btn small round-to-pallet">Округлить</button>
      </td>
      <td class="delete-cell"><button class="delete-item-x" title="Удалить">✖</button></td>
    `;

    const inputs = tr.querySelectorAll('input[type="number"]');
    
    inputs[0].addEventListener('input', e => {
      item.consumptionPeriod = +e.target.value || 0;
      updateRow(tr, item);
      saveDraft();
    });
    
    inputs[1].addEventListener('input', e => {
      item.stock = +e.target.value || 0;
      updateRow(tr, item);
      saveDraft();
    });
    
    inputs[2].addEventListener('input', e => {
      item.transit = +e.target.value || 0;
      updateRow(tr, item);
      saveDraft();
    });

    [inputs[0], inputs[1], inputs[2]].forEach(input => {
      input.addEventListener('focus', (e) => {
        if (e.target.value === '0') e.target.select();
      });
    });

    const orderPiecesInput = tr.querySelector('.order-pieces');
    const orderBoxesInput = tr.querySelector('.order-boxes');

    [orderPiecesInput, orderBoxesInput].forEach(input => {
      input.addEventListener('focus', (e) => {
        if (e.target.value === '0') e.target.select();
      });
    });

    function syncOrderInputs() {
      const pieces = +orderPiecesInput.value || 0;
      const boxes = +orderBoxesInput.value || 0;
      const qtyPerBox = item.qtyPerBox || 1;

      if (document.activeElement === orderPiecesInput) {
        orderBoxesInput.value = Math.ceil(pieces / qtyPerBox);
        item.finalOrder = pieces;
      } else if (document.activeElement === orderBoxesInput) {
        orderPiecesInput.value = boxes * qtyPerBox;
        item.finalOrder = boxes * qtyPerBox;
      }

      updateRow(tr, item);
      saveDraft();
    }

    orderPiecesInput.addEventListener('input', syncOrderInputs);
    orderBoxesInput.addEventListener('input', syncOrderInputs);

    const calcToOrderBtn = tr.querySelector('.calc-to-order');
    calcToOrderBtn.addEventListener('click', () => {
      const calc = calculateItem(item, orderState.settings);
      const calcOrder = calc.calculatedOrder || 0;
      
      orderPiecesInput.value = calcOrder;
      orderBoxesInput.value = Math.ceil(calcOrder / (item.qtyPerBox || 1));
      item.finalOrder = calcOrder;
      
      updateRow(tr, item);
      saveDraft();
    });

    const roundBtn = tr.querySelector('.round-to-pallet');
    roundBtn.addEventListener('click', () => {
      if (!item.boxesPerPallet) {
        showToast('Ошибка', 'Не указано кол-во коробок на паллете', 'error');
        return;
      }

      const boxes = Math.ceil(item.finalOrder / (item.qtyPerBox || 1));
      const pallets = Math.ceil(boxes / item.boxesPerPallet);
      const totalBoxes = pallets * item.boxesPerPallet;
      const totalPieces = totalBoxes * (item.qtyPerBox || 1);

      orderPiecesInput.value = totalPieces;
      orderBoxesInput.value = totalBoxes;
      item.finalOrder = totalPieces;

      updateRow(tr, item);
      saveDraft();
    });

    const deleteBtn = tr.querySelector('.delete-item-x');
    deleteBtn.addEventListener('click', () => {
      removeItem(item.id);
    });

    // ===== DRAG HANDLE =====
    const dragHandle = tr.querySelector('.drag-handle');
    let draggedIndex = null;

    dragHandle.addEventListener('dragstart', (e) => {
      draggedIndex = rowIndex;
      tr.style.opacity = '0.4';
      e.dataTransfer.effectAllowed = 'move';
    });

    dragHandle.addEventListener('dragend', () => {
      tr.style.opacity = '1';
      draggedIndex = null;
    });

    tr.addEventListener('dragover', (e) => {
      e.preventDefault();
      if (draggedIndex !== null && draggedIndex !== rowIndex) {
        tr.style.background = 'rgba(245,166,35,0.15)';
      }
    });

    tr.addEventListener('dragleave', () => {
      tr.style.background = '';
    });

    tr.addEventListener('drop', (e) => {
      e.preventDefault();
      tr.style.background = '';
      if (draggedIndex !== null && draggedIndex !== rowIndex) {
        swapItems(draggedIndex, rowIndex);
      }
    });

    // ===== ДВОЙНОЙ КЛИК ДЛЯ РЕДАКТИРОВАНИЯ =====
    const itemNameCell = tr.querySelector('.item-name');
    if (itemNameCell && item.supabaseId) {
      itemNameCell.style.cursor = 'pointer';
      itemNameCell.addEventListener('dblclick', async () => {
        await openEditCard(item.supabaseId);
      });
    }

    // ===== КАЛЬКУЛЯТОР В ПОЛЯХ =====
    const numberInputs = tr.querySelectorAll('input[type="number"]');
    numberInputs.forEach(input => {
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          const val = input.value.trim();
          if (/^[\d.]+[\+\-\*\/][\d.]+$/.test(val)) {
            try {
              const result = eval(val);
              input.value = Math.round(result);
              input.dispatchEvent(new Event('input', {bubbles: true}));
            } catch (err) {
              console.error('Ошибка калькулятора:', err);
            }
          }
        }
      });
    });

    tbody.appendChild(tr);
    updateRow(tr, item);
  });

  updateFinalSummary();
  toggleTransitColumn();
  toggleStockColumn();
}

function updateRow(tr, item) {
  const calc = calculateItem(item, orderState.settings);
  
  let calcText = nf.format(Math.round(calc.calculatedOrder));
  const calcValueEl = tr.querySelector('.calc-value');
  
  if (calcValueEl) {
    calcValueEl.textContent = calcText;
  }

  const dailyConsumption = orderState.settings.periodDays ? item.consumptionPeriod / orderState.settings.periodDays : 0;
  
  if (dailyConsumption > 0 && orderState.settings.today && orderState.settings.deliveryDate) {
    const daysUntilDelivery = Math.ceil((orderState.settings.deliveryDate - orderState.settings.today) / 86400000);
    const consumedBeforeDelivery = dailyConsumption * daysUntilDelivery;
    const totalStock = item.stock + (item.transit || 0);
    const stockAtDelivery = Math.max(0, totalStock - consumedBeforeDelivery);
    const availableAfterDelivery = stockAtDelivery + (item.finalOrder || 0);
    const daysOfStockAfterDelivery = Math.floor(availableAfterDelivery / dailyConsumption);
    const coverageDate = new Date(orderState.settings.deliveryDate.getTime() + daysOfStockAfterDelivery * 86400000);
    
    tr.querySelector('.date').textContent = 
      `${coverageDate.toLocaleDateString()} (${daysOfStockAfterDelivery} дн.)`;
  } else {
    tr.querySelector('.date').textContent = '-';
  }

  // ===== КОЛОНКА "ЗАПАС" =====
  const stockDisplay = tr.querySelector('.stock-display');
  if (stockDisplay && dailyConsumption > 0 && orderState.settings.today) {
    const totalStock = item.stock + (item.transit || 0);
    const daysOfCurrentStock = Math.floor(totalStock / dailyConsumption);
    const stockEndDate = new Date(orderState.settings.today.getTime() + daysOfCurrentStock * 86400000);
    stockDisplay.textContent = `${stockEndDate.toLocaleDateString()} (${daysOfCurrentStock} дн.)`;
  } else if (stockDisplay) {
    stockDisplay.textContent = '-';
  }

  if (item.boxesPerPallet && item.finalOrder > 0) {
    const boxes = orderState.settings.unit === 'boxes' 
      ? item.finalOrder 
      : item.finalOrder / (item.qtyPerBox || 1);

    const pallets = Math.floor(boxes / item.boxesPerPallet);
    const boxesLeft = Math.ceil(boxes % item.boxesPerPallet);

    const palletInfo = tr.querySelector('.pallet-info');
    if (palletInfo) {
      palletInfo.textContent = pallets > 0 
        ? `${pallets} палл. + ${boxesLeft} кор.` 
        : `${boxesLeft} кор.`;
    }
  } else {
    const palletInfo = tr.querySelector('.pallet-info');
    if (palletInfo) palletInfo.textContent = '-';
  }

  // НЕХВАТКА
  const totalNeed = calc.calculatedOrder || 0;
  const totalAvailable = (item.stock || 0) + (item.transit || 0) + (item.finalOrder || 0);
  const shortage = totalNeed - totalAvailable;

  if (shortage > 10) {
    tr.classList.add('shortage-warning');
    const shortageInfo = tr.querySelector('.shortage-info');
    if (shortageInfo) {
      let unit = orderState.settings.unit;
      let text = '';
      
      if (unit === 'boxes') {
        text = `Не хватит ${Math.round(shortage)} кор.`;
      } else if (unit === 'pieces') {
        const boxes = item.qtyPerBox ? Math.ceil(shortage / item.qtyPerBox) : 0;
        text = boxes > 0 
          ? `Не хватит ${Math.round(shortage)} шт (≈${boxes} кор.)` 
          : `Не хватит ${Math.round(shortage)} шт`;
      } else {
        text = `Не хватит ${Math.round(shortage)}`;
      }
      
      shortageInfo.textContent = text;
      shortageInfo.classList.remove('hidden');
    }
  } else {
    tr.classList.remove('shortage-warning');
    const shortageInfo = tr.querySelector('.shortage-info');
    if (shortageInfo) shortageInfo.classList.add('hidden');
  }
}

/* ================= ИТОГ ================= */
function updateFinalSummary() {
  if (!orderState.items.length) {
    finalSummary.innerHTML = '<div style="color:#999;">Нет товаров в заказе</div>';
    return;
  }

  const totalPieces = orderState.items.reduce((sum, item) => sum + (item.finalOrder || 0), 0);
  const totalBoxes = orderState.items.reduce((sum, item) => {
    return sum + Math.ceil((item.finalOrder || 0) / (item.qtyPerBox || 1));
  }, 0);

  finalSummary.innerHTML = `
    <div><strong>Товаров:</strong> ${orderState.items.length} наим.</div>
    <div><strong>Штук:</strong> ${nf.format(totalPieces)}</div>
    <div><strong>Коробок:</strong> ${nf.format(totalBoxes)}</div>
  `;
}

/* ================= КОПИРОВАТЬ ЗАКАЗ ================= */
copyOrderBtn.addEventListener('click', () => {
  if (!orderState.items.length) {
    showToast('Ошибка', 'Нет товаров для копирования', 'error');
    return;
  }

  let text = `ЗАКАЗ от ${new Date().toLocaleDateString()}\n`;
  text += `Юр. лицо: ${orderState.settings.legalEntity}\n`;
  text += `Поставщик: ${orderState.settings.supplier || 'Все'}\n\n`;

  orderState.items.forEach((item, i) => {
    const boxes = Math.ceil((item.finalOrder || 0) / (item.qtyPerBox || 1));
    text += `${i+1}. ${item.sku || ''} ${item.name}\n`;
    text += `   Заказ: ${item.finalOrder} шт / ${boxes} кор\n`;
  });

  navigator.clipboard.writeText(text).then(() => {
    showToast('Скопировано', 'Заказ скопирован в буфер обмена', 'success');
  }).catch(() => {
    showToast('Ошибка', 'Не удалось скопировать', 'error');
  });
});
/* ================= БАЗА ДАННЫХ ================= */
menuDatabaseBtn.addEventListener('click', () => {
  databaseModal.classList.remove('hidden');
  dbLegalEntitySelect.value = orderState.settings.legalEntity;
  loadDatabaseProducts();
});

closeDatabaseBtn.addEventListener('click', () => {
  databaseModal.classList.add('hidden');
  dbSearchInput.value = '';
  if (clearDbSearchBtn) clearDbSearchBtn.classList.add('hidden');
});

dbLegalEntitySelect.addEventListener('change', () => {
  loadDatabaseProducts();
});

async function loadDatabaseProducts() {
  databaseList.innerHTML = '<div style="text-align:center;padding:20px;"><div class="loading-spinner"></div><div>Загрузка...</div></div>';
  
  const legalEntity = dbLegalEntitySelect.value;
  
  let query = supabase
    .from('products')
    .select('*')
    .order('name');
  
  if (legalEntity === 'Пицца Стар') {
    query = query.eq('legal_entity', 'Пицца Стар');
  } else {
    query = query.in('legal_entity', ['Бургер БК', 'Воглия Матта']);
  }
  
  const { data, error } = await query;
  
  if (error) {
    databaseList.innerHTML = '<div style="text-align:center;color:var(--error);">Ошибка загрузки</div>';
    console.error(error);
    return;
  }
  
  renderDatabaseList(data);
}

function renderDatabaseList(products) {
  if (!products.length) {
    databaseList.innerHTML = '<div style="text-align:center;padding:20px;color:var(--muted);">Карточки не найдены</div>';
    return;
  }
  
  databaseList.innerHTML = products.map(p => `
    <div class="db-card" data-product-id="${p.id}">
      <div class="db-card-info">
        <div class="db-card-sku">${p.sku || '—'}</div>
        <div class="db-card-name">${p.name}</div>
        <div class="db-card-supplier">${p.supplier || 'Без поставщика'}</div>
      </div>
      <div class="db-card-actions">
        <button class="btn small edit-card-btn" data-id="${p.id}">✏️</button>
        <button class="btn small delete-card-btn" data-id="${p.id}" style="background:var(--error);color:white;">🗑️</button>
      </div>
    </div>
  `).join('');
  
  document.querySelectorAll('.edit-card-btn').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      const id = e.target.dataset.id;
      await openEditCard(id);
    });
  });
  
  document.querySelectorAll('.delete-card-btn').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      const id = e.target.dataset.id;
      await deleteCard(id);
    });
  });
}

if (dbSearchInput) {
  dbSearchInput.addEventListener('input', () => {
    const q = dbSearchInput.value.trim().toLowerCase();
    
    if (clearDbSearchBtn) {
      clearDbSearchBtn.classList.toggle('hidden', q.length === 0);
    }
    
    const cards = databaseList.querySelectorAll('.db-card');
    let visibleCount = 0;
    
    cards.forEach(card => {
      const sku = card.querySelector('.db-card-sku')?.textContent.toLowerCase() || '';
      const name = card.querySelector('.db-card-name')?.textContent.toLowerCase() || '';
      const supplier = card.querySelector('.db-card-supplier')?.textContent.toLowerCase() || '';
      
      if (sku.includes(q) || name.includes(q) || supplier.includes(q)) {
        card.style.display = 'flex';
        visibleCount++;
      } else {
        card.style.display = 'none';
      }
    });
    
    let noResultsMsg = databaseList.querySelector('.no-results-message');
    if (visibleCount === 0 && q.length > 0) {
      if (!noResultsMsg) {
        noResultsMsg = document.createElement('div');
        noResultsMsg.className = 'no-results-message';
        noResultsMsg.style.cssText = 'text-align:center;padding:40px;color:var(--muted);';
        noResultsMsg.textContent = 'Ничего не найдено';
        databaseList.appendChild(noResultsMsg);
      }
      noResultsMsg.style.display = 'block';
    } else if (noResultsMsg) {
      noResultsMsg.style.display = 'none';
    }
  });
}

if (clearDbSearchBtn) {
  clearDbSearchBtn.addEventListener('click', () => {
    dbSearchInput.value = '';
    clearDbSearchBtn.classList.add('hidden');
    
    const cards = databaseList.querySelectorAll('.db-card');
    cards.forEach(card => {
      card.style.display = 'flex';
    });
    
    const noResultsMsg = databaseList.querySelector('.no-results-message');
    if (noResultsMsg) noResultsMsg.style.display = 'none';
    
    dbSearchInput.focus();
  });
}

/* ================= РЕДАКТИРОВАНИЕ КАРТОЧКИ ================= */
async function openEditCard(productId) {
  const { data, error } = await supabase
    .from('products')
    .select('*')
    .eq('id', productId)
    .single();
  
  if (error || !data) {
    showToast('Ошибка', 'Не удалось загрузить карточку', 'error');
    return;
  }
  
  currentEditingProduct = data;
  
  document.getElementById('e_name').value = data.name || '';
  document.getElementById('e_sku').value = data.sku || '';
  document.getElementById('e_supplier').value = data.supplier || '';
  document.getElementById('e_legalEntity').value = data.legal_entity || 'Бургер БК';
  document.getElementById('e_box').value = data.qty_per_box || '';
  document.getElementById('e_pallet').value = data.boxes_per_pallet || '';
  document.getElementById('e_unit').value = data.unit_of_measure || 'шт';
  
  editCardModal.classList.remove('hidden');
  document.getElementById('e_name').focus();
}

closeEditCardBtn.addEventListener('click', () => {
  editCardModal.classList.add('hidden');
  currentEditingProduct = null;
});

document.getElementById('e_cancel').addEventListener('click', () => {
  editCardModal.classList.add('hidden');
  currentEditingProduct = null;
});

document.getElementById('e_save').addEventListener('click', async () => {
  if (!currentEditingProduct) return;
  
  const name = document.getElementById('e_name').value.trim();
  if (!name) {
    showToast('Ошибка', 'Наименование обязательно', 'error');
    return;
  }
  
  const updated = {
    name,
    sku: document.getElementById('e_sku').value || null,
    supplier: document.getElementById('e_supplier').value || null,
    legal_entity: document.getElementById('e_legalEntity').value,
    qty_per_box: +document.getElementById('e_box').value || null,
    boxes_per_pallet: +document.getElementById('e_pallet').value || null,
    unit_of_measure: document.getElementById('e_unit').value || 'шт'
  };
  
  const { error } = await supabase
    .from('products')
    .update(updated)
    .eq('id', currentEditingProduct.id);
  
  if (error) {
    showToast('Ошибка', 'Не удалось сохранить изменения', 'error');
    console.error(error);
    return;
  }
  
  showToast('Сохранено', 'Карточка обновлена', 'success');
  
  const itemInOrder = orderState.items.find(item => item.supabaseId === currentEditingProduct.id);
  if (itemInOrder) {
    itemInOrder.name = updated.name;
    itemInOrder.sku = updated.sku;
    itemInOrder.qtyPerBox = updated.qty_per_box;
    itemInOrder.boxesPerPallet = updated.boxes_per_pallet;
    itemInOrder.unitOfMeasure = updated.unit_of_measure;
    render();
    saveDraft();
  }
  
  editCardModal.classList.add('hidden');
  currentEditingProduct = null;
  loadDatabaseProducts();
});

async function deleteCard(productId) {
  const confirmed = await customConfirm('Удалить карточку?', 'Карточка будет удалена из базы данных. Если она есть в заказе, тоже будет удалена.');
  if (!confirmed) return;
  
  const { error } = await supabase
    .from('products')
    .delete()
    .eq('id', productId);
  
  if (error) {
    showToast('Ошибка', 'Не удалось удалить карточку', 'error');
    console.error(error);
    return;
  }
  
  showToast('Удалено', 'Карточка удалена из базы', 'success');
  
  const itemIndex = orderState.items.findIndex(item => item.supabaseId === productId);
  if (itemIndex !== -1) {
    orderState.items.splice(itemIndex, 1);
    render();
    saveDraft();
  }
  
  loadDatabaseProducts();
}

/* ================= ИСТОРИЯ ЗАКАЗОВ ================= */
menuHistoryBtn.addEventListener('click', () => {
  historyModal.classList.remove('hidden');
  loadOrderHistory();
});

closeHistoryBtn.addEventListener('click', () => {
  historyModal.classList.add('hidden');
});

historySupplierSelect.addEventListener('change', loadOrderHistory);

async function loadOrderHistory() {
  orderHistory.innerHTML = '<div class="loading-spinner"></div>';
  
  let query = supabase
    .from('orders')
    .select('*')
    .order('created_at', { ascending: false })
    .limit(20);
  
  const supplier = historySupplierSelect.value;
  if (supplier) {
    query = query.eq('supplier', supplier);
  }
  
  const legalEntity = orderState.settings.legalEntity;
  if (legalEntity === 'Пицца Стар') {
    query = query.eq('legal_entity', 'Пицца Стар');
  } else {
    query = query.in('legal_entity', ['Бургер БК', 'Воглия Матта']);
  }
  
  const { data, error } = await query;
  
  if (error) {
    orderHistory.innerHTML = '<div style="color:red;">Ошибка загрузки</div>';
    return;
  }
  
  if (!data.length) {
    orderHistory.innerHTML = '<div style="color:#999;padding:20px;text-align:center;">История пуста</div>';
    return;
  }
  
  orderHistory.innerHTML = data.map(o => {
    const date = new Date(o.created_at).toLocaleDateString();
    return `
      <div class="history-order">
        <div class="history-header">
          <span>${date} — ${o.supplier || 'Все'}</span>
          <div class="history-actions">
            <button class="btn small" onclick="restoreOrder('${o.id}')">Восстановить</button>
            <button class="btn small" style="background:var(--error);color:white;" onclick="deleteOrder('${o.id}')">🗑️</button>
          </div>
        </div>
      </div>
    `;
  }).join('');
}

window.restoreOrder = async (orderId) => {
  const { data, error } = await supabase
    .from('orders')
    .select('*')
    .eq('id', orderId)
    .single();
  
  if (error || !data) {
    showToast('Ошибка', 'Не удалось загрузить заказ', 'error');
    return;
  }
  
  orderState.items = data.items || [];
  historyModal.classList.add('hidden');
  orderSection.classList.remove('hidden');
  render();
  saveDraft();
  showToast('Восстановлено', 'Заказ загружен из истории', 'success');
};

window.deleteOrder = async (orderId) => {
  const confirmed = await customConfirm('Удалить заказ?', 'Заказ будет удалён из истории');
  if (!confirmed) return;
  
  const { error } = await supabase
    .from('orders')
    .delete()
    .eq('id', orderId);
  
  if (error) {
    showToast('Ошибка', 'Не удалось удалить', 'error');
    return;
  }
  
  showToast('Удалено', 'Заказ удалён из истории', 'success');
  loadOrderHistory();
};

/* ================= СОХРАНИТЬ ЗАКАЗ ================= */
saveOrderBtn.addEventListener('click', async () => {
  if (!orderState.items.length) {
    showToast('Ошибка', 'Нет товаров для сохранения', 'error');
    return;
  }
  
  const order = {
    supplier: orderState.settings.supplier || null,
    legal_entity: orderState.settings.legalEntity,
    delivery_date: orderState.settings.deliveryDate,
    safety_days: orderState.settings.safetyDays,
    period_days: orderState.settings.periodDays,
    unit: orderState.settings.unit,
    items: orderState.items
  };
  
  const { error } = await supabase
    .from('orders')
    .insert([order]);
  
  if (error) {
    showToast('Ошибка', 'Не удалось сохранить заказ', 'error');
    console.error(error);
    return;
  }
  
  showToast('Сохранено', 'Заказ сохранён в историю', 'success');
});

/* ================= ПОДТВЕРЖДЕНИЕ ================= */
function customConfirm(title, message) {
  return new Promise((resolve) => {
    confirmResolve = resolve;
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmMessage').textContent = message;
    confirmModal.classList.remove('hidden');
  });
}

confirmYesBtn.addEventListener('click', () => {
  confirmModal.classList.add('hidden');
  if (confirmResolve) confirmResolve(true);
});

confirmNoBtn.addEventListener('click', () => {
  confirmModal.classList.add('hidden');
  if (confirmResolve) confirmResolve(false);
});

closeConfirmBtn.addEventListener('click', () => {
  confirmModal.classList.add('hidden');
  if (confirmResolve) confirmResolve(false);
});

/* ================= TOAST ================= */
function showToast(title, message, type = 'info') {
  let container = document.querySelector('.toast-container');
  if (!container) {
    container = document.createElement('div');
    container.className = 'toast-container';
    document.body.appendChild(container);
  }

  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;
  
  const icon = type === 'success' ? '✓' : type === 'error' ? '✕' : 'ℹ';
  
  toast.innerHTML = `
    <div class="toast-icon">${icon}</div>
    <div class="toast-content">
      <div class="toast-title">${title}</div>
      ${message ? `<div class="toast-message">${message}</div>` : ''}
    </div>
    <button class="toast-close">✖</button>
  `;
  
  container.appendChild(toast);
  
  const closeBtn = toast.querySelector('.toast-close');
  closeBtn.addEventListener('click', () => {
    toast.remove();
  });
  
  setTimeout(() => {
    toast.remove();
  }, 4000);
}

/* ================= КЛАВИШИ ENTER/ESC В МОДАЛКАХ ================= */
document.addEventListener('keydown', (e) => {
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
      if (confirmResolve) confirmResolve(false);
    }
  }
  
  if (e.key === 'Enter' && !e.shiftKey) {
    if (!manualModal.classList.contains('hidden')) {
      e.preventDefault();
      document.getElementById('m_add').click();
    } else if (!editCardModal.classList.contains('hidden')) {
      e.preventDefault();
      document.getElementById('e_save').click();
    } else if (!confirmModal.classList.contains('hidden')) {
      e.preventDefault();
      confirmYesBtn.click();
    }
  }
});

/* ================= ИНИЦИАЛИЗАЦИЯ ================= */
(async function init() {
  await initSuppliers();
  loadDraft();
  updateEntityBadge();
  
  if (orderState.items.length > 0) {
    render();
  }
  
  saveHistory();
  updateUndoRedoButtons();
})();