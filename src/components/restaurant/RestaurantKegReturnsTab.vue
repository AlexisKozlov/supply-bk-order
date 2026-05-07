<template>
  <div class="krt-wrap">
    <!-- ═══ Список заявок ═══ -->
    <template v-if="!formMode">
      <div class="krt-header">
        <div>
          <h2 class="krt-title">Возврат кег</h2>
          <p class="krt-sub">Оформление ТТН на возврат пустых кег.</p>
        </div>
        <button class="krt-btn primary lg" @click="openNew">
          <span class="krt-btn-plus">+</span>
          Новая заявка
        </button>
      </div>

      <div v-if="listLoading" class="krt-empty">Загрузка...</div>
      <template v-else>
        <div v-if="!rows.length" class="krt-empty-card">
          <div class="krt-empty-illu" v-html="iconKeg"></div>
          <h3>Заявок пока нет</h3>
          <p>Создайте заявку, дождитесь маршрутизации — затем распечатайте ТТН на БСО и передайте её водителю вместе с кегами.</p>
          <button class="krt-btn primary" @click="openNew">+ Новая заявка</button>
        </div>
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
            <button
              v-for="row in filteredRows"
              :key="row.id"
              type="button"
              class="krt-card"
              :class="'krt-card--' + row.status"
              @click="openEdit(row)"
            >
              <span class="krt-card-stripe"></span>
              <div class="krt-card-body">
                <div class="krt-card-row1">
                  <div class="krt-card-date">{{ fmtDate(row.return_date) }}</div>
                  <span :class="'krt-badge krt-badge-' + row.status">{{ statusLabel(row.status) }}</span>
                </div>
                <div class="krt-card-row2">
                  <span v-if="row.total_kegs" class="krt-card-pill">
                    <span class="krt-card-pill-num">{{ row.total_kegs }}</span> кег
                  </span>
                  <span v-if="row.bso_series || row.bso_number" class="krt-card-meta">
                    БСО {{ row.bso_series }} {{ row.bso_number }}
                  </span>
                  <span v-if="row.driver" class="krt-card-meta">· {{ row.driver }}</span>
                </div>
              </div>
              <span class="krt-card-arrow">›</span>
            </button>
          </div>
        </template>
      </template>
    </template>

    <!-- ═══ Форма ═══ -->
    <template v-else>
      <div class="krt-form-wrap">
        <div class="krt-form-topbar">
          <button class="krt-btn ghost" @click="backToList">← Назад</button>
          <div class="krt-form-title-block">
            <div class="krt-form-title">{{ editingId ? 'Заявка №' + editingId : 'Новая заявка' }}</div>
            <div v-if="form.status" class="krt-form-sub">
              <span :class="'krt-badge krt-badge-' + form.status">{{ statusLabel(form.status) }}</span>
              <span v-if="deadlineIso && (form.status === 'DRAFT' || form.status === 'SUBMITTED')" class="krt-deadline-inline">
                <span v-if="!deadlinePassed">Изменить можно <b>до {{ deadlineFormatted }}</b></span>
                <span v-else class="krt-deadline-passed">Дедлайн прошёл</span>
              </span>
            </div>
          </div>
        </div>

        <div v-if="formLoading" class="krt-empty">Загрузка...</div>
        <div v-else-if="formError" class="krt-error">{{ formError }}</div>

        <template v-else>
          <!-- Секция: Основная информация -->
          <section class="krt-section">
            <div class="krt-section-head">
              <h3>Основная информация</h3>
            </div>

            <div class="krt-fld-row3">
              <div class="krt-fld krt-fld-date">
                <label class="krt-fld-label">
                  Дата возврата
                  <span class="krt-tip">
                    <span class="krt-tip-icon" tabindex="0">?</span>
                    <span class="krt-tip-bubble">Доступны только дни, разрешённые отделом закупок.</span>
                  </span>
                </label>
                <select
                  v-if="availableDates.length"
                  v-model="form.return_date"
                  :disabled="formReadonly || deadlinePassed"
                  class="krt-input"
                  :class="{ 'krt-input-err': fieldErr.return_date }"
                >
                  <option value="">— выберите дату —</option>
                  <option v-for="d in availableDates" :key="d.iso" :value="d.iso">{{ d.label }}</option>
                </select>
                <template v-else-if="!editingId">
                  <div v-if="restaurantInfoLoaded" class="krt-fld-empty">
                    Дни возврата не настроены — обратитесь в отдел закупок.
                  </div>
                  <div v-else class="krt-fld-empty">Загрузка...</div>
                </template>
                <input
                  v-else
                  v-model="form.return_date"
                  type="date"
                  :disabled="formReadonly || deadlinePassed"
                  class="krt-input"
                  :class="{ 'krt-input-err': fieldErr.return_date }"
                />
              </div>

              <div class="krt-fld krt-fld-bso-series">
                <label class="krt-fld-label">
                  Серия БСО
                  <span class="krt-tip">
                    <span class="krt-tip-icon" tabindex="0">?</span>
                    <span class="krt-tip-bubble">Две заглавные кириллические буквы (например, АА).</span>
                  </span>
                </label>
                <input
                  :value="form.bso_series || ''"
                  @input="onBsoSeriesInput"
                  type="text"
                  maxlength="2"
                  placeholder="АА"
                  :disabled="formReadonly"
                  class="krt-input krt-input-mono"
                  :class="{ 'krt-input-err': fieldErr.bso }"
                  autocomplete="off"
                  inputmode="text"
                />
              </div>

              <div class="krt-fld krt-fld-bso-number">
                <label class="krt-fld-label">
                  Номер БСО
                  <span class="krt-tip">
                    <span class="krt-tip-icon" tabindex="0">?</span>
                    <span class="krt-tip-bubble">Ровно 7 цифр.</span>
                  </span>
                </label>
                <input
                  :value="form.bso_number || ''"
                  @input="onBsoNumberInput"
                  type="text"
                  inputmode="numeric"
                  maxlength="7"
                  placeholder="0000000"
                  :disabled="formReadonly"
                  class="krt-input krt-input-mono"
                  :class="{ 'krt-input-err': fieldErr.bso }"
                  autocomplete="off"
                />
              </div>
            </div>

            <div class="krt-fld">
              <label class="krt-fld-label">
                Сдал грузоотправитель
                <span class="krt-tip">
                  <span class="krt-tip-icon" tabindex="0">?</span>
                  <span class="krt-tip-bubble">Должность, фамилия и инициалы того, кто фактически передаёт кеги. Например: «Управляющий рестораном Иванов И.И.»</span>
                </span>
              </label>
              <input
                v-model="form.sender_position_name"
                type="text"
                :disabled="formReadonly"
                class="krt-input"
                placeholder="Управляющий рестораном Иванов И.И."
              />
            </div>

            <template v-if="form.status && form.status !== 'DRAFT'">
              <div class="krt-fld-row2">
                <div class="krt-fld">
                  <label class="krt-fld-label">Машина</label>
                  <div class="krt-readonly">{{ form.vehicle || '—' }}</div>
                </div>
                <div class="krt-fld">
                  <label class="krt-fld-label">Водитель</label>
                  <div class="krt-readonly">{{ form.driver || '—' }}</div>
                </div>
              </div>
            </template>
          </section>

          <!-- Секция: Кеги -->
          <section class="krt-section">
            <div class="krt-section-head">
              <h3>Кеги к возврату</h3>
              <span v-if="totalKegsCount > 0" class="krt-section-counter">
                {{ totalKegsCount }} {{ pluralKegs(totalKegsCount) }}
              </span>
            </div>

            <div v-if="catalogLoading" class="krt-empty">Загрузка каталога...</div>
            <div v-else class="krt-keg-list">
              <div v-for="keg in catalog" :key="keg.code" class="krt-keg-row" :class="{ active: kegQty(keg.code) > 0 }">
                <button
                  type="button"
                  class="krt-keg-thumb"
                  :class="{ 'has-photo': keg.photo_url }"
                  @click.stop="keg.photo_url && openPhoto(keg)"
                  :disabled="!keg.photo_url"
                  :title="keg.photo_url ? 'Открыть фото' : 'Фото не загружено'"
                >
                  <img v-if="keg.photo_url" :src="keg.photo_url" :alt="keg.name" />
                  <span v-else class="krt-keg-thumb-placeholder" v-html="iconKeg"></span>
                </button>
                <div class="krt-keg-info">
                  <div class="krt-keg-name">
                    <span class="krt-keg-code">{{ keg.code }}</span>
                    {{ keg.name }}
                  </div>
                </div>
                <div class="krt-stepper" :class="{ disabled: formReadonly }">
                  <button
                    type="button"
                    class="krt-step-btn"
                    :disabled="formReadonly || kegQty(keg.code) === 0"
                    @click.stop="decKegQty(keg.code)"
                    aria-label="Уменьшить"
                  >−</button>
                  <input
                    type="number"
                    min="0"
                    inputmode="numeric"
                    :value="kegQty(keg.code)"
                    @input="setKegQty(keg.code, $event.target.value)"
                    :disabled="formReadonly"
                    class="krt-step-input"
                  />
                  <button
                    type="button"
                    class="krt-step-btn"
                    :disabled="formReadonly"
                    @click.stop="incKegQty(keg.code)"
                    aria-label="Увеличить"
                  >+</button>
                </div>
              </div>
            </div>

            <div v-if="!catalogLoading" class="krt-keg-summary" :class="{ empty: totalKegsCount === 0 }">
              <span v-if="totalKegsCount === 0">Кеги не указаны</span>
              <span v-else>
                Всего: <strong>{{ totalKegsCount }} {{ pluralKegs(totalKegsCount) }}</strong>
                в {{ totalKegsTypes }} {{ pluralTypes(totalKegsTypes) }}
              </span>
            </div>
          </section>
        </template>
      </div>

      <!-- Sticky-полоса с действиями -->
      <div v-if="!formLoading && !formError" class="krt-actions-bar">
        <div class="krt-actions-bar-inner">
          <template v-if="!formReadonly">
            <!-- Черновик / новая заявка: «Сохранить черновик» + «Сформировать ТТН» -->
            <template v-if="!editingId || form.status === 'DRAFT'">
              <button class="krt-btn ghost" @click="saveDraft" :disabled="saving">
                {{ saving ? 'Сохранение...' : 'Сохранить черновик' }}
              </button>
              <button
                class="krt-btn primary"
                @click="submit"
                :disabled="saving || !submitReady"
                :title="submitReady ? '' : submitMissingHint"
              >
                Сформировать ТТН
              </button>
            </template>
            <!-- Уже отправленная заявка до дедлайна: одна кнопка «Сохранить изменения» -->
            <template v-else>
              <button class="krt-btn primary" @click="saveDraft" :disabled="saving">
                {{ saving ? 'Сохранение...' : 'Сохранить изменения' }}
              </button>
            </template>
          </template>
          <button
            v-if="editingId"
            class="krt-btn outline"
            @click="downloadExcel"
            :disabled="saving || form.status === 'DRAFT'"
            :title="form.status === 'DRAFT' ? 'Доступно после формирования ТТН' : ''"
          >
            Скачать Excel
          </button>
          <button
            v-if="editingId"
            class="krt-btn outline"
            @click="printTtn"
            :disabled="saving || form.status === 'DRAFT'"
            :title="form.status === 'DRAFT' ? 'Доступно после формирования ТТН' : ''"
          >
            Печать
          </button>
        </div>
        <div v-if="!formReadonly && (editingId && (form.status === 'SUBMITTED' || form.status === 'DRAFT'))" class="krt-actions-bar-extra">
          <button v-if="form.status === 'SUBMITTED'" class="krt-link danger" @click="cancelReturn" :disabled="saving">
            Отменить заявку
          </button>
          <button v-if="form.status === 'DRAFT'" class="krt-link danger" @click="deleteDraft" :disabled="saving">
            Удалить черновик
          </button>
        </div>
      </div>
    </template>

    <!-- Просмотр фото кеги -->
    <div v-if="photoModal.show" class="krt-photo-overlay" @click.self="closePhoto">
      <div class="krt-photo-modal">
        <div class="krt-photo-head">
          <span>{{ photoModal.name }}</span>
          <button class="krt-photo-close" @click="closePhoto" aria-label="Закрыть">×</button>
        </div>
        <img :src="photoModal.url" :alt="photoModal.name" />
      </div>
    </div>

    <!-- Подтверждения -->
    <div v-if="confirmModal.show" class="krt-confirm-overlay" @click.self="confirmCancel">
      <div class="krt-confirm">
        <h3>{{ confirmModal.title }}</h3>
        <p>{{ confirmModal.message }}</p>
        <div class="krt-confirm-actions">
          <button class="krt-btn ghost" @click="confirmCancel">{{ confirmModal.cancelText || 'Отмена' }}</button>
          <button
            class="krt-btn"
            :class="confirmModal.danger ? 'danger' : 'primary'"
            @click="confirmOk"
          >{{ confirmModal.okText || 'OK' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue';
import { useRoute } from 'vue-router';
import { useToastStore } from '@/stores/toastStore.js';

const TOKEN_KEY = 'ro_token';
const route = useRoute();
const toast = useToastStore();

function buildHeaders(json = false) {
  const h = {};
  const t = localStorage.getItem(TOKEN_KEY);
  if (t) h['X-RO-Token'] = t;
  if (json) h['Content-Type'] = 'application/json';
  return h;
}

const iconKeg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="4.5" rx="6" ry="1.7"/><path d="M6 4.5v15c0 .9 2.7 1.7 6 1.7s6-.8 6-1.7v-15"/><path d="M6 9c0 .9 2.7 1.7 6 1.7S18 9.9 18 9"/><path d="M6 14.5c0 .9 2.7 1.7 6 1.7s6-.8 6-1.7"/></svg>';

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
const formMode = ref(false);
const editingId = ref(null);
const form = ref({});
const kegQties = ref({});
const formLoading = ref(false);
const formError = ref('');
const catalogLoading = ref(false);
const saving = ref(false);
const restaurantInfoLoaded = ref(false);

// Поля с ошибками для подсветки рамкой (без сдвига вёрстки)
const fieldErr = reactive({ return_date: false, bso: false });

// Просмотр фото
const photoModal = reactive({ show: false, url: '', name: '' });
function openPhoto(keg) {
  if (!keg?.photo_url) return;
  photoModal.url = keg.photo_url;
  photoModal.name = keg.name;
  photoModal.show = true;
}
function closePhoto() { photoModal.show = false; }

// Подтверждения
const confirmModal = reactive({
  show: false,
  title: '',
  message: '',
  okText: 'OK',
  cancelText: 'Отмена',
  danger: false,
  resolve: null,
});
function askConfirm({ title, message, okText = 'OK', cancelText = 'Отмена', danger = false }) {
  return new Promise(resolve => {
    confirmModal.title = title;
    confirmModal.message = message;
    confirmModal.okText = okText;
    confirmModal.cancelText = cancelText;
    confirmModal.danger = danger;
    confirmModal.resolve = resolve;
    confirmModal.show = true;
  });
}
function confirmOk() {
  confirmModal.show = false;
  confirmModal.resolve?.(true);
  confirmModal.resolve = null;
}
function confirmCancel() {
  confirmModal.show = false;
  confirmModal.resolve?.(false);
  confirmModal.resolve = null;
}

// Дедлайн
const deadlineIso = ref(null);
const deadlinePassed = ref(false);
let deadlineTimer = null;

const deadlineFormatted = computed(() => {
  if (!deadlineIso.value) return '';
  const d = new Date(deadlineIso.value);
  if (Number.isNaN(d.getTime())) return '';
  const date = d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
  const time = d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  return `${date} в ${time}`;
});

function startDeadlineWatch() {
  stopDeadlineWatch();
  if (!deadlineIso.value) return;
  const check = () => {
    const dl = new Date(deadlineIso.value).getTime();
    deadlinePassed.value = Date.now() >= dl;
  };
  check();
  // Раз в минуту обновляем флаг — этого достаточно: в формулировке точность секунд не нужна.
  deadlineTimer = setInterval(check, 60000);
}
function stopDeadlineWatch() {
  if (deadlineTimer) { clearInterval(deadlineTimer); deadlineTimer = null; }
}
onUnmounted(stopDeadlineWatch);

const WEEKDAY_NAMES = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

function calcDeadlineMs(isoDate) {
  const [Y, M, D] = isoDate.split('-').map(Number);
  const d = new Date(Y, M - 1, D, 10, 0, 0, 0);
  do { d.setDate(d.getDate() - 1); } while (d.getDay() === 0 || d.getDay() === 6);
  return d.getTime();
}

const availableDates = computed(() => {
  const mask = parseInt(form.value?.restaurant_pickup_weekdays || 0);
  const out = [];
  if (mask) {
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
      if (calcDeadlineMs(iso) <= now) continue;
      const label = String(d.getDate()).padStart(2, '0') + '.' + String(d.getMonth() + 1).padStart(2, '0') + '.' + d.getFullYear() + ' (' + WEEKDAY_NAMES[bit] + ')';
      out.push({ iso, label });
    }
  }
  // Если редактируем существующую заявку и сохранённая дата не попадает в список — добавляем её
  // как первый вариант, чтобы select не пустовал.
  const cur = form.value?.return_date;
  if (cur && !out.some(d => d.iso === cur)) {
    const dt = new Date(cur + 'T12:00:00');
    if (!Number.isNaN(dt.getTime())) {
      const jsDay = dt.getDay();
      const bit = jsDay === 0 ? 6 : jsDay - 1;
      const label = String(dt.getDate()).padStart(2, '0') + '.' + String(dt.getMonth() + 1).padStart(2, '0') + '.' + dt.getFullYear() + ' (' + WEEKDAY_NAMES[bit] + ')';
      out.unshift({ iso: cur, label });
    }
  }
  return out;
});

function validateWeekday() {
  const date = form.value.return_date;
  const mask = form.value.restaurant_pickup_weekdays;
  if (!date || !mask) return true;
  const d = new Date(date + 'T12:00:00');
  const jsDay = d.getDay();
  const bit = jsDay === 0 ? 6 : jsDay - 1;
  if (!(mask & (1 << bit))) {
    const allowed = WEEKDAY_NAMES.filter((_, i) => mask & (1 << i));
    fieldErr.return_date = true;
    toast.error('Неверный день недели', 'Возврат возможен в дни: ' + allowed.join(', '));
    return false;
  }
  fieldErr.return_date = false;
  return true;
}

// Маски ввода для БСО: серия — только 2 заглавные кириллические буквы; номер — только 7 цифр.
function onBsoSeriesInput(e) {
  const raw = String(e.target.value || '');
  // Оставляем только кириллические буквы и приводим к верхнему регистру
  const filtered = raw.replace(/[^А-Яа-яЁё]/g, '').toUpperCase().slice(0, 2);
  form.value.bso_series = filtered;
  // Принудительно обновляем DOM-инпут на случай, если v-model отстал
  if (e.target.value !== filtered) e.target.value = filtered;
}
function onBsoNumberInput(e) {
  const raw = String(e.target.value || '');
  const filtered = raw.replace(/\D/g, '').slice(0, 7);
  form.value.bso_number = filtered;
  if (e.target.value !== filtered) e.target.value = filtered;
}

function validateBso() {
  const s = (form.value.bso_series || '').trim();
  const n = (form.value.bso_number || '').trim();
  if (!s && !n) { fieldErr.bso = false; return true; }
  if (!/^[А-ЯЁ]{2}$/u.test(s)) {
    fieldErr.bso = true;
    toast.error('Серия БСО', 'Две заглавные кириллические буквы, например «АА».');
    return false;
  }
  if (!/^\d{7}$/.test(n)) {
    fieldErr.bso = true;
    toast.error('Номер БСО', 'Ровно 7 цифр.');
    return false;
  }
  fieldErr.bso = false;
  return true;
}

const formReadonly = computed(() => {
  const s = form.value.status;
  if (s === 'ROUTED' || s === 'CANCELLED') return true;
  if (deadlinePassed.value && s === 'SUBMITTED') return true;
  return false;
});

// Все обязательные поля заполнены и валидны (для разблокировки «Сформировать ТТН»)
const submitReady = computed(() => {
  if (!form.value.return_date) return false;
  const s = (form.value.bso_series || '').trim();
  const n = (form.value.bso_number || '').trim();
  if (!/^[А-ЯЁ]{2}$/u.test(s)) return false;
  if (!/^\d{7}$/.test(n)) return false;
  if (!(form.value.sender_position_name || '').trim()) return false;
  if (totalKegsCount.value === 0) return false;
  return true;
});

// Краткое описание, чего не хватает (для подсказки на кнопке)
const submitMissingHint = computed(() => {
  const missing = [];
  if (!form.value.return_date) missing.push('дату возврата');
  const s = (form.value.bso_series || '').trim();
  const n = (form.value.bso_number || '').trim();
  if (!/^[А-ЯЁ]{2}$/u.test(s) || !/^\d{7}$/.test(n)) missing.push('серию и номер БСО');
  if (!(form.value.sender_position_name || '').trim()) missing.push('кто сдал');
  if (totalKegsCount.value === 0) missing.push('количество кег');
  if (!missing.length) return '';
  return 'Заполните: ' + missing.join(', ');
});

async function loadList() {
  listLoading.value = true;
  try {
    const res = await fetch('/api/keg-returns', { headers: buildHeaders() });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка загрузки');
    rows.value = Array.isArray(data) ? data : [];
  } catch (e) {
    toast.error('Ошибка загрузки', e.message || '');
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
    fieldErr.return_date = false;
    fieldErr.bso = false;
    if (deadlineIso.value && (data.status === 'DRAFT' || data.status === 'SUBMITTED')) {
      startDeadlineWatch();
    } else {
      stopDeadlineWatch();
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
  fieldErr.return_date = false;
  fieldErr.bso = false;
  restaurantInfoLoaded.value = false;
  formMode.value = true;
  loadCatalog();
  loadRestaurantInfo();
}

function openEdit(row) {
  editingId.value = row.id;
  form.value = {};
  kegQties.value = {};
  fieldErr.return_date = false;
  fieldErr.bso = false;
  formMode.value = true;
  loadFormData(row.id);
  loadCatalog();
}

function backToList() {
  formMode.value = false;
  editingId.value = null;
  loadList();
}

function kegQty(code) { return kegQties.value[code] || 0; }
function setKegQty(code, val) {
  const n = parseInt(val, 10);
  if (n > 0) kegQties.value[code] = n;
  else delete kegQties.value[code];
}
function incKegQty(code) {
  setKegQty(code, (kegQties.value[code] || 0) + 1);
}
function decKegQty(code) {
  const cur = kegQties.value[code] || 0;
  if (cur <= 0) return;
  setKegQty(code, cur - 1);
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
function pluralKegs(n) {
  const m10 = n % 10, m100 = n % 100;
  if (m10 === 1 && m100 !== 11) return 'кега';
  if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return 'кеги';
  return 'кег';
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
  if (!validateBso()) return;
  if (!validateWeekday()) return;
  saving.value = true;
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
    toast.success('Сохранено', form.value.status === 'SUBMITTED' ? 'Изменения применены' : 'Черновик сохранён');
  } catch (e) {
    toast.error('Ошибка сохранения', e.message || '');
  } finally {
    saving.value = false;
  }
}

async function submit() {
  if (!validateBso()) return;
  if (!validateWeekday()) return;
  if (totalKegsCount.value === 0) {
    toast.error('Кеги не указаны', 'Укажите количество хотя бы по одной строке.');
    return;
  }
  const dateStr = form.value.return_date ? fmtDate(form.value.return_date) : '—';
  const ok = await askConfirm({
    title: 'Сформировать ТТН?',
    message: `Заявка на ${totalKegsCount.value} ${pluralKegs(totalKegsCount.value)} от ${dateStr}. Изменить состав можно только до дедлайна.`,
    okText: 'Сформировать',
    cancelText: 'Отмена',
  });
  if (!ok) return;
  saving.value = true;
  try {
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
    const res2 = await fetch(`/api/keg-returns/${editingId.value}/submit`, {
      method: 'POST',
      headers: buildHeaders(),
    });
    const data2 = await res2.json();
    if (!res2.ok) throw new Error(data2.error || 'Ошибка отправки');
    await loadFormData(editingId.value);
    toast.success('ТТН сформирована', 'Заявка отправлена в отдел закупок');
  } catch (e) {
    toast.error('Ошибка отправки', e.message || '');
  } finally {
    saving.value = false;
  }
}

async function cancelReturn() {
  const ok = await askConfirm({
    title: 'Отменить заявку?',
    message: 'Заявка перейдёт в статус «Отменена» — её больше нельзя будет отправить.',
    okText: 'Отменить заявку',
    cancelText: 'Не отменять',
    danger: true,
  });
  if (!ok) return;
  saving.value = true;
  try {
    const res = await fetch(`/api/keg-returns/${editingId.value}/cancel`, {
      method: 'POST',
      headers: buildHeaders(),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Не удалось отменить заявку');
    await loadFormData(editingId.value);
    toast.success('Заявка отменена', '');
  } catch (e) {
    toast.error('Не удалось отменить', e.message || '');
  } finally {
    saving.value = false;
  }
}

async function deleteDraft() {
  const ok = await askConfirm({
    title: 'Удалить черновик?',
    message: 'Это нельзя отменить.',
    okText: 'Удалить',
    cancelText: 'Не удалять',
    danger: true,
  });
  if (!ok) return;
  saving.value = true;
  try {
    const res = await fetch(`/api/keg-returns/${editingId.value}`, { method: 'DELETE', headers: buildHeaders() });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Не удалось удалить черновик');
    editingId.value = null;
    formMode.value = false;
    await loadList();
    toast.success('Черновик удалён', '');
  } catch (e) {
    toast.error('Не удалось удалить', e.message || '');
  } finally {
    saving.value = false;
  }
}

async function checkRoutedWarning() {
  const status = form.value?.status;
  if (status !== 'ROUTED' && status !== 'CANCELLED') {
    return await askConfirm({
      title: 'Заявка не маршрутизирована',
      message: 'Водителя и автомобиль нужно будет вписать в накладную вручную. Продолжить?',
      okText: 'Продолжить',
      cancelText: 'Отмена',
    });
  }
  return true;
}

async function downloadExcel() {
  if (!await checkRoutedWarning()) return;
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
  } catch (e) {
    toast.error('Ошибка скачивания', e.message || '');
  }
}

async function printTtn() {
  if (!await checkRoutedWarning()) return;
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
  const qId = route.query.id;
  if (qId) {
    const id = parseInt(qId, 10);
    if (id > 0) {
      editingId.value = id;
      form.value = {};
      kegQties.value = {};
      formMode.value = true;
      await loadFormData(id);
      await loadCatalog();
    }
  }
});
</script>

<style scoped>
.krt-wrap {
  padding: 20px 20px 110px;
  max-width: 760px;
  margin: 0 auto;
  color: #2C1A12;
}

/* ═══ Хедер списка ═══ */
.krt-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 16px; margin-bottom: 20px; flex-wrap: wrap;
}
.krt-title { font-size: 22px; font-weight: 700; margin: 0 0 4px; color: #2C1A12; }
.krt-sub { margin: 0; font-size: 13px; color: #8C7B6E; }

/* ═══ Кнопки ═══ */
.krt-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  padding: 10px 16px; border-radius: 10px; border: 1.5px solid transparent;
  font-size: 14px; font-weight: 600; line-height: 1; cursor: pointer;
  font-family: inherit; transition: transform .08s, background .15s, border-color .15s, box-shadow .15s;
  white-space: nowrap;
}
.krt-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.krt-btn.lg { padding: 12px 20px; font-size: 15px; }
.krt-btn.primary { background: #E76F51; color: #fff; border-color: #E76F51; }
.krt-btn.primary:hover:not(:disabled) { background: #D9603F; border-color: #D9603F; box-shadow: 0 4px 12px rgba(231,111,81,.25); }
.krt-btn.outline { background: #fff; color: #502314; border-color: #E8DCC8; }
.krt-btn.outline:hover:not(:disabled) { background: #FFF8F0; border-color: #D6C5AB; }
.krt-btn.ghost { background: transparent; color: #6B5344; border-color: #EDE7DF; }
.krt-btn.ghost:hover:not(:disabled) { background: #F7F2EB; }
.krt-btn.danger { background: #E53935; color: #fff; border-color: #E53935; }
.krt-btn.danger:hover:not(:disabled) { background: #C62828; border-color: #C62828; }
.krt-btn-plus { font-size: 18px; line-height: 1; font-weight: 400; margin-right: 2px; }
.krt-link {
  background: none; border: none; padding: 6px 4px;
  font: inherit; cursor: pointer; color: #6B5344; text-decoration: underline;
  text-underline-offset: 3px; font-size: 13px;
}
.krt-link.danger { color: #E53935; }
.krt-link:hover { opacity: .8; }

/* ═══ Список карточек ═══ */
.krt-list { display: flex; flex-direction: column; gap: 10px; }
.krt-card {
  display: flex; align-items: stretch; gap: 0;
  background: #fff; border: 1px solid #ECE3D6; border-radius: 12px;
  padding: 0; overflow: hidden; cursor: pointer;
  text-align: left; font-family: inherit;
  transition: transform .08s, box-shadow .15s, border-color .15s;
  width: 100%;
}
.krt-card:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(80,35,20,.08); border-color: #D6C5AB; }
.krt-card-stripe { width: 5px; flex-shrink: 0; background: #C8C8C8; }
.krt-card--DRAFT .krt-card-stripe { background: #B0AFAA; }
.krt-card--SUBMITTED .krt-card-stripe { background: #F4A261; }
.krt-card--ROUTED .krt-card-stripe { background: #43A047; }
.krt-card--CANCELLED .krt-card-stripe { background: #E57373; }
.krt-card-body { flex: 1; padding: 14px 16px; min-width: 0; }
.krt-card-row1 { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 6px; }
.krt-card-date { font-weight: 700; font-size: 16px; color: #2C1A12; }
.krt-card-row2 { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; font-size: 13px; color: #8C7B6E; }
.krt-card-pill {
  display: inline-flex; align-items: baseline; gap: 4px;
  padding: 3px 10px; border-radius: 999px;
  background: #FFF1E0; color: #C16B4D; font-weight: 600;
}
.krt-card-pill-num { font-size: 14px; font-weight: 700; }
.krt-card-meta { white-space: nowrap; }
.krt-card-arrow {
  display: flex; align-items: center; padding: 0 16px;
  font-size: 22px; color: #C7B9A7; font-weight: 300;
}

/* Пустой список */
.krt-empty-card {
  background: #fff; border: 1px solid #ECE3D6; border-radius: 14px;
  padding: 36px 24px; text-align: center;
}
.krt-empty-illu { width: 56px; height: 56px; margin: 0 auto 12px; color: #C7B9A7; }
.krt-empty-illu svg { width: 100%; height: 100%; }
.krt-empty-card h3 { margin: 0 0 6px; font-size: 17px; }
.krt-empty-card p { margin: 0 0 16px; color: #8C7B6E; font-size: 13.5px; }

/* ═══ Чипы статусов ═══ */
.krt-filter-chips {
  display: flex; gap: 8px; margin-bottom: 14px;
  overflow-x: auto; padding-bottom: 4px; -webkit-overflow-scrolling: touch;
}
.krt-filter-chips::-webkit-scrollbar { height: 4px; }
.krt-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 7px 12px; border: 1.5px solid #E8DCC8;
  background: #FFFBF6; color: #6B5344;
  border-radius: 999px; cursor: pointer; font-size: 13px; font-weight: 600;
  white-space: nowrap; transition: all .15s; flex-shrink: 0;
  font-family: inherit;
}
.krt-chip:hover { border-color: #E76F51; }
.krt-chip.active { background: #E76F51; color: #fff; border-color: #E76F51; }
.krt-chip-count {
  display: inline-block; min-width: 18px; padding: 1px 7px;
  border-radius: 10px; background: rgba(0,0,0,.08);
  font-size: 11px; font-weight: 700;
}
.krt-chip.active .krt-chip-count { background: rgba(255,255,255,.25); }

/* ═══ Бейджи ═══ */
.krt-badge {
  display: inline-block; padding: 3px 10px; border-radius: 999px;
  font-size: 11.5px; font-weight: 700; letter-spacing: .02em;
}
.krt-badge-DRAFT { background: #ECEAE5; color: #6B5344; }
.krt-badge-SUBMITTED { background: #FFF1E0; color: #C16B4D; }
.krt-badge-ROUTED { background: #E5F3E5; color: #2E7D32; }
.krt-badge-CANCELLED { background: #FCE4EC; color: #C62828; }

/* ═══ Форма ═══ */
.krt-form-wrap { display: flex; flex-direction: column; gap: 14px; }
.krt-form-topbar {
  display: flex; align-items: center; gap: 12px; padding-bottom: 6px;
}
.krt-form-title-block { flex: 1; min-width: 0; }
.krt-form-title { font-size: 18px; font-weight: 700; line-height: 1.2; color: #2C1A12; }
.krt-form-sub {
  display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
  margin-top: 4px; font-size: 12.5px; color: #8C7B6E;
}
.krt-deadline-inline b { color: #C16B4D; font-weight: 700; font-variant-numeric: tabular-nums; }
.krt-deadline-passed { color: #E53935; font-weight: 700; }

.krt-section {
  background: #fff; border: 1px solid #ECE3D6; border-radius: 14px;
  padding: 18px 18px 16px; box-shadow: 0 1px 0 rgba(80,35,20,.02);
}
.krt-section-head {
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; margin-bottom: 14px;
}
.krt-section-head h3 { margin: 0; font-size: 15px; font-weight: 700; color: #2C1A12; }
.krt-section-counter {
  font-size: 12.5px; color: #C16B4D; font-weight: 700;
  background: #FFF1E0; padding: 3px 10px; border-radius: 999px;
}

.krt-fld { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
.krt-fld:last-child { margin-bottom: 0; }
.krt-fld-row2 { display: grid; grid-template-columns: 100px 1fr; gap: 12px; margin-bottom: 12px; }
.krt-fld-row2 .krt-fld { margin-bottom: 0; }
.krt-fld-row3 {
  display: grid;
  grid-template-columns: minmax(220px, 1fr) 120px 150px;
  gap: 12px; margin-bottom: 12px;
}
.krt-fld-row3 .krt-fld { margin-bottom: 0; }
.krt-input-mono {
  font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace;
  font-size: 15px; letter-spacing: 0.06em; text-align: center;
}
.krt-fld-bso-series .krt-input-mono { text-transform: uppercase; }
.krt-fld-label {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 12px; font-weight: 600; text-transform: uppercase;
  letter-spacing: .04em; color: #8C7B6E;
}
.krt-input {
  width: 100%; padding: 11px 12px; border-radius: 10px;
  border: 1.5px solid #E8DCC8; background: #fff; color: #2C1A12;
  font-size: 15px; font-family: inherit; transition: border-color .15s, box-shadow .15s;
  -webkit-appearance: none; appearance: none;
}
.krt-input:focus {
  outline: none; border-color: #E76F51;
  box-shadow: 0 0 0 3px rgba(231,111,81,.15);
}
.krt-input:disabled { background: #F7F2EB; color: #8C7B6E; cursor: not-allowed; }
.krt-input-sm { max-width: 100%; text-transform: uppercase; }
.krt-input-err { border-color: #E53935; background: #FFF6F5; }
.krt-input-err:focus { box-shadow: 0 0 0 3px rgba(229,57,53,.15); }
.krt-fld-empty {
  padding: 10px 12px; border-radius: 10px; background: #FFF8E6;
  border: 1px solid #FFE0A8; color: #8C5A1C; font-size: 13px;
}
.krt-readonly {
  padding: 11px 12px; border-radius: 10px; background: #F7F2EB;
  color: #2C1A12; font-size: 14.5px;
}

/* Подсказки */
.krt-tip { position: relative; display: inline-flex; }
.krt-tip-icon {
  display: inline-flex; align-items: center; justify-content: center;
  width: 16px; height: 16px; border-radius: 50%;
  background: #E8DCC8; color: #6B5344;
  font-size: 11px; font-weight: 700; cursor: help;
  outline: none;
}
.krt-tip-icon:hover, .krt-tip-icon:focus { background: #E76F51; color: #fff; }
.krt-tip-bubble {
  position: absolute; left: 0; top: 100%;
  margin-top: 8px; min-width: 220px; max-width: 280px;
  padding: 10px 12px; border-radius: 8px;
  background: #2C1A12; color: #FFF8F0;
  font-size: 12.5px; font-weight: 400; line-height: 1.4;
  letter-spacing: 0; text-transform: none;
  opacity: 0; pointer-events: none;
  transform: translateY(-4px);
  transition: opacity .15s, transform .15s;
  z-index: 50;
  box-shadow: 0 6px 18px rgba(0,0,0,.18);
}
.krt-tip-bubble::before {
  content: ''; position: absolute; left: 8px; top: -5px;
  width: 10px; height: 10px; background: #2C1A12;
  transform: rotate(45deg);
}
.krt-tip-icon:hover + .krt-tip-bubble,
.krt-tip-icon:focus + .krt-tip-bubble { opacity: 1; transform: translateY(0); pointer-events: auto; }

/* ═══ Кеги ═══ */
.krt-keg-list { display: flex; flex-direction: column; gap: 6px; }
.krt-keg-row {
  display: grid; grid-template-columns: 44px 1fr auto; gap: 12px;
  align-items: center; padding: 8px 10px;
  border-radius: 10px; transition: background .15s;
}
.krt-keg-row:hover { background: #FAF5EE; }
.krt-keg-row.active { background: #FFF6EC; }
.krt-keg-thumb {
  width: 44px; height: 44px; border-radius: 10px;
  border: 1px solid #ECE3D6; background: #FFF8F0;
  overflow: hidden; padding: 0; cursor: zoom-in;
  display: flex; align-items: center; justify-content: center;
  font-family: inherit;
}
.krt-keg-thumb:disabled { cursor: default; }
.krt-keg-thumb.has-photo:hover { border-color: #E76F51; }
.krt-keg-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.krt-keg-thumb-placeholder { width: 22px; height: 22px; color: #C7B9A7; }
.krt-keg-thumb-placeholder svg { width: 100%; height: 100%; }
.krt-keg-info { min-width: 0; }
.krt-keg-name { font-size: 14px; font-weight: 500; color: #2C1A12; line-height: 1.3; }
.krt-keg-code {
  display: inline-block; font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace;
  font-size: 11px; font-weight: 600; color: #8C7B6E;
  background: #FFF8F0; border: 1px solid #ECE3D6; border-radius: 5px;
  padding: 1px 6px; margin-right: 6px; vertical-align: 1px;
  letter-spacing: 0.02em;
}

/* Степпер */
.krt-stepper {
  display: inline-flex; align-items: stretch; border-radius: 10px;
  border: 1.5px solid #E8DCC8; overflow: hidden;
  background: #fff;
}
.krt-stepper.disabled { opacity: .55; }
.krt-step-btn {
  width: 36px; min-height: 36px;
  background: #FFFBF6; color: #6B5344; border: none;
  font-size: 18px; font-weight: 600; cursor: pointer;
  display: inline-flex; align-items: center; justify-content: center;
  font-family: inherit;
  transition: background .12s, color .12s;
}
.krt-step-btn:hover:not(:disabled) { background: #E76F51; color: #fff; }
.krt-step-btn:disabled { opacity: .35; cursor: not-allowed; }
.krt-step-input {
  width: 48px; border: none; border-left: 1px solid #ECE3D6; border-right: 1px solid #ECE3D6;
  text-align: center; font-size: 15px; font-weight: 700;
  color: #2C1A12; background: transparent; padding: 0; min-height: 36px;
  font-family: inherit;
  -moz-appearance: textfield;
}
.krt-step-input::-webkit-outer-spin-button,
.krt-step-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.krt-step-input:disabled { color: #8C7B6E; }

.krt-keg-summary {
  margin-top: 12px; padding: 10px 14px; border-radius: 10px;
  background: #F4FBF4; border: 1px solid #C8E6C9; color: #2E7D32;
  font-size: 13.5px; text-align: center;
}
.krt-keg-summary.empty {
  background: #F7F2EB; border-color: #E8DCC8; color: #8C7B6E;
}
.krt-keg-summary strong { font-weight: 700; }

/* ═══ Полоса действий ═══ */
.krt-actions-bar {
  position: sticky; bottom: 0;
  margin: 12px -20px 0; padding: 10px 20px 12px;
  background: linear-gradient(to top, #FAF6EF 70%, rgba(250,246,239,0));
  z-index: 20;
}
.krt-actions-bar-inner {
  display: flex; flex-wrap: wrap; gap: 8px;
  background: #fff; border: 1px solid #ECE3D6; border-radius: 12px;
  padding: 10px 12px;
  box-shadow: 0 -2px 8px rgba(80,35,20,.05);
}
.krt-actions-bar-inner .krt-btn { flex: 1 1 auto; min-width: 110px; }
.krt-actions-bar-extra {
  display: flex; justify-content: flex-end; padding: 6px 6px 0;
}

/* ═══ Просмотр фото ═══ */
.krt-photo-overlay {
  position: fixed; inset: 0; z-index: 1000;
  background: rgba(20,10,5,.7); backdrop-filter: blur(2px);
  display: flex; align-items: center; justify-content: center; padding: 20px;
}
.krt-photo-modal {
  background: #fff; border-radius: 14px; overflow: hidden;
  max-width: 100%; max-height: 100%;
  display: flex; flex-direction: column;
}
.krt-photo-head {
  display: flex; align-items: center; justify-content: space-between;
  gap: 16px; padding: 12px 14px; background: #2C1A12; color: #fff;
  font-size: 14px;
}
.krt-photo-close {
  background: none; border: none; color: #fff;
  font-size: 28px; line-height: 1; cursor: pointer; padding: 0 6px;
  font-family: inherit;
}
.krt-photo-modal img {
  display: block; max-width: 80vw; max-height: 78vh; object-fit: contain;
  background: #FAF6EF;
}

/* ═══ Подтверждение ═══ */
.krt-confirm-overlay {
  position: fixed; inset: 0; z-index: 1100;
  background: rgba(20,10,5,.55);
  display: flex; align-items: center; justify-content: center; padding: 16px;
}
.krt-confirm {
  background: #fff; border-radius: 14px;
  padding: 22px 22px 18px; max-width: 420px; width: 100%;
  box-shadow: 0 10px 40px rgba(0,0,0,.2);
}
.krt-confirm h3 { margin: 0 0 8px; font-size: 17px; color: #2C1A12; }
.krt-confirm p { margin: 0 0 18px; color: #6B5344; font-size: 14px; line-height: 1.5; }
.krt-confirm-actions { display: flex; gap: 8px; justify-content: flex-end; }

/* ═══ Прочее ═══ */
.krt-empty { text-align: center; color: #8C7B6E; padding: 32px 0; }
.krt-error { color: #E53935; padding: 12px 0; }

/* ═══ Мобилка ═══ */
@media (max-width: 640px) {
  .krt-wrap { padding: 14px 14px 130px; }
  .krt-header { gap: 12px; }
  .krt-title { font-size: 19px; }
  .krt-card-body { padding: 12px 14px; }
  .krt-card-arrow { padding: 0 10px; font-size: 18px; }
  .krt-card-date { font-size: 15px; }

  .krt-fld-row2 { grid-template-columns: 1fr 1fr; gap: 10px; }
  .krt-fld-row3 { grid-template-columns: 1fr; gap: 10px; }
  .krt-fld-row3 .krt-fld-bso-series,
  .krt-fld-row3 .krt-fld-bso-number {
    /* На мобилке — два БСО-поля в один ряд */
    grid-column: span 1;
  }
  .krt-fld-row3 {
    grid-template-areas: 'date date' 'series number';
    grid-template-columns: 1fr 1.4fr;
  }
  .krt-fld-row3 .krt-fld-date { grid-area: date; }
  .krt-fld-row3 .krt-fld-bso-series { grid-area: series; }
  .krt-fld-row3 .krt-fld-bso-number { grid-area: number; }
  .krt-section { padding: 14px 14px 12px; border-radius: 12px; }

  .krt-keg-row { grid-template-columns: 40px 1fr auto; gap: 10px; padding: 8px 6px; }
  .krt-keg-thumb { width: 40px; height: 40px; }
  .krt-step-btn { width: 38px; min-height: 38px; font-size: 19px; }
  .krt-step-input { width: 42px; min-height: 38px; font-size: 16px; }

  .krt-tip-bubble { right: 0; left: auto; }
  .krt-tip-bubble::before { left: auto; right: 8px; }

  .krt-actions-bar { margin: 12px -14px 0; padding: 10px 14px 14px; }
  .krt-actions-bar-inner { flex-direction: column; }
  .krt-actions-bar-inner .krt-btn { width: 100%; padding: 12px; font-size: 15px; }

  .krt-photo-modal img { max-width: 92vw; max-height: 70vh; }
}
</style>
