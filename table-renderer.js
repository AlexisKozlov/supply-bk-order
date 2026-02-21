/**
 * Модуль для рендеринга таблицы заказа
 */

import { setupCalculator } from './calculator.js';
import { calculateItem } from './calculations.js';
import { validateConsumptionData } from './data-validation.js';
import { esc, getQpb, getMultiplicity } from './utils.js';

export function renderTable(orderState, tbody, callbacks) {
  const {
    saveDraft,
    saveStateToHistoryDebounced,
    saveStateToHistory,
    updateFinalSummary,
    removeItem,
    roundToPallet,
    saveItemOrder,
    render,
    openProductForEdit
  } = callbacks;

  // Дебаунс проверки данных в заказе
  let _orderValidationTimer = null;
  function debouncedValidation() {
    clearTimeout(_orderValidationTimer);
    _orderValidationTimer = setTimeout(() => validateConsumptionData(tbody), 300);
  }

  // Excel-like навигация по ячейкам (перенесено из ui.js)
  function setupExcelNavigation(input, rowIndex, columnIndex) {
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === 'ArrowDown') {
        e.preventDefault();
        moveToCell(rowIndex + 1, columnIndex);
      }
      else if (e.key === 'ArrowUp') {
        e.preventDefault();
        moveToCell(rowIndex - 1, columnIndex);
      }
      else if (e.key === 'ArrowRight') {
        let atEnd = true;
        try { atEnd = input.selectionStart >= input.value.length; } catch(err) { /* OK */ }
        if (atEnd) {
          e.preventDefault();
          moveToCell(rowIndex, columnIndex + 1);
        }
      }
      else if (e.key === 'ArrowLeft') {
        let atStart = true;
        try { atStart = input.selectionStart === 0; } catch(err) { /* OK */ }
        if (atStart) {
          e.preventDefault();
          moveToCell(rowIndex, columnIndex - 1);
        }
      }
    });
  }

  function moveToCell(rowIndex, columnIndex) {
    const rows = tbody.querySelectorAll('tr');
    if (rowIndex < 0 || rowIndex >= rows.length) return;
    if (columnIndex < 0 || columnIndex > 4) return;
    const targetRow = rows[rowIndex];
    const inputs = targetRow.querySelectorAll('input[type="number"]');
    if (inputs[columnIndex]) {
      inputs[columnIndex].focus();
      inputs[columnIndex].select();
    }
  }

  tbody.innerHTML = '';
  let draggedIndex = null;

  orderState.items.forEach((item, rowIndex) => {
    const tr = document.createElement('tr');
    tr.dataset.rowIndex = rowIndex;

    tr.innerHTML = `
      <td style="padding:4px;text-align:center;width:30px;">
        <span class="drag-handle" draggable="true" style="cursor:grab;user-select:none;color:#b0ada8;font-size:16px;">⋮⋮</span>
      </td>
      <td class="item-name">
        ${item.sku ? `<b>${esc(item.sku)}</b> ` : ''}${esc(item.name)}
        <div class="item-meta">${item.qtyPerBox ? item.qtyPerBox + ' ' + (item.unitOfMeasure || 'шт') + '/кор' : ''}${item.boxesPerPallet ? ' · ' + item.boxesPerPallet + ' кор/пал' : ''}${item.multiplicity ? ' · кратн.' + item.multiplicity : ''}</div>
        <div class="shortage-info hidden"></div>
      </td>
      <td><input type="number" value="${item.consumptionPeriod}"></td>
      <td><input type="number" value="${item.stock}"></td>
      <td class="transit-col"><input type="number" value="${item.transit || 0}"></td>
      <td class="stock-col stock-display">-</td>
      <td class="calc">
        <div class="calc-value">0</div>
        <button class="btn small calc-to-order" style="margin-top:4px;font-size:11px;padding:4px 8px;">→ В заказ</button>
      </td>
      <td class="order-cell order-highlight">
        <input type="number" class="order-pieces" value="0" style="width:70px;"> / 
        <input type="number" class="order-boxes" value="0" style="width:70px;">
      </td>
      <td class="date">-</td>
      <td class="pallets">
        <div class="pallet-info">-</div>
        <button class="btn small round-to-pallet">Округлить</button>
      </td>
      <td class="delete-cell"><button class="delete-item-x" title="Удалить">✕</button></td>
    `;

    const inputs = tr.querySelectorAll('input[type="number"]');
    const orderPiecesInput = tr.querySelector('.order-pieces');
    const orderBoxesInput = tr.querySelector('.order-boxes');
    const calcToOrderBtn = tr.querySelector('.calc-to-order');
    const roundBtn = tr.querySelector('.round-to-pallet');
    const deleteBtn = tr.querySelector('.delete-item-x');
    const itemNameCell = tr.querySelector('.item-name');

    // Клик по наименованию - открыть редактирование (если есть SKU)
    if (itemNameCell && item.sku && openProductForEdit) {
      itemNameCell.style.cursor = 'pointer';
      itemNameCell.addEventListener('click', () => {
        openProductForEdit(item.sku);
      });
    }

    // Калькулятор для всех полей
    setupCalculator(inputs[0], (result) => {
      item.consumptionPeriod = result;
      updateRow(tr, item, orderState.settings);
      saveDraft();
      saveStateToHistoryDebounced();
    });

    setupCalculator(inputs[1], (result) => {
      item.stock = result;
      updateRow(tr, item, orderState.settings);
      saveDraft();
      saveStateToHistoryDebounced();
    });

    setupCalculator(inputs[2], (result) => {
      item.transit = result;
      updateRow(tr, item, orderState.settings);
      saveDraft();
      saveStateToHistoryDebounced();
    });

    setupCalculator(orderPiecesInput, (result) => {
      orderPiecesInput.value = result;
      syncOrderInputs(true);
      updateRow(tr, item, orderState.settings);
      saveDraft();
      updateFinalSummary();
      saveStateToHistoryDebounced();
    });

    setupCalculator(orderBoxesInput, (result) => {
      orderBoxesInput.value = result;
      syncOrderInputs(false);
      updateRow(tr, item, orderState.settings);
      saveDraft();
      updateFinalSummary();
    });

    // Конверсия: order-pieces = штуки/учётные кор, order-boxes = ФИЗИЧЕСКИЕ коробки
    const qpb = getQpb(item);
    const mult = getMultiplicity(item);
    const physBoxSize = qpb * mult; // штук в 1 физической коробке

    function syncOrderInputs(fromPieces) {
      if (fromPieces) {
        // Ввели штуки → пересчитываем физ. коробки
        const pieces = +orderPiecesInput.value || 0;
        const physBoxes = physBoxSize ? Math.ceil(pieces / physBoxSize) : 0;
        orderBoxesInput.value = physBoxes;
        item.finalOrder = orderState.settings.unit === 'pieces'
          ? pieces
          : (orderState.settings.unit === 'boxes' ? pieces / qpb : pieces);
        // Пересчитываем finalOrder как штуки введённые пользователем
        if (orderState.settings.unit === 'pieces') {
          item.finalOrder = pieces;
        } else {
          // В режиме коробок finalOrder = учётные коробки = pieces / qpb
          item.finalOrder = qpb ? Math.round(pieces / qpb) : pieces;
        }
      } else {
        // Ввели физ. коробки → пересчитываем штуки
        const physBoxes = +orderBoxesInput.value || 0;
        const pieces = physBoxes * physBoxSize;
        orderPiecesInput.value = pieces;
        if (orderState.settings.unit === 'pieces') {
          item.finalOrder = pieces;
        } else {
          // В режиме учётных коробок: physBoxes * mult
          item.finalOrder = physBoxes * mult;
        }
      }
    }

    // Инициализация значений
    if (orderState.settings.unit === 'pieces') {
      const pieces = item.finalOrder || 0;
      orderPiecesInput.value = pieces;
      orderBoxesInput.value = physBoxSize ? Math.ceil(pieces / physBoxSize) : 0;
    } else {
      const accountingBoxes = item.finalOrder || 0;
      const pieces = accountingBoxes * qpb;
      const physBoxes = mult ? Math.ceil(accountingBoxes / mult) : accountingBoxes;
      orderBoxesInput.value = physBoxes;
      orderPiecesInput.value = pieces;
    }

    // Обработчики input
    inputs[0].addEventListener('input', e => {
      item.consumptionPeriod = +e.target.value || 0;
      updateRow(tr, item, orderState.settings);
      saveDraft();
      saveStateToHistoryDebounced();
      // #6 Мгновенная проверка данных (с дебаунсом)
      debouncedValidation();
    });
    inputs[0].addEventListener('blur', () => saveStateToHistory());
    setupExcelNavigation(inputs[0], rowIndex, 0);

    inputs[1].addEventListener('input', e => {
      item.stock = +e.target.value || 0;
      updateRow(tr, item, orderState.settings);
      saveDraft();
      saveStateToHistoryDebounced();
    });
    inputs[1].addEventListener('blur', () => saveStateToHistory());
    setupExcelNavigation(inputs[1], rowIndex, 1);

    inputs[2].addEventListener('input', e => {
      item.transit = +e.target.value || 0;
      updateRow(tr, item, orderState.settings);
      saveDraft();
      saveStateToHistoryDebounced();
    });
    inputs[2].addEventListener('blur', () => saveStateToHistory());
    setupExcelNavigation(inputs[2], rowIndex, 2);

    // Обновляет оба инпута из текущего item.finalOrder
    function refreshOrderInputs() {
      if (orderState.settings.unit === 'pieces') {
        const pieces = item.finalOrder || 0;
        orderPiecesInput.value = pieces;
        orderBoxesInput.value = physBoxSize ? Math.ceil(pieces / physBoxSize) : 0;
      } else {
        const accountingBoxes = item.finalOrder || 0;
        orderPiecesInput.value = accountingBoxes * qpb;
        orderBoxesInput.value = mult ? Math.ceil(accountingBoxes / mult) : accountingBoxes;
      }
    }

    calcToOrderBtn.addEventListener('click', () => {
      const calc = calculateItem(item, orderState.settings);
      if (calc.calculatedOrder > 0) {
        item.finalOrder = Math.round(calc.calculatedOrder);
        refreshOrderInputs();
        updateRow(tr, item, orderState.settings);
        updateFinalSummary();
        saveDraft();
        saveStateToHistory();
      }
    });

    orderPiecesInput.addEventListener('input', () => {
      syncOrderInputs(true);
      updateRow(tr, item, orderState.settings);
      saveDraft();
      updateFinalSummary();
    });
    orderPiecesInput.addEventListener('blur', () => saveStateToHistory());
    setupExcelNavigation(orderPiecesInput, rowIndex, 3);

    orderBoxesInput.addEventListener('input', () => {
      syncOrderInputs(false);
      updateRow(tr, item, orderState.settings);
      saveDraft();
      updateFinalSummary();
    });
    orderBoxesInput.addEventListener('blur', () => saveStateToHistory());
    setupExcelNavigation(orderBoxesInput, rowIndex, 4);

    roundBtn.addEventListener('click', () => {
      roundToPallet(item);
      refreshOrderInputs();
      updateRow(tr, item, orderState.settings);
      saveDraft();
      updateFinalSummary();
      saveStateToHistory();
    });

    deleteBtn.addEventListener('click', () => removeItem(item.id));

    // Drag and drop
    const handle = tr.querySelector('.drag-handle');
    
    handle.addEventListener('dragstart', (e) => {
      draggedIndex = rowIndex;
      tr.style.opacity = '0.5';
      e.dataTransfer.effectAllowed = 'move';
    });

    handle.addEventListener('dragend', () => {
      tr.style.opacity = '1';
      draggedIndex = null;
    });

    tr.addEventListener('dragover', (e) => {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
    });

    tr.addEventListener('drop', async (e) => {
      e.preventDefault();
      if (draggedIndex === null || draggedIndex === rowIndex) return;
      
      const item = orderState.items.splice(draggedIndex, 1)[0];
      orderState.items.splice(rowIndex, 0, item);
      
      await saveItemOrder();
      saveDraft();
      saveStateToHistory();
      render(); // Перерисовываем таблицу
    });

    updateRow(tr, item, orderState.settings);
    tbody.appendChild(tr);
  });
}

export function updateRow(tr, item, settings) {
  // Принудительно пересчитываем calc при каждом вызове
  const calc = calculateItem(item, settings);
  const nf = new Intl.NumberFormat('ru-RU');
  const periodDays = settings.periodDays || 30;
  const dailyConsumption = periodDays > 0 ? (Number(item.consumptionPeriod) || 0) / periodDays : 0;
  
  // ===== РАСЧЁТ ЗАКАЗА - формат: "84 кор (1008 шт)" =====
  const calcValue = tr.querySelector('.calc-value');
  const qpb = getQpb(item);
  const mult = getMultiplicity(item);
  
  let calcDisplayText = '0';
  if (calc.calculatedOrder > 0) {
    const orderVal = Math.round(calc.calculatedOrder);
    // Физические коробки для отображения
    const physBoxes = settings.unit === 'boxes'
      ? Math.ceil(orderVal / mult)
      : Math.ceil(orderVal / (qpb * mult));
    // Штуки для отображения
    const pieces = settings.unit === 'pieces'
      ? orderVal
      : orderVal * qpb;
    
    if (mult > 1) {
      calcDisplayText = `${physBoxes} кор (${nf.format(pieces)} ${item.unitOfMeasure || 'шт'})`;
    } else if (settings.unit === 'pieces' && qpb > 1) {
      const boxes = Math.ceil(orderVal / qpb);
      calcDisplayText = `${boxes} кор (${nf.format(orderVal)} ${item.unitOfMeasure || 'шт'})`;
    } else if (settings.unit === 'boxes') {
      calcDisplayText = `${orderVal} кор`;
    } else {
      calcDisplayText = orderVal.toString();
    }
  }
  
  // ===== ТУЛТИП РАСЧЁТА =====
  // Все промежуточные значения — в единицах ввода (как введено пользователем)
  const inputUnit = settings.unit === 'boxes' ? 'кор' : (item.unitOfMeasure || 'шт');
  const fmt1 = (n) => nf.format(Math.round(n * 10) / 10);
  
  // Суточный расход в единицах ввода
  const dailyDisplay = dailyConsumption;
  
  // Дни до прихода
  const transitDays = (settings.today && settings.deliveryDate)
    ? Math.ceil((settings.deliveryDate - settings.today) / 86400000)
    : 0;
  
  // Расход до прихода (в единицах ввода)
  const consumedBeforeDelivery = dailyDisplay * transitDays;
  
  // Общий остаток (в единицах ввода)
  const totalStock = (Number(item.stock) || 0) + (Number(item.transit) || 0);
  
  // Остаток к приходу (в единицах ввода)
  const stockAfterDelivery = Math.max(0, totalStock - consumedBeforeDelivery);
  
  // Нужно после прихода (в единицах ввода)
  const needAfterDelivery = dailyDisplay * (settings.safetyDays || 0);
  
  let tipHtml = '';
  if (settings.deliveryDate && settings.today) {
    tipHtml = `
      <div class="calc-tip-row"><span class="calc-tip-lbl">Суточный расход:</span><span class="calc-tip-val">${fmt1(dailyDisplay)} ${inputUnit}</span></div>
      <div class="calc-tip-row"><span class="calc-tip-lbl">Дней до прихода:</span><span class="calc-tip-val">${transitDays} дн.</span></div>
      <div class="calc-tip-row"><span class="calc-tip-lbl">Расход до прихода:</span><span class="calc-tip-val">${fmt1(consumedBeforeDelivery)} ${inputUnit}</span></div>
      <div class="calc-tip-row"><span class="calc-tip-lbl">Остаток к приходу:</span><span class="calc-tip-val">${fmt1(stockAfterDelivery)} ${inputUnit}</span></div>
      <hr class="calc-tip-hr">
      <div class="calc-tip-row"><span class="calc-tip-lbl">Нужно после прихода (запас ${settings.safetyDays || 0} дн.):</span><span class="calc-tip-val">${fmt1(needAfterDelivery)} ${inputUnit}</span></div>
      <div class="calc-tip-row"><span class="calc-tip-lbl">Итого к заказу:</span><span class="calc-tip-val">${calcDisplayText}</span></div>
    `;
  }
  
  if (tipHtml) {
    calcValue.innerHTML = `<span class="calc-wrap">${calcDisplayText}<div class="calc-tip">${tipHtml}</div></span>`;
  } else {
    calcValue.textContent = calcDisplayText;
  }
  
  // ===== ДАТА ПОКРЫТИЯ - формат: "дата (дни)" =====
  const dateCell = tr.querySelector('.date');
  if (dateCell) {
    if (calc.coverageDate && settings.deliveryDate) {
      try {
        const daysDiff = Math.ceil((calc.coverageDate - settings.deliveryDate) / 86400000);
        const day = String(calc.coverageDate.getDate()).padStart(2, '0');
        const month = String(calc.coverageDate.getMonth() + 1).padStart(2, '0');
        const year = String(calc.coverageDate.getFullYear()).slice(-2);
        dateCell.textContent = `${day}.${month}.${year} (${daysDiff} дн.)`;
        console.log(`📅 coverageDate для ${item.sku}:`, calc.coverageDate, daysDiff);
      } catch (e) {
        console.error('Ошибка форматирования даты:', e);
        dateCell.textContent = '-';
      }
    } else {
      dateCell.textContent = '-';
    }
  }
  
  // ===== ПАЛЛЕТЫ =====
  const palletInfo = tr.querySelector('.pallet-info');
  if (calc.palletsInfo) {
    palletInfo.textContent = `${calc.palletsInfo.pallets} пал. + ${calc.palletsInfo.boxesLeft} кор.`;
  } else {
    palletInfo.textContent = '-';
  }
  
  // ===== ЗАПАС - формат: "дата (дни)" =====
  const stockDisplay = tr.querySelector('.stock-display');
  if (stockDisplay) {
    try {
      const stock = Number(item.stock) || 0;
      const transit = Number(item.transit) || 0;
      const consumption = Number(item.consumptionPeriod) || 0;
      const period = Number(settings.periodDays) || 30;
      
      const totalStock = stock + transit;
      const dailyConsumption = period > 0 ? consumption / period : 0;
      
      let today = settings.today;
      if (!today || !(today instanceof Date) || isNaN(today)) {
        today = new Date();
      }
      
      if (dailyConsumption > 0) {
        const daysOfCurrentStock = Math.floor(totalStock / dailyConsumption);
        
        const stockEndDate = new Date(today);
        stockEndDate.setDate(stockEndDate.getDate() + daysOfCurrentStock);
        
        const day = String(stockEndDate.getDate()).padStart(2, '0');
        const month = String(stockEndDate.getMonth() + 1).padStart(2, '0');
        const year = String(stockEndDate.getFullYear()).slice(-2);
        
        stockDisplay.textContent = `${day}.${month}.${year} (${daysOfCurrentStock} дн.)`;
      } else if (totalStock > 0) {
        stockDisplay.textContent = '∞ (расход=0)';
      } else {
        stockDisplay.textContent = '0 дн.';
      }
    } catch (e) {
      console.error('Ошибка расчёта запаса:', e);
      stockDisplay.textContent = '-';
    }
  }
  
  // ===== ПРОВЕРКА ДЕФИЦИТА ДО ПОСТАВКИ =====
  const shortageInfo = tr.querySelector('.shortage-info');
  
  if (settings.deliveryDate && item.consumptionPeriod && dailyConsumption > 0 && settings.today) {
    const totalStock = (item.stock || 0) + (item.transit || 0);
    const daysUntilDelivery = Math.ceil((settings.deliveryDate - settings.today) / 86400000);
    const consumedBeforeDelivery = dailyConsumption * daysUntilDelivery;
    
    if (totalStock < consumedBeforeDelivery) {
      const deficit = consumedBeforeDelivery - totalStock;
      const deficitDays = Math.ceil(deficit / dailyConsumption);
      
      const unit = item.unitOfMeasure || 'шт';
      let deficitText;
      
      if (settings.unit === 'boxes') {
        deficitText = `${Math.ceil(deficit)} кор.`;
      } else if (qpb > 1) {
        const deficitBoxes = Math.ceil(deficit / qpb);
        deficitText = `${Math.ceil(deficit)} ${unit} (${deficitBoxes} кор.)`;
      } else {
        deficitText = `${Math.ceil(deficit)} ${unit}`;
      }
      
      shortageInfo.textContent = `⚠️ Не хватит: ${deficitText} | Дефицит: ${deficitDays} дн.`;
      shortageInfo.classList.remove('hidden');
      tr.classList.add('shortage-warning');
    } else {
      shortageInfo.classList.add('hidden');
      tr.classList.remove('shortage-warning');
    }
  } else {
    shortageInfo.classList.add('hidden');
    tr.classList.remove('shortage-warning');
  }

  // Подсветка строк с заполненным заказом
  if (item.finalOrder > 0) {
    tr.classList.add('has-order');
  } else {
    tr.classList.remove('has-order');
  }
}