<template>
  <div class="rco">
    <!-- Онбординг: модалка с подробностями. По умолчанию открывается один раз -->
    <div v-if="showTutorial" class="rco-tut-overlay" @click.self="dismissTutorial">
      <div class="rco-tut-box" role="dialog" aria-modal="true" aria-label="Как пользоваться корректировками">
        <button type="button" class="rco-tut-close" aria-label="Закрыть" @click="dismissTutorial">×</button>
        <h3 class="rco-tut-title">Как работают корректировки основной поставки</h3>

        <p class="rco-tut-lead">
          Раздел только про <strong>основную поставку со склада</strong>. Заявки локальных поставщиков (Камако и др.) сюда не относятся — у них своя процедура.
        </p>

        <ol class="rco-tut-list">
          <li>
            <strong>Что это.</strong> Если в уже отправленном заказе на ближайшую <em>основную поставку</em> нужно что-то <em>добавить, убрать или поправить количество</em> — отправь корректировку. Закупки получат её сразу в Telegram и в&nbsp;портале.
          </li>
          <li>
            <strong>Когда успеть.</strong> Подать можно до <em>дедлайна корректировки</em> — он указан под датой каждой поставки. После дедлайна доставка уходит в работу и менять её нельзя.
          </li>
          <li>
            <strong>Как заполнить.</strong> Жми «Ещё позиция», для каждой выбери действие <span class="rco-tut-chip rco-tut-chip-add">+ Добавить</span> или <span class="rco-tut-chip rco-tut-chip-rem">− Убрать</span>, найди товар по артикулу или названию, укажи количество и единицу (коробки/штуки). Если товара нет в базе — отправится тем, как написал.
          </li>
          <li>
            <strong>Причина.</strong> Поле внизу — необязательное, но если есть нюанс («запуск меню», «мероприятие в пятницу»), напиши — закупки увидят это рядом с заявкой.
          </li>
          <li>
            <strong>Что дальше.</strong> Заявка приходит закупкам. Статусы:
            <ul class="rco-tut-sub">
              <li><span class="rco-stat-icon">⏳</span> <strong>Ожидает</strong> — пришло, ещё не разобрали.</li>
              <li><span class="rco-stat-icon">🛠</span> <strong>В работе</strong> — кто-то из закупок взял в работу.</li>
              <li><span class="rco-stat-icon">✅</span> <strong>Принято</strong> — изменение войдёт в поставку.</li>
              <li><span class="rco-stat-icon">❌</span> <strong>Отклонено</strong> — не приняли, рядом будет комментарий почему.</li>
              <li><span class="rco-stat-icon">⛔</span> <strong>Отменено</strong> — ты сам отозвал заявку.</li>
            </ul>
          </li>
          <li>
            <strong>Изменить / отозвать.</strong> Пока статус «Ожидает», можешь нажать «Изменить» или «Отменить». После «В работе» — уже нельзя.
          </li>
          <li>
            <strong>Уведомления.</strong> Когда закупки ответят — придёт push в браузере (если включил его в «Напоминаниях») и сообщение в Telegram-боте (тем, кто привязан).
          </li>
          <li>
            <strong>История.</strong> Сверху есть переключатель — «Активные» и «Вся история». В истории фильтр по статусу.
          </li>
        </ol>

        <button type="button" class="rco-tut-ok" @click="dismissTutorial">Понятно</button>
      </div>
    </div>

    <!-- Шапка: заголовок + короткое объяснение + кнопка «?» -->
    <header class="rco-page-head" v-if="!loading">
      <h2 class="rco-page-title">
        <span class="rco-page-title-icon" aria-hidden="true">📦</span>
        Корректировка заказа основной поставки
      </h2>
      <p class="rco-page-sub">Доставка со склада. Здесь можно изменить уже отправленный заказ — добавить, убрать или поправить позиции на ближайшую доставку до её дедлайна.</p>
      <button type="button" class="rco-help-btn" @click="openTutorial" title="Как это работает" aria-label="Как это работает">?</button>
    </header>

    <!-- Переключатель «Активные / Вся история» -->
    <div class="rco-mode-tabs" v-if="!loading">
      <button type="button" class="rco-mode-tab" :class="{ 'is-active': viewMode === 'active' }" @click="switchMode('active')">Активные</button>
      <button type="button" class="rco-mode-tab" :class="{ 'is-active': viewMode === 'history' }" @click="switchMode('history')">Вся история</button>
    </div>

    <div v-if="loading" class="rco-state">
      <BurgerSpinner text="Загрузка корректировок..." />
    </div>

    <!-- ─────── ВКЛАДКА «АКТИВНЫЕ» ─────── -->
    <template v-else-if="viewMode === 'active'">
    <div v-if="!deliveries.length && !batches.length" class="rco-state rco-state-empty">
      <h3>Корректировок не подать</h3>
      <p>Сейчас нет ближайших поставок, по которым дедлайн ещё впереди.</p>
      <p class="rco-state-hint">Корректировка возможна до 11:30 рабочего дня перед поставкой.</p>
    </div>

    <template v-else>
      <!-- ── Выбор даты поставки ── -->
      <section class="rco-deliveries">
        <h3 class="rco-section-title">На какую дату основной поставки?</h3>
        <div class="rco-d-pills">
          <button v-for="d in deliveries"
                  :key="d.date"
                  type="button"
                  class="rco-d-pill"
                  :class="{ 'is-active': d.date === selectedDate }"
                  @click="selectDate(d.date)">
            <span class="rco-d-date">{{ d.date_fmt }}</span>
            <span class="rco-d-deadline">дедлайн {{ d.deadline_fmt }}</span>
          </button>
        </div>
      </section>

      <!-- ── Уже отправленные ── -->
      <section v-if="selectedDate && batches.length" class="rco-batches">
        <h3 class="rco-section-title">Уже отправленные на эту дату</h3>
        <article v-for="b in batches" :key="b.batch_uuid || b.created_at" class="rco-batch" :class="'rco-batch--' + dominantStatus(b)">
          <header class="rco-batch-head">
            <div class="rco-batch-meta">
              <span class="rco-batch-status-chip" :class="'is-' + dominantStatus(b)">{{ batchStatusLabel(b) }}</span>
              <span class="rco-batch-src">{{ b.source === 'cabinet' ? 'из кабинета' : 'из Telegram' }}</span>
              <span class="rco-batch-when">{{ fmtDateTime(b.created_at) }}</span>
            </div>
            <div v-if="b.can_edit || b.can_cancel" class="rco-batch-actions">
              <button type="button" class="rco-btn rco-btn-ghost" @click="startEdit(b)" :disabled="busy">Изменить</button>
              <button type="button" class="rco-btn rco-btn-ghost rco-btn-danger" @click="askCancel(b)" :disabled="busy">Отменить</button>
            </div>
          </header>

          <p v-if="b.comment" class="rco-batch-comment">💬 {{ b.comment }}</p>

          <ul class="rco-batch-items">
            <li v-for="it in b.items" :key="it.id" class="rco-batch-item" :class="'is-' + it.status">
              <span class="rco-batch-item-icon" :title="statusLabel(it.status)">{{ statusIcon(it.status) }}</span>
              <span class="rco-batch-item-act" :class="it.action">{{ it.action === 'add' ? 'Добавить' : 'Убрать' }}</span>
              <span class="rco-batch-item-name">
                <span v-if="it.sku !== '-'" class="rco-batch-item-sku">{{ it.sku }}</span>
                {{ it.name }}
              </span>
              <span class="rco-batch-item-qty">{{ fmtQty(it.qty) }} {{ it.unit }}</span>
              <span v-if="it.review_comment" class="rco-batch-item-rc">«{{ it.review_comment }}»</span>
            </li>
          </ul>
        </article>
      </section>

      <!-- ── Форма новой/изменения корректировки ── -->
      <section v-if="selectedDate" class="rco-form">
        <h3 class="rco-section-title">
          {{ editingBatchUuid ? 'Изменить корректировку' : 'Новая корректировка' }}
        </h3>

        <div class="rco-form-rows">
          <div v-for="(it, idx) in formItems" :key="idx" class="rco-form-row">
            <button type="button"
                    class="rco-act-btn"
                    :class="'rco-act-' + it.action"
                    :title="it.action === 'add' ? 'Сейчас: добавить. Кликни, чтобы поменять на убрать' : 'Сейчас: убрать. Кликни, чтобы поменять на добавить'"
                    @click="toggleAction(idx)">
              <span class="rco-act-sign">{{ it.action === 'add' ? '+' : '−' }}</span>
              <span class="rco-act-label">{{ it.action === 'add' ? 'Добавить' : 'Убрать' }}</span>
            </button>

            <div class="rco-prod">
              <input type="text"
                     class="rco-prod-input"
                     :value="it.label"
                     placeholder="Артикул или название…"
                     @input="onProdInput(idx, $event.target.value)"
                     @focus="onProdFocus(idx)"
                     @blur="onProdBlur(idx)"
                     @keydown.down.prevent="moveProdSel(idx, 1)"
                     @keydown.up.prevent="moveProdSel(idx, -1)"
                     @keydown.enter.prevent="pickProdHighlighted(idx)"
                     :ref="el => prodRefs[idx] = el" />
              <div v-if="prodOpen === idx && prodResults.length" class="rco-prod-pop">
                <button v-for="(p, i) in prodResults"
                        :key="p.sku + '_' + i"
                        type="button"
                        class="rco-prod-opt"
                        :class="{ 'is-hl': i === prodHighlight }"
                        @mousedown.prevent="pickProd(idx, p)">
                  <span class="rco-prod-opt-sku">{{ p.sku }}</span>
                  <span class="rco-prod-opt-name">{{ p.name }}</span>
                </button>
              </div>
              <div v-if="prodOpen === idx && !prodResults.length && (it.label || '').length >= 2 && !prodSearching" class="rco-prod-pop rco-prod-pop-empty">
                Не нашли в каталоге — отправим как «{{ it.label }}»
              </div>
            </div>

            <input type="number"
                   class="rco-qty"
                   min="0"
                   step="0.5"
                   v-model.number="it.qty"
                   placeholder="кол-во" />

            <button type="button"
                    class="rco-unit-btn"
                    :title="it.unit === 'кор.' ? 'Кликни, чтобы поменять на штуки' : 'Кликни, чтобы поменять на коробки'"
                    @click="toggleUnit(idx)">{{ it.unit }}</button>

            <button type="button" class="rco-row-del" @click="removeRow(idx)" :disabled="formItems.length === 1" aria-label="Удалить строку">×</button>
          </div>
        </div>

        <button type="button" class="rco-add-row" @click="addRow">+ Ещё позиция</button>

        <label class="rco-comment-label">
          <span>Причина (по желанию)</span>
          <textarea v-model="formComment"
                    rows="2"
                    maxlength="1000"
                    placeholder="Например: запуск нового меню, мероприятие в пятницу"></textarea>
        </label>

        <div class="rco-submit-row">
          <button v-if="editingBatchUuid" type="button" class="rco-btn rco-btn-ghost" @click="cancelEdit" :disabled="busy">Отмена</button>
          <button type="button" class="rco-btn rco-btn-primary" :disabled="!canSubmit || busy" @click="submit">
            {{ busy ? 'Отправляем…' : (editingBatchUuid ? 'Сохранить изменения' : 'Отправить') }}
          </button>
        </div>
      </section>
    </template>
    </template>

    <!-- ─────── ВКЛАДКА «ВСЯ ИСТОРИЯ» ─────── -->
    <template v-else-if="viewMode === 'history'">
      <section class="rco-history">
        <div class="rco-history-toolbar">
          <span class="rco-history-toolbar-label">Статус:</span>
          <button type="button"
                  v-for="opt in HISTORY_STATUS_OPTIONS"
                  :key="opt.value"
                  class="rco-history-chip"
                  :class="{ 'is-active': historyStatusFilter === opt.value }"
                  @click="historyStatusFilter = opt.value">{{ opt.label }}</button>
        </div>

        <div v-if="!filteredHistory.length" class="rco-state rco-state-empty">
          <p>Корректировок не найдено по этому фильтру.</p>
        </div>

        <article v-for="b in filteredHistory" :key="b.batch_uuid || b.created_at" class="rco-batch" :class="'rco-batch--' + dominantStatus(b)">
          <header class="rco-batch-head">
            <div class="rco-batch-meta">
              <span class="rco-batch-status-chip" :class="'is-' + dominantStatus(b)">{{ batchStatusLabel(b) }}</span>
              <span class="rco-batch-when-strong">Доставка {{ fmtDateShort(b.delivery_date) }}</span>
              <span class="rco-batch-src">{{ b.source === 'cabinet' ? 'из кабинета' : 'из Telegram' }}</span>
              <span class="rco-batch-when">{{ fmtDateTime(b.created_at) }}</span>
            </div>
          </header>

          <p v-if="b.comment" class="rco-batch-comment">💬 {{ b.comment }}</p>

          <ul class="rco-batch-items">
            <li v-for="it in b.items" :key="it.id" class="rco-batch-item" :class="'is-' + it.status">
              <span class="rco-batch-item-icon" :title="statusLabel(it.status)">{{ statusIcon(it.status) }}</span>
              <span class="rco-batch-item-act" :class="it.action">{{ it.action === 'add' ? 'Добавить' : 'Убрать' }}</span>
              <span class="rco-batch-item-name">
                <span v-if="it.sku !== '-'" class="rco-batch-item-sku">{{ it.sku }}</span>
                {{ it.name }}
              </span>
              <span class="rco-batch-item-qty">{{ fmtQty(it.qty) }} {{ it.unit }}</span>
              <span v-if="it.review_comment" class="rco-batch-item-rc">«{{ it.review_comment }}»</span>
            </li>
          </ul>
        </article>
      </section>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, defineAsyncComponent } from 'vue';
import { useToastStore } from '@/stores/toastStore.js';
import { roFetch } from '@/lib/roUtils.js';
import { appConfirm } from '@/lib/appDialogs.js';

const BurgerSpinner = defineAsyncComponent(() => import('@/components/ui/BurgerSpinner.vue'));
const toast = useToastStore();

const loading = ref(true);
const busy = ref(false);

// 'active' — рабочая вкладка (ближайшие даты + форма + батчи по выбранной дате)
// 'history' — вся история ресторана со статус-фильтром
const viewMode = ref('active');

// Онбординг при первом визите. Флаг хранится в localStorage по версии:
// если решим обновить туториал, поменяем суффикс — увидят заново.
const TUTORIAL_KEY = 'corrections_tutorial_seen_v1';
const showTutorial = ref(false);
function openTutorial() { showTutorial.value = true; }
function dismissTutorial() {
  showTutorial.value = false;
  try { localStorage.setItem(TUTORIAL_KEY, '1'); } catch (e) { /* ignore */ }
}
function onTutorialEsc(e) { if (e.key === 'Escape' && showTutorial.value) dismissTutorial(); }

const deliveries = ref([]);
const selectedDate = ref('');
const batches = ref([]);

// История — все батчи ресторана (загружается лениво при переключении)
const historyBatches = ref([]);
const historyLoaded = ref(false);
const historyStatusFilter = ref('');
const HISTORY_STATUS_OPTIONS = [
  { value: '', label: 'Все' },
  { value: 'pending', label: 'Ожидают' },
  { value: 'in_progress', label: 'В работе' },
  { value: 'approved', label: 'Принято' },
  { value: 'rejected', label: 'Отклонено' },
  { value: 'cancelled', label: 'Отменено' },
];
const filteredHistory = computed(() => {
  if (!historyStatusFilter.value) return historyBatches.value;
  return historyBatches.value.filter(b => dominantStatus(b) === historyStatusFilter.value);
});

// Форма
const editingBatchUuid = ref('');
const formItems = reactive([newEmptyRow()]);
const formComment = ref('');

// Поисковое автодополнение товаров
const prodOpen = ref(-1);          // индекс открытой строки
const prodResults = ref([]);
const prodHighlight = ref(0);
const prodSearching = ref(false);
const prodRefs = reactive({});
let prodTimer = null;

function newEmptyRow() {
  return { action: 'add', sku: '-', name: '', label: '', qty: null, unit: 'кор.' };
}

const canSubmit = computed(() => {
  if (!selectedDate.value) return false;
  if (!formItems.length) return false;
  return formItems.every(it =>
    (it.label && it.label.trim()) &&
    typeof it.qty === 'number' &&
    it.qty > 0
  );
});

function selectDate(date) {
  selectedDate.value = date;
  cancelEdit();
  loadBatches();
}

async function loadDeliveries() {
  try {
    const data = await roFetch('/api/restaurant-corrections/deliveries');
    deliveries.value = data.deliveries || [];
    if (deliveries.value.length && !selectedDate.value) {
      selectedDate.value = deliveries.value[0].date;
    }
  } catch (e) {
    toast.error(e.message || 'Не удалось загрузить даты');
    deliveries.value = [];
  }
}

async function loadBatches() {
  if (!selectedDate.value) { batches.value = []; return; }
  try {
    const data = await roFetch('/api/restaurant-corrections/list?date=' + encodeURIComponent(selectedDate.value));
    batches.value = data.batches || [];
  } catch (e) {
    toast.error(e.message || 'Не удалось загрузить корректировки');
    batches.value = [];
  }
}

async function loadHistory() {
  try {
    const data = await roFetch('/api/restaurant-corrections/list');
    historyBatches.value = data.batches || [];
    historyLoaded.value = true;
  } catch (e) {
    toast.error(e.message || 'Не удалось загрузить историю');
    historyBatches.value = [];
  }
}

async function switchMode(mode) {
  viewMode.value = mode;
  if (mode === 'history' && !historyLoaded.value) {
    loading.value = true;
    try { await loadHistory(); } finally { loading.value = false; }
  } else if (mode === 'history') {
    // Подгружаем свежую версию без полноэкранного спиннера
    loadHistory();
  }
}

async function initialLoad() {
  loading.value = true;
  try {
    await loadDeliveries();
    if (selectedDate.value) await loadBatches();
  } finally {
    loading.value = false;
  }
}

// ── Поиск товаров (с дебаунсом) ──
function onProdInput(idx, val) {
  formItems[idx].label = val;
  formItems[idx].sku = '-';   // сброс выбора при ручном вводе
  formItems[idx].name = val;
  prodOpen.value = idx;
  prodHighlight.value = 0;
  if (prodTimer) clearTimeout(prodTimer);
  if (!val || val.trim().length < 2) { prodResults.value = []; return; }
  prodSearching.value = true;
  prodTimer = setTimeout(() => searchProducts(val), 200);
}
function onProdFocus(idx) {
  prodOpen.value = idx;
  if ((formItems[idx].label || '').length >= 2) searchProducts(formItems[idx].label);
}
function onProdBlur() {
  // Закрываем чуть позже, чтобы успел сработать @mousedown.
  setTimeout(() => { prodOpen.value = -1; }, 150);
}
async function searchProducts(q) {
  prodSearching.value = true;
  try {
    const data = await roFetch('/api/restaurant-corrections/products?q=' + encodeURIComponent(q));
    prodResults.value = data.products || [];
  } catch {
    prodResults.value = [];
  } finally {
    prodSearching.value = false;
  }
}
function moveProdSel(idx, dir) {
  if (prodOpen.value !== idx || !prodResults.value.length) return;
  const next = (prodHighlight.value + dir + prodResults.value.length) % prodResults.value.length;
  prodHighlight.value = next;
}
function pickProdHighlighted(idx) {
  if (prodOpen.value !== idx || !prodResults.value.length) return;
  pickProd(idx, prodResults.value[prodHighlight.value]);
}
function pickProd(idx, prod) {
  formItems[idx].sku = prod.sku;
  formItems[idx].name = prod.name;
  formItems[idx].label = prod.sku + ' · ' + prod.name;
  if (prod.default_unit && (prod.default_unit === 'шт.' || prod.default_unit === 'кор.')) {
    formItems[idx].unit = prod.default_unit;
  }
  prodOpen.value = -1;
  prodResults.value = [];
}

// ── Управление строками формы ──
function addRow() {
  formItems.push(newEmptyRow());
}
function removeRow(idx) {
  if (formItems.length === 1) return;
  formItems.splice(idx, 1);
}
function toggleAction(idx) {
  formItems[idx].action = formItems[idx].action === 'add' ? 'remove' : 'add';
}
function toggleUnit(idx) {
  formItems[idx].unit = formItems[idx].unit === 'кор.' ? 'шт.' : 'кор.';
}

// ── Редактирование/отмена ──
function startEdit(batch) {
  editingBatchUuid.value = batch.batch_uuid;
  formComment.value = batch.comment || '';
  formItems.splice(0, formItems.length);
  for (const it of batch.items) {
    formItems.push({
      action: it.action,
      sku: it.sku,
      name: it.name,
      label: it.sku && it.sku !== '-' ? (it.sku + ' · ' + it.name) : it.name,
      qty: it.qty,
      unit: it.unit,
    });
  }
  if (!formItems.length) formItems.push(newEmptyRow());
  // Скроллим к форме
  setTimeout(() => {
    const el = document.querySelector('.rco-form');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }, 50);
}
function cancelEdit() {
  editingBatchUuid.value = '';
  formItems.splice(0, formItems.length, newEmptyRow());
  formComment.value = '';
}
async function askCancel(batch) {
  if (!(await appConfirm('Отозвать корректировку? Это действие нельзя отменить.', { okText: 'Отозвать', danger: true }))) return;
  busy.value = true;
  try {
    await roFetch('/api/restaurant-corrections/cancel', {
      method: 'POST',
      body: { batch_uuid: batch.batch_uuid },
    });
    toast.success('Корректировка отозвана');
    await loadBatches();
    historyLoaded.value = false;
  } catch (e) {
    toast.error(e.message || 'Не удалось отозвать');
  } finally {
    busy.value = false;
  }
}

// ── Отправка ──
async function submit() {
  if (!canSubmit.value || busy.value) return;
  const items = formItems.map(it => ({
    action: it.action,
    sku: it.sku || '-',
    name: (it.sku && it.sku !== '-') ? it.name : (it.label || '').trim(),
    qty: it.qty,
    unit: it.unit,
  }));
  busy.value = true;
  try {
    if (editingBatchUuid.value) {
      await roFetch('/api/restaurant-corrections/update', {
        method: 'POST',
        body: { batch_uuid: editingBatchUuid.value, items, comment: formComment.value.trim() },
      });
      toast.success('Корректировка обновлена');
    } else {
      await roFetch('/api/restaurant-corrections/save', {
        method: 'POST',
        body: { delivery_date: selectedDate.value, items, comment: formComment.value.trim() },
      });
      toast.success('Корректировка отправлена');
    }
    cancelEdit();
    await loadBatches();
    historyLoaded.value = false; // история устарела — перезагрузим при переходе
  } catch (e) {
    toast.error(e.message || 'Не удалось отправить');
  } finally {
    busy.value = false;
  }
}

// ── Хелперы для отображения ──
function fmtQty(v) {
  if (typeof v !== 'number') v = parseFloat(v);
  if (!isFinite(v)) return '0';
  return Number.isInteger(v) ? String(v) : v.toFixed(2).replace(/\.?0+$/, '');
}
function fmtDateTime(iso) {
  if (!iso) return '';
  const d = new Date(iso.replace(' ', 'T'));
  if (isNaN(d)) return iso;
  return d.toLocaleString('ru-RU', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}
function fmtDateShort(iso) {
  if (!iso) return '';
  const d = new Date(iso + (iso.includes('T') ? '' : 'T00:00:00'));
  if (isNaN(d)) return iso;
  const days = ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'];
  return days[d.getDay()] + ' ' + d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
}
const STATUS_ICONS = { pending: '⏳', in_progress: '🛠', approved: '✅', rejected: '❌', cancelled: '⛔' };
const STATUS_LABELS = { pending: 'Ожидает', in_progress: 'В работе', approved: 'Принято', rejected: 'Отклонено', cancelled: 'Отменено' };
function statusIcon(s) { return STATUS_ICONS[s] || '•'; }
function statusLabel(s) { return STATUS_LABELS[s] || s; }
function batchStatusLabel(b) {
  return STATUS_LABELS[dominantStatus(b)] || 'Ожидает';
}
function dominantStatus(b) {
  const items = b.items || [];
  if (!items.length) return 'pending';
  // если все одинаковые — он и есть
  const uniq = new Set(items.map(i => i.status));
  if (uniq.size === 1) return items[0].status;
  // приоритет: in_progress > pending > approved > rejected > cancelled
  for (const s of ['in_progress', 'pending', 'approved', 'rejected', 'cancelled']) {
    if (uniq.has(s)) return s;
  }
  return items[0].status;
}

// Когда пользователь возвращается во вкладку — освежаем данные текущего режима,
// чтобы сразу увидеть свежие статусы после approve/reject от закупок.
function refreshCurrentView() {
  if (busy.value) return;
  if (viewMode.value === 'history') { loadHistory(); return; }
  if (selectedDate.value) loadBatches();
}
function onVisibilityChange() {
  if (document.visibilityState === 'visible') refreshCurrentView();
}
function onWindowFocus() { refreshCurrentView(); }

onMounted(() => {
  initialLoad();
  document.addEventListener('visibilitychange', onVisibilityChange);
  window.addEventListener('focus', onWindowFocus);
  window.addEventListener('keydown', onTutorialEsc);
  try {
    if (!localStorage.getItem(TUTORIAL_KEY)) showTutorial.value = true;
  } catch (e) { /* ignore */ }
});
onBeforeUnmount(() => {
  document.removeEventListener('visibilitychange', onVisibilityChange);
  window.removeEventListener('focus', onWindowFocus);
  window.removeEventListener('keydown', onTutorialEsc);
});
</script>

<style scoped>
.rco { padding: 12px 4px 24px; display: flex; flex-direction: column; gap: 16px; }

/* ── Заголовок страницы ── */
.rco-page-head {
  position: relative;
  padding: 14px 16px 12px;
  background: linear-gradient(180deg, #fff7e8 0%, #fff 90%);
  border: 1px solid #f3d9a8;
  border-radius: 12px;
}
.rco-page-title {
  margin: 0 36px 4px 0;
  font-size: 17px; font-weight: 800; color: #5a3a10; line-height: 1.25;
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}
.rco-page-title-icon { font-size: 20px; line-height: 1; }
.rco-page-sub {
  margin: 0; font-size: 13px; line-height: 1.5; color: #6b5b3a;
}
.rco-help-btn {
  position: absolute; top: 12px; right: 12px;
  display: inline-flex; align-items: center; justify-content: center;
  width: 30px; height: 30px; padding: 0; border: 1px solid #d1b87a;
  background: #fff; color: #5a3a10; border-radius: 50%;
  cursor: pointer; font-weight: 700; font-size: 15px;
  transition: background 0.15s, color 0.15s, border-color 0.15s;
}
.rco-help-btn:hover { background: #c8862a; color: #fff; border-color: #c8862a; }

/* Лид-параграф в туториале */
.rco-tut-lead {
  margin: -4px 0 14px;
  padding: 9px 12px;
  background: #fff8e7;
  border-left: 3px solid #f0b94c;
  border-radius: 6px;
  font-size: 13px; line-height: 1.5; color: #5a3a10;
}
.rco-tut-lead strong { color: #3d260b; }

/* ── Онбординг ── */
.rco-tut-overlay {
  position: fixed; inset: 0; z-index: 9999;
  background: rgba(20, 24, 32, 0.55);
  backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
  display: flex; align-items: center; justify-content: center;
  padding: 16px;
  animation: rco-tut-fade 0.25s ease-out;
}
.rco-tut-box {
  position: relative;
  width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto;
  background: #fff; border-radius: 14px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.3);
  padding: 22px 24px 20px;
  animation: rco-tut-pop 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}
.rco-tut-close {
  position: absolute; top: 10px; right: 12px;
  width: 32px; height: 32px; border: none;
  background: rgba(0,0,0,0.05); color: #555;
  font-size: 22px; line-height: 1; border-radius: 50%;
  cursor: pointer;
  transition: background 0.15s, color 0.15s, transform 0.15s;
}
.rco-tut-close:hover { background: rgba(0,0,0,0.1); color: #111; transform: rotate(90deg); }
.rco-tut-title { margin: 0 26px 14px 0; font-size: 18px; color: #1c1f24; }
.rco-tut-list { margin: 0; padding-left: 22px; display: flex; flex-direction: column; gap: 11px; font-size: 14px; line-height: 1.55; color: #2d3a48; }
.rco-tut-list strong { color: #1c1f24; }
.rco-tut-list em { color: #1976d2; font-style: normal; font-weight: 600; }
.rco-tut-sub { margin: 6px 0 0; padding-left: 4px; list-style: none; display: flex; flex-direction: column; gap: 4px; font-size: 13px; }
.rco-tut-sub li { display: flex; align-items: baseline; gap: 6px; }
.rco-stat-icon { font-size: 14px; width: 18px; text-align: center; }
.rco-tut-chip {
  display: inline-block; padding: 1px 7px; border-radius: 5px;
  font-size: 12px; font-weight: 700; vertical-align: 1px;
}
.rco-tut-chip-add { background: #e7f5e8; color: #2e7d32; }
.rco-tut-chip-rem { background: #fff4e0; color: #b35900; }

.rco-tut-ok {
  margin-top: 18px; width: 100%;
  padding: 11px 16px; border: none; border-radius: 10px;
  background: linear-gradient(180deg, #1976d2, #1565c0);
  color: #fff; font-size: 14px; font-weight: 700; cursor: pointer;
  box-shadow: 0 4px 12px rgba(25, 118, 210, 0.3);
  transition: filter 0.12s, transform 0.12s;
}
.rco-tut-ok:hover { filter: brightness(1.05); }
.rco-tut-ok:active { transform: translateY(1px); }

@keyframes rco-tut-fade { from { opacity: 0; } to { opacity: 1; } }
@keyframes rco-tut-pop {
  0% { opacity: 0; transform: translateY(12px) scale(0.96); }
  100% { opacity: 1; transform: translateY(0) scale(1); }
}

@media (max-width: 520px) {
  .rco-tut-box { padding: 18px 16px 16px; border-radius: 12px; }
  .rco-tut-title { font-size: 16px; }
  .rco-tut-list { font-size: 13px; }
}

/* ── Режимы «Активные / Вся история» ── */
.rco-mode-tabs {
  display: inline-flex; gap: 0; padding: 3px; background: #eef2f6; border-radius: 10px; align-self: flex-start;
}
.rco-mode-tab {
  padding: 6px 14px; border: none; background: transparent; cursor: pointer;
  font-size: 13px; font-weight: 600; color: #5a6b7c; border-radius: 8px;
  transition: background 0.12s, color 0.12s;
}
.rco-mode-tab:hover { color: #2d3a48; }
.rco-mode-tab.is-active { background: #fff; color: #1976d2; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }

/* ── Тулбар истории ── */
.rco-history { display: flex; flex-direction: column; gap: 12px; }
.rco-history-toolbar {
  display: flex; gap: 6px; flex-wrap: wrap; align-items: center;
  background: #fff; border: 1px solid #e8e8e8; border-radius: 10px; padding: 10px 12px;
}
.rco-history-toolbar-label { font-size: 12px; color: #888; margin-right: 4px; }
.rco-history-chip {
  padding: 4px 12px; border-radius: 999px;
  background: #f1f4f8; border: 1px solid transparent; color: #455565;
  font-size: 12px; font-weight: 600; cursor: pointer; transition: background 0.12s;
}
.rco-history-chip:hover { background: #e7ecf2; }
.rco-history-chip.is-active { background: #1976d2; color: #fff; }

.rco-batch-when-strong { font-size: 12px; font-weight: 600; color: #2b2b2b; }

.rco-state { padding: 30px 16px; text-align: center; color: #777; }
.rco-state-empty h3 { margin: 0 0 6px; font-size: 16px; color: #333; }
.rco-state-empty p { margin: 4px 0; }
.rco-state-hint { font-size: 12px; color: #999; }

.rco-section-title { font-size: 14px; font-weight: 700; color: #333; margin: 0 0 10px; letter-spacing: 0.01em; }

/* ── Даты поставки ── */
.rco-deliveries { background: #fff; border: 1px solid #e8e8e8; border-radius: 10px; padding: 12px 14px; }
.rco-d-pills { display: flex; gap: 8px; flex-wrap: wrap; }
.rco-d-pill {
  display: flex; flex-direction: column; align-items: flex-start; gap: 2px;
  padding: 8px 14px; border: 1px solid #d8dee5; border-radius: 10px;
  background: #fff; cursor: pointer; transition: background 0.15s, border-color 0.15s;
  text-align: left;
}
.rco-d-pill:hover { background: #f4f7fb; border-color: #b3c0cf; }
.rco-d-pill.is-active { background: #1976d2; border-color: #1976d2; color: #fff; }
.rco-d-pill.is-active .rco-d-deadline { color: rgba(255,255,255,0.85); }
.rco-d-date { font-size: 13px; font-weight: 700; }
.rco-d-deadline { font-size: 11px; color: #888; }

/* ── Карточка батча ── */
.rco-batches { display: flex; flex-direction: column; gap: 10px; }
.rco-batch { background: #fff; border: 1px solid #e8e8e8; border-radius: 10px; padding: 12px 14px; }
.rco-batch--pending { border-left: 4px solid #ffa000; }
.rco-batch--in_progress { border-left: 4px solid #1976d2; }
.rco-batch--approved { border-left: 4px solid #4caf50; }
.rco-batch--rejected { border-left: 4px solid #c62828; }
.rco-batch--cancelled { border-left: 4px solid #888; opacity: 0.75; }

.rco-batch-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 8px; }
.rco-batch-meta { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; font-size: 12px; color: #888; }
.rco-batch-status-chip { font-size: 11px; padding: 2px 8px; border-radius: 999px; font-weight: 700; }
.rco-batch-status-chip.is-pending { background: #fff4e0; color: #b35900; }
.rco-batch-status-chip.is-in_progress { background: #e3f2fd; color: #1565c0; }
.rco-batch-status-chip.is-approved { background: #e7f5e8; color: #2e7d32; }
.rco-batch-status-chip.is-rejected { background: #fdecea; color: #c62828; }
.rco-batch-status-chip.is-cancelled { background: #eeeeee; color: #666; }
.rco-batch-src { font-size: 11px; color: #aaa; }
.rco-batch-when { font-size: 11px; color: #aaa; }
.rco-batch-actions { display: flex; gap: 6px; }
.rco-batch-comment { margin: 0 0 8px; padding: 6px 10px; background: #fafafa; border-radius: 6px; font-size: 12px; color: #555; line-height: 1.4; }
.rco-batch-items { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 4px; }
.rco-batch-item {
  display: grid;
  grid-template-columns: auto auto 1fr auto;
  gap: 8px;
  align-items: baseline;
  padding: 6px 8px;
  background: #fafafa;
  border-radius: 6px;
  font-size: 13px;
}
.rco-batch-item.is-approved { background: #f1f8e9; }
.rco-batch-item.is-rejected { background: #fdecea; }
.rco-batch-item.is-cancelled { background: #f3f3f3; opacity: 0.7; }
.rco-batch-item-icon { font-size: 14px; }
.rco-batch-item-act { font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 1px 6px; border-radius: 4px; letter-spacing: 0.03em; }
.rco-batch-item-act.add { background: #e7f5e8; color: #2e7d32; }
.rco-batch-item-act.remove { background: #fff4e0; color: #b35900; }
.rco-batch-item-name { color: #2b2b2b; }
.rco-batch-item-sku { font-family: ui-monospace, monospace; color: #888; font-size: 11px; margin-right: 4px; }
.rco-batch-item-qty { font-weight: 600; color: #2b2b2b; white-space: nowrap; }
.rco-batch-item-rc { grid-column: 1 / -1; font-size: 11px; color: #888; font-style: italic; padding-left: 24px; }

/* ── Кнопки ── */
.rco-btn {
  border: 1px solid #d8dee5; background: #fff; padding: 6px 14px;
  border-radius: 6px; font-size: 12px; font-weight: 600; color: #2d3a48;
  cursor: pointer; transition: background 0.12s, border-color 0.12s, color 0.12s;
}
.rco-btn:hover:not(:disabled) { background: #f4f7fb; border-color: #b3c0cf; }
.rco-btn:disabled { opacity: 0.55; cursor: default; }
.rco-btn-ghost { background: transparent; }
.rco-btn-danger { color: #c62828; border-color: #f5c2c2; }
.rco-btn-danger:hover:not(:disabled) { background: #fdecea; }
.rco-btn-primary { background: #1976d2; color: #fff; border-color: #1976d2; }
.rco-btn-primary:hover:not(:disabled) { background: #0d47a1; border-color: #0d47a1; }

/* ── Форма ── */
.rco-form { background: #fff; border: 1px solid #e8e8e8; border-radius: 10px; padding: 14px 16px; }
.rco-form-rows { display: flex; flex-direction: column; gap: 8px; }
.rco-form-row {
  display: grid;
  grid-template-columns: 130px 1fr 100px 70px 28px;
  gap: 8px;
  align-items: stretch;
}

.rco-act-btn {
  display: flex; align-items: center; justify-content: center; gap: 6px;
  padding: 8px 10px;
  border: 1px solid; border-radius: 6px;
  font-size: 12px; font-weight: 700; cursor: pointer;
  transition: filter 0.12s;
}
.rco-act-add { background: #e7f5e8; color: #2e7d32; border-color: #c4e6c8; }
.rco-act-remove { background: #fff4e0; color: #b35900; border-color: #ffe0b2; }
.rco-act-btn:hover { filter: brightness(0.97); }
.rco-act-sign { font-size: 16px; line-height: 1; }
.rco-act-label { font-size: 12px; }

.rco-prod { position: relative; }
.rco-prod-input {
  width: 100%; padding: 8px 10px; border: 1px solid #d8dee5; border-radius: 6px;
  font-size: 13px; outline: none; box-sizing: border-box;
}
.rco-prod-input:focus { border-color: #1976d2; }
.rco-prod-pop {
  position: absolute; left: 0; right: 0; top: calc(100% + 4px);
  background: #fff; border: 1px solid #d8dee5; border-radius: 6px;
  box-shadow: 0 6px 20px rgba(0,0,0,0.08);
  max-height: 240px; overflow-y: auto;
  z-index: 20;
}
.rco-prod-pop-empty { padding: 8px 10px; font-size: 12px; color: #888; font-style: italic; }
.rco-prod-opt {
  display: flex; gap: 8px; align-items: baseline;
  width: 100%; padding: 7px 10px;
  border: none; background: transparent; text-align: left; cursor: pointer;
  font-size: 13px;
}
.rco-prod-opt:hover, .rco-prod-opt.is-hl { background: #f4f7fb; }
.rco-prod-opt-sku { font-family: ui-monospace, monospace; color: #1565c0; font-size: 12px; white-space: nowrap; }
.rco-prod-opt-name { color: #2b2b2b; }

.rco-qty {
  width: 100%; padding: 8px 10px; border: 1px solid #d8dee5; border-radius: 6px;
  font-size: 13px; outline: none; box-sizing: border-box; text-align: right;
}
.rco-qty:focus { border-color: #1976d2; }
.rco-unit-btn {
  padding: 8px 0; border: 1px solid #d8dee5; border-radius: 6px;
  background: #fff; cursor: pointer;
  font-size: 12px; font-weight: 600; color: #455565;
  transition: background 0.12s;
}
.rco-unit-btn:hover { background: #f4f7fb; }

.rco-row-del {
  width: 28px; height: 100%;
  border: 1px solid #f5c2c2; background: #fff; color: #c62828;
  border-radius: 6px; cursor: pointer; font-size: 18px; line-height: 1;
}
.rco-row-del:hover:not(:disabled) { background: #fdecea; }
.rco-row-del:disabled { opacity: 0.4; cursor: default; }

.rco-add-row {
  margin-top: 8px;
  padding: 7px 14px; border: 1px dashed #b3c0cf; background: #fff;
  border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; color: #1976d2;
  transition: background 0.12s, border-color 0.12s;
}
.rco-add-row:hover { background: #f4f7fb; border-style: solid; }

.rco-comment-label { display: flex; flex-direction: column; gap: 4px; margin-top: 12px; font-size: 12px; color: #455565; font-weight: 600; }
.rco-comment-label textarea {
  width: 100%; box-sizing: border-box;
  padding: 8px 10px; border: 1px solid #d8dee5; border-radius: 6px;
  font-family: inherit; font-size: 13px; line-height: 1.45; resize: vertical;
  outline: none;
}
.rco-comment-label textarea:focus { border-color: #1976d2; }

.rco-submit-row { margin-top: 14px; display: flex; justify-content: flex-end; gap: 8px; }

/* ═══════════════ МОБИЛЬНЫЙ АДАПТИВ ═══════════════ */
/* Логика: один общий брейкпоинт ≤720 для перестроения формы и
   карточек в «телефонный» режим, и ≤520 для финального уплотнения. */

@media (max-width: 720px) {
  .rco { padding: 8px 0 20px; gap: 12px; }

  /* Шапка страницы */
  .rco-page-head { padding: 12px 14px 10px; }
  .rco-page-title { font-size: 15px; margin-right: 38px; line-height: 1.3; }
  .rco-page-title-icon { font-size: 17px; }
  .rco-page-sub { font-size: 12.5px; }
  .rco-help-btn { top: 10px; right: 10px; }

  .rco-section-title { font-size: 13px; margin-bottom: 8px; }

  /* Режимы — на ширину контейнера, удобно тапать */
  .rco-mode-tabs { display: flex; width: 100%; align-self: stretch; }
  .rco-mode-tab { flex: 1; padding: 9px 12px; font-size: 13px; min-height: 38px; }

  /* Даты — горизонтальный скролл со snap (не переносим, не «лесенкой») */
  .rco-deliveries { padding: 10px 12px; }
  .rco-d-pills {
    flex-wrap: nowrap; overflow-x: auto;
    margin: 0 -12px; padding: 4px 12px 6px;
    scroll-snap-type: x mandatory;
    -webkit-overflow-scrolling: touch;
  }
  .rco-d-pill {
    flex: 0 0 auto; scroll-snap-align: start;
    min-width: 150px; padding: 9px 14px;
  }
  .rco-d-pills::-webkit-scrollbar { height: 4px; }
  .rco-d-pills::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 2px; }

  /* Карточки батчей */
  .rco-batch { padding: 10px 12px; }
  .rco-batch-head { flex-direction: column; align-items: stretch; gap: 8px; }
  .rco-batch-meta { font-size: 11px; }
  .rco-batch-actions { display: flex; gap: 6px; }
  .rco-batch-actions .rco-btn { flex: 1; padding: 9px 10px; min-height: 38px; font-size: 13px; }

  /* Позиции внутри батча: иконка+действие+название+кол-во в строке,
     комментарий ревьюера переносится в подстроку. */
  .rco-batch-item {
    grid-template-columns: auto auto 1fr auto;
    grid-template-areas:
      'icon act name qty'
      'rc   rc  rc   rc';
    gap: 6px 8px;
    padding: 8px 10px;
  }
  .rco-batch-item-icon { grid-area: icon; }
  .rco-batch-item-act { grid-area: act; }
  .rco-batch-item-name { grid-area: name; word-break: break-word; min-width: 0; }
  .rco-batch-item-qty { grid-area: qty; }
  .rco-batch-item-rc { grid-area: rc; padding-left: 0; }

  /* Форма — каждая позиция как мини-карточка из трёх рядов:
       [+/− Добавить/Убрать]   [×]
       [           товар           ]
       [    кол-во    ] [ единица ]
     Логика выбирает удобство и помещается на 320–440px без перекосов. */
  .rco-form { padding: 12px; }
  .rco-form-rows { gap: 10px; }
  .rco-form-row {
    grid-template-columns: 1fr 80px;
    grid-template-areas:
      'act del'
      'prod prod'
      'qty unit';
    gap: 6px 8px;
    padding: 10px;
    background: #fafbfc;
    border: 1px solid #eef0f3;
    border-radius: 10px;
    align-items: stretch;
  }
  .rco-form-row > :nth-child(1) { grid-area: act; justify-self: start; min-width: 140px; }
  .rco-form-row > :nth-child(2) { grid-area: prod; }
  .rco-form-row > :nth-child(3) { grid-area: qty; }
  .rco-form-row > :nth-child(4) { grid-area: unit; }
  .rco-form-row > :nth-child(5) { grid-area: del; justify-self: end; }

  .rco-qty { padding: 11px 12px; font-size: 15px; min-height: 42px; text-align: center; }
  .rco-unit-btn { padding: 11px 6px; min-height: 42px; width: 80px; font-size: 13px; }
  .rco-act-btn { padding: 9px 14px; min-height: 38px; }
  .rco-act-label { font-size: 12px; }
  .rco-act-sign { font-size: 15px; }
  .rco-prod-input { padding: 11px 12px; font-size: 15px; min-height: 42px; }
  .rco-row-del {
    width: 40px; min-height: 38px; height: 38px;
    font-size: 22px; align-self: start;
  }
  .rco-prod-pop { font-size: 14px; }
  .rco-prod-opt { padding: 9px 12px; font-size: 14px; min-height: 40px; }

  /* «+ Ещё позиция» */
  .rco-add-row {
    width: 100%; padding: 10px 14px; min-height: 42px; font-size: 13px;
    text-align: center;
  }

  /* Комментарий */
  .rco-comment-label { font-size: 12px; }
  .rco-comment-label textarea { padding: 10px 12px; font-size: 14px; min-height: 64px; }

  /* Отправка */
  .rco-submit-row { flex-direction: column-reverse; gap: 8px; margin-top: 12px; }
  .rco-submit-row .rco-btn { width: 100%; padding: 13px 14px; font-size: 14px; min-height: 46px; }

  /* Тулбар истории */
  .rco-history-toolbar { padding: 8px 10px; }
  .rco-history-toolbar-label { width: 100%; }
  .rco-history-chip { padding: 7px 12px; font-size: 12px; min-height: 32px; }
}

/* Очень узкие телефоны (≤400px) — ещё аккуратнее */
@media (max-width: 400px) {
  .rco-page-title { font-size: 14.5px; }
  .rco-page-sub { font-size: 12px; }
  .rco-form-row { padding: 8px; }
  .rco-form-row > :nth-child(1) { min-width: 120px; }
  .rco-act-btn { padding: 9px 10px; }
  .rco-act-label { font-size: 11.5px; }
  .rco-unit-btn { min-width: 70px; padding: 11px 4px; }
}
</style>
