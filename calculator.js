/**
 * Встроенный калькулятор для input полей
 * Распознаёт выражения типа: 100+50, 200-30, 10*5, 100/2
 * Срабатывает при нажатии Enter или потере фокуса
 */

export function setupCalculator(input, onCalculate) {
  
  // Сохраняем оригинальный тип и ширину
  const originalType = input.type;
  const originalWidth = window.getComputedStyle(input).width;
  
  // При фокусе меняем на text чтобы можно было вводить +, -, *, /
  input.addEventListener('focus', () => {
    input.type = 'text';
    // Фиксируем ширину чтобы поле не увеличивалось
    input.style.width = originalWidth;
    
    // Автовыделение если значение = 0
    setTimeout(() => {
      if (input.value === '0') {
        input.select();
      }
    }, 0);
  });
  
  function calculate() {
    const value = input.value.trim();
    
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
            return false;
        }
        
        // Округляем до целого для количеств
        result = Math.round(result);
        
        // Устанавливаем результат
        input.value = result;
        
        // Показываем подсказку
        showCalculationHint(input, `${num1} ${operator} ${num2} = ${result}`);
        
        // Вызываем callback
        if (onCalculate) {
          // Используем setTimeout чтобы сначала обновилось значение
          setTimeout(() => {
            onCalculate(result);
          }, 0);
        }
        
        return true;
      }
    }
    
    return false;
  }
  
  // Вычисление при нажатии Enter
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      if (calculate()) {
        // Переход к следующему полю
        const form = input.form;
        if (form) {
          const inputs = Array.from(form.querySelectorAll('input[type="number"]'));
          const index = inputs.indexOf(input);
          if (index >= 0 && index < inputs.length - 1) {
            inputs[index + 1].focus();
          }
        }
      }
    }
  });
  
  // Вычисление при потере фокуса
  input.addEventListener('blur', () => {
    calculate();
    // Возвращаем оригинальный тип и убираем фиксированную ширину
    input.type = originalType;
    input.style.width = '';
  });
}

function showCalculationHint(input, text) {
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
    animation: fadeInOut 2s ease-in-out;
  `;
  
  // Позиционируем под полем
  const rect = input.getBoundingClientRect();
  hint.style.left = (rect.left + rect.width / 2) + 'px';
  hint.style.top = (rect.bottom + 8) + 'px';
  hint.style.transform = 'translateX(-50%)';
  
  document.body.appendChild(hint);
  
  // Удаляем через 2 секунды
  setTimeout(() => {
    hint.remove();
  }, 2000);
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