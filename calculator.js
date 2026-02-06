/**
 * Встроенный калькулятор для input полей (БЕЗ смены типа)
 * Работает через отслеживание клавиш
 * Пример: 100 [Enter] +50 [Enter] → 150
 */

export function setupCalculator(input, onCalculate) {
  
  let pendingOperation = null; // '+', '-', '*', '/'
  let firstValue = null;
  let isEnteringSecondValue = false;
  
  // Автовыделение при фокусе если значение = 0
  input.addEventListener('focus', () => {
    setTimeout(() => {
      if (input.value === '0') {
        input.select();
      }
    }, 0);
  });
  
  input.addEventListener('keydown', (e) => {
    const currentValue = parseFloat(input.value) || 0;
    
    // Операторы: +, -, *, /
    if (['+', '-', '*', '/'].includes(e.key)) {
      e.preventDefault();
      
      // Если уже была операция, выполняем её
      if (pendingOperation && isEnteringSecondValue) {
        const result = calculateResult(firstValue, currentValue, pendingOperation);
        input.value = result;
        showCalculationHint(input, `${firstValue} ${pendingOperation} ${currentValue} = ${result}`);
        
        if (onCalculate) {
          setTimeout(() => onCalculate(result), 0);
        }
        
        firstValue = result;
      } else {
        firstValue = currentValue;
      }
      
      pendingOperation = e.key;
      isEnteringSecondValue = false;
      
      // Показываем подсказку какая операция выбрана
      showCalculationHint(input, `${firstValue} ${e.key} ...`, 1000);
      
      // Выделяем текущее значение чтобы при вводе оно заменилось
      setTimeout(() => input.select(), 0);
    }
    
    // Enter — выполнить операцию
    else if (e.key === 'Enter') {
      if (pendingOperation && firstValue !== null) {
        e.preventDefault();
        const secondValue = parseFloat(input.value) || 0;
        const result = calculateResult(firstValue, secondValue, pendingOperation);
        
        input.value = result;
        showCalculationHint(input, `${firstValue} ${pendingOperation} ${secondValue} = ${result}`);
        
        if (onCalculate) {
          setTimeout(() => onCalculate(result), 0);
        }
        
        // Сбрасываем операцию
        pendingOperation = null;
        firstValue = null;
        isEnteringSecondValue = false;
      }
    }
    
    // Escape — отменить операцию
    else if (e.key === 'Escape') {
      if (pendingOperation) {
        e.preventDefault();
        pendingOperation = null;
        firstValue = null;
        isEnteringSecondValue = false;
        showCalculationHint(input, 'Отменено', 800);
      }
    }
    
    // Любая цифра после оператора
    else if (!isEnteringSecondValue && pendingOperation && /[0-9]/.test(e.key)) {
      isEnteringSecondValue = true;
    }
  });
}

function calculateResult(num1, num2, operator) {
  let result;
  
  switch (operator) {
    case '+':
      result = num1 + num2;
      break;
    case '-':
      result = num1 - num2;
      break;
    case '*':
      result = num1 * num2;
      break;
    case '/':
      result = num2 !== 0 ? num1 / num2 : 0;
      break;
    default:
      return num1;
  }
  
  return Math.round(result);
}

function showCalculationHint(input, text, duration = 2000) {
  // Удаляем предыдущую подсказку если есть
  const existing = document.querySelector('.calc-hint');
  if (existing) existing.remove();
  
  // Создаём временную подсказку
  const hint = document.createElement('div');
  hint.className = 'calc-hint';
  hint.textContent = text;
  hint.style.cssText = `
    position: fixed;
    background: #2D5016;
    color: white;
    padding: 6px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
    white-space: nowrap;
    pointer-events: none;
    z-index: 10000;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    animation: fadeInOut ${duration}ms ease-in-out;
  `;
  
  // Позиционируем под полем
  const rect = input.getBoundingClientRect();
  hint.style.left = (rect.left + rect.width / 2) + 'px';
  hint.style.top = (rect.bottom + 8) + 'px';
  hint.style.transform = 'translateX(-50%)';
  
  document.body.appendChild(hint);
  
  // Удаляем через заданное время
  setTimeout(() => {
    hint.remove();
  }, duration);
}

// Добавляем CSS анимацию (только один раз)
if (!document.getElementById('calc-hint-styles')) {
  const style = document.createElement('style');
  style.id = 'calc-hint-styles';
  style.textContent = `
    @keyframes fadeInOut {
      0% { opacity: 0; transform: translateX(-50%) translateY(-5px); }
      15% { opacity: 1; transform: translateX(-50%) translateY(0); }
      85% { opacity: 1; transform: translateX(-50%) translateY(0); }
      100% { opacity: 0; transform: translateX(-50%) translateY(5px); }
    }
  `;
  document.head.appendChild(style);
}