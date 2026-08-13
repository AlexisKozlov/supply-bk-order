<template>
  <span class="bk-icon-wrap" v-html="rendered"></span>
</template>

<script setup>
import { computed } from 'vue';
import { icons, iconsLight } from '@/lib/icons.js';

const props = defineProps({
  name: { type: String, required: true },
  // Либо ключ размера (xs/sm/md/lg), либо число пикселей.
  size: { type: [String, Number], default: 'sm' },
  light: { type: Boolean, default: false },
});

const sizes = { xs: 12, sm: 16, md: 20, lg: 24 };

// Про неизвестный значок сообщаем только при разработке: раньше такая кнопка
// просто оставалась без картинки, и заметить это было невозможно.
const warned = new Set();
function warnUnknown(name) {
  if (warned.has(name)) return;
  warned.add(name);
  console.warn(`[BkIcon] Нет значка «${name}» — кнопка останется без картинки.` +
    '\nВыберите существующий значок из src/lib/icons.js или добавьте новый.');
}

const rendered = computed(() => {
  const source = props.light ? (iconsLight[props.name] || icons[props.name]) : icons[props.name];
  if (!source) {
    if (import.meta.env.DEV) warnUnknown(props.name);
    return '';
  }
  const num = Number(props.size);
  const px = Number.isFinite(num) && num > 0 ? num : (sizes[props.size] || 16);
  return source.replace('<svg ', `<svg width="${px}" height="${px}" `);
});
</script>

<style>
.bk-icon-wrap { display: inline-flex; align-items: center; justify-content: center; vertical-align: middle; flex-shrink: 0; line-height: 0; }
.bk-icon-wrap svg { display: block; }
@keyframes bk-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
.bk-i-spin { animation: bk-spin 1s linear infinite; }
</style>
