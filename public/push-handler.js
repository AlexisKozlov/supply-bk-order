// Подгружается в сервис-воркер через workbox.importScripts.
// Обрабатывает входящие push-уведомления и клик по уведомлению.

self.addEventListener('push', (event) => {
  let payload = {};
  try {
    payload = event.data ? event.data.json() : {};
  } catch (e) {
    payload = { title: 'Напоминание', body: event.data ? event.data.text() : '' };
  }

  const title = payload.title || 'Напоминание';
  const options = {
    body: payload.body || '',
    icon: '/pwa-192x192.png',
    badge: '/pwa-192x192.png',
    tag: payload.tag || undefined,    // одинаковый tag заменит предыдущее уведомление
    renotify: !!payload.tag,
    data: { url: payload.url || '/' },
    requireInteraction: false,
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const targetUrl = (event.notification.data && event.notification.data.url) || '/';

  event.waitUntil((async () => {
    const allClients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    // Если уже есть открытое окно сайта — фокусируем его
    for (const client of allClients) {
      try {
        const url = new URL(client.url);
        const here = new URL(targetUrl, self.location.origin);
        if (url.origin === here.origin && 'focus' in client) {
          await client.focus();
          if ('navigate' in client) {
            try { await client.navigate(targetUrl); } catch (e) { /* iOS Safari ignores */ }
          }
          return;
        }
      } catch (e) { /* ignore */ }
    }
    // Иначе — открываем новое
    if (self.clients.openWindow) {
      await self.clients.openWindow(targetUrl);
    }
  })());
});
