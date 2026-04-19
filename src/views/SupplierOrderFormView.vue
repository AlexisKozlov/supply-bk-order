<template>
  <div class="ro-page">
    <!-- Header -->
    <div class="ro-header">
      <div class="ro-brand">
        <svg class="ro-logo" width="28" height="28" viewBox="5 5 38 38" xmlns="http://www.w3.org/2000/svg" fill="none">
          <circle cx="16" cy="16" r="10" fill="#E76F51"/><circle cx="32" cy="16" r="10" fill="#F4A261"/>
          <circle cx="16" cy="32" r="10" fill="#F4A261"/><circle cx="32" cy="32" r="10" fill="#FFD54F"/>
          <circle cx="24" cy="24" r="8.5" fill="#502314"/>
          <text x="24" y="29" text-anchor="middle" fill="white" font-size="14" font-weight="900" font-family="Arial, sans-serif">S</text>
        </svg>
        <div>
          <div class="ro-header-title">Ресторан {{ roStore.restaurant?.number }}</div>
          <div class="ro-header-addr">{{ roStore.restaurant?.city }}{{ roStore.restaurant?.address ? ', ' + roStore.restaurant.address : '' }}</div>
        </div>
      </div>
      <div class="ro-header-actions">
        <router-link :to="{ name: 'restaurant-cabinet' }" class="ro-link-btn">Заказ продуктов</router-link>
        <button class="ro-link-btn ro-logout-btn" @click="handleLogout">Выйти</button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="ro-loading">
      <div class="ro-spinner"></div>
      <span>Загрузка...</span>
    </div>

    <!-- No suppliers -->
    <div v-else-if="suppliers.length === 0 && !loading" class="ro-card ro-empty">
      <h2>Нет доступных поставщиков</h2>
      <p>Для вашего ресторана пока не настроен график заявок. Обратитесь в отдел закупок.</p>
    </div>

    <!-- Success screen -->
    <div v-else-if="showSuccess" class="ro-success-screen">
      <div class="ro-success-card">
        <div class="ro-success-icon">&#10003;</div>
        <h2 class="ro-success-title">Заявка отправлена!</h2>
        <p class="ro-success-date">{{ selectedSupplier?.name }} — доставка {{ formatDate(selectedDeliveryDate) }}</p>
        <p class="ro-success-stats">{{ successInfo.total_items }} поз., {{ successInfo.total_qty }} шт.</p>
        <div class="ro-success-actions">
          <button class="ro-submit-btn" @click="showSuccess = false">Назад к поставщикам</button>
        </div>
      </div>
    </div>

    <template v-else>
      <!-- Supplier selector / История -->
      <div v-if="!selectedSupplier" class="so-supplier-list">
        <div class="so-header-row">
          <h2 class="so-section-title">Заявки поставщикам</h2>
          <div class="so-view-tabs">
            <button class="so-view-tab" :class="{ active: !showHistory }" @click="showHistory = false">Поставщики</button>
            <button class="so-view-tab" :class="{ active: showHistory }" @click="openHistory">История</button>
          </div>
        </div>

        <!-- Список поставщиков -->
        <template v-if="!showHistory">
          <div v-for="sup in suppliers" :key="sup.id" class="so-supplier-card" @click="selectSupplier(sup)">
            <div class="so-supplier-name">{{ sup.name }}</div>
            <div class="so-supplier-schedule">
              График: {{ sup.schedule.map(s => s.order_day_name + ' → ' + s.delivery_day_name).join(', ') }}
            </div>
            <div v-if="!sup.is_accepting_orders" class="so-supplier-status closed">
              {{ sup.pause_message || 'Приём заявок временно приостановлен' }}
            </div>
            <div v-else-if="!sup.available_dates?.length" class="so-supplier-status closed">
              Ближайшие поставки не запланированы
            </div>
            <div v-else class="so-supplier-dates">
              <div v-for="d in getOrderedDates(sup)" :key="d.delivery_date" class="so-date-chip"
                :class="{ submitted: d.order, closed: d.deadline_status === 'closed' && !d.order }">
                {{ formatDateShort(d.delivery_date) }}
                <span v-if="d.order" class="so-chip-icon">✓</span>
                <span v-else-if="d.deadline_status === 'closed'" class="so-chip-icon">✕</span>
              </div>
            </div>
          </div>
        </template>

        <!-- История заявок -->
        <template v-else>
          <div v-if="historyLoading" class="ro-loading"><div class="ro-spinner"></div><span>Загрузка...</span></div>
          <div v-else-if="!history.length" class="ro-card ro-empty"><p>История заявок пуста.</p></div>
          <div v-else class="so-history-list">
            <div v-for="o in history" :key="o.id" class="so-history-item">
              <div class="so-history-top">
                <span class="so-history-supplier">{{ o.supplier_name }}</span>
                <span class="so-history-status" :class="historyStatusClass(o)">{{ historyStatusLabel(o) }}</span>
              </div>
              <div class="so-history-info">
                <span class="so-history-date">Доставка: {{ formatDate(o.delivery_date) }}</span>
                <span v-if="Number(o.item_count) > 0" class="so-history-qty">{{ o.item_count }} поз., {{ fmtNum(o.total_qty) }} шт.</span>
                <span v-else class="so-history-skip">Поставка не нужна</span>
              </div>
              <div class="so-history-sub">Подано: {{ o.submitted_at ? formatDateTime(o.submitted_at) : '—' }}</div>
            </div>
          </div>
        </template>
      </div>

      <!-- Order form for selected supplier -->
      <div v-else class="so-order-form">
        <button class="ro-link-btn so-back-btn" @click="selectedSupplier = null; selectedDeliveryDate = null">← Назад</button>

        <h2 class="so-section-title">{{ selectedSupplier.name }}</h2>

        <!-- Date tabs -->
        <div v-if="selectedSupplier.is_accepting_orders && orderedSelectedDates.length" class="ro-day-tabs">
          <button
            v-for="d in orderedSelectedDates" :key="d.delivery_date"
            class="ro-day-tab"
            :class="{
              active: selectedDeliveryDate === d.delivery_date,
              submitted: !!d.order && !d.order?.is_skip,
              skipped: !!d.order?.is_skip,
              closed: d.deadline_status === 'closed' && !d.order,
            }"
            @click="selectDate(d)"
          >
            <span class="ro-day-name">{{ d.delivery_day_name }}</span>
            <span class="ro-day-date">{{ formatDateShort(d.delivery_date) }}</span>
            <span v-if="d.order?.is_skip" class="ro-day-badge skipped" title="Поставка не нужна">🚫</span>
            <span v-else-if="d.order" class="ro-day-badge ok">V</span>
            <span v-else-if="d.deadline_status === 'closed'" class="ro-day-badge closed">X</span>
          </button>
        </div>
        <div v-else class="ro-card ro-empty">
          <p v-if="!selectedSupplier.is_accepting_orders">
            {{ selectedSupplier.pause_message || 'Приём заявок временно приостановлен.' }}
          </p>
          <p v-else>Ближайшие поставки не запланированы.</p>
        </div>

        <!-- Order area -->
        <div v-if="selectedDeliveryDate" class="ro-order-area">
          <div class="ro-deadline-bar" :class="'ro-deadline-' + currentDateInfo?.deadline_status">
            <template v-if="currentDateInfo?.deadline_status === 'open'">
              Дедлайн подачи: {{ currentDateInfo.deadline?.split(' ')[1] || '' }}, {{ formatDate(currentDateInfo.deadline?.split(' ')[0]) }}
              <span v-if="deadlineTimeLeft" class="ro-deadline-timer">· осталось {{ deadlineTimeLeft }}</span>
            </template>
            <template v-else>
              Приём заявок на эту дату закрыт
            </template>
          </div>

          <!-- Предыдущая заявка (справочно) -->
          <SupplierPreviousOrder
            v-if="previousOrderInfo && (!currentDateInfo?.order || currentDateInfo?.order?.status === 'draft')"
            :previous-order="previousOrderInfo"
            v-model:expanded="showPreviousOrder"
            :can-repeat="currentDateInfo?.deadline_status === 'open'"
            :format-date="formatDate"
            :fmt-num="fmtNum"
            variant="standalone"
            @repeat="handleRepeatPrevious"
          />

          <!-- Products -->
          <div v-if="currentDateInfo?.deadline_status === 'open' || currentDateInfo?.order" class="ro-products">
            <div v-if="productsLoading" class="ro-loading"><div class="ro-spinner"></div></div>
            <div v-else-if="isSkipOrder" class="ro-skip-banner">
              <span class="ro-skip-icon">🚫</span>
              <strong>Поставка не нужна.</strong>
              <span class="ro-skip-hint">Впишите количества, чтобы отменить.</span>
            </div>
            <table v-if="!productsLoading" class="ro-table">
              <thead>
                <tr>
                  <th class="ro-th-name">Товар</th>
                  <th class="ro-th-qty">Кол-во</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="p in products" :key="p.sku" class="ro-product-row" :class="{ 'ro-row-error': hasError(p) }">
                  <td class="ro-td-name">
                    <div class="ro-prod-name">{{ p.product_name || p.name }}</div>
                    <div v-if="p.multiplicity || p.min_qty" class="so-hints">
                      <span v-if="p.multiplicity" class="so-hint-mult">кратно {{ fmtNum(p.multiplicity) }}</span>
                      <span v-if="p.min_qty" class="so-hint-min">мин. {{ fmtNum(p.min_qty) }}</span>
                    </div>
                  </td>
                  <td class="ro-td-qty">
                    <input
                      type="number"
                      class="ro-qty-input"
                      :class="{ 'ro-qty-error': hasError(p) }"
                      v-model.number="quantities[p.sku]"
                      :disabled="currentDateInfo?.deadline_status === 'closed'"
                      min="0"
                      :step="p.multiplicity || 1"
                      inputmode="numeric"
                      @focus="$event.target.select()"
                    />
                    <div v-if="multError(p)" class="so-qty-error">Кратно {{ fmtNum(p.multiplicity) }}</div>
                    <div v-else-if="minError(p)" class="so-qty-error">Мин. {{ fmtNum(p.min_qty) }}</div>
                  </td>
                </tr>
              </tbody>
            </table>

            <!-- Submit -->
            <div v-if="currentDateInfo?.deadline_status === 'open'" class="ro-submit-area">
              <div class="ro-submit-summary">
                {{ filledCount }} поз., {{ filledTotal }} шт.
              </div>
              <div v-if="hasErrors" class="so-error-msg">Исправьте количество (кратность / минимум)</div>
              <button
                class="ro-submit-btn"
                :disabled="submitting || hasErrors"
                @click="handleSubmit"
              >
                {{ submitButtonLabel }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </template>

    <ConfirmModal
      v-if="confirmModal.show"
      :title="confirmModal.title"
      :message="confirmModal.message"
      :ok-text="confirmModal.okText"
      :cancel-text="confirmModal.cancelText"
      :danger="confirmModal.danger"
      @confirm="onConfirm"
      @cancel="onCancel"
    />
    <InfoModal
      v-if="infoModal.show"
      :title="infoModal.title"
      :message="infoModal.message"
      :type="infoModal.type"
      @close="onInfoClose"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useRestaurantOrderStore } from '@/stores/restaurantOrderStore.js';
import { useSupplierOrderStore } from '@/stores/supplierOrderStore.js';
import { useDeadlineCountdown } from '@/composables/useDeadlineCountdown.js';
import { useConfirm } from '@/composables/useConfirm.js';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';
import InfoModal from '@/components/modals/InfoModal.vue';
import SupplierPreviousOrder from '@/components/SupplierPreviousOrder.vue';

const { confirmModal, confirm: showConfirm, onConfirm, onCancel, infoModal, info: showInfo, onInfoClose } = useConfirm();

const router = useRouter();
const route = useRoute();
const roStore = useRestaurantOrderStore();
const soStore = useSupplierOrderStore();

const loading = ref(true);
const productsLoading = ref(false);
const submitting = ref(false);
const suppliers = ref([]);
const selectedSupplier = ref(null);
const selectedDeliveryDate = ref(null);
const products = ref([]);
const quantities = ref({});
const showSuccess = ref(false);
const successInfo = ref({});
const isSkipOrder = ref(false);
const previousOrderInfo = ref(null);
const showPreviousOrder = ref(false);

const showHistory = ref(false);
const history = ref([]);
const historyLoading = ref(false);

function sortAvailableDates(list = []) {
  return [...list].sort((a, b) => {
    const aClosed = a?.deadline_status === 'closed' ? 1 : 0;
    const bClosed = b?.deadline_status === 'closed' ? 1 : 0;
    if (aClosed !== bClosed) return aClosed - bClosed;
    return String(a?.delivery_date || '').localeCompare(String(b?.delivery_date || ''));
  });
}

function getOrderedDates(supplier) {
  return sortAvailableDates(supplier?.available_dates || []);
}

const orderedSelectedDates = computed(() => getOrderedDates(selectedSupplier.value));

const currentDateInfo = computed(() => {
  if (!selectedSupplier.value || !selectedDeliveryDate.value) return null;
  return selectedSupplier.value.available_dates?.find(d => d.delivery_date === selectedDeliveryDate.value);
});

const { timeLeft: deadlineTimeLeft } = useDeadlineCountdown(
  () => currentDateInfo.value?.deadline_status === 'open' ? currentDateInfo.value?.deadline : null
);

const filledCount = computed(() => Object.values(quantities.value).filter(v => v > 0).length);
const filledTotal = computed(() => Object.values(quantities.value).reduce((s, v) => s + (v > 0 ? v : 0), 0));

const submitButtonLabel = computed(() => {
  if (submitting.value) return 'Отправка...';
  if (filledCount.value === 0) {
    return currentDateInfo.value?.order ? 'Обновить: поставка не нужна' : 'Отправить: поставка не нужна';
  }
  return currentDateInfo.value?.order ? 'Обновить заявку' : 'Отправить заявку';
});

function multError(p) {
  const m = parseFloat(p.multiplicity);
  if (!m || m <= 0) return false;
  const val = parseFloat(quantities.value[p.sku]) || 0;
  if (val === 0) return false;
  const rem = Math.abs(val % m);
  return rem > 0.001 && Math.abs(rem - m) > 0.001;
}

function minError(p) {
  const min = parseFloat(p.min_qty);
  if (!min || min <= 0) return false;
  const val = parseFloat(quantities.value[p.sku]) || 0;
  if (val === 0) return false;
  return val < min;
}

function hasError(p) { return multError(p) || minError(p); }

const hasErrors = computed(() => products.value.some(p => hasError(p)));

function fmtNum(v) {
  const n = parseFloat(v);
  return n % 1 === 0 ? n.toFixed(0) : n.toString();
}

onMounted(async () => {
  // Проверяем авторизацию
  try {
    await roStore.validate();
  } catch {
    router.push({ name: 'restaurant-order-login' });
    return;
  }
  if (!roStore.restaurant) {
    router.push({ name: 'restaurant-order-login' });
    return;
  }
  await loadData();
});

async function loadData() {
  loading.value = true;
  try {
    suppliers.value = await soStore.loadSuppliers();
    const initial = route.query.supplier;
    if (initial) {
      const sup = suppliers.value.find(s => String(s.id) === String(initial));
      if (sup) selectSupplier(sup);
    }
  } catch (e) {
    console.error('Ошибка загрузки поставщиков:', e);
  } finally {
    loading.value = false;
  }
}

function selectSupplier(sup) {
  selectedSupplier.value = sup;
  selectedDeliveryDate.value = null;
  products.value = [];
  quantities.value = {};

  // Автовыбор первой открытой даты
  const openDate = getOrderedDates(sup).find(d => d.deadline_status === 'open');
  if (openDate) selectDate(openDate);
}

async function selectDate(dateInfo) {
  selectedDeliveryDate.value = dateInfo.delivery_date;
  productsLoading.value = true;
  quantities.value = {};
  isSkipOrder.value = false;

  try {
    products.value = await soStore.loadProducts(selectedSupplier.value.id);

    // Всегда грузим заявку (вернёт previous_order, даже если текущей нет)
    const { order, previousOrder } = await soStore.loadMyOrder(selectedSupplier.value.id, dateInfo.delivery_date);
    previousOrderInfo.value = previousOrder;

    if (order) {
      const itemCount = order?.items?.length || 0;
      if (itemCount > 0) {
        for (const item of order.items) {
          quantities.value[item.sku] = parseFloat(item.quantity) || 0;
        }
      } else {
        // Заявка есть, но позиций нет → «Поставка не нужна»
        isSkipOrder.value = true;
        for (const p of products.value) {
          quantities.value[p.sku] = 0;
        }
      }
    }
  } catch (e) {
    console.error('Ошибка загрузки товаров:', e);
  } finally {
    productsLoading.value = false;
  }
}

async function handleRepeatPrevious() {
  const prev = previousOrderInfo.value;
  if (!prev?.items?.length) return;
  const ok = await showConfirm('Повторить заявку', `Заполнить текущую заявку позициями из заявки от ${formatDate(prev.delivery_date)}?`);
  if (!ok) return;
  const available = new Set(products.value.map(p => p.sku));
  let applied = 0;
  let skipped = 0;
  for (const it of prev.items) {
    if (available.has(it.sku)) {
      quantities.value[it.sku] = parseFloat(it.quantity) || 0;
      applied++;
    } else {
      skipped++;
    }
  }
  if (skipped > 0) {
    showInfo('Готово', `Скопировано позиций: ${applied}.\nПропущено (нет в шаблоне): ${skipped}.`, 'success');
  }
}

async function handleSubmit() {
  submitting.value = true;
  try {
    const items = products.value
      .filter(p => quantities.value[p.sku] > 0)
      .map(p => ({
        product_id: p.product_id || p.id || '',
        sku: p.sku,
        product_name: p.product_name || p.name || '',
        quantity: quantities.value[p.sku],
      }));

    const result = await soStore.submitOrder(
      selectedSupplier.value.id,
      selectedDeliveryDate.value,
      currentDateInfo.value?.order_date || '',
      items,
      { skipDelivery: items.length === 0 },
    );

    if (result.success) {
      successInfo.value = result;
      showSuccess.value = true;
      // Перезагрузим данные
      suppliers.value = await soStore.loadSuppliers();
    }
  } catch (e) {
    showInfo('Ошибка', e.message || 'Ошибка отправки', 'error');
  } finally {
    submitting.value = false;
  }
}

async function openHistory() {
  showHistory.value = true;
  if (history.value.length) return;
  historyLoading.value = true;
  try {
    history.value = await soStore.loadMyOrders();
  } catch (e) {
    console.error('Ошибка загрузки истории:', e);
  } finally {
    historyLoading.value = false;
  }
}

function historyStatusClass(o) {
  if (Number(o.item_count) === 0) return 'so-st-skip';
  if (o.status === 'locked') return 'so-st-locked';
  return 'so-st-submitted';
}

function historyStatusLabel(o) {
  if (Number(o.item_count) === 0) return 'Не нужна';
  if (o.status === 'locked') return 'Закрыто';
  return 'Подано';
}

function formatDateTime(d) {
  if (!d) return '';
  const dt = new Date(d);
  return dt.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })
    + ' ' + dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

function handleLogout() {
  roStore.logout();
  router.push({ name: 'restaurant-order-login' });
}

function formatDate(d) {
  if (!d) return '';
  const dt = new Date(d + 'T00:00:00');
  return dt.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', weekday: 'short' });
}
function formatDateShort(d) {
  if (!d) return '';
  const dt = new Date(d + 'T00:00:00');
  return dt.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
}
</script>

<style scoped>
/* ═══ Блок «Предыдущая заявка» ═══ */

/* ═══ Базовые стили (из RestaurantOrderFormView) ═══ */
.ro-page {
  min-height: 100vh;
  background: #f5f0eb;
  font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
  padding-bottom: 100px;
}
.ro-order-area,
.ro-day-tabs {
  max-width: 900px;
  margin-left: auto;
  margin-right: auto;
}
.ro-order-area {
  background: white;
  border-radius: 16px;
  margin-top: 16px;
  box-shadow: 0 2px 16px rgba(80,35,20,0.08);
  overflow: hidden;
}

/* Header */
.ro-header {
  background: #502314;
  color: white;
  padding: 12px 20px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}
.ro-brand { display: flex; align-items: center; gap: 10px; }
.ro-header-title { font-size: 16px; font-weight: 700; }
.ro-header-addr { font-size: 12px; opacity: 0.7; }
.ro-header-actions { display: flex; gap: 8px; }
.ro-link-btn {
  padding: 6px 14px; border-radius: 8px;
  border: 1px solid rgba(255,255,255,0.3);
  background: transparent; color: white;
  font-size: 13px; cursor: pointer; text-decoration: none; font-family: inherit;
}
.ro-link-btn:hover { background: rgba(255,255,255,0.1); }
.ro-logout-btn { border-color: rgba(255,100,100,0.4); }

/* Loading */
.ro-loading { display: flex; align-items: center; justify-content: center; gap: 12px; padding: 60px; color: #8b7355; }
.ro-spinner { width: 24px; height: 24px; border: 3px solid #e0d5c8; border-top-color: #E76F51; border-radius: 50%; animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.ro-card { background: white; border-radius: 12px; padding: 32px; margin: 20px auto; text-align: center; max-width: 900px; }
.ro-empty h2 { color: #502314; margin: 0 0 8px; }
.ro-empty p { color: #8b7355; margin: 0; }

/* Day tabs */
.ro-day-tabs { display: flex; gap: 6px; padding: 12px 16px; overflow-x: auto; background: white; border-radius: 12px; margin-top: 12px; justify-content: center; }
.ro-day-tab { flex-shrink: 0; padding: 8px 14px; border-radius: 10px; border: 2px solid #e0d5c8; background: white; cursor: pointer; text-align: center; font-family: inherit; transition: all 0.2s; position: relative; }
.ro-day-tab.active { border-color: #E76F51; background: #fff5f2; }
.ro-day-tab.submitted { border-color: #16a34a; }
.ro-day-tab.closed { opacity: 0.5; }
.ro-day-name { display: block; font-size: 12px; font-weight: 600; color: #502314; }
.ro-day-date { display: block; font-size: 11px; color: #8b7355; }
.ro-day-badge { position: absolute; top: -6px; right: -6px; width: 18px; height: 18px; border-radius: 50%; font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
.ro-day-badge.ok { background: #16a34a; color: white; }
.ro-day-badge.skipped { background: #9ca3af; color: white; font-size: 10px; }
.ro-day-badge.closed { background: #9ca3af; color: white; }
.ro-day-tab.skipped { border-color: #9ca3af; background: #f5f5f5; }
.ro-day-tab.active.skipped { background: #E76F51; border-color: #E76F51; color: white; }
.ro-day-tab.active.skipped .ro-day-name, .ro-day-tab.active.skipped .ro-day-date { color: white; }

.ro-skip-banner {
  padding: 8px 14px; background: #fef3c7; border: 1px solid #fbbf24;
  border-radius: 8px; color: #92400e; margin-bottom: 10px;
  display: flex; align-items: center; flex-wrap: wrap; gap: 6px;
  font-size: 12px; line-height: 1.3;
}
.ro-skip-banner strong { font-size: 13px; }
.ro-skip-icon { font-size: 14px; }
.ro-skip-hint { font-size: 11px; opacity: 0.75; }

/* Deadline bar */
.ro-deadline-bar { padding: 10px 16px; font-size: 13px; font-weight: 600; text-align: center; }
.ro-deadline-open { background: #ecfdf5; color: #16a34a; }
.ro-deadline-closed { background: #fef2f2; color: #dc2626; }
.ro-deadline-timer { margin-left: 6px; font-variant-numeric: tabular-nums; opacity: 0.85; }

/* Products table */
.ro-products { background: white; }
.ro-table { width: 100%; border-collapse: collapse; }
.ro-table th { padding: 8px 12px; font-size: 12px; color: #8b7355; font-weight: 600; text-align: left; border-bottom: 2px solid #e0d5c8; background: #faf7f4; }
.ro-table td { padding: 6px 12px; border-bottom: 1px solid #f0ebe4; font-size: 13px; color: #502314; }
.ro-th-name { }
.ro-th-qty { width: 120px; }
.ro-product-row { }
.ro-td-name { }
.ro-prod-name { font-size: 14px; font-weight: 500; color: #502314; }
.ro-prod-sku { font-size: 11px; color: #8b7355; }
.ro-td-qty { }
.ro-qty-input { width: 80px; padding: 6px 8px; border: 2px solid #e0d5c8; border-radius: 8px; font-size: 14px; text-align: center; font-family: inherit; }
.ro-qty-input:focus { outline: none; border-color: #E76F51; }

/* Submit */
.ro-submit-area { padding: 16px; text-align: center; }
.ro-submit-summary { font-size: 14px; color: #502314; margin-bottom: 12px; }
.ro-submit-btn { padding: 14px 40px; background: #E76F51; color: white; border: none; border-radius: 12px; font-size: 16px; font-weight: 700; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 8px; transition: background 0.2s; }
.ro-submit-btn:hover:not(:disabled) { background: #b81e00; }
.ro-submit-btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* Success screen */
.ro-success-screen { display: flex; align-items: center; justify-content: center; min-height: 60vh; padding: 20px; }
.ro-success-card { background: white; border-radius: 24px; padding: 44px 36px; text-align: center; max-width: 480px; width: 100%; box-shadow: 0 12px 40px rgba(80,35,20,0.08); border: 1px solid #EDE8E3; animation: roSuccessIn 0.35s cubic-bezier(0.16, 1, 0.3, 1); }
@keyframes roSuccessIn {
  from { opacity: 0; transform: translateY(12px) scale(0.97); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}
.ro-success-icon { width: 76px; height: 76px; border-radius: 50%; background: linear-gradient(135deg, #22c55e, #16a34a); color: white; font-size: 38px; font-weight: 700; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; box-shadow: 0 8px 24px rgba(22, 163, 74, 0.3); }
.ro-success-title { color: #502314; margin: 0 0 8px; font-size: 24px; font-weight: 800; letter-spacing: -0.3px; }
.ro-success-date { color: #8b7355; margin: 0 0 4px; font-size: 15px; }
.ro-success-stats { color: #502314; margin: 0 0 24px; font-size: 14px; font-weight: 600; }
.ro-success-actions { display: flex; flex-direction: column; gap: 10px; align-items: center; }

/* ═══ Стили модуля supplier-order ═══ */
.so-supplier-list { padding: 16px; max-width: 600px; margin: 0 auto; }
.so-section-title { font-size: 18px; font-weight: 700; margin: 0 0 16px; color: #502314; }
.so-supplier-card {
  background: #fff; border-radius: 12px; padding: 16px; margin-bottom: 12px;
  box-shadow: 0 1px 3px rgba(0,0,0,.1); cursor: pointer; transition: transform .15s;
}
.so-supplier-card:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(0,0,0,.15); }
.so-supplier-name { font-size: 17px; font-weight: 700; color: #333; margin-bottom: 4px; }
.so-supplier-schedule { font-size: 13px; color: #888; margin-bottom: 8px; }
.so-supplier-status.closed { font-size: 13px; color: #d32f2f; font-weight: 600; }
.so-supplier-dates { display: flex; flex-wrap: wrap; gap: 6px; }
.so-date-chip {
  font-size: 12px; padding: 4px 10px; border-radius: 12px;
  background: #e8f5e9; color: #2e7d32; font-weight: 600;
}
.so-date-chip.submitted { background: #bbdefb; color: #1565c0; }
.so-date-chip.closed { background: #ffebee; color: #c62828; }
.so-chip-icon { margin-left: 4px; }
.so-back-btn { margin: 12px 16px 0; font-size: 14px; }
.so-order-form { max-width: 600px; margin: 0 auto; }

/* Hints & validation */
.so-hint-mult, .so-hint-min {
  display: inline-block; margin-left: 6px;
  font-size: 10px; padding: 1px 5px; border-radius: 4px; font-weight: 600;
}
.so-hint-mult { background: #eff6ff; color: #2563eb; }
.so-hint-min { background: #fef3c7; color: #92400e; }
.so-qty-error { font-size: 10px; color: #dc2626; margin-top: 2px; text-align: center; }
.so-error-msg { padding: 8px 16px; border-radius: 8px; background: #fef2f2; color: #dc2626; font-size: 13px; font-weight: 600; margin-bottom: 10px; text-align: center; }
.ro-qty-error { border-color: #dc2626 !important; background: #fef2f2; }
.ro-row-error { background: #fef2f2; }

/* История заявок */
.so-header-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
.so-header-row .so-section-title { margin-bottom: 0; }
.so-view-tabs { display: flex; gap: 4px; background: #f0ebe4; border-radius: 10px; padding: 3px; }
.so-view-tab { padding: 6px 14px; border-radius: 8px; border: none; background: transparent; font-size: 13px; font-weight: 600; color: #8b7355; cursor: pointer; font-family: inherit; transition: all 0.15s; }
.so-view-tab.active { background: white; color: #502314; box-shadow: 0 1px 4px rgba(0,0,0,.1); }

.so-history-list { display: flex; flex-direction: column; gap: 10px; }
.so-history-item { background: white; border-radius: 12px; padding: 14px 16px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
.so-history-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 6px; }
.so-history-supplier { font-size: 16px; font-weight: 700; color: #333; }
.so-history-status { font-size: 12px; font-weight: 700; padding: 3px 9px; border-radius: 8px; }
.so-st-submitted { background: #e8f5e9; color: #2e7d32; }
.so-st-locked { background: #e3f2fd; color: #1565c0; }
.so-st-skip { background: #fef3c7; color: #92400e; }
.so-history-info { display: flex; align-items: center; gap: 12px; font-size: 13px; color: #502314; flex-wrap: wrap; }
.so-history-date { font-weight: 600; }
.so-history-qty { color: #8b7355; }
.so-history-skip { color: #92400e; font-style: italic; }
.so-history-sub { font-size: 11px; color: #aaa; margin-top: 4px; }

/* Mobile */
@media (max-width: 600px) {
  .ro-page { padding-bottom: 80px; }
  .ro-header { padding: 10px 12px; }
  .ro-header-title { font-size: 14px; }
  .ro-link-btn { padding: 5px 10px; font-size: 12px; }
  .ro-day-tabs { padding: 8px 6px; gap: 4px; justify-content: flex-start; margin-top: 8px; border-radius: 10px; margin-left: 8px; margin-right: 8px; }
  .ro-day-tab { padding: 6px 10px; }
  .ro-order-area { margin: 8px; border-radius: 12px; }
  .ro-deadline-bar { font-size: 12px; padding: 8px 12px; }
  .ro-table { display: block; }
  .ro-table thead { display: none; }
  .ro-table tbody { display: block; }
  .ro-table tr {
    display: flex; flex-wrap: wrap; align-items: center;
    padding: 10px 12px; gap: 4px 8px;
    border-bottom: 1px solid #f0ebe4;
  }
  .ro-table td { padding: 0; border: none; }
  .ro-td-name { flex: 1 1 100%; order: -1; }
  .ro-td-qty { order: 2; margin-left: auto; }
  .ro-qty-input { width: 80px; height: 44px; padding: 8px 6px; font-size: 16px; }
  .ro-submit-btn { width: 100%; padding: 14px; font-size: 16px; }
  .ro-success-card { padding: 28px 20px; }
}
</style>
