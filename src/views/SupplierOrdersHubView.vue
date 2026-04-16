<template>
  <div class="so-hub">
    <div class="so-hub-header">
      <h1>Заявки поставщикам</h1>
      <div class="so-hub-header-actions">
        <button
          v-if="selectedType === 'so' && selectedId"
          class="so-hub-disconnect-btn"
          @click="disconnectSelected"
          :disabled="disconnecting"
        >
          {{ disconnecting ? 'Отключение...' : 'Отключить поставщика' }}
        </button>
        <button class="so-hub-connect-btn" @click="showConnectModal = true">
          + Подключить поставщика
        </button>
      </div>
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

    <SupplierOrdersManagerView v-if="selectedId" :supplier-id="selectedId" />
    <div v-else-if="!suppliers.length" class="so-hub-empty">
      Для юрлица «{{ orderStore.settings.legalEntity }}» пока не подключено ни одного поставщика к приёму заявок.<br>
      Нажмите <b>«+ Подключить поставщика»</b>, чтобы настроить график, шаблон товаров и дедлайны.
    </div>
    <div v-else class="so-hub-empty">Выберите поставщика</div>

    <SupplierConnectModal
      v-if="showConnectModal"
      @close="showConnectModal = false"
      @connected="onConnected"
    />
  </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';
import SupplierOrdersManagerView from '@/views/SupplierOrdersManagerView.vue';
import SupplierConnectModal from '@/components/modals/SupplierConnectModal.vue';
import { useSupplierOrderStore } from '@/stores/supplierOrderStore.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { getEntityGroupCode } from '@/lib/legalEntities.js';

const soStore = useSupplierOrderStore();
const orderStore = useOrderStore();

const suppliers = ref([]);
const selectedId = ref('');
const selectedType = ref('');
const showConnectModal = ref(false);
const disconnecting = ref(false);

async function onConnected(supplier) {
  await loadList();
  if (supplier?.id) selectSupplier(supplier.id);
}

async function disconnectSelected() {
  if (!selectedId.value) return;
  const sup = suppliers.value.find(s => s.id === selectedId.value);
  if (!sup) return;
  const ok = confirm(
    `Отключить поставщика «${sup.name}» от модуля заявок?\n\n` +
    `Расписания, шаблоны и настройки сохранятся — при повторном подключении ` +
    `всё вернётся. Рестораны перестанут видеть этого поставщика в своём кабинете.`
  );
  if (!ok) return;
  disconnecting.value = true;
  try {
    await soStore.adminDisconnectSupplier(selectedId.value);
    // Сбрасываем выбор и перезагружаем список
    selectedId.value = '';
    selectedType.value = '';
    sessionStorage.removeItem(storageKey());
    await loadList();
  } catch (e) {
    alert('Ошибка отключения: ' + (e.message || e));
  } finally {
    disconnecting.value = false;
  }
}

function selectSupplier(id) {
  selectedId.value = id;
  selectedType.value = 'so';
  // Ключ в sessionStorage делаем per-юрлицо, чтобы при переключении
  // сайдбара не восстанавливался чужой поставщик.
  sessionStorage.setItem(storageKey(), id);
}

function storageKey() {
  return 'so_hub_supplier_' + getEntityGroupCode(orderStore.settings.legalEntity);
}

async function loadList() {
  const list = [];

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

.so-hub-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}
.so-hub-header h1 {
  margin: 0;
  font-size: 22px;
  color: #502314;
}
.so-hub-header-actions {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
}
.so-hub-connect-btn {
  padding: 9px 18px;
  border-radius: 8px;
  background: #D62300;
  color: white;
  border: 1.5px solid #D62300;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  font-family: inherit;
  transition: all 0.15s;
}
.so-hub-connect-btn:hover { background: #b51e00; }
.so-hub-disconnect-btn {
  padding: 9px 18px;
  border-radius: 8px;
  background: white;
  color: #6b4f3a;
  border: 1.5px solid #e0d5c8;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  font-family: inherit;
  transition: all 0.15s;
}
.so-hub-disconnect-btn:hover:not(:disabled) {
  border-color: #d97706;
  color: #d97706;
}
.so-hub-disconnect-btn:disabled { opacity: 0.5; cursor: not-allowed; }

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
