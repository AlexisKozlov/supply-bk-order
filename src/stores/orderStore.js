import { defineStore } from 'pinia';
import { reactive, ref, computed, watch } from 'vue';
import { calculateItem } from '@/lib/calculations.js';
import { getQpb, getMultiplicity } from '@/lib/utils.js';
import { db } from '@/lib/apiClient.js';
import { useUserStore } from './userStore.js';

class History {
  constructor(maxSize = 50) {
    this.states = [];
    this.currentIndex = -1;
    this.maxSize = maxSize;
  }
  push(state) {
    this.states = this.states.slice(0, this.currentIndex + 1);
    this.states.push(JSON.parse(JSON.stringify(state)));
    if (this.states.length > this.maxSize) this.states.shift();
    this.currentIndex = this.states.length - 1;
  }
  undo() {
    if (this.canUndo()) { this.currentIndex--; return JSON.parse(JSON.stringify(this.states[this.currentIndex])); }
    return null;
  }
  redo() {
    if (this.canRedo()) { this.currentIndex++; return JSON.parse(JSON.stringify(this.states[this.currentIndex])); }
    return null;
  }
  canUndo() { return this.currentIndex > 0; }
  canRedo() { return this.currentIndex < this.states.length - 1; }
  clear() { this.states = []; this.currentIndex = -1; }
}

export const useOrderStore = defineStore('order', () => {
  const userStore = useUserStore();

  const settings = reactive({
    legalEntity: localStorage.getItem('bk_legal_entity') || 'ООО "Бургер БК"',
    supplier: '',
    today: null,
    deliveryDate: null,
    periodDays: 30,
    safetyDays: 0,
    safetyEndDate: null,
    unit: 'boxes',
    hasTransit: false,
    showStockColumn: true,
    note: '',
  });

  watch(() => settings.legalEntity, (le) => {
    try { localStorage.setItem('bk_legal_entity', le); } catch(e) { /* noop */ }
  });

  const items = ref([]);
  const editingOrderId = ref(null);
  const viewOnlyMode = ref(false);
  const _history = new History(50);
  const _historyVersion = ref(0);
  let _snapshotTimer = null;

  const canUndo = computed(() => { _historyVersion.value; return _history.canUndo(); });
  const canRedo = computed(() => { _historyVersion.value; return _history.canRedo(); });

  const pageTitle = computed(() => {
    if (viewOnlyMode.value) return 'Просмотр заказа';
    if (editingOrderId.value) return 'Редактирование заказа';
    return 'Новый заказ';
  });

  const finalSummary = computed(() => {
    const activeItems = items.value.filter(i => (i.finalOrder || 0) > 0);
    if (!activeItems.length) return null;
    let totalPallets = 0;
    let totalBoxesLeft = 0;
    activeItems.forEach(item => {
      const calc = calculateItem(item, settings);
      if (calc.palletsInfo) {
        totalPallets += calc.palletsInfo.pallets;
        totalBoxesLeft += calc.palletsInfo.boxesLeft;
      }
    });
    return { positions: activeItems.length, pallets: totalPallets, boxesLeft: totalBoxesLeft };
  });

  function _snapshot() {
    _history.push(items.value); // History.push уже делает deep clone
    _historyVersion.value++;
  }

  // FIX баг 3: сохраняем начальное состояние ДО первого изменения
  function _ensureInitialState() {
    if (_history.states.length === 0) {
      _snapshot();
    }
  }

  function _debouncedSnapshot() {
    clearTimeout(_snapshotTimer);
    _snapshotTimer = setTimeout(() => _snapshot(), 800);
  }

  function addItem(product, skipSnapshot = false) {
    const exists = items.value.find(i => i.productId === product.id || (i.sku && i.sku === product.sku));
    if (exists) return null;
    // FIX баг 3: сохраняем начальное состояние ДО изменения
    if (!skipSnapshot) _ensureInitialState();
    const item = {
      id: Date.now() + Math.random(),
      productId: product.id || null,
      sku: product.sku || '',
      name: product.name || '',
      unitOfMeasure: product.unit_of_measure || 'шт',
      qtyPerBox: product.qty_per_box || 1,
      boxesPerPallet: product.boxes_per_pallet || null,
      multiplicity: product.multiplicity || 1,
      consumptionPeriod: 0,
      stock: 0,
      transit: 0,
      finalOrder: 0,
      comment: '',
      _manualOrder: false,
    };
    items.value.push(item);
    if (!skipSnapshot) _snapshot();
    return item;
  }

  function removeItem(itemId) {
    _ensureInitialState();
    const idx = items.value.findIndex(i => i.id === itemId);
    if (idx !== -1) { items.value.splice(idx, 1); _snapshot(); }
  }

  function updateItemField(itemId, field, value) {
    const item = items.value.find(i => i.id === itemId);
    if (!item) return;
    _ensureInitialState();
    item[field] = value;
    if (field === 'finalOrder') item._manualOrder = true;
    if (['consumptionPeriod', 'stock', 'transit'].includes(field)) item._manualOrder = false;
    _snapshot();
  }

  function applyAllCalculated() {
    _ensureInitialState();
    items.value.forEach(item => {
      if (!item._manualOrder) {
        const calc = calculateItem(item, settings);
        item.finalOrder = calc.calculatedOrder;
      }
    });
    _snapshot();
  }

  function moveItem(fromIndex, toIndex) {
    _ensureInitialState();
    const arr = items.value;
    const [moved] = arr.splice(fromIndex, 1);
    arr.splice(toIndex, 0, moved);
    _snapshot();
  }

  function clearItems() {
    _ensureInitialState();
    items.value = [];
    _snapshot();
  }

  function clearAllData() {
    _ensureInitialState();
    items.value.forEach(item => {
      item.consumptionPeriod = 0;
      item.stock = 0;
      item.transit = 0;
      item.finalOrder = 0;
      item._manualOrder = false;
    });
    _snapshot();
  }

  function resetOrder() {
    items.value = [];
    settings.supplier = '';
    editingOrderId.value = null;
    viewOnlyMode.value = false;
    _history.clear(); _historyVersion.value++;
  }

  function undo() {
    clearTimeout(_snapshotTimer);
    const state = _history.undo();
    if (!state) return;
    items.value = JSON.parse(JSON.stringify(state));
    _historyVersion.value++;
  }

  function redo() {
    clearTimeout(_snapshotTimer);
    const state = _history.redo();
    if (!state) return;
    items.value = JSON.parse(JSON.stringify(state));
    _historyVersion.value++;
  }

  async function saveItemOrder() {
    const supplier = settings.supplier || 'all';
    const legalEntity = settings.legalEntity;
    await db.from('item_order').delete().eq('supplier', supplier).eq('legal_entity', legalEntity);
    const orderData = items.value.map((item, index) => ({
      supplier, legal_entity: legalEntity,
      item_id: item.productId || item.id, position: index,
    }));
    if (orderData.length > 0) await db.from('item_order').insert(orderData);
  }

  async function restoreItemOrder() {
    const supplier = settings.supplier || 'all';
    const legalEntity = settings.legalEntity;
    const { data, error } = await db.from('item_order').select('*')
      .eq('supplier', supplier).eq('legal_entity', legalEntity).order('position');
    if (error || !data || data.length === 0) return;
    const posMap = {};
    data.forEach(row => { posMap[row.item_id] = row.position; });
    items.value.sort((a, b) => {
      const pa = posMap[a.productId ?? a.id] ?? 9999;
      const pb = posMap[b.productId ?? b.id] ?? 9999;
      return pa - pb;
    });
  }

  async function loadOrderIntoForm(order, legalEntity, isEditing = false, isViewOnly = false) {
    items.value = [];
    settings.legalEntity = legalEntity;
    settings.supplier = order.supplier || '';
    settings.today = order.today_date ? new Date(order.today_date + 'T00:00:00') : new Date();
    settings.deliveryDate = order.delivery_date ? new Date(order.delivery_date + 'T00:00:00') : null;
    settings.safetyDays = parseInt(order.safety_days) || 0;
    settings.periodDays = parseInt(order.period_days) || 30;
    settings.unit = order.unit || 'pieces';
    settings.hasTransit = order.has_transit === true || order.has_transit === 'true' || order.has_transit === '1' || order.has_transit === 1;
    settings.showStockColumn = true; // всегда показываем запас при просмотре
    settings.note = order.note || '';

    const skus = (order.order_items || []).map(i => i.sku).filter(Boolean);
    let productMap = {};
    if (skus.length > 0) {
      const { data: productsData } = await db.from('products').select('*').in('sku', skus);
      if (productsData) productMap = Object.fromEntries(productsData.map(p => [p.sku, p]));
    }

    for (const histItem of (order.order_items || [])) {
      const productData = histItem.sku ? productMap[histItem.sku] : null;
      addItem(productData || {
        sku: histItem.sku, name: histItem.name,
        qty_per_box: (productData?.qty_per_box) || histItem.qty_per_box || 1,
        boxes_per_pallet: null,
        multiplicity: (productData?.multiplicity) || 1,
      }, true);

      const addedItem = items.value[items.value.length - 1];
      addedItem.consumptionPeriod = Math.round(parseFloat(String(histItem.consumption_period || '0').replace(',', '.')) || 0);
      addedItem.stock = Math.round(parseFloat(String(histItem.stock || '0').replace(',', '.')) || 0);
      addedItem.transit = Math.round(parseFloat(String(histItem.transit || '0').replace(',', '.')) || 0);

      const physBoxes = parseFloat(String(histItem.qty_boxes || '0').replace(',', '.')) || 0;
      const itemMult = getMultiplicity(addedItem);
      const itemQpb = getQpb(addedItem);
      addedItem.finalOrder = settings.unit === 'boxes'
        ? Math.round(physBoxes * itemMult)
        : Math.round(physBoxes * itemQpb * itemMult);
    }

    editingOrderId.value = (isEditing || isViewOnly) ? order.id : null;
    viewOnlyMode.value = isViewOnly;
    _history.clear(); _historyVersion.value++;
    _snapshot();
    return true;
  }

  async function auditLog(action, entityType, entityId, details = {}) {
    try {
      await db.from('audit_log').insert({
        action, entity_type: entityType, entity_id: entityId,
        user_name: userStore.currentUser?.name || null, details,
      });
    } catch (e) { /* не блокируем */ }
  }

  // Счётчик для принудительного пересчёта валидации (инкрементировать при массовом обновлении данных)
  const dataVersion = ref(0);
  function bumpDataVersion() { dataVersion.value++; }

  return {
    settings, items, editingOrderId, viewOnlyMode, dataVersion,
    canUndo, canRedo, pageTitle, finalSummary,
    addItem, removeItem, updateItemField, applyAllCalculated,
    moveItem, clearItems, clearAllData, resetOrder, undo, redo,
    saveItemOrder, restoreItemOrder, loadOrderIntoForm, auditLog,
    bumpDataVersion,
  };
});
