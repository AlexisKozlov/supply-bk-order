import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
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

  // ═══ ФИЛЬТРЫ И СОРТИРОВКА ═══
  const filters = ref({
    assignees: [],   // user names
    labels: [],      // label ids
    priorities: [],  // 'low','medium','high','urgent'
    dueState: '',    // '' | 'overdue' | 'today' | 'week' | 'no-due'
    text: '',        // быстрый текстовый поиск
  });
  const sortMode = ref('manual'); // manual | due | priority | created

  const hasActiveFilters = computed(() => {
    const f = filters.value;
    return f.assignees.length || f.labels.length || f.priorities.length || f.dueState || f.text;
  });

  function resetFilters() {
    filters.value = { assignees: [], labels: [], priorities: [], dueState: '', text: '' };
  }

  function cardMatchesFilters(c) {
    const f = filters.value;
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
  function sortCards(arr) {
    const a = arr.slice();
    if (sortMode.value === 'due') {
      a.sort((x, y) => {
        if (!x.due_date && !y.due_date) return 0;
        if (!x.due_date) return 1;
        if (!y.due_date) return -1;
        return new Date(x.due_date) - new Date(y.due_date);
      });
    } else if (sortMode.value === 'priority') {
      a.sort((x, y) => (PRIO_RANK[y.priority] || 0) - (PRIO_RANK[x.priority] || 0));
    } else if (sortMode.value === 'created') {
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
    for (const k of Object.keys(map)) map[k] = sortCards(map[k]);
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

  async function loadBoard(id) {
    if (!id) return;
    loading.value = true;
    error.value = '';
    try {
      const r = await tasksApi.loadBoard(id);
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
    // Обновим локально без перезагрузки доски
    const c = cards.value.find(x => x.id === id);
    if (c) Object.assign(c, payload);
  }

  async function deleteCard(id) {
    await tasksApi.deleteCard(id);
    cards.value = cards.value.filter(c => c.id !== id);
  }

  async function moveCard(cardId, toColumnId, toIndex) {
    // Оптимистично переставляем локально
    const card = cards.value.find(c => c.id === cardId);
    if (!card) return;
    const fromCol = card.column_id;
    card.column_id = toColumnId;
    // Пересортируем sort_order в обеих колонках
    const inTarget = cards.value
      .filter(c => c.column_id === toColumnId && c.id !== cardId)
      .sort((a, b) => a.sort_order - b.sort_order);
    inTarget.splice(Math.max(0, Math.min(toIndex, inTarget.length)), 0, card);
    inTarget.forEach((c, i) => { c.sort_order = i; });
    if (fromCol !== toColumnId) {
      const inFrom = cards.value
        .filter(c => c.column_id === fromCol)
        .sort((a, b) => a.sort_order - b.sort_order);
      inFrom.forEach((c, i) => { c.sort_order = i; });
      // Если переехала в "Готово" — отметим визуально
      const targetCol = columns.value.find(c => c.id === toColumnId);
      if (targetCol?.is_done_column) card.is_done = 1;
      else card.is_done = 0;
    }
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
    filters, sortMode, hasActiveFilters, resetFilters,
    fetchBoards, loadBoard, reload, createBoard, updateBoard, deleteBoard,
    createColumn, updateColumn, deleteColumn, reorderColumns,
    createCard, updateCard, deleteCard, moveCard,
    fetchUsers, createLabel, updateLabel, deleteLabel,
  };
});
