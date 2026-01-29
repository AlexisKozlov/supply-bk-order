import { orderState } from './state.js';
import { calculateItem } from './calculations.js';
import { supabase } from './supabase.js';

/* ================= DOM ================= */
const copyOrderBtn = document.getElementById('copyOrder');
const tbody = document.getElementById('items');
const supplierSelect = document.getElementById('supplierFilter');
const finalSummary = document.getElementById('finalSummary');

const manualForm = document.getElementById('manualForm');
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


loginBtn.addEventListener('click', () => {
  if (loginPassword.value === '157') {
    loginOverlay.style.display = 'none';
  } else {
    alert('Неверный пароль');
  }
});


buildOrderBtn.addEventListener('click', () => {
  const ok = validateRequiredSettings();

  if (!ok) {
    alert('Заполните обязательные поля');
    return;
  }

  orderSection.classList.remove('hidden');
});

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

  });
}

bindSetting('today', 'today', true);
bindSetting('deliveryDate', 'deliveryDate', true);
bindSetting('periodDays', 'periodDays');
bindSetting('safetyDays', 'safetyDays');
bindSetting('safetyPercent', 'safetyPercent');


document.getElementById('unit').addEventListener('change', e => {
  orderState.settings.unit = e.target.value;
  rerenderAll();
});

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
(async function loadSuppliers() {
  const { data } = await supabase.from('products').select('supplier');
  [...new Set(data.map(p => p.supplier).filter(Boolean))]
    .forEach(s => {
      const opt = document.createElement('option');
      opt.value = s;
      opt.textContent = s;
      supplierSelect.appendChild(opt);
    });
})();

supplierSelect.addEventListener('change', async () => {
  orderState.items = [];
  render();

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

  data.forEach(p => {
    const div = document.createElement('div');
    div.className = 'search-item';
    div.textContent = `${p.sku || ''} — ${p.name}`;

    div.addEventListener('click', () => {
      addItem(p);
      searchResults.innerHTML = '';
      searchInput.value = '';
    });

    searchResults.appendChild(div);
  });
}


/* ================= РУЧНОЙ ТОВАР ================= */
addManualBtn.addEventListener('click', () => {
  manualForm.classList.remove('hidden');
});

manualCancelBtn.addEventListener('click', () => {
  manualForm.classList.add('hidden');
});

manualAddBtn.addEventListener('click', async () => {
  const name = document.getElementById('m_name').value.trim();
  if (!name) {
    alert('Введите наименование');
    return;
  }

  const product = {
    name,
    sku: document.getElementById('m_sku').value || null,
    supplier: document.getElementById('m_supplier').value || null,
    qty_per_box: +document.getElementById('m_box').value || 1,
    boxes_per_pallet: +document.getElementById('m_pallet').value || null
  };

  if (document.getElementById('m_save').checked) {
    const { data, error } = await supabase
      .from('products')
      .insert(product)
      .select()
      .single();

    if (error) {
      alert('Ошибка сохранения в базу');
      console.error(error);
      return;
    }

    addItem(data);
  } else {
    addItem(product);
  }

  manualForm.classList.add('hidden');
});

/* ================= ДОБАВЛЕНИЕ ================= */
document.getElementById('addItem').addEventListener('click', () => {
  addItem({ name: 'Новый товар', qty_per_box: 1 });
});

function addItem(p) {
  orderState.items.push({
    id: crypto.randomUUID(),
    sku: p.sku || '',
    name: p.name,
    consumptionPeriod: 0,
    stock: 0,
    qtyPerBox: p.qty_per_box || 1,
    boxesPerPallet: p.boxes_per_pallet || null,
    finalOrder: 0
  });
  render();
}

/* ================= КОПИРОВАНИЕ ЗАКАЗА ================= */
copyOrderBtn.addEventListener('click', () => {
  if (!orderState.items.length) {
    alert('Заказ пуст');
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

      const roundedBoxes = Math.ceil(boxes);

      if (roundedBoxes <= 0) return null;

      const name = `${item.sku ? item.sku + ' ' : ''}${item.name}`;

      return `${name} ${roundedBoxes} коробок`;
    })
    .filter(Boolean);

  if (!lines.length) {
    alert('В заказе нет позиций с количеством');
    return;
  }

  const text =
`Добрый день!

Просьба поставить:

${lines.join('\n')}

Дата прихода: ${deliveryDate}

Спасибо!`;

  navigator.clipboard.writeText(text)
    .then(() => {
      alert('Заказ скопирован в буфер обмена');
    })
    .catch(() => {
      alert('Не удалось скопировать заказ');
    });
});


/* ================= ТАБЛИЦА ================= */
function render() {
  tbody.innerHTML = '';

  orderState.items.forEach(item => {
    const tr = document.createElement('tr');

  tr.innerHTML = `
  <td class="item-name">
  ${item.sku ? `<b>${item.sku}</b> ` : ''}${item.name}
</td>
  <td><input type="number" value="${item.consumptionPeriod}"></td>
  <td><input type="number" value="${item.stock}"></td>
  <td class="calc">0</td>
  <td><input type="number" value="${item.finalOrder}"></td>
  <td class="date">-</td>
  <td class="pallets">
    <div class="pallet-info">-</div>
    <button class="btn small">Округлить</button>
  </td>
  <td class="status">-</td>
`;

 const inputs = tr.querySelectorAll('input')
    const roundBtn = tr.querySelector('button');

    inputs[0].addEventListener('input', e => {
  item.consumptionPeriod = +e.target.value || 0;
  updateRow(tr, item);
});

inputs[1].addEventListener('input', e => {
  item.stock = +e.target.value || 0;
  updateRow(tr, item);
});

inputs[2].addEventListener('input', e => {
  item.finalOrder = +e.target.value || 0;
  updateRow(tr, item);
});

    roundBtn.addEventListener('click', () => {
      roundToPallet(item);
      inputs[2].value = item.finalOrder;
      updateRow(tr, item);
    });

    tbody.appendChild(tr);
    updateRow(tr, item);
  });

  updateFinalSummary();
}

function updateRow(tr, item) {
  const calc = calculateItem(item, orderState.settings);

  tr.querySelector('.calc').textContent =
    calc.calculatedOrder.toFixed(2);

  tr.querySelector('.date').textContent =
    calc.coverageDate
      ? calc.coverageDate.toLocaleDateString()
      : '-';

  if (item.boxesPerPallet && item.finalOrder > 0) {
    const boxes =
      orderState.settings.unit === 'boxes'
        ? item.finalOrder
        : item.finalOrder / item.qtyPerBox;

    const pallets = Math.floor(boxes / item.boxesPerPallet);
    const boxesLeft = Math.ceil(boxes % item.boxesPerPallet);

    tr.querySelector('.pallet-info').textContent =
      `${pallets} пал. + ${boxesLeft} кор.`;
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
  finalSummary.innerHTML = orderState.items.map(item => {
    const boxes =
      orderState.settings.unit === 'boxes'
        ? item.finalOrder
        : item.finalOrder / item.qtyPerBox;

  return `
  <div>
    <b>${item.sku ? item.sku + ' ' : ''}${item.name}</b>
    — ${Math.ceil(boxes)} коробок
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
