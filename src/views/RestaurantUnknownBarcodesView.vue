<template>
  <div class="ub">
    <div class="ub-header">
      <h1>Неизвестные штрихкоды</h1>
      <div class="ub-header-actions">
        <router-link :to="{ name: 'restaurant-orders' }" class="ub-btn ub-btn-outline">Заказы ресторанов</router-link>
      </div>
    </div>

    <p class="ub-hint">
      Сюда попадают штрихкоды, которые рестораны сканируют в приложении,
      но товар по ним не находится в базе. Разберите их: заведите товар или пометьте как игнор.
    </p>

    <!-- Блок подписчиков -->
    <section class="ub-subs">
      <div class="ub-subs-head">
        <h2>Получатели уведомлений в Telegram</h2>
        <button class="ub-btn ub-btn-primary" :disabled="subsSaving" @click="saveSubscribers">
          {{ subsSaving ? 'Сохраняю…' : 'Сохранить' }}
        </button>
      </div>
      <p v-if="!subsCandidates.length" class="ub-subs-empty">
        Нет кандидатов. Telegram-чат должен быть привязан у пользователя с ролью «admin».
      </p>
      <p v-else-if="!subscribers.length" class="ub-subs-warn">
        Никто не выбран — уведомления никому отправляться не будут.
      </p>
      <div v-if="subsCandidates.length" class="ub-subs-list">
        <label v-for="u in subsCandidates" :key="u.name" class="ub-subs-item">
          <input type="checkbox" :value="u.name" v-model="subscribers" />
          <span class="ub-subs-name">{{ u.name }}</span>
          <span v-if="u.display_role" class="ub-subs-role">{{ u.display_role }}</span>
        </label>
      </div>
    </section>

    <!-- Фильтры -->
    <div class="ub-filters">
      <div class="ub-field">
        <label>Статус</label>
        <select v-model="filterStatus" class="ub-input" @change="load">
          <option value="new">Новые</option>
          <option value="resolved">Разобранные</option>
          <option value="ignored">Игнор</option>
          <option value="all">Все</option>
        </select>
      </div>
      <div class="ub-field">
        <label>Группа</label>
        <select v-model="filterGroup" class="ub-input" @change="load">
          <option value="">Все</option>
          <option value="BK_VM">BK / Воглия</option>
          <option value="PS">Пицца Стар</option>
        </select>
      </div>
      <div class="ub-field ub-field-grow">
        <label>Поиск</label>
        <input type="text" v-model="filterSearch" class="ub-input" placeholder="Штрихкод или номер ресторана" @keydown.enter="load" />
      </div>
      <button class="ub-btn ub-btn-primary" :disabled="loading" @click="load">
        <span v-if="loading" class="ub-spin"></span> Показать
      </button>
    </div>

    <!-- Таблица -->
    <div class="ub-table-wrap">
      <table class="ub-table">
        <thead>
          <tr>
            <th>Фото</th>
            <th>Штрихкод</th>
            <th>Название от ресторана</th>
            <th>Ресторан</th>
            <th>Группа</th>
            <th>Повторов</th>
            <th>Последний скан</th>
            <th>Статус</th>
            <th>Заметка (админа)</th>
            <th class="ub-th-actions">Действия</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!loading && !items.length">
            <td colspan="10" class="ub-empty">Нет записей</td>
          </tr>
          <template v-for="row in items" :key="row.id">
            <tr :class="'ub-row-' + row.status">
              <td class="ub-photo-cell">
                <button
                  v-if="row.has_photo && photoUrl(row.id)"
                  class="ub-photo-thumb"
                  @click="openPhoto(row.id)"
                  title="Открыть фото"
                >
                  <img :src="photoUrl(row.id)" alt="фото" />
                </button>
                <span v-else-if="row.has_photo" class="ub-photo-loading">…</span>
                <span v-else class="ub-photo-none">—</span>
              </td>
              <td><code class="ub-gtin">{{ row.gtin }}</code></td>
              <td class="ub-name-cell">
                <div v-if="row.reporter_name" class="ub-rep-name">{{ row.reporter_name }}</div>
                <div v-if="row.reporter_comment" class="ub-rep-comment" :title="row.reporter_comment">
                  💬 {{ row.reporter_comment }}
                </div>
                <span v-if="!row.reporter_name && !row.reporter_comment" class="ub-empty-inline">—</span>
              </td>
              <td>{{ formatRestaurantNumber(row.restaurant_number, row.legal_entity_group) }}</td>
              <td>{{ row.legal_entity_group }}</td>
              <td class="ub-num">{{ row.seen_count }}</td>
              <td class="ub-date">{{ fmtDate(row.last_seen) }}</td>
              <td>
                <span class="ub-status" :class="'ub-status-' + row.status">{{ statusLabel(row.status) }}</span>
              </td>
              <td>
                <input
                  type="text"
                  class="ub-input ub-input-notes"
                  :value="row.notes || ''"
                  maxlength="500"
                  placeholder="—"
                  @change="saveNotes(row, $event.target.value)"
                />
              </td>
              <td class="ub-actions">
                <button v-if="row.status !== 'resolved'" class="ub-act-btn ub-act-resolve" title="Разобрано" @click="setStatus(row, 'resolved')">✓</button>
                <button v-if="row.status !== 'ignored'" class="ub-act-btn ub-act-ignore" title="Игнор" @click="setStatus(row, 'ignored')">✕</button>
                <button v-if="row.status !== 'new'" class="ub-act-btn ub-act-back" title="Вернуть в новые" @click="setStatus(row, 'new')">↺</button>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <!-- Модалка с увеличенным фото -->
    <div v-if="photoModal" class="ub-modal" @click="photoModal = null">
      <img :src="photoUrl(photoModal)" alt="фото товара" class="ub-modal-img" @click.stop />
      <button class="ub-modal-close" @click="photoModal = null">✕</button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { useToastStore } from '@/stores/toastStore.js';
import { formatRestaurantNumber } from '@/lib/legalEntities.js';

const toast = useToastStore();

const items = ref([]);
const loading = ref(false);

const filterStatus = ref('new');
const filterGroup = ref('');
const filterSearch = ref('');

const subscribers = ref([]);
const subsCandidates = ref([]);
const subsSaving = ref(false);

const photoModal = ref(null); // id строки, чьё фото открыто
const photoUrls = ref({}); // { [id]: ObjectURL }

async function loadPhoto(id) {
  if (photoUrls.value[id]) return;
  try {
    const res = await fetch(`/api/ro/admin/scan-unknown/${id}/photo`, { headers: apiHeaders() });
    if (!res.ok) return;
    const blob = await res.blob();
    photoUrls.value = { ...photoUrls.value, [id]: URL.createObjectURL(blob) };
  } catch (e) {
    console.error('photo load failed', e);
  }
}

function photoUrl(id) { return photoUrls.value[id] || ''; }

function openPhoto(id) { photoModal.value = id; }

function revokeAllPhotos() {
  for (const url of Object.values(photoUrls.value)) {
    try { URL.revokeObjectURL(url); } catch {}
  }
  photoUrls.value = {};
}

function apiHeaders() {
  const token = localStorage.getItem('bk_session_token') || '';
  return { 'X-Session-Token': token, 'Content-Type': 'application/json' };
}

async function load() {
  loading.value = true;
  try {
    const params = new URLSearchParams();
    params.set('status', filterStatus.value);
    if (filterGroup.value) params.set('group', filterGroup.value);
    if (filterSearch.value.trim()) params.set('search', filterSearch.value.trim());

    const res = await fetch(`/api/ro/admin/scan-unknown?${params}`, { headers: apiHeaders() });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка загрузки');
    items.value = data.items || [];
    // Подгружаем миниатюры для строк с фото
    revokeAllPhotos();
    for (const row of items.value) {
      if (row.has_photo) loadPhoto(row.id);
    }
  } catch (e) {
    console.error(e);
    toast.error('Не удалось загрузить список', e.message || '');
  } finally {
    loading.value = false;
  }
}

async function loadSubscribers() {
  try {
    const res = await fetch('/api/ro/admin/scan-unknown-subscribers', { headers: apiHeaders() });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка загрузки');
    subscribers.value = data.subscribers || [];
    subsCandidates.value = data.candidates || [];
  } catch (e) {
    console.error(e);
    toast.error('Не удалось загрузить подписчиков', e.message || '');
  }
}

async function saveSubscribers() {
  subsSaving.value = true;
  try {
    const res = await fetch('/api/ro/admin/scan-unknown-subscribers', {
      method: 'POST',
      headers: apiHeaders(),
      body: JSON.stringify({ subscribers: subscribers.value }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка сохранения');
    subscribers.value = data.subscribers || [];
    toast.success('Настройки уведомлений сохранены');
  } catch (e) {
    console.error(e);
    toast.error('Не удалось сохранить', e.message || '');
  } finally {
    subsSaving.value = false;
  }
}

async function setStatus(row, newStatus) {
  try {
    const res = await fetch(`/api/ro/admin/scan-unknown/${row.id}/status`, {
      method: 'POST',
      headers: apiHeaders(),
      body: JSON.stringify({ status: newStatus }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка');
    row.status = newStatus;
    if (data.notified) {
      toast.success('Статус обновлён', 'Ресторану отправлено уведомление в Telegram');
    }
    // Если текущий фильтр не покрывает новый статус — убираем из списка
    if (filterStatus.value !== 'all' && filterStatus.value !== newStatus) {
      items.value = items.value.filter(i => i.id !== row.id);
    }
  } catch (e) {
    console.error(e);
    toast.error('Не удалось изменить статус', e.message || '');
  }
}

async function saveNotes(row, value) {
  try {
    const res = await fetch(`/api/ro/admin/scan-unknown/${row.id}/notes`, {
      method: 'POST',
      headers: apiHeaders(),
      body: JSON.stringify({ notes: value }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка');
    row.notes = value;
  } catch (e) {
    console.error(e);
    toast.error('Не удалось сохранить заметку', e.message || '');
  }
}

function statusLabel(s) {
  if (s === 'new') return 'Новый';
  if (s === 'resolved') return 'Разобран';
  if (s === 'ignored') return 'Игнор';
  return s;
}

function fmtDate(s) {
  if (!s) return '';
  const d = new Date(s.replace(' ', 'T'));
  if (isNaN(d)) return s;
  const p = (n) => String(n).padStart(2, '0');
  return `${p(d.getDate())}.${p(d.getMonth() + 1)}.${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

onMounted(() => {
  load();
  loadSubscribers();
});

onBeforeUnmount(() => {
  revokeAllPhotos();
});
</script>

<style scoped>
.ub { padding: 16px 24px; max-width: 1400px; margin: 0 auto; }
.ub-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.ub-header h1 { font-size: 22px; margin: 0; }
.ub-header-actions { display: flex; gap: 8px; }
.ub-hint { color: #6b7280; font-size: 13px; margin: 0 0 16px; }

.ub-subs { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 16px; margin-bottom: 16px; }
.ub-subs-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.ub-subs-head h2 { font-size: 15px; margin: 0; font-weight: 600; }
.ub-subs-empty { color: #6b7280; font-size: 13px; margin: 0; }
.ub-subs-warn { color: #b45309; font-size: 13px; margin: 0 0 8px; }
.ub-subs-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 6px 16px; }
.ub-subs-item { display: flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer; }
.ub-subs-item:hover { color: #111827; }
.ub-subs-name { font-weight: 500; }
.ub-subs-role { color: #6b7280; font-size: 12px; }

.ub-filters { display: flex; gap: 12px; align-items: flex-end; margin-bottom: 16px; flex-wrap: wrap; }
.ub-field { display: flex; flex-direction: column; gap: 4px; }
.ub-field-grow { flex: 1; min-width: 220px; }
.ub-field label { font-size: 12px; color: #6b7280; }
.ub-input { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; min-width: 160px; }
.ub-input-notes { min-width: 140px; width: 100%; font-size: 12px; }

.ub-btn { padding: 7px 14px; border-radius: 6px; font-size: 14px; cursor: pointer; border: 1px solid transparent; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
.ub-btn-primary { background: #2563eb; color: #fff; border-color: #2563eb; }
.ub-btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.ub-btn-outline { background: #fff; color: #111827; border-color: #d1d5db; }
.ub-spin { width: 14px; height: 14px; border: 2px solid #fff; border-top-color: transparent; border-radius: 50%; animation: ub-spin 0.8s linear infinite; }
@keyframes ub-spin { to { transform: rotate(360deg); } }

.ub-table-wrap { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: auto; }
.ub-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.ub-table thead th { background: #f9fafb; text-align: left; padding: 10px 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb; position: sticky; top: 0; }
.ub-table tbody td { padding: 8px 12px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
.ub-table tbody tr:hover { background: #f9fafb; }
.ub-gtin { font-family: monospace; font-size: 13px; background: #f3f4f6; padding: 2px 6px; border-radius: 4px; }
.ub-num { text-align: center; }
.ub-date { color: #6b7280; white-space: nowrap; }
.ub-empty { text-align: center; color: #9ca3af; padding: 24px !important; }
.ub-th-actions { width: 130px; }
.ub-actions { display: flex; gap: 4px; }

.ub-status { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
.ub-status-new { background: #dbeafe; color: #1d4ed8; }
.ub-status-resolved { background: #d1fae5; color: #065f46; }
.ub-status-ignored { background: #f3f4f6; color: #6b7280; }

.ub-row-resolved { opacity: 0.7; }
.ub-row-ignored { opacity: 0.55; }

.ub-act-btn { width: 28px; height: 28px; border-radius: 6px; border: 1px solid #d1d5db; background: #fff; cursor: pointer; font-size: 13px; display: inline-flex; align-items: center; justify-content: center; }
.ub-act-btn:hover { background: #f3f4f6; }
.ub-act-resolve:hover { background: #d1fae5; border-color: #10b981; color: #065f46; }
.ub-act-ignore:hover { background: #fee2e2; border-color: #ef4444; color: #b91c1c; }
.ub-act-back:hover { background: #dbeafe; border-color: #3b82f6; color: #1d4ed8; }

.ub-photo-cell { width: 72px; }
.ub-photo-thumb {
  width: 56px; height: 56px; border: 1px solid #e5e7eb; border-radius: 6px;
  padding: 0; overflow: hidden; cursor: pointer; background: #f9fafb;
}
.ub-photo-thumb:hover { border-color: #2563eb; }
.ub-photo-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.ub-photo-loading { color: #9ca3af; font-size: 13px; }
.ub-photo-none { color: #d1d5db; }

.ub-name-cell { max-width: 260px; }
.ub-rep-name { font-weight: 600; color: #111827; line-height: 1.3; }
.ub-rep-comment {
  font-size: 11px; color: #6b7280; margin-top: 3px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 260px;
}
.ub-empty-inline { color: #d1d5db; }

.ub-modal {
  position: fixed; inset: 0; background: rgba(0,0,0,0.85);
  display: flex; align-items: center; justify-content: center;
  z-index: 1000; padding: 20px; cursor: zoom-out;
}
.ub-modal-img { max-width: 96%; max-height: 96%; border-radius: 8px; cursor: default; box-shadow: 0 4px 20px rgba(0,0,0,0.5); }
.ub-modal-close {
  position: absolute; top: 18px; right: 22px; width: 40px; height: 40px;
  border: none; border-radius: 50%; background: rgba(255,255,255,0.15); color: #fff;
  font-size: 18px; cursor: pointer;
}
.ub-modal-close:hover { background: rgba(255,255,255,0.3); }
</style>
