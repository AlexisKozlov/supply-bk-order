<template>
  <Transition name="upd-fade">
    <div v-if="needRefresh && !autoHealing" class="upd-banner" :class="{ 'upd-above-nav': isRestaurantArea }" role="alert">
      <div class="upd-content">
        <div class="upd-main">
          <div class="upd-icon" :class="{ 'is-spinning': updating }"><BkIcon name="redo" size="lg" /></div>
          <div class="upd-text">
            <div class="upd-title">Доступна новая версия портала</div>
            <div class="upd-sub">Нажмите «Обновить», чтобы загрузить свежие изменения.</div>
          </div>
        </div>
        <div class="upd-actions">
          <button class="upd-btn upd-btn-later" @click="later">Позже</button>
          <button class="upd-btn upd-btn-primary" :disabled="updating" @click="doUpdate">
            {{ updating ? 'Обновление…' : 'Обновить' }}
          </button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { computed, ref, onUnmounted } from 'vue';
import BkIcon from '@/components/ui/BkIcon.vue';
import { useRoute } from 'vue-router';
import { useRegisterSW } from 'virtual:pwa-register/vue';

// В кабинете ресторана на телефоне снизу закреплено меню — баннер вставал
// поверх него и перекрывал кнопки.
const route = useRoute();
const isRestaurantArea = computed(() => String(route.path || '').startsWith('/restaurant'));

// Таймер и слушатель сохраняем, чтобы корректно убрать при размонтировании
// (HMR в dev, тесты, или если когда-нибудь UpdatePrompt окажется не в App.vue).
let _updateCheckTimer = null;
let _visibilityHandler = null;

// Проверять обновление раз в 5 минут
const UPDATE_CHECK_INTERVAL = 5 * 60 * 1000;
// Маркер «однократного автохила» — чтобы не зацикливаться при сбоях.
const AUTO_HEAL_KEY = 'bk_sw_auto_healed';
// Сколько ждать активации waiting SW после postMessage SKIP_WAITING прежде
// чем сделать релоад. 300 мс достаточно — postMessage синхронный, SW обычно
// активируется за <100 мс; запас на медленные устройства.
const SW_ACTIVATE_DELAY_MS = 300;

const updating = ref(false);
const autoHealing = ref(false);

// Режим 'prompt' (см. vite.config.js): плагин сам отслеживает появление
// нового SW в ожидании и выставляет needRefresh. updateServiceWorker
// оставлен только из API хука, мы его НЕ используем — вместо этого
// посылаем SKIP_WAITING прямым postMessage и потом релоадим. Так кнопка
// никогда не висит.
const { needRefresh } = useRegisterSW({
  immediate: true,
  onRegisteredSW(swUrl, registration) {
    if (!registration) return;
    async function checkForUpdate() {
      try {
        if (registration.installing || !navigator) return;
        if (('connection' in navigator) && !navigator.onLine) return;
        const resp = await fetch(swUrl, {
          cache: 'no-store',
          headers: { 'cache': 'no-store', 'cache-control': 'no-cache' },
        });
        if (resp?.status === 200) {
          await registration.update();
        }
      } catch (e) { /* offline / 404 во время сборки — ок */ }
    }
    checkForUpdate();
    if (_updateCheckTimer) clearInterval(_updateCheckTimer);
    _updateCheckTimer = setInterval(checkForUpdate, UPDATE_CHECK_INTERVAL);
    if (_visibilityHandler) document.removeEventListener('visibilitychange', _visibilityHandler);
    _visibilityHandler = () => { if (document.visibilityState === 'visible') checkForUpdate(); };
    document.addEventListener('visibilitychange', _visibilityHandler);

    // Автохил: если на момент регистрации в скоупе уже есть waiting SW —
    // активируем его разово, чтобы не показывать пользователю лишний баннер.
    // Защита от циклов: sessionStorage-маркер. На любой сбой просто сбрасываем
    // флаг и показываем обычный баннер.
    if (registration.waiting && !sessionStorage.getItem(AUTO_HEAL_KEY)) {
      sessionStorage.setItem(AUTO_HEAL_KEY, '1');
      autoHealing.value = true;
      try { registration.waiting.postMessage({ type: 'SKIP_WAITING' }); } catch (_) {}
      setTimeout(() => {
        // Активация прошла или нет — в любом случае релоадим.
        // Новый SW станет контроллером (если успел skipWaiting),
        // или старый SW обслужит свежий index.html.
        try { window.location.reload(); } catch (_) { autoHealing.value = false; }
      }, SW_ACTIVATE_DELAY_MS);
    }
  },
  onRegisterError(error) {
    console.warn('[SW register error]', error);
  },
});

// Сигнал из main.js / router при ошибке загрузки чанков — поднимаем тот же баннер.
window.addEventListener('bk:needs-update', () => {
  needRefresh.value = true;
});

async function doUpdate() {
  // Защита от двойного клика: если уже идёт обновление — игнорируем.
  if (updating.value) return;
  updating.value = true;
  try {
    if ('serviceWorker' in navigator) {
      let reg = null;
      try { reg = await navigator.serviceWorker.getRegistration(); } catch (_) {}

      // 1. Если waiting SW уже есть — сразу пинаем SKIP_WAITING (не ждём
      //    лишний reg.update — он тянется секунду на медленном инете).
      //    Если waiting нет — спросим у сервера однократно.
      if (reg?.waiting) {
        try { reg.waiting.postMessage({ type: 'SKIP_WAITING' }); } catch (_) {}
      } else if (reg) {
        try {
          await reg.update();
          if (reg.waiting) reg.waiting.postMessage({ type: 'SKIP_WAITING' });
        } catch (_) { /* offline / sw.js 404 — ок */ }
      }

      // 2. Чистим Workbox-кэши, чтобы релоад взял свежие ассеты.
      //    SW НЕ снимаем (unregister) — это уничтожило бы push-подписку
      //    ресторана (подписка привязана к регистрации SW). Новый SW и так
      //    встаёт через SKIP_WAITING выше.
      if ('caches' in window) {
        try {
          const keys = await caches.keys();
          await Promise.all(keys.map(k => caches.delete(k).catch(() => false)));
        } catch (_) {}
      }
    }
  } finally {
    // 3. Жёсткий релоад с cache-bust: index.html и ассеты берутся свежими
    //    (SW-кэши очищены). Push-подписка при этом сохраняется.
    setTimeout(() => {
      const u = new URL(window.location.href);
      u.searchParams.set('_v', Date.now().toString(36));
      window.location.replace(u.toString());
    }, SW_ACTIVATE_DELAY_MS);
  }
}

function later() {
  needRefresh.value = false;
}

onUnmounted(() => {
  if (_updateCheckTimer) { clearInterval(_updateCheckTimer); _updateCheckTimer = null; }
  if (_visibilityHandler) { document.removeEventListener('visibilitychange', _visibilityHandler); _visibilityHandler = null; }
});
</script>

<style scoped>
.upd-banner {
  position: fixed;
  left: 50%;
  bottom: max(20px, env(safe-area-inset-bottom, 0px));
  transform: translateX(-50%);
  z-index: 10000;
  max-width: min(620px, calc(100vw - 24px));
  width: 100%;
  pointer-events: none;
}
.upd-content {
  pointer-events: auto;
  background: var(--tk-bg-card, #FFF);
  border-radius: var(--tk-r-lg, 14px);
  box-shadow: var(--tk-shadow-popover, 0 12px 32px rgba(15, 23, 42, 0.14));
  border: 1px solid var(--tk-border-soft, #EFEAE0);
  padding: 14px 16px;
  display: flex;
  align-items: center;
  gap: 16px;
}
.upd-main {
  display: flex;
  align-items: center;
  gap: 12px;
  flex: 1;
  min-width: 0;
}
/* Значок в собственной плашке: сам по себе он висел в воздухе и читался как
   чужеродный элемент, а не как часть карточки. */
.upd-icon {
  width: 42px;
  height: 42px;
  flex-shrink: 0;
  border-radius: var(--tk-r-md, 10px);
  background: var(--tk-accent-soft, rgba(232, 122, 30, 0.10));
  display: flex;
  align-items: center;
  justify-content: center;
}
.upd-icon :deep(svg) {
  width: 22px;
  height: 22px;
  stroke: var(--tk-accent, #E87A1E);
}
.upd-icon.is-spinning :deep(svg) { animation: upd-spin 1s linear infinite; }
@keyframes upd-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

.upd-text { flex: 1; min-width: 0; }
.upd-title {
  font-weight: var(--tk-fw-bold, 700);
  font-size: var(--tk-fz-lg, 14px);
  color: var(--tk-text, #2E1810);
  margin-bottom: 2px;
}
.upd-sub { font-size: var(--tk-fz-sm, 12px); color: var(--tk-text-muted, #6B5A50); }
.upd-actions { display: flex; gap: 8px; flex-shrink: 0; }
.upd-btn {
  border: none;
  border-radius: var(--tk-r-md, 10px);
  padding: 9px 16px;
  font-size: 13px;
  font-weight: var(--tk-fw-semibold, 600);
  cursor: pointer;
  font-family: inherit;
  transition: all 0.15s;
}
.upd-btn-later {
  background: var(--tk-n-100, #F5F1EA);
  color: var(--tk-text-secondary, #6B5A50);
}
.upd-btn-later:hover { background: var(--tk-n-200, #EDE8E0); }
.upd-btn-primary {
  background: linear-gradient(135deg, #E76F51, #F4A261);
  color: white;
}
.upd-btn-primary:hover { box-shadow: 0 4px 12px rgba(214,39,0,0.35); transform: translateY(-1px); }
.upd-btn-primary:disabled { opacity: 0.75; cursor: default; transform: none; box-shadow: none; }

.upd-fade-enter-active, .upd-fade-leave-active { transition: all 0.25s ease; }
.upd-fade-enter-from, .upd-fade-leave-to { opacity: 0; transform: translate(-50%, 20px); }

@media (max-width: 768px) {
  .upd-banner.upd-above-nav { bottom: calc(74px + env(safe-area-inset-bottom, 0px) + 10px); }
}

/* На телефоне значок раньше просто прятался, и плашка выглядела голой.
   Теперь он остаётся в строке заголовка, а кнопки уходят вниз на всю ширину. */
@media (max-width: 520px) {
  .upd-content { flex-direction: column; align-items: stretch; gap: 12px; padding: 14px; }
  .upd-main { align-items: flex-start; }
  .upd-icon { width: 38px; height: 38px; }
  .upd-icon :deep(svg) { width: 20px; height: 20px; }
  .upd-actions { gap: 10px; }
  .upd-btn { flex: 1; padding: 11px 16px; }
}
</style>
