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
          <th v-if="settings.showStockColumn" class="stock-col">Запас</th>
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
          <th v-if="settings.showStockColumn" class="stock-col">Запас</th>
          <th>Расчёт</th>
          <th>{{ settings.unit === 'boxes' ? 'Кор/Физ' : 'Шт/Кор' }}</th>
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
          :data-validation="dataValidationEnabled"
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
  dataValidationEnabled: { type: Boolean, default: false },
  compact: { type: Boolean, default: false },
});

const emit = defineEmits(['edit-product']);

const orderStore = useOrderStore();
const draftStore = useDraftStore();
const settings = computed(() => orderStore.settings);
const colSpan = computed(() => {
  let c = 8; // базовые колонки
  if (settings.value.hasTransit) c++;
  if (settings.value.showStockColumn) c++;
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
  const supplier = settings.value.supplier;
  const legalEntity = settings.value.legalEntity;
  if (!supplier || !props.dataValidationEnabled) return;

  const { data, error } = await db
    .from('orders')
    .select('*, order_items(sku, consumption_period)')
    .eq('legal_entity', legalEntity)
    .eq('supplier', supplier)
    .order('created_at', { ascending: false })
    .limit(2);

  if (error || !data || !data.length) return;

  // qtyPerBox берём из текущих загруженных товаров (из карточки products),
  // НЕ из исторических order_items — там может быть записана кратность
  const qpbBySku = {};
  orderStore.items.forEach(item => {
    if (item.sku) qpbBySku[item.sku] = item.qtyPerBox || 1;
  });

  const bySku = {};
  const currentUnit = settings.value.unit;

  data.forEach(order => {
    const orderUnit = order.unit || 'pieces';
    const periodDays = order.period_days || 30;
    (order.order_items || []).forEach(item => {
      if (!item.sku) return;
      const cp = parseFloat(item.consumption_period) || 0;
      if (cp === 0) return;
      const daily = cp / periodDays;
      let monthly = daily * 30;
      // Конвертация pieces↔boxes через учётный qtyPerBox из карточки товара
      // (без кратности multiplicity!)
      const qpb = qpbBySku[item.sku] || 1;
      if (orderUnit === 'pieces' && currentUnit === 'boxes') monthly = monthly / qpb;
      else if (orderUnit === 'boxes' && currentUnit === 'pieces') monthly = monthly * qpb;
      if (!bySku[item.sku]) bySku[item.sku] = [];
      bySku[item.sku].push(monthly);
    });
  });

  const map = {};
  Object.entries(bySku).forEach(([sku, vals]) => {
    map[sku] = vals.reduce((a, b) => a + b, 0) / vals.length;
  });
  avgConsumptionMap.value = map;
}

watch(() => [settings.value.supplier, settings.value.unit, settings.value.legalEntity, props.dataValidationEnabled, orderStore.items.length, orderStore.dataVersion], () => {
  if (props.dataValidationEnabled) loadConsumptionHistory();
}, { immediate: true });
</script>
