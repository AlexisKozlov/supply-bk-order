<template>
  <div class="usr">
    <div class="usr-body">
      <!-- Сводка: сразу видно, кого сколько и что не в порядке -->
      <div class="usr-cards">
        <div class="usr-card">
          <div class="usr-card-label">Всего</div>
          <div class="usr-card-value">{{ users.length }}</div>
          <div class="usr-card-sub">{{ adminsCount }} с полным доступом</div>
        </div>
        <div class="usr-card" :class="{ live: onlineCount > 0 }">
          <div class="usr-card-label">Сейчас в портале</div>
          <div class="usr-card-value">{{ onlineCount }}</div>
          <div class="usr-card-sub">{{ activeWeekCount }} заходили за неделю</div>
        </div>
        <div class="usr-card">
          <div class="usr-card-label">Подключили бота</div>
          <div class="usr-card-value">{{ withBotCount }}</div>
          <div class="usr-card-sub">из {{ users.length }} — остальным бот не пишет</div>
        </div>
        <div class="usr-card" :class="{ warn: problemCount > 0 }">
          <div class="usr-card-label">Требуют внимания</div>
          <div class="usr-card-value">{{ problemCount }}</div>
          <div class="usr-card-sub">закрыт доступ или блокировка входа</div>
        </div>
      </div>

      <div class="usr-toolbar">
        <div class="usr-search">
          <BkIcon name="search" size="sm"/>
          <input v-model="query" type="search" placeholder="Имя, email или должность">
          <button v-if="query" class="usr-search-clear" @click="query = ''" title="Очистить">
            <BkIcon name="close" size="sm"/>
          </button>
        </div>

        <div class="usr-chips">
          <button v-for="f in filters" :key="f.value" class="usr-chip"
                  :class="{ active: filter === f.value }" @click="filter = f.value">
            {{ f.label }}<span v-if="f.count !== null" class="usr-chip-n">{{ f.count }}</span>
          </button>
        </div>

        <button class="btn primary usr-add" @click="openUserModal(null)">
          <BkIcon name="add" size="sm"/> Новый пользователь
        </button>
      </div>

      <div class="usr-found" v-if="query || filter">
        Показано {{ visibleUsers.length }} из {{ users.length }}
      </div>

      <div v-if="loading" style="text-align:center;padding:48px;"><BurgerSpinner text="Загрузка..." /></div>
      <UiEmptyState v-else-if="!visibleUsers.length && (query || filter)"
                    title="Никого не нашлось"
                    description="Попробуйте другой запрос или снимите фильтр.">
        <template #icon><BkIcon name="search" size="lg" /></template>
      </UiEmptyState>
      <UiEmptyState v-else-if="!users.length"
                    title="Сотрудников нет"
                    description="Ни одной учётной записи. Добавьте первую — человек получит доступ в портал.">
        <template #icon><BkIcon name="user" size="lg" /></template>
      </UiEmptyState>

      <div v-else class="adm-user-list">
        <div v-for="u in visibleUsers" :key="u.id" class="adm-user-row" @click="openUserModal(u)">
          <div class="adm-user-avatar" :class="{ admin: u.role === 'admin' }">{{ initials(u.name) }}</div>

          <div class="adm-user-info">
            <div class="adm-user-name">
              {{ u.name }}
              <span v-if="u.role === 'admin'" class="adm-badge adm-badge-admin">admin</span>
              <span v-else-if="u.role === 'viewer'" class="adm-badge adm-badge-viewer">читатель</span>
              <span v-if="u.name === userStore.currentUser?.name" class="adm-badge adm-badge-you">вы</span>
              <span v-if="isLocked(u)" class="adm-badge adm-badge-locked" :title="`${lockouts[u.name]} неудачных попыток за 10 мин`"><BkIcon name="key" size="sm" /> заблокирован</span>
              <!-- Отключение по неактивности: ставит крон, снимает кнопка ниже.
                   Это не то же самое, что временная блокировка после неудачных
                   попыток входа, поэтому и метка отдельная. -->
              <span v-if="u.telegram_chat_id" class="adm-badge usr-badge-bot" title="Подключил Telegram-бота">бот</span>
              <span v-if="u.disabled_at" class="adm-badge adm-badge-off"
                    :title="`Доступ закрыт ${fmtDateTime(u.disabled_at)}${u.disabled_reason ? ' — ' + u.disabled_reason : ''}`">доступ закрыт</span>
            </div>
            <div v-if="u.email" class="adm-user-email">{{ u.email }}</div>
            <div class="adm-user-meta">
              {{ u.display_role || ({ admin: 'Администратор', manager: 'Руководитель', viewer: 'Читатель' }[u.role] || 'Сотрудник') }}
              <!-- Журнал входов вёлся с марта, но нигде не показывался.
                   Клик открывает последние 20 входов с адресом. -->
              <span class="adm-user-login" :class="loginClass(u)"
                    :title="loginTitle(u)"
                    @click.stop="openLoginHistory(u)">{{ loginLabel(u) }}</span>
            </div>
          </div>

          <div class="adm-user-entities">
            <span v-for="le in parseLe(u.legal_entities)" :key="le" class="adm-entity">{{ shortEntity(le) }}</span>
            <span v-if="!parseLe(u.legal_entities).length" class="adm-entity adm-entity-all">Все</span>
          </div>

          <div class="adm-user-actions">
            <button class="adm-act-btn" :class="{ 'adm-act-locked': isLocked(u) }" @click.stop="resetLoginAttempts(u)" :title="isLocked(u) ? 'Заблокирован — сбросить попытки входа' : 'Сбросить попытки входа'"><BkIcon name="key" size="sm"/></button>
            <button v-if="u.disabled_at" class="adm-act-btn adm-act-restore" @click.stop="restoreAccess(u)" title="Вернуть доступ"><BkIcon name="restore" size="sm"/></button>
            <button class="adm-act-btn" @click.stop="openUserModal(u)" title="Редактировать"><BkIcon name="edit" size="sm"/></button>
            <button class="adm-act-btn adm-act-del" @click.stop="deleteUser(u)" title="Удалить"
              :disabled="u.name === userStore.currentUser?.name"><BkIcon name="delete" size="sm"/></button>
          </div>
        </div>
      </div>

      <!-- История входов одного сотрудника -->
      <div v-if="loginHistory" class="adm-lh-overlay" @click.self="loginHistory = null">
        <div class="adm-lh">
          <div class="adm-lh-head">
            <h3>Входы в портал — {{ loginHistory.name }}</h3>
            <button class="adm-lh-close" @click="loginHistory = null">✕</button>
          </div>
          <p v-if="loginHistory.loading" class="adm-lh-empty">Загружаем…</p>
          <p v-else-if="!loginHistory.rows.length" class="adm-lh-empty">Записей нет — этот человек ни разу не заходил.</p>
          <table v-else class="adm-lh-table">
            <thead><tr><th>Когда</th><th>Откуда</th><th>Логин</th></tr></thead>
            <tbody>
              <tr v-for="(r, i) in loginHistory.rows" :key="i">
                <td>{{ fmtDateTime(r.created_at) }}</td>
                <td class="adm-lh-ip">{{ r.ip || '—' }}</td>
                <td class="adm-lh-mail">{{ r.email || '—' }}</td>
              </tr>
            </tbody>
          </table>
          <p class="adm-lh-note">Показаны последние 20 входов. Журнал ведётся с марта 2026.</p>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <div v-if="userModal.show" class="modal" @click.self="tryCloseUserModal">
        <div class="modal-box usr-modal">
          <div class="modal-header">
            <h2>{{ userModal.user ? 'Редактирование' : 'Новый пользователь' }}</h2>
            <button class="modal-close" @click="tryCloseUserModal"><BkIcon name="close" size="sm"/></button>
          </div>

          <div class="adm-form usr-form">
            <div class="usr-sec-title">Кто это</div>
            <div class="modal-field">
              <span class="modal-field-label">Имя</span>
              <input v-model="form.name" placeholder="ФИО пользователя" />
            </div>

            <div class="modal-field">
              <span class="modal-field-label">Email</span>
              <input v-model="form.email" type="email" placeholder="Email для входа в систему" />
            </div>

            <div class="modal-row-2">
              <div class="modal-field" style="flex:1;">
                <span class="modal-field-label">Пароль</span>
                <UiPasswordInput v-model="form.password" :placeholder="userModal.user ? 'Не менять — оставить пустым' : 'Пароль'" />
              </div>
              <div class="modal-field" style="width:155px;flex-shrink:0;">
                <span class="modal-field-label">Роль</span>
                <select v-model="form.role">
                  <option value="user">Пользователь</option>
                  <option value="manager">Руководитель</option>
                  <option value="viewer">Читатель</option>
                  <option value="admin">Администратор</option>
                </select>
              </div>
            </div>

            <div class="modal-field">
              <span class="modal-field-label">Должность</span>
              <input v-model="form.display_role" placeholder="Менеджер, Руководитель и т.д." />
            </div>

            <div class="usr-sec-title">Где работает</div>
            <div class="modal-field">
              <span class="modal-field-label">Доступные юр. лица</span>
              <div class="adm-le-grid">
                <label v-for="le in allEntities" :key="le" class="adm-le-option">
                  <input type="checkbox" :value="le" v-model="form.legal_entities" />
                  <span class="adm-le-box">
                    <BkIcon name="success" size="sm"/>
                  </span>
                  <span>{{ le }}</span>
                </label>
              </div>
              <div class="adm-le-hint">Если ничего не выбрано — доступны все</div>
            </div>

            <!-- Внешние пользователи (например, производственный центр) должны
                 видеть в «Заявках поставщикам» только своего поставщика.
                 Случай редкий, поэтому блок свёрнут: обычным сотрудникам он
                 не мешает, а списком всех поставщиков форму не забивает. -->
            <div v-if="form.role !== 'admin'" class="modal-field">
              <span class="modal-field-label">Заявки поставщикам: чьи заявки видит</span>

              <div v-if="!scopeEditing" class="adm-scope-summary">
                <span v-if="!form.supplier_scope.length" class="adm-scope-all">
                  Всех поставщиков доступных юр. лиц
                </span>
                <span v-else class="adm-scope-chips">
                  <span v-for="id in form.supplier_scope" :key="id" class="adm-scope-chip">
                    {{ supplierName(id) }}
                  </span>
                </span>
                <button class="btn small" @click="openScopeEditor">
                  {{ form.supplier_scope.length ? 'Изменить' : 'Ограничить' }}
                </button>
              </div>

              <div v-else class="adm-scope-editor">
                <div v-if="form.supplier_scope.length" class="adm-scope-chips">
                  <span v-for="id in form.supplier_scope" :key="id" class="adm-scope-chip">
                    {{ supplierName(id) }}
                    <button class="adm-scope-x" @click="toggleScopeSupplier(id)" title="Убрать">×</button>
                  </span>
                </div>
                <input v-model="scopeQuery" class="adm-scope-search" placeholder="Начните вводить название поставщика…" />
                <div class="adm-scope-list">
                  <button
                    v-for="sup in scopeMatches"
                    :key="sup.id"
                    class="adm-scope-item"
                    :class="{ 'is-on': form.supplier_scope.includes(sup.id) }"
                    @click="toggleScopeSupplier(sup.id)"
                  >
                    <span>{{ sup.short_name }}</span>
                    <small class="adm-sup-le">{{ shortEntity(sup.legal_entity) }}</small>
                  </button>
                  <div v-if="!scopeMatches.length" class="adm-scope-empty">Ничего не найдено</div>
                </div>
                <div class="adm-scope-actions">
                  <button class="btn small secondary" @click="clearScope">Снять ограничение</button>
                  <button class="btn small" @click="scopeEditing = false">Готово</button>
                </div>
              </div>

              <div class="adm-le-hint">
                Нужно только внешним сотрудникам — например, производственному центру.
                Ограничение действует и на выгрузки, и на прямые ссылки.
              </div>
            </div>

            <!-- Доступ к модулям -->
            <div class="usr-sec-title">
              Что может
              <span v-if="form.role !== 'admin'" class="usr-sec-count">открыто {{ openModulesCount }} из {{ MODULES.length }}</span>
            </div>
            <div class="modal-field">
              <div v-if="form.role === 'admin'" class="adm-perm-admin-note">
                Администратор имеет полный доступ ко всем модулям
              </div>
              <template v-else>
                <div class="usr-perm-tools">
                  <input v-model="permQuery" class="usr-perm-search" placeholder="Найти модуль" />
                  <button class="btn small" @click="applyToAllModules('view')" title="Всем модулям — только просмотр">Всё смотреть</button>
                  <button class="btn small" @click="applyToAllModules('none')" title="Закрыть все модули">Закрыть всё</button>
                  <button class="btn small" @click="resetPermissionsToTemplate" title="Вернуть права, положенные роли">Как у роли</button>
                </div>
                <div class="adm-perm-grid">
                <div class="adm-perm-header">
                  <div class="adm-perm-module-col">Модуль</div>
                  <div class="adm-perm-level-col" v-for="lvl in ['full','edit','view','none']" :key="lvl">{{ ACCESS_LEVEL_LABELS[lvl] }}</div>
                </div>
                <div v-for="mod in visibleModules" :key="mod" class="adm-perm-row">
                  <div class="adm-perm-module-col">{{ MODULE_LABELS[mod] || mod }}</div>
                  <div class="adm-perm-level-col" v-for="lvl in ['full','edit','view','none']" :key="lvl">
                    <label class="adm-perm-radio">
                      <input type="radio" :name="'perm-' + mod" :checked="getFormModuleAccess(mod) === lvl" @change="setFormModuleAccess(mod, lvl)" />
                      <span class="adm-perm-dot" :class="'adm-perm-' + lvl"></span>
                    </label>
                  </div>
                </div>
                  <div v-if="!visibleModules.length" class="usr-perm-empty">Ничего не нашлось</div>
                </div>
              </template>
            </div>
          </div>

          <div class="usr-modal-actions">
            <button class="btn primary" @click="saveUser" :disabled="saving">
              <BurgerSpinner v-if="saving" size="xs" />
              <span>{{ saving ? 'Сохранение...' : (userModal.user ? 'Сохранить' : 'Создать') }}</span>
            </button>
            <button class="btn secondary" @click="tryCloseUserModal">Отмена</button>
          </div>
        </div>
      </div>
    </Teleport>
    <ConfirmModal v-if="confirmModal.show" :title="confirmModal.title" :message="confirmModal.message"
      @confirm="onConfirmOk" @cancel="onConfirmCancel" />
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { db } from '@/lib/apiClient.js';
import { useUserStore, ROLE_TEMPLATES, MODULES, MODULE_LABELS, loadRbacConfig } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { LEGAL_ENTITIES, ENTITY_SHORT_NAMES } from '@/lib/legalEntities.js';
import { formatMoscowDateTime, parseMoscowDate, plural } from '@/lib/utils.js';
import { appConfirm } from '@/lib/appDialogs.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import UiEmptyState from '@/components/ui/UiEmptyState.vue';
import UiPasswordInput from '@/components/ui/UiPasswordInput.vue';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';
import { useConfirm } from '@/composables/useConfirm.js';

const userStore = useUserStore();
const toast = useToastStore();

// Счётчик для вкладки рисует админка.
const emit = defineEmits(['count']);

const loading = ref(false);
const saving = ref(false);
const users = ref([]);
const lockouts = ref({});
// { имя: { last_login, days_since, logins_total, logins_30d } }
const loginStats = ref({});
const loginHistory = ref(null);

const allEntities = LEGAL_ENTITIES;

// Список поставщиков для привязки внешних пользователей. Берём только
// подключённых к модулю заявок — привязывать к остальным нечего.
const scopeSuppliers = ref([]);
const scopeEditing = ref(false);
const scopeQuery = ref('');

const scopeMatches = computed(() => {
  const q = scopeQuery.value.trim().toLowerCase();
  const list = scopeSuppliers.value;
  const filtered = q ? list.filter(s => (s.short_name || '').toLowerCase().includes(q)) : list;
  return filtered.slice(0, 8);
});

function supplierName(id) {
  return scopeSuppliers.value.find(s => s.id === id)?.short_name || id;
}
function openScopeEditor() { scopeEditing.value = true; scopeQuery.value = ''; }
function toggleScopeSupplier(id) {
  const list = form.value.supplier_scope;
  const i = list.indexOf(id);
  if (i === -1) list.push(id); else list.splice(i, 1);
}
function clearScope() { form.value.supplier_scope = []; scopeEditing.value = false; }
async function loadScopeSuppliers() {
  if (scopeSuppliers.value.length) return;
  const { data } = await db.from('suppliers')
    .select('id,short_name,legal_entity,so_enabled,is_active')
    .eq('so_enabled', 1).order('short_name').limit(500);
  scopeSuppliers.value = (data || []).filter(s => Number(s.is_active) === 1);
}

const userModal = ref({ show: false, user: null });
const form = ref({ name: '', email: '', password: '', role: 'user', display_role: '', legal_entities: [], supplier_scope: [], permissions: {} });
let _userFormSnapshot = '';

function tryCloseUserModal() {
  if (JSON.stringify(form.value) !== _userFormSnapshot) {
    confirmAction('Закрыть без сохранения?', 'Введённые данные пользователя будут потеряны.').then(ok => {
      if (ok) userModal.value.show = false;
    });
    return;
  }
  userModal.value.show = false;
}

const ACCESS_LEVEL_LABELS = { full: 'Полный', edit: 'Редакт.', view: 'Просмотр', none: 'Нет' };

// Поиск по модулям: их больше двадцати, листать глазами тяжело.
const permQuery = ref('');
const visibleModules = computed(() => {
  const q = permQuery.value.trim().toLowerCase();
  if (!q) return MODULES;
  return MODULES.filter(m => String(MODULE_LABELS[m] || m).toLowerCase().includes(q));
});

// Сколько модулей человек реально видит — цифра рядом с заголовком.
const openModulesCount = computed(() =>
  MODULES.filter(m => getFormModuleAccess(m) !== 'none').length);

// Одинаковый уровень всем модулям сразу: раньше приходилось щёлкать
// два десятка радиокнопок.
function applyToAllModules(level) {
  for (const m of MODULES) setFormModuleAccess(m, level);
}

function getFormModuleAccess(module) {
  if (form.value.permissions && form.value.permissions[module] !== undefined) {
    return form.value.permissions[module];
  }
  const tpl = ROLE_TEMPLATES[form.value.role] || ROLE_TEMPLATES.user;
  return tpl[module] || 'none';
}

function setFormModuleAccess(module, level) {
  const tpl = ROLE_TEMPLATES[form.value.role] || ROLE_TEMPLATES.user;
  if (!form.value.permissions) form.value.permissions = {};
  if (tpl[module] === level) {
    delete form.value.permissions[module];
  } else {
    form.value.permissions[module] = level;
  }
}

// При смене роли НЕ сбрасываем индивидуальные права —
// они пересчитаются как diff от нового шаблона при сохранении

function resetPermissionsToTemplate() {
  form.value.permissions = {};
}

function getPermissionsDiff() {
  if (!form.value.permissions || Object.keys(form.value.permissions).length === 0) return null;
  return { ...form.value.permissions };
}

// ═══ Модалка прав доступа ═══
const showPermModal = ref(false);
const permUser = ref(null);
const permModules = ref([]);
const savingPerms = ref(false);

function permLevelLabel(level) {
  return { none: '\u2014', view: 'Просмотр', edit: 'Редактир.', full: 'Полный' }[level] || '\u2014';
}

function openPermissions(user) {
  permUser.value = user;
  const role = user.role || 'user';
  const base = ROLE_TEMPLATES[role] || ROLE_TEMPLATES.user;
  let overrides = {};
  try { overrides = typeof user.permissions === 'string' ? JSON.parse(user.permissions || '{}') : (user.permissions || {}); } catch { overrides = {}; }

  permModules.value = Object.keys(MODULE_LABELS).map(key => ({
    key,
    label: MODULE_LABELS[key],
    base: base[key] || 'none',
    current: overrides[key] || base[key] || 'none',
    override: overrides[key] || '',
  }));
  showPermModal.value = true;
}

async function savePermissions() {
  savingPerms.value = true;
  try {
    const overrides = {};
    const role = permUser.value.role || 'user';
    const base = ROLE_TEMPLATES[role] || ROLE_TEMPLATES.user;
    for (const m of permModules.value) {
      if (m.override && m.override !== base[m.key]) {
        overrides[m.key] = m.override;
      }
    }
    const permsToSend = Object.keys(overrides).length ? overrides : null;

    const { data, error } = await db.rpc('update_user', {
      caller_name: userStore.currentUser?.name || '',
      user_id: permUser.value.id,
      permissions: permsToSend,
    });
    if (error || (data && !data.success)) { toast.error('Ошибка', error || data?.error || ''); return; }

    // Обновить локально
    const idx = users.value.findIndex(u => u.id === permUser.value.id);
    if (idx >= 0) {
      users.value[idx].permissions = permsToSend ? JSON.stringify(permsToSend) : null;
    }

    toast.success('Сохранено', 'Права обновлены');
    showPermModal.value = false;
  } catch (e) {
    toast.error('Ошибка', e.message);
  } finally {
    savingPerms.value = false;
  }
}

const { confirmModal, confirm: confirmAction, onConfirm: onConfirmOk, onCancel: onConfirmCancel } = useConfirm();

// Счётчик записей журнала — его присылает сама вкладка.
const auditTotal = ref(0);


const usersWord = computed(() => {
  const n = users.value.length;
  if (n % 10 === 1 && n % 100 !== 11) return 'пользователь';
  if ([2,3,4].includes(n % 10) && ![12,13,14].includes(n % 100)) return 'пользователя';
  return 'пользователей';
});

function parseLe(val) {
  if (!val) return [];
  if (Array.isArray(val)) return val;
  try { return JSON.parse(val) || []; } catch { return []; }
}

function shortEntity(le) {
  const map = ENTITY_SHORT_NAMES;
  return map[le] || le;
}

function initials(name) {
  if (!name) return '?';
  return name.split(/\s+/).map(w => w[0]).join('').toUpperCase().slice(0, 2);
}

// Вернуть доступ отключённому: обнуляем обе колонки. Отдельная кнопка, а не
// поле в форме редактирования — чтобы возврат был в один клик и заметен.
async function restoreAccess(u) {
  if (!(await appConfirm('Вернуть доступ?', `${u.name} снова сможет войти в портал.`))) return;
  // Через CRUD нельзя: users помечена только для чтения. Отдельный метод.
  const { data, error } = await db.rpc('set_user_disabled', { name: u.name, disabled: false });
  if (error || data?.error) { toast.error('Не получилось', error || data.error); return; }
  toast.success('Доступ возвращён', u.name);
  await loadUsers();
}

async function loadUsers() {
  loading.value = true;
  try {
    const { data } = await db.from('users').select('*').order('name');
    users.value = (data || []).map(u => {
      if (u.permissions && typeof u.permissions === 'string') {
        try { u.permissions = JSON.parse(u.permissions); } catch { u.permissions = null; }
      }
      return u;
    });
    loadLockouts();
    loadLoginStats();
  } catch { toast.error('Ошибка', 'Не удалось загрузить пользователей'); }
  finally { loading.value = false; }
}

// Когда человек последний раз заходил в портал. Журнал входов пишется с
// марта, но до сих пор нигде не показывался: таблица лежала в другой
// сортировке, чем users, и JOIN по имени падал с ошибкой.
async function loadLoginStats() {
  try {
    const { data } = await db.rpc('user_login_stats');
    loginStats.value = (data && data.stats) || {};
  } catch { loginStats.value = {}; }
}

// Показываем последнюю АКТИВНОСТЬ, а не последний вход по паролю. Сессия
// живёт неделями: человек может работать в портале каждый день и месяц не
// вводить пароль. Раньше здесь стоял вход, и такие сотрудники выглядели
// пропавшими — вплоть до красной пометки «давно не заходил».
function loginLabel(u) {
  const s = loginStats.value[u.name];
  if (!s) return 'ни разу не заходил';
  if (s.minutes_since_seen !== null && s.minutes_since_seen <= 5) return 'сейчас в портале';
  const d = activeDays(s);
  if (d === null) return 'ни разу не заходил';
  if (d <= 0) return 'был сегодня';
  if (d === 1) return 'был вчера';
  return `был ${d} ${plural(d, 'день', 'дня', 'дней')} назад`;
}

// Сколько дней назад человека последний раз видели. Берём то, что свежее:
// активность или вход. Вход учитываем на случай, если heartbeat не успел
// записаться — например, зашли и сразу закрыли вкладку.
function activeDays(s) {
  const a = s.days_since_seen;
  const b = s.days_since;
  if (a === null && b === null) return null;
  if (a === null) return b;
  if (b === null) return a;
  return Math.min(a, b);
}

// Красным — кого не видели дольше двух месяцев или не видели вообще:
// у такого сотрудника доступ живёт сам по себе.
function loginClass(u) {
  const s = loginStats.value[u.name];
  const d = s ? activeDays(s) : null;
  if (d === null) return 'is-never';
  if (d >= 60) return 'is-never';
  if (d >= 30) return 'is-stale';
  return '';
}

// В подсказке — точные даты: активность и отдельно вход по паролю.
function loginTitle(u) {
  const s = loginStats.value[u.name];
  if (!s) return 'Показать историю входов';
  const fmt = v => v ? new Date(v.replace(' ', 'T')).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' }) : 'нет данных';
  return `В портале: ${fmt(s.last_seen)}\nВход по паролю: ${fmt(s.last_login)}\nНажмите, чтобы открыть историю входов`;
}

async function openLoginHistory(u) {
  loginHistory.value = { name: u.name, rows: [], loading: true };
  try {
    const { data } = await db.rpc('user_login_history', { name: u.name });
    loginHistory.value = { name: u.name, rows: (data && data.history) || [], loading: false };
  } catch {
    loginHistory.value = { name: u.name, rows: [], loading: false };
  }
}

// Кто сейчас заблокирован по числу неудачных попыток входа (за 10 минут).
async function loadLockouts() {
  try {
    const { data } = await db.rpc('get_login_lockouts');
    lockouts.value = (data && data.lockouts) || {};
  } catch { lockouts.value = {}; }
}
// Аккаунт блокируется после 5 неудачных попыток за 10 минут.
function isLocked(u) { return (lockouts.value[u.name] || 0) >= 5; }

function openUserModal(user) {
  loadScopeSuppliers();
  scopeEditing.value = false;
  scopeQuery.value = '';
  userModal.value.user = user;
  if (user) {
    const perms = user.permissions;
    form.value = {
      name: user.name || '',
      email: user.email || '',
      password: '',
      role: user.role || 'user',
      display_role: user.display_role || '',
      legal_entities: parseLe(user.legal_entities),
      supplier_scope: parseLe(user.supplier_scope),
      permissions: (perms && typeof perms === 'object') ? { ...perms } : {},
    };
  } else {
    form.value = { name: '', email: '', password: '', role: 'user', display_role: '', legal_entities: [], supplier_scope: [], permissions: {} };
  }
  userModal.value.show = true;
  _userFormSnapshot = JSON.stringify(form.value);
}

async function saveUser() {
  if (saving.value) return;
  if (!form.value.name.trim()) { toast.error('Введите имя', ''); return; }
  if (!userModal.value.user && !form.value.password) { toast.error('Введите пароль', ''); return; }
  if (form.value.password && form.value.password.length < 8) { toast.error('Короткий пароль', 'Минимум 8 символов'); return; }
  saving.value = true;
  try {
    const payload = {
      name: form.value.name.trim(),
      email: form.value.email.trim(),
      role: form.value.role,
      display_role: form.value.display_role.trim() || null,
      legal_entities: JSON.stringify(form.value.legal_entities),
      supplier_scope: form.value.supplier_scope,
      permissions: getPermissionsDiff(),
    };
    if (form.value.password) payload.password = form.value.password;

    if (userModal.value.user) {
      const { data, error } = await db.rpc('update_user', {
        caller_name: userStore.currentUser?.name || '',
        user_id: userModal.value.user.id,
        ...payload,
      });
      if (error || (data && !data.success)) { toast.error('Ошибка', error || data?.error || ''); return; }
      toast.success('Обновлено', payload.name);
    } else {
      if (!form.value.password) { toast.error('Введите пароль', ''); return; }
      const { data, error } = await db.rpc('create_user', {
        caller_name: userStore.currentUser?.name || '',
        ...payload,
        password: form.value.password,
      });
      if (error || (data && !data.success)) { toast.error('Ошибка', error || data?.error || ''); return; }
      toast.success('Создано', payload.name);
    }
    userModal.value.show = false;
    await loadUsers();
  } finally { saving.value = false; }
}

async function deleteUser(u) {
  if (u.name === userStore.currentUser?.name) { toast.error('Нельзя удалить себя', ''); return; }
  const ok = await confirmAction('Удалить пользователя?', `Пользователь «${u.name}» будет удалён безвозвратно.`);
  if (!ok) return;
  const { data, error } = await db.rpc('delete_user', { caller_name: userStore.currentUser?.name || '', user_id: u.id });
  if (error || (data && !data.success)) { toast.error('Ошибка', error || data?.error || ''); return; }
  toast.success('Удалено', u.name);
  await loadUsers();
}

async function resetLoginAttempts(u) {
  const ok = await confirmAction('Сбросить попытки входа?', `Блокировка входа для «${u.name}» будет снята — он сможет войти сразу.`);
  if (!ok) return;
  const { data, error } = await db.rpc('reset_login_attempts', { caller_name: userStore.currentUser?.name || '', name: u.name });
  if (error || (data && !data.success)) { toast.error('Ошибка', error || data?.error || ''); return; }
  toast.success('Готово', `Блокировка снята (сброшено записей: ${data?.cleared ?? 0})`);
  loadLockouts();
}

// Дата и время входа — всегда полностью, включая год: журнал ведётся с марта,
// и «12.03 09:40» без года читается неоднозначно.
function fmtDateTime(ts) {
  if (!ts) return '—';
  try {
    const dt = new Date(String(ts).replace(' ', 'T'));
    return dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' }) +
           ' ' + dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  } catch { return ts; }
}

// ─── Поиск и фильтры ───
const query = ref('');
const filter = ref('');

// «Сейчас в портале» — по журналу присутствия, его же показывает вкладка «Сессии».
const presence = ref({});
async function loadPresence() {
  try {
    const { data } = await db.rpc('get_online_users');
    const map = {};
    for (const row of data || []) map[row.user_name] = row.last_seen;
    presence.value = map;
  } catch { /* не критично: просто не подсветим */ }
}
function isOnline(u) { return !!presence.value[u.name]; }

const onlineCount = computed(() => users.value.filter(isOnline).length);
const adminsCount = computed(() => users.value.filter(u => u.role === 'admin').length);
const withBotCount = computed(() => users.value.filter(u => u.telegram_chat_id).length);
const activeWeekCount = computed(() => users.value.filter(u => {
  const s = loginStats.value[u.name];
  return s && s.days_since !== null && s.days_since <= 7;
}).length);
const problemUsers = computed(() => users.value.filter(u => u.disabled_at || isLocked(u)));
const problemCount = computed(() => problemUsers.value.length);

const filters = computed(() => [
  { value: '', label: 'Все', count: null },
  { value: 'online', label: 'Сейчас в портале', count: onlineCount.value },
  { value: 'problem', label: 'Требуют внимания', count: problemCount.value },
  { value: 'nobot', label: 'Без бота', count: users.value.length - withBotCount.value },
  { value: 'stale', label: 'Давно не заходили', count: users.value.filter(isStale).length },
]);

// «Давно» — больше месяца назад или ни разу.
function isStale(u) {
  const s = loginStats.value[u.name];
  return !s || s.days_since === null || s.days_since > 30;
}

const visibleUsers = computed(() => {
  const q = query.value.trim().toLowerCase();
  return users.value.filter(u => {
    if (filter.value === 'online' && !isOnline(u)) return false;
    if (filter.value === 'problem' && !(u.disabled_at || isLocked(u))) return false;
    if (filter.value === 'nobot' && u.telegram_chat_id) return false;
    if (filter.value === 'stale' && !isStale(u)) return false;
    if (!q) return true;
    return `${u.name} ${u.email || ''} ${u.display_role || ''}`.toLowerCase().includes(q);
  });
});

onMounted(async () => {
  loadRbacConfig();
  await loadUsers();
  emit('count', users.value.length);
  loadPresence();
  loadScopeSuppliers();
});
</script>

<style scoped>
/* ═══ User List ═══ */
.adm-user-list { display: flex; flex-direction: column; gap: 2px; }
.adm-user-row {
  display: flex; align-items: center; gap: 14px;
  padding: 10px 14px; border-radius: 10px;
  background: var(--card); border: 1.5px solid transparent;
  cursor: pointer; transition: all .15s;
}
.adm-user-row:hover { border-color: var(--bk-orange); box-shadow: 0 2px 8px rgba(244,162,97,.08); }

.adm-user-avatar {
  width: 40px; height: 40px; border-radius: 12px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; font-weight: 700; color: #fff;
  background: linear-gradient(135deg, #F4A261, #E8941A);
}
.adm-user-avatar.admin { background: linear-gradient(135deg, #E53935, #C62828); }

.adm-user-info { flex: 1; min-width: 0; }
.adm-user-name {
  font-size: 14px; font-weight: 600; color: var(--text);
  display: flex; align-items: center; gap: 6px;
}
.adm-user-email { font-size: 11px; color: var(--text-muted); margin-top: 1px; opacity: .7; }
.adm-user-meta { font-size: 12px; color: var(--text-muted); margin-top: 1px; }
.adm-user-login {
  margin-left: 8px; padding: 0 6px; border-radius: 8px; cursor: pointer;
  background: rgba(0, 0, 0, .04);
}
.adm-user-login:hover { background: rgba(0, 0, 0, .08); }
.adm-user-login.is-stale { color: #9a6b12; background: rgba(154, 107, 18, .12); }
.adm-user-login.is-never { color: #b4432e; background: rgba(180, 67, 46, .12); }

.adm-lh-overlay {
  position: fixed; inset: 0; background: rgba(0, 0, 0, .35);
  display: flex; align-items: center; justify-content: center; z-index: 60; padding: 16px;
}
.adm-lh {
  background: var(--card-bg, #fff); border-radius: 12px; padding: 16px 18px;
  width: min(560px, 100%); max-height: 80vh; overflow: auto;
}
.adm-lh-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 10px; }
.adm-lh-head h3 { margin: 0; font-size: 15px; }
.adm-lh-close { border: none; background: none; font-size: 16px; cursor: pointer; color: var(--text-muted); }
.adm-lh-empty { color: var(--text-muted); font-size: 13px; padding: 16px 0; }
.adm-lh-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.adm-lh-table th {
  text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em;
  color: var(--text-muted); font-weight: 600; padding: 4px 8px 4px 0;
}
.adm-lh-table td { padding: 4px 8px 4px 0; border-top: 1px solid var(--border, #eceff2); white-space: nowrap; }
.adm-lh-ip, .adm-lh-mail { font-family: ui-monospace, monospace; font-size: 12px; color: var(--text-muted); }
.adm-lh-note { margin: 10px 0 0; font-size: 11.5px; color: var(--text-muted); }

.adm-badge {
  display: inline-block; padding: 1px 7px; border-radius: 4px;
  font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px;
}
.adm-badge-admin { background: #FFEBEE; color: #C62828; }
.adm-badge-viewer { background: #E3F2FD; color: #1565C0; }
.adm-badge-you { background: #E8F5E9; color: #2E7D32; }
.adm-badge-off { background: #F3EBE3; color: #6B5A50; }
.adm-act-restore { color: #16A364; }
.adm-badge-locked { background: #FFEBEE; color: #C62828; font-weight: 700; }

.adm-user-entities { display: flex; gap: 4px; flex-shrink: 0; }
.adm-entity {
  padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 600;
  background: #FFF8E1; color: #E65100; border: 1px solid #FFE0B2;
}
.adm-entity-all { background: var(--bg); color: var(--text-muted); border-color: var(--border-light); }

.adm-user-actions { display: flex; gap: 4px; opacity: 0; transition: opacity .15s; flex-shrink: 0; }
.adm-user-row:hover .adm-user-actions { opacity: 1; }
.adm-act-btn {
  padding: 5px 7px; border-radius: 6px; border: 1px solid var(--border-light);
  background: none; cursor: pointer; transition: all .15s; color: var(--text-muted);
}
.adm-act-btn:hover { background: var(--bg); border-color: var(--border); color: var(--text); }
.adm-act-del:hover { background: #FFF0F0; border-color: #E57373; color: #D32F2F; }
.adm-act-locked { background: #FFEBEE; border-color: #E57373; color: #C62828; }
.adm-act-locked:hover { background: #FFCDD2; }
.adm-act-btn:disabled { opacity: .3; pointer-events: none; }

/* ═══ Responsive ═══ */
@media (max-width: 600px) {
  .adm-user-entities { display: none; }
  .adm-user-actions { opacity: 1; }
  .adm-maint-card { flex-direction: column; text-align: center; gap: 12px; padding: 16px; }

  /* Tabs — compact */
  .adm-tabs { gap: 0; }
  .adm-tab { padding: 8px 12px; font-size: 12px; gap: 4px; }
  .adm-tab-count { font-size: 10px; padding: 1px 5px; }

  /* User row — tighter */
  .adm-user-row { gap: 10px; padding: 8px 10px; }
  .adm-user-avatar { width: 34px; height: 34px; font-size: 12px; border-radius: 10px; }
  .adm-user-name { font-size: 13px; flex-wrap: wrap; }

  /* Toolbar wrap */
  .adm-toolbar { flex-wrap: wrap; gap: 8px; }

  /* Broadcast card */
  .adm-maint-msg-card { padding: 14px; }
  .adm-maint-msg-title { font-size: 13px; }

  /* Online time */
  .adm-online-time { font-size: 11px; }
}

/* ═══ Permissions Grid ═══ */
.adm-perm-admin-note {
  padding: 10px 14px; border-radius: 8px; background: #E3F2FD;
  color: #1565C0; font-size: 13px; border: 1px solid #BBDEFB;
}
.adm-perm-grid {
  border: 1px solid var(--border-light); border-radius: 10px; overflow: hidden;
}
.adm-perm-header {
  display: grid; grid-template-columns: 1fr 60px 60px 64px 44px;
  background: var(--bg); padding: 6px 12px; font-size: 11px; font-weight: 600;
  color: var(--text-muted); text-transform: uppercase; letter-spacing: .3px;
  border-bottom: 1px solid var(--border-light);
}
.adm-perm-row {
  display: grid; grid-template-columns: 1fr 60px 60px 64px 44px;
  padding: 5px 12px; align-items: center; border-bottom: 1px solid var(--border-light);
  transition: background .15s;
}
.adm-perm-row:last-of-type { border-bottom: none; }
.adm-perm-row:hover { background: var(--bg); }
.adm-perm-module-col { font-size: 13px; color: var(--text); }
.adm-perm-level-col { text-align: center; }
.adm-perm-radio { display: inline-flex; cursor: pointer; }
.adm-perm-radio input { display: none; }
.adm-perm-dot {
  width: 18px; height: 18px; border-radius: 50%; border: 2px solid var(--border);
  transition: all .15s; position: relative;
}
.adm-perm-radio input:checked + .adm-perm-dot { border-color: var(--bk-orange); }
.adm-perm-radio input:checked + .adm-perm-full { background: #4CAF50; border-color: #4CAF50; }
.adm-perm-radio input:checked + .adm-perm-edit { background: var(--bk-orange); border-color: var(--bk-orange); }
.adm-perm-radio input:checked + .adm-perm-view { background: #2196F3; border-color: #2196F3; }
.adm-perm-radio input:checked + .adm-perm-none { background: #9E9E9E; border-color: #9E9E9E; }
.adm-perm-reset { margin: 8px 12px; font-size: 11px; }

/* ═══ Form (modal) ═══ */
.adm-form { display: flex; flex-direction: column; gap: 10px; }

.adm-le-grid { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
.adm-le-option {
  display: flex; align-items: center; gap: 8px; cursor: pointer;
  padding: 8px 14px; border: 1.5px solid var(--border); border-radius: 8px;
  font-size: 13px; font-weight: 500; color: var(--text-muted);
  transition: all .15s; user-select: none;
}
.adm-le-option:hover { border-color: var(--bk-orange); }
.adm-le-option:has(input:checked) {
  border-color: var(--bk-orange); background: #FFFBF5; color: var(--bk-brown);
}
.adm-le-option input { display: none; }
.adm-le-box {
  width: 18px; height: 18px; border-radius: 5px;
  border: 2px solid var(--border); display: flex; align-items: center; justify-content: center;
  transition: all .15s; color: transparent;
}
.adm-le-option:has(input:checked) .adm-le-box {
  background: var(--bk-orange); border-color: var(--bk-orange); color: #fff;
}
.adm-le-hint { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

/* ═══ Ограничение по поставщику ═══ */
.adm-scope-summary { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.adm-scope-all { font-size: 13px; color: var(--text-muted); }
.adm-scope-chips { display: flex; gap: 6px; flex-wrap: wrap; }
.adm-scope-chip {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 8px; border-radius: 20px;
  background: #FFF4E8; color: #C25E12; font-size: 12px; font-weight: 700;
}
.adm-scope-x { border: 0; background: none; color: inherit; font-size: 14px; cursor: pointer; padding: 0 2px; }
.adm-scope-editor { display: flex; flex-direction: column; gap: 8px; }
.adm-scope-search { width: 100%; }
.adm-scope-list { display: flex; flex-direction: column; gap: 2px; max-height: 220px; overflow: auto; }
.adm-scope-item {
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  padding: 7px 10px; border: 1.5px solid var(--border); border-radius: 8px;
  background: #fff; font: inherit; font-size: 13px; cursor: pointer; text-align: left;
}
.adm-scope-item:hover { border-color: #E87A1E; }
.adm-scope-item.is-on { background: #FFF4E8; border-color: #E87A1E; color: #C25E12; font-weight: 700; }
.adm-scope-empty { padding: 8px 10px; font-size: 12.5px; color: var(--text-muted); }
.adm-scope-actions { display: flex; justify-content: space-between; gap: 8px; }


/* ═══ Окно сотрудника ═══ */
.usr-modal { width: min(680px, calc(100vw - 32px)); display: flex; flex-direction: column; max-height: 88vh; }
/* flex:1 + min-height:0 — иначе прокручиваемая область схлопывается
   и таблица прав обрезается сразу под инструментами. */
.usr-form { flex: 1 1 auto; min-height: 0; overflow-y: auto; padding-right: 4px; }

.usr-sec-title {
  display: flex; align-items: baseline; justify-content: space-between; gap: 10px;
  font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px;
  color: var(--bk-brown, #502314); margin: 6px 0 2px;
  padding-bottom: 6px; border-bottom: 1px solid var(--border-light);
}
.usr-sec-title:not(:first-child) { margin-top: 14px; }
.usr-sec-count { font-size: 11px; font-weight: 600; letter-spacing: 0; text-transform: none; color: var(--text-muted); }

.usr-perm-tools { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; }
.usr-perm-search {
  flex: 1; min-width: 140px; padding: 6px 10px; font-family: inherit; font-size: 12.5px;
  border: 1px solid var(--border-light); border-radius: 8px; background: var(--card); color: var(--text);
}
.usr-perm-search:focus { outline: none; border-color: var(--bk-orange); }
.usr-perm-empty { padding: 14px; text-align: center; font-size: 12.5px; color: var(--text-muted); }

/* Шапка таблицы прав держится сверху: иначе при прокрутке видны только
   кружки, и непонятно, где «полный», а где «просмотр». */
.adm-perm-grid .adm-perm-header { position: sticky; top: 0; z-index: 2; }

/* Кнопки всегда на виду: список прав длинный, прокручивать до них не нужно */
.usr-modal-actions {
  display: flex; gap: 8px; padding-top: 14px; margin-top: 4px;
  border-top: 1px solid var(--border-light); flex-shrink: 0;
}

@media (max-width: 600px) {
  .usr-modal { max-height: 92vh; }
  .usr-perm-tools .btn { flex: 1; justify-content: center; }
  .usr-modal-actions .btn { flex: 1; justify-content: center; }
}

/* ═══ Сводка и фильтры ═══ */
.usr-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 12px; }
.usr-card {
  background: var(--card); border: 1px solid var(--border-light);
  border-radius: 12px; padding: 12px 14px;
}
.usr-card.live { border-color: #C8E6C9; background: linear-gradient(180deg, #F4FBF4, var(--card)); }
.usr-card.warn { border-color: #FFE0B2; background: linear-gradient(180deg, #FFF8EC, var(--card)); }
.usr-card-label { font-size: 11px; text-transform: uppercase; letter-spacing: .3px; color: var(--text-muted); font-weight: 600; }
.usr-card-value { font-size: 24px; font-weight: 800; color: var(--text); line-height: 1.2; margin-top: 2px; }
.usr-card-sub { font-size: 11.5px; color: var(--text-muted); }

.usr-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
.usr-search {
  display: flex; align-items: center; gap: 6px; flex: 1; min-width: 200px;
  background: var(--card); border: 1px solid var(--border-light);
  border-radius: 10px; padding: 0 10px; color: var(--text-muted);
}
.usr-search input {
  flex: 1; min-width: 0; border: none; background: none; outline: none;
  font-family: inherit; font-size: 13px; color: var(--text); padding: 8px 0;
}
.usr-search input::-webkit-search-cancel-button { display: none; }
.usr-search-clear { border: none; background: none; cursor: pointer; color: var(--text-muted); padding: 2px; }

.usr-chips { display: flex; gap: 4px; flex-wrap: wrap; }
.usr-chip {
  border: 1px solid var(--border-light); background: var(--card);
  font-family: inherit; font-size: 12.5px; color: var(--text-muted);
  padding: 6px 11px; border-radius: 8px; cursor: pointer; transition: all .15s;
  display: inline-flex; align-items: center; gap: 6px;
}
.usr-chip:hover { border-color: var(--bk-orange); color: var(--text); }
.usr-chip.active { border-color: var(--bk-orange); background: #FFFBF5; color: var(--bk-brown); font-weight: 600; }
.usr-chip-n { font-size: 11px; font-weight: 700; padding: 0 5px; border-radius: 5px; background: rgba(0,0,0,.06); }
.usr-chip.active .usr-chip-n { background: #FFF3E0; color: #E65100; }

.usr-add { flex-shrink: 0; }
.usr-found { font-size: 12px; color: var(--text-muted); margin-bottom: 8px; }

.usr-badge-bot { background: #E3F2FD; color: #1565C0; }

@media (max-width: 900px) { .usr-cards { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 600px) {
  .usr-card-value { font-size: 20px; }
  .usr-search { min-width: 0; width: 100%; }
  .usr-add { width: 100%; justify-content: center; }
  /* Юрлица прятались на телефоне совсем — показываем под именем */
  .adm-user-entities { display: flex !important; }
  .adm-user-row { flex-wrap: wrap; }
  .adm-user-info { flex: 1 1 100%; }
}
</style>
