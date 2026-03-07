<template>
  <div class="order-table-wrapper" :class="{ 'compact-mode': compact }">
    <table class="order-table">
      <thead>
        <tr v-if="!compact">
          <th style="width:30px;"></th>
          <th>Наименование товара</th>
          <th>Расход<br><small>(за период)</small></th>
          <th>Остаток</th>
          <th v-if="settings.hasTransit" class="transit-col">Транзит</th>
          <th class="stock-col">Запас</th>
          <th v-if="cdaMode">Буфер</th>
          <th>Расчёт<br><small>заказа</small></th>
          <th>
            Заказ<br>
            <small>{{ settings.unit === 'boxes' ? 'уч.кор / физ.кор' : 'шт / физ.кор' }}</small>
          </th>
          <th>Хватит до<br><small>(после поставки)</small></th>
          <th>Паллеты</th>
          <th v-if="hasPrices" class="price-col">Сумма<br><small>BYN</small></th>
          <th style="width:32px;"></th>
        </tr>
        <tr v-else>
          <th style="width:4px;"></th>
          <th>Товар</th>
          <th>Расход</th>
          <th>Остаток</th>
          <th v-if="settings.hasTransit" class="transit-col">Транзит</th>
          <th class="stock-col">Запас</th>
          <th v-if="cdaMode">Буф.</th>
          <th>Расчёт</th>
          <th>{{ settings.unit === 'boxes' ? 'Уч/Физ' : 'Шт/Физ' }}</th>
          <th>До</th>
          <th>Пал.</th>
          <th v-if="hasPrices" class="price-col">Сум.</th>
          <th style="width:24px;"></th>
        </tr>
      </thead>
      <tbody>
        <tr v-if="!orderStore.items.length" class="order-empty-row">
          <td :colspan="colSpan" class="order-empty-cell">
            <div class="order-empty-hint">
              <span class="order-empty-icon"><BkIcon name="order" size="sm"/></span>
              <span class="order-empty-text" v-if="!settings.supplier">Выберите поставщика</span>
              <span class="order-empty-text" v-else>Товары загружены. Заполните параметры и данные заказа</span>
            </div>
          </td>
        </tr>
        <template v-for="index in filteredIndices" :key="orderStore.items[index]?.id">
          <OrderRow
            :item="orderStore.items[index]"
            :row-index="index"
            :settings="settings"
            :compact="compact"
            :avg-consumption="avgConsumptionMap[orderStore.items[index]?.sku] || 0"
            :data-validation="!orderStore.viewOnlyMode"
            :adu-value="props.aduMap.get(orderStore.items[index]?.sku)?.adu || 0"
            :cda-mode="props.cdaMode"
            :cda-params="props.cdaMode ? getCdaParams(orderStore.items[index]) : null"
            :price-info="props.priceMap[orderStore.items[index]?.sku] || null"
            :has-prices="hasPrices"
            :ref="el => { if (el) rowRefs[index] = el; }"
            @remove="orderStore.removeItem($event); draftStore.save();"
            @edit-product="$emit('edit-product', $event)"
            @drag-start="onDragStart"
            @drag-over="onDragOver"
            @drop="onDrop"
            @drag-end="onDragEnd"
            @nav="onNav"
          />
        </template>
        <!-- Строка добавления товара -->
        <tr v-if="showAddRow" class="add-product-row">
          <td :colspan="colSpan" style="padding:2px 8px;text-align:left;">
            <select class="add-product-select" @change="addProduct">
              <option value="">+ Добавить товар…</option>
              <option v-for="p in availableToAdd" :key="p.sku" :value="p.sku">{{ p.sku }} — {{ p.name }}{{ p.is_active === 0 ? ' (скрыта)' : '' }}</option>
            </select>
          </td>
        </tr>
      </tbody>
    </table>

    <!-- Итоговая строка -->
    <div class="final-summary" v-if="orderStore.finalSummary">
      <span>Позиций в заказе: <b>{{ orderStore.finalSummary.positions }}</b></span>
      <span v-if="orderStore.finalSummary.pallets > 0">
        · Паллет: <b>{{ orderStore.finalSummary.pallets }}</b>
        <template v-if="orderStore.finalSummary.boxesLeft > 0">
          + {{ orderStore.finalSummary.boxesLeft }} кор.
        </template>
      </span>
      <span v-if="hasPrices && totalOrderSum > 0" class="total-sum">
        · Сумма: <b>{{ totalOrderSum.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }} BYN</b>
      </span>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { useOrderStore } from '@/stores/orderStore.js';
import { useDraftStore } from '@/stores/draftStore.js';
import { db } from '@/lib/apiClient.js';
import { getQpb, getMultiplicity, applyEntityFilter } from '@/lib/utils.js';
import OrderRow from './OrderRow.vue';
import BkIcon from '@/components/ui/BkIcon.vue';


const props = defineProps({
  compact: { type: Boolean, default: false },
  appliedAnalogs: { type: Map, default: () => new Map() },
  aduMap: { type: Map, default: () => new Map() },
  cdaMode: { type: Boolean, default: false },
  cdaSupplierDlt: { type: Number, default: 0 },
  cdaSupplierDoc: { type: Number, default: 0 },
  cdaSafetyCoef: { type: Number, default: 1.0 },
  filterQuery: { type: String, default: '' },
  priceMap: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['edit-product']);

const orderStore = useOrderStore();
const draftStore = useDraftStore();
const settings = computed(() => orderStore.settings);

// ─── Цены ─────────────────────────────────────────────────────────────────
const hasPrices = computed(() => Object.keys(props.priceMap).length > 0);

const totalOrderSum = computed(() => {
  if (!hasPrices.value) return 0;
  let sum = 0;
  const isBoxes = settings.value.unit === 'boxes';
  for (const item of orderStore.items) {
    const pi = props.priceMap[item.sku];
    if (!pi || !item.finalOrder) continue;
    const qpb = getQpb(item);
    const mult = getMultiplicity(item);
    const fo = item.finalOrder;
    const accountingBoxes = isBoxes ? fo : Math.ceil(fo / qpb);
    const physBoxes = Math.ceil(accountingBoxes / mult);
    const pieces = isBoxes ? fo * qpb : fo;
    if (pi.unit_type === 'box') sum += physBoxes * pi.price;
    else if (pi.unit_type === 'thousand') sum += pieces * pi.price / 1000;
    else sum += pieces * pi.price;
  }
  return sum;
});

// ─── Фильтрация ───────────────────────────────────────────────────────────
const filteredIndices = computed(() => {
  const q = (props.filterQuery || '').trim().toLowerCase();
  if (!q) return orderStore.items.map((_, i) => i);
  return orderStore.items.reduce((acc, item, i) => {
    const haystack = `${item.sku || ''} ${item.name || ''}`.toLowerCase();
    if (haystack.includes(q)) acc.push(i);
    return acc;
  }, []);
});

// ─── Строка добавления товара ─────────────────────────────────────────────
const allSupplierProducts = ref([]);

const availableToAdd = computed(() => {
  const existingSkus = new Set(orderStore.items.map(i => i.sku).filter(Boolean));
  return allSupplierProducts.value.filter(p => !existingSkus.has(p.sku));
});

const showAddRow = computed(() => {
  if (!settings.value.supplier || orderStore.viewOnlyMode) return false;
  return availableToAdd.value.length > 0;
});

async function loadSupplierProducts() {
  const sup = settings.value.supplier;
  if (!sup) { allSupplierProducts.value = []; return; }
  try {
    let q = db.from('products').select('*').eq('supplier', sup);
    q = applyEntityFilter(q, settings.value.legalEntity);
    const { data } = await q;
    allSupplierProducts.value = data || [];
  } catch { allSupplierProducts.value = []; }
}

watch(() => settings.value.supplier, () => loadSupplierProducts(), { immediate: true });

function addProduct(e) {
  const sku = e.target.value;
  if (!sku) return;
  const product = allSupplierProducts.value.find(p => p.sku === sku);
  if (!product) return;
  orderStore.addItem(product);
  draftStore.save();
  e.target.value = '';
}
const colSpan = computed(() => {
  let c = 9; // базовые колонки (включая запас)
  if (settings.value.hasTransit) c++;
  if (props.cdaMode) c++;
  if (hasPrices.value) c++;
  return c;
});

function getCdaParams(item) {
  const aduInfo = props.aduMap.get(item.sku);
  return {
    adu: aduInfo?.adu || 0,
    cv: aduInfo?.cv || 0,
    dlt: props.cdaSupplierDlt || 1,
    doc: props.cdaSupplierDoc || 7,
    safetyCoef: props.cdaSafetyCoef ?? 1.0,
  };
}

const rowRefs = ref({});

function onNav({ row, col }) {
  if (row < 0 || row >= orderStore.items.length) return;
  const target = rowRefs.value[row];
  if (target) target.focusInput(col);
}

let draggedIndex = null;
let _savingOrder = false;
function onDragStart(index) { draggedIndex = index; }
function onDragOver(index) {}
async function onDrop(toIndex) {
  if (draggedIndex === null || draggedIndex === toIndex || _savingOrder) return;
  orderStore.moveItem(draggedIndex, toIndex);
  draggedIndex = null;
  _savingOrder = true;
  try {
    await orderStore.saveItemOrder();
    draftStore.save();
  } finally { _savingOrder = false; }
}
function onDragEnd() { draggedIndex = null; }

const avgConsumptionMap = ref({});

async function loadConsumptionHistory() {
  const legalEntity = settings.value.legalEntity;

  const skus = orderStore.items.filter(i => i.sku).map(i => i.sku);
  if (!skus.length) return;

  // Собираем все SKU: товары + применённые аналоги
  const allSkus = new Set(skus);
  for (const [, analogSet] of props.appliedAnalogs) {
    for (const aSku of analogSet) allSkus.add(aSku);
  }

  const { data, error } = await db
    .from('analysis_data')
    .select('sku, consumption, period_days')
    .eq('legal_entity', legalEntity)
    .in('sku', [...allSkus]);

  if (error || !data || !data.length) return;

  // Карта analysis_data по SKU
  const adMap = new Map();
  data.forEach(d => { if (d.sku) adMap.set(d.sku, d); });

  const qpbBySku = {};
  orderStore.items.forEach(item => {
    if (item.sku) qpbBySku[item.sku] = item.qtyPerBox || 1;
  });

  const currentUnit = settings.value.unit;
  const periodDays = settings.value.periodDays || 30;

  const map = {};
  orderStore.items.forEach(item => {
    if (!item.sku) return;
    const d = adMap.get(item.sku);
    if (!d) return;
    const consumption = parseFloat(d.consumption) || 0;
    const srcPeriod = d.period_days || 30;
    const daily = srcPeriod > 0 ? consumption / srcPeriod : 0;
    // Расход основного товара за период заказа (в штуках)
    let totalPcs = daily * periodDays;

    // Прибавляем расход применённых аналогов
    const appliedSet = props.appliedAnalogs.get(item.sku);
    if (appliedSet) {
      for (const aSku of appliedSet) {
        const ad = adMap.get(aSku);
        if (!ad) continue;
        const ac = parseFloat(ad.consumption) || 0;
        const ap = ad.period_days || 30;
        const aDaily = ap > 0 ? ac / ap : 0;
        totalPcs += aDaily * periodDays;
      }
    }

    if (totalPcs === 0) return;
    // Конвертируем из штук в текущую единицу
    const qpb = qpbBySku[item.sku] || 1;
    map[item.sku] = currentUnit === 'boxes' ? totalPcs / qpb : totalPcs;
  });
  avgConsumptionMap.value = map;
}

let _consumptionTimer = null;
watch(() => [settings.value.unit, settings.value.legalEntity, settings.value.periodDays, orderStore.items.length, orderStore.dataVersion, props.appliedAnalogs.size], () => {
  clearTimeout(_consumptionTimer);
  _consumptionTimer = setTimeout(() => loadConsumptionHistory(), 300);
}, { immediate: true });

onBeforeUnmount(() => { clearTimeout(_consumptionTimer); });

</script>
