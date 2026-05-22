<template>
  <div class="msct">
    <!-- ── Список ресторанов слева, контакты справа ── -->
    <div class="msct-layout">
      <!-- Левая колонка: список ресторанов с фильтрами -->
      <aside class="msct-rests">
        <div class="msct-rests-head">
          <div class="msct-rests-title-row">
            <div class="msct-rests-title">Рестораны</div>
            <div class="msct-bulk-buttons">
              <button class="msct-bulk-btn" type="button" @click="openBulkAdd">+ Массово</button>
              <button class="msct-bulk-btn msct-bulk-btn--warn" type="button" @click="openBulkEdit">✎ Управление</button>
            </div>
          </div>
          <input v-model="restFilter" class="msct-search" type="search" placeholder="Поиск по номеру/адресу..." />
          <div class="msct-rests-tabs">
            <button :class="{ active: groupFilter === 'all' }" @click="groupFilter = 'all'" type="button">Все</button>
            <button :class="{ active: groupFilter === 'BK_VM' }" @click="groupFilter = 'BK_VM'" type="button">БК+ВМ</button>
            <button :class="{ active: groupFilter === 'PS' }" @click="groupFilter = 'PS'" type="button">ПС</button>
          </div>
        </div>
        <div v-if="loadingList" class="msct-state">Загружаем...</div>
        <div v-else-if="listError" class="msct-state msct-state--error">{{ listError }}</div>
        <div v-else class="msct-rests-list">
          <button
            v-for="r in filteredRestaurants"
            :key="r.id"
            type="button"
            class="msct-rest"
            :class="{ active: selectedRestId === r.id }"
            @click="selectRestaurant(r)"
          >
            <div class="msct-rest-num">{{ formatRestNumber(r.number, r.legal_entity_group) }}</div>
            <div class="msct-rest-meta">
              <div class="msct-rest-addr">{{ r.city || '' }}{{ r.address ? ', ' + r.address : '' }}</div>
              <div class="msct-rest-counts">
                <span v-if="r.contacts_count > 0" class="msct-count msct-count--ok">{{ r.contacts_count }} конт.</span>
                <span v-else class="msct-count msct-count--empty">Пусто</span>
              </div>
            </div>
          </button>
        </div>
      </aside>

      <!-- Правая колонка: контакты выбранного ресторана -->
      <section class="msct-detail">
        <div v-if="!selectedRestId" class="msct-detail-empty">
          Выберите ресторан слева, чтобы посмотреть и отредактировать его контакты поставщиков.
        </div>

        <template v-else>
          <div class="msct-detail-head">
            <div>
              <div class="msct-detail-title">Контакты ресторана {{ formatRestNumber(selectedRest.number, selectedRest.legal_entity_group) }}</div>
              <div class="msct-detail-sub">{{ selectedRest.city || '' }}{{ selectedRest.address ? ', ' + selectedRest.address : '' }}</div>
            </div>
            <div class="msct-detail-tools">
              <input v-model="supplierFilter" type="search" class="msct-supplier-search" placeholder="Поиск поставщика..." />
              <button class="msct-btn msct-btn--ghost" type="button" @click="loadGroups" :disabled="loadingDetail">
                {{ loadingDetail ? 'Обновляем...' : 'Обновить' }}
              </button>
            </div>
          </div>

          <div v-if="detailError" class="msct-error">{{ detailError }}</div>

          <div v-if="loadingDetail && !groups.length" class="msct-state">Загружаем контакты...</div>

          <div v-else-if="!filteredGroups.length" class="msct-state">По запросу «{{ supplierFilter }}» поставщиков не найдено.</div>

          <div v-else class="msct-groups">
            <details v-for="g in filteredGroups" :key="g.key" class="msct-group" :open="g.contacts.length > 0 || !!supplierFilter">
              <summary class="msct-group-head">
                <div class="msct-group-title">
                  <span class="msct-group-name">{{ g.title }}</span>
                  <span class="msct-group-count">{{ g.contacts.length }}</span>
                </div>
                <button class="msct-btn msct-btn--add" type="button" @click.prevent="openCreate(g)">+ Добавить</button>
              </summary>
              <div class="msct-cards">
                <div v-if="!g.contacts.length" class="msct-cards-empty">Контактов пока нет.</div>
                <SupplierContactCard
                  v-for="c in g.contacts"
                  :key="c.id"
                  :contact="c"
                  :show-actions="true"
                  @edit="openEdit(g, c)"
                  @delete="askDelete(c)"
                />
              </div>
            </details>
          </div>
        </template>
      </section>
    </div>

    <SupplierContactEditModal
      v-if="modal.open"
      :restaurant="selectedRest"
      :contact="modal.contact"
      :kind="modal.kind"
      :supplier-id="modal.supplierId"
      :entity-group="modal.entityGroup"
      :scope-title="modal.scopeTitle"
      @saved="onSaved"
      @close="modal.open = false"
    />

    <SupplierContactBulkAddModal
      v-if="bulkOpen"
      :restaurants="restaurants"
      @saved="onBulkSaved"
      @close="bulkOpen = false"
    />

    <SupplierContactBulkEditModal
      v-if="bulkEditOpen"
      @updated="onBulkSaved"
      @deleted="onBulkSaved"
      @close="bulkEditOpen = false"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import SupplierContactCard from '@/components/restaurant/SupplierContactCard.vue';
import SupplierContactEditModal from '@/components/modals/SupplierContactEditModal.vue';
import SupplierContactBulkAddModal from '@/components/modals/SupplierContactBulkAddModal.vue';
import SupplierContactBulkEditModal from '@/components/modals/SupplierContactBulkEditModal.vue';
import { formatRestaurantNumber } from '@/lib/legalEntities.js';

const restaurants = ref([]);
const loadingList = ref(false);
const listError = ref('');

const restFilter = ref('');
const groupFilter = ref('all');

const selectedRestId = ref(null);
const selectedRest = ref(null);

const groups = ref([]);
const loadingDetail = ref(false);
const detailError = ref('');

const modal = reactive({ open: false, contact: null, kind: 'external', supplierId: null, entityGroup: null, scopeTitle: '' });
const bulkOpen = ref(false);
const bulkEditOpen = ref(false);
const supplierFilter = ref('');

const filteredGroups = computed(() => {
  const q = supplierFilter.value.trim().toLowerCase();
  if (!q) return groups.value;
  return groups.value.filter(g => (g.title || '').toLowerCase().includes(q));
});

function openBulkAdd() {
  bulkOpen.value = true;
}
function openBulkEdit() {
  bulkEditOpen.value = true;
}

async function onBulkSaved(res) {
  // Обновляем список ресторанов (счётчики) и при необходимости открытый ресторан
  const overview = await apiGet('/api/restaurant-supplier-contacts/manager-overview').catch(() => null);
  if (overview?.restaurants) restaurants.value = overview.restaurants;
  if (selectedRestId.value) await loadGroups();
}

function formatRestNumber(num, group) {
  return formatRestaurantNumber(num, group);
}

function authHeaders(extra = {}) {
  const h = { ...extra };
  const t = localStorage.getItem('bk_session_token') || '';
  if (t) h['X-Session-Token'] = t;
  return h;
}

async function apiGet(url) {
  const res = await fetch(url, { headers: authHeaders() });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || `Ошибка сервера (${res.status})`);
  return data;
}

async function apiPost(url, body) {
  const res = await fetch(url, {
    method: 'POST',
    headers: authHeaders({ 'Content-Type': 'application/json' }),
    body: JSON.stringify(body || {}),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || `Ошибка сервера (${res.status})`);
  return data;
}

const filteredRestaurants = computed(() => {
  const q = restFilter.value.trim().toLowerCase();
  return restaurants.value.filter(r => {
    if (groupFilter.value !== 'all' && r.legal_entity_group !== groupFilter.value) return false;
    if (!q) return true;
    const hay = [r.number, r.city, r.address, r.region, formatRestNumber(r.number, r.legal_entity_group)].join(' ').toLowerCase();
    return hay.includes(q);
  });
});

async function loadList() {
  loadingList.value = true;
  listError.value = '';
  try {
    const data = await apiGet('/api/restaurant-supplier-contacts/manager-overview');
    restaurants.value = data.restaurants || [];
  } catch (e) {
    listError.value = e.message || 'Не удалось загрузить список ресторанов';
  } finally {
    loadingList.value = false;
  }
}

async function loadGroups() {
  if (!selectedRestId.value) return;
  loadingDetail.value = true;
  detailError.value = '';
  try {
    const data = await apiGet(`/api/restaurant-supplier-contacts/list?restaurant_id=${selectedRestId.value}`);
    groups.value = data.groups || [];
  } catch (e) {
    detailError.value = e.message || 'Не удалось загрузить контакты';
  } finally {
    loadingDetail.value = false;
  }
}

async function selectRestaurant(r) {
  selectedRestId.value = r.id;
  selectedRest.value = r;
  groups.value = [];
  await loadGroups();
}

function openCreate(group) {
  modal.contact = null;
  modal.kind = group.kind;
  modal.supplierId = group.supplier_id;
  modal.entityGroup = group.entity_group;
  modal.scopeTitle = group.title;
  modal.open = true;
}

function openEdit(group, contact) {
  modal.contact = contact;
  modal.kind = group.kind;
  modal.supplierId = group.supplier_id;
  modal.entityGroup = group.entity_group;
  modal.scopeTitle = group.title;
  modal.open = true;
}

async function onSaved() {
  modal.open = false;
  await loadGroups();
  // Обновим счётчик в списке ресторанов
  const r = restaurants.value.find(x => x.id === selectedRestId.value);
  if (r) {
    const overview = await apiGet('/api/restaurant-supplier-contacts/manager-overview').catch(() => null);
    if (overview?.restaurants) restaurants.value = overview.restaurants;
  }
}

async function askDelete(contact) {
  if (!window.confirm(`Удалить контакт «${contact.name}»?`)) return;
  try {
    await apiPost('/api/restaurant-supplier-contacts/delete', { id: contact.id });
    await loadGroups();
    const overview = await apiGet('/api/restaurant-supplier-contacts/manager-overview').catch(() => null);
    if (overview?.restaurants) restaurants.value = overview.restaurants;
  } catch (e) {
    alert(e.message || 'Не удалось удалить контакт');
  }
}

onMounted(loadList);
</script>

<style scoped>
.msct {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.msct-layout {
  display: grid;
  grid-template-columns: 320px 1fr;
  gap: 16px;
  align-items: flex-start;
}
.msct-rests {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  display: flex;
  flex-direction: column;
  max-height: calc(100vh - 200px);
  position: sticky;
  top: 16px;
}
.msct-rests-head {
  padding: 12px 12px 8px;
  border-bottom: 1px solid #e5e7eb;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.msct-rests-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.msct-rests-title {
  font-size: 14px;
  font-weight: 600;
  color: #1f2937;
}
.msct-bulk-buttons { display: flex; gap: 4px; }
.msct-bulk-btn {
  padding: 5px 10px;
  border: 1px solid #1f2937;
  background: #1f2937;
  color: #fff;
  border-radius: 6px;
  font-size: 12px;
  cursor: pointer;
  white-space: nowrap;
}
.msct-bulk-btn:hover { background: #111827; }
.msct-bulk-btn--warn { background: #fff; color: #b45309; border-color: #fde68a; }
.msct-bulk-btn--warn:hover { background: #fffbeb; }
.msct-bulk-btn--danger { background: #fff; color: #b91c1c; border-color: #fecaca; }
.msct-bulk-btn--danger:hover { background: #fef2f2; }
.msct-search {
  width: 100%;
  padding: 7px 10px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  font-size: 13px;
  box-sizing: border-box;
}
.msct-rests-tabs {
  display: flex;
  gap: 4px;
}
.msct-rests-tabs button {
  flex: 1;
  padding: 5px 8px;
  border: 1px solid #d1d5db;
  background: #fff;
  border-radius: 6px;
  font-size: 12px;
  cursor: pointer;
  color: #4b5563;
}
.msct-rests-tabs button.active {
  background: #1f2937;
  color: #fff;
  border-color: #1f2937;
}
.msct-rests-list {
  overflow-y: auto;
  flex: 1;
  padding: 6px;
}
.msct-rest {
  width: 100%;
  text-align: left;
  padding: 8px 10px;
  border: none;
  background: transparent;
  border-radius: 8px;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.msct-rest:hover { background: #f3f4f6; }
.msct-rest.active { background: #eff6ff; }
.msct-rest.active .msct-rest-num { color: #1d4ed8; }
.msct-rest-num {
  font-size: 14px;
  font-weight: 600;
  color: #1f2937;
}
.msct-rest-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.msct-rest-addr {
  font-size: 12px;
  color: #6b7280;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  flex: 1;
}
.msct-count {
  font-size: 11px;
  padding: 2px 7px;
  border-radius: 999px;
  white-space: nowrap;
}
.msct-count--ok { background: #d1fae5; color: #065f46; }
.msct-count--empty { background: #f3f4f6; color: #9ca3af; }

.msct-detail {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-width: 0;
}
.msct-detail-empty {
  background: #fff;
  border: 1px dashed #d1d5db;
  border-radius: 12px;
  padding: 40px 20px;
  text-align: center;
  color: #6b7280;
}
.msct-detail-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  flex-wrap: wrap;
}
.msct-detail-tools {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
}
.msct-supplier-search {
  padding: 7px 10px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  font-size: 13px;
  width: 220px;
  max-width: 100%;
}
.msct-supplier-search:focus {
  outline: none;
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}
.msct-detail-title {
  font-size: 16px;
  font-weight: 700;
  color: #1f2937;
}
.msct-detail-sub {
  margin-top: 2px;
  font-size: 13px;
  color: #6b7280;
}

.msct-state { padding: 24px; text-align: center; color: #6b7280; }
.msct-state--error { color: #b91c1c; }
.msct-error {
  background: #fef2f2;
  border: 1px solid #fecaca;
  color: #b91c1c;
  padding: 10px 14px;
  border-radius: 8px;
}

.msct-groups {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
  gap: 10px;
  align-items: start;
}
.msct-group {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  overflow: hidden;
}
.msct-group-head {
  list-style: none;
  cursor: pointer;
  padding: 12px 14px;
  background: #f9fafb;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  border-bottom: 1px solid #e5e7eb;
}
.msct-group-head::-webkit-details-marker { display: none; }
.msct-group-title { display: flex; align-items: center; gap: 8px; }
.msct-group-name { font-weight: 600; font-size: 15px; color: #1f2937; }
.msct-group-count {
  font-size: 11px;
  padding: 2px 8px;
  background: #e5e7eb;
  color: #4b5563;
  border-radius: 999px;
}
.msct-cards {
  padding: 10px;
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
.msct-cards > * {
  flex: 1 1 100%;
  max-width: 100%;
}
.msct-cards-empty {
  flex: 1 1 100%;
  text-align: center;
  color: #9ca3af;
  font-size: 13px;
  padding: 16px;
}

.msct-btn {
  padding: 7px 14px;
  border-radius: 7px;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  border: 1px solid transparent;
}
.msct-btn--ghost {
  background: #fff;
  border-color: #d1d5db;
  color: #374151;
}
.msct-btn--ghost:hover:not(:disabled) { background: #f9fafb; }
.msct-btn--ghost:disabled { opacity: 0.5; cursor: progress; }
.msct-btn--add {
  background: #ecfdf5;
  border-color: #a7f3d0;
  color: #065f46;
}
.msct-btn--add:hover { background: #d1fae5; }

@media (max-width: 900px) {
  .msct-layout { grid-template-columns: 1fr; }
  .msct-rests { position: static; max-height: 320px; }
  .msct-groups { grid-template-columns: 1fr; }
}
</style>
