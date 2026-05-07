<template>
  <div class="krs-page">
    <div class="krs-header">
      <div>
        <router-link :to="{ name: 'keg-returns' }" class="krs-back">← К заявкам</router-link>
        <h1>График возврата кег</h1>
        <p class="krs-sub">Дни приёма, адрес погрузки и доступ к модулю — по каждому ресторану.</p>
      </div>
      <div class="krs-global">
        <span class="krs-global-label">Возврат кег включён глобально</span>
        <button
          class="krs-switch"
          :class="{ on: globalEnabled }"
          :disabled="globalSaving"
          @click="toggleGlobal"
          :title="globalEnabled ? 'Выключить для всех ресторанов' : 'Включить'"
        >
          <span></span>
        </button>
      </div>
    </div>

    <div v-if="loading" class="krs-empty">Загрузка...</div>
    <div v-else-if="!rows.length" class="krs-empty">Нет ресторанов BK_VM.</div>
    <template v-else>
      <div class="krs-toolbar">
        <input v-model="search" type="search" class="krs-search" placeholder="Поиск по номеру, городу или адресу..." />
        <span class="krs-counter">{{ filteredRows.length }} из {{ rows.length }}</span>
      </div>

      <div class="krs-table-wrap">
        <table class="krs-table">
          <thead>
            <tr>
              <th class="krs-th-num">№</th>
              <th class="krs-th-rest">Ресторан</th>
              <th class="krs-th-addr">Адрес погрузки</th>
              <th v-for="(d, i) in WEEKDAYS" :key="d" class="krs-th-day" :title="WEEKDAY_FULL[i]">{{ d }}</th>
              <th class="krs-th-enable">Возврат</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in filteredRows" :key="row.id" :class="{ 'krs-row-off': !row.keg_returns_enabled }">
              <td class="krs-td-num">{{ row.number }}</td>
              <td class="krs-td-rest">
                <div class="krs-cell-city">{{ row.city }}</div>
                <div class="krs-cell-addr">{{ row.address }}</div>
              </td>
              <td class="krs-td-addr">
                <input
                  v-model="row.pickup_address"
                  type="text"
                  class="krs-input"
                  placeholder="напр. г. Минск, ул. Притыцкого, 154"
                  @blur="saveRow(row, 'pickup_address')"
                  :disabled="!row.keg_returns_enabled"
                />
              </td>
              <td v-for="(d, i) in WEEKDAYS" :key="d" class="krs-td-day">
                <label class="krs-day">
                  <input
                    type="checkbox"
                    :checked="!!(row.pickup_weekdays & (1 << i))"
                    :disabled="!row.keg_returns_enabled"
                    @change="toggleDay(row, i)"
                  />
                </label>
              </td>
              <td class="krs-td-enable">
                <button
                  class="krs-switch sm"
                  :class="{ on: row.keg_returns_enabled }"
                  @click="toggleRow(row)"
                  :title="row.keg_returns_enabled ? 'Выключить' : 'Включить'"
                >
                  <span></span>
                </button>
              </td>
            </tr>
            <tr v-if="!filteredRows.length">
              <td :colspan="11" class="krs-no-match">Под поиск не нашли ничего</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { db } from '@/lib/apiClient.js';
import { useToastStore } from '@/stores/toastStore.js';

const toast = useToastStore();
const loading = ref(false);
const rows = ref([]);
const globalEnabled = ref(true);
const globalSaving = ref(false);
const search = ref('');

const WEEKDAYS = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
const WEEKDAY_FULL = ['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье'];

const filteredRows = computed(() => {
  const q = search.value.trim().toLowerCase();
  if (!q) return rows.value;
  return rows.value.filter(r =>
    String(r.number).includes(q) ||
    (r.city || '').toLowerCase().includes(q) ||
    (r.address || '').toLowerCase().includes(q) ||
    (r.pickup_address || '').toLowerCase().includes(q)
  );
});

async function loadSchedule() {
  loading.value = true;
  try {
    const { data, error } = await db.rpc('kr_get_schedule', {});
    if (error) throw new Error(error.message || 'Ошибка загрузки');
    globalEnabled.value = !!data.global_enabled;
    rows.value = (data.restaurants || []).map(r => ({
      ...r,
      pickup_weekdays: parseInt(r.pickup_weekdays || 0),
      keg_returns_enabled: parseInt(r.keg_returns_enabled || 0) === 1,
    }));
  } catch (e) {
    toast.error('Не удалось загрузить график', e.message || '');
  } finally {
    loading.value = false;
  }
}

async function saveRow(row, only) {
  try {
    const payload = { id: row.id };
    if (!only || only === 'pickup_address') payload.pickup_address = row.pickup_address || '';
    if (!only || only === 'pickup_weekdays') payload.pickup_weekdays = parseInt(row.pickup_weekdays || 0);
    if (!only || only === 'keg_returns_enabled') payload.keg_returns_enabled = !!row.keg_returns_enabled;
    const { error } = await db.rpc('kr_save_schedule_row', payload);
    if (error) throw new Error(error.message || 'Ошибка сохранения');
  } catch (e) {
    toast.error('Не удалось сохранить', e.message || '');
  }
}

function toggleDay(row, bitIdx) {
  const cur = parseInt(row.pickup_weekdays || 0);
  const next = cur ^ (1 << bitIdx);
  row.pickup_weekdays = next;
  saveRow(row, 'pickup_weekdays');
}

function toggleRow(row) {
  row.keg_returns_enabled = !row.keg_returns_enabled;
  saveRow(row, 'keg_returns_enabled');
}

async function toggleGlobal() {
  globalSaving.value = true;
  const next = !globalEnabled.value;
  try {
    const { data, error } = await db.rpc('kr_set_module_enabled', { enabled: next });
    if (error) throw new Error(error.message || 'Ошибка');
    globalEnabled.value = !!data.enabled;
    toast.success(globalEnabled.value ? 'Возврат кег включён' : 'Возврат кег выключен', '');
  } catch (e) {
    toast.error('Не удалось сохранить', e.message || '');
  } finally {
    globalSaving.value = false;
  }
}

onMounted(loadSchedule);
</script>

<style scoped>
.krs-page { max-width: 1200px; margin: 0 auto; padding: 20px; }
.krs-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 18px; flex-wrap: wrap; margin-bottom: 18px;
}
.krs-back {
  display: inline-block; margin-bottom: 6px;
  font-size: 13px; color: #6B5344; text-decoration: none;
}
.krs-back:hover { color: #E76F51; }
.krs-header h1 { margin: 0 0 4px; font-size: 22px; color: #2C1A12; font-weight: 700; }
.krs-sub { margin: 0; color: #8B7355; font-size: 13.5px; }
.krs-global {
  display: flex; align-items: center; gap: 12px;
  background: #fff; border: 1px solid #ECE3D6; border-radius: 12px;
  padding: 10px 14px;
}
.krs-global-label { font-size: 13px; font-weight: 600; color: #2C1A12; }

.krs-switch {
  width: 46px; height: 26px; border-radius: 999px;
  background: #ECE3D6; border: none; padding: 3px;
  cursor: pointer; position: relative;
  transition: background .15s;
}
.krs-switch span {
  display: block; width: 20px; height: 20px; border-radius: 50%;
  background: #fff;
  box-shadow: 0 2px 6px rgba(0,0,0,.15);
  transition: transform .18s;
}
.krs-switch.on { background: #43A047; }
.krs-switch.on span { transform: translateX(20px); }
.krs-switch.sm { width: 40px; height: 22px; }
.krs-switch.sm span { width: 16px; height: 16px; }
.krs-switch.sm.on span { transform: translateX(18px); }
.krs-switch:disabled { opacity: 0.5; cursor: not-allowed; }

.krs-toolbar {
  display: flex; gap: 12px; align-items: center;
  margin-bottom: 12px;
}
.krs-search {
  flex: 1; max-width: 360px;
  padding: 9px 12px; border-radius: 10px;
  border: 1.5px solid #E8DCC8; background: #fff;
  font: inherit; font-size: 14px;
}
.krs-search:focus { outline: none; border-color: #E76F51; box-shadow: 0 0 0 3px rgba(231,111,81,.12); }
.krs-counter { font-size: 12px; color: #8B7355; font-weight: 600; }

.krs-table-wrap {
  background: #fff; border: 1px solid #ECE3D6; border-radius: 12px;
  overflow-x: auto;
}
.krs-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.krs-table thead { background: #502314; }
.krs-table thead th {
  color: #fff; padding: 10px 8px; font-weight: 600; font-size: 12px;
  text-align: center; white-space: nowrap;
}
.krs-th-rest, .krs-th-addr { text-align: left !important; padding-left: 14px !important; }
.krs-th-num { width: 50px; }
.krs-th-rest { min-width: 220px; }
.krs-th-addr { min-width: 280px; }
.krs-th-day { width: 50px; }
.krs-th-enable { width: 80px; }

.krs-table tbody tr {
  border-bottom: 1px solid #F2EDE8;
  transition: background .12s;
}
.krs-table tbody tr:nth-child(even) { background: #FEFBF7; }
.krs-table tbody tr:hover { background: #FFF8F0; }
.krs-row-off { opacity: 0.5; }
.krs-table tbody td { padding: 8px 8px; vertical-align: middle; }

.krs-td-num {
  font-weight: 700; color: #502314; text-align: center; font-size: 14px;
}
.krs-td-rest { padding-left: 14px !important; }
.krs-cell-city { font-weight: 700; color: #2C1A12; font-size: 13.5px; }
.krs-cell-addr { color: #8B7355; font-size: 12px; line-height: 1.3; margin-top: 1px; }
.krs-td-addr { padding: 4px 8px !important; }
.krs-input {
  width: 100%; padding: 8px 10px; border-radius: 8px;
  border: 1.5px solid #E8DCC8; background: #fff;
  font: inherit; font-size: 13px; color: #2C1A12;
  transition: border-color .15s;
}
.krs-input:focus { outline: none; border-color: #E76F51; box-shadow: 0 0 0 3px rgba(231,111,81,.12); }
.krs-input:disabled { background: #F7F2EB; cursor: not-allowed; }

.krs-td-day { text-align: center; }
.krs-day { display: inline-block; cursor: pointer; }
.krs-day input { width: 18px; height: 18px; cursor: pointer; accent-color: #E76F51; }
.krs-day input:disabled { cursor: not-allowed; }

.krs-td-enable { text-align: center; }

.krs-no-match { padding: 20px; text-align: center; color: #8B7355; }
.krs-empty {
  background: #fff; border: 1px solid #ECE3D6; border-radius: 12px;
  padding: 40px; text-align: center; color: #8B7355;
}

@media (max-width: 768px) {
  .krs-header { flex-direction: column; align-items: stretch; }
  .krs-th-day { width: 38px; padding: 8px 4px !important; font-size: 11px; }
  .krs-th-num { width: 40px; }
  .krs-th-enable { width: 60px; }
  .krs-day input { width: 16px; height: 16px; }
}
</style>
