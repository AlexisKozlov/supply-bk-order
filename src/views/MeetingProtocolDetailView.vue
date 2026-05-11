<template>
  <div class="mpd-view">
    <div class="mpd-top-bar">
      <div class="mpd-top-left">
        <button class="mpd-back" @click="goBack">&larr; К списку</button>
        <span v-if="dirty" class="mpd-unsaved">Не сохранено</span>
      </div>
      <div class="mpd-top-actions">
        <button v-if="canEdit" class="mpd-btn mpd-btn-primary" @click="save" :disabled="saving"><BurgerSpinner v-if="saving" size="xs" /><span>{{ saving ? 'Сохранение...' : 'Сохранить' }}</span></button>
        <button v-if="canEdit && protocol.status === 'draft'" class="mpd-btn mpd-btn-finalize" @click="finalize">Финализировать</button>
        <button v-if="protocol.id" class="mpd-btn mpd-btn-export" @click="exportExcel">Excel</button>
        <button v-if="protocol.id" class="mpd-btn mpd-btn-export" @click="exportPdf">PDF</button>
        <button v-if="canDelete" class="mpd-btn mpd-btn-danger" @click="deleteProtocol">Удалить</button>
      </div>
    </div>

    <div v-if="loading" class="mpd-loading"><BurgerSpinner text="Загрузка..." /></div>

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
        <h3 class="mpd-carryover-title">Задачи из прошлого протокола серии <span class="mpd-count">({{ carryoverTasks.length }})</span></h3>
        <table class="mpd-tasks-table">
          <thead>
            <tr>
              <th class="mpd-th-expand"></th>
              <th class="mpd-th-num">№</th>
              <th>Задача</th>
              <th class="mpd-th-progress">Прогресс</th>
              <th class="mpd-th-resp">Ответственный</th>
              <th class="mpd-th-date">Срок</th>
              <th class="mpd-th-status">Статус</th>
              <th class="mpd-th-source">Из протокола</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="(t, i) in carryoverTasks" :key="'co-' + t.id">
              <tr :class="rowClass(t)" @click="onRowClick($event, t)">
                <td class="mpd-td-expand">
                  <button class="mpd-expand-btn" :class="{ 'is-open': expanded[t.id] }" @click="toggleExpand(t.id)" :aria-expanded="expanded[t.id] ? 'true' : 'false'" title="Подробнее">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                  </button>
                </td>
                <td class="mpd-td-num">{{ i + 1 }}</td>
                <td class="mpd-td-text-readonly">{{ t.text }}</td>
                <td class="mpd-td-progress">
                  <div class="mpd-progress-cell" v-if="(t.assignees_progress || []).length">
                    <span class="mpd-progress-num">{{ progressDone(t) }} <span class="mpd-progress-of">из</span> {{ progressTotal(t) }}</span>
                    <div class="mpd-progress-bar"><span :style="{ width: progressPercent(t) + '%' }"></span></div>
                  </div>
                  <span v-else class="mpd-empty-cell">—</span>
                </td>
                <td class="mpd-td-resp-readonly">{{ formatResponsible(t.responsible_person) }}</td>
                <td class="mpd-td-date">
                  <button class="mpd-chip mpd-chip-date" :class="dueChipClass(t)" @click="openDate($event, t, 'carryover')">
                    <svg class="mpd-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 9h18"/></svg>
                    <span>{{ t.deadline ? fmtFullDate(t.deadline) : 'без срока' }}</span>
                  </button>
                  <input type="date" v-model="t.deadline" @change="onCarryoverDeadlineChange(t)" class="mpd-hidden-date" />
                </td>
                <td class="mpd-td-status">
                  <div class="mpd-chip-wrap">
                    <button class="mpd-chip" :class="'mpd-chip-st-' + t.status" @click.stop="toggleStatusPicker(t.id)">
                      <span class="mpd-chip-dot"></span><span>{{ statusLabel(t.status) }}</span>
                    </button>
                    <div v-if="openStatusPicker === t.id" class="mpd-chip-menu" @click.stop>
                      <div v-for="s in statusOptions" :key="s" class="mpd-chip-menu-item" :class="'mpd-chip-st-' + s" @click="changeCarryoverStatus(t, s)">
                        <span class="mpd-chip-dot"></span><span>{{ statusLabel(s) }}</span>
                      </div>
                    </div>
                  </div>
                </td>
                <td class="mpd-td-source">{{ fmtShortDate(t.meeting_date) }}</td>
              </tr>
              <tr v-if="expanded[t.id]" class="mpd-tr-details">
                <td colspan="8">
                  <div class="mpd-details">
                    <table v-if="(t.assignees_progress || []).length" class="mpd-assignee-table">
                      <colgroup><col class="mpd-col-name"><col></colgroup>
                      <tbody>
                        <tr v-for="a in t.assignees_progress" :key="a.user_name" :class="{ 'is-done': a.is_done }">
                          <td class="mpd-assignee-td-name">
                            <span class="mpd-assignee-mark">{{ a.is_done ? '✓' : '○' }}</span>
                            <span>{{ a.user_name }}</span>
                          </td>
                          <td class="mpd-assignee-td-result" @dblclick="startEditDescription(a)">
                            <textarea v-if="editingDescCardId === a.card_id" v-model="a.description" class="mpd-assignee-edit is-active" rows="1" placeholder="Описание / результат…" @input="autoResize($event)" @blur="saveAssigneeDescription(a)" @click.stop></textarea>
                            <template v-else>
                              <span v-if="a.description">{{ a.description }}</span>
                              <span v-else class="mpd-empty-cell">— двойной клик, чтобы добавить —</span>
                            </template>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                    <div v-else class="mpd-empty-cell mpd-details-empty">нет ответственных с карточками</div>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <!-- Задачи -->
      <div class="mpd-section">
        <h3>Задачи <span class="mpd-count">({{ protocol.decisions.length }})</span></h3>
        <table v-if="protocol.decisions.length" class="mpd-tasks-table">
          <thead>
            <tr>
              <th class="mpd-th-expand"></th>
              <th class="mpd-th-num">№</th>
              <th>Задача</th>
              <th class="mpd-th-progress">Прогресс</th>
              <th class="mpd-th-resp">Ответственный</th>
              <th class="mpd-th-date">Срок</th>
              <th class="mpd-th-status">Статус</th>
              <th v-if="canEdit" class="mpd-th-del"></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="(dec, i) in protocol.decisions" :key="dec.id || ('new-' + i)">
              <tr :class="rowClass(dec)" @click="onRowClick($event, dec)">
                <td class="mpd-td-expand">
                  <button v-if="dec.id" class="mpd-expand-btn" :class="{ 'is-open': expanded[dec.id] }" @click="toggleExpand(dec.id)" :aria-expanded="expanded[dec.id] ? 'true' : 'false'" title="Подробнее">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                  </button>
                </td>
                <td class="mpd-td-num">{{ i + 1 }}</td>
                <td class="mpd-td-text" @dblclick="onTextDblClick($event, dec, i)">
                  <textarea v-if="isEditing(dec, i)" v-model="dec.text" class="mpd-cell-text-edit is-active" rows="1" placeholder="Текст задачи" @input="autoResize($event)" @blur="stopEdit" @click.stop></textarea>
                  <div v-else class="mpd-cell-text-view" :class="{ 'is-empty': !dec.text, 'is-editable': canEdit }">{{ dec.text || (canEdit ? 'Двойной клик — добавить текст' : '—') }}</div>
                </td>
                <td class="mpd-td-progress">
                  <div class="mpd-progress-cell" v-if="(dec.assignees_progress || []).length">
                    <span class="mpd-progress-num">{{ progressDone(dec) }} <span class="mpd-progress-of">из</span> {{ progressTotal(dec) }}</span>
                    <div class="mpd-progress-bar"><span :style="{ width: progressPercent(dec) + '%' }"></span></div>
                  </div>
                  <span v-else class="mpd-empty-cell">—</span>
                </td>
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
                  <span v-else class="mpd-td-resp-readonly">{{ dec.responsible_person.join(', ') }}</span>
                </td>
                <td class="mpd-td-date">
                  <button class="mpd-chip mpd-chip-date" :class="[dueChipClass(dec), { 'is-disabled': !canEdit }]" :disabled="!canEdit" @click="openDate($event, dec, 'decision')">
                    <svg class="mpd-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 9h18"/></svg>
                    <span>{{ dec.deadline ? fmtFullDate(dec.deadline) : 'без срока' }}</span>
                  </button>
                  <input type="date" v-model="dec.deadline" :disabled="!canEdit" class="mpd-hidden-date" />
                </td>
                <td class="mpd-td-status">
                  <div class="mpd-chip-wrap">
                    <button class="mpd-chip" :class="['mpd-chip-st-' + dec.status, { 'is-disabled': !canEdit && !isMyTask(dec) }]" :disabled="!canEdit && !isMyTask(dec)" @click.stop="toggleStatusPicker(decKey(dec, i))">
                      <span class="mpd-chip-dot"></span><span>{{ statusLabel(dec.status) }}</span>
                    </button>
                    <div v-if="openStatusPicker === decKey(dec, i)" class="mpd-chip-menu" @click.stop>
                      <div v-for="s in statusOptions" :key="s" class="mpd-chip-menu-item" :class="'mpd-chip-st-' + s" @click="changeDecisionStatus(dec, s)">
                        <span class="mpd-chip-dot"></span><span>{{ statusLabel(s) }}</span>
                      </div>
                    </div>
                  </div>
                </td>
                <td v-if="canEdit" class="mpd-td-del">
                  <button class="mpd-row-del" @click="protocol.decisions.splice(i, 1)" title="Удалить задачу">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                  </button>
                </td>
              </tr>
              <tr v-if="dec.id && expanded[dec.id]" class="mpd-tr-details">
                <td :colspan="canEdit ? 8 : 7">
                  <div class="mpd-details">
                    <table v-if="(dec.assignees_progress || []).length" class="mpd-assignee-table">
                      <colgroup><col class="mpd-col-name"><col></colgroup>
                      <tbody>
                        <tr v-for="a in dec.assignees_progress" :key="a.user_name" :class="{ 'is-done': a.is_done }">
                          <td class="mpd-assignee-td-name">
                            <span class="mpd-assignee-mark">{{ a.is_done ? '✓' : '○' }}</span>
                            <span>{{ a.user_name }}</span>
                          </td>
                          <td class="mpd-assignee-td-result" @dblclick="startEditDescription(a)">
                            <textarea v-if="editingDescCardId === a.card_id" v-model="a.description" class="mpd-assignee-edit is-active" rows="1" placeholder="Описание / результат…" @input="autoResize($event)" @blur="saveAssigneeDescription(a)" @click.stop></textarea>
                            <template v-else>
                              <span v-if="a.description">{{ a.description }}</span>
                              <span v-else class="mpd-empty-cell">— двойной клик, чтобы добавить —</span>
                            </template>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                    <div v-else class="mpd-empty-cell mpd-details-empty">нет ответственных с карточками</div>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
        <button v-if="canEdit" class="mpd-btn mpd-btn-add" @click="addDecision">+ Добавить задачу</button>
      </div>

      <!-- Файлы -->
      <div v-if="protocol.id" class="mpd-section">
        <h3>Файлы <span class="mpd-count">({{ files.length }})</span></h3>
        <div v-if="files.length" class="mpd-files">
          <div v-for="f in files" :key="f.id" class="mpd-file">
            <a :href="fileUrl(f)" target="_blank" class="mpd-file-name">{{ f.file_name }}</a>
            <span class="mpd-file-meta">{{ f.uploaded_by }}</span>
            <button v-if="canEdit" class="mpd-row-del" @click="deleteFile(f)" title="Удалить файл">&times;</button>
          </div>
        </div>
        <div v-else class="mpd-files-empty">Нет прикреплённых файлов</div>
        <label v-if="canEdit" class="mpd-btn mpd-btn-upload">
          <BurgerSpinner v-if="uploading" size="xs" />
          <span>{{ uploading ? 'Загрузка...' : '+ Прикрепить файл' }}</span>
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
import { ref, computed, defineAsyncComponent, onMounted, onBeforeUnmount, watch, nextTick, inject } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { db, getDownloadUrl } from '@/lib/apiClient.js';
import { useUserStore } from '@/stores/userStore.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useToastStore } from '@/stores/toastStore.js';

const orderStore = useOrderStore();
import { useConfirm } from '@/composables/useConfirm.js';

const ConfirmModal = defineAsyncComponent(() => import('@/components/modals/ConfirmModal.vue'));

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
// Карта одноразовых download-токенов: file_path → URL.
// Заполняется асинхронно в watch на files.
const downloadUrls = ref({});
function fileUrl(f) { return downloadUrls.value[f.id] || ''; }
async function refreshDownloadUrls() {
  const map = {};
  for (const f of files.value) {
    try {
      map[f.id] = await getDownloadUrl('/api/uploads/protocols/' + f.file_path);
    } catch { map[f.id] = ''; }
  }
  downloadUrls.value = map;
}
watch(files, () => { refreshDownloadUrls(); }, { immediate: false });
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

function fmtFullDate(d) {
  if (!d) return '';
  const dt = new Date(d + 'T00:00:00');
  return dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' });
}

const expanded = ref({});
function toggleExpand(id) {
  if (!id) return;
  expanded.value = { ...expanded.value, [id]: !expanded.value[id] };
}

const openStatusPicker = ref(null);
function toggleStatusPicker(key) {
  openStatusPicker.value = openStatusPicker.value === key ? null : key;
}
function decKey(dec, i) { return dec.id ? 'd-' + dec.id : 'new-' + i; }

const statusOptions = ['pending', 'done', 'overdue'];
function statusLabel(s) {
  if (s === 'pending') return 'В работе';
  if (s === 'done') return 'Выполнено';
  if (s === 'overdue') return 'Просрочено';
  return s;
}

function rowClass(t) {
  return {
    'mpd-tr': true,
    'mpd-tr-mine': isMyTask(t),
    'mpd-tr-pending': t.status === 'pending',
    'mpd-tr-overdue': t.status === 'overdue',
    'mpd-tr-done': t.status === 'done',
    'mpd-tr-expanded': !!expanded.value[t.id],
  };
}

function dueChipClass(t) {
  if (!t.deadline) return 'mpd-chip-date-none';
  if (t.status === 'done') return 'mpd-chip-date-done';
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const due = new Date(t.deadline + 'T00:00:00');
  const diffH = (due - today) / 36e5;
  if (diffH < 0) return 'mpd-chip-date-overdue';
  if (diffH <= 24) return 'mpd-chip-date-soon24';
  if (diffH <= 72) return 'mpd-chip-date-soon72';
  return '';
}

function openDate(event, t, kind) {
  const btn = event.currentTarget;
  const input = btn?.parentElement?.querySelector('.mpd-hidden-date');
  if (!input || input.disabled) return;
  if (typeof input.showPicker === 'function') input.showPicker();
  else input.click();
}

function changeCarryoverStatus(t, newStatus) {
  openStatusPicker.value = null;
  if (t.status === newStatus) return;
  t.status = newStatus;
  onCarryoverStatusChange(t);
}

function changeDecisionStatus(dec, newStatus) {
  openStatusPicker.value = null;
  if (dec.status === newStatus) return;
  dec.status = newStatus;
  onDecisionStatusChange(dec);
}

const editingDescCardId = ref(null);
const editingDescOriginal = ref('');
function startEditDescription(a) {
  if (!a || !a.card_id) return;
  editingDescOriginal.value = a.description || '';
  editingDescCardId.value = a.card_id;
  nextTick(() => {
    const el = document.querySelector('.mpd-assignee-edit.is-active');
    if (el) {
      el.focus();
      el.style.height = 'auto';
      el.style.height = el.scrollHeight + 'px';
      const v = el.value;
      el.setSelectionRange(v.length, v.length);
    }
  });
}
async function saveAssigneeDescription(a) {
  const cardId = a.card_id;
  if (!cardId) { editingDescCardId.value = null; return; }
  const newDesc = a.description || '';
  const original = editingDescOriginal.value;
  editingDescCardId.value = null;
  if (newDesc === original) return;
  try {
    const token = localStorage.getItem('bk_session_token') || '';
    const res = await fetch(`/api/tasks/cards/${cardId}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json', 'X-Session-Token': token },
      body: JSON.stringify({ description: newDesc }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.error) {
      a.description = original;
      toast.error(data.error || 'Не удалось сохранить описание');
    } else {
      toast.success('Описание обновлено');
    }
  } catch (err) {
    a.description = original;
    toast.error('Ошибка сети при сохранении');
  }
}

function progressTotal(t) { return (t.assignees_progress || []).length; }
function progressDone(t) { return (t.assignees_progress || []).filter(a => a.is_done).length; }
function progressPercent(t) {
  const tot = progressTotal(t);
  if (!tot) return 0;
  return Math.round((progressDone(t) / tot) * 100);
}

const editingKey = ref(null);
function decEditKey(dec, i) { return dec.id ? 'd-' + dec.id : 'new-' + i; }
function isEditing(dec, i) { return editingKey.value === decEditKey(dec, i); }
function startEdit(dec, i) {
  if (!canEdit.value) return;
  editingKey.value = decEditKey(dec, i);
  nextTick(() => {
    const el = document.querySelector('.mpd-cell-text-edit.is-active');
    if (el) {
      el.focus();
      el.style.height = 'auto';
      el.style.height = el.scrollHeight + 'px';
      // курсор в конец
      const v = el.value;
      el.setSelectionRange(v.length, v.length);
    }
  });
}
function stopEdit() { editingKey.value = null; }

let rowClickTimer = null;
function onRowClick(e, t) {
  if (!t || !t.id) return;
  if (e.target.closest('.mpd-chip-wrap, .mpd-multi-select, .mpd-row-del, .mpd-expand-btn, .mpd-hidden-date, .mpd-cell-text-edit')) return;
  if (rowClickTimer) clearTimeout(rowClickTimer);
  rowClickTimer = setTimeout(() => { toggleExpand(t.id); rowClickTimer = null; }, 220);
}
function onTextDblClick(e, dec, i) {
  if (rowClickTimer) { clearTimeout(rowClickTimer); rowClickTimer = null; }
  startEdit(dec, i);
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

async function onCarryoverDeadlineChange(t) {
  const { error } = await db.rpc('update_decision_deadline', { id: t.id, deadline: t.deadline || null });
  if (error) toast.error('Ошибка', 'Не удалось обновить срок');
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
  if (openStatusPicker.value && !e.target.closest('.mpd-chip-wrap')) {
    openStatusPicker.value = null;
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
.mpd-tasks-table { width: 100%; border-collapse: separate; border-spacing: 0; margin-bottom: 8px; }
.mpd-tasks-table th { font-size: 10px; color: #8a8a8a; font-weight: 600; text-align: left; padding: 6px 8px; border-bottom: 1px solid #ececec; text-transform: uppercase; letter-spacing: 0.04em; }
.mpd-th-expand { width: 28px; }
.mpd-th-num { width: 28px; text-align: center; }
.mpd-th-progress { width: 130px; }
.mpd-th-resp { width: 200px; }
.mpd-th-date { width: 130px; }
.mpd-th-status { width: 130px; }
.mpd-th-del { width: 36px; }
.mpd-tasks-table td { padding: 6px 8px; vertical-align: middle; border-bottom: 1px solid #f2f2f2; background: #fff; }
.mpd-tasks-table td.mpd-td-text,
.mpd-tasks-table td.mpd-td-text-readonly { vertical-align: top; padding-top: 8px; }
.mpd-td-num { text-align: center; font-size: 12px; color: #aaa; font-weight: 600; }
.mpd-td-text-readonly { font-size: 13px; color: #2b2b2b; line-height: 1.4; white-space: pre-wrap; word-break: break-word; padding: 8px 8px; }
.mpd-td-resp-readonly { font-size: 12px; color: #555; }

/* Приглушённые фоны строк по статусу */
.mpd-tr.mpd-tr-pending td { background: #fffbf2; }
.mpd-tr.mpd-tr-done td { background: #f4faf5; color: #6e8170; }
.mpd-tr.mpd-tr-overdue td { background: #fdf2f2; }

/* Полоска слева — статус задачи и/или "моя задача" */
.mpd-tr-mine td:first-child { box-shadow: inset 3px 0 0 #E76F51; }
.mpd-tr-overdue td:first-child { box-shadow: inset 3px 0 0 #c62828; }
.mpd-tr-overdue.mpd-tr-mine td:first-child { box-shadow: inset 3px 0 0 #c62828, inset 6px 0 0 #E76F51; }

.mpd-tr-expanded td { border-bottom-color: transparent; }
.mpd-tr-details td { border-bottom: 1px solid #ececec; padding: 0 0 10px; background: #fafbfc; }

/* Раскрытие */
.mpd-expand-btn { width: 22px; height: 22px; border: none; background: transparent; color: #999; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; border-radius: 4px; transition: transform 0.15s, color 0.15s, background 0.15s; }
.mpd-expand-btn:hover { background: #f0f0f0; color: #333; }
.mpd-expand-btn.is-open { transform: rotate(90deg); color: #E76F51; }

/* Текст задачи: режим просмотра (div) и режим редактирования (textarea) */
.mpd-cell-text-view { padding: 6px 8px; font-size: 13px; line-height: 1.45; color: #2b2b2b; white-space: pre-wrap; word-break: break-word; border-radius: 5px; min-height: 26px; }
.mpd-cell-text-view.is-empty { color: #bbb; font-style: italic; }
.mpd-cell-text-edit { padding: 6px 8px; border: 1px solid #E76F51; border-radius: 5px; font-size: 13px; width: 100%; box-sizing: border-box; background: #fff; resize: none; overflow: hidden; min-height: 30px; line-height: 1.45; font-family: inherit; color: #2b2b2b; }
.mpd-cell-text-edit:focus { outline: none; box-shadow: 0 0 0 2px rgba(231, 111, 81, 0.18); }
.mpd-td-text { cursor: text; }
.mpd-tasks-table tbody tr:not(.mpd-tr-details) { cursor: pointer; }

/* Чипы статуса и срока — общая база */
.mpd-chip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 12px; font-size: 11px; font-weight: 600; line-height: 1.4; border: 1px solid transparent; background: #f1f3f5; color: #4a4a4a; cursor: pointer; white-space: nowrap; transition: filter 0.12s, transform 0.12s; }
.mpd-chip:hover:not(:disabled):not(.is-disabled) { filter: brightness(0.96); }
.mpd-chip:active:not(:disabled) { transform: translateY(1px); }
.mpd-chip.is-disabled, .mpd-chip:disabled { cursor: default; opacity: 0.75; }
.mpd-chip-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; flex-shrink: 0; opacity: 0.8; }
.mpd-chip-icon { width: 12px; height: 12px; flex-shrink: 0; }

.mpd-chip-st-pending { background: #fff4e0; color: #b35900; }
.mpd-chip-st-done { background: #e3f6e6; color: #1b5e20; }
.mpd-chip-st-overdue { background: #fde2e2; color: #b71c1c; }

.mpd-chip-date { background: #f1f3f5; color: #555; font-weight: 500; }
.mpd-chip-date-none { color: #aaa; }
.mpd-chip-date-soon72 { background: #fff8e1; color: #8c6d00; }
.mpd-chip-date-soon24 { background: #fff0e0; color: #b35900; }
.mpd-chip-date-overdue { background: #fde2e2; color: #b71c1c; }
.mpd-chip-date-done { color: #888; }

.mpd-hidden-date { position: absolute; width: 0; height: 0; opacity: 0; pointer-events: none; border: 0; padding: 0; }

.mpd-chip-wrap { position: relative; display: inline-block; }
.mpd-chip-menu { position: absolute; top: calc(100% + 4px); left: 0; background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; box-shadow: 0 6px 18px rgba(0,0,0,0.08); padding: 4px; min-width: 130px; z-index: 40; display: flex; flex-direction: column; gap: 2px; }
.mpd-chip-menu-item { display: flex; align-items: center; gap: 6px; padding: 5px 9px; border-radius: 6px; font-size: 12px; font-weight: 500; cursor: pointer; }
.mpd-chip-menu-item:hover { filter: brightness(0.96); }

/* Удаление */
.mpd-td-del { text-align: center; }
.mpd-row-del { border: none; background: transparent; color: #c0c0c0; cursor: pointer; padding: 4px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; }
.mpd-row-del:hover { color: #c62828; background: #fde2e2; }

/* Multi-select ответственных */
.mpd-td-resp { position: relative; }
.mpd-multi-select { position: relative; }
.mpd-multi-tags { display: flex; flex-wrap: wrap; gap: 3px; padding: 3px 5px; min-height: 28px; cursor: pointer; border: 1px solid transparent; border-radius: 5px; align-items: center; }
.mpd-multi-tags:hover { border-color: #e2e2e2; background: #fafafa; }
.mpd-resp-tag { display: inline-flex; align-items: center; gap: 2px; background: #eef2f5; color: #4a5b6a; font-size: 11px; padding: 2px 7px; border-radius: 10px; white-space: nowrap; }
.mpd-resp-tag-x { cursor: pointer; font-size: 13px; color: #90a4ae; margin-left: 2px; }
.mpd-resp-tag-x:hover { color: #c62828; }
.mpd-resp-placeholder { font-size: 12px; color: #aaa; }
.mpd-resp-dropdown { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; box-shadow: 0 6px 18px rgba(0,0,0,0.08); z-index: 30; max-height: 180px; overflow-y: auto; min-width: 180px; }
.mpd-resp-option { padding: 6px 10px; cursor: pointer; font-size: 12px; display: flex; align-items: center; gap: 6px; }
.mpd-resp-option:hover { background: #f5f5f5; }
.mpd-resp-check { font-size: 14px; flex-shrink: 0; }
.mpd-resp-empty { padding: 10px; font-size: 12px; color: #999; text-align: center; }

/* Carryover tasks */
.mpd-section-carryover { border-color: #ffe0b2; background: #fffaf0; }
.mpd-section-carryover .mpd-tasks-table td { background: transparent; }
.mpd-section-carryover .mpd-tr-details td { background: rgba(255, 255, 255, 0.5); }
.mpd-carryover-title { color: #e65100; }
.mpd-td-source { font-size: 11px; color: #999; white-space: nowrap; }
.mpd-th-source { width: 90px; }
.mpd-th-comment { width: 220px; }
.mpd-td-comment {
  font-size: 12px; color: #555;
  max-width: 240px;
  overflow: hidden; text-overflow: ellipsis;
  white-space: nowrap;
}
.mpd-empty-cell { color: #bbb; }

/* Ячейка "Прогресс" в самой строке */
.mpd-progress-cell { display: flex; flex-direction: column; gap: 3px; }
.mpd-progress-num { font-size: 12px; font-weight: 600; color: #4a4a4a; }
.mpd-progress-of { font-weight: 400; color: #999; }
.mpd-progress-bar { height: 4px; background: #ececec; border-radius: 2px; overflow: hidden; }
.mpd-progress-bar > span { display: block; height: 100%; background: #4caf50; border-radius: 2px; transition: width 0.2s; }

/* Раскрытая панель: таблица "исполнитель | описание его карточки" */
.mpd-tr-details td { background: #fff; padding: 0; }
.mpd-details { padding: 4px 0 10px 0; }
.mpd-details-empty { padding: 10px 8px; font-size: 13px; }
.mpd-assignee-table { width: 100%; border-collapse: collapse; background: transparent; table-layout: auto; }
.mpd-assignee-table tr { border-top: 1px solid #efefef; }
.mpd-assignee-table tr:first-child { border-top: none; }
.mpd-assignee-table td { background: transparent; padding: 9px 12px 9px 0; vertical-align: top; font-size: 13px; line-height: 1.5; text-align: left; }
.mpd-col-name { width: 1%; }
.mpd-assignee-td-name { color: #2b2b2b; font-weight: 500; white-space: nowrap; padding-left: 8px; padding-right: 18px; }
.mpd-assignee-td-name .mpd-assignee-mark { display: inline-block; width: 18px; text-align: center; font-weight: 700; font-size: 14px; color: #b8b8b8; margin-right: 6px; }
.mpd-assignee-table tr.is-done .mpd-assignee-td-name,
.mpd-assignee-table tr.is-done .mpd-assignee-mark { color: #2e7d32; }
.mpd-assignee-td-result { color: #444; white-space: pre-wrap; word-break: break-word; text-align: left; cursor: text; }
.mpd-assignee-edit { width: 100%; box-sizing: border-box; padding: 6px 8px; border: 1px solid #E76F51; border-radius: 5px; font-size: 13px; line-height: 1.5; font-family: inherit; color: #2b2b2b; background: #fff; resize: none; overflow: hidden; min-height: 30px; }
.mpd-assignee-edit:focus { outline: none; box-shadow: 0 0 0 2px rgba(231, 111, 81, 0.18); }

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
