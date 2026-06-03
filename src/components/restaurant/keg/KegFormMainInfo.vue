<template>
  <section class="krt-section">
    <div class="krt-section-head">
      <h3>Основная информация</h3>
    </div>

    <div class="krt-fld-row3">
      <div class="krt-fld krt-fld-date">
        <label class="krt-fld-label">
          Дата возврата
          <span class="krt-tip">
            <span class="krt-tip-icon" tabindex="0">?</span>
            <span class="krt-tip-bubble">Доступны только дни, разрешённые отделом закупок.</span>
          </span>
        </label>
        <select
          v-if="availableDates.length"
          v-model="form.return_date"
          :disabled="formReadonly || deadlinePassed"
          class="krt-input"
          :class="{ 'krt-input-err': fieldErr.return_date }"
        >
          <option value="">— выберите дату —</option>
          <option v-for="d in availableDates" :key="d.iso" :value="d.iso">{{ d.label }}</option>
        </select>
        <template v-else-if="!editingId">
          <div v-if="restaurantInfoLoaded" class="krt-fld-empty">
            Дни возврата не настроены — обратитесь в отдел закупок.
          </div>
          <div v-else class="krt-fld-empty">Загрузка...</div>
        </template>
        <input
          v-else
          v-model="form.return_date"
          type="date"
          :disabled="formReadonly || deadlinePassed"
          class="krt-input"
          :class="{ 'krt-input-err': fieldErr.return_date }"
        />
      </div>

      <div class="krt-fld krt-fld-bso-series">
        <label class="krt-fld-label">
          Серия ТТН
          <span class="krt-tip">
            <span class="krt-tip-icon" tabindex="0">?</span>
            <span class="krt-tip-bubble">Две заглавные кириллические буквы (например, АА).</span>
          </span>
        </label>
        <input
          :value="form.bso_series || ''"
          @input="onSeriesInput"
          type="text"
          maxlength="2"
          placeholder="АА"
          :disabled="formReadonly"
          class="krt-input krt-input-mono"
          :class="{ 'krt-input-err': fieldErr.bso }"
          autocomplete="off"
          inputmode="text"
        />
      </div>

      <div class="krt-fld krt-fld-bso-number">
        <label class="krt-fld-label">
          Номер ТТН
          <span class="krt-tip">
            <span class="krt-tip-icon" tabindex="0">?</span>
            <span class="krt-tip-bubble">Ровно 7 цифр.</span>
          </span>
        </label>
        <input
          :value="form.bso_number || ''"
          @input="onNumberInput"
          type="text"
          inputmode="numeric"
          maxlength="7"
          placeholder="0000000"
          :disabled="formReadonly"
          class="krt-input krt-input-mono"
          :class="{ 'krt-input-err': fieldErr.bso }"
          autocomplete="off"
        />
      </div>
    </div>

    <div class="krt-fld">
      <label class="krt-fld-label">
        Сдал грузоотправитель
        <span class="krt-tip">
          <span class="krt-tip-icon" tabindex="0">?</span>
          <span class="krt-tip-bubble">Должность, фамилия и инициалы того, кто фактически передаёт кеги. Например: «Управляющий рестораном Иванов И.И.»</span>
        </span>
      </label>
      <input
        v-model="form.sender_position_name"
        type="text"
        :disabled="formReadonly"
        class="krt-input"
        placeholder="Управляющий рестораном Иванов И.И."
      />
    </div>

    <template v-if="form.status && form.status !== 'DRAFT'">
      <div class="krt-fld-row2">
        <div class="krt-fld">
          <label class="krt-fld-label">Машина</label>
          <div class="krt-readonly">{{ form.vehicle || '—' }}</div>
        </div>
        <div class="krt-fld">
          <label class="krt-fld-label">Водитель</label>
          <div class="krt-readonly">{{ form.driver || '—' }}</div>
        </div>
      </div>
    </template>
  </section>
</template>

<script setup>
import { maskBsoSeries, maskBsoNumber } from './kegHelpers.js';

const props = defineProps({
  form: { type: Object, required: true },
  formReadonly: { type: Boolean, default: false },
  deadlinePassed: { type: Boolean, default: false },
  restaurantInfoLoaded: { type: Boolean, default: false },
  editingId: { type: [Number, null], default: null },
  availableDates: { type: Array, default: () => [] },
  fieldErr: { type: Object, required: true },
});

function onSeriesInput(e) {
  const filtered = maskBsoSeries(e.target.value);
  props.form.bso_series = filtered;
  if (e.target.value !== filtered) e.target.value = filtered;
}
function onNumberInput(e) {
  const filtered = maskBsoNumber(e.target.value);
  props.form.bso_number = filtered;
  if (e.target.value !== filtered) e.target.value = filtered;
}
</script>
