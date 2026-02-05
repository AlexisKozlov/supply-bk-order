import { orderState } from './state.js';
import { calculateItem } from './calculations.js';
import { supabase } from './supabase.js';
import { 
  showToast, 
  customConfirm,
  loadOrderHistory,
  loadDatabaseProducts
} from './ui-modals.js';

/* ================= DOM ================= */
const copyOrderBtn = document.getElementById('copyOrder');
const clearOrderBtn = document.getElementById('clearOrder');
const tbody = document.getElementById('items');
const supplierSelect = document.getElementById('supplierFilter');
const finalSummary = document.getElementById('finalSummary');

const searchInput = document.getElementById('productSearch');
const searchResults = document.getElementById('searchResults');
const clearSearchBtn = document.getElementById('clearSearch');

const buildOrderBtn = document.getElementById('buildOrder');
const orderSection = document.getElementById('orderSection');
const loginOverlay = document.getElementById('loginOverlay');
const loginBtn = document.getElementById('loginBtn');
const loginPassword = document.getElementById('loginPassword');
const saveOrderBtn = document.getElementById('saveOrder');

let isLoadingDraft = false;

const nf = new Intl.NumberFormat('ru-RU', {
  maximumFractionDigits: 0
});

/* ================= BADGE ЮР. ЛИЦА ================= */
export function updateEntityBadge() {
  const badge = document.getElementById('entityBadge');
  if (badge) badge.textContent = orderState.settings.legalEntity || 'Бургер БК';
}

/* ================= АВТОРИЗАЦИЯ ================= */
loginBtn.addEventListener('click', () => {
  if (loginPassword.value === '157') {
    loginOverlay.style.display = 'none';
    localStorage.setItem('bk_logged_in', 'true');
    loadOrderHistory();
  } else {
    showToast('Ошибка входа', 'Неверный пароль', 'error');
  }
});

/* ================= КНОПКА "СФОРМИРОВАТЬ ЗАКАЗ" ================= */
buildOrderBtn.addEventListener('click', () => {
  const ok = validateRequiredSettings();

  if (!ok) {
    showToast('Заполните обязательные поля', 'Укажите даты и запас безопасности', 'error');
    return;
  }

  orderSection.classList.remove('hidden');
});

/* ================= СОХРАНЕНИЕ ЗАКАЗА ================= */
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

/* ================= SETTINGS ================= */
const todayInput = document.getElementById('today');
const deliveryDateInput = document.getElementById('deliveryDate');
const periodDaysInput = document.getElementById('periodDays');
const safetyDaysInput = document.getElementById('safetyDays');
const unitSelect = document.getElementById('unit');
const hasTransitSelect = document.getElementById('hasTransit');
const showStockColumnSelect = document.getElementById('showStockColumn');
const legalEntitySelect = document.getElementById('legalEntity');

function validateRequiredSettings() {
  const { today, deliveryDate, safetyDays } = orderState.settings;
  return today && deliveryDate && safetyDays !== null;
}

function syncSettings() {
  orderState.settings.legalEntity = legalEntitySelect.value;
  orderState.settings.supplier = supplierSelect.value;
  orderState.settings.today = todayInput.value ? new Date(todayInput.value) : null;
  orderState.settings.deliveryDate = deliveryDateInput.value ? new Date(deliveryDateInput.value) : null;
  orderState.settings.periodDays = +periodDaysInput.value || 30;
  orderState.settings.safetyDays = +safetyDaysInput.value || 0;
  orderState.settings.unit = unitSelect.value;
  orderState.settings.hasTransit = hasTransitSelect.value === 'true';
  
  // Управляем видимостью столбца "Транзит"
  const transitCols = document.querySelectorAll('.transit-col');
  transitCols.forEach(col => {
    col.style.display = orderState.settings.hasTransit ? '' : 'none';
  });
  
  // Управляем видимостью столбца "Запас"
  const showStockColumn = showStockColumnSelect.value === 'true';
  const stockCols = document.querySelectorAll('.stock-col');
  stockCols.forEach(col => {
    col.style.display = showStockColumn ? '' : 'none';
  });
  
  updateEntityBadge();
}

legalEntitySelect.addEventListener('change', () => {
  syncSettings();
  saveDraft();
  loadSupplierOptions();
  loadDatabaseProducts(); // Обновляем базу при смене юр лица
});

supplierSelect.addEventListener('change', () => {
  syncSettings();
  saveDraft();
});

todayInput.addEventListener('change', () => {
  syncSettings();
  render();
  saveDraft();
});

deliveryDateInput.addEventListener('change', () => {
  syncSettings();
  render();
  saveDraft();
});

periodDaysInput.addEventListener('change', () => {
  syncSettings();
  render();
  saveDraft();
});

safetyDaysInput.addEventListener('change', () => {
  syncSettings();
  render();
  saveDraft();
});

unitSelect.addEventListener('change', () => {
  syncSettings();
  render();
  saveDraft();
});

hasTransitSelect.addEventListener('change', () => {
  syncSettings();
  render();
  saveDraft();
});

showStockColumnSelect.addEventListener('change', () => {
  syncSettings();
  saveDraft();
});

/* ================= ПОСТАВЩИКИ ================= */
async function loadSupplierOptions() {
  const { data, error } = await supabase
    .from('products')
    .select('supplier')
    .eq('legal_entity', orderState.settings.legalEntity)
    .not('supplier', 'is', null);

  if (error) {
    console.error('Ошибка загрузки поставщиков:', error);
    return;
  }

  const unique = [...new Set(data.map(d => d.supplier).filter(Boolean))];
  unique.sort();

  supplierSelect.innerHTML = '<option value="">Все / свободный</option>';
  unique.forEach(s => {
    const opt = document.createElement('option');
    opt.value = s;
    opt.textContent = s;
    supplierSelect.appendChild(opt);
  });
}

/* ================= RENDER ================= */
export function render() {
  if (!tbody) return;

  const { hasTransit, supplier, unit } = orderState.settings;

  let filtered = orderState.items;
  if (supplier) {
    filtered = filtered.filter(i => !i.supplier || i.supplier === supplier);
  }

  // Сортировка по supplier, затем по имени
  filtered.sort((a, b) => {
    const supA = a.supplier || '';
    const supB = b.supplier || '';
    if (supA !== supB) return supA.localeCompare(supB);
    return a.name.localeCompare(b.name);
  });

  tbody.innerHTML = '';

  filtered.forEach((item, idx) => {
    const calc = calculateItem(item, orderState.settings);

    const tr = document.createElement('tr');
    tr.dataset.itemId = item.id;

    // 1) Drag handle
    const tdDrag = document.createElement('td');
    tdDrag.className = 'drag-handle';
    tdDrag.innerHTML = '⠿';
    tr.appendChild(tdDrag);

    // 2) Наименование + артикул + единицы
    const tdName = document.createElement('td');
    const unitDisplay = item.unitOfMeasure ? ` (${item.unitOfMeasure})` : '';
    tdName.innerHTML = `
      <strong>${item.name}</strong>${unitDisplay}<br>
      <small style="color:var(--muted);">
        ${item.sku || 'нет артикула'} 
        ${item.supplier ? `/ ${item.supplier}` : ''}
        ${item.qtyPerBox ? `/ ${item.qtyPerBox} шт/кор` : ''}
      </small>
    `;
    tr.appendChild(tdName);

    // 3) Расход за период
    const tdConsumption = document.createElement('td');
    const inp = document.createElement('input');
    inp.type = 'number';
    inp.value = item.consumptionPeriod || 0;
    inp.style.width = '80px';
    inp.addEventListener('input', () => {
      item.consumptionPeriod = +inp.value || 0;
      render();
      saveDraft();
    });
    tdConsumption.appendChild(inp);
    tr.appendChild(tdConsumption);

    // 4) Остаток
    const tdStock = document.createElement('td');
    const inpStock = document.createElement('input');
    inpStock.type = 'number';
    inpStock.value = item.stock || 0;
    inpStock.style.width = '80px';
    inpStock.addEventListener('input', () => {
      item.stock = +inpStock.value || 0;
      render();
      saveDraft();
    });
    tdStock.appendChild(inpStock);
    tr.appendChild(tdStock);

    // 5) Транзит
    const tdTransit = document.createElement('td');
    tdTransit.className = 'transit-col';
    tdTransit.style.display = hasTransit ? '' : 'none';
    const inpTransit = document.createElement('input');
    inpTransit.type = 'number';
    inpTransit.value = item.transit || 0;
    inpTransit.style.width = '80px';
    inpTransit.addEventListener('input', () => {
      item.transit = +inpTransit.value || 0;
      render();
      saveDraft();
    });
    tdTransit.appendChild(inpTransit);
    tr.appendChild(tdTransit);

    // 6) Запас (остаток + транзит + заказ)
    const tdStockCalc = document.createElement('td');
    tdStockCalc.className = 'stock-col';
    const showStockColumn = showStockColumnSelect.value === 'true';
    tdStockCalc.style.display = showStockColumn ? '' : 'none';
    const totalAvailable = item.stock + (item.transit || 0) + (item.finalOrder || 0);
    tdStockCalc.textContent = nf.format(totalAvailable);
    tr.appendChild(tdStockCalc);

    // 7) Расчётное количество
    const tdCalc = document.createElement('td');
    tdCalc.textContent = nf.format(calc.calculatedOrder);
    tr.appendChild(tdCalc);

    // 8) Итоговый заказ (кор/шт)
    const tdOrder = document.createElement('td');
    const inpFinal = document.createElement('input');
    inpFinal.type = 'number';
    inpFinal.value = item.finalOrder || 0;
    inpFinal.style.width = '80px';
    inpFinal.style.fontWeight = 'bold';
    inpFinal.addEventListener('input', () => {
      item.finalOrder = +inpFinal.value || 0;
      render();
      saveDraft();
    });

    let unitLabel = '';
    if (unit === 'boxes') {
      unitLabel = 'кор';
    } else if (unit === 'pieces') {
      unitLabel = 'шт';
    }

    const containerOrder = document.createElement('div');
    containerOrder.style.display = 'flex';
    containerOrder.style.gap = '4px';
    containerOrder.style.alignItems = 'center';
    containerOrder.appendChild(inpFinal);

    const span = document.createElement('span');
    span.textContent = unitLabel;
    span.style.fontSize = '14px';
    span.style.color = 'var(--muted)';
    containerOrder.appendChild(span);

    // Кнопка "=" для расчётного
    const btnCalc = document.createElement('button');
    btnCalc.textContent = '=';
    btnCalc.className = 'btn small';
    btnCalc.title = 'Использовать расчётное';
    btnCalc.addEventListener('click', () => {
      item.finalOrder = calc.calculatedOrder;
      render();
      saveDraft();
    });
    containerOrder.appendChild(btnCalc);

    tdOrder.appendChild(containerOrder);
    tr.appendChild(tdOrder);

    // 9) Хватит до
    const tdDate = document.createElement('td');
    if (calc.coverageDate) {
      const dateStr = calc.coverageDate.toLocaleDateString('ru-RU');
      tdDate.textContent = dateStr;

      // Подсветка если заканчивается раньше доставки
      if (orderState.settings.deliveryDate && calc.coverageDate < orderState.settings.deliveryDate) {
        tdDate.style.color = 'var(--error)';
        tdDate.style.fontWeight = 'bold';
      }
    } else {
      tdDate.textContent = '—';
    }
    tr.appendChild(tdDate);

    // 10) Паллеты
    const tdPallets = document.createElement('td');
    if (calc.palletsInfo) {
      const { pallets, boxesLeft } = calc.palletsInfo;
      tdPallets.innerHTML = `${pallets} п<br><small>${boxesLeft} к</small>`;
    } else {
      tdPallets.textContent = '—';
    }
    tr.appendChild(tdPallets);

    // 11) Удаление
    const tdDel = document.createElement('td');
    const btnDel = document.createElement('button');
    btnDel.className = 'btn small';
    btnDel.textContent = '🗑️';
    btnDel.title = 'Удалить';
    btnDel.addEventListener('click', async () => {
      const confirmed = await customConfirm('Удалить товар?', `Удалить "${item.name}" из заказа?`);
      if (!confirmed) return;
      
      orderState.items = orderState.items.filter(x => x.id !== item.id);
      render();
      saveDraft();
    });
    tdDel.appendChild(btnDel);
    tr.appendChild(tdDel);

    tbody.appendChild(tr);
  });

  renderSummary();
}

/* ================= ИТОГ ================= */
function renderSummary() {
  if (!finalSummary) return;

  const { unit } = orderState.settings;
  const groupedBySupplier = {};

  orderState.items.forEach(item => {
    const sup = item.supplier || 'Без поставщика';
    if (!groupedBySupplier[sup]) {
      groupedBySupplier[sup] = {
        totalBoxes: 0,
        totalPallets: 0,
        totalLeftBoxes: 0
      };
    }

    if (item.finalOrder && item.qtyPerBox) {
      const boxes = unit === 'boxes' ? item.finalOrder : item.finalOrder / item.qtyPerBox;
      groupedBySupplier[sup].totalBoxes += boxes;

      if (item.boxesPerPallet) {
        const pallets = Math.floor(boxes / item.boxesPerPallet);
        const leftBoxes = Math.ceil(boxes % item.boxesPerPallet);
        groupedBySupplier[sup].totalPallets += pallets;
        groupedBySupplier[sup].totalLeftBoxes += leftBoxes;
      }
    }
  });

  let html = '';
  for (const [sup, stats] of Object.entries(groupedBySupplier)) {
    html += `
      <div style="margin-bottom: 12px;">
        <strong>${sup}:</strong><br>
        Коробов: ${nf.format(stats.totalBoxes)}<br>
        Паллет: ${stats.totalPallets} п + ${stats.totalLeftBoxes} к
      </div>
    `;
  }

  finalSummary.innerHTML = html || '<div style="color:var(--muted);">Заказ пуст</div>';
}

/* ================= ПОИСК ТОВАРА ================= */
let searchTimeout = null;

searchInput.addEventListener('input', async () => {
  const query = searchInput.value.trim().toLowerCase();

  if (clearSearchBtn) {
    if (query.length > 0) {
      clearSearchBtn.classList.remove('hidden');
    } else {
      clearSearchBtn.classList.add('hidden');
    }
  }

  if (query.length < 2) {
    searchResults.innerHTML = '';
    searchResults.style.display = 'none';
    return;
  }

  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(async () => {
    const { data, error } = await supabase
      .from('products')
      .select('*')
      .eq('legal_entity', orderState.settings.legalEntity)
      .or(`sku.ilike.%${query}%,name.ilike.%${query}%`)
      .limit(10);

    if (error) {
      console.error(error);
      return;
    }

    if (!data || data.length === 0) {
      searchResults.innerHTML = '<div class="search-item">Не найдено</div>';
      searchResults.style.display = 'block';
      return;
    }

    searchResults.innerHTML = data
      .map(p => {
        const unitDisplay = p.unit_of_measure ? ` (${p.unit_of_measure})` : '';
        return `
          <div class="search-item" data-id="${p.id}">
            <strong>${p.name}</strong>${unitDisplay}<br>
            <small style="color:var(--muted);">
              ${p.sku || 'нет артикула'} 
              ${p.supplier ? `/ ${p.supplier}` : ''}
            </small>
          </div>
        `;
      })
      .join('');

    searchResults.style.display = 'block';

    searchResults.querySelectorAll('.search-item').forEach(el => {
      el.addEventListener('click', () => {
        const id = el.dataset.id;
        const product = data.find(p => p.id === id);
        if (product) {
          addProductToOrder(product);
          searchInput.value = '';
          searchResults.innerHTML = '';
          searchResults.style.display = 'none';
          if (clearSearchBtn) clearSearchBtn.classList.add('hidden');
        }
      });
    });
  }, 300);
});

if (clearSearchBtn) {
  clearSearchBtn.addEventListener('click', () => {
    searchInput.value = '';
    searchResults.innerHTML = '';
    searchResults.style.display = 'none';
    if (clearSearchBtn) clearSearchBtn.classList.add('hidden');
    searchInput.focus();
  });
}

function addProductToOrder(product) {
  const exists = orderState.items.find(i => i.supabaseId === product.id);
  if (exists) {
    showToast('Товар уже в заказе', '', 'info');
    return;
  }

  const newItem = {
    id: Date.now() + Math.random(),
    supabaseId: product.id,
    name: product.name,
    sku: product.sku,
    supplier: product.supplier,
    consumptionPeriod: product.consumption_period || 0,
    stock: product.stock || 0,
    transit: product.transit || 0,
    qtyPerBox: product.qty_per_box || 1,
    boxesPerPallet: product.boxes_per_pallet || null,
    unitOfMeasure: product.unit_of_measure || 'шт',
    finalOrder: 0
  };

  orderState.items.push(newItem);
  render();
  saveDraft();
  showToast('Товар добавлен', '', 'success');
}

/* ================= КОПИРОВАНИЕ ЗАКАЗА ================= */
copyOrderBtn.addEventListener('click', () => {
  const { unit } = orderState.settings;
  const filtered = orderState.settings.supplier
    ? orderState.items.filter(i => !i.supplier || i.supplier === orderState.settings.supplier)
    : orderState.items;

  const lines = filtered.map(item => {
    if (!item.finalOrder || item.finalOrder <= 0) return null;

    const boxes = unit === 'boxes' ? item.finalOrder : item.finalOrder / item.qtyPerBox;
    const boxesRounded = Math.ceil(boxes);
    const unitLabel = unit === 'boxes' ? 'кор' : 'шт';

    return `${item.name} ${item.sku || ''} — ${nf.format(item.finalOrder)} ${unitLabel} (${boxesRounded} кор)`;
  }).filter(Boolean);

  if (lines.length === 0) {
    showToast('Нечего копировать', 'Заказ пуст', 'error');
    return;
  }

  const text = lines.join('\n');
  navigator.clipboard.writeText(text).then(() => {
    showToast('Заказ скопирован', '', 'success');
  });
});

/* ================= ОЧИСТКА ЗАКАЗА ================= */
clearOrderBtn.addEventListener('click', async () => {
  const confirmed = await customConfirm('Очистить заказ?', 'Все товары будут удалены из заказа');
  if (!confirmed) return;

  orderState.items = [];
  render();
  saveDraft();
  showToast('Заказ очищен', '', 'success');
});

/* ================= ЧЕРНОВИК ================= */
function saveDraft() {
  if (isLoadingDraft) return;
  
  const draft = {
    settings: {
      legalEntity: orderState.settings.legalEntity,
      supplier: orderState.settings.supplier,
      today: orderState.settings.today ? orderState.settings.today.toISOString() : null,
      deliveryDate: orderState.settings.deliveryDate ? orderState.settings.deliveryDate.toISOString() : null,
      periodDays: orderState.settings.periodDays,
      safetyDays: orderState.settings.safetyDays,
      unit: orderState.settings.unit,
      hasTransit: orderState.settings.hasTransit
    },
    items: orderState.items.map(item => ({
      id: item.id,
      supabaseId: item.supabaseId,
      name: item.name,
      sku: item.sku,
      supplier: item.supplier,
      consumptionPeriod: item.consumptionPeriod,
      stock: item.stock,
      transit: item.transit,
      qtyPerBox: item.qtyPerBox,
      boxesPerPallet: item.boxesPerPallet,
      unitOfMeasure: item.unitOfMeasure,
      finalOrder: item.finalOrder
    }))
  };
  
  localStorage.setItem('bk_draft', JSON.stringify(draft));
}

function loadDraft() {
  isLoadingDraft = true;
  
  const saved = localStorage.getItem('bk_draft');
  if (!saved) {
    isLoadingDraft = false;
    return;
  }
  
  try {
    const draft = JSON.parse(saved);
    
    // Настройки
    if (draft.settings) {
      orderState.settings.legalEntity = draft.settings.legalEntity || 'Бургер БК';
      orderState.settings.supplier = draft.settings.supplier || '';
      orderState.settings.today = draft.settings.today ? new Date(draft.settings.today) : null;
      orderState.settings.deliveryDate = draft.settings.deliveryDate ? new Date(draft.settings.deliveryDate) : null;
      orderState.settings.periodDays = draft.settings.periodDays || 30;
      orderState.settings.safetyDays = draft.settings.safetyDays || 0;
      orderState.settings.unit = draft.settings.unit || 'pieces';
      orderState.settings.hasTransit = draft.settings.hasTransit || false;
      
      // Синхронизируем с UI
      legalEntitySelect.value = orderState.settings.legalEntity;
      supplierSelect.value = orderState.settings.supplier;
      todayInput.value = orderState.settings.today ? orderState.settings.today.toISOString().split('T')[0] : '';
      deliveryDateInput.value = orderState.settings.deliveryDate ? orderState.settings.deliveryDate.toISOString().split('T')[0] : '';
      periodDaysInput.value = orderState.settings.periodDays;
      safetyDaysInput.value = orderState.settings.safetyDays;
      unitSelect.value = orderState.settings.unit;
      hasTransitSelect.value = orderState.settings.hasTransit ? 'true' : 'false';
    }
    
    // Товары
    if (draft.items && draft.items.length > 0) {
      orderState.items = draft.items;
      orderSection.classList.remove('hidden');
    }
    
    syncSettings();
    render();
  } catch (e) {
    console.error('Ошибка загрузки черновика:', e);
  }
  
  isLoadingDraft = false;
}

function clearDraft() {
  localStorage.removeItem('bk_draft');
}

/* ================= КЛАВИШИ ENTER/ESC ================= */
document.addEventListener('keydown', (e) => {
  // ESC — закрытие поиска
  if (e.key === 'Escape') {
    if (searchResults.style.display === 'block') {
      searchResults.innerHTML = '';
      searchResults.style.display = 'none';
      searchInput.value = '';
      if (clearSearchBtn) clearSearchBtn.classList.add('hidden');
    }
  }
});

/* ================= INIT ================= */
async function init() {
  loadDraft();
  await loadSupplierOptions();
  updateEntityBadge();
  render();
}

init();