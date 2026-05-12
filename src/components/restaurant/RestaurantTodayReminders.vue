<template>
  <div v-if="allDone" class="rtr-allgood" :class="{ 'rtr-compact': compact }">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
    <span>Все заявки на сегодня поданы ({{ items.length }})</span>
  </div>
  <div v-else-if="items.length" class="rtr-wrap" :class="{ 'rtr-compact': compact }">
    <div v-if="!compact" class="rtr-title">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
      <span>Ближайшие заявки</span>
    </div>
    <div class="rtr-list">
      <div v-for="it in items" :key="itemKey(it)" class="rtr-item" :class="itemClass(it)">
        <div class="rtr-info">
          <div class="rtr-supplier">
            {{ it.supplier_name }}
            <span v-if="it.is_main_delivery" class="rtr-tag-main">склад</span>
            <span v-if="it.is_advance" class="rtr-tag-advance">{{ advanceLabel(it) }}</span>
          </div>
          <div class="rtr-deadline">
            <template v-if="it.is_acknowledged">
              <span class="rtr-status-done">✓ Заявка подана</span>
              <span v-if="it.acknowledged_at" class="rtr-meta">{{ formatAckTime(it.acknowledged_at) }}{{ it.acknowledged_by ? ' · ' + cleanActor(it.acknowledged_by) : '' }}</span>
            </template>
            <template v-else-if="it.is_expired">
              <span class="rtr-status-expired">🚨 Дедлайн истёк — заявка не подана</span>
              <span v-if="it.deadline_time" class="rtr-meta">было до {{ fmtTime(it.deadline_time) }}</span>
            </template>
            <template v-else>
              <span class="rtr-deadline-label">{{ it.is_advance ? whenLabel(it) + ' до' : 'До' }}</span>
              <span class="rtr-deadline-time">{{ fmtTime(it.deadline_time) || '—' }}</span>
              <span v-if="!it.is_advance && timeLeft(it)" class="rtr-meta">{{ timeLeft(it) }}</span>
            </template>
          </div>
        </div>
        <div class="rtr-actions">
          <button v-if="!it.is_acknowledged" class="rtr-btn-done" :class="{ 'is-late': it.is_expired, 'is-advance': it.is_advance }" :disabled="busy[itemKey(it)]" @click="onAck(it)">
            ✓ {{ it.is_expired ? 'Подал постфактум' : (it.is_advance ? 'Уже подал' : 'Сделал') }}
          </button>
          <button v-else class="rtr-btn-undo" :disabled="busy[itemKey(it)]" @click="onUnack(it)" title="Отменить отметку">
            Откатить
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue';
import { useToastStore } from '@/stores/toastStore.js';

const props = defineProps({
  compact: { type: Boolean, default: false }, // компактный режим для дашборда
});

const emit = defineEmits(['updated']);

const TOKEN_KEY = 'ro_token';
const toast = useToastStore();
const items = ref([]);
const busy = reactive({});
let pollTimer = null;
const allDone = computed(() => items.value.length > 0 && items.value.every(it => it.is_acknowledged));

function buildHeaders(json = false) {
  const h = {};
  const t = localStorage.getItem(TOKEN_KEY);
  if (t) h['X-RO-Token'] = t;
  if (json) h['Content-Type'] = 'application/json';
  return h;
}

function itemKey(it) { return it.supplier_id + '-' + it.order_day + '-' + (it.order_date || ''); }
function fmtTime(t) { return t ? String(t).slice(0, 5) : ''; }

const DAY_NAMES_RU = ['', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота', 'воскресенье'];
function whenLabel(it) {
  const db = Number(it.days_before) || 0;
  if (db === 1) return 'Завтра';
  if (db === 2) return 'Послезавтра';
  return 'В ' + (DAY_NAMES_RU[it.order_day] || '').toLowerCase();
}
function advanceLabel(it) {
  const db = Number(it.days_before) || 0;
  if (db === 1) return 'на завтра';
  if (db === 2) return 'на послезавтра';
  return 'на ' + (DAY_NAMES_RU[it.order_day] || '').toLowerCase();
}

function itemClass(it) {
  if (it.is_acknowledged) return 'is-done';
  if (it.is_expired) return 'is-expired';
  if (it.is_advance) return 'is-advance';
  // менее 30 минут до дедлайна — тревога (только для сегодняшних)
  if (it.deadline_time) {
    const dl = new Date();
    const [h, m] = String(it.deadline_time).split(':').map(Number);
    dl.setHours(h, m, 0, 0);
    const left = (dl - new Date()) / 60000;
    if (left < 30) return 'is-urgent';
    if (left < 120) return 'is-soon';
  }
  return 'is-active';
}

function timeLeft(it) {
  if (!it.deadline_time) return '';
  const dl = new Date();
  const [h, m] = String(it.deadline_time).split(':').map(Number);
  dl.setHours(h, m, 0, 0);
  const mins = Math.round((dl - new Date()) / 60000);
  if (mins <= 0) return '';
  if (mins < 60) return `осталось ${mins} мин`;
  const hrs = Math.floor(mins / 60);
  const rem = mins % 60;
  return rem ? `осталось ${hrs} ч ${rem} мин` : `осталось ${hrs} ч`;
}

function cleanActor(s) {
  if (!s) return '';
  if (s.startsWith('ro:')) return 'ресторан №' + s.slice(3);
  return s;
}
function formatAckTime(iso) {
  try {
    const dt = new Date(iso.replace(' ', 'T'));
    return dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  } catch { return ''; }
}

async function load() {
  try {
    const res = await fetch('/api/restaurant-reminders/today', { headers: buildHeaders() });
    const data = await res.json();
    if (!res.ok) {
      items.value = [];
      return;
    }
    // Показываем только подписанные позиции (включён мастер-тумблер)
    items.value = (data.items || []).filter(it => it.is_subscribed);
    emit('updated', items.value);
  } catch (e) {
    items.value = [];
  }
}

async function onAck(it) {
  const key = itemKey(it);
  busy[key] = true;
  try {
    const res = await fetch('/api/restaurant-reminders/acknowledge', {
      method: 'POST', headers: buildHeaders(true),
      body: JSON.stringify({ supplier_id: it.supplier_id, order_day: it.order_day, order_date: it.order_date }),
    });
    const data = await res.json();
    if (!res.ok) { toast.error(data.error || 'Ошибка'); return; }
    toast.success('Отмечено');
    // Перегружаем — чтобы время отображалось из БД (корректный TZ)
    await load();
  } catch (e) {
    toast.error('Ошибка сети');
  } finally {
    busy[key] = false;
  }
}

async function onUnack(it) {
  const key = itemKey(it);
  busy[key] = true;
  try {
    const res = await fetch('/api/restaurant-reminders/unacknowledge', {
      method: 'POST', headers: buildHeaders(true),
      body: JSON.stringify({ supplier_id: it.supplier_id, order_day: it.order_day, order_date: it.order_date }),
    });
    const data = await res.json();
    if (!res.ok) { toast.error(data.error || 'Ошибка'); return; }
    toast.success('Отметка снята');
    await load();
  } catch (e) {
    toast.error('Ошибка сети');
  } finally {
    busy[key] = false;
  }
}

onMounted(() => {
  load();
  // Опрос раз в 5 минут — на случай если cron отправит, а пользователь не перезагружал
  pollTimer = setInterval(load, 5 * 60 * 1000);
});
onUnmounted(() => {
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
});

defineExpose({ load });
</script>

<style scoped>
.rtr-wrap { display: flex; flex-direction: column; gap: 8px; padding: 12px 14px; background: #fff; border: 1px solid #ffe0b2; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); margin-bottom: 14px; }
.rtr-wrap.rtr-compact { padding: 10px 12px; }
.rtr-title { display: flex; align-items: center; gap: 6px; color: #b35900; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px; }
.rtr-list { display: flex; flex-direction: column; gap: 6px; }
.rtr-item { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 12px; border-radius: 8px; background: #fff8f0; border: 1px solid #ffe0b2; }
.rtr-item.is-urgent { background: #fde2e2; border-color: #f6a8a8; }
.rtr-item.is-soon { background: #fff4e0; border-color: #ffce80; }
.rtr-item.is-done { background: #f3faf4; border-color: #c4e6c8; }
.rtr-item.is-expired { background: #fde2e2; border: 2px solid #c62828; }
.rtr-item.is-expired .rtr-status-expired { color: #b71c1c; font-weight: 700; }
.rtr-item.is-expired .rtr-supplier { color: #b71c1c; }
.rtr-item.is-advance { background: #f4f8fc; border-color: #cdd9e8; }
.rtr-tag-main { font-size: 10px; padding: 1px 7px; border-radius: 10px; background: #e8f5e9; color: #2e7d32; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
.rtr-tag-advance { font-size: 10px; padding: 1px 7px; border-radius: 10px; background: #e3f2fd; color: #1565c0; font-weight: 600; }
.rtr-btn-done.is-advance { background: #1976d2; }
.rtr-btn-done.is-advance:hover { background: #0d47a1; }
.rtr-info { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
.rtr-supplier { font-size: 14px; font-weight: 700; color: #2b2b2b; }
.rtr-deadline { font-size: 12px; color: #555; display: flex; align-items: baseline; gap: 6px; flex-wrap: wrap; }
.rtr-deadline-label { color: #777; }
.rtr-deadline-time { font-size: 16px; font-weight: 700; color: #b35900; line-height: 1; }
.rtr-meta { font-size: 11px; color: #888; }
.rtr-status-done { font-size: 13px; font-weight: 600; color: #1b5e20; }
.rtr-status-expired { font-size: 13px; font-weight: 600; color: #888; }
.rtr-actions { display: flex; gap: 6px; }
.rtr-btn-done { padding: 8px 16px; border: none; background: #2e7d32; color: #fff; font-size: 13px; font-weight: 600; border-radius: 6px; cursor: pointer; white-space: nowrap; }
.rtr-btn-done:hover { background: #1b5e20; }
.rtr-btn-done:disabled { opacity: 0.6; cursor: default; }
.rtr-btn-done.is-late { background: #b71c1c; }
.rtr-btn-done.is-late:hover { background: #7f0000; }
.rtr-btn-undo { padding: 5px 10px; border: 1px solid #ddd; background: #fff; color: #666; font-size: 11px; border-radius: 5px; cursor: pointer; }
.rtr-btn-undo:hover { background: #f5f5f5; color: #c62828; border-color: #f6a8a8; }
.rtr-allgood {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 14px; margin-bottom: 14px;
  background: #ebf6ec; border: 1px solid #c4e6c8; border-radius: 10px;
  color: #1b5e20; font-size: 13px; font-weight: 600;
}
.rtr-allgood.rtr-compact { padding: 8px 12px; font-size: 12px; }
.rtr-allgood svg { color: #2e7d32; flex-shrink: 0; }
</style>
