<template>
  <div class="ps-view">
    <div class="ps-header">
      <h1 class="page-title">Паллетовка склада <span class="ps-entity-badge">{{ shortEntity }}</span></h1>
      <div class="ps-header-right">
        <label class="btn primary" style="cursor:pointer;">
          <BkIcon name="import" size="sm" /> Импорт справочника
          <input type="file" style="display:none" accept=".xlsx,.xls" @change="importRef" />
        </label>
      </div>
    </div>

    <!-- Табы -->
    <div class="ps-tabs">
      <button class="ps-tab" :class="{ active: tab === 'calc' }" @click="tab = 'calc'">Расчёт</button>
      <button class="ps-tab" :class="{ active: tab === 'ref' }" @click="tab = 'ref'">Справочник</button>
    </div>

    <!-- ═══ Расчёт ═══ -->
    <div v-if="tab === 'calc'">
      <div v-if="loading" class="ps-empty">Загрузка...</div>
      <div v-else-if="!calcData.length" class="ps-empty">Справочник пуст. Загрузите файл «инфо по товарам».</div>
      <template v-else>
        <!-- KPI -->
        <div class="ps-kpi-row">
          <div v-for="g in calcGroups" :key="g.category" class="ps-kpi">
            <div class="ps-kpi-label">{{ g.category || 'Без категории' }}</div>
            <div class="ps-kpi-val">{{ g.totalCells.toFixed(1) }} <small>ячеек</small></div>
            <div class="ps-kpi-sub">{{ g.filledItems }}/{{ g.items }} товаров · {{ g.totalPallets.toFixed(1) }} паллет</div>
          </div>
          <div class="ps-kpi ps-kpi-total">
            <div class="ps-kpi-label">Итого</div>
            <div class="ps-kpi-val">{{ totalCells.toFixed(1) }} <small>ячеек</small></div>
            <div class="ps-kpi-sub">{{ filledCount }}/{{ calcData.length }} заполнено · {{ totalPallets.toFixed(1) }} паллет</div>
          </div>
        </div>

        <!-- Фильтр -->
        <div class="ps-filter-bar">
          <select v-model="filterCategory" class="ps-select">
            <option value="">Все категории</option>
            <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
          </select>
          <input v-model="searchQuery" class="ps-input" placeholder="Поиск по товару..." />
          <label class="ps-toggle">
            <input type="checkbox" v-model="hideEmpty" /> Только заполненные
          </label>
        </div>

        <!-- Таблица -->
        <div class="ps-table-wrap">
          <table class="ps-table">
            <thead>
              <tr>
                <th class="ps-th" @click="sortBy('name')">Товар {{ sortIcon('name') }}</th>
                <th class="ps-th ps-num" @click="sortBy('incoming_boxes')">Кол-во (всего) {{ sortIcon('incoming_boxes') }}</th>
                <th class="ps-th ps-num">Ед.</th>
                <th class="ps-th ps-num">Поставок</th>
                <th class="ps-th ps-num" @click="sortBy('boxes_per_delivery')">Кор/поставку {{ sortIcon('boxes_per_delivery') }}</th>
                <th class="ps-th ps-num">Кор/пал</th>
                <th class="ps-th ps-num" @click="sortBy('actual_height')">Высота (м) {{ sortIcon('actual_height') }}</th>
                <th class="ps-th ps-num" @click="sortBy('cell_coefficient')">Коэфф. {{ sortIcon('cell_coefficient') }}</th>
                <th class="ps-th ps-num" @click="sortBy('cells')">Ячеек {{ sortIcon('cells') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in filteredCalc" :key="r.ref_id" :class="{ 'ps-row-empty': !r.incoming_boxes }">
                <td>
                  <div class="ps-product-name">{{ r.name }}</div>
                  <div class="ps-product-cat">{{ r.storage_category }}</div>
                </td>
                <td class="ps-num">
                  <input type="number" :value="r.incoming_boxes || null" min="0" class="ps-boxes-input"
                    placeholder="—"
                    @change="updateField(r.ref_id, 'incoming_boxes', $event.target.value)" />
                </td>
                <td class="ps-num">
                  <select :value="r.input_unit || 'boxes'" class="ps-unit-select"
                    @change="updateField(r.ref_id, 'input_unit', $event.target.value)">
                    <option value="boxes">кор</option>
                    <option value="pieces">шт</option>
                  </select>
                </td>
                <td class="ps-num">
                  <input type="number" :value="r.delivery_frequency || null" min="1" max="30" class="ps-freq-input"
                    placeholder="1"
                    @change="updateField(r.ref_id, 'delivery_frequency', $event.target.value)" />
                </td>
                <td class="ps-num">{{ r.boxes_per_delivery || '' }}</td>
                <td class="ps-num ps-muted">{{ r.boxes_per_pallet || '—' }}</td>
                <td class="ps-num">{{ r.actual_height ? r.actual_height.toFixed(2) : (r.pallet_height_m ? r.pallet_height_m.toFixed(2) : '—') }}</td>
                <td class="ps-num">
                  <span v-if="r.cell_coefficient" class="ps-coeff" :class="coeffClass(r.cell_coefficient)">{{ r.cell_coefficient }}</span>
                  <span v-else>—</span>
                </td>
                <td class="ps-num ps-cells">{{ r.cells || '' }}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="ps-total-row">
                <td>Итого ({{ filteredCalc.length }})</td>
                <td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                <td class="ps-num ps-cells">{{ filteredCalc.reduce((s,r) => s + r.cells, 0).toFixed(2) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </template>
    </div>

    <!-- ═══ Справочник ═══ -->
    <div v-if="tab === 'ref'">
      <div v-if="loading" class="ps-empty">Загрузка...</div>
      <div v-else-if="!calcData.length" class="ps-empty">Справочник пуст. Загрузите файл.</div>
      <template v-else>
        <div class="ps-filter-bar">
          <span class="ps-meta">{{ calcData.length }} товаров</span>
          <input v-model="refSearch" class="ps-input" placeholder="Поиск..." />
        </div>
        <div class="ps-table-wrap">
          <table class="ps-table">
            <thead>
              <tr>
                <th class="ps-th">Товар</th>
                <th class="ps-th">Категория</th>
                <th class="ps-th ps-num">Шт/блок</th>
                <th class="ps-th ps-num">Бл/кор</th>
                <th class="ps-th ps-num">Кор/пал</th>
                <th class="ps-th ps-num">Размеры (мм)</th>
                <th class="ps-th ps-num">Высота пал. (м)</th>
                <th class="ps-th ps-num">Коэфф.</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in filteredRef" :key="r.ref_id">
                <td>{{ r.name }}</td>
                <td><span class="ps-cat-badge">{{ r.storage_category || '—' }}</span></td>
                <td class="ps-num">{{ r.pieces_per_block || '—' }}</td>
                <td class="ps-num">{{ r.blocks_per_box || '—' }}</td>
                <td class="ps-num">{{ r.boxes_per_pallet || '—' }}</td>
                <td class="ps-num">{{ r.box_length_mm && r.box_height_mm && r.box_width_mm ? `${r.box_length_mm}×${r.box_height_mm}×${r.box_width_mm}` : '—' }}</td>
                <td class="ps-num">{{ r.pallet_height_m ? parseFloat(r.pallet_height_m).toFixed(2) : '—' }}</td>
                <td class="ps-num">
                  <span v-if="r.cell_coefficient" class="ps-coeff" :class="'c' + String(r.cell_coefficient).replace('.','')">{{ parseFloat(r.cell_coefficient) }}</span>
                  <span v-else>—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { db } from '@/lib/apiClient.js';
import { useUserStore } from '@/stores/userStore.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { ENTITY_SHORT_NAMES } from '@/lib/legalEntities.js';
import BkIcon from '@/components/ui/BkIcon.vue';

const userStore = useUserStore();
const orderStore = useOrderStore();
const toast = useToastStore();
const shortEntity = computed(() => ENTITY_SHORT_NAMES[orderStore.settings.legalEntity] || orderStore.settings.legalEntity);

const tab = ref('calc');
const loading = ref(false);
const calcData = ref([]);
const filterCategory = ref('');
const searchQuery = ref('');
const refSearch = ref('');
const hideEmpty = ref(false);
const sortField = ref('name');
const sortAsc = ref(true);
const sortVersion = ref(0); // инкрементируется только при клике на заголовок

async function loadData() {
  loading.value = true;
  try {
    const { data, error } = await db.rpc('calc_pallet_occupancy', { legal_entity: orderStore.settings.legalEntity });
    if (error) throw error;
    calcData.value = (data || []).map(r => ({
      ...r,
      incoming_boxes: parseInt(r.incoming_boxes) || 0,
      input_unit: r.input_unit || 'boxes',
      delivery_frequency: parseInt(r.delivery_frequency) || 0,
      pieces_per_block: parseInt(r.pieces_per_block) || 0,
      boxes_per_delivery: parseInt(r.boxes_per_delivery) || 0,
      boxes_per_pallet: parseInt(r.boxes_per_pallet) || 0,
      pallets: parseFloat(r.pallets) || 0,
      pallet_height_m: parseFloat(r.pallet_height_m) || 0,
      actual_height: parseFloat(r.actual_height) || 0,
      cell_coefficient: parseFloat(r.cell_coefficient) || 0,
      cells: parseFloat(r.cells) || 0,
    }));
  } catch (e) {
    console.error(e);
    toast.error('Ошибка загрузки');
  } finally { loading.value = false; }
}

const categories = computed(() => [...new Set(calcData.value.map(r => r.storage_category).filter(Boolean))].sort());
const filledCount = computed(() => calcData.value.filter(r => r.incoming_boxes > 0).length);

const calcGroups = computed(() => {
  const map = {};
  for (const r of calcData.value) {
    const cat = r.storage_category || 'Без категории';
    if (!map[cat]) map[cat] = { category: cat, items: 0, filledItems: 0, totalCells: 0, totalPallets: 0 };
    map[cat].items++;
    if (r.incoming_boxes > 0) map[cat].filledItems++;
    map[cat].totalCells += r.cells;
    map[cat].totalPallets += r.pallets;
  }
  return Object.values(map).sort((a, b) => a.category.localeCompare(b.category));
});

const totalCells = computed(() => calcData.value.reduce((s, r) => s + r.cells, 0));
const totalPallets = computed(() => calcData.value.reduce((s, r) => s + r.pallets, 0));

const filteredCalc = computed(() => {
  let list = calcData.value;
  if (filterCategory.value) list = list.filter(r => r.storage_category === filterCategory.value);
  if (hideEmpty.value) list = list.filter(r => r.incoming_boxes > 0);
  if (searchQuery.value) {
    const q = searchQuery.value.trim().toLowerCase();
    const words = q.split(/\s+/).filter(w => w.length > 1);
    // Первое слово — может быть артикулом, ищем по началу имени
    const firstWord = words[0] || q;
    list = list.filter(r => {
      const name = r.name.toLowerCase();
      // Совпадение по артикулу (начало имени)
      if (name.startsWith(firstWord)) return true;
      // Или хотя бы половина слов содержится в имени
      const matched = words.filter(w => name.includes(w)).length;
      return matched >= Math.ceil(words.length / 2);
    });
  }
  const _v = sortVersion.value; // зависимость для пересортировки только по клику
  const f = sortField.value;
  const dir = sortAsc.value ? 1 : -1;
  return [...list].sort((a, b) => {
    const av = a[f] ?? '', bv = b[f] ?? '';
    if (typeof av === 'number') return (av - bv) * dir;
    return String(av).localeCompare(String(bv)) * dir;
  });
});

const filteredRef = computed(() => {
  if (!refSearch.value) return calcData.value;
  const q = refSearch.value.toLowerCase();
  return calcData.value.filter(r => r.name.toLowerCase().includes(q) || (r.storage_category && r.storage_category.toLowerCase().includes(q)));
});

function sortBy(field) {
  if (sortField.value === field) sortAsc.value = !sortAsc.value;
  else { sortField.value = field; sortAsc.value = field === 'name'; }
  sortVersion.value++;
}
function sortIcon(field) {
  if (sortField.value !== field) return '';
  return sortAsc.value ? '↑' : '↓';
}

function coeffClass(c) {
  if (c <= 0.25) return 'c025';
  if (c <= 0.5) return 'c05';
  return 'c1';
}

function calcCoeff(h) {
  if (h <= 0) return 0;
  if (h <= 0.30) return 0.25;
  if (h <= 0.85) return 0.5;
  return 1.0;
}

function recalcRow(row) {
  const freq = row.delivery_frequency || 1;
  // Если ввод в штуках — пересчитать в коробки
  let totalBoxes = row.incoming_boxes;
  if (row.input_unit === 'pieces' && row.pieces_per_block > 0) {
    totalBoxes = Math.ceil(row.incoming_boxes / row.pieces_per_block);
  }
  const boxesPerDelivery = freq > 1 ? Math.ceil(totalBoxes / freq) : totalBoxes;
  row.boxes_per_delivery = boxesPerDelivery;
  const bpp = row.boxes_per_pallet || 0;
  const fullH = row.pallet_height_m || 0;
  if (bpp > 0 && boxesPerDelivery > 0) {
    const fullPallets = Math.floor(boxesPerDelivery / bpp);
    const remainder = boxesPerDelivery % bpp;
    if (fullPallets > 0) {
      let cells = fullPallets;
      if (remainder > 0) {
        const lastH = fullH * (remainder / bpp);
        cells += calcCoeff(lastH);
      }
      row.actual_height = fullH;
      row.cell_coefficient = 1;
      row.cells = Math.round(cells * 100) / 100;
    } else {
      const h = fullH * (boxesPerDelivery / bpp);
      row.actual_height = Math.round(h * 10000) / 10000;
      row.cell_coefficient = calcCoeff(h);
      row.cells = row.cell_coefficient;
    }
    row.pallets = Math.round(boxesPerDelivery / bpp * 100) / 100;
  } else {
    row.pallets = 0; row.actual_height = 0; row.cell_coefficient = 0; row.cells = 0; row.boxes_per_delivery = 0;
  }
}

async function updateField(id, field, val) {
  const isNum = field !== 'input_unit';
  const v = isNum ? (val ? parseInt(val) : null) : (val || 'boxes');
  await db.rpc('update_pallet_field', { id, field, value: v });
  await loadData();
}

// ═══ Импорт ═══
async function importRef(e) {
  const file = e.target.files?.[0];
  if (!file) return;
  try {
    const XLSX = (await import('xlsx-js-style')).default;
    const buf = await file.arrayBuffer();
    const wb = XLSX.read(buf, { type: 'array' });
    const ws = wb.Sheets[wb.SheetNames[0]];
    const data = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });
    if (data.length < 2) { toast.error('Пустой файл'); return; }

    const headers = data[0].map(h => String(h).toLowerCase().trim());
    const items = [];
    const hasPartner = headers.some(h => h.includes('партнёр') || h.includes('партнер'));

    if (hasPartner) {
      for (let i = 1; i < data.length; i++) {
        const row = data[i];
        const name = String(row[2] || '').trim();
        if (!name) continue;
        items.push({
          name, storage_category: String(row[1] || '').trim(),
          sku: String(row[3] || '').trim() || null,
          pieces_per_block: parseInt(row[4]) || null, blocks_per_box: parseInt(row[5]) || 1,
          boxes_per_pallet: parseInt(row[6]) || null, pieces_per_pallet: null,
          box_length_mm: parseInt(row[7]) || null, box_height_mm: parseInt(row[8]) || null, box_width_mm: parseInt(row[9]) || null,
        });
      }
    } else {
      for (let i = 1; i < data.length; i++) {
        const row = data[i];
        const name = String(row[1] || '').trim();
        if (!name) continue;
        const ppb = parseInt(row[2]) || null;
        const ppp = parseInt(row[3]) || null;
        // Штук в Паллете — это коробок (блоков) на паллете, т.к. 1 блок = 1 коробка
        const bpp = ppp;
        items.push({
          name, storage_category: String(row[0] || '').trim(),
          sku: null, pieces_per_block: ppb, blocks_per_box: 1,
          boxes_per_pallet: bpp, pieces_per_pallet: ppp,
          box_length_mm: parseInt(row[4]) || null, box_height_mm: parseInt(row[5]) || null, box_width_mm: parseInt(row[6]) || null,
        });
      }
    }

    if (!items.length) { toast.error('Не найдено товаров'); return; }
    const le = orderStore.settings.legalEntity;
    if (!le) { toast.error('Не выбрано юрлицо'); return; }
    if (!confirm(`Импортировать ${items.length} товаров в справочник юрлица «${le}»? Записи других юрлиц не пострадают.`)) return;
    const { error } = await db.rpc('import_pallet_reference', { items, legal_entity: le });
    if (error) throw error;
    toast.success(`Импортировано ${items.length} товаров в «${le}»`);
    await loadData();
  } catch (err) {
    console.error(err);
    toast.error('Ошибка импорта');
  } finally { e.target.value = ''; }
}

onMounted(() => { loadData(); });
watch(() => orderStore.settings.legalEntity, () => { loadData(); });
</script>

<style scoped>
.ps-view { padding: 0; }
.ps-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
.ps-entity-badge { display: inline-block; margin-left: 8px; padding: 3px 10px; border-radius: 12px; background: #fff2e0; color: #E76F51; font-size: 12px; font-weight: 700; vertical-align: middle; }
.ps-header-right { display: flex; align-items: center; gap: 8px; }
.ps-tabs { display: inline-flex; border: 1.5px solid var(--border); border-radius: 8px; overflow: hidden; margin-bottom: 16px; }
.ps-tab { padding: 6px 16px; font-size: 13px; font-weight: 600; border: none; background: none; cursor: pointer; color: var(--text-muted); transition: all .15s; }
.ps-tab.active { background: var(--bk-brown); color: #fff; }
.ps-select { padding: 6px 10px; border: 1.5px solid var(--border); border-radius: 8px; background: var(--card); font-size: 13px; }
.ps-input { padding: 6px 10px; border: 1.5px solid var(--border); border-radius: 8px; background: var(--card); font-size: 13px; flex: 1; max-width: 300px; }
.ps-empty { text-align: center; padding: 48px; color: var(--text-muted); font-size: 14px; }
.ps-meta { font-size: 13px; color: var(--text-muted); font-weight: 600; }
.ps-muted { color: var(--text-muted); font-size: 12px; }
.ps-filter-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; flex-wrap: wrap; }
.ps-toggle { display: flex; align-items: center; gap: 5px; font-size: 12px; color: var(--text-muted); cursor: pointer; white-space: nowrap; }
.ps-toggle input { accent-color: var(--bk-red); }

/* KPI */
.ps-kpi-row { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
.ps-kpi { flex: 1; min-width: 140px; background: var(--card); border: 1px solid var(--border-light); border-radius: 10px; padding: 14px 18px; }
.ps-kpi-total { background: var(--bk-brown); color: #fff; }
.ps-kpi-total .ps-kpi-label { color: rgba(255,255,255,.7); }
.ps-kpi-total .ps-kpi-sub { color: rgba(255,255,255,.6); }
.ps-kpi-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted); margin-bottom: 4px; }
.ps-kpi-val { font-size: 22px; font-weight: 800; color: var(--bk-brown); }
.ps-kpi-total .ps-kpi-val { color: #fff; }
.ps-kpi-val small { font-size: 12px; font-weight: 600; opacity: .7; }
.ps-kpi-sub { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

/* Таблица */
.ps-table-wrap { overflow-x: auto; }
.ps-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.ps-table th, .ps-table td { padding: 8px 10px; border-bottom: 1px solid var(--border-light); text-align: left; }
.ps-th { font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: .3px; color: var(--text-muted); cursor: pointer; white-space: nowrap; user-select: none; }
.ps-th:hover { color: var(--bk-brown); }
.ps-num { text-align: right !important; font-variant-numeric: tabular-nums; }
.ps-cells { font-weight: 700; color: var(--bk-brown); }
.ps-product-name { font-weight: 600; }
.ps-product-cat { font-size: 11px; color: var(--text-muted); }
.ps-row-empty { opacity: 0.5; }
.ps-total-row { font-weight: 700; background: rgba(139,115,85,.04); }
.ps-total-row td { border-top: 2px solid var(--border); }
.ps-cat-badge { font-size: 11px; padding: 2px 8px; background: rgba(244,162,97,.08); border-radius: 4px; color: var(--bk-orange); font-weight: 600; }

/* Коэффициент */
.ps-coeff { display: inline-block; padding: 2px 8px; border-radius: 4px; font-weight: 700; font-size: 12px; }
.ps-coeff.c025 { background: #E8F5E9; color: #2E7D32; }
.ps-coeff.c05 { background: #FFF3E0; color: #E65100; }
.ps-coeff.c1 { background: #FFEBEE; color: #C62828; }

/* Ввод коробок */
.ps-boxes-input { width: 70px; padding: 4px 8px; border: 1.5px solid var(--border); border-radius: 6px; text-align: right; font-size: 13px; font-weight: 600; background: var(--card); }
.ps-boxes-input:focus { border-color: var(--bk-orange); outline: none; box-shadow: 0 0 0 2px rgba(244,162,97,.15); }
.ps-unit-select { padding: 3px 4px; border: 1.5px solid var(--border); border-radius: 6px; font-size: 11px; background: var(--card); cursor: pointer; }
.ps-boxes-input::placeholder { color: var(--text-muted); font-weight: 400; }

@media (max-width: 600px) {
  .ps-kpi-row { flex-direction: column; }
  .ps-header { flex-direction: column; align-items: stretch; }
}
</style>
