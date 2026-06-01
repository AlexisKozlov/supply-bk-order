<template>
  <div class="tit-wrap">
    <header class="tit-header">
      <div class="tit-header-title">
        <h1>Заявка на пропуск</h1>
        <p>Подача транспорта поставщика на склад. Машины подтягиваются из ответов на email, можно добавить вручную.</p>
      </div>
      <div class="tit-header-actions">
        <button class="tit-btn ghost" @click="showSettings = true" title="Получатели охраны">⚙ Настройки</button>
        <button class="tit-btn ghost" @click="reload" :disabled="loading">↻ Обновить</button>
        <button class="tit-btn primary" @click="createQuick" :disabled="creating">{{ creating ? 'Создаём…' : '＋ Создать' }}</button>
      </div>
    </header>

    <div v-if="unmatchedCount > 0" class="tit-alert">
      📩 <b>{{ unmatchedCount }}</b> {{ pluralize(unmatchedCount, 'письмо', 'письма', 'писем') }} от поставщиков
      <span class="tit-alert-explain">— система нашла в них номер машины или скан, но не смогла привязать к конкретной заявке (поставщик ответил с другого ящика или новой темой). Откройте и выберите заявку — данные подтянутся.</span>
      <a href="#" @click.prevent="showUnmatched = true">Открыть и привязать</a>
    </div>

    <div class="tit-filters">
      <select v-model="filters.legal_entity_group" @change="reload">
        <option value="">Все юрлица</option>
        <option value="BK_VM">Бургер БК + Воглия Матта</option>
        <option value="PS">Пицца Стар</option>
      </select>
      <input type="date" v-model="filters.date_from" @change="reload" title="С даты" />
      <span class="tit-dash">—</span>
      <input type="date" v-model="filters.date_to" @change="reload" title="По дату" />
      <div class="tit-status-chips">
        <button v-for="s in statusChips" :key="s.value || 'all'"
                class="tit-chip" :class="{ on: filters.status === s.value }"
                @click="filters.status = s.value; reload()">
          {{ s.label }}<span v-if="counts[s.value] !== undefined" class="tit-chip-num">{{ counts[s.value] }}</span>
        </button>
      </div>
      <input v-model="filters.search" placeholder="Поиск: поставщик или номер машины" @input="debouncedReload" class="tit-search" />
    </div>

    <div v-if="loading && !rows.length" class="tit-empty"><span class="tit-spinner"></span> Загружаем…</div>
    <div v-else-if="error" class="tit-empty tit-error">⚠ {{ error }}</div>
    <div v-else-if="!rows.length" class="tit-empty">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#B0A090" stroke-width="1.5"><path d="M3 7h13l5 5v5a2 2 0 0 1-2 2H3z"/><circle cx="7" cy="19" r="2"/><circle cx="17" cy="19" r="2"/></svg>
      <h3>Заявок на пропуск пока нет</h3>
      <p>Они появятся автоматически, когда вы отправите заказ поставщику.</p>
    </div>

    <table v-else class="tit-table">
      <thead>
        <tr>
          <th>Поставщик</th>
          <th>Дата</th>
          <th>Машины</th>
          <th>Статус</th>
          <th>Обновлено</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in rows" :key="row.id" @click="openRequest(row.id)" class="tit-row" :class="{ 'tit-row-need': row.status === 'DATA_RECEIVED' }">
          <td>
            <div class="tit-supplier-name">{{ row.supplier_name || '(без названия)' }}</div>
            <div class="tit-supplier-le">{{ row.legal_entity }}</div>
          </td>
          <td>{{ formatDate(row.delivery_date) }}</td>
          <td>
            <span v-if="row.vehicles_count === 0" class="tit-machine-waiting">— ждём поставщика —</span>
            <span v-else>{{ row.vehicles_count }} {{ pluralize(row.vehicles_count, 'машина', 'машины', 'машин') }}<span v-if="row.needs_review_count > 0" class="tit-needs-review"> · {{ row.needs_review_count }} на проверку</span></span>
          </td>
          <td><span class="tit-status" :class="'tit-status-' + (row.status || '').toLowerCase()">{{ statusLabel(row.status) }}</span></td>
          <td class="tit-time">{{ relativeTime(row.updated_at) }}</td>
          <td><button class="tit-btn-link" @click.stop="openRequest(row.id)">Открыть →</button></td>
        </tr>
      </tbody>
    </table>

    <TitRequestModal v-if="editId" :id="editId" @close="onModalClose" @changed="reload" />
    <TitUnmatchedModal v-if="showUnmatched" @close="showUnmatched = false; reload()" />
    <TitSettingsModal v-if="showSettings" @close="showSettings = false" />
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, defineAsyncComponent } from 'vue';
import { db } from '@/lib/apiClient.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { appAlert } from '@/lib/appDialogs.js';

const orderStore = useOrderStore();

const TitRequestModal   = defineAsyncComponent(() => import('@/components/modals/TitRequestModal.vue'));
const TitUnmatchedModal = defineAsyncComponent(() => import('@/components/modals/TitUnmatchedModal.vue'));
const TitSettingsModal  = defineAsyncComponent(() => import('@/components/modals/TitSettingsModal.vue'));

const rows = ref([]);
const loading = ref(false);
const error = ref('');
const editId = ref(null);
const creating = ref(false);
const showUnmatched = ref(false);
const showSettings = ref(false);
const unmatchedCount = ref(0);

const filters = reactive({
  legal_entity_group: 'BK_VM',
  status: '',
  date_from: '',
  date_to: '',
  search: '',
});

const statusChips = [
  { value: '',                label: 'Все' },
  { value: 'WAITING',         label: 'Ждём поставщика' },
  { value: 'DATA_RECEIVED',   label: 'Получены данные' },
  { value: 'SENT',            label: 'Отправлено' },
];

const counts = computed(() => {
  const out = { '': rows.value.length };
  for (const r of rows.value) {
    out[r.status] = (out[r.status] || 0) + 1;
  }
  return out;
});

const statusLabel = (s) => ({
  WAITING: 'Ждём поставщика',
  DATA_RECEIVED: 'Получены данные',
  READY: 'Готово',
  SENT: 'Отправлено',
  CANCELLED: 'Отменена',
}[s] || s || '—');

const formatDate = (d) => d ? d.split('-').reverse().join('.') : '';
const pluralize = (n, one, few, many) => {
  const mod10 = n % 10, mod100 = n % 100;
  if (mod10 === 1 && mod100 !== 11) return one;
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return few;
  return many;
};
const relativeTime = (t) => {
  if (!t) return '';
  const ts = new Date(t.replace(' ', 'T')).getTime();
  const diff = (Date.now() - ts) / 1000;
  if (diff < 60) return 'только что';
  if (diff < 3600) return Math.floor(diff / 60) + ' мин назад';
  if (diff < 86400) return Math.floor(diff / 3600) + ' ч назад';
  return Math.floor(diff / 86400) + ' дн назад';
};

let reloadTimer = null;
const debouncedReload = () => {
  clearTimeout(reloadTimer);
  reloadTimer = setTimeout(reload, 350);
};

async function reload() {
  loading.value = true;
  error.value = '';
  try {
    const { data, error: e } = await db.rpc('tit_list', {
      legal_entity_group: filters.legal_entity_group || null,
      status: filters.status || null,
      date_from: filters.date_from || null,
      date_to: filters.date_to || null,
      search: filters.search || null,
    });
    if (e) throw new Error(e);
    rows.value = Array.isArray(data?.rows) ? data.rows : [];

    // unmatched count
    try {
      const { data: u } = await db.rpc('tit_unread_count', {});
      unmatchedCount.value = Number(u?.unmatched || 0);
    } catch (_) {}
  } catch (e) {
    error.value = e.message || 'Не удалось загрузить';
  } finally {
    loading.value = false;
  }
}

const openRequest = (id) => { editId.value = id; };
const onModalClose = () => { editId.value = null; };

async function createQuick() {
  if (creating.value) return;
  creating.value = true;
  try {
    // Юрлицо из сайдбара (то же что в orderStore.settings.legalEntity) —
    // самый ожидаемый для пользователя выбор. Группа подтягивается на бэке.
    const sidebarLE = orderStore.settings?.legalEntity || '';
    const { data, error: e } = await db.rpc('tit_create_quick', {
      legal_entity: sidebarLE,
      legal_entity_group: filters.legal_entity_group || null,
    });
    if (e || data?.error) throw new Error(e || data.error);
    if (!data?.id) throw new Error('Не получили id новой заявки');
    openRequest(data.id);
    reload();
  } catch (e) {
    await appAlert('Не удалось создать заявку: ' + (e.message || e), { type: 'error' });
  } finally {
    creating.value = false;
  }
}

onMounted(async () => {
  await reload();
});
</script>

<style scoped>
.tit-wrap { padding: 24px 28px 80px; max-width: 1280px; margin: 0 auto; color: var(--bk-brown, #502314); }

.tit-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
.tit-header-title h1 { margin: 0 0 4px; font-size: 24px; font-weight: 700; }
.tit-header-title p { margin: 0; font-size: 13px; color: #8C7B6E; max-width: 640px; }
.tit-header-actions { display: flex; gap: 8px; }

.tit-btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 18px; border-radius: 10px; border: 1.5px solid transparent; font-size: 14px; font-weight: 600; cursor: pointer; font-family: inherit; transition: transform .08s, background .15s; white-space: nowrap; }
.tit-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.tit-btn.primary { background: var(--bk-red, #E76F51); color: #fff; border-color: var(--bk-red, #E76F51); }
.tit-btn.primary:hover:not(:disabled) { background: var(--bk-red-dark, #C85A3E); }
.tit-btn.ghost { background: transparent; color: var(--bk-brown, #502314); border-color: #E5DDD3; }
.tit-btn.ghost:hover:not(:disabled) { background: #FFF8ED; border-color: #C9BBA8; }
.tit-btn-link { background: none; border: none; color: var(--bk-red, #E76F51); cursor: pointer; font-size: 13px; font-weight: 600; padding: 4px 8px; }


.tit-alert { background: #FFF8ED; border: 1.5px solid #F4A261; border-radius: 10px; padding: 12px 16px; margin-bottom: 14px; font-size: 14px; color: #6B4F00; line-height: 1.5; }
.tit-alert a { color: var(--bk-red, #E76F51); font-weight: 700; margin-left: 4px; white-space: nowrap; }
.tit-alert-explain { color: #8C7B6E; font-size: 13px; }

.tit-filters { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; background: #FFF8ED; border: 1px solid #EDE2D2; border-radius: 12px; padding: 12px; margin-bottom: 16px; }
.tit-filters select, .tit-filters input[type=date], .tit-search { height: 38px; border-radius: 8px; border: 1.5px solid #E5DDD3; padding: 0 12px; font-family: inherit; font-size: 13px; background: #fff; color: var(--bk-brown, #502314); }
.tit-search { flex: 1; min-width: 220px; }
.tit-dash { color: #B0A090; }

.tit-status-chips { display: flex; gap: 6px; flex-wrap: wrap; }
.tit-chip { background: #fff; border: 1.5px solid #E5DDD3; color: var(--bk-brown, #502314); padding: 8px 14px; border-radius: 999px; font-size: 13px; cursor: pointer; font-family: inherit; font-weight: 500; }
.tit-chip.on { background: var(--bk-brown, #502314); color: #fff; border-color: var(--bk-brown, #502314); }
.tit-chip-num { margin-left: 6px; background: rgba(255,255,255,.2); padding: 1px 6px; border-radius: 999px; font-size: 12px; }
.tit-chip:not(.on) .tit-chip-num { background: #F0E6D5; color: #6B4F00; }

.tit-table { width: 100%; border-collapse: separate; border-spacing: 0; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(80, 35, 20, 0.06); }
.tit-table thead th { background: var(--bk-brown, #502314); color: #fff; text-align: left; padding: 12px 14px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
.tit-row { transition: background .12s; cursor: pointer; }
.tit-row:hover { background: #FFF8ED; }
.tit-row.tit-row-need { background: #FFFAEF; }
.tit-row td { padding: 14px; border-top: 1px solid #F0E8DC; font-size: 14px; vertical-align: middle; }
.tit-supplier-name { font-weight: 600; color: var(--bk-brown, #502314); }
.tit-supplier-le { font-size: 12px; color: #8C7B6E; margin-top: 2px; }
.tit-machine-waiting { color: #B0A090; font-style: italic; }
.tit-needs-review { color: #B8860B; font-weight: 600; }
.tit-time { color: #8C7B6E; font-size: 13px; white-space: nowrap; }

.tit-status { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
.tit-status-waiting { background: #FFF3E0; color: #C05621; }
.tit-status-data_received { background: #E3F2FD; color: #1565C0; }
.tit-status-ready { background: #E8F5E9; color: #2E7D32; }
.tit-status-sent { background: #E0E0E0; color: #424242; }
.tit-status-cancelled { background: #FCE4EC; color: #AD1457; }

.tit-empty { text-align: center; padding: 60px 20px; color: #8C7B6E; background: #fff; border-radius: 12px; }
.tit-empty h3 { margin: 12px 0 4px; color: var(--bk-brown, #502314); font-size: 16px; }
.tit-empty p { margin: 0; font-size: 13px; }
.tit-empty.tit-error { color: #B91C1C; }

.tit-spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid #E5DDD3; border-top-color: var(--bk-red, #E76F51); border-radius: 50%; animation: tit-spin 0.8s linear infinite; vertical-align: middle; }
@keyframes tit-spin { to { transform: rotate(360deg); } }

@media (max-width: 720px) {
  .tit-wrap { padding: 16px 12px 80px; }
  .tit-header { flex-direction: column; }
  .tit-filters { padding: 8px; }
  .tit-table thead { display: none; }
  .tit-row { display: block; padding: 12px; border-bottom: 1px solid #F0E8DC; }
  .tit-row td { display: block; padding: 4px 0; border: none; }
  .tit-row td::before { content: attr(data-label); display: block; font-size: 11px; color: #8C7B6E; text-transform: uppercase; margin-bottom: 2px; }
}
</style>
