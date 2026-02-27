<template>
  <div class="order-view" :class="{ 'fullscreen-table': isFullscreen }">

    <div class="page-header">
      <h1 class="page-title">{{ orderStore.pageTitle }}</h1>
      <span v-if="orderStore.viewOnlyMode" class="editing-badge" style="cursor:pointer" @click="exitViewMode"><BkIcon name="eye" size="sm"/> Просмотр</span>
      <button v-if="orderStore.viewOnlyMode && orderStore.editingOrderId" class="btn small" style="margin-left:4px;" @click="openLogModal" title="Лог изменений"><BkIcon name="note" size="sm"/> Лог</button>
      <span v-else-if="orderStore.editingOrderId" class="editing-badge" style="cursor:pointer" @click="exitEditMode"><BkIcon name="edit" size="sm"/> Редактирование</span>
    </div>

    <!-- Viewer заглушка: viewer не может создавать/редактировать заказы -->
    <div v-if="isViewer && !orderStore.viewOnlyMode && !orderStore.editingOrderId" class="viewer-placeholder">
      <div class="viewer-placeholder-inner">
        <BkIcon name="eye" size="lg"/>
        <h2>Режим просмотра</h2>
        <p>Вы можете просматривать сохранённые заказы через раздел <b>История</b>.</p>
        <button class="btn primary" @click="$router.push({ name: 'history' })"><BkIcon name="history" size="sm"/> Перейти в историю</button>
      </div>
    </div>

    <!-- Параметры: кликабельная строка-сводка + раскрывающаяся панель -->
    <div v-if="orderVisible && !(isViewer && !orderStore.viewOnlyMode && !orderStore.editingOrderId)" class="params-block" :class="{ open: settingsExpanded }">
      <div class="params-summary" @click="toggleSettings">
        <BkIcon name="gear" size="sm" class="params-icon"/>
        <span class="ps-chip"><b>{{ orderStore.settings.supplier || 'Не выбран' }}</b></span>
        <span class="ps-chip" :class="{ 'ps-warn': !orderStore.settings.today }">{{ todayDisplay || 'Сегодня?' }}</span>
        <span class="ps-dot">→</span>
        <span class="ps-chip" :class="{ 'ps-warn': !orderStore.settings.deliveryDate }">{{ deliveryDisplay || 'Приход?' }}</span>
        <span class="ps-chip" :class="{ 'ps-warn': !orderStore.settings.safetyDays }">запас {{ orderStore.settings.safetyDays || '?' }} дн.</span>
        <span class="ps-chip">{{ orderStore.settings.unit === 'boxes' ? 'коробки' : 'штуки' }}</span>
        <span class="params-toggle-hint">
          <BkIcon :name="settingsExpanded ? 'chevronUp' : 'chevronDown'" size="xs"/>
        </span>
      </div>
      <div v-if="settingsExpanded" class="params-fields params-fields-animated">
        <div class="pf-group">
          <label>Поставщик</label>
          <select :value="orderStore.settings.supplier" @change="onSupplierChange" :disabled="supplierLoading || orderStore.viewOnlyMode">
            <option value="">Все / свободный</option>
            <option v-for="s in suppliers" :key="s.short_name" :value="s.short_name">{{ s.short_name }}</option>
          </select>
        </div>
        <div class="pf-group">
          <label>Сегодня</label>
          <input type="date" :value="todayStr" :class="{ 'param-required-pulse': !orderStore.settings.today }" @change="onTodayChange" :disabled="orderStore.viewOnlyMode"/>
        </div>
        <div class="pf-group">
          <label>Дата прихода</label>
          <input type="date" :value="deliveryStr" :class="{ 'param-required-pulse': !orderStore.settings.deliveryDate }" @change="onDeliveryChange" :disabled="orderStore.viewOnlyMode"/>
        </div>
        <div class="pf-group">
          <label>Запас (дн.) <small v-if="safetyDateDisplay" style="font-weight:400;color:var(--text-muted);">– {{ safetyDateDisplay }}</small></label>
          <div style="display:flex;gap:4px;align-items:center;">
            <input type="number" :value="orderStore.settings.safetyDays" :class="{ 'param-required-pulse': !orderStore.settings.safetyDays }" @change="onSafetyDaysChange" style="width:60px;" :disabled="orderStore.viewOnlyMode"/>
            <input type="date" :value="safetyDateStr" @change="onSafetyDateChange" :min="deliveryStr" style="flex:1;" :disabled="orderStore.viewOnlyMode"/>
          </div>
        </div>
        <div class="pf-group pf-narrow">
          <label>Период (дн.)</label>
          <input type="number" :value="orderStore.settings.periodDays" @change="(e) => { orderStore.settings.periodDays = +e.target.value || 30; draftStore.save(); }" :disabled="orderStore.viewOnlyMode"/>
        </div>
        <div class="pf-group pf-narrow">
          <label>Единицы</label>
          <select :value="orderStore.settings.unit" @change="onUnitChange" :disabled="orderStore.viewOnlyMode">
            <option value="pieces">Штуки</option>
            <option value="boxes">Коробки</option>
          </select>
        </div>
        <div class="pf-group pf-narrow">
          <label>Транзит</label>
          <select :value="orderStore.settings.hasTransit ? 'true' : 'false'" @change="(e) => { orderStore.settings.hasTransit = e.target.value === 'true'; draftStore.save(); }" :disabled="orderStore.viewOnlyMode">
            <option value="false">Нет</option>
            <option value="true">Да</option>
          </select>
        </div>
        <div v-if="showCollapseHint" class="params-collapse-hint" @click="settingsExpanded = false; showCollapseHint = false;">
          Параметры заполнены — нажмите чтобы свернуть ▲
        </div>
      </div>
    </div>

    <!-- Тулбар: поиск + действия -->
    <div v-if="orderVisible && !(isViewer && !orderStore.viewOnlyMode && !orderStore.editingOrderId)" class="order-toolbar">
      <div class="search-bar" v-if="!orderStore.viewOnlyMode" ref="searchBarRef" style="position:relative;display:flex;align-items:center;gap:8px;">
        <div style="position:relative;display:inline-block;">
          <input type="text" v-model="searchQuery" placeholder="Поиск товара..."
            @input="onSearchInput" ref="searchInputRef" style="width:280px;max-width:360px;padding-right:28px;"/>
          <button v-if="searchQuery" @click="clearSearch"
            style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#999;"><BkIcon name="close" size="xs"/></button>
          <div v-if="searchResults.length || (searchQuery.length >= 2 && searchDone)"
            style="position:absolute;top:100%;left:0;z-index:200;background:#fff;border:1px solid #ddd;border-radius:4px;min-width:320px;max-height:300px;overflow-y:auto;box-shadow:0 4px 12px rgba(0,0,0,.1);font-size:11px;">
            <div v-for="p in searchResults" :key="p.id||p.sku"
              @click="addFromSearch(p)"
              class="search-result-item">
              <b v-if="p.sku">{{ p.sku }}</b> {{ p.name }}
            </div>
            <div v-if="!searchResults.length" style="padding:5px 10px;color:#999;font-size:11px;">Ничего не найдено</div>
          </div>
        </div>
      </div>
      <div class="order-actions">
        <button class="btn small" :disabled="!orderStore.canUndo || orderStore.viewOnlyMode" @click="orderStore.undo" title="Отменить"><BkIcon name="undo" size="sm"/></button>
        <button class="btn small" :disabled="!orderStore.canRedo || orderStore.viewOnlyMode" @click="orderStore.redo" title="Повторить"><BkIcon name="redo" size="sm"/></button>
        <button class="compact-toggle" :class="{ active: compactMode }" @click="toggleCompact" title="Компактный режим"><BkIcon name="menu" size="sm"/> Компакт</button>
        <button class="btn small fullscreen-toggle-btn" @click="isFullscreen = !isFullscreen"><BkIcon :name="isFullscreen ? 'close' : 'eye'" size="sm"/> {{ isFullscreen ? 'Свернуть' : 'Развернуть' }}</button>
        <button class="btn small" :disabled="orderStore.viewOnlyMode" @click="orderStore.applyAllCalculated" title="Все рассчитанные → В заказ"><BkIcon name="add" size="sm"/> Все→Заказ</button>
        <button class="btn small" :disabled="fillLoading || orderStore.viewOnlyMode" @click="fillFromLastOrder" title="Загрузить расход из последнего заказа">
          <BkIcon v-if="fillLoading" name="loading" size="sm"/><BkIcon v-else name="history" size="sm"/> Загрузить расход
        </button>
        <button class="btn small" :disabled="load1cLoading || orderStore.viewOnlyMode" @click="loadFrom1c" title="Загрузить из 1С">
          <BkIcon v-if="load1cLoading" name="loading" size="sm"/><BkIcon v-else name="oneC" size="sm"/> 1С
        </button>
        <button class="btn small" :disabled="importLoading || orderStore.viewOnlyMode" @click="importFromExcel" title="Импорт из файла">
          <BkIcon v-if="importLoading" name="loading" size="sm"/><BkIcon v-else name="import" size="sm"/> Импорт
        </button>
        <button class="btn small danger" :disabled="orderStore.viewOnlyMode" @click="clearOrder" title="Очистить данные"><BkIcon name="delete" size="sm"/> Очистить</button>
      </div>
    </div>

    <!-- Секция заказа -->
    <div v-if="orderVisible && !(isViewer && !orderStore.viewOnlyMode && !orderStore.editingOrderId)" class="order-section" :class="{ 'view-only-mode': orderStore.viewOnlyMode, 'order-locked': !paramsReady && !orderStore.viewOnlyMode }">

      <!-- Overlay when params not filled -->
      <div v-if="!paramsReady && !orderStore.viewOnlyMode" class="order-lock-overlay" @click.stop>
        <div class="order-lock-msg">
          <BkIcon name="warning" size="sm"/>
          Заполните параметры: раскройте панель выше и укажите дату прихода и запас
        </div>
      </div>

      <OrderTable :data-validation-enabled="true" :compact="compactMode" @edit-product="openProductForEdit"/>

      <!-- Кнопки завершения — под таблицей справа -->
      <div class="toolbar-row toolbar-finish">
        <div class="toolbar-spacer"></div>
        <button v-if="!isViewer" class="btn primary" @click="openSaveModal" :disabled="!itemsWithOrderCount || orderStore.viewOnlyMode"><BkIcon name="save" size="sm"/> {{ orderStore.editingOrderId ? 'Обновить заказ' : 'Сохранить' }}</button>
        <button class="btn" @click="copyOrderToClipboard" :disabled="!orderStore.items.length"><BkIcon name="copy" size="sm"/> Скопировать заказ</button>
        <button class="btn" @click="showOrderResult" :disabled="!orderStore.items.length"><BkIcon name="planning" size="sm"/> Результат заказа</button>
        <div style="position:relative;display:inline-block;">
          <button class="btn" @click.stop="showShareDropdown = !showShareDropdown"><BkIcon name="send" size="sm"/> Отправить</button>
          <div v-if="showShareDropdown" class="share-dropdown" style="bottom:100%;top:auto;">
            <button @click.stop="share('whatsapp')"><span class="share-dot" style="background:#25D366"></span>WhatsApp</button>
            <button @click.stop="share('telegram')"><span class="share-dot" style="background:#0088cc"></span>Telegram</button>
            <button @click.stop="share('viber')"><span class="share-dot" style="background:#7360f2"></span>Viber</button>
            <button @click.stop="share('email')"><span class="share-dot" style="background:#8B7355"></span>Email</button>
          </div>
        </div>
        <button class="btn" @click="exportExcel" v-if="orderStore.items.length" :disabled="!itemsWithOrderCount"><BkIcon name="excel" size="sm"/> Excel</button>
      </div>
    </div>

    <!-- Модалки -->
    <SaveOrderModal
      v-if="showSaveModal"
      :supplier="orderStore.settings.supplier"
      :delivery-date="orderStore.settings.deliveryDate"
      :legal-entity="orderStore.settings.legalEntity"
      :items-count="itemsWithOrderCount"
      :lines="saveModalLines"
      :editing-order-id="orderStore.editingOrderId"
      :existing-note="orderStore.settings.note"
      :saving="savingOrder"
      @confirm="onSaveConfirm"
      @cancel="showSaveModal = false"
    />
    <ManualProductModal v-if="showManualModal" :current-supplier="orderStore.settings.supplier" @close="showManualModal = false" @added="onManualAdded"/>
    <EditCardModal v-if="editCardModal.show" :product="editCardModal.product" @close="editCardModal.show = false" @saved="onCardSaved"/>
    <ConfirmModal v-if="confirmModal.show" :title="confirmModal.title" :message="confirmModal.message"
      @confirm="onConfirmOk"
      @cancel="onConfirmCancel"/>
    <AnalogMergeModal v-if="analogMergeModal.show" :merges="analogMergeModal.merges" @apply="onAnalogApply" @skip="onAnalogSkip"/>
    <!-- Log Modal -->
    <Teleport to="body">
      <div v-if="logModal.show" class="modal" @click.self="logModal.show = false">
        <div class="modal-box" style="max-width:560px;">
          <div class="modal-header">
            <h2><BkIcon name="note" size="sm"/> Лог изменений</h2>
            <button class="modal-close" @click="logModal.show = false"><BkIcon name="close" size="sm"/></button>
          </div>
          <div style="max-height:450px;overflow-y:auto;padding:0 20px 16px;">
            <div v-if="logModal.loading" style="text-align:center;padding:24px;color:var(--text-muted);">Загрузка...</div>
            <div v-else-if="!logModal.entries.length" style="text-align:center;padding:24px;color:var(--text-muted);font-size:13px;">Нет записей в логе</div>
            <div v-else class="log-entries">
              <div v-for="log in logModal.entries" :key="log.id" class="log-entry">
                <div class="log-entry-head">
                  <span class="log-badge" :class="logBadgeClass(log.action)">{{ logBadgeLabel(log.action) }}</span>
                  <span class="log-author">{{ log.user_name || '—' }}</span>
                  <span class="log-date">{{ formatLogDate(log.created_at) }}</span>
                </div>
                <div v-if="log.details?.param_changes?.length" class="log-params">
                  <span v-for="(pc, pi) in log.details.param_changes" :key="pi" class="log-param-chip">{{ pc.label }}: {{ pc.from }} → {{ pc.to }}</span>
                </div>
                <div v-if="log.details?.note" class="log-note-line">{{ log.details.note }}</div>
                <div v-if="log.details?.items_count" class="log-meta">{{ log.details.items_count }} позиций</div>
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
    <Teleport to="body">
      <div v-if="orderResultModal.show" class="modal" @click.self="orderResultModal.show = false">
        <div class="modal-box order-result-modal">
          <div class="modal-header">
            <h2><BkIcon name="planning" size="sm"/> Результат заказа</h2>
            <button class="modal-close" @click="orderResultModal.show = false"><BkIcon name="close" size="sm"/></button>
          </div>
          <!-- Result summary cards -->
          <div class="or-summary">
            <div class="or-card">
              <span class="or-card-label">Юр. лицо</span>
              <span class="or-card-value">{{ orderStore.settings.legalEntity || '—' }}</span>
            </div>
            <div class="or-card">
              <span class="or-card-label">Поставщик</span>
              <span class="or-card-value">{{ orderResultModal.supplier || '—' }}</span>
            </div>
            <div class="or-card">
              <span class="or-card-label">Дата поставки</span>
              <span class="or-card-value">{{ orderResultModal.deliveryDate || '—' }}</span>
            </div>
            <div class="or-card or-card-accent">
              <span class="or-card-label">Позиций</span>
              <span class="or-card-value">{{ orderResultModal.lines?.length || 0 }}</span>
            </div>
          </div>
          <!-- Result table -->
          <div class="or-table-wrap">
            <table class="or-table">
              <thead>
                <tr><th>#</th><th>Артикул</th><th>Наименование</th><th>Коробки</th><th>Штуки</th></tr>
              </thead>
              <tbody>
                <tr v-for="(l, idx) in orderResultModal.lines" :key="idx">
                  <td class="or-num">{{ idx + 1 }}</td>
                  <td class="or-sku">{{ l.sku || '—' }}</td>
                  <td class="or-name">{{ l.name }}</td>
                  <td class="or-qty">{{ l.boxes }}</td>
                  <td class="or-qty">{{ nf.format(l.pieces) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div style="display:flex;gap:8px;margin-top:12px;justify-content:flex-end;">
            <button class="btn primary" @click="copyToClipboard(orderResultModal.text); toast.success('Скопировано!', '');"><BkIcon name="copy" size="sm"/> Скопировать</button>
            <button class="btn secondary" @click="orderResultModal.show = false">Закрыть</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useOrderStore } from '@/stores/orderStore.js';
import { useDraftStore } from '@/stores/draftStore.js';
import { useSupplierStore } from '@/stores/supplierStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { db } from '@/lib/apiClient.js';
import { getQpb, getMultiplicity, copyToClipboard, getEntityGroup, applyEntityFilter, toLocalDateStr } from '@/lib/utils.js';
import { saveOrder } from '@/lib/saveOrder.js';
import { importFromFile, applyAnalogMerges } from '@/lib/importStock.js';
import OrderTable from '@/components/order/OrderTable.vue';
import SaveOrderModal from '@/components/modals/SaveOrderModal.vue';
import ManualProductModal from '@/components/modals/ManualProductModal.vue';
import EditCardModal from '@/components/modals/EditCardModal.vue';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';
import AnalogMergeModal from '@/components/modals/AnalogMergeModal.vue';
import { useConfirm } from '@/composables/useConfirm.js';
import BkIcon from '@/components/ui/BkIcon.vue';


const route         = useRoute();
const router        = useRouter();
const orderStore    = useOrderStore();
const draftStore    = useDraftStore();
const supplierStore = useSupplierStore();
const toast         = useToastStore();
const userStore     = useUserStore();

const isViewer = computed(() => userStore.isViewer);
const orderVisible          = ref(true);
const settingsExpanded      = ref(false);
const supplierLoading       = ref(false);
const showShareDropdown     = ref(false);
const showManualModal       = ref(false);
const showSaveModal         = ref(false);
const saveModalLines        = ref([]);
const savingOrder           = ref(false);
const fillLoading           = ref(false);
const load1cLoading         = ref(false);
const importLoading         = ref(false);
const searchQuery           = ref('');
const searchResults         = ref([]);
const searchDone            = ref(false);
const searchInputRef        = ref(null);
const searchBarRef          = ref(null);
const editCardModal         = ref({ show: false, product: null });
const analogMergeModal      = ref({ show: false, merges: [] });
const logModal              = ref({ show: false, loading: false, entries: [] });
const { confirmModal, confirm: confirmAction, onConfirm: onConfirmOk, onCancel: onConfirmCancel } = useConfirm();
const orderResultModal      = ref({ show: false, text: '', supplier: '', deliveryDate: '', lines: [] });
const isFullscreen          = ref(false);
const compactMode           = ref(localStorage.getItem('bk_compact_mode') === '1');
let   searchTimer           = null;
const searchCache           = new Map();
const SEARCH_CACHE_MAX      = 20;

const nf = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });

const todayStr    = computed(() => toLocalDateStr(orderStore.settings.today));
const deliveryStr = computed(() => toLocalDateStr(orderStore.settings.deliveryDate));
const paramsReady = computed(() => orderStore.settings.today && orderStore.settings.deliveryDate && orderStore.settings.safetyDays > 0);
const todayDisplay = computed(() => orderStore.settings.today instanceof Date && !isNaN(orderStore.settings.today) ? orderStore.settings.today.toLocaleDateString('ru-RU', { day:'2-digit', month:'2-digit' }) : '');
const deliveryDisplay = computed(() => orderStore.settings.deliveryDate instanceof Date && !isNaN(orderStore.settings.deliveryDate) ? orderStore.settings.deliveryDate.toLocaleDateString('ru-RU', { day:'2-digit', month:'2-digit' }) : '');

function toggleSettings() {
  if (settingsExpanded.value && !paramsReady.value) return; // не закрывать пока не заполнено
  settingsExpanded.value = !settingsExpanded.value;
}

const safetyDate = computed(() => {
  const delivery = orderStore.settings.deliveryDate;
  const days = orderStore.settings.safetyDays || 0;
  if (!delivery || !(delivery instanceof Date) || isNaN(delivery) || !days) return null;
  const d = new Date(delivery); d.setDate(d.getDate() + days); return d;
});
const safetyDateDisplay = computed(() => safetyDate.value ? safetyDate.value.toLocaleDateString('ru-RU', { day:'2-digit', month:'2-digit', year:'numeric' }) : '');
const safetyDateStr = computed(() => toLocalDateStr(safetyDate.value));

function onSafetyDaysChange(e) {
  const v = +e.target.value || 0;
  orderStore.settings.safetyDays = Math.max(0, v);
  draftStore.save();
}
function onSafetyDateChange(e) {
  const delivery = orderStore.settings.deliveryDate;
  if (!delivery || !(delivery instanceof Date) || isNaN(delivery)) { toast.error('Сначала укажите дату прихода', ''); return; }
  const target = new Date(e.target.value); if (isNaN(target)) return;
  if (target < delivery) { toast.error('Дата запаса не может быть раньше даты прихода', ''); return; }
  orderStore.settings.safetyDays = Math.max(0, Math.round((target - delivery) / 86400000));
  draftStore.save();
}

const suppliers   = computed(() => supplierStore.getSuppliersForEntity(orderStore.settings.legalEntity));

const itemsWithOrderCount = computed(() => {
  const s = orderStore.settings;
  return orderStore.items.filter(item => {
    const qpb = getQpb(item); const mult = getMultiplicity(item);
    const boxes = s.unit === 'boxes' ? item.finalOrder / mult : item.finalOrder / (qpb * mult);
    return Math.ceil(Math.max(0, boxes)) > 0;
  }).length;
});

// Показать подсказку «можно скрыть» когда параметры заполнены
const showCollapseHint = ref(false);
watch(paramsReady, (ready) => {
  if (ready && settingsExpanded.value) { showCollapseHint.value = true; setTimeout(() => { showCollapseHint.value = false; }, 4000); }
});

// ─── Init ──────────────────────────────────────────────────────────────────────
// Перезагружать поставщиков при смене юр. лица в сайдбаре
watch(() => orderStore.settings.legalEntity, async (le) => {
  searchQuery.value = '';
  searchResults.value = [];
  searchDone.value = false;
  clearTimeout(searchTimer);
  searchCache.clear();
  await supplierStore.loadSuppliers(le);
});

onMounted(async () => {
  if (!orderStore.settings.today) orderStore.settings.today = new Date();
  // Авто-открытие параметров если обязательные поля не заполнены
  if (!paramsReady.value) settingsExpanded.value = true;
  await supplierStore.loadSuppliers(orderStore.settings.legalEntity);

  // Загрузка заказа по ID из query params
  if (route.query.orderId) {
    try {
      const { data: order, error } = await db.from('orders').select('*, order_items(*)').eq('id', route.query.orderId).single();
      if (!error && order) {
        const isView = route.query.mode === 'view';
        const isEdit = route.query.mode === 'edit';
        if (order.received_at && isEdit) {
          toast.warning('Доставка выполнена', 'Редактирование принятого заказа невозможно. Открыт в режиме просмотра.');
          await orderStore.loadOrderIntoForm(order, order.legal_entity, false, true);
          draftStore.saveNow();
          orderVisible.value = true;
          return;
        }
        await orderStore.loadOrderIntoForm(order, order.legal_entity, isEdit, isView);
        draftStore.saveNow();
        orderVisible.value = true;
        toast.success('Заказ загружен', isView ? 'Режим просмотра' : (isEdit ? 'Режим редактирования' : ''));
      } else {
        toast.error('Заказ не найден', '');
      }
    } catch (e) {
      toast.error('Ошибка загрузки заказа', e.message || '');
    }
  } else if (route.query.supplier) {
    // Загрузка товаров поставщика (переход из анализа и т.д.)
    const sup = route.query.supplier;
    orderStore.settings.supplier = sup;
    orderStore.items = [];
    supplierLoading.value = true;
    try {
      const { data } = await db.from('products').select('*').eq('supplier', sup).eq('is_active', 1);
      (data || []).forEach(p => orderStore.addItem(p, true));
      await orderStore.restoreItemOrder();
      draftStore.save();
      orderVisible.value = true;
      toast.success('Поставщик загружен', `${sup} — ${orderStore.items.length} товаров`);
    } catch { toast.error('Ошибка', 'Не удалось загрузить товары'); }
    finally { supplierLoading.value = false; }
    router.replace({ name: 'order' });
  } else if (orderStore.items.length > 0) {
    // Если данные уже загружены (из истории/редактирования) — не перезаписываем черновиком
    orderVisible.value = true;
  } else {
    const draft = draftStore.hasDraft();
    if (draft && draft.legalEntity === orderStore.settings.legalEntity) {
      const ok = await confirmAction('Восстановить черновик?', `Найден черновик от ${draft.date} (${draft.itemsCount} позиций). Восстановить?`);
      if (ok) {
        const result = await draftStore.load((le) => supplierStore.loadSuppliers(le));
        if (result?.loaded) {
          orderVisible.value = true;
          toast.info('Черновик загружен', `Восстановлено: ${result.date}`);
        }
      } else {
        draftStore.clear();
      }
    }
  }
  document.addEventListener('click', closeShareDropdown);
  document.addEventListener('click', closeSearchDropdown);
});

onUnmounted(() => {
  document.removeEventListener('click', closeShareDropdown);
  document.removeEventListener('click', closeSearchDropdown);
  clearTimeout(searchTimer);
});

function closeSearchDropdown(e) {
  if (searchBarRef.value && !searchBarRef.value.contains(e.target)) {
    searchResults.value = [];
    searchDone.value = false;
  }
}

// Реактивная навигация: если query изменился когда компонент уже смонтирован
watch(() => route.query.orderId, async (newId) => {
  if (!newId) return;
  try {
    const { data: order, error } = await db.from('orders').select('*, order_items(*)').eq('id', newId).single();
    if (!error && order) {
      const isView = route.query.mode === 'view';
      const isEdit = route.query.mode === 'edit';
      if (order.received_at && isEdit) {
        toast.warning('Доставка выполнена', 'Редактирование принятого заказа невозможно. Открыт в режиме просмотра.');
        await orderStore.loadOrderIntoForm(order, order.legal_entity, false, true);
        orderVisible.value = true;
        return;
      }
      await orderStore.loadOrderIntoForm(order, order.legal_entity, isEdit, isView);
      orderVisible.value = true;
    } else {
      toast.error('Заказ не найден', '');
    }
  } catch (e) {
    toast.error('Ошибка загрузки заказа', e.message || '');
  }
});

function closeShareDropdown(e) {
  if (!e.target.closest('.share-dropdown')) showShareDropdown.value = false;
}

function toggleCompact() {
  compactMode.value = !compactMode.value;
  localStorage.setItem('bk_compact_mode', compactMode.value ? '1' : '0');
}

// ─── Настройки ────────────────────────────────────────────────────────────────


async function onSupplierChange(e) {
  const newSupplier = e.target.value;
  const hasData = orderStore.items.some(i => i.consumptionPeriod > 0 || i.stock > 0 || i.transit > 0 || i.finalOrder > 0);
  if (hasData) {
    const ok = await confirmAction('Сменить поставщика?', 'Текущий заказ с заполненными данными будет сброшен.');
    if (!ok) return;
  }
  orderStore.settings.supplier = newSupplier;
  orderStore.settings.note = '';
  orderStore.items = [];
  searchCache.clear();
  draftStore.save();
  if (!newSupplier) return;
  supplierLoading.value = true;
  try {
    const { data } = await db.from('products').select('*').eq('supplier', newSupplier).eq('is_active', 1);
    (data || []).forEach(p => orderStore.addItem(p, true));
    await orderStore.restoreItemOrder();
    draftStore.save();
    orderVisible.value = true;
    setTimeout(() => searchInputRef.value?.focus(), 100);
  } catch { toast.error('Ошибка', 'Не удалось загрузить товары'); }
  finally { supplierLoading.value = false; }
}

function onTodayChange(e) {
  const d = new Date(e.target.value);
  if (!isNaN(d)) { orderStore.settings.today = d; draftStore.save(); }
}

function onDeliveryChange(e) {
  const base = orderStore.settings.today || new Date();
  if (e.target.value < toLocalDateStr(base)) {
    toast.error('Некорректная дата', 'Дата прихода не может быть раньше сегодняшней');
    e.target.value = deliveryStr.value; return;
  }
  orderStore.settings.deliveryDate = new Date(e.target.value);
  draftStore.save();
}

function onUnitChange(e) {
  const oldUnit = orderStore.settings.unit;
  const newUnit = e.target.value;
  orderStore.settings.unit = newUnit;
  if (oldUnit !== newUnit && orderStore.items.length) {
    orderStore.items.forEach(item => {
      const qpb = getQpb(item);
      if (oldUnit === 'pieces' && newUnit === 'boxes') {
        item.consumptionPeriod = item.consumptionPeriod ? Math.round(item.consumptionPeriod / qpb * 100) / 100 : 0;
        item.stock   = item.stock   ? Math.round(item.stock   / qpb * 100) / 100 : 0;
        item.transit = item.transit ? Math.round(item.transit / qpb * 100) / 100 : 0;
        item.finalOrder = item.finalOrder ? Math.ceil(item.finalOrder / qpb) : 0;
      } else {
        item.consumptionPeriod = Math.round(item.consumptionPeriod * qpb);
        item.stock   = Math.round(item.stock   * qpb);
        item.transit = Math.round(item.transit * qpb);
        item.finalOrder = Math.round(item.finalOrder * qpb);
      }
    });
  }
  draftStore.save();
}

// ─── Сохранение в БД ──────────────────────────────────────────────────────────
function openSaveModal() {
  const { lines } = buildOrderText();
  if (!lines.length) { toast.error('Нет позиций с заказом', ''); return; }
  saveModalLines.value = lines;
  showSaveModal.value = true;
}

async function onSaveConfirm(note) {
  savingOrder.value = true;
  try {
    const result = await saveOrder({
      items:          orderStore.items,
      settings:       orderStore.settings,
      editingOrderId: orderStore.editingOrderId,
      note,
      userName:       userStore.currentUser?.name || null,
    });

    if (result.error) { toast.error('Ошибка сохранения', result.error); return; }

    const label = orderStore.editingOrderId ? 'Заказ обновлён' : 'Заказ сохранён';
    toast.success(label, `Сохранено: ${result.itemsCount} позиций`);

    showSaveModal.value = false;
    orderStore.editingOrderId = null;
    orderStore.settings.note = '';
    draftStore.clear();

    // Сбрасываем данные, оставляем товары
    orderStore.items.forEach(item => {
      item.consumptionPeriod = 0; item.stock = 0; item.transit = 0; item.finalOrder = 0;
    });
    draftStore.save();
  } finally { savingOrder.value = false; }
}

// ─── Подставить расход из прошлого заказа ────────────────────────────────────
async function fillFromLastOrder() {
  if (!orderStore.items.length) { toast.error('Нет товаров', 'Сначала добавьте товары'); return; }
  const supplier = orderStore.settings.supplier;
  if (!supplier) { toast.info('Выберите поставщика', ''); return; }

  fillLoading.value = true;
  try {
    const { data, error } = await db
      .from('orders')
      .select('id, unit, period_days, order_items(sku, consumption_period, qty_per_box)')
      .eq('supplier', supplier)
      .eq('legal_entity', orderStore.settings.legalEntity)
      .order('created_at', { ascending: false })
      .limit(1)
      .single();

    if (error || !data?.order_items?.length) {
      toast.info('Нет данных', `Прошлых заказов для «${supplier}» не найдено`); return;
    }

    const prevMap = new Map();
    data.order_items.forEach(i => { if (i.sku) prevMap.set(i.sku, i); });

    const prevUnit    = data.unit || 'pieces';
    const currentUnit = orderStore.settings.unit;
    let filled = 0;

    orderStore.items.forEach(item => {
      if (!item.sku) return;
      const prev = prevMap.get(item.sku);
      if (!prev) return;
      const qpb = getQpb(item);
      let c = prev.consumption_period || 0;
      if (prevUnit === 'pieces' && currentUnit === 'boxes') c = c / qpb;
      else if (prevUnit === 'boxes' && currentUnit === 'pieces') c = c * qpb;
      item.consumptionPeriod = Math.round(c);
      filled++;
    });

    if (filled > 0) {
      orderStore.bumpDataVersion();
      draftStore.save();
      toast.success('Расход подставлен', `Заполнено: ${filled} из ${orderStore.items.length} товаров`);
    } else {
      toast.info('Ничего не подставлено', 'Расход уже заполнен или нет совпадений');
    }
  } catch { toast.error('Ошибка', 'Не удалось загрузить прошлый заказ'); }
  finally { fillLoading.value = false; }
}

// ─── Загрузить из 1С ──────────────────────────────────────────────────────────
async function loadFrom1c() {
  if (!orderStore.items.length) { toast.error('Нет товаров', 'Сначала добавьте товары'); return; }
  const skus = orderStore.items.map(i => i.sku).filter(Boolean);
  if (!skus.length) { toast.error('Нет артикулов', 'У товаров нет SKU для сопоставления с 1С'); return; }

  load1cLoading.value = true;
  try {
    const { data, error } = await db
      .from('stock_1c')
      .select('sku, stock, consumption, period_days, updated_at')
      .eq('legal_entity', orderStore.settings.legalEntity)
      .in('sku', skus);

    if (error) { toast.error('Ошибка', 'Не удалось загрузить данные из 1С'); return; }
    if (!data?.length) { toast.info('Нет данных', `В таблице stock_1c нет данных для «${orderStore.settings.legalEntity}»`); return; }

    const stockMap = new Map(data.map(d => [d.sku, d]));
    const isBoxes  = orderStore.settings.unit === 'boxes';
    const periodDays = orderStore.settings.periodDays || 30;
    let filled = 0;

    let oldestUpdate = null;
    data.forEach(d => { const t = new Date(d.updated_at); if (!oldestUpdate || t < oldestUpdate) oldestUpdate = t; });

    orderStore.items.forEach(item => {
      const d = item.sku ? stockMap.get(item.sku) : null;
      if (!d) return;
      const qpb = getQpb(item);
      item.stock = isBoxes ? Math.round(d.stock / qpb) : Math.round(d.stock || 0);
      const daily = (d.period_days || 30) > 0 ? (d.consumption || 0) / (d.period_days || 30) : 0;
      const adj   = daily * periodDays;
      item.consumptionPeriod = isBoxes ? Math.round(adj / qpb) : Math.round(adj);
      filled++;
    });

    orderStore.bumpDataVersion();
    draftStore.save();
    const hoursAgo = oldestUpdate ? Math.round((Date.now() - oldestUpdate) / 3600000) : null;
    const freshLabel = hoursAgo !== null ? (hoursAgo < 1 ? 'только что' : `${hoursAgo} ч. назад`) : '';
    toast.success('Данные из 1С загружены', `${filled} из ${orderStore.items.length} позиций${freshLabel ? ' · обновлено ' + freshLabel : ''}`);
  } catch { toast.error('Ошибка', 'Таблица stock_1c не найдена'); }
  finally { load1cLoading.value = false; }
}

// ─── Поиск ────────────────────────────────────────────────────────────────────
function onSearchInput() {
  clearTimeout(searchTimer);
  searchResults.value = []; searchDone.value = false;
  const q = searchQuery.value.trim();
  if (q.length < 2) return;
  searchTimer = setTimeout(() => searchProducts(q), 300);
}

async function searchProducts(q) {
  const cacheKey = `${q}_${orderStore.settings.legalEntity}_${orderStore.settings.supplier}`;
  const cached = searchCache.get(cacheKey);
  if (cached) {
    searchResults.value = cached;
    searchDone.value = true;
    return;
  }
  const params = new URLSearchParams({ q, limit: '10', legal_entity: orderStore.settings.legalEntity });
  if (orderStore.settings.supplier) params.set('supplier', orderStore.settings.supplier);
  try {
    const r = await fetch(`/api/search_products?${params}`, { headers: { 'X-API-Key': localStorage.getItem('bk_api_key') || '' } });
    if (!r.ok) { searchResults.value = []; return; }
    const results = await r.json();
    if (!Array.isArray(results)) { searchResults.value = []; return; }
    // Защита от гонки: проверяем, что запрос ещё актуален
    if (searchQuery.value.trim() !== q) return;
    searchResults.value = results;
    if (searchCache.size >= SEARCH_CACHE_MAX) {
      searchCache.delete(searchCache.keys().next().value);
    }
    searchCache.set(cacheKey, results);
  } catch(e) { searchResults.value = []; }
  finally { searchDone.value = true; }
}

function addFromSearch(product) {
  const added = orderStore.addItem(product);
  if (!added) toast.warning('Уже в заказе', product.name);
  searchQuery.value = ''; searchResults.value = []; draftStore.save();
}

function clearSearch() {
  searchQuery.value = ''; searchResults.value = []; searchDone.value = false;
  searchInputRef.value?.focus();
}

// ─── Лог изменений ────────────────────────────────────────────────────────────
async function openLogModal() {
  const id = orderStore.editingOrderId;
  if (!id) return;
  logModal.value = { show: true, loading: true, entries: [] };
  const { data } = await db.from('audit_log').select('*')
    .eq('entity_id', id).eq('entity_type', 'order')
    .order('created_at', { ascending: false }).limit(50);
  if (!logModal.value.show) return;
  logModal.value.entries = data || [];
  logModal.value.loading = false;
}
function logBadgeLabel(action) {
  return { order_created:'Создан', order_updated:'Изменён', order_deleted:'Удалён' }[action] || action;
}
function logBadgeClass(action) {
  if (action.includes('created')) return 'log-badge-created';
  if (action.includes('updated')) return 'log-badge-updated';
  if (action.includes('deleted')) return 'log-badge-deleted';
  return '';
}
function formatLogDate(str) {
  if (!str) return '';
  const d = new Date(str);
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' }) + ' ' +
         d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

// ─── Ручной товар ─────────────────────────────────────────────────────────────
function onManualAdded(product) {
  if (product.supplier === orderStore.settings.supplier) {
    orderStore.addItem(product);
    toast.success('Товар добавлен', 'Сохранён в базу и добавлен в заказ');
  } else {
    toast.success('Товар сохранён', 'Сохранён в базу (другой поставщик)');
  }
  showManualModal.value = false;
  supplierStore.invalidate();
  supplierStore.loadSuppliers(orderStore.settings.legalEntity);
  draftStore.save();
}

// ─── Редактирование карточки ──────────────────────────────────────────────────
function openProductForEdit(sku) {
  if (orderStore.viewOnlyMode || isViewer.value) return;
  const item = orderStore.items.find(i => i.sku === sku);
  if (!item) return;
  editCardModal.value = { show: true, product: { id: item.productId, sku: item.sku, name: item.name } };
}

async function onCardSaved() {
  const product = editCardModal.value.product;
  editCardModal.value.show = false;
  supplierStore.invalidate();
  supplierStore.loadSuppliers(orderStore.settings.legalEntity);
  if (!product?.id) return;
  try {
    const { data } = await db.from('products').select('*').eq('id', product.id).single();
    if (!data) return;
    const item = orderStore.items.find(i => i.productId === product.id || i.sku === product.sku);
    if (item) {
      item.sku = data.sku || item.sku; item.name = data.name || item.name;
      item.qtyPerBox = data.qty_per_box || item.qtyPerBox; item.boxesPerPallet = data.boxes_per_pallet || item.boxesPerPallet;
      item.multiplicity = data.multiplicity || item.multiplicity; item.unitOfMeasure = data.unit_of_measure || item.unitOfMeasure;
      draftStore.save();
    }
  } catch (e) { console.error('Ошибка обновления карточки:', e); }
}

function buildOrderText() {
  const deliveryDate = orderStore.settings.deliveryDate
    ? orderStore.settings.deliveryDate.toLocaleDateString('ru-RU', { day:'2-digit', month:'2-digit', year:'numeric' }) : '—';
  const lines = orderStore.items.map(item => {
    const qpb = getQpb(item); const mult = getMultiplicity(item);
    const physBoxes = orderStore.settings.unit === 'boxes' ? item.finalOrder / mult : item.finalOrder / (qpb * mult);
    const rb = Math.ceil(physBoxes); if (rb <= 0) return null;
    const pieces = rb * qpb * mult;
    const unit = item.unitOfMeasure || 'шт';
    return { text: `${item.sku ? item.sku + '  ' : ''}${item.name} - ${rb} коробок (${nf.format(Math.round(pieces))} ${unit})`, boxes: rb, pieces: Math.round(pieces), name: item.name, sku: item.sku, unit, qpb };
  }).filter(Boolean);
  return { lines, deliveryDate };
}

async function copyOrderToClipboard() {
  if (!orderStore.items.length) { toast.error('Заказ пуст', ''); return; }
  const { lines, deliveryDate } = buildOrderText(); if (!lines.length) { toast.error('Нет позиций с заказом', ''); return; }
  const le = orderStore.settings.legalEntity || '';
  const text = `Добрый день!\nПросьба отгрузить товар для ${le}, дата поставки - ${deliveryDate}:\n\n${lines.map(l => l.text).join('\n')}\n\nСпасибо!`;
  await copyToClipboard(text); toast.success('Скопировано!', `${lines.length} позиций в буфере обмена`);
}

function showOrderResult() {
  if (!orderStore.items.length) { toast.error('Заказ пуст', ''); return; }
  const { lines, deliveryDate } = buildOrderText(); if (!lines.length) { toast.error('Нет позиций с заказом', ''); return; }
  const resultLines = lines.map(l => `${l.sku ? l.sku + ' ' : ''}${l.name} — ${l.boxes} кор. (${nf.format(l.pieces)} ${l.unit})`);
  const supplier = orderStore.settings.supplier || 'не выбран';
  const text = `Поставщик: ${supplier}\nДата поставки: ${deliveryDate}\nПозиций: ${lines.length}\n\n${resultLines.join('\n')}`;
  orderResultModal.value = { show: true, text, supplier, deliveryDate, lines };
}

// ─── Share ────────────────────────────────────────────────────────────────────
async function share(channel) {
  showShareDropdown.value = false;
  if (!orderStore.items.length) { toast.error('Заказ пуст', ''); return; }
  const deliveryDate = orderStore.settings.deliveryDate
    ? orderStore.settings.deliveryDate.toLocaleDateString('ru-RU', { day:'2-digit', month:'2-digit', year:'numeric' }) : '—';
  const lines = orderStore.items.map(item => {
    const qpb = getQpb(item); const mult = getMultiplicity(item);
    const physBoxes = orderStore.settings.unit === 'boxes' ? item.finalOrder / mult : item.finalOrder / (qpb * mult);
    const rb = Math.ceil(physBoxes);
    if (rb <= 0) return null;
    const pieces = rb * qpb * mult;
    const unit = item.unitOfMeasure || 'шт';
    return `${item.sku ? item.sku + '  ' : ''}${item.name} - ${rb} коробок (${nf.format(Math.round(pieces))} ${unit})`;
  }).filter(Boolean);
  if (!lines.length) { toast.error('Нет позиций для отправки', ''); return; }
  const le       = orderStore.settings.legalEntity || '';
  const supplier = orderStore.settings.supplier    || '';
  const text = `Добрый день!\nПросьба отгрузить товар для ${le}, дата поставки - ${deliveryDate}:\n\n${lines.join('\n')}\n\nСпасибо!`;
  const encoded = encodeURIComponent(text);
  let contacts = null;
  if (supplier) contacts = await supplierStore.getSupplierContacts(supplier, orderStore.settings.legalEntity);

  if (channel === 'telegram') {
    await copyToClipboard(text);
    const tg = contacts?.telegram?.trim();
    if (tg) {
      if (tg.startsWith('+') || /^\d/.test(tg)) { toast.success('Текст скопирован', `Открываю чат с ${tg}`); window.open(`tg://resolve?phone=${tg.replace(/[^+\d]/g,'').replace('+','')}`, '_self'); }
      else { const u = tg.replace(/^@/,''); toast.success('Текст скопирован', `Открываю @${u}`); window.open(`tg://resolve?domain=${u}`, '_self'); }
    } else { toast.success('Текст скопирован', 'Выберите чат в Telegram и нажмите Ctrl+V'); }
    return;
  }
  if (channel === 'whatsapp') { const ph = contacts?.whatsapp?.replace(/[^+\d]/g,'') || ''; window.open(ph ? `https://wa.me/${ph}?text=${encoded}` : `https://wa.me/?text=${encoded}`, '_blank'); return; }
  if (channel === 'viber') {
    const ph = contacts?.viber?.replace(/[^+\d]/g,'') || '';
    if (ph) { await copyToClipboard(text); window.open(`viber://chat?number=${encodeURIComponent(ph)}`, '_blank'); }
    else window.open(`viber://forward?text=${encoded}`, '_blank');
    return;
  }
  if (channel === 'email') {
    const to = contacts?.email || '';
    const subject = encodeURIComponent(`Заказ ${supplier} на ${deliveryDate}`);
    const body = encoded;
    // outlook: URI scheme открывает именно десктопный Outlook
    window.location.href = `mailto:${to}?subject=${subject}&body=${body}`;
  }
}

// ─── Импорт из файла ─────────────────────────────────────────────────────────
async function importFromExcel() {
  if (!orderStore.items.length) { toast.error('Нет товаров', 'Сначала добавьте товары'); return; }
  importLoading.value = true;
  try {
    const result = await importFromFile('order', orderStore.items, orderStore.settings.legalEntity, orderStore.settings.unit);
    if (!result) return; // отмена
    if (result.error) { toast.error('Ошибка импорта', result.error); return; }
    if (result.matched === 0) { toast.info('Нет совпадений', `Из ${result.total} товаров файла — 0 совпадений с заказом`); return; }
    // Применяем обновлённые данные
    result.items.forEach((updated, idx) => {
      const item = orderStore.items[idx];
      if (!item) return;
      if (updated.stock !== item.stock) item.stock = updated.stock;
      if (updated.transit !== item.transit) item.transit = updated.transit;
      if (updated.consumptionPeriod !== item.consumptionPeriod) item.consumptionPeriod = updated.consumptionPeriod;
    });
    orderStore.bumpDataVersion();
    draftStore.save();
    toast.success('Импорт завершён', `${result.matched} из ${orderStore.items.length} позиций обновлены`);
    if (result.analogMerges?.length) {
      analogMergeModal.value = { show: true, merges: result.analogMerges, target: 'order' };
    }
  } finally { importLoading.value = false; }
}
function onAnalogApply() {
  const { merges } = analogMergeModal.value;
  const applied = applyAnalogMerges(orderStore.items, merges, 'order');
  analogMergeModal.value.show = false;
  if (applied > 0) {
    orderStore.bumpDataVersion();
    draftStore.save();
    toast.success('Аналоги применены', `${applied} аналогов добавлены`);
  }
}
function onAnalogSkip() { analogMergeModal.value.show = false; }


// ─── Excel ────────────────────────────────────────────────────────────────────
async function exportExcel() {
  if (!itemsWithOrderCount.value) { toast.error('Нет позиций', 'Нет позиций с заказом для экспорта'); return; }
  const { exportToExcel } = await import('@/lib/excelExport.js');
  exportToExcel(orderStore.items, orderStore.settings);
}

// ─── Очистить ─────────────────────────────────────────────────────────────────
async function clearOrder() {
  const ok = await confirmAction('Очистить данные?', 'Заполненные данные (расход, остаток, транзит, заказ) будут сброшены. Товары останутся.');
  if (!ok) return;
  orderStore.clearAllData();
  draftStore.save();
}

async function exitViewMode() {
  const ok = await confirmAction('Закрыть просмотр?', 'Заказ будет очищен.');
  if (!ok) return;
  orderStore.resetOrder();
  orderStore.settings.today = new Date();
  orderStore.settings.deliveryDate = null;
  orderStore.settings.safetyDays = 0;
  orderStore.settings.note = '';
  settingsExpanded.value = true;
  draftStore.clear();
  if (route.query.orderId) router.replace({ name: 'order' });
}

async function exitEditMode() {
  const ok = await confirmAction('Сбросить редактирование?', 'Заказ будет очищен.');
  if (!ok) return;
  orderStore.resetOrder();
  orderStore.settings.today = new Date();
  orderStore.settings.deliveryDate = null;
  orderStore.settings.safetyDays = 0;
  orderStore.settings.note = '';
  settingsExpanded.value = true;
  draftStore.clear();
  if (route.query.orderId) router.replace({ name: 'order' });
}
</script>

<style scoped>
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
.log-param-chip { display: inline-block; padding: 1px 7px; border-radius: 4px; font-size: 11px; background: #EDE7F6; color: #4A148C; font-weight: 500; }
.log-changes { display: flex; flex-wrap: wrap; gap: 3px; margin-top: 5px; }
.log-ch-chip { display: inline-block; padding: 1px 6px; border-radius: 4px; font-size: 10px; font-weight: 600; line-height: 1.5; }
.log-ch-add { background: #E8F5E9; color: #2E7D32; }
.log-ch-del { background: #FFEBEE; color: #C62828; }
.log-ch-upd { background: #FFF8E1; color: #5D4037; }
</style>
