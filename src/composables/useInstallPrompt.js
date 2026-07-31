/**
 * Установка портала как приложения на телефон/компьютер.
 *
 * Зачем: Chrome/Android сам показывает предложение установки редко и незаметно,
 * а Safari/iOS не показывает никогда — там установка только вручную через
 * «Поделиться → На экран Домой». Из-за этого рестораны пользовались сайтом в
 * браузере и не получали push-уведомления (они работают только в установленном
 * приложении на iOS).
 *
 * Модуль-синглтон: слушатели вешаются один раз при первом импорте, чтобы не
 * пропустить событие beforeinstallprompt (браузер шлёт его один раз и рано).
 *
 * Использование:
 *   const { canInstall, isIos, isStandalone, install } = useInstallPrompt();
 */

import { ref, computed } from 'vue';

// Событие Chrome: сохраняем, чтобы вызвать установку по своей кнопке.
const deferredEvent = ref(null);
const justInstalled = ref(false);
const isStandalone = ref(false);

const ua = typeof navigator !== 'undefined' ? navigator.userAgent || '' : '';
// iPad с iPadOS 13+ представляется как Mac — ловим по наличию тач-экрана.
const isIosDevice = /iPad|iPhone|iPod/.test(ua)
  || (/Macintosh/.test(ua) && typeof document !== 'undefined' && 'ontouchend' in document);
// Внутри iOS все браузеры — это Safari. Установка возможна только из Safari:
// в Chrome/Firefox/Яндексе на iPhone пункта «На экран Домой» нет.
const isIosSafari = isIosDevice && !/CriOS|FxiOS|EdgiOS|YaBrowser|OPiOS/.test(ua);
// Telegram-webview: там установка недоступна, подсказку не показываем.
const isInAppBrowser = /Instagram|FBAN|FBAV|Telegram|VKClient/i.test(ua);

function detectStandalone() {
  try {
    return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
      || window.navigator?.standalone === true;
  } catch (e) {
    return false;
  }
}

if (typeof window !== 'undefined') {
  isStandalone.value = detectStandalone();

  window.addEventListener('beforeinstallprompt', (e) => {
    // Отменяем встроенную мини-плашку Chrome — показываем свою карточку,
    // с понятным текстом и на русском.
    e.preventDefault();
    deferredEvent.value = e;
  });

  window.addEventListener('appinstalled', () => {
    deferredEvent.value = null;
    justInstalled.value = true;
    isStandalone.value = true;
  });

  try {
    window.matchMedia('(display-mode: standalone)').addEventListener('change', (e) => {
      isStandalone.value = e.matches;
    });
  } catch (e) { /* Safari < 14 не умеет addEventListener на matchMedia */ }
}

export function useInstallPrompt() {
  // Chrome/Edge/Android: есть системное событие — установка в один клик.
  const canInstallNative = computed(() => !!deferredEvent.value && !isStandalone.value);
  // iOS: событие не приходит никогда — показываем инструкцию.
  const needsIosHint = computed(() => isIosSafari && !isStandalone.value && !isInAppBrowser);

  const canInstall = computed(() => canInstallNative.value || needsIosHint.value);

  /**
   * Показывает системное окно установки (Chrome). Возвращает true, если
   * пользователь согласился. Для iOS всегда false — там только инструкция.
   */
  async function install() {
    const e = deferredEvent.value;
    if (!e) return false;
    try {
      e.prompt();
      const choice = await e.userChoice;
      deferredEvent.value = null;
      return choice?.outcome === 'accepted';
    } catch (err) {
      deferredEvent.value = null;
      return false;
    }
  }

  return {
    canInstall,
    canInstallNative,
    needsIosHint,
    isIos: isIosDevice,
    isStandalone,
    justInstalled,
    isInAppBrowser,
    install,
  };
}
