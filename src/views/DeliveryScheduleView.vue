<template>
  <div class="ds-view">
    <!-- Header -->
    <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
      <div>
        <h1 class="page-title" style="margin-bottom:0;">График доставки</h1>
        <div v-if="store.lastUpdate" class="ds-last-update">
          Обновлено: {{ formatLastUpdate(store.lastUpdate) }}<template v-if="store.lastUpdate.by"> — {{ store.lastUpdate.by }}</template>
        </div>
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <!-- Toggle Таблица / По дням -->
        <button class="ds-mode-toggle" :class="{ active: viewMode === 'byDay' }" @click="viewMode = viewMode === 'table' ? 'byDay' : 'table'">
          <span class="ds-toggle-switch"><span class="ds-toggle-knob"></span></span>
          {{ viewMode === 'table' ? 'Таблица' : 'По дням' }}
        </button>
        <div class="pf-filter">
          <select v-model="filterRegion">
            <option value="">Все регионы</option>
            <option v-for="r in store.regions" :key="r" :value="r">{{ r }}</option>
          </select>
        </div>
        <button
          v-if="canEdit"
          class="ds-edit-toggle"
          :class="{ active: editMode }"
          @click="toggleEditMode"
        >
          <BkIcon :name="editMode ? 'close' : 'edit'" size="xs"/>
          {{ editMode ? 'Готово' : 'Редактировать' }}
        </button>
        <button class="btn" @click="exportExcel">Excel</button>
        <button class="btn" @click="printSchedule">Печать</button>
        <button class="btn" @click="openLogModal"><BkIcon name="note" size="xs"/> История</button>
        <input
          v-model="searchQuery"
          type="text"
          class="ds-search"
          placeholder="Поиск по номеру или адресу..."
          style="margin-left:auto;"
        />
      </div>
    </div>

    <!-- Loading -->
    <div v-if="store.loading" class="loading-state" style="text-align:center;padding:40px;">
      <BurgerSpinner text="Загрузка..." />
    </div>

    <!-- ═══ TABLE MODE ═══ -->
    <div v-else-if="viewMode === 'table'" class="pf-wrap">
      <table class="pf-main-table">
        <thead>
          <tr>
            <th class="pf-mth pf-mth-center" style="width:36px;">№</th>
            <th class="pf-mth ds-th-addr">Адрес</th>
            <th class="pf-mth pf-mth-center ds-th-cnt">Дн</th>
            <th v-for="d in dayNames" :key="d.num" class="pf-mth pf-mth-center ds-th-day">
              <span class="ds-day-full">{{ d.full }}</span><span class="ds-day-short">{{ d.short }}</span>
            </th>
            <th class="pf-mth ds-th-note-h">Комментарий</th>
          </tr>
        </thead>
        <template v-for="group in filteredGroups" :key="group.label">
          <tbody>
            <tr class="ds-group-row">
              <td :colspan="colCount">
                <span class="ds-group-name">{{ group.label }}</span>
                <span class="ds-group-count">{{ group.items.length }}</span>
              </td>
            </tr>
            <tr
              v-for="r in group.items"
              :key="r.id"
              class="pf-mrow"
            >
              <td class="pf-mtd pf-mtd-center ds-td-num">{{ r.number }}</td>
              <td class="pf-mtd ds-td-addr" :class="{ 'ds-td-addr-editable': isEditing }" @dblclick="startEditRestaurant(r)">{{ r.address }}</td>
              <td class="pf-mtd pf-mtd-center ds-td-cnt">
                <span class="ds-cnt-badge">{{ deliveryCount(r) }}</span>
              </td>
              <td
                v-for="d in dayNames"
                :key="d.num"
                class="pf-mtd pf-mtd-center ds-td-day"
                :class="{
                  'ds-td-has': getTime(r, d.num),
                  'ds-td-editing': editingCell?.rid === r.id && editingCell?.day === d.num,
                  'ds-td-drop-target': dragState && dragState.rid === r.id && dragState.overDay === d.num && dragState.fromDay !== d.num,
                }"
                @dblclick="isEditing && startEdit(r, d.num)"
                @dragover.prevent="isEditing && onDragOver($event, r, d.num)"
                @dragleave="onDragLeave"
                @drop.prevent="isEditing && onDrop(r, d.num)"
              >
                <template v-if="editingCell?.rid === r.id && editingCell?.day === d.num">
                  <input
                    v-model="editingCell.value"
                    class="ds-cell-input"
                    @blur="saveEdit"
                    @keydown.enter.prevent="saveEdit"
                    @keydown.escape.prevent="cancelEdit"
                    placeholder="10:00-14:00"
                  />
                </template>
                <template v-else>
                  <span
                    v-if="getTime(r, d.num)"
                    class="ds-time-chip"
                    :draggable="isEditing"
                    @dragstart="onDragStart($event, r, d.num)"
                    @dragend="onDragEnd"
                    @pointerdown="onPointerDown($event, r, d.num)"
                    @pointerup="onPointerUp"
                    @pointercancel="onPointerUp"
                  >{{ getTime(r, d.num) }}</span>
                  <span v-else class="ds-time-empty">—</span>
                </template>
              </td>
              <td class="pf-mtd ds-td-note">{{ r.notes || '' }}</td>
            </tr>
          </tbody>
        </template>
        <tfoot>
          <tr class="ds-totals-row">
            <td :colspan="3" class="pf-mtd" style="text-align:right;font-weight:700;font-size:11px;color:var(--text-muted);">
              Доставок:
            </td>
            <td v-for="d in dayNames" :key="d.num" class="pf-mtd pf-mtd-center ds-td-total">
              <span class="ds-total-badge">{{ dayTotal(d.num) }}</span>
            </td>
            <td class="pf-mtd"></td>
          </tr>
        </tfoot>
      </table>
      <div class="ds-table-footer">
        <span>Всего {{ filteredRestaurants.length }} ресторанов</span>
        <span v-if="isEditing" class="ds-hint">2x клик: время / адрес. Перетащите время на другой день</span>
      </div>
    </div>

    <!-- ═══ BY-DAY MODE (columns) ═══ -->
    <div v-else-if="viewMode === 'byDay'" class="ds-byday">
      <div class="ds-columns">
        <div v-for="d in dayNames" :key="d.num" class="ds-col">
          <div class="ds-col-header">
            <span class="ds-col-day">{{ d.short }}</span>
            <span class="ds-col-count">{{ dayRestaurants(d.num).length }}</span>
          </div>
          <div class="ds-col-body">
            <div v-if="!dayRestaurants(d.num).length" class="ds-col-empty">—</div>
            <div
              v-for="item in dayRestaurants(d.num)"
              :key="item.id"
              class="ds-col-item"
              @dblclick="isEditing && startEditRestaurant(item)"
            >
              <span class="ds-col-item-num">{{ item.number }}</span>
              <span class="ds-col-item-addr">{{ item.address }}</span>
              <span
                class="ds-col-item-time"
                :class="{ 'ds-col-item-time-edit': isEditing }"
                @dblclick.stop="isEditing && startCardEdit(item, d.num)"
              >{{ item.delivery_time || '—' }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══ TIME EDIT MODAL (card mode) ═══ -->
    <Teleport to="body">
      <div v-if="cardEditing" class="modal" @click.self="cardEditing = null">
        <div class="modal-box" style="max-width: 360px;">
          <div class="modal-header">
            <h2>Время доставки</h2>
            <button class="modal-close" @click="cardEditing = null"><BkIcon name="close" size="sm"/></button>
          </div>
          <div class="ds-modal-body">
            <div class="ds-edit-info">
              <span class="ds-edit-num">{{ cardEditing.restaurant.number }}</span>
              {{ cardEditing.restaurant.address }}
            </div>
            <div class="ds-edit-day">{{ cardEditing.dayLabel }}</div>
            <label class="ds-label">
              <span class="ds-label-text">Время</span>
              <input
                v-model="cardEditing.value"
                type="text"
                placeholder="10:00-14:00"
                @keydown.enter="saveCardEdit"
                autofocus
              />
            </label>
            <div class="ds-edit-hint">Оставьте пустым, чтобы убрать доставку</div>
          </div>
          <div class="ds-modal-footer">
            <div class="ds-modal-footer-right">
              <button class="btn" @click="cardEditing = null">Отмена</button>
              <button class="btn primary" @click="saveCardEdit">Сохранить</button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ═══ RESTAURANT EDIT MODAL ═══ -->
    <Teleport to="body">
      <div v-if="editingRestaurant" class="modal" @click.self="editingRestaurant = null">
        <div class="modal-box" style="max-width: 440px;">
          <div class="modal-header">
            <h2>Редактирование ресторана</h2>
            <button class="modal-close" @click="editingRestaurant = null"><BkIcon name="close" size="sm"/></button>
          </div>
          <div class="ds-modal-body">
            <label class="ds-label">
              <span class="ds-label-text">Номер</span>
              <input v-model="editingRestaurant.number" type="text" />
            </label>
            <label class="ds-label">
              <span class="ds-label-text">Адрес</span>
              <input v-model="editingRestaurant.address" type="text" />
            </label>
            <div style="display:flex;gap:10px;">
              <label class="ds-label" style="flex:1;">
                <span class="ds-label-text">Город</span>
                <input v-model="editingRestaurant.city" type="text" />
              </label>
              <label class="ds-label" style="flex:1;">
                <span class="ds-label-text">Регион</span>
                <input v-model="editingRestaurant.region" type="text" />
              </label>
            </div>
            <label class="ds-label">
              <span class="ds-label-text">Комментарий</span>
              <input v-model="editingRestaurant.notes" type="text" />
            </label>
          </div>
          <div class="ds-modal-footer">
            <div class="ds-modal-footer-right">
              <button class="btn" @click="editingRestaurant = null">Отмена</button>
              <button class="btn primary" @click="saveRestaurantEdit">Сохранить</button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ═══ AUDIT LOG MODAL ═══ -->
    <AuditLogModal
      :show="showLogModal"
      :loading="logLoading"
      :entries="logEntries"
      @close="showLogModal = false"
    />
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';
import { useRestaurantStore } from '@/stores/restaurantStore.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import AuditLogModal from '@/components/modals/AuditLogModal.vue';
import { db } from '@/lib/apiClient.js';
import { exportScheduleToExcel } from '@/lib/excelExport.js';

const store = useRestaurantStore();
const orderStore = useOrderStore();
const userStore = useUserStore();
const toastStore = useToastStore();

const viewMode = ref('table');
const selectedDay = ref(1);
const filterRegion = ref('');
const searchQuery = ref('');

const canEdit = computed(() => true);
const editMode = ref(false);
const isEditing = computed(() => canEdit.value && editMode.value);

function toggleEditMode() {
  if (editMode.value) {
    // Выходим из режима — отменяем все текущие правки
    editingCell.value = null;
    editingRestaurant.value = null;
    cardEditing.value = null;
    dragState.value = null;
  }
  editMode.value = !editMode.value;
}

const dayNames = [
  { num: 1, short: 'ПН', full: 'Понедельник' },
  { num: 2, short: 'ВТ', full: 'Вторник' },
  { num: 3, short: 'СР', full: 'Среда' },
  { num: 4, short: 'ЧТ', full: 'Четверг' },
  { num: 5, short: 'ПТ', full: 'Пятница' },
  { num: 6, short: 'СБ', full: 'Суббота' },
];

const colCount = computed(() => 3 + dayNames.length + 1); // №, Адрес, Дн, 6 дней, Комментарий

function onKey(e) {
  if (e.key === 'Escape') {
    if (cardEditing.value) { cardEditing.value = null; return; }
    if (editingRestaurant.value) { editingRestaurant.value = null; return; }
  }
}

onMounted(() => {
  store.load(orderStore.settings.legalEntity);
  document.addEventListener('keydown', onKey);
});

onUnmounted(() => {
  document.removeEventListener('keydown', onKey);
  clearTimeout(longPressTimer);
});

watch(() => orderStore.settings.legalEntity, (le) => {
  store.invalidate();
  store.load(le);
});

// ═══ Filtering ═══
const filteredRestaurants = computed(() => {
  let list = store.restaurants;
  if (filterRegion.value) {
    list = list.filter(r => r.region === filterRegion.value);
  }
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase();
    list = list.filter(r =>
      String(r.number).includes(q) ||
      (r.address || '').toLowerCase().includes(q)
    );
  }
  return list;
});

const filteredGroups = computed(() => {
  const groups = [];
  const minsk = filteredRestaurants.value.filter(r => r.region === 'Минск');
  const regions = filteredRestaurants.value.filter(r => r.region !== 'Минск');
  if (minsk.length) groups.push({ label: 'Минск', items: minsk });
  if (regions.length) groups.push({ label: 'Регионы', items: regions });
  return groups;
});

function getTime(restaurant, day) {
  const rSched = store.scheduleByRestaurant.get(String(restaurant.id));
  if (!rSched) return '';
  const s = rSched.get(day);
  return s?.delivery_time || '';
}

function deliveryCount(restaurant) {
  const rSched = store.scheduleByRestaurant.get(String(restaurant.id));
  return rSched ? rSched.size : 0;
}

function dayTotal(day) {
  let count = 0;
  for (const r of filteredRestaurants.value) {
    if (getTime(r, day)) count++;
  }
  return count;
}

function dayRestaurants(day) {
  const all = store.restaurantsByDay.get(day) || [];
  let list = all;
  if (filterRegion.value) {
    list = list.filter(r => r.region === filterRegion.value);
  }
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase();
    list = list.filter(r =>
      String(r.number).includes(q) ||
      (r.address || '').toLowerCase().includes(q)
    );
  }
  return list;
}

// ═══ Inline edit (table — dblclick) ═══
const editingCell = ref(null);
let savingEdit = false;

function startEdit(restaurant, day) {
  if (!isEditing.value) return;
  editingCell.value = {
    rid: restaurant.id,
    day,
    value: getTime(restaurant, day),
    restaurantId: restaurant.id,
  };
  nextTick(() => {
    const inp = document.querySelector('.ds-cell-input');
    if (inp) { inp.focus(); inp.select(); }
  });
}

async function saveEdit() {
  if (!editingCell.value || savingEdit) return;
  savingEdit = true;
  const { restaurantId, day, value } = editingCell.value;
  const oldValue = getTime({ id: restaurantId }, day);
  editingCell.value = null;
  if (value === oldValue) { savingEdit = false; return; }
  try {
    await store.saveScheduleCell(restaurantId, day, value);
  } catch (e) {
    toastStore.error('Ошибка сохранения');
  } finally {
    savingEdit = false;
  }
}

function cancelEdit() {
  editingCell.value = null;
}

// ═══ Drag & drop (move delivery between days) ═══
const dragState = ref(null);
const dragging = ref(false);
let longPressTimer = null;

function onPointerDown(e, restaurant, day) {
  if (!isEditing.value) return;
  longPressTimer = setTimeout(() => {
    // Визуальная обратная связь — элемент станет draggable
    const el = e.target;
    if (el) el.classList.add('ds-chip-ready');
  }, 300);
}

function onPointerUp() {
  clearTimeout(longPressTimer);
}

function onDragStart(e, restaurant, day) {
  if (!isEditing.value) { e.preventDefault(); return; }
  clearTimeout(longPressTimer);
  const time = getTime(restaurant, day);
  dragState.value = { rid: restaurant.id, fromDay: day, time, overDay: null };
  e.dataTransfer.effectAllowed = 'move';
  e.dataTransfer.setData('text/plain', time);
  e.target.classList.add('ds-chip-dragging');
}

function onDragOver(e, restaurant, day) {
  if (!dragState.value || dragState.value.rid !== restaurant.id) return;
  e.dataTransfer.dropEffect = 'move';
  dragState.value.overDay = day;
}

function onDragLeave() {
  if (dragState.value) dragState.value.overDay = null;
}

async function onDrop(restaurant, toDay) {
  if (!dragState.value || dragState.value.rid !== restaurant.id || dragging.value) return;
  const { fromDay, time } = dragState.value;
  dragState.value = null;
  if (fromDay === toDay || !time) return;

  dragging.value = true;
  try {
    await store.saveScheduleCell(restaurant.id, toDay, time);
    await store.saveScheduleCell(restaurant.id, fromDay, '');
    toastStore.success(`${dayNames.find(d => d.num === fromDay)?.short} → ${dayNames.find(d => d.num === toDay)?.short}`);
  } catch (e) {
    console.error('Drag move error:', e);
    toastStore.error('Ошибка переноса');
    store.invalidate();
    store.load(orderStore.settings.legalEntity);
  } finally {
    dragging.value = false;
  }
}

function onDragEnd(e) {
  e.target.classList.remove('ds-chip-dragging', 'ds-chip-ready');
  dragState.value = null;
}

// ═══ Restaurant edit (dblclick on address/card) ═══
const editingRestaurant = ref(null);

function startEditRestaurant(restaurant) {
  if (!isEditing.value) return;
  editingRestaurant.value = { ...restaurant };
}

async function saveRestaurantEdit() {
  if (!editingRestaurant.value) return;
  try {
    await store.saveRestaurant(editingRestaurant.value);
    editingRestaurant.value = null;
    toastStore.success('Ресторан сохранён');
  } catch (e) {
    toastStore.error('Ошибка сохранения');
  }
}

// ═══ Card edit (by-day mode — dblclick) ═══
const cardEditing = ref(null);

function startCardEdit(item, dayNum) {
  if (!isEditing.value) return;
  const day = dayNum || selectedDay.value;
  const d = dayNames.find(d => d.num === day);
  cardEditing.value = {
    restaurant: item,
    day,
    dayLabel: d?.full || '',
    value: item.delivery_time || '',
  };
}

async function saveCardEdit() {
  if (!cardEditing.value) return;
  const { restaurant, day, value } = cardEditing.value;
  cardEditing.value = null;
  try {
    await store.saveScheduleCell(restaurant.id, day, value);
    store.invalidate();
    store.load(orderStore.settings.legalEntity);
  } catch (e) {
    toastStore.error('Ошибка сохранения');
  }
}

async function exportExcel() {
  try {
    await exportScheduleToExcel(filteredRestaurants.value, store.scheduleByRestaurant, store.lastUpdate);
  } catch (e) {
    console.error('Export error:', e);
    toastStore.error('Ошибка экспорта в Excel');
  }
}

function printSchedule() {
  window.print();
}

// ═══ Audit log modal ═══
const showLogModal = ref(false);
const logLoading = ref(false);
const logEntries = ref([]);

async function openLogModal() {
  showLogModal.value = true;
  logLoading.value = true;
  logEntries.value = [];
  try {
    const { data, error } = await db.from('audit_log')
      .select('*')
      .eq('entity_type', 'delivery_schedule')
      .order('created_at', { ascending: false })
      .limit(100);
    if (error) throw new Error(error);
    logEntries.value = (data || []).map(row => ({
      ...row,
      details: typeof row.details === 'string' ? JSON.parse(row.details) : row.details,
    }));
  } catch (e) {
    console.error('Failed to load audit log:', e);
    toastStore.error('Не удалось загрузить историю');
  } finally {
    logLoading.value = false;
  }
}

function formatLastUpdate(upd) {
  if (!upd?.at) return '';
  const d = new Date(upd.at);
  if (isNaN(d)) return '';
  const pad = n => String(n).padStart(2, '0');
  return `${pad(d.getDate())}.${pad(d.getMonth()+1)}.${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
</script>

<style scoped>
.ds-view { padding-bottom: 40px; }

/* ═══ Toggle Таблица / По дням ═══ */
.ds-mode-toggle {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 5px 12px;
  border-radius: 8px;
  border: 1.5px solid var(--border);
  background: white;
  font-size: 12px;
  font-weight: 600;
  font-family: inherit;
  color: var(--text-muted);
  cursor: pointer;
  transition: all 0.15s;
  white-space: nowrap;
}
.ds-mode-toggle:hover { border-color: var(--bk-orange); color: var(--text); }
.ds-mode-toggle.active { border-color: var(--bk-orange); color: var(--bk-brown); background: #FFFBF5; }
.ds-toggle-switch {
  position: relative;
  width: 30px;
  height: 16px;
  border-radius: 8px;
  background: var(--border);
  transition: background 0.2s;
  flex-shrink: 0;
}
.ds-mode-toggle.active .ds-toggle-switch { background: var(--bk-orange); }
.ds-toggle-knob {
  position: absolute;
  top: 2px;
  left: 2px;
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background: white;
  box-shadow: 0 1px 3px rgba(0,0,0,0.15);
  transition: left 0.2s;
}
.ds-mode-toggle.active .ds-toggle-knob { left: 16px; }

/* ═══ Edit mode toggle ═══ */
.ds-edit-toggle {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 12px;
  border-radius: 8px;
  border: 1.5px solid var(--border);
  background: white;
  font-size: 12px;
  font-weight: 600;
  font-family: inherit;
  color: var(--text-muted);
  cursor: pointer;
  transition: all 0.15s;
  white-space: nowrap;
}
.ds-edit-toggle:hover {
  border-color: var(--bk-orange);
  color: var(--text);
}
.ds-edit-toggle.active {
  border-color: var(--bk-orange);
  background: #FFF3E0;
  color: var(--bk-brown);
  box-shadow: 0 0 0 2px rgba(255, 135, 50, 0.15);
}

/* ═══ Header buttons — unified size ═══ */
.page-header .btn {
  padding: 5px 12px;
  font-size: 12px;
  font-weight: 600;
  border: 1.5px solid var(--border);
  border-radius: 8px;
  background: white;
  color: var(--text-muted);
  white-space: nowrap;
}
.page-header .btn:hover {
  border-color: var(--bk-orange);
  color: var(--text);
  background: white;
  transform: none;
}
.page-header :deep(.pf-filter select) {
  padding: 5px 10px;
  font-size: 12px;
  font-weight: 600;
  border: 1.5px solid var(--border);
  border-radius: 8px;
  background: white;
}

/* ═══ Search ═══ */
.ds-search {
  padding: 5px 10px; border: 1.5px solid var(--border); border-radius: 8px;
  font-size: 12px; font-weight: 600; font-family: inherit; color: var(--text);
  width: 200px; background: white;
  transition: border-color 0.15s;
}
.ds-search:focus { outline: none; border-color: var(--bk-orange); }
.ds-search::placeholder { color: var(--text-muted); font-weight: 500; }

/* ═══ Table — compact ═══ */
.ds-view :deep(.pf-wrap) {
  border: 1px solid var(--border);
  box-shadow: 0 1px 4px rgba(80, 35, 20, 0.05);
}
.ds-view :deep(.pf-main-table) {
  font-size: 12px;
  border-collapse: collapse;
}
.ds-view :deep(.pf-mth) {
  background: var(--bk-brown);
  color: rgba(255,255,255,0.85);
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.3px;
  border: 1px solid rgba(255,255,255,0.15);
  border-bottom: 2px solid rgba(0,0,0,0.2);
  padding: 5px 6px;
  white-space: nowrap;
}
.ds-view :deep(.pf-mrow) {
  cursor: default;
}
.ds-view :deep(.pf-mrow:nth-child(even)) {
  background: rgba(245, 243, 239, 0.5);
}
.ds-view :deep(.pf-mrow:hover) {
  background: #FFF8F0;
}
.ds-view :deep(.pf-mtd) {
  padding: 3px 6px;
  border: 1px solid var(--border);
  line-height: 1.3;
}

/* ═══ Column widths ═══ */
.ds-th-addr { width: 1%; white-space: nowrap; padding-right: 8px !important; }
.ds-th-cnt { width: 26px; }
.ds-th-day { white-space: nowrap; }
.ds-day-short { display: none; }
.ds-day-full { display: inline; }

/* ═══ Group row ═══ */
.ds-group-row td {
  background: #F0EBE5;
  padding: 4px 8px;
  font-size: 11px; font-weight: 800;
  text-transform: uppercase; letter-spacing: 0.6px;
  color: var(--bk-brown);
  border-bottom: 1px solid var(--border);
  border-top: 1px solid var(--border);
}
.ds-group-name { margin-right: 6px; }
.ds-group-count {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 18px; height: 16px; border-radius: 8px;
  background: var(--bk-brown); color: #fff;
  font-size: 10px; font-weight: 700; padding: 0 5px;
}

/* ═══ Table cells ═══ */
.ds-td-num {
  font-weight: 800; color: var(--bk-red);
  font-size: 13px;
  font-family: 'Plus Jakarta Sans', sans-serif;
}
.ds-td-addr {
  text-align: left;
  font-weight: 600; color: var(--bk-brown); font-size: 12px;
  width: 1%;
  white-space: nowrap;
  padding-right: 8px !important;
}
.ds-td-addr-editable { cursor: pointer; }
.ds-th-note-h { }
.ds-td-cnt { padding: 3px 4px !important; vertical-align: middle; text-align: center; }
.ds-cnt-badge {
  display: inline-flex; align-items: center; justify-content: center;
  width: 16px; height: 16px; border-radius: 50%;
  background: var(--bk-brown); color: #fff;
  font-weight: 800; font-size: 9px;
  vertical-align: middle;
  line-height: 1;
}

/* ═══ Day cells ═══ */
.ds-td-day {
  transition: background 0.1s; font-size: 11px; cursor: default;
  white-space: nowrap; position: relative;
}
.ds-td-has { }
.ds-td-has:hover { background: #F5F5F0; }
.ds-time-chip {
  display: inline-block;
  font-weight: 700; color: #1B5E20; font-size: 11px;
  background: #A5D6A7;
  padding: 2px 6px;
  border-radius: 3px;
  letter-spacing: -0.2px;
  user-select: none;
  touch-action: none;
}
.ds-time-chip[draggable="true"] {
  cursor: grab;
}
.ds-time-chip[draggable="true"]:hover {
  background: #81C784;
}
.ds-time-chip.ds-chip-ready {
  box-shadow: 0 0 0 2px var(--bk-orange);
}
.ds-time-chip.ds-chip-dragging {
  opacity: 0.4;
  cursor: grabbing;
}
.ds-time-empty { color: #D5D0CA; font-size: 10px; }

/* Drop target */
.ds-td-drop-target {
  background: #FFF3E0 !important;
  box-shadow: inset 0 0 0 2px var(--bk-orange);
}

.ds-td-editing { padding: 1px 2px; background: #FFF3E0 !important; }
.ds-cell-input {
  width: 100%; padding: 3px 4px;
  border: 2px solid var(--bk-orange);
  border-radius: 3px; font-size: 11px; text-align: center;
  outline: none; background: #fff; font-family: inherit; font-weight: 700;
  color: var(--text);
  box-shadow: 0 0 0 2px rgba(255, 135, 50, 0.12);
}
.ds-cell-input::placeholder { color: var(--text-muted); font-weight: 400; }

.ds-td-note { text-align: left; color: var(--text-secondary); font-size: 11px; max-width: 120px; }

/* ═══ Totals row ═══ */
.ds-totals-row td {
  border-top: 2px solid var(--bk-brown); background: var(--bk-brown);
  padding: 4px 6px;
}
.ds-totals-row td[style] {
  color: rgba(255,255,255,0.8) !important;
}
.ds-td-total { }
.ds-total-badge {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 22px; height: 20px; border-radius: 4px;
  background: var(--bk-orange); color: #fff;
  font-weight: 800; font-size: 12px; padding: 0 6px;
  box-shadow: 0 1px 3px rgba(255,135,50,0.25);
}

/* ═══ Table footer ═══ */
.ds-table-footer {
  display: flex; align-items: center; justify-content: space-between;
  padding: 6px 10px; border-top: 1px solid var(--border-light);
  font-size: 11px; color: var(--text-muted); font-weight: 600;
}
.ds-last-update {
  font-size: 10px; color: var(--text-muted); opacity: 0.8;
}
.ds-hint { font-size: 10px; color: var(--text-muted); opacity: 0.7; }

/* ═══ BY-DAY MODE (columns) ═══ */
.ds-columns {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 6px;
  align-items: start;
}
.ds-col {
  background: var(--card, #fff);
  border: 1px solid var(--border);
  border-radius: 6px;
  overflow: hidden;
  min-width: 0;
}
.ds-col-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 4px 6px;
  background: var(--bk-brown);
  color: #fff;
  font-weight: 700; font-size: 11px;
  letter-spacing: 0.3px;
}
.ds-col-count {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 16px; height: 15px; border-radius: 8px;
  background: var(--bk-orange); color: #fff;
  font-size: 9px; font-weight: 800; padding: 0 4px;
}
.ds-col-body {
  padding: 3px;
  display: flex; flex-direction: column; gap: 1px;
}
.ds-col-empty {
  text-align: center; padding: 10px 0;
  color: var(--text-muted); font-size: 11px; opacity: 0.5;
}

.ds-col-item {
  padding: 2px 4px;
  border-bottom: 1px solid var(--border-light, #f0ebe5);
  transition: background 0.1s;
  display: flex; align-items: center; gap: 4px;
}
.ds-col-item:last-child { border-bottom: none; }
.ds-col-item:hover { background: #FFF8F0; }
.ds-col-item-num {
  font-weight: 800; font-size: 11px; color: var(--bk-red);
  font-family: 'Plus Jakarta Sans', sans-serif;
  line-height: 1;
  flex-shrink: 0;
  min-width: 28px;
  text-align: center;
  border-right: 1px solid var(--border-light, #e8e3dd);
  padding-right: 4px;
  margin-right: 2px;
}
.ds-col-item-addr {
  font-size: 10px; font-weight: 500; color: var(--text-secondary);
  line-height: 1.2;
  flex: 1; min-width: 0;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ds-col-item-time {
  display: inline-block;
  background: #A5D6A7; color: #1B5E20;
  padding: 0 4px; border-radius: 2px;
  font-weight: 700; font-size: 9px;
  white-space: nowrap;
  line-height: 1.5;
  flex-shrink: 0;
}
.ds-col-item-time-edit { cursor: pointer; }
.ds-col-item-time-edit:hover { background: #81C784; }

/* ═══ Modal ═══ */
.ds-modal-body { padding: 0 20px 16px; display: flex; flex-direction: column; gap: 12px; }
.ds-label { display: flex; flex-direction: column; gap: 4px; }
.ds-label-text { font-size: 12px; font-weight: 600; color: var(--text-secondary); }
.ds-label input {
  padding: 8px 10px; border: 1.5px solid var(--border); border-radius: var(--radius-sm);
  font-size: 14px; font-family: inherit; color: var(--text); background: white;
  transition: border-color 0.15s;
}
.ds-label input:focus {
  outline: none; border-color: var(--bk-orange);
  box-shadow: 0 0 0 2px rgba(255, 135, 50, 0.12);
}
.ds-label input::placeholder { color: var(--text-muted); }

.ds-modal-footer {
  display: flex; align-items: center; justify-content: space-between;
  padding: 12px 20px 16px; gap: 8px;
}
.ds-modal-footer-right { display: flex; gap: 8px; margin-left: auto; }

/* ═══ Time edit modal ═══ */
.ds-edit-info {
  font-size: 14px; font-weight: 600; color: var(--bk-brown);
  line-height: 1.4; margin-bottom: 4px;
}
.ds-edit-num {
  display: inline-flex; align-items: center; justify-content: center;
  width: 28px; height: 28px; border-radius: 50%;
  background: var(--bk-red); color: white;
  font-weight: 800; font-size: 13px; margin-right: 6px;
  vertical-align: middle;
}
.ds-edit-day {
  font-size: 12px; color: var(--text-muted); font-weight: 600;
  margin-bottom: 12px;
}
.ds-edit-hint {
  font-size: 11px; color: var(--text-muted); margin-top: 4px;
}

/* ═══ Print ═══ */
@media print {
  @page { size: landscape; margin: 6mm; }

  /* Скрываем UI-элементы */
  .ds-search, .ds-mode-toggle, .ds-edit-toggle, .pf-filter, .btn,
  .ds-table-footer, .ds-hint,
  .sidebar, .topbar, .topbar-mobile-only,
  .sidebar-overlay { display: none !important; }
  .app-layout .main-wrapper { all: unset; display: block; }

  /* Показываем заголовок */
  .page-header {
    display: flex !important; margin-bottom: 4px !important; padding: 0 !important;
  }
  .page-title { font-size: 14px !important; margin: 0 !important; }
  .ds-last-update { font-size: 11px !important; opacity: 1 !important; color: #333 !important; }

  .ds-view { padding: 0 !important; }
  .pf-wrap {
    border: none !important; border-radius: 0 !important;
    overflow: visible !important; box-shadow: none !important;
  }
  .pf-main-table {
    font-size: 13px !important; border-collapse: collapse !important;
    width: 100% !important;
  }

  /* Заголовки — как на сайте (коричневые) */
  .pf-mth {
    background: #502314 !important; color: rgba(255,255,255,0.9) !important;
    padding: 3px 5px !important; font-size: 12px !important;
    border: 1.5px solid rgba(255,255,255,0.2) !important;
    white-space: nowrap !important;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }

  /* Все ячейки — чёткие границы */
  .pf-mtd {
    padding: 2px 4px !important; line-height: 1.3 !important;
    border: 1.5px solid #999 !important;
    white-space: nowrap !important;
  }

  /* Чередование строк */
  .pf-mrow:nth-child(even) {
    background: rgba(245, 243, 239, 0.5) !important;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }

  /* Номер ресторана */
  .ds-td-num {
    font-size: 14px !important; font-weight: 600 !important;
    color: #000 !important;
    font-family: 'Plus Jakarta Sans', sans-serif !important;
  }

  /* Адрес */
  .ds-th-addr, .ds-td-addr {
    white-space: nowrap !important;
  }
  .ds-td-addr {
    font-size: 13px !important; font-weight: 600 !important;
    color: #502314 !important;
  }

  /* Комментарий */
  .ds-th-note-h, .ds-td-note { font-size: 11px !important; }

  /* Бейдж кол-ва дней — просто число */
  .ds-cnt-badge {
    width: auto !important; height: auto !important; font-size: 12px !important;
    background: none !important; color: #000 !important;
    border: none !important; border-radius: 0 !important;
    font-weight: 900 !important;
  }

  /* Время доставки */
  .ds-time-chip {
    font-size: 12px !important; font-weight: 700 !important;
    background: #A5D6A7 !important; color: #1B5E20 !important;
    padding: 1px 4px !important; border-radius: 2px !important;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }
  .ds-time-empty { font-size: 11px !important; color: #D5D0CA !important; }

  /* Группы (Минск / Регионы) — бежевый как на сайте */
  .ds-group-row td {
    background: #F0EBE5 !important; padding: 3px 5px !important;
    font-size: 12px !important; font-weight: 800 !important;
    color: #502314 !important;
    border: 1.5px solid #999 !important;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }
  .ds-group-count {
    width: auto !important; height: auto !important;
    font-size: 12px !important; min-width: auto !important;
    background: none !important; color: #502314 !important;
    border: none !important; border-radius: 0 !important;
    font-weight: 900 !important; padding: 0 !important;
  }

  /* Итого — коричневый как на сайте */
  .ds-totals-row td {
    background: #502314 !important; padding: 3px 4px !important;
    border: 1px solid #502314 !important;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }
  .ds-totals-row td[style] {
    color: rgba(255,255,255,0.8) !important;
  }
  .ds-total-badge {
    background: none !important; color: #fff !important;
    min-width: auto !important; height: auto !important; font-size: 14px !important;
    font-weight: 900 !important;
    padding: 0 !important;
    border: none !important; border-radius: 0 !important;
    box-shadow: none !important;
  }

  /* ═══ Print: By-day columns ═══ */
  .ds-byday { break-inside: avoid; }
  .ds-columns {
    grid-template-columns: repeat(3, 1fr) !important;
    gap: 4px !important;
  }
  .ds-col {
    border: 1px solid #aaa !important;
    border-radius: 0 !important;
    break-inside: avoid;
  }
  .ds-col-header {
    background: #502314 !important; color: #fff !important;
    padding: 5px 8px !important; font-size: 13px !important;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }
  .ds-col-count {
    background: none !important; color: #fff !important;
    min-width: auto !important; height: auto !important; font-size: 14px !important;
    font-weight: 900 !important;
    border: none !important; border-radius: 0 !important;
  }
  .ds-col-body { padding: 2px 4px !important; gap: 0 !important; }
  .ds-col-item {
    padding: 3px 4px !important; border-color: #ddd !important;
    gap: 5px !important;
  }
  .ds-col-item-num {
    font-size: 15px !important; font-weight: 600 !important; color: #000 !important;
    font-family: 'Plus Jakarta Sans', sans-serif !important;
  }
  .ds-col-item-addr {
    font-size: 13px !important; color: #333 !important; font-weight: 600 !important;
  }
  .ds-col-item-time {
    font-size: 13px !important; padding: 1px 5px !important;
    background: #A5D6A7 !important; color: #1B5E20 !important;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }
  .ds-col-empty { padding: 8px 0 !important; font-size: 12px !important; }
}

/* ═══ Mobile ═══ */
@media (max-width: 768px) {
  .ds-view { padding-bottom: 20px; }

  /* Хедер — вертикально */
  .ds-view .page-header {
    flex-direction: column !important;
    align-items: stretch !important;
    gap: 8px !important;
  }
  .ds-view .page-header > div:last-child {
    display: grid !important;
    grid-template-columns: 1fr 1fr;
    gap: 6px !important;
  }
  .ds-search {
    width: 100% !important;
    grid-column: 1 / -1;
  }
  .ds-mode-toggle { justify-content: center; }

  /* Таблица — горизонтальный скролл */
  .pf-wrap { overflow-x: auto !important; -webkit-overflow-scrolling: touch; }
  .pf-main-table { min-width: 600px; font-size: 11px !important; }

  /* Скрываем комментарий и кол-во дней */
  .ds-th-note-h, .ds-td-note, .ds-th-cnt, .ds-td-cnt { display: none; }

  /* Короткие дни в таблице */
  .ds-day-full { display: none; }
  .ds-day-short { display: inline; }

  /* Компактнее ячейки */
  .ds-td-addr { font-size: 11px !important; }

  /* Колонки по дням — 2 на планшете */
  .ds-columns {
    grid-template-columns: repeat(2, 1fr) !important;
    gap: 4px;
  }

  /* Модалки — на всю ширину */
  .modal-box { max-width: calc(100vw - 24px) !important; margin: 12px; }

  /* Группы — компактнее */
  .ds-group-row td { font-size: 10px; padding: 3px 6px; }
}

/* Совсем маленькие экраны */
@media (max-width: 480px) {
  .ds-view .page-header > div:last-child {
    grid-template-columns: 1fr;
  }
  .ds-mode-toggle { order: -1; }
  .pf-main-table { min-width: 500px; }
  .ds-columns { grid-template-columns: 1fr !important; }
}
</style>
