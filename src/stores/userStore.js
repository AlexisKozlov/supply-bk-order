import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { db, setSessionToken } from '@/lib/apiClient.js';

// ═══ Система модульных прав ═══
export const MODULES = ['order', 'planning', 'history', 'plan-fact', 'database', 'delivery-schedule', 'analytics', 'calendar', 'analysis', 'shelf-life'];

export const ROLE_TEMPLATES = {
  admin:  { order: 'full', planning: 'full', history: 'full', 'plan-fact': 'full', database: 'full', 'delivery-schedule': 'full', analytics: 'full', calendar: 'full', analysis: 'full', 'shelf-life': 'full' },
  user:   { order: 'edit', planning: 'edit', history: 'edit', 'plan-fact': 'edit', database: 'edit', 'delivery-schedule': 'edit', analytics: 'view', calendar: 'view', analysis: 'edit', 'shelf-life': 'edit' },
  viewer: { order: 'view', planning: 'view', history: 'view', 'plan-fact': 'view', database: 'view', 'delivery-schedule': 'view', analytics: 'view', calendar: 'view', analysis: 'view', 'shelf-life': 'view' },
};

export const ACCESS_LEVELS = { full: 3, edit: 2, view: 1, none: 0 };

export const MODULE_LABELS = {
  order: 'Новый заказ', planning: 'Планирование', history: 'История',
  'plan-fact': 'Поставки', database: 'База товаров', 'delivery-schedule': 'График доставки',
  analytics: 'Аналитика', calendar: 'Календарь', analysis: 'Анализ',
  'shelf-life': 'Сроки годности',
};

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
    return ACCESS_LEVELS[getAccess(module)] >= ACCESS_LEVELS[minLevel];
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
      const { data } = await db.rpc('validate_session', { user_name: user?.name || '' });
      if (!data?.valid) {
        logout();
        return;
      }
      // Сохранить сессионный токен если сервер выдал (миграция)
      if (data.session_token) setSessionToken(data.session_token);
      // Обновить роль из сервера (защита от подмены в localStorage)
      if (data.user) {
        const updated = { ...user, role: data.user.role, display_role: data.user.display_role, legal_entities: data.user.legal_entities, permissions: data.user.permissions || null };
        currentUser.value = updated;
        localStorage.setItem('bk_user', JSON.stringify(updated));
      }
    } catch (e) { /* сеть недоступна — оставляем локальную сессию */ }
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

    throw new Error('Неверный пароль');
  }

  function logout() {
    // Инвалидация сессии на сервере (не ждём ответа)
    db.rpc('logout').catch(() => {});
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
    login,
    logout,
    getAllowedEntities,
    checkMaintenance,
    getAccess,
    hasAccess,
  };
});
