<template>
  <Teleport to="body">
    <div class="modal" @click.self="$emit('cancel')">
      <div class="modal-box confirm-modal">
        <h3>{{ title }}</h3>
        <p v-if="message" style="white-space: pre-line">{{ message }}</p>
        <div class="modal-actions">
          <button class="btn" @click="$emit('cancel')">{{ cancelText || 'Отмена' }}</button>
          <button class="btn primary" :class="{ danger: danger || dangerous }" @click="$emit('confirm')">{{ okText || 'Подтвердить' }}</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { onMounted, onUnmounted } from 'vue';

const props = defineProps({
  title: String,
  message: String,
  okText: String,
  cancelText: String,
  danger: { type: Boolean, default: false },
  dangerous: { type: Boolean, default: false },
});
const emit = defineEmits(['confirm', 'cancel']);

function onKey(e) {
  if (e.key === 'Enter' && !props.dangerous && !props.danger) { e.preventDefault(); e.stopPropagation(); emit('confirm'); }
  else if (e.key === 'Escape') { e.stopPropagation(); emit('cancel'); }
}
onMounted(() => document.addEventListener('keydown', onKey));
onUnmounted(() => document.removeEventListener('keydown', onKey));
</script>
