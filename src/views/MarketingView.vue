<template>
  <div class="mkt-view">
    <div class="mkt-header">
      <h1 class="page-title">Маркетинг</h1>
      <div style="display:flex;gap:8px;align-items:center;">
        <div class="mkt-view-toggle">
          <button class="db-sort-btn" :class="{ active: viewMode === 'list' }" @click="viewMode = 'list'">Список</button>
          <button class="db-sort-btn" :class="{ active: viewMode === 'gantt' }" @click="viewMode = 'gantt'">Гант</button>
          <button class="db-sort-btn" :class="{ active: viewMode === 'recipes' }" @click="viewMode = 'recipes'; loadRecipes()">Рецептуры</button>
        </div>
        <button v-if="!isViewer && viewMode !== 'recipes'" class="btn primary" @click="createActivity">+ Новая активность</button>
        <label v-if="!isViewer && viewMode === 'recipes'" class="btn primary" style="cursor:pointer;">
          <BkIcon name="import" size="sm" /> Импорт рецептур
          <input type="file" style="display:none;" accept=".xlsx,.xls" @change="importRecipes" />
        </label>
      </div>
    </div>

    <!-- Фильтры (только для активностей) -->
    <div v-if="viewMode !== 'recipes'" class="mkt-filters">
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
    <div v-if="loading && viewMode !== 'recipes'" style="text-align:center;padding:40px;"><BurgerSpinner text="Загрузка..." /></div>

    <!-- Пусто -->
    <div v-else-if="!filtered.length && viewMode !== 'recipes'" class="mkt-empty">
      <div class="mkt-empty-icon"><BkIcon name="marketing" size="lg" /></div>
      <div class="mkt-empty-text">{{ statusFilter || typeFilter ? 'Нет активностей с такими фильтрами' : 'Маркетинговых активностей пока нет' }}</div>
      <button v-if="!isViewer && !statusFilter && !typeFilter" class="btn primary" @click="createActivity" style="margin-top:12px;">Создать первую</button>
    </div>

    <!-- Гант -->
    <MarketingGantt v-else-if="viewMode === 'gantt'" :activities="filtered" @select="openActivity" />

    <!-- Список -->
    <div v-else-if="viewMode === 'list'" class="mkt-list">
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

    <!-- Рецептуры -->
    <template v-if="viewMode === 'recipes'">
      <div v-if="recipesLoading" style="text-align:center;padding:40px;"><BurgerSpinner text="Загрузка рецептур..." /></div>
      <template v-else>
        <div class="mkt-recipes-stats">
          <span><strong>{{ recipes.length }}</strong> блюд</span>
          <span>·</span>
          <span><strong>{{ recipesIngCount }}</strong> ингредиентов</span>
          <span v-if="recipeSearch" class="mkt-muted">· показано {{ filteredRecipes.length }}</span>
        </div>
        <div class="mkt-recipes-search">
          <input v-model="recipeSearch" class="mkt-input" placeholder="Поиск по блюдам и ингредиентам..." />
        </div>
        <div v-if="!filteredRecipes.length" class="mkt-empty">
          <div class="mkt-empty-text">{{ recipes.length ? 'Ничего не найдено' : 'Рецептуры ещё не загружены. Импортируйте файл.' }}</div>
        </div>
        <div v-else class="mkt-recipes-list">
          <div v-for="r in filteredRecipes" :key="r.id" class="mkt-recipe-card" :class="{ open: expandedRecipe === r.id }" @click="expandedRecipe = expandedRecipe === r.id ? null : r.id">
            <div class="mkt-recipe-header">
              <div class="mkt-recipe-name">
                <span v-if="r.code" class="mkt-recipe-code">{{ r.code }}</span>
                {{ r.name }}
              </div>
              <div class="mkt-recipe-meta">
                <span v-if="r.thk" class="mkt-recipe-thk">{{ r.thk }}</span>
                <span class="mkt-recipe-count">{{ (r.ingredients || []).length }} инг.</span>
                <BkIcon :name="expandedRecipe === r.id ? 'chevronUp' : 'chevronDown'" size="xs" />
              </div>
            </div>
            <div v-if="expandedRecipe === r.id" class="mkt-recipe-body" @click.stop>
              <table class="mkt-recipe-table">
                <thead>
                  <tr>
                    <th style="text-align:left;">Ингредиент</th>
                    <th>SKU</th>
                    <th>Брутто, г</th>
                    <th>Кол-во, шт</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="ing in r.ingredients" :key="ing.id">
                    <td style="text-align:left;">{{ ing.name }}</td>
                    <td><span v-if="ing.sku" style="color:var(--bk-orange);font-weight:700;font-size:11px;">{{ ing.sku }}</span></td>
                    <td>{{ ing.brutto > 0 ? parseFloat(ing.brutto).toLocaleString('ru-RU') : '—' }}</td>
                    <td>{{ ing.qty > 0 ? parseFloat(ing.qty).toLocaleString('ru-RU') : '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </template>
    </template>
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

// Recipes
const recipes = ref([]);
const recipesLoading = ref(false);
const recipeSearch = ref('');
const expandedRecipe = ref(null);
const recipesIngCount = computed(() => recipes.value.reduce((s, r) => s + (r.ingredients?.length || 0), 0));
const filteredRecipes = computed(() => {
  const q = recipeSearch.value.trim().toLowerCase();
  if (!q) return recipes.value;
  return recipes.value.filter(r => {
    if (r.name.toLowerCase().includes(q)) return true;
    if (r.code && r.code.includes(q)) return true;
    return (r.ingredients || []).some(i => i.name.toLowerCase().includes(q) || (i.sku && i.sku.includes(q)));
  });
});

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

async function loadRecipes() {
  if (recipes.value.length) return; // already loaded
  recipesLoading.value = true;
  try {
    const { data: recs } = await db.from('recipes').select('id, code, name, thk').order('name', { ascending: true });
    if (!recs?.length) { recipes.value = []; return; }
    // Load all ingredients in one query
    const { data: ings } = await db.from('recipe_ingredients').select('id, recipe_id, sku, name, brutto, qty').order('sort_order', { ascending: true });
    const ingMap = {};
    for (const i of (ings || [])) {
      if (!ingMap[i.recipe_id]) ingMap[i.recipe_id] = [];
      ingMap[i.recipe_id].push(i);
    }
    recipes.value = recs.map(r => ({ ...r, ingredients: ingMap[r.id] || [] }));
  } finally { recipesLoading.value = false; }
}

async function importRecipes(e) {
  const file = e.target.files?.[0];
  if (!file) return;
  try {
    const XLSX = (await import('xlsx-js-style')).default;
    const buf = await file.arrayBuffer();
    const wb = XLSX.read(buf, { type: 'array' });
    const ws = wb.Sheets[wb.SheetNames[0]];
    const range = XLSX.utils.decode_range(ws['!ref']);

    const parsed = [];
    let current = null;
    for (let r = 1; r <= range.e.r; r++) {
      const cellA = ws[XLSX.utils.encode_cell({ r, c: 0 })];
      const cellB = ws[XLSX.utils.encode_cell({ r, c: 1 })];
      const cellC = ws[XLSX.utils.encode_cell({ r, c: 2 })];
      const cellD = ws[XLSX.utils.encode_cell({ r, c: 3 })];
      if (!cellA?.v) continue;
      const name = String(cellA.v).trim();
      const hasRecipe = cellB?.v && String(cellB.v).includes('ТХК');
      if (hasRecipe) {
        const pm = name.match(/^(\d+)\.\s+(.+)/) || name.match(/^(\d+)\.([^\d\s].+)/) || name.match(/^(\d+\.\d+)\s+(.+)/);
        const code = pm ? pm[1] : null;
        const dishName = pm ? pm[2].trim() : name;
        current = { code, name: dishName, thk: String(cellB.v).trim(), brutto: cellC?.v || null, qty: cellD?.v || null, ingredients: [] };
        parsed.push(current);
      } else if (current) {
        const m = name.match(/^(\d+)\s+(.+)/);
        current.ingredients.push({ sku: m ? m[1] : null, name: m ? m[2].trim() : name.trim(), brutto: cellC?.v || null, qty: cellD?.v || null });
      }
    }

    if (!parsed.length) { toast.error('Не найдено блюд', 'Проверьте формат файла'); return; }
    const { data, error } = await db.rpc('import_recipes', { recipes: parsed });
    if (error) { toast.error('Ошибка импорта', error); return; }
    toast.success('Импортировано', `${data.imported} блюд`);
    recipes.value = []; // force reload
    loadRecipes();
  } catch (err) {
    console.error(err);
    toast.error('Ошибка', 'Не удалось обработать файл');
  } finally { e.target.value = ''; }
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

/* Recipes */
.mkt-recipes-stats { font-size: 13px; color: var(--text-muted); margin-bottom: 10px; display: flex; gap: 6px; align-items: center; }
.mkt-recipes-stats strong { color: var(--bk-brown, #502314); }
.mkt-recipes-search { margin-bottom: 12px; }
.mkt-input { width: 100%; max-width: 400px; padding: 8px 12px; border: 1.5px solid #E8E0D8; border-radius: 8px; font-size: 13px; font-family: inherit; background: #FAFAF8; color: var(--text); box-sizing: border-box; }
.mkt-input:focus { border-color: var(--bk-orange); outline: none; box-shadow: 0 0 0 3px rgba(214,35,0,0.08); }
.mkt-muted { color: var(--text-muted); }
.mkt-recipes-list { display: flex; flex-direction: column; gap: 6px; }
.mkt-recipe-card { background: white; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); cursor: pointer; transition: all 0.15s; overflow: hidden; }
.mkt-recipe-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.mkt-recipe-card.open { box-shadow: 0 2px 12px rgba(0,0,0,0.1); }
.mkt-recipe-header { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; gap: 12px; }
.mkt-recipe-name { font-weight: 600; font-size: 14px; color: var(--bk-brown, #502314); }
.mkt-recipe-code { color: var(--bk-orange); font-weight: 700; font-size: 12px; margin-right: 6px; }
.mkt-recipe-meta { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.mkt-recipe-thk { font-size: 10px; color: var(--text-muted); max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mkt-recipe-count { font-size: 11px; color: var(--text-muted); font-weight: 600; white-space: nowrap; }
.mkt-recipe-body { padding: 0 16px 14px; border-top: 1px solid #F5F0EB; }
.mkt-recipe-table { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 8px; }
.mkt-recipe-table th { font-size: 10px; text-transform: uppercase; letter-spacing: 0.4px; color: rgba(80,35,20,0.5); font-weight: 700; padding: 6px 8px; border-bottom: 2px solid #F5F0EB; text-align: center; }
.mkt-recipe-table td { padding: 5px 8px; border-bottom: 1px solid #F5F0EB; text-align: center; }
.mkt-recipe-table tbody tr:hover { background: #FFFBF5; }

@media (max-width: 480px) {
  .mkt-header { flex-direction: column; align-items: stretch; }
  .mkt-header .btn { text-align: center; }
  .mkt-filters { overflow-x: auto; flex-wrap: nowrap; gap: 4px; }
  .mkt-filters .db-sort-btn { flex-shrink: 0; font-size: 10px; padding: 4px 8px; }
  .mkt-card { flex-direction: column; align-items: stretch; gap: 8px; padding: 14px 16px; border-radius: 10px; }
  .mkt-card-days { text-align: left; padding-left: 0; }
}
</style>
