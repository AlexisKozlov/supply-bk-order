<template>
  <Teleport to="body">
    <div class="modal" @click.self="$emit('cancel')">
      <div class="modal-box confirm-modal">
        <h3>{{ title }}</h3>
        <p v-if="message">{{ message }}</p>
        <div class="modal-actions">
          <button class="btn" @click="$emit('cancel')">Отмена</button>
          <button class="btn primary" @click="$emit('confirm')">Подтвердить</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { onMounted, onUnmounted } from 'vue';

defineProps({ title: String, message: String });
const emit = defineEmits(['confirm', 'cancel']);

function onKey(e) {
  if (e.key === 'Enter') { e.preventDefault(); emit('confirm'); }
  else if (e.key === 'Escape') { emit('cancel'); }
}
onMounted(() => document.addEventListener('keydown', onKey));
onUnmounted(() => document.removeEventListener('keydown', onKey));
</script>
