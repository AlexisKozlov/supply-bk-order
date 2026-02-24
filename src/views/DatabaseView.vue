<template>
  <div class="database-view">
    <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;">
      <h1 class="page-title">База данных</h1>
      <button v-if="activeTab==='products'" class="btn primary" @click="openNew('product')" style="font-size:14px;padding:8px 18px;">+ Новый товар</button>
      <button v-else class="btn primary" @click="openNew('supplier')" style="font-size:14px;padding:8px 18px;">+ Новый поставщик</button>
    </div>

    <!-- Табы -->
    <div class="db-tabs">
      <button class="db-tab" :class="{ active: activeTab==='products' }" @click="activeTab='products'; loadProducts()">
        <BkIcon name="order" size="sm"/> Товары <span class="db-tab-count">{{ products.length }}</span>
      </button>
      <button class="db-tab" :class="{ active: activeTab==='suppliers' }" @click="switchToSuppliers">
        <BkIcon name="factory" size="sm"/> Поставщики <span class="db-tab-count">{{ suppliers.length }}</span>
      </button>
    </div>

    <!-- Поиск -->
    <div style="position:relative;margin-bottom:14px;">
      <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:14px;pointer-events:none;opacity:0.5;"><BkIcon name="search" size="sm"/></span>
      <input v-model="searchQuery" style="width:100%;padding:9px 36px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:var(--card);box-sizing:border-box;transition:border-color .15s,box-shadow .15s;"
        :placeholder="activeTab === 'products' ? 'Поиск по названию, артикулу, поставщику...' : 'Поиск по названию поставщика...'"
        @focus="$event.target.style.borderColor='var(--bk-orange)';$event.target.style.boxShadow='0 0 0 3px rgba(245,166,35,0.12)'"
        @blur="$event.target.style.borderColor='var(--border)';$event.target.style.boxShadow='none'" />
      <button v-if="searchQuery" @click="searchQuery=''" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:14px;"><BkIcon name="close" size="xs"/></button>
    </div>

    <!-- Товары -->
    <div v-if="activeTab==='products'">
      <div v-if="loading" style="text-align:center;padding:40px;"><BurgerSpinner text="Загрузка..." /></div>
      <div v-else-if="!filteredProducts.length" style="text-align:center;padding:40px;color:var(--text-muted);">Карточки не найдены</div>
      <div v-else class="db-grid">
        <div v-for="p in filteredProducts" :key="p.id" class="db-card" @click="editProduct(p)">
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

    <EditCardModal v-if="editCardModal.show" :product="editCardModal.product" :legal-entity="orderStore.settings.legalEntity" @close="editCardModal.show = false" @saved="onProductSaved" />
    <EditSupplierModal v-if="editSupplierModal.show" :supplier="editSupplierModal.supplier" :legal-entity="orderStore.settings.legalEntity" @close="editSupplierModal.show = false" @saved="onSupplierSaved" />
    <ConfirmModal v-if="confirmModal.show" :title="confirmModal.title" :message="confirmModal.message" @confirm="confirmModal.resolve(true); confirmModal.show = false" @cancel="confirmModal.resolve(false); confirmModal.show = false" />
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

const filteredProducts = computed(() => {
  const q = searchQuery.value.toLowerCase();
  if (!q) return products.value;
  return products.value.filter(p => (p.name||'').toLowerCase().includes(q) || (p.sku||'').toLowerCase().includes(q) || (p.supplier||'').toLowerCase().includes(q));
});
const filteredSuppliers = computed(() => {
  const q = searchQuery.value.toLowerCase();
  if (!q) return suppliers.value;
  return suppliers.value.filter(s => (s.short_name||'').toLowerCase().includes(q) || (s.full_name||'').toLowerCase().includes(q));
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

async function onProductSaved() { editCardModal.value.show = false; await loadProducts(); }
async function onSupplierSaved() { editSupplierModal.value.show = false; supplierStore.invalidate(); await loadSuppliers(); }
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
</style>
