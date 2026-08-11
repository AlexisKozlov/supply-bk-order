<template>
  <div class="auth-page">
    <div class="auth-content">
      <div class="auth-card" :class="{ 'auth-card--centered': centered }">
        <slot />
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * Каркас страниц входа и восстановления пароля: фон, карточка по центру.
 * Внутрь кладётся содержимое конкретного экрана.
 *
 * Здесь же один раз подключается общий стиль всех таких экранов, поэтому
 * в сборку он попадает единожды, а не копией в каждом экране.
 */
defineProps({
  // true — карточка-сообщение (подтверждение почты и т.п.), отступы крупнее
  centered: { type: Boolean, default: false },
});
</script>

<style src="@/assets/auth-shell.css"></style>
