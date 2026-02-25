<template>
  <Teleport to="body">
    <div class="modal" @click.self="tryClose">
      <div class="modal-box">
        <div class="modal-header">
          <h2><BkIcon name="add" size="sm"/> Новый товар</h2>
          <button class="modal-close" @click="tryClose"><BkIcon name="close" size="sm"/></button>
        </div>

        <input v-model="form.sku" placeholder="Артикул" />
        <input v-model="form.name" placeholder="Наименование*" />

        <label>Поставщик
          <select v-model="form.supplier">
            <option value="">— Выберите поставщика —</option>
            <option v-for="s in suppliers" :key="s.name || s.short_name" :value="s.name || s.short_name">
              {{ s.name || s.short_name }}
            </option>
          </select>
        </label>

        <label>Юр. лицо
          <select v-model="form.legal_entity">
            <option value="Бургер БК">Бургер БК</option>
            <option value="Воглия Матта">Воглия Матта</option>
            <option value="Пицца Стар">Пицца Стар</option>
          </select>
        </label>

        <div class="modal-row-2">
          <div class="modal-field">
            <span class="modal-field-label">Штук в коробке</span>
            <input v-model.number="form.qty_per_box" type="number" placeholder="0" />
          </div>
          <div class="modal-field">
            <span class="modal-field-label">Коробок на паллете</span>
            <input v-model.number="form.boxes_per_pallet" type="number" placeholder="0" />
          </div>
        </div>

        <div class="modal-row-2">
          <div class="modal-field">
            <span class="modal-field-label">Кратность заказа</span>
            <input v-model.number="form.multiplicity" type="number" placeholder="0" title="Минимальная партия заказа. 0 = по коробкам" />
          </div>
          <div class="modal-field">
            <span class="modal-field-label">Единица измерения</span>
            <select v-model="form.unit_of_measure">
              <option value="шт">штуки</option>
              <option value="л">литры</option>
              <option value="кг">килограммы</option>
            </select>
          </div>
        </div>

        <div class="actions">
          <button class="btn primary" @click="submit" :disabled="saving">
            {{ saving ? 'Сохранение...' : 'Добавить' }}
          </button>
          <button class="btn secondary" @click="tryClose">Отмена</button>
        </div>
      </div>
    </div>

    <ConfirmModal
      v-if="showConfirmClose"
      title="Закрыть без сохранения?"
      message="Введённые данные будут потеряны."
      @confirm="$emit('close')"
      @cancel="showConfirmClose = false"
    />
  </Teleport>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { db } from '@/lib/apiClient.js';
import { useSupplierStore } from '@/stores/supplierStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';


const props = defineProps({
  legalEntity:     { type: String, default: 'Бургер БК' },
  currentSupplier: { type: String, default: '' },
});
const emit = defineEmits(['close', 'added']);

const supplierStore = useSupplierStore();
const toast         = useToastStore();
const saving        = ref(false);
const showConfirmClose = ref(false);
let   initialForm   = null;

const form = ref({
  sku: '',
  name: '',
  supplier: props.currentSupplier || '',
  legal_entity: props.legalEntity,
  qty_per_box: '',
  boxes_per_pallet: '',
  multiplicity: '',
  unit_of_measure: 'шт',
});

const suppliers = computed(() => supplierStore.getSuppliersForEntity(form.value.legal_entity));

function onKey(e) {
  if (e.key === 'Escape' && !showConfirmClose.value) tryClose();
}
onMounted(async () => {
  document.addEventListener('keydown', onKey);
  await supplierStore.loadSuppliers(form.value.legal_entity);
  initialForm = JSON.stringify(form.value);
});
onUnmounted(() => document.removeEventListener('keydown', onKey));

function isDirty() {
  return JSON.stringify(form.value) !== initialForm;
}

function tryClose() {
  if (isDirty()) { showConfirmClose.value = true; return; }
  emit('close');
}

async function submit() {
  if (!form.value.name) { toast.error('Введите наименование', ''); return; }
  if (!form.value.sku)  { toast.error('Введите артикул', ''); return; }
  if (!form.value.supplier) { toast.error('Выберите поставщика', ''); return; }
  if (!form.value.qty_per_box || form.value.qty_per_box <= 0) { toast.error('Введите штук в коробке', ''); return; }
  if (!form.value.boxes_per_pallet || form.value.boxes_per_pallet <= 0) { toast.error('Введите коробок на паллете', ''); return; }

  saving.value = true;
  try {
    const product = {
      name:            form.value.name,
      sku:             form.value.sku || null,
      supplier:        form.value.supplier || null,
      legal_entity:    form.value.legal_entity,
      qty_per_box:     +form.value.qty_per_box,
      boxes_per_pallet: +form.value.boxes_per_pallet,
      multiplicity:    +form.value.multiplicity || 0,
      unit_of_measure: form.value.unit_of_measure || 'шт',
    };

    const { data, error } = await db.from('products').insert(product).select().single();
    if (error) { toast.error('Ошибка сохранения', 'Не удалось сохранить товар'); console.error(error); return; }
    emit('added', data);
  } finally {
    saving.value = false;
  }
}
</script>
