<template>
  <div class="tlv">
    <!-- Тулбар: поиск, скрыть выполненные, счётчик, печать -->
    <div class="tlv-toolbar">
      <div class="tlv-search-wrap">
        <TaskIcon name="search" :size="14" class="tlv-search-icon"/>
        <input v-model="search" class="tlv-search" type="text" placeholder="Поиск по названию…"/>
      </div>
      <label class="tlv-check">
        <input type="checkbox" v-model="hideDone"/>
        <span>Скрыть выполненные</span>
      </label>
      <div class="tlv-spacer"></div>
      <span class="tlv-counter">{{ rows.length }} {{ plural(rows.length, ['задача', 'задачи', 'задач']) }}</span>
      <button class="tlv-print-btn" @click="printList" title="Печать списка">
        <TaskIcon name="download" :size="14"/>
        <span>Печать</span>
      </button>
    </div>

    <!-- Таблица -->
    <div class="tlv-table-wrap">
      <table class="tlv-table">
        <thead>
          <tr>
            <th class="tlv-th tlv-th-sortable tlv-col-title" @click="setSort('title')">
              <span>Задача</span><SortMark :active="sortKey === 'title'" :dir="sortDir"/>
            </th>
            <th class="tlv-th tlv-th-sortable" @click="setSort('status')">
              <span>Статус</span><SortMark :active="sortKey === 'status'" :dir="sortDir"/>
            </th>
            <th class="tlv-th tlv-th-sortable" @click="setSort('priority')">
              <span>Приоритет</span><SortMark :active="sortKey === 'priority'" :dir="sortDir"/>
            </th>
            <th class="tlv-th tlv-th-sortable" @click="setSort('due')">
              <span>Срок</span><SortMark :active="sortKey === 'due'" :dir="sortDir"/>
            </th>
            <th class="tlv-th">Исполнители</th>
            <th class="tlv-th">Метки</th>
            <th class="tlv-th tlv-col-progress">Прогресс</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="card in rows" :key="card.id"
              class="tlv-row"
              :class="{ 'tlv-row-done': card.is_done }"
              @click="$emit('open-card', card.id)">
            <!-- Задача -->
            <td class="tlv-td tlv-cell-title">
              <span v-if="card.color" class="tlv-cover" :style="{ background: card.color }"></span>
              <span v-if="card.blocked_by_open > 0" class="tlv-lock"
                    :title="'Заблокирована: ждёт ' + card.blocked_by_open + ' задач(и)'">
                <TaskIcon name="lock" :size="11"/>
              </span>
              <span class="tlv-title-text">{{ card.title }}</span>
              <span v-if="card.is_external" class="tlv-ext" :title="'Чужая доска: ' + (card.external_board_owner || '')">
                чужая
              </span>
            </td>
            <!-- Статус — редактируется по клику (доступно и на чужих карточках) -->
            <td class="tlv-td">
              <button type="button" class="tlv-status tlv-editable" @click.stop="openEditor('status', card, $event)">
                <span class="tlv-status-dot" :style="{ background: columnColor(card) }"></span>
                {{ columnTitle(card) }}
                <TaskIcon name="chevronDown" :size="10" class="tlv-caret"/>
              </button>
            </td>
            <!-- Приоритет — редактируется (на чужих карточках только просмотр) -->
            <td class="tlv-td">
              <button v-if="!card.is_external" type="button"
                      class="tlv-prio tlv-editable tlv-prio-btn" :class="'tlv-prio-' + (card.priority || 'medium')"
                      @click.stop="openEditor('priority', card, $event)">
                {{ prioLabel(card.priority) }}
              </button>
              <span v-else class="tlv-prio" :class="'tlv-prio-' + (card.priority || 'medium')">
                {{ prioLabel(card.priority) }}
              </span>
            </td>
            <!-- Срок -->
            <td class="tlv-td">
              <button v-if="!card.is_external" type="button" class="tlv-cell-btn tlv-editable"
                      @click.stop="openEditor('due', card, $event)">
                <span v-if="card.due_date" class="tlv-due" :class="dueClass(card)">{{ fmtDue(card.due_date) }}</span>
                <span v-else class="tlv-muted">срок</span>
              </button>
              <template v-else>
                <span v-if="card.due_date" class="tlv-due" :class="dueClass(card)">{{ fmtDue(card.due_date) }}</span>
                <span v-else class="tlv-muted">—</span>
              </template>
            </td>
            <!-- Исполнители -->
            <td class="tlv-td">
              <button v-if="!card.is_external" type="button" class="tlv-cell-btn tlv-editable"
                      @click.stop="openEditor('assignees', card, $event)">
                <span v-if="card.assignees && card.assignees.length" class="tlv-assignees">
                  <span v-for="(n, i) in card.assignees.slice(0, 4)" :key="i"
                        class="tlv-bubble"
                        :class="{ 'tlv-bubble-done': (card.assignees_done || []).includes(n) }"
                        :title="n + ((card.assignees_done || []).includes(n) ? ' — выполнил' : '')">
                    {{ initials(n) }}
                  </span>
                  <span v-if="card.assignees.length > 4" class="tlv-bubble-more">+{{ card.assignees.length - 4 }}</span>
                </span>
                <span v-else class="tlv-muted">исполнитель</span>
              </button>
              <template v-else>
                <span v-if="card.assignees && card.assignees.length" class="tlv-assignees">
                  <span v-for="(n, i) in card.assignees.slice(0, 4)" :key="i"
                        class="tlv-bubble"
                        :class="{ 'tlv-bubble-done': (card.assignees_done || []).includes(n) }"
                        :title="n">
                    {{ initials(n) }}
                  </span>
                  <span v-if="card.assignees.length > 4" class="tlv-bubble-more">+{{ card.assignees.length - 4 }}</span>
                </span>
                <span v-else class="tlv-muted">—</span>
              </template>
            </td>
            <!-- Метки -->
            <td class="tlv-td">
              <span v-if="cardLabels(card).length" class="tlv-labels">
                <span v-for="l in cardLabels(card)" :key="l.id"
                      class="tlv-label" :style="{ background: l.color }" :title="l.title">
                  {{ l.title }}
                </span>
              </span>
              <span v-else class="tlv-muted">—</span>
            </td>
            <!-- Прогресс -->
            <td class="tlv-td tlv-cell-progress">
              <span v-if="card.checklist && card.checklist.total" class="tlv-stat"
                    :class="{ 'tlv-stat-done': card.checklist.done === card.checklist.total }"
                    title="Чек-лист">
                <TaskIcon name="check" :size="11"/>
                {{ card.checklist.done }}/{{ card.checklist.total }}
              </span>
              <span v-if="card.subtasks_total" class="tlv-stat"
                    :class="{ 'tlv-stat-done': card.subtasks_done === card.subtasks_total }"
                    title="Подзадачи">
                <TaskIcon name="list" :size="11"/>
                {{ card.subtasks_done }}/{{ card.subtasks_total }}
              </span>
              <span v-if="!card.checklist?.total && !card.subtasks_total" class="tlv-muted">—</span>
            </td>
          </tr>
        </tbody>
      </table>

      <div v-if="!rows.length" class="tlv-empty">
        <TaskIcon name="list" :size="40"/>
        <p class="tlv-empty-title">{{ search || hideDone ? 'Ничего не найдено' : 'На доске пока нет задач' }}</p>
        <p class="tlv-empty-sub">{{ search || hideDone ? 'Измените условия поиска' : 'Создайте первую задачу в режиме «Канбан»' }}</p>
      </div>
    </div>

    <!-- Поповер-редактор ячейки: статус / приоритет / срок / исполнители -->
    <Teleport to="body">
      <div v-if="editor" class="tlv-pop-layer" @click.self="closeEditor">
        <div ref="editorEl" class="tlv-pop" :style="editorStyle" @click.stop>

          <template v-if="editor.field === 'status'">
            <div class="tlv-pop-title">Статус</div>
            <button v-for="col in columns" :key="col.id" type="button" class="tlv-pop-item"
                    :class="{ active: col.id === editor.card.column_id }"
                    @click="setStatus(col)">
              <span class="tlv-status-dot" :style="{ background: col.color || '#9C9384' }"></span>
              <span class="tlv-pop-item-text">{{ col.title }}</span>
            </button>
          </template>

          <template v-else-if="editor.field === 'priority'">
            <div class="tlv-pop-title">Приоритет</div>
            <button v-for="p in PRIORITIES" :key="p.value" type="button" class="tlv-pop-item"
                    :class="{ active: p.value === (editor.card.priority || 'medium') }"
                    @click="setPriority(p.value)">
              <span class="tlv-prio-dot" :style="{ background: PRIO_COLOR[p.value] }"></span>
              <span class="tlv-pop-item-text">{{ p.label }}</span>
            </button>
          </template>

          <template v-else-if="editor.field === 'due'">
            <DatetimePicker :model-value="editor.card.due_date || ''"
                            @update:model-value="setDue"
                            @cancel="closeEditor"/>
          </template>

          <template v-else-if="editor.field === 'assignees'">
            <div class="tlv-pop-title">Исполнители</div>
            <div class="tlv-pop-scroll">
              <button v-for="u in store.users" :key="u.name" type="button" class="tlv-pop-item"
                      @click="toggleAssignee(u.name)">
                <span class="tlv-check" :class="{ on: (editor.card.assignees || []).includes(u.name) }">✓</span>
                <span class="tlv-pop-item-text">{{ u.name }}</span>
              </button>
              <div v-if="!store.users.length" class="tlv-pop-empty">Список пуст</div>
            </div>
          </template>

        </div>
      </div>
    </Teleport>
    <ConfirmModal v-if="confirmModal.show"
                  :title="confirmModal.title"
                  :message="confirmModal.message"
                  :ok-text="confirmModal.okText"
                  :cancel-text="confirmModal.cancelText"
                  @confirm="onConfirmOk"
                  @cancel="onConfirmCancel"/>
  </div>
</template>

<script setup>
import { ref, computed, h, nextTick, onMounted, onBeforeUnmount, defineAsyncComponent } from 'vue';
import TaskIcon from './TaskIcon.vue';
import DatetimePicker from './DatetimePicker.vue';
import { tasksApi } from '@/lib/tasksApi.js';
import { confirmProtocolDueChange } from '@/lib/protocolDueGuard.js';
import { useConfirm } from '@/composables/useConfirm.js';
const ConfirmModal = defineAsyncComponent(() => import('@/components/modals/ConfirmModal.vue'));
const { confirmModal, confirm: confirmAction, onConfirm: onConfirmOk, onCancel: onConfirmCancel } = useConfirm();
import { useTasksStore } from '@/stores/tasksStore.js';
import { useTasksDialogs } from '@/composables/useTasksDialogs.js';

const props = defineProps({
  cards:   { type: Array, default: () => [] },
  columns: { type: Array, default: () => [] },
  labels:  { type: Array, default: () => [] },
});
defineEmits(['open-card']);

const store = useTasksStore();
const dlg = useTasksDialogs();
const showError = (e) => dlg.info('Ошибка', e?.message || String(e), 'error');

// Маленький маркер сортировки в заголовке колонки
const SortMark = (p) => p.active
  ? h(TaskIcon, { name: 'chevronDown', size: 11, class: 'tlv-sort-mark', style: p.dir === 'asc' ? 'transform:rotate(180deg)' : '' })
  : null;

const search = ref('');
const hideDone = ref(false);
const sortKey = ref('status');
const sortDir = ref('asc');

const PRIO_LABEL = { urgent: 'Срочный', high: 'Высокий', medium: 'Средний', low: 'Низкий' };
const PRIO_RANK  = { urgent: 0, high: 1, medium: 2, low: 3 };

const columnsById = computed(() => {
  const m = new Map();
  props.columns.forEach(c => m.set(c.id, c));
  return m;
});
const columnOrder = computed(() => {
  const m = new Map();
  props.columns.forEach((c, i) => m.set(c.id, i));
  return m;
});
const labelsById = computed(() => {
  const m = new Map();
  props.labels.forEach(l => m.set(l.id, l));
  return m;
});

function columnTitle(card) { return columnsById.value.get(card.column_id)?.title || '—'; }
function columnColor(card) { return columnsById.value.get(card.column_id)?.color || '#9C9384'; }
function prioLabel(p) { return PRIO_LABEL[p] || PRIO_LABEL.medium; }
function cardLabels(card) {
  return (card.label_ids || []).map(id => labelsById.value.get(id)).filter(Boolean);
}

const rows = computed(() => {
  let list = props.cards.filter(c => !c.is_archived && !c.parent_card_id);
  if (hideDone.value) list = list.filter(c => !c.is_done);
  const q = search.value.trim().toLowerCase();
  if (q) list = list.filter(c => (c.title || '').toLowerCase().includes(q));

  const dir = sortDir.value === 'asc' ? 1 : -1;
  const k = sortKey.value;
  return [...list].sort((a, b) => {
    let r = 0;
    if (k === 'title') {
      r = (a.title || '').localeCompare(b.title || '', 'ru');
    } else if (k === 'priority') {
      r = (PRIO_RANK[a.priority] ?? 2) - (PRIO_RANK[b.priority] ?? 2);
    } else if (k === 'due') {
      const da = a.due_date ? new Date(a.due_date).getTime() : Infinity;
      const db = b.due_date ? new Date(b.due_date).getTime() : Infinity;
      r = da - db;
    } else { // status
      r = (columnOrder.value.get(a.column_id) ?? 999) - (columnOrder.value.get(b.column_id) ?? 999);
    }
    if (r === 0) r = (a.sort_order || 0) - (b.sort_order || 0);
    return r * dir;
  });
});

function setSort(key) {
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
  } else {
    sortKey.value = key;
    sortDir.value = 'asc';
  }
}

function plural(n, forms) {
  const m10 = n % 10, m100 = n % 100;
  if (m10 === 1 && m100 !== 11) return forms[0];
  if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return forms[1];
  return forms[2];
}

function fmtDue(s) {
  const d = new Date(s);
  if (isNaN(d)) return '';
  const pad = n => String(n).padStart(2, '0');
  let out = `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${d.getFullYear()}`;
  if (d.getHours() || d.getMinutes()) out += ` ${pad(d.getHours())}:${pad(d.getMinutes())}`;
  return out;
}
function dueClass(card) {
  if (!card.due_date || card.is_done) return '';
  const d = new Date(card.due_date);
  if (isNaN(d)) return '';
  const now = new Date();
  if (d < now) return 'tlv-due-overdue';
  const diffH = (d - now) / 3600000;
  if (diffH <= 24) return 'tlv-due-fire';
  if (diffH <= 72) return 'tlv-due-warn';
  return '';
}

function initials(name) {
  if (!name) return '?';
  const parts = String(name).trim().split(/\s+/);
  return ((parts[0]?.[0] || '') + (parts[1]?.[0] || '')).toUpperCase() || '?';
}

function printList() {
  window.print();
}

// ─── Редактирование ячеек прямо в таблице ───

const PRIORITIES = [
  { value: 'low',    label: 'Низкий'  },
  { value: 'medium', label: 'Средний' },
  { value: 'high',   label: 'Высокий' },
  { value: 'urgent', label: 'Срочный' },
];
const PRIO_COLOR = { urgent: '#C0392B', high: '#E87A1E', medium: '#2B5797', low: '#6B778C' };

// Список пользователей нужен для поповера исполнителей.
onMounted(() => { store.fetchUsers(); });

// editor = { field: 'status'|'priority'|'due'|'assignees', card } | null
const editor = ref(null);
const editorEl = ref(null);
const editorPos = ref({ top: 0, left: 0 });
let editorAnchor = null;
const editorStyle = computed(() => ({
  position: 'fixed',
  top: editorPos.value.top + 'px',
  left: editorPos.value.left + 'px',
}));

function openEditor(field, card, event) {
  // Повторный клик по той же ячейке — закрываем.
  if (editor.value && editor.value.field === field && editor.value.card.id === card.id) {
    editor.value = null;
    return;
  }
  const el = event?.currentTarget;
  editorAnchor = el ? el.getBoundingClientRect() : null;
  if (editorAnchor) editorPos.value = { top: editorAnchor.bottom + 4, left: editorAnchor.left };
  editor.value = { field, card };
  nextTick(placeEditor);
}

// Позиционирование как у поповеров карточки на канбане: фиксируем по
// триггеру, при выходе за край экрана сдвигаем внутрь / показываем выше.
function placeEditor() {
  const el = editorEl.value;
  if (!el || !editorAnchor) return;
  const r = el.getBoundingClientRect();
  const m = 8;
  let left = editorAnchor.left;
  if (left + r.width > window.innerWidth - m) left = Math.max(m, window.innerWidth - r.width - m);
  let top = editorAnchor.bottom + 4;
  if (top + r.height > window.innerHeight - m) {
    const above = editorAnchor.top - r.height - 4;
    top = above >= m ? above : Math.max(m, window.innerHeight - r.height - m);
  }
  editorPos.value = { top, left };
}

function closeEditor() { editor.value = null; }

function onEsc(e) { if (e.key === 'Escape' && editor.value) closeEditor(); }
onMounted(() => window.addEventListener('keydown', onEsc));
onBeforeUnmount(() => window.removeEventListener('keydown', onEsc));

async function setPriority(p) {
  const card = editor.value?.card;
  closeEditor();
  if (!card || p === (card.priority || 'medium')) return;
  try { await store.updateCard(card.id, { priority: p }); }
  catch (e) { showError(e); }
}

async function setDue(iso) {
  const card = editor.value?.card;
  closeEditor();
  if (!card) return;
  if (!(await confirmProtocolDueChange(card, iso || null, confirmAction))) return;
  try { await store.updateCard(card.id, { due_date: iso || null }); }
  catch (e) { showError(e); }
}

// Перенос заблокированной задачи в колонку-завершение — с подтверждением,
// тем же, что на канбане (фича зависимостей карточек).
async function confirmMoveIfBlocked(card, targetColumnId) {
  if (card.is_done) return true;
  if (!(card.blocked_by_open > 0)) return true;
  const col = props.columns.find(c => c.id === targetColumnId);
  if (!col || (!col.is_archive_column && !col.is_done_column)) return true;
  return await dlg.confirmCompleteBlocked(card.blocked_by_open);
}

async function setStatus(col) {
  const card = editor.value?.card;
  closeEditor();
  if (!card || col.id === card.column_id) return;
  if (!(await confirmMoveIfBlocked(card, col.id))) return;
  try { await store.moveCard(card.id, col.id, 0); }
  catch (e) { showError(e); }
}

// Исполнители: поповер остаётся открытым для нескольких переключений,
// поэтому после перезагрузки доски переподвязываем editor.card на свежий
// объект карточки — иначе галочки показывали бы старое состояние.
async function toggleAssignee(name) {
  const card = editor.value?.card;
  if (!card) return;
  const next = Array.isArray(card.assignees) ? card.assignees.slice() : [];
  const i = next.indexOf(name);
  if (i >= 0) next.splice(i, 1); else next.push(name);
  try {
    await tasksApi.setAssignees(card.id, next);
    await store.loadBoard(store.currentBoardId);
    const fresh = store.cards.find(c => c.id === card.id);
    if (fresh && editor.value && editor.value.field === 'assignees') {
      editor.value = { field: 'assignees', card: fresh };
    }
  } catch (e) { showError(e); }
}
</script>

<style scoped>
.tlv {
  display: flex; flex-direction: column;
  gap: 10px;
  height: 100%;
  min-height: 0;
  padding: 0 16px 12px 0;
  box-sizing: border-box;
  overflow: hidden;
}

/* Тулбар */
.tlv-toolbar {
  display: flex; align-items: center; gap: 12px;
  flex-wrap: wrap;
  flex-shrink: 0;
  padding: 4px 0;
}
.tlv-search-wrap { position: relative; }
.tlv-search-icon {
  position: absolute; left: 9px; top: 50%;
  transform: translateY(-50%);
  color: var(--tk-text-muted, #9C9384);
  pointer-events: none;
}
.tlv-search {
  height: 32px;
  width: 240px;
  padding: 0 10px 0 30px;
  border: 1px solid var(--tk-border, #E6E1D7);
  border-radius: 8px;
  background: #fff;
  font-family: inherit; font-size: 13px;
  color: var(--tk-text, #1A1814);
  outline: none;
  transition: border-color 140ms ease;
}
.tlv-search:focus { border-color: var(--tk-accent, #E87A1E); }
.tlv-check {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 12.5px; font-weight: 600;
  color: var(--tk-text-secondary, #534D40);
  cursor: pointer;
  user-select: none;
}
.tlv-check input { accent-color: var(--tk-accent, #E87A1E); cursor: pointer; }
.tlv-spacer { flex: 1; }
.tlv-counter {
  font-size: 11.5px; font-weight: 600;
  color: var(--tk-text-secondary, #534D40);
  padding: 5px 10px;
  background: var(--tk-n-100, #F3F0E8);
  border-radius: 999px;
  white-space: nowrap;
}
.tlv-print-btn {
  display: inline-flex; align-items: center; gap: 6px;
  height: 32px; padding: 0 12px;
  border: 1px solid var(--tk-border, #E6E1D7);
  border-radius: 8px;
  background: #fff;
  font-family: inherit; font-size: 12.5px; font-weight: 600;
  color: var(--tk-text-secondary, #534D40);
  cursor: pointer;
  transition: background 140ms ease, color 140ms ease, border-color 140ms ease;
}
.tlv-print-btn:hover {
  background: var(--tk-n-50, #FAF9F5);
  color: var(--tk-text, #1A1814);
  border-color: var(--tk-accent, #E87A1E);
}

/* Таблица */
.tlv-table-wrap {
  flex: 1;
  min-height: 0;
  overflow: auto;
  border: 1px solid var(--tk-border-soft, #EFEAE0);
  border-radius: 12px;
  background: #fff;
}
.tlv-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 13px;
}
.tlv-th {
  position: sticky; top: 0;
  z-index: 2;
  background: var(--tk-n-50, #FAF9F5);
  color: var(--tk-text-muted, #6E6657);
  font-size: 10.5px; font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  text-align: left;
  padding: 9px 12px;
  border-bottom: 1px solid var(--tk-border, #E6E1D7);
  white-space: nowrap;
}
.tlv-th-sortable { cursor: pointer; user-select: none; }
.tlv-th-sortable:hover { color: var(--tk-text, #1A1814); }
.tlv-th :deep(.tlv-sort-mark) {
  margin-left: 3px;
  vertical-align: middle;
  color: var(--tk-accent, #E87A1E);
}
.tlv-col-title { min-width: 220px; }
.tlv-col-progress { width: 130px; }

.tlv-row {
  cursor: pointer;
  transition: background 120ms ease;
}
.tlv-row:hover { background: var(--tk-n-50, #FAF9F5); }
.tlv-row-done { opacity: 0.6; }
.tlv-row-done .tlv-title-text { text-decoration: line-through; }

.tlv-td {
  padding: 9px 12px;
  border-bottom: 1px solid var(--tk-border-soft, #EFEAE0);
  vertical-align: middle;
}
.tlv-row:last-child .tlv-td { border-bottom: none; }

/* Ячейка названия */
.tlv-cell-title { display: flex; align-items: center; gap: 8px; }
.tlv-cover {
  width: 4px; height: 18px;
  border-radius: 2px;
  flex-shrink: 0;
}
.tlv-title-text {
  font-weight: 600;
  color: var(--tk-text, #1A1814);
}
.tlv-lock {
  display: inline-flex; align-items: center;
  color: #C0392B;
  flex-shrink: 0;
}
.tlv-ext {
  font-size: 9.5px; font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--tk-text-muted, #9C9384);
  background: var(--tk-n-100, #F3F0E8);
  padding: 1px 5px;
  border-radius: 4px;
  flex-shrink: 0;
}

/* Статус */
.tlv-status {
  display: inline-flex; align-items: center; gap: 6px;
  font-weight: 600;
  color: var(--tk-text-secondary, #534D40);
  white-space: nowrap;
}
.tlv-status-dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}

/* Приоритет */
.tlv-prio {
  display: inline-block;
  font-size: 11px; font-weight: 700;
  padding: 2px 9px;
  border-radius: 999px;
  white-space: nowrap;
}
.tlv-prio-urgent { background: rgba(212,70,56,0.12);  color: #C0392B; }
.tlv-prio-high   { background: rgba(232,122,30,0.14); color: #B85A0E; }
.tlv-prio-medium { background: rgba(43,87,151,0.12);  color: #2B5797; }
.tlv-prio-low    { background: var(--tk-n-100, #F3F0E8); color: #6B778C; }

/* Срок */
.tlv-due {
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  color: var(--tk-text-secondary, #534D40);
  white-space: nowrap;
}
.tlv-due-warn    { color: #B85A0E; }
.tlv-due-fire    { color: #E87A1E; font-weight: 700; }
.tlv-due-overdue { color: #C0392B; font-weight: 700; }

/* Исполнители */
.tlv-assignees { display: inline-flex; align-items: center; }
.tlv-bubble {
  width: 22px; height: 22px;
  border-radius: 50%;
  background: linear-gradient(135deg, #B0AAA0, #6E6657);
  color: #fff;
  font-size: 9.5px; font-weight: 700;
  display: inline-flex; align-items: center; justify-content: center;
  border: 1.5px solid #fff;
  margin-left: -6px;
  flex-shrink: 0;
}
.tlv-bubble:first-child { margin-left: 0; }
.tlv-bubble-done { background: linear-gradient(135deg, #4CAF50, #2E7D32); }
.tlv-bubble-more {
  font-size: 10px; font-weight: 700;
  color: var(--tk-text-muted, #6E6657);
  margin-left: 4px;
}

/* Метки */
.tlv-labels { display: inline-flex; flex-wrap: wrap; gap: 4px; }
.tlv-label {
  font-size: 10.5px; font-weight: 600;
  color: #fff;
  padding: 1px 8px;
  border-radius: 4px;
  max-width: 130px;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

/* Прогресс */
.tlv-cell-progress { display: flex; gap: 6px; flex-wrap: wrap; }
.tlv-stat {
  display: inline-flex; align-items: center; gap: 3px;
  font-size: 11px; font-weight: 700;
  color: var(--tk-text-muted, #6E6657);
  background: var(--tk-n-100, #F3F0E8);
  padding: 2px 7px;
  border-radius: 999px;
  white-space: nowrap;
}
.tlv-stat-done { color: #2E7D32; background: rgba(76,175,80,0.14); }

.tlv-muted { color: var(--tk-text-muted, #9C9384); }

/* Пусто */
.tlv-empty {
  display: flex; flex-direction: column; align-items: center;
  gap: 4px;
  padding: 56px 20px;
  color: var(--tk-text-muted, #9C9384);
  text-align: center;
}
.tlv-empty-title { font-size: 14px; font-weight: 700; color: var(--tk-text-secondary, #534D40); margin: 6px 0 0; }
.tlv-empty-sub { font-size: 12px; margin: 0; }

/* Адаптив */
@media (max-width: 760px) {
  .tlv { padding-right: 0; }
  .tlv-search { width: 100%; }
  .tlv-search-wrap { flex: 1; }
  .tlv-table { font-size: 12px; }
  .tlv-th, .tlv-td { padding: 7px 8px; }
}

/* ── Редактируемые ячейки ── */
.tlv-status.tlv-editable,
.tlv-cell-btn {
  border: none;
  background: transparent;
  font: inherit;
  color: inherit;
  cursor: pointer;
  border-radius: 6px;
  padding: 3px 6px;
  margin: -3px -6px;
  text-align: left;
  transition: background 120ms ease;
}
.tlv-cell-btn { display: inline-flex; align-items: center; }
.tlv-status.tlv-editable:hover,
.tlv-cell-btn:hover { background: var(--tk-accent-soft, rgba(232,122,30,0.12)); }
.tlv-caret {
  color: var(--tk-text-muted, #B0AAA0);
  opacity: 0;
  transition: opacity 120ms ease;
}
.tlv-row:hover .tlv-caret { opacity: 0.7; }

.tlv-prio-btn {
  border: none;
  font-family: inherit;
  cursor: pointer;
  transition: box-shadow 120ms ease, filter 120ms ease;
}
.tlv-prio-btn:hover {
  filter: brightness(0.96);
  box-shadow: 0 0 0 1.5px var(--tk-accent, #E87A1E);
}

/* ── Поповер-редактор ячейки ── */
.tlv-pop-layer { position: fixed; inset: 0; z-index: 1000; }
.tlv-pop {
  position: fixed;
  min-width: 180px;
  max-width: 280px;
  background: #fff;
  border: 1px solid var(--tk-border-soft, #EFEAE0);
  border-radius: 10px;
  box-shadow: 0 8px 24px -4px rgba(0,0,0,0.16);
  padding: 6px;
  font-family: inherit;
}
.tlv-pop-title {
  font-size: 10.5px; font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--tk-text-muted, #9C9384);
  padding: 4px 8px 6px;
}
.tlv-pop-item {
  display: flex; align-items: center; gap: 8px;
  width: 100%;
  padding: 7px 8px;
  border: none; background: transparent;
  border-radius: 6px;
  font-family: inherit; font-size: 12.5px;
  color: var(--tk-text, #1A1814);
  cursor: pointer;
  text-align: left;
  transition: background 120ms ease;
}
.tlv-pop-item:hover { background: var(--tk-accent-soft, rgba(232,122,30,0.10)); }
.tlv-pop-item.active { background: var(--tk-n-100, #F3F0E8); font-weight: 600; }
.tlv-pop-item-text { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tlv-prio-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.tlv-pop-scroll { max-height: 240px; overflow-y: auto; }
.tlv-pop-empty {
  padding: 10px 8px; text-align: center;
  font-size: 12px; color: var(--tk-text-muted, #9C9384);
}
.tlv-check {
  width: 16px; height: 16px;
  border: 1.5px solid var(--tk-border, #D8D2C4);
  border-radius: 4px;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 10px; color: transparent;
  flex-shrink: 0;
  transition: background 120ms ease, border-color 120ms ease;
}
.tlv-check.on {
  background: var(--tk-accent, #E87A1E);
  border-color: var(--tk-accent, #E87A1E);
  color: #fff;
}

/* Печать */
@media print {
  .tlv { padding: 0; overflow: visible; height: auto; }
  .tlv-toolbar { display: none; }
  .tlv-table-wrap { overflow: visible; border: none; }
  .tlv-row { cursor: default; }
  .tlv-th { position: static; }
}
</style>
