<template>
  <div class="gantt-wrap">
    <div class="gantt-scroll" ref="scrollEl">
      <!-- Timeline header -->
      <div class="gantt-header" :style="{ width: totalWidth + 'px' }">
        <div v-for="(col, ci) in columns" :key="ci" class="gantt-col-header"
          :style="{ left: col.left + 'px', width: col.width + 'px' }"
          :class="{ 'gantt-col-today': col.isToday }">
          {{ col.label }}
        </div>
      </div>
      <!-- Rows -->
      <div class="gantt-body" :style="{ width: totalWidth + 'px' }">
        <!-- Today marker -->
        <div class="gantt-today-line" :style="{ left: todayOffset + 'px' }"></div>
        <!-- Grid lines -->
        <div v-for="(col, ci) in columns" :key="'g'+ci" class="gantt-grid-line" :style="{ left: col.left + 'px' }"></div>
        <!-- Activity bars -->
        <div v-for="(act, ai) in activities" :key="act.id" class="gantt-row">
          <div class="gantt-bar" :class="'gantt-type-' + act.type"
            :style="barStyle(act)"
            :title="act.name + '\n' + fmtDate(act.date_from) + ' — ' + fmtDate(act.date_to)"
            @click="$emit('select', act.id)">
            <span class="gantt-bar-label">{{ act.name }}</span>
          </div>
        </div>
        <div v-if="!activities.length" class="gantt-empty">Нет активностей для отображения</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, onMounted, nextTick } from 'vue';

const props = defineProps({
  activities: { type: Array, default: () => [] },
});
defineEmits(['select']);

const scrollEl = ref(null);
const PX_PER_DAY = 8;

function toDate(s) { return s ? new Date(s + 'T00:00:00') : null; }
function daysBetween(a, b) { return Math.round((b - a) / 86400000); }
function fmtDate(s) {
  if (!s) return '—';
  const d = new Date(s + 'T00:00:00');
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' });
}

const rangeStart = computed(() => {
  const dates = props.activities.filter(a => a.date_from).map(a => toDate(a.date_from));
  if (!dates.length) return new Date();
  const min = new Date(Math.min(...dates));
  min.setDate(min.getDate() - 14);
  return min;
});

const rangeEnd = computed(() => {
  const dates = props.activities.filter(a => a.date_to).map(a => toDate(a.date_to));
  if (!dates.length) { const d = new Date(); d.setMonth(d.getMonth() + 3); return d; }
  const max = new Date(Math.max(...dates));
  max.setDate(max.getDate() + 14);
  return max;
});

const totalDays = computed(() => Math.max(daysBetween(rangeStart.value, rangeEnd.value), 30));
const totalWidth = computed(() => totalDays.value * PX_PER_DAY);

const columns = computed(() => {
  const cols = [];
  const start = new Date(rangeStart.value);
  const today = new Date(); today.setHours(0,0,0,0);
  // Weekly columns
  const d = new Date(start);
  // Align to Monday
  const dow = d.getDay(); const shift = dow === 0 ? -6 : 1 - dow;
  d.setDate(d.getDate() + shift);
  while (d < rangeEnd.value) {
    const weekStart = new Date(d);
    const left = daysBetween(rangeStart.value, weekStart) * PX_PER_DAY;
    const width = 7 * PX_PER_DAY;
    const isToday = today >= weekStart && today < new Date(weekStart.getTime() + 7 * 86400000);
    const label = weekStart.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
    cols.push({ left: Math.max(left, 0), width, label, isToday });
    d.setDate(d.getDate() + 7);
  }
  return cols;
});

const todayOffset = computed(() => {
  const today = new Date(); today.setHours(0,0,0,0);
  return daysBetween(rangeStart.value, today) * PX_PER_DAY;
});

function barStyle(act) {
  const from = toDate(act.date_from) || new Date();
  const to = toDate(act.date_to) || from;
  const left = daysBetween(rangeStart.value, from) * PX_PER_DAY;
  const width = Math.max(daysBetween(from, to) + 1, 1) * PX_PER_DAY;
  return { left: left + 'px', width: width + 'px' };
}

onMounted(() => {
  nextTick(() => {
    if (scrollEl.value) {
      const offset = todayOffset.value - scrollEl.value.clientWidth / 2;
      scrollEl.value.scrollLeft = Math.max(0, offset);
    }
  });
});
</script>

<style scoped>
.gantt-wrap { border-radius: 14px; overflow: hidden; background: white; box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
.gantt-scroll { overflow-x: auto; overflow-y: auto; max-height: 500px; position: relative; }
.gantt-header { position: sticky; top: 0; z-index: 2; display: flex; background: var(--bk-brown, #502314); height: 36px; min-width: 100%; }
.gantt-col-header { position: absolute; top: 0; height: 36px; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; color: rgba(255,255,255,0.6); border-right: 1px solid rgba(255,255,255,0.08); letter-spacing: 0.3px; }
.gantt-col-today { color: #fff; background: rgba(255,255,255,0.12); }
.gantt-body { position: relative; min-height: 100px; min-width: 100%; background: #FAFAF8; }
.gantt-grid-line { position: absolute; top: 0; bottom: 0; width: 1px; background: #F0EBE5; }
.gantt-today-line { position: absolute; top: 0; bottom: 0; width: 2px; background: #E76F51; z-index: 1; box-shadow: 0 0 6px rgba(231,111,81,0.3); }
.gantt-row { height: 40px; position: relative; border-bottom: 1px solid #F0EBE5; }
.gantt-bar { position: absolute; top: 6px; height: 28px; border-radius: 6px; cursor: pointer; display: flex; align-items: center; padding: 0 10px; overflow: hidden; transition: all 0.15s; min-width: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.15); }
.gantt-bar:hover { transform: translateY(-1px); box-shadow: 0 3px 8px rgba(0,0,0,0.2); }
.gantt-bar-label { font-size: 11px; font-weight: 700; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-shadow: 0 1px 2px rgba(0,0,0,0.25); }
.gantt-type-promo { background: #1D4ED8; }
.gantt-type-new_product { background: #059669; }
.gantt-type-discontinue { background: #DC2626; }
.gantt-type-seasonal { background: #D97706; }
.gantt-type-coupon { background: #7C3AED; }
.gantt-empty { padding: 40px; text-align: center; color: var(--text-muted); font-size: 13px; }
</style>
