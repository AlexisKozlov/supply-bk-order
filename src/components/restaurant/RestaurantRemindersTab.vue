<template>
  <div class="rrt">
    <div v-if="showTutorial" class="rrt-tut-overlay" @click.self="dismissTutorial">
      <div class="rrt-tut-box" role="dialog" aria-modal="true" aria-label="Инструкция по разделу напоминаний">
        <button type="button" class="rrt-tut-close" aria-label="Закрыть" @click="dismissTutorial">×</button>
        <div class="rrt-tut-title">Как пользоваться напоминаниями</div>
        <div class="rrt-tut-video-wrap">
          <video ref="tutorialVideo" class="rrt-tut-video" src="/reminders-tutorial.mp4" autoplay muted loop playsinline></video>
          <button type="button" class="rrt-tut-fs" aria-label="На весь экран" title="На весь экран" @click="openFullscreen">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M4 9V4h5"/><path d="M20 9V4h-5"/><path d="M4 15v5h5"/><path d="M20 15v5h-5"/>
            </svg>
          </button>
        </div>
        <button type="button" class="rrt-tut-ok" @click="dismissTutorial">Понятно</button>
      </div>
    </div>

    <RestaurantTodayReminders />
    <div v-if="loading" class="rrt-loading">Загрузка…</div>

    <div v-else-if="!groups.length && !hasMainDeliveryDays" class="rrt-empty">
      <p>Для вашего ресторана пока не настроен ни один график поставок.</p>
      <p>Если у вас есть локальный поставщик и вы хотите получать напоминания о подаче заявок — обратитесь в отдел закупок: они добавят расписание, и здесь появится возможность подписаться.</p>
    </div>

    <div v-else>
      <div class="rrt-intro-row">
        <p class="rrt-intro">
          Включи получение напоминаний для нужных поставщиков. В назначенное расписанием время (за день/часы до дедлайна подачи заявки) ты увидишь баннер в кабинете и/или получишь сообщение в Telegram.
        </p>
        <button type="button" class="rrt-tut-btn" @click="openTutorial" title="Открыть видеоинструкцию">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
          Видеоинструкция
        </button>
      </div>

      <div v-if="!availableTg.length" class="rrt-tg-warning">
        Никто из сотрудников ресторана ещё не привязал Telegram-бота. Сотрудники могут привязать чат через настройки уведомлений в боте — тогда они появятся в списке для выбора.
      </div>

      <div v-if="push.isSupported.value" class="rrt-push" :class="{ 'is-on': push.isSubscribed.value }">
        <div class="rrt-push-info">
          <div class="rrt-push-title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0 1 12 0v5l1.5 2.5h-15L6 14V9Z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>
            Push-уведомления в этом браузере
          </div>
          <div class="rrt-push-hint">
            <template v-if="push.isSubscribed.value">Уведомления приходят на этот браузер, даже когда сайт закрыт.</template>
            <template v-else-if="push.permission.value === 'denied'">Разрешение заблокировано. Откройте настройки браузера и разрешите уведомления для сайта.</template>
            <template v-else>Включите, чтобы получать напоминания, даже когда вкладка закрыта.</template>
          </div>
        </div>
        <button v-if="push.isSubscribed.value" class="rrt-push-btn rrt-push-btn-off" :disabled="push.busy.value" @click="push.unsubscribe">Отключить</button>
        <button v-else class="rrt-push-btn rrt-push-btn-on" :disabled="push.busy.value || push.permission.value === 'denied'" @click="push.subscribe">Включить</button>
      </div>

      <div class="rrt-list">
        <div v-if="hasMainDeliveryDays" class="rrt-card rrt-card-main">
          <div class="rrt-card-head">
            <div class="rrt-card-title">
              <span class="rrt-supplier-name">Основная поставка</span>
              <span class="rrt-tag rrt-tag-main">склад</span>
            </div>
          </div>

          <div class="rrt-schedule">
            <div v-for="d in mainDelivery.days" :key="'main-' + d.order_day + '-' + d.delivery_day" class="rrt-day">
              <span class="rrt-day-name">{{ weekdayShort(d.delivery_day) }}</span>
              <span class="rrt-day-meta">поставка · заявка {{ weekdayShort(d.order_day) }} до {{ fmtTime(d.deadline_time) }}</span>
            </div>
          </div>

          <div class="rrt-controls">
            <label class="rrt-toggle">
              <input type="checkbox" :checked="isMainEnabled" :disabled="savingMain" @change="onToggleMainEnabled($event.target.checked)" />
              <span class="rrt-toggle-slider"></span>
              <span class="rrt-toggle-label">Получать напоминания</span>
            </label>

            <div v-if="isMainEnabled" class="rrt-channels">
              <label class="rrt-checkbox" :class="{ 'is-disabled': !availableTg.length }">
                <input type="checkbox" :checked="mainDelivery.subscription?.telegram_enabled" :disabled="!availableTg.length" @change="onMainChannelChange('telegram', $event.target.checked)" />
                <span>Дублировать в Telegram</span>
              </label>
            </div>
          </div>

          <div v-if="isMainEnabled && mainDelivery.subscription?.telegram_enabled && availableTg.length" class="rrt-tg">
            <div class="rrt-tg-title">Кто получит сообщение в Telegram</div>
            <div class="rrt-tg-list">
              <label v-for="u in availableTg" :key="'main-tg-' + u.id" class="rrt-tg-item" :class="{ 'is-selected': isMainTgSelected(u.id) }">
                <input type="checkbox"
                       :checked="isMainTgSelected(u.id)"
                       :disabled="savingMainTg"
                       @change="toggleMainTg(u.id, $event.target.checked)" />
                <div class="rrt-tg-info">
                  <span class="rrt-tg-name">{{ u.name }}</span>
                  <span v-if="u.username" class="rrt-tg-username">{{ u.username }}</span>
                </div>
              </label>
            </div>
            <p class="rrt-tg-hint">Если никого не отметить — сообщения в Telegram не уйдут, но баннер в кабинете останется (если включён).</p>
          </div>
        </div>
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
import { ref, reactive, computed, onMounted, onBeforeUnmount, defineAsyncComponent } from 'vue';
import { useToastStore } from '@/stores/toastStore.js';
import { usePushNotifications } from '@/composables/usePushNotifications.js';

const push = usePushNotifications();

const RestaurantTodayReminders = defineAsyncComponent(() => import('@/components/restaurant/RestaurantTodayReminders.vue'));

const TOKEN_KEY = 'ro_token';
const TUTORIAL_KEY = 'reminders_tutorial_seen_v1';
const toast = useToastStore();

const loading = ref(true);
const groups = ref([]);
const availableTg = ref([]);
const saving = reactive({});
const savingTg = reactive({});

const mainDelivery = ref({ days: [], subscription: null, selected_tg_ids: [] });
const savingMain = ref(false);
const savingMainTg = ref(false);
const hasMainDeliveryDays = computed(() => mainDelivery.value?.days?.length > 0);
const isMainEnabled = computed(() => !!mainDelivery.value?.subscription?.is_enabled);
function isMainTgSelected(tgId) { return (mainDelivery.value?.selected_tg_ids || []).includes(tgId); }

const showTutorial = ref(false);
const tutorialVideo = ref(null);

async function openFullscreen() {
  const v = tutorialVideo.value;
  if (!v) return;
  try {
    if (v.requestFullscreen) await v.requestFullscreen();
    else if (v.webkitEnterFullscreen) v.webkitEnterFullscreen(); // iOS Safari
    else if (v.webkitRequestFullscreen) await v.webkitRequestFullscreen();
    try {
      if (screen.orientation && screen.orientation.lock) {
        await screen.orientation.lock('landscape');
      }
    } catch (e) { /* desktop / iOS — orientation lock не поддержан */ }
  } catch (e) { /* ignore */ }
}

function dismissTutorial() {
  showTutorial.value = false;
  try { localStorage.setItem(TUTORIAL_KEY, '1'); } catch (e) { /* ignore */ }
}

function openTutorial() {
  showTutorial.value = true;
}

function onTutorialEsc(e) {
  if (e.key === 'Escape' && showTutorial.value) dismissTutorial();
}

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
    mainDelivery.value = data.main_delivery || { days: [], subscription: null, selected_tg_ids: [] };
    if (!mainDelivery.value.selected_tg_ids) mainDelivery.value.selected_tg_ids = [];
  } catch (e) {
    toast.error('Ошибка сети');
    groups.value = [];
    availableTg.value = [];
    mainDelivery.value = { days: [], subscription: null, selected_tg_ids: [] };
  } finally {
    loading.value = false;
  }
}

async function saveMainSubscription(patch) {
  savingMain.value = true;
  try {
    const sub = mainDelivery.value.subscription || { is_enabled: false, portal_enabled: true, telegram_enabled: false };
    const payload = {
      is_enabled: sub.is_enabled ? 1 : 0,
      telegram_enabled: sub.telegram_enabled ? 1 : 0,
      ...patch,
    };
    const res = await fetch('/api/restaurant-reminders/main-set', {
      method: 'POST', headers: buildHeaders(true),
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!res.ok) { toast.error(data.error || 'Ошибка'); return false; }
    mainDelivery.value.subscription = {
      ...(mainDelivery.value.subscription || {}),
      is_enabled: !!payload.is_enabled,
      portal_enabled: !!payload.is_enabled,
      telegram_enabled: !!payload.telegram_enabled,
    };
    return true;
  } catch (e) {
    toast.error('Ошибка сети');
    return false;
  } finally {
    savingMain.value = false;
  }
}

function onToggleMainEnabled(checked) {
  saveMainSubscription({ is_enabled: checked ? 1 : 0 });
}

function onMainChannelChange(channel, checked) {
  const patch = {};
  if (channel === 'telegram') patch.telegram_enabled = checked ? 1 : 0;
  saveMainSubscription(patch);
}

async function toggleMainTg(tgId, checked) {
  const current = new Set(mainDelivery.value.selected_tg_ids || []);
  if (checked) current.add(tgId); else current.delete(tgId);
  const newIds = Array.from(current);
  savingMainTg.value = true;
  try {
    const res = await fetch('/api/restaurant-reminders/main-tg-set', {
      method: 'POST', headers: buildHeaders(true),
      body: JSON.stringify({ ro_tg_sub_ids: newIds }),
    });
    const data = await res.json();
    if (!res.ok) { toast.error(data.error || 'Ошибка'); return; }
    mainDelivery.value.selected_tg_ids = newIds;
    if (newIds.length && !mainDelivery.value.subscription?.telegram_enabled) {
      if (!mainDelivery.value.subscription) mainDelivery.value.subscription = { is_enabled: true, portal_enabled: true, telegram_enabled: true };
      mainDelivery.value.subscription.telegram_enabled = true;
    }
  } catch (e) {
    toast.error('Ошибка сети');
  } finally {
    savingMainTg.value = false;
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

onMounted(() => {
  loadGroups();
  try {
    if (!localStorage.getItem(TUTORIAL_KEY)) showTutorial.value = true;
  } catch (e) { /* ignore */ }
  window.addEventListener('keydown', onTutorialEsc);
});

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onTutorialEsc);
});
</script>

<style scoped>
.rrt { padding: 12px 4px 24px; }
.rrt-loading, .rrt-empty { padding: 28px 16px; color: #777; text-align: center; }
.rrt-empty p { margin: 6px 0; line-height: 1.5; }
.rrt-intro { font-size: 13px; color: #555; line-height: 1.5; margin: 0; padding: 10px 14px; background: #f7f9fb; border-radius: 8px; flex: 1; }
.rrt-intro-row { display: flex; gap: 12px; align-items: stretch; margin: 0 0 14px; }
.rrt-tut-btn {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 0 14px; border-radius: 8px;
  background: #fff; border: 1px solid #d1d8df; color: #2d3a48;
  font-size: 13px; font-weight: 600; cursor: pointer;
  white-space: nowrap; transition: background 0.15s, border-color 0.15s, color 0.15s;
}
.rrt-tut-btn:hover { background: #f0f4f8; border-color: #b3c0cf; color: #1a73e8; }
@media (max-width: 640px) {
  .rrt-intro-row { flex-direction: column; }
  .rrt-tut-btn { justify-content: center; padding: 10px 14px; }
}
.rrt-tg-warning { font-size: 13px; color: #b35900; background: #fff4e0; border: 1px solid #ffe0b2; padding: 10px 14px; border-radius: 8px; margin-bottom: 12px; line-height: 1.5; }

.rrt-push { display: flex; align-items: center; gap: 12px; padding: 10px 14px; margin-bottom: 12px; background: #f4f7fb; border: 1px solid #cdd9e8; border-radius: 8px; }
.rrt-push.is-on { background: #f0f7ed; border-color: #c4e6c8; }
.rrt-push-info { flex: 1; min-width: 0; }
.rrt-push-title { font-size: 13px; font-weight: 600; color: #2b2b2b; display: flex; align-items: center; gap: 6px; }
.rrt-push.is-on .rrt-push-title { color: #2e7d32; }
.rrt-push-hint { font-size: 12px; color: #666; line-height: 1.4; margin-top: 3px; }
.rrt-push-btn { padding: 7px 14px; border: none; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap; }
.rrt-push-btn:disabled { opacity: 0.6; cursor: default; }
.rrt-push-btn-on { background: #1976d2; color: #fff; }
.rrt-push-btn-on:hover { background: #0d47a1; }
.rrt-push-btn-off { background: transparent; color: #666; border: 1px solid #ccc; }
.rrt-push-btn-off:hover { background: #f0f0f0; color: #c62828; border-color: #f6a8a8; }

.rrt-list { display: flex; flex-direction: column; gap: 14px; }

.rrt-card { background: #fff; border: 1px solid #e8e8e8; border-radius: 10px; padding: 14px 16px; }
.rrt-card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.rrt-card-title { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.rrt-supplier-name { font-size: 15px; font-weight: 700; color: #2b2b2b; }
.rrt-tag { font-size: 10px; padding: 2px 8px; border-radius: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
.rrt-tag-so { background: #e3f2fd; color: #1565c0; }
.rrt-tag-local { background: #fff4e0; color: #b35900; }
.rrt-tag-main { background: #e8f5e9; color: #2e7d32; }

.rrt-card-main {
  border-color: #c4e6c8;
  background: linear-gradient(180deg, #f4faf5 0%, #ffffff 100%);
}

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

.rrt-tut-overlay {
  position: fixed;
  inset: 0;
  z-index: 9999;
  background: rgba(20, 24, 32, 0.55);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  animation: rrt-tut-fade 0.25s ease-out;
}

.rrt-tut-box {
  position: relative;
  width: 100%;
  max-width: min(1200px, 96vw);
  max-height: 96vh;
  background: #fff;
  border-radius: 18px;
  box-shadow:
    0 30px 80px rgba(0, 0, 0, 0.35),
    0 8px 24px rgba(0, 0, 0, 0.15);
  padding: 22px 22px 20px;
  display: flex;
  flex-direction: column;
  gap: 16px;
  animation: rrt-tut-pop 0.32s cubic-bezier(0.16, 1, 0.3, 1);
  transform-origin: center;
}

.rrt-tut-title {
  font-size: 17px;
  font-weight: 700;
  color: #1c1f24;
  text-align: center;
  letter-spacing: -0.01em;
  padding-right: 24px;
}

.rrt-tut-video-wrap {
  position: relative;
  width: 100%;
}
.rrt-tut-video {
  width: 100%;
  height: auto;
  max-height: 78vh;
  border-radius: 12px;
  background: #000;
  display: block;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
  object-fit: contain;
}

.rrt-tut-fs {
  position: absolute;
  top: 10px;
  right: 10px;
  width: 36px;
  height: 36px;
  border: none;
  background: rgba(0, 0, 0, 0.55);
  color: #fff;
  border-radius: 8px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s, transform 0.12s;
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
}
.rrt-tut-fs:hover { background: rgba(0, 0, 0, 0.75); }
.rrt-tut-fs:active { transform: scale(0.92); }

.rrt-tut-close {
  position: absolute;
  top: 10px;
  right: 12px;
  width: 32px;
  height: 32px;
  border: none;
  background: rgba(0, 0, 0, 0.05);
  color: #555;
  font-size: 22px;
  line-height: 1;
  border-radius: 50%;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s, color 0.15s, transform 0.15s;
}
.rrt-tut-close:hover { background: rgba(0, 0, 0, 0.1); color: #111; transform: rotate(90deg); }
.rrt-tut-close:active { transform: rotate(90deg) scale(0.92); }

.rrt-tut-ok {
  align-self: center;
  min-width: 160px;
  padding: 11px 28px;
  border: none;
  border-radius: 10px;
  background: linear-gradient(180deg, #4caf50, #3d9c41);
  color: #fff;
  font-size: 14px;
  font-weight: 600;
  letter-spacing: 0.01em;
  cursor: pointer;
  box-shadow: 0 4px 12px rgba(76, 175, 80, 0.35);
  transition: transform 0.12s ease, box-shadow 0.15s ease, filter 0.15s ease;
}
.rrt-tut-ok:hover { filter: brightness(1.05); box-shadow: 0 6px 18px rgba(76, 175, 80, 0.45); }
.rrt-tut-ok:active { transform: translateY(1px) scale(0.98); box-shadow: 0 2px 6px rgba(76, 175, 80, 0.4); }

@keyframes rrt-tut-fade {
  from { opacity: 0; }
  to { opacity: 1; }
}

@keyframes rrt-tut-pop {
  0% { opacity: 0; transform: translateY(12px) scale(0.94); }
  100% { opacity: 1; transform: translateY(0) scale(1); }
}

@media (max-width: 520px) {
  .rrt-tut-box { padding: 18px 16px 16px; border-radius: 14px; }
  .rrt-tut-title { font-size: 15px; }
  .rrt-tut-ok { min-width: 140px; padding: 10px 22px; font-size: 13px; }
}
</style>
