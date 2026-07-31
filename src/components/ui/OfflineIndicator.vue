<template>
  <Transition name="offline">
    <div v-if="isOffline" class="offline-bar" :class="`offline-${placement}`">
      <svg viewBox="0 0 20 20" width="14" height="14" fill="none">
        <path d="M2 10a8 8 0 0114.9-4M18 10a8 8 0 01-14.9 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        <line x1="3" y1="17" x2="17" y2="3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
      </svg>
      <span>Нет соединения</span>
      <span v-if="queueCount > 0" class="offline-queue">{{ queueCount }} в очереди</span>
    </div>
  </Transition>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { useDraftStore } from '@/stores/draftStore.js';

const props = defineProps({
  // 'bottom' — портал закупок;
  // 'cabinet' — кабинет ресторана: полоса встаёт НАД нижним меню телефона,
  //   чтобы не перекрывать ни меню, ни шапку с номером ресторана.
  placement: { type: String, default: 'bottom' },
  // Очередь несинхронизированных черновиков есть только у заказа закупок.
  showQueue: { type: Boolean, default: true },
});

const isOffline = ref(!navigator.onLine);
const queueCount = ref(0);

function onOnline() { isOffline.value = false; updateQueue(); }
function onOffline() { isOffline.value = true; updateQueue(); }

async function updateQueue() {
  if (!props.showQueue) return;
  const draftStore = useDraftStore();
  if (draftStore.getSyncQueueCount) {
    queueCount.value = await draftStore.getSyncQueueCount();
  }
}

onMounted(() => {
  window.addEventListener('online', onOnline);
  window.addEventListener('offline', onOffline);
  updateQueue();
});
onBeforeUnmount(() => {
  window.removeEventListener('online', onOnline);
  window.removeEventListener('offline', onOffline);
});
</script>

<style scoped>
.offline-bar {
  position: fixed;
  left: 0;
  right: 0;
  z-index: 99999;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 8px 16px;
  background: #424242;
  color: #fff;
  font-size: 13px;
  font-weight: 600;
}
.offline-bottom { bottom: 0; padding-bottom: max(8px, env(safe-area-inset-bottom, 0px)); }
.offline-cabinet { bottom: 0; padding-bottom: max(8px, env(safe-area-inset-bottom, 0px)); }
/* На телефоне снизу закреплено меню кабинета (74px) — полоса встаёт над ним. */
@media (max-width: 768px) {
  .offline-cabinet { bottom: calc(74px + env(safe-area-inset-bottom, 0px)); padding-bottom: 8px; }
}
.offline-queue {
  padding: 2px 8px;
  border-radius: 10px;
  background: rgba(255,255,255,0.15);
  font-size: 11px;
}
.offline-enter-active, .offline-leave-active { transition: transform 0.3s ease; }
.offline-enter-from, .offline-leave-to { transform: translateY(100%); }
</style>
