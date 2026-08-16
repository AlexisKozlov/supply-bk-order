<template>
  <div class="arat">

    <!-- Сводка: сразу видно, кто не сможет войти и кто пользуется приложением -->
    <div class="arat-cards">
      <div class="arat-card">
        <div class="arat-card-label">Кабинетов</div>
        <div class="arat-card-value">{{ scopedUsers.length }}</div>
        <div class="arat-card-sub">{{ withPasswordCount }} с паролем</div>
      </div>
      <div class="arat-card" :class="{ warn: cantLoginCount > 0 }">
        <div class="arat-card-label">Не смогут войти</div>
        <div class="arat-card-value">{{ cantLoginCount }}</div>
        <div class="arat-card-sub">нет пароля или доступ закрыт</div>
      </div>
      <div class="arat-card">
        <div class="arat-card-label">Почта</div>
        <div class="arat-card-value">{{ verifiedEmailCount }}</div>
        <div class="arat-card-sub">подтверждена · всего с почтой {{ withEmailCount }}</div>
      </div>
      <div class="arat-card">
        <div class="arat-card-label">Связь</div>
        <div class="arat-card-value">{{ withPwaCount }}</div>
        <div class="arat-card-sub">
          на телефоне · {{ withPushCount }} с уведомлениями · {{ withTelegramCount }} с Telegram<template v-if="tgBlockedCount">, из них {{ tgBlockedCount }} заблокировали бота</template>
        </div>
      </div>
    </div>

    <!-- Список -->
    <div class="arat-section">
      <div class="arat-section-title">
        Учётные записи
        <div class="arat-title-actions">
          <button class="btn" @click="reloadUsers" :disabled="busy">Обновить</button>
          <button class="btn" @click="openBulkModal" :disabled="busy">Выдать пароль сразу многим</button>
        </div>
      </div>

      <div class="arat-filters">
        <input v-model="filter" type="text" placeholder="Поиск по номеру, городу, адресу или email" class="arat-input" />
        <select v-model="filterEntity" class="arat-select">
          <option value="">Все компании</option>
          <option v-for="e in entityOptions" :key="e.value" :value="e.value">{{ e.label }}</option>
        </select>
        <select v-model="filterStatus" class="arat-select">
          <option value="">Все статусы</option>
          <option value="ready">С паролем, активные</option>
          <option value="nopwd">Без пароля</option>
          <option value="disabled">Отключённые</option>
          <option value="email-none">Без email</option>
          <option value="email-pending">Email не подтверждён</option>
          <option value="email-ok">Email подтверждён</option>
          <option value="tg">С Telegram</option>
          <option value="no-tg">Без Telegram</option>
          <option value="tg-blocked">Заблокировали бота</option>
          <option value="pwa">Поставили приложение</option>
          <option value="no-pwa">Без приложения</option>
          <option value="push">С уведомлениями</option>
        </select>
      </div>

      <div v-if="loading" class="arat-empty">Загрузка...</div>
      <div v-else-if="!filteredUsers.length" class="arat-empty">Ничего не найдено</div>
      <div v-else class="arat-table-wrap">
        <table class="arat-table">
          <thead>
            <tr>
              <th class="arat-col-num">№</th>
              <th class="arat-col-rest">Ресторан</th>
              <th class="arat-col-status">Статус</th>
              <th class="arat-col-email">Email</th>
              <th class="arat-col-app" title="Приложение на телефоне, уведомления, Telegram">Связь</th>
              <th class="arat-col-meta">Вход и пароль</th>
              <th class="arat-col-actions">Действия</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in filteredUsers" :key="(u.legal_entity_group || 'BK_VM') + '-' + u.restaurant_number">
              <td class="arat-col-num" data-label="Ресторан">{{ restaurantLabel(u) }}</td>
              <td class="arat-col-rest">
                <span class="arat-rest-addr">{{ placeLabel(u.city, u.address) || '—' }}</span>
                <span class="arat-rest-le" v-if="u.legal_entity"> · {{ shortLegalEntity(u.legal_entity) }}</span>
              </td>
              <td class="arat-col-status" data-label="Статус">
                <span class="arat-badge" :class="statusBadgeClass(u)">{{ statusLabel(u) }}</span>
              </td>
              <td class="arat-col-email" data-label="Email">
                <template v-if="u.email">
                  <span class="arat-email-addr">{{ u.email }}</span>
                  <span
                    class="arat-email-dot"
                    :class="u.email_verified_at ? 'ok' : 'warn'"
                    :title="u.email_verified_at ? 'Email подтверждён' : 'Email не подтверждён'"
                  >{{ u.email_verified_at ? '✓' : '!' }}</span>
                </template>
                <span v-else class="arat-email-empty">не указан</span>
              </td>
              <td class="arat-col-app" data-label="Связь">
                <span v-if="u.has_pwa" class="arat-badge app" :title="'Приложение на телефоне. Последний раз открывали: ' + formatTime(u.pwa_last_seen_at)">телефон</span>
                <span v-if="u.push_devices" class="arat-push-dot" :title="u.push_devices + ' ' + plural(u.push_devices, 'устройство', 'устройства', 'устройств') + ' с уведомлениями'"><BkIcon name="bell" size="sm" />{{ u.push_devices > 1 ? u.push_devices : '' }}</span>
                <span v-if="u.tg_subs" class="arat-badge tg" :class="{ blocked: u.tg_blocked >= u.tg_subs }" :title="tgTitle(u)">
                  TG{{ u.tg_subs > 1 ? ' ' + u.tg_subs : '' }}
                </span>
                <span v-if="!u.has_pwa && !u.push_devices && !u.tg_subs" class="arat-muted">—</span>
              </td>
              <td class="arat-col-meta" data-label="Вход и пароль">
                <div class="arat-meta-line" :title="lastLoginTitle(u)">
                  <span v-if="u.last_login_at">{{ formatTime(u.last_login_at) }}</span>
                  <span v-else class="arat-muted">ни разу не входил</span>
                </div>
                <div class="arat-meta-sub" :title="u.password_changed_at ? 'Пароль в последний раз меняли ' + formatTime(u.password_changed_at) : ''">
                  <template v-if="u.password_changed_at">пароль {{ formatTime(u.password_changed_at) }}</template>
                  <template v-else-if="u.has_password">пароль выдан давно</template>
                  <template v-else>пароля нет</template>
                </div>
              </td>
              <td class="arat-col-actions">
                <div class="arat-actions">
                  <button class="arat-act" @click="handleSetEmail(u)" :disabled="busy" :title="u.email ? 'Изменить email' : 'Указать email'">
                    <BkIcon name="mail" size="sm" />
                  </button>
                  <button class="arat-act" @click="handleSetPassword(u)" :disabled="busy" :title="u.has_password ? 'Сменить пароль' : 'Задать пароль'">
                    <BkIcon name="key" size="sm" />
                  </button>
                  <button
                    v-if="u.has_password"
                    class="arat-act"
                    :class="u.is_active ? 'arat-act-danger' : 'arat-act-success'"
                    @click="handleToggleUser(u)"
                    :disabled="busy"
                    :title="u.is_active ? 'Закрыть доступ ресторану' : 'Открыть доступ ресторану'"
                  >
                    <BkIcon :name="u.is_active ? 'eyeOff' : 'eye'" size="sm" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <RestaurantPasswordModal
      v-if="pwdModal.show"
      :title="pwdModal.title"
      :message="pwdModal.message"
      :bulk="pwdModal.bulk"
      :missing-count="pwdModal.missingCount"
      :total-count="usersList.length"
      @ok="onPasswordOk"
      @cancel="pwdModal.show = false"
    />

  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import BkIcon from '@/components/ui/BkIcon.vue';
import { useRestaurantOrderStore } from '@/stores/restaurantOrderStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { formatRestaurantNumber } from '@/lib/legalEntities.js';
import { formatMoscowDateTime, plural } from '@/lib/utils.js';
import { appConfirm, appPrompt } from '@/lib/appDialogs.js';
import RestaurantPasswordModal from '@/components/modals/RestaurantPasswordModal.vue';

const store = useRestaurantOrderStore();
const toast = useToastStore();

const usersList = ref([]);
const loading = ref(false);
const busy = ref(false);

const filter = ref('');
const filterStatus = ref('');
const filterEntity = ref('');

// Окно пароля: одно и то же и для одного ресторана, и для массовой выдачи.
const pwdModal = ref({ show: false, title: '', message: '', bulk: false, missingCount: null, user: null });

const entityOptions = computed(() => {
  const seen = new Map();
  for (const u of usersList.value) {
    if (u.legal_entity && !seen.has(u.legal_entity)) seen.set(u.legal_entity, shortLegalEntity(u.legal_entity));
  }
  return Array.from(seen, ([value, label]) => ({ value, label }));
});

// Сводка считается по выбранной компании: иначе выбираешь «Пицца Стар»,
// а наверху всё равно цифры по всем 110 кабинетам сразу.
const scopedUsers = computed(() =>
  filterEntity.value ? usersList.value.filter(u => u.legal_entity === filterEntity.value) : usersList.value,
);
const withPasswordCount = computed(() => scopedUsers.value.filter(u => u.has_password).length);
const withoutPasswordCount = computed(() => scopedUsers.value.filter(u => !u.has_password).length);
const disabledCount = computed(() => scopedUsers.value.filter(u => u.has_password && !u.is_active).length);
const withEmailCount = computed(() => scopedUsers.value.filter(u => !!u.email).length);
const verifiedEmailCount = computed(() => scopedUsers.value.filter(u => !!u.email_verified_at).length);
// Кабинет открывали с иконки на телефоне (установленное приложение).
// Кабинет без пароля или с закрытым доступом — человек просто не войдёт.
const cantLoginCount = computed(() => scopedUsers.value.filter(u => !u.has_password || !u.is_active).length);
const withPwaCount = computed(() => scopedUsers.value.filter(u => u.has_pwa).length);
const withPushCount = computed(() => scopedUsers.value.filter(u => u.push_devices > 0).length);
const withTelegramCount = computed(() => scopedUsers.value.filter(u => u.tg_subs > 0).length);
const tgBlockedCount = computed(() => scopedUsers.value.filter(u => u.tg_blocked > 0).length);

const filteredUsers = computed(() => {
  const q = (filter.value || '').toLowerCase().trim();
  const st = filterStatus.value;
  const le = filterEntity.value;
  return usersList.value.filter(u => {
    if (le && u.legal_entity !== le) return false;
    if (st === 'ready' && !(u.has_password && u.is_active)) return false;
    if (st === 'nopwd' && u.has_password) return false;
    if (st === 'disabled' && !(u.has_password && !u.is_active)) return false;
    if (st === 'email-none' && u.email) return false;
    if (st === 'email-pending' && (!u.email || u.email_verified_at)) return false;
    if (st === 'email-ok' && !u.email_verified_at) return false;
    if (st === 'tg' && !u.tg_subs) return false;
    if (st === 'no-tg' && u.tg_subs) return false;
    if (st === 'tg-blocked' && !(u.tg_blocked > 0)) return false;
    if (st === 'pwa' && !u.has_pwa) return false;
    if (st === 'no-pwa' && u.has_pwa) return false;
    if (st === 'push' && !(u.push_devices > 0)) return false;
    if (!q) return true;
    const num = String(u.restaurant_number || '');
    const formattedNum = formatRestaurantNumber(u.restaurant_number, u.legal_entity_group) || '';
    const haystack = [num, formattedNum, u.city, u.address, u.legal_entity, u.email].filter(Boolean).join(' ').toLowerCase();
    return haystack.includes(q);
  });
});

// Номер ресторана как его называют люди: 1, 2… у БК и PS01, PS02… у Пицца Стар.
// Раньше к готовой подписи добавляли «№» и выходило «№PS01».
function restaurantLabel(u) {
  const n = formatRestaurantNumber(u.restaurant_number, u.legal_entity_group);
  return /^\d+$/.test(String(n)) ? '№' + n : String(n);
}

function openBulkModal() {
  pwdModal.value = {
    show: true,
    bulk: true,
    user: null,
    missingCount: withoutPasswordCount.value,
    title: 'Выдать пароль сразу многим',
    message: 'Один и тот же пароль будет выдан выбранным кабинетам.',
  };
}

async function onPasswordOk({ password, mode }) {
  const target = pwdModal.value;
  pwdModal.value = { ...target, show: false };
  if (target.bulk) await runBulkCreate(password, mode);
  else await runSetPassword(target.user, password);
}

// Подсказка к значку Telegram: сколько человек привязано и не заблокировали ли бота.
function tgTitle(u) {
  const n = u.tg_subs || 0;
  const parts = [`${n} ${plural(n, 'человек привязан', 'человека привязано', 'человек привязано')} к Telegram`];
  if (u.tg_blocked) parts.push(`${u.tg_blocked} ${plural(u.tg_blocked, 'заблокировал', 'заблокировали', 'заблокировали')} бота — уведомления им не дойдут`);
  return parts.join('\n');
}

function statusBadgeClass(u) {
  if (u.has_password && u.is_active) return 'ok';
  if (!u.has_password) return 'warn';
  return 'off';
}
function statusLabel(u) {
  if (u.has_password && u.is_active) return 'Активен';
  if (!u.has_password) return 'Без пароля';
  return 'Отключён';
}

// Адрес в базе часто уже начинается с города — не повторяем его дважды.
function placeLabel(city, address) {
  const c = String(city || '').trim();
  const a = String(address || '').trim();
  if (!a) return c;
  if (c && a.toLowerCase().includes(c.toLowerCase())) return a;
  return c ? c + ' ' + a : a;
}

function shortLegalEntity(le) {
  if (!le) return '';
  return le.replace(/^ООО\s*["«]?/, '').replace(/["»]?$/, '');
}

// Время с сервера — московское; formatMoscowDateTime это учитывает,
// прежняя локальная копия читала его как время браузера.
const formatTime = formatMoscowDateTime;

// Точные даты прячем в подсказку — в таблице они занимали половину ширины.
function lastLoginTitle(u) {
  const parts = [];
  if (u.last_login_at) parts.push('Последний вход: ' + formatMoscowDateTime(u.last_login_at));
  if (u.password_changed_at) parts.push('Пароль менялся: ' + formatMoscowDateTime(u.password_changed_at));
  return parts.join('\n');
}

onMounted(() => reloadUsers());

async function reloadUsers() {
  loading.value = true;
  try {
    usersList.value = await store.adminGetUsers();
  } catch (e) {
    toast.error('Ошибка', e.message || 'Не удалось загрузить учётки');
  } finally {
    loading.value = false;
  }
}

async function runBulkCreate(password, mode) {
  if (mode === 'all') {
    if (!(await appConfirm('Пароль сменится у всех кабинетов. Кто уже вошёл — продолжит работать до выхода, но заново войдёт только с новым паролем.', { title: 'Сменить пароль всем', okText: 'Сменить', danger: true }))) return;
  } else {
    const n = withoutPasswordCount.value;
    if (!n) {
      toast.error('Некому выдавать', 'У всех ресторанов уже есть пароль');
      return;
    }
    const word = plural(n, 'ресторану', 'ресторанам', 'ресторанам');
    if (!(await appConfirm(`Пароль будет выдан ${n} ${word}, у которых его сейчас нет.`, { title: 'Выдать пароль', okText: 'Выдать' }))) return;
  }
  busy.value = true;
  try {
    const result = await store.adminCreateBulkUsers(password, mode);
    await reloadUsers();
    const n = result?.count ?? 0;
    toast.success('Готово', `Пароль выдан ${n} ${plural(n, 'кабинету', 'кабинетам', 'кабинетам')}`);
  } catch (e) {
    toast.error('Ошибка', e.message);
  } finally {
    busy.value = false;
  }
}

async function handleSetEmail(u) {
  const label = formatRestaurantNumber(u.restaurant_number, u.legal_entity_group);
  const current = u.email || '';
  const value = await appPrompt(`Только рабочий: @burger-king.by или @dodopizza.by.\nПосле сохранения на этот адрес уйдёт письмо для подтверждения. Чтобы очистить email — оставьте поле пустым.`, current, { title: `Email для ресторана ${label}`, okText: 'Сохранить' });
  if (value === null) return;
  const trimmed = String(value).trim();
  if (trimmed && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(trimmed)) {
    toast.error('Похоже, email указан с ошибкой', '');
    return;
  }
  if (trimmed && !/@(burger-king\.by|dodopizza\.by)$/i.test(trimmed)) {
    toast.error('Можно указать только рабочий email', 'Принимаем @burger-king.by или @dodopizza.by');
    return;
  }
  busy.value = true;
  try {
    const result = await store.adminSetUserEmail(u.restaurant_number, u.legal_entity_group, trimmed);
    if (result?.cleared) {
      toast.success('Готово', `Email ресторана ${label} удалён`);
    } else {
      toast.success('Готово', `Email сохранён. На ${trimmed} отправлено письмо для подтверждения.`);
    }
    await reloadUsers();
  } catch (e) {
    toast.error('Ошибка', e.message);
  } finally {
    busy.value = false;
  }
}

function handleSetPassword(u) {
  const label = formatRestaurantNumber(u.restaurant_number, u.legal_entity_group);
  pwdModal.value = {
    show: true,
    bulk: false,
    user: u,
    missingCount: null,
    title: `${u.has_password ? 'Новый пароль' : 'Пароль'} для ресторана ${label}`,
    message: u.has_password
      ? 'Старый пароль перестанет работать. Не забудьте передать новый ресторану.'
      : 'У этого кабинета пароля ещё нет.',
  };
}

async function runSetPassword(u, password) {
  if (!u) return;
  const label = formatRestaurantNumber(u.restaurant_number, u.legal_entity_group);
  busy.value = true;
  try {
    await store.adminCreateUser(u.restaurant_number, u.legal_entity_group, password);
    toast.success('Готово', `Пароль ресторана ${label} сохранён`);
    await reloadUsers();
  } catch (e) {
    toast.error('Ошибка', e.message);
  } finally {
    busy.value = false;
  }
}

async function handleToggleUser(u) {
  const next = u.is_active ? 0 : 1;
  const label = formatRestaurantNumber(u.restaurant_number, u.legal_entity_group);
  const msg = u.is_active
    ? `Отключить ресторан ${label}? Он не сможет войти в кабинет и выпадет из заявок поставщикам, расписаний и напоминаний. Включение всё вернёт.`
    : `Включить ресторан ${label}?`;
  if (!(await appConfirm(msg, { okText: u.is_active ? 'Отключить' : 'Включить', danger: !!u.is_active }))) return;
  busy.value = true;
  try {
    await store.adminToggleUser(u.restaurant_number, u.legal_entity_group, next);
    toast.success('Готово', `Ресторан ${label} ${u.is_active ? 'отключён' : 'включён'}`);
    await reloadUsers();
  } catch (e) {
    toast.error('Ошибка', e.message);
  } finally {
    busy.value = false;
  }
}
</script>

<style scoped>
.arat { padding: 0; }

/* ═══ Сводка ═══ */
.arat-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 12px; }
.arat-card {
  background: var(--card); border: 1px solid var(--border-light);
  border-radius: 12px; padding: 12px 14px;
}
.arat-card.warn { border-color: #FFE0B2; background: linear-gradient(180deg, #FFF8EC, var(--card)); }
.arat-card-label { font-size: 11px; text-transform: uppercase; letter-spacing: .3px; color: var(--text-muted); font-weight: 600; }
.arat-card-value { font-size: 24px; font-weight: 800; color: var(--text); line-height: 1.2; margin-top: 2px; }
.arat-card-sub { font-size: 11.5px; color: var(--text-muted); }

.arat-section {
  background: white;
  border: 1px solid #e8e0d6;
  border-radius: 12px;
  padding: 16px;
  margin-bottom: 16px;
}

.arat-section-title {
  display: flex;
  align-items: center;
  font-weight: 700;
  font-size: 14px;
  color: #502314;
  margin-bottom: 12px;
}

.arat-row {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  align-items: center;
}

.arat-filters {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}

.arat-input,
.arat-select {
  padding: 9px 12px;
  border: 1.5px solid #e8e0d6;
  border-radius: 8px;
  font-size: 14px;
  font-family: inherit;
  background: white;
  flex: 1;
  min-width: 180px;
  box-sizing: border-box;
}
.arat-input:focus,
.arat-select:focus {
  outline: none;
  border-color: #E76F51;
}

/* Поле пароля с кнопкой «показать» — пароль не должен висеть на экране. */
.arat-pwd { position: relative; display: flex; flex: 1; min-width: 180px; }
.arat-pwd .arat-input { flex: 1; padding-right: 38px; }
.arat-pwd-eye {
  position: absolute; right: 6px; top: 50%; transform: translateY(-50%);
  border: none; background: none; cursor: pointer; padding: 4px;
  color: var(--text-muted, #8a7f75); border-radius: 6px; line-height: 0;
}
.arat-pwd-eye:hover { color: var(--text, #33291f); background: rgba(0, 0, 0, .05); }

.arat-hint {
  margin-top: 8px;
  color: #8b7355;
  font-size: 12px;
}

.arat-empty {
  padding: 30px;
  text-align: center;
  color: #8b7355;
  font-size: 13px;
}

.arat-table-wrap {
  overflow-x: auto;
  border: 1px solid #e8e0d6;
  border-radius: 10px;
}

.arat-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.arat-table th,
.arat-table td {
  padding: 5px 10px;
  text-align: left;
  vertical-align: middle;
  border-bottom: 1px solid #f0ebe4;
  line-height: 1.3;
  font-size: 12.5px;
  white-space: nowrap;
}
.arat-col-rest, .arat-col-email { white-space: normal; }
.arat-table tbody tr:last-child td { border-bottom: none; }
.arat-table thead th {
  background: #faf8f5;
  color: #502314;
  font-weight: 700;
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  white-space: nowrap;
  border-bottom: 2px solid #e8e0d6;
}
.arat-table tbody tr:hover { background: #fcfaf6; }

.arat-col-num     { width: 66px; font-weight: 700; color: #502314; white-space: nowrap; }
/* Адрес переносим по строкам: без потолка колонка раздувалась до 441 px
   и вытесняла кнопки действий за край экрана. */
.arat-col-rest    { min-width: 170px; max-width: 300px; }
.arat-col-status  { width: 98px; }
.arat-col-email   { min-width: 150px; max-width: 190px; overflow-wrap: anywhere; }
.arat-col-meta    { width: 150px; font-size: 12px; color: #6f5948; }
.arat-col-actions { width: 1%; white-space: nowrap; }

.arat-rest-addr { color: #502314; }
.arat-rest-le { color: #a08570; font-size: 11.5px; }

.arat-email-addr { color: #502314; word-break: break-all; margin-right: 6px; }
.arat-email-empty { color: #c4b8a8; font-style: italic; }
.arat-email-dot {
  display: inline-block;
  width: 18px;
  height: 18px;
  line-height: 18px;
  text-align: center;
  border-radius: 50%;
  font-size: 11px;
  font-weight: 700;
  vertical-align: middle;
}
.arat-email-dot.ok   { background: #ecfdf5; color: #16a34a; }
.arat-email-dot.warn { background: #fef3c7; color: #b45309; }

.arat-muted { color: #a08570; }

.arat-badge {
  display: inline-block;
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 10px;
  font-weight: 600;
  white-space: nowrap;
}
.arat-badge.ok   { background: #ecfdf5; color: #16a34a; }
.arat-badge.warn { background: #fef3c7; color: #b45309; }
.arat-badge.off  { background: #fef2f2; color: #b91c1c; }

.arat-actions {
  display: flex;
  flex-wrap: nowrap;
  gap: 4px;
  justify-content: flex-end;
}

/* Кнопки действий — иконками. Тремя текстовыми кнопками таблица разъезжалась
   до 1408 px при окне 1152 px, и колонка «Действия» уезжала за край экрана:
   чтобы сменить пароль, приходилось листать таблицу вбок. */
.arat-act {
  width: 30px; height: 30px; padding: 0;
  display: inline-flex; align-items: center; justify-content: center;
  border: 1.5px solid var(--border); border-radius: 8px;
  background: var(--card); color: var(--text-muted);
  cursor: pointer; transition: all .15s;
}
.arat-act:hover:not(:disabled) { border-color: var(--bk-orange); color: var(--bk-brown); background: #FFFBF5; }
.arat-act:disabled { opacity: .45; cursor: default; }
.arat-act-danger { color: #C62828; border-color: #E57373; }
.arat-act-danger:hover:not(:disabled) { background: #FFF0F0; border-color: #C62828; color: #C62828; }
.arat-act-success { color: #2E7D32; border-color: #A5D6A7; }
.arat-act-success:hover:not(:disabled) { background: #F1F8F2; border-color: #2E7D32; color: #2E7D32; }

.arat-col-app { white-space: nowrap; }
.arat-badge.app { background: #FDEBD9; color: #C1502E; }
.arat-badge.tg  { background: #E3F2FD; color: #1565C0; margin-left: 4px; }
.arat-badge.tg.blocked { background: #fef2f2; color: #b91c1c; }
.arat-push-dot { margin-left: 6px; font-size: 12px; }

.arat-meta-line { white-space: nowrap; }
.arat-meta-sub { font-size: 11px; opacity: .75; white-space: nowrap; margin-top: 2px; }

.arat-title-actions { display: flex; gap: 8px; flex-wrap: wrap; }

/* ═══ Телефон: таблица на семь колонок читается только карточками ═══ */
@media (max-width: 760px) {
  .arat-cards { grid-template-columns: repeat(2, 1fr); }

  .arat-table-wrap { border: none; border-radius: 0; overflow: visible; }
  .arat-table, .arat-table tbody, .arat-table tr, .arat-table td { display: block; width: 100%; }
  .arat-table thead { display: none; }

  .arat-table tbody tr {
    border: 1px solid #e8e0d6; border-radius: 10px;
    padding: 10px 12px; margin-bottom: 8px; background: #fff;
  }
  .arat-table tbody tr:hover { background: #fff; }

  .arat-table td {
    border: none; padding: 3px 0; white-space: normal;
  }
  /* Подпись слева, значение справа одной колонкой: при flex несколько
     блоков внутри ячейки вставали в строку и текст рвался по слогам. */
  .arat-table td[data-label] {
    display: grid; grid-template-columns: 96px minmax(0, 1fr);
    gap: 4px 8px; align-items: baseline;
  }
  .arat-table td[data-label]::before {
    content: attr(data-label); grid-column: 1;
    font-size: 11px; text-transform: uppercase; letter-spacing: .3px;
    color: #a08570; font-weight: 600;
  }
  .arat-table td[data-label] > * { grid-column: 2; min-width: 0; }
  /* Ширины колонок нужны только настоящей таблице — в карточках распирают. */
  .arat-col-rest, .arat-col-email, .arat-col-meta, .arat-col-num { min-width: 0; width: auto; max-width: none; }
  .arat-col-email { overflow-wrap: break-word; }
  .arat-col-num { font-size: 14px; }
  .arat-col-actions { padding-top: 8px; }
  .arat-actions { flex-wrap: wrap; }
  .arat-actions .arat-act { width: 36px; height: 36px; }

  .arat-row { flex-direction: column; align-items: stretch; }
  .arat-pwd { min-width: 0; }
  .arat-filters { flex-direction: column; }
  .arat-input, .arat-select { min-width: 0; width: 100%; }
}
</style>
