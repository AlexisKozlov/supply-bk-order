/**
 * Модуль планирования заказов на 1-3 месяца
 * planning.js — отдельный файл, не нагружает ui.js
 * 
 * Логика:
 * 1. Выбираем поставщика → загружаем его товары из БД
 * 2. Для каждого товара вводим: расход/мес, остаток на складе, остаток у поставщика
 * 3. Система считает помесячно: сколько нужно заказать (с округлением до коробок)
 * 4. Итого по каждому месяцу + общий итог
 * 5. Можно сохранить план и скопировать текст для отправки поставщику
 */

import { supabase } from './supabase.js';
import { showToast } from './modals.js';

const nf = new Intl.NumberFormat('ru-RU');

let planState = {
  legalEntity: 'Бургер БК',
  supplier: '',
  months: 3,
  startDate: new Date(),
  items: []
  // item: { sku, name, qtyPerBox, boxesPerPallet, unitOfMeasure, 
  //          monthlyConsumption, stockOnHand, stockAtSupplier, 
  //          plan: [{month, need, order, orderBoxes}] }
};

/**
 * Инициализация модуля планирования
 */
export function initPlanning() {
  const btn = document.getElementById('menuPlanning');
  const modal = document.getElementById('planningModal');
  const closeBtn = document.getElementById('closePlanning');

  if (!btn || !modal) return;

  btn.addEventListener('click', () => {
    modal.classList.remove('hidden');
    initPlanningUI();
  });

  closeBtn.addEventListener('click', () => {
    modal.classList.add('hidden');
  });

  // Закрытие по фону
  modal.addEventListener('click', (e) => {
    if (e.target === modal) modal.classList.add('hidden');
  });
}

/**
 * Инициализация UI внутри модалки
 */
async function initPlanningUI() {
  const legalSelect = document.getElementById('planLegalEntity');
  const supplierSelect = document.getElementById('planSupplier');
  const monthsSelect = document.getElementById('planMonths');
  const loadBtn = document.getElementById('planLoadProducts');

  // Устанавливаем текущие значения из основного интерфейса
  const mainLegal = document.getElementById('legalEntity');
  if (mainLegal) legalSelect.value = mainLegal.value;

  planState.legalEntity = legalSelect.value;

  // Загружаем поставщиков
  await loadPlanSuppliers(legalSelect.value, supplierSelect);

  // Слушатели
  legalSelect.onchange = async () => {
    planState.legalEntity = legalSelect.value;
    await loadPlanSuppliers(legalSelect.value, supplierSelect);
  };

  // Убираем старый и ставим новый listener
  const newLoadBtn = loadBtn.cloneNode(true);
  loadBtn.replaceWith(newLoadBtn);
  newLoadBtn.addEventListener('click', async () => {
    planState.supplier = supplierSelect.value;
    planState.months = parseInt(monthsSelect.value) || 3;

    if (!planState.supplier) {
      showToast('Выберите поставщика', 'Для планирования нужен конкретный поставщик', 'error');
      return;
    }

    await loadPlanProducts();
    renderPlanTable();
  });

  // Копировать / Сохранить
  const copyBtn = document.getElementById('planCopyBtn');
  const saveBtn = document.getElementById('planSaveBtn');

  if (copyBtn) {
    const newCopy = copyBtn.cloneNode(true);
    copyBtn.replaceWith(newCopy);
    newCopy.addEventListener('click', copyPlanToClipboard);
  }

  if (saveBtn) {
    const newSave = saveBtn.cloneNode(true);
    saveBtn.replaceWith(newSave);
    newSave.addEventListener('click', savePlanToHistory);
  }
}

/**
 * Загрузка поставщиков для планирования
 */
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

/**
 * Загрузка товаров поставщика из БД
 */
async function loadPlanProducts() {
  const container = document.getElementById('planTableContainer');
  container.innerHTML = '<div style="text-align:center;padding:20px;"><div class="loading-spinner"></div></div>';

  let query = supabase
    .from('products')
    .select('*')
    .eq('supplier', planState.supplier)
    .order('name');

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

/**
 * Рендер таблицы планирования
 */
function renderPlanTable() {
  const container = document.getElementById('planTableContainer');

  if (!planState.items.length) {
    container.innerHTML = '<div style="text-align:center;padding:30px;color:var(--muted);">Нет товаров у поставщика «' + planState.supplier + '»</div>';
    return;
  }

  // Генерируем заголовки месяцев
  const monthNames = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
  const now = new Date();
  const monthHeaders = [];
  for (let i = 0; i < planState.months; i++) {
    const d = new Date(now.getFullYear(), now.getMonth() + 1 + i, 1);
    monthHeaders.push({
      label: monthNames[d.getMonth()] + ' ' + d.getFullYear(),
      month: d.getMonth(),
      year: d.getFullYear()
    });
  }

  let html = `
    <div class="plan-table-wrap">
      <table class="plan-table">
        <thead>
          <tr>
            <th class="plan-th-name">Товар</th>
            <th class="plan-th-num">Расход/мес</th>
            <th class="plan-th-num">Склад</th>
            <th class="plan-th-num">У постав.</th>
            ${monthHeaders.map(m => `<th class="plan-th-month">${m.label}<br><span style="font-weight:400;font-size:10px;opacity:0.7;">коробок</span></th>`).join('')}
          </tr>
        </thead>
        <tbody>
  `;

  planState.items.forEach((item, idx) => {
    html += `
      <tr data-idx="${idx}">
        <td class="plan-td-name">
          <div style="font-weight:600;font-size:13px;">${item.name}</div>
          <div style="font-size:11px;color:var(--muted);">${item.sku ? item.sku + ' · ' : ''}${item.qtyPerBox} ${item.unitOfMeasure}/кор</div>
        </td>
        <td class="plan-td-input">
          <input type="number" class="plan-input plan-consumption" data-idx="${idx}" 
                 value="${item.monthlyConsumption || ''}" placeholder="0"
                 title="Расход за месяц (${item.unitOfMeasure})">
        </td>
        <td class="plan-td-input">
          <input type="number" class="plan-input plan-stock" data-idx="${idx}" 
                 value="${item.stockOnHand || ''}" placeholder="0"
                 title="Остаток на складе (${item.unitOfMeasure})">
        </td>
        <td class="plan-td-input">
          <input type="number" class="plan-input plan-supplier-stock" data-idx="${idx}" 
                 value="${item.stockAtSupplier || ''}" placeholder="0"
                 title="Остаток у поставщика (${item.unitOfMeasure})">
        </td>
        ${monthHeaders.map((m, mi) => `<td class="plan-td-result" data-idx="${idx}" data-month="${mi}">—</td>`).join('')}
      </tr>
    `;
  });

  html += `
        </tbody>
        <tfoot>
          <tr class="plan-totals">
            <td colspan="4" style="text-align:right;font-weight:700;padding-right:12px;">ИТОГО коробок:</td>
            ${monthHeaders.map((m, mi) => `<td class="plan-total-cell" data-month="${mi}">—</td>`).join('')}
          </tr>
        </tfoot>
      </table>
    </div>
  `;

  container.innerHTML = html;

  // Вешаем обработчики на инпуты
  container.querySelectorAll('.plan-input').forEach(input => {
    input.addEventListener('input', (e) => {
      const idx = parseInt(e.target.dataset.idx);
      const item = planState.items[idx];

      if (e.target.classList.contains('plan-consumption')) {
        item.monthlyConsumption = parseFloat(e.target.value) || 0;
      } else if (e.target.classList.contains('plan-stock')) {
        item.stockOnHand = parseFloat(e.target.value) || 0;
      } else if (e.target.classList.contains('plan-supplier-stock')) {
        item.stockAtSupplier = parseFloat(e.target.value) || 0;
      }

      recalcItem(idx);
    });
  });
}

/**
 * Пересчёт одного товара
 */
function recalcItem(idx) {
  const item = planState.items[idx];
  const months = planState.months;

  item.plan = [];
  let availableStock = item.stockOnHand + item.stockAtSupplier;

  for (let m = 0; m < months; m++) {
    const need = item.monthlyConsumption;
    let deficit = need - availableStock;
    if (deficit < 0) deficit = 0;

    // Округляем до коробок вверх
    const orderBoxes = item.qtyPerBox ? Math.ceil(deficit / item.qtyPerBox) : 0;
    const orderUnits = orderBoxes * item.qtyPerBox;

    item.plan.push({
      month: m,
      need: need,
      deficit: deficit,
      orderBoxes: orderBoxes,
      orderUnits: orderUnits
    });

    // Остаток переносится на следующий месяц
    // Если был запас — вычитаем расход, если был заказ — прибавляем
    availableStock = Math.max(0, availableStock - need) + orderUnits;
  }

  updatePlanCells(idx);
  updatePlanTotals();
}

/**
 * Обновление ячеек результатов для одного товара
 */
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

/**
 * Обновление итоговой строки
 */
function updatePlanTotals() {
  const container = document.getElementById('planTableContainer');

  for (let mi = 0; mi < planState.months; mi++) {
    let totalBoxes = 0;
    planState.items.forEach(item => {
      if (item.plan[mi]) {
        totalBoxes += item.plan[mi].orderBoxes;
      }
    });

    const cell = container.querySelector(`.plan-total-cell[data-month="${mi}"]`);
    if (cell) {
      cell.textContent = totalBoxes > 0 ? nf.format(totalBoxes) : '—';
      cell.classList.toggle('plan-has-value', totalBoxes > 0);
    }
  }
}

/**
 * Копирование плана в буфер обмена
 */
function copyPlanToClipboard() {
  const monthNames = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
  const now = new Date();

  const itemsWithPlan = planState.items.filter(item =>
    item.plan.some(p => p.orderBoxes > 0)
  );

  if (!itemsWithPlan.length) {
    showToast('Нет данных', 'Заполните расход и остатки для расчёта', 'error');
    return;
  }

  let text = `Добрый день!\n`;
  text += `Планирование заказов для ${planState.legalEntity}, поставщик: ${planState.supplier}\n\n`;

  for (let mi = 0; mi < planState.months; mi++) {
    const d = new Date(now.getFullYear(), now.getMonth() + 1 + mi, 1);
    const monthLabel = monthNames[d.getMonth()] + ' ' + d.getFullYear();

    const monthItems = itemsWithPlan.filter(item => item.plan[mi] && item.plan[mi].orderBoxes > 0);
    if (!monthItems.length) continue;

    text += `📅 ${monthLabel}:\n`;
    monthItems.forEach(item => {
      const p = item.plan[mi];
      text += `${item.sku ? item.sku + ' ' : ''}${item.name} (${nf.format(p.orderUnits)} ${item.unitOfMeasure}) - ${p.orderBoxes} коробок\n`;
    });
    text += '\n';
  }

  text += 'Спасибо!';

  navigator.clipboard.writeText(text).then(() => {
    showToast('Скопировано', 'План скопирован в буфер обмена', 'success');
  }).catch(() => {
    showToast('Ошибка', 'Не удалось скопировать', 'error');
  });
}

/**
 * Сохранение плана в Supabase
 */
async function savePlanToHistory() {
  const itemsWithPlan = planState.items.filter(item =>
    item.plan.some(p => p.orderBoxes > 0)
  );

  if (!itemsWithPlan.length) {
    showToast('Нет данных', 'Заполните расход и остатки для расчёта', 'error');
    return;
  }

  const planData = {
    legal_entity: planState.legalEntity,
    supplier: planState.supplier,
    months: planState.months,
    created_at: new Date().toISOString(),
    items: itemsWithPlan.map(item => ({
      sku: item.sku,
      name: item.name,
      qty_per_box: item.qtyPerBox,
      unit_of_measure: item.unitOfMeasure,
      monthly_consumption: item.monthlyConsumption,
      stock_on_hand: item.stockOnHand,
      stock_at_supplier: item.stockAtSupplier,
      plan: item.plan.map(p => ({
        month: p.month,
        order_boxes: p.orderBoxes,
        order_units: p.orderUnits
      }))
    }))
  };

  const { error } = await supabase
    .from('plans')
    .insert([planData]);

  if (error) {
    // Если таблица plans не существует — сохраняем в localStorage
    console.warn('Supabase plans table error, saving to localStorage:', error);
    const plans = JSON.parse(localStorage.getItem('bk_plans') || '[]');
    plans.unshift(planData);
    localStorage.setItem('bk_plans', JSON.stringify(plans.slice(0, 50)));
    showToast('План сохранён', 'Сохранено локально', 'success');
    return;
  }

  showToast('План сохранён', `${itemsWithPlan.length} позиций на ${planState.months} мес.`, 'success');
}