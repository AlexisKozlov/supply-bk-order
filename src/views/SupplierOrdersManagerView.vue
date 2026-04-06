<template>
  <div :class="supplierId ? '' : 'rom-page'">
    <!-- Toolbar — показываем только если не embedded (нет пропа supplierId) -->
    <div v-if="!supplierId" class="rom-toolbar">
      <h1>Заявки поставщикам</h1>
    </div>

    <!-- Page tabs -->
    <div class="rom-page-tabs">
      <button class="rom-page-tab" :class="{ active: pageTab === 'sessions' }" @click="pageTab = 'sessions'">
        Сессии
      </button>
      <button class="rom-page-tab" :class="{ active: pageTab === 'list' }" @click="pageTab = 'list'; loadOrdersList()">
        Список заявок
      </button>
      <button class="rom-page-tab" :class="{ active: pageTab === 'schedules' }" @click="pageTab = 'schedules'; loadSchedules()">
        Графики
      </button>
      <button class="rom-page-tab" :class="{ active: pageTab === 'templates' }" @click="pageTab = 'templates'; loadTemplates()">
        Шаблон товаров
      </button>
    </div>

    <!-- Supplier selector — только если supplierId не передан через проп -->
    <div v-if="!supplierId" class="rom-date-row">
      <label>Поставщик:</label>
      <select v-model="currentSupplierId" @change="onSupplierChange" class="rom-select">
        <option value="">— выберите —</option>
        <option v-for="s in allSuppliers" :key="s.id" :value="s.id">
          {{ s.short_name }} ({{ s.restaurant_count }} рест.)
        </option>
      </select>
    </div>

    <!-- ═══ TAB: Сессии ═══ -->
    <template v-if="pageTab === 'sessions' && currentSupplierId">
      <!-- Список сессий (если нет открытой) -->
      <template v-if="!activeSessionId">
        <div class="rom-export-row">
          <button class="rom-btn rom-btn-primary" @click="showCreateModal = true">+ Новая сессия</button>
          <button class="rom-btn rom-btn-outline" @click="copyLink">Ссылка /supplier-order</button>
        </div>

        <div v-if="loadingSessions" class="rom-loading">Загрузка...</div>
        <div v-else-if="sessions.length === 0" class="rom-empty">
          Нет сессий. Нажмите «+ Новая сессия» для создания.
        </div>
        <div v-else class="so-sessions-list">
          <div v-for="s in sessions" :key="s.id"
            class="so-session-card" :class="{ closed: s.status === 'closed' }"
            @click="openSession(s)">
            <div class="so-session-header">
              <span class="so-session-name">{{ formatDateRange(s.week_start, s.week_end) }}</span>
              <span class="so-session-status" :class="'st-sess-' + s.status">
                {{ s.status === 'active' ? 'Активна' : 'Закрыта' }}
              </span>
            </div>
            <div class="so-session-meta">
              Заявок: {{ s.order_count || 0 }} · Дедлайн: {{ s.deadline_time?.substring(0, 5) || '14:00' }}
              <span v-if="s.created_by"> · {{ s.created_by }}</span>
            </div>
          </div>
        </div>
      </template>

      <!-- Детали сессии -->
      <template v-else>
        <div class="so-detail-bar">
          <button class="rom-btn-sm" @click="closeSessionDetail">← Назад к сессиям</button>
          <span class="so-detail-name">{{ formatDateRange(activeSessionData?.week_start, activeSessionData?.week_end) }}</span>
          <span class="so-session-status" :class="'st-sess-' + activeSessionData?.status">
            {{ activeSessionData?.status === 'active' ? 'Активна' : 'Закрыта' }}
          </span>
          <div class="so-detail-actions">
            <button v-if="activeSessionData?.status === 'active'" class="rom-btn-sm rom-btn-danger" @click="handleCloseSession">Закрыть</button>
            <button v-if="activeSessionData?.status === 'closed'" class="rom-btn-sm" @click="handleReopenSession">Открыть заново</button>
            <button class="rom-btn-sm rom-btn-danger" @click="handleDeleteSession">Удалить</button>
          </div>
        </div>

        <!-- Deadline -->
        <div class="rom-date-row">
          <label>Дедлайн:</label>
          <input type="time" v-model="sessionDeadline" class="rom-input-sm" style="width:100px" />
          <button class="rom-btn-sm" @click="updateDeadline" :disabled="!sessionDeadline">Сохранить</button>
        </div>

        <!-- Date nav -->
        <div class="rom-date-row">
          <label>Дата поставки:</label>
          <div class="so-date-nav">
            <button v-for="wd in weekDates" :key="wd.date"
              class="rom-btn-sm" :class="{ 'rom-btn-primary': selectedDate === wd.date }"
              @click="selectedDate = wd.date; loadStatus()">
              {{ wd.day_name }} {{ formatDateShort(wd.date) }}
            </button>
          </div>
          <input type="date" v-model="selectedDate" @change="loadStatus" style="margin-left:8px" />
        </div>

        <div v-if="loading" class="rom-loading">Загрузка...</div>
        <template v-else>
          <!-- Stats -->
          <div class="rom-stats">
            <div class="rom-stat">
              <span class="rom-stat-value">{{ stats.submitted }}</span>
              <span class="rom-stat-label">подано</span>
            </div>
            <div class="rom-stat">
              <span class="rom-stat-value rom-stat-pending">{{ stats.pending }}</span>
              <span class="rom-stat-label">не подано</span>
            </div>
            <div class="rom-stat">
              <span class="rom-stat-value">{{ stats.total }}</span>
              <span class="rom-stat-label">всего</span>
            </div>
          </div>

          <!-- Export -->
          <div class="rom-export-row">
            <button class="rom-btn rom-btn-export" @click="exportExcel" :disabled="exporting">
              {{ exporting ? 'Выгрузка...' : 'Выгрузить в Excel' }}
            </button>
            <button class="rom-btn" @click="loadStatus" :disabled="loading">Обновить</button>
          </div>

          <!-- Restaurants table -->
          <div class="rom-table-wrap">
            <table class="rom-table">
              <thead>
                <tr>
                  <th>Ресторан</th>
                  <th>Регион</th>
                  <th>Адрес</th>
                  <th>День заказа</th>
                  <th>Статус</th>
                  <th>Позиций</th>
                  <th>Кол-во</th>
                  <th>Время подачи</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in restaurants" :key="r.number" :class="{ 'rom-row-submitted': r.order_status }">
                  <td class="rom-td-num">{{ r.number }}</td>
                  <td>{{ r.region }}</td>
                  <td>{{ r.address }}</td>
                  <td>{{ dayNames[r.order_day] || '' }}</td>
                  <td>
                    <span class="rom-status" :class="'st-' + (r.order_status || 'none')">
                      {{ statusLabel(r.order_status) }}
                    </span>
                  </td>
                  <td>{{ r.item_count || '—' }}</td>
                  <td>{{ r.total_qty ? (+r.total_qty).toFixed(0) : '—' }}</td>
                  <td class="rom-td-time">{{ r.submitted_at ? formatTime(r.submitted_at) : '—' }}</td>
                  <td class="rom-td-actions">
                    <button v-if="r.order_id" class="rom-btn-sm" @click="viewOrder(r.order_id)">Открыть</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </template>
    </template>

    <!-- ═══ TAB: Список заявок ═══ -->
    <template v-if="pageTab === 'list' && currentSupplierId">
      <div class="rom-date-row">
        <label>Период:</label>
        <input type="date" v-model="listDateFrom" />
        <span>—</span>
        <input type="date" v-model="listDateTo" />
        <button class="rom-btn-sm" @click="loadOrdersList">Загрузить</button>
      </div>
      <div v-if="loadingList" class="rom-loading">Загрузка...</div>
      <div v-else-if="ordersList.length === 0" class="rom-empty">Заявок за выбранный период нет.</div>
      <div v-else class="rom-table-wrap">
        <table class="rom-table">
          <thead>
            <tr>
              <th>Рест.</th>
              <th>Адрес</th>
              <th>Дата доставки</th>
              <th>Дата заказа</th>
              <th>Статус</th>
              <th>Позиций</th>
              <th>Кол-во</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="o in ordersList" :key="o.id">
              <td class="rom-td-num">{{ o.restaurant_number }}</td>
              <td>{{ o.address }}</td>
              <td>{{ formatDate(o.delivery_date) }}</td>
              <td>{{ formatDate(o.order_date) }}</td>
              <td>
                <span class="rom-status" :class="'st-' + o.status">{{ statusLabel(o.status) }}</span>
              </td>
              <td>{{ o.item_count || '—' }}</td>
              <td>{{ o.total_qty ? (+o.total_qty).toFixed(0) : '—' }}</td>
              <td class="rom-td-actions">
                <button class="rom-btn-sm" @click="viewOrder(o.id)">Открыть</button>
                <button class="rom-btn-sm rom-btn-danger" @click="deleteOrder(o.id)">Удалить</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ═══ TAB: Графики ═══ -->
    <template v-if="pageTab === 'schedules' && currentSupplierId">
      <div v-if="loadingSchedules" class="rom-loading">Загрузка...</div>
      <div v-else>
        <div class="rom-table-wrap">
          <table class="rom-table">
            <thead>
              <tr>
                <th>Ресторан</th>
                <th>Регион</th>
                <th>Адрес</th>
                <th>День заказа</th>
                <th>День поставки</th>
                <th>Активен</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in schedules" :key="s.id">
                <td class="rom-td-num">{{ s.restaurant_number }}</td>
                <td>{{ s.region }}</td>
                <td>{{ s.address }}</td>
                <td>{{ dayNamesFull[s.order_day] || s.order_day }}</td>
                <td>{{ dayNamesFull[s.delivery_day] || s.delivery_day }}</td>
                <td>{{ s.is_active == 1 ? 'Да' : 'Нет' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="so-schedule-count">Всего записей: {{ schedules.length }}</p>
      </div>
    </template>

    <!-- ═══ TAB: Шаблон товаров ═══ -->
    <template v-if="pageTab === 'templates' && currentSupplierId">
      <div class="rom-date-row">
        <label>Юрлицо:</label>
        <select v-model="templateLe" @change="loadTemplates" class="rom-select">
          <option value='ООО "Бургер БК"'>Бургер БК</option>
          <option value='ООО "Воглия Матта"'>Воглия Матта</option>
        </select>
        <button class="rom-btn-sm" @click="importFromProducts">Импорт из справочника</button>
        <button class="rom-btn-sm rom-btn-primary" @click="saveTemplates" :disabled="savingTemplates">
          {{ savingTemplates ? 'Сохранение...' : 'Сохранить' }}
        </button>
      </div>
      <div v-if="loadingTemplates" class="rom-loading">Загрузка...</div>
      <div v-else>
        <div class="rom-table-wrap">
          <table class="rom-table">
            <thead>
              <tr>
                <th style="width:50px">Порядок</th>
                <th>SKU</th>
                <th>Название</th>
                <th style="width:80px">Кратность</th>
                <th style="width:80px">Мин. кол-во</th>
                <th style="width:40px"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(t, idx) in templates" :key="idx">
                <td><input type="number" v-model.number="t.sort_order" class="rom-input-sm" style="width:50px" /></td>
                <td>{{ t.sku }}</td>
                <td>{{ t.product_name }}</td>
                <td><input type="number" v-model.number="t.multiplicity" class="rom-input-sm" style="width:70px" min="0" step="0.01" placeholder="—" /></td>
                <td><input type="number" v-model.number="t.min_qty" class="rom-input-sm" style="width:70px" min="0" step="0.01" placeholder="—" /></td>
                <td><button class="rom-btn-sm rom-btn-danger" @click="templates.splice(idx, 1)">✕</button></td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="so-schedule-count">Товаров: {{ templates.length }}</p>
      </div>
    </template>

    <!-- ═══ Modal: Create session ═══ -->
    <div v-if="showCreateModal" class="rom-modal-overlay" @click.self="showCreateModal = false">
      <div class="rom-modal">
        <div class="rom-modal-header">
          <h3>Новая сессия</h3>
          <button class="rom-modal-close" @click="showCreateModal = false">✕</button>
        </div>
        <div class="rom-modal-body">
          <div class="so-form-row">
            <label>Начало недели:</label>
            <input type="date" v-model="createForm.weekStart" class="rom-input-sm" />
          </div>
          <div class="so-form-row">
            <label>Конец недели:</label>
            <input type="date" v-model="createForm.weekEnd" class="rom-input-sm" />
          </div>
          <div class="so-form-row">
            <label>Дедлайн подачи:</label>
            <input type="time" v-model="createForm.deadlineTime" class="rom-input-sm" />
          </div>
          <div class="so-form-actions">
            <button class="rom-btn" @click="showCreateModal = false">Отмена</button>
            <button class="rom-btn rom-btn-primary" @click="createSession" :disabled="creatingSession">
              {{ creatingSession ? 'Создание...' : 'Создать' }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══ Modal: Order detail ═══ -->
    <div v-if="showOrderModal" class="rom-modal-overlay" @click.self="showOrderModal = false">
      <div class="rom-modal">
        <div class="rom-modal-header">
          <h3>Заявка #{{ viewedOrder?.id }} — Рест. {{ viewedOrder?.restaurant_number }}</h3>
          <button class="rom-modal-close" @click="showOrderModal = false">✕</button>
        </div>
        <div class="rom-modal-body" v-if="viewedOrder">
          <p><strong>Поставщик:</strong> {{ viewedOrder.supplier_name }}</p>
          <p><strong>Доставка:</strong> {{ formatDate(viewedOrder.delivery_date) }}</p>
          <p><strong>Подано:</strong> {{ viewedOrder.submitted_at ? formatTime(viewedOrder.submitted_at) : '—' }}</p>
          <table class="rom-table">
            <thead><tr><th>Товар</th><th>SKU</th><th>Кол-во</th></tr></thead>
            <tbody>
              <tr v-for="item in viewedOrder.items" :key="item.id">
                <td>{{ item.product_name }}</td>
                <td>{{ item.sku }}</td>
                <td>{{ item.quantity }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div v-if="!currentSupplierId" class="rom-empty" style="margin-top: 40px">
      Выберите поставщика для просмотра заявок
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue';
import { useSupplierOrderStore } from '@/stores/supplierOrderStore.js';

const props = defineProps({
  supplierId: { type: String, default: '' },
});

const store = useSupplierOrderStore();

const dayNames = { 1: 'ПН', 2: 'ВТ', 3: 'СР', 4: 'ЧТ', 5: 'ПТ', 6: 'СБ', 7: 'ВС' };
const dayNamesFull = { 1: 'Понедельник', 2: 'Вторник', 3: 'Среда', 4: 'Четверг', 5: 'Пятница', 6: 'Суббота', 7: 'Воскресенье' };

const pageTab = ref('sessions');
const loading = ref(false);
const allSuppliers = ref([]);
const currentSupplierId = ref(props.supplierId || '');
const selectedDate = ref('');
const session = ref(null);
const stats = ref({ total: 0, submitted: 0, pending: 0 });
const restaurants = ref([]);
const weekDates = ref([]);

// Sessions
const loadingSessions = ref(false);
const sessions = ref([]);
const activeSessionId = ref(null);
const activeSessionData = ref(null);
const sessionDeadline = ref('14:00');

// Create session modal
const showCreateModal = ref(false);
const creatingSession = ref(false);
const createForm = ref({
  weekStart: getMonday(),
  weekEnd: getSunday(),
  deadlineTime: '14:00',
});

// List tab
const loadingList = ref(false);
const ordersList = ref([]);
const listDateFrom = ref(todayStr(-7));
const listDateTo = ref(todayStr(7));

// Schedules
const loadingSchedules = ref(false);
const schedules = ref([]);

// Templates
const loadingTemplates = ref(false);
const savingTemplates = ref(false);
const templates = ref([]);
const templateLe = ref('ООО "Бургер БК"');

// Order modal
const showOrderModal = ref(false);
const viewedOrder = ref(null);
const exporting = ref(false);

function todayStr(offsetDays = 0) {
  const d = new Date();
  d.setDate(d.getDate() + offsetDays);
  return d.toISOString().slice(0, 10);
}

function getMonday() {
  const d = new Date();
  const day = d.getDay(); // 0=Sun, 1=Mon...
  const diff = day === 0 ? 1 : (1 - day); // Sun→+1, else back to Mon
  d.setDate(d.getDate() + diff);
  return d.toISOString().slice(0, 10);
}

function getSunday() {
  const d = new Date();
  const day = d.getDay();
  const diff = day === 0 ? 7 : (7 - day + 1); // Sun→+7, else forward to Sun
  d.setDate(d.getDate() + diff);
  return d.toISOString().slice(0, 10);
}

// Если supplierId пришёл как проп — загружаем сессии сразу
watch(() => props.supplierId, (val) => {
  if (val) {
    currentSupplierId.value = val;
    loadSessions();
  }
}, { immediate: true });

onMounted(async () => {
  if (!props.supplierId) {
    try {
      allSuppliers.value = await store.adminGetSuppliers();
      if (allSuppliers.value.length === 1) {
        currentSupplierId.value = allSuppliers.value[0].id;
        await loadSessions();
      }
    } catch (e) {
      console.error(e);
    }
  }
});

async function onSupplierChange() {
  if (!currentSupplierId.value) return;
  activeSessionId.value = null;
  activeSessionData.value = null;
  await loadSessions();
}

async function loadSessions() {
  if (!currentSupplierId.value) return;
  loadingSessions.value = true;
  try {
    sessions.value = await store.adminGetSessions(currentSupplierId.value);
  } catch (e) {
    console.error(e);
  } finally {
    loadingSessions.value = false;
  }
}

function openSession(s) {
  activeSessionId.value = s.id;
  activeSessionData.value = s;
  sessionDeadline.value = (s.deadline_time || '14:00:00').substring(0, 5);
  selectedDate.value = '';
  session.value = null;
  restaurants.value = [];
  weekDates.value = [];
  loadStatus();
}

function closeSessionDetail() {
  activeSessionId.value = null;
  activeSessionData.value = null;
}

async function loadStatus() {
  if (!currentSupplierId.value || !activeSessionId.value) return;
  loading.value = true;
  try {
    const data = await store.adminGetStatus(currentSupplierId.value, selectedDate.value || undefined, activeSessionId.value);
    session.value = data.session;
    stats.value = data.stats || { total: 0, submitted: 0, pending: 0 };
    restaurants.value = data.restaurants || [];
    weekDates.value = data.week_dates || [];
    if (data.date) selectedDate.value = data.date;
  } catch (e) {
    console.error(e);
  } finally {
    loading.value = false;
  }
}

async function createSession() {
  if (!currentSupplierId.value) return;
  creatingSession.value = true;
  try {
    await store.adminCreateSession(currentSupplierId.value, {
      week_start: createForm.value.weekStart,
      week_end: createForm.value.weekEnd,
      deadline_time: createForm.value.deadlineTime + ':00',
    });
    showCreateModal.value = false;
    await loadSessions();
    // Открываем новую сессию (первая в списке)
    if (sessions.value.length > 0) {
      openSession(sessions.value[0]);
    }
  } catch (e) {
    alert('Ошибка: ' + e.message);
  } finally {
    creatingSession.value = false;
  }
}

async function updateDeadline() {
  try {
    await store.adminUpdateSession(activeSessionId.value, currentSupplierId.value, {
      deadline_time: sessionDeadline.value + ':00',
    });
    activeSessionData.value = { ...activeSessionData.value, deadline_time: sessionDeadline.value + ':00' };
    alert('Дедлайн обновлён');
  } catch (e) {
    alert('Ошибка: ' + e.message);
  }
}

async function handleCloseSession() {
  if (!confirm('Закрыть эту сессию? Рестораны не смогут подавать заявки.')) return;
  try {
    await store.adminCloseSession(activeSessionId.value, currentSupplierId.value);
    activeSessionData.value = { ...activeSessionData.value, status: 'closed' };
    await loadSessions();
  } catch (e) {
    alert('Ошибка: ' + e.message);
  }
}

async function handleReopenSession() {
  try {
    await store.adminReopenSession(activeSessionId.value, currentSupplierId.value);
    activeSessionData.value = { ...activeSessionData.value, status: 'active' };
    await loadSessions();
  } catch (e) {
    alert('Ошибка: ' + e.message);
  }
}

async function handleDeleteSession() {
  if (!confirm('Удалить сессию и все заявки в ней? Это нельзя отменить.')) return;
  try {
    await store.adminDeleteSession(activeSessionId.value, currentSupplierId.value);
    closeSessionDetail();
    await loadSessions();
  } catch (e) {
    alert('Ошибка: ' + e.message);
  }
}

async function loadOrdersList() {
  if (!currentSupplierId.value) return;
  loadingList.value = true;
  try {
    ordersList.value = await store.adminGetOrders(currentSupplierId.value, listDateFrom.value, listDateTo.value);
  } catch (e) {
    console.error(e);
  } finally {
    loadingList.value = false;
  }
}

async function loadSchedules() {
  if (!currentSupplierId.value) return;
  loadingSchedules.value = true;
  try {
    schedules.value = await store.adminGetSchedules(currentSupplierId.value);
  } catch (e) {
    console.error(e);
  } finally {
    loadingSchedules.value = false;
  }
}

async function loadTemplates() {
  if (!currentSupplierId.value) return;
  loadingTemplates.value = true;
  try {
    templates.value = await store.adminGetTemplates(currentSupplierId.value, templateLe.value);
  } catch (e) {
    console.error(e);
  } finally {
    loadingTemplates.value = false;
  }
}

async function saveTemplates() {
  savingTemplates.value = true;
  try {
    await store.adminSaveTemplates(currentSupplierId.value, templateLe.value, templates.value);
    alert('Шаблон сохранён');
  } catch (e) {
    alert('Ошибка: ' + e.message);
  } finally {
    savingTemplates.value = false;
  }
}

async function importFromProducts() {
  if (!currentSupplierId.value) return;
  try {
    const products = await store.loadProducts(currentSupplierId.value);
    if (!products.length) {
      alert('У этого поставщика нет товаров в справочнике');
      return;
    }
    templates.value = products.map((p, i) => ({
      product_id: p.product_id || p.id || '',
      sku: p.sku,
      product_name: p.product_name || p.name || '',
      sort_order: i * 10,
      multiplicity: p.multiplicity || null,
      min_qty: p.min_qty || null,
    }));
  } catch (e) {
    alert('Ошибка: ' + e.message);
  }
}

async function viewOrder(orderId) {
  try {
    viewedOrder.value = await store.adminGetOrder(orderId);
    showOrderModal.value = true;
  } catch (e) {
    alert('Ошибка: ' + e.message);
  }
}

async function deleteOrder(orderId) {
  if (!confirm('Удалить эту заявку?')) return;
  try {
    await store.adminDeleteOrder(orderId);
    await loadOrdersList();
  } catch (e) {
    alert('Ошибка: ' + e.message);
  }
}

async function exportExcel() {
  if (!currentSupplierId.value || !selectedDate.value) return;
  exporting.value = true;
  try {
    const data = await store.adminGetExport(currentSupplierId.value, selectedDate.value);
    if (!data.orders?.length) { alert('Нет заявок для экспорта'); return; }

    const XLSX = await import('xlsx-js-style');
    const wb = XLSX.utils.book_new();

    const supplierName = allSuppliers.value.find(s => s.id === currentSupplierId.value)?.short_name || 'Поставщик';

    const header = ['Ресторан', 'Регион', 'Адрес', 'SKU', 'Товар', 'Количество'];
    const rows = [header];
    for (const row of data.orders) {
      rows.push([row.restaurant_number, row.region, row.address, row.sku, row.product_name, parseFloat(row.quantity) || 0]);
    }
    const ws = XLSX.utils.aoa_to_sheet(rows);
    ws['!cols'] = [{ wch: 10 }, { wch: 12 }, { wch: 40 }, { wch: 15 }, { wch: 40 }, { wch: 12 }];
    XLSX.utils.book_append_sheet(wb, ws, 'Заявки');

    const sumHeader = ['SKU', 'Товар', 'Итого', 'Кол-во ресторанов'];
    const sumRows = [sumHeader, ...data.summary.map(s => [s.sku, s.product_name, s.total_qty, s.restaurant_count])];
    const ws2 = XLSX.utils.aoa_to_sheet(sumRows);
    ws2['!cols'] = [{ wch: 15 }, { wch: 40 }, { wch: 12 }, { wch: 18 }];
    XLSX.utils.book_append_sheet(wb, ws2, 'Сводка');

    XLSX.writeFile(wb, `${supplierName}_${selectedDate.value}.xlsx`);
  } catch (e) {
    alert('Ошибка экспорта: ' + e.message);
  } finally {
    exporting.value = false;
  }
}

function copyLink() {
  const url = window.location.origin + '/supplier-order';
  navigator.clipboard.writeText(url);
  alert('Ссылка скопирована: ' + url);
}

function statusLabel(s) {
  if (s === 'submitted') return 'Подано';
  if (s === 'locked') return 'Закрыто';
  if (s === 'draft') return 'Черновик';
  return 'Не подано';
}

function formatDate(d) {
  if (!d) return '';
  return new Date(d + 'T00:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' });
}
function formatDateShort(d) {
  if (!d) return '';
  return new Date(d + 'T00:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
}
function formatDateRange(start, end) {
  if (!start || !end) return '—';
  return formatDate(start) + ' — ' + formatDate(end);
}
function formatTime(dt) {
  if (!dt) return '';
  const d = new Date(dt);
  return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}
</script>

<style scoped>
.rom-page { padding: 20px; }

.rom-toolbar {
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: 20px; flex-wrap: wrap; gap: 12px;
}
.rom-toolbar h1 { margin: 0; font-size: 22px; color: #502314; }
.rom-toolbar-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.rom-btn {
  padding: 8px 16px; border-radius: 8px; border: 1px solid #e0d5c8;
  background: white; cursor: pointer; font-size: 13px;
  font-family: inherit; color: #502314; transition: all 0.2s;
}
.rom-btn:hover { background: #f5f0eb; }
.rom-btn-primary { background: #D62300; color: white; border-color: #D62300; }
.rom-btn-primary:hover { background: #b81e00; }
.rom-btn-outline { border-style: dashed; }
.rom-btn-export { background: #f0fdf4; color: #16a34a; border-color: #16a34a; }
.rom-btn-export:hover { background: #dcfce7; }
.rom-btn-sm {
  padding: 4px 10px; border-radius: 6px; border: 1px solid #e0d5c8;
  background: white; cursor: pointer; font-size: 12px; font-family: inherit;
  color: #502314; transition: all 0.2s;
}
.rom-btn-sm:hover { background: #f5f0eb; }
.rom-btn-sm.rom-btn-primary { background: #D62300; color: white; border-color: #D62300; }
.rom-btn-sm.rom-btn-primary:hover { background: #b81e00; }
.rom-btn-sm.rom-btn-danger { background: white; color: #dc2626; border-color: #dc2626; }
.rom-btn-sm.rom-btn-danger:hover { background: #fef2f2; }
.rom-btn-danger { color: #dc2626; border-color: #dc2626; }

.rom-page-tabs {
  display: flex; gap: 0; margin-bottom: 16px;
  border-bottom: 2px solid #e0d5c8;
}
.rom-page-tab {
  padding: 10px 24px; border: none; background: transparent;
  cursor: pointer; font-size: 15px; font-weight: 600;
  color: #8b7355; border-bottom: 3px solid transparent;
  transition: all 0.2s; font-family: inherit;
}
.rom-page-tab.active { color: #D62300; border-bottom-color: #D62300; }
.rom-page-tab:hover { color: #502314; }

.rom-date-row {
  display: flex; align-items: center; gap: 10px;
  margin-bottom: 16px; flex-wrap: wrap;
}
.rom-date-row label { font-size: 14px; font-weight: 600; color: #502314; }
.rom-date-row input[type="date"] {
  padding: 8px 12px; border: 2px solid #e0d5c8; border-radius: 8px;
  font-size: 14px; font-family: inherit;
}
.rom-select { padding: 6px 10px; border: 1px solid #e0d5c8; border-radius: 6px; font-size: 13px; font-family: inherit; min-width: 200px; }

.rom-stats {
  display: flex; gap: 16px; margin-bottom: 16px;
  align-items: center; flex-wrap: wrap;
}
.rom-stat {
  background: white; padding: 12px 20px; border-radius: 10px;
  text-align: center; box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}
.rom-stat-value { display: block; font-size: 28px; font-weight: 700; color: #16a34a; }
.rom-stat-pending { color: #D62300; }
.rom-stat-label { font-size: 12px; color: #8b7355; }

.rom-export-row { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }

.rom-loading { padding: 40px; text-align: center; color: #8b7355; }
.rom-empty { padding: 40px; text-align: center; color: #8b7355; font-size: 15px; }

.rom-table-wrap { overflow-x: auto; }
.rom-table {
  width: 100%; border-collapse: collapse; background: white;
  border-radius: 10px; overflow: hidden;
}
.rom-table th {
  padding: 10px 12px; font-size: 12px; color: #8b7355;
  text-align: left; border-bottom: 2px solid #e0d5c8;
  background: #faf7f4; font-weight: 600;
}
.rom-table td {
  padding: 8px 12px; border-bottom: 1px solid #f0ebe4;
  font-size: 13px; color: #502314;
}
.rom-td-num { font-weight: 700; }
.rom-td-time { font-size: 12px; color: #8b7355; }
.rom-td-actions { display: flex; gap: 4px; }
.rom-row-submitted { background: #f0fdf4; }
.rom-status {
  padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 600;
}
.st-submitted { background: #ecfdf5; color: #16a34a; }
.st-edited { background: #eff6ff; color: #2563eb; }
.st-draft { background: #f5f5f5; color: #666; }
.st-none { background: #fef2f2; color: #dc2626; }
.st-locked { background: #fef3c7; color: #92400e; }

.rom-modal-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,0.4);
  display: flex; align-items: center; justify-content: center;
  z-index: 1000; padding: 20px;
}
.rom-modal {
  background: white; border-radius: 16px; width: 100%;
  max-width: 500px; max-height: 85vh; overflow-y: auto;
  box-shadow: 0 8px 40px rgba(0,0,0,0.2);
}
.rom-modal-header {
  display: flex; justify-content: space-between; align-items: center;
  padding: 16px 20px; border-bottom: 1px solid #e0d5c8;
}
.rom-modal-header h3 { margin: 0; font-size: 18px; color: #502314; }
.rom-modal-close {
  background: none; border: none; cursor: pointer;
  font-size: 18px; color: #999; padding: 4px;
}
.rom-modal-body { padding: 20px; }

.rom-input-sm { padding: 4px 6px; border: 1px solid #e0d5c8; border-radius: 4px; font-size: 13px; }
.so-date-nav { display: flex; gap: 4px; flex-wrap: wrap; }
.so-schedule-count { font-size: 13px; color: #8b7355; margin: 8px 16px; }

/* ═══ Sessions ═══ */
.so-sessions-list { display: flex; flex-direction: column; gap: 8px; }
.so-session-card {
  background: white; border-radius: 10px; padding: 14px 18px;
  box-shadow: 0 1px 4px rgba(0,0,0,0.06); cursor: pointer;
  border-left: 4px solid #16a34a; transition: all 0.15s;
}
.so-session-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.12); }
.so-session-card.closed { border-left-color: #9ca3af; opacity: 0.7; }
.so-session-header { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
.so-session-name { font-size: 15px; font-weight: 700; color: #502314; }
.so-session-status {
  font-size: 11px; padding: 2px 8px; border-radius: 6px; font-weight: 600;
}
.st-sess-active { background: #ecfdf5; color: #16a34a; }
.st-sess-closed { background: #f5f5f5; color: #999; }
.so-session-meta { font-size: 12px; color: #8b7355; }

/* Detail bar */
.so-detail-bar {
  display: flex; align-items: center; gap: 10px; margin-bottom: 16px;
  flex-wrap: wrap; padding: 10px 0;
}
.so-detail-name { font-size: 16px; font-weight: 700; color: #502314; }
.so-detail-actions { display: flex; gap: 6px; margin-left: auto; }

/* Create session form */
.so-form-row {
  display: flex; align-items: center; gap: 10px; margin-bottom: 12px;
}
.so-form-row label { font-size: 13px; font-weight: 600; color: #502314; min-width: 130px; }
.so-form-row input { flex: 1; padding: 8px 12px; border: 2px solid #e0d5c8; border-radius: 8px; font-size: 14px; font-family: inherit; }
.so-form-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; }
</style>
