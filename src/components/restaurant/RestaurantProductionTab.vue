<template>
  <div class="rpt">
    <div v-if="loading" class="rpt-center"><div class="rpt-spin"></div></div>

    <div v-else-if="error" class="rpt-empty">
      <h2>Собственное производство</h2>
      <p>{{ error }}</p>
    </div>

    <div v-else-if="!workshop" class="rpt-empty">
      <h2>Собственное производство</h2>
      <p>Цех пока не подключён к вашему ресторану.</p>
      <p class="rpt-empty-hint">Обратитесь в отдел закупок</p>
    </div>

    <div v-else-if="!accepting" class="rpt-empty">
      <h2>{{ workshop.name }}</h2>
      <p>{{ pauseMessage || 'Приём заявок на тесто временно приостановлен.' }}</p>
      <p class="rpt-empty-hint">Обратитесь в отдел закупок</p>
    </div>

    <div v-else-if="!dates.length" class="rpt-empty">
      <h2>{{ workshop.name }}</h2>
      <p>Дни поставки теста для вашего ресторана не настроены.</p>
      <p class="rpt-empty-hint">Обратитесь в отдел закупок</p>
    </div>

    <template v-else>
      <!-- Дни поставки -->
      <div class="rpt-days">
        <button v-for="d in dates" :key="d.date" type="button" class="rpt-day"
          :class="{
            'is-active': selectedDate === d.date,
            'is-done': orderState[d.date]?.status && !orderState[d.date]?.isSkip,
            'is-skip': orderState[d.date]?.isSkip,
            'is-closed': d.is_closed && !orderState[d.date]?.status,
          }"
          @click="selectDate(d.date)">
          <span class="rpt-day-name">{{ dayName(d.date) }}</span>
          <span class="rpt-day-date">{{ fmtShort(d.date) }}</span>
          <span v-if="orderState[d.date]?.isSkip" class="rpt-day-mark is-skip" title="Тесто не нужно">×</span>
          <span v-else-if="orderState[d.date]?.status" class="rpt-day-mark is-done" title="Заявка подана">✓</span>
          <span v-else-if="d.is_closed" class="rpt-day-mark is-closed" title="Приём закрыт">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round">
              <rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>
            </svg>
          </span>
        </button>
      </div>

      <div v-if="selectedDate" class="rpt-body">
        <!-- Шапка: цех, дата, дедлайн -->
        <div class="rpt-hero" :class="{ 'is-closed': locked }">
          <div class="rpt-hero-main">
            <div class="rpt-hero-title">{{ workshop.name }} — тесто</div>
            <div class="rpt-hero-sub">Поставка {{ fmtFull(selectedDate) }}</div>
            <div class="rpt-hero-note">
              Заказ считается лотками: наберите лотки, штуки посчитаются сами.
              <template v-if="batches.length > 1">
                Поставка одна в неделю, поэтому тесто делят на две партии разного дня
                изготовления: первая — на начало недели, вторая — на конец.
                Распределите, сколько нужно из каждой.
              </template>
            </div>
          </div>
          <div v-if="locked" class="rpt-hero-lock">Приём закрыт</div>
          <div v-else-if="currentDate?.deadline_str" class="rpt-hero-timer">
            <div class="rpt-hero-timer-v">{{ timeLeft || '—' }}</div>
            <div class="rpt-hero-timer-l">до {{ fmtDeadline(currentDate.deadline_str) }}</div>
          </div>
        </div>

        <div v-if="orderLoading" class="rpt-center"><div class="rpt-spin"></div></div>

        <template v-else>
          <div v-if="isSkipSaved" class="rpt-skip-banner">
            <strong>Тесто на этот день не нужно.</strong>
            <span>Впишите лотки и отправьте заявку, чтобы отменить.</span>
          </div>

          <div class="rpt-cols">
            <div class="rpt-list">
              <div v-for="s in sizes" :key="s.sku" class="rpt-row" :class="{ 'is-filled': sizeTrays(s) > 0 }">
                <div class="rpt-row-info">
                  <span class="rpt-row-name">Тесто {{ s.short_name }}</span>
                  <span class="rpt-row-hint">в лотке {{ s.per_tray }} шт</span>
                </div>
                <div class="rpt-row-right">
                  <!-- Общая сумма по размеру нужна только когда партий две:
                       при одной партии её место занимают штуки над полем. -->
                  <span v-if="batches.length > 1" class="rpt-row-pieces" :class="{ 'is-on': sizeTrays(s) > 0 }">
                    {{ sizeTrays(s) > 0 ? (sizeTrays(s) * s.per_tray) + ' шт' : '' }}
                  </span>
                  <div class="rpt-batches">
                    <div v-for="b in batches" :key="b.batch_no" class="rpt-batch">
                      <!-- Подпись над полем есть всегда, даже пустая: иначе строка
                           подпрыгивает, как только набрали первый лоток. -->
                      <span class="rpt-batch-lbl">
                        <template v-if="batches.length > 1">{{ b.batch_no }}-я партия</template>
                        <b v-if="batchPieces(s, b.batch_no) > 0" class="rpt-batch-pieces">
                          {{ batchPieces(s, b.batch_no) }} шт
                        </b>
                        <em v-if="b.label">{{ b.label }}</em>
                      </span>
                      <!-- Приём закрыт — заявка только для чтения. Показываем
                           числа, а не серые мёртвые поля с кнопками. -->
                      <div v-if="locked" class="rpt-qty-static"
                           :class="{ 'is-on': trays[s.sku]?.[b.batch_no] > 0 }">
                        <template v-if="trays[s.sku]?.[b.batch_no] > 0">
                          <b>{{ trays[s.sku][b.batch_no] }}</b><span class="rpt-qty-u">лотк.</span>
                        </template>
                        <span v-else class="rpt-qty-none">—</span>
                      </div>
                      <div v-else class="rpt-qty">
                        <button type="button" class="rpt-step" tabindex="-1"
                          :disabled="!(trays[s.sku]?.[b.batch_no] > 0)"
                          @click="step(s, b.batch_no, -1)" aria-label="Убавить">−</button>
                        <label class="rpt-qty-box" :class="{ 'is-on': trays[s.sku]?.[b.batch_no] > 0 }">
                          <input type="number" class="rpt-input" v-model.number="trays[s.sku][b.batch_no]"
                            min="0" step="1" inputmode="numeric"
                            placeholder="—" @focus="$event.target.select()" />
                          <span class="rpt-qty-u">лотк.</span>
                        </label>
                        <button type="button" class="rpt-step" tabindex="-1"
                          @click="step(s, b.batch_no, 1)" aria-label="Добавить">+</button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <aside class="rpt-aside">
              <div class="rpt-sum">
                <div class="rpt-sum-row"><span>Лотков</span><b>{{ totalTrays }}</b></div>
                <div class="rpt-sum-row"><span>Штук теста</span><b>{{ totalPieces }}</b></div>
                <div class="rpt-sum-row"><span>Стопок приедет</span><b>{{ stacks }}</b></div>
                <div class="rpt-sum-row rpt-sum-dim">
                  <span>Заполнено</span><b>{{ filledCount }} из {{ sizes.length }}</b>
                </div>
              </div>

              <div v-if="submitError" class="rpt-error">{{ submitError }}</div>

              <div v-if="locked" class="rpt-locked">
                Приём заявок на этот день закрыт. Для изменений обратитесь в отдел закупок.
              </div>
              <div v-else class="rpt-actions">
                <button type="button" class="rpt-btn rpt-btn-primary"
                  :disabled="submitting || totalTrays === 0" @click="submit(false)">
                  <span v-if="submitting" class="rpt-spin rpt-spin-sm"></span>
                  {{ savedStatus && !isSkipSaved ? 'Обновить заявку' : 'Отправить заявку' }}
                </button>
                <button type="button" class="rpt-btn" :disabled="submitting" @click="submit(true)">
                  Тесто не нужно
                </button>
              </div>
            </aside>
          </div>
        </template>
      </div>
    </template>
  </div>
</template>

<script setup>
/**
 * Заказ теста в кабинете ресторана «Пицца Стар».
 *
 * Отдельный пункт в разделе «Заказы»: цех ПРЦ — не обычный поставщик, у него
 * свои единицы. Ресторан думает лотками (в лотке 10, 7 или 6 штук в
 * зависимости от размера), а в заявку уходят штуки — так их ждёт вся
 * остальная логика заявок и загрузочные листы.
 */
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { useToastStore } from '@/stores/toastStore.js';

// Подтверждение берём из кабинета: там своя модалка в общем стиле,
// вторая такая же в дочернем компоненте была бы лишней.
const props = defineProps({
  confirmFn: { type: Function, default: null },
});

const toast = useToastStore();
async function showConfirm(title, message, opts) {
  if (!props.confirmFn) return true;
  return await props.confirmFn(title, message, opts);
}

const loading = ref(true);
const error = ref('');
const workshop = ref(null);
// Пауза приёма заявок цехом — тот же выключатель, что у обычных поставщиков.
const accepting = ref(true);
const pauseMessage = ref('');
const sizes = ref([]);
const dates = ref([]);
const selectedDate = ref('');
const orderLoading = ref(false);
const submitting = ref(false);
const submitError = ref('');
const savedStatus = ref(null);
const isSkipSaved = ref(false);
const trays = reactive({});
// Снимок набранного после загрузки: по нему видно, что человек что-то ввёл и
// ещё не отправил — тогда переключение дня спрашивает подтверждение.
const traysSnapshot = ref('');
const traysPerStack = ref(22);
const orderState = reactive({});   // дата → { status, isSkip }
const timeLeft = ref('');
let timer = null;

const WEEKDAYS = ['вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'];

function api(path, opts = {}) {
  const headers = { 'Content-Type': 'application/json' };
  const st = localStorage.getItem('bk_session_token');
  if (st) headers['X-Session-Token'] = st;
  return fetch(`/api/own-production/${path}`, { headers, ...opts }).then(async res => {
    const text = await res.text();
    let data = {};
    try { data = text ? JSON.parse(text) : {}; } catch {
      throw new Error(`Ошибка сервера (${res.status})`);
    }
    if (!res.ok) throw new Error(data.error || `Ошибка сервера (${res.status})`);
    return data;
  });
}

const currentDate = computed(() => dates.value.find(d => d.date === selectedDate.value) || null);
const locked = computed(() => !!currentDate.value?.is_closed);
// Партии дня: одна у всех, две — у ресторанов с одной поставкой в неделю.
const batches = computed(() => currentDate.value?.batches?.length
  ? currentDate.value.batches
  : [{ batch_no: 1, label: '' }]);

/** Сколько всего лотков этого размера — по всем партиям. */
function sizeTrays(size) {
  const row = trays[size.sku] || {};
  return batches.value.reduce((sum, b) => sum + (Number(row[b.batch_no]) || 0), 0);
}

/** Штуки одной партии — подпись над её полем ввода. */
function batchPieces(size, batchNo) {
  return (Number(trays[size.sku]?.[batchNo]) || 0) * size.per_tray;
}

const filledCount = computed(() => sizes.value.filter(s => sizeTrays(s) > 0).length);
const totalTrays = computed(() => sizes.value.reduce((sum, s) => sum + sizeTrays(s), 0));
const totalPieces = computed(() => sizes.value.reduce(
  (sum, s) => sum + sizeTrays(s) * s.per_tray, 0));
// Стопку считаем по той же норме, что в загрузочных листах. Ресторану полезно
// видеть, сколько стопок приедет: столько мест выгружать.
const stacks = computed(() => Math.ceil(totalTrays.value / traysPerStack.value) || 0);

function fmtShort(d) { const p = String(d).split('-'); return `${p[2]}.${p[1]}`; }
function fmtFull(d) { const p = String(d).split('-'); return `${p[2]}.${p[1]}.${p[0]}`; }
function dayName(d) { return WEEKDAYS[new Date(d + 'T00:00:00').getDay()]; }

/** «2026-08-05 15:00» → «05.08 в 15:00» — так читается быстрее. */
function fmtDeadline(str) {
  const m = String(str).match(/(\d{4})-(\d{2})-(\d{2})[ T](\d{2}:\d{2})/);
  return m ? `${m[3]}.${m[2]} в ${m[4]}` : String(str);
}

function step(size, batchNo, dir) {
  if (!trays[size.sku]) trays[size.sku] = {};
  const cur = Number(trays[size.sku][batchNo]) || 0;
  const next = Math.max(0, cur + dir);
  trays[size.sku][batchNo] = next === 0 ? null : next;
}

/** Пустая сетка «размер → партия»: v-model нужен готовый объект. */
function resetTrays() {
  for (const s of sizes.value) {
    trays[s.sku] = {};
    for (const b of batches.value) trays[s.sku][b.batch_no] = null;
  }
}

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const data = await api('my-form');
    workshop.value = data.workshop || null;
    accepting.value = data.accepting !== false;
    pauseMessage.value = data.pause_message || '';
    traysPerStack.value = Number(data.trays_per_stack) || 22;
    sizes.value = data.sizes || [];
    dates.value = data.dates || [];
    if (!workshop.value) return;
    // Статусы дней — чтобы на плашках сразу было видно, где заявка есть.
    await Promise.all(dates.value.map(async d => {
      try {
        const o = await api(`my-order?supplier_id=${encodeURIComponent(workshop.value.id)}&date=${d.date}`);
        orderState[d.date] = {
          status: o.status || null,
          isSkip: !!o.status && Object.keys(o.items || {}).length === 0,
        };
      } catch { orderState[d.date] = { status: null, isSkip: false }; }
    }));
    const firstOpen = dates.value.find(d => !d.is_closed) || dates.value[0];
    if (firstOpen) await selectDate(firstOpen.date);
  } catch (e) {
    error.value = e.message || 'Не удалось загрузить';
  } finally {
    loading.value = false;
  }
}

/** Есть ли несохранённое: сравниваем текущие лотки со снимком после загрузки. */
function isDirty() {
  return traysSnapshot.value !== '' && JSON.stringify(trays) !== traysSnapshot.value;
}

async function selectDate(date) {
  if (date !== selectedDate.value && isDirty()) {
    const ok = await showConfirm(
      'Заявка не отправлена',
      `Набранное на ${fmtFull(selectedDate.value)} не сохранится. Перейти на другой день?`,
      { okText: 'Перейти', cancelText: 'Остаться', danger: true },
    );
    if (!ok) return;
  }
  selectedDate.value = date;
  submitError.value = '';
  orderLoading.value = true;
  resetTrays();
  try {
    const o = await api(`my-order?supplier_id=${encodeURIComponent(workshop.value.id)}&date=${date}`);
    savedStatus.value = o.status || null;
    const items = o.items || {};
    const saved = o.batches || {};
    isSkipSaved.value = !!o.status && Object.keys(items).length === 0;
    for (const s of sizes.value) {
      // Сервер всегда раскладывает заявку по партиям (у старых номер 1),
      // поэтому берём только их. Подстановка общей суммы в первую партию
      // задваивала позицию, заказанную одной второй партией.
      const row = saved[s.sku] || {};
      for (const b of batches.value) {
        const pieces = Number(row[b.batch_no]) || 0;
        trays[s.sku][b.batch_no] = pieces > 0 ? Math.round(pieces / s.per_tray) : null;
      }
    }
    orderState[date] = { status: savedStatus.value, isSkip: isSkipSaved.value };
  } catch (e) {
    submitError.value = e.message;
  } finally {
    orderLoading.value = false;
    traysSnapshot.value = JSON.stringify(trays);
  }
  startTimer();
}

async function submit(skip) {
  if (skip) {
    const ok = await showConfirm(
      'Тесто не нужно',
      `Подтвердить, что на ${fmtFull(selectedDate.value)} тесто не нужно?`,
      { okText: 'Не нужно', danger: true },
    );
    if (!ok) return;
  }
  submitting.value = true;
  submitError.value = '';
  try {
    const items = {};
    if (!skip) {
      for (const s of sizes.value) {
        const byBatch = {};
        for (const b of batches.value) {
          const t = Number(trays[s.sku]?.[b.batch_no]) || 0;
          if (t > 0) byBatch[b.batch_no] = t * s.per_tray;
        }
        if (Object.keys(byBatch).length) items[s.sku] = byBatch;
      }
    }
    const trayCount = totalTrays.value;
    await api('submit', {
      method: 'POST',
      body: JSON.stringify({
        supplier_id: workshop.value.id,
        delivery_date: selectedDate.value,
        items,
      }),
    });
    savedStatus.value = 'submitted';
    isSkipSaved.value = skip;
    orderState[selectedDate.value] = { status: 'submitted', isSkip: skip };
    if (skip) resetTrays();
    traysSnapshot.value = JSON.stringify(trays);
    toast.success(
      skip ? 'Отмечено' : 'Заявка отправлена',
      skip ? `На ${fmtFull(selectedDate.value)} тесто не нужно`
           : `${trayCount} лотк. на ${fmtFull(selectedDate.value)}`,
    );
  } catch (e) {
    submitError.value = e.message || 'Не удалось отправить';
  } finally {
    submitting.value = false;
  }
}

// Обратный отсчёт до дедлайна — как у обычных поставщиков.
function startTimer() {
  if (timer) clearInterval(timer);
  timeLeft.value = '';
  const str = currentDate.value?.deadline_str;
  if (!str || currentDate.value?.is_closed) return;
  const tick = () => {
    const target = parseDeadline(str);
    if (!target) { timeLeft.value = ''; return; }
    const diff = target - Date.now();
    if (diff <= 0) { timeLeft.value = 'истекло'; return; }
    const d = Math.floor(diff / 86400000);
    const h = Math.floor((diff % 86400000) / 3600000);
    const m = Math.floor((diff % 3600000) / 60000);
    if (d > 0) timeLeft.value = `${d} дн ${h} ч`;
    else timeLeft.value = h > 0 ? `${h} ч ${m} мин` : `${m} мин`;
  };
  tick();
  timer = setInterval(tick, 30000);
}

/** «2026-08-05 15:00» или «05.08 в 15:00» → метка времени. */
function parseDeadline(str) {
  let m = String(str).match(/(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
  if (m) return new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]).getTime();
  m = String(str).match(/(\d{2})\.(\d{2})(?:\.(\d{4}))?\D+(\d{1,2}):(\d{2})/);
  if (m) {
    const year = m[3] ? +m[3] : new Date().getFullYear();
    return new Date(year, +m[2] - 1, +m[1], +m[4], +m[5]).getTime();
  }
  return null;
}

onMounted(load);
onBeforeUnmount(() => { if (timer) clearInterval(timer); });
</script>

<style scoped>
/* Свои стили: классы кабинета живут в scoped-стилях его view и до сюда
   не доходят. Палитра та же — кремовый фон, коричневый текст, оранжевый акцент. */
.rpt { --rpt-brown: #4A2013; --rpt-accent: #E76F51; --rpt-green: #2E7D32;
       --rpt-line: #E8DCCA; --rpt-cream: #FFF8ED; --rpt-dim: #8B7355; }

.rpt-center { display: flex; justify-content: center; padding: 48px 0; }
.rpt-spin { width: 26px; height: 26px; border: 3px solid #EFE3D3; border-top-color: var(--rpt-accent);
            border-radius: 50%; animation: rpt-rot .8s linear infinite; }
.rpt-spin-sm { width: 14px; height: 14px; border-width: 2px; }
@keyframes rpt-rot { to { transform: rotate(360deg); } }

.rpt-empty { background: #fff; border: 1px solid var(--rpt-line); border-radius: 14px;
             padding: 32px 24px; text-align: center; color: var(--rpt-dim); }
.rpt-empty h2 { margin: 0 0 8px; font-size: 18px; color: var(--rpt-brown); }
.rpt-empty p { margin: 4px 0; font-size: 14px; }
.rpt-empty-hint { font-size: 12px; color: #B0A090; }

/* ── Дни поставки ── */
/* Метка «подано» вылезает за угол кнопки, а горизонтальная прокрутка режет
   всё, что выходит за края. Верхний и правый отступы дают ей место. */
.rpt-days { display: flex; gap: 8px; overflow-x: auto; margin-top: -6px; padding: 8px 6px 8px;
            -webkit-overflow-scrolling: touch; scrollbar-width: none; }
.rpt-days::-webkit-scrollbar { display: none; }
.rpt-day { position: relative; flex: 0 0 auto; min-width: 78px; padding: 9px 12px 8px;
           background: #fff; border: 1.5px solid var(--rpt-line); border-radius: 12px;
           cursor: pointer; text-align: center; transition: .15s; }
.rpt-day:hover { border-color: #D9C4A9; }
.rpt-day-name { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase;
                letter-spacing: .04em; color: var(--rpt-dim); }
.rpt-day-date { display: block; font-size: 15px; font-weight: 800; color: var(--rpt-brown); }
.rpt-day.is-done { border-color: #A5D6A7; background: #F4FAF2; }
.rpt-day.is-skip { border-color: #E0D5C5; background: #F7F3EC; }
.rpt-day.is-closed { opacity: .55; }
.rpt-day.is-active { border-color: var(--rpt-accent); background: var(--rpt-accent); box-shadow: 0 4px 12px rgba(231,111,81,.25); }
.rpt-day.is-active .rpt-day-name { color: rgba(255,255,255,.85); }
.rpt-day.is-active .rpt-day-date { color: #fff; }
.rpt-day-mark { position: absolute; top: -6px; right: -4px; min-width: 17px; height: 17px;
                padding: 0 4px; border-radius: 9px; font-size: 11px; font-weight: 800; line-height: 17px;
                color: #fff; background: var(--rpt-dim); }
.rpt-day-mark.is-done { background: var(--rpt-green); }
.rpt-day-mark.is-skip { background: #9E8B78; }
.rpt-day-mark.is-closed { display: flex; align-items: center; justify-content: center; padding: 0 3px; }
.rpt-day-mark.is-closed svg { width: 11px; height: 11px; }

/* ── Шапка дня ── */
.rpt-hero { display: flex; align-items: center; gap: 16px; margin: 4px 0 14px;
            padding: 16px 18px; border-radius: 16px; color: #fff;
            background: linear-gradient(135deg, #5C2A18 0%, #3A180E 100%); }
.rpt-hero.is-closed { background: linear-gradient(135deg, #6E6055 0%, #4C4239 100%); }
.rpt-hero-main { flex: 1; min-width: 0; }
.rpt-hero-title { font-size: 18px; font-weight: 800; letter-spacing: -.01em; }
.rpt-hero-sub { margin-top: 2px; font-size: 14px; font-weight: 600; color: rgba(255,255,255,.9); }
.rpt-hero-note { margin-top: 6px; font-size: 12px; color: rgba(255,255,255,.72); }
.rpt-hero-timer { flex: 0 0 auto; text-align: right; }
.rpt-hero-timer-v { font-size: 17px; font-weight: 800; font-variant-numeric: tabular-nums; white-space: nowrap; }
.rpt-hero-timer-l { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: rgba(255,255,255,.7); }
.rpt-hero-lock { flex: 0 0 auto; padding: 6px 12px; border-radius: 999px; font-size: 12px;
                 font-weight: 700; background: rgba(255,255,255,.16); }

.rpt-skip-banner { display: flex; flex-wrap: wrap; gap: 6px; align-items: baseline;
                   margin-bottom: 12px; padding: 11px 14px; border-radius: 12px;
                   background: #F7F3EC; border: 1px solid #E0D5C5; font-size: 13px; color: #6B5344; }
.rpt-skip-banner strong { color: var(--rpt-brown); }

/* ── Список размеров + сводка ── */
.rpt-cols { display: grid; grid-template-columns: 1fr 280px; gap: 16px; align-items: start; }
.rpt-list { display: flex; flex-direction: column; gap: 8px; }
.rpt-row { display: flex; align-items: center; justify-content: space-between; gap: 12px;
           padding: 12px 14px; background: #fff; border: 1.5px solid var(--rpt-line);
           border-radius: 14px; transition: .15s; }
.rpt-row.is-filled { border-color: #A5D6A7; background: #FBFEF9; }
.rpt-row-info { min-width: 0; }
.rpt-row-name { display: block; font-size: 15px; font-weight: 700; color: var(--rpt-brown); }
.rpt-row-hint { display: block; margin-top: 2px; font-size: 12px; color: var(--rpt-dim); }
/* Низ по низу: над полями есть подпись партии, и при выравнивании по центру
   общая сумма штук вставала выше полей ввода. */
.rpt-row-right { display: flex; align-items: flex-end; gap: 12px; flex: 0 0 auto; }
.rpt-row-pieces {
  display: flex; align-items: center; justify-content: flex-end;
  min-width: 56px; height: 38px; text-align: right;
  font-size: 13px; font-weight: 700; color: #C4B5A3;
}
.rpt-row-pieces.is-on { color: var(--rpt-green); }

.rpt-qty { display: flex; align-items: center; gap: 6px; }
.rpt-step { width: 34px; height: 38px; border: 1.5px solid var(--rpt-line); border-radius: 10px;
            background: var(--rpt-cream); font-size: 19px; font-weight: 700; color: var(--rpt-brown);
            cursor: pointer; line-height: 1; }
.rpt-step:hover:not(:disabled) { border-color: var(--rpt-accent); color: var(--rpt-accent); }
/* Недоступная кнопка не должна выглядеть залитым квадратом: снимаем фон,
   оставляем бледный контур — строка читается как поле, а не как плитка. */
.rpt-step:disabled {
  background: transparent; border-color: #EFE6DA; color: #D8CCBD; cursor: not-allowed;
}
/* Само число стоит ровно по центру ячейки, а «лотк.» прижата к правому краю
   отдельно: если центрировать пару целиком, цифра всегда левее середины. */
.rpt-qty-box { position: relative; display: flex; align-items: center; justify-content: center;
               /* Ширина фиксированная: поле внутри тянется на 100%, и без
                  жёсткого размера ячейка распирала всю строку. */
               flex: 0 0 116px; width: 116px; box-sizing: border-box;
               padding: 0 34px 0 8px; height: 38px;
               border: 1.5px solid var(--rpt-line); border-radius: 10px; background: #fff; }
.rpt-qty-box .rpt-qty-u { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); }
.rpt-qty-box.is-on { border-color: var(--rpt-green); background: #F4FAF2; }
.rpt-input { width: 100%; border: 0; background: transparent; outline: none;
             text-align: center; padding: 0;
             font-size: 17px; font-weight: 800; color: var(--rpt-brown); height: 38px;
             -moz-appearance: textfield; }
.rpt-input::-webkit-outer-spin-button, .rpt-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.rpt-qty-u { font-size: 11px; font-weight: 700; color: var(--rpt-dim); }
/* Закрытая заявка: рамка как у поля ввода, чтобы числа читались таблицей,
   а не висели в воздухе. Отличие от открытой формы — приглушённый фон и
   отсутствие кнопок «−» и «+». */
/* Закрытая заявка — та же сетка, что и в форме: число по центру, «лотк.»
   отдельной меткой справа. Ширина совпадает с полем ввода. */
.rpt-qty-static {
  position: relative; display: flex; align-items: center; justify-content: center;
  /* flex-basis тут задавать нельзя: блок лежит в колоночном контейнере, и
     116px уходили в ВЫСОТУ — ячейка вытягивалась квадратом. */
  flex: 0 0 auto; width: 116px; box-sizing: border-box; height: 38px; padding: 0 34px 0 8px;
  border: 1.5px solid var(--rpt-line); border-radius: 10px; background: #FCFAF7;
}
.rpt-qty-static .rpt-qty-u { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); }
.rpt-qty-static.is-on { border-color: #C6E3C0; background: #F7FBF5; }
.rpt-qty-static b { font-size: 17px; font-weight: 800; color: var(--rpt-brown); }
.rpt-qty-static.is-on b { color: var(--rpt-green); }
.rpt-qty-none { font-size: 15px; color: #D8CCBD; align-self: center; }

.rpt-aside { position: sticky; top: 12px; display: flex; flex-direction: column; gap: 10px; }
.rpt-sum { background: #fff; border: 1.5px solid var(--rpt-line); border-radius: 14px; padding: 12px 14px; }
.rpt-sum-row { display: flex; justify-content: space-between; align-items: baseline; gap: 8px;
               padding: 5px 0; font-size: 13px; color: var(--rpt-dim); }
.rpt-sum-row + .rpt-sum-row { border-top: 1px dashed #F0E7DA; }
.rpt-sum-row b { font-size: 17px; font-weight: 800; color: var(--rpt-brown); font-variant-numeric: tabular-nums; }
.rpt-sum-dim b { font-size: 13px; font-weight: 700; color: var(--rpt-dim); }

.rpt-actions { display: flex; flex-direction: column; gap: 8px; }
.rpt-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px;
           min-height: 46px; padding: 0 18px; border: 1.5px solid var(--rpt-line); border-radius: 12px;
           background: #fff; font-size: 14px; font-weight: 700; color: var(--rpt-brown);
           cursor: pointer; transition: .15s; }
.rpt-btn:hover:not(:disabled) { border-color: #D9C4A9; background: var(--rpt-cream); }
.rpt-btn:disabled { opacity: .5; cursor: not-allowed; }
.rpt-btn-primary { border-color: transparent; color: #fff; font-size: 15px;
                   background: linear-gradient(135deg, #E76F51 0%, #D65A3C 100%);
                   box-shadow: 0 4px 14px rgba(231,111,81,.3); }
.rpt-btn-primary:hover:not(:disabled) { background: linear-gradient(135deg, #D65A3C 0%, #C24E32 100%); }

.rpt-error { padding: 10px 12px; border-radius: 10px; background: #FDECEA; border: 1px solid #F5C6C1;
             color: #C0392B; font-size: 13px; font-weight: 600; }
.rpt-locked { padding: 11px 13px; border-radius: 12px; background: #F7F3EC; border: 1px solid #E0D5C5;
              color: #6B5344; font-size: 13px; }

@media (max-width: 900px) {
  .rpt-cols { grid-template-columns: 1fr; }
  .rpt-aside { position: static; }
  .rpt-hero { flex-wrap: wrap; gap: 10px; padding: 14px; }
  .rpt-hero-timer { text-align: left; }
  .rpt-row { flex-wrap: wrap; }
  .rpt-row-right { width: 100%; justify-content: space-between; }
  .rpt-input { width: 100%; }
}

.rpt-batches { display: flex; align-items: flex-end; gap: 10px; }
.rpt-batch { display: flex; flex-direction: column; gap: 4px; }
.rpt-batch-lbl {
  display: block; min-height: 14px; font-size: 11px; font-weight: 700;
  color: var(--rpt-dim); white-space: nowrap; text-align: right;
}
.rpt-batch-lbl em { display: block; font-style: normal; font-weight: 600; font-size: 10px; color: #B0A090; }
/* Штуки набранной партии: тот же мелкий кегль, но цветом «набрано». */
.rpt-batch-pieces { margin-left: 5px; font-weight: 800; color: var(--rpt-green); }
@media (max-width: 560px) {
  /* Итог в штуках уводим под название, иначе он повисает сбоку от двух
     полей ввода и мешает их читать. */
  .rpt-row { padding: 10px 12px; gap: 6px; }
  .rpt-row-right { flex-direction: column; align-items: stretch; gap: 6px; }
  .rpt-row-pieces { justify-content: flex-start; text-align: left; min-width: 0; height: auto; }
  .rpt-row-pieces:empty { display: none; }
  .rpt-batches { flex-direction: column; align-items: stretch; width: 100%; gap: 8px; }
  /* Подпись во всю строку: слева партия, справа штуки — иначе цифры
     жались влево, а справа висело пустое место. */
  .rpt-batch-lbl { display: flex; justify-content: space-between; gap: 8px; text-align: left; min-height: 0; }
  .rpt-batch-lbl:empty { display: none; }
  .rpt-batch-pieces { margin-left: 0; }
  .rpt-batch-lbl em { display: inline; margin-left: 4px; }
  /* Поле растягивается на всю ширину между «−» и «+». */
  .rpt-qty-box { flex: 1 1 auto; width: auto; justify-content: center; }
  .rpt-qty-static { flex: 1 1 auto; width: auto; min-width: 0; }
}
</style>
