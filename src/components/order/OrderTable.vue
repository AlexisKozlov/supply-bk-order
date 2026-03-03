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
          <th>Расчёт<br><small>заказа</small></th>
          <th>
            Заказ<br>
            <small>{{ settings.unit === 'boxes' ? 'уч.кор / физ.кор' : 'шт / физ.кор' }}</small>
          </th>
          <th>Хватит до<br><small>(после поставки)</small></th>
          <th>Паллеты</th>
          <th style="width:32px;"></th>
        </tr>
        <tr v-else>
          <th style="width:4px;"></th>
          <th>Товар</th>
          <th>Расход</th>
          <th>Остаток</th>
          <th v-if="settings.hasTransit" class="transit-col">Транзит</th>
          <th class="stock-col">Запас</th>
          <th>Расчёт</th>
          <th>{{ settings.unit === 'boxes' ? 'Уч/Физ' : 'Шт/Физ' }}</th>
          <th>До</th>
          <th>Пал.</th>
          <th style="width:24px;"></th>
        </tr>
      </thead>
      <tbody>
        <tr v-if="!orderStore.items.length" class="order-empty-row">
          <td :colspan="colSpan" class="order-empty-cell">
            <div class="order-empty-hint">
              <span class="order-empty-icon"><BkIcon name="order" size="sm"/></span>
              <span class="order-empty-text" v-if="!settings.supplier">Выберите поставщика или добавьте товары через поиск</span>
              <span class="order-empty-text" v-else>Товары загружены. Заполните параметры и данные заказа</span>
            </div>
          </td>
        </tr>
        <OrderRow
          v-for="(item, index) in orderStore.items"
          :key="item.id"
          :item="item"
          :row-index="index"
          :settings="settings"
          :compact="compact"
          :avg-consumption="avgConsumptionMap[item.sku] || 0"
          :data-validation="true"
          :ref="el => { if (el) rowRefs[index] = el; }"
          @remove="orderStore.removeItem($event); draftStore.save();"
          @edit-product="$emit('edit-product', $event)"
          @drag-start="onDragStart"
          @drag-over="onDragOver"
          @drop="onDrop"
          @drag-end="onDragEnd"
          @nav="onNav"
        />
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
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useOrderStore } from '@/stores/orderStore.js';
import { useDraftStore } from '@/stores/draftStore.js';
import { db } from '@/lib/apiClient.js';
import { getQpb, getMultiplicity } from '@/lib/utils.js';
import OrderRow from './OrderRow.vue';
import BkIcon from '@/components/ui/BkIcon.vue';


const props = defineProps({
  compact: { type: Boolean, default: false },
  appliedAnalogs: { type: Map, default: () => new Map() },
});

const emit = defineEmits(['edit-product']);

const orderStore = useOrderStore();
const draftStore = useDraftStore();
const settings = computed(() => orderStore.settings);
const colSpan = computed(() => {
  let c = 9; // базовые колонки (включая запас)
  if (settings.value.hasTransit) c++;
  return c;
});

const rowRefs = ref([]);

function onNav({ row, col }) {
  if (row < 0 || row >= orderStore.items.length) return;
  const target = rowRefs.value[row];
  if (target) target.focusInput(col);
}

let draggedIndex = null;
function onDragStart(index) { draggedIndex = index; }
function onDragOver(index) {}
async function onDrop(toIndex) {
  if (draggedIndex === null || draggedIndex === toIndex) return;
  orderStore.moveItem(draggedIndex, toIndex);
  draggedIndex = null;
  await orderStore.saveItemOrder();
  draftStore.save();
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

watch(() => [settings.value.unit, settings.value.legalEntity, settings.value.periodDays, orderStore.items.length, orderStore.dataVersion, props.appliedAnalogs.size], () => {
  loadConsumptionHistory();
}, { immediate: true });
</script>
