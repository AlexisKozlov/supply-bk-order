<template>
  <div class="calendar-view">
    <!-- Просроченные -->
    <div v-if="!loading && grid.overdue.length" class="cal-overdue">
      <BkIcon name="warning" size="sm"/> Давно без заказа:
      <template v-for="(s, i) in grid.overdue" :key="s.name">
        <b>{{ s.name }}</b> ({{ s.daysAgo ?? '?' }} дн.){{ i < grid.overdue.length - 1 ? ', ' : '' }}
      </template>
    </div>

    <!-- Шапка -->
    <div class="cal-header">
      <h1 class="page-title">Календарь поставок</h1>
      <div class="cal-nav">
        <button class="btn small" @click="prevMonth">◀</button>
        <button class="cal-month-btn" @click="showMonthPicker = !showMonthPicker">
          {{ monthName }} {{ year }}
        </button>
        <button class="btn small" @click="nextMonth">▶</button>
        <div v-if="showMonthPicker" class="cal-month-picker">
          <button v-for="(m, i) in allMonths" :key="i" class="cal-month-opt" :class="{ active: i === month }"
            @click="month = i; showMonthPicker = false; load();">{{ m.slice(0, 3) }}</button>
        </div>
      </div>
    </div>

    <!-- Легенда -->
    <div v-if="!loading && grid.legend.length" class="cal-legend">
      <span v-for="l in grid.legend" :key="l.name" class="cal-legend-item">
        <span class="cal-legend-dot" :style="{ background: l.color }"></span>
        {{ l.name }}{{ l.daysLabel }}
      </span>
    </div>

    <div v-if="loading" style="text-align:center;padding:60px;"><BurgerSpinner text="Загрузка..." /></div>

    <!-- Сетка -->
    <div v-else class="cal-grid-wrap">
      <div class="cal-weekdays">
        <div class="cal-weekday cal-wk-header">Нед</div>
        <div v-for="d in dayNames" :key="d" class="cal-weekday">{{ d }}</div>
      </div>

      <div v-for="(week, wi) in weeks" :key="wi" class="cal-row" :class="{ 'cal-row-current': isCurrentWeek(week) }">
        <div class="cal-wk-num" :class="{ current: isCurrentWeek(week) }">{{ isoWeekNum(week) }}</div>
        <div v-for="(cell, ci) in week" :key="ci"
          class="cal-cell"
          :class="{
            'cal-cell-empty': cell.empty,
            'cal-cell-today': cell.isToday,
            'cal-cell-past': cell.isPast && !cell.isToday,
            'cal-cell-has': cell.orders?.length,
          }">
          <template v-if="!cell.empty">
            <div class="cal-cell-head">
              <span class="cal-cell-num" :class="{ today: cell.isToday }">{{ cell.day }}</span>
            </div>
            <div v-if="cell.orders?.length" class="cal-cell-orders">
              <div v-for="o in cell.orders" :key="o.id" class="cal-tag"
                :style="{ '--tag-color': o.color }"
                @click="onTagClick(o)">
                <span class="cal-tag-dot" :style="{ background: o.color }"></span>
                <span class="cal-tag-name">{{ o.supplier }}</span>
              </div>
            </div>
          </template>
        </div>
      </div>
    </div>

    <!-- Модалка просмотра заказа -->
    <Teleport to="body">
      <div v-if="preview.show" class="modal" @click.self="preview.show = false">
        <div class="modal-box" style="max-width:440px;">
          <div class="modal-header">
            <h2>{{ preview.supplier }}</h2>
            <button class="modal-close" @click="preview.show = false"><BkIcon name="close" size="xs"/></button>
          </div>
          <div class="cal-preview-meta">
            <span>Поставка: <b>{{ preview.deliveryDate }}</b></span>
            <span>Позиций: <b>{{ preview.itemCount }}</b></span>
          </div>
          <div v-if="preview.items.length" class="cal-preview-items">
            <div v-for="item in preview.items" :key="item.sku" class="cal-preview-item">
              <span class="cal-preview-sku">{{ item.sku }}</span>
              <span class="cal-preview-name">{{ item.name }}</span>
              <span class="cal-preview-qty">{{ item.qty }} кор</span>
            </div>
            <div v-if="preview.itemCount > preview.items.length" class="cal-preview-more">
              ...и ещё {{ preview.itemCount - preview.items.length }} поз.
            </div>
          </div>
          <div v-else style="color:var(--text-muted);font-size:13px;padding:12px 0;text-align:center;">Нет позиций с заказом</div>
          <div class="cal-preview-actions">
            <button class="btn primary" @click="openOrder(preview.orderId)">
              <BkIcon name="eye" size="sm"/> Открыть заказ
            </button>
            <button class="btn" @click="preview.show = false">Закрыть</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useOrderStore } from '@/stores/orderStore.js';
import { useDraftStore } from '@/stores/draftStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import { db } from '@/lib/apiClient.js';
import { loadCalendarData, buildCalendarGrid, MONTH_NAMES, DAY_NAMES } from '@/lib/calendar.js';
import BkIcon from '@/components/ui/BkIcon.vue';

const router = useRouter();
const orderStore = useOrderStore();
const draftStore = useDraftStore();
const toast = useToastStore();

const year = ref(new Date().getFullYear());
const month = ref(new Date().getMonth());
const loading = ref(false);
const orders = ref([]);
const supplierColors = ref({});
const showMonthPicker = ref(false);
const preview = ref({ show: false, orderId: null, supplier: '', itemCount: 0, items: [], deliveryDate: '' });

const allMonths = MONTH_NAMES;
const dayNames = DAY_NAMES;
const monthName = computed(() => MONTH_NAMES[month.value]);
const grid = computed(() => buildCalendarGrid(year.value, month.value, orders.value, supplierColors.value));

const weeks = computed(() => {
  const cells = grid.value.cells;
  const result = [];
  for (let i = 0; i < cells.length; i += 7) {
    const row = cells.slice(i, i + 7);
    while (row.length < 7) row.push({ empty: true });
    result.push(row);
  }
  return result;
});

function isoWeekNum(week) {
  const real = week.find(c => !c.empty);
  if (!real) return '';
  const d = new Date(year.value, month.value, real.day);
  d.setHours(0, 0, 0, 0);
  d.setDate(d.getDate() + 3 - ((d.getDay() + 6) % 7));
  const jan4 = new Date(d.getFullYear(), 0, 4);
  return 1 + Math.round(((d - jan4) / 86400000 - 3 + ((jan4.getDay() + 6) % 7)) / 7);
}

function isCurrentWeek(week) { return week.some(c => c.isToday); }

function onTagClick(o) {
  const dd = o.deliveryDate ? new Date(o.deliveryDate).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '—';
  preview.value = {
    show: true, orderId: o.id, supplier: o.supplier,
    itemCount: o.itemCount, items: o.items || [], deliveryDate: dd,
  };
}

async function openOrder(orderId) {
  preview.value.show = false;
  const { data: order, error } = await db
    .from('orders').select('*, order_items(*)').eq('id', orderId).single();
  if (error || !order) { toast.error('Ошибка', 'Не удалось загрузить заказ'); return; }
  await orderStore.loadOrderIntoForm(order, orderStore.settings.legalEntity, false, true);
  draftStore.saveNow();
  router.push({ name: 'order' });
  toast.success('Заказ загружен', 'Режим просмотра');
}

function prevMonth() { showMonthPicker.value = false; month.value--; if (month.value < 0) { month.value = 11; year.value--; } load(); }
function nextMonth() { showMonthPicker.value = false; month.value++; if (month.value > 11) { month.value = 0; year.value++; } load(); }

async function load() {
  loading.value = true;
  const result = await loadCalendarData(year.value, month.value, orderStore.settings.legalEntity);
  orders.value = result.orders;
  supplierColors.value = result.supplierColors;
  loading.value = false;
}

watch(() => orderStore.settings.legalEntity, () => load());
onMounted(() => load());
</script>

<style scoped>
.calendar-view { padding: 0; display: flex; flex-direction: column; height: 100%; }

.cal-overdue {
  padding: 8px 14px; background: linear-gradient(90deg, #FFF3E0, #FFE0B2);
  border: 1px solid #FFCC80; border-radius: 8px;
  font-size: 12px; color: #BF360C; flex-shrink: 0; margin-bottom: 8px;
}
.cal-overdue b { margin: 0 2px; }

.cal-header { display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; margin-bottom: 8px; }
.cal-nav { display: flex; align-items: center; gap: 6px; position: relative; }
.cal-month-btn {
  background: white; border: 1.5px solid var(--border); border-radius: 8px;
  padding: 6px 18px; font-family: 'Flame', sans-serif;
  font-size: 16px; font-weight: 700; color: var(--bk-brown);
  cursor: pointer; min-width: 170px; text-align: center; transition: all 0.15s;
}
.cal-month-btn:hover { border-color: var(--bk-orange); }
.cal-month-picker {
  position: absolute; top: 100%; right: 0; margin-top: 4px;
  background: white; border-radius: 10px; padding: 8px;
  box-shadow: var(--shadow-lg); z-index: 100;
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 3px; min-width: 180px;
}
.cal-month-opt {
  background: none; border: none; border-radius: 6px; padding: 6px 4px;
  cursor: pointer; font-weight: 600; font-size: 12px; color: var(--text); transition: all 0.1s;
}
.cal-month-opt:hover { background: var(--bg); }
.cal-month-opt.active { background: var(--bk-brown); color: #fff; }

.cal-legend {
  display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 8px; padding: 6px 12px;
  background: #FAFAF7; border-radius: 6px; border: 1px solid var(--border-light); flex-shrink: 0;
}
.cal-legend-item { display: flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600; color: #5A2D0C; }
.cal-legend-dot { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }

.cal-grid-wrap { flex: 1; display: flex; flex-direction: column; min-height: 0; overflow: auto; }

.cal-weekdays {
  display: grid; grid-template-columns: 36px repeat(7, 1fr);
  gap: 2px; margin-bottom: 2px; flex-shrink: 0;
}
.cal-weekday {
  text-align: center; font-size: 11px; font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.5px; color: var(--text-muted); padding: 5px 0;
  background: var(--bg); border-radius: 4px;
}
.cal-wk-header { font-size: 9px; color: var(--text-muted); opacity: 0.6; }

.cal-row {
  display: grid; grid-template-columns: 36px repeat(7, 1fr);
  gap: 2px; margin-bottom: 2px;
}

.cal-wk-num {
  display: flex; align-items: center; justify-content: center;
  font-size: 10px; font-weight: 700; color: var(--text-muted);
  background: var(--bg); border-radius: 4px; opacity: 0.6;
}
.cal-wk-num.current { color: var(--bk-orange); opacity: 1; font-weight: 800; }

.cal-cell {
  background: white; border: 1.5px solid var(--border);
  border-radius: 6px; padding: 4px 5px;
  min-height: 78px; display: flex; flex-direction: column;
  transition: border-color 0.15s, box-shadow 0.15s;
}
.cal-cell:hover:not(.cal-cell-empty) {
  border-color: var(--bk-orange);
  box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}
.cal-cell-empty { background: #F8F6F3; border-color: #EDEAE6; }
.cal-cell-today {
  border: 2px solid var(--bk-orange); background: #FFFCF0;
  box-shadow: 0 0 0 2px rgba(255,135,50,0.12);
}
.cal-cell-past { opacity: 0.5; }
.cal-cell-has { background: #FEFEFE; }

.cal-cell-head { display: flex; align-items: center; margin-bottom: 3px; }
.cal-cell-num { font-size: 13px; font-weight: 700; color: var(--text); line-height: 1; }
.cal-cell-num.today {
  background: var(--bk-orange); color: #fff;
  width: 22px; height: 22px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center; font-size: 11px;
}

.cal-cell-orders { display: flex; flex-direction: column; gap: 2px; flex: 1; }
.cal-tag {
  --tag-color: #999;
  display: flex; align-items: center; gap: 4px;
  padding: 3px 5px; border-radius: 4px;
  background: color-mix(in srgb, var(--tag-color) 12%, white);
  border-left: 3px solid var(--tag-color);
  cursor: pointer; transition: background 0.1s; min-width: 0;
}
.cal-tag:hover { background: color-mix(in srgb, var(--tag-color) 22%, white); }
.cal-tag-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.cal-tag-name {
  font-size: 10px; font-weight: 600; color: var(--text);
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1;
}

/* Preview modal */
.cal-preview-meta {
  display: flex; gap: 16px; padding: 8px 0; border-bottom: 1px solid var(--border-light);
  font-size: 13px; color: var(--text-secondary);
}
.cal-preview-items {
  max-height: 260px; overflow-y: auto; margin: 8px 0;
}
.cal-preview-item {
  display: grid; grid-template-columns: 70px 1fr 50px;
  gap: 6px; padding: 4px 0; border-bottom: 1px solid var(--border-light);
  font-size: 12px; align-items: center;
}
.cal-preview-sku { color: var(--text-muted); font-weight: 600; font-size: 11px; }
.cal-preview-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--text); }
.cal-preview-qty { text-align: right; font-weight: 700; color: var(--bk-brown); }
.cal-preview-more { text-align: center; font-size: 11px; color: var(--text-muted); padding: 6px 0; }
.cal-preview-actions {
  display: flex; gap: 8px; justify-content: flex-end; padding-top: 12px;
  border-top: 1px solid var(--border-light);
}

@media (max-width: 900px) {
  .cal-cell { min-height: 56px; }
  .cal-row, .cal-weekdays { grid-template-columns: 28px repeat(7, 1fr); }
}
</style>
