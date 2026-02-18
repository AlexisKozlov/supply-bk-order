/**
 * Модуль планирования заказов
 * planning.js — отдельный файл
 * 
 * Поддержка периодов: 1-12 недель и 1-3 месяца
 */

import { supabase } from './supabase.js';
import { showToast, customConfirm } from './modals.js';
import { showImportDialog } from './import-stock.js';
import { validatePlanConsumption, resetConsumptionCache } from './data-validation.js';

const nf = new Intl.NumberFormat('ru-RU');

let planState = {
  legalEntity: 'Бургер БК',
  supplier: '',
  periodType: 'months',
  periodCount: 3,
  inputUnit: 'pieces',
  startDate: null,
  editingPlanId: null, // ID плана при редактировании
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
    // Недели: первая неделя — от startDate до конца той недели (воскресенье)
    // Остальные — полные 7-дневные
    const dayOfWeek = start.getDay(); // 0=вс, 1=пн...
    const daysLeftInWeek = dayOfWeek === 0 ? 0 : 7 - dayOfWeek; // дней до конца недели (вс)
    
    const fmt = (d) => `${String(d.getDate()).padStart(2,'0')}.${String(d.getMonth()+1).padStart(2,'0')}`;
    
    // Текущая неделя (остаток)
    const firstWeekEnd = new Date(start);
    firstWeekEnd.setDate(firstWeekEnd.getDate() + Math.max(daysLeftInWeek - 1, 0));
    const firstRatio = Math.max(daysLeftInWeek, 1) / 7;
    headers.push({
      label: `Тек. нед`,
      sublabel: `${fmt(start)}–${fmt(firstWeekEnd)}`,
      periodLabel: `Текущая неделя (${fmt(start)}–${fmt(firstWeekEnd)})`,
      ratio: firstRatio
    });
    
    // Следующие полные недели
    for (let i = 0; i < planState.periodCount; i++) {
      const weekStart = new Date(firstWeekEnd);
      weekStart.setDate(weekStart.getDate() + 1 + i * 7);
      const weekEnd = new Date(weekStart);
      weekEnd.setDate(weekEnd.getDate() + 6);
      headers.push({
        label: `Нед ${i + 1}`,
        sublabel: `${fmt(weekStart)}–${fmt(weekEnd)}`,
        periodLabel: `Неделя ${i + 1} (${fmt(weekStart)}–${fmt(weekEnd)})`,
        ratio: 1
      });
    }
  } else {
    const monthNames = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
    
    // Текущий месяц — остаток дней
    const daysInCurrentMonth = new Date(start.getFullYear(), start.getMonth() + 1, 0).getDate();
    const daysLeft = daysInCurrentMonth - start.getDate() + 1; // включая сегодня
    const firstRatio = daysLeft / daysInCurrentMonth;
    
    headers.push({
      label: monthNames[start.getMonth()],
      sublabel: `ост. ${daysLeft} дн.`,
      periodLabel: `${monthNames[start.getMonth()]} ${start.getFullYear()} (остаток ${daysLeft} дн.)`,
      ratio: firstRatio
    });
    
    // Следующие N полных месяцев
    for (let i = 1; i <= planState.periodCount; i++) {
      const d = new Date(start.getFullYear(), start.getMonth() + i, 1);
      headers.push({
        label: monthNames[d.getMonth()],
        sublabel: String(d.getFullYear()),
        periodLabel: `${monthNames[d.getMonth()]} ${d.getFullYear()}`,
        ratio: 1
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
  
  // Загрузка плана из истории
  document.addEventListener('history:load-plan', (e) => {
    const { plan } = e.detail;
    if (!plan) return;
    
    modal.classList.remove('hidden');
    
    planState.legalEntity = plan.legal_entity || 'Бургер БК';
    planState.supplier = plan.supplier || '';
    planState.periodType = plan.period_type || 'months';
    planState.periodCount = plan.period_count || 3;
    planState.startDate = plan.start_date ? new Date(plan.start_date) : new Date();
    planState.editingPlanId = plan.id || null; // #4 режим редактирования
    
    document.getElementById('planLegalEntity').value = planState.legalEntity;
    const periodSelect = document.getElementById('planMonths');
    periodSelect.value = planState.periodType === 'weeks' ? `w${planState.periodCount}` : `m${planState.periodCount}`;
    document.getElementById('planStartDate').value = planState.startDate.toISOString().slice(0, 10);
    
    // Загружаем поставщиков и ставим значение
    const supplierEl = document.getElementById('planSupplier');
    loadPlanSuppliers(planState.legalEntity, supplierEl).then(() => {
      supplierEl.value = planState.supplier;
    });
    
    // Восстанавливаем товары с сохранёнными plan данными
    planState.items = (plan.items || []).map(i => ({
      sku: i.sku || '',
      name: i.name || '',
      qtyPerBox: i.qty_per_box || 1,
      boxesPerPallet: i.boxes_per_pallet || null,
      unitOfMeasure: i.unit_of_measure || 'шт',
      monthlyConsumption: i.monthly_consumption || 0,
      stockOnHand: i.stock_on_hand || 0,
      stockAtSupplier: i.stock_at_supplier || 0,
      plan: (i.plan || []).map(p => ({
        month: p.month,
        need: 0,
        deficit: 0,
        orderBoxes: p.order_boxes || 0,
        orderUnits: p.order_units || 0,
        locked: p.locked || false
      }))
    }));
    
    renderPlanTable();
    // Пересчитываем с учётом locked — с начала
    planState.items.forEach((_, idx) => recalcItem(idx, 0));
    triggerPlanValidation();
    showToast('План загружен', `${plan.supplier} — ${planState.items.length} позиций`, 'success');
  });
}

let planningUIInitialized = false;

async function initPlanningUI() {
  const legalSelect = document.getElementById('planLegalEntity');
  const supplierSelect = document.getElementById('planSupplier');
  const periodSelect = document.getElementById('planMonths');

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

  // Подписки на события — ТОЛЬКО один раз
  if (!planningUIInitialized) {
    planningUIInitialized = true;

    legalSelect.addEventListener('change', async () => {
      planState.legalEntity = legalSelect.value;
      await loadPlanSuppliers(legalSelect.value, supplierSelect);
      notifyPlanParamsChanged();
    });

    supplierSelect.addEventListener('change', () => {
      notifyPlanParamsChanged();
    });

    periodSelect.addEventListener('change', () => {
      if (planState.items.length) {
        const period = parsePeriod(periodSelect.value);
        planState.periodType = period.type;
        planState.periodCount = period.count;
        renderPlanTable();
        planState.items.forEach((_, idx) => recalcItem(idx, 0));
        showToast('Период обновлён', 'Данные пересчитаны', 'info');
      }
    });

    document.getElementById('planStartDate')?.addEventListener('change', () => {
      if (planState.items.length) {
        const sdi = document.getElementById('planStartDate');
        planState.startDate = sdi.value ? new Date(sdi.value) : new Date();
        renderPlanTable();
        planState.items.forEach((_, idx) => recalcItem(idx, 0));
        showToast('Дата обновлена', 'Данные пересчитаны', 'info');
      }
    });

    document.getElementById('planUnit')?.addEventListener('change', () => {
      if (planState.items.length) {
        const oldUnit = planState.inputUnit;
        const newUnit = document.getElementById('planUnit').value;
        
        if (oldUnit !== newUnit) {
          // Конвертируем все введённые значения в новые единицы
          planState.items.forEach(item => {
            const qpb = item.qtyPerBox || 1;
            if (oldUnit === 'pieces' && newUnit === 'boxes') {
              // штуки → коробки
              item.monthlyConsumption = item.monthlyConsumption ? Math.round(item.monthlyConsumption / qpb * 100) / 100 : 0;
              item.stockOnHand = item.stockOnHand ? Math.round(item.stockOnHand / qpb * 100) / 100 : 0;
              item.stockAtSupplier = item.stockAtSupplier ? Math.round(item.stockAtSupplier / qpb * 100) / 100 : 0;
            } else if (oldUnit === 'boxes' && newUnit === 'pieces') {
              // коробки → штуки
              item.monthlyConsumption = Math.round(item.monthlyConsumption * qpb);
              item.stockOnHand = Math.round(item.stockOnHand * qpb);
              item.stockAtSupplier = Math.round(item.stockAtSupplier * qpb);
            }
            // Сбрасываем locked периоды — пересчитаем заново
            item.plan.forEach(p => { p.locked = false; });
          });
        }
        
        planState.inputUnit = newUnit;
        resetConsumptionCache(); // единицы изменились — сбросить кеш
        renderPlanTable();
        planState.items.forEach((_, idx) => recalcItem(idx, 0));
        triggerPlanValidation();
        showToast('Единицы обновлены', `Данные пересчитаны в ${newUnit === 'boxes' ? 'коробки' : 'штуки'}`, 'info');
      }
    });

    // Селектор проверки данных расхода
    document.getElementById('planDataValidation')?.addEventListener('change', () => {
      const container = document.getElementById('planTableContainer');
      if (document.getElementById('planDataValidation').value === 'true') {
        triggerPlanValidation();
      } else {
        // Немедленно убираем все предупреждения
        container.querySelectorAll('.consumption-warning').forEach(el => {
          el.classList.remove('consumption-warning');
          el.title = '';
        });
      }
    });

    setupActionBtn('planLoadProducts', async () => {
    planState.supplier = supplierSelect.value;
    const period = parsePeriod(periodSelect.value);
    planState.periodType = period.type;
    planState.periodCount = period.count;

    // Дата начала
    const startDateInput = document.getElementById('planStartDate');
    planState.startDate = startDateInput.value ? new Date(startDateInput.value) : new Date();

    // Единицы ввода
    const unitSelect = document.getElementById('planUnit');
    planState.inputUnit = unitSelect ? unitSelect.value : 'pieces';

    if (!planState.supplier) {
      showToast('Выберите поставщика', 'Для планирования нужен конкретный поставщик', 'error');
      return;
    }
    await loadPlanProducts();
    renderPlanTable();
    // Запускаем проверку данных если включена
    triggerPlanValidation();
  });

  setupActionBtn('planCopyBtn', copyPlanToClipboard);
  setupActionBtn('planSaveBtn', savePlanToHistory);
  setupActionBtn('planExcelBtn', exportPlanToExcel);
  setupActionBtn('planImportBtn', () => {
    if (!planState.items.length) {
      showToast('Нет товаров', 'Сначала загрузите товары поставщика', 'info');
      return;
    }
    showImportDialog('planning', planState.items, (updatedItems) => {
      planState.items = updatedItems;
      renderPlanTable();
      planState.items.forEach((_, idx) => recalcItem(idx));
      triggerPlanValidation();
    }, planState.legalEntity);
  });

  // Кнопка "Подставить расход" — средний из последних 2 заказов
  setupActionBtn('planFillConsumptionBtn', async () => {
    if (!planState.items.length) {
      showToast('Нет товаров', 'Сначала загрузите товары поставщика', 'info');
      return;
    }
    if (!planState.supplier) {
      showToast('Нет поставщика', 'Выберите поставщика', 'error');
      return;
    }

    const avgMap = await loadAvgConsumption(planState.supplier, planState.legalEntity, planState.inputUnit);
    if (!avgMap.size) {
      showToast('Нет истории', 'Не найдены заказы для этого поставщика', 'info');
      return;
    }

    let filled = 0;
    planState.items.forEach(item => {
      if (item.sku) {
        const avg = avgMap.get(item.sku);
        if (avg && avg > 0) {
          item.monthlyConsumption = Math.round(avg);
          filled++;
        }
      }
    });

    renderPlanTable();
    planState.items.forEach((_, idx) => recalcItem(idx, 0));
    triggerPlanValidation();
    showToast('Расход подставлен', `${filled} из ${planState.items.length} позиций из истории заказов`, 'success');
  });

  // Кнопка "Обнулить всё"
  setupActionBtn('planClearBtn', async () => {
    if (!planState.items.length) {
      showToast('Нет данных', 'Нечего обнулять', 'info');
      return;
    }

    const confirmed = await customConfirm('Обнулить все данные?', 'Расход, остатки и рассчитанные заказы будут сброшены. Товары останутся.');
    if (!confirmed) return;

    planState.items.forEach(item => {
      item.monthlyConsumption = 0;
      item.stockOnHand = 0;
      item.stockAtSupplier = 0;
      item.plan = [];
    });

    renderPlanTable();
    showToast('Данные обнулены', 'Все значения сброшены', 'success');
  });

  } // end if (!planningUIInitialized)
}

function notifyPlanParamsChanged() {
  if (planState.items.length) {
    showToast('Параметры изменены', 'Нажмите «Загрузить товары» для обновления', 'info');
  }
}

/**
 * Запуск проверки данных расхода в планировании
 */
function triggerPlanValidation() {
  const container = document.getElementById('planTableContainer');
  if (!container || !planState.items.length) return;
  validatePlanConsumption(planState, container);
}

function setupActionBtn(id, handler) {
  const btn = document.getElementById(id);
  if (!btn) return;
  const newBtn = btn.cloneNode(true);
  btn.replaceWith(newBtn);
  newBtn.addEventListener('click', handler);
}

/* ═══════ SUPPLIERS / PRODUCTS ═══════ */

/**
 * Загрузка среднего расхода по SKU из последних 2 заказов поставщику
 * Возвращает Map<sku, avgConsumption>
 */
async function loadAvgConsumption(supplier, legalEntity, currentUnit) {
  const avgMap = new Map();
  
  const { data, error } = await supabase
    .from('orders')
    .select('unit, period_days, order_items(sku, consumption_period, qty_per_box)')
    .eq('legal_entity', legalEntity)
    .eq('supplier', supplier)
    .order('created_at', { ascending: false })
    .limit(2);

  if (error || !data || !data.length) return avgMap;

  const bySku = {};
  data.forEach(order => {
    const orderUnit = order.unit || 'pieces';
    const periodDays = order.period_days || 30;
    
    (order.order_items || []).forEach(item => {
      if (!item.sku || !item.consumption_period) return;
      let val = item.consumption_period;
      const qtyPerBox = item.qty_per_box || 1;

      // Нормализуем к месячному расходу (30 дней)
      const dailyRate = val / periodDays;
      let monthlyVal = dailyRate * 30;

      // Конвертируем в текущие единицы
      if (orderUnit === 'pieces' && currentUnit === 'boxes') {
        monthlyVal = monthlyVal / qtyPerBox;
      } else if (orderUnit === 'boxes' && currentUnit === 'pieces') {
        monthlyVal = monthlyVal * qtyPerBox;
      }

      if (!bySku[item.sku]) bySku[item.sku] = [];
      bySku[item.sku].push(monthlyVal);
    });
  });

  Object.entries(bySku).forEach(([sku, vals]) => {
    avgMap.set(sku, vals.reduce((a, b) => a + b, 0) / vals.length);
  });

  return avgMap;
}

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

  const unitLabel = planState.inputUnit === 'boxes' ? 'кор' : 'шт';

  let html = `
    <div class="plan-table-wrap">
      <table class="plan-table">
        <thead>
          <tr>
            <th class="plan-th-name">Товар</th>
            <th class="plan-th-num">Расход/мес (${unitLabel})</th>
            <th class="plan-th-num">Склад (${unitLabel})</th>
            <th class="plan-th-num">У постав. (${unitLabel})</th>
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
          <div style="font-weight:600;font-size:13px;color:var(--text);">${skuPrefix}${item.name}</div>
          <div style="font-size:11px;color:var(--brown-light);">${item.qtyPerBox} ${item.unitOfMeasure}/кор${item.boxesPerPallet ? ' · ' + item.boxesPerPallet + ' кор/пал' : ''}</div>
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
    // Мгновенная проверка данных
    triggerPlanValidation();
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

/* -- removed roundPlanToPallet, now per-period in updatePlanCells -- */

/* ═══════ CALCULATION ═══════ */

function recalcItem(idx, fromMonth = 0) {
  const item = planState.items[idx];
  const headers = generatePeriodHeaders();
  
  // Если план пуст или длина не совпадает — пересоздаём с сохранением locked
  if (!item.plan.length || item.plan.length !== headers.length) {
    const oldPlan = item.plan || [];
    item.plan = headers.map((_, m) => {
      const old = oldPlan[m];
      if (old && old.locked) {
        return { ...old, month: m };
      }
      return { month: m, need: 0, deficit: 0, orderBoxes: 0, orderUnits: 0, locked: false };
    });
  }
  
  const toUnits = (val) => {
    if (planState.inputUnit === 'boxes') return val * item.qtyPerBox;
    return val;
  };
  
  const monthlyUnits = toUnits(item.monthlyConsumption);
  const weeklyUnits = monthlyUnits / 4.33;
  
  // Считаем carryOver до fromMonth
  let carryOver = toUnits(item.stockOnHand) + toUnits(item.stockAtSupplier);
  for (let m = 0; m < fromMonth && m < headers.length; m++) {
    const ratio = headers[m].ratio;
    const baseNeed = planState.periodType === 'weeks' ? weeklyUnits : monthlyUnits;
    const need = baseNeed * ratio;
    const orderUnits = item.plan[m].orderBoxes * item.qtyPerBox;
    carryOver = carryOver - need + orderUnits;
    if (carryOver < 0) carryOver = 0;
  }

  for (let m = fromMonth; m < headers.length; m++) {
    const ratio = headers[m].ratio;
    const baseNeed = planState.periodType === 'weeks' ? weeklyUnits : monthlyUnits;
    const need = baseNeed * ratio;
    
    const covered = Math.min(carryOver, need);
    const deficit = need - covered;
    
    if (item.plan[m].locked) {
      // Locked — не пересчитываем orderBoxes, но обновляем need/deficit
      item.plan[m].need = Math.round(need);
      item.plan[m].deficit = Math.round(deficit);
      item.plan[m].orderUnits = item.plan[m].orderBoxes * item.qtyPerBox;
      carryOver = carryOver - need + item.plan[m].orderUnits;
    } else {
      const orderBoxes = item.qtyPerBox ? Math.ceil(deficit / item.qtyPerBox) : 0;
      const orderUnits = orderBoxes * item.qtyPerBox;
      item.plan[m] = { month: m, need: Math.round(need), deficit: Math.round(deficit), orderBoxes, orderUnits, locked: false };
      carryOver = carryOver - need + orderUnits;
    }
    if (carryOver < 0) carryOver = 0;
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
    
    const lockedClass = p.locked ? ' plan-cell-locked' : '';
    
    if (p.orderBoxes > 0) {
      // #8 Кнопка паллеты на каждый период (кроме текущего = mi 0)
      let palletBtn = '';
      if (mi > 0 && item.boxesPerPallet && p.orderBoxes % item.boxesPerPallet !== 0) {
        const pallets = Math.ceil(p.orderBoxes / item.boxesPerPallet);
        const rounded = pallets * item.boxesPerPallet;
        palletBtn = `<span class="plan-pallet-period" data-idx="${idx}" data-month="${mi}" title="Округлить до ${pallets} пал (${rounded} кор)">⬆</span>`;
      }
      const resetBtn = p.locked ? `<span class="plan-reset-cell" data-idx="${idx}" data-month="${mi}" title="Сбросить ручное значение">✕</span>` : '';
      
      cell.innerHTML = `<span class="plan-result-value${lockedClass}">${p.orderBoxes} кор ${palletBtn}${resetBtn}</span><span class="plan-result-sub">${nf.format(p.orderUnits)} ${item.unitOfMeasure}</span>`;
      cell.classList.add('plan-has-value');
    } else {
      cell.innerHTML = '<span class="plan-result-zero">—</span>';
      cell.classList.remove('plan-has-value');
    }
    
    cell.classList.toggle('plan-cell-locked', !!p.locked);
  });
  
  // Привязываем обработчики после рендера
  bindCellHandlers(idx, container);
}

function bindCellHandlers(idx, container) {
  const item = planState.items[idx];
  
  // Dblclick → редактирование
  container.querySelectorAll(`td.plan-td-result[data-idx="${idx}"]`).forEach(cell => {
    cell.ondblclick = () => {
      const mi = parseInt(cell.dataset.month);
      const p = item.plan[mi];
      if (!p) return;
      
      const currentVal = p.orderBoxes;
      const input = document.createElement('input');
      input.type = 'number';
      input.value = currentVal;
      input.className = 'plan-edit-input';
      input.style.cssText = 'width:60px;text-align:center;font-size:13px;font-weight:700;padding:2px 4px;border:2px solid var(--orange);border-radius:4px;';
      
      cell.innerHTML = '';
      cell.appendChild(input);
      input.focus();
      input.select();
      
      const applyEdit = () => {
        const newVal = parseInt(input.value) || 0;
        p.orderBoxes = newVal;
        p.orderUnits = newVal * item.qtyPerBox;
        p.locked = true;
        // Пересчитываем только СЛЕДУЮЩИЕ периоды
        recalcItem(idx, mi + 1);
      };
      
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { applyEdit(); }
        if (e.key === 'Escape') { updatePlanCells(idx); }
      });
      input.addEventListener('blur', applyEdit);
    };
  });
  
  // #8 Кнопки паллет по периодам
  container.querySelectorAll(`.plan-pallet-period[data-idx="${idx}"]`).forEach(btn => {
    btn.onclick = (e) => {
      e.stopPropagation();
      const mi = parseInt(btn.dataset.month);
      const p = item.plan[mi];
      if (!p || !item.boxesPerPallet) return;
      
      const pallets = Math.ceil(p.orderBoxes / item.boxesPerPallet);
      p.orderBoxes = pallets * item.boxesPerPallet;
      p.orderUnits = p.orderBoxes * item.qtyPerBox;
      p.locked = true;
      // Пересчитываем следующие
      recalcItem(idx, mi + 1);
    };
  });
  
  // Сброс ручного значения
  container.querySelectorAll(`.plan-reset-cell[data-idx="${idx}"]`).forEach(btn => {
    btn.onclick = (e) => {
      e.stopPropagation();
      const mi = parseInt(btn.dataset.month);
      item.plan[mi].locked = false;
      recalcItem(idx, mi);
    };
  });
}

function updatePlanTotals() {
  const container = document.getElementById('planTableContainer');
  const headers = generatePeriodHeaders();
  for (let mi = 0; mi < headers.length; mi++) {
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

  for (let mi = 0; mi < headers.length; mi++) {
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

  // #1 #4 Подтверждение
  const isUpdate = !!planState.editingPlanId;
  const confirmMsg = isUpdate
    ? 'Заменить сохранённый план новыми данными?'
    : `Сохранить план для ${planState.supplier}?`;
  const confirmed = await customConfirm(isUpdate ? 'Обновить план?' : 'Сохранить план?', confirmMsg);
  if (!confirmed) return;

  const planData = {
    legal_entity: planState.legalEntity,
    supplier: planState.supplier,
    period_type: planState.periodType,
    period_count: planState.periodCount,
    start_date: planState.startDate ? planState.startDate.toISOString().slice(0, 10) : new Date().toISOString().slice(0, 10),
    items: itemsWithPlan.map(item => ({
      sku: item.sku, name: item.name, qty_per_box: item.qtyPerBox,
      boxes_per_pallet: item.boxesPerPallet,
      unit_of_measure: item.unitOfMeasure, monthly_consumption: item.monthlyConsumption,
      stock_on_hand: item.stockOnHand, stock_at_supplier: item.stockAtSupplier,
      plan: item.plan.map(p => ({ month: p.month, order_boxes: p.orderBoxes, order_units: p.orderUnits, locked: p.locked || false }))
    }))
  };

  let error;
  if (planState.editingPlanId) {
    // UPDATE
    ({ error } = await supabase.from('plans').update(planData).eq('id', planState.editingPlanId));
  } else {
    // INSERT
    planData.created_at = new Date().toISOString();
    ({ error } = await supabase.from('plans').insert([planData]));
  }

  if (error) {
    console.warn('Supabase plans error:', error);
    showToast('Ошибка', 'Не удалось сохранить план', 'error');
    return;
  }
  
  const label = planState.editingPlanId ? 'План обновлён' : 'План сохранён';
  const unitLabel = planState.periodType === 'weeks' ? 'нед.' : 'мес.';
  showToast(label, `${itemsWithPlan.length} позиций на ${planState.periodCount} ${unitLabel}`, 'success');
}

/* ═══════ EXCEL EXPORT ═══════ */

async function exportPlanToExcel() {
  const itemsWithPlan = planState.items.filter(item =>
    item.plan.some(p => p.orderBoxes > 0)
  );

  if (!itemsWithPlan.length) {
    showToast('Нет данных', 'Заполните расход и остатки', 'error');
    return;
  }

  const XLSX = await import('https://cdn.sheetjs.com/xlsx-0.20.1/package/xlsx.mjs');
  const headers = generatePeriodHeaders();

  // === Шапка ===
  const rows = [
    [`Планирование заказов — ${planState.supplier}`],
    [`Юр. лицо: ${planState.legalEntity}`],
    [`Дата: ${(planState.startDate || new Date()).toLocaleDateString('ru-RU')}`],
    [`Период: ${planState.periodCount} ${planState.periodType === 'weeks' ? 'нед.' : 'мес.'}`],
    []
  ];

  // === Заголовки: Арт., Наименование, Ед., период1, период2, ..., ИТОГО ===
  const headerRow = ['Арт.', 'Наименование', 'Ед.'];
  headers.forEach(h => headerRow.push(h.label));
  headerRow.push('ИТОГО');
  rows.push(headerRow);

  // === Данные — коробки (единицы) ===
  itemsWithPlan.forEach(item => {
    const unit = item.unitOfMeasure || 'шт';
    const row = [item.sku || '', item.name, unit];

    let totalBoxes = 0;
    item.plan.forEach(p => {
      const boxes = p.orderBoxes || 0;
      const units = p.orderUnits || 0;
      row.push(boxes > 0 ? `${boxes} кор (${nf.format(units)} ${unit})` : '');
      totalBoxes += boxes;
    });

    const totalUnits = totalBoxes * item.qtyPerBox;
    row.push(`${totalBoxes} кор (${nf.format(totalUnits)} ${unit})`);
    rows.push(row);
  });

  // === Итого строка ===
  const totalsRow = ['', 'ИТОГО', ''];
  let grandBoxes = 0;
  for (let mi = 0; mi < headers.length; mi++) {
    let colBoxes = 0;
    itemsWithPlan.forEach(item => {
      if (item.plan[mi]) colBoxes += item.plan[mi].orderBoxes || 0;
    });
    totalsRow.push(colBoxes > 0 ? `${nf.format(colBoxes)} кор` : '');
    grandBoxes += colBoxes;
  }
  totalsRow.push(`${nf.format(grandBoxes)} кор`);
  rows.push(totalsRow);

  // === Создание Excel ===
  const ws = XLSX.utils.aoa_to_sheet(rows);

  const cols = [
    { wch: 12 },  // арт
    { wch: 40 },  // наименование
    { wch: 6 }    // ед
  ];
  headers.forEach(() => cols.push({ wch: 22 }));
  cols.push({ wch: 22 }); // итого
  ws['!cols'] = cols;

  const totalCols = 3 + headers.length + 1;
  ws['!merges'] = [
    { s: { r: 0, c: 0 }, e: { r: 0, c: totalCols - 1 } },
    { s: { r: 1, c: 0 }, e: { r: 1, c: totalCols - 1 } },
    { s: { r: 2, c: 0 }, e: { r: 2, c: totalCols - 1 } },
    { s: { r: 3, c: 0 }, e: { r: 3, c: totalCols - 1 } }
  ];

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Планирование');

  const date = new Date().toISOString().slice(0, 10);
  const filename = `План_${planState.supplier}_${date}.xlsx`;

  XLSX.writeFile(wb, filename);
  showToast('Excel сохранён', filename, 'success');
}