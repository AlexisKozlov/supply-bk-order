<template>
  <div class="arat">

    <!-- Сводка: сразу видно, кто не сможет войти и кто пользуется приложением -->
    <div class="arat-cards">
      <div class="arat-card">
        <div class="arat-card-label">Кабинетов</div>
        <div class="arat-card-value">{{ usersList.length }}</div>
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
        <div class="arat-card-label">Приложение</div>
        <div class="arat-card-value">{{ withPwaCount }}</div>
        <div class="arat-card-sub">{{ withPushCount }} включили уведомления</div>
      </div>
    </div>

    <!-- Массовая выдача пароля -->
    <div class="arat-section">
      <div class="arat-section-title">Массовая выдача пароля</div>
      <div class="arat-row">
        <div class="arat-pwd">
          <input v-model="bulkPassword" :type="showBulkPassword ? 'text' : 'password'"
                 placeholder="Пароль (мин 8 символов)" class="arat-input" autocomplete="new-password" />
          <button class="arat-pwd-eye" type="button" @click="showBulkPassword = !showBulkPassword"
                  :title="showBulkPassword ? 'Скрыть пароль' : 'Показать пароль'">
            <BkIcon :name="showBulkPassword ? 'eyeOff' : 'eye'" size="sm"/>
          </button>
        </div>
        <select v-model="bulkMode" class="arat-select">
          <option value="missing">Только тем, у кого нет пароля</option>
          <option value="all">Всем (затереть существующие)</option>
        </select>
        <button class="btn primary" @click="handleBulkCreate" :disabled="!bulkPassword || busy">
          Применить
        </button>
      </div>
      <div v-if="bulkResult !== null" class="arat-hint">Обновлено учёток: {{ bulkResult }}</div>
    </div>

    <!-- Список -->
    <div class="arat-section">
      <div class="arat-section-title">
        Учётные записи
        <button class="btn" @click="reloadUsers" :disabled="busy">Обновить</button>
      </div>

      <div class="arat-filters">
        <input v-model="filter" type="text" placeholder="Поиск по номеру, городу, адресу или email" class="arat-input" />
        <select v-model="filterStatus" class="arat-select">
          <option value="">Все статусы</option>
          <option value="ready">С паролем, активные</option>
          <option value="nopwd">Без пароля</option>
          <option value="disabled">Отключённые</option>
          <option value="email-none">Без email</option>
          <option value="email-pending">Email не подтверждён</option>
          <option value="email-ok">Email подтверждён</option>
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
              <th class="arat-col-app">Приложение</th>
              <th class="arat-col-meta">Последний вход</th>
              <th class="arat-col-actions">Действия</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in filteredUsers" :key="(u.legal_entity_group || 'BK_VM') + '-' + u.restaurant_number">
              <td class="arat-col-num" data-label="Ресторан">№{{ formatRestaurantNumber(u.restaurant_number, u.legal_entity_group) }}</td>
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
              <td class="arat-col-app" data-label="Приложение">
                <span v-if="u.has_pwa" class="arat-badge app" :title="'Открывали с иконки: ' + formatTime(u.pwa_last_seen_at)">на телефоне</span>
                <span v-else class="arat-muted">—</span>
                <span v-if="u.push_devices" class="arat-push-dot" :title="u.push_devices + ' устройств(а) с уведомлениями'"><BkIcon name="bell" size="sm" />{{ u.push_devices > 1 ? u.push_devices : '' }}</span>
              </td>
              <td class="arat-col-meta" data-label="Последний вход" :title="lastLoginTitle(u)">
                <span v-if="u.last_login_at">{{ formatRelative(u.last_login_at) }}</span>
                <span v-else class="arat-muted">ни разу</span>
              </td>
              <td class="arat-col-actions">
                <div class="arat-actions">
                  <button class="btn" @click="handleSetEmail(u)" :disabled="busy" :title="u.email ? 'Изменить email' : 'Указать email'">
                    Email
                  </button>
                  <button class="btn" @click="handleSetPassword(u)" :disabled="busy" :title="u.has_password ? 'Сменить пароль' : 'Задать пароль'">
                    Пароль
                  </button>
                  <button
                    v-if="u.has_password"
                    class="btn"
                    :class="u.is_active ? 'arat-btn-danger' : 'arat-btn-success'"
                    @click="handleToggleUser(u)"
                    :disabled="busy"
                    :title="u.is_active ? 'Отключить учётку' : 'Включить учётку'"
                  >
                    {{ u.is_active ? 'Откл.' : 'Вкл.' }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import BkIcon from '@/components/ui/BkIcon.vue';
import { useRestaurantOrderStore } from '@/stores/restaurantOrderStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { formatRestaurantNumber } from '@/lib/legalEntities.js';
import { formatMoscowDateTime, formatMoscowRelative, plural } from '@/lib/utils.js';
import { appConfirm, appPrompt } from '@/lib/appDialogs.js';

const store = useRestaurantOrderStore();
const toast = useToastStore();

const usersList = ref([]);
const loading = ref(false);
const busy = ref(false);

const bulkPassword = ref('');
const bulkMode = ref('missing');
const bulkResult = ref(null);
const showBulkPassword = ref(false);

const filter = ref('');
const filterStatus = ref('');

const withPasswordCount = computed(() => usersList.value.filter(u => u.has_password).length);
const withoutPasswordCount = computed(() => usersList.value.filter(u => !u.has_password).length);
const disabledCount = computed(() => usersList.value.filter(u => u.has_password && !u.is_active).length);
const withEmailCount = computed(() => usersList.value.filter(u => !!u.email).length);
const verifiedEmailCount = computed(() => usersList.value.filter(u => !!u.email_verified_at).length);
// Кабинет открывали с иконки на телефоне (установленное приложение).
// Кабинет без пароля или с закрытым доступом — человек просто не войдёт.
const cantLoginCount = computed(() => usersList.value.filter(u => !u.has_password || !u.is_active).length);
const withPwaCount = computed(() => usersList.value.filter(u => u.has_pwa).length);
const withPushCount = computed(() => usersList.value.filter(u => u.push_devices > 0).length);

const filteredUsers = computed(() => {
  const q = (filter.value || '').toLowerCase().trim();
  const st = filterStatus.value;
  return usersList.value.filter(u => {
    if (st === 'ready' && !(u.has_password && u.is_active)) return false;
    if (st === 'nopwd' && u.has_password) return false;
    if (st === 'disabled' && !(u.has_password && !u.is_active)) return false;
    if (st === 'email-none' && u.email) return false;
    if (st === 'email-pending' && (!u.email || u.email_verified_at)) return false;
    if (st === 'email-ok' && !u.email_verified_at) return false;
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
const formatRelative = formatMoscowRelative;

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

async function handleBulkCreate() {
  if ((bulkPassword.value || '').length < 8) {
    toast.error('Слишком короткий пароль', 'Минимум 8 символов');
    return;
  }
  if (bulkMode.value === 'all') {
    if (!(await appConfirm('Затереть пароли ВСЕМ ресторанам? Все активные сессии будут продолжать работать со старыми паролями (пока не выйдут), но войти заново можно будет только с новым паролем.', { title: 'Сброс паролей всем', okText: 'Затереть', danger: true }))) return;
  } else {
    // Режим «только без пароля» тоже задевает десятки учёток разом — спрашиваем.
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
    const result = await store.adminCreateBulkUsers(bulkPassword.value, bulkMode.value);
    bulkResult.value = result?.count ?? 0;
    bulkPassword.value = '';
    await reloadUsers();
    toast.success('Готово', `Обновлено учёток: ${result?.count ?? 0}`);
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

async function handleSetPassword(u) {
  const verb = u.has_password ? 'Новый пароль' : 'Задайте пароль';
  const label = formatRestaurantNumber(u.restaurant_number, u.legal_entity_group);
  const pass = await appPrompt('Минимум 8 символов', '', { title: `${verb} для ресторана ${label}`, okText: 'Сохранить' });
  if (!pass) return;
  if (pass.length < 8) {
    toast.error('Слишком короткий пароль', 'Минимум 8 символов');
    return;
  }
  busy.value = true;
  try {
    await store.adminCreateUser(u.restaurant_number, u.legal_entity_group, pass);
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

.arat-col-num     { width: 80px; font-weight: 700; color: #502314; white-space: nowrap; }
.arat-col-rest    { min-width: 180px; }
.arat-col-status  { width: 110px; }
.arat-col-email   { min-width: 170px; max-width: 230px; }
.arat-col-meta    { width: 120px; font-size: 12px; color: #6f5948; }
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

/* Красим только смысл: «отключить» — красным, «включить» — зелёным.
   Форма кнопки — общая .btn портала. */
.arat-btn-danger { color: #C62828; border-color: #E57373; }
.arat-btn-danger:hover:not(:disabled) { background: #FFF0F0; }
.arat-btn-ok { color: #2E7D32; border-color: #A5D6A7; }
.arat-btn-ok:hover:not(:disabled) { background: #F1F8F2; }

.arat-col-app { white-space: nowrap; }
.arat-badge.app { background: #FDEBD9; color: #C1502E; }
.arat-push-dot { margin-left: 6px; font-size: 12px; }

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
  .arat-col-num { font-size: 14px; }
  .arat-col-actions { padding-top: 8px; }
  .arat-actions { flex-wrap: wrap; }
  .arat-actions .btn { flex: 1; min-width: 92px; }

  .arat-row { flex-direction: column; align-items: stretch; }
  .arat-pwd { min-width: 0; }
  .arat-filters { flex-direction: column; }
  .arat-input, .arat-select { min-width: 0; width: 100%; }
}
</style>
