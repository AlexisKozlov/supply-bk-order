<template>
  <div class="database-view">
    <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;">
      <h1 class="page-title">База данных</h1>
      <div v-if="activeTab==='products'" style="display:flex;gap:8px;align-items:center;">
        <button v-if="inactiveCount" class="db-active-toggle" :class="{ active: showOnlyActive }" @click="showOnlyActive = !showOnlyActive">
          <span class="db-at-switch"><span class="db-at-knob"></span></span>
          Только активные <span class="db-at-count">{{ inactiveCount }} скрыто</span>
        </button>
        <button v-if="noAnalogCount" class="db-active-toggle" :class="{ active: showNoAnalog }" @click="showNoAnalog = !showNoAnalog">
          <span class="db-at-switch"><span class="db-at-knob"></span></span>
          Без группы аналогов <span class="db-at-count">{{ noAnalogCount }}</span>
        </button>
        <button class="btn primary" @click="openNew('product')" style="font-size:14px;padding:8px 18px;">+ Новый товар</button>
        <button class="btn secondary" @click="showImportModal = true" style="font-size:14px;padding:8px 18px;">Импорт из Excel</button>
      </div>
      <button v-else-if="activeTab==='suppliers'" class="btn primary" @click="openNew('supplier')" style="font-size:14px;padding:8px 18px;">+ Новый поставщик</button>
    </div>

    <!-- Табы -->
    <div class="db-tabs">
      <button class="db-tab" :class="{ active: activeTab==='products' }" @click="activeTab='products'; loadProducts()">
        <BkIcon name="order" size="sm"/> Товары <span class="db-tab-count">{{ products.length }}</span>
      </button>
      <button class="db-tab" :class="{ active: activeTab==='suppliers' }" @click="switchToSuppliers">
        <BkIcon name="factory" size="sm"/> Поставщики <span class="db-tab-count">{{ suppliers.length }}</span>
      </button>
      <button class="db-tab" :class="{ active: activeTab==='analogs' }" @click="switchToAnalogs">
        <BkIcon name="link" size="sm"/> Аналоги <span class="db-tab-count">{{ analogGroups.length }}</span>
      </button>
    </div>

    <!-- Поиск -->
    <div style="position:relative;margin-bottom:14px;">
      <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:14px;pointer-events:none;opacity:0.5;"><BkIcon name="search" size="sm"/></span>
      <input v-model="searchQuery" style="width:100%;padding:9px 36px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:var(--card);box-sizing:border-box;transition:border-color .15s,box-shadow .15s;"
        :placeholder="activeTab === 'products' ? 'Поиск по названию, артикулу, поставщику...' : activeTab === 'suppliers' ? 'Поиск по названию поставщика...' : 'Поиск по названию группы или товара...'"
        @focus="$event.target.style.borderColor='var(--bk-orange)';$event.target.style.boxShadow='0 0 0 3px rgba(245,166,35,0.12)'"
        @blur="$event.target.style.borderColor='var(--border)';$event.target.style.boxShadow='none'" />
      <button v-if="searchQuery" @click="searchQuery=''" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:14px;"><BkIcon name="close" size="xs"/></button>
    </div>

    <!-- Товары -->
    <div v-if="activeTab==='products'">
      <div v-if="loading" style="text-align:center;padding:40px;"><BurgerSpinner text="Загрузка..." /></div>
      <div v-else-if="!filteredProducts.length" style="text-align:center;padding:40px;color:var(--text-muted);">Карточки не найдены</div>
      <div v-else class="db-grid">
        <div v-for="p in filteredProducts" :key="p.id" class="db-card" :class="{ 'db-card-inactive': p.is_active === 0 || p.is_active === '0' }" @click="editProduct(p)">
          <div class="db-card-top">
            <div class="db-card-title">
              <span v-if="p.sku" class="db-card-sku">{{ p.sku }}</span>
              <span class="db-card-name">{{ p.name }}</span>
            </div>
          </div>
          <div class="db-card-meta">
            <span>{{ p.supplier || '—' }}</span>
            <span>{{ p.qty_per_box || '?' }} {{ p.unit_of_measure || 'шт' }}/кор</span>
            <span v-if="p.boxes_per_pallet">{{ p.boxes_per_pallet }}/пал</span>
            <span v-if="p.multiplicity > 1">×{{ p.multiplicity }}</span>
            <span v-if="p.is_active === 0 || p.is_active === '0'" class="db-card-inactive-badge">неактивна</span>
          </div>
          <div class="db-card-btns">
            <button class="db-card-btn" @click.stop="editProduct(p)"><BkIcon name="edit" size="sm"/></button>
            <button class="db-card-btn db-card-btn-del" @click.stop="deleteProduct(p)"><BkIcon name="delete" size="sm"/></button>
          </div>
        </div>
      </div>
    </div>

    <!-- Поставщики -->
    <div v-if="activeTab==='suppliers'">
      <div v-if="loading" style="text-align:center;padding:40px;"><BurgerSpinner text="Загрузка..." /></div>
      <div v-else-if="!filteredSuppliers.length" style="text-align:center;padding:40px;color:var(--text-muted);">Поставщики не найдены</div>
      <div v-else class="db-grid">
        <div v-for="s in filteredSuppliers" :key="s.id" class="db-card" @click="editSupplier(s)">
          <div class="db-card-top">
            <span class="db-card-supplier-name">{{ s.short_name }}</span>
            <span v-if="s.full_name" style="font-size:11px;color:var(--text-muted);margin-left:4px;">{{ s.full_name }}</span>
          </div>
          <div class="db-card-contacts">
            <span class="db-contact wa" :class="{ active: s.whatsapp }">WA</span>
            <span class="db-contact tg" :class="{ active: s.telegram }">TG</span>
            <span class="db-contact vb" :class="{ active: s.viber }">Viber</span>
            <span class="db-contact em" :class="{ active: s.email }">Email</span>
          </div>
          <div class="db-card-btns">
            <button class="db-card-btn" @click.stop="editSupplier(s)"><BkIcon name="edit" size="sm"/></button>
            <button class="db-card-btn db-card-btn-del" @click.stop="deleteSupplier(s)"><BkIcon name="delete" size="sm"/></button>
          </div>
        </div>
      </div>
    </div>

    <!-- Группы аналогов -->
    <div v-if="activeTab==='analogs'">
      <div v-if="loading" style="text-align:center;padding:40px;"><BurgerSpinner text="Загрузка..." /></div>
      <div v-else-if="!filteredAnalogGroups.length" style="text-align:center;padding:40px;color:var(--text-muted);">Группы аналогов не найдены</div>
      <div v-else class="analog-groups">
        <div v-for="group in filteredAnalogGroups" :key="group.name" class="analog-group-card">
          <div class="analog-group-header" @click="toggleGroup(group.name)">
            <div style="display:flex;align-items:center;gap:8px;">
              <BkIcon :name="expandedGroups.has(group.name) ? 'chevronDown' : 'chevronRight'" size="sm"/>
              <span class="analog-group-name">{{ group.name }}</span>
              <span class="db-tab-count">{{ group.items.length }}</span>
            </div>
            <button class="db-card-btn" @click.stop="editAnalogGroup(group)" title="Переименовать группу"><BkIcon name="edit" size="sm"/></button>
          </div>
          <div v-if="expandedGroups.has(group.name)" class="analog-group-items">
            <div v-for="p in group.items" :key="p.id" class="analog-item" @click="editProduct(p)">
              <span v-if="p.sku" class="db-card-sku">{{ p.sku }}</span>
              <span class="db-card-name" style="flex:1;">{{ p.name }}</span>
              <span style="font-size:11px;color:var(--text-muted);">{{ p.supplier || '—' }}</span>
              <button class="db-card-btn db-card-btn-del" @click.stop="removeFromGroup(p)" title="Убрать из группы"><BkIcon name="close" size="xs"/></button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Переименование группы -->
    <Teleport to="body">
      <div v-if="renameModal.show" class="modal" @click.self="renameModal.show = false">
        <div class="modal-box" style="width:380px;">
          <div class="modal-header">
            <h2>Переименовать группу</h2>
            <button class="modal-close" @click="renameModal.show = false"><BkIcon name="close" size="sm"/></button>
          </div>
          <div class="modal-field" style="margin-bottom:12px;">
            <span class="modal-field-label">Название группы</span>
            <input v-model="renameModal.newName" placeholder="Новое название" @keydown.enter="saveRenameGroup" />
          </div>
          <div style="display:flex;gap:8px;">
            <button class="btn primary" @click="saveRenameGroup" :disabled="!renameModal.newName.trim()">Сохранить</button>
            <button class="btn secondary" @click="renameModal.show = false">Отмена</button>
          </div>
        </div>
      </div>
    </Teleport>

    <EditCardModal v-if="editCardModal.show" :product="editCardModal.product" :legal-entity="orderStore.settings.legalEntity" @close="editCardModal.show = false" @saved="onProductSaved" />
    <EditSupplierModal v-if="editSupplierModal.show" :supplier="editSupplierModal.supplier" :legal-entity="orderStore.settings.legalEntity" @close="editSupplierModal.show = false" @saved="onSupplierSaved" />
    <ConfirmModal v-if="confirmModal.show" :title="confirmModal.title" :message="confirmModal.message" @confirm="confirmModal.resolve(true); confirmModal.show = false" @cancel="confirmModal.resolve(false); confirmModal.show = false" />
    <ImportCardsModal v-if="showImportModal" :legal-entity="orderStore.settings.legalEntity" :existing-products="products" @close="showImportModal = false" @saved="onImportSaved" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { db } from '@/lib/apiClient.js';
import { useToastStore } from '@/stores/toastStore.js';
import { useSupplierStore } from '@/stores/supplierStore.js';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import { useOrderStore } from '@/stores/orderStore.js';
import { getEntityGroup } from '@/lib/utils.js';
import EditCardModal from '@/components/modals/EditCardModal.vue';
import EditSupplierModal from '@/components/modals/EditSupplierModal.vue';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';
import ImportCardsModal from '@/components/modals/ImportCardsModal.vue';
import BkIcon from '@/components/ui/BkIcon.vue';


const route = useRoute();
const router = useRouter();
const toast = useToastStore();
const supplierStore = useSupplierStore();
const orderStore = useOrderStore();

const activeTab = ref('products');
const searchQuery = ref('');
const loading = ref(false);
const products = ref([]);
const suppliers = ref([]);
const editCardModal = ref({ show: false, product: null });
const editSupplierModal = ref({ show: false, supplier: null });
const confirmModal = ref({ show: false, title: '', message: '', resolve: null });
const showImportModal = ref(false);
const showOnlyActive = ref(true);
const showNoAnalog = ref(false);
const expandedGroups = ref(new Set());
const renameModal = ref({ show: false, oldName: '', newName: '' });

const filteredProducts = computed(() => {
  const q = searchQuery.value.toLowerCase();
  let list = products.value;
  if (showOnlyActive.value) list = list.filter(p => p.is_active !== 0 && p.is_active !== '0');
  if (showNoAnalog.value) list = list.filter(p => !p.analog_group);
  if (!q) return list;
  return list.filter(p => (p.name||'').toLowerCase().includes(q) || (p.sku||'').toLowerCase().includes(q) || (p.supplier||'').toLowerCase().includes(q));
});
const inactiveCount = computed(() => products.value.filter(p => p.is_active === 0 || p.is_active === '0').length);
const noAnalogCount = computed(() => products.value.filter(p => !p.analog_group).length);
const filteredSuppliers = computed(() => {
  const q = searchQuery.value.toLowerCase();
  if (!q) return suppliers.value;
  return suppliers.value.filter(s => (s.short_name||'').toLowerCase().includes(q) || (s.full_name||'').toLowerCase().includes(q));
});

const analogGroups = computed(() => {
  const map = {};
  for (const p of products.value) {
    if (!p.analog_group) continue;
    if (!map[p.analog_group]) map[p.analog_group] = [];
    map[p.analog_group].push(p);
  }
  return Object.keys(map).sort().map(name => ({ name, items: map[name] }));
});

const filteredAnalogGroups = computed(() => {
  const q = searchQuery.value.toLowerCase();
  if (!q) return analogGroups.value;
  return analogGroups.value.filter(g =>
    g.name.toLowerCase().includes(q) ||
    g.items.some(p => (p.name||'').toLowerCase().includes(q) || (p.sku||'').toLowerCase().includes(q))
  );
});

// Watch route query — handles sidebar "Новый товар" click when already on this page
watch(() => route.query, (q) => {
  if (q?.action === 'new-product') {
    activeTab.value = 'products';
    editCardModal.value = { show: true, product: null };
    router.replace({ name: 'database' });
  }
  if (q?.tab === 'suppliers') {
    activeTab.value = 'suppliers';
    loadSuppliers();
  }
});

watch(() => orderStore.settings.legalEntity, () => {
  if (activeTab.value === 'products') loadProducts();
  else loadSuppliers();
});

onMounted(() => {
  loadProducts();
  loadSuppliers();
  if (route.query.tab === 'suppliers') activeTab.value = 'suppliers';
  if (route.query.action === 'new-product') {
    activeTab.value = 'products';
    editCardModal.value = { show: true, product: null };
    router.replace({ name: 'database' });
  }
});

async function loadProducts() {
  loading.value = true;
  try {
    const { data, error } = await db.from('products').select('*').order('name');
    if (error) { toast.error('Ошибка', ''); return; }
    const group = getEntityGroup(orderStore.settings.legalEntity);
    products.value = (data || []).filter(p => group.includes(p.legal_entity));
  } finally { loading.value = false; }
}

async function switchToSuppliers() { activeTab.value = 'suppliers'; await loadSuppliers(); }

async function loadSuppliers() {
  if (activeTab.value === 'suppliers') loading.value = true;
  try {
    const { data, error } = await db.from('suppliers').select('*').order('short_name');
    if (error) { toast.error('Ошибка', ''); return; }
    const group = getEntityGroup(orderStore.settings.legalEntity);
    suppliers.value = (data || []).filter(s => group.includes(s.legal_entity));
  } finally { loading.value = false; }
}

function openNew(type) {
  if (type === 'product') editCardModal.value = { show: true, product: null };
  else editSupplierModal.value = { show: true, supplier: null };
}
function editProduct(p) { editCardModal.value = { show: true, product: p }; }
function editSupplier(s) { editSupplierModal.value = { show: true, supplier: s }; }

async function deleteProduct(p) {
  const ok = await new Promise(r => { confirmModal.value = { show:true, title:'Удалить карточку?', message:`${p.sku ? p.sku + ' ' : ''}${p.name}`, resolve: r }; });
  if (!ok) return;
  const { error } = await db.from('products').delete().eq('id', p.id);
  if (error) { toast.error('Ошибка', ''); return; }
  toast.success('Удалено', ''); await loadProducts();
}
async function deleteSupplier(s) {
  const ok = await new Promise(r => { confirmModal.value = { show:true, title:'Удалить поставщика?', message: s.short_name, resolve: r }; });
  if (!ok) return;
  const { error } = await db.from('suppliers').delete().eq('id', s.id);
  if (error) { toast.error('Ошибка', ''); return; }
  toast.success('Удалено', ''); supplierStore.invalidate(); await loadSuppliers();
}

function toggleGroup(name) {
  if (expandedGroups.value.has(name)) expandedGroups.value.delete(name);
  else expandedGroups.value.add(name);
}

function editAnalogGroup(group) {
  renameModal.value = { show: true, oldName: group.name, newName: group.name };
}

async function saveRenameGroup() {
  const { oldName, newName } = renameModal.value;
  if (!newName.trim() || newName.trim() === oldName) { renameModal.value.show = false; return; }
  const items = products.value.filter(p => p.analog_group === oldName);
  let hasError = false;
  for (const p of items) {
    const { error } = await db.from('products').update({ analog_group: newName.trim() }).eq('id', p.id);
    if (error) hasError = true;
  }
  if (hasError) { toast.error('Ошибка при переименовании', ''); }
  else {
    toast.success('Группа переименована', `${oldName} → ${newName.trim()}`);
    if (expandedGroups.value.has(oldName)) {
      expandedGroups.value.delete(oldName);
      expandedGroups.value.add(newName.trim());
    }
  }
  renameModal.value.show = false;
  await loadProducts();
}

async function removeFromGroup(p) {
  const ok = await new Promise(r => { confirmModal.value = { show:true, title:'Убрать из группы?', message:`${p.name} будет убран из группы аналогов «${p.analog_group}»`, resolve: r }; });
  if (!ok) return;
  const { error } = await db.from('products').update({ analog_group: null }).eq('id', p.id);
  if (error) { toast.error('Ошибка', ''); return; }
  toast.success('Убрано из группы', '');
  await loadProducts();
}

async function switchToAnalogs() { activeTab.value = 'analogs'; if (!products.value.length) await loadProducts(); }

async function onProductSaved() { editCardModal.value.show = false; await loadProducts(); }
async function onSupplierSaved() { editSupplierModal.value.show = false; supplierStore.invalidate(); await loadSuppliers(); }
async function onImportSaved() { showImportModal.value = false; await loadProducts(); }
</script>

<style scoped>
.db-tabs { display:flex; justify-content:center; gap:0; margin-bottom:14px; border-bottom:2px solid var(--border-light); }
.db-tab { padding:9px 20px; font-size:14px; font-weight:600; color:var(--text-muted); background:none; border:none; border-bottom:2px solid transparent; margin-bottom:-2px; cursor:pointer; transition:all .15s; }
.db-tab.active { color:var(--bk-brown); border-bottom-color:var(--bk-brown); }
.db-tab:hover:not(.active) { color:var(--text); background:rgba(139,115,85,.05); }
.db-tab-count { display:inline-block; background:var(--border-light); color:var(--text-muted); font-size:11px; font-weight:700; padding:1px 7px; border-radius:10px; margin-left:4px; }
.db-tab.active .db-tab-count { background:var(--bk-brown); color:#fff; }
.db-grid { display:flex; flex-direction:column; gap:4px; }
.db-card { background:var(--card); border:1px solid var(--border-light); border-radius:6px; padding:7px 12px; cursor:pointer; transition:border-color .15s; display:flex; align-items:center; gap:10px; }
.db-card:hover { border-color:var(--bk-orange); }
.db-card-top { display:flex; align-items:center; gap:6px; flex:1; min-width:0; }
.db-card-title { display:flex; align-items:baseline; gap:6px; min-width:0; flex:1; }
.db-card-sku { font-size:11px; font-weight:700; color:var(--bk-orange); white-space:nowrap; flex-shrink:0; }
.db-card-name { font-size:13px; font-weight:600; color:var(--text); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.db-card-supplier-name { font-size:14px; font-weight:700; color:var(--text); }
.db-card-meta { display:flex; flex-wrap:nowrap; gap:5px; font-size:10px; color:var(--text-muted); flex-shrink:0; }
.db-card-meta span { background:var(--bg); padding:1px 5px; border-radius:3px; white-space:nowrap; }
.db-card-btns { display:flex; gap:3px; opacity:0; transition:opacity .15s; flex-shrink:0; }
.db-card:hover .db-card-btns { opacity:1; }
.db-card-btn { background:none; border:1px solid var(--border-light); border-radius:5px; padding:2px 5px; cursor:pointer; font-size:11px; transition:all .15s; }
.db-card-btn:hover { background:var(--bg); border-color:var(--border); }
.db-card-btn-del:hover { background:#FFF0F0; border-color:#E57373; }
.db-card-contacts { display:flex; gap:4px; margin-top:4px; }
.db-contact { font-size:10px; font-weight:600; padding:2px 6px; border-radius:4px; background:var(--bg); color:var(--text-muted); border:1px solid transparent; transition: all .15s; }
.db-contact.active { color:#fff; border-color:transparent; }
.db-contact.wa.active { background:#25D366; box-shadow:0 1px 3px rgba(37,211,102,.3); }
.db-contact.tg.active { background:#0088cc; box-shadow:0 1px 3px rgba(0,136,204,.3); }
.db-contact.vb.active { background:#7360f2; box-shadow:0 1px 3px rgba(115,96,242,.3); }
.db-contact.em.active { background:#FF8733; box-shadow:0 1px 3px rgba(255,135,51,.3); }

.analog-groups { display:flex; flex-direction:column; gap:6px; }
.analog-group-card { background:var(--card); border:1px solid var(--border-light); border-radius:8px; overflow:hidden; }
.analog-group-header { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; cursor:pointer; transition:background .15s; }
.analog-group-header:hover { background:rgba(139,115,85,.04); }
.analog-group-name { font-size:14px; font-weight:700; color:var(--text); }
.analog-group-items { border-top:1px solid var(--border-light); }
.analog-item { display:flex; align-items:center; gap:8px; padding:6px 14px 6px 34px; cursor:pointer; transition:background .15s; border-bottom:1px solid var(--border-light); }
.analog-item:last-child { border-bottom:none; }
.analog-item:hover { background:rgba(245,166,35,.04); }
.db-card-inactive { opacity:0.5; }
.db-card-inactive-badge { background:#FFEBEE; color:#E57373; font-weight:600; border:1px solid #E57373; }

/* ═══ Toggle «Только активные» ═══ */
.db-active-toggle {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 5px 12px;
  border-radius: 8px;
  border: 1.5px solid var(--border);
  background: white;
  font-size: 12px;
  font-weight: 600;
  font-family: inherit;
  color: var(--text-muted);
  cursor: pointer;
  transition: all 0.15s;
  white-space: nowrap;
}
.db-active-toggle:hover { border-color: var(--bk-orange); color: var(--text); }
.db-active-toggle.active { border-color: var(--bk-orange); color: var(--bk-brown); background: #FFFBF5; }
.db-at-switch {
  position: relative;
  width: 30px;
  height: 16px;
  border-radius: 8px;
  background: var(--border);
  transition: background 0.2s;
  flex-shrink: 0;
}
.db-active-toggle.active .db-at-switch { background: var(--bk-orange); }
.db-at-knob {
  position: absolute;
  top: 2px;
  left: 2px;
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background: white;
  box-shadow: 0 1px 3px rgba(0,0,0,0.15);
  transition: left 0.2s;
}
.db-active-toggle.active .db-at-knob { left: 16px; }
.db-at-count {
  font-size: 10px;
  font-weight: 500;
  opacity: 0.6;
}
</style>
