<template>
  <div class="so-hub">
    <div class="so-hub-header">
      <h1>Заявки поставщикам</h1>
    </div>

    <div v-if="suppliers.length" class="so-hub-pills">
      <button
        v-for="s in suppliers"
        :key="s.id"
        class="so-hub-pill"
        :class="{ active: selectedId === s.id }"
        @click="selectSupplier(s.id)"
      >
        {{ s.name }}
      </button>
    </div>

    <VegOrderAdminView v-if="selectedType === 'veg'" embedded />
    <SupplierOrdersManagerView v-else-if="selectedType === 'so' && selectedId" :supplier-id="selectedId" />
    <div v-else-if="!suppliers.length" class="so-hub-empty">
      Для юрлица «{{ orderStore.settings.legalEntity }}» пока не заведено ни одного поставщика.<br>
      Создайте поставщика в разделе «Справочник → Поставщики».
    </div>
    <div v-else class="so-hub-empty">Выберите поставщика</div>
  </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';
import VegOrderAdminView from '@/views/VegOrderAdminView.vue';
import SupplierOrdersManagerView from '@/views/SupplierOrdersManagerView.vue';
import { useSupplierOrderStore } from '@/stores/supplierOrderStore.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { getEntityGroupCode } from '@/lib/legalEntities.js';

const soStore = useSupplierOrderStore();
const orderStore = useOrderStore();
const userStore = useUserStore();

const suppliers = ref([]);
const selectedId = ref('');
const selectedType = ref('');

function selectSupplier(id) {
  selectedId.value = id;
  selectedType.value = id === 'veg' ? 'veg' : 'so';
  // Ключ в sessionStorage делаем per-юрлицо, чтобы при переключении
  // сайдбара не восстанавливался чужой поставщик.
  sessionStorage.setItem(storageKey(), id);
}

function storageKey() {
  return 'so_hub_supplier_' + getEntityGroupCode(orderStore.settings.legalEntity);
}

async function loadList() {
  const list = [];

  // Планета Ресторанов — только для БК+ВМ. Для Пицца Стар этот модуль
  // пока не используется, поэтому кнопку не показываем.
  const groupCode = getEntityGroupCode(orderStore.settings.legalEntity);
  const hasVeg = userStore.hasAccess?.('veg', 'view') || userStore.hasAccess?.('supplier-orders', 'view');
  if (groupCode !== 'PS' && hasVeg !== false) {
    list.push({ id: 'veg', name: 'Планета Ресторанов', type: 'veg' });
  }

  // SO-поставщики из API — передаём юрлицо, чтобы на ПС показались только свои
  try {
    const soSuppliers = await soStore.adminGetSuppliers(orderStore.settings.legalEntity);
    for (const s of soSuppliers) {
      list.push({ id: s.id, name: s.short_name, type: 'so' });
    }
  } catch (e) {
    console.error('Ошибка загрузки поставщиков:', e);
  }

  suppliers.value = list;

  // Сброс текущего выбора, если он больше не в списке
  if (selectedId.value && !list.some(s => s.id === selectedId.value)) {
    selectedId.value = '';
    selectedType.value = '';
  }

  // Восстановить выбранного поставщика (для этого юрлица)
  const saved = sessionStorage.getItem(storageKey());
  if (saved && list.some(s => s.id === saved)) {
    selectSupplier(saved);
  } else if (!selectedId.value && list.length > 0) {
    selectSupplier(list[0].id);
  }
}

onMounted(loadList);

// При смене юрлица в сайдбаре — перезагружаем список поставщиков
watch(() => orderStore.settings.legalEntity, () => { loadList(); });
</script>

<style scoped>
.so-hub { padding: 20px; }

.so-hub-header h1 {
  margin: 0 0 16px;
  font-size: 22px;
  color: #502314;
}

.so-hub-pills {
  display: flex;
  gap: 8px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}
.so-hub-pill {
  padding: 10px 24px;
  border-radius: 10px;
  border: 2px solid #e0d5c8;
  background: white;
  cursor: pointer;
  font-size: 15px;
  font-weight: 600;
  color: #502314;
  font-family: inherit;
  transition: all 0.2s;
}
.so-hub-pill:hover {
  background: #f5f0eb;
  border-color: #502314;
}
.so-hub-pill.active {
  background: #D62300;
  color: white;
  border-color: #D62300;
}

.so-hub-empty {
  padding: 60px;
  text-align: center;
  color: #8b7355;
  font-size: 15px;
}
</style>
