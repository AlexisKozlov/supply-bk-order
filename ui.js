import { orderState } from './state.js';
import { calculateItem } from './calculations.js';
import { supabase } from './supabase.js';

/* ================= DOM ================= */
const tbody = document.getElementById('items');
const summary = document.getElementById('summaryContent');
const manualForm = document.getElementById('manualForm');
const supplierSelect = document.getElementById('supplierFilter');
const searchInput = document.getElementById('productSearch');
const searchResults = document.getElementById('searchResults');

/* ================= ДАТА СЕГОДНЯ ================= */
const todayInput = document.getElementById('today');
const today = new Date();
todayInput.value = today.toISOString().slice(0, 10);
orderState.settings.today = today;

/* ================= НАСТРОЙКИ ================= */
function bindSetting(id, key, isDate = false) {
  document.getElementById(id).addEventListener('input', e => {
    orderState.settings[key] = isDate
      ? new Date(e.target.value)
      : +e.target.value || e.target.value;
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
loadSuppliers();

async function loadSuppliers() {
  const { data } = await supabase.from('products').select('supplier');

  const suppliers = [...new Set(
    data.map(p => p.supplier).filter(Boolean)
  )];

  suppliers.forEach(s => {
    const opt = document.createElement('option');
    opt.value = s;
    opt.textContent = s;
    supplierSelect.appendChild(opt);
  });
}

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

/* ================= ПОИСК ================= */
let searchTimer = null;

searchInput.oninput = () => {
  const q = searchInput.value.trim();
  clearTimeout(searchTimer);

  if (q.length < 2) {
    searchResults.innerHTML = '';
    return;
  }

  searchTimer = setTimeout(() => searchProducts(q), 300);
};

async function searchProducts(q) {
  const isSku = /^[0-9A-Za-z-]+$/.test(q);

  let query = supabase.from('products').select('*').limit(10);

  if (supplierSelect.value) {
    query = query.eq('supplier', supplierSelect.value);
  }

  query = isSku
    ? query.ilike('sku', `%${q}%`)
    : query.ilike('name', `%${q}%`);

  const { data } = await query;

  searchResults.innerHTML = '';
  data.forEach(p => {
    const div = document.createElement('div');
    div.textContent = `${p.sku || ''} — ${p.name}`;
    div.onclick = () => {
      addItem(p);
      searchResults.innerHTML = '';
      searchInput.value = '';
    };
    searchResults.appendChild(div);
  });
}

/* ================= РУЧНОЙ ТОВАР ================= */
document.getElementById('addManual').onclick = () =>
  manualForm.classList.remove('hidden');

document.getElementById('m_cancel').onclick = () =>
  manualForm.classList.add('hidden');

document.getElementById('m_add').onclick = async () => {
  const product = {
    name: m_name.value.trim(),
    sku: m_sku.value || null,
    supplier: m_supplier.value || null,
    qty_per_box: +m_box.value || 1,
    boxes_per_pallet: +m_pallet.value || null
  };

  if (!product.name) return alert('Введите наименование');

  if (m_save.checked) {
    const { data, error } = await supabase
      .from('products')
      .insert(product)
      .select()
      .single();

    if (error) {
      alert('Ошибка сохранения');
      console.error(error);
      return;
    }

    addItem(data);
  } else {
    addItem(product);
  }

  manualForm.classList.add('hidden');
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
      <td class="pallets"><div class="pallet-info">-</div></td>
    `;

    const i = tr.querySelectorAll('input');

    i[1].oninput = e => { item.consumptionPeriod = +e.target.value || 0; update(tr, item); };
    i[2].oninput = e => { item.stock = +e.target.value || 0; update(tr, item); };
    i[3].oninput = e => { item.finalOrder = +e.target.value || 0; update(tr, item); };

    tbody.appendChild(tr);
    update(tr, item);
  });

  updateSummary();
}

function update(tr, item) {
  const calc = calculateItem(item, orderState.settings);

  tr.querySelector('.calc').textContent =
    calc.calculatedOrder.toFixed(2);

  tr.querySelector('.date').textContent =
    calc.coverageDate
      ? calc.coverageDate.toLocaleDateString()
      : '-';

  tr.querySelector('.pallet-info').textContent =
    calc.palletsInfo
      ? `${calc.palletsInfo.pallets} пал.`
      : '-';
}

/* ================= ИТОГИ ================= */
function updateSummary() {
  let totalOrder = 0;
  let totalPallets = 0;

  orderState.items.forEach(i => {
    totalOrder += i.finalOrder || 0;

    if (i.boxesPerPallet) {
      const boxes = orderState.settings.unit === 'boxes'
        ? i.finalOrder
        : i.finalOrder / i.qtyPerBox;

      totalPallets += Math.floor(boxes / i.boxesPerPallet);
    }
  });

  summary.innerHTML = `
    <b>Строк:</b> ${orderState.items.length}<br>
    <b>Всего заказано:</b> ${totalOrder.toFixed(2)}<br>
    <b>Всего паллет:</b> ${totalPallets}
  `;
}

function rerenderAll() {
  document.querySelectorAll('#items tr').forEach((tr, idx) =>
    update(tr, orderState.items[idx])
  );
  updateSummary();
}

/* ================= ЭКСПОРТ ================= */
document.getElementById('exportExcel').onclick = () => {
  let csv = 'Товар;Заказ;Единица\n';

  orderState.items.forEach(i => {
    csv += `${i.name};${i.finalOrder};${orderState.settings.unit}\n`;
  });

  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'order.csv';
  a.click();
};

render();
