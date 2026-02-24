import { defineStore } from 'pinia';
import { ref } from 'vue';

export const useToastStore = defineStore('toast', () => {
  const toasts = ref([]);
  let _id = 0;

  function show(title, message = '', type = 'info', duration = 4000) {
    const id = ++_id;
    toasts.value.push({ id, title, message, type });
    if (duration > 0) {
      setTimeout(() => remove(id), duration);
    }
    return id;
  }

  function remove(id) {
    const idx = toasts.value.findIndex(t => t.id === id);
    if (idx !== -1) toasts.value.splice(idx, 1);
  }

  const success = (title, msg, d) => show(title, msg, 'success', d);
  const error   = (title, msg, d) => show(title, msg, 'error', d);
  const warning = (title, msg, d) => show(title, msg, 'warning', d);
  const info    = (title, msg, d) => show(title, msg, 'info', d);

  return { toasts, show, remove, success, error, warning, info };
});
