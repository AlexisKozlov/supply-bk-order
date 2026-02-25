import { defineStore } from 'pinia';
import { ref } from 'vue';
import { db } from '@/lib/apiClient.js';
import { useUserStore } from './userStore.js';

export const useHistoryStore = defineStore('history', () => {
  const orders  = ref([]);
  const loading = ref(false);
  const error   = ref(null);

  const userStore  = useUserStore();

  // ─── Загрузить историю ────────────────────────────────────────────────────
  async function loadOrders({ legalEntity, supplier = '', type = 'orders' } = {}) {
    if (type === 'plans') return loadPlans({ legalEntity, supplier });

    loading.value = true;
    error.value = null;

    try {
      let query = db
        .from('orders')
        .select(`id, delivery_date, today_date, supplier, legal_entity,
                 safety_days, period_days, unit, note, created_at, created_by,
                 has_transit, show_stock_column,
                 order_items(sku, name, qty_boxes, qty_per_box, consumption_period, stock, transit)`)
        .order('delivery_date', { ascending: false })
        .limit(50);

      if (legalEntity) query = query.eq('legal_entity', legalEntity);
      if (supplier)    query = query.eq('supplier', supplier);

      const { data, error: err } = await query;

      if (err) { error.value = err; return []; }

      orders.value = data || [];
      return orders.value;
    } catch (e) {
      error.value = e.message || 'Ошибка загрузки';
      return [];
    } finally {
      loading.value = false;
    }
  }

  // ─── Загрузить историю планов ─────────────────────────────────────────────
  const plans = ref([]);

  async function loadPlans({ legalEntity, supplier = '' } = {}) {
    loading.value = true;
    error.value = null;
    try {
      let query = db.from('plans').select('*').order('created_at', { ascending: false }).limit(50);
      if (legalEntity) query = query.eq('legal_entity', legalEntity);
      if (supplier)    query = query.eq('supplier', supplier);
      const { data, error: err } = await query;
      if (err) { error.value = err; return []; }
      plans.value = data || [];
      return plans.value;
    } catch (e) {
      error.value = e.message || 'Ошибка загрузки';
      return [];
    } finally {
      loading.value = false;
    }
  }

  // ─── Удалить заказ ────────────────────────────────────────────────────────
  async function deleteOrder(orderId) {
    await db.from('order_items').delete().eq('order_id', orderId);
    const { error: err } = await db.from('orders').delete().eq('id', orderId);
    if (err) return { error: err.message };
    await db.from('audit_log').insert({ action: 'order_deleted', entity_type: 'order', entity_id: orderId, user_name: userStore.currentUser?.name || null, details: {} });
    orders.value = orders.value.filter(o => o.id !== orderId);
    return { success: true };
  }

  // ─── Аудит лог для заказа ────────────────────────────────────────────────
  async function loadOrderLog(orderId) {
    const { data } = await db.from('audit_log').select('*').eq('entity_type', 'order').eq('entity_id', orderId).order('created_at', { ascending: false }).limit(20);
    return data || [];
  }

  return { orders, plans, loading, error, loadOrders, loadPlans, deleteOrder, loadOrderLog };
});
