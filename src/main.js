import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { router } from './router/index.js';
import { setAuthErrorHandler } from '@/lib/apiClient.js';
import { useUserStore } from '@/stores/userStore.js';
import { db } from '@/lib/apiClient.js';
import './assets/style.css';
import './assets/components.css';
import './assets/compact.css';

const app = createApp(App);
const pinia = createPinia();

app.use(pinia);
app.use(router);

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
    window.location.reload();
    return;
  }
  if (shouldIgnoreError(msg, stack)) return;
  logErrorToServer('error', `Unhandled rejection: ${msg}`, stack || null);
};

// Перехват ошибок Vue-компонентов
app.config.errorHandler = (err, instance, info) => {
  console.error(`[Vue error] ${info}:`, err);
  logErrorToServer('error', `[Vue ${info}] ${err?.message || err}`, err?.stack || null);
  import('./stores/toastStore.js').then(({ useToastStore }) => {
    try {
      const toast = useToastStore();
      toast.error('Произошла ошибка', err?.message || 'Неизвестная ошибка');
    } catch (e) { /* store not ready */ }
  }).catch(() => {});
};

app.mount('#app');
