<template>
  <div class="dash">
    <div class="dash-header">
      <h1 class="page-title">Дашборд</h1>
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

    <div v-if="loading" class="dash-loading">Загрузка данных...</div>

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
          <div class="dash-card" v-if="data.topSuppliers?.length">
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

const entities = computed(() => userStore.getAllowedEntities() || [])

const topKpis = computed(() => {
  const d = data.value
  return [
    { icon: '📦', value: d.ordersCount || 0, label: 'Заказов', delta: d.ordersDelta },
    { icon: '💰', value: fmtK(d.totalAmount), label: 'Сумма закупок (BYN)', delta: d.amountDelta },
    { icon: '🚚', value: (d.deliveredPct || 0) + '%', label: 'Выполнение поставок', delta: null },
    { icon: '⚠️', value: d.overdueCount || 0, label: 'Просроченные', delta: null },
    { icon: '📉', value: d.lowStockCount || 0, label: 'Низкий запас', delta: null },
    { icon: '💳', value: d.paymentsUpcoming || 0, label: 'Оплаты', delta: null },
  ]
})

async function load() {
  loading.value = true
  try {
    const { data: d } = await db.rpc('dashboard_kpi', { period: period.value, legal_entity: entityFilter.value || null })
    data.value = d || {}
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
</style>
