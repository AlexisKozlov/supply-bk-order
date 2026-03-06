<template>
  <Teleport to="body">
    <div class="modal" @click.self="tryClose">
      <div class="modal-box" style="width:480px;">
        <div class="modal-header">
          <h2><BkIcon v-if="product" name="edit" size="sm"/> <BkIcon v-else name="add" size="sm"/> {{ product ? 'Редактирование карточки' : 'Новый товар' }}</h2>
          <div style="display:flex;align-items:center;gap:6px;margin-left:auto;">
            <button v-if="product" class="btn secondary" style="font-size:11px;padding:4px 10px;" @click="showAuditLog = true"><BkIcon name="note" size="xs"/> История</button>
            <button class="modal-close" @click="tryClose"><BkIcon name="close" size="sm"/></button>
          </div>
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
            <span class="modal-field-label">Ед. измерения</span>
            <select v-model="form.unit_of_measure">
              <option value="шт">штуки</option>
              <option value="л">литры</option>
              <option value="кг">килограммы</option>
            </select>
          </div>
          <div class="modal-field">
            <span class="modal-field-label">Хранение</span>
            <select v-model="form.category">
              <option value="">Не указан</option>
              <option value="Сухой">Сухой</option>
              <option value="Холод">Холод</option>
              <option value="Мороз">Мороз</option>
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
          <div class="modal-field" style="width:100px;flex-shrink:0;">
            <span class="modal-field-label">Видимость</span>
            <select v-model="form.is_active">
              <option :value="true">Да</option>
              <option :value="false">Нет</option>
            </select>
          </div>
        </div>

        <div class="modal-field" style="margin-top:4px;">
          <span class="modal-field-label">Группа аналогов</span>
          <input v-model="form.analog_group" placeholder="Название группы" />
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
      @close="closeNewSupplier"
      @saved="onSupplierCreated"
    />

    <!-- История изменений карточки -->
    <AuditLogModal
      v-if="showAuditLog"
      :show="showAuditLog"
      :loading="auditLoading"
      :entries="auditEntries"
      @close="showAuditLog = false"
    />
  </Teleport>
</template>
<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue';
import { db } from '@/lib/apiClient.js';
import { applyEntityFilter } from '@/lib/utils.js';
import { useToastStore } from '@/stores/toastStore.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { useFormDirty } from '@/composables/useFormDirty.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';
import EditSupplierModal from '@/components/modals/EditSupplierModal.vue';
import AuditLogModal from '@/components/modals/AuditLogModal.vue';

const orderStore = useOrderStore();
const userStore = useUserStore();

const props = defineProps({
  product: { type: Object, default: null },
});
const emit = defineEmits(['close', 'saved']);
const toast = useToastStore();
const saving = ref(false);
const supplierOptions = ref([]);
const showNewSupplier = ref(false);
const showConfirmClose = ref(false);
const prevSupplier = ref('');
const showAuditLog = ref(false);
const auditLoading = ref(false);
const auditEntries = ref([]);
const originalValues = ref(null);

const form = ref({
  sku: '', name: '', supplier: '', legal_entity: orderStore.settings.legalEntity || 'ООО "Бургер БК"',
  qty_per_box: '', boxes_per_pallet: '', multiplicity: '', unit_of_measure: 'шт',
  analog_group: '', is_active: true, category: '',
});
const { saveSnapshot, isDirty } = useFormDirty(form);

function onKey(e) {
  if (e.key === 'Escape' && !showConfirmClose.value && !showNewSupplier.value && !showAuditLog.value) tryClose();
}
onMounted(async () => {
  document.addEventListener('keydown', onKey);
  if (props.product?.id) {
    const { data } = await db.from('products').select('*').eq('id', props.product.id).single();
    if (data) {
      Object.assign(form.value, {
        sku: data.sku || '', name: data.name || '', supplier: data.supplier || '',
        legal_entity: data.legal_entity || form.value.legal_entity,
        qty_per_box: data.qty_per_box || '', boxes_per_pallet: data.boxes_per_pallet || '',
        multiplicity: data.multiplicity || '', unit_of_measure: data.unit_of_measure || 'шт',
        analog_group: data.analog_group || '',
        is_active: data.is_active !== undefined ? !!data.is_active : true,
        category: data.category || '',
      });
    }
  } else if (props.product) {
    Object.assign(form.value, {
      sku: props.product.sku || '', name: props.product.name || '',
      supplier: props.product.supplier || '', legal_entity: props.product.legal_entity || form.value.legal_entity,
      qty_per_box: props.product.qty_per_box || '', boxes_per_pallet: props.product.boxes_per_pallet || '',
      multiplicity: props.product.multiplicity || '', unit_of_measure: props.product.unit_of_measure || 'шт',
      analog_group: props.product.analog_group || '',
      is_active: props.product.is_active !== undefined ? !!props.product.is_active : true,
      category: props.product.category || '',
    });
  }
  await loadSuppliers();
  // Сохраняем снимок для отслеживания изменений (аудит)
  originalValues.value = { ...form.value };
  saveSnapshot();
});
onUnmounted(() => document.removeEventListener('keydown', onKey));

// Загрузка истории изменений карточки
watch(showAuditLog, async (val) => {
  if (!val || !props.product?.id) return;
  auditLoading.value = true;
  try {
    const { data } = await db.from('audit_log')
      .select('*')
      .eq('entity_type', 'product')
      .eq('entity_id', String(props.product.id))
      .order('created_at', { ascending: false })
      .limit(50);
    auditEntries.value = data || [];
  } finally { auditLoading.value = false; }
});

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

let _lastRealSupplier = '';
watch(() => form.value.supplier, (newVal, oldVal) => {
  if (oldVal && oldVal !== '__new_supplier__') _lastRealSupplier = oldVal;
});
function onSupplierChange() {
  if (form.value.supplier === '__new_supplier__') {
    prevSupplier.value = _lastRealSupplier;
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

// Подписи полей для аудит-лога
const FIELD_LABELS = {
  name: 'Наименование', sku: 'Артикул', supplier: 'Поставщик',
  qty_per_box: 'Штук в коробке', boxes_per_pallet: 'Коробок на паллете',
  multiplicity: 'Кратность', unit_of_measure: 'Ед. измерения',
  analog_group: 'Группа аналогов', is_active: 'Видимость', category: 'Хранение',
};

async function save() {
  if (saving.value) return;
  if (!form.value.name) { toast.error('Введите наименование', ''); return; }
  if (!form.value.qty_per_box || form.value.qty_per_box <= 0) { toast.error('Введите штук в коробке', ''); return; }
  saving.value = true;
  try {
    const payload = {
      name: form.value.name, sku: form.value.sku || null, supplier: form.value.supplier || null,
      legal_entity: form.value.legal_entity, qty_per_box: +form.value.qty_per_box || null,
      boxes_per_pallet: Math.round(+form.value.boxes_per_pallet) || null, multiplicity: Math.max(1, Math.round(+form.value.multiplicity) || 1),
      unit_of_measure: form.value.unit_of_measure || 'шт',
      analog_group: form.value.analog_group || null,
      is_active: form.value.is_active ? 1 : 0,
      category: form.value.category || null,
    };
    let error, insertedId;
    if (props.product) {
      ({ error } = await db.from('products').update(payload).eq('id', props.product.id));
    } else {
      const res = await db.from('products').insert([payload]);
      error = res.error;
      insertedId = res.data?.[0]?.id || res.data?.id;
    }
    if (error) { toast.error('Ошибка', error.message || ''); return; }

    // Аудит-лог
    try {
      const isEdit = !!props.product;
      const entityId = isEdit ? props.product.id : insertedId;
      const param_changes = [];
      const old = originalValues.value || {};
      const cur = form.value;
      for (const key of Object.keys(FIELD_LABELS)) {
        const oldVal = String(old[key] ?? '');
        let curVal = String(cur[key] ?? '');
        if (key === 'is_active') { curVal = cur[key] ? 'Да' : 'Нет'; }
        const oldDisplay = key === 'is_active' ? (old[key] ? 'Да' : 'Нет') : oldVal;
        if (isEdit && oldDisplay !== curVal) {
          param_changes.push({ label: FIELD_LABELS[key], from: oldDisplay || '—', to: curVal || '—' });
        }
      }
      // Пишем запись только если есть изменения (для update) или это создание
      if (!isEdit || param_changes.length) {
        await db.from('audit_log').insert([{
          action: isEdit ? 'product_updated' : 'product_created',
          entity_type: 'product',
          entity_id: entityId ? String(entityId) : null,
          user_name: userStore.currentUser?.name || null,
          details: JSON.stringify(isEdit ? { param_changes } : { name: cur.name, sku: cur.sku }),
        }]);
      }
    } catch (_) { /* аудит не должен блокировать сохранение */ }

    toast.success(props.product ? 'Обновлено' : 'Создано', '');
    emit('saved');
  } finally { saving.value = false; }
}
</script>
