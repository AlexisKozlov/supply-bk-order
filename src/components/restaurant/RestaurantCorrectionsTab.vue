<template>
  <div class="rco">
    <div v-if="loading" class="rco-state">
      <BurgerSpinner text="Загрузка корректировок..." />
    </div>

    <div v-else-if="!deliveries.length && !batches.length" class="rco-state rco-state-empty">
      <h3>Корректировок не подать</h3>
      <p>Сейчас нет ближайших поставок, по которым дедлайн ещё впереди.</p>
      <p class="rco-state-hint">Корректировка возможна до 11:30 рабочего дня перед поставкой.</p>
    </div>

    <template v-else>
      <!-- ── Выбор даты поставки ── -->
      <section class="rco-deliveries">
        <h3 class="rco-section-title">Корректировка на доставку</h3>
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
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, defineAsyncComponent } from 'vue';
import { useToastStore } from '@/stores/toastStore.js';
import { roFetch } from '@/lib/roUtils.js';

const BurgerSpinner = defineAsyncComponent(() => import('@/components/ui/BurgerSpinner.vue'));
const toast = useToastStore();

const loading = ref(true);
const busy = ref(false);

const deliveries = ref([]);
const selectedDate = ref('');
const batches = ref([]);

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
  if (!confirm('Отозвать корректировку? Это действие нельзя отменить.')) return;
  busy.value = true;
  try {
    await roFetch('/api/restaurant-corrections/cancel', {
      method: 'POST',
      body: { batch_uuid: batch.batch_uuid },
    });
    toast.success('Корректировка отозвана');
    await loadBatches();
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

// Когда пользователь возвращается во вкладку — освежаем список батчей,
// чтобы сразу увидеть свежие статусы после approve/reject от закупок.
function onVisibilityChange() {
  if (document.visibilityState === 'visible' && selectedDate.value && !busy.value) {
    loadBatches();
  }
}
function onWindowFocus() {
  if (selectedDate.value && !busy.value) loadBatches();
}

onMounted(() => {
  initialLoad();
  document.addEventListener('visibilitychange', onVisibilityChange);
  window.addEventListener('focus', onWindowFocus);
});
onBeforeUnmount(() => {
  document.removeEventListener('visibilitychange', onVisibilityChange);
  window.removeEventListener('focus', onWindowFocus);
});
</script>

<style scoped>
.rco { padding: 12px 4px 24px; display: flex; flex-direction: column; gap: 16px; }
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
@media (max-width: 720px) {
  .rco-form-row {
    grid-template-columns: 1fr 1fr 28px;
    grid-template-areas:
      'act act del'
      'prod prod prod'
      'qty unit unit';
  }
  .rco-form-row > :nth-child(1) { grid-area: act; }
  .rco-form-row > :nth-child(2) { grid-area: prod; }
  .rco-form-row > :nth-child(3) { grid-area: qty; }
  .rco-form-row > :nth-child(4) { grid-area: unit; }
  .rco-form-row > :nth-child(5) { grid-area: del; }
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
</style>
