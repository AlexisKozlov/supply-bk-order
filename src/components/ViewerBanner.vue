<template>
  <div class="viewer-placeholder">
    <div class="viewer-placeholder-inner">
      <BkIcon name="eye" size="lg"/>
      <h2>Режим просмотра</h2>
      <p>Вы можете просматривать сохранённые {{ itemsLabel }} через раздел <b>История</b>.</p>
      <button class="btn primary" @click="$router.push({ name: 'history' })"><BkIcon name="history" size="sm"/> Перейти в историю</button>
    </div>
  </div>
</template>

<script setup>
import BkIcon from './ui/BkIcon.vue';

defineProps({
  itemsLabel: { type: String, default: 'данные' }
});
</script>
