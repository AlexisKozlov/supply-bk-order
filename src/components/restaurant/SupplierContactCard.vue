<template>
  <div class="sc-card" :class="{ 'sc-card--primary': contact.is_primary }">
    <div class="sc-head">
      <div class="sc-head-main">
        <div class="sc-name">
          <span class="sc-name-text">{{ contact.name || '(без имени)' }}</span>
          <span v-if="contact.is_primary" class="sc-badge sc-badge--primary" title="Основной контакт">★</span>
          <button v-if="contact.name" class="sc-copy sc-copy--inline" type="button" :title="copiedKey==='name' ? 'Скопировано' : 'Скопировать имя'" @click="copy('name', contact.name)">{{ copiedKey==='name' ? '✓' : '⧉' }}</button>
        </div>
        <div v-if="contact.role" class="sc-role">{{ contact.role }}</div>
      </div>
      <div v-if="showActions" class="sc-actions">
        <button class="sc-act" type="button" @click="$emit('edit', contact)" title="Редактировать">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        </button>
        <button class="sc-act sc-act--danger" type="button" @click="$emit('delete', contact)" title="Удалить">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
        </button>
      </div>
    </div>

    <div v-if="hasContacts" class="sc-links">
      <div v-for="(p, idx) in phonesList" :key="'p'+idx" class="sc-link sc-link--phone">
        <a class="sc-link-main" :href="`tel:${p.phone}`">
          <span class="sc-link-ico">📞</span>
          <span class="sc-link-text">{{ formatPhone(p.phone) }}<span v-if="p.label" class="sc-link-label"> · {{ p.label }}</span></span>
        </a>
        <button class="sc-copy" type="button" :title="copiedKey===('phone'+idx) ? 'Скопировано' : 'Скопировать'" @click.prevent.stop="copy('phone'+idx, p.phone)">{{ copiedKey===('phone'+idx) ? '✓' : '⧉' }}</button>
      </div>
      <div v-if="contact.telegram" class="sc-link sc-link--tg">
        <a class="sc-link-main" :href="telegramUrl" target="_blank" rel="noopener">
          <span class="sc-link-ico">✈</span>
          <span class="sc-link-text">{{ telegramLabel }}</span>
        </a>
        <button class="sc-copy" type="button" :title="copiedKey==='telegram' ? 'Скопировано' : 'Скопировать'" @click.prevent.stop="copy('telegram', telegramCopyValue)">{{ copiedKey==='telegram' ? '✓' : '⧉' }}</button>
      </div>
      <div v-if="contact.whatsapp" class="sc-link sc-link--wa">
        <a class="sc-link-main" :href="`https://wa.me/${stripPlus(contact.whatsapp)}`" target="_blank" rel="noopener">
          <span class="sc-link-ico">💬</span>
          <span class="sc-link-text">WhatsApp · {{ formatPhone(contact.whatsapp) }}</span>
        </a>
        <button class="sc-copy" type="button" :title="copiedKey==='whatsapp' ? 'Скопировано' : 'Скопировать'" @click.prevent.stop="copy('whatsapp', contact.whatsapp)">{{ copiedKey==='whatsapp' ? '✓' : '⧉' }}</button>
      </div>
      <div v-if="contact.viber" class="sc-link sc-link--vb">
        <a class="sc-link-main" :href="`viber://chat?number=${encodeURIComponent(contact.viber)}`">
          <span class="sc-link-ico">📲</span>
          <span class="sc-link-text">Viber · {{ formatPhone(contact.viber) }}</span>
        </a>
        <button class="sc-copy" type="button" :title="copiedKey==='viber' ? 'Скопировано' : 'Скопировать'" @click.prevent.stop="copy('viber', contact.viber)">{{ copiedKey==='viber' ? '✓' : '⧉' }}</button>
      </div>
      <div v-if="contact.email" class="sc-link sc-link--mail">
        <a class="sc-link-main" :href="`mailto:${contact.email}`">
          <span class="sc-link-ico">✉</span>
          <span class="sc-link-text">{{ contact.email }}</span>
        </a>
        <button class="sc-copy" type="button" :title="copiedKey==='email' ? 'Скопировано' : 'Скопировать'" @click.prevent.stop="copy('email', contact.email)">{{ copiedKey==='email' ? '✓' : '⧉' }}</button>
      </div>
    </div>

    <div v-if="(contact.tags || []).length" class="sc-tags">
      <span v-for="t in contact.tags" :key="t" class="sc-tag">{{ t }}</span>
    </div>

    <div v-if="contact.notes" class="sc-notes">{{ contact.notes }}</div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
  contact: { type: Object, required: true },
  showActions: { type: Boolean, default: false },
});
defineEmits(['edit', 'delete']);

const copiedKey = ref('');
let copiedTimer = null;
async function copy(key, value) {
  if (!value) return;
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(value);
    } else {
      // fallback для старых браузеров и http
      const el = document.createElement('textarea');
      el.value = value;
      el.style.position = 'fixed';
      el.style.opacity = '0';
      document.body.appendChild(el);
      el.select();
      document.execCommand('copy');
      document.body.removeChild(el);
    }
    copiedKey.value = key;
    clearTimeout(copiedTimer);
    copiedTimer = setTimeout(() => { copiedKey.value = ''; }, 1500);
  } catch (e) {
    /* не получилось — молча */
  }
}

const telegramCopyValue = computed(() => {
  const t = (props.contact.telegram || '').trim();
  if (!t) return '';
  return t.startsWith('+') ? t : '@' + t;
});

// Список телефонов: новые записи кладут массив `phones` (с подписями),
// старые — только одиночный `phone`. Fallback покрывает оба случая.
const phonesList = computed(() => {
  const c = props.contact;
  if (Array.isArray(c.phones) && c.phones.length) {
    return c.phones.filter(p => p && p.phone).map(p => ({ phone: p.phone, label: p.label || '' }));
  }
  if (c.phone) return [{ phone: c.phone, label: '' }];
  return [];
});
const hasContacts = computed(() => {
  const c = props.contact;
  return !!(phonesList.value.length || c.telegram || c.whatsapp || c.viber || c.email);
});

// Telegram-поле может быть либо @username, либо номером телефона (E.164).
// Бэк нормализует: username — без @, телефон — с ведущим +.
const telegramUrl = computed(() => {
  const t = (props.contact.telegram || '').trim();
  if (!t) return '#';
  if (t.startsWith('+')) {
    // у пользователя нет username, только номер — открыть чат по номеру
    return `https://t.me/${encodeURIComponent(t)}`;
  }
  return `https://t.me/${t}`;
});
const telegramLabel = computed(() => {
  const t = (props.contact.telegram || '').trim();
  if (!t) return '';
  return t.startsWith('+') ? formatPhone(t) : `@${t}`;
});

function stripPlus(s) { return (s || '').replace(/^\+/, ''); }

function formatPhone(p) {
  if (!p) return '';
  // +375291234567 → +375 (29) 123-45-67 (попытка, если белорусский)
  if (/^\+375\d{9}$/.test(p)) {
    return `+375 (${p.slice(4, 6)}) ${p.slice(6, 9)}-${p.slice(9, 11)}-${p.slice(11, 13)}`;
  }
  return p;
}
</script>

<style scoped>
.sc-card {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 14px 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.sc-card--primary {
  border-color: #d97706;
  background: linear-gradient(180deg, #fffbeb 0%, #fff 80%);
}
.sc-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}
.sc-head-main { min-width: 0; flex: 1; }
.sc-name {
  font-weight: 600;
  font-size: 16px;
  color: #1f2937;
  line-height: 1.3;
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.sc-name-text { user-select: text; }
.sc-role { user-select: text; }
.sc-notes { user-select: text; }
.sc-badge--primary {
  color: #d97706;
  font-size: 16px;
  line-height: 1;
}
.sc-role {
  margin-top: 2px;
  font-size: 13px;
  color: #6b7280;
}
.sc-actions {
  display: flex;
  gap: 4px;
  flex-shrink: 0;
}
.sc-act {
  background: none;
  border: none;
  width: 32px;
  height: 32px;
  border-radius: 6px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: #6b7280;
  cursor: pointer;
}
.sc-act:hover { background: #f3f4f6; color: #1f2937; }
.sc-act--danger:hover { background: #fee2e2; color: #dc2626; }

.sc-links {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.sc-link {
  display: flex;
  align-items: stretch;
  min-height: 44px;
  border-radius: 10px;
  border: 1px solid #e5e7eb;
  background: #f9fafb;
  overflow: hidden;
}
.sc-link-main {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  color: inherit;
  text-decoration: none;
  font-size: 14px;
  min-width: 0;
}
.sc-link-main:hover { background: rgba(0,0,0,0.04); }
.sc-link-ico { width: 20px; text-align: center; flex-shrink: 0; }
.sc-link-text {
  font-weight: 500;
  word-break: break-all;
  user-select: text;
}
.sc-link-label {
  font-weight: 400;
  opacity: 0.75;
  font-size: 13px;
}
.sc-copy {
  width: 40px;
  flex-shrink: 0;
  background: transparent;
  border: none;
  border-left: 1px solid rgba(0,0,0,0.06);
  color: inherit;
  opacity: 0.65;
  cursor: pointer;
  font-size: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.sc-copy:hover { opacity: 1; background: rgba(0,0,0,0.05); }
.sc-copy--inline {
  width: auto;
  min-width: 24px;
  height: 24px;
  padding: 0 6px;
  font-size: 13px;
  border-left: none;
  border-radius: 4px;
  opacity: 0.4;
  color: #6b7280;
}
.sc-copy--inline:hover { opacity: 1; background: #f3f4f6; }

.sc-link--phone { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
.sc-link--tg    { background: #eff6ff; border-color: #bfdbfe; color: #1e40af; }
.sc-link--wa    { background: #f0fdf4; border-color: #86efac; color: #166534; }
.sc-link--vb    { background: #f5f3ff; border-color: #c4b5fd; color: #5b21b6; }
.sc-link--mail  { background: #fef3c7; border-color: #fde68a; color: #92400e; }

.sc-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}
.sc-tag {
  font-size: 12px;
  padding: 2px 8px;
  border-radius: 999px;
  background: #f3f4f6;
  color: #4b5563;
}

.sc-notes {
  font-size: 13px;
  color: #6b7280;
  background: #f9fafb;
  padding: 8px 10px;
  border-radius: 8px;
  line-height: 1.4;
  white-space: pre-wrap;
}
</style>
