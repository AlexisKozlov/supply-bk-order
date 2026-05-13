<template>
  <div
    class="task-column"
    :class="{ 'is-drop-target': dropActive, 'is-archive': column.is_archive_column, 'wip-exceeded': wipExceeded }"
    :style="{ '--col-color': column.color || '#9E9E9E' }"
    @dragover.prevent="onColumnDragOver"
    @dragleave="onColumnDragLeave"
    @drop="onColumnDrop"
  >
    <div class="task-column-header">
      <span v-if="!column.is_archive_column" class="task-column-color-dot" :style="{ background: column.color || '#9E9E9E' }"></span>
      <TaskIcon v-if="column.is_archive_column" name="archive" :size="14" class="archive-icon"/>
      <div class="task-column-title-wrap">
        <span v-if="!editingTitle || column.is_archive_column" class="task-column-title"
              @dblclick="!column.is_archive_column && startEditTitle()">{{ column.title }}</span>
        <input v-else ref="titleInput" v-model="titleDraft" class="task-column-title-input"
               @blur="saveTitle" @keydown.enter="saveTitle" @keydown.esc="cancelEditTitle" />
        <span class="task-column-count" :class="{ 'wip-bad': wipExceeded }"
              :title="column.wip_limit ? ('WIP-лимит: ' + totalCount + ' из ' + column.wip_limit) : ''">
          {{ items.length }}{{ totalCount !== items.length ? '/' + totalCount : '' }}
          <span v-if="column.wip_limit" class="wip-suffix">· {{ totalCount }}/{{ column.wip_limit }}</span>
        </span>
      </div>
      <button class="task-column-filter-btn" :class="{ active: hasActiveFilters }"
              @click.stop="filterOpen = !filterOpen" title="Фильтр колонки">
        <TaskIcon name="filter" :size="14"/>
        <span v-if="hasActiveFilters" class="filter-dot"></span>
      </button>
      <button v-if="canEditStructure && !column.is_archive_column" class="task-column-menu-btn" @click="menuOpen = !menuOpen" title="Меню колонки">
        <TaskIcon name="more" :size="16"/>
      </button>
      <div v-if="menuOpen" v-click-outside="closeMenu" class="task-column-menu">
        <button @click="startEditTitle">
          <TaskIcon name="edit" :size="14"/> Переименовать
        </button>
        <button @click="openColorPicker">
          <span class="menu-color-dot" :style="{ background: column.color || '#9E9E9E' }"></span>
          Изменить цвет
        </button>
        <button @click="openWipPicker">
          <TaskIcon name="list" :size="14"/>
          WIP-лимит
          <span v-if="column.wip_limit" class="menu-meta">{{ column.wip_limit }}</span>
        </button>
        <button class="danger" @click="askDelete">
          <TaskIcon name="trash" :size="14"/> Удалить колонку
        </button>
      </div>

      <!-- Поповер выбора цвета колонки -->
      <div v-if="colorPickerOpen" v-click-outside="() => colorPickerOpen = false" class="task-column-color-pop">
        <div class="tcc-title">Цвет колонки</div>
        <ColorPalette :model-value="column.color || '#9E9E9E'" @update:modelValue="onColorPick"/>
      </div>

      <!-- Поповер WIP-лимита -->
      <div v-if="wipPickerOpen" v-click-outside="() => wipPickerOpen = false" class="task-column-wip-pop">
        <div class="tcc-title">WIP-лимит колонки</div>
        <div class="tcw-hint">Сколько карточек может быть одновременно. 0 — без лимита.</div>
        <div class="tcw-row">
          <input ref="wipInput" v-model.number="wipDraft" type="number" min="0" max="999"
                 class="tcw-input" @keydown.enter="saveWip" @keydown.esc="wipPickerOpen = false"/>
          <button class="btn primary ts-btn-sm" @click="saveWip">Применить</button>
        </div>
      </div>

      <!-- Поповер фильтров для этой колонки -->
      <div v-if="filterOpen" v-click-outside="closeFilter" class="task-column-filter-pop">
        <div class="tcf-row">
          <div class="tcf-label">Приоритет</div>
          <div class="tcf-chips">
            <button v-for="p in PRIO_OPTS" :key="p.value" class="tcf-chip"
                    :class="{ active: filters.priorities.includes(p.value), ['prio-' + p.value]: filters.priorities.includes(p.value) }"
                    @click="togglePrio(p.value)">{{ p.label }}</button>
          </div>
        </div>
        <div class="tcf-row">
          <div class="tcf-label">Срок</div>
          <div class="tcf-chips">
            <button v-for="d in DUE_OPTS" :key="d.value" class="tcf-chip"
                    :class="{ active: filters.dueState === d.value }"
                    @click="filters.dueState = filters.dueState === d.value ? '' : d.value">{{ d.label }}</button>
          </div>
        </div>
        <div v-if="labels.length" class="tcf-row">
          <div class="tcf-label">Метки</div>
          <div class="tcf-chips">
            <button v-for="l in labels" :key="l.id" class="tcf-chip"
                    :class="{ active: filters.labels.includes(l.id) }"
                    :style="filters.labels.includes(l.id) ? { background: l.color, color: '#fff', borderColor: l.color } : { borderColor: l.color, color: l.color }"
                    @click="toggleLabel(l.id)">{{ l.title }}</button>
          </div>
        </div>
        <div v-if="columnAssignees.length" class="tcf-row">
          <div class="tcf-label">Исполнители</div>
          <div class="tcf-chips">
            <button v-for="n in columnAssignees" :key="n" class="tcf-chip"
                    :class="{ active: filters.assignees.includes(n) }"
                    @click="toggleAssignee(n)">{{ n }}</button>
          </div>
        </div>
        <div class="tcf-row">
          <div class="tcf-label">Текст</div>
          <input v-model="filters.text" type="text" class="tcf-text" placeholder="Содержит…" />
        </div>
        <div class="tcf-actions">
          <button v-if="hasActiveFilters" class="tcf-reset" @click="resetFilters">Сбросить</button>
          <button class="tcf-close" @click="filterOpen = false">Закрыть</button>
        </div>
      </div>
    </div>

    <div class="task-column-body">
      <div
        v-for="(card, i) in items"
        :key="card.id"
        class="card-slot"
        @dragover.prevent="onSlotDragOver(i, $event)"
        @drop.stop="onSlotDrop(i)"
      >
        <div v-if="dropIndex === i" class="drop-indicator"></div>
        <TaskCard :card="card" :labels="labels" @open="$emit('open-card', card)"
                  @open-subtask="payload => $emit('open-subtask', payload)"
                  @subtasks-changed="$emit('subtasks-changed')"
                  @dragstart="$emit('card-dragstart', card)" @dragend="$emit('card-dragend')" />
      </div>
      <div v-if="dropIndex === items.length" class="drop-indicator"></div>
      <div v-if="!items.length && !adding" class="task-column-empty">
        <span class="task-column-empty-text">{{ column.is_archive_column ? 'Архив пуст' : 'Карточек пока нет' }}</span>
      </div>
    </div>

    <div v-if="!column.is_archive_column" class="task-column-footer">
      <button v-if="!adding" class="task-column-add-btn" @click="adding = true; $nextTick(() => $refs.addInput?.focus())">
        <TaskIcon name="plus" :size="14"/>
        <span>Добавить карточку</span>
      </button>
      <div v-else class="task-column-add-form">
        <textarea ref="addInput" v-model="newTitle" rows="2" placeholder="Название карточки"
                  @keydown.enter.prevent="submitNew" @keydown.esc="cancelAdd"></textarea>
        <div class="task-column-add-actions">
          <button class="btn primary" @click="submitNew" :disabled="!newTitle.trim()">Добавить карточку</button>
          <button class="task-column-add-cancel" @click="cancelAdd" title="Отмена">
            <TaskIcon name="close" :size="14"/>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, nextTick, computed } from 'vue';
import TaskCard from './TaskCard.vue';
import TaskIcon from './TaskIcon.vue';
import ColorPalette from './ColorPalette.vue';
import { useTasksStore } from '@/stores/tasksStore.js';
import { useTasksDialogs } from '@/composables/useTasksDialogs.js';
const dlg = useTasksDialogs();

const props = defineProps({
  column: { type: Object, required: true },
  items: { type: Array, default: () => [] },
  allItems: { type: Array, default: () => [] },
  labels: { type: Array, default: () => [] },
  canEditStructure: { type: Boolean, default: false },
  draggedCard: { type: Object, default: null },
});

const store = useTasksStore();
const filterOpen = ref(false);
const filters = computed(() => store.getColumnFilters(props.column.id));
const hasActiveFilters = computed(() => store.columnHasActiveFilters(props.column.id));
const totalCount = computed(() => props.allItems.length || props.items.length);
const columnAssignees = computed(() => {
  const set = new Set();
  for (const c of props.allItems) for (const n of (c.assignees || [])) set.add(n);
  return [...set].sort();
});

const PRIO_OPTS = [
  { value: 'urgent', label: 'Срочно' },
  { value: 'high',   label: 'Высокий' },
  { value: 'medium', label: 'Средний' },
  { value: 'low',    label: 'Низкий' },
];
const DUE_OPTS = [
  { value: 'overdue', label: '🔴 Просрочено' },
  { value: 'today',   label: '🟠 Сегодня' },
  { value: 'week',    label: '🟢 На неделе' },
  { value: 'no-due',  label: 'Без срока' },
];

function togglePrio(p) {
  const arr = filters.value.priorities;
  const i = arr.indexOf(p);
  if (i >= 0) arr.splice(i, 1); else arr.push(p);
}
function toggleLabel(id) {
  const arr = filters.value.labels;
  const i = arr.indexOf(id);
  if (i >= 0) arr.splice(i, 1); else arr.push(id);
}
function toggleAssignee(n) {
  const arr = filters.value.assignees;
  const i = arr.indexOf(n);
  if (i >= 0) arr.splice(i, 1); else arr.push(n);
}
function resetFilters() { store.resetColumnFilters(props.column.id); }
function closeFilter() { filterOpen.value = false; }
const emit = defineEmits([
  'open-card', 'open-subtask', 'subtasks-changed', 'add-card', 'move-card',
  'rename', 'set-color', 'set-wip-limit', 'toggle-done', 'delete',
  'card-dragstart', 'card-dragend',
]);

const adding = ref(false);
const newTitle = ref('');
const editingTitle = ref(false);
const titleDraft = ref('');
const titleInput = ref(null);
const menuOpen = ref(false);
const colorPickerOpen = ref(false);
const wipPickerOpen = ref(false);
const wipDraft = ref(0);
const wipInput = ref(null);
const wipExceeded = computed(() => {
  const limit = props.column.wip_limit || 0;
  if (!limit) return false;
  return totalCount.value > limit;
});
const dropIndex = ref(null);
const dropActive = ref(false);

function submitNew() {
  const t = newTitle.value.trim();
  if (!t) return;
  emit('add-card', { column_id: props.column.id, title: t });
  newTitle.value = '';
  adding.value = false;
}
function cancelAdd() { newTitle.value = ''; adding.value = false; }

function startEditTitle() {
  if (!props.canEditStructure) return;
  titleDraft.value = props.column.title;
  editingTitle.value = true;
  menuOpen.value = false;
  nextTick(() => titleInput.value?.focus());
}
function saveTitle() {
  const t = titleDraft.value.trim();
  if (t && t !== props.column.title) emit('rename', t);
  editingTitle.value = false;
}
function cancelEditTitle() { editingTitle.value = false; }

function openColorPicker() {
  menuOpen.value = false;
  colorPickerOpen.value = true;
}
function onColorPick(c) {
  colorPickerOpen.value = false;
  if (c) emit('set-color', c);
}
function openWipPicker() {
  menuOpen.value = false;
  wipDraft.value = props.column.wip_limit || 0;
  wipPickerOpen.value = true;
  nextTick(() => wipInput.value?.focus());
}
function saveWip() {
  const v = Math.max(0, Math.min(999, parseInt(wipDraft.value, 10) || 0));
  emit('set-wip-limit', v || null);
  wipPickerOpen.value = false;
}
function toggleDone() { menuOpen.value = false; emit('toggle-done'); }
async function askDelete() {
  menuOpen.value = false;
  if (props.items.length) {
    dlg.info('Колонка не пустая', 'Сначала перенесите карточки из колонки.', 'warning');
    return;
  }
  const ok = await dlg.confirm('Удалить колонку', 'Удалить колонку «' + props.column.title + '»?',
    { okText: 'Удалить', danger: true });
  if (ok) emit('delete');
}
function closeMenu() { menuOpen.value = false; }

function onColumnDragOver(e) {
  if (!props.draggedCard) return;
  dropActive.value = true;
  e.dataTransfer.dropEffect = 'move';
  if (dropIndex.value === null) dropIndex.value = props.items.length;
}
function onColumnDragLeave(e) {
  // Игнорируем переход к дочерним
  if (e.currentTarget.contains(e.relatedTarget)) return;
  dropActive.value = false;
  dropIndex.value = null;
}
function onColumnDrop() {
  if (!props.draggedCard) { dropActive.value = false; dropIndex.value = null; return; }
  const idx = dropIndex.value === null ? props.items.length : dropIndex.value;
  emit('move-card', { card: props.draggedCard, to_column_id: props.column.id, to_index: idx });
  dropActive.value = false;
  dropIndex.value = null;
}
function onSlotDragOver(i, e) {
  if (!props.draggedCard) return;
  const r = e.currentTarget.getBoundingClientRect();
  const half = (e.clientY - r.top) > r.height / 2;
  dropIndex.value = half ? i + 1 : i;
}
function onSlotDrop(i) {
  // Делегируем на column
  onColumnDrop();
}

// Простая директива click-outside (локальная)
const vClickOutside = {
  mounted(el, binding) {
    el.__clickOutside = (event) => {
      if (!el.contains(event.target)) binding.value(event);
    };
    setTimeout(() => document.addEventListener('click', el.__clickOutside), 0);
  },
  unmounted(el) {
    document.removeEventListener('click', el.__clickOutside);
  },
};
defineExpose({});
</script>

<style scoped>
.task-column {
  background: var(--tk-bg-column, #fff);
  border-radius: var(--tk-r-lg, 14px);
  padding: 0;
  width: 288px;
  flex: 0 0 288px;
  display: flex;
  flex-direction: column;
  max-height: 100%;
  border: 1px solid var(--tk-border-soft, #EAEDF4);
  box-shadow: 0 1px 2px rgba(15,23,42,0.04);
  transition: border-color var(--tk-transition, 140ms ease), background var(--tk-transition, 140ms ease);
}
.task-column.is-drop-target {
  border-color: var(--tk-accent, #E87A1E);
  background: var(--tk-accent-soft, rgba(232,122,30,0.10));
}

/* Архив-колонка — приглушённая */
.task-column.is-archive {
  background: rgba(9,30,66,0.04);
  box-shadow: inset 0 0 0 1px rgba(9,30,66,0.06);
}
.task-column.is-archive :deep(.task-card) {
  opacity: 0.7;
}
.task-column.is-archive :deep(.task-card:hover) {
  opacity: 1;
}
.archive-icon {
  color: var(--tk-text-muted, #758195);
  margin-right: 2px;
}
.task-column { position: relative; }
.task-column-color-dot {
  flex-shrink: 0;
  width: 10px; height: 10px;
  border-radius: 50%;
  background: var(--col-color, #9E9E9E);
}

.task-column-header {
  padding: var(--tk-s-3, 12px) var(--tk-s-3, 12px) var(--tk-s-2, 8px);
  display: flex; align-items: center; gap: var(--tk-s-2, 8px);
  position: relative;
}
.task-column-title-wrap { flex: 1; display: flex; align-items: center; gap: var(--tk-s-2, 8px); min-width: 0; }
.task-column-title {
  font-weight: var(--tk-fw-semibold, 600);
  font-size: var(--tk-fz-lg, 14px);
  color: var(--tk-text, #172B4D);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  cursor: text;
}
.task-column-title-input {
  font-weight: var(--tk-fw-semibold, 600);
  font-size: var(--tk-fz-md, 13px);
  border: 1px solid var(--tk-border, #DCDFE4);
  border-radius: var(--tk-r-sm, 4px);
  padding: 2px var(--tk-s-2, 8px); flex: 1;
  color: var(--tk-text, #172B4D);
  background: var(--tk-n-0, #fff);
  font-family: inherit;
}
.task-column-title-input:focus { outline: none; border-color: var(--tk-accent, #E87A1E); box-shadow: var(--tk-focus-ring, 0 0 0 2px rgba(232,122,30,0.35)); }
.task-column-count {
  background: rgba(9,30,66,0.08); color: var(--tk-text-muted, #758195);
  font-size: var(--tk-fz-xs, 11px); font-weight: var(--tk-fw-semibold, 600);
  padding: 1px 7px; border-radius: 10px;
  font-feature-settings: 'tnum';
  display: inline-flex; align-items: center; gap: 4px;
}
.task-column-count .wip-suffix { opacity: 0.75; font-weight: var(--tk-fw-medium, 500); }
.task-column-count.wip-bad {
  background: #FFE4E0;
  color: #B81C0C;
}
.task-column-count.wip-bad .wip-suffix { opacity: 1; font-weight: var(--tk-fw-bold, 700); }
.task-column-menu-btn,
.task-column-filter-btn {
  background: none; border: none; cursor: pointer;
  font-size: var(--tk-fz-md, 13px);
  color: var(--tk-text-muted, #758195);
  width: 26px; height: 26px;
  border-radius: var(--tk-r-sm, 4px);
  display: flex; align-items: center; justify-content: center;
  position: relative;
  transition: background var(--tk-transition, 120ms ease), color var(--tk-transition, 120ms ease);
}
.task-column-menu-btn { font-size: var(--tk-fz-h1, 18px); }
.task-column-menu-btn:hover,
.task-column-filter-btn:hover { background: rgba(9,30,66,0.08); color: var(--tk-text, #172B4D); }
.task-column-filter-btn.active {
  color: var(--tk-accent, #E87A1E);
  background: var(--tk-accent-soft-strong, rgba(232,122,30,0.18));
}
.task-column-filter-btn .filter-dot {
  position: absolute; top: 2px; right: 2px;
  width: 5px; height: 5px; border-radius: 50%;
  background: var(--tk-accent, #E87A1E);
}

/* ═══ Поповер фильтров колонки ═══ */
.task-column-filter-pop {
  position: absolute; top: 40px; right: var(--tk-s-1, 4px); z-index: 30;
  background: var(--tk-bg-popover, #fff);
  border: 1px solid var(--tk-border, #DCDFE4);
  border-radius: var(--tk-r-md, 8px);
  box-shadow: var(--tk-shadow-popover, 0 8px 24px rgba(9,30,66,0.18));
  padding: var(--tk-s-3, 12px);
  width: 290px;
  display: flex; flex-direction: column; gap: var(--tk-s-2, 8px);
}
.tcf-row { display: flex; flex-direction: column; gap: var(--tk-s-1, 4px); }
.tcf-label {
  font-size: var(--tk-fz-xs, 11px); font-weight: var(--tk-fw-bold, 700);
  color: var(--tk-text-muted, #758195);
  text-transform: uppercase; letter-spacing: .4px;
}
.tcf-chips { display: flex; flex-wrap: wrap; gap: var(--tk-s-1, 4px); }
.tcf-chip {
  padding: 3px var(--tk-s-2, 8px);
  border-radius: 11px;
  font-size: var(--tk-fz-xs, 11px); font-weight: var(--tk-fw-semibold, 600);
  cursor: pointer;
  border: 1px solid var(--tk-border, #DCDFE4);
  background: var(--tk-n-0, #fff);
  color: var(--tk-text, #172B4D);
  font-family: inherit;
  transition: background var(--tk-transition, 120ms ease), border-color var(--tk-transition, 120ms ease), color var(--tk-transition, 120ms ease);
}
.tcf-chip:hover { border-color: var(--tk-accent, #E87A1E); }
.tcf-chip.active {
  background: var(--tk-accent-soft, rgba(232,122,30,0.10));
  border-color: var(--tk-accent, #E87A1E);
  color: var(--tk-accent-text, #B85A0E);
}
.tcf-chip.prio-low    { background: var(--tk-prio-low-bg)    !important; border-color: var(--tk-prio-low-bg)    !important; color: var(--tk-prio-low-fg)    !important; }
.tcf-chip.prio-medium { background: var(--tk-prio-medium-bg) !important; border-color: var(--tk-prio-medium-bg) !important; color: var(--tk-prio-medium-fg) !important; }
.tcf-chip.prio-high   { background: var(--tk-prio-high-bg)   !important; border-color: var(--tk-prio-high-bg)   !important; color: var(--tk-prio-high-fg)   !important; }
.tcf-chip.prio-urgent { background: var(--tk-prio-urgent-bg) !important; border-color: var(--tk-prio-urgent-bg) !important; color: var(--tk-prio-urgent-fg) !important; }
.tcf-text {
  width: 100%; padding: 6px var(--tk-s-2, 8px);
  font-size: var(--tk-fz-md, 13px);
  border: 1px solid var(--tk-border, #DCDFE4);
  border-radius: var(--tk-r-sm, 4px);
  background: var(--tk-n-0, #fff); color: var(--tk-text, #172B4D);
  font-family: inherit;
  box-sizing: border-box;
  transition: border-color var(--tk-transition, 120ms ease), box-shadow var(--tk-transition, 120ms ease);
}
.tcf-text:focus { outline: none; border-color: var(--tk-accent, #E87A1E); box-shadow: var(--tk-focus-ring, 0 0 0 2px rgba(232,122,30,0.35)); }
.tcf-actions {
  display: flex; gap: var(--tk-s-2, 8px); justify-content: flex-end;
  padding-top: var(--tk-s-2, 8px);
  border-top: 1px solid var(--tk-border-soft, #E1E4E8);
}
.tcf-reset, .tcf-close {
  padding: 5px var(--tk-s-3, 12px);
  font-size: var(--tk-fz-sm, 12px);
  border-radius: var(--tk-r-sm, 4px);
  border: 1px solid var(--tk-border, #DCDFE4);
  background: var(--tk-n-0, #fff);
  cursor: pointer; font-family: inherit;
  color: var(--tk-text, #172B4D);
  font-weight: var(--tk-fw-medium, 500);
  transition: background var(--tk-transition, 120ms ease), border-color var(--tk-transition, 120ms ease);
}
.tcf-reset { color: var(--tk-danger, #C9372C); border-color: var(--tk-danger, #C9372C); }
.tcf-reset:hover { background: var(--tk-danger-soft, rgba(201,55,44,0.08)); }
.tcf-close:hover { background: var(--tk-n-100, #F1F2F4); }

.task-column-menu {
  position: absolute; top: 38px; right: var(--tk-s-2, 8px); z-index: 10;
  background: var(--tk-bg-popover, #fff);
  border: 1px solid var(--tk-border, #DCDFE4);
  border-radius: var(--tk-r-md, 8px);
  box-shadow: var(--tk-shadow-popover, 0 8px 24px rgba(9,30,66,0.18));
  display: flex; flex-direction: column; min-width: 200px;
  overflow: hidden;
  padding: var(--tk-s-1, 4px);
}
.task-column-menu button {
  background: none; border: none;
  padding: var(--tk-s-2, 8px) var(--tk-s-3, 12px);
  text-align: left; cursor: pointer;
  font-size: var(--tk-fz-md, 13px);
  font-family: inherit;
  color: var(--tk-text, #172B4D);
  border-radius: var(--tk-r-sm, 4px);
  display: flex; align-items: center; gap: var(--tk-s-2, 8px);
  transition: background var(--tk-transition, 120ms ease);
}
.task-column-menu button:hover { background: var(--tk-n-100, #F1F2F4); }
.task-column-menu button.danger { color: var(--tk-danger, #C9372C); }
.task-column-menu button.active {
  color: var(--tk-success, #1F8F4E);
  font-weight: var(--tk-fw-semibold, 600);
  background: var(--tk-success-soft, rgba(31,143,78,0.10));
}
.menu-color-dot {
  width: 14px; height: 14px;
  border-radius: 50%;
  flex-shrink: 0;
  border: 1px solid rgba(9,30,66,0.10);
}

.task-column-color-pop {
  position: absolute; top: 42px; right: var(--tk-s-2, 8px); z-index: 30;
  background: var(--tk-bg-popover, #fff);
  border: 1px solid var(--tk-border, #DCDFE4);
  border-radius: var(--tk-r-md, 8px);
  box-shadow: var(--tk-shadow-popover, 0 8px 24px rgba(9,30,66,0.18));
  padding: var(--tk-s-3, 12px);
}
.tcc-title {
  font-size: var(--tk-fz-xs, 11px); font-weight: var(--tk-fw-bold, 700);
  color: var(--tk-text-muted, #758195);
  text-transform: uppercase; letter-spacing: .4px;
  margin-bottom: var(--tk-s-2, 8px);
}

/* Поповер WIP-лимита */
.task-column-wip-pop {
  position: absolute; top: 42px; right: var(--tk-s-2, 8px); z-index: 30;
  background: var(--tk-bg-popover, #fff);
  border: 1px solid var(--tk-border, #DCDFE4);
  border-radius: var(--tk-r-md, 8px);
  box-shadow: var(--tk-shadow-popover, 0 8px 24px rgba(9,30,66,0.18));
  padding: var(--tk-s-3, 12px);
  width: 240px;
}
.tcw-hint {
  font-size: var(--tk-fz-sm, 12px);
  color: var(--tk-text-muted, #758195);
  margin-bottom: var(--tk-s-2, 8px);
  line-height: 1.4;
}
.tcw-row { display: flex; gap: var(--tk-s-2, 8px); align-items: center; }
.tcw-input {
  flex: 1;
  border: 1px solid var(--tk-border, #DCDFE4);
  border-radius: var(--tk-r-sm, 4px);
  padding: 6px 10px;
  font-size: var(--tk-fz-md, 13px);
  font-family: inherit;
  background: var(--tk-n-0, #fff);
  color: var(--tk-text, #172B4D);
}
.tcw-input:focus { outline: none; border-color: var(--tk-accent, #E87A1E); box-shadow: var(--tk-focus-ring, 0 0 0 2px rgba(232,122,30,0.35)); }
.menu-meta {
  margin-left: auto;
  font-size: var(--tk-fz-xs, 11px);
  font-weight: var(--tk-fw-semibold, 600);
  color: var(--tk-text-muted, #758195);
  background: var(--tk-n-100, #F1F2F4);
  padding: 1px 6px;
  border-radius: 8px;
}

/* Колонка с превышенным WIP-лимитом */
.task-column.wip-exceeded {
  box-shadow: 0 0 0 2px rgba(201,55,44,0.35) inset;
}
.task-column.wip-exceeded .task-column-color-bar {
  background: #C9372C !important;
}

.task-column-body {
  flex: 1;
  overflow-y: auto;
  padding: var(--tk-s-1, 4px) var(--tk-s-2, 8px) 0;
  display: flex; flex-direction: column; gap: var(--tk-s-2, 8px);
  min-height: 40px;
  scrollbar-width: thin;
  scrollbar-color: rgba(9,30,66,0.20) transparent;
}
.task-column-body::-webkit-scrollbar { width: 8px; }
.task-column-body::-webkit-scrollbar-thumb { background: rgba(9,30,66,0.20); border-radius: 4px; }
.task-column-body::-webkit-scrollbar-thumb:hover { background: rgba(9,30,66,0.30); }
.task-column-body::-webkit-scrollbar-track { background: transparent; }

.card-slot { position: relative; }
.task-column-empty {
  color: var(--tk-text-muted, #758195);
  font-size: var(--tk-fz-sm, 12px);
  padding: var(--tk-s-4, 16px) var(--tk-s-1, 4px);
  text-align: center;
  display: flex; align-items: center; justify-content: center;
  min-height: 64px;
  border: 1px dashed transparent;
  border-radius: var(--tk-r-md, 8px);
  transition: border-color var(--tk-transition, 120ms ease), background var(--tk-transition, 120ms ease);
}
.task-column.is-drop-target .task-column-empty {
  border-color: var(--tk-accent, #E87A1E);
  background: var(--tk-accent-soft, rgba(232,122,30,0.10));
  color: var(--tk-accent-text, #B85A0E);
}
.task-column.is-drop-target .task-column-empty .task-column-empty-text::before { content: 'Отпустите, чтобы переместить'; }
.task-column.is-drop-target .task-column-empty .task-column-empty-text { font-size: 0; }
.task-column.is-drop-target .task-column-empty .task-column-empty-text::before { font-size: var(--tk-fz-sm, 12px); font-weight: var(--tk-fw-semibold, 600); }
.drop-indicator {
  height: 3px;
  background: var(--tk-accent, #E87A1E);
  border-radius: 2px;
  margin: 2px 0;
  box-shadow: 0 0 0 2px rgba(232,122,30,0.15);
}

.task-column-footer { padding: var(--tk-s-2, 8px); }
.task-column-add-btn {
  width: 100%;
  display: flex; align-items: center; gap: 6px;
  background: none;
  border: none;
  color: var(--tk-text-muted, #758195);
  font-size: var(--tk-fz-md, 13px);
  font-weight: var(--tk-fw-medium, 500);
  padding: var(--tk-s-2, 8px) var(--tk-s-3, 12px);
  border-radius: var(--tk-r-md, 8px);
  cursor: pointer;
  text-align: left;
  font-family: inherit;
  transition: background var(--tk-transition, 120ms ease), color var(--tk-transition, 120ms ease);
}
.task-column-add-btn:hover { background: rgba(9,30,66,0.08); color: var(--tk-text, #172B4D); }
.task-column-add-btn:focus-visible {
  outline: none;
  background: rgba(9,30,66,0.06);
  color: var(--tk-text, #172B4D);
  box-shadow: var(--tk-focus-ring, 0 0 0 2px rgba(232,122,30,0.35));
}

.task-column-add-form { display: flex; flex-direction: column; gap: var(--tk-s-2, 8px); }
.task-column-add-form textarea {
  width: 100%;
  border: 1px solid var(--tk-border, #DCDFE4);
  border-radius: var(--tk-r-md, 8px);
  padding: var(--tk-s-2, 8px) var(--tk-s-3, 12px);
  font-size: var(--tk-fz-md, 13px);
  font-family: inherit; resize: vertical;
  background: var(--tk-n-0, #fff);
  color: var(--tk-text, #172B4D);
  box-shadow: var(--tk-shadow-card, 0 1px 0 rgba(9,30,66,0.08));
  box-sizing: border-box;
  transition: border-color var(--tk-transition, 120ms ease), box-shadow var(--tk-transition, 120ms ease);
}
.task-column-add-form textarea:focus { outline: none; border-color: var(--tk-accent, #E87A1E); box-shadow: var(--tk-focus-ring, 0 0 0 2px rgba(232,122,30,0.35)); }

.task-column-add-actions { display: flex; gap: var(--tk-s-2, 8px); align-items: center; }
.task-column-add-actions .btn {
  padding: 0 var(--tk-s-3, 12px);
  height: 32px;
  font-size: var(--tk-fz-md, 13px);
  font-weight: var(--tk-fw-semibold, 600);
}
.task-column-add-cancel {
  width: 32px; height: 32px;
  display: flex; align-items: center; justify-content: center;
  background: none; border: none;
  border-radius: var(--tk-r-sm, 4px);
  color: var(--tk-text-muted, #758195);
  cursor: pointer;
  transition: background var(--tk-transition, 120ms ease), color var(--tk-transition, 120ms ease);
}
.task-column-add-cancel:hover { background: rgba(9,30,66,0.08); color: var(--tk-text, #172B4D); }
</style>
