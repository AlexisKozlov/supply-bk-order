<template>
  <div class="history-view">
    <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
      <h1 class="page-title">История</h1>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <!-- Tab switcher -->
        <div class="ht-tabs">
          <button class="ht-tab" :class="{ active: filterType === 'orders' }" @click="filterType = 'orders'; load()">
            <BkIcon name="order" size="xs"/> Заказы
          </button>
          <button class="ht-tab" :class="{ active: filterType === 'plans' }" @click="filterType = 'plans'; load()">
            <BkIcon name="planning" size="xs"/> Планы
          </button>
        </div>
        <div class="ht-filter">
          <select v-model="filterSupplier" @change="load">
            <option value="">Все поставщики</option>
            <option v-for="s in suppliers" :key="s.short_name" :value="s.short_name">{{ s.short_name }}</option>
          </select>
        </div>
        <div class="ht-filter">
          <select v-model="filterAuthor" @change="load">
            <option value="">Все авторы</option>
            <option v-for="a in uniqueAuthors" :key="a" :value="a">{{ a }}</option>
          </select>
        </div>
        <div class="ht-filter" style="display:flex;align-items:center;gap:4px;">
          <input type="date" v-model="filterDateFrom" @change="load" style="padding:4px 6px;border:1px solid var(--border);border-radius:6px;font-size:12px;font-family:inherit;" title="Дата от"/>
          <span style="font-size:11px;color:var(--text-muted);">—</span>
          <input type="date" v-model="filterDateTo" @change="load" style="padding:4px 6px;border:1px solid var(--border);border-radius:6px;font-size:12px;font-family:inherit;" title="Дата до"/>
        </div>
        <div v-if="filterType === 'orders'" class="ht-filter">
          <input type="text" v-model="searchQuery" @input="onSearchInput" placeholder="Поиск по товарам..." style="padding:5px 10px;border:1px solid var(--border);border-radius:6px;font-size:12px;font-family:inherit;min-width:160px;"/>
        </div>
        <div v-if="filterType === 'orders'" class="ht-filter" style="display:flex;gap:6px;align-items:center;">
          <button class="btn small" :class="{ active: compareMode }" @click="toggleCompare" style="font-size:11px;">
            <BkIcon name="ruler" size="xs"/> {{ compareMode ? 'Отмена' : 'Сравнить' }}
          </button>
          <button v-if="compareMode && compareSelection.length === 2" class="btn small primary" @click="openCompare" style="font-size:11px;">
            Сравнить 2 заказа
          </button>
        </div>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="historyStore.loading" class="loading-state" style="text-align:center;padding:40px;">
      <BurgerSpinner text="Загрузка..." />
    </div>

    <!-- Empty -->
    <div v-else-if="!rows.length" style="text-align:center;padding:60px;color:var(--text-muted);">
      <BkIcon name="history" size="lg"/><br>
      <span style="margin-top:8px;display:block;">История пуста</span>
    </div>

    <!-- ORDERS TABLE -->
    <div v-else-if="filterType === 'orders'" class="ht-wrap">
      <table class="ht-table">
        <thead>
          <tr>
            <th v-if="compareMode" class="ht-th" style="width:36px;"></th>
            <th class="ht-th ht-th-sort" @click="toggleSort('delivery_date')">
              Дата поставки <span class="ht-sort-icon">{{ sortIcon('delivery_date') }}</span>
            </th>
            <th class="ht-th ht-th-sort ht-th-supplier" @click="toggleSort('supplier')">
              Поставщик <span class="ht-sort-icon">{{ sortIcon('supplier') }}</span>
            </th>
            <th class="ht-th">Примечание</th>
            <th class="ht-th ht-th-center">Автор</th>
            <th class="ht-th ht-th-center">Создан</th>
            <th class="ht-th ht-th-center">Действия</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="order in sortedOrders" :key="order.id" class="ht-row" :class="{ 'ht-row-selected': drawerOrder?.id === order.id || compareSelection.includes(order.id) }" @click="compareMode ? toggleCompareSelect(order) : openOrderDrawer(order)">
              <td v-if="compareMode" class="ht-td ht-td-center" style="width:36px;" @click.stop>
                <input type="checkbox" :checked="compareSelection.includes(order.id)" @change="toggleCompareSelect(order)" :disabled="compareSelection.length >= 2 && !compareSelection.includes(order.id)"/>
              </td>
              <td class="ht-td ht-td-date">
                <span class="ht-date-day">{{ dayOfWeek(order.delivery_date) }}</span>
                <span class="ht-date-main">{{ formatDateShort(order.delivery_date) }}</span>
              </td>
              <td class="ht-td ht-td-supplier">
                <span class="ht-supplier-dot" :style="{ background: supplierColor(order.supplier) }"></span>
                {{ order.supplier }}
              </td>
              <td class="ht-td ht-td-note"><span v-if="order.note" :title="order.note">{{ order.note }}</span></td>
              <td class="ht-td ht-td-center">{{ order.created_by || '—' }}</td>
              <td class="ht-td ht-td-center ht-td-created">{{ formatDateTime(order.created_at) }}</td>
              <td class="ht-td ht-td-center" @click.stop>
                <button class="ht-act" title="Просмотр" @click="viewOrder(order)"><BkIcon name="eye" size="sm"/></button>
                <button v-if="!isViewer && !order.received_at" class="ht-act" title="Редактировать" @click="editOrder(order)"><BkIcon name="edit" size="sm"/></button>
                <span v-else-if="!isViewer && order.received_at" class="ht-act ht-act-disabled" title="Доставка принята, редактирование невозможно"><BkIcon name="edit" size="sm"/></span>
                <button class="ht-act" title="Скопировать" @click="copyOrder(order)"><BkIcon name="copy" size="sm"/></button>
                <button class="ht-act" title="Ссылка" @click="copyOrderLink(order)"><BkIcon name="link" size="sm"/></button>
                <button class="ht-act" title="История изменений" @click="openLogModal(order.id, 'order')"><BkIcon name="note" size="sm"/></button>
                <button v-if="!isViewer" class="ht-act ht-act-danger" title="Удалить" @click="deleteOrder(order)"><BkIcon name="delete" size="sm"/></button>
              </td>
            </tr>
        </tbody>
      </table>
      <div class="ht-load-more-wrap">
        <span class="ht-shown-count">Показано {{ sortedOrders.length }} заказов</span>
        <button v-if="historyStore.hasMoreOrders" class="ht-load-more-btn" :disabled="historyStore.loadingMore" @click="loadMore">
          <BurgerSpinner v-if="historyStore.loadingMore" size="xs" />
          <template v-else>Загрузить ещё</template>
        </button>
      </div>
    </div>

    <!-- PLANS TABLE -->
    <div v-else-if="filterType === 'plans'" class="ht-wrap">
      <table class="ht-table">
        <thead>
          <tr>
            <th class="ht-th ht-th-supplier">Поставщик</th>
            <th class="ht-th">Период</th>
            <th class="ht-th">Примечание</th>
            <th class="ht-th ht-th-center">Автор</th>
            <th class="ht-th ht-th-center">Создан</th>
            <th class="ht-th ht-th-center">Действия</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="plan in filteredPlans" :key="plan.id" class="ht-row" :class="{ 'ht-row-selected': drawerPlan?.id === plan.id }" @click="openPlanDrawer(plan)">
              <td class="ht-td ht-td-supplier">
                <span class="ht-supplier-dot" :style="{ background: supplierColor(plan.supplier) }"></span>
                {{ plan.supplier || '—' }}
              </td>
              <td class="ht-td">{{ plan.period_type === 'weeks' ? plan.period_count + ' нед.' : plan.period_count + ' мес.' }}</td>
              <td class="ht-td ht-td-note"><span v-if="plan.note" :title="plan.note">{{ plan.note }}</span></td>
              <td class="ht-td ht-td-center">{{ plan.created_by || '—' }}</td>
              <td class="ht-td ht-td-center ht-td-created">{{ formatDate(plan.created_at) }}</td>
              <td class="ht-td ht-td-center" @click.stop>
                <button class="ht-act" title="Просмотр" @click="viewPlan(plan)"><BkIcon name="eye" size="sm"/></button>
                <button v-if="!isViewer" class="ht-act" title="Редактировать" @click="loadPlan(plan)"><BkIcon name="edit" size="sm"/></button>
                <button class="ht-act" title="Ссылка" @click="copyPlanLink(plan)"><BkIcon name="link" size="sm"/></button>
                <button class="ht-act" title="История изменений" @click="openLogModal(plan.id, 'plan')"><BkIcon name="note" size="sm"/></button>
                <button v-if="!isViewer" class="ht-act ht-act-danger" title="Удалить" @click="deletePlan(plan)"><BkIcon name="delete" size="sm"/></button>
              </td>
            </tr>
        </tbody>
      </table>
      <div class="ht-load-more-wrap">
        <span class="ht-shown-count">Показано {{ filteredPlans.length }} планов</span>
        <button v-if="historyStore.hasMorePlans" class="ht-load-more-btn" :disabled="historyStore.loadingMore" @click="loadMorePlansBtn">
          <BurgerSpinner v-if="historyStore.loadingMore" size="xs" />
          <template v-else>Загрузить ещё</template>
        </button>
      </div>
    </div>

    <AuditLogModal :show="logModal.show" :loading="logModal.loading" :entries="logModal.entries" @close="logModal.show = false" />

    <ConfirmModal
      v-if="confirmModal.show"
      :title="confirmModal.title"
      :message="confirmModal.message"
      @confirm="onConfirmOk"
      @cancel="onConfirmCancel"
    />

    <!-- Order Drawer -->
    <Teleport to="body">
      <Transition name="drawer">
        <div v-if="drawerOrder" class="drawer-backdrop" @click.self="drawerOrder = null">
          <div class="drawer-panel">
            <div class="drawer-header">
              <div>
                <div class="drawer-title">{{ drawerOrder.supplier }}</div>
                <div class="drawer-subtitle">
                  <span class="ht-date-day">{{ dayOfWeek(drawerOrder.delivery_date) }}</span>
                  {{ formatDateShort(drawerOrder.delivery_date) }}
                  <span v-if="drawerOrder.created_by" class="drawer-author">{{ drawerOrder.created_by }}</span>
                </div>
              </div>
              <button class="drawer-close" @click="drawerOrder = null"><BkIcon name="close" size="sm"/></button>
            </div>
            <div v-if="drawerOrder.note" class="drawer-note">{{ drawerOrder.note }}</div>
            <div class="drawer-body">
              <table class="ht-items-table" v-if="orderItemsFiltered(drawerOrder).length">
                <thead>
                  <tr><th>#</th><th>Артикул</th><th>Наименование</th><th>Коробки</th><th>Штуки</th></tr>
                </thead>
                <tbody>
                  <tr v-for="(item, idx) in orderItemsFiltered(drawerOrder)" :key="item.sku || item.name">
                    <td class="ht-items-num">{{ idx + 1 }}</td>
                    <td class="ht-items-sku">{{ item.sku || '—' }}</td>
                    <td class="ht-items-name">{{ item.name }}</td>
                    <td class="ht-items-qty">{{ Math.round(item.qty_boxes) }}</td>
                    <td class="ht-items-qty">{{ nf.format(Math.round(item.qty_boxes * (item.qty_per_box || 1))) }}</td>
                  </tr>
                </tbody>
              </table>
              <div v-else class="drawer-empty">Нет позиций с заказом</div>
            </div>
            <div class="drawer-footer">
              <span class="drawer-count">{{ orderItemsFiltered(drawerOrder).length }} позиций</span>
            </div>
          </div>
        </div>
      </Transition>

      <!-- Plan Drawer -->
      <Transition name="drawer">
        <div v-if="drawerPlan" class="drawer-backdrop" @click.self="drawerPlan = null">
          <div class="drawer-panel">
            <div class="drawer-header">
              <div>
                <div class="drawer-title">{{ drawerPlan.supplier || '—' }}</div>
                <div class="drawer-subtitle">
                  {{ drawerPlan.period_type === 'weeks' ? drawerPlan.period_count + ' нед.' : drawerPlan.period_count + ' мес.' }}
                  <span v-if="drawerPlan.created_by" class="drawer-author">{{ drawerPlan.created_by }}</span>
                </div>
              </div>
              <button class="drawer-close" @click="drawerPlan = null"><BkIcon name="close" size="sm"/></button>
            </div>
            <div v-if="drawerPlan.note" class="drawer-note">{{ drawerPlan.note }}</div>
            <div class="drawer-body">
              <div v-for="item in planItemsFiltered(drawerPlan)" :key="item.sku || item.name" class="drawer-plan-item">
                <span class="drawer-plan-sku" v-if="item.sku">{{ item.sku }}</span>
                <span class="drawer-plan-name">{{ item.name }}</span>
                <span class="drawer-plan-qty">{{ planItemTotalBoxes(item) }} кор</span>
              </div>
              <div v-if="!planItemsFiltered(drawerPlan).length" class="drawer-empty">Нет позиций</div>
            </div>
            <div class="drawer-footer">
              <span class="drawer-count">{{ planItemsFiltered(drawerPlan).length }} позиций</span>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <CompareOrdersModal
      v-if="showCompareModal && compareOrderA && compareOrderB"
      :order-a="compareOrderA"
      :order-b="compareOrderB"
      @close="showCompareModal = false"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useHistoryStore } from '@/stores/historyStore.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useSupplierStore } from '@/stores/supplierStore.js';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import { useDraftStore } from '@/stores/draftStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { db } from '@/lib/apiClient.js';
import { copyToClipboard, getQpb, getMultiplicity } from '@/lib/utils.js';
import { useConfirm } from '@/composables/useConfirm.js';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';
import AuditLogModal from '@/components/modals/AuditLogModal.vue';
import BkIcon from '@/components/ui/BkIcon.vue';
import CompareOrdersModal from '@/components/modals/CompareOrdersModal.vue';

const historyStore  = useHistoryStore();
const orderStore    = useOrderStore();
const supplierStore = useSupplierStore();
const draftStore    = useDraftStore();
const toast         = useToastStore();
const userStore     = useUserStore();
const router        = useRouter();

const isViewer = computed(() => userStore.isViewer);
const filterType        = ref('orders');
const filterSupplier    = ref('');
const filterAuthor      = ref('');
const filterDateFrom    = ref('');
const filterDateTo      = ref('');
const drawerOrder       = ref(null);
const drawerPlan        = ref(null);
const logModal          = ref({ show: false, loading: false, entries: [], type: '' });
const { confirmModal, confirm: confirmAction, onConfirm: onConfirmOk, onCancel: onConfirmCancel } = useConfirm();

const sortKey = ref('delivery_date');
const sortAsc = ref(false);
const nf = new Intl.NumberFormat('ru-RU');
const searchQuery = ref('');
const compareMode = ref(false);
const compareSelection = ref([]);
const showCompareModal = ref(false);

function toggleCompare() {
  compareMode.value = !compareMode.value;
  compareSelection.value = [];
}
function toggleCompareSelect(order) {
  const idx = compareSelection.value.indexOf(order.id);
  if (idx >= 0) compareSelection.value.splice(idx, 1);
  else if (compareSelection.value.length < 2) compareSelection.value.push(order.id);
}
function openCompare() { showCompareModal.value = true; }
const compareOrderA = computed(() => historyStore.orders.find(o => o.id === compareSelection.value[0]));
const compareOrderB = computed(() => historyStore.orders.find(o => o.id === compareSelection.value[1]));

let searchTimer = null;
function onSearchInput() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => load(), 500);
}

const suppliers = computed(() => supplierStore.getSuppliersForEntity(orderStore.settings.legalEntity));
const rows = computed(() => {
  const list = filterType.value === 'orders' ? historyStore.orders : historyStore.plans;
  if (!filterAuthor.value) return list;
  return list.filter(o => o.created_by === filterAuthor.value);
});
const uniqueAuthors = computed(() => {
  const list = filterType.value === 'orders' ? historyStore.orders : historyStore.plans;
  const authors = [...new Set(list.map(o => o.created_by).filter(Boolean))];
  authors.sort((a, b) => a.localeCompare(b, 'ru'));
  return authors;
});

// --- Sorting ---
function toggleSort(key) {
  if (sortKey.value === key) sortAsc.value = !sortAsc.value;
  else { sortKey.value = key; sortAsc.value = key === 'supplier'; }
}
function sortIcon(key) {
  if (sortKey.value !== key) return '⇅';
  return sortAsc.value ? '↑' : '↓';
}
const sortedOrders = computed(() => {
  let arr = [...historyStore.orders];
  if (filterAuthor.value) arr = arr.filter(o => o.created_by === filterAuthor.value);
  const k = sortKey.value;
  const dir = sortAsc.value ? 1 : -1;
  arr.sort((a, b) => {
    const va = a[k] || '', vb = b[k] || '';
    if (va < vb) return -1 * dir;
    if (va > vb) return 1 * dir;
    return 0;
  });
  return arr;
});
const filteredPlans = computed(() => {
  if (!filterAuthor.value) return historyStore.plans;
  return historyStore.plans.filter(p => p.created_by === filterAuthor.value);
});

// --- Supplier colors ---
const PALETTE = ['#F5A623','#4CAF50','#2196F3','#9C27B0','#F44336','#00BCD4','#FF5722','#607D8B','#E91E63','#795548'];
const colorMap = computed(() => {
  const map = {};
  const allS = [...new Set([...historyStore.orders.map(o => o.supplier), ...historyStore.plans.map(p => p.supplier)].filter(Boolean))].sort();
  allS.forEach((s, i) => { map[s] = PALETTE[i % PALETTE.length]; });
  return map;
});
function supplierColor(name) { return colorMap.value[name] || '#999'; }

// --- Formatting ---
function formatDateShort(str) {
  if (!str) return '';
  return new Date(str).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}
function formatDate(str) {
  if (!str) return '';
  return new Date(str).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}
function formatDateTime(str) {
  if (!str) return '';
  const d = new Date(str);
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' }) + ' ' +
         d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}
function dayOfWeek(str) {
  if (!str) return '';
  return new Date(str).toLocaleDateString('ru-RU', { weekday: 'short' });
}
function orderItemsFiltered(order) {
  return (order.order_items || []).filter(i => i.qty_boxes && Math.round(i.qty_boxes) > 0);
}

// --- Log modal ---
async function openLogModal(entityId, entityType) {
  logModal.value = { show: true, loading: true, entries: [], type: entityType };
  const { data } = await db.from('audit_log').select('*')
    .eq('entity_id', entityId).eq('entity_type', entityType)
    .order('created_at', { ascending: false }).limit(50);
  if (!logModal.value.show) return;
  logModal.value.entries = data || [];
  logModal.value.loading = false;
}

// --- Drawer Escape ---
function onKey(e) {
  if (e.key === 'Escape') {
    if (drawerOrder.value) { drawerOrder.value = null; return; }
    if (drawerPlan.value) { drawerPlan.value = null; }
  }
}
onUnmounted(() => { document.removeEventListener('keydown', onKey); clearTimeout(searchTimer); });

// --- Data ---
onMounted(async () => {
  document.addEventListener('keydown', onKey);
  await supplierStore.loadSuppliers(orderStore.settings.legalEntity);
  await load();
});
watch(() => orderStore.settings.legalEntity, async () => {
  await supplierStore.loadSuppliers(orderStore.settings.legalEntity);
  await load();
});
async function load() {
  await historyStore.loadOrders({ legalEntity: orderStore.settings.legalEntity, supplier: filterSupplier.value, type: filterType.value, dateFrom: filterDateFrom.value, dateTo: filterDateTo.value, author: filterAuthor.value, search: filterType.value === 'orders' ? searchQuery.value : '' });
}
async function loadMore() {
  await historyStore.loadMoreOrders({ legalEntity: orderStore.settings.legalEntity, supplier: filterSupplier.value, dateFrom: filterDateFrom.value, dateTo: filterDateTo.value, author: filterAuthor.value, search: searchQuery.value });
}
async function loadMorePlansBtn() {
  await historyStore.loadMorePlans({ legalEntity: orderStore.settings.legalEntity, supplier: filterSupplier.value, dateFrom: filterDateFrom.value, dateTo: filterDateTo.value });
}

// --- Actions ---
async function openOrderDrawer(order) {
  if (drawerOrder.value?.id === order.id) { drawerOrder.value = null; return; }
  drawerOrder.value = order;
  // Подгрузка multiplicity из products для старых заказов (fallback)
  if (!order?.order_items?.length) return;
  const needFetch = order.order_items.some(i => i.multiplicity == null && i.sku);
  if (!needFetch) return;
  const skus = order.order_items.map(i => i.sku).filter(Boolean);
  if (!skus.length) return;
  const { data } = await db.from('products').select('sku, multiplicity').in('sku', skus);
  if (!data || drawerOrder.value?.id !== order.id) return;
  const multMap = Object.fromEntries(data.map(p => [p.sku, p.multiplicity || 1]));
  order.order_items.forEach(i => { if (i.multiplicity == null && i.sku) i.multiplicity = multMap[i.sku] || 1; });
}
function openPlanDrawer(plan) {
  if (drawerPlan.value?.id === plan.id) { drawerPlan.value = null; return; }
  drawerPlan.value = plan;
}

async function viewOrder(order) {
  const ok = await confirmAction('Загрузить заказ?', `${order.supplier} от ${formatDate(order.delivery_date)} — просмотр`);
  if (!ok) return;
  router.push({ name: 'order', query: { orderId: order.id, mode: 'view' } });
}
async function editOrder(order) {
  // Проверяем блокировку
  const { data: lock } = await db.rpc('check_order_lock', { order_id: order.id, user_name: userStore.currentUser?.name || '' });
  if (lock?.locked) {
    toast.warning('Заказ заблокирован', `Сейчас редактирует: ${lock.locked_by}`);
    return;
  }
  const ok = await confirmAction('Редактировать заказ?', 'При сохранении — обновится поверх старого.');
  if (!ok) return;
  router.push({ name: 'order', query: { orderId: order.id, mode: 'edit' } });
}
async function copyOrderLink(order) {
  const url = `${window.location.origin}/order?orderId=${order.id}&mode=view`;
  await copyToClipboard(url);
  toast.success('Ссылка скопирована', '');
}
async function copyPlanLink(plan) {
  const url = `${window.location.origin}/planning?planId=${plan.id}&mode=view`;
  await copyToClipboard(url);
  toast.success('Ссылка скопирована', '');
}
async function copyOrder(order) {
  const deliveryDate = order.delivery_date
    ? new Date(order.delivery_date).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
    : '—';
  const skus = (order.order_items || []).map(i => i.sku).filter(Boolean);
  let productMap = {};
  if (skus.length) {
    const { data } = await db.from('products').select('sku, unit_of_measure, multiplicity, qty_per_box').in('sku', skus);
    if (data) productMap = Object.fromEntries(data.map(p => [p.sku, p]));
  }
  const lines = (order.order_items || []).map(item => {
    const boxes = Math.round(item.qty_boxes || 0);
    if (!boxes) return null;
    const prod = (item.sku && productMap[item.sku]) || {};
    const qpb  = prod.qty_per_box || parseInt(item.qty_per_box) || 1;
    const mult = prod.multiplicity || 1;
    // qty_boxes теперь в учётных → штуки = boxes * qpb, физические = boxes / mult
    const pieces = Math.round(boxes * qpb);
    const physBoxes = Math.ceil(boxes / mult);
    const unit = prod.unit_of_measure || 'шт';
    return `${item.sku ? item.sku + '  ' : ''}${item.name} - ${physBoxes} коробок (${nf.format(pieces)} ${unit})`;
  }).filter(Boolean);
  if (!lines.length) { toast.error('Нет позиций', ''); return; }
  const text = `Добрый день!\nПросьба отгрузить товар для ${order.legal_entity}, дата поставки - ${deliveryDate}:\n\n${lines.join('\n')}\n\nСпасибо!`;
  await copyToClipboard(text);
  toast.success('Скопировано!', `${lines.length} позиций`);
}
async function deleteOrder(order) {
  const ok = await confirmAction('Удалить заказ?', 'Безвозвратно');
  if (!ok) return;
  const result = await historyStore.deleteOrder(order.id);
  if (result.error) toast.error('Ошибка', result.error); else toast.success('Удалён', '');
}
function loadPlan(plan) { router.push({ path: '/planning', query: { planId: plan.id } }); }
function viewPlan(plan) { router.push({ path: '/planning', query: { planId: plan.id, mode: 'view' } }); }
async function deletePlan(plan) {
  const ok = await confirmAction('Удалить план?', plan.supplier);
  if (!ok) return;
  const { error } = await db.from('plans').delete().eq('id', plan.id);
  if (error) { toast.error('Ошибка', ''); return; }
  // Запись об удалении в аудит
  try { await db.from('audit_log').insert({ action: 'plan_deleted', entity_type: 'plan', entity_id: plan.id, user_name: userStore.currentUser?.name || null, details: { supplier: plan.supplier } }); } catch (e) { console.warn('[history] audit log:', e); }
  toast.success('Удалён', ''); await load();
}
function planItemsFiltered(plan) {
  let items;
  try {
    items = typeof plan.items === 'string' ? JSON.parse(plan.items) : (plan.items || []);
  } catch { items = []; }
  return items.filter(i => (i.plan || []).some(p => p.order_boxes > 0));
}
function planItemTotalBoxes(item) { return (item.plan || []).reduce((s, p) => s + (p.order_boxes || 0), 0); }
</script>

<style scoped>
.history-view { padding: 0; }

/* Tab switcher */
.ht-tabs {
  display: inline-flex; border: 1.5px solid var(--border); border-radius: 8px;
  overflow: hidden; background: var(--bg);
}
.ht-tab {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 5px 16px; border: none; background: transparent;
  font-size: 12px; font-weight: 600; font-family: inherit;
  color: var(--text-muted); cursor: pointer; transition: all 0.15s;
  white-space: nowrap; line-height: 1;
}
.ht-tab:first-child { border-right: 1.5px solid var(--border); }
.ht-tab:hover { color: var(--text); background: rgba(0,0,0,0.02); }
.ht-tab.active {
  background: var(--bk-orange); color: white;
  box-shadow: 0 1px 4px rgba(245,166,35,0.3);
}
.ht-tab .bk-icon { flex-shrink: 0; }

/* Filters */
.ht-filter select {
  padding: 5px 10px; border: 1px solid var(--border); border-radius: 6px;
  font-size: 12px; font-weight: 600; font-family: inherit; color: var(--text);
  background: white; cursor: pointer;
}
.ht-filter select:focus { border-color: var(--bk-orange); outline: none; }

/* Table wrapper */
.ht-wrap { overflow-x: auto; border-radius: 10px; border: 1px solid var(--border); background: white; }

/* Table */
.ht-table { width: 100%; border-collapse: collapse; font-size: 13px; }

/* Header */
.ht-th {
  padding: 8px 10px; text-align: left; font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.4px; color: var(--text-muted);
  background: var(--bg); border-bottom: 2px solid var(--border);
  white-space: nowrap; position: sticky; top: 0; z-index: 1;
}
.ht-th-sort { cursor: pointer; user-select: none; }
.ht-th-sort:hover { color: var(--text); }
.ht-sort-icon { font-size: 10px; opacity: 0.5; margin-left: 2px; }
.ht-th-center { text-align: center; }
.ht-th-supplier { padding-left: 18px; }

/* Rows */
.ht-row { cursor: pointer; transition: background 0.1s; border-bottom: 1px solid var(--border-light); }
.ht-row:hover { background: #FFFBF5; }
.ht-row-selected { background: #FFF3E0; }
.ht-row:last-child { border-bottom: none; }

/* Cells */
.ht-td { padding: 7px 10px; vertical-align: middle; color: var(--text); }
.ht-td-date { white-space: nowrap; width: 130px; }
.ht-date-day { font-size: 10px; color: var(--text-muted); margin-right: 4px; text-transform: capitalize; }
.ht-date-main { font-weight: 700; color: var(--text); }
.ht-td-supplier { font-weight: 600; white-space: nowrap; text-align: left; padding-left: 18px; color: var(--bk-brown); }
.ht-supplier-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 5px; vertical-align: middle; }
.ht-td-note { font-size: 12px; color: var(--text-secondary); max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: left; }
.ht-td-center { text-align: center; font-size: 12px; color: var(--text-secondary); white-space: nowrap; }
.ht-td-created { font-size: 11px; color: var(--text-muted); }

/* Action buttons */
.ht-act {
  background: none; border: 1px solid transparent; border-radius: 4px;
  padding: 3px 5px; cursor: pointer; opacity: 0.5; transition: all 0.15s;
}
.ht-act:hover { opacity: 1; background: var(--bg); border-color: var(--border); }
.ht-act-danger:hover { background: #FEF2F2; }
.ht-act-disabled { opacity: 0.25; cursor: not-allowed; pointer-events: auto; }
.ht-act-disabled:hover { opacity: 0.3; background: none; border-color: transparent; }

/* ═══ DRAWER ═══ */
.drawer-backdrop {
  position: fixed; inset: 0; z-index: 1000;
  background: rgba(0,0,0,0.25); display: flex; justify-content: flex-end;
}
.drawer-panel {
  width: 420px; max-width: 90vw; height: 100%;
  background: white; box-shadow: -4px 0 24px rgba(0,0,0,0.12);
  display: flex; flex-direction: column; overflow: hidden;
}
.drawer-header {
  display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;
  padding: 20px 20px 12px; border-bottom: 1px solid var(--border-light);
}
.drawer-title { font-size: 16px; font-weight: 700; color: var(--bk-brown); }
.drawer-subtitle { font-size: 12px; color: var(--text-muted); margin-top: 2px; display: flex; align-items: center; gap: 6px; }
.drawer-author { padding: 1px 8px; background: var(--bg); border-radius: 10px; font-weight: 600; font-size: 11px; color: var(--text-secondary); }
.drawer-close {
  background: none; border: 1px solid var(--border); border-radius: 6px;
  padding: 4px 6px; cursor: pointer; opacity: 0.6; transition: all 0.15s; flex-shrink: 0;
}
.drawer-close:hover { opacity: 1; background: var(--bg); }
.drawer-note {
  padding: 8px 20px; font-size: 12px; color: var(--text-secondary);
  font-style: italic; background: #FAFAF7; border-bottom: 1px solid var(--border-light);
}
.drawer-body { flex: 1; overflow-y: auto; padding: 12px 16px; }
.drawer-empty { color: var(--text-muted); font-size: 12px; padding: 16px 4px; text-align: center; }
.drawer-footer {
  padding: 10px 20px; border-top: 1px solid var(--border-light);
  font-size: 12px; color: var(--text-muted); font-weight: 500;
}
.drawer-count { font-weight: 600; }

/* Drawer plan items */
.drawer-plan-item {
  display: flex; align-items: baseline; gap: 6px;
  padding: 4px 0; border-bottom: 1px solid var(--border-light); font-size: 12px;
}
.drawer-plan-item:last-child { border-bottom: none; }
.drawer-plan-sku { font-weight: 700; color: var(--text-muted); font-size: 11px; min-width: 60px; }
.drawer-plan-name { flex: 1; color: var(--text); }
.drawer-plan-qty { font-weight: 700; color: var(--bk-brown); white-space: nowrap; }

/* Drawer transition */
.drawer-enter-active, .drawer-leave-active { transition: opacity 0.2s ease; }
.drawer-enter-active .drawer-panel, .drawer-leave-active .drawer-panel { transition: transform 0.25s ease; }
.drawer-enter-from, .drawer-leave-to { opacity: 0; }
.drawer-enter-from .drawer-panel, .drawer-leave-to .drawer-panel { transform: translateX(100%); }

/* Items table */
.ht-items-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.ht-items-table th {
  padding: 4px 8px; text-align: left; font-size: 10px; font-weight: 700;
  text-transform: uppercase; color: var(--text-muted); border-bottom: 1px solid var(--border);
}
.ht-items-table td { padding: 3px 8px; border-bottom: 1px solid var(--border-light); }
.ht-items-num { width: 30px; color: var(--text-muted); font-size: 11px; }
.ht-items-sku { font-weight: 600; color: var(--text-muted); font-size: 11px; width: 80px; }
.ht-items-name { color: var(--text); }
.ht-items-qty { font-weight: 700; text-align: right; width: 60px; color: var(--bk-brown); }

/* Load more */
.ht-load-more-wrap {
  display: flex; align-items: center; justify-content: center; gap: 12px;
  padding: 12px 16px; border-top: 1px solid var(--border-light);
}
.ht-shown-count {
  font-size: 12px; color: var(--text-muted); font-weight: 500;
}
.ht-load-more-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 20px; border: 1.5px solid var(--bk-orange); border-radius: 8px;
  background: transparent; color: var(--bk-orange);
  font-size: 13px; font-weight: 700; font-family: inherit;
  cursor: pointer; transition: all 0.15s;
}
.ht-load-more-btn:hover:not(:disabled) { background: var(--bk-orange); color: white; }
.ht-load-more-btn:disabled { opacity: 0.6; cursor: wait; }

@media (max-width: 768px) {
  .ht-tabs { font-size: 11px; }
  .ht-tab { padding: 5px 10px; }

  /* Card-based layout for history table */
  .ht-table thead { display: none; }
  .ht-table, .ht-table tbody { display: block; width: 100%; }

  .ht-row {
    display: block;
    border-radius: 10px;
    border: 1px solid var(--border);
    margin-bottom: 8px;
    padding: 10px 12px;
    position: relative;
    background: var(--card);
  }
  .ht-row:last-child { border-bottom: 1px solid var(--border); }

  .ht-td { display: block; padding: 1px 0; border-bottom: none; }

  /* Supplier — prominent */
  .ht-td-supplier {
    font-size: 14px;
    font-weight: 700;
    padding-right: 90px;
  }

  /* Date — top-right */
  .ht-td-date {
    position: absolute;
    top: 10px;
    right: 12px;
    width: auto;
    font-size: 12px;
  }

  /* Author — small */
  .ht-td-center:not(.ht-td-created) { font-size: 11px; color: var(--text-muted); }

  /* Hide note and created_at */
  .ht-td-created, .ht-td-note { display: none; }

  /* Actions — bottom row, always visible */
  .ht-td-center:last-child {
    display: flex;
    gap: 4px;
    margin-top: 6px;
    padding-top: 6px;
    border-top: 1px solid var(--border-light);
    text-align: left;
  }
  .ht-act { opacity: 1; min-height: 36px; min-width: 36px; }

  /* Drawer full-width */
  .drawer-panel { width: 100% !important; max-width: 100% !important; }

  /* Filters wrap */
  .page-header { flex-wrap: wrap; gap: 8px; }
  .ht-filter select { font-size: 14px; min-height: 36px; }
}

@media (max-width: 480px) {
  .page-header { flex-direction: column; align-items: stretch !important; }
  .page-header > div { flex-direction: column; }
  .ht-filter { width: 100%; }
  .ht-filter select { width: 100%; }
  .ht-tabs { width: 100%; justify-content: stretch; }
  .ht-tab { flex: 1; text-align: center; }
}
</style>
