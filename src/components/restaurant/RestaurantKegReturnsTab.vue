<template>
  <div class="krt-wrap">
    <div class="krt-header">
      <h2 class="krt-title">Возврат кег</h2>
      <button v-if="!formMode" class="btn primary" @click="openNew">+ Новая заявка</button>
    </div>

    <!-- Список -->
    <template v-if="!formMode">
      <div v-if="listLoading" class="krt-empty">Загрузка...</div>
      <div v-else-if="listError" class="krt-error">{{ listError }}</div>
      <template v-else>
        <div v-if="!rows.length" class="krt-empty">Нет заявок на возврат кег</div>
        <template v-else>
          <div class="krt-filter-chips">
            <button
              v-for="f in statusFilters"
              :key="f.key"
              class="krt-chip"
              :class="{ active: statusFilter === f.key }"
              @click="statusFilter = f.key"
            >
              {{ f.label }}
              <span v-if="f.count" class="krt-chip-count">{{ f.count }}</span>
            </button>
          </div>
          <div v-if="!filteredRows.length" class="krt-empty">В этой категории заявок нет</div>
          <div v-else class="krt-list">
            <div v-for="row in filteredRows" :key="row.id" class="krt-row" @click="openEdit(row)">
              <div class="krt-row-main">
                <span class="krt-row-date">{{ fmtDate(row.return_date) }}</span>
                <span :class="'krt-badge krt-badge-' + row.status">{{ statusLabel(row.status) }}</span>
                <span v-if="row.total_kegs" class="krt-row-kegs">{{ row.total_kegs }} кег</span>
              </div>
              <div v-if="row.bso_series || row.bso_number || row.driver" class="krt-row-sub">
                <span v-if="row.bso_series || row.bso_number">БСО: {{ row.bso_series }} {{ row.bso_number }}</span>
                <span v-if="row.driver"> · {{ row.driver }}</span>
              </div>
            </div>
          </div>
        </template>
      </template>
    </template>

    <!-- Форма создания / редактирования -->
    <template v-else>
      <div class="krt-form-header">
        <button class="btn" @click="backToList">← Назад</button>
        <h3 class="krt-form-title">{{ editingId ? 'Заявка #' + editingId : 'Новая заявка' }}</h3>
      </div>

      <div v-if="formLoading" class="krt-empty">Загрузка...</div>
      <div v-else-if="formError" class="krt-error">{{ formError }}</div>

      <div v-else class="krt-form">
        <div class="krt-field">
          <span class="krt-label">Дата возврата</span>
          <div style="flex:1;display:flex;flex-direction:column;gap:4px">
            <select v-if="availableDates.length" v-model="form.return_date" :disabled="formReadonly || deadlinePassed" class="krt-input" @change="validateWeekday">
              <option value="">— выберите дату —</option>
              <option v-for="d in availableDates" :key="d.iso" :value="d.iso">{{ d.label }}</option>
            </select>
            <template v-else-if="!editingId">
              <span v-if="restaurantInfoLoaded && !availableDates.length" class="krt-hint">Дни возврата не настроены — обратитесь в отдел закупок</span>
              <span v-else-if="!restaurantInfoLoaded" class="krt-hint">Загрузка...</span>
            </template>
            <input v-else v-model="form.return_date" type="date" :disabled="formReadonly || deadlinePassed" class="krt-input" @change="validateWeekday" />
            <span v-if="weekdayError" class="krt-field-error">{{ weekdayError }}</span>
          </div>
        </div>

        <!-- Countdown до дедлайна -->
        <div v-if="deadlineIso && (form.status === 'DRAFT' || form.status === 'SUBMITTED')" class="krt-deadline-wrap">
          <span v-if="!deadlinePassed" class="krt-deadline-countdown">
            До дедлайна редактирования: <strong>{{ countdownStr }}</strong>
          </span>
          <span v-else class="krt-deadline-passed">Дедлайн прошёл — редактирование недоступно</span>
        </div>

        <div class="krt-field">
          <span class="krt-label">Серия / Номер БСО</span>
          <div style="flex:1;display:flex;flex-direction:column;gap:4px">
            <div class="krt-row-inputs">
              <input v-model="form.bso_series" type="text" maxlength="2" placeholder="АА" :disabled="formReadonly" class="krt-input krt-input-sm" @blur="validateBso" />
              <input v-model="form.bso_number" type="text" placeholder="0000000" :disabled="formReadonly" class="krt-input" @blur="validateBso" />
            </div>
            <span v-if="bsoError" class="krt-field-error">{{ bsoError }}</span>
          </div>
        </div>

        <div class="krt-field">
          <span class="krt-label">Сдал грузоотправитель</span>
          <div style="flex:1;display:flex;flex-direction:column;gap:2px">
            <input v-model="form.sender_position_name" type="text" :disabled="formReadonly" class="krt-input" placeholder="Управляющий рестораном Иванов И.И." />
            <span class="krt-hint">должность, фамилия, инициалы</span>
          </div>
        </div>

        <template v-if="form.status && form.status !== 'DRAFT'">
          <div class="krt-field">
            <span class="krt-label">Машина</span>
            <span class="krt-value">{{ form.vehicle || '—' }}</span>
          </div>
          <div class="krt-field">
            <span class="krt-label">Водитель</span>
            <span class="krt-value">{{ form.driver || '—' }}</span>
          </div>
        </template>

        <div class="krt-field">
          <span class="krt-label">Статус</span>
          <span v-if="form.status" :class="'krt-badge krt-badge-' + form.status">{{ statusLabel(form.status) }}</span>
          <span v-else class="krt-value">Черновик</span>
        </div>

        <div class="krt-kegs">
          <div class="krt-label">Кеги</div>
          <div v-if="catalogLoading" class="krt-empty">Загрузка каталога...</div>
          <div v-else class="krt-catalog">
            <div v-for="keg in catalog" :key="keg.code" class="krt-keg-row">
              <span class="krt-keg-name">{{ keg.name }}</span>
              <input
                type="number"
                min="0"
                inputmode="numeric"
                :value="kegQty(keg.code)"
                @change="setKegQty(keg.code, $event.target.value)"
                :disabled="formReadonly"
                class="krt-qty"
              />
            </div>
            <div class="krt-kegs-total" :class="{ empty: totalKegsCount === 0 }">
              <span v-if="totalKegsCount === 0">Кеги не указаны</span>
              <span v-else>Всего: <strong>{{ totalKegsCount }} кег</strong> в {{ totalKegsTypes }} {{ pluralTypes(totalKegsTypes) }}</span>
            </div>
          </div>
        </div>

        <div v-if="saveError" class="krt-error">{{ saveError }}</div>

        <div class="krt-actions">
          <template v-if="!formReadonly">
            <button class="btn" @click="saveDraft" :disabled="saving">
              {{ saving ? 'Сохранение...' : 'Сохранить черновик' }}
            </button>
            <button
              class="btn primary"
              @click="submit"
              :disabled="saving || totalKegsCount === 0"
              :title="totalKegsCount === 0 ? 'Укажите количество кег' : ''"
            >
              Сформировать ТТН
            </button>
            <button v-if="editingId && form.status === 'SUBMITTED'" class="btn btn-danger" @click="cancelReturn" :disabled="saving">
              Отменить
            </button>
            <button v-if="editingId && form.status === 'DRAFT'" class="btn btn-danger" @click="deleteDraft" :disabled="saving">
              Удалить черновик
            </button>
          </template>
          <button v-if="editingId" class="btn" @click="downloadExcel" :disabled="saving">
            Скачать Excel
          </button>
          <button v-if="editingId" class="btn primary" @click="printTtn" :disabled="saving">
            🖨️ Печать
          </button>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useRoute } from 'vue-router';

const TOKEN_KEY = 'ro_token';
const route = useRoute();

function buildHeaders(json = false) {
  const h = {};
  const t = localStorage.getItem(TOKEN_KEY);
  if (t) h['X-RO-Token'] = t;
  if (json) h['Content-Type'] = 'application/json';
  return h;
}

const rows = ref([]);
const statusFilter = ref('all');
const STATUS_FILTER_DEFS = [
  { key: 'all', label: 'Все', match: () => true },
  { key: 'DRAFT', label: 'Черновики', match: r => r.status === 'DRAFT' },
  { key: 'SUBMITTED', label: 'Отправлены', match: r => r.status === 'SUBMITTED' },
  { key: 'ROUTED', label: 'Маршрутизированы', match: r => r.status === 'ROUTED' },
  { key: 'CANCELLED', label: 'Отменены', match: r => r.status === 'CANCELLED' },
];
const statusFilters = computed(() => STATUS_FILTER_DEFS.map(f => ({
  key: f.key,
  label: f.label,
  count: f.key === 'all' ? rows.value.length : rows.value.filter(f.match).length,
})));
const filteredRows = computed(() => {
  const def = STATUS_FILTER_DEFS.find(f => f.key === statusFilter.value) || STATUS_FILTER_DEFS[0];
  return rows.value.filter(def.match);
});
const catalog = ref([]);
const listLoading = ref(false);
const listError = ref('');
const formMode = ref(false);
const editingId = ref(null);
const form = ref({});
const kegQties = ref({});
const formLoading = ref(false);
const formError = ref('');
const catalogLoading = ref(false);
const saving = ref(false);
const saveError = ref('');
const bsoError = ref('');
const weekdayError = ref('');
const restaurantInfoLoaded = ref(false);

// Дедлайн и countdown
const deadlineIso = ref(null);
const countdownStr = ref('');
const deadlinePassed = ref(false);
let countdownTimer = null;

function startCountdown() {
  stopCountdown();
  if (!deadlineIso.value) return;
  function tick() {
    const now = Date.now();
    const dl = new Date(deadlineIso.value).getTime();
    const diff = dl - now;
    if (diff <= 0) {
      deadlinePassed.value = true;
      countdownStr.value = '00:00:00';
      return;
    }
    deadlinePassed.value = false;
    const h = Math.floor(diff / 3600000);
    const m = Math.floor((diff % 3600000) / 60000);
    const s2 = Math.floor((diff % 60000) / 1000);
    countdownStr.value = String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s2).padStart(2, '0');
  }
  tick();
  countdownTimer = setInterval(tick, 1000);
}

function stopCountdown() {
  if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
}

onUnmounted(stopCountdown);

const WEEKDAY_NAMES = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

// Дедлайн = 10:00 последнего рабочего дня (пн-пт) перед return_date в Europe/Minsk.
function calcDeadlineMs(isoDate) {
  const [Y, M, D] = isoDate.split('-').map(Number);
  const d = new Date(Y, M - 1, D, 10, 0, 0, 0);
  do { d.setDate(d.getDate() - 1); } while (d.getDay() === 0 || d.getDay() === 6);
  return d.getTime();
}

const availableDates = computed(() => {
  const mask = parseInt(form.value?.restaurant_pickup_weekdays || 0);
  if (!mask) return [];
  const out = [];
  const today = new Date();
  today.setHours(12, 0, 0, 0);
  const now = Date.now();
  for (let i = 0; i < 60 && out.length < 3; i++) {
    const d = new Date(today);
    d.setDate(d.getDate() + i);
    const jsDay = d.getDay();
    const bit = jsDay === 0 ? 6 : jsDay - 1;
    if (!(mask & (1 << bit))) continue;
    const iso = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    if (calcDeadlineMs(iso) <= now) continue; // дедлайн уже прошёл
    const label = String(d.getDate()).padStart(2, '0') + '.' + String(d.getMonth() + 1).padStart(2, '0') + '.' + d.getFullYear() + ' (' + WEEKDAY_NAMES[bit] + ')';
    out.push({ iso, label });
  }
  return out;
});

function validateWeekday() {
  weekdayError.value = '';
  const date = form.value.return_date;
  const mask = form.value.restaurant_pickup_weekdays;
  if (!date || !mask) return;
  // Date.getDay(): 0=Вс, 1=Пн... → нужен 0=Пн..6=Вс
  const d = new Date(date + 'T12:00:00');
  const jsDay = d.getDay(); // 0=Вс
  const bit = jsDay === 0 ? 6 : jsDay - 1; // 0=Пн
  if (!(mask & (1 << bit))) {
    const allowed = WEEKDAY_NAMES.filter((_, i) => mask & (1 << i));
    weekdayError.value = 'Дата возврата должна быть: ' + allowed.join(', ');
  }
}

function validateBso() {
  const s = (form.value.bso_series || '').trim();
  const n = (form.value.bso_number || '').trim();
  if (!s && !n) { bsoError.value = ''; return true; }
  if (!/^[А-ЯЁ]{2}$/u.test(s)) { bsoError.value = 'Серия — две заглавные кириллические буквы'; return false; }
  if (!/^\d{7}$/.test(n)) { bsoError.value = 'Номер — ровно 7 цифр'; return false; }
  bsoError.value = '';
  return true;
}

const formReadonly = computed(() => {
  const s = form.value.status;
  if (s === 'ROUTED' || s === 'CANCELLED') return true;
  if (deadlinePassed.value && s === 'SUBMITTED') return true;
  return false;
});

async function loadList() {
  listLoading.value = true;
  listError.value = '';
  try {
    const res = await fetch('/api/keg-returns', { headers: buildHeaders() });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка загрузки');
    rows.value = Array.isArray(data) ? data : [];
  } catch (e) {
    listError.value = e.message;
  } finally {
    listLoading.value = false;
  }
}

async function loadCatalog() {
  if (catalog.value.length) return;
  catalogLoading.value = true;
  try {
    const res = await fetch('/api/keg-catalog', { headers: buildHeaders() });
    const data = await res.json();
    catalog.value = Array.isArray(data) ? data : [];
  } catch {}
  catalogLoading.value = false;
}

async function loadFormData(id) {
  formLoading.value = true;
  formError.value = '';
  try {
    const res = await fetch(`/api/keg-returns/${id}`, { headers: buildHeaders() });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка загрузки');
    form.value = data;
    kegQties.value = {};
    for (const item of data.items || []) {
      kegQties.value[item.keg_code] = item.quantity;
    }
    deadlineIso.value = data.deadline_iso || null;
    bsoError.value = '';
    weekdayError.value = '';
    if (deadlineIso.value && (data.status === 'DRAFT' || data.status === 'SUBMITTED')) {
      startCountdown();
    } else {
      stopCountdown();
    }
  } catch (e) {
    formError.value = e.message;
  } finally {
    formLoading.value = false;
  }
}

async function loadRestaurantInfo() {
  restaurantInfoLoaded.value = false;
  try {
    const res = await fetch('/api/keg-returns/restaurant-info', { headers: buildHeaders() });
    if (!res.ok) return;
    const data = await res.json();
    // Заполняем form только если поле ещё пусто
    if (data.pickup_weekdays) {
      form.value.restaurant_pickup_weekdays = data.pickup_weekdays;
    }
    if (data.pickup_address && !form.value.pickup_address) {
      form.value.pickup_address = data.pickup_address;
    }
  } catch {}
  restaurantInfoLoaded.value = true;
}

function openNew() {
  editingId.value = null;
  form.value = { return_date: '', bso_series: '', bso_number: '', sender_position_name: '' };
  kegQties.value = {};
  saveError.value = '';
  restaurantInfoLoaded.value = false;
  formMode.value = true;
  loadCatalog();
  loadRestaurantInfo();
}

function openEdit(row) {
  editingId.value = row.id;
  form.value = {};
  kegQties.value = {};
  saveError.value = '';
  formMode.value = true;
  loadFormData(row.id);
  loadCatalog();
}

function backToList() {
  formMode.value = false;
  editingId.value = null;
  loadList();
}

function kegQty(code) {
  return kegQties.value[code] || 0;
}

const totalKegsCount = computed(() => {
  let s = 0;
  for (const v of Object.values(kegQties.value)) s += parseInt(v, 10) || 0;
  return s;
});
const totalKegsTypes = computed(() =>
  Object.values(kegQties.value).filter(v => (parseInt(v, 10) || 0) > 0).length
);
function pluralTypes(n) {
  const m10 = n % 10, m100 = n % 100;
  if (m10 === 1 && m100 !== 11) return 'типе';
  return 'типах';
}

function setKegQty(code, val) {
  const n = parseInt(val, 10);
  if (n > 0) kegQties.value[code] = n;
  else delete kegQties.value[code];
}

function buildBody() {
  const items = Object.entries(kegQties.value)
    .filter(([, qty]) => qty > 0)
    .map(([keg_code, quantity]) => ({ keg_code, quantity: Number(quantity) }));
  return {
    return_date: form.value.return_date,
    bso_series: form.value.bso_series,
    bso_number: form.value.bso_number,
    sender_position_name: form.value.sender_position_name,
    items,
  };
}

async function saveDraft() {
  validateBso();
  validateWeekday();
  if (bsoError.value || weekdayError.value) return;
  saving.value = true;
  saveError.value = '';
  try {
    if (editingId.value) {
      const res = await fetch(`/api/keg-returns/${editingId.value}`, {
        method: 'PATCH',
        headers: buildHeaders(true),
        body: JSON.stringify(buildBody()),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Ошибка сохранения');
    } else {
      const res = await fetch('/api/keg-returns', {
        method: 'POST',
        headers: buildHeaders(true),
        body: JSON.stringify(buildBody()),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Ошибка создания');
      editingId.value = data.id;
      await loadFormData(data.id);
    }
  } catch (e) {
    saveError.value = e.message;
  } finally {
    saving.value = false;
  }
}

async function submit() {
  validateBso();
  validateWeekday();
  if (bsoError.value || weekdayError.value) return;
  if (totalKegsCount.value === 0) {
    saveError.value = 'Укажите количество кег хотя бы в одной строке';
    return;
  }
  const dateStr = form.value.return_date ? fmtDate(form.value.return_date) : '—';
  if (!confirm(`Отправить заявку на возврат ${totalKegsCount.value} кег от ${dateStr}?\n\nИзменить состав можно только до дедлайна.`)) {
    return;
  }
  saving.value = true;
  saveError.value = '';
  try {
    // Сначала сохраняем данные
    if (editingId.value) {
      const res = await fetch(`/api/keg-returns/${editingId.value}`, {
        method: 'PATCH',
        headers: buildHeaders(true),
        body: JSON.stringify(buildBody()),
      });
      const d = await res.json();
      if (!res.ok) throw new Error(d.error || 'Ошибка сохранения');
    } else {
      const res = await fetch('/api/keg-returns', {
        method: 'POST',
        headers: buildHeaders(true),
        body: JSON.stringify(buildBody()),
      });
      const d = await res.json();
      if (!res.ok) throw new Error(d.error || 'Ошибка создания');
      editingId.value = d.id;
    }
    // Отправляем
    const res2 = await fetch(`/api/keg-returns/${editingId.value}/submit`, {
      method: 'POST',
      headers: buildHeaders(),
    });
    const data2 = await res2.json();
    if (!res2.ok) throw new Error(data2.error || 'Ошибка отправки');
    await loadFormData(editingId.value);
  } catch (e) {
    saveError.value = e.message;
  } finally {
    saving.value = false;
  }
}

async function cancelReturn() {
  if (!confirm('Отменить заявку?')) return;
  saving.value = true;
  saveError.value = '';
  try {
    const res = await fetch(`/api/keg-returns/${editingId.value}/cancel`, {
      method: 'POST',
      headers: buildHeaders(),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка');
    await loadFormData(editingId.value);
  } catch (e) {
    saveError.value = e.message;
  } finally {
    saving.value = false;
  }
}

async function deleteDraft() {
  if (!confirm('Удалить черновик? Это нельзя отменить.')) return;
  saving.value = true; saveError.value = '';
  try {
    const res = await fetch(`/api/keg-returns/${editingId.value}`, { method: 'DELETE', headers: buildHeaders() });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка');
    editingId.value = null;
    await loadList();
  } catch (e) { saveError.value = e.message; }
  finally { saving.value = false; }
}

function checkRoutedWarning() {
  const status = form.value?.status;
  if (status !== 'ROUTED' && status !== 'CANCELLED') {
    return confirm('Заявка ещё не маршрутизирована. Водителя и автомобиль нужно будет вписать в накладную вручную. Продолжить?');
  }
  return true;
}

async function downloadExcel() {
  if (!checkRoutedWarning()) return;
  try {
    const res = await fetch(`/api/keg-returns/${editingId.value}/excel`, { headers: buildHeaders() });
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      throw new Error(data.error || 'Ошибка скачивания');
    }
    const blob = await res.blob();
    const cd = res.headers.get('Content-Disposition') || '';
    const m = cd.match(/filename="?([^"]+)"?/);
    const filename = m ? m[1] : `TTN_${editingId.value}.xlsx`;
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
  } catch (e) { saveError.value = e.message; }
}

function printTtn() {
  if (!checkRoutedWarning()) return;
  const t = localStorage.getItem(TOKEN_KEY) || '';
  const url = `/api/keg-returns/${editingId.value}/print?ro_token=${encodeURIComponent(t)}`;
  window.open(url, '_blank');
}

function fmtDate(d) {
  if (!d) return '—';
  const dt = new Date(d);
  if (isNaN(dt)) return d;
  return dt.toLocaleDateString('ru-RU');
}

function statusLabel(s) {
  const map = { DRAFT: 'Черновик', SUBMITTED: 'Отправлена', ROUTED: 'Маршрутизирована', CANCELLED: 'Отменена' };
  return map[s] || s;
}

onMounted(async () => {
  await loadList();
  // Если в URL есть ?id= — сразу открываем эту заявку
  const qId = route.query.id;
  if (qId) {
    const id = parseInt(qId, 10);
    if (id > 0) {
      editingId.value = id;
      form.value = {};
      kegQties.value = {};
      saveError.value = '';
      formMode.value = true;
      await loadFormData(id);
      await loadCatalog();
    }
  }
});
</script>

<style scoped>
.krt-wrap { padding: 20px; max-width: 720px; margin: 0 auto; }
.krt-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.krt-title { font-size: 20px; font-weight: 700; margin: 0; }
.krt-list { display: flex; flex-direction: column; gap: 10px; }
.krt-row { background: var(--card-bg, #fff); border: 1px solid var(--border-color, #eee); border-radius: 8px; padding: 14px 16px; cursor: pointer; transition: box-shadow .15s; }
.krt-row:hover { box-shadow: 0 2px 8px rgba(0,0,0,.08); }
.krt-row-main { display: flex; align-items: center; gap: 12px; margin-bottom: 4px; }
.krt-row-date { font-weight: 600; font-size: 15px; }
.krt-row-sub { font-size: 13px; color: var(--text-secondary, #777); }
.krt-empty { text-align: center; color: var(--text-secondary, #999); padding: 32px 0; }
.krt-error { color: var(--danger, #e53935); padding: 12px 0; }
.krt-form-header { display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }
.krt-form-title { font-size: 17px; font-weight: 600; margin: 0; }
.krt-form { display: flex; flex-direction: column; gap: 14px; }
.krt-field { display: flex; align-items: flex-start; gap: 12px; }
.krt-label { width: 190px; flex-shrink: 0; font-size: 13px; color: var(--text-secondary, #666); padding-top: 7px; }
.krt-input { flex: 1; padding: 7px 10px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 14px; background: var(--input-bg, #fff); color: inherit; }
.krt-input:disabled { background: var(--input-disabled-bg, #f5f5f5); }
.krt-input-sm { max-width: 80px; flex: none; }
.krt-row-inputs { display: flex; gap: 8px; flex: 1; }
.krt-value { font-size: 14px; padding-top: 4px; }
.krt-kegs { display: flex; flex-direction: column; gap: 8px; }
.krt-catalog { display: flex; flex-direction: column; gap: 6px; padding-left: 202px; }
.krt-keg-row { display: flex; align-items: center; gap: 10px; }
.krt-keg-name { flex: 1; font-size: 14px; }
.krt-qty { width: 70px; padding: 5px 8px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 14px; text-align: center; background: var(--input-bg, #fff); color: inherit; }
.krt-qty:disabled { background: var(--input-disabled-bg, #f5f5f5); }
.krt-actions { display: flex; flex-wrap: wrap; gap: 10px; padding-top: 8px; }
.krt-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: 600; }
.krt-badge-DRAFT { background: #e0e0e0; color: #555; }
.krt-badge-SUBMITTED { background: #fff3e0; color: #e65100; }
.krt-badge-ROUTED { background: #e8f5e9; color: #2e7d32; }
.krt-badge-CANCELLED { background: #fce4ec; color: #c62828; }
.btn-danger { background: #fce4ec; color: #c62828; }
.btn-danger:hover { background: #f8bbd0; }
.krt-field-error { font-size: 12px; color: var(--danger, #e53935); }
.krt-hint { font-size: 12px; color: var(--text-secondary, #888); }
.krt-deadline-wrap { padding: 8px 12px; border-radius: 8px; background: #fff8e1; border: 1px solid #ffe082; font-size: 13px; }
.krt-deadline-countdown { color: #e65100; }
.krt-deadline-passed { color: var(--danger, #e53935); font-weight: 600; }

/* Чипы фильтра по статусу */
.krt-filter-chips { display: flex; gap: 8px; margin-bottom: 14px; overflow-x: auto; padding-bottom: 4px; -webkit-overflow-scrolling: touch; }
.krt-filter-chips::-webkit-scrollbar { height: 4px; }
.krt-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 12px; border: 1.5px solid var(--border-color, #E8DCC8);
  background: var(--card-bg, #FFFBF6); color: inherit;
  border-radius: 999px; cursor: pointer; font-size: 13px; font-weight: 600;
  white-space: nowrap; transition: all .15s; flex-shrink: 0;
}
.krt-chip:hover { border-color: #E76F51; }
.krt-chip.active { background: #E76F51; color: white; border-color: #E76F51; }
.krt-chip-count {
  display: inline-block; min-width: 18px; padding: 1px 7px;
  border-radius: 10px; background: rgba(0,0,0,.08); font-size: 11px; font-weight: 700;
}
.krt-chip.active .krt-chip-count { background: rgba(255,255,255,.25); }

/* Счётчик кег в карточке списка */
.krt-row-kegs {
  margin-left: auto; padding: 2px 10px; border-radius: 10px;
  background: #EFF6FF; color: #1d4ed8; font-size: 12px; font-weight: 700;
}

/* Итог под каталогом кег */
.krt-kegs-total {
  margin-top: 10px; padding: 10px 14px; border-radius: 8px;
  background: #F4FBF4; border: 1px solid #C8E6C9; color: #2e7d32; font-size: 14px;
}
.krt-kegs-total.empty { background: var(--input-disabled-bg, #f5f5f5); border-color: var(--border-color, #ddd); color: var(--text-secondary, #888); }
.krt-kegs-total strong { font-weight: 700; }

/* Мобильный вид */
@media (max-width: 640px) {
  .krt-wrap { padding: 14px; }
  .krt-title { font-size: 18px; }
  .krt-row { padding: 12px 14px; }
  .krt-row-main { flex-wrap: wrap; }

  .krt-field { flex-direction: column; align-items: stretch; gap: 6px; }
  .krt-label { width: auto; padding-top: 0; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; }
  .krt-input { width: 100%; padding: 11px 12px; font-size: 16px; }
  .krt-input-sm { max-width: 90px; }
  .krt-row-inputs { gap: 10px; }

  .krt-catalog { padding-left: 0; gap: 10px; }
  .krt-keg-row { padding: 8px 0; border-bottom: 1px solid #F0EAE0; }
  .krt-keg-row:last-child { border-bottom: none; }
  .krt-keg-name { font-size: 15px; }
  .krt-qty { width: 80px; padding: 10px; font-size: 16px; }

  .krt-actions { flex-direction: column; }
  .krt-actions .btn { width: 100%; padding: 12px; font-size: 15px; }
  /* Опасные кнопки внизу */
  .krt-actions .btn-danger { order: 99; }
}
</style>
