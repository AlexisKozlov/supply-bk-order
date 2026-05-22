<template>
  <div class="scem-backdrop" @click.self="close">
    <div class="scem-modal">
      <div class="scem-head">
        <div>
          <h3 class="scem-title">{{ isEdit ? 'Редактировать контакт' : 'Новый контакт' }}</h3>
          <div class="scem-sub">Ресторан {{ formatRestaurantNumber(restaurant.number, restaurant.legal_entity_group) }} · {{ scopeTitle }}</div>
        </div>
        <button class="scem-x" type="button" @click="close" aria-label="Закрыть">×</button>
      </div>

      <div class="scem-body">
        <div v-if="error" class="scem-error">{{ error }}</div>

        <label class="scem-field">
          <span>Имя</span>
          <input v-model="form.name" type="text" maxlength="100" placeholder="Например: Катя" />
        </label>

        <label class="scem-field">
          <span>Должность / роль</span>
          <input v-model="form.role" type="text" maxlength="60" list="scem-role-suggest" placeholder="торгпред, диспетчер, бухгалтер..." />
          <datalist id="scem-role-suggest">
            <option value="Торговый представитель"></option>
            <option value="Менеджер"></option>
            <option value="Диспетчер"></option>
            <option value="Логист"></option>
            <option value="Бухгалтер"></option>
            <option value="Кладовщик"></option>
            <option value="Руководитель"></option>
          </datalist>
        </label>

        <div class="scem-field">
          <span>Телефоны <small class="scem-hint">(до 5)</small></span>
          <div v-for="(p, i) in form.phones" :key="'p'+i" class="scem-phone-row">
            <input v-model="p.phone" type="tel" maxlength="30" placeholder="+375 29 ..." class="scem-phone-number" />
            <input v-model="p.label" type="text" maxlength="30" placeholder="Подпись (личный, рабочий...)" list="scem-phone-labels" class="scem-phone-label" />
            <button type="button" class="scem-phone-del" @click="removePhone(i)" :disabled="form.phones.length === 1" title="Убрать">×</button>
          </div>
          <button type="button" class="scem-phone-add" @click="addPhone" v-if="form.phones.length < 5">+ ещё телефон</button>
          <datalist id="scem-phone-labels">
            <option value="Личный"></option>
            <option value="Рабочий"></option>
            <option value="Офис"></option>
            <option value="Директор"></option>
            <option value="Бухгалтер"></option>
            <option value="Магазин"></option>
            <option value="Склад"></option>
          </datalist>
        </div>

        <label class="scem-field">
          <span>Email</span>
          <input v-model="form.email" type="email" maxlength="100" placeholder="katya@example.com" />
        </label>

        <div class="scem-row">
          <label class="scem-field">
            <span>Telegram</span>
            <input v-model="form.telegram" type="text" maxlength="60" placeholder="@username или +375..." />
            <small class="scem-hint">Если username нет — введите номер телефона с +.</small>
          </label>
          <label class="scem-field">
            <span>WhatsApp</span>
            <input v-model="form.whatsapp" type="tel" maxlength="30" placeholder="+375 29 ..." />
          </label>
        </div>

        <div class="scem-row">
          <label class="scem-field">
            <span>Viber</span>
            <input v-model="form.viber" type="tel" maxlength="30" placeholder="+375 29 ..." />
          </label>
          <label class="scem-field">
            <span class="scem-field-inline">
              <input v-model="form.is_primary" type="checkbox" />
              <span>Основной контакт (★)</span>
            </span>
            <small class="scem-hint">Будет показан первым; у остальных «звёздочка» сбросится.</small>
          </label>
        </div>

        <label class="scem-field">
          <span>Теги</span>
          <div class="scem-tags-wrap">
            <span v-for="(t, i) in form.tags" :key="i" class="scem-tag">
              {{ t }}
              <button type="button" class="scem-tag-x" @click="removeTag(i)" aria-label="Убрать">×</button>
            </span>
            <input
              v-model="tagInput"
              class="scem-tag-input"
              type="text"
              placeholder="по заявкам, бухгалтерия..."
              @keydown="onTagKey"
              @blur="addTag"
            />
          </div>
          <small class="scem-hint">Enter или запятая — добавить. До 10 тегов.</small>
        </label>

        <label class="scem-field">
          <span>Заметка</span>
          <textarea v-model="form.notes" rows="3" maxlength="500" placeholder="Например: звонить в будни до 18:00"></textarea>
          <small class="scem-hint">{{ (form.notes || '').length }} / 500</small>
        </label>
      </div>

      <div class="scem-foot">
        <button class="scem-btn scem-btn--ghost" type="button" @click="close" :disabled="saving">Отмена</button>
        <button class="scem-btn scem-btn--primary" type="button" @click="save" :disabled="saving || !canSave">
          {{ saving ? 'Сохраняем...' : (isEdit ? 'Сохранить' : 'Добавить') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { formatRestaurantNumber } from '@/lib/legalEntities.js';

const props = defineProps({
  restaurant: { type: Object, required: true },
  contact: { type: Object, default: null }, // null = создание
  kind: { type: String, default: 'external' }, // 'external' | 'internal'
  supplierId: { type: String, default: null },
  entityGroup: { type: String, default: null },
  scopeTitle: { type: String, default: '' }, // что показываем в подзаголовке («Камако» / «Свой склад БК+ВМ»)
});

const emit = defineEmits(['saved', 'close']);

const isEdit = computed(() => !!props.contact?.id);
const saving = ref(false);
const error = ref('');
const tagInput = ref('');

const form = reactive({
  name: '',
  role: '',
  phones: [{ phone: '', label: '' }],
  email: '',
  telegram: '',
  whatsapp: '',
  viber: '',
  notes: '',
  tags: [],
  is_primary: false,
});

function phonesFromContact(c) {
  if (c && Array.isArray(c.phones) && c.phones.length) {
    return c.phones.map(p => ({ phone: p.phone || '', label: p.label || '' }));
  }
  if (c && c.phone) return [{ phone: c.phone, label: '' }];
  return [{ phone: '', label: '' }];
}

watch(() => props.contact, (c) => {
  if (c) {
    form.name = c.name || '';
    form.role = c.role || '';
    form.phones = phonesFromContact(c);
    form.email = c.email || '';
    form.telegram = c.telegram || '';
    form.whatsapp = c.whatsapp || '';
    form.viber = c.viber || '';
    form.notes = c.notes || '';
    form.tags = [...(c.tags || [])];
    form.is_primary = !!c.is_primary;
  } else {
    form.name = '';
    form.role = '';
    form.phones = [{ phone: '', label: '' }];
    form.email = '';
    form.telegram = '';
    form.whatsapp = '';
    form.viber = '';
    form.notes = '';
    form.tags = [];
    form.is_primary = false;
  }
  error.value = '';
}, { immediate: true });

// Контакт может быть пустым (без имени и без полей). Разрешаем сохранение, если
// заполнен хотя бы один способ связи. Иначе — нет смысла.
const canSave = computed(() => {
  if (form.name.trim()) return true;
  if (form.phones.some(p => (p.phone || '').trim())) return true;
  return !!(form.email.trim() || form.telegram.trim() || form.whatsapp.trim() || form.viber.trim());
});

function addPhone() {
  if (form.phones.length >= 5) return;
  form.phones.push({ phone: '', label: '' });
}
function removePhone(i) {
  if (form.phones.length === 1) {
    form.phones[0].phone = '';
    form.phones[0].label = '';
    return;
  }
  form.phones.splice(i, 1);
}

function addTag() {
  const t = (tagInput.value || '').trim().replace(/,$/, '').trim();
  if (!t) { tagInput.value = ''; return; }
  if (form.tags.includes(t)) { tagInput.value = ''; return; }
  if (form.tags.length >= 10) { tagInput.value = ''; return; }
  form.tags.push(t.slice(0, 30));
  tagInput.value = '';
}

function onTagKey(e) {
  if (e.key === 'Enter' || e.key === ',') {
    e.preventDefault();
    addTag();
  }
}

function removeTag(i) {
  form.tags.splice(i, 1);
}

function close() {
  if (saving.value) return;
  emit('close');
}

async function save() {
  if (!canSave.value || saving.value) return;
  saving.value = true;
  error.value = '';
  // последний хвост тега из инпута
  if (tagInput.value.trim()) addTag();

  // Очищаем пустые телефоны и нормализуем подписи
  const phones = form.phones
    .map(p => ({ phone: (p.phone || '').trim(), label: (p.label || '').trim() }))
    .filter(p => p.phone);
  const payload = {
    restaurant_id: props.restaurant.id,
    kind: props.kind,
    supplier_id: props.kind === 'external' ? props.supplierId : null,
    entity_group: props.kind === 'internal' ? props.entityGroup : null,
    name: form.name.trim() || null,
    role: form.role.trim() || null,
    phones,
    email: form.email.trim() || null,
    telegram: form.telegram.trim() || null,
    whatsapp: form.whatsapp.trim() || null,
    viber: form.viber.trim() || null,
    notes: form.notes.trim() || null,
    tags: form.tags,
    is_primary: form.is_primary ? 1 : 0,
  };
  if (isEdit.value) payload.id = props.contact.id;

  try {
    const headers = { 'Content-Type': 'application/json' };
    const token = localStorage.getItem('bk_session_token') || '';
    if (token) headers['X-Session-Token'] = token;
    const res = await fetch('/api/restaurant-supplier-contacts/save', {
      method: 'POST',
      headers,
      body: JSON.stringify(payload),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Не удалось сохранить контакт');
    emit('saved', data.id);
  } catch (e) {
    error.value = e.message || 'Ошибка сохранения';
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.scem-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.5);
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 40px 16px;
  z-index: 1000;
  overflow-y: auto;
}
.scem-modal {
  background: #fff;
  border-radius: 14px;
  width: 100%;
  max-width: 600px;
  display: flex;
  flex-direction: column;
  box-shadow: 0 20px 50px rgba(0,0,0,0.25);
}
.scem-head {
  padding: 18px 20px;
  border-bottom: 1px solid #e5e7eb;
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}
.scem-title { margin: 0; font-size: 18px; font-weight: 700; color: #1f2937; }
.scem-sub { margin-top: 4px; font-size: 12px; color: #6b7280; }
.scem-x {
  background: none; border: none; font-size: 28px; line-height: 1;
  color: #9ca3af; cursor: pointer; padding: 0; width: 32px; height: 32px;
}
.scem-x:hover { color: #1f2937; }
.scem-body {
  padding: 18px 20px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.scem-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}
.scem-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}
.scem-field > span:not(.scem-field-inline) {
  font-size: 13px;
  color: #374151;
  font-weight: 500;
}
.scem-field-inline {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  color: #1f2937;
  cursor: pointer;
}
.scem-req { color: #dc2626; }
.scem-field input[type="text"],
.scem-field input[type="tel"],
.scem-field input[type="email"],
.scem-field textarea {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  font-size: 14px;
  font-family: inherit;
  background: #fff;
  color: #1f2937;
  box-sizing: border-box;
}
.scem-field input:focus,
.scem-field textarea:focus {
  outline: none;
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}
.scem-hint {
  font-size: 12px;
  color: #6b7280;
}
.scem-phone-row {
  display: grid;
  grid-template-columns: 1fr 1fr 32px;
  gap: 8px;
  align-items: center;
}
.scem-phone-number, .scem-phone-label {
  padding: 8px 12px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  font-size: 14px;
  font-family: inherit;
  background: #fff;
  color: #1f2937;
  box-sizing: border-box;
  width: 100%;
}
.scem-phone-number:focus, .scem-phone-label:focus {
  outline: none;
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}
.scem-phone-del {
  width: 32px;
  height: 36px;
  border: 1px solid #e5e7eb;
  background: #fff;
  color: #9ca3af;
  border-radius: 8px;
  cursor: pointer;
  font-size: 18px;
  line-height: 1;
}
.scem-phone-del:hover:not(:disabled) { background: #fee2e2; color: #dc2626; border-color: #fecaca; }
.scem-phone-del:disabled { opacity: 0.4; cursor: not-allowed; }
.scem-phone-add {
  align-self: flex-start;
  background: none;
  border: 1px dashed #d1d5db;
  color: #6b7280;
  padding: 6px 12px;
  border-radius: 8px;
  font-size: 13px;
  cursor: pointer;
}
.scem-phone-add:hover { color: #1f2937; border-color: #9ca3af; background: #f9fafb; }
.scem-error {
  background: #fef2f2;
  border: 1px solid #fecaca;
  color: #b91c1c;
  padding: 10px 12px;
  border-radius: 8px;
  font-size: 13px;
}
.scem-tags-wrap {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  padding: 6px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  background: #fff;
  align-items: center;
}
.scem-tag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 4px 3px 10px;
  border-radius: 999px;
  background: #e5e7eb;
  font-size: 12px;
  color: #374151;
}
.scem-tag-x {
  background: none;
  border: none;
  color: #6b7280;
  cursor: pointer;
  width: 18px;
  height: 18px;
  font-size: 14px;
  line-height: 1;
  border-radius: 50%;
  padding: 0;
}
.scem-tag-x:hover { background: #d1d5db; color: #111827; }
.scem-tag-input {
  flex: 1;
  min-width: 100px;
  border: none !important;
  padding: 4px 6px !important;
  background: transparent !important;
  font-size: 13px !important;
}
.scem-tag-input:focus { box-shadow: none !important; }

.scem-foot {
  padding: 14px 20px;
  border-top: 1px solid #e5e7eb;
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
.scem-btn {
  padding: 9px 18px;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  border: 1px solid transparent;
}
.scem-btn--ghost {
  background: #fff;
  border-color: #d1d5db;
  color: #374151;
}
.scem-btn--ghost:hover { background: #f9fafb; }
.scem-btn--primary {
  background: #1f2937;
  color: #fff;
}
.scem-btn--primary:hover:not(:disabled) { background: #111827; }
.scem-btn:disabled { opacity: 0.5; cursor: not-allowed; }

@media (max-width: 600px) {
  .scem-backdrop { padding: 0; align-items: stretch; }
  .scem-modal { border-radius: 0; max-width: none; min-height: 100vh; }
  .scem-row { grid-template-columns: 1fr; }
}
</style>
