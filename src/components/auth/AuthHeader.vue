<template>
  <div class="auth-header">
    <div class="auth-header-icon" :class="{ 'auth-header-icon--error': tone === 'error' }">
      <slot name="icon" />
    </div>
    <div>
      <h1>{{ title }}</h1>
      <p v-if="$slots.default || subtitle">
        <slot>{{ subtitle }}</slot>
      </p>
    </div>
  </div>
</template>

<script setup>
/** Шапка карточки: значок слева, заголовок и подпись справа. */
defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  // 'error' — красный фон значка (недействительная ссылка)
  tone: { type: String, default: 'brand' },
});
</script>
