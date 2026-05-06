<template>
  <Transition name="upd-fade">
    <div v-if="needRefresh" class="upd-banner" role="alert">
      <div class="upd-content">
        <div class="upd-icon">🔄</div>
        <div class="upd-text">
          <div class="upd-title">Доступна новая версия портала</div>
          <div class="upd-sub">Нажмите «Обновить», чтобы загрузить свежие изменения.</div>
        </div>
        <div class="upd-actions">
          <button class="upd-btn upd-btn-later" @click="later">Позже</button>
          <button class="upd-btn upd-btn-primary" @click="doUpdate" :disabled="updating">
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

const updating = ref(false);
const needRefresh = ref(false);

// В режиме autoUpdate новый SW сразу активируется и берёт контроль над вкладкой.
// Тост показываем по событию controllerchange — это значит, что в текущей вкладке
// уже работает свежая версия SW и пользователю стоит обновить страницу,
// чтобы загрузить актуальный index.html и ассеты.
if ('serviceWorker' in navigator) {
  let initialController = navigator.serviceWorker.controller;
  navigator.serviceWorker.addEventListener('controllerchange', () => {
    // Первый controllerchange после первичной регистрации SW (когда controller был null)
    // — это не апдейт, а просто появление контроллера. Пропускаем.
    if (!initialController) {
      initialController = navigator.serviceWorker.controller;
      return;
    }
    needRefresh.value = true;
  });
}

useRegisterSW({
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
      } catch (e) { /* offline — ок */ }
    }
    // Сразу при загрузке проверяем, нет ли свежей версии — иначе пользователь
    // увидит закэшированный SW'ом старый index.html и будет ждать 5 минут до
    // первой плановой проверки.
    checkForUpdate();
    setInterval(checkForUpdate, UPDATE_CHECK_INTERVAL);
    // Также проверяем при возвращении во вкладку — если пользователь
    // долго не работал, ждать 5 минут не нужно.
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') checkForUpdate();
    });
  },
  onRegisterError(error) {
    console.warn('[SW register error]', error);
  },
});

async function doUpdate() {
  updating.value = true;
  try {
    if ('caches' in window) {
      try {
        const keys = await caches.keys();
        await Promise.all(keys.map(k => caches.delete(k)));
      } catch (e) { /* игнор */ }
    }
  } catch (e) { /* игнор */ }
  window.location.reload();
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
.upd-btn-primary:disabled { opacity: 0.6; cursor: wait; transform: none; }

.upd-fade-enter-active, .upd-fade-leave-active { transition: all 0.25s ease; }
.upd-fade-enter-from, .upd-fade-leave-to { opacity: 0; transform: translate(-50%, 20px); }

@media (max-width: 520px) {
  .upd-content { flex-direction: column; align-items: stretch; padding: 12px; }
  .upd-actions { justify-content: flex-end; }
  .upd-icon { display: none; }
}
</style>
