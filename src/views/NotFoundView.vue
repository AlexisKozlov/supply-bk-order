<template>
  <div class="not-found">
    <div class="not-found-inner">
      <div class="not-found-code">404</div>
      <h1 class="not-found-title">Страница не найдена</h1>
      <p class="not-found-text">Такой страницы не существует или она была перемещена.</p>
      <router-link :to="{ name: 'home' }" class="btn primary not-found-btn">На главную</router-link>
    </div>
  </div>
</template>

<style scoped>
.not-found {
  display: flex; align-items: center; justify-content: center;
  min-height: 80vh; padding: 40px 20px; text-align: center;
}
.not-found-inner { max-width: 400px; }
.not-found-code {
  font-size: 96px; font-weight: 900; line-height: 1;
  color: var(--bk-orange, #F5A623); opacity: 0.8;
}
.not-found-title {
  font-size: 22px; font-weight: 700; margin: 12px 0 8px;
  color: var(--text, #333);
}
.not-found-text {
  font-size: 14px; color: var(--text-muted, #999); margin-bottom: 24px;
}
.not-found-btn { display: inline-flex; text-decoration: none; }
</style>
