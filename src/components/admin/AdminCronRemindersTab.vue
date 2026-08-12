<template>
  <div class="crn">
    <!-- Сводка за сутки: понятно ли, что крон вообще жив -->
    <div class="crn-cards">
      <div class="crn-card" :class="aliveClass">
        <div class="crn-card-label">Последний запуск</div>
        <div class="crn-card-value">{{ lastRunLabel }}</div>
        <div class="crn-card-sub">{{ aliveHint }}</div>
      </div>
      <div class="crn-card">
        <div class="crn-card-label">Запусков за сутки</div>
        <div class="crn-card-value">{{ formatInt(dayRuns.length) }}</div>
        <div class="crn-card-sub">крон ходит каждые 5 минут</div>
      </div>
      <div class="crn-card">
        <div class="crn-card-label">Отправлено за сутки</div>
        <div class="crn-card-value">{{ formatInt(daySent) }}</div>
        <div class="crn-card-sub">портал {{ formatInt(dayPortal) }} · телеграм {{ formatInt(dayTg) }}</div>
      </div>
      <div class="crn-card" :class="{ bad: dayErrors > 0 }">
        <div class="crn-card-label">Ошибок за сутки</div>
        <div class="crn-card-value">{{ formatInt(dayErrors) }}</div>
        <div class="crn-card-sub">{{ dayErrors ? 'нужно посмотреть' : 'всё чисто' }}</div>
      </div>
    </div>

    <div class="crn-toolbar">
      <div class="crn-info">
        Журнал последних {{ rows.length }} запусков
        <span v-if="onlyProblems"> · показаны только проблемные</span>
      </div>
      <label class="crn-check">
        <input type="checkbox" v-model="onlyProblems">
        <span>Только с ошибками и отправками</span>
      </label>
      <button class="btn" @click="load" :disabled="loading">
        <BkIcon name="redo" size="sm"/> Обновить
      </button>
    </div>

    <div v-if="loading && !rows.length" class="crn-state"><BurgerSpinner text="Загрузка…" /></div>
    <div v-else-if="!visibleRows.length" class="crn-state">
      {{ rows.length ? 'Подходящих запусков нет — снимите фильтр' : 'Журнал пуст. Крон ещё не запускался?' }}
    </div>

    <div v-else class="crn-list">
      <div v-for="row in visibleRows" :key="row.id" class="crn-run" :class="{ bad: row.status === 'error', quiet: isQuiet(row) }">
        <div class="crn-run-main">
          <span class="crn-run-time">{{ fmtTime(row.started_at) }}</span>
          <span class="crn-run-dur">{{ duration(row) }}</span>
          <span v-if="row.status === 'error'" class="crn-badge bad">
            <BkIcon name="warning" size="sm"/> ошибка
          </span>
          <span v-else-if="isQuiet(row)" class="crn-badge quiet">тихо</span>
          <span v-else class="crn-badge ok">отправлено {{ formatInt(sentOf(row)) }}</span>
        </div>

        <div class="crn-run-nums">
          <span class="crn-group">
            <span class="crn-group-name">Поставщики</span>
            <span class="crn-num" title="показано в портале">портал {{ row.sup_portal ?? 0 }}</span>
            <span class="crn-num" title="отправлено в Telegram">телеграм {{ row.sup_tg ?? 0 }}</span>
            <span class="crn-num muted" title="пропущено — уже отправляли или выключено">пропуск {{ row.sup_skip ?? 0 }}</span>
          </span>
          <span class="crn-group">
            <span class="crn-group-name">Основная поставка</span>
            <span class="crn-num" title="показано в портале">портал {{ row.main_portal ?? 0 }}</span>
            <span class="crn-num" title="отправлено в Telegram">телеграм {{ row.main_tg ?? 0 }}</span>
            <span class="crn-num muted" title="пропущено — уже отправляли или выключено">пропуск {{ row.main_skip ?? 0 }}</span>
          </span>
        </div>

        <p v-if="row.status === 'error' && row.error_text" class="crn-error">{{ row.error_text }}</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { db } from '@/lib/apiClient.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import { formatInt, parseMoscowDate, formatMoscowRelative } from '@/lib/utils.js';

// Красную точку у вкладки рисует админка — отдаём ей свежее число ошибок.
const emit = defineEmits(['err-count']);

const rows = ref([]);
const loading = ref(false);
const onlyProblems = ref(false);
let timer = null;

function ts(value) {
  const d = parseMoscowDate(value);
  return d && !isNaN(d.getTime()) ? d.getTime() : null;
}

// За сутки — по времени запуска.
const dayRuns = computed(() => {
  const from = Date.now() - 86400000;
  return rows.value.filter(r => (ts(r.started_at) || 0) > from);
});
const dayErrors = computed(() => dayRuns.value.filter(r => r.status === 'error').length);
const dayPortal = computed(() => dayRuns.value.reduce((s, r) => s + (+r.sup_portal || 0) + (+r.main_portal || 0), 0));
const dayTg = computed(() => dayRuns.value.reduce((s, r) => s + (+r.sup_tg || 0) + (+r.main_tg || 0), 0));
const daySent = computed(() => dayPortal.value + dayTg.value);

const lastRun = computed(() => rows.value[0] || null);
const lastRunLabel = computed(() => (lastRun.value ? formatMoscowRelative(lastRun.value.started_at) : '—'));

// Крон ходит каждые 5 минут: если тишина дольше 15 — что-то встало.
const minutesSinceLast = computed(() => {
  const t = lastRun.value ? ts(lastRun.value.started_at) : null;
  return t ? Math.floor((Date.now() - t) / 60000) : null;
});
const aliveClass = computed(() => {
  const m = minutesSinceLast.value;
  if (m === null) return 'bad';
  if (m > 15) return 'bad';
  if (m > 8) return 'warn';
  return 'good';
});
const aliveHint = computed(() => {
  const m = minutesSinceLast.value;
  if (m === null) return 'запусков не было';
  if (m > 15) return 'крон молчит — проверьте сервер';
  if (m > 8) return 'дольше обычного';
  return 'по расписанию';
});

function sentOf(row) {
  return (+row.sup_portal || 0) + (+row.sup_tg || 0) + (+row.main_portal || 0) + (+row.main_tg || 0);
}
// «Тихий» запуск — никому ничего не полагалось, это нормально.
function isQuiet(row) {
  return row.status !== 'error' && sentOf(row) === 0;
}

const visibleRows = computed(() => (onlyProblems.value
  ? rows.value.filter(r => r.status === 'error' || sentOf(r) > 0)
  : rows.value));

function fmtTime(value) {
  const d = parseMoscowDate(value);
  if (!d || isNaN(d.getTime())) return String(value || '—');
  const time = d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Moscow' });
  const sameDay = d.toDateString() === new Date().toDateString();
  if (sameDay) return time;
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', timeZone: 'Europe/Moscow' }) + ' ' + time;
}

function duration(row) {
  const s = ts(row.started_at);
  const e = ts(row.finished_at);
  if (!s || !e) return '—';
  const ms = e - s;
  return ms < 1000 ? `${ms} мс` : `${(ms / 1000).toFixed(1)} с`;
}

async function load() {
  loading.value = true;
  try {
    const res = await db.from('reminder_cron_log')
      .select('*')
      .order('started_at', { ascending: false })
      .limit(100);
    rows.value = res.error ? [] : (res.data || []);
    emit('err-count', dayErrors.value);
  } catch {
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  load();
  // Крон ходит каждые 5 минут — раз в минуту подтягиваем свежие строки,
  // но только когда вкладка на экране.
  timer = setInterval(() => {
    if (typeof document === 'undefined' || document.visibilityState === 'visible') load();
  }, 60000);
});
onBeforeUnmount(() => { if (timer) clearInterval(timer); });
</script>

<style scoped>
.crn { display: flex; flex-direction: column; gap: 12px; }

.crn-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
.crn-card {
  background: var(--card); border: 1px solid var(--border-light);
  border-radius: 12px; padding: 12px 14px;
}
.crn-card.good { border-color: #C8E6C9; background: linear-gradient(180deg, #F4FBF4, var(--card)); }
.crn-card.warn { border-color: #FFE0B2; background: linear-gradient(180deg, #FFF8EC, var(--card)); }
.crn-card.bad  { border-color: #FFCDD2; background: linear-gradient(180deg, #FFF5F5, var(--card)); }
.crn-card-label { font-size: 11px; text-transform: uppercase; letter-spacing: .3px; color: var(--text-muted); font-weight: 600; }
.crn-card-value { font-size: 22px; font-weight: 800; color: var(--text); line-height: 1.25; margin-top: 2px; }
.crn-card-sub { font-size: 11.5px; color: var(--text-muted); }

.crn-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.crn-info { font-size: 13px; color: var(--text-muted); flex: 1; min-width: 160px; }
.crn-check { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text-secondary); cursor: pointer; }

.crn-state { text-align: center; padding: 36px; color: var(--text-muted); font-size: 14px; }

.crn-list { display: flex; flex-direction: column; gap: 6px; }
.crn-run {
  background: var(--card); border: 1px solid var(--border-light);
  border-radius: 10px; padding: 10px 14px;
  display: flex; flex-direction: column; gap: 6px;
}
.crn-run.bad { border-color: #E57373; background: #FFF7F7; }
.crn-run.quiet { opacity: .72; }

.crn-run-main { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.crn-run-time { font-size: 13px; font-weight: 700; color: var(--text); font-variant-numeric: tabular-nums; }
.crn-run-dur { font-size: 11.5px; color: var(--text-muted); }

.crn-badge {
  font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px;
  padding: 2px 7px; border-radius: 5px; display: inline-flex; align-items: center; gap: 4px;
}
.crn-badge.ok { background: #E8F5E9; color: #2E7D32; }
.crn-badge.bad { background: #FFEBEE; color: #C62828; }
.crn-badge.quiet { background: var(--bg); color: var(--text-muted); }

.crn-run-nums { display: flex; gap: 18px; flex-wrap: wrap; }
.crn-group { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.crn-group-name { font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: .3px; }
.crn-num { font-size: 12px; color: var(--text-secondary); font-variant-numeric: tabular-nums; }
.crn-num.muted { color: var(--text-muted); }

.crn-error {
  margin: 0; font-size: 12px; color: #C62828; line-height: 1.45;
  background: #FFEBEE; border-radius: 8px; padding: 8px 10px;
  word-break: break-word;
}

@media (max-width: 900px) {
  .crn-cards { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 600px) {
  .crn-card-value { font-size: 19px; }
  .crn-run-nums { gap: 8px; flex-direction: column; }
  .crn-toolbar .btn { flex: 1; justify-content: center; }
}
</style>
