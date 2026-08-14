<template>
  <div class="tlv">
    <div class="tlv-head">
      <h2 class="tlv-title">Типы машин</h2>
      <p class="tlv-hint">Из этого списка машины добавляются в конструктор. Вместимость нужна, чтобы портал считал загрузку.</p>
    </div>

    <div v-if="loading" class="tlv-state"><BurgerSpinner text="Загрузка..." /></div>
    <div v-else-if="!rows.length && !showNew" class="tlv-state">
      Типов машин пока нет.
      <button type="button" class="tlv-btn tlv-btn-primary" @click="startNew">Добавить первый</button>
    </div>

    <div v-else class="tlv-list">
      <div class="tlv-row tlv-row-head">
        <span>Название</span>
        <span>Паллет</span>
        <span>Грузоподъёмность, кг</span>
        <span></span>
      </div>

      <div v-for="v in rows" :key="v.id" class="tlv-row" :class="{ 'is-changed': isChanged(v) }">
        <input v-model="v.name" class="tlv-input" placeholder="Название">
        <input v-model.number="v.capacity_pallets" type="number" min="1" class="tlv-input tlv-input-num">
        <input v-model.number="v.capacity_kg" type="number" min="1" class="tlv-input tlv-input-num">
        <span class="tlv-actions">
          <!-- Кнопка появляется только когда есть что сохранять: раньше она
               висела у каждой строки и путалась с «Удалить» -->
          <button v-if="isChanged(v)" type="button" class="tlv-btn tlv-btn-primary" @click="$emit('save', v)">Сохранить</button>
          <button type="button" class="tlv-btn tlv-btn-ghost" title="Удалить тип машины" @click="$emit('delete', v)">Удалить</button>
        </span>
      </div>

      <div v-if="showNew" class="tlv-row is-new">
        <input v-model="draft.name" class="tlv-input" placeholder="Например, Фура 20 паллет" ref="newNameRef">
        <input v-model.number="draft.capacity_pallets" type="number" min="1" class="tlv-input tlv-input-num">
        <input v-model.number="draft.capacity_kg" type="number" min="1" class="tlv-input tlv-input-num">
        <span class="tlv-actions">
          <button type="button" class="tlv-btn tlv-btn-primary" :disabled="!draft.name.trim()" @click="create">Добавить</button>
          <button type="button" class="tlv-btn tlv-btn-ghost" @click="showNew = false">Отмена</button>
        </span>
      </div>
    </div>

    <button v-if="!showNew && rows.length" type="button" class="tlv-add" @click="startNew">+ Добавить тип машины</button>
  </div>
</template>

<script setup>
import { ref, computed, nextTick, watch } from 'vue';

const props = defineProps({
  vehicles: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
});

const emit = defineEmits(['save', 'delete', 'create']);

// Правим копии, чтобы «Сохранить» появлялась только при реальных изменениях
const rows = ref([]);
const original = ref({});

watch(() => props.vehicles, (list) => {
  rows.value = (list || []).map(v => ({ ...v }));
  original.value = Object.fromEntries((list || []).map(v => [v.id, JSON.stringify(pick(v))]));
}, { immediate: true, deep: false });

function pick(v) {
  return { name: v.name, capacity_pallets: +v.capacity_pallets, capacity_kg: +v.capacity_kg };
}
function isChanged(v) {
  return original.value[v.id] !== JSON.stringify(pick(v));
}

const showNew = ref(false);
const newNameRef = ref(null);
const draft = ref({ name: '', capacity_pallets: 20, capacity_kg: 10000 });

function startNew() {
  draft.value = { name: '', capacity_pallets: 20, capacity_kg: 10000 };
  showNew.value = true;
  nextTick(() => newNameRef.value?.focus());
}

function create() {
  emit('create', { ...draft.value });
  showNew.value = false;
}

// Родителю нужно знать, есть ли незаписанные правки
const hasUnsaved = computed(() => rows.value.some(isChanged) || showNew.value);
defineExpose({ hasUnsaved });
</script>

<style scoped>
.tlv { max-width: 860px; }
.tlv-head { margin-bottom: 12px; }
.tlv-title { margin: 0 0 2px; font-size: 15px; color: #502314; }
.tlv-hint { margin: 0; font-size: 12px; color: #8b7355; }

.tlv-state { padding: 28px; text-align: center; color: #8b7355; font-size: 13px; display: flex; flex-direction: column; align-items: center; gap: 10px; }

.tlv-list { display: flex; flex-direction: column; border: 1px solid #e6ddd2; border-radius: 8px; overflow: hidden; background: #fff; }
.tlv-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 90px 150px auto;
  gap: 10px;
  align-items: center;
  padding: 8px 10px;
  border-top: 1px solid #f0eae3;
}
.tlv-row:first-child { border-top: none; }
.tlv-row-head { background: #faf7f3; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #8b7355; font-weight: 600; }
.tlv-row.is-changed { background: #fffaf5; }
.tlv-row.is-new { background: #f7fbf7; }

.tlv-input { width: 100%; box-sizing: border-box; padding: 6px 8px; border: 1px solid #e0d5c8; border-radius: 6px; font-size: 13px; font-family: inherit; }
.tlv-input:focus { outline: none; border-color: #E76F51; box-shadow: 0 0 0 2px rgba(231,111,81,0.15); }
.tlv-input-num { text-align: right; font-variant-numeric: tabular-nums; }

.tlv-actions { display: flex; gap: 6px; justify-content: flex-end; }
.tlv-btn { padding: 5px 11px; border-radius: 6px; font-size: 12px; cursor: pointer; border: 1px solid #e0d5c8; background: #fff; white-space: nowrap; }
.tlv-btn:hover { background: #faf6f1; }
.tlv-btn-primary { background: #E76F51; border-color: #E76F51; color: #fff; }
.tlv-btn-primary:hover { background: #d45f42; }
.tlv-btn-primary:disabled { opacity: 0.5; cursor: default; }
.tlv-btn-ghost { color: #8b7355; }
.tlv-btn-ghost:hover { color: #c62828; border-color: #f0c4c4; background: #fdf2f2; }

.tlv-add { margin-top: 10px; padding: 8px 14px; border: 1px dashed #ddd0c2; border-radius: 8px; background: #fff; color: #8b7355; font-size: 13px; cursor: pointer; }
.tlv-add:hover { border-color: #E76F51; color: #E76F51; }

@media (max-width: 700px) {
  .tlv-row-head { display: none; }
  .tlv-row { grid-template-columns: minmax(0, 1fr) 1fr; gap: 6px; padding: 10px; }
  .tlv-actions { grid-column: 1 / -1; justify-content: flex-start; }
  /* 16px — иначе телефон зумит страницу при попадании в поле */
  .tlv-input { font-size: 16px; }
}
</style>
