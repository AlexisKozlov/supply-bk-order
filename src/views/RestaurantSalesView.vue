<template>
  <div class="rsv">
    <!-- Header -->
    <div class="rsv-header">
      <h1 v-if="!embedded" class="page-title" style="margin-bottom:0">Реализация ресторанов</h1>
      <div class="rsv-controls">
        <select v-model="period" class="rsv-select">
          <option value="7">7 дней</option>
          <option value="14">14 дней</option>
          <option value="30">30 дней</option>
          <option value="90">3 месяца</option>
        </select>
        <input v-model="searchQuery" class="rsv-search" placeholder="Поиск группы…" />
        <select v-model="supplierFilter" class="rsv-select">
          <option value="">Все поставщики</option>
          <option v-for="s in supplierList" :key="s" :value="s">{{ s }}</option>
        </select>
        <select v-model="sortKey" class="rsv-select">
          <option value="total-desc">По объёму ↓</option>
          <option value="total-asc">По объёму ↑</option>
          <option value="trend-desc">По росту ↓</option>
          <option value="trend-asc">По росту ↑</option>
          <option value="name-asc">По имени А-Я</option>
          <option value="restaurants-desc">По ресторанам ↓</option>
        </select>
        <label class="rsv-check"><input type="checkbox" v-model="hideZero" /> Только актуальные</label>
        <button v-if="!isViewer" class="btn small" @click="doImport" :disabled="importing">
          <BkIcon v-if="importing" name="loading" size="sm" />
          <BkIcon v-else name="import" size="sm" /> Импорт
        </button>
      </div>
    </div>

    <!-- Import overlay -->
    <div v-if="importing" class="rsv-overlay">
      <BurgerSpinner />
      <p class="rsv-overlay-text">Загрузка файла…</p>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="rsv-center"><BurgerSpinner /></div>

    <!-- Empty -->
    <div v-else-if="!rawData.length" class="rsv-center rsv-empty">
      <p><b>Нет данных о реализации</b></p>
      <p class="rsv-muted">Загрузите файл Excel с данными реализации ресторанов</p>
      <button v-if="!isViewer" class="btn" @click="doImport" style="margin-top:12px">
        <BkIcon name="import" size="sm" /> Импорт
      </button>
    </div>

    <template v-else>
      <!-- Info -->
      <div class="rsv-info">
        <span>{{ periodLabel }} · {{ filteredGroups.length }} из {{ allGroups.length }} групп · Данные: {{ salesMeta.dateRange }}</span>
      </div>

      <!-- Table -->
      <div class="rsv-table-wrap">
        <table class="rsv-table">
          <thead>
            <tr>
              <th class="rsv-th-name">Группа аналогов</th>
              <th class="rsv-th-num" title="Суммарная реализация за выбранный период">За период</th>
              <th class="rsv-th-num" title="Средняя реализация в день">Ср. / день</th>
              <th class="rsv-th-num" title="Максимальное количество ресторанов, продававших товар">Рест.</th>
              <th class="rsv-th-num" title="Изменение по сравнению с предыдущим аналогичным периодом">Тренд</th>
              <th class="rsv-th-chart" title="Динамика за последние 7 дней">7 дней</th>
              <th class="rsv-th-num" title="Количество дней с данными в выбранном периоде">Дни</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="g in paginatedGroups" :key="g.name">
              <tr class="rsv-row" :class="{ 'rsv-row-open': expanded === g.name }" @click="toggleExpand(g.name)">
                <td class="rsv-td-name">
                  <svg class="rsv-chevron" :class="{ open: expanded === g.name }" viewBox="0 0 16 16" width="11" height="11"><path d="M5 3l5 5-5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  <span>{{ g.name }}</span>
                  <span v-if="!g.inDb" class="rsv-badge-new" title="Группа ещё не добавлена в базу товаров">новая</span>
                </td>
                <td class="rsv-td-num"><b>{{ fmtNum(g.total) }}</b></td>
                <td class="rsv-td-num">{{ fmtNum(g.avgDay) }}</td>
                <td class="rsv-td-num">{{ g.maxRc }}</td>
                <td class="rsv-td-num">
                  <span v-if="g.hasPrev" :class="trendClass(g.trend)">{{ g.trend > 0 ? '+' : '' }}{{ g.trend }}%</span>
                  <span v-else class="rsv-muted">—</span>
                </td>
                <td class="rsv-td-chart">
                  <svg class="rsv-sparkline" viewBox="0 0 70 20" preserveAspectRatio="none">
                    <path :d="sparkline(g.last7)" stroke="#FF8732" stroke-width="1.5" fill="none" />
                  </svg>
                </td>
                <td class="rsv-td-num rsv-td-days-cnt">{{ g.dayCount }}</td>
              </tr>
              <!-- Expanded detail -->
              <tr v-if="expanded === g.name" class="rsv-detail-row">
                <td :colspan="7">
                  <div class="rsv-detail">
                    <!-- Daily chart -->
                    <div class="rsv-detail-section">
                      <div class="rsv-detail-title">Реализация по дням</div>
                      <div class="rsv-daily-chart">
                        <div class="rsv-chart-y">
                          <span>{{ fmtNum(g.maxDay) }}</span>
                          <span>{{ fmtNum(Math.round(g.maxDay / 2)) }}</span>
                          <span>0</span>
                        </div>
                        <div class="rsv-chart-area">
                          <div class="rsv-chart-bars">
                            <div
                              v-for="(d, i) in g.dailyData"
                              :key="i"
                              class="rsv-bar-col"
                              :title="`${d.label} (${d.dow}): ${fmtNum(d.qty)} · ${d.rc} рест.`"
                            >
                              <div class="rsv-bar" :class="{ 'rsv-bar-we': d.isWe }" :style="{ height: barH(d.qty, g.maxDay) }"></div>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- Weekly trends -->
                    <div v-if="g.weeklyData.length > 1" class="rsv-detail-section">
                      <div class="rsv-detail-title">Динамика по неделям</div>
                      <div class="rsv-weekly">
                        <div v-for="(w, wi) in g.weeklyData" :key="wi" class="rsv-wk-col">
                          <div class="rsv-wk-change" v-if="w.change !== null" :class="trendClass(w.change)">
                            {{ w.change > 0 ? '+' : '' }}{{ w.change }}%
                          </div>
                          <div class="rsv-wk-change" v-else>&nbsp;</div>
                          <div class="rsv-wk-val">{{ fmtNum(w.total) }}</div>
                          <div class="rsv-wk-bar-area">
                            <div class="rsv-wk-bar" :style="{ height: barH(w.total, g.weeklyMax) }"></div>
                          </div>
                          <div class="rsv-wk-label">{{ w.label }}</div>
                        </div>
                      </div>
                    </div>

                    <div class="rsv-detail-cols">
                      <!-- Weekday averages -->
                      <div class="rsv-detail-section">
                        <div class="rsv-detail-title">По дням недели (средн.)</div>
                        <div class="rsv-wd-chart">
                          <div v-for="(w, i) in g.weekdays" :key="i" class="rsv-wd-col">
                            <div class="rsv-wd-val">{{ fmtNum(w.avg) }}</div>
                            <div class="rsv-wd-bar-area"><div class="rsv-wd-bar" :class="{ 'rsv-bar-we': w.isWe }" :style="{ height: barH(w.avg, g.wdMax) }"></div></div>
                            <div class="rsv-wd-label" :class="{ 'rsv-wd-we': w.isWe }">{{ w.label }}</div>
                          </div>
                        </div>
                      </div>

                      <!-- Key numbers -->
                      <div class="rsv-detail-section">
                        <div class="rsv-detail-title">Показатели</div>
                        <div class="rsv-metrics">
                          <div class="rsv-metric"><span>Всего за период</span><b>{{ fmtNum(g.total) }}</b></div>
                          <div class="rsv-metric"><span>Среднее в день</span><b>{{ fmtNum(g.avgDay) }}</b></div>
                          <div class="rsv-metric"><span>Макс. за день</span><b>{{ fmtNum(g.maxDay) }}</b></div>
                          <div class="rsv-metric"><span>Мин. за день</span><b>{{ fmtNum(g.minDay) }}</b></div>
                          <div class="rsv-metric"><span>Рест. (средн.)</span><b>{{ g.avgRestaurants }}</b></div>
                          <div class="rsv-metric"><span>Рест. (макс.)</span><b>{{ g.maxRc }}</b></div>
                          <div class="rsv-metric"><span>Выходные vs будни</span><b :class="trendClass(g.weDiff)">{{ g.weDiff > 0 ? '+' : '' }}{{ g.weDiff }}%</b></div>
                          <div class="rsv-metric"><span>Пиковый день</span><b>{{ g.peakDay }}</b></div>
                          <div v-if="g.hasPrev" class="rsv-metric"><span>Пред. период</span><b>{{ fmtNum(g.prevTotal) }}</b></div>
                          <div v-if="g.hasPrev" class="rsv-metric"><span>Изменение</span><b :class="trendClass(g.trend)">{{ g.trend > 0 ? '+' : '' }}{{ g.trend }}%</b></div>
                        </div>
                      </div>
                    </div>

                    <!-- Products in group -->
                    <div v-if="g.products.length" class="rsv-detail-section">
                      <div class="rsv-detail-title">Товары в группе ({{ g.products.length }})</div>
                      <div class="rsv-prods">
                        <div v-for="p in g.products" :key="p.sku" class="rsv-prod">
                          <span class="rsv-prod-sku">{{ p.sku }}</span>
                          <span class="rsv-prod-name">{{ p.name }}</span>
                          <span class="rsv-prod-sup">{{ p.supplier }}</span>
                        </div>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="filteredGroups.length > pageSize" class="rsv-pag">
        <button class="btn small" :disabled="page === 1" @click="page--">&laquo;</button>
        <span class="rsv-pag-info">{{ (page - 1) * pageSize + 1 }}–{{ Math.min(page * pageSize, filteredGroups.length) }} из {{ filteredGroups.length }}</span>
        <button class="btn small" :disabled="page * pageSize >= filteredGroups.length" @click="page++">&raquo;</button>
      </div>
    </template>

    <input ref="fileInput" type="file" accept=".xlsx,.xls" style="display:none" @change="onFileSelected" />
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { db } from '@/lib/apiClient.js';
import { parseSalesFile } from '@/lib/salesImport.js';
import { useUserStore } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';

const props = defineProps({ embedded: { type: Boolean, default: false } });

const userStore = useUserStore();
const toast = useToastStore();
const isViewer = computed(() => !userStore.hasAccess('analysis', 'edit'));

const loading = ref(false);
const importing = ref(false);
const rawData = ref([]);
const dbGroups = ref(new Set());
const productsByGroup = ref({});
const fileInput = ref(null);

const period = ref('30');
const searchQuery = ref('');
const sortKey = ref('total-desc');
const hideZero = ref(true);
const supplierFilter = ref('');
const expanded = ref(null);
const page = ref(1);
const pageSize = 50;

const dowNames = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
const dowOrder = [1, 2, 3, 4, 5, 6, 0]; // Пн-Вс

// ═══ Data loading ═══

async function loadData() {
  loading.value = true;
  try {
    const pd = parseInt(period.value);
    // Загружаем текущий + предыдущий период для тренда + запас
    const daysNeeded = pd * 2 + 14;
    const cutoff = new Date();
    cutoff.setDate(cutoff.getDate() - daysNeeded);
    const cutStr = fmtISO(cutoff);

    const { data, error } = await db.from('restaurant_sales')
      .select('sale_date, analog_group, quantity, restaurant_count')
      .gte('sale_date', cutStr)
      .order('sale_date', { ascending: true })
      .limit(50000);
    if (error) { toast.error('Ошибка', 'Не удалось загрузить данные'); return; }
    rawData.value = data || [];
  } catch (e) { toast.error('Ошибка', e.message); }
  finally { loading.value = false; }
}

async function loadProducts() {
  const { data } = await db.from('products').select('sku, name, analog_group, supplier').eq('is_active', 1);
  if (!data) return;
  const gs = new Set(), bg = {};
  for (const p of data) {
    if (!p.analog_group) continue;
    gs.add(p.analog_group);
    if (!bg[p.analog_group]) bg[p.analog_group] = [];
    bg[p.analog_group].push(p);
  }
  dbGroups.value = gs;
  productsByGroup.value = bg;
}

onMounted(() => { loadData(); loadProducts(); });

// ═══ Period boundaries ═══

const periodDays = computed(() => parseInt(period.value));
const periodLabel = computed(() => {
  const d = periodDays.value;
  if (d <= 30) return d + ' дней';
  if (d === 90) return '3 месяца';
  return '';
});

// Берём дату последней записи как «сегодня» (данные всегда за вчера максимум)
const lastDate = computed(() => {
  if (!rawData.value.length) return null;
  return rawData.value[rawData.value.length - 1].sale_date;
});

function periodBounds(daysAgo, length) {
  if (!lastDate.value) return { from: '', to: '' };
  const end = new Date(lastDate.value + 'T12:00:00');
  const to = new Date(end);
  to.setDate(to.getDate() - daysAgo);
  const from = new Date(to);
  from.setDate(from.getDate() - length + 1);
  return { from: fmtISO(from), to: fmtISO(to) };
}

const currentBounds = computed(() => periodBounds(0, periodDays.value));
const prevBounds = computed(() => periodBounds(periodDays.value, periodDays.value));

const currentPeriod = computed(() =>
  rawData.value.filter(r => r.sale_date >= currentBounds.value.from && r.sale_date <= currentBounds.value.to)
);
const previousPeriod = computed(() =>
  rawData.value.filter(r => r.sale_date >= prevBounds.value.from && r.sale_date <= prevBounds.value.to)
);

const salesMeta = computed(() => {
  if (!rawData.value.length) return {};
  return {
    dateRange: fmtDateRu(currentBounds.value.from) + ' — ' + fmtDateRu(currentBounds.value.to),
  };
});

// ═══ Build groups ═══

const allGroups = computed(() => {
  const curMap = {};
  for (const r of currentPeriod.value) {
    if (!curMap[r.analog_group]) curMap[r.analog_group] = [];
    curMap[r.analog_group].push(r);
  }
  const prevMap = {};
  for (const r of previousPeriod.value) {
    if (!prevMap[r.analog_group]) prevMap[r.analog_group] = 0;
    prevMap[r.analog_group] += parseFloat(r.quantity) || 0;
  }

  return Object.entries(curMap).map(([name, rows]) => {
    const total = rows.reduce((s, r) => s + (parseFloat(r.quantity) || 0), 0);
    const rcRows = rows.filter(r => parseInt(r.restaurant_count) > 0);
    const totalRc = rcRows.reduce((s, r) => s + (parseInt(r.restaurant_count) || 0), 0);
    const dayCount = rows.length;
    const avgDay = dayCount ? Math.round(total / dayCount) : 0;
    const avgRestaurants = rcRows.length ? Math.round(totalRc / rcRows.length) : 0;

    // Trend vs previous period
    const prevTotal = prevMap[name] || 0;
    const hasPrev = prevTotal > 0;
    const trend = hasPrev ? Math.round((total - prevTotal) / prevTotal * 100) : 0;

    // Sparkline: last 7 days
    const last7 = rows.slice(-7).map(r => parseFloat(r.quantity) || 0);

    // Daily chart data
    const dailyData = rows.map(r => {
      const dt = new Date(r.sale_date + 'T12:00:00');
      return {
        label: fmtDateRu(r.sale_date),
        dow: dowNames[dt.getDay()],
        qty: parseFloat(r.quantity) || 0,
        rc: parseInt(r.restaurant_count) || 0,
        isWe: dt.getDay() === 0 || dt.getDay() === 6,
      };
    });
    const qtys = dailyData.map(d => d.qty);
    const maxDay = Math.max(...qtys, 1);
    const minDay = qtys.length ? Math.round(Math.min(...qtys)) : 0;
    const maxRc = Math.max(...dailyData.map(d => d.rc), 0);

    // Weekdays Пн-Вс
    const wdBuckets = [[], [], [], [], [], [], []];
    for (const d of dailyData) {
      const dt = new Date(rows[dailyData.indexOf(d)].sale_date + 'T12:00:00');
      wdBuckets[dt.getDay()].push(d.qty);
    }
    const weekdays = dowOrder.map(i => ({
      label: dowNames[i],
      avg: wdBuckets[i].length ? Math.round(wdBuckets[i].reduce((a, b) => a + b, 0) / wdBuckets[i].length) : 0,
      isWe: i === 0 || i === 6,
    }));
    const wdMax = Math.max(...weekdays.map(w => w.avg), 1);
    const wdWorkdays = weekdays.slice(0, 5);
    const wdWeekend = weekdays.slice(5);
    const wdAvg = wdWorkdays.reduce((s, w) => s + w.avg, 0) / 5;
    const weAvg = (wdWeekend[0].avg + wdWeekend[1].avg) / 2;
    const weDiff = wdAvg > 0 ? Math.round((weAvg - wdAvg) / wdAvg * 100) : 0;
    const peakIdx = weekdays.reduce((pi, w, i, a) => w.avg > a[pi].avg ? i : pi, 0);

    // Дата последней продажи
    const lastSaleDate = rows[rows.length - 1]?.sale_date || '';

    // Недельные тренды — из rawData, только полные недели (7 дней)
    const weekMap = {};
    for (const r of rawData.value) {
      if (r.analog_group !== name) continue;
      const dt = new Date(r.sale_date + 'T12:00:00');
      const day = dt.getDay() || 7; // Вс=7
      const mon = new Date(dt);
      mon.setDate(mon.getDate() - day + 1);
      const weekKey = fmtISO(mon);
      if (!weekMap[weekKey]) weekMap[weekKey] = { start: weekKey, total: 0, days: 0 };
      weekMap[weekKey].total += parseFloat(r.quantity) || 0;
      weekMap[weekKey].days++;
    }
    // Оставляем только полные недели (7 дней данных)
    const weeklyData = Object.values(weekMap)
      .filter(w => w.days === 7)
      .sort((a, b) => a.start.localeCompare(b.start));
    for (let wi = 0; wi < weeklyData.length; wi++) {
      const wk = weeklyData[wi];
      wk.total = Math.round(wk.total);
      wk.label = fmtDateRu(wk.start);
      if (wi > 0 && weeklyData[wi - 1].total > 0) {
        wk.change = Math.round((wk.total - weeklyData[wi - 1].total) / weeklyData[wi - 1].total * 100);
      } else {
        wk.change = null;
      }
    }
    const weeklyMax = Math.max(...weeklyData.map(w => w.total), 1);

    return {
      name, total: Math.round(total), avgDay, avgRestaurants, trend, hasPrev,
      prevTotal: Math.round(prevTotal), last7, dayCount, lastSaleDate,
      dailyData, maxDay, minDay, maxRc, weekdays, wdMax, weDiff,
      peakDay: weekdays[peakIdx].label,
      inDb: dbGroups.value.has(name),
      products: productsByGroup.value[name] || [],
      weeklyData, weeklyMax,
    };
  });
});

// ═══ Filtering & sorting ═══

// Список поставщиков из справочника
const supplierList = computed(() => {
  const set = new Set();
  for (const prods of Object.values(productsByGroup.value)) {
    for (const p of prods) { if (p.supplier) set.add(p.supplier); }
  }
  return [...set].sort((a, b) => a.localeCompare(b, 'ru'));
});

// Группы аналогов, связанные с выбранным поставщиком
const supplierGroups = computed(() => {
  if (!supplierFilter.value) return null;
  const groups = new Set();
  for (const [group, prods] of Object.entries(productsByGroup.value)) {
    if (prods.some(p => p.supplier === supplierFilter.value)) groups.add(group);
  }
  return groups;
});

const filteredGroups = computed(() => {
  let list = allGroups.value;
  if (supplierGroups.value) {
    list = list.filter(g => supplierGroups.value.has(g.name));
  }
  if (hideZero.value && lastDate.value) {
    // Скрываем группы без реальных продаж или где последняя продажа была более 3 дней назад
    const ld = new Date(lastDate.value + 'T12:00:00');
    ld.setDate(ld.getDate() - 3);
    const cutoff = fmtISO(ld);
    list = list.filter(g => g.total > 0 && g.lastSaleDate >= cutoff);
  }
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase();
    list = list.filter(g => g.name.toLowerCase().includes(q));
  }
  const [field, dir] = sortKey.value.split('-');
  const m = dir === 'asc' ? 1 : -1;
  return [...list].sort((a, b) => {
    if (field === 'name') return m * a.name.localeCompare(b.name, 'ru');
    if (field === 'trend') return m * (a.trend - b.trend);
    if (field === 'restaurants') return m * (a.avgRestaurants - b.avgRestaurants);
    return m * (a.total - b.total);
  });
});

const paginatedGroups = computed(() =>
  filteredGroups.value.slice((page.value - 1) * pageSize, page.value * pageSize)
);

// ═══ UI ═══

function toggleExpand(name) { expanded.value = expanded.value === name ? null : name; }

watch(period, () => { page.value = 1; expanded.value = null; loadData(); });
watch(searchQuery, () => { page.value = 1; });
watch(sortKey, () => { page.value = 1; });
watch(supplierFilter, () => { page.value = 1; });

// ═══ Helpers ═══

function fmtISO(d) {
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}
function fmtDateRu(s) {
  if (!s) return '';
  const p = s.split('-');
  return p[2] + '.' + p[1];
}
function fmtNum(n) {
  if (n == null || isNaN(n)) return '—';
  return Math.round(n).toLocaleString('ru-RU');
}
function barH(v, max) { return max ? Math.max(1, v / max * 100) + '%' : '0%'; }
function trendClass(t) { return t > 5 ? 'rsv-trend-up' : t < -5 ? 'rsv-trend-down' : ''; }
function sparkline(data) {
  if (!data || data.length < 2) return '';
  const max = Math.max(...data, 1), step = 70 / (data.length - 1);
  return data.map((v, i) => ((i ? 'L' : 'M') + (i * step).toFixed(1) + ' ' + (18 - v / max * 16).toFixed(1))).join(' ');
}

// ═══ Import ═══

function doImport() { fileInput.value?.click(); }

async function onFileSelected(e) {
  const file = e.target.files?.[0];
  if (!file) return;
  e.target.value = '';
  importing.value = true;
  try {
    // Загружаем карту артикул→группа аналогов из базы товаров
    const { data: prods } = await db.from('products').select('sku, analog_group').neq('analog_group', '');
    const skuToGroup = {};
    if (prods) prods.forEach(p => { if (p.sku && p.analog_group) skuToGroup[p.sku] = p.analog_group; });

    const result = await parseSalesFile(file, skuToGroup);
    const items = result.items || result;
    const skuMapped = result.skuMapped || 0;
    if (!items.length) { toast.error('Ошибка', 'Не удалось распознать данные'); return; }
    toast.info('Загрузка', `Отправляю ${items.length.toLocaleString('ru')} записей…` + (skuMapped ? ` (${skuMapped} по артикулу)` : ''));
    for (let i = 0; i < items.length; i += 10000) {
      const isLast = i + 10000 >= items.length;
      const { error } = await db.rpc('replace_restaurant_sales', { items: items.slice(i, i + 10000), notify: isLast });
      if (error) { toast.error('Ошибка', error); return; }
    }
    toast.success('Готово', `Загружено ${items.length.toLocaleString('ru')} записей` + (skuMapped ? `, ${skuMapped} привязано по артикулу` : ''));
    await loadData();
  } catch (err) { toast.error('Ошибка', err.message); }
  finally { importing.value = false; }
}

// parseSalesFile — из @/lib/salesImport.js
</script>

<style scoped>
.rsv { display: flex; flex-direction: column; height: 100%; overflow: hidden; gap: 0; flex: 1; min-height: 0; position: relative; }

/* Header */
.rsv-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 4px; flex-shrink: 0; }
.rsv-controls { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.rsv-select { padding: 5px 8px; border: 1.5px solid var(--border); border-radius: 8px; background: var(--card); color: var(--text); font-size: 12px; }
.rsv-search { padding: 5px 10px; border: 1.5px solid var(--border); border-radius: 8px; background: var(--card); color: var(--text); font-size: 12px; width: 180px; }
.rsv-check { display: flex; align-items: center; gap: 4px; font-size: 12px; color: var(--text-muted); cursor: pointer; white-space: nowrap; user-select: none; }
.rsv-check input { cursor: pointer; }

.rsv-overlay { position: absolute; inset: 0; z-index: 10; display: flex; flex-direction: column; align-items: center; justify-content: center; background: var(--bg-overlay, rgba(0,0,0,0.35)); border-radius: 12px; gap: 12px; }
.rsv-overlay-text { color: #fff; font-size: 14px; font-weight: 600; }
.rsv-center { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 60px 20px; }
.rsv-empty { color: var(--text-muted); }
.rsv-muted { color: var(--text-muted); font-size: 12px; }

/* Info */
.rsv-info { font-size: 11px; color: var(--text-muted); padding: 4px 0; flex-shrink: 0; }

/* Table */
.rsv-table-wrap { flex: 1; overflow: auto; min-height: 0; }
.rsv-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.rsv-table thead { position: sticky; top: 0; z-index: 2; }
.rsv-table th {
  padding: 7px 10px; text-align: left; font-size: 11px; font-weight: 700;
  color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.3px;
  background: var(--card); border-bottom: 2px solid var(--border);
  white-space: nowrap; user-select: none;
}
.rsv-th-num { text-align: right; width: 80px; }
.rsv-th-chart { text-align: center; width: 80px; }

/* Rows */
.rsv-row { cursor: pointer; transition: background 0.1s; }
.rsv-row:hover { background: var(--bg); }
.rsv-row-open { background: var(--bg); }
.rsv-row td { padding: 6px 10px; border-bottom: 1px solid var(--border-light); }

.rsv-td-name { display: flex; align-items: center; gap: 6px; font-weight: 600; }
.rsv-td-num { text-align: right; font-variant-numeric: tabular-nums; }
.rsv-td-chart { text-align: center; }
.rsv-td-days-cnt { color: var(--text-muted); font-size: 12px; }

.rsv-chevron { transition: transform 0.15s; color: var(--text-muted); flex-shrink: 0; }
.rsv-chevron.open { transform: rotate(90deg); }

.rsv-badge-new { font-size: 9px; font-weight: 700; padding: 1px 5px; border-radius: 4px; background: #FFF3E0; color: #E65100; }

.rsv-sparkline { width: 70px; height: 20px; }

/* Trends */
.rsv-trend-up { color: #2E7D32 !important; font-weight: 700; }
.rsv-trend-down { color: #C62828 !important; font-weight: 700; }

/* Detail row */
.rsv-detail-row td { padding: 0 !important; border-bottom: 2px solid #FF8732; }
.rsv-detail { padding: 14px 10px; background: var(--bg); }

.rsv-detail-section { margin-bottom: 14px; }
.rsv-detail-section:last-child { margin-bottom: 0; }
.rsv-detail-title { font-size: 10px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px; }

.rsv-detail-cols { display: grid; grid-template-columns: 240px 1fr; gap: 16px; }

/* Daily chart */
.rsv-daily-chart { display: flex; height: 110px; }
.rsv-chart-y { display: flex; flex-direction: column; justify-content: space-between; font-size: 10px; color: var(--text-muted); width: 44px; text-align: right; padding-right: 6px; flex-shrink: 0; }
.rsv-chart-area { flex: 1; min-width: 0; }
.rsv-chart-bars { display: flex; align-items: flex-end; height: 100%; gap: 0; }
.rsv-bar-col { flex: 1; height: 100%; display: flex; align-items: flex-end; min-width: 0; padding: 0 0.3px; }
.rsv-bar { width: 100%; background: #FF8732; border-radius: 1px 1px 0 0; transition: height 0.15s; min-height: 1px; }
.rsv-bar:hover { opacity: 0.7; }
.rsv-bar-we { background: #FFB74D; }

/* Weekday chart */
.rsv-wd-chart { display: flex; gap: 4px; height: 100px; }
.rsv-wd-col { flex: 1; display: flex; flex-direction: column; align-items: center; height: 100%; }
.rsv-wd-val { font-size: 10px; color: var(--text-muted); font-variant-numeric: tabular-nums; }
.rsv-wd-bar-area { flex: 1; width: 100%; display: flex; align-items: flex-end; justify-content: center; }
.rsv-wd-bar { width: 100%; max-width: 28px; background: #FF8732; border-radius: 3px 3px 0 0; transition: height 0.15s; }
.rsv-wd-label { font-size: 10px; font-weight: 600; color: var(--text-muted); margin-top: 2px; }
.rsv-wd-we { color: #FF8732 !important; }

/* Weekly trends */
.rsv-weekly { display: flex; gap: 2px; height: 130px; }
.rsv-wk-col { flex: 1; display: flex; flex-direction: column; align-items: center; min-width: 0; }
.rsv-wk-change { font-size: 10px; font-weight: 700; white-space: nowrap; margin-bottom: 1px; }
.rsv-wk-val { font-size: 10px; color: var(--text-muted); font-variant-numeric: tabular-nums; }
.rsv-wk-bar-area { flex: 1; width: 100%; display: flex; align-items: flex-end; justify-content: center; }
.rsv-wk-bar { width: 100%; max-width: 40px; background: #FF8732; border-radius: 3px 3px 0 0; transition: height 0.15s; min-height: 2px; }
.rsv-wk-label { font-size: 10px; font-weight: 600; color: var(--text-muted); margin-top: 2px; white-space: nowrap; }

/* Metrics */
.rsv-metrics { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; }
.rsv-metric { display: flex; justify-content: space-between; padding: 4px 8px; background: var(--card); border-radius: 5px; font-size: 12px; }
.rsv-metric span { color: var(--text-muted); }
.rsv-metric b { font-variant-numeric: tabular-nums; }

/* Products */
.rsv-prods { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 3px; }
.rsv-prod { display: flex; gap: 6px; font-size: 12px; padding: 4px 8px; background: var(--card); border-radius: 5px; }
.rsv-prod-sku { color: var(--text-muted); min-width: 50px; }
.rsv-prod-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rsv-prod-sup { color: var(--text-muted); font-size: 11px; }

/* Pagination */
.rsv-pag { display: flex; align-items: center; justify-content: center; gap: 12px; padding: 8px 0; flex-shrink: 0; }
.rsv-pag-info { font-size: 12px; color: var(--text-muted); }

/* Mobile */
@media (max-width: 900px) {
  .rsv-detail-cols { grid-template-columns: 1fr; }
  .rsv-weekly { height: 110px; }
}
@media (max-width: 768px) {
  .rsv-th-chart, .rsv-td-chart { display: none; }
  .rsv-th-num:nth-child(4), .rsv-td-num:nth-child(4) { display: none; } /* Рест. */
  .rsv-th-num:last-child, .rsv-td-days-cnt { display: none; } /* Дни */
  .rsv-controls { width: 100%; }
  .rsv-search { flex: 1; min-height: 36px; font-size: 14px; }
  .rsv-select { font-size: 14px; min-height: 36px; }
  .rsv-metrics { grid-template-columns: 1fr; }
  .rsv-table { font-size: 12px; }
  .rsv-row td { padding: 8px 6px; }
  .rsv-th-num { width: 60px; }
  .rsv-td-name { gap: 4px; }
  .rsv-daily-chart { height: 80px; }
  .rsv-wd-chart { height: 80px; }
  .rsv-prods { grid-template-columns: 1fr; }
}
@media (max-width: 480px) {
  .rsv-header { flex-direction: column; align-items: flex-start; }
  .rsv-controls { flex-direction: column; align-items: stretch; }
  .rsv-select { width: 100%; }
  .rsv-search { width: 100%; }
  .rsv-check { justify-content: flex-start; }
  .rsv-th-name { font-size: 10px; }
  .rsv-td-name span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 140px; display: inline-block; vertical-align: middle; }
}
</style>
