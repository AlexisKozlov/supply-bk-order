<template>
  <Teleport to="body" v-if="modelValue">
    <div class="tr-overlay" @click.self="close">
      <div class="tr-modal" @click.stop>
        <header class="tr-head">
          <span class="tr-head-icon"><TaskIcon name="clock" :size="16"/></span>
          <h2 class="tr-title">Сводка по времени</h2>
          <button class="tr-close" @click="close" title="Закрыть">
            <TaskIcon name="close" :size="16"/>
          </button>
        </header>

        <div class="tr-body">
          <div v-if="loading" class="tr-state">Загрузка…</div>
          <div v-else-if="error" class="tr-state tr-error">{{ error }}</div>
          <div v-else-if="!total" class="tr-state">
            По задачам этой доски таймер ещё не использовался.
          </div>
          <template v-else>
            <div class="tr-total">
              <span class="tr-total-label">Всего по доске</span>
              <span class="tr-total-value">{{ formatDuration(total) }}</span>
            </div>

            <section class="tr-section">
              <h3 class="tr-section-title">По людям</h3>
              <ul class="tr-list">
                <li v-for="u in byUser" :key="u.user_name" class="tr-row">
                  <span class="tr-bubble">{{ initials(u.user_name) }}</span>
                  <span class="tr-name">{{ u.user_name }}</span>
                  <span class="tr-sub">{{ u.cards }} {{ plural(u.cards, 'задача', 'задачи', 'задач') }}</span>
                  <span class="tr-bar-wrap">
                    <span class="tr-bar" :style="{ width: pct(u.seconds) + '%' }"></span>
                  </span>
                  <span class="tr-time">{{ formatDuration(u.seconds) }}</span>
                </li>
              </ul>
            </section>

            <section class="tr-section">
              <h3 class="tr-section-title">По задачам</h3>
              <ul class="tr-list">
                <li v-for="c in byCard" :key="c.id" class="tr-row tr-row-card"
                    @click="openCard(c.id)">
                  <span class="tr-card-title" :title="c.title">{{ c.title }}</span>
                  <span v-if="c.is_archived" class="tr-tag">архив</span>
                  <span class="tr-bar-wrap">
                    <span class="tr-bar tr-bar-card" :style="{ width: pctCard(c.seconds) + '%' }"></span>
                  </span>
                  <span class="tr-time">{{ formatDuration(c.seconds) }}</span>
                </li>
              </ul>
            </section>
          </template>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, watch } from 'vue';
import { tasksApi } from '../../lib/tasksApi';
import TaskIcon from './TaskIcon.vue';

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  boardId: { type: [Number, String], default: null },
});
const emit = defineEmits(['update:modelValue', 'open-card']);

const loading = ref(false);
const error = ref('');
const byUser = ref([]);
const byCard = ref([]);
const total = ref(0);

watch(() => props.modelValue, (open) => {
  if (open) load();
});

async function load() {
  if (!props.boardId) return;
  loading.value = true;
  error.value = '';
  try {
    const r = await tasksApi.boardTimeReport(props.boardId);
    byUser.value = r.by_user || [];
    byCard.value = r.by_card || [];
    total.value = r.total_seconds || 0;
  } catch (e) {
    error.value = 'Не удалось загрузить сводку: ' + (e?.message || e);
  } finally {
    loading.value = false;
  }
}

function close() { emit('update:modelValue', false); }
function openCard(id) {
  emit('open-card', id);
  close();
}

function formatDuration(sec) {
  sec = Math.max(0, Math.round(sec));
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  if (h && m) return `${h} ч ${m} мин`;
  if (h) return `${h} ч`;
  if (m) return `${m} мин`;
  return 'меньше минуты';
}
function pct(sec) {
  const max = byUser.value.reduce((a, u) => Math.max(a, u.seconds), 0) || 1;
  return Math.round((sec / max) * 100);
}
function pctCard(sec) {
  const max = byCard.value.reduce((a, c) => Math.max(a, c.seconds), 0) || 1;
  return Math.round((sec / max) * 100);
}
function initials(name) {
  return (name || '').trim().split(/\s+/).slice(0, 2).map(w => w[0] || '').join('').toUpperCase();
}
function plural(n, one, few, many) {
  const m10 = n % 10, m100 = n % 100;
  if (m10 === 1 && m100 !== 11) return one;
  if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return few;
  return many;
}
</script>

<style scoped>
.tr-overlay {
  position: fixed; inset: 0;
  background: rgba(9,30,66,0.48);
  display: flex; align-items: center; justify-content: center;
  z-index: 1000;
  padding: 16px;
}
.tr-modal {
  background: #fff;
  border-radius: var(--tk-r-lg);
  width: 100%; max-width: 560px;
  max-height: 90vh;
  display: flex; flex-direction: column;
  box-shadow: 0 18px 48px rgba(9,30,66,0.32);
}
.tr-head {
  display: flex; align-items: center; gap: 10px;
  padding: 16px 18px;
  border-bottom: 1px solid var(--tk-n-200);
}
.tr-head-icon { display: flex; color: var(--tk-accent); }
.tr-title { flex: 1; margin: 0; font-size: 16px; font-weight: 700; color: var(--tk-text); }
.tr-close {
  background: none; border: none; cursor: pointer;
  display: flex; padding: 4px; border-radius: 6px; color: var(--tk-n-300);
}
.tr-close:hover { background: var(--tk-n-100); color: var(--tk-text); }

.tr-body { padding: 12px 18px 18px; overflow-y: auto; }
.tr-state { padding: 28px 8px; text-align: center; color: var(--tk-n-300); font-size: 13px; }
.tr-error { color: #c0392b; }

.tr-total {
  display: flex; align-items: baseline; justify-content: space-between;
  padding: 12px 14px; margin-bottom: 8px;
  background: var(--tk-n-100);
  border-radius: 10px;
}
.tr-total-label { font-size: 13px; color: var(--tk-n-300); font-weight: 600; }
.tr-total-value { font-size: 20px; font-weight: 800; color: var(--tk-text); }

.tr-section { padding: 12px 0; }
.tr-section-title {
  margin: 0 0 10px;
  font-size: 12px; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.04em;
  color: var(--tk-n-300);
}
.tr-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.tr-row {
  display: flex; align-items: center; gap: 8px;
}
.tr-row-card { cursor: pointer; border-radius: 8px; padding: 2px 4px; margin: 0 -4px; }
.tr-row-card:hover { background: var(--tk-n-100); }
.tr-bubble {
  flex: 0 0 auto;
  width: 24px; height: 24px; border-radius: 50%;
  background: var(--tk-accent); color: #fff;
  font-size: 10px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
}
.tr-name { font-size: 13px; color: var(--tk-text); font-weight: 600; white-space: nowrap; }
.tr-card-title {
  font-size: 13px; color: var(--tk-text);
  max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.tr-sub { font-size: 11px; color: var(--tk-n-300); white-space: nowrap; }
.tr-tag {
  font-size: 10px; color: var(--tk-n-300);
  background: var(--tk-n-100); border-radius: 4px; padding: 1px 5px;
}
.tr-bar-wrap {
  flex: 1; min-width: 30px;
  height: 6px; background: var(--tk-n-100); border-radius: 3px;
  overflow: hidden;
}
.tr-bar { display: block; height: 100%; background: var(--tk-accent); border-radius: 3px; }
.tr-bar-card { background: #66BB6A; }
.tr-time {
  flex: 0 0 auto;
  font-size: 12px; font-weight: 700; color: var(--tk-text);
  font-variant-numeric: tabular-nums;
  min-width: 78px; text-align: right;
}
</style>
