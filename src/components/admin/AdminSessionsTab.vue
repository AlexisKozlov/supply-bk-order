<template>
  <div class="ses">
    <!-- ═══ Сводка ═══ -->
    <div class="ses-cards">
      <div class="ses-card ses-card-live">
        <div class="ses-card-label">Сотрудники сейчас</div>
        <div class="ses-card-value">{{ onlineUsers.length }}</div>
        <div class="ses-card-sub">{{ onlineUsers.length ? 'на портале' : 'никого нет' }}</div>
      </div>
      <div class="ses-card ses-card-live">
        <div class="ses-card-label">Рестораны сейчас</div>
        <div class="ses-card-value">{{ onlineRestaurants.length }}</div>
        <div class="ses-card-sub">{{ onlineRestaurants.length ? 'в кабинете' : 'никого нет' }}</div>
      </div>
      <div class="ses-card">
        <div class="ses-card-label">Устройства сотрудников</div>
        <div class="ses-card-value">{{ portalSessions.length }}</div>
        <div class="ses-card-sub">{{ portalUsersCount }} {{ plural(portalUsersCount, 'человек', 'человека', 'человек') }}</div>
      </div>
      <div class="ses-card">
        <div class="ses-card-label">Устройства ресторанов</div>
        <div class="ses-card-value">{{ roSessions.length }}</div>
        <div class="ses-card-sub">{{ roPwaCount }} через приложение</div>
      </div>
    </div>

    <!-- ═══ Кто сейчас в системе ═══ -->
    <div class="ses-now">
      <div class="ses-now-col">
        <div class="ses-now-head">
          <h4>Сотрудники на портале</h4>
          <button class="ses-mini-btn" @click="loadOnline" :disabled="onlineLoading" title="Обновить">
            <BkIcon name="redo" size="sm"/>
          </button>
        </div>
        <div v-if="onlineLoading && !onlineUsers.length" class="ses-now-empty">Загрузка…</div>
        <div v-else-if="!onlineUsers.length" class="ses-now-empty">Никого нет</div>
        <div v-else class="ses-now-list">
          <div v-for="u in onlineUsers" :key="u.user_name" class="ses-now-row">
            <span class="ses-avatar ses-avatar-sm is-online">{{ initials(u.user_name) }}</span>
            <div class="ses-now-info">
              <div class="ses-now-name">
                {{ u.user_name }}
                <span v-if="u.user_name === myName" class="ses-tag ses-tag-you">вы</span>
              </div>
              <div class="ses-now-page">{{ pageLabel(u.page) }}</div>
            </div>
            <span class="ses-now-time">{{ rel(u.last_seen) }}</span>
          </div>
        </div>
      </div>

      <div class="ses-now-col">
        <div class="ses-now-head">
          <h4>Рестораны в кабинете</h4>
          <button class="ses-mini-btn" @click="loadOnline" :disabled="onlineLoading" title="Обновить">
            <BkIcon name="redo" size="sm"/>
          </button>
        </div>
        <div v-if="onlineLoading && !onlineRestaurants.length" class="ses-now-empty">Загрузка…</div>
        <div v-else-if="!onlineRestaurants.length" class="ses-now-empty">Никого нет</div>
        <div v-else class="ses-now-list">
          <div v-for="r in onlineRestaurants"
               :key="(r.legal_entity_group || 'BK_VM') + '-' + r.restaurant_number"
               class="ses-now-row">
            <span class="ses-avatar ses-avatar-sm is-online is-rest">{{ restLabel(r.restaurant_number, r.legal_entity_group) }}</span>
            <div class="ses-now-info">
              <div class="ses-now-name">{{ placeLabel(r.city, r.address) || '—' }}</div>
              <div class="ses-now-page">{{ pageLabel(r.last_page) }} · {{ shortEntity(r.legal_entity) }}</div>
            </div>
            <span class="ses-now-time">{{ rel(r.last_activity) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══ Устройства ═══ -->
    <div class="ses-toolbar">
      <div class="ses-seg">
        <button class="ses-seg-btn" :class="{ active: scope === 'portal' }" @click="scope = 'portal'">
          Сотрудники <span class="ses-seg-count">{{ portalSessions.length }}</span>
        </button>
        <button class="ses-seg-btn" :class="{ active: scope === 'restaurants' }" @click="scope = 'restaurants'">
          Рестораны <span class="ses-seg-count">{{ roSessions.length }}</span>
        </button>
      </div>

      <div class="ses-search">
        <BkIcon name="search" size="sm"/>
        <input v-model="query" type="search" :placeholder="scope === 'portal' ? 'Имя или IP' : 'Номер, город или IP'">
        <button v-if="query" class="ses-search-clear" @click="query = ''" title="Очистить">
          <BkIcon name="close" size="sm"/>
        </button>
      </div>

      <label class="ses-check">
        <input type="checkbox" v-model="onlyActive">
        <span>Только активные за сутки</span>
      </label>

      <button class="btn ses-refresh" @click="loadSessions" :disabled="loading">
        <BkIcon name="redo" size="sm"/> Обновить
      </button>
    </div>

    <div v-if="loading && !groups.length" class="ses-loading"><BurgerSpinner text="Загрузка…" /></div>
    <div v-else-if="!groups.length" class="ses-empty">
      {{ query || onlyActive ? 'Ничего не найдено — попробуйте изменить фильтр' : 'Активных сессий нет' }}
    </div>

    <div v-else class="ses-groups">
      <div v-for="g in groups" :key="g.key" class="ses-group">
        <div class="ses-group-head">
          <span class="ses-avatar" :class="{ 'is-online': g.online, 'is-rest': scope === 'restaurants', 'is-admin': g.role === 'admin' }">
            {{ g.badge }}
          </span>
          <div class="ses-group-info">
            <div class="ses-group-name">
              {{ g.title }}
              <span v-if="g.isMe" class="ses-tag ses-tag-you">вы</span>
              <span v-if="g.role === 'admin'" class="ses-tag ses-tag-admin">админ</span>
            </div>
            <div class="ses-group-meta">
              <span v-if="g.online" class="ses-live">сейчас в системе</span>
              <span v-else>активность {{ rel(g.lastSeen) }}</span>
              <span v-if="g.page"> · {{ pageLabel(g.page) }}</span>
            </div>
          </div>
          <span class="ses-group-count">{{ g.rows.length }} {{ plural(g.rows.length, 'устройство', 'устройства', 'устройств') }}</span>
          <button v-if="g.rows.length > 1 || !g.hasMine" class="ses-group-kill"
                  @click="terminateGroup(g)" :disabled="busy">
            Завершить все
          </button>
        </div>

        <div class="ses-devices">
          <div v-for="s in g.rows" :key="s.id" class="ses-device" :class="{ 'is-current': s.is_current }">
            <div class="ses-dev-main">
              <span class="ses-dev-kind" :class="deviceKindClass(s)">{{ deviceKind(s) }}</span>
              <span class="ses-dev-name">{{ deviceName(s) }}</span>
              <span v-if="s.is_current" class="ses-tag ses-tag-you">эта вкладка</span>
              <span v-if="s.is_pwa" class="ses-tag ses-tag-pwa">приложение</span>
              <span v-if="s.remember" class="ses-tag ses-tag-soft">запомнено</span>
            </div>
            <div class="ses-dev-meta">
              <span class="ses-dev-ip">{{ s.ip_address || 'IP неизвестен' }}</span>
              <span>вход {{ dt(s.created_at) }}</span>
              <span>активность {{ rel(s.last_seen_at || s.created_at) }}</span>
              <span :class="{ 'ses-dev-soon': expiresSoon(s) }">{{ expiresLabel(s) }}</span>
            </div>
            <button class="ses-dev-kill" @click="terminateOne(s)" :disabled="busy || s.is_current"
                    :title="s.is_current ? 'Это ваша текущая сессия' : 'Завершить сессию'">
              <BkIcon name="close" size="sm"/>
            </button>
          </div>
        </div>
      </div>
    </div>

    <ConfirmModal v-if="confirmModal.show" :title="confirmModal.title" :message="confirmModal.message"
      @confirm="onConfirmOk" @cancel="onConfirmCancel" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, defineAsyncComponent } from 'vue';
import { useRouter } from 'vue-router';
import { db } from '@/lib/apiClient.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import { formatMoscowDateTime, formatMoscowRelative, plural } from '@/lib/utils.js';
import { formatRestaurantNumber } from '@/lib/legalEntities.js';
import { useToastStore } from '@/stores/toastStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { useConfirm } from '@/composables/useConfirm.js';

const ConfirmModal = defineAsyncComponent(() => import('@/components/modals/ConfirmModal.vue'));

const emit = defineEmits(['online-count']);

const toast = useToastStore();
const userStore = useUserStore();
const router = useRouter();
const {
  confirmModal,
  confirm: confirmAction,
  onConfirm: onConfirmOk,
  onCancel: onConfirmCancel,
} = useConfirm();

const myName = computed(() => userStore.currentUser?.name || '');

// Названия страниц для «где сейчас человек»: старые бандлы шлют имя маршрута.
const routeTitles = {};
router.getRoutes().forEach(r => { if (r.name && r.meta?.title) routeTitles[r.name] = r.meta.title; });
function pageLabel(page) {
  if (!page) return '';
  return routeTitles[page] || page;
}

const rel = formatMoscowRelative;
const dt = (s) => formatMoscowDateTime(s) || '—';

function initials(name) {
  if (!name) return '?';
  return name.split(/\s+/).map(w => w[0]).join('').toUpperCase().slice(0, 2);
}
// Пицца Стар в базе идёт с 1001, а людям показываем PS01…PS52 —
// формат общий для портала, бота и Excel (см. formatRestaurantNumber).
function restLabel(num, group) {
  const label = formatRestaurantNumber(num, group);
  if (!label) return '—';
  return (group === 'PS' || Number(num) >= 1000) ? label : '№' + label;
}
// Город и адрес часто дублируются: city «Гродно», address «г. Гродно, ул. …».
// Показываем адрес целиком, город добавляем только если его там нет.
function placeLabel(city, address) {
  const c = String(city || '').trim();
  const a = String(address || '').trim();
  if (!a) return c;
  if (c && a.toLowerCase().includes(c.toLowerCase())) return a;
  return c ? c + ', ' + a : a;
}

const LE_SHORT = { 'ООО "Бургер БК"': 'БК', 'ООО "Воглия Матта"': 'ВМ', 'ООО "Пицца Стар"': 'ПС' };
function shortEntity(le) {
  if (!le) return '';
  if (LE_SHORT[le]) return LE_SHORT[le];
  const m = le.match(/«([^»]+)»|"([^"]+)"/);
  return m ? (m[1] || m[2]).trim() : le;
}

// ─── Данные ───
const onlineUsers = ref([]);
const onlineRestaurants = ref([]);
const onlineLoading = ref(false);
const portalSessions = ref([]);
const roSessions = ref([]);
const loading = ref(false);
const busy = ref(false);

const scope = ref('portal');
const query = ref('');
const onlyActive = ref(false);

let timer = null;

const portalUsersCount = computed(() => new Set(portalSessions.value.map(s => s.user_name)).size);
const roPwaCount = computed(() => roSessions.value.filter(s => Number(s.is_pwa) === 1).length);

// Кто «в системе прямо сейчас»: сотрудники по присутствию, рестораны по
// последней активности сессии.
const onlineNames = computed(() => new Set(onlineUsers.value.map(u => u.user_name)));

function isFresh(str, minutes) {
  if (!str) return false;
  const d = new Date(String(str).replace(' ', 'T') + '+03:00');
  if (isNaN(d)) return false;
  return Date.now() - d.getTime() < minutes * 60000;
}

function deviceKind(s) {
  const label = String(s.device_label || '');
  if (/iPhone|Android|iPad/i.test(label)) return /iPad/i.test(label) ? 'Планшет' : 'Телефон';
  if (/Windows|Mac|Linux/i.test(label)) return 'Компьютер';
  return 'Прочее';
}
function deviceKindClass(s) {
  const k = deviceKind(s);
  if (k === 'Прочее') return 'is-other';
  return k === 'Телефон' || k === 'Планшет' ? 'is-mobile' : 'is-desktop';
}
// Браузер не распознан (скрипт, curl, старый бот) — не пишем «Устройство»
// дважды, а говорим честно.
function deviceName(s) {
  const label = String(s.device_label || '').trim();
  if (!label || label === 'Устройство') return 'Не определилось';
  return label;
}

function daysLeft(s) {
  if (!s.expires_at) return null;
  const d = new Date(String(s.expires_at).replace(' ', 'T') + '+03:00');
  if (isNaN(d)) return null;
  return Math.round((d.getTime() - Date.now()) / 86400000);
}
function expiresLabel(s) {
  const n = daysLeft(s);
  if (n === null) return '';
  if (n <= 0) return 'истекает сегодня';
  return `ещё ${n} ${plural(n, 'день', 'дня', 'дней')}`;
}
function expiresSoon(s) {
  const n = daysLeft(s);
  return n !== null && n <= 1;
}

// ─── Группировка ───
const groups = computed(() => {
  const q = query.value.trim().toLowerCase();
  const map = new Map();

  if (scope.value === 'portal') {
    for (const s of portalSessions.value) {
      if (onlyActive.value && !isFresh(s.last_seen_at || s.created_at, 1440)) continue;
      if (q && !(`${s.user_name} ${s.ip_address || ''} ${s.device_label || ''}`.toLowerCase().includes(q))) continue;
      const key = s.user_name;
      if (!map.has(key)) {
        map.set(key, {
          key,
          title: s.user_name,
          badge: initials(s.user_name),
          role: s.role,
          isMe: s.user_name === myName.value,
          online: onlineNames.value.has(s.user_name),
          page: onlineUsers.value.find(u => u.user_name === s.user_name)?.page || '',
          lastSeen: s.last_seen_at || s.created_at,
          hasMine: false,
          rows: [],
        });
      }
      const g = map.get(key);
      g.rows.push(s);
      if (s.is_current) g.hasMine = true;
      const cur = s.last_seen_at || s.created_at;
      if (cur > g.lastSeen) g.lastSeen = cur;
    }
  } else {
    for (const s of roSessions.value) {
      if (onlyActive.value && !isFresh(s.last_seen_at, 1440)) continue;
      const place = placeLabel(s.city, s.address);
      const label = restLabel(s.restaurant_number, s.legal_entity_group);
      if (q && !(`${label} ${s.restaurant_number} ${place} ${s.ip_address || ''} ${s.device_label || ''}`.toLowerCase().includes(q))) continue;
      const key = (s.legal_entity_group || 'BK_VM') + '-' + s.restaurant_number;
      if (!map.has(key)) {
        map.set(key, {
          key,
          number: s.restaurant_number,
          group: s.legal_entity_group || 'BK_VM',
          title: label + (place ? ' · ' + place : ''),
          badge: label,
          role: '',
          isMe: false,
          online: isFresh(s.last_seen_at, 15),
          page: s.last_page || '',
          lastSeen: s.last_seen_at,
          hasMine: false,
          rows: [],
        });
      }
      const g = map.get(key);
      g.rows.push(s);
      if (s.last_seen_at > g.lastSeen) g.lastSeen = s.last_seen_at;
    }
  }

  const list = [...map.values()];
  // Сначала те, кто в системе прямо сейчас, дальше — по свежести активности.
  list.sort((a, b) => (Number(b.online) - Number(a.online)) || String(b.lastSeen).localeCompare(String(a.lastSeen)));
  return list;
});

// ─── Загрузка ───
async function loadOnline() {
  onlineLoading.value = true;
  try {
    const [u, r] = await Promise.all([
      db.rpc('get_online_users'),
      db.rpc('get_online_restaurants'),
    ]);
    onlineUsers.value = u.data || [];
    onlineRestaurants.value = r.data || [];
    emit('online-count', onlineUsers.value.length);
  } catch (e) {
    console.warn('[sessions] online:', e);
  } finally {
    onlineLoading.value = false;
  }
}

async function loadSessions() {
  loading.value = true;
  try {
    const [p, r] = await Promise.all([
      db.rpc('get_sessions'),
      db.rpc('get_ro_sessions'),
    ]);
    portalSessions.value = Array.isArray(p.data) ? p.data : [];
    roSessions.value = Array.isArray(r.data) ? r.data : [];
  } catch (e) {
    toast.error('Ошибка', 'Не удалось загрузить сессии');
  } finally {
    loading.value = false;
  }
}

// ─── Действия ───
async function terminateOne(s) {
  if (s.is_current) return;
  const who = scope.value === 'portal'
    ? s.user_name
    : restLabel(s.restaurant_number, s.legal_entity_group);
  const ok = await confirmAction(
    'Завершить сессию?',
    `${who} — ${deviceName(s)}. Придётся войти заново.`
  );
  if (!ok) return;
  busy.value = true;
  try {
    const fn = scope.value === 'portal' ? 'terminate_session' : 'terminate_ro_session';
    const { data } = await db.rpc(fn, { session_id: s.id });
    if (data?.success) {
      toast.success('Сессия завершена', who);
      if (scope.value === 'portal') portalSessions.value = portalSessions.value.filter(x => x.id !== s.id);
      else roSessions.value = roSessions.value.filter(x => x.id !== s.id);
    } else {
      toast.error('Ошибка', data?.error || '');
    }
  } catch {
    toast.error('Ошибка', 'Не удалось завершить сессию');
  } finally {
    busy.value = false;
  }
}

async function terminateGroup(g) {
  const n = g.rows.filter(r => !r.is_current).length;
  if (!n) return;
  const tail = g.hasMine ? ' Ваша текущая сессия останется.' : '';
  const ok = await confirmAction(
    'Завершить все сессии?',
    `${g.title}: отключим ${n} ${plural(n, 'устройство', 'устройства', 'устройств')}.${tail}`
  );
  if (!ok) return;
  busy.value = true;
  try {
    const { data } = scope.value === 'portal'
      ? await db.rpc('terminate_user_sessions', { user_name: g.key })
      : await db.rpc('terminate_ro_restaurant_sessions', { restaurant_number: g.number, legal_entity_group: g.group });
    if (data?.success) {
      toast.success('Готово', `Завершено сессий: ${data.count ?? n}`);
      await loadSessions();
    } else {
      toast.error('Ошибка', data?.error || '');
    }
  } catch {
    toast.error('Ошибка', 'Не удалось завершить сессии');
  } finally {
    busy.value = false;
  }
}

onMounted(() => {
  loadOnline();
  loadSessions();
  // На скрытой вкладке сервер не дёргаем — лишний трафик и нагрузка.
  timer = setInterval(() => {
    if (typeof document === 'undefined' || document.visibilityState === 'visible') loadOnline();
  }, 15000);
});
onBeforeUnmount(() => { if (timer) clearInterval(timer); });
</script>

<style scoped>
.ses { display: flex; flex-direction: column; gap: 16px; }

/* ═══ Сводка ═══ */
.ses-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
.ses-card {
  background: var(--card); border: 1px solid var(--border-light);
  border-radius: 12px; padding: 12px 14px;
}
.ses-card-live { border-color: #C8E6C9; background: linear-gradient(180deg, #F4FBF4, var(--card)); }
.ses-card-label { font-size: 11px; text-transform: uppercase; letter-spacing: .3px; color: var(--text-muted); font-weight: 600; }
.ses-card-value { font-size: 26px; font-weight: 800; color: var(--text); line-height: 1.2; margin-top: 2px; }
.ses-card-sub { font-size: 11.5px; color: var(--text-muted); }

/* ═══ Кто сейчас ═══ */
.ses-now { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.ses-now-col {
  background: var(--card); border: 1px solid var(--border-light);
  border-radius: 12px; padding: 12px 14px; min-width: 0;
}
.ses-now-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.ses-now-head h4 { margin: 0; font-size: 13px; font-weight: 700; color: var(--text); }
.ses-now-empty { font-size: 12.5px; color: var(--text-muted); padding: 10px 0; }
.ses-now-list { display: flex; flex-direction: column; gap: 6px; max-height: 260px; overflow-y: auto; }
.ses-now-row { display: flex; align-items: center; gap: 10px; min-width: 0; }
.ses-now-info { flex: 1; min-width: 0; }
.ses-now-name {
  font-size: 13px; font-weight: 600; color: var(--text);
  display: flex; align-items: center; gap: 6px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ses-now-page { font-size: 11.5px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ses-now-time { font-size: 11.5px; color: var(--text-muted); flex-shrink: 0; white-space: nowrap; }

.ses-mini-btn {
  border: 1px solid var(--border-light); background: none; cursor: pointer;
  color: var(--text-muted); border-radius: 6px; padding: 3px 7px; transition: all .15s;
}
.ses-mini-btn:hover { background: var(--bg); color: var(--text); }
.ses-mini-btn:disabled { opacity: .4; pointer-events: none; }

/* ═══ Аватар ═══ */
.ses-avatar {
  width: 38px; height: 38px; border-radius: 11px; flex-shrink: 0;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 12.5px; font-weight: 700; color: #fff; position: relative;
  background: linear-gradient(135deg, #F4A261, #E8941A);
}
.ses-avatar-sm { width: 30px; height: 30px; border-radius: 9px; font-size: 11px; }
.ses-avatar.is-rest { background: linear-gradient(135deg, #7E9CB8, #4F6E8C); }
.ses-avatar.is-admin { background: linear-gradient(135deg, #E53935, #C62828); }
.ses-avatar.is-online::after {
  content: ''; position: absolute; bottom: -1px; right: -1px;
  width: 10px; height: 10px; border-radius: 50%;
  background: #4CAF50; border: 2px solid var(--card);
}

/* ═══ Панель ═══ */
.ses-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.ses-seg {
  display: inline-flex; background: var(--bg); border: 1px solid var(--border-light);
  border-radius: 10px; padding: 2px;
}
.ses-seg-btn {
  border: none; background: none; cursor: pointer; font-family: inherit;
  font-size: 13px; font-weight: 600; color: var(--text-muted);
  padding: 6px 12px; border-radius: 8px; transition: all .15s;
  display: inline-flex; align-items: center; gap: 6px;
}
.ses-seg-btn.active { background: var(--card); color: var(--text); box-shadow: 0 1px 3px rgba(0,0,0,.08); }
.ses-seg-count {
  font-size: 11px; font-weight: 700; padding: 1px 6px; border-radius: 6px;
  background: rgba(0,0,0,.06); color: var(--text-muted);
}
.ses-seg-btn.active .ses-seg-count { background: #FFF3E0; color: #E65100; }

.ses-search {
  display: flex; align-items: center; gap: 6px; flex: 1; min-width: 180px;
  background: var(--card); border: 1px solid var(--border-light);
  border-radius: 10px; padding: 0 10px; color: var(--text-muted);
}
.ses-search input {
  flex: 1; min-width: 0; border: none; background: none; outline: none;
  font-family: inherit; font-size: 13px; color: var(--text); padding: 8px 0;
}
.ses-search input::-webkit-search-cancel-button { display: none; }
.ses-search-clear { border: none; background: none; cursor: pointer; color: var(--text-muted); padding: 2px; }

.ses-check { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text-secondary); cursor: pointer; }
.ses-check input { cursor: pointer; }
.ses-refresh { flex-shrink: 0; }

.ses-loading { text-align: center; padding: 48px; }
.ses-empty { text-align: center; padding: 40px; color: var(--text-muted); font-size: 14px; }

/* ═══ Группы ═══ */
.ses-groups { display: flex; flex-direction: column; gap: 10px; }
.ses-group {
  background: var(--card); border: 1px solid var(--border-light);
  border-radius: 12px; overflow: hidden;
}
.ses-group-head {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 14px; border-bottom: 1px solid var(--border-light);
}
.ses-group-info { flex: 1; min-width: 0; }
.ses-group-name {
  font-size: 14px; font-weight: 700; color: var(--text);
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
}
.ses-group-meta { font-size: 11.5px; color: var(--text-muted); margin-top: 1px; }
.ses-live { color: #2E7D32; font-weight: 600; }
.ses-group-count { font-size: 11.5px; color: var(--text-muted); flex-shrink: 0; white-space: nowrap; }
.ses-group-kill {
  flex-shrink: 0; border: 1px solid var(--border-light); background: none;
  font-family: inherit; font-size: 11.5px; font-weight: 600; color: var(--text-muted);
  padding: 5px 10px; border-radius: 8px; cursor: pointer; transition: all .15s;
}
.ses-group-kill:hover { background: #FFF0F0; border-color: #E57373; color: #D32F2F; }
.ses-group-kill:disabled { opacity: .4; pointer-events: none; }

/* ═══ Устройства ═══ */
.ses-devices { display: flex; flex-direction: column; }
.ses-device {
  display: flex; align-items: center; gap: 12px;
  padding: 9px 14px; border-top: 1px solid var(--border-light);
}
.ses-device:first-child { border-top: none; }
.ses-device.is-current { background: #F4FBF4; }
.ses-dev-main { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; flex: 1; min-width: 0; }
.ses-dev-name { font-size: 13px; font-weight: 600; color: var(--text); }
.ses-dev-kind {
  font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px;
  padding: 2px 7px; border-radius: 6px; flex-shrink: 0;
}
.ses-dev-kind.is-mobile { background: #E3F2FD; color: #1565C0; }
.ses-dev-kind.is-desktop { background: #F3EBE3; color: #6B5A50; }
.ses-dev-kind.is-other { background: var(--bg); color: var(--text-muted); }
.ses-dev-meta {
  display: flex; gap: 14px; font-size: 11.5px; color: var(--text-muted);
  flex-shrink: 0; white-space: nowrap;
}
.ses-dev-ip { font-family: ui-monospace, monospace; }
.ses-dev-soon { color: #B4432E; font-weight: 600; }
.ses-dev-kill {
  flex-shrink: 0; padding: 5px 7px; border-radius: 6px;
  border: 1px solid var(--border-light); background: none;
  cursor: pointer; color: var(--text-muted); transition: all .15s;
}
.ses-dev-kill:hover { background: #FFF0F0; border-color: #E57373; color: #D32F2F; }
.ses-dev-kill:disabled { opacity: .25; pointer-events: none; }

/* ═══ Бейджи ═══ */
.ses-tag {
  display: inline-block; padding: 1px 7px; border-radius: 5px;
  font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px;
}
.ses-tag-you { background: #E8F5E9; color: #2E7D32; }
.ses-tag-admin { background: #FFEBEE; color: #C62828; }
.ses-tag-pwa { background: #EDE7F6; color: #5E35B1; }
.ses-tag-soft { background: var(--bg); color: var(--text-muted); }

/* ═══ Телефон ═══ */
@media (max-width: 900px) {
  .ses-cards { grid-template-columns: repeat(2, 1fr); }
  .ses-now { grid-template-columns: 1fr; }
}
@media (max-width: 600px) {
  .ses-card-value { font-size: 22px; }
  .ses-toolbar { gap: 8px; }
  .ses-seg { width: 100%; }
  .ses-seg-btn { flex: 1; justify-content: center; }
  .ses-search { min-width: 0; width: 100%; }
  .ses-refresh { flex: 1; justify-content: center; }

  /* Имя занимает остаток первой строки рядом с аватаром, счётчик и кнопка
     уезжают на вторую — иначе имя переносится по одному слову в столбик. */
  .ses-group-head { flex-wrap: wrap; gap: 8px 10px; padding: 10px 12px; }
  .ses-group-info { flex: 1 1 calc(100% - 52px); }
  .ses-group-count { order: 3; }
  .ses-group-kill { order: 4; margin-left: auto; }

  /* Строка устройства становится карточкой: подписи уходят под название */
  .ses-device { align-items: flex-start; flex-wrap: wrap; padding: 10px 12px; }
  .ses-dev-main { width: calc(100% - 40px); }
  .ses-dev-meta {
    width: 100%; flex-wrap: wrap; gap: 4px 12px; white-space: normal; margin-top: 2px;
  }
  .ses-dev-kill { position: absolute; right: 12px; }
  .ses-device { position: relative; }
}
</style>
