/**
 * Composable: встроенный калькулятор для <input type="number">
 * Логика полностью из calculator.js — не изменена.
 */
export function useCalculator(onCalculate) {
  let pendingOperation = null;
  let firstValue = null;
  let isEnteringSecondValue = false;

  function onFocus(e) {
    setTimeout(() => {
      if (e.target.value === '0') e.target.select();
    }, 0);
  }

  function onKeydown(e) {
    const input = e.target;
    const currentValue = parseFloat(input.value) || 0;

    if (['+', '-', '*', '/'].includes(e.key)) {
      e.preventDefault();
      if (pendingOperation && isEnteringSecondValue) {
        const result = _calc(firstValue, currentValue, pendingOperation);
        input.value = result;
        _hint(input, `${firstValue} ${pendingOperation} ${currentValue} = ${result}`);
        if (onCalculate) setTimeout(() => onCalculate(result), 0);
        firstValue = result;
      } else {
        firstValue = currentValue;
      }
      pendingOperation = e.key;
      isEnteringSecondValue = false;
      _hint(input, `${firstValue} ${e.key} ...`, 1000);
      setTimeout(() => input.select(), 0);
    } else if (e.key === 'Enter') {
      if (pendingOperation && firstValue !== null) {
        e.preventDefault();
        const secondValue = parseFloat(input.value) || 0;
        const result = _calc(firstValue, secondValue, pendingOperation);
        input.value = result;
        _hint(input, `${firstValue} ${pendingOperation} ${secondValue} = ${result}`);
        if (onCalculate) setTimeout(() => onCalculate(result), 0);
        pendingOperation = null;
        firstValue = null;
        isEnteringSecondValue = false;
      }
    } else if (e.key === 'Escape') {
      if (pendingOperation) {
        e.preventDefault();
        pendingOperation = null;
        firstValue = null;
        isEnteringSecondValue = false;
        _hint(input, 'Отменено', 800);
      }
    } else if (!isEnteringSecondValue && pendingOperation && /[0-9]/.test(e.key)) {
      isEnteringSecondValue = true;
    }
  }

  function hasPendingOp() {
    return pendingOperation !== null;
  }

  return { onFocus, onKeydown, hasPendingOp };
}

function _calc(a, b, op) {
  let r;
  if (op === '+') r = a + b;
  else if (op === '-') r = a - b;
  else if (op === '*') r = a * b;
  else if (op === '/') r = b !== 0 ? a / b : 0;
  else return a;
  return Math.round(r);
}

function _hint(input, text, duration = 2000) {
  document.querySelector('.calc-hint')?.remove();
  const hint = document.createElement('div');
  hint.className = 'calc-hint';
  hint.textContent = text;
  hint.style.cssText = `
    position:fixed;background:#2D5016;color:#fff;padding:6px 10px;
    border-radius:4px;font-size:12px;font-weight:500;white-space:nowrap;
    pointer-events:none;z-index:10000;box-shadow:0 2px 8px rgba(0,0,0,.2);
  `;
  const rect = input.getBoundingClientRect();
  hint.style.left = (rect.left + rect.width / 2) + 'px';
  hint.style.top = (rect.bottom + 8) + 'px';
  hint.style.transform = 'translateX(-50%)';
  document.body.appendChild(hint);
  setTimeout(() => hint.remove(), duration);
}
