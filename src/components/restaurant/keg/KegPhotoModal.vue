<template>
  <div v-if="show" class="krt-photo-overlay" @click.self="$emit('close')">
    <div class="krt-photo-modal">
      <div class="krt-photo-head">
        <span>{{ name }}</span>
        <button class="krt-photo-close" @click="$emit('close')" aria-label="Закрыть">×</button>
      </div>
      <img :src="url" :alt="name" />
    </div>
  </div>
</template>

<script setup>
defineProps({
  show: { type: Boolean, default: false },
  url: { type: String, default: '' },
  name: { type: String, default: '' },
});
defineEmits(['close']);
</script>
