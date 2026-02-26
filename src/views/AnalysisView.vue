<template>
  <div class="analysis-view" :class="{ 'anv-compact': compactMode }">
    <!-- Header -->
    <div class="an-header">
      <h1 class="page-title">Анализ запасов</h1>
      <div class="anv-controls">
        <div class="anv-control">
          <label>Поставщик</label>
          <select v-model="filterSupplier">
            <option value="">Все</option>
            <option v-for="s in uniqueSuppliers" :key="s" :value="s">{{ s }}</option>
          </select>
        </div>
        <div class="anv-control">
          <label>Статус</label>
          <select v-model="filterStatus">
            <option value="">Все</option>
            <option value="red">&lt;3 дн.</option>
            <option value="orange">&lt;7 дн.</option>
            <option value="yellow">&lt;14 дн.</option>
            <option value="green">14–30 дн.</option>
            <option value="purple">30+ дн.</option>
          </select>
        </div>
        <div class="anv-control">
          <label>Расход за</label>
          <select v-model.number="periodDays">
            <option :value="7">7 дней</option>
            <option :value="14">14 дней</option>
            <option :value="21">21 день</option>
            <option :value="30">30 дней</option>
            <option :value="60">60 дней</option>
            <option :value="90">90 дней</option>
          </select>
        </div>
        <div class="anv-control">
          <label>Единицы</label>
          <select v-model="unit">
            <option value="pieces">Штуки</option>
            <option value="boxes">Коробки</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Тулбар -->
    <div class="anv-toolbar" v-if="items.length">
      <button v-if="!isViewer" class="btn small" @click="doImport" :disabled="importLoading || savingData">
        <BkIcon v-if="importLoading" name="loading" size="sm"/>
        <BkIcon v-else name="import" size="sm"/> Импорт
      </button>
      <button v-if="!isViewer" class="btn small" @click="loadFrom1c" :disabled="load1cLoading || savingData">
        <BkIcon v-if="load1cLoading" name="loading" size="sm"/>
        <BkIcon v-else name="oneC" size="sm"/> 1С
      </button>
      <button class="compact-toggle" :class="{ active: compactMode }" @click="toggleCompact"><BkIcon name="menu" size="sm"/> Компакт</button>
      <div class="anv-search-wrap" v-if="hasData">
        <input
          v-model="searchQuery"
          type="text"
          class="anv-search"
          placeholder="Поиск по группе / товару..."
        />
        <span v-if="searchQuery" class="anv-search-clear" @click="searchQuery = ''">&times;</span>
      </div>
      <span v-if="savingData" class="anv-saving">Сохранение...</span>
      <span v-if="lastUpdate.by && !savingData" class="anv-last-update">
        Обновил: <b>{{ lastUpdate.by }}</b> · {{ lastUpdate.label }}
      </span>
    </div>

    <!-- Загрузка -->
    <div v-if="loading" class="anv-empty">
      <BurgerSpinner text="Загрузка товаров..." />
    </div>

    <!-- Пусто -->
    <div v-else-if="!items.length" class="anv-empty">
      Нет товаров для «{{ orderStore.settings.legalEntity }}»
    </div>

    <!-- Нет данных -->
    <div v-else-if="!hasData" class="anv-empty">
      <div style="font-size:28px;margin-bottom:8px;">📊</div>
      <div style="font-weight:600;margin-bottom:4px;">Загрузите данные</div>
      <div style="font-size:13px;">Нажмите «1С» или «Импорт» чтобы заполнить остатки и расход</div>
    </div>

    <!-- Основная область: таблица + сводка -->
    <div v-else class="anv-body">
      <!-- Таблица групп -->
      <div class="anv-main">
        <div class="order-table-wrapper">
          <table class="order-table">
            <thead>
              <tr>
                <th style="width:28px"></th>
                <th>Группа / Товар</th>
                <th style="text-align:right;width:80px">Остаток</th>
                <th style="text-align:right;width:80px">Расход</th>
                <th style="text-align:right;width:70px">Дней</th>
                <th style="width:32px"></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="group in filteredGroups" :key="group.name">
                <tr class="anv-group" @click="toggleGroup(group.name)" :class="daysRowClass(group.groupDays)">
                  <td class="anv-toggle">{{ expandedGroups.has(group.name) ? '▾' : '▸' }}</td>
                  <td>
                    <span class="anv-group-name">{{ group.name }}</span>
                    <span class="anv-group-cnt">{{ group.items.length }}</span>
                  </td>
                  <td style="text-align:right" class="anv-group-val">{{ nf(group.totalStock) }}</td>
                  <td style="text-align:right" class="anv-group-val">{{ nf(group.totalConsumption) }}</td>
                  <td style="text-align:right">
                    <span class="anv-days" :class="daysClass(group.groupDays)">{{ formatDays(group.groupDays) }}</span>
                  </td>
                  <td class="anv-action-cell" @click.stop>
                    <button v-if="!isViewer && group.groupDays !== Infinity && group.groupDays < 7 && group.mainSupplier" class="anv-order-btn" @click="goToOrder(group.mainSupplier)" title="Заказать">
                      <BkIcon name="package" size="xs"/>
                    </button>
                  </td>
                </tr>
                <template v-if="expandedGroups.has(group.name)">
                  <tr v-for="item in group.items" :key="item.id" class="anv-item">
                    <td></td>
                    <td>
                      <span class="anv-sku">{{ item.sku }}</span>
                      <span class="anv-item-name">{{ item.name }}</span>
                      <span v-if="!compactMode" class="anv-item-supplier">{{ item.supplier_name }}</span>
                    </td>
                    <td style="text-align:right">{{ nf(item.displayStock) }}</td>
                    <td style="text-align:right">{{ nf(item.displayConsumption) }}</td>
                    <td style="text-align:right">
                      <span class="anv-days anv-days-sm" :class="daysClass(item.daysOfStock)">{{ formatDays(item.daysOfStock) }}</span>
                    </td>
                    <td></td>
                  </tr>
                </template>
              </template>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Сводка -->
      <aside class="anv-sidebar">
        <div class="anv-card">
          <div class="anv-card-title">Сводка</div>
          <div class="anv-kpi">
            <div class="anv-kpi-row">
              <span class="anv-kpi-label">Групп аналогов</span>
              <span class="anv-kpi-val">{{ groupsWithData.length }}</span>
            </div>
            <div class="anv-kpi-row">
              <span class="anv-kpi-label">Товаров с данными</span>
              <span class="anv-kpi-val">{{ totalItemsWithData }}</span>
            </div>
            <div class="anv-kpi-row">
              <span class="anv-kpi-label">Всего товаров</span>
              <span class="anv-kpi-val anv-kpi-muted">{{ items.length }}</span>
            </div>
          </div>
        </div>

        <div class="anv-card" v-if="criticalGroups.length">
          <div class="anv-card-title anv-card-danger">Критичные (&lt;3 дн.)</div>
          <div class="anv-crit-list">
            <div v-for="g in criticalGroups" :key="g.name" class="anv-crit-item" @click="expandAndScroll(g.name)">
              <span>{{ g.name }}</span>
              <div style="display:flex;align-items:center;gap:4px;">
                <span class="anv-days anv-days-sm anv-d-red">{{ formatDays(g.groupDays) }}</span>
                <button v-if="!isViewer && g.mainSupplier" class="anv-order-btn anv-order-btn-sm" @click.stop="goToOrder(g.mainSupplier)" title="Заказать"><BkIcon name="package" size="xs"/></button>
              </div>
            </div>
          </div>
        </div>

        <div class="anv-card" v-if="warningGroups.length">
          <div class="anv-card-title anv-card-warn">Внимание (&lt;7 дн.)</div>
          <div class="anv-crit-list">
            <div v-for="g in warningGroups" :key="g.name" class="anv-crit-item" @click="expandAndScroll(g.name)">
              <span>{{ g.name }}</span>
              <div style="display:flex;align-items:center;gap:4px;">
                <span class="anv-days anv-days-sm anv-d-orange">{{ formatDays(g.groupDays) }}</span>
                <button v-if="!isViewer && g.mainSupplier" class="anv-order-btn anv-order-btn-sm" @click.stop="goToOrder(g.mainSupplier)" title="Заказать"><BkIcon name="package" size="xs"/></button>
              </div>
            </div>
          </div>
        </div>

        <div class="anv-card">
          <div class="anv-card-title">По статусу</div>
          <div class="anv-status-bar">
            <div v-if="statusCounts.red" class="anv-bar-seg anv-bar-red" :style="{ flex: statusCounts.red }" @click="filterStatus = 'red'" style="cursor:pointer">{{ statusCounts.red }}</div>
            <div v-if="statusCounts.orange" class="anv-bar-seg anv-bar-orange" :style="{ flex: statusCounts.orange }" @click="filterStatus = 'orange'" style="cursor:pointer">{{ statusCounts.orange }}</div>
            <div v-if="statusCounts.yellow" class="anv-bar-seg anv-bar-yellow" :style="{ flex: statusCounts.yellow }" @click="filterStatus = 'yellow'" style="cursor:pointer">{{ statusCounts.yellow }}</div>
            <div v-if="statusCounts.green" class="anv-bar-seg anv-bar-green" :style="{ flex: statusCounts.green }" @click="filterStatus = 'green'" style="cursor:pointer">{{ statusCounts.green }}</div>
            <div v-if="statusCounts.purple" class="anv-bar-seg anv-bar-purple" :style="{ flex: statusCounts.purple }" @click="filterStatus = 'purple'" style="cursor:pointer">{{ statusCounts.purple }}</div>
          </div>
          <div class="anv-legend">
            <span :class="{ 'anv-legend-active': filterStatus === 'red' }" @click="filterStatus = filterStatus === 'red' ? '' : 'red'" style="cursor:pointer"><i class="anv-dot anv-dot-red"></i> &lt;3 дн.</span>
            <span :class="{ 'anv-legend-active': filterStatus === 'orange' }" @click="filterStatus = filterStatus === 'orange' ? '' : 'orange'" style="cursor:pointer"><i class="anv-dot anv-dot-orange"></i> &lt;7</span>
            <span :class="{ 'anv-legend-active': filterStatus === 'yellow' }" @click="filterStatus = filterStatus === 'yellow' ? '' : 'yellow'" style="cursor:pointer"><i class="anv-dot anv-dot-yellow"></i> &lt;14</span>
            <span :class="{ 'anv-legend-active': filterStatus === 'green' }" @click="filterStatus = filterStatus === 'green' ? '' : 'green'" style="cursor:pointer"><i class="anv-dot anv-dot-green"></i> 14–30</span>
            <span :class="{ 'anv-legend-active': filterStatus === 'purple' }" @click="filterStatus = filterStatus === 'purple' ? '' : 'purple'" style="cursor:pointer"><i class="anv-dot anv-dot-purple"></i> 30+</span>
          </div>
        </div>
      </aside>
    </div>

    <!-- Модалка ненайденных позиций -->
    <Teleport to="body">
      <div v-if="showUnmatched" class="anv-modal-overlay" @click.self="showUnmatched = false">
        <div class="anv-modal">
          <div class="anv-modal-header">
            <span class="anv-modal-title">Не сопоставлено: {{ unmatchedItems.length }}</span>
            <button class="anv-modal-close" @click="showUnmatched = false">&times;</button>
          </div>
          <div class="anv-modal-desc">Позиции из файла, для которых не найден артикул в базе товаров</div>
          <div class="anv-modal-body">
            <table class="anv-modal-table">
              <thead>
                <tr>
                  <th>Артикул</th>
                  <th>Наименование</th>
                  <th style="text-align:right">Остаток</th>
                  <th style="text-align:right">Расход</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(u, idx) in unmatchedItems" :key="idx">
                  <td class="anv-sku">{{ u.sku || '—' }}</td>
                  <td>{{ u.name || '—' }}</td>
                  <td style="text-align:right">{{ u.stock ? nf(u.stock) : '—' }}</td>
                  <td style="text-align:right">{{ u.consumption ? nf(u.consumption) : '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="anv-modal-footer">
            <button class="btn small" @click="showUnmatched = false">Закрыть</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useOrderStore } from '@/stores/orderStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { db } from '@/lib/apiClient.js';
import { applyEntityFilter } from '@/lib/utils.js';
import { importFromFile } from '@/lib/importStock.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';

const router = useRouter();
const orderStore = useOrderStore();
const userStore = useUserStore();
const toast = useToastStore();
const isViewer = computed(() => userStore.isViewer);

const periodDays = ref(30);
const unit = ref('pieces');
const loading = ref(false);
const importLoading = ref(false);
const load1cLoading = ref(false);
const savingData = ref(false);
const items = ref([]);
const expandedGroups = reactive(new Set());
const lastUpdate = reactive({ by: '', at: null, label: '' });

const searchQuery = ref('');
const filterSupplier = ref('');
const filterStatus = ref('');
const compactMode = ref(localStorage.getItem('bk_analysis_compact') === '1');

const isBoxes = computed(() => unit.value === 'boxes');

function toggleCompact() {
  compactMode.value = !compactMode.value;
  localStorage.setItem('bk_analysis_compact', compactMode.value ? '1' : '0');
}

function localNow() {
  const d = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

function formatTimeAgo(date) {
  if (!date) return '';
  const now = Date.now();
  const diff = now - date.getTime();
  const mins = Math.round(diff / 60000);
  if (mins < 1) return 'только что';
  if (mins < 60) return `${mins} мин. назад`;
  const hours = Math.round(mins / 60);
  if (hours < 24) return `${hours} ч. назад`;
  const days = Math.round(hours / 24);
  if (days === 1) return 'вчера';
  return `${days} дн. назад`;
}

async function loadProducts() {
  loading.value = true;
  items.value = [];
  expandedGroups.clear();
  lastUpdate.by = '';
  lastUpdate.at = null;
  lastUpdate.label = '';
  try {
    let q = db.from('products')
      .select('id, sku, name, analog_group, supplier, qty_per_box');
    q = applyEntityFilter(q, orderStore.settings.legalEntity);
    const { data, error } = await q;
    if (error) { toast.error('Ошибка', 'Не удалось загрузить товары'); return; }
    items.value = (data || []).map(p => ({
      id: p.id,
      sku: p.sku || '',
      name: p.name || '',
      analog_group: p.analog_group || '',
      supplier_name: p.supplier || '',
      qtyPerBox: p.qty_per_box || 1,
      stock: 0,
      consumption: 0,
    }));
    // Автозагрузка сохранённых данных
    await loadSavedData();
  } catch {
    toast.error('Ошибка', 'Не удалось загрузить товары');
  } finally {
    loading.value = false;
  }
}

async function loadSavedData() {
  const skus = items.value.map(i => i.sku).filter(Boolean);
  if (!skus.length) return;
  try {
    const { data } = await db
      .from('analysis_data')
      .select('sku, stock, consumption, period_days, updated_by, updated_at')
      .eq('legal_entity', orderStore.settings.legalEntity)
      .in('sku', skus);
    if (!data?.length) return;

    const map = new Map(data.map(d => [d.sku, d]));
    let filled = 0;
    let newest = null;
    let newestBy = '';

    items.value.forEach(item => {
      const d = item.sku ? map.get(item.sku) : null;
      if (!d) return;
      // Данные в analysis_data хранятся в штуках
      item.stock = Math.round(d.stock || 0);
      const daily = (d.period_days || 30) > 0 ? (d.consumption || 0) / (d.period_days || 30) : 0;
      item.consumption = Math.round(daily * periodDays.value);
      filled++;
      const t = d.updated_at ? new Date(d.updated_at) : null;
      if (t && (!newest || t > newest)) { newest = t; newestBy = d.updated_by || ''; }
    });

    if (newest) {
      lastUpdate.by = newestBy;
      lastUpdate.at = newest;
      lastUpdate.label = formatTimeAgo(newest);
    }
  } catch { /* тихо — данных может не быть */ }
}

async function saveDataToDB() {
  const le = orderStore.settings.legalEntity;
  const userName = userStore.currentUser?.name || 'Неизвестный';
  const withData = items.value.filter(i => i.sku && (i.stock > 0 || i.consumption > 0));
  if (!withData.length) return;

  savingData.value = true;
  try {
    // Удалить старые данные для этого юр. лица
    await db.from('analysis_data').delete().eq('legal_entity', le);
    // Вставить новые (батчами по 50)
    const now = localNow();
    for (let i = 0; i < withData.length; i += 50) {
      const batch = withData.slice(i, i + 50).map(item => ({
        id: `${le}_${item.sku}`,
        legal_entity: le,
        sku: item.sku,
        stock: item.stock,
        consumption: item.consumption,
        period_days: periodDays.value,
        updated_by: userName,
        updated_at: now,
      }));
      await db.from('analysis_data').insert(batch);
    }
    lastUpdate.by = userName;
    lastUpdate.at = new Date();
    lastUpdate.label = formatTimeAgo(new Date());
  } catch (e) {
    console.error('[analysis] Ошибка сохранения:', e);
    toast.error('Ошибка', 'Не удалось сохранить данные анализа');
  } finally {
    savingData.value = false;
  }
}

function toUnit(val, qpb) {
  if (!isBoxes.value || !qpb || qpb <= 1) return val;
  return Math.round(val / qpb * 10) / 10;
}

function calcDays(stock, consumption) {
  if (!consumption || consumption <= 0) return stock > 0 ? Infinity : 0;
  const dailyRate = consumption / periodDays.value;
  return dailyRate > 0 ? stock / dailyRate : (stock > 0 ? Infinity : 0);
}

function getStatusKey(days) {
  if (days === Infinity || days >= 30) return 'purple';
  if (days >= 14) return 'green';
  if (days >= 7) return 'yellow';
  if (days >= 3) return 'orange';
  return 'red';
}

const hasData = computed(() => items.value.some(i => i.stock > 0 || i.consumption > 0));

// Уникальные поставщики из товаров с данными
const uniqueSuppliers = computed(() => {
  const set = new Set();
  for (const item of items.value) {
    if ((item.stock > 0 || item.consumption > 0) && item.supplier_name) {
      set.add(item.supplier_name);
    }
  }
  return [...set].sort((a, b) => a.localeCompare(b, 'ru'));
});

const groupsWithData = computed(() => {
  const map = new Map();
  for (const item of items.value) {
    if (!item.analog_group) continue;
    if (item.stock <= 0 && item.consumption <= 0) continue;
    // Фильтр по поставщику
    if (filterSupplier.value && item.supplier_name !== filterSupplier.value) continue;

    if (!map.has(item.analog_group)) {
      map.set(item.analog_group, {
        name: item.analog_group,
        items: [],
        rawTotalStock: 0,
        rawTotalConsumption: 0,
        supplierCounts: {},
      });
    }
    const g = map.get(item.analog_group);
    const d = calcDays(item.stock, item.consumption);
    g.items.push({
      ...item,
      daysOfStock: d,
      displayStock: toUnit(item.stock, item.qtyPerBox),
      displayConsumption: toUnit(item.consumption, item.qtyPerBox),
    });
    g.rawTotalStock += item.stock;
    g.rawTotalConsumption += item.consumption;
    // Считаем поставщиков
    if (item.supplier_name) {
      g.supplierCounts[item.supplier_name] = (g.supplierCounts[item.supplier_name] || 0) + 1;
    }
  }
  const arr = Array.from(map.values());
  for (const g of arr) {
    g.groupDays = calcDays(g.rawTotalStock, g.rawTotalConsumption);
    // Суммируем уже сконвертированные значения каждого товара (вместо среднего qpb)
    g.totalStock = Math.round(g.items.reduce((s, i) => s + i.displayStock, 0) * 10) / 10;
    g.totalConsumption = Math.round(g.items.reduce((s, i) => s + i.displayConsumption, 0) * 10) / 10;
    g.items.sort((a, b) => a.daysOfStock - b.daysOfStock);
    // Основной поставщик — самый частый
    const sc = g.supplierCounts;
    g.mainSupplier = Object.keys(sc).sort((a, b) => sc[b] - sc[a])[0] || '';
  }
  arr.sort((a, b) => a.groupDays - b.groupDays);
  return arr;
});

const filteredGroups = computed(() => {
  let result = groupsWithData.value;

  // Фильтр по статусу
  if (filterStatus.value) {
    result = result.filter(g => getStatusKey(g.groupDays) === filterStatus.value);
  }

  // Фильтр по поиску
  const q = searchQuery.value.toLowerCase().trim();
  if (q) {
    result = result.filter(g => {
      if (g.name.toLowerCase().includes(q)) return true;
      return g.items.some(i =>
        i.name.toLowerCase().includes(q) ||
        i.sku.toLowerCase().includes(q)
      );
    });
  }

  return result;
});

const totalItemsWithData = computed(() => groupsWithData.value.reduce((s, g) => s + g.items.length, 0));

const criticalGroups = computed(() => groupsWithData.value.filter(g => g.groupDays !== Infinity && g.groupDays < 3));
const warningGroups = computed(() => groupsWithData.value.filter(g => g.groupDays !== Infinity && g.groupDays >= 3 && g.groupDays < 7));

const statusCounts = computed(() => {
  let red = 0, orange = 0, yellow = 0, green = 0, purple = 0;
  for (const g of groupsWithData.value) {
    const d = g.groupDays;
    if (d === Infinity || d >= 30) purple++;
    else if (d >= 14) green++;
    else if (d < 3) red++;
    else if (d < 7) orange++;
    else yellow++;
  }
  return { red, orange, yellow, green, purple };
});

function toggleGroup(name) {
  if (expandedGroups.has(name)) expandedGroups.delete(name);
  else expandedGroups.add(name);
}

function expandAndScroll(name) {
  expandedGroups.add(name);
}

function goToOrder(supplier) {
  router.push({ name: 'order', query: { supplier } });
}

function daysClass(days) {
  if (days === Infinity || days >= 30) return 'anv-d-purple';
  if (days < 3) return 'anv-d-red';
  if (days < 7) return 'anv-d-orange';
  if (days < 14) return 'anv-d-yellow';
  return 'anv-d-green';
}

function daysRowClass(days) {
  if (days === Infinity || days >= 30) return 'anv-row-purple';
  if (days < 3) return 'anv-row-red';
  if (days < 7) return 'anv-row-orange';
  if (days < 14) return 'anv-row-yellow';
  return 'anv-row-green';
}

function formatDays(days) {
  if (days === Infinity) return '∞';
  if (days === 0) return '0';
  return Math.round(days);
}

function nf(n) {
  if (n === undefined || n === null) return '—';
  return n.toLocaleString('ru-RU');
}

// Ненайденные позиции после импорта
const unmatchedItems = ref([]);
const showUnmatched = ref(false);

// Импорт — только по артикулу
async function doImport() {
  importLoading.value = true;
  try {
    const result = await importFromFile('analysis', items.value, orderStore.settings.legalEntity);
    if (!result) return;
    if (result.error) { toast.error('Ошибка импорта', result.error); return; }
    const imported = result.items;
    // Данные из importFromFile уже в штуках — сохраняем как есть (без конвертации)
    items.value = imported;
    toast.success('Импорт завершён', `Сопоставлено: ${result.matched} из ${result.total} (файл)`);
    await saveDataToDB();
    // Показать ненайденные
    if (result.unmatchedFile?.length) {
      unmatchedItems.value = result.unmatchedFile;
      showUnmatched.value = true;
    } else {
      unmatchedItems.value = [];
    }
  } finally {
    importLoading.value = false;
  }
}

async function loadFrom1c() {
  const skus = items.value.map(i => i.sku).filter(Boolean);
  if (!skus.length) { toast.error('Нет артикулов', 'У товаров нет SKU'); return; }

  load1cLoading.value = true;
  try {
    const { data, error } = await db
      .from('stock_1c')
      .select('sku, stock, consumption, period_days, updated_at')
      .eq('legal_entity', orderStore.settings.legalEntity)
      .in('sku', skus);

    if (error) { toast.error('Ошибка', 'Не удалось загрузить данные из 1С'); return; }
    if (!data?.length) { toast.info('Нет данных', `В таблице stock_1c нет данных для «${orderStore.settings.legalEntity}»`); return; }

    const stockMap = new Map(data.map(d => [d.sku, d]));
    let filled = 0;

    let oldestUpdate = null;
    data.forEach(d => { const t = new Date(d.updated_at); if (!oldestUpdate || t < oldestUpdate) oldestUpdate = t; });

    items.value.forEach(item => {
      const d = item.sku ? stockMap.get(item.sku) : null;
      if (!d) return;
      // Всегда храним в штуках — конвертация в коробки только при отображении (toUnit)
      item.stock = Math.round(d.stock || 0);
      const daily = (d.period_days || 30) > 0 ? (d.consumption || 0) / (d.period_days || 30) : 0;
      item.consumption = Math.round(daily * periodDays.value);
      filled++;
    });

    const hoursAgo = oldestUpdate ? Math.round((Date.now() - oldestUpdate) / 3600000) : null;
    const freshLabel = hoursAgo !== null ? (hoursAgo < 1 ? 'только что' : `${hoursAgo} ч. назад`) : '';
    toast.success('Данные из 1С', `${filled} из ${items.value.length} позиций${freshLabel ? ' · ' + freshLabel : ''}`);
    await saveDataToDB();
  } catch {
    toast.error('Ошибка', 'Таблица stock_1c не найдена');
  } finally {
    load1cLoading.value = false;
  }
}

watch(() => orderStore.settings.legalEntity, () => { loadProducts(); });
onMounted(() => { loadProducts(); });
</script>

<style scoped>
.analysis-view {
  display: flex;
  flex-direction: column;
  height: 100%;
  gap: 6px;
  overflow: hidden;
}

.an-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-shrink: 0;
}

.anv-controls {
  display: flex;
  gap: 12px;
  align-items: center;
  flex-wrap: wrap;
}
.anv-control {
  display: flex;
  align-items: center;
  gap: 5px;
}
.anv-control label {
  font-size: 12px;
  color: var(--text-muted);
  white-space: nowrap;
}
.anv-control select {
  padding: 4px 8px;
  border-radius: 6px;
  border: 1px solid var(--border);
  font-size: 12px;
  font-weight: 600;
  background: white;
}

.anv-toolbar {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
  flex-wrap: wrap;
}

.anv-search-wrap {
  position: relative;
  margin-left: 6px;
}
.anv-search {
  padding: 4px 24px 4px 8px;
  border-radius: 6px;
  border: 1px solid var(--border);
  font-size: 12px;
  width: 200px;
  background: white;
}
.anv-search:focus {
  outline: none;
  border-color: var(--bk-orange);
}
.anv-search-clear {
  position: absolute;
  right: 6px;
  top: 50%;
  transform: translateY(-50%);
  cursor: pointer;
  font-size: 16px;
  line-height: 1;
  color: var(--text-muted);
}
.anv-search-clear:hover { color: var(--text); }

.anv-saving {
  font-size: 11px;
  color: var(--text-muted);
  font-style: italic;
}
.anv-last-update {
  font-size: 11px;
  color: var(--text-muted);
  margin-left: auto;
}
.anv-last-update b {
  color: var(--text-secondary);
  font-weight: 600;
}

.anv-empty {
  text-align: center;
  padding: 60px 20px;
  color: var(--text-muted);
}

/* ═══ Двухколоночный лейаут ═══ */
.anv-body {
  display: flex;
  gap: 16px;
  flex: 1;
  min-height: 0;
  overflow: hidden;
}
.anv-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  min-height: 0;
}
.anv-main .order-table-wrapper {
  flex: 1;
  min-height: 0;
  overflow: auto;
}

/* ═══ Сайдбар-сводка ═══ */
.anv-sidebar {
  width: 240px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
  overflow-y: auto;
  min-height: 0;
}

.anv-card {
  background: white;
  border: 1px solid var(--border-light);
  border-radius: var(--radius-sm);
  padding: 12px;
}
.anv-card-title {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--text-muted);
  margin-bottom: 8px;
}
.anv-card-danger { color: #C62828; }
.anv-card-warn { color: #E65100; }

.anv-kpi { display: flex; flex-direction: column; gap: 6px; }
.anv-kpi-row { display: flex; justify-content: space-between; align-items: center; }
.anv-kpi-label { font-size: 12px; color: var(--text-secondary); }
.anv-kpi-val { font-size: 14px; font-weight: 700; color: var(--text); }
.anv-kpi-muted { color: var(--text-muted); font-weight: 500; }

.anv-crit-list { display: flex; flex-direction: column; gap: 4px; }
.anv-crit-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 12px;
  padding: 3px 0;
  cursor: pointer;
  color: var(--text);
}
.anv-crit-item:hover { color: var(--accent); }

/* Статус-бар */
.anv-status-bar {
  display: flex;
  height: 18px;
  border-radius: 4px;
  overflow: hidden;
  gap: 1px;
}
.anv-bar-seg {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  font-weight: 700;
  color: white;
  min-width: 18px;
}
.anv-bar-red { background: #EF5350; }
.anv-bar-orange { background: #FF9800; }
.anv-bar-yellow { background: #FDD835; color: #5D4037; }
.anv-bar-green { background: #66BB6A; }
.anv-bar-purple { background: #9C27B0; }

.anv-legend {
  display: flex;
  gap: 8px;
  margin-top: 6px;
  font-size: 10px;
  color: var(--text-muted);
}
.anv-legend-active {
  font-weight: 700;
  color: var(--text);
}
.anv-dot {
  display: inline-block;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  margin-right: 2px;
  vertical-align: middle;
}
.anv-dot-red { background: #EF5350; }
.anv-dot-orange { background: #FF9800; }
.anv-dot-yellow { background: #FDD835; }
.anv-dot-green { background: #66BB6A; }
.anv-dot-purple { background: #9C27B0; }

/* ═══ Строка группы ═══ */
.anv-group {
  cursor: pointer;
  user-select: none;
}
.anv-group td {
  background: var(--bk-yellow) !important;
  border-bottom: 1px solid var(--border) !important;
  padding: 6px 8px !important;
  font-size: 12px;
}
.anv-group td:nth-child(2),
.anv-item td:nth-child(2) {
  text-align: left;
}
.anv-group:hover td { background: #f0e5d0 !important; }
.anv-group.anv-row-red td { background: #FFF0F0 !important; }
.anv-group.anv-row-red:hover td { background: #FFE4E4 !important; }
.anv-group.anv-row-orange td { background: #FFF8ED !important; }
.anv-group.anv-row-orange:hover td { background: #FFEFDB !important; }
.anv-group.anv-row-yellow td { background: #FFFDE7 !important; }
.anv-group.anv-row-yellow:hover td { background: #FFF9C4 !important; }
.anv-group.anv-row-green td { background: #E8F5E9 !important; }
.anv-group.anv-row-green:hover td { background: #C8E6C9 !important; }
.anv-group.anv-row-purple td { background: #F3E5F5 !important; }
.anv-group.anv-row-purple:hover td { background: #E1BEE7 !important; }

.anv-toggle {
  text-align: center;
  font-size: 10px;
  color: var(--bk-brown-light);
}
.anv-group-name {
  font-weight: 600;
  color: var(--bk-brown);
  font-size: 12px;
}
.anv-group-cnt {
  font-size: 10px;
  color: var(--text-muted);
  margin-left: 4px;
}
.anv-group-val {
  font-weight: 600;
  font-size: 12px;
  color: var(--text-secondary);
}

/* ═══ Строка товара ═══ */
.anv-item td {
  font-size: 11px;
  padding: 3px 8px !important;
  border-bottom: 1px solid var(--border-light) !important;
  color: var(--text-secondary);
}
.anv-sku {
  font-family: monospace;
  font-size: 10px;
  color: var(--text-muted);
  margin-right: 4px;
}
.anv-item-name {
  font-size: 11px;
}
.anv-item-supplier {
  font-size: 9px;
  color: var(--text-muted);
  margin-left: 6px;
  opacity: 0.7;
}

/* ═══ Кнопка «Заказать» ═══ */
.anv-action-cell {
  text-align: center;
  padding: 2px !important;
}
.anv-order-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border: 1px solid var(--border);
  border-radius: 6px;
  background: white;
  cursor: pointer;
  opacity: 0.5;
  transition: all 0.15s;
}
.anv-order-btn:hover {
  opacity: 1;
  border-color: var(--bk-orange);
  background: #FFF8ED;
}
.anv-order-btn-sm {
  width: 20px;
  height: 20px;
  border-radius: 4px;
  flex-shrink: 0;
}

/* ═══ Бейдж дней ═══ */
.anv-days {
  display: inline-block;
  padding: 1px 8px;
  border-radius: 10px;
  font-size: 12px;
  font-weight: 700;
  min-width: 32px;
  text-align: center;
}
.anv-days-sm {
  font-size: 11px;
  padding: 1px 6px;
  min-width: 26px;
}
.anv-d-red { background: #FFEBEE; color: #C62828; }
.anv-d-orange { background: #FFF3E0; color: #E65100; }
.anv-d-yellow { background: #FFFDE7; color: #F57F17; }
.anv-d-green { background: #E8F5E9; color: #2E7D32; }
.anv-d-purple { background: #F3E5F5; color: #6A1B9A; }

/* ═══ Компактный режим ═══ */
.anv-compact .anv-group td { padding: 3px 6px !important; font-size: 11px; }
.anv-compact .anv-item td { padding: 2px 6px !important; font-size: 10px; }
.anv-compact .anv-days { font-size: 10px; padding: 0 6px; min-width: 26px; }
.anv-compact .anv-days-sm { font-size: 9px; padding: 0 4px; min-width: 22px; }
.anv-compact .anv-group-name { font-size: 11px; }
.anv-compact .anv-sku { font-size: 9px; }
.anv-compact .anv-item-name { font-size: 10px; }
.anv-compact .anv-item-supplier { display: none; }
.anv-compact .anv-group-cnt { font-size: 9px; }

/* ═══ Мобильная адаптация ═══ */
@media (max-width: 1024px) {
  .anv-sidebar { width: 200px; }
}
@media (max-width: 900px) {
  .anv-body { flex-direction: column; overflow-y: auto; }
  .anv-main { flex: none; }
  .anv-main .order-table-wrapper {
    flex: none;
    max-height: 60vh;
  }
  .anv-sidebar { width: 100%; flex-direction: row; flex-wrap: wrap; overflow-y: visible; }
  .anv-card { flex: 1; min-width: 160px; }
}
@media (max-width: 768px) {
  .an-header { flex-direction: column; align-items: flex-start; gap: 8px; }
  .anv-controls { width: 100%; }
  .anv-toolbar { flex-wrap: wrap; }
  .anv-search { width: 160px; }
}
@media (max-width: 480px) {
  .anv-sidebar { flex-direction: column; }
  .anv-card { min-width: 0; }
}

/* ═══ Модалка ненайденных ═══ */
.anv-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.4);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}
.anv-modal {
  background: white;
  border-radius: 12px;
  width: 640px;
  max-width: 95vw;
  max-height: 80vh;
  display: flex;
  flex-direction: column;
  box-shadow: 0 8px 32px rgba(0,0,0,0.2);
}
.anv-modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 18px 0;
}
.anv-modal-title {
  font-size: 15px;
  font-weight: 700;
  color: var(--text);
}
.anv-modal-close {
  background: none;
  border: none;
  font-size: 22px;
  cursor: pointer;
  color: var(--text-muted);
  line-height: 1;
  padding: 0 4px;
}
.anv-modal-close:hover { color: var(--text); }
.anv-modal-desc {
  font-size: 12px;
  color: var(--text-muted);
  padding: 4px 18px 10px;
}
.anv-modal-body {
  flex: 1;
  overflow-y: auto;
  padding: 0 18px;
  min-height: 0;
}
.anv-modal-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}
.anv-modal-table th {
  text-align: left;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  color: var(--text-muted);
  padding: 6px 8px;
  border-bottom: 2px solid var(--border);
  position: sticky;
  top: 0;
  background: white;
}
.anv-modal-table td {
  padding: 5px 8px;
  border-bottom: 1px solid var(--border-light);
  color: var(--text-secondary);
}
.anv-modal-table tbody tr:hover td {
  background: var(--bk-yellow-light, #FFF8ED);
}
.anv-modal-footer {
  padding: 10px 18px 14px;
  display: flex;
  justify-content: flex-end;
}
</style>
