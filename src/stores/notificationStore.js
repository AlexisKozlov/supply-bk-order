import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { db } from '@/lib/apiClient.js';
import { useUserStore } from './userStore.js';

const POLL_INTERVAL = 60000;

export const useNotificationStore = defineStore('notification', () => {
  const notifications = ref([]);
  const loading = ref(false);
  let pollTimer = null;

  const userStore = useUserStore();

  const unreadCount = computed(() => {
    const name = userStore.currentUser?.name;
    if (!name) return 0;
    return notifications.value.filter(n => {
      const readBy = typeof n.read_by === 'string' ? JSON.parse(n.read_by || '[]') : (n.read_by || []);
      return !readBy.includes(name);
    }).length;
  });

  async function load() {
    loading.value = true;
    try {
      const { data } = await db.from('notifications')
        .select('*')
        .order('created_at', { ascending: false })
        .limit(50);
      notifications.value = data || [];
    } catch (e) {
      console.error('Ошибка загрузки уведомлений:', e);
    } finally {
      loading.value = false;
    }
  }

  async function markRead(ids) {
    const name = userStore.currentUser?.name;
    if (!name || !ids.length) return;
    try {
      await db.rpc('mark_notifications_read', { ids, user_name: name });
      // Обновляем локально
      notifications.value.forEach(n => {
        if (ids.includes(n.id)) {
          const readBy = typeof n.read_by === 'string' ? JSON.parse(n.read_by || '[]') : (n.read_by || []);
          if (!readBy.includes(name)) {
            readBy.push(name);
            n.read_by = readBy;
          }
        }
      });
    } catch (e) {
      console.error('Ошибка отметки уведомлений:', e);
    }
  }

  async function markAllRead() {
    const name = userStore.currentUser?.name;
    if (!name) return;
    const unreadIds = notifications.value.filter(n => {
      const readBy = typeof n.read_by === 'string' ? JSON.parse(n.read_by || '[]') : (n.read_by || []);
      return !readBy.includes(name);
    }).map(n => n.id);
    if (unreadIds.length) await markRead(unreadIds);
  }

  function startPolling() {
    stopPolling();
    load();
    pollTimer = setInterval(() => load(), POLL_INTERVAL);
  }

  function stopPolling() {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  return { notifications, loading, unreadCount, load, markRead, markAllRead, startPolling, stopPolling };
});
