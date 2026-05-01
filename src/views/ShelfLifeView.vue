<template>
  <div class="sl-view">
    <!-- Header -->
    <div class="sl-header">
      <div style="display:flex;align-items:center;gap:16px;">
        <h1 class="page-title" style="margin:0;">{{ slTab === 'shelf' ? 'Сроки годности' : 'Загрузка склада' }}</h1>
        <!-- freshness badge removed: confusing (shows page open time, not data import time) -->
        <div class="sl-mode-tabs">
          <button class="sl-mode-tab" :class="{ active: slTab === 'shelf' }" @click="slTab = 'shelf'">Сроки</button>
          <button class="sl-mode-tab" :class="{ active: slTab === 'cells' }" @click="slTab = 'cells'; loadCellStats()">Ячейки</button>
        </div>
      </div>
      <div class="sl-header-right">
        <!-- Табы юрлиц -->
        <div v-if="entityTabs.length > 1" class="sl-tabs">
          <button class="sl-tab" :class="{ active: !filterCustomer }" @click="setEntity('')">
            Все
          </button>
          <button
            v-for="c in entityTabs" :key="c"
            class="sl-tab"
            :class="{ active: filterCustomer === c }"
            @click="setEntity(c)"
          >{{ shortName(c) }}</button>
        </div>
        <button
          v-if="userStore.hasAccess('shelf-life', 'edit')"
          class="sl-upload-btn"
          @click="handleUpload"
          :disabled="uploading"
        >
          <BkIcon name="send" size="sm"/>
          <BurgerSpinner v-if="uploading" size="xs" />
          <span>{{ uploading ? 'Загрузка...' : 'Загрузить файл' }}</span>
        </button>
      </div>
    </div>

    <!-- ═══ Вкладка «Ячейки» ═══ -->
    <div v-if="slTab === 'cells'" class="sl-cells-section">
      <div v-if="cellsLoading" class="sl-loader"><BurgerSpinner text="Загрузка..." /></div>
      <template v-else-if="cellStats.length">
        <div class="sl-filter-bar" style="margin-bottom:10px;">
          <select v-model="cellPeriod" style="padding:5px 8px;border:1.5px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-size:12px;">
            <option :value="30">30 дней</option>
            <option :value="60">60 дней</option>
            <option :value="90">90 дней</option>
          </select>
        </div>

        <!-- Таблица -->
        <div style="overflow-x:auto;">
          <table class="sl-cells-table">
            <thead>
              <tr>
                <th>Дата</th>
                <th v-for="e in cellTableEntities" :key="e" :colspan="stockTypesFor(e).length + 1" class="cell-entity-header">{{ e }}</th>
              </tr>
              <tr>
                <th></th>
                <template v-for="e in cellTableEntities" :key="'h'+e">
                  <th v-for="(st, si) in stockTypesFor(e)" :key="st" :class="{ 'cell-border-left': si === 0 }">{{ STOCK_TYPE_LABELS[st] }}</th>
                  <th class="cell-total">Итого</th>
                </template>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(day, di) in cellTableRows" :key="day.date" :style="di === 0 ? 'font-weight:600;background:rgba(244,162,97,.06);' : ''">
                <td>{{ fmtCellDate(day.date) }}</td>
                <template v-for="e in cellTableEntities" :key="day.date+e">
                  <td v-for="(st, si) in stockTypesFor(e)" :key="st" :class="[si === 0 ? 'cell-border-left' : '', { 'cell-manual': day.manual[e]?.[st] }]" @dblclick="canEditCells && startCellEdit(day.date, e, st, day.data[e]?.[st] || 0)">
                    <template v-if="cellEditing?.date === day.date && cellEditing?.entity === e && cellEditing?.type === st">
                      <input type="number" v-model.number="cellEditing.value" class="cell-edit-input" min="0"
                        @keyup.enter="saveCellEdit" @keyup.escape="cellEditing = null" @blur="saveCellEdit" />
                    </template>
                    <template v-else>
                      <div class="cell-layout"><span class="cell-val">{{ day.data[e]?.[st] || '—' }}</span><span class="cell-delta" :class="day.delta[e]?.[st] ? (day.delta[e][st] > 0 ? 'cell-up' : 'cell-down') : ''">{{ day.delta[e]?.[st] ? (day.delta[e][st] > 0 ? '+' : '') + day.delta[e][st] : '' }}</span></div>
                    </template>
                  </td>
                  <td class="cell-total"><div class="cell-layout"><span class="cell-val">{{ day.data[e]?.total || '—' }}</span><span class="cell-delta" :class="day.delta[e]?.total ? (day.delta[e].total > 0 ? 'cell-up' : 'cell-down') : ''">{{ day.delta[e]?.total ? (day.delta[e].total > 0 ? '+' : '') + day.delta[e].total : '' }}</span></div></td>
                </template>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- График -->
        <div class="sl-cells-chart-section" style="margin-top:20px;">
          <h3 style="font-size:14px;margin-bottom:10px;color:var(--text-muted);">Динамика ячеек</h3>
          <div class="sl-cells-chart" @mouseleave="chartHover = null">
            <svg :viewBox="'0 0 ' + chartW + ' ' + chartH" style="width:100%;height:220px;">
              <!-- Сетка -->
              <line v-for="(y, i) in chartGridY" :key="'g'+i" :x1="chartPad" :x2="chartW - 10" :y1="y" :y2="y" stroke="var(--border-light)" stroke-width="0.5"/>
              <text v-for="(y, i) in chartGridY" :key="'gl'+i" :x="chartPad - 4" :y="y + 3" fill="var(--text-muted)" font-size="9" text-anchor="end">{{ chartGridLabels[i] }}</text>
              <!-- Даты по оси X -->
              <text v-for="(d, i) in chartDates" :key="'xd'+i" :x="chartXPos(i)" :y="chartH - 2" fill="var(--text-muted)" font-size="8" text-anchor="middle">{{ chartDateLabel(d) }}</text>
              <!-- Линии -->
              <template v-for="(line, li) in chartLines" :key="'l'+li">
                <path v-if="!chartHiddenSeries.has(li)" :d="line.path" :stroke="line.color" fill="none" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" :opacity="chartHover !== null && chartHover !== li ? 0.2 : 1"/>
              </template>
              <!-- Точки -->
              <template v-for="(line, li) in chartLines" :key="'p'+li">
                <template v-if="!chartHiddenSeries.has(li)">
                  <circle v-for="(pt, pi) in line.points" :key="pi" :cx="pt.x" :cy="pt.y" :r="chartHover === li ? 4 : 2.5" :fill="line.color" :opacity="chartHover !== null && chartHover !== li ? 0.2 : 1" style="cursor:pointer" @mouseenter="showChartTooltip(li, pi, $event)" @mouseleave="chartTooltip = null"/>
                </template>
              </template>
              <!-- Вертикальная линия при наведении -->
              <line v-if="chartTooltip" :x1="chartTooltip.x" :x2="chartTooltip.x" :y1="20" :y2="chartH - 20" stroke="var(--text-muted)" stroke-width="0.5" stroke-dasharray="3,3" opacity="0.5"/>
            </svg>
            <!-- Тултип -->
            <div v-if="chartTooltip" class="chart-tooltip" :style="{ left: chartTooltip.screenX + 'px', top: chartTooltip.screenY + 'px' }">
              <div class="chart-tooltip-date">{{ chartTooltip.date }}</div>
              <div class="chart-tooltip-val"><span class="chart-tooltip-dot" :style="{ background: chartTooltip.color }"></span>{{ chartTooltip.label }}: <b>{{ chartTooltip.value }}</b></div>
            </div>
            <!-- Легенда (кликабельная) -->
            <div class="sl-cells-legend">
              <span v-for="(line, li) in chartLines" :key="li" class="chart-legend-item" :class="{ 'legend-hidden': chartHiddenSeries.has(li) }" @click="toggleChartSeries(li)" @mouseenter="chartHover = li" @mouseleave="chartHover = null">
                <span class="chart-legend-dot" :style="{ background: chartHiddenSeries.has(li) ? '#ccc' : line.color }"></span>{{ line.label }}
              </span>
            </div>
          </div>
        </div>
      </template>
      <div v-else class="sl-empty">Нет данных. Загрузите файл остатков склада.</div>
    </div>

    <!-- ═══ Вкладка «Сроки годности» ═══ -->
    <!-- Loading -->
    <div v-if="slTab === 'shelf' && loading" class="sl-loader"><BurgerSpinner text="Загрузка..." /></div>

    <template v-if="slTab === 'shelf' && !loading && allData.length">
      <!-- KPI row -->
      <div class="sl-kpi-row">
        <button class="sl-kpi" :class="['sl-kpi-red', { active: activeFilter === 'expired' }]" @click="toggleFilter('expired')">
          <span class="sl-kpi-val">{{ counts.expired }}</span>
          <span class="sl-kpi-label">Просрочено</span>
        </button>
        <button class="sl-kpi" :class="['sl-kpi-orange', { active: activeFilter === '7days' }]" @click="toggleFilter('7days')">
          <span class="sl-kpi-val">{{ counts.days7 }}</span>
          <span class="sl-kpi-label">&le; 7 дн</span>
        </button>
        <button class="sl-kpi" :class="['sl-kpi-yellow', { active: activeFilter === '14days' }]" @click="toggleFilter('14days')">
          <span class="sl-kpi-val">{{ counts.days14 }}</span>
          <span class="sl-kpi-label">8–14 дн</span>
        </button>
        <button class="sl-kpi" :class="['sl-kpi-blue', { active: activeFilter === '30days' }]" @click="toggleFilter('30days')">
          <span class="sl-kpi-val">{{ counts.days30 }}</span>
          <span class="sl-kpi-label">15–30 дн</span>
        </button>
      </div>

      <!-- Filters -->
      <div class="sl-filter-bar">
        <select v-model="filterWarehouse">
          <option value="">Все склады</option>
          <option v-for="w in uniqueWarehouses" :key="w" :value="w">{{ w }}</option>
        </select>
        <input v-model="searchQuery" type="text" placeholder="Поиск по товару..." class="sl-input"/>
        <button v-if="hasAnyFilter" class="sl-clear-btn" @click="clearFilters">&times; Сбросить</button>
        <span class="sl-meta" v-if="uploadedAt">
          {{ fmtDateTime(uploadedAt) }}<template v-if="uploadedBy"> &middot; {{ uploadedBy }}</template>
        </span>
      </div>

      <!-- Table -->
      <div class="sl-table-wrap">
        <table class="sl-table">
          <thead>
            <tr>
              <th class="sl-th sl-th-sort sl-th-center sl-col-name" @click="sortBy('product_name')">Товар <span class="sl-sort-icon">{{ sortIcon('product_name') }}</span></th>
              <th v-if="!filterCustomer" class="sl-th sl-th-sort sl-th-center" @click="sortBy('customer')">Заказчик <span class="sl-sort-icon">{{ sortIcon('customer') }}</span></th>
              <th class="sl-th sl-th-sort sl-th-center" @click="sortBy('warehouse')">Склад <span class="sl-sort-icon">{{ sortIcon('warehouse') }}</span></th>
              <th class="sl-th sl-th-sort sl-th-center" @click="sortBy('expiry_date')">Годен до <span class="sl-sort-icon">{{ sortIcon('expiry_date') }}</span></th>
              <th class="sl-th sl-th-sort sl-th-center sl-col-days" @click="sortBy('days_left')">Дней <span class="sl-sort-icon">{{ sortIcon('days_left') }}</span></th>
              <th class="sl-th sl-th-sort sl-th-center" @click="sortBy('quantity')">Кол-во <span class="sl-sort-icon">{{ sortIcon('quantity') }}</span></th>
              <th class="sl-th sl-th-center sl-col-status">Статус</th>
              <th v-if="showBlockColumn" class="sl-th sl-th-center">Блокировка</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in paginatedData"
              :key="row.id"
              class="sl-row"
              :class="rowCls(row)"
            >
              <td class="sl-td sl-td-name" :title="row.product_name">{{ row.product_name }}</td>
              <td v-if="!filterCustomer" class="sl-td">{{ row.customer }}</td>
              <td class="sl-td">{{ row.warehouse }}</td>
              <td class="sl-td sl-td-center">{{ fmtDate(row.expiry_date) }}</td>
              <td class="sl-td sl-td-center sl-td-days" :class="daysCls(row.days_left)">
                {{ row.days_left != null ? row.days_left : '\u2014' }}
              </td>
              <td class="sl-td sl-td-center">{{ fmtQty(row.quantity) }}</td>
              <td class="sl-td sl-td-center">
                <span class="sl-chip" :class="chipCls(row)">{{ chipText(row) }}</span>
              </td>
              <td v-if="showBlockColumn" class="sl-td sl-td-block" :title="row.block_reason || ''">{{ row.block_reason || '' }}</td>
            </tr>
          </tbody>
        </table>
        <div v-if="!filteredData.length" class="sl-empty-table">Нет данных по выбранным фильтрам</div>
        <div class="sl-table-footer">
          <span class="sl-shown">Показано {{ filteredData.length }} из {{ enrichedData.length }}</span>
          <div v-if="totalPages > 1" class="sl-pager">
            <button :disabled="currentPage <= 1" @click="currentPage--">&lsaquo;</button>
            <span>{{ currentPage }}/{{ totalPages }}</span>
            <button :disabled="currentPage >= totalPages" @click="currentPage++">&rsaquo;</button>
          </div>
        </div>
      </div>
    </template>

    <!-- Empty state -->
    <div v-if="slTab === 'shelf' && !loading && !allData.length" class="sl-empty">
      <BkIcon name="shelfLife" size="lg"/>
      <p>Данные о сроках годности ещё не загружены</p>
      <button
        v-if="userStore.hasAccess('shelf-life', 'edit')"
        class="sl-upload-btn"
        @click="handleUpload"
      >Загрузить stock_mailing</button>
    </div>

    <div v-if="storageChoiceModal.open" class="sl-modal-backdrop" @click.self="cancelStorageChoices">
      <div class="sl-storage-modal" role="dialog" aria-modal="true" aria-labelledby="storage-modal-title">
        <div class="sl-storage-modal-head">
          <div>
            <h2 id="storage-modal-title">Уточните хранение товаров</h2>
            <p>Для этих товаров не удалось автоматически определить, это холод или мороз. Выбор запомнится для следующих загрузок.</p>
          </div>
          <button class="sl-modal-close" type="button" @click="cancelStorageChoices" aria-label="Закрыть">&times;</button>
        </div>

        <div class="sl-storage-modal-body">
          <div v-for="row in storageChoiceModal.rows" :key="row.key" class="sl-storage-choice-row">
            <div class="sl-storage-choice-info">
              <div class="sl-storage-choice-sku">{{ row.sku || 'Без артикула' }}</div>
              <div class="sl-storage-choice-name">{{ row.product_name }}</div>
            </div>
            <div class="sl-storage-choice-actions">
              <button
                type="button"
                class="sl-choice-btn sl-choice-cold"
                :class="{ active: row.choice === 'Холод' }"
                @click="row.choice = 'Холод'"
              >Холод</button>
              <button
                type="button"
                class="sl-choice-btn sl-choice-frozen"
                :class="{ active: row.choice === 'Мороз' }"
                @click="row.choice = 'Мороз'"
              >Мороз</button>
            </div>
          </div>
        </div>

        <div class="sl-storage-modal-foot">
          <span>{{ chosenStorageCount }} из {{ storageChoiceModal.rows.length }} выбрано</span>
          <div class="sl-storage-modal-actions">
            <button class="sl-modal-secondary" type="button" @click="cancelStorageChoices">Отмена</button>
            <button class="sl-modal-primary" type="button" @click="confirmStorageChoices">Продолжить загрузку</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue';
import { db } from '@/lib/apiClient.js';
import { normalizeCustomer, normalizeWarehouse, parseStockMalling, parseCellStats, extractStockReportDateFromName } from '@/lib/shelfLifeImport.js';
import { useUserStore } from '@/stores/userStore.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import BkIcon from '@/components/ui/BkIcon.vue';

const userStore = useUserStore();
const orderStore = useOrderStore();
const toastStore = useToastStore();

// Короткое имя юрлица (в customer-поле таблицы): «ООО "Бургер БК"» → «Бургер БК»
function entityToCustomer(entity) {
  if (!entity) return '';
  if (entity.includes('Пицца Стар')) return 'Пицца Стар';
  if (entity.includes('Воглия Матта')) return 'Воглия Матта';
  if (entity.includes('Бургер БК')) return 'Бургер БК';
  return '';
}

const allData = ref([]);
const loading = ref(true);
const lastLoadedAt = ref(null);
const uploading = ref(false);
const uploadedAt = ref(null);
const uploadedBy = ref('');
const STORAGE_RULES_KEY = 'shelfLifeStorageRules.v1';
const storageChoiceModal = ref({ open: false, rows: [], items: [], rules: {}, resolve: null });

// По умолчанию фильтр настроен на юрлицо из боковой панели, чтобы не показывать
// чужие данные при переключении. «Все» включается кнопкой вручную.
const filterCustomer = ref(entityToCustomer(orderStore.settings.legalEntity));
const filterWarehouse = ref('');
const searchQuery = ref('');
const activeFilter = ref('');
const slTab = ref('shelf');

// ═══ Ячейки склада ═══
const cellStats = ref([]);
const cellsLoading = ref(false);
const cellPeriod = ref(30);
// cellFilterEntity синхронизирован с filterCustomer
const cellFilterEntity = computed(() => filterCustomer.value);

function setEntity(val) {
  filterCustomer.value = filterCustomer.value === val ? '' : val;
}

// Шабаны только для Пицца Стар
const PS_ENTITY = 'Пицца Стар';
function stockTypesFor(entity) {
  return entity === PS_ENTITY ? STOCK_TYPES : STOCK_TYPES.filter(t => t !== 'shabany');
}

const STOCK_TYPE_LABELS = { cold: 'Холод', frozen: 'Мороз', dry: 'Сухой', shabany: 'Шабаны' };
const STOCK_TYPES = ['cold', 'frozen', 'dry', 'shabany'];

async function loadCellStats() {
  cellsLoading.value = true;
  try {
    const { data } = await db.rpc('get_warehouse_cells', { days: cellPeriod.value });
    cellStats.value = data || [];
  } catch { cellStats.value = []; }
  finally { cellsLoading.value = false; }
}

watch(cellPeriod, () => { if (slTab.value === 'cells') loadCellStats(); });

const cellEntities = computed(() => [...new Set(cellStats.value.map(c => c.legal_entity))].sort());
const cellTableEntities = computed(() => cellFilterEntity.value ? [cellFilterEntity.value] : cellEntities.value);

const cellTableRows = computed(() => {
  const dates = [...new Set(cellStats.value.map(c => c.report_date))].sort().reverse();
  const entities = cellTableEntities.value;
  return dates.map((date, di) => {
    const data = {};
    const manual = {};
    for (const e of entities) {
      data[e] = { cold: 0, frozen: 0, dry: 0, shabany: 0, total: 0 };
      manual[e] = {};
      for (const c of cellStats.value) {
        if (c.report_date === date && c.legal_entity === e) {
          data[e][c.stock_type] = c.cell_count;
          data[e].total += c.cell_count;
          if (c.is_manual) manual[e][c.stock_type] = true;
        }
      }
    }
    // Дельта к предыдущему дню
    const delta = {};
    const prevDate = dates[di + 1];
    if (prevDate) {
      for (const e of entities) {
        delta[e] = {};
        for (const t of [...STOCK_TYPES, 'total']) {
          const prev = t === 'total'
            ? cellStats.value.filter(c => c.report_date === prevDate && c.legal_entity === e).reduce((s, c) => s + c.cell_count, 0)
            : (cellStats.value.find(c => c.report_date === prevDate && c.legal_entity === e && c.stock_type === t)?.cell_count || 0);
          const cur = data[e]?.[t] || 0;
          const d = cur - prev;
          if (d !== 0) delta[e][t] = d;
        }
      }
    }
    return { date, data, delta, manual };
  });
});

// ═══ Редактирование ячеек ═══
const canEditCells = computed(() => userStore.hasAccess('shelf-life', 'edit'));
const cellEditing = ref(null);

function startCellEdit(date, entity, type, currentValue) {
  cellEditing.value = { date, entity, type, value: currentValue };
  nextTick(() => document.querySelector('.cell-edit-input')?.focus());
}

async function saveCellEdit() {
  if (!cellEditing.value) return;
  const { date, entity, type, value } = cellEditing.value;
  cellEditing.value = null;
  try {
    const { error } = await db.rpc('upsert_warehouse_cell', {
      report_date: date, legal_entity: entity, stock_type: type, cell_count: value || 0,
    });
    if (error) throw error;
    // Обновляем локально
    const idx = cellStats.value.findIndex(c => c.report_date === date && c.legal_entity === entity && c.stock_type === type);
    if (idx >= 0) {
      cellStats.value[idx].cell_count = value || 0;
      cellStats.value[idx].is_manual = 1;
    } else {
      cellStats.value.push({ report_date: date, legal_entity: entity, stock_type: type, cell_count: value || 0, is_manual: 1 });
    }
  } catch {
    toastStore.error('Ошибка сохранения');
  }
}

function fmtCellDate(d) {
  const days = ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'];
  const dt = new Date(d + 'T00:00:00');
  return days[dt.getDay()] + ' ' + d.slice(8) + '.' + d.slice(5, 7);
}

// Chart — по типам хранения для каждого юрлица
const chartW = 800, chartH = 180, chartPad = 40;

const STOCK_TYPE_COLORS = { cold: '#2196F3', frozen: '#9C27B0', dry: '#F4A261', shabany: '#4CAF50' };

function buildChartData() {
  const entities = cellTableEntities.value;
  const dates = [...new Set(cellStats.value.map(c => c.report_date))].sort();
  if (dates.length < 2) return { series: [], dates, maxVal: 1 };
  const series = [];
  let maxVal = 1;
  for (const e of entities) {
    const eName = e.replace(/ООО\s*"([^"]+)"/, '$1');
    for (const st of stockTypesFor(e)) {
      const points = dates.map(d => {
        const row = cellStats.value.find(c => c.report_date === d && c.legal_entity === e && c.stock_type === st);
        return row ? row.cell_count : 0;
      });
      if (points.every(v => v === 0)) continue;
      const prefix = entities.length > 1 ? `${eName} · ` : '';
      series.push({ label: `${prefix}${STOCK_TYPE_LABELS[st]}`, points, color: STOCK_TYPE_COLORS[st] });
      maxVal = Math.max(maxVal, ...points);
    }
  }
  return { series, dates, maxVal };
}

const chartHiddenSeries = ref(new Set());
const chartHover = ref(null);
const chartTooltip = ref(null);

function toggleChartSeries(idx) {
  const s = new Set(chartHiddenSeries.value);
  s.has(idx) ? s.delete(idx) : s.add(idx);
  chartHiddenSeries.value = s;
}

const chartDates = computed(() => buildChartData().dates);

function chartXPos(i) {
  const dates = chartDates.value;
  const xStep = (chartW - chartPad - 10) / Math.max(dates.length - 1, 1);
  return chartPad + i * xStep;
}

function chartDateLabel(d) {
  if (!d) return '';
  const parts = d.split('-');
  return parts[2] + '.' + parts[1];
}

const chartLines = computed(() => {
  const { series, dates, maxVal } = buildChartData();
  if (!series.length) return [];
  const xStep = (chartW - chartPad - 10) / Math.max(dates.length - 1, 1);
  return series.map(s => {
    const points = s.points.map((v, i) => ({
      x: chartPad + i * xStep,
      y: chartH - 20 - (v / maxVal) * (chartH - 40),
      value: v,
      date: dates[i],
    }));
    const pts = points.map(p => `${p.x},${p.y}`);
    return { path: 'M' + pts.join('L'), color: s.color, label: s.label, points };
  });
});

function showChartTooltip(lineIdx, pointIdx, event) {
  const line = chartLines.value[lineIdx];
  if (!line) return;
  const pt = line.points[pointIdx];
  const svg = event.target.closest('svg');
  const rect = svg.getBoundingClientRect();
  const scaleX = rect.width / chartW;
  const scaleY = rect.height / chartH;
  chartTooltip.value = {
    x: pt.x,
    label: line.label,
    color: line.color,
    value: pt.value,
    date: chartDateLabel(pt.date),
    screenX: pt.x * scaleX + 12,
    screenY: pt.y * scaleY - 10,
  };
}

const chartGridY = computed(() => {
  const count = 4;
  return Array.from({ length: count + 1 }, (_, i) => chartH - 20 - (i / count) * (chartH - 40));
});
const chartGridLabels = computed(() => {
  const { maxVal } = buildChartData();
  return Array.from({ length: 5 }, (_, i) => Math.round(maxVal * (1 - i / 4)));
});

const sortField = ref('days_left');
const sortAsc = ref(true);
const currentPage = ref(1);
const PAGE_SIZE = 100;

function getToday() {
  const d = new Date();
  d.setHours(0, 0, 0, 0);
  return d;
}

// normalizeCustomer, normalizeWarehouse — из @/lib/shelfLifeImport.js

function shortName(name) {
  if (!name) return '';
  if (name.length > 16) return name.slice(0, 15) + '\u2026';
  return name;
}

// ═══ Computed ═══

const enrichedData = computed(() =>
  allData.value.map(row => {
    let daysLeft = null;
    if (row.expiry_date) {
      const exp = new Date(row.expiry_date + 'T00:00:00');
      daysLeft = Math.floor((exp - getToday()) / 86400000);
    }
    return { ...row, customer: normalizeCustomer(row.customer), warehouse: normalizeWarehouse(row.warehouse), days_left: daysLeft };
  })
);

const counts = computed(() => {
  let expired = 0, days7 = 0, days14 = 0, days30 = 0;
  let base = enrichedData.value;
  if (filterCustomer.value) base = base.filter(r => r.customer === filterCustomer.value);
  if (filterWarehouse.value) base = base.filter(r => r.warehouse === filterWarehouse.value);
  for (const r of base) {
    if (r.days_left == null) continue;
    if (r.days_left < 0) expired++;
    else if (r.days_left <= 7) days7++;
    else if (r.days_left <= 14) days14++;
    else if (r.days_left <= 30) days30++;
  }
  return { expired, days7, days14, days30 };
});

const uniqueCustomers = computed(() => {
  const set = new Set(enrichedData.value.map(r => r.customer).filter(Boolean));
  return [...set].sort();
});

// Объединённый список юрлиц из обоих источников (сроки + ячейки)
const entityTabs = computed(() => {
  const set = new Set([
    ...enrichedData.value.map(r => r.customer).filter(Boolean),
    ...cellStats.value.map(c => c.legal_entity).filter(Boolean),
  ]);
  return [...set].sort();
});

const uniqueWarehouses = computed(() => {
  // Склады зависят от выбранного заказчика
  const base = filterCustomer.value
    ? enrichedData.value.filter(r => r.customer === filterCustomer.value)
    : enrichedData.value;
  const set = new Set(base.map(r => r.warehouse).filter(Boolean));
  return [...set].sort();
});

const hasAnyFilter = computed(() =>
  filterCustomer.value || filterWarehouse.value || searchQuery.value || activeFilter.value
);

const showBlockColumn = computed(() => filteredData.value.some(r => r.block_reason));

const filteredData = computed(() => {
  let data = enrichedData.value;
  if (filterCustomer.value) data = data.filter(r => r.customer === filterCustomer.value);
  if (filterWarehouse.value) data = data.filter(r => r.warehouse === filterWarehouse.value);
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase();
    data = data.filter(r => (r.product_name || '').toLowerCase().includes(q));
  }
  if (activeFilter.value === 'expired') data = data.filter(r => r.days_left != null && r.days_left < 0);
  else if (activeFilter.value === '7days') data = data.filter(r => r.days_left != null && r.days_left >= 0 && r.days_left <= 7);
  else if (activeFilter.value === '14days') data = data.filter(r => r.days_left != null && r.days_left >= 8 && r.days_left <= 14);
  else if (activeFilter.value === '30days') data = data.filter(r => r.days_left != null && r.days_left >= 15 && r.days_left <= 30);

  const field = sortField.value;
  const asc = sortAsc.value ? 1 : -1;
  return [...data].sort((a, b) => {
    let va = a[field], vb = b[field];
    if (va == null) va = field === 'days_left' ? 99999 : '';
    if (vb == null) vb = field === 'days_left' ? 99999 : '';
    if (typeof va === 'string') return va.localeCompare(vb, 'ru') * asc;
    return (va - vb) * asc;
  });
});

const totalPages = computed(() => Math.max(1, Math.ceil(filteredData.value.length / PAGE_SIZE)));
const paginatedData = computed(() => {
  const s = (currentPage.value - 1) * PAGE_SIZE;
  return filteredData.value.slice(s, s + PAGE_SIZE);
});

watch([filterCustomer, filterWarehouse, searchQuery, activeFilter], () => { currentPage.value = 1; });

// ═══ Methods ═══

function toggleFilter(f) { activeFilter.value = activeFilter.value === f ? '' : f; }

function clearFilters() {
  filterCustomer.value = '';
  filterWarehouse.value = '';
  searchQuery.value = '';
  activeFilter.value = '';
}

function sortBy(field) {
  if (sortField.value === field) sortAsc.value = !sortAsc.value;
  else { sortField.value = field; sortAsc.value = field === 'days_left'; }
  currentPage.value = 1;
}

function sortIcon(field) {
  if (sortField.value !== field) return '';
  return sortAsc.value ? '\u25B2' : '\u25BC';
}

function rowCls(row) {
  if (row.days_left == null) return '';
  if (row.days_left < 0) return 'sl-row-red';
  if (row.days_left <= 7) return 'sl-row-orange';
  if (row.days_left <= 14) return 'sl-row-yellow';
  if (row.days_left <= 30) return 'sl-row-blue';
  return '';
}

function daysCls(d) {
  if (d == null) return '';
  if (d < 0) return 'sl-c-red';
  if (d <= 7) return 'sl-c-orange';
  if (d <= 14) return 'sl-c-amber';
  if (d <= 30) return 'sl-c-blue';
  return 'sl-c-green';
}

function chipCls(row) {
  if (row.days_left != null && row.days_left < 0) return 'sl-chip-red';
  if (row.days_left != null && row.days_left <= 7) return 'sl-chip-orange';
  if (row.expiry_status === 'Просрочен') return 'sl-chip-red';
  return 'sl-chip-green';
}

function chipText(row) {
  if (row.days_left != null && row.days_left < 0) return 'Просрочен';
  if (row.expiry_status) return row.expiry_status;
  return 'Годен';
}

function fmtDateTime(d) {
  if (!d) return '';
  const dt = new Date(d);
  return dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ' ' +
         dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

function fmtDate(d) {
  if (!d) return '\u2014';
  const ds = typeof d === 'string' && d.length === 10 ? d + 'T00:00:00' : d;
  return new Date(ds).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' });
}

function fmtQty(v) {
  if (v == null) return '\u2014';
  const n = +v;
  return isNaN(n) ? '\u2014' : n.toLocaleString('ru-RU', { maximumFractionDigits: 2 });
}

function formatTimeAgo(date) {
  if (!date) return '';
  const sec = Math.floor((Date.now() - date.getTime()) / 1000);
  if (sec < 60) return 'только что';
  if (sec < 3600) return Math.floor(sec / 60) + ' мин. назад';
  if (sec < 86400) return Math.floor(sec / 3600) + ' ч. назад';
  return date.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}

function localTimeForDate(dateStr) {
  const d = new Date();
  const pad = n => String(n).padStart(2, '0');
  const date = dateStr || `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
  return `${date} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

function promptReportDate(file) {
  const d = new Date();
  const pad = n => String(n).padStart(2, '0');
  const fallback = `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
  const suggested = extractStockReportDateFromName(file.name) || fallback;
  const value = window.prompt('На какую дату загрузить остатки? Формат: ГГГГ-ММ-ДД', suggested);
  if (value == null) return null;
  const clean = value.trim();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(clean)) {
    toastStore.error('Неверная дата', 'Введите дату в формате ГГГГ-ММ-ДД');
    return null;
  }
  return clean;
}

function loadStorageRules() {
  try { return JSON.parse(localStorage.getItem(STORAGE_RULES_KEY) || '{}') || {}; }
  catch { return {}; }
}

function saveStorageRules(rules) {
  localStorage.setItem(STORAGE_RULES_KEY, JSON.stringify(rules || {}));
}

async function loadProductCategories() {
  const map = {};
  const { data } = await db.from('products').select('sku,category').eq('is_active', 1);
  for (const p of data || []) {
    if (p.sku && p.category && !map[p.sku]) map[p.sku] = p.category;
  }
  return map;
}

const chosenStorageCount = computed(() => storageChoiceModal.value.rows.filter(r => r.choice).length);

function requestStorageChoices(items, rules) {
  const unknown = [];
  const seen = new Set();
  for (const item of items) {
    if (!item._needs_storage_choice || !item._storage_key || seen.has(item._storage_key)) continue;
    seen.add(item._storage_key);
    unknown.push({
      key: item._storage_key,
      sku: item._storage_sku,
      product_name: item.product_name,
      choice: '',
    });
  }
  if (!unknown.length) return Promise.resolve(true);

  return new Promise(resolve => {
    storageChoiceModal.value = { open: true, rows: unknown, items, rules, resolve };
  });
}

function applyStorageRulesToItems(items, rules) {
  for (const item of items) {
    const category = rules[item._storage_key] || rules[item._storage_sku];
    if (item._needs_storage_choice && category) {
      item.warehouse = category;
      item._needs_storage_choice = false;
    }
  }
}

function closeStorageChoiceModal(result) {
  const resolve = storageChoiceModal.value.resolve;
  storageChoiceModal.value = { open: false, rows: [], items: [], rules: {}, resolve: null };
  if (resolve) resolve(result);
}

function cancelStorageChoices() {
  closeStorageChoiceModal(false);
}

function confirmStorageChoices() {
  const missing = storageChoiceModal.value.rows.filter(r => !r.choice);
  if (missing.length) {
    toastStore.error('Не всё выбрано', `Осталось выбрать: ${missing.length}`);
    return;
  }
  const rules = storageChoiceModal.value.rules;
  for (const row of storageChoiceModal.value.rows) {
    rules[row.key] = row.choice;
    if (row.sku) rules[row.sku] = row.choice;
  }
  saveStorageRules(rules);
  applyStorageRulesToItems(storageChoiceModal.value.items, rules);
  closeStorageChoiceModal(true);
}

// ═══ Data loading ═══

async function loadData() {
  loading.value = true;
  try {
    const { data, error } = await db.from('stock_malling').select('*');
    if (error) throw error;
    allData.value = data || [];
    if (allData.value.length) {
      let latest = null;
      for (const r of allData.value) {
        if (r.uploaded_at && (!latest || new Date(r.uploaded_at) > new Date(latest.uploaded_at))) latest = r;
      }
      if (latest) { uploadedAt.value = latest.uploaded_at; uploadedBy.value = latest.uploaded_by || ''; }
    }
    lastLoadedAt.value = new Date();
  } catch (e) {
    console.error('[ShelfLife]', e);
    toastStore.error('Не удалось загрузить данные');
  } finally { loading.value = false; }
}

// ═══ XLSX upload & parse ═══

async function handleUpload() {
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = '.xlsx,.xls';
  input.addEventListener('change', async (e) => {
    input.remove();
    const file = e.target.files[0];
    if (!file) return;
    uploading.value = true;
    try {
      const reportDate = promptReportDate(file);
      if (!reportDate) return;
      const storageRules = loadStorageRules();
      const productCategories = await loadProductCategories();
      const items = await parseStockMalling(file, { productCategories, manualStorageCategories: storageRules });
      if (!items.length) { toastStore.error('Не удалось распознать данные в файле'); return; }
      if (!(await requestStorageChoices(items, storageRules))) return;
      const userName = userStore.currentUser?.name || '';
      const now = localTimeForDate(reportDate);
      const payload = items.map(item => ({ ...item, uploaded_at: now, uploaded_by: userName }));
      const [{ data, error }, cellResult] = await Promise.all([
        db.rpc('replace_stock_malling', { items: payload }),
        parseCellStats(file, { reportDate, productCategories, manualStorageCategories: storageRules }),
      ]);
      if (error) throw error;
      // Сохраняем статистику ячеек если распознана
      let cellMsg = '';
      if (cellResult.cells.length) {
        const cellItems = cellResult.cells.map(c => ({ ...c, report_date: cellResult.reportDate }));
        const cellRes = await db.rpc('save_warehouse_cells', { items: cellItems });
        if (cellRes.data?.count > 0) {
          const total = cellResult.cells.reduce((s, c) => s + c.cell_count, 0);
          cellMsg = `, ${total} ячеек за ${cellResult.reportDate}`;
        } else if (cellRes.data?.skipped) {
          cellMsg = ` (ячейки за ${cellResult.reportDate} пропущены — есть более свежие данные)`;
        }
      }
      toastStore.success(`Загружено ${data?.count || items.length} позиций${cellMsg}`);
      await loadData();
    } catch (err) {
      console.error('[ShelfLife]', err);
      toastStore.error('Ошибка: ' + (err.message || 'неизвестная ошибка'));
    } finally { uploading.value = false; }
  });
  input.click();
}

onMounted(loadData);
// При смене юрлица в сайдбаре переключаем фильтр, если он не был «Все».
watch(() => orderStore.settings.legalEntity, (v) => {
  filterCustomer.value = entityToCustomer(v);
});
</script>

<style scoped>
/* ═══ Page ═══ */
.sl-view { }

/* ═══ Header ═══ */
.sl-header {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 8px; margin-bottom: 12px;
}
.sl-header-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

/* ═══ Tabs (entity toggle) — стиль как pf-tabs ═══ */
.sl-tabs {
  display: inline-flex; border: 1.5px solid var(--border); border-radius: 8px;
  overflow: hidden; background: var(--bg);
}
.sl-tab {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 6px 14px; border: none; background: transparent;
  font-size: 12px; font-weight: 600; font-family: inherit;
  color: var(--text-muted); cursor: pointer; transition: all 0.15s;
  white-space: nowrap; line-height: 1;
  border-right: 1.5px solid var(--border);
}
.sl-tab:last-child { border-right: none; }
.sl-tab:hover { color: var(--text); background: rgba(0,0,0,0.02); }
.sl-tab.active {
  background: var(--bk-orange); color: white;
  box-shadow: 0 1px 4px rgba(244,162,97,0.3);
}

/* ═══ Upload button ═══ */
.sl-upload-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 14px; border-radius: 8px; border: 1.5px solid var(--bk-orange);
  background: transparent; color: var(--bk-orange);
  font-size: 12px; font-weight: 600; font-family: inherit;
  cursor: pointer; transition: all 0.15s; white-space: nowrap;
}
.sl-upload-btn:hover { background: var(--bk-orange); color: #fff; }
.sl-upload-btn:disabled { opacity: 0.4; cursor: not-allowed; }

/* ═══ Loader ═══ */
.sl-loader { text-align: center; padding: 60px 20px; color: var(--text-muted); font-size: 14px; }

/* ═══ KPI row ═══ */
.sl-kpi-row { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
.sl-kpi {
  flex: 1; min-width: 90px; display: flex; flex-direction: column; align-items: center;
  padding: 12px 8px; border-radius: 10px; border: 2px solid transparent;
  cursor: pointer; transition: all 0.15s; background: var(--card); font-family: inherit;
}
.sl-kpi:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }
.sl-kpi.active { border-color: var(--text); }
.sl-kpi-val { font-size: 24px; font-weight: 800; line-height: 1; }
.sl-kpi-label { font-size: 10px; font-weight: 600; color: var(--text-muted); margin-top: 4px; text-transform: uppercase; letter-spacing: 0.3px; }

.sl-kpi-red { background: #FFF5F5; } .sl-kpi-red .sl-kpi-val { color: #D32F2F; }
.sl-kpi-orange { background: #FFF8F0; } .sl-kpi-orange .sl-kpi-val { color: #E65100; }
.sl-kpi-yellow { background: #FFFDF0; } .sl-kpi-yellow .sl-kpi-val { color: #F57F17; }
.sl-kpi-blue { background: #F0F6FF; } .sl-kpi-blue .sl-kpi-val { color: #1565C0; }

/* ═══ Filter bar ═══ */
.sl-filter-bar {
  display: flex; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; align-items: center;
}
.sl-filter-bar select {
  padding: 6px 10px; border: 1px solid var(--border); border-radius: 6px;
  font-size: 12px; font-weight: 600; font-family: inherit; color: var(--text);
  background: white; cursor: pointer;
}
.sl-filter-bar select:focus { outline: none; border-color: var(--bk-orange); box-shadow: 0 0 0 2px rgba(232,122,30,0.15); }
.sl-input {
  padding: 6px 10px; border: 1px solid var(--border); border-radius: 6px;
  font-size: 12px; font-family: inherit; color: var(--text);
  background: white; flex: 1; min-width: 140px;
}
.sl-input:focus { outline: none; border-color: var(--bk-orange); box-shadow: 0 0 0 2px rgba(232,122,30,0.15); }
.sl-clear-btn {
  padding: 5px 10px; border: 1px solid var(--border); border-radius: 6px;
  background: white; font-size: 11px; font-weight: 600; font-family: inherit;
  cursor: pointer; color: var(--text-muted); transition: all 0.15s;
}
.sl-clear-btn:hover { border-color: var(--error); color: var(--error); }
.sl-meta { font-size: 11px; color: var(--text-muted); margin-left: auto; white-space: nowrap; }

/* ═══ Table ═══ */
.sl-table-wrap {
  overflow-x: auto; border-radius: 10px; border: 1px solid var(--border); background: white;
}
.sl-table { width: 100%; border-collapse: collapse; font-size: 13px; }

/* Header */
.sl-th {
  padding: 8px 10px; text-align: left; font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.4px; color: var(--text-muted);
  background: var(--bg); border-bottom: 2px solid var(--border);
  white-space: nowrap; position: sticky; top: 0; z-index: 1;
}
.sl-th-sort { cursor: pointer; user-select: none; }
.sl-th-sort:hover { color: var(--text); }
.sl-th-center { text-align: center; }
.sl-sort-icon { font-size: 10px; opacity: 0.5; margin-left: 2px; }
.sl-col-name { min-width: 200px; }
.sl-col-days { width: 60px; }
.sl-col-status { width: 85px; }

/* Rows */
.sl-row {
  transition: background 0.1s;
  border-bottom: 1px solid var(--border-light);
  border-left: 3px solid transparent;
}
.sl-row:hover { background: #FFFBF5; }
.sl-row:last-child { border-bottom: none; }
.sl-row-red { border-left-color: #D32F2F; background: #FFF8F8; }
.sl-row-red:hover { background: #FFF0F0; }
.sl-row-orange { border-left-color: #E65100; background: #FFFAF5; }
.sl-row-orange:hover { background: #FFF5EC; }
.sl-row-yellow { border-left-color: #F9A825; background: #FFFEF5; }
.sl-row-yellow:hover { background: #FFFCEC; }
.sl-row-blue { border-left-color: #1976D2; background: #F8FBFF; }
.sl-row-blue:hover { background: #F0F6FF; }

/* Cells */
.sl-td { padding: 7px 10px; vertical-align: middle; color: var(--text); }
.sl-td-name { font-weight: 600; color: var(--bk-brown); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 320px; text-align: left; }
.sl-td-center { text-align: center; font-size: 12px; white-space: nowrap; }
.sl-td-days { font-weight: 800; font-size: 13px; }
.sl-td-block { font-size: 11px; color: var(--text-muted); max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* Days colors */
.sl-c-red { color: #D32F2F; }
.sl-c-orange { color: #E65100; }
.sl-c-amber { color: #F57F17; }
.sl-c-blue { color: #1565C0; }
.sl-c-green { color: #2E7D32; }

/* ═══ Chips ═══ */
.sl-chip {
  display: inline-flex; align-items: center; gap: 3px;
  padding: 3px 8px; border-radius: 6px;
  font-size: 10px; font-weight: 700; line-height: 1;
}
.sl-chip-red { background: #FFEBEE; color: #C62828; }
.sl-chip-orange { background: #FFF3E0; color: #BF360C; }
.sl-chip-green { background: #E8F5E9; color: #2E7D32; }

/* ═══ Footer ═══ */
.sl-table-footer {
  display: flex; align-items: center; justify-content: space-between;
  padding: 8px 12px; border-top: 1px solid var(--border-light);
}
.sl-shown { font-size: 11px; color: var(--text-muted); }
.sl-pager { display: flex; align-items: center; gap: 6px; }
.sl-pager button {
  width: 26px; height: 26px; border-radius: 6px; border: 1px solid var(--border);
  background: white; cursor: pointer; font-size: 16px; font-weight: 600;
  color: var(--text); display: flex; align-items: center; justify-content: center;
  font-family: inherit; transition: all 0.12s;
}
.sl-pager button:hover:not(:disabled) { background: var(--bg); border-color: var(--bk-orange); }
.sl-pager button:disabled { opacity: 0.3; cursor: not-allowed; }
.sl-pager span { font-size: 11px; color: var(--text-muted); }

/* ═══ Empty ═══ */
.sl-empty-table { text-align: center; padding: 40px 20px; color: var(--text-muted); font-size: 13px; }
.sl-empty {
  display: flex; flex-direction: column; align-items: center;
  gap: 12px; padding: 80px 20px; color: var(--text-muted);
}
.sl-empty p { font-size: 14px; margin: 0; }

/* ═══ Mobile ═══ */
@media (max-width: 768px) {
  .sl-header { flex-direction: column; align-items: flex-start; }
  .sl-header-right { width: 100%; justify-content: space-between; }
  .sl-kpi { min-width: 70px; padding: 10px 6px; }
  .sl-kpi-val { font-size: 20px; }
  .sl-td-block { display: none; }
}
@media (max-width: 480px) {
  .sl-tabs { font-size: 11px; }
  .sl-tab { padding: 5px 10px; }
  .sl-kpi-val { font-size: 18px; }
  .sl-kpi-label { font-size: 9px; }
}

/* Mode tabs */
.sl-mode-tabs { display: flex; gap: 0; border: 1.5px solid var(--border); border-radius: 8px; overflow: hidden; }
.sl-mode-tab { padding: 5px 14px; font-size: 12px; font-weight: 600; background: none; border: none; cursor: pointer; color: var(--text-muted); transition: all .15s; }
.sl-mode-tab.active { background: var(--bk-brown); color: #fff; }
.sl-mode-tab:hover:not(.active) { background: rgba(139,115,85,.08); }

/* Cells table */
.sl-cells-section { flex: 1; overflow: auto; padding: 0 2px; }
.sl-cells-table { width: 100%; border-collapse: collapse; font-size: 13px; background: var(--card); border-radius: 8px; overflow: hidden; border: 1px solid var(--border-light); }
.sl-cells-table th { padding: 8px 10px; text-align: center; font-weight: 700; font-size: 12px; color: var(--text); background: var(--bg); border-bottom: 2px solid var(--border); white-space: nowrap; }
.sl-cells-table th:first-child { text-align: left; position: sticky; left: 0; background: var(--bg); z-index: 1; }
.sl-cells-table td { padding: 7px 4px 7px 6px; text-align: center; border-bottom: 1px solid var(--border-light); white-space: nowrap; font-variant-numeric: tabular-nums; }
.sl-cells-table td:first-child { text-align: left; font-weight: 600; color: var(--text); font-size: 12px; position: sticky; left: 0; background: var(--card); z-index: 1; }
.sl-cells-table tbody tr:hover td { background: rgba(244,162,97,.06); }
.sl-cells-table tbody tr:first-child td { background: rgba(244,162,97,.08); font-weight: 700; }
.cell-layout { display: inline-flex; align-items: baseline; justify-content: center; }
.cell-val { text-align: center; }
.cell-delta { width: 30px; text-align: left; font-size: 10px; font-weight: 600; margin-left: 2px; flex-shrink: 0; }
.cell-up { color: #E57373; }
.cell-down { color: #4CAF50; }
.sl-cells-chart { position: relative; }
.sl-cells-legend { text-align: center; margin-top: 10px; display: flex; flex-wrap: wrap; justify-content: center; gap: 4px 14px; }
.chart-legend-item { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: var(--text-muted); cursor: pointer; padding: 3px 8px; border-radius: 4px; transition: background 0.15s, opacity 0.15s; user-select: none; }
.chart-legend-item:hover { background: rgba(0,0,0,.04); }
.chart-legend-item.legend-hidden { opacity: 0.4; text-decoration: line-through; }
.chart-legend-dot { width: 10px; height: 4px; border-radius: 2px; flex-shrink: 0; }
.chart-tooltip { position: absolute; background: #fff; border: 1px solid #ddd; border-radius: 6px; box-shadow: 0 2px 8px rgba(0,0,0,.12); padding: 6px 10px; font-size: 12px; pointer-events: none; z-index: 10; white-space: nowrap; }
.chart-tooltip-date { font-size: 10px; color: #999; margin-bottom: 2px; }
.chart-tooltip-val { display: flex; align-items: center; gap: 5px; }
.chart-tooltip-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.sl-cells-chart-section { background: var(--card); border-radius: 8px; padding: 16px; border: 1px solid var(--border-light); }
.cell-border-left { border-left: 2px solid var(--border) !important; }
.cell-entity-header { text-align: center !important; border-left: 2px solid var(--border) !important; font-size: 13px !important; color: var(--bk-brown) !important; background: rgba(139,115,85,.06) !important; }
.cell-total { font-weight: 700 !important; background: rgba(139,115,85,.03); }

/* Ручные значения */
.cell-manual { position: relative; }
.cell-manual::after {
  content: '';
  position: absolute; top: 3px; right: 3px;
  width: 5px; height: 5px; border-radius: 50%;
  background: #F4A261;
}

/* Инпут редактирования */
.cell-edit-input {
  width: 56px; padding: 2px 4px; font-size: 12px; font-weight: 600;
  border: 1.5px solid #F4A261; border-radius: 4px;
  background: rgba(244,162,97,.06); color: var(--text);
  text-align: center; outline: none;
}
.cell-edit-input:focus { border-color: #E76F51; box-shadow: 0 0 0 2px rgba(231,111,81,.12); }

/* Ячейки с данными — курсор при наведении */
.sl-cells-table td:not(.cell-total):not(:first-child) { cursor: default; position: relative; }

.data-freshness { font-size: 11px; color: var(--text-muted, #999); display: inline-flex; align-items: center; gap: 4px; }
.data-freshness::before { content: '●'; font-size: 6px; color: #4CAF50; }

/* Выбор хранения при загрузке */
.sl-modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  background: rgba(24, 20, 16, .42);
}
.sl-storage-modal {
  width: min(920px, 100%);
  max-height: min(760px, calc(100dvh - 48px));
  display: flex;
  flex-direction: column;
  background: var(--card);
  border: 1px solid var(--border-light);
  border-radius: 12px;
  box-shadow: 0 24px 70px rgba(0,0,0,.24);
  overflow: hidden;
}
.sl-storage-modal-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 20px 22px 16px;
  border-bottom: 1px solid var(--border-light);
}
.sl-storage-modal-head h2 {
  margin: 0;
  font-size: 18px;
  line-height: 1.25;
  color: var(--text);
}
.sl-storage-modal-head p {
  margin: 6px 0 0;
  max-width: 680px;
  font-size: 13px;
  line-height: 1.45;
  color: var(--text-muted);
}
.sl-modal-close {
  width: 36px;
  height: 36px;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg);
  color: var(--text);
  font-size: 22px;
  line-height: 1;
  cursor: pointer;
}
.sl-storage-modal-body {
  overflow: auto;
  padding: 10px 14px;
}
.sl-storage-choice-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 14px;
  align-items: center;
  padding: 12px 8px;
  border-bottom: 1px solid var(--border-light);
}
.sl-storage-choice-row:last-child { border-bottom: none; }
.sl-storage-choice-info { min-width: 0; }
.sl-storage-choice-sku {
  margin-bottom: 3px;
  font-size: 12px;
  font-weight: 800;
  color: var(--bk-brown);
  font-variant-numeric: tabular-nums;
}
.sl-storage-choice-name {
  font-size: 13px;
  line-height: 1.35;
  color: var(--text);
}
.sl-storage-choice-actions {
  display: inline-flex;
  gap: 8px;
}
.sl-choice-btn {
  min-width: 84px;
  height: 38px;
  padding: 0 14px;
  border: 1.5px solid var(--border);
  border-radius: 8px;
  background: var(--card);
  color: var(--text);
  font-weight: 700;
  cursor: pointer;
}
.sl-choice-btn:hover { border-color: var(--bk-orange); }
.sl-choice-cold.active {
  border-color: #2563eb;
  background: #eff6ff;
  color: #1d4ed8;
}
.sl-choice-frozen.active {
  border-color: #7c3aed;
  background: #f3e8ff;
  color: #6d28d9;
}
.sl-storage-modal-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 22px;
  border-top: 1px solid var(--border-light);
  background: var(--bg);
  font-size: 12px;
  color: var(--text-muted);
}
.sl-storage-modal-actions {
  display: flex;
  gap: 10px;
}
.sl-modal-secondary,
.sl-modal-primary {
  min-height: 38px;
  padding: 0 16px;
  border-radius: 8px;
  font-weight: 700;
  cursor: pointer;
}
.sl-modal-secondary {
  border: 1px solid var(--border);
  background: var(--card);
  color: var(--text);
}
.sl-modal-primary {
  border: 1px solid var(--bk-brown);
  background: var(--bk-brown);
  color: #fff;
}
@media (max-width: 640px) {
  .sl-modal-backdrop { padding: 10px; align-items: stretch; }
  .sl-storage-modal { max-height: calc(100dvh - 20px); }
  .sl-storage-choice-row { grid-template-columns: 1fr; }
  .sl-storage-choice-actions { display: grid; grid-template-columns: 1fr 1fr; }
  .sl-storage-modal-foot { align-items: stretch; flex-direction: column; }
  .sl-storage-modal-actions { display: grid; grid-template-columns: 1fr 1fr; }
}
</style>
