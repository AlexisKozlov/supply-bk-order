<template>
  <div class="abc-view">
    <div class="abc-header">
      <div class="abc-controls">
        <select v-model="period" class="abc-select">
          <option value="30">1 месяц</option>
          <option value="90">3 месяца</option>
          <option value="180">6 месяцев</option>
          <option value="365">1 год</option>
        </select>
        <select v-model="dataSource" class="abc-select">
          <option value="orders">По заказам</option>
          <option value="analysis">По расходу (анализ)</option>
          <option value="both">Оба источника</option>
        </select>
      </div>
    </div>

    <div v-if="loading" style="text-align:center;padding:60px;"><BurgerSpinner text="Загрузка данных..." /></div>
    <template v-else-if="items.length">
      <!-- Матрица ABC/XYZ -->
      <div class="abc-matrix-card">
        <div class="abc-card-title">Матрица ABC/XYZ</div>
        <div class="abc-matrix">
          <div class="matrix-corner"></div>
          <div class="matrix-header x">X <span class="matrix-hint">стабильный</span></div>
          <div class="matrix-header y">Y <span class="matrix-hint">колебания</span></div>
          <div class="matrix-header z">Z <span class="matrix-hint">хаотичный</span></div>
          <template v-for="abc in ['A','B','C']" :key="abc">
            <div class="matrix-row-header" :class="'abc-' + abc">{{ abc }} <span class="matrix-hint">{{ abcHint(abc) }}</span></div>
            <div v-for="xyz in ['X','Y','Z']" :key="abc+xyz"
              class="matrix-cell" :class="['abc-' + abc, 'xyz-' + xyz, { active: activeCell === abc+xyz }]"
              @click="activeCell = activeCell === abc+xyz ? '' : abc+xyz">
              <div class="cell-count">{{ countByGroup(abc, xyz) }}</div>
              <div class="cell-pct">{{ pctByGroup(abc, xyz) }}%</div>
            </div>
          </template>
        </div>
      </div>

      <!-- Сводка -->
      <div class="abc-summary-row">
        <div class="abc-stat-card">
          <div class="abc-stat-label">Всего товаров</div>
          <div class="abc-stat-value">{{ items.length }}</div>
        </div>
        <div class="abc-stat-card">
          <div class="abc-stat-label">A (топ 80%)</div>
          <div class="abc-stat-value abc-a">{{ countByAbc('A') }}</div>
        </div>
        <div class="abc-stat-card">
          <div class="abc-stat-label">B (15%)</div>
          <div class="abc-stat-value abc-b">{{ countByAbc('B') }}</div>
        </div>
        <div class="abc-stat-card">
          <div class="abc-stat-label">C (5%)</div>
          <div class="abc-stat-value abc-c">{{ countByAbc('C') }}</div>
        </div>
      </div>

      <!-- Таблица товаров -->
      <div class="abc-table-card">
        <div class="abc-card-title">
          <span>{{ activeCell ? `Группа ${activeCell}` : 'Все товары' }} <span v-if="activeCell" class="abc-clear-filter" @click="activeCell = ''">&times; Сбросить</span></span>
          <div class="abc-search-wrap">
            <input v-model="search" class="abc-search" placeholder="Поиск по названию или SKU..." />
          </div>
        </div>
        <div class="abc-table-wrap">
          <table class="abc-table">
            <thead>
              <tr>
                <th class="col-rank">#</th>
                <th class="col-name" @click="sortBy('name')">Товар {{ sortIcon('name') }}</th>
                <th @click="sortBy('supplier')">Поставщик {{ sortIcon('supplier') }}</th>
                <th @click="sortBy('totalQty')">Объём {{ sortIcon('totalQty') }}</th>
                <th @click="sortBy('cumPct')">% от общего {{ sortIcon('cumPct') }}</th>
                <th @click="sortBy('abc')">ABC {{ sortIcon('abc') }}</th>
                <th @click="sortBy('cv')">CV {{ sortIcon('cv') }}</th>
                <th @click="sortBy('xyz')">XYZ {{ sortIcon('xyz') }}</th>
                <th>Группа</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(item, i) in filteredItems" :key="item.sku" :class="'abc-row-' + item.abc">
                <td class="col-rank">{{ i + 1 }}</td>
                <td class="col-name">
                  <div class="item-name">{{ item.name }}</div>
                  <div class="item-sku">{{ item.sku }}</div>
                </td>
                <td>{{ item.supplier }}</td>
                <td class="num-cell">{{ formatNum(item.totalQty) }}</td>
                <td class="num-cell">{{ item.cumPct.toFixed(1) }}%</td>
                <td class="abc-cell"><span class="abc-tag" :class="'abc-' + item.abc">{{ item.abc }}</span></td>
                <td class="num-cell">{{ item.cv != null ? item.cv.toFixed(2) : '—' }}</td>
                <td class="abc-cell"><span class="xyz-tag" :class="'xyz-' + item.xyz">{{ item.xyz }}</span></td>
                <td class="abc-cell"><span class="group-tag" :class="'abc-' + item.abc + ' xyz-' + item.xyz">{{ item.abc }}{{ item.xyz }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
    <div v-else class="abc-empty">
      <div>Нет данных для анализа</div>
      <div style="font-size:12px;color:var(--text-muted);margin-top:8px;">Нужны заказы или данные расхода за выбранный период</div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { db } from '@/lib/apiClient.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';

const orderStore = useOrderStore();
const toast = useToastStore();

const legalEntity = computed(() => orderStore.settings.legalEntity);
const period = ref('90');
const dataSource = ref('both');
const loading = ref(false);
const items = ref([]);
const search = ref('');
const activeCell = ref('');
const sortKey = ref('cumPct');
const sortAsc = ref(true);

function abcHint(abc) {
  if (abc === 'A') return 'топ 80%';
  if (abc === 'B') return 'след. 15%';
  return 'остальные';
}

function sortBy(key) {
  if (sortKey.value === key) { sortAsc.value = !sortAsc.value; }
  else { sortKey.value = key; sortAsc.value = key === 'name' || key === 'supplier'; }
}
function sortIcon(key) {
  if (sortKey.value !== key) return '';
  return sortAsc.value ? '↑' : '↓';
}

const filteredItems = computed(() => {
  let list = items.value;
  if (activeCell.value) {
    const abc = activeCell.value[0];
    const xyz = activeCell.value[1];
    list = list.filter(it => it.abc === abc && it.xyz === xyz);
  }
  if (search.value) {
    const q = search.value.toLowerCase();
    list = list.filter(it => it.name.toLowerCase().includes(q) || it.sku.toLowerCase().includes(q));
  }
  const key = sortKey.value;
  const dir = sortAsc.value ? 1 : -1;
  return [...list].sort((a, b) => {
    const va = a[key], vb = b[key];
    if (typeof va === 'string') return va.localeCompare(vb) * dir;
    return ((va || 0) - (vb || 0)) * dir;
  });
});

function countByGroup(abc, xyz) { return items.value.filter(it => it.abc === abc && it.xyz === xyz).length; }
function pctByGroup(abc, xyz) {
  if (!items.value.length) return '0';
  return (countByGroup(abc, xyz) / items.value.length * 100).toFixed(0);
}
function countByAbc(abc) { return items.value.filter(it => it.abc === abc).length; }
function formatNum(n) {
  if (n == null) return '—';
  return Math.round(n).toLocaleString('ru-RU');
}

let _loadGen = 0;
async function loadData() {
  const le = legalEntity.value;
  if (!le) return;
  const gen = ++_loadGen;
  loading.value = true;

  try {
    // Загружаем товары
    const { data: products, error: pErr } = await db.from('products').select('sku,name,supplier,qty_per_box,category').eq('legal_entity', le);
    if (gen !== _loadGen) return;
    if (pErr) { toast.error('Ошибка загрузки товаров', pErr); return; }
    if (!products?.length) { items.value = []; return; }

    const prodMap = {};
    for (const p of products) {
      prodMap[p.sku] = { sku: p.sku, name: p.name, supplier: p.supplier || '', qtyPerBox: parseFloat(p.qty_per_box) || 1, category: p.category || '', totalQty: 0, cv: null };
    }

    // Загружаем product_adu (CV)
    const skus = products.map(p => p.sku);
    const { data: aduData } = await db.from('product_adu').select('sku,adu,cv,sample_count').eq('legal_entity', le).in('sku', skus);
    if (gen !== _loadGen) return;
    const aduMap = {};
    if (aduData) for (const a of aduData) aduMap[a.sku] = a;

    // Источник данных: заказы
    if (dataSource.value === 'orders' || dataSource.value === 'both') {
      const dateFrom = new Date();
      dateFrom.setDate(dateFrom.getDate() - parseInt(period.value));
      const dateStr = dateFrom.toISOString().slice(0, 10);

      const { data: orders } = await db.from('orders').select('id,delivery_date').eq('legal_entity', le).gte('delivery_date', dateStr);
      if (gen !== _loadGen) return;

      if (orders?.length) {
        const orderIds = orders.map(o => o.id);
        // Загружаем позиции порциями
        const allItems = [];
        for (let i = 0; i < orderIds.length; i += 50) {
          const batch = orderIds.slice(i, i + 50);
          const { data: oi } = await db.from('order_items').select('sku,qty_boxes,qty_per_box').in('order_id', batch);
          if (gen !== _loadGen) return;
          if (oi) allItems.push(...oi);
        }
        for (const oi of allItems) {
          if (!oi.sku || !prodMap[oi.sku]) continue;
          const qty = (parseFloat(oi.qty_boxes) || 0) * (parseFloat(oi.qty_per_box) || prodMap[oi.sku].qtyPerBox);
          prodMap[oi.sku].totalQty += qty;
        }
      }
    }

    // Источник данных: analysis_data (расход)
    if (dataSource.value === 'analysis' || dataSource.value === 'both') {
      const { data: analysisData } = await db.from('analysis_data').select('sku,consumption,period_days').eq('legal_entity', le).in('sku', skus);
      if (gen !== _loadGen) return;
      if (analysisData) {
        for (const a of analysisData) {
          if (!prodMap[a.sku]) continue;
          const daily = (parseFloat(a.consumption) || 0) / (parseFloat(a.period_days) || 30);
          const totalForPeriod = daily * parseInt(period.value);
          if (dataSource.value === 'analysis') {
            prodMap[a.sku].totalQty = totalForPeriod;
          } else {
            // Для 'both' — берём максимум из двух источников (чтобы не упустить товары)
            prodMap[a.sku].totalQty = Math.max(prodMap[a.sku].totalQty, totalForPeriod);
          }
        }
      }
    }

    // Применяем CV из product_adu
    for (const sku in prodMap) {
      if (aduMap[sku]) {
        prodMap[sku].cv = parseFloat(aduMap[sku].cv) || 0;
      }
    }

    // Фильтруем товары с нулевым объёмом
    let result = Object.values(prodMap).filter(it => it.totalQty > 0);

    // Сортируем по объёму (убывание) для ABC
    result.sort((a, b) => b.totalQty - a.totalQty);

    // Расчёт ABC
    const totalAll = result.reduce((s, it) => s + it.totalQty, 0);
    let cum = 0;
    for (const it of result) {
      cum += it.totalQty;
      it.cumPct = totalAll > 0 ? (cum / totalAll * 100) : 0;
      if (it.cumPct <= 80) it.abc = 'A';
      else if (it.cumPct <= 95) it.abc = 'B';
      else it.abc = 'C';
    }

    // Расчёт XYZ
    for (const it of result) {
      if (it.cv == null || it.cv === 0) {
        it.xyz = 'Z'; // нет данных — непредсказуемый
      } else if (it.cv <= 0.3) {
        it.xyz = 'X';
      } else if (it.cv <= 0.7) {
        it.xyz = 'Y';
      } else {
        it.xyz = 'Z';
      }
    }

    items.value = result;
  } catch (err) {
    if (gen === _loadGen) toast.error('Ошибка', err.message);
  } finally {
    if (gen === _loadGen) loading.value = false;
  }
}

onMounted(() => { loadData(); });
watch([legalEntity, period, dataSource], () => { items.value = []; loadData(); });
</script>

<style scoped>
.abc-view { padding:0; }
.abc-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:16px; }
.abc-controls { display:flex; gap:8px; }
.abc-select { padding:6px 12px; border:1.5px solid #D4C4B0; border-radius:8px; font-size:12px; font-family:inherit; background:white; color:var(--text); }

/* Матрица */
.abc-matrix-card { background:white; border-radius:14px; padding:20px; box-shadow:0 1px 4px rgba(0,0,0,0.06); margin-bottom:16px; }
.abc-card-title { font-size:14px; font-weight:700; color:var(--bk-brown, #502314); margin-bottom:16px; display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap; }

.abc-matrix { display:grid; grid-template-columns:80px 1fr 1fr 1fr; gap:4px; max-width:500px; }
.matrix-corner { }
.matrix-header { text-align:center; font-size:16px; font-weight:800; padding:8px; color:var(--bk-brown); }
.matrix-header .matrix-hint { display:block; font-size:9px; font-weight:500; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.3px; }
.matrix-row-header { display:flex; flex-direction:column; align-items:center; justify-content:center; font-size:16px; font-weight:800; padding:8px; border-radius:8px; }
.matrix-row-header .matrix-hint { font-size:9px; font-weight:500; color:var(--text-muted); }
.matrix-row-header.abc-A { background:#E8F5E9; color:#2E7D32; }
.matrix-row-header.abc-B { background:#FFF3E0; color:#E65100; }
.matrix-row-header.abc-C { background:#FFEBEE; color:#C62828; }

.matrix-cell { text-align:center; padding:14px 8px; border-radius:10px; cursor:pointer; transition:all .15s; border:2px solid transparent; }
.matrix-cell:hover { transform:scale(1.03); }
.matrix-cell.active { border-color:var(--bk-brown); box-shadow:0 2px 8px rgba(0,0,0,0.1); }
.matrix-cell.abc-A.xyz-X { background:#C8E6C9; }
.matrix-cell.abc-A.xyz-Y { background:#DCEDC8; }
.matrix-cell.abc-A.xyz-Z { background:#F0F4C3; }
.matrix-cell.abc-B.xyz-X { background:#DCEDC8; }
.matrix-cell.abc-B.xyz-Y { background:#FFF9C4; }
.matrix-cell.abc-B.xyz-Z { background:#FFE0B2; }
.matrix-cell.abc-C.xyz-X { background:#F0F4C3; }
.matrix-cell.abc-C.xyz-Y { background:#FFE0B2; }
.matrix-cell.abc-C.xyz-Z { background:#FFCDD2; }
.cell-count { font-size:20px; font-weight:800; color:var(--text); }
.cell-pct { font-size:10px; color:var(--text-muted); margin-top:2px; }

/* Сводка */
.abc-summary-row { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:16px; }
.abc-stat-card { background:white; border-radius:12px; padding:14px 16px; box-shadow:0 1px 4px rgba(0,0,0,0.06); }
.abc-stat-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:4px; }
.abc-stat-value { font-size:22px; font-weight:800; }
.abc-stat-value.abc-a { color:#2E7D32; }
.abc-stat-value.abc-b { color:#E65100; }
.abc-stat-value.abc-c { color:#C62828; }

/* Таблица */
.abc-table-card { background:white; border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,0.06); overflow:hidden; }
.abc-search-wrap { }
.abc-search { padding:6px 12px; border:1.5px solid #D4C4B0; border-radius:8px; font-size:12px; font-family:inherit; width:220px; }
.abc-search:focus { border-color:#D62300; outline:none; }
.abc-clear-filter { font-size:11px; color:var(--text-muted); cursor:pointer; margin-left:6px; }
.abc-clear-filter:hover { color:#C62828; }

.abc-table-wrap { overflow-x:auto; }
.abc-table { width:100%; border-collapse:collapse; font-size:12px; }
.abc-table th { padding:10px 12px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); background:#FEFBF7; border-bottom:2px solid #E8E0D6; cursor:pointer; white-space:nowrap; text-align:center; }
.abc-table th:first-child, .abc-table th.col-name { text-align:left; }
.abc-table td { padding:8px 12px; border-bottom:1px solid #F0EBE4; text-align:center; }
.abc-table tbody tr:hover { background:#FEFBF7; }
.abc-table .col-rank { width:40px; text-align:center; color:var(--text-muted); font-size:11px; }
.abc-table .col-name { text-align:left; min-width:180px; }
.item-name { font-weight:600; font-size:12px; }
.item-sku { font-size:10px; color:var(--text-muted); }
.num-cell { font-family:'JetBrains Mono',monospace; font-weight:500; text-align:right; }
.abc-cell { text-align:center; }

.abc-tag, .xyz-tag, .group-tag { display:inline-block; padding:2px 10px; border-radius:6px; font-size:10px; font-weight:700; }
.abc-tag.abc-A { background:#E8F5E9; color:#2E7D32; }
.abc-tag.abc-B { background:#FFF3E0; color:#E65100; }
.abc-tag.abc-C { background:#FFEBEE; color:#C62828; }
.xyz-tag.xyz-X { background:#E3F2FD; color:#1565C0; }
.xyz-tag.xyz-Y { background:#FFF3E0; color:#E65100; }
.xyz-tag.xyz-Z { background:#FCE4EC; color:#AD1457; }
.group-tag { font-size:11px; font-weight:800; padding:3px 12px; border-radius:8px; }
.group-tag.abc-A.xyz-X { background:#C8E6C9; color:#1B5E20; }
.group-tag.abc-A.xyz-Y { background:#DCEDC8; color:#33691E; }
.group-tag.abc-A.xyz-Z { background:#F0F4C3; color:#827717; }
.group-tag.abc-B.xyz-X { background:#DCEDC8; color:#33691E; }
.group-tag.abc-B.xyz-Y { background:#FFF9C4; color:#F57F17; }
.group-tag.abc-B.xyz-Z { background:#FFE0B2; color:#E65100; }
.group-tag.abc-C.xyz-X { background:#F0F4C3; color:#827717; }
.group-tag.abc-C.xyz-Y { background:#FFE0B2; color:#E65100; }
.group-tag.abc-C.xyz-Z { background:#FFCDD2; color:#B71C1C; }

.abc-row-A { }
.abc-row-B { }
.abc-row-C td { opacity:0.7; }

.abc-empty { text-align:center; padding:60px 20px; font-size:14px; color:var(--text-muted); }

/* Мобильная адаптация */
@media (max-width: 768px) {
  .abc-header { flex-direction:column; align-items:stretch; }
  .abc-summary-row { grid-template-columns:repeat(2,1fr); }
  .abc-matrix { max-width:100%; }
  .abc-search { width:100%; }
}
@media (max-width: 480px) {
  .abc-summary-row { grid-template-columns:1fr 1fr; gap:8px; }
  .abc-controls { flex-wrap:wrap; }
  .abc-select { flex:1; }
  .abc-table th, .abc-table td { padding:6px 8px; font-size:11px; }
}
</style>
