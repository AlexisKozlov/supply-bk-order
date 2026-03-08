<template>
  <tr
    :class="{
      'has-order': item.finalOrder > 0,
      'shortage-warning': hasShortage,
      'dragging': isDragging,
      'item-hidden': item._hidden,
    }"
    :draggable="dragActive"
    @dragstart="onDragStart"
    @dragover.prevent="onDragOver"
    @drop.prevent="onDrop"
    @dragend="onDragEnd"
  >
    <!-- Drag handle -->
    <td class="td-drag" style="padding:4px;text-align:center;width:30px;">
      <span class="drag-handle" style="cursor:grab;user-select:none;color:#b0ada8;font-size:16px;"
        @mousedown="dragActive = true" @mouseup="dragActive = false">⋮⋮</span>
    </td>

    <!-- Наименование -->
    <td class="item-name" :title="compact ? metaTooltip : ''" :style="item.sku ? 'cursor:pointer' : ''" @dblclick="item.sku && $emit('edit-product', item.sku)">
      <b v-if="item.sku">{{ item.sku }}</b>{{ item.sku ? ' ' : '' }}{{ item.name }}
      <span v-if="item._hidden" class="hidden-badge">скрыта</span>
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
    <td data-label="Расход">
      <input
        type="number"
        :value="item.consumptionPeriod"
        :class="{ 'consumption-warning': consumptionWarning }"
        :title="consumptionWarning ? `⚠ Расход отличается от анализа запасов (${nf.format(Math.round(avgConsumption))}), проверьте данные` : ''"
        @focus="calcConsumption.onFocus"
        @blur="calcConsumption.onBlur"
        @keydown="(e) => handleCalcKeydown(e, 'consumptionPeriod', calcConsumption)"
        @change="(e) => updateField('consumptionPeriod', +e.target.value)"
        ref="inputConsumption"
        :data-col="0"
      />
      <div v-if="aduValue > 0 && !compact" class="adu-hint">ADU: {{ aduValue.toFixed(1) }}</div>
    </td>

    <!-- Остаток -->
    <td data-label="Остаток">
      <input
        type="number"
        :value="item.stock"
        @focus="calcStock.onFocus"
        @blur="calcStock.onBlur"
        @keydown="(e) => handleCalcKeydown(e, 'stock', calcStock)"
        @change="(e) => updateField('stock', +e.target.value)"
        ref="inputStock"
        :data-col="1"
      />
    </td>

    <!-- Транзит -->
    <td v-if="settings.hasTransit" class="transit-col" data-label="Транзит">
      <input
        type="number"
        :value="item.transit"
        @focus="calcTransit.onFocus"
        @blur="calcTransit.onBlur"
        @keydown="(e) => handleCalcKeydown(e, 'transit', calcTransit)"
        @change="(e) => updateField('transit', +e.target.value)"
        ref="inputTransit"
        :data-col="2"
      />
    </td>

    <!-- Хватит до (текущий запас) -->
    <td class="stock-col stock-display td-stock-until" :class="stockCurrentHighlight" data-label="Запас" :title="compact ? stockUntilDisplay : ''">{{ compact ? stockUntilShort : stockUntilDisplay }}</td>

    <!-- Буфер CDA (только в CDA-режиме) -->
    <td v-if="cdaMode" class="buffer-cell">
      <div v-if="calc.buffer" class="buffer-bar" :title="bufferTooltip">
        <div class="buffer-seg buffer-red" :style="{ width: bufferSegWidth('red') }"></div>
        <div class="buffer-seg buffer-yellow" :style="{ width: bufferSegWidth('yellow') }"></div>
        <div class="buffer-seg buffer-green" :style="{ width: bufferSegWidth('green') }"></div>
        <div class="buffer-marker" :style="{ left: bufferMarkerPos }" :title="'Остаток: ' + nf.format(Math.round((item.stock || 0) + (item.transit || 0)))"></div>
      </div>
      <div v-if="calc.buffer && !compact" class="buffer-text">{{ nf.format(Math.round(calc.buffer.total)) }}</div>
      <div v-else-if="!calc.buffer" class="buffer-text" style="color:#999">—</div>
    </td>

    <!-- Расчёт заказа -->
    <td class="calc">
      <div class="calc-value" @click="compact && applyCalc()" :title="compact ? 'Клик — применить в заказ' : ''">
        <span class="calc-wrap" v-if="hasTooltipData"
          @mouseenter="showCalcTip" @mouseleave="hideCalcTip" @mousemove="moveCalcTip">
          {{ calcDisplayText }}
        </span>
        <template v-else>{{ calcDisplayText }}</template>
      </div>
      <button class="btn small calc-to-order" style="margin-top:4px;font-size:11px;padding:4px 8px;" @click="applyCalc">
        → В заказ
      </button>
    </td>

    <!-- Заказ: штуки / коробки -->
    <td class="order-cell order-highlight td-order">
      <input
        type="number"
        class="order-pieces"
        :value="orderPieces"
        style="width:70px;"
        @focus="calcOrderPieces.onFocus"
        @blur="calcOrderPieces.onBlur"
        @keydown="(e) => handleOrderPiecesKeydown(e)"
        @change="(e) => onOrderPiecesChange(+e.target.value)"
        ref="inputOrderPieces"
        :data-col="3"
      />
      <span class="order-separator">/</span>
      <input
        type="number"
        class="order-boxes"
        :value="orderBoxes"
        style="width:70px;"
        @focus="calcOrderBoxes.onFocus"
        @blur="calcOrderBoxes.onBlur"
        @keydown="(e) => handleOrderBoxesKeydown(e)"
        @change="(e) => onOrderBoxesChange(+e.target.value)"
        ref="inputOrderBoxes"
        :data-col="4"
      />
      <button class="btn small calc-to-order mob-calc-btn" @click="applyCalc">→ Расчёт</button>
      <div v-if="orderInfoText && !compact" class="order-info-line">{{ orderInfoText }}</div>
    </td>

    <!-- Хватит до (после заказа) -->
    <td class="date td-coverage" :class="stockCoverageHighlight" data-label="Хватит до" :title="compact ? coverageDateDisplay : ''">{{ compact ? coverageDateShort : coverageDateDisplay }}</td>

    <!-- Паллеты -->
    <td class="pallets">
      <div class="pallet-info">{{ palletDisplay }}</div>
      <button class="btn small round-to-pallet" @click="roundToPallet">Округлить</button>
    </td>

    <!-- Сумма (цена × заказ) -->
    <td v-if="hasPrices" class="price-col" :title="priceTooltip">
      <span v-if="rowSum > 0" class="row-sum">{{ rowSumDisplay }}<span v-if="priceInfo?.currency === 'RUB'" class="currency-badge rub">₽</span></span>
      <span v-else-if="priceInfo" class="row-sum row-sum-zero">—</span>
      <span v-else class="row-sum row-sum-none"></span>
    </td>

    <!-- Удалить -->
    <td class="delete-cell">
      <button class="delete-item-x" title="Удалить" @click="$emit('remove', item.id)"><BkIcon name="close" size="xs"/></button>
    </td>
  </tr>
  <Teleport to="body">
    <div v-if="tipVisible && tooltipHtml" class="calc-tip-portal" :style="tipStyle" v-html="tooltipHtml"></div>
  </Teleport>
</template>

<script setup>
import { computed, ref } from 'vue';
import { calculateItem, calculateBufferItem } from '@/lib/calculations.js';
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
  aduValue: { type: Number, default: 0 },
  cdaMode: { type: Boolean, default: false },
  cdaParams: { type: Object, default: null },
  priceInfo: { type: Object, default: null },
  hasPrices: { type: Boolean, default: false },
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

const inputStock       = ref(null);
const inputTransit     = ref(null);
const inputOrderPieces = ref(null);
const inputOrderBoxes  = ref(null);

const tipVisible = ref(false);
const tipX = ref(0);
const tipY = ref(0);
const tipStyle = computed(() => ({
  position: 'fixed',
  left: tipX.value + 'px',
  top: tipY.value + 'px',
  zIndex: 99999,
  pointerEvents: 'none',
}));

const colRefs = computed(() => [
  inputConsumption.value,
  inputStock.value,
  inputTransit.value,
  inputOrderPieces.value,
  inputOrderBoxes.value,
]);

function showCalcTip(e) {
  tipVisible.value = true;
  placeTip(e.currentTarget);
}
function hideCalcTip() {
  tipVisible.value = false;
}
function moveCalcTip(e) {
  placeTip(e.currentTarget);
}
function placeTip(el) {
  const rect = el.getBoundingClientRect();
  let top = rect.bottom + 8;
  let left = rect.left + rect.width / 2 - 130;
  if (left < 8) left = 8;
  if (left + 280 > window.innerWidth) left = window.innerWidth - 288;
  if (top + 200 > window.innerHeight) top = rect.top - 208;
  tipX.value = left;
  tipY.value = top;
}

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
const calc = computed(() => {
  if (props.cdaMode && props.cdaParams) {
    return calculateBufferItem(props.item, props.settings, props.cdaParams);
  }
  return calculateItem(props.item, props.settings);
});

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
  // Учётные коробки
  const accountingBoxes = s.unit === 'boxes' ? order : Math.ceil(order / qpb.value);
  // Физические коробки
  const physBoxes = Math.ceil(accountingBoxes / mult.value);
  const pieces = s.unit === 'pieces' ? order : order * qpb.value;

  const safeUnit = escHtml(props.item.unitOfMeasure || 'шт');
  if (mult.value > 1) return `${accountingBoxes} уч.кор (${nf.format(pieces)} ${safeUnit})`;
  if (s.unit === 'pieces' && qpb.value > 1) return `${accountingBoxes} кор (${nf.format(order)} ${safeUnit})`;
  if (s.unit === 'boxes') return `${order} кор`;
  return String(order);
});

function escHtml(str) {
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

const hasTooltipData = computed(() => !!props.settings.deliveryDate && !!props.settings.today);

const tooltipHtml = computed(() => {
  const s = props.settings;
  if (!s.deliveryDate || !s.today) return '';
  const inputUnit = escHtml(s.unit === 'boxes' ? 'кор' : (props.item.unitOfMeasure || 'шт'));
  const fmt = (n) => nf.format(Math.round(n * 10) / 10);
  const daily = s.periodDays > 0 ? (props.item.consumptionPeriod || 0) / s.periodDays : 0;
  const transitDays = Math.ceil((s.deliveryDate - s.today) / 86400000);
  const consumed = daily * transitDays;
  const totalStock = (props.item.stock || 0) + (props.item.transit || 0);
  const stockAfter = totalStock - consumed;
  const deficitLine = stockAfter < 0
    ? `<div class="calc-tip-row" style="color:#D32F2F"><span class="calc-tip-lbl">Дефицит до прихода:</span><span class="calc-tip-val">${fmt(Math.abs(stockAfter))} ${inputUnit}</span></div>`
    : '';

  if (props.cdaMode && calc.value.buffer) {
    const b = calc.value.buffer;
    return `
      <div class="calc-tip-row"><span class="calc-tip-lbl">Суточный расход:</span><span class="calc-tip-val">${fmt(daily)} ${inputUnit}</span></div>
      <div class="calc-tip-row"><span class="calc-tip-lbl">Дней до прихода:</span><span class="calc-tip-val">${transitDays} дн.</span></div>
      <div class="calc-tip-row"><span class="calc-tip-lbl">Остаток к приходу:</span><span class="calc-tip-val">${stockAfter < 0 ? 0 : fmt(stockAfter)} ${inputUnit}</span></div>
      ${deficitLine}
      <hr class="calc-tip-hr">
      <div class="calc-tip-row" style="color:#4CAF50"><span class="calc-tip-lbl">Зелёная зона (DOC):</span><span class="calc-tip-val">${fmt(b.green)} ${inputUnit}</span></div>
      <div class="calc-tip-row" style="color:#FF9800"><span class="calc-tip-lbl">Жёлтая зона (CV):</span><span class="calc-tip-val">${fmt(b.yellow)} ${inputUnit}</span></div>
      <div class="calc-tip-row" style="color:#F44336"><span class="calc-tip-lbl">Красная зона (DLT):</span><span class="calc-tip-val">${fmt(b.red)} ${inputUnit}</span></div>
      <hr class="calc-tip-hr">
      <div class="calc-tip-row"><span class="calc-tip-lbl">Буфер:</span><span class="calc-tip-val">${fmt(b.total)} ${inputUnit}</span></div>
      <div class="calc-tip-row"><span class="calc-tip-lbl">Итого к заказу:</span><span class="calc-tip-val">${calcDisplayText.value}</span></div>
    `;
  }

  const need = daily * (s.safetyDays || 0);
  return `
    <div class="calc-tip-row"><span class="calc-tip-lbl">Суточный расход:</span><span class="calc-tip-val">${fmt(daily)} ${inputUnit}</span></div>
    <div class="calc-tip-row"><span class="calc-tip-lbl">Дней до прихода:</span><span class="calc-tip-val">${transitDays} дн.</span></div>
    <div class="calc-tip-row"><span class="calc-tip-lbl">Расход до прихода:</span><span class="calc-tip-val">${fmt(consumed)} ${inputUnit}</span></div>
    <div class="calc-tip-row"><span class="calc-tip-lbl">Остаток к приходу:</span><span class="calc-tip-val">${stockAfter < 0 ? 0 : fmt(stockAfter)} ${inputUnit}</span></div>
    ${deficitLine}
    <hr class="calc-tip-hr">
    <div class="calc-tip-row"><span class="calc-tip-lbl">Нужно после прихода (запас ${s.safetyDays || 0} дн.):</span><span class="calc-tip-val">${fmt(need)} ${inputUnit}</span></div>
    <div class="calc-tip-row"><span class="calc-tip-lbl">Итого к заказу:</span><span class="calc-tip-val">${calcDisplayText.value}</span></div>
  `;
});

// ─── Буфер CDA ────────────────────────────────────────────────────────────────
function bufferSegWidth(zone) {
  const b = calc.value.buffer;
  if (!b || b.total <= 0) return '0%';
  return Math.round((b[zone] / b.total) * 100) + '%';
}

const bufferMarkerPos = computed(() => {
  const b = calc.value.buffer;
  if (!b || b.total <= 0) return '0%';
  const stock = (props.item.stock || 0) + (props.item.transit || 0);
  const pct = Math.min(100, Math.max(0, (stock / b.total) * 100));
  return pct + '%';
});

const bufferTooltip = computed(() => {
  const b = calc.value.buffer;
  if (!b) return '';
  const inputUnit = props.settings.unit === 'boxes' ? 'кор' : (props.item.unitOfMeasure || 'шт');
  return `Зелёная: ${nf.format(Math.round(b.green))} ${inputUnit}\nЖёлтая: ${nf.format(Math.round(b.yellow))} ${inputUnit}\nКрасная: ${nf.format(Math.round(b.red))} ${inputUnit}\nИтого буфер: ${nf.format(Math.round(b.total))} ${inputUnit}`;
});

// ─── Заказ: синхронизация штуки ↔ коробки ────────────────────────────────────
// Левое поле: finalOrder напрямую (учётные коробки при boxes, штуки при pieces)
const orderPieces = computed(() => props.item.finalOrder || 0);

// Правое поле: физические коробки = finalOrder / mult (boxes) или finalOrder / (qpb * mult) (pieces)
const orderBoxes = computed(() => {
  const fo = props.item.finalOrder || 0;
  if (props.settings.unit === 'boxes') {
    return mult.value ? Math.ceil(fo / mult.value) : 0;
  }
  return physBoxSize.value ? Math.ceil(fo / physBoxSize.value) : 0;
});

function onOrderPiecesChange(value) {
  // Левое поле: finalOrder = value напрямую (учётные при boxes, штуки при pieces)
  orderStore.updateItemField(props.item.id, 'finalOrder', value);
  draftStore.save();
}

function onOrderBoxesChange(physBoxes) {
  let finalOrder;
  if (props.settings.unit === 'pieces') {
    if (!physBoxSize.value) return; // нет данных для пересчёта
    finalOrder = physBoxes * physBoxSize.value;
  } else {
    if (!mult.value) return; // нет данных для пересчёта
    finalOrder = physBoxes * mult.value;
  }
  orderStore.updateItemField(props.item.id, 'finalOrder', finalOrder);
  draftStore.save();
}

function handleOrderPiecesKeydown(e) {
  handleCalcKeydown(e, 'finalOrder', calcOrderPieces);
}

function handleOrderBoxesKeydown(e) {
  handleCalcKeydown(e, 'finalOrder', calcOrderBoxes);
}

// Информационная строка под полями заказа
const orderInfoText = computed(() => {
  const fo = props.item.finalOrder || 0;
  if (!fo) return '';
  if (props.settings.unit === 'boxes') {
    return `${nf.format(fo * qpb.value)} ${props.item.unitOfMeasure || 'шт'}`;
  }
  return `${Math.ceil(fo / qpb.value)} уч.кор`;
});

// ─── Применить расчёт → в заказ ──────────────────────────────────────────────
function applyCalc() {
  const order = calc.value.calculatedOrder;
  if (order == null || isNaN(order) || order < 0) return;
  orderStore.updateItemField(props.item.id, 'finalOrder', Math.round(order));
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
const stockUntilInfo = computed(() => {
  const s = props.settings;
  const stock = (props.item.stock || 0) + (props.item.transit || 0);
  const period = s.periodDays || 30;
  const daily = period > 0 ? (props.item.consumptionPeriod || 0) / period : 0;
  if (daily <= 0) return { days: Infinity, display: stock > 0 ? '∞ (расход=0)' : '0 дн.', short: stock > 0 ? '∞' : '0' };
  const days = Math.floor(stock / daily);
  const today = s.today instanceof Date ? s.today : new Date();
  const d = new Date(today);
  d.setDate(d.getDate() + days);
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const yy = String(d.getFullYear()).slice(-2);
  return { days, display: `${dd}.${mm}.${yy} (${days} дн.)`, short: `${days} дн.` };
});
const stockUntilDisplay = computed(() => stockUntilInfo.value.display);
const stockUntilShort = computed(() => stockUntilInfo.value.short);

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
  const days = stockUntilInfo.value.days;
  if (days === Infinity) return '';
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

// ─── Цена и сумма ────────────────────────────────────────────────────────────
const rowSum = computed(() => {
  if (!props.priceInfo || !props.item.finalOrder) return 0;
  const fo = props.item.finalOrder;
  const ut = props.priceInfo.unit_type;
  const isBoxes = props.settings.unit === 'boxes';
  // Привести к нужным единицам для расчёта
  const accountingBoxes = isBoxes ? fo : Math.ceil(fo / qpb.value);
  const physBoxes = Math.ceil(accountingBoxes / mult.value);
  const pieces = isBoxes ? fo * qpb.value : fo;
  if (ut === 'box') return physBoxes * props.priceInfo.price;
  if (ut === 'thousand') return pieces * props.priceInfo.price / 1000;
  return pieces * props.priceInfo.price;
});
const rowSumDisplay = computed(() => {
  if (!rowSum.value) return '';
  return rowSum.value.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
});
const priceTooltip = computed(() => {
  if (!props.priceInfo) return 'Цена не задана';
  const p = parseFloat(props.priceInfo.price);
  const units = { box: 'кор', piece: 'шт', thousand: 'тыс/шт', kg: 'кг', liter: 'л' };
  const unit = units[props.priceInfo.unit_type] || 'шт';
  const cur = props.priceInfo.currency || 'BYN';
  const curSign = cur === 'RUB' ? '₽' : 'BYN';
  if (cur === 'RUB') {
    const orig = parseFloat(props.priceInfo.origPrice);
    return `Цена: ${orig.toLocaleString('ru-RU', { minimumFractionDigits: 2 })} ₽ / ${unit} (≈ ${p.toLocaleString('ru-RU', { minimumFractionDigits: 2 })} BYN)`;
  }
  return `Цена: ${p.toLocaleString('ru-RU', { minimumFractionDigits: 2 })} ${curSign} / ${unit}`;
});

// ─── Дефицит до поставки (объединённый computed) ─────────────────────────────
const shortageInfo = computed(() => {
  const s = props.settings;
  if (!s.deliveryDate || !s.today || !props.item.consumptionPeriod) return null;
  const daily = s.periodDays > 0 ? props.item.consumptionPeriod / s.periodDays : 0;
  if (daily <= 0) return null;
  const totalStock = (props.item.stock || 0) + (props.item.transit || 0);
  const days = Math.ceil((s.deliveryDate - s.today) / 86400000);
  const consumed = daily * days;
  if (totalStock >= consumed) return null;
  const deficit = consumed - totalStock;
  const unit = props.item.unitOfMeasure || 'шт';
  let text;
  if (s.unit === 'boxes') text = `${Math.ceil(deficit)} кор.`;
  else if (qpb.value > 1) text = `${Math.ceil(deficit)} ${unit} (${Math.ceil(deficit / qpb.value)} кор.)`;
  else text = `${Math.ceil(deficit)} ${unit}`;
  return { text, days: Math.ceil(deficit / daily) };
});
const hasShortage = computed(() => !!shortageInfo.value);
const shortageText = computed(() => shortageInfo.value?.text || '');
const shortageDays = computed(() => shortageInfo.value?.days || 0);

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
