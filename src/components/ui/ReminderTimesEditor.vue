<template>
  <div class="rte">
    <div v-if="!items.length && fallbackItems.length" class="rte-fallback">
      <div class="rte-fallback-title">Используются дефолты поставщика:</div>
      <ul class="rte-fallback-list">
        <li v-for="(f, i) in fallbackItems" :key="i">{{ fallbackLabel(f) }}</li>
      </ul>
      <div class="rte-fallback-hint">Чтобы переопределить только для этой связки — добавьте свои времена ниже.</div>
    </div>
    <div v-else-if="!items.length" class="rte-empty">
      Напоминания не настроены — заявка пройдёт без оклика (только финальное за 5 мин до дедлайна).
    </div>
    <div v-for="(it, idx) in items" :key="idx" class="rte-row">
      <select v-model.number="it.days_before" class="rte-select" @change="emitNow">
        <option :value="0">в день подачи</option>
        <option :value="1">накануне</option>
        <option :value="2">за 2 дня</option>
        <option :value="3">за 3 дня</option>
        <option :value="4">за 4 дня</option>
        <option :value="5">за 5 дней</option>
        <option :value="6">за 6 дней</option>
        <option :value="7">за 7 дней</option>
      </select>
      <input type="time" v-model="it.time" class="rte-time" @change="emitNow" />
      <button type="button" class="rte-rm" @click="remove(idx)" title="Удалить">×</button>
    </div>
    <button type="button" class="rte-add" @click="add">+ добавить время</button>
  </div>
</template>

<script setup>
import { ref, watch, computed } from 'vue';

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  fallback: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:modelValue']);

function normalize(v) {
  if (!Array.isArray(v)) return [];
  return v
    .filter(x => x && /^\d{1,2}:\d{2}/.test(x.time || ''))
    .map(x => ({ days_before: Number(x.days_before) | 0, time: String(x.time).slice(0, 5) }));
}

const items = ref(normalize(props.modelValue));

// Из-вне (открыли модалку/сменили строку) — синхронизируемся только если значение
// действительно отличается. Иначе зацикливаемся: emit → prop → watch → emit → ...
watch(() => props.modelValue, (v) => {
  const next = normalize(v);
  if (JSON.stringify(next) !== JSON.stringify(items.value)) {
    items.value = next;
  }
}, { deep: true });

function emitNow() {
  emit('update:modelValue', items.value.map(it => ({ days_before: it.days_before, time: it.time })));
}

function add() {
  // По умолчанию: накануне 17:00 либо в день подачи 08:00
  const defaultRow = items.value.some(i => i.days_before === 1)
    ? { days_before: 0, time: '08:00' }
    : { days_before: 1, time: '17:00' };
  items.value.push(defaultRow);
  emitNow();
}

function remove(idx) {
  items.value.splice(idx, 1);
  emitNow();
}

const fallbackItems = computed(() => normalize(props.fallback));

function fallbackLabel(f) {
  const db = Number(f.days_before) | 0;
  const t = String(f.time).slice(0, 5);
  if (db === 0) return `в день подачи в ${t}`;
  if (db === 1) return `накануне в ${t}`;
  return `за ${db} дн. в ${t}`;
}
</script>

<style scoped>
.rte { display: flex; flex-direction: column; gap: 6px; }
.rte-empty {
  padding: 8px 10px;
  background: #fffbe8;
  border: 1px dashed #f1d28a;
  border-radius: 6px;
  font-size: 12px;
  color: #8a6b00;
  line-height: 1.4;
}
.rte-fallback {
  padding: 8px 10px;
  background: #f0f7ed;
  border: 1px dashed #c4e6c8;
  border-radius: 6px;
  font-size: 12px;
  color: #2e5e30;
  line-height: 1.4;
}
.rte-fallback-title { font-weight: 600; margin-bottom: 4px; }
.rte-fallback-list { margin: 0 0 6px 0; padding-left: 18px; }
.rte-fallback-hint { font-size: 11px; color: #5d735d; }
.rte-row {
  display: flex;
  align-items: center;
  gap: 8px;
}
.rte-select {
  flex: 1;
  padding: 6px 8px;
  border: 1px solid #ddd;
  border-radius: 6px;
  font-size: 13px;
  background: #fff;
  min-width: 0;
}
.rte-time {
  width: 90px;
  padding: 6px 8px;
  border: 1px solid #ddd;
  border-radius: 6px;
  font-size: 13px;
}
.rte-rm {
  width: 26px;
  height: 26px;
  border: none;
  background: transparent;
  color: #b30000;
  border-radius: 50%;
  cursor: pointer;
  font-size: 18px;
  line-height: 1;
}
.rte-rm:hover { background: #ffeaea; }
.rte-add {
  align-self: flex-start;
  padding: 5px 10px;
  border: 1px dashed #b0bdcc;
  background: transparent;
  color: #4a5260;
  font-size: 12px;
  border-radius: 6px;
  cursor: pointer;
}
.rte-add:hover { background: #f4f7fb; color: #2b2b2b; border-color: #8a93a0; }
</style>
