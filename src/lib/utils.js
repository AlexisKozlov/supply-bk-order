/**
 * ООО "Бургер БК" и ООО "Воглия Матта" — одна группа (общие поставщики и товары).
 * ООО "Пицца Стар" — отдельная группа.
 * Возвращает массив юр. лиц для фильтрации.
 */
export function getEntityGroup(legalEntity) {
  if (legalEntity === 'ООО "Пицца Стар"') return ['ООО "Пицца Стар"'];
  return ['ООО "Бургер БК"', 'ООО "Воглия Матта"'];
}

/**
 * Применяет фильтр юр. лица к db query.
 * Использует .or() вместо .in() т.к. PHP бэкенд удаляет пробелы из значений в in.()
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

/**
 * Парсит MySQL datetime (без таймзоны, сервер в UTC+3) в Date.
 */
export function parseMoscowDate(str) {
  if (!str) return null;
  return new Date(str.replace(' ', 'T') + '+03:00');
}

/**
 * Форматирует MySQL datetime в «дд.мм.гггг чч:мм» (UTC+3).
 */
export function formatMoscowDateTime(str) {
  const d = parseMoscowDate(str);
  if (!d || isNaN(d)) return '';
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'Europe/Moscow' }) + ' ' +
         d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Moscow' });
}

/**
 * Форматирует MySQL datetime как «X мин назад» (UTC+3).
 */
export function formatMoscowRelative(str) {
  const d = parseMoscowDate(str);
  if (!d || isNaN(d)) return '';
  const diff = Math.floor((Date.now() - d.getTime()) / 1000);
  if (diff < 10) return 'только что';
  if (diff < 60) return `${diff} сек назад`;
  if (diff < 120) return '1 мин назад';
  if (diff < 3600) return `${Math.floor(diff / 60)} мин назад`;
  if (diff < 7200) return '1 час назад';
  if (diff < 86400) return `${Math.floor(diff / 3600)} ч назад`;
  if (diff < 172800) return 'вчера';
  if (diff < 604800) return `${Math.floor(diff / 86400)} дн назад`;
  return formatMoscowDateTime(str);
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
