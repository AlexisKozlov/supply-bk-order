<template>
  <div class="mpd-view" ref="rootRef">
    <!-- Шапка липкая: страница длинная, и раньше за «Сохранить» приходилось
         мотать вверх через весь протокол -->
    <div class="mpd-top-bar">
      <div class="mpd-top-left">
        <button class="mpd-back" @click="goBack">&larr; К списку</button>
        <span v-if="!loading && protocol.id" class="mpd-summary">
          <span>{{ summary.total }} {{ taskWord(summary.total) }}</span>
          <span v-if="summary.overdue" class="mpd-summary-overdue">{{ summary.overdue }} просрочено</span>
          <span v-if="summary.done" class="mpd-summary-done">{{ summary.done }} выполнено</span>
        </span>
        <span v-if="dirty" class="mpd-unsaved">Не сохранено</span>
      </div>
      <div class="mpd-top-actions">
        <button v-if="canEdit" class="mpd-btn mpd-btn-primary" @click="save" :disabled="saving"><BurgerSpinner v-if="saving" size="xs" /><span>{{ saving ? 'Сохранение...' : 'Сохранить' }}</span></button>
        <button v-if="canEdit && protocol.status === 'draft'" class="mpd-btn mpd-btn-finalize" @click="finalize">Финализировать</button>
        <div v-if="protocol.id || canDelete" class="mpd-more-wrap">
          <button class="mpd-btn mpd-more" title="Ещё" aria-label="Ещё действия" :aria-expanded="moreOpen ? 'true' : 'false'" @click.stop="moreOpen = !moreOpen">&#8943;</button>
          <div v-if="moreOpen" class="mpd-more-menu" @click.stop>
            <button v-if="protocol.id" class="mpd-more-item" @click="runMore(exportExcel)">Выгрузить в Excel</button>
            <button v-if="protocol.id" class="mpd-more-item" @click="runMore(exportPdf)">Выгрузить в PDF</button>
            <button v-if="canDelete" class="mpd-more-item mpd-more-danger" @click="runMore(deleteProtocol)">Удалить протокол</button>
          </div>
        </div>
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

      <!-- Участники: длинный список раньше занимал на телефоне целый экран,
           поэтому больше пяти показываем только по запросу -->
      <div class="mpd-section mpd-section-compact">
        <h3>Участники <span class="mpd-count">({{ protocol.participants.length }})</span></h3>
        <div class="mpd-participants">
          <div v-for="name in shownParticipants" :key="name" class="mpd-participant-tag">
            {{ name }}
            <button v-if="canEdit" class="mpd-tag-remove" title="Убрать участника" @click="removeParticipant(name)">&times;</button>
          </div>
          <button v-if="hiddenParticipants" class="mpd-participant-more" @click="allParticipants = true">ещё {{ hiddenParticipants }}</button>
          <button v-if="allParticipants && protocol.participants.length > 5" class="mpd-participant-more" @click="allParticipants = false">свернуть</button>
          <div v-if="canEdit && availableUsers.length" class="mpd-add-wrap">
            <button class="mpd-add-btn" title="Добавить участника" @click="showUserPicker = !showUserPicker">+</button>
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

      <!-- Долги с прошлых совещаний: свёрнуты, чтобы не занимать экран.
           В счётчике только незакрытое — именно оно требует внимания -->
      <div v-if="carryoverTasks.length" class="mpd-section mpd-section-carryover">
        <button class="mpd-carryover-head" :aria-expanded="carryoverOpen ? 'true' : 'false'" @click="carryoverOpen = !carryoverOpen">
          <svg class="mpd-carryover-chevron" :class="{ 'is-open': carryoverOpen }" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
          <span class="mpd-carryover-title">С прошлых совещаний</span>
          <span class="mpd-carryover-num">{{ carryoverOpenCount }} {{ openWord(carryoverOpenCount) }} из {{ carryoverTasks.length }}</span>
        </button>
        <ProtocolTaskList
          v-if="carryoverOpen"
          :key="'carry-' + (protocol.id || 'new')"
          :tasks="carryoverTasks"
          mode="carryover"
          :can-edit-text="false"
          :can-change="canChangeCarryover"
          :is-mine="isTaskAssignee"
          :number-of="numberOfCarryover"
          :can-edit-description="canEditDescription"
          empty-state-text="Незакрытых задач с прошлых совещаний нет"
          @status="changeCarryoverStatus"
          @deadline="onCarryoverDeadlineChange"
          @save-description="saveAssigneeDescription"
        />
      </div>

      <!-- Задачи -->
      <div class="mpd-section">
        <h3>Задачи <span class="mpd-count">({{ protocol.decisions.length }})</span></h3>
        <ProtocolTaskList
          :key="'tasks-' + (protocol.id || 'new')"
          ref="ownListRef"
          :tasks="protocol.decisions"
          mode="own"
          :participants="protocol.participants"
          :can-edit-text="canEdit"
          :can-change="canChangeDecision"
          :is-mine="isTaskAssignee"
          :number-of="numberOfDecision"
          :empty-warning="emptyWarning"
          :can-edit-description="canEditDescription"
          :empty-state-text="protocol.decisions.length ? 'Все задачи выполнены' : 'Задач пока нет'"
          @status="changeDecisionStatus"
          @deadline="onDecisionDeadlineChange"
          @remove="removeDecision"
          @add="addDecision"
          @save-description="saveAssigneeDescription"
        />
      </div>

      <!-- Файлы -->
      <div v-if="protocol.id" class="mpd-section">
        <h3>Файлы <span class="mpd-count">({{ files.length }})</span></h3>
        <div v-if="files.length" class="mpd-files">
          <div v-for="f in files" :key="f.id" class="mpd-file">
            <a v-if="fileUrl(f)" :href="fileUrl(f)" target="_blank" class="mpd-file-name">{{ f.file_name }}</a>
            <span v-else class="mpd-file-name mpd-file-name-pending" title="Ссылка готовится...">{{ f.file_name }}</span>
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
      :ok-text="confirmModal.okText" :cancel-text="confirmModal.cancelText" :danger="confirmModal.danger"
      @confirm="onConfirm" @cancel="onCancel" />
  </div>
</template>

<script setup>
import { ref, computed, defineAsyncComponent, onMounted, onBeforeUnmount, onActivated, onDeactivated, watch, nextTick, inject } from 'vue';
import { useRouter, useRoute, onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router';
import { db, getDownloadUrl } from '@/lib/apiClient.js';
import { useUserStore } from '@/stores/userStore.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useToastStore } from '@/stores/toastStore.js';

const orderStore = useOrderStore();
import { useConfirm } from '@/composables/useConfirm.js';
import { confirmProtocolDueChange } from '@/lib/protocolDueGuard.js';

import ProtocolTaskList from '@/components/protocols/ProtocolTaskList.vue';

const ConfirmModal = defineAsyncComponent(() => import('@/components/modals/ConfirmModal.vue'));

const router = useRouter();
const route = useRoute();
const userStore = useUserStore();
const toast = useToastStore();

const loading = ref(false);
const saving = ref(false);
const dirty = ref(false);
const savedSnapshot = ref('');
const rootRef = ref(null);
// Слепок протокола ровно в том виде, в каком его отдал сервер при последней
// загрузке. Нужен, чтобы перед сохранением понять, менял ли протокол кто-то ещё.
const serverBaseline = ref(null);
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
const showUserPicker = ref(false);
// Меню «Ещё» в шапке: выгрузки и удаление — редкие действия,
// в липкой шапке им хватает одной кнопки
const moreOpen = ref(false);
function runMore(fn) {
  moreOpen.value = false;
  fn();
}
// Долги с прошлых совещаний по умолчанию свёрнуты
const carryoverOpen = ref(false);
const allParticipants = ref(false);

function blankProtocol() {
  return {
    id: null,
    series_id: null,
    meeting_date: new Date().toISOString().slice(0, 10),
    topic: '',
    participants: [],
    questions: '',
    notes: '',
    status: 'draft',
    created_by: userStore.currentUser?.name || '',
    decisions: [],
  };
}

const protocol = ref(blankProtocol());

// Прежние сроки задач. Поле даты через v-model успевает поменять значение
// до вызова обработчика, а нам нужно знать, что было до — чтобы вернуть
// прежний срок, если человек передумал или запрос не прошёл.
const dueBefore = new Map();

// Закрытый долг пропадает из списка с задержкой — таймер надо уметь снять
let carryoverHideTimer = null;

// Подсветка задач без текста включается, когда человек отказался их удалять
const emptyWarning = ref(false);

// Чистый лист для нового совещания: страница живёт в KeepAlive и после
// просмотра чужого протокола показывала бы его данные в форме создания
function resetProtocol() {
  protocol.value = blankProtocol();
  files.value = [];
  carryoverTasks.value = [];
  serverBaseline.value = null;
  dueBefore.clear();
  emptyWarning.value = false;
  carryoverOpen.value = false;
  allParticipants.value = false;
  moreOpen.value = false;
  showUserPicker.value = false;
  downloadUrls.value = {};
  if (rootRef.value) rootRef.value.scrollTop = 0;
}

const isAdminOrManager = computed(() => ['admin', 'manager'].includes(userStore.currentUser?.role));
const setTabTitle = inject('setTabTitle', () => {});
watch(() => protocol.value.topic, (t) => {
  setTabTitle(t ? 'Протокол: ' + t : 'Новый протокол');
});

// Те же условия, что проверяет сервер в save_protocol: право «Редактирование»
// плюс либо авторство, либо роль, либо уровень «Полный»
const canEdit = computed(() => {
  if (!userStore.hasAccess('protocols', 'edit')) return false;
  if (!protocol.value.id) return true;
  if (isAdminOrManager.value) return true;
  if (userStore.hasAccess('protocols', 'full')) return true;
  if (protocol.value.created_by === userStore.currentUser?.name) return true;
  return false;
});

// Удаление сервер тоже требует с правом «Редактирование» (delete_protocol):
// без этой проверки автор с доступом «Просмотр» видел кнопку и получал отказ
const canDelete = computed(() => {
  if (!protocol.value.id) return false;
  if (!userStore.hasAccess('protocols', 'edit')) return false;
  if (isAdminOrManager.value) return true;
  return protocol.value.created_by === userStore.currentUser?.name;
});

const availableUsers = computed(() =>
  allUsers.value.filter(u => !protocol.value.participants.includes(u.name))
);

function pickUser(name) {
  if (!protocol.value.participants.includes(name)) {
    protocol.value.participants.push(name);
  }
  showUserPicker.value = false;
}

function removeParticipant(name) {
  protocol.value.participants = protocol.value.participants.filter(n => n !== name);
}

// Больше пяти участников показываем только по кнопке: на телефоне
// длинный список раньше занимал экран целиком
const shownParticipants = computed(() => {
  const list = protocol.value.participants || [];
  return allParticipants.value ? list : list.slice(0, 5);
});
const hiddenParticipants = computed(() => {
  const rest = (protocol.value.participants || []).length - shownParticipants.value.length;
  return rest > 0 ? rest : 0;
});

// Сводка в шапке: сколько всего задач, сколько просрочено и закрыто
const summary = computed(() => {
  const list = protocol.value.decisions || [];
  return {
    total: list.length,
    done: list.filter(d => d.status === 'done').length,
    overdue: list.filter(d => d.status === 'overdue').length,
  };
});

const carryoverOpenCount = computed(() => carryoverTasks.value.filter(t => t.status !== 'done').length);

function taskWord(n) {
  const mod10 = n % 10;
  const mod100 = n % 100;
  if (mod10 === 1 && mod100 !== 11) return 'задача';
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return 'задачи';
  return 'задач';
}

function openWord(n) {
  const mod10 = n % 10;
  const mod100 = n % 100;
  if (mod10 === 1 && mod100 !== 11) return 'открытая';
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return 'открытые';
  return 'открытых';
}

// Номер задачи считаем по исходному списку, а не по отфильтрованному:
// иначе при включённом фильтре номера разъедутся с выгрузками в Excel и PDF
function numberOfDecision(dec) { return (protocol.value.decisions || []).indexOf(dec) + 1; }
function numberOfCarryover(t) { return carryoverTasks.value.indexOf(t) + 1; }

// Удаляем по самому объекту: при включённом фильтре индекс строки на экране
// не совпадает с индексом в списке задач протокола
function removeDecision(dec) {
  const idx = protocol.value.decisions.indexOf(dec);
  if (idx >= 0) protocol.value.decisions.splice(idx, 1);
}

async function changeCarryoverStatus(t, newStatus) {
  if (!canChangeCarryover(t)) return;
  if (t.status === newStatus) return;
  const prev = t.status;
  t.status = newStatus;
  await onCarryoverStatusChange(t, prev);
}

async function changeDecisionStatus(dec, newStatus) {
  if (!canChangeDecision(dec)) return;
  if (dec.status === newStatus) return;
  const prev = dec.status;
  const prevCompletedAt = dec.completed_at;
  dec.status = newStatus;
  await onDecisionStatusChange(dec, prev, prevCompletedAt);
}

// Описание исполнителя уходит в базу сразу, мимо кнопки «Сохранить»:
// это поле карточки в модуле «Задачи», а не поле протокола
async function saveAssigneeDescription(a, original) {
  const cardId = a.card_id;
  if (!cardId) return;
  const newDesc = a.description || '';
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
  } catch {
    a.description = original;
    toast.error('Ошибка сети при сохранении');
  }
}

async function loadCarryoverTasks() {
  if (!protocol.value.series_id) { carryoverTasks.value = []; return; }
  const { data } = await db.rpc('get_carryover_tasks', {
    series_id: protocol.value.series_id,
    exclude_protocol_id: protocol.value.id || 0,
  });
  carryoverTasks.value = (data || []).map(t => ({ ...t, responsible_person: normalizeResponsible(t.responsible_person) }));
  rememberDue(carryoverTasks.value);
}

async function onCarryoverStatusChange(t, prevStatus) {
  const { error } = await db.rpc('update_decision_status', { id: t.id, status: t.status });
  if (error) {
    // Запрос не прошёл — возвращаем статус как было, чтобы экран не врал
    t.status = prevStatus;
    toast.error('Ошибка', 'Не удалось изменить статус задачи');
    return;
  }
  if (t.status === 'done') {
    // Убираем из списка через секунду
    if (carryoverHideTimer) clearTimeout(carryoverHideTimer);
    carryoverHideTimer = setTimeout(() => {
      carryoverHideTimer = null;
      carryoverTasks.value = carryoverTasks.value.filter(x => x.id !== t.id);
    }, 800);
  }
}

function rememberDue(list) {
  for (const t of list || []) if (t.id) dueBefore.set(t.id, t.deadline || null);
}

// Общий перенос срока: спрашиваем подтверждение (срок общий — сдвинется
// в решении и у всех соисполнителей), пишем в базу, при отказе/ошибке
// возвращаем прежнюю дату. true — срок действительно перенесён.
async function applyDeadlineChange(t) {
  const prev = dueBefore.has(t.id) ? dueBefore.get(t.id) : null;
  const next = t.deadline || null;
  if (next === prev) return false;
  const ok = await confirmProtocolDueChange({ protocol_decision_id: t.id, due_date: prev }, next, confirm);
  if (!ok) { t.deadline = prev || ''; return false; }
  const { error } = await db.rpc('update_decision_deadline', { id: t.id, deadline: next });
  if (error) {
    t.deadline = prev || '';
    toast.error('Ошибка', error === 'Session expired' ? 'Сессия истекла' : 'Не удалось перенести срок');
    return false;
  }
  dueBefore.set(t.id, next);
  return true;
}

async function onCarryoverDeadlineChange(t) {
  if (!canChangeCarryover(t)) { t.deadline = dueBefore.get(t.id) || ''; return; }
  // Перечитываем список: перенос срока вперёд снимает на сервере «Просрочено»
  if (await applyDeadlineChange(t)) await loadCarryoverTasks();
}

// Срок задачи текущего протокола обычно уходит в базу по кнопке «Сохранить».
// Но у ответственного без права «Редактирование» этой кнопки нет — для него
// переносим срок сразу, иначе изменение просто потерялось бы.
async function onDecisionDeadlineChange(dec) {
  if (canEdit.value || !dec.id) return;
  if (!canChangeDecision(dec)) { dec.deadline = dueBefore.get(dec.id) || ''; return; }
  if (!await applyDeadlineChange(dec)) return;
  const base = serverBaseline.value?.decisions?.[dec.id];
  if (base) base.dl = dec.deadline || '';
  markClean();
}

// Ответственный за задачу — сравнение точное, после обрезки пробелов
// (список ответственных приходит строкой через запятую). Дополнительно
// считаем ответственным того, у кого есть карточка исполнителя: в «Задачах»
// человека могли добавить только карточкой — сервер это тоже учитывает.
function isTaskAssignee(t) {
  const me = (userStore.currentUser?.name || '').trim();
  if (!me) return false;
  const names = Array.isArray(t.responsible_person)
    ? t.responsible_person
    : normalizeResponsible(t.responsible_person);
  if (names.some(n => String(n || '').trim() === me)) return true;
  return (t.assignees_progress || []).some(a => String(a.user_name || '').trim() === me);
}

// Право «Редактирование» в разделе (без привязки к автору протокола) —
// именно это проверяет сервер для чужих задач
const canEditModule = computed(() => userStore.hasAccess('protocols', 'edit'));

// Статус и срок задачи: сервер (update_decision_status, update_decision_deadline)
// требует либо «Редактирование» в разделе, либо быть ответственным — авторство
// протокола здесь ни при чём. Правило одно и то же для задач протокола и для
// долгов с прошлых совещаний, иначе одинаковые строки вели бы себя по-разному.
function canChangeDecision(dec) {
  return canEditModule.value || isTaskAssignee(dec);
}

// Отчёт о работе правит только сам исполнитель (или руководитель):
// сервер в модуле «Задачи» чужую карточку не отдаст
function canEditDescription(a) {
  if (isAdminOrManager.value) return true;
  return String(a?.user_name || '').trim() === (userStore.currentUser?.name || '').trim();
}

function canChangeCarryover(t) {
  return canEditModule.value || isTaskAssignee(t);
}

const ownListRef = ref(null);
function addDecision() {
  protocol.value.decisions.push({ id: null, text: '', responsible_person: [], deadline: '', status: 'pending' });
  // Иначе при фильтре «Мои» новая задача (пока без ответственных) не появится
  // на экране, человек нажмёт кнопку ещё раз и получит вопрос про пустые задачи
  ownListRef.value?.showTask?.(protocol.value.decisions[protocol.value.decisions.length - 1]);
}

function autoResize(e) {
  const el = e.target;
  el.style.height = 'auto';
  el.style.height = el.scrollHeight + 'px';
}

// База принимает дату только в виде «2026-08-13 15:17:29». Раньше сюда клали
// ISO-строку с «T» и «Z», и сохранение протокола после закрытия задачи
// отваливалось с ошибкой сервера.
function mysqlNow() {
  const d = new Date();
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
}

async function onDecisionStatusChange(dec, prevStatus, prevCompletedAt) {
  if (dec.status === 'done' && !dec.completed_at) dec.completed_at = mysqlNow();
  if (dec.status !== 'done') dec.completed_at = null;
  // Быстрое обновление статуса для существующих решений
  if (!dec.id) return;
  const { error } = await db.rpc('update_decision_status', { id: dec.id, status: dec.status });
  if (error) {
    // Запрос не прошёл — возвращаем статус как было, чтобы экран не врал
    dec.status = prevStatus;
    dec.completed_at = prevCompletedAt ?? null;
    toast.error('Ошибка', 'Не удалось изменить статус задачи');
    return;
  }
  // Статус ушёл в базу сразу — синхронизируем базовый слепок, иначе
  // перед следующим сохранением это будет выглядеть как «чужое изменение».
  const base = serverBaseline.value?.decisions?.[dec.id];
  if (base) base.s = dec.status || '';
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

// Слепок «как на сервере»: по нему видно, менял ли протокол кто-то ещё,
// пока страница была открыта. updated_at ловит правки самого протокола,
// статусы/сроки решений — правки из модуля «Задачи» (там updated_at не трогается).
function serverSnapshot(p) {
  const decisions = {};
  for (const d of (p.decisions || [])) {
    if (!d.id) continue;
    decisions[d.id] = { s: d.status || '', dl: d.deadline || '', t: d.text || '' };
  }
  return { u: p.updated_at || '', decisions };
}

// Проверяем, не изменился ли протокол на сервере с момента загрузки страницы.
// Возвращает 'ok' — можно сохранять, 'abort' — сохранение отменено.
async function checkForRemoteChanges() {
  if (!protocol.value.id || !serverBaseline.value) return 'ok';
  const { data, error } = await db.rpc('get_protocol', { id: Number(protocol.value.id) });
  // Не смогли проверить — не блокируем сохранение, иначе работа встанет
  if (error || !data) return 'ok';
  const fresh = serverSnapshot(data);
  if (JSON.stringify(fresh) === JSON.stringify(serverBaseline.value)) return 'ok';

  const overwrite = await confirm(
    'Протокол изменили',
    'Протокол изменили, пока страница была открыта: кто-то поправил его или закрыл задачу в модуле «Задачи».\n\nСохранить вашу версию поверх? Чужие изменения при этом пропадут.',
    { okText: 'Перезаписать', cancelText: 'Нет', danger: true },
  );
  if (overwrite) return 'ok';

  const reload = await confirm(
    'Обновить страницу',
    'Загрузить свежую версию протокола? Ваши несохранённые правки будут потеряны.',
    { okText: 'Обновить', cancelText: 'Отмена' },
  );
  if (reload) await loadProtocol(protocol.value.id, { silent: true });
  return 'abort';
}

function isEmptyText(dec) { return !String(dec.text || '').trim(); }

// «1 пустую задачу» / «2 пустые задачи» / «5 пустых задач»
function emptyTasksPhrase(n) {
  const mod10 = n % 10;
  const mod100 = n % 100;
  if (mod10 === 1 && mod100 !== 11) return `${n} пустую задачу`;
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return `${n} пустые задачи`;
  return `${n} пустых задач`;
}

// Задачи без текста сервер не сохраняет. Раньше они молча исчезали —
// теперь спрашиваем. Возвращает true, если можно продолжать сохранение.
async function confirmEmptyTasks() {
  const empty = protocol.value.decisions.filter(isEmptyText);
  if (!empty.length) { emptyWarning.value = false; return true; }
  const ok = await confirm(
    'Задачи без текста',
    `Удалить ${emptyTasksPhrase(empty.length)}?\n\nЗадачи без текста не сохраняются: они исчезнут из протокола, а карточки исполнителей по ним уйдут в архив.`,
    { okText: 'Удалить', cancelText: 'Вернуться к форме', danger: true },
  );
  if (ok) { emptyWarning.value = false; return true; }
  // Отказался — показываем, какие именно строки пустые
  emptyWarning.value = true;
  nextTick(() => {
    const el = rootRef.value?.querySelector('.ptl-text.is-empty-warn');
    if (el) el.scrollIntoView({ block: 'center', behavior: 'smooth' });
  });
  return false;
}

// Возвращает true, если сохранение прошло (нужно для finalize).
async function save() {
  if (!protocol.value.topic.trim()) { toast.error('Укажите тему совещания'); return false; }
  if (!await confirmEmptyTasks()) return false;
  if (await checkForRemoteChanges() === 'abort') return false;
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
  if (error) { toast.error(error); return false; }
  markClean();
  toast.success('Протокол сохранён');
  const isNew = !protocol.value.id;
  const savedId = protocol.value.id || data?.id;
  if (isNew && data?.id) {
    protocol.value.id = data.id;
    // Протокол перечитаем сами (без мигания) — watch на route.params.id пропускаем
    skipRouteLoad = true;
    router.replace({ name: 'protocol-detail', params: { id: data.id } });
  }
  // ВАЖНО: перечитываем протокол всегда. Иначе у только что добавленных задач
  // не появится id, и следующее «Сохранить» отправит их как новые — сервер
  // пересоздаст задачи, а старые уберёт в архив вместе с работой исполнителей.
  if (savedId) await loadProtocol(savedId, { silent: true });
  return true;
}

async function finalize() {
  if (!await confirm('Финализация', 'Финализировать протокол? Участникам будет отправлено уведомление в Telegram.')) return;
  const prevStatus = protocol.value.status;
  protocol.value.status = 'final';
  const ok = await save();
  // Не сохранилось — возвращаем прежний статус, иначе на экране «Финальный»,
  // а в базе всё ещё «Черновик». Если протокол успели перечитать с сервера,
  // ничего не трогаем — там уже актуальный статус.
  if (!ok && protocol.value.status === 'final') protocol.value.status = prevStatus;
}

async function deleteProtocol() {
  if (!await confirm('Удаление', 'Удалить этот протокол? Это действие нельзя отменить.')) return;
  const { error } = await db.rpc('delete_protocol', { id: protocol.value.id });
  if (error) { toast.error('Ошибка', 'Не удалось удалить протокол'); return; }
  toast.success('Протокол удалён');
  markClean();
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
      // Массив заменяем целиком: push «на месте» не будит watch,
      // и у нового файла не появлялась ссылка для скачивания
      files.value = [...files.value, { id: data.id, file_name: data.file_name, file_path: data.file_path, uploaded_by: data.uploaded_by }];
      await refreshDownloadUrls();
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
  try {
    const res = await fetch(`/api/upload/protocol-file?file_id=${f.id}`, { method: 'DELETE', headers: { 'X-Session-Token': token } });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.error) {
      toast.error(data.error || 'Не удалось удалить файл');
      return;
    }
  } catch {
    toast.error('Ошибка сети при удалении файла');
    return;
  }
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

// Общее подтверждение ухода со страницы: и для кнопки «К списку»,
// и для перехода по разделу в боковом меню.
async function confirmLeave() {
  recalcDirty();
  if (!dirty.value) return true;
  return await confirm('Несохранённые изменения', 'Вы не сохранили протокол. Уйти без сохранения?');
}

async function goBack() {
  if (!await confirmLeave()) return;
  markClean();
  router.push({ name: 'protocols' });
}

// Уход со страницы через меню/навигацию тоже спрашивает про несохранённое
onBeforeRouteLeave(async () => {
  return await confirmLeave();
});

// Переход на другой протокол (тот же раздел, другой адрес) — тоже спрашиваем
onBeforeRouteUpdate(async (to, from) => {
  if (to.params.id === from.params.id) return true;
  return await confirmLeave();
});

// В слепок для признака «Не сохранено» входят ТОЛЬКО поля, которые записывает
// кнопка «Сохранить». Описание исполнителя и статус задачи уходят в базу сразу,
// поэтому их здесь нет — иначе экран показывал бы «Не сохранено» на пустом месте.
function currentSnapshot() {
  const p = protocol.value;
  return JSON.stringify({
    t: p.topic || '',
    md: p.meeting_date || '',
    s: p.status || '',
    si: p.series_id ?? null,
    p: p.participants || [],
    q: p.questions || '',
    n: p.notes || '',
    d: (p.decisions || []).map(d => ({
      id: d.id || null,
      x: d.text || '',
      r: Array.isArray(d.responsible_person) ? d.responsible_person : normalizeResponsible(d.responsible_person),
      dl: d.deadline || '',
    })),
  });
}

function takeSnapshot() {
  savedSnapshot.value = currentSnapshot();
}

// Пересчёт «Не сохранено» отложенный: иначе на каждый символ в «Вопросах»
// сериализуется весь протокол.
let dirtyTimer = null;
function recalcDirty() {
  if (dirtyTimer) { clearTimeout(dirtyTimer); dirtyTimer = null; }
  if (!savedSnapshot.value) return;
  dirty.value = currentSnapshot() !== savedSnapshot.value;
}

// «Всё записано»: снимаем слепок заново и гасим отложенный пересчёт,
// иначе он тут же вернёт «Не сохранено» и страница переспросит про уход.
function markClean() {
  if (dirtyTimer) { clearTimeout(dirtyTimer); dirtyTimer = null; }
  takeSnapshot();
  dirty.value = false;
}

function beforeUnloadHandler(e) {
  if (dirty.value) { e.preventDefault(); e.returnValue = ''; }
}

// silent: true — перечитать данные без спиннера и с сохранением прокрутки
// (используется после сохранения, чтобы страница не «прыгала»).
async function loadProtocol(id, opts = {}) {
  const silent = !!opts.silent;
  const scrollTop = silent ? (rootRef.value?.scrollTop || 0) : 0;
  if (!silent) loading.value = true;
  const { data, error } = await db.rpc('get_protocol', { id: Number(id) });
  // Пока шёл запрос, могли уйти на другой протокол или на форму нового —
  // поздний ответ не должен затирать то, что уже на экране
  const cur = String(route.params.id ?? '');
  if (cur && cur !== 'new' && cur !== String(id)) { loading.value = false; return; }
  if (cur === 'new' && !silent) { loading.value = false; return; }
  if (error || !data) {
    loading.value = false;
    toast.error('Протокол не найден');
    markClean();
    router.push({ name: 'protocols' });
    return;
  }
  data.participants = typeof data.participants === 'string' ? JSON.parse(data.participants) : (data.participants || []);
  data.decisions = (data.decisions || []).map(d => ({ ...d, responsible_person: normalizeResponsible(d.responsible_person) }));
  files.value = data.files || [];
  delete data.files;
  serverBaseline.value = serverSnapshot(data);
  rememberDue(data.decisions);
  protocol.value = data;
  loading.value = false;
  emptyWarning.value = false;
  markClean();
  loadCarryoverTasks();
  nextTick(() => {
    if (questionsRef.value) { questionsRef.value.style.height = 'auto'; questionsRef.value.style.height = questionsRef.value.scrollHeight + 'px'; }
    if (notesRef.value) { notesRef.value.style.height = 'auto'; notesRef.value.style.height = notesRef.value.scrollHeight + 'px'; }
    if (silent && rootRef.value) rootRef.value.scrollTop = scrollTop;
  });
}

watch(protocol, () => {
  if (loading.value || !savedSnapshot.value) return;
  if (dirtyTimer) clearTimeout(dirtyTimer);
  dirtyTimer = setTimeout(() => { dirtyTimer = null; recalcDirty(); }, 300);
}, { deep: true });

// Клик мимо — закрываем меню «Ещё» и список участников
function closePickerOnOutsideClick(e) {
  if (moreOpen.value && !e.target.closest('.mpd-more-wrap')) moreOpen.value = false;
  if (showUserPicker.value && !e.target.closest('.mpd-add-wrap')) showUserPicker.value = false;
}

// Нормализация responsible_person: строка → массив (для совместимости со старыми данными)
function normalizeResponsible(rp) {
  if (Array.isArray(rp)) return rp;
  if (!rp) return [];
  return rp.split(',').map(s => s.trim()).filter(Boolean);
}

onMounted(() => window.addEventListener('beforeunload', beforeUnloadHandler));

// Страница остаётся в кэше вкладок, поэтому слушатель клика вешаем только
// на время, пока раздел открыт — иначе он срабатывает по всему порталу
onActivated(() => document.addEventListener('click', closePickerOnOutsideClick));
onDeactivated(() => document.removeEventListener('click', closePickerOnOutsideClick));

// Список форматов зависит от юрлица, список пользователей — нет
async function loadSeriesList() {
  const { data } = await db.rpc('get_protocol_series', { legal_entity: orderStore.settings.legalEntity });
  const list = data || [];
  // Формат, к которому уже привязан протокол, оставляем в списке даже если он
  // от другого юрлица — иначе привязка молча слетит при сохранении
  const bound = protocol.value.series_id;
  if (bound && !list.some(s => String(s.id) === String(bound))) {
    list.unshift({ id: bound, name: protocol.value.series_name || 'Текущий формат' });
  }
  seriesList.value = list;
}

async function loadUsersList() {
  if (allUsers.value.length) return;
  const { data } = await db.rpc('get_users_list_short');
  if (data) allUsers.value = data;
}

// Флаг: протокол только что сохранён и перечитан вручную,
// повторную загрузку по смене адреса пропускаем
let skipRouteLoad = false;

// Справочники + загрузка протокола. Watch с immediate вместо onMounted,
// чтобы при переходе между /protocols/:id с разными id KeepAlive не показывал
// данные предыдущего протокола (см. AppLayout, ключ — route.name).
watch(() => route.params.id, async (id) => {
  if (skipRouteLoad) { skipRouteLoad = false; return; }
  // Экран переиспользуется (KeepAlive), поэтому форму нового совещания
  // очищаем сразу, ДО запросов справочников: иначе полсекунды на экране
  // видны данные предыдущего протокола и в них можно начать печатать
  if (!id || id === 'new') resetProtocol();
  await Promise.all([loadUsersList(), loadSeriesList()]);
  // Пока грузились справочники, могли уйти на другой протокол
  if (String(route.params.id ?? '') !== String(id ?? '')) return;
  if (id && id !== 'new') {
    await loadProtocol(id);
  } else {
    const qSeries = route.query.series_id;
    if (qSeries) {
      // id из адреса — строка, а в списке значения могут быть числами:
      // берём значение из самого списка, иначе предвыбор не срабатывает
      const found = seriesList.value.find(s => String(s.id) === String(qSeries));
      protocol.value.series_id = found ? found.id : qSeries;
      onSeriesChange();
    }
    // Новый протокол: сразу считаем состояние «сохранённым»,
    // иначе шаблон вопросов из формата сам по себе даст «Не сохранено»
    markClean();
  }
}, { immediate: true });

// Юрлицо переключили — перечитываем список форматов,
// иначе можно привязать протокол к формату другого юрлица
watch(() => orderStore.settings.legalEntity, () => { loadSeriesList(); });

onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', beforeUnloadHandler);
  document.removeEventListener('click', closePickerOnOutsideClick);
  if (dirtyTimer) { clearTimeout(dirtyTimer); dirtyTimer = null; }
  if (carryoverHideTimer) { clearTimeout(carryoverHideTimer); carryoverHideTimer = null; }
});
</script>

<style scoped>
.mpd-view { padding: 0; }

/* Липкая шапка: протокол длинный, за «Сохранить» больше не надо
   мотать страницу вверх. Работает потому, что прокручивается сам .mpd-view */
.mpd-top-bar { position: sticky; top: 0; z-index: 60; display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 14px; padding: 8px 0; background: var(--bg-page, #faf7f2); border-bottom: 1px solid #ececec; }
.mpd-top-left { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; min-width: 0; }
.mpd-top-actions { display: flex; gap: 6px; flex-wrap: nowrap; justify-content: flex-end; align-items: center; }
.mpd-back { border: none; background: none; font-size: 13px; cursor: pointer; color: #E76F51; padding: 4px 0; white-space: nowrap; }
.mpd-back:hover { text-decoration: underline; }

/* Сводка по задачам — видна всегда, вместе с липкой шапкой */
.mpd-summary { display: flex; align-items: center; gap: 10px; font-size: 12px; color: #777; flex-wrap: wrap; }
.mpd-summary-overdue { color: #c62828; font-weight: 600; }
.mpd-summary-done { color: #2e7d32; }

/* Меню «Ещё»: выгрузки и удаление */
.mpd-more-wrap { position: relative; }
.mpd-more { font-size: 16px; line-height: 1; padding: 6px 10px; color: #666; }
.mpd-more-menu { position: absolute; top: calc(100% + 4px); right: 0; display: flex; flex-direction: column; min-width: 190px; background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; box-shadow: 0 6px 18px rgba(0,0,0,0.1); padding: 4px; z-index: 70; }
.mpd-more-item { border: none; background: none; text-align: left; padding: 8px 10px; border-radius: 6px; font-size: 13px; color: #333; cursor: pointer; white-space: nowrap; }
.mpd-more-item:hover { background: #f5f5f5; }
.mpd-more-danger { color: #c62828; }
.mpd-more-danger:hover { background: #fdf2f2; }

.mpd-content { display: flex; flex-direction: column; gap: 10px; }
.mpd-section { background: #fff; border: 1px solid #e8e8e8; border-radius: 8px; padding: 14px 16px; }
.mpd-section-compact { padding: 10px 16px 12px; }
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

/* Участники */
.mpd-participants { display: flex; flex-wrap: wrap; gap: 5px; align-items: center; }
.mpd-participant-tag { display: inline-flex; align-items: center; gap: 4px; background: #f0f0f0; padding: 4px 10px; border-radius: 12px; font-size: 12px; }
.mpd-tag-remove { border: none; background: none; font-size: 15px; cursor: pointer; color: #999; line-height: 1; padding: 0 2px; }
.mpd-tag-remove:hover { color: #E76F51; }
.mpd-participant-more { border: 1px dashed #d5d5d5; background: none; border-radius: 12px; padding: 4px 10px; font-size: 12px; color: #777; cursor: pointer; }
.mpd-participant-more:hover { border-color: #E76F51; color: #E76F51; }
.mpd-add-wrap { position: relative; }
.mpd-add-btn { width: 28px; height: 28px; border-radius: 50%; border: 1.5px dashed #bbb; background: none; font-size: 18px; line-height: 1; color: #888; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.mpd-add-btn:hover { border-color: #E76F51; color: #E76F51; }
.mpd-picker { position: absolute; top: 34px; left: 0; background: #fff; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.12); z-index: 20; min-width: 200px; max-height: 220px; overflow-y: auto; }
.mpd-picker-item { padding: 7px 12px; cursor: pointer; font-size: 13px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.mpd-picker-item:hover { background: #f5f5f5; }
.mpd-picker-name { white-space: nowrap; }
.mpd-picker-role { font-size: 11px; color: #999; white-space: nowrap; text-align: right; }

/* Долги с прошлых совещаний */
.mpd-section-carryover { border-color: #ffe0b2; background: #fffaf0; }
.mpd-carryover-head { display: flex; align-items: center; gap: 8px; width: 100%; border: none; background: none; padding: 2px 0; font-size: 14px; color: #e65100; cursor: pointer; text-align: left; }
.mpd-carryover-title { font-weight: 600; }
.mpd-carryover-num { font-size: 12px; font-weight: 400; color: #a1703b; }
.mpd-carryover-chevron { transition: transform 0.15s; flex-shrink: 0; }
.mpd-carryover-chevron.is-open { transform: rotate(90deg); }
.mpd-section-carryover :deep(.ptl-row) { background: transparent; }

/* Кнопки */
.mpd-btn { padding: 6px 14px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; cursor: pointer; background: #fff; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px; }
.mpd-btn:hover { background: #f5f5f5; }
.mpd-btn-primary { background: #E76F51; color: #fff; border-color: #E76F51; }
.mpd-btn-primary:hover { background: #b52200; }
.mpd-btn-primary:disabled { opacity: 0.6; cursor: default; }
.mpd-btn-finalize { background: #2e7d32; color: #fff; border-color: #2e7d32; }
.mpd-btn-finalize:hover { background: #1b5e20; }

/* Файлы */
.mpd-files { display: flex; flex-direction: column; gap: 4px; margin-bottom: 8px; }
.mpd-file { display: flex; align-items: center; gap: 10px; padding: 5px 8px; border-radius: 4px; }
.mpd-file:hover { background: #f8f8f8; }
.mpd-file-name { color: #1565c0; text-decoration: none; font-size: 13px; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mpd-file-name:hover { text-decoration: underline; }
/* Ссылка ещё не готова — показываем просто имя, чтобы клик не уводил со страницы */
.mpd-file-name-pending { color: #999; cursor: default; }
.mpd-file-name-pending:hover { text-decoration: none; }
.mpd-file-meta { font-size: 11px; color: #999; white-space: nowrap; }
.mpd-files-empty { font-size: 12px; color: #aaa; margin-bottom: 8px; }
.mpd-btn-upload { border-style: dashed; color: #888; padding: 7px 14px; cursor: pointer; display: inline-block; text-align: center; border: 1px dashed #ddd; border-radius: 6px; font-size: 13px; }
.mpd-btn-upload:hover { border-color: #E76F51; color: #E76F51; }
.mpd-row-del { border: none; background: transparent; color: #c0c0c0; cursor: pointer; padding: 4px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; }
.mpd-row-del:hover { color: #c62828; background: #fde2e2; }

.mpd-unsaved { font-size: 11px; color: #e65100; font-weight: 500; background: #fff3e0; padding: 3px 8px; border-radius: 10px; white-space: nowrap; }
.mpd-loading { text-align: center; padding: 40px; color: #888; }

@media (max-width: 700px) {
  .mpd-row { grid-template-columns: 1fr; }
  .mpd-top-bar { flex-wrap: wrap; gap: 6px; padding: 6px 0; }
  .mpd-top-left { order: 2; width: 100%; gap: 8px; }
  .mpd-top-actions { order: 1; width: 100%; justify-content: space-between; }
  .mpd-top-actions .mpd-btn-primary { flex: 1 1 auto; justify-content: center; }
  .mpd-summary { font-size: 11px; gap: 8px; }
  .mpd-section { padding: 12px; }
}
</style>
