import { defineStore } from 'pinia';
import { ref } from 'vue';

const API_BASE = '/api/sa';

function buildHeaders() {
  const h = { 'Content-Type': 'application/json' };
  const st = localStorage.getItem('bk_session_token');
  if (st) h['X-Session-Token'] = st;
  return h;
}

// 401 — не редиректим, просто бросаем ошибку, UI решит что показать.
async function api(path, opts = {}) {
  const url = `${API_BASE}/${path}`;
  let res;
  try {
    res = await fetch(url, { headers: buildHeaders(), ...opts });
  } catch (e) {
    throw new Error('Сервер недоступен');
  }
  // Одна тихая повторная попытка при 401 (защита от краткого рестарта бэкенда при деплое)
  if (res.status === 401) {
    await new Promise(r => setTimeout(r, 1500));
    try {
      res = await fetch(url, { headers: buildHeaders(), ...opts });
    } catch (e) {
      throw new Error('Сервер недоступен');
    }
    if (res.status === 401) {
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
  if (!res.ok) {
    // Возвращаем объект с mult_errors если есть (кратность)
    const err = new Error(data.error || `Ошибка сервера (${res.status})`);
    if (data.mult_errors) err.multErrors = data.mult_errors;
    throw err;
  }
  return data;
}

export const useSupplyAssistantStore = defineStore('supplyAssistant', () => {
  const deliveryDays = ref([]);
  const products = ref([]);          // товары шаблона
  const stockProducts = ref([]);     // товары со склада (из «Сроков годности»)
  const myOrders = ref([]);
  const loading = ref(false);

  async function loadDeliveryDays() {
    const data = await api('delivery-days');
    deliveryDays.value = data.delivery_days || [];
    return deliveryDays.value;
  }

  async function loadProducts() {
    const data = await api('products');
    products.value = data.products || [];
    return products.value;
  }

  async function loadStockProducts() {
    const data = await api('stock-products');
    stockProducts.value = data.products || [];
    return stockProducts.value;
  }

  async function searchProducts(q) {
    if (!q || q.trim().length < 2) return [];
    const data = await api(`search-products?q=${encodeURIComponent(q.trim())}`);
    return data.products || [];
  }

  async function loadOrder(date) {
    const data = await api(`order?date=${encodeURIComponent(date)}`);
    return data.order || null;
  }

  async function loadMyOrders() {
    const data = await api('my-orders');
    myOrders.value = data.orders || [];
    return myOrders.value;
  }

  async function saveOrder(deliveryDate, comment, items) {
    const data = await api('order', {
      method: 'POST',
      body: JSON.stringify({ delivery_date: deliveryDate, comment: comment || '', items }),
    });
    return data;
  }

  // ═══ Admin-методы (раздел отдела закупок) ═══

  async function adminListOrders(filters = {}) {
    const params = new URLSearchParams();
    if (filters.restaurant) params.set('restaurant', filters.restaurant);
    if (filters.date_from) params.set('date_from', filters.date_from);
    if (filters.date_to) params.set('date_to', filters.date_to);
    if (filters.legal_entity) params.set('legal_entity', filters.legal_entity);
    const data = await api(`admin/orders?${params}`);
    return data.orders || [];
  }

  async function adminGetOrder(id) {
    const data = await api(`admin/order/${id}`);
    return data.order || null;
  }

  async function adminSaveOrder(id, payload) {
    return await api(`admin/order/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(payload),
    });
  }

  async function adminDeleteOrder(id) {
    return await api(`admin/order/${id}`, { method: 'DELETE' });
  }

  async function adminGetTemplates(legalEntity, category) {
    const params = new URLSearchParams();
    if (legalEntity) params.set('legal_entity', legalEntity);
    if (category) params.set('category', category);
    const data = await api(`admin/templates?${params}`);
    return data.templates || [];
  }

  async function adminSaveTemplates(payload) {
    return await api('admin/templates', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  }

  async function adminStockProducts(legalEntity) {
    const params = new URLSearchParams();
    if (legalEntity) params.set('legal_entity', legalEntity);
    const data = await api(`admin/stock-products?${params}`);
    return data.products || [];
  }

  return {
    deliveryDays,
    products,
    stockProducts,
    myOrders,
    loading,
    loadDeliveryDays,
    loadProducts,
    loadStockProducts,
    searchProducts,
    loadOrder,
    loadMyOrders,
    saveOrder,
    adminListOrders,
    adminGetOrder,
    adminSaveOrder,
    adminDeleteOrder,
    adminGetTemplates,
    adminSaveTemplates,
    adminStockProducts,
  };
});
