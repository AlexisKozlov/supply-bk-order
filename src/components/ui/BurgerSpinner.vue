<template>
  <div class="burger-spinner" :class="[sizeClass]">
    <div class="bs-stack">
      <div class="bs-layer bs-bun-top"></div>
      <div class="bs-layer bs-lettuce"></div>
      <div class="bs-layer bs-cheese"></div>
      <div class="bs-layer bs-patty"></div>
      <div class="bs-layer bs-bun-bottom"></div>
    </div>
    <div v-if="text" class="bs-text">{{ text }}</div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
const props = defineProps({
  size: { type: String, default: 'md' },
  text: { type: String, default: '' },
});
const sizeClass = computed(() => `bs-${props.size}`);
</script>

<style scoped>
.burger-spinner {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}

.bs-stack {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0;
}

.bs-layer {
  opacity: 0;
  transform: translateY(-30px) scale(0.7);
}

/* ── Bun top ── */
.bs-bun-top {
  background: linear-gradient(180deg, #F5A623 0%, #D4881A 100%);
  border-radius: 45px 45px 3px 3px;
  animation: bs-drop 0.35s ease forwards 0.15s;
  box-shadow: inset 0 -2px 0 rgba(0,0,0,0.1);
  position: relative;
}
.bs-bun-top::after {
  content: '';
  position: absolute;
  top: 25%;
  left: 22%;
  width: 8%;
  height: 20%;
  background: rgba(255,255,255,0.5);
  border-radius: 50%;
  box-shadow: 200% 30% 0 rgba(255,255,255,0.4), 400% -15% 0 rgba(255,255,255,0.3);
}

/* ── Lettuce ── */
.bs-lettuce {
  background: #4CAF50;
  animation: bs-drop 0.35s ease forwards 0.4s;
  clip-path: polygon(0% 50%, 5% 0%, 12% 60%, 20% 10%, 28% 55%, 35% 5%, 43% 50%, 50% 0%, 58% 55%, 65% 8%, 73% 50%, 80% 5%, 88% 55%, 95% 10%, 100% 50%, 100% 100%, 0% 100%);
}

/* ── Cheese ── */
.bs-cheese {
  background: #FDBD10;
  animation: bs-drop 0.35s ease forwards 0.6s;
  clip-path: polygon(0% 0%, 100% 0%, 100% 60%, 92% 100%, 75% 60%, 58% 100%, 42% 60%, 25% 100%, 8% 60%, 0% 100%);
}

/* ── Patty ── */
.bs-patty {
  background: linear-gradient(180deg, #6D3A1F 0%, #4A2510 100%);
  border-radius: 3px;
  animation: bs-drop 0.35s ease forwards 0.8s;
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.08), inset 0 -1px 0 rgba(0,0,0,0.2);
}

/* ── Bun bottom ── */
.bs-bun-bottom {
  background: linear-gradient(180deg, #D4881A 0%, #C47A15 100%);
  border-radius: 3px 3px 14px 14px;
  animation: bs-drop 0.35s ease forwards 0.1s;
  box-shadow: 0 3px 8px rgba(0,0,0,0.2);
}

/* ── Sizes ── */
.bs-sm .bs-stack { gap: 0; }
.bs-sm .bs-bun-top    { width: 36px; height: 12px; }
.bs-sm .bs-lettuce    { width: 40px; height: 5px; }
.bs-sm .bs-cheese     { width: 38px; height: 5px; }
.bs-sm .bs-patty      { width: 36px; height: 8px; }
.bs-sm .bs-bun-bottom { width: 38px; height: 7px; }

.bs-md .bs-bun-top    { width: 56px; height: 18px; }
.bs-md .bs-lettuce    { width: 60px; height: 7px; }
.bs-md .bs-cheese     { width: 58px; height: 7px; }
.bs-md .bs-patty      { width: 54px; height: 12px; }
.bs-md .bs-bun-bottom { width: 58px; height: 10px; }

.bs-lg .bs-bun-top    { width: 90px; height: 28px; }
.bs-lg .bs-lettuce    { width: 96px; height: 10px; }
.bs-lg .bs-cheese     { width: 94px; height: 10px; }
.bs-lg .bs-patty      { width: 88px; height: 18px; }
.bs-lg .bs-bun-bottom { width: 92px; height: 16px; }

@keyframes bs-drop {
  0%   { opacity: 0; transform: translateY(-30px) scale(0.7) rotate(-5deg); }
  60%  { opacity: 1; transform: translateY(3px) scale(1.05) rotate(1deg); }
  80%  { transform: translateY(-1px) scale(0.98) rotate(0deg); }
  100% { opacity: 1; transform: translateY(0) scale(1) rotate(0deg); }
}

.bs-text {
  color: var(--text-muted, #9B8B7E);
  font-size: 12px;
  font-weight: 600;
  animation: bs-pulse 1.5s ease-in-out infinite;
}

@keyframes bs-pulse {
  0%, 100% { opacity: 0.5; }
  50% { opacity: 1; }
}
</style>
