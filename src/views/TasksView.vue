<template>
  <div class="tasks-view">
    <!-- Шапка -->
    <header class="tasks-header">
      <div class="tasks-header-left">
        <h1 class="tasks-title">
          <BkIcon name="clipboard" size="md"/>
          Задачи
        </h1>
        <div class="tasks-header-divider"></div>
        <div v-if="store.boards.length" class="tasks-board-picker">
          <select class="tasks-board-select" :value="store.currentBoardId" @change="changeBoard">
            <optgroup v-for="(group, owner) in groupedBoards" :key="owner" :label="owner === currentUserName ? 'Мои' : owner">
              <option v-for="b in group.active" :key="b.id" :value="b.id">{{ b.title }}</option>
            </optgroup>
            <optgroup v-if="archivedBoards.length" label="Архивные">
              <option v-for="b in archivedBoards" :key="b.id" :value="b.id">{{ b.title }} ({{ b.owner_name }})</option>
            </optgroup>
          </select>
          <TaskIcon name="chevronDown" :size="14" class="tasks-board-select-chev"/>
        </div>
      </div>
      <div class="tasks-header-right">
        <div class="view-toggle" role="group" aria-label="Режим отображения">
          <button class="view-btn" :class="{ active: viewMode === 'kanban' }" @click="setViewMode('kanban')" title="Канбан">
            <TaskIcon name="columns" :size="14"/>
            <span>Канбан</span>
          </button>
          <button class="view-btn" :class="{ active: viewMode === 'calendar' }" @click="setViewMode('calendar')" title="Календарь">
            <TaskIcon name="calendar" :size="14"/>
            <span>Календарь</span>
          </button>
        </div>
        <button class="btn search-btn" @click="openSearch" title="Поиск по карточкам">
          <TaskIcon name="search" :size="14"/>
          <span class="search-btn-label">Поиск</span>
          <kbd class="search-btn-kbd">{{ shortcutSymbol }}K</kbd>
        </button>
        <div class="sort-wrap" v-if="viewMode === 'kanban'">
          <select :value="store.sortMode" @change="store.sortMode = $event.target.value" class="sort-select" title="Сортировка карточек">
            <option value="manual">Вручную</option>
            <option value="due">По сроку</option>
            <option value="priority">По приоритету</option>
            <option value="created">По дате создания</option>
          </select>
          <TaskIcon name="chevronDown" :size="12" class="sort-select-chev"/>
        </div>
        <button v-if="store.board" class="btn icon-btn" @click="boardMenuOpen = !boardMenuOpen" title="Настройки доски">
          <TaskIcon name="gear" :size="16"/>
        </button>
        <div class="tasks-header-divider"></div>
        <button class="btn primary tasks-new-board-btn" @click="createBoardPrompt">
          <TaskIcon name="plus" :size="14"/>
          <span>Новая доска</span>
        </button>
      </div>

      <!-- Меню настроек доски -->
      <div v-if="boardMenuOpen && store.board" class="board-menu" v-click-outside-board="() => boardMenuOpen = false">
        <div class="board-menu-title">Настройки доски «{{ store.board.title }}»</div>
        <button v-if="store.canEditStructure" class="board-menu-item" @click="renameBoard">
          <TaskIcon name="edit" :size="16"/> Переименовать
        </button>
        <button class="board-menu-item" @click="openLabelsManager">
          <TaskIcon name="tag" :size="16"/> Метки доски ({{ store.labels.length }})
        </button>
        <button v-if="store.canEditStructure" class="board-menu-item" @click="archiveBoard">
          <TaskIcon name="archive" :size="16"/> {{ store.board.is_archived ? 'Вернуть из архива' : 'Архивировать' }}
        </button>
        <button v-if="store.canEditStructure" class="board-menu-item danger" @click="deleteBoard">
          <TaskIcon name="trash" :size="16"/> Удалить доску
        </button>
      </div>
    </header>

    <!-- Менеджер меток (модалка) -->
    <Teleport to="body" v-if="labelsManagerOpen">
      <div class="modal" @click.self="closeLabelsManager">
        <div class="modal-box labels-mgr-box">
          <div class="modal-header">
            <h3>Метки доски</h3>
            <button class="modal-close" @click="closeLabelsManager">
              <TaskIcon name="close" :size="16"/>
            </button>
          </div>

          <p class="labels-mgr-help">Метки помогают группировать карточки по теме или подразделению.</p>

          <!-- Список существующих меток -->
          <div class="labels-mgr-list">
            <div v-for="l in store.labels" :key="l.id" class="labels-mgr-card"
                 :class="{ open: editingLabelId === l.id }">
              <div class="labels-mgr-card-row">
                <span class="labels-mgr-swatch" :style="{ background: l.color }"></span>
                <span class="labels-mgr-pill" :style="{ background: l.color }">{{ l.title || '—' }}</span>
                <input v-model="l.title" type="text" class="labels-mgr-input"
                       placeholder="Название метки"
                       @blur="updateLabel(l)" @keydown.enter="$event.target.blur()" />
                <button class="labels-mgr-edit-btn" :class="{ active: editingLabelId === l.id }"
                        @click="toggleEdit(l)" title="Сменить цвет">
                  <TaskIcon name="edit" :size="14"/>
                </button>
                <button class="ts-icon-btn danger" @click="deleteLabel(l)" title="Удалить">
                  <TaskIcon name="trash" :size="14"/>
                </button>
              </div>
              <div v-if="editingLabelId === l.id" class="labels-mgr-palette-wrap">
                <ColorPalette :model-value="l.color" @update:modelValue="onLabelColorPick(l, $event)"/>
              </div>
            </div>
            <div v-if="!store.labels.length" class="labels-mgr-empty">Меток пока нет — добавьте первую ниже</div>
          </div>

          <!-- Форма добавления -->
          <div class="labels-mgr-add">
            <div class="labels-mgr-add-title">Добавить метку</div>
            <div class="labels-mgr-add-row">
              <span class="labels-mgr-pill" :style="{ background: newLabel.color }">
                {{ newLabel.title.trim() || 'Превью' }}
              </span>
              <input v-model="newLabel.title" type="text" placeholder="Название новой метки"
                     class="labels-mgr-input flex-grow"
                     @keydown.enter="addLabel" />
              <button class="btn primary" @click="addLabel" :disabled="!newLabel.title.trim()">
                <TaskIcon name="plus" :size="14"/> Добавить
              </button>
            </div>
            <div class="labels-mgr-add-palette">
              <div class="labels-mgr-palette-label">Цвет:</div>
              <ColorPalette v-model="newLabel.color"/>
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
      <!-- Колонки (канбан) -->
      <div v-if="viewMode === 'kanban'" class="tasks-columns"
           :class="{ 'is-panning': panActive }"
           @dragover.prevent
           @mousedown="onBoardPanStart">
        <div v-for="(col, i) in store.columns" :key="col.id"
             class="tasks-column-wrap"
             :class="{ 'col-drag-over': colDragOver === i }"
             draggable="true"
             @dragstart="onColDragStart(i, $event)"
             @dragover.prevent="onColDragOver(i)"
             @drop="onColDrop(i)"
             @dragend="colDragFrom = null; colDragOver = null">
          <TaskColumn
            :column="col"
            :items="store.cardsByColumn[col.id] || []"
            :all-items="cardsByColumnAll[col.id] || []"
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
            @set-wip-limit="limit => store.updateColumn(col.id, { wip_limit: limit })"
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

      <!-- Календарь -->
      <TaskCalendar v-else
                    :cards="store.cards"
                    @open-card="openCard"
                    @update-due="onCalendarUpdateDue"/>
    </div>

    <!-- Сайдбар карточки -->
    <TaskCardModal v-if="openedCardId" :card-id="openedCardId"
                   :can-go-back="cardStack.length > 1"
                   @close="closeCard"
                   @open-card="openSubcard"
                   @go-back="goBackInStack"
                   @deleted="onCardDeleted"
                   @refresh="store.loadBoard(store.currentBoardId)" />

    <!-- Глобальные диалоги модуля (один раз на странице) -->
    <ConfirmModal v-if="dlg.confirmModal.value.show"
                  :title="dlg.confirmModal.value.title"
                  :message="dlg.confirmModal.value.message"
                  :ok-text="dlg.confirmModal.value.okText"
                  :cancel-text="dlg.confirmModal.value.cancelText"
                  :danger="dlg.confirmModal.value.danger"
                  @confirm="dlg.onConfirm" @cancel="dlg.onCancel"/>
    <InfoModal v-if="dlg.infoModal.value.show"
               :title="dlg.infoModal.value.title"
               :message="dlg.infoModal.value.message"
               :type="dlg.infoModal.value.type"
               @close="dlg.onInfoClose"/>
    <PromptModal v-if="dlg.promptModal.value.show"
                 :title="dlg.promptModal.value.title"
                 :message="dlg.promptModal.value.message"
                 :value="dlg.promptModal.value.value"
                 :placeholder="dlg.promptModal.value.placeholder"
                 :ok-text="dlg.promptModal.value.okText"
                 :cancel-text="dlg.promptModal.value.cancelText"
                 @ok="dlg.onPromptOk" @cancel="dlg.onPromptCancel"/>

    <!-- Поиск -->
    <Teleport to="body">
      <div v-if="searchOpen" class="search-modal" @click.self="searchOpen = false">
        <div class="search-box" @click.stop>
        <div class="search-input-wrap">
          <TaskIcon name="search" :size="20" class="search-icon-svg"/>
          <input ref="searchInputRef" v-model="searchQuery" type="text" class="search-input"
                 placeholder="Поиск по карточкам всех досок (название, описание)…"
                 @keydown.esc="searchOpen = false"
                 @keydown.down.prevent="searchHover = Math.min(searchHover + 1, searchResults.length - 1)"
                 @keydown.up.prevent="searchHover = Math.max(searchHover - 1, 0)"
                 @keydown.enter="goToSearchResult(searchResults[searchHover])" />
          <span v-if="searchLoading" class="search-loading">…</span>
          <span v-else-if="searchQuery.length >= 2" class="search-count">{{ searchResults.length }}</span>
        </div>
        <div v-if="searchError" class="search-empty" style="color: var(--tk-text-muted);">{{ searchError }}</div>
        <div v-else-if="searchQuery.length < 2" class="search-hint">Введите минимум 2 символа</div>
        <div v-else-if="!searchResults.length && !searchLoading" class="search-empty">Ничего не найдено</div>
        <div v-else class="search-results">
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
            <div class="sr-arrow"><TaskIcon name="arrowRight" :size="16"/></div>
          </div>
        </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick, defineAsyncComponent } from 'vue';
import { useRoute } from 'vue-router';
import { useTasksStore } from '@/stores/tasksStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { tasksApi } from '@/lib/tasksApi.js';
import { useTasksDialogs } from '@/composables/useTasksDialogs.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import TaskColumn from '@/components/tasks/TaskColumn.vue';
import TaskCalendar from '@/components/tasks/TaskCalendar.vue';
import TaskCardModal from '@/components/tasks/TaskCardModal.vue';
import TaskIcon from '@/components/tasks/TaskIcon.vue';
import ColorPalette from '@/components/tasks/ColorPalette.vue';

const ConfirmModal = defineAsyncComponent(() => import('@/components/modals/ConfirmModal.vue'));
const InfoModal    = defineAsyncComponent(() => import('@/components/modals/InfoModal.vue'));
const PromptModal  = defineAsyncComponent(() => import('@/components/modals/PromptModal.vue'));

const dlg = useTasksDialogs();

const store = useTasksStore();
const userStore = useUserStore();
const route = useRoute();

const cardStack = ref([]); // стек открытых карточек (для подзадач)
const openedCardId = computed(() => cardStack.value[cardStack.value.length - 1] || null);
const draggedCard = ref(null);
const boardMenuOpen = ref(false);
const labelsManagerOpen = ref(false);
const editingLabelId = ref(null);
const newLabel = ref({ title: '', color: '#42A5F5' });
const searchOpen = ref(false);
const searchQuery = ref('');
const searchResults = ref([]);
const searchLoading = ref(false);
const searchHover = ref(0);
const searchInputRef = ref(null);

// Drag-and-drop колонок
const colDragFrom = ref(null);
const colDragOver = ref(null);

// «Хват» доски — горизонтальная панорама без скроллбара. Срабатывает на:
// 1) средней кнопке мыши в любом месте полотна
// 2) левой кнопке в пустой области полотна (между/под колонками)
// 3) Shift + левая кнопка где угодно
const panActive = ref(false);
function onBoardPanStart(e) {
  const middleBtn = e.button === 1;
  const leftBtn = e.button === 0;
  const shift = e.shiftKey;
  // Левый клик: только если попали прямо в контейнер (пустое место)
  // или удерживается Shift.
  if (leftBtn && !shift && e.target !== e.currentTarget) return;
  if (!middleBtn && !leftBtn) return;
  e.preventDefault();
  const el = e.currentTarget;
  const startX = e.clientX;
  const startY = e.clientY;
  const startScrollLeft = el.scrollLeft;
  const startScrollTop = el.scrollTop;
  panActive.value = true;
  const onMove = (ev) => {
    el.scrollLeft = startScrollLeft - (ev.clientX - startX);
    el.scrollTop  = startScrollTop  - (ev.clientY - startY);
  };
  const onUp = () => {
    panActive.value = false;
    window.removeEventListener('mousemove', onMove);
    window.removeEventListener('mouseup', onUp);
  };
  window.addEventListener('mousemove', onMove);
  window.addEventListener('mouseup', onUp);
}

// Режим отображения доски (канбан / календарь). Запоминается в localStorage
// отдельно для каждой доски — у разных досок разные привычки просмотра.
const viewMode = ref('kanban');
function viewModeKey() { return 'bk_tasks_view_mode_' + (store.currentBoardId || 'default'); }
function setViewMode(mode) {
  viewMode.value = mode;
  try { localStorage.setItem(viewModeKey(), mode); } catch (_) {}
}
watch(() => store.currentBoardId, (id) => {
  if (!id) return;
  try {
    const v = localStorage.getItem(viewModeKey());
    viewMode.value = (v === 'calendar') ? 'calendar' : 'kanban';
  } catch (_) { viewMode.value = 'kanban'; }
}, { immediate: true });

async function onCalendarUpdateDue(cardId, newIso) {
  try {
    await store.updateCard(cardId, { due_date: newIso });
  } catch (e) {
    dlg.info('Не удалось перенести задачу', e?.message || String(e), 'error');
  }
}

const currentUserName = computed(() => userStore.currentUser?.name || '');
const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);
const shortcutSymbol = isMac ? '⌘' : 'Ctrl ';

// Все карточки по колонкам без применения фильтров — для счётчика и списка исполнителей в поповере фильтра
const cardsByColumnAll = computed(() => {
  const map = {};
  for (const col of store.columns) map[col.id] = [];
  for (const c of store.cards) {
    if (!map[c.column_id]) map[c.column_id] = [];
    map[c.column_id].push(c);
  }
  return map;
});

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
}

function openSearch() {
  searchOpen.value = true;
  searchQuery.value = '';
  searchResults.value = [];
  searchHover.value = 0;
  nextTick(() => searchInputRef.value?.focus?.());
}

const searchError = ref('');
let searchTimer = null;
watch(searchQuery, (v) => {
  clearTimeout(searchTimer);
  searchError.value = '';
  if (!v || v.length < 2) { searchResults.value = []; return; }
  searchTimer = setTimeout(async () => {
    searchLoading.value = true;
    try {
      const r = await tasksApi.search(v);
      searchResults.value = r.results || [];
      searchHover.value = 0;
    } catch (e) {
      searchResults.value = [];
      searchError.value = e?.message || 'Ошибка поиска';
    }
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
  const title = await dlg.prompt('Новая задача', { placeholder: 'Название задачи', okText: 'Создать' });
  if (!title) return;
  try { await store.createCard({ board_id: store.currentBoardId, column_id: store.columns[0].id, title }); }
  catch (e) { dlg.info('Ошибка', e.message, 'error'); }
}

async function showError(title, e) { dlg.info(title, e?.message || String(e), 'error'); }

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
  const title = await dlg.prompt('Новая доска', { placeholder: 'Название доски', okText: 'Создать' });
  if (!title) return;
  try { await store.createBoard({ title }); }
  catch (e) { showError('Ошибка', e); }
}

async function renameBoard() {
  boardMenuOpen.value = false;
  if (!store.board) return;
  const t = await dlg.prompt('Переименовать доску', { defaultValue: store.board.title, placeholder: 'Название доски' });
  if (!t || t === store.board.title) return;
  try { await store.updateBoard(store.board.id, { title: t }); }
  catch (e) { showError('Ошибка', e); }
}

function openLabelsManager() {
  boardMenuOpen.value = false;
  labelsManagerOpen.value = true;
}
function closeLabelsManager() {
  labelsManagerOpen.value = false;
  editingLabelId.value = null;
}
function toggleEdit(l) {
  editingLabelId.value = (editingLabelId.value === l.id) ? null : l.id;
}
async function onLabelColorPick(l, color) {
  l.color = color;
  editingLabelId.value = null;
  try { await store.updateLabel(l.id, { title: l.title, color }); }
  catch (e) { showError('Ошибка', e); }
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
  } catch (e) { showError('Ошибка', e); }
}
async function updateLabel(l) {
  try { await store.updateLabel(l.id, { title: l.title, color: l.color }); }
  catch (e) { showError('Ошибка', e); }
}
async function deleteLabel(l) {
  const ok = await dlg.confirm('Удалить метку', 'Удалить метку «' + l.title + '»?', { okText: 'Удалить', danger: true });
  if (!ok) return;
  try { await store.deleteLabel(l.id); }
  catch (e) { showError('Ошибка', e); }
}

async function archiveBoard() {
  boardMenuOpen.value = false;
  const newState = !store.board.is_archived ? 1 : 0;
  try { await store.updateBoard(store.board.id, { is_archived: newState }); }
  catch (e) { showError('Ошибка', e); }
}

async function deleteBoard() {
  boardMenuOpen.value = false;
  const ok = await dlg.confirm('Удалить доску',
    'Удалить доску «' + store.board.title + '» со всеми задачами? Действие нельзя отменить.',
    { okText: 'Удалить', danger: true });
  if (!ok) return;
  try { await store.deleteBoard(store.board.id); }
  catch (e) { showError('Ошибка', e); }
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
  const title = await dlg.prompt('Новая колонка', { placeholder: 'Название колонки', okText: 'Создать' });
  if (!title) return;
  try { await store.createColumn({ board_id: store.currentBoardId, title }); }
  catch (e) { showError('Ошибка', e); }
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
  catch (e) { showError('Ошибка', e); }
}

async function onMoveCard({ card, to_column_id, to_index }) {
  if (!card) return;
  await store.moveCard(card.id, to_column_id, to_index);
  draggedCard.value = null;
}

function onCardDeleted(id) {
  store.cards = store.cards.filter(c => c.id !== id);
}

// Drag-and-drop колонок (только для редакторов структуры; архив-колонку не двигаем)
function onColDragStart(i, e) {
  if (!store.canEditStructure) { e.preventDefault(); return; }
  if (store.columns[i]?.is_archive_column) { e.preventDefault(); return; }
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
/* ═══ Дизайн-токены модуля «Задачи» ═══
   Бренд: оранжевый #E87A1E. Стиль: Trello/Jira — нейтральная серая база, цветной акцент только в активных состояниях.
   Дочерние компоненты (TaskColumn, TaskCard, TaskCardModal) наследуют эти переменные. */
.tasks-view {
  /* Радиусы — добавлен r-card специально для крупных карточек/колонок */
  --tk-r-sm: 4px;
  --tk-r-md: 10px;
  --tk-r-lg: 14px;
  --tk-r-card: 12px;
  --tk-r-pill: 999px;

  /* Отступы — ступени по 4 */
  --tk-s-1: 4px;
  --tk-s-2: 8px;
  --tk-s-3: 12px;
  --tk-s-4: 16px;
  --tk-s-5: 20px;
  --tk-s-6: 24px;

  /* Тени — мягче и тоньше (Yougile/Linear) */
  --tk-shadow-card: 0 1px 2px rgba(15,23,42,0.05), 0 1px 4px rgba(15,23,42,0.04);
  --tk-shadow-card-hover: 0 4px 12px rgba(15,23,42,0.10), 0 1px 3px rgba(15,23,42,0.06);
  --tk-shadow-column: 0 1px 2px rgba(15,23,42,0.04);
  --tk-shadow-popover: 0 12px 32px rgba(15,23,42,0.14), 0 2px 4px rgba(15,23,42,0.06);

  /* Типографика */
  --tk-fz-xs: 11px;
  --tk-fz-sm: 12px;
  --tk-fz-md: 13px;
  --tk-fz-lg: 14px;
  --tk-fz-xl: 16px;
  --tk-fz-h1: 18px;
  --tk-fw-medium: 500;
  --tk-fw-semibold: 600;
  --tk-fw-bold: 700;

  /* Палитра — тёплая нейтральная шкала, согласуется с бежевым фоном */
  --tk-n-0: #FFFFFF;
  --tk-n-50: #FAF9F5;
  --tk-n-100: #F3F0E8;
  --tk-n-200: #E6E1D7;
  --tk-n-300: #C8C1B2;
  --tk-n-400: #9C9384;
  --tk-n-500: #6E6657;
  --tk-n-600: #534D40;
  --tk-n-700: #3D382E;
  --tk-n-800: #2A2620;
  --tk-n-900: #1A1814;

  /* Поверхности: тёплая бежевая база доски привязывает модуль к бренду
     (оранжевый), колонки — чистый белый с очень тонкой тёплой границей.
     Карточки тоже белые, но за счёт плотной тени отделяются. */
  --tk-bg-board: #F6F4EF;
  --tk-bg-column: #FFFFFF;
  --tk-bg-card: #FFFFFF;
  --tk-bg-popover: #FFFFFF;
  --tk-border: #E6E1D7;
  --tk-border-soft: #EFEAE0;

  /* Акцент бренда (оранжевый, сохраняем) */
  --tk-accent: #E87A1E;
  --tk-accent-hover: #D26B12;
  --tk-accent-soft: rgba(232,122,30,0.10);
  --tk-accent-soft-strong: rgba(232,122,30,0.18);
  --tk-accent-text: #B85A0E;

  /* Доп. акцент — для разнообразия в карточках/чипах */
  --tk-violet: #635BFF;
  --tk-violet-soft: rgba(99,91,255,0.12);

  /* Приоритеты — пастельные плашки */
  --tk-prio-urgent-bg: #FEE7E0;
  --tk-prio-urgent-fg: #B23B16;
  --tk-prio-high-bg: #FFF1D6;
  --tk-prio-high-fg: #8B5E00;
  --tk-prio-medium-bg: #E4ECFE;
  --tk-prio-medium-fg: #2C4DB0;
  --tk-prio-low-bg: #EEF0F4;
  --tk-prio-low-fg: #525B6F;

  /* Семантика */
  --tk-success: #16A364;
  --tk-success-soft: rgba(22,163,100,0.12);
  --tk-warning: #BB6A0A;
  --tk-warning-soft: rgba(187,106,10,0.12);
  --tk-danger: #D33A2C;
  --tk-danger-soft: rgba(211,58,44,0.10);

  /* Текст */
  --tk-text: var(--tk-n-900);
  --tk-text-secondary: var(--tk-n-700);
  --tk-text-muted: var(--tk-n-500);

  --tk-transition: 140ms ease;
  --tk-focus-ring: 0 0 0 3px rgba(232,122,30,0.25);

  /* ═══ собственно стили страницы ═══ */
  display: flex; flex-direction: column;
  height: 100%;
  background: var(--tk-bg-board);
  color: var(--tk-text);
}
.tasks-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 6px var(--tk-s-4);
  gap: var(--tk-s-3);
  background: var(--tk-n-0);
  border-bottom: 1px solid var(--tk-border);
  flex-wrap: wrap;
  min-height: 44px;
}
.tasks-header-left { display: flex; align-items: center; gap: var(--tk-s-3); flex: 1; min-width: 0; }
.tasks-title {
  display: flex; align-items: center; gap: var(--tk-s-2);
  font-size: var(--tk-fz-h1); font-weight: var(--tk-fw-bold); margin: 0;
  color: var(--tk-text); letter-spacing: -0.2px;
  white-space: nowrap;
}

/* Разделитель между группами в шапке */
.tasks-header-divider {
  width: 1px; height: 24px;
  background: var(--tk-border);
  flex-shrink: 0;
}

/* Селект досок — Trello-стиль (плоский, с chevron) */
.tasks-board-picker { position: relative; display: inline-flex; align-items: center; min-width: 0; }
.tasks-board-select {
  appearance: none; -webkit-appearance: none; -moz-appearance: none;
  padding: 0 var(--tk-s-5) 0 var(--tk-s-3);
  height: 36px;
  font-size: var(--tk-fz-lg);
  border: 1px solid transparent;
  border-radius: var(--tk-r-md);
  background: transparent;
  color: var(--tk-text);
  min-width: 180px; max-width: 320px;
  font-weight: var(--tk-fw-semibold);
  font-family: inherit;
  cursor: pointer;
  transition: background var(--tk-transition), border-color var(--tk-transition), box-shadow var(--tk-transition);
}
.tasks-board-select:hover { background: var(--tk-n-100); }
.tasks-board-select:focus { outline: none; background: var(--tk-n-100); border-color: var(--tk-accent); box-shadow: var(--tk-focus-ring); }
.tasks-board-select-chev {
  position: absolute; right: var(--tk-s-2); top: 50%;
  transform: translateY(-50%);
  pointer-events: none;
  color: var(--tk-text-muted);
}

.tasks-header-right { display: flex; gap: var(--tk-s-2); align-items: center; flex-wrap: wrap; }
.tasks-header-right .btn { padding: 0 var(--tk-s-3); height: 32px; font-size: var(--tk-fz-md); }
.tasks-new-board-btn {
  display: inline-flex; align-items: center; gap: 6px;
  font-weight: var(--tk-fw-semibold);
}

/* Переключатель режима «Канбан / Календарь» */
.view-toggle {
  display: inline-flex; align-items: center;
  background: var(--tk-n-50);
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-sm);
  padding: 2px;
}
.view-btn {
  display: inline-flex; align-items: center; gap: 5px;
  border: none; background: transparent;
  padding: 4px 10px;
  font-family: inherit;
  font-size: var(--tk-fz-sm, 12px);
  font-weight: var(--tk-fw-semibold);
  color: var(--tk-text-muted);
  cursor: pointer;
  border-radius: 3px;
  transition: background var(--tk-transition), color var(--tk-transition);
}
.view-btn:hover { color: var(--tk-text); }
.view-btn.active {
  background: var(--tk-n-0);
  color: var(--tk-text);
  box-shadow: 0 1px 2px rgba(9,30,66,0.08);
}
@media (max-width: 600px) {
  .view-btn span { display: none; }
  .view-btn { padding: 6px 8px; }
}

/* Кнопка поиска — широкая, как в Linear / Notion */
.search-btn {
  display: inline-flex; align-items: center; gap: var(--tk-s-2);
  background: var(--tk-n-50);
  border: 1px solid var(--tk-border);
  color: var(--tk-text-muted);
  font-family: inherit;
  font-weight: var(--tk-fw-medium);
  padding: 0 var(--tk-s-2) 0 var(--tk-s-3) !important;
  cursor: pointer;
  transition: background var(--tk-transition), border-color var(--tk-transition), color var(--tk-transition);
}
.search-btn:hover { background: var(--tk-n-100); border-color: var(--tk-n-300); color: var(--tk-text-secondary); }
.search-btn:focus-visible { outline: none; border-color: var(--tk-accent); box-shadow: var(--tk-focus-ring); }
.search-btn-label {
  font-size: var(--tk-fz-md);
  color: var(--tk-text-secondary);
}
.search-btn-kbd {
  font-family: inherit;
  font-size: var(--tk-fz-xs);
  font-weight: var(--tk-fw-semibold);
  padding: 1px 6px;
  background: var(--tk-n-0);
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-sm);
  color: var(--tk-text-muted);
  white-space: nowrap;
}

/* Сортировка — компактнее, с chevron */
.sort-wrap { position: relative; display: inline-flex; align-items: center; }
.sort-select-chev {
  position: absolute; right: 8px; top: 50%;
  transform: translateY(-50%);
  pointer-events: none;
  color: var(--tk-text-muted);
}

.tasks-loading, .tasks-error, .tasks-empty {
  padding: 40px; text-align: center; color: var(--tk-text-muted);
  font-size: var(--tk-fz-md);
}
.tasks-error { color: var(--tk-danger); }

.tasks-board-area {
  flex: 1; overflow: hidden;
  padding: var(--tk-s-3) 0 4px var(--tk-s-3);
}
.tasks-columns {
  display: flex; gap: var(--tk-s-3); align-items: flex-start;
  height: 100%; overflow-x: auto; overflow-y: hidden;
  padding-bottom: 0;
  padding-right: var(--tk-s-4);
  cursor: grab;
  user-select: text;
  /* Тонкий горизонтальный скроллбар внизу полотна */
  scrollbar-width: thin;
  scrollbar-color: var(--tk-n-200) transparent;
}
.tasks-columns::-webkit-scrollbar { height: 8px; }
.tasks-columns::-webkit-scrollbar-thumb { background: var(--tk-n-200); border-radius: 4px; }
.tasks-columns::-webkit-scrollbar-thumb:hover { background: var(--tk-n-300); }
.tasks-columns::-webkit-scrollbar-track { background: transparent; }
.tasks-columns.is-panning {
  cursor: grabbing;
  user-select: none;
}
.tasks-columns.is-panning * { cursor: inherit !important; }
.tasks-column-wrap {
  height: 100%;
  display: flex;
  border: 2px solid transparent;
  border-radius: var(--tk-r-lg);
  transition: border-color var(--tk-transition);
}
.tasks-column-wrap.col-drag-over { border-color: var(--tk-accent); }

.tasks-add-column {
  width: 280px; flex: 0 0 280px;
}
.tasks-add-column-btn {
  width: 100%; padding: var(--tk-s-3);
  background: rgba(9,30,66,0.04);
  border: 1px dashed var(--tk-border);
  border-radius: var(--tk-r-md);
  cursor: pointer; color: var(--tk-text-muted);
  font-size: var(--tk-fz-md); font-weight: var(--tk-fw-medium);
  font-family: inherit;
  transition: background var(--tk-transition), color var(--tk-transition), border-color var(--tk-transition);
}
.tasks-add-column-btn:hover {
  background: rgba(9,30,66,0.07);
  color: var(--tk-text);
  border-color: var(--tk-n-300);
}

/* ═══ Меню настроек доски ═══ */
.tasks-header { position: relative; }
.icon-btn {
  width: 32px; height: 32px; padding: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: var(--tk-fz-lg);
  background: var(--tk-n-0);
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-sm);
  color: var(--tk-text-secondary);
  cursor: pointer;
  transition: background var(--tk-transition), border-color var(--tk-transition), color var(--tk-transition);
}
.icon-btn:hover { background: var(--tk-n-100); border-color: var(--tk-n-300); color: var(--tk-text); }
.icon-btn:focus-visible { outline: none; box-shadow: var(--tk-focus-ring); border-color: var(--tk-accent); }

.board-menu {
  position: absolute; top: 56px; right: var(--tk-s-5); z-index: 100;
  background: var(--tk-bg-popover);
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-md);
  box-shadow: var(--tk-shadow-popover);
  min-width: 240px; padding: var(--tk-s-1);
}
.board-menu-title {
  padding: var(--tk-s-2) var(--tk-s-3) var(--tk-s-1);
  font-size: var(--tk-fz-xs); font-weight: var(--tk-fw-bold);
  color: var(--tk-text-muted); text-transform: uppercase; letter-spacing: .5px;
  border-bottom: 1px solid var(--tk-border-soft);
  margin-bottom: var(--tk-s-1);
}
.board-menu-item {
  display: flex; align-items: center; gap: var(--tk-s-2); width: 100%;
  padding: var(--tk-s-2) var(--tk-s-3);
  border: none; background: none; cursor: pointer;
  font-size: var(--tk-fz-md); color: var(--tk-text);
  border-radius: var(--tk-r-sm);
  font-family: inherit; text-align: left;
  transition: background var(--tk-transition);
}
.board-menu-item:hover { background: var(--tk-n-100); }
.board-menu-item.danger { color: var(--tk-danger); }
.board-menu-item.danger:hover { background: var(--tk-danger-soft); }

/* ═══ Менеджер меток ═══ */
:deep(.modal) {
  position: fixed; inset: 0; background: rgba(9,30,66,0.50);
  display: flex; align-items: center; justify-content: center;
  z-index: 1000; padding: var(--tk-s-5);
}
:deep(.modal-box) {
  background: var(--tk-bg-popover);
  border-radius: var(--tk-r-lg);
  box-shadow: var(--tk-shadow-popover);
  width: 100%; padding: var(--tk-s-4);
}
:deep(.modal-header) {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: var(--tk-s-3);
}
:deep(.modal-header h3) { margin: 0; font-size: var(--tk-fz-xl); font-weight: var(--tk-fw-semibold); color: var(--tk-text); }
:deep(.modal-close) {
  background: none; border: none; cursor: pointer;
  font-size: var(--tk-fz-h1); color: var(--tk-text-muted);
  padding: var(--tk-s-1) var(--tk-s-2); border-radius: var(--tk-r-sm);
  transition: background var(--tk-transition), color var(--tk-transition);
}
:deep(.modal-close):hover { background: var(--tk-n-100); color: var(--tk-text); }

.labels-mgr { display: flex; flex-direction: column; gap: var(--tk-s-1); }
.labels-mgr-row {
  display: flex; align-items: center; gap: var(--tk-s-2);
  padding: var(--tk-s-1) var(--tk-s-2); border-radius: var(--tk-r-sm);
}
.labels-mgr-row:hover { background: var(--tk-n-100); }
.labels-mgr-swatch {
  width: 14px; height: 14px; border-radius: 50%;
  flex-shrink: 0; border: 1px solid rgba(9,30,66,0.10);
}
.labels-mgr-input {
  flex: 1; padding: 6px var(--tk-s-2); font-size: var(--tk-fz-md);
  border: 1px solid var(--tk-border); border-radius: var(--tk-r-sm);
  background: var(--tk-n-0); color: var(--tk-text); font-family: inherit;
  transition: border-color var(--tk-transition), box-shadow var(--tk-transition);
}
.labels-mgr-input:focus { outline: none; border-color: var(--tk-accent); box-shadow: var(--tk-focus-ring); }
.labels-mgr-color {
  width: 30px; height: 28px; padding: 0; cursor: pointer;
  border: 1px solid var(--tk-border); border-radius: var(--tk-r-sm);
  background: var(--tk-n-0);
}
.labels-mgr-empty {
  text-align: center; padding: var(--tk-s-3); color: var(--tk-text-muted);
  font-size: var(--tk-fz-md); font-style: italic;
}
.labels-mgr-add {
  display: flex; gap: var(--tk-s-2); align-items: center;
  margin-top: var(--tk-s-2); padding-top: var(--tk-s-3);
  border-top: 1px solid var(--tk-border-soft);
}
.labels-mgr-add input[type="text"] {
  flex: 1; padding: 6px var(--tk-s-2); font-size: var(--tk-fz-md);
  border: 1px solid var(--tk-border); border-radius: var(--tk-r-sm);
  background: var(--tk-n-0); color: var(--tk-text); font-family: inherit;
  transition: border-color var(--tk-transition), box-shadow var(--tk-transition);
}
.labels-mgr-add input[type="text"]:focus { outline: none; border-color: var(--tk-accent); box-shadow: var(--tk-focus-ring); }
.labels-mgr-add input[type="color"] {
  width: 30px; height: 28px; padding: 0; cursor: pointer;
  border: 1px solid var(--tk-border); border-radius: var(--tk-r-sm);
}
.labels-mgr-add .btn { padding: 0 var(--tk-s-3); height: 28px; }
.ts-icon-btn {
  background: none; border: none; cursor: pointer;
  color: var(--tk-text-muted); font-size: var(--tk-fz-sm);
  padding: var(--tk-s-1) 7px; border-radius: var(--tk-r-sm);
  transition: background var(--tk-transition), color var(--tk-transition);
}
.ts-icon-btn:hover { color: var(--tk-danger); background: var(--tk-danger-soft); }

/* ═══ Сортировка ═══ */
.sort-select {
  appearance: none; -webkit-appearance: none; -moz-appearance: none;
  padding: 0 24px 0 var(--tk-s-3); height: 32px;
  font-size: var(--tk-fz-sm);
  border: 1px solid var(--tk-border); border-radius: var(--tk-r-sm);
  background: var(--tk-n-0); color: var(--tk-text);
  font-family: inherit; font-weight: var(--tk-fw-medium);
  cursor: pointer;
  transition: border-color var(--tk-transition), box-shadow var(--tk-transition);
}
.sort-select:hover { border-color: var(--tk-n-300); }
.sort-select:focus { outline: none; border-color: var(--tk-accent); box-shadow: var(--tk-focus-ring); }

/* ═══ Поиск ═══
   Teleport-ится в body — токены с .tasks-view не наследуются. Дублируем минимум локально. */
.search-modal {
  --tk-r-sm: 4px; --tk-r-md: 8px; --tk-r-lg: 12px;
  --tk-s-1: 4px; --tk-s-2: 8px; --tk-s-3: 12px; --tk-s-4: 16px; --tk-s-5: 20px; --tk-s-6: 24px;
  --tk-fz-xs: 11px; --tk-fz-sm: 12px; --tk-fz-md: 13px; --tk-fz-lg: 14px; --tk-fz-xl: 16px;
  --tk-fw-medium: 500; --tk-fw-semibold: 600; --tk-fw-bold: 700;
  --tk-n-0: #FFFFFF; --tk-n-50: #F7F8F9; --tk-n-100: #F1F2F4;
  --tk-n-200: #DCDFE4; --tk-n-300: #B3B9C4; --tk-n-500: #758195;
  --tk-n-700: #44546F; --tk-n-900: #172B4D;
  --tk-bg-popover: #FFFFFF;
  --tk-border: #DCDFE4; --tk-border-soft: #E1E4E8;
  --tk-accent: #E87A1E; --tk-accent-text: #B85A0E;
  --tk-prio-urgent-bg: #FFEBE6; --tk-prio-urgent-fg: #BF2600;
  --tk-prio-high-bg: #FFF7D6;   --tk-prio-high-fg:   #974F0C;
  --tk-success: #1F8F4E;
  --tk-text: var(--tk-n-900); --tk-text-secondary: var(--tk-n-700); --tk-text-muted: var(--tk-n-500);
  --tk-transition: 120ms ease;
  --tk-shadow-popover: 0 8px 24px rgba(9,30,66,0.18), 0 1px 2px rgba(9,30,66,0.10);

  position: fixed; inset: 0; background: rgba(9,30,66,0.50);
  z-index: 1100;
}
.search-box {
  position: absolute;
  top: 80px; left: 50%; transform: translateX(-50%);
  background: var(--tk-bg-popover);
  border-radius: var(--tk-r-lg);
  width: calc(100% - 40px); max-width: 640px;
  max-height: calc(100vh - 120px);
  display: flex; flex-direction: column;
  box-shadow: var(--tk-shadow-popover);
  overflow: hidden;
}
.search-input-wrap {
  display: flex; align-items: center; gap: var(--tk-s-2);
  padding: var(--tk-s-3) var(--tk-s-4);
  border-bottom: 1px solid var(--tk-border-soft);
}
.search-icon-svg { color: var(--tk-text-muted); }
.search-input {
  flex: 1; border: none; outline: none;
  font-size: var(--tk-fz-xl); background: none; color: var(--tk-text);
  font-family: inherit;
}
.search-input::placeholder { color: var(--tk-text-muted); }
.search-loading { color: var(--tk-text-muted); font-size: var(--tk-fz-lg); }
.search-count {
  font-size: var(--tk-fz-sm); color: var(--tk-text-secondary);
  background: var(--tk-n-100);
  padding: 2px var(--tk-s-2); border-radius: 10px;
  font-weight: var(--tk-fw-semibold);
}
.search-results {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
}
.search-empty, .search-hint {
  padding: var(--tk-s-6); text-align: center;
  color: var(--tk-text-muted); font-size: var(--tk-fz-md);
}
.search-result {
  display: flex; align-items: center; gap: var(--tk-s-3);
  padding: var(--tk-s-3) var(--tk-s-4);
  cursor: pointer;
  border-bottom: 1px solid var(--tk-border-soft);
  transition: background var(--tk-transition);
}
.search-result:last-child { border-bottom: none; }
.search-result.hover, .search-result:hover { background: var(--tk-n-100); }
.search-result.is-done { opacity: 0.55; }
.sr-main { flex: 1; min-width: 0; }
.sr-title {
  font-size: var(--tk-fz-lg); font-weight: var(--tk-fw-semibold); color: var(--tk-text);
  display: flex; align-items: center; gap: var(--tk-s-1); flex-wrap: wrap;
}
.sr-tag {
  font-size: 10px; font-weight: var(--tk-fw-bold);
  padding: 1px var(--tk-s-1); border-radius: var(--tk-r-sm);
  background: var(--tk-n-100); color: var(--tk-text-muted);
  text-transform: uppercase; letter-spacing: .3px;
}
.sr-tag.urgent { background: var(--tk-prio-urgent-bg); color: var(--tk-prio-urgent-fg); }
.sr-tag.high   { background: var(--tk-prio-high-bg);   color: var(--tk-prio-high-fg); }
.sr-desc {
  font-size: var(--tk-fz-sm); color: var(--tk-text-muted);
  margin-top: var(--tk-s-1); line-height: 1.4;
  overflow: hidden; text-overflow: ellipsis;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
}
.sr-meta { font-size: var(--tk-fz-xs); color: var(--tk-text-muted); margin-top: var(--tk-s-1); }
.sr-board { font-weight: var(--tk-fw-semibold); color: var(--tk-accent-text); }
.sr-due { font-weight: var(--tk-fw-semibold); color: var(--tk-success); }
.sr-arrow { color: var(--tk-text-muted); font-size: var(--tk-fz-h1); }
</style>
