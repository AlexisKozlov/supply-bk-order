import { defineStore } from 'pinia';
import { ref } from 'vue';

const API_BASE = '/api/so';

function buildHeaders() {
  const h = { 'Content-Type': 'application/json' };
  // Для ресторанов
  const roToken = localStorage.getItem('ro_token');
  if (roToken) h['X-RO-Token'] = roToken;
  // Для закупщиков
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
    return data.order || null;
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

  // ═══ Для закупщиков (admin) ═══

  async function adminGetSuppliers() {
    const data = await api('admin/suppliers');
    return data.suppliers || [];
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

  async function adminGetOrders(supplierId, dateFrom, dateTo) {
    const params = new URLSearchParams();
    if (supplierId) params.set('supplier_id', supplierId);
    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo) params.set('date_to', dateTo);
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
    return { schedules: data.schedules || [], deadlineRules: data.deadline_rules || [] };
  }

  async function adminSaveSchedules(supplierId, schedules) {
    return api('admin/schedules', {
      method: 'POST',
      body: JSON.stringify({ supplier_id: supplierId, schedules }),
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

  async function adminExtendDeadline(supplierId, deliveryDate, deadlineTime) {
    return api('admin/extend-deadline', {
      method: 'POST',
      body: JSON.stringify({ supplier_id: supplierId, delivery_date: deliveryDate, deadline_time: deadlineTime }),
    });
  }

  async function adminRemoveDeadlineOverride(supplierId, deliveryDate) {
    return api('admin/remove-deadline-override', {
      method: 'POST',
      body: JSON.stringify({ supplier_id: supplierId, delivery_date: deliveryDate }),
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

  return {
    loading,
    // Ресторан
    loadSuppliers, loadProducts, loadMyOrder, loadMyOrders, submitOrder,
    // Закупщик
    adminGetSuppliers, adminGetStatus, adminGetOrders, adminGetOrder,
    adminUpdateOrder, adminDeleteOrder,
    adminGetSettings, adminSaveSettings,
    adminGetSchedules, adminSaveSchedules,
    adminGetDeadlineRules, adminSaveDeadlineRules, adminExtendDeadline, adminRemoveDeadlineOverride,
    adminGetTemplates, adminSaveTemplates,
    adminUpdateQty, adminGetExport,
  };
});
