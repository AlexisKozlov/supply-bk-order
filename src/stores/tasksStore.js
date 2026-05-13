import { defineStore } from 'pinia';
import { ref, computed, watch } from 'vue';
import { tasksApi } from '@/lib/tasksApi.js';

export const useTasksStore = defineStore('tasks', () => {
  const boards = ref([]);
  const currentBoardId = ref(null);
  const board = ref(null);
  const columns = ref([]);
  const cards = ref([]);
  const labels = ref([]);
  const canEditStructure = ref(false);
  const isOwner = ref(false);
  const loading = ref(false);
  const error = ref('');
  const users = ref([]);

  // ═══ ФИЛЬТРЫ И СОРТИРОВКА ПО КОЛОНКАМ ═══
  // Фильтры по колонкам: { [columnId]: { assignees, labels, priorities, dueState, text } }
  const filtersByColumn = ref({});
  // Сортировка по колонкам: { [columnId]: 'manual' | 'due' | 'priority' | 'created' }
  const sortByColumn = ref({});

  function emptyFilter() {
    return { assignees: [], labels: [], priorities: [], dueState: '', text: '' };
  }

  function getColumnFilters(colId) {
    if (!filtersByColumn.value[colId]) filtersByColumn.value[colId] = emptyFilter();
    return filtersByColumn.value[colId];
  }

  function columnHasActiveFilters(colId) {
    const f = filtersByColumn.value[colId];
    if (!f) return false;
    return !!(f.assignees.length || f.labels.length || f.priorities.length || f.dueState || f.text);
  }

  function activeFilterCount(colId) {
    const f = filtersByColumn.value[colId];
    if (!f) return 0;
    let n = 0;
    if (f.assignees.length)  n += f.assignees.length;
    if (f.labels.length)     n += f.labels.length;
    if (f.priorities.length) n += f.priorities.length;
    if (f.dueState)          n += 1;
    if (f.text)              n += 1;
    return n;
  }

  function resetColumnFilters(colId) {
    filtersByColumn.value[colId] = emptyFilter();
  }

  function getColumnSort(colId) {
    return sortByColumn.value[colId] || 'manual';
  }
  function setColumnSort(colId, mode) {
    sortByColumn.value = { ...sortByColumn.value, [colId]: mode };
  }
  function columnHasCustomSort(colId) {
    const m = sortByColumn.value[colId];
    return !!(m && m !== 'manual');
  }

  // Скопировать настройки одной колонки во все остальные обычные колонки доски
  function applyColumnSettingsToAll(srcColId) {
    const src = filtersByColumn.value[srcColId];
    const srcSort = sortByColumn.value[srcColId] || 'manual';
    const nextF = { ...filtersByColumn.value };
    const nextS = { ...sortByColumn.value };
    for (const col of columns.value) {
      if (col.id === srcColId) continue;
      if (col.is_archive_column) continue;
      nextF[col.id] = src ? JSON.parse(JSON.stringify(src)) : emptyFilter();
      nextS[col.id] = srcSort;
    }
    filtersByColumn.value = nextF;
    sortByColumn.value = nextS;
  }

  function cardMatchesFilters(c) {
    const f = filtersByColumn.value[c.column_id];
    if (!f) return true;
    if (f.text) {
      const t = f.text.toLowerCase();
      if (!(c.title || '').toLowerCase().includes(t) && !(c.description || '').toLowerCase().includes(t)) return false;
    }
    if (f.assignees.length && !f.assignees.some(a => (c.assignees || []).includes(a))) return false;
    if (f.labels.length && !f.labels.some(l => (c.label_ids || []).includes(l))) return false;
    if (f.priorities.length && !f.priorities.includes(c.priority || 'medium')) return false;
    if (f.dueState) {
      const now = new Date();
      const today = new Date(now); today.setHours(0,0,0,0);
      const tomorrow = new Date(today); tomorrow.setDate(today.getDate()+1);
      const weekEnd = new Date(today); weekEnd.setDate(today.getDate()+7);
      if (f.dueState === 'no-due' && c.due_date) return false;
      if (f.dueState === 'overdue' && (!c.due_date || new Date(c.due_date) >= now || c.is_done)) return false;
      if (f.dueState === 'today' && (!c.due_date || new Date(c.due_date) < today || new Date(c.due_date) >= tomorrow)) return false;
      if (f.dueState === 'week' && (!c.due_date || new Date(c.due_date) >= weekEnd)) return false;
    }
    return true;
  }

  const PRIO_RANK = { urgent: 4, high: 3, medium: 2, low: 1 };
  function sortCards(arr, colId) {
    const mode = getColumnSort(colId);
    const a = arr.slice();
    if (mode === 'due') {
      a.sort((x, y) => {
        if (!x.due_date && !y.due_date) return 0;
        if (!x.due_date) return 1;
        if (!y.due_date) return -1;
        return new Date(x.due_date) - new Date(y.due_date);
      });
    } else if (mode === 'priority') {
      a.sort((x, y) => (PRIO_RANK[y.priority] || 0) - (PRIO_RANK[x.priority] || 0));
    } else if (mode === 'created') {
      a.sort((x, y) => new Date(y.created_at) - new Date(x.created_at));
    } else {
      a.sort((x, y) => (x.sort_order ?? 0) - (y.sort_order ?? 0));
    }
    return a;
  }

  const cardsByColumn = computed(() => {
    const map = {};
    for (const col of columns.value) map[col.id] = [];
    for (const c of cards.value) {
      if (!cardMatchesFilters(c)) continue;
      if (!map[c.column_id]) map[c.column_id] = [];
      map[c.column_id].push(c);
    }
    for (const k of Object.keys(map)) map[k] = sortCards(map[k], k);
    return map;
  });

  async function fetchBoards() {
    loading.value = true;
    error.value = '';
    try {
      const r = await tasksApi.listBoards();
      boards.value = r.boards || [];
    } catch (e) {
      error.value = e.message || 'Ошибка загрузки досок';
    } finally {
      loading.value = false;
    }
  }

  const BOARD_STATE_KEY = (id) => 'bk_tasks_state_' + id;
  function loadBoardState(id) {
    try {
      const raw = localStorage.getItem(BOARD_STATE_KEY(id));
      if (!raw) { filtersByColumn.value = {}; sortByColumn.value = {}; return; }
      const obj = JSON.parse(raw);
      filtersByColumn.value = obj && obj.filters ? obj.filters : {};
      sortByColumn.value    = obj && obj.sort    ? obj.sort    : {};
    } catch { filtersByColumn.value = {}; sortByColumn.value = {}; }
  }
  function saveBoardState() {
    const id = currentBoardId.value;
    if (!id) return;
    try {
      localStorage.setItem(BOARD_STATE_KEY(id), JSON.stringify({
        filters: filtersByColumn.value,
        sort:    sortByColumn.value,
      }));
    } catch { /* localStorage недоступен — игнорируем */ }
  }
  // Автосейв при любом изменении фильтров/сортировки
  watch([filtersByColumn, sortByColumn], () => saveBoardState(), { deep: true });

  async function loadBoard(id) {
    if (!id) return;
    loading.value = true;
    error.value = '';
    try {
      const r = await tasksApi.loadBoard(id);
      // При смене доски восстанавливаем сохранённые фильтры/сортировку этой доски
      if (currentBoardId.value !== id) loadBoardState(id);
      board.value = r.board;
      columns.value = r.columns || [];
      cards.value = r.cards || [];
      labels.value = r.labels || [];
      canEditStructure.value = !!r.can_edit_structure;
      isOwner.value = !!r.is_owner;
      currentBoardId.value = id;
    } catch (e) {
      error.value = e.message || 'Ошибка загрузки доски';
    } finally {
      loading.value = false;
    }
  }

  async function reload() {
    if (currentBoardId.value) await loadBoard(currentBoardId.value);
  }

  async function createBoard(payload) {
    const r = await tasksApi.createBoard(payload);
    await fetchBoards();
    if (r.id) await loadBoard(r.id);
    return r.id;
  }

  async function updateBoard(id, payload) {
    await tasksApi.updateBoard(id, payload);
    await fetchBoards();
    if (board.value && board.value.id === id && payload.title) board.value.title = payload.title;
  }

  async function deleteBoard(id) {
    await tasksApi.deleteBoard(id);
    await fetchBoards();
    if (currentBoardId.value === id) {
      const myFirst = boards.value[0];
      if (myFirst) await loadBoard(myFirst.id);
      else { board.value = null; currentBoardId.value = null; columns.value = []; cards.value = []; }
    }
  }

  async function createColumn(payload) {
    await tasksApi.createColumn(payload);
    await reload();
  }

  async function updateColumn(id, payload) {
    await tasksApi.updateColumn(id, payload);
    await reload();
  }

  async function deleteColumn(id) {
    await tasksApi.deleteColumn(id);
    await reload();
  }

  async function reorderColumns(ids) {
    if (!currentBoardId.value) return;
    // Оптимистично — переставляем локально
    const map = new Map(columns.value.map(c => [c.id, c]));
    columns.value = ids.map(id => map.get(id)).filter(Boolean);
    columns.value.forEach((c, i) => { c.sort_order = i; });
    try { await tasksApi.reorderColumns(currentBoardId.value, ids); }
    catch (e) { error.value = e.message; await reload(); }
  }

  async function createCard(payload) {
    await tasksApi.createCard(payload);
    await reload();
  }

  async function updateCard(id, payload) {
    await tasksApi.updateCard(id, payload);
    // Обновим локально без перезагрузки доски — заменяем объект в массиве,
    // чтобы computed cardsByColumn гарантированно перевычислился (в т.ч. для is_done).
    const idx = cards.value.findIndex(x => x.id === id);
    if (idx >= 0) {
      const next = cards.value.slice();
      next[idx] = { ...next[idx], ...payload };
      cards.value = next;
    }
  }

  async function deleteCard(id) {
    await tasksApi.deleteCard(id);
    cards.value = cards.value.filter(c => c.id !== id);
  }

  async function moveCard(cardId, toColumnId, toIndex) {
    // Оптимистичный апдейт. Заменяем объект карточки в массиве (а не мутируем по полю),
    // чтобы Vue гарантированно перерисовал классы is-done и состояние чекбокса.
    const idx = cards.value.findIndex(c => c.id === cardId);
    if (idx < 0) return;
    const original = cards.value[idx];
    const fromCol = original.column_id;
    const targetCol = columns.value.find(c => c.id === toColumnId);
    const toArchive = !!(targetCol && targetCol.is_archive_column);
    const toDone    = !!(targetCol && targetCol.is_done_column);
    // Для внешних карточек is_done/is_archived — это персональный статус
    // («моя часть закрыта»), а не флаги оригинала автора. Бэк отдаёт их
    // подменёнными на assignee.is_done, поэтому локально обращаемся с ними
    // как с обычными — то же поведение, что и для своих.
    const updated = {
      ...original,
      column_id: toColumnId,
      is_done:     (toArchive || toDone) ? 1 : 0,
      is_archived: toArchive ? 1 : 0,
      completed_at: (toArchive || toDone) ? new Date().toISOString().slice(0,19).replace('T',' ') : null,
    };
    // Пересчёт sort_order в целевой колонке
    const inTarget = cards.value
      .filter(c => c.column_id === toColumnId && c.id !== cardId)
      .sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0));
    inTarget.splice(Math.max(0, Math.min(toIndex, inTarget.length)), 0, updated);
    inTarget.forEach((c, i) => { c.sort_order = i; });
    if (fromCol !== toColumnId) {
      const inFrom = cards.value
        .filter(c => c.column_id === fromCol && c.id !== cardId)
        .sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0));
      inFrom.forEach((c, i) => { c.sort_order = i; });
    }
    // Заменяем массив целиком, чтобы computed гарантированно перевычислился
    const next = cards.value.slice();
    next[idx] = updated;
    cards.value = next;

    try {
      await tasksApi.moveCard({ card_id: cardId, to_column_id: toColumnId, to_index: toIndex });
    } catch (e) {
      error.value = e.message;
      await reload();
    }
  }

  async function fetchUsers() {
    if (users.value.length) return;
    try { const r = await tasksApi.listUsers(); users.value = r.users || []; }
    catch (e) { /* noop */ }
  }

  async function createLabel(payload) {
    await tasksApi.createLabel(payload);
    await reload();
  }
  async function updateLabel(id, payload) {
    await tasksApi.updateLabel(id, payload);
    await reload();
  }
  async function deleteLabel(id) {
    await tasksApi.deleteLabel(id);
    await reload();
  }

  return {
    boards, currentBoardId, board, columns, cards, labels, users,
    canEditStructure, isOwner, loading, error, cardsByColumn,
    filtersByColumn, sortByColumn,
    getColumnFilters, columnHasActiveFilters, activeFilterCount, resetColumnFilters,
    getColumnSort, setColumnSort, columnHasCustomSort,
    applyColumnSettingsToAll,
    fetchBoards, loadBoard, reload, createBoard, updateBoard, deleteBoard,
    createColumn, updateColumn, deleteColumn, reorderColumns,
    createCard, updateCard, deleteCard, moveCard,
    fetchUsers, createLabel, updateLabel, deleteLabel,
  };
});
