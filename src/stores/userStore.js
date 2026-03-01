import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { db, setApiKey, setSessionToken } from '@/lib/apiClient.js';

export const useUserStore = defineStore('user', () => {
  const currentUser = ref(null);
  const maintenanceMode = ref(false);
  const maintenanceMessage = ref('');
  const maintenanceEndTime = ref(null);

  const isAuthenticated = computed(() => !!currentUser.value);
  const isAdmin = computed(() => currentUser.value?.role === 'admin');
  const isViewer = computed(() => currentUser.value?.role === 'viewer');

  function restoreSession() {
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
        const updated = { ...user, role: data.user.role, display_role: data.user.display_role, legal_entities: data.user.legal_entities };
        currentUser.value = updated;
        localStorage.setItem('bk_user', JSON.stringify(updated));
      }
    } catch (e) { /* сеть недоступна — оставляем локальную сессию */ }
  }

  async function fetchUserList() {
    try {
      const { data } = await db.rpc('get_user_list');
      return Array.isArray(data) ? data : [];
    } catch (e) {
      return [];
    }
  }

  async function login(name, password) {
    const { data, error } = await db.rpc('check_user_password', {
      user_name: name,
      user_password: password,
    });
    if (!error && data?.success) {
      if (data.api_key) setApiKey(data.api_key);
      if (data.session_token) setSessionToken(data.session_token);
      const user = data.user || { name, role: 'user' };
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
    fetchUserList,
    login,
    logout,
    getAllowedEntities,
    checkMaintenance,
  };
});
