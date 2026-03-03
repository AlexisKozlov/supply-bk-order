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
  let _loadRequestId = 0;

  const userStore  = useUserStore();

  // ─── Загрузить историю ────────────────────────────────────────────────────
  async function loadOrders({ legalEntity, supplier = '', type = 'orders', reset = true, dateFrom = '', dateTo = '', author = '', search = '' } = {}) {
    if (type === 'plans') return loadPlans({ legalEntity, supplier, reset, dateFrom, dateTo });

    const myRequestId = reset ? ++_loadRequestId : _loadRequestId;

    if (reset) {
      loading.value = true;
      orders.value = [];
    } else {
      if (loadingMore.value) return orders.value;
      loadingMore.value = true;
    }
    error.value = null;

    const offset = reset ? 0 : orders.value.length;

    try {
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

      const { data, error: err } = await query;

      if (myRequestId !== _loadRequestId) return orders.value;
      if (err) { error.value = err; return []; }

      const fetched = data || [];
      hasMoreOrders.value = fetched.length >= PAGE_SIZE;

      if (reset) {
        orders.value = fetched;
      } else {
        orders.value.push(...fetched);
      }
      return orders.value;
    } catch (e) {
      error.value = e.message || 'Ошибка загрузки';
      return [];
    } finally {
      loading.value = false;
      loadingMore.value = false;
    }
  }

  // ─── Загрузить историю планов ─────────────────────────────────────────────
  let _planRequestId = 0;

  async function loadPlans({ legalEntity, supplier = '', reset = true, dateFrom = '', dateTo = '' } = {}) {
    const myRequestId = reset ? ++_planRequestId : _planRequestId;

    if (reset) {
      loading.value = true;
      plans.value = [];
    } else {
      if (loadingMore.value) return plans.value;
      loadingMore.value = true;
    }
    error.value = null;

    const offset = reset ? 0 : plans.value.length;

    try {
      let query = db.from('plans').select('*')
        .order('created_at', { ascending: false })
        .limit(PAGE_SIZE)
        .offset(offset);

      if (legalEntity) query = query.eq('legal_entity', legalEntity);
      if (supplier)    query = query.eq('supplier', supplier);
      if (dateFrom)    query = query.gte('created_at', dateFrom);
      if (dateTo)      query = query.lte('created_at', dateTo + ' 23:59:59');

      const { data, error: err } = await query;
      if (myRequestId !== _planRequestId) return plans.value;
      if (err) { error.value = err; return []; }

      const fetched = data || [];
      hasMorePlans.value = fetched.length >= PAGE_SIZE;

      if (reset) {
        plans.value = fetched;
      } else {
        plans.value.push(...fetched);
      }
      return plans.value;
    } catch (e) {
      error.value = e.message || 'Ошибка загрузки';
      return [];
    } finally {
      loading.value = false;
      loadingMore.value = false;
    }
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
    const { error: itemsErr } = await db.from('order_items').delete().eq('order_id', orderId);
    if (itemsErr) return { error: 'Не удалось удалить позиции заказа: ' + itemsErr };
    const { error: err } = await db.from('orders').delete().eq('id', orderId);
    if (err) return { error: err };
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
