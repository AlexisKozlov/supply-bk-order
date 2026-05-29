<template>
  <div class="aei">
    <!-- Сводка -->
    <div class="aei-summary">
      <div class="aei-summary-item warn">
        <span class="aei-num">{{ counts.pending || 0 }}</span>
        <span class="aei-label">ждут</span>
      </div>
      <div class="aei-summary-item ok">
        <span class="aei-num">{{ counts.applied || 0 }}</span>
        <span class="aei-label">применено</span>
      </div>
      <div class="aei-summary-item off">
        <span class="aei-num">{{ counts.dismissed || 0 }}</span>
        <span class="aei-label">отклонено</span>
      </div>
      <div class="aei-summary-item err">
        <span class="aei-num">{{ (counts.rejected || 0) + (counts.error || 0) }}</span>
        <span class="aei-label">не приняты</span>
      </div>
    </div>

    <!-- Список писем -->
    <div class="aei-section">
      <div class="aei-section-title">
        Письма с импортом
        <div class="aei-section-right">
          <select v-model="filterStatus" class="aei-select">
            <option value="">Все статусы</option>
            <option value="pending">Ждут применения</option>
            <option value="applied">Применённые</option>
            <option value="dismissed">Отклонённые</option>
            <option value="rejected">Отказ системой</option>
          </select>
          <button class="aei-btn aei-btn-sm" @click="loadList" :disabled="loading">Обновить</button>
        </div>
      </div>

      <div v-if="loading" class="aei-empty">Загрузка…</div>
      <div v-else-if="!items.length" class="aei-empty">Писем нет</div>
      <div v-else class="aei-table-wrap">
        <table class="aei-table">
          <thead>
            <tr>
              <th class="c-date">Получено</th>
              <th class="c-from">От кого</th>
              <th class="c-subj">Тема / файл</th>
              <th class="c-type">Тип</th>
              <th class="c-le">Юр. лицо</th>
              <th class="c-status">Статус</th>
              <th class="c-actions">Действия</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in items" :key="row.id">
              <td class="c-date">{{ formatDateTime(row.received_at) }}</td>
              <td class="c-from">
                <div class="aei-from">
                  <span class="aei-from-name" v-if="row.from_name">{{ row.from_name }}</span>
                  <span class="aei-from-email">{{ row.from_email }}</span>
                </div>
              </td>
              <td class="c-subj">
                <div class="aei-subj">{{ row.subject || '—' }}</div>
                <div v-if="row.file_name" class="aei-file">
                  <BkIcon name="excel" size="xs" />
                  <span>{{ row.file_name }}</span>
                  <span v-if="row.size_bytes" class="aei-size">{{ formatSize(row.size_bytes) }}</span>
                </div>
              </td>
              <td class="c-type">{{ typeLabel(row.type) }}</td>
              <td class="c-le">{{ row.legal_entity || '—' }}</td>
              <td class="c-status">
                <span class="aei-badge" :class="'st-' + row.status">{{ statusLabel(row.status) }}</span>
                <div v-if="row.notes && (row.status === 'rejected' || row.status === 'error')" class="aei-notes">{{ row.notes }}</div>
                <div v-if="row.status === 'applied'" class="aei-notes">
                  {{ row.applied_by }} · {{ formatDateTime(row.applied_at) }}
                  <span v-if="row.applied_count">· {{ formatInt(row.applied_count) }}</span>
                </div>
              </td>
              <td class="c-actions">
                <template v-if="row.status === 'pending' && row.file_path">
                  <button class="aei-btn aei-btn-primary" @click="openInImport(row)">Открыть в импорте</button>
                  <button class="aei-btn aei-btn-sm" @click="downloadFile(row)">Скачать</button>
                  <button class="aei-btn aei-btn-sm aei-btn-warn" @click="dismiss(row)">Отклонить</button>
                </template>
                <template v-else-if="row.file_path">
                  <button class="aei-btn aei-btn-sm" @click="downloadFile(row)">Скачать</button>
                </template>
                <template v-else>—</template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Whitelist отправителей -->
    <div v-if="isAdmin" class="aei-section">
      <div class="aei-section-title">
        Доверенные отправители
        <div class="aei-section-right">
          <button class="aei-btn aei-btn-sm" @click="loadSenders" :disabled="sendersLoading">Обновить</button>
        </div>
      </div>

      <div class="aei-row aei-row-sender">
        <input v-model="newSender.email" type="email" placeholder="email@example.com" class="aei-input" />
        <select v-model="newSender.type" class="aei-select">
          <option value="restaurant_sales">Реализация ресторанов</option>
          <option value="analysis">Анализ запасов (остатки + расход)</option>
          <option value="stock_1c" disabled>Остатки 1С (скоро)</option>
        </select>
        <select v-model="newSender.legal_entity" class="aei-select">
          <option value="">Любое юрлицо</option>
          <option v-for="le in LEGAL_ENTITIES" :key="le" :value="le">{{ le }}</option>
        </select>
        <input v-model="newSender.note" type="text" placeholder="Заметка" class="aei-input" />
        <button class="aei-btn aei-btn-primary" @click="addSender" :disabled="!newSender.email">Добавить</button>
      </div>

      <div v-if="sendersLoading" class="aei-empty">Загрузка…</div>
      <div v-else-if="!senders.length" class="aei-empty">Список пуст. Письма от неизвестных адресов будут отклонены автоматически.</div>
      <div v-else class="aei-table-wrap">
        <table class="aei-table aei-table-senders">
          <thead>
            <tr>
              <th>Email</th>
              <th>Тип</th>
              <th>Юр. лицо</th>
              <th>Активен</th>
              <th>Заметка</th>
              <th class="c-actions">Действия</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in senders" :key="s.id">
              <td>{{ s.email }}</td>
              <td>{{ typeLabel(s.type) }}</td>
              <td>{{ s.legal_entity || '—' }}</td>
              <td>
                <label class="aei-switch">
                  <input type="checkbox" :checked="!!s.is_active" @change="toggleSender(s, $event.target.checked)" />
                  <span>{{ s.is_active ? 'да' : 'нет' }}</span>
                </label>
              </td>
              <td>{{ s.note || '—' }}</td>
              <td class="c-actions">
                <button class="aei-btn aei-btn-sm aei-btn-warn" @click="deleteSender(s)">Удалить</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { db } from '@/lib/apiClient.js';
import { useUserStore } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { appConfirm } from '@/lib/appDialogs.js';
import { LEGAL_ENTITIES } from '@/lib/legalEntities.js';
import BkIcon from '@/components/ui/BkIcon.vue';

const router = useRouter();
const userStore = useUserStore();
const toast = useToastStore();

const isAdmin = computed(() => userStore.currentUser?.role === 'admin');

const items = ref([]);
const counts = ref({});
const loading = ref(false);
const filterStatus = ref('');

const senders = ref([]);
const sendersLoading = ref(false);
const newSender = ref({ email: '', type: 'restaurant_sales', legal_entity: '', note: '' });

const TYPE_LABEL = {
  restaurant_sales: 'Реализация ресторанов',
  analysis: 'Анализ запасов',
  stock_1c: 'Остатки 1С',
  unknown: 'Неизвестный',
};
const STATUS_LABEL = {
  pending: 'Ждёт',
  applied: 'Применено',
  dismissed: 'Отклонено',
  rejected: 'Отказ',
  error: 'Ошибка',
};

function typeLabel(t) { return TYPE_LABEL[t] || t; }
function statusLabel(s) { return STATUS_LABEL[s] || s; }
function formatInt(n) { return new Intl.NumberFormat('ru-RU').format(n || 0); }
function formatDateTime(s) {
  if (!s) return '';
  const d = new Date((s || '').replace(' ', 'T'));
  if (isNaN(d)) return s;
  return d.toLocaleDateString('ru-RU') + ' ' + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}
function formatSize(b) {
  if (b < 1024) return b + ' Б';
  if (b < 1024 * 1024) return Math.round(b / 1024) + ' КБ';
  return (b / 1024 / 1024).toFixed(1) + ' МБ';
}

async function loadList() {
  loading.value = true;
  try {
    const params = new URLSearchParams();
    if (filterStatus.value) params.set('status', filterStatus.value);
    params.set('limit', '200');
    const res = await fetch('/api/email-imports?' + params.toString(), {
      headers: { 'X-Session-Token': localStorage.getItem('bk_session_token') || '' }
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data?.error || 'Не удалось загрузить');
    items.value = data.items || [];
    counts.value = data.counts || {};
  } catch (e) {
    toast.error('Ошибка загрузки', e.message || '');
  } finally {
    loading.value = false;
  }
}

watch(filterStatus, () => loadList());

async function dismiss(row) {
  if (!(await appConfirm(`Отклонить письмо «${row.subject || row.from_email}»?`, { okText: 'Отклонить', danger: true }))) return;
  try {
    const res = await fetch(`/api/email-imports/${row.id}/dismiss`, {
      method: 'POST',
      headers: { 'X-Session-Token': localStorage.getItem('bk_session_token') || '', 'Content-Type': 'application/json' },
      body: JSON.stringify({})
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data?.error || 'Ошибка');
    toast.success('Отклонено', '');
    await loadList();
  } catch (e) { toast.error('Ошибка', e.message || ''); }
}

async function downloadFile(row) {
  try {
    const res = await fetch(`/api/email-imports/${row.id}/file-token`, {
      method: 'POST',
      headers: { 'X-Session-Token': localStorage.getItem('bk_session_token') || '', 'Content-Type': 'application/json' },
      body: '{}'
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data?.error || 'Ошибка');
    window.location.href = data.url;
  } catch (e) { toast.error('Ошибка', e.message || ''); }
}

function openInImport(row) {
  router.push({ name: 'import', query: { ei: row.id } });
}

// senders
async function loadSenders() {
  if (!isAdmin.value) return;
  sendersLoading.value = true;
  try {
    const res = await fetch('/api/email-imports/senders', {
      headers: { 'X-Session-Token': localStorage.getItem('bk_session_token') || '' }
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data?.error || 'Ошибка');
    senders.value = data.items || [];
  } catch (e) {
    toast.error('Ошибка загрузки', e.message || '');
  } finally { sendersLoading.value = false; }
}

async function addSender() {
  try {
    const payload = {
      email: newSender.value.email.trim().toLowerCase(),
      type: newSender.value.type,
      legal_entity: newSender.value.legal_entity || null,
      note: newSender.value.note || null,
      is_active: 1,
    };
    if (!payload.email) return;
    const res = await fetch('/api/email-imports/senders', {
      method: 'POST',
      headers: { 'X-Session-Token': localStorage.getItem('bk_session_token') || '', 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data?.error || 'Ошибка');
    newSender.value = { email: '', type: 'restaurant_sales', legal_entity: '', note: '' };
    await loadSenders();
    toast.success('Добавлено', '');
  } catch (e) { toast.error('Ошибка', e.message || ''); }
}

async function toggleSender(s, val) {
  try {
    const res = await fetch('/api/email-imports/senders', {
      method: 'POST',
      headers: { 'X-Session-Token': localStorage.getItem('bk_session_token') || '', 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: s.id, email: s.email, type: s.type, legal_entity: s.legal_entity, note: s.note, is_active: val ? 1 : 0 })
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data?.error || 'Ошибка');
    await loadSenders();
  } catch (e) { toast.error('Ошибка', e.message || ''); }
}

async function deleteSender(s) {
  if (!(await appConfirm(`Удалить ${s.email} из списка?`, { okText: 'Удалить', danger: true }))) return;
  try {
    const res = await fetch(`/api/email-imports/senders/${s.id}`, {
      method: 'DELETE',
      headers: { 'X-Session-Token': localStorage.getItem('bk_session_token') || '' }
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data?.error || 'Ошибка');
    await loadSenders();
  } catch (e) { toast.error('Ошибка', e.message || ''); }
}

onMounted(() => {
  loadList();
  loadSenders();
});
</script>

<style scoped>
.aei { padding: 12px 14px; }

.aei-summary { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
.aei-summary-item {
  display: flex; flex-direction: column; align-items: center;
  padding: 8px 14px; border-radius: 6px; background: #f7f8fa; border: 1px solid #e5e7eb;
  min-width: 96px;
}
.aei-summary-item.warn { background: #fff7e6; border-color: #f5c876; }
.aei-summary-item.ok   { background: #ecfaf1; border-color: #80d0a0; }
.aei-summary-item.off  { background: #f3f4f6; border-color: #d1d5db; }
.aei-summary-item.err  { background: #fdecec; border-color: #f4a8a8; }
.aei-num { font-size: 20px; font-weight: 700; color: #1f2937; }
.aei-label { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.4px; }

.aei-section { margin-bottom: 18px; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 12px; background: #ffffff; }
.aei-section-title {
  display: flex; justify-content: space-between; align-items: center;
  font-weight: 600; color: #1f2937; font-size: 14px;
  margin-bottom: 10px; padding-bottom: 6px;
  border-bottom: 1px solid #f0f1f3;
}
.aei-section-right { display: flex; gap: 6px; align-items: center; }

.aei-empty { padding: 24px 0; text-align: center; color: #9ca3af; font-size: 13px; }

.aei-row { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; flex-wrap: wrap; }
.aei-row-sender { padding: 6px 0; }
.aei-input, .aei-select {
  padding: 6px 10px; font-size: 13px; border: 1px solid #d1d5db; border-radius: 4px; background: #fff;
}
.aei-input { flex: 1; min-width: 180px; }
.aei-select { min-width: 160px; }

.aei-btn {
  padding: 6px 12px; font-size: 13px; border: 1px solid #d1d5db; background: #fff; color: #1f2937;
  border-radius: 4px; cursor: pointer;
}
.aei-btn:hover { background: #f6f8fa; }
.aei-btn-sm { padding: 4px 8px; font-size: 12px; }
.aei-btn-primary { background: #E87A1E; color: #fff; border-color: #E87A1E; }
.aei-btn-primary:hover { background: #d36a14; }
.aei-btn-warn { color: #b14400; border-color: #e7c4ac; background: #fff7ef; }
.aei-btn-warn:hover { background: #fdebd9; }
.aei-btn:disabled { opacity: 0.5; cursor: not-allowed; }

.aei-table-wrap { overflow-x: auto; }
.aei-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.aei-table th, .aei-table td { padding: 5px 8px; border-bottom: 1px solid #f0f1f3; vertical-align: middle; text-align: left; }
.aei-table thead th { background: #f7f8fa; color: #4b5563; font-weight: 600; font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.4px; }
.aei-table tr:hover td { background: #fafbfc; }
.aei-table .c-actions { white-space: nowrap; display: flex; gap: 4px; flex-wrap: nowrap; }
.aei-table .c-date { white-space: nowrap; color: #6b7280; }
.aei-table .c-status { white-space: nowrap; }
.aei-table .c-type, .aei-table .c-le { white-space: nowrap; color: #4b5563; }

.aei-from { display: flex; flex-direction: column; }
.aei-from-name { color: #111827; font-weight: 600; font-size: 12.5px; }
.aei-from-email { color: #6b7280; font-size: 11.5px; }
.aei-subj { color: #111827; line-height: 1.3; }
.aei-file { display: inline-flex; gap: 4px; align-items: center; color: #4b5563; font-size: 11.5px; margin-top: 3px; }
.aei-size { color: #9ca3af; }
.aei-notes { color: #9ca3af; font-size: 11px; margin-top: 2px; }

.aei-badge {
  display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px;
  text-transform: uppercase; letter-spacing: 0.3px; font-weight: 600;
}
.aei-badge.st-pending  { background: #fff3d1; color: #92560f; }
.aei-badge.st-applied  { background: #dff7ea; color: #1d6b3e; }
.aei-badge.st-dismissed{ background: #eef0f3; color: #6b7280; }
.aei-badge.st-rejected { background: #fbe1e1; color: #9c2828; }
.aei-badge.st-error    { background: #fbe1e1; color: #9c2828; }

.aei-switch { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
</style>
