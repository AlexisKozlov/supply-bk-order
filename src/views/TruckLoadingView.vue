<template>
  <div class="tl-page">
    <!-- Вся шапка — одна строка. Раньше здесь было шесть полос подряд,
         и на ноутбуке под работу оставалось меньше 200 точек -->
    <div class="tl-bar">
      <div class="tl-tabs">
        <button class="tl-tab" :class="{ active: activeTab === 'constructor' }" @click="activeTab = 'constructor'">Конструктор</button>
        <button class="tl-tab" :class="{ active: activeTab === 'directions' }" @click="goDirections">Направления</button>
        <button class="tl-tab" :class="{ active: activeTab === 'vehicles' }" @click="goVehicles">Машины</button>
      </div>

      <template v-if="activeTab === 'constructor'">
        <div class="tl-date">
          <button class="tl-date-arrow" title="Предыдущий день" @click="shiftDate(-1)">&#8249;</button>
          <input type="date" v-model="selectedDate" class="tl-date-input" @change="onDateChange">
          <button class="tl-date-arrow" title="Следующий день" @click="shiftDate(1)">&#8250;</button>
        </div>

        <!-- Порядок важен: на ноутбуке полоса узкая. Сначала то, без чего не
             обойтись, килограммы — последними: на узком экране они прячутся,
             и целиком сводка остаётся в подсказке -->
        <div v-if="store.orders.length" class="tl-summary" :title="summaryTitle">
          <span><b>{{ store.totalStats.orders }}</b> {{ orderWord(store.totalStats.orders) }}</span>
          <span v-if="unassignedCount" class="tl-summary-left">осталось {{ unassignedCount }}</span>
          <span v-else-if="store.trucks.length" class="tl-summary-ok">всё разложено</span>
          <span><b>{{ store.totalStats.pallets.toFixed(1) }}</b> п</span>
          <span class="tl-summary-kg"><b>{{ Math.round(store.totalStats.weight) }}</b> кг</span>
        </div>

        <!-- Один значок вместо двух: несохранённая работа важнее статуса плана -->
        <span v-if="store.dirty" class="tl-status is-unsaved">Не сохранено</span>
        <span v-else-if="store.plan" class="tl-status" :class="store.plan.status === 'confirmed' ? 'is-confirmed' : 'is-draft'">
          {{ store.plan.status === 'confirmed' ? 'Подтверждён' : 'Черновик' }}
        </span>

        <div class="tl-bar-actions">
          <div v-if="!compact" class="tl-add-wrap">
            <button class="tl-btn tl-btn-primary" @click.stop="showAddTruck = !showAddTruck">+ Машина</button>
            <div v-if="showAddTruck" class="tl-menu" @click.stop>
              <div v-if="store.directions.length" class="tl-menu-group">Направление рейса</div>
              <select v-if="store.directions.length" v-model="newTruckDirection" class="tl-menu-select">
                <option :value="null">Без направления</option>
                <option v-for="d in store.directions" :key="d.id" :value="d.id">{{ d.name }}</option>
              </select>
              <div class="tl-menu-group">Тип машины</div>
              <button v-for="v in store.vehicles" :key="v.id" class="tl-menu-item" @click="pickVehicle(v)">
                {{ v.name }}<span class="tl-menu-cap">{{ v.capacity_pallets }} п · {{ Math.round(+v.capacity_kg) }} кг</span>
              </button>
              <button class="tl-menu-item tl-menu-item-alt" @click="pickVehicle(null)">Своя машина</button>
              <div v-if="!store.vehicles.length" class="tl-menu-empty">Типов машин нет — заведите их на вкладке «Машины»</div>
            </div>
          </div>
          <button v-if="!compact" class="tl-btn" :disabled="!store.orders.length" @click="handleAutoAssign">Авто</button>
          <button v-if="!compact" class="tl-btn tl-btn-save" :disabled="store.saving || !store.trucks.length" @click="handleSave">
            <BurgerSpinner v-if="store.saving" size="xs" />
            <span>{{ store.saving ? 'Сохранение...' : 'Сохранить' }}</span>
          </button>
          <div class="tl-more-wrap">
            <button class="tl-btn tl-btn-more" title="Ещё" aria-label="Ещё действия" @click.stop="showMore = !showMore">&#8943;</button>
            <div v-if="showMore" class="tl-menu tl-menu-right" @click.stop>
              <div class="tl-menu-group">Показывать заказы</div>
              <button v-for="g in groupOptions" :key="g.value" class="tl-menu-item" :class="{ 'is-on': store.groupBy === g.value }" @click="setGroupBy(g.value)">
                {{ g.label }}
              </button>
              <div class="tl-menu-sep"></div>
              <button class="tl-menu-item" @click="toggleMixed">
                <span class="tl-menu-check">{{ store.allowMixedModes ? '☐' : '☑' }}</span> Мороз, холод и сухое — разными машинами
              </button>
              <div class="tl-menu-sep"></div>
              <button class="tl-menu-item" :disabled="!store.trucks.length" @click="runMore(handleExport)">Выгрузить в Excel</button>
              <button v-if="store.plan?.status === 'draft'" class="tl-menu-item" @click="runMore(handleConfirm)">Подтвердить план</button>
              <button v-if="store.plan?.status === 'confirmed'" class="tl-menu-item" @click="runMore(handleUnconfirm)">Вернуть в черновик</button>
              <button class="tl-menu-item tl-menu-danger" :disabled="!store.trucks.length" @click="runMore(handleReset)">Убрать все назначения</button>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- ═══ Конструктор ═══ -->
    <template v-if="activeTab === 'constructor'">
      <div v-if="store.loading" class="tl-state"><BurgerSpinner text="Загрузка..." /></div>

      <div v-else-if="loadError" class="tl-state tl-state-error">
        <div>Не удалось загрузить данные: {{ loadError }}</div>
        <button class="tl-btn" @click="loadDate">Повторить</button>
      </div>

      <div v-else-if="!store.orders.length" class="tl-state">
        На {{ prettyDate }} заказов ресторанов нет.
      </div>

      <div v-else class="tl-columns" :class="{ 'is-compact': compact }">
        <!-- Слева: что ещё не разложено -->
        <section class="tl-col">
          <header class="tl-col-head">
            <h2>Нераспределённые <span class="tl-col-count">{{ filteredItems.length }}</span></h2>
          </header>

          <div class="tl-filters">
            <input v-model="filterRestaurant" type="search" class="tl-input" placeholder="Ресторан №">
            <div class="tl-chips">
              <button class="tl-chip" :class="{ active: !filterCategory }" @click="filterCategory = ''">Все</button>
              <button v-for="c in CATEGORIES" :key="c" class="tl-chip" :class="['cat-' + catClass(c), { active: filterCategory === c }]" @click="filterCategory = filterCategory === c ? '' : c">{{ c }}</button>
            </div>
            <button class="tl-chip tl-chip-more" :class="{ active: showFilters }" @click="showFilters = !showFilters">
              Ещё<span v-if="activeFiltersCount" class="tl-chip-num">{{ activeFiltersCount }}</span>
            </button>
          </div>

          <div v-if="showFilters" class="tl-filters-more">
            <label class="tl-field">
              <span>Город</span>
              <select v-model="filterCity" class="tl-input"><option value="">Все</option><option v-for="c in availableCities" :key="c">{{ c }}</option></select>
            </label>
            <label class="tl-field">
              <span>Сортировка</span>
              <select v-model="sortBy" class="tl-input">
                <option value="restaurant">По номеру</option>
                <option value="pallets-desc">Больше паллет</option>
                <option value="pallets-asc">Меньше паллет</option>
                <option value="weight-desc">Тяжелее</option>
                <option value="city">По городу</option>
              </select>
            </label>
            <label v-if="store.directions.length" class="tl-field">
              <span>Направление</span>
              <select v-model="filterDirection" class="tl-input">
                <option value="">Все</option>
                <option v-for="d in store.directions" :key="d.id" :value="String(d.id)">{{ d.name }}</option>
                <option value="none">Без направления</option>
              </select>
            </label>
            <label v-if="store.availableEntities.length > 1" class="tl-field">
              <span>Юрлицо</span>
              <select v-model="entityOne" class="tl-input">
                <option value="">Все</option>
                <option v-for="e in store.availableEntities" :key="e.legal_entity" :value="e.legal_entity">{{ entityShortName(e.legal_entity) }} ({{ e.orders_count }})</option>
              </select>
            </label>
            <button class="tl-btn tl-btn-sm" :disabled="!activeFiltersCount && sortBy === 'restaurant'" @click="resetFilters">Сбросить</button>
          </div>

          <div class="tl-scroll" @dragover.prevent @drop.prevent="onDropUnassigned">
            <div v-if="!filteredItems.length" class="tl-col-empty">
              {{ store.unassignedItems.length ? 'Ничего не найдено' : 'Все заказы разложены по машинам' }}
            </div>
            <TlOrderCard
              v-for="item in filteredItems"
              :key="item.key"
              :item="item"
              :mode="store.groupBy"
              :targets="menuKey === item.key ? store.assignTargets(item) : []"
              :menu-open="menuKey === item.key"
              :can-assign="!compact"
              @toggle-menu="toggleMenu"
              @assign="onAssign"
              @dragstart="onDragStartItem"
            />
          </div>
        </section>

        <!-- Справа: машины -->
        <section class="tl-col">
          <header class="tl-col-head">
            <h2>Машины <span class="tl-col-count">{{ store.trucks.length }}</span></h2>
            <button v-if="compact && store.trucks.length" class="tl-hint-compact">только просмотр</button>
          </header>

          <div class="tl-scroll">
            <div v-if="!store.trucks.length" class="tl-col-empty">
              <template v-if="compact">Машины на этот день ещё не набраны</template>
              <template v-else>
                Добавьте машину кнопкой «+ Машина» или нажмите «Авто» — портал разложит заказы сам
              </template>
            </div>
            <TlTruckCard
              v-for="(truck, i) in store.trucks"
              :key="truck.uid"
              :truck="truck"
              :index="i"
              :stats="store.statsByTruck[truck.uid] || store.truckStats(truck)"
              :drag-over="dragOverTruck === truck.uid"
              :readonly="compact"
              @remove="handleRemoveTruck(truck)"
              @unassign="uid => store.unassign(truck.uid, uid)"
              @dragover="dragOverTruck = truck.uid"
              @dragleave="onTruckDragLeave"
              @drop="onDropTruck(truck.uid)"
              @item-dragstart="p => onDragStartAssignment(p, truck.uid)"
            />
          </div>
        </section>
      </div>
    </template>

    <!-- ═══ Направления ═══ -->
    <div v-else-if="activeTab === 'directions'" class="tl-vehicles-wrap">
      <TlDirectionsTab
        :directions="store.directions"
        :cities="directionCities"
        :restaurants="directionRestaurants"
        :loading="directionsLoading"
        @save="handleSaveDirection"
        @delete="handleDeleteDirection"
      />
    </div>

    <!-- ═══ Машины ═══ -->
    <div v-else class="tl-vehicles-wrap">
      <TlVehiclesTab
        :vehicles="store.vehicles"
        :loading="vehiclesLoading"
        @save="handleSaveVehicle"
        @create="handleCreateVehicle"
        @delete="handleDeleteVehicle"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, onActivated, onDeactivated, watch } from 'vue';
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router';
import { useTabRoute } from '@/composables/useTabRoute.js';
import { useTruckLoadingStore } from '@/stores/truckLoadingStore.js';
import { appConfirm } from '@/lib/appDialogs.js';
import { useToastStore } from '@/stores/toastStore.js';
import { exportTruckLoading } from '@/lib/truckLoadingExport.js';
import { parseRestaurantInput, ENTITY_SHORT_NAMES } from '@/lib/legalEntities.js';
import TlOrderCard from '@/components/truck-loading/TlOrderCard.vue';
import TlTruckCard from '@/components/truck-loading/TlTruckCard.vue';
import TlVehiclesTab from '@/components/truck-loading/TlVehiclesTab.vue';
import TlDirectionsTab from '@/components/truck-loading/TlDirectionsTab.vue';

const store = useTruckLoadingStore();
const toast = useToastStore();
const route = useRoute();
const router = useRouter();

const CATEGORIES = ['Сухой', 'Холод', 'Мороз'];
const groupOptions = [
  { value: 'restaurant', label: 'Целиком по ресторанам' },
  { value: 'category', label: 'Отдельно по режимам' },
  { value: 'item', label: 'По позициям' },
];

const selectedDate = ref('');
const activeTab = useTabRoute('constructor', ['constructor', 'directions', 'vehicles']);
const loadError = ref('');
const vehiclesLoading = ref(false);

const showAddTruck = ref(false);
const newTruckDirection = ref(null);
const directionsLoading = ref(false);
// Города и рестораны для формы направления приходят вместе со справочником
const directionCities = ref([]);
const directionRestaurants = ref([]);
const showMore = ref(false);
const showFilters = ref(false);
const menuKey = ref(null);

// Узкий экран: раскладывать заказы мышью там нельзя, поэтому показываем
// готовый план только на просмотр
const compact = ref(false);
let mq = null;
function applyMq(e) { compact.value = e.matches; }

// --- Фильтры ---
const filterCategory = ref('');
const filterRestaurant = ref('');
const filterCity = ref('');
const filterDirection = ref('');
const sortBy = ref('restaurant');
// Один выбранный список юрлиц вместо набора кнопок — в сторе он остался массивом
const entityOne = computed({
  get: () => store.entityFilter[0] || '',
  set: (v) => { store.entityFilter.splice(0); if (v) store.entityFilter.push(v); },
});

const activeFiltersCount = computed(() => {
  let n = 0;
  if (filterCategory.value) n++;
  if (filterRestaurant.value.trim()) n++;
  if (filterCity.value) n++;
  if (filterDirection.value) n++;
  if (store.entityFilter.length) n++;
  return n;
});

function resetFilters() {
  filterCategory.value = '';
  filterRestaurant.value = '';
  filterCity.value = '';
  filterDirection.value = '';
  sortBy.value = 'restaurant';
  store.entityFilter.splice(0);
}

const availableCities = computed(() => {
  const set = new Set();
  for (const o of store.filteredOrders) if (o.city) set.add(o.city);
  return [...set].sort((a, b) => a.localeCompare(b, 'ru'));
});

const filteredItems = computed(() => {
  let items = store.unassignedItems;
  const cat = filterCategory.value;
  const rest = filterRestaurant.value.trim();
  const city = filterCity.value;

  if (cat) {
    items = items.filter(item => (item.categories ? item.categories[cat] : item.category === cat));
  }
  if (rest) {
    const parsed = parseRestaurantInput(rest);
    items = parsed
      ? items.filter(item => String(item.restaurant_number) === String(parsed.number))
      : items.filter(item => String(item.restaurant_number).includes(rest));
  }
  if (city) items = items.filter(item => item.city === city);
  if (filterDirection.value === 'none') items = items.filter(item => !item.direction_id);
  else if (filterDirection.value) items = items.filter(item => String(item.direction_id) === filterDirection.value);

  const arr = [...items];
  const num = (v) => parseInt(v) || 0;
  if (sortBy.value === 'pallets-desc') arr.sort((a, b) => (+b.pallets || 0) - (+a.pallets || 0));
  else if (sortBy.value === 'pallets-asc') arr.sort((a, b) => (+a.pallets || 0) - (+b.pallets || 0));
  else if (sortBy.value === 'weight-desc') arr.sort((a, b) => (+b.weight_kg || 0) - (+a.weight_kg || 0));
  else if (sortBy.value === 'city') arr.sort((a, b) => (a.city || '').localeCompare(b.city || '', 'ru') || num(a.restaurant_number) - num(b.restaurant_number));
  else arr.sort((a, b) => num(a.restaurant_number) - num(b.restaurant_number));
  return arr;
});

const unassignedCount = computed(() => store.unassignedItems.length);

// На узком экране килограммы прячутся — подсказка держит всю сводку целиком
const summaryTitle = computed(() => {
  const s = store.totalStats;
  return `${s.orders} ${orderWord(s.orders)} · ${s.pallets.toFixed(1)} паллет · ${Math.round(s.weight)} кг`;
});

// --- Формат ---
function entityShortName(entity) { return ENTITY_SHORT_NAMES[entity] || entity; }
function catClass(cat) { return { 'Сухой': 'dry', 'Холод': 'cold', 'Мороз': 'frozen' }[cat] || ''; }
function orderWord(n) {
  const m10 = n % 10, m100 = n % 100;
  if (m10 === 1 && m100 !== 11) return 'заказ';
  if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return 'заказа';
  return 'заказов';
}
const prettyDate = computed(() => {
  if (!selectedDate.value) return '';
  return new Date(selectedDate.value + 'T00:00:00').toLocaleDateString('ru-RU', { day: '2-digit', month: 'long' });
});

// --- Дата ---
function dateStr(d) { return d.toISOString().slice(0, 10); }

// Дата живёт в адресе: ссылку на конкретный день можно отправить коллеге
function syncDateToUrl() {
  if (!selectedDate.value || route.query.date === selectedDate.value) return;
  router.replace({ query: { ...route.query, date: selectedDate.value } }).catch(() => {});
}

async function shiftDate(days) {
  if (!await confirmLoseWork()) return;
  const d = new Date(selectedDate.value + 'T00:00:00');
  d.setDate(d.getDate() + days);
  selectedDate.value = dateStr(d);
  await loadDate();
}

let lastLoadedDate = '';
async function onDateChange() {
  if (!await confirmLoseWork()) { selectedDate.value = lastLoadedDate; return; }
  await loadDate();
}

async function loadDate() {
  loadError.value = '';
  syncDateToUrl();
  try {
    await store.loadDate(selectedDate.value);
    lastLoadedDate = selectedDate.value;
  } catch (e) {
    loadError.value = e.message || 'неизвестная ошибка';
  }
}

// Несохранённую раскладку раньше стирала любая смена даты — молча
async function confirmLoseWork() {
  if (!store.dirty) return true;
  const ok = await appConfirm('Раскладка не сохранена. Уйти и потерять её?', { okText: 'Уйти', cancelText: 'Остаться', danger: true });
  if (ok) store.markClean();
  return ok;
}

// --- Меню и назначение ---
function toggleMenu(key) { menuKey.value = menuKey.value === key ? null : key; }

function onAssign({ item, truckUid }) {
  const check = store.canAssign(truckUid, item);
  if (!check.ok) { toast.warning(check.reason); return; }
  store.assignToTruck(truckUid, item);
  menuKey.value = null;
}

function setGroupBy(v) { store.groupBy = v; showMore.value = false; }
function toggleMixed() { store.allowMixedModes = !store.allowMixedModes; }
function runMore(fn) { showMore.value = false; fn(); }

function pickVehicle(v) {
  store.addTruck(v, newTruckDirection.value);
  showAddTruck.value = false;
}

// --- Перетаскивание (мышью, как и раньше) ---
const dragOverTruck = ref(null);
const dragPayload = ref(null);

function onDragStartItem({ event, item }) {
  dragPayload.value = { source: 'unassigned', item };
  event.dataTransfer.effectAllowed = 'move';
  event.dataTransfer.setData('text/plain', item.key);
}

function onDragStartAssignment({ event, assignment }, truckUid) {
  dragPayload.value = { source: 'truck', truckUid, assignment };
  event.dataTransfer.effectAllowed = 'move';
  event.dataTransfer.setData('text/plain', assignment.uid);
}

function onTruckDragLeave(e) {
  if (!e?.currentTarget?.contains(e.relatedTarget)) dragOverTruck.value = null;
}

function onDropTruck(truckUid) {
  dragOverTruck.value = null;
  const p = dragPayload.value;
  if (!p) return;
  if (p.source === 'unassigned') {
    const check = store.canAssign(truckUid, p.item);
    if (!check.ok) { toast.warning(check.reason); return; }
    store.assignToTruck(truckUid, p.item);
  } else if (p.source === 'truck' && p.truckUid !== truckUid) {
    const check = store.canAssign(truckUid, p.assignment);
    if (!check.ok) { toast.warning(check.reason); return; }
    store.moveAssignment(p.truckUid, truckUid, p.assignment.uid);
  }
  dragPayload.value = null;
}

function onDropUnassigned() {
  const p = dragPayload.value;
  if (p?.source === 'truck') store.unassign(p.truckUid, p.assignment.uid);
  dragPayload.value = null;
}

// --- Действия ---
async function handleSave() {
  try { await store.savePlan(); toast.success('План сохранён'); }
  catch (e) { toast.error('Не сохранилось', e.message); }
}

async function handleConfirm() {
  try {
    if (store.dirty || !store.plan?.id) await store.savePlan();
    await store.confirmPlan();
    toast.success('План подтверждён');
  } catch (e) { toast.error('Ошибка', e.message); }
}

async function handleUnconfirm() {
  try { await store.unconfirmPlan(); toast.info('Возвращён в черновик'); }
  catch (e) { toast.error('Ошибка', e.message); }
}

async function handleReset() {
  if (!await appConfirm('Убрать все заказы из машин? Сами машины останутся.', { okText: 'Убрать', danger: true })) return;
  store.resetAllAssignments();
}

async function handleRemoveTruck(truck) {
  if (truck.assignments.length && !await appConfirm(`Убрать машину вместе с ${truck.assignments.length} назначениями?`, { okText: 'Убрать', danger: true })) return;
  store.removeTruck(truck.uid);
}

async function handleAutoAssign() {
  if (store.trucks.length && !await appConfirm('Текущая раскладка будет заменена. Продолжить?', { okText: 'Заменить', danger: true })) return;
  try { await store.autoAssign(); toast.success('Портал разложил заказы'); }
  catch (e) { toast.error('Ошибка', e.message); }
}

async function handleExport() {
  try { await exportTruckLoading(store.trucks, store.orders, store.deliveryDate, store.truckStats); }
  catch (e) { toast.error('Ошибка выгрузки', e.message); }
}

// --- Справочник машин ---
async function goDirections() {
  activeTab.value = 'directions';
  await loadDirections();
}

// silent — при открытии страницы: если направлений нет, ругаться не о чем
async function loadDirections({ silent = false } = {}) {
  directionsLoading.value = true;
  try {
    const data = await store.loadDirections();
    // Сервер отдаёт справочники вместе со списком — отдельный запрос не нужен
    if (data?.available_cities) directionCities.value = data.available_cities;
    if (data?.available_restaurants) directionRestaurants.value = data.available_restaurants;
  } catch (e) {
    if (!silent) toast.error('Ошибка', e.message);
  } finally { directionsLoading.value = false; }
}

async function handleSaveDirection(d) {
  try { await store.saveDirection(d); await loadDirections(); toast.success('Направление сохранено'); }
  catch (e) { toast.error('Ошибка', e.message); }
}

async function handleDeleteDirection(d) {
  if (!await appConfirm(`Удалить направление «${d.name}»? Машины, которым оно назначено, останутся без направления.`, { okText: 'Удалить', danger: true })) return;
  try { await store.deleteDirection(d.id); await loadDirections(); toast.success('Удалено'); }
  catch (e) { toast.error('Ошибка', e.message); }
}

async function goVehicles() {
  activeTab.value = 'vehicles';
  await loadVehicles();
}

async function loadVehicles() {
  vehiclesLoading.value = true;
  try { await store.loadVehicles(); }
  catch (e) { toast.error('Ошибка', e.message); }
  finally { vehiclesLoading.value = false; }
}

async function handleSaveVehicle(v) {
  try { await store.saveVehicle(v); toast.success('Сохранено'); }
  catch (e) { toast.error('Ошибка', e.message); }
}

async function handleCreateVehicle(v) {
  try { await store.saveVehicle(v); toast.success('Тип машины добавлен'); }
  catch (e) { toast.error('Ошибка', e.message); }
}

async function handleDeleteVehicle(v) {
  if (!await appConfirm(`Удалить «${v.name}» из списка типов машин?`, { okText: 'Удалить', danger: true })) return;
  try { await store.deleteVehicle(v.id); toast.success('Удалено'); }
  catch (e) { toast.error('Ошибка', e.message); }
}

// --- Клик мимо закрывает меню ---
function closeMenus(e) {
  if (showAddTruck.value && !e.target.closest('.tl-add-wrap')) showAddTruck.value = false;
  if (showMore.value && !e.target.closest('.tl-more-wrap')) showMore.value = false;
  if (menuKey.value && !e.target.closest('.tlc')) menuKey.value = null;
}

function beforeUnloadHandler(e) {
  if (store.dirty) { e.preventDefault(); e.returnValue = ''; }
}

onBeforeRouteLeave(async () => await confirmLoseWork());

// Смена группировки меняет карточки — открытое меню перестаёт им соответствовать
watch(() => store.groupBy, () => { menuKey.value = null; });

onMounted(async () => {
  mq = window.matchMedia('(max-width: 900px)');
  compact.value = mq.matches;
  mq.addEventListener('change', applyMq);
  window.addEventListener('beforeunload', beforeUnloadHandler);

  const fromUrl = String(route.query.date || '');
  if (/^\d{4}-\d{2}-\d{2}$/.test(fromUrl)) {
    selectedDate.value = fromUrl;
  } else {
    const d = new Date();
    d.setDate(d.getDate() + 1);
    selectedDate.value = dateStr(d);
  }
  await Promise.all([loadVehicles(), loadDirections({ silent: true }), loadDate()]);
});

// Страница живёт в кэше вкладок: слушатель работает, только пока раздел открыт
onActivated(() => document.addEventListener('click', closeMenus));
onDeactivated(() => document.removeEventListener('click', closeMenus));

onBeforeUnmount(() => {
  document.removeEventListener('click', closeMenus);
  window.removeEventListener('beforeunload', beforeUnloadHandler);
  mq?.removeEventListener('change', applyMq);
});
</script>

<style scoped>
/* Экран занимает всю высоту, прокручиваются только колонки внутри */
.tl-page {
  height: 100%;
  display: flex;
  flex-direction: column;
  min-height: 0;
  padding: 12px 16px 14px;
  box-sizing: border-box;
  gap: 10px;
}

/* ── Единственная полоса управления ── */
/* wrap — страховка: лучше вторая строка, чем обрезанное на середине число */
.tl-bar { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; flex: 0 0 auto; }

.tl-tabs { display: flex; gap: 2px; background: #f1eae2; border-radius: 8px; padding: 2px; }
.tl-tab { border: none; background: none; padding: 5px 9px; border-radius: 6px; font-size: 13px; color: #8b7355; cursor: pointer; white-space: nowrap; }
.tl-tab:hover { color: #502314; }
.tl-tab.active { background: #fff; color: #502314; font-weight: 600; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }

.tl-date { display: flex; align-items: center; gap: 2px; }
.tl-date-arrow { width: 24px; height: 30px; border: 1px solid #e0d5c8; background: #fff; border-radius: 6px; color: #8b7355; font-size: 15px; line-height: 1; cursor: pointer; }
.tl-date-arrow:hover { border-color: #E76F51; color: #E76F51; }
.tl-date-input { padding: 5px 8px; border: 1px solid #e0d5c8; border-radius: 6px; font-size: 13px; font-family: inherit; color: #502314; }

/* Сводка сжимается первой: кнопки действий не должны уезжать на вторую строку */
.tl-summary { display: flex; align-items: center; gap: 10px; font-size: 12px; color: #8b7355; white-space: nowrap; min-width: 0; flex-shrink: 1; }
/* На ноутбуке 1366 полосе не хватает места: килограммы уходят в подсказку,
   остальное чуть ужимается — чтобы всё уместилось в одну строку */
@media (max-width: 1500px) {
  .tl-summary-kg { display: none; }
  .tl-summary { gap: 8px; }
  .tl-tab { font-size: 12px; padding: 5px 8px; }
  .tl-date-input { font-size: 12px; padding: 5px 6px; }
  .tl-date-arrow { width: 22px; }
  .tl-btn { font-size: 12px; padding: 5px 9px; }
}
.tl-summary b { color: #502314; font-size: 13px; font-variant-numeric: tabular-nums; }
.tl-summary-left { color: #b35900; font-weight: 600; }
.tl-summary-ok { color: #2e7d32; font-weight: 600; }

.tl-status { font-size: 11px; font-weight: 600; padding: 2px 9px; border-radius: 10px; white-space: nowrap; }
.tl-status.is-draft { background: #fff3e0; color: #b35900; }
.tl-status.is-confirmed { background: #e3f6e6; color: #1b5e20; }
.tl-status.is-unsaved { background: #fdecea; color: #c62828; }

.tl-bar-actions { display: flex; align-items: center; gap: 6px; margin-left: auto; flex: 0 0 auto; }

.tl-btn { padding: 6px 10px; border: 1px solid #e0d5c8; border-radius: 6px; background: #fff; font-size: 13px; color: #502314; cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px; }
.tl-btn:hover:not(:disabled) { background: #faf6f1; border-color: #d8c9b8; }
.tl-btn:disabled { opacity: 0.5; cursor: default; }
.tl-btn-sm { padding: 5px 10px; font-size: 12px; }
.tl-btn-primary { background: #E76F51; border-color: #E76F51; color: #fff; }
.tl-btn-primary:hover:not(:disabled) { background: #d45f42; border-color: #d45f42; }
.tl-btn-save { background: #2e7d32; border-color: #2e7d32; color: #fff; }
.tl-btn-save:hover:not(:disabled) { background: #24631f; border-color: #24631f; }
.tl-btn-more { padding: 6px 10px; font-size: 15px; line-height: 1; color: #8b7355; }

/* ── Выпадающие меню ── */
.tl-add-wrap, .tl-more-wrap { position: relative; }
.tl-menu {
  position: absolute; top: calc(100% + 4px); left: 0; z-index: 60;
  display: flex; flex-direction: column; min-width: 230px;
  background: #fff; border: 1px solid #e0d5c8; border-radius: 8px;
  box-shadow: 0 8px 22px rgba(0,0,0,0.12); padding: 4px;
}
.tl-menu-right { left: auto; right: 0; }
.tl-menu-item { display: flex; align-items: center; gap: 8px; padding: 7px 10px; border: none; background: none; border-radius: 6px; font-size: 13px; color: #333; text-align: left; cursor: pointer; white-space: nowrap; }
.tl-menu-item:hover:not(:disabled) { background: #faf6f1; }
.tl-menu-item:disabled { opacity: 0.45; cursor: default; }
.tl-menu-item.is-on { color: #E76F51; font-weight: 600; }
.tl-menu-item-alt { border-top: 1px solid #f0eae3; color: #8b7355; }
.tl-menu-cap { margin-left: auto; font-size: 11px; color: #8b7355; }
.tl-menu-check { width: 14px; }
.tl-menu-select { margin: 2px 6px 6px; padding: 5px 8px; border: 1px solid #e0d5c8; border-radius: 6px; font-size: 12px; font-family: inherit; color: #333; }
.tl-menu-group { padding: 6px 10px 3px; font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; color: #b0a396; }
.tl-menu-sep { height: 1px; background: #f0eae3; margin: 4px 0; }
.tl-menu-danger { color: #c62828; }
.tl-menu-danger:hover:not(:disabled) { background: #fdf2f2; }
.tl-menu-empty { padding: 10px; font-size: 12px; color: #8b7355; }

/* ── Рабочая область ── */
.tl-state { flex: 1 1 auto; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; color: #8b7355; font-size: 14px; }
.tl-state-error { color: #c62828; }

.tl-columns {
  flex: 1 1 auto;
  min-height: 0;
  display: grid;
  grid-template-columns: minmax(300px, 38%) minmax(0, 1fr);
  gap: 12px;
}

.tl-col { display: flex; flex-direction: column; min-height: 0; background: #fff; border: 1px solid #ece4da; border-radius: 10px; padding: 10px; }
.tl-col-head { display: flex; align-items: center; gap: 8px; flex: 0 0 auto; margin-bottom: 8px; }
.tl-col-head h2 { margin: 0; font-size: 13px; text-transform: uppercase; letter-spacing: 0.04em; color: #8b7355; }
.tl-col-count { background: #f1eae2; color: #502314; border-radius: 8px; padding: 1px 7px; font-size: 12px; margin-left: 4px; }
.tl-hint-compact { margin-left: auto; border: none; background: #f1eae2; color: #8b7355; font-size: 10px; padding: 2px 8px; border-radius: 8px; }

/* Прокручивается только этот блок — за счёт него страница держится в экране */
.tl-scroll { flex: 1 1 auto; min-height: 0; overflow-y: auto; overflow-x: hidden; padding-right: 2px; }
.tl-col-empty { padding: 24px 12px; text-align: center; color: #b0a396; font-size: 13px; }

/* ── Фильтры ── */
.tl-filters { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; flex: 0 0 auto; margin-bottom: 6px; }
.tl-input { padding: 5px 8px; border: 1px solid #e0d5c8; border-radius: 6px; font-size: 12px; font-family: inherit; color: #333; max-width: 130px; }
.tl-input:focus { outline: none; border-color: #E76F51; box-shadow: 0 0 0 2px rgba(231,111,81,0.15); }
.tl-chips { display: flex; gap: 3px; }
.tl-chip { border: 1px solid transparent; background: #f5f0eb; color: #6b6b6b; font-size: 11px; padding: 4px 9px; border-radius: 10px; cursor: pointer; white-space: nowrap; }
.tl-chip:hover { filter: brightness(0.97); }
.tl-chip.active { border-color: currentColor; font-weight: 600; }
.tl-chip.cat-dry { background: #fff4e0; color: #8a5a00; }
.tl-chip.cat-cold { background: #e3f2fd; color: #0d47a1; }
.tl-chip.cat-frozen { background: #ede7f6; color: #4527a0; }
.tl-chip-more { margin-left: auto; display: inline-flex; align-items: center; gap: 4px; }
.tl-chip-num { background: #E76F51; color: #fff; border-radius: 8px; padding: 0 5px; font-size: 10px; }

.tl-filters-more { display: flex; align-items: flex-end; gap: 8px; flex-wrap: wrap; flex: 0 0 auto; padding: 8px; margin-bottom: 6px; background: #faf7f3; border-radius: 8px; }
.tl-field { display: flex; flex-direction: column; gap: 2px; font-size: 10px; color: #8b7355; text-transform: uppercase; letter-spacing: 0.03em; }
.tl-field .tl-input { max-width: none; min-width: 120px; }

.tl-vehicles-wrap { flex: 1 1 auto; min-height: 0; overflow-y: auto; }

/* ── Узкий экран: одна колонка, только просмотр ── */
@media (max-width: 900px) {
  /* Высота по содержимому: иначе колонки зажаты в экран и заказы налезают
     на блок «Машины» */
  .tl-page { padding: 10px 12px 12px; height: auto; flex: 0 0 auto; }
  .tl-bar { flex-wrap: wrap; }
  .tl-columns, .tl-columns.is-compact { grid-template-columns: minmax(0, 1fr); grid-template-rows: auto auto; overflow: visible; gap: 10px; }
  .tl-col { min-height: 0; }
  /* Внутренняя прокрутка колонок на телефоне только мешает — едет вся страница */
  .tl-scroll { overflow: visible; }
  .tl-bar-actions { margin-left: 0; }
  .tl-summary { font-size: 11px; gap: 8px; flex-wrap: wrap; }
  .tl-input { font-size: 16px; max-width: none; flex: 1 1 120px; }
}
</style>
