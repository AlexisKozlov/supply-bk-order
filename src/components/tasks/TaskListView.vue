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
            <!-- Статус -->
            <td class="tlv-td">
              <span class="tlv-status">
                <span class="tlv-status-dot" :style="{ background: columnColor(card) }"></span>
                {{ columnTitle(card) }}
              </span>
            </td>
            <!-- Приоритет -->
            <td class="tlv-td">
              <span class="tlv-prio" :class="'tlv-prio-' + (card.priority || 'medium')">
                {{ prioLabel(card.priority) }}
              </span>
            </td>
            <!-- Срок -->
            <td class="tlv-td">
              <span v-if="card.due_date" class="tlv-due" :class="dueClass(card)">{{ fmtDue(card.due_date) }}</span>
              <span v-else class="tlv-muted">—</span>
            </td>
            <!-- Исполнители -->
            <td class="tlv-td">
              <span v-if="card.assignees && card.assignees.length" class="tlv-assignees">
                <span v-for="(n, i) in card.assignees.slice(0, 4)" :key="i"
                      class="tlv-bubble"
                      :class="{ 'tlv-bubble-done': (card.assignees_done || []).includes(n) }"
                      :title="n + ((card.assignees_done || []).includes(n) ? ' — выполнил' : '')">
                  {{ initials(n) }}
                </span>
                <span v-if="card.assignees.length > 4" class="tlv-bubble-more">+{{ card.assignees.length - 4 }}</span>
              </span>
              <span v-else class="tlv-muted">—</span>
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
  </div>
</template>

<script setup>
import { ref, computed, h } from 'vue';
import TaskIcon from './TaskIcon.vue';

const props = defineProps({
  cards:   { type: Array, default: () => [] },
  columns: { type: Array, default: () => [] },
  labels:  { type: Array, default: () => [] },
});
defineEmits(['open-card']);

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

/* Печать */
@media print {
  .tlv { padding: 0; overflow: visible; height: auto; }
  .tlv-toolbar { display: none; }
  .tlv-table-wrap { overflow: visible; border: none; }
  .tlv-row { cursor: default; }
  .tlv-th { position: static; }
}
</style>
