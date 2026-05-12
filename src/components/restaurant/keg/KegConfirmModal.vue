<template>
  <div v-if="show" class="krt-confirm-overlay" @click.self="$emit('cancel')">
    <div class="krt-confirm">
      <h3>{{ title }}</h3>
      <p>{{ message }}</p>
      <div class="krt-confirm-actions">
        <button class="krt-btn ghost" @click="$emit('cancel')">{{ cancelText }}</button>
        <button
          class="krt-btn"
          :class="danger ? 'danger' : 'primary'"
          @click="$emit('ok')"
        >{{ okText }}</button>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  show: { type: Boolean, default: false },
  title: { type: String, default: '' },
  message: { type: String, default: '' },
  okText: { type: String, default: 'OK' },
  cancelText: { type: String, default: 'Отмена' },
  danger: { type: Boolean, default: false },
});
defineEmits(['ok', 'cancel']);
</script>
