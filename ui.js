import { orderState } from './state.js';
import { calculateItem } from './calculations.js';
import { supabase } from './supabase.js';

const tbody = document.getElementById('items');
const searchInput = document.getElementById('productSearch');
const searchResults = document.getElementById('searchResults');

/* ---------- SETTINGS ---------- */

function bindSetting(id, key, isDate = false) {
  document.getElementById(id).addEventListener('input', e => {
    orderState.settings[key] = isDate
      ? new Date(e.target.value)
      : +e.target.value || e.target.value;
    render();
  });
}

bindSetting('today', 'today', true);
bindSetting('deliveryDate', 'deliveryDate', true);
bindSetting('periodDays', 'periodDays');
bindSetting('safetyDays', 'safetyDays');
bindSetting('safetyPercent', 'safetyPercent');

document.getElementById('unit').onchange = e => {
  orderState.settings.unit = e.target.value;
  render();
};

/* ---------- ADD EMPTY ---------- */

document.getElementById('addItem').onclick = () => {
  addItem({ name: 'Новый товар', qty_per_box: 1 });
};

/* ---------- SEARCH ---------- */

let searchTimeout;

searchInput.oninput = () => {
  clearTimeout(searchTimeout);
  const q = searchInput.value.trim();
  if (q.length < 2) return (searchResults.innerHTML = '');

  searchTimeout = setTimeout(() => searchProducts(q), 300);
};

async function searchProducts(q) {
  const { data } = await supabase
    .from('products')
    .select('*')
    .ilike('name', `%${q}%`)
    .limit(10);

  searchResults.innerHTML = '';
  data?.forEach(p => {
    const div = document.createElement('div');
    div.textContent = p.name;
    div.onclick = () => {
      addItem(p);
      searchResults.innerHTML = '';
      searchInput.value = '';
    };
    searchResults.appendChild(div);
  });
}

/* ---------- MANUAL FORM ---------- */

const form = document.getElementById('manualForm');

document.getElementById('addManual').onclick = () =>
  form.classList.remove('hidden');

document.getElementById('m_cancel').onclick = () =>
  form.classList.add('hidden');

document.getElementById('m_add').onclick = async () => {
  const product = {
    name: m_name.value.trim(),
    sku: m_sku.value || null,
    supplier: m_supplier.value || null,
    qty_per_box: +m_box.value || 1,
    boxes_per_pallet: +m_pallet.value || null
  };

  if (!product.name) {
    alert('Наименование обязательно');
    return;
  }

  if (m_save.checked) {
    const { data, error } = await supabase
      .from('products')
      .insert(product)
      .select()
      .single();

    if (error) {
      alert('Ошибка сохранения');
      return;
    }

    addItem(data);
  } else {
    addItem(product);
  }

  form.classList.add('hidden');
  form.querySelectorAll('input').forEach(i => (i.value = ''));
  m_box.value = 1;
  m_save.checked = false;
};

/* ---------- ITEMS ---------- */

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

/* ---------- RENDER ---------- */

function render() {
  tbody.innerHTML = '';

  orderState.items.forEach(item => {
    const calc = calculateItem(item, orderState.settings);
    const tr = document.createElement('tr');

    tr.innerHTML = `
      <td><input value="${item.name}"></td>
      <td><input type="number" value="${item.consumptionPeriod}"></td>
      <td><input type="number" value="${item.stock}"></td>
      <td>${calc.calculatedOrder.toFixed(2)}</td>
      <td><input type="number" value="${item.finalOrder || 0}"></td>
      <td>${calc.coverageDate ? calc.coverageDate.toLocaleDateString() : '-'}</td>
      <td>${calc.palletsInfo
        ? `${calc.palletsInfo.pallets} пал. + ${calc.palletsInfo.boxesLeft} кор.`
        : '-'}</td>
    `;

    const i = tr.querySelectorAll('input');
    i[0].oninput = e => item.name = e.target.value;
    i[1].oninput = e => item.consumptionPeriod = +e.target.value || 0;
    i[2].oninput = e => item.stock = +e.target.value || 0;
    i[3].oninput = e => item.finalOrder = +e.target.value || 0;

    tbody.appendChild(tr);
  });
}

render();
