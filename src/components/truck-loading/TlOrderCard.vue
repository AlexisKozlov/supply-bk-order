<template>
  <div
    class="tlc"
    :class="['tlc-' + (item.legal_entity_group || 'bk_vm').toLowerCase(), { 'is-open': menuOpen }]"
    :draggable="canAssign"
    @dragstart="onDragStart"
    @click="toggleMenu"
  >
    <div class="tlc-top">
      <span class="tlc-num">{{ restaurantLabel }}</span>
      <span v-if="mode === 'restaurant'" class="tlc-city">{{ item.city }}</span>
      <span v-else-if="mode === 'category'" class="tlc-badge" :class="'cat-' + catClass(item.category)">{{ item.category }}</span>
      <span v-else class="tlc-sku">{{ item.sku }} {{ item.product_name }}</span>
      <!-- Часть заказа уже в машине — показываем остаток, а не весь заказ -->
      <span v-if="item.direction_name" class="tlc-dir" :title="'Направление: ' + item.direction_name">{{ item.direction_name }}</span>
      <span v-if="item.partial" class="tlc-partial" title="Часть заказа уже в машине">остаток</span>
      <span class="tlc-total">{{ fmtPallets(item.pallets) }} п · {{ Math.round(+item.weight_kg || 0) }} кг</span>
    </div>

    <div v-if="mode === 'restaurant'" class="tlc-cats">
      <span v-for="(data, cat) in item.categories" :key="cat" class="tlc-badge" :class="'cat-' + catClass(cat)">
        {{ cat }} {{ fmtPallets(data.pallets) }}п
      </span>
    </div>
    <div v-else-if="mode === 'item'" class="tlc-qty">{{ item.quantity }} шт.</div>

    <!-- Раньше положить заказ в машину можно было только перетаскиванием -->
    <div v-if="menuOpen" class="tlc-menu" @click.stop>
      <div v-if="!targets.length" class="tlc-menu-empty">Сначала добавьте машину</div>
      <button
        v-for="t in targets"
        :key="t.uid"
        type="button"
        class="tlc-menu-item"
        :class="{ 'is-disabled': !t.ok }"
        :disabled="!t.ok"
        :title="t.ok ? '' : t.reason"
        @click="pick(t)"
      >
        <span class="tlc-menu-name">{{ t.name }}</span>
        <span v-if="t.directionName" class="tlc-menu-dir" :class="{ 'is-foreign': t.foreign }">{{ t.directionName }}</span>
        <span class="tlc-menu-mode" :class="'mode-' + t.mode">{{ modeLabel(t.mode) }}</span>
        <span class="tlc-menu-free">{{ t.ok ? 'свободно ' + fmtPallets(t.freePallets) + ' п' : t.reason }}</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { formatRestaurantNumber } from '@/lib/legalEntities.js';

const props = defineProps({
  item: { type: Object, required: true },
  // 'restaurant' | 'category' | 'item' — как сгруппирован список
  mode: { type: String, default: 'restaurant' },
  // Машины с остатком места; пустой массив = назначать некуда
  targets: { type: Array, default: () => [] },
  menuOpen: { type: Boolean, default: false },
  canAssign: { type: Boolean, default: true },
});

const emit = defineEmits(['toggle-menu', 'assign', 'dragstart']);

const restaurantLabel = computed(() =>
  formatRestaurantNumber(props.item.restaurant_number, props.item.legal_entity_group)
);

function toggleMenu() {
  if (!props.canAssign) return;
  emit('toggle-menu', props.item.key);
}

function pick(target) {
  emit('assign', { item: props.item, truckUid: target.uid });
}

function onDragStart(e) {
  if (!props.canAssign) return;
  emit('dragstart', { event: e, item: props.item });
}

function catClass(cat) {
  if (cat === 'Сухой') return 'dry';
  if (cat === 'Холод') return 'cold';
  if (cat === 'Мороз') return 'frozen';
  return '';
}

function modeLabel(m) {
  return { any: 'Любой', dry: 'Сухой', cold: 'Холод', frozen: 'Мороз' }[m] || m;
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
.tlc {
  position: relative;
  border: 1px solid var(--tl-line, #e6ddd2);
  border-left: 3px solid var(--tl-line, #e6ddd2);
  border-radius: 8px;
  background: #fff;
  padding: 7px 10px;
  margin-bottom: 6px;
  cursor: pointer;
  transition: border-color 0.12s, box-shadow 0.12s;
}
.tlc:hover { border-color: #d8c9b8; box-shadow: 0 1px 6px rgba(0,0,0,0.06); }
.tlc.is-open { border-color: #E76F51; box-shadow: 0 2px 10px rgba(231,111,81,0.18); }
.tlc-bk_vm { border-left-color: #E76F51; }
.tlc-ps { border-left-color: #2e7d32; }

.tlc-top { display: flex; align-items: baseline; gap: 8px; }
.tlc-num { font-weight: 700; font-size: 13px; color: #502314; flex: 0 0 auto; }
.tlc-city { font-size: 12px; color: #8b7355; flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tlc-sku { font-size: 12px; color: #4a4a4a; flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tlc-total { font-size: 12px; color: #6b6b6b; font-weight: 600; margin-left: auto; white-space: nowrap; }
.tlc-qty { font-size: 11px; color: #8b7355; margin-top: 2px; }
.tlc-dir { font-size: 10px; font-weight: 600; color: #0d47a1; background: #e3f2fd; border-radius: 8px; padding: 1px 6px; white-space: nowrap; max-width: 120px; overflow: hidden; text-overflow: ellipsis; }
.tlc-partial { font-size: 10px; font-weight: 600; color: #b35900; background: #fff3e0; border-radius: 8px; padding: 1px 6px; white-space: nowrap; }

.tlc-cats { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
.tlc-badge { font-size: 10px; font-weight: 600; padding: 1px 6px; border-radius: 8px; white-space: nowrap; }
.cat-dry { background: #fff4e0; color: #8a5a00; }
.cat-cold { background: #e3f2fd; color: #0d47a1; }
.cat-frozen { background: #ede7f6; color: #4527a0; }

/* Выбор машины по клику */
.tlc-menu {
  position: absolute;
  top: calc(100% - 2px);
  left: 0;
  right: 0;
  z-index: 30;
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 4px;
  background: #fff;
  border: 1px solid #e0d5c8;
  border-radius: 8px;
  box-shadow: 0 8px 20px rgba(0,0,0,0.12);
  max-height: 220px;
  overflow-y: auto;
}
.tlc-menu-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 8px;
  border: none;
  background: none;
  border-radius: 6px;
  font-size: 12px;
  cursor: pointer;
  text-align: left;
}
.tlc-menu-item:hover:not(.is-disabled) { background: #faf6f1; }
.tlc-menu-item.is-disabled { opacity: 0.5; cursor: default; }
.tlc-menu-name { font-weight: 600; color: #502314; white-space: nowrap; }
/* Направление рейса прямо в списке выбора: иначе не видно, куда едет машина */
.tlc-menu-dir { font-size: 10px; font-weight: 600; color: #0d47a1; background: #e3f2fd; border-radius: 8px; padding: 1px 6px; white-space: nowrap; max-width: 110px; overflow: hidden; text-overflow: ellipsis; }
.tlc-menu-dir.is-foreign { color: #b35900; background: #fff3e0; }
.tlc-menu-mode { font-size: 10px; padding: 1px 6px; border-radius: 8px; white-space: nowrap; }
.mode-any { background: #f1f3f5; color: #555; }
.mode-dry { background: #fff4e0; color: #8a5a00; }
.mode-cold { background: #e3f2fd; color: #0d47a1; }
.mode-frozen { background: #ede7f6; color: #4527a0; }
.tlc-menu-free { margin-left: auto; font-size: 11px; color: #8b7355; white-space: nowrap; }
.tlc-menu-empty { padding: 8px; font-size: 12px; color: #8b7355; text-align: center; }
</style>
