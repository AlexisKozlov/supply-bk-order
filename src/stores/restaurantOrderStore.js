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

function buildAuthHeaders() {
  const h = {};
  const t = getToken();
  if (t) h['X-RO-Token'] = t;
  const st = localStorage.getItem('bk_session_token');
  if (st) h['X-Session-Token'] = st;
  return h;
}

// Обработка 401: чистим токен и сигналим UI. БЕЗ автоматического редиректа —
// иначе во время кратковременного 401 при деплое пользователь теряет
// несохранённые данные. Кабинет показывает баннер «Войти заново», пользователь
// сам решает, когда уйти на логин.
let session401Notified = false;
function handle401() {
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(REST_KEY);
  if (typeof window !== 'undefined' && !session401Notified) {
    session401Notified = true;
    try { window.dispatchEvent(new Event('bk:ro-session-expired')); } catch (e) {}
  }
}

async function api(path, opts = {}) {
  const url = `${API_BASE}/${path}`;
  let res;
  try {
    res = await fetch(url, { headers: buildHeaders(), ...opts });
  } catch (e) {
    throw new Error('Сервер недоступен');
  }
  // 401 на одной попытке мог быть разовым (рестарт бэкенда при деплое).
  // Делаем одну тихую повторную попытку через 1.5 сек, прежде чем считать
  // сессию завершённой.
  if (res.status === 401 && !path.startsWith('admin')) {
    await new Promise(r => setTimeout(r, 1500));
    try {
      res = await fetch(url, { headers: buildHeaders(), ...opts });
    } catch (e) {
      throw new Error('Сервер недоступен');
    }
    if (res.status === 401) {
      handle401();
      throw new Error('Сессия завершена');
    }
  }
  const text = await res.text();
  let data = {};
  if (text) {
    try {
      data = JSON.parse(text);
    } catch {
      throw new Error(`Ошибка сервера (${res.status})`);
    }
  }
  if (!res.ok) throw new Error(data.error || `Ошибка сервера (${res.status})`);
  return data;
}

export const useRestaurantOrderStore = defineStore('restaurantOrder', () => {
  // === Состояние ресторана ===
  const restaurant = ref(JSON.parse(localStorage.getItem(REST_KEY) || 'null'));
  const isAuthenticated = computed(() => !!restaurant.value && !!getToken());

  const sessionInfo = ref(null);
  const deliveryDays = ref([]);
  const restaurantOrdersEnabled = ref(true);
  const loading = ref(false);
  // Дельта между серверным и клиентским временем (мс): server_time - client_time.
  // Считается при каждом loadMyInfo. Применяется при расчёте обратного отсчёта
  // дедлайна, чтобы сбитые часы устройства не показывали ложное «осталось N мин».
  const serverTimeOffset = ref(0);
  function nowFromServer() { return Date.now() + serverTimeOffset.value; }

  async function loginByTelegram(tgToken, acceptedDataRules = false) {
    const data = await api('tg-auth', {
      method: 'POST',
      body: JSON.stringify({ tg_token: tgToken, accepted_data_rules: acceptedDataRules }),
    });
    if (data.success) {
      localStorage.setItem(TOKEN_KEY, data.token);
      localStorage.setItem(REST_KEY, JSON.stringify(data.restaurant));
      restaurant.value = data.restaurant;
    }
    return data;
  }

  async function login(restaurantNumber, password, legalEntityGroup = null, force = false, acceptedDataRules = false) {
    const data = await api('login', {
      method: 'POST',
      body: JSON.stringify({ restaurant_number: restaurantNumber, password, legal_entity_group: legalEntityGroup, force, accepted_data_rules: acceptedDataRules }),
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
        logoutLocal();
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
    // Жёсткая перезагрузка вместо SPA-редиректа: гарантированно сбрасывает
    // все компоненты и таймеры, чтобы следующий пользователь не увидел данные
    // предыдущего. SPA-навигация оставляет в памяти ref'ы, watchers, setInterval.
    if (typeof window !== 'undefined') {
      window.location.replace('/restaurant/login');
    }
  }

  // Локальный выход: чистит только клиентское состояние, не обращаясь к серверу.
  // Нужен при входе по tg_token — чтобы не убить активную сессию того же ресторана
  // на другом устройстве (сервер сам перезапишет session_token в tg-auth).
  function logoutLocal() {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(REST_KEY);
    // Чистим все локальные черновики формы заказа: они привязаны к ресторану,
    // и оставлять их — это утечка данных предыдущего юзера.
    try {
      const keys = [];
      for (let i = 0; i < localStorage.length; i++) {
        const k = localStorage.key(i);
        if (k && k.startsWith('bk_ro_draft_')) keys.push(k);
      }
      keys.forEach(k => localStorage.removeItem(k));
    } catch (e) { /* игнор */ }
    restaurant.value = null;
    sessionInfo.value = null;
    deliveryDays.value = [];
  }

  async function loadMyInfo() {
    loading.value = true;
    try {
      const data = await api('my-info');
      restaurantOrdersEnabled.value = data.restaurant_orders_enabled !== false;
      sessionInfo.value = data.session;
      deliveryDays.value = data.delivery_days || [];
      if (typeof data.server_time === 'number' && Number.isFinite(data.server_time)) {
        serverTimeOffset.value = data.server_time - Date.now();
      }
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

  async function scanProduct(gtin) {
    const data = await api(`scan-product?gtin=${encodeURIComponent(gtin)}`);
    return data;
  }

  async function reportMissingGtin(gtin, opts = {}) {
    const { name = '', comment = '', photo = null } = opts || {};
    // Если есть фото — multipart/form-data, иначе JSON (обратная совместимость)
    if (photo) {
      const fd = new FormData();
      fd.append('gtin', gtin);
      if (name) fd.append('name', name);
      if (comment) fd.append('comment', comment);
      fd.append('photo', photo);
      const url = `${API_BASE}/report-missing-gtin`;
      const headers = {};
      const t = getToken();
      if (t) headers['X-RO-Token'] = t;
      const st = localStorage.getItem('bk_session_token');
      if (st) headers['X-Session-Token'] = st;
      const res = await fetch(url, { method: 'POST', headers, body: fd });
      const text = await res.text();
      let data = {};
      if (text) { try { data = JSON.parse(text); } catch {} }
      if (!res.ok) throw new Error(data.error || `Ошибка сервера (${res.status})`);
      return data;
    }
    const data = await api('report-missing-gtin', {
      method: 'POST',
      body: JSON.stringify({ gtin, name, comment }),
    });
    return data;
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

  // === Admin (для отдела закупок) ===
  async function adminGetStatus(date, legalEntity = null) {
    const params = new URLSearchParams({ date });
    if (legalEntity) params.set('legal_entity', legalEntity);
    return await api(`admin/status?${params}`);
  }

  async function adminGetModuleSettings(legalEntity = null) {
    const params = new URLSearchParams();
    if (legalEntity) params.set('legal_entity', legalEntity);
    const qs = params.toString() ? `?${params}` : '';
    return await api(`admin/module-settings${qs}`);
  }

  async function adminSaveModuleSettings(legalEntity, restaurantOrdersEnabled) {
    return await api('admin/module-settings', {
      method: 'POST',
      body: JSON.stringify({
        legal_entity: legalEntity,
        restaurant_orders_enabled: !!restaurantOrdersEnabled,
      }),
    });
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

  async function adminCreateSession(weekStart, weekEnd, legalEntity = null) {
    return await api('admin/session', {
      method: 'POST',
      body: JSON.stringify({ action: 'create', week_start: weekStart, week_end: weekEnd, legal_entity: legalEntity }),
    });
  }

  async function adminAutoSession(legalEntity = null) {
    return await api('admin/session', {
      method: 'POST',
      body: JSON.stringify({ action: 'auto', legal_entity: legalEntity }),
    });
  }

  async function adminCloseSession(sessionId, legalEntity = null) {
    return await api('admin/session', {
      method: 'POST',
      body: JSON.stringify({ action: 'close', session_id: sessionId, legal_entity: legalEntity }),
    });
  }

  async function adminToggleDate(sessionId, deliveryDate, isOpen, legalEntity = null) {
    return await api('admin/toggle-date', {
      method: 'POST',
      body: JSON.stringify({ session_id: sessionId, delivery_date: deliveryDate, is_open: isOpen, legal_entity: legalEntity }),
    });
  }

  async function adminGetOpenDates(sessionId, legalEntity = null) {
    const params = new URLSearchParams();
    if (sessionId) params.set('session_id', sessionId);
    if (legalEntity) params.set('legal_entity', legalEntity);
    const qs = params.toString() ? `?${params}` : '';
    const data = await api(`admin/open-dates${qs}`);
    return data.dates || [];
  }

  async function adminExtendDeadline(sessionId, deliveryDate, soft, hard, legalEntity = null) {
    return await api('admin/extend-deadline', {
      method: 'POST',
      body: JSON.stringify({ session_id: sessionId, delivery_date: deliveryDate, soft_deadline: soft, hard_deadline: hard, legal_entity: legalEntity }),
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

  async function adminCreateUser(restaurantNumber, legalEntityGroup, password) {
    return await api('admin/users', {
      method: 'POST',
      body: JSON.stringify({ action: 'create', restaurant_number: restaurantNumber, legal_entity_group: legalEntityGroup, password }),
    });
  }

  async function adminCreateBulkUsers(password, mode = 'missing') {
    return await api('admin/users', {
      method: 'POST',
      body: JSON.stringify({ action: 'create-bulk', password, mode }),
    });
  }

  async function adminToggleUser(restaurantNumber, legalEntityGroup, isActive) {
    return await api('admin/users', {
      method: 'POST',
      body: JSON.stringify({ action: 'toggle', restaurant_number: restaurantNumber, legal_entity_group: legalEntityGroup, is_active: isActive }),
    });
  }

  async function adminResetPassword(restaurantNumber, legalEntityGroup, password) {
    return await api('admin/users', {
      method: 'POST',
      body: JSON.stringify({ action: 'reset-password', restaurant_number: restaurantNumber, legal_entity_group: legalEntityGroup, password }),
    });
  }

  async function adminDeleteOrder(orderId) {
    return await api(`admin/order/${orderId}`, { method: 'DELETE' });
  }

  async function adminGetExportData(format, date, legalEntity = null) {
    const params = new URLSearchParams({ date });
    if (legalEntity) params.set('legal_entity', legalEntity);
    return await api(`admin/export/${format}?${params}`);
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

  async function adminPreviewUtImport(file, deliveryDate, legalEntity = '') {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('delivery_date', deliveryDate);
    if (legalEntity) formData.append('legal_entity', legalEntity);
    const res = await fetch(`${API_BASE}/admin/import-ut`, {
      method: 'POST',
      headers: {
        'X-Session-Token': localStorage.getItem('bk_session_token') || '',
      },
      body: formData,
    });
    const data = await res.json();
    if (!res.ok && data.error) throw new Error(data.error);
    return data.preview;
  }

  async function adminConfirmUtImport(payload, addMissingTemplates = false, overwriteMode = 'none', overwriteRestaurants = []) {
    return await api('admin/import-ut', {
      method: 'POST',
      body: JSON.stringify({
        action: 'confirm',
        payload,
        add_missing_templates: addMissingTemplates,
        overwrite_mode: overwriteMode,
        overwrite_restaurants: overwriteRestaurants,
      }),
    });
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

  async function adminGetStockBalances(balanceDate, deliveryDate, legalEntity = '', options = {}) {
    const params = new URLSearchParams({
      date: balanceDate,
      delivery_date: deliveryDate,
    });
    if (legalEntity) params.set('legal_entity', legalEntity);
    if (options.orderMode) params.set('order_mode', options.orderMode);
    if (Array.isArray(options.orderDates) && options.orderDates.length) {
      params.set('order_dates', options.orderDates.join(','));
    }
    let url = `admin/stock-balances?${params.toString()}`;
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

  async function loadHistoryOrder(source, id) {
    const params = new URLSearchParams({ source, id: String(id) });
    const data = await api(`history-order?${params}`);
    return data.order || null;
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

  async function telegramUnlink(chatId = null) {
    return await api('telegram-unlink', {
      method: 'POST',
      body: JSON.stringify(chatId !== null ? { chat_id: String(chatId) } : {}),
    });
  }

  async function telegramLinks() {
    const data = await api('telegram-links');
    return data.links || [];
  }

  async function loadBroadcasts() {
    const data = await api('broadcasts');
    return data.broadcasts || [];
  }

  async function loadCabinetPosts(limit = 50) {
    const data = await api(`cabinet-posts?limit=${encodeURIComponent(limit)}`);
    return data.posts || [];
  }

  async function markCabinetPostsRead(ids) {
    return await api('cabinet-post-read', {
      method: 'POST',
      body: JSON.stringify({ ids }),
    });
  }

  async function adminGetCabinetPosts(group = '') {
    const qs = group ? `?group=${encodeURIComponent(group)}` : '';
    const data = await api(`admin/cabinet-posts${qs}`);
    return data.posts || [];
  }

  async function adminCreateCabinetPost(payload, files = []) {
    const fd = new FormData();
    for (const [key, value] of Object.entries(payload || {})) {
      if (Array.isArray(value) || (value && typeof value === 'object')) {
        fd.append(key, JSON.stringify(value));
      } else {
        fd.append(key, value == null ? '' : String(value));
      }
    }
    for (const file of files || []) {
      fd.append('files[]', file);
    }
    const res = await fetch(`${API_BASE}/admin/cabinet-posts`, {
      method: 'POST',
      headers: buildAuthHeaders(),
      body: fd,
    });
    const text = await res.text();
    let data = {};
    if (text) { try { data = JSON.parse(text); } catch {} }
    if (!res.ok) throw new Error(data.error || `Ошибка сервера (${res.status})`);
    return data;
  }

  async function adminUpdateCabinetPost(postId, payload) {
    return await api(`admin/cabinet-posts/${postId}`, {
      method: 'PATCH',
      body: JSON.stringify(payload || {}),
    });
  }

  async function adminDeleteCabinetPost(postId) {
    return await api(`admin/cabinet-posts/${postId}`, { method: 'DELETE' });
  }

  async function downloadCabinetFile(file) {
    const url = file?.url || ('/api/' + String(file?.file_path || '').replace(/^\/+/, ''));
    if (!url || url === '/api/') throw new Error('Файл не найден');
    const res = await fetch(url, { headers: buildAuthHeaders() });
    const blob = await res.blob();
    if (!res.ok) {
      let data = null;
      try { data = JSON.parse(await blob.text()); } catch {}
      throw new Error(data?.error || `Ошибка сервера (${res.status})`);
    }
    const objectUrl = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = objectUrl;
    a.download = file.file_name || 'file';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
  }

  async function getCabinetFileObjectUrl(file) {
    const url = file?.url || ('/api/' + String(file?.file_path || '').replace(/^\/+/, ''));
    if (!url || url === '/api/') throw new Error('Файл не найден');
    const res = await fetch(url, { headers: buildAuthHeaders() });
    const blob = await res.blob();
    if (!res.ok) {
      let data = null;
      try { data = JSON.parse(await blob.text()); } catch {}
      throw new Error(data?.error || `Ошибка сервера (${res.status})`);
    }
    return URL.createObjectURL(blob);
  }

  async function loadSurveys() {
    const data = await api('my-surveys');
    return data.surveys || [];
  }

  async function loadSurvey(surveyId) {
    const data = await api(`my-survey/${surveyId}`);
    return data.survey || null;
  }

  async function submitSurvey(surveyId, answers, comment = '') {
    return await api('submit-survey', {
      method: 'POST',
      body: JSON.stringify({ survey_id: surveyId, answers, comment }),
    });
  }

  async function markBroadcastRead(ids) {
    return await api('broadcast-read', {
      method: 'POST',
      body: JSON.stringify({ ids }),
    });
  }

  async function getStockCollectionStatus() {
    return await api('stock-collection-status');
  }

  async function getStockCollectionData(collectionId = null) {
    const qs = collectionId ? `?collection_id=${encodeURIComponent(collectionId)}` : '';
    return await api(`stock-collection-data${qs}`);
  }

  async function submitStockCollection(collectionId, items) {
    return await api('stock-collection-submit', {
      method: 'POST',
      body: JSON.stringify({ collection_id: collectionId, items }),
    });
  }

  async function loadWarehouseStock() {
    return await api('warehouse-stock');
  }

  return {
    restaurant, isAuthenticated, sessionInfo, deliveryDays, restaurantOrdersEnabled, loading,
    serverTimeOffset, nowFromServer,
    login, loginByTelegram, validate, logout, logoutLocal, loadMyInfo, loadProducts, scanProduct, reportMissingGtin, loadMyOrder, loadMyOrders, submitOrder, repeatOrder,
    loadAllHistory, loadHistoryOrder, changePassword, getTelegramStatus, telegramLink, telegramUnlink, telegramLinks,
    loadBroadcasts, loadCabinetPosts, markCabinetPostsRead, adminGetCabinetPosts,
    adminCreateCabinetPost, adminUpdateCabinetPost, adminDeleteCabinetPost, downloadCabinetFile, getCabinetFileObjectUrl,
    loadSurveys, loadSurvey, submitSurvey, markBroadcastRead,
    getStockCollectionStatus, getStockCollectionData, submitStockCollection, loadWarehouseStock,
    adminGetStatus, adminGetModuleSettings, adminSaveModuleSettings, adminGetOrder, adminUpdateOrder,
    adminCreateSession, adminAutoSession, adminCloseSession, adminDeleteOrder,
    adminToggleDate, adminGetOpenDates,
    adminExtendDeadline, adminGetTemplates, adminSaveTemplate, adminImportTemplateFromStock, adminSearchProducts,
    adminGetUsers, adminCreateUser, adminCreateBulkUsers, adminToggleUser, adminResetPassword,
    adminGetExportData, adminGetSessions, adminDeleteItem,
    adminPreviewUtImport, adminConfirmUtImport,
    adminGetAuditLog, adminGetOrderHistory,
    adminUploadStock, adminGetStockBalances, adminGetStockDates,
  };
});
