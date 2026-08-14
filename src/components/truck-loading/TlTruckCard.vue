<template>
  <div
    class="tlt"
    :class="{ 'is-over': dragOver, 'is-readonly': readonly }"
    @dragover.prevent="$emit('dragover')"
    @dragleave="$emit('dragleave', $event)"
    @drop.prevent="$emit('drop')"
  >
    <div class="tlt-head">
      <span class="tlt-name">{{ name }}</span>
      <span v-if="truck.direction_name" class="tlt-dir">{{ truck.direction_name }}</span>
      <span class="tlt-mode" :class="'mode-' + truck.mode">{{ modeLabel(truck.mode) }}</span>
      <span class="tlt-count">{{ truck.assignments.length }}</span>
      <button v-if="!readonly" type="button" class="tlt-del" title="Убрать машину" @click.stop="$emit('remove')">&#10005;</button>
    </div>

    <div class="tlt-bars">
      <div class="tlt-bar-row">
        <span class="tlt-bar-cap">{{ stats.pallets }}/{{ truck.capacity_pallets }} п</span>
        <span class="tlt-bar"><span class="tlt-bar-fill" :class="barColor(stats.percentPallets)" :style="{ width: Math.min(stats.percentPallets, 100) + '%' }"></span></span>
      </div>
      <div class="tlt-bar-row">
        <span class="tlt-bar-cap">{{ Math.round(stats.weight) }}/{{ Math.round(+truck.capacity_kg) }} кг</span>
        <span class="tlt-bar"><span class="tlt-bar-fill" :class="barColor(stats.percentWeight)" :style="{ width: Math.min(stats.percentWeight, 100) + '%' }"></span></span>
      </div>
    </div>

    <div class="tlt-items">
      <div
        v-for="a in truck.assignments"
        :key="a.uid"
        class="tlt-item"
        :draggable="!readonly"
        @dragstart="$emit('item-dragstart', { event: $event, assignment: a })"
      >
        <span class="tlt-item-rest">{{ restLabel(a) }}</span>
        <!-- Ресторан не из направления рейса: класть можно, но видно должно быть -->
        <span v-if="a.foreign_direction" class="tlt-item-foreign" title="Ресторан не из направления этого рейса">не по пути</span>
        <span v-if="a.category" class="tlt-item-cat" :class="'cat-' + catClass(a.category)">{{ a.category }}</span>
        <!-- Для позиции показываем артикул с названием: раньше в машине было
             видно только номер ресторана и цифры -->
        <span v-if="a.sku || a.product_name" class="tlt-item-sku">{{ a.sku }} {{ a.product_name }}</span>
        <span class="tlt-item-stats">{{ fmtPallets(a.pallets) }} п · {{ Math.round(+a.weight_kg || 0) }} кг</span>
        <button v-if="!readonly" type="button" class="tlt-item-del" title="Убрать из машины" @click.stop="$emit('unassign', a.uid)">&#10005;</button>
      </div>
      <div v-if="!truck.assignments.length" class="tlt-empty">
        {{ readonly ? 'Пусто' : 'Перетащите заказ или выберите машину в карточке заказа' }}
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { formatRestaurantNumber } from '@/lib/legalEntities.js';

const props = defineProps({
  truck: { type: Object, required: true },
  index: { type: Number, default: 0 },
  stats: { type: Object, required: true },
  dragOver: { type: Boolean, default: false },
  readonly: { type: Boolean, default: false },
});

defineEmits(['remove', 'unassign', 'drop', 'dragover', 'dragleave', 'item-dragstart']);

const name = computed(() => props.truck.custom_name || 'Машина ' + (props.index + 1));

function restLabel(a) {
  return formatRestaurantNumber(a.restaurant_number, a.legal_entity_group);
}

function modeLabel(m) {
  return { any: 'Любой', dry: 'Сухой', cold: 'Холод', frozen: 'Мороз' }[m] || m;
}

function catClass(cat) {
  if (cat === 'Сухой') return 'dry';
  if (cat === 'Холод') return 'cold';
  if (cat === 'Мороз') return 'frozen';
  return '';
}

function barColor(percent) {
  if (percent > 100) return 'bar-red';
  if (percent > 85) return 'bar-orange';
  return 'bar-green';
}

function fmtPallets(v) {
  const n = +v || 0;
  if (n === 0) return '0';
  if (n >= 1) return n.toFixed(1);
  const s = n.toFixed(2);
  return s === '0.00' ? n.toFixed(3) : s;
}
</script>

<style scoped>
.tlt {
  border: 1px solid #e6ddd2;
  border-radius: 8px;
  background: #fff;
  padding: 8px 10px;
  margin-bottom: 8px;
}
.tlt.is-over { border-color: #E76F51; background: #fffaf7; box-shadow: inset 0 0 0 1px #E76F51; }

.tlt-head { display: flex; align-items: center; gap: 8px; }
.tlt-name { font-weight: 700; font-size: 13px; color: #502314; }
.tlt-mode { font-size: 10px; font-weight: 600; padding: 1px 7px; border-radius: 8px; }
.mode-any { background: #f1f3f5; color: #555; }
.mode-dry { background: #fff4e0; color: #8a5a00; }
.mode-cold { background: #e3f2fd; color: #0d47a1; }
.mode-frozen { background: #ede7f6; color: #4527a0; }
.tlt-count { font-size: 11px; color: #8b7355; background: #f5f0eb; border-radius: 8px; padding: 1px 7px; }
.tlt-dir { font-size: 11px; font-weight: 600; color: #0d47a1; background: #e3f2fd; border-radius: 8px; padding: 1px 8px; white-space: nowrap; }
.tlt-item-foreign { font-size: 10px; font-weight: 600; color: #b35900; background: #fff3e0; border-radius: 8px; padding: 1px 6px; white-space: nowrap; }
.tlt-del { margin-left: auto; border: none; background: none; color: #c0b3a6; cursor: pointer; font-size: 13px; padding: 2px 4px; border-radius: 4px; }
.tlt-del:hover { color: #c62828; background: #fde2e2; }

/* Полосы загрузки: подписи слева фиксированной ширины, дальше — сама полоса */
.tlt-bars { display: flex; gap: 10px; margin: 6px 0 4px; }
.tlt-bar-row { display: flex; align-items: center; gap: 6px; flex: 1 1 0; min-width: 0; }
.tlt-bar-cap { font-size: 11px; color: #6b6b6b; white-space: nowrap; font-variant-numeric: tabular-nums; }
.tlt-bar { flex: 1 1 auto; height: 5px; background: #efe9e2; border-radius: 3px; overflow: hidden; min-width: 30px; }
.tlt-bar-fill { display: block; height: 100%; border-radius: 3px; transition: width 0.15s; }
.bar-green { background: #4caf50; }
.bar-orange { background: #ef8b32; }
.bar-red { background: #d64545; }

.tlt-items { display: flex; flex-direction: column; gap: 3px; }
.tlt-item {
  display: flex; align-items: center; gap: 6px;
  padding: 4px 6px; border-radius: 6px; background: #faf7f3;
  font-size: 12px; cursor: grab;
}
.tlt-item:hover { background: #f3ece4; }
.tlt-item-rest { font-weight: 700; color: #502314; min-width: 34px; }
.tlt-item-cat { font-size: 10px; font-weight: 600; padding: 1px 6px; border-radius: 8px; white-space: nowrap; }
.cat-dry { background: #fff4e0; color: #8a5a00; }
.cat-cold { background: #e3f2fd; color: #0d47a1; }
.cat-frozen { background: #ede7f6; color: #4527a0; }
.tlt-item-sku { color: #4a4a4a; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tlt-item-stats { margin-left: auto; color: #6b6b6b; white-space: nowrap; font-variant-numeric: tabular-nums; }
.tlt-item-del { border: none; background: none; color: #c0b3a6; cursor: pointer; padding: 0 2px; border-radius: 4px; }
.tlt-item-del:hover { color: #c62828; background: #fde2e2; }
.tlt-empty { padding: 8px; font-size: 11px; color: #b0a396; text-align: center; border: 1px dashed #e6ddd2; border-radius: 6px; }

@media (max-width: 900px) {
  .tlt-bars { flex-direction: column; gap: 4px; }
  .tlt-item { flex-wrap: wrap; }
  .tlt-item-sku { flex: 1 1 100%; }
}
</style>
