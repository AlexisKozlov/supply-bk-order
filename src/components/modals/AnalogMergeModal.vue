<template>
  <Teleport to="body">
    <div class="modal" @click.self="trySkip">
      <div class="modal-box" style="width: min(560px, calc(100% - 32px));">
        <div class="modal-header">
          <h2>Найдены аналоги</h2>
          <button class="modal-close" @click="trySkip">&times;</button>
        </div>

        <div class="am-warning">Убедитесь, что единицы в файле (коробки/штуки) совпадают с единицами в параметрах заказа.</div>

        <div class="am-hint">Отметьте аналоги, остатки и расход которых нужно прибавить:</div>

        <div v-for="merge in merges" :key="merge.itemSku" class="am-group">
          <div class="am-item">
            <span class="am-sku">{{ merge.itemSku }}</span>
            <span class="am-name">{{ merge.itemName }}</span>
          </div>
          <div v-for="a in merge.analogs" :key="a.sku" class="am-analog" :class="{ 'am-unchecked': !a.checked, 'am-shared': a.shared }" @click="toggleAnalog(a)">
            <span class="am-tick">{{ a.checked ? '✓' : '' }}</span>
            <span class="am-arrow">←</span>
            <span class="am-sku">{{ a.sku }}</span>
            <span class="am-aname">{{ a.name }}</span>
            <span class="am-vals">
              <template v-if="a.stock">ост: {{ a.stock }}</template>
              <template v-if="a.consumption"><template v-if="a.stock">, </template>расх: {{ a.consumption }}</template>
              <template v-if="a.transit"><template v-if="a.stock || a.consumption">, </template>тр: {{ a.transit }}</template>
            </span>
            <span v-if="a.shared" class="am-shared-badge" :title="'Этот аналог также предложен для ' + a.sharedWith">⚠ общий с {{ a.sharedWith }}</span>
          </div>
        </div>

        <div v-if="hasSharedConflict" class="am-conflict-warning">
          Один и тот же аналог отмечен для нескольких товаров — его данные прибавятся к каждому. Оставьте галочку только у одного.
        </div>

        <div class="modal-actions" style="margin-top: 16px;">
          <button class="btn" @click="trySkip">Пропустить</button>
          <button class="btn primary" @click="$emit('apply')" :disabled="!hasChecked">Применить</button>
        </div>
      </div>
    </div>
    <ConfirmModal v-if="showConfirmClose" title="Закрыть без применения?" message="Выбранные аналоги не будут применены." @confirm="emit('skip')" @cancel="showConfirmClose = false" />
  </Teleport>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import ConfirmModal from './ConfirmModal.vue';

const props = defineProps({ merges: Array });
const emit = defineEmits(['apply', 'skip']);
const showConfirmClose = ref(false);
let initialState = '';

const hasChecked = computed(() => props.merges?.some(m => m.analogs.some(a => a.checked)));

const hasSharedConflict = computed(() => {
  if (!props.merges) return false;
  const checkedByAnalog = new Map();
  for (const m of props.merges) {
    for (const a of m.analogs) {
      if (!a.checked || !a.shared) continue;
      if (!checkedByAnalog.has(a.sku)) checkedByAnalog.set(a.sku, []);
      checkedByAnalog.get(a.sku).push(m.itemSku);
    }
  }
  // Также проверить первый (у него нет shared, но его sku может быть shared у других)
  for (const m of props.merges) {
    for (const a of m.analogs) {
      if (!a.checked || a.shared) continue;
      // Проверить, есть ли этот sku checked у кого-то ещё
      const others = props.merges.filter(m2 => m2 !== m && m2.analogs.some(a2 => a2.sku === a.sku && a2.checked));
      if (others.length > 0) {
        if (!checkedByAnalog.has(a.sku)) checkedByAnalog.set(a.sku, [m.itemSku]);
        others.forEach(o => checkedByAnalog.get(a.sku).push(o.itemSku));
      }
    }
  }
  return [...checkedByAnalog.values()].some(arr => arr.length > 1);
});

function toggleAnalog(a) {
  a.checked = !a.checked;
}

function getAnalogState() {
  return JSON.stringify(props.merges?.map(m => m.analogs.map(a => a.checked)));
}

function trySkip() {
  if (getAnalogState() !== initialState) { showConfirmClose.value = true; return; }
  emit('skip');
}

function onKey(e) {
  if (e.key === 'Escape') { e.preventDefault(); if (!showConfirmClose.value) trySkip(); }
  else if (e.key === 'Enter') { e.preventDefault(); if (hasChecked.value) emit('apply'); }
}
onMounted(() => {
  initialState = getAnalogState();
  document.addEventListener('keydown', onKey);
});
onUnmounted(() => document.removeEventListener('keydown', onKey));
</script>

<style scoped>
.am-warning {
  background: #fff8e1;
  border: 1px solid #ffe082;
  border-radius: 8px;
  padding: 8px 12px;
  font-size: 12px;
  line-height: 1.4;
  color: #7a6200;
  margin-bottom: 10px;
}
.am-hint {
  font-size: 13px;
  color: var(--text-muted, #666);
  margin-bottom: 12px;
}
.am-group {
  border: 1px solid var(--border, #e0e0e0);
  border-radius: 8px;
  padding: 10px 12px;
  margin-bottom: 8px;
}
.am-item {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 600;
  font-size: 13px;
  margin-bottom: 6px;
}
.am-analog {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  padding: 5px 6px;
  color: var(--text-muted, #666);
  cursor: pointer;
  border-radius: 6px;
  transition: background 0.15s, opacity 0.15s;
  user-select: none;
}
.am-analog:hover { background: rgba(0,0,0,0.04); }
.am-unchecked { opacity: 0.4; }
.am-tick {
  width: 18px;
  height: 18px;
  border-radius: 4px;
  border: 2px solid var(--border, #ccc);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 700;
  color: #fff;
  background: #fff;
  flex-shrink: 0;
  transition: all 0.15s;
}
.am-analog:not(.am-unchecked) .am-tick {
  background: var(--bk-brown, #8B4513);
  border-color: var(--bk-brown, #8B4513);
}
.am-arrow { color: var(--bk-brown, #8B4513); font-weight: 700; flex-shrink: 0; }
.am-sku { font-family: monospace; font-size: 11px; color: var(--text-muted, #888); flex-shrink: 0; }
.am-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.am-aname { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
.am-vals { flex-shrink: 0; font-size: 11px; font-weight: 600; color: var(--text, #333); }
.am-shared { border-left: 3px solid #ff9800; }
.am-shared-badge {
  flex-shrink: 0;
  font-size: 10px;
  color: #e65100;
  background: #fff3e0;
  border-radius: 4px;
  padding: 1px 5px;
  white-space: nowrap;
}
.am-conflict-warning {
  background: #fbe9e7;
  border: 1px solid #ef9a9a;
  border-radius: 8px;
  padding: 8px 12px;
  font-size: 12px;
  line-height: 1.4;
  color: #c62828;
  margin-top: 10px;
}
</style>
