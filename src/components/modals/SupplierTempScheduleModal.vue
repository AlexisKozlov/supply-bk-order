<template>
  <div class="stsm-backdrop" @click.self="close">
    <div class="stsm-modal">
      <div class="stsm-head">
        <div>
          <h3 class="stsm-title">Временный график · {{ supplier.short_name }}</h3>
          <div class="stsm-sub">На выбранный период этот график заменяет основной. После окончания периода система сама вернётся к обычному графику.</div>
        </div>
        <button class="stsm-x" type="button" @click="close" aria-label="Закрыть">×</button>
      </div>

      <div class="stsm-body">
        <div v-if="error" class="stsm-error">{{ error }}</div>
        <div v-if="loading" class="stsm-state">Загружаем текущий график...</div>

        <template v-else>
          <div class="stsm-row">
            <label class="stsm-field">
              <span>С даты</span>
              <input v-model="dateFrom" type="date" />
            </label>
            <label class="stsm-field">
              <span>По дату</span>
              <input v-model="dateTo" type="date" />
            </label>
            <div class="stsm-stats">
              {{ activeRests }} рест., {{ activeDays }} дн.
            </div>
          </div>

          <div class="stsm-actions">
            <button type="button" class="stsm-btn-ghost" @click="copyFromMain">Скопировать из основного</button>
            <button type="button" class="stsm-btn-ghost" @click="clearAll">Очистить</button>
            <input v-model="filter" type="search" class="stsm-search" placeholder="Поиск: №, город..." />
          </div>

          <div class="stsm-rules-hint">
            <span class="stsm-rules-label">Дни подачи заявки (из дефолтов поставщика):</span>
            <span v-for="d in 7" :key="'rl-' + d" class="stsm-rule">
              <b>{{ DAYS_SHORT[d] }}</b> ← {{ DAYS_SHORT[orderDayFor(d)] }}
            </span>
          </div>

          <div class="stsm-table-wrap">
            <table class="stsm-table">
              <thead>
                <tr>
                  <th class="stsm-th-rest">Ресторан</th>
                  <th v-for="d in 7" :key="d" class="stsm-th-day">{{ DAYS_SHORT[d] }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in filteredRestaurants" :key="r.id">
                  <td class="stsm-td-rest">
                    <span class="stsm-num">{{ formatRestaurantNumber(r.number, r.legal_entity_group) }}</span>
                    <span class="stsm-addr">{{ r.city || '' }}{{ r.address ? ', ' + r.address : '' }}</span>
                  </td>
                  <td v-for="d in 7" :key="d" class="stsm-td-check" @click="toggle(r.id, d)">
                    <input type="checkbox" :checked="!!grid[r.id]?.[d]" @click.stop="toggle(r.id, d)" />
                  </td>
                </tr>
                <tr v-if="!filteredRestaurants.length">
                  <td :colspan="8" class="stsm-empty">По запросу ничего не найдено.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </div>

      <div class="stsm-foot">
        <button class="stsm-btn-ghost" type="button" @click="close" :disabled="saving">Закрыть</button>
        <button class="stsm-btn-danger" type="button" @click="removePeriod" :disabled="saving || loading || !hasPeriod">Удалить период</button>
        <button class="stsm-btn-primary" type="button" @click="save(false)" :disabled="saving || loading">
          {{ saving && !notifyMode ? 'Сохраняем...' : 'Сохранить' }}
        </button>
        <button class="stsm-btn-accent" type="button" @click="save(true)" :disabled="saving || loading" title="Сохранить и отправить уведомление в Telegram + Push всем ресторанам">
          {{ saving && notifyMode ? 'Уведомляем...' : 'Сохранить и уведомить' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { formatRestaurantNumber } from '@/lib/legalEntities.js';
import { appConfirm } from '@/lib/appDialogs.js';

const props = defineProps({
  supplier: { type: Object, required: true },     // { id, short_name, legal_entity_group, so_enabled }
  restaurants: { type: Array, required: true },   // отфильтрованные по группе поставщика
  mainSchedules: { type: Array, default: () => [] }, // основной график [{restaurant_number, order_day, delivery_day, is_active}]
});

const emit = defineEmits(['saved', 'close']);

const DAYS_SHORT = { 1: 'Пн', 2: 'Вт', 3: 'Ср', 4: 'Чт', 5: 'Пт', 6: 'Сб', 7: 'Вс' };

const loading = ref(true);
const saving = ref(false);
const notifyMode = ref(false);
const error = ref('');

const dateFrom = ref('');
const dateTo = ref('');
const grid = reactive({});           // { restaurant_id: { 1..7: true } }
const filter = ref('');
const hasPeriod = ref(false);
const deadlineRules = reactive({});  // { delivery_dow: deadline_dow } — день подачи заявки на день поставки

const filteredRestaurants = computed(() => {
  if (!filter.value.trim()) return props.restaurants;
  const q = filter.value.toLowerCase();
  return props.restaurants.filter(r =>
    String(r.number).includes(q) ||
    (r.city || '').toLowerCase().includes(q) ||
    (r.address || '').toLowerCase().includes(q)
  );
});

const activeRests = computed(() => {
  let n = 0;
  for (const rid of Object.keys(grid)) {
    for (let d = 1; d <= 7; d++) if (grid[rid]?.[d]) { n++; break; }
  }
  return n;
});
const activeDays = computed(() => {
  let n = 0;
  for (const rid of Object.keys(grid)) {
    for (let d = 1; d <= 7; d++) if (grid[rid]?.[d]) n++;
  }
  return n;
});

function authHeaders(extra = {}) {
  const h = { ...extra };
  const t = localStorage.getItem('bk_session_token') || '';
  if (t) h['X-Session-Token'] = t;
  return h;
}

function resetGrid() {
  for (const k of Object.keys(grid)) delete grid[k];
  for (const r of props.restaurants) grid[r.id] = {};
}

function toggle(rid, d) {
  if (!grid[rid]) grid[rid] = {};
  grid[rid][d] = !grid[rid][d];
}

function clearAll() {
  resetGrid();
}

function copyFromMain() {
  resetGrid();
  for (const s of props.mainSchedules) {
    if (s.is_active != 1) continue;
    const rest = props.restaurants.find(r => r.number == s.restaurant_number);
    if (!rest) continue;
    if (!grid[rest.id]) grid[rest.id] = {};
    grid[rest.id][s.delivery_day] = true;
  }
}

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const res = await fetch(`/api/so/admin/schedules?supplier_id=${encodeURIComponent(props.supplier.id)}`, { headers: authHeaders() });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Ошибка загрузки');
    resetGrid();
    // Дефолтные правила: для каждого дня поставки — день подачи заявки.
    // Если для какого-то дня правила нет — fallback на «за день до поставки».
    for (const k of Object.keys(deadlineRules)) delete deadlineRules[k];
    for (const r of (data.deadline_rules || [])) {
      const dd = parseInt(r.delivery_dow);
      const od = parseInt(r.deadline_dow);
      if (dd >= 1 && dd <= 7 && od >= 1 && od <= 7) deadlineRules[dd] = od;
    }
    const temp = data.temporary_schedule;
    if (temp) {
      hasPeriod.value = true;
      dateFrom.value = temp.date_from || '';
      dateTo.value = temp.date_to || '';
      for (const it of temp.items || []) {
        if (it.is_active != 1) continue;
        const rest = props.restaurants.find(r => r.number == it.restaurant_number);
        if (!rest) continue;
        if (!grid[rest.id]) grid[rest.id] = {};
        grid[rest.id][it.delivery_day] = true;
      }
    }
  } catch (e) {
    error.value = e.message || 'Не удалось загрузить временный график';
  } finally {
    loading.value = false;
  }
}

function orderDayFor(deliveryDay) {
  // Берём из дефолтных правил поставщика; если нет — за день до поставки.
  const rule = deadlineRules[deliveryDay];
  if (rule >= 1 && rule <= 7) return rule;
  return deliveryDay > 1 ? deliveryDay - 1 : 7;
}

function buildItems() {
  const items = [];
  for (const r of props.restaurants) {
    for (let d = 1; d <= 7; d++) {
      if (grid[r.id]?.[d]) {
        items.push({ restaurant_id: r.id, order_day: orderDayFor(d), delivery_day: d, is_active: 1 });
      }
    }
  }
  return items;
}

async function save(notify = false) {
  error.value = '';
  if (!dateFrom.value || !dateTo.value) {
    error.value = 'Укажите обе даты периода';
    return;
  }
  if (dateFrom.value > dateTo.value) {
    error.value = 'Дата окончания раньше даты начала';
    return;
  }
  saving.value = true;
  notifyMode.value = notify;
  try {
    const res = await fetch('/api/so/admin/temp-schedule', {
      method: 'POST',
      headers: authHeaders({ 'Content-Type': 'application/json' }),
      body: JSON.stringify({
        supplier_id: props.supplier.id,
        date_from: dateFrom.value,
        date_to: dateTo.value,
        items: buildItems(),
        notify: notify ? 1 : 0,
      }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Ошибка сохранения');
    emit('saved', { notified: notify, ...data });
    emit('close');
  } catch (e) {
    error.value = e.message || 'Ошибка сохранения';
  } finally {
    saving.value = false;
    notifyMode.value = false;
  }
}

async function removePeriod() {
  if (!(await appConfirm('Удалить временный график? Расписание сразу вернётся к основному.', { okText: 'Удалить', danger: true }))) return;
  saving.value = true;
  error.value = '';
  try {
    const res = await fetch('/api/so/admin/temp-schedule', {
      method: 'POST',
      headers: authHeaders({ 'Content-Type': 'application/json' }),
      body: JSON.stringify({
        supplier_id: props.supplier.id,
        date_from: '',
        date_to: '',
        items: [],
      }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Ошибка удаления');
    emit('saved');
    emit('close');
  } catch (e) {
    error.value = e.message || 'Ошибка удаления';
  } finally {
    saving.value = false;
  }
}

function close() {
  if (saving.value) return;
  emit('close');
}

onMounted(load);
</script>

<style scoped>
.stsm-backdrop {
  position: fixed; inset: 0;
  background: rgba(15, 23, 42, 0.5);
  z-index: 1000;
  overflow-y: auto;
  display: flex; align-items: flex-start; justify-content: center;
  padding: 40px 16px;
}
.stsm-modal {
  background: #fff;
  border-radius: 14px;
  width: 100%;
  max-width: 920px;
  display: flex; flex-direction: column;
  box-shadow: 0 20px 50px rgba(0,0,0,0.25);
}
.stsm-head {
  padding: 18px 20px;
  border-bottom: 1px solid #e5e7eb;
  display: flex; justify-content: space-between; gap: 12px;
}
.stsm-title { margin: 0; font-size: 18px; font-weight: 700; color: #1f2937; }
.stsm-sub { margin-top: 4px; font-size: 13px; color: #6b7280; max-width: 700px; }
.stsm-x {
  background: none; border: none; font-size: 28px; line-height: 1;
  color: #9ca3af; cursor: pointer; padding: 0; width: 32px; height: 32px;
}
.stsm-x:hover { color: #1f2937; }

.stsm-body { padding: 18px 20px; display: flex; flex-direction: column; gap: 12px; max-height: 70vh; overflow-y: auto; }

.stsm-row { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
.stsm-field { display: flex; flex-direction: column; gap: 4px; min-width: 160px; }
.stsm-field > span { font-size: 13px; color: #374151; font-weight: 500; }
.stsm-field input {
  padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px;
  font-size: 14px; background: #fff; color: #1f2937;
}
.stsm-field input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
.stsm-stats { font-size: 13px; color: #6b7280; padding: 8px 0; }

.stsm-actions { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin-top: 6px; }

.stsm-rules-hint {
  display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
  padding: 8px 10px; background: #fffbeb; border: 1px solid #fde68a;
  border-radius: 8px; font-size: 12px; color: #78350f;
}
.stsm-rules-label { font-weight: 600; color: #92400e; }
.stsm-rule { display: inline-flex; gap: 4px; align-items: center; }
.stsm-rule b { color: #1f2937; }
.stsm-search { flex: 1; min-width: 200px; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; }

.stsm-state { padding: 24px; text-align: center; color: #6b7280; }
.stsm-error {
  background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c;
  padding: 10px 12px; border-radius: 8px; font-size: 13px;
}

.stsm-table-wrap { overflow-x: auto; border: 1px solid #e5e7eb; border-radius: 10px; }
.stsm-table { width: 100%; border-collapse: collapse; font-size: 13px; color: #1f2937; background: #fff; }
.stsm-table th, .stsm-table td { padding: 8px; border-bottom: 1px solid #f3f4f6; color: #1f2937; }
.stsm-table thead th { background: #f9fafb; color: #374151; font-weight: 600; text-align: center; }
.stsm-th-rest { text-align: left; min-width: 200px; color: #374151; }
.stsm-th-day { width: 50px; color: #374151; }
.stsm-td-rest { display: flex; flex-direction: column; gap: 2px; }
.stsm-num { font-weight: 600; color: #1f2937; }
.stsm-addr { font-size: 12px; color: #6b7280; }
.stsm-td-check { text-align: center; cursor: pointer; }
.stsm-td-check:hover { background: #f9fafb; }
.stsm-td-check input { width: 18px; height: 18px; cursor: pointer; }
.stsm-empty { padding: 24px; text-align: center; color: #9ca3af; }

.stsm-foot {
  padding: 14px 20px;
  border-top: 1px solid #e5e7eb;
  display: flex; justify-content: flex-end; gap: 8px;
}
.stsm-btn-ghost, .stsm-btn-primary, .stsm-btn-danger {
  padding: 9px 18px; border-radius: 8px; font-size: 14px; font-weight: 500;
  cursor: pointer; border: 1px solid transparent;
}
.stsm-btn-ghost { background: #fff; border-color: #d1d5db; color: #374151; }
.stsm-btn-ghost:hover:not(:disabled) { background: #f9fafb; }
.stsm-btn-primary { background: #1f2937; color: #fff; }
.stsm-btn-primary:hover:not(:disabled) { background: #111827; }
.stsm-btn-danger { background: #fff; border-color: #fecaca; color: #b91c1c; }
.stsm-btn-danger:hover:not(:disabled) { background: #fef2f2; }
.stsm-btn-accent { background: #2563eb; color: #fff; }
.stsm-btn-accent:hover:not(:disabled) { background: #1d4ed8; }
.stsm-btn-ghost:disabled, .stsm-btn-primary:disabled, .stsm-btn-danger:disabled, .stsm-btn-accent:disabled { opacity: 0.5; cursor: not-allowed; }

@media (max-width: 720px) {
  .stsm-backdrop { padding: 0; align-items: stretch; }
  .stsm-modal { border-radius: 0; max-width: none; min-height: 100vh; }
}
</style>
