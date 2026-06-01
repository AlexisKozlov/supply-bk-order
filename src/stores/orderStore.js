import { defineStore } from 'pinia';
import { reactive, ref, computed, watch, toRaw } from 'vue';
import { calculateItem, calculateBufferItem } from '@/lib/calculations.js';
import { getQpb, getMultiplicity, applyEntityGroupFilter, toPhysicalBoxes } from '@/lib/utils.js';
import { DEFAULT_ENTITY } from '@/lib/legalEntities.js';
import { db } from '@/lib/apiClient.js';
import { useUserStore } from './userStore.js';
import { useToastStore } from './toastStore.js';

const ORDER_PRODUCT_FIELDS = 'id, sku, external_code, name, unit_of_measure, qty_per_box, boxes_per_pallet, multiplicity, category';

const _clone = typeof structuredClone === 'function'
  ? structuredClone
  : (v) => JSON.parse(JSON.stringify(v));

class History {
  constructor(maxSize = 50) {
    this.states = [];
    this.currentIndex = -1;
    this.maxSize = maxSize;
  }
  push(state) {
    this.states = this.states.slice(0, this.currentIndex + 1);
    this.states.push(_clone(state));
    if (this.states.length > this.maxSize) this.states.shift();
    this.currentIndex = this.states.length - 1;
  }
  undo() {
    if (this.canUndo()) { this.currentIndex--; return _clone(this.states[this.currentIndex]); }
    return null;
  }
  redo() {
    if (this.canRedo()) { this.currentIndex++; return _clone(this.states[this.currentIndex]); }
    return null;
  }
  canUndo() { return this.currentIndex > 0; }
  canRedo() { return this.currentIndex < this.states.length - 1; }
  clear() { this.states = []; this.currentIndex = -1; }
}

export const useOrderStore = defineStore('order', () => {
  const userStore = useUserStore();

  const settings = reactive({
    legalEntity: localStorage.getItem('bk_legal_entity') || DEFAULT_ENTITY,
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
    cdaMode: false,
    safetyCoef: 1.0,
  });

  watch(() => settings.legalEntity, (le) => {
    try { localStorage.setItem('bk_legal_entity', le); } catch(e) { /* noop */ }
  });

  const items = ref([]);
  const editingOrderId = ref(null);
  const editingOrderUpdatedAt = ref(null);
  const viewOnlyMode = ref(false);
  const _history = new History(50);
  const _historyVersion = ref(0);
  let _loadRequestId = 0;

  const canUndo = computed(() => { _historyVersion.value; return _history.canUndo(); });
  const canRedo = computed(() => { _historyVersion.value; return _history.canRedo(); });

  const pageTitle = computed(() => {
    if (viewOnlyMode.value) return 'Просмотр заказа';
    if (editingOrderId.value) return 'Редактирование заказа';
    return 'Новый заказ';
  });

  // Итоговая сводка — зависит только от items и unit (не от всех settings)
  const finalSummary = computed(() => {
    const activeItems = items.value.filter(i => (i.finalOrder || 0) > 0);
    if (!activeItems.length) return null;
    let totalPallets = 0;
    let totalBoxesLeft = 0;
    const unit = settings.unit;
    activeItems.forEach(item => {
      if (!item.boxesPerPallet || !item.finalOrder) return;
      // Используем единые хелперы — иначе общие паллеты в шапке могли
      // не совпадать с поштучным расчётом в строках и в Excel.
      const physBoxes = toPhysicalBoxes(item, item.finalOrder, unit);
      totalPallets += Math.floor(physBoxes / item.boxesPerPallet);
      totalBoxesLeft += physBoxes % item.boxesPerPallet;
    });
    return { positions: activeItems.length, pallets: totalPallets, boxesLeft: totalBoxesLeft };
  });

  function _snapshot() {
    // Сохраняем не только items+unit, но и параметры расчёта — иначе undo
    // возвращает позиции, но safetyDays/periodDays/deliveryDate остаются
    // новыми, и расчёт даёт неконсистентные значения.
    _history.push({
      items: toRaw(items.value).map(i => ({ ...toRaw(i) })),
      unit: settings.unit,
      safetyDays: settings.safetyDays,
      periodDays: settings.periodDays,
      deliveryDate: settings.deliveryDate ? new Date(settings.deliveryDate.getTime()) : null,
      today: settings.today ? new Date(settings.today.getTime()) : null,
    });
    _historyVersion.value++;
  }

  // Дебаунсированный snapshot для частых изменений (ввод цифр в полях):
  // раньше каждое нажатие клавиши клонировало массив из 500 строк и пушило
  // в историю — заметная задержка ввода в больших заказах. Теперь снапшот
  // случается один раз через 500 мс после последнего изменения. Undo
  // откатывает к состоянию ДО серии правок, а не до каждой цифры.
  let _snapshotTimer = null;
  function _scheduleSnapshot() {
    if (_snapshotTimer) clearTimeout(_snapshotTimer);
    _snapshotTimer = setTimeout(() => { _snapshotTimer = null; _snapshot(); }, 500);
  }
  // Принудительный коммит ожидающего snapshot — вызывается перед операциями,
  // которые меняют структуру (add/remove/apply), чтобы серия мелких правок
  // не «слиплась» с крупным изменением в один шаг истории.
  function _flushSnapshot() {
    if (_snapshotTimer) {
      clearTimeout(_snapshotTimer);
      _snapshotTimer = null;
      _snapshot();
    }
  }

  // FIX баг 3: сохраняем начальное состояние ДО первого изменения
  function _ensureInitialState() {
    if (_history.states.length === 0) {
      _snapshot();
    }
  }

  function addItem(product, skipSnapshot = false) {
    const exists = items.value.find(i =>
      (product.id && i.productId === product.id) ||
      (product.sku && i.sku === product.sku) ||
      (!product.id && !product.sku && product.name && i.name === product.name)
    );
    if (exists) return null;
    // FIX баг 3: сохраняем начальное состояние ДО изменения
    if (!skipSnapshot) { _flushSnapshot(); _ensureInitialState(); }
    const item = {
      id: Date.now().toString(36) + Math.random().toString(36).slice(2, 8),
      productId: product.id || null,
      sku: product.sku || '',
      externalCode: product.external_code || '',
      category: product.category || '',
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
    _flushSnapshot();
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
    // Раньше изменение consumptionPeriod сбрасывало _manualOrder → массовый
    // «Применить расчёт» затирал ручной заказ. Теперь правка расхода не
    // отменяет ручной режим заказа; пользователь снимает его явно.
    _scheduleSnapshot();
  }

  function applyAllCalculated() {
    _flushSnapshot();
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
    const arr = items.value;
    if (fromIndex < 0 || fromIndex >= arr.length || toIndex < 0 || toIndex >= arr.length) return;
    _flushSnapshot();
    _ensureInitialState();
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
    editingOrderUpdatedAt.value = null;
    viewOnlyMode.value = false;
    _history.clear(); _historyVersion.value++;
  }

  function clearHistory() {
    _history.clear(); _historyVersion.value++;
  }

  function _applyHistoryState(state) {
    if (!state) return;
    // Обратная совместимость: старый формат — массив, новый — {items, unit, ...}
    items.value = Array.isArray(state) ? state : state.items;
    if (Array.isArray(state)) return;
    if (state.unit) settings.unit = state.unit;
    if (state.safetyDays !== undefined) settings.safetyDays = state.safetyDays;
    if (state.periodDays !== undefined) settings.periodDays = state.periodDays;
    if (state.deliveryDate !== undefined) settings.deliveryDate = state.deliveryDate;
    if (state.today !== undefined) settings.today = state.today;
  }

  function undo() {
    // Сначала закоммитим ожидающий snapshot — иначе откатим к состоянию ДО
    // последних быстрых правок (они «исчезнут» вместе с дебаунсом).
    _flushSnapshot();
    _applyHistoryState(_history.undo());
    _historyVersion.value++;
  }

  function redo() {
    _flushSnapshot();
    _applyHistoryState(_history.redo());
    _historyVersion.value++;
  }

  async function saveItemOrder() {
    const supplier = settings.supplier || 'all';
    const legalEntity = settings.legalEntity;
    const orderData = items.value.map((item, index) => ({
      item_id: item.productId || item.id, position: index,
    }));
    const { error } = await db.rpc('replace_item_order', {
      supplier, legal_entity: legalEntity, items: orderData,
    });
    if (error) {
      console.error('saveItemOrder error:', error);
      try {
        useToastStore().error('Не удалось сохранить порядок товаров', error.message || '');
      } catch (_) { /* toast store недоступен */ }
    }
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
    const myRequestId = ++_loadRequestId;

    const skus = (order.order_items || []).map(i => i.sku).filter(Boolean);
    let productMap = {};
    if (skus.length > 0) {
      let prodQuery = db.from('products').select(ORDER_PRODUCT_FIELDS).in('sku', skus);
      prodQuery = applyEntityGroupFilter(prodQuery, legalEntity);
      const { data: productsData } = await prodQuery;
      if (myRequestId !== _loadRequestId) return; // Новый вызов перехватил — выходим
      if (productsData) productMap = Object.fromEntries(productsData.map(p => [p.sku, p]));
    }

    // Состояние обновляем ПОСЛЕ асинхронных операций и проверки requestId
    items.value = [];
    settings.legalEntity = legalEntity;
    settings.supplier = order.supplier || '';
    settings.today = order.today_date ? new Date(order.today_date + 'T00:00:00') : new Date();
    settings.deliveryDate = order.delivery_date ? new Date(order.delivery_date + 'T00:00:00') : null;
    settings.safetyDays = parseInt(order.safety_days, 10) || 0;
    settings.periodDays = parseInt(order.period_days, 10) || 30;
    settings.unit = order.unit || 'pieces';
    settings.hasTransit = order.has_transit === true || order.has_transit === 'true' || order.has_transit === '1' || order.has_transit === 1;
    settings.showStockColumn = true; // всегда показываем запас при просмотре
    settings.note = order.notes || order.note || '';
    settings.cdaMode = order.cda_mode === true || order.cda_mode === 1 || order.cda_mode === '1';
    settings.safetyCoef = parseFloat(order.safety_coef) || 1.0;

    for (const histItem of (order.order_items || [])) {
      const productData = histItem.sku ? productMap[histItem.sku] : null;
      const added = addItem(productData || {
        sku: histItem.sku, name: histItem.name,
        qty_per_box: histItem.qty_per_box || 1,
        boxes_per_pallet: null,
        multiplicity: histItem.multiplicity || 1,
      }, true);

      // Если товар-дубликат (addItem вернул null), пропускаем
      if (!added) continue;

      const addedItem = added;
      // Восстанавливаем кратность и штук-в-коробке из сохранённого заказа,
      // чтобы пересчёт был корректным даже если карточку товара с тех пор изменили
      if (histItem.multiplicity) addedItem.multiplicity = histItem.multiplicity;
      if (histItem.qty_per_box) addedItem.qtyPerBox = histItem.qty_per_box;
      addedItem.consumptionPeriod = Math.round(parseFloat(String(histItem.consumption_period || '0').replace(',', '.')) || 0);
      addedItem.stock = Math.round(parseFloat(String(histItem.stock || '0').replace(',', '.')) || 0);
      addedItem.transit = Math.round(parseFloat(String(histItem.transit || '0').replace(',', '.')) || 0);

      // Если в БД сохранено исходное количество (final_order + unit_of_measure),
      // восстанавливаем точно. Иначе — fallback через qty_boxes*qpb (старые заказы).
      const accountingBoxes = parseFloat(String(histItem.qty_boxes || '0').replace(',', '.')) || 0;
      const itemQpb = getQpb(addedItem);
      const savedFinal = histItem.final_order != null && histItem.final_order !== ''
        ? parseFloat(String(histItem.final_order).replace(',', '.'))
        : null;
      const savedUnit = histItem.unit_of_measure || null;
      if (savedFinal !== null && Number.isFinite(savedFinal) && savedUnit) {
        // Конвертируем в текущий unit заказа (если пользователь сменил режим).
        if (savedUnit === settings.unit) {
          addedItem.finalOrder = Math.round(savedFinal);
        } else if (savedUnit === 'pieces' && settings.unit === 'boxes') {
          addedItem.finalOrder = Math.ceil(savedFinal / itemQpb);
        } else {
          addedItem.finalOrder = Math.round(savedFinal * itemQpb);
        }
      } else {
        addedItem.finalOrder = settings.unit === 'boxes'
          ? Math.round(accountingBoxes)
          : Math.round(accountingBoxes * itemQpb);
      }
    }

    editingOrderId.value = (isEditing || isViewOnly) ? order.id : null;
    editingOrderUpdatedAt.value = (isEditing || isViewOnly) ? (order.updated_at || null) : null;
    viewOnlyMode.value = isViewOnly;
    _history.clear(); _historyVersion.value++;
    _snapshot();
    return true;
  }

  async function auditLog(action, entityType, entityId, details = {}) {
    try {
      await db.from('audit_log').insert({
        action, entity_type: entityType, entity_id: entityId,
        user_name: userStore.currentUser?.name || null, legal_entity: settings.legalEntity || null, details,
      });
    } catch (e) { /* не блокируем */ }
  }

  // Счётчик для принудительного пересчёта валидации (инкрементировать при массовом обновлении данных)
  const dataVersion = ref(0);
  function bumpDataVersion() { dataVersion.value++; }

  return {
    settings, items, editingOrderId, editingOrderUpdatedAt, viewOnlyMode, dataVersion,
    canUndo, canRedo, pageTitle, finalSummary,
    addItem, removeItem, updateItemField, applyAllCalculated,
    moveItem, clearItems, clearAllData, resetOrder, clearHistory, undo, redo,
    saveItemOrder, restoreItemOrder, loadOrderIntoForm, auditLog,
    bumpDataVersion,
  };
});
