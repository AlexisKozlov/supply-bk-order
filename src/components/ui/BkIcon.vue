<template>
  <span class="bk-icon-wrap" v-html="rendered"></span>
</template>

<script setup>
import { computed } from 'vue';
import { icons, iconsLight } from '@/lib/icons.js';

const props = defineProps({
  name: { type: String, required: true },
  size: { type: String, default: 'sm' },
  light: { type: Boolean, default: false },
});

const sizes = { xs: 12, sm: 16, md: 20, lg: 24 };

const rendered = computed(() => {
  const source = props.light ? (iconsLight[props.name] || icons[props.name]) : icons[props.name];
  if (!source) return '';
  const px = sizes[props.size] || 16;
  return source.replace('<svg ', `<svg width="${px}" height="${px}" `);
});
</script>

<style>
.bk-icon-wrap { display: inline-flex; align-items: center; justify-content: center; vertical-align: middle; flex-shrink: 0; line-height: 0; }
.bk-icon-wrap svg { display: block; }
@keyframes bk-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
.bk-i-spin { animation: bk-spin 1s linear infinite; }
</style>
