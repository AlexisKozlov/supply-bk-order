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

// Перехват JS-ошибок
window.onerror = (message, source, lineno, colno, error) => {
  const msg = `${message} (${source}:${lineno}:${colno})`;
  logErrorToServer('error', msg, error?.stack || null);
};

// Перехват необработанных промисов
window.onunhandledrejection = (event) => {
  const reason = event.reason;
  const msg = reason?.message || String(reason);
  logErrorToServer('error', `Unhandled rejection: ${msg}`, reason?.stack || null);
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
