/**
 * Общие утилиты модуля «Заказы ресторанов» (ro_*)
 * Используется в: RestaurantOrdersManagerView, RestaurantCabinetView,
 *                  RestaurantReportView, RestaurantOrderLoginView
 */

// ═══ Форматирование дат ═══

export function formatDate(d) {
  if (!d) return '';
  return new Date(d + 'T00:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' });
}

export function formatDateShort(d) {
  if (!d) return '';
  return new Date(d + 'T00:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
}

export function formatTime(dt) {
  if (!dt) return '';
  return new Date(dt).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

export function formatDateTime(dt) {
  if (!dt) return '';
  const d = new Date(dt);
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }) + ' ' +
    d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

// ═══ Статусы заказов ═══

export const STATUS_LABELS = {
  submitted: 'Подано',
  edited: 'Изменён',
  draft: 'Черновик',
  locked: 'Заблокирован',
};

export function statusLabel(status) {
  return STATUS_LABELS[status] || 'Не подано';
}

// ═══ Excel стили (xlsx-js-style) ═══

export const EXCEL_HEADER_STYLE = {
  font: { bold: true, color: { rgb: 'FFFFFF' } },
  fill: { fgColor: { rgb: '502314' } },
};

export const EXCEL_SUBTOTAL_STYLE = {
  font: { bold: true, color: { rgb: '502314' } },
  fill: { fgColor: { rgb: 'F5F0EB' } },
};

export const EXCEL_TOTAL_STYLE = {
  font: { bold: true, color: { rgb: 'FFFFFF' } },
  fill: { fgColor: { rgb: 'D62300' } },
};
