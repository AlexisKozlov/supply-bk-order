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
              Нераспределённые <span class="tl-section-count">{{ store.unassignedItems.length }}</span>
            </div>

            <div v-if="!store.unassignedItems.length" class="tl-empty-section">
              Все заказы распределены
            </div>

            <!-- groupBy = restaurant -->
            <template v-if="store.groupBy === 'restaurant'">
              <div v-for="item in store.unassignedItems" :key="item.key"
                class="tl-card" draggable="true" @dragstart="onDragStart($event, item)">
                <div class="tl-card-header">
                  <span class="tl-card-num">{{ item.restaurant_number }}</span>
                  <span class="tl-card-city">{{ item.city }}</span>
                </div>
                <div class="tl-card-stats">
                  <span v-for="(data, cat) in item.categories" :key="cat"
                    class="tl-cat-badge" :class="'cat-' + catClass(cat)">
                    {{ cat }}: {{ data.pallets }}п / {{ (+data.weight).toFixed(0) }}кг
                  </span>
                </div>
                <div class="tl-card-total">{{ (+item.pallets).toFixed(1) }} палл. | {{ (+item.weight_kg).toFixed(0) }} кг</div>
              </div>
            </template>

            <!-- groupBy = category -->
            <template v-else-if="store.groupBy === 'category'">
              <div v-for="item in store.unassignedItems" :key="item.key"
                class="tl-card" draggable="true" @dragstart="onDragStart($event, item)">
                <div class="tl-card-header">
                  <span class="tl-card-num">{{ item.restaurant_number }}</span>
                  <span class="tl-cat-badge" :class="'cat-' + catClass(item.category)">{{ item.category }}</span>
                </div>
                <div class="tl-card-total">{{ (+item.pallets).toFixed(1) }} палл. | {{ (+item.weight_kg).toFixed(0) }} кг</div>
              </div>
            </template>

            <!-- groupBy = item -->
            <template v-else>
              <div v-for="item in store.unassignedItems" :key="item.key"
                class="tl-card" draggable="true" @dragstart="onDragStart($event, item)">
                <div class="tl-card-header">
                  <span class="tl-card-num">{{ item.restaurant_number }}</span>
                  <span class="tl-card-sku">{{ item.sku }} {{ item.product_name }}</span>
                </div>
                <div class="tl-card-total">{{ item.quantity }} шт. | {{ (+item.pallets).toFixed(1) }} палл.</div>
              </div>
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
                  <span class="tl-assigned-rest">{{ a.restaurant_number }}</span>
                  <span class="tl-assigned-cat" v-if="a.category" :class="'cat-' + catClass(a.category)">{{ a.category }}</span>
                  <span class="tl-assigned-stats">{{ (+a.pallets).toFixed(1) }}п | {{ (+a.weight_kg).toFixed(0) }}кг</span>
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
import { ref, onMounted } from 'vue';
import { useTruckLoadingStore } from '@/stores/truckLoadingStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { exportTruckLoading } from '@/lib/truckLoadingExport.js';

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
  padding: 20px;
  max-width: 1400px;
  margin: 0 auto;
}

/* Toolbar */
.tl-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
  flex-wrap: wrap;
  gap: 12px;
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

/* Columns layout */
.tl-columns {
  display: flex;
  gap: 20px;
  align-items: flex-start;
}

.tl-left {
  flex: 0 0 40%;
  min-height: 200px;
}

.tl-right {
  flex: 1;
  min-height: 200px;
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
