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
  // При 401 — сессия невалидна, выкидываем на логин
  if (res.status === 401 && !path.startsWith('admin')) {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(REST_KEY);
    if (window.location.pathname.startsWith('/ro') || window.location.pathname.startsWith('/restaurant')) {
      window.location.href = '/restaurant/login';
    }
    throw new Error('Сессия завершена');
  }
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

  async function login(restaurantNumber, password, force = false) {
    const data = await api('login', {
      method: 'POST',
      body: JSON.stringify({ restaurant_number: restaurantNumber, password, force }),
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
    logoutLocal();
  }

  // Локальный выход: чистит только клиентское состояние, не обращаясь к серверу.
  // Нужен при входе по tg_token — чтобы не убить активную сессию того же ресторана
  // на другом устройстве (сервер сам перезапишет session_token в tg-auth).
  function logoutLocal() {
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

  async function submitOrder(deliveryDate, items, comment = null) {
    return await api('submit-order', {
      method: 'POST',
      body: JSON.stringify({ delivery_date: deliveryDate, items, comment }),
    });
  }

  async function repeatOrder(sourceOrderId, deliveryDate) {
    return await api('repeat-order', {
      method: 'POST',
      body: JSON.stringify({ source_order_id: sourceOrderId, delivery_date: deliveryDate }),
    });
  }

  // === Admin (для закупщиков) ===
  async function adminGetStatus(date, legalEntity = null) {
    const params = new URLSearchParams({ date });
    if (legalEntity) params.set('legal_entity', legalEntity);
    return await api(`admin/status?${params}`);
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

  async function adminToggleDate(sessionId, deliveryDate, isOpen) {
    return await api('admin/toggle-date', {
      method: 'POST',
      body: JSON.stringify({ session_id: sessionId, delivery_date: deliveryDate, is_open: isOpen }),
    });
  }

  async function adminGetOpenDates(sessionId) {
    const params = sessionId ? `?session_id=${sessionId}` : '';
    const data = await api(`admin/open-dates${params}`);
    return data.dates || [];
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

  async function adminSearchProducts(legalEntity, search) {
    const params = new URLSearchParams();
    if (legalEntity) params.set('legal_entity', legalEntity);
    if (search) params.set('search', search);
    const data = await api(`admin/products?${params}`);
    return data.products || [];
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

  async function adminCreateBulkUsers(password, mode = 'missing') {
    return await api('admin/users', {
      method: 'POST',
      body: JSON.stringify({ action: 'create-bulk', password, mode }),
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

  async function adminDeleteItem(itemId, orderId = null, sku = null) {
    const params = new URLSearchParams();
    if (orderId) params.set('order_id', orderId);
    if (sku) params.set('sku', sku);
    const qs = params.toString() ? `?${params}` : '';
    return await api(`admin/item/${itemId}${qs}`, { method: 'DELETE' });
  }

  // === Журнал изменений ===
  async function adminGetAuditLog(filters = {}) {
    const params = new URLSearchParams();
    if (filters.dateFrom) params.set('date_from', filters.dateFrom);
    if (filters.dateTo) params.set('date_to', filters.dateTo);
    if (filters.restaurant) params.set('restaurant', filters.restaurant);
    if (filters.actor) params.set('actor', filters.actor);
    if (filters.action) params.set('action', filters.action);
    if (filters.search) params.set('search', filters.search);
    if (filters.legalEntity) params.set('legal_entity', filters.legalEntity);
    if (filters.limit) params.set('limit', filters.limit);
    if (filters.offset) params.set('offset', filters.offset);
    return await api(`admin/audit?${params}`);
  }

  async function adminGetOrderHistory(orderId) {
    const data = await api(`admin/audit/${orderId}`);
    return data.events || [];
  }

  // === Остатки склада ===
  async function adminUploadStock(file, balanceDate) {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('balance_date', balanceDate);
    const res = await fetch(`${API_BASE}/admin/stock-upload`, {
      method: 'POST',
      headers: {
        'X-Session-Token': localStorage.getItem('bk_session_token') || '',
      },
      body: formData,
    });
    const data = await res.json();
    if (!res.ok && data.error) throw new Error(data.error);
    return data;
  }

  async function adminGetStockBalances(balanceDate, deliveryDate, legalEntity = '') {
    let url = `admin/stock-balances?date=${balanceDate}&delivery_date=${deliveryDate}`;
    if (legalEntity) url += `&legal_entity=${encodeURIComponent(legalEntity)}`;
    return await api(url);
  }

  async function adminGetStockDates() {
    const data = await api('admin/stock-dates');
    return data.dates || [];
  }

  // === Личный кабинет ресторана ===
  async function loadAllHistory(limit = 30) {
    const data = await api(`all-history?limit=${limit}`);
    return data.orders || [];
  }

  async function changePassword(oldPassword, newPassword) {
    return await api('change-password', {
      method: 'POST',
      body: JSON.stringify({ old_password: oldPassword, new_password: newPassword }),
    });
  }

  async function getTelegramStatus() {
    return await api('telegram-status');
  }

  async function telegramLink() {
    return await api('telegram-link', { method: 'POST' });
  }

  async function telegramUnlink() {
    return await api('telegram-unlink', { method: 'POST' });
  }

  async function getStockCollectionStatus() {
    return await api('stock-collection-status');
  }

  async function getStockCollectionData() {
    return await api('stock-collection-data');
  }

  async function submitStockCollection(collectionId, items) {
    return await api('stock-collection-submit', {
      method: 'POST',
      body: JSON.stringify({ collection_id: collectionId, items }),
    });
  }

  return {
    restaurant, isAuthenticated, sessionInfo, deliveryDays, loading,
    login, loginByTelegram, validate, logout, logoutLocal, loadMyInfo, loadProducts, loadMyOrder, loadMyOrders, submitOrder, repeatOrder,
    loadAllHistory, changePassword, getTelegramStatus, telegramLink, telegramUnlink,
    getStockCollectionStatus, getStockCollectionData, submitStockCollection,
    adminGetStatus, adminGetOrder, adminUpdateOrder,
    adminCreateSession, adminAutoSession, adminCloseSession, adminDeleteOrder,
    adminToggleDate, adminGetOpenDates,
    adminExtendDeadline, adminGetTemplates, adminSaveTemplate, adminImportTemplateFromStock, adminSearchProducts,
    adminGetUsers, adminCreateUser, adminCreateBulkUsers, adminToggleUser, adminResetPassword,
    adminGetExportData, adminGetSessions, adminDeleteItem,
    adminGetAuditLog, adminGetOrderHistory,
    adminUploadStock, adminGetStockBalances, adminGetStockDates,
  };
});
