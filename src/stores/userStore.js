import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { db, setSessionToken } from '@/lib/apiClient.js';

// ═══ Система модульных прав ═══
// Fallback-значения (используются до загрузки конфига с сервера)
export const MODULES = ['order', 'planning', 'history', 'plan-fact', 'database', 'delivery-schedule', 'analytics', 'calendar', 'analysis', 'restaurant-sales', 'shelf-life', 'pricing', 'tenders', 'stock-collection', 'deficit', 'distribution', 'telegram', 'pallet-calc', 'cards', 'protocols', 'marketing', 'corrections', 'chat', 'pallet-storage', 'restaurant-orders', 'supplier-orders', 'truck-loading', 'surveys'];

export const ROLE_TEMPLATES = {
  admin:  { order: 'full', planning: 'full', history: 'full', 'plan-fact': 'full', database: 'full', 'delivery-schedule': 'full', analytics: 'full', calendar: 'full', analysis: 'full', 'restaurant-sales': 'full', 'shelf-life': 'full', pricing: 'full', tenders: 'full', 'stock-collection': 'full', deficit: 'full', distribution: 'full', telegram: 'full', 'pallet-calc': 'full', cards: 'full', protocols: 'full', marketing: 'full', corrections: 'full', chat: 'full', 'pallet-storage': 'full', 'restaurant-orders': 'full', 'supplier-orders': 'full', 'truck-loading': 'full', surveys: 'full' },
  manager: { order: 'full', planning: 'full', history: 'full', 'plan-fact': 'full', database: 'full', 'delivery-schedule': 'full', analytics: 'full', calendar: 'full', analysis: 'full', 'restaurant-sales': 'full', 'shelf-life': 'full', pricing: 'full', tenders: 'full', 'stock-collection': 'full', deficit: 'full', distribution: 'full', telegram: 'none', 'pallet-calc': 'full', cards: 'full', protocols: 'full', marketing: 'full', corrections: 'full', chat: 'full', 'pallet-storage': 'full', 'restaurant-orders': 'full', 'supplier-orders': 'full', 'truck-loading': 'full', surveys: 'full' },
  user:   { order: 'edit', planning: 'edit', history: 'edit', 'plan-fact': 'edit', database: 'edit', 'delivery-schedule': 'edit', analytics: 'view', calendar: 'view', analysis: 'edit', 'restaurant-sales': 'edit', 'shelf-life': 'edit', pricing: 'edit', tenders: 'edit', 'stock-collection': 'edit', deficit: 'edit', distribution: 'edit', telegram: 'none', 'pallet-calc': 'edit', cards: 'view', protocols: 'edit', marketing: 'view', corrections: 'view', chat: 'edit', 'pallet-storage': 'none', 'restaurant-orders': 'full', 'supplier-orders': 'full', 'truck-loading': 'full', surveys: 'edit' },
  viewer: { order: 'view', planning: 'view', history: 'view', 'plan-fact': 'view', database: 'view', 'delivery-schedule': 'view', analytics: 'view', calendar: 'view', analysis: 'view', 'restaurant-sales': 'view', 'shelf-life': 'view', pricing: 'view', tenders: 'view', 'stock-collection': 'view', deficit: 'view', distribution: 'view', telegram: 'none', 'pallet-calc': 'view', cards: 'view', protocols: 'view', marketing: 'none', corrections: 'none', chat: 'none', 'pallet-storage': 'none', 'restaurant-orders': 'view', 'supplier-orders': 'view', 'truck-loading': 'view', surveys: 'view' },
};

export const ACCESS_LEVELS = { full: 3, edit: 2, view: 1, none: 0 };

export const MODULE_LABELS = {
  order: 'Новый заказ', planning: 'Планирование', history: 'История',
  'plan-fact': 'Поставки', database: 'База товаров', 'delivery-schedule': 'График доставки',
  analytics: 'Аналитика', calendar: 'Календарь', analysis: 'Анализ',
  'restaurant-sales': 'Реализация', 'shelf-life': 'Сроки годности',
  pricing: 'Цены и ПСЦ', tenders: 'Тендеры',
  'stock-collection': 'Сбор остатков', deficit: 'Распределение дефицита',
  distribution: 'Распределение', telegram: 'Telegram-бот',
  'pallet-calc': 'Калькулятор паллет',
  cards: 'Поиск карточек',
  protocols: 'Протоколы',
  marketing: 'Маркетинг',
  corrections: 'Корректировки',
  chat: 'Чат с ресторанами',
  'pallet-storage': 'Паллетовка склада',
  'restaurant-orders': 'Заказы ресторанов',
  'supplier-orders': 'Заявки поставщикам',
  'truck-loading': 'Загрузка машин',
  surveys: 'Опросы',
};

// Загрузка RBAC-конфига с сервера (единый источник правды — PHP)
let _rbacLoaded = false;
export async function loadRbacConfig() {
  if (_rbacLoaded) return;
  try {
    const { data } = await db.rpc('get_rbac_config');
    if (data?.modules) { MODULES.length = 0; MODULES.push(...data.modules); }
    if (data?.role_templates) { Object.keys(ROLE_TEMPLATES).forEach(k => delete ROLE_TEMPLATES[k]); Object.assign(ROLE_TEMPLATES, data.role_templates); }
    if (data?.access_levels) { Object.keys(ACCESS_LEVELS).forEach(k => delete ACCESS_LEVELS[k]); Object.assign(ACCESS_LEVELS, data.access_levels); }
    _rbacLoaded = true;
  } catch (e) { /* используем fallback */ }
}

export const useUserStore = defineStore('user', () => {
  const currentUser = ref(null);
  const maintenanceMode = ref(false);
  const maintenanceMessage = ref('');
  const maintenanceEndTime = ref(null);

  const isAuthenticated = computed(() => !!currentUser.value);
  const isAdmin = computed(() => currentUser.value?.role === 'admin');
  const isViewer = computed(() => currentUser.value?.role === 'viewer');

  function getAccess(module) {
    if (!currentUser.value) return 'none';
    if (currentUser.value.role === 'admin') return 'full';
    const role = currentUser.value.role || 'user';
    const base = ROLE_TEMPLATES[role] || ROLE_TEMPLATES.user;
    const overrides = currentUser.value.permissions || {};
    return overrides[module] ?? base[module] ?? 'none';
  }

  function hasAccess(module, minLevel = 'view') {
    if (ACCESS_LEVELS[getAccess(module)] >= ACCESS_LEVELS[minLevel]) return true;
    return false;
  }

  let _sessionRestored = false;
  function restoreSession() {
    if (_sessionRestored) return;
    _sessionRestored = true;
    try {
      const stored = localStorage.getItem('bk_user');
      if (stored) {
        const user = JSON.parse(stored);
        currentUser.value = user;
        // Асинхронная валидация сессии на сервере
        validateSession(user);
      }
    } catch (e) { /* noop */ }
  }

  async function validateSession(user) {
    try {
      const { data } = await db.rpc('validate_session', {});
      if (!data?.valid) {
        logout();
        return null;
      }
      // Сохранить сессионный токен если сервер выдал (миграция)
      if (data.session_token) setSessionToken(data.session_token);
      // Обновить роль и настройки из сервера (защита от подмены в localStorage)
      if (data.user) {
        const updated = { ...user, role: data.user.role, display_role: data.user.display_role, legal_entities: data.user.legal_entities, permissions: data.user.permissions || null, hidden_modules: data.user.hidden_modules || [] };
        currentUser.value = updated;
        localStorage.setItem('bk_user', JSON.stringify(updated));
        return updated;
      }
      return currentUser.value;
    } catch (e) { /* сеть недоступна — оставляем локальную сессию */ }
    return currentUser.value;
  }

  async function refreshSession() {
    if (!currentUser.value) return null;
    return await validateSession(currentUser.value);
  }

  async function login(email, password) {
    const { data, error } = await db.rpc('check_user_password', {
      user_email: email,
      user_password: password,
    });
    if (!error && data?.success) {
      if (data.session_token) setSessionToken(data.session_token);
      const user = data.user || { name: email, role: 'user' };
      currentUser.value = user;
      localStorage.setItem('bk_user', JSON.stringify(user));
      if (data.maintenance_mode !== undefined) {
        maintenanceMode.value = !!data.maintenance_mode;
      }
      if (data.maintenance_message !== undefined) {
        maintenanceMessage.value = data.maintenance_message || '';
      }
      return user;
    }

    throw new Error(error || data?.message || 'Неверный пароль');
  }

  function logout() {
    // Инвалидация сессии на сервере (не ждём ответа)
    db.rpc('logout').catch(() => {});
    _sessionRestored = false;
    currentUser.value = null;
    maintenanceMode.value = false;
    maintenanceMessage.value = '';
    maintenanceEndTime.value = null;
    const keysToRemove = [];
    for (let i = 0; i < localStorage.length; i++) {
      const key = localStorage.key(i);
      if (key && key.startsWith('bk_')) keysToRemove.push(key);
    }
    keysToRemove.forEach(k => localStorage.removeItem(k));
    try { sessionStorage.removeItem('bk_just_logged_in'); } catch(e) {}
  }

  function getHiddenModules() {
    return currentUser.value?.hidden_modules || [];
  }

  async function setHiddenModules(modules) {
    if (!currentUser.value) return;
    currentUser.value = { ...currentUser.value, hidden_modules: modules };
    localStorage.setItem('bk_user', JSON.stringify(currentUser.value));
    try { await db.rpc('save_hidden_modules', { modules }); } catch (e) { /* сохранится при следующей попытке */ }
  }

  function getAllowedEntities() {
    const allowed = currentUser.value?.legal_entities;
    if (!allowed || !allowed.length) return null;
    return allowed;
  }

  async function checkMaintenance() {
    try {
      const { data } = await db.rpc('check_maintenance');
      maintenanceMode.value = !!data?.maintenance_mode;
      maintenanceMessage.value = data?.maintenance_message || '';
      maintenanceEndTime.value = data?.maintenance_end_time || null;
    } catch (e) { /* noop */ }
  }

  return {
    currentUser,
    isAuthenticated,
    isAdmin,
    isViewer,
    maintenanceMode,
    maintenanceMessage,
    maintenanceEndTime,
    restoreSession,
    refreshSession,
    login,
    logout,
    getAllowedEntities,
    getHiddenModules,
    setHiddenModules,
    checkMaintenance,
    getAccess,
    hasAccess,
  };
});
