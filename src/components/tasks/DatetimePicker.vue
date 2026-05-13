<template>
  <div class="dtp" @click.stop>
    <!-- Быстрые пресеты -->
    <div class="dtp-presets">
      <button type="button" class="dtp-preset" @click="applyPreset('today-evening')">Сегодня вечером</button>
      <button type="button" class="dtp-preset" @click="applyPreset('tomorrow-morning')">Завтра утром</button>
      <button type="button" class="dtp-preset" @click="applyPreset('next-week')">Через неделю</button>
    </div>

    <!-- Навигация по месяцам -->
    <div class="dtp-nav">
      <button type="button" class="dtp-nav-btn" @click="prevMonth" title="Предыдущий месяц">‹</button>
      <div class="dtp-month-label">{{ monthLabel }}</div>
      <button type="button" class="dtp-nav-btn" @click="nextMonth" title="Следующий месяц">›</button>
    </div>

    <!-- Сетка дней недели -->
    <div class="dtp-weekdays">
      <span v-for="(w, i) in weekdays" :key="i" :class="{ 'dtp-weekend': i >= 5 }">{{ w }}</span>
    </div>

    <!-- Сетка дней -->
    <div class="dtp-grid">
      <button v-for="d in monthGrid" :key="d.iso"
              type="button"
              class="dtp-day"
              :class="{
                'is-other': !d.inMonth,
                'is-today': d.isToday,
                'is-selected': d.iso === selectedDayIso,
                'is-weekend': d.weekend,
              }"
              @click="pickDay(d)">
        {{ d.date.getDate() }}
      </button>
    </div>

    <!-- Время -->
    <div class="dtp-time-row">
      <label class="dtp-time-label">Время</label>
      <input type="number" min="0" max="23" v-model.number="hh" class="dtp-time-input" @input="onTimeInput"/>
      <span class="dtp-time-sep">:</span>
      <input type="number" min="0" max="59" v-model.number="mm" class="dtp-time-input" @input="onTimeInput"/>
    </div>

    <!-- Действия -->
    <div class="dtp-actions">
      <button v-if="modelValue" type="button" class="dtp-btn dtp-btn-clear" @click="clear">Убрать срок</button>
      <span class="dtp-actions-spacer"></span>
      <button type="button" class="dtp-btn" @click="$emit('cancel')">Отмена</button>
      <button type="button" class="dtp-btn dtp-btn-primary" :disabled="!selectedDayIso" @click="apply">Применить</button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
  modelValue: { type: String, default: '' }, // ISO 'YYYY-MM-DD HH:MM:SS' или ''
});
const emit = defineEmits(['update:modelValue', 'cancel']);

const weekdays = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
const monthNames = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];

const today = (() => { const d = new Date(); d.setHours(0,0,0,0); return d; })();
function isoOf(d) {
  const pad = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}
function parseValue(s) {
  if (!s) return { day: null, hh: 12, mm: 0 };
  const d = new Date(s);
  if (isNaN(d)) return { day: null, hh: 12, mm: 0 };
  const day = new Date(d); day.setHours(0,0,0,0);
  return { day, hh: d.getHours(), mm: d.getMinutes() };
}

const initial = parseValue(props.modelValue);
const viewDate = ref(initial.day ? new Date(initial.day) : new Date(today));
viewDate.value.setDate(1);
const selectedDayIso = ref(initial.day ? isoOf(initial.day) : '');
const hh = ref(initial.hh);
const mm = ref(initial.mm);

watch(() => props.modelValue, (v) => {
  const { day, hh: h, mm: m } = parseValue(v);
  selectedDayIso.value = day ? isoOf(day) : '';
  hh.value = h; mm.value = m;
});

const monthLabel = computed(() => `${monthNames[viewDate.value.getMonth()]} ${viewDate.value.getFullYear()}`);

const monthGrid = computed(() => {
  const first = new Date(viewDate.value);
  first.setDate(1);
  const dow = (first.getDay() + 6) % 7; // Пн = 0
  const start = new Date(first);
  start.setDate(1 - dow);
  const days = [];
  for (let i = 0; i < 42; i++) {
    const d = new Date(start);
    d.setDate(start.getDate() + i);
    d.setHours(0,0,0,0);
    days.push({
      date: d,
      iso: isoOf(d),
      inMonth: d.getMonth() === viewDate.value.getMonth(),
      isToday: d.getTime() === today.getTime(),
      weekend: d.getDay() === 0 || d.getDay() === 6,
    });
  }
  return days;
});

function prevMonth() {
  const d = new Date(viewDate.value);
  d.setMonth(d.getMonth() - 1, 1);
  viewDate.value = d;
}
function nextMonth() {
  const d = new Date(viewDate.value);
  d.setMonth(d.getMonth() + 1, 1);
  viewDate.value = d;
}
function pickDay(d) {
  selectedDayIso.value = d.iso;
}

function onTimeInput() {
  if (!Number.isFinite(hh.value)) hh.value = 0;
  if (!Number.isFinite(mm.value)) mm.value = 0;
  hh.value = Math.max(0, Math.min(23, hh.value | 0));
  mm.value = Math.max(0, Math.min(59, mm.value | 0));
}

function applyPreset(p) {
  const d = new Date(today);
  if (p === 'today-evening') { hh.value = 18; mm.value = 0; }
  else if (p === 'tomorrow-morning') { d.setDate(d.getDate() + 1); hh.value = 9; mm.value = 0; }
  else if (p === 'next-week') { d.setDate(d.getDate() + 7); hh.value = 9; mm.value = 0; }
  selectedDayIso.value = isoOf(d);
  viewDate.value = new Date(d.getFullYear(), d.getMonth(), 1);
  apply();
}

function apply() {
  if (!selectedDayIso.value) return;
  const [Y, M, D] = selectedDayIso.value.split('-').map(Number);
  const pad = n => String(n).padStart(2, '0');
  const iso = `${Y}-${pad(M)}-${pad(D)} ${pad(hh.value)}:${pad(mm.value)}:00`;
  emit('update:modelValue', iso);
}
function clear() {
  selectedDayIso.value = '';
  emit('update:modelValue', null);
}
</script>

<style scoped>
.dtp {
  width: 280px;
  background: #fff;
  border: 1px solid #E6E1D7;
  border-radius: 12px;
  box-shadow: 0 12px 32px rgba(15,23,42,0.14), 0 2px 4px rgba(15,23,42,0.06);
  padding: 12px;
  font-family: inherit;
  color: #1A1814;
  user-select: none;
}

/* Пресеты */
.dtp-presets {
  display: flex; flex-wrap: wrap; gap: 4px;
  margin-bottom: 10px;
}
.dtp-preset {
  flex: 1; min-width: 0;
  padding: 5px 8px;
  font-family: inherit; font-size: 11.5px; font-weight: 600;
  background: #F3F0E8; color: #534D40;
  border: 1px solid transparent;
  border-radius: 6px;
  cursor: pointer;
  white-space: nowrap;
  transition: background 140ms ease, color 140ms ease;
}
.dtp-preset:hover { background: #E6E1D7; color: #1A1814; }

/* Навигация */
.dtp-nav {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 6px;
}
.dtp-month-label {
  flex: 1;
  text-align: center;
  font-size: 13px; font-weight: 700;
  color: #1A1814;
  text-transform: capitalize;
}
.dtp-nav-btn {
  width: 28px; height: 28px;
  border: none; background: transparent; cursor: pointer;
  font-size: 16px; color: #534D40;
  border-radius: 6px;
  display: inline-flex; align-items: center; justify-content: center;
  transition: background 140ms ease;
}
.dtp-nav-btn:hover { background: #F3F0E8; color: #1A1814; }

/* Дни недели */
.dtp-weekdays {
  display: grid; grid-template-columns: repeat(7, 1fr);
  font-size: 10.5px; font-weight: 600;
  color: #9C9384;
  text-align: center;
  margin-bottom: 4px;
}
.dtp-weekdays .dtp-weekend { color: #B23B16; }

/* Сетка дней */
.dtp-grid {
  display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px;
}
.dtp-day {
  aspect-ratio: 1 / 1;
  display: inline-flex; align-items: center; justify-content: center;
  background: transparent; border: 1px solid transparent;
  border-radius: 6px;
  font-family: inherit; font-size: 12.5px; font-weight: 500;
  color: #1A1814; cursor: pointer;
  transition: background 140ms ease, color 140ms ease, border-color 140ms ease;
}
.dtp-day:hover { background: #F3F0E8; }
.dtp-day.is-other { color: #C8C1B2; }
.dtp-day.is-today { font-weight: 700; color: #B85A0E; }
.dtp-day.is-weekend:not(.is-other) { color: #B23B16; }
.dtp-day.is-selected {
  background: #E87A1E !important;
  color: #fff !important;
  font-weight: 700;
}

/* Время */
.dtp-time-row {
  display: flex; align-items: center; gap: 4px;
  margin-top: 10px; padding-top: 10px;
  border-top: 1px solid #EFEAE0;
}
.dtp-time-label {
  font-size: 11.5px; font-weight: 600;
  color: #6E6657;
  margin-right: 6px;
}
.dtp-time-input {
  width: 48px;
  padding: 6px 8px;
  border: 1px solid #E6E1D7;
  border-radius: 6px;
  font-family: inherit; font-size: 13px; font-weight: 600;
  text-align: center;
  color: #1A1814;
  background: #fff;
  -moz-appearance: textfield;
}
.dtp-time-input::-webkit-outer-spin-button,
.dtp-time-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.dtp-time-input:focus {
  outline: none;
  border-color: #E87A1E;
  box-shadow: 0 0 0 3px rgba(232,122,30,0.25);
}
.dtp-time-sep { font-weight: 700; color: #6E6657; }

/* Действия */
.dtp-actions {
  display: flex; align-items: center; gap: 6px;
  margin-top: 10px;
}
.dtp-actions-spacer { flex: 1; }
.dtp-btn {
  padding: 6px 12px;
  border: 1px solid #E6E1D7;
  background: #fff; color: #534D40;
  border-radius: 7px;
  font-family: inherit; font-size: 12px; font-weight: 600;
  cursor: pointer;
  transition: background 140ms ease, border-color 140ms ease, color 140ms ease;
}
.dtp-btn:hover { background: #F3F0E8; color: #1A1814; }
.dtp-btn-primary {
  background: #E87A1E; color: #fff; border-color: #E87A1E;
}
.dtp-btn-primary:hover:not(:disabled) { background: #D26B12; border-color: #D26B12; color: #fff; }
.dtp-btn-primary:disabled { opacity: 0.55; cursor: default; }
.dtp-btn-clear {
  color: #B23B16; border-color: #FEE7E0;
  background: #FEF3F0;
}
.dtp-btn-clear:hover { background: #FEE7E0; color: #B23B16; }
</style>
