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
import { ref } from 'vue';
import { useRegisterSW } from 'virtual:pwa-register/vue';

// Проверять обновление раз в 5 минут
const UPDATE_CHECK_INTERVAL = 5 * 60 * 1000;
// Маркер «однократного автохила» — чтобы не зацикливаться при сбоях.
const AUTO_HEAL_KEY = 'bk_sw_auto_healed';
// Сколько ждать активации waiting SW после postMessage SKIP_WAITING прежде
// чем сделать релоад. Хватает с запасом, страница не зависает.
const SW_ACTIVATE_DELAY_MS = 800;

const updating = ref(false);
const autoHealing = ref(false);

// 'prompt': плагин сам отслеживает появление нового SW в ожидании и
// выставляет needRefresh. updateServiceWorker оставлен только из API хука,
// мы его НЕ используем — вместо этого посылаем SKIP_WAITING прямым
// postMessage и потом релоадим. Так кнопка никогда не висит.
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
    setInterval(checkForUpdate, UPDATE_CHECK_INTERVAL);
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') checkForUpdate();
    });

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
  updating.value = true;
  try {
    // 1. Если есть waiting SW — даём команду активироваться. Не ждём ответа.
    if ('serviceWorker' in navigator) {
      try {
        const reg = await navigator.serviceWorker.getRegistration();
        if (reg?.waiting) reg.waiting.postMessage({ type: 'SKIP_WAITING' });
      } catch (_) { /* игнор */ }
    }
  } finally {
    // 2. Жёсткий релоад с cache-bust через короткую паузу. Если новый SW
    //    успел стать контроллером — ок, отдаст свежий index.html. Если идёт
    //    сборка на сервере (sw.js 404) — попадём на maintenance, ждём.
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
