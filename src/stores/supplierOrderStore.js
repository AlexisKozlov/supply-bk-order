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

  async function submitOrder(supplierId, deliveryDate, orderDate, items) {
    return api('submit-order', {
      method: 'POST',
      body: JSON.stringify({ supplier_id: supplierId, delivery_date: deliveryDate, order_date: orderDate, items }),
    });
  }

  // ═══ Для закупщиков (admin) ═══

  async function adminGetSuppliers() {
    const data = await api('admin/suppliers');
    return data.suppliers || [];
  }

  async function adminGetStatus(supplierId, date, sessionId) {
    const params = new URLSearchParams({ supplier_id: supplierId });
    if (date) params.set('date', date);
    if (sessionId) params.set('session_id', sessionId);
    const data = await api(`admin/status?${params}`);
    return data;
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

  async function adminCreateSession(supplierId, opts = {}) {
    return api('admin/session', {
      method: 'POST',
      body: JSON.stringify({ action: 'create', supplier_id: supplierId, ...opts }),
    });
  }

  async function adminAutoSession(supplierId) {
    return api('admin/session', {
      method: 'POST',
      body: JSON.stringify({ action: 'auto', supplier_id: supplierId }),
    });
  }

  async function adminCloseSession(sessionId, supplierId) {
    return api('admin/session', {
      method: 'POST',
      body: JSON.stringify({ action: 'close', session_id: sessionId, supplier_id: supplierId }),
    });
  }

  async function adminUpdateSession(sessionId, supplierId, updates) {
    return api('admin/session', {
      method: 'POST',
      body: JSON.stringify({ action: 'update', session_id: sessionId, supplier_id: supplierId, ...updates }),
    });
  }

  async function adminReopenSession(sessionId, supplierId) {
    return api('admin/session', {
      method: 'POST',
      body: JSON.stringify({ action: 'reopen', session_id: sessionId, supplier_id: supplierId }),
    });
  }

  async function adminDeleteSession(sessionId, supplierId) {
    return api('admin/session', {
      method: 'POST',
      body: JSON.stringify({ action: 'delete', session_id: sessionId, supplier_id: supplierId }),
    });
  }

  async function adminGetSessions(supplierId) {
    const params = supplierId ? `?supplier_id=${supplierId}` : '';
    const data = await api(`admin/sessions${params}`);
    return data.sessions || [];
  }

  async function adminGetSchedules(supplierId) {
    const data = await api(`admin/schedules?supplier_id=${supplierId}`);
    return data.schedules || [];
  }

  async function adminSaveSchedules(supplierId, schedules) {
    return api('admin/schedules', {
      method: 'POST',
      body: JSON.stringify({ supplier_id: supplierId, schedules }),
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
    adminCreateSession, adminAutoSession, adminCloseSession, adminReopenSession, adminDeleteSession, adminUpdateSession, adminGetSessions,
    adminGetSchedules, adminSaveSchedules,
    adminGetTemplates, adminSaveTemplates,
    adminUpdateQty, adminGetExport,
  };
});
