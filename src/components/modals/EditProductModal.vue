<template>
  <Teleport to="body">
    <div class="modal" @click.self="tryClose">
      <div class="modal-box">
        <div class="modal-header">
          <h2><BkIcon v-if="mode === 'create'" name="add" size="sm"/> <BkIcon v-else name="edit" size="sm"/> {{ mode === 'create' ? 'Новый товар' : 'Редактирование карточки' }}</h2>
          <button class="modal-close" @click="tryClose"><BkIcon name="close" size="sm"/></button>
        </div>

        <input v-model="form.sku" placeholder="Артикул" />
        <input v-model="form.name" placeholder="Наименование*" />

        <label>Поставщик
          <select v-model="form.supplier" @change="onSupplierSelectChange">
            <option value="">— Выберите поставщика —</option>
            <option v-for="s in supplierOptions" :key="s" :value="s">{{ s }}</option>
            <option value="__new__" style="color:#c77800;">＋ Новый поставщик...</option>
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
            <input v-model.number="form.multiplicity" type="number" placeholder="0" title="0 = по коробкам" />
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
            {{ saving ? 'Сохранение...' : (mode === 'create' ? 'Создать' : 'Сохранить') }}
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
import { ref, watch, onMounted, onUnmounted } from 'vue';
import { db } from '@/lib/apiClient.js';
import { applyEntityFilter } from '@/lib/utils.js';
import { useToastStore } from '@/stores/toastStore.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';


const props = defineProps({
  productId:   { type: [String, Number], default: null }, // null = create
  sku:         { type: String, default: null },            // альтернатива id
  legalEntity: { type: String, default: 'Бургер БК' },
});

const emit = defineEmits(['close', 'saved']);

const toast  = useToastStore();
const saving = ref(false);
const mode   = ref(props.productId || props.sku ? 'edit' : 'create');
let   editId = null;
let   initialized = false;
let   initialForm = null;
const showConfirmClose = ref(false);

const supplierOptions = ref([]);

const form = ref({
  name: '', sku: '', supplier: '',
  legal_entity: props.legalEntity,
  qty_per_box: '', boxes_per_pallet: '',
  multiplicity: '', unit_of_measure: 'шт',
});

function onKey(e) {
  if (e.key === 'Escape' && !showConfirmClose.value) tryClose();
}
onMounted(async () => {
  document.addEventListener('keydown', onKey);
  if (props.productId || props.sku) {
    await loadProduct();
  }
  await loadSupplierOptions();
  initialized = true;
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

async function loadSupplierOptions() {
  let query = db.from('suppliers').select('short_name').order('short_name');
  query = applyEntityFilter(query, form.value.legal_entity);
  const { data } = await query;
  supplierOptions.value = (data || []).map(s => s.short_name);
}

// Перезагрузить поставщиков при смене юр. лица (только после инициализации)
watch(() => form.value.legal_entity, () => {
  if (!initialized) return;
  form.value.supplier = '';
  loadSupplierOptions();
});

async function loadProduct() {
  let query = db.from('products').select('*');
  if (props.productId) query = query.eq('id', props.productId);
  else if (props.sku)  query = query.eq('sku', props.sku);
  const { data, error } = await query.maybeSingle();
  if (error || !data) { toast.error('Ошибка', 'Не удалось загрузить карточку'); return; }
  editId = data.id;
  form.value = {
    name:             data.name || '',
    sku:              data.sku  || '',
    supplier:         data.supplier || '',
    legal_entity:     data.legal_entity || props.legalEntity,
    qty_per_box:      data.qty_per_box ?? '',
    boxes_per_pallet: data.boxes_per_pallet ?? '',
    multiplicity:     data.multiplicity ?? '',
    unit_of_measure:  data.unit_of_measure || 'шт',
  };
}

function onSupplierSelectChange() {
  if (form.value.supplier === '__new__') {
    form.value.supplier = '';
    // TODO: открыть форму нового поставщика (реализуется в DatabaseView)
    toast.info('Подсказка', 'Создайте поставщика в разделе «База данных»');
  }
}

async function submit() {
  if (!form.value.name.trim()) { toast.error('Введите наименование', ''); return; }

  const productData = {
    name:             form.value.name.trim(),
    sku:              form.value.sku  || null,
    supplier:         form.value.supplier && form.value.supplier !== '__new__' ? form.value.supplier : null,
    legal_entity:     form.value.legal_entity,
    qty_per_box:      Math.round(+form.value.qty_per_box) || null,
    boxes_per_pallet: Math.round(+form.value.boxes_per_pallet) || null,
    multiplicity:     Math.round(+form.value.multiplicity) || 0,
    unit_of_measure:  form.value.unit_of_measure,
  };

  saving.value = true;
  try {
    let error, data;
    if (mode.value === 'create') {
      ({ data, error } = await db.from('products').insert([productData]).select().single());
    } else {
      ({ data, error } = await db.from('products').update(productData).eq('id', editId).select().single());
    }
    if (error) { toast.error('Ошибка сохранения', error.message); return; }
    toast.success(mode.value === 'create' ? 'Товар создан' : 'Карточка обновлена', '');
    emit('saved', data);
    emit('close');
  } finally {
    saving.value = false;
  }
}
</script>
