<template>
  <div class="bem-backdrop" @click.self="close">
    <div class="bem-modal">
      <div class="bem-head">
        <div>
          <h3 class="bem-title">Управление контактами</h3>
          <div class="bem-sub">Выберите поставщика → найдём всех его людей. Кликните на карточку → исправьте поля и нажмите «Применить», либо удалите карточку из выбранных ресторанов.</div>
        </div>
        <button class="bem-x" type="button" @click="close" aria-label="Закрыть">×</button>
      </div>

      <div class="bem-body">
        <div v-if="error" class="bem-error">{{ error }}</div>
        <div v-if="result" class="bem-success">
          <template v-if="result.kind === 'delete'">Удалено: <strong>{{ result.deleted }}</strong> карточ{{ result.deleted === 1 ? 'ка' : 'ек' }}.</template>
          <template v-else>Обновлено: <strong>{{ result.updated }}</strong> из {{ result.total }}.</template>
          <details v-if="(result.errors || []).length" class="bem-details">
            <summary>Ошибки: {{ result.errors.length }}</summary>
            <ul>
              <li v-for="(e, i) in result.errors" :key="'e'+i">id {{ e.id }} — {{ e.reason }}</li>
            </ul>
          </details>
        </div>

        <!-- Выбор поставщика -->
        <section class="bem-section">
          <div class="bem-section-title">1. Поставщик</div>
          <div class="bem-group">
            <label><input type="radio" v-model="form.kind" value="external" @change="onKindChange" /> Внешний поставщик</label>
            <label><input type="radio" v-model="form.kind" value="internal" @change="onKindChange" /> Свой склад</label>
          </div>
          <div class="bem-row">
            <label class="bem-field">
              <span>Группа юрлиц</span>
              <select v-model="form.group" @change="reload">
                <option value="BK_VM">БК + Воглия Матта</option>
                <option value="PS">Пицца Стар</option>
              </select>
            </label>
            <label v-if="form.kind === 'external'" class="bem-field">
              <span>Поставщик</span>
              <select v-model="form.supplierId" :disabled="loadingSuppliers" @change="reload">
                <option value="">— выберите поставщика —</option>
                <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.short_name || s.full_name }}</option>
              </select>
            </label>
          </div>
        </section>

        <!-- Группы карточек -->
        <section class="bem-section">
          <div class="bem-section-title">2. Карточки контактов</div>
          <div v-if="loadingContacts" class="bem-state">Ищем контакты...</div>
          <div v-else-if="!hasQuery" class="bem-state">Выберите поставщика выше.</div>
          <div v-else-if="!groups.length" class="bem-state">У этого поставщика нет контактов ни в одном ресторане.</div>

          <div v-else class="bem-groups">
            <details v-for="g in groups" :key="g.key" class="bem-card" :open="false">
              <summary class="bem-card-head">
                <div class="bem-card-info">
                  <div class="bem-card-name">
                    {{ g.name || '(без имени)' }}
                    <span v-if="g.role" class="bem-card-role">· {{ g.role }}</span>
                  </div>
                  <div class="bem-card-extra">
                    <span v-if="g.phone">{{ formatPhone(g.phone) }}</span>
                    <span v-if="g.telegram">{{ g.telegram.startsWith('+') ? 'TG: ' + formatPhone(g.telegram) : '@' + g.telegram }}</span>
                    <span v-if="g.email">{{ g.email }}</span>
                  </div>
                </div>
                <div class="bem-card-count">в {{ g.contacts.length }} ресторан{{ ruEnding(g.contacts.length) }}</div>
              </summary>

              <!-- Форма редактирования группы -->
              <div class="bem-form">
                <label class="bem-field">
                  <span>Имя</span>
                  <input v-model="g.edit.name" type="text" maxlength="100" />
                </label>
                <label class="bem-field">
                  <span>Должность / роль</span>
                  <input v-model="g.edit.role" type="text" maxlength="60" />
                </label>

                <div class="bem-field">
                  <span>Телефоны <small class="bem-hint">(до 5)</small></span>
                  <div v-for="(p, i) in g.edit.phones" :key="'p'+i" class="bem-phone-row">
                    <input v-model="p.phone" type="tel" maxlength="30" placeholder="+375 29 ..." class="bem-phone-number" />
                    <input v-model="p.label" type="text" maxlength="30" placeholder="Подпись" :list="'bem-phone-labels-' + g.key" class="bem-phone-label" />
                    <button type="button" class="bem-phone-del" @click="removePhone(g, i)" :disabled="g.edit.phones.length === 1" title="Убрать">×</button>
                  </div>
                  <button type="button" class="bem-phone-add" @click="addPhone(g)" v-if="g.edit.phones.length < 5">+ ещё телефон</button>
                  <datalist :id="'bem-phone-labels-' + g.key">
                    <option value="Личный"></option>
                    <option value="Рабочий"></option>
                    <option value="Офис"></option>
                    <option value="Директор"></option>
                    <option value="Бухгалтер"></option>
                    <option value="Магазин"></option>
                    <option value="Склад"></option>
                  </datalist>
                </div>

                <div class="bem-row">
                  <label class="bem-field">
                    <span>Email</span>
                    <input v-model="g.edit.email" type="email" maxlength="100" />
                  </label>
                  <label class="bem-field">
                    <span>Telegram</span>
                    <input v-model="g.edit.telegram" type="text" maxlength="60" placeholder="@username или +375..." />
                  </label>
                </div>
                <div class="bem-row">
                  <label class="bem-field">
                    <span>WhatsApp</span>
                    <input v-model="g.edit.whatsapp" type="tel" maxlength="30" />
                  </label>
                  <label class="bem-field">
                    <span>Viber</span>
                    <input v-model="g.edit.viber" type="tel" maxlength="30" />
                  </label>
                </div>
                <label class="bem-field">
                  <span>Заметка</span>
                  <textarea v-model="g.edit.notes" rows="2" maxlength="500"></textarea>
                </label>
                <label class="bem-field">
                  <span class="bem-field-inline">
                    <input v-model="g.edit.is_primary" type="checkbox" />
                    <span>Сделать основным</span>
                  </span>
                  <small class="bem-hint">В каждом ресторане сбросит звёздочку у других карточек этого поставщика.</small>
                </label>

                <!-- Список ресторанов для применения -->
                <div class="bem-field">
                  <span>В каких ресторанах обновить ({{ countSelected(g) }} из {{ g.contacts.length }})</span>
                  <div class="bem-rest-tools">
                    <button class="bem-mini" type="button" @click="selectAllRests(g)">Все</button>
                    <button class="bem-mini" type="button" @click="selectNoRests(g)">Никого</button>
                  </div>
                  <div class="bem-rest-list">
                    <label v-for="c in g.contacts" :key="c.id" class="bem-rest">
                      <input type="checkbox" :value="c.id" v-model="g.edit.selectedIds" />
                      <span class="bem-rest-num">{{ formatRestNum(c.restaurant_number, c.legal_entity_group) }}</span>
                      <span v-if="c.is_primary" class="bem-primary" title="Основной">★</span>
                    </label>
                  </div>
                </div>

                <div class="bem-form-foot">
                  <button class="bem-btn bem-btn--ghost" type="button" @click="resetGroup(g)" :disabled="g.saving">Сбросить</button>
                  <button class="bem-btn bem-btn--danger" type="button" @click="deleteGroup(g)" :disabled="!countSelected(g) || g.saving">
                    {{ g.saving === 'delete' ? 'Удаляем...' : `Удалить из ${countSelected(g)} ресторан${ruEnding(countSelected(g))}` }}
                  </button>
                  <button class="bem-btn bem-btn--primary" type="button" @click="applyGroup(g)" :disabled="!countSelected(g) || g.saving">
                    {{ g.saving === 'update' ? 'Применяем...' : `Применить к ${countSelected(g)} ресторан${ruEnding(countSelected(g))}` }}
                  </button>
                </div>
              </div>
            </details>
          </div>
        </section>
      </div>

      <div class="bem-foot">
        <button class="bem-btn bem-btn--ghost" type="button" @click="close">Закрыть</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { formatRestaurantNumber } from '@/lib/legalEntities.js';

const emit = defineEmits(['updated', 'deleted', 'close']);

const error = ref('');
const result = ref(null);

const form = reactive({
  kind: 'external',
  group: 'BK_VM',
  supplierId: '',
});

const suppliers = ref([]);
const loadingSuppliers = ref(false);

const contacts = ref([]);
const loadingContacts = ref(false);

const hasQuery = computed(() => form.kind === 'internal' || !!form.supplierId);

// Группы — реактивный список. Перестраиваются только когда меняется contacts
// (обычно после reload/applyGroup/deleteGroup). Локальные правки в g.edit
// не пересоздают группу — иначе бы терялись пользовательские изменения.
const groups = ref([]);

function buildEditFromContact(c) {
  return reactive({
    name: c.name || '',
    role: c.role || '',
    phones: (c.phones && c.phones.length)
      ? c.phones.map(p => ({ phone: p.phone || '', label: p.label || '' }))
      : (c.phone ? [{ phone: c.phone, label: '' }] : [{ phone: '', label: '' }]),
    email: c.email || '',
    telegram: c.telegram || '',
    whatsapp: c.whatsapp || '',
    viber: c.viber || '',
    notes: c.notes || '',
    is_primary: !!c.is_primary,
    selectedIds: [],
  });
}

function buildGroups(contactsList) {
  const map = new Map();
  for (const c of contactsList) {
    const firstPhone = (c.phones && c.phones[0] && c.phones[0].phone) || c.phone || '';
    const key = [c.name || '', firstPhone, c.telegram || '', c.email || ''].join('|');
    if (!map.has(key)) {
      map.set(key, reactive({
        key,
        name: c.name || '',
        role: c.role || '',
        phone: firstPhone,
        telegram: c.telegram || '',
        email: c.email || '',
        contacts: [],
        edit: buildEditFromContact(c),
        saving: false, // 'update' | 'delete' | false
      }));
    }
    map.get(key).contacts.push(c);
  }
  const arr = Array.from(map.values()).sort((a, b) => b.contacts.length - a.contacts.length);
  for (const g of arr) g.edit.selectedIds = g.contacts.map(c => c.id);
  return arr;
}

watch(contacts, (list) => { groups.value = buildGroups(list || []); }, { immediate: true });

function countSelected(g) {
  return g.edit.selectedIds.length;
}
function selectAllRests(g) { g.edit.selectedIds = g.contacts.map(c => c.id); }
function selectNoRests(g) { g.edit.selectedIds = []; }
function addPhone(g) { if (g.edit.phones.length < 5) g.edit.phones.push({ phone: '', label: '' }); }
function removePhone(g, i) {
  if (g.edit.phones.length === 1) { g.edit.phones[0].phone = ''; g.edit.phones[0].label = ''; return; }
  g.edit.phones.splice(i, 1);
}
function resetGroup(g) {
  const c = g.contacts[0];
  if (!c) return;
  g.edit.name = c.name || '';
  g.edit.role = c.role || '';
  g.edit.phones = (c.phones && c.phones.length)
    ? c.phones.map(p => ({ phone: p.phone || '', label: p.label || '' }))
    : (c.phone ? [{ phone: c.phone, label: '' }] : [{ phone: '', label: '' }]);
  g.edit.email = c.email || '';
  g.edit.telegram = c.telegram || '';
  g.edit.whatsapp = c.whatsapp || '';
  g.edit.viber = c.viber || '';
  g.edit.notes = c.notes || '';
  g.edit.is_primary = !!c.is_primary;
  g.edit.selectedIds = g.contacts.map(c => c.id);
}

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
  } catch (e) {
    error.value = e.message || 'Не удалось загрузить поставщиков';
  } finally {
    loadingSuppliers.value = false;
  }
}

async function reload() {
  contacts.value = [];
  if (!hasQuery.value) return;
  loadingContacts.value = true;
  error.value = '';
  try {
    const params = form.kind === 'external'
      ? `kind=external&supplier_id=${encodeURIComponent(form.supplierId)}`
      : `kind=internal&entity_group=${encodeURIComponent(form.group)}`;
    const res = await fetch(`/api/restaurant-supplier-contacts/find-by-supplier?${params}`, { headers: authHeaders() });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Ошибка поиска');
    contacts.value = data.contacts || [];
  } catch (e) {
    error.value = e.message || 'Не удалось загрузить контакты';
  } finally {
    loadingContacts.value = false;
  }
}

function onKindChange() {
  contacts.value = [];
  if (form.kind === 'external') loadSuppliers();
  else reload();
}

async function applyGroup(g) {
  if (!g.edit.selectedIds.length || g.saving) return;
  g.saving = 'update';
  error.value = '';
  result.value = null;
  try {
    const phones = g.edit.phones
      .map(p => ({ phone: (p.phone || '').trim(), label: (p.label || '').trim() }))
      .filter(p => p.phone);
    const payload = {
      ids: g.edit.selectedIds,
      fields: {
        name: (g.edit.name || '').trim() || null,
        role: (g.edit.role || '').trim() || null,
        phones,
        email: (g.edit.email || '').trim() || null,
        telegram: (g.edit.telegram || '').trim() || null,
        whatsapp: (g.edit.whatsapp || '').trim() || null,
        viber: (g.edit.viber || '').trim() || null,
        notes: (g.edit.notes || '').trim() || null,
        is_primary: g.edit.is_primary ? 1 : 0,
      },
    };
    const res = await fetch('/api/restaurant-supplier-contacts/bulk-update', {
      method: 'POST',
      headers: authHeaders({ 'Content-Type': 'application/json' }),
      body: JSON.stringify(payload),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Ошибка обновления');
    result.value = { kind: 'update', ...data };
    emit('updated', data);
    await reload();
  } catch (e) {
    error.value = e.message || 'Ошибка обновления';
  } finally {
    g.saving = false;
  }
}

async function deleteGroup(g) {
  if (!g.edit.selectedIds.length || g.saving) return;
  const n = g.edit.selectedIds.length;
  if (!window.confirm(`Удалить ${n} контакт${ruEnding(n)}? Это действие нельзя отменить.`)) return;
  g.saving = 'delete';
  error.value = '';
  result.value = null;
  try {
    const res = await fetch('/api/restaurant-supplier-contacts/bulk-delete', {
      method: 'POST',
      headers: authHeaders({ 'Content-Type': 'application/json' }),
      body: JSON.stringify({ ids: g.edit.selectedIds }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Ошибка удаления');
    result.value = { kind: 'delete', ...data };
    emit('deleted', data);
    await reload();
  } catch (e) {
    error.value = e.message || 'Ошибка удаления';
  } finally {
    g.saving = false;
  }
}

function formatRestNum(num, group) { return formatRestaurantNumber(num, group); }
function formatPhone(p) {
  if (!p) return '';
  if (/^\+375\d{9}$/.test(p)) {
    return `+375 (${p.slice(4, 6)}) ${p.slice(6, 9)}-${p.slice(9, 11)}-${p.slice(11, 13)}`;
  }
  return p;
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
  emit('close');
}

onMounted(() => loadSuppliers());
</script>

<style scoped>
.bem-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,0.5); z-index: 1000; overflow-y: auto; display: flex; align-items: flex-start; justify-content: center; padding: 40px 16px; }
.bem-modal { background: #fff; border-radius: 14px; width: 100%; max-width: 800px; display: flex; flex-direction: column; box-shadow: 0 20px 50px rgba(0,0,0,0.25); }
.bem-head { padding: 18px 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; gap: 12px; }
.bem-title { margin: 0; font-size: 18px; font-weight: 700; color: #1f2937; }
.bem-sub { margin-top: 4px; font-size: 13px; color: #6b7280; max-width: 640px; }
.bem-x { background: none; border: none; font-size: 28px; line-height: 1; color: #9ca3af; cursor: pointer; padding: 0; width: 32px; height: 32px; }
.bem-x:hover { color: #1f2937; }

.bem-body { padding: 18px 20px; display: flex; flex-direction: column; gap: 16px; }
.bem-section { display: flex; flex-direction: column; gap: 10px; }
.bem-section-title { font-size: 13px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; }
.bem-group { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end; }
.bem-group label:not(.bem-field) { display: inline-flex; align-items: center; gap: 6px; font-size: 14px; color: #1f2937; cursor: pointer; }
.bem-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.bem-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; flex: 1; }
.bem-field > span:not(.bem-field-inline) { font-size: 13px; color: #374151; font-weight: 500; }
.bem-field-inline { display: inline-flex; align-items: center; gap: 8px; font-size: 14px; color: #1f2937; cursor: pointer; }
.bem-field input[type="text"], .bem-field input[type="tel"], .bem-field input[type="email"], .bem-field select, .bem-field textarea {
  width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; font-family: inherit; background: #fff; color: #1f2937; box-sizing: border-box;
}
.bem-field input:focus, .bem-field select:focus, .bem-field textarea:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
.bem-hint { font-size: 12px; color: #6b7280; }

.bem-error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; padding: 10px 12px; border-radius: 8px; font-size: 13px; }
.bem-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 10px 12px; border-radius: 8px; font-size: 13px; }
.bem-details { font-size: 12px; margin-top: 4px; }
.bem-state { padding: 16px; text-align: center; color: #6b7280; font-size: 13px; }

.bem-groups { display: flex; flex-direction: column; gap: 8px; }
.bem-card { border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; background: #fff; }
.bem-card-head { list-style: none; cursor: pointer; padding: 10px 12px; display: grid; grid-template-columns: 1fr auto; gap: 10px; align-items: center; background: #f9fafb; }
.bem-card-head::-webkit-details-marker { display: none; }
.bem-card[open] .bem-card-head { background: #f3f4f6; border-bottom: 1px solid #e5e7eb; }
.bem-card-info { min-width: 0; }
.bem-card-name { font-weight: 600; font-size: 14px; color: #1f2937; }
.bem-card-role { color: #6b7280; font-weight: 400; font-size: 13px; }
.bem-card-extra { margin-top: 2px; font-size: 12px; color: #6b7280; display: flex; flex-wrap: wrap; gap: 10px; }
.bem-card-count { font-size: 12px; color: #4b5563; background: #e5e7eb; padding: 3px 10px; border-radius: 999px; white-space: nowrap; }

.bem-form { padding: 14px; display: flex; flex-direction: column; gap: 12px; background: #fff; }

.bem-phone-row { display: grid; grid-template-columns: 1fr 1fr 32px; gap: 8px; align-items: center; margin-top: 4px; }
.bem-phone-number, .bem-phone-label { padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; font-family: inherit; background: #fff; color: #1f2937; box-sizing: border-box; width: 100%; }
.bem-phone-number:focus, .bem-phone-label:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
.bem-phone-del { width: 32px; height: 36px; border: 1px solid #e5e7eb; background: #fff; color: #9ca3af; border-radius: 8px; cursor: pointer; font-size: 18px; line-height: 1; }
.bem-phone-del:hover:not(:disabled) { background: #fee2e2; color: #dc2626; border-color: #fecaca; }
.bem-phone-del:disabled { opacity: 0.4; cursor: not-allowed; }
.bem-phone-add { align-self: flex-start; background: none; border: 1px dashed #d1d5db; color: #6b7280; padding: 6px 12px; border-radius: 8px; font-size: 13px; cursor: pointer; margin-top: 6px; }
.bem-phone-add:hover { color: #1f2937; border-color: #9ca3af; background: #f9fafb; }

.bem-rest-tools { display: flex; gap: 6px; }
.bem-mini { padding: 5px 10px; border: 1px solid #d1d5db; background: #fff; color: #374151; border-radius: 6px; cursor: pointer; font-size: 12px; }
.bem-mini:hover { background: #f9fafb; }
.bem-rest-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 4px; padding: 8px; border: 1px solid #e5e7eb; border-radius: 8px; background: #fafafa; max-height: 200px; overflow-y: auto; }
.bem-rest { display: inline-flex; align-items: center; gap: 6px; padding: 4px 8px; border-radius: 6px; cursor: pointer; font-size: 13px; }
.bem-rest:hover { background: #f3f4f6; }
.bem-rest input { width: 16px; height: 16px; cursor: pointer; }
.bem-rest-num { font-weight: 500; color: #1f2937; }
.bem-primary { color: #d97706; }

.bem-form-foot { display: flex; justify-content: flex-end; gap: 8px; }

.bem-foot { padding: 14px 20px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 8px; }
.bem-btn { padding: 9px 18px; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; border: 1px solid transparent; }
.bem-btn--ghost { background: #fff; border-color: #d1d5db; color: #374151; }
.bem-btn--ghost:hover:not(:disabled) { background: #f9fafb; }
.bem-btn--primary { background: #1f2937; color: #fff; }
.bem-btn--primary:hover:not(:disabled) { background: #111827; }
.bem-btn--danger { background: #dc2626; color: #fff; }
.bem-btn--danger:hover:not(:disabled) { background: #b91c1c; }
.bem-btn:disabled { opacity: 0.5; cursor: not-allowed; }

@media (max-width: 700px) {
  .bem-backdrop { padding: 0; align-items: stretch; }
  .bem-modal { border-radius: 0; max-width: none; min-height: 100vh; }
  .bem-row { grid-template-columns: 1fr; }
}
</style>
