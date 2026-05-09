import { getEntityGroup, getEntityGroupCode } from '@/lib/legalEntities.js';
import { orVal } from '@/lib/apiClient.js';

// Re-export для обратной совместимости (другие модули импортируют из utils)
export { getEntityGroup, getEntityGroupCode };

/**
 * Применяет фильтр юр. лица к db query по текстовой колонке legal_entity.
 * Используется для таблиц с данными (orders, analysis_data, stock_1c и т.п.),
 * где каждая запись хранит полное название юрлица.
 * .or() вместо .in() — PHP бэкенд удаляет пробелы из значений в in.()
 */
export function applyEntityFilter(query, legalEntity, column = 'legal_entity') {
  const group = getEntityGroup(legalEntity);
  if (group.length === 1) {
    return query.eq(column, group[0]);
  }
  return query.or(group.map(e => orVal(column, 'eq', e)).join(','));
}

/**
 * Применяет фильтр группы юрлиц к db query по колонке legal_entity_group.
 * Используется для справочников (products, suppliers, restaurants),
 * которые делятся не на конкретное юрлицо, а на группу: BK_VM или PS.
 * Быстрее applyEntityFilter: один .eq() вместо .or() по двум значениям.
 */
export function applyEntityGroupFilter(query, legalEntity, column = 'legal_entity_group') {
  return query.eq(column, getEntityGroupCode(legalEntity));
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

/**
 * Перевод finalOrder в УЧЁТНЫЕ коробки.
 * Учётные = коробки в той фасовке, в которой товар лежит в учёте (qty_per_box штук в каждой).
 * Округление вверх — иначе при ручной правке finalOrder в штуках с остатком от qpb
 * можно потерять часть заказа в БД (Math.round(1.41) = 1 → недозаказ).
 */
export function toAccountingBoxes(item, finalOrder, unit) {
  const qpb = item.qtyPerBox > 0 ? item.qtyPerBox : 1;
  const value = unit === 'boxes' ? (finalOrder || 0) : (finalOrder || 0) / qpb;
  return Math.max(0, Math.ceil(value));
}

/**
 * Перевод finalOrder в ФИЗИЧЕСКИЕ коробки.
 * Физические = коробки той фасовки, что отгружает поставщик (если кратность=2,
 * то 1 физ.коробка = 2 учётных). Используется для расчёта паллет и текста заказа.
 * Округление вверх по тем же причинам.
 */
export function toPhysicalBoxes(item, finalOrder, unit) {
  const mult = item.multiplicity > 0 ? item.multiplicity : 1;
  return Math.ceil(toAccountingBoxes(item, finalOrder, unit) / mult);
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

/**
 * Парсит строку даты, безопасно обрабатывая даты без времени (YYYY-MM-DD).
 * Добавляет T00:00:00, чтобы дата не сдвигалась из-за таймзоны.
 */
export function parseLocalDate(str) {
  if (!str) return null;
  if (typeof str === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(str)) {
    return new Date(str + 'T00:00:00');
  }
  return new Date(str);
}

/**
 * Форматирует дату в «дд.мм.гггг» (полная дата).
 */
export function formatDate(d) {
  if (!d) return '';
  const dt = parseLocalDate(d);
  if (isNaN(dt)) return d;
  return dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

/**
 * Форматирует дату в «дд.мм» (короткая, без года).
 */
export function formatDateShort(d) {
  if (!d) return '';
  const dt = parseLocalDate(d);
  if (isNaN(dt)) return '';
  return dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
}

/**
 * Форматирует дату и время в «дд.мм.гг чч:мм» (2-значный год).
 */
export function formatDateTime(d) {
  if (!d) return '';
  const dt = parseLocalDate(d);
  if (isNaN(dt)) return '';
  return dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' }) + ' ' +
         dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

/**
 * Форматирует дату и время в «дд.мм.гггг чч:мм» (4-значный год).
 */
export function formatDateTimeFull(d) {
  if (!d) return '';
  const dt = parseLocalDate(d);
  if (isNaN(dt)) return d;
  return dt.toLocaleDateString('ru-RU') + ' ' +
         dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

/**
 * Форматирует дату и время в «дд.мм чч:мм» (без года).
 */
export function formatDateTimeShort(d) {
  if (!d) return '';
  const dt = parseLocalDate(d);
  if (isNaN(dt)) return '';
  return dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' }) + ' ' +
         dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
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
