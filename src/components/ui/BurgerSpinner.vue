<template>
  <div class="supply-spinner" :class="[sizeClass]">
    <div class="ss-icon">
      <svg viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" fill="none" class="ss-svg">
        <circle cx="16" cy="16" r="10" fill="#D62300" class="ss-circle ss-c1"/>
        <circle cx="32" cy="16" r="10" fill="#F5A623" class="ss-circle ss-c2"/>
        <circle cx="16" cy="32" r="10" fill="#FF8733" class="ss-circle ss-c3"/>
        <circle cx="32" cy="32" r="10" fill="#FFD54F" class="ss-circle ss-c4"/>
        <circle cx="24" cy="24" r="8.5" fill="#502314"/>
        <text x="24" y="29" text-anchor="middle" fill="white" font-size="14" font-weight="900" font-family="Arial, sans-serif">S</text>
      </svg>
    </div>
    <div v-if="text" class="ss-text">{{ text }}</div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
const props = defineProps({
  size: { type: String, default: 'md' },
  text: { type: String, default: '' },
});
const sizeClass = computed(() => `ss-${props.size}`);
</script>

<style scoped>
.supply-spinner {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
}
.ss-icon { display: flex; align-items: center; justify-content: center; }
.ss-svg { display: block; }

.ss-circle {
  animation: ss-pulse 1.6s ease-in-out infinite;
  transform-origin: center center;
}
.ss-c1 { animation-delay: 0s; }
.ss-c2 { animation-delay: 0.2s; }
.ss-c3 { animation-delay: 0.4s; }
.ss-c4 { animation-delay: 0.6s; }

@keyframes ss-pulse {
  0%, 100% { opacity: 0.5; transform: scale(0.92); }
  50% { opacity: 1; transform: scale(1); }
}

.ss-xs .ss-svg { width: 20px; height: 20px; }
.ss-sm .ss-svg { width: 28px; height: 28px; }
.ss-md .ss-svg { width: 40px; height: 40px; }
.ss-lg .ss-svg { width: 56px; height: 56px; }

.ss-text {
  color: var(--text-muted, #9B8B7E);
  font-size: 12px;
  font-weight: 600;
  animation: ss-text-pulse 1.5s ease-in-out infinite;
}
@keyframes ss-text-pulse {
  0%, 100% { opacity: 0.5; }
  50% { opacity: 1; }
}
</style>
