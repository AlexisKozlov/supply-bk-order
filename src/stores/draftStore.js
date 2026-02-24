import { defineStore } from 'pinia';
import { ref } from 'vue';
import { useOrderStore } from './orderStore.js';

const DRAFT_KEY = 'bk_draft';

export const useDraftStore = defineStore('draft', () => {
  const isLoading = ref(false);
  let _timer = null;

  function save() {
    const orderStore = useOrderStore();
    // Не сохраняем черновик в режиме просмотра или редактирования
    if (orderStore.viewOnlyMode || orderStore.editingOrderId) return;
    clearTimeout(_timer);
    _timer = setTimeout(() => _doSave(orderStore), 500);
  }

  /** Синхронное сохранение (без таймера) — для навигации */
  function saveNow() {
    clearTimeout(_timer);
    const orderStore = useOrderStore();
    if (orderStore.viewOnlyMode || orderStore.editingOrderId) return;
    _doSave(orderStore);
  }

  function _doSave(orderStore) {
    // Не сохраняем если нет данных
    const hasData = orderStore.items.some(i => i.consumptionPeriod > 0 || i.stock > 0 || i.transit > 0 || i.finalOrder > 0);
    if (!hasData) return;
    const draft = {
      settings: { ...orderStore.settings },
      items: orderStore.items,
      timestamp: new Date().toISOString(),
    };
    if (draft.settings.today instanceof Date) draft.settings.today = draft.settings.today.toISOString();
    if (draft.settings.deliveryDate instanceof Date) draft.settings.deliveryDate = draft.settings.deliveryDate.toISOString();
    if (draft.settings.safetyEndDate instanceof Date) draft.settings.safetyEndDate = draft.settings.safetyEndDate.toISOString();
    localStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
  }

  function clear() {
    clearTimeout(_timer);
    localStorage.removeItem(DRAFT_KEY);
  }

  /** Восстановить черновик. Возвращает true если черновик был загружен. */
  async function load(supplierLoader) {
    const raw = localStorage.getItem(DRAFT_KEY);
    if (!raw) return false;

    try {
      const data = JSON.parse(raw);
      const orderStore = useOrderStore();
      isLoading.value = true;

      // Настройки
      if (data.settings.today) orderStore.settings.today = new Date(data.settings.today);
      if (data.settings.deliveryDate) orderStore.settings.deliveryDate = new Date(data.settings.deliveryDate);
      if (data.settings.safetyEndDate) orderStore.settings.safetyEndDate = new Date(data.settings.safetyEndDate);

      orderStore.settings.legalEntity  = data.settings.legalEntity  || 'Бургер БК';
      orderStore.settings.supplier     = data.settings.supplier      || '';
      orderStore.settings.periodDays   = data.settings.periodDays    || 30;
      orderStore.settings.safetyDays   = data.settings.safetyDays    || 0;
      orderStore.settings.unit         = data.settings.unit          || 'pieces';
      orderStore.settings.hasTransit   = data.settings.hasTransit    || false;
      orderStore.settings.showStockColumn = data.settings.showStockColumn ?? true;
      orderStore.settings.note         = data.settings.note          || '';

      // Загружаем поставщиков для юр. лица если передан загрузчик
      if (supplierLoader) {
        await supplierLoader(orderStore.settings.legalEntity);
      }

      // Товары
      orderStore.items = data.items || [];

      isLoading.value = false;

      if (orderStore.items.length > 0) {
        await orderStore.restoreItemOrder();
        const draftDate = new Date(data.timestamp).toLocaleString('ru-RU');
        return { loaded: true, date: draftDate };
      }

      return false;
    } catch (e) {
      isLoading.value = false;
      console.error('Ошибка загрузки черновика:', e);
      return false;
    }
  }

  /** Проверить наличие черновика (без загрузки) */
  function hasDraft() {
    const raw = localStorage.getItem(DRAFT_KEY);
    if (!raw) return null;
    try {
      const data = JSON.parse(raw);
      if (!data.items || data.items.length === 0) return null;
      return { date: new Date(data.timestamp).toLocaleString('ru-RU'), itemsCount: data.items.length };
    } catch { return null; }
  }

  // ═══ ПЛАНИРОВАНИЕ — черновик ═══
  const PLAN_DRAFT_KEY = 'bk_plan_draft';
  let _planTimer = null;

  function savePlan(planState) {
    // Не сохраняем просмотр/редактирование
    if (planState.viewOnly || planState.editingPlanId) return;
    clearTimeout(_planTimer);
    _planTimer = setTimeout(() => {
      // Не сохраняем если нет данных
      const hasData = (planState.items || []).some(i => i.monthlyConsumption > 0 || i.stockOnHand > 0 || i.stockAtSupplier > 0 || (i.plan && i.plan.some(p => p.orderBoxes > 0)));
      if (!hasData) return;
      const draft = {
        supplier: planState.supplier,
        periodValue: planState.periodValue,
        startDateStr: planState.startDateStr,
        inputUnit: planState.inputUnit,
        items: planState.items,
        timestamp: new Date().toISOString(),
      };
      localStorage.setItem(PLAN_DRAFT_KEY, JSON.stringify(draft));
    }, 500);
  }

  function clearPlanDraft() {
    clearTimeout(_planTimer);
    localStorage.removeItem(PLAN_DRAFT_KEY);
  }

  function hasPlanDraft() {
    const raw = localStorage.getItem(PLAN_DRAFT_KEY);
    if (!raw) return null;
    try {
      const data = JSON.parse(raw);
      if (!data.items || data.items.length === 0) return null;
      return { date: new Date(data.timestamp).toLocaleString('ru-RU'), itemsCount: data.items.length, supplier: data.supplier };
    } catch { return null; }
  }

  function loadPlanDraft() {
    const raw = localStorage.getItem(PLAN_DRAFT_KEY);
    if (!raw) return null;
    try { return JSON.parse(raw); } catch { return null; }
  }

  return { isLoading, save, saveNow, clear, load, hasDraft, savePlan, clearPlanDraft, hasPlanDraft, loadPlanDraft };
});
