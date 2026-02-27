import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { db } from '@/lib/apiClient.js';
import { useUserStore } from './userStore.js';

const POLL_INTERVAL = 60000;
const BROADCAST_POLL_INTERVAL = 15000;

export const useNotificationStore = defineStore('notification', () => {
  const notifications = ref([]);
  const loading = ref(false);
  let pollTimer = null;
  let broadcastTimer = null;

  const activeBroadcasts = ref([]);
  const _savedDismissed = JSON.parse(localStorage.getItem('bk_broadcast_dismissed') || '[]');
  const broadcastDismissed = ref(new Set(_savedDismissed));

  const sessionStartTime = ref(null);

  const userStore = useUserStore();

  // Парсит JSON-поля read_by/deleted_by один раз, кэширует результат как массив
  function _parseJsonArray(n, field) {
    const raw = n[field];
    if (Array.isArray(raw)) return raw;
    const parsed = typeof raw === 'string' ? JSON.parse(raw || '[]') : [];
    n[field] = parsed; // кэшируем распарсенный массив
    return parsed;
  }

  function isDeletedForUser(n) {
    const name = userStore.currentUser?.name;
    if (!name) return false;
    return _parseJsonArray(n, 'deleted_by').includes(name);
  }

  const visibleNotifications = computed(() => {
    return notifications.value.filter(n => !isDeletedForUser(n));
  });

  const unreadCount = computed(() => {
    const name = userStore.currentUser?.name;
    if (!name) return 0;
    return visibleNotifications.value.filter(n => !_parseJsonArray(n, 'read_by').includes(name)).length;
  });

  async function load() {
    const name = userStore.currentUser?.name;
    if (!name) return;
    loading.value = true;
    try {
      const { data } = await db.from('notifications')
        .select('*')
        .or(`target_user.eq.${name},type.eq.broadcast`)
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
          const readBy = _parseJsonArray(n, 'read_by');
          if (!readBy.includes(name)) readBy.push(name);
        }
      });
    } catch (e) {
      console.error('Ошибка отметки уведомлений:', e);
    }
  }

  async function markAllRead() {
    const name = userStore.currentUser?.name;
    if (!name) return;
    const unreadIds = visibleNotifications.value
      .filter(n => !_parseJsonArray(n, 'read_by').includes(name))
      .map(n => n.id);
    if (unreadIds.length) await markRead(unreadIds);
  }

  async function deleteNotification(id) {
    const name = userStore.currentUser?.name;
    if (!name) return;
    try {
      await db.rpc('delete_notification_for_user', { id, user_name: name });
      // Обновляем локально — добавляем юзера в deleted_by
      const n = notifications.value.find(n => n.id === id);
      if (n) {
        _parseJsonArray(n, 'deleted_by').push(name);
      }
    } catch (e) {
      console.error('Ошибка удаления уведомления:', e);
    }
  }

  async function deleteAll() {
    const name = userStore.currentUser?.name;
    if (!name) return;
    try {
      await db.rpc('delete_all_notifications_for_user', { user_name: name });
      // Обновляем локально — помечаем все видимые как удалённые
      notifications.value.forEach(n => {
        if (!isDeletedForUser(n)) {
          _parseJsonArray(n, 'deleted_by').push(name);
        }
      });
    } catch (e) {
      console.error('Ошибка удаления всех уведомлений:', e);
    }
  }

  const currentBroadcast = computed(() => {
    if (!sessionStartTime.value) return null;
    return activeBroadcasts.value.find(b => {
      if (broadcastDismissed.value.has(b.id)) return false;
      // Показывать модалку только для broadcast, отправленных после входа в приложение
      const createdAt = new Date(b.created_at).getTime();
      return createdAt > sessionStartTime.value;
    }) || null;
  });

  async function checkBroadcasts() {
    const name = userStore.currentUser?.name;
    if (!name) return;
    try {
      const { data } = await db.rpc('get_active_broadcasts', { user_name: name });
      activeBroadcasts.value = data || [];
    } catch (e) {
      console.error('Ошибка проверки broadcast:', e);
    }
  }

  async function dismissBroadcast(id) {
    broadcastDismissed.value.add(id);
    localStorage.setItem('bk_broadcast_dismissed', JSON.stringify([...broadcastDismissed.value]));
    try { await markRead([id]); } catch (e) { console.error('Ошибка отметки broadcast:', e); }
  }

  function startPolling() {
    stopPolling();
    sessionStartTime.value = Date.now();
    load();
    checkBroadcasts();
    pollTimer = setInterval(() => load(), POLL_INTERVAL);
    broadcastTimer = setInterval(() => checkBroadcasts(), BROADCAST_POLL_INTERVAL);
  }

  function stopPolling() {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
    if (broadcastTimer) {
      clearInterval(broadcastTimer);
      broadcastTimer = null;
    }
  }

  return { notifications, visibleNotifications, loading, unreadCount, activeBroadcasts, currentBroadcast, load, markRead, markAllRead, deleteNotification, deleteAll, checkBroadcasts, dismissBroadcast, startPolling, stopPolling };
});
