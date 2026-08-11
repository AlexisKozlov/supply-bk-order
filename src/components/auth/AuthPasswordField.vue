<template>
  <div class="auth-field">
    <label>
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
        <path d="M7 11V7a5 5 0 0 1 10 0v4" />
      </svg>
      {{ label }}
    </label>
    <div class="auth-input-wrap" :class="{ 'auth-input-wrap--eye': withEye }">
      <input
        :value="modelValue"
        :type="visible ? 'text' : 'password'"
        :placeholder="placeholder"
        :minlength="minlength || undefined"
        :disabled="disabled"
        autocomplete="new-password"
        required
        @input="$emit('update:modelValue', $event.target.value)"
      />
      <button v-if="withEye" type="button" class="auth-eye" tabindex="-1" @click="$emit('update:visible', !visible)">
        <svg class="pw-eye-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <template v-if="visible">
            <path d="M3 3 21 21" />
            <path d="M10.6 6.2A9.6 9.6 0 0 1 12 6c6 0 9.5 6 9.5 6a16 16 0 0 1-3.2 3.8" />
            <path d="M6.6 8.3A16 16 0 0 0 2.5 12S6 18 12 18c1.5 0 2.8-.4 4-.9" />
            <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2" />
          </template>
          <template v-else>
            <path d="M2.5 12S6 6 12 6s9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" />
            <circle cx="12" cy="12" r="3" />
          </template>
        </svg>
      </button>
    </div>
  </div>
</template>

<script setup>
/** Поле пароля с замочком в подписи и кнопкой «показать пароль». */
defineProps({
  modelValue: { type: String, default: '' },
  label: { type: String, required: true },
  placeholder: { type: String, default: '' },
  minlength: { type: [Number, String], default: 0 },
  disabled: { type: Boolean, default: false },
  // глазок рисуем только у первого поля — он показывает оба
  withEye: { type: Boolean, default: false },
  visible: { type: Boolean, default: false },
});

defineEmits(['update:modelValue', 'update:visible']);
</script>
