<template>
  <div class="sa-wrap" ref="rootEl">
    <!-- Загрузка дней доставки -->
    <div v-if="state === 'loading-days'" class="sa-card sa-state">
      <div class="sa-spin"></div>
      <p>Загрузка расписания…</p>
    </div>

    <!-- Ошибка -->
    <div v-else-if="state === 'error'" class="sa-card sa-state">
      <div class="sa-state-icon sa-state-icon--warn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
      </div>
      <h2>Не удалось загрузить</h2>
      <p>{{ errorMsg }}</p>
      <button class="sa-btn sa-btn--primary" @click="init">Повторить</button>
    </div>

    <!-- Нет дней доставки -->
    <div v-else-if="state === 'no-days'" class="sa-card sa-state">
      <div class="sa-state-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
      </div>
      <h2>Нет дней поставки</h2>
      <p>Дни поставки пока не запланированы. Обратитесь в отдел закупок.</p>
    </div>

    <template v-else>
      <!-- ── Выбор даты (ближайшие 5) ── -->
      <div class="sa-section">
        <div class="sa-section-label">Дата поставки</div>
        <div class="sa-days">
          <button
            v-for="day in visibleDeliveryDays"
            :key="day.date"
            class="sa-day"
            :class="{ 'is-active': selectedDate === day.date, 'has-order': day.has_order }"
            @click="selectDate(day.date)"
          >
            <span class="sa-day-name sa-day-name--full">{{ day.day_name }}</span>
            <span class="sa-day-name sa-day-name--short">{{ dayShort(day.day_of_week) }}</span>
            <span class="sa-day-date">{{ fmtShort(day.date) }}</span>
            <span v-if="day.has_order" class="sa-day-mark" v-html="checkSvg"></span>
          </button>
        </div>
      </div>

      <!-- Загрузка данных выбранной даты -->
      <div v-if="state === 'loading-order'" class="sa-card sa-state">
        <div class="sa-spin"></div>
        <p>Загрузка заказа…</p>
      </div>

      <!-- ── Форма заказа ── -->
      <template v-else-if="state === 'editing' || state === 'saving'">
        <div class="sa-card sa-form">
          <!-- Полоса «заказ сохранён» с экспортом -->
          <div v-if="loadedOrderExists" class="sa-saved-bar">
            <span class="sa-saved-bar-text">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17l9-10"/></svg>
              Заказ на {{ fmtFull(selectedDate) }} сохранён
            </span>
            <button class="sa-btn sa-btn--primary sa-btn--sm" @click="openExportModal">Экспорт 1С УТ</button>
          </div>

          <!-- Категории -->
          <div class="sa-cats">
            <button
              v-for="cat in categories"
              :key="cat"
              class="sa-cat"
              :class="{ 'is-active': activeCategory === cat }"
              @click="activeCategory = cat"
            >
              {{ cat }}
              <span v-if="getCatFilledCount(cat)" class="sa-cat-count">{{ getCatFilledCount(cat) }}</span>
            </button>
          </div>

          <!-- Поиск + добавление -->
          <div class="sa-toolbar">
            <label class="sa-search">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
              <input v-model="searchQuery" type="text" placeholder="Поиск по названию или артикулу" />
              <button v-if="searchQuery" class="sa-search-clear" @click="searchQuery = ''" aria-label="Очистить">&times;</button>
            </label>
            <button
              class="sa-btn sa-btn--toggle"
              :class="{ 'is-on': onlyFilled }"
              :disabled="totalItems === 0"
              @click="onlyFilled = !onlyFilled"
            >
              В заказе
              <span v-if="totalItems" class="sa-toggle-count">{{ totalItems }}</span>
            </button>
            <button class="sa-btn sa-btn--ghost" @click="openCatalogModal">+ Добавить товар</button>
          </div>

          <!-- Таблица позиций -->
          <div class="sa-table-wrap">
            <table v-if="filteredItems.length" class="sa-table">
              <thead>
                <tr>
                  <th class="sa-col-name">Товар</th>
                  <th class="sa-col-analog">Группа аналогов</th>
                  <th class="sa-col-mult">Крат.</th>
                  <th class="sa-col-stock">Остаток склада</th>
                  <th class="sa-col-qty">Кол-во</th>
                  <th class="sa-col-act"></th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="item in filteredItems"
                  :key="item.sku"
                  :class="{ 'is-filled': item.quantity > 0, 'is-err': item._multError }"
                >
                  <td class="sa-col-name">
                    <span class="sa-sku">{{ item.sku }}</span>
                    <span class="sa-name-text">{{ item.product_name }}</span>
                    <span v-if="item._added" class="sa-badge-added">добавлен</span>
                  </td>
                  <td class="sa-col-analog">{{ item.analog_group || '—' }}</td>
                  <td class="sa-col-mult">
                    <span v-if="item.multiplicity > 1" class="sa-mult-chip">×{{ item.multiplicity }}</span>
                    <span v-else class="sa-dim">—</span>
                  </td>
                  <td class="sa-col-stock" :class="{ 'sa-dim': !item.stock }">
                    {{ item.stock != null ? item.stock : '—' }}
                  </td>
                  <td class="sa-col-qty">
                    <input
                      v-model.number="item.quantity"
                      type="number"
                      inputmode="decimal"
                      min="0"
                      :step="item.multiplicity > 1 ? item.multiplicity : 1"
                      class="sa-qty"
                      :class="{ 'is-err': item._multError }"
                      placeholder="0"
                      @input="checkMult(item)"
                      @focus="$event.target.select()"
                    />
                    <div v-if="item._multError" class="sa-mult-hint">
                      Кратно {{ item.multiplicity }} → {{ multSuggest(item) }}
                    </div>
                  </td>
                  <td class="sa-col-act">
                    <button v-if="item._added" class="sa-row-del" @click="removeItem(item)" aria-label="Удалить позицию">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
            <div v-else class="sa-empty">
              <template v-if="onlyFilled">В заказе пока нет товаров в категории «{{ activeCategory }}»</template>
              <template v-else-if="searchQuery">Ничего не найдено в категории «{{ activeCategory }}»</template>
              <template v-else>Нет товаров в категории «{{ activeCategory }}»</template>
            </div>
          </div>
        </div>

        <!-- Итоги + сохранение -->
        <div class="sa-card sa-submit">
          <div v-if="multErrorsCount" class="sa-alert sa-alert--err">
            {{ multErrorsCount }} {{ plural(multErrorsCount, 'позиция','позиции','позиций') }} с неверной кратностью — исправьте перед сохранением.
          </div>

          <input
            v-model="orderComment"
            type="text"
            class="sa-comment"
            placeholder="Комментарий к заказу (необязательно)"
          />

          <div class="sa-submit-row">
            <div class="sa-totals">
              <div class="sa-total">
                <span class="sa-total-num">{{ totalItems }}</span>
                <span class="sa-total-lbl">{{ plural(totalItems, 'позиция','позиции','позиций') }}</span>
              </div>
              <div class="sa-total">
                <span class="sa-total-num">{{ totalQty }}</span>
                <span class="sa-total-lbl">{{ plural(totalQty, 'единица','единицы','единиц') }}</span>
              </div>
              <div v-if="estWeight > 0" class="sa-total sa-total--est">
                <span class="sa-total-num">≈{{ fmtWeight(estWeight) }}</span>
                <span class="sa-total-lbl">кг (примерно)</span>
              </div>
              <div v-if="estPallets > 0" class="sa-total sa-total--est">
                <span class="sa-total-num">≈{{ estPallets }}</span>
                <span class="sa-total-lbl">{{ plural(estPallets, 'паллета','паллеты','паллет') }} (примерно)</span>
              </div>
            </div>
            <button
              class="sa-btn sa-btn--primary sa-btn--lg"
              :disabled="state === 'saving' || totalItems === 0 || multErrorsCount > 0"
              @click="handleSave"
            >
              <span v-if="state === 'saving'" class="sa-spin sa-spin--sm"></span>
              {{ state === 'saving' ? 'Сохранение…' : 'Сохранить заказ' }}
            </button>
          </div>
          <div v-if="saveError" class="sa-alert sa-alert--err">{{ saveError }}</div>
        </div>
      </template>

      <!-- ── Экран успеха ── -->
      <div v-else-if="state === 'success'" class="sa-card sa-success">
        <div class="sa-success-check" v-html="checkSvg"></div>
        <h2>Заказ сохранён</h2>
        <div class="sa-success-date">Поставка {{ fmtFull(selectedDate) }}</div>
        <div class="sa-success-stats">
          <div class="sa-success-stat">
            <span class="sa-success-num">{{ successStats.items }}</span>
            <span class="sa-success-lbl">позиций</span>
          </div>
          <div class="sa-success-div"></div>
          <div class="sa-success-stat">
            <span class="sa-success-num">{{ successStats.qty }}</span>
            <span class="sa-success-lbl">единиц</span>
          </div>
        </div>
        <div class="sa-success-btns">
          <button class="sa-btn sa-btn--ghost" @click="backToEditing">Изменить</button>
          <button class="sa-btn sa-btn--primary" @click="openExportModal">Экспорт 1С УТ</button>
        </div>
      </div>

      <!-- ── Подсказка до выбора даты ── -->
      <div v-else class="sa-card sa-intro">
        <div class="sa-intro-head">
          <div class="sa-intro-head-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 13h6M9 17h4"/></svg>
          </div>
          <div>
            <h3>Как собрать заказ</h3>
            <p>Помощник подготовит заявку для загрузки в 1С УТ</p>
          </div>
        </div>
        <ol class="sa-intro-steps">
          <li><span class="sa-intro-num">1</span><span class="sa-intro-text">Выберите дату поставки выше</span></li>
          <li><span class="sa-intro-num">2</span><span class="sa-intro-text">Укажите количество товаров по складам — Сухой, Холод, Мороз</span></li>
          <li><span class="sa-intro-num">3</span><span class="sa-intro-text">Сохраните заказ</span></li>
          <li><span class="sa-intro-num">4</span><span class="sa-intro-text">Нажмите «Экспорт 1С УТ» и перенесите заявки в 1С</span></li>
        </ol>
      </div>
    </template>

    <!-- ══ Модалка: добавить из каталога ══ -->
    <div v-if="catalogModalOpen" class="sa-overlay" @click.self="catalogModalOpen = false">
      <div class="sa-modal">
        <div class="sa-modal-head">
          <h2>Добавить из каталога</h2>
          <button class="sa-modal-close" @click="catalogModalOpen = false" aria-label="Закрыть">&times;</button>
        </div>
        <div class="sa-modal-body">
          <p class="sa-modal-hint">Поиск по всему каталогу товаров — для позиций, которых нет в шаблоне.</p>
          <label class="sa-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <input v-model="catalogSearch" type="text" placeholder="Поиск по каталогу" @input="doCatalogSearch" ref="catalogSearchInput" />
          </label>
          <div v-if="catalogLoading" class="sa-state sa-state--inline"><div class="sa-spin"></div></div>
          <div v-else-if="catalogResults.length" class="sa-pick-list">
            <button v-for="p in catalogResults" :key="p.sku" class="sa-pick sa-pick--btn" @click="addFromCatalog(p)">
              <span class="sa-pick-main">
                <span class="sa-sku">{{ p.sku }}</span>
                <span class="sa-name-text">{{ p.name }}</span>
              </span>
              <span class="sa-pick-cat">{{ canonCategory(p.category) }}</span>
            </button>
          </div>
          <div v-else-if="catalogSearch.length >= 2" class="sa-empty">Ничего не найдено</div>
          <div v-else class="sa-empty">Введите минимум 2 символа</div>
        </div>
      </div>
    </div>

    <!-- ══ Модалка: Экспорт 1С УТ ══ -->
    <div v-if="exportModalOpen" class="sa-overlay" @click.self="exportModalOpen = false">
      <div class="sa-modal sa-modal--wide">
        <div class="sa-modal-head">
          <h2>Экспорт в 1С УТ</h2>
          <button class="sa-modal-close" @click="exportModalOpen = false" aria-label="Закрыть">&times;</button>
        </div>
        <div class="sa-modal-body">
          <p class="sa-export-hint">
            Каждый склад — отдельная заявка в 1С УТ. «Копировать заявку» даёт данные
            в формате импорта: внешний код, два пустых столбца, количество.
            Можно скопировать и отдельный столбец — клик по его заголовку выделяет его.
          </p>

          <div v-if="exportInvalidRows.length" class="sa-alert sa-alert--warn">
            <strong>{{ exportInvalidRows.length }}</strong>
            {{ plural(exportInvalidRows.length, 'позиция','позиции','позиций') }}
            без корректного 9-значного внешнего кода — они не загрузятся в 1С УТ.
          </div>

          <div v-if="copiedMsg" class="sa-copy-toast">{{ copiedMsg }}</div>

          <!-- Отдельная заявка на каждый склад -->
          <div v-for="g in exportGroups" :key="g.category" class="sa-export-group">
            <div class="sa-export-group-head">
              <span class="sa-export-group-title">Заявка — {{ g.category }}</span>
              <span class="sa-dim">{{ g.rows.length }} {{ plural(g.rows.length, 'позиция','позиции','позиций') }}</span>
              <button class="sa-btn sa-btn--primary sa-btn--sm" @click="copyGroup(g.category)">Копировать заявку</button>
            </div>
            <div class="sa-export">
              <div
                v-for="col in exportCols"
                :key="col.key"
                class="sa-export-col"
                :style="{ flex: col.flex }"
              >
                <div class="sa-export-colhead">
                  <button class="sa-export-pick" @click="selectColumn(g.category, col.key)" :title="`Выделить столбец «${col.label}»`">
                    {{ col.short }}
                  </button>
                  <button class="sa-export-copy" @click="copyColumn(g.category, col.key)" :aria-label="`Копировать «${col.label}»`">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                  </button>
                </div>
                <div class="sa-export-cells" :ref="el => setColRef(g.category, col.key, el)">
                  <div
                    v-for="row in g.rows"
                    :key="row.sku"
                    class="sa-export-cell"
                    :class="{ 'is-invalid': !row.validCode }"
                  >{{ col.value(row) }}</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Кнопка «Наверх» (в body — чтобы не обрезалась контейнерами) -->
    <Teleport to="body">
      <transition name="sa-fade">
        <button v-show="showScrollTop" class="sa-scrolltop" @click="scrollToTop" aria-label="Наверх">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
        </button>
      </transition>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { useSupplyAssistantStore } from '@/stores/supplyAssistantStore.js';

const store = useSupplyAssistantStore();

const checkSvg = '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5 10 17l9-10"/></svg>';

// ── Состояние ──
const state = ref('loading-days'); // loading-days | error | no-days | ready | loading-order | editing | saving | success
const errorMsg = ref('');
const deliveryDays = ref([]);
const selectedDate = ref('');
const orderItems = ref([]);
const activeCategory = ref('');
const searchQuery = ref('');
const onlyFilled = ref(false);
const orderComment = ref('');
const saveError = ref('');
const successStats = ref({ items: 0, qty: 0 });
const loadedOrderExists = ref(false); // на сервере уже есть сохранённый заказ на эту дату
const rootEl = ref(null);
const showScrollTop = ref(false);

// ── Утилиты ──
function plural(n, one, few, many) {
  const a = Math.abs(n) % 100;
  const b = a % 10;
  if (a > 10 && a < 20) return many;
  if (b > 1 && b < 5) return few;
  if (b === 1) return one;
  return many;
}
function fmtShort(dateStr) {
  if (!dateStr) return '';
  const [, m, d] = dateStr.split('-');
  return `${d}.${m}`;
}
function dayShort(dow) {
  return ['', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'][dow] || '';
}
function fmtFull(dateStr) {
  if (!dateStr) return '';
  const months = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
  const [, m, d] = dateStr.split('-');
  return `${parseInt(d)} ${months[parseInt(m) - 1]}`;
}
function fmtWeight(kg) {
  return kg >= 100 ? String(Math.round(kg)) : (Math.round(kg * 10) / 10).toString();
}

// «Сухой сток», «сухой склад» и т.п. — это всё «Сухой». Приводим к одной из трёх категорий.
function canonCategory(c) {
  const s = String(c || '').toLowerCase();
  if (s.includes('мороз') || s.includes('замор')) return 'Мороз';
  if (s.includes('холод') || s.includes('охлажд') || s === 'хол') return 'Холод';
  if (s.includes('сух')) return 'Сухой';
  return c || 'Сухой';
}

// Ближайшие 5 дат поставки (от сегодня).
const visibleDeliveryDays = computed(() => {
  const d = new Date();
  const today = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  return deliveryDays.value.filter(x => x.date >= today).slice(0, 5);
});

// ── Инициализация ──
async function init() {
  state.value = 'loading-days';
  errorMsg.value = '';
  try {
    const days = await store.loadDeliveryDays();
    deliveryDays.value = days;
    state.value = days.length ? 'ready' : 'no-days';
  } catch (e) {
    errorMsg.value = e.message || 'Неизвестная ошибка';
    state.value = 'error';
  }
}
onMounted(init);

// ── Кнопка «Наверх» ──
let scrollTarget = null;
function findScrollParent(el) {
  let node = el ? el.parentElement : null;
  while (node && node !== document.body && node !== document.documentElement) {
    const oy = getComputedStyle(node).overflowY;
    if ((oy === 'auto' || oy === 'scroll') && node.scrollHeight > node.clientHeight + 4) return node;
    node = node.parentElement;
  }
  return window;
}
function onScroll() {
  const y = scrollTarget === window
    ? (window.scrollY || document.documentElement.scrollTop || 0)
    : scrollTarget.scrollTop;
  showScrollTop.value = y > 500;
}
function scrollToTop() {
  if (scrollTarget === window) window.scrollTo({ top: 0, behavior: 'smooth' });
  else if (scrollTarget) scrollTarget.scrollTo({ top: 0, behavior: 'smooth' });
}
onMounted(() => {
  nextTick(() => {
    scrollTarget = findScrollParent(rootEl.value);
    scrollTarget.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  });
});
onBeforeUnmount(() => {
  if (scrollTarget) scrollTarget.removeEventListener('scroll', onScroll);
});

// ── Выбор даты и загрузка заказа ──
async function selectDate(date) {
  if (selectedDate.value === date && (state.value === 'editing' || state.value === 'success')) return;
  selectedDate.value = date;
  orderComment.value = '';
  saveError.value = '';
  searchQuery.value = '';
  onlyFilled.value = false;
  state.value = 'loading-order';
  try {
    const [templateProducts, savedOrder] = await Promise.all([
      store.loadProducts(),
      store.loadOrder(date),
    ]);
    buildOrderItems(templateProducts, savedOrder);
    loadedOrderExists.value = !!savedOrder;
    if (categories.value.length) activeCategory.value = categories.value[0];
    state.value = 'editing';
  } catch (e) {
    errorMsg.value = e.message || 'Ошибка загрузки';
    state.value = 'error';
  }
}

function buildOrderItems(templateProducts, savedOrder) {
  const savedMap = {};
  if (savedOrder && savedOrder.items) {
    for (const it of savedOrder.items) savedMap[it.sku] = it;
  }
  const items = templateProducts.map(p => ({
    sku: p.sku,
    product_name: p.name,
    category: canonCategory(p.category),
    multiplicity: p.multiplicity || 1,
    external_code: p.external_code || '',
    analog_group: p.analog_group || '',
    qty_per_box: p.qty_per_box || null,
    weight_brutto: p.weight_brutto != null ? Number(p.weight_brutto) : null,
    boxes_per_pallet: p.boxes_per_pallet != null ? Number(p.boxes_per_pallet) : null,
    stock: p.stock ?? null,
    quantity: savedMap[p.sku] ? Number(savedMap[p.sku].quantity) : 0,
    _added: false,
    _multError: false,
  }));
  if (savedOrder && savedOrder.items) {
    const templateSkus = new Set(templateProducts.map(p => p.sku));
    for (const it of savedOrder.items) {
      if (!templateSkus.has(it.sku)) {
        items.push({
          sku: it.sku,
          product_name: it.product_name,
          category: canonCategory(it.category),
          multiplicity: it.multiplicity || 1,
          external_code: it.external_code || '',
          analog_group: it.analog_group || '',
          qty_per_box: null,
          weight_brutto: null,
          boxes_per_pallet: null,
          stock: null,
          quantity: Number(it.quantity),
          _added: true,
          _multError: false,
        });
      }
    }
  }
  if (savedOrder && savedOrder.comment) orderComment.value = savedOrder.comment;
  orderItems.value = items;
}

// ── Категории ──
const categories = computed(() => {
  const order = ['Сухой', 'Холод', 'Мороз'];
  const found = new Set(orderItems.value.map(i => i.category).filter(Boolean));
  const result = order.filter(c => found.has(c));
  for (const c of found) if (!order.includes(c)) result.push(c);
  return result;
});
function getCatFilledCount(cat) {
  return orderItems.value.filter(i => i.category === cat && i.quantity > 0).length;
}

// ── Фильтрация ──
const filteredItems = computed(() => {
  const q = searchQuery.value.trim().toLowerCase();
  return orderItems.value.filter(item => {
    if (item.category !== activeCategory.value) return false;
    if (onlyFilled.value && !(item.quantity > 0)) return false;
    if (!q) return true;
    return String(item.sku || '').toLowerCase().includes(q) ||
           String(item.product_name || '').toLowerCase().includes(q);
  });
});

// ── Кратность ──
function checkMult(item) {
  const qty = Number(item.quantity) || 0;
  const mult = item.multiplicity || 1;
  item._multError = mult > 1 && qty > 0 && qty % mult !== 0;
}
function multSuggest(item) {
  const qty = Number(item.quantity) || 0;
  const mult = item.multiplicity || 1;
  return Math.ceil(qty / mult) * mult;
}
const multErrorsCount = computed(() => orderItems.value.filter(i => i._multError).length);

// ── Итого ──
const totalItems = computed(() => orderItems.value.filter(i => i.quantity > 0).length);
const totalQty = computed(() => orderItems.value.reduce((s, i) => s + (Number(i.quantity) || 0), 0));

// Примерный вес: количество × вес брутто. weight_brutto хранится в ГРАММАХ — делим на 1000.
const estWeight = computed(() => {
  let kg = 0;
  for (const i of orderItems.value) {
    const q = Number(i.quantity) || 0;
    if (q > 0 && i.weight_brutto) kg += q * Number(i.weight_brutto) / 1000;
  }
  return kg;
});
// Примерные паллетоместа: считаются по каждому складу отдельно — склады не смешиваются.
const estPallets = computed(() => {
  const byCat = {};
  for (const i of orderItems.value) {
    const q = Number(i.quantity) || 0;
    const bpp = Number(i.boxes_per_pallet) || 0;
    if (q > 0 && bpp > 0) byCat[i.category] = (byCat[i.category] || 0) + q / bpp;
  }
  let total = 0;
  for (const c in byCat) total += Math.ceil(byCat[c]);
  return total;
});

// ── Удаление позиции ──
function removeItem(item) {
  const idx = orderItems.value.indexOf(item);
  if (idx !== -1) orderItems.value.splice(idx, 1);
}

// ── Сохранение ──
async function handleSave() {
  if (state.value === 'saving') return;
  saveError.value = '';
  state.value = 'saving';
  const items = orderItems.value
    .filter(i => i.quantity > 0)
    .map(i => ({
      sku: i.sku,
      product_name: i.product_name,
      category: i.category,
      quantity: Number(i.quantity),
      multiplicity: i.multiplicity || 1,
      external_code: i.external_code || '',
      analog_group: i.analog_group || '',
    }));
  try {
    await store.saveOrder(selectedDate.value, orderComment.value, items);
    successStats.value = { items: totalItems.value, qty: totalQty.value };
    loadedOrderExists.value = true;
    const day = deliveryDays.value.find(d => d.date === selectedDate.value);
    if (day) day.has_order = true;
    state.value = 'success';
  } catch (e) {
    state.value = 'editing';
    if (e.multErrors && e.multErrors.length) {
      for (const me of e.multErrors) {
        const found = orderItems.value.find(i => i.sku === me.sku);
        if (found) found._multError = true;
      }
      saveError.value = 'Исправьте количества с неверной кратностью';
    } else {
      saveError.value = e.message || 'Ошибка сохранения';
    }
  }
}
function backToEditing() {
  state.value = 'editing';
}

// ── Модалка: каталог ──
const catalogModalOpen = ref(false);
const catalogSearch = ref('');
const catalogResults = ref([]);
const catalogLoading = ref(false);
const catalogSearchInput = ref(null);
let catalogDebounce = null;

async function openCatalogModal() {
  catalogModalOpen.value = true;
  catalogSearch.value = '';
  catalogResults.value = [];
  await nextTick();
  catalogSearchInput.value?.focus();
}
function doCatalogSearch() {
  clearTimeout(catalogDebounce);
  if (catalogSearch.value.length < 2) {
    catalogResults.value = [];
    catalogLoading.value = false;
    return;
  }
  catalogLoading.value = true;
  catalogDebounce = setTimeout(async () => {
    try {
      catalogResults.value = await store.searchProducts(catalogSearch.value);
    } catch (e) {
      catalogResults.value = [];
    }
    catalogLoading.value = false;
  }, 300);
}
function addFromCatalog(p) {
  const existing = orderItems.value.find(i => i.sku === p.sku);
  const cat = canonCategory(p.category);
  if (!existing) {
    orderItems.value.push({
      sku: p.sku,
      product_name: p.name,
      category: cat,
      multiplicity: p.multiplicity || 1,
      external_code: p.external_code || '',
      analog_group: p.analog_group || '',
      qty_per_box: p.qty_per_box || null,
      weight_brutto: p.weight_brutto != null ? Number(p.weight_brutto) : null,
      boxes_per_pallet: p.boxes_per_pallet != null ? Number(p.boxes_per_pallet) : null,
      stock: p.stock ?? null,
      quantity: 0,
      _added: true,
      _multError: false,
    });
  }
  if (categories.value.includes(cat)) activeCategory.value = cat;
  catalogModalOpen.value = false;
}

// ── Модалка: Экспорт 1С УТ ──
const exportModalOpen = ref(false);
const copiedMsg = ref('');
let copiedMsgTimer = null;
const colRefs = {};

const exportCols = [
  { key: 'code', label: 'Внешний код', short: 'Код', flex: '0 0 116px', value: r => r.external_code || '' },
  { key: 'name', label: 'Наименование', short: 'Наименование', flex: '1 1 100px', value: r => `${r.sku} ${r.product_name}` },
  { key: 'qty',  label: 'Количество',  short: 'Кол-во', flex: '0 0 108px', value: r => String(r.quantity) },
];

const exportRows = computed(() =>
  orderItems.value
    .filter(i => i.quantity > 0)
    .map(i => ({ ...i, validCode: /^\d{9}$/.test(String(i.external_code || '')) }))
);
const exportInvalidRows = computed(() => exportRows.value.filter(r => !r.validCode));

// Экспорт делится по складам: каждый склад (категория) — отдельная заявка в 1С УТ.
const exportGroups = computed(() => {
  const order = ['Сухой', 'Холод', 'Мороз'];
  const byCat = {};
  for (const r of exportRows.value) {
    (byCat[r.category] = byCat[r.category] || []).push(r);
  }
  const cats = order.filter(c => byCat[c]);
  for (const c of Object.keys(byCat)) if (!order.includes(c)) cats.push(c);
  return cats.map(c => ({ category: c, rows: byCat[c] }));
});

function colKey(cat, key) { return cat + ' ' + key; }
function setColRef(cat, key, el) { if (el) colRefs[colKey(cat, key)] = el; }

function openExportModal() {
  exportModalOpen.value = true;
  copiedMsg.value = '';
}
function showCopied(msg) {
  copiedMsg.value = msg;
  clearTimeout(copiedMsgTimer);
  copiedMsgTimer = setTimeout(() => { copiedMsg.value = ''; }, 2500);
}
function selectColumn(cat, key) {
  const el = colRefs[colKey(cat, key)];
  if (!el) return;
  const sel = window.getSelection();
  const range = document.createRange();
  range.selectNodeContents(el);
  sel.removeAllRanges();
  sel.addRange(range);
}
function copyColumn(cat, key) {
  const group = exportGroups.value.find(g => g.category === cat);
  if (!group) return;
  const col = exportCols.find(c => c.key === key);
  navigator.clipboard.writeText(group.rows.map(r => col.value(r)).join('\n'))
    .then(() => { selectColumn(cat, key); showCopied(`«${col.label}» (${cat}) скопирован`); })
    .catch(() => showCopied('Не удалось скопировать'));
}
function copyGroup(cat) {
  const group = exportGroups.value.find(g => g.category === cat);
  if (!group) return;
  // Формат импорта 1С УТ: внешний код, два пустых столбца, количество.
  const lines = group.rows.map(r => [r.external_code || '', '', '', String(r.quantity)].join('\t'));
  navigator.clipboard.writeText(lines.join('\n'))
    .then(() => showCopied(`Заявка «${cat}» скопирована`))
    .catch(() => showCopied('Не удалось скопировать'));
}
</script>

<style scoped>
.sa-wrap {
  --sa-ink: #2b1a0e;
  --sa-brown: #502314;
  --sa-muted: #8b7355;
  --sa-accent: #E76F51;
  --sa-accent-d: #d65f43;
  --sa-line: #EDE8E3;
  --sa-line-soft: #F3EEE8;
  --sa-bg-soft: #FAFAF8;
  display: flex;
  flex-direction: column;
  gap: 14px;
  color: var(--sa-ink);
}

/* ── Карточка ── */
.sa-card {
  background: #fff;
  border: 1px solid var(--sa-line);
  border-radius: 18px;
  padding: 18px;
}

/* ── Состояния ── */
.sa-state { text-align: center; padding: 36px 24px; }
.sa-state--inline { padding: 28px; }
.sa-state p { margin: 12px 0 0; color: var(--sa-muted); font-size: 14px; }
.sa-state h2 { margin: 14px 0 6px; color: var(--sa-brown); font-size: 18px; }
.sa-state-icon {
  width: 56px; height: 56px; margin: 0 auto;
  display: flex; align-items: center; justify-content: center;
  border-radius: 16px; background: var(--sa-bg-soft);
  color: var(--sa-muted);
}
.sa-state-icon svg { width: 30px; height: 30px; }
.sa-state-icon--warn { background: #FFF3EC; color: var(--sa-accent); }
.sa-state .sa-btn { margin-top: 14px; }

/* ── Спиннер ── */
.sa-spin {
  width: 32px; height: 32px; margin: 0 auto;
  border: 3px solid var(--sa-line);
  border-top-color: var(--sa-accent);
  border-radius: 50%;
  animation: sa-rotate .7s linear infinite;
}
.sa-spin--sm { width: 16px; height: 16px; border-width: 2px; margin: 0; }
@keyframes sa-rotate { to { transform: rotate(360deg); } }

/* ── Секция с подписью ── */
.sa-section { display: flex; flex-direction: column; gap: 8px; }
.sa-section-label {
  font-size: 12px; font-weight: 800; letter-spacing: .04em;
  text-transform: uppercase; color: var(--sa-muted);
}

/* ── Дни поставки ── */
.sa-days {
  display: flex; gap: 8px; overflow-x: auto;
  padding: 9px 2px 4px; -webkit-overflow-scrolling: touch;
}
.sa-day {
  position: relative; flex: 0 0 auto;
  display: flex; flex-direction: column; align-items: center; gap: 2px;
  min-width: 80px; min-height: 60px; padding: 10px 14px;
  border: 1.5px solid var(--sa-line); border-radius: 14px;
  background: #fff; cursor: pointer;
  transition: border-color .16s ease, background .16s ease, transform .1s ease;
}
.sa-day:hover { border-color: #d9cebf; }
.sa-day:active { transform: scale(.97); }
.sa-day-name { font-size: 13px; font-weight: 700; color: var(--sa-ink); }
.sa-day-name--short { display: none; }
.sa-day-date { font-size: 12px; color: var(--sa-muted); font-variant-numeric: tabular-nums; }
.sa-day.has-order { border-color: #cfe6cf; background: #F4FBF4; }
.sa-day.is-active { border-color: var(--sa-brown); background: var(--sa-brown); }
.sa-day.is-active .sa-day-name { color: #fff; }
.sa-day.is-active .sa-day-date { color: rgba(255,255,255,.72); }
.sa-day-mark {
  position: absolute; top: -7px; right: -7px;
  width: 20px; height: 20px; padding: 3px;
  border-radius: 50%; background: #3a9d4e; color: #fff;
  box-shadow: 0 0 0 2px #fff;
}

/* ── Полоса «заказ сохранён» ── */
.sa-saved-bar {
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap;
  margin: -4px 0 14px;
  padding: 10px 14px;
  background: #F4FBF4; border: 1px solid #cfe6cf; border-radius: 12px;
}
.sa-saved-bar-text {
  display: flex; align-items: center; gap: 8px;
  font-size: 13px; font-weight: 700; color: #2e7d32;
}
.sa-saved-bar-text svg { width: 17px; height: 17px; flex-shrink: 0; }

/* ── Категории ── */
.sa-cats { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
.sa-cat {
  display: inline-flex; align-items: center; gap: 7px;
  min-height: 40px; padding: 8px 16px;
  border: 1.5px solid var(--sa-line); border-radius: 10px;
  background: var(--sa-bg-soft); color: #5f4b38;
  font-size: 13px; font-weight: 700; cursor: pointer;
  transition: border-color .16s ease, background .16s ease, color .16s ease;
}
.sa-cat:hover { border-color: #d9cebf; }
.sa-cat.is-active { background: var(--sa-brown); border-color: var(--sa-brown); color: #fff; }
.sa-cat-count {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 20px; height: 20px; padding: 0 6px;
  border-radius: 10px; background: var(--sa-accent); color: #fff;
  font-size: 11px; font-weight: 800;
}
.sa-cat.is-active .sa-cat-count { background: rgba(255,255,255,.22); }

/* ── Тулбар ── */
.sa-toolbar { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
.sa-toolbar .sa-search { flex: 1; min-width: 200px; }
.sa-search {
  display: flex; align-items: center; gap: 8px;
  padding: 0 12px; min-height: 44px;
  background: var(--sa-bg-soft); border: 1px solid var(--sa-line);
  border-radius: 10px;
  transition: border-color .16s ease, box-shadow .16s ease, background .16s ease;
}
.sa-search:focus-within {
  background: #fff; border-color: var(--sa-brown);
  box-shadow: 0 0 0 3px rgba(80,35,20,.1);
}
.sa-search svg { width: 18px; height: 18px; stroke: var(--sa-muted); flex-shrink: 0; }
.sa-search input {
  flex: 1; min-width: 0; appearance: none;
  border: 0; outline: 0; background: transparent;
  font: inherit; font-size: 15px; color: var(--sa-ink); min-height: 42px;
}
.sa-search-clear {
  border: 0; background: transparent; cursor: pointer;
  font-size: 22px; line-height: 1; color: var(--sa-muted);
  padding: 0 2px;
}

/* ── Кнопки ── */
.sa-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  min-height: 44px; padding: 10px 18px;
  border-radius: 10px; border: 1.5px solid transparent;
  font: inherit; font-size: 14px; font-weight: 700; cursor: pointer;
  transition: background .16s ease, border-color .16s ease, opacity .16s ease;
}
.sa-btn--lg { min-height: 48px; padding: 12px 26px; font-size: 15px; }
.sa-btn--sm { min-height: 36px; padding: 7px 14px; font-size: 13px; }
.sa-btn--primary { background: var(--sa-accent); color: #fff; }
.sa-btn--primary:hover:not(:disabled) { background: var(--sa-accent-d); }
.sa-btn--ghost { background: #fff; border-color: #d9cebf; color: var(--sa-brown); }
.sa-btn--ghost:hover:not(:disabled) { background: var(--sa-bg-soft); border-color: var(--sa-brown); }
.sa-btn--toggle { background: var(--sa-bg-soft); border-color: var(--sa-line); color: #5f4b38; }
.sa-btn--toggle:hover:not(:disabled) { border-color: #d9cebf; }
.sa-btn--toggle.is-on { background: var(--sa-brown); border-color: var(--sa-brown); color: #fff; }
.sa-btn:disabled { opacity: .5; cursor: default; }
.sa-toggle-count {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 20px; height: 20px; padding: 0 6px;
  border-radius: 10px; background: var(--sa-accent); color: #fff;
  font-size: 11px; font-weight: 800;
}
.sa-btn--toggle.is-on .sa-toggle-count { background: rgba(255,255,255,.22); }

/* ── Таблица позиций ── */
.sa-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.sa-table { width: 100%; border-collapse: collapse; }
.sa-table thead th {
  position: sticky; top: 0;
  padding: 8px 10px; text-align: center;
  font-size: 11px; font-weight: 800; letter-spacing: .03em;
  text-transform: uppercase; color: var(--sa-muted);
  background: #fff; border-bottom: 1.5px solid var(--sa-line);
  white-space: nowrap;
}
.sa-table tbody td {
  padding: 9px 10px;
  border-bottom: 1px solid var(--sa-line-soft);
  font-size: 14px; vertical-align: middle;
}
.sa-table tbody tr:last-child td { border-bottom: 0; }
.sa-table tbody tr.is-filled { background: #FFF9F1; }
.sa-table tbody tr.is-err { background: #FFF1F0; }

.sa-col-name { min-width: 200px; white-space: normal; line-height: 1.4; text-align: left; }
.sa-col-analog { font-size: 12px; color: var(--sa-muted); max-width: 160px; text-align: left; }
.sa-col-mult { text-align: center; white-space: nowrap; }
.sa-col-stock { text-align: center; font-variant-numeric: tabular-nums; white-space: nowrap; }
.sa-col-qty { width: 96px; }
.sa-col-act { width: 40px; }

.sa-sku { font-weight: 800; color: var(--sa-accent); font-size: 12.5px; margin-right: 5px; }
.sa-name-text { color: var(--sa-ink); }
.sa-dim { color: #c2b6a6; }

.sa-mult-chip {
  display: inline-block; padding: 1px 7px;
  border-radius: 6px; background: #F0E7DC;
  font-size: 12px; font-weight: 700; color: #7a5c3a;
}
.sa-badge-added {
  display: inline-block; margin-left: 6px; padding: 1px 7px;
  border-radius: 6px; background: #EAF3FB;
  font-size: 11px; font-weight: 700; color: #3b6ea3; vertical-align: middle;
}

.sa-qty {
  width: 100%; min-height: 40px; padding: 6px 8px;
  border: 1.5px solid var(--sa-line); border-radius: 9px;
  background: var(--sa-bg-soft);
  font: inherit; font-size: 15px; font-weight: 700; text-align: center;
  color: var(--sa-ink); font-variant-numeric: tabular-nums;
  transition: border-color .16s ease, box-shadow .16s ease, background .16s ease;
}
.sa-qty:focus {
  outline: 0; background: #fff; border-color: var(--sa-brown);
  box-shadow: 0 0 0 3px rgba(80,35,20,.1);
}
.sa-qty.is-err { border-color: #e5736b; background: #FFF1F0; color: #c0392b; }
.sa-mult-hint { margin-top: 3px; font-size: 11px; color: #c0392b; font-weight: 600; text-align: center; }

.sa-row-del {
  display: flex; align-items: center; justify-content: center;
  width: 32px; height: 32px; padding: 0; margin: 0 auto;
  border: 0; border-radius: 8px; background: transparent;
  color: #c08b8b; cursor: pointer;
  transition: background .16s ease, color .16s ease;
}
.sa-row-del:hover { background: #FFF1F0; color: #c0392b; }
.sa-row-del svg { width: 16px; height: 16px; }

/* ── Пусто ── */
.sa-empty {
  padding: 28px 16px; text-align: center;
  color: var(--sa-muted); font-size: 14px;
}

/* ── Блок сохранения ── */
.sa-submit { display: flex; flex-direction: column; gap: 12px; }
.sa-comment {
  width: 100%; min-height: 44px; padding: 10px 14px;
  border: 1px solid var(--sa-line); border-radius: 10px;
  background: var(--sa-bg-soft);
  font: inherit; font-size: 14px; color: var(--sa-ink);
  transition: border-color .16s ease, box-shadow .16s ease, background .16s ease;
}
.sa-comment:focus {
  outline: 0; background: #fff; border-color: var(--sa-brown);
  box-shadow: 0 0 0 3px rgba(80,35,20,.1);
}
.sa-submit-row {
  display: flex; align-items: center; justify-content: space-between;
  gap: 14px; flex-wrap: wrap;
}
.sa-totals { display: flex; gap: 20px; flex-wrap: wrap; }
.sa-total { display: flex; flex-direction: column; }
.sa-total-num { font-size: 22px; font-weight: 800; color: var(--sa-brown); line-height: 1.1; font-variant-numeric: tabular-nums; }
.sa-total-lbl { font-size: 12px; color: var(--sa-muted); }
.sa-total--est .sa-total-num { color: var(--sa-muted); }
.sa-submit-row .sa-btn { flex: 0 0 auto; }

/* ── Алерты ── */
.sa-alert {
  padding: 9px 13px; border-radius: 10px;
  font-size: 13px; font-weight: 600; line-height: 1.4;
}
.sa-alert--err { background: #FFF1F0; border: 1px solid #f3c9c5; color: #b23b30; }
.sa-alert--warn { background: #FFF8E8; border: 1px solid #f0dca0; color: #8a6400; }
.sa-alert strong { font-weight: 800; }

/* ── Экран успеха ── */
.sa-success { text-align: center; padding: 34px 24px; }
.sa-success-check {
  width: 64px; height: 64px; margin: 0 auto 14px; padding: 16px;
  border-radius: 50%; background: #EAF7EC; color: #3a9d4e;
}
.sa-success h2 { margin: 0; font-size: 21px; color: var(--sa-brown); }
.sa-success-date { margin-top: 4px; color: var(--sa-muted); font-size: 14px; }
.sa-success-stats {
  display: flex; align-items: center; justify-content: center; gap: 22px;
  margin: 18px 0 20px;
}
.sa-success-stat { display: flex; flex-direction: column; }
.sa-success-num { font-size: 26px; font-weight: 800; color: var(--sa-brown); font-variant-numeric: tabular-nums; }
.sa-success-lbl { font-size: 12px; color: var(--sa-muted); }
.sa-success-div { width: 1px; height: 34px; background: var(--sa-line); }
.sa-success-btns { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }

/* ── Модалки ── */
.sa-overlay {
  position: fixed; inset: 0; z-index: 1000;
  display: flex; align-items: center; justify-content: center;
  padding: 16px; background: rgba(40,24,14,.5);
  -webkit-backdrop-filter: blur(2px); backdrop-filter: blur(2px);
}
.sa-modal {
  display: flex; flex-direction: column;
  width: 100%; max-width: 460px; max-height: 88vh;
  background: #fff; border-radius: 18px; overflow: hidden;
  box-shadow: 0 24px 60px rgba(40,24,14,.3);
}
.sa-modal--wide { max-width: 680px; }
.sa-modal-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 18px; border-bottom: 1px solid var(--sa-line);
}
.sa-modal-head h2 { margin: 0; font-size: 17px; color: var(--sa-brown); }
.sa-modal-close {
  width: 34px; height: 34px; flex-shrink: 0;
  border: 0; border-radius: 8px; background: var(--sa-bg-soft);
  font-size: 22px; line-height: 1; color: var(--sa-muted); cursor: pointer;
  transition: background .16s ease;
}
.sa-modal-close:hover { background: #F0E7DC; }
.sa-modal-body { padding: 16px 18px; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; }
.sa-modal-hint { margin: 0; font-size: 13px; line-height: 1.5; color: var(--sa-muted); }
.sa-modal-foot {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  padding: 14px 18px; border-top: 1px solid var(--sa-line);
}

/* ── Список выбора в модалках ── */
.sa-pick-list {
  display: flex; flex-direction: column;
  max-height: 52vh; overflow-y: auto;
  border: 1px solid var(--sa-line); border-radius: 12px;
}
.sa-pick {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 12px; cursor: pointer;
  border-bottom: 1px solid var(--sa-line-soft);
  font: inherit; text-align: left; background: transparent;
}
.sa-pick:last-child { border-bottom: 0; }
.sa-pick--btn { border-width: 0 0 1px; width: 100%; }
.sa-pick:hover { background: var(--sa-bg-soft); }
.sa-pick.is-checked { background: #FFF7F2; }
.sa-pick input[type="checkbox"] { width: 18px; height: 18px; flex-shrink: 0; accent-color: var(--sa-accent); }
.sa-pick-main { flex: 1; min-width: 0; font-size: 14px; line-height: 1.4; }
.sa-pick-cat {
  display: inline-block; margin-left: 6px; padding: 1px 7px;
  border-radius: 6px; background: var(--sa-bg-soft); border: 1px solid var(--sa-line);
  font-size: 11px; font-weight: 700; color: var(--sa-muted); white-space: nowrap;
}
.sa-pick-qty { font-size: 13px; font-weight: 700; color: var(--sa-muted); font-variant-numeric: tabular-nums; white-space: nowrap; }

/* ── Экспорт 1С УТ ── */
.sa-export-hint { margin: 0; font-size: 13px; line-height: 1.5; color: var(--sa-muted); }
.sa-copy-toast {
  padding: 8px 12px; border-radius: 9px;
  background: #EAF7EC; border: 1px solid #bfe3c4;
  font-size: 13px; font-weight: 700; color: #2e7d32;
}
.sa-export-group { display: flex; flex-direction: column; gap: 8px; }
.sa-export-group + .sa-export-group { margin-top: 4px; }
.sa-export-group-head {
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
}
.sa-export-group-title { font-size: 14px; font-weight: 800; color: var(--sa-brown); }
.sa-export-group-head .sa-btn { margin-left: auto; }
.sa-export {
  display: flex; gap: 0;
  border: 1px solid var(--sa-line); border-radius: 12px; overflow: hidden;
}
.sa-export-col { display: flex; flex-direction: column; min-width: 0; border-right: 1px solid var(--sa-line); }
.sa-export-col:last-child { border-right: 0; }
.sa-export-colhead {
  display: flex; align-items: center; gap: 6px;
  padding: 6px 8px; background: var(--sa-bg-soft);
  border-bottom: 1.5px solid var(--sa-line);
}
.sa-export-pick {
  flex: 1; min-width: 0; padding: 4px 6px;
  border: 0; background: transparent; cursor: pointer;
  font: inherit; font-size: 11px; font-weight: 800; letter-spacing: .03em;
  text-transform: uppercase; color: var(--sa-brown); text-align: center;
  border-radius: 6px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.sa-export-pick:hover { background: #F0E7DC; }
.sa-export-copy {
  display: flex; align-items: center; justify-content: center;
  width: 28px; height: 28px; flex-shrink: 0;
  border: 1px solid var(--sa-line); border-radius: 7px;
  background: #fff; color: var(--sa-muted); cursor: pointer;
  transition: color .16s ease, border-color .16s ease;
}
.sa-export-copy:hover { color: var(--sa-accent); border-color: var(--sa-accent); }
.sa-export-copy svg { width: 15px; height: 15px; }
.sa-export-cells { display: flex; flex-direction: column; user-select: text; cursor: text; }
.sa-export-cell {
  padding: 5px 10px; height: 30px;
  display: flex; align-items: center;
  font-size: 13px; line-height: 1.3; color: var(--sa-ink);
  border-bottom: 1px solid var(--sa-line-soft);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  font-variant-numeric: tabular-nums;
}
.sa-export-cell:last-child { border-bottom: 0; }
.sa-export-cell.is-invalid { background: #FFF1F0; color: #c0392b; font-weight: 600; }

/* ── Подсказка до выбора даты ── */
.sa-intro-head {
  display: flex; align-items: center; gap: 12px;
  margin-bottom: 16px; padding-bottom: 14px;
  border-bottom: 1px solid var(--sa-line-soft);
}
.sa-intro-head-icon {
  width: 44px; height: 44px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  border-radius: 12px; background: #FFF3EC; color: var(--sa-accent);
}
.sa-intro-head-icon svg { width: 24px; height: 24px; }
.sa-intro-head h3 { margin: 0; font-size: 17px; color: var(--sa-brown); }
.sa-intro-head p { margin: 3px 0 0; font-size: 13px; color: var(--sa-muted); }
.sa-intro-steps { list-style: none; margin: 0; padding: 0; }
.sa-intro-steps li {
  position: relative; display: flex; gap: 12px; align-items: flex-start;
  padding-bottom: 16px;
}
.sa-intro-steps li:last-child { padding-bottom: 0; }
.sa-intro-steps li:not(:last-child)::before {
  content: ''; position: absolute; left: 13px; top: 30px; bottom: 0;
  width: 2px; background: var(--sa-line);
}
.sa-intro-num {
  position: relative; z-index: 1;
  display: flex; align-items: center; justify-content: center;
  width: 28px; height: 28px; flex-shrink: 0;
  border-radius: 50%; background: var(--sa-accent); color: #fff;
  font-size: 14px; font-weight: 800;
}
.sa-intro-text { font-size: 14px; line-height: 1.5; color: var(--sa-ink); padding-top: 4px; }

/* ── Кнопка «Наверх» ── */
/* Кнопка телепортируется в body — переменные --sa-* не наследуются, цвета литералом */
.sa-scrolltop {
  position: fixed; right: 16px; z-index: 90;
  bottom: calc(16px + env(safe-area-inset-bottom, 0px));
  width: 46px; height: 46px;
  display: flex; align-items: center; justify-content: center;
  border: 0; border-radius: 50%;
  background: #502314; color: #fff;
  box-shadow: 0 6px 20px rgba(40,24,14,.35);
  cursor: pointer;
}
.sa-scrolltop:hover { background: #3d1a0f; }
.sa-scrolltop:active { transform: scale(.94); }
.sa-scrolltop svg { width: 22px; height: 22px; }
.sa-fade-enter-active, .sa-fade-leave-active { transition: opacity .2s ease, transform .2s ease; }
.sa-fade-enter-from, .sa-fade-leave-to { opacity: 0; transform: translateY(8px); }

/* ══ Мобильная компактная версия — без горизонтального скролла ══ */
@media (max-width: 560px) {
  .sa-card { padding: 14px; border-radius: 14px; }
  .sa-submit-row { flex-direction: column; align-items: stretch; }
  .sa-submit-row .sa-btn { width: 100%; }
  .sa-totals { justify-content: space-between; gap: 12px; }

  /* Даты — 5 узких плиток в один ряд, без переноса и скролла */
  .sa-days { flex-wrap: nowrap; overflow-x: visible; gap: 6px; }
  .sa-day { flex: 1 1 0; min-width: 0; padding: 9px 4px; }
  .sa-day-name--full { display: none; }
  .sa-day-name--short { display: inline; }

  /* Таблица позиций → компактные строки: товар · ×кратность · количество */
  .sa-table-wrap { overflow-x: visible; }
  .sa-table, .sa-table tbody { display: block; }
  .sa-table thead { display: none; }
  .sa-table tbody tr {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    border: 1.5px solid var(--sa-line); border-radius: 10px;
    padding: 8px 10px; margin-bottom: 8px; background: #fff;
  }
  .sa-table tbody tr.is-filled { border-color: #efd3b3; background: #FFF9F1; }
  .sa-table tbody tr.is-err { border-color: #e9b4af; background: #FFF1F0; }
  .sa-table tbody td { display: block; border: 0; padding: 0; }

  /* Только товар, кратность, количество. Селекторы с .sa-table tbody td —
     иначе их по специфичности перебивает правило td выше. */
  .sa-table tbody td.sa-col-analog,
  .sa-table tbody td.sa-col-stock { display: none; }
  .sa-table tbody td.sa-col-name { flex: 1 1 120px; min-width: 0; font-size: 13.5px; line-height: 1.35; }
  .sa-table tbody td.sa-col-mult { width: auto; text-align: center; }
  .sa-col-mult .sa-dim { display: none; }
  .sa-table tbody td.sa-col-qty { display: flex; flex-direction: column; align-items: flex-end; width: auto; }
  .sa-col-qty .sa-qty { width: 76px; }
  .sa-mult-hint { width: 100%; text-align: right; margin-top: 4px; }
  .sa-table tbody td.sa-col-act { width: auto; }
}
</style>
