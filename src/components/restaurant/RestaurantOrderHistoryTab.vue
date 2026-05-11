<template>
  <div class="history-list">
    <div v-if="loading" class="mini-loader"><div class="cab-spin"></div></div>
    <div v-else-if="error" class="cab-empty-card"><p>{{ error }}</p></div>
    <div v-else-if="!orders.length" class="cab-empty-card"><h2>Нет заказов</h2></div>
    <template v-else>
      <div class="hist-filters">
        <button class="hist-filter-chip" :class="{ active: filter === 'all' }" @click="filter = 'all'">Все</button>
        <button v-for="src in sourceOptions" :key="src.label"
          class="hist-filter-chip" :class="[{ active: filter === src.label }, 'src-chip-' + src.source]"
          @click="filter = src.label">
          {{ src.label }}
        </button>
      </div>
      <div v-if="!filteredOrders.length" class="cab-empty-card"><p>Нет заказов по этому фильтру</p></div>
      <div v-else class="hist-cards">
        <div v-for="order in filteredOrders" :key="order.source + ':' + order.id"
             class="hist-card" :class="'hist-src-' + order.source"
             @click="$emit('open', order)">
          <div class="hist-card-left"></div>
          <div class="hist-card-body">
            <div class="hist-card-top">
              <span class="hist-card-date">{{ fmtDate(order.delivery_date) }}</span>
              <span class="hist-badge" :class="'src-' + order.source">{{ order.source_name }}</span>
              <span class="hist-badge status-badge" :class="'st-' + order.status">{{ statusLabel(order.status) }}</span>
            </div>
            <div class="hist-card-meta">
              <span v-if="Number(order.item_count) > 0" class="hist-meta-pill">
                {{ order.item_count }} поз. · {{ order.total_qty }} {{ order.source === 'delivery' ? 'кор.' : 'шт.' }}
              </span>
              <span v-else class="hist-meta-skip">Поставка не нужна</span>
              <span v-if="order.submitted_at" class="hist-card-time">{{ fmtDateTime(order.submitted_at) }}</span>
            </div>
          </div>
          <div class="hist-card-arrow">›</div>
        </div>
      </div>
      <div v-if="hasMore && filter === 'all'" class="hist-more">
        <button class="btn btn-outline" :disabled="loadingMore" @click="$emit('load-more')">
          <span v-if="loadingMore" class="cab-spin cab-spin-sm"></span>
          {{ loadingMore ? 'Загружаем…' : 'Загрузить ещё' }}
        </button>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { formatDate as fmtDate, formatDateTime as fmtDateTime, statusLabel } from '@/lib/roUtils.js';

const props = defineProps({
  orders: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' },
  hasMore: { type: Boolean, default: false },
  loadingMore: { type: Boolean, default: false },
});

defineEmits(['load-more', 'open']);

const filter = ref('all');

const sourceOptions = computed(() => {
  const groups = new Map();
  for (const o of props.orders) {
    const key = o.source === 'supplier' ? 'sup_' + o.supplier_id : o.source;
    if (!groups.has(o.source_name)) {
      groups.set(o.source_name, { keys: new Set(), source: o.source });
    }
    groups.get(o.source_name).keys.add(key);
  }
  return [...groups.entries()].map(([label, g]) => ({
    label, keys: [...g.keys], source: g.source,
  }));
});

const filteredOrders = computed(() => {
  if (filter.value === 'all') return props.orders;
  const opt = sourceOptions.value.find(o => o.label === filter.value);
  if (!opt) return props.orders;
  return props.orders.filter(o => {
    const key = o.source === 'supplier' ? 'sup_' + o.supplier_id : o.source;
    return opt.keys.includes(key);
  });
});
</script>

<style scoped>
.history-list { padding: 0; }
.mini-loader { padding: 24px; text-align: center; }
.cab-spin { width: 28px; height: 28px; border: 3px solid #ede8e3; border-top-color: #E76F51; border-radius: 50%; animation: spin 0.7s linear infinite; display: inline-block; }
.cab-spin-sm { width: 16px; height: 16px; border-width: 2px; }
@keyframes spin { to { transform: rotate(360deg); } }
.cab-empty-card { background: #fff; border-radius: 14px; border: 1px solid #EDE8E3; padding: 24px; text-align: center; }
.cab-empty-card h2 { color: #502314; margin: 0 0 8px; font-size: 18px; }
.cab-empty-card p { color: #8b7355; font-size: 14px; margin: 0; }
.btn { padding: 10px 18px; border-radius: 10px; border: none; font-family: inherit; font-size: 14px; font-weight: 600; cursor: pointer; }
.btn-outline { background: #fff; border: 1.5px solid #d5c8bc; color: #502314; }
.btn-outline:hover:not(:disabled) { background: #FAF8F5; border-color: #502314; }
.btn:disabled { opacity: 0.6; cursor: default; }

.hist-filters { display: flex; gap: 6px; flex-wrap: wrap; padding: 4px 0 12px; }
.hist-filter-chip { padding: 6px 14px; border-radius: 20px; border: 1.5px solid #e0d5c8; background: white; font-size: 12px; font-weight: 600; color: #6b4f3a; cursor: pointer; font-family: inherit; transition: all 0.15s; }
.hist-filter-chip:hover { border-color: #502314; color: #502314; }
.hist-filter-chip.active { background: #502314; color: white; border-color: #502314; }
.hist-filter-chip.src-chip-delivery.active { background: #E76F51; border-color: #E76F51; }
.hist-filter-chip.src-chip-supplier.active { background: #2563eb; border-color: #2563eb; }
.hist-filter-chip.src-chip-planeta.active { background: #16a34a; border-color: #16a34a; }

.hist-cards { display: flex; flex-direction: column; gap: 8px; }
.hist-more { display: flex; justify-content: center; margin-top: 16px; }
.hist-more .btn { display: inline-flex; align-items: center; gap: 8px; min-width: 200px; justify-content: center; }
.hist-card { display: flex; align-items: stretch; background: white; border-radius: 14px; border: 1px solid #EDE8E3; overflow: hidden; cursor: pointer; transition: box-shadow 0.15s, border-color 0.15s; }
.hist-card:hover { box-shadow: 0 2px 12px rgba(80,35,20,0.10); border-color: #d5c8bc; }
.hist-card-left { width: 5px; flex-shrink: 0; background: #e0d5c8; }
.hist-src-delivery .hist-card-left { background: #E76F51; }
.hist-src-supplier .hist-card-left { background: #2563eb; }
.hist-src-planeta .hist-card-left { background: #16a34a; }
.hist-card-body { flex: 1; padding: 12px 14px; min-width: 0; }
.hist-card-arrow { display: flex; align-items: center; padding: 0 12px 0 4px; font-size: 20px; color: #c5b8aa; }
.hist-card-top { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; margin-bottom: 6px; }
.hist-card-date { font-weight: 700; color: #502314; font-size: 14px; }
.hist-badge { font-size: 10px; padding: 2px 8px; border-radius: 10px; font-weight: 700; white-space: nowrap; }
.src-delivery { background: #FFF0EE; color: #E76F51; }
.src-supplier { background: #EFF6FF; color: #2563eb; }
.src-planeta { background: #ECFDF5; color: #16a34a; }
.status-badge.st-submitted { background: #ECFDF5; color: #16a34a; }
.status-badge.st-locked   { background: #FEF2F2; color: #dc2626; }
.status-badge.st-draft    { background: #F5F0EB; color: #8b7355; }
.hist-card-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.hist-meta-pill { font-size: 12px; color: #6b4f3a; background: #F7F2EC; border-radius: 8px; padding: 2px 8px; font-weight: 600; }
.hist-meta-skip { font-size: 12px; color: #d97706; font-style: italic; }
.hist-card-time { font-size: 11px; color: #b0a090; margin-left: auto; }

@media (max-width: 640px) {
  .hist-card-time { margin-left: 0; }
}
</style>
