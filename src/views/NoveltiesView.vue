<template>
  <div class="nov">
    <div class="nov-top">
      <div>
        <h1 class="page-title"><span class="nov-newtag">NEW</span> Новинки</h1>
        <p class="nov-sub">
          Новые товары справочника за последние {{ noveltyDays }} дней. Их видят рестораны в кабинете и телеграм-боте.
          Опишите новинку, добавьте фото и дату старта — по желанию. Ложную новинку (реимпорт старой карточки) можно скрыть.
        </p>
      </div>
      <div class="nov-actions">
        <div class="nov-search-wrap">
          <span class="nov-search-ico"><BkIcon name="search" size="sm" /></span>
          <input v-model="search" class="nov-input nov-search-input" placeholder="Поиск: название или артикул…" />
          <button v-if="search" class="nov-clear" @click="search = ''" title="Очистить">×</button>
        </div>
        <label class="nov-toggle">
          <input type="checkbox" v-model="showHidden" /> показывать скрытые
        </label>
        <button v-if="canEdit" class="nov-btn" @click="openAdd">＋ Добавить товар</button>
      </div>
    </div>

    <!-- Модалка добавления старого/любого товара -->
    <div v-if="showAdd" class="nov-modal-back" @click.self="closeAdd">
      <div class="nov-modal">
        <div class="nov-modal-head">
          <h3>Добавить товар в новинки</h3>
          <button class="nov-modal-x" @click="closeAdd">×</button>
        </div>
        <p class="nov-hint" style="margin:0 0 10px">
          Найдите любой товар справочника — даже созданный давно. После добавления он покажется ресторанам
          {{ noveltyDays }} дней (срок можно изменить).
        </p>
        <div class="nov-search-wrap" style="width:100%">
          <span class="nov-search-ico"><BkIcon name="search" size="sm" /></span>
          <input v-model="addSearch" class="nov-input nov-search-input" style="width:100%;box-sizing:border-box"
                 placeholder="Название, артикул или код…" autofocus />
        </div>
        <div class="nov-add-results">
          <div v-if="addSearching" class="nov-add-state">Поиск…</div>
          <div v-else-if="addSearch.trim().length < 2" class="nov-add-state">Введите минимум 2 символа.</div>
          <div v-else-if="!addResults.length" class="nov-add-state">Ничего не найдено.</div>
          <button v-for="p in addResults" :key="p.product_id" class="nov-add-row" @click="addProduct(p)" :disabled="addingId === p.product_id">
            <span class="nov-add-name"><b>{{ (p.sku ? p.sku + ' ' : '') + p.name }}</b></span>
            <span class="nov-add-plus">{{ addingId === p.product_id ? '…' : '＋' }}</span>
          </button>
        </div>
      </div>
    </div>

    <div class="nov-stats">
      <span><b>{{ currentCount }}</b> сейчас показываются</span>
      <span v-if="hiddenCount"><b>{{ hiddenCount }}</b> скрыто</span>
      <span><b>{{ describedCount }}</b> с описанием</span>
    </div>

    <div v-if="loading" style="text-align:center;padding:50px;"><BurgerSpinner text="Загрузка…" /></div>
    <div v-else-if="!items.length" class="nov-empty">Новых товаров за последние {{ noveltyDays }} дней нет.</div>
    <div v-else-if="!filtered.length" class="nov-empty">Ничего не найдено.</div>

    <div v-else class="nov-list">
      <div v-for="it in filtered" :key="it.product_id" class="nov-card" :class="{ 'nov-card-off': !it.is_current }">
        <div class="nov-thumb" @click="canEdit && openEdit(it)">
          <img v-if="it.photo_url" :src="photoSrc(it)" alt="" />
          <span v-else class="nov-thumb-ph"><BkIcon name="burger" size="sm" /></span>
        </div>
        <div class="nov-body">
          <div class="nov-name-row">
            <span class="nov-name">{{ novTitle(it) }}</span>
            <span v-if="it.is_hidden" class="nov-badge nov-badge-hidden">скрыто</span>
            <span v-else-if="it.is_current" class="nov-badge nov-badge-live">новинка до {{ fmtDate(it.effective_end) }}</span>
            <span v-else class="nov-badge nov-badge-gone">срок вышел</span>
          </div>
          <div class="nov-meta">
            <span class="nov-appeared">появился {{ fmtDate(it.created_at) }}</span>
            <span v-if="it.sales_start_date" class="nov-start">старт продаж {{ fmtDate(it.sales_start_date) }}</span>
          </div>
          <div class="nov-desc" v-if="it.description">{{ it.description }}</div>
          <div class="nov-desc nov-desc-empty" v-else>Описание не добавлено</div>
        </div>
        <div class="nov-side" v-if="canEdit">
          <button class="nov-btn" @click="openEdit(it)">✎ Описание</button>
          <button v-if="it.is_hidden" class="nov-btn nov-btn-ghost" @click="quickToggleHidden(it, false)">Вернуть</button>
          <button v-else class="nov-btn nov-btn-ghost" @click="quickToggleHidden(it, true)">Скрыть</button>
        </div>
      </div>
    </div>

    <!-- Модалка описания -->
    <div v-if="editing" class="nov-modal-back" @click.self="closeEdit">
      <div class="nov-modal">
        <div class="nov-modal-head">
          <h3>{{ novTitle(editing) }}</h3>
          <button class="nov-modal-x" @click="closeEdit">×</button>
        </div>

        <div class="nov-photo-box">
          <div class="nov-photo-prev">
            <img v-if="editing.photo_url" :src="photoSrc(editing)" alt="" />
            <span v-else class="nov-thumb-ph nov-thumb-ph-lg"><BkIcon name="burger" size="sm" /></span>
          </div>
          <div class="nov-photo-ctrls">
            <button class="nov-btn nov-btn-ghost" @click="pickPhoto" :disabled="uploading">
              {{ uploading ? 'Загрузка…' : (editing.photo_url ? 'Заменить фото' : 'Добавить фото') }}
            </button>
            <button v-if="editing.photo_url" class="nov-btn nov-btn-del" @click="removePhoto" :disabled="uploading">Удалить фото</button>
            <input ref="photoInput" type="file" accept="image/jpeg,image/png,image/webp" style="display:none" @change="onPhotoFile" />
            <small class="nov-hint">JPG, PNG или WEBP, до 8 МБ.</small>
          </div>
        </div>

        <label class="nov-field">
          <span>Описание — что за новинка</span>
          <textarea v-model="form.description" class="nov-input nov-textarea" rows="4"
                    placeholder="Коротко: что это, зачем, когда старт продаж и т.п."></textarea>
        </label>
        <label class="nov-field">
          <span>Дата старта продаж (если известна)</span>
          <input type="date" v-model="form.sales_start_date" class="nov-input" />
        </label>
        <label class="nov-field">
          <span>Показывать ресторанам до</span>
          <input type="date" v-model="form.show_until" class="nov-input" />
          <small class="nov-hint">По умолчанию — {{ noveltyDays }} дней с даты появления. Поставьте дату, чтобы продлить или убрать раньше.</small>
        </label>
        <label class="nov-check">
          <input type="checkbox" v-model="form.is_hidden" />
          <span>Скрыть новинку (не показывать ресторанам)</span>
        </label>

        <div class="nov-modal-actions">
          <span class="nov-modal-spacer"></span>
          <button class="nov-btn nov-btn-ghost" @click="closeEdit">Отмена</button>
          <button class="nov-btn" @click="save" :disabled="saving">{{ saving ? 'Сохранение…' : 'Сохранить' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import BkIcon from '@/components/ui/BkIcon.vue';
import { db } from '@/lib/apiClient.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { getEntityGroupCode } from '@/lib/legalEntities.js';
import { getDownloadUrl } from '@/lib/apiClient.js';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';

const orderStore = useOrderStore();
const userStore = useUserStore();
const toast = useToastStore();

const canEdit = computed(() => userStore.hasAccess('novelties', 'edit'));
const items = ref([]);
const loading = ref(false);
const noveltyDays = ref(21);
const search = ref('');
const showHidden = ref(false);

const editing = ref(null);   // текущая новинка в модалке
const form = ref({ description: '', sales_start_date: '', show_until: '', is_hidden: false });
let _formSnapshot = '';
const saving = ref(false);
const uploading = ref(false);
const photoInput = ref(null);

// Ручное добавление товара (в т.ч. старой карточки)
const showAdd = ref(false);
const addSearch = ref('');
const addResults = ref([]);
const addSearching = ref(false);
const addingId = ref('');
let _addTimer = null;

// Кэш ссылок на фото с download-токеном (фото требует авторизации).
const _photoUrls = ref({});

function currentGroup() { return getEntityGroupCode(orderStore.settings.legalEntity); }

async function load() {
  loading.value = true;
  try {
    const group = currentGroup();
    const { data, error } = await db.request('GET', 'novelties?group=' + encodeURIComponent(group));
    if (error) throw new Error(error);
    items.value = data?.items || [];
    if (data?.novelty_days) noveltyDays.value = data.novelty_days;
    // подгружаем защищённые ссылки на фото
    for (const it of items.value) {
      if (it.photo_url) resolvePhoto(it);
    }
  } catch (e) {
    items.value = [];
    toast.error('Ошибка', 'Не удалось загрузить новинки');
  } finally {
    loading.value = false;
  }
}

async function resolvePhoto(it) {
  try {
    const url = await getDownloadUrl(it.photo_url);
    _photoUrls.value = { ..._photoUrls.value, [it.product_id]: url };
  } catch { /* ignore */ }
}
function photoSrc(it) { return _photoUrls.value[it.product_id] || it.photo_url; }

const filtered = computed(() => {
  let list = items.value;
  if (!showHidden.value) list = list.filter(it => !it.is_hidden);
  const q = search.value.trim().toLowerCase();
  if (q) list = list.filter(it => (it.name || '').toLowerCase().includes(q) || (it.sku || '').toLowerCase().includes(q));
  return list;
});
const currentCount = computed(() => items.value.filter(it => it.is_current).length);
const hiddenCount = computed(() => items.value.filter(it => it.is_hidden).length);
const describedCount = computed(() => items.value.filter(it => (it.description || '').trim()).length);

function fmtDate(d) {
  if (!d) return '';
  const dt = new Date(String(d).replace(' ', 'T'));
  if (isNaN(dt)) return '';
  return dt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}
// Артикул + название — единое целое, артикул всегда впереди.
function novTitle(it) {
  const sku = (it.sku || '').trim();
  return sku ? sku + ' ' + it.name : it.name;
}

function snap() { return JSON.stringify(form.value); }
function openEdit(it) {
  editing.value = it;
  form.value = {
    description: it.description || '',
    sales_start_date: (it.sales_start_date || '').slice(0, 10),
    show_until: (it.show_until || '').slice(0, 10),
    is_hidden: !!it.is_hidden,
  };
  _formSnapshot = snap();
}
function closeEdit() {
  if (snap() !== _formSnapshot && !confirm('Закрыть без сохранения? Изменения пропадут.')) return;
  editing.value = null;
}

async function save() {
  if (!editing.value) return;
  saving.value = true;
  try {
    const id = editing.value.product_id;
    const { error } = await db.request('POST', 'novelties/' + encodeURIComponent(id), {
      description: form.value.description.trim(),
      sales_start_date: form.value.sales_start_date || '',
      show_until: form.value.show_until || '',
      is_hidden: form.value.is_hidden ? 1 : 0,
    });
    if (error) throw new Error(error);
    toast.success('Сохранено', editing.value.name);
    _formSnapshot = snap();
    editing.value = null;
    await load();
  } catch (e) {
    toast.error('Ошибка', 'Не удалось сохранить');
  } finally {
    saving.value = false;
  }
}

async function quickToggleHidden(it, hidden) {
  try {
    const { error } = await db.request('POST', 'novelties/' + encodeURIComponent(it.product_id), {
      description: it.description || '',
      sales_start_date: (it.sales_start_date || '').slice(0, 10),
      show_until: (it.show_until || '').slice(0, 10),
      is_hidden: hidden ? 1 : 0,
    });
    if (error) throw new Error(error);
    toast.success(hidden ? 'Скрыто' : 'Возвращено', it.name);
    await load();
  } catch (e) {
    toast.error('Ошибка', 'Не удалось изменить');
  }
}

function pickPhoto() { photoInput.value?.click(); }
async function onPhotoFile(e) {
  const file = e.target.files?.[0];
  e.target.value = '';
  if (!file || !editing.value) return;
  uploading.value = true;
  try {
    const fd = new FormData();
    fd.append('file', file);
    const { data, error } = await db.request('POST', 'novelties/' + encodeURIComponent(editing.value.product_id) + '/photo', fd);
    if (error) throw new Error(error);
    // обновляем в модалке и в списке
    editing.value.photo_url = data.photo_url;
    const row = items.value.find(x => x.product_id === editing.value.product_id);
    if (row) { row.photo_url = data.photo_url; resolvePhoto(row); }
    resolvePhoto(editing.value);
    toast.success('Фото загружено', editing.value.name);
  } catch (err) {
    toast.error('Ошибка', err.message || 'Не удалось загрузить фото');
  } finally {
    uploading.value = false;
  }
}
async function removePhoto() {
  if (!editing.value || !confirm('Удалить фото новинки?')) return;
  uploading.value = true;
  try {
    const { error } = await db.request('DELETE', 'novelties/' + encodeURIComponent(editing.value.product_id) + '/photo');
    if (error) throw new Error(error);
    editing.value.photo_url = null;
    const row = items.value.find(x => x.product_id === editing.value.product_id);
    if (row) row.photo_url = null;
    toast.success('Фото удалено', editing.value.name);
  } catch (e) {
    toast.error('Ошибка', 'Не удалось удалить фото');
  } finally {
    uploading.value = false;
  }
}

// ── Ручное добавление товара ──
function openAdd() { showAdd.value = true; addSearch.value = ''; addResults.value = []; }
function closeAdd() { showAdd.value = false; }
watch(addSearch, (v) => {
  clearTimeout(_addTimer);
  const q = (v || '').trim();
  if (q.length < 2) { addResults.value = []; addSearching.value = false; return; }
  addSearching.value = true;
  _addTimer = setTimeout(doAddSearch, 300);
});
async function doAddSearch() {
  const q = addSearch.value.trim();
  if (q.length < 2) { addSearching.value = false; return; }
  try {
    const { data } = await db.request('GET', 'novelties/search?q=' + encodeURIComponent(q) + '&group=' + encodeURIComponent(currentGroup()));
    addResults.value = data?.items || [];
  } catch { addResults.value = []; }
  finally { addSearching.value = false; }
}
async function addProduct(p) {
  addingId.value = p.product_id;
  try {
    // Старая карточка сама по себе уже за пределами авто-окна — ставим срок
    // показа вперёд, чтобы новинка появилась у ресторанов.
    const until = new Date(Date.now() + noveltyDays.value * 86400000).toISOString().slice(0, 10);
    const { error } = await db.request('POST', 'novelties/' + encodeURIComponent(p.product_id), {
      description: '',
      sales_start_date: '',
      show_until: until,
      is_hidden: 0,
    });
    if (error) throw new Error(error);
    toast.success('Добавлено в новинки', (p.sku ? p.sku + ' ' : '') + p.name);
    showAdd.value = false;
    await load();
    const row = items.value.find(x => x.product_id === p.product_id);
    if (row) openEdit(row);
  } catch (e) {
    toast.error('Ошибка', 'Не удалось добавить товар');
  } finally {
    addingId.value = '';
  }
}

watch(() => orderStore.settings.legalEntity, load);
onMounted(load);
</script>

<style scoped>
.nov { padding: 4px 2px 60px; }
.page-title { font-size: 22px; font-weight: 800; color: var(--text); margin: 0; display: flex; align-items: center; gap: 9px; }
.nov-newtag { display: inline-flex; align-items: center; font-size: 12px; font-weight: 800; letter-spacing: .6px; color: #fff; background: #E4572E; padding: 3px 8px; border-radius: 5px; line-height: 1.3; box-shadow: 0 1px 3px rgba(228,87,46,.35); }
.nov-sub { font-size: 12px; color: var(--text-muted); margin: 2px 0 0; max-width: 720px; line-height: 1.5; }
.nov-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
.nov-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.nov-input { padding: 7px 12px; border: 1.5px solid var(--border); border-radius: 8px; font-size: 13px; background: var(--card); color: var(--text); }
.nov-search-wrap { position: relative; display: flex; align-items: center; }
.nov-search-ico { position: absolute; left: 10px; font-size: 12px; opacity: .6; pointer-events: none; }
.nov-search-input { padding-left: 30px; padding-right: 28px; min-width: 260px; }
.nov-clear { position: absolute; right: 6px; border: none; background: var(--border-light); color: var(--text-muted); border-radius: 50%; width: 18px; height: 18px; line-height: 16px; cursor: pointer; font-size: 14px; padding: 0; }
.nov-toggle { font-size: 12px; color: var(--text-muted); display: flex; align-items: center; gap: 5px; cursor: pointer; }
.nov-stats { display: flex; gap: 16px; flex-wrap: wrap; font-size: 12px; color: var(--text-muted); margin-bottom: 14px; }
.nov-stats b { color: var(--text); font-size: 14px; }
.nov-empty { text-align: center; padding: 50px; color: var(--text-muted); }
.nov-list { display: flex; flex-direction: column; gap: 8px; }
.nov-card { display: flex; gap: 12px; background: var(--card); border: 1px solid var(--border-light); border-radius: 12px; padding: 10px; align-items: flex-start; }
.nov-card-off { opacity: .62; }
.nov-thumb { width: 64px; height: 64px; flex: none; border-radius: 10px; overflow: hidden; background: var(--bg); display: flex; align-items: center; justify-content: center; cursor: pointer; border: 1px solid var(--border-light); }
.nov-thumb img { width: 100%; height: 100%; object-fit: cover; }
.nov-thumb-ph { font-size: 30px; opacity: .5; }
.nov-thumb-ph-lg { font-size: 54px; }
.nov-body { flex: 1; min-width: 0; }
.nov-name-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.nov-name { font-weight: 700; color: var(--text); font-size: 14px; }
.nov-badge { font-size: 10px; font-weight: 800; border-radius: 5px; padding: 2px 7px; white-space: nowrap; }
.nov-badge-live { background: #E8F5E9; color: #2E7D32; }
.nov-badge-hidden { background: #ECEFF1; color: #607D8B; }
.nov-badge-gone { background: #FFF3E0; color: #E65100; }
.nov-meta { display: flex; gap: 12px; flex-wrap: wrap; font-size: 11.5px; color: var(--text-muted); margin: 3px 0; }
.nov-sku { font-weight: 700; color: #B26A00; font-variant-numeric: tabular-nums; }
.nov-start { color: #2E7D32; font-weight: 600; }
.nov-desc { font-size: 12.5px; color: var(--text); line-height: 1.45; margin-top: 2px; white-space: pre-wrap; }
.nov-desc-empty { color: var(--text-muted); font-style: italic; }
.nov-side { display: flex; flex-direction: column; gap: 6px; flex: none; }
.nov-btn { padding: 6px 12px; border: none; border-radius: 8px; background: #502314; color: #fff; font-weight: 700; font-size: 12px; cursor: pointer; white-space: nowrap; }
.nov-btn:hover:not(:disabled) { background: #3d1a0f; }
.nov-btn:disabled { opacity: .5; cursor: default; }
.nov-btn-ghost { background: var(--card); color: #502314; border: 1.5px solid var(--border); }
.nov-btn-ghost:hover:not(:disabled) { background: var(--bg); }
.nov-btn-del { background: #C0392B; }
.nov-btn-del:hover:not(:disabled) { background: #a93226; }
/* Модалка */
.nov-modal-back { position: fixed; inset: 0; background: rgba(0,0,0,.5); backdrop-filter: blur(3px); display: flex; align-items: center; justify-content: center; z-index: 3000; padding: 20px; }
.nov-modal { background: var(--card); border-radius: 14px; width: 100%; max-width: 500px; max-height: 90vh; overflow-y: auto; padding: 20px; box-shadow: 0 10px 40px rgba(0,0,0,.25); }
.nov-modal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; gap: 10px; }
.nov-modal-head h3 { margin: 0; font-size: 16px; color: #502314; }
.nov-modal-x { border: none; background: transparent; font-size: 24px; line-height: 1; color: var(--text-muted); cursor: pointer; }
.nov-photo-box { display: flex; gap: 14px; margin-bottom: 16px; align-items: center; }
.nov-photo-prev { width: 96px; height: 96px; flex: none; border-radius: 12px; overflow: hidden; background: var(--bg); display: flex; align-items: center; justify-content: center; border: 1px solid var(--border-light); }
.nov-photo-prev img { width: 100%; height: 100%; object-fit: cover; }
.nov-photo-ctrls { display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }
.nov-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 12px; font-size: 12px; font-weight: 700; color: #502314; }
.nov-field .nov-input { width: 100%; box-sizing: border-box; font-weight: 400; }
.nov-textarea { resize: vertical; font-family: inherit; }
.nov-hint { font-weight: 400; font-size: 11px; color: var(--text-muted); }
.nov-check { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text); margin: 6px 0 4px; cursor: pointer; }
.nov-modal-actions { display: flex; align-items: center; gap: 8px; margin-top: 14px; }
.nov-modal-spacer { flex: 1; }
.nov-add-results { margin-top: 12px; max-height: 50vh; overflow-y: auto; display: flex; flex-direction: column; gap: 4px; }
.nov-add-state { padding: 16px; text-align: center; color: var(--text-muted); font-size: 13px; }
.nov-add-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; width: 100%; text-align: left; padding: 9px 12px; border: 1px solid var(--border-light); border-radius: 8px; background: var(--card); cursor: pointer; font-size: 13px; color: var(--text); }
.nov-add-row:hover:not(:disabled) { background: var(--bg); border-color: var(--border); }
.nov-add-row:disabled { opacity: .5; cursor: default; }
.nov-add-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.nov-add-plus { flex: none; font-weight: 800; color: #502314; font-size: 16px; }
@media (max-width: 700px) {
  .nov-search-input { min-width: 0; width: 100%; }
  .nov-side { flex-direction: row; }
  .nov-card { flex-wrap: wrap; }
}
</style>
