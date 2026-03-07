<template>
  <div class="pricing-view">
    <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;">
      <h1 class="page-title">Цены и ПСЦ</h1>
      <div style="display:flex;gap:8px;align-items:center;">
        <!-- Курс RUB→BYN -->
        <div v-if="!isViewer" class="rate-control">
          <span class="rate-label">1 RUB =</span>
          <input type="number" step="0.0001" min="0.001" max="1" :value="rubToBynRate" @change="onRateChange" class="rate-input" />
          <span class="rate-label">BYN</span>
        </div>
        <span v-else-if="rubToBynRate" class="rate-display">1 RUB = {{ rubToBynRate }} BYN</span>
        <button v-if="!isViewer && activeTab === 'prices'" class="btn primary" @click="showImportModal = true" style="font-size:11px;padding:5px 12px;">Импорт цен</button>
        <button v-if="!isViewer && activeTab === 'prices'" class="btn secondary" @click="openNewPrice" style="font-size:11px;padding:5px 12px;">+ Цена</button>
        <button v-if="!isViewer && activeTab === 'agreements'" class="btn primary" @click="openNewAgreement" style="font-size:11px;padding:5px 12px;">+ Протокол</button>
      </div>
    </div>

    <!-- Табы -->
    <div class="db-tabs">
      <button class="db-tab" :class="{ active: activeTab === 'prices' }" @click="activeTab = 'prices'; loadPrices()">
        <BkIcon name="pricing" size="sm"/> Прайс-лист <span class="db-tab-count">{{ prices.length }}</span>
      </button>
      <button class="db-tab" :class="{ active: activeTab === 'agreements' }" @click="activeTab = 'agreements'; loadAgreements()">
        <BkIcon name="order" size="sm"/> Протоколы (ПСЦ) <span class="db-tab-count">{{ agreements.length }}</span>
      </button>
    </div>

    <!-- Поиск -->
    <div style="position:relative;margin-bottom:14px;">
      <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:14px;pointer-events:none;opacity:0.5;"><BkIcon name="search" size="sm"/></span>
      <input v-model="searchQuery" style="width:100%;padding:9px 36px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:var(--card);box-sizing:border-box;transition:border-color .15s,box-shadow .15s;"
        :placeholder="activeTab === 'prices' ? 'Поиск по артикулу, поставщику...' : 'Поиск по номеру, поставщику...'"
        @focus="$event.target.style.borderColor='var(--bk-orange)';$event.target.style.boxShadow='0 0 0 3px rgba(245,166,35,0.12)'"
        @blur="$event.target.style.borderColor='var(--border)';$event.target.style.boxShadow='none'" />
      <button v-if="searchQuery" @click="searchQuery=''" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:14px;"><BkIcon name="close" size="xs"/></button>
    </div>

    <!-- Фильтр по поставщику -->
    <div v-if="supplierNames.length > 1" style="margin-bottom:14px;display:flex;gap:6px;flex-wrap:wrap;">
      <button class="db-sort-btn" :class="{ active: !filterSupplier }" @click="filterSupplier = ''" style="font-size:11px;">Все</button>
      <button v-for="s in supplierNames" :key="s" class="db-sort-btn" :class="{ active: filterSupplier === s }" @click="filterSupplier = s" style="font-size:11px;">{{ s }}</button>
    </div>

    <!-- ПРАЙС-ЛИСТ -->
    <div v-if="activeTab === 'prices'">
      <div v-if="loading" style="text-align:center;padding:40px;"><BurgerSpinner text="Загрузка..." /></div>
      <div v-else-if="!filteredPrices.length" style="text-align:center;padding:40px;color:var(--text-muted);">Цены не найдены</div>
      <div v-else>
        <table class="pricing-table">
          <thead>
            <tr>
              <th @click="toggleSort('sku')" style="cursor:pointer;">Артикул {{ sortIcon('sku') }}</th>
              <th>Название</th>
              <th @click="toggleSort('supplier')" style="cursor:pointer;">Поставщик {{ sortIcon('supplier') }}</th>
              <th @click="toggleSort('price')" style="cursor:pointer;" class="text-right">Цена {{ sortIcon('price') }}</th>
              <th>За</th>
              <th>Вал.</th>
              <th v-if="hasRubPrices" class="text-right">В BYN</th>
              <th>ПСЦ</th>
              <th @click="toggleSort('updated_at')" style="cursor:pointer;">Обновлено {{ sortIcon('updated_at') }}</th>
              <th v-if="!isViewer" style="width:60px;"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in sortedPrices" :key="p.id" @click="!isViewer && editPrice(p)" :style="!isViewer ? 'cursor:pointer' : ''">
              <td class="mono">{{ p.sku }}</td>
              <td>{{ productNames[p.sku] || '—' }}</td>
              <td>{{ p.supplier }}</td>
              <td class="text-right mono">{{ formatPrice(p.price) }}</td>
              <td>{{ p.unit_type === 'box' ? 'кор' : 'шт' }}</td>
              <td><span class="currency-badge" :class="'cur-' + (p.currency || 'BYN')">{{ p.currency || 'BYN' }}</span></td>
              <td v-if="hasRubPrices" class="text-right mono">{{ p.currency === 'RUB' ? formatPrice(p.price * rubToBynRate) : '' }}</td>
              <td>
                <span v-if="p.agreement_id" class="psc-badge" :title="getAgreementLabel(p.agreement_id)">ПСЦ</span>
                <span v-else class="psc-badge psc-manual">Руч.</span>
              </td>
              <td class="text-muted" style="font-size:11px;">{{ formatDate(p.updated_at) }}</td>
              <td v-if="!isViewer" style="text-align:center;">
                <button class="db-card-btn db-card-btn-del" @click.stop="deletePrice(p)" title="Удалить">
                  <BkIcon name="delete" size="sm"/>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ПРОТОКОЛЫ (ПСЦ) -->
    <div v-if="activeTab === 'agreements'">
      <div v-if="loading" style="text-align:center;padding:40px;"><BurgerSpinner text="Загрузка..." /></div>
      <div v-else-if="!filteredAgreements.length" style="text-align:center;padding:40px;color:var(--text-muted);">Протоколы не найдены</div>
      <div v-else class="db-grid">
        <div v-for="a in filteredAgreements" :key="a.id" class="db-card agreement-card" :class="{ 'agreement-active': a.status === 'active', 'agreement-archived': a.status === 'archived' }" @click="!isViewer && editAgreement(a)" :style="!isViewer ? '' : 'cursor:default'">
          <div class="db-card-top" style="display:flex;align-items:center;gap:8px;">
            <span class="agreement-status" :class="'st-' + a.status">{{ statusLabel(a.status) }}</span>
            <span style="font-weight:600;">{{ a.number }}</span>
          </div>
          <div class="db-card-meta">
            <span>{{ a.supplier }}</span>
            <span v-if="a.valid_from">{{ formatDate(a.valid_from) }} — {{ a.valid_to ? formatDate(a.valid_to) : '...' }}</span>
          </div>
          <div class="db-card-meta" style="font-size:10px;">
            <span>Создал: {{ a.created_by }}</span>
            <span v-if="a.approved_by">Согласовал: {{ a.approved_by }}</span>
          </div>
          <div v-if="a.file_name" class="db-card-meta">
            <a :href="apiBase + '/' + a.file_path + '?download=1'" @click.stop class="psc-file-link" target="_blank">{{ a.file_name }}</a>
          </div>
          <div v-if="!isViewer" class="db-card-btns">
            <button v-if="a.status === 'draft' && hasFullAccess" class="approve-btn" @click.stop="approveAgreement(a)"><BkIcon name="check" size="sm"/> Согласовать</button>
            <button class="db-card-btn" @click.stop="editAgreement(a)" title="Редактировать"><BkIcon name="edit" size="sm"/></button>
            <button v-if="hasFullAccess" class="db-card-btn db-card-btn-del" @click.stop="deleteAgreement(a)" title="Удалить"><BkIcon name="delete" size="sm"/></button>
          </div>
        </div>
      </div>
    </div>

    <!-- Модалка: Редактирование/создание цены -->
    <div v-if="showPriceModal" class="modal-overlay" @click.self="showPriceModal = false">
      <div class="modal-card" style="max-width:420px;">
        <h3 style="margin:0 0 16px;">{{ editingPrice ? 'Редактировать цену' : 'Новая цена' }}</h3>
        <div class="form-group">
          <label>Артикул (SKU)</label>
          <input v-model="priceForm.sku" class="form-input" placeholder="Например: 10234" />
        </div>
        <div class="form-group">
          <label>Поставщик</label>
          <select v-model="priceForm.supplier" class="form-input">
            <option value="">— Выберите —</option>
            <option v-for="s in supplierNames" :key="s" :value="s">{{ s }}</option>
          </select>
        </div>
        <div class="form-group" style="display:flex;gap:12px;">
          <div style="flex:1;">
            <label>Цена</label>
            <input v-model.number="priceForm.price" type="number" step="0.01" min="0" class="form-input" />
          </div>
          <div style="flex:0 0 100px;">
            <label>За</label>
            <select v-model="priceForm.unit_type" class="form-input">
              <option value="piece">штуку</option>
              <option value="box">коробку</option>
            </select>
          </div>
          <div style="flex:0 0 80px;">
            <label>Валюта</label>
            <select v-model="priceForm.currency" class="form-input">
              <option value="BYN">BYN</option>
              <option value="RUB">RUB</option>
            </select>
          </div>
        </div>
        <div v-if="priceForm.currency === 'RUB' && priceForm.price > 0" style="font-size:11px;color:var(--text-muted);margin-top:-8px;margin-bottom:8px;">
          = {{ formatPrice(priceForm.price * rubToBynRate) }} BYN (курс {{ rubToBynRate }})
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
          <button class="btn secondary" @click="showPriceModal = false">Отмена</button>
          <button class="btn primary" @click="savePrice" :disabled="savingPrice">{{ savingPrice ? 'Сохранение...' : 'Сохранить' }}</button>
        </div>
      </div>
    </div>

    <!-- Модалка: Редактирование/создание протокола -->
    <div v-if="showAgreementModal" class="modal-overlay" @click.self="showAgreementModal = false">
      <div class="modal-card" style="max-width:480px;">
        <h3 style="margin:0 0 16px;">{{ editingAgreement ? 'Редактировать протокол' : 'Новый протокол (ПСЦ)' }}</h3>
        <div class="form-group">
          <label>Номер протокола</label>
          <input v-model="agForm.number" class="form-input" placeholder="ПСЦ-001" />
        </div>
        <div class="form-group">
          <label>Поставщик</label>
          <select v-model="agForm.supplier" class="form-input">
            <option value="">— Выберите —</option>
            <option v-for="s in supplierNames" :key="s" :value="s">{{ s }}</option>
          </select>
        </div>
        <div class="form-group" style="display:flex;gap:12px;">
          <div style="flex:1;">
            <label>Действует с</label>
            <input v-model="agForm.valid_from" type="date" class="form-input" />
          </div>
          <div style="flex:1;">
            <label>Действует до</label>
            <input v-model="agForm.valid_to" type="date" class="form-input" />
          </div>
        </div>
        <div class="form-group">
          <label>Примечание</label>
          <textarea v-model="agForm.note" class="form-input" rows="2" style="resize:vertical;"></textarea>
        </div>
        <!-- Загрузка файла -->
        <div class="form-group">
          <label>Файл ПСЦ</label>
          <div v-if="agForm.file_name" style="margin-bottom:6px;font-size:12px;">
            Текущий: <a :href="apiBase + '/' + agForm.file_path + '?download=1'" target="_blank">{{ agForm.file_name }}</a>
          </div>
          <input ref="pscFileInput" type="file" accept=".pdf,.jpg,.jpeg,.png,.xlsx,.xls" @change="onPscFileSelected" style="font-size:12px;" />
          <div v-if="uploadingFile" style="font-size:11px;color:var(--text-muted);margin-top:4px;">Загрузка файла...</div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
          <button class="btn secondary" @click="showAgreementModal = false">Отмена</button>
          <button class="btn primary" @click="saveAgreement" :disabled="savingAgreement">{{ savingAgreement ? 'Сохранение...' : 'Сохранить' }}</button>
        </div>
      </div>
    </div>

    <!-- Модалка: Импорт цен из файла -->
    <div v-if="showImportModal" class="modal-overlay" @click.self="showImportModal = false">
      <div class="modal-card" style="max-width:520px;">
        <h3 style="margin:0 0 16px;">Импорт цен из файла</h3>
        <div class="form-group">
          <label>Поставщик</label>
          <select v-model="importSupplier" class="form-input">
            <option value="">— Выберите —</option>
            <option v-for="s in supplierNames" :key="s" :value="s">{{ s }}</option>
          </select>
        </div>
        <div class="form-group">
          <label>Валюта цен в файле</label>
          <select v-model="importCurrency" class="form-input" style="width:120px;">
            <option value="BYN">BYN</option>
            <option value="RUB">RUB</option>
          </select>
        </div>
        <div class="form-group">
          <label>Привязать к протоколу (необязательно)</label>
          <select v-model="importAgreementId" class="form-input">
            <option :value="null">— Без привязки —</option>
            <option v-for="a in agreements.filter(x => x.supplier === importSupplier)" :key="a.id" :value="a.id">{{ a.number }} ({{ statusLabel(a.status) }})</option>
          </select>
        </div>
        <div class="form-group">
          <label>Файл (.xlsx, .xls, .csv)</label>
          <input ref="importFileInput" type="file" accept=".xlsx,.xls,.csv" @change="onImportFileSelected" style="font-size:12px;" />
        </div>
        <div v-if="importPreview.length" style="margin-top:12px;">
          <div style="font-size:12px;font-weight:600;margin-bottom:6px;">Предпросмотр (первые {{ Math.min(importPreview.length, 10) }} из {{ importPreview.length }}):</div>
          <table class="pricing-table" style="font-size:11px;">
            <thead><tr><th>Артикул</th><th class="text-right">Цена</th><th>За</th></tr></thead>
            <tbody>
              <tr v-for="(p, i) in importPreview.slice(0, 10)" :key="i">
                <td class="mono">{{ p.sku }}</td>
                <td class="text-right mono">{{ formatPrice(p.price) }}</td>
                <td>{{ p.unit_type === 'box' ? 'кор' : 'шт' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
          <button class="btn secondary" @click="showImportModal = false; importPreview = [];">Отмена</button>
          <button class="btn primary" @click="doImport" :disabled="importing || !importPreview.length || !importSupplier">{{ importing ? 'Импорт...' : `Импортировать (${importPreview.length})` }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { db } from '@/lib/apiClient.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { useSupplierStore } from '@/stores/supplierStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';

const orderStore = useOrderStore();
const userStore = useUserStore();
const supplierStore = useSupplierStore();
const toast = useToastStore();

const API_BASE = import.meta.env.VITE_API_URL || '/api';
const apiBase = API_BASE;

const isViewer = computed(() => !userStore.hasAccess('pricing', 'edit'));
const hasFullAccess = computed(() => userStore.hasAccess('pricing', 'full'));

const activeTab = ref('prices');
const rubToBynRate = ref(0.0375);
const searchQuery = ref('');
const filterSupplier = ref('');
const loading = ref(false);

// Данные
const prices = ref([]);
const agreements = ref([]);
const productNames = ref({}); // sku -> name

// Сортировка
const sortKey = ref('sku');
const sortDir = ref('asc');

function toggleSort(key) {
  if (sortKey.value === key) { sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'; }
  else { sortKey.value = key; sortDir.value = 'asc'; }
}
function sortIcon(key) {
  if (sortKey.value !== key) return '';
  return sortDir.value === 'asc' ? '\u2191' : '\u2193';
}

// Поставщики
const hasRubPrices = computed(() => prices.value.some(p => p.currency === 'RUB'));

async function onRateChange(e) {
  const val = parseFloat(e.target.value);
  if (!val || val <= 0 || val > 1) { toast.error('Некорректный курс'); e.target.value = rubToBynRate.value; return; }
  const { error } = await db.rpc('update_exchange_rate', { rate: val });
  if (error) { toast.error('Ошибка', error); return; }
  rubToBynRate.value = val;
  toast.success('Курс обновлён', `1 RUB = ${val} BYN`);
}

const supplierNames = computed(() => {
  const list = supplierStore.getSuppliersForEntity(orderStore.settings.legalEntity);
  return list.map(s => s.short_name);
});

// Загрузка данных
async function loadPrices() {
  const le = orderStore.settings.legalEntity;
  if (!le) return;
  loading.value = true;
  try {
    const { data, error } = await db.rpc('get_current_prices', { legal_entity: le });
    if (error) { toast.error('Ошибка', error); return; }
    prices.value = data?.prices || [];
    if (data?.rub_to_byn_rate) rubToBynRate.value = parseFloat(data.rub_to_byn_rate);
    await loadProductNames();
  } finally {
    loading.value = false;
  }
}

async function loadProductNames() {
  const le = orderStore.settings.legalEntity;
  if (!le) return;
  const skus = prices.value.map(p => p.sku).filter(Boolean);
  if (!skus.length) return;
  // Загружаем все продукты для юрлица и кэшируем имена
  const { data } = await db.from('products').select('sku,name').eq('legal_entity', le);
  const map = {};
  if (data) {
    for (const p of data) map[p.sku] = p.name;
  }
  productNames.value = map;
}

async function loadAgreements() {
  const le = orderStore.settings.legalEntity;
  if (!le) return;
  loading.value = true;
  try {
    const { data, error } = await db.from('price_agreements').select('*').eq('legal_entity', le).order('created_at', { ascending: false });
    if (error) { toast.error('Ошибка', error); return; }
    agreements.value = data || [];
  } finally {
    loading.value = false;
  }
}

// Фильтрация и сортировка
const filteredPrices = computed(() => {
  let list = prices.value;
  if (filterSupplier.value) list = list.filter(p => p.supplier === filterSupplier.value);
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase();
    list = list.filter(p => (p.sku && p.sku.toLowerCase().includes(q)) || (p.supplier && p.supplier.toLowerCase().includes(q)) || (productNames.value[p.sku] && productNames.value[p.sku].toLowerCase().includes(q)));
  }
  return list;
});

const sortedPrices = computed(() => {
  const list = [...filteredPrices.value];
  const key = sortKey.value;
  const dir = sortDir.value === 'asc' ? 1 : -1;
  list.sort((a, b) => {
    let va = a[key], vb = b[key];
    if (key === 'price') return (va - vb) * dir;
    va = (va || '').toString().toLowerCase();
    vb = (vb || '').toString().toLowerCase();
    return va.localeCompare(vb, 'ru') * dir;
  });
  return list;
});

const filteredAgreements = computed(() => {
  let list = agreements.value;
  if (filterSupplier.value) list = list.filter(a => a.supplier === filterSupplier.value);
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase();
    list = list.filter(a => (a.number && a.number.toLowerCase().includes(q)) || (a.supplier && a.supplier.toLowerCase().includes(q)));
  }
  return list;
});

// Форматирование
function formatPrice(v) {
  const n = parseFloat(v);
  if (isNaN(n)) return '—';
  return n.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function formatDate(d) {
  if (!d) return '';
  const dt = new Date(d);
  if (isNaN(dt)) return d;
  return dt.toLocaleDateString('ru-RU');
}
function statusLabel(s) {
  return { draft: 'Черновик', active: 'Действует', archived: 'Архив' }[s] || s;
}
function getAgreementLabel(id) {
  const a = agreements.value.find(x => x.id === id);
  return a ? `${a.number} (${statusLabel(a.status)})` : `ПСЦ #${id}`;
}

// === Цена: создание/редактирование ===
const showPriceModal = ref(false);
const editingPrice = ref(null);
const savingPrice = ref(false);
const priceForm = ref({ sku: '', supplier: '', price: 0, unit_type: 'piece', currency: 'BYN' });

function openNewPrice() {
  editingPrice.value = null;
  priceForm.value = { sku: '', supplier: supplierNames.value[0] || '', price: 0, unit_type: 'piece', currency: 'BYN' };
  showPriceModal.value = true;
}
function editPrice(p) {
  editingPrice.value = p;
  priceForm.value = { sku: p.sku, supplier: p.supplier, price: p.price, unit_type: p.unit_type, currency: p.currency || 'BYN' };
  showPriceModal.value = true;
}

async function savePrice() {
  if (savingPrice.value) return;
  const { sku, supplier, price, unit_type, currency } = priceForm.value;
  if (!sku || !supplier) { toast.error('Ошибка', 'Укажите артикул и поставщика'); return; }
  savingPrice.value = true;
  try {
    const { error } = await db.rpc('import_prices', {
      legal_entity: orderStore.settings.legalEntity,
      supplier,
      currency,
      prices: [{ sku: sku.trim(), price: parseFloat(price) || 0, unit_type }],
    });
    if (error) { toast.error('Ошибка', error); return; }
    toast.success('Сохранено');
    showPriceModal.value = false;
    await loadPrices();
  } finally {
    savingPrice.value = false;
  }
}

async function deletePrice(p) {
  if (!confirm(`Удалить цену для ${p.sku}?`)) return;
  const { error } = await db.rpc('delete_price', { id: p.id });
  if (error) { toast.error('Ошибка', error); return; }
  toast.success('Удалено');
  prices.value = prices.value.filter(x => x.id !== p.id);
}

// === Протоколы: создание/редактирование ===
const showAgreementModal = ref(false);
const editingAgreement = ref(null);
const savingAgreement = ref(false);
const uploadingFile = ref(false);
const pscFileInput = ref(null);
const agForm = ref({ number: '', supplier: '', valid_from: '', valid_to: '', note: '', file_name: '', file_path: '' });

function openNewAgreement() {
  editingAgreement.value = null;
  agForm.value = { number: '', supplier: supplierNames.value[0] || '', valid_from: '', valid_to: '', note: '', file_name: '', file_path: '' };
  showAgreementModal.value = true;
}
function editAgreement(a) {
  editingAgreement.value = a;
  agForm.value = {
    number: a.number, supplier: a.supplier,
    valid_from: a.valid_from || '', valid_to: a.valid_to || '',
    note: a.note || '', file_name: a.file_name || '', file_path: a.file_path || '',
  };
  showAgreementModal.value = true;
}

async function saveAgreement() {
  if (savingAgreement.value) return;
  const { number, supplier, valid_from, valid_to, note } = agForm.value;
  if (!number || !supplier) { toast.error('Ошибка', 'Укажите номер и поставщика'); return; }
  savingAgreement.value = true;
  try {
    const le = orderStore.settings.legalEntity;
    const payload = {
      number, supplier, legal_entity: le,
      valid_from: valid_from || null, valid_to: valid_to || null,
      note: note || null,
    };
    if (editingAgreement.value) {
      const { error } = await db.from('price_agreements').update(payload).eq('id', editingAgreement.value.id);
      if (error) { toast.error('Ошибка', error); return; }
    } else {
      payload.created_by = userStore.currentUser?.name || '';
      payload.status = 'draft';
      const { data, error } = await db.from('price_agreements').insert(payload);
      if (error) { toast.error('Ошибка', error); return; }
      // Загрузить файл, если выбран, для нового протокола
      if (pendingPscFile.value && data?.id) {
        await uploadPscFile(data.id);
      }
    }
    toast.success('Сохранено');
    showAgreementModal.value = false;
    await loadAgreements();
  } finally {
    savingAgreement.value = false;
  }
}

const pendingPscFile = ref(null);

function onPscFileSelected(e) {
  const file = e.target.files?.[0];
  if (!file) return;
  if (editingAgreement.value) {
    uploadPscFile(editingAgreement.value.id, file);
  } else {
    pendingPscFile.value = file;
  }
}

async function uploadPscFile(agreementId, file) {
  const f = file || pendingPscFile.value;
  if (!f) return;
  uploadingFile.value = true;
  try {
    const formData = new FormData();
    formData.append('file', f);
    formData.append('agreement_id', agreementId);
    const token = localStorage.getItem('bk_session_token');
    const res = await fetch(`${API_BASE}/upload/psc`, {
      method: 'POST',
      headers: { 'X-Session-Token': token || '' },
      body: formData,
    });
    const result = await res.json();
    if (!res.ok) { toast.error('Ошибка загрузки', result.error || 'Неизвестная ошибка'); return; }
    agForm.value.file_name = result.file_name;
    agForm.value.file_path = result.path;
    toast.success('Файл загружен');
    pendingPscFile.value = null;
  } finally {
    uploadingFile.value = false;
  }
}

async function approveAgreement(a) {
  if (!confirm(`Согласовать протокол ${a.number}? Предыдущий активный протокол для этого поставщика будет заархивирован.`)) return;
  const { error } = await db.rpc('approve_agreement', { id: a.id });
  if (error) { toast.error('Ошибка', error); return; }
  toast.success('Протокол согласован');
  await loadAgreements();
}

async function deleteAgreement(a) {
  if (!confirm(`Удалить протокол ${a.number}?`)) return;
  const { error } = await db.from('price_agreements').delete().eq('id', a.id);
  if (error) { toast.error('Ошибка', error); return; }
  toast.success('Удалено');
  agreements.value = agreements.value.filter(x => x.id !== a.id);
}

// === Импорт цен из файла ===
const showImportModal = ref(false);
const importSupplier = ref('');
const importCurrency = ref('RUB');
const importAgreementId = ref(null);
const importPreview = ref([]);
const importing = ref(false);
const importFileInput = ref(null);

async function onImportFileSelected(e) {
  const file = e.target.files?.[0];
  if (!file) return;
  try {
    const XLSX = await import('xlsx-js-style');
    const buf = await file.arrayBuffer();
    const wb = XLSX.read(buf, { type: 'array' });
    const ws = wb.Sheets[wb.SheetNames[0]];
    const rows = XLSX.utils.sheet_to_json(ws, { defval: '' });
    if (!rows.length) { toast.error('Пустой файл'); return; }

    // Маппинг колонок: ищем артикул и цену
    const first = rows[0];
    const keys = Object.keys(first);
    const skuCol = keys.find(k => /артикул|sku|код|article/i.test(k)) || keys[0];
    const priceCol = keys.find(k => /цена|price|стоимость|cost/i.test(k)) || keys[1];
    const unitCol = keys.find(k => /единица|unit|за что|тип/i.test(k));

    const parsed = [];
    for (const row of rows) {
      const sku = String(row[skuCol] || '').trim();
      const price = parseFloat(String(row[priceCol] || '0').replace(/[^\d.,]/g, '').replace(',', '.'));
      if (!sku || isNaN(price) || price <= 0) continue;
      let unit_type = 'piece';
      if (unitCol) {
        const uv = String(row[unitCol] || '').toLowerCase();
        if (/кор|box|упак/i.test(uv)) unit_type = 'box';
      }
      parsed.push({ sku, price, unit_type });
    }
    importPreview.value = parsed;
    if (!parsed.length) toast.error('Не найдены данные', 'Проверьте, что в файле есть колонки с артикулом и ценой');
  } catch (err) {
    toast.error('Ошибка чтения файла', err.message);
  }
}

async function doImport() {
  if (importing.value || !importPreview.value.length || !importSupplier.value) return;
  importing.value = true;
  try {
    const { data, error } = await db.rpc('import_prices', {
      legal_entity: orderStore.settings.legalEntity,
      supplier: importSupplier.value,
      currency: importCurrency.value,
      prices: importPreview.value,
      agreement_id: importAgreementId.value,
    });
    if (error) { toast.error('Ошибка импорта', error); return; }
    toast.success('Импорт завершён', `Загружено ${data?.imported || 0} цен`);
    showImportModal.value = false;
    importPreview.value = [];
    await loadPrices();
  } finally {
    importing.value = false;
  }
}

// Инициализация
onMounted(async () => {
  await supplierStore.loadSuppliers(orderStore.settings.legalEntity);
  await loadPrices();
  // Подгрузить протоколы в фоне для отображения бейджей
  loadAgreements();
});

// При смене юрлица — перезагрузить
watch(() => orderStore.settings.legalEntity, async (le) => {
  if (!le) return;
  filterSupplier.value = '';
  searchQuery.value = '';
  await supplierStore.loadSuppliers(le);
  if (activeTab.value === 'prices') await loadPrices();
  else await loadAgreements();
});
</script>

<style scoped>
.pricing-view { padding: 0; }

/* ═══ Tabs (из DatabaseView) ═══ */
.db-tabs { display:flex; justify-content:center; gap:0; margin-bottom:14px; border-bottom:2px solid var(--border-light); }
.db-tab { padding:9px 20px; font-size:14px; font-weight:600; color:var(--text-muted); background:none; border:none; border-bottom:2px solid transparent; margin-bottom:-2px; cursor:pointer; transition:all .15s; display:inline-flex; align-items:center; gap:6px; }
.db-tab.active { color:var(--bk-brown); border-bottom-color:var(--bk-brown); }
.db-tab:hover:not(.active) { color:var(--text); background:rgba(139,115,85,.05); }
.db-tab-count { display:inline-block; background:var(--border-light); color:var(--text-muted); font-size:11px; font-weight:700; padding:1px 7px; border-radius:10px; margin-left:4px; }
.db-tab.active .db-tab-count { background:var(--bk-brown); color:#fff; }

/* ═══ Cards grid ═══ */
.db-grid { display:flex; flex-direction:column; gap:4px; }
.db-card { background:var(--card); border:1px solid var(--border-light); border-radius:6px; padding:7px 12px; cursor:pointer; transition:border-color .15s; display:flex; align-items:center; gap:10px; }
.db-card:hover { border-color:var(--bk-orange); }
.db-card-top { display:flex; align-items:center; gap:6px; flex:1; min-width:0; }
.db-card-meta { display:flex; flex-wrap:nowrap; gap:5px; font-size:10px; color:var(--text-muted); flex-shrink:0; }
.db-card-meta span { background:var(--bg); padding:1px 5px; border-radius:3px; white-space:nowrap; }
.db-card-btns { display:flex; gap:3px; opacity:0; transition:opacity .15s; flex-shrink:0; }
.db-card:hover .db-card-btns { opacity:1; }
.db-card-btn { background:none; border:1px solid var(--border-light); border-radius:5px; padding:2px 5px; cursor:pointer; font-size:11px; transition:all .15s; }
.db-card-btn:hover { background:var(--bg); border-color:var(--border); }
.db-card-btn-del:hover { background:#FFF0F0; border-color:#E57373; }

.approve-btn {
  display:inline-flex; align-items:center; gap:4px;
  padding:5px 12px; border-radius:8px;
  border:1.5px solid #4CAF50; background:#E8F5E9;
  color:#2E7D32; font-size:11px; font-weight:600;
  font-family:inherit; cursor:pointer; transition:all .15s;
}
.approve-btn:hover { background:#C8E6C9; border-color:#388E3C; }

/* ═══ Filter buttons ═══ */
.db-sort-btn { display:inline-flex; align-items:center; gap:4px; padding:4px 10px; border-radius:8px; border:1.5px solid var(--border); background:white; font-size:11px; font-weight:600; font-family:inherit; color:var(--text-muted); cursor:pointer; transition:all .15s; white-space:nowrap; }
.db-sort-btn:hover { border-color:var(--bk-orange); color:var(--text); }
.db-sort-btn.active { border-color:var(--bk-orange); color:var(--bk-brown); background:#FFFBF5; }
.pricing-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.pricing-table th { background: var(--card); padding: 8px 10px; text-align: left; font-size: 11px; color: var(--text-muted); font-weight: 600; border-bottom: 2px solid var(--border); white-space: nowrap; user-select: none; }
.pricing-table td { padding: 7px 10px; border-bottom: 1px solid var(--border); }
.pricing-table tbody tr:hover { background: rgba(245,166,35,0.04); }
.text-right { text-align: right; }
.text-muted { color: var(--text-muted); }
.mono { font-family: monospace; font-size: 12px; }

.psc-badge { display: inline-block; padding: 1px 6px; border-radius: 4px; font-size: 10px; font-weight: 600; background: rgba(76,175,80,0.15); color: #388E3C; }
.psc-badge.psc-manual { background: rgba(158,158,158,0.15); color: #757575; }

.agreement-card { transition: border-color 0.2s; }
.agreement-card.agreement-active { border-left: 3px solid #4CAF50; }
.agreement-card.agreement-archived { opacity: 0.6; }

.agreement-status { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; }
.agreement-status.st-draft { background: rgba(255,183,77,0.2); color: #EF6C00; }
.agreement-status.st-active { background: rgba(76,175,80,0.15); color: #2E7D32; }
.agreement-status.st-archived { background: rgba(158,158,158,0.15); color: #757575; }

.psc-file-link { color: var(--bk-orange); text-decoration: none; font-size: 11px; }
.psc-file-link:hover { text-decoration: underline; }

.form-group { margin-bottom: 12px; }
.form-group label { display: block; font-size: 11px; font-weight: 600; color: var(--text-muted); margin-bottom: 4px; }
.form-input { width: 100%; padding: 8px 10px; border: 1.5px solid var(--border); border-radius: 6px; font-size: 13px; background: var(--card); box-sizing: border-box; }
.form-input:focus { border-color: var(--bk-orange); outline: none; box-shadow: 0 0 0 3px rgba(245,166,35,0.12); }

.modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center; }
.modal-card { background: var(--bg); border-radius: 12px; padding: 24px; width: 90%; box-shadow: 0 8px 32px rgba(0,0,0,0.2); }

/* ═══ Currency ═══ */
.currency-badge { display:inline-block; padding:1px 5px; border-radius:4px; font-size:10px; font-weight:700; }
.currency-badge.cur-BYN { background:rgba(76,175,80,0.12); color:#2E7D32; }
.currency-badge.cur-RUB { background:rgba(33,150,243,0.12); color:#1565C0; }

.rate-control { display:flex; align-items:center; gap:4px; }
.rate-label { font-size:11px; color:var(--text-muted); font-weight:600; white-space:nowrap; }
.rate-input { width:70px; padding:3px 6px; border:1.5px solid var(--border); border-radius:5px; font-size:12px; text-align:center; background:var(--card); }
.rate-input:focus { border-color:var(--bk-orange); outline:none; }
.rate-display { font-size:11px; color:var(--text-muted); font-weight:600; white-space:nowrap; padding:4px 8px; background:var(--card); border-radius:5px; border:1px solid var(--border-light); }
</style>
