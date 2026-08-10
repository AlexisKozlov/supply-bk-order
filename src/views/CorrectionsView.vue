<template>
  <div class="corr">
    <div class="corr-top">
      <h1 class="page-title">Корректировки заказов</h1>
      <div class="corr-seg corr-seg-tabs">
        <button class="corr-seg-btn" :class="{ active: tab === 'requests' }" @click="tab = 'requests'">Заявки</button>
        <button class="corr-seg-btn" :class="{ active: tab === 'settings' }" @click="tab = 'settings'; loadSettings()">Настройки</button>
      </div>
    </div>

    <!-- Заявки -->
    <template v-if="tab === 'requests'">
      <div class="corr-toolbar">
        <!-- Статусы вкладками: по ним фильтруют чаще всего, выпадающий
             список ради двух кликов здесь только мешал. -->
        <div class="corr-seg">
          <button v-for="f in STATUS_FILTERS" :key="f.value"
                  class="corr-seg-btn" :class="{ active: statusFilter === f.value }"
                  @click="statusFilter = f.value; loadCorrections()">
            {{ f.label }}
            <span v-if="f.value === 'pending' && pendingCount" class="corr-seg-count">{{ pendingCount }}</span>
          </button>
        </div>
        <select v-model="sourceFilter" class="corr-input corr-input-sm" @change="loadCorrections">
          <option value="">Откуда угодно</option>
          <option value="cabinet">Из кабинета</option>
          <option value="telegram">Из бота</option>
        </select>
        <input v-model="restFilter" class="corr-input corr-input-sm" placeholder="Ресторан" style="width:110px;" @input="debounceLoad"/>
        <button v-if="corrections.length" class="corr-btn-text danger corr-clear" @click="clearAll">Очистить всё</button>
      </div>

      <div v-if="loading" class="corr-empty"><BurgerSpinner text="Загрузка..." /></div>
      <div v-else-if="!groupedCorrections.length" class="corr-empty">
        <div class="corr-empty-title">Заявок нет</div>
        <p>Здесь появятся корректировки, которые рестораны подают из кабинета и из бота.</p>
      </div>

      <div v-else class="corr-cards">
        <article v-for="g in groupedCorrections" :key="g.key" class="corr-card" :class="'st-' + g.overallStatus">
          <header class="corr-card-head">
            <div class="corr-card-rest">
              <span class="corr-card-num">{{ restLabel(g.restaurant_number) }}</span>
              <span class="corr-card-date">{{ g.dateLabel }}</span>
            </div>
            <div class="corr-card-head-right">
              <span class="corr-badge" :class="g.overallStatus">{{ statusLabel(g.overallStatus) }}</span>
              <button class="corr-card-del" title="Удалить заявку" @click="deleteGroup(g)">×</button>
            </div>
          </header>

          <div class="corr-card-items">
            <div v-for="c in g.items" :key="c.id" class="corr-line" :class="'st-' + c.status">
              <span class="corr-line-act" :class="c.action">{{ c.action === 'add' ? '+' : '−' }}</span>
              <span v-if="c.product_sku && c.product_sku !== '-'" class="corr-line-sku">{{ c.product_sku }}</span>
              <span class="corr-line-name">{{ c.product_name }}</span>
              <span class="corr-line-qty">{{ fmtQty(c.quantity) }} {{ c.unit_of_measure }}</span>
              <span class="corr-line-state" :class="c.status">{{ shortStatus(c.status) }}</span>
              <span class="corr-line-btns">
                <template v-if="c.status === 'pending' || c.status === 'in_progress'">
                  <button class="corr-line-btn ok" @click.stop="reviewBatch([c.id], 'approve')" title="Принять позицию">✓</button>
                  <button class="corr-line-btn no" @click.stop="openReview([c.id], 'reject')" title="Отклонить позицию">✕</button>
                </template>
              </span>
            </div>
          </div>

          <div v-if="g.submitterComment || anyReviewComment(g)" class="corr-card-notes">
            <p v-if="g.submitterComment" class="corr-note from-rest">
              <span class="corr-note-label">Ресторан:</span> {{ g.submitterComment }}
            </p>
            <p v-if="anyReviewComment(g)" class="corr-note from-us">
              <span class="corr-note-label">Ответ закупок:</span> {{ anyReviewComment(g) }}
            </p>
          </div>

          <footer class="corr-card-foot">
            <div class="corr-card-meta">
              <span class="corr-source" :class="'src-' + g.source">
                {{ g.source === 'cabinet' ? 'кабинет' : 'бот' }}
              </span>
              <span>{{ g.submitter || '—' }}</span>
              <span class="corr-dim">{{ fmtDateTime(g.created_at) }}</span>
              <span v-if="g.reviewer" class="corr-dim">· обработал {{ g.reviewer }}</span>
            </div>
            <div class="corr-card-actions">
              <button v-if="g.hasUntaken" class="corr-act take" @click="takeInWork(g.untakenIds)">Взять в работу</button>
              <template v-if="g.hasOpen">
                <button class="corr-act approve" @click="reviewBatch(g.openIds, 'approve')">Принять</button>
                <button class="corr-act comment" @click="openReview(g.openIds, 'approve')"
                        title="Принять и написать ресторану">С комментарием</button>
                <button class="corr-act reject" @click="openReview(g.openIds, 'reject')">Отклонить</button>
              </template>
            </div>
          </footer>
        </article>
      </div>
    </template>

    <!-- Решение по заявке: принять или отклонить, с комментарием ресторану -->
    <Teleport to="body">
      <div v-if="reviewModal.show" class="modal">
        <div class="modal-box" style="max-width:420px;">
          <h3 style="margin-bottom:12px;">
            {{ reviewModal.action === 'approve' ? 'Принять заявку' : 'Отклонить заявку' }}
          </h3>
          <p class="corr-hint" style="margin-bottom:8px;">
            Комментарий увидит ресторан в кабинете и в боте.
          </p>
          <textarea v-model="reviewModal.comment" class="corr-textarea"
                    placeholder="Комментарий (необязательно)" rows="3"></textarea>
          <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;">
            <button class="btn" @click="reviewModal.show = false">Отмена</button>
            <button class="btn primary" @click="submitReview">
              {{ reviewModal.action === 'approve' ? 'Принять' : 'Отклонить' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Настройки -->
    <template v-if="tab === 'settings'">
      <div class="corr-settings">
        <h3 class="corr-section-title">Дедлайн корректировок</h3>
        <p class="corr-hint">
          До этого времени в рабочий день перед поставкой рестораны могут подать корректировку.
          Позже даты пропадают у них из кабинета и из бота. У каждой группы юрлиц своё время.
        </p>
        <div v-for="g in deadlineGroups" :key="g.code" class="corr-deadline-row">
          <span class="corr-deadline-group">{{ g.label }}</span>
          <input v-model="deadlineTime[g.code]" type="time" class="corr-input corr-deadline-input" step="300" />
          <button class="btn primary"
                  :disabled="deadlineSaving === g.code || !deadlineTime[g.code] || deadlineTime[g.code] === deadlineSaved[g.code]"
                  @click="saveDeadline(g.code)">
            {{ deadlineSaving === g.code ? 'Сохраняем…' : 'Сохранить' }}
          </button>
          <span v-if="deadlineSaved[g.code]" class="corr-deadline-current">сейчас {{ deadlineSaved[g.code] }}</span>
        </div>

        <h3 class="corr-section-title corr-section-title-next">Кто получает уведомления о корректировках</h3>
        <p class="corr-hint">Отмеченные пользователи будут получать заявки в Telegram-бот с возможностью принять или отклонить.</p>
        <div v-if="settingsLoading" class="corr-empty"><BurgerSpinner text="Загрузка..." /></div>
        <div v-else-if="!settingsUsers.length" class="corr-empty">Нет привязанных пользователей</div>
        <div v-else class="corr-settings-list">
          <div v-for="u in settingsUsers" :key="u.name" class="corr-settings-row" @click="toggleNotification(u)">
            <span class="corr-toggle">{{ u.correction_notifications ? '<BkIcon name="success" size="sm" />' : '⬜' }}</span>
            <span>{{ u.name }}</span>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import BkIcon from '@/components/ui/BkIcon.vue';
import { useTabRoute } from '@/composables/useTabRoute.js'
import { db } from '@/lib/apiClient.js'
import { appConfirm } from '@/lib/appDialogs.js'
import { formatRestaurantNumber, getEntityGroupCode } from '@/lib/legalEntities.js'
import { useToastStore } from '@/stores/toastStore.js'
import { useOrderStore } from '@/stores/orderStore.js'
import { useUserStore } from '@/stores/userStore.js'

const orderStore = useOrderStore()

const toastStore = useToastStore()
const userStore = useUserStore()
const tab = useTabRoute('requests', ['requests', 'settings'])
const loading = ref(false)
const corrections = ref([])
const statusFilter = ref('')
const sourceFilter = ref('')
const restFilter = ref('')
const settingsLoading = ref(false)
const settingsUsers = ref([])
// Показываем только те группы юрлиц, к которым у сотрудника есть доступ:
// закупщик «Пицца Стар» не должен менять время «Бургер БК».
const deadlineGroups = computed(() => {
  const all = [
    { code: 'BK_VM', label: 'Бургер БК и Воглия Матта' },
    { code: 'PS', label: 'Пицца Стар' },
  ]
  const allowed = new Set((userStore.getAllowedEntities?.() || []).map(getEntityGroupCode))
  const mine = all.filter(g => allowed.has(g.code))
  return mine.length ? mine : all
})

// Дедлайн свой у каждой группы юрлиц: { BK_VM: '10:00', PS: '11:00' }
const deadlineTime = ref({})      // что сейчас в полях
const deadlineSaved = ref({})     // что сохранено на сервере
const deadlineSaving = ref('')    // код группы, которая сейчас сохраняется
const reviewModal = ref({ show: false, ids: [], action: 'reject', comment: '' })

const STATUS_FILTERS = [
  { value: '', label: 'Все' },
  { value: 'pending', label: 'Ожидают' },
  { value: 'in_progress', label: 'В работе' },
  { value: 'approved', label: 'Приняты' },
  { value: 'rejected', label: 'Отклонены' },
]

const pendingCount = computed(() => corrections.value.filter(c => c.status === 'pending').length)

// Группируем позиции в заявки.
// Для кабинетных корректировок ключ — batch_uuid (поле есть всегда, source='cabinet').
// Для телеграмовских — старая логика: ресторан + дата + чат подавшего.
const groupedCorrections = computed(() => {
  const groups = {}
  for (const c of corrections.value) {
    const key = c.batch_uuid
      ? `uuid_${c.batch_uuid}`
      : `${c.restaurant_number}_${c.delivery_date}_${c.restaurant_chat_id}`
    if (!groups[key]) {
      groups[key] = {
        key,
        restaurant_number: c.restaurant_number,
        legal_entity_group: c.legal_entity_group,
        delivery_date: c.delivery_date,
        dateLabel: fmtDate(c.delivery_date),
        submitter: c.submitter_name,
        source: c.submitter_source || 'telegram',
        submitterComment: c.submitter_comment || '',
        created_at: c.created_at,
        items: [],
        pendingIds: [],
        untakenIds: [],  // только pending — для «Взять в работу»
        openIds: [],     // pending + in_progress — для approve/reject
        reviewer: null,
      }
    }
    groups[key].items.push(c)
    if (c.status === 'pending') {
      groups[key].pendingIds.push(c.id)
      groups[key].untakenIds.push(c.id)
      groups[key].openIds.push(c.id)
    } else if (c.status === 'in_progress') {
      groups[key].openIds.push(c.id)
    }
    if (c.reviewer_name && !groups[key].reviewer) groups[key].reviewer = c.reviewer_name
  }
  // Определяем общий статус
  for (const g of Object.values(groups)) {
    const statuses = new Set(g.items.map(i => i.status))
    if (statuses.has('pending')) g.overallStatus = 'pending'
    else if (statuses.has('in_progress')) g.overallStatus = 'in_progress'
    else if (statuses.size === 1) g.overallStatus = [...statuses][0]
    else g.overallStatus = 'mixed'
    g.hasPending = g.pendingIds.length > 0
    g.hasUntaken = g.untakenIds.length > 0
    g.hasOpen = g.openIds.length > 0
  }
  return Object.values(groups).sort((a, b) => (b.created_at || '').localeCompare(a.created_at || ''))
})

let loadTimer = null
function debounceLoad() { clearTimeout(loadTimer); loadTimer = setTimeout(loadCorrections, 300) }

async function loadCorrections() {
  loading.value = true
  try {
    const groupCode = getEntityGroupCode(orderStore.settings.legalEntity)
    let query = db.from('order_corrections').select('*').eq('legal_entity_group', groupCode).order('created_at', { ascending: false }).limit(500)
    if (statusFilter.value) query = query.eq('status', statusFilter.value)
    if (sourceFilter.value) query = query.eq('submitter_source', sourceFilter.value)
    if (restFilter.value.trim()) query = query.eq('restaurant_number', restFilter.value.trim())
    const { data } = await query
    corrections.value = data || []
  } catch { corrections.value = [] }
  finally { loading.value = false }
}

async function takeInWork(ids) {
  if (!ids || !ids.length) return
  try {
    await db.rpc('correction_take_batch', { ids })
    toastStore.show('Взято в работу')
    await loadCorrections()
  } catch (e) { toastStore.show('Ошибка: ' + (e.message || e), 'error') }
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

function openReview(ids, action) {
  reviewModal.value = { show: true, ids, action, comment: '' }
}
async function submitReview() {
  await reviewBatch(reviewModal.value.ids, reviewModal.value.action, reviewModal.value.comment)
  reviewModal.value.show = false
}

async function deleteGroup(g) {
  const ids = g.items.map(i => i.id)
  if (!(await appConfirm(`Удалить заявку (${ids.length} поз.) от рест. ${g.restaurant_number}?`, { okText: 'Удалить', danger: true }))) return
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
  if (!(await appConfirm(msg, { title: 'Очистить обработанные', okText: 'Удалить', danger: true }))) return
  if (!(await appConfirm('Точно удалить? Это действие необратимо.', { okText: 'Удалить', danger: true }))) return
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
  loadDeadline()
}

async function loadDeadline() {
  try {
    const { data } = await db.rpc('correction_get_deadline')
    const byGroup = data?.deadlines || {}
    const fallback = data?.deadline_time || ''
    const next = {}
    for (const g of deadlineGroups.value) next[g.code] = byGroup[g.code] || fallback
    deadlineTime.value = { ...next }
    deadlineSaved.value = { ...next }
  } catch { /* оставляем поля пустыми — сохранение всё равно проверит формат */ }
}

async function saveDeadline(group) {
  deadlineSaving.value = group
  try {
    const { data } = await db.rpc('correction_set_deadline', {
      deadline_time: deadlineTime.value[group],
      group,
    })
    const saved = data?.deadline_time || deadlineTime.value[group]
    deadlineSaved.value = { ...deadlineSaved.value, [group]: saved }
    deadlineTime.value = { ...deadlineTime.value, [group]: saved }
    const label = deadlineGroups.value.find(g => g.code === group)?.label || ''
    toastStore.show(`Дедлайн корректировок (${label}): ${saved}`)
  } catch (e) { toastStore.show('Ошибка: ' + (e.message || e), 'error') }
  finally { deadlineSaving.value = '' }
}

async function toggleNotification(user) {
  try {
    await db.rpc('correction_toggle_notification', { user_name: user.name })
    user.correction_notifications = user.correction_notifications ? 0 : 1
  } catch { toastStore.show('Ошибка', 'error') }
}

function statusLabel(s) { return { pending: 'Ожидает', in_progress: 'В работе', approved: 'Принята', rejected: 'Отклонена', cancelled: 'Отменена', mixed: 'Частично' }[s] || s }

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

// Номер ресторана в привычном виде: у «Пицца Стар» это PS01…PS52.
function restLabel(num) { return formatRestaurantNumber(num) }

// Короткая пометка у позиции: у заявки статус общий, а решения бывают
// разными по каждой строке.
function shortStatus(s) {
  return { pending: '', in_progress: 'в работе', approved: 'принято', rejected: 'отклонено', cancelled: 'отменено' }[s] || ''
}

// Ответ закупок мог быть записан не во все позиции — берём первый непустой.
function anyReviewComment(g) {
  const found = g.items.find(i => i.review_comment && String(i.review_comment).trim())
  return found ? found.review_comment : ''
}

onMounted(() => {
  loadCorrections();
  // Догружаем данные текущей вкладки, если она пришла из URL `?tab=...`
  if (tab.value === 'settings') loadSettings();
})
watch(() => orderStore.settings.legalEntity, () => loadCorrections())
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
.corr-badge.cancelled { background: #ECEFF1; color: #546E7A; }

.corr-source-badge {
  display: inline-block; margin-right: 6px;
  width: 18px; height: 18px; line-height: 18px; text-align: center;
  border-radius: 50%; font-size: 11px;
  background: #E3F2FD; color: #1565C0;
}
.corr-source-badge.src-cabinet { background: #E8F5E9; color: #2E7D32; }
.corr-source-badge.src-telegram { background: #E3F2FD; color: #1565C0; }

.corr-submitter-comment {
  font-size: 12px; color: #455565; font-style: italic;
  background: #FAFAFA; border-radius: 6px; padding: 4px 8px;
  margin-bottom: 4px; line-height: 1.4;
  max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

.corr-action-btns { display: flex; gap: 4px; }
.corr-btn { width: 30px; height: 30px; border: none; border-radius: 6px; cursor: pointer; font-weight: 700; font-size: 15px; color: #fff; }
.corr-btn.approve { background: #4CAF50; }
.corr-btn.approve:hover { background: #388E3C; }
.corr-btn.comment { background: #FFF; border: 1px solid var(--border); font-size: 13px; }
.corr-btn.comment:hover { border-color: #E87A1E; }
.corr-btn.reject { background: #F44336; }
.corr-btn.reject:hover { background: #D32F2F; }
.corr-btn.delete { background: none; border: 1px solid var(--border); color: var(--text-muted); font-size: 13px; }
.corr-btn.delete:hover { background: #FFEBEE; color: #F44336; border-color: #F44336; }

.corr-seg-tabs { margin-left: auto; }
.corr-seg {
  display: inline-flex; padding: 3px; gap: 2px;
  background: #F4EDE4; border-radius: 11px;
}
.corr-seg-btn {
  padding: 6px 13px; border: 0; border-radius: 8px; background: transparent;
  font: inherit; font-size: 12.5px; font-weight: 700; color: #6B5544; cursor: pointer;
  display: inline-flex; align-items: center; gap: 6px;
}
.corr-seg-btn:hover { color: #C25E12; }
.corr-seg-btn.active { background: #fff; color: #3A2418; box-shadow: 0 1px 4px rgba(74,32,19,.1); }
.corr-seg-count {
  min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9px;
  background: #E87A1E; color: #fff; font-size: 11px; font-weight: 800;
  display: inline-flex; align-items: center; justify-content: center;
}
.corr-input-sm { font-size: 13px; }
.corr-clear { margin-left: auto; }

/* ═══ Карточки заявок ═══
   Раньше это была широкая таблица на восемь колонок: «Подал» ужимался
   в четыре строки, комментарии не читались, кнопки-иконки без подписей.
   Заявка — цельная сущность, поэтому показываем её карточкой. */
.corr-cards { display: flex; flex-direction: column; gap: 10px; }

.corr-card {
  border: 1.5px solid #EFE7DC; border-left: 4px solid #D9CFC0;
  border-radius: 14px; background: #fff; overflow: hidden;
  transition: border-color .16s ease, box-shadow .16s ease;
}
.corr-card:hover { box-shadow: 0 4px 16px rgba(74, 32, 19, .07); }
.corr-card.st-approved,
.corr-card.st-rejected,
.corr-card.st-cancelled { background: #FDFBF8; }
.corr-card.st-approved .corr-card-num,
.corr-card.st-rejected .corr-card-num,
.corr-card.st-cancelled .corr-card-num { background: #8A7F72; }
/* Полоса слева — статус заявки, видно ещё до чтения текста. */
.corr-card.st-pending { border-left-color: #E87A1E; background: #FFFDF9; }
.corr-card.st-in_progress { border-left-color: #4A90D9; }
.corr-card.st-approved { border-left-color: #4CAF50; }
.corr-card.st-rejected { border-left-color: #E5736B; }
.corr-card.st-mixed { border-left-color: #4A90D9; }
.corr-card.st-cancelled { border-left-color: #B9AEA0; }

.corr-card-head {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  padding: 11px 14px 9px;
}
.corr-card-rest { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; }
.corr-card-num {
  padding: 3px 11px; border-radius: 9px;
  background: #3A2418; color: #fff;
  font-size: 14px; font-weight: 800; letter-spacing: .01em;
}
.corr-card-date {
  padding: 3px 10px; border-radius: 9px;
  background: rgba(232,122,30,.12); color: #C25E12;
  font-size: 12.5px; font-weight: 700;
}
.corr-card-head-right { display: flex; align-items: center; gap: 8px; }
.corr-card-del {
  width: 26px; height: 26px; border: 0; border-radius: 8px;
  background: transparent; color: #C4B8A8;
  font-size: 19px; line-height: 1; cursor: pointer;
}
.corr-card-del:hover { background: #FFF1F0; color: #C0392B; }

.corr-card-items { padding: 0 14px 4px; }
/* Сетка, а не флекс: количество и статус позиции выстраиваются в колонку,
   иначе они прыгали в зависимости от длины названия товара. */
.corr-line {
  display: grid; align-items: center; gap: 8px;
  grid-template-columns: 20px auto minmax(0, 1fr) 84px 76px 62px;
  padding: 6px 0; border-top: 1px solid #F4EDE4; font-size: 13.5px;
}
.corr-line:first-child { border-top: 0; }
.corr-line.st-rejected { opacity: .55; }
.corr-line-act {
  flex: 0 0 auto; width: 20px; height: 20px; border-radius: 6px;
  display: inline-flex; align-items: center; justify-content: center;
  font-weight: 800; font-size: 14px; line-height: 1;
}
.corr-line-act.add { background: rgba(76,175,80,.14); color: #2E7D32; }
.corr-line-act.remove { background: rgba(229,115,107,.16); color: #C0392B; }
.corr-line-sku { font-size: 12px; font-weight: 700; color: #C25E12; }
.corr-line-name { min-width: 0; color: #2E1C10; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.corr-line-qty { font-weight: 800; white-space: nowrap; text-align: right; font-variant-numeric: tabular-nums; overflow: visible; }
.corr-line-state { font-size: 11.5px; font-weight: 700; text-align: right; }
.corr-line-state.approved { color: #2E7D32; }
.corr-line-state.rejected { color: #C0392B; }
.corr-line-state.in_progress { color: #1565C0; }
.corr-line-btns { display: inline-flex; gap: 5px; justify-content: flex-end; }
.corr-line-btn {
  width: 26px; height: 26px; border-radius: 7px; border: 1.5px solid #E4D9CB;
  background: #fff; cursor: pointer; font-size: 13px; font-weight: 700; line-height: 1;
}
.corr-line-btn.ok { color: #2E7D32; }
.corr-line-btn.ok:hover { background: rgba(76,175,80,.12); border-color: #4CAF50; }
.corr-line-btn.no { color: #C0392B; }
.corr-line-btn.no:hover { background: rgba(229,115,107,.12); border-color: #E5736B; }

.corr-card-notes { padding: 8px 14px 2px; display: flex; flex-direction: column; gap: 5px; }
.corr-note {
  margin: 0; padding: 7px 10px; border-radius: 9px;
  font-size: 12.5px; line-height: 1.45;
}
.corr-note.from-rest { background: #FBF6F0; color: #5F4B38; }
.corr-note.from-us { background: rgba(232,122,30,.09); color: #8A4A12; }
.corr-note-label { font-weight: 800; }

.corr-card-foot {
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap;
  padding: 9px 14px 11px; margin-top: 4px; border-top: 1px solid #F4EDE4;
}
.corr-card-meta { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; font-size: 12px; color: #6B5544; }
.corr-dim { color: #9A8F80; }
.corr-source {
  padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 800;
  background: #EFE7DC; color: #6B5544;
}
.corr-source.src-cabinet { background: rgba(76,175,80,.14); color: #2E7D32; }
.corr-source.src-telegram { background: rgba(74,144,217,.14); color: #1565C0; }

.corr-card-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.corr-act {
  padding: 7px 13px; border-radius: 9px; border: 1.5px solid #E4D9CB;
  background: #fff; font: inherit; font-size: 12.5px; font-weight: 700;
  color: #5F4B38; cursor: pointer; white-space: nowrap;
  transition: background .14s ease, border-color .14s ease, color .14s ease;
}
.corr-act:hover { border-color: #C4B8A8; }
.corr-act.approve { background: linear-gradient(135deg, #4CAF50 0%, #3E9142 100%); border-color: transparent; color: #fff; }
.corr-act.approve:hover { filter: brightness(1.06); }
.corr-act.reject { color: #C0392B; border-color: #E9B4AF; }
.corr-act.reject:hover { background: #FFF1F0; }
.corr-act.comment:hover { border-color: #E87A1E; color: #C25E12; }
.corr-act.take { background: linear-gradient(135deg, #E87A1E 0%, #D9661A 100%); border-color: transparent; color: #fff; }
.corr-act.take:hover { filter: brightness(1.06); }
.corr-act.delete { color: #9A8F80; }
.corr-act.delete:hover { color: #C0392B; border-color: #E9B4AF; }

.corr-empty-title { font-size: 16px; font-weight: 800; color: #3A2418; margin-bottom: 6px; }

@media (max-width: 700px) {
  .corr-card-foot { flex-direction: column; align-items: stretch; }
  .corr-card-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .corr-act { text-align: center; }

  /* На телефоне строка позиции идёт в два ряда: сверху артикул,
     количество и кнопки, снизу — название целиком. В одну строку
     название сжималось до «ТЕСТ Б…». */
  .corr-line {
    grid-template-columns: 20px auto minmax(0, 1fr) auto;
    grid-template-areas:
      "act sku qty btns"
      "name name name state";
    row-gap: 3px;
  }
  .corr-line-act { grid-area: act; }
  .corr-line-sku { grid-area: sku; }
  .corr-line-qty { grid-area: qty; }
  .corr-line-btns { grid-area: btns; }
  .corr-line-state { grid-area: state; }
  .corr-line-name { grid-area: name; white-space: normal; }

  /* Фильтры не переносим — прокручиваем, иначе «В работе» ломается пополам. */
  .corr-seg { max-width: 100%; overflow-x: auto; flex-wrap: nowrap; }
  /* Без этого кнопки сжимаются и подписи наезжают друг на друга. */
  .corr-seg-btn { white-space: nowrap; flex: 0 0 auto; }
  .corr-seg-tabs { margin-left: 0; }
}

/* Настройки */
.corr-section-title { font-size: 16px; margin-bottom: 4px; }
.corr-section-title-next { margin-top: 28px; padding-top: 24px; border-top: 1px solid var(--border-light); }
.corr-deadline-group {
  min-width: 190px; font-size: 13px; font-weight: 700; color: #5F4B38;
}
.corr-deadline-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.corr-deadline-input { width: 110px; font-size: 14px; }
.corr-deadline-current { font-size: 12px; color: var(--text-muted); }
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
