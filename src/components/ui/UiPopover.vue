<!--
  UiPopover — универсальная оболочка поповера/диалога.

  Не отвечает за позиционирование на странице (это делает родитель через position: fixed/absolute).
  Отвечает за визуал: фон, рамка, радиус, тень, анимация появления/исчезновения,
  семантика секций (заголовок / контент / футер).

  Использование:
    <UiPopover v-if="open" title="Срок" @close="open = false">
      <DatePicker ... />
    </UiPopover>

  Или со слотами:
    <UiPopover :open="open">
      <template #header>Кастомный заголовок</template>
      <DatePicker ... />
      <template #footer><UiButton>Применить</UiButton></template>
    </UiPopover>

  Закрытие по Esc и клику вне — отвечает родитель. UiPopover только рендерит шелл.
-->

<template>
  <Transition name="ui-popover" appear>
    <div v-if="open" class="ui-popover" role="dialog" :aria-label="title || undefined">
      <header v-if="$slots.header || title" class="ui-popover__header">
        <slot name="header">{{ title }}</slot>
      </header>
      <div class="ui-popover__content">
        <slot/>
      </div>
      <footer v-if="$slots.footer" class="ui-popover__footer">
        <slot name="footer"/>
      </footer>
    </div>
  </Transition>
</template>

<script setup>
defineProps({
  open:  { type: Boolean, default: true },
  title: { type: String,  default: '' },
});
</script>

<style scoped>
.ui-popover {
  background: var(--tk-bg-popover);
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-lg);
  box-shadow: var(--tk-shadow-popover);
  min-width: 200px;
  max-width: 480px;
  font-family: var(--tk-font);
  color: var(--tk-text);
  font-size: var(--tk-fz-md);
  overflow: hidden;
}

.ui-popover__header {
  padding: var(--tk-s-3);
  border-bottom: 1px solid var(--tk-border-soft);
  font-size: var(--tk-fz-md);
  font-weight: var(--tk-fw-semibold);
  color: var(--tk-text-secondary);
}

.ui-popover__content {
  padding: var(--tk-s-3);
}

.ui-popover__footer {
  padding: var(--tk-s-3);
  border-top: 1px solid var(--tk-border-soft);
  display: flex;
  justify-content: flex-end;
  gap: var(--tk-s-2);
}

/* Появление: fade + slide-up 4px, 180ms. */
.ui-popover-enter-active {
  transition: opacity var(--tk-anim) ease-out, transform var(--tk-anim) ease-out;
}
.ui-popover-leave-active {
  transition: opacity var(--tk-anim-fast) ease-in, transform var(--tk-anim-fast) ease-in;
}
.ui-popover-enter-from,
.ui-popover-leave-to {
  opacity: 0;
  transform: translateY(4px);
}

@media (prefers-reduced-motion: reduce) {
  .ui-popover-enter-active,
  .ui-popover-leave-active {
    transition: opacity 60ms linear;
    transform: none;
  }
  .ui-popover-enter-from,
  .ui-popover-leave-to {
    transform: none;
  }
}
</style>
