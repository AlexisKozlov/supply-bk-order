<template>
  <div class="krt-wrap">
    <KegReturnList
      v-if="!formMode"
      :rows="rows"
      :loading="listLoading"
      :pickup-weekdays="restaurantInfo.pickup_weekdays"
      :pickup-address="restaurantInfo.pickup_address"
      @new="openNew"
      @open="openEdit"
    />
    <KegReturnForm
      v-else
      :initial-id="editingId"
      @back="backToList"
      @deleted="backToList"
    />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { useToastStore } from '@/stores/toastStore.js';
import { roFetch } from '@/lib/roUtils.js';
import KegReturnList from './keg/KegReturnList.vue';
import KegReturnForm from './keg/KegReturnForm.vue';

const route = useRoute();
const toast = useToastStore();

const rows = ref([]);
const listLoading = ref(false);
const formMode = ref(false);
const editingId = ref(null);
const restaurantInfo = ref({ pickup_weekdays: 0, pickup_address: '' });

async function loadRestaurantInfo() {
  try {
    const data = await roFetch('/api/keg-returns/restaurant-info');
    restaurantInfo.value = {
      pickup_weekdays: parseInt(data.pickup_weekdays || 0, 10),
      pickup_address: data.pickup_address || '',
    };
  } catch (e) { /* не критично — памятка покажется без графика */ }
}

async function loadList() {
  listLoading.value = true;
  try {
    const data = await roFetch('/api/keg-returns');
    rows.value = Array.isArray(data) ? data : [];
  } catch (e) {
    toast.error('Ошибка загрузки', e.message || '');
  } finally {
    listLoading.value = false;
  }
}

function openNew() {
  editingId.value = null;
  formMode.value = true;
}

function openEdit(row) {
  editingId.value = row.id;
  formMode.value = true;
}

function backToList() {
  formMode.value = false;
  editingId.value = null;
  loadList();
}

onMounted(async () => {
  loadRestaurantInfo();
  await loadList();
  const qId = route.query.id;
  if (qId) {
    const id = parseInt(qId, 10);
    if (id > 0) {
      editingId.value = id;
      formMode.value = true;
    }
  }
});
</script>

<!--
  Стили без scoped: классы .krt-* используют дочерние компоненты
  (KegReturnList, KegReturnForm и все его секции/модалки).
  Префикс .krt- играет роль namespace — конфликтов нет.
-->
<style>
.krt-wrap {
  padding: 20px 20px 110px;
  max-width: 760px;
  margin: 0 auto;
  color: #2C1A12;
}

/* ═══ Памятка «Как это работает» ═══ */
.krt-help {
  border: 1px solid #F0DCC8;
  background: #FFFaf4;
  border-radius: 12px;
  margin-bottom: 18px;
  overflow: hidden;
}
.krt-help-head {
  display: flex; align-items: center; gap: 10px;
  width: 100%; padding: 12px 16px;
  background: none; border: none; cursor: pointer;
  font-size: 15px; color: #2C1A12; text-align: left;
}
.krt-help-head:hover { background: #FFF1E0; }
.krt-help-icon { font-size: 16px; }
.krt-help-title { font-weight: 700; }
.krt-help-toggle { margin-left: auto; font-size: 12px; color: #B08968; }
.krt-help-schedule {
  display: flex; flex-wrap: wrap; align-items: center; gap: 8px 28px;
  padding: 11px 16px 12px; font-size: 13px; color: #5A4636;
  border-top: 1px solid #F4E4D2;
  background: #FFF6EC;
}
.krt-help-sched-item { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.krt-help-sched-item b { color: #2C1A12; }
.krt-sched-ico { font-size: 14px; line-height: 1; }
.krt-sched-label {
  font-weight: 700; color: #946A3E;
  text-transform: uppercase; letter-spacing: 0.05em; font-size: 11px;
}
.krt-sched-val { color: #3A2A1E; font-weight: 500; }
.krt-day-chips { display: inline-flex; flex-wrap: wrap; gap: 4px; }
.krt-day-chip {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 30px; padding: 2px 9px;
  background: #fff; border: 1px solid #E6CFB6; border-radius: 999px;
  font-size: 12px; font-weight: 700; color: #B5651D;
  box-shadow: 0 1px 1px rgba(140, 90, 40, 0.06);
}
.krt-help-muted { color: #B08968; }
.krt-help-body {
  padding: 4px 16px 16px;
  border-top: 1px dashed #F0DCC8;
}
.krt-help-steps {
  margin: 10px 0; padding-left: 20px;
  font-size: 13px; line-height: 1.55; color: #3A2A1E;
}
.krt-help-steps li { margin-bottom: 5px; }
.krt-help-steps b { color: #2C1A12; }
.krt-help-deadline {
  margin-top: 8px; padding: 10px 12px;
  background: #FFF3E0; border-radius: 8px;
  font-size: 12.5px; line-height: 1.5; color: #8A5A2B;
}
.krt-help-deadline b { color: #6B4416; }
.krt-help-pdf {
  display: inline-flex; align-items: center; gap: 6px;
  margin-top: 12px; padding: 8px 14px;
  background: #fff; border: 1px solid #E0C6AC; border-radius: 8px;
  font-size: 13px; font-weight: 600; color: #B5651D; text-decoration: none;
}
.krt-help-pdf:hover { background: #FFF1E0; border-color: #D0A87C; }

/* ═══ Хедер списка ═══ */
.krt-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 16px; margin-bottom: 20px; flex-wrap: wrap;
}
.krt-title { font-size: 22px; font-weight: 700; margin: 0 0 4px; color: #2C1A12; }
.krt-header-title { display: flex; align-items: center; gap: 12px; }
.krt-header-icon {
  width: 44px; height: 44px;
  flex-shrink: 0;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: 10px;
  background: #FFF1E0;
  color: #E76F51;
}
.krt-header-icon :deep(svg) { width: 26px; height: 26px; }
@media (max-width: 640px) {
  .krt-header-icon { width: 38px; height: 38px; }
  .krt-header-icon :deep(svg) { width: 22px; height: 22px; }
}
.krt-sub { margin: 0; font-size: 13px; color: #8C7B6E; }

/* ═══ Кнопки ═══ */
.krt-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  padding: 10px 16px; border-radius: 10px; border: 1.5px solid transparent;
  font-size: 14px; font-weight: 600; line-height: 1; cursor: pointer;
  font-family: inherit; transition: transform .08s, background .15s, border-color .15s, box-shadow .15s;
  white-space: nowrap;
}
.krt-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.krt-btn.lg { padding: 12px 20px; font-size: 15px; }
.krt-btn.primary { background: #E76F51; color: #fff; border-color: #E76F51; }
.krt-btn.primary:hover:not(:disabled) { background: #D9603F; border-color: #D9603F; box-shadow: 0 4px 12px rgba(231,111,81,.25); }
.krt-btn.outline { background: #fff; color: #502314; border-color: #E8DCC8; }
.krt-btn.outline:hover:not(:disabled) { background: #FFF8F0; border-color: #D6C5AB; }
.krt-btn.ghost { background: transparent; color: #6B5344; border-color: #EDE7DF; }
.krt-btn.ghost:hover:not(:disabled) { background: #F7F2EB; }
.krt-btn.danger { background: #E53935; color: #fff; border-color: #E53935; }
.krt-btn.danger:hover:not(:disabled) { background: #C62828; border-color: #C62828; }
.krt-btn-plus { font-size: 18px; line-height: 1; font-weight: 400; margin-right: 2px; }
.krt-link {
  background: none; border: none; padding: 6px 4px;
  font: inherit; cursor: pointer; color: #6B5344; text-decoration: underline;
  text-underline-offset: 3px; font-size: 13px;
}
.krt-link.danger { color: #E53935; }
.krt-link:hover { opacity: .8; }

/* ═══ Список карточек ═══ */
.krt-list { display: flex; flex-direction: column; gap: 10px; }
.krt-card {
  display: flex; align-items: stretch; gap: 0;
  background: #fff; border: 1px solid #ECE3D6; border-radius: 12px;
  padding: 0; overflow: hidden; cursor: pointer;
  text-align: left; font-family: inherit;
  transition: transform .08s, box-shadow .15s, border-color .15s;
  width: 100%;
}
.krt-card:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(80,35,20,.08); border-color: #D6C5AB; }
.krt-card-stripe { width: 5px; flex-shrink: 0; background: #C8C8C8; }
.krt-card--DRAFT .krt-card-stripe { background: #B0AFAA; }
.krt-card--SUBMITTED .krt-card-stripe { background: #F4A261; }
.krt-card--ROUTED .krt-card-stripe { background: #43A047; }
.krt-card--CANCELLED .krt-card-stripe { background: #E57373; }
.krt-card--NOT_RETURNED .krt-card-stripe { background: #C62828; }
.krt-card-body { flex: 1; padding: 14px 16px; min-width: 0; }
.krt-card-row1 { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 6px; }
.krt-card-date { font-weight: 700; font-size: 16px; color: #2C1A12; }
.krt-card-row2 { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; font-size: 13px; color: #8C7B6E; }
.krt-card-pill {
  display: inline-flex; align-items: baseline; gap: 4px;
  padding: 3px 10px; border-radius: 999px;
  background: #FFF1E0; color: #C16B4D; font-weight: 600;
}
.krt-card-pill-num { font-size: 14px; font-weight: 700; }
.krt-card-meta { white-space: nowrap; }
.krt-card-arrow {
  display: flex; align-items: center; padding: 0 16px;
  font-size: 22px; color: #C7B9A7; font-weight: 300;
}

/* Пустой список */
.krt-empty-card {
  background: #fff; border: 1px solid #ECE3D6; border-radius: 14px;
  padding: 36px 24px; text-align: center;
}
.krt-empty-illu { width: 56px; height: 56px; margin: 0 auto 12px; color: #C7B9A7; }
.krt-empty-illu svg { width: 100%; height: 100%; }
.krt-empty-card h3 { margin: 0 0 6px; font-size: 17px; }
.krt-empty-card p { margin: 0 0 16px; color: #8C7B6E; font-size: 13.5px; }

/* ═══ Чипы статусов ═══ */
.krt-filter-chips {
  display: flex; gap: 8px; margin-bottom: 14px;
  overflow-x: auto; padding-bottom: 4px; -webkit-overflow-scrolling: touch;
}
.krt-filter-chips::-webkit-scrollbar { height: 4px; }
.krt-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 7px 12px; border: 1.5px solid #E8DCC8;
  background: #FFFBF6; color: #6B5344;
  border-radius: 999px; cursor: pointer; font-size: 13px; font-weight: 600;
  white-space: nowrap; transition: all .15s; flex-shrink: 0;
  font-family: inherit;
}
.krt-chip:hover { border-color: #E76F51; }
.krt-chip.active { background: #E76F51; color: #fff; border-color: #E76F51; }
.krt-chip-count {
  display: inline-block; min-width: 18px; padding: 1px 7px;
  border-radius: 10px; background: rgba(0,0,0,.08);
  font-size: 11px; font-weight: 700;
}
.krt-chip.active .krt-chip-count { background: rgba(255,255,255,.25); }

/* ═══ Бейджи ═══ */
.krt-badge {
  display: inline-block; padding: 3px 10px; border-radius: 999px;
  font-size: 11.5px; font-weight: 700; letter-spacing: .02em;
}
.krt-badge-DRAFT { background: #ECEAE5; color: #6B5344; }
.krt-badge-SUBMITTED { background: #FFF1E0; color: #C16B4D; }
.krt-badge-ROUTED { background: #E5F3E5; color: #2E7D32; }
.krt-badge-CANCELLED { background: #FCE4EC; color: #C62828; }
.krt-badge-NOT_RETURNED { background: #FDE0DC; color: #B71C1C; }

/* ═══ Форма ═══ */
.krt-form-wrap { display: flex; flex-direction: column; gap: 14px; }
.krt-form-topbar {
  display: flex; align-items: center; gap: 12px; padding-bottom: 6px;
}
.krt-form-title-block { flex: 1; min-width: 0; }
.krt-form-title { font-size: 18px; font-weight: 700; line-height: 1.2; color: #2C1A12; }
.krt-form-sub {
  display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
  margin-top: 4px; font-size: 12.5px; color: #8C7B6E;
}
.krt-deadline-inline b { color: #C16B4D; font-weight: 700; font-variant-numeric: tabular-nums; }
.krt-deadline-passed { color: #E53935; font-weight: 700; }

.krt-section {
  background: #fff; border: 1px solid #ECE3D6; border-radius: 14px;
  padding: 18px 18px 16px; box-shadow: 0 1px 0 rgba(80,35,20,.02);
}
.krt-section-head {
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; margin-bottom: 14px;
}
.krt-section-head h3 { margin: 0; font-size: 15px; font-weight: 700; color: #2C1A12; }
.krt-section-counter {
  font-size: 12.5px; color: #C16B4D; font-weight: 700;
  background: #FFF1E0; padding: 3px 10px; border-radius: 999px;
}

.krt-fld { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
.krt-fld:last-child { margin-bottom: 0; }
.krt-fld-row2 { display: grid; grid-template-columns: 100px 1fr; gap: 12px; margin-bottom: 12px; }
.krt-fld-row2 .krt-fld { margin-bottom: 0; }
.krt-fld-row3 {
  display: grid;
  grid-template-columns: minmax(220px, 1fr) 120px 150px;
  gap: 12px; margin-bottom: 12px;
}
.krt-fld-row3 .krt-fld { margin-bottom: 0; }
.krt-input-mono {
  font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace;
  font-size: 15px; letter-spacing: 0.06em; text-align: center;
}
.krt-fld-bso-series .krt-input-mono { text-transform: uppercase; }
.krt-fld-label {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 12px; font-weight: 600; text-transform: uppercase;
  letter-spacing: .04em; color: #8C7B6E;
}
.krt-input {
  width: 100%; padding: 0 12px; border-radius: 10px;
  border: 1.5px solid #E8DCC8; background: #fff; color: #2C1A12;
  font-size: 15px; font-family: inherit;
  height: 44px; box-sizing: border-box; line-height: 1.2;
  transition: border-color .15s, box-shadow .15s;
  -webkit-appearance: none; appearance: none;
}
.krt-input:focus {
  outline: none; border-color: #E76F51;
  box-shadow: 0 0 0 3px rgba(231,111,81,.15);
}
.krt-input:disabled { background: #F7F2EB; color: #8C7B6E; cursor: not-allowed; }
.krt-input-sm { max-width: 100%; text-transform: uppercase; }
.krt-input-err { border-color: #E53935; background: #FFF6F5; }
.krt-input-err:focus { box-shadow: 0 0 0 3px rgba(229,57,53,.15); }
.krt-fld-empty {
  padding: 10px 12px; border-radius: 10px; background: #FFF8E6;
  border: 1px solid #FFE0A8; color: #8C5A1C; font-size: 13px;
}
.krt-readonly {
  padding: 11px 12px; border-radius: 10px; background: #F7F2EB;
  color: #2C1A12; font-size: 14.5px;
}

/* Подсказки */
.krt-tip { position: relative; display: inline-flex; }
.krt-tip-icon {
  display: inline-flex; align-items: center; justify-content: center;
  width: 16px; height: 16px; border-radius: 50%;
  background: #E8DCC8; color: #6B5344;
  font-size: 11px; font-weight: 700; cursor: help;
  outline: none;
}
.krt-tip-icon:hover, .krt-tip-icon:focus { background: #E76F51; color: #fff; }
.krt-tip-bubble {
  position: absolute; left: 0; top: 100%;
  margin-top: 8px; min-width: 220px; max-width: 280px;
  padding: 10px 12px; border-radius: 8px;
  background: #2C1A12; color: #FFF8F0;
  font-size: 12.5px; font-weight: 400; line-height: 1.4;
  letter-spacing: 0; text-transform: none;
  opacity: 0; pointer-events: none;
  transform: translateY(-4px);
  transition: opacity .15s, transform .15s;
  z-index: 50;
  box-shadow: 0 6px 18px rgba(0,0,0,.18);
}
.krt-tip-bubble::before {
  content: ''; position: absolute; left: 8px; top: -5px;
  width: 10px; height: 10px; background: #2C1A12;
  transform: rotate(45deg);
}
.krt-tip-icon:hover + .krt-tip-bubble,
.krt-tip-icon:focus + .krt-tip-bubble { opacity: 1; transform: translateY(0); pointer-events: auto; }

/* ═══ Кеги ═══ */
.krt-keg-list { display: flex; flex-direction: column; gap: 6px; }
.krt-keg-row {
  display: grid; grid-template-columns: 44px 1fr auto; gap: 12px;
  align-items: center; padding: 8px 10px;
  border-radius: 10px; transition: background .15s;
}
.krt-keg-row:hover { background: #FAF5EE; }
.krt-keg-row.active { background: #FFF6EC; }
.krt-keg-thumb {
  width: 44px; height: 44px; border-radius: 10px;
  border: 1px solid #ECE3D6; background: #FFF8F0;
  overflow: hidden; padding: 0; cursor: zoom-in;
  display: flex; align-items: center; justify-content: center;
  font-family: inherit;
}
.krt-keg-thumb:disabled { cursor: default; }
.krt-keg-thumb.has-photo:hover { border-color: #E76F51; }
.krt-keg-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.krt-keg-thumb-placeholder { width: 22px; height: 22px; color: #C7B9A7; }
.krt-keg-thumb-placeholder svg { width: 100%; height: 100%; }
.krt-keg-info { min-width: 0; }
.krt-keg-name { font-size: 14px; font-weight: 500; color: #2C1A12; line-height: 1.3; }
.krt-keg-code {
  display: inline-block; font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace;
  font-size: 11px; font-weight: 600; color: #8C7B6E;
  background: #FFF8F0; border: 1px solid #ECE3D6; border-radius: 5px;
  padding: 1px 6px; margin-right: 6px; vertical-align: 1px;
  letter-spacing: 0.02em;
}

/* Степпер */
.krt-stepper {
  display: inline-flex; align-items: stretch; border-radius: 10px;
  border: 1.5px solid #E8DCC8; overflow: hidden;
  background: #fff;
}
.krt-stepper.disabled { opacity: .55; }
.krt-step-btn {
  width: 36px; min-height: 36px;
  background: #FFFBF6; color: #6B5344; border: none;
  font-size: 18px; font-weight: 600; cursor: pointer;
  display: inline-flex; align-items: center; justify-content: center;
  font-family: inherit;
  transition: background .12s, color .12s;
}
.krt-step-btn:hover:not(:disabled) { background: #E76F51; color: #fff; }
.krt-step-btn:disabled { opacity: .35; cursor: not-allowed; }
.krt-step-input {
  width: 48px; border: none; border-left: 1px solid #ECE3D6; border-right: 1px solid #ECE3D6;
  text-align: center; font-size: 15px; font-weight: 700;
  color: #2C1A12; background: transparent; padding: 0; min-height: 36px;
  font-family: inherit;
  -moz-appearance: textfield;
}
.krt-step-input::-webkit-outer-spin-button,
.krt-step-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.krt-step-input:disabled { color: #8C7B6E; }

.krt-keg-summary {
  margin-top: 12px; padding: 10px 14px; border-radius: 10px;
  background: #F4FBF4; border: 1px solid #C8E6C9; color: #2E7D32;
  font-size: 13.5px; text-align: center;
}
.krt-keg-summary.empty {
  background: #F7F2EB; border-color: #E8DCC8; color: #8C7B6E;
}
.krt-keg-summary strong { font-weight: 700; }

/* ═══ Полоса действий ═══ */
.krt-actions-bar {
  position: sticky; bottom: 0;
  margin: 12px -20px 0; padding: 10px 20px 12px;
  background: linear-gradient(to top, #FAF6EF 70%, rgba(250,246,239,0));
  z-index: 20;
}
.krt-actions-bar-inner {
  display: flex; flex-wrap: wrap; gap: 8px;
  background: #fff; border: 1px solid #ECE3D6; border-radius: 12px;
  padding: 10px 12px;
  box-shadow: 0 -2px 8px rgba(80,35,20,.05);
}
.krt-actions-bar-inner .krt-btn { flex: 1 1 auto; min-width: 110px; }
.krt-actions-bar-extra {
  display: flex; justify-content: flex-end; padding: 6px 6px 0;
}
.krt-nr-reason-note { margin-top: 6px; font-size: 13px; color: #B71C1C; background: #FDE0DC; border-radius: 8px; padding: 6px 10px; }
/* Кнопка «Кеги не сдал» — заметная, во всю ширину */
.krt-notreturned-bar { padding: 12px 6px 2px; }
.krt-notreturned-btn { width: 100%; box-shadow: 0 4px 12px rgba(229,57,53,.28); }
.krt-notreturned-icon { margin-right: 4px; }

/* ═══ Просмотр фото ═══ */
.krt-photo-overlay {
  position: fixed; inset: 0; z-index: 1000;
  background: rgba(20,10,5,.7); backdrop-filter: blur(2px);
  display: flex; align-items: center; justify-content: center; padding: 20px;
}
.krt-photo-modal {
  background: #fff; border-radius: 14px; overflow: hidden;
  max-width: 100%; max-height: 100%;
  display: flex; flex-direction: column;
}
.krt-photo-head {
  display: flex; align-items: center; justify-content: space-between;
  gap: 16px; padding: 12px 14px; background: #2C1A12; color: #fff;
  font-size: 14px;
}
.krt-photo-close {
  background: none; border: none; color: #fff;
  font-size: 28px; line-height: 1; cursor: pointer; padding: 0 6px;
  font-family: inherit;
}
.krt-photo-modal img {
  display: block; max-width: 80vw; max-height: 78vh; object-fit: contain;
  background: #FAF6EF;
}

/* ═══ Подтверждение ═══ */
.krt-confirm-overlay {
  position: fixed; inset: 0; z-index: 1100;
  background: rgba(20,10,5,.55);
  display: flex; align-items: center; justify-content: center; padding: 16px;
}
.krt-confirm {
  background: #fff; border-radius: 14px;
  padding: 22px 22px 18px; max-width: 420px; width: 100%;
  box-shadow: 0 10px 40px rgba(0,0,0,.2);
}
.krt-confirm h3 { margin: 0 0 8px; font-size: 17px; color: #2C1A12; }
.krt-confirm p { margin: 0 0 18px; color: #6B5344; font-size: 14px; line-height: 1.5; }
.krt-confirm-actions { display: flex; gap: 8px; justify-content: flex-end; }

/* ═══ Секция «Бланк БСО» (замена + история) ═══ */
.krt-bso-section { background: #fff; }
.krt-bso-replaced-tag {
  display: inline-block; margin-left: 8px;
  padding: 2px 9px; border-radius: 999px;
  background: #FFE0B2; color: #C16B4D;
  font-size: 11px; font-weight: 700; vertical-align: 2px;
}
.krt-bso-replace-cta {
  display: flex; align-items: center; justify-content: space-between;
  gap: 14px; flex-wrap: wrap;
  padding: 14px 16px; border-radius: 12px;
  background: linear-gradient(135deg, #FFF8F0, #FFFBF6);
  border: 1.5px solid #FFD9B6; margin-bottom: 12px;
}
.krt-bso-replace-text { flex: 1; min-width: 200px; }
.krt-bso-replace-title { font-weight: 700; font-size: 15px; color: #2C1A12; margin-bottom: 2px; }
.krt-bso-replace-sub { font-size: 12.5px; color: #6B5344; line-height: 1.4; }
.krt-bso-cutoff-msg {
  padding: 12px 14px; border-radius: 10px;
  background: #F7F2EB; color: #6B5344;
  font-size: 13px; line-height: 1.5; margin-bottom: 12px;
}
.krt-bso-history-title {
  font-size: 12px; font-weight: 700; color: #8B7355;
  text-transform: uppercase; letter-spacing: 0.4px;
  margin: 4px 0 6px;
}
.krt-bso-history-list { list-style: none; padding: 0; margin: 0; }
.krt-bso-history-item {
  padding: 10px 12px; border-radius: 8px;
  background: #FAF6EF; margin-bottom: 6px;
  border-left: 3px solid #F4A261;
}
.krt-bso-history-row {
  display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap;
  font-size: 13.5px; color: #2C1A12;
  font-variant-numeric: tabular-nums;
}
.krt-bso-history-old { color: #8B7355; text-decoration: line-through; font-weight: 600; }
.krt-bso-history-arrow { color: #C16B4D; }
.krt-bso-history-new { font-weight: 700; }
.krt-bso-history-time { margin-left: auto; font-size: 12px; color: #8B7355; }
.krt-bso-history-reason { margin-top: 2px; font-size: 12.5px; color: #6B5344; }

/* ═══ Модалка «Заменить БСО» ═══ */
.krt-bso-modal { max-width: 460px; }
.krt-bso-modal-current {
  margin: -2px 0 14px; padding: 8px 12px;
  background: #FAF6EF; border-radius: 8px;
  font-size: 13px; color: #6B5344;
}
.krt-bso-modal-current b { color: #2C1A12; font-variant-numeric: tabular-nums; }
.krt-bso-modal-row {
  display: grid; grid-template-columns: 1fr 1.4fr; gap: 10px; margin-bottom: 10px;
}
.krt-bso-modal-warn {
  margin: 12px 0 16px; font-size: 12px; color: #8B7355;
  padding: 8px 10px; background: #FFF1E0; border-radius: 8px; line-height: 1.45;
}
.krt-bso-modal-warn b { color: #C16B4D; }
@media (max-width: 480px) {
  .krt-bso-modal-row { grid-template-columns: 1fr 1fr; }
}

/* ═══ Модал «Что дальше» после формирования ТТН ═══ */
.krt-submitted-overlay {
  position: fixed; inset: 0; z-index: 1150;
  background: rgba(20,10,5,.55);
  display: flex; align-items: center; justify-content: center; padding: 16px;
  backdrop-filter: blur(2px);
}
.krt-submitted {
  background: #fff; border-radius: 18px;
  padding: 28px 26px 24px; max-width: 460px; width: 100%;
  box-shadow: 0 18px 50px rgba(0,0,0,.22);
  max-height: calc(100vh - 32px);
  max-height: calc(100dvh - 32px);
  overflow-y: auto;
}
.krt-submitted-icon {
  width: 64px; height: 64px; margin: 0 auto 14px;
  border-radius: 50%;
  background: linear-gradient(135deg, #43A047, #66BB6A);
  color: #fff;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 10px 24px rgba(67,160,71,.3);
}
.krt-submitted-title {
  margin: 0 0 4px; text-align: center;
  font-size: 22px; font-weight: 700; color: #2C1A12;
}
.krt-submitted-bso {
  margin: 0 0 18px; text-align: center;
  font-size: 14px; font-weight: 600; color: #8B7355;
  font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace;
  letter-spacing: 0.04em;
}
.krt-submitted-steps {
  list-style: none; padding: 0; margin: 0 0 22px;
  display: flex; flex-direction: column; gap: 14px;
}
.krt-submitted-steps li {
  display: flex; gap: 12px; align-items: flex-start;
  background: #FAFAF8; border: 1px solid #ECE3D6; border-radius: 12px;
  padding: 12px 14px;
}
.krt-step-num {
  flex-shrink: 0;
  width: 28px; height: 28px; border-radius: 50%;
  background: #E76F51; color: #fff;
  display: inline-flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 14px;
}
.krt-step-title { font-size: 14.5px; font-weight: 700; color: #2C1A12; line-height: 1.25; }
.krt-step-sub { font-size: 13px; color: #6B5344; line-height: 1.45; margin-top: 3px; }
.krt-step-sub b { color: #2C1A12; }
.krt-step-warn {
  display: block; margin-top: 6px;
  color: #C16B4D; font-weight: 700; font-size: 12.5px;
}
.krt-step-key { /* Подсветить ключевой шаг (печать БСО) */ }
.krt-step-key .krt-step-num { background: #C16B4D; box-shadow: 0 0 0 4px rgba(193,107,77,0.18); }
.krt-step-key .krt-step-title { color: #C16B4D; }
.krt-submitted-actions {
  display: flex; justify-content: flex-end; gap: 8px; flex-wrap: wrap;
  position: sticky; bottom: 0;
  background: #fff; padding-top: 12px;
}

/* Напоминание о печати */
.krt-print-reminder {
  display: flex; align-items: center; gap: 14px;
  padding: 14px 16px; margin-top: 14px;
  background: linear-gradient(135deg, #FFF3E0, #FFE0B2);
  border: 1.5px solid #F4A261; border-radius: 12px;
  box-shadow: 0 2px 8px rgba(244,162,97,0.15);
  flex-wrap: wrap;
}
.krt-print-reminder-icon { font-size: 28px; flex-shrink: 0; }
.krt-print-reminder-text { flex: 1; min-width: 200px; }
.krt-print-reminder-title { font-weight: 700; color: #2C1A12; font-size: 14px; line-height: 1.3; }
.krt-print-reminder-sub { font-size: 12.5px; color: #6B5344; line-height: 1.45; margin-top: 4px; }
@media (max-width: 640px) {
  .krt-print-reminder { padding: 12px; }
  .krt-print-reminder .krt-btn { width: 100%; }
}

@media (max-width: 480px) {
  .krt-submitted { padding: 22px 18px 20px; border-radius: 16px; }
  .krt-submitted-icon { width: 56px; height: 56px; }
  .krt-submitted-title { font-size: 19px; }
  .krt-submitted-steps li { padding: 10px 12px; gap: 10px; }
  .krt-step-num { width: 26px; height: 26px; font-size: 13px; }
}

/* ═══ Прочее ═══ */
.krt-empty { text-align: center; color: #8C7B6E; padding: 32px 0; }
.krt-error { color: #E53935; padding: 12px 0; }

/* ═══ Мобилка ═══ */
@media (max-width: 640px) {
  .krt-wrap { padding: 14px 14px 130px; }
  .krt-header { gap: 12px; }
  .krt-title { font-size: 19px; }
  .krt-card-body { padding: 12px 14px; }
  .krt-card-arrow { padding: 0 10px; font-size: 18px; }
  .krt-card-date { font-size: 15px; }

  .krt-fld-row2 { grid-template-columns: 1fr 1fr; gap: 10px; }
  .krt-fld-row3 { grid-template-columns: 1fr; gap: 10px; }
  .krt-fld-row3 .krt-fld-bso-series,
  .krt-fld-row3 .krt-fld-bso-number {
    /* На мобилке — два БСО-поля в один ряд */
    grid-column: span 1;
  }
  .krt-fld-row3 {
    grid-template-areas: 'date date' 'series number';
    grid-template-columns: 1fr 1.4fr;
  }
  .krt-fld-row3 .krt-fld-date { grid-area: date; }
  .krt-fld-row3 .krt-fld-bso-series { grid-area: series; }
  .krt-fld-row3 .krt-fld-bso-number { grid-area: number; }
  .krt-section { padding: 14px 14px 12px; border-radius: 12px; }

  .krt-keg-row { grid-template-columns: 40px 1fr auto; gap: 10px; padding: 8px 6px; }
  .krt-keg-thumb { width: 40px; height: 40px; }
  .krt-step-btn { width: 38px; min-height: 38px; font-size: 19px; }
  .krt-step-input { width: 42px; min-height: 38px; font-size: 16px; }

  .krt-tip-bubble { right: 0; left: auto; }
  .krt-tip-bubble::before { left: auto; right: 8px; }

  .krt-actions-bar { margin: 12px -14px 0; padding: 10px 14px 14px; }
  .krt-actions-bar-inner { flex-direction: column; }
  .krt-actions-bar-inner .krt-btn { width: 100%; padding: 12px; font-size: 15px; }

  .krt-photo-modal img { max-width: 92vw; max-height: 70vh; }
}
</style>
