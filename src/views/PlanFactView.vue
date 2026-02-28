<template>
  <div class="planfact-view">
    <!-- Header -->
    <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
      <h1 class="page-title">Доставки</h1>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <div class="pf-tabs">
          <button class="pf-tab" :class="{ active: tab === 'overdue' }" @click="tab = 'overdue'; loadOrders()">
            Не принятые
            <span v-if="overdueCount > 0" class="pf-badge pf-badge-warn">{{ overdueCount }}</span>
          </button>
          <button class="pf-tab" :class="{ active: tab === 'transit' }" @click="tab = 'transit'; loadOrders()">
            В пути
            <span v-if="transitCount > 0" class="pf-badge">{{ transitCount }}</span>
          </button>
          <button class="pf-tab" :class="{ active: tab === 'received' }" @click="tab = 'received'; loadOrders()">
            Принятые
          </button>
        </div>
        <div class="pf-filter">
          <select v-model="filterSupplier" @change="loadOrders">
            <option value="">Все поставщики</option>
            <option v-for="s in suppliers" :key="s" :value="s">{{ s }}</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="loading-state" style="text-align:center;padding:40px;">
      <BurgerSpinner text="Загрузка..." />
    </div>

    <!-- Empty -->
    <div v-else-if="!orders.length" class="pf-empty">
      <BkIcon name="success" size="lg"/>
      <span>{{ emptyText }}</span>
    </div>

    <!-- Main table -->
    <div v-else class="pf-wrap">
      <table class="pf-main-table">
        <thead>
          <tr>
            <th class="pf-mth pf-mth-sort" @click="toggleMainSort('delivery_date')">
              Дата поставки <span class="pf-sort-icon">{{ mainSortIcon('delivery_date') }}</span>
            </th>
            <th class="pf-mth pf-mth-sort pf-mth-supplier" @click="toggleMainSort('supplier')">
              Поставщик <span class="pf-sort-icon">{{ mainSortIcon('supplier') }}</span>
            </th>
            <th class="pf-mth pf-mth-center">Позиций</th>
            <th class="pf-mth pf-mth-center pf-mth-boxes">Коробок</th>
            <th class="pf-mth pf-mth-center pf-mth-author">Автор</th>
            <th class="pf-mth pf-mth-center">Статус</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="order in sortedOrders"
            :key="order.id"
            class="pf-mrow"
            :class="{
              'pf-mrow-selected': selectedOrder?.id === order.id,
              'pf-mrow-overdue': tab === 'overdue',
            }"
            @click="openDrawer(order)"
          >
            <td class="pf-mtd pf-mtd-date">
              <span class="pf-date-wd">{{ weekday(order.delivery_date) }}</span>
              <span class="pf-date-main">{{ formatDate(order.delivery_date) }}</span>
            </td>
            <td class="pf-mtd pf-mtd-supplier">
              <span class="pf-supplier-dot" :style="{ background: supplierColor(order.supplier) }"></span>
              {{ order.supplier }}
            </td>
            <td class="pf-mtd pf-mtd-center">{{ (order.order_items || []).length }}</td>
            <td class="pf-mtd pf-mtd-center pf-mtd-boxes">{{ nf(sumOrderBoxes(order)) }}</td>
            <td class="pf-mtd pf-mtd-center pf-mtd-author">{{ order.created_by || '—' }}</td>
            <td class="pf-mtd pf-mtd-center">
              <template v-if="tab === 'received'">
                <span v-if="getDiscrepancyCount(order) > 0" class="pf-chip pf-chip-warn">
                  {{ getDiscrepancyCount(order) }} расх.
                </span>
                <span v-else class="pf-chip pf-chip-ok">OK</span>
                <span v-if="order.act_file" class="pf-chip pf-chip-file">Акт</span>
              </template>
              <template v-else-if="tab === 'overdue'">
                <span class="pf-chip pf-chip-overdue">Просрочен</span>
              </template>
              <template v-else>
                <span class="pf-chip pf-chip-transit">В пути</span>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
      <div class="pf-table-footer">
        <span class="pf-shown-count">Показано {{ sortedOrders.length }} {{ pluralOrders(sortedOrders.length) }}</span>
      </div>
    </div>

    <!-- Drawer -->
    <Teleport to="body">
      <Transition name="pf-drawer">
        <div v-if="drawerOpen" class="pf-drawer-overlay" @click.self="closeDrawer">
          <div class="pf-drawer">
            <div class="pf-drawer-header">
              <div>
                <div class="pf-drawer-title">{{ selectedOrder?.supplier }}</div>
                <div class="pf-drawer-subtitle">
                  <template v-if="tab === 'transit'">
                    Доставка:
                    <input type="date" class="pf-date-input" :value="editDeliveryDate" @change="onDeliveryDateChange" />
                  </template>
                  <template v-else>
                    Доставка: {{ formatDate(selectedOrder?.delivery_date) }}
                  </template>
                  <template v-if="selectedOrder?.created_by">
                    <span class="pf-drawer-sep">·</span>
                    <span class="pf-drawer-author-badge">{{ selectedOrder.created_by }}</span>
                  </template>
                  <template v-if="tab === 'received' && selectedOrder?.received_by">
                    <span class="pf-drawer-sep">·</span>
                    Принял: {{ selectedOrder.received_by }}
                  </template>
                </div>
              </div>
              <div class="pf-drawer-actions">
                <button class="pf-drawer-open-order" @click="openInOrder" title="Открыть заказ">
                  <BkIcon name="order" size="sm"/>
                </button>
                <button class="pf-drawer-close" @click="closeDrawer">
                  <BkIcon name="close" size="sm"/>
                </button>
              </div>
            </div>

            <div class="pf-drawer-body">
              <!-- Файл акта (если есть) -->
              <div v-if="selectedOrder?.act_file" class="pf-act-banner">
                <BkIcon name="note" size="sm"/>
                <span>Акт расхождения</span>
                <a :href="'/api/' + selectedOrder.act_file" target="_blank" class="pf-act-link">Просмотр</a>
                <a href="#" class="pf-act-link" @click.prevent="downloadActFile">Скачать</a>
                <button class="pf-act-delete" @click="deleteActFile" title="Удалить акт">&times;</button>
              </div>

              <table class="pf-table">
                <thead>
                  <tr>
                    <th class="pf-th pf-th-num">#</th>
                    <th class="pf-th pf-th-name">Товар</th>
                    <th class="pf-th pf-th-qty">Заказ</th>
                    <th class="pf-th pf-th-fact">Факт</th>
                    <th class="pf-th pf-th-delta">&Delta;</th>
                    <th class="pf-th pf-th-coverage">Хватит до</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="(item, idx) in drawerItems"
                    :key="item.id"
                    :class="{ 'pf-row-discrepancy': item._delta !== 0 && item._factValue !== null }"
                  >
                    <td class="pf-td pf-td-num">{{ idx + 1 }}</td>
                    <td class="pf-td pf-td-name">
                      <span class="pf-item-name">{{ item.name }}</span>
                      <span v-if="item.sku" class="pf-item-sku">{{ item.sku }}</span>
                    </td>
                    <td class="pf-td pf-td-qty">{{ item.qty_boxes }}</td>
                    <td v-if="tab === 'overdue' || tab === 'transit'" class="pf-td pf-td-fact">
                      <input
                        class="pf-fact-input"
                        :value="item._factValue"
                        @input="onFactInput(idx, $event)"
                        inputmode="numeric"
                        placeholder="—"
                      />
                    </td>
                    <td v-else-if="tab === 'received'" class="pf-td pf-td-fact">
                      <span>{{ item.received_qty ?? '—' }}</span>
                    </td>
                    <td class="pf-td pf-td-delta">
                      <template v-if="item._factValue !== null && item._delta !== 0">
                        <span :class="item._delta < 0 ? 'pf-delta-under' : 'pf-delta-over'">
                          {{ item._delta > 0 ? '+' : '' }}{{ item._delta }}
                        </span>
                      </template>
                    </td>
                    <td class="pf-td pf-td-coverage">
                      <span v-if="item._coverageDate" :class="item._coverageDays <= 3 ? 'pf-coverage-danger' : item._coverageDays <= 7 ? 'pf-coverage-warn' : 'pf-coverage-ok'">
                        {{ item._coverageDateStr }}
                        <small class="pf-coverage-days">{{ item._coverageDays }} дн.</small>
                      </span>
                      <span v-else class="pf-coverage-na">—</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div class="pf-drawer-footer">
              <!-- В пути: принять досрочно -->
              <template v-if="tab === 'transit'">
                <div class="pf-act-upload">
                  <label class="pf-act-upload-btn" :class="{ 'pf-act-has-file': actFile }">
                    <BkIcon name="note" size="xs"/>
                    {{ actFile ? actFile.name : 'Акт расхожд.' }}
                    <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic" @change="onActFileChange" hidden />
                  </label>
                  <button v-if="actFile" class="pf-act-remove" @click="actFile = null" title="Убрать">&times;</button>
                </div>
                <div class="pf-footer-buttons">
                  <button class="btn" @click="acceptAsOrdered" :disabled="saving">
                    Принять без расхождений
                  </button>
                  <button class="btn primary" @click="saveReceived" :disabled="saving || !hasAnyFact">
                    {{ saving ? 'Сохранение...' : 'Принять с расхождениями' }}
                  </button>
                </div>
              </template>

              <!-- Не принятые: ввод факта -->
              <template v-else-if="tab === 'overdue'">
                <div class="pf-act-upload">
                  <label class="pf-act-upload-btn" :class="{ 'pf-act-has-file': actFile }">
                    <BkIcon name="note" size="xs"/>
                    {{ actFile ? actFile.name : 'Акт расхожд.' }}
                    <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic" @change="onActFileChange" hidden />
                  </label>
                  <button v-if="actFile" class="pf-act-remove" @click="actFile = null" title="Убрать">&times;</button>
                </div>
                <div class="pf-footer-buttons">
                  <button class="btn" @click="acceptAsOrdered" :disabled="saving">
                    Принять без расхождений
                  </button>
                  <button class="btn primary" @click="saveReceived" :disabled="saving || !hasAnyFact">
                    {{ saving ? 'Сохранение...' : 'Принять с расхождениями' }}
                  </button>
                </div>
              </template>

              <!-- Принятые: сводка + возврат -->
              <template v-else>
                <div class="pf-footer-received">
                  <div class="pf-summary">
                    <span v-if="receivedDiscrepancies > 0" class="pf-delta-under">
                      {{ receivedDiscrepancies }} расхождений из {{ drawerItems.length }}
                    </span>
                    <span v-else style="color:var(--green);">Все позиции совпадают</span>
                  </div>
                  <div class="pf-footer-actions">
                    <button class="btn pf-btn-revert" @click="revertToTransit" :disabled="saving">
                      <BkIcon name="redo" size="xs"/> Вернуть
                    </button>
                  </div>
                </div>
              </template>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- Confirm modal -->
    <Teleport to="body">
      <div v-if="confirmModal.show" class="modal" @click.self="onCancel">
        <div class="modal-box" style="max-width:400px;">
          <h3 style="margin-bottom:8px;">{{ confirmModal.title }}</h3>
          <p style="color:var(--text-muted);font-size:14px;margin-bottom:20px;">{{ confirmModal.message }}</p>
          <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button class="btn" @click="onCancel">Отмена</button>
            <button class="btn primary" @click="onConfirm">Подтвердить</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { db } from '@/lib/apiClient.js'
import { useUserStore } from '@/stores/userStore.js'
import { useOrderStore } from '@/stores/orderStore.js'
import { useToastStore } from '@/stores/toastStore.js'
import { useDraftStore } from '@/stores/draftStore.js'
import { useConfirm } from '@/composables/useConfirm.js'
import BkIcon from '@/components/ui/BkIcon.vue'
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue'

const router = useRouter()
const userStore = useUserStore()
const orderStore = useOrderStore()
const draftStore = useDraftStore()
const toast = useToastStore()
const { confirmModal, confirm, onConfirm, onCancel } = useConfirm()

const tab = ref('overdue')
const loading = ref(false)
const saving = ref(false)
const orders = ref([])
const transitCount = ref(0)
const overdueCount = ref(0)
const filterSupplier = ref('')
const suppliers = ref([])

// Main table sort
const mainSortKey = ref('delivery_date')
const mainSortAsc = ref(true)

function toggleMainSort(key) {
  if (mainSortKey.value === key) mainSortAsc.value = !mainSortAsc.value
  else { mainSortKey.value = key; mainSortAsc.value = key === 'supplier' }
}
function mainSortIcon(key) {
  if (mainSortKey.value !== key) return '⇅'
  return mainSortAsc.value ? '↑' : '↓'
}

const sortedOrders = computed(() => {
  const arr = [...orders.value]
  const k = mainSortKey.value
  const dir = mainSortAsc.value ? 1 : -1
  arr.sort((a, b) => {
    const va = a[k] || '', vb = b[k] || ''
    if (va < vb) return -1 * dir
    if (va > vb) return 1 * dir
    return 0
  })
  return arr
})

// Supplier colors
const PALETTE = ['#F5A623','#4CAF50','#2196F3','#9C27B0','#F44336','#00BCD4','#FF5722','#607D8B','#E91E63','#795548']
const supplierColorMap = computed(() => {
  const map = {}
  const allS = [...new Set(orders.value.map(o => o.supplier).filter(Boolean))].sort()
  allS.forEach((s, i) => { map[s] = PALETTE[i % PALETTE.length] })
  return map
})
function supplierColor(name) { return supplierColorMap.value[name] || '#999' }

// Weekday helper
const WD = ['вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб']
function weekday(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr + 'T00:00:00')
  return WD[d.getDay()]
}

// Drawer
const drawerOpen = ref(false)
const selectedOrder = ref(null)
const drawerItems = ref([])
const actFile = ref(null)
const editDeliveryDate = ref('')

const legalEntity = computed(() => orderStore.settings.legalEntity)
const hasAnyFact = computed(() => drawerItems.value.some(i => i._factValue !== null))

const emptyText = computed(() => {
  if (tab.value === 'transit') return 'Нет заказов в пути'
  if (tab.value === 'overdue') return 'Нет непринятых доставок'
  return 'Нет принятых заказов'
})

const receivedDiscrepancies = computed(() =>
  drawerItems.value.filter(i => {
    const fact = tab.value === 'received' ? Number(i.received_qty ?? i.qty_boxes) : i._factValue
    return fact !== null && fact !== Number(i.qty_boxes)
  }).length
)

const formatter = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 })
function nf(v) { return formatter.format(v || 0) }

function pluralOrders(n) {
  const mod = n % 10, mod100 = n % 100
  if (mod === 1 && mod100 !== 11) return 'заказ'
  if (mod >= 2 && mod <= 4 && (mod100 < 12 || mod100 > 14)) return 'заказа'
  return 'заказов'
}

function sumOrderBoxes(order) {
  return (order.order_items || []).reduce((s, i) => s + (parseFloat(String(i.qty_boxes || '0').replace(',', '.')) || 0), 0)
}

onMounted(async () => {
  await loadSuppliers()
  await loadOrders()
})

watch(legalEntity, async () => {
  await loadSuppliers()
  await loadOrders()
})

async function loadSuppliers() {
  const { data } = await db.from('suppliers').select('short_name').order('short_name')
  if (data) suppliers.value = data.map(s => s.short_name)
}

function todayStr() {
  const d = new Date()
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0')
}

function datePlusDays(dateStr, n) {
  const d = new Date(dateStr + 'T00:00:00')
  d.setDate(d.getDate() + n)
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0')
}

async function loadOrders() {
  loading.value = true
  try {
    const today = todayStr()
    let query = db.from('orders')
      .select('*, order_items(*)')
      .eq('legal_entity', legalEntity.value)
      .order('delivery_date', { ascending: tab.value !== 'received' })
      .limit(200)

    if (tab.value === 'transit') {
      query = query.is('received_at', null).gt('delivery_date', today)
    } else if (tab.value === 'overdue') {
      query = query.is('received_at', null).lte('delivery_date', today)
    } else {
      query = query.not('received_at', 'is', null)
    }

    if (filterSupplier.value) {
      query = query.eq('supplier', filterSupplier.value)
    }

    const { data, error } = await query
    if (error) { toast.error('Ошибка', 'Не удалось загрузить заказы'); return }
    orders.value = data || []

    // Reset sort direction based on tab
    if (tab.value === 'received') mainSortAsc.value = false
    else mainSortAsc.value = true

    await loadCounts()
  } finally {
    loading.value = false
  }
}

async function loadCounts() {
  const today = todayStr()
  const le = legalEntity.value

  const [transitRes, overdueRes] = await Promise.all([
    db.from('orders').select('id').eq('legal_entity', le).is('received_at', null).gt('delivery_date', today).limit(200),
    db.from('orders').select('id').eq('legal_entity', le).is('received_at', null).lte('delivery_date', today).limit(200),
  ])
  transitCount.value = transitRes.data?.length ?? 0
  overdueCount.value = overdueRes.data?.length ?? 0
}

const MONTHS = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек']

function formatDate(dateStr) {
  if (!dateStr) return '—'
  const d = new Date(dateStr + 'T00:00:00')
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function formatDateShort(d) {
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' })
}

function getDiscrepancyCount(order) {
  if (!order.order_items) return 0
  return order.order_items.filter(i =>
    i.received_qty != null && Number(i.received_qty) !== Number(i.qty_boxes)
  ).length
}

/**
 * Расчёт покрытия — идентично формуле в calculations.js.
 */
function calcCoverageForItem(item, factQty, order) {
  const periodDays = Number(order?.period_days) || 30
  const consumptionPeriod = Number(item.consumption_period) || 0
  const daily = periodDays > 0 ? consumptionPeriod / periodDays : 0
  if (daily <= 0) return { date: null, days: 0, str: '—' }

  const deliveryDate = new Date((order?.delivery_date || '') + 'T00:00:00')
  if (isNaN(deliveryDate.getTime())) return { date: null, days: 0, str: '—' }

  const orderToday = new Date((order?.today_date || todayStr()) + 'T00:00:00')
  const transitDays = Math.max(0, Math.ceil((deliveryDate - orderToday) / 86400000))

  const stock = Number(item.stock) || 0
  const transit = Number(item.transit) || 0
  const consumedBeforeDelivery = daily * transitDays
  const stockAfterDelivery = Math.max(0, (stock + transit) - consumedBeforeDelivery)

  const mult = Number(item.multiplicity) || 1
  const qpb = Number(item.qty_per_box) || 1
  const unit = order?.unit || 'boxes'
  const physBoxes = factQty ?? (Number(item.qty_boxes) || 0)
  const effectiveQty = unit === 'boxes' ? physBoxes * mult : physBoxes * qpb * mult

  const available = stockAfterDelivery + effectiveQty

  const coverageDays = Math.floor(available / daily)
  if (coverageDays <= 0) return { date: null, days: 0, str: '—' }

  const coverageDate = new Date(deliveryDate)
  coverageDate.setDate(coverageDate.getDate() + coverageDays)

  return { date: coverageDate, days: coverageDays, str: formatDateShort(coverageDate) }
}

async function openInOrder() {
  if (!selectedOrder.value) return
  const order = selectedOrder.value
  await orderStore.loadOrderIntoForm(order, order.legal_entity, false, true)
  draftStore.saveNow()
  closeDrawer()
  router.push({ name: 'order' })
  toast.success('Заказ загружен', 'Режим просмотра')
}

function openDrawer(order) {
  selectedOrder.value = order
  actFile.value = null
  editDeliveryDate.value = order.delivery_date || ''
  drawerItems.value = (order.order_items || []).map(item => {
    const factVal = tab.value === 'received' ? (item.received_qty != null ? Number(item.received_qty) : null) : null
    const delta = factVal !== null ? factVal - Number(item.qty_boxes) : 0
    const cov = calcCoverageForItem(item, factVal, order)
    return { ...item, _factValue: factVal, _delta: delta, _coverageDate: cov.date, _coverageDays: cov.days, _coverageDateStr: cov.str }
  })
  drawerOpen.value = true
}

function closeDrawer() {
  drawerOpen.value = false
  selectedOrder.value = null
  drawerItems.value = []
  actFile.value = null
}

function onFactInput(idx, event) {
  const val = event.target.value.replace(/[^0-9]/g, '')
  const num = val === '' ? null : parseInt(val, 10)
  const item = drawerItems.value[idx]
  item._factValue = num
  item._delta = num !== null ? num - Number(item.qty_boxes) : 0
  const cov = calcCoverageForItem(item, num, selectedOrder.value)
  item._coverageDate = cov.date
  item._coverageDays = cov.days
  item._coverageDateStr = cov.str
}

async function onDeliveryDateChange(e) {
  const newDate = e.target.value
  if (!newDate || !selectedOrder.value) return
  const ok = await confirm('Изменить дату доставки?', `Новая дата: ${formatDate(newDate)}`)
  if (!ok) { e.target.value = editDeliveryDate.value; return }

  saving.value = true
  try {
    await db.from('orders').update({ delivery_date: newDate }).eq('id', selectedOrder.value.id)
    const userName = userStore.currentUser?.name || 'Неизвестно'
    const now = new Date().toISOString().slice(0, 19).replace('T', ' ')
    await db.from('audit_log').insert({
      entity_type: 'order', entity_id: selectedOrder.value.id, action: 'delivery_date_changed',
      user_name: userName,
      details: JSON.stringify({ supplier: selectedOrder.value.supplier, old_date: editDeliveryDate.value, new_date: newDate }),
      created_at: now,
    })
    editDeliveryDate.value = newDate
    selectedOrder.value.delivery_date = newDate
    toast.success('Дата изменена', formatDate(newDate))
    closeDrawer()
    await loadOrders()
  } catch (e) {
    toast.error('Ошибка', 'Не удалось изменить дату')
  } finally {
    saving.value = false
  }
}

function onActFileChange(e) {
  const file = e.target.files?.[0]
  if (!file) return
  if (file.size > 10 * 1024 * 1024) { toast.warning('Файл слишком большой', 'Максимум 10 МБ'); return }
  actFile.value = file
}

async function downloadActFile() {
  if (!selectedOrder.value?.act_file) return
  try {
    const url = '/api/' + selectedOrder.value.act_file
    const resp = await fetch(url)
    if (!resp.ok) { toast.error('Ошибка', 'Не удалось скачать файл'); return }
    const blob = await resp.blob()
    const filename = selectedOrder.value.act_file.split('/').pop() || 'act.pdf'
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = filename
    document.body.appendChild(a)
    a.click()
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove() }, 100)
  } catch (e) {
    toast.error('Ошибка', 'Не удалось скачать файл')
  }
}

async function deleteActFile() {
  if (!selectedOrder.value?.act_file) return
  const ok = await confirm('Удалить акт?', 'Файл акта расхождения будет удалён.')
  if (!ok) return
  try {
    const apiKey = localStorage.getItem('bk_api_key') || ''
    const resp = await fetch('/api/upload/act?order_id=' + selectedOrder.value.id, {
      method: 'DELETE', headers: { 'X-API-Key': apiKey }
    })
    if (!resp.ok) { toast.error('Ошибка', 'Не удалось удалить файл'); return }
    selectedOrder.value.act_file = null
    toast.success('Удалено', 'Акт расхождения удалён')
  } catch (e) {
    toast.error('Ошибка', 'Не удалось удалить файл')
  }
}

async function uploadActFile(orderId) {
  if (!actFile.value) return false
  const formData = new FormData()
  formData.append('order_id', orderId)
  formData.append('file', actFile.value)
  const apiKey = localStorage.getItem('bk_api_key') || ''
  try {
    const resp = await fetch('/api/upload/act', { method: 'POST', headers: { 'X-API-Key': apiKey }, body: formData })
    if (!resp.ok) {
      const body = await resp.json().catch(() => ({}))
      toast.error('Ошибка загрузки', body.error || 'Не удалось загрузить файл')
      return false
    }
    return true
  } catch (e) {
    toast.error('Ошибка загрузки', 'Не удалось загрузить файл')
    return false
  }
}

async function acceptAsOrdered() {
  const label = tab.value === 'transit' ? 'Принять досрочно?' : 'Принять согласно заказу?'
  const msg = tab.value === 'transit'
    ? `Заказ ${selectedOrder.value.supplier} будет принят досрочно. Дата доставки: ${formatDate(selectedOrder.value.delivery_date)}.`
    : 'Все позиции будут приняты с количеством, указанным в заказе.'
  const ok = await confirm(label, msg)
  if (!ok) return

  saving.value = true
  try {
    const items = drawerItems.value
    const userName = userStore.currentUser?.name || 'Неизвестно'
    for (const item of items) {
      await db.from('order_items').update({ received_qty: item.qty_boxes }).eq('id', item.id)
    }
    const now = new Date().toISOString().slice(0, 19).replace('T', ' ')
    await db.from('orders').update({ received_at: now, received_by: userName }).eq('id', selectedOrder.value.id)
    if (actFile.value) await uploadActFile(selectedOrder.value.id)
    await db.from('audit_log').insert({
      entity_type: 'order', entity_id: selectedOrder.value.id, action: 'received', user_name: userName,
      details: JSON.stringify({ supplier: selectedOrder.value.supplier, items_count: items.length, discrepancies: 0 }),
      created_at: now,
    })
    toast.success('Принято', `Заказ ${selectedOrder.value.supplier} принят`)
    closeDrawer()
    await loadOrders()
  } catch (e) {
    toast.error('Ошибка', 'Не удалось сохранить приёмку')
  } finally { saving.value = false }
}

async function saveReceived() {
  const itemsWithFact = drawerItems.value.filter(i => i._factValue !== null)
  if (!itemsWithFact.length) return
  const discrepancies = itemsWithFact.filter(i => i._delta !== 0).length
  const msg = discrepancies > 0
    ? `Будет сохранено ${itemsWithFact.length} позиций, из них ${discrepancies} с расхождениями.`
    : `Все ${itemsWithFact.length} позиций совпадают с заказом.`
  const ok = await confirm('Сохранить приёмку?', msg)
  if (!ok) return

  saving.value = true
  try {
    const userName = userStore.currentUser?.name || 'Неизвестно'
    for (const item of itemsWithFact) {
      await db.from('order_items').update({ received_qty: item._factValue }).eq('id', item.id)
    }
    for (const item of drawerItems.value.filter(i => i._factValue === null)) {
      await db.from('order_items').update({ received_qty: item.qty_boxes }).eq('id', item.id)
    }
    const now = new Date().toISOString().slice(0, 19).replace('T', ' ')
    await db.from('orders').update({ received_at: now, received_by: userName }).eq('id', selectedOrder.value.id)
    if (actFile.value) await uploadActFile(selectedOrder.value.id)
    await db.from('audit_log').insert({
      entity_type: 'order', entity_id: selectedOrder.value.id, action: 'received', user_name: userName,
      details: JSON.stringify({
        supplier: selectedOrder.value.supplier, items_count: drawerItems.value.length, discrepancies,
        items_with_discrepancy: itemsWithFact.filter(i => i._delta !== 0).map(i => ({ name: i.name, ordered: i.qty_boxes, received: i._factValue, delta: i._delta })),
      }),
      created_at: now,
    })
    toast.success('Принято', `Заказ ${selectedOrder.value.supplier} принят${discrepancies ? ` (${discrepancies} расхожд.)` : ''}`)
    closeDrawer()
    await loadOrders()
  } catch (e) {
    toast.error('Ошибка', 'Не удалось сохранить приёмку')
  } finally { saving.value = false }
}

async function revertToTransit() {
  const ok = await confirm('Вернуть заказ?', `Заказ ${selectedOrder.value.supplier} будет снова помечен как не принятый. Данные приёмки будут сброшены.`)
  if (!ok) return
  saving.value = true
  try {
    const userName = userStore.currentUser?.name || 'Неизвестно'
    const orderId = selectedOrder.value.id
    for (const item of drawerItems.value) {
      await db.from('order_items').update({ received_qty: null }).eq('id', item.id)
    }
    await db.from('orders').update({ received_at: null, received_by: null }).eq('id', orderId)
    const now = new Date().toISOString().slice(0, 19).replace('T', ' ')
    await db.from('audit_log').insert({
      entity_type: 'order', entity_id: orderId, action: 'reception_reverted', user_name: userName,
      details: JSON.stringify({ supplier: selectedOrder.value.supplier, reverted_from: selectedOrder.value.received_by }),
      created_at: now,
    })
    toast.success('Возвращено', `Заказ ${selectedOrder.value.supplier} снова ожидает приёмки`)
    closeDrawer()
    await loadOrders()
  } catch (e) {
    toast.error('Ошибка', 'Не удалось вернуть заказ')
  } finally { saving.value = false }
}
</script>

<style scoped>
/* ═══ Tabs toggle ═══ */
.pf-tabs {
  display: inline-flex; border: 1.5px solid var(--border); border-radius: 8px;
  overflow: hidden; background: var(--bg);
}
.pf-tab {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 6px 14px; border: none; background: transparent;
  font-size: 12px; font-weight: 600; font-family: inherit;
  color: var(--text-muted); cursor: pointer; transition: all 0.15s;
  white-space: nowrap; line-height: 1;
  border-right: 1.5px solid var(--border);
}
.pf-tab:last-child { border-right: none; }
.pf-tab:hover { color: var(--text); background: rgba(0,0,0,0.02); }
.pf-tab.active {
  background: var(--bk-orange, #E87A1E); color: white;
  box-shadow: 0 1px 4px rgba(245,166,35,0.3);
}

/* ═══ Filter ═══ */
.pf-filter select {
  padding: 6px 10px; border: 1px solid var(--border); border-radius: 6px;
  font-size: 12px; font-weight: 600; font-family: inherit; color: var(--text);
  background: white; cursor: pointer;
}

/* ═══ Badge ═══ */
.pf-badge {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 18px; height: 18px; border-radius: 9px;
  background: #1565C0; color: #fff; font-size: 10px; font-weight: 700;
  padding: 0 5px; margin-left: 3px; line-height: 1;
}
.pf-badge-warn { background: #D62700; }
.pf-tab.active .pf-badge { background: rgba(255,255,255,0.9); color: var(--bk-orange, #E87A1E); }
.pf-tab.active .pf-badge-warn { background: rgba(255,255,255,0.9); color: #D62700; }

/* ═══ Empty ═══ */
.pf-empty {
  text-align: center; padding: 60px 20px; color: var(--text-muted);
  display: flex; flex-direction: column; align-items: center; gap: 8px;
}

/* ═══ Main table wrapper ═══ */
.pf-wrap {
  overflow-x: auto; border-radius: 10px; border: 1px solid var(--border); background: white;
}

/* ═══ Main table ═══ */
.pf-main-table { width: 100%; border-collapse: collapse; font-size: 13px; }

/* ═══ Main table header ═══ */
.pf-mth {
  padding: 8px 10px; text-align: left; font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.4px; color: var(--text-muted);
  background: var(--bg); border-bottom: 2px solid var(--border);
  white-space: nowrap; position: sticky; top: 0; z-index: 1;
}
.pf-mth-sort { cursor: pointer; user-select: none; }
.pf-mth-sort:hover { color: var(--text); }
.pf-sort-icon { font-size: 10px; opacity: 0.5; margin-left: 2px; }
.pf-mth-center { text-align: center; }
.pf-mth-supplier { padding-left: 18px; }

/* ═══ Main table rows ═══ */
.pf-mrow {
  cursor: pointer; transition: background 0.1s;
  border-bottom: 1px solid var(--border-light, #f0f0f0);
  border-left: 3px solid transparent;
}
.pf-mrow:hover { background: #FFFBF5; }
.pf-mrow-selected { background: #FFF3E0; }
.pf-mrow:last-child { border-bottom: none; }
.pf-mrow-overdue { border-left-color: #D62700; }

/* ═══ Main table cells ═══ */
.pf-mtd { padding: 7px 10px; vertical-align: middle; color: var(--text); }
.pf-mtd-date { white-space: nowrap; width: 130px; }
.pf-date-wd { font-size: 10px; color: var(--text-muted); margin-right: 4px; text-transform: capitalize; }
.pf-date-main { font-weight: 700; color: var(--text); }
.pf-mtd-supplier { font-weight: 600; white-space: nowrap; padding-left: 18px; color: var(--bk-brown, #4A2C0A); }
.pf-supplier-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 5px; vertical-align: middle; }
.pf-mtd-center { text-align: center; font-size: 12px; color: var(--text-secondary); white-space: nowrap; }

/* ═══ Chip badges ═══ */
.pf-chip {
  display: inline-flex; align-items: center; gap: 3px;
  padding: 3px 8px; border-radius: 6px;
  font-size: 10px; font-weight: 700; line-height: 1;
}
.pf-chip-warn { background: #FFEBEE; color: #C62828; }
.pf-chip-ok { background: #E8F5E9; color: #2E7D32; }
.pf-chip-file { background: #E3F2FD; color: #1565C0; margin-left: 4px; }
.pf-chip-overdue { background: #FFEBEE; color: #C62828; }
.pf-chip-transit { background: #E3F2FD; color: #1565C0; }

/* ═══ Table footer ═══ */
.pf-table-footer {
  display: flex; align-items: center; justify-content: center;
  padding: 10px 16px; border-top: 1px solid var(--border-light, #f0f0f0);
}
.pf-shown-count { font-size: 12px; color: var(--text-muted); font-weight: 500; }

/* ═══ Drawer ═══ */
.pf-drawer-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,0.3); z-index: 1000;
  display: flex; justify-content: flex-end;
}
.pf-drawer {
  width: 560px; max-width: 100%; height: 100%;
  background: var(--bg, #fff);
  display: flex; flex-direction: column;
  box-shadow: -4px 0 24px rgba(0,0,0,0.12);
}
.pf-drawer-header {
  display: flex; justify-content: space-between; align-items: flex-start;
  padding: 20px 24px 14px; border-bottom: 1px solid var(--border-light, #eee);
}
.pf-drawer-title { font-weight: 700; font-size: 16px; color: var(--bk-brown, #4A2C0A); }
.pf-drawer-subtitle { font-size: 13px; color: var(--text-muted); margin-top: 2px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.pf-drawer-sep { color: var(--border); margin: 0 2px; }
.pf-drawer-author-badge {
  padding: 1px 8px; background: var(--bg); border-radius: 10px;
  font-weight: 600; font-size: 11px; color: var(--text-secondary);
}
.pf-date-input {
  padding: 2px 6px; border: 1px solid var(--border); border-radius: 4px;
  font-size: 13px; font-family: inherit; color: var(--text);
}
.pf-drawer-actions { display: flex; align-items: center; gap: 4px; }
.pf-drawer-open-order {
  background: none; border: 1px solid var(--border); border-radius: 6px;
  cursor: pointer; padding: 4px 8px; color: var(--text-muted); transition: all 0.15s;
  display: inline-flex; align-items: center;
}
.pf-drawer-open-order:hover { color: var(--bk-orange, #E87A1E); border-color: var(--bk-orange, #E87A1E); }
.pf-drawer-close {
  background: none; border: 1px solid var(--border); border-radius: 6px;
  cursor: pointer; padding: 4px 6px; color: var(--text-muted); transition: all 0.15s;
  display: inline-flex; align-items: center;
}
.pf-drawer-close:hover { color: var(--text); background: var(--bg); }
.pf-drawer-body { flex: 1; overflow-y: auto; padding: 0; }

/* ═══ Act banner in drawer ═══ */
.pf-act-banner {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 24px; background: #E3F2FD; border-bottom: 1px solid #90CAF9;
  font-size: 13px; color: #1565C0; font-weight: 500;
}
.pf-act-link {
  font-weight: 600; color: #1565C0; text-decoration: underline; font-size: 13px;
}
.pf-act-link:hover { text-decoration: none; }
.pf-act-link + .pf-act-link { margin-left: 4px; }
.pf-act-delete {
  margin-left: auto; background: none; border: none; cursor: pointer;
  font-size: 20px; line-height: 1; color: var(--text-muted); padding: 0 4px;
}
.pf-act-delete:hover { color: #D62700; }

/* ═══ Drawer items table ═══ */
.pf-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.pf-th {
  text-align: left; padding: 8px 10px; font-weight: 700; font-size: 11px;
  text-transform: uppercase; letter-spacing: 0.4px; color: var(--text-muted);
  border-bottom: 2px solid var(--border);
  position: sticky; top: 0; background: var(--bg, #fff); z-index: 1;
}
.pf-th-num { width: 32px; text-align: center; }
.pf-th-qty, .pf-th-fact, .pf-th-delta { width: 64px; text-align: right; }
.pf-th-coverage { width: 90px; text-align: center; }
.pf-td { padding: 7px 10px; border-bottom: 1px solid var(--border-light, #f5f5f5); vertical-align: middle; }
.pf-td-num { text-align: center; color: var(--text-muted); font-size: 12px; }
.pf-td-name { max-width: 180px; }
.pf-item-name { display: block; font-weight: 500; line-height: 1.3; }
.pf-item-sku { display: block; font-size: 11px; color: var(--text-muted); }
.pf-td-qty { text-align: right; font-weight: 700; color: var(--bk-brown, #4A2C0A); }
.pf-td-fact { text-align: right; }
.pf-td-delta { text-align: right; font-weight: 700; font-size: 13px; }
.pf-td-coverage { text-align: center; font-size: 12px; font-weight: 600; }

.pf-coverage-danger { color: #D32F2F; }
.pf-coverage-warn { color: #E65100; }
.pf-coverage-ok { color: #2E7D32; }
.pf-coverage-na { color: var(--text-muted); }
.pf-coverage-days { display: block; font-size: 10px; font-weight: 500; opacity: 0.7; }

.pf-fact-input {
  width: 64px; text-align: right; padding: 6px 8px;
  border: 1.5px solid var(--border, #ddd); border-radius: 6px;
  font-size: 14px; font-weight: 600; font-family: inherit; transition: border-color 0.15s;
}
.pf-fact-input:focus {
  outline: none; border-color: var(--bk-orange, #E87A1E);
  box-shadow: 0 0 0 2px rgba(232, 122, 30, 0.15);
}

.pf-row-discrepancy { background: #FFEBEE; }
.pf-delta-under { color: #D32F2F; }
.pf-delta-over { color: #E65100; }

/* ═══ Footer ═══ */
.pf-drawer-footer {
  padding: 14px 24px; border-top: 2px solid var(--border); background: var(--bg);
}
.pf-footer-buttons { display: flex; gap: 8px; margin-top: 6px; }
.pf-footer-received {
  display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap;
}
.pf-footer-actions { display: flex; gap: 8px; align-items: center; }
.pf-summary { font-size: 13px; font-weight: 600; }
.pf-btn-revert {
  display: inline-flex; align-items: center; gap: 4px;
  color: #D62700; border-color: #D62700;
}
.pf-btn-revert:hover { background: rgba(214, 39, 0, 0.06); }

.pf-act-upload { display: flex; align-items: center; gap: 4px; }
.pf-act-upload-btn {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 4px 10px; border: 1px dashed var(--border, #ccc); border-radius: 6px;
  font-size: 12px; font-weight: 500; color: var(--text-secondary);
  cursor: pointer; transition: all 0.15s;
  max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.pf-act-upload-btn:hover { border-color: var(--bk-orange, #E87A1E); color: var(--text); }
.pf-act-upload-btn.pf-act-has-file { border-style: solid; border-color: #1565C0; color: #1565C0; background: #E3F2FD; }
.pf-act-remove { background: none; border: none; cursor: pointer; font-size: 18px; line-height: 1; color: var(--text-muted); padding: 0 4px; }
.pf-act-remove:hover { color: #D62700; }

/* ═══ Drawer transition ═══ */
.pf-drawer-enter-active, .pf-drawer-leave-active { transition: opacity 0.2s ease; }
.pf-drawer-enter-active .pf-drawer, .pf-drawer-leave-active .pf-drawer { transition: transform 0.25s ease; }
.pf-drawer-enter-from { opacity: 0; }
.pf-drawer-enter-from .pf-drawer { transform: translateX(100%); }
.pf-drawer-leave-to { opacity: 0; }
.pf-drawer-leave-to .pf-drawer { transform: translateX(100%); }

/* ═══ Mobile ═══ */
@media (max-width: 768px) {
  .pf-mth-boxes, .pf-mtd-boxes { display: none; }
  .pf-mth-author, .pf-mtd-author { display: none; }
  .pf-drawer { width: 100%; }
  .pf-footer-buttons { flex-direction: column; }
  .pf-footer-buttons .btn { width: 100%; }
  .pf-footer-received { flex-direction: column; align-items: flex-start; }
  .pf-footer-actions { width: 100%; }
  .pf-footer-actions .btn { flex: 1; }
  .pf-fact-input { font-size: 16px; width: 60px; }
  .pf-th-coverage, .pf-td-coverage { display: none; }
}
</style>
