<template>
  <section class="cab-section whs-section">
    <div class="whs-panel">
      <div class="whs-head">
        <div>
          <h2>Остатки склада</h2>
          <p>{{ customer || 'Ваше юрлицо' }}<template v-if="uploadedAt"> · обновлено {{ fmtDateTime(uploadedAt) }}</template></p>
        </div>
        <button class="btn btn-outline" :disabled="loading || !filteredItems.length" @click="exportToExcel">Excel</button>
      </div>

      <div class="whs-controls">
        <div class="whs-search">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
          <input v-model="search" type="search" placeholder="Артикул, товар, GTIN или группа аналогов" />
        </div>
      </div>

      <div class="whs-tabs" aria-label="Режимы хранения">
        <button v-for="tab in storageTabs" :key="tab.key"
          class="whs-tab" :class="{ active: storageFilter === tab.key }"
          @click="storageFilter = tab.key">
          {{ tab.label }} <span>{{ tab.count }}</span>
        </button>
      </div>

      <div v-if="loading" class="cab-empty-card"><BurgerSpinner text="Загрузка..." /></div>
      <div v-else-if="error" class="cab-empty-card">
        <h2>Не удалось загрузить остатки</h2>
        <p>{{ error }}</p>
        <button class="btn btn-primary" @click="$emit('reload')">Повторить</button>
      </div>
      <div v-else-if="!items.length" class="cab-empty-card">
        <h2>Нет данных</h2>
        <p>В модуле «Сроки годности» пока нет остатков для вашего юрлица.</p>
      </div>
      <div v-else-if="!filteredItems.length" class="cab-empty-card">
        <h2>Ничего не найдено</h2>
        <p>Измените поиск или фильтр режима хранения.</p>
      </div>
      <div v-else class="whs-list">
        <div class="whs-list-head">
          <span>Номенклатура</span><span>Остаток</span><span>Срок годности</span>
        </div>
        <div v-for="item in filteredItems" :key="item.key" class="whs-row" :class="{ soon: item.days_left >= 0 && item.days_left <= 7 }">
          <div class="whs-row-main">
            <div class="whs-name">
              <button class="whs-copy whs-sku" type="button" title="Скопировать артикул и товар" @click="copyTitle(item)">
                {{ item.sku || item.external_code || '—' }}
              </button>
              <button class="whs-copy whs-title" type="button" title="Скопировать артикул и товар" @click="copyTitle(item)">
                {{ item.name }}
              </button>
            </div>
            <div class="whs-meta">
              <span>{{ item.storage_label }}</span>
              <span v-if="item.analog_group">Группа: {{ item.analog_group }}</span>
              <span v-if="item.gtin">GTIN: {{ item.gtin }}</span>
              <span v-if="copiedKey === item.key" class="whs-copied">Скопировано</span>
            </div>
          </div>
          <div class="whs-qty"><strong>{{ formatQty(item.quantity) }}</strong></div>
          <div class="whs-exp">
            <span :class="expiryClass(item)">{{ expiryText(item) }}</span>
            <button v-if="item.batches?.length > 1" class="whs-batches-btn" @click="toggleItem(item.key)">
              {{ openItems[item.key] ? 'Скрыть' : `Партии ${item.batches.length}` }}
            </button>
          </div>
          <div v-if="openItems[item.key]" class="whs-batches">
            <div class="whs-batch whs-batch-head">
              <span>Номенклатура</span><span>Склад</span><span>Остаток</span><span>Срок годности</span><span>Статус</span>
            </div>
            <div v-for="(b, idx) in item.batches" :key="idx" class="whs-batch">
              <span class="whs-batch-name">{{ nomenclature(item) }}</span>
              <span>{{ b.warehouse || item.storage_label }}</span>
              <b>{{ formatQty(b.quantity) }}</b>
              <span>{{ formatDate(b.expiry_date) || '—' }}</span>
              <span>{{ b.expiry_status || '—' }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { ref, reactive, computed } from 'vue';
import { formatDateTime as fmtDateTime } from '@/lib/roUtils.js';

const props = defineProps({
  items: { type: Array, default: () => [] },
  customer: { type: String, default: '' },
  uploadedAt: { type: String, default: '' },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' },
});
defineEmits(['reload']);

const search = ref('');
const storageFilter = ref('all');
const openItems = reactive({});
const copiedKey = ref('');

const baseItems = computed(() => props.items.filter(item => !(Number(item.days_left) < 0)));

const storageTabs = computed(() => {
  const counts = new Map();
  for (const item of baseItems.value) {
    const key = item.storage_key || 'other';
    if (!counts.has(key)) counts.set(key, { key, label: item.storage_label || 'Без режима', count: 0 });
    counts.get(key).count++;
  }
  const preferred = ['dry', 'cold', 'frozen', 'mixed', 'other'];
  const tabs = [...counts.values()].sort((a, b) => {
    const ia = preferred.indexOf(a.key);
    const ib = preferred.indexOf(b.key);
    return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib) || a.label.localeCompare(b.label, 'ru');
  });
  return [{ key: 'all', label: 'Все', count: baseItems.value.length }, ...tabs];
});

const filteredItems = computed(() => {
  const q = search.value.trim().toLowerCase();
  return baseItems.value.filter(item => {
    if (storageFilter.value !== 'all' && item.storage_key !== storageFilter.value) return false;
    if (!q) return true;
    return [item.sku, item.external_code, item.gtin, item.name, item.raw_name, item.analog_group, item.category]
      .some(v => String(v || '').toLowerCase().includes(q));
  });
});

function toggleItem(key) { openItems[key] = !openItems[key]; }
function formatQty(value) {
  const n = Number(value || 0);
  return n.toLocaleString('ru-RU', { maximumFractionDigits: 2 });
}
function nomenclature(item) {
  const code = item?.sku || item?.external_code || '';
  return [code, item?.name || item?.raw_name || ''].filter(Boolean).join(' ');
}
function formatDate(value) {
  if (!value) return '';
  const m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!m) return value;
  return `${m[3]}.${m[2]}.${m[1]}`;
}
function expiryText(item) {
  if (!item?.nearest_expiry) return 'Срок не указан';
  const days = Number(item.days_left);
  const date = formatDate(item.nearest_expiry);
  if (Number.isNaN(days)) return date;
  if (days === 0) return `${date} · сегодня`;
  return `${date} · ${days} дн.`;
}
function expiryClass(item) {
  const days = Number(item?.days_left);
  if (Number.isNaN(days)) return '';
  if (days < 0) return 'bad';
  if (days <= 7) return 'warn';
  return 'ok';
}
async function copyTitle(item) {
  const text = nomenclature(item);
  if (!text) return;
  try {
    if (navigator?.clipboard?.writeText) {
      await navigator.clipboard.writeText(text);
    } else {
      const el = document.createElement('textarea');
      el.value = text;
      el.setAttribute('readonly', '');
      el.style.position = 'fixed';
      el.style.left = '-9999px';
      document.body.appendChild(el);
      el.select();
      document.execCommand('copy');
      document.body.removeChild(el);
    }
    copiedKey.value = item.key;
    setTimeout(() => {
      if (copiedKey.value === item.key) copiedKey.value = '';
    }, 1400);
  } catch {
    copiedKey.value = '';
  }
}
async function exportToExcel() {
  const mod = await import('xlsx-js-style');
  const XLSX = mod.default || mod;
  const headers = ['Номенклатура', 'Склад', 'Остаток партии', 'Срок годности', 'Статус', 'Режим хранения', 'Группа аналогов', 'GTIN', 'Внешний код'];
  const rows = [];
  for (const item of filteredItems.value) {
    const batches = Array.isArray(item.batches) && item.batches.length ? item.batches : [{
      warehouse: item.storage_label || '',
      quantity: item.quantity || 0,
      expiry_date: item.nearest_expiry || '',
      expiry_status: item.nearest_status || '',
    }];
    for (const batch of batches) {
      rows.push([
        nomenclature(item),
        batch.warehouse || item.storage_label || '',
        Number(batch.quantity || 0),
        batch.expiry_date ? formatDate(batch.expiry_date) : '',
        batch.expiry_status || '',
        item.storage_label || '',
        item.analog_group || '',
        item.gtin || '',
        item.external_code || '',
      ]);
    }
  }
  const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
  ws['!cols'] = [{ wch: 58 }, { wch: 24 }, { wch: 12 }, { wch: 16 }, { wch: 18 }, { wch: 18 }, { wch: 24 }, { wch: 18 }, { wch: 16 }];
  ws['!autofilter'] = { ref: XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: rows.length, c: headers.length - 1 } }) };
  const border = {
    top: { style: 'thin', color: { rgb: 'E7DED4' } },
    bottom: { style: 'thin', color: { rgb: 'E7DED4' } },
    left: { style: 'thin', color: { rgb: 'E7DED4' } },
    right: { style: 'thin', color: { rgb: 'E7DED4' } },
  };
  for (let c = 0; c < headers.length; c++) {
    const cell = ws[XLSX.utils.encode_cell({ r: 0, c })];
    cell.s = {
      font: { bold: true, color: { rgb: 'FFFFFF' } },
      fill: { fgColor: { rgb: '502314' } },
      alignment: { vertical: 'center', horizontal: 'center', wrapText: true },
      border,
    };
  }
  for (let r = 1; r <= rows.length; r++) {
    for (let c = 0; c < headers.length; c++) {
      const addr = XLSX.utils.encode_cell({ r, c });
      if (!ws[addr]) continue;
      ws[addr].s = {
        fill: { fgColor: { rgb: r % 2 ? 'FFF8EF' : 'FFFFFF' } },
        alignment: { vertical: 'top', horizontal: c === 2 ? 'right' : 'left', wrapText: true },
        border,
      };
      if (c === 2) ws[addr].z = '#,##0.00';
    }
  }
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Остатки склада');
  XLSX.writeFile(wb, `Остатки склада ${props.customer || ''}.xlsx`);
}
</script>

<style scoped>
.whs-section { max-width: 1180px; margin-left: auto; margin-right: auto; }
.whs-panel { background: #fff; border: 1px solid #EDE8E3; border-radius: 18px; padding: 18px; }
.whs-head { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; margin-bottom: 14px; }
.whs-head h2 { margin: 0 0 4px; color: #502314; font-size: 20px; }
.whs-head p { margin: 0; color: #8b7355; font-size: 13px; }
.whs-controls { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; }
.whs-search { flex: 1; min-width: 260px; display: flex; align-items: center; gap: 8px; background: #FAFAF8; border: 1px solid #EDE8E3; border-radius: 10px; padding: 0 12px; min-height: 44px; transition: border-color .16s ease, box-shadow .16s ease, background .16s ease; }
.whs-search:focus-within { background: #fff; border-color: #502314; box-shadow: 0 0 0 3px rgba(80, 35, 20, .12); }
.whs-search svg { width: 18px; height: 18px; stroke: #8b7355; }
.whs-search input { appearance: none; -webkit-appearance: none; border: 0; outline: 0; box-shadow: none; border-radius: 0; background: transparent; width: 100%; font: inherit; color: #2b1a0e; min-height: 42px; }
.whs-search input:focus { outline: 0; box-shadow: none; }
.whs-tabs { display: flex; gap: 6px; overflow-x: auto; padding-bottom: 10px; margin-bottom: 8px; -webkit-overflow-scrolling: touch; }
.whs-tab { border: 1px solid #EDE8E3; background: #FAFAF8; color: #5f4b38; min-height: 40px; padding: 8px 12px; border-radius: 10px; font-weight: 700; font-size: 12px; cursor: pointer; white-space: nowrap; display: flex; align-items: center; gap: 8px; }
.whs-tab span { color: #9b8064; font-weight: 700; }
.whs-tab.active { background: #502314; border-color: #502314; color: #fff; }
.whs-tab.active span { color: rgba(255,255,255,0.75); }
.whs-list { display: flex; flex-direction: column; gap: 8px; }
.whs-list-head { display: grid; grid-template-columns: minmax(0, 1fr) 120px 180px; gap: 12px; align-items: center; padding: 0 12px 2px; color: #8b7355; font-size: 11px; font-weight: 800; text-transform: uppercase; }
.whs-list-head span:nth-child(2), .whs-list-head span:nth-child(3) { text-align: right; }
.whs-row { display: grid; grid-template-columns: minmax(0, 1fr) 120px 180px; gap: 12px; align-items: center; border: 1px solid #F0E8DD; border-radius: 12px; padding: 12px; background: #fff; }
.whs-row.soon { border-color: #F4A261; background: #FFF8EF; }
.whs-row-main { min-width: 0; }
.whs-name { display: flex; gap: 8px; align-items: baseline; color: #2b1a0e; font-weight: 700; line-height: 1.35; }
.whs-copy { border: 0; background: transparent; padding: 0; margin: 0; font: inherit; color: inherit; text-align: left; cursor: pointer; }
.whs-copy:hover { color: #E76F51; text-decoration: none; }
.whs-copy:focus-visible { outline: 2px solid rgba(80, 35, 20, .35); outline-offset: 2px; border-radius: 4px; }
.whs-sku { color: #E76F51; font-size: 12px; font-weight: 800; white-space: nowrap; }
.whs-title { min-width: 0; font-weight: 700; }
.whs-meta { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 5px; color: #8b7355; font-size: 12px; }
.whs-copied { color: #2e7d32; font-weight: 700; }
.whs-qty { display: flex; flex-direction: column; align-items: flex-end; gap: 2px; text-align: right; color: #2b1a0e; font-variant-numeric: tabular-nums; }
.whs-qty strong { font-size: 16px; font-weight: 700; line-height: 1.2; }
.whs-exp { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; font-size: 12px; font-weight: 700; text-align: right; }
.whs-exp .ok { color: #2e7d32; }
.whs-exp .warn { color: #ef6c00; }
.whs-exp .bad { color: #c0392b; }
.whs-batches-btn { border: 0; background: transparent; color: #E76F51; padding: 2px 0; cursor: pointer; font: inherit; font-size: 12px; font-weight: 700; }
.whs-batches { grid-column: 1 / -1; border-top: 1px solid #F0E8DD; padding-top: 10px; display: grid; gap: 0; }
.whs-batch { display: grid; grid-template-columns: minmax(260px, 1fr) minmax(150px, 220px) 92px 118px minmax(100px, 140px); gap: 12px; align-items: center; color: #5f4b38; font-size: 12px; padding: 7px 8px; border-bottom: 1px solid #F6EFE7; }
.whs-batch:last-child { border-bottom: 0; }
.whs-batch-head { color: #8b7355; font-weight: 800; background: #FAFAF8; border-radius: 8px; border-bottom: 0; margin-bottom: 2px; }
.whs-batch-name { color: #2b1a0e; font-weight: 700; }
.whs-batch-head span:nth-child(n+3) { text-align: right; }
.whs-batch b { color: #2b1a0e; text-align: right; font-variant-numeric: tabular-nums; }
.whs-batch span:nth-child(n+3) { text-align: right; font-variant-numeric: tabular-nums; }
.cab-empty-card { background: #fff; border-radius: 14px; border: 1px solid #EDE8E3; padding: 24px; text-align: center; }
.cab-empty-card h2 { color: #502314; margin: 0 0 8px; font-size: 18px; }
.cab-empty-card p { color: #8b7355; font-size: 14px; margin: 0 0 12px; }
.btn { padding: 10px 18px; border-radius: 10px; border: none; font-family: inherit; font-size: 14px; font-weight: 600; cursor: pointer; }
.btn-outline { background: #fff; border: 1.5px solid #d5c8bc; color: #502314; }
.btn-outline:hover:not(:disabled) { background: #FAF8F5; border-color: #502314; }
.btn-primary { background: #E76F51; color: #fff; }
.btn-primary:hover { background: #d65f43; }
.btn:disabled { opacity: 0.6; cursor: default; }

@media (max-width: 600px) {
  .whs-section { padding-left: 0; padding-right: 0; }
  .whs-panel { padding: 12px 10px; border-radius: 14px; }

  /* Шапка: заголовок и кнопка Excel в столбик */
  .whs-head { flex-direction: column; align-items: stretch; gap: 8px; margin-bottom: 10px; }
  .whs-head h2 { font-size: 17px; }
  .whs-head .btn { align-self: flex-end; padding: 7px 14px; font-size: 13px; }

  /* Заголовок столбцов на мобильном не нужен — всё подписано в карточке */
  .whs-list-head { display: none; }

  /* Карточка остатка: 3 строки — артикул+название, метаданные, число+срок */
  .whs-row {
    grid-template-columns: 1fr 1fr;
    gap: 4px 8px;
    padding: 10px 12px;
  }
  .whs-row-main { grid-column: 1 / -1; }
  .whs-name { flex-direction: column; gap: 1px; align-items: flex-start; }
  .whs-sku { font-size: 11px; }
  .whs-title { font-size: 14px; line-height: 1.3; }

  /* Метаданные — одна компактная строка с обрезкой */
  .whs-meta {
    font-size: 11px;
    gap: 6px;
    margin-top: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex-wrap: nowrap;
  }
  .whs-meta > span { flex-shrink: 0; }

  /* Остаток слева, срок+партии справа — на одной строке */
  .whs-row > .whs-qty {
    grid-column: 1;
    flex-direction: row;
    align-items: baseline;
    justify-content: flex-start;
    gap: 4px;
    padding-top: 6px;
    border-top: 1px solid #F6EFE7;
  }
  .whs-row > .whs-qty strong { font-size: 16px; }

  .whs-row > .whs-exp {
    grid-column: 2;
    flex-direction: row;
    align-items: baseline;
    justify-content: flex-end;
    gap: 6px;
    padding-top: 6px;
    border-top: 1px solid #F6EFE7;
    font-size: 13px;
  }
  .whs-batches-btn { font-size: 12px; padding: 0 4px; }
  .whs-row > .whs-batches { grid-column: 1 / -1; }

  /* Партии — стек с подписями вместо таблицы */
  .whs-batch-head { display: none; }
  .whs-batch {
    grid-template-columns: 1fr;
    gap: 3px;
    padding: 8px 10px;
    background: #FAFAF8;
    border: 1px solid #F0E8DD;
    border-radius: 8px;
    margin-bottom: 6px;
  }
  .whs-batch:last-child { margin-bottom: 0; }
  .whs-batch-name { font-size: 13px; margin-bottom: 2px; }
  .whs-batch > span:not(.whs-batch-name)::before,
  .whs-batch > b::before {
    color: #8b7355;
    font-size: 11px;
    font-weight: 600;
    margin-right: 5px;
  }
  .whs-batch > span:not(.whs-batch-name),
  .whs-batch > b {
    text-align: left;
    font-size: 12px;
  }
  .whs-batch > span:nth-of-type(2)::before { content: 'Склад:'; }
  .whs-batch > b::before { content: 'Остаток:'; }
  .whs-batch > span:nth-of-type(3)::before { content: 'Срок:'; }
  .whs-batch > span:nth-of-type(4)::before { content: 'Статус:'; }
}
</style>
