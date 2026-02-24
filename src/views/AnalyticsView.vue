<template>
  <div class="analytics-view">
    <!-- Header -->
    <div class="an-header">
      <h1 class="page-title">Аналитика</h1>
      <select v-model.number="days" @change="load" class="an-period">
        <option :value="7">7 дней</option>
        <option :value="14">14 дней</option>
        <option :value="30">30 дней</option>
        <option :value="60">60 дней</option>
        <option :value="90">90 дней</option>
      </select>
    </div>

    <!-- Anomaly banner (if critical, always visible) -->
    <div v-if="!loading && data && criticalAnomalies.length && activeTab !== 'anomalies'" class="an-alert-banner" @click="activeTab = 'anomalies'">
      <BkIcon name="warning" size="sm"/> {{ criticalAnomalies.length }} критич. аномалий
      <span class="an-alert-link">Смотреть →</span>
    </div>

    <!-- Tabs -->
    <div v-if="!loading && data" class="an-tabs">
      <button v-for="t in tabs" :key="t.id" class="an-tab" :class="{ active: activeTab === t.id }" @click="activeTab = t.id">
        {{ t.label }}
        <span v-if="t.id === 'anomalies' && data.anomalies.length" class="an-tab-badge">{{ data.anomalies.length }}</span>
      </button>
    </div>

    <div v-if="loading" style="text-align:center;padding:60px;">
      <BurgerSpinner text="Загрузка..." />
    </div>
    <div v-else-if="!data" style="text-align:center;padding:60px;color:var(--text-muted);">Нет данных за выбранный период</div>

    <!-- Tab content -->
    <div v-else class="an-content">

      <!-- ===== OVERVIEW ===== -->
      <template v-if="activeTab === 'overview'">
        <!-- KPI cards -->
        <div class="an-kpi-grid">
          <div class="an-kpi">
            <div class="an-kpi-head"><span class="an-kpi-icon"><BkIcon name="history" size="sm"/></span> Заказов</div>
            <div class="an-kpi-row">
              <span class="an-kpi-val">{{ nf(data.totals.orders) }}</span>
              <span v-if="data.deltaOrders !== null" class="an-badge" :class="data.deltaOrders >= 0 ? 'up' : 'down'">
                {{ data.deltaOrders >= 0 ? '▲' : '▼' }} {{ Math.abs(data.deltaOrders) }}%
              </span>
            </div>
            <div class="an-kpi-sub">прошлый: {{ nf(data.prev.orders) }}</div>
          </div>
          <div class="an-kpi">
            <div class="an-kpi-head"><span class="an-kpi-icon"><BkIcon name="order" size="sm"/></span> Коробок</div>
            <div class="an-kpi-row">
              <span class="an-kpi-val">{{ nf(data.totals.boxes) }}</span>
              <span v-if="data.deltaBoxes !== null" class="an-badge" :class="data.deltaBoxes >= 0 ? 'up' : 'down'">
                {{ data.deltaBoxes >= 0 ? '▲' : '▼' }} {{ Math.abs(data.deltaBoxes) }}%
              </span>
            </div>
            <div class="an-kpi-sub">прошлый: {{ nf(data.prev.boxes) }}</div>
          </div>
          <div class="an-kpi">
            <div class="an-kpi-head"><span class="an-kpi-icon"><BkIcon name="ruler" size="sm"/></span> Ср. кор/заказ</div>
            <div class="an-kpi-row">
              <span class="an-kpi-val">{{ data.totals.orders ? Math.round(data.totals.boxes / data.totals.orders) : 0 }}</span>
            </div>
            <div class="an-kpi-sub">за период</div>
          </div>
        </div>

        <!-- Chart -->
        <div class="an-card">
          <div class="an-card-header">
            <span class="an-card-title"><BkIcon name="calendar" size="sm"/> Коробок по дням</span>
            <div class="an-legend">
              <span v-for="s in data.suppliers" :key="s.supplier" class="an-legend-item">
                <span class="an-legend-dot" :style="{ background: s.color }"></span>{{ s.supplier }}
              </span>
            </div>
          </div>
          <div class="an-chart">
            <div v-for="(day, i) in chartDays" :key="day.dayKey" class="an-bar-col"
              :title="day.dayLabel + ': ' + nf(day.total) + ' кор.'">
              <div class="an-bar-num">{{ nf(day.total) }}</div>
              <div class="an-bar-stack">
                <template v-for="s in data.suppliers" :key="s.supplier">
                  <div v-if="day.bySupplier[s.supplier]" class="an-bar-seg"
                    :style="{ height: barH(day.bySupplier[s.supplier]) + 'px', background: s.color,
                      borderRadius: isTop(day, s.supplier) ? '3px 3px 0 0' : '0' }">
                  </div>
                </template>
              </div>
              <div class="an-bar-label">{{ day.dayLabel }}</div>
            </div>
          </div>
        </div>

        <!-- Suppliers quick -->
        <div class="an-card">
          <div class="an-card-title"><BkIcon name="building" size="sm"/> По поставщикам</div>
          <div class="an-sup-table">
            <div v-for="s in data.suppliers" :key="s.supplier" class="an-sup-row">
              <div class="an-sup-left">
                <span class="an-sup-dot" :style="{ background: s.color }"></span>
                <span class="an-sup-name">{{ s.supplier }}</span>
              </div>
              <div class="an-sup-right">
                <div class="an-sup-bar-wrap">
                  <div class="an-sup-bar" :style="{ width: sPct(s) + '%', background: s.color }"></div>
                </div>
                <span class="an-sup-val">{{ nf(s.boxes) }}</span>
                <span class="an-sup-meta">{{ s.orders }} зак.</span>
              </div>
            </div>
          </div>
        </div>
      </template>

      <!-- ===== SUPPLIERS ===== -->
      <template v-if="activeTab === 'suppliers'">
        <div v-for="s in data.suppliers" :key="s.supplier" class="an-card an-sup-card">
          <div class="an-sup-card-head">
            <span class="an-sup-dot-lg" :style="{ background: s.color }"></span>
            <span class="an-sup-card-name">{{ s.supplier }}</span>
            <span v-if="s.daysAgo !== null" class="an-sup-card-ago">{{ s.daysAgo }} дн. назад</span>
          </div>
          <div class="an-sup-metrics">
            <div class="an-sup-metric">
              <div class="an-sup-metric-val">{{ s.orders }}</div>
              <div class="an-sup-metric-label">Заказов</div>
            </div>
            <div class="an-sup-metric">
              <div class="an-sup-metric-val">{{ nf(s.boxes) }}</div>
              <div class="an-sup-metric-label">Коробок</div>
            </div>
            <div class="an-sup-metric">
              <div class="an-sup-metric-val">{{ s.orders ? Math.round(s.boxes / s.orders) : 0 }}</div>
              <div class="an-sup-metric-label">Ср./заказ</div>
            </div>
            <div class="an-sup-metric">
              <div class="an-sup-metric-val" :class="deltaCls(supDelta(s))">
                <template v-if="supDelta(s) !== null">{{ supDelta(s) >= 0 ? '▲' : '▼' }}{{ Math.abs(supDelta(s)) }}%</template>
                <template v-else>—</template>
              </div>
              <div class="an-sup-metric-label">vs прошл.</div>
            </div>
          </div>
        </div>
      </template>

      <!-- ===== PRODUCTS + FORECAST ===== -->
      <template v-if="activeTab === 'products'">
        <div class="an-card" style="padding:0;">
          <div class="an-prod-header">
            <span><BkIcon name="fire" size="sm"/> Топ товаров + прогноз</span>
          </div>
          <div v-for="(p, i) in data.topProducts" :key="p.sku || p.name" class="an-prod-row">
            <div class="an-prod-rank" :class="{ top: i < 3 }">{{ i + 1 }}</div>
            <div class="an-prod-info">
              <div class="an-prod-line1">
                <span class="an-prod-sku">{{ p.sku || '' }}</span>
                <span class="an-prod-name">{{ p.name || '—' }}</span>
              </div>
              <div class="an-prod-progress">
                <div class="an-prod-progress-bar" :style="{ width: pPct(p) + '%' }"></div>
              </div>
            </div>
            <div class="an-prod-stats">
              <div class="an-prod-boxes">{{ nf(p.boxes) }} кор</div>
              <div v-if="p.deltaBoxes !== null" class="an-prod-delta" :class="p.deltaBoxes >= 0 ? 'up' : 'down'">
                {{ p.deltaBoxes >= 0 ? '▲' : '▼' }} {{ Math.abs(p.deltaBoxes) }}%
              </div>
            </div>
            <div class="an-prod-forecast">
              <div class="an-prod-forecast-label">прогноз</div>
              <div class="an-prod-forecast-val">~{{ nf(p.forecast) }}</div>
            </div>
          </div>
        </div>
        <div class="an-forecast-note">
          <BkIcon name="bulb" size="sm"/> Прогноз = средний расход в день × {{ days }} дней
        </div>
      </template>

      <!-- ===== ANOMALIES ===== -->
      <template v-if="activeTab === 'anomalies'">
        <div v-if="!data.anomalies.length" style="text-align:center;padding:40px;color:var(--text-muted);">
          <BkIcon name="success" size="sm"/> Аномалий не обнаружено за выбранный период
        </div>
        <div v-for="(a, i) in data.anomalies" :key="i" class="an-anomaly" :class="['sev-' + a.severity, { clickable: a.orderId }]"
          @click="a.orderId ? loadOrder(a.orderId) : null">
          <span class="an-anomaly-icon">{{ a.icon }}</span>
          <div class="an-anomaly-body">
            <div class="an-anomaly-title">{{ a.title }}</div>
            <div class="an-anomaly-text">{{ a.text }}</div>
            <div class="an-anomaly-detail">{{ a.detail }}</div>
          </div>
          <span class="an-anomaly-tag">{{ typeLabel(a.type) }}</span>
          <span v-if="a.orderId" class="an-anomaly-go">→</span>
        </div>
      </template>

    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { getOrdersAnalytics } from '@/lib/analytics.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useDraftStore } from '@/stores/draftStore.js';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import { useToastStore } from '@/stores/toastStore.js';
import { db } from '@/lib/apiClient.js';
import BkIcon from '@/components/ui/BkIcon.vue';


const router = useRouter();
const orderStore = useOrderStore();
const draftStore = useDraftStore();
const toast = useToastStore();

const days = ref(30);
const loading = ref(false);
const data = ref(null);
const activeTab = ref('overview');

const formatter = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });
function nf(v) { return formatter.format(v || 0); }

// Chart: only days with orders (skip zero days)
const chartDays = computed(() => {
  if (!data.value) return [];
  return data.value.days.filter(d => d.total > 0);
});
const maxTotal = computed(() => chartDays.value.length ? Math.max(...chartDays.value.map(d => d.total), 1) : 1);

const criticalAnomalies = computed(() => data.value ? data.value.anomalies.filter(a => a.severity === 'danger') : []);

const tabs = [
  { id: 'overview', label: 'Обзор' },
  { id: 'suppliers', label: 'Поставщики' },
  { id: 'products', label: 'Товары' },
  { id: 'anomalies', label: 'Аномалии' },
];

function barH(boxes) { return Math.max(Math.round((boxes / maxTotal.value) * 110), 4); }
function isTop(day, sup) {
  let last = null;
  for (const s of data.value.suppliers) { if (day.bySupplier[s.supplier]) last = s.supplier; }
  return last === sup;
}
function sPct(s) { return data.value.totals.boxes > 0 ? (s.boxes / data.value.totals.boxes * 100) : 0; }
function pPct(p) { return data.value.topProducts[0]?.boxes ? (p.boxes / data.value.topProducts[0].boxes * 100) : 0; }
function supDelta(s) { return s.prevBoxes > 0 ? Math.round((s.boxes - s.prevBoxes) / s.prevBoxes * 100) : null; }
function deltaCls(d) { return d === null ? '' : d >= 0 ? 'val-up' : 'val-down'; }
function typeLabel(t) {
  return { spike: 'Рост', drop: 'Падение', supplier: 'Поставщик', outlier: 'Выброс' }[t] || t;
}

async function loadOrder(orderId) {
  const { data: order, error } = await db
    .from('orders').select('*, order_items(*)').eq('id', orderId).single();
  if (error || !order) { toast.error('Ошибка', 'Не удалось загрузить заказ'); return; }
  await orderStore.loadOrderIntoForm(order, orderStore.settings.legalEntity, false, true);
  draftStore.saveNow();
  router.push({ name: 'order' });
  toast.success('Заказ загружен', 'Режим просмотра');
}

async function load() {
  loading.value = true;
  data.value = await getOrdersAnalytics(orderStore.settings.legalEntity, days.value);
  loading.value = false;
}

watch(() => orderStore.settings.legalEntity, () => load());
onMounted(() => load());
</script>

<style scoped>
.analytics-view { padding: 0; display: flex; flex-direction: column; }

/* Header */
.an-header {
  display: flex; align-items: center; justify-content: space-between;
  flex-shrink: 0; margin-bottom: 8px;
}
.an-period {
  padding: 5px 10px; border-radius: 6px; border: 1px solid var(--border);
  font-size: 12px; font-weight: 600; background: white;
}

/* Alert banner */
.an-alert-banner {
  padding: 7px 14px; background: #FFF3E0; border: 1px solid #FFCC80;
  border-radius: 8px; font-size: 12px; color: #E65100; cursor: pointer;
  display: flex; align-items: center; gap: 8px; flex-shrink: 0; margin-bottom: 8px;
}
.an-alert-link { margin-left: auto; font-weight: 600; }

/* Tabs */
.an-tabs {
  display: flex; gap: 0; border-bottom: 2px solid var(--border-light);
  margin-bottom: 12px; flex-shrink: 0;
}
.an-tab {
  padding: 7px 14px; font-size: 12px; font-weight: 600; border: none; cursor: pointer;
  border-bottom: 2px solid transparent; margin-bottom: -2px; background: none;
  color: var(--text-muted); transition: all 0.1s; display: flex; align-items: center; gap: 4px;
}
.an-tab.active { color: #5A2D0C; border-bottom-color: #5A2D0C; }
.an-tab:hover { color: #5A2D0C; }
.an-tab-badge {
  font-size: 10px; font-weight: 700; background: #F44336; color: #fff;
  padding: 0 5px; border-radius: 8px; line-height: 16px;
}

/* Content scroll area */
.an-content { flex: 1; overflow-y: auto; min-height: 0; }

/* KPI grid */
.an-kpi-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 12px; }
.an-kpi {
  background: white; border: 1px solid var(--border-light); border-radius: 10px; padding: 12px 14px;
}
.an-kpi-head { font-size: 11px; color: #8B7355; font-weight: 600; }
.an-kpi-icon { margin-right: 2px; }
.an-kpi-row { display: flex; align-items: baseline; gap: 6px; margin-top: 2px; }
.an-kpi-val { font-size: 24px; font-weight: 800; color: #3E2723; }
.an-kpi-sub { font-size: 10px; color: #999; margin-top: 1px; }

.an-badge {
  font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 8px;
}
.an-badge.up { background: #E8F5E9; color: #2E7D32; }
.an-badge.down { background: #FFEBEE; color: #C62828; }

/* Cards */
.an-card {
  background: white; border: 1px solid var(--border-light); border-radius: 10px;
  padding: 14px; margin-bottom: 12px;
}
.an-card-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 10px; flex-wrap: wrap; gap: 6px;
}
.an-card-title { font-size: 14px; font-weight: 700; color: #5A2D0C; }

/* Legend */
.an-legend { display: flex; flex-wrap: wrap; gap: 8px; }
.an-legend-item { display: flex; align-items: center; gap: 3px; font-size: 10px; color: #5A2D0C; }
.an-legend-dot { width: 8px; height: 8px; border-radius: 2px; flex-shrink: 0; }

/* Chart */
.an-chart {
  display: flex; align-items: flex-end; gap: 4px; height: 160px;
  overflow-x: auto; overflow-y: visible; padding-bottom: 24px;
}
.an-bar-col {
  flex: 1; min-width: 28px; max-width: 70px;
  display: flex; flex-direction: column; align-items: center;
}
.an-bar-num { font-size: 9px; font-weight: 700; color: #5A2D0C; margin-bottom: 2px; white-space: nowrap; }
.an-bar-stack {
  width: 85%; display: flex; flex-direction: column; justify-content: flex-end; height: 120px;
}
.an-bar-seg { width: 100%; flex-shrink: 0; }
.an-bar-label {
  font-size: 9px; color: #999; margin-top: 3px; border-top: 1px solid #e8e3db; padding-top: 2px;
  white-space: nowrap; text-align: center;
}

/* Supplier rows (overview) — bar aligned from right */
.an-sup-table { display: flex; flex-direction: column; }
.an-sup-row {
  display: flex; align-items: center; gap: 10px; padding: 5px 0;
  border-bottom: 1px solid #f5f0ea;
}
.an-sup-row:last-child { border-bottom: none; }
.an-sup-left { display: flex; align-items: center; gap: 6px; flex: 1; min-width: 0; }
.an-sup-dot { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
.an-sup-name { font-size: 12px; font-weight: 600; color: #5A2D0C; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.an-sup-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; width: 70%; }
.an-sup-bar-wrap { flex: 1; height: 12px; background: #f0ece5; border-radius: 6px; overflow: hidden; }
.an-sup-bar { height: 100%; border-radius: 6px; transition: width 0.4s; }
.an-sup-val { font-size: 11px; font-weight: 700; color: #5A2D0C; min-width: 50px; text-align: right; }
.an-sup-meta { font-size: 10px; color: #8B7355; min-width: 45px; text-align: right; }

/* Supplier cards (tab) */
.an-sup-card { padding: 14px 16px; }
.an-sup-card-head { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.an-sup-dot-lg { width: 14px; height: 14px; border-radius: 4px; flex-shrink: 0; }
.an-sup-card-name { font-size: 15px; font-weight: 700; color: #3E2723; flex: 1; }
.an-sup-card-ago { font-size: 11px; color: #8B7355; }

.an-sup-metrics { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
.an-sup-metric { background: #FAFAF7; padding: 8px 6px; border-radius: 6px; text-align: center; }
.an-sup-metric-val { font-size: 18px; font-weight: 800; color: #3E2723; }
.an-sup-metric-val.val-up { color: #2E7D32; font-size: 14px; }
.an-sup-metric-val.val-down { color: #C62828; font-size: 14px; }
.an-sup-metric-label { font-size: 9px; color: #8B7355; font-weight: 600; }

/* Products */
.an-prod-header {
  padding: 10px 14px; border-bottom: 1px solid #f0ece5;
  font-size: 14px; font-weight: 700; color: #5A2D0C;
}
.an-prod-row {
  display: flex; align-items: center; gap: 10px; padding: 9px 14px;
  border-bottom: 1px solid #f5f0ea;
}
.an-prod-row:last-child { border-bottom: none; }
.an-prod-rank {
  width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
  font-size: 10px; font-weight: 700; flex-shrink: 0; background: #e8e3db; color: #5A2D0C;
}
.an-prod-rank.top { background: #F5A623; color: #fff; font-size: 12px; }
.an-prod-info { flex: 1; min-width: 0; }
.an-prod-line1 { display: flex; align-items: baseline; gap: 5px; }
.an-prod-sku { font-size: 10px; font-weight: 700; color: #F5A623; }
.an-prod-name { font-size: 12px; font-weight: 600; color: #3E2723; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.an-prod-progress { height: 4px; background: #f0ece5; border-radius: 2px; overflow: hidden; margin-top: 3px; }
.an-prod-progress-bar { height: 100%; background: linear-gradient(90deg, #4CAF50, #81C784); border-radius: 2px; transition: width 0.4s; }
.an-prod-stats { text-align: right; min-width: 65px; flex-shrink: 0; }
.an-prod-boxes { font-size: 13px; font-weight: 700; color: #3E2723; }
.an-prod-delta { font-size: 10px; font-weight: 700; }
.an-prod-delta.up { color: #2E7D32; }
.an-prod-delta.down { color: #C62828; }
.an-prod-forecast { border-left: 1px solid #f0ece5; padding-left: 10px; min-width: 60px; text-align: right; flex-shrink: 0; }
.an-prod-forecast-label { font-size: 9px; color: #8B7355; }
.an-prod-forecast-val { font-size: 14px; font-weight: 700; color: #2196F3; }

.an-forecast-note {
  margin-top: 4px; padding: 8px 12px; background: #E3F2FD; border-radius: 8px;
  border: 1px solid #90CAF9; font-size: 12px; color: #1565C0;
}

/* Anomalies */
.an-anomaly {
  display: flex; align-items: flex-start; gap: 10px; padding: 10px 14px;
  margin-bottom: 6px; border-radius: 8px;
}
.an-anomaly.sev-danger { background: #FFF5F5; border: 1px solid #FFCDD2; }
.an-anomaly.sev-warning { background: #FFFCF0; border: 1px solid #FFE082; }
.an-anomaly.sev-info { background: #FAFAFA; border: 1px solid #E0E0E0; }
.an-anomaly-icon { font-size: 18px; flex-shrink: 0; margin-top: 1px; }
.an-anomaly-body { flex: 1; }
.an-anomaly-title { font-size: 13px; font-weight: 700; color: #3E2723; }
.an-anomaly-text { font-size: 12px; color: #5A2D0C; margin-top: 1px; }
.an-anomaly-detail { font-size: 11px; color: #8B7355; margin-top: 2px; }
.an-anomaly-tag {
  font-size: 9px; font-weight: 700; padding: 2px 8px; border-radius: 4px;
  flex-shrink: 0; white-space: nowrap;
}
.sev-danger .an-anomaly-tag { background: #FFCDD2; color: #B71C1C; }
.sev-warning .an-anomaly-tag { background: #FFE082; color: #BF360C; }
.sev-info .an-anomaly-tag { background: #E0E0E0; color: #424242; }

.an-anomaly.clickable { cursor: pointer; }
.an-anomaly.clickable:hover { opacity: 0.85; }
.an-anomaly-go {
  font-size: 16px; font-weight: 700; color: #5A2D0C; flex-shrink: 0; align-self: center;
}

@media (max-width: 600px) {
  .an-kpi-grid { grid-template-columns: 1fr; }
  .an-sup-metrics { grid-template-columns: repeat(2, 1fr); }
}
</style>
