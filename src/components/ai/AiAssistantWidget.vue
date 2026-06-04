<template>
  <Teleport to="body">
    <div v-if="!hidden" class="aiw">
      <transition name="aiw-fade">
        <div v-if="open" class="aiw-panel">
          <div class="aiw-head">
            <BkIcon name="analytics" size="sm" />
            <span class="aiw-title">ИИ-помощник</span>
            <router-link to="/assistant" class="aiw-link" @click="open = false">На страницу →</router-link>
            <button class="aiw-close" @click="open = false" title="Свернуть"><BkIcon name="close" size="sm" /></button>
          </div>
          <div class="aiw-body"><AiChat compact /></div>
        </div>
      </transition>
      <button class="aiw-fab" :class="{ 'aiw-fab--open': open }" @click="open = !open" :title="open ? 'Свернуть' : 'ИИ-помощник'">
        <BkIcon :name="open ? 'close' : 'analytics'" size="md" />
      </button>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useRoute } from 'vue-router';
import BkIcon from '@/components/ui/BkIcon.vue';
import AiChat from '@/components/ai/AiChat.vue';

const open = ref(false);
const route = useRoute();
// На самой странице ассистента плавающую кнопку не показываем.
const hidden = computed(() => route.name === 'assistant');
</script>

<style scoped>
.aiw-fab {
  position: fixed;
  right: 20px;
  bottom: 20px;
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: var(--tk-accent);
  color: #fff;
  border: none;
  box-shadow: var(--tk-shadow-modal, 0 8px 28px rgba(44,24,16,0.18));
  cursor: pointer;
  z-index: 900;
  display: flex;
  align-items: center;
  justify-content: center;
}
.aiw-fab:hover { background: var(--tk-accent-hover); }
.aiw-fab--open { background: var(--tk-n-700); }

.aiw-panel {
  position: fixed;
  right: 20px;
  bottom: 88px;
  width: 390px;
  max-width: calc(100vw - 40px);
  height: 560px;
  max-height: calc(100vh - 120px);
  background: var(--tk-bg-board);
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-card);
  box-shadow: var(--tk-shadow-modal, 0 12px 40px rgba(44,24,16,0.2));
  z-index: 900;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.aiw-head {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: var(--tk-s-2);
  padding: var(--tk-s-3) var(--tk-s-4);
  background: var(--tk-text);
  color: #fff;
}
.aiw-title { font-weight: var(--tk-fw-semibold); }
.aiw-link { margin-left: auto; color: #fff; opacity: 0.85; font-size: var(--tk-fz-sm); text-decoration: none; }
.aiw-link:hover { opacity: 1; text-decoration: underline; }
.aiw-close { background: none; border: none; color: #fff; cursor: pointer; padding: 0; display: flex; }
.aiw-body { flex: 1; min-height: 0; }

.aiw-fade-enter-active, .aiw-fade-leave-active { transition: opacity 0.15s ease, transform 0.15s ease; }
.aiw-fade-enter-from, .aiw-fade-leave-to { opacity: 0; transform: translateY(8px); }
@media (prefers-reduced-motion: reduce) { .aiw-fade-enter-active, .aiw-fade-leave-active { transition: none; } }

@media (max-width: 600px) {
  .aiw-panel {
    right: 12px; left: 12px;
    width: auto;
    bottom: 84px;
    height: calc(100vh - 160px);
  }
}
</style>
