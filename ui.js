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

/* ---------- ADD EMPTY ITEM ---------- */

document.getElementById('addItem').onclick = () => {
  addItem({
    name: 'Новый товар',
    qty_per_box: 1,
    boxes_per_pallet: null
  });
};

/* ---------- SEARCH ---------- */

let searchTimeout = null;

searchInput.oninput = () => {
  clearTimeout(searchTimeout);
  const query = searchInput.value.trim();

  if (query.length < 2) {
    searchResults.innerHTML = '';
    return;
  }

  searchTimeout = setTimeout(() => {
    searchProducts(query);
  }, 300);
};

async function searchProducts(query) {
  const { data, error } = await supabase
    .from('products')
    .select('*')
    .ilike('name', `%${query}%`)
    .limit(10);

  if (error) {
    console.error(error);
    return;
  }

  renderSearchResults(data);
}

function renderSearchResults(products) {
  searchResults.innerHTML = '';

  products.forEach(p => {
    const div = document.createElement('div');
    div.textContent = `${p.name} (${p.sku || 'без артикула'})`;

    div.onclick = () => {
      addItem(p);
      searchResults.innerHTML = '';
      searchInput.value = '';
    };

    searchResults.appendChild(div);
  });
}

/* ---------- ITEMS ---------- */

function addItem(product) {
  orderState.items.push({
    id: crypto.randomUUID(),
    name: product.name,
    consumptionPeriod: 0,
    stock: 0,
    qtyPerBox: product.qty_per_box || 1,
    boxesPerPallet: product.boxes_per_pallet,
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

    const inputs = tr.querySelectorAll('input');
    inputs[0].oninput = e => item.name = e.target.value;
    inputs[1].oninput = e => item.consumptionPeriod = +e.target.value || 0;
    inputs[2].oninput = e => item.stock = +e.target.value || 0;
    inputs[3].oninput = e => item.finalOrder = +e.target.value || 0;

    tbody.appendChild(tr);
  });
}

render();
