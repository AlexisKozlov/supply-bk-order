import { defineStore } from 'pinia';
import { ref, computed } from 'vue';

const API_BASE = '/api/ro';
const TOKEN_KEY = 'ro_token';
const REST_KEY = 'ro_restaurant';

function getToken() { return localStorage.getItem(TOKEN_KEY) || ''; }
function buildHeaders() {
  const h = { 'Content-Type': 'application/json' };
  const t = getToken();
  if (t) h['X-RO-Token'] = t;
  // Для admin-запросов нужен основной токен
  const st = localStorage.getItem('bk_session_token');
  if (st) h['X-Session-Token'] = st;
  return h;
}

async function api(path, opts = {}) {
  const url = `${API_BASE}/${path}`;
  const res = await fetch(url, { headers: buildHeaders(), ...opts });
  const data = await res.json();
  if (!res.ok && data.error) throw new Error(data.error);
  return data;
}

export const useRestaurantOrderStore = defineStore('restaurantOrder', () => {
  // === Состояние ресторана ===
  const restaurant = ref(JSON.parse(localStorage.getItem(REST_KEY) || 'null'));
  const isAuthenticated = computed(() => !!restaurant.value && !!getToken());

  const sessionInfo = ref(null);
  const deliveryDays = ref([]);
  const loading = ref(false);

  async function loginByTelegram(tgToken) {
    const data = await api('tg-auth', {
      method: 'POST',
      body: JSON.stringify({ tg_token: tgToken }),
    });
    if (data.success) {
      localStorage.setItem(TOKEN_KEY, data.token);
      localStorage.setItem(REST_KEY, JSON.stringify(data.restaurant));
      restaurant.value = data.restaurant;
    }
    return data;
  }

  async function login(restaurantNumber, password) {
    const data = await api('login', {
      method: 'POST',
      body: JSON.stringify({ restaurant_number: restaurantNumber, password }),
    });
    if (data.success) {
      localStorage.setItem(TOKEN_KEY, data.token);
      localStorage.setItem(REST_KEY, JSON.stringify(data.restaurant));
      restaurant.value = data.restaurant;
    }
    return data;
  }

  async function validate() {
    try {
      const data = await api('validate', { method: 'POST' });
      if (!data.valid) {
        logout();
        return false;
      }
      restaurant.value = data.restaurant;
      localStorage.setItem(REST_KEY, JSON.stringify(data.restaurant));
      return true;
    } catch {
      return false;
    }
  }

  function logout() {
    api('logout', { method: 'POST' }).catch(() => {});
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(REST_KEY);
    restaurant.value = null;
    sessionInfo.value = null;
    deliveryDays.value = [];
  }

  async function loadMyInfo() {
    loading.value = true;
    try {
      const data = await api('my-info');
      sessionInfo.value = data.session;
      deliveryDays.value = data.delivery_days || [];
    } finally {
      loading.value = false;
    }
  }

  async function loadProducts(category, search) {
    const params = new URLSearchParams();
    if (category) params.set('category', category);
    if (search) params.set('search', search);
    const data = await api(`products?${params}`);
    return data.products || [];
  }

  async function loadMyOrder(date) {
    const data = await api(`my-order/${date}`);
    return data.order;
  }

  async function loadMyOrders(limit = 20) {
    const data = await api(`my-orders?limit=${limit}`);
    return data.orders || [];
  }

  async function submitOrder(deliveryDate, items) {
    return await api('submit-order', {
      method: 'POST',
      body: JSON.stringify({ delivery_date: deliveryDate, items }),
    });
  }

  async function repeatOrder(sourceOrderId, deliveryDate) {
    return await api('repeat-order', {
      method: 'POST',
      body: JSON.stringify({ source_order_id: sourceOrderId, delivery_date: deliveryDate }),
    });
  }

  // === Admin (для закупщиков) ===
  async function adminGetStatus(date) {
    return await api(`admin/status?date=${date}`);
  }

  async function adminGetOrder(orderId) {
    const data = await api(`admin/order/${orderId}`);
    return data.order;
  }

  async function adminUpdateOrder(orderId, payload) {
    return await api(`admin/order/${orderId}`, {
      method: 'PATCH',
      body: JSON.stringify(payload),
    });
  }

  async function adminCreateSession(weekStart, weekEnd) {
    return await api('admin/session', {
      method: 'POST',
      body: JSON.stringify({ action: 'create', week_start: weekStart, week_end: weekEnd }),
    });
  }

  async function adminAutoSession() {
    return await api('admin/session', {
      method: 'POST',
      body: JSON.stringify({ action: 'auto' }),
    });
  }

  async function adminCloseSession(sessionId) {
    return await api('admin/session', {
      method: 'POST',
      body: JSON.stringify({ action: 'close', session_id: sessionId }),
    });
  }

  async function adminExtendDeadline(sessionId, deliveryDate, soft, hard) {
    return await api('admin/extend-deadline', {
      method: 'POST',
      body: JSON.stringify({ session_id: sessionId, delivery_date: deliveryDate, soft_deadline: soft, hard_deadline: hard }),
    });
  }

  async function adminGetTemplates(legalEntity, category) {
    const params = new URLSearchParams();
    if (legalEntity) params.set('legal_entity', legalEntity);
    if (category) params.set('category', category);
    const data = await api(`admin/templates?${params}`);
    return data.templates || [];
  }

  async function adminSaveTemplate(legalEntity, category, items) {
    return await api('admin/templates', {
      method: 'POST',
      body: JSON.stringify({ action: 'save', legal_entity: legalEntity, category, items }),
    });
  }

  async function adminImportTemplateFromStock(legalEntity, category) {
    return await api('admin/templates', {
      method: 'POST',
      body: JSON.stringify({ action: 'import-from-stock', legal_entity: legalEntity, category }),
    });
  }

  async function adminGetUsers() {
    const data = await api('admin/users');
    return data.users || [];
  }

  async function adminCreateUser(restaurantNumber, password) {
    return await api('admin/users', {
      method: 'POST',
      body: JSON.stringify({ action: 'create', restaurant_number: restaurantNumber, password }),
    });
  }

  async function adminCreateBulkUsers(password) {
    return await api('admin/users', {
      method: 'POST',
      body: JSON.stringify({ action: 'create-bulk', password }),
    });
  }

  async function adminToggleUser(restaurantNumber, isActive) {
    return await api('admin/users', {
      method: 'POST',
      body: JSON.stringify({ action: 'toggle', restaurant_number: restaurantNumber, is_active: isActive }),
    });
  }

  async function adminResetPassword(restaurantNumber, password) {
    return await api('admin/users', {
      method: 'POST',
      body: JSON.stringify({ action: 'reset-password', restaurant_number: restaurantNumber, password }),
    });
  }

  async function adminDeleteOrder(orderId) {
    return await api(`admin/order/${orderId}`, { method: 'DELETE' });
  }

  async function adminGetExportData(format, date) {
    return await api(`admin/export/${format}?date=${date}`);
  }

  async function adminGetSessions() {
    const data = await api('admin/sessions');
    return data.sessions || [];
  }

  return {
    restaurant, isAuthenticated, sessionInfo, deliveryDays, loading,
    login, loginByTelegram, validate, logout, loadMyInfo, loadProducts, loadMyOrder, loadMyOrders, submitOrder, repeatOrder,
    adminGetStatus, adminGetOrder, adminUpdateOrder,
    adminCreateSession, adminAutoSession, adminCloseSession, adminDeleteOrder,
    adminExtendDeadline, adminGetTemplates, adminSaveTemplate, adminImportTemplateFromStock,
    adminGetUsers, adminCreateUser, adminCreateBulkUsers, adminToggleUser, adminResetPassword,
    adminGetExportData, adminGetSessions,
  };
});
