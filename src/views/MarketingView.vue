<template>
  <div class="mkt-view">
    <div class="mkt-header">
      <h1 class="page-title">Маркетинг</h1>
      <div style="display:flex;gap:8px;align-items:center;">
        <div class="mkt-view-toggle">
          <button class="db-sort-btn" :class="{ active: viewMode === 'list' }" @click="viewMode = 'list'">Список</button>
          <button class="db-sort-btn" :class="{ active: viewMode === 'gantt' }" @click="viewMode = 'gantt'">Гант</button>
          <button class="db-sort-btn" :class="{ active: viewMode === 'recipes' }" @click="viewMode = 'recipes'; loadRecipes()">Рецептуры</button>
          <button class="db-sort-btn" :class="{ active: viewMode === 'groups' }" @click="viewMode = 'groups'; loadGroups()">Группы</button>
        </div>
        <button v-if="!isViewer && viewMode !== 'recipes' && viewMode !== 'groups'" class="btn primary" @click="createActivity">+ Новая активность</button>
        <label v-if="!isViewer && viewMode === 'recipes'" class="btn primary" style="cursor:pointer;">
          <BkIcon name="import" size="sm" /> Импорт рецептур
          <input type="file" style="display:none;" accept=".xlsx,.xls" @change="importRecipes" />
        </label>
        <button v-if="!isViewer && viewMode === 'groups'" class="btn primary" @click="openGroupModal(null)">+ Новая группа</button>
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
    <MarketingGantt v-else-if="viewMode === 'gantt'" :activities="filtered.filter(a => a.date_from)" @select="openActivity" />

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

    <!-- Группы рецептур -->
    <template v-if="viewMode === 'groups'">
      <div v-if="groupsLoading" style="text-align:center;padding:40px;"><BurgerSpinner text="Загрузка..." /></div>
      <div v-else-if="!groups.length" class="mkt-empty">
        <div class="mkt-empty-text">Групп пока нет. Создайте первую — например «Соусы» или «Напитки 0,3 л».</div>
        <div class="mkt-empty-text mkt-muted" style="margin-top:6px;font-size:12px;">При импорте купонов «2 Соуса на выбор» автоматически подставятся рецептуры из группы.</div>
      </div>
      <div v-else class="mkt-groups-list">
        <div v-for="g in groups" :key="g.id" class="mkt-group-card">
          <div class="mkt-group-header" @click="openGroupModal(g)">
            <div>
              <strong>{{ g.name }}</strong>
              <span class="mkt-muted" style="margin-left:8px;">{{ g.recipe_count }} рецептур</span>
            </div>
            <div class="mkt-group-kw">
              <span v-for="k in (g.keywords || [])" :key="k" class="mkt-kw-tag">{{ k }}</span>
            </div>
          </div>
          <div class="mkt-group-recipes">
            <span v-for="r in g.recipes.slice(0, 10)" :key="r.id" class="mkt-group-recipe">{{ r.name }}</span>
            <span v-if="g.recipes.length > 10" class="mkt-muted">+{{ g.recipes.length - 10 }}</span>
          </div>
        </div>
      </div>
    </template>

    <!-- Модалка группы -->
    <Teleport to="body">
      <div v-if="groupModal.show" class="modal">
        <div class="modal-box" style="max-width:600px;">
          <h3 style="margin-bottom:12px;">{{ groupModal.id ? 'Редактировать группу' : 'Новая группа' }}</h3>
          <div class="mkt-field">
            <label>Название</label>
            <input v-model="groupModal.name" class="mkt-input" placeholder="Например: Соусы" />
          </div>
          <div class="mkt-field" style="margin-top:8px;">
            <label>Ключевые слова <span class="mkt-muted">(по ним группа находится при импорте)</span></label>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px;">
              <span v-for="(k, ki) in groupModal.keywords" :key="ki" class="mkt-kw-tag" style="cursor:pointer;" @click="groupModal.keywords.splice(ki, 1)">{{ k }} ×</span>
            </div>
            <div style="display:flex;gap:6px;">
              <input v-model="groupModal.newKeyword" class="mkt-input" placeholder="Соус, Соуса, Соусы..." @keydown.enter.prevent="addKeyword" style="flex:1;" />
              <button class="btn" @click="addKeyword">Добавить</button>
            </div>
          </div>
          <div class="mkt-field" style="margin-top:12px;">
            <label>Рецептуры ({{ groupModal.selectedRecipes.length }})</label>
            <input v-model="groupModal.recipeSearch" class="mkt-input" placeholder="Поиск рецептуры..." style="margin-bottom:6px;" />
            <div class="mkt-group-recipe-list">
              <label v-for="r in groupRecipeOptions" :key="r.id" class="mkt-group-recipe-option">
                <input type="checkbox" :checked="groupModal.selectedRecipes.includes(r.id)" @change="toggleGroupRecipe(r.id)" />
                <span class="mkt-recipe-code" v-if="r.code" style="margin-right:4px;">{{ r.code }}</span>
                {{ r.name }}
              </label>
            </div>
          </div>
          <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <button v-if="groupModal.id" class="btn" style="color:var(--error);margin-right:auto;" @click="deleteGroup">Удалить</button>
            <button class="btn" @click="groupModal.show = false">Отмена</button>
            <button class="btn primary" @click="saveGroup" :disabled="!groupModal.name.trim()">Сохранить</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, defineAsyncComponent, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { db } from '@/lib/apiClient.js';
import { formatDate, applyEntityFilter } from '@/lib/utils.js';
import { getEntityGroupCode } from '@/lib/legalEntities.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';

const MarketingGantt = defineAsyncComponent(() => import('@/components/marketing/MarketingGantt.vue'));

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
    const groupCode = getEntityGroupCode(legalEntity.value);
    const { data: recs } = await db.from('recipes')
      .select('id, code, name, thk, legal_entity_group')
      .eq('legal_entity_group', groupCode)
      .order('name', { ascending: true });
    if (!recs?.length) { recipes.value = []; return; }
    // Load all ingredients in one query, фильтруем только по этим рецептам
    const recipeIdSet = new Set(recs.map(r => r.id));
    const { data: ings } = await db.from('recipe_ingredients').select('id, recipe_id, sku, name, brutto, qty').order('sort_order', { ascending: true }).limit(10000);
    const ingMap = {};
    for (const i of (ings || [])) {
      if (!recipeIdSet.has(i.recipe_id)) continue;
      if (!ingMap[i.recipe_id]) ingMap[i.recipe_id] = [];
      ingMap[i.recipe_id].push(i);
    }
    recipes.value = recs.map(r => ({ ...r, ingredients: ingMap[r.id] || [] }));
  } finally { recipesLoading.value = false; }
}

// ═══ Группы рецептур ═══
const groups = ref([]);
const groupsLoading = ref(false);
const groupModal = ref({ show: false, id: null, name: '', keywords: [], newKeyword: '', selectedRecipes: [], recipeSearch: '' });

async function loadGroups() {
  groupsLoading.value = true;
  try {
    const { data } = await db.rpc('get_recipe_groups_list', { legal_entity: legalEntity.value });
    groups.value = data || [];
  } finally { groupsLoading.value = false; }
}

function openGroupModal(g) {
  if (!recipes.value.length) loadRecipes();
  if (g) {
    groupModal.value = { show: true, id: g.id, name: g.name, keywords: [...(g.keywords || [])], newKeyword: '', selectedRecipes: g.recipes.map(r => r.id), recipeSearch: '' };
  } else {
    groupModal.value = { show: true, id: null, name: '', keywords: [], newKeyword: '', selectedRecipes: [], recipeSearch: '' };
  }
}

function addKeyword() {
  const kw = groupModal.value.newKeyword.trim();
  if (kw && !groupModal.value.keywords.includes(kw)) groupModal.value.keywords.push(kw);
  groupModal.value.newKeyword = '';
}

function toggleGroupRecipe(id) {
  const idx = groupModal.value.selectedRecipes.indexOf(id);
  if (idx >= 0) groupModal.value.selectedRecipes.splice(idx, 1);
  else groupModal.value.selectedRecipes.push(id);
}

const groupRecipeOptions = computed(() => {
  const q = groupModal.value.recipeSearch.toLowerCase().trim();
  let list = recipes.value;
  if (q) list = list.filter(r => r.name.toLowerCase().includes(q) || (r.code && r.code.includes(q)));
  // Выбранные сверху
  const sel = new Set(groupModal.value.selectedRecipes);
  return [...list].sort((a, b) => (sel.has(b.id) ? 1 : 0) - (sel.has(a.id) ? 1 : 0) || a.name.localeCompare(b.name));
});

async function saveGroup() {
  const { id, name, keywords, selectedRecipes } = groupModal.value;
  try {
    const { error } = await db.rpc('save_recipe_group', { id, name: name.trim(), keywords, recipe_ids: selectedRecipes, legal_entity: legalEntity.value });
    if (error) throw error;
    groupModal.value.show = false;
    toast.success('Сохранено');
    await loadGroups();
  } catch { toast.error('Ошибка сохранения'); }
}

async function deleteGroup() {
  if (!confirm('Удалить группу?')) return;
  try {
    await db.rpc('delete_recipe_group', { id: groupModal.value.id });
    groupModal.value.show = false;
    toast.success('Удалено');
    await loadGroups();
  } catch { toast.error('Ошибка удаления'); }
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
      const hasRecipe = cellB?.v && /ТХК|ТК\s|ТК\s*№/.test(String(cellB.v));
      if (hasRecipe) {
        const pm = name.match(/^(\d+)\.\s+(.+)/) || name.match(/^(\d+)\.([^\d\s].+)/) || name.match(/^(\d+\.\d+)\s+(.+)/);
        const code = pm ? pm[1] : null;
        const dishName = pm ? pm[2].trim() : name;
        current = { code, name: dishName, thk: String(cellB.v).trim(), brutto: cellC?.v || null, qty: cellD?.v || null, ingredients: [] };
        parsed.push(current);
      } else if (current) {
        const m = name.match(/^(\d[\dA-Za-z_-]*)\s+(.+)/) || name.match(/^([A-Za-z][\dA-Za-z_-]*\d[\dA-Za-z_-]*)\s+(.+)/);
        current.ingredients.push({ sku: m ? m[1] : null, name: m ? m[2].trim() : name.trim(), brutto: cellC?.v || null, qty: cellD?.v || null });
      }
    }

    if (!parsed.length) { toast.error('Не найдено блюд', 'Проверьте формат файла'); return; }
    if (!confirm(`Заменить все рецептуры юрлица «${legalEntity.value}» на импортируемые (${parsed.length} блюд)? Рецептуры других юрлиц не пострадают.`)) return;
    const { data, error } = await db.rpc('import_recipes', { recipes: parsed, legal_entity: legalEntity.value });
    if (error) { toast.error('Ошибка импорта', error); return; }
    toast.success('Импортировано', `${data.imported} блюд`);
    recipes.value = []; // force reload
    loadRecipes();
  } catch (err) {
    console.error(err);
    toast.error('Ошибка', 'Не удалось обработать файл');
  } finally { e.target.value = ''; }
}

async function importCoupons(e) {
  const file = e.target.files?.[0];
  if (!file) return;
  try {
    const XLSX = (await import('xlsx-js-style')).default;
    const buf = await file.arrayBuffer();
    const wb = XLSX.read(buf, { type: 'array' });
    const ws = wb.Sheets[wb.SheetNames[0]];
    const data = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });
    if (data.length < 3) { toast.error('Пустой файл', ''); return; }

    // Строка 1: период
    const periodRaw = String(data[0][1] || '').replace(/ПЕРИОД:\s*/i, '').trim();
    const actName = 'Купоны ' + periodRaw;

    // Парсим даты из периода (формат DD.MM-DD.MM или DD.MM.YY-DD.MM.YY)
    let dateFrom = '', dateTo = '';
    const pm = periodRaw.match(/(\d{2}\.\d{2}(?:\.\d{2,4})?)\s*[-–]\s*(\d{2}\.\d{2}(?:\.\d{2,4})?)/);
    if (pm) {
      const parseD = (s) => { const p = s.split('.'); const y = p[2] ? (p[2].length === 2 ? '20' + p[2] : p[2]) : new Date().getFullYear(); return `${y}-${p[1]}-${p[0]}`; };
      dateFrom = parseD(pm[1]);
      dateTo = parseD(pm[2]);
    }

    // Строки 3+: купоны
    const items = [];
    for (let i = 2; i < data.length; i++) {
      const row = data[i];
      const couponId = String(row[0] || '').trim();
      const composition = String(row[1] || '').trim();
      const auv = parseFloat(row[2]) || 0;
      if (!composition) continue;

      // Парсим состав: разделяем по запятым, обрабатываем множители
      const parts = composition.split(/,\s*/);
      const subItems = [];
      for (let part of parts) {
        part = part.replace(/\(.*?\)/g, '').trim(); // убираем (лимит) и т.п.
        if (!part) continue;
        const isChoice = part.toLowerCase().includes('на выбор');
        const cleanPart = part.replace(/на выбор/gi, '').trim();
        if (!cleanPart) continue;
        // Множитель: "2 Кинг Фри малый" или "3 Соуса"
        const qm = cleanPart.match(/^(\d+)\s+(.+)/);
        const qty = qm ? parseInt(qm[1]) : 1;
        const dishName = qm ? qm[2].trim() : cleanPart.trim();
        // Нормализация сокращений
        const normalized = dishName.replace(/мал\.$/, 'малый').replace(/газ\.\s*/, 'газ. ');
        subItems.push({ recipe_id: null, name: normalized, code: '', share: 0, qty, _choice: isChoice });
      }

      // Доли: пропорционально кол-ву (каждое блюдо = qty порций)
      const totalQty = subItems.reduce((s, si) => s + si.qty, 0);
      subItems.forEach(si => { si.share = totalQty > 0 ? Math.round(si.qty / totalQty * 10000) / 10000 : 0; });

      const label = couponId ? `${couponId}: ${composition}` : composition;
      items.push({
        product_id: null, sku: couponId || null, name: label,
        calc_method: 'category', auv, auv_periods: null, sub_items: subItems,
        total_volume: null, fixed_qty: null, unit: 'шт', note: '',
      });
    }

    if (!items.length) { toast.error('Не найдено купонов', ''); return; }

    // Привязка к рецептурам
    const allDishNames = [...new Set(items.flatMap(it => (it.sub_items || []).map(s => s.name)))];
    if (allDishNames.length) {
      const { data: recipeData } = await db.rpc('find_recipes_by_names', { names: allDishNames });
      const recipeMap = recipeData?.recipes || {};
      for (const item of items) {
        for (const sub of (item.sub_items || [])) {
          const found = recipeMap[sub.name];
          if (found) { sub.recipe_id = found.id; sub.code = found.code; sub.name = found.name; }
        }
      }
    }

    // Очистка служебных полей перед сохранением
    for (const item of items) {
      for (const sub of (item.sub_items || [])) { delete sub._choice; }
    }

    // Создаём активность
    const { data: result, error } = await db.rpc('save_marketing_activity', {
      name: actName,
      legal_entity: legalEntity.value,
      type: 'coupon',
      status: 'active',
      date_from: dateFrom || null,
      date_to: dateTo || null,
      items,
    });
    if (error) { toast.error('Ошибка', error); return; }
    toast.success('Купоны импортированы', `${items.length} купонов`);
    router.push({ name: 'marketing-detail', params: { id: result.id } });
  } catch (err) {
    console.error(err);
    toast.error('Ошибка', 'Не удалось обработать файл');
  } finally { e.target.value = ''; }
}

onMounted(() => { loadActivities(); });
watch(legalEntity, () => {
  activities.value = [];
  recipes.value = [];
  groups.value = [];
  loadActivities();
  if (viewMode.value === 'recipes') loadRecipes();
  if (viewMode.value === 'groups') loadGroups();
});
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
.db-sort-btn.active { border-color: #E76F51; color: var(--bk-brown); background: #FFFBF5; }

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
.mkt-input:focus { border-color: var(--bk-orange); outline: none; box-shadow: 0 0 0 3px rgba(231,111,81,0.08); }
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
.mkt-recipe-table th { font-size: 10px; text-transform: uppercase; letter-spacing: 0.4px; color: var(--bk-brown, #502314); font-weight: 700; padding: 8px 8px; border-bottom: 2px solid var(--bk-orange, #E76F51); text-align: center; background: #FFF8F0; }
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

/* Группы рецептур */
.mkt-groups-list { display: flex; flex-direction: column; gap: 10px; }
.mkt-group-card { background: var(--card); border: 1px solid var(--border-light); border-radius: 10px; padding: 14px 18px; }
.mkt-group-header { display: flex; justify-content: space-between; align-items: center; cursor: pointer; gap: 12px; }
.mkt-group-header:hover { color: var(--bk-red); }
.mkt-group-kw { display: flex; gap: 4px; flex-wrap: wrap; }
.mkt-kw-tag { display: inline-block; padding: 2px 8px; background: rgba(244,162,97,.1); color: var(--bk-orange); border-radius: 4px; font-size: 11px; font-weight: 600; }
.mkt-group-recipes { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
.mkt-group-recipe { font-size: 12px; padding: 2px 8px; background: var(--bg); border-radius: 4px; color: var(--text-muted); }
.mkt-field { display: flex; flex-direction: column; gap: 4px; }
.mkt-field label { font-size: 12px; font-weight: 600; color: var(--text-muted); }
.mkt-group-recipe-list { max-height: 280px; overflow-y: auto; border: 1px solid var(--border-light); border-radius: 8px; padding: 6px; }
.mkt-group-recipe-option { display: flex; align-items: center; gap: 8px; font-size: 13px; padding: 5px 8px; border-radius: 6px; cursor: pointer; line-height: 1.3; }
.mkt-group-recipe-option:hover { background: rgba(244,162,97,.06); }
.mkt-group-recipe-option input[type=checkbox] { flex: 0 0 14px; width: 14px; height: 14px; min-width: 14px; max-width: 14px; margin: 0; accent-color: var(--bk-red); cursor: pointer; -webkit-appearance: checkbox; appearance: checkbox; }
</style>
