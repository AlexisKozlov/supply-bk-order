/**
 * Календарь поставок
 * delivery-calendar.js
 * 
 * Визуальный месячный календарь:
 * - Даты поставок из истории заказов
 * - Цветовая разметка по поставщикам
 * - Индикация просроченных / скоро заказ
 */

import { supabase } from './supabase.js';
import { showToast } from './modals.js';

const PALETTE = [
  '#F5A623','#4CAF50','#2196F3','#9C27B0',
  '#F44336','#00BCD4','#FF5722','#607D8B',
  '#E91E63','#795548'
];

const MONTH_NAMES = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
const DAY_NAMES = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];

let calState = {
  year: new Date().getFullYear(),
  month: new Date().getMonth(),
  legalEntity: 'Бургер БК',
  orders: [],
  supplierColors: {}
};

/* ═══════ INIT ═══════ */

export function initDeliveryCalendar() {
  const btn = document.getElementById('menuCalendar');
  const modal = document.getElementById('calendarModal');
  const closeBtn = document.getElementById('closeCalendar');
  if (!btn || !modal) return;

  btn.addEventListener('click', async () => {
    modal.classList.remove('hidden');
    // Берём юр.лицо из основного интерфейса
    const mainLegal = document.getElementById('legalEntity');
    if (mainLegal) calState.legalEntity = mainLegal.value;
    await loadCalendarData();
    renderCalendar();
  });

  closeBtn.addEventListener('click', () => modal.classList.add('hidden'));
  modal.addEventListener('click', (e) => { if (e.target === modal) modal.classList.add('hidden'); });
}

/* ═══════ DATA ═══════ */

async function loadCalendarData() {
  // Загружаем заказы за 3 месяца назад и 2 месяца вперёд (по delivery_date)
  const from = new Date(calState.year, calState.month - 2, 1);
  const to = new Date(calState.year, calState.month + 3, 0);

  const { data, error } = await supabase
    .from('orders')
    .select('id, supplier, delivery_date, created_at, order_items(qty_boxes)')
    .eq('legal_entity', calState.legalEntity)
    .gte('delivery_date', from.toISOString().slice(0, 10))
    .lte('delivery_date', to.toISOString().slice(0, 10))
    .order('delivery_date', { ascending: true });

  if (error) {
    console.error('Calendar data error:', error);
    calState.orders = [];
    return;
  }

  calState.orders = data || [];

  // Назначаем цвета поставщикам
  const suppliers = [...new Set(calState.orders.map(o => o.supplier).filter(Boolean))];
  suppliers.sort((a, b) => a.localeCompare(b, 'ru'));
  calState.supplierColors = {};
  suppliers.forEach((s, i) => {
    calState.supplierColors[s] = PALETTE[i % PALETTE.length];
  });
}

/* ═══════ RENDER ═══════ */

function renderCalendar() {
  const container = document.getElementById('calendarContainer');
  if (!container) return;

  const year = calState.year;
  const month = calState.month;
  const today = new Date();
  today.setHours(0, 0, 0, 0);

  // Первый день месяца
  const firstDay = new Date(year, month, 1);
  const lastDay = new Date(year, month + 1, 0);
  const daysInMonth = lastDay.getDate();

  // День недели первого числа (0=Вс → нужно сдвинуть для Пн-старта)
  let startDay = firstDay.getDay();
  startDay = startDay === 0 ? 6 : startDay - 1; // Пн=0

  // Группируем заказы по дате
  const ordersByDate = {};
  calState.orders.forEach(o => {
    const d = o.delivery_date?.slice(0, 10);
    if (!d) return;
    if (!ordersByDate[d]) ordersByDate[d] = [];
    ordersByDate[d].push(o);
  });

  // Последний заказ по поставщику для расчёта "дней без заказа"
  const lastOrderBySupplier = {};
  calState.orders.forEach(o => {
    const d = new Date(o.delivery_date);
    const sup = o.supplier;
    if (!sup) return;
    if (!lastOrderBySupplier[sup] || d > lastOrderBySupplier[sup]) {
      lastOrderBySupplier[sup] = d;
    }
  });

  let html = '';

  // Навигация
  html += `
    <div class="cal-nav">
      <button class="cal-nav-btn" id="calPrev">◀</button>
      <span class="cal-nav-title">${MONTH_NAMES[month]} ${year}</span>
      <button class="cal-nav-btn" id="calNext">▶</button>
    </div>
  `;

  // Легенда поставщиков
  const suppliers = Object.keys(calState.supplierColors);
  if (suppliers.length) {
    html += '<div class="cal-legend">';
    suppliers.forEach(s => {
      const daysSince = lastOrderBySupplier[s]
        ? Math.round((today - lastOrderBySupplier[s]) / 86400000)
        : null;
      const urgencyClass = daysSince !== null && daysSince > 14 ? 'cal-legend-urgent' : '';
      const daysLabel = daysSince !== null ? ` (${daysSince}д)` : '';
      html += `<span class="cal-legend-item ${urgencyClass}"><span class="cal-legend-dot" style="background:${calState.supplierColors[s]}"></span>${s}${daysLabel}</span>`;
    });
    html += '</div>';
  }

  // Сетка календаря
  html += '<div class="cal-grid">';

  // Заголовки дней
  DAY_NAMES.forEach(d => {
    html += `<div class="cal-day-header">${d}</div>`;
  });

  // Пустые ячейки до первого дня
  for (let i = 0; i < startDay; i++) {
    html += '<div class="cal-cell cal-empty"></div>';
  }

  // Дни месяца
  for (let day = 1; day <= daysInMonth; day++) {
    const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    const cellDate = new Date(year, month, day);
    const isToday = cellDate.getTime() === today.getTime();
    const isPast = cellDate < today;
    const orders = ordersByDate[dateStr] || [];

    let classes = 'cal-cell';
    if (isToday) classes += ' cal-today';
    if (isPast && !isToday) classes += ' cal-past';
    if (orders.length) classes += ' cal-has-orders';

    html += `<div class="${classes}">`;
    html += `<span class="cal-day-num">${day}</span>`;

    if (orders.length) {
      html += '<div class="cal-orders">';
      orders.forEach(o => {
        const color = calState.supplierColors[o.supplier] || '#999';
        const boxes = (o.order_items || []).reduce((s, i) => s + (i.qty_boxes || 0), 0);
        html += `<div class="cal-order-dot" style="background:${color}" title="${o.supplier}: ${boxes} кор" data-order-id="${o.id}"></div>`;
      });
      html += '</div>';
    }

    html += '</div>';
  }

  html += '</div>';

  // Сводка: поставщики без заказа > 14 дней
  const overdue = suppliers.filter(s => {
    const days = lastOrderBySupplier[s]
      ? Math.round((today - lastOrderBySupplier[s]) / 86400000)
      : 999;
    return days > 14;
  });

  if (overdue.length) {
    html += '<div class="cal-overdue">';
    html += `⚠️ Давно без заказа: ${overdue.map(s => {
      const days = Math.round((today - lastOrderBySupplier[s]) / 86400000);
      return `<b>${s}</b> (${days} дн.)`;
    }).join(', ')}`;
    html += '</div>';
  }

  container.innerHTML = html;

  // Навигация
  document.getElementById('calPrev')?.addEventListener('click', () => {
    calState.month--;
    if (calState.month < 0) { calState.month = 11; calState.year--; }
    loadCalendarData().then(() => renderCalendar());
  });

  document.getElementById('calNext')?.addEventListener('click', () => {
    calState.month++;
    if (calState.month > 11) { calState.month = 0; calState.year++; }
    loadCalendarData().then(() => renderCalendar());
  });

  // Клик по точке заказа → загрузить этот заказ
  container.querySelectorAll('.cal-order-dot').forEach(dot => {
    dot.style.cursor = 'pointer';
    dot.addEventListener('click', async () => {
      const orderId = dot.dataset.orderId;
      if (!orderId) return;

      // Загружаем полный заказ
      const { data: order, error } = await supabase
        .from('orders')
        .select('*, order_items(*)')
        .eq('id', orderId)
        .single();

      if (error || !order) {
        showToast('Ошибка', 'Не удалось загрузить заказ', 'error');
        return;
      }

      // Отправляем событие — ui.js слушает и загружает заказ
      document.dispatchEvent(new CustomEvent('calendar:load-order', {
        detail: { order, legalEntity: calState.legalEntity }
      }));

      // Закрываем календарь
      document.getElementById('calendarModal')?.classList.add('hidden');
    });
  });
}