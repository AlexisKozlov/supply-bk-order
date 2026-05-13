<template>
  <div class="tc">
    <!-- Шапка с навигацией -->
    <div class="tc-toolbar">
      <div class="tc-nav-group">
        <button class="tc-nav-btn" @click="navPrev" :title="viewMode === 'week' ? 'Предыдущая неделя' : 'Предыдущий месяц'">
          <TaskIcon name="chevronRight" :size="16" style="transform: rotate(180deg)"/>
        </button>
        <button class="tc-today-btn" @click="goToday">Сегодня</button>
        <button class="tc-nav-btn" @click="navNext" :title="viewMode === 'week' ? 'Следующая неделя' : 'Следующий месяц'">
          <TaskIcon name="chevronRight" :size="16"/>
        </button>
      </div>
      <div class="tc-title">{{ viewMode === 'week' ? weekLabel : monthLabel }}</div>
      <div class="tc-spacer"></div>
      <div class="tc-counter" v-if="totalCardsInPeriod">
        <TaskIcon name="calendar" :size="13"/>
        <span>{{ totalCardsInPeriod }} {{ plural(totalCardsInPeriod, ['задача','задачи','задач']) }}</span>
      </div>
      <div class="tc-mode-toggle" role="group" aria-label="Режим календаря">
        <button class="tc-mode-btn" :class="{ active: viewMode === 'month' }" @click="setViewMode('month')">Месяц</button>
        <button class="tc-mode-btn" :class="{ active: viewMode === 'week' }" @click="setViewMode('week')">Неделя</button>
      </div>
    </div>

    <!-- Шапка дней недели -->
    <div class="tc-weekdays" :class="{ 'tc-weekdays-week': viewMode === 'week' }">
      <div v-for="(w, i) in (viewMode === 'week' ? weekGrid : weekdays.map(x => null))"
           :key="i"
           class="tc-weekday"
           :class="{ 'tc-weekend': i >= 5, 'tc-weekday-today': viewMode === 'week' && weekGrid[i]?.isToday }">
        <template v-if="viewMode === 'week'">
          <span class="tc-weekday-name">{{ weekdays[i] }}</span>
          <span class="tc-weekday-date">{{ weekGrid[i].date.getDate() }}.{{ String(weekGrid[i].date.getMonth() + 1).padStart(2,'0') }}</span>
        </template>
        <template v-else>{{ weekdays[i] }}</template>
      </div>
    </div>

    <!-- Сетка месяца -->
    <div v-if="viewMode === 'month'" class="tc-grid">
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
            <span class="tc-card-dot" :class="'tc-card-dot-' + (card.priority || 'medium')"></span>
            <span class="tc-card-title">{{ card.title }}</span>
            <span v-if="card.assignees?.length" class="tc-card-bubble" :title="card.assignees.join(', ')">{{ initials(card.assignees[0]) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Недельный вид: 7 колонок с расширенным пространством для карточек -->
    <div v-else class="tc-week-grid">
      <div v-for="day in weekGrid" :key="day.iso"
           class="tc-week-col"
           :class="{
             'tc-day-today': day.isToday,
             'tc-day-weekend': day.weekend,
             'tc-day-drop': dropDayIso === day.iso,
           }"
           @dragover.prevent="onDayDragOver($event, day)"
           @dragleave="onDayDragLeave(day)"
           @drop.prevent="onDayDrop($event, day)">
        <div class="tc-week-col-empty" v-if="!day.cards.length">— нет задач —</div>
        <div v-for="card in day.cards"
             :key="card.id"
             class="tc-card tc-card-week"
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

// 'month' | 'week' — режим календаря, запоминаем в localStorage
const viewMode = ref(localStorage.getItem('bk_tasks_cal_view_mode') === 'week' ? 'week' : 'month');
function setViewMode(m) {
  viewMode.value = m;
  try { localStorage.setItem('bk_tasks_cal_view_mode', m); } catch (_) {}
  // При переключении в неделю — выставляем viewDate на текущую неделю
  if (m === 'week') {
    weekAnchor.value = new Date(today);
  }
}

// Якорь для недельного режима (любая дата в выбранной неделе)
const weekAnchor = ref(new Date(today));

const monthLabel = computed(() => `${monthNames[viewDate.value.getMonth()]} ${viewDate.value.getFullYear()}`);
const weekLabel = computed(() => {
  const g = weekGrid.value;
  if (!g.length) return '';
  const a = g[0].date, b = g[6].date;
  const sameMonth = a.getMonth() === b.getMonth() && a.getFullYear() === b.getFullYear();
  const aTxt = `${a.getDate()} ${monthNames[a.getMonth()].slice(0,3).toLowerCase()}`;
  const bTxt = sameMonth
    ? `${b.getDate()} ${monthNames[b.getMonth()].slice(0,3).toLowerCase()} ${b.getFullYear()}`
    : `${b.getDate()} ${monthNames[b.getMonth()].slice(0,3).toLowerCase()} ${b.getFullYear()}`;
  return `${aTxt} — ${bTxt}`;
});

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

// Недельный грид: 7 дней начиная с понедельника недели, в которой weekAnchor
const weekGrid = computed(() => {
  const anchor = new Date(weekAnchor.value);
  anchor.setHours(0, 0, 0, 0);
  // Сдвигаем на понедельник
  const dow = (anchor.getDay() + 6) % 7;
  const start = new Date(anchor);
  start.setDate(anchor.getDate() - dow);
  const days = [];
  for (let i = 0; i < 7; i++) {
    const d = new Date(start);
    d.setDate(start.getDate() + i);
    d.setHours(0, 0, 0, 0);
    const iso = isoOf(d);
    days.push({
      date: d,
      iso,
      isToday: d.getTime() === today.getTime(),
      weekend: d.getDay() === 0 || d.getDay() === 6,
      cards: cardsByDate.value.get(iso) || [],
    });
  }
  return days;
});

const totalCardsInPeriod = computed(() => {
  if (viewMode.value === 'week') {
    return weekGrid.value.reduce((sum, d) => sum + d.cards.length, 0);
  }
  return monthGrid.value.filter(d => d.inMonth).reduce((sum, d) => sum + d.cards.length, 0);
});

function plural(n, forms) {
  const mod10 = n % 10, mod100 = n % 100;
  if (mod10 === 1 && mod100 !== 11) return forms[0];
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) return forms[1];
  return forms[2];
}

function navPrev() {
  if (viewMode.value === 'week') {
    const a = new Date(weekAnchor.value);
    a.setDate(a.getDate() - 7);
    weekAnchor.value = a;
  } else {
    viewDate.value = new Date(viewDate.value.getFullYear(), viewDate.value.getMonth() - 1, 1);
  }
  expandedDayIso.value = null;
}
function navNext() {
  if (viewMode.value === 'week') {
    const a = new Date(weekAnchor.value);
    a.setDate(a.getDate() + 7);
    weekAnchor.value = a;
  } else {
    viewDate.value = new Date(viewDate.value.getFullYear(), viewDate.value.getMonth() + 1, 1);
  }
  expandedDayIso.value = null;
}
function goToday() {
  viewDate.value = new Date(today.getFullYear(), today.getMonth(), 1);
  weekAnchor.value = new Date(today);
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
  gap: 10px;
  padding: 0 16px 12px 0;
  height: 100%;
  min-height: 0;
  box-sizing: border-box;
  overflow: hidden;
}

/* Шапка-тулбар */
.tc-toolbar {
  display: flex; align-items: center; gap: 10px;
  flex-wrap: wrap;
  flex-shrink: 0;
  padding: 4px 0;
}
.tc-nav-group {
  display: inline-flex; align-items: center;
  background: var(--tk-n-50, #FAF9F5);
  border: 1px solid var(--tk-border-soft, #EFEAE0);
  border-radius: 8px;
  padding: 2px;
  gap: 2px;
}
.tc-title {
  font-size: 17px;
  font-weight: 700;
  color: var(--tk-text, #1A1814);
  text-transform: capitalize;
  letter-spacing: 0.01em;
  min-width: 180px;
  padding-left: 4px;
}
.tc-nav-btn, .tc-today-btn {
  height: 28px; min-width: 28px;
  padding: 0 10px;
  display: inline-flex; align-items: center; justify-content: center;
  background: transparent; border: none;
  border-radius: 6px;
  cursor: pointer;
  color: var(--tk-text-secondary, #534D40);
  font-family: inherit; font-size: 12px;
  font-weight: 600;
  transition: background 140ms ease, color 140ms ease;
}
.tc-nav-btn:hover, .tc-today-btn:hover {
  background: #fff;
  color: var(--tk-text, #1A1814);
}
.tc-today-btn { padding: 0 12px; }
.tc-spacer { flex: 1; }

/* Счётчик задач периода */
.tc-counter {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 5px 10px;
  background: var(--tk-n-100, #F3F0E8);
  border-radius: 999px;
  font-size: 11.5px; font-weight: 600;
  color: var(--tk-text-secondary, #534D40);
}
.tc-counter :deep(svg) { color: var(--tk-accent, #E87A1E); }

/* Шапка дней недели */
.tc-weekdays {
  display: grid; grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 6px;
  font-size: 10.5px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--tk-text-muted, #9C9384);
  flex-shrink: 0;
}
.tc-weekday {
  padding: 6px 8px;
  min-width: 0;
}
.tc-weekday.tc-weekend { color: #B23B16; }
.tc-weekdays-week .tc-weekday {
  display: flex; flex-direction: column; gap: 2px;
  font-size: 12px;
  align-items: flex-start;
  padding: 8px 12px;
  border-radius: 8px;
  background: var(--tk-n-50, #FAF9F5);
}
.tc-weekdays-week .tc-weekday-today {
  background: var(--tk-accent-soft, rgba(232,122,30,0.12));
  color: var(--tk-accent-text, #B85A0E);
}
.tc-weekday-name {
  font-weight: 700;
  letter-spacing: 0.04em;
}
.tc-weekday-date {
  font-size: 14px; font-weight: 700;
  color: var(--tk-text, #1A1814);
  letter-spacing: normal;
  text-transform: none;
}
.tc-weekdays-week .tc-weekday-today .tc-weekday-date {
  color: var(--tk-accent-text, #B85A0E);
}

/* Недельный грид */
.tc-week-grid {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 8px;
  flex: 1;
  min-height: 0;
  overflow: hidden;
}
.tc-week-col {
  background: #fff;
  border: 1px solid var(--tk-border-soft, #EFEAE0);
  border-radius: 12px;
  padding: 10px;
  display: flex; flex-direction: column; gap: 6px;
  overflow-y: auto;
  min-width: 0;
  transition: background 160ms ease, border-color 160ms ease, box-shadow 160ms ease;
}
.tc-week-col:hover { box-shadow: 0 2px 6px rgba(15,23,42,0.05); }
.tc-week-col.tc-day-today {
  background: linear-gradient(180deg, var(--tk-accent-soft, rgba(232,122,30,0.10)) 0%, #fff 100%);
  border-color: color-mix(in srgb, var(--tk-accent, #E87A1E) 35%, var(--tk-border, #E6E1D7) 65%);
}
.tc-week-col.tc-day-weekend { background: var(--tk-n-50, #FAF9F5); }
.tc-week-col.tc-day-drop {
  background: var(--tk-accent-soft, rgba(232,122,30,0.12));
  border-color: var(--tk-accent, #E87A1E);
  border-style: dashed;
}
.tc-week-col-empty {
  font-size: 11px;
  color: var(--tk-text-muted, #9C9384);
  text-align: center;
  padding: 14px 0;
  font-style: italic;
}
.tc-card-week {
  padding: 7px 10px;
  font-size: 12.5px;
}

/* Переключатель «Месяц / Неделя» */
.tc-mode-toggle {
  display: inline-flex; align-items: center;
  background: var(--tk-n-100, #F3F0E8);
  border-radius: 8px;
  padding: 2px;
  gap: 0;
}
.tc-mode-btn {
  border: none; background: transparent;
  padding: 5px 14px; border-radius: 6px;
  font-family: inherit; font-size: 12px; font-weight: 600;
  color: var(--tk-text-muted, #6E6657); cursor: pointer;
  transition: background 140ms ease, color 140ms ease;
}
.tc-mode-btn:hover { color: var(--tk-text, #1A1814); }
.tc-mode-btn.active {
  background: #fff;
  color: var(--tk-accent-text, #B85A0E);
  box-shadow: 0 1px 2px rgba(15,23,42,0.08);
}

/* Сетка месяца */
.tc-grid {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  grid-auto-rows: minmax(78px, 1fr);
  gap: 6px;
  flex: 1;
  min-height: 0;
  overflow: hidden;
}
.tc-day {
  background: #fff;
  border: 1px solid var(--tk-border-soft, #EFEAE0);
  border-radius: 10px;
  padding: 6px 8px 8px;
  display: flex; flex-direction: column;
  min-height: 0;
  min-width: 0;
  overflow: hidden;
  transition: background 140ms ease, border-color 140ms ease, box-shadow 140ms ease;
}
.tc-day:hover { box-shadow: 0 2px 6px rgba(15,23,42,0.05); }
.tc-day-other {
  background: transparent;
  border-color: transparent;
  color: var(--tk-text-muted, #9C9384);
}
.tc-day-other .tc-day-num { opacity: 0.5; }
.tc-day-other:hover { box-shadow: none; }
.tc-day-weekend:not(.tc-day-other) { background: var(--tk-n-50, #FAF9F5); }
.tc-day-today {
  background: linear-gradient(180deg, var(--tk-accent-soft, rgba(232,122,30,0.10)) 0%, #fff 60%);
  border-color: color-mix(in srgb, var(--tk-accent, #E87A1E) 35%, var(--tk-border, #E6E1D7) 65%);
}
.tc-day-today .tc-day-num {
  background: var(--tk-accent, #E87A1E);
  color: #fff;
  border-radius: 999px;
  width: 22px; height: 22px;
  display: inline-flex; align-items: center; justify-content: center;
  font-weight: 700;
}
.tc-day-drop {
  background: var(--tk-accent-soft, rgba(232,122,30,0.12)) !important;
  border-color: var(--tk-accent, #E87A1E) !important;
  border-style: dashed !important;
}

.tc-day-head {
  display: flex; align-items: center; justify-content: space-between;
  font-size: 12px; font-weight: 700;
  color: var(--tk-text, #1A1814);
  margin-bottom: 4px;
  min-height: 22px;
}
.tc-day-weekend:not(.tc-day-other):not(.tc-day-today) .tc-day-num {
  color: #B23B16;
}
.tc-day-other .tc-day-head { color: var(--tk-text-muted, #9C9384); }
.tc-day-more {
  font-size: 10.5px; font-weight: 700;
  color: var(--tk-accent-text, #B85A0E);
  cursor: pointer;
  padding: 1px 7px;
  border-radius: 999px;
  background: var(--tk-accent-soft, rgba(232,122,30,0.10));
  transition: background 140ms ease;
}
.tc-day-more:hover { background: color-mix(in srgb, var(--tk-accent, #E87A1E) 20%, transparent); }

.tc-day-cards {
  display: flex; flex-direction: column; gap: 3px;
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  scrollbar-width: thin;
}
.tc-day-cards::-webkit-scrollbar { width: 4px; }
.tc-day-cards::-webkit-scrollbar-thumb { background: var(--tk-border, #E6E1D7); border-radius: 4px; }

/* Карточка-пилюля в ячейке дня */
.tc-card {
  display: flex; align-items: center; gap: 5px;
  background: var(--tk-n-50, #FAF9F5);
  border: 1px solid var(--tk-border-soft, #EFEAE0);
  padding: 4px 7px;
  font-size: 11px;
  font-weight: 600;
  border-radius: 6px;
  cursor: pointer;
  user-select: none;
  color: var(--tk-text, #1A1814);
  transition: background 140ms ease, transform 100ms ease, box-shadow 140ms ease, border-color 140ms ease;
}
.tc-card:hover {
  background: #fff;
  border-color: var(--tk-border, #E6E1D7);
  transform: translateY(-1px);
  box-shadow: 0 2px 6px rgba(15,23,42,0.08);
}
.tc-card-dot {
  width: 7px; height: 7px;
  border-radius: 50%;
  flex-shrink: 0;
  background: var(--tk-text-muted, #9C9384);
}
.tc-card-dot-urgent { background: #D44638; }
.tc-card-dot-high   { background: #E87A1E; }
.tc-card-dot-medium { background: #2B5797; }
.tc-card-dot-low    { background: #6B778C; }

.tc-card-done { opacity: 0.5; text-decoration: line-through; }
.tc-card-overdue {
  border-color: #D44638;
  background: rgba(212,70,56,0.04);
}
.tc-card-overdue .tc-card-dot { box-shadow: 0 0 0 2px rgba(212,70,56,0.25); }

.tc-card-title {
  flex: 1; min-width: 0;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.tc-card-bubble {
  width: 16px; height: 16px;
  border-radius: 50%;
  background: linear-gradient(135deg, #B0AAA0, #6E6657);
  color: #fff;
  font-size: 9px; font-weight: 700;
  display: inline-flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  border: 1.5px solid #fff;
}

/* Блок «Без срока» */
.tc-undated {
  border: 1px solid var(--tk-border-soft, #EFEAE0);
  border-radius: 12px;
  background: #fff;
  transition: background 140ms ease, border-color 140ms ease;
  flex-shrink: 0;
  max-height: 30%;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}
.tc-undated.open .tc-undated-list { overflow-y: auto; }
.tc-undated.tc-day-drop {
  background: var(--tk-accent-soft, rgba(232,122,30,0.12));
  border-color: var(--tk-accent, #E87A1E);
  border-style: dashed;
}
.tc-undated-head {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 14px;
  cursor: pointer;
  font-size: 12.5px; font-weight: 700;
  color: var(--tk-text, #1A1814);
  user-select: none;
  transition: background 140ms ease;
}
.tc-undated-head:hover { background: var(--tk-n-50, #FAF9F5); }
.tc-undated-hint {
  margin-left: auto;
  font-size: 11px; color: var(--tk-text-muted, #9C9384);
  font-weight: 500;
}
.tc-undated-list {
  display: flex; flex-wrap: wrap; gap: 6px;
  padding: 0 14px 12px;
}
.tc-undated-list .tc-card { width: 220px; flex-shrink: 0; }

/* Адаптив */
@media (max-width: 760px) {
  .tc-counter { display: none; }
  .tc-title { min-width: 140px; font-size: 15px; }
  .tc-day { padding: 4px 5px 5px; }
  .tc-grid {
    grid-auto-rows: minmax(64px, auto);
    gap: 4px;
  }
  .tc-card { font-size: 10.5px; padding: 3px 5px; }
  .tc-card-bubble { display: none; }
  .tc-day-today .tc-day-num { width: 20px; height: 20px; font-size: 11px; }
}
</style>
