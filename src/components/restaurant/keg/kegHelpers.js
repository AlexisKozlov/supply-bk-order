// Утилиты и иконки для компонентов «Возврат кег».
// Цель — не дублировать одинаковые куски между секциями формы, списком и модалками.

export const iconKeg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="4.5" rx="6" ry="1.7"/><path d="M6 4.5v15c0 .9 2.7 1.7 6 1.7s6-.8 6-1.7v-15"/><path d="M6 9c0 .9 2.7 1.7 6 1.7S18 9.9 18 9"/><path d="M6 14.5c0 .9 2.7 1.7 6 1.7s6-.8 6-1.7"/></svg>';

// Иконка «Возврат кег» — кега + дугообразная стрелка возврата сверху.
export const iconKegReturn = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8 Q 4 3 12 3 Q 20 3 20 8"/><polyline points="6.5 6 4 8 6.5 10.5"/><ellipse cx="12" cy="10.5" rx="5" ry="1.5"/><path d="M7 10.5V20c0 .8 2.2 1.5 5 1.5s5-.7 5-1.5v-9.5"/><path d="M7 14c0 .8 2.2 1.5 5 1.5s5-.7 5-1.5"/><path d="M7 17.5c0 .8 2.2 1.5 5 1.5s5-.7 5-1.5"/></svg>';

export const WEEKDAY_NAMES = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

// Короткая дата в локали ru.
export function fmtDate(d) {
  if (!d) return '—';
  const dt = new Date(d);
  if (isNaN(dt)) return d;
  return dt.toLocaleDateString('ru-RU');
}

// Дата + время (для истории замен БСО).
export function fmtDateTime(s) {
  if (!s) return '';
  const d = new Date(s.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return s;
  const date = d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
  const time = d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  return `${date} ${time}`;
}

// «ДД.ММ.ГГГГ в ЧЧ:ММ» — для подписей дедлайнов.
export function fmtIsoLocal(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const date = d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
  const time = d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  return `${date} в ${time}`;
}

export function statusLabel(s) {
  const map = { DRAFT: 'Черновик', SUBMITTED: 'Отправлена', ROUTED: 'Маршрутизирована', CANCELLED: 'Отменена', NOT_RETURNED: 'Не сдана' };
  return map[s] || s;
}

// Дедлайн ресторана = 10:00 в предыдущий рабочий день относительно даты возврата.
export function calcDeadlineMs(isoDate) {
  const [Y, M, D] = isoDate.split('-').map(Number);
  const d = new Date(Y, M - 1, D, 10, 0, 0, 0);
  do { d.setDate(d.getDate() - 1); } while (d.getDay() === 0 || d.getDay() === 6);
  return d.getTime();
}

// Список доступных дат возврата (по битмаске разрешённых дней недели).
// Если редактируется заявка с уже сохранённой датой, не попадающей в маску, она добавляется первой.
// opts.includePastDeadline = true: даты с уже прошедшим дедлайном не отбрасываются,
//   а возвращаются с флагом deadlinePassed (для портала закупок — там разрешено
//   создавать заявку и после дедлайна, с предупреждением).
export function buildAvailableDates(weekdayMask, currentDate, opts = {}) {
  const includePastDeadline = !!opts.includePastDeadline;
  const mask = parseInt(weekdayMask || 0, 10);
  const out = [];
  if (mask) {
    const today = new Date();
    today.setHours(12, 0, 0, 0);
    const now = Date.now();
    for (let i = 0; i < 60 && out.length < 3; i++) {
      const d = new Date(today);
      d.setDate(d.getDate() + i);
      const jsDay = d.getDay();
      const bit = jsDay === 0 ? 6 : jsDay - 1;
      if (!(mask & (1 << bit))) continue;
      const iso = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
      const deadlinePassed = calcDeadlineMs(iso) <= now;
      if (deadlinePassed && !includePastDeadline) continue;
      let label = String(d.getDate()).padStart(2, '0') + '.' + String(d.getMonth() + 1).padStart(2, '0') + '.' + d.getFullYear() + ' (' + WEEKDAY_NAMES[bit] + ')';
      if (deadlinePassed) label += ' — дедлайн прошёл';
      out.push({ iso, label, deadlinePassed });
    }
  }
  if (currentDate && !out.some(d => d.iso === currentDate)) {
    const dt = new Date(currentDate + 'T12:00:00');
    if (!Number.isNaN(dt.getTime())) {
      const jsDay = dt.getDay();
      const bit = jsDay === 0 ? 6 : jsDay - 1;
      const label = String(dt.getDate()).padStart(2, '0') + '.' + String(dt.getMonth() + 1).padStart(2, '0') + '.' + dt.getFullYear() + ' (' + WEEKDAY_NAMES[bit] + ')';
      out.unshift({ iso: currentDate, label });
    }
  }
  return out;
}

export function pluralKegs(n) {
  const m10 = n % 10, m100 = n % 100;
  if (m10 === 1 && m100 !== 11) return 'кега';
  if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return 'кеги';
  return 'кег';
}

export function pluralTypes(n) {
  const m10 = n % 10, m100 = n % 100;
  if (m10 === 1 && m100 !== 11) return 'типе';
  return 'типах';
}

// Маски ввода для БСО.
export function maskBsoSeries(raw) {
  return String(raw || '').replace(/[^А-Яа-яЁё]/g, '').toUpperCase().slice(0, 2);
}
export function maskBsoNumber(raw) {
  return String(raw || '').replace(/\D/g, '').slice(0, 7);
}

export const BSO_REASONS = [
  { key: 'PRINT_DAMAGED', label: 'Испорчен при печати' },
  { key: 'WRONG_FORM',    label: 'Не тот бланк / не та сторона' },
  { key: 'LOST',          label: 'Утерян' },
  { key: 'OTHER',         label: 'Другое (указать)' },
];
