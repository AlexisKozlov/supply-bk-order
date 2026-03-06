import { defineStore } from 'pinia';
import { ref } from 'vue';
import { db } from '@/lib/apiClient.js';
import { useUserStore } from './userStore.js';

const PAGE_SIZE = 50;

export const useHistoryStore = defineStore('history', () => {
  const orders  = ref([]);
  const plans   = ref([]);
  const loading = ref(false);
  const loadingMore = ref(false);
  const error   = ref(null);
  const hasMoreOrders = ref(false);
  const hasMorePlans  = ref(false);

  const userStore  = useUserStore();

  // ─── Общая функция пагинированной загрузки ──────────────────────────────────
  let _requestIds = { orders: 0, plans: 0 };

  async function _loadPaginated(type, { reset, buildQuery }) {
    const listRef = type === 'orders' ? orders : plans;
    const hasMoreRef = type === 'orders' ? hasMoreOrders : hasMorePlans;
    const myRequestId = reset ? ++_requestIds[type] : _requestIds[type];

    if (reset) {
      loading.value = true;
      listRef.value = [];
    } else {
      if (loadingMore.value) return listRef.value;
      loadingMore.value = true;
    }
    error.value = null;

    const offset = reset ? 0 : listRef.value.length;

    try {
      const query = buildQuery(offset);
      const { data, error: err } = await query;

      if (myRequestId !== _requestIds[type]) return listRef.value;
      if (err) { error.value = err; return []; }

      const fetched = data || [];
      hasMoreRef.value = fetched.length >= PAGE_SIZE;

      if (reset) {
        listRef.value = fetched;
      } else {
        listRef.value.push(...fetched);
      }
      return listRef.value;
    } catch (e) {
      error.value = e.message || 'Ошибка загрузки';
      return [];
    } finally {
      loading.value = false;
      loadingMore.value = false;
    }
  }

  // ─── Загрузить историю ────────────────────────────────────────────────────
  async function loadOrders({ legalEntity, supplier = '', type = 'orders', reset = true, dateFrom = '', dateTo = '', author = '', search = '' } = {}) {
    if (type === 'plans') return loadPlans({ legalEntity, supplier, reset, dateFrom, dateTo });

    return _loadPaginated('orders', {
      reset,
      buildQuery: (offset) => {
        let query = db
          .from('orders')
          .select(`id, delivery_date, today_date, supplier, legal_entity,
                   safety_days, period_days, unit, note, created_at, created_by,
                   has_transit, show_stock_column, received_at, updated_at,
                   order_items(sku, name, qty_boxes, qty_per_box, multiplicity, consumption_period, stock, transit)`)
          .order('delivery_date', { ascending: false })
          .limit(PAGE_SIZE)
          .offset(offset);

        if (legalEntity) query = query.eq('legal_entity', legalEntity);
        if (supplier)    query = query.eq('supplier', supplier);
        if (dateFrom)    query = query.gte('delivery_date', dateFrom);
        if (dateTo)      query = query.lte('delivery_date', dateTo);
        if (author)      query = query.eq('created_by', author);
        if (search)      query = query.rawParam('search', search);
        return query;
      },
    });
  }

  // ─── Загрузить историю планов ─────────────────────────────────────────────
  async function loadPlans({ legalEntity, supplier = '', reset = true, dateFrom = '', dateTo = '' } = {}) {
    return _loadPaginated('plans', {
      reset,
      buildQuery: (offset) => {
        let query = db.from('plans').select('*')
          .order('created_at', { ascending: false })
          .limit(PAGE_SIZE)
          .offset(offset);

        if (legalEntity) query = query.eq('legal_entity', legalEntity);
        if (supplier)    query = query.eq('supplier', supplier);
        if (dateFrom)    query = query.gte('created_at', dateFrom);
        if (dateTo)      query = query.lte('created_at', dateTo + ' 23:59:59');
        return query;
      },
    });
  }

  // ─── Подгрузить ещё ────────────────────────────────────────────────────────
  function loadMoreOrders(params) {
    return loadOrders({ ...params, reset: false });
  }
  function loadMorePlans(params) {
    return loadPlans({ ...params, reset: false });
  }

  // ─── Удалить заказ ────────────────────────────────────────────────────────
  async function deleteOrder(orderId) {
    const { error } = await db.rpc('delete_order', { order_id: orderId });
    if (error) return { error: 'Не удалось удалить заказ: ' + error };
    await db.from('audit_log').insert({ action: 'order_deleted', entity_type: 'order', entity_id: orderId, user_name: userStore.currentUser?.name || null, details: {} });
    orders.value = orders.value.filter(o => o.id !== orderId);
    return { success: true };
  }

  // ─── Аудит лог для заказа ────────────────────────────────────────────────
  async function loadOrderLog(orderId) {
    const { data } = await db.from('audit_log').select('*').eq('entity_type', 'order').eq('entity_id', orderId).order('created_at', { ascending: false }).limit(20);
    return data || [];
  }

  return {
    orders, plans, loading, loadingMore, error,
    hasMoreOrders, hasMorePlans,
    loadOrders, loadPlans, loadMoreOrders, loadMorePlans,
    deleteOrder, loadOrderLog,
  };
});
