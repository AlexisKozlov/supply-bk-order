<template>
  <div class="plt">
    <!-- Header -->
    <div class="plt-top">
      <h1 class="plt-title">Калькулятор паллет</h1>
      <div class="plt-top-right">
        <select v-model="legalEntity" class="plt-select" @change="onEntityChange">
          <option v-for="e in entities" :key="e" :value="e">{{ shortName(e) }}</option>
        </select>
      </div>
    </div>

    <!-- Tabs -->
    <div class="plt-tabs">
      <button v-for="t in tabs" :key="t.key" class="plt-tab" :class="{ active: tab === t.key }" @click="switchTab(t.key)">
        {{ t.label }}
      </button>
    </div>

    <!-- ═══ TAB: КАЛЬКУЛЯТОР ═══ -->
    <div v-if="tab === 'calculator'" class="plt-body">
      <div class="plt-calc">
        <!-- Delivery header -->
        <div class="plt-calc-header">
          <div class="plt-field">
            <label>Дата</label>
            <input type="date" v-model="calcDate" class="plt-input" />
          </div>
          <div class="plt-field" style="flex:1;min-width:200px;">
            <label>Поставщик</label>
            <input type="text" v-model="calcSupplierName" class="plt-input" placeholder="Введите название поставщика" />
          </div>
        </div>

        <!-- Product search -->
        <div v-if="calcSupplierName.trim()" class="plt-calc-search">
          <div class="plt-field" style="flex:1;max-width:500px;">
            <label>Поиск товара по артикулу или названию</label>
            <div class="plt-search-wrap">
              <input
                type="text" v-model="productSearch" class="plt-input plt-search-input"
                placeholder="Введите артикул или часть названия..."
                @input="onProductSearch"
                @keydown.down.prevent="searchHighlight = Math.min(searchHighlight + 1, searchResults.length - 1)"
                @keydown.up.prevent="searchHighlight = Math.max(searchHighlight - 1, 0)"
                @keydown.enter.prevent="addSearchResult"
                @keydown.escape="searchResults = []"
              />
              <!-- Search dropdown -->
              <div v-if="searchResults.length" class="plt-search-dropdown">
                <div
                  v-for="(p, i) in searchResults" :key="p.id"
                  class="plt-search-item"
                  :class="{ highlighted: i === searchHighlight }"
                  @click="addProduct(p)"
                >
                  <span class="plt-search-sku">{{ p.sku || '—' }}</span>
                  <span class="plt-search-name">{{ p.name }}</span>
                  <span class="plt-badge" :class="p.storage_type">{{ p.storage_type === 'cold' ? 'Х' : 'М' }}</span>
                  <span class="plt-search-bpp">{{ p.boxes_per_pallet }} кор/пал</span>
                </div>
              </div>
            </div>
          </div>
          <button class="plt-btn sm outline" @click="showBulkPaste = true" title="Вставить список товаров из 1С">Вставить список</button>
        </div>

        <!-- Bulk paste modal -->
        <div v-if="showBulkPaste" class="plt-overlay" @click.self="showBulkPaste = false">
          <div class="plt-modal" style="max-width:600px;">
            <div class="plt-modal-head">
              <span>Вставить список товаров</span>
              <button class="plt-btn-icon" @click="showBulkPaste = false"><BkIcon name="close" :size="18" /></button>
            </div>
            <div class="plt-modal-body">
              <div class="plt-field">
                <label>Скопируйте названия товаров из 1С (каждый с новой строки)</label>
                <textarea v-model="bulkText" class="plt-textarea" rows="8" placeholder="52373 Контейнер для салата бумажный...&#10;55000 Воппер Джуниор 270х335...&#10;55001 Бумага оберточная Воппер..."></textarea>
              </div>
              <div v-if="bulkResults" class="plt-bulk-results">
                <div class="plt-bulk-found">Найдено: <strong>{{ bulkResults.found }}</strong> из {{ bulkResults.total }}</div>
                <div v-if="bulkResults.notFound.length" class="plt-bulk-missing">
                  <div class="plt-bulk-missing-title">Не найдены:</div>
                  <div v-for="(line, i) in bulkResults.notFound" :key="i" class="plt-bulk-missing-item">{{ line }}</div>
                </div>
              </div>
            </div>
            <div class="plt-modal-foot">
              <div style="flex:1"></div>
              <button class="plt-btn outline" @click="showBulkPaste = false">Отмена</button>
              <button class="plt-btn outline" @click="parseBulkText">Найти</button>
              <button class="plt-btn fill" :disabled="!bulkResults || !bulkResults.found" @click="applyBulkProducts">Добавить ({{ bulkResults?.found || 0 }})</button>
            </div>
          </div>
        </div>

        <!-- Added items -->
        <div v-if="calcItems.length" class="plt-calc-items">
          <table class="plt-table">
            <thead>
              <tr>
                <th style="width:50px">#</th>
                <th>Товар</th>
                <th style="width:70px">Тип</th>
                <th style="width:90px">Кор/пал</th>
                <th style="width:120px">Коробок</th>
                <th style="width:90px">Паллет</th>
                <th style="width:50px"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(item, idx) in calcItems" :key="idx" :class="{ 'has-value': item.boxes > 0 }">
                <td class="num">{{ idx + 1 }}</td>
                <td class="item-name">{{ item.name }}</td>
                <td>
                  <select v-model="item.storage_type" class="plt-select-sm storage-select" @change="onItemStorageChange(item)" :title="item.storage_type === 'cold' ? 'Холод' : 'Мороз'">
                    <option value="cold">Х</option>
                    <option value="frozen">М</option>
                  </select>
                </td>
                <td class="num">{{ item.boxes_per_pallet }}</td>
                <td>
                  <input type="number" v-model.number="item.boxes" min="0" class="plt-input-num calc-input"
                    @input="recalcItem(item)" placeholder="0"
                    @paste.prevent="onPasteBoxes($event, idx)" />
                </td>
                <td class="num pallets">{{ item.pallets || '' }}</td>
                <td>
                  <button class="plt-btn-icon red" @click="calcItems.splice(idx, 1)" title="Убрать">
                    <BkIcon name="close" :size="14" />
                  </button>
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="plt-total">
                <td colspan="4">Итого</td>
                <td></td>
                <td class="num" colspan="2">
                  <div class="plt-total-badges">
                    <span v-if="calcTotalCold" class="plt-badge cold">Холод: {{ calcTotalCold }}</span>
                    <span v-if="calcTotalFrozen" class="plt-badge frozen">Мороз: {{ calcTotalFrozen }}</span>
                  </div>
                </td>
              </tr>
            </tfoot>
          </table>

          <div class="plt-calc-actions">
            <button class="plt-btn fill" :disabled="!calcCanSave" @click="saveDelivery">
              {{ editingDeliveryId ? 'Обновить поставку' : 'Сохранить поставку' }}
            </button>
            <button v-if="editingDeliveryId" class="plt-btn outline" @click="cancelEdit">Отмена</button>
          </div>
        </div>

        <!-- Saved deliveries for date -->
        <div class="plt-calc-saved">
          <div class="plt-section-head">
            <span class="plt-section-title">Поставки за {{ formatDateShort(calcDate) }}</span>
            <button class="plt-btn sm outline" @click="loadDateDeliveries">Обновить</button>
          </div>
          <div v-if="!dateDeliveries.length" class="plt-empty">Нет сохранённых поставок</div>
          <div v-for="d in dateDeliveries" :key="d.id" class="plt-delivery-card">
            <div class="plt-delivery-info">
              <strong>{{ d.supplier_name }}</strong>
              <span v-if="d.total_cold" class="plt-badge cold">Х: {{ d.total_cold }}</span>
              <span v-if="d.total_frozen" class="plt-badge frozen">М: {{ d.total_frozen }}</span>
              <span class="plt-delivery-time">{{ formatTime(d.created_at) }}</span>
            </div>
            <div class="plt-delivery-btns">
              <button class="plt-btn sm outline" @click="openDelivery(d)">Открыть</button>
              <button v-if="canEdit" class="plt-btn sm outline red-text" @click="deleteDelivery(d)">Удалить</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══ TAB: СВОДКА ═══ -->
    <div v-if="tab === 'summary'" class="plt-body">
      <div class="plt-sum">
        <div class="plt-sum-controls">
          <div class="plt-field">
            <label>Месяц</label>
            <input type="month" v-model="sumMonth" class="plt-input" @change="loadSummary" />
          </div>
          <div class="plt-sum-actions">
            <button v-if="canEdit" class="plt-btn sm outline" @click="addManualEntry">+ Запись</button>
            <button class="plt-btn sm outline" @click="exportSummaryExcel">Excel</button>
          </div>
        </div>

        <div v-if="sumLoading" class="plt-empty">Загрузка...</div>
        <div v-else class="plt-sum-table-wrap">
          <table class="plt-table sum-table">
            <thead>
              <tr>
                <th class="col-date">Дата</th>
                <th class="col-day">День</th>
                <th class="col-stock">Остатки Х</th>
                <th class="col-stock">Остатки М</th>
                <th class="col-deliveries">Приходы Холод</th>
                <th class="col-total">Итого Х</th>
                <th class="col-deliveries">Приходы Мороз</th>
                <th class="col-total">Итого М</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in sumRows" :key="row.date" :class="{ weekend: row.isWeekend, today: row.isToday }">
                <td class="col-date">{{ row.dateStr }}</td>
                <td class="col-day">{{ row.dayName }}</td>
                <td class="col-stock" @dblclick="editStock(row, 'cold')">
                  <template v-if="editingStock?.date === row.date && editingStock?.type === 'cold'">
                    <input type="number" v-model.number="editingStock.value" class="plt-input-num stock-input"
                      @keyup.enter="saveStock" @keyup.escape="editingStock = null" @blur="saveStock" />
                  </template>
                  <span v-else class="stock-val" :class="{ empty: row.coldStock == null }">{{ row.coldStock ?? '—' }}</span>
                </td>
                <td class="col-stock" @dblclick="editStock(row, 'frozen')">
                  <template v-if="editingStock?.date === row.date && editingStock?.type === 'frozen'">
                    <input type="number" v-model.number="editingStock.value" class="plt-input-num stock-input"
                      @keyup.enter="saveStock" @keyup.escape="editingStock = null" @blur="saveStock" />
                  </template>
                  <span v-else class="stock-val" :class="{ empty: row.frozenStock == null }">{{ row.frozenStock ?? '—' }}</span>
                </td>
                <td class="col-deliveries">
                  <span v-if="row.coldEntries.length" class="plt-entries">
                    <span v-for="(e, i) in row.coldEntries" :key="i" class="plt-entry" @dblclick="canEdit && editSummaryEntry(e)">
                      {{ e.supplier_name }} ({{ e.cold_pallets }})
                    </span>
                  </span>
                </td>
                <td class="col-total num">{{ row.totalCold || '' }}</td>
                <td class="col-deliveries">
                  <span v-if="row.frozenEntries.length" class="plt-entries">
                    <span v-for="(e, i) in row.frozenEntries" :key="i" class="plt-entry" @dblclick="canEdit && editSummaryEntry(e)">
                      {{ e.supplier_name }} ({{ e.frozen_pallets }})
                    </span>
                  </span>
                </td>
                <td class="col-total num">{{ row.totalFrozen || '' }}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="plt-total">
                <td colspan="2">Итого</td>
                <td class="num"></td>
                <td class="num"></td>
                <td class="num"></td>
                <td class="num">{{ sumTotalColdDeliveries || '' }}</td>
                <td class="num"></td>
                <td class="num">{{ sumTotalFrozenDeliveries || '' }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>

    <!-- ═══ TAB: СПРАВОЧНИК ═══ -->
    <div v-if="tab === 'reference'" class="plt-body">
      <div class="plt-ref">
        <div class="plt-ref-controls">
          <div class="plt-field" style="flex:1;max-width:400px;">
            <input type="text" v-model="refSearch" class="plt-input" placeholder="Поиск по артикулу или названию..." />
          </div>
          <div class="plt-ref-stats">{{ filteredRefProducts.length }} из {{ refProducts.length }} товаров</div>
          <button v-if="canEdit" class="plt-btn sm fill" @click="addRefProduct">+ Добавить товар</button>
        </div>
        <div class="plt-ref-table-wrap">
          <table class="plt-table ref-table">
            <thead>
              <tr>
                <th style="width:90px">Артикул</th>
                <th>Наименование</th>
                <th style="width:80px">Хранение</th>
                <th style="width:90px">Кор/пал</th>
                <th v-if="canEdit" style="width:80px"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in paginatedRefProducts" :key="p.id">
                <td class="ref-sku">{{ p.sku || '—' }}</td>
                <td>
                  <template v-if="editingRef === p.id">
                    <input v-model="editRefData.name" class="plt-input-inline" @keyup.enter="saveRefProduct(p)" @keyup.escape="editingRef = null" />
                  </template>
                  <span v-else>{{ p.name }}</span>
                </td>
                <td>
                  <template v-if="editingRef === p.id">
                    <select v-model="editRefData.storage_type" class="plt-select-sm">
                      <option value="cold">Холод</option>
                      <option value="frozen">Мороз</option>
                    </select>
                  </template>
                  <span v-else class="plt-badge" :class="p.storage_type">{{ p.storage_type === 'cold' ? 'Холод' : 'Мороз' }}</span>
                </td>
                <td>
                  <template v-if="editingRef === p.id">
                    <input v-model.number="editRefData.boxes_per_pallet" type="number" min="1" class="plt-input-num" @keyup.enter="saveRefProduct(p)" @keyup.escape="editingRef = null" />
                  </template>
                  <span v-else class="num">{{ p.boxes_per_pallet }}</span>
                </td>
                <td v-if="canEdit" class="col-actions">
                  <template v-if="editingRef === p.id">
                    <button class="plt-btn-icon green" @click="saveRefProduct(p)"><BkIcon name="check" :size="14" /></button>
                    <button class="plt-btn-icon" @click="editingRef = null"><BkIcon name="close" :size="14" /></button>
                  </template>
                  <template v-else>
                    <button class="plt-btn-icon" @click="startEditRef(p)"><BkIcon name="edit" :size="14" /></button>
                    <button class="plt-btn-icon red" @click="deleteRefProduct(p)"><BkIcon name="trash" :size="14" /></button>
                  </template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <!-- Pagination -->
        <div v-if="refTotalPages > 1" class="plt-pagination">
          <button class="plt-btn sm outline" :disabled="refPage <= 1" @click="refPage--">&laquo;</button>
          <span class="plt-page-info">{{ refPage }} / {{ refTotalPages }}</span>
          <button class="plt-btn sm outline" :disabled="refPage >= refTotalPages" @click="refPage++">&raquo;</button>
        </div>
      </div>
    </div>

    <!-- ═══ MODAL: Manual Entry ═══ -->
    <div v-if="showEntryModal" class="plt-overlay" @click.self="showEntryModal = false">
      <div class="plt-modal">
        <div class="plt-modal-head">
          <span>{{ entryModalEdit ? 'Редактировать запись' : 'Новая запись' }}</span>
          <button class="plt-btn-icon" @click="showEntryModal = false"><BkIcon name="close" :size="18" /></button>
        </div>
        <div class="plt-modal-body">
          <div class="plt-field"><label>Дата</label><input type="date" v-model="entryForm.entry_date" class="plt-input" /></div>
          <div class="plt-field"><label>Поставщик</label><input type="text" v-model="entryForm.supplier_name" class="plt-input" placeholder="Название" /></div>
          <div class="plt-field-row">
            <div class="plt-field"><label>Холод (паллет)</label><input type="number" v-model.number="entryForm.cold_pallets" class="plt-input" min="0" /></div>
            <div class="plt-field"><label>Мороз (паллет)</label><input type="number" v-model.number="entryForm.frozen_pallets" class="plt-input" min="0" /></div>
          </div>
        </div>
        <div class="plt-modal-foot">
          <button v-if="entryModalEdit" class="plt-btn outline red-text" @click="deleteSummaryEntry">Удалить</button>
          <div style="flex:1"></div>
          <button class="plt-btn outline" @click="showEntryModal = false">Отмена</button>
          <button class="plt-btn fill" @click="saveEntryModal">Сохранить</button>
        </div>
      </div>
    </div>

    <!-- ═══ MODAL: Confirm ═══ -->
    <div v-if="cfm.show" class="plt-overlay" @click.self="cfmCancel">
      <div class="plt-modal" style="max-width:400px;">
        <div class="plt-modal-head">
          <span>{{ cfm.title }}</span>
          <button class="plt-btn-icon" @click="cfmCancel"><BkIcon name="close" :size="18" /></button>
        </div>
        <div class="plt-modal-body"><p style="margin:0;font-size:14px;color:#555;">{{ cfm.text }}</p></div>
        <div class="plt-modal-foot">
          <div style="flex:1"></div>
          <button class="plt-btn outline" @click="cfmCancel">Отмена</button>
          <button class="plt-btn fill" :class="{ 'btn-danger': cfm.danger }" @click="cfmOk">{{ cfm.btn || 'Да' }}</button>
        </div>
      </div>
    </div>

    <!-- ═══ MODAL: Prompt ═══ -->
    <div v-if="pmt.show" class="plt-overlay" @click.self="pmtCancel">
      <div class="plt-modal" style="max-width:420px;">
        <div class="plt-modal-head">
          <span>{{ pmt.title }}</span>
          <button class="plt-btn-icon" @click="pmtCancel"><BkIcon name="close" :size="18" /></button>
        </div>
        <div class="plt-modal-body">
          <div v-for="(f, idx) in pmt.fields" :key="idx" class="plt-field">
            <label>{{ f.label }}</label>
            <input
              v-if="f.type !== 'select'"
              :type="f.type || 'text'" v-model="f.value" class="plt-input"
              :placeholder="f.placeholder || ''"
              :min="f.min" :ref="idx === 0 ? 'pmtFirstInput' : undefined"
              @keyup.enter="pmtOk"
            />
            <select v-else v-model="f.value" class="plt-select" style="width:100%">
              <option v-for="o in f.options" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </div>
        </div>
        <div class="plt-modal-foot">
          <div style="flex:1"></div>
          <button class="plt-btn outline" @click="pmtCancel">Отмена</button>
          <button class="plt-btn fill" @click="pmtOk">{{ pmt.btn || 'Сохранить' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue';
import { db } from '@/lib/apiClient.js';
import { useUserStore } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { LEGAL_ENTITIES, ENTITY_SHORT_NAMES } from '@/lib/legalEntities.js';
import BkIcon from '@/components/ui/BkIcon.vue';

const userStore = useUserStore();
const toastStore = useToastStore();

const entities = LEGAL_ENTITIES;
const legalEntity = ref(userStore.currentUser?.legal_entity || entities[0]);
const tab = ref('calculator');
const tabs = [
  { key: 'calculator', label: 'Калькулятор' },
  { key: 'summary', label: 'Сводка' },
  { key: 'reference', label: 'Справочник' },
];

const canEdit = computed(() => userStore.hasAccess('pallet-calc', 'edit'));
const shortName = (e) => ENTITY_SHORT_NAMES[e] || e;

function entityGroup(entity) {
  return entity === 'ООО "Пицца Стар"' ? 'ps' : 'bk_vm';
}

// ═══ Confirm / Prompt modals ═══
const cfm = ref({ show: false, title: '', text: '', btn: '', danger: false, _resolve: null });
function showConfirm(title, text, { btn = 'Да', danger = false } = {}) {
  return new Promise(resolve => { cfm.value = { show: true, title, text, btn, danger, _resolve: resolve }; });
}
function cfmOk() { cfm.value._resolve?.(true); cfm.value.show = false; }
function cfmCancel() { cfm.value._resolve?.(false); cfm.value.show = false; }

const pmt = ref({ show: false, title: '', fields: [], btn: '', _resolve: null });
const pmtFirstInput = ref(null);
function showPrompt(title, fields, { btn = 'Сохранить' } = {}) {
  return new Promise(resolve => {
    pmt.value = { show: true, title, fields, btn, _resolve: resolve };
    nextTick(() => { pmtFirstInput.value?.[0]?.focus?.(); });
  });
}
function pmtOk() { pmt.value._resolve?.(pmt.value.fields.map(f => f.value)); pmt.value.show = false; }
function pmtCancel() { pmt.value._resolve?.(null); pmt.value.show = false; }

// ═══ All products cache ═══
const allProducts = ref([]);

async function loadAllProducts() {
  try {
    const group = entityGroup(legalEntity.value);
    const { data, error } = await db.from('plt_products').select('*').eq('entity_group', group).order('sort_order').order('name');
    if (error) throw error;
    allProducts.value = data || [];
  } catch (e) {
    console.error('[PalletCalc]', e);
    toastStore.error('Ошибка загрузки справочника');
  }
}

// ═══ CALCULATOR ═══
const calcDate = ref(new Date().toISOString().slice(0, 10));
const calcSupplierName = ref('');
const calcItems = ref([]);
const editingDeliveryId = ref(null); // null = новая, число = редактирование
const productSearch = ref('');
const searchResults = ref([]);
const searchHighlight = ref(0);
const dateDeliveries = ref([]);
let searchTimeout = null;

function onProductSearch() {
  clearTimeout(searchTimeout);
  searchHighlight.value = 0;
  const q = productSearch.value.trim().toLowerCase();
  if (!q || q.length < 2) { searchResults.value = []; return; }

  searchTimeout = setTimeout(() => {
    const addedIds = new Set(calcItems.value.map(i => i.product_id));
    const results = allProducts.value.filter(p => {
      if (addedIds.has(p.id)) return false;
      const haystack = (p.sku || '') + ' ' + p.name;
      return haystack.toLowerCase().includes(q);
    });
    searchResults.value = results.slice(0, 15);
  }, 150);
}

function addSearchResult() {
  if (searchResults.value.length && searchHighlight.value >= 0) {
    addProduct(searchResults.value[searchHighlight.value]);
  }
}

function addProduct(p) {
  if (calcItems.value.some(i => i.product_id === p.id)) return;
  calcItems.value.push({
    product_id: p.id,
    name: p.name,
    sku: p.sku,
    storage_type: p.storage_type,
    boxes_per_pallet: p.boxes_per_pallet,
    boxes: 0,
    pallets: 0,
  });
  productSearch.value = '';
  searchResults.value = [];
}

function recalcItem(item) {
  item.pallets = (item.boxes > 0 && item.boxes_per_pallet > 0) ? Math.ceil(item.boxes / item.boxes_per_pallet) : 0;
}

async function onItemStorageChange(item) {
  // Update in reference DB too
  try {
    await db.from('plt_products').update({ storage_type: item.storage_type }).eq('id', item.product_id);
    // Update local cache
    const p = allProducts.value.find(x => x.id === item.product_id);
    if (p) p.storage_type = item.storage_type;
  } catch (e) {
    console.error('[PalletCalc] storage update', e);
  }
}

// ═══ Bulk paste ═══
const showBulkPaste = ref(false);
const bulkText = ref('');
const bulkResults = ref(null);
const bulkMatched = ref([]);

function parseBulkText() {
  const lines = bulkText.value.split('\n').map(l => l.trim()).filter(Boolean);
  const addedIds = new Set(calcItems.value.map(i => i.product_id));
  const found = [];
  const notFound = [];

  for (const line of lines) {
    // Extract SKU from beginning of line
    const skuMatch = line.match(/^(\d[\d_]*)/);
    let product = null;

    if (skuMatch) {
      const sku = skuMatch[1];
      product = allProducts.value.find(p => p.sku === sku && !addedIds.has(p.id));
    }
    // Fallback: search by substring
    if (!product) {
      const lower = line.toLowerCase();
      product = allProducts.value.find(p => !addedIds.has(p.id) && p.name.toLowerCase().includes(lower));
    }

    if (product) {
      found.push(product);
      addedIds.add(product.id);
    } else {
      notFound.push(line.length > 60 ? line.slice(0, 60) + '...' : line);
    }
  }

  bulkMatched.value = found;
  bulkResults.value = { found: found.length, total: lines.length, notFound };
}

function applyBulkProducts() {
  for (const p of bulkMatched.value) {
    if (!calcItems.value.some(i => i.product_id === p.id)) {
      calcItems.value.push({
        product_id: p.id, name: p.name, sku: p.sku,
        storage_type: p.storage_type, boxes_per_pallet: p.boxes_per_pallet,
        boxes: 0, pallets: 0,
      });
    }
  }
  showBulkPaste.value = false;
  bulkText.value = '';
  bulkResults.value = null;
  bulkMatched.value = [];
  toastStore.success(`Добавлено ${bulkMatched.value.length || 'товаров'}`);
}

// Paste column of numbers into boxes
function onPasteBoxes(event, startIdx) {
  const text = event.clipboardData?.getData('text') || '';
  const values = text.split(/[\n\r\t]+/).map(v => v.trim()).filter(Boolean);
  if (values.length <= 1) {
    // Single value — just set it normally
    const num = parseInt(values[0]);
    if (!isNaN(num)) {
      calcItems.value[startIdx].boxes = num;
      recalcItem(calcItems.value[startIdx]);
    }
    return;
  }
  // Multiple values — distribute starting from current row
  for (let i = 0; i < values.length && (startIdx + i) < calcItems.value.length; i++) {
    const num = parseInt(values[i]);
    if (!isNaN(num)) {
      calcItems.value[startIdx + i].boxes = num;
      recalcItem(calcItems.value[startIdx + i]);
    }
  }
  toastStore.success(`Вставлено ${Math.min(values.length, calcItems.value.length - startIdx)} значений`);
}

const calcTotalCold = computed(() => calcItems.value.filter(i => i.storage_type === 'cold').reduce((s, i) => s + (i.pallets || 0), 0));
const calcTotalFrozen = computed(() => calcItems.value.filter(i => i.storage_type === 'frozen').reduce((s, i) => s + (i.pallets || 0), 0));
const calcCanSave = computed(() => calcSupplierName.value.trim() && calcItems.value.some(i => i.boxes > 0));

async function saveDelivery() {
  if (!calcCanSave.value) return;
  try {
    const supplierName = calcSupplierName.value.trim();
    let deliveryId = editingDeliveryId.value;

    if (deliveryId) {
      // === UPDATE existing ===
      const { error: updErr } = await db.from('plt_deliveries').update({
        supplier_name: supplierName,
        total_cold: calcTotalCold.value,
        total_frozen: calcTotalFrozen.value,
      }).eq('id', deliveryId);
      if (updErr) throw updErr;

      // Delete old items and re-insert
      await db.from('plt_delivery_items').delete().eq('delivery_id', deliveryId);

      // Update summary
      const { data: existingSum } = await db.from('plt_summary').select('id').eq('delivery_id', deliveryId).maybeSingle();
      if (existingSum) {
        await db.from('plt_summary').update({
          supplier_name: supplierName,
          cold_pallets: calcTotalCold.value,
          frozen_pallets: calcTotalFrozen.value,
        }).eq('id', existingSum.id);
      }
    } else {
      // === CREATE new ===
      const { data: delData, error: delErr } = await db.from('plt_deliveries').insert({
        legal_entity: legalEntity.value,
        delivery_date: calcDate.value,
        supplier_name: supplierName,
        total_cold: calcTotalCold.value,
        total_frozen: calcTotalFrozen.value,
        created_by: userStore.currentUser?.display_name || null,
      });
      if (delErr) throw delErr;
      deliveryId = Array.isArray(delData) ? delData[0]?.id : delData?.id;
      if (!deliveryId) throw new Error('No delivery ID');

      // Add to summary
      const { error: sumErr } = await db.from('plt_summary').insert({
        legal_entity: legalEntity.value,
        entry_date: calcDate.value,
        supplier_name: supplierName,
        cold_pallets: calcTotalCold.value,
        frozen_pallets: calcTotalFrozen.value,
        delivery_id: deliveryId,
        is_manual: 0,
      });
      if (sumErr) throw sumErr;
    }

    // Insert items
    const items = calcItems.value.filter(i => i.boxes > 0).map(i => ({
      delivery_id: deliveryId,
      product_id: i.product_id,
      product_name: i.name,
      boxes_per_pallet: i.boxes_per_pallet,
      storage_type: i.storage_type,
      boxes: i.boxes,
      pallets: i.pallets,
    }));
    if (items.length) {
      const { error } = await db.from('plt_delivery_items').insert(items);
      if (error) throw error;
    }

    toastStore.success(editingDeliveryId.value ? 'Поставка обновлена' : 'Поставка сохранена');
    cancelEdit();
    await loadDateDeliveries();
  } catch (e) {
    console.error('[PalletCalc] saveDelivery', e);
    toastStore.error('Ошибка сохранения');
  }
}

function cancelEdit() {
  editingDeliveryId.value = null;
  calcItems.value = [];
  calcSupplierName.value = '';
}

async function loadDateDeliveries() {
  if (!calcDate.value) return;
  try {
    const { data, error } = await db.from('plt_deliveries').select('*').eq('legal_entity', legalEntity.value).eq('delivery_date', calcDate.value).order('created_at');
    if (error) throw error;
    dateDeliveries.value = data || [];
  } catch (e) {
    console.error('[PalletCalc]', e);
  }
}

async function openDelivery(d) {
  editingDeliveryId.value = d.id;
  calcSupplierName.value = d.supplier_name;
  try {
    const { data, error } = await db.from('plt_delivery_items').select('*').eq('delivery_id', d.id);
    if (error) throw error;
    calcItems.value = (data || []).map(item => ({
      product_id: item.product_id,
      name: item.product_name,
      storage_type: item.storage_type,
      boxes_per_pallet: item.boxes_per_pallet,
      boxes: item.boxes,
      pallets: item.pallets,
    }));
  } catch (e) {
    toastStore.error('Ошибка загрузки');
  }
}

async function deleteDelivery(d) {
  const ok = await showConfirm('Удалить поставку?', `Поставка «${d.supplier_name}» будет удалена из калькулятора и сводки.`, { btn: 'Удалить', danger: true });
  if (!ok) return;
  try {
    await db.from('plt_summary').delete().eq('delivery_id', d.id);
    const { error } = await db.from('plt_deliveries').delete().eq('id', d.id);
    if (error) throw error;
    toastStore.success('Удалено');
    await loadDateDeliveries();
  } catch (e) {
    toastStore.error('Ошибка удаления');
  }
}

function formatDateShort(dateStr) {
  const d = new Date(dateStr + 'T00:00:00');
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' });
}

function formatTime(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

// ═══ SUMMARY ═══
const sumMonth = ref(new Date().toISOString().slice(0, 7));
const sumLoading = ref(false);
const sumData = ref({ stock: [], entries: [] });
const editingStock = ref(null);

const DAYS_RU = ['вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'];

const sumRows = computed(() => {
  const [year, month] = sumMonth.value.split('-').map(Number);
  const daysInMonth = new Date(year, month, 0).getDate();
  const today = new Date().toISOString().slice(0, 10);
  const rows = [];
  for (let day = 1; day <= daysInMonth; day++) {
    const date = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    const d = new Date(date + 'T00:00:00');
    const dow = d.getDay();
    const stockRow = sumData.value.stock.find(s => s.stock_date === date);
    const dayEntries = sumData.value.entries.filter(e => e.entry_date === date);
    rows.push({
      date, dateStr: `${String(day).padStart(2, '0')}.${String(month).padStart(2, '0')}`,
      dayName: DAYS_RU[dow], isWeekend: dow === 0 || dow === 6, isToday: date === today,
      coldStock: stockRow?.cold_pallets ?? null, frozenStock: stockRow?.frozen_pallets ?? null,
      coldEntries: dayEntries.filter(e => e.cold_pallets > 0),
      frozenEntries: dayEntries.filter(e => e.frozen_pallets > 0),
      totalCold: dayEntries.reduce((s, e) => s + (e.cold_pallets || 0), 0),
      totalFrozen: dayEntries.reduce((s, e) => s + (e.frozen_pallets || 0), 0),
    });
  }
  return rows;
});

const sumTotalColdDeliveries = computed(() => sumData.value.entries.reduce((s, e) => s + (e.cold_pallets || 0), 0));
const sumTotalFrozenDeliveries = computed(() => sumData.value.entries.reduce((s, e) => s + (e.frozen_pallets || 0), 0));

async function loadSummary() {
  sumLoading.value = true;
  try {
    const [year, month] = sumMonth.value.split('-').map(Number);
    const from = `${year}-${String(month).padStart(2, '0')}-01`;
    const to = `${year}-${String(month).padStart(2, '0')}-${new Date(year, month, 0).getDate()}`;
    const [stockRes, entriesRes] = await Promise.all([
      db.from('plt_daily_stock').select('*').eq('legal_entity', legalEntity.value).gte('stock_date', from).lte('stock_date', to),
      db.from('plt_summary').select('*').eq('legal_entity', legalEntity.value).gte('entry_date', from).lte('entry_date', to).order('supplier_name'),
    ]);
    if (stockRes.error) throw stockRes.error;
    if (entriesRes.error) throw entriesRes.error;
    sumData.value = { stock: stockRes.data || [], entries: entriesRes.data || [] };
  } catch (e) {
    console.error('[PalletCalc]', e);
    toastStore.error('Ошибка загрузки сводки');
  } finally {
    sumLoading.value = false;
  }
}

function editStock(row, type) {
  if (!canEdit.value) return;
  editingStock.value = { date: row.date, type, value: type === 'cold' ? (row.coldStock ?? 0) : (row.frozenStock ?? 0) };
  nextTick(() => { document.querySelector('.stock-input')?.focus(); });
}

async function saveStock() {
  if (!editingStock.value) return;
  const { date, type, value } = editingStock.value;
  editingStock.value = null;
  try {
    const { data: existing } = await db.from('plt_daily_stock').select('id,cold_pallets,frozen_pallets').eq('legal_entity', legalEntity.value).eq('stock_date', date).maybeSingle();
    if (existing) {
      const upd = type === 'cold' ? { cold_pallets: value || 0 } : { frozen_pallets: value || 0 };
      await db.from('plt_daily_stock').update(upd).eq('id', existing.id);
    } else {
      await db.from('plt_daily_stock').insert({
        legal_entity: legalEntity.value, stock_date: date,
        cold_pallets: type === 'cold' ? (value || 0) : 0,
        frozen_pallets: type === 'frozen' ? (value || 0) : 0,
      });
    }
    await loadSummary();
  } catch (e) {
    toastStore.error('Ошибка сохранения остатков');
  }
}

// Manual entry modal
const showEntryModal = ref(false);
const entryModalEdit = ref(false);
const entryForm = ref({ id: null, entry_date: '', supplier_name: '', cold_pallets: 0, frozen_pallets: 0 });

function addManualEntry() {
  entryModalEdit.value = false;
  entryForm.value = { id: null, entry_date: new Date().toISOString().slice(0, 10), supplier_name: '', cold_pallets: 0, frozen_pallets: 0 };
  showEntryModal.value = true;
}

function editSummaryEntry(e) {
  entryModalEdit.value = true;
  entryForm.value = { id: e.id, entry_date: e.entry_date, supplier_name: e.supplier_name, cold_pallets: e.cold_pallets || 0, frozen_pallets: e.frozen_pallets || 0 };
  showEntryModal.value = true;
}

async function saveEntryModal() {
  const f = entryForm.value;
  if (!f.entry_date || !f.supplier_name?.trim()) { toastStore.error('Заполните дату и поставщика'); return; }
  try {
    if (f.id) {
      await db.from('plt_summary').update({ entry_date: f.entry_date, supplier_name: f.supplier_name.trim(), cold_pallets: f.cold_pallets || 0, frozen_pallets: f.frozen_pallets || 0, is_manual: 1 }).eq('id', f.id);
    } else {
      await db.from('plt_summary').insert({ legal_entity: legalEntity.value, entry_date: f.entry_date, supplier_name: f.supplier_name.trim(), cold_pallets: f.cold_pallets || 0, frozen_pallets: f.frozen_pallets || 0, is_manual: 1 });
    }
    showEntryModal.value = false;
    toastStore.success('Сохранено');
    await loadSummary();
  } catch (e) { toastStore.error('Ошибка сохранения'); }
}

async function deleteSummaryEntry() {
  const ok = await showConfirm('Удалить запись?', 'Эта запись будет удалена из сводки.', { btn: 'Удалить', danger: true });
  if (!entryForm.value.id || !ok) return;
  try {
    await db.from('plt_summary').delete().eq('id', entryForm.value.id);
    showEntryModal.value = false;
    toastStore.success('Удалено');
    await loadSummary();
  } catch (e) { toastStore.error('Ошибка удаления'); }
}

// Excel
async function exportSummaryExcel() {
  try {
    const XLSX = await import('xlsx-js-style');
    const wb = XLSX.utils.book_new();
    const header = ['Дата', 'День', 'Остатки Х', 'Остатки М', 'Приходы Холод', 'Приходы Мороз'];
    const rows = sumRows.value.map(r => [
      r.dateStr, r.dayName, r.coldStock ?? '', r.frozenStock ?? '',
      r.coldEntries.map(e => `${e.supplier_name} (${e.cold_pallets})`).join(', '),
      r.frozenEntries.map(e => `${e.supplier_name} (${e.frozen_pallets})`).join(', '),
    ]);
    const ws = XLSX.utils.aoa_to_sheet([header, ...rows]);
    ws['!cols'] = [{ wch: 8 }, { wch: 6 }, { wch: 12 }, { wch: 12 }, { wch: 45 }, { wch: 45 }];
    XLSX.utils.book_append_sheet(wb, ws, shortName(legalEntity.value));
    XLSX.writeFile(wb, `Паллеты_${shortName(legalEntity.value)}_${sumMonth.value}.xlsx`);
  } catch (e) { toastStore.error('Ошибка экспорта'); }
}

// ═══ REFERENCE ═══
const refProducts = ref([]);
const refSearch = ref('');
const refPage = ref(1);
const REF_PER_PAGE = 50;
const editingRef = ref(null);
const editRefData = ref({});

const filteredRefProducts = computed(() => {
  const q = refSearch.value.trim().toLowerCase();
  if (!q) return refProducts.value;
  return refProducts.value.filter(p => {
    const h = ((p.sku || '') + ' ' + p.name).toLowerCase();
    return h.includes(q);
  });
});

const refTotalPages = computed(() => Math.ceil(filteredRefProducts.value.length / REF_PER_PAGE));
const paginatedRefProducts = computed(() => {
  const start = (refPage.value - 1) * REF_PER_PAGE;
  return filteredRefProducts.value.slice(start, start + REF_PER_PAGE);
});

watch(refSearch, () => { refPage.value = 1; });

async function loadRefProducts() {
  const group = entityGroup(legalEntity.value);
  try {
    const { data, error } = await db.from('plt_products').select('*').eq('entity_group', group).order('sort_order').order('name');
    if (error) throw error;
    refProducts.value = data || [];
  } catch (e) { console.error(e); }
}

function startEditRef(p) {
  editingRef.value = p.id;
  editRefData.value = { name: p.name, storage_type: p.storage_type, boxes_per_pallet: p.boxes_per_pallet };
}

async function saveRefProduct(p) {
  try {
    const { error } = await db.from('plt_products').update(editRefData.value).eq('id', p.id);
    if (error) throw error;
    editingRef.value = null;
    toastStore.success('Сохранено');
    await loadRefProducts();
    await loadAllProducts();
  } catch (e) { toastStore.error('Ошибка'); }
}

async function deleteRefProduct(p) {
  const ok = await showConfirm('Удалить товар?', `«${p.name}» будет удалён из справочника.`, { btn: 'Удалить', danger: true });
  if (!ok) return;
  try {
    await db.from('plt_products').delete().eq('id', p.id);
    toastStore.success('Удалено');
    await loadRefProducts();
    await loadAllProducts();
  } catch (e) { toastStore.error('Ошибка удаления'); }
}

async function addRefProduct() {
  const result = await showPrompt('Добавить товар', [
    { label: 'Название (с артикулом)', value: '', placeholder: '12345 Название товара' },
    { label: 'Коробок на паллету', value: 40, type: 'number', min: 1 },
    { label: 'Тип хранения', value: 'cold', type: 'select', options: [{ value: 'cold', label: 'Холод' }, { value: 'frozen', label: 'Мороз' }] },
  ]);
  if (!result) return;
  const [name, bpp, storageType] = result;
  if (!name?.trim() || !bpp || +bpp < 1) { toastStore.error('Заполните все поля'); return; }
  const skuMatch = name.trim().match(/^(\d[\d_]*)\s/);
  try {
    await db.from('plt_products').insert({
      entity_group: entityGroup(legalEntity.value),
      name: name.trim(),
      sku: skuMatch ? skuMatch[1] : null,
      boxes_per_pallet: +bpp,
      storage_type: storageType,
    });
    toastStore.success('Товар добавлен');
    await loadRefProducts();
    await loadAllProducts();
  } catch (e) { toastStore.error('Ошибка'); }
}

// ═══ Lifecycle ═══
function switchTab(key) {
  tab.value = key;
  if (key === 'summary') loadSummary();
  if (key === 'reference') loadRefProducts();
}

function onEntityChange() {
  loadAllProducts();
  loadRefProducts();
  editingDeliveryId.value = null;
  calcItems.value = [];
  calcSupplierName.value = '';
  dateDeliveries.value = [];
  loadDateDeliveries();
  if (tab.value === 'summary') loadSummary();
}

watch(calcDate, () => loadDateDeliveries());

onMounted(async () => {
  await loadAllProducts();
  loadDateDeliveries();
});
</script>

<style scoped>
.plt { padding: 20px 24px; }
.plt-top { display: flex; align-items: center; gap: 16px; margin-bottom: 16px; flex-wrap: wrap; }
.plt-title { font-size: 22px; font-weight: 700; color: #502314; margin: 0; }
.plt-top-right { margin-left: auto; }

.plt-tabs { display: flex; gap: 0; border-bottom: 2px solid #eee; margin-bottom: 20px; }
.plt-tab { padding: 10px 24px; font-size: 14px; font-weight: 600; color: #888; background: none; border: none; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all .2s; }
.plt-tab:hover { color: #502314; }
.plt-tab.active { color: #D62700; border-bottom-color: #D62700; }

.plt-body { }
.plt-empty { padding: 24px; text-align: center; color: #999; font-size: 14px; }

.plt-select { padding: 8px 12px; border: 1.5px solid #ddd; border-radius: 8px; font-size: 14px; background: #fff; }
.plt-select-sm { padding: 4px 8px; border: 1.5px solid #ddd; border-radius: 6px; font-size: 13px; }
.plt-input { padding: 8px 12px; border: 1.5px solid #ddd; border-radius: 8px; font-size: 14px; background: #fff; width: 100%; box-sizing: border-box; }
.plt-input:focus, .plt-select:focus { border-color: #FF8733; outline: none; box-shadow: 0 0 0 3px rgba(255,135,51,.15); }
.plt-input-inline { padding: 4px 8px; border: 1.5px solid #FF8733; border-radius: 6px; font-size: 13px; width: 100%; box-sizing: border-box; }
.plt-input-num { padding: 6px 8px; border: 1.5px solid #ddd; border-radius: 6px; font-size: 13px; width: 80px; text-align: center; }
.plt-input-num:focus { border-color: #FF8733; outline: none; }

.plt-field { display: flex; flex-direction: column; gap: 4px; }
.plt-field label { font-size: 11px; font-weight: 700; color: #999; text-transform: uppercase; letter-spacing: .5px; }
.plt-field-row { display: flex; gap: 12px; }
.plt-field-row .plt-field { flex: 1; }

.plt-btn { padding: 8px 18px; border: 1.5px solid #D62700; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all .15s; background: #fff; color: #D62700; }
.plt-btn.fill { background: #D62700; color: #fff; }
.plt-btn.fill:hover { background: #b82000; }
.plt-btn.outline:hover { background: #FFF3E0; }
.plt-btn.sm { padding: 6px 12px; font-size: 13px; }
.plt-btn:disabled { opacity: .4; cursor: not-allowed; }
.plt-btn.red-text { color: #D62700; border-color: #fcc; }

.plt-btn-icon { background: none; border: none; cursor: pointer; padding: 4px; border-radius: 6px; display: inline-flex; align-items: center; opacity: .5; transition: all .15s; }
.plt-btn-icon:hover { opacity: 1; background: #f5f5f5; }
.plt-btn-icon.red:hover { background: #FFF0F0; }
.plt-btn-icon.green:hover { background: #E8F5E9; }

.plt-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
.plt-badge.cold { background: #E3F2FD; color: #1565C0; }
.plt-badge.frozen { background: #E8EAF6; color: #283593; }

.plt-section-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.plt-section-title { font-size: 15px; font-weight: 700; color: #502314; }

/* Table */
.plt-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.plt-table th { padding: 8px 10px; text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #888; border-bottom: 2px solid #eee; background: #fafafa; position: sticky; top: 0; z-index: 1; letter-spacing: .3px; }
.plt-table td { padding: 7px 10px; border-bottom: 1px solid #f0f0f0; }
.plt-table tr:hover td { background: #FFFAF5; }
.plt-table .num { text-align: center; font-weight: 600; }
.plt-table .pallets { color: #D62700; font-size: 14px; }
.plt-table .col-actions { white-space: nowrap; }
.plt-total td { font-weight: 700; background: #FFF3E0 !important; border-top: 2px solid #FF8733; }
.plt-total-badges { display: flex; gap: 6px; justify-content: center; }
.has-value td { background: #FFFDE7; }
.item-name { max-width: 400px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* Calculator */
.plt-calc { }
.plt-calc-header { display: flex; gap: 16px; margin-bottom: 16px; flex-wrap: wrap; align-items: flex-end; }
.plt-calc-search { margin-bottom: 16px; position: relative; }
.plt-search-wrap { position: relative; }
.plt-search-dropdown { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1.5px solid #ddd; border-top: none; border-radius: 0 0 8px 8px; box-shadow: 0 8px 24px rgba(0,0,0,.12); max-height: 350px; overflow-y: auto; z-index: 100; }
.plt-search-item { display: flex; align-items: center; gap: 10px; padding: 8px 12px; cursor: pointer; transition: background .1s; font-size: 13px; }
.plt-search-item:hover, .plt-search-item.highlighted { background: #FFF3E0; }
.plt-search-sku { font-weight: 700; color: #D62700; min-width: 70px; font-size: 12px; }
.plt-search-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.plt-search-bpp { color: #999; font-size: 11px; white-space: nowrap; }

.plt-calc-items { margin-bottom: 20px; overflow-x: auto; }
.calc-input { width: 90px; }
.storage-select { width: 50px; padding: 2px 4px; font-size: 12px; font-weight: 600; text-align: center; cursor: pointer; }

/* Bulk paste */
.plt-textarea { padding: 10px 12px; border: 1.5px solid #ddd; border-radius: 8px; font-size: 13px; font-family: monospace; width: 100%; box-sizing: border-box; resize: vertical; line-height: 1.5; }
.plt-textarea:focus { border-color: #FF8733; outline: none; box-shadow: 0 0 0 3px rgba(255,135,51,.15); }
.plt-bulk-results { margin-top: 8px; }
.plt-bulk-found { font-size: 14px; color: #502314; }
.plt-bulk-missing { margin-top: 8px; }
.plt-bulk-missing-title { font-size: 12px; font-weight: 600; color: #D62700; margin-bottom: 4px; }
.plt-bulk-missing-item { font-size: 12px; color: #999; padding: 2px 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.btn-danger { background: #D62700; border-color: #D62700; color: #fff; }
.btn-danger:hover { background: #b82000; }
.plt-calc-actions { margin-top: 12px; }

.plt-calc-saved { margin-top: 28px; border-top: 1px solid #eee; padding-top: 16px; }
.plt-delivery-card { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border: 1px solid #eee; border-radius: 10px; margin-bottom: 6px; transition: all .15s; }
.plt-delivery-card:hover { border-color: #FF8733; background: #FFF8F0; }
.plt-delivery-info { display: flex; align-items: center; gap: 10px; font-size: 14px; flex: 1; flex-wrap: wrap; }
.plt-delivery-time { font-size: 12px; color: #999; }
.plt-delivery-btns { display: flex; gap: 6px; flex-shrink: 0; }

/* Summary */
.plt-sum-controls { display: flex; align-items: flex-end; gap: 16px; margin-bottom: 16px; flex-wrap: wrap; }
.plt-sum-actions { margin-left: auto; display: flex; gap: 8px; }
.plt-sum-table-wrap { overflow-x: auto; }
.sum-table .col-date { width: 65px; font-weight: 600; }
.sum-table .col-day { width: 40px; color: #999; font-size: 12px; }
.sum-table .col-stock { width: 90px; text-align: center; cursor: pointer; }
.sum-table .col-deliveries { min-width: 200px; }
.sum-table .col-total { width: 70px; text-align: center; font-weight: 700; color: #D62700; }
.sum-table tr.weekend td { background: #fafafa; }
.sum-table tr.today td { background: #FFFDE7; }
.sum-table tr.today .col-date { color: #D62700; }
.stock-val { font-weight: 600; color: #502314; }
.stock-val.empty { color: #ddd; font-weight: 400; }
.stock-input { width: 70px; }
.plt-entries { display: flex; flex-wrap: wrap; gap: 4px; }
.plt-entry { display: inline-block; padding: 3px 10px; background: #FFF3E0; border-radius: 6px; font-size: 12px; font-weight: 500; color: #502314; cursor: pointer; transition: all .15s; white-space: nowrap; }
.plt-entry:hover { background: #FFE0B2; }

/* Reference */
.plt-ref-controls { display: flex; align-items: center; gap: 16px; margin-bottom: 12px; flex-wrap: wrap; }
.plt-ref-stats { font-size: 13px; color: #999; }
.plt-ref-table-wrap { overflow-x: auto; max-height: calc(100vh - 280px); overflow-y: auto; }
.ref-table .ref-sku { font-weight: 700; color: #D62700; font-size: 12px; font-family: monospace; }
.plt-pagination { display: flex; align-items: center; justify-content: center; gap: 12px; margin-top: 12px; }
.plt-page-info { font-size: 13px; color: #888; }

/* Modal */
.plt-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.plt-modal { background: #fff; border-radius: 14px; width: 440px; max-width: 95vw; box-shadow: 0 20px 60px rgba(0,0,0,.2); }
.plt-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid #eee; font-weight: 700; font-size: 16px; color: #502314; }
.plt-modal-body { padding: 20px; display: flex; flex-direction: column; gap: 14px; }
.plt-modal-foot { display: flex; align-items: center; gap: 8px; padding: 14px 20px; border-top: 1px solid #eee; }

@media (max-width: 768px) {
  .plt { padding: 12px; }
  .plt-calc-header { flex-direction: column; }
  .plt-tab { padding: 8px 14px; font-size: 13px; }
  .plt-sum-controls { flex-direction: column; align-items: stretch; }
  .plt-sum-actions { margin-left: 0; }
  .plt-ref-controls { flex-direction: column; align-items: stretch; }
  .item-name { max-width: 200px; }
}
</style>
