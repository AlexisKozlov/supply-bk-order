<template>
  <div class="ub">
    <div class="ub-header">
      <h1>Штрихкоды</h1>
      <div class="ub-header-actions">
        <router-link :to="{ name: 'restaurant-orders' }" class="ub-btn ub-btn-outline">Заказы ресторанов</router-link>
      </div>
    </div>

    <!-- Вкладки -->
    <div class="ub-tabs">
      <button :class="['ub-tab', { active: tab === 'unknown' }]" @click="tab = 'unknown'">
        Ненайденные
        <span v-if="unknownNewCount > 0" class="ub-tab-badge">{{ unknownNewCount }}</span>
      </button>
      <button :class="['ub-tab', { active: tab === 'all' }]" @click="onTabAll">
        Все штрихкоды
      </button>
    </div>

    <!-- ════════════ Вкладка «Ненайденные» ════════════ -->
    <div v-if="tab === 'unknown'">
    <p class="ub-hint">
      Сюда попадают штрихкоды, которые рестораны сканируют в приложении,
      но товар по ним не находится в базе. Разберите их: привяжите к существующему товару,
      заведите новый или пометьте как игнор.
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
                <button v-if="row.status === 'new'" class="ub-act-btn ub-act-bind" title="Привязать к товару" @click="openBindModal(row.gtin)">🔗</button>
                <button v-if="row.status !== 'resolved'" class="ub-act-btn ub-act-resolve" title="Разобрано" @click="setStatus(row, 'resolved')">✓</button>
                <button v-if="row.status !== 'ignored'" class="ub-act-btn ub-act-ignore" title="Игнор" @click="setStatus(row, 'ignored')">✕</button>
                <button v-if="row.status !== 'new'" class="ub-act-btn ub-act-back" title="Вернуть в новые" @click="setStatus(row, 'new')">↺</button>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
    </div><!-- /tab=unknown -->

    <!-- ════════════ Вкладка «Все штрихкоды» ════════════ -->
    <div v-if="tab === 'all'">
      <p class="ub-hint">
        Все штрихкоды, привязанные к товарам. Можно добавлять и удалять любое количество штрихкодов
        на один товар (коробки, штуки, промежуточные упаковки). «Основной» штрихкод синхронизируется
        с полем GTIN в карточке товара.
      </p>

      <div class="ub-filters">
        <div class="ub-field">
          <label>Тип</label>
          <select v-model="allFilterType" class="ub-input" @change="loadAll">
            <option value="">Все</option>
            <option value="box">Коробка</option>
            <option value="piece">Штука</option>
            <option value="pack">Промежуточная упаковка</option>
            <option value="other">Другое</option>
            <option value="unknown">Не указан</option>
          </select>
        </div>
        <div class="ub-field">
          <label>Источник</label>
          <select v-model="allFilterSource" class="ub-input" @change="loadAll">
            <option value="">Любой</option>
            <option value="admin">Админ</option>
            <option value="restaurant">Ресторан</option>
            <option value="import">Импорт</option>
            <option value="migration">Миграция</option>
          </select>
        </div>
        <div class="ub-field ub-field-grow">
          <label>Поиск</label>
          <input type="text" v-model="allFilterSearch" class="ub-input" placeholder="Штрихкод, SKU или название товара" @keydown.enter="loadAll" />
        </div>
        <button class="ub-btn ub-btn-primary" :disabled="allLoading" @click="loadAll">
          <span v-if="allLoading" class="ub-spin"></span> Показать
        </button>
        <button class="ub-btn ub-btn-primary" @click="openBindModal(null)">+ Добавить штрихкод</button>
      </div>

      <div class="ub-table-wrap">
        <table class="ub-table">
          <thead>
            <tr>
              <th>Штрихкод</th>
              <th>Тип</th>
              <th>SKU</th>
              <th>Название товара</th>
              <th>Юрлицо</th>
              <th>Основной</th>
              <th>Источник</th>
              <th>Добавлен</th>
              <th class="ub-th-actions">Действия</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!allLoading && !allItems.length">
              <td colspan="9" class="ub-empty">Нет записей</td>
            </tr>
            <tr v-for="row in allItems" :key="row.id">
              <td><code class="ub-gtin">{{ row.barcode }}</code></td>
              <td>
                <select :value="row.barcode_type" class="ub-input ub-input-type" @change="updateBarcodeType(row, $event.target.value)">
                  <option value="box">Коробка</option>
                  <option value="piece">Штука</option>
                  <option value="pack">Упаковка</option>
                  <option value="other">Другое</option>
                  <option value="unknown">Не указан</option>
                </select>
              </td>
              <td><code class="ub-sku">{{ row.sku }}</code></td>
              <td class="ub-name-cell">{{ row.product_name || '—' }}</td>
              <td>{{ row.legal_entity || '—' }}</td>
              <td class="ub-num">
                <button class="ub-star" :class="{ active: row.is_primary }" :title="row.is_primary ? 'Основной' : 'Сделать основным'" @click="toggleBarcodePrimary(row)">
                  {{ row.is_primary ? '★' : '☆' }}
                </button>
              </td>
              <td>{{ sourceLabel(row.source) }}</td>
              <td class="ub-date">
                {{ fmtDate(row.created_at) }}
                <div v-if="row.created_by" class="ub-rep-comment">{{ row.created_by }}</div>
              </td>
              <td class="ub-actions">
                <button class="ub-act-btn ub-act-ignore" title="Удалить" @click="deleteBarcodeRow(row)">✕</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div><!-- /tab=all -->

    <!-- Модалка с увеличенным фото -->
    <div v-if="photoModal" class="ub-modal" @click="photoModal = null">
      <img :src="photoUrl(photoModal)" alt="фото товара" class="ub-modal-img" @click.stop />
      <button class="ub-modal-close" @click="photoModal = null">✕</button>
    </div>

    <!-- Модалка добавления / привязки штрихкода -->
    <div v-if="bindModal" class="ub-modal" @click="closeBindModal">
      <div class="ub-bind-modal" @click.stop>
        <h3 class="ub-bind-modal-title">
          {{ bindModal.barcode ? 'Привязать штрихкод к товару' : 'Добавить штрихкод' }}
        </h3>
        <p v-if="bindModal.barcode" class="ub-bind-modal-sub">Штрихкод: <code>{{ bindModal.barcode }}</code></p>

        <div class="ub-bind-row">
          <label class="ub-bind-label">Штрихкод</label>
          <input
            type="text"
            v-model="bindModal.barcode"
            class="ub-input"
            placeholder="Например: 4601234567890"
            maxlength="64"
            :disabled="!!bindModal.barcodeLocked"
          />
        </div>

        <div class="ub-bind-row">
          <label class="ub-bind-label">Тип</label>
          <select v-model="bindModal.type" class="ub-input">
            <option value="piece">Штука</option>
            <option value="box">Коробка</option>
            <option value="pack">Промежуточная упаковка</option>
            <option value="other">Другое</option>
            <option value="unknown">Не указан</option>
          </select>
        </div>

        <div class="ub-bind-row">
          <label class="ub-bind-label">Товар (поиск по SKU / названию)</label>
          <input
            type="text"
            v-model="bindModal.search"
            class="ub-input"
            placeholder="Введите минимум 2 символа"
            @input="onBindModalSearchInput"
          />
        </div>

        <div v-if="bindModal.searching" class="ub-bind-loading">Ищу…</div>
        <div v-else-if="bindModal.results.length" class="ub-bind-results">
          <button
            v-for="p in bindModal.results"
            :key="p.sku + '|' + p.legal_entity"
            :class="['ub-bind-item', { active: bindModal.selectedSku === p.sku && bindModal.selectedLE === p.legal_entity }]"
            @click="bindModal.selectedSku = p.sku; bindModal.selectedName = p.name; bindModal.selectedLE = p.legal_entity"
          >
            <span class="ub-bind-item-name">{{ p.name }}</span>
            <span class="ub-bind-item-meta">{{ p.sku }} · {{ p.legal_entity }}<span v-if="p.gtin"> · текущий GTIN: {{ p.gtin }}</span></span>
          </button>
        </div>
        <div v-else-if="bindModal.search && bindModal.search.length >= 2 && !bindModal.searching" class="ub-bind-empty">Ничего не найдено</div>

        <label class="ub-bind-checkbox">
          <input type="checkbox" v-model="bindModal.isPrimary" />
          Сделать основным (синхронизировать с GTIN в карточке товара)
        </label>

        <div v-if="bindModal.error" class="ub-bind-error">{{ bindModal.error }}</div>

        <div class="ub-bind-actions">
          <button class="ub-btn ub-btn-outline" @click="closeBindModal" :disabled="bindModal.saving">Отмена</button>
          <button class="ub-btn ub-btn-primary" :disabled="!bindModal.selectedSku || !bindModal.barcode || bindModal.saving" @click="submitBindModal">
            {{ bindModal.saving ? 'Сохраняю…' : 'Сохранить' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useToastStore } from '@/stores/toastStore.js';
import { formatRestaurantNumber } from '@/lib/legalEntities.js';

const toast = useToastStore();

const tab = ref('unknown'); // 'unknown' | 'all'

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

// ─── Вкладка «Все штрихкоды» ───
const allItems = ref([]);
const allLoading = ref(false);
const allFilterType = ref('');
const allFilterSource = ref('');
const allFilterSearch = ref('');

// ─── Модалка добавления/привязки штрихкода ───
const bindModal = ref(null);
let bindModalSearchTimer = null;

const unknownNewCount = computed(() => {
  if (filterStatus.value !== 'new') return 0;
  return items.value.filter(i => i.status === 'new').length;
});

function onTabAll() {
  tab.value = 'all';
  if (allItems.value.length === 0) loadAll();
}

async function loadAll() {
  allLoading.value = true;
  try {
    const params = new URLSearchParams();
    if (allFilterType.value) params.set('type', allFilterType.value);
    if (allFilterSource.value) params.set('source', allFilterSource.value);
    if (allFilterSearch.value.trim()) params.set('q', allFilterSearch.value.trim());
    params.set('limit', '300');
    const res = await fetch(`/api/ro/admin/barcodes?${params}`, { headers: apiHeaders() });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка загрузки');
    allItems.value = data.barcodes || [];
  } catch (e) {
    console.error(e);
    toast.error('Не удалось загрузить штрихкоды', e.message || '');
  } finally {
    allLoading.value = false;
  }
}

function openBindModal(barcode) {
  bindModal.value = {
    barcode: barcode || '',
    barcodeLocked: !!barcode,
    type: 'piece',
    search: '',
    results: [],
    searching: false,
    selectedSku: '',
    selectedName: '',
    selectedLE: '',
    isPrimary: false,
    saving: false,
    error: '',
  };
}

function closeBindModal() {
  if (bindModal.value?.saving) return;
  bindModal.value = null;
  if (bindModalSearchTimer) { clearTimeout(bindModalSearchTimer); bindModalSearchTimer = null; }
}

function onBindModalSearchInput() {
  if (!bindModal.value) return;
  bindModal.value.selectedSku = '';
  bindModal.value.selectedName = '';
  bindModal.value.selectedLE = '';
  if (bindModalSearchTimer) clearTimeout(bindModalSearchTimer);
  const q = bindModal.value.search.trim();
  if (q.length < 2) {
    bindModal.value.results = [];
    bindModal.value.searching = false;
    return;
  }
  bindModal.value.searching = true;
  bindModalSearchTimer = setTimeout(async () => {
    try {
      // Поиск идёт по products через тот же эндпоинт, что использует admin для шаблонов,
      // но он требует legal_entity. У нас тут админ — берём через продукты-таблицу.
      const params = new URLSearchParams({ q });
      const res = await fetch(`/api/ro/admin/products-search?${params}`, { headers: apiHeaders() });
      // Если такого эндпоинта нет — fallback на products
      if (res.status === 404) {
        bindModal.value.results = [];
        return;
      }
      const data = await res.json();
      bindModal.value.results = data.products || [];
    } catch (e) {
      bindModal.value.results = [];
    } finally {
      if (bindModal.value) bindModal.value.searching = false;
    }
  }, 250);
}

async function submitBindModal() {
  if (!bindModal.value || !bindModal.value.selectedSku || !bindModal.value.barcode) return;
  bindModal.value.saving = true;
  bindModal.value.error = '';
  try {
    const res = await fetch('/api/ro/admin/barcodes', {
      method: 'POST',
      headers: apiHeaders(),
      body: JSON.stringify({
        sku: bindModal.value.selectedSku,
        barcode: bindModal.value.barcode.trim(),
        barcode_type: bindModal.value.type,
        is_primary: !!bindModal.value.isPrimary,
      }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка');

    // Если этот штрихкод был в «Ненайденных» — отметим как resolved.
    if (bindModal.value.barcodeLocked && tab.value === 'unknown') {
      const row = items.value.find(i => i.gtin === bindModal.value.barcode.trim() && i.status === 'new');
      if (row) await setStatus(row, 'resolved');
    }

    toast.success('Штрихкод сохранён');
    closeBindModal();
    if (tab.value === 'all') loadAll();
  } catch (e) {
    bindModal.value.error = e.message || 'неизвестная ошибка';
  } finally {
    if (bindModal.value) bindModal.value.saving = false;
  }
}

async function updateBarcodeType(row, newType) {
  try {
    const res = await fetch(`/api/ro/admin/barcodes/${row.id}`, {
      method: 'PATCH',
      headers: apiHeaders(),
      body: JSON.stringify({ barcode_type: newType }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка');
    row.barcode_type = newType;
  } catch (e) {
    toast.error('Не удалось изменить тип', e.message || '');
  }
}

async function toggleBarcodePrimary(row) {
  const newPrimary = row.is_primary ? 0 : 1;
  try {
    const res = await fetch(`/api/ro/admin/barcodes/${row.id}`, {
      method: 'PATCH',
      headers: apiHeaders(),
      body: JSON.stringify({ is_primary: newPrimary }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка');
    // На клиенте: если сделали основным — снять флаг у других этого же SKU.
    if (newPrimary === 1) {
      allItems.value.forEach(r => { if (r.sku === row.sku) r.is_primary = (r.id === row.id ? 1 : 0); });
    } else {
      row.is_primary = 0;
    }
  } catch (e) {
    toast.error('Не удалось изменить', e.message || '');
  }
}

async function deleteBarcodeRow(row) {
  if (!confirm(`Удалить штрихкод ${row.barcode} (товар ${row.sku})?`)) return;
  try {
    const res = await fetch(`/api/ro/admin/barcodes/${row.id}`, {
      method: 'DELETE',
      headers: apiHeaders(),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка');
    allItems.value = allItems.value.filter(r => r.id !== row.id);
    toast.success('Штрихкод удалён');
  } catch (e) {
    toast.error('Не удалось удалить', e.message || '');
  }
}

function sourceLabel(s) {
  return ({ admin: 'Админ', restaurant: 'Ресторан', import: 'Импорт', migration: 'Миграция' })[s] || s;
}

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
.ub-input-type { min-width: 110px; font-size: 12px; padding: 4px 6px; }

/* Вкладки */
.ub-tabs { display: flex; gap: 4px; border-bottom: 1px solid #e5e7eb; margin-bottom: 16px; }
.ub-tab {
  position: relative; background: transparent; border: none; padding: 10px 16px;
  font-size: 14px; font-weight: 500; color: #6b7280; cursor: pointer;
  border-bottom: 2px solid transparent; margin-bottom: -1px;
  display: inline-flex; align-items: center; gap: 8px;
}
.ub-tab:hover { color: #111827; }
.ub-tab.active { color: #E76F51; border-bottom-color: #E76F51; }
.ub-tab-badge {
  background: #E76F51; color: #fff; font-size: 11px; font-weight: 700;
  padding: 1px 7px; border-radius: 10px; min-width: 20px; text-align: center;
}

/* Звезда «основной» */
.ub-star {
  background: transparent; border: none; cursor: pointer;
  font-size: 18px; color: #d1d5db; padding: 2px 4px;
}
.ub-star:hover { color: #fbbf24; }
.ub-star.active { color: #f59e0b; }
.ub-sku { font-family: ui-monospace, monospace; font-size: 12px; color: #4b5563; }

/* Модалка привязки */
.ub-bind-modal {
  background: #fff; border-radius: 12px; padding: 24px;
  width: 560px; max-width: 92vw; max-height: 88vh; overflow-y: auto;
  box-shadow: 0 12px 32px rgba(0,0,0,0.2);
}
.ub-bind-modal-title { margin: 0 0 6px; font-size: 18px; font-weight: 700; color: #2b1a0e; }
.ub-bind-modal-sub { margin: 0 0 16px; color: #6b7280; font-size: 13px; }
.ub-bind-row { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
.ub-bind-label { font-size: 12px; color: #4b5563; font-weight: 600; }
.ub-bind-results {
  display: flex; flex-direction: column; gap: 4px; max-height: 240px; overflow-y: auto;
  border: 1px solid #e5e7eb; border-radius: 6px; padding: 4px; background: #fafafa;
  margin-bottom: 12px;
}
.ub-bind-item {
  display: flex; flex-direction: column; gap: 2px;
  text-align: left; padding: 8px 10px; background: #fff;
  border: 1px solid transparent; border-radius: 4px;
  cursor: pointer; font: inherit; color: inherit;
}
.ub-bind-item:hover { background: #f9fafb; }
.ub-bind-item.active { border-color: #E76F51; background: #fff3ec; }
.ub-bind-item-name { font-size: 13px; font-weight: 600; color: #111827; }
.ub-bind-item-meta { font-size: 11px; color: #6b7280; }
.ub-bind-empty, .ub-bind-loading {
  text-align: center; padding: 12px; color: #6b7280; font-size: 13px;
  background: #fafafa; border-radius: 6px; margin-bottom: 12px;
}
.ub-bind-checkbox {
  display: flex; align-items: center; gap: 8px; font-size: 13px;
  color: #4b5563; margin: 8px 0 12px; cursor: pointer;
}
.ub-bind-error { color: #c0392b; font-size: 13px; margin-bottom: 12px; }
.ub-bind-actions { display: flex; justify-content: flex-end; gap: 8px; }

/* Иконка «привязать» в действиях */
.ub-act-bind {
  background: #fff3ec; color: #c66f1f;
}
.ub-act-bind:hover { background: #ffe5d3; }

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
