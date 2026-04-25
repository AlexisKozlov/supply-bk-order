import { defineStore } from 'pinia';
import { ref } from 'vue';

const API_BASE = '/api/so';

function buildHeaders() {
  const h = { 'Content-Type': 'application/json' };
  // Для ресторанов
  const roToken = localStorage.getItem('ro_token');
  if (roToken) h['X-RO-Token'] = roToken;
  // Для отдела закупок
  const st = localStorage.getItem('bk_session_token');
  if (st) h['X-Session-Token'] = st;
  return h;
}

async function api(path, opts = {}) {
  const url = `${API_BASE}/${path}`;
  const res = await fetch(url, { headers: buildHeaders(), ...opts });
  const text = await res.text();
  let data;
  try {
    data = text ? JSON.parse(text) : {};
  } catch {
    throw new Error(`Ошибка сервера (${res.status}): ${text.slice(0, 200)}`);
  }
  if (!res.ok) throw new Error(data.error || `Ошибка сервера (${res.status})`);
  return data;
}

export const useSupplierOrderStore = defineStore('supplierOrder', () => {
  const loading = ref(false);

  // ═══ Для ресторанов ═══

  async function loadSuppliers() {
    loading.value = true;
    try {
      const data = await api('suppliers');
      return data.suppliers || [];
    } finally { loading.value = false; }
  }

  async function loadProducts(supplierId) {
    const data = await api(`products/${supplierId}`);
    return data.products || [];
  }

  async function loadMyOrder(supplierId, deliveryDate) {
    const data = await api(`my-order/${supplierId}/${deliveryDate}`);
    return { order: data.order || null, previousOrder: data.previous_order || null };
  }

  async function loadMyOrders(supplierId) {
    const params = supplierId ? `?supplier_id=${supplierId}` : '';
    const data = await api(`my-orders${params}`);
    return data.orders || [];
  }

  async function submitOrder(supplierId, deliveryDate, orderDate, items, opts = {}) {
    return api('submit-order', {
      method: 'POST',
      body: JSON.stringify({
        supplier_id: supplierId,
        delivery_date: deliveryDate,
        order_date: orderDate,
        items,
        skip_delivery: !!opts.skipDelivery,
      }),
    });
  }

  // ═══ Для отдела закупок (admin) ═══

  async function adminGetSuppliers(legalEntity = null) {
    const params = new URLSearchParams();
    if (legalEntity) params.set('legal_entity', legalEntity);
    const data = await api(`admin/suppliers${params.toString() ? '?' + params : ''}`);
    return data.suppliers || [];
  }

  // Список поставщиков группы юрлиц, ещё НЕ подключённых к SO-модулю
  async function adminGetAvailableSuppliers(legalEntity) {
    const params = new URLSearchParams();
    if (legalEntity) params.set('legal_entity', legalEntity);
    const data = await api(`admin/available-suppliers?${params}`);
    return data.suppliers || [];
  }

  // Подключение поставщика к SO-модулю одним запросом (мастер)
  async function adminRegisterSupplier(payload) {
    return api('admin/register-supplier', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  // Отключение (not delete) — просто снимает флаг so_enabled
  async function adminDisconnectSupplier(supplierId) {
    return api('admin/disconnect-supplier', {
      method: 'POST',
      body: JSON.stringify({ supplier_id: supplierId }),
    });
  }

  async function adminGetStatus(supplierId, date) {
    const params = new URLSearchParams({ supplier_id: supplierId });
    if (date) params.set('date', date);
    const data = await api(`admin/status?${params}`);
    return data;
  }

  async function adminGetSettings(supplierId) {
    const data = await api(`admin/settings?supplier_id=${supplierId}`);
    return data;
  }

  async function adminSaveSettings(supplierId, payload) {
    return api('admin/settings', {
      method: 'POST',
      body: JSON.stringify({ supplier_id: supplierId, ...payload }),
    });
  }

  async function adminGetOrders(supplierId, filters = {}) {
    const params = new URLSearchParams();
    if (supplierId) params.set('supplier_id', supplierId);
    if (filters.submitted_from) params.set('submitted_from', filters.submitted_from);
    if (filters.submitted_to) params.set('submitted_to', filters.submitted_to);
    if (filters.delivery_from) params.set('delivery_from', filters.delivery_from);
    if (filters.delivery_to) params.set('delivery_to', filters.delivery_to);
    if (filters.status) params.set('status', filters.status);
    if (filters.query) params.set('query', filters.query);
    if (filters.skip_only) params.set('skip_only', '1');
    const data = await api(`admin/orders?${params}`);
    return data.orders || [];
  }

  async function adminGetOrder(orderId) {
    const data = await api(`admin/order/${orderId}`);
    return data.order || null;
  }

  async function adminUpdateOrder(orderId, updates) {
    return api(`admin/order/${orderId}`, {
      method: 'PATCH',
      body: JSON.stringify(updates),
    });
  }

  async function adminDeleteOrder(orderId) {
    return api(`admin/order/${orderId}`, { method: 'DELETE' });
  }

  async function adminGetSchedules(supplierId) {
    const data = await api(`admin/schedules?supplier_id=${supplierId}`);
    return {
      schedules: data.schedules || [],
      temporarySchedule: data.temporary_schedule || null,
      deadlineRules: data.deadline_rules || [],
    };
  }

  async function adminSaveSchedules(supplierId, schedules, temporarySchedule = null) {
    return api('admin/schedules', {
      method: 'POST',
      body: JSON.stringify({ supplier_id: supplierId, schedules, temporary_schedule: temporarySchedule }),
    });
  }

  async function adminGetDeadlineRules(supplierId) {
    const data = await api(`admin/deadline-rules?supplier_id=${supplierId}`);
    return data.rules || [];
  }

  async function adminSaveDeadlineRules(supplierId, rules) {
    return api('admin/deadline-rules', {
      method: 'POST',
      body: JSON.stringify({ supplier_id: supplierId, rules }),
    });
  }

  async function adminExtendDeadline(supplierId, deliveryDate, deadlineTime, deadlineDate = null) {
    return api('admin/extend-deadline', {
      method: 'POST',
      body: JSON.stringify({ supplier_id: supplierId, delivery_date: deliveryDate, deadline_time: deadlineTime, deadline_date: deadlineDate }),
    });
  }

  async function adminRemoveDeadlineOverride(supplierId, deliveryDate) {
    return api('admin/remove-deadline-override', {
      method: 'POST',
      body: JSON.stringify({ supplier_id: supplierId, delivery_date: deliveryDate }),
    });
  }

  async function adminCloseDay(supplierId, deliveryDate, isClosed = true) {
    return api('admin/close-day', {
      method: 'POST',
      body: JSON.stringify({ supplier_id: supplierId, delivery_date: deliveryDate, is_closed: isClosed ? 1 : 0 }),
    });
  }

  async function adminGetTemplates(supplierId, legalEntity) {
    const params = new URLSearchParams({ supplier_id: supplierId });
    if (legalEntity) params.set('legal_entity', legalEntity);
    const data = await api(`admin/templates?${params}`);
    return data.templates || [];
  }

  async function adminSaveTemplates(supplierId, legalEntity, items) {
    return api('admin/templates', {
      method: 'POST',
      body: JSON.stringify({ supplier_id: supplierId, legal_entity: legalEntity, items }),
    });
  }

  async function adminUpdateQty(params) {
    return api('admin/update-qty', {
      method: 'POST',
      body: JSON.stringify(params),
    });
  }

  async function adminGetExport(supplierId, date) {
    const data = await api(`admin/export?supplier_id=${supplierId}&date=${date}`);
    return data;
  }

  async function adminSendSummary(supplierId, deliveryDate) {
    return api('admin/send-summary', {
      method: 'POST',
      body: JSON.stringify({ supplier_id: supplierId, delivery_date: deliveryDate }),
    });
  }

  return {
    loading,
    // Ресторан
    loadSuppliers, loadProducts, loadMyOrder, loadMyOrders, submitOrder,
    // Отдел закупок
    adminGetSuppliers, adminGetAvailableSuppliers, adminRegisterSupplier, adminDisconnectSupplier,
    adminGetStatus, adminGetOrders, adminGetOrder,
    adminUpdateOrder, adminDeleteOrder,
    adminGetSettings, adminSaveSettings,
    adminGetSchedules, adminSaveSchedules,
    adminGetDeadlineRules, adminSaveDeadlineRules, adminExtendDeadline, adminRemoveDeadlineOverride, adminCloseDay,
    adminGetTemplates, adminSaveTemplates,
    adminUpdateQty, adminGetExport, adminSendSummary,
  };
});
