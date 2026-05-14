<!--
  UiEmptyState — единый шелл пустого состояния.

  Применяется везде, где «нечего показать»:
  - пустая колонка задач
  - пустая доска
  - нет результатов поиска
  - пустой архив
  - пустой список меток/исполнителей в поповере

  Использование:
    <UiEmptyState
      title="Нет задач"
      description="Создай первую — это займёт пару секунд."
      action-label="+ Создать карточку"
      @action="createCard"
    >
      <template #icon>
        <TaskIcon name="clipboard" :size="48"/>
      </template>
    </UiEmptyState>

  Иконка через слот — чтобы можно было передать любой компонент (TaskIcon, BkIcon, кастомный SVG).
  Действие — либо через action-label + @action, либо через слот #action для нестандартной кнопки.
-->

<template>
  <div class="ui-empty">
    <div v-if="$slots.icon" class="ui-empty__icon">
      <slot name="icon"/>
    </div>
    <h3 v-if="title" class="ui-empty__title">{{ title }}</h3>
    <p v-if="description" class="ui-empty__description">{{ description }}</p>
    <div v-if="$slots.action || actionLabel" class="ui-empty__action">
      <slot name="action">
        <button v-if="actionLabel" class="ui-empty__btn" type="button" @click="$emit('action')">
          {{ actionLabel }}
        </button>
      </slot>
    </div>
  </div>
</template>

<script setup>
defineProps({
  title:       { type: String, default: '' },
  description: { type: String, default: '' },
  actionLabel: { type: String, default: '' },
});
defineEmits(['action']);
</script>

<style scoped>
.ui-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: var(--tk-s-7) var(--tk-s-4);
  color: var(--tk-text-secondary);
  font-family: var(--tk-font);
}

.ui-empty__icon {
  width: 48px;
  height: 48px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--tk-n-300);
  margin-bottom: var(--tk-s-3);
}

.ui-empty__title {
  margin: 0 0 var(--tk-s-1);
  font-size: var(--tk-fz-xl);
  font-weight: var(--tk-fw-semibold);
  color: var(--tk-text);
  line-height: var(--tk-lh-tight);
}

.ui-empty__description {
  margin: 0 0 var(--tk-s-3);
  font-size: var(--tk-fz-md);
  color: var(--tk-text-muted);
  line-height: var(--tk-lh-base);
  max-width: 320px;
}

.ui-empty__action {
  margin-top: var(--tk-s-2);
}

.ui-empty__btn {
  display: inline-flex;
  align-items: center;
  gap: var(--tk-s-2);
  padding: var(--tk-s-2) var(--tk-s-4);
  background: var(--tk-accent);
  color: #FFFFFF;
  border: none;
  border-radius: var(--tk-r-md);
  font-family: inherit;
  font-size: var(--tk-fz-md);
  font-weight: var(--tk-fw-medium);
  cursor: pointer;
  transition: background var(--tk-transition-fast);
  min-height: var(--tk-touch-min);
  box-sizing: border-box;
}
.ui-empty__btn:hover {
  background: var(--tk-accent-hover);
}
.ui-empty__btn:focus-visible {
  outline: none;
  box-shadow: var(--tk-focus-ring);
}
</style>
