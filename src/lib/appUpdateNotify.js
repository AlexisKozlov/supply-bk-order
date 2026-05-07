// Показывает баннер «Доступна новая версия» (UpdatePrompt) и тост-предупреждение,
// когда приложение не может догрузить чанки после деплоя. Никакой автоматической
// перезагрузки — иначе у пользователя теряются несохранённые данные.
import { useToastStore } from '@/stores/toastStore.js';

export function notifyAppUpdateRequired() {
  if (typeof window === 'undefined') return;
  if (window.__bkUpdateNotified) return;
  window.__bkUpdateNotified = true;
  try {
    window.dispatchEvent(new Event('bk:needs-update'));
  } catch (e) { /* игнор */ }
  try {
    const toast = useToastStore();
    toast.warning(
      'Доступна новая версия портала',
      'Сохраните введённые данные и нажмите «Обновить» в баннере внизу — старые ссылки не работают.',
      0, // не скрывать автоматически
    );
  } catch (e) { /* store не готов */ }
}
