<template>
  <div class="sc">
    <div class="sc-top">
      <h1 class="sc-title">Сбор остатков</h1>
      <button class="sc-btn fill" @click="openCreateModal">+ Новый сбор</button>
    </div>

    <!-- Retry banner -->
    <div v-if="loadError && !activeCollection" class="retry-banner">
      <span>Не удалось загрузить данные</span>
      <button class="btn secondary small" @click="loadCollections">Повторить</button>
    </div>

    <!-- Collections list -->
    <div v-if="!activeCollection" class="sc-list">
      <div v-if="loading" class="sc-empty">Загрузка...</div>
      <div v-else-if="!collections.length && !loadError" class="sc-empty">Нет сессий сбора. Создайте первую.</div>
      <div
        v-for="c in collections" :key="c.id"
        class="sc-card"
        :class="{ closed: c.status === 'closed' }"
        @click="openCollection(c)"
      >
        <div class="sc-card-top">
          <div class="sc-card-name">{{ c.name }}</div>
          <span class="sc-tag" :class="c.status === 'active' ? 'green' : 'gray'">
            {{ c.status === 'active' ? 'Активен' : 'Закрыт' }}
          </span>
        </div>
        <div class="sc-card-meta">
          {{ c.created_by || '---' }} · {{ fmtDate(c.created_at) }}
        </div>
      </div>
    </div>

    <!-- Active collection detail -->
    <div v-if="activeCollection" class="sc-detail">
      <div class="sc-detail-bar">
        <button class="sc-btn outline" @click="activeCollection = null; collectionData = null; responseFilter = ''; sortKey = 'restaurant'; sortDir = 'asc';">← Назад</button>
        <div class="sc-detail-info">
          <div class="sc-detail-name">{{ activeCollection.name }}</div>
          <span class="sc-tag" :class="activeCollection.status === 'active' ? 'green' : 'gray'">
            {{ activeCollection.status === 'active' ? 'Активен' : 'Закрыт' }}
          </span>
        </div>
        <div class="sc-detail-actions">
          <button v-if="activeCollection.status === 'active'" class="sc-btn outline" @click="notifyRestaurants" :disabled="notifying">
            {{ notifying ? 'Отправка...' : '🔔 Напомнить в Telegram' }}
          </button>
          <button class="sc-btn outline" @click="openRename">Переименовать</button>
          <button v-if="activeCollection.status === 'active'" class="sc-btn outline red-text" @click="askCloseCollection">
            Закрыть сбор
          </button>
          <button class="sc-btn outline" @click="duplicateCollection">Копировать сбор</button>
          <button class="sc-btn outline red-text" @click="askDeleteCollection">Удалить</button>
        </div>
      </div>

      <!-- Token (inline) -->
      <div v-if="tokenLink" class="sc-token">
        <div class="sc-token-label">Ссылка для ресторанов</div>
        <div class="sc-token-row">
          <input :value="tokenLink" readonly class="sc-token-input" @focus="$event.target.select()"/>
          <button class="sc-btn sm fill" @click="copyToken">{{ copied ? '✓ Скопировано' : 'Копировать' }}</button>
        </div>
      </div>

      <!-- Products & data -->
      <div v-if="collectionData" class="sc-data">
        <!-- Summary bar -->
        <div class="sc-summary">
          <div class="sc-summary-item">
            <div class="sc-summary-num">{{ collectionData.products?.length || 0 }}</div>
            <div class="sc-summary-lbl">Товаров</div>
          </div>
          <div class="sc-summary-item">
            <div class="sc-summary-num">{{ uniqueRestaurants }}</div>
            <div class="sc-summary-lbl">Ресторанов</div>
          </div>
          <div class="sc-summary-item">
            <div class="sc-summary-num">{{ collectionData.data?.length || 0 }}</div>
            <div class="sc-summary-lbl">Ответов</div>
          </div>
          <div style="margin-left: auto; display: flex; gap: 6px;">
            <button v-if="activeCollection.status === 'active'" class="sc-btn sm outline" @click="openEditProducts">Товары</button>
            <button class="sc-btn sm outline" @click="exportExcel">Excel</button>
            <button class="sc-btn sm outline" @click="refreshData">Обновить</button>
          </div>
        </div>

        <!-- Filter -->
        <div v-if="mergedRows.length" class="sc-filter-bar">
          <input
            v-model="responseFilter"
            type="text"
            class="sc-input sc-filter-input"
            placeholder="Поиск по номеру, городу или адресу..."
          />
          <span v-if="responseFilter" class="sc-filter-count">
            {{ filteredRows.length }} из {{ mergedRows.length }}
          </span>
        </div>

        <!-- Unified table: Restaurant | Product1 | Product2 | ... -->
        <div v-if="mergedRows.length" class="sc-tbl-wrap">
          <table class="sc-tbl">
            <thead>
              <tr>
                <th class="col-num sortable" @click="toggleSort('restaurant')">Ресторан <span class="sort-arrow">{{ sortKey === 'restaurant' ? (sortDir === 'asc' ? '▲' : '▼') : '⇅' }}</span></th>
                <th class="col-city sortable" @click="toggleSort('city')">Город <span class="sort-arrow">{{ sortKey === 'city' ? (sortDir === 'asc' ? '▲' : '▼') : '⇅' }}</span></th>
                <th class="col-addr sortable" @click="toggleSort('address')">Адрес <span class="sort-arrow">{{ sortKey === 'address' ? (sortDir === 'asc' ? '▲' : '▼') : '⇅' }}</span></th>
                <th class="col-time sortable" @click="toggleSort('time')">Заполнено <span class="sort-arrow">{{ sortKey === 'time' ? (sortDir === 'asc' ? '▲' : '▼') : '⇅' }}</span></th>
                <th v-for="prod in collectionData.products" :key="prod.id" class="col-prod sortable" @click="toggleSort('prod_' + prod.id)">
                  <div>{{ prod.product_name }} <span class="sort-arrow">{{ sortKey === 'prod_' + prod.id ? (sortDir === 'asc' ? '▲' : '▼') : '⇅' }}</span></div>
                  <div class="th-unit">{{ unitLabel(prod.unit) }}</div>
                  <div v-if="prod.note" class="th-note" :title="prod.note">{{ prod.note }}</div>
                </th>
                <th v-if="activeCollection.status === 'active'" class="col-del"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in filteredRows" :key="row.restaurant">
                <td class="col-num fw">Ресторан {{ row.restaurant }}</td>
                <td class="col-city muted">{{ row.city }}</td>
                <td class="col-addr muted">{{ row.address }}</td>
                <td class="col-time muted">{{ fmtShort(row.submittedAt) }}</td>
                <td v-for="prod in collectionData.products" :key="prod.id" class="col-prod">
                  <template v-if="editingCell === row.restaurant + '_' + prod.id">
                    <input
                      v-model="editStockValue"
                      type="text" inputmode="decimal"
                      class="sc-cell-input"
                      @keydown.enter="saveCellEdit(row.cells[prod.id])"
                      @keydown.escape="editingCell = null"
                    />
                  </template>
                  <template v-else>
                    <span
                      v-if="row.cells[prod.id]"
                      class="sc-cell-val"
                      :class="{ editable: activeCollection.status === 'active' }"
                      @dblclick="activeCollection.status === 'active' && startCellEdit(row, prod.id)"
                    >{{ row.cells[prod.id].stock }}</span>
                    <span v-else class="sc-cell-empty">—</span>
                  </template>
                </td>
                <td v-if="activeCollection.status === 'active'" class="col-del">
                  <button class="sc-row-del" @click="deleteRestaurantRow(row)" title="Удалить ресторан">✕</button>
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="4" class="foot-label">Итого</td>
                <td v-for="prod in collectionData.products" :key="prod.id" class="col-prod foot-val">
                  {{ getProductTotal(prod.id) }}
                </td>
                <td v-if="activeCollection.status === 'active'"></td>
              </tr>
            </tfoot>
          </table>
        </div>
        <div v-else class="sc-empty">Нет данных</div>

        <!-- Missing restaurants (bottom) -->
        <div v-if="missingRestaurants.length" class="sc-missing">
          <div class="sc-missing-head" @click="showMissing = !showMissing">
            <span class="sc-missing-icon">{{ showMissing ? '▾' : '▸' }}</span>
            <span>Не заполнили: <b>{{ missingRestaurants.length }}</b> из {{ restaurantStore.restaurants.length }}</span>
          </div>
          <div v-if="showMissing" class="sc-missing-list">
            <span v-for="r in missingRestaurants" :key="r.number" class="sc-missing-tag">
              {{ formatRestaurantNumber(r.number, r.legal_entity_group) }}<template v-if="r.city"> · {{ r.city }}</template>
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Modals -->
    <Teleport to="body">
      <!-- Confirm modal -->
      <div v-if="confirmModal.show" class="modal modal-confirm">
        <div class="modal-box" style="max-width: 420px;">
          <div class="sc-modal-head">
            <h3>{{ confirmModal.title }}</h3>
            <button class="sc-x" @click="confirmModal.show = false">✕</button>
          </div>
          <p class="sc-confirm-text">{{ confirmModal.text }}</p>
          <div class="sc-modal-foot">
            <button class="sc-btn outline" @click="confirmModal.show = false">Отмена</button>
            <button class="sc-btn fill" :class="{ 'btn-danger': confirmModal.danger }" @click="confirmModal.action(); confirmModal.show = false;">
              {{ confirmModal.btnText }}
            </button>
          </div>
        </div>
      </div>

      <!-- Rename modal -->
      <div v-if="showRename" class="modal">
        <div class="modal-box" style="max-width: 420px;">
          <div class="sc-modal-head">
            <h3>Переименовать сбор</h3>
            <button class="sc-x" @click="showRename = false">✕</button>
          </div>
          <div class="sc-field">
            <label>Название</label>
            <input v-model="renameName" type="text" class="sc-input full" @keydown.enter="saveRename"/>
          </div>
          <div class="sc-modal-foot">
            <button class="sc-btn outline" @click="showRename = false">Отмена</button>
            <button class="sc-btn fill" @click="saveRename" :disabled="!renameName.trim()">Сохранить</button>
          </div>
        </div>
      </div>

      <!-- Token modal -->
      <div v-if="showTokenModal" class="modal">
        <div class="modal-box" style="max-width: 500px;">
          <div class="sc-modal-head">
            <h3>Ссылка для ресторанов</h3>
            <button class="sc-x" @click="showTokenModal = false">✕</button>
          </div>
          <p class="sc-confirm-text">Будет создана ссылка, действительная 48 часов. Отправьте её ресторанам для заполнения остатков.</p>
          <div v-if="tokenLink" class="sc-token" style="margin-top: 12px;">
            <div class="sc-token-row">
              <input :value="tokenLink" readonly class="sc-token-input" @focus="$event.target.select()"/>
              <button class="sc-btn sm fill" @click="copyToken">{{ copied ? '✓' : 'Копировать' }}</button>
            </div>
          </div>
          <div class="sc-modal-foot">
            <button v-if="!tokenLink" class="sc-btn fill" @click="doCreateToken" :disabled="creatingToken">
              {{ creatingToken ? '...' : 'Создать ссылку' }}
            </button>
            <button v-else class="sc-btn outline" @click="showTokenModal = false">Готово</button>
          </div>
        </div>
      </div>

      <!-- Create modal -->
      <div v-if="showCreate" class="modal" @click.self="tryCloseCreate">
        <div class="modal-box" style="max-width: 600px;">
          <div class="sc-modal-head">
            <h3>Новый сбор остатков</h3>
            <button class="sc-x" @click="tryCloseCreate">✕</button>
          </div>

          <div class="sc-field">
            <label>Название</label>
            <input v-model="newName" type="text" class="sc-input full" :placeholder="'Сбор ' + todayStr"/>
          </div>

          <div class="sc-field">
            <label>Товары</label>
            <div v-for="(p, i) in newProducts" :key="i" class="sc-product-card">
              <button v-if="newProducts.length > 1" class="sc-card-remove" @click="newProducts.splice(i, 1)">✕</button>

              <!-- Search / selected state -->
              <div v-if="p.fromDb" class="sc-product-selected">
                <div class="sc-product-selected-info">
                  <div class="sc-product-selected-name">{{ p.name }}</div>
                  <div class="sc-product-selected-meta">
                    <span v-if="p.sku">{{ p.sku }}</span>
                    <span v-if="p.supplier">{{ p.supplier }}</span>
                  </div>
                </div>
                <button class="sc-btn sm outline" @click="clearProductRow(i)">Изменить</button>
              </div>
              <div v-else class="sc-product-search">
                <input
                  v-model="p.searchQuery"
                  type="text"
                  placeholder="Найти товар в базе или ввести вручную..."
                  class="sc-input full"
                  @input="onProductInput(i)"
                  @focus="p.showDrop = true"
                  @keydown.escape="p.showDrop = false"
                />
                <div v-if="p.showDrop && p.results.length" class="sc-drop" @mousedown.prevent>
                  <div
                    v-for="r in p.results" :key="r.id"
                    class="sc-drop-item"
                    @click="pickProduct(i, r)"
                  >
                    <div class="sc-drop-name">{{ r.name }}</div>
                    <div class="sc-drop-meta">
                      {{ r.sku }}
                      <template v-if="r.supplier"> · {{ r.supplier }}</template>
                      <template v-if="r.qty_per_box"> · {{ r.qty_per_box }} шт/кор</template>
                    </div>
                  </div>
                  <div class="sc-drop-item sc-drop-manual" @click="setManual(i)">
                    <div class="sc-drop-name">Ввести вручную</div>
                    <div class="sc-drop-meta">Товара нет в базе — ввести название и артикул</div>
                  </div>
                </div>

                <!-- Manual input (when nothing selected from DB) -->
                <div v-if="p.searchQuery && !p.results.length && !p.searching" class="sc-manual-hint">
                  Не найдено в базе — можно
                  <button class="sc-link-btn" @click="setManual(i)">ввести вручную</button>
                </div>
                <div v-if="p.manual" class="sc-manual-fields">
                  <input v-model="p.name" type="text" placeholder="Название товара" class="sc-input full"/>
                  <input v-model="p.sku" type="text" placeholder="Артикул (SKU)" class="sc-input full" style="margin-top: 6px;"/>
                </div>
              </div>

              <!-- Unit selector -->
              <div class="sc-product-unit-row">
                <span class="sc-product-unit-label">Единица сбора:</span>
                <template v-if="p.unitLocked">
                  <span class="sc-unit-locked">{{ unitLabel(p.unit) }}</span>
                </template>
                <template v-else>
                  <div class="sc-switcher">
                    <button :class="{ on: p.unit === 'boxes' }" @click="p.unit = 'boxes'">Коробки</button>
                    <button :class="{ on: p.unit === 'pieces' }" @click="p.unit = 'pieces'">Штуки</button>
                    <button :class="{ on: p.unit === 'kg' }" @click="p.unit = 'kg'">Кг</button>
                    <button :class="{ on: p.unit === 'liters' }" @click="p.unit = 'liters'">Литры</button>
                  </div>
                </template>
              </div>

              <!-- Note -->
              <input v-model="p.note" type="text" placeholder="Примечание (видно сборщикам)" class="sc-input full sc-note-input" />
            </div>
            <button class="sc-btn outline full" @click="addProductRow" style="margin-top: 8px;">+ Добавить товар</button>
          </div>

          <div class="sc-modal-foot">
            <button class="sc-btn outline" @click="tryCloseCreate">Отмена</button>
            <button class="sc-btn fill" @click="createCollection" :disabled="!canCreate || creating">
              {{ creating ? '...' : 'Создать' }}
            </button>
          </div>
        </div>
      </div>
      <!-- Edit products modal -->
      <div v-if="showEditProducts" class="modal">
        <div class="modal-box" style="max-width: 600px;">
          <div class="sc-modal-head">
            <h3>Редактирование товаров</h3>
            <button class="sc-x" @click="showEditProducts = false">✕</button>
          </div>

          <div class="sc-field">
            <label>Товары в сборе</label>
            <div v-for="(p, i) in editProducts" :key="p.id || ('new_' + i)" class="sc-product-card">
              <button v-if="editProducts.length > 1" class="sc-card-remove" @click="removeEditProduct(i)">✕</button>

              <!-- Existing product: editable fields -->
              <div v-if="p.id && !p._searchMode" class="sc-product-edit-fields">
                <div class="sc-product-edit-row">
                  <input v-model="p.product_name" type="text" placeholder="Название товара" class="sc-input full"/>
                </div>
                <div class="sc-product-edit-row" style="margin-top: 6px;">
                  <input v-model="p.product_sku" type="text" placeholder="Артикул (SKU)" class="sc-input" style="width: 140px;"/>
                </div>
              </div>

              <!-- New product: search or manual entry -->
              <div v-else class="sc-product-search">
                <div v-if="p._fromDb" class="sc-product-selected">
                  <div class="sc-product-selected-info">
                    <div class="sc-product-selected-name">{{ p.product_name }}</div>
                    <div class="sc-product-selected-meta">
                      <span v-if="p.product_sku">{{ p.product_sku }}</span>
                    </div>
                  </div>
                  <button class="sc-btn sm outline" @click="p._fromDb = false; p._searchQuery = ''; p.product_name = ''; p.product_sku = '';">Изменить</button>
                </div>
                <template v-else>
                  <input
                    v-model="p._searchQuery"
                    type="text"
                    placeholder="Найти товар в базе или ввести вручную..."
                    class="sc-input full"
                    @input="onEditProductInput(i)"
                    @focus="p._showDrop = true"
                    @keydown.escape="p._showDrop = false"
                  />
                  <div v-if="p._showDrop && p._results?.length" class="sc-drop" @mousedown.prevent>
                    <div
                      v-for="r in p._results" :key="r.id"
                      class="sc-drop-item"
                      @click="pickEditProduct(i, r)"
                    >
                      <div class="sc-drop-name">{{ r.name }}</div>
                      <div class="sc-drop-meta">
                        {{ r.sku }}
                        <template v-if="r.supplier"> · {{ r.supplier }}</template>
                      </div>
                    </div>
                    <div class="sc-drop-item sc-drop-manual" @click="setEditManual(i)">
                      <div class="sc-drop-name">Ввести вручную</div>
                      <div class="sc-drop-meta">Товара нет в базе — ввести название и артикул</div>
                    </div>
                  </div>
                  <div v-if="p._searchQuery && !p._results?.length && !p._searching" class="sc-manual-hint">
                    Не найдено в базе — можно
                    <button class="sc-link-btn" @click="setEditManual(i)">ввести вручную</button>
                  </div>
                  <div v-if="p._manual" class="sc-manual-fields">
                    <input v-model="p.product_name" type="text" placeholder="Название товара" class="sc-input full"/>
                    <input v-model="p.product_sku" type="text" placeholder="Артикул (SKU)" class="sc-input full" style="margin-top: 6px;"/>
                  </div>
                </template>
              </div>

              <!-- Unit selector -->
              <div class="sc-product-unit-row">
                <span class="sc-product-unit-label">Единица сбора:</span>
                <div class="sc-switcher">
                  <button :class="{ on: p.unit === 'boxes' }" @click="p.unit = 'boxes'">Коробки</button>
                  <button :class="{ on: p.unit === 'pieces' }" @click="p.unit = 'pieces'">Штуки</button>
                  <button :class="{ on: p.unit === 'kg' }" @click="p.unit = 'kg'">Кг</button>
                  <button :class="{ on: p.unit === 'liters' }" @click="p.unit = 'liters'">Литры</button>
                </div>
              </div>

              <!-- Note -->
              <input v-model="p.note" type="text" placeholder="Примечание (видно сборщикам)" class="sc-input full sc-note-input" />

              <!-- Warning if product has data and being deleted -->
              <div v-if="p._markedForDelete" class="sc-product-delete-warn">
                Будет удалён при сохранении (вместе с собранными остатками)
                <button class="sc-link-btn" @click="p._markedForDelete = false; editProducts.splice(i, 0, editProducts.splice(i, 1)[0]);">Отменить</button>
              </div>
            </div>
            <button class="sc-btn outline full" @click="addEditProductRow" style="margin-top: 8px;">+ Добавить товар</button>
          </div>

          <div class="sc-modal-foot">
            <button class="sc-btn outline" @click="showEditProducts = false">Отмена</button>
            <button class="sc-btn fill" @click="saveEditProducts" :disabled="savingProducts || !canSaveProducts">
              {{ savingProducts ? '...' : 'Сохранить' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue';
import { db } from '@/lib/apiClient.js';
import { formatRestaurantNumber } from '@/lib/legalEntities.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { useRestaurantStore } from '@/stores/restaurantStore.js';

const orderStore = useOrderStore();
const userStore = useUserStore();
const toastStore = useToastStore();
const restaurantStore = useRestaurantStore();

const loading = ref(true);
const loadError = ref(false);
const collections = ref([]);
const activeCollection = ref(null);
const collectionData = ref(null);

// Create modal
const showCreate = ref(false);
const creating = ref(false);
const newName = ref('');
const newProducts = ref([makeProductRow()]);
const canCreate = computed(() => newName.value.trim() && newProducts.value.some(p => p.name.trim() || p.fromDb));

// Token
const tokenLink = ref('');
const copied = ref(false);
const creatingToken = ref(false);
const showTokenModal = ref(false);
const notifying = ref(false);

// Confirm modal
const confirmModal = ref({ show: false, title: '', text: '', btnText: '', danger: false, action: () => {} });

// Rename
const showRename = ref(false);
const renameName = ref('');

// Filter & Sort
const responseFilter = ref('');
const sortKey = ref('restaurant');
const sortDir = ref('asc');

// Cell edit
const editingCell = ref(null);
const editStockValue = ref('');

// Missing restaurants
const showMissing = ref(true);

const todayStr = new Date().toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });

// Close dropdowns on outside click
function handleDocClick(e) {
  if (!e.target.closest('.sc-product-search')) {
    for (const p of newProducts.value) p.showDrop = false;
  }
}
onMounted(() => { loadCollections(); restaurantStore.load(orderStore.settings.legalEntity); document.addEventListener('click', handleDocClick); });
onUnmounted(() => {
  document.removeEventListener('click', handleDocClick);
  Object.values(searchTimers).forEach(t => clearTimeout(t));
  searchTimers = {};
});
watch(() => orderStore.settings.legalEntity, () => { collectionData.value = null; loadCollections(); restaurantStore.invalidate(); restaurantStore.load(orderStore.settings.legalEntity); });

const uniqueRestaurants = computed(() => {
  if (!collectionData.value?.data) return 0;
  return new Set(collectionData.value.data.map(d => d.restaurant_number)).size;
});

const missingRestaurants = computed(() => {
  if (!collectionData.value?.data || !restaurantStore.restaurants.length) return [];
  const answered = new Set(collectionData.value.data.map(d => String(d.restaurant_number)));
  return restaurantStore.restaurants
    .filter(r => !answered.has(String(r.number)))
    .sort((a, b) => String(a.number).localeCompare(String(b.number), undefined, { numeric: true }));
});

function makeProductRow() {
  return { name: '', sku: '', unit: 'pieces', unitLocked: false, fromDb: false, results: [], showDrop: false, searchQuery: '', manual: false, supplier: '', searching: false, note: '' };
}
function addProductRow() {
  newProducts.value.push(makeProductRow());
}
function clearProductRow(i) {
  const p = newProducts.value[i];
  Object.assign(p, { name: '', sku: '', unit: 'pieces', unitLocked: false, fromDb: false, results: [], searchQuery: '', manual: false, supplier: '', searching: false });
}
function setManual(i) {
  const p = newProducts.value[i];
  p.manual = true;
  p.name = p.searchQuery;
  p.showDrop = false;
}

// Product search
let searchTimers = {};
function onProductInput(i) {
  const p = newProducts.value[i];
  p.fromDb = false;
  p.manual = false;
  p.showDrop = true;
  clearTimeout(searchTimers[i]);
  if (p.searchQuery.length < 2) { p.results = []; p.searching = false; return; }
  p.searching = true;
  searchTimers[i] = setTimeout(() => searchProduct(i), 250);
}
async function searchProduct(i) {
  const p = newProducts.value[i];
  try {
    const le = orderStore.settings.legalEntity;
    const params = new URLSearchParams({ q: p.searchQuery, legal_entity: le, limit: '10' });
    const r = await fetch(`/api/search_products?${params}`, {
      headers: { 'X-Session-Token': localStorage.getItem('bk_session_token') || '' },
    });
    if (r.ok) p.results = await r.json();
  } catch { p.results = []; } finally { p.searching = false; }
}
function pickProduct(i, product) {
  const p = newProducts.value[i];
  p.name = product.name;
  p.sku = product.sku || '';
  p.supplier = product.supplier || '';
  p.fromDb = true;
  p.manual = false;
  p.showDrop = false;
  p.results = [];
  p.searchQuery = '';
  // Единица измерения из карточки товара — блокируем выбор
  const uom = product.unit_of_measure;
  if (uom === 'кг') { p.unit = 'kg'; p.unitLocked = true; }
  else if (uom === 'л') { p.unit = 'liters'; p.unitLocked = true; }
  else { p.unit = 'pieces'; p.unitLocked = true; }
}

// Collections CRUD
async function loadCollections() {
  loading.value = true;
  loadError.value = false;
  try {
    const { data } = await db.from('stock_collections')
      .select('*')
      .eq('legal_entity', orderStore.settings.legalEntity)
      .order('created_at', { ascending: false })
      .limit(50);
    collections.value = data || [];
  } catch {
    loadError.value = true;
    toastStore.error('Ошибка', 'Не удалось загрузить сессии сбора');
  } finally { loading.value = false; }
}

function openCreateModal() {
  newName.value = '';
  newProducts.value = [makeProductRow()];
  showCreate.value = true;
}

function duplicateCollection() {
  const products = collectionData.value?.products || [];
  if (!products.length) { toastStore.error('Нет товаров для копирования'); return; }
  newName.value = (activeCollection.value?.name || 'Сбор') + ' (копия)';
  newProducts.value = products.map(p => ({
    ...makeProductRow(),
    name: p.product_name || '',
    sku: p.product_sku || '',
    unit: p.unit || 'pieces',
    note: p.note || '',
    fromDb: true,
  }));
  activeCollection.value = null;
  showCreate.value = true;
}

function isCreateDirty() {
  if (newName.value.trim()) return true;
  return newProducts.value.some(p => p.name.trim() || p.fromDb || p.searchQuery.trim());
}

function tryCloseCreate() {
  if (!isCreateDirty()) { showCreate.value = false; return; }
  confirmModal.value = {
    show: true, title: 'Закрыть форму?', danger: false,
    text: 'Вы уже начали заполнять сбор. Все введённые данные будут потеряны.',
    btnText: 'Закрыть',
    action: () => { showCreate.value = false; },
  };
}

async function createCollection() {
  creating.value = true;
  try {
    const products = newProducts.value.filter(p => p.name.trim()).map(p => ({
      name: p.name.trim(),
      sku: p.sku.trim() || null,
      unit: p.unit,
      note: p.note.trim() || null,
    }));
    const { data } = await db.rpc('sc_create_collection', {
      legal_entity: orderStore.settings.legalEntity,
      name: newName.value.trim() || `Сбор ${todayStr}`,
      products,
      user_name: userStore.currentUser?.name || '',
    });
    if (data?.id) {
      toastStore.success('Создано', 'Сессия сбора создана');
      showCreate.value = false;
      await loadCollections();
      const coll = collections.value.find(c => c.id === data.id);
      if (coll) {
        await openCollection(coll);
        // Токен создаётся автоматически — подхватываем из ответа
        if (data.token) {
          tokenLink.value = `${window.location.origin}/stock-form/${data.token}`;
        }
      }
    }
  } catch { toastStore.error('Ошибка', 'Не удалось создать'); } finally { creating.value = false; }
}

async function openCollection(c) {
  activeCollection.value = c;
  tokenLink.value = '';
  editingCell.value = null;
  await Promise.all([refreshData(), loadActiveToken(c.id)]);
}

async function loadActiveToken(collectionId) {
  try {
    const { data } = await db.from('stock_collection_tokens')
      .select('token,expires_at')
      .eq('collection_id', collectionId)
      .order('expires_at', { ascending: false })
      .limit(1);
    if (data?.length) {
      const t = data[0];
      if (new Date(t.expires_at) > new Date()) {
        tokenLink.value = `${window.location.origin}/stock-form/${t.token}`;
      }
    }
  } catch {}
}

async function refreshData() {
  if (!activeCollection.value) return;
  try {
    const { data } = await db.rpc('sc_get_collection_data', { collection_id: activeCollection.value.id });
    collectionData.value = data;
  } catch { toastStore.error('Ошибка', 'Не удалось загрузить данные'); }
}

// Token modal
function openTokenModal() {
  tokenLink.value = '';
  copied.value = false;
  showTokenModal.value = true;
}
async function doCreateToken() {
  creatingToken.value = true;
  try {
    const { data } = await db.rpc('sc_create_token', {
      collection_id: activeCollection.value.id,
      user_name: userStore.currentUser?.name || '',
    });
    if (data?.token) {
      tokenLink.value = `${window.location.origin}/stock-form/${data.token}`;
    }
  } catch { toastStore.error('Ошибка', 'Не удалось создать ссылку'); } finally { creatingToken.value = false; }
}

async function notifyRestaurants() {
  if (!activeCollection.value) return
  notifying.value = true
  try {
    const { data, error } = await db.rpc('sc_notify_restaurants', { collection_id: activeCollection.value.id })
    if (error) throw new Error(error)
    toastStore.show(`Уведомления отправлены (${data.sent})`)
  } catch (e) { toastStore.error('Ошибка', e.message || e) }
  finally { notifying.value = false }
}

function copyToken() {
  navigator.clipboard.writeText(tokenLink.value).then(() => {
    copied.value = true;
    setTimeout(() => copied.value = false, 2000);
  }).catch(() => { toastStore.error('Ошибка', 'Не удалось скопировать'); });
}

// Close collection
function askCloseCollection() {
  confirmModal.value = {
    show: true, title: 'Закрыть сбор', danger: true,
    text: 'Рестораны больше не смогут отправить остатки по ссылке. Данные сохранятся.',
    btnText: 'Закрыть сбор',
    action: doCloseCollection,
  };
}
async function doCloseCollection() {
  try {
    await db.rpc('sc_close_collection', { collection_id: activeCollection.value.id });
    activeCollection.value.status = 'closed';
    toastStore.success('Закрыт', 'Сбор закрыт');
  } catch { toastStore.error('Ошибка', 'Не удалось закрыть'); }
}

// Delete collection
function askDeleteCollection() {
  confirmModal.value = {
    show: true, title: 'Удалить сбор', danger: true,
    text: `Сбор «${activeCollection.value.name}» и все собранные данные будут удалены. Это нельзя отменить.`,
    btnText: 'Удалить',
    action: doDeleteCollection,
  };
}
async function doDeleteCollection() {
  try {
    const { data, error } = await db.rpc('sc_delete_collection', { collection_id: activeCollection.value.id });
    if (error) { toastStore.error('Ошибка', 'Не удалось удалить сбор'); return; }
    activeCollection.value = null;
    collectionData.value = null;
    toastStore.success('Удалено', 'Сбор удалён');
    await loadCollections();
  } catch { toastStore.error('Ошибка', 'Не удалось удалить'); }
}

// Rename
function openRename() {
  renameName.value = activeCollection.value.name;
  showRename.value = true;
}
async function saveRename() {
  if (!renameName.value.trim()) return;
  try {
    const { error } = await db.from('stock_collections').update({ name: renameName.value.trim() }).eq('id', activeCollection.value.id).eq('legal_entity', orderStore.settings.legalEntity);
    if (error) { toastStore.error('Ошибка', 'Не удалось переименовать'); return; }
    activeCollection.value.name = renameName.value.trim();
    showRename.value = false;
    toastStore.success('Сохранено', '');
    const c = collections.value.find(x => x.id === activeCollection.value.id);
    if (c) c.name = renameName.value.trim();
  } catch { toastStore.error('Ошибка', 'Не удалось переименовать'); }
}

function getProductData(productId) {
  if (!collectionData.value?.data) return [];
  return collectionData.value.data.filter(d => d.product_id === productId);
}

function getProductTotal(productId) {
  const total = getProductData(productId).reduce((s, d) => s + (parseFloat(d.stock) || 0), 0);
  return parseFloat(total.toFixed(2));
}

// Merged table: one row per restaurant, columns = products
function getRestaurantInfo(num) {
  const r = restaurantStore.restaurants.find(r => String(r.number) === String(num));
  return r ? { city: r.city || '', address: r.address || '' } : { city: '', address: '' };
}

const mergedRows = computed(() => {
  if (!collectionData.value?.data?.length) return [];
  const allData = collectionData.value.data;
  const restMap = new Map();
  for (const d of allData) {
    const key = String(d.restaurant_number);
    if (!restMap.has(key)) {
      const info = getRestaurantInfo(key);
      restMap.set(key, { restaurant: key, city: info.city, address: info.address, cells: {}, submittedAt: null });
    }
    const row = restMap.get(key);
    row.cells[d.product_id] = d;
    if (d.submitted_at && (!row.submittedAt || d.submitted_at > row.submittedAt)) {
      row.submittedAt = d.submitted_at;
    }
  }
  return [...restMap.values()].sort((a, b) =>
    a.restaurant.localeCompare(b.restaurant, undefined, { numeric: true })
  );
});

function toggleSort(key) {
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
  } else {
    sortKey.value = key;
    sortDir.value = 'asc';
  }
}

const filteredRows = computed(() => {
  const q = responseFilter.value.trim().toLowerCase();
  let rows = mergedRows.value;
  if (q) {
    rows = rows.filter(row =>
      row.restaurant.toLowerCase().includes(q) ||
      row.city.toLowerCase().includes(q) ||
      row.address.toLowerCase().includes(q)
    );
  }
  const key = sortKey.value;
  const dir = sortDir.value === 'asc' ? 1 : -1;
  return [...rows].sort((a, b) => {
    if (key === 'restaurant') {
      return dir * a.restaurant.localeCompare(b.restaurant, undefined, { numeric: true });
    }
    if (key === 'city') return dir * a.city.localeCompare(b.city, 'ru');
    if (key === 'address') return dir * a.address.localeCompare(b.address, 'ru');
    if (key === 'time') {
      return dir * ((a.submittedAt || '') < (b.submittedAt || '') ? -1 : (a.submittedAt || '') > (b.submittedAt || '') ? 1 : 0);
    }
    // Sort by product column
    if (key.startsWith('prod_')) {
      const prodId = parseInt(key.slice(5));
      const va = parseFloat(a.cells[prodId]?.stock) || 0;
      const vb = parseFloat(b.cells[prodId]?.stock) || 0;
      return dir * (va - vb);
    }
    return 0;
  });
});

// Cell edit
function startCellEdit(row, prodId) {
  const d = row.cells[prodId];
  if (!d) return;
  editingCell.value = row.restaurant + '_' + prodId;
  editStockValue.value = String(d.stock);
  nextTick(() => {
    const inp = document.querySelector('.sc-cell-input');
    if (inp) inp.focus();
  });
}

async function saveCellEdit(d) {
  if (!d) return;
  const val = parseFloat(String(editStockValue.value).replace(',', '.'));
  if (isNaN(val)) { toastStore.error('Ошибка', 'Неверное значение'); return; }
  try {
    const { error } = await db.from('stock_collection_data').update({ stock: val }).eq('id', d.id);
    if (error) { toastStore.error('Ошибка', 'Не удалось сохранить'); return; }
    d.stock = val;
    editingCell.value = null;
    toastStore.success('Сохранено', '');
  } catch { toastStore.error('Ошибка', 'Не удалось сохранить'); }
}

function deleteRestaurantRow(row) {
  confirmModal.value = {
    show: true, title: 'Удалить ресторан', danger: true,
    text: `Удалить все остатки ресторана ${row.restaurant}?`,
    btnText: 'Удалить',
    action: async () => {
      try {
        const ids = Object.values(row.cells).map(d => d.id).filter(Boolean);
        for (const id of ids) {
          const { error } = await db.from('stock_collection_data').delete().eq('id', id);
          if (error) { toastStore.error('Ошибка', 'Не удалось удалить'); return; }
        }
        await refreshData();
        toastStore.success('Удалено', '');
      } catch { toastStore.error('Ошибка', 'Не удалось удалить'); }
    },
  };
}

function deleteEntry(d) {
  confirmModal.value = {
    show: true, title: 'Удалить запись', danger: true,
    text: `Удалить остаток ресторана ${d.restaurant_number}?`,
    btnText: 'Удалить',
    action: async () => {
      try {
        await db.from('stock_collection_data').delete().eq('id', d.id);
        await refreshData();
        toastStore.success('Удалено', '');
      } catch { toastStore.error('Ошибка', 'Не удалось удалить'); }
    },
  };
}

// ═══ Edit products ═══
const showEditProducts = ref(false);
const editProducts = ref([]);
const savingProducts = ref(false);
let editSearchTimers = {};

const canSaveProducts = computed(() => {
  const active = editProducts.value.filter(p => !p._markedForDelete);
  return active.length > 0 && active.every(p => (p.product_name || '').trim());
});

function openEditProducts() {
  if (!collectionData.value?.products) return;
  editProducts.value = collectionData.value.products.map(p => ({
    ...p,
    note: p.note || '',
    _original: { ...p },
    _markedForDelete: false,
    _searchMode: false,
  }));
  showEditProducts.value = true;
}

function addEditProductRow() {
  editProducts.value.push({
    id: null,
    product_name: '',
    product_sku: '',
    unit: 'pieces',
    note: '',
    sort_order: editProducts.value.length,
    _searchMode: true,
    _fromDb: false,
    _searchQuery: '',
    _results: [],
    _showDrop: false,
    _searching: false,
    _manual: false,
    _markedForDelete: false,
  });
}

function removeEditProduct(i) {
  const p = editProducts.value[i];
  if (p.id) {
    // Existing product — check if it has collected data
    const hasData = collectionData.value?.data?.some(d => d.product_id === p.id);
    if (hasData) {
      confirmModal.value = {
        show: true, title: 'Удалить товар', danger: true,
        text: `Товар «${p.product_name}» уже содержит собранные остатки. При удалении все эти данные будут потеряны. Продолжить?`,
        btnText: 'Удалить',
        action: () => { editProducts.value.splice(i, 1); },
      };
    } else {
      editProducts.value.splice(i, 1);
    }
  } else {
    editProducts.value.splice(i, 1);
  }
}

function onEditProductInput(i) {
  const p = editProducts.value[i];
  p._fromDb = false;
  p._manual = false;
  p._showDrop = true;
  clearTimeout(editSearchTimers[i]);
  if ((p._searchQuery || '').length < 2) { p._results = []; p._searching = false; return; }
  p._searching = true;
  editSearchTimers[i] = setTimeout(() => searchEditProduct(i), 250);
}

async function searchEditProduct(i) {
  const p = editProducts.value[i];
  try {
    const le = orderStore.settings.legalEntity;
    const params = new URLSearchParams({ q: p._searchQuery, legal_entity: le, limit: '10' });
    const r = await fetch(`/api/search_products?${params}`, {
      headers: { 'X-Session-Token': localStorage.getItem('bk_session_token') || '' },
    });
    if (r.ok) p._results = await r.json();
  } catch { p._results = []; } finally { p._searching = false; }
}

function pickEditProduct(i, product) {
  const p = editProducts.value[i];
  p.product_name = product.name;
  p.product_sku = product.sku || '';
  p._fromDb = true;
  p._manual = false;
  p._showDrop = false;
  p._results = [];
  p._searchQuery = '';
  const uom = product.unit_of_measure;
  if (uom === 'кг') p.unit = 'kg';
  else if (uom === 'л') p.unit = 'liters';
  else p.unit = 'pieces';
}

function setEditManual(i) {
  const p = editProducts.value[i];
  p._manual = true;
  p.product_name = p._searchQuery;
  p._showDrop = false;
}

async function saveEditProducts() {
  savingProducts.value = true;
  try {
    const collId = activeCollection.value.id;
    const active = editProducts.value.filter(p => !p._markedForDelete);

    // 1. Delete removed products (existing ones that are no longer in the list)
    const originalIds = (collectionData.value?.products || []).map(p => p.id);
    const keepIds = active.filter(p => p.id).map(p => p.id);
    const toDelete = originalIds.filter(id => !keepIds.includes(id));

    for (const id of toDelete) {
      // Delete associated data first
      await db.from('stock_collection_data').delete().eq('product_id', id);
      await db.from('stock_collection_products').delete().eq('id', id);
    }

    // 2. Update existing products
    for (const p of active.filter(p => p.id)) {
      const orig = p._original;
      if (orig && (p.product_name !== orig.product_name || p.product_sku !== orig.product_sku || p.unit !== orig.unit || p.note !== orig.note)) {
        await db.from('stock_collection_products').update({
          product_name: p.product_name.trim(),
          product_sku: (p.product_sku || '').trim() || null,
          unit: p.unit,
          note: (p.note || '').trim() || null,
        }).eq('id', p.id);
      }
    }

    // 3. Insert new products
    const newOnes = active.filter(p => !p.id && (p.product_name || '').trim());
    for (let i = 0; i < newOnes.length; i++) {
      const p = newOnes[i];
      await db.from('stock_collection_products').insert({
        collection_id: collId,
        product_name: p.product_name.trim(),
        product_sku: (p.product_sku || '').trim() || null,
        unit: p.unit,
        sort_order: keepIds.length + i,
        note: (p.note || '').trim() || null,
      });
    }

    // 4. Update sort_order for all
    for (let i = 0; i < active.length; i++) {
      const p = active[i];
      if (p.id && p.sort_order !== i) {
        await db.from('stock_collection_products').update({ sort_order: i }).eq('id', p.id);
      }
    }

    showEditProducts.value = false;
    toastStore.success('Сохранено', 'Список товаров обновлён');
    await refreshData();
  } catch (e) {
    toastStore.error('Ошибка', 'Не удалось сохранить изменения');
  } finally { savingProducts.value = false; }
}

// Export
async function exportExcel() {
  if (!collectionData.value) return;
  const XLSX = await import('xlsx-js-style');
  const products = collectionData.value.products || [];
  const allData = collectionData.value.data || [];

  // Collect all unique restaurants
  const restSet = new Set(allData.map(d => d.restaurant_number));
  const restNums = [...restSet].sort((a, b) => String(a).localeCompare(String(b), undefined, { numeric: true }));

  const brown = '502314';
  const bdr = { style: 'thin', color: { rgb: 'E0D6CC' } };
  const borders = { top: bdr, bottom: bdr, left: bdr, right: bdr };
  const sH = { font: { bold: true, sz: 11, color: { rgb: 'FFFFFF' }, name: 'Calibri' }, fill: { fgColor: { rgb: brown } }, alignment: { horizontal: 'center', vertical: 'center' }, border: borders };
  const sC = (stripe) => ({ font: { sz: 11, name: 'Calibri' }, fill: stripe ? { fgColor: { rgb: 'FFF8F0' } } : undefined, alignment: { vertical: 'center' }, border: borders });
  const sB = (stripe) => ({ font: { bold: true, sz: 11, name: 'Calibri' }, fill: stripe ? { fgColor: { rgb: 'FFF8F0' } } : undefined, alignment: { vertical: 'center' }, border: borders });

  const ws = {};
  let r = 0;

  // Title
  ws[XLSX.utils.encode_cell({ r, c: 0 })] = { v: activeCollection.value.name, t: 's', s: { font: { bold: true, sz: 14, color: { rgb: brown }, name: 'Calibri' } } };
  r += 2;

  // Headers: Ресторан | Город | Адрес | Product1 (unit) | Product2 (unit) | ...
  const cols = [{ wch: 12 }, { wch: 14 }, { wch: 24 }];
  ws[XLSX.utils.encode_cell({ r, c: 0 })] = { v: 'Ресторан', t: 's', s: sH };
  ws[XLSX.utils.encode_cell({ r, c: 1 })] = { v: 'Город', t: 's', s: sH };
  ws[XLSX.utils.encode_cell({ r, c: 2 })] = { v: 'Адрес', t: 's', s: sH };
  const prodOffset = 3;
  products.forEach((p, i) => {
    const ul = unitLabel(p.unit);
    ws[XLSX.utils.encode_cell({ r, c: i + prodOffset })] = { v: `${p.product_name} (${ul})`, t: 's', s: sH };
    cols.push({ wch: Math.max(16, p.product_name.length + 8) });
  });
  r++;

  // Data rows
  const dataMap = new Map();
  for (const d of allData) {
    const key = `${d.restaurant_number}_${d.product_id}`;
    dataMap.set(key, d.stock);
  }

  restNums.forEach((num, ri) => {
    const stripe = ri % 2 === 1;
    const info = getRestaurantInfo(num);
    ws[XLSX.utils.encode_cell({ r, c: 0 })] = { v: `Ресторан ${num}`, t: 's', s: sB(stripe) };
    ws[XLSX.utils.encode_cell({ r, c: 1 })] = { v: info.city, t: 's', s: sC(stripe) };
    ws[XLSX.utils.encode_cell({ r, c: 2 })] = { v: info.address, t: 's', s: sC(stripe) };
    products.forEach((p, pi) => {
      const val = dataMap.get(`${num}_${p.id}`) ?? '';
      ws[XLSX.utils.encode_cell({ r, c: pi + prodOffset })] = { v: val === '' ? '' : Number(val), t: val === '' ? 's' : 'n', s: sC(stripe) };
    });
    r++;
  });

  // Totals
  const sBold = { font: { bold: true, sz: 11, color: { rgb: brown }, name: 'Calibri' }, border: borders };
  ws[XLSX.utils.encode_cell({ r, c: 0 })] = { v: 'Итого', t: 's', s: sBold };
  ws[XLSX.utils.encode_cell({ r, c: 1 })] = { v: '', t: 's', s: sBold };
  ws[XLSX.utils.encode_cell({ r, c: 2 })] = { v: '', t: 's', s: sBold };
  products.forEach((p, pi) => {
    const total = allData.filter(d => d.product_id === p.id).reduce((s, d) => s + (parseFloat(d.stock) || 0), 0);
    ws[XLSX.utils.encode_cell({ r, c: pi + prodOffset })] = { v: parseFloat(total.toFixed(2)), t: 'n', s: sBold };
  });

  ws['!ref'] = XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r, c: products.length + prodOffset - 1 } });
  ws['!cols'] = cols;

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Остатки');
  const safeName = activeCollection.value.name.replace(/[^а-яА-ЯёЁa-zA-Z0-9\s]/g, '').trim();
  XLSX.writeFile(wb, `Остатки_${safeName}_${new Date().toLocaleDateString('ru-RU')}.xlsx`);
}

function sourceLabel(s) {
  if (s === 'form') return 'Форма';
  if (s === 'file') return 'Файл';
  return 'Вручную';
}

function unitLabel(u) {
  if (u === 'boxes') return 'кор.';
  if (u === 'kg') return 'кг';
  if (u === 'liters') return 'л';
  return 'шт.';
}

function fmtShort(s) {
  if (!s) return '';
  const d = new Date(s);
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' }) + ' ' + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

function fmtDate(s) {
  if (!s) return '';
  const d = new Date(s);
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' }) + ' ' + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}
function fmtTime(s) {
  if (!s) return '';
  return new Date(s).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}
</script>

<style scoped>
.retry-banner {
  display: flex; align-items: center; gap: 12px; padding: 12px 16px;
  background: #FFF3E0; border: 1px solid #FFE0B2; border-radius: 8px;
  color: #E65100; font-size: 13px; margin-bottom: 16px;
}
.retry-banner .btn { flex-shrink: 0; }
.sc { --brown: #502314; --orange: #F4A261; --red: #E76F51; --green: #2E7D32; --border: #EDE7DF; --muted: #8C7B6E; --bg2: #F9F6F2; }
.modal-confirm { z-index: 10001; }

/* Top */
.sc-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
.sc-title { font-family: 'Flame', sans-serif; font-size: 22px; font-weight: 700; color: var(--brown); margin: 0; }

/* List */
.sc-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; }
.sc-card {
  background: #fff; border: 1px solid var(--border); border-radius: 12px;
  padding: 16px; cursor: pointer; transition: all 0.12s;
}
.sc-card:hover { border-color: var(--orange); box-shadow: 0 2px 12px rgba(44,24,16,0.08); }
.sc-card.closed { opacity: 0.55; }
.sc-card-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.sc-card-name { font-size: 15px; font-weight: 700; color: var(--brown); }
.sc-card-meta { font-size: 12px; color: var(--muted); margin-top: 4px; }
.sc-tag { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 8px; white-space: nowrap; }
.sc-tag.green { background: #E8F5E9; color: #2E7D32; }
.sc-tag.gray { background: #f0ece6; color: #999; }
.sc-empty { text-align: center; color: var(--muted); padding: 40px; font-size: 14px; }

/* Missing restaurants */
.sc-missing {
  background: #FFF8E1; border: 1px solid #FFE082; border-radius: 10px;
  padding: 10px 14px; margin-top: 12px;
}
.sc-missing-head {
  font-size: 13px; color: #F57F17; cursor: pointer; user-select: none;
  display: flex; align-items: center; gap: 6px;
}
.sc-missing-head b { color: #E65100; }
.sc-missing-icon { font-size: 10px; width: 12px; }
.sc-missing-list { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.sc-missing-tag {
  font-size: 11px; padding: 3px 8px; background: #fff; border: 1px solid #FFE082;
  border-radius: 6px; color: #795548; white-space: nowrap;
}

/* Detail */
.sc-detail-bar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
.sc-detail-info { display: flex; align-items: center; gap: 8px; flex: 1; min-width: 0; }
.sc-detail-name { font-size: 18px; font-weight: 700; color: var(--brown); font-family: 'Flame', sans-serif; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sc-detail-actions { display: flex; gap: 8px; flex-wrap: wrap; }

/* Token */
.sc-token { background: #FFFBF0; border: 1px solid #FFE082; border-radius: 10px; padding: 12px; margin-bottom: 16px; }
.sc-token-label { font-size: 11px; font-weight: 600; color: #F57F17; margin-bottom: 6px; }
.sc-token-row { display: flex; gap: 6px; }
.sc-token-input { flex: 1; padding: 6px 8px; border: 1px solid #FFE082; border-radius: 6px; font-size: 11px; background: #fff; font-family: monospace; }

/* Summary */
.sc-summary {
  display: flex; align-items: center; gap: 16px; padding: 12px 16px;
  background: #fff; border: 1px solid var(--border); border-radius: 10px;
  margin-bottom: 16px;
}
.sc-summary-item { text-align: center; }
.sc-summary-num { font-size: 18px; font-weight: 700; color: var(--brown); font-family: 'Flame', sans-serif; }
.sc-summary-lbl { font-size: 10px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.3px; }

/* Filter */
.sc-filter-bar { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
.sc-filter-input { max-width: 320px; padding: 7px 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 13px; color: var(--brown); background: #fff; }
.sc-filter-input:focus { outline: none; border-color: var(--orange); box-shadow: 0 0 0 2px rgba(255,135,50,0.15); }
.sc-filter-input::placeholder { color: #bbb; }
.sc-filter-count { font-size: 12px; color: var(--muted); white-space: nowrap; }
th.sortable { cursor: pointer; user-select: none; }
th.sortable:hover { background: rgba(80,35,20,0.08); }
.sort-arrow { font-size: 10px; opacity: 0.4; margin-left: 2px; }
th.sortable:hover .sort-arrow { opacity: 0.7; }

/* Table */
.sc-tbl-wrap {
  background: #fff; border: 1px solid var(--border); border-radius: 10px;
  overflow-x: auto;
}
.sc-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.sc-tbl thead tr { background: #502314; }
.sc-tbl thead th {
  color: #fff; font-size: 11px; font-weight: 600;
  padding: 6px 5px; text-align: center;
}
.sc-tbl thead th.col-prod { white-space: normal; word-break: break-word; max-width: 80px; font-size: 10px; line-height: 1.3; }
.sc-tbl thead th .th-unit { font-weight: 400; font-size: 9px; opacity: 0.7; margin-top: 1px; }
.sc-tbl thead th .th-note { font-weight: 400; font-size: 9px; color: #c88; font-style: italic; margin-top: 1px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 120px; }
.sc-tbl tbody td {
  padding: 5px 5px; border-bottom: 1px solid #f0ece6; text-align: center;
}
.sc-tbl tbody tr:nth-child(even) { background: #FEFBF7; }
.sc-tbl tbody tr:hover { background: #FFF3E0; }
.sc-tbl tbody tr:last-child td { border-bottom: none; }

.col-num { text-align: left !important; white-space: nowrap; min-width: 80px; }
.col-city { text-align: left !important; white-space: nowrap; font-size: 12px; }
.col-addr { text-align: left !important; font-size: 12px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.col-prod { min-width: 50px; max-width: 90px; font-size: 12px; }
.col-time { white-space: nowrap; font-size: 11px; width: 80px; }
.col-del { width: 36px; text-align: center !important; }
.sc-row-del {
  width: 24px; height: 24px; border: none; background: none;
  cursor: pointer; font-size: 12px; border-radius: 4px;
  color: #ccc; display: inline-flex; align-items: center; justify-content: center;
  transition: all 0.1s;
}
.sc-row-del:hover { background: #FFEBEE; color: #E76F51; }
.muted { color: #8C7B6E; }
.fw { font-weight: 700; color: #502314; }

.sc-tbl tfoot td {
  padding: 8px 12px; border-top: 2px solid var(--border); text-align: center;
}
.foot-label { font-weight: 700; color: var(--muted); font-size: 12px; text-align: left !important; }
.foot-val { font-weight: 700; color: #502314; }

/* Cell values */
.sc-cell-val { cursor: default; }
.sc-cell-val.editable { cursor: pointer; border-bottom: 1px dashed transparent; }
.sc-cell-val.editable:hover { border-bottom-color: var(--orange); color: var(--orange); }
.sc-cell-empty { color: #ddd; }

/* Cell edit */
.sc-cell-input {
  width: 70px; padding: 3px 6px; border: 1.5px solid var(--orange);
  border-radius: 5px; font-size: 13px; font-family: inherit;
  text-align: center; background: #FFFBF5;
}
.sc-cell-input:focus { outline: none; }

/* Buttons */
.sc-btn {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 7px 14px; border-radius: 7px; font-size: 12px; font-weight: 600;
  font-family: inherit; border: 1.5px solid transparent; cursor: pointer;
  transition: all 0.12s; white-space: nowrap;
}
.sc-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.sc-btn.fill { background: #502314 !important; color: #fff !important; border-color: #502314 !important; }
.sc-btn.fill:hover:not(:disabled) { background: #3D1A0D !important; }
.sc-btn.outline { background: #fff !important; color: #6B5344 !important; border-color: #EDE7DF !important; }
.sc-btn.outline:hover:not(:disabled) { background: #F9F6F2 !important; }
.sc-btn.sm { padding: 4px 10px; font-size: 11px; }
.sc-btn.full { width: 100%; justify-content: center; }
.red-text { color: var(--red) !important; }
.sc-x { width: 28px; height: 28px; border: none; background: none; color: #bbb; cursor: pointer; font-size: 14px; border-radius: 4px; display: flex; align-items: center; justify-content: center; }
.sc-x:hover { background: #FFEBEE; color: var(--red); }
.sc-x.sm { width: 24px; height: 24px; font-size: 12px; }

/* Modal */
.sc-modal-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.sc-modal-head h3 { margin: 0; font-size: 16px; color: var(--brown); }
.sc-modal-foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; padding-top: 14px; border-top: 1px solid var(--border); }
.sc-confirm-text { font-size: 13px; color: #555; line-height: 1.5; margin: 0; }
.btn-danger { background: var(--red) !important; border-color: var(--red) !important; }
.btn-danger:hover:not(:disabled) { background: #B71C00 !important; }

/* Fields */
.sc-field { margin-bottom: 14px; }
.sc-field label { display: block; font-size: 11px; font-weight: 600; color: var(--muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.3px; }
.sc-input {
  padding: 8px 11px; border: 1.5px solid var(--border); border-radius: 7px;
  font-size: 13px; font-family: inherit; transition: border-color 0.12s;
}
.sc-input:focus { outline: none; border-color: var(--orange); }
.sc-input.full { width: 100%; box-sizing: border-box; }
.sc-input.selected { border-color: #A5D6A7; background: #FCFFF9; }
.sc-input:disabled { background: var(--bg2); color: var(--muted); }

/* Product card in create modal */
.sc-product-card {
  position: relative;
  background: var(--bg2); border: 1.5px solid var(--border); border-radius: 10px;
  padding: 14px; margin-bottom: 10px;
}
.sc-card-remove {
  position: absolute; top: 8px; right: 8px;
  width: 22px; height: 22px; border: none; background: none;
  color: #bbb; cursor: pointer; font-size: 12px; border-radius: 4px;
  display: flex; align-items: center; justify-content: center;
}
.sc-card-remove:hover { background: #FFEBEE; color: var(--red); }

/* Selected product display */
.sc-product-selected {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  background: #fff; border: 1.5px solid #A5D6A7; border-radius: 8px; padding: 10px 12px;
}
.sc-product-selected-name { font-size: 14px; font-weight: 700; color: var(--brown); }
.sc-product-selected-meta { display: flex; gap: 8px; font-size: 11px; color: var(--muted); margin-top: 2px; }
.sc-product-selected-meta span { white-space: nowrap; }

/* Search area */
.sc-product-search { position: relative; }
.sc-manual-hint { font-size: 11px; color: var(--muted); margin-top: 6px; }
.sc-link-btn { background: none; border: none; color: var(--orange); font-weight: 600; font-size: 11px; cursor: pointer; text-decoration: underline; font-family: inherit; padding: 0; }
.sc-manual-fields { margin-top: 8px; }

/* Unit row */
.sc-product-unit-row { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
.sc-product-unit-label { font-size: 12px; color: var(--muted); font-weight: 500; }
.sc-unit-locked { font-size: 12px; font-weight: 700; color: #502314; padding: 5px 12px; background: #F0EBE4; border-radius: 6px; }
.sc-note-input { margin-top: 8px; font-size: 12px; }
.sc-note-input::placeholder { font-style: italic; }

/* Dropdown */
.sc-drop {
  position: absolute; z-index: 60; left: 0; right: 0; margin-top: 3px;
  background: #fff; border: 1px solid var(--border); border-radius: 8px;
  box-shadow: 0 8px 28px rgba(44,24,16,0.12); max-height: 220px; overflow-y: auto;
}
.sc-drop-item {
  padding: 8px 12px; cursor: pointer;
  border-bottom: 1px solid var(--border); transition: background 0.08s;
}
.sc-drop-item:last-child { border-bottom: none; }
.sc-drop-item:hover { background: #FFF8F0; }
.sc-drop-manual { border-top: 2px solid var(--border); background: #FEFBF7; }
.sc-drop-manual .sc-drop-name { color: var(--orange); }
.sc-drop-name { font-size: 13px; font-weight: 600; color: var(--brown); }
.sc-drop-meta { font-size: 10px; color: var(--muted); }

/* Switcher */
.sc-switcher { display: inline-flex; border: 1.5px solid var(--border); border-radius: 6px; overflow: hidden; flex-shrink: 0; }
.sc-switcher button {
  padding: 6px 11px; font-size: 11px; font-weight: 600; font-family: inherit;
  border: none; cursor: pointer; background: transparent; color: #8C7B6E; transition: all 0.12s;
}
.sc-switcher button:not(:last-child) { border-right: 1.5px solid #EDE7DF; }
.sc-switcher .on { background: #502314 !important; color: #fff !important; }
.sc-switcher button:hover:not(.on) { background: #F9F6F2; }

/* Edit products */
.sc-product-edit-fields { }
.sc-product-edit-row { display: flex; gap: 8px; }
.sc-product-delete-warn {
  margin-top: 8px; padding: 6px 10px; background: #FFF3E0;
  border: 1px solid #FFE082; border-radius: 6px;
  font-size: 11px; color: #E65100;
  display: flex; align-items: center; gap: 8px;
}

@media (max-width: 640px) {
  .sc-product-card { padding: 10px; }
  .sc-detail-bar { flex-direction: column; align-items: flex-start; }
  .sc-detail-actions { flex-wrap: wrap; }
  .sc-summary { flex-wrap: wrap; gap: 12px; }
}
</style>
