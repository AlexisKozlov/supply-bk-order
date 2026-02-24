<template>
  <div class="suppliers-view">
    <div class="page-header">
      <h1 class="page-title">Поставщики</h1>
      <button class="btn small primary" @click="openNew">+ Новый поставщик</button>
    </div>

    <!-- Фильтр -->
    <div style="display:flex;gap:12px;align-items:center;margin-bottom:12px;flex-wrap:wrap;">
      <div style="position:relative;">
        <input v-model="searchQuery" placeholder="Поиск по названию..." style="min-width:260px;padding-right:28px;" />
        <button v-if="searchQuery" @click="searchQuery=''" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;"><BkIcon name="close" size="xs"/></button>
      </div>
    </div>

    <div v-if="loading" style="text-align:center;padding:40px;"><div class="loading-spinner"></div></div>
    <div v-else-if="!filtered.length" style="text-align:center;padding:40px;color:var(--text-muted);">Поставщики не найдены</div>
    <div v-else class="db-list">
      <div v-for="s in filtered" :key="s.id" class="supplier-card">
        <div class="supplier-card-info">
          <div class="supplier-card-name">{{ s.short_name }}</div>
          <div v-if="s.full_name" style="font-size:12px;color:#888;">{{ s.full_name }}</div>
          <div style="display:flex;gap:6px;margin-top:4px;">
            <span class="supplier-contact-badge" :class="s.whatsapp ? 'filled-whatsapp' : ''">WA</span>
            <span class="supplier-contact-badge" :class="s.telegram ? 'filled-telegram' : ''">TG</span>
            <span class="supplier-contact-badge" :class="s.viber ? 'filled-viber' : ''">Viber</span>
            <span class="supplier-contact-badge" :class="s.email ? 'filled-email' : ''">Email</span>
          </div>
          <div style="margin-top:4px;font-size:11px;color:var(--text-muted);">
            Товаров: {{ productCounts[s.short_name] || 0 }}
          </div>
        </div>
        <div class="supplier-card-actions">
          <button class="btn small" @click="edit(s)"><BkIcon name="edit" size="sm"/></button>
          <button class="btn small" style="background:var(--error);color:#fff;" @click="del(s)"><BkIcon name="delete" size="sm"/></button>
        </div>
      </div>
    </div>

    <EditSupplierModal v-if="modal.show" :supplier="modal.supplier" :legal-entity="orderStore.settings.legalEntity"
      @close="modal.show = false" @saved="onSaved"/>
    <ConfirmModal v-if="confirmModal.show" :title="confirmModal.title" :message="confirmModal.message"
      @confirm="confirmModal.resolve(true); confirmModal.show = false"
      @cancel="confirmModal.resolve(false); confirmModal.show = false"/>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { db } from '@/lib/apiClient.js';
import { useToastStore } from '@/stores/toastStore.js';
import { useSupplierStore } from '@/stores/supplierStore.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { getEntityGroup } from '@/lib/utils.js';
import EditSupplierModal from '@/components/modals/EditSupplierModal.vue';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';
import BkIcon from '@/components/ui/BkIcon.vue';


const toast = useToastStore();
const supplierStore = useSupplierStore();
const orderStore = useOrderStore();

const searchQuery = ref('');
const loading = ref(false);
const suppliers = ref([]);
const productCounts = ref({});
const modal = ref({ show: false, supplier: null });
const confirmModal = ref({ show: false, title: '', message: '', resolve: null });

const filtered = computed(() => {
  const q = searchQuery.value.toLowerCase();
  if (!q) return suppliers.value;
  return suppliers.value.filter(s =>
    (s.short_name || '').toLowerCase().includes(q) ||
    (s.full_name || '').toLowerCase().includes(q)
  );
});

// Перезагружать при смене юр. лица
watch(() => orderStore.settings.legalEntity, () => load());

onMounted(() => load());

async function load() {
  loading.value = true;
  try {
    const { data, error } = await db.from('suppliers').select('*').order('short_name');
    if (error) { toast.error('Ошибка', ''); return; }
    const group = getEntityGroup(orderStore.settings.legalEntity);
    suppliers.value = (data || []).filter(s => group.includes(s.legal_entity));

    // Подсчёт товаров у каждого поставщика
    const { data: prods } = await db.from('products').select('supplier');
    const counts = {};
    (prods || []).forEach(p => { if (p.supplier) counts[p.supplier] = (counts[p.supplier] || 0) + 1; });
    productCounts.value = counts;
  } finally { loading.value = false; }
}

function openNew() { modal.value = { show: true, supplier: null }; }
function edit(s) { modal.value = { show: true, supplier: s }; }

async function del(s) {
  const ok = await new Promise(resolve => {
    confirmModal.value = { show: true, title: 'Удалить поставщика?', message: `${s.short_name} будет удалён`, resolve };
  });
  if (!ok) return;
  const { error } = await db.from('suppliers').delete().eq('id', s.id);
  if (error) { toast.error('Ошибка', ''); return; }
  toast.success('Поставщик удалён', '');
  supplierStore.invalidate();
  await load();
}

async function onSaved() {
  modal.value.show = false;
  supplierStore.invalidate();
  await load();
}
</script>
