<template>
  <div class="mp-view">
    <div class="mp-header">
      <h1 class="page-title">Протоколы совещаний</h1>
      <div class="mp-header-actions">
        <button v-if="canEdit" class="mp-btn mp-btn-series" @click="showSeriesModal = true">Форматы</button>
        <button v-if="canEdit" class="mp-btn mp-btn-primary" @click="createProtocol">
          + Новый<span class="mp-btn-word">&nbsp;протокол</span>
        </button>
      </div>
    </div>

    <!-- Поиск и фильтры -->
    <div class="mp-toolbar">
      <div class="mp-search">
        <BkIcon name="search" size="sm" class="mp-search-icon" />
        <input v-model="searchText" type="text" class="mp-search-input"
          placeholder="Поиск по теме, участнику, автору" aria-label="Поиск протоколов">
        <button v-if="searchText" type="button" class="mp-search-clear" aria-label="Очистить поиск"
          @click="searchText = ''">&times;</button>
      </div>
      <select v-model="filterSeries" class="mp-select" aria-label="Фильтр по формату совещания">
        <option value="">Все совещания</option>
        <option v-for="s in seriesList" :key="s.id" :value="String(s.id)">{{ s.name }} ({{ s.protocols_count }})</option>
      </select>
      <select v-model="filterStatus" class="mp-select" aria-label="Фильтр по статусу">
        <option value="">Все статусы</option>
        <option value="draft">Черновик</option>
        <option value="final">Финальный</option>
      </select>
    </div>

    <!-- Статистика задач -->
    <div v-if="decisionStats.total > 0" class="mp-stats">
      <div class="mp-stat">
        <span class="mp-stat-num">{{ decisionStats.total }}</span>
        <span class="mp-stat-label">задач</span>
      </div>
      <div class="mp-stat mp-stat-done">
        <span class="mp-stat-num">{{ decisionStats.done }}</span>
        <span class="mp-stat-label">выполнено</span>
      </div>
      <div class="mp-stat mp-stat-pending">
        <span class="mp-stat-num">{{ decisionStats.pending }}</span>
        <span class="mp-stat-label">в работе</span>
      </div>
      <!-- Плашка — переключатель: оставляет в списке только протоколы с просрочкой -->
      <button v-if="decisionStats.overdue > 0" type="button" class="mp-stat mp-stat-overdue mp-stat-btn"
        :class="{ 'is-active': filterOverdue }" :aria-pressed="filterOverdue"
        :title="filterOverdue ? 'Показать все протоколы' : 'Показать только протоколы с просрочкой'"
        @click="filterOverdue = !filterOverdue">
        <span class="mp-stat-num">{{ decisionStats.overdue }}</span>
        <span class="mp-stat-label">просрочено</span>
      </button>
    </div>

    <!-- Загрузка (только когда показывать ещё нечего) -->
    <div v-if="loading && !protocols.length" class="mp-loading"><BurgerSpinner text="Загрузка..." /></div>

    <!-- Сбой загрузки: раздел не должен выглядеть пустым -->
    <div v-else-if="loadError && !protocols.length" class="mp-error">
      <div class="mp-error-title">Не удалось загрузить протоколы</div>
      <div class="mp-error-text">{{ loadError }}</div>
      <button class="mp-btn" @click="retryLoad">Повторить</button>
    </div>

    <template v-else>
      <!-- Данные на экране есть, но обновить их не вышло -->
      <div v-if="loadError" class="mp-error-bar">
        <span>Список мог устареть: {{ loadError }}</span>
        <button class="mp-btn-sm" @click="retryLoad">Повторить</button>
      </div>

      <!-- Список протоколов -->
      <div v-if="cards.length" class="mp-list">
        <!-- Ссылка, а не кнопка: открывается в новой вкладке и текст можно выделить -->
        <router-link v-for="p in cards" :key="p.id" class="mp-card"
          :to="{ name: 'protocol-detail', params: { id: p.id } }">
          <span class="mp-card-top">
            <span class="mp-card-date">{{ p.dateLabel }}</span>
            <span class="mp-card-badge" :class="'mp-badge-' + p.status">{{ p.statusLabel }}</span>
          </span>
          <span class="mp-card-topic" :title="p.topic">{{ p.topic }}</span>
          <span class="mp-card-meta">
            <span v-if="p.seriesName" class="mp-card-series">{{ p.seriesName }}</span>
            <span>{{ p.createdBy }}</span>
            <span v-if="p.decisionsTotal > 0" class="mp-card-decisions">{{ p.decisionsDone }}/{{ p.decisionsTotal }} задач</span>
            <span v-if="p.overdue > 0" class="mp-card-overdue">{{ p.overdue }} просрочено</span>
          </span>
          <span v-if="p.people.length" class="mp-card-participants">
            <!-- На телефоне имена не помещаются — там показываем только количество -->
            <span class="mp-people-count">{{ p.peopleLabel }}</span>
            <span v-for="(a, i) in p.people" :key="i" class="mp-person" :title="a.name">{{ a.short }}</span>
            <span v-if="p.more" class="mp-person mp-person-more" :title="p.moreTitle">+{{ p.more }}</span>
          </span>
        </router-link>
      </div>
      <!-- Пусто из-за фильтров — это не то же самое, что «протоколов нет» -->
      <div v-else-if="hasFilters" class="mp-empty">
        <div>По выбранным фильтрам ничего не найдено</div>
        <button class="mp-btn mp-empty-btn" @click="resetFilters">Сбросить фильтры</button>
      </div>
      <div v-else class="mp-empty">
        <div>Протоколов пока нет</div>
        <button v-if="canEdit" class="mp-btn mp-btn-primary mp-empty-btn" @click="createProtocol">Создать первый протокол</button>
      </div>
    </template>

    <!-- Модалка форматов совещаний -->
    <div v-if="showSeriesModal" class="mp-overlay" @click.self="closeSeriesModal">
      <div class="mp-modal" role="dialog" aria-modal="true" aria-label="Форматы совещаний">
        <div class="mp-modal-header">
          <h2>Форматы совещаний</h2>
          <button class="mp-modal-close" title="Закрыть" aria-label="Закрыть" @click="closeSeriesModal">
            <BkIcon name="close" size="md" />
          </button>
        </div>
        <div class="mp-modal-body">
          <div v-if="seriesError" class="mp-error-bar">
            <span>Не удалось загрузить форматы: {{ seriesError }}</span>
            <button class="mp-btn-sm" @click="loadSeries">Повторить</button>
          </div>
          <div v-for="s in seriesList" :key="s.id" class="mp-series-row">
            <div class="mp-series-info">
              <strong>{{ s.name }}</strong>
              <span class="mp-series-meta">{{ recurrenceLabel(s.recurrence) }} · {{ s.protocols_count }} протокол(ов)</span>
            </div>
            <div class="mp-series-actions">
              <button class="mp-btn-sm" @click="editSeries(s)">Изменить</button>
              <button v-if="userStore.isAdmin" class="mp-btn-sm mp-btn-danger" @click="deleteSeries(s)">Удалить</button>
            </div>
          </div>
          <div v-if="!seriesList.length && !seriesError" class="mp-series-empty">Форматов пока нет</div>
          <!-- Форма развёрнута только когда её открыли: на телефоне окно не тонет в полях -->
          <button v-if="!showSeriesForm" class="mp-btn mp-btn-add-format" @click="openSeriesForm">+ Добавить формат</button>
          <div v-else class="mp-series-form">
            <h3>{{ editingSeriesId ? 'Редактировать формат' : 'Новый формат' }}</h3>
            <input v-model="seriesForm.name" class="mp-input" placeholder="Название (напр. Еженедельная планёрка)">
            <select v-model="seriesForm.recurrence" class="mp-select">
              <option value="weekly">Еженедельно</option>
              <option value="biweekly">Раз в 2 недели</option>
              <option value="monthly">Ежемесячно</option>
              <option value="custom">Другое</option>
            </select>
            <textarea v-model="seriesForm.agendaText" class="mp-textarea" placeholder="Шаблон повестки (каждый пункт с новой строки)" rows="3"></textarea>
            <div class="mp-series-form-btns">
              <button class="mp-btn mp-btn-primary" @click="saveSeries">{{ editingSeriesId ? 'Сохранить' : 'Создать' }}</button>
              <button class="mp-btn" @click="cancelSeriesForm">Отмена</button>
            </div>
          </div>
        </div>
      </div>
    </div>
    <ConfirmModal v-if="confirmModal.show" :title="confirmModal.title" :message="confirmModal.message"
      @confirm="onConfirm" @cancel="onCancel" />
  </div>
</template>

<script setup>
import { ref, computed, defineAsyncComponent, onMounted, onActivated, onUnmounted, watch } from 'vue';
import { confirmDiscard } from '@/composables/useFormDirty.js';
import { useRoute, useRouter } from 'vue-router';
import { db } from '@/lib/apiClient.js';
import { useUserStore } from '@/stores/userStore.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { useConfirm } from '@/composables/useConfirm.js';
import BkIcon from '@/components/ui/BkIcon.vue';

const ConfirmModal = defineAsyncComponent(() => import('@/components/modals/ConfirmModal.vue'));

const orderStore = useOrderStore();

const route = useRoute();
const router = useRouter();
const userStore = useUserStore();
const toast = useToastStore();

const loading = ref(false);
const protocols = ref([]);
const seriesList = ref([]);
const loadError = ref('');
const seriesError = ref('');
const filterSeries = ref('');
const filterStatus = ref('');
const filterOverdue = ref(false);
const searchText = ref('');
const showSeriesModal = ref(false);
const showSeriesForm = ref(false);
const editingSeriesId = ref(null);
const seriesForm = ref({ name: '', recurrence: 'weekly', agendaText: '' });

const { confirmModal, confirm, onConfirm, onCancel } = useConfirm();
const canEdit = computed(() => userStore.hasAccess('protocols', 'edit'));

const hasFilters = computed(() =>
  !!filterSeries.value || !!filterStatus.value || filterOverdue.value || !!searchText.value.trim());

// Регистр и «ё» при поиске не должны мешать: «Ерома» находит и «ерёма».
function normalize(s) {
  return String(s || '').toLowerCase().replace(/ё/g, 'е');
}

// Участники и строка для поиска разбираются один раз на протокол,
// а не заново при каждой перерисовке списка и на каждой букве в поиске.
const prepared = computed(() => protocols.value.map((p) => {
  const names = parseParticipants(p.participants);
  return {
    raw: p,
    names,
    overdue: Number(p.overdue_count) || 0,
    // Ищем по теме, автору и именам участников.
    haystack: normalize([p.topic, p.created_by, ...names].join(' ')),
  };
}));

const filtered = computed(() => {
  let list = prepared.value;
  // id из адреса и из базы приходят строками — сравниваем строки, чтобы
  // выбранный пункт совпадал и в выпадайке, и при фильтрации.
  if (filterSeries.value) list = list.filter(x => String(x.raw.series_id) === filterSeries.value);
  if (filterStatus.value) list = list.filter(x => x.raw.status === filterStatus.value);
  if (filterOverdue.value) list = list.filter(x => x.overdue > 0);
  const q = normalize(searchText.value.trim());
  if (q) list = list.filter(x => x.haystack.includes(q));
  return list;
});

// Готовые данные карточек.
const cards = computed(() => filtered.value.map((x) => {
  const p = x.raw;
  const shown = x.names.slice(0, 3);
  const rest = x.names.slice(3);
  return {
    id: p.id,
    status: p.status,
    statusLabel: p.status === 'final' ? 'Финальный' : 'Черновик',
    dateLabel: fmtDate(p.meeting_date),
    topic: p.topic,
    seriesName: p.series_name || '',
    createdBy: p.created_by || '',
    decisionsTotal: Number(p.decisions_count) || 0,
    decisionsDone: Number(p.decisions_done) || 0,
    overdue: x.overdue,
    people: shown.map(n => ({ name: n, short: shortName(n) })),
    more: rest.length,
    moreTitle: rest.join(', '),
    peopleLabel: `${x.names.length} ${plural(x.names.length, 'участник', 'участника', 'участников')}`,
  };
}));

const decisionStats = computed(() => {
  let total = 0, done = 0, overdue = 0;
  for (const p of protocols.value) {
    // База отдаёт счётчики строками: без Number плюс склеивал их в «01511»,
    // а «в работе» уходило в минус.
    total += Number(p.decisions_count) || 0;
    done += Number(p.decisions_done) || 0;
    // Если сервер ещё не отдаёт overdue_count — выйдет 0, блок просто не покажется.
    overdue += Number(p.overdue_count) || 0;
  }
  // Три числа не пересекаются: всего = выполнено + в работе + просрочено.
  return { total, done, overdue, pending: Math.max(0, total - done - overdue) };
});

function parseParticipants(p) {
  if (Array.isArray(p)) return p;
  if (typeof p === 'string') { try { const v = JSON.parse(p); return Array.isArray(v) ? v : []; } catch { return []; } }
  return [];
}

// Одна буква в кружке не различала тёзок. Показываем первое слово целиком,
// остальные — инициалами: «Ерома Инна» -> «Ерома И.».
function shortName(n) {
  const parts = String(n || '').trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return '—';
  if (parts.length === 1) return parts[0];
  return parts[0] + ' ' + parts.slice(1).map(w => w[0].toUpperCase() + '.').join(' ');
}

function plural(n, one, few, many) {
  const a = Math.abs(n) % 100, b = a % 10;
  if (a > 10 && a < 20) return many;
  if (b > 1 && b < 5) return few;
  if (b === 1) return one;
  return many;
}

function fmtDate(d) {
  if (!d) return '';
  const dt = new Date(d + 'T00:00:00');
  return dt.toLocaleDateString('ru-RU', { day: '2-digit', month: 'long', year: 'numeric' });
}

function recurrenceLabel(r) {
  return { weekly: 'Еженедельно', biweekly: 'Раз в 2 недели', monthly: 'Ежемесячно', custom: 'Другое' }[r] || r;
}

// silent — тихое обновление: список на экране не мигает спиннером.
async function loadProtocols({ silent = false } = {}) {
  if (!silent) loading.value = true;
  const { data, error } = await db.rpc('get_protocols', { legal_entity: orderStore.settings.legalEntity });
  if (error) {
    // Обнулять список молча нельзя: пустой раздел читается как «данные пропали».
    loadError.value = String(error);
  } else {
    loadError.value = '';
    protocols.value = Array.isArray(data) ? data : [];
  }
  if (!silent) loading.value = false;
}

async function loadSeries() {
  const { data, error } = await db.rpc('get_protocol_series', { legal_entity: orderStore.settings.legalEntity });
  if (error) { seriesError.value = String(error); return; }
  seriesError.value = '';
  seriesList.value = Array.isArray(data) ? data : [];
}

async function loadAll(opts) {
  await Promise.all([loadProtocols(opts), loadSeries()]);
}

async function retryLoad() {
  await loadAll();
}

function resetFilters() {
  filterSeries.value = '';
  filterStatus.value = '';
  filterOverdue.value = false;
  searchText.value = '';
}

// ═══ Адрес страницы ═══
// Выбранные фильтры живут в адресе: ссылку «черновики планёрки» можно
// отправить коллеге, и после обновления страницы фильтр не слетает.
function applyQueryFilters() {
  const q = route.query || {};
  const series = String(q.series || '');
  const status = String(q.status || '');
  const search = String(q.q || '');
  if (series) filterSeries.value = series;
  if (status === 'draft' || status === 'final') filterStatus.value = status;
  if (search) searchText.value = search;
  if (String(q.overdue || '') === '1') filterOverdue.value = true;
}

function syncUrl() {
  // Юрлицо можно переключить, находясь в другом разделе, — тогда адрес
  // трогать нельзя, иначе параметры уедут на чужую страницу.
  if (route.name !== 'protocols') return;
  const q = { ...route.query };
  if (filterSeries.value) q.series = String(filterSeries.value); else delete q.series;
  if (filterStatus.value) q.status = filterStatus.value; else delete q.status;
  if (searchText.value.trim()) q.q = searchText.value.trim(); else delete q.q;
  if (filterOverdue.value) q.overdue = '1'; else delete q.overdue;
  router.replace({ query: q }).catch(() => {});
}

applyQueryFilters();
watch([filterSeries, filterStatus, filterOverdue, searchText], syncUrl);

function createProtocol() { router.push({ name: 'protocol-detail', params: { id: 'new' } }); }

// Заполненная форма формата совещания не пропадает молча.
let closingSeries = false;
async function closeSeriesModal() {
  if (closingSeries) return;            // Esc дважды не стакает подтверждения
  if (!await confirmSeriesFormDiscard()) return;
  showSeriesModal.value = false;
  // Иначе при следующем открытии всплывал старый текст, да ещё в режиме
  // правки прежнего формата — легко было перезаписать не тот.
  resetSeriesForm();
}

async function confirmSeriesFormDiscard() {
  const f = seriesForm.value;
  const filled = (f.name || '').trim() || (f.agendaText || '').trim();
  if (!filled) return true;
  closingSeries = true;
  try { return await confirmDiscard(); } finally { closingSeries = false; }
}

// Окно закрывается по Esc. Слушатель вешаем только пока окно открыто:
// страница живёт в <KeepAlive> и не размонтируется при уходе в другой раздел.
function onSeriesKeydown(e) {
  if (e.key !== 'Escape') return;
  if (confirmModal.value.show) return;   // сверху висит подтверждение — Esc не наш
  closeSeriesModal();
}
watch(showSeriesModal, (open) => {
  if (open) document.addEventListener('keydown', onSeriesKeydown);
  else document.removeEventListener('keydown', onSeriesKeydown);
});
onUnmounted(() => document.removeEventListener('keydown', onSeriesKeydown));

function openSeriesForm() {
  resetSeriesForm();
  showSeriesForm.value = true;
}

async function cancelSeriesForm() {
  if (closingSeries) return;
  if (!await confirmSeriesFormDiscard()) return;
  resetSeriesForm();
}

function editSeries(s) {
  editingSeriesId.value = s.id;
  seriesForm.value.name = s.name;
  seriesForm.value.recurrence = s.recurrence;
  let tmpl = s.agenda_template || [];
  if (typeof tmpl === 'string') { try { tmpl = JSON.parse(tmpl || '[]'); } catch { tmpl = []; } }
  seriesForm.value.agendaText = Array.isArray(tmpl) ? tmpl.join('\n') : '';
  showSeriesForm.value = true;
}

function resetSeriesForm() {
  editingSeriesId.value = null;
  showSeriesForm.value = false;
  seriesForm.value = { name: '', recurrence: 'weekly', agendaText: '' };
}

async function saveSeries() {
  const agendaTemplate = seriesForm.value.agendaText.split('\n').map(s => s.trim()).filter(Boolean);
  const { error } = await db.rpc('save_protocol_series', {
    id: editingSeriesId.value || 0,
    name: seriesForm.value.name,
    legal_entity: orderStore.settings.legalEntity,
    recurrence: seriesForm.value.recurrence,
    agenda_template: agendaTemplate,
  });
  if (error) { toast.error(error); return; }
  toast.success('Формат сохранён');
  resetSeriesForm();
  // Название формата видно и в карточках списка — обновляем обе выборки.
  await loadAll({ silent: true });
}

async function deleteSeries(s) {
  if (!await confirm('Удаление формата', `Удалить формат «${s.name}»? Протоколы останутся, но потеряют привязку.`)) return;
  const { error } = await db.rpc('delete_protocol_series', { id: s.id });
  if (error) { toast.error('Не удалось удалить формат', String(error)); return; }
  toast.success('Формат удалён');
  if (String(editingSeriesId.value) === String(s.id)) resetSeriesForm();
  if (filterSeries.value === String(s.id)) filterSeries.value = '';
  // Протоколы потеряли привязку — их тоже перечитываем.
  await loadAll({ silent: true });
}

onMounted(async () => {
  await loadAll();
});

// Страница живёт в <KeepAlive>: onMounted срабатывает один раз. Без перечитывания
// при возврате созданный протокол не появлялся в списке, а удалённый оставался —
// человек думал, что сохранение не прошло.
let firstActivation = true;
onActivated(() => {
  if (firstActivation) { firstActivation = false; return; }
  applyQueryFilters();   // пришли по ссылке с фильтрами
  syncUrl();             // ...или вернулись «К списку» и адрес потерял их
  loadAll({ silent: true });
});

watch(() => orderStore.settings.legalEntity, async () => {
  protocols.value = [];
  seriesList.value = [];
  filterSeries.value = '';   // форматы у каждого юрлица свои
  await loadAll();
});
</script>

<style scoped>
.mp-view { padding: 0; }
.mp-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
.mp-header-actions { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.mp-select { padding: 5px 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; background: #fff; max-width: 100%; }
.mp-input { padding: 7px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; width: 100%; box-sizing: border-box; }
.mp-textarea { padding: 7px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; width: 100%; resize: vertical; box-sizing: border-box; font-family: inherit; }
.mp-btn { padding: 6px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; cursor: pointer; background: #fff; white-space: nowrap; }
.mp-btn:hover { background: #f5f5f5; }
.mp-btn-primary { background: #E76F51; color: #fff; border-color: #E76F51; }
.mp-btn-primary:hover { background: #b52200; }
.mp-btn-series { background: #f5f5f5; }
.mp-btn-sm { padding: 3px 8px; font-size: 12px; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; background: #fff; white-space: nowrap; }
.mp-btn-sm:hover { background: #f5f5f5; }
.mp-btn-danger { color: #E76F51; border-color: #fcc; }
.mp-btn-danger:hover { background: #fff0f0; }

/* Поиск и фильтры */
.mp-toolbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; }
.mp-toolbar .mp-select { flex: 0 1 auto; max-width: 220px; }
.mp-search { position: relative; display: flex; align-items: center; flex: 1 1 260px; min-width: 0; }
.mp-search-icon { position: absolute; left: 9px; pointer-events: none; }
.mp-search-input { width: 100%; box-sizing: border-box; padding: 6px 30px 6px 31px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; font-family: inherit; background: #fff; }
.mp-search-input:focus { outline: none; border-color: #E76F51; }
.mp-search-clear { position: absolute; right: 2px; width: 26px; height: 26px; display: flex; align-items: center; justify-content: center; border: none; background: none; color: #999; font-size: 18px; line-height: 1; cursor: pointer; border-radius: 50%; }
.mp-search-clear:hover { color: #333; background: #f0f0f0; }

/* Stats */
.mp-stats { display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
.mp-stat { background: #f5f5f5; border-radius: 6px; padding: 6px 12px; display: flex; align-items: baseline; gap: 5px; }
.mp-stat-num { font-size: 17px; font-weight: 700; color: #333; }
.mp-stat-label { font-size: 11px; color: #888; }
.mp-stat-done .mp-stat-num { color: #2e7d32; }
.mp-stat-pending .mp-stat-num { color: #e65100; }
.mp-stat-overdue .mp-stat-num { color: #c62828; }
/* Плашка-переключатель: видно, что на неё можно нажать и что она включена */
.mp-stat-btn { font: inherit; border: 1px solid transparent; cursor: pointer; transition: background .15s, border-color .15s; }
.mp-stat-btn:hover { background: #fdeaea; border-color: #f3c6c6; }
.mp-stat-btn:focus-visible { outline: 2px solid #c62828; outline-offset: 2px; }
.mp-stat-btn.is-active { background: #fdeaea; border-color: #c62828; }
.mp-stat-btn.is-active .mp-stat-label { color: #c62828; }

/* List — вертикальный, как тендеры */
.mp-list { display: flex; flex-direction: column; gap: 8px; }
/* Карточка — ссылка: открывается в новой вкладке, работает с клавиатуры */
/* Две строки: сверху дата, статус и тема (тема — главное, ей вся ширина),
   снизу мелким — формат, автор, счётчики и участники */
.mp-card { width: 100%; box-sizing: border-box; font: inherit; color: inherit; text-decoration: none; text-align: left; background: #fff; border: 1px solid #e8e8e8; border-radius: 8px; padding: 10px 16px; cursor: pointer; transition: box-shadow .15s; display: grid; grid-template-columns: auto minmax(0, 1fr) auto; grid-template-areas: "top topic topic" "meta meta parts"; gap: 3px 14px; align-items: center; }
.mp-card > .mp-card-top { grid-area: top; }
.mp-card > .mp-card-topic { grid-area: topic; }
.mp-card > .mp-card-meta { grid-area: meta; }
.mp-card > .mp-card-participants { grid-area: parts; justify-self: end; }
.mp-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.07); }
.mp-card:focus-visible { outline: 2px solid #E76F51; outline-offset: 2px; }
.mp-card-top { display: flex; align-items: center; gap: 10px; flex-shrink: 0; min-width: 130px; }
.mp-card-date { font-size: 13px; color: #666; white-space: nowrap; }
.mp-card-badge { font-size: 10px; padding: 2px 7px; border-radius: 10px; font-weight: 600; white-space: nowrap; }
.mp-badge-draft { background: #fff3e0; color: #e65100; }
.mp-badge-final { background: #e8f5e9; color: #2e7d32; }
.mp-card-topic { font-size: 14px; font-weight: 600; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mp-card-meta { display: flex; gap: 10px; font-size: 12px; color: #888; flex-shrink: 0; align-items: center; min-width: 0; }
.mp-card-series { background: #f0f0f0; padding: 1px 6px; border-radius: 4px; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mp-card-decisions { font-weight: 500; white-space: nowrap; }
.mp-card-overdue { color: #c62828; font-weight: 600; white-space: nowrap; }
.mp-card-participants { display: flex; gap: 4px; flex-shrink: 0; align-items: center; }
/* Имя вместо буквы: тёзки больше не сливаются */
.mp-person { background: #fdece7; color: #b23c17; border-radius: 10px; padding: 2px 8px; font-size: 11px; font-weight: 600; white-space: nowrap; max-width: 150px; overflow: hidden; text-overflow: ellipsis; }
.mp-person-more { background: #eee; color: #666; }
.mp-people-count { display: none; font-size: 12px; color: #888; white-space: nowrap; }

.mp-loading, .mp-empty { text-align: center; padding: 40px; color: #888; }
.mp-empty-btn { margin-top: 12px; }

/* Ошибки загрузки */
.mp-error { text-align: center; padding: 36px 20px; color: #666; border: 1px solid #f0dcd6; background: #fffaf8; border-radius: 8px; }
.mp-error-title { font-size: 15px; font-weight: 600; color: #c62828; margin-bottom: 6px; }
.mp-error-text { font-size: 13px; color: #888; margin-bottom: 14px; word-break: break-word; }
.mp-error-bar { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; padding: 8px 12px; border: 1px solid #f0dcd6; background: #fffaf8; border-radius: 6px; font-size: 12px; color: #b23c17; }

/* Modal */
.mp-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 1000; display: flex; align-items: center; justify-content: center; padding: 16px; box-sizing: border-box; overflow-y: auto; }
.mp-modal { background: #fff; border-radius: 10px; width: 100%; max-width: 520px; max-height: 100%; overflow-y: auto; }
.mp-modal-header { position: sticky; top: 0; background: #fff; z-index: 1; display: flex; justify-content: space-between; align-items: center; padding: 8px 8px 8px 18px; border-bottom: 1px solid #eee; border-radius: 10px 10px 0 0; }
.mp-modal-header h2 { margin: 0; font-size: 16px; }
/* Цель нажатия 44×44 — на телефоне в крестик не промахнуться */
.mp-modal-close { width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; border: none; background: none; cursor: pointer; border-radius: 8px; flex-shrink: 0; }
.mp-modal-close:hover { background: #f5f5f5; }
.mp-modal-body { padding: 14px 18px; }
.mp-series-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
.mp-series-info { min-width: 0; }
.mp-series-info strong { display: block; font-size: 13px; word-break: break-word; }
.mp-series-meta { font-size: 11px; color: #888; }
.mp-series-actions { display: flex; gap: 6px; flex-shrink: 0; }
.mp-series-empty { text-align: center; padding: 16px; color: #aaa; font-size: 13px; }
.mp-btn-add-format { width: 100%; margin-top: 14px; }
.mp-series-form { margin-top: 14px; display: flex; flex-direction: column; gap: 8px; }
.mp-series-form h3 { margin: 0 0 4px; font-size: 13px; }
.mp-series-form-btns { display: flex; gap: 8px; }

@media (max-width: 600px) {
  /* Шапка в одну строку: заголовок и кнопки рядом, поиск — отдельной строкой */
  .mp-header { gap: 6px; margin-bottom: 8px; }
  .mp-header .page-title { font-size: 17px; min-width: 0; overflow: hidden; text-overflow: ellipsis; }
  .mp-header-actions { flex-wrap: nowrap; flex-shrink: 0; }
  .mp-btn-word { display: none; }
  /* Поиск на всю ширину, фильтры делят вторую строку пополам */
  .mp-search { flex: 1 1 100%; }
  .mp-search-input { padding-right: 40px; }
  .mp-search-clear { width: 36px; height: 36px; font-size: 22px; }
  .mp-toolbar .mp-select { flex: 1 1 0; min-width: 0; max-width: none; }
  .mp-stat { flex: 1 1 auto; justify-content: center; }
  .mp-card { grid-template-columns: minmax(0, 1fr); grid-template-areas: "top" "topic" "meta" "parts"; gap: 6px; align-items: stretch; }
  .mp-card > .mp-card-participants { justify-self: start; }
  .mp-card-topic { white-space: normal; }
  .mp-card-meta { flex-wrap: wrap; gap: 6px 10px; }
  /* Имена участников не помещаются — оставляем количество */
  .mp-person { display: none; }
  .mp-people-count { display: inline; }
  .mp-loading, .mp-empty { padding: 28px 12px; }
  /* Окно прижато к верху и само прокручивается: поле не уезжает под клавиатуру */
  .mp-overlay { align-items: flex-start; padding: 8px; }
  .mp-modal { max-height: none; }
  /* 16px — иначе телефон зумит страницу при фокусе в поле */
  .mp-input, .mp-textarea, .mp-search-input, .mp-modal .mp-select { font-size: 16px; }
}
</style>
