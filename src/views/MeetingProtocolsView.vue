<template>
  <div class="mp-view">
    <div class="mp-header">
      <h1 class="page-title">Протоколы совещаний</h1>
      <div class="mp-header-actions">
        <select v-model="filterSeries" class="mp-select">
          <option value="">Все совещания</option>
          <option v-for="s in seriesList" :key="s.id" :value="s.id">{{ s.name }} ({{ s.protocols_count }})</option>
        </select>
        <select v-model="filterStatus" class="mp-select">
          <option value="">Все статусы</option>
          <option value="draft">Черновик</option>
          <option value="final">Финальный</option>
        </select>
        <button v-if="canEdit" class="mp-btn mp-btn-series" @click="showSeriesModal = true">Форматы</button>
        <button v-if="canEdit" class="mp-btn mp-btn-primary" @click="createProtocol">+ Новый протокол</button>
      </div>
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
      <div class="mp-stat mp-stat-overdue" v-if="decisionStats.overdue > 0">
        <span class="mp-stat-num">{{ decisionStats.overdue }}</span>
        <span class="mp-stat-label">просрочено</span>
      </div>
    </div>

    <!-- Загрузка -->
    <div v-if="loading" class="mp-loading">Загрузка...</div>

    <!-- Список протоколов -->
    <div v-else-if="filtered.length" class="mp-list">
      <div v-for="p in filtered" :key="p.id" class="mp-card" @click="openProtocol(p.id)">
        <div class="mp-card-top">
          <span class="mp-card-date">{{ fmtDate(p.meeting_date) }}</span>
          <span class="mp-card-badge" :class="'mp-badge-' + p.status">{{ p.status === 'final' ? 'Финальный' : 'Черновик' }}</span>
        </div>
        <div class="mp-card-topic" :title="p.topic">{{ p.topic }}</div>
        <div class="mp-card-meta">
          <span v-if="p.series_name" class="mp-card-series">{{ p.series_name }}</span>
          <span>{{ p.created_by }}</span>
          <span v-if="p.decisions_count > 0" class="mp-card-decisions">{{ p.decisions_done }}/{{ p.decisions_count }} задач</span>
        </div>
        <div v-if="parseParticipants(p.participants).length" class="mp-card-participants">
          <span v-for="name in parseParticipants(p.participants).slice(0, 4)" :key="name" class="mp-avatar" :title="name">{{ name[0] }}</span>
          <span v-if="parseParticipants(p.participants).length > 4" class="mp-avatar mp-avatar-more">+{{ parseParticipants(p.participants).length - 4 }}</span>
        </div>
      </div>
    </div>
    <div v-else class="mp-empty">Протоколов пока нет</div>

    <!-- Модалка серий -->
    <div v-if="showSeriesModal" class="mp-overlay" @click.self="showSeriesModal = false">
      <div class="mp-modal">
        <div class="mp-modal-header">
          <h2>Форматы совещаний</h2>
          <button class="mp-modal-close" @click="showSeriesModal = false">&times;</button>
        </div>
        <div class="mp-modal-body">
          <div v-for="s in seriesList" :key="s.id" class="mp-series-row">
            <div class="mp-series-info">
              <strong>{{ s.name }}</strong>
              <span class="mp-series-meta">{{ recurrenceLabel(s.recurrence) }} · {{ s.protocols_count }} протокол(ов)</span>
            </div>
            <div class="mp-series-actions">
              <button class="mp-btn-sm" @click="editSeries(s)">Ред.</button>
              <button v-if="userStore.isAdmin" class="mp-btn-sm mp-btn-danger" @click="deleteSeries(s)">Удл.</button>
            </div>
          </div>
          <div v-if="!seriesList.length" class="mp-series-empty">Серий пока нет</div>
          <div class="mp-series-form">
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
              <button v-if="editingSeriesId" class="mp-btn" @click="resetSeriesForm">Отмена</button>
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
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { db } from '@/lib/apiClient.js';
import { useUserStore } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';
import { useConfirm } from '@/composables/useConfirm.js';

const router = useRouter();
const userStore = useUserStore();
const toast = useToastStore();

const loading = ref(false);
const protocols = ref([]);
const seriesList = ref([]);
const filterSeries = ref('');
const filterStatus = ref('');
const showSeriesModal = ref(false);
const editingSeriesId = ref(null);
const seriesForm = ref({ name: '', recurrence: 'weekly', agendaText: '' });

const { confirmModal, confirm, onConfirm, onCancel } = useConfirm();
const canEdit = computed(() => userStore.hasAccess('protocols', 'edit'));

const filtered = computed(() => {
  let list = protocols.value;
  if (filterSeries.value) list = list.filter(p => p.series_id == filterSeries.value);
  if (filterStatus.value) list = list.filter(p => p.status === filterStatus.value);
  return list;
});

const decisionStats = computed(() => {
  let total = 0, done = 0, pending = 0, overdue = 0;
  for (const p of protocols.value) {
    total += p.decisions_count || 0;
    done += p.decisions_done || 0;
  }
  pending = total - done;
  // overdue подсчитаем позже на детальной странице
  return { total, done, pending, overdue };
});

function parseParticipants(p) {
  if (Array.isArray(p)) return p;
  if (typeof p === 'string') { try { return JSON.parse(p); } catch { return []; } }
  return [];
}

function fmtDate(d) {
  if (!d) return '';
  const dt = new Date(d + 'T00:00:00');
  return dt.toLocaleDateString('ru-RU', { day: '2-digit', month: 'long', year: 'numeric' });
}

function recurrenceLabel(r) {
  return { weekly: 'Еженедельно', biweekly: 'Раз в 2 недели', monthly: 'Ежемесячно', custom: 'Другое' }[r] || r;
}

async function loadProtocols() {
  loading.value = true;
  const { data, error } = await db.rpc('get_protocols');
  if (!error && data) protocols.value = data;
  loading.value = false;
}

async function loadSeries() {
  const { data } = await db.rpc('get_protocol_series');
  if (data) seriesList.value = data;
}

function openProtocol(id) { router.push({ name: 'protocol-detail', params: { id } }); }

function createProtocol() { router.push({ name: 'protocol-detail', params: { id: 'new' } }); }

function editSeries(s) {
  editingSeriesId.value = s.id;
  seriesForm.value.name = s.name;
  seriesForm.value.recurrence = s.recurrence;
  const tmpl = typeof s.agenda_template === 'string' ? JSON.parse(s.agenda_template || '[]') : (s.agenda_template || []);
  seriesForm.value.agendaText = tmpl.join('\n');
}

function resetSeriesForm() {
  editingSeriesId.value = null;
  seriesForm.value = { name: '', recurrence: 'weekly', agendaText: '' };
}

async function saveSeries() {
  const agendaTemplate = seriesForm.value.agendaText.split('\n').map(s => s.trim()).filter(Boolean);
  const { error } = await db.rpc('save_protocol_series', {
    id: editingSeriesId.value || 0,
    name: seriesForm.value.name,
    recurrence: seriesForm.value.recurrence,
    agenda_template: agendaTemplate,
  });
  if (error) { toast.error(error); return; }
  toast.success('Формат сохранён');
  resetSeriesForm();
  await loadSeries();
}

async function deleteSeries(s) {
  if (!await confirm('Удаление формата', `Удалить формат «${s.name}»? Протоколы останутся, но потеряют привязку.`)) return;
  await db.rpc('delete_protocol_series', { id: s.id });
  await loadSeries();
  toast.success('Формат удалён');
}

onMounted(async () => {
  await Promise.all([loadProtocols(), loadSeries()]);
});
</script>

<style scoped>
.mp-view { padding: 0; }
.mp-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
.mp-header-actions { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.mp-select { padding: 5px 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; background: #fff; }
.mp-input { padding: 7px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; width: 100%; box-sizing: border-box; }
.mp-textarea { padding: 7px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; width: 100%; resize: vertical; box-sizing: border-box; font-family: inherit; }
.mp-btn { padding: 6px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; cursor: pointer; background: #fff; white-space: nowrap; }
.mp-btn:hover { background: #f5f5f5; }
.mp-btn-primary { background: #D62700; color: #fff; border-color: #D62700; }
.mp-btn-primary:hover { background: #b52200; }
.mp-btn-series { background: #f5f5f5; }
.mp-btn-sm { padding: 3px 8px; font-size: 12px; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; background: #fff; }
.mp-btn-sm:hover { background: #f5f5f5; }
.mp-btn-danger { color: #D62700; border-color: #fcc; }
.mp-btn-danger:hover { background: #fff0f0; }

/* Stats */
.mp-stats { display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
.mp-stat { background: #f5f5f5; border-radius: 6px; padding: 6px 12px; display: flex; align-items: baseline; gap: 5px; }
.mp-stat-num { font-size: 17px; font-weight: 700; color: #333; }
.mp-stat-label { font-size: 11px; color: #888; }
.mp-stat-done .mp-stat-num { color: #2e7d32; }
.mp-stat-pending .mp-stat-num { color: #e65100; }
.mp-stat-overdue .mp-stat-num { color: #c62828; }

/* List — вертикальный, как тендеры */
.mp-list { display: flex; flex-direction: column; gap: 8px; }
.mp-card { background: #fff; border: 1px solid #e8e8e8; border-radius: 8px; padding: 12px 16px; cursor: pointer; transition: box-shadow .15s; display: flex; align-items: center; gap: 16px; }
.mp-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.07); }
.mp-card-top { display: flex; align-items: center; gap: 10px; flex-shrink: 0; min-width: 130px; }
.mp-card-date { font-size: 13px; color: #666; white-space: nowrap; }
.mp-card-badge { font-size: 10px; padding: 2px 7px; border-radius: 10px; font-weight: 600; white-space: nowrap; }
.mp-badge-draft { background: #fff3e0; color: #e65100; }
.mp-badge-final { background: #e8f5e9; color: #2e7d32; }
.mp-card-topic { font-size: 14px; font-weight: 600; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mp-card-meta { display: flex; gap: 10px; font-size: 12px; color: #888; flex-shrink: 0; align-items: center; }
.mp-card-series { background: #f0f0f0; padding: 1px 6px; border-radius: 4px; }
.mp-card-decisions { font-weight: 500; white-space: nowrap; }
.mp-card-participants { display: flex; gap: 3px; flex-shrink: 0; }
.mp-avatar { width: 24px; height: 24px; border-radius: 50%; background: #D62700; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 600; }
.mp-avatar-more { background: #888; }

.mp-loading, .mp-empty { text-align: center; padding: 40px; color: #888; }

/* Modal */
.mp-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 1000; display: flex; align-items: center; justify-content: center; }
.mp-modal { background: #fff; border-radius: 10px; width: 95%; max-width: 520px; max-height: 80vh; overflow-y: auto; }
.mp-modal-header { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-bottom: 1px solid #eee; }
.mp-modal-header h2 { margin: 0; font-size: 16px; }
.mp-modal-close { border: none; background: none; font-size: 22px; cursor: pointer; color: #888; }
.mp-modal-body { padding: 14px 18px; }
.mp-series-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
.mp-series-info strong { display: block; font-size: 13px; }
.mp-series-meta { font-size: 11px; color: #888; }
.mp-series-actions { display: flex; gap: 6px; }
.mp-series-empty { text-align: center; padding: 16px; color: #aaa; font-size: 13px; }
.mp-series-form { margin-top: 14px; display: flex; flex-direction: column; gap: 8px; }
.mp-series-form h3 { margin: 0 0 4px; font-size: 13px; }
.mp-series-form-btns { display: flex; gap: 8px; }

@media (max-width: 600px) {
  .mp-header { flex-direction: column; align-items: stretch; }
  .mp-card { flex-direction: column; align-items: stretch; gap: 6px; }
  .mp-card-topic { white-space: normal; }
}
</style>
