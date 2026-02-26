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
          <select v-model="filterAuthor">
            <option value="">Все авторы</option>
            <option v-for="a in uniqueAuthors" :key="a" :value="a">{{ a }}</option>
          </select>
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
          <template v-for="order in sortedOrders" :key="order.id">
            <tr class="ht-row" :class="{ 'ht-row-expanded': expandedOrders.has(order.id) }" @click="toggleItems(order.id)">
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
                <button v-if="!isViewer" class="ht-act" title="Редактировать" @click="editOrder(order)"><BkIcon name="edit" size="sm"/></button>
                <button class="ht-act" title="Скопировать" @click="copyOrder(order)"><BkIcon name="copy" size="sm"/></button>
                <button class="ht-act" title="Ссылка" @click="copyOrderLink(order)"><BkIcon name="link" size="sm"/></button>
                <button class="ht-act" title="Лог" @click="openLogModal(order.id, 'order')"><BkIcon name="note" size="sm"/></button>
                <button v-if="!isViewer" class="ht-act ht-act-danger" title="Удалить" @click="deleteOrder(order)"><BkIcon name="delete" size="sm"/></button>
              </td>
            </tr>
            <!-- Expanded: items (note removed — already shown in table column) -->
            <tr v-if="expandedOrders.has(order.id)" class="ht-detail-row">
              <td :colspan="6" class="ht-detail-cell">
                <div class="ht-detail-inner">
                  <table class="ht-items-table" v-if="orderItemsFiltered(order).length">
                    <thead>
                      <tr><th>#</th><th>Артикул</th><th>Наименование</th><th>Коробки</th><th>Штуки</th></tr>
                    </thead>
                    <tbody>
                      <tr v-for="(item, idx) in orderItemsFiltered(order)" :key="item.sku || item.name">
                        <td class="ht-items-num">{{ idx + 1 }}</td>
                        <td class="ht-items-sku">{{ item.sku || '—' }}</td>
                        <td class="ht-items-name">{{ item.name }}</td>
                        <td class="ht-items-qty">{{ Math.round(item.qty_boxes) }}</td>
                        <td class="ht-items-qty">{{ nf.format(Math.round(item.qty_boxes * (item.qty_per_box || 1) * (item.multiplicity || 1))) }}</td>
                      </tr>
                    </tbody>
                  </table>
                  <div v-else style="color:var(--text-muted);font-size:12px;padding:8px;">Нет позиций с заказом</div>
                </div>
              </td>
            </tr>
          </template>
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
          <template v-for="plan in filteredPlans" :key="plan.id">
            <tr class="ht-row" @click="togglePlanItems(plan.id)">
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
                <button class="ht-act" title="Лог" @click="openLogModal(plan.id, 'plan')"><BkIcon name="note" size="sm"/></button>
                <button v-if="!isViewer" class="ht-act ht-act-danger" title="Удалить" @click="deletePlan(plan)"><BkIcon name="delete" size="sm"/></button>
              </td>
            </tr>
            <tr v-if="expandedPlans.has(plan.id)" class="ht-detail-row">
              <td :colspan="6" class="ht-detail-cell">
                <div class="ht-detail-inner">
                  <div v-for="item in planItemsFiltered(plan)" :key="item.sku || item.name" style="font-size:12px;padding:2px 0;">
                    <b v-if="item.sku">{{ item.sku }}</b> {{ item.name }} — {{ planItemTotalBoxes(item) }} кор
                  </div>
                  <div v-if="!planItemsFiltered(plan).length" style="color:var(--text-muted);font-size:12px;">Нет позиций</div>
                </div>
              </td>
            </tr>
          </template>
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

    <!-- LOG MODAL -->
    <Teleport to="body">
      <div v-if="logModal.show" class="modal" @click.self="logModal.show = false">
        <div class="modal-box log-modal-box">
          <div class="modal-header">
            <h2><BkIcon name="note" size="sm"/> Лог изменений</h2>
            <button class="modal-close" @click="logModal.show = false"><BkIcon name="close" size="sm"/></button>
          </div>
          <div class="log-modal-body">
            <div v-if="logModal.loading" style="text-align:center;padding:24px;"><BurgerSpinner size="sm" /></div>
            <div v-else-if="!logModal.entries.length" style="text-align:center;padding:24px;color:var(--text-muted);font-size:13px;">Нет записей в логе</div>
            <div v-else class="log-entries">
              <div v-for="log in logModal.entries" :key="log.id" class="log-entry">
                <div class="log-entry-head">
                  <span class="log-badge" :class="logBadgeClass(log.action)">{{ logBadgeLabel(log.action) }}</span>
                  <span class="log-author">{{ log.user_name || '—' }}</span>
                  <span class="log-date">{{ formatDateTime(log.created_at) }}</span>
                </div>
                <!-- Param changes inline -->
                <div v-if="log.details?.param_changes?.length" class="log-params">
                  <span v-for="(pc, pi) in log.details.param_changes" :key="pi" class="log-param-chip">
                    {{ pc.label }}: {{ pc.from }} → {{ pc.to }}
                  </span>
                </div>
                <div v-if="log.details?.note" class="log-note-line">📝 {{ log.details.note }}</div>
                <div v-if="log.details?.items_count" class="log-meta">{{ log.details.items_count }} позиций</div>
                <!-- Item changes compact -->
                <div v-if="log.details?.changes?.length" class="log-changes">
                  <span v-for="(c, ci) in log.details.changes" :key="ci" class="log-ch-chip" :class="{ 'log-ch-add': c.type==='added', 'log-ch-del': c.type==='removed', 'log-ch-upd': c.type==='changed' }">
                    <template v-if="c.type === 'added'">+ {{ c.item }} {{ c.boxes }}кор</template>
                    <template v-else-if="c.type === 'removed'">− {{ c.item }} {{ c.boxes }}кор</template>
                    <template v-else>{{ c.item }}: {{ c.diffs?.join(', ') }}</template>
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <ConfirmModal
      v-if="confirmModal.show"
      :title="confirmModal.title"
      :message="confirmModal.message"
      @confirm="confirmModal.resolve(true); confirmModal.show = false"
      @cancel="confirmModal.resolve(false); confirmModal.show = false"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
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
import ConfirmModal from '@/components/modals/ConfirmModal.vue';
import BkIcon from '@/components/ui/BkIcon.vue';

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
const expandedOrders    = ref(new Set());
const expandedPlans     = ref(new Set());
const logModal          = ref({ show: false, loading: false, entries: [], type: '' });
const confirmModal      = ref({ show: false, title: '', message: '', resolve: null });

const sortKey = ref('delivery_date');
const sortAsc = ref(false);
const nf = new Intl.NumberFormat('ru-RU');

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
function logBadgeLabel(action) {
  return { order_created:'Создан', order_updated:'Изменён', order_deleted:'Удалён', plan_created:'Создан', plan_updated:'Изменён', plan_deleted:'Удалён' }[action] || action;
}
function logBadgeClass(action) {
  if (action.includes('created')) return 'log-badge-created';
  if (action.includes('updated')) return 'log-badge-updated';
  if (action.includes('deleted')) return 'log-badge-deleted';
  return '';
}

async function openLogModal(entityId, entityType) {
  logModal.value = { show: true, loading: true, entries: [], type: entityType };
  const { data } = await db.from('audit_log').select('*')
    .eq('entity_id', entityId).eq('entity_type', entityType)
    .order('created_at', { ascending: false }).limit(50);
  logModal.value.entries = data || [];
  logModal.value.loading = false;
}

// --- Data ---
onMounted(async () => {
  await supplierStore.loadSuppliers(orderStore.settings.legalEntity);
  await load();
});
watch(() => orderStore.settings.legalEntity, async () => {
  await supplierStore.loadSuppliers(orderStore.settings.legalEntity);
  await load();
});
async function load() {
  await historyStore.loadOrders({ legalEntity: orderStore.settings.legalEntity, supplier: filterSupplier.value, type: filterType.value });
}
async function loadMore() {
  await historyStore.loadMoreOrders({ legalEntity: orderStore.settings.legalEntity, supplier: filterSupplier.value });
}
async function loadMorePlansBtn() {
  await historyStore.loadMorePlans({ legalEntity: orderStore.settings.legalEntity, supplier: filterSupplier.value });
}

// --- Actions ---
async function toggleItems(id) {
  if (expandedOrders.value.has(id)) { expandedOrders.value.delete(id); return; }
  expandedOrders.value.add(id);
  // Подгрузка multiplicity из products для старых заказов (fallback)
  const order = historyStore.orders.find(o => o.id === id);
  if (!order?.order_items?.length) return;
  const needFetch = order.order_items.some(i => i.multiplicity == null && i.sku);
  if (!needFetch) return;
  const skus = order.order_items.map(i => i.sku).filter(Boolean);
  if (!skus.length) return;
  const { data } = await db.from('products').select('sku, multiplicity').in('sku', skus);
  if (!data) return;
  const multMap = Object.fromEntries(data.map(p => [p.sku, p.multiplicity || 1]));
  order.order_items.forEach(i => { if (i.multiplicity == null && i.sku) i.multiplicity = multMap[i.sku] || 1; });
}
function togglePlanItems(id) { expandedPlans.value.has(id) ? expandedPlans.value.delete(id) : expandedPlans.value.add(id); }

async function viewOrder(order) {
  const ok = await confirmAction('Загрузить заказ?', `${order.supplier} от ${formatDate(order.delivery_date)} — просмотр`);
  if (!ok) return;
  router.push({ name: 'order', query: { orderId: order.id, mode: 'view' } });
}
async function editOrder(order) {
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
    const pieces = Math.round(boxes * qpb * mult);
    const unit = prod.unit_of_measure || 'шт';
    return `${item.sku ? item.sku + '  ' : ''}${item.name}, ${nf.format(qpb)} ${unit} - ${boxes} коробок (${nf.format(pieces)} ${unit})`;
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
  // Clean orphan audit entries
  try { await db.from('audit_log').delete().eq('entity_type', 'plan').eq('entity_id', plan.id); } catch {}
  toast.success('Удалён', ''); await load();
}
function planItemsFiltered(plan) {
  const items = typeof plan.items === 'string' ? JSON.parse(plan.items) : (plan.items || []);
  return items.filter(i => (i.plan || []).some(p => p.order_boxes > 0));
}
function planItemTotalBoxes(item) { return (item.plan || []).reduce((s, p) => s + (p.order_boxes || 0), 0); }
function confirmAction(title, message) { return new Promise(resolve => { confirmModal.value = { show: true, title, message, resolve }; }); }
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
.ht-row-expanded { background: #FFF9F0; }
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

/* Detail row */
.ht-detail-row td { padding: 0 !important; }
.ht-detail-cell { background: #FAFAF7; }
.ht-detail-inner { padding: 8px 16px 12px; border-top: 1px dashed var(--border); }

/* Note */
.ht-note { font-size: 12px; color: var(--text-secondary); font-style: italic; padding: 4px 0 8px; display: flex; align-items: center; gap: 4px; }

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

/* ═══ LOG MODAL ═══ */
.log-modal-box { max-width: 560px; }
.log-modal-body { max-height: 450px; overflow-y: auto; padding: 0 20px 16px; }
.log-entries { display: flex; flex-direction: column; }
.log-entry { padding: 10px 0; border-bottom: 1px solid var(--border-light); }
.log-entry:last-child { border-bottom: none; }
.log-entry-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.log-badge { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 10px; font-weight: 700; }
.log-badge-created { background: #E8F5E9; color: #2E7D32; }
.log-badge-updated { background: #FFF3E0; color: #E65100; }
.log-badge-deleted { background: #FFEBEE; color: #C62828; }
.log-author { font-weight: 600; font-size: 12px; color: var(--text); }
.log-date { font-size: 11px; color: var(--text-muted); }
.log-note-line { font-size: 11px; color: var(--text-secondary); font-style: italic; margin-top: 3px; }
.log-meta { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
.log-params { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px; }
.log-param-chip {
  display: inline-block; padding: 1px 7px; border-radius: 4px;
  font-size: 11px; background: #EDE7F6; color: #4A148C; font-weight: 500;
}
.log-changes { display: flex; flex-wrap: wrap; gap: 3px; margin-top: 5px; }
.log-ch-chip {
  display: inline-block; padding: 1px 6px; border-radius: 4px;
  font-size: 10px; font-weight: 600; line-height: 1.5;
}
.log-ch-add { background: #E8F5E9; color: #2E7D32; }
.log-ch-del { background: #FFEBEE; color: #C62828; }
.log-ch-upd { background: #FFF8E1; color: #5D4037; }

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
  .ht-td-created, .ht-td-note { display: none; }
  .ht-th:nth-child(3), .ht-th:nth-child(5) { display: none; }
  .ht-tabs { font-size: 11px; }
  .ht-tab { padding: 5px 10px; }
}
</style>
