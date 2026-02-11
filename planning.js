/**
 * Модуль планирования заказов
 * planning.js — отдельный файл
 * 
 * Поддержка периодов: 1-12 недель и 1-3 месяца
 */

import { supabase } from './supabase.js';
import { showToast } from './modals.js';

const nf = new Intl.NumberFormat('ru-RU');

let planState = {
  legalEntity: 'Бургер БК',
  supplier: '',
  periodType: 'months',
  periodCount: 3,
  items: []
};

function parsePeriod(val) {
  if (val.startsWith('w')) return { type: 'weeks', count: parseInt(val.slice(1)) };
  if (val.startsWith('m')) return { type: 'months', count: parseInt(val.slice(1)) };
  return { type: 'months', count: 3 };
}

function generatePeriodHeaders() {
  const headers = [];
  const start = planState.startDate || new Date();

  if (planState.periodType === 'weeks') {
    for (let i = 0; i < planState.periodCount; i++) {
      const weekStart = new Date(start);
      weekStart.setDate(weekStart.getDate() + i * 7);
      const weekEnd = new Date(weekStart);
      weekEnd.setDate(weekEnd.getDate() + 6);
      const fmt = (d) => `${String(d.getDate()).padStart(2,'0')}.${String(d.getMonth()+1).padStart(2,'0')}`;
      headers.push({
        label: `Нед ${i + 1}`,
        sublabel: `${fmt(weekStart)}–${fmt(weekEnd)}`,
        periodLabel: `Неделя ${i + 1} (${fmt(weekStart)}–${fmt(weekEnd)})`
      });
    }
  } else {
    const monthNames = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
    for (let i = 0; i < planState.periodCount; i++) {
      const d = new Date(start.getFullYear(), start.getMonth() + i, 1);
      headers.push({
        label: monthNames[d.getMonth()],
        sublabel: String(d.getFullYear()),
        periodLabel: `${monthNames[d.getMonth()]} ${d.getFullYear()}`
      });
    }
  }
  return headers;
}

function consumptionPerPeriod(monthlyConsumption) {
  if (planState.periodType === 'weeks') return monthlyConsumption / 4.33;
  return monthlyConsumption;
}

/* ═══════ INIT ═══════ */

export function initPlanning() {
  const btn = document.getElementById('menuPlanning');
  const modal = document.getElementById('planningModal');
  const closeBtn = document.getElementById('closePlanning');
  if (!btn || !modal) return;

  btn.addEventListener('click', () => {
    modal.classList.remove('hidden');
    initPlanningUI();
  });
  closeBtn.addEventListener('click', () => modal.classList.add('hidden'));
  modal.addEventListener('click', (e) => { if (e.target === modal) modal.classList.add('hidden'); });
}

async function initPlanningUI() {
  const legalSelect = document.getElementById('planLegalEntity');
  const supplierSelect = document.getElementById('planSupplier');
  const periodSelect = document.getElementById('planMonths');
  const loadBtn = document.getElementById('planLoadProducts');

  const mainLegal = document.getElementById('legalEntity');
  if (mainLegal) legalSelect.value = mainLegal.value;
  planState.legalEntity = legalSelect.value;

  // Дата начала по умолчанию = сегодня
  const startDateInput = document.getElementById('planStartDate');
  if (startDateInput && !startDateInput.value) {
    const today = new Date();
    startDateInput.value = today.toISOString().slice(0, 10);
  }

  await loadPlanSuppliers(legalSelect.value, supplierSelect);

  legalSelect.onchange = async () => {
    planState.legalEntity = legalSelect.value;
    await loadPlanSuppliers(legalSelect.value, supplierSelect);
  };

  setupActionBtn('planLoadProducts', async () => {
    planState.supplier = supplierSelect.value;
    const period = parsePeriod(periodSelect.value);
    planState.periodType = period.type;
    planState.periodCount = period.count;

    // Дата начала
    const startDateInput = document.getElementById('planStartDate');
    planState.startDate = startDateInput.value ? new Date(startDateInput.value) : new Date();

    if (!planState.supplier) {
      showToast('Выберите поставщика', 'Для планирования нужен конкретный поставщик', 'error');
      return;
    }
    await loadPlanProducts();
    renderPlanTable();
  });

  setupActionBtn('planCopyBtn', copyPlanToClipboard);
  setupActionBtn('planSaveBtn', savePlanToHistory);
}

function setupActionBtn(id, handler) {
  const btn = document.getElementById(id);
  if (!btn) return;
  const newBtn = btn.cloneNode(true);
  btn.replaceWith(newBtn);
  newBtn.addEventListener('click', handler);
}

/* ═══════ SUPPLIERS / PRODUCTS ═══════ */

async function loadPlanSuppliers(legalEntity, selectEl) {
  selectEl.innerHTML = '<option value="">— Выберите поставщика —</option>';
  let query = supabase.from('products').select('supplier');
  if (legalEntity === 'Пицца Стар') {
    query = query.eq('legal_entity', 'Пицца Стар');
  } else {
    query = query.in('legal_entity', ['Бургер БК', 'Воглия Матта']);
  }
  const { data, error } = await query;
  if (error || !data) return;

  const suppliers = [...new Set(data.map(p => p.supplier).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'ru'));
  suppliers.forEach(s => {
    const opt = document.createElement('option');
    opt.value = s;
    opt.textContent = s;
    selectEl.appendChild(opt);
  });
}

async function loadPlanProducts() {
  const container = document.getElementById('planTableContainer');
  container.innerHTML = '<div style="text-align:center;padding:20px;"><div class="loading-spinner"></div></div>';

  let query = supabase.from('products').select('*').eq('supplier', planState.supplier).order('name');
  if (planState.legalEntity === 'Пицца Стар') {
    query = query.eq('legal_entity', 'Пицца Стар');
  } else {
    query = query.in('legal_entity', ['Бургер БК', 'Воглия Матта']);
  }

  const { data, error } = await query;
  if (error || !data) {
    container.innerHTML = '<div style="text-align:center;color:var(--error);">Ошибка загрузки</div>';
    return;
  }

  planState.items = data.map(p => ({
    sku: p.sku || '',
    name: p.name,
    qtyPerBox: p.qty_per_box || 1,
    boxesPerPallet: p.boxes_per_pallet || null,
    unitOfMeasure: p.unit_of_measure || 'шт',
    monthlyConsumption: 0,
    stockOnHand: 0,
    stockAtSupplier: 0,
    plan: []
  }));
}

/* ═══════ RENDER ═══════ */

function renderPlanTable() {
  const container = document.getElementById('planTableContainer');

  if (!planState.items.length) {
    container.innerHTML = `<div style="text-align:center;padding:30px;color:var(--muted);">Нет товаров у поставщика «${planState.supplier}»</div>`;
    return;
  }

  const headers = generatePeriodHeaders();

  let html = `
    <div class="plan-table-wrap">
      <table class="plan-table">
        <thead>
          <tr>
            <th class="plan-th-name">Товар</th>
            <th class="plan-th-num">Расход/мес</th>
            <th class="plan-th-num">Склад</th>
            <th class="plan-th-num">У постав.</th>
            ${headers.map(h => `<th class="plan-th-month">${h.label}<br><span style="font-weight:400;font-size:10px;opacity:0.7;">${h.sublabel}</span></th>`).join('')}
          </tr>
        </thead>
        <tbody>
  `;

  planState.items.forEach((item, idx) => {
    const skuPrefix = item.sku ? `<b style="color:var(--orange);margin-right:4px;">${item.sku}</b> ` : '';

    html += `
      <tr data-idx="${idx}">
        <td class="plan-td-name">
          <div style="font-weight:600;font-size:13px;color:var(--brown);">${skuPrefix}${item.name}</div>
          <div style="font-size:11px;color:var(--brown-light);">${item.qtyPerBox} ${item.unitOfMeasure}/кор</div>
        </td>
        <td class="plan-td-input">
          <input type="text" inputmode="numeric" class="plan-input plan-consumption" data-idx="${idx}" data-col="0" value="${item.monthlyConsumption || ''}" placeholder="0">
        </td>
        <td class="plan-td-input">
          <input type="text" inputmode="numeric" class="plan-input plan-stock" data-idx="${idx}" data-col="1" value="${item.stockOnHand || ''}" placeholder="0">
        </td>
        <td class="plan-td-input">
          <input type="text" inputmode="numeric" class="plan-input plan-supplier-stock" data-idx="${idx}" data-col="2" value="${item.stockAtSupplier || ''}" placeholder="0">
        </td>
        ${headers.map((h, mi) => `<td class="plan-td-result" data-idx="${idx}" data-month="${mi}">—</td>`).join('')}
      </tr>
    `;
  });

  html += `
        </tbody>
        <tfoot>
          <tr class="plan-totals">
            <td colspan="4" style="text-align:right;font-weight:700;padding-right:12px;">ИТОГО коробок:</td>
            ${headers.map((h, mi) => `<td class="plan-total-cell" data-month="${mi}">—</td>`).join('')}
          </tr>
        </tfoot>
      </table>
    </div>
  `;

  container.innerHTML = html;

  // Обработчики инпутов
  const allInputs = Array.from(container.querySelectorAll('.plan-input'));
  
  allInputs.forEach(input => {
    // Ввод — обновляем значение (при обычном числе — сразу)
    input.addEventListener('input', (e) => {
      const val = e.target.value.trim();
      // Если это простое число — сразу применяем
      if (/^\d+\.?\d*$/.test(val)) {
        applyInputValue(e.target, parseFloat(val));
      }
    });

    // Enter — вычислить выражение + перейти вниз
    input.addEventListener('keydown', (e) => {
      const idx = parseInt(e.target.dataset.idx);
      const col = parseInt(e.target.dataset.col);

      if (e.key === 'Enter') {
        e.preventDefault();
        evaluateAndApply(e.target);
        // Переход вниз
        navigatePlan(allInputs, idx, col, 1, 0);
        return;
      }

      if (e.key === 'Tab') {
        // Tab — стандартное поведение, но вычислим перед переходом
        evaluateAndApply(e.target);
        return;
      }

      // Стрелки
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        evaluateAndApply(e.target);
        navigatePlan(allInputs, idx, col, 1, 0);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        evaluateAndApply(e.target);
        navigatePlan(allInputs, idx, col, -1, 0);
      } else if (e.key === 'ArrowRight' && e.target.selectionStart === e.target.value.length) {
        e.preventDefault();
        evaluateAndApply(e.target);
        navigatePlan(allInputs, idx, col, 0, 1);
      } else if (e.key === 'ArrowLeft' && e.target.selectionStart === 0) {
        e.preventDefault();
        evaluateAndApply(e.target);
        navigatePlan(allInputs, idx, col, 0, -1);
      }
    });

    // Blur — вычислить выражение при уходе из поля
    input.addEventListener('blur', (e) => {
      evaluateAndApply(e.target);
    });
  });
}

function updateConsumptionHint(inputEl, monthlyVal) {
  if (planState.periodType !== 'weeks' || !monthlyVal) {
    const existing = inputEl.parentElement.querySelector('.plan-hint');
    if (existing) existing.remove();
    return;
  }
  const perWeek = Math.round(monthlyVal * 7 / 30.44);
  let hint = inputEl.parentElement.querySelector('.plan-hint');
  if (!hint) {
    hint = document.createElement('div');
    hint.className = 'plan-hint';
    inputEl.parentElement.appendChild(hint);
  }
  hint.textContent = `≈${nf.format(perWeek)}/нед`;
}

/**
 * Вычисляет математическое выражение в инпуте (500+300 → 800)
 * Поддержка: + - * /
 */
function evaluateAndApply(input) {
  const raw = input.value.trim();
  if (!raw) return;
  
  // Если уже простое число — просто применяем
  if (/^\d+\.?\d*$/.test(raw)) {
    applyInputValue(input, parseFloat(raw));
    return;
  }

  // Проверяем что строка — математическое выражение (цифры и +-*/)
  if (/^[\d\s+\-*/().]+$/.test(raw)) {
    try {
      // Безопасное вычисление через Function
      const result = new Function('return ' + raw)();
      if (typeof result === 'number' && isFinite(result)) {
        const rounded = Math.round(result * 100) / 100;
        input.value = rounded;
        applyInputValue(input, rounded);
      }
    } catch (e) {
      // Невалидное выражение — игнорируем
    }
  }
}

/**
 * Применяет числовое значение к state и пересчитывает
 */
function applyInputValue(input, value) {
  const idx = parseInt(input.dataset.idx);
  const item = planState.items[idx];
  if (!item) return;

  if (input.classList.contains('plan-consumption')) {
    item.monthlyConsumption = value;
    updateConsumptionHint(input, value);
  } else if (input.classList.contains('plan-stock')) {
    item.stockOnHand = value;
  } else if (input.classList.contains('plan-supplier-stock')) {
    item.stockAtSupplier = value;
  }
  
  recalcItem(idx);
}

/**
 * Навигация между ячейками: dRow = ±1 (вверх/вниз), dCol = ±1 (лево/право)
 */
function navigatePlan(allInputs, currentRow, currentCol, dRow, dCol) {
  const maxCol = 2; // 0=расход, 1=склад, 2=у постав.
  const maxRow = planState.items.length - 1;

  let newRow = currentRow + dRow;
  let newCol = currentCol + dCol;

  // Перенос между строками при горизонтальном движении
  if (newCol > maxCol) {
    newCol = 0;
    newRow++;
  } else if (newCol < 0) {
    newCol = maxCol;
    newRow--;
  }

  // Границы
  if (newRow < 0 || newRow > maxRow) return;

  const target = allInputs.find(inp => 
    parseInt(inp.dataset.idx) === newRow && parseInt(inp.dataset.col) === newCol
  );

  if (target) {
    target.focus();
    target.select();
  }
}

/* ═══════ CALCULATION ═══════ */

function recalcItem(idx) {
  const item = planState.items[idx];
  item.plan = [];
  
  // Начальный запас = склад + у поставщика
  let carryOver = item.stockOnHand + item.stockAtSupplier;

  for (let m = 0; m < planState.periodCount; m++) {
    const need = consumptionPerPeriod(item.monthlyConsumption);
    
    // Сколько покрывает текущий остаток
    const covered = Math.min(carryOver, need);
    const deficit = need - covered;
    
    // Заказ = дефицит, округлённый вверх до целых коробок
    const orderBoxes = item.qtyPerBox ? Math.ceil(deficit / item.qtyPerBox) : 0;
    const orderUnits = orderBoxes * item.qtyPerBox;
    
    // Остаток на следующий период = (было − расход + заказано)
    // orderUnits может быть чуть больше deficit из-за округления до коробки
    carryOver = carryOver - need + orderUnits;
    if (carryOver < 0) carryOver = 0;

    item.plan.push({ month: m, need: Math.round(need), deficit: Math.round(deficit), orderBoxes, orderUnits });
  }

  updatePlanCells(idx);
  updatePlanTotals();
}

function updatePlanCells(idx) {
  const item = planState.items[idx];
  const container = document.getElementById('planTableContainer');

  item.plan.forEach((p, mi) => {
    const cell = container.querySelector(`td.plan-td-result[data-idx="${idx}"][data-month="${mi}"]`);
    if (!cell) return;
    if (p.orderBoxes > 0) {
      cell.innerHTML = `<span class="plan-result-value">${p.orderBoxes}</span><span class="plan-result-sub">${nf.format(p.orderUnits)} ${item.unitOfMeasure}</span>`;
      cell.classList.add('plan-has-value');
    } else {
      cell.innerHTML = '<span class="plan-result-zero">—</span>';
      cell.classList.remove('plan-has-value');
    }
  });
}

function updatePlanTotals() {
  const container = document.getElementById('planTableContainer');
  for (let mi = 0; mi < planState.periodCount; mi++) {
    let totalBoxes = 0;
    planState.items.forEach(item => { if (item.plan[mi]) totalBoxes += item.plan[mi].orderBoxes; });
    const cell = container.querySelector(`.plan-total-cell[data-month="${mi}"]`);
    if (cell) {
      cell.textContent = totalBoxes > 0 ? nf.format(totalBoxes) : '—';
      cell.classList.toggle('plan-has-value', totalBoxes > 0);
    }
  }
}

/* ═══════ COPY / SAVE ═══════ */

function copyPlanToClipboard() {
  const headers = generatePeriodHeaders();
  const itemsWithPlan = planState.items.filter(item => item.plan.some(p => p.orderBoxes > 0));

  if (!itemsWithPlan.length) {
    showToast('Нет данных', 'Заполните расход и остатки', 'error');
    return;
  }

  let text = `Добрый день!\nПланирование для ${planState.legalEntity}, поставщик: ${planState.supplier}\n\n`;

  for (let mi = 0; mi < planState.periodCount; mi++) {
    const monthItems = itemsWithPlan.filter(item => item.plan[mi] && item.plan[mi].orderBoxes > 0);
    if (!monthItems.length) continue;
    text += `📅 ${headers[mi].periodLabel}:\n`;
    monthItems.forEach(item => {
      const p = item.plan[mi];
      text += `${item.sku ? item.sku + ' ' : ''}${item.name} (${nf.format(p.orderUnits)} ${item.unitOfMeasure}) - ${p.orderBoxes} коробок\n`;
    });
    text += '\n';
  }
  text += 'Спасибо!';

  navigator.clipboard.writeText(text).then(() => {
    showToast('Скопировано', 'План скопирован в буфер обмена', 'success');
  }).catch(() => showToast('Ошибка', 'Не удалось скопировать', 'error'));
}

async function savePlanToHistory() {
  const itemsWithPlan = planState.items.filter(item => item.plan.some(p => p.orderBoxes > 0));
  if (!itemsWithPlan.length) {
    showToast('Нет данных', 'Заполните расход и остатки', 'error');
    return;
  }

  const planData = {
    legal_entity: planState.legalEntity,
    supplier: planState.supplier,
    period_type: planState.periodType,
    period_count: planState.periodCount,
    created_at: new Date().toISOString(),
    items: itemsWithPlan.map(item => ({
      sku: item.sku, name: item.name, qty_per_box: item.qtyPerBox,
      unit_of_measure: item.unitOfMeasure, monthly_consumption: item.monthlyConsumption,
      stock_on_hand: item.stockOnHand, stock_at_supplier: item.stockAtSupplier,
      plan: item.plan.map(p => ({ month: p.month, order_boxes: p.orderBoxes, order_units: p.orderUnits }))
    }))
  };

  const { error } = await supabase.from('plans').insert([planData]);
  if (error) {
    console.warn('Supabase plans error, localStorage fallback:', error);
    const plans = JSON.parse(localStorage.getItem('bk_plans') || '[]');
    plans.unshift(planData);
    localStorage.setItem('bk_plans', JSON.stringify(plans.slice(0, 50)));
    showToast('План сохранён', 'Сохранено локально', 'success');
    return;
  }
  const unitLabel = planState.periodType === 'weeks' ? 'нед.' : 'мес.';
  showToast('План сохранён', `${itemsWithPlan.length} позиций на ${planState.periodCount} ${unitLabel}`, 'success');
}