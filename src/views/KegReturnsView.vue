<template>
  <div class="kr-page">
    <div class="kr-page-header">
      <h1 class="page-title">Возврат кег</h1>
      <div class="kr-page-actions">
        <router-link :to="{ name: 'keg-returns-schedule' }" class="btn">График</router-link>
        <button class="btn" @click="exportExcel" :disabled="!filteredRows.length">📥 Экспорт в Excel</button>
        <button class="btn primary" @click="openImport">Импорт маршрутизации</button>
      </div>
    </div>

    <div class="kr-toolbar">
      <select v-model="filters.status">
        <option value="">Все статусы</option>
        <option value="SUBMITTED">Отправлена</option>
        <option value="ROUTED">Маршрутизирована</option>
        <option value="CANCELLED">Отменена</option>
      </select>
      <select v-model="filters.restaurant_id">
        <option value="">Все рестораны</option>
        <option v-for="r in restaurants" :key="r.id" :value="r.id">№{{ r.number }} — {{ r.address }}</option>
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
              <div class="kr-import-preview-title">Результат парсинга ({{ importPreview.length }} строк)</div>
              <table class="kr-import-table">
                <thead>
                  <tr>
                    <th>Адрес из файла</th>
                    <th>Водитель</th>
                    <th>Машина</th>
                    <th>Заявка</th>
                    <th>Предупреждение</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(p, i) in importPreview" :key="i" :class="{'kr-import-warn': p.warning, 'kr-import-ok': !p.warning && p.match}">
                    <td>{{ p.row.address }}</td>
                    <td>{{ p.row.driver || '—' }}</td>
                    <td>{{ p.row.vehicle || '—' }}</td>
                    <td>
                      <template v-if="p.match">
                        #{{ p.match.request_id }} — №{{ p.match.restaurant_number }}
                      </template>
                      <template v-else>—</template>
                    </td>
                    <td>{{ p.warning || '' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div class="modal-actions" style="justify-content:flex-end;gap:8px">
            <button class="btn" @click="closeImport" :disabled="importLoading">Отмена</button>
            <button
              v-if="importPreview.length && importPreview.some(p => p.match && p.warning !== 'не найден')"
              class="btn primary"
              @click="commitImport"
              :disabled="importLoading"
            >
              {{ importLoading ? 'Применение...' : 'Применить маршрутизацию' }}
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

function authHeaders(extra = {}) {
  const t = localStorage.getItem('bk_session_token') || '';
  const h = { ...extra };
  if (t) h['X-Session-Token'] = t;
  return h;
}

const KegReturnEditModal = defineAsyncComponent(() => import('@/components/modals/KegReturnEditModal.vue'));

const rows = ref([]);
const restaurants = ref([]);
const loading = ref(false);
const error = ref('');
const editId = ref(null);

const filters = ref({ status: '', restaurant_id: '', from: '', to: '' });

// Импорт маршрутизации
const importOpen = ref(false);
const importDate = ref('');
const importFileInput = ref(null);
const importPreview = ref([]);
const importError = ref('');
const importLoading = ref(false);
const importFile = ref(null);

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
    const { data } = await db.from('restaurants').select('id,number,address,city').order('number');
    restaurants.value = data || [];
  } catch {}
}

const filteredRows = computed(() => {
  return rows.value.filter(r => {
    if (filters.value.status && r.status !== filters.value.status) return false;
    if (filters.value.restaurant_id && String(r.restaurant_id) !== String(filters.value.restaurant_id)) return false;
    if (filters.value.from && r.return_date < filters.value.from) return false;
    if (filters.value.to && r.return_date > filters.value.to) return false;
    return true;
  });
});

function openEdit(id) {
  editId.value = id;
}

async function deleteRow(row) {
  if (!confirm(`Удалить заявку №${row.bso_series || ''} ${row.bso_number || row.id}?`)) return;
  try {
    const res = await fetch(`/api/keg-returns/${row.id}`, { method: 'DELETE', credentials: 'include', headers: authHeaders() });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка');
    await loadList();
  } catch (e) { error.value = e.message; }
}

async function exportExcel() {
  try {
    const res = await fetch('/api/keg-returns/export', { credentials: 'include', headers: authHeaders() });
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
  importError.value = '';
  importFile.value = null;
  importOpen.value = true;
}

function closeImport() {
  importOpen.value = false;
  importPreview.value = [];
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
  try {
    const fd = new FormData();
    fd.append('file', importFile.value);
    fd.append('return_date', importDate.value);
    const res = await fetch('/api/keg-returns/import-routing', {
      method: 'POST',
      credentials: 'include',
      headers: authHeaders(),
      body: fd,
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка импорта');
    importPreview.value = data.preview || [];
  } catch (e) {
    importError.value = e.message;
    importPreview.value = [];
  } finally {
    importLoading.value = false;
  }
}

async function commitImport() {
  if (!importFile.value || !importDate.value) return;
  importError.value = '';
  importLoading.value = true;
  try {
    const fd = new FormData();
    fd.append('file', importFile.value);
    fd.append('return_date', importDate.value);
    fd.append('commit', 'true');
    const res = await fetch('/api/keg-returns/import-routing', {
      method: 'POST',
      credentials: 'include',
      headers: authHeaders(),
      body: fd,
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка при применении');
    closeImport();
    await loadList();
  } catch (e) {
    importError.value = e.message;
  } finally {
    importLoading.value = false;
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
  const map = { DRAFT: 'Черновик', SUBMITTED: 'Отправлена', ROUTED: 'Маршрутизирована', CANCELLED: 'Отменена' };
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
.kr-bso-replaced {
  display: inline-block; margin-left: 6px;
  padding: 1px 7px; border-radius: 999px;
  background: #FFE0B2; color: #C16B4D;
  font-size: 11px; font-weight: 700; line-height: 1.6;
  vertical-align: 1px;
}
.kr-import-modal { max-width: 820px; width: 100%; }
.kr-import-body { padding: 12px 0; }
.kr-em-field { display: flex; align-items: center; gap: 12px; }
.kr-em-label { width: 150px; flex-shrink: 0; font-size: 13px; color: var(--text-secondary, #666); }
.kr-em-input { flex: 1; padding: 6px 10px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 14px; background: var(--input-bg, #fff); color: inherit; }
.kr-em-save-error { color: var(--danger, #e53935); font-size: 13px; }
.kr-import-preview { margin-top: 16px; overflow-x: auto; }
.kr-import-preview-title { font-size: 13px; font-weight: 600; margin-bottom: 8px; color: var(--text-secondary, #666); }
.kr-import-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.kr-import-table th { text-align: left; padding: 6px 10px; border-bottom: 2px solid var(--border-color, #e0e0e0); font-weight: 600; color: var(--text-secondary, #666); }
.kr-import-table td { padding: 6px 10px; border-bottom: 1px solid var(--border-color, #eee); }
.kr-import-ok td { background: #f1f8e9; }
.kr-import-warn td { background: #fff8e1; }
</style>
