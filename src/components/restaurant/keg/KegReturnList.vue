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

    <!-- Как это работает + график этого ресторана -->
    <div class="krt-help">
      <button type="button" class="krt-help-head" @click="helpOpen = !helpOpen">
        <span class="krt-help-icon">❓</span>
        <span class="krt-help-title">Как это работает</span>
        <span class="krt-help-toggle">{{ helpOpen ? 'свернуть' : 'подробнее' }}</span>
      </button>

      <div class="krt-help-schedule">
        <span class="krt-help-sched-item">
          <span class="krt-sched-ico">📅</span>
          <span class="krt-sched-label">День приёма</span>
          <span v-if="myReturnDays.length" class="krt-day-chips">
            <span v-for="d in myReturnDays" :key="d" class="krt-day-chip">{{ d }}</span>
          </span>
          <span v-else class="krt-help-muted">не задан — уточните в отделе закупок</span>
        </span>
        <span v-if="pickupAddress" class="krt-help-sched-item">
          <span class="krt-sched-ico">📍</span>
          <span class="krt-sched-label">Погрузка</span>
          <span class="krt-sched-val">{{ pickupAddress }}</span>
        </span>
      </div>

      <div v-if="helpOpen" class="krt-help-body">
        <div style="border:2px solid #C0392B; background:#FDEDEC; border-radius:8px; padding:10px 14px; margin-bottom:12px; line-height:1.5;">
          <div style="font-weight:800; color:#C0392B; font-size:15px; margin-bottom:2px;">⚠️ Экземпляры ТТН</div>
          Экземпляры <b>№ 1, 3 и 4 — отдаются водителю</b>, экземпляр <b>№ 2 — остаётся ресторану</b>.
          Не забудьте взять <b>подписи водителя</b>.
        </div>
        <ol class="krt-help-steps">
          <li>Нажмите <b>«Новая заявка»</b>.</li>
          <li>Укажите <b>серию и номер ТТН</b> (2 буквы + 7 цифр) и ФИО управляющего.</li>
          <li>Выберите <b>дату возврата</b> (из дней приёма вашего ресторана) и укажите <b>кеги</b> с количеством.</li>
          <li>Нажмите <b>«Сформировать заявку»</b> и <b>распечатайте ТТН на бланке</b> до дедлайна — проверьте, что бланк не испорчен и номер совпадает.</li>
          <li>Дождитесь уведомления о маршрутизации в Telegram-боте <b>@supplyportal_bot</b> — там будут водитель и машина.</li>
          <li>Впишите <b>от руки</b> в распечатанный бланк водителя, машину и «товар принял к перевозке», передайте бланк водителю.</li>
        </ol>
        <div class="krt-help-deadline">
          <b>Сроки:</b> править заявку можно до <b>10:00 предыдущего рабочего дня</b> перед возвратом.
          С 10:00 до 15:00 — только замена испорченного бланка. После 15:00 изменения невозможны.
        </div>
        <a class="krt-help-pdf" href="/keg-returns-memo.pdf" target="_blank" rel="noopener">📄 Скачать памятку (PDF)</a>
      </div>
    </div>

    <div v-if="loading" class="krt-empty">Загрузка...</div>
    <template v-else>
      <div v-if="!rows.length" class="krt-empty-card">
        <div class="krt-empty-illu" v-html="iconKeg"></div>
        <h3>Заявок пока нет</h3>
        <p>Сформируйте заявку и сразу распечатайте ТТН на бланке (до дедлайна) — чтобы убедиться, что бланк не испорчен и номер совпадает с фактическим. Дождитесь уведомления о маршрутизации в Telegram, впишите ОТ РУКИ водителя и машину в распечатанный бланк, возьмите подпись водителя — и передайте ему вместе с кегами.</p>
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
                  ТТН {{ row.bso_series }} {{ row.bso_number }}
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
import { iconKeg, iconKegReturn, fmtDate, statusLabel, WEEKDAY_NAMES } from './kegHelpers.js';

const props = defineProps({
  rows: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  pickupWeekdays: { type: Number, default: 0 },
  pickupAddress: { type: String, default: '' },
});
defineEmits(['new', 'open']);

const helpOpen = ref(false);

// Дни приёма этого ресторана из битовой маски pickup_weekdays (бит 0 = Пн … 6 = Вс)
const myReturnDays = computed(() => {
  const mask = parseInt(props.pickupWeekdays || 0, 10);
  const days = [];
  for (let i = 0; i < 7; i++) if (mask & (1 << i)) days.push(WEEKDAY_NAMES[i]);
  return days;
});

const statusFilter = ref('all');
const STATUS_FILTER_DEFS = [
  { key: 'all', label: 'Все', match: () => true },
  { key: 'DRAFT', label: 'Черновики', match: r => r.status === 'DRAFT' },
  { key: 'SUBMITTED', label: 'Отправлены', match: r => r.status === 'SUBMITTED' },
  { key: 'ROUTED', label: 'Маршрутизированы', match: r => r.status === 'ROUTED' },
  { key: 'NOT_RETURNED', label: 'Не сданы', match: r => r.status === 'NOT_RETURNED' },
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
