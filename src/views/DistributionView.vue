<template>
  <div class="dist">
    <!-- ═══ Список сессий ═══ -->
    <template v-if="!activeSession">
      <div class="dist-top">
        <h1 class="page-title">Распределение новинок</h1>
        <div class="dist-top-actions">
          <button class="dist-btn ghost" @click="showOverview = true" title="Сводка по всем активным сессиям, сгруппированная по дням доставки">📋 Вывести распределение</button>
          <button class="dist-btn primary" @click="showCreate = true">+ Новая сессия</button>
        </div>
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
          <button v-if="activeSession.status === 'active'" class="dist-btn ghost" title="Импорт из Excel" @click="showImport = true"><span class="btn-icon">📥</span><span class="btn-text"> Импорт</span></button>
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
                <span class="cell-row">
                  <span v-if="hasCustomQty(rest.number, p.id)" class="cell-qty">{{ getEntryQty(rest.number, p.id) }}</span>
                  <span v-if="getCellStatus(rest.number, p.id) === 1" class="cell-ok">✓</span>
                  <span v-else-if="getCellStatus(rest.number, p.id) === 2" class="cell-no">✗</span>
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

    <!-- ═══ Модалка: импорт из Excel ═══ -->
    <Teleport to="body">
      <div v-if="showImport" class="dist-overlay" @mousedown.self="closeImport">
        <div class="dist-modal" style="width:640px">
          <div class="modal-title">Импорт из Excel</div>
          <p class="modal-text" style="margin-bottom:12px">
            Скачайте шаблон, заполните количество для каждого ресторана, загрузите обратно.
            <br><br>
            <b>Что писать в клетках:</b>
            пустое — пропустить, число (например <code>2</code>) — отгружено в этом количестве,
            <code>✓</code> или <code>да</code> — отгружено по умолчанию ({{ '' }}),
            <code>✗</code> или <code>нет</code> — не нужно ресторану.
          </p>
          <div style="display:flex;gap:8px;margin-bottom:14px">
            <button class="dist-btn ghost" @click="downloadTemplate">📥 Скачать шаблон</button>
            <label class="dist-btn primary" style="cursor:pointer">
              📤 Выбрать файл
              <input type="file" accept=".xlsx,.xls" style="display:none" @change="onFileSelected"/>
            </label>
          </div>
          <div v-if="importPreview.length" class="import-preview">
            <div class="import-preview-stats">
              <span>Всего строк: <b>{{ importPreview.length }}</b></span>
              <span>К записи: <b class="ok">{{ importStats.valid }}</b></span>
              <span v-if="importStats.errors">Ошибок: <b class="err">{{ importStats.errors }}</b></span>
            </div>
            <div class="import-preview-list">
              <div v-for="(row, i) in importPreview.slice(0, 50)" :key="i" class="import-row" :class="{ err: row.error }">
                <span class="import-rn">{{ row.restaurant_number }}</span>
                <span v-if="row.error" class="import-err-msg">{{ row.error }}</span>
                <span v-else class="import-changes">
                  <span v-for="ch in row.changes" :key="ch.spId" class="import-chg">
                    {{ ch.label }}: <b>{{ ch.action }}</b>
                  </span>
                </span>
              </div>
              <div v-if="importPreview.length > 50" class="import-more">… и ещё {{ importPreview.length - 50 }} строк</div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="dist-btn ghost" @click="closeImport">Отмена</button>
            <button class="dist-btn primary" :disabled="!importStats.valid || importing" @click="doImport">
              {{ importing ? 'Импорт...' : `Импортировать ${importStats.valid} строк` }}
            </button>
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

    <!-- Сводка распределения по дням доставки -->
    <DistributionOverview v-if="showOverview" @close="showOverview = false" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import { db } from '@/lib/apiClient.js';
import { formatRestaurantNumber } from '@/lib/legalEntities.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { useDistributionSession } from '@/composables/useDistributionSession.js';
import { exportDistExcel } from '@/lib/distExcel.js';
import DistributionOverview from '@/components/distribution/DistributionOverview.vue';

const orderStore = useOrderStore();
const toastStore = useToastStore();

// UI-only state модалок и инпутов
const editingQty = ref(null);
const editQtyValue = ref('');
const editingNote = ref(null);
const editNoteValue = ref('');

// Import Excel
const showImport = ref(false);
const importPreview = ref([]); // [{restaurant_number, changes:[{spId,label,action}], error?}]
const importing = ref(false);

// Composable знает, что пользователь сейчас редактирует, чтобы не дёргать
// auto-refresh поверх его правок.
const isEditing = computed(() => !!editingQty.value || !!editingNote.value || showImport.value);
const legalEntityRef = computed(() => orderStore.settings.legalEntity);

const session = useDistributionSession({
  toastStore,
  legalEntityRef,
  isEditingRef: isEditing,
});

// Реэкспортируем то, что нужно шаблону — имена совпадают со старыми, чтобы
// разметка осталась без изменений.
const {
  loading, sessions, activeSession, sessionData,
  regionFilter, cityFilter, hideProcessed, dayFilter,
  entriesMap, notesMap, undoStack,
  sessionProducts, allRestaurants, regions, cities, availableDays,
  filteredRestaurants, totalShipped, totalNotNeeded, totalProcessed, totalCells, progressPct,
  isVM, getDayCount, entryKey, getEntry, getEntryQty, hasCustomQty,
  getCellStatus, cellClass, getProductShippedCount, getProductCrossedCount,
  loadSessions, openSession, loadSessionData, closeDetail,
  markShipped, markCrossed, undo,
} = session;

const dayShort = { 1: 'ПН', 2: 'ВТ', 3: 'СР', 4: 'ЧТ', 5: 'ПТ', 6: 'СБ' };

function fmtDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

// Create session
const showOverview = ref(false);
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

// Confirm
const confirmMsg = ref('');
const confirmAction = ref(null);

// ═══ Mouse actions ═══
// markShipped/markCrossed приходят из composable — отвечают только за
// бизнес-логику клетки. Здесь оставлены тонкие UI-обёртки:
//   - cellTitle — подсказка при наведении
//   - long-press handlers для touch-устройств (на телефоне ПКМ не существует)
const cellTitle = 'ЛКМ — отгружено, ПКМ — не нужно, двойной клик — кол-во. На телефоне: тап — ✓, долгое нажатие — ✗, двойной тап — кол-во';

const LONG_PRESS_MS = 500;
let longPressTimer = null;
let longPressFired = false;
function onTouchStart(restNum, product) {
  longPressFired = false;
  cancelLongPress();
  longPressTimer = setTimeout(() => {
    longPressFired = true;
    markCrossed(restNum, product);
    if (navigator.vibrate) navigator.vibrate(15);
  }, LONG_PRESS_MS);
}
function onTouchEnd(event) {
  cancelLongPress();
  if (longPressFired) {
    event.preventDefault();
    longPressFired = false;
  }
}
function cancelLongPress() {
  if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
}
function onCellClick(restNum, product, event) {
  if (longPressFired) { event.preventDefault(); longPressFired = false; return; }
  markShipped(restNum, product);
}

// ═══ Edit qty (UI-state, сохранение → composable.saveQty) ═══
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
  const val = editQtyValue.value.toString().trim() || null;
  editingQty.value = null;
  await session.saveQty(restNum, spId, val);
}

async function resetQty() {
  if (!editingQty.value) return;
  const { restNum, spId } = editingQty.value;
  editingQty.value = null;
  await session.saveQty(restNum, spId, null);
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
onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => window.removeEventListener('keydown', onKeydown));

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
  if (!editingNote.value) return;
  const rn = editingNote.value.restNum;
  const val = editNoteValue.value;
  editingNote.value = null;
  await session.saveNote(rn, val);
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
function exportExcel() {
  return exportDistExcel({
    sessionName: activeSession.value.name,
    products: sessionProducts.value,
    restaurants: filteredRestaurants.value,
    getStatus: getCellStatus,
    getQty: getEntryQty,
    hasCustomQty,
    isVM,
    getShippedCount: getProductShippedCount,
  });
}

// ═══ Импорт Excel ═══
// Стат подсчитываем тут же, чтобы не плодить computed на временные данные.
const importStats = computed(() => {
  let valid = 0, errors = 0;
  for (const row of importPreview.value) {
    if (row.error) errors++;
    else if (row.changes?.length) valid++;
  }
  return { valid, errors };
});

// Генерим Excel-шаблон: первая колонка — номера ресторанов сессии,
// дальше пустые колонки по числу товаров с подписями.
async function downloadTemplate() {
  const XLSX = (await import('xlsx-js-style')).default;
  const products = sessionProducts.value;
  const rests = allRestaurants.value;
  const header = ['Ресторан', 'Адрес', ...products.map(p => `${p.product_name || 'Товар'} (${p.unit})`)];
  const rows = rests.map(r => [r.number, r.address || r.city || '', ...products.map(() => '')]);
  const ws = XLSX.utils.aoa_to_sheet([header, ...rows]);
  ws['!cols'] = [{ wch: 10 }, { wch: 30 }, ...products.map(() => ({ wch: 14 }))];
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Импорт');
  XLSX.writeFile(wb, `Шаблон_${(activeSession.value?.name || 'distribution').replace(/[^a-zA-Zа-яА-Я0-9]/g, '_')}.xlsx`);
}

// Распознаём значение клетки.
//  - '' → пропустить (ничего не меняем)
//  - '✓ / + / да / yes / true' → ставим ✓ (отгружено), qty не трогаем
//  - '✗ / x / нет / no / false / -' → ставим ✗ (не нужно), qty не трогаем
//  - число (включая 0 и 1, и текст с числом, типа «2 кор») → СОХРАНЯЕМ qty БЕЗ галки
//    (statusOnly: false, shipped: null — closing-key для бэка «не трогай статус»)
//    Так закупщик может массово загрузить «количество для каждого ресторана»,
//    а галки потом поставить руками (или не поставить совсем).
function parseImportCell(raw) {
  if (raw === undefined || raw === null) return null;
  const s = String(raw).trim();
  if (s === '') return null;
  const low = s.toLowerCase();
  if (['✓', '+', 'да', 'y', 'yes', 'true'].includes(low)) return { shipped: 1, qty: null };
  if (['✗', 'x', 'х', '×', '-', 'нет', 'n', 'no', 'false'].includes(low)) return { shipped: 2, qty: null };
  // Число → только qty. shipped=null означает «не менять статус».
  if (/\d/.test(s)) return { shipped: null, qty: s };
  return { error: `Не понял значение «${s}»` };
}

async function onFileSelected(event) {
  const file = event.target.files?.[0];
  if (!file) return;
  importPreview.value = [];
  const XLSX = (await import('xlsx-js-style')).default;
  const data = await file.arrayBuffer();
  const wb = XLSX.read(data, { type: 'array' });
  const sheet = wb.Sheets[wb.SheetNames[0]];
  const rows = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '' });
  if (!rows.length) { toastStore.show('Файл пуст', 'error'); return; }

  // Строка 0 — заголовки. Колонки B...N сопоставляем с sessionProducts по индексу
  // (порядок столбцов = порядок товаров в шапке шаблона).
  const products = sessionProducts.value;
  const restMap = new Map(allRestaurants.value.map(r => [String(r.number), r]));
  const preview = [];
  for (let i = 1; i < rows.length; i++) {
    const row = rows[i];
    const rn = String(row[0] ?? '').trim();
    if (!rn) continue; // пустая строка
    if (!restMap.has(rn)) { preview.push({ restaurant_number: rn, error: 'Ресторан не найден' }); continue; }
    const changes = [];
    for (let pi = 0; pi < products.length; pi++) {
      // Шаблон: A=Ресторан, B=Адрес, C+ — товары → начинаем с индекса 2
      const cell = row[pi + 2];
      const parsed = parseImportCell(cell);
      if (!parsed) continue;
      if (parsed.error) {
        preview.push({ restaurant_number: rn, error: `${products[pi].product_name}: ${parsed.error}` });
        continue;
      }
      const p = products[pi];
      let label = p.product_name || 'Товар';
      let action;
      if (parsed.shipped === 1) action = '✓';
      else if (parsed.shipped === 2) action = '✗';
      else action = parsed.qty != null ? String(parsed.qty) : '—';
      changes.push({ spId: p.id, shipped: parsed.shipped, qty: parsed.qty, label, action });
    }
    if (changes.length) preview.push({ restaurant_number: rn, changes });
  }
  importPreview.value = preview;
  if (!preview.length) toastStore.show('Не нашлось ни одной клетки для импорта', 'warning');
  // Сбрасываем input чтобы можно было выбрать тот же файл повторно
  event.target.value = '';
}

async function doImport() {
  if (!activeSession.value || !importStats.value.valid) return;
  importing.value = true;
  try {
    const entries = [];
    for (const row of importPreview.value) {
      if (row.error || !row.changes) continue;
      for (const ch of row.changes) {
        entries.push({
          session_product_id: ch.spId,
          restaurant_number: row.restaurant_number,
          shipped: ch.shipped,
          qty: ch.qty,
        });
      }
    }
    const { error } = await db.rpc('dist_bulk_set_qty', {
      session_id: activeSession.value.id,
      entries,
    });
    if (error) { toastStore.show('Ошибка импорта: ' + error, 'error'); return; }
    toastStore.show(`Импортировано ${entries.length} клеток`, 'success');
    closeImport();
    await loadSessionData(activeSession.value.id);
  } finally { importing.value = false; }
}

function closeImport() {
  showImport.value = false;
  importPreview.value = [];
}

</script>

<style scoped>
/* ═══ Layout ═══ */
.dist { padding: 20px 24px; max-width: 100%; }
.dist-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; gap: 12px; flex-wrap: wrap; }
.dist-top-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

/* ═══ Buttons ═══ */
.dist-btn { padding: 7px 14px; border-radius: var(--radius-sm); font-size: 13px; font-weight: 600; cursor: pointer; border: none; transition: all var(--transition); font-family: inherit; }
.dist-btn.primary { background: var(--bk-brown); color: #fff; }
.dist-btn.primary:hover { background: var(--bk-red-dark); }
.dist-btn.primary.danger { background: #C62828; }
.dist-btn.primary.danger:hover { background: #B71C1C; }
.dist-btn.ghost { background: transparent; color: var(--bk-brown); border: 1px solid var(--border); }
.dist-btn.ghost .btn-icon { color: var(--bk-brown); }
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
.cell-row { display: inline-flex; align-items: center; justify-content: center; gap: 4px; line-height: 1; }
.cell-ok { color: var(--green); font-weight: 700; font-size: 14px; }
.cell-no { color: #C62828; font-weight: 700; font-size: 14px; }
.cell-qty { font-size: 12px; color: #E65100; }

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

/* Импорт Excel */
.import-preview { margin-top: 10px; }
.import-preview-stats { display: flex; gap: 16px; font-size: 13px; padding: 8px 12px; background: #fdfbf8; border-radius: 6px; margin-bottom: 8px; }
.import-preview-stats b.ok { color: var(--green); }
.import-preview-stats b.err { color: #C62828; }
.import-preview-list { max-height: 360px; overflow-y: auto; border: 1px solid var(--border); border-radius: 6px; }
.import-row { display: flex; align-items: baseline; gap: 12px; padding: 6px 12px; border-bottom: 1px solid var(--border-light); font-size: 12px; }
.import-row.err { background: #FFEBEE; }
.import-rn { font-weight: 700; color: var(--bk-brown); min-width: 36px; }
.import-changes { display: flex; flex-wrap: wrap; gap: 10px; flex: 1; color: var(--text-secondary); }
.import-chg { white-space: nowrap; }
.import-chg b { color: var(--text); }
.import-err-msg { color: #C62828; font-weight: 600; }
.import-more { padding: 10px; text-align: center; color: var(--text-muted); font-size: 12px; font-style: italic; }
code { background: #fdfbf8; padding: 1px 5px; border-radius: 3px; font-size: 0.9em; font-family: monospace; color: var(--bk-brown); }

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
