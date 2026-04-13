<template>
  <div class="sfv">
    <!-- Loading -->
    <div v-if="loading" class="sfv-center"><BurgerSpinner /></div>

    <!-- Empty -->
    <div v-else-if="!forecastRows.length" class="sfv-center">
      <p class="sfv-empty-title">Нет данных для прогноза</p>
      <p class="sfv-empty-sub">Загрузите данные анализа запасов и реализации ресторанов</p>
    </div>

    <template v-else>
      <!-- Toolbar -->
      <div class="sfv-toolbar">
        <div class="sfv-period-toggle">
          <button :class="{ active: period === 7 }" @click="period = 7">7 дней</button>
          <button :class="{ active: period === 14 }" @click="period = 14">14 дней</button>
          <button :class="{ active: period === 30 }" @click="period = 30">30 дней</button>
        </div>
        <input v-model="search" class="sfv-input" placeholder="Поиск группы…" />
        <select v-model="filterSupplier" class="sfv-input sfv-select">
          <option value="">Все поставщики</option>
          <option v-for="s in supplierList" :key="s" :value="s">{{ s }}</option>
        </select>
        <select v-model="filterCategory" class="sfv-input sfv-select">
          <option value="">Все категории</option>
          <option v-for="c in categoryList" :key="c" :value="c">{{ c }}</option>
        </select>
        <select v-model="sortKey" class="sfv-input sfv-select">
          <option value="days-asc">По срочности</option>
          <option value="stock-desc">По остатку</option>
          <option value="avg-desc">По продажам</option>
          <option value="trend">По тренду</option>
          <option value="name">По названию</option>
        </select>
      </div>

      <!-- KPI strip -->
      <div class="sfv-kpi">
        <span class="sfv-kpi-item">Групп: <b>{{ filteredRows.length }}</b></span>
        <span v-if="kpi.red > 0" class="sfv-kpi-item sfv-kpi-red" @click="filterAlert = filterAlert === 'red' ? '' : 'red'">
          Критичных (&lt;3 дн): <b>{{ kpi.red }}</b>
        </span>
        <span v-if="kpi.yellow > 0" class="sfv-kpi-item sfv-kpi-yellow" @click="filterAlert = filterAlert === 'yellow' ? '' : 'yellow'">
          Внимание (&lt;7 дн): <b>{{ kpi.yellow }}</b>
        </span>
        <span v-if="kpi.ok > 0" class="sfv-kpi-item sfv-kpi-ok" @click="filterAlert = filterAlert === 'ok' ? '' : 'ok'">
          В норме: <b>{{ kpi.ok }}</b>
        </span>
        <span v-if="filterAlert" class="sfv-kpi-clear" @click="filterAlert = ''">✕ сбросить</span>
        <span class="sfv-kpi-period">Данные: {{ periodLabel }}</span>
      </div>

      <!-- Table -->
      <div class="sfv-table-wrap">
        <table class="sfv-tbl">
          <thead>
            <tr>
              <th class="sfv-th-expand"></th>
              <th class="sfv-th-name">Группа аналогов</th>
              <th class="sfv-th-num">Остаток</th>
              <th class="sfv-th-num">Ср/день</th>
              <th class="sfv-th-days">Дней</th>
              <th class="sfv-th-date">Обнуление</th>
              <th class="sfv-th-expiry">Годность</th>
              <th class="sfv-th-trend">Тренд</th>
              <th class="sfv-th-spark">График</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="r in filteredRows" :key="r.group">
              <!-- Main row -->
              <tr :class="[rowClass(r), { 'sfv-row-open': expanded === r.group }]" @click="toggleExpand(r.group)">
                <td class="sfv-td-expand">
                  <span class="sfv-chevron" :class="{ open: expanded === r.group }">&#9656;</span>
                </td>
                <td class="sfv-td-name">
                  <span class="sfv-group-name">{{ r.group }}</span>
                  <span class="sfv-group-meta">{{ r.supplier || '' }}{{ r.supplier && r.category ? ' · ' : '' }}{{ r.category || '' }}</span>
                </td>
                <td class="sfv-td-num">{{ fmtNum(r.stock) }}</td>
                <td class="sfv-td-num">
                  {{ fmtNum(r.avg) }}
                  <span v-if="r.dataSource === 'analysis'" class="sfv-src-hint" title="По расходу со склада (нет данных реализации)">склад</span>
                </td>
                <td class="sfv-td-days">
                  <span class="sfv-badge" :class="badgeClass(r.daysLeft)">{{ fmtDays(r.daysLeft) }}</span>
                </td>
                <td class="sfv-td-date">{{ r.zeroDate || '—' }}</td>
                <td class="sfv-td-expiry">
                  <span v-if="r.expiryRisk === 'expired'" class="sfv-expiry-tag sfv-expiry-bad" title="Есть просроченный товар">Просрочка</span>
                  <span v-else-if="r.expiryRisk === 'week'" class="sfv-expiry-tag sfv-expiry-warn" title="Часть товара истекает в течение 7 дней">До 7 дн</span>
                  <span v-else-if="r.expiryRisk === 'soon'" class="sfv-expiry-tag sfv-expiry-info" title="Часть товара истекает в течение 14 дней">До 14 дн</span>
                  <span v-else-if="r.expiry" class="sfv-expiry-ok">ОК</span>
                  <span v-else class="sfv-muted">—</span>
                </td>
                <td class="sfv-td-trend">
                  <span class="sfv-trend" :class="trendClass(r.trendPct)">
                    {{ trendIcon(r.trendPct) }} {{ trendLabel(r.trendPct) }}
                  </span>
                </td>
                <td class="sfv-td-spark">
                  <svg v-if="r.depletionData.length > 1" class="sfv-spark" viewBox="0 0 60 18">
                    <path :d="sparkPath(r.depletionData)" fill="none" :stroke="sparkColor(r)" stroke-width="1.5" />
                  </svg>
                </td>
              </tr>

              <!-- Expanded detail -->
              <tr v-if="expanded === r.group" class="sfv-detail-row">
                <td colspan="9">
                  <div class="sfv-detail">
                    <!-- Chart area -->
                    <div class="sfv-detail-chart">
                      <div class="sfv-detail-chart-title">Продажи по дням и прогноз обнуления</div>
                      <svg class="sfv-big-chart" :viewBox="`0 0 ${chartW} ${chartH}`" preserveAspectRatio="xMinYMin meet">
                        <!-- Y axis grid + labels -->
                        <template v-for="(tick, ti) in yTicks(r.chartMax)" :key="'yt'+ti">
                          <line :x1="chartPadL" :y1="yPos(tick, r.chartMax)" :x2="chartW" :y2="yPos(tick, r.chartMax)" stroke="var(--border-light)" stroke-width="0.5" />
                          <text :x="chartPadL - 4" :y="yPos(tick, r.chartMax) + 3" text-anchor="end" class="sfv-chart-label">{{ shortNum(tick) }}</text>
                        </template>
                        <!-- Sales bars -->
                        <g v-for="(bar, i) in r.chartBars" :key="'b'+i" class="sfv-bar-group">
                          <!-- Invisible wider hit area for easier hover -->
                          <rect :x="bar.x - 1" y="0" :width="bar.w + 2" :height="chartInnerH" fill="transparent" />
                          <!-- Visible bar -->
                          <rect :x="bar.x" :y="bar.y" :width="bar.w" :height="bar.h" :fill="bar.color" rx="1.5" />
                          <!-- Hover label -->
                          <text :x="bar.x + bar.w / 2" :y="bar.y - 3" text-anchor="middle" class="sfv-bar-label">{{ shortNum(bar.value) }}</text>
                          <title>{{ formatBarDate(bar.date) }}: {{ fmtNum(bar.value) }}</title>
                        </g>
                        <!-- X axis date labels (every ~5th) -->
                        <template v-for="(d, i) in r.chartDates" :key="'xd'+i">
                          <text v-if="i % Math.max(1, Math.floor(r.chartDates.length / 6)) === 0 || i === r.chartDates.length - 1"
                            :x="chartPadL + (i + 0.5) * (chartInnerW / r.chartDates.length)"
                            :y="chartH - 3"
                            text-anchor="middle" class="sfv-chart-label">{{ shortDate(d) }}</text>
                        </template>
                        <!-- Avg line -->
                        <line v-if="r.avg > 0"
                          :x1="chartPadL" :y1="yPos(r.avg, r.chartMax)" :x2="chartW" :y2="yPos(r.avg, r.chartMax)"
                          stroke="#1976D2" stroke-width="1" stroke-dasharray="3 3" opacity="0.5" />
                        <text v-if="r.avg > 0"
                          :x="chartW - 2" :y="yPos(r.avg, r.chartMax) - 3"
                          text-anchor="end" class="sfv-chart-label" fill="#1976D2">ср {{ shortNum(r.avg) }}</text>
                        <!-- Depletion line -->
                        <path :d="depletionPath(r)" fill="none" stroke="#EF5350" stroke-width="2" stroke-dasharray="4 2" />
                        <!-- Stock label on depletion start -->
                        <text v-if="r.avg > 0 && r.stock > 0"
                          :x="depletionStartX(r) + 2" :y="yPos(r.stock, Math.max(r.chartMax, r.stock)) - 4"
                          class="sfv-chart-label" fill="#EF5350">{{ shortNum(r.stock) }}</text>
                      </svg>
                      <div class="sfv-chart-legend">
                        <span class="sfv-legend-item"><span class="sfv-legend-dot" style="background:#90CAF9"></span>Продажи/день</span>
                        <span class="sfv-legend-item"><span class="sfv-legend-dot" style="background:#EF5350"></span>Прогноз остатка</span>
                      </div>
                    </div>

                    <!-- Stats cards -->
                    <div class="sfv-detail-stats">
                      <div class="sfv-stat-card">
                        <div class="sfv-stat-label">Остаток на складе</div>
                        <div class="sfv-stat-value">{{ fmtNum(r.stock) }}</div>
                      </div>
                      <div class="sfv-stat-card">
                        <div class="sfv-stat-label">Средние продажи/день</div>
                        <div class="sfv-stat-value">{{ fmtNum(r.avg) }}</div>
                      </div>
                      <div class="sfv-stat-card">
                        <div class="sfv-stat-label">Макс. за день</div>
                        <div class="sfv-stat-value">{{ fmtNum(r.maxDaily) }}</div>
                      </div>
                      <div class="sfv-stat-card">
                        <div class="sfv-stat-label">Мин. за день</div>
                        <div class="sfv-stat-value">{{ fmtNum(r.minDaily) }}</div>
                      </div>
                      <div class="sfv-stat-card">
                        <div class="sfv-stat-label">Тренд продаж</div>
                        <div class="sfv-stat-value" :class="trendClass(r.trendPct)">
                          {{ trendIcon(r.trendPct) }} {{ r.trendPct > 0 ? '+' : '' }}{{ Math.round(r.trendPct) }}%
                        </div>
                        <div class="sfv-stat-hint">{{ trendHint(r) }}</div>
                      </div>
                      <div class="sfv-stat-card">
                        <div class="sfv-stat-label">Хватит на</div>
                        <div class="sfv-stat-value" :class="badgeClass(r.daysLeft)">{{ fmtDays(r.daysLeft) }} дн</div>
                        <div v-if="r.zeroDate" class="sfv-stat-hint">до {{ r.zeroDate }}</div>
                      </div>

                      <!-- Day of week pattern -->
                      <div v-if="r.dowAvg.some(v => v > 0)" class="sfv-stat-card sfv-stat-wide">
                        <div class="sfv-stat-label">Продажи по дням недели</div>
                        <div class="sfv-dow">
                          <div v-for="(val, i) in r.dowAvg" :key="'dow'+i" class="sfv-dow-col">
                            <div class="sfv-dow-bar-wrap">
                              <div class="sfv-dow-bar"
                                :style="{ height: (val / r.dowMax * 100) + '%' }"
                                :class="{ 'sfv-dow-peak': i === r.peakDay, 'sfv-dow-low': i === r.lowDay && val > 0 }"
                              ></div>
                            </div>
                            <div class="sfv-dow-val">{{ val > 0 ? shortNum(val) : '' }}</div>
                            <div class="sfv-dow-label">{{ DOW_NAMES[i] }}</div>
                          </div>
                        </div>
                        <div class="sfv-dow-hint">
                          Пик: <b>{{ DOW_FULL[r.peakDay] }}</b> ({{ fmtNum(r.dowAvg[r.peakDay]) }}/день)
                          <span v-if="r.dowAvg[r.peakDay] > 0 && r.dowAvg[r.lowDay] > 0">
                             · Спад: <b>{{ DOW_FULL[r.lowDay] }}</b> ({{ fmtNum(r.dowAvg[r.lowDay]) }}/день)
                          </span>
                        </div>
                      </div>

                      <!-- Expiry breakdown -->
                      <div v-if="r.expiry" class="sfv-stat-card sfv-stat-wide">
                        <div class="sfv-stat-label">Сроки годности на складе</div>
                        <div class="sfv-expiry-breakdown">
                          <div class="sfv-expiry-row" v-if="r.expiry.expired > 0">
                            <span class="sfv-expiry-dot" style="background:#C62828"></span>
                            <span>Просрочено</span>
                            <span class="sfv-expiry-qty sfv-expiry-bad">{{ fmtNum(r.expiry.expired) }}</span>
                          </div>
                          <div class="sfv-expiry-row" v-if="r.expiry.expiring7 > 0">
                            <span class="sfv-expiry-dot" style="background:#E65100"></span>
                            <span>Истекает за 7 дней</span>
                            <span class="sfv-expiry-qty sfv-expiry-warn">{{ fmtNum(r.expiry.expiring7) }}</span>
                          </div>
                          <div class="sfv-expiry-row" v-if="r.expiry.expiring14 > 0">
                            <span class="sfv-expiry-dot" style="background:#FFA726"></span>
                            <span>Истекает за 14 дней</span>
                            <span class="sfv-expiry-qty">{{ fmtNum(r.expiry.expiring14) }}</span>
                          </div>
                          <div class="sfv-expiry-row" v-if="r.expiry.expiring30 > 0">
                            <span class="sfv-expiry-dot" style="background:#66BB6A"></span>
                            <span>Истекает за 30 дней</span>
                            <span class="sfv-expiry-qty">{{ fmtNum(r.expiry.expiring30) }}</span>
                          </div>
                          <div class="sfv-expiry-row">
                            <span class="sfv-expiry-dot" style="background:#2E7D32"></span>
                            <span>Годный остаток (30+ дн)</span>
                            <span class="sfv-expiry-qty" style="font-weight:700">{{ fmtNum(r.expiry.total - r.expiry.expired - r.expiry.expiring7 - r.expiry.expiring14 - r.expiry.expiring30) }}</span>
                          </div>
                        </div>
                        <div v-if="r.effectiveStock < r.stock" class="sfv-expiry-note">
                          Реальный годный остаток: <b>{{ fmtNum(r.effectiveStock) }}</b> (хватит на <b>{{ fmtDays(r.effectiveDaysLeft) }}</b> дн)
                        </div>
                      </div>

                      <!-- Note about restaurant buffer -->
                      <div class="sfv-stat-card sfv-stat-wide sfv-note-card">
                        <div class="sfv-stat-label">Как читать прогноз</div>
                        <div class="sfv-note-text">
                          Остаток — только склад. У ресторанов тоже есть запас, который мы не видим. Реальное время жизни может быть дольше прогноза. Продажи — это продажи ресторанов конечным клиентам, а не отгрузки со склада.
                        </div>
                      </div>

                      <!-- SKU breakdown -->
                      <div v-if="r.skuBreakdown.length > 1" class="sfv-stat-card sfv-stat-wide">
                        <div class="sfv-stat-label">Артикулы в группе</div>
                        <div class="sfv-sku-list">
                          <div v-for="sku in r.skuBreakdown" :key="sku.sku" class="sfv-sku-row">
                            <span class="sfv-sku-name">{{ sku.name }}</span>
                            <span class="sfv-sku-stock">{{ fmtNum(sku.stock) }}</span>
                          </div>
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
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { db } from '@/lib/apiClient.js'
import { applyEntityGroupFilter, toLocalDateStr } from '@/lib/utils.js'
import { useOrderStore } from '@/stores/orderStore.js'
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue'

const orderStore = useOrderStore()

const loading = ref(false)
const productsData = ref([])
const analysisData = ref([])
const salesData = ref([])
const expiryData = ref([])
const lastSaleDate = ref(null)

const search = ref('')
const filterSupplier = ref('')
const filterCategory = ref('')
const filterAlert = ref('')
const sortKey = ref('days-asc')
const period = ref(7)
const expanded = ref(null)

const chartW = 440
const chartH = 120
const chartPadL = 40 // left padding for Y axis
const chartPadB = 18 // bottom padding for X axis
const chartInnerH = chartH - chartPadB
const chartInnerW = chartW - chartPadL

const DOW_NAMES = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс']
const DOW_FULL = ['понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота', 'воскресенье']

function toggleExpand(group) {
  expanded.value = expanded.value === group ? null : group
}

// ═══ Load data ═══

async function loadData() {
  loading.value = true
  try {
    const entity = orderStore.settings.legalEntity

    let pq = db.from('products').select('sku, name, analog_group, supplier, category')
    pq = applyEntityGroupFilter(pq, entity)
    const { data: products } = await pq
    productsData.value = products || []

    const skus = productsData.value.map(p => p.sku).filter(Boolean)
    if (skus.length) {
      const { data: analysis } = await db
        .from('analysis_data')
        .select('sku, stock, consumption, period_days')
        .eq('legal_entity', entity)
        .in('sku', skus)
      analysisData.value = analysis || []
    } else {
      analysisData.value = []
    }

    const { data: metaRows } = await db
      .from('restaurant_sales')
      .select('sale_date')
      .eq('legal_entity', entity)
      .order('sale_date', { ascending: false })
      .limit(1)

    if (!metaRows?.length) {
      salesData.value = []
      lastSaleDate.value = null
      return
    }

    const ld = metaRows[0].sale_date
    lastSaleDate.value = ld
    const cutoff = new Date(ld + 'T12:00:00')
    cutoff.setDate(cutoff.getDate() - 29)
    const cutoffStr = toLocalDateStr(cutoff)

    const { data: sales } = await db
      .from('restaurant_sales')
      .select('analog_group, quantity, sale_date')
      .eq('legal_entity', entity)
      .gte('sale_date', cutoffStr)
      .limit(50000)
    salesData.value = sales || []

    // 4. Shelf life data (stock_malling) — сроки годности
    const { data: expiry } = await db
      .from('stock_malling')
      .select('product_name, quantity, expiry_date, customer')
      .limit(5000)
    expiryData.value = expiry || []
  } catch (e) {
    console.error('[forecast] Load error:', e)
  } finally {
    loading.value = false
  }
}

onMounted(loadData)
watch(() => orderStore.settings.legalEntity, loadData)

// ═══ Build forecast rows ═══

const forecastRows = computed(() => {
  if (!productsData.value.length) return []

  const skuProduct = new Map()
  for (const p of productsData.value) {
    if (p.sku) skuProduct.set(p.sku, p)
  }

  // Aggregate stock by analog group + collect SKU breakdown
  const groupStock = {}
  const groupSkus = {}     // group → [{ sku, name, stock }]
  const groupSupplier = {}
  const groupCategory = {}
  const groupConsumption = {} // group → daily consumption from analysis_data (fallback)

  for (const a of analysisData.value) {
    const p = skuProduct.get(a.sku)
    if (!p || !p.analog_group) continue
    const g = p.analog_group
    const st = parseFloat(a.stock) || 0
    const cons = parseFloat(a.consumption) || 0
    const pDays = parseInt(a.period_days) || 30
    groupStock[g] = (groupStock[g] || 0) + st
    if (cons > 0) groupConsumption[g] = (groupConsumption[g] || 0) + cons / pDays

    if (!groupSkus[g]) groupSkus[g] = []
    groupSkus[g].push({ sku: a.sku, name: p.name || a.sku, stock: st })

    if (p.supplier) {
      if (!groupSupplier[g]) groupSupplier[g] = {}
      groupSupplier[g][p.supplier] = (groupSupplier[g][p.supplier] || 0) + 1
    }
    if (p.category) {
      if (!groupCategory[g]) groupCategory[g] = {}
      groupCategory[g][p.category] = (groupCategory[g][p.category] || 0) + 1
    }
  }

  // Expiry by analog group: extract SKU from product_name, map to group
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const groupExpiry = {} // group → { total, expiring30, expiring14, expiring7, expired, lots: [{qty, daysLeft}] }

  for (const e of expiryData.value) {
    if (!e.product_name || !e.expiry_date) continue
    const sku = e.product_name.split(/\s+/)[0] // артикул в начале названия
    const p = skuProduct.get(sku)
    if (!p || !p.analog_group) continue
    const g = p.analog_group
    if (!groupExpiry[g]) groupExpiry[g] = { total: 0, expired: 0, expiring7: 0, expiring14: 0, expiring30: 0, lots: [] }
    const qty = parseFloat(e.quantity) || 0
    const exp = new Date(e.expiry_date + 'T00:00:00')
    const daysToExpiry = Math.floor((exp - today) / 86400000)
    groupExpiry[g].total += qty
    groupExpiry[g].lots.push({ qty, daysToExpiry })
    if (daysToExpiry < 0) groupExpiry[g].expired += qty
    else if (daysToExpiry <= 7) groupExpiry[g].expiring7 += qty
    else if (daysToExpiry <= 14) groupExpiry[g].expiring14 += qty
    else if (daysToExpiry <= 30) groupExpiry[g].expiring30 += qty
  }

  // Sales by group, by date
  const groupSales = {}
  for (const s of salesData.value) {
    if (!s.analog_group) continue
    if (!groupSales[s.analog_group]) groupSales[s.analog_group] = {}
    const d = s.sale_date
    groupSales[s.analog_group][d] = (groupSales[s.analog_group][d] || 0) + (parseFloat(s.quantity) || 0)
  }

  const ld = lastSaleDate.value
  if (!ld) return []
  const ldDate = new Date(ld + 'T12:00:00')
  const p = period.value

  // Cutoff for selected period
  const cutoffDate = new Date(ldDate)
  cutoffDate.setDate(cutoffDate.getDate() - (p - 1))
  const cutoffStr = toLocalDateStr(cutoffDate)

  const allGroups = new Set([...Object.keys(groupStock), ...Object.keys(groupSales)])
  const rows = []

  for (const g of allGroups) {
    const stock = Math.round((groupStock[g] || 0) * 10) / 10
    const dailySales = groupSales[g] || {}
    const allDates = Object.keys(dailySales).sort()
    if (allDates.length === 0 && stock <= 0) continue

    // Period-filtered dates & values
    const periodDates = allDates.filter(d => d >= cutoffStr)
    const periodValues = periodDates.map(d => dailySales[d])

    // Average: предпочитаем реализацию, fallback на расход из analysis_data
    const totalQty = periodValues.reduce((a, b) => a + b, 0)
    const daysWithData = periodValues.length
    const hasSales = daysWithData > 0 && totalQty > 0
    const avg = hasSales ? totalQty / daysWithData : (groupConsumption[g] || 0)
    const dataSource = hasSales ? 'sales' : (groupConsumption[g] ? 'analysis' : 'none')

    // Min / Max
    const maxDaily = periodValues.length ? Math.max(...periodValues) : 0
    const minDaily = periodValues.length ? Math.min(...periodValues) : 0

    // Days left
    const daysLeft = avg > 0 ? stock / avg : (stock > 0 ? Infinity : 0)

    // Zero date
    let zeroDate = ''
    if (avg > 0 && stock > 0 && daysLeft < 365) {
      const zd = new Date(today)
      zd.setDate(zd.getDate() + Math.ceil(daysLeft))
      zeroDate = `${String(zd.getDate()).padStart(2,'0')}.${String(zd.getMonth()+1).padStart(2,'0')}.${zd.getFullYear()}`
    }

    // Trend: последние 7 дней vs предыдущие 7 дней (от lastSaleDate)
    let trendPct = 0
    if (allDates.length >= 7) {
      const last7 = allDates.slice(-7).reduce((a, d) => a + (dailySales[d] || 0), 0) / 7
      const prev7start = Math.max(0, allDates.length - 14)
      const prev7end = Math.max(0, allDates.length - 7)
      const prev7dates = allDates.slice(prev7start, prev7end)
      const prev7 = prev7dates.length > 0 ? prev7dates.reduce((a, d) => a + (dailySales[d] || 0), 0) / prev7dates.length : 0
      if (prev7 > 0) trendPct = ((last7 - prev7) / prev7) * 100
    }

    // Depletion sparkline (mini, for table)
    const depletionData = []
    if (avg > 0) {
      let rem = stock
      const steps = Math.min(Math.ceil(daysLeft) + 2, 20)
      for (let i = 0; i <= steps; i++) {
        depletionData.push(Math.max(0, Math.round(rem * 10) / 10))
        rem -= avg
      }
    }

    // Chart bars: last N days sales as bar chart data
    const chartDates = allDates.slice(-30)
    const chartValues = chartDates.map(d => dailySales[d])
    const chartMax = Math.max(...chartValues, 1)
    const colW = chartInnerW / Math.max(chartDates.length, 1)
    const barW = Math.max(2, colW - 2)
    const chartBars = chartDates.map((d, i) => {
      const v = dailySales[d]
      const h = (v / chartMax) * (chartInnerH - 10)
      const inPeriod = d >= cutoffStr
      return {
        x: chartPadL + i * colW + (colW - barW) / 2,
        y: chartInnerH - h - 2,
        w: barW,
        h: Math.max(1, h),
        color: inPeriod ? '#90CAF9' : '#E0E0E0',
        date: d,
        value: v,
      }
    })

    // Day-of-week breakdown
    const dowTotals = [0, 0, 0, 0, 0, 0, 0] // пн–вс
    const dowCounts = [0, 0, 0, 0, 0, 0, 0]
    for (const d of periodDates) {
      const dt = new Date(d + 'T12:00:00')
      const dow = (dt.getDay() + 6) % 7 // 0=пн, 6=вс
      dowTotals[dow] += dailySales[d]
      dowCounts[dow]++
    }
    const dowAvg = dowTotals.map((t, i) => dowCounts[i] > 0 ? t / dowCounts[i] : 0)
    const dowMax = Math.max(...dowAvg, 1)
    const peakDay = dowAvg.indexOf(Math.max(...dowAvg))
    const nonZero = dowAvg.filter(v => v > 0)
    const lowDay = nonZero.length ? dowAvg.indexOf(Math.min(...nonZero)) : 0

    // SKU breakdown sorted by stock desc
    const skuBreakdown = (groupSkus[g] || []).sort((a, b) => b.stock - a.stock)

    const supplier = topKey(groupSupplier[g])
    const category = topKey(groupCategory[g])

    // Expiry info
    const exp = groupExpiry[g] || null
    let expiryRisk = ''
    let effectiveStock = stock
    if (exp) {
      const unusable = exp.expired + exp.expiring7
      effectiveStock = Math.max(0, stock - unusable)
      if (exp.expired > 0) expiryRisk = 'expired'
      else if (exp.expiring7 > 0) expiryRisk = 'week'
      else if (exp.expiring14 > 0) expiryRisk = 'soon'
    }
    const effectiveDaysLeft = avg > 0 ? effectiveStock / avg : (effectiveStock > 0 ? Infinity : 0)

    rows.push({
      group: g, stock, avg, maxDaily, minDaily,
      daysLeft, zeroDate, trendPct, dataSource,
      depletionData, chartBars, chartDates, chartValues, chartMax,
      skuBreakdown, supplier, category,
      periodDates, periodValues,
      expiry: exp, expiryRisk, effectiveStock, effectiveDaysLeft,
      dowAvg, dowMax, peakDay, lowDay,
    })
  }

  return rows
})

function topKey(obj) {
  if (!obj) return ''
  let best = '', bestN = 0
  for (const [k, v] of Object.entries(obj)) {
    if (v > bestN) { best = k; bestN = v }
  }
  return best
}

// ═══ Chart helpers ═══

function yPos(val, maxVal) {
  return chartInnerH - 2 - (val / Math.max(maxVal, 1)) * (chartInnerH - 10)
}

function yTicks(maxVal) {
  if (maxVal <= 0) return [0]
  const step = niceStep(maxVal)
  const ticks = []
  for (let v = 0; v <= maxVal; v += step) ticks.push(Math.round(v))
  return ticks
}

function niceStep(max) {
  const rough = max / 4
  const mag = Math.pow(10, Math.floor(Math.log10(rough)))
  const norm = rough / mag
  if (norm < 1.5) return mag
  if (norm < 3) return 2 * mag
  if (norm < 7) return 5 * mag
  return 10 * mag
}

function shortNum(n) {
  if (n >= 10000) return Math.round(n / 1000) + 'к'
  if (n >= 1000) return (n / 1000).toFixed(1).replace('.0', '') + 'к'
  if (n >= 100) return Math.round(n)
  return Math.round(n * 10) / 10
}

function shortDate(d) {
  if (!d) return ''
  const parts = d.split('-')
  return parts[2] + '.' + parts[1]
}

function formatBarDate(d) {
  if (!d) return ''
  const dt = new Date(d + 'T12:00:00')
  const dow = DOW_NAMES[(dt.getDay() + 6) % 7]
  return dow + ' ' + shortDate(d)
}

function depletionStartX(r) {
  const totalBars = r.chartBars.length
  if (!totalBars) return chartPadL
  const colW = chartInnerW / totalBars
  return chartPadL + totalBars * colW
}

function depletionPath(r) {
  if (!r.avg || r.avg <= 0 || r.stock <= 0) return ''
  const totalBars = r.chartBars.length
  if (!totalBars) return ''
  const colW = chartInnerW / totalBars
  const startX = depletionStartX(r)
  const maxY = Math.max(r.chartMax, r.stock)
  const points = []
  let rem = r.stock
  const stepsForward = Math.min(Math.ceil(r.daysLeft) + 1, 15)
  for (let i = 0; i <= stepsForward; i++) {
    const x = startX + i * colW * 0.7
    if (x > chartW) break
    const y = chartInnerH - 2 - (Math.max(0, rem) / maxY) * (chartInnerH - 10)
    points.push((i === 0 ? 'M' : 'L') + x.toFixed(1) + ' ' + y.toFixed(1))
    rem -= r.avg
  }
  return points.join(' ')
}

// ═══ Filters & sorting ═══

const supplierList = computed(() => {
  const set = new Set(forecastRows.value.map(r => r.supplier).filter(Boolean))
  return [...set].sort((a, b) => a.localeCompare(b, 'ru'))
})

const categoryList = computed(() => {
  const set = new Set(forecastRows.value.map(r => r.category).filter(Boolean))
  return [...set].sort((a, b) => a.localeCompare(b, 'ru'))
})

const filteredRows = computed(() => {
  let list = forecastRows.value
  if (filterSupplier.value) list = list.filter(r => r.supplier === filterSupplier.value)
  if (filterCategory.value) list = list.filter(r => r.category === filterCategory.value)
  if (search.value) {
    const q = search.value.toLowerCase()
    list = list.filter(r => r.group.toLowerCase().includes(q) || (r.supplier || '').toLowerCase().includes(q))
  }
  if (filterAlert.value === 'red') list = list.filter(r => r.daysLeft < 3)
  else if (filterAlert.value === 'yellow') list = list.filter(r => r.daysLeft >= 3 && r.daysLeft < 7)
  else if (filterAlert.value === 'ok') list = list.filter(r => r.daysLeft >= 7)

  list = [...list]
  const s = sortKey.value
  if (s === 'days-asc') list.sort((a, b) => a.daysLeft - b.daysLeft)
  else if (s === 'stock-desc') list.sort((a, b) => b.stock - a.stock)
  else if (s === 'avg-desc') list.sort((a, b) => b.avg - a.avg)
  else if (s === 'trend') list.sort((a, b) => b.trendPct - a.trendPct)
  else if (s === 'name') list.sort((a, b) => a.group.localeCompare(b.group, 'ru'))

  return list
})

const kpi = computed(() => {
  let red = 0, yellow = 0, ok = 0
  for (const r of forecastRows.value) {
    if (r.daysLeft < 3) red++
    else if (r.daysLeft < 7) yellow++
    else ok++
  }
  return { red, yellow, ok }
})

const periodLabel = computed(() => {
  const ld = lastSaleDate.value
  if (!ld) return ''
  const end = new Date(ld + 'T12:00:00')
  const start = new Date(end)
  start.setDate(start.getDate() - (period.value - 1))
  const fmt = d => `${String(d.getDate()).padStart(2,'0')}.${String(d.getMonth()+1).padStart(2,'0')}`
  return `${fmt(start)} – ${fmt(end)}`
})

// ═══ Display helpers ═══

function fmtNum(n) {
  if (n == null || isNaN(n)) return '—'
  if (n === 0) return '0'
  return n.toLocaleString('ru-RU', { maximumFractionDigits: 1 })
}

function fmtDays(d) {
  if (d === Infinity) return '∞'
  if (d <= 0) return '0'
  return Math.round(d)
}

function badgeClass(days) {
  if (days === Infinity) return 'sfv-badge-inf'
  if (days < 3) return 'sfv-badge-red'
  if (days < 7) return 'sfv-badge-yellow'
  return 'sfv-badge-ok'
}

function rowClass(r) {
  if (r.daysLeft < 3) return 'sfv-row-red'
  if (r.daysLeft < 7) return 'sfv-row-yellow'
  return ''
}

function trendClass(pct) {
  if (pct > 15) return 'sfv-trend-up'
  if (pct < -15) return 'sfv-trend-down'
  return 'sfv-trend-flat'
}

function trendIcon(pct) {
  if (pct > 15) return '↑'
  if (pct < -15) return '↓'
  return '→'
}

function trendLabel(pct) {
  if (pct > 15) return '+' + Math.round(pct) + '%'
  if (pct < -15) return Math.round(pct) + '%'
  return 'стабильно'
}

function trendHint(r) {
  if (r.trendPct > 15) return 'Продажи растут — остатки кончатся быстрее'
  if (r.trendPct < -15) return 'Продажи падают — остатков хватит дольше'
  return 'Продажи стабильны'
}

function sparkPath(data) {
  if (!data || data.length < 2) return ''
  const max = Math.max(...data, 1)
  const step = 60 / (data.length - 1)
  return data.map((v, i) => {
    const x = i * step
    const y = 16 - (v / max) * 14
    return (i === 0 ? 'M' : 'L') + x.toFixed(1) + ' ' + y.toFixed(1)
  }).join(' ')
}

function sparkColor(r) {
  if (r.daysLeft < 3) return '#EF5350'
  if (r.daysLeft < 7) return '#FFA726'
  return '#66BB6A'
}
</script>

<style scoped>
.sfv { display: flex; flex-direction: column; gap: 8px; flex: 1; min-height: 0; }
.sfv-center { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 60px 20px; }
.sfv-empty-title { font-size: 15px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
.sfv-empty-sub { font-size: 12px; color: var(--text-muted); }

/* Period toggle */
.sfv-period-toggle { display: flex; background: var(--card); border: 1.5px solid var(--border); border-radius: 8px; overflow: hidden; }
.sfv-period-toggle button { padding: 5px 12px; border: none; background: none; color: var(--text-muted); font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.15s; }
.sfv-period-toggle button.active { background: var(--bk-brown); color: #fff; }
.sfv-period-toggle button:hover:not(.active) { background: var(--hover); }

/* Toolbar */
.sfv-toolbar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.sfv-input { padding: 5px 10px; border: 1.5px solid var(--border); border-radius: 8px; background: var(--card); color: var(--text); font-size: 12px; }
.sfv-select { min-width: 130px; }

/* KPI strip */
.sfv-kpi { display: flex; gap: 14px; align-items: center; font-size: 12px; color: var(--text-muted); flex-wrap: wrap; }
.sfv-kpi-item { cursor: pointer; }
.sfv-kpi-item:hover { text-decoration: underline; }
.sfv-kpi-red { color: #EF5350; }
.sfv-kpi-yellow { color: #FFA726; }
.sfv-kpi-ok { color: #66BB6A; }
.sfv-kpi-clear { color: var(--text-muted); cursor: pointer; font-size: 11px; }
.sfv-kpi-clear:hover { color: var(--text); }
.sfv-kpi-period { margin-left: auto; font-style: italic; }

/* Table */
.sfv-table-wrap { flex: 1; overflow: auto; min-height: 0; }
.sfv-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.sfv-tbl thead { position: sticky; top: 0; z-index: 2; }
.sfv-tbl th {
  padding: 7px 10px; text-align: left; font-size: 11px; font-weight: 700;
  color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.3px;
  background: var(--card); border-bottom: 2px solid var(--border);
  white-space: nowrap; user-select: none;
}
.sfv-tbl td { padding: 6px 10px; border-bottom: 1px solid var(--border-light); }
.sfv-tbl tbody tr:not(.sfv-detail-row) { cursor: pointer; transition: background 0.1s; }
.sfv-tbl tbody tr:not(.sfv-detail-row):hover { background: var(--hover); }

.sfv-th-expand { width: 24px; }
.sfv-th-num { text-align: right; width: 80px; }
.sfv-th-days { text-align: center; width: 70px; }
.sfv-th-date { text-align: center; width: 90px; }
.sfv-th-trend { width: 100px; }
.sfv-th-spark { width: 70px; }

.sfv-td-expand { width: 24px; text-align: center; color: var(--text-muted); }
.sfv-td-num { text-align: right; white-space: nowrap; }
.sfv-td-days { text-align: center; }
.sfv-td-date { text-align: center; font-size: 11px; color: var(--text-muted); }
.sfv-td-spark { width: 70px; }

.sfv-chevron { display: inline-block; transition: transform 0.15s; font-size: 11px; }
.sfv-chevron.open { transform: rotate(90deg); }

.sfv-td-name { display: flex; flex-direction: column; gap: 1px; }
.sfv-group-name { font-weight: 600; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 280px; }
.sfv-group-meta { font-size: 10px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 280px; }
.sfv-src-hint { display: inline-block; font-size: 9px; color: #E65100; background: #FFF3E0; padding: 1px 5px; border-radius: 4px; margin-left: 4px; font-weight: 600; vertical-align: middle; }

/* Badges */
.sfv-badge { display: inline-block; padding: 2px 8px; border-radius: 6px; font-weight: 700; font-size: 12px; min-width: 32px; text-align: center; }
.sfv-badge-red { background: #FFEBEE; color: #C62828; }
.sfv-badge-yellow { background: #FFF3E0; color: #E65100; }
.sfv-badge-ok { background: #E8F5E9; color: #2E7D32; }
.sfv-badge-inf { background: #F3E5F5; color: #7B1FA2; }

/* Row highlight */
.sfv-row-red { background: rgba(239, 83, 80, 0.04); }
.sfv-row-yellow { background: rgba(255, 167, 38, 0.04); }
.sfv-row-open { background: var(--hover); }

/* Trend */
.sfv-trend { font-size: 12px; font-weight: 600; white-space: nowrap; }
.sfv-trend-up { color: #EF5350; }
.sfv-trend-down { color: #66BB6A; }
.sfv-trend-flat { color: var(--text-muted); }

/* Sparkline */
.sfv-spark { width: 60px; height: 18px; display: block; }

/* ═══ Expanded detail ═══ */
.sfv-detail-row { background: var(--card); }
.sfv-detail-row td { padding: 0; border-bottom: 2px solid var(--border); }
.sfv-detail { display: flex; flex-direction: column; gap: 16px; padding: 16px 20px; }

.sfv-detail-chart { width: 100%; }
.sfv-detail-chart-title { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 8px; }
.sfv-big-chart { width: 100%; height: 160px; border: 1px solid var(--border-light); border-radius: 8px; background: var(--bg, #fafafa); display: block; }
.sfv-chart-label { font-size: 8px; fill: var(--text-muted, #999); }
.sfv-bar-label { font-size: 7px; fill: var(--text-muted, #999); opacity: 0; transition: opacity 0.1s; pointer-events: none; }
.sfv-bar-group:hover .sfv-bar-label { opacity: 1; }
.sfv-bar-group:hover rect:nth-child(2) { filter: brightness(0.85); }
.sfv-chart-legend { display: flex; gap: 16px; margin-top: 6px; font-size: 10px; color: var(--text-muted); }
.sfv-legend-item { display: flex; align-items: center; gap: 4px; }
.sfv-legend-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }

/* Stats cards */
.sfv-detail-stats { display: flex; flex-wrap: wrap; gap: 10px; width: 100%; }
.sfv-stat-card { background: var(--bg, #fafafa); border: 1px solid var(--border-light); border-radius: 8px; padding: 8px 12px; min-width: 100px; }
.sfv-stat-wide { width: 100%; }
.sfv-stat-label { font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 2px; }
.sfv-stat-value { font-size: 18px; font-weight: 700; }
.sfv-stat-hint { font-size: 10px; color: var(--text-muted); margin-top: 2px; }

/* SKU breakdown */
.sfv-sku-list { margin-top: 4px; }
.sfv-sku-row { display: flex; justify-content: space-between; gap: 8px; padding: 2px 0; font-size: 12px; border-bottom: 1px solid var(--border-light); }
.sfv-sku-row:last-child { border-bottom: none; }
.sfv-sku-name { color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sfv-sku-stock { font-weight: 600; white-space: nowrap; }

/* Expiry column */
.sfv-th-expiry { width: 80px; text-align: center; }
.sfv-td-expiry { text-align: center; }
.sfv-expiry-tag { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 700; }
.sfv-expiry-bad { background: #FFEBEE; color: #C62828; }
.sfv-expiry-warn { background: #FFF3E0; color: #E65100; }
.sfv-expiry-info { background: #E3F2FD; color: #1565C0; }
.sfv-expiry-ok { font-size: 11px; color: #66BB6A; font-weight: 600; }

/* Expiry breakdown in detail */
.sfv-expiry-breakdown { display: flex; flex-direction: column; gap: 4px; margin-top: 6px; }
.sfv-expiry-row { display: flex; align-items: center; gap: 8px; font-size: 12px; }
.sfv-expiry-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.sfv-expiry-qty { margin-left: auto; font-weight: 600; white-space: nowrap; }
.sfv-expiry-note { margin-top: 8px; padding: 6px 10px; background: #FFF3E0; border-radius: 6px; font-size: 12px; color: #E65100; }

/* Day of week */
.sfv-dow { display: flex; gap: 4px; align-items: flex-end; height: 70px; margin-top: 8px; }
.sfv-dow-col { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 2px; }
.sfv-dow-bar-wrap { width: 100%; height: 44px; display: flex; align-items: flex-end; justify-content: center; }
.sfv-dow-bar { width: 80%; max-width: 32px; min-height: 2px; border-radius: 3px 3px 0 0; background: #90CAF9; transition: height 0.2s; }
.sfv-dow-peak { background: #1976D2; }
.sfv-dow-low { background: #FFCC80; }
.sfv-dow-val { font-size: 9px; color: var(--text-muted); height: 12px; }
.sfv-dow-label { font-size: 10px; font-weight: 600; color: var(--text-muted); }
.sfv-dow-hint { margin-top: 6px; font-size: 11px; color: var(--text-muted); }

/* Note card */
.sfv-note-card { background: var(--bg, #fafafa); border-style: dashed; }
.sfv-note-text { font-size: 12px; color: var(--text-muted); line-height: 1.5; margin-top: 4px; }
</style>
