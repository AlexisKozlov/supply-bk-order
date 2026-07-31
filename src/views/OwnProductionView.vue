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
                <tr v-for="r in day.restaurants" :key="r.restaurant_number">
                  <td class="op-rest">
                    <span class="op-rest-title">{{ r.title }}</span>
                    <span class="op-rest-num">{{ restLabel(r.restaurant_number) }}</span>
                  </td>
                  <td class="op-addr">{{ r.address }}</td>
                  <td v-for="s in day.sizes" :key="s.sku" class="op-num">
                    <template v-if="r.qty[s.sku]">
                      <b>{{ fmt(r.qty[s.sku]) }}</b>
                      <span class="op-trays">{{ r.trays[s.sku] }} лотк.</span>
                    </template>
                    <span v-else class="op-dim">—</span>
                  </td>
                  <td class="op-num"><b>{{ r.total_trays }}</b></td>
                  <td class="op-num">{{ r.stacks }}</td>
                  <td class="op-num">{{ fmt(r.pallets) }}</td>
                  <td class="op-print-col">
                    <button class="op-btn op-btn-sm" :disabled="printing === r.restaurant_number"
                            @click="printSheets(r.restaurant_number)">
                      {{ printing === r.restaurant_number ? '…' : 'Печать' }}
                    </button>
                  </td>
                </tr>
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

      <!-- ═══ План производства ═══ -->
      <template v-else-if="tab === 'plan'">
        <div class="op-panel">
          <label class="op-panel-label">Период</label>
          <input type="date" v-model="planFrom" class="rom-input-sm" @change="loadPlan" />
          <span class="op-dash">—</span>
          <input type="date" v-model="planTo" class="rom-input-sm" @change="loadPlan" />
          <button class="op-btn" :disabled="loadingPlan" @click="loadPlan">
            {{ loadingPlan ? 'Считаю…' : 'Обновить' }}
          </button>
          <div class="op-panel-right">
            <div class="op-switch">
              <button class="op-switch-btn" :class="{ active: planMode === 'production' }"
                      @click="planMode = 'production'">По дню изготовления</button>
              <button class="op-switch-btn" :class="{ active: planMode === 'delivery' }"
                      @click="planMode = 'delivery'">По дню поставки</button>
            </div>
            <label class="op-reserve">
              Запас
              <input type="number" v-model.number="reserve" min="0" max="50" class="rom-input-sm op-reserve-input" />
              %
            </label>
          </div>
        </div>

        <div v-if="schedIsEmpty" class="op-warn">
          График изготовления не заполнен — тесто считается изготовленным в день поставки,
          и оба вида плана совпадают.
          <button class="op-link" @click="switchTab('production')">Заполнить график</button>
        </div>

        <div v-if="loadingPlan" class="op-card op-empty">Считаю…</div>
        <div v-else-if="!plan.days.length" class="op-card op-empty">
          <div class="op-empty-title">Заявок за период нет</div>
          <p>Выберите другие даты.</p>
        </div>

        <!-- Вид «по дню изготовления»: один день = одна смена цеха, внутри —
             дни поставки, на которые это тесто уедет. -->
        <div v-else-if="planMode === 'production'" class="op-card op-card-flush">
          <table class="rom-table op-table">
            <thead>
              <tr>
                <th>День изготовления</th>
                <th>Уедет на поставку</th>
                <th class="op-num">Точек</th>
                <th v-for="s in plan.sizes" :key="s.sku" class="op-num">{{ s.short_name }}</th>
                <th class="op-num">Лотков</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="d in plan.production" :key="d.date">
                <td><b>{{ fmtDate(d.date) }}</b> <span class="op-dow">{{ dowOf(d.date) }}</span></td>
                <td class="op-deliv">
                  <span v-for="dd in d.deliveries" :key="dd" class="op-chip">
                    {{ fmtDate(dd) }} <i>{{ dowOf(dd) }}</i>
                  </span>
                </td>
                <td class="op-num">{{ d.restaurants }}</td>
                <td v-for="s in plan.sizes" :key="s.sku" class="op-num">
                  <template v-if="d.by_sku[s.sku]?.pieces">
                    <b>{{ withReserve(d.by_sku[s.sku].pieces) }}</b>
                    <span class="op-trays">{{ d.by_sku[s.sku].trays }} лотк.</span>
                  </template>
                  <span v-else class="op-dim">—</span>
                </td>
                <td class="op-num"><b>{{ d.trays }}</b></td>
              </tr>
              <tr class="op-total-row">
                <td>ИТОГО</td>
                <td></td>
                <td class="op-num"></td>
                <td v-for="s in plan.sizes" :key="s.sku" class="op-num">
                  <b>{{ withReserve(prodSizeTotal(s.sku)) }}</b>
                </td>
                <td class="op-num"><b>{{ prodTotalTrays }}</b></td>
              </tr>
            </tbody>
          </table>
          <p class="op-note">
            Дни изготовления берутся из графика в разделе «График изготовления».
            Для дней поставки без графика тесто считается изготовленным в тот же день.
            <template v-if="reserve > 0">
              В колонках размеров — количество с запасом {{ reserve }}%, округлённое вверх.
            </template>
          </p>
        </div>

        <div v-else class="op-card op-card-flush">
          <table class="rom-table op-table">
            <thead>
              <tr>
                <th>Дата поставки</th>
                <th class="op-num">Точек</th>
                <th v-for="s in plan.sizes" :key="s.sku" class="op-num">{{ s.short_name }}</th>
                <th class="op-num">Лотков</th>
                <th class="op-num">Стопок</th>
                <th class="op-num">Паллет</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="d in plan.days" :key="d.date">
                <td><b>{{ fmtDate(d.date) }}</b> <span class="op-dow">{{ dowOf(d.date) }}</span></td>
                <td class="op-num">{{ d.restaurants }}</td>
                <td v-for="s in plan.sizes" :key="s.sku" class="op-num">
                  <template v-if="d.by_sku[s.sku]?.pieces">
                    <b>{{ withReserve(d.by_sku[s.sku].pieces) }}</b>
                    <span class="op-trays">{{ d.by_sku[s.sku].trays }} лотк.</span>
                  </template>
                  <span v-else class="op-dim">—</span>
                </td>
                <td class="op-num"><b>{{ d.trays }}</b></td>
                <td class="op-num">{{ d.stacks }}</td>
                <td class="op-num">{{ fmt(d.pallets) }}</td>
              </tr>
              <tr class="op-total-row">
                <td>ИТОГО</td>
                <td class="op-num"></td>
                <td v-for="s in plan.sizes" :key="s.sku" class="op-num">
                  <b>{{ withReserve(planSizeTotal(s.sku)) }}</b>
                </td>
                <td class="op-num"><b>{{ planTotal('trays') }}</b></td>
                <td class="op-num"><b>{{ planTotal('stacks') }}</b></td>
                <td class="op-num"><b>{{ fmt(planTotal('pallets')) }}</b></td>
              </tr>
            </tbody>
          </table>
          <p v-if="reserve > 0" class="op-note">
            В колонках размеров показано количество с запасом {{ reserve }}% — округлено вверх до лотка.
            Лотки, стопки и паллеты — по факту заявок.
          </p>
        </div>
      </template>

      <!-- ═══ График изготовления ═══ -->
      <template v-else-if="tab === 'production'">
        <div class="op-card">
          <h3 class="op-h3">Когда изготавливать тесто</h3>
          <p class="op-hint">
            Тесто должно созреть, поэтому изготавливают его заранее. Здесь задаём,
            в какой день делать тесто под каждый день поставки. Вторая партия нужна
            ресторанам с одной поставкой в неделю: часть теста делают раньше, часть позже,
            чтобы к концу недели оно не перестояло.
          </p>

          <table class="rom-table op-table op-sched-table">
            <thead>
              <tr>
                <th>День поставки</th>
                <th>Партия 1 — изготовление</th>
                <th>Партия 2 — изготовление</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="dow in DOWS" :key="dow.n">
                <td><b>{{ dow.label }}</b></td>
                <td>
                  <select v-model.number="sched[dow.n].b1" class="rom-input-sm op-sched-select">
                    <option :value="0">— в день поставки —</option>
                    <option v-for="d in DOWS" :key="'b1' + d.n" :value="d.n">{{ d.label }}</option>
                  </select>
                  <span v-if="sched[dow.n].b1" class="op-sched-hint">{{ daysBeforeLabel(dow.n, sched[dow.n].b1) }}</span>
                </td>
                <td>
                  <select v-model.number="sched[dow.n].b2" class="rom-input-sm op-sched-select"
                          :disabled="!sched[dow.n].b1">
                    <option :value="0">— без второй партии —</option>
                    <option v-for="d in DOWS" :key="'b2' + d.n" :value="d.n">{{ d.label }}</option>
                  </select>
                  <span v-if="sched[dow.n].b2" class="op-sched-hint">{{ daysBeforeLabel(dow.n, sched[dow.n].b2) }}</span>
                </td>
              </tr>
            </tbody>
          </table>

          <div class="op-sched-actions">
            <button class="op-btn op-btn-accent" :disabled="savingSched || !schedDirty" @click="saveSchedule">
              {{ savingSched ? 'Сохраняю…' : 'Сохранить график' }}
            </button>
            <span v-if="schedSaved" class="op-saved">Сохранено</span>
            <span v-else-if="schedDirty" class="op-dirty">Есть несохранённые изменения</span>
          </div>
        </div>
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
  { key: 'plan', label: 'План производства' },
  { key: 'archive', label: 'Все заявки', managerTab: 'list',
    hint: 'Поданные заявки на тесто за любой период: поиск по ресторану, статус, открытие и правка.' },
];
const SETUP_TABS = [
  { key: 'delivery', label: 'График поставок', managerTab: 'schedules',
    hint: 'В какие дни какой ресторан получает тесто и до какого часа успевает подать заявку.' },
  { key: 'production', label: 'График изготовления' },
  { key: 'products', label: 'Товары цеха', managerTab: 'templates',
    hint: 'Размеры теста, которые видит ресторан. «Кратность» — сколько штук в лотке: по ней кабинет пересчитывает лотки в штуки.' },
  { key: 'params', label: 'Параметры цеха', managerTab: 'settings',
    hint: 'Пауза приёма заявок, авто-письмо со сводкой цеху, минимальный заказ и содержимое Excel.' },
];
const ALL_TABS = [...WORK_TABS, ...SETUP_TABS];

const SupplierOrdersManagerView = defineAsyncComponent(() => import('@/views/SupplierOrdersManagerView.vue'));

const DOWS = [
  { n: 1, label: 'понедельник' }, { n: 2, label: 'вторник' }, { n: 3, label: 'среда' },
  { n: 4, label: 'четверг' }, { n: 5, label: 'пятница' }, { n: 6, label: 'суббота' },
  { n: 7, label: 'воскресенье' },
];

const loading = ref(true);
const loadingDay = ref(false);
const loadingPlan = ref(false);
const sheetsBusy = ref(false);
const printing = ref('');
const tab = ref('intake');
const workshops = ref([]);
const shopId = ref('');
const date = ref(todayStr());
const day = ref(emptyDay());
const plan = ref({ sizes: [], days: [], production: [] });
const planMode = ref('production');
// График изготовления: день поставки → партия 1 и партия 2 (0 = нет)
const sched = ref(emptySched());
const schedLoaded = ref('');           // снимок сохранённого графика — для «есть изменения»
const savingSched = ref(false);
const schedSaved = ref(false);
const planFrom = ref(todayStr());
const planTo = ref(todayStr(21));
// Запас производства: цех делает тесто с небольшим перекрытием, чтобы брак или
// довоз не оставил точку без теста. На заявки не влияет — только на план.
const reserve = ref(0);

const hasDay = computed(() => day.value.restaurants.length > 0);
const managerTab = computed(() => ALL_TABS.find(t => t.key === tab.value)?.managerTab || '');
const tabHint = computed(() => ALL_TABS.find(t => t.key === tab.value)?.hint || '');
// Пустой график изготовления означает «делаем в день поставки» — про это лучше
// сказать прямо, иначе план выглядит правильным и никто не заметит настройку.
const schedIsEmpty = computed(() => Object.values(sched.value).every(v => !v.b1));
const shopEntity = computed(() =>
  workshops.value.find(w => w.id === shopId.value)?.legal_entity || PS_ENTITY);
const schedDirty = computed(() => JSON.stringify(sched.value) !== schedLoaded.value);

function emptyDay() {
  return { sizes: [], restaurants: [], totals: { by_sku: {}, pieces: 0, trays: 0, stacks: 0, pallets: 0 } };
}
function emptySched() {
  return Object.fromEntries([1, 2, 3, 4, 5, 6, 7].map(n => [n, { b1: 0, b2: 0 }]));
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

/** Количество с запасом, округлённое вверх — цех работает лотками. */
function withReserve(pieces) {
  const n = Number(pieces) || 0;
  if (!reserve.value) return fmt(n);
  return fmt(Math.ceil(n * (1 + reserve.value / 100)));
}
function planSizeTotal(sku) {
  return plan.value.days.reduce((sum, d) => sum + (d.by_sku[sku]?.pieces || 0), 0);
}
function planTotal(field) {
  return plan.value.days.reduce((sum, d) => sum + (Number(d[field]) || 0), 0);
}
function prodSizeTotal(sku) {
  return (plan.value.production || []).reduce((sum, d) => sum + (d.by_sku[sku]?.pieces || 0), 0);
}
const prodTotalTrays = computed(() =>
  (plan.value.production || []).reduce((sum, d) => sum + (Number(d.trays) || 0), 0));

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
      // График изготовления нужен не только своей вкладке: по нему план
      // понимает, предупреждать ли о ненастроенных днях.
      await loadSchedule();
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

async function loadPlan() {
  if (!shopId.value) return;
  if (planTo.value < planFrom.value) {
    toast.error('Период задан наоборот', 'Конец периода раньше начала');
    return;
  }
  loadingPlan.value = true;
  try {
    plan.value = await api('plan', { from: planFrom.value, to: planTo.value });
  } catch (e) {
    toast.error('Не получилось', e.message);
  } finally {
    loadingPlan.value = false;
  }
}

/** Данные текущей вкладки. Экраны заявок грузят себя сами. */
function loadTab() {
  if (tab.value === 'day') return loadDay();
  if (tab.value === 'plan') return loadPlan();
  if (tab.value === 'production') return loadSchedule();
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
  plan.value = { sizes: [], days: [], production: [] };
  loadTab();
}

// ═══ График изготовления ═══

/** «за сколько дней до поставки» — чтобы график читался без счёта в уме. */
function daysBeforeLabel(deliveryDow, productionDow) {
  let back = (deliveryDow - productionDow + 7) % 7;
  if (back === 0) back = 7;
  const word = back === 1 ? 'день' : (back < 5 ? 'дня' : 'дней');
  return `за ${back} ${word}`;
}

async function loadSchedule() {
  try {
    const data = await api('schedule');
    const fresh = emptySched();
    for (const [dow, rows] of Object.entries(data.schedule || {})) {
      for (const r of rows) {
        if (Number(r.batch_no) === 2) fresh[dow].b2 = Number(r.production_dow);
        else fresh[dow].b1 = Number(r.production_dow);
      }
    }
    sched.value = fresh;
    schedLoaded.value = JSON.stringify(fresh);
  } catch (e) {
    toast.error('Не получилось', e.message);
  }
}

async function saveSchedule() {
  savingSched.value = true;
  schedSaved.value = false;
  try {
    const rows = [];
    for (const [dow, v] of Object.entries(sched.value)) {
      if (v.b1) rows.push({ delivery_dow: Number(dow), batch_no: 1, production_dow: v.b1 });
      if (v.b1 && v.b2) rows.push({ delivery_dow: Number(dow), batch_no: 2, production_dow: v.b2 });
    }
    const { data, error } = await db.request('POST', 'own-production/schedule', {
      supplier_id: shopId.value, rows,
    });
    if (error) throw new Error(error);
    if (data?.error) throw new Error(data.error);
    schedLoaded.value = JSON.stringify(sched.value);
    schedSaved.value = true;
    setTimeout(() => { schedSaved.value = false; }, 2500);
    // План пересчитываем: дни изготовления изменились.
    plan.value = { sizes: [], days: [], production: [] };
  } catch (e) {
    toast.error('Не сохранилось', e.message);
  } finally {
    savingSched.value = false;
  }
}

/** Скачивание файла с сессионным заголовком — окно открыть нельзя. */
async function downloadFile(url, filename) {
  const r = await fetch(url, {
    credentials: 'include',
    headers: { 'X-Session-Token': localStorage.getItem('bk_session_token') || '' },
  });
  if (!r.ok) { toast.error('Не получилось', 'Файл не собрался'); return; }
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
  if (/^\d{4}-\d{2}-\d{2}$/.test(String(q.from || ''))) planFrom.value = String(q.from);
  if (/^\d{4}-\d{2}-\d{2}$/.test(String(q.to || ''))) planTo.value = String(q.to);
  if (q.mode === 'delivery' || q.mode === 'production') planMode.value = String(q.mode);
  const r = Number(q.reserve);
  if (Number.isFinite(r) && r > 0 && r <= 50) reserve.value = r;
}

// Слежка не immediate: срабатывает только на настоящее изменение, поэтому
// адрес пишем сразу — иначе первое переключение вкладки теряется.
watch([tab, date, planFrom, planTo, planMode, reserve], () => {
  const q = { ...route.query };
  const set = (key, val) => { if (val) q[key] = String(val); else delete q[key]; };
  set('tab', tab.value !== 'intake' ? tab.value : '');
  set('date', date.value);
  set('from', planFrom.value);
  set('to', planTo.value);
  set('mode', planMode.value !== 'production' ? planMode.value : '');
  set('reserve', reserve.value > 0 ? reserve.value : '');
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
.op-dash { color: #C4B8A8; }
.op-datebox { display: inline-flex; align-items: center; gap: 6px; }
.op-date-dow { font-size: 12.5px; font-weight: 700; color: #8A7F72; }
.op-nav-day {
  width: 30px; height: 32px; border: 1.5px solid #E4D9CB; border-radius: 9px; background: #fff;
  font-size: 17px; line-height: 1; font-weight: 700; color: #6B5544; cursor: pointer;
}
.op-nav-day:hover { border-color: #C4B8A8; }
.op-reserve { display: flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 700; color: #6B5544; }
.op-reserve-input { width: 64px; }

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
.op-h3 { margin: 0 0 4px; font-size: 15.5px; font-weight: 800; color: #4A2013; }
.op-hint { margin: 0 0 12px; font-size: 12.5px; color: #8A7F72; line-height: 1.5; }
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
.op-total-row { background: #FBF6F0; }
.op-total-row td { font-weight: 700; }

@media (max-width: 760px) {
  .op-panel-right { margin-left: 0; width: 100%; }
  .op-tiles .op-tile { flex: 1 1 calc(50% - 8px); }
  .op-seg-setup { flex-wrap: nowrap; }
}

.op-switch { display: inline-flex; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.op-switch-btn { padding: 7px 12px; border: 0; background: #fff; font-size: 12px; font-weight: 700;
                 color: var(--text-secondary); cursor: pointer; }
.op-switch-btn + .op-switch-btn { border-left: 1px solid var(--border); }
.op-switch-btn.active { background: var(--bk-brown); color: #fff; }
.op-dow { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; }
.op-deliv { display: flex; flex-wrap: wrap; gap: 4px; }
.op-chip { padding: 2px 8px; border-radius: 999px; background: #F3EBE0; font-size: 12px; font-weight: 700;
           color: var(--bk-brown); }
.op-chip i { font-style: normal; font-weight: 600; color: #9A8F80; }
.op-sched-table td { vertical-align: middle; }
.op-sched-select { min-width: 150px; }
.op-sched-hint { margin-left: 8px; font-size: 11px; color: var(--text-muted); }
.op-sched-actions { display: flex; align-items: center; gap: 12px; margin-top: 14px; flex-wrap: wrap; }
.op-saved { font-size: 12px; font-weight: 700; color: var(--green); }
.op-dirty { font-size: 12px; font-weight: 700; color: #C25E12; }

.op-tabhint { margin: -4px 0 12px; font-size: 12.5px; color: #8A7F72; line-height: 1.5; max-width: 900px; }
.op-warn {
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
  padding: 11px 14px; margin-bottom: 12px; border-radius: 12px;
  border: 1.5px solid #F0DCC0; background: #FFF7EC;
  font-size: 12.5px; color: #7A5A34; line-height: 1.45;
}
.op-link {
  border: 0; background: none; padding: 0; font: inherit; font-weight: 700;
  color: #C25E12; text-decoration: underline; cursor: pointer;
}
</style>
