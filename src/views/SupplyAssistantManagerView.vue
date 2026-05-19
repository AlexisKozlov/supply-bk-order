<template>
  <div class="sam-page">
    <!-- ─── Шапка ─── -->
    <div class="rom-header">
      <div class="rom-header-left">
        <h1>Сбор заказа основной поставки</h1>
        <div class="rom-page-tabs">
          <button class="rom-page-tab" :class="{ active: pageTab === 'orders' }" @click="pageTab = 'orders'">
            Заказы ресторанов
          </button>
          <button class="rom-page-tab" :class="{ active: pageTab === 'templates' }" @click="pageTab = 'templates'; loadSaTemplates()">
            Шаблон товаров
          </button>
        </div>
      </div>
    </div>

    <!-- ═══ TAB: Заказы ресторанов ═══ -->
    <template v-if="pageTab === 'orders'">
      <!-- Фильтры -->
      <div class="rom-panel">
        <div class="rom-list-filters sam-filters">
          <input
            v-model="filterRestaurant"
            type="text"
            placeholder="Номер ресторана..."
            class="rom-input sam-filter-input"
          />
          <input
            v-model="filterDateFrom"
            type="date"
            class="rom-input sam-filter-date"
            :title="'Дата с'"
          />
          <input
            v-model="filterDateTo"
            type="date"
            class="rom-input sam-filter-date"
            :title="'Дата по'"
          />
          <select v-model="filterLegalEntity" class="rom-input sam-filter-le">
            <option value="">Все юрлица</option>
            <option v-for="le in saEntities" :key="le" :value="le">{{ le }}</option>
          </select>
          <button class="rom-btn" @click="loadOrders" :disabled="ordersLoading">
            {{ ordersLoading ? '...' : 'Найти' }}
          </button>
          <button class="rom-btn" @click="resetFilters">Сбросить</button>
        </div>
      </div>

      <div v-if="ordersLoading" class="rom-loading"><BurgerSpinner text="Загрузка..." /></div>

      <template v-else>
        <div v-if="ordersError" class="sam-error">{{ ordersError }}</div>
        <!-- Таблица заказов -->
        <div class="rom-table-card">
          <table class="rom-table rom-table-compact">
            <thead>
              <tr>
                <th class="rom-th-left">Ресторан</th>
                <th>Дата поставки</th>
                <th>Юрлицо</th>
                <th>Позиций</th>
                <th>Кол-во (сумм.)</th>
                <th>Изменён</th>
                <th>Кто правил</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="order in orders"
                :key="order.id"
                class="rom-row rom-row-clickable rom-row-submitted"
                @click="openOrder(order.id)"
              >
                <td class="rom-td-rest">
                  <span class="rom-cell-rest-city">№{{ order.restaurant_number }}</span>
                  <span v-if="order.city" class="sam-city">, {{ order.city }}</span>
                </td>
                <td>{{ order.delivery_date || '—' }}</td>
                <td class="sam-td-le">{{ shortLE(order.legal_entity) }}</td>
                <td class="sam-td-num">{{ order.item_count || 0 }}</td>
                <td class="sam-td-num">{{ fmtQty(order.total_qty) }}</td>
                <td class="rom-td-time">{{ order.updated_at ? fmtDateTime(order.updated_at) : '—' }}</td>
                <td>{{ order.updated_by || '—' }}</td>
                <td @click.stop>
                  <button class="rom-btn-sm rom-btn-danger" @click="confirmDeleteOrder(order.id)" :disabled="!canEdit" title="Удалить заказ">
                    ✕
                  </button>
                </td>
              </tr>
              <tr v-if="!orders.length">
                <td colspan="8" class="rom-no-items">Заказов не найдено</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </template>

    <!-- ═══ TAB: Шаблон товаров ═══ -->
    <template v-if="pageTab === 'templates'">
      <div class="rom-panel">
        <div class="rom-tpl-toolbar">
          <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap">
            <select v-model="tplLegalEntity" class="rom-input" style="min-width:180px" @change="loadSaTemplates">
              <option v-for="le in saEntities" :key="le" :value="le">{{ le }}</option>
            </select>
            <div class="rom-tpl-tabs-inline">
              <button
                v-for="cat in CATEGORIES"
                :key="cat"
                class="rom-tpl-tab"
                :class="{ active: tplCategory === cat }"
                @click="tplCategory = cat"
              >
                {{ cat }}
                <span class="rom-tpl-tab-count">{{ tplItems.filter(i => i.category === cat).length }}</span>
              </button>
            </div>
          </div>
          <div class="rom-tpl-toolbar-right">
            <button v-if="canEdit" class="rom-btn rom-btn-primary" @click="saveSaTemplate" :disabled="tplSaving">
              <BurgerSpinner v-if="tplSaving" size="xs" />
              <span>{{ tplSaving ? 'Сохранение...' : 'Сохранить шаблон' }}</span>
            </button>
          </div>
        </div>

        <div class="rom-tpl-filter">
          <input v-model="tplFilter" type="text" placeholder="Фильтр по названию или артикулу..." class="rom-input rom-tpl-filter-input" />
          <button v-if="canEdit" class="rom-btn" @click="showTplAddModal = true">+ Добавить товар</button>
        </div>

        <div v-if="tplMessage" class="rom-tpl-msg" :class="{ success: tplMessageOk }">{{ tplMessage }}</div>

        <div v-if="tplLoading" class="rom-loading"><BurgerSpinner text="Загрузка шаблона..." /></div>
        <div v-else class="rom-table-wrap">
          <table class="rom-table" v-if="filteredTplItems.length">
            <thead>
              <tr>
                <th style="width:40px">#</th>
                <th>Товар</th>
                <th style="width:90px">Группа аналогов</th>
                <th style="width:90px">Кратность</th>
                <th v-if="canEdit" style="width:50px"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(item, idx) in filteredTplItems" :key="item.sku">
                <td class="rom-td-num">{{ idx + 1 }}</td>
                <td><span class="rom-sku-label">{{ item.sku }}</span> {{ item.product_name }}</td>
                <td class="sam-td-ag">{{ item.analog_group || '—' }}</td>
                <td>
                  <input
                    v-if="canEdit"
                    v-model.number="item.multiplicity"
                    type="number"
                    min="1"
                    class="rom-tpl-mult-input"
                  />
                  <span v-else>{{ item.multiplicity }}</span>
                </td>
                <td v-if="canEdit">
                  <button class="rom-btn-sm rom-btn-danger" @click="removeTplItem(item)">X</button>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-else class="rom-empty">
            {{ tplFilter ? 'Ничего не найдено' : 'Шаблон пуст. Нажмите «+ Добавить товар».' }}
          </div>
        </div>
      </div>
    </template>

    <!-- ═══ Панель деталей заказа ═══ -->
    <div v-if="showOrderPanel" class="rom-modal-overlay" @click.self="closeOrderPanel">
      <div class="rom-modal sam-order-modal">
        <div class="rom-modal-header">
          <h2>
            Заказ ресторана №{{ editingOrder?.restaurant_number }}
            <span v-if="editingOrder?.delivery_date" class="sam-modal-sub">
              — поставка {{ editingOrder.delivery_date }}
            </span>
          </h2>
          <button class="rom-modal-close" @click="closeOrderPanel">&times;</button>
        </div>
        <div class="rom-modal-body">
          <div v-if="orderLoading" style="text-align:center; padding:32px">
            <BurgerSpinner text="Загрузка заказа..." />
          </div>
          <template v-else>
            <!-- Фильтр по категории -->
            <div class="sam-order-tabs">
              <button
                v-for="cat in ['Все', ...CATEGORIES]"
                :key="cat"
                class="rom-chip"
                :class="{ active: editCategory === cat }"
                @click="editCategory = cat"
              >
                {{ cat }}
                <span v-if="cat !== 'Все'" class="rom-tpl-tab-count">
                  {{ editItems.filter(i => i.category === cat).length }}
                </span>
              </button>
            </div>

            <!-- Таблица позиций -->
            <div class="rom-table-wrap">
              <table class="rom-table">
                <thead>
                  <tr>
                    <th>Товар</th>
                    <th style="width:100px">Группа аналогов</th>
                    <th style="width:70px">Кратность</th>
                    <th style="width:90px">Количество</th>
                    <th v-if="canEdit" style="width:40px"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in filteredEditItems" :key="item.sku">
                    <td><span class="rom-sku-label">{{ item.sku }}</span> {{ item.product_name }}</td>
                    <td class="sam-td-ag">{{ item.analog_group || '—' }}</td>
                    <td class="sam-td-num">{{ item.multiplicity }}</td>
                    <td>
                      <input
                        v-if="canEdit"
                        v-model.number="item.quantity"
                        type="number"
                        min="0"
                        class="rom-tpl-mult-input sam-qty-input"
                        :class="{ 'sam-qty-error': multErrors[item.sku] }"
                        :title="multErrors[item.sku] || ''"
                      />
                      <span v-else>{{ item.quantity }}</span>
                    </td>
                    <td v-if="canEdit">
                      <button class="rom-btn-sm rom-btn-danger" @click="removeEditItem(item)" title="Удалить позицию">✕</button>
                    </td>
                  </tr>
                  <tr v-if="!filteredEditItems.length">
                    <td :colspan="canEdit ? 5 : 4" class="rom-no-items">Нет позиций</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Ошибки кратности -->
            <div v-if="Object.keys(multErrors).length" class="sam-mult-errors">
              <div class="sam-mult-errors-title">Нарушение кратности:</div>
              <div v-for="(msg, sku) in multErrors" :key="sku" class="sam-mult-error-row">
                {{ sku }}: {{ msg }}
              </div>
            </div>

            <!-- Комментарий -->
            <div class="sam-comment-block">
              <label class="sam-comment-label">Комментарий</label>
              <textarea
                v-model="editComment"
                class="rom-input sam-comment-input"
                rows="2"
                :disabled="!canEdit"
                placeholder="Без комментария"
              ></textarea>
            </div>

            <!-- Действия -->
            <div class="sam-order-actions">
              <button v-if="canEdit" class="rom-btn rom-btn-primary" @click="saveEditOrder" :disabled="saving">
                <BurgerSpinner v-if="saving" size="xs" />
                <span>{{ saving ? 'Сохранение...' : 'Сохранить' }}</span>
              </button>
              <button v-if="canEdit" class="rom-btn rom-btn-danger" @click="confirmDeleteCurrentOrder" :disabled="saving">
                Удалить заказ
              </button>
              <div v-if="saveError" class="sam-save-error">{{ saveError }}</div>
            </div>
          </template>
        </div>
      </div>
    </div>

    <!-- ═══ Модалка добавления товара в шаблон ═══ -->
    <div v-if="showTplAddModal" class="rom-modal-overlay" @click.self="showTplAddModal = false">
      <div class="rom-modal">
        <div class="rom-modal-header">
          <h2>Добавить товар в шаблон</h2>
          <button class="rom-modal-close" @click="showTplAddModal = false">&times;</button>
        </div>
        <div class="rom-modal-body">
          <input
            v-model="tplAddSearch"
            type="text"
            placeholder="Поиск по названию или артикулу..."
            class="rom-input"
            style="width:100%;margin-bottom:12px"
            @input="doTplAddSearch"
          />
          <div v-if="tplAddResults.length" style="max-height:360px;overflow-y:auto">
            <div
              v-for="p in tplAddResults"
              :key="p.sku"
              class="rom-tpl-add-row"
              @click="addToTemplate(p)"
            >
              <span class="rom-td-sku-tpl">{{ p.sku }}</span>
              <span style="flex:1">{{ p.product_name || p.name }}</span>
              <span class="rom-add-cat">{{ p.category }}</span>
            </div>
          </div>
          <div v-else-if="tplAddSearch.length >= 2" class="rom-no-items">Ничего не найдено</div>
          <div v-else class="rom-no-items">Введите минимум 2 символа</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useSupplyAssistantStore } from '@/stores/supplyAssistantStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { LEGAL_ENTITIES } from '@/lib/legalEntities.js';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';

const saStore = useSupplyAssistantStore();
const userStore = useUserStore();
const toast = useToastStore();

const CATEGORIES = ['Сухой', 'Холод', 'Мороз'];

// Пицца Стар работает не через 1С УТ — модуль на неё не распространяется.
const saEntities = computed(() => LEGAL_ENTITIES.filter(le => !String(le).includes('Пицца')));

const canEdit = computed(() => userStore.hasAccess('supply-assistant', 'edit'));

// ═══ Вкладки ═══
const pageTab = ref('orders');

// ═══ Вкладка «Заказы ресторанов» ═══
const filterRestaurant = ref('');
const filterDateFrom = ref('');
const filterDateTo = ref('');
const filterLegalEntity = ref('');
const orders = ref([]);
const ordersLoading = ref(false);
const ordersError = ref('');

function resetFilters() {
  filterRestaurant.value = '';
  filterDateFrom.value = '';
  filterDateTo.value = '';
  filterLegalEntity.value = '';
  orders.value = [];
  ordersError.value = '';
}

async function loadOrders() {
  ordersLoading.value = true;
  ordersError.value = '';
  try {
    orders.value = await saStore.adminListOrders({
      restaurant: filterRestaurant.value.trim() || undefined,
      date_from: filterDateFrom.value || undefined,
      date_to: filterDateTo.value || undefined,
      legal_entity: filterLegalEntity.value || undefined,
    });
  } catch (e) {
    ordersError.value = e.message || 'Ошибка загрузки';
    orders.value = [];
  } finally {
    ordersLoading.value = false;
  }
}

function shortLE(le) {
  if (!le) return '—';
  if (le.includes('Воглия')) return 'Воглия Матта';
  if (le.includes('Бургер')) return 'Бургер БК';
  if (le.includes('Пицца')) return 'Пицца Стар';
  return le;
}

function fmtQty(value) {
  const n = Number(value) || 0;
  return Math.abs(n - Math.round(n)) < 0.001 ? String(Math.round(n)) : n.toFixed(2).replace(/\.?0+$/, '');
}

function fmtDateTime(dt) {
  if (!dt) return '—';
  try {
    const d = new Date(dt);
    const pad = n => String(n).padStart(2, '0');
    return `${pad(d.getDate())}.${pad(d.getMonth() + 1)} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
  } catch { return dt; }
}

// Удаление заказа из списка
async function confirmDeleteOrder(id) {
  if (!canEdit.value) return;
  if (!confirm('Удалить заказ? Это действие нельзя отменить.')) return;
  try {
    await saStore.adminDeleteOrder(id);
    orders.value = orders.value.filter(o => o.id !== id);
    toast.success('Готово', 'Заказ удалён');
  } catch (e) {
    toast.error('Ошибка', e.message);
  }
}

// ═══ Панель редактирования заказа ═══
const showOrderPanel = ref(false);
const editingOrder = ref(null);
const editItems = ref([]);
const editComment = ref('');
const editCategory = ref('Все');
const orderLoading = ref(false);
const saving = ref(false);
const saveError = ref('');
const multErrors = ref({});

const filteredEditItems = computed(() => {
  if (editCategory.value === 'Все') return editItems.value;
  return editItems.value.filter(i => i.category === editCategory.value);
});

async function openOrder(id) {
  showOrderPanel.value = true;
  orderLoading.value = true;
  editingOrder.value = null;
  editItems.value = [];
  editComment.value = '';
  editCategory.value = 'Все';
  multErrors.value = {};
  saveError.value = '';
  try {
    const order = await saStore.adminGetOrder(id);
    if (!order) throw new Error('Заказ не найден');
    editingOrder.value = order;
    editComment.value = order.comment || '';
    editItems.value = (order.items || []).map(i => ({ ...i }));
  } catch (e) {
    toast.error('Ошибка', e.message);
    showOrderPanel.value = false;
  } finally {
    orderLoading.value = false;
  }
}

function closeOrderPanel() {
  showOrderPanel.value = false;
  editingOrder.value = null;
  editItems.value = [];
  multErrors.value = {};
  saveError.value = '';
}

function removeEditItem(item) {
  editItems.value = editItems.value.filter(i => i !== item);
}

async function saveEditOrder() {
  if (!editingOrder.value) return;
  saving.value = true;
  saveError.value = '';
  multErrors.value = {};
  try {
    await saStore.adminSaveOrder(editingOrder.value.id, {
      comment: editComment.value,
      items: editItems.value.map(i => ({
        sku: i.sku,
        product_name: i.product_name,
        category: i.category,
        quantity: i.quantity,
        multiplicity: i.multiplicity,
        external_code: i.external_code,
        analog_group: i.analog_group,
      })),
    });
    toast.success('Сохранено', 'Заказ обновлён');
    // Обновим запись в списке
    const idx = orders.value.findIndex(o => o.id === editingOrder.value.id);
    if (idx !== -1) {
      orders.value[idx] = {
        ...orders.value[idx],
        item_count: editItems.value.length,
        total_qty: editItems.value.reduce((s, i) => s + (Number(i.quantity) || 0), 0),
        updated_at: new Date().toISOString(),
        updated_by: userStore.currentUser?.name || userStore.currentUser?.login || '',
      };
    }
    closeOrderPanel();
  } catch (e) {
    if (e.multErrors) {
      multErrors.value = e.multErrors;
      saveError.value = 'Исправьте нарушения кратности перед сохранением.';
    } else {
      saveError.value = e.message || 'Ошибка сохранения';
    }
  } finally {
    saving.value = false;
  }
}

async function confirmDeleteCurrentOrder() {
  if (!editingOrder.value) return;
  if (!confirm('Удалить этот заказ? Это действие нельзя отменить.')) return;
  saving.value = true;
  try {
    await saStore.adminDeleteOrder(editingOrder.value.id);
    orders.value = orders.value.filter(o => o.id !== editingOrder.value.id);
    toast.success('Готово', 'Заказ удалён');
    closeOrderPanel();
  } catch (e) {
    saveError.value = e.message || 'Ошибка удаления';
  } finally {
    saving.value = false;
  }
}

// ═══ Вкладка «Шаблон товаров» ═══
const tplLegalEntity = ref(LEGAL_ENTITIES[0]);
const tplCategory = ref('Сухой');
const tplItems = ref([]);
const tplFilter = ref('');
const tplLoading = ref(false);
const tplSaving = ref(false);
const tplMessage = ref('');
const tplMessageOk = ref(false);
const showTplAddModal = ref(false);
const tplAddSearch = ref('');
const tplAddResults = ref([]);
let tplAddTimer = null;

const filteredTplItems = computed(() => {
  const catItems = tplItems.value.filter(i => i.category === tplCategory.value);
  const q = tplFilter.value.trim().toLowerCase();
  if (!q) return catItems;
  return catItems.filter(i =>
    (i.sku || '').toLowerCase().includes(q) ||
    (i.product_name || '').toLowerCase().includes(q)
  );
});

async function loadSaTemplates() {
  tplMessage.value = '';
  tplItems.value = [];
  tplLoading.value = true;
  try {
    for (const cat of CATEGORIES) {
      const items = await saStore.adminGetTemplates(tplLegalEntity.value, cat);
      tplItems.value.push(...items.map(i => ({
        ...i,
        category: i.category || cat,
        multiplicity: parseInt(i.multiplicity) || 1,
      })));
    }
  } catch (e) {
    tplMessage.value = 'Ошибка загрузки: ' + (e.message || '');
    tplMessageOk.value = false;
  } finally {
    tplLoading.value = false;
  }
}

function removeTplItem(item) {
  tplItems.value = tplItems.value.filter(i => i !== item);
}

async function saveSaTemplate() {
  tplSaving.value = true;
  tplMessage.value = '';
  try {
    for (const cat of CATEGORIES) {
      const items = tplItems.value.filter(i => i.category === cat);
      await saStore.adminSaveTemplates({
        action: 'save',
        legal_entity: tplLegalEntity.value,
        category: cat,
        items,
      });
    }
    tplMessage.value = `Шаблон сохранён (${tplItems.value.length} товаров)`;
    tplMessageOk.value = true;
    setTimeout(() => { tplMessage.value = ''; }, 3000);
  } catch (e) {
    tplMessage.value = 'Ошибка: ' + (e.message || '');
    tplMessageOk.value = false;
  } finally {
    tplSaving.value = false;
  }
}

function doTplAddSearch() {
  clearTimeout(tplAddTimer);
  if (!tplAddSearch.value || tplAddSearch.value.length < 2) {
    tplAddResults.value = [];
    return;
  }
  tplAddTimer = setTimeout(async () => {
    try {
      // Используем общий поиск товаров через стор
      const products = await saStore.searchProducts(tplAddSearch.value);
      const existing = new Set(tplItems.value.map(i => i.sku));
      tplAddResults.value = products.filter(p => !existing.has(p.sku));
    } catch {
      tplAddResults.value = [];
    }
  }, 300);
}

function addToTemplate(product) {
  tplItems.value.push({
    sku: product.sku,
    product_name: product.product_name || product.name,
    category: product.category || tplCategory.value,
    multiplicity: parseInt(product.multiplicity) || 1,
    analog_group: product.analog_group || '',
    external_code: product.external_code || '',
  });
  tplAddResults.value = tplAddResults.value.filter(p => p.sku !== product.sku);
  showTplAddModal.value = false;
  tplAddSearch.value = '';
}

onMounted(() => {
  // Загрузим первую страницу заказов сразу
  loadOrders();
});
</script>

<style scoped>
/* ── Палитра портала ── */
.sam-page {
  --p-ink: #502314;
  --p-muted: #8b7355;
  --p-accent: #E76F51;
  --p-accent-d: #d65f43;
  --p-line: #e8dccd;
  --p-line-soft: #f0e9df;
  --p-soft: #faf7f4;
  --p-danger: #c0392b;
  padding: 20px;
  max-width: 1280px;
  margin: 0 auto;
  color: var(--p-ink);
}

/* ── Шапка ── */
.rom-header {
  display: flex; justify-content: space-between; align-items: flex-end;
  gap: 12px; flex-wrap: wrap;
  margin-bottom: 18px;
  border-bottom: 1px solid #e0d5c8;
}
.rom-header-left { display: flex; flex-direction: column; gap: 10px; }
.rom-header h1 {
  margin: 0; font-size: 22px; font-weight: 700;
  letter-spacing: -.2px; color: var(--p-ink);
}
.rom-page-tabs { display: flex; gap: 4px; }
.rom-page-tab {
  position: relative;
  padding: 9px 16px; border: 0; background: transparent;
  font: inherit; font-size: 14px; font-weight: 600; color: var(--p-muted);
  cursor: pointer; border-radius: 8px 8px 0 0;
  transition: color .15s ease, background .15s ease;
}
.rom-page-tab:hover { color: var(--p-ink); background: var(--p-soft); }
.rom-page-tab.active { color: var(--p-accent); }
.rom-page-tab.active::after {
  content: ''; position: absolute; left: 8px; right: 8px; bottom: -1px;
  height: 2.5px; background: var(--p-accent); border-radius: 2px;
}

/* ── Панель / карточка ── */
.rom-panel {
  background: #fff; border: 1px solid var(--p-line);
  border-radius: 12px; padding: 16px;
  box-shadow: 0 2px 10px rgba(80,35,20,.05);
  margin-bottom: 16px;
}

/* ── Поля ввода ── */
.rom-input {
  min-height: 38px; padding: 8px 12px;
  border: 1px solid #e0d5c8; border-radius: 8px;
  background: var(--p-soft);
  font: inherit; font-size: 14px; color: var(--p-ink);
  transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
}
.rom-input:focus {
  outline: 0; background: #fff; border-color: var(--p-accent);
  box-shadow: 0 0 0 3px rgba(231,111,81,.13);
}
.sam-filters { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.sam-filter-input { min-width: 150px; }
.sam-filter-date { min-width: 140px; }
.sam-filter-le { min-width: 170px; }

/* ── Кнопки ── */
.rom-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 7px;
  min-height: 38px; padding: 8px 16px;
  border: 1px solid #d9cbbd;
  border-radius: 8px; background: #fff;
  font: inherit; font-size: 13px; font-weight: 600; color: var(--p-ink);
  cursor: pointer;
  transition: background .15s ease, border-color .15s ease, opacity .15s ease;
}
.rom-btn:hover:not(:disabled) { background: var(--p-soft); border-color: var(--p-accent); }
.rom-btn:disabled { opacity: .5; cursor: default; }
.rom-btn-primary {
  background: var(--p-accent); border-color: var(--p-accent); color: #fff;
}
.rom-btn-primary:hover:not(:disabled) { background: var(--p-accent-d); border-color: var(--p-accent-d); }
.rom-btn-danger { color: var(--p-danger); border-color: #e6c4bf; }
.rom-btn-danger:hover:not(:disabled) { background: #fdf0ee; border-color: var(--p-danger); }
.rom-btn-sm {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 30px; min-height: 30px; padding: 4px 8px;
  border: 1px solid #d9cbbd; border-radius: 7px; background: #fff;
  font: inherit; font-size: 13px; font-weight: 600; color: var(--p-ink); cursor: pointer;
  transition: background .15s ease, border-color .15s ease;
}
.rom-btn-sm:hover:not(:disabled) { background: var(--p-soft); }
.rom-btn-sm.rom-btn-danger { color: var(--p-danger); border-color: #e6c4bf; }
.rom-btn-sm.rom-btn-danger:hover:not(:disabled) { background: #fdf0ee; }
.rom-btn-sm:disabled { opacity: .4; cursor: default; }

/* ── Загрузка / пусто ── */
.rom-loading { padding: 40px; text-align: center; }
.rom-empty, .rom-no-items {
  padding: 28px 16px; text-align: center;
  color: var(--p-muted); font-size: 14px;
}

/* ── Таблицы ── */
.rom-table-card {
  background: #fff; border: 1px solid var(--p-line);
  border-radius: 12px; overflow: hidden;
  box-shadow: 0 2px 10px rgba(80,35,20,.05);
}
.rom-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.rom-table { width: 100%; border-collapse: collapse; }
.rom-table thead th {
  padding: 10px 12px; text-align: left;
  font-size: 11px; font-weight: 800; letter-spacing: .03em;
  text-transform: uppercase; color: var(--p-muted);
  background: var(--p-soft); border-bottom: 1.5px solid var(--p-line);
  white-space: nowrap;
}
.rom-table tbody td {
  padding: 9px 12px; font-size: 13.5px;
  border-bottom: 1px solid var(--p-line-soft); vertical-align: middle;
}
.rom-table tbody tr:last-child td { border-bottom: 0; }
.rom-table-compact tbody td { padding: 7px 12px; }
.rom-row-clickable { cursor: pointer; transition: background .12s ease; }
.rom-row-clickable:hover { background: #fdf6f1; }
.rom-th-left { text-align: left; }

.rom-td-rest { font-weight: 700; white-space: nowrap; }
.rom-cell-rest-city { color: var(--p-ink); }
.sam-city { font-size: 12px; color: var(--p-muted); font-weight: 400; }
.sam-td-le { font-size: 12px; color: var(--p-ink); }
.sam-td-num { text-align: right; font-variant-numeric: tabular-nums; }
.sam-td-ag { font-size: 12px; color: var(--p-muted); }
.rom-td-time { font-size: 12px; color: var(--p-muted); white-space: nowrap; }
.rom-td-num { text-align: center; color: var(--p-muted); font-variant-numeric: tabular-nums; }
.rom-sku-label, .rom-td-sku-tpl {
  font-weight: 800; color: var(--p-accent); font-size: 12.5px;
  margin-right: 5px; white-space: nowrap;
}

/* ── Ошибки ── */
.sam-error {
  color: var(--p-danger); padding: 9px 13px;
  background: #fdf0ee; border: 1px solid #f1cdc8;
  border-radius: 8px; margin-bottom: 12px; font-size: 13px; font-weight: 600;
}

/* ── Шаблон товаров ── */
.rom-tpl-toolbar {
  display: flex; justify-content: space-between; align-items: center;
  gap: 12px; flex-wrap: wrap; margin-bottom: 12px;
}
.rom-tpl-tabs-inline { display: flex; gap: 4px; }
.rom-tpl-tab {
  display: inline-flex; align-items: center; gap: 6px;
  min-height: 36px; padding: 6px 14px;
  border: 1px solid var(--p-line); border-radius: 8px;
  background: var(--p-soft); color: #5f4b38;
  font: inherit; font-size: 13px; font-weight: 600; cursor: pointer;
  transition: background .15s ease, border-color .15s ease, color .15s ease;
}
.rom-tpl-tab:hover { border-color: #d9cbbd; }
.rom-tpl-tab.active { background: var(--p-ink); border-color: var(--p-ink); color: #fff; }
.rom-tpl-tab-count {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 18px; height: 18px; padding: 0 5px;
  border-radius: 9px; background: rgba(80,35,20,.1);
  font-size: 11px; font-weight: 800; color: var(--p-muted);
}
.rom-tpl-tab.active .rom-tpl-tab-count { background: rgba(255,255,255,.22); color: #fff; }
.rom-tpl-toolbar-right { display: flex; gap: 8px; }
.rom-tpl-filter { display: flex; gap: 8px; margin-bottom: 12px; }
.rom-tpl-filter-input { flex: 1; }
.rom-tpl-mult-input {
  width: 72px; min-height: 34px; padding: 5px 8px;
  border: 1px solid #e0d5c8; border-radius: 7px; background: var(--p-soft);
  font: inherit; font-size: 14px; text-align: center; color: var(--p-ink);
  font-variant-numeric: tabular-nums;
  transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
}
.rom-tpl-mult-input:focus {
  outline: 0; background: #fff; border-color: var(--p-accent);
  box-shadow: 0 0 0 3px rgba(231,111,81,.13);
}
.rom-tpl-msg {
  padding: 8px 12px; border-radius: 8px; margin-bottom: 12px;
  font-size: 13px; font-weight: 600;
  background: #fdf0ee; border: 1px solid #f1cdc8; color: var(--p-danger);
}
.rom-tpl-msg.success { background: #edf7ee; border-color: #bfe3c4; color: #2e7d32; }
.rom-tpl-add-row {
  display: flex; align-items: center; gap: 8px;
  padding: 9px 10px; border-bottom: 1px solid var(--p-line-soft);
  cursor: pointer; font-size: 13.5px;
  transition: background .12s ease;
}
.rom-tpl-add-row:hover { background: var(--p-soft); }
.rom-add-cat {
  padding: 1px 7px; border-radius: 6px;
  background: var(--p-soft); border: 1px solid var(--p-line);
  font-size: 11px; font-weight: 700; color: var(--p-muted); white-space: nowrap;
}

/* ── Модалки ── */
.rom-modal-overlay {
  position: fixed; inset: 0; z-index: 1000;
  display: flex; align-items: center; justify-content: center; padding: 20px;
  background: rgba(40,24,14,.5);
  -webkit-backdrop-filter: blur(2px); backdrop-filter: blur(2px);
}
.rom-modal {
  display: flex; flex-direction: column;
  width: 100%; max-width: 460px; max-height: 88vh;
  background: #fff; border-radius: 14px; overflow: hidden;
  box-shadow: 0 24px 60px rgba(40,24,14,.3);
}
.sam-order-modal { max-width: 880px; }
.rom-modal-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 15px 18px; border-bottom: 1px solid var(--p-line);
}
.rom-modal-header h2 { margin: 0; font-size: 17px; color: var(--p-ink); }
.sam-modal-sub { font-size: 14px; font-weight: 400; color: var(--p-muted); }
.rom-modal-close {
  width: 34px; height: 34px; flex-shrink: 0;
  border: 0; border-radius: 8px; background: var(--p-soft);
  font-size: 22px; line-height: 1; color: var(--p-muted); cursor: pointer;
  transition: background .15s ease;
}
.rom-modal-close:hover { background: #f0e7dc; }
.rom-modal-body { padding: 16px 18px; overflow-y: auto; }

/* ── Чипы категорий в заказе ── */
.sam-order-tabs { display: flex; gap: 6px; margin-bottom: 14px; flex-wrap: wrap; }
.rom-chip {
  display: inline-flex; align-items: center; gap: 6px;
  min-height: 32px; padding: 5px 13px;
  border: 1px solid var(--p-line); border-radius: 8px;
  background: var(--p-soft); color: #5f4b38;
  font: inherit; font-size: 13px; font-weight: 600; cursor: pointer;
  transition: background .15s ease, border-color .15s ease, color .15s ease;
}
.rom-chip:hover { border-color: #d9cbbd; }
.rom-chip.active { background: var(--p-ink); border-color: var(--p-ink); color: #fff; }
.rom-chip.active .rom-tpl-tab-count { background: rgba(255,255,255,.22); color: #fff; }

/* ── Поля количества в заказе ── */
.sam-qty-input { width: 86px; }
.sam-qty-error { border-color: #e5736b !important; background: #fdf0ee; color: var(--p-danger); }

.sam-mult-errors {
  margin: 12px 0; padding: 9px 13px;
  background: #fdf0ee; border: 1px solid #f1cdc8;
  border-left: 3px solid var(--p-danger); border-radius: 8px;
}
.sam-mult-errors-title { font-weight: 700; color: var(--p-danger); margin-bottom: 4px; font-size: 13px; }
.sam-mult-error-row { font-size: 13px; color: var(--p-danger); }

/* ── Комментарий и действия ── */
.sam-comment-block { margin-top: 14px; }
.sam-comment-label {
  display: block; margin-bottom: 5px;
  font-size: 12px; font-weight: 700; color: var(--p-muted);
}
.sam-comment-input { width: 100%; box-sizing: border-box; resize: vertical; font-family: inherit; }
.sam-order-actions {
  display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
  margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--p-line-soft);
}
.sam-save-error { color: var(--p-danger); font-size: 13px; font-weight: 600; }

@media (max-width: 600px) {
  .sam-page { padding: 14px; }
  .rom-tpl-toolbar { flex-direction: column; align-items: stretch; }
}
</style>
