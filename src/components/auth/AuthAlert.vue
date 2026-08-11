<template>
  <div :class="type === 'success' ? 'auth-success' : 'auth-error'">
    <svg v-if="type === 'success'" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <polyline points="20 6 9 17 4 12" />
    </svg>
    <svg v-else width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <circle cx="12" cy="12" r="10" />
      <line x1="15" y1="9" x2="9" y2="15" />
      <line x1="9" y1="9" x2="15" y2="15" />
    </svg>
    <span><slot /></span>
  </div>
</template>

<script setup>
/** Полоска с ошибкой или сообщением об успехе на экранах входа. */
defineProps({
  type: { type: String, default: 'error' }, // 'error' | 'success'
});
</script>
