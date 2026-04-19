<template>
  <Teleport to="body">
    <div class="modal" @click.self="$emit('close')">
      <div class="modal-box info-modal" :class="type">
        <h3>{{ title }}</h3>
        <p v-if="message" style="white-space: pre-line">{{ message }}</p>
        <div class="modal-actions">
          <button class="btn primary" @click="$emit('close')">OK</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { onMounted, onUnmounted } from 'vue';

defineProps({
  title: String,
  message: String,
  type: { type: String, default: 'info' },
});
const emit = defineEmits(['close']);

function onKey(e) {
  if (e.key === 'Enter' || e.key === 'Escape') {
    e.preventDefault();
    e.stopPropagation();
    emit('close');
  }
}
onMounted(() => document.addEventListener('keydown', onKey));
onUnmounted(() => document.removeEventListener('keydown', onKey));
</script>

<style scoped>
.info-modal.success h3 { color: #16a34a; }
.info-modal.warning h3 { color: #d97706; }
.info-modal.error   h3 { color: #dc2626; }
</style>
