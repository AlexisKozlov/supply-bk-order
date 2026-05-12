import { ref, computed } from 'vue';

const TOKEN_KEY = 'ro_token';

// Конвертация base64url-публичного ключа в Uint8Array для pushManager.subscribe
function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - base64String.length % 4) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = atob(base64);
  const outputArray = new Uint8Array(rawData.length);
  for (let i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
  return outputArray;
}

function buildHeaders(json = false) {
  const h = {};
  const t = localStorage.getItem(TOKEN_KEY);
  if (t) h['X-RO-Token'] = t;
  if (json) h['Content-Type'] = 'application/json';
  return h;
}

export function usePushNotifications() {
  const isSupported = computed(() => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window);
  const permission = ref(isSupported.value ? Notification.permission : 'unsupported');
  const isSubscribed = ref(false);
  const busy = ref(false);
  const error = ref('');

  async function refresh() {
    if (!isSupported.value) return;
    permission.value = Notification.permission;
    try {
      const reg = await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.getSubscription();
      isSubscribed.value = !!sub;
    } catch (e) { /* ignore */ }
  }

  async function subscribe() {
    if (!isSupported.value) { error.value = 'Push не поддерживается в этом браузере'; return false; }
    busy.value = true;
    error.value = '';
    try {
      // Запрашиваем разрешение
      const perm = await Notification.requestPermission();
      permission.value = perm;
      if (perm !== 'granted') {
        error.value = 'Разрешение не выдано';
        return false;
      }

      // Получаем публичный VAPID-ключ от сервера
      const keyRes = await fetch('/api/push/key', { headers: buildHeaders() });
      const keyData = await keyRes.json();
      if (!keyRes.ok || !keyData.publicKey) {
        error.value = keyData.error || 'Не удалось получить ключ';
        return false;
      }

      const reg = await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(keyData.publicKey),
      });

      // Отправляем подписку на сервер
      const subRes = await fetch('/api/push/subscribe', {
        method: 'POST',
        headers: buildHeaders(true),
        body: JSON.stringify({
          subscription: sub.toJSON(),
          user_agent: navigator.userAgent,
        }),
      });
      if (!subRes.ok) {
        const d = await subRes.json().catch(() => ({}));
        error.value = d.error || 'Не удалось сохранить подписку';
        return false;
      }
      isSubscribed.value = true;
      return true;
    } catch (e) {
      error.value = e.message || 'Ошибка подписки';
      return false;
    } finally {
      busy.value = false;
    }
  }

  async function unsubscribe() {
    if (!isSupported.value) return false;
    busy.value = true;
    error.value = '';
    try {
      const reg = await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.getSubscription();
      if (!sub) { isSubscribed.value = false; return true; }
      const endpoint = sub.endpoint;
      await sub.unsubscribe();
      await fetch('/api/push/unsubscribe', {
        method: 'POST',
        headers: buildHeaders(true),
        body: JSON.stringify({ endpoint }),
      });
      isSubscribed.value = false;
      return true;
    } catch (e) {
      error.value = e.message || 'Ошибка отписки';
      return false;
    } finally {
      busy.value = false;
    }
  }

  refresh();

  return { isSupported, permission, isSubscribed, busy, error, subscribe, unsubscribe, refresh };
}
