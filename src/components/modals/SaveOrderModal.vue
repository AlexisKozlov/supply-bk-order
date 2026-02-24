<template>
  <Teleport to="body">
    <div class="modal" @click.self="$emit('cancel')">
      <div class="modal-box save-modal">
        <div class="modal-header">
          <h2><BkIcon name="save" size="sm"/> {{ isEditing ? 'Обновить заказ' : 'Сохранить заказ' }}</h2>
          <button class="modal-close" @click="$emit('cancel')"><BkIcon name="close" size="xs"/></button>
        </div>

        <!-- Summary cards -->
        <div class="sm-cards">
          <div class="sm-card">
            <span class="sm-card-label">Юр. лицо</span>
            <span class="sm-card-value">{{ legalEntity || '—' }}</span>
          </div>
          <div class="sm-card">
            <span class="sm-card-label">Поставщик</span>
            <span class="sm-card-value">{{ supplier || 'Свободный' }}</span>
          </div>
          <div class="sm-card">
            <span class="sm-card-label">Дата поставки</span>
            <span class="sm-card-value">{{ deliveryDateStr }}</span>
          </div>
          <div class="sm-card sm-card-accent">
            <span class="sm-card-label">Позиций</span>
            <span class="sm-card-value">{{ lines.length }}</span>
          </div>
        </div>

        <!-- Items table -->
        <div class="sm-table-wrap">
          <table class="sm-table">
            <thead>
              <tr><th>#</th><th>Артикул</th><th>Наименование</th><th>Коробки</th><th>Штуки</th></tr>
            </thead>
            <tbody>
              <tr v-for="(l, idx) in lines" :key="idx">
                <td class="sm-num">{{ idx + 1 }}</td>
                <td class="sm-sku">{{ l.sku || '—' }}</td>
                <td class="sm-name">{{ l.name }}</td>
                <td class="sm-qty">{{ l.boxes }}</td>
                <td class="sm-qty">{{ nf.format(l.pieces) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Note -->
        <div class="sm-note-section">
          <label>Примечание</label>
          <input v-model="note" type="text" placeholder="Например: срочный заказ, акция..."
            ref="noteInput" @keydown.enter="doConfirm" @keydown.esc="$emit('cancel')"/>
        </div>

        <!-- Actions -->
        <div class="sm-actions">
          <button class="btn primary" @click="doConfirm" :disabled="saving">
            <BkIcon name="save" size="sm"/> {{ saving ? 'Сохранение...' : (isEditing ? 'Обновить заказ' : 'Сохранить заказ') }}
          </button>
          <button class="btn secondary" @click="$emit('cancel')" :disabled="saving">Отмена</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import BkIcon from '@/components/ui/BkIcon.vue';

const props = defineProps({
  supplier:       { type: String,  default: '' },
  deliveryDate:   { default: null },
  legalEntity:    { type: String,  default: '' },
  itemsCount:     { type: Number,  default: 0 },
  lines:          { type: Array,   default: () => [] },
  editingOrderId: { default: null },
  existingNote:   { type: String,  default: '' },
  saving:         { type: Boolean, default: false },
});
const emit = defineEmits(['confirm', 'cancel']);
const note = ref(props.existingNote || '');
const noteInput = ref(null);
const nf = new Intl.NumberFormat('ru-RU');
const isEditing = computed(() => !!props.editingOrderId);
const deliveryDateStr = computed(() => {
  if (!props.deliveryDate) return '—';
  return new Date(props.deliveryDate).toLocaleDateString('ru-RU');
});
onMounted(() => setTimeout(() => noteInput.value?.focus(), 50));
function doConfirm() { emit('confirm', note.value.trim()); }
</script>

<style scoped>
.save-modal { max-width: 600px; }

.sm-cards {
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;
  margin-bottom: 14px;
}
.sm-card {
  padding: 8px 10px; border-radius: 8px;
  background: var(--bg); border: 1px solid var(--border-light);
}
.sm-card-accent {
  background: #FFF3E0; border-color: #FFCC80;
}
.sm-card-label {
  display: block; font-size: 9px; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.3px;
  color: var(--text-muted); margin-bottom: 2px;
}
.sm-card-value {
  font-size: 13px; font-weight: 700; color: var(--text);
}
.sm-card-accent .sm-card-value { color: #BF360C; }

.sm-table-wrap {
  max-height: 280px; overflow-y: auto;
  border: 1px solid var(--border); border-radius: 8px;
  margin-bottom: 14px;
}
.sm-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.sm-table thead th {
  padding: 6px 8px; text-align: left; font-size: 10px; font-weight: 700;
  text-transform: uppercase; color: var(--text-muted);
  background: var(--bg); border-bottom: 1px solid var(--border);
  position: sticky; top: 0;
}
.sm-table tbody td { padding: 4px 8px; border-bottom: 1px solid var(--border-light); }
.sm-table tbody tr:last-child td { border-bottom: none; }
.sm-num { width: 28px; color: var(--text-muted); font-size: 11px; }
.sm-sku { width: 70px; font-weight: 600; color: var(--text-muted); font-size: 11px; }
.sm-name { color: var(--text); }
.sm-qty { width: 60px; text-align: right; font-weight: 700; color: var(--bk-brown); }

.sm-note-section { margin-bottom: 14px; }
.sm-note-section label {
  display: block; margin-bottom: 4px;
  font-size: 12px; font-weight: 600; color: var(--text-secondary);
}
.sm-note-section input {
  width: 100%; padding: 7px 10px;
  border: 1px solid var(--border); border-radius: 6px;
  font-size: 13px; font-family: inherit;
}
.sm-note-section input:focus { border-color: var(--bk-orange); outline: none; }

.sm-actions { display: flex; gap: 8px; }
</style>
