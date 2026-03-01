<template>
  <Teleport to="body">
    <div class="modal" @click.self="$emit('close')">
      <div class="modal-box compare-modal">
        <div class="modal-header">
          <h2>Сравнение заказов</h2>
          <button class="modal-close" @click="$emit('close')"><BkIcon name="close" size="sm"/></button>
        </div>

        <div class="compare-header">
          <div class="compare-col">
            <div class="compare-label">Заказ 1</div>
            <div class="compare-info">{{ orderA.supplier }} — {{ formatDate(orderA.delivery_date) }}</div>
            <div class="compare-meta">{{ orderA.created_by || '—' }}</div>
          </div>
          <div class="compare-col">
            <div class="compare-label">Заказ 2</div>
            <div class="compare-info">{{ orderB.supplier }} — {{ formatDate(orderB.delivery_date) }}</div>
            <div class="compare-meta">{{ orderB.created_by || '—' }}</div>
          </div>
        </div>

        <div class="compare-summary">
          <span class="cs-chip cs-added" v-if="stats.added">+{{ stats.added }} добавлено</span>
          <span class="cs-chip cs-removed" v-if="stats.removed">−{{ stats.removed }} убрано</span>
          <span class="cs-chip cs-changed" v-if="stats.changed">{{ stats.changed }} изменено</span>
          <span class="cs-chip" v-if="stats.same">{{ stats.same }} без изменений</span>
        </div>

        <div class="compare-table-wrap">
          <table class="compare-table">
            <thead>
              <tr>
                <th>Артикул</th>
                <th>Наименование</th>
                <th class="ct-right">Заказ 1</th>
                <th class="ct-right">Заказ 2</th>
                <th class="ct-right">Разница</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in diffRows" :key="row.sku || row.name" :class="'ct-' + row.type">
                <td class="ct-sku">{{ row.sku || '—' }}</td>
                <td class="ct-name">{{ row.name }}</td>
                <td class="ct-right">{{ row.qtyA ?? '—' }}</td>
                <td class="ct-right">{{ row.qtyB ?? '—' }}</td>
                <td class="ct-right ct-diff">
                  <template v-if="row.type === 'added'">+{{ row.qtyB }}</template>
                  <template v-else-if="row.type === 'removed'">−{{ row.qtyA }}</template>
                  <template v-else-if="row.type === 'changed'">{{ row.diff > 0 ? '+' : '' }}{{ row.diff }}</template>
                  <template v-else>—</template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, onMounted, onUnmounted } from 'vue';
import BkIcon from '@/components/ui/BkIcon.vue';

const props = defineProps({
  orderA: { type: Object, required: true },
  orderB: { type: Object, required: true },
});

const emit = defineEmits(['close']);

function onKey(e) {
  if (e.key === 'Escape') { e.preventDefault(); emit('close'); }
}
onMounted(() => document.addEventListener('keydown', onKey));
onUnmounted(() => document.removeEventListener('keydown', onKey));

function formatDate(str) {
  if (!str) return '—';
  return new Date(str).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function getItems(order) {
  return (order.order_items || []).filter(i => i.qty_boxes && Math.round(i.qty_boxes) > 0);
}

const diffRows = computed(() => {
  const itemsA = getItems(props.orderA);
  const itemsB = getItems(props.orderB);

  // Карта по sku+name
  const mapA = new Map();
  const mapB = new Map();
  for (const i of itemsA) mapA.set(i.sku || i.name, i);
  for (const i of itemsB) mapB.set(i.sku || i.name, i);

  const allKeys = new Set([...mapA.keys(), ...mapB.keys()]);
  const rows = [];

  for (const key of allKeys) {
    const a = mapA.get(key);
    const b = mapB.get(key);
    const qtyA = a ? Math.round(a.qty_boxes) : null;
    const qtyB = b ? Math.round(b.qty_boxes) : null;
    const name = (a || b).name;
    const sku = (a || b).sku;

    let type = 'same';
    let diff = 0;
    if (!a) { type = 'added'; }
    else if (!b) { type = 'removed'; }
    else if (qtyA !== qtyB) { type = 'changed'; diff = qtyB - qtyA; }

    rows.push({ sku, name, qtyA, qtyB, type, diff });
  }

  // Сортировка: изменённые/добавленные/убранные сверху, затем без изменений
  const order = { removed: 0, added: 1, changed: 2, same: 3 };
  rows.sort((a, b) => order[a.type] - order[b.type] || (a.name || '').localeCompare(b.name || '', 'ru'));
  return rows;
});

const stats = computed(() => {
  const s = { added: 0, removed: 0, changed: 0, same: 0 };
  for (const r of diffRows.value) s[r.type]++;
  return s;
});
</script>

<style scoped>
.compare-modal { max-width: 700px; width: 95vw; }

.compare-header {
  display: flex; gap: 16px; padding: 12px 20px;
  border-bottom: 1px solid var(--border-light);
}
.compare-col { flex: 1; }
.compare-label { font-size: 10px; text-transform: uppercase; font-weight: 700; color: var(--text-muted); letter-spacing: 0.5px; }
.compare-info { font-size: 14px; font-weight: 600; color: var(--text); margin-top: 2px; }
.compare-meta { font-size: 11px; color: var(--text-muted); }

.compare-summary {
  display: flex; gap: 8px; padding: 10px 20px; flex-wrap: wrap;
}
.cs-chip {
  display: inline-block; padding: 2px 10px; border-radius: 10px;
  font-size: 11px; font-weight: 700;
  background: var(--bg); color: var(--text-muted);
}
.cs-added { background: #E8F5E9; color: #2E7D32; }
.cs-removed { background: #FFEBEE; color: #C62828; }
.cs-changed { background: #FFF8E1; color: #E65100; }

.compare-table-wrap { max-height: 400px; overflow-y: auto; padding: 0 20px 16px; }
.compare-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.compare-table th {
  padding: 6px 8px; text-align: left; font-size: 10px; font-weight: 700;
  text-transform: uppercase; color: var(--text-muted); border-bottom: 2px solid var(--border);
  position: sticky; top: 0; background: white; z-index: 1;
}
.compare-table td { padding: 4px 8px; border-bottom: 1px solid var(--border-light); }
.ct-right { text-align: right; }
.ct-sku { font-weight: 600; color: var(--text-muted); font-size: 11px; }
.ct-name { color: var(--text); }
.ct-diff { font-weight: 700; }

.ct-added { background: #F1F8E9; }
.ct-added .ct-diff { color: #2E7D32; }
.ct-removed { background: #FFF8F8; }
.ct-removed .ct-diff { color: #C62828; }
.ct-changed { background: #FFFDE7; }
.ct-changed .ct-diff { color: #E65100; }
</style>
