<template>
  <div class="tasks-view">
    <!-- Шапка -->
    <header class="tasks-header">
      <div class="tasks-header-left">
        <h1 class="tasks-title">
          <BkIcon name="clipboard" size="md"/>
          Задачи
        </h1>
        <select v-if="store.boards.length" class="tasks-board-select" :value="store.currentBoardId" @change="changeBoard">
          <optgroup v-for="(group, owner) in groupedBoards" :key="owner" :label="owner === currentUserName ? 'Мои' : owner">
            <option v-for="b in group.active" :key="b.id" :value="b.id">{{ b.title }}</option>
          </optgroup>
          <optgroup v-if="archivedBoards.length" label="📦 Архивные">
            <option v-for="b in archivedBoards" :key="b.id" :value="b.id">{{ b.title }} ({{ b.owner_name }})</option>
          </optgroup>
        </select>
      </div>
      <div class="tasks-header-right">
        <button class="btn icon-btn" @click="openSearch" title="Поиск (Ctrl+K)">
          <span style="font-size:14px;">🔍</span>
        </button>
        <button class="btn icon-btn" :class="{ 'btn-active': store.hasActiveFilters }"
                @click="filtersOpen = !filtersOpen" title="Фильтры">
          <span style="font-size:14px;">⚲</span>
          <span v-if="store.hasActiveFilters" class="filter-dot"></span>
        </button>
        <select :value="store.sortMode" @change="store.sortMode = $event.target.value" class="sort-select" title="Сортировка карточек">
          <option value="manual">Вручную</option>
          <option value="due">По сроку</option>
          <option value="priority">По приоритету</option>
          <option value="created">По дате создания</option>
        </select>
        <button v-if="store.board" class="btn icon-btn" @click="boardMenuOpen = !boardMenuOpen" title="Настройки доски">⚙</button>
        <button class="btn primary" @click="createBoardPrompt">+ Новая доска</button>
      </div>

      <!-- Панель фильтров -->
      <div v-if="filtersOpen" class="filters-panel" v-click-outside-board="() => filtersOpen = false">
        <div class="filters-row">
          <label class="filters-label">Приоритет</label>
          <div class="filters-chips">
            <button v-for="p in PRIO_OPTS" :key="p.value" class="filter-chip"
                    :class="{ active: store.filters.priorities.includes(p.value), ['prio-bg-' + p.value]: store.filters.priorities.includes(p.value) }"
                    @click="togglePrio(p.value)">{{ p.label }}</button>
          </div>
        </div>
        <div class="filters-row">
          <label class="filters-label">Срок</label>
          <div class="filters-chips">
            <button v-for="d in DUE_OPTS" :key="d.value" class="filter-chip"
                    :class="{ active: store.filters.dueState === d.value }"
                    @click="store.filters.dueState = store.filters.dueState === d.value ? '' : d.value">
              {{ d.label }}
            </button>
          </div>
        </div>
        <div v-if="store.labels.length" class="filters-row">
          <label class="filters-label">Метки</label>
          <div class="filters-chips">
            <button v-for="l in store.labels" :key="l.id" class="filter-chip"
                    :class="{ active: store.filters.labels.includes(l.id) }"
                    :style="store.filters.labels.includes(l.id) ? { background: l.color, color: '#fff', borderColor: l.color } : { borderColor: l.color, color: l.color }"
                    @click="toggleLabelFilter(l.id)">{{ l.title }}</button>
          </div>
        </div>
        <div v-if="allAssignees.length" class="filters-row">
          <label class="filters-label">Исполнители</label>
          <div class="filters-chips">
            <button v-for="n in allAssignees" :key="n" class="filter-chip"
                    :class="{ active: store.filters.assignees.includes(n) }"
                    @click="toggleAssigneeFilter(n)">
              <span class="filter-bubble">{{ initials(n) }}</span>{{ n }}
            </button>
          </div>
        </div>
        <div class="filters-row">
          <label class="filters-label">Текст</label>
          <input v-model="store.filters.text" type="text" placeholder="Содержит…" class="filters-text" />
        </div>
        <div class="filters-actions">
          <button v-if="store.hasActiveFilters" class="btn" @click="store.resetFilters()">Сбросить всё</button>
        </div>
      </div>

      <!-- Меню настроек доски -->
      <div v-if="boardMenuOpen && store.board" class="board-menu" v-click-outside-board="() => boardMenuOpen = false">
        <div class="board-menu-title">Настройки доски «{{ store.board.title }}»</div>
        <button v-if="store.canEditStructure" class="board-menu-item" @click="renameBoard">
          <BkIcon name="edit" size="sm"/> Переименовать
        </button>
        <button class="board-menu-item" @click="openLabelsManager">
          <BkIcon name="document" size="sm"/> Метки доски ({{ store.labels.length }})
        </button>
        <button v-if="store.canEditStructure" class="board-menu-item" @click="archiveBoard">
          <BkIcon name="archive" size="sm"/> {{ store.board.is_archived ? 'Вернуть из архива' : 'Архивировать' }}
        </button>
        <button v-if="store.canEditStructure" class="board-menu-item danger" @click="deleteBoard">
          <BkIcon name="delete" size="sm"/> Удалить доску
        </button>
      </div>
    </header>

    <!-- Менеджер меток (модалка) -->
    <Teleport to="body" v-if="labelsManagerOpen">
      <div class="modal" @click.self="labelsManagerOpen = false">
        <div class="modal-box" style="max-width: 420px;">
          <div class="modal-header">
            <h3>Метки доски</h3>
            <button class="modal-close" @click="labelsManagerOpen = false">✕</button>
          </div>
          <div class="labels-mgr">
            <div v-for="l in store.labels" :key="l.id" class="labels-mgr-row">
              <span class="labels-mgr-swatch" :style="{ background: l.color }"></span>
              <input v-model="l.title" type="text" class="labels-mgr-input"
                     @blur="updateLabel(l)" @keydown.enter="$event.target.blur()" />
              <input v-model="l.color" type="color" class="labels-mgr-color" @change="updateLabel(l)" />
              <button class="ts-icon-btn" @click="deleteLabel(l)" title="Удалить">✕</button>
            </div>
            <div v-if="!store.labels.length" class="labels-mgr-empty">Меток пока нет</div>
            <div class="labels-mgr-add">
              <input v-model="newLabel.title" type="text" placeholder="Название новой метки" />
              <input v-model="newLabel.color" type="color" />
              <button class="btn primary" @click="addLabel" :disabled="!newLabel.title.trim()">+</button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Тело -->
    <div v-if="store.loading && !store.board" class="tasks-loading">Загрузка…</div>
    <div v-else-if="store.error" class="tasks-error">{{ store.error }}</div>
    <div v-else-if="!store.board" class="tasks-empty">Создайте первую доску</div>

    <div v-else class="tasks-board-area">
      <!-- Колонки -->
      <div class="tasks-columns" @dragover.prevent>
        <div v-for="(col, i) in store.columns" :key="col.id"
             class="tasks-column-wrap"
             :class="{ 'col-drag-over': colDragOver === i }"
             draggable="true"
             @dragstart.self="onColDragStart(i, $event)"
             @dragover.self.prevent="onColDragOver(i)"
             @drop.self="onColDrop(i)"
             @dragend.self="colDragFrom = null; colDragOver = null">
          <TaskColumn
            :column="col"
            :items="store.cardsByColumn[col.id] || []"
            :labels="store.labels"
            :can-edit-structure="store.canEditStructure"
            :dragged-card="draggedCard"
            @open-card="openCard"
            @open-subtask="onOpenSubtaskFromBoard"
            @subtasks-changed="store.reload"
            @add-card="addCard"
            @move-card="onMoveCard"
            @rename="title => store.updateColumn(col.id, { title })"
            @set-color="color => store.updateColumn(col.id, { color })"
            @toggle-done="store.updateColumn(col.id, { is_done_column: col.is_done_column ? 0 : 1 })"
            @delete="store.deleteColumn(col.id)"
            @card-dragstart="card => draggedCard = card"
            @card-dragend="draggedCard = null"
          />
        </div>
        <div v-if="store.canEditStructure" class="tasks-add-column">
          <button class="tasks-add-column-btn" @click="addColumnPrompt">+ Добавить колонку</button>
        </div>
      </div>
    </div>

    <!-- Сайдбар карточки -->
    <TaskCardModal v-if="openedCardId" :card-id="openedCardId"
                   :can-go-back="cardStack.length > 1"
                   @close="closeCard"
                   @open-card="openSubcard"
                   @go-back="goBackInStack"
                   @deleted="onCardDeleted" />

    <!-- Поиск -->
    <Teleport to="body" v-if="searchOpen">
      <div class="search-modal" @click.self="searchOpen = false">
        <div class="search-box">
          <div class="search-input-wrap">
            <span class="search-icon">🔍</span>
            <input ref="searchInputRef" v-model="searchQuery" type="text" class="search-input"
                   placeholder="Поиск по карточкам всех досок (название, описание)…"
                   @keydown.esc="searchOpen = false"
                   @keydown.down.prevent="searchHover = Math.min(searchHover + 1, searchResults.length - 1)"
                   @keydown.up.prevent="searchHover = Math.max(searchHover - 1, 0)"
                   @keydown.enter="goToSearchResult(searchResults[searchHover])" />
            <span v-if="searchLoading" class="search-loading">…</span>
          </div>
          <div class="search-results" v-if="searchQuery.length >= 2">
            <div v-if="!searchResults.length && !searchLoading" class="search-empty">Ничего не найдено</div>
            <div v-for="(r, i) in searchResults" :key="r.id"
                 class="search-result"
                 :class="{ hover: i === searchHover, 'is-done': r.is_done }"
                 @click="goToSearchResult(r)"
                 @mouseenter="searchHover = i">
              <div class="sr-main">
                <div class="sr-title">
                  {{ r.title }}
                  <span v-if="r.is_done_column || r.is_done" class="sr-tag">✓ готово</span>
                  <span v-if="r.priority === 'urgent'" class="sr-tag urgent">срочно</span>
                  <span v-if="r.priority === 'high'" class="sr-tag high">высокий</span>
                </div>
                <div v-if="r.description" class="sr-desc">{{ r.description }}</div>
                <div class="sr-meta">
                  <span class="sr-board">{{ r.board_title }}</span>
                  <span class="sr-col">· {{ r.column_title }}</span>
                  <span v-if="r.due_date" class="sr-due">· до {{ formatShortDue(r.due_date) }}</span>
                </div>
              </div>
              <div class="sr-arrow">→</div>
            </div>
          </div>
          <div v-else class="search-hint">Введите минимум 2 символа</div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue';
import { useRoute } from 'vue-router';
import { useTasksStore } from '@/stores/tasksStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { tasksApi } from '@/lib/tasksApi.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import TaskColumn from '@/components/tasks/TaskColumn.vue';
import TaskCardModal from '@/components/tasks/TaskCardModal.vue';

const store = useTasksStore();
const userStore = useUserStore();
const route = useRoute();

const cardStack = ref([]); // стек открытых карточек (для подзадач)
const openedCardId = computed(() => cardStack.value[cardStack.value.length - 1] || null);
const draggedCard = ref(null);
const boardMenuOpen = ref(false);
const labelsManagerOpen = ref(false);
const newLabel = ref({ title: '', color: '#42A5F5' });
const filtersOpen = ref(false);
const searchOpen = ref(false);
const searchQuery = ref('');
const searchResults = ref([]);
const searchLoading = ref(false);
const searchHover = ref(0);
const searchInputRef = ref(null);

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

// Drag-and-drop колонок
const colDragFrom = ref(null);
const colDragOver = ref(null);

const currentUserName = computed(() => userStore.currentUser?.name || '');

const groupedBoards = computed(() => {
  const g = {};
  for (const b of store.boards) {
    if (b.is_archived) continue;
    if (b.owner_name === currentUserName.value) (g[b.owner_name] ||= { active: [] }).active.push(b);
  }
  for (const b of store.boards) {
    if (b.is_archived) continue;
    if (b.owner_name !== currentUserName.value) (g[b.owner_name] ||= { active: [] }).active.push(b);
  }
  return g;
});

const archivedBoards = computed(() => store.boards.filter(b => b.is_archived));

// Список всех соисполнителей по карточкам — для фильтра
const allAssignees = computed(() => {
  const set = new Set();
  for (const c of store.cards) {
    if (store.board?.owner_name) set.add(store.board.owner_name);
    for (const n of (c.assignees || [])) set.add(n);
  }
  return [...set].sort();
});

function togglePrio(p) {
  const arr = store.filters.priorities;
  const i = arr.indexOf(p);
  if (i >= 0) arr.splice(i, 1); else arr.push(p);
}
function toggleLabelFilter(id) {
  const arr = store.filters.labels;
  const i = arr.indexOf(id);
  if (i >= 0) arr.splice(i, 1); else arr.push(id);
}
function toggleAssigneeFilter(n) {
  const arr = store.filters.assignees;
  const i = arr.indexOf(n);
  if (i >= 0) arr.splice(i, 1); else arr.push(n);
}
function initials(n) {
  return (n || '').split(/\s+/).filter(Boolean).map(w => w[0]).join('').slice(0, 2).toUpperCase();
}

onMounted(async () => {
  await store.fetchBoards();
  // Открываем первую СВОЮ доску
  const my = store.boards.find(b => b.owner_name === currentUserName.value && !b.is_archived);
  const target = my || store.boards[0];
  if (target) await store.loadBoard(target.id);
  // Если в URL передана карточка — открываем её
  const cardId = parseInt(route.query.cardId);
  if (cardId) cardStack.value = [cardId];
  document.addEventListener('keydown', onHotkey);
});

onUnmounted(() => {
  document.removeEventListener('keydown', onHotkey);
});

function onHotkey(e) {
  // Игнорировать в полях ввода
  const tag = e.target.tagName;
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || e.target.isContentEditable) return;
  if ((e.ctrlKey || e.metaKey) && e.key === 'k') { e.preventDefault(); openSearch(); return; }
  if (e.key === 'n' && !e.ctrlKey && !e.metaKey && !e.altKey) {
    if (store.canEditStructure || store.isOwner) {
      e.preventDefault();
      addCardToFirstColumn();
    }
  }
  if (e.key === 'f' && !e.ctrlKey && !e.metaKey && !e.altKey) {
    e.preventDefault();
    filtersOpen.value = !filtersOpen.value;
  }
}

function openSearch() {
  searchOpen.value = true;
  searchQuery.value = '';
  searchResults.value = [];
  searchHover.value = 0;
  nextTick(() => searchInputRef.value?.focus?.());
}

let searchTimer = null;
watch(searchQuery, (v) => {
  clearTimeout(searchTimer);
  if (!v || v.length < 2) { searchResults.value = []; return; }
  searchTimer = setTimeout(async () => {
    searchLoading.value = true;
    try {
      const r = await tasksApi.search(v);
      searchResults.value = r.results || [];
      searchHover.value = 0;
    } catch (e) { /* noop */ }
    finally { searchLoading.value = false; }
  }, 200);
});

async function goToSearchResult(r) {
  if (!r) return;
  searchOpen.value = false;
  if (store.currentBoardId !== r.board_id) await store.loadBoard(r.board_id);
  // Если это подзадача — открываем со стеком [parent, sub]
  cardStack.value = r.parent_card_id ? [r.parent_card_id, r.id] : [r.id];
}

function formatShortDue(s) {
  if (!s) return '';
  const d = new Date(s);
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
}

async function addCardToFirstColumn() {
  if (!store.columns.length) return;
  const title = prompt('Новая задача:');
  if (!title || !title.trim()) return;
  try { await store.createCard({ board_id: store.currentBoardId, column_id: store.columns[0].id, title: title.trim() }); }
  catch (e) { alert(e.message); }
}

// Открытие карточки при изменении query-параметра (например, при переходе из уведомления)
watch(() => route.query.cardId, (v) => {
  const id = parseInt(v);
  if (id && id !== openedCardId.value) cardStack.value = [id];
});

async function changeBoard(e) {
  const id = parseInt(e.target.value);
  if (id) await store.loadBoard(id);
}

async function createBoardPrompt() {
  const title = prompt('Название новой доски:');
  if (!title || !title.trim()) return;
  try { await store.createBoard({ title: title.trim() }); }
  catch (e) { alert('Ошибка: ' + e.message); }
}

async function renameBoard() {
  boardMenuOpen.value = false;
  if (!store.board) return;
  const t = prompt('Новое название доски:', store.board.title);
  if (!t || t.trim() === store.board.title) return;
  try { await store.updateBoard(store.board.id, { title: t.trim() }); }
  catch (e) { alert('Ошибка: ' + e.message); }
}

function openLabelsManager() {
  boardMenuOpen.value = false;
  labelsManagerOpen.value = true;
}

async function addLabel() {
  if (!newLabel.value.title.trim()) return;
  try {
    await store.createLabel({
      board_id: store.currentBoardId,
      title: newLabel.value.title.trim(),
      color: newLabel.value.color || '#42A5F5',
    });
    newLabel.value = { title: '', color: '#42A5F5' };
  } catch (e) { alert(e.message); }
}
async function updateLabel(l) {
  try { await store.updateLabel(l.id, { title: l.title, color: l.color }); }
  catch (e) { alert(e.message); }
}
async function deleteLabel(l) {
  if (!confirm('Удалить метку «' + l.title + '»?')) return;
  try { await store.deleteLabel(l.id); }
  catch (e) { alert(e.message); }
}

async function archiveBoard() {
  boardMenuOpen.value = false;
  const newState = !store.board.is_archived ? 1 : 0;
  try { await store.updateBoard(store.board.id, { is_archived: newState }); }
  catch (e) { alert('Ошибка: ' + e.message); }
}

async function deleteBoard() {
  boardMenuOpen.value = false;
  if (!confirm('Удалить доску «' + store.board.title + '» со всеми задачами? Действие нельзя отменить.')) return;
  try { await store.deleteBoard(store.board.id); }
  catch (e) { alert('Ошибка: ' + e.message); }
}

// Локальная директива для закрытия меню по клику снаружи
const vClickOutsideBoard = {
  mounted(el, binding) {
    el.__co = (e) => { if (!el.contains(e.target)) binding.value(e); };
    setTimeout(() => document.addEventListener('mousedown', el.__co), 0);
  },
  unmounted(el) { document.removeEventListener('mousedown', el.__co); },
};

async function addColumnPrompt() {
  const title = prompt('Название колонки:');
  if (!title || !title.trim()) return;
  try { await store.createColumn({ board_id: store.currentBoardId, title: title.trim() }); }
  catch (e) { alert('Ошибка: ' + e.message); }
}

function openCard(card) { cardStack.value = [card.id]; }
function openSubcard(cardId) { cardStack.value.push(cardId); }
function onOpenSubtaskFromBoard({ parent, subtask }) {
  // Сразу открываем подзадачу с возможностью «← Назад» к родителю
  cardStack.value = [parent.id, subtask.id];
}
function closeCard() {
  cardStack.value = [];
  // Перезагружаем доску, чтобы счётчики подзадач/чек-листа обновились
  store.reload();
}
function goBackInStack() {
  if (cardStack.value.length > 1) cardStack.value.pop();
  else cardStack.value = [];
}

async function addCard(payload) {
  try { await store.createCard({ board_id: store.currentBoardId, ...payload }); }
  catch (e) { alert('Ошибка: ' + e.message); }
}

async function onMoveCard({ card, to_column_id, to_index }) {
  if (!card) return;
  await store.moveCard(card.id, to_column_id, to_index);
  draggedCard.value = null;
}

function onCardDeleted(id) {
  store.cards = store.cards.filter(c => c.id !== id);
}

// Drag-and-drop колонок (только для редакторов структуры)
function onColDragStart(i, e) {
  if (!store.canEditStructure) { e.preventDefault(); return; }
  // Игнорируем перетаскивание, если оно начинается с карточки
  if (e.target.closest('.task-card')) { e.preventDefault(); return; }
  colDragFrom.value = i;
  e.dataTransfer.effectAllowed = 'move';
}
function onColDragOver(i) {
  if (colDragFrom.value === null) return;
  colDragOver.value = i;
}
function onColDrop(i) {
  if (colDragFrom.value === null || colDragFrom.value === i) {
    colDragFrom.value = null; colDragOver.value = null;
    return;
  }
  const ids = store.columns.map(c => c.id);
  const from = colDragFrom.value;
  const moved = ids.splice(from, 1)[0];
  ids.splice(i, 0, moved);
  store.reorderColumns(ids);
  colDragFrom.value = null;
  colDragOver.value = null;
}
</script>

<style scoped>
.tasks-view {
  display: flex; flex-direction: column;
  height: 100%;
  background: var(--bg, #fafbfc);
}
.tasks-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 22px; gap: 16px;
  background: #fff; border-bottom: 1px solid var(--border-light);
  flex-wrap: wrap;
}
.tasks-header-left { display: flex; align-items: center; gap: 16px; flex: 1; min-width: 0; }
.tasks-title {
  display: flex; align-items: center; gap: 8px;
  font-size: 18px; font-weight: 700; margin: 0;
  color: var(--text, #1f2937);
}
.tasks-board-select {
  padding: 6px 10px; font-size: 13.5px;
  border: 1px solid var(--border-light); border-radius: 6px;
  background: #fff; min-width: 220px; max-width: 320px;
}
.tasks-header-right { display: flex; gap: 8px; }
.tasks-header-right .btn { padding: 6px 14px; font-size: 13px; }

.tasks-loading, .tasks-error, .tasks-empty {
  padding: 40px; text-align: center; color: var(--text-muted);
}
.tasks-error { color: #E53935; }

.tasks-board-area {
  flex: 1; overflow: hidden;
  padding: 16px 0 16px 16px;
}
.tasks-columns {
  display: flex; gap: 12px; align-items: flex-start;
  height: 100%; overflow-x: auto; padding-bottom: 8px;
  padding-right: 16px;
}
.tasks-column-wrap {
  height: 100%;
  display: flex;
  border: 2px solid transparent;
  border-radius: 12px;
  transition: border-color .15s;
}
.tasks-column-wrap.col-drag-over { border-color: #FFA726; }

.tasks-add-column {
  width: 280px; flex: 0 0 280px;
}
.tasks-add-column-btn {
  width: 100%; padding: 10px;
  background: rgba(0,0,0,0.04); border: 2px dashed var(--border-light);
  border-radius: 10px; cursor: pointer; color: var(--text-muted);
  font-size: 13px; font-weight: 500;
}
.tasks-add-column-btn:hover { background: rgba(0,0,0,0.07); color: var(--text); }

/* ═══ Меню настроек доски ═══ */
.tasks-header { position: relative; }
.icon-btn {
  width: 36px; padding: 6px; display: flex; align-items: center; justify-content: center;
  font-size: 16px;
}
.board-menu {
  position: absolute; top: 56px; right: 22px; z-index: 100;
  background: #fff; border: 1px solid var(--border-light);
  border-radius: 10px; box-shadow: 0 6px 24px rgba(0,0,0,0.15);
  min-width: 240px; padding: 6px;
}
.board-menu-title {
  padding: 8px 12px 6px; font-size: 11px; font-weight: 700;
  color: var(--text-muted); text-transform: uppercase; letter-spacing: .5px;
  border-bottom: 1px solid var(--border-light); margin-bottom: 4px;
}
.board-menu-item {
  display: flex; align-items: center; gap: 10px; width: 100%;
  padding: 8px 12px; border: none; background: none; cursor: pointer;
  font-size: 13.5px; color: var(--text); border-radius: 6px;
  font-family: inherit; text-align: left;
}
.board-menu-item:hover { background: var(--bg-secondary, #f5f5f5); }
.board-menu-item.danger { color: #E53935; }
.board-menu-item.danger:hover { background: rgba(229,57,53,0.08); }

/* ═══ Менеджер меток ═══ */
:deep(.modal) {
  position: fixed; inset: 0; background: rgba(0,0,0,0.45);
  display: flex; align-items: center; justify-content: center;
  z-index: 1000; padding: 20px;
}
:deep(.modal-box) {
  background: #fff; border-radius: 12px;
  width: 100%; padding: 16px;
}
:deep(.modal-header) {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 12px;
}
:deep(.modal-header h3) { margin: 0; font-size: 16px; }
:deep(.modal-close) {
  background: none; border: none; cursor: pointer;
  font-size: 18px; color: var(--text-muted); padding: 4px 8px;
}
.labels-mgr { display: flex; flex-direction: column; gap: 6px; }
.labels-mgr-row {
  display: flex; align-items: center; gap: 8px;
  padding: 4px 6px; border-radius: 6px;
}
.labels-mgr-row:hover { background: var(--bg-secondary, #f5f5f5); }
.labels-mgr-swatch {
  width: 16px; height: 16px; border-radius: 50%;
  flex-shrink: 0; border: 1px solid rgba(0,0,0,0.1);
}
.labels-mgr-input {
  flex: 1; padding: 5px 8px; font-size: 13px;
  border: 1px solid var(--border-light); border-radius: 4px;
  background: #fff; color: var(--text); font-family: inherit;
}
.labels-mgr-color {
  width: 32px; height: 28px; padding: 0; cursor: pointer;
  border: 1px solid var(--border-light); border-radius: 4px;
}
.labels-mgr-empty {
  text-align: center; padding: 12px; color: var(--text-muted);
  font-size: 13px; font-style: italic;
}
.labels-mgr-add {
  display: flex; gap: 6px; align-items: center;
  margin-top: 8px; padding-top: 12px; border-top: 1px solid var(--border-light);
}
.labels-mgr-add input[type="text"] {
  flex: 1; padding: 5px 8px; font-size: 13px;
  border: 1px solid var(--border-light); border-radius: 4px;
  background: #fff; color: var(--text); font-family: inherit;
}
.labels-mgr-add input[type="color"] {
  width: 32px; height: 28px; padding: 0; cursor: pointer;
  border: 1px solid var(--border-light); border-radius: 4px;
}
.labels-mgr-add .btn { padding: 4px 12px; }
.ts-icon-btn {
  background: none; border: none; cursor: pointer;
  color: var(--text-muted); font-size: 12px; padding: 3px 7px; border-radius: 3px;
}
.ts-icon-btn:hover { color: #E53935; background: rgba(229,57,53,0.1); }

/* ═══ Кнопки в шапке + сортировка ═══ */
.btn-active {
  background: rgba(232,122,30,0.15) !important;
  border-color: var(--bk-orange, #E87A1E) !important;
  color: var(--bk-orange, #E87A1E) !important;
  position: relative;
}
.filter-dot {
  position: absolute; top: 4px; right: 4px;
  width: 6px; height: 6px; border-radius: 50%;
  background: var(--bk-orange, #E87A1E);
}
.sort-select {
  padding: 6px 10px; font-size: 12.5px;
  border: 1px solid var(--border-light); border-radius: 6px;
  background: #fff; color: var(--text); font-family: inherit;
  height: 32px;
}

/* ═══ Панель фильтров ═══ */
.filters-panel {
  position: absolute; top: 56px; right: 22px; z-index: 90;
  background: #fff; border: 1px solid var(--border-light);
  border-radius: 10px; box-shadow: 0 6px 24px rgba(0,0,0,0.15);
  padding: 12px 14px;
  min-width: 360px; max-width: 480px;
  display: flex; flex-direction: column; gap: 10px;
}
.filters-row {
  display: flex; align-items: flex-start; gap: 10px;
}
.filters-label {
  font-size: 11px; font-weight: 700; color: var(--text-muted);
  text-transform: uppercase; letter-spacing: .4px;
  min-width: 80px; padding-top: 5px; flex-shrink: 0;
}
.filters-chips {
  display: flex; flex-wrap: wrap; gap: 4px;
  flex: 1;
}
.filter-chip {
  padding: 3px 10px; border-radius: 12px; font-size: 11.5px; font-weight: 600;
  cursor: pointer; border: 1.5px solid var(--border-light); background: #fff;
  color: var(--text); font-family: inherit;
  display: inline-flex; align-items: center; gap: 4px;
}
.filter-chip:hover { border-color: var(--bk-orange, #E87A1E); }
.filter-chip.active { background: rgba(232,122,30,0.1); border-color: var(--bk-orange); color: var(--bk-orange); }
.filter-chip.prio-bg-low    { background: #ECEFF1 !important; border-color: #ECEFF1 !important; color: #455A64 !important; }
.filter-chip.prio-bg-medium { background: #E1F5FE !important; border-color: #E1F5FE !important; color: #0277BD !important; }
.filter-chip.prio-bg-high   { background: #FFF3E0 !important; border-color: #FFF3E0 !important; color: #E65100 !important; }
.filter-chip.prio-bg-urgent { background: #FFEBEE !important; border-color: #FFEBEE !important; color: #C62828 !important; }
.filter-bubble {
  display: inline-flex; align-items: center; justify-content: center;
  width: 18px; height: 18px; border-radius: 50%;
  background: linear-gradient(135deg, #E76F51, #F4A261);
  color: #fff; font-size: 9px; font-weight: 700;
}
.filters-text {
  flex: 1; padding: 6px 10px; font-size: 13px;
  border: 1px solid var(--border-light); border-radius: 6px;
  background: #fff; color: var(--text); font-family: inherit;
}
.filters-actions {
  display: flex; justify-content: flex-end; padding-top: 4px; border-top: 1px solid var(--border-light);
}

/* ═══ Поиск ═══ */
.search-modal {
  position: fixed; inset: 0; background: rgba(0,0,0,0.4);
  display: flex; align-items: flex-start; justify-content: center;
  z-index: 1100; padding: 80px 20px 20px;
}
.search-box {
  background: #fff; border-radius: 12px;
  width: 100%; max-width: 640px;
  box-shadow: 0 12px 36px rgba(0,0,0,0.25);
  overflow: hidden;
  max-height: calc(100vh - 100px);
  display: flex; flex-direction: column;
}
.search-input-wrap {
  display: flex; align-items: center; gap: 8px;
  padding: 14px 16px;
  border-bottom: 1px solid var(--border-light);
}
.search-icon { font-size: 18px; }
.search-input {
  flex: 1; border: none; outline: none;
  font-size: 16px; background: none; color: var(--text);
  font-family: inherit;
}
.search-loading { color: var(--text-muted); font-size: 14px; }
.search-results { overflow-y: auto; }
.search-empty, .search-hint {
  padding: 24px; text-align: center; color: var(--text-muted); font-size: 13px;
}
.search-result {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 16px;
  cursor: pointer;
  border-bottom: 1px solid var(--border-light);
  transition: background .12s;
}
.search-result:last-child { border-bottom: none; }
.search-result.hover, .search-result:hover { background: var(--bg-secondary, #f5f5f5); }
.search-result.is-done { opacity: 0.6; }
.sr-main { flex: 1; min-width: 0; }
.sr-title {
  font-size: 14px; font-weight: 600; color: var(--text);
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
}
.sr-tag {
  font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 8px;
  background: rgba(0,0,0,0.06); color: var(--text-muted);
}
.sr-tag.urgent { background: #FFEBEE; color: #C62828; }
.sr-tag.high { background: #FFF3E0; color: #E65100; }
.sr-desc {
  font-size: 12.5px; color: var(--text-muted);
  margin-top: 4px; line-height: 1.3;
  overflow: hidden; text-overflow: ellipsis;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
}
.sr-meta { font-size: 11.5px; color: var(--text-muted); margin-top: 4px; }
.sr-board { font-weight: 600; color: var(--bk-orange, #E87A1E); }
.sr-due { font-weight: 600; color: #2E7D32; }
.sr-arrow { color: var(--text-muted); font-size: 18px; }
</style>
