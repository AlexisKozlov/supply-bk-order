<template>
  <div class="asv">
    <div class="asv-header">
      <h1 class="asv-title">ИИ-помощник закупок</h1>
      <p class="asv-subtitle">Спрашивайте про остатки, заказы, поставки, цены, сроки годности и сводки — ИИ отвечает по реальным данным портала.</p>
    </div>
    <div class="asv-chat">
      <AiChat />
    </div>
  </div>
</template>

<script setup>
import AiChat from '@/components/ai/AiChat.vue';
</script>

<style scoped>
.asv {
  display: flex;
  flex-direction: column;
  height: calc(100vh - 70px);
  max-width: 900px;
  padding: var(--tk-s-6) var(--tk-s-6) var(--tk-s-4);
  font-family: var(--tk-font);
  color: var(--tk-text);
}
.asv-header { flex-shrink: 0; margin-bottom: var(--tk-s-4); }
.asv-title {
  margin: 0 0 var(--tk-s-1);
  font-size: var(--tk-fz-h1);
  font-weight: var(--tk-fw-bold);
  color: var(--tk-text);
}
.asv-subtitle { margin: 0; font-size: var(--tk-fz-md); color: var(--tk-text-muted); }
.asv-chat {
  flex: 1;
  min-height: 0;
  background: var(--tk-bg-board);
  border: 1px solid var(--tk-border);
  border-radius: var(--tk-r-card);
  overflow: hidden;
}
@media (max-width: 600px) {
  .asv { padding: var(--tk-s-4) var(--tk-s-3); height: calc(100vh - 56px); }
}
</style>
