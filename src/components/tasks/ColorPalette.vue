<template>
  <div class="color-palette">
    <button v-for="c in colors" :key="c"
            type="button"
            class="cp-swatch"
            :class="{ active: c.toLowerCase() === (modelValue || '').toLowerCase() }"
            :style="{ background: c }"
            :title="c"
            @click="$emit('update:modelValue', c)">
      <span v-if="c.toLowerCase() === (modelValue || '').toLowerCase()" class="cp-check">✓</span>
    </button>
  </div>
</template>

<script setup>
defineProps({
  modelValue: { type: String, default: '' },
});
defineEmits(['update:modelValue']);

// Палитра: 14 цветов в стиле Trello/Linear — без раздражающего ярко-красного, спокойные пастельные.
const colors = [
  '#90A4AE', // серо-голубой (по умолчанию)
  '#9E9E9E', // серый (архив)
  '#42A5F5', // синий
  '#26C6DA', // бирюза
  '#66BB6A', // зелёный
  '#9CCC65', // лайм
  '#FFCA28', // жёлтый
  '#FFA726', // оранжевый
  '#FF7043', // тёрракот
  '#EF5350', // красный
  '#EC407A', // розовый
  '#AB47BC', // фиолетовый
  '#7E57C2', // индиго
  '#8D6E63', // коричневый
];
</script>

<style scoped>
.color-palette {
  display: grid; grid-template-columns: repeat(7, 28px);
  gap: 6px;
}
.cp-swatch {
  width: 28px; height: 28px;
  border-radius: 50%;
  border: 2px solid transparent;
  cursor: pointer;
  position: relative;
  padding: 0;
  transition: transform 120ms ease, border-color 120ms ease, box-shadow 120ms ease;
}
.cp-swatch:hover { transform: scale(1.10); border-color: rgba(9,30,66,0.20); }
.cp-swatch:focus-visible { outline: none; box-shadow: 0 0 0 2px rgba(232,122,30,0.45); }
.cp-swatch.active {
  border-color: #172B4D;
  box-shadow: 0 0 0 2px #fff inset;
}
.cp-check {
  color: #fff;
  font-size: 14px;
  font-weight: 700;
  text-shadow: 0 1px 2px rgba(0,0,0,0.50);
  display: block; line-height: 24px; text-align: center;
}
</style>
