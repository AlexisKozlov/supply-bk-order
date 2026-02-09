/**
 * Модуль для рендеринга таблицы заказа
 */

import { setupCalculator } from './calculator.js';
import { calculateItem } from './calculations.js';

export function renderTable(orderState, tbody, callbacks) {
  const {
    saveDraft,
    saveStateToHistoryDebounced,
    saveStateToHistory,
    updateFinalSummary,
    removeItem,
    setupExcelNavigation,
    roundToPallet,
    saveItemOrder,
    render
  } = callbacks;

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
        ${item.sku ? `<b>${item.sku}</b> ` : ''}${item.name}
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
      <td class="delete-cell"><button class="delete-item-x" title="Удалить"><img src="./icons/close.png" width="12" height="12" alt=""></button></td>
    `;

    const inputs = tr.querySelectorAll('input[type="number"]');
    const orderPiecesInput = tr.querySelector('.order-pieces');
    const orderBoxesInput = tr.querySelector('.order-boxes');
    const calcToOrderBtn = tr.querySelector('.calc-to-order');
    const roundBtn = tr.querySelector('.round-to-pallet');
    const deleteBtn = tr.querySelector('.delete-item-x');

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

    function syncOrderInputs(fromPieces) {
      if (fromPieces) {
        const pieces = +orderPiecesInput.value || 0;
        const boxes = item.qtyPerBox ? Math.ceil(pieces / item.qtyPerBox) : 0;
        orderBoxesInput.value = boxes;
        item.finalOrder = orderState.settings.unit === 'pieces' ? pieces : boxes;
      } else {
        const boxes = +orderBoxesInput.value || 0;
        const pieces = boxes * (item.qtyPerBox || 1);
        orderPiecesInput.value = pieces;
        item.finalOrder = orderState.settings.unit === 'pieces' ? pieces : boxes;
      }
    }

    // Инициализация значений
    if (orderState.settings.unit === 'pieces') {
      orderPiecesInput.value = item.finalOrder || 0;
      orderBoxesInput.value = item.qtyPerBox ? Math.ceil((item.finalOrder || 0) / item.qtyPerBox) : 0;
    } else {
      orderBoxesInput.value = item.finalOrder || 0;
      orderPiecesInput.value = (item.finalOrder || 0) * (item.qtyPerBox || 1);
    }

    // Обработчики input
    inputs[0].addEventListener('input', e => {
      item.consumptionPeriod = +e.target.value || 0;
      updateRow(tr, item, orderState.settings);
      saveDraft();
      saveStateToHistoryDebounced();
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

    calcToOrderBtn.addEventListener('click', () => {
      const calc = calculateItem(item, orderState.settings);
      if (calc.calculatedOrder > 0) {
        item.finalOrder = Math.round(calc.calculatedOrder);
        
        if (orderState.settings.unit === 'pieces') {
          orderPiecesInput.value = item.finalOrder;
          orderBoxesInput.value = item.qtyPerBox ? Math.ceil(item.finalOrder / item.qtyPerBox) : 0;
        } else {
          orderBoxesInput.value = item.finalOrder;
          orderPiecesInput.value = item.finalOrder * (item.qtyPerBox || 1);
        }
        
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
      
      if (orderState.settings.unit === 'pieces') {
        orderPiecesInput.value = item.finalOrder;
        orderBoxesInput.value = item.qtyPerBox ? Math.ceil(item.finalOrder / item.qtyPerBox) : 0;
      } else {
        orderBoxesInput.value = item.finalOrder;
        orderPiecesInput.value = item.finalOrder * (item.qtyPerBox || 1);
      }
      
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
  const calc = calculateItem(item, settings);
  const nf = new Intl.NumberFormat('ru-RU');
  const periodDays = settings.periodDays || 30;
  const dailyConsumption = item.consumptionPeriod / periodDays;
  
  // ===== РАСЧЁТ ЗАКАЗА - формат: "4 кор (48 шт)" или "4 кор" =====
  const calcValue = tr.querySelector('.calc-value');
  if (calc.calculatedOrder > 0) {
    if (settings.unit === 'pieces' && item.qtyPerBox) {
      // Единицы = штуки → показываем "X кор (Y шт)"
      const boxes = Math.ceil(calc.calculatedOrder / item.qtyPerBox);
      calcValue.textContent = `${boxes} кор (${Math.round(calc.calculatedOrder)} шт)`;
    } else if (settings.unit === 'boxes') {
      // Единицы = коробки → показываем "X кор"
      calcValue.textContent = `${Math.round(calc.calculatedOrder)} кор`;
    } else {
      calcValue.textContent = Math.round(calc.calculatedOrder).toString();
    }
  } else {
    calcValue.textContent = '0';
  }
  
  // ===== ДАТА ПОКРЫТИЯ - формат: "дата (дни)" =====
  const dateCell = tr.querySelector('.date');
  if (calc.coverageDate && settings.today) {
    const daysDiff = Math.ceil((calc.coverageDate - settings.today) / 86400000);
    dateCell.textContent = `${calc.coverageDate.toLocaleDateString()} (${daysDiff} дн.)`;
  } else {
    dateCell.textContent = '-';
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
  if (stockDisplay && dailyConsumption > 0 && settings.today) {
    const totalStock = item.stock + (item.transit || 0);
    const daysOfCurrentStock = Math.floor(totalStock / dailyConsumption);
    const stockEndDate = new Date(settings.today.getTime() + daysOfCurrentStock * 86400000);
    stockDisplay.textContent = `${stockEndDate.toLocaleDateString()} (${daysOfCurrentStock} дн.)`;
  } else if (stockDisplay) {
    stockDisplay.textContent = '-';
  }
  
  // ===== ПРОВЕРКА ДЕФИЦИТА ДО ПОСТАВКИ =====
  const shortageInfo = tr.querySelector('.shortage-info');
  
  if (settings.deliveryDate && item.consumptionPeriod && dailyConsumption > 0) {
    // Считаем ТОЛЬКО с остатком и транзитом, БЕЗ заказа
    const totalStock = item.stock + (item.transit || 0);
    const daysUntilDelivery = Math.ceil((settings.deliveryDate - settings.today) / 86400000);
    const consumedBeforeDelivery = dailyConsumption * daysUntilDelivery;
    
    // Если не хватает до поставки
    if (totalStock < consumedBeforeDelivery) {
      const deficit = consumedBeforeDelivery - totalStock;
      const deficitDays = Math.ceil(deficit / dailyConsumption);
      
      const unit = item.unitOfMeasure || 'шт';
      let deficitText;
      
      if (settings.unit === 'boxes') {
        // расход и остаток введены в коробках → deficit тоже в коробках
        deficitText = `${Math.ceil(deficit)} кор.`;
      } else if (item.qtyPerBox) {
        // расход и остаток в штуках → deficit в штуках, коробки в скобках
        const deficitBoxes = Math.ceil(deficit / item.qtyPerBox);
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
}