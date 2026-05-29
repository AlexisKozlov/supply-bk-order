<template>
  <div v-if="modelValue" class="tpl-overlay" @click.self="close">
    <div class="tpl-modal" @click.stop>
      <!-- Шапка -->
      <header class="tpl-head">
        <button v-if="mode === 'edit'" class="tpl-back" @click="goBack" title="К списку">
          <TaskIcon name="chevronLeft" :size="14"/>
        </button>
        <h2 class="tpl-title">
          {{ mode === 'list' ? 'Мои шаблоны' : (current?.title || 'Шаблон') }}
        </h2>
        <button class="tpl-close" @click="close" title="Закрыть">
          <TaskIcon name="close" :size="14"/>
        </button>
      </header>

      <!-- Режим: список -->
      <div v-if="mode === 'list'" class="tpl-list-wrap">
        <div class="tpl-list-head">
          <p class="tpl-hint">Один шаблон → можно положить на несколько досок с разной периодичностью.</p>
          <button class="btn primary" @click="onCreateClick">
            <TaskIcon name="plus" :size="14"/>
            <span>Новый шаблон</span>
          </button>
        </div>

        <div v-if="loading" class="tpl-empty">Загрузка…</div>
        <div v-else-if="!templates.length" class="tpl-empty">
          <div class="tpl-empty-icon"><TaskIcon name="calendar" :size="22"/></div>
          <div class="tpl-empty-text">Шаблонов пока нет</div>
          <div class="tpl-empty-hint">Добавьте первый шаблон, чтобы карточки появлялись автоматически</div>
        </div>
        <ul v-else class="tpl-cards">
          <li v-for="t in templates" :key="t.id" class="tpl-card" @click="openEditor(t.id)">
            <div class="tpl-card-main">
              <div class="tpl-card-title">{{ t.title }}</div>
              <div class="tpl-card-meta">
                <span class="tpl-chip" :class="'pri-' + t.priority">{{ priorityLabel(t.priority) }}</span>
                <span class="tpl-chip">{{ t.schedules_active }}/{{ t.schedules_total }} активных расп.</span>
                <span v-if="t.assignees_count" class="tpl-chip">{{ t.assignees_count }} испол.</span>
              </div>
            </div>
            <TaskIcon name="chevronRight" :size="14" class="tpl-card-chev"/>
          </li>
        </ul>
      </div>

      <!-- Режим: редактор -->
      <div v-if="mode === 'edit' && current" class="tpl-edit">
        <!-- Тело карточки -->
        <section class="tpl-section">
          <h3 class="tpl-section-title">Карточка</h3>
          <label class="tpl-field">
            <span class="tpl-field-label">Название</span>
            <input v-model="current.title" type="text" class="tpl-input" @change="saveField('title')" maxlength="255"/>
          </label>
          <label class="tpl-field">
            <span class="tpl-field-label">Описание</span>
            <textarea v-model="current.description" class="tpl-input tpl-textarea" rows="3" @change="saveField('description')"></textarea>
          </label>
          <label class="tpl-field">
            <span class="tpl-field-label">Приоритет</span>
            <select v-model="current.priority" class="tpl-input" @change="saveField('priority')">
              <option value="low">Низкий</option>
              <option value="medium">Обычный</option>
              <option value="high">Высокий</option>
              <option value="urgent">Срочный</option>
            </select>
          </label>
        </section>

        <!-- Исполнители -->
        <section class="tpl-section">
          <h3 class="tpl-section-title">Исполнители</h3>
          <p v-if="!current.assignees?.length" class="tpl-section-empty">Не назначены</p>
          <div v-else class="tpl-pills">
            <span v-for="u in current.assignees" :key="u" class="tpl-pill">
              {{ u }}
              <button class="tpl-pill-del" @click="removeAssignee(u)" title="Убрать">×</button>
            </span>
          </div>
          <div class="tpl-row">
            <select v-model="newAssignee" class="tpl-input">
              <option value="">— добавить исполнителя —</option>
              <option v-for="u in availableUsers" :key="u" :value="u">{{ u }}</option>
            </select>
            <button class="btn" :disabled="!newAssignee" @click="addAssignee">Добавить</button>
          </div>
        </section>

        <!-- Чек-лист -->
        <section class="tpl-section">
          <h3 class="tpl-section-title">Чек-лист</h3>
          <p v-if="!current.checklist?.length" class="tpl-section-empty">Пусто</p>
          <ul v-else class="tpl-checklist">
            <li v-for="(item, i) in current.checklist" :key="i" class="tpl-checklist-item">
              <input v-model="item.title" type="text" class="tpl-input" @change="saveChecklist"/>
              <button class="tpl-row-del" @click="removeChecklistItem(i)" title="Удалить">×</button>
            </li>
          </ul>
          <div class="tpl-row">
            <input v-model="newChecklistItem" type="text" class="tpl-input" placeholder="Новый пункт..."
                   @keydown.enter.prevent="addChecklistItem"/>
            <button class="btn" :disabled="!newChecklistItem.trim()" @click="addChecklistItem">Добавить</button>
          </div>
        </section>

        <!-- Расписания -->
        <section class="tpl-section">
          <h3 class="tpl-section-title">
            Расписания
            <span class="tpl-section-hint">когда и куда плодить карточки</span>
          </h3>
          <ul v-if="current.schedules?.length" class="tpl-schedules">
            <li v-for="s in current.schedules" :key="s.id" class="tpl-schedule" :class="{ 'is-inactive': !s.is_active }">
              <div class="tpl-schedule-head">
                <div class="tpl-schedule-title">{{ scheduleHumanTitle(s) }}</div>
                <div class="tpl-schedule-actions">
                  <button class="tpl-mini-btn" @click="toggleScheduleActive(s)"
                          :title="s.is_active ? 'Выключить' : 'Включить'">
                    {{ s.is_active ? 'Включено' : 'Выключено' }}
                  </button>
                  <button class="tpl-mini-btn" @click="runScheduleNow(s.id)" :disabled="!s.is_active">
                    Создать сейчас
                  </button>
                  <button class="tpl-mini-btn danger" @click="deleteSchedule(s.id)">Удалить</button>
                </div>
              </div>

              <div class="tpl-schedule-grid">
                <label class="tpl-field-inline">
                  <span>Доска</span>
                  <select v-model.number="s.target_board_id" class="tpl-input"
                          @change="onScheduleBoardChange(s)">
                    <option v-for="b in availableBoards" :key="b.id" :value="b.id">{{ b.title }}</option>
                  </select>
                </label>
                <label class="tpl-field-inline">
                  <span>Колонка</span>
                  <select v-model.number="s.target_column_id" class="tpl-input"
                          @change="saveScheduleField(s, 'target_column_id')">
                    <option v-for="c in columnsOfBoard(s.target_board_id)" :key="c.id" :value="c.id">{{ c.title }}</option>
                  </select>
                </label>
              </div>

              <!-- Повтор -->
              <div class="tpl-recur">
                <div class="tpl-recur-row">
                  <span class="tpl-recur-label">Повторять каждые</span>
                  <input type="number" min="1" max="60" v-model.number="s.interval_n"
                         class="tpl-num" @change="saveRecurrence(s)"/>
                  <select v-model="s.recurrence_kind" class="tpl-input tpl-kind" @change="onKindChange(s)">
                    <option value="daily">дн.</option>
                    <option value="weekly">нед.</option>
                    <option value="monthly">мес.</option>
                  </select>
                </div>
                <div v-if="s.recurrence_kind === 'weekly'" class="tpl-weekdays">
                  <button v-for="d in weekdayDefs" :key="d.n" type="button"
                          class="tpl-wd" :class="{ on: s.weekdaysArr.includes(d.n) }"
                          @click="toggleWeekday(s, d.n)">{{ d.short }}</button>
                  <button type="button" class="tpl-mini-btn" @click="setWeekdaysPreset(s, 'workdays')">Будни</button>
                  <button type="button" class="tpl-mini-btn" @click="setWeekdaysPreset(s, 'all')">Все</button>
                </div>
                <div v-if="s.recurrence_kind === 'monthly'" class="tpl-recur-row">
                  <span class="tpl-recur-label">Число месяца</span>
                  <input type="number" min="1" max="31" v-model.number="s.day_of_month"
                         class="tpl-num" @change="saveRecurrence(s)"/>
                </div>
              </div>

              <!-- Окончание повтора -->
              <div class="tpl-recur-row">
                <span class="tpl-recur-label">Окончание</span>
                <select v-model="s.end_kind" class="tpl-input" @change="onEndKindChange(s)">
                  <option value="never">Бессрочно</option>
                  <option value="until">До даты</option>
                  <option value="count">Повторить N раз</option>
                </select>
                <input v-if="s.end_kind === 'until'" type="date" v-model="s.end_date"
                       class="tpl-input" @change="saveRecurrence(s)"/>
                <input v-if="s.end_kind === 'count'" type="number" min="1" max="999"
                       v-model.number="s.end_count" class="tpl-num" @change="saveRecurrence(s)"/>
              </div>

              <label class="tpl-field-inline tpl-field-due">
                <span>Срок задачи через (дней)</span>
                <input v-model.number="s.due_offset_days" type="number" min="0" max="60" class="tpl-num"
                       @change="saveScheduleField(s, 'due_offset_days')"/>
              </label>

              <div v-if="s.label_ids?.length || labelsOfBoard(s.target_board_id).length" class="tpl-schedule-labels">
                <span class="tpl-field-mini-label">Метки:</span>
                <span v-for="l in labelsOfBoard(s.target_board_id)" :key="l.id"
                      class="tpl-label-chip"
                      :class="{ 'is-on': s.label_ids?.includes(l.id) }"
                      :style="s.label_ids?.includes(l.id) ? { background: l.color } : {}"
                      @click="toggleLabel(s, l.id)">
                  {{ l.title }}
                </span>
              </div>

              <div v-if="s.deactivated_reason" class="tpl-schedule-warn">
                {{ s.deactivated_reason === 'completed' ? 'Повтор завершён' : 'Деактивировано: ' + reasonLabel(s.deactivated_reason) }}
              </div>

              <!-- Результат: следующий запуск + созданные задачи -->
              <div class="tpl-schedule-result">
                <span class="tpl-result-stat">
                  Следующая: <b>{{ s.is_active && s.next_run_date ? formatDate(s.next_run_date) : '—' }}</b>
                </span>
                <button class="tpl-mini-btn" @click="toggleScheduleCards(s)">
                  Создано задач: {{ s.created_count ?? 0 }}
                  <span class="tpl-chev">{{ expandedCards[s.id] ? '▴' : '▾' }}</span>
                </button>
              </div>
              <ul v-if="expandedCards[s.id]" class="tpl-created-list">
                <li v-if="cardsLoading[s.id]" class="tpl-muted">Загрузка…</li>
                <li v-else-if="!scheduleCards[s.id]?.length" class="tpl-muted">Пока ни одной задачи</li>
                <li v-for="c in scheduleCards[s.id]" :key="c.id"
                    class="tpl-created-item" @click="openGeneratedCard(c.id)">
                  <span class="tpl-created-title" :class="{ done: c.is_done }">{{ c.title }}</span>
                  <span class="tpl-created-meta">{{ c.column_title }} · {{ formatDate(c.created_at) }}</span>
                </li>
              </ul>

              <div class="tpl-schedule-preview">
                <span class="tpl-field-mini-label">Ближайшие запуски:</span>
                <span v-if="previewLoading[s.id]" class="tpl-muted">…</span>
                <span v-else-if="previews[s.id]?.length">
                  <span v-for="(d, i) in previews[s.id]" :key="i" class="tpl-date-chip">
                    {{ formatDate(d) }}
                  </span>
                </span>
                <button v-if="!previews[s.id]" class="tpl-mini-btn" @click="loadPreview(s.id)">Показать</button>
              </div>
            </li>
          </ul>
          <button class="btn" @click="addSchedule">
            <TaskIcon name="plus" :size="14"/>
            <span>Добавить расписание</span>
          </button>
        </section>

        <!-- Удаление шаблона -->
        <section class="tpl-section tpl-danger-zone">
          <button class="btn danger" @click="deleteTemplate">Удалить шаблон</button>
        </section>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { tasksApi } from '@/lib/tasksApi.js';
import { appConfirm, appAlert, appPrompt } from '@/lib/appDialogs.js';
import { useTasksStore } from '@/stores/tasksStore.js';
import TaskIcon from './TaskIcon.vue';

const props = defineProps({
  modelValue: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'open-card']);

const store = useTasksStore();

const mode = ref('list'); // 'list' | 'edit'
const loading = ref(false);
const templates = ref([]);
const current = ref(null); // полная карточка шаблона
const usersList = ref([]);
const boardsFull = ref([]); // доски с колонками/метками
const newAssignee = ref('');
const newChecklistItem = ref('');
const previews = ref({}); // schedule_id -> [dates]
const previewLoading = ref({});
const expandedCards = ref({}); // schedule_id -> bool (раскрыт список созданных задач)
const scheduleCards = ref({}); // schedule_id -> [cards]
const cardsLoading = ref({});  // schedule_id -> bool
const weekdayDefs = [
  { n: 1, short: 'Пн' }, { n: 2, short: 'Вт' }, { n: 3, short: 'Ср' },
  { n: 4, short: 'Чт' }, { n: 5, short: 'Пт' }, { n: 6, short: 'Сб' }, { n: 7, short: 'Вс' },
];

// Приводит расписание с бэка к виду, удобному для UI (числа, массив дней недели).
function normalizeSchedule(s) {
  s.interval_n = Math.max(1, Number(s.interval_n) || 1);
  s.recurrence_kind = s.recurrence_kind || 'daily';
  let arr = [];
  if (s.weekdays) arr = String(s.weekdays).split(',').map(n => parseInt(n, 10)).filter(n => n >= 1 && n <= 7);
  if (!arr.length && s.weekday) arr = [Number(s.weekday)];
  s.weekdaysArr = arr;
  s.day_of_month = Number(s.day_of_month) || 1;
  s.end_kind = s.end_kind || 'never';
  s.end_count = s.end_count != null ? Number(s.end_count) : null;
  s.due_offset_days = Number(s.due_offset_days) || 0;
  s.created_count = Number(s.created_count) || 0;
  return s;
}

watch(() => props.modelValue, async (val) => {
  if (val) {
    mode.value = 'list';
    await loadTemplates();
    if (!usersList.value.length) {
      try { usersList.value = unwrap(await tasksApi.listUsers(), 'users', 'items'); }
      catch (_) {}
    }
    if (!boardsFull.value.length) await loadBoardsFull();
  }
});

function close() { emit('update:modelValue', false); }
function goBack() { mode.value = 'list'; current.value = null; previews.value = {}; loadTemplates(); }

// Универсальная распаковка: бэк отдаёт {items: [...]} | {boards: [...]} | {users: [...]} | сам массив
function unwrap(r, ...keys) {
  if (Array.isArray(r)) return r;
  for (const k of keys) if (Array.isArray(r?.[k])) return r[k];
  return [];
}

async function loadTemplates() {
  loading.value = true;
  try { templates.value = unwrap(await tasksApi.listTemplates(), 'items', 'templates'); }
  catch (e) { alert('Не удалось загрузить шаблоны: ' + e.message); }
  finally { loading.value = false; }
}

async function loadBoardsFull() {
  try {
    const boards = unwrap(await tasksApi.listBoards(), 'boards', 'items');
    const ownBoards = boards.filter(b => !b.is_archived);
    const detailed = await Promise.all(ownBoards.map(async b => {
      try {
        const full = await tasksApi.loadBoard(b.id);
        return { id: b.id, title: b.title, columns: full.columns || [], labels: full.labels || [] };
      } catch (_) { return { id: b.id, title: b.title, columns: [], labels: [] }; }
    }));
    boardsFull.value = detailed;
  } catch (e) { console.warn('boards load failed', e); }
}

const availableUsers = computed(() => {
  const taken = new Set(current.value?.assignees || []);
  return (usersList.value || []).map(u => u.name || u).filter(n => !taken.has(n));
});
const availableBoards = computed(() => boardsFull.value);

function columnsOfBoard(boardId) {
  const b = boardsFull.value.find(x => x.id === boardId);
  return (b?.columns || []).filter(c => !c.is_archive_column);
}
function labelsOfBoard(boardId) {
  const b = boardsFull.value.find(x => x.id === boardId);
  return b?.labels || [];
}

async function onCreateClick() {
  const title = await appPrompt('Название шаблона:', '', { title: 'Новый шаблон', okText: 'Создать' });
  if (!title || !title.trim()) return;
  tasksApi.createTemplate({ title: title.trim() })
    .then(async (r) => {
      await loadTemplates();
      openEditor(r.id);
    })
    .catch(e => appAlert('Ошибка: ' + e.message, { type: 'error' }));
}

async function openEditor(id) {
  try {
    current.value = await tasksApi.loadTemplate(id);
    (current.value.schedules || []).forEach(normalizeSchedule);
    mode.value = 'edit';
    // Подгружаем превью для всех активных расписаний
    for (const s of current.value.schedules || []) {
      if (s.is_active) loadPreview(s.id);
    }
  } catch (e) { appAlert('Ошибка: ' + e.message, { type: 'error' }); }
}

function saveField(field) {
  if (!current.value) return;
  const payload = { [field]: current.value[field] };
  tasksApi.updateTemplate(current.value.id, payload).catch(e => alert('Ошибка сохранения: ' + e.message));
}

function addAssignee() {
  if (!newAssignee.value) return;
  current.value.assignees = [...(current.value.assignees || []), newAssignee.value];
  newAssignee.value = '';
  saveAssignees();
}
function removeAssignee(u) {
  current.value.assignees = (current.value.assignees || []).filter(x => x !== u);
  saveAssignees();
}
function saveAssignees() {
  tasksApi.setTemplateAssignees(current.value.id, current.value.assignees)
    .catch(e => appAlert('Ошибка: ' + e.message, { type: 'error' }));
}

function addChecklistItem() {
  const t = newChecklistItem.value.trim();
  if (!t) return;
  current.value.checklist = [...(current.value.checklist || []), { title: t, sort_order: (current.value.checklist?.length || 0) }];
  newChecklistItem.value = '';
  saveChecklist();
}
function removeChecklistItem(i) {
  current.value.checklist.splice(i, 1);
  saveChecklist();
}
function saveChecklist() {
  const items = (current.value.checklist || []).map((it, i) => ({ title: it.title, sort_order: i }));
  tasksApi.setTemplateChecklist(current.value.id, items)
    .catch(e => appAlert('Ошибка: ' + e.message, { type: 'error' }));
}

async function addSchedule() {
  if (!boardsFull.value.length) {
    await appAlert('Сначала создайте хотя бы одну доску для назначения расписаний', { type: 'warning' });
    return;
  }
  const b = boardsFull.value[0];
  const col = (b.columns || []).find(c => !c.is_archive_column);
  if (!col) {
    await appAlert('У доски «' + b.title + '» нет неархивных колонок', { type: 'warning' });
    return;
  }
  try {
    await tasksApi.createSchedule(current.value.id, {
      target_board_id: b.id,
      target_column_id: col.id,
      recurrence_kind: 'weekly',
      weekdays: [1],
      interval_n: 1,
      due_offset_days: 0,
      end_kind: 'never',
    });
    // Перезагружаем редактор
    await openEditor(current.value.id);
  } catch (e) { appAlert('Ошибка: ' + e.message, { type: 'error' }); }
}

// Сохранение простых полей расписания (колонка, срок задачи).
async function saveScheduleField(s, field) {
  const payload = { [field]: s[field] };
  try { await tasksApi.updateSchedule(s.id, payload); }
  catch (e) { appAlert('Ошибка: ' + e.message, { type: 'error' }); }
}

// Сохраняет весь блок повтора целиком (бэк пересчитывает следующую дату).
async function saveRecurrence(s) {
  try {
    await tasksApi.updateSchedule(s.id, {
      recurrence_kind: s.recurrence_kind,
      interval_n: Math.max(1, Number(s.interval_n) || 1),
      weekdays: s.weekdaysArr,
      day_of_month: Number(s.day_of_month) || 1,
      end_kind: s.end_kind,
      end_date: s.end_date || null,
      end_count: s.end_count || null,
    });
    // Перечитываем редактор — обновятся «Следующая дата» и заголовок расписания.
    await openEditor(current.value.id);
  } catch (e) { appAlert('Ошибка: ' + e.message, { type: 'error' }); }
}

function onKindChange(s) {
  if (s.recurrence_kind === 'weekly' && !s.weekdaysArr.length) s.weekdaysArr = [1];
  if (s.recurrence_kind === 'monthly' && !s.day_of_month) s.day_of_month = 1;
  saveRecurrence(s);
}

function onEndKindChange(s) {
  if (s.end_kind === 'count' && !s.end_count) s.end_count = 5;
  if (s.end_kind === 'until' && !s.end_date) {
    const d = new Date();
    d.setMonth(d.getMonth() + 3);
    s.end_date = d.toISOString().slice(0, 10);
  }
  saveRecurrence(s);
}

function toggleWeekday(s, n) {
  const i = s.weekdaysArr.indexOf(n);
  if (i >= 0) {
    if (s.weekdaysArr.length > 1) s.weekdaysArr.splice(i, 1); // минимум один день
  } else {
    s.weekdaysArr.push(n);
  }
  s.weekdaysArr.sort((a, b) => a - b);
  saveRecurrence(s);
}

function setWeekdaysPreset(s, preset) {
  s.weekdaysArr = preset === 'workdays' ? [1, 2, 3, 4, 5] : [1, 2, 3, 4, 5, 6, 7];
  saveRecurrence(s);
}

// Раскрывает/сворачивает список задач, созданных расписанием (ленивая загрузка).
async function toggleScheduleCards(s) {
  const open = !expandedCards.value[s.id];
  expandedCards.value = { ...expandedCards.value, [s.id]: open };
  if (open && !scheduleCards.value[s.id]) {
    cardsLoading.value = { ...cardsLoading.value, [s.id]: true };
    try {
      const r = await tasksApi.scheduleCards(s.id);
      scheduleCards.value = { ...scheduleCards.value, [s.id]: r.cards || [] };
    } catch (_) {
      scheduleCards.value = { ...scheduleCards.value, [s.id]: [] };
    } finally {
      cardsLoading.value = { ...cardsLoading.value, [s.id]: false };
    }
  }
}

function openGeneratedCard(id) {
  emit('open-card', id);
  close();
}

async function onScheduleBoardChange(s) {
  // При смене доски сбрасываем колонку на первую неархивную
  const cols = columnsOfBoard(s.target_board_id);
  if (cols.length) s.target_column_id = cols[0].id;
  // И метки очищаем (бэк это сделает; на фронте обнулим явно)
  s.label_ids = [];
  try {
    await tasksApi.updateSchedule(s.id, {
      target_board_id: s.target_board_id,
      target_column_id: s.target_column_id,
      label_ids: [],
    });
  } catch (e) { appAlert('Ошибка: ' + e.message, { type: 'error' }); }
}

async function toggleScheduleActive(s) {
  s.is_active = s.is_active ? 0 : 1;
  if (s.is_active) s.deactivated_reason = null;
  await tasksApi.updateSchedule(s.id, { is_active: !!s.is_active });
}

async function toggleLabel(s, labelId) {
  const set = new Set(s.label_ids || []);
  if (set.has(labelId)) set.delete(labelId); else set.add(labelId);
  s.label_ids = Array.from(set);
  try { await tasksApi.updateSchedule(s.id, { label_ids: s.label_ids }); }
  catch (e) { appAlert('Ошибка: ' + e.message, { type: 'error' }); }
}

async function deleteSchedule(id) {
  if (!(await appConfirm('Удалить это расписание? Шаблон останется.', { okText: 'Удалить', danger: true }))) return;
  try {
    await tasksApi.deleteSchedule(id);
    await openEditor(current.value.id);
  } catch (e) { appAlert('Ошибка: ' + e.message, { type: 'error' }); }
}

async function runScheduleNow(id) {
  try {
    await tasksApi.runScheduleNow(id);
    // Обновляем счётчик и список созданных задач.
    delete scheduleCards.value[id];
    await openEditor(current.value.id);
    if (expandedCards.value[id]) {
      const s = (current.value.schedules || []).find(x => x.id === id);
      if (s) { expandedCards.value[id] = false; toggleScheduleCards(s); }
    }
  } catch (e) { appAlert('Ошибка: ' + e.message, { type: 'error' }); }
}

async function loadPreview(id) {
  previewLoading.value[id] = true;
  try {
    const r = await tasksApi.previewSchedule(id);
    previews.value[id] = r.dates || [];
  } catch (_) { previews.value[id] = []; }
  finally { previewLoading.value[id] = false; }
}

async function deleteTemplate() {
  if (!(await appConfirm('Удалить шаблон и все его расписания? Уже созданные карточки останутся.', { okText: 'Удалить', danger: true }))) return;
  try {
    await tasksApi.deleteTemplate(current.value.id);
    goBack();
  } catch (e) { appAlert('Ошибка: ' + e.message, { type: 'error' }); }
}

function priorityLabel(p) {
  return ({ low: 'Низкий', medium: 'Обычный', high: 'Высокий', urgent: 'Срочный' })[p] || p;
}
function reasonLabel(r) {
  return ({ no_access: 'нет доступа к доске', board_archived: 'доска архивирована',
            manual: 'вручную', completed: 'повтор завершён' })[r] || r;
}
function scheduleHumanTitle(s) {
  const short = ['', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
  const n = Math.max(1, Number(s.interval_n) || 1);
  let base;
  if (s.recurrence_kind === 'daily') {
    base = n === 1 ? 'Каждый день' : `Каждые ${n} дн.`;
  } else if (s.recurrence_kind === 'weekly') {
    const days = (s.weekdaysArr || []).map(d => short[d]).filter(Boolean).join(', ');
    const every = n === 1 ? 'Каждую неделю' : `Каждые ${n} нед.`;
    base = days ? `${every}: ${days}` : every;
  } else if (s.recurrence_kind === 'monthly') {
    const every = n === 1 ? 'Каждый месяц' : `Каждые ${n} мес.`;
    base = `${every}, ${s.day_of_month || '?'}-го`;
  } else {
    base = s.recurrence_kind;
  }
  if (s.end_kind === 'until' && s.end_date) base += ` · до ${formatDate(s.end_date)}`;
  if (s.end_kind === 'count' && s.end_count) base += ` · ${s.end_count} раз`;
  return base;
}
function formatDate(d) {
  if (!d) return '';
  // Принимает и 'YYYY-MM-DD', и 'YYYY-MM-DD HH:MM:SS'.
  const [y, m, day] = String(d).slice(0, 10).split('-');
  return `${day}.${m}.${y.slice(2)}`;
}
</script>

<style scoped>
.tpl-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 10010;
  display: flex; align-items: center; justify-content: center; padding: 16px;
}
.tpl-modal {
  background: var(--tk-bg, #fff); border-radius: 16px;
  width: 100%; max-width: 720px; max-height: 90vh; overflow: hidden;
  display: flex; flex-direction: column;
  box-shadow: 0 12px 40px rgba(0,0,0,0.18);
}
.tpl-head {
  display: flex; align-items: center; gap: 8px;
  padding: 14px 18px; border-bottom: 1px solid var(--tk-n-200, #E8E2D4);
  background: var(--tk-bg, #fff);
}
.tpl-back, .tpl-close {
  width: 28px; height: 28px; border-radius: 50%;
  background: var(--tk-n-100, #F3F0E8); border: none; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  color: var(--tk-text-secondary, #534D40);
}
.tpl-back:hover, .tpl-close:hover { background: var(--tk-n-200, #E8E2D4); }
.tpl-title { flex: 1; margin: 0; font-size: 17px; font-weight: 700; color: var(--tk-text, #1A1814); }

.tpl-list-wrap, .tpl-edit {
  overflow-y: auto; padding: 16px 18px 24px;
}

.tpl-list-head {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  margin-bottom: 16px;
}
.tpl-hint { margin: 0; font-size: 13px; color: var(--tk-text-secondary, #534D40); }

.tpl-empty {
  text-align: center; padding: 40px 16px;
  color: var(--tk-text-secondary, #534D40);
}
.tpl-empty-icon {
  width: 48px; height: 48px; border-radius: 50%;
  background: var(--tk-n-100, #F3F0E8); display: inline-flex;
  align-items: center; justify-content: center; margin-bottom: 12px;
  color: var(--tk-accent, #E87A1E);
}
.tpl-empty-text { font-size: 15px; font-weight: 600; color: var(--tk-text, #1A1814); }
.tpl-empty-hint { font-size: 13px; margin-top: 4px; }

.tpl-cards { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; }
.tpl-card {
  display: flex; align-items: center; gap: 8px;
  padding: 12px 14px; border-radius: 12px;
  background: var(--tk-n-100, #F3F0E8); cursor: pointer;
  transition: background 0.15s;
}
.tpl-card:hover { background: var(--tk-n-200, #E8E2D4); }
.tpl-card-main { flex: 1; min-width: 0; }
.tpl-card-title { font-size: 14.5px; font-weight: 600; color: var(--tk-text, #1A1814); margin-bottom: 4px; }
.tpl-card-meta { display: flex; flex-wrap: wrap; gap: 6px; }
.tpl-chip {
  display: inline-block; padding: 2px 8px; border-radius: 999px;
  font-size: 11.5px; background: rgba(0,0,0,0.05);
  color: var(--tk-text-secondary, #534D40);
}
.tpl-chip.pri-urgent { background: #FEE2E2; color: #991B1B; }
.tpl-chip.pri-high   { background: #FEF3C7; color: #92400E; }
.tpl-chip.pri-medium { background: #DBEAFE; color: #1E40AF; }
.tpl-chip.pri-low    { background: #E5E7EB; color: #374151; }
.tpl-card-chev { color: var(--tk-text-secondary, #534D40); }

.tpl-section { margin-bottom: 22px; }
.tpl-section-title {
  font-size: 13px; font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.5px; color: var(--tk-text-secondary, #534D40);
  margin: 0 0 10px;
}
.tpl-section-hint {
  font-size: 11.5px; font-weight: 500; text-transform: none;
  letter-spacing: 0; margin-left: 8px; color: var(--tk-text-secondary, #8A8275);
}
.tpl-section-empty { font-size: 13px; color: var(--tk-text-secondary, #8A8275); margin: 0 0 8px; }

.tpl-field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 10px; }
.tpl-field-label { font-size: 12px; color: var(--tk-text-secondary, #534D40); }
.tpl-input {
  border: 1px solid var(--tk-n-300, #D4CDB8); border-radius: 8px;
  padding: 7px 10px; font-size: 13.5px; background: #fff;
  color: var(--tk-text, #1A1814); font-family: inherit;
  width: 100%;
}
.tpl-input:focus { outline: 2px solid var(--tk-accent, #E87A1E); outline-offset: -1px; }
.tpl-textarea { resize: vertical; min-height: 60px; }

.tpl-row { display: flex; gap: 8px; align-items: center; }
.tpl-row .tpl-input { flex: 1; }
.tpl-row-del {
  width: 28px; height: 28px; border-radius: 50%;
  background: var(--tk-n-100, #F3F0E8); border: none; cursor: pointer;
  color: var(--tk-text-secondary, #534D40); flex-shrink: 0;
}
.tpl-row-del:hover { background: #FEE2E2; color: #991B1B; }

.tpl-pills { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
.tpl-pill {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 4px 8px 4px 10px; border-radius: 999px;
  background: var(--tk-n-100, #F3F0E8); font-size: 13px;
  color: var(--tk-text, #1A1814);
}
.tpl-pill-del {
  background: none; border: none; cursor: pointer;
  width: 18px; height: 18px; border-radius: 50%;
  font-size: 14px; line-height: 1; padding: 0;
  color: var(--tk-text-secondary, #534D40);
}
.tpl-pill-del:hover { background: rgba(0,0,0,0.08); }

.tpl-checklist { list-style: none; padding: 0; margin: 0 0 10px; display: flex; flex-direction: column; gap: 6px; }
.tpl-checklist-item { display: flex; gap: 8px; align-items: center; }
.tpl-checklist-item .tpl-input { flex: 1; }

.tpl-schedules { list-style: none; padding: 0; margin: 0 0 12px; display: flex; flex-direction: column; gap: 12px; }
.tpl-schedule {
  border: 1px solid var(--tk-n-200, #E8E2D4); border-radius: 12px;
  padding: 12px; background: #fff;
}
.tpl-schedule.is-inactive { opacity: 0.65; }
.tpl-schedule-head {
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  margin-bottom: 10px;
}
.tpl-schedule-title { font-size: 14px; font-weight: 600; color: var(--tk-text, #1A1814); }
.tpl-schedule-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.tpl-mini-btn {
  padding: 4px 10px; border-radius: 999px;
  background: var(--tk-n-100, #F3F0E8); border: none; cursor: pointer;
  font-size: 12px; color: var(--tk-text, #1A1814);
}
.tpl-mini-btn:hover { background: var(--tk-n-200, #E8E2D4); }
.tpl-mini-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.tpl-mini-btn.danger { background: #FEE2E2; color: #991B1B; }
.tpl-mini-btn.danger:hover { background: #FCA5A5; }

.tpl-schedule-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 10px; margin-bottom: 10px;
}
.tpl-field-inline { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: var(--tk-text-secondary, #534D40); }
.tpl-field-inline .tpl-input { font-size: 13px; padding: 5px 8px; }

.tpl-schedule-labels { margin: 8px 0; display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
.tpl-field-mini-label { font-size: 11.5px; color: var(--tk-text-secondary, #534D40); margin-right: 4px; }
.tpl-label-chip {
  display: inline-block; padding: 3px 8px; border-radius: 999px;
  font-size: 12px; cursor: pointer; user-select: none;
  background: var(--tk-n-100, #F3F0E8); color: var(--tk-text, #1A1814);
}
.tpl-label-chip.is-on { color: #fff; }

.tpl-schedule-warn {
  font-size: 12px; padding: 6px 10px; border-radius: 8px;
  background: #FEF3C7; color: #92400E; margin-bottom: 8px;
}
.tpl-schedule-preview { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
.tpl-date-chip {
  display: inline-block; padding: 2px 8px; border-radius: 999px;
  font-size: 12px; background: #DBEAFE; color: #1E40AF;
}
.tpl-muted { font-size: 12px; color: var(--tk-text-secondary, #8A8275); }

.tpl-danger-zone { padding-top: 12px; border-top: 1px solid var(--tk-n-200, #E8E2D4); }
.btn.danger { background: #FEE2E2; color: #991B1B; border: none; }
.btn.danger:hover { background: #FCA5A5; }

/* ─── Блок повтора ─── */
.tpl-recur {
  display: flex; flex-direction: column; gap: 8px;
  margin: 8px 0;
  padding: 8px 10px;
  background: var(--tk-n-100, #F3F0E8); border-radius: 8px;
}
.tpl-recur-row {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
  font-size: 13px; color: var(--tk-text, #1A1814);
}
.tpl-recur-label { color: var(--tk-text-secondary, #534D40); font-size: 12px; }
.tpl-num {
  width: 58px; padding: 5px 8px; font-size: 13px;
  border: 1px solid var(--tk-n-200, #E8E2D4); border-radius: 6px;
  background: #fff; color: var(--tk-text, #1A1814);
}
.tpl-num:focus { outline: 2px solid var(--tk-accent, #E87A1E); outline-offset: -1px; }
.tpl-kind { width: auto; min-width: 70px; }

.tpl-weekdays { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
.tpl-wd {
  width: 34px; height: 30px; padding: 0;
  border: 1px solid var(--tk-n-200, #E8E2D4); border-radius: 6px;
  background: #fff; cursor: pointer;
  font-size: 12px; font-weight: 600; color: var(--tk-text-secondary, #534D40);
  transition: background 120ms, color 120ms, border-color 120ms;
}
.tpl-wd:hover { border-color: var(--tk-accent, #E87A1E); }
.tpl-wd.on {
  background: var(--tk-accent, #E87A1E); color: #fff;
  border-color: var(--tk-accent, #E87A1E);
}

.tpl-field-due { flex-direction: row; align-items: center; gap: 8px; margin: 4px 0; }

/* ─── Результат повтора ─── */
.tpl-schedule-result {
  display: flex; align-items: center; justify-content: space-between;
  gap: 8px; flex-wrap: wrap; margin-top: 8px;
}
.tpl-result-stat { font-size: 12px; color: var(--tk-text-secondary, #534D40); }
.tpl-chev { font-size: 10px; opacity: 0.7; }
.tpl-created-list {
  list-style: none; margin: 6px 0 0; padding: 0;
  display: flex; flex-direction: column; gap: 2px;
  max-height: 180px; overflow-y: auto;
}
.tpl-created-item {
  display: flex; align-items: baseline; justify-content: space-between; gap: 8px;
  padding: 5px 8px; border-radius: 6px; cursor: pointer;
  font-size: 12px;
}
.tpl-created-item:hover { background: var(--tk-n-100, #F3F0E8); }
.tpl-created-title { color: var(--tk-text, #1A1814); }
.tpl-created-title.done { text-decoration: line-through; color: var(--tk-text-secondary, #8A8275); }
.tpl-created-meta { color: var(--tk-text-secondary, #8A8275); white-space: nowrap; }
</style>
