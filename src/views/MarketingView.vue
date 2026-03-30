<template>
  <div class="mkt-view">
    <div class="mkt-header">
      <h1 class="page-title">Маркетинг</h1>
      <div style="display:flex;gap:8px;align-items:center;">
        <div class="mkt-view-toggle">
          <button class="db-sort-btn" :class="{ active: viewMode === 'list' }" @click="viewMode = 'list'">Список</button>
          <button class="db-sort-btn" :class="{ active: viewMode === 'gantt' }" @click="viewMode = 'gantt'">Гант</button>
        </div>
        <button v-if="!isViewer" class="btn primary" @click="createActivity">+ Новая активность</button>
      </div>
    </div>

    <!-- Фильтры -->
    <div class="mkt-filters">
      <button class="db-sort-btn" :class="{ active: statusFilter === '' }" @click="statusFilter = ''">Все <span v-if="activities.length" class="mkt-count">{{ activities.length }}</span></button>
      <button class="db-sort-btn" :class="{ active: statusFilter === 'active' }" @click="statusFilter = 'active'">Активные <span v-if="countByStatus('active')" class="mkt-count">{{ countByStatus('active') }}</span></button>
      <button class="db-sort-btn" :class="{ active: statusFilter === 'completed' }" @click="statusFilter = 'completed'">Завершённые <span v-if="countByStatus('completed')" class="mkt-count">{{ countByStatus('completed') }}</span></button>
      <span class="mkt-divider">|</span>
      <button class="db-sort-btn" :class="{ active: typeFilter === '' }" @click="typeFilter = ''">Все типы</button>
      <button v-for="t in types" :key="t.value" class="db-sort-btn" :class="{ active: typeFilter === t.value }" @click="typeFilter = t.value" :style="typeFilter === t.value ? 'border-color:' + t.color + ';color:' + t.color : ''">
        {{ t.label }} <span v-if="countByType(t.value)" class="mkt-count">{{ countByType(t.value) }}</span>
      </button>
    </div>

    <!-- Загрузка -->
    <div v-if="loading" style="text-align:center;padding:40px;"><BurgerSpinner text="Загрузка..." /></div>

    <!-- Пусто -->
    <div v-else-if="!filtered.length" class="mkt-empty">
      <div class="mkt-empty-icon"><BkIcon name="marketing" size="lg" /></div>
      <div class="mkt-empty-text">{{ statusFilter || typeFilter ? 'Нет активностей с такими фильтрами' : 'Маркетинговых активностей пока нет' }}</div>
      <button v-if="!isViewer && !statusFilter && !typeFilter" class="btn primary" @click="createActivity" style="margin-top:12px;">Создать первую</button>
    </div>

    <!-- Гант -->
    <MarketingGantt v-else-if="viewMode === 'gantt'" :activities="filtered" @select="openActivity" />

    <!-- Список -->
    <div v-else class="mkt-list">
      <div v-for="a in filtered" :key="a.id" class="mkt-card" :style="'border-left-color:' + typeColor(a.type)" @click="openActivity(a.id)">
        <div class="mkt-card-left">
          <div class="mkt-card-top">
            <span class="mkt-type-badge" :style="'background:' + typeColor(a.type)">{{ typeLabel(a.type) }}</span>
            <span class="mkt-status-dot" :class="a.status"></span>
            <span class="mkt-card-name">{{ a.name }}</span>
          </div>
          <div class="mkt-card-meta">
            <span v-if="a.date_from"><BkIcon name="calendar" size="xs" /> {{ formatDate(a.date_from) }}{{ a.date_to ? ' — ' + formatDate(a.date_to) : '' }}</span>
            <span v-if="a.restaurant_count">{{ a.restaurant_count }} рест.</span>
            <span v-if="a.created_by"><BkIcon name="user" size="xs" /> {{ a.created_by }}</span>
          </div>
        </div>
        <div class="mkt-card-days" v-if="a.date_from && a.date_to">
          {{ daysLabel(a) }}
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { db } from '@/lib/apiClient.js';
import { formatDate, applyEntityFilter } from '@/lib/utils.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import MarketingGantt from '@/components/marketing/MarketingGantt.vue';

const router = useRouter();
const orderStore = useOrderStore();
const userStore = useUserStore();
const toast = useToastStore();

const isViewer = computed(() => !userStore.hasAccess('marketing', 'edit'));
const legalEntity = computed(() => orderStore.settings.legalEntity);

const activities = ref([]);
const loading = ref(false);
const viewMode = ref('list');
const statusFilter = ref('');
const typeFilter = ref('');

const types = [
  { value: 'promo', label: 'Промо', color: '#1D4ED8' },
  { value: 'new_product', label: 'Новинка', color: '#059669' },
  { value: 'discontinue', label: 'Вывод', color: '#DC2626' },
  { value: 'seasonal', label: 'Сезонное', color: '#D97706' },
  { value: 'coupon', label: 'Купон', color: '#7C3AED' },
];

function typeLabel(v) { return types.find(t => t.value === v)?.label || v; }
function typeColor(v) { return types.find(t => t.value === v)?.color || '#888'; }
function countByStatus(s) { return activities.value.filter(a => a.status === s).length; }
function countByType(t) { return activities.value.filter(a => a.type === t).length; }

function daysLabel(a) {
  const from = new Date(a.date_from + 'T00:00:00');
  const to = new Date(a.date_to + 'T00:00:00');
  const days = Math.round((to - from) / 86400000) + 1;
  const today = new Date(); today.setHours(0,0,0,0);
  if (today < from) { const left = Math.round((from - today) / 86400000); return `через ${left} дн · ${days} дн`; }
  if (today > to) return `${days} дн · завершена`;
  const left = Math.round((to - today) / 86400000) + 1;
  return `ещё ${left} из ${days} дн`;
}

const filtered = computed(() => {
  return activities.value.filter(a => {
    if (statusFilter.value && a.status !== statusFilter.value) return false;
    if (typeFilter.value && a.type !== typeFilter.value) return false;
    return true;
  });
});

let _loadGen = 0;
async function loadActivities() {
  const le = legalEntity.value;
  if (!le) return;
  const gen = ++_loadGen;
  loading.value = true;
  try {
    let q = db.from('marketing_activities').select('*');
    q = applyEntityFilter(q, le);
    q = q.order('date_from', { ascending: false });
    const { data, error } = await q;
    if (gen !== _loadGen) return;
    if (error) { toast.error('Ошибка', error); return; }
    activities.value = data || [];
  } finally {
    if (gen === _loadGen) loading.value = false;
  }
}

async function createActivity() {
  const { data, error } = await db.rpc('save_marketing_activity', {
    name: 'Новая активность',
    legal_entity: legalEntity.value,
    type: 'promo',
    status: 'active',
    items: [],
  });
  if (error) { toast.error('Ошибка', error); return; }
  router.push({ name: 'marketing-detail', params: { id: data.id } });
}

function openActivity(id) {
  router.push({ name: 'marketing-detail', params: { id } });
}

onMounted(() => { loadActivities(); });
watch(legalEntity, () => { activities.value = []; loadActivities(); });
</script>

<style scoped>
.mkt-view { padding: 0; }
.mkt-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
.mkt-view-toggle { display: flex; gap: 0; }
.mkt-view-toggle .db-sort-btn { border-radius: 0; margin-left: -1px; }
.mkt-view-toggle .db-sort-btn:first-child { border-radius: 8px 0 0 8px; margin-left: 0; }
.mkt-view-toggle .db-sort-btn:last-child { border-radius: 0 8px 8px 0; }
.mkt-filters { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 16px; align-items: center; }
.mkt-count { font-size: 10px; opacity: 0.7; margin-left: 2px; }
.mkt-divider { color: var(--border-light); font-size: 14px; margin: 0 2px; }

.db-sort-btn { display: inline-flex; align-items: center; gap: 4px; padding: 6px 14px; border-radius: 8px; border: 1.5px solid #D4C4B0; background: white; font-size: 11px; font-weight: 600; font-family: inherit; color: var(--text-muted); cursor: pointer; transition: all .15s; white-space: nowrap; }
.db-sort-btn:hover { border-color: var(--bk-orange); color: var(--text); }
.db-sort-btn.active { border-color: #D62300; color: var(--bk-brown); background: #FFFBF5; }

.mkt-empty { text-align: center; padding: 60px 20px; }
.mkt-empty-icon { margin-bottom: 12px; opacity: 0.3; }
.mkt-empty-text { font-size: 14px; color: var(--text-muted); }

/* Карточки — стиль как у тендеров */
.mkt-list { display: flex; flex-direction: column; gap: 10px; }
.mkt-card {
  display: flex; justify-content: space-between; align-items: center; gap: 14px;
  background: white; border-radius: 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.06);
  padding: 16px 20px; cursor: pointer; transition: all .15s;
  border-left: 4px solid transparent;
}
.mkt-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); transform: translateY(-1px); }
.mkt-card-left { flex: 1; min-width: 0; }
.mkt-card-top { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 6px; }
.mkt-card-name { font-weight: 700; font-size: 15px; color: var(--bk-brown, #502314); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mkt-type-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 10px; font-weight: 700; letter-spacing: 0.3px; color: #fff; white-space: nowrap; flex-shrink: 0; }
.mkt-status-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.mkt-status-dot.active { background: #4CAF50; box-shadow: 0 0 0 3px rgba(76,175,80,0.2); }
.mkt-status-dot.completed { background: #9E9E9E; }
.mkt-card-meta { display: flex; gap: 10px; font-size: 11px; color: var(--text-muted); flex-wrap: wrap; }
.mkt-card-meta span { display: inline-flex; align-items: center; gap: 3px; }
.mkt-card-days { font-size: 12px; font-weight: 600; white-space: nowrap; text-align: right; padding-left: 12px; color: var(--text-muted); }

@media (max-width: 480px) {
  .mkt-header { flex-direction: column; align-items: stretch; }
  .mkt-header .btn { text-align: center; }
  .mkt-filters { overflow-x: auto; flex-wrap: nowrap; gap: 4px; }
  .mkt-filters .db-sort-btn { flex-shrink: 0; font-size: 10px; padding: 4px 8px; }
  .mkt-card { flex-direction: column; align-items: stretch; gap: 8px; padding: 14px 16px; border-radius: 10px; }
  .mkt-card-days { text-align: left; padding-left: 0; }
}
</style>
