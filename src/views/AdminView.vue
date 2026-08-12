<template>
  <div class="admin-view">
    <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;">
      <h1 class="page-title"><BkIcon name="gear" size="sm"/> Администрирование</h1>
    </div>

    <!-- Табы -->
    <div class="adm-tabs">
      <button class="adm-tab" :class="{ active: activeTab === 'users' }" @click="activeTab = 'users'">
        <BkIcon name="user" size="sm"/> Сотрудники <span class="adm-tab-count" :class="{ active: activeTab === 'users' }">{{ usersCount || '' }}</span>
      </button>
      <button class="adm-tab" :class="{ active: activeTab === 'restaurant-accounts' }" @click="activeTab = 'restaurant-accounts'">
        <BkIcon name="user" size="sm"/> Кабинеты ресторанов
      </button>
      <button class="adm-tab" :class="{ active: activeTab === 'email-imports' }" @click="activeTab = 'email-imports'">
        <BkIcon name="mail" size="sm"/> Импорт по email
      </button>
      <button class="adm-tab" :class="{ active: activeTab === 'maintenance' }" @click="activeTab = 'maintenance'">
        <BkIcon name="warning" size="sm"/> Тех. работы
        <span v-if="maintenanceOn" class="adm-tab-dot"></span>
      </button>
      <button class="adm-tab" :class="{ active: activeTab === 'broadcast' }" @click="activeTab = 'broadcast'">
        <BkIcon name="bell" size="sm"/> Рассылка
      </button>
      <button class="adm-tab" :class="{ active: activeTab === 'audit' }" @click="activeTab = 'audit'">
        <BkIcon name="note" size="sm"/> Журнал
        <span class="adm-tab-count" :class="{ active: activeTab === 'audit' }">{{ auditTotal || '' }}</span>
      </button>
      <button class="adm-tab" :class="{ active: activeTab === 'stats' }" @click="activeTab = 'stats'">
        <BkIcon name="analytics" size="sm"/> Статистика
      </button>
      <button class="adm-tab" :class="{ active: activeTab === 'backup' }" @click="activeTab = 'backup'">
        <BkIcon name="database" size="sm"/> Бэкап
      </button>
      <button class="adm-tab" :class="{ active: activeTab === 'sessions' }" @click="activeTab = 'sessions'">
        <BkIcon name="key" size="sm"/> Сессии
        <span class="adm-tab-count" :class="{ active: activeTab === 'sessions' }">{{ onlineCount }}</span>
      </button>
      <button class="adm-tab" :class="{ active: activeTab === 'feedback' }" @click="activeTab = 'feedback'">
        <BkIcon name="feedback" size="sm"/> Обращения
        <span v-if="bugNewCount" class="adm-tab-dot"></span>
        <span class="adm-tab-count" :class="{ active: activeTab === 'feedback' }">{{ bugCount || '' }}</span>
      </button>
      <button class="adm-tab" :class="{ active: activeTab === 'cron-reminders' }" @click="activeTab = 'cron-reminders'">
        <BkIcon name="bell" size="sm"/> Крон напоминаний
        <span v-if="cronErrCount" class="adm-tab-dot"></span>
      </button>
      <button class="adm-tab" :class="{ active: activeTab === 'bot-monitor' }" @click="activeTab = 'bot-monitor'">
        <BkIcon name="analytics" size="sm"/> Бот-монитор
      </button>
    </div>

    <!-- ═══ Пользователи ═══ -->
    <!-- ═══ Сотрудники ═══ -->
    <AdminUsersTab v-if="activeTab === 'users'" @count="usersCount = $event" />


    <!-- ═══ Кабинеты ресторанов ═══ -->
    <div v-if="activeTab === 'restaurant-accounts'" class="adm-section">
      <AdminRestaurantAccountsTab />
    </div>

    <div v-if="activeTab === 'email-imports'" class="adm-section">
      <AdminEmailImportsTab />
    </div>

    <!-- ═══ Тех. работы ═══ -->
    <!-- ═══ Тех. работы ═══ -->
    <AdminMaintenanceTab v-if="activeTab === 'maintenance'" @state="maintenanceOn = $event" />

    <!-- ═══ Рассылка ═══ -->
    <!-- ═══ Рассылка ═══ -->
    <AdminBroadcastTab v-if="activeTab === 'broadcast'" ref="broadcastTab" @open-changelog="openChangelogModal" />

    <!-- ═══ Журнал ═══ -->
    <AdminAuditTab v-if="activeTab === 'audit'" @total="auditTotal = $event" />

    <!-- ═══ Статистика ═══ -->
    <AdminStatsTab v-if="activeTab === 'stats'" />

    <!-- ═══ Бэкап ═══ -->
    <AdminBackupTab v-if="activeTab === 'backup'" />

    <!-- ═══ Сессии ═══ -->
    <AdminSessionsTab v-if="activeTab === 'sessions'" @online-count="onlineCount = $event" />

    <!-- ═══ Обращения — мессенджер ═══ -->
    <!-- ═══ Обращения ═══ -->
    <AdminFeedbackTab v-if="activeTab === 'feedback'" @count="bugCount = $event" />

    <AdminCronRemindersTab v-if="activeTab === 'cron-reminders'" @err-count="cronErrCount = $event" />

    <div v-if="activeTab === 'bot-monitor'" class="adm-section">
      <AdminBotMonitorTab />
    </div>

    <!-- ═══ Модалка обновления (changelog) ═══ -->
    <Teleport to="body">
      <div v-if="changelogModal.show" class="modal" @click.self="tryCloseChangelog">
        <div class="modal-box" style="width:460px;">
          <div class="modal-header">
            <h2>{{ changelogModal.entry ? 'Редактировать' : 'Новое обновление' }}</h2>
            <button class="modal-close" @click="tryCloseChangelog"><BkIcon name="close" size="sm"/></button>
          </div>
          <div class="adm-form">
            <div class="modal-field">
              <span class="modal-field-label">Версия</span>
              <input v-model="changelogForm.version" placeholder="1.0.0" />
            </div>
            <div class="modal-field">
              <span class="modal-field-label">Заголовок</span>
              <input v-model="changelogForm.title" placeholder="Что нового" />
            </div>
            <div class="modal-field">
              <span class="modal-field-label">Описание</span>
              <textarea v-model="changelogForm.description" class="adm-maint-textarea" rows="5" placeholder="Подробное описание изменений..."></textarea>
            </div>
          </div>
          <div style="display:flex;gap:8px;margin-top:20px;">
            <button class="btn primary" @click="saveChangelog" :disabled="changelogSaving || !changelogForm.version.trim() || !changelogForm.title.trim()">
              <BurgerSpinner v-if="changelogSaving" size="xs" />
              <span>{{ changelogSaving ? 'Сохранение...' : (changelogModal.entry ? 'Сохранить' : 'Создать') }}</span>
            </button>
            <button class="btn secondary" @click="tryCloseChangelog">Отмена</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ═══ Модалка пользователя ═══ -->

    <ConfirmModal v-if="confirmModal.show" :title="confirmModal.title" :message="confirmModal.message"
      @confirm="onConfirmOk"
      @cancel="onConfirmCancel" />
  </div>
</template>

<script setup>
import { ref, reactive, computed, defineAsyncComponent, nextTick, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useTabRoute } from '@/composables/useTabRoute.js';
import { appConfirm } from '@/lib/appDialogs.js';
import { db } from '@/lib/apiClient.js';
import { formatMoscowDateTime, parseMoscowDate, plural } from '@/lib/utils.js';
import { useUserStore, ROLE_TEMPLATES, MODULES, MODULE_LABELS, loadRbacConfig } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { LEGAL_ENTITIES, ENTITY_SHORT_NAMES, formatRestaurantNumber } from '@/lib/legalEntities.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import UiPasswordInput from '@/components/ui/UiPasswordInput.vue';
import UiEmptyState from '@/components/ui/UiEmptyState.vue';

const router = useRouter();

import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import AdminRestaurantAccountsTab from '@/components/admin/AdminRestaurantAccountsTab.vue';
import AdminEmailImportsTab from '@/components/admin/AdminEmailImportsTab.vue';
import AdminBotMonitorTab from '@/components/admin/AdminBotMonitorTab.vue';
import AdminSessionsTab from '@/components/admin/AdminSessionsTab.vue';
import AdminBackupTab from '@/components/admin/AdminBackupTab.vue';
import AdminCronRemindersTab from '@/components/admin/AdminCronRemindersTab.vue';
import AdminMaintenanceTab from '@/components/admin/AdminMaintenanceTab.vue';
import AdminStatsTab from '@/components/admin/AdminStatsTab.vue';
import AdminBroadcastTab from '@/components/admin/AdminBroadcastTab.vue';
import AdminAuditTab from '@/components/admin/AdminAuditTab.vue';
import AdminFeedbackTab from '@/components/admin/AdminFeedbackTab.vue';
import AdminUsersTab from '@/components/admin/AdminUsersTab.vue';
import { useConfirm } from '@/composables/useConfirm.js';

const ConfirmModal = defineAsyncComponent(() => import('@/components/modals/ConfirmModal.vue'));

const userStore = useUserStore();
const toast = useToastStore();

const activeTab = useTabRoute('users', ['users', 'restaurant-accounts', 'email-imports', 'sessions', 'audit', 'feedback', 'broadcast', 'stats', 'backup', 'maintenance', 'cron-reminders', 'bot-monitor']);
// Подтверждения нужны редактору списка обновлений.
const { confirmModal, confirm: confirmAction, onConfirm: onConfirmOk, onCancel: onConfirmCancel } = useConfirm();

// Счётчик сотрудников — присылает сама вкладка.
const usersCount = ref(0);

// Точка «идут техработы» у вкладки: состояние присылает сама вкладка,
// а до её открытия спрашиваем настройку сразу.
const maintenanceOn = ref(false);
async function loadMaintenanceFlag() {
  try {
    const { data } = await db.from('settings').select('*').eq('key', 'maintenance_mode');
    const row = (data || [])[0];
    if (row) maintenanceOn.value = row.value === 'true';
  } catch (e) { console.warn('[admin] maintenance flag:', e); }
}

// Роль проверяем ещё раз на сервере: в localStorage её могли подменить.
onMounted(() => {
  if (userStore.currentUser?.role !== 'admin') return;
  loadRbacConfig();
  loadMaintenanceFlag();
});

watch(() => userStore.currentUser?.role, (role) => {
  if (role && role !== 'admin') router.replace({ name: 'order' });
});

// ═══ Настройки системы ═══
const sysSettings = ref([]);
const sysSettingsLoading = ref(false);

const SETTINGS_CATEGORIES = {
  'maintenance_mode': 'Система', 'maintenance_message': 'Система', 'maintenance_end_time': 'Система',
  'last_update': 'Данные',
};

const sysSettingsGrouped = computed(() => {
  const groups = {};
  for (const s of sysSettings.value) {
    const cat = SETTINGS_CATEGORIES[s.key] || 'Прочее';
    if (!groups[cat]) groups[cat] = [];
    groups[cat].push(s);
  }
  return Object.entries(groups).map(([name, items]) => ({ name, items }));
});

async function loadSysSettings() {
  sysSettingsLoading.value = true;
  try {
    const { data } = await db.from('settings').select('*').order('key');
    sysSettings.value = (data || []).map(s => ({ ...s, _editValue: s.value || '', _changed: false, _saving: false }));
  } catch { toast.error('Ошибка', 'Не удалось загрузить настройки'); }
  finally { sysSettingsLoading.value = false; }
}

async function saveSysSetting(s) {
  s._saving = true;
  try {
    const { error } = await db.from('settings').update({ value: s._editValue }).eq('key', s.key);
    if (error) { toast.error('Ошибка', ''); return; }
    s.value = s._editValue;
    s._changed = false;
    toast.success('Сохранено', s.key);
  } catch { toast.error('Ошибка', 'Не удалось сохранить'); }
  finally { s._saving = false; }
}

// ═══ Обновления (Changelog) ═══
const changelogEntries = ref([]);
const changelogLoading = ref(false);
const changelogSaving = ref(false);
const changelogModal = ref({ show: false, entry: null });
const changelogForm = ref({ version: '', title: '', description: '' });
let _changelogFormSnapshot = '';

function tryCloseChangelog() {
  if (JSON.stringify(changelogForm.value) !== _changelogFormSnapshot) {
    confirmAction('Закрыть без сохранения?', 'Введённые данные будут потеряны.').then(ok => {
      if (ok) changelogModal.value.show = false;
    });
    return;
  }
  changelogModal.value.show = false;
}

async function loadChangelog() {
  changelogLoading.value = true;
  try {
    const { data } = await db.rpc('get_changelog');
    changelogEntries.value = data || [];
  } catch { toast.error('Ошибка', 'Не удалось загрузить обновления'); }
  finally { changelogLoading.value = false; }
}

function openChangelogModal(entry) {
  changelogModal.value.entry = entry;
  if (entry) {
    changelogForm.value = { version: entry.version, title: entry.title, description: entry.description || '' };
  } else {
    changelogForm.value = { version: '', title: '', description: '' };
  }
  changelogModal.value.show = true;
  _changelogFormSnapshot = JSON.stringify(changelogForm.value);
}

async function saveChangelog() {
  if (!changelogForm.value.version.trim() || !changelogForm.value.title.trim()) return;
  changelogSaving.value = true;
  try {
    const payload = {
      version: changelogForm.value.version.trim(),
      title: changelogForm.value.title.trim(),
      description: changelogForm.value.description.trim() || null,
    };
    if (changelogModal.value.entry) {
      const { error } = await db.from('changelog').update(payload).eq('id', changelogModal.value.entry.id);
      if (error) { toast.error('Ошибка', ''); return; }
      toast.success('Обновлено', payload.title);
    } else {
      payload.created_by = userStore.currentUser?.name || '';
      const { error } = await db.from('changelog').insert(payload);
      if (error) { toast.error('Ошибка', ''); return; }
      toast.success('Создано', payload.title);
    }
    changelogModal.value.show = false;
    await loadChangelog();
  } catch { toast.error('Ошибка', 'Не удалось сохранить'); }
  finally { changelogSaving.value = false; }
}

async function deleteChangelog(entry) {
  const ok = await confirmAction('Удалить запись?', `Обновление «${entry.title}» будет удалено.`);
  if (!ok) return;
  try {
    const { error } = await db.from('changelog').delete().eq('id', entry.id);
    if (error) { toast.error('Ошибка', ''); return; }
    toast.success('Удалено', entry.title);
    changelogEntries.value = changelogEntries.value.filter(e => e.id !== entry.id);
  } catch { toast.error('Ошибка', 'Не удалось удалить'); }
}

// Красная точка у вкладки «Крон напоминаний» должна гореть ещё до того, как
// её откроют, поэтому ошибки за сутки считаем сразу. Дальше счётчик присылает
// сама вкладка.
onMounted(async () => {
  try {
    const res = await db.from('reminder_cron_log')
      .select('status,started_at')
      .order('started_at', { ascending: false })
      .limit(50);
    if (res.data) {
      const dayAgo = Date.now() - 24 * 60 * 60 * 1000;
      cronErrCount.value = res.data.filter(r => {
        const d = parseMoscowDate(r.started_at);
        return r.status === 'error' && d && d.getTime() > dayAgo;
      }).length;
    }
  } catch (e) { /* молча: это только подсветка вкладки */ }
});

// Счётчики обращений для вкладки: общее число присылает сама вкладка,
// число новых спрашиваем сразу — красная точка должна гореть до открытия.
const bugCount = ref(0);
const bugNewCount = ref(0);

onMounted(() => {
  db.rpc('get_bug_reports_count', {}).then(({ data }) => {
    if (data) bugNewCount.value = data.new_count || 0;
  }).catch(() => {});
});


</script>

<style scoped>

/* ═══ Layout ═══ */
.admin-view { padding: 0; }
.adm-section { animation: admFade .2s ease; }
@keyframes admFade { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }

/* ═══ Tabs ═══ */
.adm-tabs {
  display: flex; flex-wrap: wrap; gap: 0; margin-bottom: 20px;
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
.adm-maint-textarea:focus { border-color: var(--bk-orange); outline: none; box-shadow: 0 0 0 3px rgba(244,162,97,.1); }

/* Timer */

/* ═══ Online ═══ */
.adm-avatar-online {
  position: relative;
}
.adm-online-dot {
  position: absolute; bottom: -1px; right: -1px;
  width: 11px; height: 11px; border-radius: 50%;
  background: #4CAF50; border: 2px solid var(--card);
}
.adm-online-time {
  font-size: 12px; color: var(--text-muted); flex-shrink: 0; white-space: nowrap;
}

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

/* ═══ Stats Cards ═══ */




/* ═══ System Settings ═══ */
.adm-settings-list { display: flex; flex-direction: column; gap: 6px; }
.adm-setting-row {
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
  padding: 6px 0; border-bottom: 1px solid var(--border-light);
}
.adm-setting-row:last-child { border-bottom: none; }
.adm-setting-key { font-size: 13px; font-weight: 600; color: var(--text); min-width: 180px; }
.adm-setting-input-wrap { display: flex; gap: 6px; flex: 1; align-items: center; }
.adm-setting-input {
  flex: 1; padding: 6px 10px; border: 1.5px solid var(--border); border-radius: 6px;
  font-size: 13px; font-family: inherit; background: var(--bg); min-width: 0;
}
.adm-setting-input:focus { border-color: var(--bk-orange); outline: none; }
.adm-setting-save-btn { font-size: 12px !important; padding: 5px 12px !important; flex-shrink: 0; }

/* ═══ Changelog ═══ */
.adm-changelog-version {
  display: inline-block; padding: 1px 8px; border-radius: 10px;
  font-size: 11px; font-weight: 700;
  background: #E8F5E9; color: #2E7D32;
}
.adm-changelog-title { font-size: 14px; font-weight: 600; color: var(--text); }
.adm-changelog-desc {
  font-size: 13px; color: var(--text-secondary); margin-top: 4px;
  line-height: 1.5; white-space: pre-line;
}
.adm-changelog-meta {
  display: flex; align-items: center; justify-content: space-between;
  margin-top: 6px;
}

/* ═══ Permissions Matrix ═══ */
.perm-matrix { border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
.perm-row { display: grid; grid-template-columns: 1fr 90px 90px 120px; align-items: center; padding: 6px 12px; border-bottom: 1px solid var(--border); font-size: 12px; }
.perm-row:last-child { border-bottom: none; }
.perm-header { background: var(--card); font-weight: 700; font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
.perm-module { font-weight: 600; }
.perm-level { text-align: center; font-size: 11px; }
.perm-base { color: var(--text-muted); }
.perm-lvl-full { color: #D32F2F; font-weight: 600; }
.perm-lvl-edit { color: #F57C00; font-weight: 600; }
.perm-lvl-view { color: #1976D2; }
.perm-lvl-none { color: var(--text-muted); opacity: 0.5; }
.perm-select { padding: 3px 6px; border: 1px solid var(--border); border-radius: 6px; font-size: 11px; font-family: inherit; background: var(--card); }

/* ═══ Bug Reports ═══ */
.bug-filter-select {
  padding: 5px 10px;
  border: 1px solid var(--border);
  border-radius: 8px;
  font-size: 12px;
  font-family: inherit;
  background: var(--card);
}
.adm-bug-row { cursor: pointer; }
.adm-bug-row:hover { background: rgba(231,111,81,0.02); }
.adm-bug-status-col { flex-shrink: 0; width: 90px; }
.adm-bug-status {
  font-size: 10px;
  font-weight: 600;
  padding: 3px 8px;
  border-radius: 8px;
  display: inline-block;
  white-space: nowrap;
}
.adm-bug-status.st-new { background: #FFF3E0; color: #E65100; }
.adm-bug-status.st-in_progress { background: #E3F2FD; color: #1565C0; }
.adm-bug-status.st-resolved { background: #E8F5E9; color: #2E7D32; }
.adm-bug-status.st-closed { background: #F5F5F5; color: #757575; }
.adm-bug-thumbs {
  display: flex;
  gap: 4px;
  align-items: center;
  flex-shrink: 0;
}
.adm-bug-thumb {
  width: 36px;
  height: 36px;
  object-fit: cover;
  border-radius: 6px;
  border: 1px solid var(--border);
}
.adm-bug-more {
  font-size: 10px;
  color: var(--text-muted);
  font-weight: 600;
}
.bug-reply-input {
  width: 100%;
  padding: 8px 12px;
  border: 1.5px solid var(--border);
  border-radius: 8px;
  font-size: 13px;
  font-family: inherit;
  resize: vertical;
  min-height: 40px;
}
.bug-reply-input:focus {
  outline: none;
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(231,111,81,0.08);
}

/* ═══ Feedback messenger ═══ */
.fb-messenger {
  display: flex;
  gap: 0;
  height: calc(100vh - 160px);
  min-height: 400px;
  border: 1px solid var(--border);
  border-radius: 12px;
  overflow: hidden;
  background: var(--card);
}
.fb-sidebar {
  width: 320px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  border-right: 1px solid var(--border-light);
  background: var(--bg);
}
.fb-sidebar-top {
  display: flex;
  gap: 6px;
  padding: 10px 12px;
  border-bottom: 1px solid var(--border-light);
  flex-shrink: 0;
}
.fb-list {
  flex: 1;
  overflow-y: auto;
}
.fb-item {
  padding: 10px 14px;
  cursor: pointer;
  border-bottom: 1px solid var(--border-light);
  transition: background 0.1s;
}
.fb-item:hover { background: rgba(0,0,0,0.03); }
.fb-item.active { background: var(--card); border-left: 3px solid var(--accent); }
.fb-item.is-new { border-left: 3px solid #E65100; }
.fb-item.active.is-new { border-left-color: var(--accent); }
.fb-item-top {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 3px;
}
.fb-item-status {
  width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
}
.fb-item-status.st-new { background: #E65100; }
.fb-item-status.st-in_progress { background: #1565C0; }
.fb-item-status.st-resolved { background: #2E7D32; }
.fb-item-status.st-closed { background: #9E9E9E; }
.fb-item-author { font-size: 11px; font-weight: 600; color: var(--text-secondary); }
.fb-item-date { font-size: 10px; color: var(--text-muted); margin-left: auto; }
.fb-item-title {
  font-size: 13px; font-weight: 600; color: var(--text);
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.fb-item-bottom {
  display: flex; gap: 8px; margin-top: 2px;
  font-size: 10px; color: var(--text-muted);
}

/* Chat panel */
.fb-chat {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.fb-chat-empty {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  color: var(--text-muted);
  font-size: 13px;
}
.fb-chat-header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  border-bottom: 1px solid var(--border-light);
  flex-shrink: 0;
}
.fb-chat-title {
  font-size: 14px; font-weight: 600;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.fb-del-btn {
  background: none; border: none; cursor: pointer; color: var(--text-muted);
  padding: 4px; border-radius: 6px; transition: 0.15s;
}
.fb-del-btn:hover { color: var(--error); background: rgba(211,47,47,0.08); }
.fb-chat-info {
  padding: 0 16px;
  border-bottom: 1px solid var(--border-light);
  font-size: 12px;
  color: var(--text-muted);
  flex-shrink: 0;
}
.fb-chat-info summary { padding: 8px 0; cursor: pointer; font-weight: 600; }
.fb-chat-info-body { padding: 0 0 10px; }
.fb-chat-messages {
  flex: 1;
  overflow-y: auto;
  padding: 12px 16px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.fb-msg {
  padding: 8px 12px;
  border-radius: 12px;
  background: var(--bg);
  max-width: 80%;
  align-self: flex-start;
}
.fb-msg.admin {
  background: #e8f5e9;
  align-self: flex-end;
}
.fb-msg-meta {
  display: flex; justify-content: space-between; gap: 8px;
  margin-bottom: 2px; font-size: 10px; font-weight: 600;
  color: var(--text-secondary);
}
.fb-msg-meta span:last-child { color: var(--text-muted); font-weight: 400; }
.fb-msg-text { font-size: 13px; white-space: pre-wrap; line-height: 1.45; }
.fb-msg-img { max-width: 200px; border-radius: 8px; margin-top: 4px; cursor: pointer; }
.fb-chat-input {
  display: flex; gap: 8px;
  padding: 10px 16px;
  border-top: 1px solid var(--border-light);
  flex-shrink: 0;
  align-items: flex-end;
}
.fb-chat-input textarea { flex: 1; resize: none; min-height: 36px; max-height: 100px; }

@media (max-width: 768px) {
  .fb-messenger { flex-direction: column; height: auto; min-height: unset; }
  .fb-sidebar { width: 100%; max-height: 300px; border-right: none; border-bottom: 1px solid var(--border-light); }
  .fb-chat { min-height: 400px; }
}
.adm-sup-le { color: var(--text-muted); font-size: 11px; margin-left: 4px; }
</style>
