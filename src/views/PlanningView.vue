<template>
  <div class="planning-view" :class="{ 'fullscreen-table': isFullscreen }">
    <div class="page-header">
      <h1 class="page-title">Планирование</h1>
      <span v-if="viewOnly" class="editing-badge" style="cursor:pointer;background:#E3F2FD;color:#1565C0;border-color:#90CAF9;" @click="resetPlan"><BkIcon name="eye" size="sm"/> Просмотр</span>
      <span v-else-if="editingPlanId" class="editing-badge" style="cursor:pointer" @click="resetPlan"><BkIcon name="edit" size="sm"/> Редактирование</span>
    </div>

    <!-- Параметры: кликабельная строка-сводка + раскрывающаяся панель -->
    <div class="params-block" :class="{ open: settingsExpanded }">
      <div class="params-summary" @click="toggleSettings">
        <BkIcon name="gear" size="sm" class="params-icon"/>
        <span class="ps-chip"><b>{{ supplier || 'Не выбран' }}</b></span>
        <span class="ps-chip">{{ periodLabel }}</span>
        <span class="ps-chip">с {{ startDateDisplay }}</span>
        <span class="ps-chip">{{ inputUnit === 'boxes' ? 'коробки' : 'штуки' }}</span>
        <span class="ps-chip">расход/{{ consumptionPeriodDays }}дн</span>
        <span class="params-toggle-hint">
          <BkIcon :name="settingsExpanded ? 'chevronUp' : 'chevronDown'" size="xs"/>
        </span>
      </div>
      <div v-if="settingsExpanded" class="params-fields">
        <div class="pf-group">
          <label>Поставщик</label>
          <select v-model="supplier" @change="loadProducts" :disabled="suppLoading || viewOnly">
            <option value="">— Выберите —</option>
            <option v-for="s in suppliers" :key="s.short_name" :value="s.short_name">{{ s.short_name }}</option>
          </select>
        </div>
        <div class="pf-group">
          <label>Период</label>
          <select v-model="periodValue" @change="onPeriodChange" :disabled="viewOnly">
            <option value="w1">1 неделя</option><option value="w2">2 недели</option><option value="w4">4 недели</option>
            <option value="w6">6 недель</option><option value="w8">8 недель</option><option value="w12">12 недель</option>
            <option value="m1">1 месяц</option><option value="m2">2 месяца</option><option value="m3">3 месяца</option>
          </select>
        </div>
        <div class="pf-group">
          <label>Дата начала</label>
          <input type="date" v-model="startDateStr" @change="onParamsChange" :disabled="viewOnly"/>
        </div>
        <div class="pf-group">
          <label>Единицы</label>
          <select :value="inputUnit" @change="onUnitChange">
            <option value="pieces">Штуки</option>
            <option value="boxes">Коробки</option>
          </select>
        </div>
        <div class="pf-group">
          <label>Период расхода</label>
          <select v-model.number="consumptionPeriodDays" @change="onConsumptionPeriodChange" :disabled="viewOnly">
            <option :value="7">7 дней</option><option :value="14">14 дней</option><option :value="21">21 день</option>
            <option :value="30">30 дней</option>
          </select>
        </div>
        <div v-if="showCollapseHint" class="params-collapse-hint" @click="settingsExpanded = false; showCollapseHint = false;">
          Параметры заполнены — нажмите чтобы свернуть ▲
        </div>
      </div>
    </div>

    <!-- Тулбар: действия -->
    <div class="order-toolbar" v-if="items.length">
      <div class="order-actions">
        <button class="btn small" :disabled="!canUndo || viewOnly" @click="undo" title="Отменить"><BkIcon name="undo" size="sm"/></button>
        <button class="btn small" :disabled="!canRedo || viewOnly" @click="redo" title="Повторить"><BkIcon name="redo" size="sm"/></button>
        <button class="btn small fullscreen-toggle-btn" @click="isFullscreen = !isFullscreen"><BkIcon :name="isFullscreen ? 'close' : 'eye'" size="sm"/> {{ isFullscreen ? 'Свернуть' : 'Развернуть' }}</button>
        <button class="compact-toggle" :class="{ active: compactPlan }" @click="toggleCompactPlan" title="Компактный режим"><BkIcon name="menu" size="sm"/> Компакт</button>
        <button class="btn small" @click="fillConsumption" :disabled="viewOnly" title="Загрузить расход"><BkIcon name="history" size="sm"/> Загрузить расход</button>
        <button class="btn small" @click="loadFrom1c" :disabled="load1cLoading || viewOnly" title="Загрузить из 1С"><BkIcon v-if="load1cLoading" name="loading" size="sm"/><BkIcon v-else name="oneC" size="sm"/> 1С</button>
        <button class="btn small" @click="doImport" :disabled="viewOnly" title="Импорт из файла"><BkIcon name="import" size="sm"/> Импорт</button>
        <button class="btn small danger" @click="clearAll" :disabled="viewOnly" title="Очистить данные">Очистить</button>
      </div>
    </div>

    <!-- Таблица -->
    <div v-if="!supplier" style="text-align:center;padding:40px;color:var(--text-muted);">Выберите поставщика</div>
    <div v-else-if="suppLoading" style="text-align:center;padding:40px;"><BurgerSpinner text="Загрузка..." /></div>
    <div v-else-if="!items.length" style="text-align:center;padding:40px;color:var(--text-muted);">Нет товаров у «{{ supplier }}»</div>
    <div v-else class="order-table-wrapper" :class="{ 'plan-compact': compactPlan }">
      <table class="order-table plan-table">
        <thead>
          <tr>
            <th class="plan-th-name">Товар</th>
            <th>{{ compactPlan ? 'Расх.' : consumptionColumnLabel }} ({{ unitLabel }})</th>
            <th>{{ compactPlan ? 'Склад' : 'Склад' }} ({{ unitLabel }})</th>
            <th>{{ compactPlan ? 'Пост.' : 'У постав.' }} ({{ unitLabel }})</th>
            <th class="plan-th-reserve">Запас<br v-if="!compactPlan"><small v-if="!compactPlan" style="font-weight:400;opacity:0.7;">дней</small></th>
            <th v-for="h in periodHeaders" :key="h.label" class="plan-th-month" :title="compactPlan ? h.label + ' ' + h.sublabel : ''">
              {{ h.label }}<br v-if="!compactPlan"><small v-if="!compactPlan" style="font-weight:400;opacity:0.7;">{{ h.sublabel }}</small>
            </th>
            <th class="plan-th-total">Итого</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(item, idx) in items" :key="item.sku || idx" :class="{ 'has-order': itemHasOrder(item) }">
            <td class="plan-td-name" style="text-align:left;" :title="compactPlan ? planMetaTooltip(item) : ''" @dblclick="openProductEdit(item)">
              <div style="font-weight:600;color:var(--text);" :style="compactPlan ? 'font-size:12px' : 'font-size:13px'">
                <b v-if="item.sku" style="color:var(--bk-orange);margin-right:4px;">{{ item.sku }}</b>{{ item.name }}
              </div>
              <div v-if="!compactPlan" style="font-size:11px;color:var(--text-muted);font-weight:500;">{{ item.qtyPerBox }} {{ item.unitOfMeasure || 'шт' }}/кор{{ item.boxesPerPallet ? ' · ' + item.boxesPerPallet + ' кор/пал' : '' }}{{ item.multiplicity > 1 ? ' · кратн.' + item.multiplicity : '' }}</div>
            </td>
            <td class="plan-td-input"><input type="number" class="plan-calc-input" :value="item.monthlyConsumption || ''" :class="{ 'consumption-warning': item._cw }" :title="item._ct || ''" @change="e => onInput(idx, 'consumption', e.target.value)" @focus="e => onCalcFocus(e, idx, 'consumption')" @keydown="e => onCalcKeydown(e, idx, 'consumption')" :disabled="viewOnly" placeholder="0"/></td>
            <td class="plan-td-input"><input type="number" class="plan-calc-input" :value="displayStock(item, 'stockOnHand')" @change="e => onInput(idx, 'stock', e.target.value)" @focus="e => onCalcFocus(e, idx, 'stock')" @keydown="e => onCalcKeydown(e, idx, 'stock')" :disabled="viewOnly" placeholder="0"/></td>
            <td class="plan-td-input"><input type="number" class="plan-calc-input" :value="displayStock(item, 'stockAtSupplier')" @change="e => onInput(idx, 'supplierStock', e.target.value)" @focus="e => onCalcFocus(e, idx, 'supplierStock')" @keydown="e => onCalcKeydown(e, idx, 'supplierStock')" :disabled="viewOnly" placeholder="0"/></td>
            <td class="plan-td-reserve" :class="reserveDaysClass(item)">{{ reserveDaysText(item) }}</td>
            <!-- Период 0 — readonly -->
            <td v-if="item.plan.length" class="plan-td-result" :class="{ 'plan-has-value': item.plan[0]?.orderBoxes > 0 }" :title="compactPlan && item.plan[0]?.orderBoxes > 0 ? nf.format(item.plan[0].orderUnits) + ' ' + item.unitOfMeasure : ''">
              <template v-if="item.plan[0]?.orderBoxes > 0">
                <span class="plan-result-value">{{ item.plan[0].orderBoxes }} кор</span>
                <span v-if="!compactPlan" class="plan-result-sub">{{ nf.format(item.plan[0].orderUnits) }} {{ item.unitOfMeasure }}</span>
              </template>
              <span v-else class="plan-result-zero">—</span>
            </td>
            <!-- Периоды 1+ — dblclick -->
            <td v-for="m in periodHeaders.length - 1" :key="m" class="plan-td-result"
              :class="{ 'plan-has-value': item.plan[m]?.orderBoxes > 0, 'plan-cell-locked': item.plan[m]?.locked }"
              :title="compactPlan && item.plan[m]?.orderBoxes > 0 ? nf.format(item.plan[m].orderUnits) + ' ' + item.unitOfMeasure : ''"
              @dblclick="startEdit(idx, m, $event)">
              <template v-if="!editingCell || editingCell.idx !== idx || editingCell.m !== m">
                <template v-if="item.plan[m]?.orderBoxes > 0">
                  <span class="plan-result-value" :class="{ 'plan-cell-locked': item.plan[m]?.locked }">
                    {{ item.plan[m].orderBoxes }} кор
                    <span v-if="!viewOnly && item.boxesPerPallet && item.plan[m].orderBoxes % item.boxesPerPallet !== 0" class="plan-pallet-period"
                      :title="`До ${Math.ceil(item.plan[m].orderBoxes / item.boxesPerPallet)} пал (${Math.ceil(item.plan[m].orderBoxes / item.boxesPerPallet) * item.boxesPerPallet} кор)`"
                      @click.stop="roundToPallet(idx, m)">⬆</span>
                    <span v-if="!viewOnly && item.plan[m]?.locked" class="plan-reset-cell" title="Сбросить" @click.stop="resetCell(idx, m)"><BkIcon name="close" size="sm"/></span>
                  </span>
                  <span v-if="!compactPlan" class="plan-result-sub">{{ nf.format(item.plan[m].orderUnits) }} {{ item.unitOfMeasure }}</span>
                </template>
                <span v-else class="plan-result-zero">—</span>
              </template>
              <!-- Inline edit input (#6 fix) -->
              <input v-else type="number" class="plan-edit-input" :value="item.plan[m]?.orderBoxes || 0"
                @keydown.enter.prevent="applyEdit(idx, m, $event.target.value)"
                @keydown.escape.prevent="cancelEdit()"
                @blur="applyEdit(idx, m, $event.target.value)"
                ref="editInputRef"
                style="width:60px;text-align:center;font-size:13px;font-weight:700;padding:2px 4px;border:2px solid var(--bk-orange);border-radius:4px;"/>
            </td>
            <td class="plan-td-total" :class="{ 'plan-has-value': itemTotalBoxes(item) > 0 }">
              <template v-if="itemTotalBoxes(item) > 0">
                <span class="plan-total-boxes">{{ nf.format(itemTotalBoxes(item)) }} кор</span>
                <span class="plan-total-units">{{ nf.format(itemTotalUnits(item)) }} {{ item.unitOfMeasure || 'шт' }}</span>
              </template>
              <span v-else class="plan-result-zero">—</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Кнопки завершения -->
    <div v-if="items.length" class="toolbar-row toolbar-finish" style="margin-top:12px;" v-show="!isFullscreen">
      <div class="toolbar-spacer"></div>
      <button class="btn primary" @click="savePlan" :disabled="!itemsWithPlan.length || viewOnly"><BkIcon name="save" size="sm"/> {{ editingPlanId ? 'Обновить план' : 'Сохранить план' }}</button>
      <button class="btn" @click="copyPlanToClipboard" :disabled="!itemsWithPlan.length"><BkIcon name="history" size="sm"/> Копировать</button>
      <button class="btn" @click="exportExcel" :disabled="!itemsWithPlan.length"><BkIcon name="excel" size="sm"/> Excel</button>
    </div>

    <!-- Модалки -->
    <EditCardModal v-if="editCardModal.show" :product="editCardModal.product" :legal-entity="orderStore.settings.legalEntity" @close="editCardModal.show = false" @saved="onCardSaved"/>
    <ConfirmModal v-if="confirmModal.show" :title="confirmModal.title" :message="confirmModal.message"
      @confirm="confirmModal.resolve(true); confirmModal.show = false"
      @cancel="confirmModal.resolve(false); confirmModal.show = false"/>

    <!-- Модалка сохранения плана -->
    <Teleport to="body">
      <div v-if="showSaveModal" class="modal" @click.self="showSaveModal = false">
        <div class="modal-box" style="max-width:420px;">
          <div class="modal-header">
            <h2><BkIcon name="save" size="sm"/> {{ editingPlanId ? 'Обновить план' : 'Сохранить план' }}</h2>
            <button class="modal-close" @click="showSaveModal = false"><BkIcon name="close" size="sm"/></button>
          </div>
          <div style="margin-bottom:16px;color:#555;font-size:14px;">
            <div>Поставщик: <b>{{ supplier }}</b></div>
            <div>Позиций с заказом: <b>{{ itemsWithPlan.length }}</b></div>
            <div>Период: <b>{{ periodCount }} {{ periodType === 'weeks' ? 'нед.' : 'мес.' }}</b></div>
            <div>Расход за: <b>{{ consumptionPeriodDays }} дн.</b></div>
          </div>
          <div v-if="editingPlanId" style="margin-bottom:12px;padding:8px 12px;background:#FFF3E0;border-radius:6px;font-size:13px;color:#E65100;">
            <BkIcon name="warning" size="sm"/> Существующий план будет перезаписан
          </div>
          <label style="display:block;margin-bottom:8px;font-size:13px;font-weight:600;color:#555;">Примечание (необязательно)</label>
          <input v-model="saveNote" type="text" placeholder="Например: согласовано с поставщиком..." style="width:100%;margin-bottom:16px;" ref="saveNoteInput" @keydown.enter="confirmSave" @keydown.esc="showSaveModal = false"/>
          <div class="actions" style="display:flex;gap:8px;">
            <button class="btn primary" @click="confirmSave" :disabled="saving">{{ saving ? 'Сохранение...' : (editingPlanId ? 'Обновить план' : 'Сохранить план') }}</button>
            <button class="btn secondary" @click="showSaveModal = false" :disabled="saving">Отмена</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue';
import { useRoute } from 'vue-router';
import { db } from '@/lib/apiClient.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useSupplierStore } from '@/stores/supplierStore.js';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import { useToastStore } from '@/stores/toastStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { useDraftStore } from '@/stores/draftStore.js';
import { getQpb, getMultiplicity, copyToClipboard, getEntityGroup, toLocalDateStr } from '@/lib/utils.js';
import { importFromFile } from '@/lib/importStock.js';
import { useCalculator } from '@/lib/useCalculator.js';
import EditCardModal from '@/components/modals/EditCardModal.vue';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';
import BkIcon from '@/components/ui/BkIcon.vue';


const route = useRoute();
const orderStore = useOrderStore();
const supplierStore = useSupplierStore();
const toast = useToastStore();
const userStore = useUserStore();
const draftStore = useDraftStore();

const nf = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });

const supplier = ref('');
const periodValue = ref('m3');
const startDateStr = ref(toLocalDateStr(new Date()));
const inputUnit = ref('pieces');
const consumptionPeriodDays = ref(30);
const items = ref([]);
const suppLoading = ref(false);
const settingsExpanded = ref(false);
const load1cLoading = ref(false);
const editingPlanId = ref(null);
const viewOnly = ref(false);
const confirmModal = ref({ show: false, title: '', message: '', resolve: null });
const editCardModal = ref({ show: false, product: null });
const editingCell = ref(null);
const showSaveModal = ref(false);
const saveNote = ref('');
const saveNoteInput = ref(null);
const saving = ref(false); // { idx, m } for inline edit (#6)
const isFullscreen = ref(false);
const compactPlan = ref(localStorage.getItem('bk_compact_plan') === '1');
let _prevPlanItems = null;
let _loadedNote = '';
let _consumptionCache = null;

// ─── Calculator for plan inputs (#3) ──────────────────────────────────────
let _activeCalcIdx = null;
let _activeCalcField = null;
const planCalc = useCalculator((val) => {
  if (_activeCalcIdx !== null && _activeCalcField) {
    applyCalcResult(_activeCalcIdx, _activeCalcField, val);
  }
});

function applyCalcResult(idx, field, val) {
  const item = items.value[idx]; if (!item) return;
  const qpb = getQpb(item);
  if (field === 'consumption') { item.monthlyConsumption = val; triggerValidation(); }
  else if (field === 'stock') { item.stockOnHand = inputUnit.value === 'boxes' ? val * qpb : val; }
  else if (field === 'supplierStock') { item.stockAtSupplier = inputUnit.value === 'boxes' ? val * qpb : val; }
  recalcItem(idx, 0); _savePlanDraft();
}

function onCalcFocus(e, idx, field) {
  _activeCalcIdx = idx; _activeCalcField = field;
  planCalc.onFocus(e);
}

function onCalcKeydown(e, idx, field) {
  _activeCalcIdx = idx; _activeCalcField = field;
  // If calculator has pending op, let it handle operator keys, digits, Enter, Escape
  if (planCalc.hasPendingOp()) {
    if (['+', '-', '*', '/'].includes(e.key) || /[0-9.]/.test(e.key) || e.key === 'Enter' || e.key === 'Escape') {
      planCalc.onKeydown(e);
      return;
    }
  } else if (['+', '-', '*', '/'].includes(e.key)) {
    planCalc.onKeydown(e);
    return;
  }
  // Arrow nav between plan inputs
  if (['ArrowDown', 'ArrowUp'].includes(e.key)) {
    e.preventDefault();
    const colMap = { consumption: 0, stock: 1, supplierStock: 2 };
    const col = colMap[field] ?? 0;
    const dir = e.key === 'ArrowDown' ? 1 : -1;
    const newIdx = idx + dir;
    if (newIdx >= 0 && newIdx < items.value.length) {
      const row = e.target.closest('tr')?.parentElement;
      const targetRow = row?.children[newIdx];
      const inputs = targetRow?.querySelectorAll('.plan-calc-input');
      if (inputs?.[col]) { inputs[col].focus(); inputs[col].select(); }
    }
  }
  if (e.key === 'Enter') {
    e.preventDefault();
    // Apply current value and move down
    onInput(idx, field, e.target.value);
    const colMap = { consumption: 0, stock: 1, supplierStock: 2 };
    const col = colMap[field] ?? 0;
    const newIdx = idx + 1;
    if (newIdx < items.value.length) {
      nextTick(() => {
        const tbody = document.querySelector('.plan-table tbody');
        const targetRow = tbody?.children[newIdx];
        const inputs = targetRow?.querySelectorAll('.plan-calc-input');
        if (inputs?.[col]) { inputs[col].focus(); inputs[col].select(); }
      });
    }
  }
}

// ─── Undo/Redo (#1) ──────────────────────────────────────────────────────
const undoStack = ref([]);
const redoStack = ref([]);
const canUndo = computed(() => undoStack.value.length > 0);
const canRedo = computed(() => redoStack.value.length > 0);

function snapshot() {
  undoStack.value.push(JSON.stringify(items.value.map(i => ({ ...i, plan: [...i.plan] }))));
  if (undoStack.value.length > 30) undoStack.value.shift();
  redoStack.value = [];
}
function undo() {
  if (!undoStack.value.length) return;
  redoStack.value.push(JSON.stringify(items.value.map(i => ({ ...i, plan: [...i.plan] }))));
  const data = JSON.parse(undoStack.value.pop());
  items.value = data;
  recalcAll();
}
function redo() {
  if (!redoStack.value.length) return;
  undoStack.value.push(JSON.stringify(items.value.map(i => ({ ...i, plan: [...i.plan] }))));
  const data = JSON.parse(redoStack.value.pop());
  items.value = data;
  recalcAll();
}

const suppliers = computed(() => supplierStore.getSuppliersForEntity(orderStore.settings.legalEntity));
const unitLabel = computed(() => inputUnit.value === 'boxes' ? 'кор' : 'шт');
const periodLabel = computed(() => {
  const map = { w1:'1 нед', w2:'2 нед', w4:'4 нед', w6:'6 нед', w8:'8 нед', w12:'12 нед', m1:'1 мес', m2:'2 мес', m3:'3 мес' };
  return map[periodValue.value] || periodValue.value;
});
const startDateDisplay = computed(() => {
  const d = new Date(startDateStr.value);
  return !isNaN(d) ? d.toLocaleDateString('ru-RU', { day:'2-digit', month:'2-digit' }) : '—';
});
function toggleSettings() {
  if (settingsExpanded.value && !supplier.value) return; // не закрывать без поставщика
  settingsExpanded.value = !settingsExpanded.value;
}

const consumptionColumnLabel = computed(() => {
  const d = consumptionPeriodDays.value;
  if (d === 30) return 'Расход/мес';
  return `Расход/${d}дн`;
});
const periodType = computed(() => periodValue.value.startsWith('w') ? 'weeks' : 'months');
const periodCount = computed(() => parseInt(periodValue.value.slice(1)));
const startDate = computed(() => new Date(startDateStr.value));

const periodHeaders = computed(() => {
  const headers = []; const start = startDate.value;
  const fmt = (d) => `${String(d.getDate()).padStart(2,'0')}.${String(d.getMonth()+1).padStart(2,'0')}`;
  const mn = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
  if (periodType.value === 'weeks') {
    const dow = start.getDay(); const dlw = dow === 0 ? 0 : 7 - dow;
    const fwe = new Date(start); fwe.setDate(fwe.getDate() + Math.max(dlw - 1, 0));
    headers.push({ label: 'Тек. нед', sublabel: `${fmt(start)}–${fmt(fwe)}`, ratio: Math.max(dlw, 1) / 7 });
    for (let i = 0; i < periodCount.value; i++) {
      const ws = new Date(fwe); ws.setDate(ws.getDate() + 1 + i * 7);
      const we = new Date(ws); we.setDate(we.getDate() + 6);
      headers.push({ label: `Нед ${i+1}`, sublabel: `${fmt(ws)}–${fmt(we)}`, ratio: 1 });
    }
  } else {
    const dim = new Date(start.getFullYear(), start.getMonth() + 1, 0).getDate();
    const dl = dim - start.getDate() + 1;
    headers.push({ label: mn[start.getMonth()], sublabel: `ост. ${dl} дн.`, ratio: dl / dim });
    for (let i = 1; i <= periodCount.value; i++) {
      const d = new Date(start.getFullYear(), start.getMonth() + i, 1);
      headers.push({ label: mn[d.getMonth()], sublabel: String(d.getFullYear()), ratio: 1 });
    }
  }
  return headers;
});

function toggleCompactPlan() {
  compactPlan.value = !compactPlan.value;
  localStorage.setItem('bk_compact_plan', compactPlan.value ? '1' : '0');
}

function planMetaTooltip(item) {
  const parts = [];
  if (item.qtyPerBox) parts.push(item.qtyPerBox + ' ' + (item.unitOfMeasure || 'шт') + '/кор');
  if (item.boxesPerPallet) parts.push(item.boxesPerPallet + ' кор/пал');
  if (item.multiplicity > 1) parts.push('кратн.' + item.multiplicity);
  return parts.join(' · ') || '';
}

function displayStock(item, field) {
  const val = item[field]; if (!val) return '';
  return inputUnit.value === 'boxes' ? Math.ceil(val / getQpb(item)) : val;
}

function onInput(idx, type, rawValue) {
  snapshot();
  let value = 0; const raw = rawValue.trim();
  if (/^[\d\s+\-*/().]+$/.test(raw) && raw) { try { value = new Function('return ' + raw)(); } catch { value = parseFloat(raw) || 0; } if (!isFinite(value)) value = 0; value = Math.round(value * 100) / 100; }
  const item = items.value[idx]; const qpb = getQpb(item);
  if (type === 'consumption') { item.monthlyConsumption = value; triggerValidation(); }
  else if (type === 'stock') { item.stockOnHand = inputUnit.value === 'boxes' ? value * qpb : value; }
  else if (type === 'supplierStock') { item.stockAtSupplier = inputUnit.value === 'boxes' ? value * qpb : value; }
  recalcItem(idx, 0); _savePlanDraft();
}

function recalcItem(idx, fromMonth = 0) {
  const item = items.value[idx]; const headers = periodHeaders.value;
  if (!item.plan.length || item.plan.length !== headers.length) {
    const old = item.plan || [];
    item.plan = headers.map((_, m) => { const o = old[m]; if (o && o.locked) return { ...o, month: m }; return { month: m, need: 0, deficit: 0, orderBoxes: 0, orderUnits: 0, locked: false }; });
  }
  const qpb = getQpb(item); const mult = getMultiplicity(item); const pbu = qpb * mult;
  const toBase = (v) => inputUnit.value === 'boxes' ? v * qpb : v;
  const periodDays = consumptionPeriodDays.value || 30;
  const daily = toBase(item.monthlyConsumption) / periodDays;
  const mu = daily * 30; const wu = daily * 7;
  let co = item.stockOnHand + item.stockAtSupplier;
  for (let m = 0; m < fromMonth && m < headers.length; m++) { co = co - (periodType.value === 'weeks' ? wu : mu) * headers[m].ratio + (item.plan[m].orderUnits || 0); if (co < 0) co = 0; }
  for (let m = fromMonth; m < headers.length; m++) {
    const need = (periodType.value === 'weeks' ? wu : mu) * headers[m].ratio;
    const deficit = need - Math.min(co, need);
    if (item.plan[m].locked) { item.plan[m].need = Math.round(need); item.plan[m].deficit = Math.round(deficit); item.plan[m].orderUnits = item.plan[m].orderBoxes * pbu; co = co - need + item.plan[m].orderUnits; }
    else { let ob = 0, ou = 0; if (deficit > 0 && pbu > 0) { ob = Math.ceil(deficit / pbu); ou = ob * pbu; } item.plan[m] = { month: m, need: Math.round(need), deficit: Math.round(deficit), orderBoxes: ob, orderUnits: ou, locked: false }; co = co - need + ou; }
    if (co < 0) co = 0;
  }
}
function recalcAll() { items.value.forEach((_, i) => recalcItem(i, 0)); }

// ─── Inline edit (#6 fix) ─────────────────────────────────────────────────
function startEdit(idx, m, event) {
  if (viewOnly.value) return;
  snapshot();
  editingCell.value = { idx, m };
  nextTick(() => {
    const td = event.currentTarget;
    const inp = td?.querySelector('.plan-edit-input');
    if (inp) { inp.focus(); inp.select(); }
  });
}
function applyEdit(idx, m, val) {
  const newVal = parseInt(val) || 0;
  const item = items.value[idx]; const p = item.plan[m]; if (!p) { editingCell.value = null; return; }
  p.orderBoxes = newVal; p.orderUnits = newVal * getQpb(item) * getMultiplicity(item); p.locked = true;
  editingCell.value = null;
  recalcItem(idx, m + 1); _savePlanDraft();
}
function cancelEdit() { editingCell.value = null; }

function roundToPallet(idx, m) {
  snapshot();
  const item = items.value[idx]; const p = item.plan[m]; if (!p || !item.boxesPerPallet) return;
  p.orderBoxes = Math.ceil(p.orderBoxes / item.boxesPerPallet) * item.boxesPerPallet;
  p.orderUnits = p.orderBoxes * getQpb(item) * getMultiplicity(item); p.locked = true;
  recalcItem(idx, m + 1); _savePlanDraft();
}
function resetCell(idx, m) { snapshot(); items.value[idx].plan[m].locked = false; recalcItem(idx, m); _savePlanDraft(); }

function reserveDays(item) {
  const qpb = getQpb(item);
  const toBase = (v) => inputUnit.value === 'boxes' ? v * qpb : v;
  const periodDays = consumptionPeriodDays.value || 30;
  const daily = toBase(item.monthlyConsumption) / periodDays;
  if (!daily || daily <= 0) return null;
  const totalStock = (item.stockOnHand || 0) + (item.stockAtSupplier || 0);
  if (totalStock <= 0) return 0;
  return Math.round(totalStock / daily);
}
function reserveDaysText(item) {
  const d = reserveDays(item);
  if (d === null) return '—';
  return d;
}
function reserveDaysClass(item) {
  const d = reserveDays(item);
  if (d === null) return '';
  if (d <= 3) return 'reserve-danger';
  if (d <= 7) return 'reserve-warning';
  return 'reserve-ok';
}

function itemHasOrder(item) { return item.plan.some(p => p.orderBoxes > 0); }
function itemTotalBoxes(item) { return item.plan.reduce((s, p) => s + (p.orderBoxes || 0), 0); }
function itemTotalUnits(item) { return item.plan.reduce((s, p) => s + (p.orderUnits || 0), 0); }
function periodTotalBoxes(m) { return items.value.reduce((s, i) => s + (i.plan[m]?.orderBoxes || 0), 0); }
const itemsWithPlan = computed(() => items.value.filter(i => i.plan.some(p => p.orderBoxes > 0)));

// ─── Unit change (#7 fix — clear cache before converting) ─────────────────
function onUnitChange(e) {
  const newUnit = e.target.value;
  if (newUnit === inputUnit.value) return;
  if (!items.value.length) { inputUnit.value = newUnit; return; }
  const oldUnit = inputUnit.value;
  snapshot();
  items.value.forEach(item => {
    const qpb = getQpb(item);
    if (oldUnit === 'pieces' && newUnit === 'boxes') { item.monthlyConsumption = item.monthlyConsumption ? Math.round(item.monthlyConsumption / qpb * 100) / 100 : 0; }
    else if (oldUnit === 'boxes' && newUnit === 'pieces') { item.monthlyConsumption = Math.round(item.monthlyConsumption * qpb); }
    item.plan.forEach(p => { p.locked = false; });
  });
  inputUnit.value = newUnit;
  _consumptionCache = null; // (#7) сбрасываем кэш ПЕРЕД validation
  recalcAll(); triggerValidation(); _savePlanDraft();
  toast.info('Единицы обновлены', `Пересчитано в ${newUnit === 'boxes' ? 'коробки' : 'штуки'}`);
}

// ─── Validation (#7 fix — uses inputUnit.value which is already updated) ──
let _vTimer = null;
function triggerValidation() { clearTimeout(_vTimer); _vTimer = setTimeout(runValidation, 300); }
async function runValidation() {
  if (!supplier.value || !items.value.length) return;
  const avgMap = await loadAvgConsumption();
  if (!avgMap.size) return;
  items.value.forEach(item => {
    if (!item.sku || !item.monthlyConsumption) { item._cw = false; item._ct = ''; return; }
    const avg = avgMap.get(item.sku);
    if (!avg) { item._cw = false; item._ct = ''; return; }
    const dev = Math.abs(item.monthlyConsumption - avg) / avg;
    if (dev > 0.30) { item._cw = true; item._ct = `⚠️ Отклонение от среднего (${nf.format(Math.round(avg))})`; }
    else { item._cw = false; item._ct = ''; }
  });
}
async function loadAvgConsumption() {
  if (_consumptionCache && _consumptionCache.supplier === supplier.value && _consumptionCache.unit === inputUnit.value && _consumptionCache.periodDays === consumptionPeriodDays.value) return _consumptionCache.data;
  const { data, error } = await db.from('orders').select('*, order_items(sku, consumption_period, qty_per_box)')
    .eq('legal_entity', orderStore.settings.legalEntity).eq('supplier', supplier.value).order('created_at', { ascending: false }).limit(2);
  const avgMap = new Map();
  const targetPeriod = consumptionPeriodDays.value || 30;
  if (error || !data?.length) { _consumptionCache = { supplier: supplier.value, unit: inputUnit.value, periodDays: targetPeriod, data: avgMap }; return avgMap; }
  const bySku = {};
  data.forEach(order => {
    const oUnit = order.unit || 'pieces'; const pd = order.period_days || 30;
    (order.order_items || []).forEach(oi => {
      if (!oi.sku || !oi.consumption_period) return;
      let mv = (oi.consumption_period / pd) * targetPeriod;
      const eqpb = items.value.find(i => i.sku === oi.sku)?.qtyPerBox || oi.qty_per_box || 1;
      if (oUnit === 'pieces' && inputUnit.value === 'boxes') mv /= eqpb;
      else if (oUnit === 'boxes' && inputUnit.value === 'pieces') mv *= eqpb;
      if (!bySku[oi.sku]) bySku[oi.sku] = [];
      bySku[oi.sku].push(mv);
    });
  });
  Object.entries(bySku).forEach(([sku, vals]) => { avgMap.set(sku, vals.reduce((a, b) => a + b, 0) / vals.length); });
  _consumptionCache = { supplier: supplier.value, unit: inputUnit.value, periodDays: targetPeriod, data: avgMap };
  return avgMap;
}

// ─── Загрузить расход ─────────────────────────────────────────────────────
async function fillConsumption() {
  if (!items.value.length || !supplier.value) return;
  snapshot(); _consumptionCache = null;
  const avgMap = await loadAvgConsumption();
  if (!avgMap.size) { toast.info('Нет истории', 'Не найдены заказы'); return; }
  let f = 0;
  items.value.forEach(item => { if (item.sku) { const avg = avgMap.get(item.sku); if (avg > 0) { item.monthlyConsumption = Math.round(avg); f++; } } });
  recalcAll(); triggerValidation(); _savePlanDraft();
  toast.success('Расход подставлен', `${f} из ${items.value.length} позиций`);
}

// ─── 1С (#1) ──────────────────────────────────────────────────────────────
async function loadFrom1c() {
  if (!items.value.length) return;
  const skus = items.value.map(i => i.sku).filter(Boolean);
  if (!skus.length) { toast.error('Нет артикулов', ''); return; }
  snapshot(); load1cLoading.value = true;
  try {
    const { data, error } = await db.from('stock_1c').select('sku, stock, consumption, period_days, updated_at')
      .eq('legal_entity', orderStore.settings.legalEntity).in('sku', skus);
    if (error) { toast.error('Ошибка', ''); return; }
    if (!data?.length) { toast.info('Нет данных', ''); return; }
    const stockMap = new Map(data.map(d => [d.sku, d]));
    let f = 0;
    items.value.forEach(item => {
      const d = item.sku ? stockMap.get(item.sku) : null; if (!d) return;
      const qpb = getQpb(item);
      // stock_1c всегда в штуках → stockOnHand хранится в штуках
      item.stockOnHand = Math.round(d.stock || 0);
      // consumption → расход за выбранный период в текущих единицах
      const dailyC = (d.period_days || 30) > 0 ? (d.consumption || 0) / (d.period_days || 30) : 0;
      const periodConsumption = dailyC * (consumptionPeriodDays.value || 30);
      item.monthlyConsumption = inputUnit.value === 'boxes' ? Math.round(periodConsumption / qpb * 100) / 100 : Math.round(periodConsumption);
      f++;
    });
    recalcAll(); triggerValidation(); _savePlanDraft();
    toast.success('Из 1С загружено', `${f} из ${items.value.length} позиций`);
  } catch { toast.error('Ошибка', 'stock_1c не найдена'); }
  finally { load1cLoading.value = false; }
}

// ─── Очистить / Импорт ───────────────────────────────────────────────────
async function clearAll() {
  const ok = await new Promise(r => { confirmModal.value = { show: true, title: 'Обнулить данные?', message: 'Расход, остатки и расчёты будут сброшены.', resolve: r }; });
  if (!ok) return;
  snapshot();
  items.value.forEach(i => { i.monthlyConsumption = 0; i.stockOnHand = 0; i.stockAtSupplier = 0; i.plan = []; });
  recalcAll(); _savePlanDraft(); toast.success('Обнулено', '');
}
async function doImport() {
  const result = await importFromFile('planning', items.value, orderStore.settings.legalEntity);
  if (!result) return;
  if (result.error) { toast.error('Ошибка', result.error); return; }
  if (result.matched === 0) { toast.info('0 совпадений', ''); return; }
  snapshot();
  result.items.forEach((u, idx) => {
    const item = items.value[idx]; if (!item) return;
    if (u.stockOnHand !== undefined) item.stockOnHand = inputUnit.value === 'boxes' ? u.stockOnHand * getQpb(item) : u.stockOnHand;
    if (u.stockAtSupplier !== undefined) item.stockAtSupplier = inputUnit.value === 'boxes' ? u.stockAtSupplier * getQpb(item) : u.stockAtSupplier;
    if (u.monthlyConsumption !== undefined) item.monthlyConsumption = u.monthlyConsumption;
  });
  recalcAll(); triggerValidation(); _savePlanDraft();
  toast.success('Импорт', `${result.matched} обновлены`);
}

// ─── Edit product card (#2) ───────────────────────────────────────────────
async function openProductEdit(item) {
  if (!item.sku && !item.name) return;
  // Need product.id for EditCardModal to load full data
  let productId = item.productId;
  if (!productId && item.sku) {
    const { data } = await db.from('products').select('id').eq('sku', item.sku).limit(1).single();
    if (data) productId = data.id;
  }
  if (!productId) { toast.info('Карточка не найдена', ''); return; }
  editCardModal.value = { show: true, product: { id: productId, sku: item.sku, name: item.name } };
}
async function onCardSaved() {
  const product = editCardModal.value.product; editCardModal.value.show = false;
  supplierStore.invalidate();
  supplierStore.loadSuppliers(orderStore.settings.legalEntity);
  if (!product?.sku) return;
  try {
    const { data } = await db.from('products').select('*').eq('sku', product.sku).single();
    if (!data) return;
    const item = items.value.find(i => i.sku === product.sku);
    if (item) {
      item.name = data.name || item.name; item.qtyPerBox = data.qty_per_box || item.qtyPerBox;
      item.boxesPerPallet = data.boxes_per_pallet || item.boxesPerPallet;
      item.multiplicity = data.multiplicity || item.multiplicity;
      item.unitOfMeasure = data.unit_of_measure || item.unitOfMeasure;
      recalcAll(); _savePlanDraft();
    }
  } catch (e) { console.error(e); }
}

async function exportExcel() {
  if (!itemsWithPlan.value.length) { toast.error('Нет позиций', 'Нет позиций с заказом для экспорта'); return; }
  const XLSX = await import('xlsx');
  const headers = periodHeaders.value;
  const le = orderStore.settings.legalEntity;

  const info = [
    [`План: ${supplier.value}`],
    [`Юр. лицо: ${le}`],
    [`Период: ${periodCount.value} ${periodType.value === 'weeks' ? 'нед.' : 'мес.'} с ${startDateStr.value}`],
    [],
  ];

  const colHeaders = ['Артикул', 'Наименование'];
  headers.forEach(h => colHeaders.push(h.label));

  const rows = itemsWithPlan.value.map(item => {
    const row = [item.sku || '', item.name || ''];
    item.plan.forEach(p => {
      if (p.orderBoxes > 0) {
        row.push(`${p.orderBoxes} кор (${nf.format(p.orderUnits)} ${item.unitOfMeasure || 'шт'})`);
      } else {
        row.push('');
      }
    });
    return row;
  });

  const totalRow = ['', 'ИТОГО'];
  headers.forEach((_, m) => {
    const t = periodTotalBoxes(m);
    totalRow.push(t > 0 ? `${nf.format(t)} кор` : '');
  });

  const allRows = [...info, colHeaders, ...rows, totalRow];
  const ws = XLSX.utils.aoa_to_sheet(allRows);

  ws['!cols'] = [
    { wch: 12 }, { wch: 40 },
    ...headers.map(() => ({ wch: 22 })),
  ];

  const totalCols = 2 + headers.length;
  ws['!merges'] = [
    { s: { r: 0, c: 0 }, e: { r: 0, c: totalCols - 1 } },
    { s: { r: 1, c: 0 }, e: { r: 1, c: totalCols - 1 } },
    { s: { r: 2, c: 0 }, e: { r: 2, c: totalCols - 1 } },
  ];

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'План');

  const fileDate = startDateStr.value.replace(/-/g, '');
  XLSX.writeFile(wb, `План_${supplier.value}_${fileDate}.xlsx`);
  toast.success('Экспорт', 'Файл Excel сохранён');
}

function _savePlanDraft() {
  draftStore.savePlan({ supplier: supplier.value, periodValue: periodValue.value, startDateStr: startDateStr.value, inputUnit: inputUnit.value, consumptionPeriodDays: consumptionPeriodDays.value, items: items.value, viewOnly: viewOnly.value, editingPlanId: editingPlanId.value });
}

// ─── Загрузка товаров (#3 — порядок из item_order) ────────────────────────
async function loadProducts() {
  if (!supplier.value) { items.value = []; return; }
  suppLoading.value = true;
  try {
    const { data, error } = await db.from('products').select('*').eq('supplier', supplier.value).order('name');
    if (error) { toast.error('Ошибка', ''); return; }
    const group = getEntityGroup(orderStore.settings.legalEntity);
    items.value = (data || []).filter(p => group.includes(p.legal_entity)).map(p => ({
      productId: p.id, sku: p.sku || '', name: p.name, qtyPerBox: p.qty_per_box || 1,
      boxesPerPallet: p.boxes_per_pallet || null, unitOfMeasure: p.unit_of_measure || 'шт',
      multiplicity: p.multiplicity || 1, monthlyConsumption: 0,
      stockOnHand: 0, stockAtSupplier: 0, plan: [], _cw: false, _ct: '',
    }));
    // (#3) Применяем порядок товаров из item_order (как в заказе)
    await restoreItemOrder();
    editingPlanId.value = null; viewOnly.value = false; _prevPlanItems = null;
    undoStack.value = []; redoStack.value = [];
    recalcAll(); triggerValidation(); _savePlanDraft();
  } finally { suppLoading.value = false; }
}

async function restoreItemOrder() {
  const le = orderStore.settings.legalEntity;
  const sup = supplier.value || 'all';
  const { data } = await db.from('item_order').select('*').eq('supplier', sup).eq('legal_entity', le).order('position');
  if (!data?.length) return;
  const posMap = {}; data.forEach(r => { posMap[r.item_id] = r.position; });
  items.value.sort((a, b) => (posMap[a.productId] ?? 9999) - (posMap[b.productId] ?? 9999));
}

function onParamsChange() { supplierStore.loadSuppliers(orderStore.settings.legalEntity); recalcAll(); _savePlanDraft(); }
function onPeriodChange() { items.value.forEach(i => { i.plan = []; }); recalcAll(); }
function onConsumptionPeriodChange() {
  _consumptionCache = null;
  items.value.forEach(i => { i.plan = []; });
  recalcAll(); triggerValidation(); _savePlanDraft();
}

// ─── Сохранение ────────────────────────────────────────────────────────────
async function savePlan() {
  if (!itemsWithPlan.value.length) { toast.error('Нет данных', ''); return; }
  saveNote.value = editingPlanId.value ? _loadedNote : '';
  showSaveModal.value = true;
  nextTick(() => setTimeout(() => saveNoteInput.value?.focus(), 50));
}

async function confirmSave() {
  saving.value = true;
  try {
  const planData = {
    legal_entity: orderStore.settings.legalEntity, supplier: supplier.value,
    period_type: periodType.value, period_count: periodCount.value, start_date: startDateStr.value,
    consumption_period_days: consumptionPeriodDays.value || 30,
    note: saveNote.value.trim() || null,
    items: itemsWithPlan.value.map(i => ({
      sku: i.sku, name: i.name, qty_per_box: i.qtyPerBox, boxes_per_pallet: i.boxesPerPallet,
      multiplicity: i.multiplicity || 1, unit_of_measure: i.unitOfMeasure,
      monthly_consumption: i.monthlyConsumption, stock_on_hand: i.stockOnHand, stock_at_supplier: i.stockAtSupplier,
      plan: i.plan.map(p => ({ month: p.month, order_boxes: p.orderBoxes, order_units: p.orderUnits, locked: p.locked || false }))
    })),
  };
  let error;
  if (editingPlanId.value) {
    ({ error } = await db.from('plans').update(planData).eq('id', editingPlanId.value));
  } else {
    planData.created_by = userStore.currentUser?.name || null;
    ({ error } = await db.from('plans').insert([planData]));
  }
  if (error) { toast.error('Ошибка', ''); saving.value = false; return; }
  try {
    const ld = { supplier: supplier.value, items_count: itemsWithPlan.value.length, period: `${periodCount.value} ${periodType.value === 'weeks' ? 'нед.' : 'мес.'}`, note: saveNote.value.trim() || null };
    if (editingPlanId.value && _prevPlanItems) {
      const ch = []; const pm = {}; _prevPlanItems.forEach(i => { pm[i.sku || i.name] = i; });
      const ni = planData.items; const nm = {}; ni.forEach(i => { nm[i.sku || i.name] = i; });
      ni.forEach(i => { if (!pm[i.sku || i.name]) ch.push({ type: 'added', item: `${i.sku ? i.sku + ' ' : ''}${i.name}`, boxes: (i.plan || []).reduce((s,p) => s + (p.order_boxes||0), 0) }); });
      _prevPlanItems.forEach(i => { if (!nm[i.sku || i.name]) ch.push({ type: 'removed', item: `${i.sku ? i.sku + ' ' : ''}${i.name}`, boxes: (i.plan || []).reduce((s,p) => s + (p.order_boxes||0), 0) }); });
      const hd = periodHeaders.value;
      ni.forEach(i => { const pv = pm[i.sku || i.name]; if (!pv) return; const df = [];
        if ((pv.monthly_consumption||0) !== (i.monthly_consumption||0)) df.push(`расход: ${pv.monthly_consumption}→${i.monthly_consumption}`);
        for (let m = 0; m < Math.max((pv.plan||[]).length, (i.plan||[]).length); m++) { const pb = (pv.plan||[])[m]?.order_boxes||0; const nb = (i.plan||[])[m]?.order_boxes||0; if (pb !== nb) df.push(`${hd[m]?.label||`п.${m+1}`}: ${pb}→${nb} кор`); }
        if (df.length) ch.push({ type: 'changed', item: `${i.sku ? i.sku + ' ' : ''}${i.name}`, diffs: df });
      });
      if (ch.length) ld.changes = ch;
    }
    await db.from('audit_log').insert({ action: editingPlanId.value ? 'plan_updated' : 'plan_created', entity_type: 'plan', entity_id: editingPlanId.value || null, user_name: userStore.currentUser?.name || null, details: ld });
  } catch (_) {}
  toast.success(editingPlanId.value ? 'План обновлён' : 'План сохранён', `${itemsWithPlan.value.length} позиций`);
  showSaveModal.value = false;
  draftStore.clearPlanDraft(); resetPlan();
  } finally { saving.value = false; }
}

async function copyPlanToClipboard() {
  const hd = periodHeaders.value;
  let text = `План ${supplier.value} (${periodCount.value} ${periodType.value === 'weeks' ? 'нед.' : 'мес.'}):\n\n`;
  for (let mi = 0; mi < hd.length; mi++) { const mi2 = itemsWithPlan.value.filter(i => i.plan[mi]?.orderBoxes > 0); if (!mi2.length) continue; text += `📅 ${hd[mi].label}:\n`; mi2.forEach(i => { const p = i.plan[mi]; text += `${i.sku ? i.sku + ' ' : ''}${i.name} (${nf.format(p.orderUnits)} ${i.unitOfMeasure}) - ${p.orderBoxes} кор\n`; }); text += '\n'; }
  text += 'Спасибо!';
  await copyToClipboard(text); toast.success('Скопировано!', '');
}

function resetPlan() {
  editingPlanId.value = null; viewOnly.value = false; _prevPlanItems = null; _loadedNote = '';
  items.value.forEach(i => { i.plan = []; i.monthlyConsumption = 0; i.stockOnHand = 0; i.stockAtSupplier = 0; });
  undoStack.value = []; redoStack.value = [];
  recalcAll(); draftStore.clearPlanDraft();
}

async function loadPlanFromHistory(planId) {
  const { data: plan, error } = await db.from('plans').select('*').eq('id', planId).single();
  if (error || !plan) { toast.error('Ошибка', ''); return; }
  orderStore.settings.legalEntity = plan.legal_entity || 'Бургер БК';
  supplier.value = plan.supplier || '';
  periodValue.value = (plan.period_type === 'weeks' ? 'w' : 'm') + plan.period_count;
  startDateStr.value = plan.start_date || toLocalDateStr(new Date());
  consumptionPeriodDays.value = plan.consumption_period_days || 30;
  editingPlanId.value = plan.id;
  _loadedNote = plan.note || '';
  await supplierStore.loadSuppliers(orderStore.settings.legalEntity);
  _prevPlanItems = JSON.parse(JSON.stringify(plan.items || []));
  items.value = (plan.items || []).map(i => ({
    productId: null, sku: i.sku || '', name: i.name || '', qtyPerBox: i.qty_per_box || 1,
    boxesPerPallet: i.boxes_per_pallet || null, unitOfMeasure: i.unit_of_measure || 'шт',
    multiplicity: i.multiplicity || 1, monthlyConsumption: i.monthly_consumption || 0,
    stockOnHand: i.stock_on_hand || 0, stockAtSupplier: i.stock_at_supplier || 0, _cw: false, _ct: '',
    plan: (i.plan || []).map(p => ({ month: p.month, need: 0, deficit: 0, orderBoxes: p.order_boxes || 0, orderUnits: p.order_units || 0, locked: p.locked || false }))
  }));
  undoStack.value = []; redoStack.value = [];
  items.value.forEach((_, idx) => recalcItem(idx, 0));
  triggerValidation();
  toast.success('План загружен', `${plan.supplier} — ${items.value.length} позиций`);
}

watch(() => orderStore.settings.legalEntity, async () => { await supplierStore.loadSuppliers(orderStore.settings.legalEntity); supplier.value = ''; items.value = []; });
const showCollapseHint = ref(false);
watch(supplier, (v) => { if (v && settingsExpanded.value) { showCollapseHint.value = true; setTimeout(() => { showCollapseHint.value = false; }, 4000); } });

onMounted(async () => {
  if (!supplier.value) settingsExpanded.value = true;
  await supplierStore.loadSuppliers(orderStore.settings.legalEntity);
  if (route.query.planId) {
    await loadPlanFromHistory(route.query.planId);
    if (route.query.mode === 'view') { viewOnly.value = true; editingPlanId.value = null; }
  } else {
    const draft = draftStore.hasPlanDraft();
    if (draft) {
      const ok = await new Promise(r => { confirmModal.value = { show: true, title: 'Восстановить черновик?', message: `от ${draft.date} (${draft.supplier}, ${draft.itemsCount} поз.)`, resolve: r }; });
      if (ok) { const d = draftStore.loadPlanDraft(); if (d) { supplier.value = d.supplier || ''; periodValue.value = d.periodValue || 'm3'; startDateStr.value = d.startDateStr || new Date().toISOString().slice(0,10); inputUnit.value = d.inputUnit || 'pieces'; consumptionPeriodDays.value = d.consumptionPeriodDays || 30; items.value = (d.items || []).map(i => ({ ...i, _cw: false, _ct: '' })); recalcAll(); toast.info('Черновик загружен', ''); } }
      else { draftStore.clearPlanDraft(); }
    }
  }
});
</script>

<style scoped>
.plan-td-input input { -moz-appearance: textfield; width: 72px; text-align: center; }
.plan-td-input input::-webkit-outer-spin-button, .plan-td-input input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.plan-calc-input { -moz-appearance: textfield !important; }
.plan-calc-input::-webkit-outer-spin-button, .plan-calc-input::-webkit-inner-spin-button { -webkit-appearance: none !important; margin: 0 !important; }
.plan-td-result { text-align: center; padding: 4px; min-width: 100px; cursor: default; transition: background 0.15s; }
.plan-td-result:not(:first-of-type) { cursor: pointer; }
.plan-td-result:hover { background: #fdf9f3; }
.plan-has-value { background: #fff8f0; }
.plan-result-value { display: block; font-weight: 700; color: var(--text); font-size: 13px; }
.plan-result-sub { display: block; font-size: 10px; color: var(--text-muted); margin-top: 1px; }
.plan-result-zero { color: var(--text-muted); font-size: 10px; }
.plan-cell-locked { background: #fff8e1 !important; border: 1px dashed var(--bk-orange) !important; }
.plan-result-value.plan-cell-locked { color: #e65100; }
.plan-pallet-period, .plan-reset-cell { display: inline-block; font-size: 10px; cursor: pointer; margin-left: 2px; opacity: 0.5; transition: opacity 0.15s; vertical-align: middle; }
.plan-pallet-period:hover, .plan-reset-cell:hover { opacity: 1; }
.plan-reset-cell { color: #d32f2f; font-weight: 700; }
.plan-th-total { min-width: 70px; background: rgba(245,166,35,0.15) !important; }
.plan-td-total { text-align: center; min-width: 80px; border-left: 2px solid var(--bk-orange); padding: 4px 6px; }
.plan-td-total.plan-has-value { background: rgba(245,166,35,0.08); }
.plan-total-boxes { display: block; font-weight: 700; font-size: 13px; color: var(--bk-brown); }
.plan-total-units { display: block; font-size: 10px; color: var(--text-muted); margin-top: 1px; }
.consumption-warning { color: #d32f2f !important; font-weight: 700 !important; border-color: #d32f2f !important; background: #ffebee !important; }

/* Колонка запаса (дней) */
.plan-th-reserve { min-width: 50px; text-align: center; }
.plan-td-reserve { text-align: center; font-weight: 700; font-size: 13px; color: var(--text-muted); border-right: 2px solid var(--border-light); }
.plan-td-reserve.reserve-danger { color: #d32f2f; background: #ffebee; }
.plan-td-reserve.reserve-warning { color: #e65100; background: #fff3e0; }
.plan-td-reserve.reserve-ok { color: #2e7d32; }

/* (#4) Sticky name column — solid bg to prevent bleed-through */
.plan-th-name { text-align: left !important; padding-left: 10px !important; min-width: 200px; position: sticky; left: 0; z-index: 22; background: inherit; }
.plan-td-name { text-align: left !important; padding-left: 14px !important; position: sticky; left: 0; z-index: 2; background: #ffffff !important; border-right: 1px solid var(--border-light); min-width: 200px; }
.plan-table tbody tr:hover .plan-td-name { background: #fffbf0 !important; }
.plan-table tbody tr.has-order .plan-td-name { background: #f7faf5 !important; }
.plan-table tbody tr.has-order:hover .plan-td-name { background: #f2f7ee !important; }
.plan-totals .plan-td-name { background: #fdf9f2 !important; }

/* ─── Plan Compact Mode ─── */
.plan-compact :deep(.order-table td),
.plan-compact :deep(.plan-table td),
.plan-compact td { padding: 3px 5px; }
.plan-compact :deep(.order-table thead th),
.plan-compact :deep(.plan-table thead th),
.plan-compact th { padding: 5px 5px; font-size: 9px; letter-spacing: 0.3px; }
.plan-compact .plan-td-name { padding-left: 8px !important; padding-top: 2px !important; padding-bottom: 2px !important; cursor: help; }
.plan-compact .plan-td-input input { width: 60px; padding: 2px 3px; font-size: 12px; height: 24px; }
.plan-compact .plan-td-result { padding: 2px 3px; min-width: 70px; }
.plan-compact .plan-result-value { font-size: 12px; }
.plan-compact .plan-result-zero { font-size: 9px; }
.plan-compact .plan-pallet-period { display: none; }
.plan-compact .plan-td-result:hover .plan-pallet-period { display: inline-block; }
.plan-compact .plan-totals td { padding: 6px 5px; font-size: 12px; }
.plan-compact .plan-total-cell.plan-has-value { font-size: 13px; }
.plan-compact .plan-th-month { cursor: help; }
.plan-compact .plan-edit-input { width: 50px !important; font-size: 12px !important; padding: 1px 3px !important; }
</style>
