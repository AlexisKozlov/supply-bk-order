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
            <th v-if="isPSGroup" class="pf-mth pf-mth-center" style="width:48px;" title="Номер в системе 1С Додо">ИС</th>
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
                <span class="ds-group-avg">(сред. {{ group.avg }} дост./нед.)</span>
              </td>
            </tr>
            <tr
              v-for="r in group.items"
              :key="r.id"
              class="pf-mrow"
            >
              <td class="pf-mtd pf-mtd-center ds-td-num">{{ displayNumber(r) }}</td>
              <td v-if="isPSGroup" class="pf-mtd pf-mtd-center ds-td-num">{{ r.dodo_is_number || '—' }}</td>
              <td class="pf-mtd ds-td-addr" :class="{ 'ds-td-addr-editable': isEditing }" @dblclick="startEditRestaurant(r)">{{ r.address }}</td>
              <td class="pf-mtd pf-mtd-center ds-td-cnt">
                <span class="ds-cnt-badge">{{ deliveryCount(r) }}</span>
              </td>
              <td
                v-for="d in dayNames"
                :key="d.num"
                class="pf-mtd pf-mtd-center ds-td-day"
                :class="{
                  'ds-td-has': getTime(r, d.num) || getDough(r, d.num),
                  'ds-td-editing': editingCell?.rid === r.id && editingCell?.day === d.num,
                  'ds-td-drop-target': dragState && dragState.rid === r.id && dragState.overDay === d.num && dragState.fromDay !== d.num,
                  'ds-td-ps': isPSGroup,
                }"
                @dblclick="isEditing && startEdit(r, d.num, 'delivery_time')"
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
                <template v-else-if="isPSGroup">
                  <!-- ПС: два чипа в ряд — основная доставка и тесто -->
                  <div class="ds-ps-cell">
                    <span
                      v-if="getTime(r, d.num)"
                      class="ds-time-chip ds-chip-main"
                      :draggable="isEditing"
                      @dragstart="onDragStart($event, r, d.num, 'delivery_time')"
                      @dragend="onDragEnd"
                      @dblclick.stop="isEditing && startEdit(r, d.num, 'delivery_time')"
                    >{{ getTime(r, d.num) }}</span>
                    <span v-else class="ds-time-empty" @dblclick.stop="isEditing && startEdit(r, d.num, 'delivery_time')">—</span>
                    <span
                      v-if="getDough(r, d.num)"
                      class="ds-time-chip ds-chip-dough"
                      :draggable="isEditing"
                      @dragstart="onDragStart($event, r, d.num, 'dough_time')"
                      @dragend="onDragEnd"
                      title="Тесто"
                      @dblclick.stop="isEditing && startEdit(r, d.num, 'dough_time')"
                    >{{ getDough(r, d.num) }}</span>
                    <span v-else class="ds-time-empty ds-dough-empty" title="Тесто" @dblclick.stop="isEditing && startEdit(r, d.num, 'dough_time')">—</span>
                  </div>
                  <button
                    v-if="getTime(r, d.num)"
                    class="ds-deadline-btn"
                    :class="{ 'ds-deadline-btn-set': getDeadline(r, d.num), 'ds-deadline-btn-empty': isEditing && !getDeadline(r, d.num) }"
                    :title="getDeadline(r, d.num) ? `Заявка: ${DAY_SHORT_LOCAL[getDeadline(r, d.num).order_day]} до ${getDeadline(r, d.num).order_deadline}` : (isEditing ? 'Задать дедлайн заявки' : 'Дедлайн заявки не задан')"
                    :disabled="!isEditing"
                    @click.stop="startDeadlineEdit(r, d.num)"
                  >
                    <span v-if="getDeadline(r, d.num)" class="ds-deadline-text">{{ DAY_SHORT_LOCAL[getDeadline(r, d.num).order_day] }} {{ getDeadline(r, d.num).order_deadline }}</span>
                    <span v-else>+ заявка</span>
                  </button>
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
                  <button
                    v-if="getTime(r, d.num)"
                    class="ds-deadline-btn"
                    :class="{ 'ds-deadline-btn-set': getDeadline(r, d.num), 'ds-deadline-btn-empty': isEditing && !getDeadline(r, d.num) }"
                    :title="getDeadline(r, d.num) ? `Заявка: ${DAY_SHORT_LOCAL[getDeadline(r, d.num).order_day]} до ${getDeadline(r, d.num).order_deadline}` : (isEditing ? 'Задать дедлайн заявки' : 'Дедлайн заявки не задан')"
                    :disabled="!isEditing"
                    @click.stop="startDeadlineEdit(r, d.num)"
                  >
                    <span v-if="getDeadline(r, d.num)" class="ds-deadline-text">{{ DAY_SHORT_LOCAL[getDeadline(r, d.num).order_day] }} {{ getDeadline(r, d.num).order_deadline }}</span>
                    <span v-else>+ заявка</span>
                  </button>
                </template>
              </td>
              <td
                class="pf-mtd ds-td-note"
                :class="{ 'ds-td-note-editable': isEditing, 'ds-td-editing': editingNote?.rid === r.id }"
                @dblclick="isEditing && startNoteEdit(r)"
              >
                <template v-if="editingNote?.rid === r.id">
                  <input
                    v-model="editingNote.value"
                    class="ds-note-input"
                    @blur="saveNoteEdit"
                    @keydown.enter.prevent="saveNoteEdit"
                    @keydown.escape.prevent="cancelNoteEdit"
                    placeholder="Комментарий..."
                  />
                </template>
                <template v-else>
                  <span v-if="mainSubFor(r)?.is_enabled"
                        class="ds-rest-sub"
                        :class="{ 'has-tg': (mainSubFor(r)?.tg_names || []).length }"
                        :title="mainSubTitle(r)">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0 1 12 0v5l1.5 2.5h-15L6 14V9Z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>
                    <span v-if="(mainSubFor(r)?.tg_names || []).length" class="ds-rest-sub-count">{{ mainSubFor(r).tg_names.length }}</span>
                  </span>
                  {{ r.notes || '' }}
                </template>
              </td>
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
            <div class="ds-col-header-meta">
              <span class="ds-col-count">{{ dayMainCount(d.num) }}</span>
              <span v-if="isPSGroup && dayDoughCount(d.num)" class="ds-col-subcount">тесто {{ dayDoughCount(d.num) }}</span>
            </div>
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
              <div class="ds-col-item-content">
                <span class="ds-col-item-addr">{{ item.address }}</span>
                <div class="ds-col-item-lines">
                  <span
                    v-if="item.delivery_time"
                    class="ds-col-item-time"
                    :class="{ 'ds-col-item-time-edit': isEditing }"
                    @dblclick.stop="isEditing && startCardEdit(item, d.num, 'delivery_time')"
                  >{{ item.delivery_time }}</span>
                  <span
                    v-if="item.dough_time"
                    class="ds-col-item-dough"
                    :class="{ 'ds-col-item-dough-edit': isEditing }"
                    @dblclick.stop="isEditing && startCardEdit(item, d.num, 'dough_time')"
                  >Тесто: {{ item.dough_time }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══ TIME EDIT MODAL (card mode) ═══ -->
    <Teleport to="body">
      <div v-if="cardEditing" class="modal" @click.self="tryCloseCard">
        <div class="modal-box" style="max-width: 360px;">
          <div class="modal-header">
            <h2>{{ cardEditing?.field === 'dough_time' ? 'Время теста' : 'Время доставки' }}</h2>
            <button class="modal-close" @click="tryCloseCard"><BkIcon name="close" size="sm"/></button>
          </div>
          <div class="ds-modal-body">
            <div class="ds-edit-info">
              <span class="ds-edit-num">{{ cardEditing.restaurant.number }}</span>
              {{ cardEditing.restaurant.address }}
            </div>
            <div class="ds-edit-day">{{ cardEditing.dayLabel }}</div>
            <label class="ds-label">
              <span class="ds-label-text">{{ cardEditing?.field === 'dough_time' ? 'Время теста' : 'Время доставки' }}</span>
              <input
                v-model="cardEditing.value"
                type="text"
                placeholder="10:00-14:00"
                @keydown.enter="saveCardEdit"
                autofocus
              />
            </label>
            <div class="ds-edit-hint">Оставьте пустым, чтобы убрать это время</div>
          </div>
          <div class="ds-modal-footer">
            <div class="ds-modal-footer-right">
              <button class="btn" @click="tryCloseCard">Отмена</button>
              <button class="btn primary" @click="saveCardEdit">Сохранить</button>
            </div>
          </div>
        </div>
      </div>
      <ConfirmModal v-if="showCardConfirm" title="Закрыть без сохранения?" message="Изменённое время не будет сохранено." @confirm="cardEditing = null; showCardConfirm = false" @cancel="showCardConfirm = false" />
    </Teleport>

    <!-- ═══ ORDER DEADLINE MODAL ═══ -->
    <Teleport to="body">
      <div v-if="editingDeadline" class="modal" @click.self="cancelDeadlineEdit">
        <div class="modal-box ds-deadline-modal" style="max-width: 420px;">
          <div class="modal-header">
            <h2>Дедлайн заявки на поставку</h2>
            <button class="modal-close" @click="cancelDeadlineEdit"><BkIcon name="close" size="sm"/></button>
          </div>
          <div class="ds-modal-body">
            <div class="ds-edit-info">
              <span class="ds-edit-num">{{ editingDeadline.restaurantNumber }}</span>
              {{ editingDeadline.restaurantAddress }}
            </div>
            <div class="ds-edit-day">Поставка: {{ editingDeadline.dayLabel }}</div>
            <div class="ds-dl-hint">Когда ресторан должен подать заявку через 1С, чтобы заказ попал в эту доставку.</div>
            <div class="ds-dl-row">
              <label class="ds-label">
                <span class="ds-label-text">День подачи</span>
                <select v-model.number="editingDeadline.orderDay">
                  <option v-for="i in 7" :key="i" :value="i">{{ DAY_NAMES_FULL_LOCAL[i] }}</option>
                </select>
              </label>
              <label class="ds-label">
                <span class="ds-label-text">Крайний срок</span>
                <input v-model="editingDeadline.orderDeadline" type="time" />
              </label>
            </div>
            <div class="ds-dl-reminders">
              <div class="ds-label-text" style="margin-bottom:6px;">Времена напоминаний</div>
              <ReminderTimesEditor v-model="editingDeadline.reminderTimes" />
            </div>
          </div>
          <div class="ds-modal-footer">
            <button v-if="editingDeadline.hadValue" class="btn ds-btn-danger" @click="clearDeadlineEdit">Очистить</button>
            <div class="ds-modal-footer-right">
              <button class="btn" @click="cancelDeadlineEdit">Отмена</button>
              <button class="btn primary" @click="saveDeadlineEdit">Сохранить</button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ═══ RESTAURANT EDIT MODAL ═══ -->
    <Teleport to="body">
      <div v-if="editingRestaurant" class="modal" @click.self="tryCloseRestaurant">
        <div class="modal-box" style="max-width: 440px;">
          <div class="modal-header">
            <h2>Редактирование ресторана</h2>
            <button class="modal-close" @click="tryCloseRestaurant"><BkIcon name="close" size="sm"/></button>
          </div>
          <div class="ds-modal-body">
            <div style="display:flex;gap:10px;">
              <label class="ds-label" style="flex:1;">
                <span class="ds-label-text">Номер</span>
                <input v-model="editingRestaurant.number" type="text" />
              </label>
              <label v-if="isPSGroup" class="ds-label" style="flex:1;">
                <span class="ds-label-text">№ ДОДО ИС</span>
                <input v-model="editingRestaurant.dodo_is_number" type="number" />
              </label>
            </div>
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
              <button class="btn" @click="tryCloseRestaurant">Отмена</button>
              <button class="btn primary" @click="saveRestaurantEdit">Сохранить</button>
            </div>
          </div>
        </div>
      </div>
      <ConfirmModal v-if="showRestConfirm" title="Закрыть без сохранения?" message="Изменения ресторана не будут сохранены." @confirm="editingRestaurant = null; showRestConfirm = false" @cancel="showRestConfirm = false" />
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
import { ref, computed, defineAsyncComponent, watch, nextTick, onMounted, onUnmounted } from 'vue';
import { useRestaurantStore } from '@/stores/restaurantStore.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import { db } from '@/lib/apiClient.js';

const AuditLogModal = defineAsyncComponent(() => import('@/components/modals/AuditLogModal.vue'));
const ConfirmModal = defineAsyncComponent(() => import('@/components/modals/ConfirmModal.vue'));
const ReminderTimesEditor = defineAsyncComponent(() => import('@/components/ui/ReminderTimesEditor.vue'));

const store = useRestaurantStore();
const orderStore = useOrderStore();
const userStore = useUserStore();
const toastStore = useToastStore();

const viewMode = ref('table');
const selectedDay = ref(1);
const filterRegion = ref('');
const searchQuery = ref('');

const canEdit = computed(() => userStore.hasAccess('delivery-schedule', 'edit'));
const editMode = ref(false);
const isEditing = computed(() => canEdit.value && editMode.value);

function toggleEditMode() {
  if (editMode.value) {
    // Выходим из режима — отменяем все текущие правки
    editingCell.value = null;
    editingNote.value = null;
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

const colCount = computed(() => {
  // №, [ДОДО ИС для ПС], Адрес, Дн, 6 дней, Комментарий
  return 3 + dayNames.length + 1 + (isPSGroup.value ? 1 : 0);
});

function onKey(e) {
  if (e.key === 'Escape') {
    if (showCardConfirm.value || showRestConfirm.value) return;
    if (cardEditing.value) { tryCloseCard(); return; }
    if (editingRestaurant.value) { tryCloseRestaurant(); return; }
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

function groupAvgDeliveries(items) {
  if (!items.length) return 0;
  let total = 0;
  for (const r of items) total += deliveryCount(r);
  return (total / items.length).toFixed(1).replace(/\.0$/, '');
}

const filteredGroups = computed(() => {
  const groups = [];
  const minsk = filteredRestaurants.value.filter(r => r.region === 'Минск');
  const regions = filteredRestaurants.value.filter(r => r.region !== 'Минск');
  if (minsk.length) groups.push({ label: 'Минск', items: minsk, avg: groupAvgDeliveries(minsk) });
  if (regions.length) groups.push({ label: 'Регионы', items: regions, avg: groupAvgDeliveries(regions) });
  return groups;
});

function getTime(restaurant, day) {
  const rSched = store.scheduleByRestaurant.get(String(restaurant.id));
  if (!rSched) return '';
  const s = rSched.get(day);
  return s?.delivery_time || '';
}
function getDough(restaurant, day) {
  const rSched = store.scheduleByRestaurant.get(String(restaurant.id));
  if (!rSched) return '';
  const s = rSched.get(day);
  return s?.dough_time || '';
}
function getDeadline(restaurant, day) {
  const rSched = store.scheduleByRestaurant.get(String(restaurant.id));
  if (!rSched) return null;
  const s = rSched.get(day);
  if (!s?.order_day || !s?.order_deadline) return null;
  return { order_day: Number(s.order_day), order_deadline: String(s.order_deadline).slice(0, 5) };
}
const DAY_NAMES_FULL_LOCAL = ['', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье'];
const DAY_SHORT_LOCAL = ['', 'ПН', 'ВТ', 'СР', 'ЧТ', 'ПТ', 'СБ', 'ВС'];
// ПС-группа: показываем колонку «тесто» дополнительно к основной доставке
const isPSGroup = computed(() => {
  const le = orderStore.settings.legalEntity || '';
  return le.includes('Пицца Стар');
});

// Отображаемый номер: для ПС — «чистый» додовский номер (1..50),
// для БК+ВМ — как есть.
function displayNumber(r) {
  if (r.legal_entity_group === 'PS' && r.number >= 1000) return r.number - 1000;
  return r.number;
}

// Подписка ресторана на напоминания об основной поставке.
// Используется в шаблоне для иконки-колокольчика рядом с номером.
function mainSubFor(restaurant) {
  return store.mainSubscriptionFor(restaurant.id);
}

function mainSubTitle(restaurant) {
  const s = mainSubFor(restaurant);
  if (!s || !s.is_enabled) return 'Ресторан не подписан на напоминания';
  const names = (s.tg_names || []);
  if (names.length) return 'Подписан, Telegram: ' + names.join(', ');
  if (s.telegram_enabled) return 'Подписан, Telegram-канал включён, но получатели не выбраны';
  return 'Подписан на напоминания в кабинете';
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

function dayMainCount(day) {
  return dayRestaurants(day).filter(item => item.delivery_time).length;
}

function dayDoughCount(day) {
  return dayRestaurants(day).filter(item => item.dough_time).length;
}

// ═══ Inline edit (table — dblclick) ═══
const editingCell = ref(null);
let savingEdit = false;

function startEdit(restaurant, day, field = 'delivery_time') {
  if (!isEditing.value) return;
  const currentValue = field === 'dough_time' ? getDough(restaurant, day) : getTime(restaurant, day);
  editingCell.value = {
    rid: restaurant.id,
    day,
    field,
    value: currentValue,
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
  const { restaurantId, day, value, field } = editingCell.value;
  const oldValue = field === 'dough_time'
    ? getDough({ id: restaurantId }, day)
    : getTime({ id: restaurantId }, day);
  editingCell.value = null;
  if (value === oldValue) { savingEdit = false; return; }
  try {
    await store.saveScheduleCell(restaurantId, day, value, field);
  } catch (e) {
    toastStore.error('Ошибка сохранения');
  } finally {
    savingEdit = false;
  }
}

function cancelEdit() {
  editingCell.value = null;
}

// ═══ Inline note edit (table — dblclick on comment) ═══
const editingNote = ref(null);
let savingNote = false;

function startNoteEdit(restaurant) {
  if (!isEditing.value) return;
  editingNote.value = {
    rid: restaurant.id,
    value: restaurant.notes || '',
    original: restaurant.notes || '',
  };
  nextTick(() => {
    const inp = document.querySelector('.ds-note-input');
    if (inp) { inp.focus(); inp.select(); }
  });
}

async function saveNoteEdit() {
  if (!editingNote.value || savingNote) return;
  savingNote = true;
  const { rid, value, original } = editingNote.value;
  editingNote.value = null;
  if (value === original) { savingNote = false; return; }
  try {
    await store.saveRestaurantNote(rid, value);
  } catch (e) {
    toastStore.error('Ошибка сохранения комментария');
  } finally {
    savingNote = false;
  }
}

function cancelNoteEdit() {
  editingNote.value = null;
}

// ═══ Дедлайн подачи заявки на основную поставку ═══
const editingDeadline = ref(null);
let savingDeadline = false;

function startDeadlineEdit(restaurant, day) {
  if (!isEditing.value) return;
  const current = getDeadline(restaurant, day);
  // По умолчанию — день перед доставкой
  const defaultOrderDay = day > 1 ? day - 1 : 7;
  const rSched = store.scheduleByRestaurant.get(String(restaurant.id));
  const rawRt = rSched?.get(day)?.reminder_times;
  let reminderTimes = [];
  if (rawRt) {
    try {
      const arr = typeof rawRt === 'string' ? JSON.parse(rawRt) : rawRt;
      if (Array.isArray(arr)) reminderTimes = arr
        .filter(x => x && /^\d{1,2}:\d{2}/.test(x.time || ''))
        .map(x => ({ days_before: Number(x.days_before) | 0, time: String(x.time).slice(0, 5) }));
    } catch (e) { /* ignore */ }
  }
  editingDeadline.value = {
    restaurantId: restaurant.id,
    restaurantNumber: restaurant.number,
    restaurantAddress: restaurant.address,
    deliveryDay: day,
    dayLabel: DAY_NAMES_FULL_LOCAL[day] || `День ${day}`,
    orderDay: current?.order_day || defaultOrderDay,
    orderDeadline: current?.order_deadline || '14:00',
    reminderTimes,
    hadValue: !!current,
  };
}

async function saveDeadlineEdit() {
  if (!editingDeadline.value || savingDeadline) return;
  const { restaurantId, deliveryDay, orderDay, orderDeadline, reminderTimes } = editingDeadline.value;
  savingDeadline = true;
  try {
    await store.saveScheduleOrderDeadline(restaurantId, deliveryDay, orderDay, orderDeadline, reminderTimes || []);
    editingDeadline.value = null;
  } catch (e) {
    toastStore.error('Ошибка сохранения дедлайна');
  } finally {
    savingDeadline = false;
  }
}

async function clearDeadlineEdit() {
  if (!editingDeadline.value || savingDeadline) return;
  const { restaurantId, deliveryDay } = editingDeadline.value;
  savingDeadline = true;
  try {
    await store.saveScheduleOrderDeadline(restaurantId, deliveryDay, null, null, null);
    editingDeadline.value = null;
  } catch (e) {
    toastStore.error('Ошибка очистки дедлайна');
  } finally {
    savingDeadline = false;
  }
}

function cancelDeadlineEdit() {
  editingDeadline.value = null;
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

function onDragStart(e, restaurant, day, field = 'delivery_time') {
  if (!isEditing.value) { e.preventDefault(); return; }
  clearTimeout(longPressTimer);
  const time = field === 'dough_time' ? getDough(restaurant, day) : getTime(restaurant, day);
  dragState.value = { rid: restaurant.id, fromDay: day, time, field, overDay: null };
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
  const { fromDay, time, field } = dragState.value;
  dragState.value = null;
  if (fromDay === toDay || !time) return;

  dragging.value = true;
  try {
    // Переносим именно то поле, которое тащили (основная или тесто)
    await store.saveScheduleCell(restaurant.id, toDay, time, field);
    await store.saveScheduleCell(restaurant.id, fromDay, '', field);
    const label = field === 'dough_time' ? ' (тесто)' : '';
    toastStore.success(`${dayNames.find(d => d.num === fromDay)?.short} → ${dayNames.find(d => d.num === toDay)?.short}${label}`);
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
let _restSnapshot = '';
const showRestConfirm = ref(false);

function startEditRestaurant(restaurant) {
  if (!isEditing.value) return;
  editingRestaurant.value = { ...restaurant };
  _restSnapshot = JSON.stringify(editingRestaurant.value);
}

function tryCloseRestaurant() {
  if (editingRestaurant.value && JSON.stringify(editingRestaurant.value) !== _restSnapshot) {
    showRestConfirm.value = true;
    return;
  }
  editingRestaurant.value = null;
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
let _cardSnapshot = '';
const showCardConfirm = ref(false);

function startCardEdit(item, dayNum, field = 'delivery_time') {
  if (!isEditing.value) return;
  const day = dayNum || selectedDay.value;
  const d = dayNames.find(d => d.num === day);
  cardEditing.value = {
    restaurant: item,
    day,
    field,
    dayLabel: d?.full || '',
    value: field === 'dough_time' ? (item.dough_time || '') : (item.delivery_time || ''),
  };
  _cardSnapshot = cardEditing.value.value;
}

function tryCloseCard() {
  if (cardEditing.value && cardEditing.value.value !== _cardSnapshot) {
    showCardConfirm.value = true;
    return;
  }
  cardEditing.value = null;
}

async function saveCardEdit() {
  if (!cardEditing.value) return;
  const { restaurant, day, value, field } = cardEditing.value;
  cardEditing.value = null;
  try {
    await store.saveScheduleCell(restaurant.id, day, value, field);
    store.invalidate();
    store.load(orderStore.settings.legalEntity);
  } catch (e) {
    toastStore.error('Ошибка сохранения');
  }
}

async function exportExcel() {
  try {
    const { exportScheduleToExcel } = await import('@/lib/excelExport.js');
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
      .eq('legal_entity', orderStore.settings.legalEntity)
      .order('created_at', { ascending: false })
      .limit(100);
    if (error) throw new Error(error);
    logEntries.value = (data || []).map(row => ({
      ...row,
      details: typeof row.details === 'string' ? (() => { try { return JSON.parse(row.details); } catch { return {}; } })() : row.details,
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
.ds-group-avg {
  margin-left: 8px; font-size: 11px; font-weight: 400;
  color: var(--text-muted, #999);
}

/* ═══ Table cells ═══ */
.ds-td-num {
  font-weight: 800; color: var(--bk-red);
  font-size: 13px;
  font-family: 'Plus Jakarta Sans', sans-serif;
}
/* Иконка-колокольчик: ресторан подписан на напоминания об основной поставке.
   Размещается в начале ячейки «Комментарий». Серый — подписан без Telegram-
   получателей, оранжевый — с получателями (число рядом). */
.ds-rest-sub {
  display: inline-flex; align-items: center; gap: 2px;
  margin-right: 4px; vertical-align: middle;
  color: #9aa0a6;
}
.ds-rest-sub.has-tg { color: var(--bk-orange, #E76F51); }
.ds-rest-sub-count {
  font-size: 10px; font-weight: 700;
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

/* Кнопка дедлайна заявки в ячейке дня */
.ds-deadline-btn {
  display: inline-block;
  margin-top: 3px;
  padding: 1px 6px;
  font-size: 10px;
  line-height: 1.3;
  border: 1px dashed #cdd5df;
  background: transparent;
  color: #8a93a0;
  border-radius: 8px;
  cursor: default;
  white-space: nowrap;
  transition: background 0.12s, color 0.12s, border-color 0.12s;
}
.ds-deadline-btn:disabled { display: none; }
.ds-deadline-btn-empty { cursor: pointer; }
.ds-deadline-btn-empty:hover { background: #f4f7fb; color: #4a5260; border-color: #b0bdcc; }
.ds-deadline-btn-set {
  background: #fff4e0;
  color: #8a5a00;
  border: 1px solid #f1d28a;
  border-style: solid;
  cursor: pointer;
}
.ds-deadline-btn-set:hover { background: #ffe9c3; }
.ds-deadline-text { font-weight: 600; }

/* Модалка дедлайна — кнопка очистки слева */
.ds-deadline-modal .ds-modal-footer { justify-content: space-between; }
.ds-btn-danger { color: #b30000; }
.ds-btn-danger:hover { background: #ffeaea; }
.ds-dl-hint { font-size: 12px; color: #777; margin: 4px 0 12px; line-height: 1.4; }
.ds-dl-row { display: flex; gap: 12px; align-items: stretch; }
.ds-dl-row .ds-label { flex: 1; }
.ds-dl-reminders { margin-top: 14px; padding-top: 14px; border-top: 1px dashed #e8e8e8; }

/* ПС: ячейка с двумя чипами (основная доставка + тесто) рядом */
.ds-td-ps { padding: 4px 3px !important; vertical-align: middle; }
.ds-ps-cell {
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 4px;
  justify-content: center;
  flex-wrap: nowrap;
}
.ds-ps-cell .ds-time-chip { font-size: 10px; padding: 1px 5px; white-space: nowrap; }
.ds-ps-cell .ds-chip-main { background: #A5D6A7; color: #1B5E20; }
.ds-ps-cell .ds-chip-dough { background: #FFE0B2; color: #6b4f3a; }
.ds-ps-cell .ds-dough-empty { font-size: 9px; color: #e0d5c8; padding: 1px 3px; }

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

.ds-td-note { text-align: left; color: var(--text-secondary); font-size: 11px; max-width: 200px; }
.ds-td-note-editable { cursor: pointer; }
.ds-td-note-editable:hover { background: #FFF8F0; }
.ds-note-input {
  width: 100%; padding: 3px 4px;
  border: 2px solid var(--bk-orange);
  border-radius: 3px; font-size: 11px; text-align: left;
  outline: none; background: #fff; font-family: inherit; font-weight: 500;
  color: var(--text);
  box-shadow: 0 0 0 2px rgba(255, 135, 50, 0.12);
  min-width: 120px;
}
.ds-note-input::placeholder { color: var(--text-muted); font-weight: 400; }

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
.ds-col-header-meta {
  display: flex;
  align-items: center;
  gap: 6px;
}
.ds-col-count {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 16px; height: 15px; border-radius: 8px;
  background: var(--bk-orange); color: #fff;
  font-size: 9px; font-weight: 800; padding: 0 4px;
}
.ds-col-subcount {
  font-size: 9px;
  font-weight: 700;
  color: rgba(255,255,255,0.85);
  white-space: nowrap;
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
  min-width: 0;
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
.ds-col-item-content {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  flex: 1;
  min-width: 0;
}
.ds-col-item-addr {
  font-size: 10px; font-weight: 500; color: var(--text-secondary);
  line-height: 1.2;
  flex: 1; min-width: 0;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ds-col-item-lines {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  min-width: 0;
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
.ds-col-item-dough {
  display: inline-block;
  background: #FFE0B2;
  color: #6b4f3a;
  padding: 0 4px;
  border-radius: 2px;
  font-weight: 700;
  font-size: 9px;
  white-space: nowrap;
  line-height: 1.5;
}
.ds-col-item-dough-edit { cursor: pointer; }
.ds-col-item-dough-edit:hover { background: #FFD39B; }

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
  .ds-col-header-meta { gap: 8px !important; }
  .ds-col-count {
    background: none !important; color: #fff !important;
    min-width: auto !important; height: auto !important; font-size: 14px !important;
    font-weight: 900 !important;
    border: none !important; border-radius: 0 !important;
  }
  .ds-col-subcount {
    font-size: 11px !important;
    color: rgba(255,255,255,0.9) !important;
  }
  .ds-col-body { padding: 2px 4px !important; gap: 0 !important; }
  .ds-col-item {
    padding: 3px 4px !important; border-color: #ddd !important;
    gap: 5px !important;
    align-items: flex-start !important;
  }
  .ds-col-item-num {
    font-size: 15px !important; font-weight: 600 !important; color: #000 !important;
    font-family: 'Plus Jakarta Sans', sans-serif !important;
  }
  .ds-col-item-content {
    gap: 3px !important;
  }
  .ds-col-item-addr {
    font-size: 13px !important; color: #333 !important; font-weight: 600 !important;
  }
  .ds-col-item-time {
    font-size: 13px !important; padding: 1px 5px !important;
    background: #A5D6A7 !important; color: #1B5E20 !important;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }
  .ds-col-item-dough {
    font-size: 12px !important;
    padding: 1px 5px !important;
    background: #FFE0B2 !important;
    color: #6b4f3a !important;
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
  .pf-wrap { overflow-x: auto !important; }
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
