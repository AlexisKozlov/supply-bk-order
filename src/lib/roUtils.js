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

export const EXCEL_TRACEABLE_STYLE = {
  fill: { fgColor: { rgb: 'FFF9C4' } },
};

// ═══ Общий fetch для кабинета ресторана ═══
// Оборачивает fetch:
//  - авторизация через HttpOnly-cookie ro_session (браузер шлёт сам)
//  - ставит Content-Type когда передаётся объект как body
//  - таймаут по умолчанию 15 сек (через AbortController)
//  - бросает Error при HTTP-ошибках и таймаутах (для удобной try/catch)

const DEFAULT_TIMEOUT = 15000;

export async function roFetch(url, opts = {}) {
  const method = opts.method || 'GET';
  const timeout = opts.timeout ?? DEFAULT_TIMEOUT;

  const headers = { ...(opts.headers || {}) };

  let body = opts.body;
  if (body && typeof body === 'object' && !(body instanceof FormData) && !(body instanceof Blob) && !(body instanceof ArrayBuffer)) {
    body = JSON.stringify(body);
    if (!headers['Content-Type']) headers['Content-Type'] = 'application/json';
  }

  const ctrl = new AbortController();
  const tid = setTimeout(() => ctrl.abort(), timeout);
  let res;
  try {
    res = await fetch(url, { method, headers, body, signal: ctrl.signal });
  } catch (e) {
    if (e.name === 'AbortError') throw new Error('Запрос слишком долгий — попробуйте позже');
    throw e;
  } finally {
    clearTimeout(tid);
  }

  if (opts.skipParse) return res;
  let data = null;
  try { data = await res.json(); } catch { /* пустой ответ */ }
  if (!res.ok) {
    const msg = (data && data.error) || `Ошибка ${res.status}`;
    const err = new Error(msg);
    err.status = res.status;
    err.data = data;
    throw err;
  }
  return data;
}
