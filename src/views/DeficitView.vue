<template>
  <div class="dfc">
    <!-- ══════ SETUP MODE (data + params side by side) ══════ -->
    <template v-if="!showResults">
      <div class="dfc-grid">
        <!-- LEFT: Data -->
        <div class="dfc-col">
          <div class="dfc-card">
            <div class="dfc-card-title">
              Остатки ресторанов
              <span v-if="stockData.length" class="dfc-tag green">{{ stockData.length }}</span>
            </div>

            <!-- Source: collection or file -->
            <div class="dfc-stock-source">
              <div class="dfc-switcher">
                <button :class="{ on: stockSource === 'collection' }" @click="stockSource = 'collection'">Из сбора</button>
                <button :class="{ on: stockSource === 'file' }" @click="stockSource = 'file'">Из файла</button>
              </div>
            </div>

            <!-- Collection picker -->
            <template v-if="stockSource === 'collection'">
              <div v-if="loadingCollections" class="dfc-note">Загрузка сборов...</div>
              <div v-else-if="!availableCollections.length" class="dfc-note">
                Нет сборов. <router-link :to="{ name: 'stock-collection' }" class="dfc-link">Создать сбор</router-link>
              </div>
              <template v-else>
                <select v-model="selectedCollectionId" class="dfc-input mb-8" @change="onCollectionSelect">
                  <option :value="null">Выберите сбор...</option>
                  <option v-for="c in availableCollections" :key="c.id" :value="c.id">
                    {{ c.name }} ({{ fmtDateShort(c.created_at) }}) {{ c.status === 'closed' ? '· закрыт' : '' }}
                  </option>
                </select>
                <!-- Product in collection -->
                <div v-if="selectedCollectionId && collectionProducts.length > 1" class="dfc-field mb-8">
                  <label>Товар из сбора</label>
                  <select v-model="selectedCollectionProductId" class="dfc-input" @change="onCollectionProductSelect">
                    <option :value="null">Выберите товар...</option>
                    <option v-for="p in collectionProducts" :key="p.id" :value="p.id">
                      {{ p.product_name }} ({{ p.unit === 'boxes' ? 'кор.' : 'шт.' }})
                    </option>
                  </select>
                </div>
              </template>
            </template>

            <!-- File upload -->
            <template v-if="stockSource === 'file'">
              <div class="dfc-row gap-8 mb-12">
                <button class="dfc-btn fill" @click="uploadStock"><BkIcon name="send" size="sm"/> Загрузить файл</button>
              </div>
            </template>

            <div v-if="stockData.length" class="dfc-table-box">
              <table class="dfc-tbl">
                <thead><tr><th>Рест.</th><th>Остаток</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="(d, i) in stockData" :key="d.restaurantNumber">
                    <td class="fw">{{ d.restaurantNumber }}</td>
                    <td><input v-model.number="stockData[i].value" type="number" min="0" class="dfc-cell-input"/></td>
                    <td><button class="dfc-x" @click="stockData.splice(i, 1)">✕</button></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div class="dfc-card">
            <div class="dfc-card-title">
              Расход ресторанов
              <span v-if="consumptionData.length" class="dfc-tag green">{{ consumptionData.length }}</span>
            </div>
            <div class="dfc-row gap-8 mb-12">
              <button class="dfc-btn fill" @click="uploadConsumption"><BkIcon name="send" size="sm"/> Загрузить</button>
              <div class="dfc-row gap-6 align-center">
                <span class="dfc-label-sm">Период</span>
                <input v-model.number="consumptionDays" type="number" min="1" max="365" class="dfc-num-input w60"/>
                <span class="dfc-label-sm">дн.</span>
              </div>
            </div>
            <div v-if="detectedPeriod" class="dfc-note">{{ detectedPeriod }}</div>

            <div v-if="consumptionData.length" class="dfc-table-box">
              <table class="dfc-tbl">
                <thead><tr><th>Рест.</th><th>Расход</th><th>Сут.</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="(d, i) in consumptionData" :key="d.restaurantNumber">
                    <td class="fw">{{ d.restaurantNumber }}</td>
                    <td><input v-model.number="consumptionData[i].value" type="number" min="0" class="dfc-cell-input"/></td>
                    <td class="muted">{{ (d.value / (consumptionDays || 1)).toFixed(1) }}</td>
                    <td><button class="dfc-x" @click="consumptionData.splice(i, 1)">✕</button></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- RIGHT: Parameters -->
        <div class="dfc-col">
          <div class="dfc-card">
            <div class="dfc-card-title">Параметры</div>

            <!-- Product search -->
            <div class="dfc-field">
              <label>Товар</label>
              <div class="dfc-search-box">
                <input
                  v-model="productSearch"
                  type="text"
                  :placeholder="selectedProduct ? selectedProduct.name : 'Название или SKU'"
                  class="dfc-input"
                  :class="{ selected: selectedProduct }"
                  @input="onProductSearch"
                  @focus="showProductDropdown = true"
                />
                <button v-if="selectedProduct" class="dfc-search-x" @click="clearProduct">✕</button>
              </div>
              <div v-if="showProductDropdown && productResults.length" class="dfc-drop">
                <div v-for="p in productResults" :key="p.id" class="dfc-drop-item" @click="selectProduct(p)">
                  <span class="dfc-drop-name">{{ p.name }}</span>
                  <span class="dfc-drop-meta">{{ p.sku }}<template v-if="p.supplier"> · {{ p.supplier }}</template><template v-if="p.qty_per_box"> · {{ p.qty_per_box }} шт/кор</template></span>
                </div>
              </div>
              <div v-if="showProductDropdown && productSearch.length >= 2 && !productResults.length && !searchingProduct" class="dfc-drop dfc-drop-empty">Не найдено</div>
              <div v-if="selectedProduct" class="dfc-selected-badge">
                {{ selectedProduct.name }}
                <span v-if="selectedProduct.sku"> · {{ selectedProduct.sku }}</span>
                <span v-if="selectedProduct.qty_per_box"> · {{ selectedProduct.qty_per_box }} шт/кор</span>
                <span v-if="selectedProduct.multiplicity"> · кратн. {{ selectedProduct.multiplicity }}</span>
              </div>
            </div>

            <div class="dfc-divider"></div>

            <!-- Unit -->
            <div class="dfc-field">
              <label>Распределять в</label>
              <div class="dfc-switcher">
                <button :class="{ on: unit === 'boxes' }" @click="unit = 'boxes'">Коробках</button>
                <button :class="{ on: unit === 'pieces' }" @click="unit = 'pieces'">Штуках</button>
              </div>
            </div>

            <div class="dfc-field">
              <label>Штук в коробке</label>
              <input v-model.number="qtyPerBox" type="number" min="1" class="dfc-num-input w80"/>
            </div>

            <div class="dfc-pair">
              <div class="dfc-field">
                <label>На складе</label>
                <div class="dfc-row gap-6 align-center">
                  <input v-model.number="warehouseStock" type="number" min="0" class="dfc-num-input w80"/>
                  <span class="dfc-unit">{{ unitShort }}</span>
                </div>
              </div>
              <div class="dfc-field">
                <label>Кратность</label>
                <div class="dfc-row gap-6 align-center">
                  <input v-model.number="multiplicity" type="number" min="1" class="dfc-num-input w80" placeholder="1"/>
                  <span class="dfc-unit">{{ unitShort }}</span>
                </div>
              </div>
            </div>

            <div class="dfc-pair">
              <div class="dfc-field">
                <label>Поставка на склад</label>
                <input v-model="nextDeliveryDate" type="date" class="dfc-input"/>
              </div>
              <div class="dfc-field">
                <label>Рост расхода</label>
                <input v-model.number="growthFactor" type="number" min="0.1" max="5" step="0.05" class="dfc-num-input w80"/>
              </div>
            </div>

            <div class="dfc-divider"></div>

            <div class="dfc-pair">
              <div class="dfc-field">
                <label>Остатки в файле</label>
                <div class="dfc-switcher sm">
                  <button :class="{ on: stockUnit === 'boxes' }" @click="stockUnit = 'boxes'">Кор.</button>
                  <button :class="{ on: stockUnit === 'pieces' }" @click="stockUnit = 'pieces'">Шт.</button>
                </div>
              </div>
              <div class="dfc-field">
                <label>Расход в файле</label>
                <div class="dfc-switcher sm">
                  <button :class="{ on: consumptionUnit === 'boxes' }" @click="consumptionUnit = 'boxes'">Кор.</button>
                  <button :class="{ on: consumptionUnit === 'pieces' }" @click="consumptionUnit = 'pieces'">Шт.</button>
                </div>
              </div>
            </div>

            <button class="dfc-go" :disabled="!canCalculate" @click="calculate">
              Рассчитать
            </button>
          </div>

          <!-- History -->
          <button v-if="sessions.length" class="dfc-btn outline full" @click="showHistory = !showHistory">
            <BkIcon name="history" size="sm"/> {{ showHistory ? 'Скрыть историю' : 'История (' + sessions.length + ')' }}
          </button>
          <div v-if="showHistory && sessions.length" class="dfc-card dfc-history">
            <div v-for="s in sessions" :key="s.id" class="dfc-hist-row">
              <div class="dfc-hist-name">{{ s.product_name }}</div>
              <div class="dfc-hist-info">{{ s.warehouse_stock }} склад · {{ s.total_allocated }}/{{ s.total_need }} · {{ s.restaurant_count }} рест. · {{ formatDate(s.created_at) }}</div>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- ══════ RESULTS MODE ══════ -->
    <template v-if="showResults">
      <!-- Top bar -->
      <div class="dfc-res-bar">
        <div class="dfc-res-bar-left">
          <button class="dfc-btn outline" @click="showResults = false">← Назад</button>
          <div class="dfc-res-title">{{ productName }}</div>
        </div>
        <div class="dfc-res-bar-right">
          <div class="dfc-switcher sm">
            <button :class="{ on: displayUnit === 'boxes' }" @click="displayUnit = 'boxes'">Кор.</button>
            <button :class="{ on: displayUnit === 'pieces' }" @click="displayUnit = 'pieces'">Шт.</button>
          </div>
          <button class="dfc-btn fill" @click="exportResults"><BkIcon name="import" size="sm"/> Excel</button>
          <button class="dfc-btn outline" @click="saveSession" :disabled="saving">{{ saving ? '...' : 'Сохранить' }}</button>
        </div>
      </div>

      <!-- KPIs -->
      <div class="dfc-kpis">
        <div class="dfc-kpi">
          <div class="dfc-kpi-num">{{ warehouseStock }}</div>
          <div class="dfc-kpi-lbl">На складе ({{ unitShort }})</div>
        </div>
        <div class="dfc-kpi">
          <div class="dfc-kpi-num" :class="{ red: !allocationResult.sufficient }">{{ fmt(allocationResult.totalNeed) }}</div>
          <div class="dfc-kpi-lbl">Потребность ({{ unitShort }})</div>
        </div>
        <div class="dfc-kpi">
          <div class="dfc-kpi-num orange">{{ dspTotal('totalAllocated') }}</div>
          <div class="dfc-kpi-lbl">Отгрузить ({{ dspUnitShort }})</div>
        </div>
        <div class="dfc-kpi">
          <div class="dfc-kpi-num">{{ allocationResult.results.length }}</div>
          <div class="dfc-kpi-lbl">Ресторанов</div>
        </div>
        <div class="dfc-kpi" :class="allocationResult.sufficient ? 'kpi-ok' : 'kpi-bad'">
          <div class="dfc-kpi-num">{{ allocationResult.sufficient ? '✓' : '!' }}</div>
          <div class="dfc-kpi-lbl">{{ allocationResult.sufficient ? 'Хватает' : 'Дефицит' }}</div>
        </div>
      </div>

      <!-- Table -->
      <div class="dfc-res-table-wrap">
        <table class="dfc-res-table">
          <thead>
            <tr>
              <th class="left sort" @click="toggleSort('rest')">Ресторан <span class="sort-ico">{{ sortIcon('rest') }}</span></th>
              <th class="sort" @click="toggleSort('stock')">Остаток <span class="sort-ico">{{ sortIcon('stock') }}</span></th>
              <th class="sort" @click="toggleSort('daily')">Сут. расход <span class="sort-ico">{{ sortIcon('daily') }}</span></th>
              <th class="sort" @click="toggleSort('days')">Дней <span class="sort-ico">{{ sortIcon('days') }}</span></th>
              <th class="sort" @click="toggleSort('delivery')">Доставка <span class="sort-ico">{{ sortIcon('delivery') }}</span></th>
              <th class="sort" @click="toggleSort('need')">Потребность <span class="sort-ico">{{ sortIcon('need') }}</span></th>
              <th class="col-ship sort" @click="toggleSort('alloc')">Отгрузить <span class="sort-ico">{{ sortIcon('alloc') }}</span></th>
            </tr>
            <tr class="filter-row">
              <td><input v-model="filters.rest" placeholder="№" class="flt-input"/></td>
              <td><input v-model="filters.stock" placeholder="от" type="number" class="flt-input"/></td>
              <td><input v-model="filters.daily" placeholder="от" type="number" class="flt-input"/></td>
              <td><input v-model="filters.days" placeholder="от" type="number" class="flt-input"/></td>
              <td><input v-model="filters.delivery" placeholder="дд.мм" class="flt-input"/></td>
              <td><input v-model="filters.need" placeholder="от" type="number" class="flt-input"/></td>
              <td><input v-model="filters.alloc" placeholder="от" type="number" class="flt-input"/></td>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in filteredResults" :key="r.restaurantNumber" :class="{ dim: r.allocated === 0 }">
              <td class="left fw">{{ r.restaurantNumber }}</td>
              <td>{{ dsp(r.currentStock) }}</td>
              <td>{{ dsp(r.dailyConsumption, 2) }}</td>
              <td>{{ r.daysToCover }}</td>
              <td class="muted">{{ r.deliveryDay }}</td>
              <td>{{ fmt(r.need) }}</td>
              <td class="col-ship-val">{{ dsp(r.allocated) }}</td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="5" class="foot-label">Итого ({{ filteredResults.length }} рест.)</td>
              <td class="foot-val">{{ fmt(filteredTotalNeed) }}</td>
              <td class="foot-val col-ship-val">{{ dsp(filteredTotalAlloc) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </template>

    <!-- Sheet picker -->
    <Teleport to="body">
      <div v-if="showSheetPicker" class="modal" @click.self="showSheetPicker = false">
        <div class="modal-box" style="max-width: 480px;">
          <h3 style="margin-bottom:12px;">Выберите лист</h3>
          <div class="dfc-pick-list">
            <button v-for="name in sheetNames" :key="name" class="dfc-pick-btn" @click="pickSheet(name)">{{ name }}</button>
          </div>
          <div style="text-align:right;margin-top:12px;"><button class="dfc-btn outline" @click="showSheetPicker = false">Отмена</button></div>
        </div>
      </div>
    </Teleport>

    <!-- Column picker -->
    <Teleport to="body">
      <div v-if="showColumnPicker" class="modal" @click.self="showColumnPicker = false">
        <div class="modal-box" style="max-width: 520px;">
          <h3 style="margin-bottom:12px;">Выберите товар</h3>
          <div class="dfc-pick-list">
            <button v-for="col in productColumns" :key="col.index" class="dfc-pick-btn" @click="pickColumn(col.index)">{{ col.name }}</button>
          </div>
          <div style="text-align:right;margin-top:12px;"><button class="dfc-btn outline" @click="showColumnPicker = false">Отмена</button></div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { db } from '@/lib/apiClient.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useRestaurantStore } from '@/stores/restaurantStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { parseRestaurantFile, getSheetNames, getProductColumns } from '@/lib/deficitImport.js';
import { allocateDeficit } from '@/lib/deficitAllocator.js';
import BkIcon from '@/components/ui/BkIcon.vue';

const orderStore = useOrderStore();
const restaurantStore = useRestaurantStore();
const userStore = useUserStore();
const toastStore = useToastStore();

// --- State ---
const showResults = ref(false);
const stockData = ref([]);
const consumptionData = ref([]);
const consumptionDays = ref(30);
const detectedPeriod = ref('');
const warehouseStock = ref(0);
const nextDeliveryDate = ref('');
const growthFactor = ref(1.0);
const multiplicity = ref(1);
const allocationResult = ref({ results: [], totalNeed: 0, totalAllocated: 0, sufficient: true });

// Product
const productSearch = ref('');
const productResults = ref([]);
const selectedProduct = ref(null);
const showProductDropdown = ref(false);
const searchingProduct = ref(false);
let searchTimeout = null;
const productName = computed(() => selectedProduct.value?.name || productSearch.value);

// Units
const unit = ref('boxes');
const qtyPerBox = ref(1);
const stockUnit = ref('boxes');
const consumptionUnit = ref('pieces');
const displayUnit = ref('boxes');
const unitShort = computed(() => unit.value === 'boxes' ? 'кор.' : 'шт.');
const dspUnitShort = computed(() => displayUnit.value === 'boxes' ? 'кор.' : 'шт.');

// Stock source
const stockSource = ref('collection'); // 'collection' | 'file'
const loadingCollections = ref(false);
const availableCollections = ref([]);
const selectedCollectionId = ref(null);
const collectionProducts = ref([]);
const selectedCollectionProductId = ref(null);

// History
const showHistory = ref(false);
const sessions = ref([]);
const saving = ref(false);

const canCalculate = computed(() =>
  (selectedProduct.value || productSearch.value) &&
  warehouseStock.value >= 0 && nextDeliveryDate.value &&
  stockData.value.length > 0 && consumptionData.value.length > 0
);

onMounted(async () => {
  await restaurantStore.load(orderStore.settings.legalEntity);
  loadHistory();
  loadCollections();
  document.addEventListener('click', closeDrop);
});
onUnmounted(() => { document.removeEventListener('click', closeDrop); clearTimeout(searchTimeout); });
watch(() => orderStore.settings.legalEntity, () => { sessions.value = []; loadHistory(); loadCollections(); });

function closeDrop(e) {
  if (!e.target.closest('.dfc-search-box') && !e.target.closest('.dfc-drop')) showProductDropdown.value = false;
}

// --- Format helpers ---
function fmt(v) { return +(Math.round(v * 100) / 100).toFixed(2); }

function convertForDisplay(value) {
  if (displayUnit.value === unit.value) return value;
  const q = qtyPerBox.value || 1;
  return displayUnit.value === 'pieces' ? value * q : value / q;
}
function dsp(value, dec) {
  const v = convertForDisplay(value);
  if (dec != null) return +(v.toFixed(dec));
  return +(Math.round(v * 100) / 100).toFixed(2);
}
function dspTotal(key) { return dsp(allocationResult.value[key]); }

// --- Filters & sorting ---
const sortCol = ref('');
const sortAsc = ref(true);
const filters = ref({ rest: '', stock: '', daily: '', days: '', delivery: '', need: '', alloc: '' });

function toggleSort(col) {
  if (sortCol.value === col) { sortAsc.value = !sortAsc.value; }
  else { sortCol.value = col; sortAsc.value = true; }
}

const filteredResults = computed(() => {
  let rows = allocationResult.value.results;

  // Text/number filters
  if (filters.value.rest) rows = rows.filter(r => String(r.restaurantNumber).includes(filters.value.rest));
  if (filters.value.stock) rows = rows.filter(r => dsp(r.currentStock) >= +filters.value.stock);
  if (filters.value.daily) rows = rows.filter(r => dsp(r.dailyConsumption, 2) >= +filters.value.daily);
  if (filters.value.days) rows = rows.filter(r => r.daysToCover >= +filters.value.days);
  if (filters.value.delivery) rows = rows.filter(r => r.deliveryDay.includes(filters.value.delivery));
  if (filters.value.need) rows = rows.filter(r => fmt(r.need) >= +filters.value.need);
  if (filters.value.alloc) rows = rows.filter(r => dsp(r.allocated) >= +filters.value.alloc);

  // Sort
  if (sortCol.value) {
    const dir = sortAsc.value ? 1 : -1;
    rows = [...rows].sort((a, b) => {
      let va, vb;
      switch (sortCol.value) {
        case 'rest': va = a.restaurantNumber; vb = b.restaurantNumber; return va < vb ? -dir : va > vb ? dir : 0;
        case 'stock': va = a.currentStock; vb = b.currentStock; break;
        case 'daily': va = a.dailyConsumption; vb = b.dailyConsumption; break;
        case 'days': va = a.daysToCover; vb = b.daysToCover; break;
        case 'delivery': va = a.deliveryDay; vb = b.deliveryDay; return va < vb ? -dir : va > vb ? dir : 0;
        case 'need': va = a.need; vb = b.need; break;
        case 'alloc': va = a.allocated; vb = b.allocated; break;
        default: return 0;
      }
      return (va - vb) * dir;
    });
  }
  return rows;
});

const filteredTotalNeed = computed(() => filteredResults.value.reduce((s, r) => s + r.need, 0));
const filteredTotalAlloc = computed(() => filteredResults.value.reduce((s, r) => s + r.allocated, 0));

function sortIcon(col) {
  if (sortCol.value !== col) return '↕';
  return sortAsc.value ? '↑' : '↓';
}

// --- Product search ---
function onProductSearch() {
  showProductDropdown.value = true;
  selectedProduct.value = null;
  clearTimeout(searchTimeout);
  if (productSearch.value.length < 2) { productResults.value = []; return; }
  searchTimeout = setTimeout(searchProducts, 250);
}
async function searchProducts() {
  searchingProduct.value = true;
  try {
    const le = orderStore.settings.legalEntity;
    const params = new URLSearchParams({ q: productSearch.value, legal_entity: le, limit: '15' });
    const r = await fetch(`/api/search_products?${params}`, {
      headers: { 'X-Session-Token': localStorage.getItem('bk_session_token') || '' },
    });
    if (r.ok) productResults.value = await r.json();
  } catch {} finally { searchingProduct.value = false; }
}
function selectProduct(p) {
  selectedProduct.value = p;
  productSearch.value = p.name;
  showProductDropdown.value = false;
  productResults.value = [];
  if (p.multiplicity) multiplicity.value = p.multiplicity;
  if (p.qty_per_box) qtyPerBox.value = p.qty_per_box;
}
function clearProduct() { selectedProduct.value = null; productSearch.value = ''; productResults.value = []; }

// --- Collections ---
async function loadCollections() {
  loadingCollections.value = true;
  try {
    const { data } = await db.from('stock_collections')
      .select('*')
      .eq('legal_entity', orderStore.settings.legalEntity)
      .order('created_at', { ascending: false })
      .limit(30);
    availableCollections.value = data || [];
  } catch {} finally { loadingCollections.value = false; }
}

async function onCollectionSelect() {
  collectionProducts.value = [];
  selectedCollectionProductId.value = null;
  stockData.value = [];
  if (!selectedCollectionId.value) return;
  try {
    const { data } = await db.rpc('sc_get_collection_data', { collection_id: selectedCollectionId.value });
    if (!data) return;
    collectionProducts.value = data.products || [];
    // If only one product, auto-select
    if (collectionProducts.value.length === 1) {
      selectedCollectionProductId.value = collectionProducts.value[0].id;
      onCollectionProductSelect();
    }
  } catch { toastStore.error('Ошибка', 'Не удалось загрузить сбор'); }
}

function onCollectionProductSelect() {
  if (!selectedCollectionProductId.value) { stockData.value = []; return; }
  // Load data for this product
  const prodId = selectedCollectionProductId.value;
  const prod = collectionProducts.value.find(p => p.id === prodId);
  // Get data from the RPC result (stored in closure from onCollectionSelect)
  loadCollectionProductData(prodId, prod);
}

async function loadCollectionProductData(prodId, prod) {
  try {
    const { data } = await db.rpc('sc_get_collection_data', { collection_id: selectedCollectionId.value });
    if (!data?.data) return;
    const rows = data.data.filter(d => d.product_id === prodId);
    stockData.value = rows.map(d => ({ restaurantNumber: String(d.restaurant_number), value: d.stock }));
    // Auto-set stock unit from product
    if (prod) stockUnit.value = prod.unit;
    // Try to fill product name
    if (prod && !selectedProduct.value && !productSearch.value) {
      productSearch.value = prod.product_name;
    }
    toastStore.success('Загружено', `${stockData.value.length} ресторанов из сбора`);
  } catch {}
}

function fmtDateShort(s) {
  if (!s) return '';
  return new Date(s).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
}

// --- File upload ---
function openFilePicker() {
  return new Promise((resolve) => {
    const input = document.createElement('input');
    input.type = 'file'; input.accept = '.xlsx,.xls,.csv,.tsv';
    input.addEventListener('change', (e) => resolve(e.target.files[0] || null));
    const onFocus = () => { setTimeout(() => resolve(null), 300); window.removeEventListener('focus', onFocus); };
    window.addEventListener('focus', onFocus);
    input.click();
  });
}

const showSheetPicker = ref(false);
const sheetNames = ref([]);
const pendingFile = ref(null);
const pendingTarget = ref('');
const showColumnPicker = ref(false);
const productColumns = ref([]);
const pendingSheetName = ref(null);

async function uploadStock() { const f = await openFilePicker(); if (f) await loadFileWithSheetPicker(f, 'stock'); }
async function uploadConsumption() { const f = await openFilePicker(); if (!f) return; await detectPeriodFromFile(f); await loadFileWithSheetPicker(f, 'consumption'); }

async function detectPeriodFromFile(file) {
  try {
    const ext = file.name.split('.').pop().toLowerCase();
    if (ext === 'csv' || ext === 'tsv') return;
    const XLSX = await import('xlsx-js-style');
    const buffer = await file.arrayBuffer();
    const wb = XLSX.read(buffer, { type: 'array' });
    const ws = wb.Sheets[wb.SheetNames[0]];
    if (!ws) return;
    const rows = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });
    for (let i = 0; i < Math.min(10, rows.length); i++) {
      for (const cell of rows[i]) {
        const m = String(cell).match(/Период:\s*(\d{2})\.(\d{2})\.(\d{4})\s*-\s*(\d{2})\.(\d{2})\.(\d{4})/);
        if (m) {
          const from = new Date(+m[3], +m[2]-1, +m[1]), to = new Date(+m[6], +m[5]-1, +m[4]);
          const days = Math.round((to - from) / 86400000);
          if (days > 0 && days < 366) { consumptionDays.value = days; detectedPeriod.value = `${m[1]}.${m[2]}.${m[3]} — ${m[4]}.${m[5]}.${m[6]}, ${days} дн.`; }
          return;
        }
      }
    }
  } catch {}
}

async function loadFileWithSheetPicker(file, target) {
  try {
    const sheets = await getSheetNames(file);
    if (sheets.length > 1) { sheetNames.value = sheets; pendingFile.value = file; pendingTarget.value = target; showSheetPicker.value = true; return; }
    await tryLoadWithColumnPicker(file, null, target);
  } catch { toastStore.error('Ошибка', 'Не удалось прочитать файл'); }
}
async function pickSheet(name) { showSheetPicker.value = false; if (pendingFile.value) await tryLoadWithColumnPicker(pendingFile.value, name, pendingTarget.value); }
async function tryLoadWithColumnPicker(file, sheet, target) {
  try {
    const cols = await getProductColumns(file, sheet);
    if (cols.length > 1) { productColumns.value = cols; pendingFile.value = file; pendingSheetName.value = sheet; pendingTarget.value = target; showColumnPicker.value = true; return; }
    await processFile(file, sheet, null, target);
  } catch { toastStore.error('Ошибка', 'Не удалось прочитать файл'); }
}
async function pickColumn(idx) {
  showColumnPicker.value = false;
  if (!pendingFile.value) return;
  const col = productColumns.value.find(c => c.index === idx);
  if (col?.name && !selectedProduct.value && !productSearch.value) productSearch.value = col.name;
  await processFile(pendingFile.value, pendingSheetName.value, idx, pendingTarget.value);
  pendingFile.value = null; pendingSheetName.value = null;
}
async function processFile(file, sheet, colIdx, target) {
  try {
    const data = await parseRestaurantFile(file, sheet, colIdx);
    if (!data.length) { toastStore.error('Ошибка', 'Не удалось распознать данные'); return; }
    if (target === 'stock') {
      const merged = mergeStockSources(data, formResponses.value);
      stockData.value = merged.length ? merged : data;
      toastStore.success('Загружено', `${stockData.value.length} ресторанов`);
    } else {
      consumptionData.value = data;
      toastStore.success('Загружено', `${data.length} ресторанов`);
    }
  } catch { toastStore.error('Ошибка', 'Не удалось прочитать файл'); }
}

// --- Calc ---
function convertToUnit(val, from, to) {
  if (from === to) return val;
  const q = qtyPerBox.value || 1;
  return from === 'pieces' ? val / q : val * q;
}

function calculate() {
  const schedMap = restaurantStore.scheduleByRestaurant;
  const rests = restaurantStore.restaurants;
  const sMap = new Map(); for (const d of stockData.value) sMap.set(d.restaurantNumber, d.value);
  const cMap = new Map(); for (const d of consumptionData.value) cMap.set(d.restaurantNumber, d.value);

  const rd = rests.map(r => {
    const num = String(r.number);
    const cs = convertToUnit(sMap.get(num) || 0, stockUnit.value, unit.value);
    const tc = convertToUnit(cMap.get(num) || 0, consumptionUnit.value, unit.value);
    return { id: r.id, number: num, dailyConsumption: consumptionDays.value > 0 ? tc / consumptionDays.value : 0, currentStock: cs, schedule: schedMap.get(String(r.id)) || new Map() };
  });

  const result = allocateDeficit({ warehouseStock: warehouseStock.value, nextDeliveryDate: new Date(nextDeliveryDate.value), growthFactor: growthFactor.value, multiplicity: multiplicity.value || 1, restaurants: rd });
  result.results = result.results.filter(r => r.need > 0 || r.dailyConsumption > 0 || r.currentStock > 0);
  result.results.sort((a, b) => b.allocated - a.allocated);
  allocationResult.value = result;
  displayUnit.value = unit.value;
  showResults.value = true;
}

// --- Export ---
async function exportResults() {
  const XLSX = await import('xlsx-js-style');
  const brown = '502314';
  const bdr = { style: 'thin', color: { rgb: 'E0D6CC' } };
  const borders = { top: bdr, bottom: bdr, left: bdr, right: bdr };
  const sH = { font: { bold: true, sz: 11, color: { rgb: 'FFFFFF' }, name: 'Calibri' }, fill: { fgColor: { rgb: brown } }, alignment: { horizontal: 'center', vertical: 'center' }, border: borders };
  const sC = (s) => ({ font: { sz: 11, name: 'Calibri' }, fill: s ? { fgColor: { rgb: 'FFF8F0' } } : undefined, alignment: { vertical: 'center' }, border: borders });
  const sB = (s) => ({ font: { bold: true, sz: 11, color: { rgb: brown }, name: 'Calibri' }, fill: s ? { fgColor: { rgb: 'FFF8F0' } } : undefined, alignment: { horizontal: 'center', vertical: 'center' }, border: borders });

  function sc(ws, r, c, v, s) { ws[XLSX.utils.encode_cell({ r, c })] = { v, t: typeof v === 'number' ? 'n' : 's', s }; }

  const dl = dspUnitShort.value;
  const ws = {}; let r = 0;
  sc(ws, r, 0, `Распределение — ${productName.value}`, { font: { bold: true, sz: 14, color: { rgb: brown }, name: 'Calibri' } }); r++;
  sc(ws, r, 0, `Склад: ${warehouseStock.value} ${unitShort.value} | Кратн: ${multiplicity.value} | ${nextDeliveryDate.value} | Вывод: ${dl}`, { font: { sz: 11, color: { rgb: '666666' }, name: 'Calibri' } }); r += 2;

  ['Ресторан', `Остаток (${dl})`, 'Сут. расход', 'Дней', 'Доставка', 'Потребность', `Отгрузить (${dl})`].forEach((h, c) => sc(ws, r, c, h, sH)); r++;
  const res = allocationResult.value.results;
  res.forEach((row, i) => {
    const s = i % 2 === 1;
    sc(ws, r, 0, row.restaurantNumber, sC(s)); sc(ws, r, 1, dsp(row.currentStock), sC(s));
    sc(ws, r, 2, dsp(row.dailyConsumption, 1), sC(s)); sc(ws, r, 3, row.daysToCover, sC(s));
    sc(ws, r, 4, row.deliveryDay, sC(s)); sc(ws, r, 5, fmt(row.need), sC(s)); sc(ws, r, 6, dsp(row.allocated), sB(s)); r++;
  });
  sc(ws, r, 4, 'Итого:', { font: { bold: true, sz: 11, name: 'Calibri' }, alignment: { horizontal: 'right' }, border: borders });
  sc(ws, r, 5, fmt(allocationResult.value.totalNeed), { font: { bold: true, sz: 11, name: 'Calibri' }, border: borders });
  sc(ws, r, 6, dspTotal('totalAllocated'), { font: { bold: true, sz: 11, color: { rgb: brown }, name: 'Calibri' }, border: borders });
  ws['!ref'] = XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r, c: 6 } });
  ws['!cols'] = [{ wch: 12 }, { wch: 12 }, { wch: 12 }, { wch: 8 }, { wch: 12 }, { wch: 14 }, { wch: 14 }];
  const wb = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb, ws, 'Распределение');
  XLSX.writeFile(wb, `Дефицит_${productName.value.replace(/[^а-яА-ЯёЁa-zA-Z0-9\s]/g, '').trim()}_${new Date().toLocaleDateString('ru-RU')}.xlsx`);
}

// --- Save ---
async function saveSession() {
  saving.value = true;
  try {
    const { data: session, error } = await db.from('deficit_sessions').insert({
      legal_entity: orderStore.settings.legalEntity, product_name: productName.value, warehouse_stock: warehouseStock.value,
      next_delivery_date: nextDeliveryDate.value, growth_factor: growthFactor.value, total_need: allocationResult.value.totalNeed,
      total_allocated: allocationResult.value.totalAllocated, restaurant_count: allocationResult.value.results.length, created_by: userStore.currentUser?.name || '',
    });
    if (error) throw new Error(error);
    const sid = Array.isArray(session) ? session[0]?.id : session?.id;
    if (sid) {
      for (const r of allocationResult.value.results) {
        await db.from('deficit_results').insert({ session_id: sid, restaurant_number: r.restaurantNumber, current_stock: r.currentStock, daily_consumption: r.dailyConsumption, days_to_cover: r.daysToCover, need: r.need, allocated: r.allocated, delivery_day: r.deliveryDay });
      }
    }
    toastStore.success('Сохранено', ''); loadHistory();
  } catch { toastStore.error('Ошибка', 'Не удалось сохранить'); } finally { saving.value = false; }
}

async function loadHistory() {
  try {
    const { data } = await db.from('deficit_sessions').select('*').eq('legal_entity', orderStore.settings.legalEntity).order('created_at', { ascending: false }).limit(20);
    sessions.value = data || [];
  } catch {}
}

function formatDate(s) {
  if (!s) return '';
  const d = new Date(s);
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' }) + ' ' + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}
</script>

<style scoped>
/* ══════ BASE ══════ */
.dfc { --brown: #502314; --orange: #FF8732; --red: #D62700; --green: #2E7D32; --border: #EDE7DF; --muted: #8C7B6E; --bg2: #F9F6F2; }

/* ══════ GRID ══════ */
.dfc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; align-items: start; }
@media (max-width: 920px) { .dfc-grid { grid-template-columns: 1fr; } }

/* ══════ CARD ══════ */
.dfc-card {
  background: #fff; border: 1px solid var(--border); border-radius: 12px;
  padding: 18px; margin-bottom: 12px;
}
.dfc-card-title {
  font-size: 14px; font-weight: 700; color: var(--brown);
  margin-bottom: 14px; display: flex; align-items: center; gap: 8px;
}

/* ══════ TAGS ══════ */
.dfc-tag { font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 8px; }
.dfc-tag.green { background: #E8F5E9; color: var(--green); }

/* ══════ BUTTONS ══════ */
.dfc-btn {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 6px 13px; border-radius: 7px; font-size: 12px; font-weight: 600;
  font-family: inherit; border: 1.5px solid transparent; cursor: pointer;
  transition: all 0.12s; white-space: nowrap;
}
.dfc-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.dfc-btn.fill { background: var(--brown); color: #fff; border-color: var(--brown); }
.dfc-btn.fill:hover:not(:disabled) { background: #3D1A0D; }
.dfc-btn.outline { background: none; color: #6B5344; border-color: var(--border); }
.dfc-btn.outline:hover:not(:disabled) { background: var(--bg2); }
.dfc-btn.sm { padding: 4px 9px; font-size: 11px; }
.dfc-btn.full { width: 100%; justify-content: center; }
.dfc-link { background: none; border: none; color: var(--orange); font-size: 11px; font-weight: 600; cursor: pointer; font-family: inherit; text-decoration: underline; }
.dfc-x {
  width: 24px; height: 24px; border: none; background: none;
  color: #bbb; cursor: pointer; font-size: 13px; border-radius: 4px;
  display: flex; align-items: center; justify-content: center;
}
.dfc-x:hover { background: #FFEBEE; color: var(--red); }

/* ══════ GO BUTTON ══════ */
.dfc-go {
  display: block; width: 100%; padding: 13px; margin-top: 16px;
  border: none; border-radius: 10px; font-size: 14px; font-weight: 700;
  font-family: inherit; cursor: pointer; color: #fff;
  background: linear-gradient(135deg, var(--red), var(--orange));
  transition: filter 0.15s;
}
.dfc-go:hover:not(:disabled) { filter: brightness(1.08); }
.dfc-go:disabled { opacity: 0.4; cursor: not-allowed; }

/* ══════ INPUTS ══════ */
.dfc-input {
  width: 100%; padding: 8px 11px; border: 1.5px solid var(--border);
  border-radius: 7px; font-size: 13px; font-family: inherit; transition: border-color 0.12s;
}
.dfc-input:focus { outline: none; border-color: var(--orange); }
.dfc-input.selected { border-color: #A5D6A7; background: #FCFFF9; padding-right: 28px; }
.dfc-num-input {
  padding: 7px 10px; border: 1.5px solid var(--border); border-radius: 7px;
  font-size: 13px; font-family: inherit; text-align: right;
}
.dfc-num-input:focus { outline: none; border-color: var(--orange); }
.w60 { width: 60px; }
.w80 { width: 80px; }
.dfc-unit { font-size: 12px; color: var(--muted); }
.dfc-label-sm { font-size: 12px; color: var(--muted); font-weight: 500; white-space: nowrap; }
.dfc-note { font-size: 11px; color: var(--muted); margin: -8px 0 10px; }

/* ══════ FIELD ══════ */
.dfc-field { position: relative; margin-bottom: 12px; }
.dfc-field label { display: block; font-size: 11px; font-weight: 600; color: var(--muted); margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.3px; }
.dfc-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.dfc-divider { height: 1px; background: var(--border); margin: 4px 0 12px; }

/* ══════ SWITCHER ══════ */
.dfc-switcher {
  display: inline-flex; border: 1.5px solid var(--border); border-radius: 7px; overflow: hidden;
}
.dfc-switcher button {
  padding: 6px 14px; font-size: 12px; font-weight: 600; font-family: inherit;
  border: none; cursor: pointer; background: none; color: var(--muted); transition: all 0.12s;
}
.dfc-switcher button:not(:last-child) { border-right: 1.5px solid var(--border); }
.dfc-switcher button.on { background: var(--brown); color: #fff; }
.dfc-switcher button:hover:not(.on) { background: var(--bg2); }
.dfc-switcher.sm button { padding: 4px 10px; font-size: 11px; }

/* ══════ SEARCH DROPDOWN ══════ */
.dfc-search-box { position: relative; }
.dfc-search-x {
  position: absolute; right: 7px; top: 50%; transform: translateY(-50%);
  background: none; border: none; cursor: pointer; color: #bbb; font-size: 13px; padding: 2px;
}
.dfc-search-x:hover { color: var(--red); }
.dfc-drop {
  position: absolute; z-index: 50; left: 0; right: 0; margin-top: 3px;
  background: #fff; border: 1px solid var(--border); border-radius: 8px;
  box-shadow: 0 8px 28px rgba(44,24,16,0.12); max-height: 240px; overflow-y: auto;
}
.dfc-drop-empty { padding: 14px; text-align: center; color: var(--muted); font-size: 12px; }
.dfc-drop-item { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid var(--border); transition: background 0.08s; }
.dfc-drop-item:last-child { border-bottom: none; }
.dfc-drop-item:hover { background: #FFF8F0; }
.dfc-drop-name { font-size: 13px; font-weight: 600; }
.dfc-drop-meta { font-size: 10px; color: var(--muted); }
.dfc-selected-badge {
  margin-top: 6px; padding: 6px 10px; border-radius: 6px;
  background: #F0FFF0; border: 1px solid #C8E6C9;
  font-size: 12px; font-weight: 500; color: #2E7D32;
}

/* ══════ TOKEN ══════ */
.dfc-token { background: #FFFBF0; border: 1px solid #FFE082; border-radius: 8px; padding: 10px; margin-bottom: 12px; }
.dfc-token-top { display: flex; gap: 6px; }
.dfc-token-input { flex: 1; padding: 4px 7px; border: 1px solid #FFE082; border-radius: 5px; font-size: 10px; background: #fff; font-family: monospace; }
.dfc-token-status { display: flex; align-items: center; gap: 6px; margin-top: 6px; font-size: 11px; color: #8D6E63; }
.dfc-dot { width: 6px; height: 6px; border-radius: 50%; background: #ccc; }
.dfc-dot.on { background: #4CAF50; animation: dfc-p 2s infinite; }
@keyframes dfc-p { 0%,100% { opacity:1 } 50% { opacity:0.3 } }

/* ══════ DATA TABLES ══════ */
.dfc-table-box { max-height: 300px; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px; }
.dfc-tbl { width: 100%; border-collapse: collapse; font-size: 12px; }
.dfc-tbl th { position: sticky; top: 0; z-index: 1; background: var(--bg2); font-size: 10px; font-weight: 600; color: var(--muted); padding: 5px 8px; text-align: left; border-bottom: 1px solid var(--border); }
.dfc-tbl td { padding: 2px 8px; border-bottom: 1px solid #f0ece6; }
.dfc-tbl tr:last-child td { border-bottom: none; }
.fw { font-weight: 700; color: var(--brown); }
.muted { color: var(--muted); }
.dfc-cell-input {
  width: 70px; padding: 2px 5px; border: 1px solid transparent; border-radius: 4px;
  font-size: 12px; font-family: inherit; background: transparent;
}
.dfc-cell-input:hover { border-color: var(--border); background: #fff; }
.dfc-cell-input:focus { outline: none; border-color: var(--orange); background: #fff; }

/* ══════ HISTORY ══════ */
.dfc-history { padding: 14px; }
.dfc-hist-row { padding: 7px 0; border-bottom: 1px solid var(--border); }
.dfc-hist-row:last-child { border-bottom: none; }
.dfc-hist-name { font-size: 13px; font-weight: 600; color: var(--brown); }
.dfc-hist-info { font-size: 11px; color: var(--muted); margin-top: 2px; }

/* ══════ RESULTS BAR ══════ */
.dfc-res-bar {
  display: flex; justify-content: space-between; align-items: center;
  flex-wrap: wrap; gap: 10px; margin-bottom: 14px;
}
.dfc-res-bar-left { display: flex; align-items: center; gap: 12px; }
.dfc-res-bar-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.dfc-res-title { font-family: 'Flame', sans-serif; font-size: 18px; font-weight: 700; color: var(--brown); }

/* ══════ KPIs ══════ */
.dfc-kpis {
  display: flex; gap: 0; margin-bottom: 14px;
  background: #fff; border: 1px solid var(--border); border-radius: 12px;
  overflow: hidden;
}
.dfc-kpi {
  flex: 1; text-align: center; padding: 14px 8px;
  border-right: 1px solid var(--border);
}
.dfc-kpi:last-child { border-right: none; }
.dfc-kpi-num {
  font-size: 20px; font-weight: 700; color: var(--brown);
  font-family: 'Flame', sans-serif;
}
.dfc-kpi-lbl { font-size: 10px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.3px; margin-top: 2px; }
.dfc-kpi-num.orange { color: var(--orange); }
.dfc-kpi-num.red { color: var(--red); }
.kpi-ok { background: #F1F8E9; }
.kpi-ok .dfc-kpi-num { color: var(--green); }
.kpi-bad { background: #FFF3E0; }
.kpi-bad .dfc-kpi-num { color: var(--red); }

@media (max-width: 640px) {
  .dfc-kpis { flex-wrap: wrap; }
  .dfc-kpi { min-width: 33%; padding: 10px 6px; }
  .dfc-kpi-num { font-size: 16px; }
}

/* ══════ RESULT TABLE ══════ */
.dfc-res-table-wrap {
  background: #fff; border: 1px solid var(--border);
  border-radius: 12px; overflow: hidden;
}
.dfc-res-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.dfc-res-table thead th {
  background: var(--brown); color: #fff;
  padding: 10px 12px; font-size: 11px; font-weight: 600;
  text-align: center; white-space: nowrap;
}
.dfc-res-table thead th.left { text-align: left; }
.dfc-res-table tbody td {
  padding: 8px 12px; text-align: center;
  border-bottom: 1px solid #f0ece6;
}
.dfc-res-table tbody td.left { text-align: left; }
.dfc-res-table tbody tr:nth-child(even) { background: #FEFBF7; }
.dfc-res-table tbody tr:hover { background: #FFF3E0; }
.dfc-res-table tbody tr.dim { opacity: 0.35; }

.col-ship { background: #3D1A0D !important; }
.col-ship-val { font-weight: 700; color: var(--brown); font-size: 14px; }

/* Sort & Filters */
.sort { cursor: pointer; user-select: none; }
.sort:hover { background: #3D1A0D !important; }
.sort-ico { font-size: 9px; opacity: 0.5; margin-left: 2px; }
.filter-row td {
  padding: 4px 4px; background: var(--bg2);
  border-bottom: 2px solid var(--border);
}
.flt-input {
  width: 100%; padding: 4px 6px; border: 1px solid var(--border);
  border-radius: 4px; font-size: 11px; font-family: inherit;
  background: #fff; text-align: center;
}
.flt-input:focus { outline: none; border-color: var(--orange); }
.flt-input::placeholder { color: #c5b8ab; }

.dfc-res-table tfoot td {
  padding: 10px 12px; text-align: center;
  border-top: 2px solid var(--border);
}
.foot-label { text-align: right !important; font-weight: 700; color: var(--muted); }
.foot-val { font-weight: 700; }

/* ══════ HELPER ══════ */
.dfc-row { display: flex; }
.gap-6 { gap: 6px; }
.gap-8 { gap: 8px; }
.mb-8 { margin-bottom: 8px; }
.mb-12 { margin-bottom: 12px; }
.align-center { align-items: center; }
.dfc-stock-source { margin-bottom: 12px; }

/* ══════ PICKERS ══════ */
.dfc-pick-list { display: flex; flex-direction: column; gap: 4px; max-height: 400px; overflow-y: auto; }
.dfc-pick-btn {
  text-align: left; padding: 10px 14px; border: 1px solid var(--border);
  border-radius: 8px; background: none; cursor: pointer; font-size: 13px; font-family: inherit;
}
.dfc-pick-btn:hover { background: var(--bg2); border-color: var(--orange); }
</style>
