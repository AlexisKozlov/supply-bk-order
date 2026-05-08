// Поднимает баннер «Доступна новая версия» (UpdatePrompt) либо предупреждение
// про идущую сборку — в зависимости от того, что отдаёт сервер. Никакой
// автоматической перезагрузки, чтобы не терять несохранённые данные.
import { useToastStore } from '@/stores/toastStore.js';

const RECHECK_INTERVAL_MS = 30 * 1000;

// Идёт ли сейчас сборка на сервере. Если /sw.js ещё не появился (404) или
// nginx отдаёт 503 — значит билд в процессе и реальной «новой версии» пока
// нет. В этом случае показываем мягкое сообщение и периодически перепроверяем.
async function isServerBuilding() {
  if (typeof fetch !== 'function') return false;
  try {
    const resp = await fetch('/sw.js?probe=' + Date.now().toString(36), {
      method: 'HEAD',
      cache: 'no-store',
      headers: { 'cache-control': 'no-cache' },
    });
    return resp.status === 404 || resp.status === 503;
  } catch (e) {
    // Сеть упала — не считаем что это билд.
    return false;
  }
}

function showBanner() {
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
      0,
    );
  } catch (e) { /* store не готов */ }
}

function showBuildingToast() {
  if (typeof window === 'undefined') return;
  if (window.__bkBuildingNotified) return;
  window.__bkBuildingNotified = true;
  try {
    const toast = useToastStore();
    toast.warning(
      'Портал обновляется',
      'Сейчас на сервере идёт сборка новой версии. Подождите 1–2 минуты и перезагрузите страницу.',
      0,
    );
  } catch (e) { /* store не готов */ }

  // Через 30 секунд проверим: если сборка закончилась — сбрасываем флаг,
  // и при следующем чанк-сбое уже сработает обычный баннер «Обновить».
  setTimeout(async () => {
    const stillBuilding = await isServerBuilding();
    if (!stillBuilding) {
      window.__bkBuildingNotified = false;
    } else {
      // Ещё строится — сбрасываем тоже, но через ещё 30 секунд.
      setTimeout(() => { window.__bkBuildingNotified = false; }, RECHECK_INTERVAL_MS);
    }
  }, RECHECK_INTERVAL_MS);
}

export async function notifyAppUpdateRequired() {
  // Любая ошибка внутри проглатывается — иначе она снова попадёт в
  // window.onunhandledrejection и вызовет повторный notify (бесконечный цикл).
  try {
    if (typeof window === 'undefined') return;
    // Если уже показали что-то одно — не повторяемся.
    if (window.__bkUpdateNotified || window.__bkBuildingNotified) return;
    // Сначала смотрим: на сервере билд или новая версия уже выложена?
    const building = await isServerBuilding();
    if (building) {
      showBuildingToast();
    } else {
      showBanner();
    }
  } catch (e) {
    // Тихо. Главное чтобы не зациклить onunhandledrejection.
    console.warn('[notifyAppUpdateRequired]', e);
  }
}
