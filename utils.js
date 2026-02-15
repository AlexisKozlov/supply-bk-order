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

/** Debug-лог — работает только при localStorage.BK_DEBUG=true */
export function debug(...args) {
  try { if (localStorage.getItem('BK_DEBUG') === 'true') console.log(...args); } catch(e) { /* noop */ }
}