<template>
  <div class="rr">
    <div class="rr-header">
      <h1>Отчёт по заказам ресторанов</h1>
      <div class="rr-actions">
        <button class="rr-btn rr-btn-green" @click="exportExcel" :disabled="!reportData.length">Excel</button>
        <router-link :to="{ name: 'restaurant-orders' }" class="rr-btn rr-btn-outline">Заказы</router-link>
      </div>
    </div>

    <!-- Фильтры -->
    <div class="rr-filters">
      <div class="rr-filter-row">
        <div class="rr-field">
          <label>Период с</label>
          <input type="date" v-model="dateFrom" class="rr-input" />
        </div>
        <div class="rr-field">
          <label>по</label>
          <input type="date" v-model="dateTo" class="rr-input" />
        </div>
        <div class="rr-field">
          <label>Режим</label>
          <select v-model="category" class="rr-input">
            <option value="">Все</option>
            <option value="Сухой">Сухой</option>
            <option value="Холод">Холод</option>
            <option value="Мороз">Мороз</option>
          </select>
        </div>
        <div class="rr-field">
          <label>Статус</label>
          <select v-model="status" class="rr-input">
            <option value="">Все</option>
            <option value="submitted">Подано</option>
            <option value="edited">Изменён</option>
          </select>
        </div>
        <div class="rr-field">
          <label>Рестораны</label>
          <div class="rr-rest-select" @click="showRestPicker = !showRestPicker">
            {{ selectedRestaurants.length ? selectedRestaurants.length + ' выбр.' : 'Все' }}
            <span class="rr-chevron">&#9662;</span>
          </div>
          <div v-if="showRestPicker" class="rr-rest-dropdown">
            <div class="rr-rest-actions">
              <button @click="selectedRestaurants = [...restaurantList]">Все</button>
              <button @click="selectedRestaurants = []">Сбросить</button>
            </div>
            <label v-for="r in restaurantList" :key="r" class="rr-rest-option">
              <input type="checkbox" :value="r" v-model="selectedRestaurants" />
              {{ r }}
            </label>
          </div>
        </div>
        <button class="rr-btn rr-btn-primary" @click="loadReport" :disabled="loading">
          <span v-if="loading" class="rr-spin"></span>
          Показать
        </button>
      </div>
      <!-- Поиск по товару -->
      <div v-if="loaded" class="rr-search-row">
        <input v-model="searchQuery" type="text" class="rr-input rr-search-input" placeholder="Поиск по артикулу или названию товара..." />
        <span v-if="searchQuery" class="rr-search-hint">
          Найдено: {{ filteredData.length }} из {{ reportData.length }} позиций
        </span>
      </div>
    </div>

    <!-- Группировка -->
    <div class="rr-group-bar">
      <span class="rr-group-label">Группировка:</span>
      <button v-for="g in groupOptions" :key="g.id" class="rr-group-btn" :class="{ active: groupBy === g.id }" @click="groupBy = g.id">
        {{ g.label }}
      </button>
    </div>

    <!-- Loader -->
    <div v-if="loading" class="rr-loader"><div class="rr-spin rr-spin-lg"></div></div>

    <!-- Нет данных -->
    <div v-else-if="loaded && !filteredData.length" class="rr-empty">
      {{ searchQuery ? 'Ничего не найдено по запросу «' + searchQuery + '»' : 'Нет данных за выбранный период' }}
    </div>

    <!-- ═══ Таблица: Позиции (детально) ═══ -->
    <div v-else-if="loaded && groupBy === 'items'" class="rr-table-wrap">
      <table class="rr-table">
        <thead>
          <tr>
            <th @click="sortTable('delivery_date')">Дата {{ sortIcon('delivery_date') }}</th>
            <th @click="sortTable('restaurant_number')">Рест. {{ sortIcon('restaurant_number') }}</th>
            <th>Город</th>
            <th class="rr-th-name" @click="sortTable('product_name')">Товар {{ sortIcon('product_name') }}</th>
            <th class="rr-th-cat" @click="sortTable('category')">Режим {{ sortIcon('category') }}</th>
            <th class="rr-th-num" @click="sortTable('quantity')">Кол-во {{ sortIcon('quantity') }}</th>
            <th style="width:90px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in sortedItemsData" :key="row.id" class="rr-item-row">
            <td>{{ fmtDate(row.delivery_date) }}</td>
            <td><strong>{{ row.restaurant_number }}</strong></td>
            <td class="rr-city">{{ row.city }}</td>
            <td>
              <span class="rr-sku">{{ row.sku }}</span> {{ row.product_name }}
              <span v-if="row.comment" class="rr-comment" :title="row.comment">💬</span>
            </td>
            <td><span class="rr-cat-badge" :class="'cat-' + row.category">{{ row.category }}</span></td>
            <td class="rr-num">{{ fmtNum(row.quantity) }}</td>
            <td class="rr-item-actions">
              <button class="rr-action-btn rr-action-goto" @click="goToOrder(row)" title="Открыть заказ">→</button>
              <button class="rr-action-btn rr-action-del" @click="deleteItem(row)" title="Удалить позицию">✕</button>
            </td>
          </tr>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="5"><strong>Итого: {{ sortedItemsData.length }} позиций, {{ itemsRestCount }} рест., {{ itemsOrderCount }} заказов</strong></td>
            <td class="rr-num"><strong>{{ fmtNum(itemsTotalQty) }}</strong></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <!-- ═══ Таблица: По товарам ═══ -->
    <div v-else-if="loaded && groupBy === 'product'" class="rr-table-wrap">
      <table class="rr-table">
        <thead>
          <tr>
            <th class="rr-th-name" @click="sortTable('name')">Товар {{ sortIcon('name') }}</th>
            <th class="rr-th-cat" @click="sortTable('category')">Режим {{ sortIcon('category') }}</th>
            <th class="rr-th-num" @click="sortTable('totalQty')">Всего (кор.) {{ sortIcon('totalQty') }}</th>
            <th class="rr-th-num" @click="sortTable('orderCount')">Заказов {{ sortIcon('orderCount') }}</th>
            <th class="rr-th-num" @click="sortTable('restCount')">Ресторанов {{ sortIcon('restCount') }}</th>
            <th class="rr-th-num" @click="sortTable('avgQty')">Ср. на заказ {{ sortIcon('avgQty') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in sortedProductData" :key="row.sku" class="rr-clickable" @click="drillDown(row.sku, row.name)">
            <td><span class="rr-sku">{{ row.sku }}</span> {{ row.name }}</td>
            <td><span class="rr-cat-badge" :class="'cat-' + row.category">{{ row.category }}</span></td>
            <td class="rr-num">{{ fmtNum(row.totalQty) }}</td>
            <td class="rr-num">{{ row.orderCount }}</td>
            <td class="rr-num">{{ row.restCount }}</td>
            <td class="rr-num">{{ fmtNum(row.avgQty) }}</td>
          </tr>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="2"><strong>Итого: {{ sortedProductData.length }} товаров</strong></td>
            <td class="rr-num"><strong>{{ fmtNum(totalQty) }}</strong></td>
            <td class="rr-num"><strong>{{ totalOrders }}</strong></td>
            <td></td><td></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <!-- ═══ Таблица: По ресторанам ═══ -->
    <div v-else-if="loaded && groupBy === 'restaurant'" class="rr-table-wrap">
      <table class="rr-table">
        <thead>
          <tr>
            <th @click="sortTable('number')">Ресторан {{ sortIcon('number') }}</th>
            <th @click="sortTable('city')">Город {{ sortIcon('city') }}</th>
            <th class="rr-th-num" @click="sortTable('totalQty')">Коробок {{ sortIcon('totalQty') }}</th>
            <th class="rr-th-num" @click="sortTable('itemCount')">Позиций {{ sortIcon('itemCount') }}</th>
            <th class="rr-th-num" @click="sortTable('orderCount')">Заказов {{ sortIcon('orderCount') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in sortedRestData" :key="row.number">
            <td><strong>{{ row.number }}</strong></td>
            <td>{{ row.city }}</td>
            <td class="rr-num">{{ fmtNum(row.totalQty) }}</td>
            <td class="rr-num">{{ row.itemCount }}</td>
            <td class="rr-num">{{ row.orderCount }}</td>
          </tr>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="2"><strong>Итого: {{ sortedRestData.length }} ресторанов</strong></td>
            <td class="rr-num"><strong>{{ fmtNum(totalQty) }}</strong></td>
            <td class="rr-num"><strong>{{ totalItems }}</strong></td>
            <td class="rr-num"><strong>{{ totalOrders }}</strong></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <!-- ═══ Таблица: По дням ═══ -->
    <div v-else-if="loaded && groupBy === 'day'" class="rr-table-wrap">
      <table class="rr-table">
        <thead>
          <tr>
            <th @click="sortTable('date')">Дата {{ sortIcon('date') }}</th>
            <th @click="sortTable('dayName')">День {{ sortIcon('dayName') }}</th>
            <th class="rr-th-num" @click="sortTable('totalQty')">Коробок {{ sortIcon('totalQty') }}</th>
            <th class="rr-th-num" @click="sortTable('itemCount')">Позиций {{ sortIcon('itemCount') }}</th>
            <th class="rr-th-num" @click="sortTable('orderCount')">Заказов {{ sortIcon('orderCount') }}</th>
            <th class="rr-th-num" @click="sortTable('restCount')">Ресторанов {{ sortIcon('restCount') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in sortedDayData" :key="row.date">
            <td>{{ fmtDate(row.date) }}</td>
            <td>{{ row.dayName }}</td>
            <td class="rr-num">{{ fmtNum(row.totalQty) }}</td>
            <td class="rr-num">{{ row.itemCount }}</td>
            <td class="rr-num">{{ row.orderCount }}</td>
            <td class="rr-num">{{ row.restCount }}</td>
          </tr>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="2"><strong>Итого: {{ sortedDayData.length }} дней</strong></td>
            <td class="rr-num"><strong>{{ fmtNum(totalQty) }}</strong></td>
            <td class="rr-num"><strong>{{ totalItems }}</strong></td>
            <td class="rr-num"><strong>{{ totalOrders }}</strong></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <!-- ═══ Таблица: По режимам ═══ -->
    <div v-else-if="loaded && groupBy === 'category'" class="rr-table-wrap">
      <table class="rr-table">
        <thead>
          <tr>
            <th>Режим</th>
            <th class="rr-th-num">Коробок</th>
            <th class="rr-th-num">Позиций (уник.)</th>
            <th class="rr-th-num">Строк заказов</th>
            <th class="rr-th-num">% от общего</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in categoryData" :key="row.category">
            <td><span class="rr-cat-badge" :class="'cat-' + row.category">{{ row.category }}</span></td>
            <td class="rr-num">{{ fmtNum(row.totalQty) }}</td>
            <td class="rr-num">{{ row.uniqueProducts }}</td>
            <td class="rr-num">{{ row.lineCount }}</td>
            <td class="rr-num">{{ totalQty ? Math.round(row.totalQty / totalQty * 100) : 0 }}%</td>
          </tr>
        </tbody>
        <tfoot>
          <tr>
            <td><strong>Итого</strong></td>
            <td class="rr-num"><strong>{{ fmtNum(totalQty) }}</strong></td>
            <td></td>
            <td class="rr-num"><strong>{{ totalItems }}</strong></td>
            <td class="rr-num">100%</td>
          </tr>
        </tfoot>
      </table>
    </div>

    <!-- ═══ Кросс-таблица ═══ -->
    <div v-else-if="loaded && groupBy === 'cross'" class="rr-table-wrap rr-cross-wrap">
      <table class="rr-table rr-cross">
        <thead>
          <tr>
            <th class="rr-cross-corner">Товар</th>
            <th v-for="r in crossRestaurants" :key="r" class="rr-cross-head">{{ r }}</th>
            <th class="rr-cross-total">Итого</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in crossData" :key="row.sku">
            <td class="rr-cross-name"><span class="rr-sku">{{ row.sku }}</span> {{ row.name }}</td>
            <td v-for="r in crossRestaurants" :key="r" class="rr-cross-cell" :class="{ 'rr-cross-has': row.byRest[r] }">
              {{ row.byRest[r] || '' }}
            </td>
            <td class="rr-cross-total rr-num"><strong>{{ fmtNum(row.total) }}</strong></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Диалог подтверждения удаления -->
    <div v-if="deleteConfirm" class="rr-overlay" @click.self="deleteConfirm = null">
      <div class="rr-confirm">
        <p>Удалить <strong>{{ deleteConfirm.sku }} {{ deleteConfirm.product_name }}</strong> ({{ deleteConfirm.quantity }} кор.) из заказа ресторана <strong>{{ deleteConfirm.restaurant_number }}</strong> на {{ fmtDate(deleteConfirm.delivery_date) }}?</p>
        <div class="rr-confirm-btns">
          <button class="rr-btn rr-btn-danger" @click="confirmDelete" :disabled="deleting">{{ deleting ? 'Удаление...' : 'Удалить' }}</button>
          <button class="rr-btn rr-btn-outline" @click="deleteConfirm = null">Отмена</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { useRestaurantOrderStore } from '@/stores/restaurantOrderStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { EXCEL_HEADER_STYLE } from '@/lib/roUtils.js';

const store = useRestaurantOrderStore();
const toast = useToastStore();
const router = useRouter();

const today = new Date();
const weekAgo = new Date(today); weekAgo.setDate(weekAgo.getDate() - 7);
const weekAhead = new Date(today); weekAhead.setDate(weekAhead.getDate() + 7);
const dateFrom = ref(weekAgo.toISOString().slice(0, 10));
const dateTo = ref(weekAhead.toISOString().slice(0, 10));
const category = ref('');
const status = ref('');
const selectedRestaurants = ref([]);
const showRestPicker = ref(false);
const loading = ref(false);
const loaded = ref(false);
const groupBy = ref('items');
const sortKey = ref('');
const sortDir = ref('asc');
const searchQuery = ref('');

const rawOrders = ref([]);
const rawItems = ref([]);
const restaurantList = ref([]);

const deleteConfirm = ref(null);
const deleting = ref(false);

const groupOptions = [
  { id: 'items', label: 'Позиции' },
  { id: 'product', label: 'По товарам' },
  { id: 'restaurant', label: 'По ресторанам' },
  { id: 'day', label: 'По дням' },
  { id: 'category', label: 'По режимам' },
  { id: 'cross', label: 'Кросс-таблица' },
];

const DAY_NAMES = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];

async function loadReport() {
  loading.value = true;
  try {
    const params = new URLSearchParams({ date_from: dateFrom.value, date_to: dateTo.value });
    if (category.value) params.set('category', category.value);
    if (status.value) params.set('status', status.value);
    if (selectedRestaurants.value.length) params.set('restaurants', selectedRestaurants.value.join(','));

    const token = localStorage.getItem('bk_session_token') || '';
    const res = await fetch(`/api/ro/admin/report?${params}`, {
      headers: { 'X-Session-Token': token },
    });
    const data = await res.json();
    rawOrders.value = data.orders || [];
    rawItems.value = data.items || [];
    restaurantList.value = data.restaurant_list || [];
    loaded.value = true;
    sortKey.value = '';
  } catch (e) { console.error(e); }
  finally { loading.value = false; }
}

// Enriched items: add restaurant_number, delivery_date, city from orders
const reportData = computed(() => {
  const orderMap = {};
  for (const o of rawOrders.value) orderMap[o.id] = o;
  return rawItems.value.map(item => {
    const order = orderMap[item.order_id] || {};
    return { ...item, restaurant_number: order.restaurant_number, delivery_date: order.delivery_date, city: order.city || '' };
  });
});

// Filtered by search query
const filteredData = computed(() => {
  if (!searchQuery.value) return reportData.value;
  const q = searchQuery.value.toLowerCase();
  return reportData.value.filter(item =>
    (item.sku || '').toLowerCase().includes(q) ||
    (item.product_name || '').toLowerCase().includes(q)
  );
});

// ═══ Group: Items (detail) ═══
const sortedItemsData = computed(() => applySorted(filteredData.value));
const itemsTotalQty = computed(() => sortedItemsData.value.reduce((s, i) => s + (parseFloat(i.quantity) || 0), 0));
const itemsRestCount = computed(() => new Set(sortedItemsData.value.map(i => i.restaurant_number)).size);
const itemsOrderCount = computed(() => new Set(sortedItemsData.value.map(i => i.order_id)).size);

// ═══ Group: By Product ═══
const productData = computed(() => {
  const map = {};
  for (const item of filteredData.value) {
    const key = item.sku;
    if (!map[key]) map[key] = { sku: item.sku, name: item.product_name, category: item.category, totalQty: 0, orderCount: 0, rests: new Set() };
    map[key].totalQty += parseFloat(item.quantity) || 0;
    map[key].orderCount++;
    if (item.restaurant_number) map[key].rests.add(item.restaurant_number);
  }
  return Object.values(map).map(r => ({ ...r, restCount: r.rests.size, avgQty: r.orderCount ? +(r.totalQty / r.orderCount).toFixed(1) : 0 }));
});

// ═══ Group: By Restaurant ═══
const restData = computed(() => {
  const map = {};
  for (const item of filteredData.value) {
    const key = item.restaurant_number;
    if (!map[key]) map[key] = { number: key, city: item.city, totalQty: 0, itemCount: 0, orders: new Set() };
    map[key].totalQty += parseFloat(item.quantity) || 0;
    map[key].itemCount++;
    if (item.order_id) map[key].orders.add(item.order_id);
  }
  return Object.values(map).map(r => ({ ...r, orderCount: r.orders.size }));
});

// ═══ Group: By Day ═══
const dayData = computed(() => {
  const map = {};
  for (const item of filteredData.value) {
    const key = item.delivery_date;
    if (!key) continue;
    if (!map[key]) { const d = new Date(key + 'T00:00:00'); map[key] = { date: key, dayName: DAY_NAMES[d.getDay()], totalQty: 0, itemCount: 0, orders: new Set(), rests: new Set() }; }
    map[key].totalQty += parseFloat(item.quantity) || 0;
    map[key].itemCount++;
    if (item.order_id) map[key].orders.add(item.order_id);
    if (item.restaurant_number) map[key].rests.add(item.restaurant_number);
  }
  return Object.values(map).map(r => ({ ...r, orderCount: r.orders.size, restCount: r.rests.size }));
});

// ═══ Group: By Category ═══
const categoryData = computed(() => {
  const map = {};
  for (const item of filteredData.value) {
    const key = item.category || 'Без категории';
    if (!map[key]) map[key] = { category: key, totalQty: 0, lineCount: 0, products: new Set() };
    map[key].totalQty += parseFloat(item.quantity) || 0;
    map[key].lineCount++;
    map[key].products.add(item.sku);
  }
  return Object.values(map).map(r => ({ ...r, uniqueProducts: r.products.size }));
});

// ═══ Cross table ═══
const crossRestaurants = computed(() => {
  const rests = new Set();
  for (const item of filteredData.value) if (item.restaurant_number) rests.add(item.restaurant_number);
  return [...rests].sort((a, b) => a - b);
});
const crossData = computed(() => {
  const map = {};
  for (const item of filteredData.value) {
    const key = item.sku;
    if (!map[key]) map[key] = { sku: item.sku, name: item.product_name, byRest: {}, total: 0 };
    const qty = parseFloat(item.quantity) || 0;
    map[key].byRest[item.restaurant_number] = (map[key].byRest[item.restaurant_number] || 0) + qty;
    map[key].total += qty;
  }
  return Object.values(map).sort((a, b) => a.name.localeCompare(b.name));
});

// Totals
const totalQty = computed(() => filteredData.value.reduce((s, i) => s + (parseFloat(i.quantity) || 0), 0));
const totalItems = computed(() => filteredData.value.length);
const totalOrders = computed(() => new Set(filteredData.value.map(i => i.order_id)).size);

// Sorting
function sortTable(key) {
  if (sortKey.value === key) sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
  else { sortKey.value = key; sortDir.value = key === 'name' || key === 'date' || key === 'delivery_date' || key === 'city' || key === 'dayName' || key === 'product_name' ? 'asc' : 'desc'; }
}
function sortIcon(key) { if (sortKey.value !== key) return ''; return sortDir.value === 'asc' ? '\u25B2' : '\u25BC'; }
function applySorted(data) {
  if (!sortKey.value) return data;
  const k = sortKey.value; const dir = sortDir.value === 'asc' ? 1 : -1;
  return [...data].sort((a, b) => {
    const va = a[k], vb = b[k];
    if (typeof va === 'number') return (va - vb) * dir;
    return String(va || '').localeCompare(String(vb || ''), 'ru') * dir;
  });
}
const sortedProductData = computed(() => applySorted(productData.value));
const sortedRestData = computed(() => applySorted(restData.value));
const sortedDayData = computed(() => applySorted(dayData.value));

function fmtNum(n) { if (!n) return '0'; const v = parseFloat(n); return v % 1 === 0 ? v.toLocaleString('ru-RU') : v.toFixed(1); }
function fmtDate(d) { if (!d) return ''; return new Date(d + 'T00:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }); }

// ═══ Actions ═══

// Клик по товару в группировке "По товарам" → переключиться на "Позиции" с поиском
function drillDown(sku) {
  searchQuery.value = sku;
  groupBy.value = 'items';
  sortKey.value = 'restaurant_number';
  sortDir.value = 'asc';
}

// Переход к заказу в менеджере
function goToOrder(item) {
  router.push({ name: 'restaurant-orders', query: { date: item.delivery_date, order: item.order_id, t: Date.now() } });
}

// Удаление позиции
function deleteItem(item) {
  deleteConfirm.value = item;
}

async function confirmDelete() {
  if (!deleteConfirm.value) return;
  deleting.value = true;
  try {
    const result = await store.adminDeleteItem(deleteConfirm.value.id);
    // Удаляем из локальных данных
    const itemId = deleteConfirm.value.id;
    const orderId = deleteConfirm.value.order_id;
    rawItems.value = rawItems.value.filter(i => i.id !== itemId);
    if (result.order_deleted) {
      rawOrders.value = rawOrders.value.filter(o => o.id !== orderId);
    }
    toast.success('Позиция удалена');
    deleteConfirm.value = null;
  } catch (e) {
    toast.error('Ошибка удаления', e.message);
  } finally {
    deleting.value = false;
  }
}

// Excel
async function exportExcel() {
  const XLSX = await import('xlsx-js-style');
  const wb = XLSX.utils.book_new();
  const hStyle = { ...EXCEL_HEADER_STYLE, alignment: { horizontal: 'center' } };

  // Sheet 1: По товарам
  const prodRows = [['Артикул', 'Товар', 'Режим', 'Всего (кор.)', 'Заказов', 'Ресторанов', 'Ср. на заказ']];
  for (const r of sortedProductData.value) prodRows.push([r.sku, r.name, r.category, r.totalQty, r.orderCount, r.restCount, r.avgQty]);
  const ws1 = XLSX.utils.aoa_to_sheet(prodRows);
  for (let c = 0; c < 7; c++) { const cell = ws1[XLSX.utils.encode_cell({ r: 0, c })]; if (cell) cell.s = hStyle; }
  ws1['!cols'] = [{ wch: 12 }, { wch: 35 }, { wch: 10 }, { wch: 14 }, { wch: 10 }, { wch: 12 }, { wch: 12 }];
  XLSX.utils.book_append_sheet(wb, ws1, 'По товарам');

  // Sheet 2: По ресторанам
  const restRows = [['Ресторан', 'Город', 'Коробок', 'Позиций', 'Заказов']];
  for (const r of sortedRestData.value) restRows.push([r.number, r.city, r.totalQty, r.itemCount, r.orderCount]);
  const ws2 = XLSX.utils.aoa_to_sheet(restRows);
  for (let c = 0; c < 5; c++) { const cell = ws2[XLSX.utils.encode_cell({ r: 0, c })]; if (cell) cell.s = hStyle; }
  ws2['!cols'] = [{ wch: 10 }, { wch: 20 }, { wch: 12 }, { wch: 10 }, { wch: 10 }];
  XLSX.utils.book_append_sheet(wb, ws2, 'По ресторанам');

  // Sheet 3: Кросс-таблица
  const crossHead = ['Артикул', 'Товар', ...crossRestaurants.value.map(r => `Рест ${r}`), 'Итого'];
  const crossRows = [crossHead];
  for (const r of crossData.value) {
    crossRows.push([r.sku, r.name, ...crossRestaurants.value.map(rest => r.byRest[rest] || ''), r.total]);
  }
  const ws3 = XLSX.utils.aoa_to_sheet(crossRows);
  for (let c = 0; c < crossHead.length; c++) { const cell = ws3[XLSX.utils.encode_cell({ r: 0, c })]; if (cell) cell.s = hStyle; }
  ws3['!cols'] = [{ wch: 12 }, { wch: 30 }, ...crossRestaurants.value.map(() => ({ wch: 8 })), { wch: 10 }];
  XLSX.utils.book_append_sheet(wb, ws3, 'Кросс-таблица');

  XLSX.writeFile(wb, `Отчёт_заказы_${dateFrom.value}_${dateTo.value}.xlsx`);
}
</script>

<style scoped>
.rr { padding: 0; }
.rr-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
.rr-header h1 { margin: 0; font-size: 20px; color: #502314; }
.rr-actions { display: flex; gap: 8px; }

/* Filters */
.rr-filters { background: white; border-radius: 10px; padding: 14px 16px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
.rr-filter-row { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
.rr-field { display: flex; flex-direction: column; gap: 3px; position: relative; }
.rr-field label { font-size: 11px; font-weight: 600; color: #8b7355; }
.rr-input { padding: 7px 10px; border: 1.5px solid #e0dbd5; border-radius: 8px; font-size: 13px; font-family: inherit; background: white; min-width: 120px; }
.rr-input:focus { outline: none; border-color: #D62300; }
.rr-search-row { display: flex; align-items: center; gap: 12px; margin-top: 10px; }
.rr-search-input { flex: 1; min-width: 250px; }
.rr-search-hint { font-size: 12px; color: #8b7355; white-space: nowrap; }
.rr-rest-select { padding: 7px 10px; border: 1.5px solid #e0dbd5; border-radius: 8px; font-size: 13px; cursor: pointer; min-width: 100px; display: flex; justify-content: space-between; align-items: center; gap: 6px; background: white; }
.rr-rest-select:hover { border-color: #c4b8a8; }
.rr-chevron { font-size: 10px; color: #8b7355; }
.rr-rest-dropdown { position: absolute; top: 100%; left: 0; background: white; border: 1.5px solid #e0dbd5; border-radius: 8px; padding: 8px; z-index: 100; max-height: 200px; overflow-y: auto; min-width: 140px; box-shadow: 0 4px 16px rgba(0,0,0,0.12); }
.rr-rest-actions { display: flex; gap: 4px; margin-bottom: 6px; border-bottom: 1px solid #ede8e3; padding-bottom: 6px; }
.rr-rest-actions button { background: none; border: none; cursor: pointer; font-size: 11px; color: #2563eb; font-family: inherit; padding: 2px 4px; }
.rr-rest-option { display: flex; align-items: center; gap: 6px; padding: 2px 0; font-size: 12px; cursor: pointer; color: #502314; }
.rr-rest-option input { margin: 0; }

/* Group bar */
.rr-group-bar { display: flex; align-items: center; gap: 6px; margin-bottom: 12px; flex-wrap: wrap; }
.rr-group-label { font-size: 12px; font-weight: 600; color: #8b7355; }
.rr-group-btn { padding: 5px 12px; border-radius: 6px; border: 1.5px solid #e0dbd5; background: white; cursor: pointer; font-size: 12px; font-weight: 600; font-family: inherit; color: #502314; transition: all 0.15s; }
.rr-group-btn.active { background: #502314; color: white; border-color: #502314; }
.rr-group-btn:hover:not(.active) { border-color: #D62300; color: #D62300; }

/* Buttons */
.rr-btn { padding: 7px 16px; border-radius: 8px; border: none; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
.rr-btn-primary { background: #D62300; color: white; }
.rr-btn-primary:hover:not(:disabled) { background: #b81e00; }
.rr-btn-primary:disabled { opacity: 0.5; }
.rr-btn-green { background: #16a34a; color: white; }
.rr-btn-green:hover:not(:disabled) { background: #15803d; }
.rr-btn-green:disabled { opacity: 0.4; }
.rr-btn-outline { border: 1.5px solid #e0dbd5; background: white; color: #502314; }
.rr-btn-outline:hover { border-color: #D62300; color: #D62300; }
.rr-btn-danger { background: #dc2626; color: white; }
.rr-btn-danger:hover:not(:disabled) { background: #b91c1c; }
.rr-btn-danger:disabled { opacity: 0.5; }

/* Loader */
.rr-loader { padding: 60px; text-align: center; }
.rr-spin { width: 20px; height: 20px; border: 2px solid #ede8e3; border-top-color: #D62300; border-radius: 50%; animation: rr-spin 0.7s linear infinite; display: inline-block; }
.rr-spin-lg { width: 28px; height: 28px; border-width: 3px; }
@keyframes rr-spin { to { transform: rotate(360deg); } }

.rr-empty { padding: 40px; text-align: center; color: #8b7355; font-size: 14px; }

/* Table */
.rr-table-wrap { overflow-x: auto; background: white; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
.rr-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.rr-table th { padding: 8px 10px; text-align: left; font-size: 11px; font-weight: 700; color: #8b7355; background: #faf8f5; border-bottom: 2px solid #ede8e3; cursor: pointer; user-select: none; white-space: nowrap; }
.rr-table th:hover { color: #502314; }
.rr-table td { padding: 7px 10px; border-bottom: 1px solid #f3eeea; color: #502314; }
.rr-table tbody tr:hover { background: #faf8f5; }
.rr-table tfoot td { padding: 10px; background: #faf8f5; border-top: 2px solid #ede8e3; font-size: 12px; }
.rr-th-name { min-width: 200px; }
.rr-th-cat { width: 80px; }
.rr-th-num { width: 100px; text-align: right; }
.rr-num { text-align: right; font-variant-numeric: tabular-nums; }
.rr-sku { font-size: 10px; color: #8b7355; margin-right: 3px; }
.rr-cat-badge { font-size: 10px; padding: 2px 6px; border-radius: 4px; font-weight: 600; }
.cat-Сухой { background: #fef3c7; color: #92400e; }
.cat-Холод { background: #eff6ff; color: #2563eb; }
.cat-Мороз { background: #ede9fe; color: #7c3aed; }
.rr-city { font-size: 12px; color: #8b7355; }
.rr-comment { font-size: 11px; cursor: help; margin-left: 4px; }

/* Clickable rows */
.rr-clickable { cursor: pointer; }
.rr-clickable:hover { background: #f0ede8 !important; }

/* Item actions */
.rr-item-actions { display: flex; gap: 4px; justify-content: flex-end; }
.rr-action-btn { width: 28px; height: 28px; border-radius: 6px; border: 1.5px solid #e0dbd5; background: white; cursor: pointer; font-size: 14px; display: flex; align-items: center; justify-content: center; transition: all 0.15s; }
.rr-action-goto { color: #2563eb; }
.rr-action-goto:hover { background: #eff6ff; border-color: #2563eb; }
.rr-action-del { color: #dc2626; }
.rr-action-del:hover { background: #fef2f2; border-color: #dc2626; }

/* Confirm dialog */
.rr-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 1000; display: flex; align-items: center; justify-content: center; }
.rr-confirm { background: white; border-radius: 12px; padding: 24px; max-width: 420px; width: 90%; box-shadow: 0 8px 32px rgba(0,0,0,0.2); }
.rr-confirm p { margin: 0 0 16px; font-size: 14px; color: #502314; line-height: 1.5; }
.rr-confirm-btns { display: flex; gap: 8px; justify-content: flex-end; }

/* Cross table */
.rr-cross-wrap { overflow-x: auto; }
.rr-cross th, .rr-cross td { text-align: center; padding: 6px 8px; font-size: 12px; min-width: 50px; }
.rr-cross-corner { text-align: left !important; min-width: 200px !important; position: sticky; left: 0; background: #faf8f5; z-index: 1; }
.rr-cross-name { text-align: left !important; min-width: 200px; position: sticky; left: 0; background: white; z-index: 1; font-size: 12px; }
.rr-cross-head { font-size: 11px; writing-mode: horizontal-tb; }
.rr-cross-cell { font-variant-numeric: tabular-nums; }
.rr-cross-has { background: #f0fdf4; font-weight: 600; color: #16a34a; }
.rr-cross-total { font-weight: 700; background: #faf8f5 !important; }

@media (max-width: 640px) {
  .rr-header { flex-direction: column; align-items: flex-start; }
  .rr-header h1 { font-size: 17px; }
  .rr-filter-row { flex-direction: column; }
  .rr-field { width: 100%; }
  .rr-input { width: 100%; box-sizing: border-box; }
  .rr-rest-select { width: 100%; }
  .rr-group-bar { overflow-x: auto; flex-wrap: nowrap; }
  .rr-table { font-size: 12px; }
  .rr-search-row { flex-direction: column; align-items: stretch; }
}
</style>
