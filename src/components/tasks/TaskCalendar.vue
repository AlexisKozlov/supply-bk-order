<template>
  <div class="tc">
    <!-- Шапка с навигацией -->
    <div class="tc-toolbar">
      <button class="tc-nav-btn" @click="prevMonth" title="Предыдущий месяц">
        <TaskIcon name="chevronRight" :size="16" style="transform: rotate(180deg)"/>
      </button>
      <button class="tc-today-btn" @click="goToday">Сегодня</button>
      <button class="tc-nav-btn" @click="nextMonth" title="Следующий месяц">
        <TaskIcon name="chevronRight" :size="16"/>
      </button>
      <div class="tc-title">{{ monthLabel }}</div>
      <div class="tc-spacer"></div>
      <div class="tc-legend">
        <span class="tc-legend-item"><span class="tc-legend-dot tc-prio-urgent"></span> Срочно</span>
        <span class="tc-legend-item"><span class="tc-legend-dot tc-prio-high"></span> Высокий</span>
        <span class="tc-legend-item"><span class="tc-legend-dot tc-prio-medium"></span> Средний</span>
        <span class="tc-legend-item"><span class="tc-legend-dot tc-prio-low"></span> Низкий</span>
      </div>
    </div>

    <!-- Шапка дней недели -->
    <div class="tc-weekdays">
      <div v-for="(w, i) in weekdays" :key="i" class="tc-weekday" :class="{ 'tc-weekend': i >= 5 }">{{ w }}</div>
    </div>

    <!-- Сетка месяца -->
    <div class="tc-grid">
      <div v-for="day in monthGrid" :key="day.iso"
           class="tc-day"
           :class="{
             'tc-day-other': !day.inMonth,
             'tc-day-today': day.isToday,
             'tc-day-weekend': day.weekend,
             'tc-day-drop': dropDayIso === day.iso,
           }"
           @dragover.prevent="onDayDragOver($event, day)"
           @dragleave="onDayDragLeave(day)"
           @drop.prevent="onDayDrop($event, day)">
        <div class="tc-day-head">
          <span class="tc-day-num">{{ day.date.getDate() }}</span>
          <span v-if="day.cards.length > 3" class="tc-day-more" @click="onShowMore(day)">+{{ day.cards.length - 3 }}</span>
        </div>
        <div class="tc-day-cards">
          <div v-for="card in (expandedDayIso === day.iso ? day.cards : day.cards.slice(0, 3))"
               :key="card.id"
               class="tc-card"
               :class="['prio-bg-' + (card.priority || 'medium'), { 'tc-card-done': card.is_done, 'tc-card-overdue': isOverdue(card) }]"
               draggable="true"
               @click.stop="$emit('open-card', card.id)"
               @dragstart="onCardDragStart($event, card)"
               @dragend="onCardDragEnd">
            <span class="tc-card-title">{{ card.title }}</span>
            <span v-if="card.assignees?.length" class="tc-card-bubble" :title="card.assignees.join(', ')">{{ initials(card.assignees[0]) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Карточки без срока -->
    <div v-if="undatedCards.length" class="tc-undated" :class="{ open: undatedOpen, 'tc-day-drop': dropUndated }"
         @dragover.prevent="onUndatedDragOver"
         @dragleave="dropUndated = false"
         @drop.prevent="onUndatedDrop">
      <div class="tc-undated-head" @click="undatedOpen = !undatedOpen">
        <TaskIcon :name="undatedOpen ? 'chevronDown' : 'chevronRight'" :size="14"/>
        <span>Без срока ({{ undatedCards.length }})</span>
        <span class="tc-undated-hint">перетащите сюда, чтобы убрать срок</span>
      </div>
      <div v-if="undatedOpen" class="tc-undated-list">
        <div v-for="card in undatedCards" :key="card.id"
             class="tc-card"
             :class="['prio-bg-' + (card.priority || 'medium'), { 'tc-card-done': card.is_done }]"
             draggable="true"
             @click.stop="$emit('open-card', card.id)"
             @dragstart="onCardDragStart($event, card)"
             @dragend="onCardDragEnd">
          <span class="tc-card-title">{{ card.title }}</span>
          <span v-if="card.assignees?.length" class="tc-card-bubble" :title="card.assignees.join(', ')">{{ initials(card.assignees[0]) }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import TaskIcon from './TaskIcon.vue';

const props = defineProps({
  cards: { type: Array, required: true },
});
const emit = defineEmits(['open-card', 'update-due']);

const weekdays = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
const monthNames = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];

const today = new Date();
today.setHours(0, 0, 0, 0);

const viewDate = ref(new Date(today.getFullYear(), today.getMonth(), 1));
const expandedDayIso = ref(null);
const undatedOpen = ref(false);
const dropDayIso = ref(null);
const dropUndated = ref(false);
let draggedCardId = null;

const monthLabel = computed(() => `${monthNames[viewDate.value.getMonth()]} ${viewDate.value.getFullYear()}`);

function isoOf(d) {
  const pad = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

const cardsByDate = computed(() => {
  const map = new Map();
  for (const c of props.cards) {
    if (c.is_archived) continue;
    if (c.parent_card_id) continue;
    if (!c.due_date) continue;
    const d = new Date(c.due_date);
    if (isNaN(d)) continue;
    const k = isoOf(d);
    if (!map.has(k)) map.set(k, []);
    map.get(k).push(c);
  }
  // сортировка карточек в дне: незавершённые → выполненные, приоритет → по дате
  const prioRank = { urgent: 0, high: 1, medium: 2, low: 3 };
  for (const list of map.values()) {
    list.sort((a, b) => {
      if ((a.is_done || 0) !== (b.is_done || 0)) return (a.is_done || 0) - (b.is_done || 0);
      const pa = prioRank[a.priority || 'medium'];
      const pb = prioRank[b.priority || 'medium'];
      if (pa !== pb) return pa - pb;
      return new Date(a.due_date) - new Date(b.due_date);
    });
  }
  return map;
});

const undatedCards = computed(() => props.cards.filter(c => !c.is_archived && !c.parent_card_id && !c.due_date));

const monthGrid = computed(() => {
  const first = new Date(viewDate.value);
  first.setDate(1);
  // Сдвиг чтобы понедельник = 0
  const dow = (first.getDay() + 6) % 7;
  const start = new Date(first);
  start.setDate(1 - dow);
  const days = [];
  for (let i = 0; i < 42; i++) {
    const d = new Date(start);
    d.setDate(start.getDate() + i);
    d.setHours(0, 0, 0, 0);
    const iso = isoOf(d);
    days.push({
      date: d,
      iso,
      inMonth: d.getMonth() === viewDate.value.getMonth(),
      isToday: d.getTime() === today.getTime(),
      weekend: d.getDay() === 0 || d.getDay() === 6,
      cards: cardsByDate.value.get(iso) || [],
    });
  }
  return days;
});

function prevMonth() {
  viewDate.value = new Date(viewDate.value.getFullYear(), viewDate.value.getMonth() - 1, 1);
  expandedDayIso.value = null;
}
function nextMonth() {
  viewDate.value = new Date(viewDate.value.getFullYear(), viewDate.value.getMonth() + 1, 1);
  expandedDayIso.value = null;
}
function goToday() {
  viewDate.value = new Date(today.getFullYear(), today.getMonth(), 1);
  expandedDayIso.value = null;
}

function onShowMore(day) {
  expandedDayIso.value = expandedDayIso.value === day.iso ? null : day.iso;
}

function isOverdue(c) {
  if (c.is_done || !c.due_date) return false;
  return new Date(c.due_date) < today;
}

function initials(name) {
  if (!name) return '?';
  const parts = String(name).trim().split(/\s+/);
  return ((parts[0]?.[0] || '') + (parts[1]?.[0] || '')).toUpperCase() || '?';
}

function onCardDragStart(e, card) {
  draggedCardId = card.id;
  try {
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', String(card.id));
  } catch (_) {}
}
function onCardDragEnd() {
  draggedCardId = null;
  dropDayIso.value = null;
  dropUndated.value = false;
}

function onDayDragOver(e, day) {
  if (!draggedCardId) return;
  e.dataTransfer.dropEffect = 'move';
  dropDayIso.value = day.iso;
}
function onDayDragLeave(day) {
  if (dropDayIso.value === day.iso) dropDayIso.value = null;
}
function onDayDrop(e, day) {
  dropDayIso.value = null;
  const id = draggedCardId || parseInt(e.dataTransfer.getData('text/plain'), 10);
  if (!id) return;
  const card = props.cards.find(c => c.id === id);
  if (!card) return;
  // Сохраняем время существующего due_date или ставим 12:00 по умолчанию
  const newDate = new Date(day.date);
  if (card.due_date) {
    const prev = new Date(card.due_date);
    newDate.setHours(prev.getHours(), prev.getMinutes(), 0, 0);
  } else {
    newDate.setHours(12, 0, 0, 0);
  }
  const pad = n => String(n).padStart(2, '0');
  const iso = `${newDate.getFullYear()}-${pad(newDate.getMonth() + 1)}-${pad(newDate.getDate())} ${pad(newDate.getHours())}:${pad(newDate.getMinutes())}:00`;
  emit('update-due', id, iso);
}

function onUndatedDragOver() {
  if (!draggedCardId) return;
  dropUndated.value = true;
}
function onUndatedDrop(e) {
  dropUndated.value = false;
  const id = draggedCardId || parseInt(e.dataTransfer.getData('text/plain'), 10);
  if (!id) return;
  emit('update-due', id, null);
}
</script>

<style scoped>
.tc {
  display: flex; flex-direction: column;
  gap: var(--tk-s-2);
  padding: 0 var(--tk-s-4) var(--tk-s-3) 0;
  height: 100%;
  min-height: 0;
  box-sizing: border-box;
  overflow: hidden;
}

.tc-toolbar {
  display: flex; align-items: center; gap: var(--tk-s-2);
  flex-wrap: wrap;
  flex-shrink: 0;
}
.tc-title {
  font-size: 17px;
  font-weight: var(--tk-fw-bold);
  color: var(--tk-text);
  text-transform: capitalize;
  letter-spacing: 0.02em;
  min-width: 160px;
}
.tc-nav-btn, .tc-today-btn {
  height: 30px; min-width: 30px;
  padding: 0 var(--tk-s-2);
  display: inline-flex; align-items: center; justify-content: center;
  background: var(--tk-n-0); border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-sm);
  cursor: pointer;
  color: var(--tk-text);
  font-family: inherit; font-size: var(--tk-fz-sm, 12px);
  font-weight: var(--tk-fw-semibold);
  transition: background var(--tk-transition), border-color var(--tk-transition);
}
.tc-nav-btn:hover, .tc-today-btn:hover { background: var(--tk-n-100); border-color: var(--tk-n-300); }
.tc-spacer { flex: 1; }
.tc-legend { display: flex; gap: 10px; font-size: 11px; color: var(--tk-text-muted); flex-wrap: wrap; }
.tc-legend-item { display: inline-flex; align-items: center; gap: 4px; }
.tc-legend-dot {
  width: 8px; height: 8px; border-radius: 50%;
  display: inline-block;
}
.tc-prio-urgent { background: var(--tk-prio-urgent, #D44638); }
.tc-prio-high { background: var(--tk-prio-high, #E87A1E); }
.tc-prio-medium { background: var(--tk-prio-medium, #2B5797); }
.tc-prio-low { background: var(--tk-prio-low, #6B778C); }

.tc-weekdays {
  display: grid; grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 4px;
  font-size: 11px;
  font-weight: var(--tk-fw-semibold);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--tk-text-muted);
  flex-shrink: 0;
}
.tc-weekday { padding: 4px 6px; min-width: 0; }
.tc-weekday.tc-weekend { color: var(--tk-prio-urgent, #D44638); }

.tc-grid {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  grid-auto-rows: minmax(72px, 1fr);
  gap: 4px;
  flex: 1;
  min-height: 0;
  overflow: hidden;
}
.tc-day {
  background: var(--tk-n-0);
  border: 1px solid var(--tk-border-soft);
  border-radius: var(--tk-r-sm);
  padding: 4px 6px 6px;
  display: flex; flex-direction: column;
  min-height: 0;
  min-width: 0;
  overflow: hidden;
  transition: background 120ms ease, border-color 120ms ease;
}
.tc-day-other {
  background: transparent;
  color: var(--tk-text-muted);
  border-color: transparent;
}
.tc-day-other .tc-day-num { opacity: 0.55; }
.tc-day-weekend:not(.tc-day-other) { background: var(--tk-n-50, #F7F8F9); }
.tc-day-today {
  border-color: var(--tk-accent, #E87A1E);
  box-shadow: 0 0 0 1px var(--tk-accent, #E87A1E);
}
.tc-day-today .tc-day-num {
  background: var(--tk-accent, #E87A1E);
  color: #fff;
  border-radius: 999px;
  padding: 1px 7px;
  display: inline-block;
}
.tc-day-drop { background: var(--tk-accent-soft, #FEEFE0); border-color: var(--tk-accent); }

.tc-day-head {
  display: flex; align-items: center; justify-content: space-between;
  font-size: 12px; font-weight: var(--tk-fw-semibold);
  color: var(--tk-text);
  margin-bottom: 3px;
}
.tc-day-other .tc-day-head { color: var(--tk-text-muted); }
.tc-day-more {
  font-size: 10px; font-weight: var(--tk-fw-semibold);
  color: var(--tk-text-muted);
  cursor: pointer;
  padding: 1px 5px;
  border-radius: 999px;
}
.tc-day-more:hover { background: var(--tk-n-100); color: var(--tk-text); }

.tc-day-cards {
  display: flex; flex-direction: column; gap: 2px;
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  scrollbar-width: thin;
}
.tc-day-cards::-webkit-scrollbar { width: 4px; }
.tc-day-cards::-webkit-scrollbar-thumb { background: var(--tk-n-300); border-radius: 4px; }

.tc-card {
  display: flex; align-items: center; gap: 4px;
  background: var(--tk-n-0);
  border-left: 3px solid var(--tk-n-300);
  padding: 2px 6px;
  font-size: 11px;
  font-weight: var(--tk-fw-semibold);
  border-radius: 3px;
  cursor: pointer;
  user-select: none;
  box-shadow: 0 1px 2px rgba(9,30,66,0.08);
  transition: transform 100ms ease, box-shadow 100ms ease;
}
.tc-card:hover { transform: translateY(-1px); box-shadow: 0 2px 6px rgba(9,30,66,0.15); }
.tc-card.prio-bg-urgent { border-left-color: var(--tk-prio-urgent); background: rgba(212,70,56,0.06); }
.tc-card.prio-bg-high   { border-left-color: var(--tk-prio-high);   background: rgba(232,122,30,0.06); }
.tc-card.prio-bg-medium { border-left-color: var(--tk-prio-medium); background: rgba(43,87,151,0.05); }
.tc-card.prio-bg-low    { border-left-color: var(--tk-prio-low);    background: rgba(107,119,140,0.06); }
.tc-card-done { opacity: 0.55; text-decoration: line-through; }
.tc-card-overdue {
  outline: 1.5px solid var(--tk-prio-urgent, #D44638);
}
.tc-card-title {
  flex: 1; min-width: 0;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.tc-card-bubble {
  width: 16px; height: 16px;
  border-radius: 50%;
  background: var(--tk-n-300);
  color: #fff;
  font-size: 9px; font-weight: var(--tk-fw-bold);
  display: inline-flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}

.tc-undated {
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-sm);
  background: var(--tk-n-0);
  transition: background 120ms ease, border-color 120ms ease;
  flex-shrink: 0;
  max-height: 30%;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}
.tc-undated.open .tc-undated-list { overflow-y: auto; }
.tc-undated.tc-day-drop { background: var(--tk-accent-soft, #FEEFE0); border-color: var(--tk-accent); }
.tc-undated-head {
  display: flex; align-items: center; gap: 6px;
  padding: var(--tk-s-2) var(--tk-s-3);
  cursor: pointer;
  font-size: 13px; font-weight: var(--tk-fw-semibold);
  color: var(--tk-text);
  user-select: none;
}
.tc-undated-hint { margin-left: auto; font-size: 11px; color: var(--tk-text-muted); font-weight: normal; }
.tc-undated-list {
  display: flex; flex-wrap: wrap; gap: 4px;
  padding: 0 var(--tk-s-3) var(--tk-s-3);
}
.tc-undated-list .tc-card { width: 220px; flex-shrink: 0; }

@media (max-width: 760px) {
  .tc-legend { display: none; }
  .tc-day { min-height: 70px; padding: 3px 4px; }
  .tc-grid { grid-auto-rows: minmax(70px, auto); }
  .tc-card { font-size: 10px; padding: 1px 4px; }
}
</style>
