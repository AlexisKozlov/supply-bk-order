<template>
  <div class="sts">
    <div class="sts-toolbar">
      <div class="sts-period">
        <button v-for="p in periods" :key="p.value" class="sts-period-btn"
                :class="{ active: period === p.value }" @click="setPeriod(p.value)">
          {{ p.label }}
        </button>
      </div>
      <button class="btn" @click="load" :disabled="loading">
        <BkIcon name="redo" size="sm"/> Обновить
      </button>
    </div>

    <div v-if="loading && !hasData" class="sts-state"><BurgerSpinner text="Считаем…" /></div>

    <template v-else>
      <!-- За выбранный период -->
      <div class="sts-block">
        <div class="sts-block-head">
          <h4 class="sts-block-title">За период</h4>
          <span class="sts-block-hint">{{ periodLabel }}</span>
        </div>
        <div class="sts-cards">
          <div class="sts-card">
            <div class="sts-card-value">{{ formatInt(data.orders_total) }}</div>
            <div class="sts-card-label">Заказов закупки</div>
            <div class="sts-card-sub">сегодня {{ formatInt(data.orders_today) }}</div>
          </div>
          <div class="sts-card">
            <div class="sts-card-value">{{ formatInt(data.ro_orders_total) }}</div>
            <div class="sts-card-label">Заказов ресторанов</div>
          </div>
          <div class="sts-card">
            <div class="sts-card-value">{{ formatInt(data.so_orders_total) }}</div>
            <div class="sts-card-label">Заявок поставщикам</div>
          </div>
          <div class="sts-card">
            <div class="sts-card-value">{{ formatInt(data.plans_total) }}</div>
            <div class="sts-card-label">Планов</div>
          </div>
          <div class="sts-card">
            <div class="sts-card-value">{{ formatInt(data.price_agreements_total) }}</div>
            <div class="sts-card-label">Протоколов цен</div>
          </div>
          <div class="sts-card">
            <div class="sts-card-value">{{ formatInt(data.active_users) }}</div>
            <div class="sts-card-label">Людей работало</div>
            <div class="sts-card-sub">по журналу действий</div>
          </div>
        </div>
      </div>

      <!-- График -->
      <div class="sts-block" v-if="days.length">
        <div class="sts-block-head">
          <h4 class="sts-block-title">Заказы по дням</h4>
          <div class="sts-legend">
            <span class="sts-leg"><i class="sts-dot orders"></i>закупка</span>
            <span class="sts-leg"><i class="sts-dot ro"></i>рестораны</span>
            <span class="sts-leg"><i class="sts-dot so"></i>поставщикам</span>
          </div>
        </div>
        <div class="sts-chart">
          <div v-for="d in days" :key="d.date" class="sts-bar-col"
               :class="{ picked: pickedDay?.date === d.date }"
               :title="`${d.label}: закупка ${d.orders}, рестораны ${d.ro}, поставщикам ${d.so}`"
               @click="pickedDay = pickedDay?.date === d.date ? null : d">
            <div class="sts-bar-stack">
              <div class="sts-bar so" :style="{ height: barHeight(d.so) }"></div>
              <div class="sts-bar ro" :style="{ height: barHeight(d.ro) }"></div>
              <div class="sts-bar orders" :style="{ height: barHeight(d.orders) }"></div>
            </div>
            <div class="sts-bar-label">{{ d.short }}</div>
          </div>
        </div>
        <!-- Числа по нажатию: на телефоне наводить нечем, и подсказка-title
             там не показывается вовсе. -->
        <p v-if="pickedDay" class="sts-chart-note sts-picked">
          <b>{{ pickedDay.label }}</b> — закупка {{ pickedDay.orders }},
          рестораны {{ pickedDay.ro }}, поставщикам {{ pickedDay.so }}
        </p>
        <p v-else class="sts-chart-note">Последние 30 дней. Нажмите на столбец, чтобы увидеть числа.</p>
      </div>

      <!-- Всего в системе -->
      <div class="sts-block">
        <div class="sts-block-head">
          <h4 class="sts-block-title">Сейчас в системе</h4>
          <span class="sts-block-hint">не зависит от выбранного периода</span>
        </div>
        <div class="sts-cards">
          <div class="sts-card muted">
            <div class="sts-card-value">{{ formatInt(data.products_count) }}</div>
            <div class="sts-card-label">Товаров</div>
          </div>
          <div class="sts-card muted">
            <div class="sts-card-value">{{ formatInt(data.suppliers_count) }}</div>
            <div class="sts-card-label">Поставщиков</div>
          </div>
          <div class="sts-card muted">
            <div class="sts-card-value">{{ formatInt(data.users_count) }}</div>
            <div class="sts-card-label">Сотрудников</div>
          </div>
          <div class="sts-card muted">
            <div class="sts-card-value">{{ formatInt(data.active_sessions) }}</div>
            <div class="sts-card-label">Открытых сессий</div>
          </div>
        </div>
      </div>

      <div class="sts-columns">
        <div class="sts-block">
          <h4 class="sts-block-title">Заказы по юрлицам</h4>
          <div v-if="!byEntity.length" class="sts-empty">Нет данных за период</div>
          <div v-else class="sts-bars">
            <div v-for="e in byEntity" :key="e.legal_entity || '—'" class="sts-bar-row">
              <div class="sts-bar-name">{{ shortEntity(e.legal_entity) }}</div>
              <div class="sts-bar-track">
                <div class="sts-bar-fill" :style="{ width: entityWidth(e.cnt) }"></div>
              </div>
              <div class="sts-bar-val">{{ formatInt(e.cnt) }}<span class="sts-bar-pct">{{ entityPct(e.cnt) }}</span></div>
            </div>
          </div>
        </div>

        <div class="sts-block">
          <h4 class="sts-block-title">Кто больше заказывал</h4>
          <div v-if="!topUsers.length" class="sts-empty">Нет данных за период</div>
          <div v-else class="sts-top">
            <div v-for="(u, i) in topUsers" :key="u.user_name || i" class="sts-top-row">
              <span class="sts-top-num" :class="{ lead: i === 0 }">{{ i + 1 }}</span>
              <span class="sts-top-name">{{ u.user_name || '—' }}</span>
              <span class="sts-top-bar"><i :style="{ width: topWidth(u.cnt) }"></i></span>
              <span class="sts-top-cnt">{{ formatInt(u.cnt) }}</span>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { db } from '@/lib/apiClient.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import { formatInt } from '@/lib/utils.js';

const data = ref({});
const loading = ref(false);
const period = ref('month');
// Выбранный столбец графика: на телефоне числа иначе не посмотреть.
const pickedDay = ref(null);

const periods = [
  { value: 'week', label: 'Неделя' },
  { value: 'month', label: 'Месяц' },
  { value: 'all', label: 'Всё время' },
];
const periodLabel = computed(() => ({
  week: 'последние 7 дней',
  month: 'последние 30 дней',
  all: 'за всё время',
}[period.value] || ''));

const hasData = computed(() => Object.keys(data.value).length > 0);
const byEntity = computed(() => data.value.orders_by_entity || []);
const topUsers = computed(() => data.value.top_users || []);

const LE_SHORT = { 'ООО "Бургер БК"': 'Бургер БК', 'ООО "Воглия Матта"': 'Воглия Матта', 'ООО "Пицца Стар"': 'Пицца Стар' };
function shortEntity(le) {
  if (!le) return 'Без юрлица';
  return LE_SHORT[le] || le;
}

const entityMax = computed(() => Math.max(1, ...byEntity.value.map(e => Number(e.cnt) || 0)));
const entityTotal = computed(() => byEntity.value.reduce((s, e) => s + (Number(e.cnt) || 0), 0));
function entityWidth(n) { return Math.max(4, ((Number(n) || 0) / entityMax.value) * 100) + '%'; }
function entityPct(n) {
  if (!entityTotal.value) return '';
  return ' · ' + Math.round(((Number(n) || 0) / entityTotal.value) * 100) + '%';
}

const topMax = computed(() => Math.max(1, ...topUsers.value.map(u => Number(u.cnt) || 0)));
function topWidth(n) { return Math.max(3, ((Number(n) || 0) / topMax.value) * 100) + '%'; }

// График: дни без данных тоже показываем, иначе провалы не видно.
const days = computed(() => {
  const raw = data.value.by_day || [];
  if (!raw.length) return [];
  const map = new Map(raw.map(r => [r.date, r]));
  const out = [];
  const start = new Date();
  start.setHours(0, 0, 0, 0);
  for (let i = 29; i >= 0; i--) {
    const d = new Date(start.getTime() - i * 86400000);
    const iso = d.toISOString().slice(0, 10);
    const row = map.get(iso) || {};
    out.push({
      date: iso,
      orders: Number(row.orders) || 0,
      ro: Number(row.ro) || 0,
      so: Number(row.so) || 0,
      label: d.toLocaleDateString('ru-RU', { day: '2-digit', month: 'long' }),
      short: String(d.getDate()),
    });
  }
  return out;
});
const dayMax = computed(() => Math.max(1, ...days.value.map(d => d.orders + d.ro + d.so)));
function barHeight(n) {
  const v = Number(n) || 0;
  if (!v) return '0';
  return Math.max(2, (v / dayMax.value) * 100) + '%';
}

async function load() {
  loading.value = true;
  try {
    const { data: res } = await db.rpc('get_admin_stats', { period: period.value });
    data.value = res || {};
  } catch (e) {
    console.warn('[stats] load:', e);
  } finally {
    loading.value = false;
  }
}

function setPeriod(v) {
  period.value = v;
  load();
}

onMounted(load);
</script>

<style scoped>
.sts { display: flex; flex-direction: column; gap: 12px; }

.sts-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.sts-period { display: inline-flex; gap: 2px; background: var(--bg); border: 1px solid var(--border-light); border-radius: 10px; padding: 2px; }
.sts-period-btn {
  border: none; background: none; cursor: pointer; font-family: inherit;
  font-size: 13px; font-weight: 600; color: var(--text-muted);
  padding: 6px 14px; border-radius: 8px; transition: all .15s;
}
.sts-period-btn.active { background: var(--card); color: var(--text); box-shadow: 0 1px 3px rgba(0, 0, 0, .08); }

.sts-state { text-align: center; padding: 48px; }
.sts-empty { padding: 20px 0; text-align: center; font-size: 13px; color: var(--text-muted); }

.sts-block {
  background: var(--card); border: 1px solid var(--border-light);
  border-radius: 12px; padding: 14px 16px;
}
.sts-block-head {
  display: flex; align-items: baseline; justify-content: space-between;
  gap: 10px; flex-wrap: wrap; margin-bottom: 12px;
}
.sts-block-title { margin: 0; font-size: 13px; font-weight: 700; color: var(--text); }
.sts-block-hint { font-size: 11.5px; color: var(--text-muted); }

.sts-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
.sts-card {
  padding: 12px 14px; border-radius: 10px;
  background: var(--bg); border: 1px solid var(--border-light);
}
.sts-card.muted { opacity: .9; }
.sts-card-value { font-size: 24px; font-weight: 800; color: var(--text); line-height: 1.15; }
.sts-card-label { font-size: 12px; color: var(--text-secondary); margin-top: 3px; }
.sts-card-sub { font-size: 11px; color: var(--text-muted); margin-top: 1px; }

/* ═══ График по дням ═══ */
.sts-legend { display: flex; gap: 12px; flex-wrap: wrap; }
.sts-leg { display: inline-flex; align-items: center; gap: 5px; font-size: 11.5px; color: var(--text-muted); }
.sts-dot { width: 9px; height: 9px; border-radius: 3px; display: inline-block; }
.sts-dot.orders, .sts-bar.orders { background: #E8941A; }
.sts-dot.ro, .sts-bar.ro { background: #4F6E8C; }
.sts-dot.so, .sts-bar.so { background: #7BA05B; }

.sts-chart {
  display: flex; align-items: flex-end; gap: 3px;
  height: 150px; overflow-x: auto; padding-bottom: 2px;
}
.sts-bar-col { flex: 1; min-width: 14px; display: flex; flex-direction: column; align-items: center; height: 100%; }
.sts-bar-stack {
  flex: 1; width: 100%; display: flex; flex-direction: column; justify-content: flex-end;
  border-radius: 4px 4px 0 0; overflow: hidden; background: var(--bg);
}
.sts-bar { width: 100%; }
.sts-bar-label { font-size: 9.5px; color: var(--text-muted); margin-top: 4px; }
.sts-chart-note { margin: 8px 0 0; font-size: 11px; color: var(--text-muted); }

/* ═══ Юрлица ═══ */
.sts-columns { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.sts-bars { display: flex; flex-direction: column; gap: 8px; }
.sts-bar-row { display: flex; align-items: center; gap: 10px; }
.sts-bar-name { width: 110px; flex-shrink: 0; font-size: 12.5px; color: var(--text-secondary); }
.sts-bar-track { flex: 1; height: 10px; border-radius: 5px; background: var(--bg); overflow: hidden; }
.sts-bar-fill { height: 100%; border-radius: 5px; background: linear-gradient(90deg, #F4A261, #E8941A); }
.sts-bar-val { font-size: 12px; font-weight: 600; color: var(--text); flex-shrink: 0; min-width: 72px; text-align: right; }
.sts-bar-pct { font-weight: 400; color: var(--text-muted); }

/* ═══ Топ ═══ */
.sts-top { display: flex; flex-direction: column; gap: 6px; }
.sts-top-row { display: flex; align-items: center; gap: 10px; }
.sts-top-num {
  width: 20px; height: 20px; border-radius: 6px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 700; color: var(--text-muted); background: var(--bg);
}
.sts-top-num.lead { background: #FFF3E0; color: #E65100; }
.sts-top-name {
  flex: 1; min-width: 0; font-size: 13px; color: var(--text);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.sts-top-bar { width: 70px; height: 6px; border-radius: 3px; background: var(--bg); flex-shrink: 0; overflow: hidden; }
.sts-top-bar i { display: block; height: 100%; background: #E8941A; border-radius: 3px; }
.sts-top-cnt { font-size: 12px; font-weight: 600; color: var(--text); flex-shrink: 0; min-width: 34px; text-align: right; }

@media (max-width: 800px) {
  .sts-columns { grid-template-columns: 1fr; }
  .sts-bar-name { width: 88px; font-size: 12px; }
  .sts-top-bar { display: none; }
}
@media (max-width: 600px) {
  .sts-period { width: 100%; }
  .sts-period-btn { flex: 1; }
  .sts-card-value { font-size: 21px; }
  .sts-chart { height: 120px; }
}

.sts-bar-col { cursor: pointer; border-radius: 6px; transition: background .15s; }
.sts-bar-col:hover { background: rgba(0, 0, 0, .04); }
.sts-bar-col.picked { background: rgba(244, 162, 97, .18); }
.sts-picked { color: var(--text); }
</style>
