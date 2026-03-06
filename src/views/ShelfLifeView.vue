<template>
  <div class="sl-view">
    <!-- Header -->
    <div class="sl-header">
      <h1 class="page-title">Сроки годности</h1>
      <div class="sl-header-right">
        <!-- Тоггл юрлиц -->
        <div v-if="!loading && uniqueCustomers.length > 1" class="sl-tabs">
          <button class="sl-tab" :class="{ active: !filterCustomer }" @click="filterCustomer = ''">
            Все
          </button>
          <button
            v-for="c in uniqueCustomers" :key="c"
            class="sl-tab"
            :class="{ active: filterCustomer === c }"
            @click="filterCustomer = filterCustomer === c ? '' : c"
          >{{ shortName(c) }}</button>
        </div>
        <button
          v-if="userStore.hasAccess('shelf-life', 'edit')"
          class="sl-upload-btn"
          @click="handleUpload"
          :disabled="uploading"
        >
          <BkIcon name="send" size="sm"/>
          {{ uploading ? 'Загрузка...' : 'Загрузить файл' }}
        </button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="sl-loader">Загрузка...</div>

    <template v-if="!loading && allData.length">
      <!-- KPI row -->
      <div class="sl-kpi-row">
        <button class="sl-kpi" :class="['sl-kpi-red', { active: activeFilter === 'expired' }]" @click="toggleFilter('expired')">
          <span class="sl-kpi-val">{{ counts.expired }}</span>
          <span class="sl-kpi-label">Просрочено</span>
        </button>
        <button class="sl-kpi" :class="['sl-kpi-orange', { active: activeFilter === '7days' }]" @click="toggleFilter('7days')">
          <span class="sl-kpi-val">{{ counts.days7 }}</span>
          <span class="sl-kpi-label">&le; 7 дн</span>
        </button>
        <button class="sl-kpi" :class="['sl-kpi-yellow', { active: activeFilter === '14days' }]" @click="toggleFilter('14days')">
          <span class="sl-kpi-val">{{ counts.days14 }}</span>
          <span class="sl-kpi-label">8–14 дн</span>
        </button>
        <button class="sl-kpi" :class="['sl-kpi-blue', { active: activeFilter === '30days' }]" @click="toggleFilter('30days')">
          <span class="sl-kpi-val">{{ counts.days30 }}</span>
          <span class="sl-kpi-label">15–30 дн</span>
        </button>
      </div>

      <!-- Filters -->
      <div class="sl-filter-bar">
        <select v-model="filterWarehouse">
          <option value="">Все склады</option>
          <option v-for="w in uniqueWarehouses" :key="w" :value="w">{{ w }}</option>
        </select>
        <input v-model="searchQuery" type="text" placeholder="Поиск по товару..." class="sl-input"/>
        <button v-if="hasAnyFilter" class="sl-clear-btn" @click="clearFilters">&times; Сбросить</button>
        <span class="sl-meta" v-if="uploadedAt">
          {{ fmtDateTime(uploadedAt) }}<template v-if="uploadedBy"> &middot; {{ uploadedBy }}</template>
        </span>
      </div>

      <!-- Table -->
      <div class="sl-table-wrap">
        <table class="sl-table">
          <thead>
            <tr>
              <th class="sl-th sl-th-sort sl-th-center sl-col-name" @click="sortBy('product_name')">Товар <span class="sl-sort-icon">{{ sortIcon('product_name') }}</span></th>
              <th v-if="!filterCustomer" class="sl-th sl-th-sort sl-th-center" @click="sortBy('customer')">Заказчик <span class="sl-sort-icon">{{ sortIcon('customer') }}</span></th>
              <th class="sl-th sl-th-sort sl-th-center" @click="sortBy('warehouse')">Склад <span class="sl-sort-icon">{{ sortIcon('warehouse') }}</span></th>
              <th class="sl-th sl-th-sort sl-th-center" @click="sortBy('expiry_date')">Годен до <span class="sl-sort-icon">{{ sortIcon('expiry_date') }}</span></th>
              <th class="sl-th sl-th-sort sl-th-center sl-col-days" @click="sortBy('days_left')">Дней <span class="sl-sort-icon">{{ sortIcon('days_left') }}</span></th>
              <th class="sl-th sl-th-sort sl-th-center" @click="sortBy('quantity')">Кол-во <span class="sl-sort-icon">{{ sortIcon('quantity') }}</span></th>
              <th class="sl-th sl-th-center sl-col-status">Статус</th>
              <th v-if="showBlockColumn" class="sl-th sl-th-center">Блокировка</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in paginatedData"
              :key="row.id"
              class="sl-row"
              :class="rowCls(row)"
            >
              <td class="sl-td sl-td-name" :title="row.product_name">{{ row.product_name }}</td>
              <td v-if="!filterCustomer" class="sl-td">{{ row.customer }}</td>
              <td class="sl-td">{{ row.warehouse }}</td>
              <td class="sl-td sl-td-center">{{ fmtDate(row.expiry_date) }}</td>
              <td class="sl-td sl-td-center sl-td-days" :class="daysCls(row.days_left)">
                {{ row.days_left != null ? row.days_left : '\u2014' }}
              </td>
              <td class="sl-td sl-td-center">{{ fmtQty(row.quantity) }}</td>
              <td class="sl-td sl-td-center">
                <span class="sl-chip" :class="chipCls(row)">{{ chipText(row) }}</span>
              </td>
              <td v-if="showBlockColumn" class="sl-td sl-td-block" :title="row.block_reason || ''">{{ row.block_reason || '' }}</td>
            </tr>
          </tbody>
        </table>
        <div v-if="!filteredData.length" class="sl-empty-table">Нет данных по выбранным фильтрам</div>
        <div class="sl-table-footer">
          <span class="sl-shown">Показано {{ filteredData.length }} из {{ enrichedData.length }}</span>
          <div v-if="totalPages > 1" class="sl-pager">
            <button :disabled="currentPage <= 1" @click="currentPage--">&lsaquo;</button>
            <span>{{ currentPage }}/{{ totalPages }}</span>
            <button :disabled="currentPage >= totalPages" @click="currentPage++">&rsaquo;</button>
          </div>
        </div>
      </div>
    </template>

    <!-- Empty state -->
    <div v-if="!loading && !allData.length" class="sl-empty">
      <BkIcon name="shelfLife" size="lg"/>
      <p>Данные о сроках годности ещё не загружены</p>
      <button
        v-if="userStore.hasAccess('shelf-life', 'edit')"
        class="sl-upload-btn"
        @click="handleUpload"
      >Загрузить stock_mailing</button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { db } from '@/lib/apiClient.js';
import { useUserStore } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import BkIcon from '@/components/ui/BkIcon.vue';

const userStore = useUserStore();
const toastStore = useToastStore();

const allData = ref([]);
const loading = ref(true);
const uploading = ref(false);
const uploadedAt = ref(null);
const uploadedBy = ref('');

const filterCustomer = ref('');
const filterWarehouse = ref('');
const searchQuery = ref('');
const activeFilter = ref('');

const sortField = ref('days_left');
const sortAsc = ref(true);
const currentPage = ref(1);
const PAGE_SIZE = 100;

function getToday() {
  const d = new Date();
  d.setHours(0, 0, 0, 0);
  return d;
}

// ═══ Маппинг заказчиков ═══
const CUSTOMER_MAP = {
  'додо': 'Пицца Стар',
  'сбарро': 'Пицца Стар',
  'dodo': 'Пицца Стар',
  'sbarro': 'Пицца Стар',
  'бургер бк': 'Бургер БК',
};

function normalizeCustomer(raw) {
  if (!raw) return '';
  const lower = raw.trim().toLowerCase();
  for (const [key, val] of Object.entries(CUSTOMER_MAP)) {
    if (lower.includes(key)) return val;
  }
  return raw.trim();
}

// Маппинг складов на понятные названия
const WAREHOUSE_MAP = [
  { match: 'шабаны', name: 'Шабаны' },
  { match: 'прилесье 6', name: 'Сухой сток' },
  { match: 'прилесье 1 охлажд', name: 'Холод' },
  { match: 'прилесье 1 заморож', name: 'Мороз' },
  { match: 'прилесье 1', name: 'Холод' },
];

function normalizeWarehouse(raw) {
  if (!raw) return '';
  const lower = raw.trim().toLowerCase();
  for (const w of WAREHOUSE_MAP) {
    if (lower.includes(w.match)) return w.name;
  }
  return raw.trim();
}

function shortName(name) {
  if (!name) return '';
  if (name.length > 16) return name.slice(0, 15) + '\u2026';
  return name;
}

// ═══ Computed ═══

const enrichedData = computed(() =>
  allData.value.map(row => {
    let daysLeft = null;
    if (row.expiry_date) {
      const exp = new Date(row.expiry_date);
      exp.setHours(0, 0, 0, 0);
      daysLeft = Math.floor((exp - getToday()) / 86400000);
    }
    return { ...row, customer: normalizeCustomer(row.customer), warehouse: normalizeWarehouse(row.warehouse), days_left: daysLeft };
  })
);

const counts = computed(() => {
  let expired = 0, days7 = 0, days14 = 0, days30 = 0;
  let base = enrichedData.value;
  if (filterCustomer.value) base = base.filter(r => r.customer === filterCustomer.value);
  if (filterWarehouse.value) base = base.filter(r => r.warehouse === filterWarehouse.value);
  for (const r of base) {
    if (r.days_left == null) continue;
    if (r.days_left < 0) expired++;
    else if (r.days_left <= 7) days7++;
    else if (r.days_left <= 14) days14++;
    else if (r.days_left <= 30) days30++;
  }
  return { expired, days7, days14, days30 };
});

const uniqueCustomers = computed(() => {
  const set = new Set(enrichedData.value.map(r => r.customer).filter(Boolean));
  return [...set].sort();
});

const uniqueWarehouses = computed(() => {
  // Склады зависят от выбранного заказчика
  const base = filterCustomer.value
    ? enrichedData.value.filter(r => r.customer === filterCustomer.value)
    : enrichedData.value;
  const set = new Set(base.map(r => r.warehouse).filter(Boolean));
  return [...set].sort();
});

const hasAnyFilter = computed(() =>
  filterCustomer.value || filterWarehouse.value || searchQuery.value || activeFilter.value
);

const showBlockColumn = computed(() => filteredData.value.some(r => r.block_reason));

const filteredData = computed(() => {
  let data = enrichedData.value;
  if (filterCustomer.value) data = data.filter(r => r.customer === filterCustomer.value);
  if (filterWarehouse.value) data = data.filter(r => r.warehouse === filterWarehouse.value);
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase();
    data = data.filter(r => (r.product_name || '').toLowerCase().includes(q));
  }
  if (activeFilter.value === 'expired') data = data.filter(r => r.days_left != null && r.days_left < 0);
  else if (activeFilter.value === '7days') data = data.filter(r => r.days_left != null && r.days_left >= 0 && r.days_left <= 7);
  else if (activeFilter.value === '14days') data = data.filter(r => r.days_left != null && r.days_left >= 8 && r.days_left <= 14);
  else if (activeFilter.value === '30days') data = data.filter(r => r.days_left != null && r.days_left >= 15 && r.days_left <= 30);

  const field = sortField.value;
  const asc = sortAsc.value ? 1 : -1;
  return [...data].sort((a, b) => {
    let va = a[field], vb = b[field];
    if (va == null) va = field === 'days_left' ? 99999 : '';
    if (vb == null) vb = field === 'days_left' ? 99999 : '';
    if (typeof va === 'string') return va.localeCompare(vb, 'ru') * asc;
    return (va - vb) * asc;
  });
});

const totalPages = computed(() => Math.max(1, Math.ceil(filteredData.value.length / PAGE_SIZE)));
const paginatedData = computed(() => {
  const s = (currentPage.value - 1) * PAGE_SIZE;
  return filteredData.value.slice(s, s + PAGE_SIZE);
});

watch([filterCustomer, filterWarehouse, searchQuery, activeFilter], () => { currentPage.value = 1; });

// ═══ Methods ═══

function toggleFilter(f) { activeFilter.value = activeFilter.value === f ? '' : f; }

function clearFilters() {
  filterCustomer.value = '';
  filterWarehouse.value = '';
  searchQuery.value = '';
  activeFilter.value = '';
}

function sortBy(field) {
  if (sortField.value === field) sortAsc.value = !sortAsc.value;
  else { sortField.value = field; sortAsc.value = field === 'days_left'; }
  currentPage.value = 1;
}

function sortIcon(field) {
  if (sortField.value !== field) return '';
  return sortAsc.value ? '\u25B2' : '\u25BC';
}

function rowCls(row) {
  if (row.days_left == null) return '';
  if (row.days_left < 0) return 'sl-row-red';
  if (row.days_left <= 7) return 'sl-row-orange';
  if (row.days_left <= 14) return 'sl-row-yellow';
  if (row.days_left <= 30) return 'sl-row-blue';
  return '';
}

function daysCls(d) {
  if (d == null) return '';
  if (d < 0) return 'sl-c-red';
  if (d <= 7) return 'sl-c-orange';
  if (d <= 14) return 'sl-c-amber';
  if (d <= 30) return 'sl-c-blue';
  return 'sl-c-green';
}

function chipCls(row) {
  if (row.days_left != null && row.days_left < 0) return 'sl-chip-red';
  if (row.days_left != null && row.days_left <= 7) return 'sl-chip-orange';
  if (row.expiry_status === 'Просрочен') return 'sl-chip-red';
  return 'sl-chip-green';
}

function chipText(row) {
  if (row.days_left != null && row.days_left < 0) return 'Просрочен';
  if (row.expiry_status) return row.expiry_status;
  return 'Годен';
}

function fmtDateTime(d) {
  if (!d) return '';
  const dt = new Date(d);
  return dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ' ' +
         dt.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

function fmtDate(d) {
  if (!d) return '\u2014';
  return new Date(d).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' });
}

function fmtQty(v) {
  if (v == null) return '\u2014';
  const n = +v;
  return isNaN(n) ? '\u2014' : n.toLocaleString('ru-RU', { maximumFractionDigits: 2 });
}

// ═══ Data loading ═══

async function loadData() {
  loading.value = true;
  try {
    const { data, error } = await db.from('stock_malling').select('*');
    if (error) throw error;
    allData.value = data || [];
    if (allData.value.length) {
      let latest = null;
      for (const r of allData.value) {
        if (r.uploaded_at && (!latest || new Date(r.uploaded_at) > new Date(latest.uploaded_at))) latest = r;
      }
      if (latest) { uploadedAt.value = latest.uploaded_at; uploadedBy.value = latest.uploaded_by || ''; }
    }
  } catch (e) {
    console.error('[ShelfLife]', e);
    toastStore.error('Не удалось загрузить данные');
  } finally { loading.value = false; }
}

// ═══ XLSX upload & parse ═══

async function handleUpload() {
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = '.xlsx,.xls';
  input.addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    uploading.value = true;
    try {
      const items = await parseStockMailing(file);
      if (!items.length) { toastStore.error('Не удалось распознать данные в файле'); return; }
      const userName = userStore.currentUser?.name || '';
      const d = new Date();
      const pad = n => String(n).padStart(2, '0');
      const now = `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
      const payload = items.map(item => ({ ...item, uploaded_at: now, uploaded_by: userName }));
      const { data, error } = await db.rpc('replace_stock_malling', { items: payload });
      if (error) throw error;
      toastStore.success(`Загружено ${data?.count || items.length} позиций`);
      await loadData();
    } catch (err) {
      console.error('[ShelfLife]', err);
      toastStore.error('Ошибка: ' + (err.message || 'неизвестная ошибка'));
    } finally { uploading.value = false; }
  });
  input.click();
}

async function parseStockMailing(file) {
  const XLSX = await import('xlsx-js-style');
  const buffer = await file.arrayBuffer();
  const wb = XLSX.read(buffer, { type: 'array', cellDates: true });
  const ws = wb.Sheets[wb.SheetNames[0]];

  let maxRow = 0, maxCol = 0;
  for (const key of Object.keys(ws).filter(k => !k.startsWith('!'))) {
    const cell = XLSX.utils.decode_cell(key);
    if (cell.r > maxRow) maxRow = cell.r;
    if (cell.c > maxCol) maxCol = cell.c;
  }

  const rows = [];
  for (let r = 0; r <= maxRow; r++) {
    const row = [];
    for (let c = 0; c <= maxCol; c++) {
      const cell = ws[XLSX.utils.encode_cell({ r, c })];
      row.push(cell ? (cell.v !== undefined ? cell.v : '') : '');
    }
    rows.push(row);
  }

  const keywords = ['заказчик', 'склад', 'наименование', 'годен', 'дата производства', 'блокировк', 'остаток', 'статус'];
  let headerIdx = -1;
  for (let i = 0; i < Math.min(rows.length, 15); i++) {
    const cells = rows[i].map(c => String(c ?? '').toLowerCase().trim());
    if (cells.filter(cell => cell && keywords.some(kw => cell.includes(kw))).length >= 3) { headerIdx = i; break; }
  }
  if (headerIdx < 0) return [];

  const headers = rows[headerIdx].map(h => String(h ?? '').toLowerCase().trim());
  const findCol = (kws) => { for (const kw of kws) { const i = headers.findIndex(h => h.includes(kw)); if (i >= 0) return i; } return -1; };

  const colMap = {
    customer: findCol(['заказчик', 'покупатель', 'клиент']),
    warehouse: findCol(['склад']),
    product_name: findCol(['наименование', 'номенклатура', 'товар', 'продукт']),
    production_date: findCol(['дата производства', 'дата выработки', 'дата изготовления']),
    expiry_date: findCol(['годен до', 'срок годности', 'дата окончания']),
    block_reason: findCol(['причина блокировк', 'блокировк']),
    expiry_status: findCol(['статус годности', 'статус годн', 'статус']),
    quantity: findCol(['остатки', 'остаток', 'количество', 'кол-во', 'кол.']),
  };

  if (colMap.product_name < 0) return [];

  const parseDate = (val) => {
    if (!val) return null;
    if (val instanceof Date) return isNaN(val.getTime()) ? null : val.toISOString().slice(0, 10);
    const s = String(val).trim();
    const m = s.match(/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/);
    if (m) return `${m[3]}-${m[2].padStart(2,'0')}-${m[1].padStart(2,'0')}`;
    const m2 = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (m2) return `${m2[1]}-${m2[2]}-${m2[3]}`;
    return null;
  };

  const parseNum = (val) => {
    if (val == null || val === '') return 0;
    const n = parseFloat(String(val).replace(/\s/g, '').replace(',', '.'));
    return isNaN(n) ? 0 : Math.round(n * 100) / 100;
  };

  const items = [];
  for (let i = headerIdx + 1; i < rows.length; i++) {
    const row = rows[i];
    const name = colMap.product_name >= 0 ? String(row[colMap.product_name] ?? '').trim() : '';
    if (!name) continue;
    const rawCustomer = colMap.customer >= 0 ? String(row[colMap.customer] ?? '').trim() : '';
    items.push({
      customer: normalizeCustomer(rawCustomer),
      warehouse: normalizeWarehouse(colMap.warehouse >= 0 ? String(row[colMap.warehouse] ?? '').trim() : ''),
      product_name: name,
      production_date: colMap.production_date >= 0 ? parseDate(row[colMap.production_date]) : null,
      expiry_date: colMap.expiry_date >= 0 ? parseDate(row[colMap.expiry_date]) : null,
      block_reason: colMap.block_reason >= 0 ? String(row[colMap.block_reason] ?? '').trim() || null : null,
      expiry_status: colMap.expiry_status >= 0 ? String(row[colMap.expiry_status] ?? '').trim() : '',
      quantity: colMap.quantity >= 0 ? parseNum(row[colMap.quantity]) : 0,
    });
  }

  // Агрегация
  const agg = new Map();
  for (const item of items) {
    const key = [item.product_name, item.customer, item.warehouse, item.production_date, item.expiry_date].join('||');
    if (agg.has(key)) agg.get(key).quantity += item.quantity;
    else agg.set(key, { ...item });
  }
  return Array.from(agg.values());
}

onMounted(loadData);
</script>

<style scoped>
/* ═══ Page ═══ */
.sl-view { }

/* ═══ Header ═══ */
.sl-header {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 8px; margin-bottom: 12px;
}
.sl-header-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

/* ═══ Tabs (entity toggle) — стиль как pf-tabs ═══ */
.sl-tabs {
  display: inline-flex; border: 1.5px solid var(--border); border-radius: 8px;
  overflow: hidden; background: var(--bg);
}
.sl-tab {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 6px 14px; border: none; background: transparent;
  font-size: 12px; font-weight: 600; font-family: inherit;
  color: var(--text-muted); cursor: pointer; transition: all 0.15s;
  white-space: nowrap; line-height: 1;
  border-right: 1.5px solid var(--border);
}
.sl-tab:last-child { border-right: none; }
.sl-tab:hover { color: var(--text); background: rgba(0,0,0,0.02); }
.sl-tab.active {
  background: var(--bk-orange); color: white;
  box-shadow: 0 1px 4px rgba(245,166,35,0.3);
}

/* ═══ Upload button ═══ */
.sl-upload-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 14px; border-radius: 8px; border: 1.5px solid var(--bk-orange);
  background: transparent; color: var(--bk-orange);
  font-size: 12px; font-weight: 600; font-family: inherit;
  cursor: pointer; transition: all 0.15s; white-space: nowrap;
}
.sl-upload-btn:hover { background: var(--bk-orange); color: #fff; }
.sl-upload-btn:disabled { opacity: 0.4; cursor: not-allowed; }

/* ═══ Loader ═══ */
.sl-loader { text-align: center; padding: 60px 20px; color: var(--text-muted); font-size: 14px; }

/* ═══ KPI row ═══ */
.sl-kpi-row { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
.sl-kpi {
  flex: 1; min-width: 90px; display: flex; flex-direction: column; align-items: center;
  padding: 12px 8px; border-radius: 10px; border: 2px solid transparent;
  cursor: pointer; transition: all 0.15s; background: var(--card); font-family: inherit;
}
.sl-kpi:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }
.sl-kpi.active { border-color: var(--text); }
.sl-kpi-val { font-size: 24px; font-weight: 800; line-height: 1; }
.sl-kpi-label { font-size: 10px; font-weight: 600; color: var(--text-muted); margin-top: 4px; text-transform: uppercase; letter-spacing: 0.3px; }

.sl-kpi-red { background: #FFF5F5; } .sl-kpi-red .sl-kpi-val { color: #D32F2F; }
.sl-kpi-orange { background: #FFF8F0; } .sl-kpi-orange .sl-kpi-val { color: #E65100; }
.sl-kpi-yellow { background: #FFFDF0; } .sl-kpi-yellow .sl-kpi-val { color: #F57F17; }
.sl-kpi-blue { background: #F0F6FF; } .sl-kpi-blue .sl-kpi-val { color: #1565C0; }

/* ═══ Filter bar ═══ */
.sl-filter-bar {
  display: flex; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; align-items: center;
}
.sl-filter-bar select {
  padding: 6px 10px; border: 1px solid var(--border); border-radius: 6px;
  font-size: 12px; font-weight: 600; font-family: inherit; color: var(--text);
  background: white; cursor: pointer;
}
.sl-filter-bar select:focus { outline: none; border-color: var(--bk-orange); box-shadow: 0 0 0 2px rgba(232,122,30,0.15); }
.sl-input {
  padding: 6px 10px; border: 1px solid var(--border); border-radius: 6px;
  font-size: 12px; font-family: inherit; color: var(--text);
  background: white; flex: 1; min-width: 140px;
}
.sl-input:focus { outline: none; border-color: var(--bk-orange); box-shadow: 0 0 0 2px rgba(232,122,30,0.15); }
.sl-clear-btn {
  padding: 5px 10px; border: 1px solid var(--border); border-radius: 6px;
  background: white; font-size: 11px; font-weight: 600; font-family: inherit;
  cursor: pointer; color: var(--text-muted); transition: all 0.15s;
}
.sl-clear-btn:hover { border-color: var(--error); color: var(--error); }
.sl-meta { font-size: 11px; color: var(--text-muted); margin-left: auto; white-space: nowrap; }

/* ═══ Table ═══ */
.sl-table-wrap {
  overflow-x: auto; border-radius: 10px; border: 1px solid var(--border); background: white;
}
.sl-table { width: 100%; border-collapse: collapse; font-size: 13px; }

/* Header */
.sl-th {
  padding: 8px 10px; text-align: left; font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.4px; color: var(--text-muted);
  background: var(--bg); border-bottom: 2px solid var(--border);
  white-space: nowrap; position: sticky; top: 0; z-index: 1;
}
.sl-th-sort { cursor: pointer; user-select: none; }
.sl-th-sort:hover { color: var(--text); }
.sl-th-center { text-align: center; }
.sl-sort-icon { font-size: 10px; opacity: 0.5; margin-left: 2px; }
.sl-col-name { min-width: 200px; }
.sl-col-days { width: 60px; }
.sl-col-status { width: 85px; }

/* Rows */
.sl-row {
  transition: background 0.1s;
  border-bottom: 1px solid var(--border-light);
  border-left: 3px solid transparent;
}
.sl-row:hover { background: #FFFBF5; }
.sl-row:last-child { border-bottom: none; }
.sl-row-red { border-left-color: #D32F2F; background: #FFF8F8; }
.sl-row-red:hover { background: #FFF0F0; }
.sl-row-orange { border-left-color: #E65100; background: #FFFAF5; }
.sl-row-orange:hover { background: #FFF5EC; }
.sl-row-yellow { border-left-color: #F9A825; background: #FFFEF5; }
.sl-row-yellow:hover { background: #FFFCEC; }
.sl-row-blue { border-left-color: #1976D2; background: #F8FBFF; }
.sl-row-blue:hover { background: #F0F6FF; }

/* Cells */
.sl-td { padding: 7px 10px; vertical-align: middle; color: var(--text); }
.sl-td-name { font-weight: 600; color: var(--bk-brown); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 320px; text-align: left; }
.sl-td-center { text-align: center; font-size: 12px; white-space: nowrap; }
.sl-td-days { font-weight: 800; font-size: 13px; }
.sl-td-block { font-size: 11px; color: var(--text-muted); max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* Days colors */
.sl-c-red { color: #D32F2F; }
.sl-c-orange { color: #E65100; }
.sl-c-amber { color: #F57F17; }
.sl-c-blue { color: #1565C0; }
.sl-c-green { color: #2E7D32; }

/* ═══ Chips ═══ */
.sl-chip {
  display: inline-flex; align-items: center; gap: 3px;
  padding: 3px 8px; border-radius: 6px;
  font-size: 10px; font-weight: 700; line-height: 1;
}
.sl-chip-red { background: #FFEBEE; color: #C62828; }
.sl-chip-orange { background: #FFF3E0; color: #BF360C; }
.sl-chip-green { background: #E8F5E9; color: #2E7D32; }

/* ═══ Footer ═══ */
.sl-table-footer {
  display: flex; align-items: center; justify-content: space-between;
  padding: 8px 12px; border-top: 1px solid var(--border-light);
}
.sl-shown { font-size: 11px; color: var(--text-muted); }
.sl-pager { display: flex; align-items: center; gap: 6px; }
.sl-pager button {
  width: 26px; height: 26px; border-radius: 6px; border: 1px solid var(--border);
  background: white; cursor: pointer; font-size: 16px; font-weight: 600;
  color: var(--text); display: flex; align-items: center; justify-content: center;
  font-family: inherit; transition: all 0.12s;
}
.sl-pager button:hover:not(:disabled) { background: var(--bg); border-color: var(--bk-orange); }
.sl-pager button:disabled { opacity: 0.3; cursor: not-allowed; }
.sl-pager span { font-size: 11px; color: var(--text-muted); }

/* ═══ Empty ═══ */
.sl-empty-table { text-align: center; padding: 40px 20px; color: var(--text-muted); font-size: 13px; }
.sl-empty {
  display: flex; flex-direction: column; align-items: center;
  gap: 12px; padding: 80px 20px; color: var(--text-muted);
}
.sl-empty p { font-size: 14px; margin: 0; }

/* ═══ Mobile ═══ */
@media (max-width: 768px) {
  .sl-header { flex-direction: column; align-items: flex-start; }
  .sl-header-right { width: 100%; justify-content: space-between; }
  .sl-kpi { min-width: 70px; padding: 10px 6px; }
  .sl-kpi-val { font-size: 20px; }
  .sl-td-block { display: none; }
}
@media (max-width: 480px) {
  .sl-tabs { font-size: 11px; }
  .sl-tab { padding: 5px 10px; }
  .sl-kpi-val { font-size: 18px; }
  .sl-kpi-label { font-size: 9px; }
}
</style>
