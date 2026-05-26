/**
 * useDistributionSession — управление модулем «Распределение».
 *
 * Один composable на список сессий + детали активной + матрицу клеток.
 * Не управляет UI-only state (модалки, поиск товаров) — это остаётся
 * в DistributionView.vue.
 *
 * Зачем выделено: до распиливания всё жило в DistributionView.vue
 * (1056 строк), теперь сама бизнес-логика отвязана от шаблона и её
 * можно использовать в нескольких компонентах (Table + Filters).
 */

import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue';
import { db } from '@/lib/apiClient.js';

export function useDistributionSession({ toastStore, legalEntityRef, isEditingRef }) {
  // ═══ State ═══
  const loading = ref(false);
  const sessions = ref([]);
  const activeSession = ref(null);
  const sessionData = ref(null);

  // Фильтры (хранятся здесь, чтобы filteredRestaurants и другие computeds сами их учитывали)
  const regionFilter = ref('');
  const cityFilter = ref('');
  const hideProcessed = ref(false);
  const dayFilter = ref(0);

  // Карта клеток матрицы: key = "spId_restNum" → { shipped, qty, version, updated_by }
  const entriesMap = reactive({});
  // Карта примечаний: restaurant_number → текст
  const notesMap = reactive({});

  // Undo stack (20 последних действий)
  const UNDO_LIMIT = 20;
  const undoStack = ref([]);
  function pushUndo(action) {
    undoStack.value.push(action);
    if (undoStack.value.length > UNDO_LIMIT) undoStack.value.shift();
  }

  // ═══ Computed ═══
  const sessionProducts = computed(() => sessionData.value?.products || []);
  const allRestaurants = computed(() => sessionData.value?.restaurants || []);

  function getRegion(r) { return r.region || 'Другое'; }
  function isVM(r) { return String(r.number) === '3'; }

  const regions = computed(() => {
    const set = new Set();
    for (const r of allRestaurants.value) {
      const region = getRegion(r);
      if (region) set.add(region);
    }
    return [...set].sort();
  });

  const cities = computed(() => {
    const set = new Set();
    let rests = allRestaurants.value;
    if (regionFilter.value) rests = rests.filter(r => getRegion(r) === regionFilter.value);
    for (const r of rests) if (r.city) set.add(r.city);
    return [...set].sort();
  });

  const availableDays = computed(() => {
    const days = new Set();
    for (const r of allRestaurants.value) {
      if (r.delivery_days) for (const d of r.delivery_days) days.add(d);
    }
    return [...days].sort((a, b) => a - b);
  });

  function getDayCount(day) {
    return allRestaurants.value.filter(r => r.delivery_days?.includes(day)).length;
  }

  const filteredRestaurants = computed(() => {
    let rests = allRestaurants.value;
    if (dayFilter.value) rests = rests.filter(r => r.delivery_days?.includes(dayFilter.value));
    if (regionFilter.value) rests = rests.filter(r => getRegion(r) === regionFilter.value);
    if (cityFilter.value) rests = rests.filter(r => r.city === cityFilter.value);
    if (hideProcessed.value) {
      // Прячем ресторан только если по ВСЕМ товарам уже принято решение
      rests = rests.filter(r => sessionProducts.value.some(p => getCellStatus(r.number, p.id) === 0));
    }
    return rests;
  });

  const totalShipped = computed(() => {
    let c = 0;
    for (const k in entriesMap) if (entriesMap[k]?.shipped === 1) c++;
    return c;
  });

  const totalNotNeeded = computed(() => {
    let c = 0;
    for (const k in entriesMap) if (entriesMap[k]?.shipped === 2) c++;
    return c;
  });

  const totalProcessed = computed(() => totalShipped.value + totalNotNeeded.value);
  const totalCells = computed(() => allRestaurants.value.length * sessionProducts.value.length);
  const progressPct = computed(() => totalCells.value ? Math.round(totalProcessed.value / totalCells.value * 100) : 0);

  // ═══ Helpers ═══
  function entryKey(restNum, spId) { return `${spId}_${restNum}`; }
  function getEntry(restNum, spId) { return entriesMap[entryKey(restNum, spId)] || null; }
  function getEntryQty(restNum, spId) { return getEntry(restNum, spId)?.qty ?? null; }
  function hasCustomQty(restNum, spId) {
    const e = getEntry(restNum, spId);
    return e && e.qty !== null && e.qty !== undefined;
  }
  function getCellStatus(restNum, spId) { return getEntry(restNum, spId)?.shipped || 0; }
  function cellClass(restNum, spId) {
    const s = getCellStatus(restNum, spId);
    return {
      'td-shipped': s === 1,
      'td-crossed': s === 2,
      'td-custom': hasCustomQty(restNum, spId),
    };
  }
  function getProductShippedCount(spId) {
    let c = 0;
    for (const r of filteredRestaurants.value) if (getCellStatus(r.number, spId) === 1) c++;
    return c;
  }
  function getProductCrossedCount(spId) {
    let c = 0;
    for (const r of filteredRestaurants.value) if (getCellStatus(r.number, spId) === 2) c++;
    return c;
  }

  // ═══ Load ═══
  async function loadSessions() {
    loading.value = true;
    try {
      const { data } = await db.rpc('dist_get_sessions', { legal_entity: legalEntityRef.value });
      sessions.value = data || [];
    } catch (e) { console.warn('[dist]', e); }
    finally { loading.value = false; }
  }

  let refreshInterval = null;
  async function openSession(s) {
    activeSession.value = s;
    await loadSessionData(s.id);
    startAutoRefresh();
  }

  function startAutoRefresh() {
    stopAutoRefresh();
    refreshInterval = setInterval(() => {
      if (typeof document !== 'undefined' && document.visibilityState !== 'visible') return;
      // Не дёргаем сервер, пока пользователь печатает в модалке/инпуте
      if (activeSession.value && !isEditingRef?.value) {
        loadSessionData(activeSession.value.id);
      }
    }, 10000);
  }

  function stopAutoRefresh() {
    if (refreshInterval) { clearInterval(refreshInterval); refreshInterval = null; }
  }

  async function loadSessionData(id) {
    try {
      const { data } = await db.rpc('dist_get_session_data', { session_id: id });
      sessionData.value = data;
      for (const k in entriesMap) delete entriesMap[k];
      for (const e of (data?.entries || [])) {
        const k = entryKey(e.restaurant_number, e.session_product_id);
        entriesMap[k] = {
          id: e.id,
          shipped: parseInt(e.shipped) || 0,
          qty: e.qty ?? null,
          version: parseInt(e.version) || 1,
          updated_by: e.updated_by || null,
        };
      }
      for (const k in notesMap) delete notesMap[k];
      if (data?.notes) for (const [rn, n] of Object.entries(data.notes)) notesMap[rn] = n;
    } catch (e) { console.warn('[dist] load session data', e); }
  }

  function closeDetail() {
    stopAutoRefresh();
    activeSession.value = null;
    sessionData.value = null;
    for (const k in entriesMap) delete entriesMap[k];
    for (const k in notesMap) delete notesMap[k];
    loadSessions();
  }

  // ═══ Mutations: клетки ═══
  function markShipped(restNum, product) {
    const cur = getCellStatus(restNum, product.id);
    setStatus(restNum, product, cur === 1 ? 0 : 1);
  }
  function markCrossed(restNum, product) {
    const cur = getCellStatus(restNum, product.id);
    setStatus(restNum, product, cur === 2 ? 0 : 2);
  }
  async function setStatus(restNum, product, next) {
    if (activeSession.value?.status === 'closed') return;
    const k = entryKey(restNum, product.id);
    if (!entriesMap[k]) entriesMap[k] = { shipped: 0, qty: null, version: 0 };
    const prev = entriesMap[k].shipped;
    const prevVersion = entriesMap[k].version || 0;
    if (prev === next) return;
    entriesMap[k].shipped = next;
    const { data, error } = await db.rpc('dist_toggle_shipped', {
      session_product_id: product.id,
      restaurant_number: String(restNum),
      shipped: next,
      version: prevVersion,
    });
    if (error === 'conflict') {
      toastStore?.show('Кто-то изменил эту клетку, обновляю', 'warning');
      await loadSessionData(activeSession.value.id);
      return;
    }
    if (error) {
      entriesMap[k].shipped = prev;
      toastStore?.show('Ошибка сохранения', 'error');
      return;
    }
    if (data?.version) entriesMap[k].version = data.version;
    pushUndo({ type: 'shipped', restNum: String(restNum), spId: product.id, prev, next });
  }

  async function saveQty(restNum, spId, val) {
    const k = entryKey(restNum, spId);
    if (!entriesMap[k]) entriesMap[k] = { shipped: 0, qty: null, version: 0 };
    const prevQty = entriesMap[k].qty;
    const prevVersion = entriesMap[k].version || 0;
    entriesMap[k].qty = val;
    const { data, error } = await db.rpc('dist_update_qty', {
      session_product_id: spId,
      restaurant_number: restNum,
      qty: val,
      version: prevVersion,
    });
    if (error === 'conflict') {
      toastStore?.show('Кто-то изменил эту клетку, обновляю', 'warning');
      await loadSessionData(activeSession.value.id);
      return;
    }
    if (error) {
      entriesMap[k].qty = prevQty;
      toastStore?.show('Ошибка', 'error');
      return;
    }
    if (data?.version) entriesMap[k].version = data.version;
    pushUndo({ type: 'qty', restNum, spId, prev: prevQty, next: val });
  }

  async function saveNote(restNum, note) {
    if (!activeSession.value) return;
    notesMap[restNum] = note;
    const { error } = await db.rpc('dist_save_note', {
      session_id: activeSession.value.id,
      restaurant_number: restNum,
      note,
    });
    if (error) toastStore?.show('Ошибка', 'error');
  }

  // ═══ Undo ═══
  async function undo() {
    if (!activeSession.value || activeSession.value.status === 'closed') return;
    const action = undoStack.value.pop();
    if (!action) return;
    const k = entryKey(action.restNum, action.spId);
    const ver = entriesMap[k]?.version || 0;
    if (action.type === 'shipped') {
      if (entriesMap[k]) entriesMap[k].shipped = action.prev;
      const { error } = await db.rpc('dist_toggle_shipped', {
        session_product_id: action.spId,
        restaurant_number: action.restNum,
        shipped: action.prev,
        version: ver,
      });
      if (error) toastStore?.show('Не удалось отменить', 'error');
      else await loadSessionData(activeSession.value.id);
    } else if (action.type === 'qty') {
      if (entriesMap[k]) entriesMap[k].qty = action.prev;
      const { error } = await db.rpc('dist_update_qty', {
        session_product_id: action.spId,
        restaurant_number: action.restNum,
        qty: action.prev,
        version: ver,
      });
      if (error) toastStore?.show('Не удалось отменить', 'error');
      else await loadSessionData(activeSession.value.id);
    }
  }

  // ═══ Жизненный цикл ═══
  onMounted(loadSessions);
  onUnmounted(stopAutoRefresh);

  // При смене юрлица — закрываем сессию (она может быть другой группы) и перегружаем список
  watch(legalEntityRef, async () => {
    if (activeSession.value) closeDetail();
    await loadSessions();
  });

  return {
    // state
    loading, sessions, activeSession, sessionData,
    regionFilter, cityFilter, hideProcessed, dayFilter,
    entriesMap, notesMap, undoStack,
    // computed
    sessionProducts, allRestaurants, regions, cities, availableDays,
    filteredRestaurants, totalShipped, totalNotNeeded, totalProcessed, totalCells, progressPct,
    // helpers
    isVM, getDayCount, entryKey, getEntry, getEntryQty, hasCustomQty,
    getCellStatus, cellClass, getProductShippedCount, getProductCrossedCount,
    // lifecycle
    loadSessions, openSession, loadSessionData, closeDetail,
    // mutations
    markShipped, markCrossed, setStatus, saveQty, saveNote, undo,
  };
}
