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
  document.getElementById(id).addEventListener('input', e => {
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

document.getElementById('unit').onchange = e => {
  orderState.settings.unit = e.target.value;
  rerenderAll();
};

/* ================= ПОСТАВЩИКИ ================= */
(async function loadSuppliers() {
  const { data } = await supabase.from('products').select('supplier');
  const suppliers = [...new Set(data.map(p => p.supplier).filter(Boolean))];

  suppliers.forEach(s => {
    const opt = document.createElement('option');
    opt.value = s;
    opt.textContent = s;
    supplierSelect.appendChild(opt);
  });
})();

supplierSelect.onchange = async () => {
  orderState.items = [];
  render();

  if (!supplierSelect.value) return;

  const { data } = await supabase
    .from('products')
    .select('*')
    .eq('supplier', supplierSelect.value);

  data.forEach(addItem);
};

/* ================= ДОБАВЛЕНИЕ ================= */
document.getElementById('addItem').onclick = () =>
  addItem({ name: 'Новый товар', qty_per_box: 1 });

function addItem(p) {
  orderState.items.push({
    id: crypto.randomUUID(),
    name: p.name,
    consumptionPeriod: 0,
    stock: 0,
    qtyPerBox: p.qty_per_box || 1,
    boxesPerPallet: p.boxes_per_pallet,
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
    const btn = tr.querySelector('button');

    inputs[1].oninput = e => {
      item.consumptionPeriod = +e.target.value || 0;
      update(tr, item);
    };

    inputs[2].oninput = e => {
      item.stock = +e.target.value || 0;
      update(tr, item);
    };

    inputs[3].oninput = e => {
      item.finalOrder = +e.target.value || 0;
      update(tr, item);
    };

    btn.onclick = () => {
      roundToPallet(item);
      inputs[3].value = item.finalOrder;
      update(tr, item);
    };

    tbody.appendChild(tr);
    update(tr, item);
  });

  updateFinalSummary();
}

function update(tr, item) {
  const calc = calculateItem(item, orderState.settings);

  tr.querySelector('.calc').textContent =
    calc.calculatedOrder.toFixed(2);

  tr.querySelector('.date').textContent =
    calc.coverageDate
      ? calc.coverageDate.toLocaleDateString()
      : '-';

  if (calc.palletsInfo) {
    tr.querySelector('.pallet-info').textContent =
      `${calc.palletsInfo.pallets} пал. + ${calc.palletsInfo.boxesLeft} кор.`;
  } else {
    tr.querySelector('.pallet-info').textContent = '-';
  }
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

/* ================= ИТОГИ ДЛЯ СКРИНА ================= */
function updateFinalSummary() {
  finalSummary.innerHTML = orderState.items
    .map(i => `<div><b>${i.name}</b> — ${i.finalOrder}</div>`)
    .join('');
}

/* ================= ЭКСПОРТ В EXCEL ================= */
document.getElementById('exportExcel').onclick = () => {
  let html =
    '<table><tr><th>Номенклатура</th><th>Заказ итого</th></tr>';

  orderState.items.forEach(i => {
    html += `<tr><td>${i.name}</td><td>${i.finalOrder}</td></tr>`;
  });

  html += '</table>';

  const blob = new Blob(
    ['\ufeff' + html],
    { type: 'application/vnd.ms-excel' }
  );

  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'order.xls';
  a.click();
};

/* ================= ПЕРЕРИСОВКА ================= */
function rerenderAll() {
  document.querySelectorAll('#items tr')
    .forEach((tr, i) => update(tr, orderState.items[i]));
  updateFinalSummary();
}

render();
