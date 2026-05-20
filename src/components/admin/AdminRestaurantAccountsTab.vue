<template>
  <div class="arat">

    <!-- Сводка -->
    <div class="arat-summary">
      <div class="arat-summary-item">
        <span class="arat-num">{{ usersList.length }}</span>
        <span class="arat-label">всего</span>
      </div>
      <div class="arat-summary-item ok">
        <span class="arat-num">{{ withPasswordCount }}</span>
        <span class="arat-label">с паролем</span>
      </div>
      <div class="arat-summary-item warn">
        <span class="arat-num">{{ withoutPasswordCount }}</span>
        <span class="arat-label">без пароля</span>
      </div>
      <div class="arat-summary-item off">
        <span class="arat-num">{{ disabledCount }}</span>
        <span class="arat-label">отключено</span>
      </div>
      <div class="arat-summary-item info">
        <span class="arat-num">{{ withEmailCount }}</span>
        <span class="arat-label">с email</span>
      </div>
      <div class="arat-summary-item info">
        <span class="arat-num">{{ verifiedEmailCount }}</span>
        <span class="arat-label">email подтверждён</span>
      </div>
    </div>

    <!-- Массовая выдача пароля -->
    <div class="arat-section">
      <div class="arat-section-title">Массовая выдача пароля</div>
      <div class="arat-row">
        <input v-model="bulkPassword" type="text" placeholder="Пароль (мин 8 символов)" class="arat-input" />
        <select v-model="bulkMode" class="arat-select">
          <option value="missing">Только тем, у кого нет пароля</option>
          <option value="all">Всем (затереть существующие)</option>
        </select>
        <button class="arat-btn arat-btn-primary" @click="handleBulkCreate" :disabled="!bulkPassword || busy">
          Применить
        </button>
      </div>
      <div v-if="bulkResult !== null" class="arat-hint">Обновлено учёток: {{ bulkResult }}</div>
    </div>

    <!-- Список -->
    <div class="arat-section">
      <div class="arat-section-title">
        Учётные записи
        <button class="arat-btn arat-btn-sm" @click="reloadUsers" :disabled="busy">Обновить</button>
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
              <th class="arat-col-meta">Активность</th>
              <th class="arat-col-actions">Действия</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in filteredUsers" :key="(u.legal_entity_group || 'BK_VM') + '-' + u.restaurant_number">
              <td class="arat-col-num">№{{ formatRestaurantNumber(u.restaurant_number, u.legal_entity_group) }}</td>
              <td class="arat-col-rest">
                <div class="arat-rest-addr">{{ u.city || '—' }} {{ u.address || '' }}</div>
                <div class="arat-rest-le">{{ shortLegalEntity(u.legal_entity) }}</div>
              </td>
              <td class="arat-col-status">
                <span class="arat-badge" :class="statusBadgeClass(u)">{{ statusLabel(u) }}</span>
              </td>
              <td class="arat-col-email">
                <template v-if="u.email">
                  <div class="arat-email-addr">{{ u.email }}</div>
                  <span class="arat-badge" :class="u.email_verified_at ? 'ok' : 'warn'">
                    {{ u.email_verified_at ? '✓ подтверждён' : 'не подтверждён' }}
                  </span>
                </template>
                <span v-else class="arat-email-empty">не указан</span>
              </td>
              <td class="arat-col-meta">
                <div v-if="u.last_login_at">Вход: {{ formatTime(u.last_login_at) }}</div>
                <div v-else class="arat-muted">Вход: —</div>
                <div v-if="u.password_changed_at" class="arat-muted">
                  Пароль: {{ formatTime(u.password_changed_at) }}
                </div>
              </td>
              <td class="arat-col-actions">
                <div class="arat-actions">
                  <button class="arat-btn arat-btn-sm" @click="handleSetEmail(u)" :disabled="busy" :title="u.email ? 'Изменить email' : 'Указать email'">
                    {{ u.email ? 'Email' : 'Email…' }}
                  </button>
                  <button class="arat-btn arat-btn-sm" @click="handleSetPassword(u)" :disabled="busy" :title="u.has_password ? 'Сменить пароль' : 'Задать пароль'">
                    {{ u.has_password ? 'Пароль' : 'Пароль…' }}
                  </button>
                  <button
                    v-if="u.has_password"
                    class="arat-btn arat-btn-sm"
                    :class="u.is_active ? 'arat-btn-danger' : 'arat-btn-success'"
                    @click="handleToggleUser(u)"
                    :disabled="busy"
                  >
                    {{ u.is_active ? 'Отключить' : 'Включить' }}
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
import { useRestaurantOrderStore } from '@/stores/restaurantOrderStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { parseRestaurantInput } from '@/lib/legalEntities.js';

const store = useRestaurantOrderStore();
const toast = useToastStore();

const usersList = ref([]);
const loading = ref(false);
const busy = ref(false);

const bulkPassword = ref('');
const bulkMode = ref('missing');
const bulkResult = ref(null);

const filter = ref('');
const filterStatus = ref('');

const withPasswordCount = computed(() => usersList.value.filter(u => u.has_password).length);
const withoutPasswordCount = computed(() => usersList.value.filter(u => !u.has_password).length);
const disabledCount = computed(() => usersList.value.filter(u => u.has_password && !u.is_active).length);
const withEmailCount = computed(() => usersList.value.filter(u => !!u.email).length);
const verifiedEmailCount = computed(() => usersList.value.filter(u => !!u.email_verified_at).length);

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

function formatRestaurantNumber(num, group) {
  if (!num) return '';
  const n = Number(num);
  if (!Number.isFinite(n)) return String(num);
  if ((group === 'PS') || n >= 1000) {
    const last = n % 1000;
    return 'PS' + String(last).padStart(2, '0');
  }
  return String(n);
}

function shortLegalEntity(le) {
  if (!le) return '';
  return le.replace(/^ООО\s*["«]?/, '').replace(/["»]?$/, '');
}

function formatTime(ts) {
  if (!ts) return '';
  try {
    const d = new Date(ts.replace(' ', 'T'));
    return d.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' });
  } catch (e) { return ts; }
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
    if (!confirm('Затереть пароли ВСЕМ ресторанам? Все активные сессии будут продолжать работать со старыми паролями (пока не выйдут), но войти заново можно будет только с новым паролем.')) return;
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
  const value = prompt(`Email для ресторана ${label}:\n\nПосле сохранения на этот адрес уйдёт письмо для подтверждения. Чтобы очистить email — оставьте поле пустым.`, current);
  if (value === null) return;
  const trimmed = value.trim();
  if (trimmed && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(trimmed)) {
    toast.error('Похоже, email указан с ошибкой', '');
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
  const pass = prompt(`${verb} для ресторана ${label} (минимум 8 символов):`);
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
  if (!confirm(`${u.is_active ? 'Отключить' : 'Включить'} ресторан ${label}?`)) return;
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

.arat-summary {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 20px;
}

.arat-summary-item {
  flex: 1;
  min-width: 110px;
  background: white;
  border: 1px solid #e8e0d6;
  border-radius: 10px;
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.arat-summary-item.ok    { border-color: #bbf7d0; background: #f0fdf4; }
.arat-summary-item.warn  { border-color: #fde68a; background: #fffbeb; }
.arat-summary-item.off   { border-color: #fecaca; background: #fef2f2; }
.arat-summary-item.info  { border-color: #c7d2fe; background: #eef2ff; }

.arat-num { font-size: 22px; font-weight: 700; color: #502314; }
.arat-label { font-size: 12px; color: #8b7355; }

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
  padding: 10px 12px;
  text-align: left;
  vertical-align: top;
  border-bottom: 1px solid #f0ebe4;
}
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
.arat-col-rest    { min-width: 200px; }
.arat-col-status  { width: 110px; }
.arat-col-email   { min-width: 220px; max-width: 280px; }
.arat-col-meta    { width: 170px; font-size: 12px; color: #6f5948; }
.arat-col-actions { width: 1%; white-space: nowrap; }

.arat-rest-addr { color: #502314; line-height: 1.3; }
.arat-rest-le { color: #a08570; font-size: 11px; line-height: 1.3; margin-top: 2px; }

.arat-email-addr { color: #502314; word-break: break-all; line-height: 1.3; margin-bottom: 3px; }
.arat-email-empty { color: #c4b8a8; font-style: italic; }

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
  flex-wrap: wrap;
  gap: 6px;
  justify-content: flex-end;
}

.arat-btn {
  padding: 7px 12px;
  font-size: 13px;
  font-weight: 600;
  font-family: inherit;
  border: 1.5px solid #e8e0d6;
  background: white;
  color: #502314;
  border-radius: 7px;
  cursor: pointer;
  transition: all 0.15s;
  white-space: nowrap;
}
.arat-btn:hover:not(:disabled) { background: #faf6ef; border-color: #d4c4ad; }
.arat-btn:disabled { opacity: 0.5; cursor: not-allowed; }

.arat-btn-sm { padding: 6px 10px; font-size: 12px; }

.arat-btn-primary {
  background: #E76F51;
  color: white;
  border-color: #E76F51;
}
.arat-btn-primary:hover:not(:disabled) {
  background: #b81e00;
  border-color: #b81e00;
}

.arat-btn-success {
  color: #16a34a;
  border-color: #bbf7d0;
}
.arat-btn-success:hover:not(:disabled) { background: #f0fdf4; }

.arat-btn-danger {
  color: #dc2626;
  border-color: #fecaca;
}
.arat-btn-danger:hover:not(:disabled) { background: #fef2f2; }
</style>
