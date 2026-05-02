<template>
  <div class="mytasks-view">
    <header class="mytasks-header">
      <h1 class="mytasks-title">
        <BkIcon name="user" size="md"/>
        Мои задачи
      </h1>
      <div class="mytasks-stats">
        <span class="stat overdue" v-if="byBucket.overdue.length">{{ byBucket.overdue.length }} просрочено</span>
        <span class="stat today" v-if="byBucket.today.length">{{ byBucket.today.length }} сегодня</span>
        <span class="stat week" v-if="byBucket.week.length">{{ byBucket.week.length }} на неделе</span>
        <span class="stat total">всего {{ openCards.length }}</span>
      </div>
      <div class="mytasks-filters">
        <button class="mt-filter-btn" :class="{ active: showDone }" @click="showDone = !showDone">
          {{ showDone ? '☑' : '☐' }} Показать выполненные
        </button>
      </div>
    </header>

    <div v-if="loading" class="mytasks-loading">Загрузка…</div>
    <div v-else-if="!cards.length" class="mytasks-empty">
      <p>Пока никаких задач. Создайте первую на странице <router-link :to="{ name: 'tasks' }">Задачи</router-link>.</p>
    </div>

    <div v-else class="mytasks-buckets">
      <section v-for="bucket in visibleBuckets" :key="bucket.key" class="mt-bucket">
        <header class="mt-bucket-header" @click="toggleBucket(bucket.key)">
          <span class="mt-bucket-arrow">{{ collapsedBuckets[bucket.key] ? '▸' : '▾' }}</span>
          <span class="mt-bucket-title" :class="bucket.key">{{ bucket.label }}</span>
          <span class="mt-bucket-count">{{ bucket.cards.length }}</span>
        </header>
        <div v-if="!collapsedBuckets[bucket.key]" class="mt-bucket-list">
          <div v-for="card in bucket.cards" :key="card.id" class="mt-card"
               :class="['prio-' + card.priority, { 'is-done': card.is_done, 'is-overdue': isOverdue(card) && !card.is_done }]"
               @click="openCard(card)">
            <input type="checkbox" :checked="!!card.is_done" @click.stop @change="toggleDone(card)" class="mt-round-chk" />
            <div class="mt-card-main">
              <div class="mt-card-title">{{ card.title }}</div>
              <div class="mt-card-meta">
                <span class="mt-card-board">{{ card.board_title }} · {{ card.column_title }}</span>
                <span v-if="card.due_date" class="mt-card-due" :class="dueClass(card)">
                  {{ formatDue(card.due_date) }}
                </span>
                <span v-if="card.priority !== 'medium'" class="mt-card-prio" :class="'prio-bg-' + card.priority">
                  {{ priorityLabel(card.priority) }}
                </span>
                <span v-if="card.parent_card_id" class="mt-card-sub">подзадача</span>
                <span v-if="card.assignees?.length" class="mt-card-assignees">
                  <span v-for="(n, i) in card.assignees.slice(0,3)" :key="i" class="mt-bubble" :title="n">{{ initials(n) }}</span>
                </span>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>

    <!-- Сайдбар карточки (с указанием доски) -->
    <TaskCardModal v-if="openedCardId" :card-id="openedCardId"
                   @close="openedCardId = null; load()"
                   @open-card="id => openedCardId = id"
                   @deleted="load" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { tasksApi } from '@/lib/tasksApi.js';
import { useTasksStore } from '@/stores/tasksStore.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import TaskCardModal from '@/components/tasks/TaskCardModal.vue';

const cards = ref([]);
const loading = ref(false);
const showDone = ref(false);
const openedCardId = ref(null);
const collapsedBuckets = ref({});
const store = useTasksStore();

async function load() {
  loading.value = true;
  try {
    const r = await tasksApi.myCards();
    cards.value = r.cards || [];
  } catch (e) {
    alert('Ошибка загрузки: ' + e.message);
  } finally {
    loading.value = false;
  }
}

onMounted(load);

const openCards = computed(() => cards.value.filter(c => !c.is_done));
const doneCards = computed(() => cards.value.filter(c => c.is_done));

function isOverdue(c) {
  if (!c.due_date) return false;
  return new Date(c.due_date) < new Date();
}

const byBucket = computed(() => {
  const now = new Date();
  const today = new Date(now); today.setHours(0,0,0,0);
  const tomorrow = new Date(today); tomorrow.setDate(today.getDate() + 1);
  const weekEnd = new Date(today); weekEnd.setDate(today.getDate() + 7);

  const r = { overdue: [], today: [], tomorrow: [], week: [], later: [], nodate: [], done: [] };
  for (const c of cards.value) {
    if (c.is_done) { r.done.push(c); continue; }
    if (!c.due_date) { r.nodate.push(c); continue; }
    const d = new Date(c.due_date);
    if (d < now) r.overdue.push(c);
    else if (d < tomorrow) r.today.push(c);
    else {
      const dDay = new Date(d); dDay.setHours(0,0,0,0);
      const tomorrowDay = new Date(tomorrow);
      if (dDay.getTime() === tomorrowDay.getTime()) r.tomorrow.push(c);
      else if (d < weekEnd) r.week.push(c);
      else r.later.push(c);
    }
  }
  return r;
});

const buckets = computed(() => [
  { key: 'overdue',  label: '🔴 Просрочено',     cards: byBucket.value.overdue },
  { key: 'today',    label: '🟠 Сегодня',         cards: byBucket.value.today },
  { key: 'tomorrow', label: '🟡 Завтра',          cards: byBucket.value.tomorrow },
  { key: 'week',     label: '🟢 На этой неделе',  cards: byBucket.value.week },
  { key: 'later',    label: '⚪ Позже',           cards: byBucket.value.later },
  { key: 'nodate',   label: '— Без срока',        cards: byBucket.value.nodate },
  { key: 'done',     label: '✓ Выполненные',     cards: byBucket.value.done },
]);

const visibleBuckets = computed(() => {
  return buckets.value.filter(b => {
    if (b.key === 'done') return showDone.value && b.cards.length;
    return b.cards.length;
  });
});

function toggleBucket(key) { collapsedBuckets.value[key] = !collapsedBuckets.value[key]; }

function dueClass(c) {
  if (c.is_done) return '';
  if (isOverdue(c)) return 'overdue';
  const now = new Date(), d = new Date(c.due_date);
  const hours = (d - now) / 3600000;
  if (hours < 24) return 'urgent';
  if (hours < 72) return 'soon';
  return '';
}
function formatDue(s) {
  if (!s) return '';
  const d = new Date(s);
  const today = new Date(); today.setHours(0,0,0,0);
  const dDay = new Date(d); dDay.setHours(0,0,0,0);
  const diff = (dDay - today) / 86400000;
  if (diff === 0) return 'Сегодня ' + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  if (diff === 1) return 'Завтра';
  if (diff === -1) return 'Вчера';
  if (diff < 0) return Math.abs(diff) + ' дн назад';
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
}
function priorityLabel(p) { return ({ low: 'низ', medium: 'ср', high: 'выс', urgent: '!' })[p] || ''; }
function initials(n) { return (n || '').split(/\s+/).filter(Boolean).map(w => w[0]).join('').slice(0, 2).toUpperCase(); }

async function openCard(card) {
  // Загрузим доску в фоновом режиме, чтобы при открытии карточки были колонки/метки
  if (store.currentBoardId !== card.board_id) {
    await store.loadBoard(card.board_id);
  }
  openedCardId.value = card.id;
}

async function toggleDone(card) {
  try {
    const newVal = card.is_done ? 0 : 1;
    await tasksApi.updateCard(card.id, { is_done: newVal });
    card.is_done = newVal;
  } catch (e) { alert(e.message); }
}
</script>

<style scoped>
.mytasks-view {
  padding: 22px;
  max-width: 1100px;
  margin: 0 auto;
  height: 100%;
  overflow-y: auto;
}
.mytasks-header {
  display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
  margin-bottom: 22px;
  padding-bottom: 12px;
  border-bottom: 2px solid var(--border-light);
}
.mytasks-title {
  display: flex; align-items: center; gap: 8px;
  font-size: 22px; font-weight: 700; margin: 0;
}
.mytasks-stats { display: flex; gap: 8px; flex-wrap: wrap; }
.stat {
  font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 12px;
  background: var(--bg-secondary, #f0f1f3); color: var(--text);
}
.stat.overdue { background: #FFEBEE; color: #C62828; }
.stat.today   { background: #FFF3E0; color: #E65100; }
.stat.week    { background: #E8F5E9; color: #2E7D32; }
.stat.total   { background: rgba(0,0,0,0.06); color: var(--text-muted); }

.mytasks-filters { margin-left: auto; }
.mt-filter-btn {
  background: none; border: 1px solid var(--border-light);
  padding: 6px 12px; border-radius: 6px; cursor: pointer;
  font-size: 13px; color: var(--text); font-family: inherit;
}
.mt-filter-btn:hover { background: var(--bg-secondary, #f5f5f5); }
.mt-filter-btn.active { background: rgba(232,122,30,0.1); border-color: var(--bk-orange, #E87A1E); color: var(--bk-orange); font-weight: 600; }

.mytasks-loading, .mytasks-empty {
  text-align: center; padding: 60px; color: var(--text-muted);
}

.mytasks-buckets { display: flex; flex-direction: column; gap: 16px; }
.mt-bucket {
  background: #fff; border-radius: 10px;
  border: 1px solid var(--border-light);
  overflow: hidden;
}
.mt-bucket-header {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 14px; cursor: pointer; user-select: none;
  background: var(--bg-secondary, #fafbfc);
  border-bottom: 1px solid var(--border-light);
}
.mt-bucket-header:hover { background: #f0f1f3; }
.mt-bucket-arrow { font-size: 11px; color: var(--text-muted); width: 12px; }
.mt-bucket-title { font-size: 14px; font-weight: 700; }
.mt-bucket-title.overdue { color: #C62828; }
.mt-bucket-title.today { color: #E65100; }
.mt-bucket-title.tomorrow { color: #F9A825; }
.mt-bucket-title.week { color: #2E7D32; }
.mt-bucket-title.done { color: var(--text-muted); }
.mt-bucket-count {
  margin-left: auto;
  background: rgba(0,0,0,0.07); color: var(--text-muted);
  font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 10px;
}
.mt-bucket-list { display: flex; flex-direction: column; }

.mt-card {
  display: flex; align-items: flex-start; gap: 10px;
  padding: 10px 14px;
  cursor: pointer;
  border-bottom: 1px solid var(--border-light);
  border-left: 3px solid #4FC3F7;
  transition: background .12s;
}
.mt-card:last-child { border-bottom: none; }
.mt-card:hover { background: rgba(79,195,247,0.06); }
.mt-card.is-done { opacity: 0.6; background: #FAFAFA; }
.mt-card.is-done .mt-card-title { text-decoration: line-through; color: var(--text-muted); }
.mt-card.is-overdue { background: #FFF8F8; }

.mt-card.prio-low    { border-left-color: #B0BEC5; }
.mt-card.prio-medium { border-left-color: #4FC3F7; }
.mt-card.prio-high   { border-left-color: #FFB74D; }
.mt-card.prio-urgent { border-left-color: #EF5350; }

.mt-round-chk {
  appearance: none; -webkit-appearance: none;
  width: 20px; height: 20px;
  border: 2px solid #B0BEC5; border-radius: 50%;
  background: #fff; cursor: pointer; flex-shrink: 0; margin-top: 2px;
  display: inline-flex; align-items: center; justify-content: center;
  transition: all .15s;
}
.mt-round-chk:hover { border-color: #66BB6A; }
.mt-round-chk:checked { background: #66BB6A; border-color: #66BB6A; }
.mt-round-chk:checked::after {
  content: ''; display: block;
  width: 9px; height: 5px;
  border-left: 2px solid #fff; border-bottom: 2px solid #fff;
  transform: rotate(-45deg) translate(1px, -1px);
}

.mt-card-main { flex: 1; min-width: 0; }
.mt-card-title { font-size: 14px; font-weight: 500; word-break: break-word; line-height: 1.35; }
.mt-card-meta {
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
  margin-top: 4px; font-size: 11.5px; color: var(--text-muted);
}
.mt-card-board { font-weight: 500; }
.mt-card-due {
  font-weight: 700; padding: 2px 8px; border-radius: 10px;
  background: #E8F5E9; color: #2E7D32;
}
.mt-card-due.urgent { background: #FFEBEE; color: #C62828; }
.mt-card-due.soon { background: #FFF3E0; color: #E65100; }
.mt-card-due.overdue { background: #FFEBEE; color: #C62828; font-weight: 800; }
.mt-card-prio {
  font-size: 10.5px; font-weight: 700; padding: 1px 6px; border-radius: 8px;
}
.prio-bg-low    { background: #ECEFF1; color: #455A64; }
.prio-bg-high   { background: #FFF3E0; color: #E65100; }
.prio-bg-urgent { background: #FFEBEE; color: #C62828; }
.mt-card-sub {
  font-size: 10px; padding: 1px 6px; border-radius: 6px;
  background: rgba(33,150,243,0.1); color: #1976D2; font-weight: 600;
}
.mt-card-assignees { display: inline-flex; gap: 0; margin-left: auto; }
.mt-bubble {
  display: inline-flex; align-items: center; justify-content: center;
  width: 20px; height: 20px; border-radius: 50%;
  background: linear-gradient(135deg, #E76F51, #F4A261);
  color: #fff; font-size: 9px; font-weight: 700;
  border: 1.5px solid #fff;
  margin-left: -4px;
}
.mt-bubble:first-child { margin-left: 0; }
</style>
