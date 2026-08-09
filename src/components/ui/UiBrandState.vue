<template>
  <div class="ubs" :class="{ 'is-standalone': standalone }">
    <div class="ubs-card">
      <div class="ubs-art" aria-hidden="true"><slot name="art" /></div>

      <div class="ubs-content">
        <span v-if="chip" class="ubs-chip">{{ chip }}</span>
        <h1 class="ubs-title"><slot name="title" /></h1>
        <p class="ubs-text"><slot /></p>
        <div class="ubs-actions"><slot name="actions" /></div>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * Полноэкранное фирменное состояние: «нет доступа», «страница не найдена»
 * и всё, что ещё появится (техработы, офлайн).
 *
 * Оболочка одна, чтобы страницы-состояния не разъезжались между собой:
 * раньше 404 и отказ выглядели как из разных продуктов.
 *
 * standalone — страница живёт вне каркаса приложения (без меню), тогда она
 * сама рисует фон и занимает всю высоту.
 */
defineProps({
  chip:       { type: String, default: '' },
  standalone: { type: Boolean, default: false },
});
</script>

<style scoped>
.ubs {
  display: flex; align-items: center; justify-content: center;
  min-height: 72vh;
  padding: var(--tk-s-6) var(--tk-s-4);
}
.ubs.is-standalone {
  min-height: 100vh;
  background: var(--tk-bg-board);
}

/* Кремовая плашка — фирменный фон, а не белый лист интерфейса. */
.ubs-card {
  display: grid;
  grid-template-columns: 260px minmax(0, 1fr);
  align-items: center;
  gap: var(--tk-s-7);
  width: 100%;
  max-width: 860px;
  padding: var(--tk-s-7);
  border-radius: 28px;
  background: var(--brand-cream);
}

.ubs-art { display: flex; justify-content: center; }
.ubs-art :deep(svg),
.ubs-art :deep(video),
.ubs-art :deep(img) { width: 100%; max-width: 260px; height: auto; display: block; }

.ubs-chip {
  display: inline-block;
  padding: 6px var(--tk-s-4);
  border-radius: var(--tk-r-pill);
  background: var(--brand-red);
  color: var(--brand-cream);
  /* Flame — только на крупном заголовке. В мелком тексте он тяжёлый и
     плохо читается, поэтому плашка и кнопки идут интерфейсным шрифтом. */
  font-family: var(--tk-font);
  font-size: var(--tk-fz-sm);
  font-weight: var(--tk-fw-bold);
  letter-spacing: .08em;
  text-transform: uppercase;
}

.ubs-title {
  font-family: 'Flame', var(--tk-font);
  font-size: var(--tk-fz-hero);
  font-weight: var(--tk-fw-bold);
  line-height: 1.02;
  letter-spacing: -.01em;
  color: var(--brand-brown);
  margin: var(--tk-s-4) 0;
  text-transform: uppercase;
}

.ubs-text {
  font-size: var(--tk-fz-xl);
  line-height: var(--tk-lh-base);
  color: var(--brand-brown);
  margin: 0 0 var(--tk-s-6);
  max-width: 42ch;
}
.ubs-text :deep(a) {
  color: var(--brand-red);
  font-weight: var(--tk-fw-bold);
  text-decoration: none;
  box-shadow: inset 0 -2px 0 currentColor;
}
.ubs-text :deep(a:focus-visible) {
  outline: none; box-shadow: var(--tk-focus-ring); border-radius: var(--tk-r-sm);
}

.ubs-actions { display: flex; flex-wrap: wrap; gap: var(--tk-s-3); }
.ubs-actions :deep(.ubs-btn) {
  display: inline-flex; align-items: center; justify-content: center;
  min-height: var(--tk-touch-min);
  padding: var(--tk-s-3) var(--tk-s-6);
  border: 2.5px solid var(--brand-brown);
  border-radius: var(--tk-r-pill);
  background: transparent;
  color: var(--brand-brown);
  font-family: var(--tk-font);
  font-size: var(--tk-fz-xl);
  font-weight: var(--tk-fw-semibold);
  text-decoration: none;
  cursor: pointer;
  transition: background var(--tk-transition), color var(--tk-transition),
              border-color var(--tk-transition);
}
.ubs-actions :deep(.ubs-btn:hover) { background: var(--brand-brown); color: var(--brand-cream); }
.ubs-actions :deep(.ubs-btn:focus-visible) { outline: none; box-shadow: var(--tk-focus-ring); }
.ubs-actions :deep(.ubs-btn-primary) {
  border-color: var(--brand-red);
  background: var(--brand-red);
  color: var(--brand-cream);
}
.ubs-actions :deep(.ubs-btn-primary:hover) { background: var(--brand-brown); border-color: var(--brand-brown); }

@media (max-width: 760px) {
  .ubs { min-height: auto; padding: var(--tk-s-4) var(--tk-s-3); }
  .ubs.is-standalone { min-height: 100vh; align-items: flex-start; padding-top: var(--tk-s-7); }
  .ubs-card {
    grid-template-columns: 1fr;
    gap: var(--tk-s-5);
    padding: var(--tk-s-6) var(--tk-s-5);
    border-radius: 22px;
  }
  .ubs-art :deep(svg),
  .ubs-art :deep(video),
  .ubs-art :deep(img) { max-width: 168px; }
  /* Перенос в заголовке НЕ скрываем. Пробовали `br { display: none }` —
     слова склеивались в «СТРАНИЦЫНЕ», потому что вокруг переноса пробела нет. */
  .ubs-title { font-size: var(--tk-fz-display); }
  .ubs-text { font-size: var(--tk-fz-lg); }
  .ubs-actions { width: 100%; }
  .ubs-actions :deep(.ubs-btn) { flex: 1 1 auto; }
}
</style>
