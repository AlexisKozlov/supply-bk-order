import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { db, setApiKey } from '@/lib/apiClient.js';

export const useUserStore = defineStore('user', () => {
  const currentUser = ref(null);

  const isAuthenticated = computed(() => !!currentUser.value);

  function restoreSession() {
    try {
      const stored = localStorage.getItem('bk_user');
      if (stored) {
        currentUser.value = JSON.parse(stored);
      }
    } catch (e) { /* noop */ }
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
      const user = data.user || { name, role: 'user' };
      currentUser.value = user;
      localStorage.setItem('bk_user', JSON.stringify(user));
      return user;
    }

    throw new Error('Неверный пароль');
  }

  function logout() {
    currentUser.value = null;
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

  return {
    currentUser,
    isAuthenticated,
    restoreSession,
    fetchUserList,
    login,
    logout,
    getAllowedEntities,
  };
});
