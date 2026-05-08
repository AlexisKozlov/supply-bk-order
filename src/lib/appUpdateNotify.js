// Поднимает баннер «Доступна новая версия» (UpdatePrompt) если на сервере
// уже выложена новая версия. Если идёт сборка — баннер не показываем
// (нечего обновлять), просто молча ждём — плагин сам подхватит когда сборка
// закончится.

const RECHECK_INTERVAL_MS = 30 * 1000;

// Идёт ли сейчас сборка на сервере. Если /sw.js ещё не появился (404) или
// nginx отдаёт 503 — значит билд в процессе и реальной «новой версии» пока
// нет.
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
}

export async function notifyAppUpdateRequired() {
  // Любая ошибка внутри проглатывается — иначе она снова попадёт в
  // window.onunhandledrejection и вызовет повторный notify (бесконечный цикл).
  try {
    if (typeof window === 'undefined') return;
    if (window.__bkUpdateNotified) return;
    // Если на сервере идёт сборка — банер не показываем (нечего активировать).
    // Через 30 секунд ещё раз проверим состояние; когда сборка закончится,
    // плагин сам зарегистрирует новый SW и поднимет баннер штатно.
    const building = await isServerBuilding();
    if (building) {
      setTimeout(() => { notifyAppUpdateRequired(); }, RECHECK_INTERVAL_MS);
      return;
    }
    showBanner();
  } catch (e) {
    console.warn('[notifyAppUpdateRequired]', e);
  }
}
