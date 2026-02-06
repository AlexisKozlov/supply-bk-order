/**
 * Встроенный калькулятор для input полей
 * Распознаёт выражения типа: 100+50, 200-30, 10*5, 100/2
 */

export function setupCalculator(input, onCalculate) {
  let lastValue = input.value;
  
  input.addEventListener('input', (e) => {
    const value = e.target.value.trim();
    
    // Проверяем есть ли математическая операция
    // Паттерн: число оператор число (например: 100+50, 200-30)
    const calcPattern = /^(-?\d+\.?\d*)\s*([\+\-\*\/])\s*(-?\d+\.?\d*)$/;
    const match = value.match(calcPattern);
    
    if (match) {
      const num1 = parseFloat(match[1]);
      const operator = match[2];
      const num2 = parseFloat(match[3]);
      
      if (!isNaN(num1) && !isNaN(num2)) {
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
            return;
        }
        
        // Округляем до целого для количеств
        result = Math.round(result);
        
        // Устанавливаем результат
        e.target.value = result;
        lastValue = result.toString();
        
        // Вызываем callback
        if (onCalculate) {
          onCalculate(result);
        }
        
        // Показываем подсказку
        showCalculationHint(input, `${num1} ${operator} ${num2} = ${result}`);
      }
    } else {
      lastValue = value;
    }
  });
}

function showCalculationHint(input, text) {
  // Создаём временную подсказку
  const hint = document.createElement('div');
  hint.className = 'calc-hint';
  hint.textContent = text;
  hint.style.cssText = `
    position: absolute;
    background: #2D5016;
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    white-space: nowrap;
    pointer-events: none;
    z-index: 1000;
    animation: fadeInOut 1.5s ease-in-out;
  `;
  
  // Позиционируем под полем
  const rect = input.getBoundingClientRect();
  hint.style.left = rect.left + 'px';
  hint.style.top = (rect.bottom + 4) + 'px';
  
  document.body.appendChild(hint);
  
  // Удаляем через 1.5 секунды
  setTimeout(() => {
    hint.remove();
  }, 1500);
}

// Добавляем CSS анимацию
const style = document.createElement('style');
style.textContent = `
  @keyframes fadeInOut {
    0% { opacity: 0; transform: translateY(-5px); }
    20% { opacity: 1; transform: translateY(0); }
    80% { opacity: 1; transform: translateY(0); }
    100% { opacity: 0; transform: translateY(5px); }
  }
`;
document.head.appendChild(style);