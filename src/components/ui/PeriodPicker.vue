<template>
  <div class="pp">
    <select v-model="kindOrPreset" @change="onSelectChange" class="pp-select">
      <option v-for="p in presets" :key="p.value" :value="'preset:' + p.value">{{ p.label }}</option>
      <option value="custom">Свой период</option>
    </select>
    <template v-if="isCustom">
      <input type="date" v-model="customFrom" :max="customTo || undefined" @change="onCustomChange" class="pp-date" />
      <span class="pp-dash">—</span>
      <input type="date" v-model="customTo" :min="customFrom || undefined" @change="onCustomChange" class="pp-date" />
    </template>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
  modelValue: { type: Object, required: true },
  presets: {
    type: Array,
    default: () => [
      { value: '7', label: '7 дней' },
      { value: '14', label: '14 дней' },
      { value: '30', label: '30 дней' },
      { value: '90', label: '3 месяца' },
    ],
  },
});

const emit = defineEmits(['update:modelValue']);

const kindOrPreset = ref(
  props.modelValue.kind === 'custom'
    ? 'custom'
    : 'preset:' + (props.modelValue.preset || '30')
);
const customFrom = ref(props.modelValue.from || '');
const customTo = ref(props.modelValue.to || '');

const isCustom = computed(() => kindOrPreset.value === 'custom');

function onSelectChange() {
  if (kindOrPreset.value === 'custom') {
    emit('update:modelValue', { kind: 'custom', preset: '', from: customFrom.value, to: customTo.value });
  } else {
    const preset = kindOrPreset.value.replace('preset:', '');
    emit('update:modelValue', { kind: 'preset', preset, from: '', to: '' });
  }
}

function onCustomChange() {
  emit('update:modelValue', { kind: 'custom', preset: '', from: customFrom.value, to: customTo.value });
}

watch(
  () => props.modelValue,
  (val) => {
    if (val.kind === 'custom') {
      kindOrPreset.value = 'custom';
      customFrom.value = val.from || '';
      customTo.value = val.to || '';
    } else {
      kindOrPreset.value = 'preset:' + (val.preset || '30');
      customFrom.value = '';
      customTo.value = '';
    }
  },
  { deep: true }
);
</script>

<style scoped>
.pp { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.pp-select, .pp-date {
  padding: 5px 8px;
  border: 1.5px solid var(--border);
  border-radius: 8px;
  background: var(--card);
  color: var(--text);
  font-size: 12px;
}
.pp-date { padding: 4px 6px; }
.pp-dash { color: var(--text-muted); font-size: 12px; }
</style>
