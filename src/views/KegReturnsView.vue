<template>
  <div class="kr-page">
    <div class="kr-page-header">
      <h1 class="page-title">Возврат кег</h1>
      <div class="kr-page-actions">
        <router-link :to="{ name: 'keg-returns-schedule' }" class="btn">График</router-link>
        <button class="btn" @click="exportExcel" :disabled="!filteredRows.length">📥 Экспорт в Excel</button>
        <button class="btn" @click="openImport">Импорт маршрутизации</button>
        <button class="btn" @click="openNotify" title="Адреса бухгалтерии для писем «Не сдана»">✉️ Письма бухгалтерии</button>
        <button class="btn primary" @click="createOpen = true">+ Создать заявку</button>
      </div>
    </div>

    <div class="kr-toolbar">
      <select v-model="filters.legal_entity" title="Юрлицо — для отдельной выгрузки по БК и ВМ">
        <option value="">Все юрлица</option>
        <option value="BK">Бургер БК</option>
        <option value="VM">Воглия Матта</option>
      </select>
      <select v-model="filters.status">
        <option value="">Все статусы</option>
        <option value="SUBMITTED">Отправлена</option>
        <option value="ROUTED">Маршрутизирована</option>
        <option value="NOT_RETURNED">Не сдана</option>
        <option value="CANCELLED">Отменена</option>
      </select>
      <select v-model="filters.restaurant_id">
        <option value="">Все рестораны</option>
        <option v-for="r in filterRestaurantOptions" :key="r.id" :value="r.id">№{{ r.number }} — {{ r.address }}</option>
      </select>
      <input v-model="filters.from" type="date" />
      <input v-model="filters.to" type="date" />
    </div>

    <div v-if="loading" class="kr-loading">Загрузка...</div>
    <div v-else-if="error" class="kr-error">{{ error }}</div>
    <div v-else class="kr-table-wrap">
    <table class="kr-table">
      <thead>
        <tr>
          <th>Ресторан</th>
          <th>Адрес погрузки</th>
          <th>Дата возврата</th>
          <th>№ БСО</th>
          <th>Статус</th>
          <th>Кег</th>
          <th>Водитель</th>
          <th>Машина</th>
          <th>Обновлено</th>
          <th class="kr-th-actions">Действия</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in filteredRows" :key="row.id" @click="openEdit(row.id)" class="kr-row">
          <td>№{{ row.restaurant_number }} {{ row.restaurant_city }}<span v-if="row.restaurant_address">, {{ row.restaurant_address }}</span></td>
          <td>{{ pickupAddressFor(row) }}</td>
          <td>{{ fmtDate(row.return_date) }}</td>
          <td>
            {{ row.bso_series }} {{ row.bso_number }}
            <span v-if="Number(row.bso_replaced_count) > 0" class="kr-bso-replaced" :title="'БСО заменён ' + row.bso_replaced_count + ' раз'">
              ↻{{ row.bso_replaced_count }}
            </span>
          </td>
          <td><span :class="'kr-badge kr-badge-' + row.status">{{ statusLabel(row.status) }}</span></td>
          <td>{{ row.total_kegs != null ? row.total_kegs : '—' }}</td>
          <td>{{ row.driver || '—' }}</td>
          <td>{{ row.vehicle || '—' }}</td>
          <td>{{ fmtDateTime(row.updated_at || row.submitted_at || row.created_at) }}</td>
          <td class="kr-td-actions" @click.stop>
            <button class="kr-action-btn" @click="openEdit(row.id)" title="Открыть">✏️</button>
            <button class="kr-action-btn kr-action-del" @click="deleteRow(row)" title="Удалить">🗑️</button>
          </td>
        </tr>
        <tr v-if="!filteredRows.length">
          <td colspan="10" class="kr-empty">Нет заявок</td>
        </tr>
      </tbody>
    </table>
    </div>

    <KegReturnEditModal v-if="editId" :id="editId" @close="editId = null; loadList()" />

    <KegReturnCreateModal
      v-if="createOpen"
      :restaurants="restaurants"
      @close="createOpen = false"
      @created="createOpen = false; loadList()"
    />

    <!-- Адреса бухгалтерии для писем «Не сдана» -->
    <Teleport v-if="notifyOpen" to="body">
      <div class="modal" @click.self="notifyOpen = false">
        <div class="modal-box kr-notify-modal">
          <div class="modal-header">
            <h2>Письма бухгалтерии</h2>
            <button class="modal-close" @click="notifyOpen = false">✕</button>
          </div>
          <div class="kr-notify-body">
            <p class="kr-notify-hint">
              На эти адреса уйдёт письмо, когда заявку отметят «Не сдана» (ресторан или закупка).
              Несколько адресов — через запятую. Пусто — письма не отправляются.
            </p>
            <div v-if="notifyLoading" class="kr-loading">Загрузка...</div>
            <template v-else>
              <textarea v-model="notifyEmails" rows="3" class="kr-notify-input"
                placeholder="buh1@company.by, buh2@company.by"></textarea>
              <div v-if="notifyError" class="kr-error">{{ notifyError }}</div>
            </template>
          </div>
          <div class="modal-actions" style="justify-content:flex-end;gap:8px">
            <button class="btn" @click="notifyOpen = false" :disabled="notifySaving">Отмена</button>
            <button class="btn primary" @click="saveNotify" :disabled="notifySaving || notifyLoading">
              {{ notifySaving ? 'Сохранение...' : 'Сохранить' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Модалка импорта маршрутизации -->
    <Teleport v-if="importOpen" to="body">
      <div class="modal" @click.self="closeImport">
        <div class="modal-box kr-import-modal">
          <div class="modal-header">
            <h2>Импорт маршрутизации</h2>
            <button class="modal-close" @click="closeImport">✕</button>
          </div>

          <div class="kr-import-body">
            <div class="kr-em-field">
              <label class="kr-em-label">Дата возврата</label>
              <input v-model="importDate" type="date" class="kr-em-input" :disabled="importLoading" />
            </div>
            <div class="kr-em-field" style="margin-top:10px">
              <label class="kr-em-label">Файл (.xlsx / .xls)</label>
              <input ref="importFileInput" type="file" accept=".xlsx,.xls" @change="onImportFile" :disabled="importLoading" />
            </div>
            <div style="margin-top:8px; font-size:12px; color:#8B7355">
              Нет файла под рукой?
              <a href="#" @click.prevent="downloadImportTemplate" class="kr-tmpl-link">Скачать шаблон с актуальными адресами</a> и отправить логистам.
            </div>

            <div v-if="importError" class="kr-em-save-error" style="margin-top:10px">{{ importError }}</div>

            <!-- Превью -->
            <div v-if="importPreview.length" class="kr-import-preview">
              <div class="kr-import-preview-title">
                Результат парсинга ({{ importPreview.length }} строк).
                Учитываются только строки склада «Прилесье 6» (сухой) — оттуда забирают кеги.
                Если строка не сопоставилась автоматически — выберите заявку в селекте.
              </div>
              <div class="kr-import-search">
                <input
                  v-model="importSearch"
                  type="search"
                  placeholder="Поиск по адресу, заказчику или водителю…"
                  class="kr-import-search-input"
                />
                <span v-if="importSearch" class="kr-import-search-count">
                  Найдено {{ filteredImportPreview.length }} из {{ importPreview.length }}
                </span>
              </div>
              <table class="kr-import-table">
                <thead>
                  <tr>
                    <th>Адрес из файла</th>
                    <th>Водитель</th>
                    <th>Машина</th>
                    <th>Заявка</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="!filteredImportPreview.length">
                    <td colspan="4" class="kr-import-empty">Ничего не найдено по запросу «{{ importSearch }}».</td>
                  </tr>
                  <tr
                    v-for="{ p, i } in filteredImportPreview"
                    :key="i"
                    :class="{
                      'kr-import-warn': effectiveReqId(i, p) === null,
                      'kr-import-ok': effectiveReqId(i, p) !== null,
                      'kr-import-override': Object.prototype.hasOwnProperty.call(importOverrides, i),
                    }"
                  >
                    <td>
                      <div>{{ p.row.address }}</div>
                      <div v-if="p.warning && !Object.prototype.hasOwnProperty.call(importOverrides, i)" class="kr-import-hint">
                        ⚠ {{ p.warning }}
                      </div>
                    </td>
                    <td>{{ p.row.driver || '—' }}</td>
                    <td>{{ p.row.vehicle || '—' }}</td>
                    <td>
                      <select
                        class="kr-import-select"
                        :value="effectiveReqId(i, p) === null ? 'null' : String(effectiveReqId(i, p))"
                        @change="setOverride(i, $event.target.value)"
                      >
                        <option value="null">— не назначать —</option>
                        <option v-for="a in importAvailable" :key="a.request_id" :value="a.request_id">
                          {{ formatAvailable(a) }}
                        </option>
                      </select>
                      <div v-if="p.match && effectiveReqId(i, p) !== p.match.request_id" class="kr-import-hint">
                        Авто-матч: №{{ p.match.restaurant_number }}
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Прогресс импорта в подвале — всегда видно над кнопками, не зависит
               от того, докуда пользователь прокрутил таблицу превью. Точный
               процент только во время загрузки файла (XHR upload.onprogress);
               пока бэк парсит — индетерминированная «бегущая полоска». -->
          <div v-if="importLoading && importPhase" class="kr-import-progress kr-import-progress-footer">
            <div class="kr-import-progress-bar">
              <div
                v-if="importPhase === 'upload'"
                class="kr-import-progress-fill"
                :style="{ width: importUploadPct + '%' }"
              ></div>
              <div v-else class="kr-import-progress-indeterminate"></div>
            </div>
            <div class="kr-import-progress-label">
              <template v-if="importPhase === 'upload'">
                Загружаем файл: {{ importUploadPct }}%
              </template>
              <template v-else>
                Обрабатываем на сервере…
              </template>
            </div>
          </div>

          <div class="modal-actions" style="justify-content:flex-end;gap:8px">
            <button class="btn" @click="closeImport" :disabled="importLoading">Отмена</button>
            <button
              v-if="importPreview.length"
              class="btn primary"
              @click="commitImport"
              :disabled="importLoading || !importPreview.some((p, i) => effectiveReqId(i, p) !== null)"
              :title="importPreview.some((p, i) => effectiveReqId(i, p) !== null) ? 'Применить выбранные сопоставления' : 'Выберите хотя бы одну заявку'"
            >
              <template v-if="!importLoading">Применить маршрутизацию</template>
              <template v-else-if="importPhase === 'upload'">Загружаем {{ importUploadPct }}%</template>
              <template v-else>Обрабатываем…</template>
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, defineAsyncComponent } from 'vue';
import { db } from '@/lib/apiClient.js';
import { appConfirm } from '@/lib/appDialogs.js';

function authHeaders(extra = {}) {
  const t = localStorage.getItem('bk_session_token') || '';
  const h = { ...extra };
  if (t) h['X-Session-Token'] = t;
  return h;
}

const KegReturnEditModal = defineAsyncComponent(() => import('@/components/modals/KegReturnEditModal.vue'));
const KegReturnCreateModal = defineAsyncComponent(() => import('@/components/modals/KegReturnCreateModal.vue'));

const rows = ref([]);
const restaurants = ref([]);
const loading = ref(false);
const error = ref('');
const editId = ref(null);
const createOpen = ref(false);

const filters = ref({ status: '', restaurant_id: '', from: '', to: '', legal_entity: '' });

// Адреса бухгалтерии для писем при статусе «Не сдана»
const notifyOpen = ref(false);
const notifyEmails = ref('');
const notifyLoading = ref(false);
const notifySaving = ref(false);
const notifyError = ref('');

async function openNotify() {
  notifyOpen.value = true;
  notifyError.value = '';
  notifyLoading.value = true;
  try {
    const res = await fetch('/api/keg-returns/not-returned-emails', { credentials: 'include', headers: authHeaders() });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка');
    notifyEmails.value = data.emails || '';
  } catch (e) {
    notifyError.value = e.message;
  } finally {
    notifyLoading.value = false;
  }
}
async function saveNotify() {
  notifySaving.value = true;
  notifyError.value = '';
  try {
    const res = await fetch('/api/keg-returns/not-returned-emails', {
      method: 'POST',
      credentials: 'include',
      headers: { ...authHeaders(), 'Content-Type': 'application/json' },
      body: JSON.stringify({ emails: notifyEmails.value }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка');
    notifyEmails.value = data.emails || '';
    notifyOpen.value = false;
  } catch (e) {
    notifyError.value = e.message;
  } finally {
    notifySaving.value = false;
  }
}

// Импорт маршрутизации
const importOpen = ref(false);
const importDate = ref('');
const importFileInput = ref(null);
const importPreview = ref([]);
const importAvailable = ref([]);     // список SUBMITTED заявок на выбранную дату — для селекта ручного матча
const importOverrides = ref({});     // { rowIdx: request_id|null } — выбор пользователя
const importSearch = ref('');         // строка поиска по адресам импортируемых строк
const importError = ref('');
const importLoading = ref(false);
const importFile = ref(null);
const importPhase = ref('');         // 'upload' (грузим файл) или 'processing' (бэк парсит). '' — простой.
const importUploadPct = ref(0);      // 0–100, реальный процент загрузки байтов

// Завернули fetch для multipart на XHR, чтобы получить честный прогресс загрузки.
// fetch таких хуков не даёт. После того как файл ушёл — переключаемся в
// индетерминированный режим («бегущая полоска»), потому что бэк не отдаёт
// промежуточный прогресс парсинга — это один HTTP-запрос.
function xhrUpload(url, formData, { onUploadPct, onUploadDone }) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url);
    xhr.withCredentials = true;
    const token = localStorage.getItem('bk_session_token') || '';
    if (token) xhr.setRequestHeader('X-Session-Token', token);
    xhr.upload.onprogress = e => {
      if (!e.lengthComputable) return;
      onUploadPct?.(Math.round((e.loaded / e.total) * 100));
    };
    xhr.upload.onload = () => onUploadDone?.();
    xhr.onerror = () => reject(new Error('Сетевая ошибка'));
    xhr.onload = () => {
      let data = null;
      try { data = JSON.parse(xhr.responseText); } catch {}
      if (xhr.status >= 200 && xhr.status < 300) {
        resolve(data);
      } else {
        reject(new Error(data?.error || `Ошибка ${xhr.status}`));
      }
    };
    xhr.send(formData);
  });
}

// Превью с учётом строки поиска. Сохраняем оригинальный индекс — ключи в
// importOverrides и аналогии «авто-матч» завязаны на индексе исходного preview.
const filteredImportPreview = computed(() => {
  const q = importSearch.value.trim().toLowerCase();
  const arr = importPreview.value.map((p, i) => ({ p, i }));
  if (!q) return arr;
  return arr.filter(({ p }) => {
    const addr = (p.row?.address || '').toLowerCase();
    const cust = (p.row?.customer || '').toLowerCase();
    const drv  = (p.row?.driver  || '').toLowerCase();
    return addr.includes(q) || cust.includes(q) || drv.includes(q);
  });
});

// Эффективный request_id для строки превью: оверрайд побеждает авто-матч.
// Возвращает null если «не назначать».
function effectiveReqId(idx, p) {
  if (Object.prototype.hasOwnProperty.call(importOverrides.value, idx)) {
    return importOverrides.value[idx];
  }
  return p.match?.request_id ?? null;
}

function setOverride(idx, val) {
  // val: 'null' (явно не назначать), '' (вернуть к авто-матчу) или request_id
  const next = { ...importOverrides.value };
  if (val === '' || val === undefined) {
    delete next[idx];
  } else if (val === 'null') {
    next[idx] = null;
  } else {
    next[idx] = Number(val);
  }
  importOverrides.value = next;
}

// Хелпер для подписи в селекте
function formatAvailable(a) {
  const city = a.restaurant_city || '';
  const addr = a.restaurant_address || '';
  const where = [city, addr].filter(Boolean).join(', ');
  return `№${a.restaurant_number} — ${where}`;
}

async function loadList() {
  loading.value = true;
  error.value = '';
  try {
    const res = await fetch('/api/keg-returns', { credentials: 'include', headers: authHeaders() });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка загрузки');
    rows.value = Array.isArray(data) ? data : [];
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}

async function loadRestaurants() {
  try {
    const { data } = await db.from('restaurants')
      .select('id,number,address,city,legal_entity_group,pickup_weekdays,default_vehicle,default_driver,active,keg_returns_enabled')
      .order('number');
    restaurants.value = (data || []).filter(r => r.active !== false && r.active !== 0);
  } catch {}
}

// Рестораны для селекта-фильтра: прячем тех, у кого возврат кег отключён
// (keg_returns_enabled = 0). NULL/единица — это включено по умолчанию схемы.
const filterRestaurantOptions = computed(() =>
  restaurants.value.filter(r => Number(r.keg_returns_enabled ?? 1) !== 0)
);

const filteredRows = computed(() => {
  return rows.value.filter(r => {
    if (filters.value.status && r.status !== filters.value.status) return false;
    if (filters.value.restaurant_id && String(r.restaurant_id) !== String(filters.value.restaurant_id)) return false;
    if (filters.value.from && r.return_date < filters.value.from) return false;
    if (filters.value.to && r.return_date > filters.value.to) return false;
    // Юрлицо: ресторан №3 — Воглия Матта, остальные — Бургер БК
    if (filters.value.legal_entity === 'VM' && Number(r.restaurant_number) !== 3) return false;
    if (filters.value.legal_entity === 'BK' && Number(r.restaurant_number) === 3) return false;
    return true;
  });
});

function openEdit(id) {
  editId.value = id;
}

async function deleteRow(row) {
  if (!(await appConfirm(`Удалить заявку №${row.bso_series || ''} ${row.bso_number || row.id}?`, { okText: 'Удалить', danger: true }))) return;
  try {
    const res = await fetch(`/api/keg-returns/${row.id}`, { method: 'DELETE', credentials: 'include', headers: authHeaders() });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка');
    await loadList();
  } catch (e) { error.value = e.message; }
}

async function exportExcel() {
  try {
    // Передаём ровно те же фильтры, что выставлены на странице — чтобы Excel
    // совпадал с тем, что пользователь видит в таблице.
    const qp = new URLSearchParams();
    if (filters.value.status) qp.set('status', filters.value.status);
    if (filters.value.restaurant_id) qp.set('restaurant_id', filters.value.restaurant_id);
    if (filters.value.legal_entity) qp.set('legal_entity', filters.value.legal_entity);
    if (filters.value.from) qp.set('from', filters.value.from);
    if (filters.value.to) qp.set('to', filters.value.to);
    const url0 = '/api/keg-returns/export' + (qp.toString() ? ('?' + qp.toString()) : '');
    const res = await fetch(url0, { credentials: 'include', headers: authHeaders() });
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      throw new Error(data.error || 'Ошибка экспорта');
    }
    const blob = await res.blob();
    const cd = res.headers.get('Content-Disposition') || '';
    const m = cd.match(/filename="?([^"]+)"?/);
    const filename = m ? m[1] : 'keg-returns.xlsx';
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
  } catch (e) { error.value = e.message; }
}

function openImport() {
  importPreview.value = [];
  importAvailable.value = [];
  importOverrides.value = {};
  importSearch.value = '';
  importError.value = '';
  importFile.value = null;
  importOpen.value = true;
}

function closeImport() {
  importOpen.value = false;
  importPreview.value = [];
  importAvailable.value = [];
  importOverrides.value = {};
  importSearch.value = '';
  importError.value = '';
  importFile.value = null;
}

async function downloadImportTemplate() {
  try {
    const t = localStorage.getItem('bk_session_token') || '';
    const res = await fetch('/api/keg-returns/import-template.xlsx', { headers: t ? { 'X-Session-Token': t } : {} });
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      throw new Error(data.error || 'Не удалось получить шаблон');
    }
    const blob = await res.blob();
    const cd = res.headers.get('Content-Disposition') || '';
    const m = cd.match(/filename="?([^"]+)"?/);
    const filename = m ? m[1] : 'keg_routing_template.xlsx';
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
  } catch (e) {
    importError.value = e.message;
  }
}

async function onImportFile(e) {
  const file = e.target.files[0];
  if (!file) return;
  importFile.value = file;
  if (!importDate.value) {
    importError.value = 'Укажите дату возврата';
    return;
  }
  await runImportPreview();
}

async function runImportPreview() {
  if (!importFile.value || !importDate.value) return;
  importError.value = '';
  importLoading.value = true;
  importPhase.value = 'upload';
  importUploadPct.value = 0;
  try {
    const fd = new FormData();
    fd.append('file', importFile.value);
    fd.append('return_date', importDate.value);
    const data = await xhrUpload('/api/keg-returns/import-routing', fd, {
      onUploadPct: p => { importUploadPct.value = p; },
      onUploadDone: () => { importPhase.value = 'processing'; },
    });
    importPreview.value = data.preview || [];
    importAvailable.value = data.available_requests || [];
    importOverrides.value = {};  // новый файл — сбрасываем ручные правки
    importSearch.value = '';      // и поиск тоже
  } catch (e) {
    importError.value = e.message;
    importPreview.value = [];
    importAvailable.value = [];
    importOverrides.value = {};
  } finally {
    importLoading.value = false;
    importPhase.value = '';
    importUploadPct.value = 0;
  }
}

async function commitImport() {
  if (!importFile.value || !importDate.value) return;
  importError.value = '';
  importLoading.value = true;
  importPhase.value = 'upload';
  importUploadPct.value = 0;
  try {
    const fd = new FormData();
    fd.append('file', importFile.value);
    fd.append('return_date', importDate.value);
    fd.append('commit', 'true');
    // Сериализуем оверрайды: ключ — индекс строки превью, значение — request_id или null.
    fd.append('overrides', JSON.stringify(importOverrides.value));
    await xhrUpload('/api/keg-returns/import-routing', fd, {
      onUploadPct: p => { importUploadPct.value = p; },
      onUploadDone: () => { importPhase.value = 'processing'; },
    });
    closeImport();
    await loadList();
  } catch (e) {
    importError.value = e.message;
  } finally {
    importLoading.value = false;
    importPhase.value = '';
    importUploadPct.value = 0;
  }
}

function pickupAddressFor(row) {
  // Адрес погрузки: явный pickup_address; если пуст — собираем из города и адреса ресторана.
  if (row.pickup_address) return row.pickup_address;
  const city = row.restaurant_city || '';
  const addr = row.restaurant_address || '';
  if (city && addr) return city + ', ' + addr;
  return city || addr || '—';
}

function fmtDate(d) {
  if (!d) return '—';
  const dt = new Date(d);
  if (isNaN(dt)) return d;
  return dt.toLocaleDateString('ru-RU');
}

function fmtDateTime(d) {
  if (!d) return '—';
  const dt = new Date(d);
  if (isNaN(dt)) return d;
  return dt.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function statusLabel(s) {
  const map = { DRAFT: 'Черновик', SUBMITTED: 'Отправлена', ROUTED: 'Маршрутизирована', CANCELLED: 'Отменена', NOT_RETURNED: 'Не сдана' };
  return map[s] || s;
}

onMounted(() => {
  loadList();
  loadRestaurants();
});
</script>

<style scoped>
.kr-page { padding: 24px; max-width: 100%; }
.kr-table-wrap { overflow-x: auto; }
.kr-table { min-width: 1200px; }
.kr-page-header { display: flex; align-items: center; gap: 16px; margin-bottom: 20px; }
.kr-page-header .page-title { margin-bottom: 0; flex: 1; }
.page-title { font-size: 22px; font-weight: 700; }
.kr-toolbar { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
.kr-toolbar select,
.kr-toolbar input { padding: 7px 10px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 14px; background: var(--input-bg, #fff); color: inherit; }
.kr-page-actions { display: flex; gap: 8px; }
.kr-table { width: 100%; border-collapse: collapse; font-size: 14px; }
.kr-table th { text-align: left; padding: 9px 12px; border-bottom: 2px solid var(--border, #d8d2c4); font-weight: 700; font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px; color: var(--text, #2b2b2b); background: var(--card, #fff); position: sticky; top: 0; z-index: 1; }
.kr-th-actions { width: 110px; }
.kr-td-actions { white-space: nowrap; }
.kr-action-btn { padding: 4px 8px; margin: 0 2px; border: 1px solid var(--border, #ddd); border-radius: 6px; background: var(--card, #fff); cursor: pointer; font-size: 14px; }
.kr-action-btn:hover { background: var(--hover-bg, #f5f5f5); }
.kr-action-del:hover { background: #ffebee; border-color: #e53935; }
.kr-row { cursor: pointer; }
.kr-row:hover td { background: var(--hover-bg, #f5f5f5); }
.kr-table td { padding: 9px 12px; border-bottom: 1px solid var(--border-color, #eee); }
.kr-empty { text-align: center; color: var(--text-secondary, #999); padding: 32px; }
.kr-loading, .kr-error { padding: 24px; text-align: center; }
.kr-error { color: var(--danger, #e53935); }
.kr-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: 600; }
.kr-badge-DRAFT { background: #e0e0e0; color: #555; }
.kr-badge-SUBMITTED { background: #fff3e0; color: #e65100; }
.kr-badge-ROUTED { background: #e8f5e9; color: #2e7d32; }
.kr-badge-CANCELLED { background: #fce4ec; color: #c62828; }
.kr-badge-NOT_RETURNED { background: #fde0dc; color: #b71c1c; }
.kr-notify-modal { max-width: 460px; width: 100%; }
.kr-notify-body { padding: 4px 0 12px; }
.kr-notify-hint { margin: 0 0 10px; font-size: 13px; color: #6b5b4a; line-height: 1.45; }
.kr-notify-input { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #d9cfc0; border-radius: 6px; font: inherit; resize: vertical; }
.kr-bso-replaced {
  display: inline-block; margin-left: 6px;
  padding: 1px 7px; border-radius: 999px;
  background: #FFE0B2; color: #C16B4D;
  font-size: 11px; font-weight: 700; line-height: 1.6;
  vertical-align: 1px;
}
.kr-import-modal { max-width: 920px; width: 100%; }
.kr-import-body { padding: 12px 0; }
.kr-em-field { display: flex; align-items: center; gap: 12px; }
/* Контраст важнее, чем визуальная иерархия — раньше брал --text-secondary (#6B5344),
   на белой карточке выглядел как «грязно-серый», пользователь не видел подписи. */
.kr-em-label {
  width: 150px; flex-shrink: 0;
  font-size: 13px; font-weight: 600;
  color: var(--text, #2C1810);
}
.kr-em-input { flex: 1; padding: 6px 10px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 14px; background: var(--input-bg, #fff); color: inherit; }
.kr-em-save-error { color: var(--danger, #e53935); font-size: 13px; }
.kr-import-preview { margin-top: 16px; overflow-x: auto; }
.kr-import-preview-title {
  font-size: 13px; font-weight: 600; margin-bottom: 8px;
  color: var(--text, #2C1810);
}
.kr-import-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.kr-import-table thead { background: var(--bk-brown, #502314); }
.kr-import-table th {
  text-align: left; padding: 8px 10px;
  border-bottom: 2px solid var(--bk-brown, #502314);
  font-weight: 700; font-size: 12px;
  text-transform: uppercase; letter-spacing: 0.4px;
  color: #fff;
}
.kr-import-table td { padding: 8px 10px; border-bottom: 1px solid var(--border-color, #eee); vertical-align: top; }
.kr-import-ok td { background: #f1f8e9; }
.kr-import-warn td { background: #fff8e1; }
.kr-import-override td { background: #e8f0fe; }
.kr-import-select {
  width: 100%; padding: 5px 8px;
  border: 1px solid var(--border-color, #ddd); border-radius: 6px;
  font-size: 13px; background: var(--input-bg, #fff); color: inherit;
}
.kr-import-hint {
  font-size: 11.5px; color: #8B7355;
  margin-top: 3px;
}
.kr-import-search {
  display: flex; align-items: center; gap: 10px;
  margin-bottom: 8px;
}
.kr-import-search-input {
  flex: 1;
  padding: 7px 10px;
  border: 1px solid var(--border-color, #ddd);
  border-radius: 6px;
  font-size: 13px;
  background: var(--input-bg, #fff);
  color: inherit;
}
.kr-import-search-count {
  font-size: 12px; color: var(--text-secondary, #6B5344);
  white-space: nowrap;
}
.kr-import-empty {
  padding: 14px; text-align: center;
  color: var(--text-secondary, #6B5344);
  font-style: italic;
}
.kr-import-progress {
  margin-top: 12px;
  display: flex; flex-direction: column; gap: 6px;
}
/* Подвальный вариант: блок прогресса между таблицей превью и кнопками.
   Пользователь к моменту клика «Применить» уже прокручен вниз, поэтому
   полоска должна быть прямо там, а не на самом верху модалки. */
.kr-import-progress-footer {
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px solid var(--border-color, #eee);
}
.kr-import-progress-bar {
  position: relative;
  height: 8px; width: 100%;
  background: #F0E8DC;
  border-radius: 4px; overflow: hidden;
}
.kr-import-progress-fill {
  height: 100%;
  background: linear-gradient(90deg, #E76F51 0%, #F4A261 100%);
  transition: width .15s ease-out;
}
.kr-import-progress-indeterminate {
  position: absolute;
  inset: 0;
  background: linear-gradient(90deg, transparent 0%, #E76F51 35%, #F4A261 65%, transparent 100%);
  animation: krProgressSlide 1.2s infinite linear;
}
@keyframes krProgressSlide {
  0%   { transform: translateX(-100%); }
  100% { transform: translateX(100%); }
}
.kr-import-progress-label {
  font-size: 12.5px;
  color: var(--text-secondary, #6B5344);
}
</style>
