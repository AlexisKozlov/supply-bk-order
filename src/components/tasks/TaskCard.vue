<template>
  <div
    class="task-card"
    :class="[priorityClass, dueClass, { 'is-overdue': isOverdue, 'is-done': card.is_done, 'is-dragging': dragging }]"
    draggable="true"
    @dragstart="onDragStart"
    @dragend="onDragEnd"
    @click="onCardClick"
  >
    <div v-if="cardLabels.length || canEditCard" class="task-card-labels">
      <span v-for="l in cardLabels" :key="l.id" class="label-pill" :style="{ background: l.color }"
            @click.stop="openLabelsPicker">{{ l.title }}</span>
      <button v-if="canEditCard && !cardLabels.length" class="badge-add" @click.stop="openLabelsPicker"
              title="Добавить метку">+ метка</button>
    </div>

    <button v-if="canEditCard" class="card-menu-btn" @click.stop="cardMenuOpen = !cardMenuOpen" title="Меню">⋮</button>
    <div v-if="cardMenuOpen" class="card-menu" v-click-outside-card="() => cardMenuOpen = false" @click.stop>
      <button class="card-menu-item" @click="startAddSubtask">
        <span class="cm-icon">＋</span> Создать подзадачу
      </button>
      <button class="card-menu-item" @click="openCardFromMenu">
        <span class="cm-icon">↗</span> Открыть задачу
      </button>
      <div class="card-menu-divider"></div>
      <button class="card-menu-item" @click="duplicateCard">
        <span class="cm-icon">⧉</span> Дублировать
      </button>
      <button class="card-menu-item submenu-trigger" @click.stop="moveSubmenuOpen = !moveSubmenuOpen">
        <span class="cm-icon">→</span> Переместить в колонку
        <span class="cm-arrow">▸</span>
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
      <button class="card-menu-item danger" @click="deleteCardFromMenu">
        <span class="cm-icon">✕</span> Удалить карточку
      </button>
    </div>

    <div class="task-card-title">{{ card.title }}</div>

    <!-- Бейджи внизу -->
    <div class="task-card-badges">
      <!-- Приоритет -->
      <button class="badge prio-badge" :class="'prio-bg-' + (card.priority || 'medium')"
              @click.stop="togglePopover('priority')"
              :title="'Приоритет: ' + priorityLabel">
        <span class="prio-dot"></span>{{ priorityLabel }}
      </button>

      <!-- Срок -->
      <button v-if="card.due_date" class="badge due-badge" :class="{ overdue: isOverdue }"
              @click.stop="togglePopover('due')" :title="'Срок: ' + (card.due_date || '')">
        <BkIcon name="calendar" size="xs"/> {{ formatDue }}
      </button>
      <button v-else-if="canEditCard" class="badge-add" @click.stop="togglePopover('due')">+ срок</button>

      <!-- Чек-лист -->
      <span v-if="card.checklist?.total" class="badge meta-badge">
        ✓ {{ card.checklist.done }}/{{ card.checklist.total }}
      </span>

      <!-- Чат -->
      <span v-if="card.comments" class="badge meta-badge" @click.stop="openCardChat">
        <BkIcon name="chat" size="xs"/> {{ card.comments }}
      </span>

      <!-- Соисполнители -->
      <span v-if="card.assignees?.length" class="badge assignees-badge">
        <span v-for="(n, i) in card.assignees.slice(0,3)" :key="i" class="assignee-bubble" :title="n">{{ initials(n) }}</span>
        <span v-if="card.assignees.length > 3" class="assignee-more">+{{ card.assignees.length - 3 }}</span>
      </span>
    </div>

    <!-- Раскрывалка подзадач (только если есть подзадачи) -->
    <div v-if="hasSubtasks" class="subtasks-toggle" @click.stop="toggleSubtasks">
      <span class="subtasks-chevron">{{ subtasksOpen ? '▾' : '▸' }}</span>
      <span class="subtasks-label">Подзадачи</span>
      <span class="subtasks-counter">{{ card.subtasks_done || 0 }}/{{ card.subtasks_total || 0 }}</span>
    </div>

    <!-- Список подзадач (раскрытый) -->
    <div v-if="hasSubtasks && subtasksOpen" class="subtasks-list" @click.stop>
      <div v-for="st in card.subtasks || []" :key="st.id" class="subtask-mini"
           :class="{ done: st.is_done, overdue: isSubOverdue(st) }">
        <input type="checkbox" :checked="!!st.is_done" @click.stop @change="toggleSubtaskDone(st)" class="round-chk" />
        <span class="subtask-mini-title" @click.stop="$emit('open-subtask', { parent: card, subtask: st })">
          {{ st.title }}
          <span v-if="st.due_date" class="subtask-mini-due">{{ formatSubDue(st.due_date) }}</span>
        </span>
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
import BkIcon from '@/components/ui/BkIcon.vue';
import { tasksApi } from '@/lib/tasksApi.js';
import { useTasksStore } from '@/stores/tasksStore.js';

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
const priorityClass = computed(() => 'prio-' + (props.card.priority || 'medium'));
const priorityLabel = computed(() => (priorities.find(p => p.value === props.card.priority)?.label) || 'Средний');
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
  // Не открываем модалку, если кликали по бейджу/поповеру/подзадачам/меню
  if (e.target.closest('.badge') || e.target.closest('.badge-add') || e.target.closest('.card-popover') ||
      e.target.closest('.label-pill') || e.target.closest('.subtasks-toggle') || e.target.closest('.subtasks-list') ||
      e.target.closest('.card-menu-btn') || e.target.closest('.card-menu') || e.target.closest('.subtask-add-form')) return;
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
  } catch (e) { alert('Ошибка дублирования: ' + e.message); }
}

async function moveToColumn(columnId) {
  cardMenuOpen.value = false;
  moveSubmenuOpen.value = false;
  if (columnId === props.card.column_id) return;
  try {
    await store.moveCard(props.card.id, columnId, 0);
  } catch (e) { alert('Ошибка перемещения: ' + e.message); }
}

async function deleteCardFromMenu() {
  cardMenuOpen.value = false;
  if (!confirm('Удалить карточку «' + props.card.title + '»?')) return;
  try {
    await tasksApi.deleteCard(props.card.id);
    await store.reload();
  } catch (e) { alert('Ошибка удаления: ' + e.message); }
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
    // Пересчитаем счётчик в родителе
    const subs = props.card.subtasks || [];
    props.card.subtasks_done = subs.filter(s => s.is_done).length;
    emit('subtasks-changed');
  } catch (e) { alert(e.message); }
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
  } catch (e) { alert(e.message); }
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
  } catch (e) { alert(e.message); }
}

async function setDue(e) {
  const v = e.target?.value || '';
  closePopover();
  const due = v ? v.replace('T', ' ') + ':00' : null;
  try {
    await tasksApi.updateCard(props.card.id, { due_date: due });
    props.card.due_date = due;
  } catch (er) { alert(er.message); }
}

async function toggleLabel(l) {
  const current = props.card.label_ids || [];
  const has = current.includes(l.id);
  const newIds = has ? current.filter(id => id !== l.id) : [...current, l.id];
  try {
    await tasksApi.setCardLabels(props.card.id, newIds);
    props.card.label_ids = newIds;
  } catch (e) { alert(e.message); }
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
  background: #fff;
  border: 1px solid var(--border-light, #e5e7eb);
  border-radius: 8px;
  padding: 8px 10px 10px;
  box-shadow: 0 1px 2px rgba(0,0,0,0.04);
  cursor: pointer;
  user-select: none;
  transition: box-shadow .15s, transform .15s, border-color .15s;
  border-left-width: 3px;
  position: relative;
}
.task-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); transform: translateY(-1px); }
.task-card.is-dragging { opacity: 0.4; }
.task-card.is-done { opacity: 0.7; }
.task-card.is-done .task-card-title { text-decoration: line-through; color: var(--text-muted); }

.prio-low    { border-left-color: #B0BEC5; }
.prio-medium { border-left-color: #4FC3F7; }
.prio-high   { border-left-color: #FFB74D; }
.prio-urgent { border-left-color: #EF5350; }

.task-card-labels { display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: 6px; min-height: 18px; }
.label-pill {
  font-size: 10px; font-weight: 700; color: #fff;
  padding: 2px 7px; border-radius: 10px;
  text-shadow: 0 1px 1px rgba(0,0,0,0.15);
  max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  cursor: pointer;
}
.label-pill:hover { opacity: 0.85; }

.task-card-title {
  font-size: 13.5px; font-weight: 500; color: var(--text, #1f2937);
  line-height: 1.35; word-break: break-word;
  margin-bottom: 8px;
  padding-right: 18px; /* место под кнопку «⋮» */
}

/* ═══ Меню «⋮» ═══ */
.card-menu-btn {
  position: absolute;
  top: 6px; right: 6px;
  background: rgba(255,255,255,0.7);
  border: none; cursor: pointer;
  font-size: 14px; color: var(--text-muted);
  width: 22px; height: 22px; padding: 0;
  border-radius: 4px;
  line-height: 1;
  opacity: 0;
  transition: opacity .15s, background .15s, color .15s;
  z-index: 5;
}
.task-card:hover .card-menu-btn { opacity: 0.85; }
.card-menu-btn:hover {
  background: rgba(0,0,0,0.08); color: var(--text);
  opacity: 1 !important;
}

.card-menu {
  position: absolute; top: 30px; right: 6px; z-index: 30;
  background: #fff; border: 1px solid var(--border-light);
  border-radius: 8px; box-shadow: 0 6px 18px rgba(0,0,0,0.15);
  min-width: 200px; padding: 4px;
}
.card-menu-item {
  display: flex; align-items: center; gap: 8px;
  width: 100%; text-align: left;
  padding: 7px 10px; border: none; background: none; cursor: pointer;
  font-size: 13px; color: var(--text); border-radius: 5px;
  font-family: inherit;
  position: relative;
}
.card-menu-item:hover { background: var(--bg-secondary, #f5f5f5); }
.card-menu-item.danger { color: #E53935; }
.card-menu-item.danger:hover { background: rgba(229,57,53,0.08); }
.card-menu-item.disabled { opacity: 0.4; cursor: default; }
.card-menu-item.disabled:hover { background: none; }
.card-menu-divider {
  height: 1px; background: var(--border-light);
  margin: 4px 0;
}
.cm-icon {
  display: inline-flex; align-items: center; justify-content: center;
  width: 18px; font-size: 13px; color: var(--text-muted);
  flex-shrink: 0;
}
.card-menu-item.danger .cm-icon { color: #E53935; }
.cm-arrow { margin-left: auto; font-size: 10px; color: var(--text-muted); }
.cm-col-dot {
  display: inline-block; width: 10px; height: 10px;
  border-radius: 50%; flex-shrink: 0;
}
.card-submenu {
  margin-left: 14px; margin-top: 2px;
  background: var(--bg-secondary, #f8f8fa);
  border-radius: 6px; padding: 2px;
}
.card-submenu .card-menu-item {
  font-size: 12.5px; padding: 5px 8px;
}

.task-card-badges {
  display: flex; gap: 4px; flex-wrap: wrap; align-items: center;
}

/* Бейджи */
.badge {
  display: inline-flex; align-items: center; gap: 3px;
  font-size: 11px; font-weight: 600;
  padding: 2px 7px; border-radius: 10px;
  border: none; cursor: pointer;
  background: var(--bg-secondary, #f0f1f3); color: var(--text, #1f2937);
  font-family: inherit;
  transition: filter .15s;
}
.badge:hover { filter: brightness(0.95); }
.badge.meta-badge { background: rgba(0,0,0,0.06); color: var(--text-muted); cursor: default; }
.badge .bk-icon-wrap { line-height: 0; }

.prio-badge { padding-left: 6px; }
.prio-dot {
  width: 8px; height: 8px; border-radius: 50%;
  display: inline-block;
  background: #4FC3F7;
}
.prio-bg-low    { background: #ECEFF1 !important; color: #455A64 !important; }
.prio-bg-low .prio-dot { background: #B0BEC5; }
.prio-bg-medium { background: #E1F5FE !important; color: #0277BD !important; }
.prio-bg-medium .prio-dot { background: #4FC3F7; }
.prio-bg-high   { background: #FFF3E0 !important; color: #E65100 !important; }
.prio-bg-high .prio-dot { background: #FFB74D; }
.prio-bg-urgent { background: #FFEBEE !important; color: #C62828 !important; }
.prio-bg-urgent .prio-dot { background: #EF5350; }

.due-badge { background: #E8F5E9; color: #2E7D32; }
.due-badge.overdue { background: #FFEBEE; color: #C62828; font-weight: 700; }

/* ═══ «Срок горит» — выделение карточек ═══ */
.task-card.due-fire {
  background: linear-gradient(180deg, #FFF3E0 0%, #fff 50%);
  box-shadow: 0 0 0 1px #FFB74D, 0 1px 4px rgba(255,152,0,0.2);
}
.task-card.due-fire .due-badge { background: #FFE0B2; color: #E65100; }
.task-card.due-warn {
  background: linear-gradient(180deg, #FFFDE7 0%, #fff 50%);
}
.task-card.due-warn .due-badge { background: #FFF59D; color: #F57F17; }
.task-card.due-overdue, .is-overdue {
  background: linear-gradient(180deg, #FFEBEE 0%, #fff 50%);
  box-shadow: 0 0 0 1px #EF9A9A, 0 1px 4px rgba(244,67,54,0.2);
}

.badge-add {
  font-size: 11px; font-weight: 600;
  padding: 2px 7px; border-radius: 10px;
  border: 1.5px dashed var(--border, #ccc); background: transparent;
  color: var(--text-muted); cursor: pointer; font-family: inherit;
}
.badge-add:hover { border-color: var(--bk-orange, #E87A1E); color: var(--bk-orange, #E87A1E); }

.assignees-badge { gap: 0; padding: 1px 5px 1px 1px; background: transparent !important; }
.assignee-bubble {
  display: inline-flex; align-items: center; justify-content: center;
  width: 20px; height: 20px; border-radius: 50%;
  background: linear-gradient(135deg, #E76F51, #F4A261);
  color: #fff; font-size: 9px; font-weight: 700;
  border: 1.5px solid #fff;
  margin-left: -4px;
}
.assignee-bubble:first-child { margin-left: 0; }
.assignee-more {
  font-size: 11px; color: var(--text-muted);
  margin-left: 2px;
}

/* Поповер */
.card-popover {
  position: absolute; top: 100%; left: 0; right: 0;
  margin-top: 4px; z-index: 50;
  background: #fff; border: 1px solid var(--border-light);
  border-radius: 8px; box-shadow: 0 4px 18px rgba(0,0,0,0.15);
  padding: 8px;
  cursor: default;
}
.popover-title {
  font-size: 11px; font-weight: 700; color: var(--text-muted);
  text-transform: uppercase; letter-spacing: .5px;
  margin-bottom: 6px;
}
.popover-item {
  display: flex; align-items: center; gap: 8px;
  width: 100%; padding: 6px 8px; border-radius: 6px;
  background: none; border: none; cursor: pointer; text-align: left;
  font-family: inherit; font-size: 13px; color: var(--text);
}
.popover-item:hover { background: var(--bg-secondary, #f5f5f5); }
.popover-item.active { background: rgba(232, 122, 30, 0.1); font-weight: 600; }
.popover-item .prio-dot { flex-shrink: 0; }

.popover-input {
  width: 100%; box-sizing: border-box;
  padding: 6px 8px; font-size: 13px;
  border: 1px solid var(--border-light); border-radius: 6px;
  background: #fff; color: var(--text); font-family: inherit;
}
.popover-actions { margin-top: 6px; display: flex; justify-content: flex-end; }
.popover-clear {
  background: none; border: none; cursor: pointer;
  color: #E53935; font-size: 12px; font-weight: 600; padding: 4px 8px;
}
.popover-clear:hover { background: rgba(229,57,53,0.1); border-radius: 4px; }

.labels-popover .popover-labels {
  display: flex; flex-wrap: wrap; gap: 4px; max-height: 180px; overflow-y: auto;
}
.popover-label {
  padding: 3px 8px; border-radius: 10px; font-size: 11px; font-weight: 600;
  cursor: pointer; border: 1.5px solid; background: transparent;
  font-family: inherit;
}
.popover-empty {
  font-size: 12px; color: var(--text-muted); font-style: italic; padding: 8px 4px;
}

/* ═══ Раскрывалка подзадач ═══ */
.subtasks-toggle {
  display: flex; align-items: center; gap: 6px;
  margin-top: 8px;
  padding: 4px 6px;
  background: rgba(0,0,0,0.03);
  border-radius: 5px;
  cursor: pointer;
  font-size: 11.5px; color: var(--text-muted);
  font-weight: 600;
  user-select: none;
  transition: background .12s;
}
.subtasks-toggle:hover { background: rgba(0,0,0,0.06); color: var(--text); }
.subtasks-chevron { font-size: 10px; line-height: 1; width: 10px; text-align: center; }
.subtasks-counter {
  margin-left: auto;
  font-weight: 700;
  background: rgba(66,165,245,0.15);
  color: #1565C0;
  padding: 1px 7px; border-radius: 8px;
  font-size: 10.5px;
}

.subtasks-list {
  margin-top: 6px;
  display: flex; flex-direction: column; gap: 4px;
  padding: 4px 0;
}
.subtask-mini {
  display: flex; align-items: center; gap: 6px;
  background: #fff;
  border: 1px solid var(--border-light);
  border-radius: 6px;
  padding: 6px 8px;
  font-size: 12.5px;
  transition: border-color .15s, background .15s;
}
.subtask-mini:hover { border-color: #4FC3F7; background: #FAFEFF; }
.subtask-mini.done { background: #F5F5F5; }
.subtask-mini.done .subtask-mini-title { text-decoration: line-through; color: var(--text-muted); }
.subtask-mini.overdue .subtask-mini-due { background: #FFEBEE; color: #C62828; }

/* ═══ Круглый чекбокс ═══ */
.round-chk {
  appearance: none; -webkit-appearance: none;
  width: 18px; height: 18px;
  border: 2px solid #B0BEC5; border-radius: 50%;
  background: #fff;
  cursor: pointer;
  flex-shrink: 0; margin: 0;
  display: inline-flex; align-items: center; justify-content: center;
  transition: all .15s;
  position: relative;
}
.round-chk:hover { border-color: #4FC3F7; }
.round-chk:checked {
  background: #66BB6A; border-color: #66BB6A;
}
.round-chk:checked::after {
  content: ''; display: block;
  width: 8px; height: 4px;
  border-left: 2px solid #fff; border-bottom: 2px solid #fff;
  transform: rotate(-45deg) translate(1px, -1px);
}
.subtask-mini-title {
  flex: 1; min-width: 0; cursor: pointer;
  color: var(--text); line-height: 1.3;
  display: flex; align-items: center; justify-content: space-between; gap: 6px;
  word-break: break-word;
}
.subtask-mini-title:hover { color: #1976D2; }
.subtask-mini-due {
  flex-shrink: 0;
  font-size: 10.5px; font-weight: 600;
  padding: 1px 6px; border-radius: 8px;
  background: #E8F5E9; color: #2E7D32;
}

.subtask-add-form {
  display: flex; flex-direction: column; gap: 4px;
  margin-top: 8px;
  padding: 6px;
  background: rgba(232,122,30,0.06);
  border-radius: 6px;
}
.subtask-add-form input {
  padding: 5px 8px; font-size: 12.5px;
  border: 1px solid var(--bk-orange, #E87A1E); border-radius: 5px;
  background: #fff; color: var(--text); font-family: inherit;
}
.subtask-add-actions { display: flex; gap: 4px; }
.subtask-add-actions .btn { padding: 3px 10px; font-size: 12px; }
.ts-btn-sm { padding: 3px 10px; font-size: 12px; }
</style>
