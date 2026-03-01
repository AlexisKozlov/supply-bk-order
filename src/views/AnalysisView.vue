<template>
  <div class="analysis-view" :class="{ 'anv-compact': compactMode }">
    <!-- Header -->
    <div class="anv-header">
      <h1 class="page-title" style="margin-bottom:0">Анализ запасов</h1>
      <div class="anv-header-controls">
        <div class="anv-unit-toggle">
          <button
            class="anv-unit-btn"
            :class="{ active: unit === 'pieces' }"
            @click="unit = 'pieces'"
          >Шт</button>
          <button
            class="anv-unit-btn"
            :class="{ active: unit === 'boxes' }"
            @click="unit = 'boxes'"
          >Кор</button>
        </div>
      </div>
    </div>

    <!-- Alert banner for critical groups -->
    <div
      v-if="hasData && criticalGroups.length"
      class="anv-alert-banner"
      @click="activeTab = 'red'"
    >
      <span class="anv-alert-icon">!</span>
      <span>{{ criticalGroups.length }} {{ criticalGroups.length === 1 ? 'критичная группа' : criticalGroups.length < 5 ? 'критичные группы' : 'критичных групп' }} — менее 3 дней запаса</span>
      <span class="anv-alert-arrow">&rarr;</span>
    </div>

    <!-- KPI Cards -->
    <div v-if="hasData" class="anv-kpi-grid">
      <div class="anv-kpi-card">
        <div class="anv-kpi-head">Групп аналогов</div>
        <div class="anv-kpi-val">{{ groupsWithData.length }}</div>
      </div>
      <div class="anv-kpi-card">
        <div class="anv-kpi-head">Товаров с данными</div>
        <div class="anv-kpi-val">{{ totalItemsWithData }}</div>
        <div class="anv-kpi-sub">из {{ items.length }}</div>
      </div>
      <div class="anv-kpi-card anv-kpi-danger" v-if="statusCounts.red">
        <div class="anv-kpi-head">Критичных &lt;3 дн.</div>
        <div class="anv-kpi-val">{{ statusCounts.red }}</div>
      </div>
      <div class="anv-kpi-card anv-kpi-danger" v-else>
        <div class="anv-kpi-head">Критичных &lt;3 дн.</div>
        <div class="anv-kpi-val" style="color:var(--green)">0</div>
      </div>
      <div class="anv-kpi-card anv-kpi-warn" v-if="statusCounts.orange">
        <div class="anv-kpi-head">Требуют внимания</div>
        <div class="anv-kpi-val">{{ statusCounts.orange }}</div>
      </div>
      <div class="anv-kpi-card" v-else>
        <div class="anv-kpi-head">Требуют внимания</div>
        <div class="anv-kpi-val" style="color:var(--green)">0</div>
      </div>
    </div>

    <!-- Distribution bar -->
    <div v-if="hasData && groupsWithData.length" class="anv-dist-bar">
      <div v-if="statusCounts.red" class="anv-dist-seg anv-dist-red" :style="{ flex: statusCounts.red }" :title="'<3 дн: ' + statusCounts.red"></div>
      <div v-if="statusCounts.orange" class="anv-dist-seg anv-dist-orange" :style="{ flex: statusCounts.orange }" :title="'<7 дн: ' + statusCounts.orange"></div>
      <div v-if="statusCounts.yellow" class="anv-dist-seg anv-dist-yellow" :style="{ flex: statusCounts.yellow }" :title="'<14 дн: ' + statusCounts.yellow"></div>
      <div v-if="statusCounts.green" class="anv-dist-seg anv-dist-green" :style="{ flex: statusCounts.green }" :title="'14-30 дн: ' + statusCounts.green"></div>
      <div v-if="statusCounts.purple" class="anv-dist-seg anv-dist-purple" :style="{ flex: statusCounts.purple }" :title="'30+ дн: ' + statusCounts.purple"></div>
    </div>

    <!-- Tabs -->
    <div v-if="hasData" class="anv-tabs">
      <button class="anv-tab" :class="{ active: activeTab === 'all' }" @click="activeTab = 'all'">
        Все <span class="anv-tab-cnt">{{ groupsWithData.length }}</span>
      </button>
      <button class="anv-tab" :class="{ active: activeTab === 'red' }" @click="activeTab = 'red'">
        <span class="anv-tab-dot" style="background:#EF5350"></span> 0–3 дн <span v-if="statusCounts.red" class="anv-tab-cnt anv-tab-cnt-red">{{ statusCounts.red }}</span>
      </button>
      <button class="anv-tab" :class="{ active: activeTab === 'orange' }" @click="activeTab = 'orange'">
        <span class="anv-tab-dot" style="background:#FF9800"></span> 3–7 дн <span v-if="statusCounts.orange" class="anv-tab-cnt anv-tab-cnt-orange">{{ statusCounts.orange }}</span>
      </button>
      <button class="anv-tab" :class="{ active: activeTab === 'normal' }" @click="activeTab = 'normal'">
        <span class="anv-tab-dot" style="background:#66BB6A"></span> Норма <span v-if="statusCounts.yellow + statusCounts.green" class="anv-tab-cnt">{{ statusCounts.yellow + statusCounts.green }}</span>
      </button>
      <button class="anv-tab" :class="{ active: activeTab === 'purple' }" @click="activeTab = 'purple'">
        <span class="anv-tab-dot" style="background:#AB47BC"></span> 30+ <span v-if="statusCounts.purple" class="anv-tab-cnt anv-tab-cnt-purple">{{ statusCounts.purple }}</span>
      </button>
    </div>

    <!-- Toolbar -->
    <div class="anv-toolbar" v-if="items.length">
      <button v-if="!isViewer" class="btn small" @click="doImport" :disabled="importLoading || savingData">
        <BkIcon v-if="importLoading" name="loading" size="sm"/>
        <BkIcon v-else name="import" size="sm"/> Импорт
      </button>
      <button v-if="!isViewer" class="btn small" @click="loadFrom1c" :disabled="load1cLoading || savingData">
        <BkIcon v-if="load1cLoading" name="loading" size="sm"/>
        <BkIcon v-else name="oneC" size="sm"/> 1С
      </button>
      <button class="anv-compact-btn" :class="{ active: compactMode }" @click="toggleCompact">
        <BkIcon name="menu" size="sm"/>
      </button>
      <div class="anv-toolbar-sep"></div>
      <div class="anv-chip-filter" v-if="hasData">
        <select v-model="filterSupplier" class="anv-chip-select">
          <option value="">Поставщик: все</option>
          <option v-for="s in uniqueSuppliers" :key="s" :value="s">{{ s }}</option>
        </select>
      </div>
      <div class="anv-chip-filter" v-if="hasData">
        <select v-model="filterCategory" class="anv-chip-select">
          <option value="">Хранение: все</option>
          <option value="Сухой">Сухой</option>
          <option value="Холод">Холод</option>
          <option value="Мороз">Мороз</option>
        </select>
      </div>
      <div class="anv-search-wrap" v-if="hasData">
        <input
          v-model="searchQuery"
          type="text"
          class="anv-search"
          placeholder="Поиск..."
        />
        <span v-if="searchQuery" class="anv-search-clear" @click="searchQuery = ''">&times;</span>
      </div>
      <span v-if="savingData" class="anv-saving">Сохранение...</span>
      <span v-if="lastUpdate.by && !savingData" class="anv-last-update">
        <b>{{ lastUpdate.by }}</b> &middot; {{ lastUpdate.label }}
      </span>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="anv-empty">
      <BurgerSpinner text="Загрузка товаров..." />
    </div>

    <!-- Empty -->
    <div v-else-if="!items.length" class="anv-empty">
      Нет товаров для &laquo;{{ orderStore.settings.legalEntity }}&raquo;
    </div>

    <!-- No data -->
    <div v-else-if="!hasData" class="anv-empty">
      <div style="font-size:28px;margin-bottom:8px;">📊</div>
      <div style="font-weight:600;margin-bottom:4px;">Загрузите данные</div>
      <div style="font-size:13px;">Нажмите «1С» или «Импорт» чтобы заполнить остатки и расход</div>
    </div>

    <!-- Main table -->
    <div v-else class="anv-table-card">
      <div class="anv-table-wrap">
        <table class="anv-table">
          <thead>
            <tr>
              <th class="anv-th-toggle"></th>
              <th class="anv-th-name">Группа / Товар</th>
              <th class="anv-th-num">Остаток</th>
              <th class="anv-th-num">Расход</th>
              <th class="anv-th-days">Дней</th>
              <th class="anv-th-action"></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="section in sectionedGroups" :key="section.key">
              <tr class="anv-section-row" @click="toggleSection(section.key)">
                <td colspan="6">
                  <svg class="anv-chevron" :class="{ open: expandedSections.has(section.key) }" viewBox="0 0 16 16" width="12" height="12"><path d="M5 3l5 5-5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  <span class="anv-section-label">{{ section.label }}</span>
                  <span class="anv-section-count">{{ section.groups.length }}</span>
                </td>
              </tr>
              <template v-if="expandedSections.has(section.key)">
                <template v-for="group in section.groups" :key="group.name">
                  <tr class="anv-group-row" :class="groupRowClass(group.groupDays)" @click="toggleGroup(group.name)">
                    <td class="anv-td-toggle">
                      <svg class="anv-chevron" :class="{ open: expandedGroups.has(group.name) }" viewBox="0 0 16 16" width="11" height="11"><path d="M5 3l5 5-5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </td>
                    <td class="anv-td-name">
                      <span class="anv-group-name">{{ group.name }}</span>
                      <span class="anv-group-cnt">{{ group.items.filter(i => !i._foreign).length }}{{ group.items.some(i => i._foreign) ? '+' + group.items.filter(i => i._foreign).length : '' }}</span>
                    </td>
                    <td class="anv-td-num">{{ nf(group.totalStock) }}</td>
                    <td class="anv-td-num">{{ nf(group.totalConsumption) }}</td>
                    <td class="anv-td-days">
                      <span class="anv-days-badge" :class="daysClass(group.groupDays)">{{ formatDays(group.groupDays) }}</span>
                    </td>
                    <td class="anv-td-action" @click.stop>
                      <button v-if="!isViewer && group.groupDays !== Infinity && group.groupDays < 7 && group.mainSupplier" class="anv-order-btn" @click="goToOrder(group.mainSupplier)" title="Заказать">
                        <BkIcon name="package" size="xs"/>
                      </button>
                    </td>
                  </tr>
                  <template v-if="expandedGroups.has(group.name)">
                    <tr v-for="item in group.items" :key="item.id" class="anv-item-row" :class="{ 'anv-item-foreign': item._foreign }">
                      <td></td>
                      <td class="anv-td-name anv-td-name-item">
                        <span class="anv-sku">{{ item.sku }}</span>
                        <span class="anv-item-name">{{ item.name }}</span>
                        <span v-if="item._foreign" class="anv-foreign-badge">{{ item.supplier_name }}</span>
                        <span v-else-if="!compactMode" class="anv-item-supplier">{{ item.supplier_name }}</span>
                      </td>
                      <td class="anv-td-num anv-editable" @dblclick="startEdit(item, 'stock')">
                        <input v-if="editingCell && editingCell.itemId === item.id && editingCell.field === 'stock'"
                          v-model="editingValue" class="anv-inline-input" type="text" inputmode="decimal"
                          @keydown.enter="commitEdit(item)" @keydown.escape="cancelEdit" @blur="commitEdit(item)"/>
                        <span v-else>{{ nf(item.displayStock) }}</span>
                      </td>
                      <td class="anv-td-num anv-editable" @dblclick="startEdit(item, 'consumption')">
                        <input v-if="editingCell && editingCell.itemId === item.id && editingCell.field === 'consumption'"
                          v-model="editingValue" class="anv-inline-input" type="text" inputmode="decimal"
                          @keydown.enter="commitEdit(item)" @keydown.escape="cancelEdit" @blur="commitEdit(item)"/>
                        <span v-else>{{ nf(item.displayConsumption) }}</span>
                      </td>
                      <td class="anv-td-days">
                        <template v-if="item._foreign"><span class="anv-days-badge anv-days-sm anv-d-muted">&mdash;</span></template>
                        <template v-else><span class="anv-days-badge anv-days-sm" :class="daysClass(item.daysOfStock)">{{ formatDays(item.daysOfStock) }}</span></template>
                      </td>
                      <td></td>
                    </tr>
                  </template>
                </template>
              </template>
            </template>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Unmatched items modal -->
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
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
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
const filterCategory = ref('');
const activeTab = ref('all');
const expandedSections = reactive(new Set(['Сухой', 'Холод', 'Мороз', '']));
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
      .select('id, sku, name, analog_group, supplier, qty_per_box, category');
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
      category: p.category || '',
      stock: 0,
      consumption: 0,
    }));
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
      item.stock = Math.round((d.stock || 0) * 10) / 10;
      const daily = (d.period_days || 30) > 0 ? (d.consumption || 0) / (d.period_days || 30) : 0;
      item.consumption = Math.round(daily * periodDays.value * 10) / 10;
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

  const skuMap = new Map();
  withData.forEach(item => { skuMap.set(item.sku, item); });
  const unique = [...skuMap.values()];

  savingData.value = true;
  try {
    const now = localNow();
    const allItems = unique.map(item => ({
      id: `${le}_${item.sku}`,
      legal_entity: le,
      sku: item.sku,
      stock: item.stock,
      consumption: item.consumption,
      period_days: periodDays.value,
      updated_by: userName,
      updated_at: now,
    }));
    const { data: result, error } = await db.rpc('replace_analysis_data', {
      legal_entity: le,
      items: allItems,
    });
    if (error || (result && result.error)) {
      throw new Error(error || result?.error || 'Transaction failed');
    }
    lastUpdate.by = userName;
    lastUpdate.at = new Date();
    lastUpdate.label = formatTimeAgo(new Date());
    toast.success('Сохранено', 'Данные анализа обновлены');
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

function fromUnit(val, qpb) {
  if (!isBoxes.value || !qpb || qpb <= 1) return Math.round(val * 10) / 10;
  return Math.round(val * qpb * 10) / 10;
}

// --- Inline editing ---
const editingCell = ref(null);
const editingValue = ref('');
let _saveTimer = null;

function startEdit(item, field) {
  if (isViewer.value) return;
  const display = field === 'stock' ? item.displayStock : item.displayConsumption;
  editingCell.value = { itemId: item.id, field };
  editingValue.value = String(display || 0);
  nextTick(() => {
    const el = document.querySelector('.anv-inline-input');
    if (el) { el.focus(); el.select(); }
  });
}

function commitEdit(item) {
  if (!editingCell.value) return;
  const { field } = editingCell.value;
  const parsed = parseFloat(String(editingValue.value).replace(',', '.')) || 0;
  const raw = items.value.find(i => i.id === item.id);
  if (raw) {
    raw[field] = fromUnit(Math.max(0, parsed), raw.qtyPerBox);
  }
  editingCell.value = null;
  clearTimeout(_saveTimer);
  _saveTimer = setTimeout(() => saveDataToDB(), 1000);
}

function cancelEdit() {
  editingCell.value = null;
}

function calcDays(stock, consumption) {
  if (!consumption || consumption <= 0) return stock > 0 ? Infinity : 0;
  const dailyRate = consumption / periodDays.value;
  return dailyRate > 0 ? stock / dailyRate : (stock > 0 ? Infinity : 0);
}

function getStatusKey(days) {
  if (days === Infinity) return 'purple';
  const d = Math.round(days);
  if (d >= 30) return 'purple';
  if (d >= 14) return 'green';
  if (d >= 7) return 'yellow';
  if (d >= 3) return 'orange';
  return 'red';
}

const hasData = computed(() => items.value.some(i => i.stock > 0 || i.consumption > 0));

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

    const isForeign = !!(filterSupplier.value && item.supplier_name !== filterSupplier.value);

    if (!map.has(item.analog_group)) {
      map.set(item.analog_group, {
        name: item.analog_group,
        items: [],
        rawTotalStock: 0,
        rawTotalConsumption: 0,
        supplierCounts: {},
        categoryCounts: {},
        hasOwn: false,
      });
    }
    const g = map.get(item.analog_group);
    const d = calcDays(item.stock, item.consumption);
    g.items.push({
      ...item,
      _foreign: isForeign,
      daysOfStock: d,
      displayStock: toUnit(item.stock, item.qtyPerBox),
      displayConsumption: toUnit(item.consumption, item.qtyPerBox),
    });
    g.rawTotalStock += item.stock;
    g.rawTotalConsumption += item.consumption;
    if (!isForeign) {
      g.hasOwn = true;
      if (item.supplier_name) {
        g.supplierCounts[item.supplier_name] = (g.supplierCounts[item.supplier_name] || 0) + 1;
      }
      const cat = item.category || '';
      g.categoryCounts[cat] = (g.categoryCounts[cat] || 0) + 1;
    }
  }
  const arr = Array.from(map.values()).filter(g => !filterSupplier.value || g.hasOwn);
  for (const g of arr) {
    g.groupDays = calcDays(g.rawTotalStock, g.rawTotalConsumption);
    g.totalStock = Math.round(g.items.reduce((s, i) => s + i.displayStock, 0) * 10) / 10;
    g.totalConsumption = Math.round(g.items.reduce((s, i) => s + i.displayConsumption, 0) * 10) / 10;
    g.items.sort((a, b) => (a._foreign ? 1 : 0) - (b._foreign ? 1 : 0) || a.daysOfStock - b.daysOfStock);
    const sc = g.supplierCounts;
    g.mainSupplier = Object.keys(sc).sort((a, b) => sc[b] - sc[a])[0] || '';
    const cc = g.categoryCounts;
    g.category = Object.keys(cc).sort((a, b) => cc[b] - cc[a])[0] || '';
  }
  arr.sort((a, b) => a.groupDays - b.groupDays);
  return arr;
});

const filteredGroups = computed(() => {
  let result = groupsWithData.value;

  // Tab filter
  if (activeTab.value === 'red') {
    result = result.filter(g => getStatusKey(g.groupDays) === 'red');
  } else if (activeTab.value === 'orange') {
    result = result.filter(g => getStatusKey(g.groupDays) === 'orange');
  } else if (activeTab.value === 'normal') {
    result = result.filter(g => {
      const s = getStatusKey(g.groupDays);
      return s === 'yellow' || s === 'green';
    });
  } else if (activeTab.value === 'purple') {
    result = result.filter(g => getStatusKey(g.groupDays) === 'purple');
  }

  // Category filter
  if (filterCategory.value) {
    result = result.filter(g => g.category === filterCategory.value);
  }

  // Search filter
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

const sectionOrder = ['Сухой', 'Холод', 'Мороз', ''];
const sectionLabels = { 'Сухой': 'Сухой', 'Холод': 'Холод', 'Мороз': 'Мороз', '': 'Без категории' };

const sectionedGroups = computed(() => {
  const map = {};
  for (const cat of sectionOrder) map[cat] = [];
  for (const g of filteredGroups.value) {
    const cat = sectionOrder.includes(g.category) ? g.category : '';
    map[cat].push(g);
  }
  return sectionOrder
    .filter(cat => map[cat].length > 0)
    .map(cat => ({ key: cat, label: sectionLabels[cat], groups: map[cat] }));
});

const totalItemsWithData = computed(() => groupsWithData.value.reduce((s, g) => s + g.items.length, 0));

const criticalGroups = computed(() => groupsWithData.value.filter(g => getStatusKey(g.groupDays) === 'red'));
const warningGroups = computed(() => groupsWithData.value.filter(g => getStatusKey(g.groupDays) === 'orange'));

const statusCounts = computed(() => {
  let red = 0, orange = 0, yellow = 0, green = 0, purple = 0;
  for (const g of groupsWithData.value) {
    const s = getStatusKey(g.groupDays);
    if (s === 'red') red++;
    else if (s === 'orange') orange++;
    else if (s === 'yellow') yellow++;
    else if (s === 'green') green++;
    else purple++;
  }
  return { red, orange, yellow, green, purple };
});

function toggleSection(key) {
  if (expandedSections.has(key)) expandedSections.delete(key);
  else expandedSections.add(key);
}

function toggleGroup(name) {
  if (expandedGroups.has(name)) expandedGroups.delete(name);
  else expandedGroups.add(name);
}

function goToOrder(supplier) {
  router.push({ name: 'order', query: { supplier } });
}

function daysClass(days) {
  return 'anv-d-' + getStatusKey(days);
}

function groupRowClass(days) {
  return 'anv-grow-' + getStatusKey(days);
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

// Unmatched items
const unmatchedItems = ref([]);
const showUnmatched = ref(false);

async function doImport() {
  importLoading.value = true;
  try {
    const result = await importFromFile('analysis', items.value, orderStore.settings.legalEntity);
    if (!result) return;
    if (result.error) { toast.error('Ошибка импорта', result.error); return; }
    const imported = result.items;
    items.value = imported;
    toast.success('Импорт завершён', `Сопоставлено: ${result.matched} из ${result.total} (файл)`);
    await saveDataToDB();
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
      item.stock = Math.round((d.stock || 0) * 10) / 10;
      const daily = (d.period_days || 30) > 0 ? (d.consumption || 0) / (d.period_days || 30) : 0;
      item.consumption = Math.round(daily * periodDays.value * 10) / 10;
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
onBeforeUnmount(() => { clearTimeout(_saveTimer); });
</script>

<style scoped>
.analysis-view {
  display: flex;
  flex-direction: column;
  height: 100%;
  gap: 0;
  overflow: hidden;
}

/* ═══ Header ═══ */
.anv-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-shrink: 0;
  margin-bottom: 8px;
  flex-wrap: wrap;
  gap: 8px;
}
.anv-header-controls {
  display: flex;
  align-items: center;
  gap: 10px;
}

/* Unit toggle */
.anv-unit-toggle {
  display: flex;
  border: 1.5px solid var(--border);
  border-radius: 8px;
  overflow: hidden;
}
.anv-unit-btn {
  padding: 5px 12px;
  font-size: 12px;
  font-weight: 600;
  border: none;
  background: var(--card);
  color: var(--text-muted);
  cursor: pointer;
  transition: all 0.15s;
}
.anv-unit-btn:first-child { border-right: 1px solid var(--border-light); }
.anv-unit-btn:hover { background: var(--bg); }
.anv-unit-btn.active {
  background: var(--bk-brown);
  color: white;
}

/* ═══ Alert Banner ═══ */
.anv-alert-banner {
  padding: 8px 14px;
  background: #FFF3E0;
  border: 1px solid #FFCC80;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 600;
  color: #E65100;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
  transition: background 0.15s;
}
.anv-alert-banner:hover { background: #FFE0B2; }
.anv-alert-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: #E65100;
  color: white;
  font-size: 11px;
  font-weight: 800;
  flex-shrink: 0;
}
.anv-alert-arrow {
  margin-left: auto;
  font-size: 14px;
}

/* ═══ KPI Cards ═══ */
.anv-kpi-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 10px;
  margin-bottom: 8px;
}
.anv-kpi-card {
  background: var(--card);
  border: 1px solid var(--border-light);
  border-radius: 10px;
  padding: 12px 14px;
}
.anv-kpi-danger { border-left: 3px solid #EF5350; }
.anv-kpi-warn { border-left: 3px solid #FF9800; }
.anv-kpi-head {
  font-size: 11px;
  color: var(--text-muted);
  font-weight: 600;
  margin-bottom: 2px;
}
.anv-kpi-val {
  font-size: 24px;
  font-weight: 800;
  color: var(--text);
  line-height: 1.2;
}
.anv-kpi-danger .anv-kpi-val { color: #C62828; }
.anv-kpi-warn .anv-kpi-val { color: #E65100; }
.anv-kpi-sub {
  font-size: 10px;
  color: var(--text-muted);
  margin-top: 1px;
}

/* ═══ Distribution Bar ═══ */
.anv-dist-bar {
  display: flex;
  height: 6px;
  border-radius: 3px;
  overflow: hidden;
  margin-bottom: 8px;
  flex-shrink: 0;
  min-width: 0;
}
.anv-dist-seg { min-width: 4px; }
.anv-dist-red { background: #EF5350; }
.anv-dist-orange { background: #FF9800; }
.anv-dist-yellow { background: #AED581; }
.anv-dist-green { background: #66BB6A; }
.anv-dist-purple { background: #AB47BC; }

/* ═══ Tabs ═══ */
.anv-tabs {
  display: flex;
  gap: 0;
  border-bottom: 2px solid var(--border-light);
  margin-bottom: 8px;
  flex-shrink: 0;
}
.anv-tab {
  padding: 7px 14px;
  font-size: 12px;
  font-weight: 600;
  border: none;
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  background: none;
  color: var(--text-muted);
  cursor: pointer;
  white-space: nowrap;
  display: flex;
  align-items: center;
  gap: 5px;
  transition: color 0.15s;
}
.anv-tab:hover { color: var(--text-secondary); }
.anv-tab-dot {
  display: inline-block;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  flex-shrink: 0;
}
.anv-tab.active {
  color: var(--text);
  border-bottom-color: var(--bk-brown);
}
.anv-tab-cnt {
  font-size: 10px;
  font-weight: 700;
  padding: 0 6px;
  border-radius: 8px;
  line-height: 16px;
  background: var(--border-light);
  color: var(--text-muted);
}
.anv-tab.active .anv-tab-cnt {
  background: var(--bk-brown);
  color: white;
}
.anv-tab-cnt-red { background: #FFEBEE; color: #C62828; }
.anv-tab.active .anv-tab-cnt-red { background: #EF5350; color: white; }
.anv-tab-cnt-orange { background: #FFF3E0; color: #E65100; }
.anv-tab.active .anv-tab-cnt-orange { background: #FF9800; color: white; }
.anv-tab-cnt-purple { background: #F3E5F5; color: #7B1FA2; }
.anv-tab.active .anv-tab-cnt-purple { background: #AB47BC; color: white; }

/* ═══ Toolbar ═══ */
.anv-toolbar {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
  flex-wrap: wrap;
  margin-bottom: 8px;
}
.anv-compact-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border: 1.5px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--card);
  cursor: pointer;
  color: var(--text-muted);
  transition: all 0.15s;
}
.anv-compact-btn:hover { border-color: var(--bk-orange); color: var(--text); }
.anv-compact-btn.active { border-color: var(--bk-orange); background: #FFFBF5; color: var(--bk-brown); }

.anv-toolbar-sep {
  width: 1px;
  height: 20px;
  background: var(--border-light);
  margin: 0 2px;
}

.anv-chip-filter { position: relative; }
.anv-chip-select {
  padding: 4px 24px 4px 8px;
  border-radius: 14px;
  border: 1.5px solid var(--border);
  font-size: 11px;
  font-weight: 600;
  background: var(--card);
  color: var(--text-secondary);
  cursor: pointer;
  appearance: none;
  -webkit-appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg width='10' height='6' viewBox='0 0 10 6' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1L5 5L9 1' stroke='%237A6B5F' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 8px center;
}
.anv-chip-select:hover { border-color: var(--bk-orange); }

.anv-search-wrap {
  position: relative;
}
.anv-search {
  padding: 5px 24px 5px 10px;
  border-radius: var(--radius-sm);
  border: 1.5px solid var(--border);
  font-size: 12px;
  font-weight: 600;
  width: 220px;
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

/* ═══ Empty state ═══ */
.anv-empty {
  text-align: center;
  padding: 60px 20px;
  color: var(--text-muted);
}

/* ═══ Table Card ═══ */
.anv-table-card {
  flex: 1;
  min-height: 0;
  background: var(--card);
  border: 1px solid var(--border-light);
  border-radius: var(--radius);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}
.anv-table-wrap {
  flex: 1;
  min-height: 0;
  overflow: auto;
}
.anv-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
  table-layout: auto;
}

/* Table header */
.anv-table thead th {
  padding: 8px 10px;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  color: var(--text-muted);
  background: var(--bg);
  border-bottom: 2px solid var(--border-light);
  position: sticky;
  top: 0;
  z-index: 20;
  text-align: left;
}
.anv-th-toggle { width: 28px; }
.anv-th-name { }
.anv-th-num { width: 80px; text-align: right !important; }
.anv-th-days { width: 70px; text-align: center !important; }
.anv-th-action { width: 36px; }

/* ═══ Section Row (Сухой/Холод/Мороз) ═══ */
.anv-section-row {
  cursor: pointer;
  user-select: none;
}
.anv-section-row td {
  background: #F5F0EA;
  border-bottom: 1px solid var(--border);
  padding: 7px 10px;
  font-size: 12px;
}
.anv-section-row:hover td { background: #EDE6DC; }
.anv-section-label {
  font-size: 11px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.6px;
  color: var(--bk-brown);
}
.anv-section-count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 16px;
  border-radius: 8px;
  background: var(--bk-brown);
  color: white;
  font-size: 10px;
  font-weight: 700;
  margin-left: 8px;
  padding: 0 5px;
}

/* Chevron icon */
.anv-chevron {
  color: var(--bk-brown-light);
  transition: transform 0.15s;
  vertical-align: middle;
  margin-right: 6px;
  flex-shrink: 0;
}
.anv-chevron.open { transform: rotate(90deg); }

/* ═══ Group Row ═══ */
.anv-group-row {
  cursor: pointer;
  user-select: none;
}
.anv-group-row td {
  padding: 6px 10px;
  border-bottom: 1px solid var(--border-light);
  font-size: 12px;
  background: var(--card);
  transition: background 0.1s;
}

/* Status color indicator — left border */
.anv-group-row.anv-grow-red td:first-child { box-shadow: inset 3px 0 0 #EF5350; }
.anv-group-row.anv-grow-orange td:first-child { box-shadow: inset 3px 0 0 #FF9800; }
.anv-group-row.anv-grow-yellow td:first-child { box-shadow: inset 3px 0 0 #FDD835; }
.anv-group-row.anv-grow-green td:first-child { box-shadow: inset 3px 0 0 #66BB6A; }
.anv-group-row.anv-grow-purple td:first-child { box-shadow: inset 3px 0 0 #AB47BC; }

/* Light background tint for critical */
.anv-group-row.anv-grow-red td { background: #FFF8F7; }
.anv-group-row.anv-grow-red:hover td { background: #FFEFED; }
.anv-group-row.anv-grow-orange td { background: #FFFBF5; }
.anv-group-row.anv-grow-orange:hover td { background: #FFF3E0; }
.anv-group-row:hover td { background: #FFF8F0; }
.anv-group-row.anv-grow-red:hover td { background: #FFEFED; }
.anv-group-row.anv-grow-orange:hover td { background: #FFF3E0; }

.anv-td-toggle {
  text-align: center;
  width: 28px;
}
.anv-td-name { text-align: left; }
.anv-td-name-item { padding-left: 24px !important; }
.anv-td-num {
  text-align: right;
  font-weight: 600;
  color: var(--text-secondary);
  font-size: 12px;
}
.anv-td-days { text-align: center; }
.anv-td-action {
  text-align: center;
  padding: 2px !important;
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

/* ═══ Item Row ═══ */
.anv-item-row td {
  font-size: 11px;
  padding: 3px 10px;
  border-bottom: 1px solid var(--border-light);
  color: var(--text-secondary);
}
.anv-sku {
  font-family: monospace;
  font-size: 10px;
  color: var(--text-muted);
  margin-right: 4px;
}
.anv-item-name { font-size: 11px; }
.anv-item-supplier {
  font-size: 9px;
  color: var(--text-muted);
  margin-left: 6px;
  opacity: 0.7;
}

/* ═══ Inline editing ═══ */
.anv-editable { cursor: default; }
.anv-editable:hover { background: rgba(0,0,0,.03); }
.anv-inline-input {
  width: 60px;
  padding: 1px 4px;
  font-size: 12px;
  text-align: right;
  border: 1.5px solid var(--bk-orange);
  border-radius: 4px;
  outline: none;
  background: #fff;
}

/* ═══ Foreign items ═══ */
.anv-item-foreign td { opacity: 0.65; }
.anv-foreign-badge {
  display: inline-block;
  font-size: 9px;
  line-height: 1;
  padding: 2px 5px;
  margin-left: 6px;
  border-radius: 3px;
  background: #E3F2FD;
  color: #1565C0;
  vertical-align: middle;
  white-space: nowrap;
}
.anv-d-muted {
  background: transparent;
  color: var(--text-muted);
  font-weight: 400;
}

/* ═══ Order button ═══ */
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

/* ═══ Days Badge ═══ */
.anv-days-badge {
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
.anv-d-yellow { background: #F1F8E9; color: #558B2F; }
.anv-d-green { background: #E8F5E9; color: #2E7D32; }
.anv-d-purple { background: #F3E5F5; color: #6A1B9A; }

/* ═══ Compact Mode ═══ */
.anv-compact .anv-section-row td { padding: 5px 6px; }
.anv-compact .anv-section-label { font-size: 10px; }
.anv-compact .anv-group-row td { padding: 3px 6px; font-size: 11px; }
.anv-compact .anv-item-row td { padding: 2px 6px; font-size: 10px; }
.anv-compact .anv-days-badge { font-size: 10px; padding: 0 6px; min-width: 26px; }
.anv-compact .anv-days-sm { font-size: 9px; padding: 0 4px; min-width: 22px; }
.anv-compact .anv-group-name { font-size: 11px; }
.anv-compact .anv-sku { font-size: 9px; }
.anv-compact .anv-item-name { font-size: 10px; }
.anv-compact .anv-item-supplier { display: none; }
.anv-compact .anv-group-cnt { font-size: 9px; }

/* ═══ Responsive ═══ */
@media (max-width: 900px) {
  .analysis-view {
    overflow: auto !important;
    height: auto !important;
    min-height: 0;
  }
  .anv-table-card {
    flex: none;
  }
  .anv-table-wrap {
    flex: none;
    overflow-x: auto;
    overflow-y: visible;
    height: auto;
  }
  .anv-kpi-grid { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 768px) {
  .anv-header { flex-direction: column; align-items: flex-start; gap: 8px; }
  .anv-header-controls { width: 100%; justify-content: flex-end; }
  .anv-kpi-grid { grid-template-columns: repeat(2, 1fr); gap: 6px; }
  .anv-kpi-val { font-size: 20px; }
  .anv-tabs { gap: 0; }
  .anv-tab { padding: 6px 8px; font-size: 11px; }
  .anv-toolbar { flex-wrap: wrap; }
  .anv-search { width: 100%; font-size: 14px; min-height: 36px; }
  .anv-search-wrap { width: 100%; margin-left: 0; }

  /* Table card-like layout */
  .anv-table thead { display: none; }
  .anv-table, .anv-table tbody { display: block; width: 100%; }

  .anv-group-row {
    display: flex !important;
    align-items: center;
    gap: 6px;
    padding: 6px 8px;
    border-bottom: 1px solid var(--border);
  }
  .anv-group-row td {
    display: contents;
    padding: 0 !important;
    border: none !important;
    background: none !important;
  }
  .anv-group-row td.anv-td-toggle {
    display: inline;
    flex-shrink: 0;
    width: auto;
  }
  .anv-group-row td.anv-td-name {
    display: inline;
    flex: 1;
    min-width: 0;
    overflow: hidden;
  }
  .anv-group-row td.anv-td-num { display: none !important; }
  .anv-group-row td.anv-td-days {
    display: inline;
    margin-left: auto;
    flex-shrink: 0;
  }
  .anv-group-row td.anv-td-action { display: none !important; }

  .anv-item-row {
    display: flex !important;
    align-items: center;
    gap: 4px;
    padding: 3px 8px 3px 20px;
    border-bottom: 1px solid var(--border-light);
  }
  .anv-item-row td {
    display: contents;
    padding: 0 !important;
    border: none !important;
  }
  .anv-item-row td:first-child { display: none !important; }
  .anv-item-row td.anv-td-name {
    display: inline;
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    padding-left: 0 !important;
  }
  .anv-item-row td.anv-td-num { display: none !important; }
  .anv-item-row td.anv-td-days {
    display: inline;
    margin-left: auto;
    flex-shrink: 0;
  }
  .anv-item-row td:last-child { display: none !important; }
  .anv-item-supplier { display: none; }
}

@media (max-width: 480px) {
  .anv-kpi-grid { grid-template-columns: 1fr 1fr; gap: 6px; }
  .anv-kpi-card { padding: 8px 10px; }
  .anv-kpi-val { font-size: 18px; }
  .anv-tab { padding: 5px 6px; font-size: 10px; }
  .anv-tab-cnt { font-size: 9px; padding: 0 4px; }
  .anv-chip-select { font-size: 10px; }
}

/* ═══ Modal ═══ */
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
  background: #FFF8ED;
}
.anv-modal-footer {
  padding: 10px 18px 14px;
  display: flex;
  justify-content: flex-end;
}
</style>
