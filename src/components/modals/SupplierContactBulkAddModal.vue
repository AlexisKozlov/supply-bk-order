<template>
  <div class="bcm-backdrop" @click.self="close">
    <div class="bcm-modal">
      <div class="bcm-head">
        <div>
          <h3 class="bcm-title">Массовое добавление контакта</h3>
          <div class="bcm-sub">Заполните карточку один раз и выберите рестораны, в которые её скопировать.</div>
        </div>
        <button class="bcm-x" type="button" @click="close" aria-label="Закрыть">×</button>
      </div>

      <div class="bcm-body">
        <div v-if="error" class="bcm-error">{{ error }}</div>
        <div v-if="result" class="bcm-success">
          <div><strong>Готово.</strong> Создано: {{ result.created }} из {{ result.total }}.</div>
          <details v-if="(result.skipped || []).length" class="bcm-details">
            <summary>Пропущено: {{ result.skipped.length }}</summary>
            <ul>
              <li v-for="(s, i) in result.skipped" :key="'s'+i">Ресторан {{ s.number || s.restaurant_id }} — {{ s.reason }}</li>
            </ul>
          </details>
          <details v-if="(result.errors || []).length" class="bcm-details">
            <summary>Ошибки: {{ result.errors.length }}</summary>
            <ul>
              <li v-for="(e, i) in result.errors" :key="'e'+i">Ресторан {{ e.number || e.restaurant_id }} — {{ e.reason }}</li>
            </ul>
          </details>
        </div>

        <!-- Шаг 1: куда добавляем -->
        <section class="bcm-section">
          <div class="bcm-section-title">1. Куда добавляем</div>
          <div class="bcm-group">
            <label><input type="radio" v-model="form.kind" value="external" /> Внешний поставщик</label>
            <label><input type="radio" v-model="form.kind" value="internal" /> Свой склад</label>
          </div>

          <div class="bcm-group">
            <label class="bcm-field">
              <span>Группа юрлиц</span>
              <select v-model="form.group" @change="onGroupChange">
                <option value="BK_VM">БК + Воглия Матта</option>
                <option value="PS">Пицца Стар</option>
              </select>
            </label>

            <label v-if="form.kind === 'external'" class="bcm-field">
              <span>Поставщик</span>
              <select v-model="form.supplierId" :disabled="loadingSuppliers">
                <option value="">— выберите поставщика —</option>
                <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.short_name || s.full_name }}</option>
              </select>
              <small v-if="loadingSuppliers" class="bcm-hint">Загружаем поставщиков…</small>
            </label>
          </div>
        </section>

        <!-- Шаг 2: карточка контакта -->
        <section class="bcm-section">
          <div class="bcm-section-title">2. Карточка контакта</div>
          <label class="bcm-field">
            <span>Имя</span>
            <input v-model="form.name" type="text" maxlength="100" placeholder="Например: Катя" />
          </label>
          <label class="bcm-field">
            <span>Должность / роль</span>
            <input v-model="form.role" type="text" maxlength="60" placeholder="торгпред, диспетчер, бухгалтер..." />
          </label>
          <div class="bcm-field">
            <span>Телефоны <small class="bcm-hint">(до 5)</small></span>
            <div v-for="(p, i) in form.phones" :key="'p'+i" class="bcm-phone-row">
              <input v-model="p.phone" type="tel" maxlength="30" placeholder="+375 29 ..." class="bcm-phone-number" />
              <input v-model="p.label" type="text" maxlength="30" placeholder="Подпись (личный, рабочий...)" list="bcm-phone-labels" class="bcm-phone-label" />
              <button type="button" class="bcm-phone-del" @click="removePhone(i)" :disabled="form.phones.length === 1" title="Убрать">×</button>
            </div>
            <button type="button" class="bcm-phone-add" @click="addPhone" v-if="form.phones.length < 5">+ ещё телефон</button>
            <datalist id="bcm-phone-labels">
              <option value="Личный"></option>
              <option value="Рабочий"></option>
              <option value="Офис"></option>
              <option value="Директор"></option>
              <option value="Бухгалтер"></option>
              <option value="Магазин"></option>
              <option value="Склад"></option>
            </datalist>
          </div>
          <label class="bcm-field">
            <span>Email</span>
            <input v-model="form.email" type="email" maxlength="100" />
          </label>
          <div class="bcm-row">
            <label class="bcm-field">
              <span>Telegram</span>
              <input v-model="form.telegram" type="text" maxlength="60" placeholder="@username или +375..." />
            </label>
            <label class="bcm-field">
              <span>WhatsApp</span>
              <input v-model="form.whatsapp" type="tel" maxlength="30" />
            </label>
          </div>
          <div class="bcm-row">
            <label class="bcm-field">
              <span>Viber</span>
              <input v-model="form.viber" type="tel" maxlength="30" />
            </label>
            <label class="bcm-field">
              <span class="bcm-field-inline">
                <input v-model="form.is_primary" type="checkbox" />
                <span>Сделать основным</span>
              </span>
              <small class="bcm-hint">В каждом ресторане сбросит звёздочку у других карточек этого поставщика.</small>
            </label>
          </div>
          <label class="bcm-field">
            <span>Теги</span>
            <div class="bcm-tags-wrap">
              <span v-for="(t, i) in form.tags" :key="i" class="bcm-tag">
                {{ t }}
                <button type="button" class="bcm-tag-x" @click="removeTag(i)" aria-label="Убрать">×</button>
              </span>
              <input
                v-model="tagInput"
                class="bcm-tag-input"
                type="text"
                placeholder="по заявкам, бухгалтерия..."
                @keydown="onTagKey"
                @blur="addTag"
              />
            </div>
            <small class="bcm-hint">Enter или запятая — добавить. До 10 тегов.</small>
          </label>

          <label class="bcm-field">
            <span>Заметка</span>
            <textarea v-model="form.notes" rows="2" maxlength="500"></textarea>
          </label>
        </section>

        <!-- Шаг 3: в какие рестораны -->
        <section class="bcm-section">
          <div class="bcm-section-title">3. В какие рестораны ({{ selectedCount }} из {{ filteredRestaurants.length }})</div>
          <div class="bcm-rest-tools">
            <input v-model="restFilter" class="bcm-search" type="search" placeholder="Поиск по номеру / адресу" />
            <button class="bcm-mini" type="button" @click="selectAll">Все</button>
            <button class="bcm-mini" type="button" @click="selectNone">Никого</button>
            <button class="bcm-mini" type="button" @click="invertSelection">Инверсия</button>
          </div>
          <div v-if="loadingRestaurants" class="bcm-state">Загружаем рестораны…</div>
          <div v-else-if="!filteredRestaurants.length" class="bcm-state">Нет ресторанов в этой группе.</div>
          <div v-else class="bcm-rest-list">
            <label v-for="r in filteredRestaurants" :key="r.id" class="bcm-rest">
              <input type="checkbox" :value="r.id" v-model="selectedIds" />
              <span class="bcm-rest-num">{{ formatRestNum(r.number, r.legal_entity_group) }}</span>
              <span class="bcm-rest-addr">{{ r.city || '' }}{{ r.address ? ', ' + r.address : '' }}</span>
              <span v-if="r.contacts_count > 0" class="bcm-rest-count">{{ r.contacts_count }}</span>
            </label>
          </div>
        </section>
      </div>

      <div class="bcm-foot">
        <button class="bcm-btn bcm-btn--ghost" type="button" @click="close" :disabled="saving">Закрыть</button>
        <button class="bcm-btn bcm-btn--primary" type="button" @click="apply" :disabled="!canApply || saving">
          {{ saving ? 'Сохраняем...' : `Создать в ${selectedCount} ресторан${ruEnding(selectedCount)}` }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { formatRestaurantNumber } from '@/lib/legalEntities.js';

const props = defineProps({
  restaurants: { type: Array, default: () => [] }, // из manager-overview
});
const emit = defineEmits(['saved', 'close']);

const error = ref('');
const result = ref(null);
const saving = ref(false);

const form = reactive({
  kind: 'external',          // 'external' | 'internal'
  group: 'BK_VM',
  supplierId: '',
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

function addPhone() {
  if (form.phones.length >= 5) return;
  form.phones.push({ phone: '', label: '' });
}
function removePhone(i) {
  if (form.phones.length === 1) { form.phones[0].phone = ''; form.phones[0].label = ''; return; }
  form.phones.splice(i, 1);
}
const tagInput = ref('');

function addTag() {
  const t = (tagInput.value || '').trim().replace(/,$/, '').trim();
  if (!t) { tagInput.value = ''; return; }
  if (form.tags.includes(t)) { tagInput.value = ''; return; }
  if (form.tags.length >= 10) { tagInput.value = ''; return; }
  form.tags.push(t.slice(0, 30));
  tagInput.value = '';
}
function removeTag(i) { form.tags.splice(i, 1); }
function onTagKey(e) {
  if (e.key === 'Enter' || e.key === ',') {
    e.preventDefault();
    addTag();
  }
}

const restFilter = ref('');
const selectedIds = ref([]);

const suppliers = ref([]);
const loadingSuppliers = ref(false);

const loadingRestaurants = computed(() => !props.restaurants.length);

const filteredRestaurants = computed(() => {
  const q = restFilter.value.trim().toLowerCase();
  return props.restaurants.filter(r => {
    if (r.legal_entity_group !== form.group) return false;
    if (!q) return true;
    const hay = [r.number, r.city, r.address, r.region, formatRestNum(r.number, r.legal_entity_group)].join(' ').toLowerCase();
    return hay.includes(q);
  });
});

const selectedCount = computed(() => {
  const set = new Set(filteredRestaurants.value.map(r => r.id));
  return selectedIds.value.filter(id => set.has(id)).length;
});

const canApply = computed(() => {
  // Хотя бы имя или хотя бы один способ связи. Иначе создавать нечего.
  const hasPhone = form.phones.some(p => (p.phone || '').trim());
  const hasContact = !!(form.name.trim() || hasPhone || form.email.trim() || form.telegram.trim() || form.whatsapp.trim() || form.viber.trim());
  if (!hasContact) return false;
  if (form.kind === 'external' && !form.supplierId) return false;
  return selectedCount.value > 0;
});

function authHeaders(extra = {}) {
  const h = { ...extra };
  const t = localStorage.getItem('bk_session_token') || '';
  if (t) h['X-Session-Token'] = t;
  return h;
}

async function loadSuppliers() {
  if (form.kind !== 'external') { suppliers.value = []; return; }
  loadingSuppliers.value = true;
  try {
    const res = await fetch(`/api/restaurant-supplier-contacts/suppliers?group=${encodeURIComponent(form.group)}`, { headers: authHeaders() });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Ошибка загрузки поставщиков');
    suppliers.value = data.suppliers || [];
    // Если выбранный поставщик из другой группы — сбрасываем
    if (form.supplierId && !suppliers.value.find(s => s.id === form.supplierId)) {
      form.supplierId = '';
    }
  } catch (e) {
    error.value = e.message || 'Не удалось загрузить поставщиков';
  } finally {
    loadingSuppliers.value = false;
  }
}

function onGroupChange() {
  // При смене группы сбрасываем выбранных ресторанов чужой группы и подгружаем поставщиков
  const set = new Set(filteredRestaurants.value.map(r => r.id));
  selectedIds.value = selectedIds.value.filter(id => set.has(id));
  loadSuppliers();
}

watch(() => form.kind, () => loadSuppliers());

function selectAll() {
  const ids = filteredRestaurants.value.map(r => r.id);
  const cur = new Set(selectedIds.value);
  ids.forEach(id => cur.add(id));
  selectedIds.value = Array.from(cur);
}
function selectNone() {
  const set = new Set(filteredRestaurants.value.map(r => r.id));
  selectedIds.value = selectedIds.value.filter(id => !set.has(id));
}
function invertSelection() {
  const set = new Set(filteredRestaurants.value.map(r => r.id));
  const sel = new Set(selectedIds.value);
  filteredRestaurants.value.forEach(r => {
    if (sel.has(r.id)) sel.delete(r.id);
    else sel.add(r.id);
  });
  selectedIds.value = Array.from(sel);
}

function formatRestNum(num, group) {
  return formatRestaurantNumber(num, group);
}
function ruEnding(n) {
  const last = Math.abs(n) % 10;
  const last2 = Math.abs(n) % 100;
  if (last2 >= 11 && last2 <= 14) return 'ов';
  if (last === 1) return '';
  if (last >= 2 && last <= 4) return 'а';
  return 'ов';
}

function close() {
  if (saving.value) return;
  emit('close');
}

async function apply() {
  if (!canApply.value || saving.value) return;
  saving.value = true;
  error.value = '';
  result.value = null;

  if (tagInput.value.trim()) addTag(); // последний хвост тега из инпута

  const targetIds = selectedIds.value.filter(id => filteredRestaurants.value.find(r => r.id === id));

  const phones = form.phones
    .map(p => ({ phone: (p.phone || '').trim(), label: (p.label || '').trim() }))
    .filter(p => p.phone);
  const payload = {
    restaurant_ids: targetIds,
    kind: form.kind,
    supplier_id: form.kind === 'external' ? form.supplierId : null,
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

  try {
    const res = await fetch('/api/restaurant-supplier-contacts/bulk-create', {
      method: 'POST',
      headers: authHeaders({ 'Content-Type': 'application/json' }),
      body: JSON.stringify(payload),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Ошибка сохранения');
    result.value = data;
    emit('saved', data);
  } catch (e) {
    error.value = e.message || 'Ошибка сохранения';
  } finally {
    saving.value = false;
  }
}

onMounted(() => loadSuppliers());
</script>

<style scoped>
.bcm-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.5);
  z-index: 1000;
  overflow-y: auto;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 40px 16px;
}
.bcm-modal {
  background: #fff;
  border-radius: 14px;
  width: 100%;
  max-width: 720px;
  display: flex;
  flex-direction: column;
  box-shadow: 0 20px 50px rgba(0,0,0,0.25);
}
.bcm-head {
  padding: 18px 20px;
  border-bottom: 1px solid #e5e7eb;
  display: flex;
  justify-content: space-between;
  gap: 12px;
}
.bcm-title { margin: 0; font-size: 18px; font-weight: 700; color: #1f2937; }
.bcm-sub  { margin-top: 4px; font-size: 13px; color: #6b7280; max-width: 600px; }
.bcm-x {
  background: none; border: none; font-size: 28px; line-height: 1;
  color: #9ca3af; cursor: pointer; padding: 0; width: 32px; height: 32px;
}
.bcm-x:hover { color: #1f2937; }

.bcm-body { padding: 18px 20px; display: flex; flex-direction: column; gap: 16px; }

.bcm-section { display: flex; flex-direction: column; gap: 10px; }
.bcm-section-title {
  font-size: 13px;
  font-weight: 600;
  color: #6b7280;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.bcm-group { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end; }
.bcm-group label:not(.bcm-field) {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 14px; color: #1f2937; cursor: pointer;
}
.bcm-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.bcm-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; flex: 1; }
.bcm-field > span:not(.bcm-field-inline) { font-size: 13px; color: #374151; font-weight: 500; }
.bcm-field-inline { display: inline-flex; align-items: center; gap: 8px; font-size: 14px; color: #1f2937; cursor: pointer; }
.bcm-req { color: #dc2626; }
.bcm-field input[type="text"],
.bcm-field input[type="tel"],
.bcm-field input[type="email"],
.bcm-field select,
.bcm-field textarea {
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
.bcm-field input:focus, .bcm-field select:focus, .bcm-field textarea:focus {
  outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}
.bcm-hint { font-size: 12px; color: #6b7280; }
.bcm-phone-row { display: grid; grid-template-columns: 1fr 1fr 32px; gap: 8px; align-items: center; margin-top: 4px; }
.bcm-phone-number, .bcm-phone-label {
  padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px;
  font-size: 14px; font-family: inherit; background: #fff; color: #1f2937; box-sizing: border-box; width: 100%;
}
.bcm-phone-number:focus, .bcm-phone-label:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
.bcm-phone-del {
  width: 32px; height: 36px; border: 1px solid #e5e7eb; background: #fff; color: #9ca3af;
  border-radius: 8px; cursor: pointer; font-size: 18px; line-height: 1;
}
.bcm-phone-del:hover:not(:disabled) { background: #fee2e2; color: #dc2626; border-color: #fecaca; }
.bcm-phone-del:disabled { opacity: 0.4; cursor: not-allowed; }
.bcm-phone-add {
  align-self: flex-start; background: none; border: 1px dashed #d1d5db; color: #6b7280;
  padding: 6px 12px; border-radius: 8px; font-size: 13px; cursor: pointer; margin-top: 6px;
}
.bcm-phone-add:hover { color: #1f2937; border-color: #9ca3af; background: #f9fafb; }

.bcm-error {
  background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c;
  padding: 10px 12px; border-radius: 8px; font-size: 13px;
}
.bcm-success {
  background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46;
  padding: 10px 12px; border-radius: 8px; font-size: 13px;
  display: flex; flex-direction: column; gap: 6px;
}
.bcm-details { font-size: 12px; }
.bcm-details ul { margin: 6px 0 0; padding-left: 18px; }

.bcm-rest-tools {
  display: flex; gap: 6px; align-items: center; flex-wrap: wrap;
}
.bcm-search {
  flex: 1; min-width: 200px;
  padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px;
}
.bcm-mini {
  padding: 6px 10px; border: 1px solid #d1d5db; background: #fff; color: #374151;
  border-radius: 6px; cursor: pointer; font-size: 12px;
}
.bcm-mini:hover { background: #f9fafb; }

.bcm-state { padding: 16px; text-align: center; color: #6b7280; font-size: 13px; }

.bcm-rest-list {
  max-height: 280px; overflow-y: auto;
  border: 1px solid #e5e7eb; border-radius: 10px;
  display: flex; flex-direction: column;
}
.bcm-rest {
  display: grid;
  grid-template-columns: 24px 70px 1fr auto;
  gap: 10px;
  align-items: center;
  padding: 8px 12px;
  border-bottom: 1px solid #f3f4f6;
  cursor: pointer;
  font-size: 13px;
}
.bcm-rest:last-child { border-bottom: none; }
.bcm-rest:hover { background: #f9fafb; }
.bcm-rest input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
.bcm-rest-num { font-weight: 600; color: #1f2937; }
.bcm-rest-addr {
  color: #6b7280;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.bcm-rest-count {
  font-size: 11px; padding: 1px 7px; border-radius: 999px;
  background: #e5e7eb; color: #4b5563;
}

.bcm-tags-wrap {
  display: flex; flex-wrap: wrap; gap: 6px;
  padding: 6px; border: 1px solid #d1d5db; border-radius: 8px;
  background: #fff; align-items: center;
}
.bcm-tag {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 4px 3px 10px; border-radius: 999px;
  background: #e5e7eb; font-size: 12px; color: #374151;
}
.bcm-tag-x {
  background: none; border: none; color: #6b7280; cursor: pointer;
  width: 18px; height: 18px; font-size: 14px; line-height: 1;
  border-radius: 50%; padding: 0;
}
.bcm-tag-x:hover { background: #d1d5db; color: #111827; }
.bcm-tag-input {
  flex: 1; min-width: 100px;
  border: none !important; padding: 4px 6px !important;
  background: transparent !important; font-size: 13px !important;
}
.bcm-tag-input:focus { box-shadow: none !important; }

.bcm-foot {
  padding: 14px 20px;
  border-top: 1px solid #e5e7eb;
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
.bcm-btn { padding: 9px 18px; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; border: 1px solid transparent; }
.bcm-btn--ghost { background: #fff; border-color: #d1d5db; color: #374151; }
.bcm-btn--ghost:hover:not(:disabled) { background: #f9fafb; }
.bcm-btn--primary { background: #1f2937; color: #fff; }
.bcm-btn--primary:hover:not(:disabled) { background: #111827; }
.bcm-btn:disabled { opacity: 0.5; cursor: not-allowed; }

@media (max-width: 700px) {
  .bcm-backdrop { padding: 0; align-items: stretch; }
  .bcm-modal { border-radius: 0; max-width: none; min-height: 100vh; }
  .bcm-row { grid-template-columns: 1fr; }
}
</style>
