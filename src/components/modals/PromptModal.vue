<template>
  <Teleport to="body">
    <div class="modal" @click.self="$emit('cancel')">
      <div class="modal-box prompt-modal">
        <h3>{{ title }}</h3>
        <p v-if="message" class="prompt-message">{{ message }}</p>
        <input ref="inp" v-model="local" type="text" class="prompt-input"
               :placeholder="placeholder" @keydown.enter.prevent="submit" @keydown.esc.prevent="$emit('cancel')" />
        <div class="modal-actions">
          <button class="btn" @click="$emit('cancel')">{{ cancelText || 'Отмена' }}</button>
          <button class="btn primary" @click="submit" :disabled="!local.trim()">{{ okText || 'Сохранить' }}</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, onMounted, watch, nextTick } from 'vue';

const props = defineProps({
  title: String,
  message: String,
  value: { type: String, default: '' },
  placeholder: String,
  okText: String,
  cancelText: String,
});
const emit = defineEmits(['ok', 'cancel']);

const local = ref(props.value || '');
const inp = ref(null);

watch(() => props.value, v => { local.value = v || ''; });

onMounted(async () => {
  await nextTick();
  inp.value?.focus?.();
  inp.value?.select?.();
});

function submit() {
  const v = local.value.trim();
  if (!v) return;
  emit('ok', v);
}
</script>

<style scoped>
.prompt-modal { min-width: 360px; max-width: 480px; }
.prompt-message { margin: 0 0 12px; color: #5e6878; font-size: 13px; }
.prompt-input {
  width: 100%; box-sizing: border-box;
  padding: 8px 12px; font-size: 14px;
  border: 1px solid #DCDFE4; border-radius: 6px;
  background: #fff; color: #172B4D; font-family: inherit;
  margin-bottom: 16px;
}
.prompt-input:focus { outline: none; border-color: #E87A1E; box-shadow: 0 0 0 2px rgba(232,122,30,0.30); }
</style>
