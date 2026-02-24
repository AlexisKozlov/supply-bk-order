<template>
  <tr
    :class="{
      'has-order': item.finalOrder > 0,
      'shortage-warning': hasShortage,
      'dragging': isDragging,
    }"
    :draggable="dragActive"
    @dragstart="onDragStart"
    @dragover.prevent="onDragOver"
    @drop.prevent="onDrop"
    @dragend="onDragEnd"
  >
    <!-- Drag handle -->
    <td style="padding:4px;text-align:center;width:30px;">
      <span class="drag-handle" style="cursor:grab;user-select:none;color:#b0ada8;font-size:16px;"
        @mousedown="dragActive = true" @mouseup="dragActive = false">⋮⋮</span>
    </td>

    <!-- Наименование -->
    <td class="item-name" :title="compact ? metaTooltip : ''" :style="item.sku ? 'cursor:pointer' : ''" @dblclick="item.sku && $emit('edit-product', item.sku)">
      <b v-if="item.sku">{{ item.sku }}</b>{{ item.sku ? ' ' : '' }}{{ item.name }}
      <div v-if="!compact" class="item-meta">
        {{ item.qtyPerBox ? item.qtyPerBox + ' ' + (item.unitOfMeasure || 'шт') + '/кор' : '' }}
        {{ item.boxesPerPallet ? ' · ' + item.boxesPerPallet + ' кор/пал' : '' }}
        {{ item.multiplicity && item.multiplicity > 1 ? ' · кратн.' + item.multiplicity : '' }}
      </div>
      <div v-if="hasShortage && !compact" class="shortage-info">
        <BkIcon name="warning" size="sm"/> Не хватит: {{ shortageText }} | Дефицит: {{ shortageDays }} дн.
      </div>
      <span v-if="hasShortage && compact" class="shortage-compact" :title="'Не хватит: ' + shortageText + ' | Дефицит: ' + shortageDays + ' дн.'">
        <BkIcon name="warning" size="sm"/>
      </span>
    </td>

    <!-- Расход -->
    <td>
      <input
        type="number"
        :value="item.consumptionPeriod"
        :class="{ 'consumption-warning': consumptionWarning }"
        :title="consumptionWarning ? `⚠ Расход отличается от среднего (${nf.format(Math.round(avgConsumption))}), проверьте данные` : ''"
        @focus="calcConsumption.onFocus"
        @keydown="(e) => handleCalcKeydown(e, 'consumptionPeriod', calcConsumption)"
        @change="(e) => updateField('consumptionPeriod', +e.target.value)"
        ref="inputConsumption"
        :data-col="0"
      />
    </td>

    <!-- Остаток -->
    <td>
      <input
        type="number"
        :value="item.stock"
        @focus="calcStock.onFocus"
        @keydown="(e) => handleCalcKeydown(e, 'stock', calcStock)"
        @change="(e) => updateField('stock', +e.target.value)"
        ref="inputStock"
        :data-col="1"
      />
    </td>

    <!-- Транзит -->
    <td v-if="settings.hasTransit" class="transit-col">
      <input
        type="number"
        :value="item.transit"
        @focus="calcTransit.onFocus"
        @keydown="(e) => handleCalcKeydown(e, 'transit', calcTransit)"
        @change="(e) => updateField('transit', +e.target.value)"
        ref="inputTransit"
        :data-col="2"
      />
    </td>

    <!-- Хватит до (текущий запас) -->
    <td v-if="settings.showStockColumn" class="stock-col stock-display" :class="stockCurrentHighlight" :title="compact ? stockUntilDisplay : ''">{{ compact ? stockUntilShort : stockUntilDisplay }}</td>

    <!-- Расчёт заказа -->
    <td class="calc">
      <div class="calc-value" @click="compact && applyCalc()" :title="compact ? 'Клик — применить в заказ' : ''">
        <span class="calc-wrap" v-if="tooltipHtml"
              @mouseenter="positionTooltip" @mouseleave="hideTooltip">
          {{ calcDisplayText }}
        </span>
        <template v-else>{{ calcDisplayText }}</template>
      </div>
      <Teleport to="body">
        <div class="calc-tip" ref="calcTipEl" v-html="tooltipHtml" v-if="tooltipHtml"></div>
      </Teleport>
      <button class="btn small calc-to-order" style="margin-top:4px;font-size:11px;padding:4px 8px;" @click="applyCalc">
        → В заказ
      </button>
    </td>

    <!-- Заказ: штуки / коробки -->
    <td class="order-cell order-highlight">
      <input
        type="number"
        class="order-pieces"
        :value="orderPieces"
        style="width:70px;"
        @focus="calcOrderPieces.onFocus"
        @keydown="(e) => handleOrderPiecesKeydown(e)"
        @change="(e) => onOrderPiecesChange(+e.target.value)"
        ref="inputOrderPieces"
        :data-col="3"
      />
      /
      <input
        type="number"
        class="order-boxes"
        :value="orderBoxes"
        style="width:70px;"
        @focus="calcOrderBoxes.onFocus"
        @keydown="(e) => handleOrderBoxesKeydown(e)"
        @change="(e) => onOrderBoxesChange(+e.target.value)"
        ref="inputOrderBoxes"
        :data-col="4"
      />
    </td>

    <!-- Хватит до (после заказа) -->
    <td class="date" :class="stockCoverageHighlight" :title="compact ? coverageDateDisplay : ''">{{ compact ? coverageDateShort : coverageDateDisplay }}</td>

    <!-- Паллеты -->
    <td class="pallets">
      <div class="pallet-info">{{ palletDisplay }}</div>
      <button class="btn small round-to-pallet" @click="roundToPallet">Округлить</button>
    </td>

    <!-- Удалить -->
    <td class="delete-cell">
      <button class="delete-item-x" title="Удалить" @click="$emit('remove', item.id)"><BkIcon name="close" size="xs"/></button>
    </td>
  </tr>
</template>

<script setup>
import { computed, ref } from 'vue';
import { calculateItem } from '@/lib/calculations.js';
import { getQpb, getMultiplicity } from '@/lib/utils.js';
import { useCalculator } from '@/lib/useCalculator.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useDraftStore } from '@/stores/draftStore.js';
import BkIcon from '@/components/ui/BkIcon.vue';


const props = defineProps({
  item: { type: Object, required: true },
  rowIndex: { type: Number, required: true },
  settings: { type: Object, required: true },
  compact: { type: Boolean, default: false },
  avgConsumption: { type: Number, default: 0 },
  dataValidation: { type: Boolean, default: false },
});

const emit = defineEmits(['remove', 'edit-product', 'drag-start', 'drag-over', 'drop', 'drag-end', 'nav']);

const orderStore = useOrderStore();
const draftStore = useDraftStore();

const nf = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });
const isDragging = ref(false);
const dragActive = ref(false);

// ─── Калькуляторы ─────────────────────────────────────────────────────────────
const calcConsumption = useCalculator((v) => updateField('consumptionPeriod', v));
const calcStock       = useCalculator((v) => updateField('stock', v));
const calcTransit     = useCalculator((v) => updateField('transit', v));
const calcOrderPieces = useCalculator((v) => onOrderPiecesChange(v));
const calcOrderBoxes  = useCalculator((v) => onOrderBoxesChange(v));

function handleCalcKeydown(e, field, calcInstance) {
  // Операторы и цифры всегда идут в калькулятор
  if (['+', '-', '*', '/'].includes(e.key) || (calcInstance.hasPendingOp() && /[0-9.]/.test(e.key))) {
    calcInstance.onKeydown(e);
    return;
  }
  // Enter при pending operation → завершить калькуляцию
  if (e.key === 'Enter' && calcInstance.hasPendingOp()) {
    calcInstance.onKeydown(e);
    return;
  }
  // Escape при pending → отмена
  if (e.key === 'Escape' && calcInstance.hasPendingOp()) {
    calcInstance.onKeydown(e);
    return;
  }
  // Остальные навигационные клавиши
  if (['Enter', 'ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
    handleExcelNav(e);
    return;
  }
  // Все остальные (цифры без pending) — калькулятор
  calcInstance.onKeydown(e);
}

// ─── Excel-навигация ──────────────────────────────────────────────────────────
const inputConsumption = ref(null);
const calcTipEl = ref(null);

function positionTooltip(e) {
  const tip = calcTipEl.value;
  if (!tip) return;
  const wrap = e.currentTarget;
  const rect = wrap.getBoundingClientRect();
  tip.style.display = 'block';
  tip.style.left = (rect.left + rect.width / 2 - tip.offsetWidth / 2) + 'px';
  tip.style.top = (rect.top - tip.offsetHeight - 8) + 'px';
  // Если уходит за верхний край — показать снизу
  if (rect.top - tip.offsetHeight - 8 < 0) {
    tip.style.top = (rect.bottom + 8) + 'px';
  }
}
function hideTooltip() {
  const tip = calcTipEl.value;
  if (tip) tip.style.display = 'none';
}
const inputStock       = ref(null);
const inputTransit     = ref(null);
const inputOrderPieces = ref(null);
const inputOrderBoxes  = ref(null);

const colRefs = computed(() => [
  inputConsumption.value,
  inputStock.value,
  inputTransit.value,
  inputOrderPieces.value,
  inputOrderBoxes.value,
]);

function handleExcelNav(e) {
  const col = parseInt(e.target.dataset.col ?? '-1');
  if (e.key === 'Enter' || e.key === 'ArrowDown') {
    e.preventDefault();
    emit('nav', { row: props.rowIndex + 1, col });
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    emit('nav', { row: props.rowIndex - 1, col });
  } else if (e.key === 'ArrowRight') {
    let atEnd = true;
    try { atEnd = e.target.selectionStart >= e.target.value.length; } catch(_) {}
    if (atEnd) { e.preventDefault(); focusCol(col + 1); }
  } else if (e.key === 'ArrowLeft') {
    let atStart = true;
    try { atStart = e.target.selectionStart === 0; } catch(_) {}
    if (atStart) { e.preventDefault(); focusCol(col - 1); }
  }
}

function focusCol(col) {
  if (col < 0 || col > 4) return;
  const el = colRefs.value[col];
  if (el) { el.focus(); el.select(); }
}

// Вызывается из OrderTable для фокуса по row+col
function focusInput(col) {
  focusCol(col);
}
defineExpose({ focusInput });

// ─── Обновление полей ─────────────────────────────────────────────────────────
function updateField(field, value) {
  orderStore.updateItemField(props.item.id, field, value);
  draftStore.save();
}

// ─── Расчёт ───────────────────────────────────────────────────────────────────
const calc = computed(() => calculateItem(props.item, props.settings));

const qpb  = computed(() => getQpb(props.item));
const mult = computed(() => getMultiplicity(props.item));
const physBoxSize = computed(() => qpb.value * mult.value);

// ─── Compact tooltips ─────────────────────────────────────────────────────────
const metaTooltip = computed(() => {
  const parts = [];
  if (props.item.qtyPerBox) parts.push(props.item.qtyPerBox + ' ' + (props.item.unitOfMeasure || 'шт') + '/кор');
  if (props.item.boxesPerPallet) parts.push(props.item.boxesPerPallet + ' кор/пал');
  if (props.item.multiplicity && props.item.multiplicity > 1) parts.push('кратн.' + props.item.multiplicity);
  return parts.join(' · ') || '';
});

// ─── Отображение расчёта ──────────────────────────────────────────────────────
const calcDisplayText = computed(() => {
  const order = Math.round(calc.value.calculatedOrder || 0);
  if (!order || order <= 0 || isNaN(order)) return '0';
  const s = props.settings;
  const physBoxes = s.unit === 'boxes'
    ? Math.ceil(order / mult.value)
    : Math.ceil(order / (qpb.value * mult.value));
  const pieces = s.unit === 'pieces' ? order : order * qpb.value;

  if (mult.value > 1) return `${physBoxes} кор (${nf.format(pieces)} ${props.item.unitOfMeasure || 'шт'})`;
  if (s.unit === 'pieces' && qpb.value > 1) return `${Math.ceil(order / qpb.value)} кор (${nf.format(order)} ${props.item.unitOfMeasure || 'шт'})`;
  if (s.unit === 'boxes') return `${order} кор`;
  return String(order);
});

const tooltipHtml = computed(() => {
  const s = props.settings;
  if (!s.deliveryDate || !s.today) return '';
  const inputUnit = s.unit === 'boxes' ? 'кор' : (props.item.unitOfMeasure || 'шт');
  const fmt = (n) => nf.format(Math.round(n * 10) / 10);
  const daily = s.periodDays > 0 ? (props.item.consumptionPeriod || 0) / s.periodDays : 0;
  const transitDays = Math.ceil((s.deliveryDate - s.today) / 86400000);
  const consumed = daily * transitDays;
  const totalStock = (props.item.stock || 0) + (props.item.transit || 0);
  const stockAfter = Math.max(0, totalStock - consumed);
  const need = daily * (s.safetyDays || 0);
  return `
    <div class="calc-tip-row"><span class="calc-tip-lbl">Суточный расход:</span><span class="calc-tip-val">${fmt(daily)} ${inputUnit}</span></div>
    <div class="calc-tip-row"><span class="calc-tip-lbl">Дней до прихода:</span><span class="calc-tip-val">${transitDays} дн.</span></div>
    <div class="calc-tip-row"><span class="calc-tip-lbl">Расход до прихода:</span><span class="calc-tip-val">${fmt(consumed)} ${inputUnit}</span></div>
    <div class="calc-tip-row"><span class="calc-tip-lbl">Остаток к приходу:</span><span class="calc-tip-val">${fmt(stockAfter)} ${inputUnit}</span></div>
    <hr class="calc-tip-hr">
    <div class="calc-tip-row"><span class="calc-tip-lbl">Нужно после прихода (запас ${s.safetyDays || 0} дн.):</span><span class="calc-tip-val">${fmt(need)} ${inputUnit}</span></div>
    <div class="calc-tip-row"><span class="calc-tip-lbl">Итого к заказу:</span><span class="calc-tip-val">${calcDisplayText.value}</span></div>
  `;
});

// ─── Заказ: синхронизация штуки ↔ коробки ────────────────────────────────────
const orderPieces = computed(() => {
  if (props.settings.unit === 'pieces') return props.item.finalOrder || 0;
  return (props.item.finalOrder || 0) * qpb.value;
});

const orderBoxes = computed(() => {
  const pieces = orderPieces.value;
  return physBoxSize.value ? Math.ceil(pieces / physBoxSize.value) : 0;
});

function onOrderPiecesChange(pieces) {
  const physBoxes = physBoxSize.value ? Math.ceil(pieces / physBoxSize.value) : 0;
  let finalOrder;
  if (props.settings.unit === 'pieces') {
    finalOrder = pieces;
  } else {
    finalOrder = qpb.value ? Math.round(pieces / qpb.value) : pieces;
  }
  orderStore.updateItemField(props.item.id, 'finalOrder', finalOrder);
  draftStore.save();
}

function onOrderBoxesChange(physBoxes) {
  const pieces = physBoxes * physBoxSize.value;
  let finalOrder;
  if (props.settings.unit === 'pieces') {
    finalOrder = pieces;
  } else {
    finalOrder = physBoxes * mult.value;
  }
  orderStore.updateItemField(props.item.id, 'finalOrder', finalOrder);
  draftStore.save();
}

function handleOrderPiecesKeydown(e) {
  if (['+', '-', '*', '/'].includes(e.key) || (calcOrderPieces.hasPendingOp() && /[0-9.]/.test(e.key))) {
    calcOrderPieces.onKeydown(e);
    return;
  }
  if (e.key === 'Enter' && calcOrderPieces.hasPendingOp()) {
    calcOrderPieces.onKeydown(e);
    return;
  }
  if (e.key === 'Escape' && calcOrderPieces.hasPendingOp()) {
    calcOrderPieces.onKeydown(e);
    return;
  }
  if (['Enter', 'ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
    handleExcelNav(e);
    return;
  }
  calcOrderPieces.onKeydown(e);
}

function handleOrderBoxesKeydown(e) {
  if (['+', '-', '*', '/'].includes(e.key) || (calcOrderBoxes.hasPendingOp() && /[0-9.]/.test(e.key))) {
    calcOrderBoxes.onKeydown(e);
    return;
  }
  if (e.key === 'Enter' && calcOrderBoxes.hasPendingOp()) {
    calcOrderBoxes.onKeydown(e);
    return;
  }
  if (e.key === 'Escape' && calcOrderBoxes.hasPendingOp()) {
    calcOrderBoxes.onKeydown(e);
    return;
  }
  if (['Enter', 'ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
    handleExcelNav(e);
    return;
  }
  calcOrderBoxes.onKeydown(e);
}

// ─── Применить расчёт → в заказ ──────────────────────────────────────────────
function applyCalc() {
  orderStore.updateItemField(props.item.id, 'finalOrder', calc.value.calculatedOrder);
  orderStore.updateItemField(props.item.id, '_manualOrder', false);
  draftStore.save();
}

// ─── Округлить до паллеты ────────────────────────────────────────────────────
function roundToPallet() {
  if (!props.item.boxesPerPallet) return;
  const current = props.item.finalOrder || 0;
  const s = props.settings;
  const physBoxes = s.unit === 'boxes'
    ? Math.ceil(current / mult.value)
    : Math.ceil(current / (qpb.value * mult.value));
  const rounded = Math.ceil(physBoxes / props.item.boxesPerPallet) * props.item.boxesPerPallet;
  const newFinalOrder = s.unit === 'boxes'
    ? rounded * mult.value
    : rounded * qpb.value * mult.value;
  orderStore.updateItemField(props.item.id, 'finalOrder', newFinalOrder);
  draftStore.save();
}

// ─── Отображение "хватит до" (текущий запас) ─────────────────────────────────
const stockUntilDisplay = computed(() => {
  const s = props.settings;
  const stock = (props.item.stock || 0) + (props.item.transit || 0);
  const period = s.periodDays || 30;
  const daily = period > 0 ? (props.item.consumptionPeriod || 0) / period : 0;
  if (daily <= 0) return stock > 0 ? '∞ (расход=0)' : '0 дн.';
  const days = Math.floor(stock / daily);
  const today = s.today instanceof Date ? s.today : new Date();
  const d = new Date(today);
  d.setDate(d.getDate() + days);
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const yy = String(d.getFullYear()).slice(-2);
  return `${dd}.${mm}.${yy} (${days} дн.)`;
});

// Compact: only days
const stockUntilShort = computed(() => {
  const s = props.settings;
  const stock = (props.item.stock || 0) + (props.item.transit || 0);
  const period = s.periodDays || 30;
  const daily = period > 0 ? (props.item.consumptionPeriod || 0) / period : 0;
  if (daily <= 0) return stock > 0 ? '∞' : '0';
  const days = Math.floor(stock / daily);
  return `${days} дн.`;
});

// ─── Отображение "хватит до" (после заказа) ──────────────────────────────────
const coverageDateDisplay = computed(() => {
  const { coverageDate } = calc.value;
  if (!coverageDate || !props.settings.deliveryDate) return '-';
  try {
    const daysDiff = Math.ceil((coverageDate - props.settings.deliveryDate) / 86400000);
    const dd = String(coverageDate.getDate()).padStart(2, '0');
    const mm = String(coverageDate.getMonth() + 1).padStart(2, '0');
    const yy = String(coverageDate.getFullYear()).slice(-2);
    return `${dd}.${mm}.${yy} (${daysDiff} дн.)`;
  } catch (_) { return '-'; }
});

// Compact: only days
const coverageDateShort = computed(() => {
  const { coverageDate } = calc.value;
  if (!coverageDate || !props.settings.deliveryDate) return '-';
  try {
    const daysDiff = Math.ceil((coverageDate - props.settings.deliveryDate) / 86400000);
    return `${daysDiff} дн.`;
  } catch (_) { return '-'; }
});

// ─── Подсветка запаса: мягкий цвет текста + точка ────────────────────────────
const stockCurrentHighlight = computed(() => {
  if (!props.item.consumptionPeriod || props.item.consumptionPeriod <= 0) return '';
  const s = props.settings;
  const stock = (props.item.stock || 0) + (props.item.transit || 0);
  const period = s.periodDays || 30;
  const daily = period > 0 ? props.item.consumptionPeriod / period : 0;
  if (daily <= 0) return '';
  const days = Math.floor(stock / daily);
  if (days < 7) return 'stock-low';
  if (days > 30) return 'stock-high';
  return '';
});

const stockCoverageHighlight = computed(() => {
  if (!props.item.consumptionPeriod || props.item.consumptionPeriod <= 0) return '';
  const { coverageDate } = calc.value;
  if (!coverageDate || !props.settings.deliveryDate) return '';
  const daysDiff = Math.ceil((coverageDate - props.settings.deliveryDate) / 86400000);
  if (daysDiff < 7) return 'stock-low';
  if (daysDiff > 30) return 'stock-high';
  return '';
});

// ─── Паллеты ─────────────────────────────────────────────────────────────────
const palletDisplay = computed(() => {
  const p = calc.value.palletsInfo;
  if (!p) return '-';
  return `${p.pallets} пал. + ${p.boxesLeft} кор.`;
});

// ─── Дефицит до поставки ────────────────────────────────────────────────────
const hasShortage = computed(() => {
  const s = props.settings;
  if (!s.deliveryDate || !s.today || !props.item.consumptionPeriod) return false;
  const daily = s.periodDays > 0 ? props.item.consumptionPeriod / s.periodDays : 0;
  if (daily <= 0) return false;
  const totalStock = (props.item.stock || 0) + (props.item.transit || 0);
  const days = Math.ceil((s.deliveryDate - s.today) / 86400000);
  return totalStock < daily * days;
});

const shortageText = computed(() => {
  if (!hasShortage.value) return '';
  const s = props.settings;
  const daily = s.periodDays > 0 ? props.item.consumptionPeriod / s.periodDays : 0;
  const days = Math.ceil((s.deliveryDate - s.today) / 86400000);
  const totalStock = (props.item.stock || 0) + (props.item.transit || 0);
  const deficit = daily * days - totalStock;
  const unit = props.item.unitOfMeasure || 'шт';
  if (s.unit === 'boxes') return `${Math.ceil(deficit)} кор.`;
  if (qpb.value > 1) return `${Math.ceil(deficit)} ${unit} (${Math.ceil(deficit / qpb.value)} кор.)`;
  return `${Math.ceil(deficit)} ${unit}`;
});

const shortageDays = computed(() => {
  if (!hasShortage.value) return 0;
  const s = props.settings;
  const daily = s.periodDays > 0 ? props.item.consumptionPeriod / s.periodDays : 0;
  if (daily <= 0) return 0;
  const totalStock = (props.item.stock || 0) + (props.item.transit || 0);
  const days = Math.ceil((s.deliveryDate - s.today) / 86400000);
  return Math.ceil((daily * days - totalStock) / daily);
});

// ─── Проверка расхода (аномалия) ─────────────────────────────────────────────
const consumptionWarning = computed(() => {
  if (!props.dataValidation || !props.avgConsumption || !props.item.consumptionPeriod) return false;
  const deviation = Math.abs(props.item.consumptionPeriod - props.avgConsumption) / props.avgConsumption;
  return deviation > 0.30;
});

// ─── Drag & Drop ──────────────────────────────────────────────────────────────
function onDragStart(e) {
  isDragging.value = true;
  emit('drag-start', props.rowIndex);
  e.dataTransfer.effectAllowed = 'move';
}

function onDragOver(e) {
  e.dataTransfer.dropEffect = 'move';
  emit('drag-over', props.rowIndex);
}

function onDrop() {
  emit('drop', props.rowIndex);
}

function onDragEnd() {
  isDragging.value = false;
  dragActive.value = false;
  emit('drag-end');
}
</script>
