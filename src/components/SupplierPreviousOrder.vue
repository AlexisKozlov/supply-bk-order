<template>
  <div v-if="previousOrder" class="spo-block" :class="variantClass">
    <div class="spo-head" @click="$emit('update:expanded', !expanded)">
      <span>📋 Ваша предыдущая заявка от {{ formatDate(previousOrder.delivery_date) }} — {{ countLabel }}</span>
      <span class="spo-toggle">{{ expanded ? '▲ скрыть' : '▼ показать' }}</span>
    </div>
    <div v-if="expanded" class="spo-body">
      <div v-for="it in previousOrder.items" :key="it.sku" class="spo-row">
        <span class="spo-name">{{ it.product_name }}</span>
        <span class="spo-qty">{{ fmtNum(it.quantity) }}</span>
      </div>
    </div>
    <div v-if="canRepeat && previousOrder.items?.length" class="spo-actions">
      <button type="button" class="spo-repeat-btn" @click="$emit('repeat')">
        ↺ Повторить предыдущую заявку
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  previousOrder: { type: Object, default: null },
  expanded: { type: Boolean, default: false },
  canRepeat: { type: Boolean, default: false },
  formatDate: { type: Function, required: true },
  fmtNum: { type: Function, required: true },
  variant: { type: String, default: 'standalone' },
});

defineEmits(['update:expanded', 'repeat']);

const variantClass = computed(() => 'spo-' + props.variant);

// «3 товара» с правильным склонением; пустая заявка = «Поставка не нужна»
// (её подают отметкой «Поставка не нужна», товаров в ней нет — «0 товаров»
// сбивало с толку).
const countLabel = computed(() => {
  const n = props.previousOrder?.items?.length || 0;
  if (!n) return 'поставка не нужна';
  const forms = ['товар', 'товара', 'товаров'];
  const m10 = n % 10, m100 = n % 100;
  const word = (m10 === 1 && m100 !== 11) ? forms[0]
    : (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) ? forms[1] : forms[2];
  return `${n} ${word}`;
});
</script>

<style scoped>
.spo-block { background: #f1f5f9; }
.spo-standalone { border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 12px; margin-bottom: 10px; }
.spo-inline     { border-bottom: 1px solid #cbd5e1; padding: 8px 14px; }
.spo-head { display: flex; justify-content: space-between; align-items: center; cursor: pointer; font-weight: 500; color: #334155; font-size: 14px; gap: 6px; flex-wrap: wrap; }
.spo-inline .spo-head { font-size: 13px; }
.spo-toggle { font-size: 12px; color: #64748b; flex-shrink: 0; }
.spo-inline .spo-toggle { font-size: 11px; }
.spo-body { margin-top: 8px; border-top: 1px dashed #cbd5e1; padding-top: 8px; max-height: 240px; overflow-y: auto; }
.spo-inline .spo-body { margin-top: 6px; padding-top: 6px; }
.spo-row { display: flex; justify-content: space-between; padding: 3px 0; font-size: 13px; }
.spo-inline .spo-row { padding: 2px 0; font-size: 12px; }
.spo-name { color: #334155; }
.spo-qty { color: #64748b; font-variant-numeric: tabular-nums; }
.spo-actions { margin-top: 10px; border-top: 1px dashed #cbd5e1; padding-top: 10px; display: flex; justify-content: center; }
.spo-inline .spo-actions { margin-top: 8px; padding-top: 8px; }
.spo-repeat-btn { background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 14px; font-size: 13px; font-weight: 500; color: #0f766e; cursor: pointer; transition: background 0.15s; }
.spo-inline .spo-repeat-btn { padding: 6px 12px; font-size: 12px; }
.spo-repeat-btn:hover { background: #ecfdf5; }
.spo-repeat-btn:active { background: #d1fae5; }
</style>
