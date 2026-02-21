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

/**
 * Конверсия единиц шт↔кор (переключатель в UI).
 * Используется ТОЛЬКО qty_per_box. multiplicity НЕ участвует.
 * Молоко (qty_per_box=1): 12000 шт = 12000 кор
 * Тар тар (qty_per_box=1000): 10000 шт = 10 кор
 */
export function getQpb(item) {
  return item.qtyPerBox || 1;
}

/**
 * Количество учётных единиц (кор 1С) в одной ФИЗИЧЕСКОЙ коробке.
 * Молоко: multiplicity=12, т.е. физ. коробка = 12 учётных коробок (12 литров)
 * Тар тар: multiplicity=3, т.е. физ. коробка = 3 учётных коробки (3000 шт)
 * Для товаров без кратности: 1 (учётная коробка = физическая)
 */
export function getMultiplicity(item) {
  return item.multiplicity || 1;
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
    if (!el || !el.options) return;
    const optionExists = Array.from(el.options).some(o => o.value === val);
    if (optionExists) {
      el.value = val;
    }
  });
}

/** Debug-лог — работает только при localStorage.BK_DEBUG=true */
export function debug(...args) {
  try { if (localStorage.getItem('BK_DEBUG') === 'true') console.log(...args); } catch(e) { /* noop */ }
}