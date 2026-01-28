import { orderState } from './state.js';
import { calculateItem } from './calculations.js';

const tbody = document.getElementById('items');

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

document.getElementById('addItem').onclick = () => {
  orderState.items.push({
    id: crypto.randomUUID(),
    name: 'Новый товар',
    consumptionPeriod: 0,
    stock: 0,
    qtyPerBox: 1,
    boxesPerPallet: null,
    finalOrder: 0
  });
  render();
};

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
