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
      <!-- Компактная шапка: одна строка интро + кнопка-«?» + статус-чипы -->
      <div class="rrt-toolbar">
        <div class="rrt-toolbar-intro">
          <span>Включи напоминания у нужных поставщиков — придёт баннер в кабинете и/или сообщение в&nbsp;Telegram.</span>
          <button type="button" class="rrt-help-btn" @click="openTutorial" title="Видеоинструкция" aria-label="Видеоинструкция">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
          </button>
        </div>

        <div class="rrt-toolbar-chips">
          <button v-if="push.isSupported.value"
                  type="button"
                  class="rrt-chip rrt-chip-action"
                  :class="{ 'is-on': push.isSubscribed.value, 'is-blocked': push.permission.value === 'denied' }"
                  :disabled="push.busy.value || push.permission.value === 'denied'"
                  :title="pushChipTitle"
                  @click="onPushChipClick">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0 1 12 0v5l1.5 2.5h-15L6 14V9Z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>
            <span>{{ pushChipLabel }}</span>
          </button>

          <span class="rrt-chip" :class="{ 'is-warn': !availableTg.length, 'is-on': availableTg.length }" :title="tgChipTitle">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-1.99 1.93c-.23.23-.42.42-.83.42z"/></svg>
            <span>{{ tgChipLabel }}</span>
          </span>
        </div>
      </div>

      <!-- Сетка карточек -->
      <div class="rrt-grid">
        <!-- Основная поставка -->
        <article v-if="hasMainDeliveryDays" class="rrt-card rrt-card-main rrt-card-wide">
          <header class="rrt-head">
            <div class="rrt-head-info">
              <h4 class="rrt-name">Основная поставка</h4>
              <span class="rrt-tag rrt-tag-main">склад</span>
            </div>
            <label class="rrt-toggle" :title="isMainEnabled ? 'Выключить напоминания' : 'Включить напоминания'">
              <input type="checkbox" :checked="isMainEnabled" :disabled="savingMain" @change="onToggleMainEnabled($event.target.checked)" />
              <span class="rrt-toggle-slider"></span>
            </label>
          </header>

          <div class="rrt-schedule">
            <span v-for="d in mainDelivery.days" :key="'main-' + d.order_day + '-' + d.delivery_day" class="rrt-day">
              <span class="rrt-day-from">{{ weekdayShort(d.order_day) }} {{ fmtTime(d.deadline_time) }}</span>
              <span class="rrt-day-arrow">→</span>
              <span class="rrt-day-to">{{ weekdayShort(d.delivery_day) }}</span>
            </span>
          </div>

          <div v-if="isMainEnabled" class="rrt-body">
            <label class="rrt-checkbox" :class="{ 'is-disabled': !availableTg.length }">
              <input type="checkbox" :checked="mainDelivery.subscription?.telegram_enabled" :disabled="!availableTg.length" @change="onMainChannelChange('telegram', $event.target.checked)" />
              <span>Дублировать в Telegram</span>
            </label>

            <button v-if="mainDelivery.subscription?.telegram_enabled && availableTg.length"
                    type="button"
                    class="rrt-tg-chip"
                    :class="{ 'is-empty': !(mainDelivery.selected_tg_ids || []).length, 'is-open': mainTgOpen }"
                    @click="mainTgOpen = !mainTgOpen">
              <span>{{ recipientsLabel(mainDelivery.selected_tg_ids) }}</span>
              <span class="rrt-tg-chip-arrow">▾</span>
            </button>
          </div>

          <div v-if="isMainEnabled && mainDelivery.subscription?.telegram_enabled && availableTg.length && mainTgOpen" class="rrt-tg">
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
            <p class="rrt-tg-hint">Если никого не отметить — сообщения в Telegram не уйдут.</p>
          </div>
        </article>

        <!-- Поставщики через портал -->
        <h3 v-if="portalGroups.length" class="rrt-group-title">Через портал</h3>
        <article v-for="g in portalGroups" :key="'p-' + g.supplier_id" class="rrt-card">
          <header class="rrt-head">
            <div class="rrt-head-info">
              <h4 class="rrt-name">{{ g.supplier_name }}</h4>
              <span class="rrt-tag rrt-tag-so">портал</span>
            </div>
            <label class="rrt-toggle" :title="isEnabled(g) ? 'Выключить напоминания' : 'Включить напоминания'">
              <input type="checkbox" :checked="isEnabled(g)" :disabled="saving[g.supplier_id]" @change="onToggleEnabled(g, $event.target.checked)" />
              <span class="rrt-toggle-slider"></span>
            </label>
          </header>

          <div class="rrt-schedule">
            <span v-for="d in g.days" :key="d.order_day + '-' + d.delivery_day" class="rrt-day">
              <span class="rrt-day-from">{{ weekdayShort(d.order_day) }} {{ effectiveDeadlineTime(g, d) }}</span>
              <span class="rrt-day-arrow">→</span>
              <span class="rrt-day-to">{{ weekdayShort(d.delivery_day) }}</span>
            </span>
          </div>

          <div v-if="isEnabled(g)" class="rrt-body">
            <label class="rrt-checkbox" :class="{ 'is-disabled': !availableTg.length }">
              <input type="checkbox" :checked="g.subscription?.telegram_enabled" :disabled="!availableTg.length" @change="onChannelChange(g, 'telegram', $event.target.checked)" />
              <span>Дублировать в Telegram</span>
            </label>

            <button v-if="g.subscription?.telegram_enabled && availableTg.length"
                    type="button"
                    class="rrt-tg-chip"
                    :class="{ 'is-empty': !(g.selected_tg_ids || []).length, 'is-open': expandedTg.has(g.supplier_id) }"
                    @click="toggleTgPanel(g.supplier_id)">
              <span>{{ recipientsLabel(g.selected_tg_ids) }}</span>
              <span class="rrt-tg-chip-arrow">▾</span>
            </button>
          </div>

          <div v-if="isEnabled(g) && g.subscription?.telegram_enabled && availableTg.length && expandedTg.has(g.supplier_id)" class="rrt-tg">
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
            <p class="rrt-tg-hint">Если никого не отметить — сообщения в Telegram не уйдут.</p>
          </div>
        </article>

        <!-- Локальные поставщики -->
        <h3 v-if="localGroups.length" class="rrt-group-title">Локальные</h3>
        <article v-for="g in localGroups" :key="'l-' + g.supplier_id" class="rrt-card">
          <header class="rrt-head">
            <div class="rrt-head-info">
              <h4 class="rrt-name">{{ g.supplier_name }}</h4>
              <span class="rrt-tag rrt-tag-local">локальный</span>
            </div>
            <label class="rrt-toggle" :title="isEnabled(g) ? 'Выключить напоминания' : 'Включить напоминания'">
              <input type="checkbox" :checked="isEnabled(g)" :disabled="saving[g.supplier_id]" @change="onToggleEnabled(g, $event.target.checked)" />
              <span class="rrt-toggle-slider"></span>
            </label>
          </header>

          <div class="rrt-schedule">
            <span v-for="d in g.days" :key="d.order_day + '-' + d.delivery_day" class="rrt-day">
              <span class="rrt-day-from">{{ weekdayShort(d.order_day) }} {{ effectiveDeadlineTime(g, d) }}</span>
              <span class="rrt-day-arrow">→</span>
              <span class="rrt-day-to">{{ weekdayShort(d.delivery_day) }}</span>
            </span>
          </div>

          <div v-if="isEnabled(g)" class="rrt-body">
            <label class="rrt-checkbox" :class="{ 'is-disabled': !availableTg.length }">
              <input type="checkbox" :checked="g.subscription?.telegram_enabled" :disabled="!availableTg.length" @change="onChannelChange(g, 'telegram', $event.target.checked)" />
              <span>Дублировать в Telegram</span>
            </label>

            <button v-if="g.subscription?.telegram_enabled && availableTg.length"
                    type="button"
                    class="rrt-tg-chip"
                    :class="{ 'is-empty': !(g.selected_tg_ids || []).length, 'is-open': expandedTg.has(g.supplier_id) }"
                    @click="toggleTgPanel(g.supplier_id)">
              <span>{{ recipientsLabel(g.selected_tg_ids) }}</span>
              <span class="rrt-tg-chip-arrow">▾</span>
            </button>
          </div>

          <div v-if="isEnabled(g) && g.subscription?.telegram_enabled && availableTg.length && expandedTg.has(g.supplier_id)" class="rrt-tg">
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
            <p class="rrt-tg-hint">Если никого не отметить — сообщения в Telegram не уйдут.</p>
          </div>
        </article>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, defineAsyncComponent } from 'vue';
import { useToastStore } from '@/stores/toastStore.js';
import { usePushNotifications } from '@/composables/usePushNotifications.js';
import { roFetch } from '@/lib/roUtils.js';

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

const WEEKDAYS_SHORT = ['', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
function weekdayShort(d) { return WEEKDAYS_SHORT[d] || ''; }
function fmtTime(t) { return t ? String(t).slice(0, 5) : ''; }

function effectiveDeadlineTime(group, day) {
  if (day.deadline_override) return fmtTime(day.deadline_override);
  const dflt = (group.default_deadlines || []).find(d => Number(d.delivery_dow) === Number(day.delivery_day));
  return dflt ? fmtTime(dflt.deadline_time) : '';
}

function isEnabled(group) {
  return !!group.subscription?.is_enabled;
}

function isSelected(group, tgId) {
  return (group.selected_tg_ids || []).includes(tgId);
}

// Группировка карточек: «через портал» vs «локальные»
const portalGroups = computed(() => groups.value.filter(g => g.so_enabled));
const localGroups = computed(() => groups.value.filter(g => !g.so_enabled));

// Раскрытые TG-панели поставщиков (по supplier_id)
const expandedTg = ref(new Set());
function toggleTgPanel(supplierId) {
  const s = new Set(expandedTg.value);
  if (s.has(supplierId)) s.delete(supplierId); else s.add(supplierId);
  expandedTg.value = s;
}
// Раскрытая TG-панель «Основной поставки»
const mainTgOpen = ref(false);

// Лейбл «Получатели: Иван, Мария +1»
function recipientsLabel(selectedIds) {
  const ids = selectedIds || [];
  if (!ids.length) return 'Получатели: никого';
  const names = ids
    .map(id => {
      const u = availableTg.value.find(x => x.id === id);
      return u ? u.name : null;
    })
    .filter(Boolean);
  if (!names.length) return 'Получатели: никого';
  if (names.length <= 2) return 'Получатели: ' + names.join(', ');
  return 'Получатели: ' + names[0] + ', ' + names[1] + ' +' + (names.length - 2);
}

// Чип статуса Push
const pushChipLabel = computed(() => {
  if (push.permission.value === 'denied') return 'Push заблокирован';
  return push.isSubscribed.value ? 'Push: вкл' : 'Включить push';
});
const pushChipTitle = computed(() => {
  if (push.permission.value === 'denied') return 'Разрешение заблокировано в браузере';
  return push.isSubscribed.value
    ? 'Push в этом браузере включён. Кликни, чтобы выключить.'
    : 'Кликни, чтобы получать напоминания, даже когда вкладка закрыта.';
});
function onPushChipClick() {
  if (push.busy.value) return;
  if (push.isSubscribed.value) push.unsubscribe();
  else if (push.permission.value !== 'denied') push.subscribe();
}

// Чип статуса Telegram
const tgChipLabel = computed(() => {
  const n = availableTg.value.length;
  if (!n) return 'Telegram: никто не привязал бота';
  const word = n === 1 ? 'сотрудник' : (n >= 2 && n <= 4 ? 'сотрудника' : 'сотрудников');
  return `Telegram: ${n} ${word}`;
});
const tgChipTitle = computed(() => {
  return availableTg.value.length
    ? 'Готово выбирать получателей в карточке поставщика.'
    : 'Сотрудники могут привязать чат через настройки уведомлений в боте.';
});

async function loadGroups() {
  loading.value = true;
  try {
    const data = await roFetch('/api/restaurant-reminders/list');
    groups.value = data.groups || [];
    availableTg.value = data.available_tg || [];
    mainDelivery.value = data.main_delivery || { days: [], subscription: null, selected_tg_ids: [] };
    if (!mainDelivery.value.selected_tg_ids) mainDelivery.value.selected_tg_ids = [];
  } catch (e) {
    toast.error(e.message || 'Не удалось загрузить напоминания');
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
    await roFetch('/api/restaurant-reminders/main-set', { method: 'POST', body: payload });
    mainDelivery.value.subscription = {
      ...(mainDelivery.value.subscription || {}),
      is_enabled: !!payload.is_enabled,
      portal_enabled: !!payload.is_enabled,
      telegram_enabled: !!payload.telegram_enabled,
    };
    return true;
  } catch (e) {
    toast.error(e.message || 'Ошибка');
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
    await roFetch('/api/restaurant-reminders/main-tg-set', {
      method: 'POST',
      body: { ro_tg_sub_ids: newIds },
    });
    mainDelivery.value.selected_tg_ids = newIds;
    if (newIds.length && !mainDelivery.value.subscription?.telegram_enabled) {
      if (!mainDelivery.value.subscription) mainDelivery.value.subscription = { is_enabled: true, portal_enabled: true, telegram_enabled: true };
      mainDelivery.value.subscription.telegram_enabled = true;
    }
  } catch (e) {
    toast.error(e.message || 'Ошибка');
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
    await roFetch('/api/restaurant-reminders/set', { method: 'POST', body: payload });
    if (!group.subscription) group.subscription = { is_enabled: 0, portal_enabled: 1, telegram_enabled: 0 };
    group.subscription = {
      ...group.subscription,
      is_enabled: !!payload.is_enabled,
      portal_enabled: !!payload.portal_enabled,
      telegram_enabled: !!payload.telegram_enabled,
    };
    return true;
  } catch (e) {
    toast.error(e.message || 'Ошибка');
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
    await roFetch('/api/restaurant-reminders/tg-set', {
      method: 'POST',
      body: { supplier_id: group.supplier_id, ro_tg_sub_ids: newIds },
    });
    group.selected_tg_ids = newIds;
    // Если выбрали хотя бы одного — телеграм-канал включается на сервере
    if (newIds.length && !group.subscription?.telegram_enabled) {
      if (!group.subscription) group.subscription = { is_enabled: 1, portal_enabled: 1, telegram_enabled: 1 };
      group.subscription.telegram_enabled = true;
    }
  } catch (e) {
    toast.error(e.message || 'Ошибка');
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

/* ─── Тулбар: интро + статус-чипы в одну строку ─── */
.rrt-toolbar {
  display: flex; gap: 12px; align-items: center; flex-wrap: wrap;
  margin: 0 0 14px; padding: 10px 14px;
  background: #f7f9fb; border: 1px solid #e6ebf1; border-radius: 10px;
}
.rrt-toolbar-intro {
  display: flex; align-items: center; gap: 8px;
  flex: 1; min-width: 220px;
  font-size: 13px; line-height: 1.5; color: #455565;
}
.rrt-help-btn {
  flex: none;
  display: inline-flex; align-items: center; justify-content: center;
  width: 28px; height: 28px; padding: 0; border: 1px solid #d1d8df;
  background: #fff; color: #2d3a48; border-radius: 50%;
  cursor: pointer; transition: background 0.15s, color 0.15s, border-color 0.15s;
}
.rrt-help-btn:hover { background: #1a73e8; color: #fff; border-color: #1a73e8; }
.rrt-toolbar-chips { display: flex; gap: 8px; flex-wrap: wrap; }

.rrt-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 5px 12px; border-radius: 999px;
  font-size: 12px; font-weight: 600; line-height: 1.3;
  background: #eef2f6; color: #5a6b7c; border: 1px solid transparent;
  white-space: nowrap;
}
.rrt-chip.is-on { background: #e7f5e8; color: #2e7d32; border-color: #c4e6c8; }
.rrt-chip.is-warn { background: #fff4e0; color: #b35900; border-color: #ffe0b2; }
.rrt-chip.is-blocked { background: #fdecea; color: #c62828; border-color: #f5c2c2; }
.rrt-chip-action { cursor: pointer; transition: filter 0.12s, transform 0.12s; }
.rrt-chip-action:hover:not(:disabled) { filter: brightness(0.97); }
.rrt-chip-action:active:not(:disabled) { transform: translateY(1px); }
.rrt-chip-action:disabled { cursor: default; opacity: 0.7; }

@media (max-width: 560px) {
  .rrt-toolbar { padding: 10px 12px; }
  .rrt-toolbar-intro { font-size: 12px; }
}

/* ─── Сетка карточек ─── */
.rrt-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 12px;
  align-items: start;
}
.rrt-card-wide { grid-column: 1 / -1; }

.rrt-group-title {
  grid-column: 1 / -1;
  margin: 10px 0 -2px;
  font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.06em;
  color: #8a9aab;
}

/* ─── Карточка ─── */
.rrt-card {
  background: #fff; border: 1px solid #e8e8e8; border-radius: 10px;
  padding: 12px 14px;
  display: flex; flex-direction: column; gap: 10px;
}
.rrt-card-main { border-color: #c4e6c8; background: linear-gradient(180deg, #f4faf5 0%, #ffffff 100%); }

.rrt-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.rrt-head-info { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; min-width: 0; }
.rrt-name { margin: 0; font-size: 15px; font-weight: 700; color: #2b2b2b; line-height: 1.3; }
.rrt-tag { font-size: 10px; padding: 2px 8px; border-radius: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
.rrt-tag-so { background: #e3f2fd; color: #1565c0; }
.rrt-tag-local { background: #fff4e0; color: #b35900; }
.rrt-tag-main { background: #e8f5e9; color: #2e7d32; }

.rrt-toggle { flex: none; display: inline-flex; align-items: center; cursor: pointer; user-select: none; }
.rrt-toggle input { display: none; }
.rrt-toggle-slider { width: 36px; height: 20px; background: #d8d8d8; border-radius: 12px; position: relative; transition: background 0.15s; }
.rrt-toggle-slider::after { content: ''; position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; background: #fff; border-radius: 50%; transition: left 0.15s; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
.rrt-toggle input:checked + .rrt-toggle-slider { background: #4caf50; }
.rrt-toggle input:checked + .rrt-toggle-slider::after { left: 18px; }

/* ─── Расписание: компактные пилюли ─── */
.rrt-schedule { display: flex; flex-wrap: wrap; gap: 6px; }
.rrt-day {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 3px 9px; background: #f1f4f8; border-radius: 999px;
  font-size: 12px; color: #455565; white-space: nowrap;
}
.rrt-day-from { color: #2b2b2b; font-weight: 600; }
.rrt-day-arrow { color: #8a9aab; font-weight: 700; }
.rrt-day-to { color: #2b2b2b; font-weight: 600; }
.rrt-card-main .rrt-day { background: #e8f5e9; }

/* ─── Тело карточки (когда включено) ─── */
.rrt-body {
  display: flex; flex-wrap: wrap; gap: 8px 14px; align-items: center;
  padding-top: 8px; border-top: 1px solid #f2f2f2;
}
.rrt-checkbox { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: #455565; cursor: pointer; }
.rrt-checkbox input { margin: 0; }
.rrt-checkbox.is-disabled { color: #aaa; cursor: not-allowed; }

.rrt-tg-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 4px 10px; border-radius: 999px;
  background: #e7f5e8; color: #2e7d32; border: 1px solid #c4e6c8;
  font-size: 12px; font-weight: 600;
  cursor: pointer; transition: background 0.12s;
}
.rrt-tg-chip:hover { background: #d6ebd9; }
.rrt-tg-chip.is-empty { background: #fff4e0; color: #b35900; border-color: #ffe0b2; }
.rrt-tg-chip-arrow { font-size: 10px; transition: transform 0.15s; }
.rrt-tg-chip.is-open .rrt-tg-chip-arrow { transform: rotate(180deg); }

/* ─── Раскрытая TG-панель ─── */
.rrt-tg { padding-top: 10px; border-top: 1px dashed #e8e8e8; }
.rrt-tg-list { display: flex; flex-direction: column; gap: 4px; }
.rrt-tg-item { display: flex; align-items: center; gap: 10px; padding: 6px 10px; background: #fafafa; border: 1px solid transparent; border-radius: 6px; cursor: pointer; transition: background 0.1s; }
.rrt-tg-item:hover { background: #f3f3f3; }
.rrt-tg-item.is-selected { background: #e7f5e8; border-color: #c4e6c8; }
.rrt-tg-item input { margin: 0; }
.rrt-tg-info { display: flex; align-items: baseline; gap: 8px; min-width: 0; }
.rrt-tg-name { font-size: 13px; color: #2b2b2b; font-weight: 500; }
.rrt-tg-username { font-size: 11px; color: #888; }
.rrt-tg-hint { margin: 8px 0 0; font-size: 11px; color: #888; line-height: 1.5; }

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
