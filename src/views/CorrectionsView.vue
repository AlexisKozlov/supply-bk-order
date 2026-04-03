<template>
  <div class="corr">
    <div class="corr-top">
      <h1 class="page-title">Корректировки заказов</h1>
      <div class="corr-top-actions">
        <button class="corr-tab" :class="{ active: tab === 'requests' }" @click="tab = 'requests'">Заявки</button>
        <button class="corr-tab" :class="{ active: tab === 'settings' }" @click="tab = 'settings'; loadSettings()">Настройки</button>
      </div>
    </div>

    <!-- Заявки -->
    <template v-if="tab === 'requests'">
      <div class="corr-toolbar">
        <select v-model="statusFilter" class="corr-input" @change="loadCorrections">
          <option value="">Все статусы</option>
          <option value="pending">Ожидают</option>
          <option value="approved">Приняты</option>
          <option value="rejected">Отклонены</option>
        </select>
        <input v-model="restFilter" class="corr-input" placeholder="Ресторан..." style="width:90px;" @input="debounceLoad"/>
        <span v-if="pendingCount" class="corr-pending-badge">{{ pendingCount }} ожидают</span>
        <div style="margin-left:auto;">
          <button v-if="corrections.length" class="corr-btn-text danger" @click="clearAll">Очистить всё</button>
        </div>
      </div>

      <div v-if="loading" class="corr-empty">Загрузка...</div>
      <div v-else-if="!groupedCorrections.length" class="corr-empty">Нет заявок</div>

      <div v-else class="corr-table-wrap">
        <table class="corr-table">
          <thead>
            <tr>
              <th class="col-rest">Рест.</th>
              <th class="col-date">Доставка</th>
              <th class="col-items">Позиции</th>
              <th class="col-comment">Комментарий</th>
              <th class="col-who">Подал</th>
              <th class="col-status">Статус</th>
              <th class="col-reviewer">Обработал</th>
              <th class="col-actions">Действия</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="g in groupedCorrections" :key="g.key" :class="'row-' + g.overallStatus">
              <td class="col-rest"><strong>{{ g.restaurant_number }}</strong></td>
              <td class="col-date">{{ g.dateLabel }}</td>
              <td class="col-items">
                <div v-for="c in g.items" :key="c.id" class="corr-item-line">
                  <span class="corr-status-icon">{{ {pending:'⏳',in_progress:'🔄',approved:'✅',rejected:'❌'}[c.status] }}</span>
                  <span :class="c.action === 'add' ? 'act-add' : 'act-rem'">{{ c.action === 'add' ? '+' : '−' }}</span>
                  <span v-if="c.product_sku && c.product_sku !== '-'" class="corr-sku">{{ c.product_sku }}</span>
                  <span>{{ c.product_name }}</span>
                  <strong>{{ fmtQty(c.quantity) }} {{ c.unit_of_measure }}</strong>
                  <template v-if="c.status === 'pending'">
                    <button class="corr-item-btn approve" @click.stop="reviewBatch([c.id], 'approve')" title="Принять">✓</button>
                    <button class="corr-item-btn reject" @click.stop="openReject([c.id])" title="Отклонить">✕</button>
                  </template>
                  <span v-else-if="c.reviewer_name" class="corr-item-reviewer">{{ c.reviewer_name }}</span>
                </div>
              </td>
              <td class="col-comment">
                <div v-for="c in g.items" :key="'cm'+c.id" class="corr-comment-line">
                  <span v-if="c.comment" class="corr-comment-text" :title="c.comment">{{ c.comment }}</span>
                  <span v-if="c.review_comment" class="corr-review-text">{{ c.review_comment }}</span>
                </div>
              </td>
              <td class="col-who">
                <div class="corr-meta">{{ g.submitter || '—' }}</div>
                <div class="corr-meta-sub">{{ fmtDateTime(g.created_at) }}</div>
              </td>
              <td class="col-status">
                <span class="corr-badge" :class="g.overallStatus">{{ statusLabel(g.overallStatus) }}</span>
              </td>
              <td class="col-reviewer">
                <span v-if="g.reviewer" class="corr-meta">{{ g.reviewer }}</span>
              </td>
              <td class="col-actions">
                <div class="corr-action-btns">
                  <template v-if="g.hasPending">
                    <button class="corr-btn approve" @click="reviewBatch(g.pendingIds, 'approve')" title="Принять всё">✓</button>
                    <button class="corr-btn reject" @click="openReject(g.pendingIds)" title="Отклонить всё">✕</button>
                  </template>
                  <button class="corr-btn delete" @click="deleteGroup(g)" title="Удалить">🗑</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Модалка отклонения -->
    <Teleport to="body">
      <div v-if="rejectModal.show" class="modal">
        <div class="modal-box" style="max-width:400px;">
          <h3 style="margin-bottom:12px;">Отклонить заявку</h3>
          <textarea v-model="rejectModal.comment" class="corr-textarea" placeholder="Комментарий (необязательно)" rows="3"></textarea>
          <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;">
            <button class="btn" @click="rejectModal.show = false">Отмена</button>
            <button class="btn primary" @click="submitReject">Отклонить</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Настройки -->
    <template v-if="tab === 'settings'">
      <div class="corr-settings">
        <h3 class="corr-section-title">Кто получает уведомления о корректировках</h3>
        <p class="corr-hint">Отмеченные пользователи будут получать заявки в Telegram-бот с возможностью принять или отклонить.</p>
        <div v-if="settingsLoading" class="corr-empty">Загрузка...</div>
        <div v-else-if="!settingsUsers.length" class="corr-empty">Нет привязанных пользователей</div>
        <div v-else class="corr-settings-list">
          <div v-for="u in settingsUsers" :key="u.name" class="corr-settings-row" @click="toggleNotification(u)">
            <span class="corr-toggle">{{ u.correction_notifications ? '✅' : '⬜' }}</span>
            <span>{{ u.name }}</span>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { db } from '@/lib/apiClient.js'
import { useToastStore } from '@/stores/toastStore.js'

const toastStore = useToastStore()
const tab = ref('requests')
const loading = ref(false)
const corrections = ref([])
const statusFilter = ref('')
const restFilter = ref('')
const settingsLoading = ref(false)
const settingsUsers = ref([])
const rejectModal = ref({ show: false, ids: [], comment: '' })

const pendingCount = computed(() => corrections.value.filter(c => c.status === 'pending').length)

// Группируем позиции в заявки: по ресторану + дате + submitter + близкое время
const groupedCorrections = computed(() => {
  const groups = {}
  for (const c of corrections.value) {
    // Ключ: ресторан + дата + подавший (одна заявка = одна пачка)
    const key = `${c.restaurant_number}_${c.delivery_date}_${c.restaurant_chat_id}`
    if (!groups[key]) {
      groups[key] = {
        key,
        restaurant_number: c.restaurant_number,
        delivery_date: c.delivery_date,
        dateLabel: fmtDate(c.delivery_date),
        submitter: c.submitter_name,
        created_at: c.created_at,
        items: [],
        pendingIds: [],
        reviewer: null,
      }
    }
    groups[key].items.push(c)
    if (c.status === 'pending') groups[key].pendingIds.push(c.id)
    if (c.reviewer_name && !groups[key].reviewer) groups[key].reviewer = c.reviewer_name
  }
  // Определяем общий статус
  for (const g of Object.values(groups)) {
    const statuses = new Set(g.items.map(i => i.status))
    if (statuses.has('pending')) g.overallStatus = 'pending'
    else if (statuses.size === 1) g.overallStatus = [...statuses][0]
    else g.overallStatus = 'mixed'
    g.hasPending = g.pendingIds.length > 0
  }
  return Object.values(groups).sort((a, b) => (b.created_at || '').localeCompare(a.created_at || ''))
})

let loadTimer = null
function debounceLoad() { clearTimeout(loadTimer); loadTimer = setTimeout(loadCorrections, 300) }

async function loadCorrections() {
  loading.value = true
  try {
    let query = db.from('order_corrections').select('*').order('created_at', { ascending: false }).limit(500)
    if (statusFilter.value) query = query.eq('status', statusFilter.value)
    if (restFilter.value.trim()) query = query.eq('restaurant_number', restFilter.value.trim())
    const { data } = await query
    corrections.value = data || []
  } catch { corrections.value = [] }
  finally { loading.value = false }
}

async function reviewBatch(ids, action, comment = '') {
  try {
    if (ids.length === 1) {
      await db.rpc('correction_review', { id: ids[0], action, comment })
    } else {
      await db.rpc('correction_review_batch', { ids, action, comment })
    }
    toastStore.show(action === 'approve' ? 'Принято' : 'Отклонено')
    await loadCorrections()
  } catch (e) { toastStore.show('Ошибка: ' + (e.message || e), 'error') }
}

function openReject(ids) { rejectModal.value = { show: true, ids, comment: '' } }
async function submitReject() {
  await reviewBatch(rejectModal.value.ids, 'reject', rejectModal.value.comment)
  rejectModal.value.show = false
}

async function deleteGroup(g) {
  const ids = g.items.map(i => i.id)
  if (!confirm(`Удалить заявку (${ids.length} поз.) от рест. ${g.restaurant_number}?`)) return
  try {
    await db.rpc('correction_delete', { ids })
    toastStore.show('Удалено')
    await loadCorrections()
  } catch (e) { toastStore.show('Ошибка: ' + (e.message || e), 'error') }
}

async function clearAll() {
  const pending = corrections.value.filter(c => c.status === 'pending' || c.status === 'in_progress').length
  const processed = corrections.value.filter(c => c.status === 'approved' || c.status === 'rejected').length
  const msg = `Будет удалено ${processed} обработанных заявок.\n${pending} необработанных останутся.\n\nПродолжить?`
  if (!confirm(msg)) return
  if (!confirm('Точно удалить? Это действие необратимо.')) return
  try {
    await db.rpc('correction_clear_processed')
    toastStore.show('Обработанные заявки удалены')
    await loadCorrections()
  } catch (e) { toastStore.show('Ошибка: ' + (e.message || e), 'error') }
}

async function loadSettings() {
  settingsLoading.value = true
  try {
    const { data } = await db.rpc('correction_get_settings')
    settingsUsers.value = data || []
  } catch { settingsUsers.value = [] }
  finally { settingsLoading.value = false }
}

async function toggleNotification(user) {
  try {
    await db.rpc('correction_toggle_notification', { user_name: user.name })
    user.correction_notifications = user.correction_notifications ? 0 : 1
  } catch { toastStore.show('Ошибка', 'error') }
}

function statusLabel(s) { return { pending: 'Ожидает', in_progress: 'В работе', approved: 'Принята', rejected: 'Отклонена', mixed: 'Частично' }[s] || s }

function fmtDate(d) {
  if (!d) return ''
  const dt = new Date(d + (d.includes('T') ? '' : 'T00:00:00'))
  const days = ['Вс','Пн','Вт','Ср','Чт','Пт','Сб']
  return days[dt.getDay()] + ' ' + dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' })
}

function fmtDateTime(d) {
  if (!d) return ''
  const dt = new Date(d)
  return dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' }) + ' ' +
         dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
}

function fmtQty(q) { const n = parseFloat(q); return n % 1 === 0 ? n.toFixed(0) : n.toFixed(1) }

onMounted(loadCorrections)
</script>

<style scoped>
.corr { padding: 16px 24px; }
.corr-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.corr-top-actions { display: flex; gap: 0; border-bottom: 2px solid var(--border-light); }
.corr-tab { padding: 8px 18px; font-size: 13px; font-weight: 600; color: var(--text-muted); background: none; border: none; border-bottom: 2px solid transparent; margin-bottom: -2px; cursor: pointer; }
.corr-tab.active { color: var(--bk-brown); border-bottom-color: var(--bk-brown); }
.corr-toolbar { display: flex; gap: 8px; margin-bottom: 12px; align-items: center; }
.corr-input { padding: 5px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 13px; }
.corr-pending-badge { background: #FFF3E0; color: #E65100; padding: 3px 10px; border-radius: 10px; font-size: 12px; font-weight: 600; }
.corr-btn-text { background: none; border: none; cursor: pointer; font-size: 12px; font-weight: 600; padding: 4px 8px; border-radius: 4px; }
.corr-btn-text.danger { color: #F44336; }
.corr-btn-text.danger:hover { background: #FFEBEE; }
.corr-empty { text-align: center; padding: 40px 0; color: var(--text-muted); }

/* Таблица */
.corr-table-wrap { overflow-x: auto; }
.corr-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.corr-table th { text-align: left; padding: 8px 10px; border-bottom: 2px solid var(--border); font-weight: 600; color: var(--text-muted); font-size: 12px; white-space: nowrap; }
.corr-table td { padding: 10px 10px; border-bottom: 1px solid var(--border-light); vertical-align: top; }
.col-rest { width: 60px; }
.col-date { width: 90px; white-space: nowrap; }
.col-items { min-width: 250px; }
.col-comment { min-width: 140px; }
.col-who { width: 120px; }
.col-status { width: 80px; }
.col-reviewer { width: 100px; }
.col-actions { width: 70px; }
.row-pending td { background: #FFFDE7; }
.row-mixed td { background: #FFF8E1; }

/* Позиции внутри заявки */
.corr-item-line { display: flex; align-items: baseline; gap: 4px; padding: 2px 0; flex-wrap: wrap; }
.corr-status-icon { font-size: 12px; }
.act-add { color: #2E7D32; font-weight: 700; }
.act-rem { color: #C62828; font-weight: 700; }
.corr-sku { color: var(--text-muted); font-size: 11px; }
.corr-item-btn { width: 22px; height: 22px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 700; color: #fff; margin-left: 4px; flex-shrink: 0; }
.corr-item-btn.approve { background: #4CAF50; }
.corr-item-btn.approve:hover { background: #388E3C; }
.corr-item-btn.reject { background: #F44336; }
.corr-item-btn.reject:hover { background: #D32F2F; }
.corr-item-reviewer { font-size: 11px; color: var(--text-muted); margin-left: 4px; }
.corr-comment-line { padding: 2px 0; min-height: 20px; }
.corr-comment-text { font-size: 12px; color: var(--text); }
.corr-review-text { font-size: 12px; color: var(--bk-brown); font-style: italic; }
.corr-meta { font-size: 12px; }
.corr-meta-sub { font-size: 11px; color: var(--text-muted); }
.corr-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
.corr-badge.pending { background: #FFF3E0; color: #E65100; }
.corr-badge.in_progress { background: #E3F2FD; color: #1565C0; }
.corr-badge.approved { background: #E8F5E9; color: #2E7D32; }
.corr-badge.rejected { background: #FFEBEE; color: #C62828; }
.corr-badge.mixed { background: #E3F2FD; color: #1565C0; }

.corr-action-btns { display: flex; gap: 4px; }
.corr-btn { width: 30px; height: 30px; border: none; border-radius: 6px; cursor: pointer; font-weight: 700; font-size: 15px; color: #fff; }
.corr-btn.approve { background: #4CAF50; }
.corr-btn.approve:hover { background: #388E3C; }
.corr-btn.reject { background: #F44336; }
.corr-btn.reject:hover { background: #D32F2F; }
.corr-btn.delete { background: none; border: 1px solid var(--border); color: var(--text-muted); font-size: 13px; }
.corr-btn.delete:hover { background: #FFEBEE; color: #F44336; border-color: #F44336; }

/* Настройки */
.corr-section-title { font-size: 16px; margin-bottom: 4px; }
.corr-hint { font-size: 13px; color: var(--text-muted); margin-bottom: 12px; }
.corr-settings-list { display: flex; flex-direction: column; gap: 2px; max-width: 400px; }
.corr-settings-row { display: flex; align-items: center; gap: 10px; padding: 8px 12px; cursor: pointer; border-radius: 8px; }
.corr-settings-row:hover { background: var(--bk-cream); }
.corr-toggle { font-size: 16px; }
.corr-textarea { width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 13px; font-family: inherit; resize: vertical; box-sizing: border-box; }

/* Мобильная адаптация */
@media (max-width: 700px) {
  .corr { padding: 12px 10px; }
  .corr-top { flex-direction: column; align-items: flex-start; gap: 8px; }
  .corr-toolbar { flex-wrap: wrap; }
  .corr-input { font-size: 14px; }

  .corr-table-wrap { overflow-x: visible; }
  .corr-table { display: block; }
  .corr-table thead { display: none; }
  .corr-table tbody { display: flex; flex-direction: column; gap: 10px; }
  .corr-table tr {
    display: flex;
    flex-direction: column;
    background: var(--card, #fff);
    border: 1px solid var(--border-light);
    border-radius: 10px;
    padding: 12px;
    gap: 6px;
  }
  .corr-table td {
    display: block;
    padding: 0;
    border-bottom: none;
  }
  /* Скрываем менее важные колонки */
  .col-comment,
  .col-reviewer { display: none; }

  .col-rest { font-size: 15px; }
  .col-rest::before { content: 'Рест. '; font-weight: 400; color: var(--text-muted); font-size: 12px; }
  .col-date::before { content: 'Доставка: '; font-weight: 600; color: var(--text-muted); font-size: 12px; }
  .col-who::before { content: 'Подал: '; font-weight: 600; color: var(--text-muted); font-size: 12px; }
  .col-status { order: -1; }
  .col-actions { margin-top: 4px; }
  .corr-action-btns { justify-content: flex-end; }

  .corr-item-line { font-size: 13px; }
  .corr-settings { padding: 0; }
  .corr-settings-list { max-width: 100%; }
}
</style>
