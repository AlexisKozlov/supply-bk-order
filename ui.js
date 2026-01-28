import { orderState } from './state.js';
import { calculateItem } from './calculations.js';
import { supabase } from './supabase.js';

/* ================= DOM ================= */
const tbody = document.getElementById('items');
const supplierSelect = document.getElementById('supplierFilter');
const finalSummary = document.getElementById('finalSummary');

/* ================= ДАТА СЕГОДНЯ ================= */
const today = new Date();
const todayInput = document.getElementById('today');
todayInput.value = today.toISOString().slice(0, 10);
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

/* ================= ПОСТАВЩИКИ ================= */
(async function loadSuppliers() {
  const { data, error } = await supabase
    .from('products')
    .select('supplier');

  if (error) {
    console.error(error);
    return;
  }

  const suppliers = [...new Set(
    data.map(p => p.supplier).filter(Boolean)
  )];

  suppliers.forEach(s => {
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

  const { data, error } = await supabase
    .from('products')
    .select('*')
    .eq('supplier', supplierSelect.value);

  if (error) {
    console.error(error);
    return;
  }

  data.forEach(addItem);
});

/* ================= ДОБАВЛЕНИЕ ================= */
document.getElementById('addItem').addEventListener('click', () => {
  addItem({ name: 'Новый товар', qty_per_box: 1 });
});

function addItem(p) {
  orderState.items.push({
    id: crypto.randomUUID(),
    name: p.name,
    consumptionPeriod: 0,
    stock: 0,
    qtyPerBox: p.qty_per_box || 1,
    boxesPerPallet: p.boxes_per_pallet || null,
    finalOrder: 0
  });
  render();
}

/* ================= ТАБЛИЦА ================= */
function render() {
  tbody.innerHTML = '';

  orderState.items.forEach(item => {
    const tr = document.createElement('tr');

    tr.innerHTML = `
      <td><input value="${item.name}"></td>
      <td><input type="number" value="${item.consumptionPeriod}"></td>
      <td><input type="number" value="${item.stock}"></td>
      <td class="calc">0</td>
      <td><input type="number" value="${item.finalOrder}"></td>
      <td class="date">-</td>
      <td class="pallets">
        <div class="pallet-info">-</div>
        <button class="btn small">Округлить</button>
      </td>
    `;

    const inputs = tr.querySelectorAll('input');
    const roundBtn = tr.querySelector('button');

    inputs[1].addEventListener('input', e => {
      item.consumptionPeriod = +e.target.value || 0;
      updateRow(tr, item);
    });

    inputs[2].addEventListener('input', e => {
      item.stock = +e.target.value || 0;
      updateRow(tr, item);
    });

    inputs[3].addEventListener('input', e => {
      item.finalOrder = +e.target.value || 0;
      updateRow(tr, item);
    });

    roundBtn.addEventListener('click', () => {
      roundToPallet(item);
      inputs[3].value = item.finalOrder;
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

  // --- паллеты всегда считаем через коробки ---
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

  updateFinalSummary();
}

/* ================= ОКРУГЛЕНИЕ ДО ПАЛЛЕТЫ ================= */
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

    return `<div><b>${item.name}</b> — ${Math.ceil(boxes)} коробок</div>`;
  }).join('');
}

/* ================= ПЕРЕРИСОВКА ================= */
function rerenderAll() {
  document
    .querySelectorAll('#items tr')
    .forEach((tr, i) => updateRow(tr, orderState.items[i]));
}

render();
