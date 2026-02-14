/**
 * Модуль отправки заказа через мессенджеры
 * share-order.js
 * 
 * Подставляет контакты поставщика из suppliers таблицы
 */

import { orderState } from './state.js';
import { showToast } from './modals.js';
import { getSupplierContacts } from './database.js';

const nf = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });

const CHANNELS = [
  { id: 'whatsapp',  label: 'WhatsApp', color: '#25D366' },
  { id: 'telegram',  label: 'Telegram', color: '#0088cc' },
  { id: 'viber',     label: 'Viber',    color: '#7360f2' },
  { id: 'email',     label: 'Email',    color: '#8B7355' },
];

/** Кеш контактов поставщика */
let cachedContacts = null;
let cachedSupplier = '';

async function getContacts(supplier) {
  if (cachedSupplier === supplier && cachedContacts) return cachedContacts;
  cachedContacts = await getSupplierContacts(supplier);
  cachedSupplier = supplier;
  return cachedContacts;
}

/** Формирует текст заказа */
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

/** Открывает ссылку мессенджера с контактом поставщика */
async function openChannel(channel, order) {
  const encoded = encodeURIComponent(order.text);
  const contacts = await getContacts(order.supplier);

  const subject = encodeURIComponent(
    `Заказ ${order.supplier} на ${order.deliveryDate}`
  );

  if (channel === 'telegram') {
    navigator.clipboard.writeText(order.text).then(() => {
      const tgContact = contacts?.telegram?.trim();
      if (tgContact) {
        if (tgContact.startsWith('+') || /^\d/.test(tgContact)) {
          // Телефон — открываем по номеру
          const phone = tgContact.replace(/[^+\d]/g, '');
          showToast('Текст скопирован', `Открываю чат с ${tgContact} — нажмите Ctrl+V`, 'success');
          window.open(`tg://resolve?phone=${phone.replace('+', '')}`, '_self');
        } else {
          // Username
          const username = tgContact.replace(/^@/, '');
          showToast('Текст скопирован', `Открываю чат с @${username} — нажмите Ctrl+V`, 'success');
          window.open(`tg://resolve?domain=${username}`, '_self');
        }
      } else {
        showToast('Текст скопирован в буфер', 'Выберите чат в Telegram и нажмите Ctrl+V', 'success');
        window.open('tg://', '_self');
      }
    }).catch(() => {
      showToast('Ошибка', 'Не удалось скопировать текст', 'error');
    });
    return;
  }

  if (channel === 'whatsapp') {
    const phone = contacts?.whatsapp?.replace(/[^+\d]/g, '') || '';
    const url = phone
      ? `https://wa.me/${phone}?text=${encoded}`
      : `https://wa.me/?text=${encoded}`;
    window.open(url, '_blank');
    showToast('Отправка', `${order.count} позиций → WhatsApp${phone ? ` (${contacts.whatsapp})` : ''}`, 'success');
    return;
  }

  if (channel === 'viber') {
    const phone = contacts?.viber?.replace(/[^+\d]/g, '') || '';
    if (phone) {
      // Viber не поддерживает предзаполненный текст в chat deep link
      // Копируем текст + открываем чат с контактом
      navigator.clipboard.writeText(order.text).then(() => {
        showToast('Текст скопирован', `Открываю Viber чат с ${contacts.viber} — нажмите Ctrl+V`, 'success');
        window.open(`viber://chat?number=${encodeURIComponent(phone)}`, '_blank');
      }).catch(() => {
        window.open(`viber://forward?text=${encoded}`, '_blank');
        showToast('Отправка', `${order.count} позиций → Viber`, 'success');
      });
    } else {
      window.open(`viber://forward?text=${encoded}`, '_blank');
      showToast('Отправка', `${order.count} позиций → Viber (выберите контакт)`, 'success');
    }
    return;
  }

  if (channel === 'email') {
    const to = contacts?.email || '';
    const url = `mailto:${to}?subject=${subject}&body=${encoded}`;
    window.open(url, '_blank');
    showToast('Отправка', `${order.count} позиций → Email${to ? ` (${to})` : ''}`, 'success');
    return;
  }
}

/** Инициализация кнопки и дропдауна */
export function initShareOrder() {
  const btn = document.getElementById('shareOrderBtn');
  if (!btn) return;

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

  btn.addEventListener('click', async (e) => {
    const channelBtn = e.target.closest('[data-channel]');

    if (channelBtn) {
      e.stopPropagation();
      dropdown.classList.add('hidden');

      const order = buildOrderText();
      if (!order) {
        showToast('Заказ пуст', 'Добавьте товары в заказ', 'error');
        return;
      }
      await openChannel(channelBtn.dataset.channel, order);
    } else {
      if (!orderState.items.length) {
        showToast('Заказ пуст', 'Добавьте товары в заказ', 'error');
        return;
      }

      // Подгружаем контакты и показываем в дропдауне
      const supplier = orderState.settings.supplier;
      const contacts = supplier ? await getContacts(supplier) : null;
      
      dropdown.querySelectorAll('button[data-channel]').forEach(b => {
        const ch = b.dataset.channel;
        const contact = contacts?.[ch] || '';
        const hint = b.querySelector('.share-contact-hint');
        if (hint) hint.remove();
        if (contact) {
          const span = document.createElement('span');
          span.className = 'share-contact-hint';
          span.style.cssText = 'font-size:10px;color:#888;margin-left:auto;';
          span.textContent = contact.length > 20 ? contact.slice(0, 18) + '…' : contact;
          b.appendChild(span);
        }
      });

      dropdown.classList.toggle('hidden');
    }
  });

  document.addEventListener('click', (e) => {
    if (!btn.contains(e.target)) {
      dropdown.classList.add('hidden');
    }
  });
}