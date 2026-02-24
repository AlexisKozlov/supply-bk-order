<template>
  <Teleport to="body">
    <div class="modal" @click.self="$emit('close')">
      <div class="modal-box">
        <div class="modal-header">
          <h2><BkIcon v-if="supplier" name="edit" size="sm"/> <BkIcon v-else name="add" size="sm"/> {{ supplier ? 'Редактирование поставщика' : 'Новый поставщик' }}</h2>
          <button class="modal-close" @click="$emit('close')"><BkIcon name="close" size="xs"/></button>
        </div>

        <input v-model="form.short_name" placeholder="Краткое наименование*" />
        <input v-model="form.full_name" placeholder="Полное наименование" />

        <label>Юр. лицо
          <select v-model="form.legal_entity">
            <option value="Бургер БК">Бургер БК</option>
            <option value="Воглия Матта">Воглия Матта</option>
            <option value="Пицца Стар">Пицца Стар</option>
          </select>
        </label>

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
            <input v-model="form.email" placeholder="email@example.com" />
          </div>
        </div>

        <div class="actions" style="display:flex;gap:8px;margin-top:16px;">
          <button class="btn primary" @click="save" :disabled="saving">
            {{ saving ? 'Сохранение...' : (supplier ? 'Сохранить' : 'Создать') }}
          </button>
          <button class="btn secondary" @click="$emit('close')">Отмена</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { db } from '@/lib/apiClient.js';
import { useToastStore } from '@/stores/toastStore.js';
import BkIcon from '@/components/ui/BkIcon.vue';


const props = defineProps({
  supplier: { type: Object, default: null },
  legalEntity: { type: String, default: 'Бургер БК' },
});
const emit = defineEmits(['close', 'saved']);
const toast = useToastStore();
const saving = ref(false);
const oldShortName = ref('');

const form = ref({
  short_name: '', full_name: '', legal_entity: props.legalEntity,
  whatsapp: '', telegram: '', viber: '', email: '',
});

onMounted(() => {
  if (props.supplier) {
    Object.assign(form.value, {
      short_name: props.supplier.short_name || '',
      full_name: props.supplier.full_name || '',
      legal_entity: props.supplier.legal_entity || props.legalEntity,
      whatsapp: props.supplier.whatsapp || '',
      telegram: props.supplier.telegram || '',
      viber: props.supplier.viber || '',
      email: props.supplier.email || '',
    });
    oldShortName.value = props.supplier.short_name || '';
  }
});

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
    };
    let error;
    if (props.supplier) {
      ({ error } = await db.from('suppliers').update(payload).eq('id', props.supplier.id));
      // Переименование — обновляем products.supplier
      if (!error && oldShortName.value && oldShortName.value !== payload.short_name) {
        await db.from('products').update({ supplier: payload.short_name }).eq('supplier', oldShortName.value);
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
