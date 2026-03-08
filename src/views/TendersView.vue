<template>
  <div class="tenders-view">
    <div class="tenders-header">
      <h1 class="page-title">Тендеры</h1>
      <button v-if="!isViewer" class="btn primary" @click="createTender">+ Новый тендер</button>
    </div>

    <!-- Фильтры -->
    <div class="tenders-filters">
      <button class="db-sort-btn" :class="{ active: statusFilter === '' }" @click="statusFilter = ''">Все <span v-if="tenders.length" class="filter-count">{{ tenders.length }}</span></button>
      <button v-for="s in statuses" :key="s.value" class="db-sort-btn" :class="{ active: statusFilter === s.value }" @click="statusFilter = s.value">
        {{ s.label }} <span v-if="countByStatus(s.value)" class="filter-count">{{ countByStatus(s.value) }}</span>
      </button>
    </div>

    <!-- Список -->
    <div v-if="loading" style="text-align:center;padding:40px;"><BurgerSpinner text="Загрузка..." /></div>
    <div v-else-if="!filteredTenders.length" class="empty-state">
      <div class="empty-icon"><BkIcon name="tender" size="lg" /></div>
      <div class="empty-text">{{ statusFilter ? 'Нет тендеров с таким статусом' : 'Тендеров пока нет' }}</div>
      <button v-if="!isViewer && !statusFilter" class="btn primary" @click="createTender" style="margin-top:12px;">Создать первый тендер</button>
    </div>
    <div v-else class="tenders-list">
      <div v-for="t in filteredTenders" :key="t.id" class="tender-card" :class="'st-' + t.status" @click="$router.push({ name: 'tender-detail', params: { id: t.id } })">
        <div class="tender-card-left">
          <div class="tender-card-top">
            <span class="tender-status-badge" :class="'st-' + t.status">{{ statusLabel(t.status) }}</span>
            <span class="tender-name">{{ t.name }}</span>
          </div>
          <div v-if="t.description" class="tender-desc">{{ t.description }}</div>
          <div class="tender-meta">
            <span v-if="t.deadline"><BkIcon name="calendar" size="xs" /> {{ formatDate(t.deadline) }}</span>
            <span><BkIcon name="user" size="xs" /> {{ t.created_by }}</span>
            <span>{{ formatDate(t.created_at) }}</span>
          </div>
        </div>
        <div v-if="t.winner_supplier" class="tender-card-right">
          <div class="winner-label">Победитель</div>
          <div class="winner-name">{{ t.winner_supplier }}</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { db } from '@/lib/apiClient.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';

const router = useRouter();
const orderStore = useOrderStore();
const userStore = useUserStore();
const toast = useToastStore();

const isViewer = computed(() => !userStore.hasAccess('tenders', 'edit'));
const legalEntity = computed(() => orderStore.settings.legalEntity);

const tenders = ref([]);
const loading = ref(false);
const statusFilter = ref('');

const statuses = [
  { value: 'draft', label: 'Черновики' },
  { value: 'collecting', label: 'Сбор предложений' },
  { value: 'evaluation', label: 'Оценка' },
  { value: 'approval', label: 'Согласование' },
  { value: 'completed', label: 'Завершённые' },
];

function statusLabel(s) {
  const found = statuses.find(x => x.value === s);
  return found ? found.label : s;
}
function countByStatus(s) { return tenders.value.filter(t => t.status === s).length; }

function formatDate(d) {
  if (!d) return '';
  const ds = typeof d === 'string' && d.length === 10 ? d + 'T00:00:00' : d;
  const dt = new Date(ds);
  if (isNaN(dt)) return d;
  return dt.toLocaleDateString('ru-RU');
}

let _loadGen = 0;
async function loadTenders() {
  const le = legalEntity.value;
  if (!le) return;
  const gen = ++_loadGen;
  loading.value = true;
  try {
    const { data, error } = await db.from('tenders').select('*').eq('legal_entity', le).order('created_at', { ascending: false });
    if (gen !== _loadGen) return;
    if (error) { toast.error('Ошибка', error); return; }
    tenders.value = data || [];
  } finally {
    if (gen === _loadGen) loading.value = false;
  }
}

const filteredTenders = computed(() => {
  if (!statusFilter.value) return tenders.value;
  return tenders.value.filter(t => t.status === statusFilter.value);
});

async function createTender() {
  const { data, error } = await db.rpc('save_tender', {
    name: 'Новый тендер',
    legal_entity: legalEntity.value,
    status: 'draft',
    items: [],
    offers: [],
  });
  if (error) { toast.error('Ошибка', error); return; }
  router.push({ name: 'tender-detail', params: { id: data.id } });
}

onMounted(() => { loadTenders(); });
watch(legalEntity, () => { tenders.value = []; loadTenders(); });
</script>

<style scoped>
.tenders-view { padding: 0; }
.tenders-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:16px; }
.tenders-filters { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:16px; }
.filter-count { font-size:10px; opacity:0.7; margin-left:2px; }

.empty-state { text-align:center; padding:60px 20px; }
.empty-icon { margin-bottom:12px; opacity:0.3; }
.empty-text { font-size:14px; color:var(--text-muted); }

/* Карточки */
.tenders-list { display:flex; flex-direction:column; gap:10px; }
.tender-card {
  display:flex; justify-content:space-between; align-items:center; gap:14px;
  background:white; border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,0.06);
  padding:16px 20px; cursor:pointer; transition:all .15s;
}
.tender-card:hover { box-shadow:0 4px 12px rgba(0,0,0,0.1); transform:translateY(-1px); }
.tender-card.st-completed { border-left:4px solid #4CAF50; }
.tender-card.st-collecting { border-left:4px solid #1D4ED8; }
.tender-card.st-evaluation { border-left:4px solid #B45309; }
.tender-card.st-approval { border-left:4px solid #7B1FA2; }

.tender-card-left { flex:1; min-width:0; }
.tender-card-top { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:6px; }
.tender-name { font-weight:700; font-size:15px; color:var(--bk-brown, #502314); }
.tender-desc { font-size:12px; color:var(--text-muted); margin-bottom:4px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.tender-meta { display:flex; gap:10px; font-size:11px; color:var(--text-muted); flex-wrap:wrap; }
.tender-meta span { display:inline-flex; align-items:center; gap:3px; }

.tender-card-right { text-align:right; flex-shrink:0; }
.winner-label { font-size:10px; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:0.5px; }
.winner-name { font-size:13px; font-weight:700; color:#2E7D32; margin-top:2px; }

.tender-status-badge { display:inline-block; padding:4px 12px; border-radius:20px; font-size:10px; font-weight:700; letter-spacing:0.3px; flex-shrink:0; }
.tender-status-badge.st-draft { background:rgba(158,158,158,0.15); color:#757575; }
.tender-status-badge.st-collecting { background:#DBEAFE; color:#1D4ED8; }
.tender-status-badge.st-evaluation { background:#FEF3C7; color:#B45309; }
.tender-status-badge.st-approval { background:rgba(156,39,176,0.12); color:#7B1FA2; }
.tender-status-badge.st-completed { background:rgba(76,175,80,0.15); color:#2E7D32; }

.db-sort-btn { display:inline-flex; align-items:center; gap:4px; padding:6px 14px; border-radius:8px; border:1.5px solid #D4C4B0; background:white; font-size:11px; font-weight:600; font-family:inherit; color:var(--text-muted); cursor:pointer; transition:all .15s; white-space:nowrap; }
.db-sort-btn:hover { border-color:var(--bk-orange); color:var(--text); }
.db-sort-btn.active { border-color:#D62300; color:var(--bk-brown); background:#FFFBF5; }

@media (max-width: 480px) {
  .tenders-header { flex-direction:column; align-items:stretch; }
  .tenders-header .btn { text-align:center; }
  .tenders-filters { overflow-x:auto; flex-wrap:nowrap; gap:4px; }
  .tenders-filters .db-sort-btn { flex-shrink:0; font-size:10px; padding:4px 8px; }
  .tender-card { flex-direction:column; align-items:stretch; gap:8px; padding:14px 16px; border-radius:10px; }
  .tender-card-right { text-align:left; }
}
</style>
