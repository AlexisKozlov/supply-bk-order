/**
 * Composable: встроенный калькулятор для <input type="number">
 * Поддерживает +, -, *, / и % (процент от первого числа).
 *
 * Примеры:
 *   100 + 10% → 110    (прибавить 10%)
 *   100 - 25% → 75     (отнять 25%)
 *   200 * 15% → 30     (15% от 200)
 *   500 / 50% → 1000   (разделить на 50%)
 */
export function useCalculator(onCalculate, { decimals = 0 } = {}) {
  let pendingOperation = null;
  let firstValue = null;
  let isEnteringSecondValue = false;
  const _round = decimals > 0
    ? (v) => Math.round(v * 10 ** decimals) / 10 ** decimals
    : Math.round;

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
        const result = _calc(firstValue, currentValue, pendingOperation, _round);
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
    } else if (e.key === '%') {
      // Применить процент: второе число трактуется как процент
      if (pendingOperation && firstValue !== null && isEnteringSecondValue) {
        e.preventDefault();
        const pct = parseFloat(input.value) || 0;
        const result = _calcPercent(firstValue, pct, pendingOperation, _round);
        input.value = result;
        _hint(input, `${firstValue} ${pendingOperation} ${pct}% = ${result}`);
        if (onCalculate) setTimeout(() => onCalculate(result), 0);
        pendingOperation = null;
        firstValue = null;
        isEnteringSecondValue = false;
      }
    } else if (e.key === 'Enter') {
      if (pendingOperation && firstValue !== null) {
        e.preventDefault();
        const secondValue = parseFloat(input.value) || 0;
        const result = _calc(firstValue, secondValue, pendingOperation, _round);
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

  function onBlur() {
    pendingOperation = null;
    firstValue = null;
    isEnteringSecondValue = false;
  }

  return { onFocus, onKeydown, onBlur, hasPendingOp };
}

function _calc(a, b, op, roundFn = Math.round) {
  let r;
  if (op === '+') r = a + b;
  else if (op === '-') r = a - b;
  else if (op === '*') r = a * b;
  else if (op === '/') r = b !== 0 ? a / b : 0;
  else return a;
  return roundFn(r);
}

/** Вычисление с процентом: 100 + 10% = 110, 100 - 25% = 75 и т.д. */
function _calcPercent(a, pct, op, roundFn = Math.round) {
  const part = a * pct / 100;
  let r;
  if (op === '+') r = a + part;
  else if (op === '-') r = a - part;
  else if (op === '*') r = part;       // 200 * 15% = 30
  else if (op === '/') r = pct !== 0 ? a / (pct / 100) : 0;
  else return a;
  return roundFn(r);
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
