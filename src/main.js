import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { router } from './router/index.js';
import './assets/style.css';
import './assets/components.css';
import './assets/compact.css';

const app = createApp(App);
const pinia = createPinia();

app.use(pinia);
app.use(router);

app.config.errorHandler = (err, instance, info) => {
  console.error(`[Vue error] ${info}:`, err);
  import('./stores/toastStore.js').then(({ useToastStore }) => {
    try {
      const toast = useToastStore();
      toast.error('Произошла ошибка', err?.message || 'Неизвестная ошибка');
    } catch (e) { /* store not ready */ }
  }).catch(() => {});
};

app.mount('#app');
