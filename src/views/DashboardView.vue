<template>
  <div class="dash">
    <div class="dash-header">
      <h1 class="page-title">Дашборд</h1>
      <!-- freshness badge removed -->
      <div class="dash-controls">
        <select v-model="entityFilter" class="dash-select" @change="load">
          <option value="">Все юрлица</option>
          <option v-for="le in entities" :key="le" :value="le">{{ le }}</option>
        </select>
        <select v-model="period" class="dash-select" @change="load">
          <option value="week">Неделя</option>
          <option value="month">Месяц</option>
          <option value="quarter">Квартал</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="dash-loading"><BurgerSpinner text="Загрузка данных..." /></div>

    <template v-else>
      <!-- KPI Row 1 -->
      <div class="dash-kpis">
        <div class="dash-kpi" v-for="kpi in topKpis" :key="kpi.label">
          <div class="dash-kpi-top">
            <span class="dash-kpi-icon">{{ kpi.icon }}</span>
            <span v-if="kpi.delta" class="dash-kpi-delta" :class="kpi.delta > 0 ? 'up' : 'down'">
              {{ kpi.delta > 0 ? '+' : '' }}{{ kpi.delta }}%
            </span>
          </div>
          <div class="dash-kpi-val">{{ kpi.value }}</div>
          <div class="dash-kpi-label">{{ kpi.label }}</div>
        </div>
      </div>

      <div class="dash-cols">
        <!-- Левая колонка -->
        <div class="dash-col">
          <!-- Топ поставщиков -->
          <div class="dash-card" v-if="data.topSuppliers?.length && userStore.hasAccess('pricing', 'view')">
            <div class="dash-card-title">Топ поставщиков по сумме</div>
            <div class="dash-top-list">
              <div v-for="(s, i) in data.topSuppliers" :key="i" class="dash-top-item">
                <span class="dash-top-rank">{{ i + 1 }}</span>
                <div class="dash-top-info">
                  <div class="dash-top-name">{{ s.supplier }}</div>
                  <div class="dash-top-bar"><div class="dash-top-bar-fill" :style="{ width: barWidth(s.total, data.topSuppliers[0].total) }"></div></div>
                </div>
                <span class="dash-top-val">{{ fmtK(s.total) }}</span>
              </div>
            </div>
          </div>

          <!-- Просроченные поставки -->
          <div class="dash-card" v-if="data.overdueOrders?.length">
            <div class="dash-card-title">⚠️ Просроченные поставки</div>
            <div class="dash-overdue-list">
              <div v-for="o in data.overdueOrders" :key="o.id" class="dash-overdue-item">
                <strong>{{ o.supplier }}</strong>
                <span class="dash-overdue-date">{{ fmtDate(o.delivery_date) }}</span>
                <span class="dash-overdue-days">{{ o.days_overdue }} дн.</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Правая колонка -->
        <div class="dash-col">
          <!-- Предстоящие оплаты -->
          <div class="dash-card" v-if="data.upcomingPayments?.length">
            <div class="dash-card-title">💳 Ближайшие оплаты</div>
            <div class="dash-pay-list">
              <div v-for="p in data.upcomingPayments" :key="p.id" class="dash-pay-item">
                <strong>{{ p.supplier }}</strong>
                <span class="dash-pay-date">{{ fmtDate(p.payment_date) }}</span>
                <span class="dash-pay-amount">{{ p.amount ? fmtK(p.amount) + ' ' + (p.currency || 'RUB') : '—' }}</span>
              </div>
            </div>
          </div>

          <!-- Активность -->
          <div class="dash-card">
            <div class="dash-card-title">📊 Активность</div>
            <div class="dash-stats">
              <div class="dash-stat-row"><span>Корректировки ожидают</span><strong>{{ data.correctionsPending }}</strong></div>
              <div class="dash-stat-row"><span>Сообщений в чате</span><strong>{{ data.chatUnread }}</strong></div>
              <div class="dash-stat-row"><span>Товаров с запасом ≤3 дн.</span><strong>{{ data.lowStockCount }}</strong></div>
              <div class="dash-stat-row"><span>Активных тендеров</span><strong>{{ data.activeTenders }}</strong></div>
              <div class="dash-stat-row"><span>Активных сборов остатков</span><strong>{{ data.activeCollections }}</strong></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Блок руководителя -->
      <div class="dash-cols" style="margin-top:16px;">
        <!-- Критичные запасы -->
        <div class="dash-col">
          <div class="dash-card">
            <div class="dash-card-title">🔴 Критичные запасы (менее 5 дней)</div>
            <div v-if="criticalStock.length" class="dash-crit-list">
              <div v-for="s in criticalStock.slice(0, 10)" :key="s.analog_group" class="dash-crit-item">
                <span class="dash-crit-name">{{ s.analog_group }}</span>
                <span class="dash-crit-days" :class="s.days_of_stock <= 2 ? 'red' : 'orange'">{{ s.days_of_stock }} дн</span>
              </div>
              <div v-if="criticalStock.length > 10" class="dash-crit-more">и ещё {{ criticalStock.length - 10 }} позиций</div>
            </div>
            <div v-else class="dash-empty-block">Критичных позиций нет ✓</div>
          </div>

          <!-- Задачи из протоколов -->
          <div class="dash-card">
            <div class="dash-card-title">📋 Задачи из протоколов</div>
            <div v-if="pendingTasks.length" class="dash-tasks-list">
              <div v-for="t in pendingTasks.slice(0, 8)" :key="t.id" class="dash-task-item" :class="{ overdue: t.status === 'overdue' || (t.deadline && t.deadline < todayStr) }">
                <div class="dash-task-text">{{ t.text }}</div>
                <div class="dash-task-meta">
                  <span>{{ t.responsible_person }}</span>
                  <span v-if="t.deadline" class="dash-task-deadline">до {{ fmtDate(t.deadline) }}</span>
                </div>
              </div>
            </div>
            <div v-else class="dash-empty-block">Нет открытых задач ✓</div>
          </div>
        </div>

        <!-- Активность команды -->
        <div class="dash-col">
          <div class="dash-card">
            <div class="dash-card-title">👥 Активность команды</div>
            <div v-if="teamActivity.length" class="dash-activity-list">
              <div v-for="a in teamActivity" :key="a.created_at" class="dash-activity-item">
                <div class="dash-activity-dot"></div>
                <div class="dash-activity-body">
                  <span class="dash-activity-user">{{ a.user_name }}</span>
                  <span class="dash-activity-action">{{ formatAction(a.action) }}</span>
                  <span v-if="a.details" class="dash-activity-details">{{ formatDetails(a.details) }}</span>
                </div>
                <span class="dash-activity-time">{{ formatTimeAgo(new Date(a.created_at)) }}</span>
              </div>
            </div>
            <div v-else class="dash-empty-block">Нет активности</div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { db } from '@/lib/apiClient.js'
import { useUserStore } from '@/stores/userStore.js'

const userStore = useUserStore()
const period = ref('week')
const entityFilter = ref('')
const loading = ref(false)
const data = ref({})
const lastLoadedAt = ref(null)
const teamActivity = ref([])
const pendingTasks = ref([])
const criticalStock = ref([])

const entities = computed(() => userStore.getAllowedEntities() || [])

const topKpis = computed(() => {
  const d = data.value
  const showPricing = userStore.hasAccess('pricing', 'view')
  return [
    { icon: '📦', value: d.ordersCount || 0, label: 'Заказов', delta: d.ordersDelta },
    ...(showPricing ? [{ icon: '💰', value: fmtK(d.totalAmount), label: 'Сумма закупок (BYN)', delta: d.amountDelta }] : []),
    { icon: '🚚', value: (d.deliveredPct || 0) + '%', label: 'Выполнение поставок', delta: null },
    { icon: '⚠️', value: d.overdueCount || 0, label: 'Просроченные', delta: null },
    { icon: '📉', value: d.lowStockCount || 0, label: 'Низкий запас', delta: null },
    { icon: '💳', value: d.paymentsUpcoming || 0, label: 'Оплаты', delta: null },
  ]
})

async function load() {
  loading.value = true
  try {
    const kpiRes = await db.rpc('dashboard_kpi', { period: period.value, legal_entity: entityFilter.value || null })
    data.value = kpiRes.data || {}
    // Дополнительные данные — грузим независимо, ошибки не ломают дашборд
    const [actRes, tasksRes, stockRes] = await Promise.allSettled([
      db.from('audit_log').select('action, user_name, created_at, details').order('created_at', { ascending: false }).limit(15),
      db.rpc('get_pending_tasks_all'),
      db.rpc('dashboard_critical_stock', { legal_entity: entityFilter.value || null }),
    ])
    teamActivity.value = actRes.status === 'fulfilled' ? (actRes.value?.data || []) : []
    pendingTasks.value = tasksRes.status === 'fulfilled' ? (tasksRes.value?.data || []) : []
    criticalStock.value = stockRes.status === 'fulfilled' ? (stockRes.value?.data || []) : []
    lastLoadedAt.value = new Date()
  } catch { data.value = {} }
  finally { loading.value = false }
}

function fmtK(v) {
  if (!v) return '0'
  const n = Number(v)
  if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M'
  if (n >= 1000) return (n / 1000).toFixed(1) + 'K'
  return n.toLocaleString('ru-RU', { maximumFractionDigits: 0 })
}
function fmtDate(d) {
  if (!d) return ''
  const dt = new Date(d + 'T00:00:00')
  return dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' })
}
function barWidth(v, max) { return max > 0 ? (v / max * 100) + '%' : '0%' }
function truncateStr(s, len) { return s && s.length > len ? s.slice(0, len) + '…' : (s || '') }
const todayStr = new Date().toISOString().slice(0, 10)

const ACTION_LABELS = {
  order_created: 'создал заказ',
  order_updated: 'обновил заказ',
  order_deleted: 'удалил заказ',
  plan_created: 'создал план',
  plan_updated: 'обновил план',
  plan_deleted: 'удалил план',
  order_received: 'принял поставку',
  login: 'вошёл в систему',
  import_data: 'импортировал данные',
  protocol_created: 'создал протокол',
  protocol_finalized: 'финализировал протокол',
}

function formatAction(action) {
  return ACTION_LABELS[action] || action.replace(/_/g, ' ')
}

function formatDetails(details) {
  if (!details) return ''
  try {
    const d = typeof details === 'string' ? JSON.parse(details) : details
    const parts = []
    if (d.supplier) parts.push(d.supplier)
    if (d.items_count) parts.push(d.items_count + ' поз.')
    if (d.period) parts.push(d.period)
    if (d.note) parts.push(d.note)
    if (d.param_changes?.length) {
      d.param_changes.forEach(c => parts.push(c.label + ': ' + c.from + ' → ' + c.to))
    }
    if (d.changes?.length) {
      parts.push(d.changes.length + ' изменений')
    }
    return parts.join(' · ') || ''
  } catch { return truncateStr(String(details), 50) }
}

function formatTimeAgo(date) {
  if (!date) return ''
  const sec = Math.floor((Date.now() - date.getTime()) / 1000)
  if (sec < 60) return 'только что'
  if (sec < 3600) return Math.floor(sec / 60) + ' мин. назад'
  if (sec < 86400) return Math.floor(sec / 3600) + ' ч. назад'
  return date.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

onMounted(load)
</script>

<style scoped>
.dash { padding: 20px 28px; }
.dash-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 8px; }
.dash-controls { display: flex; gap: 8px; }
.dash-select { padding: 6px 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 13px; }
.dash-loading { text-align: center; padding: 60px; color: var(--text-muted); }

/* KPIs */
.dash-kpis { display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; margin-bottom: 20px; }
@media (max-width: 1200px) { .dash-kpis { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 700px) { .dash-kpis { grid-template-columns: repeat(2, 1fr); } }

.dash-kpi { background: var(--card); border: 1px solid var(--border-light); border-radius: 12px; padding: 16px; }
.dash-kpi-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.dash-kpi-icon { font-size: 20px; }
.dash-kpi-delta { font-size: 12px; font-weight: 700; padding: 2px 6px; border-radius: 8px; }
.dash-kpi-delta.up { color: #2E7D32; background: #E8F5E9; }
.dash-kpi-delta.down { color: #C62828; background: #FFEBEE; }
.dash-kpi-val { font-size: 24px; font-weight: 800; }
.dash-kpi-label { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

/* Two column layout */
.dash-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 900px) { .dash-cols { grid-template-columns: 1fr; } }
.dash-col { display: flex; flex-direction: column; gap: 16px; }

/* Cards */
.dash-card { background: var(--card); border: 1px solid var(--border-light); border-radius: 12px; padding: 16px 20px; }
.dash-card-title { font-size: 14px; font-weight: 700; margin-bottom: 12px; }

/* Top suppliers */
.dash-top-list { display: flex; flex-direction: column; gap: 8px; }
.dash-top-item { display: flex; align-items: center; gap: 10px; }
.dash-top-rank { width: 22px; height: 22px; border-radius: 50%; background: var(--bk-cream); font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.dash-top-info { flex: 1; min-width: 0; }
.dash-top-name { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dash-top-bar { height: 4px; background: var(--border-light); border-radius: 2px; margin-top: 3px; }
.dash-top-bar-fill { height: 100%; background: var(--bk-brown); border-radius: 2px; transition: width 0.3s; }
.dash-top-val { font-size: 12px; color: var(--text-muted); font-weight: 600; white-space: nowrap; }

/* Overdue */
.dash-overdue-list { display: flex; flex-direction: column; gap: 6px; }
.dash-overdue-item { display: flex; align-items: center; gap: 8px; font-size: 13px; padding: 6px 0; border-bottom: 1px solid var(--border-light); }
.dash-overdue-item:last-child { border-bottom: none; }
.dash-overdue-date { color: var(--text-muted); font-size: 12px; margin-left: auto; }
.dash-overdue-days { color: #F44336; font-weight: 700; font-size: 12px; }

/* Payments */
.dash-pay-list { display: flex; flex-direction: column; gap: 6px; }
.dash-pay-item { display: flex; align-items: center; gap: 8px; font-size: 13px; padding: 6px 0; border-bottom: 1px solid var(--border-light); }
.dash-pay-item:last-child { border-bottom: none; }
.dash-pay-date { color: var(--text-muted); font-size: 12px; }
.dash-pay-amount { margin-left: auto; font-weight: 600; font-size: 12px; }

/* Stats */
.dash-stats { display: flex; flex-direction: column; gap: 4px; }
.dash-stat-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid var(--border-light); font-size: 13px; }
.dash-stat-row:last-child { border-bottom: none; }

/* Critical stock */
.dash-crit-list { display: flex; flex-direction: column; gap: 4px; }
.dash-crit-item { display: flex; justify-content: space-between; align-items: center; padding: 5px 0; border-bottom: 1px solid var(--border-light); font-size: 13px; }
.dash-crit-item:last-child { border-bottom: none; }
.dash-crit-name { font-weight: 500; }
.dash-crit-days { font-weight: 700; font-size: 12px; padding: 2px 8px; border-radius: 6px; }
.dash-crit-days.red { background: #FFEBEE; color: #C62828; }
.dash-crit-days.orange { background: #FFF3E0; color: #E65100; }
.dash-crit-more { font-size: 12px; color: var(--text-muted); padding-top: 6px; }

/* Tasks */
.dash-tasks-list { display: flex; flex-direction: column; gap: 6px; }
.dash-task-item { padding: 8px 10px; border-radius: 8px; background: #FFFBF5; border-left: 3px solid #F4A261; }
.dash-task-item.overdue { background: #FFF5F5; border-left-color: #F44336; }
.dash-task-text { font-size: 13px; font-weight: 500; }
.dash-task-meta { font-size: 11px; color: var(--text-muted); margin-top: 3px; display: flex; gap: 8px; }
.dash-task-deadline { font-weight: 600; }
.dash-task-item.overdue .dash-task-deadline { color: #F44336; }

/* Activity */
.dash-activity-list { display: flex; flex-direction: column; gap: 0; }
.dash-activity-item { display: flex; align-items: flex-start; gap: 10px; padding: 8px 0; border-bottom: 1px solid var(--border-light); }
.dash-activity-item:last-child { border-bottom: none; }
.dash-activity-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--bk-brown); margin-top: 5px; flex-shrink: 0; }
.dash-activity-body { flex: 1; min-width: 0; font-size: 12px; }
.dash-activity-user { font-weight: 700; }
.dash-activity-action { color: var(--text-muted); margin-left: 4px; }
.dash-activity-details { display: block; color: var(--text-muted); font-size: 11px; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dash-activity-time { font-size: 10px; color: var(--text-muted); white-space: nowrap; flex-shrink: 0; }

/* Empty */
.dash-empty-block { padding: 16px; text-align: center; color: var(--text-muted); font-size: 13px; }

.data-freshness { font-size: 11px; color: var(--text-muted, #999); display: inline-flex; align-items: center; gap: 4px; }
.data-freshness::before { content: '●'; font-size: 6px; color: #4CAF50; }
</style>
