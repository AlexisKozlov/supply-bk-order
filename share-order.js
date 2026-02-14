/**
 * Модуль отправки заказа через мессенджеры
 * share-order.js
 */

import { orderState } from './state.js';
import { showToast } from './modals.js';

const nf = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });

const CHANNELS = [
  { id: 'whatsapp',  label: 'WhatsApp', color: '#25D366' },
  { id: 'telegram',  label: 'Telegram', color: '#0088cc' },
  { id: 'viber',     label: 'Viber',    color: '#7360f2' },
  { id: 'email',     label: 'Email',    color: '#8B7355' },
];

/** Формирует текст заказа — такой же как при копировании */
function buildOrderText() {
  if (!orderState.items.length) return null;

  const deliveryDate = orderState.settings.deliveryDate
    ? orderState.settings.deliveryDate.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
    : '—';

  const lines = orderState.items
    .map(item => {
      const boxes = orderState.settings.unit === 'boxes'
        ? item.finalOrder
        : item.finalOrder / item.qtyPerBox;

      const pieces = orderState.settings.unit === 'pieces'
        ? item.finalOrder
        : item.finalOrder * item.qtyPerBox;

      const roundedBoxes = Math.ceil(boxes);
      const roundedPieces = Math.round(pieces);

      if (roundedBoxes <= 0) return null;

      const name = `${item.sku ? item.sku + ' ' : ''}${item.name}`;
      const unit = item.unitOfMeasure || 'шт';

      return `${name} (${nf.format(roundedPieces)} ${unit}) - ${roundedBoxes} коробок`;
    })
    .filter(Boolean);

  if (!lines.length) return null;

  const legalEntity = orderState.settings.legalEntity || 'Бургер БК';
  const supplier = orderState.settings.supplier || '';

  return {
    text: `Добрый день!\nПросьба поставить для юр. лица ${legalEntity}, на дату - ${deliveryDate}:\n\n${lines.join('\n')}\n\nСпасибо!`,
    count: lines.length,
    supplier,
    deliveryDate,
  };
}

/** Открывает ссылку мессенджера */
function openChannel(channel, order) {
  const encoded = encodeURIComponent(order.text);

  const subject = encodeURIComponent(
    `Заказ ${order.supplier} на ${order.deliveryDate}`
  );

  const urls = {
    whatsapp: `https://wa.me/?text=${encoded}`,
    telegram: `https://t.me/share?url=&text=${encoded}`,
    viber:    `viber://forward?text=${encoded}`,
    email:    `mailto:?subject=${subject}&body=${encoded}`,
  };

  const url = urls[channel];
  if (!url) return;

  window.open(url, '_blank');
  showToast('Отправка', `${order.count} позиций → ${CHANNELS.find(c => c.id === channel)?.label}`, 'success');
}

/** Инициализация кнопки и дропдауна */
export function initShareOrder() {
  const btn = document.getElementById('shareOrderBtn');
  if (!btn) return;

  // Создаём дропдаун
  const dropdown = document.createElement('div');
  dropdown.className = 'share-dropdown hidden';

  CHANNELS.forEach(ch => {
    const item = document.createElement('button');
    item.type = 'button';
    item.dataset.channel = ch.id;
    item.innerHTML = `<span class="share-dot" style="background:${ch.color}"></span>${ch.label}`;
    dropdown.appendChild(item);
  });

  btn.style.position = 'relative';
  btn.appendChild(dropdown);

  // Клик по кнопке — показать/скрыть дропдаун
  btn.addEventListener('click', (e) => {
    const channelBtn = e.target.closest('[data-channel]');

    if (channelBtn) {
      // Клик по пункту мессенджера
      e.stopPropagation();
      dropdown.classList.add('hidden');

      const order = buildOrderText();
      if (!order) {
        showToast('Заказ пуст', 'Добавьте товары в заказ', 'error');
        return;
      }
      openChannel(channelBtn.dataset.channel, order);
    } else {
      // Клик по самой кнопке
      if (!orderState.items.length) {
        showToast('Заказ пуст', 'Добавьте товары в заказ', 'error');
        return;
      }
      dropdown.classList.toggle('hidden');
    }
  });

  // Закрыть при клике вне
  document.addEventListener('click', (e) => {
    if (!btn.contains(e.target)) {
      dropdown.classList.add('hidden');
    }
  });
}