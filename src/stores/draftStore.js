import { defineStore } from 'pinia';
import { ref, toRaw } from 'vue';
import { useOrderStore } from './orderStore.js';

const DRAFT_KEY = 'bk_draft';
const IDB_NAME = 'bk_drafts';
const IDB_STORE = 'drafts';

// ─── IndexedDB хелперы ───
function openIDB() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(IDB_NAME, 1);
    req.onupgradeneeded = () => { req.result.createObjectStore(IDB_STORE); };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

async function idbGet(key) {
  try {
    const db = await openIDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(IDB_STORE, 'readonly');
      const req = tx.objectStore(IDB_STORE).get(key);
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
  } catch { return undefined; }
}

async function idbSet(key, value) {
  try {
    const db = await openIDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(IDB_STORE, 'readwrite');
      tx.objectStore(IDB_STORE).put(value, key);
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    });
  } catch { /* fallback на localStorage */ }
}

async function idbDelete(key) {
  try {
    const db = await openIDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(IDB_STORE, 'readwrite');
      tx.objectStore(IDB_STORE).delete(key);
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    });
  } catch { /* ignore */ }
}

export const useDraftStore = defineStore('draft', () => {
  const isLoading = ref(false);
  const lastSaved = ref(null);
  const lastPlanSaved = ref(null);
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
    // Не сохраняем если нет товаров
    if (!orderStore.items || orderStore.items.length === 0) return;
    // JSON.stringify автоматически конвертирует Date → ISO строки
    const json = JSON.stringify({
      settings: toRaw(orderStore.settings),
      items: toRaw(orderStore.items),
      timestamp: new Date().toISOString(),
    });
    try { localStorage.setItem(DRAFT_KEY, json); } catch(e) { /* хранилище переполнено */ }
    // В IndexedDB сохраняем распарсенную копию (структурированный клон — без реактивности)
    try { idbSet(DRAFT_KEY, JSON.parse(json)); } catch(e) { /* ignore */ }
    lastSaved.value = new Date();
  }

  function clear() {
    clearTimeout(_timer);
    localStorage.removeItem(DRAFT_KEY);
    idbDelete(DRAFT_KEY);
  }

  /** Восстановить черновик. Возвращает true если черновик был загружен. */
  async function load(supplierLoader) {
    let data = await idbGet(DRAFT_KEY);
    if (!data) {
      const raw = localStorage.getItem(DRAFT_KEY);
      if (!raw) return false;
      try { data = JSON.parse(raw); } catch { return false; }
    }

    try {
      const orderStore = useOrderStore();
      isLoading.value = true;

      // Настройки
      if (data.settings.today) { const d = new Date(data.settings.today); if (!isNaN(d)) orderStore.settings.today = d; }
      if (data.settings.deliveryDate) { const d = new Date(data.settings.deliveryDate); if (!isNaN(d)) orderStore.settings.deliveryDate = d; }
      if (data.settings.safetyEndDate) { const d = new Date(data.settings.safetyEndDate); if (!isNaN(d)) orderStore.settings.safetyEndDate = d; }

      orderStore.settings.legalEntity  = data.settings.legalEntity  || 'ООО "Бургер БК"';
      orderStore.settings.supplier     = data.settings.supplier      || '';
      orderStore.settings.periodDays   = Math.max(1, data.settings.periodDays || 30);
      orderStore.settings.safetyDays   = Math.max(0, data.settings.safetyDays || 0);
      orderStore.settings.unit         = data.settings.unit          || 'pieces';
      orderStore.settings.hasTransit   = data.settings.hasTransit    || false;
      orderStore.settings.showStockColumn = data.settings.showStockColumn ?? true;
      orderStore.settings.note         = data.settings.note          || '';
      if (data.settings.cdaMode !== undefined) orderStore.settings.cdaMode = data.settings.cdaMode;
      if (data.settings.safetyCoef !== undefined) orderStore.settings.safetyCoef = data.settings.safetyCoef;

      // Загружаем поставщиков для юр. лица если передан загрузчик
      if (supplierLoader) {
        await supplierLoader(orderStore.settings.legalEntity);
      }

      // Товары
      orderStore.items = data.items || [];
      orderStore.clearHistory();

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
      return {
        date: new Date(data.timestamp).toLocaleString('ru-RU'),
        itemsCount: data.items.length,
        legalEntity: data.settings?.legalEntity || '',
      };
    } catch { return null; }
  }

  // ═══ ПЛАНИРОВАНИЕ — черновик ═══
  const PLAN_DRAFT_KEY = 'bk_plan_draft';
  let _planTimer = null;

  function savePlan(planState) {
    // Не сохраняем в режиме просмотра
    if (planState.viewOnly) return;
    clearTimeout(_planTimer);
    _planTimer = setTimeout(() => {
      // Не сохраняем если нет товаров
      if (!planState.items || planState.items.length === 0) return;
      const draft = {
        supplier: planState.supplier,
        periodValue: planState.periodValue,
        startDateStr: planState.startDateStr,
        inputUnit: planState.inputUnit,
        consumptionPeriodDays: planState.consumptionPeriodDays,
        editingPlanId: planState.editingPlanId || null,
        items: JSON.parse(JSON.stringify(toRaw(planState.items))),
        timestamp: new Date().toISOString(),
      };
      localStorage.setItem(PLAN_DRAFT_KEY, JSON.stringify(draft));
      lastPlanSaved.value = new Date();
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

  return { isLoading, lastSaved, lastPlanSaved, save, saveNow, clear, load, hasDraft, savePlan, clearPlanDraft, hasPlanDraft, loadPlanDraft };
});
