<template>
  <div
    class="task-column"
    :class="{ 'is-drop-target': dropActive }"
    @dragover.prevent="onColumnDragOver"
    @dragleave="onColumnDragLeave"
    @drop="onColumnDrop"
  >
    <div class="task-column-header" :style="{ borderTopColor: column.color || '#9E9E9E' }">
      <div class="task-column-title-wrap">
        <span v-if="!editingTitle" class="task-column-title" @dblclick="startEditTitle">{{ column.title }}</span>
        <input v-else ref="titleInput" v-model="titleDraft" class="task-column-title-input"
               @blur="saveTitle" @keydown.enter="saveTitle" @keydown.esc="cancelEditTitle" />
        <span class="task-column-count">{{ items.length }}</span>
      </div>
      <button v-if="canEditStructure" class="task-column-menu-btn" @click="menuOpen = !menuOpen" title="Меню колонки">⋮</button>
      <div v-if="menuOpen" v-click-outside="closeMenu" class="task-column-menu">
        <button @click="startEditTitle">Переименовать</button>
        <button @click="pickColor">Изменить цвет</button>
        <button @click="toggleDone" :class="{ active: column.is_done_column }">
          {{ column.is_done_column ? '✓ Колонка «Готово»' : 'Назначить как «Готово»' }}
        </button>
        <button class="danger" @click="askDelete">Удалить колонку</button>
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
      <div v-if="!items.length" class="task-column-empty">Перетащите карточку сюда</div>
    </div>

    <div class="task-column-footer">
      <button v-if="!adding" class="task-column-add-btn" @click="adding = true; $nextTick(() => $refs.addInput?.focus())">
        + Добавить карточку
      </button>
      <div v-else class="task-column-add-form">
        <textarea ref="addInput" v-model="newTitle" rows="2" placeholder="Название карточки"
                  @keydown.enter.prevent="submitNew" @keydown.esc="cancelAdd"></textarea>
        <div class="task-column-add-actions">
          <button class="btn primary" @click="submitNew" :disabled="!newTitle.trim()">Добавить</button>
          <button class="btn" @click="cancelAdd">Отмена</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, nextTick, computed } from 'vue';
import TaskCard from './TaskCard.vue';

const props = defineProps({
  column: { type: Object, required: true },
  items: { type: Array, default: () => [] },
  labels: { type: Array, default: () => [] },
  canEditStructure: { type: Boolean, default: false },
  draggedCard: { type: Object, default: null },
});
const emit = defineEmits([
  'open-card', 'open-subtask', 'subtasks-changed', 'add-card', 'move-card',
  'rename', 'set-color', 'toggle-done', 'delete',
  'card-dragstart', 'card-dragend',
]);

const adding = ref(false);
const newTitle = ref('');
const editingTitle = ref(false);
const titleDraft = ref('');
const titleInput = ref(null);
const menuOpen = ref(false);
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

function pickColor() {
  menuOpen.value = false;
  const cur = props.column.color || '#9E9E9E';
  const c = prompt('Цвет колонки (HEX, например #66BB6A):', cur);
  if (c && /^#[0-9a-fA-F]{6}$/.test(c)) emit('set-color', c);
}
function toggleDone() { menuOpen.value = false; emit('toggle-done'); }
function askDelete() {
  menuOpen.value = false;
  if (props.items.length) { alert('Сначала перенесите карточки из колонки'); return; }
  if (confirm('Удалить колонку «' + props.column.title + '»?')) emit('delete');
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
  background: #F4F5F7;
  border-radius: 10px;
  padding: 0;
  width: 280px;
  flex: 0 0 280px;
  display: flex;
  flex-direction: column;
  max-height: 100%;
  border: 2px solid transparent;
  transition: border-color .15s, background .15s;
}
.task-column.is-drop-target {
  border-color: #4FC3F7;
  background: #ECF7FE;
}

.task-column-header {
  padding: 10px 12px 8px;
  border-top: 4px solid #9E9E9E;
  border-radius: 10px 10px 0 0;
  display: flex; align-items: center; gap: 6px;
  position: relative;
}
.task-column-title-wrap { flex: 1; display: flex; align-items: center; gap: 6px; min-width: 0; }
.task-column-title {
  font-weight: 700; font-size: 13.5px; color: var(--text, #1f2937);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  cursor: text;
}
.task-column-title-input {
  font-weight: 700; font-size: 13.5px;
  border: 1px solid var(--border-light); border-radius: 4px;
  padding: 2px 6px; flex: 1;
}
.task-column-count {
  background: rgba(0,0,0,0.08); color: var(--text-muted);
  font-size: 11px; font-weight: 600;
  padding: 1px 7px; border-radius: 10px;
}
.task-column-menu-btn {
  background: none; border: none; cursor: pointer;
  font-size: 18px; color: var(--text-muted);
  width: 24px; height: 24px; border-radius: 4px;
  display: flex; align-items: center; justify-content: center;
}
.task-column-menu-btn:hover { background: rgba(0,0,0,0.06); color: var(--text); }
.task-column-menu {
  position: absolute; top: 36px; right: 8px; z-index: 10;
  background: #fff; border: 1px solid var(--border-light);
  border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.12);
  display: flex; flex-direction: column; min-width: 180px;
  overflow: hidden;
}
.task-column-menu button {
  background: none; border: none; padding: 8px 12px;
  text-align: left; cursor: pointer; font-size: 13px;
}
.task-column-menu button:hover { background: var(--bg-secondary, #f5f5f5); }
.task-column-menu button.danger { color: #E53935; }
.task-column-menu button.active { color: var(--bk-orange, #E87A1E); font-weight: 600; }

.task-column-body {
  flex: 1;
  overflow-y: auto;
  padding: 4px 8px;
  display: flex; flex-direction: column; gap: 6px;
  min-height: 60px;
}
.card-slot { position: relative; }
.task-column-empty {
  color: var(--text-muted); font-size: 12px; font-style: italic;
  padding: 16px 4px; text-align: center;
}
.drop-indicator {
  height: 3px; background: #4FC3F7; border-radius: 2px;
  margin: 2px 0;
}

.task-column-footer { padding: 6px 8px 8px; }
.task-column-add-btn {
  width: 100%; background: none; border: none;
  color: var(--text-muted); font-size: 13px;
  padding: 6px 8px; border-radius: 6px; cursor: pointer;
  text-align: left;
}
.task-column-add-btn:hover { background: rgba(0,0,0,0.05); color: var(--text); }
.task-column-add-form { display: flex; flex-direction: column; gap: 6px; }
.task-column-add-form textarea {
  width: 100%; border: 1px solid var(--border); border-radius: 6px;
  padding: 6px 8px; font-size: 13px; font-family: inherit; resize: vertical;
}
.task-column-add-actions { display: flex; gap: 6px; }
.task-column-add-actions .btn { padding: 5px 12px; font-size: 12.5px; }
</style>
