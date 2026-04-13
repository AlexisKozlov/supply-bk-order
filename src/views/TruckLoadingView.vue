<template>
  <div class="tl-page">
    <!-- Toolbar -->
    <div class="tl-toolbar">
      <h1>Загрузка машин</h1>
      <div class="tl-toolbar-actions">
        <input type="date" v-model="selectedDate" @change="loadDate" class="tl-date-input" />
        <button class="tl-btn" @click="setTomorrow">Завтра</button>
        <button class="tl-btn" @click="setDayAfter">Послезавтра</button>
      </div>
    </div>

    <!-- Tabs -->
    <div class="tl-page-tabs">
      <button class="tl-page-tab" :class="{ active: activeTab === 'constructor' }" @click="activeTab = 'constructor'">
        Конструктор
      </button>
      <button class="tl-page-tab" :class="{ active: activeTab === 'vehicles' }" @click="activeTab = 'vehicles'; store.loadVehicles()">
        Справочник машин
      </button>
    </div>

    <!-- ═══ TAB: Конструктор ═══ -->
    <template v-if="activeTab === 'constructor'">
      <!-- Loading -->
      <div v-if="store.loading" class="tl-loading">Загрузка...</div>

      <!-- No orders -->
      <div v-else-if="!store.orders.length" class="tl-empty">Нет заказов на эту дату.</div>

      <template v-else>
        <!-- Stats bar -->
        <div class="tl-stats">
          <div class="tl-stat">
            <span class="tl-stat-value">{{ store.totalStats.orders }}</span>
            <span class="tl-stat-label">заказов</span>
          </div>
          <div class="tl-stat">
            <span class="tl-stat-value">{{ store.totalStats.pallets.toFixed(1) }}</span>
            <span class="tl-stat-label">паллет</span>
          </div>
          <div class="tl-stat">
            <span class="tl-stat-value">{{ store.totalStats.weight.toFixed(0) }}</span>
            <span class="tl-stat-label">кг</span>
          </div>
          <div class="tl-stat" v-if="store.plan">
            <span class="tl-stat-value" :class="store.plan.status === 'confirmed' ? 'tl-stat-confirmed' : 'tl-stat-draft'">
              {{ store.plan.status === 'confirmed' ? 'Подтверждён' : 'Черновик' }}
            </span>
            <span class="tl-stat-label">статус</span>
          </div>
        </div>

        <!-- Controls -->
        <div class="tl-controls">
          <div class="tl-controls-left">
            <span class="tl-controls-label">Группировка:</span>
            <button class="tl-btn-sm" :class="{ 'tl-btn-active': store.groupBy === 'restaurant' }" @click="store.groupBy = 'restaurant'">По ресторанам</button>
            <button class="tl-btn-sm" :class="{ 'tl-btn-active': store.groupBy === 'category' }" @click="store.groupBy = 'category'">По режимам</button>
            <button class="tl-btn-sm" :class="{ 'tl-btn-active': store.groupBy === 'item' }" @click="store.groupBy = 'item'">По позициям</button>
          </div>
          <div class="tl-controls-right">
            <label class="tl-checkbox-label">
              <input type="checkbox" v-model="store.allowMixedModes" />
              Разрешить смешивание режимов
            </label>
            <div class="tl-add-truck-wrap">
              <button class="tl-btn tl-btn-primary" @click="toggleAddTruck">Добавить машину</button>
              <div v-if="showAddTruck" class="tl-dropdown">
                <div v-for="v in store.vehicles" :key="v.id" class="tl-dropdown-item" @click="selectVehicleForTruck(v)">
                  {{ v.name }} ({{ v.capacity_pallets }}п / {{ (+v.capacity_kg).toFixed(0) }}кг)
                </div>
                <div class="tl-dropdown-item tl-dropdown-custom" @click="addCustomTruck">Пользовательская</div>
              </div>
            </div>
            <button class="tl-btn" @click="handleAutoAssign">Автоматически</button>
            <button class="tl-btn tl-btn-outline" @click="handleReset">Сбросить</button>
          </div>
        </div>

        <!-- Two columns -->
        <div class="tl-columns">
          <!-- Left: Unassigned -->
          <div class="tl-left"
            @dragover.prevent
            @drop="onDropUnassigned($event)">
            <div class="tl-section-header">
              Нераспределённые <span class="tl-section-count">{{ filteredItems.length }}</span>
            </div>

            <!-- Фильтры -->
            <div class="tl-filters" v-if="store.orders.length">
              <div class="tl-filter-row">
                <input type="text" v-model="filterRestaurant" placeholder="Ресторан № (1, PS01…)" class="tl-filter-input" />
                <div class="tl-filter-cats">
                  <button class="tl-filter-cat" :class="{ active: !filterCategory }" @click="filterCategory = ''">Все</button>
                  <button class="tl-filter-cat cat-dry" :class="{ active: filterCategory === 'Сухой' }" @click="filterCategory = filterCategory === 'Сухой' ? '' : 'Сухой'">Сухой</button>
                  <button class="tl-filter-cat cat-cold" :class="{ active: filterCategory === 'Холод' }" @click="filterCategory = filterCategory === 'Холод' ? '' : 'Холод'">Холод</button>
                  <button class="tl-filter-cat cat-frozen" :class="{ active: filterCategory === 'Мороз' }" @click="filterCategory = filterCategory === 'Мороз' ? '' : 'Мороз'">Мороз</button>
                </div>
                <button class="tl-filter-more" :class="{ active: showAdvancedFilters }" @click="showAdvancedFilters = !showAdvancedFilters" title="Больше фильтров">
                  Фильтры
                  <span v-if="activeFiltersCount" class="tl-filter-more-count">{{ activeFiltersCount }}</span>
                  <span class="tl-filter-more-chev">{{ showAdvancedFilters ? '▴' : '▾' }}</span>
                </button>
              </div>
              <div v-if="store.availableEntities.length > 1" class="tl-filter-row tl-filter-entities">
                <span class="tl-filter-label">Юрлицо:</span>
                <button class="tl-filter-entity" :class="{ active: !store.entityFilter.length }" @click="clearEntityFilter">Все</button>
                <button
                  v-for="e in store.availableEntities" :key="e.legal_entity"
                  class="tl-filter-entity"
                  :class="[{ active: store.entityFilter.includes(e.legal_entity) }, 'tl-entity-' + e.legal_entity_group.toLowerCase()]"
                  @click="toggleEntityFilter(e.legal_entity)"
                >
                  {{ entityShortName(e.legal_entity) }}
                  <span class="tl-filter-entity-count">{{ e.orders_count }}</span>
                </button>
              </div>
              <div v-if="showAdvancedFilters" class="tl-filter-advanced">
                <div class="tl-filter-field">
                  <label>Город</label>
                  <select v-model="filterCity" class="tl-filter-select">
                    <option value="">Все</option>
                    <option v-for="c in availableCities" :key="c" :value="c">{{ c }}</option>
                  </select>
                </div>
                <div class="tl-filter-field">
                  <label>Регион</label>
                  <select v-model="filterRegion" class="tl-filter-select">
                    <option value="">Все</option>
                    <option v-for="r in availableRegions" :key="r" :value="r">{{ r }}</option>
                  </select>
                </div>
                <div class="tl-filter-field tl-filter-field-range">
                  <label>Паллеты</label>
                  <div class="tl-filter-range">
                    <input type="number" v-model="filterMinPallets" placeholder="от" min="0" step="0.5" class="tl-filter-num" />
                    <span>–</span>
                    <input type="number" v-model="filterMaxPallets" placeholder="до" min="0" step="0.5" class="tl-filter-num" />
                  </div>
                </div>
                <div class="tl-filter-field">
                  <label>Сортировка</label>
                  <select v-model="sortBy" class="tl-filter-select">
                    <option value="restaurant">По номеру ресторана</option>
                    <option value="pallets-desc">Больше паллет сначала</option>
                    <option value="pallets-asc">Меньше паллет сначала</option>
                    <option value="weight-desc">Тяжелее сначала</option>
                    <option value="city">По городу</option>
                  </select>
                </div>
                <button class="tl-filter-reset" @click="resetFilters" :disabled="!activeFiltersCount && sortBy === 'restaurant'">Сбросить</button>
              </div>
            </div>

            <div v-if="!filteredItems.length" class="tl-empty-section">
              {{ store.unassignedItems.length ? 'Ничего не найдено' : 'Все заказы распределены' }}
            </div>

            <!-- Визуальное разделение по юрлицам: шапка → карточки -->
            <template v-for="grp in groupedFilteredItems" :key="grp.legal_entity">
              <div class="tl-entity-header" :class="'tl-entity-' + grp.legal_entity_group.toLowerCase()">
                <span class="tl-entity-badge">{{ entityShortName(grp.legal_entity) }}</span>
                <span class="tl-entity-name">{{ grp.legal_entity }}</span>
                <span class="tl-entity-count">{{ grp.items.length }}</span>
              </div>

              <!-- groupBy = restaurant -->
              <template v-if="store.groupBy === 'restaurant'">
                <div v-for="item in grp.items" :key="item.key"
                  class="tl-card" :class="'tl-entity-' + grp.legal_entity_group.toLowerCase()"
                  draggable="true" @dragstart="onDragStart($event, item)">
                  <div class="tl-card-header">
                    <span class="tl-card-num">{{ formatRestaurantNumber(item.restaurant_number, item.legal_entity_group) }}</span>
                    <span class="tl-card-city">{{ item.city }}</span>
                  </div>
                  <div class="tl-card-stats">
                    <span v-for="(data, cat) in item.categories" :key="cat"
                      class="tl-cat-badge" :class="'cat-' + catClass(cat)">
                      {{ cat }}: {{ data.pallets }}п / {{ (+data.weight).toFixed(0) }}кг
                    </span>
                  </div>
                  <div class="tl-card-total">{{ fmtPallets(item.pallets) }} палл. | {{ (+item.weight_kg).toFixed(0) }} кг</div>
                </div>
              </template>

              <!-- groupBy = category -->
              <template v-else-if="store.groupBy === 'category'">
                <div v-for="item in grp.items" :key="item.key"
                  class="tl-card" :class="'tl-entity-' + grp.legal_entity_group.toLowerCase()"
                  draggable="true" @dragstart="onDragStart($event, item)">
                  <div class="tl-card-header">
                    <span class="tl-card-num">{{ formatRestaurantNumber(item.restaurant_number, item.legal_entity_group) }}</span>
                    <span class="tl-cat-badge" :class="'cat-' + catClass(item.category)">{{ item.category }}</span>
                  </div>
                  <div class="tl-card-total">{{ fmtPallets(item.pallets) }} палл. | {{ (+item.weight_kg).toFixed(0) }} кг</div>
                </div>
              </template>

              <!-- groupBy = item -->
              <template v-else>
                <div v-for="item in grp.items" :key="item.key"
                  class="tl-card" :class="'tl-entity-' + grp.legal_entity_group.toLowerCase()"
                  draggable="true" @dragstart="onDragStart($event, item)">
                  <div class="tl-card-header">
                    <span class="tl-card-num">{{ formatRestaurantNumber(item.restaurant_number, item.legal_entity_group) }}</span>
                    <span class="tl-card-sku">{{ item.sku }} {{ item.product_name }}</span>
                  </div>
                  <div class="tl-card-total">{{ item.quantity }} шт. | {{ fmtPallets(item.pallets) }} палл.</div>
                </div>
              </template>
            </template>
          </div>

          <!-- Right: Trucks -->
          <div class="tl-right">
            <div class="tl-section-header">
              Машины <span class="tl-section-count">{{ store.trucks.length }}</span>
            </div>

            <div v-if="!store.trucks.length" class="tl-empty-section">
              Добавьте машину для начала работы
            </div>

            <div v-for="(truck, tIdx) in store.trucks" :key="tIdx"
              class="tl-truck"
              @dragover.prevent="onDragOver($event, tIdx)"
              @dragleave="onDragLeave($event, tIdx)"
              @drop="onDrop($event, tIdx)"
              :class="{ 'tl-truck-dragover': dragOverTruck === tIdx }">

              <!-- Header -->
              <div class="tl-truck-header">
                <span class="tl-truck-name">Машина {{ tIdx + 1 }}{{ truck.custom_name ? ' — ' + truck.custom_name : '' }}</span>
                <span class="tl-truck-mode" :class="'mode-' + truck.mode">{{ modeLabel(truck.mode) }}</span>
                <button class="tl-btn-remove" @click="store.removeTruck(tIdx)">&#10005;</button>
              </div>

              <!-- Progress bars -->
              <div class="tl-truck-bars">
                <div class="tl-bar-row">
                  <span class="tl-bar-label">Паллеты</span>
                  <div class="tl-bar">
                    <div class="tl-bar-fill"
                      :style="{ width: Math.min(store.truckStats(truck).percentPallets, 100) + '%' }"
                      :class="barColor(store.truckStats(truck).percentPallets)"></div>
                  </div>
                  <span class="tl-bar-value">{{ store.truckStats(truck).pallets }}/{{ truck.capacity_pallets }}</span>
                </div>
                <div class="tl-bar-row">
                  <span class="tl-bar-label">Вес</span>
                  <div class="tl-bar">
                    <div class="tl-bar-fill"
                      :style="{ width: Math.min(store.truckStats(truck).percentWeight, 100) + '%' }"
                      :class="barColor(store.truckStats(truck).percentWeight)"></div>
                  </div>
                  <span class="tl-bar-value">{{ store.truckStats(truck).weight }}/{{ (+truck.capacity_kg).toFixed(0) }} кг</span>
                </div>
              </div>

              <!-- Assigned cards -->
              <div class="tl-truck-items">
                <div v-for="(a, aIdx) in truck.assignments" :key="aIdx"
                  class="tl-assigned-card"
                  draggable="true" @dragstart="onDragStartFromTruck($event, tIdx, aIdx, a)">
                  <span class="tl-assigned-rest">{{ formatRestaurantNumber(a.restaurant_number, a.legal_entity_group) }}</span>
                  <span class="tl-assigned-cat" v-if="a.category" :class="'cat-' + catClass(a.category)">{{ a.category }}</span>
                  <span class="tl-assigned-stats">{{ fmtPallets(a.pallets) }}п | {{ (+a.weight_kg).toFixed(0) }}кг</span>
                  <button class="tl-btn-unassign" @click="store.unassign(tIdx, aIdx)">&#10005;</button>
                </div>
                <div v-if="!truck.assignments.length" class="tl-truck-empty">
                  Перетащите заказы сюда
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Footer -->
        <div class="tl-footer">
          <div class="tl-footer-status" v-if="store.plan">
            Статус: <strong>{{ store.plan.status === 'confirmed' ? 'Подтверждён' : 'Черновик' }}</strong>
          </div>
          <div style="flex:1"></div>
          <button @click="handleReset" class="tl-btn tl-btn-outline">Сбросить</button>
          <button @click="handleExport" class="tl-btn tl-btn-export" :disabled="!store.trucks.length">Excel</button>
          <button @click="handleSave" class="tl-btn tl-btn-primary" :disabled="store.saving">
            {{ store.saving ? 'Сохранение...' : 'Сохранить' }}
          </button>
          <button v-if="store.plan?.status === 'draft'" @click="handleConfirm" class="tl-btn tl-btn-confirm">Подтвердить</button>
          <button v-if="store.plan?.status === 'confirmed'" @click="handleUnconfirm" class="tl-btn tl-btn-outline">В черновик</button>
        </div>
      </template>
    </template>

    <!-- ═══ TAB: Справочник машин ═══ -->
    <template v-if="activeTab === 'vehicles'">
      <div class="tl-vehicles-section">
        <table class="tl-table">
          <thead>
            <tr>
              <th>Название</th>
              <th>Паллеты</th>
              <th>Грузоподъёмность, кг</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="v in store.vehicles" :key="v.id">
              <td><input v-model="v.name" class="tl-inline-input" /></td>
              <td><input v-model.number="v.capacity_pallets" type="number" class="tl-inline-input tl-inline-num" /></td>
              <td><input v-model.number="v.capacity_kg" type="number" class="tl-inline-input tl-inline-num" /></td>
              <td class="tl-table-actions">
                <button class="tl-btn-sm tl-btn-primary" @click="handleSaveVehicle(v)">Сохранить</button>
                <button class="tl-btn-sm tl-btn-danger" @click="handleDeleteVehicle(v.id)">Удалить</button>
              </td>
            </tr>
            <tr v-if="showNewVehicle">
              <td><input v-model="newVehicle.name" class="tl-inline-input" placeholder="Название" /></td>
              <td><input v-model.number="newVehicle.capacity_pallets" type="number" class="tl-inline-input tl-inline-num" /></td>
              <td><input v-model.number="newVehicle.capacity_kg" type="number" class="tl-inline-input tl-inline-num" /></td>
              <td class="tl-table-actions">
                <button class="tl-btn-sm tl-btn-primary" @click="handleCreateVehicle">Добавить</button>
              </td>
            </tr>
          </tbody>
        </table>
        <button v-if="!showNewVehicle" class="tl-btn" @click="showNewVehicle = true; newVehicle = { name: '', capacity_pallets: 33, capacity_kg: 20000 }">+ Добавить тип машины</button>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useTruckLoadingStore } from '@/stores/truckLoadingStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { exportTruckLoading } from '@/lib/truckLoadingExport.js';
import { formatRestaurantNumber, parseRestaurantInput, ENTITY_SHORT_NAMES } from '@/lib/legalEntities.js';

const store = useTruckLoadingStore();
const toast = useToastStore();

const selectedDate = ref('');
const activeTab = ref('constructor');

// Drag & drop state
const dragOverTruck = ref(null);
const dragData = ref(null);

// Vehicle management
const showNewVehicle = ref(false);
const newVehicle = ref({ name: '', capacity_pallets: 33, capacity_kg: 20000 });

// Add truck dropdown
const showAddTruck = ref(false);

// Filters
const filterCategory = ref('');       // '' = все, 'Сухой', 'Холод', 'Мороз'
const filterRestaurant = ref('');     // поиск по номеру ресторана
const filterCity = ref('');           // точный город
const filterRegion = ref('');         // точный регион
const filterMinPallets = ref('');     // минимум паллет
const filterMaxPallets = ref('');     // максимум паллет
const sortBy = ref('restaurant');     // restaurant | pallets-desc | pallets-asc | weight-desc | city
const showAdvancedFilters = ref(false);

function resetFilters() {
  filterCategory.value = '';
  filterRestaurant.value = '';
  filterCity.value = '';
  filterRegion.value = '';
  filterMinPallets.value = '';
  filterMaxPallets.value = '';
  sortBy.value = 'restaurant';
  clearEntityFilter();
}

// Уникальные города и регионы из текущих заказов — для селектов
const availableCities = computed(() => {
  const set = new Set();
  for (const o of store.filteredOrders) if (o.city) set.add(o.city);
  return [...set].sort((a, b) => a.localeCompare(b, 'ru'));
});
const availableRegions = computed(() => {
  const set = new Set();
  for (const o of store.filteredOrders) if (o.region) set.add(o.region);
  return [...set].sort((a, b) => a.localeCompare(b, 'ru'));
});
const activeFiltersCount = computed(() => {
  let n = 0;
  if (filterCategory.value) n++;
  if (filterRestaurant.value.trim()) n++;
  if (filterCity.value) n++;
  if (filterRegion.value) n++;
  if (filterMinPallets.value) n++;
  if (filterMaxPallets.value) n++;
  if (store.entityFilter.length) n++;
  return n;
});

// Фильтр по юрлицам хранится в сторе, чтобы он пережил переход со вкладки
// и влиял на статистику сверху.
function toggleEntityFilter(entity) {
  const idx = store.entityFilter.indexOf(entity);
  if (idx >= 0) store.entityFilter.splice(idx, 1);
  else store.entityFilter.push(entity);
}
function clearEntityFilter() { store.entityFilter.splice(0); }

function entityShortName(entity) {
  return ENTITY_SHORT_NAMES[entity] || entity;
}

const filteredItems = computed(() => {
  let items = store.unassignedItems;
  const cat = filterCategory.value;
  const rest = filterRestaurant.value.trim();
  const city = filterCity.value;
  const region = filterRegion.value;
  const minP = parseFloat(filterMinPallets.value);
  const maxP = parseFloat(filterMaxPallets.value);

  if (cat) {
    items = items.filter(item => {
      // Для группировки «по ресторанам» — проверяем наличие категории в заказе
      if (item.categories) return item.categories[cat];
      // Для группировки «по режимам» / «по позициям» — прямое совпадение
      return item.category === cat;
    });
  }

  if (rest) {
    // Поддерживаем и сырой номер (1, 1001), и PS-формат (PS01)
    const parsed = parseRestaurantInput(rest);
    if (parsed) {
      items = items.filter(item => String(item.restaurant_number) === String(parsed.number));
    } else {
      items = items.filter(item => String(item.restaurant_number).includes(rest));
    }
  }

  if (city) items = items.filter(item => item.city === city);
  if (region) items = items.filter(item => item.region === region);
  if (!Number.isNaN(minP)) items = items.filter(item => (parseFloat(item.pallets) || 0) >= minP);
  if (!Number.isNaN(maxP)) items = items.filter(item => (parseFloat(item.pallets) || 0) <= maxP);

  // Сортировка
  const arr = [...items];
  if (sortBy.value === 'pallets-desc') {
    arr.sort((a, b) => (parseFloat(b.pallets) || 0) - (parseFloat(a.pallets) || 0));
  } else if (sortBy.value === 'pallets-asc') {
    arr.sort((a, b) => (parseFloat(a.pallets) || 0) - (parseFloat(b.pallets) || 0));
  } else if (sortBy.value === 'weight-desc') {
    arr.sort((a, b) => (parseFloat(b.weight_kg) || 0) - (parseFloat(a.weight_kg) || 0));
  } else if (sortBy.value === 'city') {
    arr.sort((a, b) => (a.city || '').localeCompare(b.city || '', 'ru') || (parseInt(a.restaurant_number) || 0) - (parseInt(b.restaurant_number) || 0));
  } else {
    // по умолчанию — по номеру ресторана
    arr.sort((a, b) => (parseInt(a.restaurant_number) || 0) - (parseInt(b.restaurant_number) || 0));
  }
  return arr;
});

// Группировка отображаемых карточек по юрлицу — нужна для визуального
// разделения Бургер БК / Воглия Матта / Пицца Стар в списке.
const ENTITY_ORDER = ['ООО "Бургер БК"', 'ООО "Воглия Матта"', 'ООО "Пицца Стар"'];
const groupedFilteredItems = computed(() => {
  const map = new Map();
  for (const item of filteredItems.value) {
    const le = item.legal_entity || 'Без юрлица';
    if (!map.has(le)) map.set(le, { legal_entity: le, legal_entity_group: item.legal_entity_group || 'BK_VM', items: [] });
    map.get(le).items.push(item);
  }
  const groups = [...map.values()];
  groups.sort((a, b) => {
    const ia = ENTITY_ORDER.indexOf(a.legal_entity);
    const ib = ENTITY_ORDER.indexOf(b.legal_entity);
    return (ia === -1 ? 999 : ia) - (ib === -1 ? 999 : ib);
  });
  return groups;
});

// --- Format helpers ---

function fmtPallets(v) {
  const n = +v;
  if (n === 0) return '0';
  if (n >= 1) return n.toFixed(1);
  // Меньше 1 — показываем 2 знака, но не «0.00»
  const s = n.toFixed(2);
  return s === '0.00' ? n.toFixed(3) : s;
}

// --- Date helpers ---

function setTomorrow() {
  const d = new Date();
  d.setDate(d.getDate() + 1);
  selectedDate.value = d.toISOString().slice(0, 10);
  loadDate();
}

function setDayAfter() {
  const d = new Date();
  d.setDate(d.getDate() + 2);
  selectedDate.value = d.toISOString().slice(0, 10);
  loadDate();
}

async function loadDate() {
  await store.loadDate(selectedDate.value);
}

// --- Display helpers ---

function modeLabel(mode) {
  return { any: 'Смешанный', dry: 'Сухой', cold: 'Холод', frozen: 'Мороз' }[mode] || mode;
}

function catClass(cat) {
  if (cat === 'Сухой') return 'dry';
  if (cat === 'Холод') return 'cold';
  if (cat === 'Мороз') return 'frozen';
  return '';
}

function barColor(percent) {
  if (percent > 95) return 'bar-red';
  if (percent > 80) return 'bar-orange';
  return 'bar-green';
}

// --- Drag & Drop ---

function onDragStart(e, item) {
  dragData.value = { source: 'unassigned', item };
  e.dataTransfer.effectAllowed = 'move';
  e.dataTransfer.setData('text/plain', JSON.stringify(item));
}

function onDragStartFromTruck(e, truckIdx, assignIdx, item) {
  dragData.value = { source: 'truck', truckIdx, assignIdx, item };
  e.dataTransfer.effectAllowed = 'move';
  e.dataTransfer.setData('text/plain', '');
}

function onDragOver(e, truckIdx) {
  e.preventDefault();
  dragOverTruck.value = truckIdx;
}

function onDragLeave(e, truckIdx) {
  if (!e.currentTarget.contains(e.relatedTarget)) {
    dragOverTruck.value = null;
  }
}

function onDrop(e, truckIdx) {
  e.preventDefault();
  dragOverTruck.value = null;
  if (!dragData.value) return;

  if (dragData.value.source === 'unassigned') {
    const check = store.canAssign(truckIdx, dragData.value.item);
    if (!check.ok) { toast.warning(check.reason); return; }
    store.assignToTruck(truckIdx, dragData.value.item);
  } else if (dragData.value.source === 'truck') {
    if (dragData.value.truckIdx === truckIdx) return;
    const item = store.trucks[dragData.value.truckIdx]?.assignments[dragData.value.assignIdx];
    if (!item) return;
    const check = store.canAssign(truckIdx, item);
    if (!check.ok) { toast.warning(check.reason); return; }
    store.moveAssignment(dragData.value.truckIdx, truckIdx, dragData.value.assignIdx);
  }
  dragData.value = null;
}

function onDropUnassigned(e) {
  e.preventDefault();
  if (dragData.value?.source === 'truck') {
    store.unassign(dragData.value.truckIdx, dragData.value.assignIdx);
  }
  dragData.value = null;
}

// --- Actions ---

async function handleSave() {
  try { await store.savePlan(); toast.success('План сохранён'); }
  catch (e) { toast.error('Ошибка', e.message); }
}

async function handleConfirm() {
  if (!store.plan?.id) { await handleSave(); }
  try { await store.confirmPlan(); toast.success('План подтверждён'); }
  catch (e) { toast.error('Ошибка', e.message); }
}

async function handleUnconfirm() {
  try { await store.unconfirmPlan(); toast.info('Возвращён в черновик'); }
  catch (e) { toast.error('Ошибка', e.message); }
}

function handleReset() {
  if (!confirm('Сбросить все назначения?')) return;
  store.resetAllAssignments();
}

async function handleAutoAssign() {
  if (store.trucks.length && !confirm('Текущее распределение будет заменено. Продолжить?')) return;
  try { await store.autoAssign(); toast.success('Автораспределение выполнено'); }
  catch (e) { toast.error('Ошибка', e.message); }
}

async function handleExport() {
  try { await exportTruckLoading(store.trucks, store.orders, store.deliveryDate, store.truckStats); }
  catch (e) { toast.error('Ошибка экспорта', e.message); }
}

// --- Vehicles ---

async function handleSaveVehicle(v) {
  try { await store.saveVehicle(v); toast.success('Сохранено'); }
  catch (e) { toast.error('Ошибка', e.message); }
}

async function handleDeleteVehicle(id) {
  if (!confirm('Удалить тип машины?')) return;
  try { await store.deleteVehicle(id); }
  catch (e) { toast.error('Ошибка', e.message); }
}

async function handleCreateVehicle() {
  try { await store.saveVehicle(newVehicle.value); showNewVehicle.value = false; toast.success('Добавлено'); }
  catch (e) { toast.error('Ошибка', e.message); }
}

// --- Add truck dropdown ---

function toggleAddTruck() {
  showAddTruck.value = !showAddTruck.value;
}

function selectVehicleForTruck(v) {
  store.addTruck(v);
  showAddTruck.value = false;
}

function addCustomTruck() {
  store.addTruck(null);
  showAddTruck.value = false;
}

// --- Init ---

onMounted(async () => {
  setTomorrow();
  await store.loadVehicles();
  await loadDate();
});
</script>

<style scoped>
.tl-page {
  padding: 20px 28px;
  display: flex;
  flex-direction: column;
  min-height: 0;
  height: 100%;
}

/* Toolbar */
.tl-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
  flex-wrap: wrap;
  gap: 12px;
  flex-shrink: 0;
}

.tl-toolbar h1 {
  font-size: 22px;
  font-weight: 700;
  color: #502314;
  margin: 0;
}

.tl-toolbar-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.tl-date-input {
  padding: 6px 10px;
  border: 2px solid #e0d5c8;
  border-radius: 8px;
  font-size: 14px;
  color: #502314;
  background: white;
  outline: none;
}

.tl-date-input:focus {
  border-color: #D62300;
}

/* Tabs */
.tl-page-tabs {
  display: flex;
  gap: 0;
  margin-bottom: 20px;
  border-bottom: 2px solid #e0d5c8;
  flex-shrink: 0;
}

.tl-page-tab {
  padding: 10px 20px;
  background: none;
  border: none;
  border-bottom: 3px solid transparent;
  font-size: 14px;
  font-weight: 600;
  color: #8b7355;
  cursor: pointer;
  margin-bottom: -2px;
  transition: all 0.15s;
}

.tl-page-tab:hover {
  color: #502314;
}

.tl-page-tab.active {
  color: #D62300;
  border-bottom-color: #D62300;
}

/* Buttons */
.tl-btn {
  padding: 7px 14px;
  border: 2px solid #e0d5c8;
  border-radius: 8px;
  background: white;
  color: #502314;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s;
  white-space: nowrap;
}

.tl-btn:hover {
  border-color: #502314;
  background: #f5f0eb;
}

.tl-btn-primary {
  background: #D62300;
  color: white;
  border-color: #D62300;
}

.tl-btn-primary:hover {
  background: #b51e00;
  border-color: #b51e00;
}

.tl-btn-outline {
  background: white;
  color: #502314;
  border-color: #e0d5c8;
}

.tl-btn-outline:hover {
  border-color: #502314;
}

.tl-btn-export {
  background: #16a34a;
  color: white;
  border-color: #16a34a;
}

.tl-btn-export:hover {
  background: #15803d;
  border-color: #15803d;
}

.tl-btn-export:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.tl-btn-confirm {
  background: #16a34a;
  color: white;
  border-color: #16a34a;
}

.tl-btn-confirm:hover {
  background: #15803d;
  border-color: #15803d;
}

.tl-btn-danger {
  background: #dc2626;
  color: white;
  border-color: #dc2626;
}

.tl-btn-danger:hover {
  background: #b91c1c;
  border-color: #b91c1c;
}

.tl-btn-sm {
  padding: 5px 12px;
  border: 2px solid #e0d5c8;
  border-radius: 6px;
  background: white;
  color: #502314;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s;
}

.tl-btn-sm:hover {
  border-color: #502314;
}

.tl-btn-sm.tl-btn-primary {
  background: #D62300;
  color: white;
  border-color: #D62300;
}

.tl-btn-sm.tl-btn-danger {
  background: #dc2626;
  color: white;
  border-color: #dc2626;
}

.tl-btn-active {
  background: #502314;
  color: white;
  border-color: #502314;
}

.tl-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* Loading / Empty */
.tl-loading, .tl-empty {
  text-align: center;
  padding: 40px 20px;
  color: #8b7355;
  font-size: 15px;
}

/* Stats bar */
.tl-stats {
  display: flex;
  gap: 24px;
  padding: 14px 20px;
  background: #f5f0eb;
  border-radius: 10px;
  margin-bottom: 16px;
  flex-shrink: 0;
}

.tl-stat {
  display: flex;
  flex-direction: column;
  align-items: center;
}

.tl-stat-value {
  font-size: 20px;
  font-weight: 700;
  color: #502314;
}

.tl-stat-label {
  font-size: 12px;
  color: #8b7355;
  margin-top: 2px;
}

.tl-stat-confirmed {
  color: #16a34a;
  font-size: 14px;
}

.tl-stat-draft {
  color: #d97706;
  font-size: 14px;
}

/* Controls */
.tl-controls {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
  flex-wrap: wrap;
  flex-shrink: 0;
}

.tl-controls-left {
  display: flex;
  align-items: center;
  gap: 6px;
}

.tl-controls-right {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.tl-controls-label {
  font-size: 13px;
  color: #8b7355;
  font-weight: 600;
  margin-right: 4px;
}

.tl-checkbox-label {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: #502314;
  cursor: pointer;
  white-space: nowrap;
}

.tl-checkbox-label input[type="checkbox"] {
  accent-color: #D62300;
}

/* Add truck dropdown */
.tl-add-truck-wrap {
  position: relative;
}

.tl-dropdown {
  position: absolute;
  top: 100%;
  left: 0;
  margin-top: 4px;
  background: white;
  border: 2px solid #e0d5c8;
  border-radius: 10px;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
  z-index: 100;
  min-width: 240px;
  overflow: hidden;
}

.tl-dropdown-item {
  padding: 10px 14px;
  font-size: 13px;
  color: #502314;
  cursor: pointer;
  transition: background 0.1s;
}

.tl-dropdown-item:hover {
  background: #f5f0eb;
}

.tl-dropdown-custom {
  border-top: 1px solid #e0d5c8;
  font-weight: 600;
}

/* Columns layout — обе колонки фиксированы по высоте экрана, скроллятся внутри себя */
.tl-columns {
  display: flex;
  gap: 20px;
  align-items: stretch;
  flex: 1 1 auto;
  min-height: 0;
  overflow: hidden;
}

.tl-left {
  flex: 0 0 40%;
  min-height: 0;
  display: flex;
  flex-direction: column;
  overflow-y: auto;
  overflow-x: hidden;
  padding-right: 8px;
  scrollbar-gutter: stable;
}

.tl-right {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  overflow-y: auto;
  overflow-x: hidden;
  padding-right: 8px;
  scrollbar-gutter: stable;
}

.tl-section-header {
  font-size: 15px;
  font-weight: 700;
  color: #502314;
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.tl-section-count {
  background: #e0d5c8;
  color: #502314;
  font-size: 12px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 10px;
}

.tl-empty-section {
  text-align: center;
  padding: 24px;
  color: #8b7355;
  font-size: 13px;
  border: 2px dashed #e0d5c8;
  border-radius: 10px;
}

/* Filters */
.tl-filters {
  margin-bottom: 10px;
}

.tl-filter-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.tl-filter-input {
  width: 100px;
  padding: 5px 8px;
  border: 2px solid #e0d5c8;
  border-radius: 6px;
  font-size: 12px;
  color: #502314;
  background: white;
  outline: none;
  flex-shrink: 0;
}

.tl-filter-input:focus {
  border-color: #D62300;
}

.tl-filter-cats {
  display: flex;
  gap: 4px;
  flex-wrap: wrap;
}

.tl-filter-cat {
  padding: 4px 10px;
  border: 2px solid #e0d5c8;
  border-radius: 6px;
  background: white;
  font-size: 11px;
  font-weight: 600;
  color: #502314;
  cursor: pointer;
  transition: all 0.15s;
}

.tl-filter-cat:hover {
  border-color: #502314;
}

.tl-filter-cat.active {
  border-color: #502314;
  background: #502314;
  color: white;
}

.tl-filter-cat.cat-dry.active {
  background: #92400e;
  border-color: #92400e;
}

.tl-filter-cat.cat-cold.active {
  background: #2563eb;
  border-color: #2563eb;
}

.tl-filter-cat.cat-frozen.active {
  background: #7c3aed;
  border-color: #7c3aed;
}

/* Кнопка «больше фильтров» */
.tl-filter-more {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-left: auto;
  padding: 5px 10px;
  border: 1.5px solid #e0d5c8;
  border-radius: 6px;
  background: white;
  color: #502314;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}
.tl-filter-more:hover { border-color: #D62300; }
.tl-filter-more.active {
  background: #fff2e0;
  border-color: #D62300;
  color: #D62300;
}
.tl-filter-more-count {
  min-width: 18px;
  padding: 1px 5px;
  background: #D62300;
  color: white;
  border-radius: 9px;
  font-size: 10px;
  font-weight: 700;
  text-align: center;
}
.tl-filter-more-chev { font-size: 10px; opacity: 0.7; }

.tl-filter-advanced {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: flex-end;
  margin-top: 8px;
  padding: 10px 12px;
  background: #fbf7f2;
  border: 1.5px solid #e0d5c8;
  border-radius: 8px;
}
.tl-filter-field { display: flex; flex-direction: column; gap: 3px; }
.tl-filter-field label {
  font-size: 10px;
  font-weight: 700;
  color: #8b7355;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.tl-filter-select {
  padding: 5px 8px;
  border: 1.5px solid #e0d5c8;
  border-radius: 6px;
  font-size: 12px;
  color: #502314;
  background: white;
  outline: none;
  min-width: 140px;
}
.tl-filter-select:focus { border-color: #D62300; }
.tl-filter-range { display: flex; align-items: center; gap: 6px; color: #8b7355; }
.tl-filter-num {
  width: 60px;
  padding: 5px 6px;
  border: 1.5px solid #e0d5c8;
  border-radius: 6px;
  font-size: 12px;
  color: #502314;
  background: white;
  outline: none;
}
.tl-filter-num:focus { border-color: #D62300; }
.tl-filter-reset {
  margin-left: auto;
  padding: 5px 12px;
  border: 1.5px solid #e0d5c8;
  border-radius: 6px;
  background: white;
  color: #8b7355;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}
.tl-filter-reset:hover:not(:disabled) { border-color: #D62300; color: #D62300; }
.tl-filter-reset:disabled { opacity: 0.4; cursor: not-allowed; }

/* Entity filters (legal entity) */
.tl-filter-entities {
  gap: 6px;
  flex-wrap: wrap;
  margin-top: 6px;
}
.tl-filter-label {
  font-size: 12px;
  font-weight: 600;
  color: #6b4f3a;
  margin-right: 4px;
}
.tl-filter-entity {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 10px;
  font-size: 12px;
  font-weight: 600;
  border: 1.5px solid #e0d5c8;
  border-radius: 14px;
  background: white;
  color: #502314;
  cursor: pointer;
  transition: all 0.15s;
}
.tl-filter-entity:hover { border-color: #D62300; }
.tl-filter-entity.active {
  background: #D62300;
  border-color: #D62300;
  color: white;
}
.tl-filter-entity.tl-entity-ps.active {
  background: #0e7490;
  border-color: #0e7490;
}
.tl-filter-entity-count {
  font-size: 10px;
  font-weight: 700;
  padding: 1px 5px;
  border-radius: 8px;
  background: rgba(0,0,0,0.08);
}
.tl-filter-entity.active .tl-filter-entity-count {
  background: rgba(255,255,255,0.28);
}

/* Заголовок группы юрлица перед карточками */
.tl-entity-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 12px 0 6px;
  padding: 6px 10px;
  border-radius: 8px;
  background: #fff2e0;
  border-left: 4px solid #D62300;
  font-size: 12px;
  color: #502314;
}
.tl-entity-header:first-child { margin-top: 0; }
.tl-entity-header.tl-entity-ps {
  background: #ecfeff;
  border-left-color: #0e7490;
}
.tl-entity-badge {
  padding: 2px 8px;
  border-radius: 10px;
  background: #D62300;
  color: white;
  font-weight: 700;
  font-size: 11px;
  letter-spacing: 0.3px;
}
.tl-entity-header.tl-entity-ps .tl-entity-badge { background: #0e7490; }
.tl-entity-name { flex: 1; font-weight: 600; }
.tl-entity-count {
  font-weight: 700;
  color: #8b7355;
}

/* Карточка окрашена под юрлицо — тонкая цветная полоса слева */
.tl-card.tl-entity-bk_vm { border-left: 4px solid #D62300; }
.tl-card.tl-entity-ps { border-left: 4px solid #0e7490; }

/* Cards (unassigned) */
.tl-card {
  padding: 10px;
  border: 2px solid #e0d5c8;
  border-radius: 10px;
  margin-bottom: 8px;
  cursor: grab;
  background: white;
  transition: all 0.15s;
}

.tl-card:hover {
  border-color: #D62300;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.tl-card:active {
  cursor: grabbing;
}

.tl-card-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 6px;
}

.tl-card-num {
  font-weight: 700;
  color: #502314;
  font-size: 15px;
}

.tl-card-city {
  font-size: 12px;
  color: #8b7355;
}

.tl-card-sku {
  font-size: 12px;
  color: #502314;
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.tl-card-stats {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-bottom: 4px;
}

.tl-card-total {
  font-size: 12px;
  color: #8b7355;
  font-weight: 600;
}

/* Category badges */
.tl-cat-badge {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 4px;
  font-weight: 600;
}

.cat-dry {
  background: #fef3c7;
  color: #92400e;
}

.cat-cold {
  background: #eff6ff;
  color: #2563eb;
}

.cat-frozen {
  background: #ede9fe;
  color: #7c3aed;
}

/* Trucks */
.tl-truck {
  border: 2px dashed #e0d5c8;
  border-radius: 12px;
  padding: 14px;
  margin-bottom: 12px;
  background: #faf7f4;
  transition: all 0.2s;
  min-height: 80px;
}

.tl-truck-dragover {
  border-color: #D62300;
  background: #fff5f3;
  border-style: solid;
}

.tl-truck-header {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 10px;
}

.tl-truck-name {
  font-weight: 700;
  font-size: 14px;
  color: #502314;
  flex: 1;
}

.tl-truck-mode {
  font-size: 11px;
  padding: 2px 10px;
  border-radius: 4px;
  font-weight: 600;
}

.mode-dry {
  background: #fef3c7;
  color: #92400e;
}

.mode-cold {
  background: #eff6ff;
  color: #2563eb;
}

.mode-frozen {
  background: #ede9fe;
  color: #7c3aed;
}

.mode-any {
  background: #f5f0eb;
  color: #502314;
}

.tl-btn-remove {
  width: 24px;
  height: 24px;
  border: none;
  background: none;
  color: #8b7355;
  font-size: 14px;
  cursor: pointer;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.15s;
}

.tl-btn-remove:hover {
  background: #dc2626;
  color: white;
}

/* Progress bars */
.tl-truck-bars {
  margin-bottom: 10px;
}

.tl-bar-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 4px;
}

.tl-bar-label {
  font-size: 11px;
  color: #8b7355;
  width: 55px;
  flex-shrink: 0;
}

.tl-bar {
  flex: 1;
  height: 8px;
  background: #e0d5c8;
  border-radius: 4px;
  overflow: hidden;
}

.tl-bar-fill {
  height: 100%;
  border-radius: 4px;
  transition: width 0.3s;
}

.bar-green {
  background: #16a34a;
}

.bar-orange {
  background: #d97706;
}

.bar-red {
  background: #dc2626;
}

.tl-bar-value {
  font-size: 11px;
  color: #502314;
  font-weight: 600;
  width: 90px;
  text-align: right;
  flex-shrink: 0;
}

/* Assigned cards inside trucks */
.tl-truck-items {
  min-height: 20px;
}

.tl-assigned-card {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 10px;
  background: white;
  border: 1px solid #e0d5c8;
  border-radius: 8px;
  margin-bottom: 4px;
  font-size: 13px;
  cursor: grab;
  transition: all 0.1s;
}

.tl-assigned-card:hover {
  border-color: #D62300;
}

.tl-assigned-card:active {
  cursor: grabbing;
}

.tl-assigned-rest {
  font-weight: 700;
  color: #502314;
  min-width: 36px;
}

.tl-assigned-cat {
  font-size: 11px;
  padding: 1px 6px;
  border-radius: 4px;
  font-weight: 600;
}

.tl-assigned-stats {
  flex: 1;
  text-align: right;
  color: #8b7355;
  font-size: 12px;
}

.tl-btn-unassign {
  width: 20px;
  height: 20px;
  border: none;
  background: none;
  color: #8b7355;
  font-size: 12px;
  cursor: pointer;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.15s;
  flex-shrink: 0;
}

.tl-btn-unassign:hover {
  background: #dc2626;
  color: white;
}

.tl-truck-empty {
  text-align: center;
  padding: 16px;
  color: #8b7355;
  font-size: 13px;
  font-style: italic;
}

/* Footer */
.tl-footer {
  position: sticky;
  bottom: 0;
  background: white;
  padding: 12px 20px;
  border-top: 2px solid #e0d5c8;
  display: flex;
  align-items: center;
  gap: 10px;
  z-index: 10;
  margin: 20px -20px 0;
}

.tl-footer-status {
  font-size: 13px;
  color: #502314;
}

/* Vehicles table */
.tl-vehicles-section {
  margin-top: 8px;
}

.tl-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 16px;
}

.tl-table th {
  text-align: left;
  padding: 10px 12px;
  font-size: 12px;
  font-weight: 700;
  color: #8b7355;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  border-bottom: 2px solid #e0d5c8;
}

.tl-table td {
  padding: 8px 12px;
  border-bottom: 1px solid #e0d5c8;
}

.tl-table-actions {
  display: flex;
  gap: 6px;
  white-space: nowrap;
}

.tl-inline-input {
  width: 100%;
  padding: 6px 8px;
  border: 2px solid #e0d5c8;
  border-radius: 6px;
  font-size: 13px;
  color: #502314;
  background: white;
  outline: none;
  transition: border-color 0.15s;
}

.tl-inline-input:focus {
  border-color: #D62300;
}

.tl-inline-num {
  width: 100px;
  text-align: right;
}

/* Responsive */
@media (max-width: 900px) {
  .tl-columns {
    flex-direction: column;
  }

  .tl-left {
    flex: none;
    width: 100%;
  }

  .tl-right {
    flex: none;
    width: 100%;
  }

  .tl-controls {
    flex-direction: column;
    align-items: flex-start;
  }

  .tl-toolbar {
    flex-direction: column;
    align-items: flex-start;
  }

  .tl-footer {
    flex-wrap: wrap;
  }
}
</style>
