<template>
  <div>
    <div class="krt-header">
      <div class="krt-header-title">
        <span class="krt-header-icon" v-html="iconKegReturn"></span>
        <div>
          <h2 class="krt-title">Возврат кег</h2>
          <p class="krt-sub">Оформление ТТН на возврат пустых кег.</p>
        </div>
      </div>
      <button class="krt-btn primary lg" @click="$emit('new')">
        <span class="krt-btn-plus">+</span>
        Новая заявка
      </button>
    </div>

    <div v-if="loading" class="krt-empty">Загрузка...</div>
    <template v-else>
      <div v-if="!rows.length" class="krt-empty-card">
        <div class="krt-empty-illu" v-html="iconKeg"></div>
        <h3>Заявок пока нет</h3>
        <p>Сформируйте заявку и сразу распечатайте ТТН на бланке БСО (до дедлайна) — чтобы убедиться, что бланк не испорчен и номер совпадает с фактическим. Дождитесь уведомления о маршрутизации в Telegram, впишите ОТ РУКИ водителя и машину в распечатанный бланк, возьмите подпись водителя — и передайте ему вместе с кегами.</p>
        <button class="krt-btn primary" @click="$emit('new')">+ Новая заявка</button>
      </div>
      <template v-else>
        <div class="krt-filter-chips">
          <button
            v-for="f in statusFilters"
            :key="f.key"
            class="krt-chip"
            :class="{ active: statusFilter === f.key }"
            @click="statusFilter = f.key"
          >
            {{ f.label }}
            <span v-if="f.count" class="krt-chip-count">{{ f.count }}</span>
          </button>
        </div>
        <div v-if="!filteredRows.length" class="krt-empty">В этой категории заявок нет</div>
        <div v-else class="krt-list">
          <button
            v-for="row in filteredRows"
            :key="row.id"
            type="button"
            class="krt-card"
            :class="'krt-card--' + row.status"
            @click="$emit('open', row)"
          >
            <span class="krt-card-stripe"></span>
            <div class="krt-card-body">
              <div class="krt-card-row1">
                <div class="krt-card-date">{{ fmtDate(row.return_date) }}</div>
                <span :class="'krt-badge krt-badge-' + row.status">{{ statusLabel(row.status) }}</span>
              </div>
              <div class="krt-card-row2">
                <span v-if="row.total_kegs" class="krt-card-pill">
                  <span class="krt-card-pill-num">{{ row.total_kegs }}</span> кег
                </span>
                <span v-if="row.bso_series || row.bso_number" class="krt-card-meta">
                  БСО {{ row.bso_series }} {{ row.bso_number }}
                </span>
                <span v-if="row.driver" class="krt-card-meta">· {{ row.driver }}</span>
              </div>
            </div>
            <span class="krt-card-arrow">›</span>
          </button>
        </div>
      </template>
    </template>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { iconKeg, iconKegReturn, fmtDate, statusLabel } from './kegHelpers.js';

const props = defineProps({
  rows: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
});
defineEmits(['new', 'open']);

const statusFilter = ref('all');
const STATUS_FILTER_DEFS = [
  { key: 'all', label: 'Все', match: () => true },
  { key: 'DRAFT', label: 'Черновики', match: r => r.status === 'DRAFT' },
  { key: 'SUBMITTED', label: 'Отправлены', match: r => r.status === 'SUBMITTED' },
  { key: 'ROUTED', label: 'Маршрутизированы', match: r => r.status === 'ROUTED' },
  { key: 'CANCELLED', label: 'Отменены', match: r => r.status === 'CANCELLED' },
];
const statusFilters = computed(() => STATUS_FILTER_DEFS.map(f => ({
  key: f.key,
  label: f.label,
  count: f.key === 'all' ? props.rows.length : props.rows.filter(f.match).length,
})));
const filteredRows = computed(() => {
  const def = STATUS_FILTER_DEFS.find(f => f.key === statusFilter.value) || STATUS_FILTER_DEFS[0];
  return props.rows.filter(def.match);
});
</script>
