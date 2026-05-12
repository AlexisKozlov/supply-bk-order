<template>
  <div v-if="show" class="krt-confirm-overlay" @click.self="onClose">
    <div class="krt-confirm krt-bso-modal">
      <h3>Заменить БСО</h3>
      <p class="krt-bso-modal-current">
        Текущий: <b>{{ currentSeries }} {{ currentNumber }}</b>
      </p>

      <div class="krt-bso-modal-row">
        <div class="krt-fld krt-fld-bso-series">
          <label class="krt-fld-label">Новая серия</label>
          <input
            :value="newSeries"
            @input="onSeriesInput"
            type="text"
            maxlength="2"
            placeholder="АА"
            class="krt-input krt-input-mono"
            autocomplete="off"
          />
        </div>
        <div class="krt-fld krt-fld-bso-number">
          <label class="krt-fld-label">Новый номер</label>
          <input
            :value="newNumber"
            @input="onNumberInput"
            type="text"
            inputmode="numeric"
            maxlength="7"
            placeholder="0000000"
            class="krt-input krt-input-mono"
            autocomplete="off"
          />
        </div>
      </div>

      <div class="krt-fld">
        <label class="krt-fld-label">Причина замены</label>
        <select v-model="reasonKey" class="krt-input">
          <option v-for="r in BSO_REASONS" :key="r.key" :value="r.key">{{ r.label }}</option>
        </select>
      </div>

      <div v-if="reasonKey === 'OTHER'" class="krt-fld">
        <label class="krt-fld-label">Уточните причину</label>
        <input
          v-model="reasonOther"
          type="text"
          maxlength="200"
          placeholder="Например: попал в воду"
          class="krt-input"
        />
      </div>

      <p class="krt-bso-modal-warn">
        Старый номер сохранится в истории заявки. Окно замены до <b>{{ cutoffFormatted }}</b>.
      </p>

      <div class="krt-confirm-actions">
        <button class="krt-btn ghost" @click="onClose" :disabled="saving">Отмена</button>
        <button class="krt-btn primary" @click="onSubmit" :disabled="saving">
          {{ saving ? 'Сохраняем...' : 'Заменить' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import { useToastStore } from '@/stores/toastStore.js';

const props = defineProps({
  show: { type: Boolean, default: false },
  currentSeries: { type: String, default: '' },
  currentNumber: { type: String, default: '' },
  cutoffFormatted: { type: String, default: '' },
  saving: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'submit']);

const toast = useToastStore();

const BSO_REASONS = [
  { key: 'PRINT_DAMAGED', label: 'Испорчен при печати' },
  { key: 'WRONG_FORM',    label: 'Не тот бланк / не та сторона' },
  { key: 'LOST',          label: 'Утерян' },
  { key: 'OTHER',         label: 'Другое (указать)' },
];

const newSeries = ref('');
const newNumber = ref('');
const reasonKey = ref('PRINT_DAMAGED');
const reasonOther = ref('');

// При каждом открытии — сбрасываем поля и подставляем текущую серию по умолчанию.
watch(() => props.show, (v) => {
  if (v) {
    newSeries.value = props.currentSeries || '';
    newNumber.value = '';
    reasonKey.value = 'PRINT_DAMAGED';
    reasonOther.value = '';
  }
});

function onSeriesInput(e) {
  const raw = String(e.target.value || '');
  const filtered = raw.replace(/[^А-Яа-яЁё]/g, '').toUpperCase().slice(0, 2);
  newSeries.value = filtered;
  if (e.target.value !== filtered) e.target.value = filtered;
}
function onNumberInput(e) {
  const raw = String(e.target.value || '');
  const filtered = raw.replace(/\D/g, '').slice(0, 7);
  newNumber.value = filtered;
  if (e.target.value !== filtered) e.target.value = filtered;
}

function onClose() {
  if (props.saving) return;
  emit('close');
}

function onSubmit() {
  const s = newSeries.value.trim();
  const n = newNumber.value.trim();
  if (!/^[А-ЯЁ]{2}$/u.test(s)) { toast.error('Серия БСО', 'Две заглавные кириллические буквы.'); return; }
  if (!/^\d{7}$/.test(n))      { toast.error('Номер БСО', 'Ровно 7 цифр.'); return; }
  if (s === props.currentSeries && n === props.currentNumber) {
    toast.error('Тот же БСО', 'Новый номер совпадает с текущим.');
    return;
  }
  const reasonDef = BSO_REASONS.find(r => r.key === reasonKey.value);
  let reason = reasonDef?.label || '';
  if (reasonKey.value === 'OTHER') {
    const custom = reasonOther.value.trim();
    if (!custom) { toast.error('Причина', 'Уточните причину замены.'); return; }
    reason = 'Другое: ' + custom;
  }
  emit('submit', { new_series: s, new_number: n, reason });
}
</script>
