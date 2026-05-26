<template>
  <div class="dist">
    <!-- ═══ Список сессий ═══ -->
    <template v-if="!activeSession">
      <div class="dist-top">
        <h1 class="page-title">Распределение новинок</h1>
        <button class="dist-btn primary" @click="showCreate = true">+ Новая сессия</button>
      </div>

      <div v-if="loading" class="dist-empty"><BurgerSpinner text="Загрузка..." /></div>
      <div v-else-if="!sessions.length" class="dist-empty">
        <div class="dist-empty-icon">📦</div>
        <div class="dist-empty-text">Нет сессий</div>
        <div class="dist-empty-sub">Создайте первую сессию распределения</div>
      </div>
      <div v-else class="dist-list">
        <div
          v-for="s in sessions" :key="s.id"
          class="dist-card"
          :class="{ closed: s.status === 'closed' }"
          @click="openSession(s)"
        >
          <div class="dist-card-header">
            <div class="dist-card-name">{{ s.name }}</div>
            <span class="dist-badge" :class="s.status">
              {{ s.status === 'active' ? 'Активна' : 'Закрыта' }}
            </span>
          </div>
          <div class="dist-card-meta">{{ s.created_by || '---' }} · {{ fmtDate(s.created_at) }}</div>
        </div>
      </div>
    </template>

    <!-- ═══ Детали сессии ═══ -->
    <template v-if="activeSession">
      <!-- Шапка -->
      <div class="dist-header">
        <button class="dist-back" @click="closeDetail" title="Назад">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        </button>
        <div class="dist-header-info">
          <h1 class="dist-session-name">{{ activeSession.name }}</h1>
          <span class="dist-badge" :class="activeSession.status">
            {{ activeSession.status === 'active' ? 'Активна' : 'Закрыта' }}
          </span>
        </div>
        <div class="dist-actions">
          <button
            v-if="activeSession.status === 'active'"
            class="dist-btn ghost"
            :disabled="!undoStack.length"
            :title="undoStack.length ? 'Отменить последнее действие (Ctrl+Z)' : 'Нет действий для отмены'"
            @click="undo"
          ><span class="btn-icon">↶</span><span class="btn-text"> Отменить</span></button>
          <button v-if="activeSession.status === 'active'" class="dist-btn ghost" title="Добавить товар" @click="showAddProduct = true"><span class="btn-icon">＋</span><span class="btn-text"> Товар</span></button>
          <button class="dist-btn ghost" title="Скачать Excel" @click="exportExcel"><span class="btn-icon">⬇</span><span class="btn-text"> Excel</span></button>
          <button v-if="activeSession.status === 'active'" class="dist-btn ghost danger" title="Закрыть сессию" @click="askCloseSession"><span class="btn-icon">✕</span><span class="btn-text"> Закрыть</span></button>
          <button v-if="activeSession.status === 'closed'" class="dist-btn ghost" title="Открыть сессию" @click="reopenSession"><span class="btn-icon">↻</span><span class="btn-text"> Открыть</span></button>
          <button class="dist-btn ghost danger" title="Удалить сессию" @click="askDeleteSession"><span class="btn-icon">🗑</span><span class="btn-text"> Удалить</span></button>
        </div>
      </div>

      <!-- Сводка + прогресс -->
      <div v-if="sessionData" class="dist-stats">
        <div class="dist-stat">
          <div class="dist-stat-val">{{ sessionProducts.length }}</div>
          <div class="dist-stat-label">Товаров</div>
        </div>
        <div class="dist-stat">
          <div class="dist-stat-val">{{ filteredRestaurants.length }}</div>
          <div class="dist-stat-label">Ресторанов</div>
        </div>
        <div class="dist-stat wide">
          <div class="dist-stat-row">
            <span class="dist-stat-label">Обработано</span>
            <span class="dist-stat-pct">
              <span class="dist-pct-ok">{{ totalShipped }} ✓</span>
              <span v-if="totalNotNeeded" class="dist-pct-no"> + {{ totalNotNeeded }} ✗</span>
              <span class="dist-pct-tot"> = {{ totalProcessed }} / {{ totalCells }} ({{ progressPct }}%)</span>
            </span>
          </div>
          <div class="dist-progress">
            <div class="dist-progress-fill" :style="{ width: progressPct + '%' }"></div>
          </div>
        </div>
      </div>

      <!-- Табы дней -->
      <div v-if="sessionData && availableDays.length" class="dist-day-bar">
        <button
          class="dist-day"
          :class="{ active: dayFilter === 0 }"
          @click="dayFilter = 0"
        >Все</button>
        <button
          v-for="d in availableDays" :key="d"
          class="dist-day"
          :class="{ active: dayFilter === d }"
          @click="dayFilter = d"
        >{{ dayShort[d] }} <span class="dist-day-n">{{ getDayCount(d) }}</span></button>
      </div>

      <!-- Фильтры -->
      <div v-if="sessionData" class="dist-filters">
        <select v-model="regionFilter" class="dist-sel">
          <option value="">Все регионы</option>
          <option v-for="r in regions" :key="r" :value="r">{{ r }}</option>
        </select>
        <select v-model="cityFilter" class="dist-sel">
          <option value="">Все города</option>
          <option v-for="c in cities" :key="c" :value="c">{{ c }}</option>
        </select>
        <label class="dist-chk" title="Прячет рестораны, у которых по всем товарам уже принято решение (✓ или ✗)">
          <input type="checkbox" v-model="hideProcessed"/>
          Скрыть обработанных
        </label>
      </div>

      <!-- Таблица -->
      <div v-if="sessionData && sessionProducts.length" class="dist-table-wrap">
        <table class="dist-tbl">
          <thead>
            <tr>
              <th class="th-rest" rowspan="2">Ресторан</th>
              <th v-for="p in sessionProducts" :key="p.id" class="th-prod">
                <div class="th-prod-name">{{ p.product_name || 'Товар #' + p.product_id }}</div>
                <div v-if="p.article" class="th-prod-art">{{ p.article }}</div>
                <div class="th-prod-qty">{{ p.default_qty }} {{ p.unit }}</div>
                <button v-if="activeSession.status === 'active'" class="th-del" title="Удалить товар" @click.stop="removeProduct(p)">×</button>
              </th>
              <th class="th-note" rowspan="2">Примечание</th>
            </tr>
            <tr>
              <th v-for="p in sessionProducts" :key="'s'+p.id" class="th-stat">
                <span class="th-stat-ok">{{ getProductShippedCount(p.id) }}</span>
                <span v-if="getProductCrossedCount(p.id)" class="th-stat-no">{{ getProductCrossedCount(p.id) }}</span>
                <span class="th-stat-total"> / {{ filteredRestaurants.length }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(rest, ri) in filteredRestaurants" :key="rest.number" :class="{ 'row-alt': ri % 2 === 1 }">
              <td class="td-rest">
                <span class="rest-num">{{ formatRestaurantNumber(rest.number, rest.legal_entity_group) }}</span>
                <span class="rest-addr">{{ rest.address }}</span>
                <span v-if="isVM(rest)" class="rest-vm">ВМ</span>
              </td>
              <td
                v-for="p in sessionProducts" :key="p.id"
                class="td-cell"
                :class="cellClass(rest.number, p.id)"
                :title="cellTitle"
                @click="onCellClick(rest.number, p, $event)"
                @contextmenu.prevent="markCrossed(rest.number, p)"
                @dblclick.stop="startEditQty(rest.number, p, $event)"
                @touchstart.passive="onTouchStart(rest.number, p)"
                @touchend="onTouchEnd($event)"
                @touchmove="cancelLongPress"
                @touchcancel="cancelLongPress"
              >
                <span v-if="getCellStatus(rest.number, p.id) === 1" class="cell-ok">✓</span>
                <span v-else-if="getCellStatus(rest.number, p.id) === 2" class="cell-no">✗</span>
                <span v-if="hasCustomQty(rest.number, p.id)" class="cell-qty">
                  {{ getEntryQty(rest.number, p.id) }}
                </span>
              </td>
              <td class="td-note" @dblclick.stop="startEditNote(rest.number)">
                <template v-if="editingNote?.restNum === String(rest.number)">
                  <input
                    v-model="editNoteValue"
                    class="note-input"
                    @keyup.enter="saveNote"
                    @keyup.escape="editingNote = null"
                    @blur="saveNote"
                  />
                </template>
                <template v-else>{{ notesMap[String(rest.number)] || '' }}</template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="sessionData && !sessionProducts.length" class="dist-empty">
        <div class="dist-empty-icon">📋</div>
        <div class="dist-empty-text">Нет товаров</div>
        <div class="dist-empty-sub">Добавьте товары в сессию</div>
      </div>
    </template>

    <!-- ═══ Модалка: создание сессии ═══ -->
    <Teleport to="body">
      <div v-if="showCreate" class="dist-overlay" @mousedown.self="showCreate = false">
        <div class="dist-modal">
          <div class="modal-title">Новая сессия</div>
          <label class="modal-label">Название</label>
          <input v-model="newName" class="modal-input" placeholder="Новинки март 2026"/>
          <label class="modal-label" style="margin-top:14px">Товары</label>
          <div class="prod-search">
            <input v-model="productSearchQuery" class="modal-input" placeholder="Поиск по названию или артикулу..." @input="debounceSearch"/>
            <div v-if="productSearchResults.length" class="prod-dropdown">
              <div
                v-for="pr in productSearchResults" :key="pr.id"
                class="prod-option"
                @click="addProductToNew(pr)"
              >
                <span class="prod-sku">{{ pr.sku }}</span>
                {{ pr.name }}
              </div>
            </div>
          </div>
          <div class="prod-manual">
            <div class="prod-manual-row">
              <input v-model="manualSku" class="modal-input" placeholder="Артикул" style="width:120px;"/>
              <input v-model="manualName" class="modal-input" placeholder="Название товара (вручную)" style="flex:1;"/>
              <button class="dist-btn ghost sm" :disabled="!manualName.trim()" @click="addManualToNew">+</button>
            </div>
          </div>
          <div v-if="newProducts.length" class="prod-list">
            <div v-for="(np, i) in newProducts" :key="np.product_id || np.custom_name" class="prod-row">
              <div class="prod-name"><span class="prod-sku">{{ np.sku }}</span> {{ np.name }}</div>
              <input v-model.number="np.default_qty" type="number" min="0.1" step="0.1" class="prod-qty" placeholder="Кол-во"/>
              <select v-model="np.unit" class="prod-unit">
                <option value="кор">кор</option>
                <option value="шт">шт</option>
                <option value="кг">кг</option>
                <option value="л">л</option>
              </select>
              <button class="prod-del" @click="newProducts.splice(i, 1)">×</button>
            </div>
          </div>
          <div class="modal-footer">
            <button class="dist-btn ghost" @click="showCreate = false">Отмена</button>
            <button class="dist-btn primary" :disabled="!newName.trim() || !newProducts.length || creating" @click="createSession">
              {{ creating ? 'Создание...' : 'Создать' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ═══ Модалка: добавить товар ═══ -->
    <Teleport to="body">
      <div v-if="showAddProduct" class="dist-overlay" @mousedown.self="showAddProduct = false">
        <div class="dist-modal">
          <div class="modal-title">Добавить товар</div>
          <div class="prod-search">
            <input v-model="addProductQuery" class="modal-input" placeholder="Поиск по названию или артикулу..." @input="debounceSearchAdd"/>
            <div v-if="addProductResults.length" class="prod-dropdown">
              <div
                v-for="pr in addProductResults" :key="pr.id"
                class="prod-option"
                @click="selectAddProduct(pr)"
              >
                <span class="prod-sku">{{ pr.sku }}</span>
                {{ pr.name }}
              </div>
            </div>
          </div>
          <div class="prod-manual">
            <div class="prod-manual-row">
              <input v-model="addManualSku" class="modal-input" placeholder="Артикул" style="width:120px;"/>
              <input v-model="addManualName" class="modal-input" placeholder="Или введите название вручную" style="flex:1;"/>
              <button class="dist-btn ghost sm" :disabled="!addManualName.trim()" @click="selectManualProduct">OK</button>
            </div>
          </div>
          <div v-if="addSelectedProduct" class="prod-selected">
            <div class="prod-name"><span v-if="addSelectedProduct.sku" class="prod-sku">{{ addSelectedProduct.sku }}</span> {{ addSelectedProduct.name }}</div>
            <div style="display:flex;gap:8px;margin-top:8px">
              <input v-model.number="addQty" type="number" min="0.1" step="0.1" class="prod-qty" placeholder="Кол-во"/>
              <select v-model="addUnit" class="prod-unit">
                <option value="кор">кор</option>
                <option value="шт">шт</option>
                <option value="кг">кг</option>
                <option value="л">л</option>
              </select>
            </div>
          </div>
          <div class="modal-footer">
            <button class="dist-btn ghost" @click="showAddProduct = false">Отмена</button>
            <button class="dist-btn primary" :disabled="!addSelectedProduct" @click="addProductToSession">Добавить</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ═══ Модалка: редактирование кол-ва ═══ -->
    <Teleport to="body">
      <div v-if="editingQty" class="dist-overlay" @mousedown.self="editingQty = null">
        <div class="dist-modal sm">
          <div class="modal-title">Ресторан {{ editingQty.restNum }}</div>
          <div class="modal-hint">По умолчанию: {{ editingQty.defaultQty }} {{ editingQty.unit }}</div>
          <input v-model="editQtyValue" class="modal-input" placeholder="Значение (число или текст)" @keyup.enter="saveQty"/>
          <div class="modal-footer">
            <button class="dist-btn ghost" @click="resetQty">Сбросить</button>
            <button class="dist-btn primary" @click="saveQty">Сохранить</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ═══ Модалка: подтверждение ═══ -->
    <Teleport to="body">
      <div v-if="confirmMsg" class="dist-overlay" @mousedown.self="confirmMsg = ''">
        <div class="dist-modal sm">
          <div class="modal-title">Подтверждение</div>
          <p class="modal-text">{{ confirmMsg }}</p>
          <div class="modal-footer">
            <button class="dist-btn ghost" @click="confirmMsg = ''">Отмена</button>
            <button class="dist-btn primary danger" @click="confirmAction?.(); confirmMsg = ''">Подтвердить</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, reactive, watch, onMounted, onUnmounted, nextTick } from 'vue';
import { db } from '@/lib/apiClient.js';
import { formatRestaurantNumber } from '@/lib/legalEntities.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useToastStore } from '@/stores/toastStore.js';

const orderStore = useOrderStore();
const toastStore = useToastStore();

// ═══ State ═══
const loading = ref(false);
const sessions = ref([]);
const activeSession = ref(null);
const sessionData = ref(null);

// Filters
const regionFilter = ref('');
const cityFilter = ref('');
const hideProcessed = ref(false);
const dayFilter = ref(0);

const dayShort = { 1: 'ПН', 2: 'ВТ', 3: 'СР', 4: 'ЧТ', 5: 'ПТ', 6: 'СБ' };

// Create session
const showCreate = ref(false);
const newName = ref('');
const newProducts = ref([]);
const creating = ref(false);
const productSearchQuery = ref('');
const productSearchResults = ref([]);
const manualName = ref('');
const manualSku = ref('');

// Add product to existing session
const showAddProduct = ref(false);
const addProductQuery = ref('');
const addProductResults = ref([]);
const addSelectedProduct = ref(null);
const addQty = ref(1);
const addUnit = ref('кор');
const addManualName = ref('');
const addManualSku = ref('');

// Edit qty
const editingQty = ref(null);
const editQtyValue = ref('');

// Confirm
const confirmMsg = ref('');
const confirmAction = ref(null);

// Entries map: key = "spId_restNum" => entry object
const entriesMap = reactive({});

// Notes
const notesMap = reactive({});
const editingNote = ref(null);
const editNoteValue = ref('');

// Undo: храним последние 20 действий. Каждая запись содержит достаточно,
// чтобы повторить обратную операцию без лишних запросов к серверу.
const UNDO_LIMIT = 20;
const undoStack = ref([]);
function pushUndo(action) {
  undoStack.value.push(action);
  if (undoStack.value.length > UNDO_LIMIT) undoStack.value.shift();
}

// ═══ Computed ═══
const sessionProducts = computed(() => sessionData.value?.products || []);
const allRestaurants = computed(() => sessionData.value?.restaurants || []);

const regions = computed(() => {
  const set = new Set();
  for (const r of allRestaurants.value) {
    const region = getRegion(r);
    if (region) set.add(region);
  }
  return [...set].sort();
});

const cities = computed(() => {
  const set = new Set();
  let rests = allRestaurants.value;
  if (regionFilter.value) rests = rests.filter(r => getRegion(r) === regionFilter.value);
  for (const r of rests) {
    if (r.city) set.add(r.city);
  }
  return [...set].sort();
});

const availableDays = computed(() => {
  const days = new Set();
  for (const r of allRestaurants.value) {
    if (r.delivery_days) {
      for (const d of r.delivery_days) days.add(d);
    }
  }
  return [...days].sort((a, b) => a - b);
});

function getDayCount(day) {
  return allRestaurants.value.filter(r => r.delivery_days?.includes(day)).length;
}

const filteredRestaurants = computed(() => {
  let rests = allRestaurants.value;
  if (dayFilter.value) {
    rests = rests.filter(r => r.delivery_days?.includes(dayFilter.value));
  }
  if (regionFilter.value) {
    rests = rests.filter(r => getRegion(r) === regionFilter.value);
  }
  if (cityFilter.value) rests = rests.filter(r => r.city === cityFilter.value);
  if (hideProcessed.value) {
    // Прячем ресторан, только если по ВСЕМ товарам уже принято решение
    // (✓ или ✗). Иначе ресторан останется висеть и сбивать оператора.
    rests = rests.filter(r => {
      return sessionProducts.value.some(p => getCellStatus(r.number, p.id) === 0);
    });
  }
  return rests;
});

const totalShipped = computed(() => {
  let count = 0;
  for (const key in entriesMap) {
    if (entriesMap[key]?.shipped === 1) count++;
  }
  return count;
});

const totalNotNeeded = computed(() => {
  let count = 0;
  for (const key in entriesMap) {
    if (entriesMap[key]?.shipped === 2) count++;
  }
  return count;
});

const totalProcessed = computed(() => totalShipped.value + totalNotNeeded.value);

const totalCells = computed(() => {
  return allRestaurants.value.length * sessionProducts.value.length;
});

// «Обработано» включает и отгрузки, и явные отказы — это полное закрытие
// клетки. Раньше показывали только отгрузки и оператор не понимал, что
// ещё нужно решить, а что уже закрыто.
const progressPct = computed(() => {
  if (!totalCells.value) return 0;
  return Math.round(totalProcessed.value / totalCells.value * 100);
});

// ═══ Helpers ═══
function getRegion(r) {
  return r.region || 'Другое';
}

function isVM(r) {
  return String(r.number) === '3';
}

function entryKey(restNum, spId) {
  return `${spId}_${restNum}`;
}

function getEntry(restNum, spId) {
  return entriesMap[entryKey(restNum, spId)] || null;
}

function getEntryQty(restNum, spId) {
  const e = getEntry(restNum, spId);
  return e?.qty ?? null;
}

function hasCustomQty(restNum, spId) {
  const e = getEntry(restNum, spId);
  return e && e.qty !== null && e.qty !== undefined;
}

function getCellStatus(restNum, spId) {
  const e = getEntry(restNum, spId);
  if (!e) return 0;
  return e.shipped;
}

function cellClass(restNum, spId) {
  const s = getCellStatus(restNum, spId);
  return {
    'td-shipped': s === 1,
    'td-crossed': s === 2,
    'td-custom': hasCustomQty(restNum, spId),
  };
}

function getProductShippedCount(spId) {
  let count = 0;
  for (const r of filteredRestaurants.value) {
    if (getCellStatus(r.number, spId) === 1) count++;
  }
  return count;
}

function getProductCrossedCount(spId) {
  let count = 0;
  for (const r of filteredRestaurants.value) {
    if (getCellStatus(r.number, spId) === 2) count++;
  }
  return count;
}

function fmtDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

// ═══ Load ═══
onMounted(() => {
  loadSessions();
  window.addEventListener('keydown', onKeydown);
});

// При смене юрлица: закрываем открытую сессию (если она не той группы) и перезагружаем список
watch(() => orderStore.settings.legalEntity, async () => {
  if (activeSession.value) {
    stopAutoRefresh();
    activeSession.value = null;
    sessionData.value = null;
    for (const key in entriesMap) delete entriesMap[key];
    for (const key in notesMap) delete notesMap[key];
  }
  await loadSessions();
});

async function loadSessions() {
  loading.value = true;
  try {
    const { data } = await db.rpc('dist_get_sessions', { legal_entity: orderStore.settings.legalEntity });
    sessions.value = data || [];
  } catch (e) { console.warn('[dist]', e); }
  finally { loading.value = false; }
}

let refreshInterval = null;

async function openSession(s) {
  activeSession.value = s;
  await loadSessionData(s.id);
  startAutoRefresh();
}

function startAutoRefresh() {
  stopAutoRefresh();
  refreshInterval = setInterval(() => {
    // Не дёргаем сервер на скрытой вкладке.
    if (typeof document !== 'undefined' && document.visibilityState !== 'visible') return;
    if (activeSession.value && !editingQty.value && !editingNote.value) {
      loadSessionData(activeSession.value.id);
    }
  }, 10000);
}

function stopAutoRefresh() {
  if (refreshInterval) { clearInterval(refreshInterval); refreshInterval = null; }
}

async function loadSessionData(id) {
  try {
    const { data } = await db.rpc('dist_get_session_data', { session_id: id });
    sessionData.value = data;
    for (const key in entriesMap) delete entriesMap[key];
    for (const e of (data?.entries || [])) {
      const k = entryKey(e.restaurant_number, e.session_product_id);
      entriesMap[k] = {
        id: e.id,
        shipped: parseInt(e.shipped) || 0,
        qty: e.qty ?? null,
        version: parseInt(e.version) || 1,
        updated_by: e.updated_by || null,
      };
    }
    for (const key in notesMap) delete notesMap[key];
    if (data?.notes) {
      for (const [rn, note] of Object.entries(data.notes)) {
        notesMap[rn] = note;
      }
    }
  } catch (e) { console.warn('[dist] load session data', e); }
}

function closeDetail() {
  stopAutoRefresh();
  activeSession.value = null;
  sessionData.value = null;
  for (const key in entriesMap) delete entriesMap[key];
  for (const key in notesMap) delete notesMap[key];
  loadSessions();
}

onUnmounted(() => {
  stopAutoRefresh();
  window.removeEventListener('keydown', onKeydown);
});

// ═══ Mouse actions ═══
// ЛКМ ставит «отгружено», повторный ЛКМ по уже отгруженной — сброс в пусто.
// ПКМ ставит «не нужно», повторный — сброс. Это упрощает работу: оператор
// держит две кнопки мыши и не циклит через все три состояния.
// На touch-устройствах ПКМ заменяется long-press (см. onTouchStart).
const cellTitle = 'ЛКМ — отгружено, ПКМ — не нужно, двойной клик — кол-во. На телефоне: тап — ✓, долгое нажатие — ✗, двойной тап — кол-во';

function markShipped(restNum, product) {
  const cur = getCellStatus(restNum, product.id);
  const next = cur === 1 ? 0 : 1; // toggle с любого состояния
  setStatus(restNum, product, next);
}

function markCrossed(restNum, product) {
  const cur = getCellStatus(restNum, product.id);
  const next = cur === 2 ? 0 : 2;
  setStatus(restNum, product, next);
}

// Long-press на touch — эквивалент ПКМ. Удерживаем 500 мс — ставим ✗,
// и подавляем последующий click чтобы не сработал markShipped по отпусканию.
const LONG_PRESS_MS = 500;
let longPressTimer = null;
let longPressFired = false;
function onTouchStart(restNum, product) {
  longPressFired = false;
  cancelLongPress();
  longPressTimer = setTimeout(() => {
    longPressFired = true;
    markCrossed(restNum, product);
    if (navigator.vibrate) navigator.vibrate(15); // тактильная обратная связь
  }, LONG_PRESS_MS);
}
function onTouchEnd(event) {
  cancelLongPress();
  if (longPressFired) {
    // long-press уже отработал — не даём системе сгенерировать click
    event.preventDefault();
    longPressFired = false;
  }
}
function cancelLongPress() {
  if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
}
// Обёртка click: если long-press только что сработал — игнорируем
// синтетический click, который Safari всё же может прислать.
function onCellClick(restNum, product, event) {
  if (longPressFired) { event.preventDefault(); longPressFired = false; return; }
  markShipped(restNum, product);
}

async function setStatus(restNum, product, next) {
  if (activeSession.value?.status === 'closed') return;
  const k = entryKey(restNum, product.id);
  if (!entriesMap[k]) entriesMap[k] = { shipped: 0, qty: null, version: 0 };
  const prev = entriesMap[k].shipped;
  const prevVersion = entriesMap[k].version || 0;
  if (prev === next) return; // ничего не изменилось — RPC не дёргаем
  entriesMap[k].shipped = next;
  const { data, error } = await db.rpc('dist_toggle_shipped', {
    session_product_id: product.id,
    restaurant_number: String(restNum),
    shipped: next,
    version: prevVersion,
  });
  if (error === 'conflict') {
    toastStore.show('Кто-то изменил эту клетку, обновляю', 'warning');
    await loadSessionData(activeSession.value.id);
    return;
  }
  if (error) {
    entriesMap[k].shipped = prev;
    toastStore.show('Ошибка сохранения', 'error');
    return;
  }
  if (data?.version) entriesMap[k].version = data.version;
  pushUndo({ type: 'shipped', restNum: String(restNum), spId: product.id, prev, next });
}

// ═══ Edit qty ═══
function startEditQty(restNum, product, event) {
  if (activeSession.value?.status === 'closed') return;
  event.preventDefault();
  editingQty.value = {
    restNum: String(restNum),
    spId: product.id,
    defaultQty: product.default_qty,
    unit: product.unit,
  };
  const existing = getEntry(restNum, product.id);
  editQtyValue.value = existing?.qty ?? `${product.default_qty}`;
}

async function saveQty() {
  if (!editingQty.value) return;
  const { restNum, spId } = editingQty.value;
  const k = entryKey(restNum, spId);
  if (!entriesMap[k]) entriesMap[k] = { shipped: 0, qty: null, version: 0 };
  const val = editQtyValue.value.toString().trim() || null;
  const prevQty = entriesMap[k].qty;
  const prevVersion = entriesMap[k].version || 0;
  entriesMap[k].qty = val;
  editingQty.value = null;
  const { data, error } = await db.rpc('dist_update_qty', {
    session_product_id: spId,
    restaurant_number: restNum,
    qty: val,
    version: prevVersion,
  });
  if (error === 'conflict') {
    toastStore.show('Кто-то изменил эту клетку, обновляю', 'warning');
    await loadSessionData(activeSession.value.id);
    return;
  }
  if (error) {
    entriesMap[k].qty = prevQty;
    toastStore.show('Ошибка', 'error');
    return;
  }
  if (data?.version) entriesMap[k].version = data.version;
  pushUndo({ type: 'qty', restNum, spId, prev: prevQty, next: val });
}

async function resetQty() {
  if (!editingQty.value) return;
  const { restNum, spId } = editingQty.value;
  const k = entryKey(restNum, spId);
  const prevQty = entriesMap[k]?.qty ?? null;
  const prevVersion = entriesMap[k]?.version || 0;
  if (entriesMap[k]) entriesMap[k].qty = null;
  editingQty.value = null;
  const { data, error } = await db.rpc('dist_update_qty', {
    session_product_id: spId,
    restaurant_number: restNum,
    qty: null,
    version: prevVersion,
  });
  if (error === 'conflict') {
    toastStore.show('Кто-то изменил эту клетку, обновляю', 'warning');
    await loadSessionData(activeSession.value.id);
    return;
  }
  if (error) {
    if (entriesMap[k]) entriesMap[k].qty = prevQty;
    toastStore.show('Ошибка', 'error');
    return;
  }
  if (data?.version) entriesMap[k].version = data.version;
  pushUndo({ type: 'qty', restNum, spId, prev: prevQty, next: null });
}

// ═══ Undo последней мутации ═══
// Возвращаем клетку к предыдущему значению, отправив тот же RPC с актуальной
// версией (берём её из entriesMap, потому что версия уже могла измениться).
async function undo() {
  if (!activeSession.value || activeSession.value.status === 'closed') return;
  const action = undoStack.value.pop();
  if (!action) return;
  const k = entryKey(action.restNum, action.spId);
  const ver = entriesMap[k]?.version || 0;
  if (action.type === 'shipped') {
    if (entriesMap[k]) entriesMap[k].shipped = action.prev;
    const { error } = await db.rpc('dist_toggle_shipped', {
      session_product_id: action.spId,
      restaurant_number: action.restNum,
      shipped: action.prev,
      version: ver,
    });
    if (error) toastStore.show('Не удалось отменить', 'error');
    else await loadSessionData(activeSession.value.id);
  } else if (action.type === 'qty') {
    if (entriesMap[k]) entriesMap[k].qty = action.prev;
    const { error } = await db.rpc('dist_update_qty', {
      session_product_id: action.spId,
      restaurant_number: action.restNum,
      qty: action.prev,
      version: ver,
    });
    if (error) toastStore.show('Не удалось отменить', 'error');
    else await loadSessionData(activeSession.value.id);
  }
}

function onKeydown(e) {
  // Ctrl+Z / Cmd+Z = undo. Не перехватываем когда юзер печатает в input/textarea.
  if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
    const tag = (e.target?.tagName || '').toUpperCase();
    if (tag === 'INPUT' || tag === 'TEXTAREA') return;
    e.preventDefault();
    undo();
  }
}

// ═══ Notes ═══
function startEditNote(restNum) {
  editingNote.value = { restNum: String(restNum) };
  editNoteValue.value = notesMap[String(restNum)] || '';
  nextTick(() => {
    const inp = document.querySelector('.note-input');
    if (inp) inp.focus();
  });
}

async function saveNote() {
  if (!editingNote.value || !activeSession.value) return;
  const rn = editingNote.value.restNum;
  notesMap[rn] = editNoteValue.value;
  editingNote.value = null;
  try {
    await db.rpc('dist_save_note', {
      session_id: activeSession.value.id,
      restaurant_number: rn,
      note: editNoteValue.value,
    });
  } catch { toastStore.show('Ошибка', 'error'); }
}

// ═══ Product search ═══
let searchTimer = null;
function debounceSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => searchProducts(productSearchQuery.value, productSearchResults), 300);
}
function debounceSearchAdd() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => searchProducts(addProductQuery.value, addProductResults), 300);
}

async function searchProducts(query, target) {
  const { data } = await db.searchProducts(query, {
    legalEntity: orderStore.settings.legalEntity,
    limit: 15,
  });
  target.value = data || [];
}

function addProductToNew(pr) {
  if (newProducts.value.some(p => p.product_id === pr.id)) return;
  newProducts.value.push({
    product_id: pr.id,
    name: pr.name,
    sku: pr.sku,
    default_qty: 1,
    unit: 'кор',
  });
  productSearchQuery.value = '';
  productSearchResults.value = [];
}

function selectAddProduct(pr) {
  addSelectedProduct.value = pr;
  addProductQuery.value = '';
  addProductResults.value = [];
  addManualName.value = '';
  addManualSku.value = '';
}

function addManualToNew() {
  const name = manualName.value.trim();
  if (!name) return;
  const sku = manualSku.value.trim();
  newProducts.value.push({
    product_id: null,
    custom_name: name,
    custom_sku: sku || null,
    name,
    sku: sku || '',
    default_qty: 1,
    unit: 'кор',
  });
  manualName.value = '';
  manualSku.value = '';
}

function selectManualProduct() {
  const name = addManualName.value.trim();
  if (!name) return;
  const sku = addManualSku.value.trim();
  addSelectedProduct.value = { id: null, name, sku: sku || '', custom_name: name, custom_sku: sku || null };
  addManualName.value = '';
  addManualSku.value = '';
}

// ═══ Create session ═══
async function createSession() {
  creating.value = true;
  try {
    const { data } = await db.rpc('dist_create_session', {
      name: newName.value.trim(),
      legal_entity: orderStore.settings.legalEntity,
      products: newProducts.value.map(p => ({
        product_id: p.product_id || null,
        custom_name: p.custom_name || null,
        custom_sku: p.custom_sku || null,
        default_qty: p.default_qty,
        unit: p.unit,
      })),
    });
    showCreate.value = false;
    newName.value = '';
    newProducts.value = [];
    toastStore.show('Сессия создана');
    await loadSessions();
    if (data?.session_id) {
      const s = sessions.value.find(s => s.id == data.session_id);
      if (s) openSession(s);
    }
  } catch { toastStore.show('Ошибка создания', 'error'); }
  finally { creating.value = false; }
}

// ═══ Add product to session ═══
async function addProductToSession() {
  if (!addSelectedProduct.value || !activeSession.value) return;
  try {
    const pr = addSelectedProduct.value;
    await db.rpc('dist_add_products', {
      session_id: activeSession.value.id,
      products: [{
        product_id: pr.id || null,
        custom_name: pr.custom_name || null,
        custom_sku: pr.custom_sku || null,
        default_qty: addQty.value,
        unit: addUnit.value,
      }],
    });
    showAddProduct.value = false;
    addSelectedProduct.value = null;
    addProductQuery.value = '';
    addQty.value = 1;
    addUnit.value = 'кор';
    toastStore.show('Товар добавлен');
    await loadSessionData(activeSession.value.id);
  } catch { toastStore.show('Ошибка', 'error'); }
}

// ═══ Remove product ═══
function removeProduct(p) {
  confirmMsg.value = `Удалить товар «${p.product_name}» из сессии? Все данные отгрузки по нему будут удалены.`;
  confirmAction.value = async () => {
    try {
      await db.rpc('dist_remove_product', { session_product_id: p.id });
      toastStore.show('Товар удалён');
      await loadSessionData(activeSession.value.id);
    } catch { toastStore.show('Ошибка', 'error'); }
  };
}

// ═══ Close / reopen session ═══
function askCloseSession() {
  confirmMsg.value = 'Закрыть сессию? Изменения будут невозможны.';
  confirmAction.value = async () => {
    try {
      await db.rpc('dist_close_session', { session_id: activeSession.value.id });
      activeSession.value.status = 'closed';
      toastStore.show('Сессия закрыта');
    } catch { toastStore.show('Ошибка', 'error'); }
  };
}

function askDeleteSession() {
  confirmMsg.value = `Удалить сессию «${activeSession.value.name}»? Все данные будут потеряны.`;
  confirmAction.value = async () => {
    try {
      await db.rpc('dist_delete_session', { session_id: activeSession.value.id });
      toastStore.show('Сессия удалена');
      closeDetail();
    } catch { toastStore.show('Ошибка удаления', 'error'); }
  };
}

async function reopenSession() {
  try {
    await db.rpc('dist_reopen_session', { session_id: activeSession.value.id });
    activeSession.value.status = 'active';
    toastStore.show('Сессия открыта');
  } catch { toastStore.show('Ошибка', 'error'); }
}

// ═══ Excel export ═══
async function exportExcel() {
  const XLSX = (await import('xlsx-js-style')).default;
  const products = sessionProducts.value;
  const rests = filteredRestaurants.value;

  const header1 = ['Ресторан', ...products.map(p => p.product_name || 'Товар')];
  const header2 = ['', ...products.map(p => `${p.default_qty} ${p.unit}`)];

  const dataRows = rests.map(r => {
    const row = [`${r.number} ${r.address || r.city}${isVM(r) ? ' (ВМ)' : ''}`];
    for (const p of products) {
      const s = getCellStatus(r.number, p.id);
      if (s === 1) {
        row.push(hasCustomQty(r.number, p.id) ? getEntryQty(r.number, p.id) : '✓');
      } else if (s === 2) {
        row.push('✗');
      } else {
        row.push('');
      }
    }
    return row;
  });

  const totals = ['ИТОГО'];
  for (const p of products) {
    totals.push(getProductShippedCount(p.id));
  }

  const ws = XLSX.utils.aoa_to_sheet([header1, header2, ...dataRows, totals]);

  const border = {
    top: { style: 'thin', color: { rgb: 'CCCCCC' } },
    bottom: { style: 'thin', color: { rgb: 'CCCCCC' } },
    left: { style: 'thin', color: { rgb: 'CCCCCC' } },
    right: { style: 'thin', color: { rgb: 'CCCCCC' } },
  };
  const headerStyle = {
    font: { bold: true, sz: 11, name: 'Calibri', color: { rgb: 'FFFFFF' } },
    fill: { fgColor: { rgb: '502314' } },
    alignment: { horizontal: 'center', vertical: 'center', wrapText: true },
    border,
  };
  const subHeaderStyle = {
    font: { sz: 10, name: 'Calibri', color: { rgb: '6B5344' } },
    fill: { fgColor: { rgb: 'F5EBDC' } },
    alignment: { horizontal: 'center', vertical: 'center' },
    border,
  };
  const cellStyle = { font: { sz: 11, name: 'Calibri' }, alignment: { horizontal: 'center', vertical: 'center' }, border };
  const shippedStyle = { ...cellStyle, font: { ...cellStyle.font, color: { rgb: '2E7D32' }, bold: true }, fill: { fgColor: { rgb: 'E8F5E9' } } };
  const totalStyle = { font: { bold: true, sz: 11, name: 'Calibri', color: { rgb: '502314' } }, fill: { fgColor: { rgb: 'F5EBDC' } }, alignment: { horizontal: 'center', vertical: 'center' }, border };

  const range = XLSX.utils.decode_range(ws['!ref']);
  for (let R = range.s.r; R <= range.e.r; R++) {
    for (let C = range.s.c; C <= range.e.c; C++) {
      const addr = XLSX.utils.encode_cell({ r: R, c: C });
      const cell = ws[addr];
      if (!cell) continue;
      if (R === 0) cell.s = headerStyle;
      else if (R === 1) cell.s = subHeaderStyle;
      else if (R === range.e.r) cell.s = totalStyle;
      else if (C === 0) cell.s = { ...cellStyle, alignment: { horizontal: 'left' } };
      else if (cell.v === '✗') cell.s = { ...cellStyle, font: { ...cellStyle.font, color: { rgb: 'C62828' }, bold: true }, fill: { fgColor: { rgb: 'FFEBEE' } } };
      else if (cell.v === '✓' || (typeof cell.v === 'number' && cell.v > 0)) cell.s = shippedStyle;
      else cell.s = cellStyle;
    }
  }

  ws['!cols'] = [{ wch: 28 }, ...products.map(() => ({ wch: 16 }))];

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Распределение');
  XLSX.writeFile(wb, `Распределение_${activeSession.value.name.replace(/[^a-zA-Zа-яА-Я0-9]/g, '_')}.xlsx`);
}
</script>

<style scoped>
/* ═══ Layout ═══ */
.dist { padding: 20px 24px; max-width: 100%; }
.dist-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }

/* ═══ Buttons ═══ */
.dist-btn { padding: 7px 14px; border-radius: var(--radius-sm); font-size: 13px; font-weight: 600; cursor: pointer; border: none; transition: all var(--transition); font-family: inherit; }
.dist-btn.primary { background: var(--bk-brown); color: #fff; }
.dist-btn.primary:hover { background: var(--bk-red-dark); }
.dist-btn.primary.danger { background: #C62828; }
.dist-btn.primary.danger:hover { background: #B71C1C; }
.dist-btn.ghost { background: transparent; color: var(--text-secondary); border: 1px solid var(--border); }
.dist-btn.ghost:hover { border-color: var(--bk-brown); color: var(--bk-brown); background: var(--bk-cream); }
.dist-btn.ghost.danger { color: #C62828; }
.dist-btn.ghost.danger:hover { border-color: #C62828; background: #FFF5F5; }
.dist-btn:disabled { opacity: 0.45; cursor: not-allowed; }

/* ═══ Empty state ═══ */
.dist-empty { padding: 60px 20px; text-align: center; }
.dist-empty-icon { font-size: 40px; margin-bottom: 12px; opacity: 0.5; }
.dist-empty-text { font-size: 16px; font-weight: 600; color: var(--text); margin-bottom: 4px; }
.dist-empty-sub { font-size: 13px; color: var(--text-muted); }

/* ═══ Session list ═══ */
.dist-list { display: flex; flex-direction: column; gap: 8px; }
.dist-card { padding: 14px 18px; background: var(--card); border-radius: var(--radius); border: 1px solid var(--border); cursor: pointer; transition: all var(--transition); }
.dist-card:hover { border-color: var(--bk-brown-light); box-shadow: var(--shadow-md); }
.dist-card.closed { opacity: 0.55; }
.dist-card-header { display: flex; align-items: center; gap: 10px; }
.dist-card-name { font-weight: 700; font-size: 15px; color: var(--text); }
.dist-card-meta { font-size: 12px; color: var(--text-muted); margin-top: 3px; }

/* ═══ Badge ═══ */
.dist-badge { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 6px; letter-spacing: 0.02em; }
.dist-badge.active { background: var(--green-bg); color: var(--green); }
.dist-badge.closed { background: #f0ece8; color: var(--text-muted); }

/* ═══ Session header ═══ */
.dist-header { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
.dist-back { background: none; border: none; cursor: pointer; color: var(--text-muted); padding: 6px; border-radius: var(--radius-sm); transition: all var(--transition); display: flex; }
.dist-back:hover { background: var(--bk-cream); color: var(--bk-brown); }
.dist-header-info { display: flex; align-items: center; gap: 8px; flex: 1; min-width: 0; }
.dist-session-name { font-family: 'Flame', sans-serif; font-size: 20px; font-weight: 700; color: var(--text); margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dist-actions { display: flex; gap: 6px; flex-wrap: wrap; }

/* ═══ Stats ═══ */
.dist-stats { display: flex; gap: 12px; margin-bottom: 14px; align-items: stretch; flex-wrap: wrap; }
.dist-stat { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px 18px; text-align: center; min-width: 90px; }
.dist-stat.wide { flex: 1; min-width: 200px; text-align: left; display: flex; flex-direction: column; justify-content: center; }
.dist-stat-val { font-family: 'Flame', sans-serif; font-size: 24px; font-weight: 700; color: var(--bk-brown); line-height: 1.1; }
.dist-stat-label { font-size: 11px; color: var(--text-muted); margin-top: 2px; text-transform: uppercase; letter-spacing: 0.04em; }
.dist-stat-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
.dist-stat-pct { font-size: 13px; font-weight: 700; color: var(--bk-brown); }
.dist-pct-ok { color: var(--green); }
.dist-pct-no { color: #C62828; }
.dist-pct-tot { color: var(--text-secondary); font-weight: 600; }
.dist-progress { height: 6px; background: var(--border-light); border-radius: 3px; overflow: hidden; }
.dist-progress-fill { height: 100%; background: linear-gradient(90deg, var(--bk-orange), var(--green)); border-radius: 3px; transition: width 0.4s ease; }

/* ═══ Day tabs ═══ */
.dist-day-bar { display: flex; gap: 4px; margin-bottom: 10px; flex-wrap: wrap; }
.dist-day { padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; cursor: pointer; border: 1px solid var(--border); background: var(--card); color: var(--text-secondary); transition: all var(--transition); font-family: inherit; }
.dist-day:hover { border-color: var(--bk-brown-light); color: var(--bk-brown); }
.dist-day.active { background: var(--bk-brown); color: #fff; border-color: var(--bk-brown); }
.dist-day-n { font-weight: 400; opacity: 0.7; margin-left: 2px; }

/* ═══ Filters ═══ */
.dist-filters { display: flex; gap: 8px; align-items: center; margin-bottom: 12px; flex-wrap: wrap; }
.dist-sel { padding: 5px 10px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 12px; background: var(--card); color: var(--text); font-family: inherit; }
.dist-chk { font-size: 12px; color: var(--text-secondary); display: flex; align-items: center; gap: 4px; cursor: pointer; }

/* ═══ Table ═══ */
.dist-table-wrap { overflow: auto; border-radius: var(--radius); border: 1px solid var(--border); background: var(--card); max-height: calc(100vh - 290px); }
.dist-tbl { border-collapse: separate; border-spacing: 0; font-size: 12px; width: 100%; }
.dist-tbl thead { position: sticky; top: 0; z-index: 2; }

/* Header cells */
.dist-tbl th { background: var(--bk-brown); color: #fff; padding: 5px 6px; font-weight: 600; font-size: 11px; border-bottom: 1px solid rgba(255,255,255,0.15); border-right: 1px solid rgba(255,255,255,0.1); white-space: nowrap; }
.th-rest { min-width: 170px; text-align: center; position: sticky; left: 0; z-index: 3; background: var(--bk-brown); }
.th-prod { position: relative; min-width: 72px; max-width: 150px; white-space: normal !important; vertical-align: top; padding-top: 6px !important; }
.th-prod-name { font-size: 10px; line-height: 1.25; font-weight: 600; }
.th-prod-art { font-size: 9px; opacity: 0.55; margin-top: 1px; }
.th-prod-qty { font-size: 9px; opacity: 0.65; margin-top: 2px; }
.th-del { position: absolute; top: 2px; right: 2px; background: none; border: none; color: rgba(255,255,255,0.4); cursor: pointer; font-size: 14px; line-height: 1; padding: 0 3px; }
.th-del:hover { color: #fff; }
.th-stat { background: rgba(80,35,20,0.85) !important; font-size: 10px; font-weight: 500; }
.th-stat-ok { color: #81C784; }
.th-stat-no { color: #EF9A9A; margin-left: 3px; }
.th-stat-total { opacity: 0.6; }
.th-note { min-width: 120px; text-align: left; padding-left: 8px !important; font-size: 10px; }

/* Body cells */
.dist-tbl td { padding: 3px 5px; border-bottom: 1px solid var(--border-light); border-right: 1px solid var(--border-light); text-align: center; }
.row-alt td { background: #fdfbf8; }
.td-rest { text-align: left; padding-left: 8px !important; white-space: nowrap; font-size: 11px; position: sticky; left: 0; z-index: 1; background: var(--card); border-right: 1px solid var(--border) !important; }
.row-alt .td-rest { background: #fdfbf8; }
.rest-num { display: inline-block; min-width: 24px; font-weight: 700; color: var(--bk-brown); font-size: 12px; }
.rest-addr { margin-left: 2px; font-size: 11px; color: var(--text-secondary); }
.rest-vm { background: var(--bk-orange); color: #fff; font-size: 8px; font-weight: 700; padding: 1px 4px; border-radius: 3px; margin-left: 4px; vertical-align: middle; }

/* Interactive cells */
.td-cell { cursor: pointer; transition: background 0.1s; min-width: 46px; position: relative; }
.td-cell:hover { background: var(--bk-cream) !important; }
.td-shipped { background: #E8F5E9 !important; }
.td-shipped:hover { background: #C8E6C9 !important; }
.td-crossed { background: #FFEBEE !important; }
.td-crossed:hover { background: #FFCDD2 !important; }
.td-custom { outline: 2px solid var(--bk-orange); outline-offset: -2px; }
.cell-ok { color: var(--green); font-weight: 700; font-size: 14px; }
.cell-no { color: #C62828; font-weight: 700; font-size: 14px; }
.cell-qty { display: block; font-size: 9px; color: #E65100; font-weight: 600; margin-top: -2px; }

/* Notes */
.td-note { text-align: left; padding-left: 8px !important; font-size: 11px; color: var(--text-secondary); cursor: pointer; min-width: 120px; }
.td-note:hover { background: var(--bk-cream) !important; }
.note-input { width: 100%; padding: 2px 4px; border: 1px solid var(--bk-brown); border-radius: 4px; font-size: 11px; box-sizing: border-box; outline: none; font-family: inherit; }

/* ═══ Modals ═══ */
.dist-modal { background: var(--card); border-radius: var(--radius); padding: 24px; width: 480px; max-width: 92vw; max-height: 80vh; overflow-y: auto; box-shadow: var(--shadow-lg); }
.dist-modal.sm { width: 360px; }
.modal-title { font-family: 'Flame', sans-serif; font-size: 18px; font-weight: 700; color: var(--text); margin-bottom: 16px; }
.modal-label { font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px; display: block; text-transform: uppercase; letter-spacing: 0.03em; }
.modal-input { width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 14px; box-sizing: border-box; font-family: inherit; color: var(--text); background: var(--card); }
.modal-input:focus { border-color: var(--bk-brown); outline: none; }
.modal-hint { font-size: 13px; color: var(--text-muted); margin-bottom: 10px; }
.modal-text { font-size: 14px; color: var(--text-secondary); line-height: 1.5; }
.modal-footer { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }

/* Product search */
.prod-search { position: relative; }
.prod-dropdown { position: absolute; top: 100%; left: 0; right: 0; background: var(--card); border: 1px solid var(--border); border-radius: 0 0 var(--radius-sm) var(--radius-sm); max-height: 200px; overflow-y: auto; z-index: 10; box-shadow: var(--shadow-md); }
.prod-option { padding: 8px 12px; cursor: pointer; font-size: 13px; border-bottom: 1px solid var(--border-light); }
.prod-option:hover { background: var(--bk-cream); }
.prod-sku { color: var(--text-muted); font-size: 11px; margin-right: 6px; }
.prod-manual { margin-top: 8px; }
.prod-manual-row { display: flex; gap: 6px; align-items: center; }

/* Product rows */
.prod-list { margin-top: 10px; display: flex; flex-direction: column; gap: 6px; }
.prod-row { display: grid; grid-template-columns: 1fr 70px 60px 24px; gap: 6px; align-items: center; padding: 6px 8px; background: #fdfbf8; border-radius: var(--radius-sm); }
.prod-name { font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.prod-qty { padding: 4px 6px; border: 1px solid var(--border); border-radius: 6px; font-size: 13px; text-align: center; font-family: inherit; }
.prod-unit { padding: 4px 2px; border: 1px solid var(--border); border-radius: 6px; font-size: 13px; font-family: inherit; }
.prod-del { background: none; border: none; cursor: pointer; color: var(--text-muted); font-size: 18px; padding: 0; line-height: 1; }
.prod-del:hover { color: #C62828; }
.prod-selected { margin-top: 12px; padding: 10px; background: #fdfbf8; border-radius: var(--radius-sm); }

/* ═══ Mobile (<= 600px) ═══
   Цель: пальцем удобно ставить ✓/✗ в матрице и не тратить
   половину экрана на шапку. */
@media (max-width: 600px) {
  .dist { padding: 12px 10px; }

  /* Шапка: убираем wrap, ужимаем заголовок, кнопки в одну строку */
  .dist-header { gap: 6px; margin-bottom: 10px; }
  .dist-back { padding: 4px; }
  .dist-session-name { font-size: 15px; }
  .dist-actions { gap: 4px; width: 100%; justify-content: flex-end; }
  /* На мобиле прячем подписи у кнопок и оставляем иконки, чтобы 5 кнопок
     помещались в строку. На десктопе работают полные подписи. */
  .dist-actions .dist-btn { padding: 6px 8px; font-size: 13px; min-width: 36px; }
  .dist-actions .btn-text { display: none; }
  .dist-actions .btn-icon { font-size: 15px; }

  /* Сводка: 2 карточки по 50%, прогресс на отдельной строке */
  .dist-stats { gap: 6px; margin-bottom: 10px; }
  .dist-stat { flex: 1; min-width: 0; padding: 8px 10px; }
  .dist-stat.wide { flex-basis: 100%; padding: 10px 12px; }
  .dist-stat-val { font-size: 20px; }
  .dist-stat-label { font-size: 10px; }
  .dist-stat-pct { font-size: 12px; }

  /* Табы дней: однострочный скролл вместо переноса */
  .dist-day-bar { flex-wrap: nowrap; overflow-x: auto; padding-bottom: 4px; }
  .dist-day { flex-shrink: 0; }

  /* Фильтры в столбик */
  .dist-filters { flex-direction: column; align-items: stretch; gap: 6px; }
  .dist-sel { width: 100%; padding: 8px 10px; font-size: 13px; }
  .dist-chk { padding: 4px 0; }

  /* Таблица: tap-target минимум 44 px, увеличенный шрифт, видимый gap */
  .dist-table-wrap { max-height: calc(100vh - 380px); border-radius: 8px; }
  .dist-tbl { font-size: 13px; }
  .dist-tbl th { padding: 6px 4px; font-size: 11px; }
  .th-rest { min-width: 140px; }
  .th-prod { min-width: 64px; padding: 6px 4px !important; }
  .th-prod-name { font-size: 11px; }
  .th-note { min-width: 90px; }
  .dist-tbl td { padding: 8px 4px; }
  .td-cell { min-width: 56px; min-height: 44px; }
  .cell-ok, .cell-no { font-size: 18px; }
  .td-rest { padding-left: 6px !important; font-size: 11px; }
  .rest-num { font-size: 13px; }
  .rest-addr { display: block; font-size: 10px; margin-left: 0; color: var(--text-muted); }

  /* Поле примечания: больше, чтобы поместилась клавиатура */
  .note-input { font-size: 13px; padding: 6px 8px; }
  .td-note { padding: 8px 6px !important; }

  /* Модалки: шире на маленьком экране */
  .dist-modal { padding: 18px 14px; width: 95vw; }
  .dist-modal.sm { width: 90vw; }
}
</style>

<style>
.dist-overlay { position: fixed; inset: 0; background: rgba(44,24,16,0.35); display: flex; align-items: center; justify-content: center; z-index: 9999; backdrop-filter: blur(2px); }
</style>
