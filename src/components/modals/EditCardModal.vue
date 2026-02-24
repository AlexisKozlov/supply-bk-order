<template>
  <Teleport to="body">
    <div class="modal" @click.self="tryClose">
      <div class="modal-box" style="width:480px;">
        <div class="modal-header">
          <h2><BkIcon v-if="product" name="edit" size="sm"/> <BkIcon v-else name="add" size="sm"/> {{ product ? 'Редактирование карточки' : 'Новый товар' }}</h2>
          <button class="modal-close" @click="tryClose"><BkIcon name="close" size="xs"/></button>
        </div>

        <div class="modal-row-2" style="grid-template-columns: 1fr 3fr;">
          <div class="modal-field">
            <span class="modal-field-label">Артикул SKU</span>
            <input v-model="form.sku" placeholder="SKU" />
          </div>
          <div class="modal-field">
            <span class="modal-field-label">Наименование*</span>
            <input v-model="form.name" placeholder="Название товара" />
          </div>
        </div>

        <div class="modal-row-2">
          <div class="modal-field">
            <span class="modal-field-label">Поставщик</span>
            <select v-model="form.supplier" @change="onSupplierChange">
              <option value="">— Выберите —</option>
              <option v-for="s in supplierOptions" :key="s" :value="s">{{ s }}</option>
              <option value="__new_supplier__">+ Новый поставщик</option>
            </select>
          </div>
          <div class="modal-field">
            <span class="modal-field-label">Юр. лицо</span>
            <select v-model="form.legal_entity">
              <option value="Бургер БК">Бургер БК</option>
              <option value="Воглия Матта">Воглия Матта</option>
              <option value="Пицца Стар">Пицца Стар</option>
            </select>
          </div>
        </div>

        <div class="modal-row-2">
          <div class="modal-field">
            <span class="modal-field-label">Штук в коробке*</span>
            <input v-model.number="form.qty_per_box" type="number" placeholder="0" />
          </div>
          <div class="modal-field">
            <span class="modal-field-label">Коробок на паллете</span>
            <input v-model.number="form.boxes_per_pallet" type="number" placeholder="0" />
          </div>
          <div class="modal-field">
            <span class="modal-field-label">Кратность</span>
            <input v-model.number="form.multiplicity" type="number" placeholder="0" title="0 = по коробкам" />
          </div>
          <div class="modal-field">
            <span class="modal-field-label">Ед. измерения</span>
            <select v-model="form.unit_of_measure">
              <option value="шт">штуки</option>
              <option value="л">литры</option>
              <option value="кг">килограммы</option>
            </select>
          </div>
        </div>

        <div class="actions" style="display:flex;gap:8px;margin-top:12px;">
          <button class="btn primary" @click="save" :disabled="saving">
            {{ saving ? 'Сохранение...' : (product ? 'Сохранить' : 'Создать') }}
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

    <!-- Вложенная модалка создания поставщика -->
    <EditSupplierModal
      v-if="showNewSupplier"
      :legalEntity="form.legal_entity"
      @close="closeNewSupplier"
      @saved="onSupplierCreated"
    />
  </Teleport>
</template>
<script setup>
import { ref, watch, onMounted } from 'vue';
import { db } from '@/lib/apiClient.js';
import { applyEntityFilter } from '@/lib/utils.js';
import { useToastStore } from '@/stores/toastStore.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';
import EditSupplierModal from '@/components/modals/EditSupplierModal.vue';


const props = defineProps({
  product: { type: Object, default: null },
  legalEntity: { type: String, default: 'Бургер БК' },
});
const emit = defineEmits(['close', 'saved']);
const toast = useToastStore();
const saving = ref(false);
const supplierOptions = ref([]);
const showNewSupplier = ref(false);
const showConfirmClose = ref(false);
const prevSupplier = ref('');
let initialized = false;
let initialForm = null;

const form = ref({
  sku: '', name: '', supplier: '', legal_entity: props.legalEntity,
  qty_per_box: '', boxes_per_pallet: '', multiplicity: '', unit_of_measure: 'шт',
});

onMounted(async () => {
  if (props.product?.id) {
    const { data } = await db.from('products').select('*').eq('id', props.product.id).single();
    if (data) {
      Object.assign(form.value, {
        sku: data.sku || '', name: data.name || '', supplier: data.supplier || '',
        legal_entity: data.legal_entity || props.legalEntity,
        qty_per_box: data.qty_per_box || '', boxes_per_pallet: data.boxes_per_pallet || '',
        multiplicity: data.multiplicity || '', unit_of_measure: data.unit_of_measure || 'шт',
      });
    }
  } else if (props.product) {
    Object.assign(form.value, {
      sku: props.product.sku || '', name: props.product.name || '',
      supplier: props.product.supplier || '', legal_entity: props.product.legal_entity || props.legalEntity,
      qty_per_box: props.product.qty_per_box || '', boxes_per_pallet: props.product.boxes_per_pallet || '',
      multiplicity: props.product.multiplicity || '', unit_of_measure: props.product.unit_of_measure || 'шт',
    });
  }
  await loadSuppliers();
  initialized = true;
  initialForm = JSON.stringify(form.value);
});

function isDirty() {
  return JSON.stringify(form.value) !== initialForm;
}

function tryClose() {
  if (isDirty()) { showConfirmClose.value = true; return; }
  emit('close');
}

async function loadSuppliers() {
  let query = db.from('suppliers').select('short_name').order('short_name');
  query = applyEntityFilter(query, form.value.legal_entity);
  const { data } = await query;
  supplierOptions.value = (data || []).map(s => s.short_name);
}

// Перезагрузить поставщиков при смене юр. лица (только после инициализации)
watch(() => form.value.legal_entity, () => {
  if (!initialized) return;
  form.value.supplier = '';
  loadSuppliers();
});

function onSupplierChange() {
  if (form.value.supplier === '__new_supplier__') {
    prevSupplier.value = '';
    form.value.supplier = '';
    showNewSupplier.value = true;
  }
}

function closeNewSupplier() {
  showNewSupplier.value = false;
  // Восстанавливаем предыдущее значение если не было создания
  if (!form.value.supplier) {
    form.value.supplier = prevSupplier.value;
  }
}

async function onSupplierCreated() {
  showNewSupplier.value = false;
  // Перезагружаем список поставщиков
  await loadSuppliers();
  // Выбираем последнего созданного (он будет последним по алфавиту или найдём новый)
  // Получим самого нового поставщика для текущего юр. лица
  let q = db.from('suppliers').select('short_name').order('created_at', { ascending: false }).limit(1);
  q = applyEntityFilter(q, form.value.legal_entity);
  const { data } = await q;
  if (data && data.length) {
    form.value.supplier = data[0].short_name;
  }
}

async function save() {
  if (!form.value.name) { toast.error('Введите наименование', ''); return; }
  if (!form.value.qty_per_box || form.value.qty_per_box <= 0) { toast.error('Введите штук в коробке', ''); return; }
  saving.value = true;
  try {
    const payload = {
      name: form.value.name, sku: form.value.sku || null, supplier: form.value.supplier || null,
      legal_entity: form.value.legal_entity, qty_per_box: Math.round(+form.value.qty_per_box) || null,
      boxes_per_pallet: Math.round(+form.value.boxes_per_pallet) || null, multiplicity: Math.round(+form.value.multiplicity) || 0,
      unit_of_measure: form.value.unit_of_measure || 'шт',
    };
    let error;
    if (props.product) { ({ error } = await db.from('products').update(payload).eq('id', props.product.id)); }
    else { ({ error } = await db.from('products').insert([payload])); }
    if (error) { toast.error('Ошибка', error.message || ''); return; }
    toast.success(props.product ? 'Обновлено' : 'Создано', '');
    emit('saved');
  } finally { saving.value = false; }
}
</script>
