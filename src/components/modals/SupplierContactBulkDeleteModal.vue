<template>
  <div class="bdm-backdrop" @click.self="close">
    <div class="bdm-modal">
      <div class="bdm-head">
        <div>
          <h3 class="bdm-title">Массовое удаление контактов</h3>
          <div class="bdm-sub">Выберите поставщика — увидите всех его людей по всем ресторанам. Снимите/поставьте галочки и удалите ненужных.</div>
        </div>
        <button class="bdm-x" type="button" @click="close" aria-label="Закрыть">×</button>
      </div>

      <div class="bdm-body">
        <div v-if="error" class="bdm-error">{{ error }}</div>
        <div v-if="result" class="bdm-success">Удалено: {{ result.deleted }}.</div>

        <!-- Выбор поставщика -->
        <section class="bdm-section">
          <div class="bdm-section-title">1. Поставщик</div>
          <div class="bdm-group">
            <label><input type="radio" v-model="form.kind" value="external" @change="onKindChange" /> Внешний поставщик</label>
            <label><input type="radio" v-model="form.kind" value="internal" @change="onKindChange" /> Свой склад</label>
          </div>
          <div class="bdm-row">
            <label class="bdm-field">
              <span>Группа юрлиц</span>
              <select v-model="form.group" @change="reload">
                <option value="BK_VM">БК + Воглия Матта</option>
                <option value="PS">Пицца Стар</option>
              </select>
            </label>
            <label v-if="form.kind === 'external'" class="bdm-field">
              <span>Поставщик</span>
              <select v-model="form.supplierId" :disabled="loadingSuppliers" @change="reload">
                <option value="">— выберите поставщика —</option>
                <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.short_name || s.full_name }}</option>
              </select>
            </label>
          </div>
        </section>

        <!-- Найденные контакты -->
        <section class="bdm-section">
          <div class="bdm-section-title">2. Найденные контакты ({{ selectedCount }} выбрано из {{ totalContacts }})</div>

          <div v-if="loadingContacts" class="bdm-state">Ищем контакты...</div>
          <div v-else-if="!hasQuery" class="bdm-state">Выберите поставщика выше.</div>
          <div v-else-if="!groupedContacts.length" class="bdm-state">У этого поставщика нет контактов ни в одном ресторане.</div>

          <div v-else class="bdm-list">
            <div class="bdm-tools">
              <button class="bdm-mini" type="button" @click="selectAll">Все</button>
              <button class="bdm-mini" type="button" @click="selectNone">Никого</button>
            </div>

            <details v-for="g in groupedContacts" :key="g.key" class="bdm-card">
              <summary class="bdm-card-head">
                <label class="bdm-card-check" @click.stop>
                  <input
                    type="checkbox"
                    :checked="groupAllChecked(g)"
                    :indeterminate.prop="groupSomeChecked(g) && !groupAllChecked(g)"
                    @change="toggleGroup(g, $event.target.checked)"
                  />
                </label>
                <div class="bdm-card-info">
                  <div class="bdm-card-name">
                    {{ g.name || '(без имени)' }}
                    <span v-if="g.role" class="bdm-card-role">· {{ g.role }}</span>
                  </div>
                  <div class="bdm-card-extra">
                    <span v-if="g.phone">{{ formatPhone(g.phone) }}</span>
                    <span v-if="g.telegram">{{ g.telegram.startsWith('+') ? 'TG: ' + formatPhone(g.telegram) : '@' + g.telegram }}</span>
                    <span v-if="g.email">{{ g.email }}</span>
                  </div>
                </div>
                <div class="bdm-card-count">в {{ g.contacts.length }} ресторан{{ ruEnding(g.contacts.length) }}</div>
              </summary>
              <div class="bdm-card-rests">
                <label v-for="c in g.contacts" :key="c.id" class="bdm-rest">
                  <input type="checkbox" :value="c.id" v-model="selectedIds" />
                  <span class="bdm-rest-num">{{ formatRestNum(c.restaurant_number, c.legal_entity_group) }}</span>
                  <span v-if="c.is_primary" class="bdm-primary" title="Основной">★</span>
                </label>
              </div>
            </details>
          </div>
        </section>
      </div>

      <div class="bdm-foot">
        <button class="bdm-btn bdm-btn--ghost" type="button" @click="close" :disabled="saving">Закрыть</button>
        <button class="bdm-btn bdm-btn--danger" type="button" @click="confirmDelete" :disabled="!selectedCount || saving">
          {{ saving ? 'Удаляем...' : `Удалить ${selectedCount} контакт${ruEnding(selectedCount)}` }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { formatRestaurantNumber } from '@/lib/legalEntities.js';
import { appConfirm } from '@/lib/appDialogs.js';

const emit = defineEmits(['deleted', 'close']);

const error = ref('');
const result = ref(null);
const saving = ref(false);

const form = reactive({
  kind: 'external',
  group: 'BK_VM',
  supplierId: '',
});

const suppliers = ref([]);
const loadingSuppliers = ref(false);

const contacts = ref([]);
const loadingContacts = ref(false);
const selectedIds = ref([]);

const hasQuery = computed(() => form.kind === 'internal' || !!form.supplierId);
const totalContacts = computed(() => contacts.value.length);
const selectedCount = computed(() => selectedIds.value.length);

const groupedContacts = computed(() => {
  // Группируем по ключу = name | phone | telegram | email (одинаковые карточки в разных ресторанах)
  const map = new Map();
  for (const c of contacts.value) {
    const key = [c.name || '', c.phone || '', c.telegram || '', c.email || ''].join('|');
    if (!map.has(key)) {
      map.set(key, {
        key,
        name: c.name || '',
        role: c.role || '',
        phone: c.phone || '',
        telegram: c.telegram || '',
        email: c.email || '',
        contacts: [],
      });
    }
    map.get(key).contacts.push(c);
  }
  return Array.from(map.values()).sort((a, b) => b.contacts.length - a.contacts.length);
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
  } catch (e) {
    error.value = e.message || 'Не удалось загрузить поставщиков';
  } finally {
    loadingSuppliers.value = false;
  }
}

async function reload() {
  selectedIds.value = [];
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
  selectedIds.value = [];
  contacts.value = [];
  if (form.kind === 'external') loadSuppliers();
  else reload();
}

function groupAllChecked(g) {
  return g.contacts.length > 0 && g.contacts.every(c => selectedIds.value.includes(c.id));
}
function groupSomeChecked(g) {
  return g.contacts.some(c => selectedIds.value.includes(c.id));
}
function toggleGroup(g, on) {
  const ids = g.contacts.map(c => c.id);
  if (on) {
    const set = new Set(selectedIds.value);
    ids.forEach(id => set.add(id));
    selectedIds.value = Array.from(set);
  } else {
    selectedIds.value = selectedIds.value.filter(id => !ids.includes(id));
  }
}
function selectAll() {
  selectedIds.value = contacts.value.map(c => c.id);
}
function selectNone() {
  selectedIds.value = [];
}

function formatRestNum(num, group) {
  return formatRestaurantNumber(num, group);
}
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
  if (saving.value) return;
  emit('close');
}

async function confirmDelete() {
  if (!selectedCount.value || saving.value) return;
  const n = selectedCount.value;
  if (!(await appConfirm(`Удалить ${n} контакт${ruEnding(n)}? Это действие нельзя отменить.`, { okText: 'Удалить', danger: true }))) return;
  saving.value = true;
  error.value = '';
  result.value = null;
  try {
    const res = await fetch('/api/restaurant-supplier-contacts/bulk-delete', {
      method: 'POST',
      headers: authHeaders({ 'Content-Type': 'application/json' }),
      body: JSON.stringify({ ids: selectedIds.value }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Ошибка удаления');
    result.value = data;
    emit('deleted', data);
    // Подгружаем заново — оставшиеся
    selectedIds.value = [];
    await reload();
  } catch (e) {
    error.value = e.message || 'Ошибка удаления';
  } finally {
    saving.value = false;
  }
}

onMounted(() => loadSuppliers());
</script>

<style scoped>
.bdm-backdrop {
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
.bdm-modal {
  background: #fff;
  border-radius: 14px;
  width: 100%;
  max-width: 720px;
  display: flex;
  flex-direction: column;
  box-shadow: 0 20px 50px rgba(0,0,0,0.25);
}
.bdm-head {
  padding: 18px 20px;
  border-bottom: 1px solid #e5e7eb;
  display: flex;
  justify-content: space-between;
  gap: 12px;
}
.bdm-title { margin: 0; font-size: 18px; font-weight: 700; color: #1f2937; }
.bdm-sub { margin-top: 4px; font-size: 13px; color: #6b7280; max-width: 600px; }
.bdm-x {
  background: none; border: none; font-size: 28px; line-height: 1;
  color: #9ca3af; cursor: pointer; padding: 0; width: 32px; height: 32px;
}
.bdm-x:hover { color: #1f2937; }

.bdm-body { padding: 18px 20px; display: flex; flex-direction: column; gap: 16px; }
.bdm-section { display: flex; flex-direction: column; gap: 10px; }
.bdm-section-title {
  font-size: 13px;
  font-weight: 600;
  color: #6b7280;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.bdm-group { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end; }
.bdm-group label:not(.bdm-field) {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 14px; color: #1f2937; cursor: pointer;
}
.bdm-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.bdm-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; flex: 1; }
.bdm-field > span { font-size: 13px; color: #374151; font-weight: 500; }
.bdm-field select {
  width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px;
  font-size: 14px; background: #fff; color: #1f2937; box-sizing: border-box;
}
.bdm-field select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }

.bdm-error {
  background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c;
  padding: 10px 12px; border-radius: 8px; font-size: 13px;
}
.bdm-success {
  background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46;
  padding: 10px 12px; border-radius: 8px; font-size: 13px;
}
.bdm-state { padding: 16px; text-align: center; color: #6b7280; font-size: 13px; }

.bdm-list { display: flex; flex-direction: column; gap: 8px; }
.bdm-tools { display: flex; gap: 6px; }
.bdm-mini {
  padding: 5px 10px; border: 1px solid #d1d5db; background: #fff; color: #374151;
  border-radius: 6px; cursor: pointer; font-size: 12px;
}
.bdm-mini:hover { background: #f9fafb; }

.bdm-card {
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  overflow: hidden;
  background: #fff;
}
.bdm-card-head {
  list-style: none;
  cursor: pointer;
  padding: 10px 12px;
  display: grid;
  grid-template-columns: 24px 1fr auto;
  gap: 10px;
  align-items: center;
  background: #f9fafb;
}
.bdm-card-head::-webkit-details-marker { display: none; }
.bdm-card[open] .bdm-card-head { background: #f3f4f6; }
.bdm-card-check { display: flex; align-items: center; }
.bdm-card-check input { width: 18px; height: 18px; cursor: pointer; }
.bdm-card-info { min-width: 0; }
.bdm-card-name { font-weight: 600; font-size: 14px; color: #1f2937; }
.bdm-card-role { color: #6b7280; font-weight: 400; font-size: 13px; }
.bdm-card-extra {
  margin-top: 2px;
  font-size: 12px;
  color: #6b7280;
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
.bdm-card-count {
  font-size: 12px;
  color: #4b5563;
  background: #e5e7eb;
  padding: 3px 10px;
  border-radius: 999px;
  white-space: nowrap;
}

.bdm-card-rests {
  padding: 8px 12px 12px 46px;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
  gap: 6px;
}
.bdm-rest {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 5px 8px; border-radius: 6px; cursor: pointer; font-size: 13px;
}
.bdm-rest:hover { background: #f3f4f6; }
.bdm-rest input { width: 16px; height: 16px; cursor: pointer; }
.bdm-rest-num { font-weight: 500; color: #1f2937; }
.bdm-primary { color: #d97706; }

.bdm-foot {
  padding: 14px 20px;
  border-top: 1px solid #e5e7eb;
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
.bdm-btn { padding: 9px 18px; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; border: 1px solid transparent; }
.bdm-btn--ghost { background: #fff; border-color: #d1d5db; color: #374151; }
.bdm-btn--ghost:hover:not(:disabled) { background: #f9fafb; }
.bdm-btn--danger { background: #dc2626; color: #fff; }
.bdm-btn--danger:hover:not(:disabled) { background: #b91c1c; }
.bdm-btn:disabled { opacity: 0.5; cursor: not-allowed; }

@media (max-width: 700px) {
  .bdm-backdrop { padding: 0; align-items: stretch; }
  .bdm-modal { border-radius: 0; max-width: none; min-height: 100vh; }
  .bdm-row { grid-template-columns: 1fr; }
}
</style>
