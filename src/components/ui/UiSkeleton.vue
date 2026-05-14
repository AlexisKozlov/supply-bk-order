<!--
  UiSkeleton — скелетон-плейсхолдер для loading-состояний.

  Универсальная замена спиннеру: вместо «крутится» показывается серый прямоугольник
  с лёгкой shimmer-анимацией. Подсознательно сигналит «контент загружается, форма
  будет такая же» и не сбивает layout.

  Использование:
    <!-- Прямоугольный скелетон (по умолчанию) -->
    <UiSkeleton width="200px" height="20px"/>

    <!-- Круглый аватар-плейсхолдер -->
    <UiSkeleton width="32px" height="32px" shape="circle"/>

    <!-- Скелетон карточки задачи -->
    <div class="card-skeleton">
      <UiSkeleton width="60%" height="14px"/>
      <UiSkeleton width="100%" height="12px"/>
      <UiSkeleton width="40%" height="12px"/>
    </div>

  Поддерживает prefers-reduced-motion: при включённой опции анимация отключается,
  остаётся статичный серый блок.
-->

<template>
  <span class="ui-skeleton" :class="shapeClass" :style="style"></span>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  width:  { type: [String, Number], default: '100%' },
  height: { type: [String, Number], default: '1em' },
  shape:  { type: String, default: 'rect' },  // 'rect' | 'circle' | 'pill'
});

const style = computed(() => ({
  width:  typeof props.width  === 'number' ? props.width  + 'px' : props.width,
  height: typeof props.height === 'number' ? props.height + 'px' : props.height,
}));

const shapeClass = computed(() => 'ui-skeleton--' + props.shape);
</script>

<style scoped>
.ui-skeleton {
  display: inline-block;
  background: linear-gradient(
    90deg,
    var(--tk-n-100) 0%,
    var(--tk-n-200) 50%,
    var(--tk-n-100) 100%
  );
  background-size: 200% 100%;
  animation: ui-skeleton-shimmer 1.5s ease-in-out infinite;
  border-radius: var(--tk-r-sm);
  vertical-align: middle;
}

.ui-skeleton--circle {
  border-radius: var(--tk-r-pill);
}

.ui-skeleton--pill {
  border-radius: var(--tk-r-pill);
}

@keyframes ui-skeleton-shimmer {
  0%   { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

@media (prefers-reduced-motion: reduce) {
  .ui-skeleton {
    animation: none;
    background: var(--tk-n-100);
  }
}
</style>
