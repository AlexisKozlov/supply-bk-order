export function daysBetween(date1, date2) {
  if (!date1 || !date2) return 0;
  const ms = date2 - date1;
  return Math.max(Math.ceil(ms / 86400000), 0);
}

export function roundUp(value) {
  return Math.ceil(value);
}

export function safeDivide(a, b) {
  if (!b) return 0;
  return a / b;
}

/** Экранирование HTML — защита от XSS при вставке через innerHTML */
const _escDiv = document.createElement('div');
export function esc(str) {
  _escDiv.textContent = str ?? '';
  return _escDiv.innerHTML;
}

/**
 * Синхронизация юр.лица из основного селектора в модальные окна.
 * Вызывается при открытии модалки, чтобы выбранное юр.лицо подставлялось автоматически.
 * @param {...string} selectorIds — id элементов select для синхронизации
 */
export function syncEntityFromMain(...selectorIds) {
  const mainLegal = document.getElementById('legalEntity');
  if (!mainLegal) return;
  const val = mainLegal.value;
  selectorIds.forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      // Проверяем, есть ли такой option
      const optionExists = Array.from(el.options).some(o => o.value === val);
      if (optionExists) {
        el.value = val;
      }
    }
  });
}

/** Debug-лог — работает только при localStorage.BK_DEBUG=true */
export function debug(...args) {
  try { if (localStorage.getItem('BK_DEBUG') === 'true') console.log(...args); } catch(e) { /* noop */ }
}