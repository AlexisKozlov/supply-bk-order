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
import { ref, defineAsyncComponent, onMounted, watch } from 'vue';
import { useSupplierOrderStore } from '@/stores/supplierOrderStore.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { getEntityGroupCode } from '@/lib/legalEntities.js';
import { appAlert, appConfirm } from '@/lib/appDialogs.js';

const SupplierOrdersManagerView = defineAsyncComponent(() => import('@/views/SupplierOrdersManagerView.vue'));
const SupplierConnectModal = defineAsyncComponent(() => import('@/components/modals/SupplierConnectModal.vue'));

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
  const ok = await appConfirm(
    `Отключить поставщика «${sup.name}» от модуля заявок?\n\n` +
    `Расписания, шаблоны и настройки сохранятся — при повторном подключении ` +
    `всё вернётся. Рестораны перестанут видеть этого поставщика в своём кабинете.`,
    { title: 'Отключить поставщика', okText: 'Отключить', danger: true }
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
    await appAlert('Ошибка отключения: ' + (e.message || e), { type: 'error' });
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
/*
 * Оформление по дизайн-системе проекта (tokens.css, DESIGN.md): только var(--tk-*).
 * Заливка акцентом — ровно у одного элемента (главное действие «Подключить»);
 * выбранный поставщик показывается мягкой подсветкой, а не второй яркой кнопкой.
 */
.so-hub { padding: var(--tk-s-5); }

.so-hub-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--tk-s-3);
  margin-bottom: var(--tk-s-4);
  flex-wrap: wrap;
}
.so-hub-header h1 {
  margin: 0;
  font-size: var(--tk-fz-h2);
  font-weight: var(--tk-fw-bold);
  color: var(--tk-text);
  letter-spacing: -0.01em;
}
.so-hub-header-actions {
  display: flex;
  gap: var(--tk-s-2);
  align-items: center;
  flex-wrap: wrap;
}
.so-hub-connect-btn {
  padding: 8px var(--tk-s-4);
  border-radius: var(--tk-r-md);
  background: var(--tk-accent);
  color: var(--tk-n-0);
  border: 1px solid var(--tk-accent);
  font-size: var(--tk-fz-md);
  font-weight: var(--tk-fw-semibold);
  cursor: pointer;
  font-family: inherit;
  transition: background var(--tk-anim-fast), border-color var(--tk-anim-fast);
}
.so-hub-connect-btn:hover { background: var(--tk-accent-hover); border-color: var(--tk-accent-hover); }
.so-hub-connect-btn:focus-visible { outline: none; box-shadow: var(--tk-focus-ring); }
.so-hub-disconnect-btn {
  padding: 8px var(--tk-s-4);
  border-radius: var(--tk-r-md);
  background: var(--tk-bg-card);
  color: var(--tk-text);
  border: 1px solid var(--tk-border);
  font-size: var(--tk-fz-md);
  font-weight: var(--tk-fw-medium);
  cursor: pointer;
  font-family: inherit;
  transition: background var(--tk-anim-fast), border-color var(--tk-anim-fast), color var(--tk-anim-fast);
}
.so-hub-disconnect-btn:hover:not(:disabled) {
  background: var(--tk-danger-soft);
  border-color: var(--tk-danger);
  color: var(--tk-danger);
}
.so-hub-disconnect-btn:focus-visible { outline: none; box-shadow: var(--tk-focus-ring); }
.so-hub-disconnect-btn:disabled { opacity: 0.45; cursor: not-allowed; }

.so-hub-pills {
  display: flex;
  gap: var(--tk-s-2);
  margin-bottom: var(--tk-s-5);
  flex-wrap: wrap;
}
.so-hub-pill {
  padding: 7px var(--tk-s-4);
  border-radius: var(--tk-r-md);
  border: 1px solid var(--tk-border);
  background: var(--tk-bg-card);
  cursor: pointer;
  font-size: var(--tk-fz-lg);
  font-weight: var(--tk-fw-medium);
  color: var(--tk-text-secondary);
  font-family: inherit;
  transition: background var(--tk-anim-fast), border-color var(--tk-anim-fast), color var(--tk-anim-fast);
}
.so-hub-pill:hover {
  background: var(--tk-n-50);
  border-color: var(--tk-n-300);
  color: var(--tk-text);
}
.so-hub-pill.active {
  background: var(--tk-accent-soft);
  color: var(--tk-accent-text);
  border-color: var(--tk-accent);
  font-weight: var(--tk-fw-semibold);
}
.so-hub-pill:focus-visible { outline: none; box-shadow: var(--tk-focus-ring); }

.so-hub-empty {
  padding: var(--tk-s-7);
  text-align: center;
  color: var(--tk-text-muted);
  font-size: var(--tk-fz-lg);
  line-height: var(--tk-lh-loose);
}

/* На телефоне заголовок и кнопки шли одна под другой во всю ширину и
   съедали пол-экрана до содержимого. */
@media (max-width: 640px) {
  .so-hub { padding: var(--tk-s-3); }
  .so-hub-header h1 { font-size: var(--tk-fz-h1); }
  .so-hub-header-actions { display: grid; grid-template-columns: 1fr 1fr; width: 100%; }
  .so-hub-connect-btn, .so-hub-disconnect-btn {
    width: 100%; padding-left: var(--tk-s-2); padding-right: var(--tk-s-2);
  }
  /* Поставщиков может быть много — прокручиваем вбок, а не переносим. */
  .so-hub-pills {
    flex-wrap: nowrap; overflow-x: auto; scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
  }
  .so-hub-pills::-webkit-scrollbar { display: none; }
  .so-hub-pill { flex: 0 0 auto; white-space: nowrap; }
}

@media (prefers-reduced-motion: reduce) {
  .so-hub-connect-btn, .so-hub-disconnect-btn, .so-hub-pill { transition: none; }
}
</style>
