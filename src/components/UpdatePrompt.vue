<template>
  <Transition name="upd-fade">
    <div v-if="needRefresh && !autoHealing" class="upd-banner" role="alert">
      <div class="upd-content">
        <div class="upd-icon">🔄</div>
        <div class="upd-text">
          <div class="upd-title">Доступна новая версия портала</div>
          <div class="upd-sub">Нажмите «Обновить», чтобы загрузить свежие изменения.</div>
        </div>
        <div class="upd-actions">
          <button class="upd-btn upd-btn-later" @click="later">Позже</button>
          <button class="upd-btn upd-btn-primary" @click="doUpdate">
            {{ updating ? 'Обновление…' : 'Обновить' }}
          </button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { ref, onUnmounted } from 'vue';
import { useRegisterSW } from 'virtual:pwa-register/vue';

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
  max-width: min(540px, calc(100vw - 24px));
  width: 100%;
  pointer-events: none;
}
.upd-content {
  pointer-events: auto;
  background: #FFF;
  border-radius: 14px;
  box-shadow: 0 10px 32px rgba(0,0,0,0.22), 0 2px 8px rgba(0,0,0,0.1);
  border: 1px solid rgba(214,39,0,0.15);
  padding: 14px 16px;
  display: flex;
  align-items: center;
  gap: 12px;
}
.upd-icon {
  font-size: 24px;
  flex-shrink: 0;
}
.upd-text { flex: 1; min-width: 0; }
.upd-title { font-weight: 700; font-size: 14px; color: #2E1810; margin-bottom: 2px; }
.upd-sub { font-size: 12px; color: #6B5A50; }
.upd-actions { display: flex; gap: 8px; flex-shrink: 0; }
.upd-btn {
  border: none;
  border-radius: 8px;
  padding: 8px 14px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  font-family: inherit;
  transition: all 0.15s;
}
.upd-btn-later {
  background: #F5F1EA;
  color: #6B5A50;
}
.upd-btn-later:hover { background: #EDE8E0; }
.upd-btn-primary {
  background: linear-gradient(135deg, #E76F51, #F4A261);
  color: white;
}
.upd-btn-primary:hover { box-shadow: 0 4px 12px rgba(214,39,0,0.35); transform: translateY(-1px); }

.upd-fade-enter-active, .upd-fade-leave-active { transition: all 0.25s ease; }
.upd-fade-enter-from, .upd-fade-leave-to { opacity: 0; transform: translate(-50%, 20px); }

@media (max-width: 520px) {
  .upd-content { flex-direction: column; align-items: stretch; padding: 12px; }
  .upd-actions { justify-content: flex-end; }
  .upd-icon { display: none; }
}
</style>
