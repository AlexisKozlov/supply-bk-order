<!--
  UiMenu — универсальное выпадающее меню (контекстное, действия карточки/колонки/доски).

  Не отвечает за позиционирование. Родитель ставит position: absolute/fixed.
  Отвечает за визуал шелла + стилизацию пунктов через классы:
    .ui-menu__item            — обычный пункт (кнопка с иконкой+текстом)
    .ui-menu__item--danger    — опасное действие (красный)
    .ui-menu__item--disabled  — недоступный пункт
    .ui-menu__divider         — разделитель между группами
    .ui-menu__submenu-arrow   — стрелка вправо для пункта с подменю

  Использование:
    <UiMenu v-if="open">
      <button class="ui-menu__item" @click="edit">
        <TaskIcon name="edit" :size="14"/> Редактировать
      </button>
      <div class="ui-menu__divider"></div>
      <button class="ui-menu__item ui-menu__item--danger" @click="del">
        <TaskIcon name="trash" :size="14"/> Удалить
      </button>
    </UiMenu>

  Закрытие по Esc, клику вне, выбору пункта — отвечает родитель.
-->

<template>
  <Transition name="ui-menu" appear>
    <div v-if="open" class="ui-menu" role="menu">
      <slot/>
    </div>
  </Transition>
</template>

<script setup>
defineProps({
  open: { type: Boolean, default: true },
});
</script>

<style scoped>
.ui-menu {
  background: var(--tk-bg-popover);
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-md);
  box-shadow: var(--tk-shadow-popover);
  padding: var(--tk-s-1) 0;
  min-width: 180px;
  font-family: var(--tk-font);
  font-size: var(--tk-fz-md);
  color: var(--tk-text);
}

/* Стилизация пунктов меню через :deep — родитель пишет .ui-menu__item */
:deep(.ui-menu__item) {
  display: flex;
  align-items: center;
  gap: var(--tk-s-2);
  width: 100%;
  padding: var(--tk-s-2) var(--tk-s-3);
  background: none;
  border: none;
  cursor: pointer;
  font: inherit;
  color: var(--tk-text);
  text-align: left;
  white-space: nowrap;
  transition: background var(--tk-transition-fast);
  min-height: var(--tk-touch-min);
  box-sizing: border-box;
}
:deep(.ui-menu__item:hover) {
  background: var(--tk-n-100);
}
:deep(.ui-menu__item:focus-visible) {
  outline: none;
  background: var(--tk-n-100);
  box-shadow: inset 0 0 0 2px var(--tk-accent-soft-strong);
}
:deep(.ui-menu__item--danger) {
  color: var(--tk-danger);
}
:deep(.ui-menu__item--danger:hover) {
  background: var(--tk-danger-soft);
}
:deep(.ui-menu__item--disabled),
:deep(.ui-menu__item:disabled) {
  color: var(--tk-text-muted);
  cursor: not-allowed;
}
:deep(.ui-menu__item--disabled:hover),
:deep(.ui-menu__item:disabled:hover) {
  background: none;
}

:deep(.ui-menu__divider) {
  height: 1px;
  background: var(--tk-border-soft);
  margin: var(--tk-s-1) 0;
}

:deep(.ui-menu__submenu-arrow) {
  margin-left: auto;
  color: var(--tk-text-muted);
}

/* Анимация — fade + лёгкое смещение */
.ui-menu-enter-active {
  transition: opacity var(--tk-anim-fast) ease-out, transform var(--tk-anim-fast) ease-out;
}
.ui-menu-leave-active {
  transition: opacity 100ms ease-in, transform 100ms ease-in;
}
.ui-menu-enter-from,
.ui-menu-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}

@media (prefers-reduced-motion: reduce) {
  .ui-menu-enter-active,
  .ui-menu-leave-active {
    transition: opacity 60ms linear;
    transform: none;
  }
  .ui-menu-enter-from,
  .ui-menu-leave-to {
    transform: none;
  }
}
</style>
