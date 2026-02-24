/**
 * Бургер БК и Воглия Матта — одна группа (общие поставщики и товары).
 * Пицца Стар — отдельная группа.
 * Возвращает массив юр. лиц для фильтрации.
 */
export function getEntityGroup(legalEntity) {
  if (legalEntity === 'Пицца Стар') return ['Пицца Стар'];
  return ['Бургер БК', 'Воглия Матта'];
}

/**
 * Применяет фильтр юр. лица к db query.
 * Использует .or() вместо .in() т.к. PHP бэкенд удаляет пробелы из in.()
 */
export function applyEntityFilter(query, legalEntity, column = 'legal_entity') {
  const group = getEntityGroup(legalEntity);
  if (group.length === 1) {
    return query.eq(column, group[0]);
  }
  return query.or(group.map(e => `${column}.eq.${e}`).join(','));
}

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

export function getQpb(item) {
  return item.qtyPerBox || 1;
}

export function getMultiplicity(item) {
  return item.multiplicity || 1;
}

export function toLocalDateStr(d) {
  if (!(d instanceof Date) || isNaN(d)) return '';
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function debug(...args) {
  try { if (localStorage.getItem('BK_DEBUG') === 'true') console.log(...args); } catch(e) { /* noop */ }
}

export function copyToClipboard(text) {
  if (navigator.clipboard && navigator.clipboard.writeText) {
    return navigator.clipboard.writeText(text);
  }
  return new Promise((resolve, reject) => {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    try {
      textarea.select();
      textarea.setSelectionRange(0, 99999);
      const successful = document.execCommand('copy');
      if (successful) resolve();
      else reject(new Error('execCommand failed'));
    } catch (err) {
      reject(err);
    } finally {
      document.body.removeChild(textarea);
    }
  });
}
