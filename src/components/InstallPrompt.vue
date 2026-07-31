<template>
  <Transition name="inst-fade">
    <div v-if="visible" class="inst-banner" :class="{ 'inst-above-nav': isRestaurantArea }" role="dialog" aria-label="Установка приложения">
      <div class="inst-card">
        <button class="inst-close" aria-label="Закрыть" @click="later">×</button>

        <div class="inst-head">
          <img :src="iconSrc" alt="" class="inst-logo" width="44" height="44" />
          <div class="inst-text">
            <div class="inst-title">{{ title }}</div>
            <div class="inst-sub">{{ subtitle }}</div>
          </div>
        </div>

        <!-- iPhone/iPad: системного окна установки нет, показываем шаги. -->
        <ol v-if="showIosSteps" class="inst-steps">
          <li>Нажмите <b>Поделиться</b> <span class="inst-share" aria-hidden="true"></span> внизу экрана</li>
          <li>Пролистайте вниз и выберите <b>«На экран „Домой“»</b></li>
          <li>Нажмите <b>«Добавить»</b> — появится иконка</li>
        </ol>

        <div class="inst-actions">
          <button class="inst-btn inst-btn-later" @click="later">Позже</button>
          <button v-if="canInstallNative" class="inst-btn inst-btn-primary" @click="doInstall">Установить</button>
          <button v-else-if="!showIosSteps" class="inst-btn inst-btn-primary" @click="showIosSteps = true">Как установить</button>
          <button v-else class="inst-btn inst-btn-primary" @click="later">Понятно</button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<script setup>
/**
 * Предложение установить приложение (портал закупок или кабинет ресторана).
 *
 * Показывается только тем, кто ещё не установил, один раз, и не чаще одного
 * раза в 30 дней после «Позже». В браузере Telegram и прочих встроенных
 * webview не показывается — установка там невозможна.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { useUserStore } from '@/stores/userStore.js';
import { useRestaurantOrderStore } from '@/stores/restaurantOrderStore.js';
import { useInstallPrompt } from '@/composables/useInstallPrompt.js';

const SNOOZE_KEY = 'bk_install_prompt_snoozed_until';
const SNOOZE_DAYS = 30;
// Пауза перед показом: человек сначала должен увидеть, что открылось,
// и только потом получить предложение установить.
const SHOW_DELAY_MS = 6000;

const route = useRoute();
const userStore = useUserStore();
const roStore = useRestaurantOrderStore();
const { canInstall, canInstallNative, isStandalone, install } = useInstallPrompt();

const dismissed = ref(false);
const delayPassed = ref(false);
const showIosSteps = ref(false);
let timer = null;

const isRestaurantArea = computed(() => String(route.path || '').startsWith('/restaurant'));
// Предлагаем только тем, кто уже вошёл: на экране входа это лишний шум.
const isLoggedIn = computed(() => (isRestaurantArea.value ? roStore.isAuthenticated : userStore.isAuthenticated));

const visible = computed(() =>
  delayPassed.value && !dismissed.value && !isStandalone.value && canInstall.value && isLoggedIn.value
);

const title = computed(() => (isRestaurantArea.value
  ? 'Установите кабинет на телефон'
  : 'Установите портал как приложение'));

const subtitle = computed(() => (isRestaurantArea.value
  ? 'Иконка на экране телефона, вход без браузера и уведомления о заказах и напоминаниях.'
  : 'Открывается в своём окне без адресной строки и запускается с рабочего стола.'));

const iconSrc = computed(() => (isRestaurantArea.value ? '/pwa-rest-192x192.png' : '/pwa-192x192.png'));

function snoozed() {
  try {
    const until = Number(localStorage.getItem(SNOOZE_KEY) || 0);
    return until > Date.now();
  } catch (e) {
    return false;
  }
}

function later() {
  dismissed.value = true;
  try {
    localStorage.setItem(SNOOZE_KEY, String(Date.now() + SNOOZE_DAYS * 24 * 60 * 60 * 1000));
  } catch (e) { /* приватный режим — просто не запомним */ }
}

async function doInstall() {
  const accepted = await install();
  if (accepted) dismissed.value = true;
  // Отказ в системном окне тоже прячем и откладываем: повторно Chrome
  // событие не пришлёт, висящая карточка будет бесполезной.
  else later();
}

onMounted(() => {
  if (snoozed()) { dismissed.value = true; return; }
  timer = setTimeout(() => { delayPassed.value = true; }, SHOW_DELAY_MS);
});

onUnmounted(() => {
  if (timer) clearTimeout(timer);
});
</script>

<style scoped>
.inst-banner {
  position: fixed;
  left: 50%;
  bottom: max(20px, calc(env(safe-area-inset-bottom, 0px) + 12px));
  transform: translateX(-50%);
  z-index: 9999;
  width: 100%;
  max-width: min(460px, calc(100vw - 24px));
  pointer-events: none;
}
.inst-card {
  position: relative;
  pointer-events: auto;
  background: #FFF;
  border: 1px solid rgba(80, 35, 20, 0.12);
  border-radius: 16px;
  box-shadow: 0 12px 34px rgba(0, 0, 0, 0.22), 0 2px 8px rgba(0, 0, 0, 0.08);
  padding: 16px 16px 14px;
}
.inst-close {
  position: absolute;
  top: 6px; right: 8px;
  background: transparent;
  border: none;
  color: #9C8B80;
  font-size: 22px;
  line-height: 1;
  padding: 4px 6px;
  cursor: pointer;
  font-family: inherit;
}
.inst-close:hover { color: #502314; }
.inst-head { display: flex; gap: 12px; align-items: center; }
.inst-logo { border-radius: 10px; flex-shrink: 0; }
.inst-text { min-width: 0; padding-right: 18px; }
.inst-title { font-weight: 700; font-size: 15px; color: #2E1810; margin-bottom: 3px; }
.inst-sub { font-size: 12.5px; color: #6B5A50; line-height: 1.4; }
.inst-steps {
  margin: 12px 0 0;
  padding-left: 20px;
  font-size: 13px;
  color: #4A3A32;
  line-height: 1.6;
}
.inst-steps b { color: #2E1810; }
/* Значок «Поделиться» из Safari: квадрат со стрелкой вверх. */
.inst-share {
  display: inline-block;
  width: 12px; height: 14px;
  vertical-align: -2px;
  background: currentColor;
  -webkit-mask: no-repeat center/contain url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M12 3l4 4-1.4 1.4L13 6.8V15h-2V6.8L9.4 8.4 8 7l4-4z'/%3E%3Cpath d='M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7h-2v7H7v-7H5z'/%3E%3C/svg%3E");
  mask: no-repeat center/contain url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M12 3l4 4-1.4 1.4L13 6.8V15h-2V6.8L9.4 8.4 8 7l4-4z'/%3E%3Cpath d='M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7h-2v7H7v-7H5z'/%3E%3C/svg%3E");
  color: #2F6FED;
}
.inst-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 14px; }
.inst-btn {
  border: none;
  border-radius: 10px;
  padding: 9px 16px;
  font-size: 13.5px;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
  transition: all 0.15s;
}
.inst-btn-later { background: #F5F1EA; color: #6B5A50; }
.inst-btn-later:hover { background: #EDE8E0; }
.inst-btn-primary { background: linear-gradient(135deg, #E76F51, #F4A261); color: #fff; }
.inst-btn-primary:hover { box-shadow: 0 4px 12px rgba(231, 111, 81, 0.4); transform: translateY(-1px); }

.inst-fade-enter-active, .inst-fade-leave-active { transition: all 0.25s ease; }
.inst-fade-enter-from, .inst-fade-leave-to { opacity: 0; transform: translate(-50%, 16px); }

/* В кабинете на телефоне снизу закреплено меню (74px) — карточка встаёт над ним. */
@media (max-width: 768px) {
  .inst-banner.inst-above-nav { bottom: calc(74px + env(safe-area-inset-bottom, 0px) + 10px); }
}

@media (max-width: 520px) {
  .inst-banner { bottom: max(12px, calc(env(safe-area-inset-bottom, 0px) + 8px)); }
  .inst-banner.inst-above-nav { bottom: calc(74px + env(safe-area-inset-bottom, 0px) + 10px); }
  .inst-actions { justify-content: stretch; }
  .inst-btn { flex: 1; }
}
</style>
