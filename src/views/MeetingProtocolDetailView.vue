<template>
  <div class="mpd-view">
    <div class="mpd-top-bar">
      <div class="mpd-top-left">
        <button class="mpd-back" @click="goBack">&larr; К списку</button>
        <span v-if="dirty" class="mpd-unsaved">Не сохранено</span>
      </div>
      <div class="mpd-top-actions">
        <button v-if="canEdit" class="mpd-btn mpd-btn-primary" @click="save" :disabled="saving">{{ saving ? 'Сохранение...' : 'Сохранить' }}</button>
        <button v-if="canEdit && protocol.status === 'draft'" class="mpd-btn mpd-btn-finalize" @click="finalize">Финализировать</button>
        <button v-if="protocol.id" class="mpd-btn mpd-btn-export" @click="exportExcel">Excel</button>
        <button v-if="protocol.id" class="mpd-btn mpd-btn-export" @click="exportPdf">PDF</button>
        <button v-if="canDelete" class="mpd-btn mpd-btn-danger" @click="deleteProtocol">Удалить</button>
      </div>
    </div>

    <div v-if="loading" class="mpd-loading">Загрузка...</div>

    <div v-else class="mpd-content">
      <!-- Основная информация -->
      <div class="mpd-section">
        <div class="mpd-row">
          <div class="mpd-field">
            <label>Дата совещания</label>
            <input type="date" v-model="protocol.meeting_date" :disabled="!canEdit" class="mpd-input">
          </div>
          <div class="mpd-field">
            <label>Статус</label>
            <select v-model="protocol.status" :disabled="!canEdit" class="mpd-input">
              <option value="draft">Черновик</option>
              <option value="final">Финальный</option>
            </select>
          </div>
          <div class="mpd-field">
            <label>Формат</label>
            <select v-model="protocol.series_id" :disabled="!canEdit" class="mpd-input" @change="onSeriesChange">
              <option :value="null">— Разовое совещание —</option>
              <option v-for="s in seriesList" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
          </div>
        </div>
        <div class="mpd-field">
          <label>Тема совещания</label>
          <input v-model="protocol.topic" :disabled="!canEdit" class="mpd-input" placeholder="О чём совещание?">
        </div>
      </div>

      <!-- Участники -->
      <div class="mpd-section">
        <h3>Участники</h3>
        <div class="mpd-participants">
          <div v-for="(name, i) in protocol.participants" :key="i" class="mpd-participant-tag">
            {{ name }}
            <button v-if="canEdit" class="mpd-tag-remove" @click="protocol.participants.splice(i, 1)">&times;</button>
          </div>
          <div v-if="canEdit && availableUsers.length" class="mpd-add-wrap">
            <button class="mpd-add-btn" @click="showUserPicker = !showUserPicker">+</button>
            <div v-if="showUserPicker" class="mpd-picker">
              <div v-for="u in availableUsers" :key="u.name" class="mpd-picker-item" @click="pickUser(u.name)">
                <span class="mpd-picker-name">{{ u.name }}</span>
                <span v-if="u.display_role" class="mpd-picker-role">{{ u.display_role }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Обсуждённые вопросы -->
      <div class="mpd-section">
        <h3>Обсуждённые вопросы</h3>
        <textarea v-model="protocol.questions" :disabled="!canEdit" class="mpd-textarea mpd-auto-grow" rows="3" placeholder="Что обсуждалось на совещании..." @input="autoResize($event)" ref="questionsRef"></textarea>
      </div>

      <!-- Перенесённые задачи из предыдущих протоколов -->
      <div v-if="carryoverTasks.length" class="mpd-section mpd-section-carryover">
        <h3 class="mpd-carryover-title">Незакрытые задачи из предыдущих совещаний <span class="mpd-count">({{ carryoverTasks.length }})</span></h3>
        <table class="mpd-tasks-table">
          <thead>
            <tr>
              <th class="mpd-th-num">№</th>
              <th>Задача</th>
              <th class="mpd-th-resp">Ответственный</th>
              <th class="mpd-th-date">Срок</th>
              <th class="mpd-th-status">Статус</th>
              <th class="mpd-th-source">Из протокола</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(t, i) in carryoverTasks" :key="'co-' + t.id" :class="['mpd-tr-' + t.status, { 'mpd-tr-mine': isMyTask(t) }]">
              <td class="mpd-td-num">{{ i + 1 }}</td>
              <td class="mpd-td-text">{{ t.text }}</td>
              <td>{{ formatResponsible(t.responsible_person) }}</td>
              <td>{{ fmtShortDate(t.deadline) }}</td>
              <td><select v-model="t.status" class="mpd-cell-input mpd-cell-status" :class="'mpd-st-' + t.status" @change="onCarryoverStatusChange(t)">
                <option value="pending">В работе</option>
                <option value="done">Выполнено</option>
                <option value="overdue">Просрочено</option>
              </select></td>
              <td class="mpd-td-source">{{ fmtShortDate(t.meeting_date) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Задачи -->
      <div class="mpd-section">
        <h3>Задачи <span class="mpd-count">({{ protocol.decisions.length }})</span></h3>
        <table v-if="protocol.decisions.length" class="mpd-tasks-table">
          <thead>
            <tr>
              <th class="mpd-th-num">№</th>
              <th>Задача</th>
              <th class="mpd-th-resp">Ответственный</th>
              <th class="mpd-th-date">Срок</th>
              <th class="mpd-th-status">Статус</th>
              <th v-if="canEdit" class="mpd-th-del"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(dec, i) in protocol.decisions" :key="dec.id || ('new-' + i)" :class="['mpd-tr-' + dec.status, { 'mpd-tr-mine': isMyTask(dec) }]">
              <td class="mpd-td-num">{{ i + 1 }}</td>
              <td><textarea v-model="dec.text" :disabled="!canEdit" class="mpd-cell-input mpd-cell-text" rows="1" placeholder="Текст задачи" @input="autoResize($event)"></textarea></td>
              <td class="mpd-td-resp">
                <div class="mpd-multi-select" v-if="canEdit">
                  <div class="mpd-multi-tags" @click="toggleResponsiblePicker(dec)">
                    <span v-for="name in dec.responsible_person" :key="name" class="mpd-resp-tag">{{ name }} <span class="mpd-resp-tag-x" @click.stop="removeResponsible(dec, name)">&times;</span></span>
                    <span v-if="!dec.responsible_person.length" class="mpd-resp-placeholder">Выбрать...</span>
                  </div>
                  <div v-if="openResponsiblePicker === dec" class="mpd-resp-dropdown">
                    <div v-for="name in protocol.participants" :key="name" class="mpd-resp-option" @click="toggleResponsible(dec, name)">
                      <span class="mpd-resp-check">{{ dec.responsible_person.includes(name) ? '☑' : '☐' }}</span> {{ name }}
                    </div>
                    <div v-if="!protocol.participants.length" class="mpd-resp-empty">Нет участников</div>
                  </div>
                </div>
                <span v-else>{{ dec.responsible_person.join(', ') }}</span>
              </td>
              <td><input type="date" v-model="dec.deadline" :disabled="!canEdit" class="mpd-cell-input"></td>
              <td><select v-model="dec.status" class="mpd-cell-input mpd-cell-status" :class="'mpd-st-' + dec.status" :disabled="!canEdit && !isMyTask(dec)" @change="onDecisionStatusChange(dec)">
                <option value="pending">В работе</option>
                <option value="done">Выполнено</option>
                <option value="overdue">Просрочено</option>
              </select></td>
              <td v-if="canEdit" class="mpd-td-del"><button class="mpd-row-del" @click="protocol.decisions.splice(i, 1)">&times;</button></td>
            </tr>
          </tbody>
        </table>
        <button v-if="canEdit" class="mpd-btn mpd-btn-add" @click="addDecision">+ Добавить задачу</button>
      </div>

      <!-- Файлы -->
      <div v-if="protocol.id" class="mpd-section">
        <h3>Файлы <span class="mpd-count">({{ files.length }})</span></h3>
        <div v-if="files.length" class="mpd-files">
          <div v-for="f in files" :key="f.id" class="mpd-file">
            <a :href="'/api/uploads/protocols/' + f.file_path + '?token=' + sessionToken" target="_blank" class="mpd-file-name">{{ f.file_name }}</a>
            <span class="mpd-file-meta">{{ f.uploaded_by }}</span>
            <button v-if="canEdit" class="mpd-row-del" @click="deleteFile(f)" title="Удалить файл">&times;</button>
          </div>
        </div>
        <div v-else class="mpd-files-empty">Нет прикреплённых файлов</div>
        <label v-if="canEdit" class="mpd-btn mpd-btn-upload">
          {{ uploading ? 'Загрузка...' : '+ Прикрепить файл' }}
          <input type="file" hidden @change="uploadFile" :disabled="uploading" accept=".pdf,.jpg,.jpeg,.png,.webp,.xlsx,.xls,.docx,.doc,.txt">
        </label>
      </div>

      <!-- Заметки -->
      <div class="mpd-section">
        <h3>Заметки</h3>
        <textarea v-model="protocol.notes" :disabled="!canEdit" class="mpd-textarea mpd-auto-grow" rows="2" placeholder="Дополнительные заметки..." @input="autoResize($event)" ref="notesRef"></textarea>
      </div>

      <!-- Кнопки убраны снизу — они теперь в шапке -->
    </div>
    <ConfirmModal v-if="confirmModal.show" :title="confirmModal.title" :message="confirmModal.message"
      @confirm="onConfirm" @cancel="onCancel" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick, inject } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { db } from '@/lib/apiClient.js';
import { useUserStore } from '@/stores/userStore.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useToastStore } from '@/stores/toastStore.js';

const orderStore = useOrderStore();
import ConfirmModal from '@/components/modals/ConfirmModal.vue';
import { useConfirm } from '@/composables/useConfirm.js';

const router = useRouter();
const route = useRoute();
const userStore = useUserStore();
const toast = useToastStore();

const loading = ref(false);
const saving = ref(false);
const dirty = ref(false);
const savedSnapshot = ref('');
const files = ref([]);
const carryoverTasks = ref([]);
const questionsRef = ref(null);
const notesRef = ref(null);
const uploading = ref(false);
const sessionToken = computed(() => localStorage.getItem('bk_session_token') || '');
const allUsers = ref([]);
const seriesList = ref([]);
const { confirmModal, confirm, onConfirm, onCancel } = useConfirm();
const newParticipant = ref('');
const showUserPicker = ref(false);

const protocol = ref({
  id: null,
  series_id: null,
  meeting_date: new Date().toISOString().slice(0, 10),
  topic: '',
  participants: [],
  questions: '',
  notes: '',
  status: 'draft',
  created_by: '',
  decisions: [],
});

const isAdminOrManager = computed(() => ['admin', 'manager'].includes(userStore.currentUser?.role));
const setTabTitle = inject('setTabTitle', () => {});
watch(() => protocol.value.topic, (t) => { if (t) setTabTitle('Протокол: ' + t); });

const canEdit = computed(() => {
  if (!userStore.hasAccess('protocols', 'edit')) return false;
  if (!protocol.value.id) return true;
  if (isAdminOrManager.value) return true;
  if (protocol.value.created_by === userStore.currentUser?.name) return true;
  return false;
});

const canDelete = computed(() => {
  if (!protocol.value.id) return false;
  if (isAdminOrManager.value) return true;
  return protocol.value.created_by === userStore.currentUser?.name;
});

const availableUsers = computed(() =>
  allUsers.value.filter(u => !protocol.value.participants.includes(u.name))
);

function addParticipant() {
  if (newParticipant.value && !protocol.value.participants.includes(newParticipant.value)) {
    protocol.value.participants.push(newParticipant.value);
  }
  newParticipant.value = '';
}

function pickUser(name) {
  if (!protocol.value.participants.includes(name)) {
    protocol.value.participants.push(name);
  }
  showUserPicker.value = false;
}

function fmtShortDate(d) {
  if (!d) return '';
  const dt = new Date(d + 'T00:00:00');
  return dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
}

async function loadCarryoverTasks() {
  if (!protocol.value.series_id) { carryoverTasks.value = []; return; }
  const { data } = await db.rpc('get_carryover_tasks', {
    series_id: protocol.value.series_id,
    exclude_protocol_id: protocol.value.id || 0,
  });
  carryoverTasks.value = (data || []).map(t => ({ ...t, responsible_person: normalizeResponsible(t.responsible_person) }));
}

function onCarryoverStatusChange(t) {
  const completedAt = t.status === 'done' ? new Date().toISOString() : null;
  db.rpc('update_decision_status', { id: t.id, status: t.status });
  if (t.status === 'done') {
    // Убираем из списка через секунду
    setTimeout(() => { carryoverTasks.value = carryoverTasks.value.filter(x => x.id !== t.id); }, 800);
  }
}

function isMyTask(dec) {
  const me = userStore.currentUser?.name;
  if (!me) return false;
  const rp = dec.responsible_person;
  if (Array.isArray(rp)) return rp.includes(me);
  return rp === me;
}

function formatResponsible(rp) {
  if (Array.isArray(rp)) return rp.join(', ');
  return rp || '';
}

const openResponsiblePicker = ref(null);

function toggleResponsiblePicker(dec) {
  openResponsiblePicker.value = openResponsiblePicker.value === dec ? null : dec;
}

function toggleResponsible(dec, name) {
  const idx = dec.responsible_person.indexOf(name);
  if (idx >= 0) dec.responsible_person.splice(idx, 1);
  else dec.responsible_person.push(name);
}

function removeResponsible(dec, name) {
  dec.responsible_person = dec.responsible_person.filter(n => n !== name);
}

function addDecision() {
  protocol.value.decisions.push({ id: null, text: '', responsible_person: [], deadline: '', status: 'pending' });
}

function autoResize(e) {
  const el = e.target;
  el.style.height = 'auto';
  el.style.height = el.scrollHeight + 'px';
}

function onDecisionStatusChange(dec) {
  if (dec.status === 'done' && !dec.completed_at) dec.completed_at = new Date().toISOString();
  if (dec.status !== 'done') dec.completed_at = null;
  // Быстрое обновление статуса для существующих решений
  if (dec.id) {
    db.rpc('update_decision_status', { id: dec.id, status: dec.status });
  }
}

function onSeriesChange() {
  const s = seriesList.value.find(s => s.id == protocol.value.series_id);
  if (s && s.agenda_template && !protocol.value.questions) {
    const tmpl = typeof s.agenda_template === 'string' ? JSON.parse(s.agenda_template) : s.agenda_template;
    if (tmpl.length) {
      protocol.value.questions = tmpl.map((q, i) => `${i + 1}. ${q}`).join('\n');
    }
  }
  loadCarryoverTasks();
}

async function save() {
  if (!protocol.value.topic.trim()) { toast.error('Укажите тему совещания'); return; }
  saving.value = true;
  const { data, error } = await db.rpc('save_protocol', {
    id: protocol.value.id || 0,
    series_id: protocol.value.series_id,
    meeting_date: protocol.value.meeting_date,
    topic: protocol.value.topic,
    legal_entity: protocol.value.legal_entity || orderStore.settings.legalEntity,
    participants: protocol.value.participants,
    questions: protocol.value.questions,
    notes: protocol.value.notes,
    status: protocol.value.status,
    decisions: protocol.value.decisions.map(d => ({ ...d, responsible_person: Array.isArray(d.responsible_person) ? d.responsible_person.join(', ') : (d.responsible_person || '') })),
  });
  saving.value = false;
  if (error) { toast.error(error); return; }
  takeSnapshot();
  dirty.value = false;
  toast.success('Протокол сохранён');
  if (!protocol.value.id && data?.id) {
    protocol.value.id = data.id;
    router.replace({ name: 'protocol-detail', params: { id: data.id } });
  }
}

async function finalize() {
  if (!await confirm('Финализация', 'Финализировать протокол? Участникам будет отправлено уведомление в Telegram.')) return;
  protocol.value.status = 'final';
  await save();
}

async function deleteProtocol() {
  if (!await confirm('Удаление', 'Удалить этот протокол? Это действие нельзя отменить.')) return;
  await db.rpc('delete_protocol', { id: protocol.value.id });
  toast.success('Протокол удалён');
  router.push({ name: 'protocols' });
}

async function uploadFile(e) {
  const file = e.target.files?.[0];
  if (!file || !protocol.value.id) return;
  uploading.value = true;
  try {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('protocol_id', protocol.value.id);
    const token = localStorage.getItem('bk_session_token') || '';
    const res = await fetch('/api/upload/protocol-file', { method: 'POST', headers: { 'X-Session-Token': token }, body: fd });
    const data = await res.json();
    if (data.success) {
      files.value.push({ id: data.id, file_name: data.file_name, file_path: data.file_path, uploaded_by: data.uploaded_by });
      toast.success('Файл прикреплён');
    } else {
      toast.error(data.error || 'Ошибка загрузки');
    }
  } catch { toast.error('Ошибка загрузки файла'); }
  uploading.value = false;
  e.target.value = '';
}

async function deleteFile(f) {
  if (!await confirm('Удаление файла', `Удалить файл «${f.file_name}»?`)) return;
  const token = localStorage.getItem('bk_session_token') || '';
  await fetch(`/api/upload/protocol-file?file_id=${f.id}`, { method: 'DELETE', headers: { 'X-Session-Token': token } });
  files.value = files.value.filter(x => x.id !== f.id);
  toast.success('Файл удалён');
}

async function exportExcel() {
  const { exportProtocolExcel } = await import('@/lib/protocolExport.js');
  exportProtocolExcel(protocol.value);
}

async function exportPdf() {
  const { exportProtocolPdf } = await import('@/lib/protocolExport.js');
  exportProtocolPdf(protocol.value);
}

async function goBack() {
  if (dirty.value) {
    if (!await confirm('Несохранённые изменения', 'Вы не сохранили протокол. Уйти без сохранения?')) return;
  }
  dirty.value = false;
  router.push({ name: 'protocols' });
}

function takeSnapshot() {
  savedSnapshot.value = JSON.stringify({ t: protocol.value.topic, p: protocol.value.participants, q: protocol.value.questions, n: protocol.value.notes, d: protocol.value.decisions, s: protocol.value.status, si: protocol.value.series_id });
}

function currentSnapshot() {
  return JSON.stringify({ t: protocol.value.topic, p: protocol.value.participants, q: protocol.value.questions, n: protocol.value.notes, d: protocol.value.decisions, s: protocol.value.status, si: protocol.value.series_id });
}

function beforeUnloadHandler(e) {
  if (dirty.value) { e.preventDefault(); e.returnValue = ''; }
}

async function loadProtocol(id) {
  loading.value = true;
  const { data, error } = await db.rpc('get_protocol', { id: Number(id) });
  if (error || !data) { toast.error('Протокол не найден'); router.push({ name: 'protocols' }); return; }
  data.participants = typeof data.participants === 'string' ? JSON.parse(data.participants) : (data.participants || []);
  data.decisions = (data.decisions || []).map(d => ({ ...d, responsible_person: normalizeResponsible(d.responsible_person) }));
  files.value = data.files || [];
  delete data.files;
  protocol.value = data;
  loading.value = false;
  takeSnapshot();
  loadCarryoverTasks();
  nextTick(() => {
    if (questionsRef.value) { questionsRef.value.style.height = 'auto'; questionsRef.value.style.height = questionsRef.value.scrollHeight + 'px'; }
    if (notesRef.value) { notesRef.value.style.height = 'auto'; notesRef.value.style.height = notesRef.value.scrollHeight + 'px'; }
  });
}

watch(protocol, () => {
  if (!loading.value && savedSnapshot.value) {
    dirty.value = currentSnapshot() !== savedSnapshot.value;
  }
}, { deep: true });

function closePickerOnOutsideClick(e) {
  if (openResponsiblePicker.value && !e.target.closest('.mpd-multi-select')) {
    openResponsiblePicker.value = null;
  }
}

// Нормализация responsible_person: строка → массив (для совместимости со старыми данными)
function normalizeResponsible(rp) {
  if (Array.isArray(rp)) return rp;
  if (!rp) return [];
  return rp.split(',').map(s => s.trim()).filter(Boolean);
}

onMounted(async () => {
  window.addEventListener('beforeunload', beforeUnloadHandler);
  document.addEventListener('click', closePickerOnOutsideClick);
  const [usersRes, seriesRes] = await Promise.all([
    db.rpc('get_users_list_short'),
    db.rpc('get_protocol_series', { legal_entity: orderStore.settings.legalEntity }),
  ]);
  if (usersRes.data) allUsers.value = usersRes.data;
  if (seriesRes.data) seriesList.value = seriesRes.data;

  const id = route.params.id;
  if (id && id !== 'new') await loadProtocol(id);
  else {
    protocol.value.created_by = userStore.currentUser?.name;
    // Если есть series_id из query (напр. создание из списка с фильтром)
    if (route.query.series_id) { protocol.value.series_id = Number(route.query.series_id); onSeriesChange(); }
    takeSnapshot();
  }
});

onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', beforeUnloadHandler);
  document.removeEventListener('click', closePickerOnOutsideClick);
});
</script>

<style scoped>
.mpd-view { padding: 0; }
.mpd-top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
.mpd-top-left { display: flex; align-items: center; gap: 12px; }
.mpd-top-actions { display: flex; gap: 6px; }
.mpd-back { border: none; background: none; font-size: 13px; cursor: pointer; color: #E76F51; padding: 4px 0; }
.mpd-back:hover { text-decoration: underline; }

.mpd-content { display: flex; flex-direction: column; gap: 10px; }
.mpd-section { background: #fff; border: 1px solid #e8e8e8; border-radius: 8px; padding: 14px 16px; }
.mpd-section h3 { margin: 0 0 10px; font-size: 14px; color: #333; }
.mpd-count { color: #888; font-weight: 400; font-size: 13px; }

.mpd-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 10px; }
.mpd-field { display: flex; flex-direction: column; gap: 3px; }
.mpd-field label { font-size: 11px; color: #888; font-weight: 500; }
.mpd-input { padding: 7px 9px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; width: 100%; box-sizing: border-box; }
.mpd-input:disabled { background: #f8f8f8; color: #555; }
.mpd-textarea { padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; width: 100%; box-sizing: border-box; resize: vertical; font-family: inherit; }
.mpd-textarea:disabled { background: #f8f8f8; }
.mpd-auto-grow { overflow: hidden; resize: none; }

/* Participants — tags + plus button */
.mpd-participants { display: flex; flex-wrap: wrap; gap: 5px; align-items: center; }
.mpd-participant-tag { display: inline-flex; align-items: center; gap: 4px; background: #f0f0f0; padding: 4px 10px; border-radius: 12px; font-size: 12px; }
.mpd-tag-remove { border: none; background: none; font-size: 15px; cursor: pointer; color: #999; line-height: 1; padding: 0 2px; }
.mpd-tag-remove:hover { color: #E76F51; }
.mpd-add-wrap { position: relative; }
.mpd-add-btn { width: 28px; height: 28px; border-radius: 50%; border: 1.5px dashed #bbb; background: none; font-size: 18px; line-height: 1; color: #888; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.mpd-add-btn:hover { border-color: #E76F51; color: #E76F51; }
.mpd-picker { position: absolute; top: 34px; left: 0; background: #fff; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.12); z-index: 20; min-width: 200px; max-height: 220px; overflow-y: auto; }
.mpd-picker-item { padding: 7px 12px; cursor: pointer; font-size: 13px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.mpd-picker-item:hover { background: #f5f5f5; }
.mpd-picker-name { white-space: nowrap; }
.mpd-picker-role { font-size: 11px; color: #999; white-space: nowrap; text-align: right; }

/* Tasks table */
.mpd-tasks-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
.mpd-tasks-table th { font-size: 11px; color: #888; font-weight: 500; text-align: left; padding: 4px 6px; border-bottom: 2px solid #eee; }
.mpd-th-num { width: 32px; text-align: center; }
.mpd-th-resp { width: 200px; }
.mpd-th-date { width: 120px; }
.mpd-th-status { width: 110px; }
.mpd-th-del { width: 30px; }
.mpd-tasks-table td { padding: 3px 4px; vertical-align: middle; border-bottom: 1px solid #f0f0f0; }
.mpd-td-num { text-align: center; font-size: 12px; color: #888; font-weight: 600; }
.mpd-cell-input { padding: 5px 7px; border: 1px solid transparent; border-radius: 4px; font-size: 12px; width: 100%; box-sizing: border-box; background: transparent; }
.mpd-cell-input:focus { border-color: #ddd; background: #fff; outline: none; }
.mpd-cell-input:disabled { color: #555; }
.mpd-cell-text { font-size: 13px; resize: none; overflow: hidden; min-height: 28px; line-height: 1.4; font-family: inherit; display: block; }
.mpd-cell-status { font-weight: 500; }
.mpd-st-pending { color: #e65100; }
.mpd-st-done { color: #2e7d32; }
.mpd-st-overdue { color: #c62828; }
.mpd-tr-pending { background: #fff8e1; }
.mpd-tr-done { background: #e8f5e9; }
.mpd-tr-overdue { background: #fce4ec; }
.mpd-tr-mine { box-shadow: inset 3px 0 0 #E76F51; }
.mpd-tr-mine.mpd-tr-pending { background: #fff3e0; }
.mpd-tr-mine.mpd-tr-done { background: #c8e6c9; }
.mpd-tr-mine.mpd-tr-overdue { background: #f8bbd0; }
.mpd-td-del { text-align: center; }
.mpd-row-del { border: none; background: none; color: #ccc; font-size: 16px; cursor: pointer; padding: 0 4px; }
.mpd-row-del:hover { color: #E76F51; }

/* Multi-select ответственных */
.mpd-td-resp { position: relative; }
.mpd-multi-select { position: relative; }
.mpd-multi-tags { display: flex; flex-wrap: wrap; gap: 3px; padding: 3px 5px; min-height: 28px; cursor: pointer; border: 1px solid transparent; border-radius: 4px; align-items: center; }
.mpd-multi-tags:hover { border-color: #ddd; background: #fafafa; }
.mpd-resp-tag { display: inline-flex; align-items: center; gap: 2px; background: #e3f2fd; color: #1565c0; font-size: 11px; padding: 1px 6px; border-radius: 3px; white-space: nowrap; }
.mpd-resp-tag-x { cursor: pointer; font-size: 13px; color: #90a4ae; margin-left: 2px; }
.mpd-resp-tag-x:hover { color: #c62828; }
.mpd-resp-placeholder { font-size: 12px; color: #aaa; }
.mpd-resp-dropdown { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #ddd; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.12); z-index: 30; max-height: 180px; overflow-y: auto; min-width: 180px; }
.mpd-resp-option { padding: 6px 10px; cursor: pointer; font-size: 12px; display: flex; align-items: center; gap: 6px; }
.mpd-resp-option:hover { background: #f5f5f5; }
.mpd-resp-check { font-size: 14px; flex-shrink: 0; }
.mpd-resp-empty { padding: 10px; font-size: 12px; color: #999; text-align: center; }

/* Carryover tasks */
.mpd-section-carryover { border-color: #ffe0b2; background: #fffaf0; }
.mpd-carryover-title { color: #e65100; }
.mpd-td-text { font-size: 13px; padding: 5px 8px; }
.mpd-td-source { font-size: 11px; color: #999; white-space: nowrap; }
.mpd-th-source { width: 90px; }

/* Buttons */
.mpd-btn { padding: 6px 14px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; cursor: pointer; background: #fff; white-space: nowrap; }
.mpd-btn:hover { background: #f5f5f5; }
.mpd-btn-primary { background: #E76F51; color: #fff; border-color: #E76F51; }
.mpd-btn-primary:hover { background: #b52200; }
.mpd-btn-primary:disabled { opacity: 0.6; cursor: default; }
.mpd-btn-finalize { background: #2e7d32; color: #fff; border-color: #2e7d32; }
.mpd-btn-finalize:hover { background: #1b5e20; }
.mpd-btn-danger { color: #E76F51; border-color: #fcc; font-size: 12px; }
.mpd-btn-danger:hover { background: #fff0f0; }
.mpd-btn-export { background: #f5f5f5; font-size: 12px; padding: 5px 10px; }
.mpd-btn-add { border-style: dashed; color: #888; width: 100%; padding: 8px; margin-top: 4px; }
.mpd-btn-add:hover { border-color: #E76F51; color: #E76F51; }

/* Files */
.mpd-files { display: flex; flex-direction: column; gap: 4px; margin-bottom: 8px; }
.mpd-file { display: flex; align-items: center; gap: 10px; padding: 5px 8px; border-radius: 4px; }
.mpd-file:hover { background: #f8f8f8; }
.mpd-file-name { color: #1565c0; text-decoration: none; font-size: 13px; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mpd-file-name:hover { text-decoration: underline; }
.mpd-file-meta { font-size: 11px; color: #999; white-space: nowrap; }
.mpd-files-empty { font-size: 12px; color: #aaa; margin-bottom: 8px; }
.mpd-btn-upload { border-style: dashed; color: #888; padding: 7px 14px; cursor: pointer; display: inline-block; text-align: center; border: 1px dashed #ddd; border-radius: 6px; font-size: 13px; }
.mpd-btn-upload:hover { border-color: #E76F51; color: #E76F51; }

.mpd-actions { display: flex; gap: 8px; margin-top: 4px; align-items: center; }
.mpd-unsaved { font-size: 11px; color: #e65100; font-weight: 500; background: #fff3e0; padding: 3px 8px; border-radius: 10px; }
.mpd-loading { text-align: center; padding: 40px; color: #888; }

@media (max-width: 700px) {
  .mpd-row { grid-template-columns: 1fr; }
  .mpd-top-bar { flex-direction: column; align-items: flex-start; gap: 8px; }
  .mpd-tasks-table { font-size: 11px; }
  .mpd-th-resp, .mpd-th-date { width: auto; }
}
</style>
