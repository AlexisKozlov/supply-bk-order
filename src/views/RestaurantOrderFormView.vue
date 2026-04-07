<template>
  <div class="ro-page">
    <!-- Header -->
    <div class="ro-header">
      <div class="ro-brand">
        <svg class="ro-logo" width="28" height="28" viewBox="5 5 38 38" xmlns="http://www.w3.org/2000/svg" fill="none">
          <circle cx="16" cy="16" r="10" fill="#D62300"/><circle cx="32" cy="16" r="10" fill="#F5A623"/>
          <circle cx="16" cy="32" r="10" fill="#FF8733"/><circle cx="32" cy="32" r="10" fill="#FFD54F"/>
          <circle cx="24" cy="24" r="8.5" fill="#502314"/>
          <text x="24" y="29" text-anchor="middle" fill="white" font-size="14" font-weight="900" font-family="Arial, sans-serif">S</text>
        </svg>
        <div>
          <div class="ro-header-title">Ресторан {{ store.restaurant?.number }}</div>
          <div class="ro-header-addr">{{ store.restaurant?.city }}{{ store.restaurant?.address ? ', ' + store.restaurant.address : '' }}</div>
        </div>
      </div>
      <div class="ro-header-actions">
        <router-link :to="{ name: 'restaurant-order-history' }" class="ro-link-btn">Мои заказы</router-link>
        <button class="ro-link-btn ro-logout-btn" @click="handleLogout">Выйти</button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="ro-loading">
      <div class="ro-spinner"></div>
      <span>Загрузка...</span>
    </div>

    <!-- No session -->
    <div v-else-if="!store.sessionInfo" class="ro-card ro-empty">
      <h2>Нет активной сессии</h2>
      <p>Сейчас приём заявок закрыт. Обратитесь в отдел закупок.</p>
    </div>

    <!-- Success screen after submit -->
    <div v-else-if="showSuccessScreen" class="ro-success-screen">
      <div class="ro-success-card">
        <div class="ro-success-icon">&#10003;</div>
        <h2 class="ro-success-title">Заказ {{ wasEdited ? 'обновлён' : 'отправлен' }}!</h2>
        <p class="ro-success-date">Доставка: {{ formatDate(selectedDate) }}</p>
        <p class="ro-success-stats">{{ totalItems }} поз., {{ totalQty }} кор.</p>

        <div v-if="editTimeLeft" class="ro-success-timer">
          <p class="ro-success-timer-label">Можно изменить до 10:00</p>
          <div class="ro-success-countdown">{{ editTimeLeft }}</div>
        </div>
        <div v-else class="ro-success-timer ro-success-timer-expired">
          <p class="ro-success-timer-label">Время на изменение истекло</p>
        </div>

        <div class="ro-success-actions">
          <button v-if="editTimeLeft" class="ro-submit-btn" @click="returnToEdit">Изменить заказ</button>
          <button class="ro-link-btn ro-success-new" @click="goToNextDay">Следующий день</button>
          <router-link :to="{ name: 'restaurant-order-history' }" class="ro-link-btn ro-success-history">Мои заказы</router-link>
        </div>
      </div>
    </div>

    <template v-else>
      <!-- Session info -->
      <div class="ro-session-bar">
        Сессия: {{ formatDate(store.sessionInfo.week_start) }} — {{ formatDate(store.sessionInfo.week_end) }}
      </div>

      <!-- Delivery day selector -->
      <div class="ro-day-tabs">
        <button
          v-for="day in store.deliveryDays" :key="day.date"
          class="ro-day-tab"
          :class="{
            active: selectedDate === day.date,
            submitted: day.order?.status === 'submitted' || day.order?.status === 'edited',
            closed: day.deadline_status === 'closed',
            warning: day.deadline_status === 'warning',
          }"
          @click="selectDay(day.date)"
        >
          <span class="ro-day-name">{{ day.day_name }}</span>
          <span class="ro-day-date">{{ formatDateShort(day.date) }}</span>
          <span v-if="day.order?.status === 'submitted' || day.order?.status === 'edited'" class="ro-day-badge ok">V</span>
          <span v-else-if="day.deadline_status === 'closed'" class="ro-day-badge closed">X</span>
        </button>
      </div>

      <!-- Selected day -->
      <div v-if="selectedDate" class="ro-order-area">
        <!-- Deadline info -->
        <div class="ro-deadline-bar" :class="'ro-deadline-' + currentDeadlineStatus">
          <template v-if="currentDeadlineStatus === 'open'">
            Приём заявок открыт до {{ currentDeadlines?.soft }}
          </template>
          <template v-else-if="currentDeadlineStatus === 'warning'">
            Внимание! Основной дедлайн ({{ currentDeadlines?.soft }}) прошёл. Можно подать до {{ currentDeadlines?.hard }}
          </template>
          <template v-else-if="currentDeadlineStatus === 'closed'">
            Приём заявок на эту дату закрыт
          </template>
          <template v-else-if="currentDeadlineStatus === 'not_yet'">
            Приём заявок на эту дату ещё не начался
          </template>
        </div>

        <!-- Category tabs -->
        <div class="ro-cat-tabs">
          <button
            v-for="cat in categories" :key="cat"
            class="ro-cat-tab"
            :class="{ active: activeCategory === cat }"
            @click="activeCategory = cat"
          >
            {{ cat }}
            <span v-if="getCategoryItemCount(cat)" class="ro-cat-count">{{ getCategoryItemCount(cat) }}</span>
          </button>
        </div>

        <!-- Product list -->
        <div class="ro-products">
          <!-- Search = filter + Add button -->
          <div class="ro-search-row">
            <input
              v-model="searchQuery"
              type="text"
              placeholder="Фильтр по названию или артикулу..."
              class="ro-search-input"
            />
            <button v-if="searchQuery" class="ro-search-clear" @click="searchQuery = ''">X</button>
            <button class="ro-add-btn" @click="showAddModal = true">+ Добавить</button>
            <button v-if="hasTemplateGap" class="ro-tpl-btn" @click="loadTemplateGap">Загрузить шаблон</button>
            <button class="ro-excel-btn" @click="exportExcel" title="Скачать Excel">Excel</button>
          </div>

          <!-- Items table -->
          <table v-if="filteredItems.length" class="ro-table">
            <thead>
              <tr>
                <th class="ro-th-name">Товар</th>
                <th class="ro-th-mult">Кратн.</th>
                <th class="ro-th-qty">Кол-во (кор.)</th>
                <th class="ro-th-comment">Комментарий</th>
                <th class="ro-th-actions"></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="item in filteredItems" :key="item.sku"
                :class="{ 'ro-row-filled': item.quantity > 0, 'ro-row-error': item._multError }"
              >
                <td class="ro-td-name"><span class="ro-sku-label">{{ item.sku }}</span> {{ item.product_name }}</td>
                <td class="ro-td-mult">
                  <span v-if="item.multiplicity > 1" class="ro-mult-badge">{{ item.multiplicity }}</span>
                </td>
                <td class="ro-td-qty">
                  <input
                    v-model.number="item.quantity"
                    type="number"
                    inputmode="decimal"
                    min="0"
                    :step="item.multiplicity > 1 ? item.multiplicity : 1"
                    class="ro-qty-input"
                    :class="{ 'ro-qty-error': item._multError }"
                    :disabled="!canSubmit && !canEdit"
                    placeholder="0"
                    @input="checkMultiplicity(item)"
                    @focus="$event.target.select()"
                  />
                  <div v-if="item._multError" class="ro-mult-hint">Кратность: {{ item.multiplicity }}</div>
                </td>
                <td class="ro-td-comment">
                  <input
                    v-model="item.comment"
                    type="text"
                    class="ro-comment-input"
                    placeholder=""
                    :disabled="!canSubmit && !canEdit"
                  />
                </td>
                <td class="ro-td-actions">
                  <button v-if="item._added" class="ro-remove-btn" @click="removeItem(item)" title="Убрать">X</button>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-else-if="searchQuery && !productsLoading" class="ro-empty-cat">
            Ничего не найдено по запросу "{{ searchQuery }}"
          </div>
          <div v-else-if="!productsLoading" class="ro-empty-cat">
            Нет товаров в категории "{{ activeCategory }}".
          </div>
          <div v-if="productsLoading" class="ro-loading-small">
            <div class="ro-spinner"></div>
          </div>
        </div>

        <!-- Summary & Submit -->
        <div class="ro-summary" v-if="totalItems > 0">
          <div class="ro-summary-stats">
            <span>Позиций: <strong>{{ totalItems }}</strong></span>
            <span>Коробок: <strong>{{ totalQty }}</strong></span>
          </div>
          <button v-if="canSubmit || canEdit" class="ro-clear-btn" @click="clearOrder">Очистить</button>
        </div>

        <div class="ro-actions">
          <div v-if="hasMultErrors" class="ro-error-msg">
            Исправьте количество — некоторые товары заказаны не кратно
          </div>
          <button
            v-if="canSubmit || canEdit"
            class="ro-submit-btn"
            :disabled="submitting || totalItems === 0 || hasMultErrors"
            @click="handleSubmit"
          >
            <span v-if="submitting" class="ro-spinner ro-spinner-sm"></span>
            {{ existingOrder ? 'Обновить заказ' : 'Отправить заказ' }}
          </button>
          <div v-if="!canSubmit && !canEdit && currentDeadlineStatus === 'closed'" class="ro-locked-msg">
            Заказ заблокирован. Для изменений обратитесь в отдел закупок.
          </div>
          <div v-if="submitError" class="ro-error-msg">{{ submitError }}</div>
        </div>

        <!-- Repeat previous order -->
        <div v-if="previousOrders.length && !existingOrder && (canSubmit || canEdit)" class="ro-repeat-section">
          <div class="ro-repeat-title">Повторить предыдущий заказ:</div>
          <div v-for="po in previousOrders" :key="po.id" class="ro-repeat-item">
            <button class="ro-repeat-btn" @click="handleRepeat(po.id)">
              {{ formatDate(po.delivery_date) }} — {{ po.item_count }} поз., {{ po.total_qty }} кор.
            </button>
          </div>
        </div>
      </div>
    </template>

    <!-- Add product modal -->
    <div v-if="showAddModal" class="ro-modal-overlay" @click.self="showAddModal = false">
      <div class="ro-modal">
        <div class="ro-modal-header">
          <h2>Добавить товар</h2>
          <button class="ro-modal-close" @click="showAddModal = false">X</button>
        </div>
        <div class="ro-modal-body">
          <input
            v-model="addSearch"
            type="text"
            placeholder="Поиск по названию или артикулу..."
            class="ro-search-input ro-modal-search"
            @input="doAddSearch"
            ref="addSearchInput"
          />
          <div v-if="addLoading" class="ro-loading-small"><div class="ro-spinner"></div></div>
          <div v-else-if="addResults.length" class="ro-add-list">
            <div
              v-for="p in addResults" :key="p.sku"
              class="ro-add-item"
              @click="addProduct(p)"
            >
              <div class="ro-add-item-info">
                <span class="ro-add-sku">{{ p.sku }}</span>
                <span class="ro-add-name">{{ p.name }}</span>
              </div>
              <div class="ro-add-item-meta">
                <span class="ro-add-cat">{{ p.category }}</span>
                <span v-if="p.multiplicity > 1" class="ro-mult-badge">x{{ p.multiplicity }}</span>
              </div>
            </div>
          </div>
          <div v-else-if="addSearch.length >= 2" class="ro-empty-cat">Ничего не найдено</div>
          <div v-else class="ro-empty-cat">Введите минимум 2 символа для поиска</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue';
import { useRouter, onBeforeRouteLeave } from 'vue-router';
import { useRestaurantOrderStore } from '@/stores/restaurantOrderStore.js';

const router = useRouter();
const store = useRestaurantOrderStore();

const loading = ref(true);
const productsLoading = ref(false);
const selectedDate = ref('');
const activeCategory = ref('Сухой');
const categories = ['Сухой', 'Холод', 'Мороз'];

const orderItems = ref([]);
const searchQuery = ref('');
const previousOrders = ref([]);

const submitting = ref(false);
const submitSuccess = ref(false);
const submitError = ref('');
const existingOrder = ref(null);

// Отслеживание несохранённых изменений
const savedSnapshot = ref('');

function takeSnapshot() {
  return JSON.stringify(orderItems.value.map(i => ({ s: i.sku, q: i.quantity, c: i.comment })));
}

const hasUnsavedChanges = computed(() => {
  if (!selectedDate.value || showSuccessScreen.value) return false;
  const current = takeSnapshot();
  return current !== savedSnapshot.value && orderItems.value.some(i => i.quantity > 0);
});

function onBeforeUnload(e) {
  if (hasUnsavedChanges.value) { e.preventDefault(); e.returnValue = ''; }
}

onMounted(() => window.addEventListener('beforeunload', onBeforeUnload));
onUnmounted(() => window.removeEventListener('beforeunload', onBeforeUnload));

onBeforeRouteLeave(() => {
  if (hasUnsavedChanges.value) {
    return confirm('Заказ не отправлен. Уйти со страницы?');
  }
});

// Success screen
const showSuccessScreen = ref(false);
const wasEdited = ref(false);
const editTimeLeft = ref('');
let editTimerInterval = null;

function startEditTimer() {
  clearInterval(editTimerInterval);
  updateEditTimeLeft();
  editTimerInterval = setInterval(updateEditTimeLeft, 1000);
}

function updateEditTimeLeft() {
  const now = new Date();
  const deadline = new Date(now);
  deadline.setHours(10, 0, 0, 0);
  // Если уже после 10:00 — время вышло
  if (now >= deadline) {
    editTimeLeft.value = '';
    clearInterval(editTimerInterval);
    return;
  }
  const diff = deadline - now;
  const hours = Math.floor(diff / 3600000);
  const mins = Math.floor((diff % 3600000) / 60000);
  const secs = Math.floor((diff % 60000) / 1000);
  editTimeLeft.value = `${String(hours).padStart(2, '0')}:${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
}

function returnToEdit() {
  showSuccessScreen.value = false;
  clearInterval(editTimerInterval);
}

function goToNextDay() {
  showSuccessScreen.value = false;
  clearInterval(editTimerInterval);
  // Найти следующий день после текущего
  const idx = store.deliveryDays.findIndex(d => d.date === selectedDate.value);
  const next = store.deliveryDays[idx + 1];
  if (next) {
    selectDay(next.date);
  }
}

// Add modal
const showAddModal = ref(false);
const addSearch = ref('');
const addResults = ref([]);
const addLoading = ref(false);
const addSearchInput = ref(null);
const addTimer = ref(null);

// Computed
const currentDay = computed(() => store.deliveryDays.find(d => d.date === selectedDate.value));
const currentDeadlineStatus = computed(() => currentDay.value?.deadline_status || 'closed');
const currentDeadlines = computed(() => currentDay.value?.deadlines);
const canSubmit = computed(() => ['open', 'warning'].includes(currentDeadlineStatus.value));
const canEdit = computed(() => currentDay.value?.can_edit && existingOrder.value);

// Фильтрация по текущей категории + поисковый запрос
const filteredItems = computed(() => {
  let items = orderItems.value.filter(i => i.category === activeCategory.value);
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase();
    items = items.filter(i =>
      i.product_name.toLowerCase().includes(q) ||
      i.sku.toLowerCase().includes(q)
    );
  }
  return items;
});

const totalItems = computed(() => orderItems.value.filter(i => i.quantity > 0).length);
const totalQty = computed(() => orderItems.value.reduce((s, i) => s + (parseFloat(i.quantity) || 0), 0));
const hasMultErrors = computed(() => orderItems.value.some(i => i._multError && i.quantity > 0));
const templateLoaded = ref(false);
const hasTemplateGap = computed(() => existingOrder.value && !templateLoaded.value);

function getCategoryItemCount(cat) {
  return orderItems.value.filter(i => i.category === cat && i.quantity > 0).length;
}

function checkMultiplicity(item) {
  const mult = item.multiplicity || 1;
  const qty = parseFloat(item.quantity) || 0;
  if (mult > 1 && qty > 0 && qty % mult !== 0) {
    item._multError = true;
  } else {
    item._multError = false;
  }
}

// Init
onMounted(async () => {
  if (!store.isAuthenticated) {
    const valid = await store.validate();
    if (!valid) { router.replace({ name: 'restaurant-order-login' }); return; }
  }
  try {
    await store.loadMyInfo();
    if (store.deliveryDays.length) {
      const today = new Date().toISOString().slice(0, 10);
      const nearest = store.deliveryDays.find(d => d.date >= today && d.deadline_status !== 'closed')
        || store.deliveryDays.find(d => d.date >= today)
        || store.deliveryDays[0];
      if (nearest) selectDay(nearest.date);
    }
    const orders = await store.loadMyOrders(5);
    previousOrders.value = orders.filter(o => o.status === 'submitted' || o.status === 'edited');
  } finally {
    loading.value = false;
  }
});

async function selectDay(date) {
  selectedDate.value = date;
  existingOrder.value = null;
  submitSuccess.value = false;
  submitError.value = '';
  searchQuery.value = '';
  activeCategory.value = 'Сухой';
  templateLoaded.value = false;

  const order = await store.loadMyOrder(date);
  if (order) {
    existingOrder.value = order;
    orderItems.value = order.items.map(i => ({
      sku: i.sku,
      product_name: i.product_name,
      category: i.category,
      quantity: parseFloat(i.quantity) || 0,
      comment: i.comment || '',
      multiplicity: 1,
      _added: false,
      _multError: false,
    }));
  } else {
    orderItems.value = [];
  }

  // Load template products
  for (const cat of categories) {
    const hasItems = orderItems.value.some(i => i.category === cat);
    if (!hasItems) {
      await loadCategoryProducts(cat);
    }
  }

  // Заполненные позиции наверх (один раз при загрузке, без прыжков при вводе)
  orderItems.value.sort((a, b) => {
    if (a.category !== b.category) return categories.indexOf(a.category) - categories.indexOf(b.category);
    const aFilled = a.quantity > 0 ? 0 : 1;
    const bFilled = b.quantity > 0 ? 0 : 1;
    return aFilled - bFilled;
  });

  savedSnapshot.value = takeSnapshot();
}

async function loadCategoryProducts(category) {
  productsLoading.value = true;
  try {
    const products = await store.loadProducts(category);
    const existing = new Set(orderItems.value.filter(i => i.category === category).map(i => i.sku));
    const newItems = products
      .filter(p => !existing.has(p.sku))
      .map(p => ({
        sku: p.sku,
        product_name: p.name || p.product_name,
        category: p.category || category,
        quantity: 0,
        comment: '',
        multiplicity: parseInt(p.multiplicity) || 1,
        _added: false,
        _multError: false,
      }));
    orderItems.value.push(...newItems);
  } finally {
    productsLoading.value = false;
  }
}

// Add modal search
watch(showAddModal, (v) => {
  if (v) {
    addSearch.value = '';
    addResults.value = [];
    nextTick(() => addSearchInput.value?.focus());
  }
});

function doAddSearch() {
  clearTimeout(addTimer.value);
  if (!addSearch.value || addSearch.value.length < 2) { addResults.value = []; return; }
  addTimer.value = setTimeout(async () => {
    addLoading.value = true;
    try {
      const products = await store.loadProducts(null, addSearch.value);
      const existingSkus = new Set(orderItems.value.map(i => i.sku));
      addResults.value = products.filter(p => !existingSkus.has(p.sku));
    } catch { addResults.value = []; }
    finally { addLoading.value = false; }
  }, 300);
}

function addProduct(product) {
  const cat = product.category || activeCategory.value;
  orderItems.value.push({
    sku: product.sku,
    product_name: product.name || product.product_name,
    category: cat,
    quantity: 0,
    comment: '',
    multiplicity: parseInt(product.multiplicity) || 1,
    _added: true,
    _multError: false,
  });
  addResults.value = addResults.value.filter(p => p.sku !== product.sku);
  activeCategory.value = cat;
  showAddModal.value = false;
}

async function loadTemplateGap() {
  // Загружаем все товары шаблона по каждой категории, добавляем недостающие
  const existingSkus = new Set(orderItems.value.map(i => i.sku));
  for (const cat of categories) {
    const products = await store.loadProducts(cat);
    for (const p of products) {
      if (!existingSkus.has(p.sku)) {
        orderItems.value.push({
          sku: p.sku,
          product_name: p.name || p.product_name,
          category: p.category || cat,
          quantity: 0,
          comment: '',
          multiplicity: parseInt(p.multiplicity) || 1,
          _added: false,
          _multError: false,
        });
        existingSkus.add(p.sku);
      }
    }
  }
  templateLoaded.value = true;
}

function clearOrder() {
  if (!confirm('Очистить все количества?')) return;
  for (const item of orderItems.value) {
    item.quantity = 0;
    item.comment = '';
    item._multError = false;
  }
}

function removeItem(item) {
  const idx = orderItems.value.indexOf(item);
  if (idx >= 0) orderItems.value.splice(idx, 1);
}

async function handleSubmit() {
  submitting.value = true;
  submitSuccess.value = false;
  submitError.value = '';
  try {
    const items = orderItems.value
      .filter(i => i.quantity > 0)
      .map(i => ({
        sku: i.sku,
        product_name: i.product_name,
        category: i.category,
        quantity: i.quantity,
        comment: i.comment || null,
      }));
    if (!items.length) { submitError.value = 'Добавьте хотя бы одну позицию'; return; }
    const result = await store.submitOrder(selectedDate.value, items);
    if (result.success) {
      wasEdited.value = !!existingOrder.value;
      existingOrder.value = { id: result.order_id };
      store.loadMyInfo();
      savedSnapshot.value = takeSnapshot();
      showSuccessScreen.value = true;
      startEditTimer();
    }
  } catch (e) {
    submitError.value = e.message || 'Ошибка при отправке';
  } finally {
    submitting.value = false;
  }
}

async function handleRepeat(sourceOrderId) {
  try {
    const result = await store.repeatOrder(sourceOrderId, selectedDate.value);
    if (result.items) {
      for (const item of result.items) {
        const existing = orderItems.value.find(i => i.sku === item.sku);
        if (existing) {
          existing.quantity = parseFloat(item.quantity) || 0;
          existing.comment = item.comment || '';
        } else {
          orderItems.value.push({
            sku: item.sku,
            product_name: item.product_name,
            category: item.category,
            quantity: parseFloat(item.quantity) || 0,
            comment: item.comment || '',
            multiplicity: 1,
            _added: true,
            _multError: false,
          });
        }
      }
    }
  } catch (e) {
    submitError.value = e.message || 'Ошибка при повторе заказа';
  }
}

onUnmounted(() => clearInterval(editTimerInterval));

async function exportExcel() {
  const XLSX = await import('xlsx-js-style');
  const wb = XLSX.utils.book_new();

  const header = ['Товар', 'Категория', 'Кратность', 'Кол-во (кор.)', 'Комментарий'];
  const rows = [header];

  for (const cat of categories) {
    const items = orderItems.value.filter(i => i.category === cat);
    for (const item of items) {
      const product = item.sku ? `${item.sku} ${item.product_name}` : item.product_name;
      rows.push([
        product,
        item.category,
        item.multiplicity > 1 ? item.multiplicity : '',
        item.quantity > 0 ? item.quantity : '',
        item.comment || '',
      ]);
    }
  }

  const ws = XLSX.utils.aoa_to_sheet(rows);

  // Стили заголовка
  const sH = { font: { bold: true, sz: 11, color: { rgb: 'FFFFFF' } }, fill: { fgColor: { rgb: '502314' } }, alignment: { horizontal: 'center' } };
  for (let c = 0; c < header.length; c++) {
    const cell = ws[XLSX.utils.encode_cell({ r: 0, c })];
    if (cell) cell.s = sH;
  }

  // Ширина колонок
  ws['!cols'] = [{ wch: 12 }, { wch: 40 }, { wch: 12 }, { wch: 10 }, { wch: 14 }, { wch: 20 }];

  const restNum = store.restaurant?.number || '';
  const dateStr = selectedDate.value || '';
  const sheetName = `Заказ ${restNum}`;
  XLSX.utils.book_append_sheet(wb, ws, sheetName.slice(0, 31));

  const hasFilled = orderItems.value.some(i => i.quantity > 0);
  const fileName = hasFilled
    ? `Заказ_${restNum}_${dateStr}.xlsx`
    : `Бланк_заказа_${restNum}.xlsx`;

  XLSX.writeFile(wb, fileName);
}

function handleLogout() {
  store.logout();
  router.replace({ name: 'restaurant-order-login' });
}

function formatDate(d) {
  if (!d) return '';
  return new Date(d + 'T00:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' });
}
function formatDateShort(d) {
  if (!d) return '';
  return new Date(d + 'T00:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
}
</script>

<style scoped>
.ro-page {
  min-height: 100vh;
  background: #f5f0eb;
  font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
  padding-bottom: 100px;
}
.ro-order-area,
.ro-session-bar,
.ro-day-tabs,
.ro-repeat-section {
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
.ro-loading-small { padding: 20px; text-align: center; }
.ro-spinner { width: 24px; height: 24px; border: 3px solid #e0d5c8; border-top-color: #D62300; border-radius: 50%; animation: spin 0.8s linear infinite; }
.ro-spinner-sm { width: 16px; height: 16px; border-width: 2px; }
@keyframes spin { to { transform: rotate(360deg); } }

.ro-card { background: white; border-radius: 12px; padding: 32px; margin: 20px auto; text-align: center; max-width: 900px; }
.ro-empty h2 { color: #502314; margin: 0 0 8px; }
.ro-empty p { color: #8b7355; margin: 0; }

/* Session bar */
.ro-session-bar { background: #502314; color: white; text-align: center; padding: 8px; font-size: 13px; opacity: 0.9; border-radius: 10px; margin-top: 12px; }

/* Day tabs */
.ro-day-tabs { display: flex; gap: 6px; padding: 12px 16px; overflow-x: auto; background: white; border-radius: 12px; margin-top: 12px; justify-content: center; }
.ro-day-tab { flex-shrink: 0; padding: 8px 14px; border-radius: 10px; border: 2px solid #e0d5c8; background: white; cursor: pointer; text-align: center; font-family: inherit; transition: all 0.2s; position: relative; }
.ro-day-tab.active { border-color: #D62300; background: #fff5f2; }
.ro-day-tab.submitted { border-color: #16a34a; }
.ro-day-tab.closed { opacity: 0.5; }
.ro-day-tab.warning { border-color: #f59e0b; }
.ro-day-name { display: block; font-size: 12px; font-weight: 600; color: #502314; }
.ro-day-date { display: block; font-size: 11px; color: #8b7355; }
.ro-day-badge { position: absolute; top: -6px; right: -6px; width: 18px; height: 18px; border-radius: 50%; font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
.ro-day-badge.ok { background: #16a34a; color: white; }
.ro-day-badge.closed { background: #9ca3af; color: white; }

/* Deadline bar */
.ro-deadline-bar { padding: 10px 16px; font-size: 13px; font-weight: 600; text-align: center; }
.ro-deadline-open { background: #ecfdf5; color: #16a34a; }
.ro-deadline-warning { background: #fffbeb; color: #d97706; }
.ro-deadline-closed { background: #fef2f2; color: #dc2626; }
.ro-deadline-not_yet { background: #f0f9ff; color: #2563eb; }

/* Category tabs */
.ro-cat-tabs { display: flex; gap: 0; background: white; border-bottom: 2px solid #e0d5c8; }
.ro-cat-tab { flex: 1; padding: 12px 16px; border: none; background: transparent; cursor: pointer; font-size: 14px; font-weight: 600; color: #8b7355; font-family: inherit; border-bottom: 3px solid transparent; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 6px; }
.ro-cat-tab.active { color: #D62300; border-bottom-color: #D62300; background: #fff5f2; }
.ro-cat-count { background: #D62300; color: white; font-size: 11px; padding: 1px 7px; border-radius: 10px; font-weight: 700; }

/* Search row */
.ro-search-row { display: flex; gap: 8px; padding: 12px 16px; background: white; border-bottom: 1px solid #f0ebe4; position: relative; align-items: center; }
.ro-search-input { flex: 1; padding: 10px 14px; border: 2px solid #e0d5c8; border-radius: 10px; font-size: 14px; font-family: inherit; }
.ro-search-input:focus { outline: none; border-color: #D62300; }
.ro-search-clear { background: none; border: none; cursor: pointer; font-size: 16px; color: #999; padding: 4px 8px; }
.ro-add-btn { padding: 10px 16px; border-radius: 10px; border: 2px solid #D62300; background: #fff5f2; color: #D62300; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; white-space: nowrap; transition: all 0.2s; }
.ro-add-btn:hover { background: #D62300; color: white; }
.ro-tpl-btn { padding: 10px 16px; border-radius: 10px; border: 2px solid #2563eb; background: #eff6ff; color: #2563eb; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; white-space: nowrap; transition: all 0.2s; }
.ro-tpl-btn:hover { background: #2563eb; color: white; }
.ro-excel-btn { padding: 10px 16px; border-radius: 10px; border: 2px solid #16a34a; background: #f0fdf4; color: #16a34a; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; white-space: nowrap; transition: all 0.2s; }
.ro-excel-btn:hover { background: #16a34a; color: white; }

/* Products table */
.ro-products { background: white; }
.ro-table { width: 100%; border-collapse: collapse; }
.ro-table th { padding: 8px 12px; font-size: 12px; color: #8b7355; font-weight: 600; text-align: left; border-bottom: 2px solid #e0d5c8; background: #faf7f4; }
.ro-table td { padding: 6px 12px; border-bottom: 1px solid #f0ebe4; font-size: 13px; color: #502314; }
.ro-th-sku { width: 80px; }
.ro-th-mult { width: 50px; }
.ro-th-qty { width: 120px; }
.ro-th-comment { width: 140px; }
.ro-th-actions { width: 36px; }
.ro-sku-label { font-size: 11px; color: #8b7355; margin-right: 4px; }
.ro-td-mult { text-align: center; }
.ro-mult-badge { background: #eff6ff; color: #2563eb; font-size: 11px; padding: 2px 6px; border-radius: 4px; font-weight: 600; }
.ro-row-filled { background: #f0fdf4; }
.ro-row-error { background: #fef2f2; }
.ro-qty-input { width: 80px; padding: 6px 8px; border: 2px solid #e0d5c8; border-radius: 8px; font-size: 14px; text-align: center; font-family: inherit; }
.ro-qty-input:focus { outline: none; border-color: #D62300; }
.ro-qty-error { border-color: #dc2626 !important; background: #fef2f2; }
.ro-mult-hint { font-size: 10px; color: #dc2626; margin-top: 2px; text-align: center; }
.ro-comment-input { width: 100%; padding: 6px 8px; border: 1px solid #e0d5c8; border-radius: 6px; font-size: 12px; font-family: inherit; }
.ro-comment-input:focus { outline: none; border-color: #D62300; }
.ro-remove-btn { background: none; border: none; cursor: pointer; color: #dc2626; font-size: 14px; padding: 4px; }
.ro-empty-cat { padding: 40px; text-align: center; color: #8b7355; font-size: 14px; }

/* Summary */
.ro-summary { background: white; padding: 12px 16px; border-top: 2px solid #e0d5c8; display: flex; justify-content: center; gap: 24px; }
.ro-summary-stats { display: flex; gap: 20px; font-size: 14px; color: #502314; }
.ro-summary-stats strong { color: #D62300; }
.ro-clear-btn { padding: 6px 14px; border-radius: 8px; border: 1px solid #dc2626; background: transparent; color: #dc2626; font-size: 12px; cursor: pointer; font-family: inherit; transition: all 0.2s; }
.ro-clear-btn:hover { background: #dc2626; color: white; }

/* Actions */
.ro-actions { padding: 16px; text-align: center; }
.ro-submit-btn { padding: 14px 40px; background: #D62300; color: white; border: none; border-radius: 12px; font-size: 16px; font-weight: 700; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 8px; transition: background 0.2s; }
.ro-submit-btn:hover:not(:disabled) { background: #b81e00; }
.ro-submit-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.ro-success-msg { margin-top: 12px; padding: 10px 16px; border-radius: 8px; background: #ecfdf5; color: #16a34a; font-weight: 600; }
.ro-error-msg { margin-top: 12px; padding: 10px 16px; border-radius: 8px; background: #fef2f2; color: #dc2626; font-size: 13px; }
.ro-locked-msg { color: #dc2626; font-size: 14px; font-weight: 600; }

/* Repeat */
.ro-repeat-section { padding: 16px; margin: 0 16px; background: white; border-radius: 12px; margin-top: 12px; }
.ro-repeat-title { font-size: 13px; font-weight: 600; color: #502314; margin-bottom: 8px; }
.ro-repeat-btn { display: block; width: 100%; padding: 10px 14px; border: 1px solid #e0d5c8; border-radius: 8px; background: white; cursor: pointer; font-size: 13px; font-family: inherit; color: #502314; text-align: left; transition: background 0.15s; margin-bottom: 6px; }
.ro-repeat-btn:hover { background: #f5f0eb; }

/* Add modal */
.ro-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 20px; }
.ro-modal { background: white; border-radius: 16px; width: 100%; max-width: 500px; max-height: 80vh; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 8px 40px rgba(0,0,0,0.2); }
.ro-modal-header { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-bottom: 1px solid #e0d5c8; }
.ro-modal-header h2 { margin: 0; font-size: 18px; color: #502314; }
.ro-modal-close { background: none; border: none; cursor: pointer; font-size: 18px; color: #999; }
.ro-modal-body { padding: 16px 20px; overflow-y: auto; flex: 1; }
.ro-modal-search { width: 100%; margin-bottom: 12px; box-sizing: border-box; }
.ro-add-list { display: flex; flex-direction: column; gap: 4px; }
.ro-add-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; border-radius: 8px; cursor: pointer; transition: background 0.15s; border: 1px solid #f0ebe4; }
.ro-add-item:hover { background: #f5f0eb; border-color: #D62300; }
.ro-add-item-info { display: flex; align-items: center; gap: 8px; flex: 1; min-width: 0; }
.ro-add-sku { font-size: 11px; color: #8b7355; min-width: 60px; flex-shrink: 0; }
.ro-add-name { font-size: 13px; color: #502314; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ro-add-item-meta { display: flex; gap: 6px; align-items: center; flex-shrink: 0; }
.ro-add-cat { font-size: 11px; color: #8b7355; background: #f5f0eb; padding: 2px 8px; border-radius: 4px; }

/* Success screen */
.ro-success-screen { display: flex; align-items: center; justify-content: center; min-height: 60vh; padding: 20px; }
.ro-success-card { background: white; border-radius: 20px; padding: 40px 32px; text-align: center; max-width: 420px; width: 100%; box-shadow: 0 4px 24px rgba(80,35,20,0.1); }
.ro-success-icon { width: 64px; height: 64px; border-radius: 50%; background: #16a34a; color: white; font-size: 32px; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; }
.ro-success-title { color: #502314; margin: 0 0 8px; font-size: 22px; }
.ro-success-date { color: #8b7355; margin: 0 0 4px; font-size: 15px; }
.ro-success-stats { color: #502314; margin: 0 0 24px; font-size: 14px; font-weight: 600; }
.ro-success-timer { background: #f0fdf4; border-radius: 12px; padding: 16px; margin-bottom: 24px; }
.ro-success-timer-expired { background: #fef2f2; }
.ro-success-timer-label { margin: 0 0 8px; font-size: 13px; color: #8b7355; }
.ro-success-countdown { font-size: 32px; font-weight: 700; color: #16a34a; font-variant-numeric: tabular-nums; letter-spacing: 2px; }
.ro-success-timer-expired .ro-success-timer-label { color: #dc2626; }
.ro-success-actions { display: flex; flex-direction: column; gap: 10px; align-items: center; }
.ro-success-new, .ro-success-history { display: inline-block; padding: 10px 24px; border-radius: 10px; border: 2px solid #e0d5c8; background: white; color: #502314; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; font-family: inherit; transition: all 0.2s; }
.ro-success-new:hover, .ro-success-history:hover { background: #f5f0eb; }

/* Mobile */
@media (max-width: 600px) {
  .ro-page { padding-bottom: 80px; }
  .ro-header { padding: 10px 12px; }
  .ro-header-title { font-size: 14px; }
  .ro-header-addr { font-size: 11px; }
  .ro-link-btn { padding: 5px 10px; font-size: 12px; }

  .ro-session-bar { margin: 8px 8px 0; font-size: 12px; }
  .ro-day-tabs { padding: 8px 6px; gap: 4px; justify-content: flex-start; margin-top: 8px; border-radius: 10px; margin-left: 8px; margin-right: 8px; }
  .ro-day-tab { padding: 6px 10px; }
  .ro-day-name { font-size: 11px; }
  .ro-day-date { font-size: 10px; }

  .ro-order-area { margin: 8px; border-radius: 12px; }
  .ro-deadline-bar { font-size: 12px; padding: 8px 12px; }
  .ro-cat-tab { padding: 10px 8px; font-size: 13px; }

  /* Поиск и кнопки */
  .ro-search-row { flex-wrap: wrap; padding: 10px 12px; gap: 6px; }
  .ro-search-input { width: 100%; flex: none; padding: 8px 12px; font-size: 14px; }
  .ro-add-btn, .ro-tpl-btn, .ro-excel-btn { flex: 1; text-align: center; padding: 8px 12px; font-size: 12px; }

  /* Таблица — карточный вид */
  .ro-table { display: block; }
  .ro-table thead { display: none; }
  .ro-table tbody { display: block; }
  .ro-table tr {
    display: flex; flex-wrap: wrap; align-items: center;
    padding: 10px 12px; gap: 4px 8px;
    border-bottom: 1px solid #f0ebe4;
  }
  .ro-table td { padding: 0; border: none; }
  .ro-sku-label { font-size: 10px; }
  .ro-td-name { flex: 1 1 100%; font-size: 13px; font-weight: 500; order: -1; }
  .ro-td-mult { order: 1; }
  .ro-td-qty { order: 2; margin-left: auto; }
  .ro-qty-input { width: 70px; padding: 8px 6px; font-size: 16px; }
  .ro-td-comment { flex: 1 1 100%; order: 3; margin-top: 4px; }
  .ro-comment-input { font-size: 13px; padding: 6px 8px; }
  .ro-td-actions { order: 4; }
  .ro-row-filled { background: #f0fdf4; }

  /* Итоги */
  .ro-summary { flex-wrap: wrap; gap: 10px; padding: 10px 12px; justify-content: space-between; }
  .ro-submit-btn { width: 100%; padding: 14px; font-size: 16px; }

  /* Повторение */
  .ro-repeat-section { margin: 8px; padding: 12px; }

  /* Модалка */
  .ro-modal { max-width: 100%; margin: 10px; border-radius: 12px; max-height: 90vh; }

  /* Экран успеха */
  .ro-success-card { padding: 28px 20px; }
  .ro-success-countdown { font-size: 28px; }
}
</style>
