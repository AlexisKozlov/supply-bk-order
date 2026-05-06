import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { router } from './router/index.js';
import { setAuthErrorHandler } from '@/lib/apiClient.js';
import { useUserStore } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { db } from '@/lib/apiClient.js';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import './assets/style.css';
import './assets/components.css';
import './assets/compact.css';

const app = createApp(App);
const pinia = createPinia();

app.use(pinia);
app.use(router);
app.component('BurgerSpinner', BurgerSpinner);

// Автовыход при истёкшей сессии (ответ 401 от сервера)
setAuthErrorHandler(() => {
  const userStore = useUserStore();
  userStore.logout();
  router.push({ name: 'home', query: { showLogin: 'true', expired: 'true' } });
});

// Отправка ошибки в базу
function logErrorToServer(level, message, stack) {
  try {
    const userStore = useUserStore();
    const userName = userStore.currentUser?.name || null;
    db.rpc('log_frontend_error', {
      level,
      message: String(message).slice(0, 5000),
      stack: stack ? String(stack).slice(0, 10000) : null,
      user_name: userName,
      url: window.location.href,
    }).catch(() => {});
  } catch (e) { /* store not ready */ }
}

// Ошибки, которые не нужно логировать (внешние скрипты, SW и т.д.)
function shouldIgnoreError(message, source) {
  const msg = String(message || '');
  const src = String(source || '');
  // "Script error." без источника — внешний скрипт (расширения, CORS)
  if (msg === 'Script error.' || (msg.startsWith('Script error') && (!src || src === ''))) return true;
  // Service Worker ошибки регистрации
  if (msg.includes('registerSW') || src.includes('registerSW')) return true;
  if (msg.includes('service-worker') || msg.includes('serviceWorker')) return true;
  // Ошибки расширений браузера
  if (src.includes('extension://') || src.includes('moz-extension://')) return true;
  return false;
}

// Перехват JS-ошибок
window.onerror = (message, source, lineno, colno, error) => {
  if (shouldIgnoreError(message, source)) return;
  const msg = `${message} (${source}:${lineno}:${colno})`;
  logErrorToServer('error', msg, error?.stack || null);
};

// Жёсткая перезагрузка после деплоя: чистим кэши Service Worker'а и его самого,
// иначе reload снова отдаст старый закэшированный index.html со ссылками на
// удалённые чанки.
async function hardReloadAfterChunkError() {
  try {
    if ('caches' in window) {
      const keys = await caches.keys();
      await Promise.all(keys.map((k) => caches.delete(k)));
    }
  } catch (e) { /* игнор */ }
  try {
    if ('serviceWorker' in navigator) {
      const regs = await navigator.serviceWorker.getRegistrations();
      await Promise.all(regs.map((r) => r.unregister()));
    }
  } catch (e) { /* игнор */ }
  window.location.reload();
}

// Перехват необработанных промисов
window.onunhandledrejection = (event) => {
  const reason = event.reason;
  const msg = reason?.message || String(reason);
  const stack = reason?.stack || '';
  // Ошибки загрузки модулей/CSS после деплоя новой версии — перезагружаем страницу
  if (
    msg.includes('Failed to fetch dynamically imported module') ||
    msg.includes('Unable to preload CSS') ||
    msg.includes('Importing a module script failed') ||
    msg.includes('error loading dynamically imported module')
  ) {
    const key = 'bk_reload_count';
    const count = parseInt(sessionStorage.getItem(key) || '0');
    if (count < 2) {
      sessionStorage.setItem(key, String(count + 1));
      hardReloadAfterChunkError();
    } else {
      sessionStorage.removeItem(key);
    }
    return;
  }
  if (shouldIgnoreError(msg, stack)) return;
  logErrorToServer('error', `Unhandled rejection: ${msg}`, stack || null);
};

// Перехват ошибок Vue-компонентов
app.config.errorHandler = (err, instance, info) => {
  const errMsg = err?.message || String(err || '');
  // Ошибки загрузки модулей/CSS после деплоя — то же поведение, что и в onunhandledrejection
  if (
    errMsg.includes('Failed to fetch dynamically imported module') ||
    errMsg.includes('Unable to preload CSS') ||
    errMsg.includes('Importing a module script failed') ||
    errMsg.includes('error loading dynamically imported module')
  ) {
    const key = 'bk_reload_count';
    const count = parseInt(sessionStorage.getItem(key) || '0');
    if (count < 2) {
      sessionStorage.setItem(key, String(count + 1));
      hardReloadAfterChunkError();
    } else {
      sessionStorage.removeItem(key);
      try {
        const toast = useToastStore();
        toast.error(
          'Не удалось загрузить часть приложения',
          'Закройте все вкладки портала и откройте заново — это подгрузит новую версию.'
        );
      } catch (e) { /* store not ready */ }
    }
    return;
  }
  console.error(`[Vue error] ${info}:`, err);
  logErrorToServer('error', `[Vue ${info}] ${errMsg}`, err?.stack || null);
  try {
    const toast = useToastStore();
    toast.error('Произошла ошибка', errMsg || 'Неизвестная ошибка');
  } catch (e) { /* store not ready */ }
};

app.mount('#app');

// Приложение успешно стартовало — сбрасываем счётчик попыток автоперезагрузки
// после ошибки загрузки чанка, чтобы при следующем сбое снова было 2 попытки.
try { sessionStorage.removeItem('bk_reload_count'); } catch (e) { /* игнор */ }
