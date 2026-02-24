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
defineProps({ title: String, message: String });
defineEmits(['confirm', 'cancel']);
</script>
