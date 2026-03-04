<template>
  <Teleport to="body">
    <div class="modal" @click.self="tryClose">
      <div class="modal-box">
        <div class="modal-header">
          <h2><BkIcon v-if="supplier" name="edit" size="sm"/> <BkIcon v-else name="add" size="sm"/> {{ supplier ? 'Редактирование поставщика' : 'Новый поставщик' }}</h2>
          <button class="modal-close" @click="tryClose"><BkIcon name="close" size="sm"/></button>
        </div>

        <input v-model="form.short_name" placeholder="Краткое наименование*" />
        <input v-model="form.full_name" placeholder="Полное наименование" />

        <div style="margin-top:12px;font-size:13px;font-weight:600;color:#555;margin-bottom:6px;">Контакты</div>
        <div class="modal-row-2">
          <div class="modal-field">
            <span class="modal-field-label">WhatsApp</span>
            <input v-model="form.whatsapp" placeholder="+7..." />
          </div>
          <div class="modal-field">
            <span class="modal-field-label">Telegram</span>
            <input v-model="form.telegram" placeholder="@username или +7..." />
          </div>
        </div>
        <div class="modal-row-2">
          <div class="modal-field">
            <span class="modal-field-label">Viber</span>
            <input v-model="form.viber" placeholder="+7..." />
          </div>
          <div class="modal-field">
            <span class="modal-field-label">Email</span>
            <input v-model="form.email" placeholder="email@example.com; email2@..." />
            <span v-if="form.email && /[,;]/.test(form.email)" class="modal-field-hint">Несколько адресов через точку с запятой</span>
          </div>
        </div>

        <div style="margin-top:12px;font-size:13px;font-weight:600;color:#555;margin-bottom:6px;">Параметры доставки</div>
        <div class="modal-row-2">
          <div class="modal-field">
            <span class="modal-field-label">Срок доставки (дн.)</span>
            <input type="number" v-model.number="form.dlt" min="0" placeholder="напр. 2" />
            <span class="modal-field-hint">Через сколько дней приезжает товар</span>
          </div>
          <div class="modal-field">
            <span class="modal-field-label">Частота заказа (дн.)</span>
            <input type="number" v-model.number="form.doc" min="0" placeholder="напр. 7" />
            <span class="modal-field-hint">Как часто заказываете</span>
          </div>
        </div>

        <div class="actions" style="display:flex;gap:8px;margin-top:16px;">
          <button class="btn primary" @click="save" :disabled="saving">
            {{ saving ? 'Сохранение...' : (supplier ? 'Сохранить' : 'Создать') }}
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
import { ref, onMounted, onUnmounted } from 'vue';
import { db } from '@/lib/apiClient.js';
import { applyEntityFilter } from '@/lib/utils.js';
import { useToastStore } from '@/stores/toastStore.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useFormDirty } from '@/composables/useFormDirty.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';

const orderStore = useOrderStore();

const props = defineProps({
  supplier: { type: Object, default: null },
});
const emit = defineEmits(['close', 'saved']);
const toast = useToastStore();
const saving = ref(false);
const showConfirmClose = ref(false);
const oldShortName = ref('');

const form = ref({
  short_name: '', full_name: '', legal_entity: orderStore.settings.legalEntity || 'ООО "Бургер БК"',
  whatsapp: '', telegram: '', viber: '', email: '',
  dlt: null, doc: null,
});
const { saveSnapshot, isDirty } = useFormDirty(form);

function onKey(e) {
  if (e.key === 'Escape' && !showConfirmClose.value) tryClose();
}
onMounted(() => {
  document.addEventListener('keydown', onKey);
  if (props.supplier) {
    Object.assign(form.value, {
      short_name: props.supplier.short_name || '',
      full_name: props.supplier.full_name || '',
      legal_entity: props.supplier.legal_entity || form.value.legal_entity,
      whatsapp: props.supplier.whatsapp || '',
      telegram: props.supplier.telegram || '',
      viber: props.supplier.viber || '',
      email: props.supplier.email || '',
      dlt: props.supplier.dlt ?? null,
      doc: props.supplier.doc ?? null,
    });
    oldShortName.value = props.supplier.short_name || '';
  }
  saveSnapshot();
});
onUnmounted(() => document.removeEventListener('keydown', onKey));

function tryClose() {
  if (isDirty()) { showConfirmClose.value = true; return; }
  emit('close');
}

async function save() {
  if (!form.value.short_name.trim()) { toast.error('Введите краткое наименование', ''); return; }
  saving.value = true;
  try {
    const payload = {
      short_name: form.value.short_name.trim(),
      full_name: form.value.full_name.trim() || null,
      legal_entity: form.value.legal_entity,
      whatsapp: form.value.whatsapp.trim() || null,
      telegram: form.value.telegram.trim() || null,
      viber: form.value.viber.trim() || null,
      email: form.value.email.trim() || null,
      dlt: form.value.dlt || null,
      doc: form.value.doc || null,
    };
    let error;
    if (props.supplier) {
      ({ error } = await db.from('suppliers').update(payload).eq('id', props.supplier.id));
      // Переименование — обновляем products.supplier
      if (!error && oldShortName.value && oldShortName.value !== payload.short_name) {
        let renameQuery = db.from('products').update({ supplier: payload.short_name }).eq('supplier', oldShortName.value);
        renameQuery = applyEntityFilter(renameQuery, payload.legal_entity);
        await renameQuery;
        toast.info('Связи обновлены', `${oldShortName.value} → ${payload.short_name}`);
      }
    } else {
      ({ error } = await db.from('suppliers').insert([payload]));
    }
    if (error) {
      const msg = error.message?.includes('unique') ? 'Поставщик с таким именем уже существует' : (error.message || 'Ошибка');
      toast.error('Ошибка сохранения', msg); return;
    }
    toast.success(props.supplier ? 'Поставщик обновлён' : 'Поставщик создан', payload.short_name);
    emit('saved');
  } finally { saving.value = false; }
}
</script>
