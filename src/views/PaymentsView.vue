<template>
  <div class="pay">
    <div class="pay-top">
      <h1 class="page-title">Оплаты поставщиков</h1>
      <div class="pay-filters">
        <select v-model="statusFilter" class="pay-input" @change="load">
          <option value="">Все</option>
          <option value="upcoming">Предстоящие</option>
          <option value="request_due">Нужна заявка</option>
          <option value="requested">Заявка подана</option>
          <option value="paid">Оплачено</option>
          <option value="cancelled">Отменено</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="pay-empty"><BurgerSpinner text="Загрузка..." /></div>
    <div v-else-if="!payments.length" class="pay-empty">Нет оплат</div>

    <div v-else class="pay-table-wrap">
      <table class="pay-table">
        <thead>
          <tr>
            <th>Поставщик</th>
            <th>Юрлицо</th>
            <th>Дата прихода</th>
            <th>Отсрочка</th>
            <th>Дата оплаты</th>
            <th>Дедлайн заявки</th>
            <th>Сумма</th>
            <th>Статус</th>
            <th>Комментарий</th>
            <th>Действия</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in payments" :key="p.id" :class="'pay-row-' + p.status">
            <td>
              <a class="pay-link" @click="goToOrder(p)" title="Перейти к заказу">{{ p.supplier }}</a>
            </td>
            <td class="pay-le">{{ shortLE(p.legal_entity) }}</td>
            <td>{{ fmtDate(p.delivery_date) }}</td>
            <td class="pay-center">{{ p.payment_delay_days }} дн.</td>
            <td>
              <input v-if="editId === p.id" type="date" v-model="editPayDate" class="pay-date-input" />
              <strong v-else>{{ fmtDate(p.payment_date) }}</strong>
            </td>
            <td>{{ fmtDateTime(p.request_deadline) }}</td>
            <td>
              <input v-if="editId === p.id" v-model="editAmount" type="number" class="pay-amount-input" placeholder="Сумма" />
              <span v-else>{{ p.amount ? fmtAmount(p.amount) + ' ' + (p.currency || 'RUB') : '—' }}</span>
            </td>
            <td><span class="pay-badge" :class="p.status">{{ statusLabel(p.status) }}</span></td>
            <td>
              <input v-if="editId === p.id" v-model="editNote" class="pay-note-input" placeholder="Комментарий" />
              <span v-else class="pay-note">{{ p.note || '' }}</span>
            </td>
            <td>
              <div class="pay-actions">
                <template v-if="editId === p.id">
                  <button class="pay-btn save" @click="saveEdit(p)" title="Сохранить">💾</button>
                  <button class="pay-btn" @click="editId = null" title="Отмена">✕</button>
                </template>
                <template v-else>
                  <button class="pay-btn" @click="startEdit(p)" title="Редактировать">✏️</button>
                  <button v-if="p.status === 'upcoming' || p.status === 'request_due'" class="pay-btn requested" @click="markRequested(p)" title="Заявка подана">📋</button>
                  <button v-if="p.status === 'requested'" class="pay-btn approve" @click="markPaid(p)" title="Оплачено">✅</button>
                  <button v-if="p.status !== 'paid' && p.status !== 'cancelled'" class="pay-btn" @click="cancel(p)" title="Отменить">🗑</button>
                </template>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { db } from '@/lib/apiClient.js'
import { useOrderStore } from '@/stores/orderStore.js'
import { useToastStore } from '@/stores/toastStore.js'
import { getEntityGroupCode } from '@/lib/legalEntities.js'

const orderStore = useOrderStore()

const toast = useToastStore()
const router = useRouter()
const loading = ref(false)
const payments = ref([])
const statusFilter = ref('')
const editId = ref(null)
const editAmount = ref('')
const editNote = ref('')
const editPayDate = ref('')

async function load() {
  loading.value = true
  try {
    // Оплаты смотрим по всей группе юрлиц (БК+ВМ или ПС отдельно) — финансовый
    // отдел работает по группе, а не по конкретному юрлицу. Фильтр по
    // legal_entity_group (триггер БД автоматически проставляет колонку при INSERT).
    const groupCode = getEntityGroupCode(orderStore.settings.legalEntity)
    let q = db.from('supplier_payments').select('*').eq('legal_entity_group', groupCode).order('payment_date')
    if (statusFilter.value) q = q.eq('status', statusFilter.value)
    const { data } = await q
    payments.value = data || []
  } catch { payments.value = [] }
  finally { loading.value = false }
}

function startEdit(p) {
  editId.value = p.id
  editAmount.value = p.amount || ''
  editNote.value = p.note || ''
  editPayDate.value = p.payment_date || ''
}

async function saveEdit(p) {
  try {
    const updates = { id: p.id, amount: editAmount.value || null, note: editNote.value || null }
    if (editPayDate.value && editPayDate.value !== p.payment_date) {
      updates.payment_date = editPayDate.value
      // Пересчитываем дедлайн: предыдущий день 15:00
      const payDt = new Date(editPayDate.value + 'T00:00:00')
      payDt.setDate(payDt.getDate() - 1)
      const y = payDt.getFullYear(), m = String(payDt.getMonth()+1).padStart(2,'0'), d = String(payDt.getDate()).padStart(2,'0')
      updates.request_deadline = `${y}-${m}-${d} 15:00:00`
    }
    await db.rpc('update_payment', updates)
    editId.value = null
    toast.show('Сохранено')
    await load()
  } catch (e) { toast.error('Ошибка', e.message || e) }
}

async function markRequested(p) {
  try {
    await db.rpc('update_payment', { id: p.id, status: 'requested' })
    toast.show('Заявка подана')
    await load()
  } catch (e) { toast.error('Ошибка', e.message || e) }
}

async function markPaid(p) {
  try {
    await db.rpc('update_payment', { id: p.id, status: 'paid' })
    toast.show('Оплачено')
    await load()
  } catch (e) { toast.error('Ошибка', e.message || e) }
}

async function cancel(p) {
  try {
    await db.rpc('update_payment', { id: p.id, status: 'cancelled' })
    toast.show('Отменено')
    await load()
  } catch (e) { toast.error('Ошибка', e.message || e) }
}

function goToOrder(p) {
  if (p.order_id) router.push({ name: 'order', query: { orderId: p.order_id, mode: 'view' } })
}

function shortLE(le) {
  if (!le) return ''
  if (le.includes('Бургер')) return 'БК'
  if (le.includes('Воглия')) return 'ВМ'
  if (le.includes('Пицца')) return 'ПС'
  return le.slice(0, 10)
}

function statusLabel(s) {
  return { upcoming: 'Предстоит', request_due: 'Нужна заявка!', requested: 'Заявка подана', paid: 'Оплачено', cancelled: 'Отменено' }[s] || s
}
function fmtDate(d) {
  if (!d) return ''
  const dt = new Date(d + 'T00:00:00')
  const days = ['Вс','Пн','Вт','Ср','Чт','Пт','Сб']
  return days[dt.getDay()] + ' ' + dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
}
function fmtDateTime(d) {
  if (!d) return ''
  const dt = new Date(d)
  return dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' }) + ' ' + dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
}
function fmtAmount(v) { return Number(v).toLocaleString('ru-RU', { minimumFractionDigits: 0 }) }

onMounted(load)
watch(() => orderStore.settings.legalEntity, () => load())
</script>

<style scoped>
.pay { padding: 16px 24px; }
.pay-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
.pay-filters { display: flex; gap: 8px; }
.pay-input { padding: 5px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 13px; }
.pay-empty { text-align: center; padding: 40px; color: var(--text-muted); }
.pay-table-wrap { overflow-x: auto; }
.pay-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.pay-table th { text-align: left; padding: 8px 8px; border-bottom: 2px solid var(--border); font-weight: 600; color: var(--text-muted); font-size: 12px; white-space: nowrap; }
.pay-table td { text-align: left; padding: 8px 8px; border-bottom: 1px solid var(--border-light); vertical-align: middle; white-space: nowrap; }
.pay-center { text-align: center; }
.pay-le { font-size: 12px; color: var(--text-muted); }
.pay-link { color: var(--bk-brown); cursor: pointer; font-weight: 600; text-decoration: none; }
.pay-link:hover { text-decoration: underline; }
.pay-row-upcoming td { }
.pay-row-request_due td { background: #FFF3E0; }
.pay-row-requested td { background: #E3F2FD; }
.pay-row-paid td { opacity: 0.6; }
.pay-row-cancelled td { opacity: 0.4; text-decoration: line-through; }
.pay-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.pay-badge.upcoming { background: #E3F2FD; color: #1565C0; }
.pay-badge.request_due { background: #FFF3E0; color: #E65100; }
.pay-badge.requested { background: #E8F5E9; color: #2E7D32; }
.pay-badge.paid { background: #ECEFF1; color: #546E7A; }
.pay-badge.cancelled { background: #ECEFF1; color: #9E9E9E; }
.pay-note { font-size: 12px; color: var(--text-muted); }
.pay-amount-input { width: 100px; padding: 3px 6px; border: 1px solid var(--border); border-radius: 4px; font-size: 13px; }
.pay-date-input { padding: 3px 6px; border: 1px solid var(--border); border-radius: 4px; font-size: 13px; }
.pay-note-input { width: 150px; padding: 3px 6px; border: 1px solid var(--border); border-radius: 4px; font-size: 12px; }
.pay-actions { display: flex; gap: 4px; }
.pay-btn { width: 28px; height: 28px; border: 1px solid var(--border); border-radius: 6px; cursor: pointer; font-size: 13px; background: #fff; }
.pay-btn:hover { background: var(--bk-cream); }
.pay-btn.approve { background: #E8F5E9; border-color: #4CAF50; }
.pay-btn.requested { background: #E3F2FD; border-color: #1565C0; }
.pay-btn.save { background: #E3F2FD; border-color: #1565C0; }

/* Мобильная адаптация */
@media (max-width: 700px) {
  .pay { padding: 12px 10px; }
  .pay-top { flex-direction: column; align-items: flex-start; }
  .pay-input { font-size: 14px; width: 100%; }

  .pay-table-wrap { overflow-x: visible; }
  .pay-table { display: block; }
  .pay-table thead { display: none; }
  .pay-table tbody { display: flex; flex-direction: column; gap: 10px; }
  .pay-table tr {
    display: flex;
    flex-direction: column;
    background: var(--card, #fff);
    border: 1px solid var(--border-light);
    border-radius: 10px;
    padding: 12px;
    gap: 4px;
  }
  .pay-table td {
    display: block;
    padding: 2px 0;
    border-bottom: none;
    white-space: normal;
  }

  /* Подписи перед значениями */
  .pay-table td:nth-child(1) { font-size: 15px; font-weight: 600; }
  .pay-table td:nth-child(2)::before { content: 'Юрлицо: '; font-weight: 600; color: var(--text-muted); font-size: 12px; }
  .pay-table td:nth-child(3)::before { content: 'Приход: '; font-weight: 600; color: var(--text-muted); font-size: 12px; }
  .pay-table td:nth-child(5)::before { content: 'Оплата: '; font-weight: 600; color: var(--text-muted); font-size: 12px; }
  .pay-table td:nth-child(7)::before { content: 'Сумма: '; font-weight: 600; color: var(--text-muted); font-size: 12px; }

  /* Скрываем менее важные колонки: отсрочка, дедлайн заявки, комментарий */
  .pay-table td:nth-child(4),
  .pay-table td:nth-child(6),
  .pay-table td:nth-child(9) { display: none; }

  /* Статус наверх */
  .pay-table td:nth-child(8) { order: -1; }

  /* Кнопки */
  .pay-actions { justify-content: flex-end; margin-top: 4px; }

  .pay-amount-input,
  .pay-note-input,
  .pay-date-input { width: 100%; box-sizing: border-box; }
}
</style>
