<template>
  <div class="op rom-page">
    <div class="rom-toolbar">
      <div>
        <h1>Собственное производство</h1>
        <p class="op-sub">
          Цех «Пицца Стар»: что изготовить, сколько лотков и стопок, куда везём.
          Рестораны заказывают тесто в своём кабинете.
        </p>
      </div>
    </div>

    <div v-if="loading" class="op-card op-empty">Загрузка…</div>

    <div v-else-if="!workshops.length" class="op-card op-empty">
      <div class="op-empty-title">Цех не найден</div>
      <p>Проверьте, что поставщик цеха активен и подключён к заявкам «Пицца Стар».</p>
    </div>

    <template v-else>
      <!-- Цех: обычно один, но список оставлен на будущее -->
      <div v-if="workshops.length > 1" class="op-shops">
        <button v-for="w in workshops" :key="w.id" class="op-shop"
                :class="{ active: shopId === w.id }" @click="pickShop(w.id)">
          {{ w.short_name }}
        </button>
      </div>

      <!-- Навигация: сверху ежедневная работа, ниже — то, что настраивают редко -->
      <nav class="op-nav">
        <div class="op-seg">
          <button v-for="t in WORK_TABS" :key="t.key" class="op-seg-btn"
                  :class="{ active: tab === t.key }" @click="switchTab(t.key)">
            {{ t.label }}
          </button>
        </div>
        <div class="op-seg op-seg-setup">
          <span class="op-seg-cap">Настройка</span>
          <button v-for="t in SETUP_TABS" :key="t.key" class="op-seg-btn"
                  :class="{ active: tab === t.key }" @click="switchTab(t.key)">
            {{ t.label }}
          </button>
        </div>
      </nav>

      <!-- ═══ Заказ на день ═══ -->
      <template v-if="tab === 'day'">
        <div class="op-panel">
          <label class="op-panel-label">Дата поставки</label>
          <!-- Стрелки держим вплотную к полю: на узком экране они иначе
               разъезжаются по разным строкам и теряют смысл. -->
          <span class="op-datebox">
            <button class="op-nav-day" title="Предыдущий день" @click="shiftDate(-1)">‹</button>
            <input type="date" v-model="date" class="rom-input-sm" @change="loadDay" />
            <button class="op-nav-day" title="Следующий день" @click="shiftDate(1)">›</button>
            <span class="op-date-dow">{{ dowFull(date) }}</span>
          </span>
          <button class="op-btn" :disabled="loadingDay" @click="loadDay">
            {{ loadingDay ? 'Загрузка…' : 'Обновить' }}
          </button>
          <div class="op-panel-right">
            <button class="op-btn" :disabled="weekBusy" @click="downloadWeek"
                    :title="'Заказ на неделю ' + weekLabel + ': два листа по три дня'">
              {{ weekBusy ? 'Собираю…' : 'Заказ на неделю (Excel)' }}
            </button>
            <button class="op-btn op-btn-accent" :disabled="!hasDay || sheetsBusy" @click="downloadSheets">
              {{ sheetsBusy ? 'Готовлю…' : 'Загрузочные листы (Excel)' }}
            </button>
          </div>
        </div>

        <div v-if="loadingDay" class="op-card op-empty">Загрузка…</div>
        <div v-else-if="!hasDay" class="op-card op-empty">
          <div class="op-empty-title">На {{ fmtDateFull(date) }} заявок нет</div>
          <p>Показываются только поданные заявки ресторанов. Выберите другую дату поставки.</p>
        </div>
        <template v-else>
          <div class="op-tiles">
            <div class="op-tile">
              <span class="op-tile-num">{{ day.restaurants.length }}</span>
              <span>{{ plural(day.restaurants.length, ['точка', 'точки', 'точек']) }}</span>
            </div>
            <div class="op-tile">
              <span class="op-tile-num">{{ fmt(day.totals.pieces) }}</span>
              <span>{{ plural(day.totals.pieces, ['штука', 'штуки', 'штук']) }} теста</span>
            </div>
            <div class="op-tile">
              <span class="op-tile-num">{{ day.totals.trays }}</span>
              <span>{{ plural(day.totals.trays, ['лоток', 'лотка', 'лотков']) }}</span>
            </div>
            <div class="op-tile">
              <span class="op-tile-num">{{ day.totals.stacks }}</span>
              <span>{{ plural(day.totals.stacks, ['стопка', 'стопки', 'стопок']) }}</span>
            </div>
            <div class="op-tile op-tile-accent">
              <span class="op-tile-num">{{ fmt(day.totals.pallets) }}</span>
              <span>{{ plural(day.totals.pallets, ['паллета', 'паллеты', 'паллет']) }}</span>
            </div>
          </div>

          <div class="op-card op-card-flush">
            <table class="rom-table op-table">
              <thead>
                <tr>
                  <th>Точка</th>
                  <th>Адрес</th>
                  <th v-for="s in day.sizes" :key="s.sku" class="op-num">
                    {{ s.short_name }}<br><span class="op-th-hint">по {{ s.per_tray }} шт</span>
                  </th>
                  <th class="op-num">Лотков</th>
                  <th class="op-num">Стопок</th>
                  <th class="op-num">Паллет</th>
                  <th class="op-print-col">Лист</th>
                </tr>
              </thead>
              <tbody>
                <!-- Ресторан с двумя партиями занимает две строки: цех печёт их
                     в разные дни, поэтому сумма по точке ему бесполезна.
                     Лотки, стопки и паллеты общие — везут одной поставкой. -->
                <template v-for="r in day.restaurants" :key="r.restaurant_number">
                  <tr v-for="(b, i) in restBatches(r)" :key="r.restaurant_number + '-' + b.no"
                      :class="{ 'op-batch-next': i > 0 }">
                    <td class="op-rest">
                      <template v-if="i === 0">
                        <span class="op-rest-title">{{ r.title }}</span>
                        <span class="op-rest-num">{{ restLabel(r.restaurant_number) }}</span>
                      </template>
                      <span v-if="b.no" class="op-batch-tag">{{ b.no }}-я партия</span>
                    </td>
                    <td class="op-addr">{{ i === 0 ? r.address : '' }}</td>
                    <td v-for="s in day.sizes" :key="s.sku" class="op-num">
                      <template v-if="b.qty[s.sku]">
                        <b>{{ fmt(b.qty[s.sku]) }}</b>
                        <span class="op-trays">{{ b.trays[s.sku] }} лотк.</span>
                      </template>
                      <span v-else class="op-dim">—</span>
                    </td>
                    <template v-if="i === 0">
                      <td class="op-num" :rowspan="restBatches(r).length"><b>{{ r.total_trays }}</b></td>
                      <td class="op-num" :rowspan="restBatches(r).length">{{ r.stacks }}</td>
                      <td class="op-num" :rowspan="restBatches(r).length">{{ fmt(r.pallets) }}</td>
                      <td class="op-print-col" :rowspan="restBatches(r).length">
                        <button class="op-btn op-btn-sm" :disabled="printing === r.restaurant_number"
                                @click="printSheets(r.restaurant_number)">
                          {{ printing === r.restaurant_number ? '…' : 'Печать' }}
                        </button>
                      </td>
                    </template>
                  </tr>
                </template>
                <tr class="op-total-row">
                  <td>ИТОГО</td>
                  <td></td>
                  <td v-for="s in day.sizes" :key="s.sku" class="op-num">
                    <template v-if="day.totals.by_sku[s.sku]?.pieces">
                      <b>{{ fmt(day.totals.by_sku[s.sku].pieces) }}</b>
                      <span class="op-trays">{{ day.totals.by_sku[s.sku].trays }} лотк.</span>
                    </template>
                    <span v-else class="op-dim">—</span>
                  </td>
                  <td class="op-num"><b>{{ day.totals.trays }}</b></td>
                  <td class="op-num"><b>{{ day.totals.stacks }}</b></td>
                  <td class="op-num"><b>{{ fmt(day.totals.pallets) }}</b></td>
                  <td class="op-print-col"></td>
                </tr>
              </tbody>
            </table>
            <p class="op-note">
              «Печать» — загрузочные листы одной точки, по листу на стопку.
              Кнопка сверху собирает книгу Excel сразу по всем точкам дня.
            </p>
          </div>
        </template>
      </template>

      <!-- ═══ Экраны заявок поставщику: приём, архив, графики, товары, параметры ═══ -->
      <!-- Механика уже написана в «Заявках поставщикам» — модуль показывает
           нужный экран без своей копии и без второй полосы вкладок. -->
      <template v-else-if="managerTab">
        <p v-if="tabHint" class="op-tabhint">{{ tabHint }}</p>
        <SupplierOrdersManagerView :key="managerTab"
          :supplier-id="shopId" :tabs="[managerTab]" :legal-entity="shopEntity"
          hide-tabs query-prefix="so" />
      </template>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, defineAsyncComponent } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { db } from '@/lib/apiClient.js';
import { useToastStore } from '@/stores/toastStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { formatRestaurantNumber } from '@/lib/legalEntities.js';

const toast = useToastStore();
const route = useRoute();
const router = useRouter();
const userStore = useUserStore();
const orderStore = useOrderStore();

// Модуль работает только по «Пицца Стар». Ссылку на раздел могут открыть с
// другим юрлицом в сайдбаре — тогда встроенные экраны (товары цеха, почта
// поставщика) искали бы данные не в той группе. Переключаем сами, до того
// как отрисуются дочерние экраны.
const PS_ENTITY = 'ООО "Пицца Стар"';
{
  const allowed = userStore.getAllowedEntities?.() || [];
  if (allowed.includes(PS_ENTITY) && orderStore.settings.legalEntity !== PS_ENTITY) {
    orderStore.settings.legalEntity = PS_ENTITY;
  }
}

// Разделы модуля. Ежедневная работа — сверху, редкие настройки — отдельной
// строкой: раньше «Настройки» лежали внутри «Настроек» и путали.
// managerTab — какой экран «Заявок поставщикам» показать вместо своей вёрстки.
const WORK_TABS = [
  { key: 'intake', label: 'Приём заявок', managerTab: 'status',
    hint: 'Кто из ресторанов подал заявку на выбранный день. Здесь же правят количества, продлевают дедлайн и отправляют сводку цеху.' },
  { key: 'day', label: 'Заказ на день' },
  { key: 'archive', label: 'Все заявки', managerTab: 'list',
    hint: 'Поданные заявки на тесто за любой период: поиск по ресторану, статус, открытие и правка.' },
];
const SETUP_TABS = [
  { key: 'delivery', label: 'График поставок', managerTab: 'schedules',
    hint: 'В какие дни какой ресторан получает тесто и до какого часа успевает подать заявку.' },
  { key: 'products', label: 'Товары цеха', managerTab: 'templates',
    hint: 'Размеры теста, которые видит ресторан. «Кратность» — сколько штук в лотке: по ней кабинет пересчитывает лотки в штуки.' },
  { key: 'params', label: 'Параметры цеха', managerTab: 'settings',
    hint: 'Пауза приёма заявок, авто-письмо со сводкой цеху, минимальный заказ и содержимое Excel.' },
];
const ALL_TABS = [...WORK_TABS, ...SETUP_TABS];

const SupplierOrdersManagerView = defineAsyncComponent(() => import('@/views/SupplierOrdersManagerView.vue'));

const loading = ref(true);
const loadingDay = ref(false);
const sheetsBusy = ref(false);
const weekBusy = ref(false);
const printing = ref('');
const tab = ref('intake');
const workshops = ref([]);
const shopId = ref('');
const date = ref(todayStr());
const day = ref(emptyDay());

const hasDay = computed(() => day.value.restaurants.length > 0);
const managerTab = computed(() => ALL_TABS.find(t => t.key === tab.value)?.managerTab || '');
const tabHint = computed(() => ALL_TABS.find(t => t.key === tab.value)?.hint || '');
const shopEntity = computed(() =>
  workshops.value.find(w => w.id === shopId.value)?.legal_entity || PS_ENTITY);
/** «10.08–15.08» — неделя, в которую попадает выбранная дата (пн–сб). */
const weekLabel = computed(() => {
  const d = new Date(date.value + 'T00:00:00');
  if (isNaN(d)) return '';
  const back = (d.getDay() + 6) % 7;          // getDay(): вс = 0
  const mon = new Date(d); mon.setDate(d.getDate() - back);
  const sat = new Date(mon); sat.setDate(mon.getDate() + 5);
  return `${fmtDate(ymd(mon))}–${fmtDate(ymd(sat))}`;
});

function emptyDay() {
  return { sizes: [], restaurants: [], totals: { by_sku: {}, pieces: 0, trays: 0, stacks: 0, pallets: 0 } };
}
/**
 * Дата в виде ГГГГ-ММ-ДД по местному времени.
 * toISOString() переводит в UTC: в Минске после 21:00 он отдавал вчерашний
 * день, и «сегодня» вместе со стрелками промахивались на сутки.
 */
function ymd(d) {
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}
function todayStr(offset = 0) {
  const d = new Date();
  d.setDate(d.getDate() + offset);
  return ymd(d);
}
function fmt(v) {
  const n = Number(v) || 0;
  return n.toLocaleString('ru-RU', { maximumFractionDigits: 2 });
}
/** Русское склонение: forms = [1 точка, 2 точки, 5 точек]. Доли — как «2». */
function plural(v, forms) {
  const n = Number(v) || 0;
  if (!Number.isInteger(n)) return forms[1];
  const a = Math.abs(n) % 100, b = a % 10;
  if (a > 10 && a < 20) return forms[2];
  if (b === 1) return forms[0];
  if (b >= 2 && b <= 4) return forms[1];
  return forms[2];
}
function fmtDate(d) {
  const p = String(d).slice(0, 10).split('-');
  return `${p[2]}.${p[1]}`;
}
/** «2026-08-04» → «04.08.2026» — как в шапке загрузочного листа. */
function fmtDateFull(d) {
  const p = String(d).slice(0, 10).split('-');
  return `${p[2]}.${p[1]}.${p[0]}`;
}
function restLabel(num) { return formatRestaurantNumber(Number(num)); }

/**
 * Строки таблицы по одному ресторану.
 * Одна партия — одна строка с общими количествами (`no: 0`, без пометки).
 * Две партии — две строки: цех делает их в разные дни, и общая сумма по точке
 * ничего не говорит о том, сколько замешивать сегодня.
 */
function restBatches(r) {
  const byBatch = r.qty_by_batch || {};
  const nums = Object.keys(byBatch).map(Number).filter(n => n > 0).sort((a, b) => a - b);
  if (nums.length < 2) return [{ no: 0, qty: r.qty || {}, trays: r.trays || {} }];
  return nums.map((no) => {
    const qty = byBatch[no] || {};
    const trays = {};
    for (const s of day.value.sizes) {
      const pieces = Number(qty[s.sku]) || 0;
      const perTray = Number(s.per_tray) || 0;
      trays[s.sku] = pieces > 0 && perTray > 0 ? Math.ceil(pieces / perTray) : 0;
    }
    return { no, qty, trays };
  });
}

const DOW_SHORT = ['вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'];
const DOW_FULL = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];
function dowOf(d) { return DOW_SHORT[new Date(d + 'T00:00:00').getDay()] || ''; }
function dowFull(d) { return d ? DOW_FULL[new Date(d + 'T00:00:00').getDay()] || '' : ''; }

function shiftDate(delta) {
  const d = new Date(date.value + 'T00:00:00');
  d.setDate(d.getDate() + delta);
  date.value = ymd(d);
  loadDay();
}

async function api(path, params = {}) {
  const qs = new URLSearchParams({ supplier_id: shopId.value, ...params }).toString();
  const { data, error } = await db.request('GET', `own-production/${path}?${qs}`);
  if (error) throw new Error(error);
  if (data?.error) throw new Error(data.error);
  return data;
}

async function loadShops() {
  loading.value = true;
  try {
    const { data, error } = await db.request('GET', 'own-production/suppliers');
    if (error) throw new Error(error);
    workshops.value = data?.suppliers || [];
    if (workshops.value.length) {
      shopId.value = workshops.value[0].id;
      await loadTab();
    }
  } catch (e) {
    toast.error('Не получилось', e.message);
  } finally {
    loading.value = false;
  }
}

async function loadDay() {
  if (!shopId.value || !date.value) return;
  loadingDay.value = true;
  try {
    day.value = await api('day', { date: date.value });
  } catch (e) {
    toast.error('Не получилось', e.message);
  } finally {
    loadingDay.value = false;
  }
}

/** Данные текущей вкладки. Экраны заявок грузят себя сами. */
function loadTab() {
  if (tab.value === 'day') return loadDay();
  return Promise.resolve();
}

function switchTab(key) {
  if (tab.value === key) return;
  tab.value = key;
  loadTab();
}

function pickShop(id) {
  shopId.value = id;
  day.value = emptyDay();
  loadTab();
}

/** Скачивание файла с сессионным заголовком — окно открыть нельзя. */
async function downloadFile(url, filename) {
  const r = await fetch(url, {
    credentials: 'include',
    headers: { 'X-Session-Token': localStorage.getItem('bk_session_token') || '' },
  });
  if (!r.ok) {
    // Сервер объясняет причину («на эту неделю заявок нет») — она полезнее,
    // чем общее «файл не собрался».
    let reason = 'Файл не собрался';
    try { reason = (await r.clone().json())?.error || reason; } catch (e) { /* не JSON */ }
    toast.error('Не получилось', reason);
    return;
  }
  const blob = await r.blob();
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = filename;
  link.click();
  URL.revokeObjectURL(link.href);
}

async function downloadSheets() {
  sheetsBusy.value = true;
  try {
    const url = `/api/so/admin/loading-sheets?supplier_id=${encodeURIComponent(shopId.value)}&date=${encodeURIComponent(date.value)}`;
    await downloadFile(url, `Загрузочные листы ${fmtDate(date.value)}.xlsx`);
  } finally {
    sheetsBusy.value = false;
  }
}

/** Заказ теста на неделю: два листа — пн-вт-ср и чт-пт-сб. */
async function downloadWeek() {
  weekBusy.value = true;
  try {
    const url = `/api/own-production/week-export?supplier_id=${encodeURIComponent(shopId.value)}&date=${encodeURIComponent(date.value)}`;
    await downloadFile(url, `Тесто ${weekLabel.value}.xlsx`);
  } finally {
    weekBusy.value = false;
  }
}

/**
 * Печать листов одной точки: открываем окно с готовой вёрсткой.
 * Содержимое собирается из тех же данных, что и книга Excel, — по странице
 * на стопку, содержимое по центру листа (верх лотка загибается).
 */
async function printSheets(restaurantNumber) {
  printing.value = String(restaurantNumber);
  try {
    const path = `so/admin/loading-sheets-data?supplier_id=${encodeURIComponent(shopId.value)}&date=${encodeURIComponent(date.value)}`;
    const { data, error } = await db.request('GET', path);
    if (error) throw new Error(error);
    const rest = (data?.restaurants || []).find(r => String(r.restaurant_number) === String(restaurantNumber));
    if (!rest) { toast.error('Нет данных', 'По этой точке листов нет'); return; }

    const esc = (v) => String(v ?? '').replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
    const trayWord = (n) => {
      const a = Math.abs(n) % 100, b = a % 10;
      if (a > 10 && a < 20) return 'лотков';
      if (b === 1) return 'лоток';
      if (b >= 2 && b <= 4) return 'лотка';
      return 'лотков';
    };
    const dateFmt = fmtDateFull(date.value);
    // Цвет стикера лежит в items (в стопках его нет) — собираем справочник по SKU.
    const stickerBySku = {};
    for (const it of rest.items || []) stickerBySku[it.sku] = it.sticker || '';
    const stickerWord = (sku) => (stickerBySku[sku] || '—').replace(/стикер/gi, '').trim().toUpperCase() || '—';
    const total = rest.stacks.length;
    const pages = rest.stacks.map((st, idx) => {
      const lines = st.lines.map(l => `<div class="ls-row">
          <div class="ls-name">${esc(l.name)}</div>
          <div class="ls-trays">${l.trays} ${trayWord(l.trays)}</div>
          <div class="ls-sticker">${esc(stickerWord(l.sku))}</div>
        </div>`).join('');
      return `<section class="ls-page">
        <div class="ls-title">${esc(rest.address)}</div>
        <div class="ls-addr">${esc(rest.title)}</div>
        <div class="ls-date">Отгрузка ${esc(dateFmt)}</div>
        <div class="ls-stack">
          <div class="ls-stack-head">${st.mixed ? 'СБОРНАЯ СТОПКА' : 'СТОПКА'} &nbsp;${idx + 1} / ${total}</div>
          <div class="ls-cols"><div>Наименование</div><div>Количество</div><div>Стикер</div></div>
          ${lines}
          ${st.mixed ? `<div class="ls-total">ИТОГО В СТОПКЕ: ${st.total} ${trayWord(st.total)}</div>` : ''}
        </div>
        <div class="ls-foot">Лист ${idx + 1} из ${total}</div>
      </section>`;
    }).join('');

    const w = window.open('', '_blank');
    if (!w) { toast.error('Не получилось', 'Браузер заблокировал окно печати'); return; }
    w.document.write(`<!doctype html><html lang="ru"><head><meta charset="utf-8">
      <title>Загрузочный лист — ${esc(rest.title)} — ${esc(dateFmt)}</title>
      <style>
        @page { size: A4 portrait; margin: 12mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #000; margin: 0; }
        .ls-page { page-break-after: always; display: flex; flex-direction: column;
                   justify-content: center; min-height: 265mm; }
        .ls-page:last-child { page-break-after: auto; }
        .ls-title { font-size: 34px; font-weight: 800; text-align: center; letter-spacing: 1px;
                    border: 2px solid #000; background: #ececec; padding: 8px 4px; }
        .ls-addr { text-align: center; font-size: 18px; padding: 4px; border-left: 2px solid #000; border-right: 2px solid #000; background: #ececec; }
        .ls-date { text-align: center; font-size: 18px; font-weight: 700; border: 2px solid #000; background: #ececec; padding: 5px; }
        .ls-stack { margin-top: 14px; border: 3px solid #000; }
        .ls-stack-head { background: #000; color: #fff; font-size: 25px; font-weight: 800; text-align: center; padding: 7px; letter-spacing: 1px; }
        .ls-cols { display: grid; grid-template-columns: 2fr 1.2fr 1fr; background: #f2f2f2;
                   border-bottom: 1px solid #000; font-size: 14px; font-weight: 700; color: #444; }
        .ls-cols > div { text-align: center; padding: 4px 2px; }
        .ls-row { display: grid; grid-template-columns: 2fr 1.2fr 1fr; align-items: center;
                  padding: 14px 8px; min-height: 96px; border-bottom: 1px solid #000; }
        .ls-stack .ls-row:last-child { border-bottom: none; }
        .ls-name { font-size: 22px; font-weight: 700; text-align: center; line-height: 1.25; }
        .ls-trays { font-size: 46px; font-weight: 800; text-align: center; line-height: 1.05; }
        .ls-sticker { font-size: 24px; font-weight: 800; text-align: center; }
        .ls-total { font-size: 22px; font-weight: 800; text-align: center; padding: 8px; background: #ececec; border-top: 2px solid #000; }
        .ls-foot { margin-top: 14px; text-align: center; font-size: 17px; font-weight: 700; border-top: 1px solid #000; padding-top: 8px; }
      </style></head><body>${pages}</body></html>`);
    w.document.close();
    w.focus();
    setTimeout(() => w.print(), 300);
  } catch (e) {
    toast.error('Не получилось', e.message);
  } finally {
    printing.value = '';
  }
}

// ═══ Адрес страницы ═══
// У каждой вкладки и выбранной даты свой адрес: ссылку можно отправить коллеге,
// а «назад» в браузере возвращает туда, где человек был.
{
  const q = route.query || {};
  const t = String(q.tab || '');
  if (ALL_TABS.some(x => x.key === t)) tab.value = t;
  if (/^\d{4}-\d{2}-\d{2}$/.test(String(q.date || ''))) date.value = String(q.date);
}

// Слежка не immediate: срабатывает только на настоящее изменение, поэтому
// адрес пишем сразу — иначе первое переключение вкладки теряется.
watch([tab, date], () => {
  const q = { ...route.query };
  const set = (key, val) => { if (val) q[key] = String(val); else delete q[key]; };
  set('tab', tab.value !== 'intake' ? tab.value : '');
  set('date', date.value);
  // Хвосты удалённых разделов (план производства, график изготовления):
  // старые ссылки не должны тащить за собой мёртвые параметры.
  for (const dead of ['from', 'to', 'mode', 'reserve']) delete q[dead];
  router.replace({ query: q }).catch(() => {});
});

onMounted(loadShops);
</script>

<style scoped>
.op { color: #2E1C10; }
.op-sub { margin: 4px 0 0; font-size: 13px; color: #8A7F72; max-width: 640px; line-height: 1.45; }

.op-shops { display: flex; gap: 8px; margin-bottom: 12px; }
.op-shop {
  padding: 8px 15px; border: 1.5px solid #E4D9CB; border-radius: 10px; background: #fff;
  font: inherit; font-size: 13px; font-weight: 700; color: #5F4B38; cursor: pointer;
}
.op-shop.active {
  background: linear-gradient(135deg, #E87A1E 0%, #D9661A 100%);
  border-color: transparent; color: #fff;
}

.op-nav { display: flex; flex-direction: column; gap: 8px; margin-bottom: 14px; }
.op-seg {
  display: inline-flex; align-items: center; padding: 3px; gap: 2px;
  background: #F4EDE4; border-radius: 12px; max-width: 100%; overflow-x: auto;
}
.op-seg-btn {
  flex: 0 0 auto; padding: 7px 15px; border: 0; border-radius: 9px; background: transparent;
  font: inherit; font-size: 13px; font-weight: 700; color: #6B5544; cursor: pointer; white-space: nowrap;
}
.op-seg-btn.active { background: #fff; color: #3A2418; box-shadow: 0 1px 4px rgba(74, 32, 19, .12); }
.op-seg-setup { background: transparent; padding: 0; gap: 6px; }
.op-seg-setup .op-seg-btn {
  border: 1.5px solid #EFE7DC; background: #fff; font-size: 12.5px; font-weight: 600;
  color: #8A7F72; border-radius: 999px; padding: 6px 13px;
}
.op-seg-setup .op-seg-btn.active { border-color: #E4B98A; background: #FFF5EA; color: #B4560D; box-shadow: none; }
.op-seg-cap {
  flex: 0 0 auto; padding-right: 4px; font-size: 11px; font-weight: 700; letter-spacing: .04em;
  text-transform: uppercase; color: #B3A697;
}

.op-panel {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
  padding: 12px 14px; margin-bottom: 12px;
  border: 1.5px solid #EFE7DC; border-radius: 14px; background: #fff;
}
.op-panel-label { font-size: 12.5px; font-weight: 700; color: #6B5544; }
.op-panel-right { display: flex; align-items: center; gap: 8px; margin-left: auto; flex-wrap: wrap; }
.op-datebox { display: inline-flex; align-items: center; gap: 6px; }
.op-date-dow { font-size: 12.5px; font-weight: 700; color: #8A7F72; }
.op-nav-day {
  width: 30px; height: 32px; border: 1.5px solid #E4D9CB; border-radius: 9px; background: #fff;
  font-size: 17px; line-height: 1; font-weight: 700; color: #6B5544; cursor: pointer;
}
.op-nav-day:hover { border-color: #C4B8A8; }

.op-btn {
  padding: 9px 15px; border: 1.5px solid #E4D9CB; border-radius: 10px; background: #fff;
  font: inherit; font-size: 13px; font-weight: 700; color: #5F4B38; cursor: pointer; white-space: nowrap;
}
.op-btn:hover:not(:disabled) { border-color: #C4B8A8; }
.op-btn:disabled { opacity: .5; cursor: default; }
.op-btn-sm { padding: 5px 11px; font-size: 12px; }
.op-btn-accent {
  background: linear-gradient(135deg, #E87A1E 0%, #D9661A 100%);
  border-color: transparent; color: #fff; box-shadow: 0 4px 12px rgba(232, 122, 30, .22);
}
.op-btn-accent:hover:not(:disabled) { filter: brightness(1.06); }

.op-card {
  padding: 16px 18px; margin-bottom: 14px;
  border: 1.5px solid #EFE7DC; border-radius: 14px; background: #fff;
}
.op-card-flush { padding: 0; overflow-x: auto; }
.op-empty { text-align: center; color: #8A7F72; font-size: 13.5px; padding: 30px 20px; }
.op-empty-title { font-size: 16px; font-weight: 800; color: #3A2418; margin-bottom: 6px; }
.op-note { margin: 10px 14px 12px; font-size: 12px; color: #8A7F72; line-height: 1.45; }

.op-tiles { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
.op-tile {
  display: flex; align-items: baseline; gap: 7px;
  padding: 9px 15px; border: 1.5px solid #EFE7DC; border-radius: 12px; background: #fff;
  font-size: 12.5px; font-weight: 600; color: #8A7F72;
}
.op-tile-num { font-size: 20px; font-weight: 800; color: #3A2418; font-variant-numeric: tabular-nums; }
.op-tile-accent { border-color: #F0C89A; background: rgba(232, 122, 30, .08); }
.op-tile-accent .op-tile-num { color: #C25E12; }

.op-table { width: 100%; font-size: 13px; }
.op-table th { white-space: nowrap; }
.op-th-hint { font-size: 10.5px; font-weight: 600; color: #9A8F80; }
.op-num { text-align: right !important; white-space: nowrap; font-variant-numeric: tabular-nums; }
.op-trays { display: block; font-size: 11px; color: #9A8F80; }
.op-rest { white-space: nowrap; }
.op-rest-title { font-weight: 700; }
.op-rest-num {
  display: inline-block; margin-left: 6px; padding: 1px 7px; border-radius: 7px;
  background: #F4EDE4; color: #6B5544; font-size: 11px; font-weight: 700;
}
.op-addr { color: #5F4B38; }
.op-dim { color: #C4B8A8; }
.op-print-col { text-align: center !important; white-space: nowrap; }
/* Вторая строка партии липнет к первой: это одна точка, а не новая. */
.op-batch-next > td { border-top: 0; }
.op-batch-tag {
  display: inline-block; margin-top: 2px; padding: 1px 7px; border-radius: 7px;
  background: #F4EDE4; color: #8A7F72; font-size: 11px; font-weight: 700; white-space: nowrap;
}
.op-total-row { background: #FBF6F0; }
.op-total-row td { font-weight: 700; }

@media (max-width: 760px) {
  .op-panel-right { margin-left: 0; width: 100%; }
  .op-tiles .op-tile { flex: 1 1 calc(50% - 8px); }
  .op-seg-setup { flex-wrap: nowrap; }
}

.op-tabhint { margin: -4px 0 12px; font-size: 12.5px; color: #8A7F72; line-height: 1.5; max-width: 900px; }
</style>
