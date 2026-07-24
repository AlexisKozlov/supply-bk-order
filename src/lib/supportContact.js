/**
 * Контакт поддержки портала (Telegram).
 * Одно общее значение, настраивается в админке «Кабинет ресторанов».
 * Загружается один раз и кешируется; до загрузки — запасной логин.
 *
 * Использование в компоненте:
 *   import { useSupportContact } from '@/lib/supportContact.js';
 *   const support = useSupportContact();   // ref со строкой-логином без @
 *   // support.value → 'alexiskozlov'
 *   // ссылка:  https://t.me/${support.value}   подпись:  @${support.value}
 */
import { ref } from 'vue';

const FALLBACK = 'alexiskozlov';
const username = ref(FALLBACK);
let started = false;

export function useSupportContact() {
  if (!started) {
    started = true;
    fetch('/api/ro/support-contact')
      .then((r) => (r.ok ? r.json() : null))
      .then((d) => {
        const v = d && d.support_telegram ? String(d.support_telegram).replace(/^@/, '').trim() : '';
        if (v) username.value = v;
      })
      .catch(() => {});
  }
  return username;
}
