<template>
  <div class="mktd-view">
    <!-- Header -->
    <div class="mktd-top">
      <router-link :to="{ name: 'marketing' }" class="mktd-back"><BkIcon name="chevronLeft" size="sm" /> Маркетинг</router-link>
      <div class="mktd-top-right">
        <button v-if="!isViewer" class="btn primary" @click="save" :disabled="saving">{{ saving ? 'Сохранение...' : 'Сохранить' }}</button>
        <button v-if="!isViewer && activity.id" class="btn danger small" @click="confirmDelete">Удалить</button>
      </div>
    </div>

    <div v-if="loading" style="text-align:center;padding:40px;"><BurgerSpinner text="Загрузка..." /></div>

    <template v-else>
      <!-- Основное -->
      <div class="mktd-card">
        <div class="mktd-card-title">Основное</div>
        <div class="mktd-form">
          <div class="mktd-row">
            <div class="mktd-field" style="flex:2;">
              <label>Название</label>
              <input v-model="activity.name" :disabled="isViewer" class="mktd-input" placeholder="Название активности" />
            </div>
            <div class="mktd-field">
              <label>Тип</label>
              <select v-model="activity.type" :disabled="isViewer" class="mktd-input">
                <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
              </select>
            </div>
            <div class="mktd-field">
              <label>Статус</label>
              <select v-model="activity.status" :disabled="isViewer" class="mktd-input">
                <option value="active">Активная</option>
                <option value="completed">Завершённая</option>
              </select>
            </div>
          </div>
          <div class="mktd-row">
            <div class="mktd-field">
              <label>Дата начала</label>
              <input type="date" v-model="activity.date_from" :disabled="isViewer" class="mktd-input" />
            </div>
            <div class="mktd-field">
              <label>Дата окончания</label>
              <input type="date" v-model="activity.date_to" :disabled="isViewer" class="mktd-input" />
            </div>
            <div class="mktd-field">
              <label>Кол-во ресторанов</label>
              <input type="number" v-model.number="activity.restaurant_count" :disabled="isViewer" class="mktd-input" placeholder="0" min="1" />
            </div>
            <div class="mktd-field" v-if="activityDays">
              <label>Длительность</label>
              <div class="mktd-info">{{ activityDays }} дн</div>
            </div>
          </div>
          <div class="mktd-row">
            <div class="mktd-field" style="flex:1;">
              <label>Заметки</label>
              <textarea v-model="activity.note" :disabled="isViewer" class="mktd-input mktd-textarea" placeholder="Комментарии, ссылки..." rows="2"></textarea>
            </div>
          </div>
        </div>
      </div>

      <!-- Блюда / Ингредиенты -->
      <div class="mktd-card">
        <div class="mktd-card-title" style="justify-content:space-between;">
          <div style="display:flex;align-items:center;gap:8px;">
            <div class="mktd-tabs">
              <button class="mktd-tab" :class="{ active: itemsTab === 'dishes' }" @click="itemsTab = 'dishes'">Блюда <span v-if="activity.items.length" class="mktd-card-count">{{ activity.items.length }}</span></button>
              <button class="mktd-tab" :class="{ active: itemsTab === 'ingredients' }" @click="itemsTab = 'ingredients'; loadIngredients()">Ингредиенты <span v-if="ingredientsList.length" class="mktd-card-count">{{ ingredientsList.length }}</span></button>
            </div>
          </div>
          <div v-if="itemsTab === 'dishes'" style="display:flex;gap:6px;">
            <button v-if="!isViewer" class="btn small" @click="addItem">+ Блюдо</button>
          </div>
        </div>

        <!-- Таб: Блюда -->
        <div v-if="itemsTab === 'dishes'" class="mktd-items-wrap">
          <table class="mktd-items-table" v-if="activity.items.length">
            <thead>
              <tr>
                <th style="min-width:200px;text-align:left;">Блюдо</th>
                <th style="width:120px;">Метод расчёта</th>
                <th style="width:100px;">Значение</th>
                <th style="width:60px;">Ед.</th>
                <th style="width:110px;">Итого порций</th>
                <th style="width:140px;">Заметка</th>
                <th style="width:36px;" v-if="!isViewer"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(item, ii) in activity.items" :key="ii">
                <td style="text-align:left;position:relative;">
                  <input class="mktd-input mktd-item-name" v-model="item.name" :disabled="isViewer"
                    placeholder="Поиск блюда..."
                    @input="onItemSearch(ii, $event.target.value)"
                    @focus="onItemSearch(ii, item.name)"
                    @blur="closeSearch(ii)"
                    :ref="el => setItemRef(el, ii)" />
                  <span v-if="item.sku" class="mktd-item-sku">{{ item.sku }}</span>
                </td>
                <td>
                  <select v-model="item.calc_method" :disabled="isViewer" class="mktd-input mktd-input-sm">
                    <option value="auv">AUV</option>
                    <option value="total_volume">Общий объём</option>
                    <option value="fixed_qty">Фикс. кол-во</option>
                  </select>
                </td>
                <td>
                  <input v-if="item.calc_method === 'auv'" type="number" v-model.number="item.auv" :disabled="isViewer" class="mktd-input mktd-input-sm" placeholder="шт/рест/день" step="0.01" />
                  <input v-else-if="item.calc_method === 'total_volume'" type="number" v-model.number="item.total_volume" :disabled="isViewer" class="mktd-input mktd-input-sm" placeholder="Объём" />
                  <input v-else type="number" v-model.number="item.fixed_qty" :disabled="isViewer" class="mktd-input mktd-input-sm" placeholder="Кол-во" />
                </td>
                <td>
                  <select v-model="item.unit" :disabled="isViewer" class="mktd-input mktd-input-sm">
                    <option value="шт">шт</option>
                    <option value="кг">кг</option>
                    <option value="л">л</option>
                    <option value="кор">кор</option>
                    <option value="уп">уп</option>
                  </select>
                </td>
                <td class="mktd-total-cell">
                  <template v-if="itemTotal(item) > 0">
                    <strong>{{ formatNum(itemTotal(item)) }}</strong> {{ item.unit }}
                  </template>
                  <span v-else class="mktd-muted">—</span>
                </td>
                <td>
                  <input v-model="item.note" :disabled="isViewer" class="mktd-input mktd-input-sm" placeholder="" />
                </td>
                <td v-if="!isViewer">
                  <button class="mktd-remove-btn" @click="removeItem(ii)" title="Удалить"><BkIcon name="close" size="xs" /></button>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-if="!activity.items.length" class="mktd-muted" style="padding:16px 0;text-align:center;font-size:13px;">Добавьте блюда, по которым маркетинг планирует активность</div>
        </div>

        <!-- Таб: Ингредиенты (автоматический расклад) -->
        <div v-if="itemsTab === 'ingredients'">
          <div v-if="ingredientsLoading" style="text-align:center;padding:20px;"><BurgerSpinner text="Загрузка рецептур..." /></div>
          <div v-else-if="!activity.items.length" class="mktd-muted" style="padding:16px 0;text-align:center;font-size:13px;">Сначала добавьте блюда во вкладке «Блюда»</div>
          <div v-else-if="!ingredientsList.length" class="mktd-muted" style="padding:16px 0;text-align:center;font-size:13px;">Рецептуры не найдены. Импортируйте справочник рецептур.</div>
          <div v-else class="mktd-items-wrap">
            <div class="mktd-ing-info">
              Расклад по рецептурам для {{ matchedDishes }} из {{ activity.items.length }} блюд
              <span v-if="unmatchedDishes.length" class="mktd-ing-warn">· Не найдены: {{ unmatchedDishes.join(', ') }}</span>
            </div>
            <table class="mktd-items-table">
              <thead>
                <tr>
                  <th style="text-align:left;">Ингредиент</th>
                  <th style="width:80px;">SKU</th>
                  <th style="width:120px;">Итого, г</th>
                  <th style="width:120px;">Итого, кг</th>
                  <th style="width:120px;">Итого, шт</th>
                  <th style="width:180px;">Из блюд</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="ing in ingredientsList" :key="ing.sku || ing.name">
                  <td style="text-align:left;font-weight:500;">{{ ing.name }}</td>
                  <td><span v-if="ing.sku" class="mktd-item-sku" style="position:static;">{{ ing.sku }}</span></td>
                  <td class="mktd-total-cell">{{ ing.totalGrams > 0 ? formatNum(ing.totalGrams) : '—' }}</td>
                  <td class="mktd-total-cell">{{ ing.totalGrams > 0 ? formatNum(ing.totalGrams / 1000) : '—' }}</td>
                  <td class="mktd-total-cell">{{ ing.totalQty > 0 ? formatNum(ing.totalQty) : '—' }}</td>
                  <td style="font-size:11px;color:var(--text-muted);">{{ ing.fromDishes.join(', ') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Файлы -->
      <div class="mktd-card">
        <div class="mktd-card-title">
          Файлы
          <span v-if="activity.files.length" class="mktd-card-count">{{ activity.files.length }}</span>
        </div>
        <div class="mktd-files">
          <div v-for="f in activity.files" :key="f.id" class="mktd-file">
            <a :href="fileUrl(f)" target="_blank" class="mktd-file-name"><BkIcon name="export" size="xs" /> {{ f.file_name }}</a>
            <button v-if="!isViewer" class="mktd-remove-btn" @click="deleteFile(f)" title="Удалить"><BkIcon name="close" size="xs" /></button>
          </div>
          <div v-if="!activity.files.length" class="mktd-muted" style="font-size:12px;">Нет вложений</div>
          <div v-if="!isViewer && activity.id" style="margin-top:8px;">
            <label class="btn small">
              <BkIcon name="import" size="sm" /> Загрузить файл
              <input type="file" style="display:none;" @change="uploadFile" accept=".pdf,.jpg,.jpeg,.png,.webp,.xlsx,.xls,.docx,.doc" />
            </label>
            <span v-if="uploading" style="font-size:11px;color:var(--text-muted);margin-left:8px;">Загрузка...</span>
          </div>
        </div>
      </div>
    </template>

    <!-- Product search dropdown -->
    <Teleport to="body">
      <div v-if="search.index >= 0 && search.results.length" class="mktd-dropdown" :style="dropdownStyle" @mousedown.prevent>
        <div v-for="pr in search.results" :key="pr.id" class="mktd-dropdown-item" @mousedown.prevent="pickProduct(ii, pr)">
          <span class="mktd-dropdown-sku">{{ pr.sku }}</span> {{ pr.name }}
        </div>
      </div>
    </Teleport>

    <ConfirmModal v-if="confirmModal.show" :title="confirmModal.title" :message="confirmModal.message" @confirm="onConfirm" @cancel="onCancel" />
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { db } from '@/lib/apiClient.js';
import { applyEntityFilter } from '@/lib/utils.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useUserStore } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';

const route = useRoute();
const router = useRouter();
const orderStore = useOrderStore();
const userStore = useUserStore();
const toast = useToastStore();

const isViewer = computed(() => !userStore.hasAccess('marketing', 'edit'));
const legalEntity = computed(() => orderStore.settings.legalEntity);

const loading = ref(false);
const saving = ref(false);
const uploading = ref(false);
const itemsTab = ref('dishes');
const ingredientsLoading = ref(false);
const ingredientsData = ref([]); // raw recipe data from API

const types = [
  { value: 'promo', label: 'Промо' },
  { value: 'new_product', label: 'Новинка' },
  { value: 'discontinue', label: 'Вывод из меню' },
  { value: 'seasonal', label: 'Сезонное меню' },
  { value: 'coupon', label: 'Купон' },
];

const activity = ref({
  id: null, name: '', type: 'promo', status: 'active',
  date_from: '', date_to: '', restaurant_count: null,
  legal_entity: '', note: '',
  items: [], files: [],
});

const activityDays = computed(() => {
  if (!activity.value.date_from || !activity.value.date_to) return 0;
  const from = new Date(activity.value.date_from + 'T00:00:00');
  const to = new Date(activity.value.date_to + 'T00:00:00');
  return Math.max(Math.round((to - from) / 86400000) + 1, 0);
});

function itemTotal(item) {
  const days = activityDays.value;
  const rests = activity.value.restaurant_count || 0;
  if (item.calc_method === 'auv') return (item.auv || 0) * rests * days;
  if (item.calc_method === 'total_volume') return item.total_volume || 0;
  return item.fixed_qty || 0;
}

const grandTotal = computed(() => activity.value.items.reduce((s, i) => s + itemTotal(i), 0));

function formatNum(v) {
  if (!v) return '—';
  return Number(v).toLocaleString('ru-RU', { maximumFractionDigits: 2 });
}

// ─── Ингредиенты (расклад по рецептурам) ────────────────────────────────────
const matchedDishes = computed(() => {
  const names = activity.value.items.map(i => i.name);
  return ingredientsData.value.filter(r => names.includes(r.name)).length;
});
const unmatchedDishes = computed(() => {
  const matched = new Set(ingredientsData.value.map(r => r.name));
  return activity.value.items.map(i => i.name).filter(n => n && !matched.has(n));
});

const ingredientsList = computed(() => {
  const map = {}; // key = sku||name → { name, sku, totalGrams, totalQty, fromDishes }
  const recipeMap = {};
  for (const r of ingredientsData.value) recipeMap[r.name] = r;

  for (const dish of activity.value.items) {
    const recipe = recipeMap[dish.name];
    if (!recipe || !recipe.ingredients) continue;
    const portions = itemTotal(dish); // total portions for this dish
    if (portions <= 0) continue;

    for (const ing of recipe.ingredients) {
      const key = ing.sku || ing.name;
      if (!map[key]) map[key] = { name: ing.name, sku: ing.sku, totalGrams: 0, totalQty: 0, fromDishes: [] };
      if (ing.brutto) map[key].totalGrams += parseFloat(ing.brutto) * portions;
      if (ing.qty) map[key].totalQty += parseFloat(ing.qty) * portions;
      if (!map[key].fromDishes.includes(dish.name)) map[key].fromDishes.push(dish.name);
    }
  }

  return Object.values(map).sort((a, b) => (b.totalGrams + b.totalQty) - (a.totalGrams + a.totalQty));
});

async function loadIngredients() {
  const names = activity.value.items.map(i => i.name).filter(Boolean);
  if (!names.length) return;
  // Only reload if dish names changed
  const cached = ingredientsData.value.map(r => r.name).sort().join(',');
  if (cached === names.sort().join(',') && ingredientsData.value.length) return;
  ingredientsLoading.value = true;
  try {
    const { data, error } = await db.rpc('get_recipe_ingredients', { dish_names: names });
    if (error) { toast.error('Ошибка', error); return; }
    ingredientsData.value = data?.recipes || [];
  } finally { ingredientsLoading.value = false; }
}

// ─── Items ──────────────────────────────────────────────────────────────────
function addItem() {
  activity.value.items.push({
    product_id: null, sku: null, name: '',
    calc_method: 'auv', auv: null, total_volume: null, fixed_qty: null,
    unit: 'шт', note: '',
  });
}
function removeItem(ii) { activity.value.items.splice(ii, 1); }

// ─── Product search ─────────────────────────────────────────────────────────
const search = reactive({ index: -1, results: [], timer: null });
const itemInputRefs = {};
function setItemRef(el, i) { if (el) itemInputRefs[i] = el; }

const dropdownStyle = computed(() => {
  const el = itemInputRefs[search.index];
  if (!el) return { display: 'none' };
  const rect = el.getBoundingClientRect();
  return { position: 'fixed', top: rect.bottom + 'px', left: rect.left + 'px', width: Math.max(rect.width, 280) + 'px', zIndex: 99999 };
});

function onItemSearch(ii, val) {
  search.index = ii;
  clearTimeout(search.timer);
  const q = (val || '').trim();
  if (q.length < 2) { search.results = []; return; }
  search.timer = setTimeout(async () => {
    // Search in recipes first, then products
    const { data: recipes } = await db.from('recipes').select('id, code, name').or(`code.ilike.%${q}%,name.ilike.%${q}%`).limit(10);
    if (search.index === ii) search.results = (recipes || []).map(r => ({ id: r.id, sku: r.code, name: r.name, _type: 'recipe' }));
  }, 250);
}

function pickProduct(ii, pr) {
  const item = activity.value.items[search.index];
  if (!item) return;
  item.product_id = pr.id;
  item.sku = pr.sku;
  item.name = pr.name;
  if (pr.unit_of_measure) item.unit = pr.unit_of_measure;
  search.results = [];
  search.index = -1;
}

function closeSearch() {
  setTimeout(() => { search.results = []; search.index = -1; }, 200);
}

// ─── Files ──────────────────────────────────────────────────────────────────
const API_BASE = import.meta.env.VITE_API_BASE || '/api';

function fileUrl(f) {
  const token = localStorage.getItem('bk_session_token') || '';
  return `${API_BASE}/uploads/marketing/${f.file_path}?download=1&token=${token}`;
}

async function uploadFile(e) {
  const file = e.target.files?.[0];
  if (!file || !activity.value.id) return;
  uploading.value = true;
  try {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('activity_id', activity.value.id);
    const token = localStorage.getItem('bk_session_token') || '';
    const res = await fetch(`${API_BASE}/upload/marketing-file`, {
      method: 'POST', body: fd, headers: { 'X-Session-Token': token },
    });
    const data = await res.json();
    if (data.error) { toast.error('Ошибка', data.error); return; }
    activity.value.files.push({ id: data.id, file_name: data.file_name, file_path: data.file_path });
    toast.success('Файл загружен', data.file_name);
  } catch { toast.error('Ошибка загрузки', ''); }
  finally { uploading.value = false; e.target.value = ''; }
}

async function deleteFile(f) {
  const token = localStorage.getItem('bk_session_token') || '';
  const res = await fetch(`${API_BASE}/upload/marketing-file?file_id=${f.id}`, {
    method: 'DELETE', headers: { 'X-Session-Token': token },
  });
  const data = await res.json();
  if (data.success) {
    activity.value.files = activity.value.files.filter(x => x.id !== f.id);
    toast.info('Файл удалён', '');
  }
}

// ─── Save / Load / Delete ───────────────────────────────────────────────────
async function save() {
  if (!activity.value.name.trim()) { toast.error('Укажите название', ''); return; }
  saving.value = true;
  try {
    const payload = {
      id: activity.value.id || undefined,
      name: activity.value.name,
      type: activity.value.type,
      status: activity.value.status,
      date_from: activity.value.date_from || null,
      date_to: activity.value.date_to || null,
      legal_entity: activity.value.legal_entity || legalEntity.value,
      restaurant_count: activity.value.restaurant_count || null,
      note: activity.value.note || null,
      items: activity.value.items.map((it, i) => ({
        product_id: it.product_id, sku: it.sku, name: it.name,
        calc_method: it.calc_method, auv: it.auv, total_volume: it.total_volume,
        fixed_qty: it.fixed_qty, unit: it.unit, note: it.note,
      })),
    };
    const { data, error } = await db.rpc('save_marketing_activity', payload);
    if (error) { toast.error('Ошибка', error); return; }
    if (!activity.value.id && data.id) {
      activity.value.id = data.id;
      activity.value.legal_entity = legalEntity.value;
      router.replace({ name: 'marketing-detail', params: { id: data.id } });
    }
    toast.success('Сохранено', '');
  } finally { saving.value = false; }
}

async function loadActivity(id) {
  loading.value = true;
  try {
    const { data, error } = await db.rpc('get_marketing_activity', { id: parseInt(id) });
    if (error || !data) { toast.error('Ошибка', error || 'Не найдена'); router.push({ name: 'marketing' }); return; }
    activity.value = {
      id: data.id, name: data.name, type: data.type, status: data.status,
      date_from: data.date_from || '', date_to: data.date_to || '',
      legal_entity: data.legal_entity, restaurant_count: data.restaurant_count,
      note: data.note || '',
      items: (data.items || []).map(it => ({
        product_id: it.product_id, sku: it.sku, name: it.name,
        calc_method: it.calc_method || 'auv',
        auv: it.auv ? parseFloat(it.auv) : null,
        total_volume: it.total_volume ? parseFloat(it.total_volume) : null,
        fixed_qty: it.fixed_qty ? parseFloat(it.fixed_qty) : null,
        unit: it.unit || 'шт', note: it.note || '',
      })),
      files: data.files || [],
    };
  } finally { loading.value = false; }
}

// ─── Confirm modal ──────────────────────────────────────────────────────────
const confirmModal = reactive({ show: false, title: '', message: '', action: null });
function confirmDelete() {
  confirmModal.show = true;
  confirmModal.title = 'Удалить активность?';
  confirmModal.message = `«${activity.value.name}» будет удалена вместе с файлами.`;
  confirmModal.action = 'delete';
}
async function onConfirm() {
  confirmModal.show = false;
  if (confirmModal.action === 'delete') {
    const { error } = await db.rpc('delete_marketing_activity', { id: activity.value.id });
    if (error) { toast.error('Ошибка', error); return; }
    toast.info('Удалено', '');
    router.push({ name: 'marketing' });
  }
}
function onCancel() { confirmModal.show = false; }

// ─── Mount ──────────────────────────────────────────────────────────────────
onMounted(() => {
  const id = route.params.id;
  if (id) {
    loadActivity(id);
  } else {
    activity.value.legal_entity = legalEntity.value;
  }
});
</script>

<style scoped>
.mktd-view { padding: 0; max-width: 960px; }
.mktd-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
.mktd-back { font-size: 13px; color: var(--text-muted); text-decoration: none; display: flex; align-items: center; gap: 4px; font-weight: 500; }
.mktd-back:hover { color: var(--bk-brown); }
.mktd-top-right { display: flex; gap: 8px; }

/* Cards */
.mktd-card { background: white; border-radius: 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); padding: 20px 24px; margin-bottom: 16px; }
.mktd-card-title { font-weight: 700; font-size: 15px; color: var(--bk-brown, #502314); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; padding-bottom: 10px; border-bottom: 2px solid #E8E0D8; }
.mktd-card-count { font-size: 11px; background: var(--bk-orange); color: #fff; padding: 2px 8px; border-radius: 10px; font-weight: 700; }

/* Form */
.mktd-form { display: flex; flex-direction: column; gap: 14px; }
.mktd-row { display: flex; gap: 14px; flex-wrap: wrap; }
.mktd-field { flex: 1; min-width: 140px; }
.mktd-field label { display: block; font-size: 11px; font-weight: 700; color: var(--bk-brown, #502314); margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.4px; opacity: 0.6; }
.mktd-input { width: 100%; padding: 8px 12px; border: 1.5px solid #E8E0D8; border-radius: 8px; font-size: 13px; font-family: inherit; background: #FAFAF8; color: var(--text); box-sizing: border-box; transition: border-color 0.15s, box-shadow 0.15s; }
.mktd-input:focus { border-color: var(--bk-orange); outline: none; box-shadow: 0 0 0 3px rgba(214,35,0,0.08); background: #fff; }
.mktd-input:disabled { opacity: 0.6; background: #F5F0EB; }
.mktd-textarea { resize: vertical; min-height: 48px; line-height: 1.5; }
.mktd-info { font-size: 15px; font-weight: 700; padding: 8px 0; color: var(--bk-brown, #502314); }

/* Items table */
.mktd-items-wrap { overflow-x: auto; margin: 0 -8px; padding: 0 8px; }
.mktd-items-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px; }
.mktd-items-table th { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--bk-brown, #502314); font-weight: 700; padding: 8px 8px; border-bottom: 2px solid var(--bk-orange, #D62300); text-align: center; white-space: nowrap; background: #FFF8F0; }
.mktd-items-table td { padding: 8px 8px; border-bottom: 1px solid #F5F0EB; text-align: center; vertical-align: middle; }
.mktd-items-table tbody tr:hover { background: #FFFBF5; }
.mktd-input-sm { padding: 6px 8px; font-size: 12px; }
.mktd-item-name { padding-right: 55px !important; }
.mktd-item-sku { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); font-size: 10px; font-weight: 800; color: var(--bk-orange); background: rgba(214,35,0,0.06); padding: 2px 6px; border-radius: 4px; }
.mktd-total-cell { font-weight: 700; color: var(--bk-brown, #502314); font-size: 13px; }
.mktd-remove-btn { background: none; border: none; cursor: pointer; color: #ccc; padding: 4px; border-radius: 6px; transition: all 0.15s; }
.mktd-remove-btn:hover { color: #D62300; background: rgba(214,35,0,0.08); }
.mktd-muted { color: var(--text-muted); }

/* Files */
.mktd-files { display: flex; flex-direction: column; gap: 8px; }
.mktd-file { display: flex; align-items: center; gap: 10px; padding: 8px 12px; background: #FAFAF8; border: 1px solid #F0EBE5; border-radius: 8px; transition: border-color 0.15s; }
.mktd-file:hover { border-color: var(--bk-orange); }
.mktd-file-name { font-size: 13px; font-weight: 500; color: var(--text); text-decoration: none; display: flex; align-items: center; gap: 4px; flex: 1; }
.mktd-file-name:hover { color: var(--bk-orange); }

/* Dropdown */
.mktd-dropdown { background: white; border: 1px solid #E8E0D8; border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,0.12); max-height: 220px; overflow-y: auto; }
.mktd-dropdown-item { padding: 10px 14px; cursor: pointer; font-size: 13px; border-bottom: 1px solid #F5F0EB; transition: background 0.1s; }
.mktd-dropdown-item:last-child { border-bottom: none; }
.mktd-dropdown-item:hover { background: #FFF3E0; }
.mktd-dropdown-sku { font-weight: 800; color: var(--bk-orange); margin-right: 6px; }

/* Tabs */
.mktd-tabs { display: flex; gap: 0; }
.mktd-tab { padding: 6px 16px; border: 1.5px solid #E8E0D8; background: #FAFAF8; font-size: 12px; font-weight: 700; font-family: inherit; color: var(--text-muted); cursor: pointer; transition: all 0.15s; display: flex; align-items: center; gap: 6px; }
.mktd-tab:first-child { border-radius: 8px 0 0 8px; }
.mktd-tab:last-child { border-radius: 0 8px 8px 0; margin-left: -1px; }
.mktd-tab.active { background: var(--bk-brown, #502314); color: #fff; border-color: var(--bk-brown, #502314); }
.mktd-tab.active .mktd-card-count { background: rgba(255,255,255,0.3); }

/* Ingredients info */
.mktd-ing-info { font-size: 12px; color: var(--text-muted); padding: 8px 0 12px; }
.mktd-ing-warn { color: #D97706; font-weight: 600; }

@media (max-width: 600px) {
  .mktd-card { padding: 16px; border-radius: 10px; }
  .mktd-row { flex-direction: column; gap: 10px; }
  .mktd-field { min-width: 100%; }
}
</style>
