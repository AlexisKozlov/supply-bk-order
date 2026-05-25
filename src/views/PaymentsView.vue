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
        <button class="btn primary pay-add-btn" @click="openAddModal">+ Добавить вручную</button>
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

    <!-- Модалка ручного создания оплаты -->
    <div v-if="addModal.show" class="pay-modal-backdrop" @click.self="closeAddModal">
      <div class="pay-modal">
        <h2 class="pay-modal-title">Добавить оплату вручную</h2>
        <p class="pay-modal-hint">Если заказ не проходил через портал. Срок оплаты и дедлайн заявки рассчитаются автоматически по отсрочке из карточки поставщика.</p>
        <div class="pay-modal-row">
          <label>Юрлицо <span class="req">*</span></label>
          <select v-model="addModal.legalEntity" class="pay-input">
            <option v-for="le in legalEntityOptions" :key="le" :value="le">{{ le }}</option>
          </select>
        </div>
        <div class="pay-modal-row">
          <label>Поставщик <span class="req">*</span></label>
          <input v-model="addModal.supplierQuery" class="pay-input" placeholder="Поиск по названию" @input="onSupplierInput" />
          <div v-if="addModal.supplierMatches.length && !addModal.supplier" class="pay-supplier-suggest">
            <button v-for="s in addModal.supplierMatches" :key="s.short_name" @click="pickSupplier(s)">
              {{ s.short_name }}<span v-if="s.payment_delay_days"> · отсрочка {{ s.payment_delay_days }} дн.</span>
            </button>
          </div>
          <div v-if="addModal.supplier" class="pay-supplier-picked">
            ✓ {{ addModal.supplier }}
            <button class="pay-supplier-clear" @click="clearSupplier" title="Сбросить">✕</button>
          </div>
        </div>
        <div class="pay-modal-row">
          <label>Дата прихода <span class="req">*</span></label>
          <input type="date" v-model="addModal.deliveryDate" class="pay-input" />
        </div>
        <div class="pay-modal-row">
          <label>Сумма (необязательно)</label>
          <input type="number" v-model="addModal.amount" class="pay-input" placeholder="0" min="0" step="0.01" />
        </div>
        <div class="pay-modal-row">
          <label>Комментарий (необязательно)</label>
          <input v-model="addModal.note" class="pay-input" placeholder="Например: счёт 123 от 24.05" maxlength="255" />
        </div>
        <div v-if="addModal.error" class="pay-modal-error">{{ addModal.error }}</div>
        <div class="pay-modal-actions">
          <button class="btn" @click="closeAddModal" :disabled="addModal.saving">Отмена</button>
          <button class="btn primary" @click="submitAddModal" :disabled="addModal.saving || !canSubmitAdd">
            {{ addModal.saving ? 'Сохраняю…' : 'Создать' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { db } from '@/lib/apiClient.js'
import { useOrderStore } from '@/stores/orderStore.js'
import { useToastStore } from '@/stores/toastStore.js'
import { getEntityGroup, getEntityGroupCode } from '@/lib/legalEntities.js'

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

// ─── Ручное создание оплаты ───────────────────────────────────────────────
const addModal = ref({
  show: false,
  legalEntity: '',
  supplier: '',
  supplierQuery: '',
  supplierMatches: [],
  deliveryDate: '',
  amount: '',
  note: '',
  saving: false,
  error: '',
})

const legalEntityOptions = computed(() => getEntityGroup(orderStore.settings.legalEntity))
const canSubmitAdd = computed(() =>
  addModal.value.legalEntity && addModal.value.supplier && addModal.value.deliveryDate
)

function openAddModal() {
  const today = new Date().toISOString().slice(0, 10)
  addModal.value = {
    show: true,
    legalEntity: orderStore.settings.legalEntity,
    supplier: '',
    supplierQuery: '',
    supplierMatches: [],
    deliveryDate: today,
    amount: '',
    note: '',
    saving: false,
    error: '',
  }
}
function closeAddModal() {
  if (addModal.value.saving) return
  addModal.value.show = false
}
function clearSupplier() {
  addModal.value.supplier = ''
  addModal.value.supplierQuery = ''
  addModal.value.supplierMatches = []
}

let _supplierSearchTimer = null
function onSupplierInput() {
  addModal.value.supplier = ''
  if (_supplierSearchTimer) clearTimeout(_supplierSearchTimer)
  const q = addModal.value.supplierQuery.trim()
  if (q.length < 2) { addModal.value.supplierMatches = []; return }
  _supplierSearchTimer = setTimeout(async () => {
    try {
      const group = getEntityGroupCode(addModal.value.legalEntity)
      const { data } = await db.from('suppliers')
        .select('short_name,payment_delay_days,country')
        .eq('legal_entity_group', group)
        .eq('is_active', 1)
        .ilike('short_name', `%${q}%`)
        .limit(15)
      addModal.value.supplierMatches = data || []
    } catch {
      addModal.value.supplierMatches = []
    }
  }, 250)
}
function pickSupplier(s) {
  addModal.value.supplier = s.short_name
  addModal.value.supplierQuery = s.short_name
  addModal.value.supplierMatches = []
}

async function submitAddModal() {
  if (!canSubmitAdd.value || addModal.value.saving) return
  addModal.value.saving = true
  addModal.value.error = ''
  try {
    const { data, error } = await db.rpc('create_manual_payment', {
      supplier: addModal.value.supplier,
      legal_entity: addModal.value.legalEntity,
      delivery_date: addModal.value.deliveryDate,
      amount: addModal.value.amount || null,
      note: addModal.value.note || null,
    })
    if (error) throw new Error(error)
    if (data?.error) throw new Error(data.error)
    toast.show('Оплата создана', `Срок: ${data.payment_date || ''}`)
    addModal.value.show = false
    await load()
  } catch (e) {
    addModal.value.error = e?.message || 'Не удалось создать'
  } finally {
    addModal.value.saving = false
  }
}
</script>

<style scoped>
.pay { padding: 16px 24px; }
.pay-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
.pay-filters { display: flex; gap: 8px; }
.pay-input { padding: 5px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 13px; }
.pay-empty { text-align: center; padding: 40px; color: var(--text-muted); }
.pay-table-wrap { overflow-x: auto; }
.pay-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.pay-table th {
  text-align: left; padding: 10px 10px;
  background: #FAF5EF;
  border-bottom: 2px solid #C8A27C;
  font-weight: 700; color: #502314;
  font-size: 11px; letter-spacing: 0.4px; text-transform: uppercase;
  white-space: nowrap;
}
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

/* Кнопка «+ Добавить вручную» в фильтрах */
.pay-add-btn { padding: 6px 14px; font-size: 13px; }

/* Модалка ручного создания оплаты */
.pay-modal-backdrop {
  position: fixed; inset: 0; background: rgba(0,0,0,0.5);
  display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 20px;
}
.pay-modal {
  background: #fff; border-radius: 12px; padding: 24px;
  width: 460px; max-width: 92vw; max-height: 88vh; overflow-y: auto;
  box-shadow: 0 12px 32px rgba(0,0,0,0.2);
}
.pay-modal-title { margin: 0 0 6px; font-size: 18px; font-weight: 700; color: #502314; }
.pay-modal-hint { margin: 0 0 18px; font-size: 12px; color: var(--text-muted); line-height: 1.45; }
.pay-modal-row { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; position: relative; }
.pay-modal-row label { font-size: 12px; color: #502314; font-weight: 600; }
.pay-modal-row .req { color: #c0392b; }
.pay-supplier-suggest {
  position: absolute; top: 100%; left: 0; right: 0; z-index: 10;
  background: #fff; border: 1px solid var(--border); border-radius: 6px;
  max-height: 220px; overflow-y: auto; margin-top: 2px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.pay-supplier-suggest button {
  display: block; width: 100%; text-align: left;
  padding: 8px 12px; border: 0; background: transparent;
  font: inherit; cursor: pointer; font-size: 13px;
}
.pay-supplier-suggest button:hover { background: #FAF5EF; }
.pay-supplier-picked {
  display: flex; align-items: center; gap: 6px;
  padding: 6px 10px; background: #FAF5EF; border-radius: 6px;
  color: #2e7d32; font-size: 13px; font-weight: 600;
  margin-top: 2px;
}
.pay-supplier-clear {
  margin-left: auto; background: transparent; border: 0; cursor: pointer;
  color: var(--text-muted); font-size: 14px;
}
.pay-modal-error { color: #c0392b; font-size: 13px; margin-bottom: 12px; }
.pay-modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 4px; }
</style>
