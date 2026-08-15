// Поднимает баннер «Доступна новая версия» (UpdatePrompt) если на сервере
// уже выложена новая версия. Если идёт сборка — баннер не показываем
// (нечего обновлять), просто молча ждём — плагин сам подхватит когда сборка
// закончится.

const RECHECK_INTERVAL_MS = 30 * 1000;

// Кусок приложения (js-чанк или CSS) не догрузился — обычно потому, что
// вышла новая версия и старые файлы уже удалены. У каждого браузера свой
// текст ошибки, поэтому держим все известные варианты в одном месте:
// раньше список был продублирован в трёх файлах и разъезжался.
const CHUNK_ERROR_PATTERNS = [
  'Failed to fetch dynamically imported module',
  'Unable to preload CSS',
  'Importing a module script failed',
  'error loading dynamically imported module',
];

// Запрос вообще не ушёл: телефон потерял связь, Wi-Fi переключился, iOS
// усыпила приложение. Это не поломка кода. Safari пишет «Load failed»,
// Chrome — «Failed to fetch», Firefox — «NetworkError…».
const NETWORK_ERROR_PATTERNS = [
  'Load failed',
  'Failed to fetch',
  'NetworkError when attempting to fetch',
  'The network connection was lost',
  'The Internet connection appears to be offline',
  'Сервер недоступен',
  'Сервер не отвечает',
];

function matchesAny(input, patterns) {
  const msg = typeof input === 'string' ? input : (input?.message || String(input || ''));
  if (!msg) return false;
  return patterns.some(p => msg.includes(p));
}

export function isChunkLoadError(input) { return matchesAny(input, CHUNK_ERROR_PATTERNS); }
export function isNetworkError(input) { return matchesAny(input, NETWORK_ERROR_PATTERNS); }

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
