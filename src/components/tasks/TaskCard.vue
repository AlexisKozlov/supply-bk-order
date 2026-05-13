<template>
  <div
    class="task-card"
    :class="[priorityClass, dueClass, { 'is-overdue': isOverdue, 'is-done': card.is_done, 'is-dragging': dragging, 'is-external': !!card.is_external }]"
    draggable="true"
    @dragstart="onDragStart"
    @dragend="onDragEnd"
    @click="onCardClick"
  >
    <!-- Маркер чужой карточки: видна на моей доске, потому что я в исполнителях -->
    <div v-if="card.is_external" class="task-card-external" :title="'Карточка с доски: ' + card.external_board_owner">
      <TaskIcon name="arrowUpRight" :size="11"/>
      <span>с доски: {{ card.external_board_owner }}</span>
    </div>

    <!-- Метки сверху (Trello-стиль: тонкие пиллы) -->
    <div v-if="cardLabels.length" class="task-card-labels">
      <span v-for="l in cardLabels" :key="l.id" class="label-pill" :style="{ '--lbl-color': l.color }"
            :title="l.title" @click.stop="openLabelsPicker">{{ l.title }}</span>
    </div>

    <!-- Меню «⋮» в правом верхнем углу -->
    <button v-if="canEditCard" class="card-menu-btn" @click.stop="cardMenuOpen = !cardMenuOpen" title="Меню">
      <TaskIcon name="more" :size="14"/>
    </button>
    <div v-if="cardMenuOpen" class="card-menu" v-click-outside-card="() => cardMenuOpen = false" @click.stop>
      <button class="card-menu-item" @click="startAddSubtask">
        <TaskIcon name="plus" :size="14"/> Создать подзадачу
      </button>
      <button class="card-menu-item" @click="openCardFromMenu">
        <TaskIcon name="arrowUpRight" :size="14"/> Открыть задачу
      </button>
      <div v-if="!card.is_external" class="card-menu-divider"></div>
      <button v-if="!card.is_external" class="card-menu-item" @click="duplicateCard">
        <TaskIcon name="copy" :size="14"/> Дублировать
      </button>
      <button class="card-menu-item submenu-trigger" @click.stop="moveSubmenuOpen = !moveSubmenuOpen">
        <TaskIcon name="arrowRight" :size="14"/> Переместить в колонку
        <TaskIcon name="chevronRight" :size="12" class="cm-arrow"/>
      </button>
      <div v-if="moveSubmenuOpen" class="card-submenu">
        <button v-for="col in store.columns" :key="col.id" class="card-menu-item"
                :class="{ disabled: col.id === card.column_id }"
                :disabled="col.id === card.column_id"
                @click="moveToColumn(col.id)">
          <span class="cm-col-dot" :style="{ background: col.color || '#9E9E9E' }"></span>
          {{ col.title }}
        </button>
      </div>
      <div class="card-menu-divider"></div>
      <button class="card-menu-item" @click="archiveOrRestoreFromMenu">
        <TaskIcon name="archive" :size="14"/>
        {{ isInArchiveColumn ? 'Восстановить из архива' : 'Архивировать' }}
      </button>
      <button v-if="!card.is_external" class="card-menu-item danger" @click="deleteCardFromMenu">
        <TaskIcon name="trash" :size="14"/> Удалить карточку
      </button>
    </div>

    <!-- Заголовок + чекбокс «Готово → в архив» -->
    <div class="task-card-title-row">
      <input v-if="canEditCard" type="checkbox" class="task-card-done-chk"
             :checked="!!card.is_done"
             @click.stop
             @change="completeAndArchive"
             title="Завершить и убрать в архив" />
      <div class="task-card-title">{{ card.title }}</div>
    </div>

    <!-- Прогресс чек-листа — тонкая заполняющаяся линия (Yougile-стиль) -->
    <div v-if="card.checklist?.total" class="task-card-progress"
         :title="'Чек-лист: ' + card.checklist.done + ' из ' + card.checklist.total"
         :class="{ done: card.checklist.done === card.checklist.total }">
      <div class="task-card-progress-fill" :style="{ width: checklistPct + '%' }"></div>
    </div>

    <!-- Прогресс подзадач — тоже заполняющаяся линия, если подзадач больше одной -->
    <div v-if="card.subtasks_total" class="task-card-progress task-card-progress-sub"
         :title="'Подзадачи: ' + (card.subtasks_done || 0) + ' из ' + (card.subtasks_total || 0)"
         :class="{ done: card.subtasks_done === card.subtasks_total }">
      <div class="task-card-progress-fill" :style="{ width: subtasksPct + '%' }"></div>
    </div>


    <!-- Метаданные снизу -->
    <div class="task-card-meta">
      <!-- Приоритет (всегда показываем, включая «Средний») -->
      <button class="meta-pill prio-pill" :class="'prio-' + (card.priority || 'medium')"
              @click.stop="togglePopover('priority')"
              :title="'Приоритет: ' + priorityLabel">
        <span class="meta-dot"></span>{{ priorityLabel }}
      </button>

      <!-- Срок -->
      <button v-if="card.due_date" class="meta-pill due-pill"
              :class="{ overdue: isOverdue, fire: dueClass === 'due-fire', warn: dueClass === 'due-warn' }"
              @click.stop="togglePopover('due')" :title="'Срок: ' + (card.due_date || '')">
        <TaskIcon name="calendar" :size="12"/>
        <span>{{ formatDue }}</span>
      </button>
      <button v-else-if="canEditCard" class="meta-pill meta-pill-ghost"
              @click.stop="togglePopover('due')" title="Установить срок">
        <TaskIcon name="calendar" :size="12"/>
        <span>Срок</span>
      </button>

      <!-- Чек-лист -->
      <span v-if="card.checklist?.total" class="meta-icon-stat"
            :class="{ done: card.checklist.done === card.checklist.total }"
            :title="'Чек-лист: ' + card.checklist.done + ' из ' + card.checklist.total">
        <TaskIcon name="check" :size="12"/>
        <span>{{ card.checklist.done }}/{{ card.checklist.total }}</span>
      </span>

      <!-- Чат -->
      <span v-if="card.comments" class="meta-icon-stat" @click.stop="openCardChat" :title="'Комментариев: ' + card.comments">
        <TaskIcon name="chat" :size="12"/>
        <span>{{ card.comments }}</span>
      </span>

      <!-- Вложения -->
      <span v-if="card.attachments" class="meta-icon-stat" :title="'Вложений: ' + card.attachments">
        <TaskIcon name="paperclip" :size="12"/>
        <span>{{ card.attachments }}</span>
      </span>

      <!-- Соисполнители (справа). Галочка на bubble — этот исполнитель закрыл свою часть. -->
      <span v-if="card.assignees?.length" class="meta-assignees">
        <span v-for="(n, i) in card.assignees.slice(0,3)" :key="i" class="assignee-bubble"
              :class="{ 'assignee-done': (card.assignees_done || []).includes(n) }"
              :title="n + ((card.assignees_done || []).includes(n) ? ' — выполнил' : '')">
          {{ initials(n) }}
          <span v-if="(card.assignees_done || []).includes(n)" class="assignee-done-tick">✓</span>
        </span>
        <span v-if="card.assignees.length > 3" class="assignee-more">+{{ card.assignees.length - 3 }}</span>
      </span>
    </div>

    <!-- Раскрывалка подзадач (только если есть подзадачи) -->
    <div v-if="hasSubtasks" class="subtasks-toggle" @click.stop="toggleSubtasks">
      <TaskIcon :name="subtasksOpen ? 'chevronDown' : 'chevronRight'" :size="12" class="subtasks-chevron"/>
      <span class="subtasks-label">Подзадачи</span>
      <span class="subtasks-counter">{{ card.subtasks_done || 0 }}/{{ card.subtasks_total || 0 }}</span>
    </div>

    <!-- Список подзадач (раскрытый) -->
    <div v-if="hasSubtasks && subtasksOpen" class="subtasks-list" @click.stop>
      <div v-for="st in card.subtasks || []" :key="st.id" class="subtask-mini"
           :class="{ done: st.is_done, overdue: isSubOverdue(st) }"
           @click.stop="$emit('open-subtask', { parent: card, subtask: st })">
        <input type="checkbox" :checked="!!st.is_done" @click.stop @change="toggleSubtaskDone(st)" class="round-chk" />
        <div class="subtask-mini-body">
          <div class="subtask-mini-title">{{ st.title }}</div>
          <div v-if="subtaskHasMeta(st)" class="subtask-mini-meta">
            <span v-if="st.priority && st.priority !== 'medium'" class="subtask-chip subtask-chip-prio" :class="'prio-bg-' + st.priority">
              {{ shortPrio(st.priority) }}
            </span>
            <span v-if="st.due_date" class="subtask-chip subtask-chip-due" :class="{ overdue: isSubOverdue(st) }">
              <TaskIcon name="calendar" :size="10"/> {{ formatSubDue(st.due_date) }}
            </span>
            <span v-for="l in subtaskLabels(st)" :key="'l-' + l.id" class="subtask-chip subtask-chip-label" :style="{ '--lbl-color': l.color }">
              {{ l.title }}
            </span>
          </div>
        </div>
        <button v-if="canEditCard" class="subtask-add-sticker"
                @click.stop="$emit('open-subtask', { parent: card, subtask: st })"
                title="Добавить стикер (приоритет, срок, метку)">
          <TaskIcon name="plus" :size="12"/>
        </button>
      </div>
    </div>

    <!-- Форма создания подзадачи (открывается через меню ⋮) -->
    <div v-if="addingSubtask" class="subtask-add-form" @click.stop>
      <input ref="newSubInput" v-model="newSubtaskTitle" type="text" placeholder="Название подзадачи"
             @keydown.enter="submitNewSubtask" @keydown.esc="cancelAddSubtask" />
      <div class="subtask-add-actions">
        <button class="btn primary ts-btn-sm" @click.stop="submitNewSubtask" :disabled="!newSubtaskTitle.trim()">Добавить</button>
        <button class="btn ts-btn-sm" @click.stop="cancelAddSubtask">Отмена</button>
      </div>
    </div>

    <!-- Поповер: приоритет -->
    <div v-if="popover === 'priority'" class="card-popover" @click.stop v-click-outside-card="closePopover">
      <div class="popover-title">Приоритет</div>
      <button v-for="p in priorities" :key="p.value" class="popover-item"
              :class="{ active: card.priority === p.value }"
              @click="setPriority(p.value)">
        <span class="prio-dot" :class="'prio-bg-' + p.value"></span>{{ p.label }}
      </button>
    </div>

    <!-- Поповер: срок -->
    <div v-if="popover === 'due'" class="card-popover" @click.stop v-click-outside-card="closePopover">
      <div class="popover-title">Срок</div>
      <input type="datetime-local" :value="dueLocal" @change="setDue" class="popover-input" />
      <div class="popover-actions">
        <button v-if="card.due_date" class="popover-clear" @click="setDue({ target: { value: '' } })">Убрать срок</button>
      </div>
    </div>

    <!-- Поповер: метки -->
    <div v-if="popover === 'labels'" class="card-popover labels-popover" @click.stop v-click-outside-card="closePopover">
      <div class="popover-title">Метки</div>
      <div class="popover-labels">
        <button v-for="l in labels" :key="l.id" class="popover-label"
                :class="{ active: (card.label_ids || []).includes(l.id) }"
                :style="{ background: (card.label_ids || []).includes(l.id) ? l.color : 'transparent', borderColor: l.color, color: (card.label_ids || []).includes(l.id) ? '#fff' : l.color }"
                @click="toggleLabel(l)">{{ l.title }}</button>
        <div v-if="!labels.length" class="popover-empty">Меток пока нет — добавьте в настройках доски</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import TaskIcon from './TaskIcon.vue';
import { tasksApi } from '@/lib/tasksApi.js';
import { useTasksStore } from '@/stores/tasksStore.js';
import { useTasksDialogs } from '@/composables/useTasksDialogs.js';
const dlg = useTasksDialogs();
const showError = (e) => dlg.info('Ошибка', e?.message || String(e), 'error');

const props = defineProps({
  card: { type: Object, required: true },
  labels: { type: Array, default: () => [] },
  canEditCard: { type: Boolean, default: true },
});
const emit = defineEmits(['open', 'open-chat', 'open-subtask', 'subtasks-changed', 'dragstart', 'dragend']);

const store = useTasksStore();
const dragging = ref(false);
const popover = ref(null); // 'priority' | 'due' | 'labels' | null
const subtasksOpen = ref(false);
const addingSubtask = ref(false);
const newSubtaskTitle = ref('');
const cardMenuOpen = ref(false);
const moveSubmenuOpen = ref(false);

const hasSubtasks = computed(() => (props.card.subtasks_total || 0) > 0);

const priorities = [
  { value: 'low',    label: 'Низкий' },
  { value: 'medium', label: 'Средний' },
  { value: 'high',   label: 'Высокий' },
  { value: 'urgent', label: 'Срочно' },
];

const labelsMap = computed(() => {
  const m = new Map();
  for (const l of props.labels) m.set(l.id, l);
  return m;
});
const cardLabels = computed(() => {
  return (props.card.label_ids || []).map(id => labelsMap.value.get(id)).filter(Boolean);
});

// Хелперы для подзадач — возвращают стикеры, аналогичные карточечным
function subtaskLabels(st) {
  return (st.label_ids || []).map(id => labelsMap.value.get(id)).filter(Boolean);
}
function shortPrio(p) {
  return ({ urgent: 'Срочно', high: 'Высокий', medium: 'Средний', low: 'Низкий' })[p] || '';
}
function subtaskHasMeta(st) {
  if (st.priority && st.priority !== 'medium') return true;
  if (st.due_date) return true;
  if ((st.label_ids || []).length) return true;
  return false;
}
const priorityClass = computed(() => 'prio-' + (props.card.priority || 'medium'));
const priorityLabel = computed(() => (priorities.find(p => p.value === props.card.priority)?.label) || 'Средний');
const checklistPct = computed(() => {
  const total = props.card.checklist?.total || 0;
  const done = props.card.checklist?.done || 0;
  if (!total) return 0;
  return Math.round(done / total * 100);
});
const subtasksPct = computed(() => {
  const total = props.card.subtasks_total || 0;
  const done = props.card.subtasks_done || 0;
  if (!total) return 0;
  return Math.round(done / total * 100);
});
const isInArchiveColumn = computed(() => {
  const col = store.columns.find(c => c.id === props.card.column_id);
  return !!col?.is_archive_column;
});
const isOverdue = computed(() => {
  if (!props.card.due_date || props.card.is_done) return false;
  return new Date(props.card.due_date) < new Date();
});
const dueClass = computed(() => {
  if (!props.card.due_date || props.card.is_done) return '';
  const d = new Date(props.card.due_date);
  const now = new Date();
  const hours = (d - now) / 3600000;
  if (hours < 0) return 'due-overdue';
  if (hours < 24) return 'due-fire';     // горит
  if (hours < 72) return 'due-warn';     // в ближайшие 3 дня
  return '';
});
const formatDue = computed(() => {
  if (!props.card.due_date) return '';
  const d = new Date(props.card.due_date);
  const today = new Date();
  const isToday = d.toDateString() === today.toDateString();
  const tomorrow = new Date(today); tomorrow.setDate(today.getDate() + 1);
  const isTomorrow = d.toDateString() === tomorrow.toDateString();
  if (isToday) return 'Сегодня';
  if (isTomorrow) return 'Завтра';
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
});
const dueLocal = computed(() => {
  if (!props.card.due_date) return '';
  const d = new Date(props.card.due_date);
  if (isNaN(d)) return '';
  const pad = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
});

function initials(n) {
  return (n || '').split(/\s+/).filter(Boolean).map(w => w[0]).join('').slice(0, 2).toUpperCase();
}

function onCardClick(e) {
  // Не открываем модалку, если кликали по бейджу/поповеру/подзадачам/меню/чекбоксу
  if (e.target.closest('.meta-pill') || e.target.closest('.meta-icon-stat') ||
      e.target.closest('.card-popover') || e.target.closest('.label-pill') ||
      e.target.closest('.subtasks-toggle') || e.target.closest('.subtasks-list') ||
      e.target.closest('.card-menu-btn') || e.target.closest('.card-menu') ||
      e.target.closest('.subtask-add-form') || e.target.closest('.task-card-done-chk')) return;
  emit('open', props.card);
}

function startAddSubtask() {
  cardMenuOpen.value = false;
  moveSubmenuOpen.value = false;
  addingSubtask.value = true;
  if (hasSubtasks.value) subtasksOpen.value = true;
  setTimeout(() => {
    const inp = document.querySelector('.task-card .subtask-add-form input');
    inp?.focus?.();
  }, 0);
}

function openCardFromMenu() {
  cardMenuOpen.value = false;
  emit('open', props.card);
}

async function duplicateCard() {
  cardMenuOpen.value = false;
  try {
    const r = await tasksApi.createCard({
      board_id: store.currentBoardId,
      column_id: props.card.column_id,
      title: props.card.title + ' (копия)',
      description: props.card.description,
      priority: props.card.priority,
      due_date: props.card.due_date,
    });
    // Копируем метки
    if ((props.card.label_ids || []).length) {
      await tasksApi.setCardLabels(r.id, props.card.label_ids);
    }
    await store.reload();
  } catch (e) { showError(e); }
}

async function moveToColumn(columnId) {
  cardMenuOpen.value = false;
  moveSubmenuOpen.value = false;
  if (columnId === props.card.column_id) return;
  try {
    await store.moveCard(props.card.id, columnId, 0);
  } catch (e) { showError(e); }
}

async function deleteCardFromMenu() {
  cardMenuOpen.value = false;
  const ok = await dlg.confirm('Удалить карточку', 'Удалить карточку «' + props.card.title + '»?',
    { okText: 'Удалить', danger: true });
  if (!ok) return;
  try {
    await tasksApi.deleteCard(props.card.id);
    await store.reload();
  } catch (e) { showError(e); }
}

async function completeAndArchive() {
  const archiveCol = store.columns.find(c => c.is_archive_column);
  if (!archiveCol) { dlg.info('Нет архива', 'У доски нет колонки «Архив». Обратитесь к администратору.', 'warning'); return; }
  const isInArchive = props.card.column_id === archiveCol.id;
  let targetCol = archiveCol;
  if (isInArchive) {
    targetCol = store.columns.find(c => !c.is_archive_column && !c.is_done_column) || store.columns[0];
  }
  try {
    await store.moveCard(props.card.id, targetCol.id, 0);
  } catch (e) {
    showError(e);
  }
}

async function archiveOrRestoreFromMenu() {
  cardMenuOpen.value = false;
  await completeAndArchive();
}

function toggleSubtasks() { subtasksOpen.value = !subtasksOpen.value; }

function isSubOverdue(st) {
  if (!st.due_date || st.is_done) return false;
  return new Date(st.due_date) < new Date();
}
function formatSubDue(s) {
  const d = new Date(s);
  if (isNaN(d)) return '';
  const today = new Date();
  if (d.toDateString() === today.toDateString()) return 'сегодня';
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
}

async function toggleSubtaskDone(st) {
  const newVal = st.is_done ? 0 : 1;
  try {
    await tasksApi.updateCard(st.id, { is_done: newVal });
    st.is_done = newVal;
    const subs = props.card.subtasks || [];
    props.card.subtasks_done = subs.filter(s => s.is_done).length;
    emit('subtasks-changed');
  } catch (e) { showError(e); }
}

async function submitNewSubtask() {
  const t = newSubtaskTitle.value.trim();
  if (!t) return;
  try {
    const r = await tasksApi.createCard({ parent_card_id: props.card.id, title: t });
    if (!props.card.subtasks) props.card.subtasks = [];
    props.card.subtasks.push({ id: r.id, title: t, is_done: 0, priority: 'medium', due_date: null, sort_order: 999 });
    props.card.subtasks_total = (props.card.subtasks_total || 0) + 1;
    newSubtaskTitle.value = '';
    addingSubtask.value = false;
    emit('subtasks-changed');
  } catch (e) { showError(e); }
}
function cancelAddSubtask() { newSubtaskTitle.value = ''; addingSubtask.value = false; }

function onDragStart(e) {
  closePopover();
  dragging.value = true;
  e.dataTransfer.effectAllowed = 'move';
  e.dataTransfer.setData('text/plain', String(props.card.id));
  emit('dragstart', props.card);
}
function onDragEnd() {
  dragging.value = false;
  emit('dragend');
}

function togglePopover(name) {
  if (!props.canEditCard && name !== 'labels') { /* всё равно даём смотреть */ }
  popover.value = popover.value === name ? null : name;
}
function closePopover() { popover.value = null; }
function openLabelsPicker() { togglePopover('labels'); }
function openCardChat() { emit('open-chat', props.card); }

async function setPriority(p) {
  closePopover();
  if (p === props.card.priority) return;
  try {
    await tasksApi.updateCard(props.card.id, { priority: p });
    props.card.priority = p;
  } catch (e) { showError(e); }
}

async function setDue(e) {
  const v = e.target?.value || '';
  closePopover();
  const due = v ? v.replace('T', ' ') + ':00' : null;
  try {
    await tasksApi.updateCard(props.card.id, { due_date: due });
    props.card.due_date = due;
  } catch (er) { showError(er); }
}

async function toggleLabel(l) {
  const current = props.card.label_ids || [];
  const has = current.includes(l.id);
  const newIds = has ? current.filter(id => id !== l.id) : [...current, l.id];
  try {
    await tasksApi.setCardLabels(props.card.id, newIds);
    props.card.label_ids = newIds;
  } catch (e) { showError(e); }
}

// Локальная директива «click-outside» для поповеров
const vClickOutsideCard = {
  mounted(el, binding) {
    el.__co = (e) => {
      if (!el.contains(e.target)) binding.value(e);
    };
    setTimeout(() => document.addEventListener('mousedown', el.__co), 0);
  },
  unmounted(el) {
    document.removeEventListener('mousedown', el.__co);
  },
};
</script>

<style scoped>
.task-card {
  background: var(--tk-bg-card, #fff);
  border: 1px solid var(--tk-border-soft, #EFEAE0);
  border-radius: var(--tk-r-card, 12px);
  padding: 14px 14px 12px;
  box-shadow: var(--tk-shadow-card);
  cursor: pointer;
  user-select: none;
  position: relative;
  display: flex; flex-direction: column; gap: 10px;
  transition: box-shadow var(--tk-transition, 140ms ease), border-color var(--tk-transition, 140ms ease);
}
.task-card:hover {
  box-shadow: var(--tk-shadow-card-hover);
  border-color: var(--tk-border, #E6E1D7);
}
.task-card.is-dragging { opacity: 0.45; transform: rotate(1deg); }
.task-card.is-done { opacity: 0.6; }
.task-card.is-done .task-card-title { text-decoration: line-through; color: var(--tk-text-muted); }
.task-card.is-external { background: linear-gradient(180deg, #FAF5EE 0%, #FFFFFF 60%); border-color: #E8D9BD; }

.task-card-external {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 11px; color: #8A6F3D; font-weight: 600;
  background: #F4E9CF; border-radius: 4px;
  padding: 2px 6px; margin-bottom: 6px;
}
.task-card-external svg { color: #B58A2E; }

/* Приоритет — без полоски слева. Подсветка идёт через цветной meta-pill
   в футере карточки. Срочно/высокий дополнительно тонируют border-color. */
.task-card.prio-urgent { border-color: color-mix(in srgb, var(--tk-danger) 35%, var(--tk-border-soft) 65%); }
.task-card.prio-high   { border-color: color-mix(in srgb, var(--tk-warning) 30%, var(--tk-border-soft) 70%); }

/* ═══ Метки сверху ═══ */
.task-card-labels {
  display: flex; gap: 6px;
  flex-wrap: wrap;
  margin: 0;
}
.label-pill {
  /* В Yougile метки — пастельная подложка + тёмный шрифт того же оттенка.
     Цвет приходит через --lbl-color, подложку и текст выводим color-mix-ом. */
  --lbl-color: var(--tk-n-400);
  font-size: 11px; font-weight: var(--tk-fw-semibold, 600);
  color: color-mix(in srgb, var(--lbl-color) 65%, #0E1320 35%);
  background: color-mix(in srgb, var(--lbl-color) 20%, #ffffff 80%);
  padding: 3px 10px;
  border-radius: var(--tk-r-pill, 999px);
  max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  cursor: pointer;
  letter-spacing: .1px;
  text-shadow: none;
  border: 1px solid transparent;
  transition: opacity var(--tk-transition, 140ms ease);
}
.label-pill:hover { opacity: 0.85; }

/* ═══ Заголовок ═══ */
.task-card-title-row {
  display: flex; align-items: flex-start; gap: 10px;
  margin: 0;
  padding-right: 24px;
}
.task-card-title {
  flex: 1;
  font-size: 13.5px;
  font-weight: var(--tk-fw-semibold, 600);
  color: var(--tk-text, #1A1814);
  line-height: 1.4;
  letter-spacing: -0.005em;
  word-break: break-word;
  min-width: 0;
}
.task-card.is-done .task-card-title { /* перебивает .is-done из ранее */ }

/* Прогресс-бар — тонкая заполняющаяся линия (Yougile-стиль) */
.task-card-progress {
  height: 5px;
  background: var(--tk-n-100, #F3F0E8);
  border-radius: 999px;
  overflow: hidden;
  margin: 0;
  position: relative;
}
.task-card-progress-sub { margin-top: 2px; }
.task-card-progress-fill {
  height: 100%;
  background: linear-gradient(90deg, var(--tk-accent, #E87A1E), #F4A261);
  border-radius: inherit;
  transition: width 280ms cubic-bezier(0.16, 1, 0.3, 1), background 200ms ease;
  box-shadow: 0 0 0 1px rgba(232,122,30,0.12);
}
.task-card-progress.done .task-card-progress-fill {
  background: linear-gradient(90deg, var(--tk-success, #16A364), #4BCE97);
  box-shadow: 0 0 0 1px rgba(22,163,100,0.15);
}
.task-card-progress-sub .task-card-progress-fill {
  background: linear-gradient(90deg, #635BFF, #7B82FF);
  box-shadow: 0 0 0 1px rgba(99,91,255,0.15);
}
.task-card-progress-sub.done .task-card-progress-fill {
  background: linear-gradient(90deg, var(--tk-success, #16A364), #4BCE97);
}

/* Круглый чекбокс «Готово + в архив» */
.task-card-done-chk {
  appearance: none; -webkit-appearance: none;
  width: 18px; height: 18px;
  border: 2px solid var(--tk-n-300, #B3B9C4);
  border-radius: 50%;
  background: var(--tk-n-0, #fff);
  cursor: pointer;
  flex-shrink: 0;
  margin: 1px 0 0;
  display: inline-flex; align-items: center; justify-content: center;
  position: relative;
  transition: all var(--tk-transition, 120ms ease);
}
.task-card-done-chk:hover {
  border-color: var(--tk-success, #1F8F4E);
  box-shadow: 0 0 0 4px var(--tk-success-soft, rgba(31,143,78,0.10));
}
.task-card-done-chk:checked {
  background: var(--tk-success, #1F8F4E);
  border-color: var(--tk-success, #1F8F4E);
}
.task-card-done-chk:checked::after {
  content: ''; display: block;
  width: 8px; height: 4px;
  border-left: 2px solid #fff; border-bottom: 2px solid #fff;
  transform: rotate(-45deg) translate(1px, -1px);
}

/* ═══ Меню «⋮» ═══ */
.card-menu-btn {
  position: absolute;
  top: var(--tk-s-1, 4px); right: var(--tk-s-1, 4px);
  background: var(--tk-n-0, #fff);
  border: none; cursor: pointer;
  color: var(--tk-text-muted, #758195);
  width: 24px; height: 24px; padding: 0;
  border-radius: var(--tk-r-sm, 4px);
  display: flex; align-items: center; justify-content: center;
  opacity: 0;
  transition: opacity var(--tk-transition, 120ms ease), background var(--tk-transition, 120ms ease), color var(--tk-transition, 120ms ease);
  z-index: 5;
}
.task-card:hover .card-menu-btn { opacity: 1; }
.card-menu-btn:hover {
  background: var(--tk-n-100, #F1F2F4);
  color: var(--tk-text, #172B4D);
}

.card-menu {
  position: absolute; top: 32px; right: var(--tk-s-1, 4px); z-index: 30;
  background: var(--tk-bg-popover, #fff);
  border: 1px solid var(--tk-border, #DCDFE4);
  border-radius: var(--tk-r-md, 8px);
  box-shadow: var(--tk-shadow-popover, 0 8px 24px rgba(9,30,66,0.18));
  min-width: 220px; padding: var(--tk-s-1, 4px);
}
.card-menu-item {
  display: flex; align-items: center; gap: var(--tk-s-2, 8px);
  width: 100%; text-align: left;
  padding: var(--tk-s-2, 8px) var(--tk-s-3, 12px);
  border: none; background: none; cursor: pointer;
  font-size: var(--tk-fz-md, 13px);
  color: var(--tk-text, #172B4D);
  border-radius: var(--tk-r-sm, 4px);
  font-family: inherit;
  position: relative;
  transition: background var(--tk-transition, 120ms ease);
}
.card-menu-item:hover { background: var(--tk-n-100, #F1F2F4); }
.card-menu-item.danger { color: var(--tk-danger, #C9372C); }
.card-menu-item.danger:hover { background: var(--tk-danger-soft, rgba(201,55,44,0.08)); }
.card-menu-item.disabled { opacity: 0.4; cursor: default; }
.card-menu-item.disabled:hover { background: none; }
.card-menu-divider {
  height: 1px; background: var(--tk-border-soft, #E1E4E8);
  margin: var(--tk-s-1, 4px) 0;
}
.cm-arrow { margin-left: auto; color: var(--tk-text-muted, #758195); }
.cm-col-dot {
  display: inline-block; width: 10px; height: 10px;
  border-radius: 50%; flex-shrink: 0;
}
.card-submenu {
  margin-left: var(--tk-s-3, 12px);
  margin-top: 2px;
  background: var(--tk-n-50, #F7F8F9);
  border-radius: var(--tk-r-sm, 4px);
  padding: 2px;
}
.card-submenu .card-menu-item {
  font-size: var(--tk-fz-sm, 12px);
  padding: 5px var(--tk-s-2, 8px);
}

/* ═══ Метаданные карточки ═══ */
.task-card-meta {
  display: flex; gap: 6px;
  flex-wrap: wrap; align-items: center;
  margin-top: 2px;
}

/* Универсальная плашка метаданных */
.meta-pill {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 11px;
  font-weight: var(--tk-fw-semibold, 600);
  padding: 2px 8px;
  border-radius: var(--tk-r-pill, 999px);
  border: none; cursor: pointer;
  background: var(--tk-n-100, #F3F0E8);
  color: var(--tk-text-secondary, #3D382E);
  font-family: inherit;
  letter-spacing: 0;
  transition: background var(--tk-transition, 140ms ease);
}
.meta-pill:hover { background: var(--tk-n-200, #E6E1D7); }
.meta-pill-ghost {
  background: transparent;
  color: var(--tk-text-muted, #758195);
  border: 1px dashed var(--tk-border, #DCDFE4);
  padding: 1px 7px;
  opacity: 0;
  transition: opacity var(--tk-transition, 120ms ease), border-color var(--tk-transition, 120ms ease), color var(--tk-transition, 120ms ease);
}
.task-card:hover .meta-pill-ghost { opacity: 0.95; }
.meta-pill-ghost:hover { border-color: var(--tk-accent, #E87A1E); color: var(--tk-accent-text, #B85A0E); }

.meta-dot {
  width: 8px; height: 8px; border-radius: 50%;
  display: inline-block;
  background: var(--tk-n-400);
}
.meta-dot-medium { background: var(--tk-prio-medium-fg, #0747A6); }

/* Приоритеты */
.prio-pill.prio-low    { background: var(--tk-prio-low-bg);    color: var(--tk-prio-low-fg); }
.prio-pill.prio-low .meta-dot    { background: var(--tk-prio-low-fg); }
.prio-pill.prio-medium { background: var(--tk-prio-medium-bg); color: var(--tk-prio-medium-fg); }
.prio-pill.prio-medium .meta-dot { background: var(--tk-prio-medium-fg); }
.prio-pill.prio-high   { background: var(--tk-prio-high-bg);   color: var(--tk-prio-high-fg); }
.prio-pill.prio-high .meta-dot   { background: var(--tk-prio-high-fg); }
.prio-pill.prio-urgent { background: var(--tk-prio-urgent-bg); color: var(--tk-prio-urgent-fg); }
.prio-pill.prio-urgent .meta-dot { background: var(--tk-prio-urgent-fg); }

/* Срок */
.due-pill { background: var(--tk-success-soft, rgba(31,143,78,0.10)); color: var(--tk-success, #1F8F4E); }
.due-pill.warn { background: var(--tk-prio-high-bg); color: var(--tk-prio-high-fg); }
.due-pill.fire { background: var(--tk-warning-soft, rgba(182,94,3,0.10)); color: var(--tk-warning, #B65E03); }
.due-pill.overdue {
  background: var(--tk-prio-urgent-bg);
  color: var(--tk-prio-urgent-fg);
  font-weight: var(--tk-fw-bold, 700);
}

/* Иконо-статистика без фона (чек-лист, чат) */
.meta-icon-stat {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: var(--tk-fz-xs, 11px);
  font-weight: var(--tk-fw-semibold, 600);
  padding: 2px var(--tk-s-1, 4px);
  color: var(--tk-text-muted, #758195);
  cursor: default;
  border-radius: var(--tk-r-sm, 4px);
}
.meta-icon-stat.done { color: var(--tk-success, #1F8F4E); }
.meta-icon-stat[role="button"], .meta-icon-stat:not(.done):hover { cursor: default; }

/* Срок горит / просрочено — мягкое тонирование рамки без второго ободка.
   Информативную нагрузку несёт также красная/жёлтая капсула срока внизу. */
.task-card.due-fire {
  border-color: color-mix(in srgb, var(--tk-warning, #BB6A0A) 35%, var(--tk-border, #E6E1D7) 65%);
}
.task-card.due-overdue,
.task-card.is-overdue {
  border-color: color-mix(in srgb, var(--tk-danger, #D33A2C) 40%, var(--tk-border, #E6E1D7) 60%);
}

/* ═══ Соисполнители ═══ */
.meta-assignees {
  margin-left: auto;
  display: inline-flex; align-items: center; gap: 0;
}
.assignee-bubble {
  display: inline-flex; align-items: center; justify-content: center;
  width: 22px; height: 22px; border-radius: 50%;
  background: linear-gradient(135deg, var(--tk-accent, #E87A1E), #F4A261);
  color: #fff; font-size: 9px; font-weight: var(--tk-fw-bold, 700);
  border: 2px solid var(--tk-bg-card, #fff);
  margin-left: -6px;
}
.assignee-bubble:first-child { margin-left: 0; }
.assignee-bubble.assignee-done {
  background: linear-gradient(135deg, #1F845A, #4BCE97);
  position: relative;
}
.assignee-done-tick {
  position: absolute;
  right: -4px; bottom: -4px;
  width: 12px; height: 12px;
  background: #1F845A;
  border: 1.5px solid #fff;
  border-radius: 50%;
  font-size: 8px; line-height: 9px;
  color: #fff; font-weight: 900;
  display: flex; align-items: center; justify-content: center;
}
.assignee-more {
  font-size: var(--tk-fz-xs, 11px);
  color: var(--tk-text-muted, #758195);
  margin-left: 4px;
  font-weight: var(--tk-fw-semibold, 600);
}

/* ═══ Поповер ═══ */
.card-popover {
  position: absolute; top: 100%; left: 0; right: 0;
  margin-top: var(--tk-s-1, 4px); z-index: 50;
  background: var(--tk-bg-popover, #fff);
  border: 1px solid var(--tk-border, #DCDFE4);
  border-radius: var(--tk-r-md, 8px);
  box-shadow: var(--tk-shadow-popover, 0 8px 24px rgba(9,30,66,0.18));
  padding: var(--tk-s-2, 8px);
  cursor: default;
}
.popover-title {
  font-size: var(--tk-fz-xs, 11px); font-weight: var(--tk-fw-bold, 700);
  color: var(--tk-text-muted, #758195);
  text-transform: uppercase; letter-spacing: .5px;
  margin-bottom: var(--tk-s-1, 4px);
  padding: 0 var(--tk-s-2, 8px);
}
.popover-item {
  display: flex; align-items: center; gap: var(--tk-s-2, 8px);
  width: 100%;
  padding: var(--tk-s-2, 8px) var(--tk-s-2, 8px);
  border-radius: var(--tk-r-sm, 4px);
  background: none; border: none; cursor: pointer; text-align: left;
  font-family: inherit;
  font-size: var(--tk-fz-md, 13px);
  color: var(--tk-text, #172B4D);
  transition: background var(--tk-transition, 120ms ease);
}
.popover-item:hover { background: var(--tk-n-100, #F1F2F4); }
.popover-item.active { background: var(--tk-accent-soft, rgba(232,122,30,0.10)); font-weight: var(--tk-fw-semibold, 600); color: var(--tk-accent-text, #B85A0E); }
.popover-item .prio-dot { flex-shrink: 0; width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
.popover-item .prio-dot.prio-bg-low    { background: var(--tk-prio-low-fg); }
.popover-item .prio-dot.prio-bg-medium { background: var(--tk-prio-medium-fg); }
.popover-item .prio-dot.prio-bg-high   { background: var(--tk-prio-high-fg); }
.popover-item .prio-dot.prio-bg-urgent { background: var(--tk-prio-urgent-fg); }

.popover-input {
  width: 100%; box-sizing: border-box;
  padding: 6px var(--tk-s-2, 8px);
  font-size: var(--tk-fz-md, 13px);
  border: 1px solid var(--tk-border, #DCDFE4);
  border-radius: var(--tk-r-sm, 4px);
  background: var(--tk-n-0, #fff);
  color: var(--tk-text, #172B4D);
  font-family: inherit;
  transition: border-color var(--tk-transition, 120ms ease), box-shadow var(--tk-transition, 120ms ease);
}
.popover-input:focus { outline: none; border-color: var(--tk-accent, #E87A1E); box-shadow: var(--tk-focus-ring, 0 0 0 2px rgba(232,122,30,0.35)); }
.popover-actions { margin-top: var(--tk-s-2, 8px); display: flex; justify-content: flex-end; }
.popover-clear {
  background: none; border: none; cursor: pointer;
  color: var(--tk-danger, #C9372C);
  font-size: var(--tk-fz-sm, 12px);
  font-weight: var(--tk-fw-semibold, 600);
  padding: var(--tk-s-1, 4px) var(--tk-s-2, 8px);
  border-radius: var(--tk-r-sm, 4px);
  transition: background var(--tk-transition, 120ms ease);
}
.popover-clear:hover { background: var(--tk-danger-soft, rgba(201,55,44,0.08)); }

.labels-popover .popover-labels {
  display: flex; flex-wrap: wrap; gap: var(--tk-s-1, 4px);
  max-height: 180px; overflow-y: auto;
  padding: 0 var(--tk-s-1, 4px);
}
.popover-label {
  padding: 3px var(--tk-s-2, 8px);
  border-radius: var(--tk-r-sm, 4px);
  font-size: var(--tk-fz-xs, 11px);
  font-weight: var(--tk-fw-semibold, 600);
  cursor: pointer;
  border: 1.5px solid;
  background: transparent;
  font-family: inherit;
}
.popover-empty {
  font-size: var(--tk-fz-sm, 12px);
  color: var(--tk-text-muted, #758195);
  font-style: italic;
  padding: var(--tk-s-2, 8px) var(--tk-s-1, 4px);
}

/* ═══ Раскрывалка подзадач — заголовок секции (корень дерева) ═══ */
.subtasks-toggle {
  display: flex; align-items: center; gap: 6px;
  margin: 4px 0 0;
  padding: 6px 10px;
  background: transparent;
  border-radius: 8px;
  cursor: pointer;
  font-size: 11.5px;
  color: var(--tk-text-secondary, #534D40);
  font-weight: var(--tk-fw-semibold, 600);
  user-select: none;
  transition: background var(--tk-transition, 140ms ease), color var(--tk-transition, 140ms ease);
}
.subtasks-toggle:hover { background: var(--tk-n-100, #F3F0E8); color: var(--tk-text, #1A1814); }
.subtasks-chevron { color: var(--tk-text-muted); }
.subtasks-counter {
  margin-left: auto;
  font-weight: var(--tk-fw-semibold, 600);
  background: var(--tk-n-100, #F3F0E8);
  color: var(--tk-text-secondary, #534D40);
  padding: 1px 8px;
  border-radius: 999px;
  font-size: 10.5px;
  font-feature-settings: 'tnum';
}

/* Список подзадач — каждая полноценная мини-карточка как в Yougile.
   Дерево: непрерывная вертикаль идёт от заголовка «Подзадачи»
   (через top: -22px) до центра последней подзадачи. */
.subtasks-list {
  margin-top: 4px;
  display: flex; flex-direction: column; gap: 6px;
  padding: 0 0 0 22px;
  position: relative;
}
.subtasks-list::before {
  content: '';
  position: absolute;
  left: 10px;
  top: -22px;   /* линия начинается из строки «Подзадачи» */
  bottom: 20px; /* и заканчивается у центра последней подзадачи */
  width: 1.5px;
  background: var(--tk-border, #E6E1D7);
  border-radius: 1px;
  pointer-events: none;
}
.subtask-mini { position: relative; }
/* Горизонтальный отвод к каждой подзадаче */
.subtask-mini::before {
  content: '';
  position: absolute;
  left: -12px;
  top: 20px;
  width: 12px;
  height: 1.5px;
  background: var(--tk-border, #E6E1D7);
  border-radius: 1px;
}
.subtask-mini {
  display: flex; align-items: flex-start; gap: 10px;
  background: var(--tk-bg-card, #fff);
  border: 1px solid var(--tk-border-soft, #EFEAE0);
  border-radius: 10px;
  padding: 9px 10px 9px 12px;
  min-height: 38px;
  font-size: 12.5px;
  cursor: pointer;
  transition: border-color var(--tk-transition, 140ms ease), box-shadow var(--tk-transition, 140ms ease);
}
.subtask-mini:hover {
  border-color: var(--tk-border, #E6E1D7);
  box-shadow: 0 2px 6px rgba(15,23,42,0.06);
}
.subtask-mini.done { opacity: 0.6; }
.subtask-mini.done .subtask-mini-title { text-decoration: line-through; color: var(--tk-text-muted); }
.subtask-mini.overdue { border-color: color-mix(in srgb, var(--tk-danger, #D33A2C) 35%, var(--tk-border, #E6E1D7) 65%); }

.round-chk { margin-top: 1px; }
.subtask-mini-body {
  flex: 1; min-width: 0;
  display: flex; flex-direction: column; gap: 4px;
}

/* ═══ Круглый чекбокс подзадачи (как кружок-радио в Yougile) ═══ */
.round-chk {
  appearance: none; -webkit-appearance: none;
  width: 20px; height: 20px;
  border: 1.5px solid var(--tk-n-300, #C8C1B2);
  border-radius: 50%;
  background: var(--tk-n-0, #fff);
  cursor: pointer;
  flex-shrink: 0; margin: 0;
  display: inline-flex; align-items: center; justify-content: center;
  transition: all var(--tk-transition, 140ms ease);
  position: relative;
}
.round-chk:hover { border-color: var(--tk-success, #16A364); }
.round-chk:checked {
  background: var(--tk-success, #16A364);
  border-color: var(--tk-success, #16A364);
}
.round-chk:checked::after {
  content: ''; display: block;
  width: 9px; height: 5px;
  border-left: 2px solid #fff; border-bottom: 2px solid #fff;
  transform: rotate(-45deg) translate(1px, -1px);
}

.subtask-mini-title {
  color: var(--tk-text, #1A1814);
  font-weight: var(--tk-fw-medium, 500);
  font-size: 12.5px;
  line-height: 1.35;
  word-break: break-word;
}

/* Стикеры подзадачи: те же типы что у карточки — приоритет, срок, метка */
.subtask-mini-meta {
  display: flex; gap: 5px; flex-wrap: wrap; align-items: center;
}
.subtask-chip {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 10.5px;
  font-weight: var(--tk-fw-semibold, 600);
  padding: 2px 8px;
  border-radius: 999px;
  letter-spacing: 0;
  white-space: nowrap;
  max-width: 160px; overflow: hidden; text-overflow: ellipsis;
}
.subtask-chip-prio.prio-bg-low    { background: var(--tk-prio-low-bg);    color: var(--tk-prio-low-fg); }
.subtask-chip-prio.prio-bg-high   { background: var(--tk-prio-high-bg);   color: var(--tk-prio-high-fg); }
.subtask-chip-prio.prio-bg-urgent { background: var(--tk-prio-urgent-bg); color: var(--tk-prio-urgent-fg); }
.subtask-chip-due { background: var(--tk-success-soft); color: var(--tk-success); }
.subtask-chip-due.overdue { background: var(--tk-danger-soft); color: var(--tk-danger); }
.subtask-chip-label {
  --lbl-color: var(--tk-n-400);
  color: color-mix(in srgb, var(--lbl-color) 65%, #0E1320 35%);
  background: color-mix(in srgb, var(--lbl-color) 20%, #ffffff 80%);
}

/* Кнопка «+ стикер» — появляется на ховере, открывает редактирование подзадачи */
.subtask-add-sticker {
  flex-shrink: 0;
  display: inline-flex; align-items: center; justify-content: center;
  width: 22px; height: 22px;
  border: 1px dashed var(--tk-n-300, #C8C1B2);
  border-radius: 999px;
  background: transparent;
  color: var(--tk-text-muted, #6E6657);
  cursor: pointer;
  opacity: 0;
  transition: opacity var(--tk-transition, 140ms ease), border-color var(--tk-transition, 140ms ease), color var(--tk-transition, 140ms ease), background var(--tk-transition, 140ms ease);
}
.subtask-mini:hover .subtask-add-sticker { opacity: 1; }
.subtask-add-sticker:hover {
  border-color: var(--tk-accent, #E87A1E);
  color: var(--tk-accent-text, #B85A0E);
  background: var(--tk-accent-soft, rgba(232,122,30,0.10));
}

.subtask-add-form {
  display: flex; flex-direction: column; gap: var(--tk-s-1, 4px);
  margin-top: var(--tk-s-2, 8px);
  padding: var(--tk-s-2, 8px);
  background: var(--tk-accent-soft, rgba(232,122,30,0.10));
  border-radius: var(--tk-r-sm, 4px);
}
.subtask-add-form input {
  padding: 6px var(--tk-s-2, 8px);
  font-size: var(--tk-fz-sm, 12px);
  border: 1px solid var(--tk-accent, #E87A1E);
  border-radius: var(--tk-r-sm, 4px);
  background: var(--tk-n-0, #fff);
  color: var(--tk-text, #172B4D);
  font-family: inherit;
}
.subtask-add-form input:focus { outline: none; box-shadow: var(--tk-focus-ring, 0 0 0 2px rgba(232,122,30,0.35)); }
.subtask-add-actions { display: flex; gap: var(--tk-s-1, 4px); }
.subtask-add-actions .btn { padding: 4px var(--tk-s-3, 12px); font-size: var(--tk-fz-sm, 12px); }
.ts-btn-sm { padding: 4px var(--tk-s-3, 12px); font-size: var(--tk-fz-sm, 12px); }
</style>
