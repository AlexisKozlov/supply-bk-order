<template>
  <div class="crv">
    <!-- Loading -->
    <div v-if="loading" class="crv-center"><BurgerSpinner /></div>

    <!-- Empty -->
    <div v-else-if="!rows.length" class="crv-center">
      <p class="crv-empty-title">Нет данных для сравнения</p>
      <p class="crv-empty-sub">Загрузите данные анализа запасов и реализации ресторанов</p>
    </div>

    <template v-else>
      <!-- Controls -->
      <div class="crv-toolbar">
        <div class="crv-toolbar-left">
          <input v-model="search" class="crv-input" placeholder="Поиск группы…" />
          <select v-model="sortBy" class="crv-input crv-sort">
            <option value="diff">По расхождению</option>
            <option value="name">По названию</option>
            <option value="warehouse">По расходу склада</option>
            <option value="restaurant">По реализации</option>
          </select>
          <label class="crv-toggle">
            <input type="checkbox" v-model="onlyMismatch" />
            <span>Только расхождения</span>
          </label>
        </div>
        <div class="crv-toolbar-right">
          <button
            v-if="excluded.size > 0"
            class="crv-excl-btn"
            @click="showExcluded = !showExcluded"
          >
            Исключения ({{ excluded.size }})
          </button>
          <span class="crv-legend">
            <span class="crv-dot crv-dot-wh"></span>Склад
            <span class="crv-dot crv-dot-rest"></span>Рестораны
          </span>
        </div>
      </div>

      <!-- Excluded panel -->
      <div v-if="showExcluded" class="crv-excl-panel">
        <div class="crv-excl-header">
          <span class="crv-excl-title">Исключённые группы</span>
          <button class="crv-excl-clear" @click="clearExcluded">Очистить все</button>
        </div>
        <div class="crv-excl-list">
          <div v-for="name in sortedExcluded" :key="name" class="crv-excl-item">
            <span class="crv-excl-name">{{ name }}</span>
            <button class="crv-excl-restore" @click="restoreGroup(name)">Вернуть</button>
          </div>
        </div>
      </div>

      <!-- Summary strip -->
      <div class="crv-strip">
        <span>{{ filteredRows.length }} из {{ rows.length }} групп</span>
        <span v-if="mismatchCount > 0" class="crv-strip-warn">{{ mismatchCount }} с расхождением &gt;30%</span>
        <span v-if="excluded.size > 0" class="crv-strip-excl">{{ excluded.size }} скрыто</span>
        <span class="crv-strip-period">{{ periodLabel }}</span>
      </div>

      <!-- Table -->
      <div class="crv-table-box">
        <table class="crv-tbl">
          <thead>
            <tr>
              <th class="crv-col-name">Группа аналогов</th>
              <th class="crv-col-num">Склад</th>
              <th class="crv-col-num">Рестораны</th>
              <th class="crv-col-diff">Разница</th>
              <th class="crv-col-bar">Соотношение</th>
              <th class="crv-col-act"></th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(r, i) in filteredRows"
              :key="r.name"
              class="crv-tr"
              :class="{ 'crv-tr-alt': i % 2 === 1, 'crv-tr-warn': isWarn(r), 'crv-tr-danger': isDanger(r) }"
            >
              <td class="crv-cell-name">
                <span class="crv-name-text">{{ r.name }}</span>
              </td>
              <td class="crv-cell-num">{{ nf(r.warehouse) }}</td>
              <td class="crv-cell-num">{{ nf(r.restaurant) }}</td>
              <td class="crv-cell-diff" :class="diffCls(r)">
                <span class="crv-diff-badge" :class="diffBadgeCls(r)">{{ diffLabel(r) }}</span>
              </td>
              <td class="crv-cell-bar">
                <div class="crv-bar-row">
                  <div class="crv-bar crv-bar-wh" :style="{ width: barPct(r.warehouse, r) }"></div>
                </div>
                <div class="crv-bar-row">
                  <div class="crv-bar crv-bar-rest" :style="{ width: barPct(r.restaurant, r) }"></div>
                </div>
              </td>
              <td class="crv-cell-act">
                <button class="crv-hide-btn" @click="excludeGroup(r.name)" title="Исключить из отчёта">✕</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { db } from '@/lib/apiClient.js'
import { applyEntityGroupFilter, toLocalDateStr } from '@/lib/utils.js'
import { useOrderStore } from '@/stores/orderStore.js'
import { useUserStore } from '@/stores/userStore.js'
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue'

const orderStore = useOrderStore()
const userStore = useUserStore()

const loading = ref(false)
const warehouseData = ref({})
const restaurantData = ref({})
const search = ref('')
const onlyMismatch = ref(false)
const sortBy = ref('diff')
const lastDate = ref(null)
const showExcluded = ref(false)

// --- Exclusions (server) ---
const excluded = reactive(new Set())
const excludedIds = reactive(new Map()) // name → id in DB

async function loadExcluded() {
  try {
    const { data } = await db.from('report_exclusions').select('id, analog_group')
    if (data) {
      excluded.clear()
      excludedIds.clear()
      for (const row of data) {
        excluded.add(row.analog_group)
        excludedIds.set(row.analog_group, row.id)
      }
    }
  } catch { /* тихо */ }
}

async function excludeGroup(name) {
  excluded.add(name)
  try {
    const userName = userStore.currentUser?.name || ''
    const { data } = await db.from('report_exclusions').insert({ analog_group: name, created_by: userName })
    if (data?.id) excludedIds.set(name, data.id)
  } catch { /* уже есть — ок */ }
}

async function restoreGroup(name) {
  excluded.delete(name)
  const id = excludedIds.get(name)
  excludedIds.delete(name)
  if (excluded.size === 0) showExcluded.value = false
  if (id) {
    try { await db.from('report_exclusions').delete().eq('id', id) } catch {}
  }
}

async function clearExcluded() {
  const ids = [...excludedIds.values()]
  excluded.clear()
  excludedIds.clear()
  showExcluded.value = false
  for (const id of ids) {
    try { await db.from('report_exclusions').delete().eq('id', id) } catch {}
  }
}

const sortedExcluded = computed(() => [...excluded].sort((a, b) => a.localeCompare(b, 'ru')))

// --- Period label ---
const periodLabel = computed(() => {
  const ld = lastDate.value
  if (!ld) return '30 дней'
  const end = new Date(ld + 'T12:00:00')
  const start = new Date(end)
  start.setDate(start.getDate() - 29)
  const fmt = d => `${String(d.getDate()).padStart(2,'0')}.${String(d.getMonth()+1).padStart(2,'0')}`
  return `${fmt(start)} – ${fmt(end)}`
})

// --- Load data ---
async function loadData() {
  loading.value = true
  try {
    const entity = orderStore.settings.legalEntity

    let q = db.from('products').select('sku, name, analog_group, qty_per_box')
    q = applyEntityGroupFilter(q, entity)
    const { data: products } = await q

    const skus = (products || []).map(p => p.sku).filter(Boolean)
    const analysisMap = new Map()
    if (skus.length) {
      const { data: analysis } = await db
        .from('analysis_data')
        .select('sku, consumption, period_days')
        .eq('legal_entity', entity)
        .in('sku', skus)
      if (analysis) {
        for (const a of analysis) analysisMap.set(a.sku, a)
      }
    }

    const whMap = {}
    for (const p of (products || [])) {
      if (!p.analog_group) continue
      const a = analysisMap.get(p.sku)
      if (!a || !a.consumption) continue
      const daily = (a.period_days || 30) > 0 ? a.consumption / (a.period_days || 30) : 0
      if (!whMap[p.analog_group]) whMap[p.analog_group] = 0
      whMap[p.analog_group] += daily * 30
    }
    warehouseData.value = whMap

    const { data: metaRows } = await db
      .from('restaurant_sales')
      .select('sale_date')
      .eq('legal_entity', entity)
      .order('sale_date', { ascending: false })
      .limit(1)

    if (!metaRows?.length) {
      restaurantData.value = {}
      lastDate.value = null
      return
    }
    const ld = metaRows[0].sale_date
    lastDate.value = ld
    const cutoff = new Date(ld + 'T12:00:00')
    cutoff.setDate(cutoff.getDate() - 29)
    const cutoffStr = toLocalDateStr(cutoff)

    const { data: sales } = await db
      .from('restaurant_sales')
      .select('analog_group, quantity, sale_date')
      .eq('legal_entity', entity)
      .gte('sale_date', cutoffStr)
      .limit(50000)

    const restMap = {}
    for (const s of (sales || [])) {
      if (!s.analog_group) continue
      if (!restMap[s.analog_group]) restMap[s.analog_group] = 0
      restMap[s.analog_group] += parseFloat(s.quantity) || 0
    }
    restaurantData.value = restMap
  } catch (e) {
    console.error('[report] Load error:', e)
  } finally {
    loading.value = false
  }
}

// --- Computed ---
const rows = computed(() => {
  const wh = warehouseData.value
  const rest = restaurantData.value
  const allNames = new Set([...Object.keys(wh), ...Object.keys(rest)])
  const result = []
  for (const name of allNames) {
    if (excluded.has(name)) continue
    const w = Math.round((wh[name] || 0) * 10) / 10
    const r = Math.round((rest[name] || 0) * 10) / 10
    if (w <= 0 || r <= 0) continue
    const base = Math.max(w, r)
    const diffPct = base > 0 ? Math.round((w - r) / base * 100) : null
    result.push({ name, warehouse: w, restaurant: r, diffPct })
  }
  return result
})

const mismatchCount = computed(() => rows.value.filter(r => Math.abs(r.diffPct ?? 0) > 30).length)

const filteredRows = computed(() => {
  let list = rows.value
  if (search.value) {
    const q = search.value.toLowerCase()
    list = list.filter(r => r.name.toLowerCase().includes(q))
  }
  if (onlyMismatch.value) {
    list = list.filter(r => Math.abs(r.diffPct ?? 0) > 30)
  }
  const s = sortBy.value
  return [...list].sort((a, b) => {
    if (s === 'diff') return Math.abs(b.diffPct ?? 0) - Math.abs(a.diffPct ?? 0)
    if (s === 'name') return a.name.localeCompare(b.name, 'ru')
    if (s === 'warehouse') return b.warehouse - a.warehouse
    if (s === 'restaurant') return b.restaurant - a.restaurant
    return 0
  })
})

// --- Helpers ---
function nf(v) {
  if (!v) return '0'
  return v.toLocaleString('ru-RU', { maximumFractionDigits: 1 })
}

function diffLabel(r) {
  if (r.diffPct === null) return '—'
  const sign = r.diffPct > 0 ? '+' : ''
  return `${sign}${r.diffPct}%`
}

function isWarn(r) { return r.diffPct !== null && Math.abs(r.diffPct) > 30 && Math.abs(r.diffPct) <= 50 }
function isDanger(r) { return r.diffPct !== null && Math.abs(r.diffPct) > 50 }

function diffCls(r) {
  if (r.diffPct === null) return ''
  if (Math.abs(r.diffPct) > 50) return 'crv-c-danger'
  if (Math.abs(r.diffPct) > 30) return 'crv-c-warn'
  return 'crv-c-ok'
}

function diffBadgeCls(r) {
  if (r.diffPct === null) return 'crv-badge-muted'
  if (Math.abs(r.diffPct) > 50) return 'crv-badge-danger'
  if (Math.abs(r.diffPct) > 30) return 'crv-badge-warn'
  return 'crv-badge-ok'
}

function barPct(val, r) {
  const max = Math.max(r.warehouse, r.restaurant)
  if (max <= 0) return '0%'
  return Math.round(val / max * 100) + '%'
}

watch(() => orderStore.settings.legalEntity, () => { loadData() })
onMounted(async () => { await loadExcluded(); loadData() })
</script>

<style scoped>
.crv {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
}

/* Center states */
.crv-center {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  flex: 1;
  padding: 60px 20px;
  text-align: center;
}
.crv-empty-title { font-weight: 600; color: var(--text); margin: 0 0 6px; font-size: 15px; }
.crv-empty-sub { color: var(--text-muted); font-size: 13px; margin: 0; }

/* Toolbar */
.crv-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  flex-wrap: wrap;
  flex-shrink: 0;
  padding-bottom: 6px;
}
.crv-toolbar-left {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.crv-toolbar-right {
  display: flex;
  align-items: center;
  gap: 10px;
}
.crv-input {
  padding: 5px 10px;
  border: 1.5px solid var(--border);
  border-radius: 8px;
  background: var(--card);
  color: var(--text);
  font-size: 12px;
  font-family: inherit;
  outline: none;
  transition: border-color 0.15s;
}
.crv-input:focus { border-color: var(--bk-brown); }
.crv-input:first-child { width: 180px; }
.crv-sort { width: auto; }
.crv-toggle {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  color: var(--text-muted);
  cursor: pointer;
  user-select: none;
  white-space: nowrap;
}
.crv-toggle input { cursor: pointer; accent-color: var(--bk-brown); }

/* Legend */
.crv-legend {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  color: var(--text-muted);
}
.crv-dot {
  width: 10px;
  height: 10px;
  border-radius: 3px;
  flex-shrink: 0;
}
.crv-dot:not(:first-child) { margin-left: 8px; }
.crv-dot-wh { background: var(--bk-brown, #502314); }
.crv-dot-rest { background: #42A5F5; }

/* Exclusions button */
.crv-excl-btn {
  padding: 4px 10px;
  border: 1.5px solid var(--border);
  border-radius: 8px;
  background: var(--card);
  color: var(--text-muted);
  font-size: 11px;
  font-family: inherit;
  cursor: pointer;
  transition: all 0.15s;
}
.crv-excl-btn:hover { border-color: var(--bk-brown); color: var(--text); }

/* Excluded panel */
.crv-excl-panel {
  border: 1.5px solid var(--border);
  border-radius: 8px;
  background: var(--card);
  padding: 10px 14px;
  margin-bottom: 6px;
}
.crv-excl-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}
.crv-excl-title {
  font-size: 12px;
  font-weight: 600;
  color: var(--text);
}
.crv-excl-clear {
  font-size: 11px;
  color: var(--bk-red, #D62300);
  background: none;
  border: none;
  cursor: pointer;
  font-family: inherit;
  padding: 0;
}
.crv-excl-clear:hover { text-decoration: underline; }
.crv-excl-list {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.crv-excl-item {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 3px 8px 3px 10px;
  background: var(--bg);
  border-radius: 6px;
  font-size: 12px;
}
.crv-excl-name { color: var(--text-secondary); }
.crv-excl-restore {
  font-size: 10px;
  color: var(--bk-brown);
  background: none;
  border: none;
  cursor: pointer;
  font-family: inherit;
  font-weight: 600;
  padding: 0;
}
.crv-excl-restore:hover { text-decoration: underline; }

/* Summary strip */
.crv-strip {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 11px;
  color: var(--text-muted);
  padding: 4px 0 6px;
  flex-shrink: 0;
  border-bottom: 1px solid var(--border-light);
}
.crv-strip-warn { color: #BF360C; font-weight: 600; }
.crv-strip-excl { color: var(--text-muted); font-style: italic; }
.crv-strip-period { margin-left: auto; }

/* Table container */
.crv-table-box {
  flex: 1;
  overflow: auto;
  min-height: 0;
}

/* Table */
.crv-tbl {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 13px;
}
.crv-tbl thead {
  position: sticky;
  top: 0;
  z-index: 2;
}
.crv-tbl th {
  background: var(--card);
  color: var(--text-muted);
  font-weight: 600;
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  padding: 8px 12px;
  text-align: left;
  border-bottom: 2px solid var(--border);
  white-space: nowrap;
}
.crv-col-num { text-align: right; width: 90px; }
.crv-col-diff { text-align: center; width: 80px; }
.crv-col-bar { text-align: left; width: 180px; }
.crv-col-act { width: 36px; }

/* Rows */
.crv-tr td {
  padding: 8px 12px;
  border-bottom: 1px solid var(--border-light);
  background: var(--card);
  vertical-align: middle;
}
.crv-tr-alt td { background: #FDFCFB; }
.crv-tr:hover td { background: #F5F2EE; }

/* Row warn/danger */
.crv-tr-warn td { background: #FFF8F0; }
.crv-tr-warn.crv-tr-alt td { background: #FFF5E8; }
.crv-tr-warn:hover td { background: #FFEDCC; }
.crv-tr-danger td { background: #FFF0EE; }
.crv-tr-danger.crv-tr-alt td { background: #FFEBE8; }
.crv-tr-danger:hover td { background: #FFD6CC; }

/* Cells */
.crv-cell-name { max-width: 300px; }
.crv-name-text {
  font-weight: 600;
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.crv-cell-num {
  text-align: right;
  font-variant-numeric: tabular-nums;
  color: var(--text-secondary);
}
.crv-cell-diff { text-align: center; }

/* Hide button */
.crv-cell-act { text-align: center; padding: 4px !important; }
.crv-hide-btn {
  width: 22px;
  height: 22px;
  border: none;
  border-radius: 4px;
  background: transparent;
  color: var(--text-muted);
  font-size: 12px;
  cursor: pointer;
  opacity: 0;
  transition: all 0.15s;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-family: inherit;
}
.crv-tr:hover .crv-hide-btn { opacity: 0.5; }
.crv-hide-btn:hover { opacity: 1 !important; background: var(--border-light); color: var(--bk-red, #D62300); }

/* Diff badge */
.crv-diff-badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 10px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.2px;
}
.crv-badge-ok { background: #E8F5E9; color: #2E7D32; }
.crv-badge-warn { background: #FFF3E0; color: #E65100; }
.crv-badge-danger { background: #FFEBEE; color: #C62828; }
.crv-badge-muted { background: var(--bg); color: var(--text-muted); }

/* Bars */
.crv-cell-bar { padding: 5px 12px; }
.crv-bar-row {
  height: 7px;
  background: var(--border-light);
  border-radius: 4px;
  margin-bottom: 3px;
  overflow: hidden;
}
.crv-bar-row:last-child { margin-bottom: 0; }
.crv-bar {
  height: 100%;
  border-radius: 4px;
  transition: width 0.3s ease;
  min-width: 2px;
}
.crv-bar-wh { background: var(--bk-brown, #502314); }
.crv-bar-rest { background: #42A5F5; }

@media (max-width: 768px) {
  .crv-col-bar, .crv-cell-bar { display: none; }
  .crv-col-act, .crv-cell-act { display: none; }
  .crv-cell-name { max-width: 160px; }
  .crv-toolbar { flex-direction: column; align-items: stretch; }
  .crv-toolbar-left { flex-direction: column; align-items: stretch; }
  .crv-toolbar-right { justify-content: space-between; }
  .crv-input { width: 100% !important; min-height: 36px; font-size: 14px; }
  .crv-sort { width: 100% !important; }
  .crv-tbl { font-size: 12px; }
  .crv-tbl th { padding: 6px 8px; font-size: 9px; }
  .crv-tr td { padding: 8px 8px; }
  .crv-col-num { width: 65px; }
  .crv-col-diff { width: 65px; }
  .crv-strip { flex-wrap: wrap; gap: 6px; }
}
@media (max-width: 480px) {
  .crv-tbl thead { display: none; }
  .crv-tbl, .crv-tbl tbody { display: block; }
  .crv-tr { display: block !important; padding: 10px 12px; border-bottom: 1px solid var(--border-light); }
  .crv-tr td { display: inline; padding: 0 !important; border: none !important; background: none !important; }
  .crv-tr-alt { background: #FDFCFB; }
  .crv-tr:hover { background: #F5F2EE; }
  .crv-cell-name { display: block !important; max-width: none; margin-bottom: 6px; }
  .crv-name-text { white-space: normal; }
  .crv-cell-num { display: inline-block !important; margin-right: 16px; }
  .crv-cell-num::before { font-size: 10px; color: var(--text-muted); font-weight: 400; }
  .crv-tr td:nth-child(2)::before { content: 'Склад: '; }
  .crv-tr td:nth-child(3)::before { content: 'Рест: '; }
  .crv-cell-diff { display: inline-block !important; float: right; }
}
</style>
