<template>
  <div class="admin-view">
    <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;">
      <h1 class="page-title"><BkIcon name="gear" size="sm"/> Администрирование</h1>
    </div>

    <!-- Табы -->
    <div class="adm-tabs">
      <button class="adm-tab" :class="{ active: activeTab === 'users' }" @click="activeTab = 'users'">
        <BkIcon name="user" size="sm"/> Пользователи <span class="adm-tab-count" :class="{ active: activeTab === 'users' }">{{ users.length }}</span>
      </button>
      <button class="adm-tab" :class="{ active: activeTab === 'maintenance' }" @click="activeTab = 'maintenance'">
        <BkIcon name="warning" size="sm"/> Тех. работы
        <span v-if="maintenanceOn" class="adm-tab-dot"></span>
      </button>
    </div>

    <!-- ═══ Пользователи ═══ -->
    <div v-if="activeTab === 'users'" class="adm-section">
      <div class="adm-toolbar">
        <div class="adm-toolbar-info">{{ users.length }} {{ usersWord }}</div>
        <button class="btn primary" @click="openUserModal(null)">
          <BkIcon name="add" size="sm"/> Новый пользователь
        </button>
      </div>

      <div v-if="loading" style="text-align:center;padding:48px;"><BurgerSpinner text="Загрузка..." /></div>
      <div v-else-if="!users.length" class="adm-empty">Нет пользователей</div>

      <div v-else class="adm-user-list">
        <div v-for="u in users" :key="u.id" class="adm-user-row" @click="openUserModal(u)">
          <div class="adm-user-avatar" :class="{ admin: u.role === 'admin' }">{{ initials(u.name) }}</div>

          <div class="adm-user-info">
            <div class="adm-user-name">
              {{ u.name }}
              <span v-if="u.role === 'admin'" class="adm-badge adm-badge-admin">admin</span>
              <span v-else-if="u.role === 'viewer'" class="adm-badge adm-badge-viewer">читатель</span>
              <span v-if="u.name === userStore.currentUser?.name" class="adm-badge adm-badge-you">вы</span>
            </div>
            <div class="adm-user-meta">
              {{ u.display_role || (u.role === 'admin' ? 'Администратор' : 'Сотрудник') }}
            </div>
          </div>

          <div class="adm-user-entities">
            <span v-for="le in parseLe(u.legal_entities)" :key="le" class="adm-entity">{{ shortEntity(le) }}</span>
            <span v-if="!parseLe(u.legal_entities).length" class="adm-entity adm-entity-all">Все</span>
          </div>

          <div class="adm-user-actions">
            <button class="adm-act-btn" @click.stop="openUserModal(u)" title="Редактировать"><BkIcon name="edit" size="sm"/></button>
            <button class="adm-act-btn adm-act-del" @click.stop="deleteUser(u)" title="Удалить"
              :disabled="u.name === userStore.currentUser?.name"><BkIcon name="delete" size="sm"/></button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══ Тех. работы ═══ -->
    <div v-if="activeTab === 'maintenance'" class="adm-section">
      <div class="adm-maint-card" :class="{ on: maintenanceOn }">
        <div class="adm-maint-icon">
          <svg viewBox="0 0 48 48" width="48" height="48" fill="none">
            <circle cx="24" cy="24" r="22" :fill="maintenanceOn ? 'rgba(211,47,47,0.08)' : 'rgba(0,0,0,0.03)'" :stroke="maintenanceOn ? '#D32F2F' : 'var(--border)'" stroke-width="2"/>
            <path d="M24 14v12" :stroke="maintenanceOn ? '#D32F2F' : 'var(--text-muted)'" stroke-width="3.5" stroke-linecap="round"/>
            <circle cx="24" cy="32" r="2.5" :fill="maintenanceOn ? '#D32F2F' : 'var(--text-muted)'"/>
          </svg>
        </div>

        <div class="adm-maint-body">
          <h3 class="adm-maint-title">Режим технических работ</h3>
          <p class="adm-maint-desc">
            Когда режим включён, все пользователи кроме администраторов видят заглушку и не могут работать в системе.
          </p>
        </div>

        <button class="adm-maint-toggle" :class="{ on: maintenanceOn }" @click="toggleMaintenance" :disabled="maintenanceSaving">
          <span class="adm-maint-track"><span class="adm-maint-thumb"></span></span>
          <span class="adm-maint-label">{{ maintenanceOn ? 'Включён' : 'Выключен' }}</span>
        </button>
      </div>

      <div v-if="maintenanceOn" class="adm-maint-warning">
        <BkIcon name="warning" size="sm"/>
        <span>Сайт <b>недоступен</b> для обычных пользователей прямо сейчас</span>
      </div>

      <div class="adm-maint-msg-card">
        <h4 class="adm-maint-msg-title">Сообщение для пользователей</h4>
        <p class="adm-maint-msg-hint">Отображается на экране технических работ. Если пусто — показывается стандартный текст.</p>
        <textarea v-model="maintenanceMsg" class="adm-maint-textarea" rows="3" placeholder="Например: Обновление системы до 18:00. Приносим извинения за неудобства."></textarea>
        <button class="btn primary" style="margin-top:8px;font-size:13px;padding:7px 16px;" @click="saveMaintenanceMsg" :disabled="maintenanceMsgSaving">
          {{ maintenanceMsgSaving ? 'Сохранение...' : 'Сохранить сообщение' }}
        </button>
      </div>
    </div>

    <!-- ═══ Модалка пользователя ═══ -->
    <Teleport to="body">
      <div v-if="userModal.show" class="modal" @click.self="userModal.show = false">
        <div class="modal-box" style="width:460px;">
          <div class="modal-header">
            <h2>{{ userModal.user ? 'Редактирование' : 'Новый пользователь' }}</h2>
            <button class="modal-close" @click="userModal.show = false"><BkIcon name="close" size="sm"/></button>
          </div>

          <div class="adm-form">
            <div class="modal-field">
              <span class="modal-field-label">Имя</span>
              <input v-model="form.name" placeholder="ФИО пользователя" />
            </div>

            <div class="modal-row-2">
              <div class="modal-field" style="flex:1;">
                <span class="modal-field-label">Пароль</span>
                <input v-model="form.password" :placeholder="userModal.user ? 'Не менять — оставить пустым' : 'Пароль'" />
              </div>
              <div class="modal-field" style="width:155px;flex-shrink:0;">
                <span class="modal-field-label">Роль</span>
                <select v-model="form.role">
                  <option value="user">Пользователь</option>
                  <option value="viewer">Читатель</option>
                  <option value="admin">Администратор</option>
                </select>
              </div>
            </div>

            <div class="modal-field">
              <span class="modal-field-label">Должность</span>
              <input v-model="form.display_role" placeholder="Менеджер, Руководитель и т.д." />
            </div>

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
          </div>

          <div style="display:flex;gap:8px;margin-top:20px;">
            <button class="btn primary" @click="saveUser" :disabled="saving">
              {{ saving ? 'Сохранение...' : (userModal.user ? 'Сохранить' : 'Создать') }}
            </button>
            <button class="btn secondary" @click="userModal.show = false">Отмена</button>
          </div>
        </div>
      </div>
    </Teleport>

    <ConfirmModal v-if="confirmModal.show" :title="confirmModal.title" :message="confirmModal.message"
      @confirm="confirmModal.resolve(true); confirmModal.show = false"
      @cancel="confirmModal.resolve(false); confirmModal.show = false" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { db } from '@/lib/apiClient.js';
import { useUserStore } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';

const userStore = useUserStore();
const toast = useToastStore();

const activeTab = ref('users');
const loading = ref(false);
const saving = ref(false);
const users = ref([]);

const allEntities = ['Бургер БК', 'Воглия Матта', 'Пицца Стар'];

const userModal = ref({ show: false, user: null });
const form = ref({ name: '', password: '', role: 'user', display_role: '', legal_entities: [] });
const confirmModal = ref({ show: false, title: '', message: '', resolve: null });

const maintenanceOn = ref(false);
const maintenanceSaving = ref(false);
const maintenanceMsg = ref('');
const maintenanceMsgSaving = ref(false);

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
  const map = { 'Бургер БК': 'БК', 'Воглия Матта': 'ВМ', 'Пицца Стар': 'ПС' };
  return map[le] || le;
}

function initials(name) {
  if (!name) return '?';
  return name.split(/\s+/).map(w => w[0]).join('').toUpperCase().slice(0, 2);
}

async function loadUsers() {
  loading.value = true;
  try {
    const { data } = await db.from('users').select('*').order('name');
    users.value = data || [];
  } catch { toast.error('Ошибка', 'Не удалось загрузить пользователей'); }
  finally { loading.value = false; }
}

async function loadSettings() {
  try {
    const { data } = await db.from('settings').select('*').or('key.eq.maintenance_mode,key.eq.maintenance_message');
    if (!data) return;
    for (const s of data) {
      if (s.key === 'maintenance_mode') maintenanceOn.value = s.value === 'true';
      if (s.key === 'maintenance_message') maintenanceMsg.value = s.value || '';
    }
  } catch { /* noop */ }
}

async function saveMaintenanceMsg() {
  maintenanceMsgSaving.value = true;
  try {
    // Попробуем обновить; если запись не существует — создадим
    const { data } = await db.from('settings').select('key').eq('key', 'maintenance_message').limit(1);
    if (data && data.length) {
      const { error } = await db.from('settings').update({ value: maintenanceMsg.value }).eq('key', 'maintenance_message');
      if (error) { toast.error('Ошибка', ''); return; }
    } else {
      const { error } = await db.from('settings').insert({ key: 'maintenance_message', value: maintenanceMsg.value });
      if (error) { toast.error('Ошибка', ''); return; }
    }
    toast.success('Сообщение сохранено', '');
  } finally { maintenanceMsgSaving.value = false; }
}

function openUserModal(user) {
  userModal.value.user = user;
  if (user) {
    form.value = {
      name: user.name || '',
      password: '',
      role: user.role || 'user',
      display_role: user.display_role || '',
      legal_entities: parseLe(user.legal_entities),
    };
  } else {
    form.value = { name: '', password: '', role: 'user', display_role: '', legal_entities: [] };
  }
  userModal.value.show = true;
}

async function saveUser() {
  if (!form.value.name.trim()) { toast.error('Введите имя', ''); return; }
  if (!userModal.value.user && !form.value.password) { toast.error('Введите пароль', ''); return; }
  saving.value = true;
  try {
    const payload = {
      name: form.value.name.trim(),
      role: form.value.role,
      display_role: form.value.display_role.trim() || null,
      legal_entities: JSON.stringify(form.value.legal_entities),
    };
    if (form.value.password) payload.password = form.value.password;

    if (userModal.value.user) {
      const { error } = await db.from('users').update(payload).eq('id', userModal.value.user.id);
      if (error) { toast.error('Ошибка', error.message || ''); return; }
      toast.success('Обновлено', payload.name);
    } else {
      const { error } = await db.from('users').insert([payload]);
      if (error) { toast.error('Ошибка', error.message || ''); return; }
      toast.success('Создано', payload.name);
    }
    userModal.value.show = false;
    await loadUsers();
  } finally { saving.value = false; }
}

async function deleteUser(u) {
  if (u.name === userStore.currentUser?.name) { toast.error('Нельзя удалить себя', ''); return; }
  const ok = await new Promise(r => {
    confirmModal.value = { show: true, title: 'Удалить пользователя?', message: `Пользователь «${u.name}» будет удалён безвозвратно.`, resolve: r };
  });
  if (!ok) return;
  const { error } = await db.from('users').delete().eq('id', u.id);
  if (error) { toast.error('Ошибка', ''); return; }
  toast.success('Удалено', u.name);
  await loadUsers();
}

async function toggleMaintenance() {
  maintenanceSaving.value = true;
  const newVal = !maintenanceOn.value;
  try {
    const { error } = await db.from('settings').update({ value: String(newVal) }).eq('key', 'maintenance_mode');
    if (error) { toast.error('Ошибка', ''); return; }
    maintenanceOn.value = newVal;
    userStore.maintenanceMode = newVal;
    toast.success(newVal ? 'Тех. работы включены' : 'Тех. работы выключены', '');
  } finally { maintenanceSaving.value = false; }
}

onMounted(() => { loadUsers(); loadSettings(); });
</script>

<style scoped>
/* ═══ Layout ═══ */
.admin-view { padding: 0; }
.adm-section { animation: admFade .2s ease; }
@keyframes admFade { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }

/* ═══ Tabs ═══ */
.adm-tabs {
  display: flex; gap: 0; margin-bottom: 20px;
  border-bottom: 2px solid var(--border-light);
}
.adm-tab {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 10px 22px; font-size: 14px; font-weight: 600; font-family: inherit;
  color: var(--text-muted); background: none; border: none;
  border-bottom: 2.5px solid transparent; margin-bottom: -2px;
  cursor: pointer; transition: all .15s; position: relative;
}
.adm-tab.active { color: var(--bk-brown); border-bottom-color: var(--bk-brown); }
.adm-tab:hover:not(.active) { color: var(--text); background: rgba(139,115,85,.04); }
.adm-tab-count {
  font-size: 11px; font-weight: 700; padding: 1px 7px;
  border-radius: 10px; background: var(--border-light); color: var(--text-muted);
}
.adm-tab-count.active { background: var(--bk-brown); color: #fff; }
.adm-tab-dot {
  width: 7px; height: 7px; border-radius: 50%; background: #D32F2F;
  position: absolute; top: 8px; right: 10px;
  animation: admPulse 2s infinite;
}
@keyframes admPulse { 0%, 100% { opacity: 1; } 50% { opacity: .4; } }

/* ═══ Toolbar ═══ */
.adm-toolbar {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 16px;
}
.adm-toolbar-info { font-size: 13px; color: var(--text-muted); font-weight: 500; }
.adm-empty { text-align: center; padding: 48px; color: var(--text-muted); font-size: 14px; }

/* ═══ User List ═══ */
.adm-user-list { display: flex; flex-direction: column; gap: 2px; }
.adm-user-row {
  display: flex; align-items: center; gap: 14px;
  padding: 10px 14px; border-radius: 10px;
  background: var(--card); border: 1.5px solid transparent;
  cursor: pointer; transition: all .15s;
}
.adm-user-row:hover { border-color: var(--bk-orange); box-shadow: 0 2px 8px rgba(245,166,35,.08); }

.adm-user-avatar {
  width: 40px; height: 40px; border-radius: 12px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; font-weight: 700; color: #fff;
  background: linear-gradient(135deg, #F5A623, #E8941A);
}
.adm-user-avatar.admin { background: linear-gradient(135deg, #E53935, #C62828); }

.adm-user-info { flex: 1; min-width: 0; }
.adm-user-name {
  font-size: 14px; font-weight: 600; color: var(--text);
  display: flex; align-items: center; gap: 6px;
}
.adm-user-meta { font-size: 12px; color: var(--text-muted); margin-top: 1px; }

.adm-badge {
  display: inline-block; padding: 1px 7px; border-radius: 4px;
  font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px;
}
.adm-badge-admin { background: #FFEBEE; color: #C62828; }
.adm-badge-viewer { background: #E3F2FD; color: #1565C0; }
.adm-badge-you { background: #E8F5E9; color: #2E7D32; }

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
.adm-act-btn:disabled { opacity: .3; pointer-events: none; }

/* ═══ Maintenance ═══ */
.adm-maint-card {
  display: flex; align-items: center; gap: 20px;
  padding: 24px; border-radius: 14px;
  background: var(--card); border: 2px solid var(--border-light);
  transition: all .3s;
}
.adm-maint-card.on { border-color: #FFCDD2; background: #FFFAFA; }

.adm-maint-icon { flex-shrink: 0; }
.adm-maint-body { flex: 1; }
.adm-maint-title { margin: 0 0 4px; font-size: 16px; font-weight: 700; color: var(--text); }
.adm-maint-desc { margin: 0; font-size: 13px; color: var(--text-muted); line-height: 1.5; }

.adm-maint-toggle {
  display: flex; flex-direction: column; align-items: center; gap: 6px;
  background: none; border: none; cursor: pointer; padding: 8px; flex-shrink: 0;
  font-family: inherit;
}
.adm-maint-track {
  position: relative; width: 52px; height: 28px; border-radius: 14px;
  background: var(--border); transition: background .25s;
}
.adm-maint-toggle.on .adm-maint-track { background: #D32F2F; }
.adm-maint-thumb {
  position: absolute; top: 3px; left: 3px;
  width: 22px; height: 22px; border-radius: 50%;
  background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,.18);
  transition: left .25s;
}
.adm-maint-toggle.on .adm-maint-thumb { left: 27px; }
.adm-maint-label {
  font-size: 11px; font-weight: 600;
  color: var(--text-muted); transition: color .2s;
}
.adm-maint-toggle.on .adm-maint-label { color: #D32F2F; }

.adm-maint-warning {
  display: flex; align-items: center; gap: 8px;
  margin-top: 12px; padding: 12px 16px; border-radius: 10px;
  background: #FFF3F3; border: 1.5px solid #FFCDD2;
  font-size: 13px; color: #C62828; font-weight: 500;
  animation: admFade .3s ease;
}

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

/* ═══ Maintenance Message ═══ */
.adm-maint-msg-card {
  margin-top: 16px; padding: 20px; border-radius: 14px;
  background: var(--card); border: 1.5px solid var(--border-light);
}
.adm-maint-msg-title { margin: 0 0 4px; font-size: 14px; font-weight: 700; color: var(--text); }
.adm-maint-msg-hint { margin: 0 0 10px; font-size: 12px; color: var(--text-muted); }
.adm-maint-textarea {
  width: 100%; padding: 10px 14px; border: 1.5px solid var(--border); border-radius: 8px;
  font-size: 13px; font-family: inherit; resize: vertical;
  transition: border-color .15s; box-sizing: border-box;
  background: var(--bg);
}
.adm-maint-textarea:focus { border-color: var(--bk-orange); outline: none; box-shadow: 0 0 0 3px rgba(245,166,35,.1); }

/* ═══ Responsive ═══ */
@media (max-width: 600px) {
  .adm-user-entities { display: none; }
  .adm-user-actions { opacity: 1; }
  .adm-maint-card { flex-direction: column; text-align: center; }
}
</style>
