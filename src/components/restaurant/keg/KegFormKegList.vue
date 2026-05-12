<template>
  <section class="krt-section">
    <div class="krt-section-head">
      <h3>Кеги к возврату</h3>
      <span v-if="totalKegsCount > 0" class="krt-section-counter">
        {{ totalKegsCount }} {{ pluralKegs(totalKegsCount) }}
      </span>
    </div>

    <div v-if="catalogLoading" class="krt-empty">Загрузка каталога...</div>
    <div v-else class="krt-keg-list">
      <div v-for="keg in catalog" :key="keg.code" class="krt-keg-row" :class="{ active: kegQty(keg.code) > 0 }">
        <button
          type="button"
          class="krt-keg-thumb"
          :class="{ 'has-photo': keg.photo_url }"
          @click.stop="keg.photo_url && $emit('open-photo', keg)"
          :disabled="!keg.photo_url"
          :title="keg.photo_url ? 'Открыть фото' : 'Фото не загружено'"
        >
          <img v-if="keg.photo_url" :src="keg.photo_url" :alt="keg.name" />
          <span v-else class="krt-keg-thumb-placeholder" v-html="iconKeg"></span>
        </button>
        <div class="krt-keg-info">
          <div class="krt-keg-name">
            <span class="krt-keg-code">{{ keg.code }}</span>
            {{ keg.name }}
          </div>
        </div>
        <div class="krt-stepper" :class="{ disabled: formReadonly }">
          <button
            type="button"
            class="krt-step-btn"
            :disabled="formReadonly || kegQty(keg.code) === 0"
            @click.stop="decKegQty(keg.code)"
            aria-label="Уменьшить"
          >−</button>
          <input
            type="number"
            min="0"
            inputmode="numeric"
            :value="kegQty(keg.code)"
            @input="setKegQty(keg.code, $event.target.value)"
            :disabled="formReadonly"
            class="krt-step-input"
          />
          <button
            type="button"
            class="krt-step-btn"
            :disabled="formReadonly"
            @click.stop="incKegQty(keg.code)"
            aria-label="Увеличить"
          >+</button>
        </div>
      </div>
    </div>

    <div v-if="!catalogLoading" class="krt-keg-summary" :class="{ empty: totalKegsCount === 0 }">
      <span v-if="totalKegsCount === 0">Кеги не указаны</span>
      <span v-else>
        Всего: <strong>{{ totalKegsCount }} {{ pluralKegs(totalKegsCount) }}</strong>
        в {{ totalKegsTypes }} {{ pluralTypes(totalKegsTypes) }}
      </span>
    </div>
  </section>
</template>

<script setup>
import { iconKeg, pluralKegs, pluralTypes } from './kegHelpers.js';

const props = defineProps({
  catalog: { type: Array, default: () => [] },
  catalogLoading: { type: Boolean, default: false },
  kegQties: { type: Object, required: true },
  formReadonly: { type: Boolean, default: false },
  totalKegsCount: { type: Number, default: 0 },
  totalKegsTypes: { type: Number, default: 0 },
});
defineEmits(['open-photo']);

function kegQty(code) { return props.kegQties[code] || 0; }
function setKegQty(code, val) {
  const n = parseInt(val, 10);
  if (n > 0) props.kegQties[code] = n;
  else delete props.kegQties[code];
}
function incKegQty(code) {
  setKegQty(code, (props.kegQties[code] || 0) + 1);
}
function decKegQty(code) {
  const cur = props.kegQties[code] || 0;
  if (cur <= 0) return;
  setKegQty(code, cur - 1);
}
</script>
