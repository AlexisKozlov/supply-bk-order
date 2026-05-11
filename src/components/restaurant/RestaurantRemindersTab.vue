<template>
  <div class="rrt">
    <RestaurantTodayReminders />
    <div v-if="loading" class="rrt-loading">Загрузка…</div>

    <div v-else-if="!groups.length" class="rrt-empty">
      <p>Для вашего ресторана пока не настроен ни один график поставок.</p>
      <p>Если у вас есть локальный поставщик и вы хотите получать напоминания о подаче заявок — обратитесь в отдел закупок: они добавят расписание, и здесь появится возможность подписаться.</p>
    </div>

    <div v-else>
      <p class="rrt-intro">
        Включи получение напоминаний для нужных поставщиков. В назначенное расписанием время (за день/часы до дедлайна подачи заявки) ты увидишь баннер в кабинете и/или получишь сообщение в Telegram.
      </p>

      <div v-if="!availableTg.length" class="rrt-tg-warning">
        Никто из сотрудников ресторана ещё не привязал Telegram-бота. Сотрудники могут привязать чат через настройки уведомлений в боте — тогда они появятся в списке для выбора.
      </div>

      <div class="rrt-list">
        <div v-for="g in groups" :key="g.supplier_id" class="rrt-card">
          <div class="rrt-card-head">
            <div class="rrt-card-title">
              <span class="rrt-supplier-name">{{ g.supplier_name }}</span>
              <span class="rrt-tag" :class="g.so_enabled ? 'rrt-tag-so' : 'rrt-tag-local'">
                {{ g.so_enabled ? 'через портал' : 'локальный' }}
              </span>
            </div>
          </div>

          <div class="rrt-schedule">
            <div v-for="d in g.days" :key="d.order_day + '-' + d.delivery_day" class="rrt-day">
              <span class="rrt-day-name">{{ weekdayShort(d.delivery_day) }}</span>
              <span class="rrt-day-meta">поставка · заявка {{ weekdayShort(d.order_day) }} {{ effectiveDeadline(g, d) }}</span>
            </div>
          </div>

          <div class="rrt-controls">
            <label class="rrt-toggle">
              <input type="checkbox" :checked="isEnabled(g)" :disabled="saving[g.supplier_id]" @change="onToggleEnabled(g, $event.target.checked)" />
              <span class="rrt-toggle-slider"></span>
              <span class="rrt-toggle-label">Получать напоминания</span>
            </label>

            <div v-if="isEnabled(g)" class="rrt-channels">
              <label class="rrt-checkbox" :class="{ 'is-disabled': !availableTg.length }">
                <input type="checkbox" :checked="g.subscription?.telegram_enabled" :disabled="!availableTg.length" @change="onChannelChange(g, 'telegram', $event.target.checked)" />
                <span>Дублировать в Telegram</span>
              </label>
            </div>
          </div>

          <div v-if="isEnabled(g) && g.subscription?.telegram_enabled && availableTg.length" class="rrt-tg">
            <div class="rrt-tg-title">Кто получит сообщение в Telegram</div>
            <div class="rrt-tg-list">
              <label v-for="u in availableTg" :key="u.id" class="rrt-tg-item" :class="{ 'is-selected': isSelected(g, u.id) }">
                <input type="checkbox"
                       :checked="isSelected(g, u.id)"
                       :disabled="savingTg[g.supplier_id]"
                       @change="toggleTg(g, u.id, $event.target.checked)" />
                <div class="rrt-tg-info">
                  <span class="rrt-tg-name">{{ u.name }}</span>
                  <span v-if="u.username" class="rrt-tg-username">{{ u.username }}</span>
                </div>
              </label>
            </div>
            <p class="rrt-tg-hint">Если никого не отметить — сообщения в Telegram не уйдут, но баннер в кабинете останется (если включён).</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, defineAsyncComponent } from 'vue';
import { useToastStore } from '@/stores/toastStore.js';

const RestaurantTodayReminders = defineAsyncComponent(() => import('@/components/restaurant/RestaurantTodayReminders.vue'));

const TOKEN_KEY = 'ro_token';
const toast = useToastStore();

const loading = ref(true);
const groups = ref([]);
const availableTg = ref([]);
const saving = reactive({});
const savingTg = reactive({});

function buildHeaders(json = false) {
  const h = {};
  const t = localStorage.getItem(TOKEN_KEY);
  if (t) h['X-RO-Token'] = t;
  if (json) h['Content-Type'] = 'application/json';
  return h;
}

const WEEKDAYS_SHORT = ['', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
function weekdayShort(d) { return WEEKDAYS_SHORT[d] || ''; }
function fmtTime(t) { return t ? String(t).slice(0, 5) : ''; }

function effectiveDeadline(group, day) {
  if (day.deadline_override) return 'до ' + fmtTime(day.deadline_override);
  const dflt = (group.default_deadlines || []).find(d => Number(d.delivery_dow) === Number(day.delivery_day));
  return dflt ? 'до ' + fmtTime(dflt.deadline_time) : '';
}

function isEnabled(group) {
  return !!group.subscription?.is_enabled;
}

function isSelected(group, tgId) {
  return (group.selected_tg_ids || []).includes(tgId);
}

async function loadGroups() {
  loading.value = true;
  try {
    const res = await fetch('/api/restaurant-reminders/list', { headers: buildHeaders() });
    const data = await res.json();
    if (!res.ok) {
      toast.error(data.error || 'Не удалось загрузить напоминания');
      groups.value = [];
      availableTg.value = [];
      return;
    }
    groups.value = data.groups || [];
    availableTg.value = data.available_tg || [];
  } catch (e) {
    toast.error('Ошибка сети');
    groups.value = [];
    availableTg.value = [];
  } finally {
    loading.value = false;
  }
}

async function saveSubscription(group, patch) {
  saving[group.supplier_id] = true;
  try {
    const payload = {
      supplier_id: group.supplier_id,
      is_enabled: group.subscription?.is_enabled ? 1 : 0,
      portal_enabled: group.subscription?.portal_enabled ? 1 : 0,
      telegram_enabled: group.subscription?.telegram_enabled ? 1 : 0,
      ...patch,
    };
    const res = await fetch('/api/restaurant-reminders/set', {
      method: 'POST', headers: buildHeaders(true),
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!res.ok) { toast.error(data.error || 'Ошибка'); return false; }
    if (!group.subscription) group.subscription = { is_enabled: 0, portal_enabled: 1, telegram_enabled: 0 };
    group.subscription = {
      ...group.subscription,
      is_enabled: !!payload.is_enabled,
      portal_enabled: !!payload.portal_enabled,
      telegram_enabled: !!payload.telegram_enabled,
    };
    return true;
  } catch (e) {
    toast.error('Ошибка сети');
    return false;
  } finally {
    saving[group.supplier_id] = false;
  }
}

function onToggleEnabled(group, checked) {
  saveSubscription(group, { is_enabled: checked ? 1 : 0 });
}

function onChannelChange(group, channel, checked) {
  const patch = {};
  if (channel === 'portal') patch.portal_enabled = checked ? 1 : 0;
  if (channel === 'telegram') patch.telegram_enabled = checked ? 1 : 0;
  saveSubscription(group, patch);
}

async function toggleTg(group, tgId, checked) {
  const current = new Set(group.selected_tg_ids || []);
  if (checked) current.add(tgId); else current.delete(tgId);
  const newIds = Array.from(current);
  savingTg[group.supplier_id] = true;
  try {
    const res = await fetch('/api/restaurant-reminders/tg-set', {
      method: 'POST', headers: buildHeaders(true),
      body: JSON.stringify({ supplier_id: group.supplier_id, ro_tg_sub_ids: newIds }),
    });
    const data = await res.json();
    if (!res.ok) {
      toast.error(data.error || 'Ошибка');
      return;
    }
    group.selected_tg_ids = newIds;
    // Если выбрали хотя бы одного — телеграм-канал включается на сервере
    if (newIds.length && !group.subscription?.telegram_enabled) {
      if (!group.subscription) group.subscription = { is_enabled: 1, portal_enabled: 1, telegram_enabled: 1 };
      group.subscription.telegram_enabled = true;
    }
  } catch (e) {
    toast.error('Ошибка сети');
  } finally {
    savingTg[group.supplier_id] = false;
  }
}

onMounted(loadGroups);
</script>

<style scoped>
.rrt { padding: 12px 4px 24px; }
.rrt-loading, .rrt-empty { padding: 28px 16px; color: #777; text-align: center; }
.rrt-empty p { margin: 6px 0; line-height: 1.5; }
.rrt-intro { font-size: 13px; color: #555; line-height: 1.5; margin: 0 0 14px; padding: 10px 14px; background: #f7f9fb; border-radius: 8px; }
.rrt-tg-warning { font-size: 13px; color: #b35900; background: #fff4e0; border: 1px solid #ffe0b2; padding: 10px 14px; border-radius: 8px; margin-bottom: 12px; line-height: 1.5; }

.rrt-list { display: flex; flex-direction: column; gap: 14px; }

.rrt-card { background: #fff; border: 1px solid #e8e8e8; border-radius: 10px; padding: 14px 16px; }
.rrt-card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.rrt-card-title { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.rrt-supplier-name { font-size: 15px; font-weight: 700; color: #2b2b2b; }
.rrt-tag { font-size: 10px; padding: 2px 8px; border-radius: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
.rrt-tag-so { background: #e3f2fd; color: #1565c0; }
.rrt-tag-local { background: #fff4e0; color: #b35900; }

.rrt-schedule { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
.rrt-day { display: inline-flex; align-items: baseline; gap: 6px; padding: 5px 10px; background: #f7f8fa; border-radius: 14px; font-size: 12px; color: #444; }
.rrt-day-name { font-weight: 700; color: #2b2b2b; font-size: 13px; }
.rrt-day-meta { color: #777; }

.rrt-controls { display: flex; flex-wrap: wrap; gap: 12px 18px; align-items: center; padding-top: 10px; border-top: 1px solid #f2f2f2; }

.rrt-toggle { display: inline-flex; align-items: center; gap: 10px; cursor: pointer; user-select: none; }
.rrt-toggle input { display: none; }
.rrt-toggle-slider { width: 36px; height: 20px; background: #d8d8d8; border-radius: 12px; position: relative; transition: background 0.15s; }
.rrt-toggle-slider::after { content: ''; position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; background: #fff; border-radius: 50%; transition: left 0.15s; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
.rrt-toggle input:checked + .rrt-toggle-slider { background: #4caf50; }
.rrt-toggle input:checked + .rrt-toggle-slider::after { left: 18px; }
.rrt-toggle-label { font-size: 13px; color: #2b2b2b; font-weight: 500; }

.rrt-channels { display: inline-flex; gap: 14px; flex-wrap: wrap; }
.rrt-checkbox { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #444; cursor: pointer; }
.rrt-checkbox input { margin: 0; }
.rrt-checkbox.is-disabled { color: #aaa; cursor: not-allowed; }

.rrt-tg { margin-top: 12px; padding-top: 12px; border-top: 1px dashed #e8e8e8; }
.rrt-tg-title { font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #888; font-weight: 600; margin-bottom: 10px; }
.rrt-tg-list { display: flex; flex-direction: column; gap: 4px; }
.rrt-tg-item { display: flex; align-items: center; gap: 10px; padding: 7px 10px; background: #fafafa; border: 1px solid transparent; border-radius: 6px; cursor: pointer; transition: background 0.1s; }
.rrt-tg-item:hover { background: #f3f3f3; }
.rrt-tg-item.is-selected { background: #e7f5e8; border-color: #c4e6c8; }
.rrt-tg-item input { margin: 0; }
.rrt-tg-info { display: flex; align-items: baseline; gap: 8px; }
.rrt-tg-name { font-size: 13px; color: #2b2b2b; font-weight: 500; }
.rrt-tg-username { font-size: 11px; color: #888; }
.rrt-tg-hint { margin: 10px 0 0; font-size: 11px; color: #888; line-height: 1.5; }
</style>
