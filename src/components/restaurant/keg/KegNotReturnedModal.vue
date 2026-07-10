<template>
  <div v-if="show" class="krt-confirm-overlay" @click.self="onCancel">
    <div class="krt-confirm krt-nr-modal">
      <h3>Кеги не сдал</h3>
      <p class="krt-nr-sub">Укажите причину — её увидят отдел закупок и бухгалтерия.</p>

      <div class="krt-nr-reasons">
        <label v-for="r in reasons" :key="r" class="krt-nr-reason" :class="{ 'is-active': preset === r }">
          <input type="radio" :value="r" v-model="preset" />
          <span>{{ r }}</span>
        </label>
      </div>

      <textarea
        v-model="comment"
        class="krt-nr-comment"
        rows="2"
        :placeholder="isOther ? 'Опишите причину (обязательно)' : 'Комментарий (необязательно)'"
      ></textarea>

      <div class="krt-confirm-actions">
        <button class="krt-btn ghost" @click="onCancel">Отмена</button>
        <button class="krt-btn danger" :disabled="!canConfirm" @click="onConfirm">Подтвердить</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { KEG_NOT_RETURNED_REASONS } from './kegHelpers.js';

const props = defineProps({
  show: { type: Boolean, default: false },
});
const emit = defineEmits(['confirm', 'cancel']);

const reasons = KEG_NOT_RETURNED_REASONS;
const preset = ref('');
const comment = ref('');

const isOther = computed(() => preset.value === 'Другое');
const canConfirm = computed(() => {
  if (!preset.value) return false;
  if (isOther.value) return comment.value.trim().length > 0;
  return true;
});

// Сброс при каждом открытии
watch(() => props.show, (v) => {
  if (v) { preset.value = ''; comment.value = ''; }
});

function buildReason() {
  const c = comment.value.trim();
  if (isOther.value) return c;                 // «Другое» — только текст пользователя
  return c ? `${preset.value} — ${c}` : preset.value;
}
function onConfirm() {
  if (!canConfirm.value) return;
  emit('confirm', buildReason());
}
function onCancel() {
  emit('cancel');
}
</script>

<style scoped>
.krt-nr-modal { text-align: left; max-width: 420px; }
.krt-nr-sub { color: #6B5344; font-size: 13.5px; margin: 0 0 14px; line-height: 1.45; }
.krt-nr-reasons { display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px; }
.krt-nr-reason {
  display: flex; align-items: center; gap: 10px;
  padding: 11px 13px; border: 1.5px solid #E8DCC8; border-radius: 10px;
  cursor: pointer; font-size: 14.5px; color: #2C1A12; transition: all .12s;
}
.krt-nr-reason:hover { border-color: #E0B4A6; }
.krt-nr-reason.is-active { border-color: #E53935; background: #FDECEA; }
.krt-nr-reason input { accent-color: #E53935; width: 18px; height: 18px; flex-shrink: 0; }
.krt-nr-comment {
  width: 100%; box-sizing: border-box; padding: 10px 12px;
  border: 1.5px solid #E8DCC8; border-radius: 10px; font: inherit; resize: vertical;
  margin-bottom: 4px;
}
.krt-nr-comment:focus { outline: none; border-color: #E0B4A6; }
</style>
