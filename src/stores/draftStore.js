import { defineStore } from 'pinia';
import { ref, toRaw } from 'vue';
import { useOrderStore } from './orderStore.js';
import { useToastStore } from './toastStore.js';
import { DEFAULT_ENTITY } from '@/lib/legalEntities.js';

const DRAFT_KEY_PREFIX = 'bk_draft';
const IDB_NAME = 'bk_drafts';
const IDB_STORE = 'drafts';
const IDB_SYNC_STORE = 'sync_queue';

function getDraftKey(orderStore) {
  const le = orderStore?.settings?.legalEntity || '';
  return le ? `${DRAFT_KEY_PREFIX}_${le}` : DRAFT_KEY_PREFIX;
}

// ─── IndexedDB хелперы ───
function openIDB() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(IDB_NAME, 2);
    req.onupgradeneeded = (e) => {
      const db = req.result;
      if (!db.objectStoreNames.contains(IDB_STORE)) {
        db.createObjectStore(IDB_STORE);
      }
      if (!db.objectStoreNames.contains(IDB_SYNC_STORE)) {
        db.createObjectStore(IDB_SYNC_STORE, { keyPath: 'id' });
      }
    };
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
      req.onsuccess = () => { db.close(); resolve(req.result); };
      req.onerror = () => { db.close(); reject(req.error); };
    });
  } catch { return undefined; }
}

async function idbSet(key, value) {
  try {
    const db = await openIDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(IDB_STORE, 'readwrite');
      tx.objectStore(IDB_STORE).put(value, key);
      tx.oncomplete = () => { db.close(); resolve(); };
      tx.onerror = () => { db.close(); reject(tx.error); };
    });
  } catch { /* fallback на localStorage */ }
}

async function idbDelete(key) {
  try {
    const db = await openIDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(IDB_STORE, 'readwrite');
      tx.objectStore(IDB_STORE).delete(key);
      tx.oncomplete = () => { db.close(); resolve(); };
      tx.onerror = () => { db.close(); reject(tx.error); };
    });
  } catch { /* ignore */ }
}

export const useDraftStore = defineStore('draft', () => {
  const isLoading = ref(false);
  const lastSaved = ref(null);
  const lastPlanSaved = ref(null);
  let _timer = null;
  let _quotaWarningShown = false;

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
    const key = getDraftKey(orderStore);
    // JSON.stringify автоматически конвертирует Date → ISO строки
    const json = JSON.stringify({
      settings: toRaw(orderStore.settings),
      items: toRaw(orderStore.items),
      timestamp: new Date().toISOString(),
    });
    let lsSaved = true;
    try { localStorage.setItem(key, json); } catch(e) {
      lsSaved = false;
      if (!_quotaWarningShown) {
        _quotaWarningShown = true;
        try {
          const toast = useToastStore();
          toast.warning('Внимание', 'Не удалось сохранить черновик — хранилище заполнено');
        } catch (_) { /* toast store не доступен */ }
      }
    }
    // В IndexedDB сохраняем распарсенную копию (структурированный клон — без реактивности)
    idbSet(key, JSON.parse(json)).catch(() => { /* ignore */ });
    if (lsSaved) lastSaved.value = new Date();
  }

  function clear() {
    clearTimeout(_timer);
    const orderStore = useOrderStore();
    const key = getDraftKey(orderStore);
    localStorage.removeItem(key);
    idbDelete(key).catch(() => {});
  }

  /** Восстановить черновик. Возвращает true если черновик был загружен. */
  async function load(supplierLoader) {
    const orderStore = useOrderStore();
    const key = getDraftKey(orderStore);
    let data = await idbGet(key);
    if (!data) {
      const raw = localStorage.getItem(key);
      if (!raw) return false;
      try { data = JSON.parse(raw); } catch { return false; }
    }

    try {
      isLoading.value = true;

      // Настройки
      if (data.settings.today) { const v = data.settings.today; const d = new Date(typeof v === 'string' && v.length === 10 ? v + 'T00:00:00' : v); if (!isNaN(d)) orderStore.settings.today = d; }
      if (data.settings.deliveryDate) { const v = data.settings.deliveryDate; const d = new Date(typeof v === 'string' && v.length === 10 ? v + 'T00:00:00' : v); if (!isNaN(d)) orderStore.settings.deliveryDate = d; }
      if (data.settings.safetyEndDate) { const v = data.settings.safetyEndDate; const d = new Date(typeof v === 'string' && v.length === 10 ? v + 'T00:00:00' : v); if (!isNaN(d)) orderStore.settings.safetyEndDate = d; }

      orderStore.settings.legalEntity  = data.settings.legalEntity  || DEFAULT_ENTITY;
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
    const orderStore = useOrderStore();
    const key = getDraftKey(orderStore);
    const raw = localStorage.getItem(key);
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
  const PLAN_DRAFT_PREFIX = 'bk_plan_draft';
  let _planTimer = null;

  function getPlanDraftKey() {
    const orderStore = useOrderStore();
    const le = orderStore?.settings?.legalEntity || '';
    return le ? `${PLAN_DRAFT_PREFIX}_${le}` : PLAN_DRAFT_PREFIX;
  }

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
        planningDateStr: planState.planningDateStr || '',
        inputUnit: planState.inputUnit,
        consumptionPeriodDays: planState.consumptionPeriodDays,
        editingPlanId: planState.editingPlanId || null,
        items: JSON.parse(JSON.stringify(toRaw(planState.items))),
        timestamp: new Date().toISOString(),
      };
      try {
        localStorage.setItem(getPlanDraftKey(), JSON.stringify(draft));
        lastPlanSaved.value = new Date();
      } catch (e) {
        console.warn('Plan draft save failed (quota?):', e);
      }
    }, 500);
  }

  function clearPlanDraft() {
    clearTimeout(_planTimer);
    localStorage.removeItem(getPlanDraftKey());
  }

  function hasPlanDraft() {
    const raw = localStorage.getItem(getPlanDraftKey());
    if (!raw) return null;
    try {
      const data = JSON.parse(raw);
      if (!data.items || data.items.length === 0) return null;
      return { date: new Date(data.timestamp).toLocaleString('ru-RU'), itemsCount: data.items.length, supplier: data.supplier };
    } catch { return null; }
  }

  function loadPlanDraft() {
    const raw = localStorage.getItem(getPlanDraftKey());
    if (!raw) return null;
    try { return JSON.parse(raw); } catch { return null; }
  }

  // ═══ ОЧЕРЕДЬ СИНХРОНИЗАЦИИ (offline) ═══

  async function addToSyncQueue(operation) {
    try {
      const db = await openIDB();
      const entry = {
        id: Date.now() + '_' + Math.random().toString(36).slice(2, 8),
        timestamp: new Date().toISOString(),
        method: operation.method,
        url: operation.url,
        body: operation.body || null,
      };
      return new Promise((resolve, reject) => {
        const tx = db.transaction(IDB_SYNC_STORE, 'readwrite');
        tx.objectStore(IDB_SYNC_STORE).add(entry);
        tx.oncomplete = () => { db.close(); resolve(entry.id); };
        tx.onerror = () => { db.close(); reject(tx.error); };
      });
    } catch (e) {
      console.error('Ошибка добавления в очередь синхронизации:', e);
    }
  }

  async function getSyncQueueCount() {
    try {
      const db = await openIDB();
      return new Promise((resolve, reject) => {
        const tx = db.transaction(IDB_SYNC_STORE, 'readonly');
        const req = tx.objectStore(IDB_SYNC_STORE).count();
        req.onsuccess = () => { db.close(); resolve(req.result); };
        req.onerror = () => { db.close(); resolve(0); };
      });
    } catch { return 0; }
  }

  async function processSyncQueue() {
    if (!navigator.onLine) return;
    try {
      const db = await openIDB();
      const entries = await new Promise((resolve, reject) => {
        const tx = db.transaction(IDB_SYNC_STORE, 'readonly');
        const req = tx.objectStore(IDB_SYNC_STORE).getAll();
        req.onsuccess = () => resolve(req.result || []);
        req.onerror = () => resolve([]);
      });

      // Обрабатываем по порядку
      for (const entry of entries.sort((a, b) => a.id.localeCompare(b.id))) {
        try {
          const headers = { 'Content-Type': 'application/json' };
          const sessionToken = localStorage.getItem('bk_session_token');
          if (sessionToken) headers['X-Session-Token'] = sessionToken;
          const fetchOpts = { method: entry.method, headers };
          if (entry.body) fetchOpts.body = JSON.stringify(entry.body);
          const resp = await fetch(entry.url, fetchOpts);
          if (resp.ok) {
            // Удаляем успешно обработанную запись
            const delDb = await openIDB();
            await new Promise((resolve, reject) => {
              const tx = delDb.transaction(IDB_SYNC_STORE, 'readwrite');
              tx.objectStore(IDB_SYNC_STORE).delete(entry.id);
              tx.oncomplete = () => { delDb.close(); resolve(); };
              tx.onerror = () => { delDb.close(); resolve(); };
            });
          }
        } catch {
          // Если запрос не удался — прекращаем обработку очереди
          break;
        }
      }
      db.close();
    } catch (e) {
      console.error('Ошибка обработки очереди синхронизации:', e);
    }
  }

  // Автоматическая обработка очереди при восстановлении соединения
  if (typeof window !== 'undefined') {
    window.addEventListener('online', () => {
      processSyncQueue();
    });
  }

  return { isLoading, lastSaved, lastPlanSaved, save, saveNow, clear, load, hasDraft, savePlan, clearPlanDraft, hasPlanDraft, loadPlanDraft, addToSyncQueue, getSyncQueueCount, processSyncQueue };
});
