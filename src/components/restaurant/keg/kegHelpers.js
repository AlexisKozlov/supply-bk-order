// Утилиты и иконки для компонентов «Возврат кег».
// Цель — не дублировать одинаковые куски между List/Form/модалками.

export const iconKeg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="4.5" rx="6" ry="1.7"/><path d="M6 4.5v15c0 .9 2.7 1.7 6 1.7s6-.8 6-1.7v-15"/><path d="M6 9c0 .9 2.7 1.7 6 1.7S18 9.9 18 9"/><path d="M6 14.5c0 .9 2.7 1.7 6 1.7s6-.8 6-1.7"/></svg>';

// Иконка «Возврат кег» — кега + дугообразная стрелка возврата сверху.
export const iconKegReturn = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8 Q 4 3 12 3 Q 20 3 20 8"/><polyline points="6.5 6 4 8 6.5 10.5"/><ellipse cx="12" cy="10.5" rx="5" ry="1.5"/><path d="M7 10.5V20c0 .8 2.2 1.5 5 1.5s5-.7 5-1.5v-9.5"/><path d="M7 14c0 .8 2.2 1.5 5 1.5s5-.7 5-1.5"/><path d="M7 17.5c0 .8 2.2 1.5 5 1.5s5-.7 5-1.5"/></svg>';

export function fmtDate(d) {
  if (!d) return '—';
  const dt = new Date(d);
  if (isNaN(dt)) return d;
  return dt.toLocaleDateString('ru-RU');
}

export function statusLabel(s) {
  const map = { DRAFT: 'Черновик', SUBMITTED: 'Отправлена', ROUTED: 'Маршрутизирована', CANCELLED: 'Отменена' };
  return map[s] || s;
}
