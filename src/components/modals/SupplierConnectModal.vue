<template>
  <div class="scm-overlay" @click.self="tryClose">
    <div class="scm-modal">
      <div class="scm-header">
        <h2>Подключить поставщика к заявкам</h2>
        <button class="scm-close" @click="tryClose">×</button>
      </div>

      <!-- Шаги -->
      <div class="scm-steps">
        <div v-for="(s, i) in steps" :key="i" class="scm-step" :class="{ active: step === i, done: step > i }">
          <span class="scm-step-num">{{ i + 1 }}</span>
          <span class="scm-step-name">{{ s }}</span>
        </div>
      </div>

      <div class="scm-body">
        <!-- Шаг 1: выбор поставщика -->
        <div v-if="step === 0" class="scm-pane">
          <p class="scm-hint">Выберите поставщика из справочника для подключения к модулю заявок. Если нужного нет — создайте в разделе «Справочник → Поставщики».</p>
          <div v-if="loadingAvailable" class="scm-loading"><BurgerSpinner text="Загрузка..." /></div>
          <div v-else-if="!availableSuppliers.length" class="scm-empty">
            Нет доступных поставщиков для подключения. Все поставщики этого юрлица уже подключены, либо справочник пуст.
          </div>
          <div v-else class="scm-supplier-list">
            <label v-for="s in availableSuppliers" :key="s.id" class="scm-supplier-item" :class="{ selected: supplierId === s.id }">
              <input type="radio" :value="s.id" v-model="supplierId" />
              <div class="scm-supplier-info">
                <div class="scm-supplier-name">{{ s.short_name }}</div>
                <div class="scm-supplier-full">{{ s.full_name || '—' }}</div>
              </div>
            </label>
          </div>
        </div>

        <!-- Шаг 2: график доставок -->
        <div v-else-if="step === 1" class="scm-pane">
          <p class="scm-hint">Отметьте рестораны и дни доставок. По каждому ресторану можно выбрать несколько дней.</p>
          <div v-if="loadingRests" class="scm-loading"><BurgerSpinner text="Загрузка ресторанов..." /></div>
          <div v-else-if="!restaurants.length" class="scm-empty">
            Для этого юрлица нет активных ресторанов.
          </div>
          <div v-else class="scm-sched-wrap">
            <table class="scm-sched-tbl">
              <thead>
                <tr>
                  <th>Ресторан</th>
                  <th v-for="d in [1,2,3,4,5,6,7]" :key="d">{{ daysShort[d] }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in restaurants" :key="r.id">
                  <td class="scm-sched-rest">
                    <b>{{ r.number }}</b> <span>{{ r.city }}{{ r.address ? ', ' + r.address : '' }}</span>
                  </td>
                  <td v-for="d in [1,2,3,4,5,6,7]" :key="d" class="scm-sched-cell" @click="toggleScheduleDay(r.id, d)">
                    <input type="checkbox" :checked="!!scheduleGrid[r.id]?.[d]" @click.stop="toggleScheduleDay(r.id, d)" />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Шаг 3: шаблон товаров -->
        <div v-else-if="step === 2" class="scm-pane">
          <p class="scm-hint">Список товаров, которые ресторан сможет заказать у поставщика. Для юрлиц одной группы шаблон общий.</p>
          <div v-if="templateEntities.length > 1" class="scm-tpl-le-row">
            <label v-for="e in templateEntities" :key="e" class="scm-tpl-le-btn" :class="{ active: activeTplEntity === e }">
              <input type="radio" :value="e" v-model="activeTplEntity" />
              {{ entityShort(e) }}
            </label>
          </div>
          <div class="scm-tpl-controls">
            <input type="text" v-model="productSearch" placeholder="Поиск товара (SKU или название)" class="scm-input" @input="onProductSearch" />
            <div v-if="productResults.length" class="scm-product-dropdown">
              <div v-for="p in productResults" :key="p.id + '-' + p.sku" class="scm-product-item" @click="addProduct(p)">
                <b>{{ p.sku }}</b> {{ p.name }}
                <span class="scm-product-supplier">{{ p.supplier || '' }}</span>
              </div>
            </div>
          </div>
          <div class="scm-tpl-list">
            <div v-if="!currentTemplate.length" class="scm-empty">Добавьте товары из справочника или строку вручную</div>
            <table v-else class="scm-tpl-tbl">
              <thead>
                <tr>
                  <th>#</th>
                  <th>SKU</th>
                  <th>Товар</th>
                  <th style="width:70px">Кратность</th>
                  <th style="width:70px">Мин.</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(t, i) in currentTemplate" :key="i">
                  <td>{{ i + 1 }}</td>
                  <td><input type="text" v-model="t.sku" class="scm-cell-input" /></td>
                  <td><input type="text" v-model="t.product_name" class="scm-cell-input" /></td>
                  <td><input type="number" v-model.number="t.multiplicity" class="scm-cell-input scm-num" step="0.01" min="0" /></td>
                  <td><input type="number" v-model.number="t.min_qty" class="scm-cell-input scm-num" step="0.01" min="0" /></td>
                  <td><button class="scm-row-del" @click="currentTemplate.splice(i, 1)">×</button></td>
                </tr>
              </tbody>
            </table>
            <button class="scm-btn-outline scm-btn-sm" @click="addEmptyRow">+ Строка вручную</button>
          </div>
        </div>

        <!-- Шаг 4: дедлайны -->
        <div v-else-if="step === 3" class="scm-pane">
          <p class="scm-hint">Для каждого дня доставки укажите день и время дедлайна подачи заявки. Если поле выключено — заявки на этот день приниматься не будут.</p>
          <div class="scm-deadlines">
            <div v-for="dow in activeDeliveryDays" :key="dow" class="scm-deadline-row">
              <div class="scm-deadline-label">
                <b>{{ daysFull[dow] }}</b>
                <span>доставка</span>
              </div>
              <div class="scm-deadline-arrow">→</div>
              <select v-model.number="deadlineRulesMap[dow].deadline_dow" class="scm-input scm-input-sm">
                <option v-for="d in [1,2,3,4,5,6,7]" :key="d" :value="d">{{ daysShort[d] }}</option>
              </select>
              <input type="time" v-model="deadlineRulesMap[dow].deadline_time" class="scm-input scm-input-sm" />
              <label class="scm-switch">
                <input type="checkbox" v-model="deadlineRulesMap[dow].active" />
                <span>{{ deadlineRulesMap[dow].active ? 'принимать' : 'выкл' }}</span>
              </label>
            </div>
            <div v-if="!activeDeliveryDays.length" class="scm-empty">
              Вернитесь на шаг «График» и выберите хотя бы один день доставки.
            </div>
          </div>
        </div>

        <!-- Шаг 5: режим приёма -->
        <div v-else-if="step === 4" class="scm-pane">
          <p class="scm-hint">Текущий режим приёма заявок. Можно поставить на паузу — тогда рестораны не смогут подавать заявки, но увидят ваше сообщение.</p>
          <label class="scm-switch-big">
            <input type="checkbox" v-model="acceptance.is_accepting_orders" />
            <span>{{ acceptance.is_accepting_orders ? 'Принимаем заявки' : 'Приём на паузе' }}</span>
          </label>
          <div class="scm-field">
            <label>Дефолтное время дедлайна <small>(используется, если правила не заданы)</small></label>
            <input type="time" v-model="acceptance.default_deadline_time" class="scm-input" />
          </div>
          <div class="scm-field">
            <label>Сообщение на время паузы <small>(необязательно)</small></label>
            <textarea v-model="acceptance.pause_message" rows="3" class="scm-input" placeholder="Например: Заявки не принимаем до 20.04 по техническим причинам"></textarea>
          </div>
        </div>

        <!-- Шаг 6: подписчики уведомлений -->
        <div v-else-if="step === 5" class="scm-pane">
          <p class="scm-hint">Сотрудники отдела закупок, которые получают в Telegram сводку после дедлайна (Excel со всеми заявками ресторанов).</p>
          <div v-if="loadingUsers" class="scm-loading"><BurgerSpinner text="Загрузка пользователей..." /></div>
          <div v-else class="scm-users">
            <label v-for="u in allUsers" :key="u.name" class="scm-user-item">
              <input type="checkbox" :value="u.name" v-model="notifyUsers" />
              <span>{{ u.name }}<small v-if="u.display_role"> · {{ u.display_role }}</small></span>
              <small v-if="!u.telegram_chat_id" class="scm-user-no-tg">(нет Telegram)</small>
            </label>
          </div>
        </div>
      </div>

      <div class="scm-footer">
        <button class="scm-btn-outline" @click="tryClose">Отмена</button>
        <button v-if="step > 0" class="scm-btn-outline" @click="step--">← Назад</button>
        <button v-if="step < steps.length - 1" class="scm-btn-primary" @click="nextStep" :disabled="!canNext">Далее →</button>
        <button v-else class="scm-btn-primary" @click="submit" :disabled="saving">
          {{ saving ? 'Подключение...' : 'Подключить' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { db } from '@/lib/apiClient.js';
import { useSupplierOrderStore } from '@/stores/supplierOrderStore.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { LEGAL_ENTITIES, ENTITY_SHORT_NAMES, getEntityGroupCode } from '@/lib/legalEntities.js';

const emit = defineEmits(['close', 'connected']);
const soStore = useSupplierOrderStore();
const orderStore = useOrderStore();
const toast = useToastStore();

const steps = ['Поставщик', 'График', 'Товары', 'Дедлайны', 'Приём', 'Уведомления'];
const step = ref(0);

const daysShort = { 1: 'Пн', 2: 'Вт', 3: 'Ср', 4: 'Чт', 5: 'Пт', 6: 'Сб', 7: 'Вс' };
const daysFull = { 1: 'Понедельник', 2: 'Вторник', 3: 'Среда', 4: 'Четверг', 5: 'Пятница', 6: 'Суббота', 7: 'Воскресенье' };

// ═══ Шаг 1: поставщик ═══
const availableSuppliers = ref([]);
const loadingAvailable = ref(false);
const supplierId = ref('');
const selectedSupplier = computed(() => availableSuppliers.value.find(s => String(s.id) === String(supplierId.value)) || null);

async function loadAvailable() {
  loadingAvailable.value = true;
  try {
    availableSuppliers.value = await soStore.adminGetAvailableSuppliers(orderStore.settings.legalEntity);
  } catch (e) { toast.error('Ошибка загрузки поставщиков'); }
  finally { loadingAvailable.value = false; }
}

// ═══ Шаг 2: график ═══
const restaurants = ref([]);
const loadingRests = ref(false);
const scheduleGrid = reactive({}); // { restaurantId: { 1: true, 2: false, ... } }

async function loadRestaurants() {
  loadingRests.value = true;
  try {
    const group = getEntityGroupCode(orderStore.settings.legalEntity);
    const token = localStorage.getItem('bk_session_token') || '';
    const res = await fetch(`/api/restaurants?select=id,number,city,address,region,legal_entity_group&active=eq.1&legal_entity_group=eq.${group}&order=number.asc&limit=500`, {
      headers: { 'X-Session-Token': token, 'X-API-Key': token },
    });
    const data = await res.json();
    restaurants.value = (data.data || data || []).sort((a, b) => parseInt(a.number) - parseInt(b.number));
    for (const r of restaurants.value) if (!scheduleGrid[r.id]) scheduleGrid[r.id] = {};
  } catch { toast.error('Ошибка загрузки ресторанов'); }
  finally { loadingRests.value = false; }
}

function toggleScheduleDay(restId, dow) {
  if (!scheduleGrid[restId]) scheduleGrid[restId] = {};
  scheduleGrid[restId][dow] = !scheduleGrid[restId][dow];
}

// Дни доставки, которые реально выбраны хотя бы одним рестораном — для шага дедлайнов
const activeDeliveryDays = computed(() => {
  const set = new Set();
  for (const rId of Object.keys(scheduleGrid)) {
    for (let d = 1; d <= 7; d++) {
      if (scheduleGrid[rId]?.[d]) set.add(d);
    }
  }
  return [...set].sort((a, b) => a - b);
});

// ═══ Шаг 3: шаблон товаров ═══
// Для группы BK_VM можно завести шаблоны под БК и под ВМ отдельно; для PS — только Пицца Стар.
const templateEntities = computed(() => {
  const group = getEntityGroupCode(orderStore.settings.legalEntity);
  if (group === 'PS') return LEGAL_ENTITIES.filter(e => e.includes('Пицца Стар'));
  return LEGAL_ENTITIES.filter(e => !e.includes('Пицца Стар'));
});
const activeTplEntity = ref('');
const templatesByEntity = reactive({}); // { legalEntity: [ {sku, product_name, ...}, ... ] }

function entityShort(e) { return ENTITY_SHORT_NAMES[e] || e; }
function ensureActiveTplEntity() {
  if (!activeTplEntity.value && templateEntities.value.length) {
    activeTplEntity.value = templateEntities.value[0];
  }
}

const currentTemplate = computed({
  get: () => templatesByEntity[activeTplEntity.value] || [],
  set: (v) => { templatesByEntity[activeTplEntity.value] = v; },
});

function addEmptyRow() {
  if (!activeTplEntity.value) return;
  if (!templatesByEntity[activeTplEntity.value]) templatesByEntity[activeTplEntity.value] = [];
  templatesByEntity[activeTplEntity.value].push({ sku: '', product_name: '', multiplicity: null, min_qty: null });
}

const productSearch = ref('');
const productResults = ref([]);
let searchTimer = null;
function onProductSearch() {
  clearTimeout(searchTimer);
  const q = productSearch.value.trim();
  if (q.length < 2) { productResults.value = []; return; }
  searchTimer = setTimeout(async () => {
    const le = activeTplEntity.value || orderStore.settings.legalEntity;
    const params = new URLSearchParams({ q, legal_entity: le, limit: '15' });
    if (selectedSupplier.value?.short_name) params.set('supplier', selectedSupplier.value.short_name);
    const r = await fetch(`/api/search_products?${params}`, {
      headers: { 'X-Session-Token': localStorage.getItem('bk_session_token') || '' },
    });
    if (r.ok) productResults.value = await r.json();
  }, 250);
}

function addProduct(p) {
  if (!activeTplEntity.value) return;
  if (!templatesByEntity[activeTplEntity.value]) templatesByEntity[activeTplEntity.value] = [];
  const list = templatesByEntity[activeTplEntity.value];
  if (list.some(t => t.sku === p.sku)) { toast.info('Уже в шаблоне'); return; }
  list.push({
    sku: p.sku,
    product_name: p.name,
    multiplicity: p.multiplicity ? parseFloat(p.multiplicity) : null,
    min_qty: null,
  });
  productSearch.value = '';
  productResults.value = [];
}

// ═══ Шаг 4: дедлайны ═══
const deadlineRulesMap = reactive({});
for (let d = 1; d <= 7; d++) {
  deadlineRulesMap[d] = { deadline_dow: d > 1 ? d - 1 : 7, deadline_time: '14:00', active: false };
}

// Авто-активируем дедлайны для дней, которые реально выбраны в графике
function syncDeadlinesFromSchedule() {
  for (const dow of activeDeliveryDays.value) {
    if (deadlineRulesMap[dow]) deadlineRulesMap[dow].active = true;
  }
}

// ═══ Шаг 5: режим приёма ═══
const acceptance = reactive({
  is_accepting_orders: true,
  default_deadline_time: '14:00',
  pause_message: '',
});

// ═══ Шаг 6: уведомления ═══
const allUsers = ref([]);
const loadingUsers = ref(false);
const notifyUsers = ref([]);

async function loadUsers() {
  loadingUsers.value = true;
  try {
    const { data } = await db.rpc('get_users_list_short');
    allUsers.value = data || [];
  } catch {}
  finally { loadingUsers.value = false; }
}

// ═══ Навигация ═══
const canNext = computed(() => {
  if (step.value === 0) return !!supplierId.value;
  if (step.value === 1) return activeDeliveryDays.value.length > 0;
  if (step.value === 2) {
    // Хотя бы один шаблон должен быть непустым
    return Object.values(templatesByEntity).some(arr => arr && arr.length > 0);
  }
  if (step.value === 3) return true;
  if (step.value === 4) return true;
  return true;
});

async function nextStep() {
  if (step.value === 0 && restaurants.value.length === 0) await loadRestaurants();
  if (step.value === 1) ensureActiveTplEntity();
  if (step.value === 1) syncDeadlinesFromSchedule();
  if (step.value === 4 && !allUsers.value.length) await loadUsers();
  step.value++;
}

const saving = ref(false);
async function submit() {
  saving.value = true;
  try {
    // Собираем расписание
    const schedules = [];
    for (const rId of Object.keys(scheduleGrid)) {
      for (let d = 1; d <= 7; d++) {
        if (scheduleGrid[rId]?.[d]) {
          const rule = deadlineRulesMap[d];
          const orderDay = rule?.active ? rule.deadline_dow : (d > 1 ? d - 1 : 7);
          schedules.push({ restaurant_id: parseInt(rId), order_day: orderDay, delivery_day: d });
        }
      }
    }

    // Шаблоны
    const templates = [];
    for (const [le, items] of Object.entries(templatesByEntity)) {
      if (!items || !items.length) continue;
      templates.push({
        legal_entity: le,
        items: items.filter(t => t.sku && t.product_name).map((t, i) => ({ ...t, sort_order: i })),
      });
    }

    // Дедлайны — только для дней, где активно
    const deadlineRules = [];
    for (const [dow, rule] of Object.entries(deadlineRulesMap)) {
      if (!rule.active) continue;
      deadlineRules.push({
        delivery_dow: parseInt(dow),
        deadline_dow: rule.deadline_dow,
        deadline_time: rule.deadline_time.length === 5 ? rule.deadline_time + ':00' : rule.deadline_time,
      });
    }

    const payload = {
      supplier_id: supplierId.value,
      schedules,
      templates,
      deadline_rules: deadlineRules,
      acceptance: {
        is_accepting_orders: acceptance.is_accepting_orders ? 1 : 0,
        default_deadline_time: acceptance.default_deadline_time.length === 5 ? acceptance.default_deadline_time + ':00' : acceptance.default_deadline_time,
        pause_message: acceptance.pause_message || null,
      },
      notify_users: notifyUsers.value,
    };

    const res = await soStore.adminRegisterSupplier(payload);
    if (res.error) { toast.error('Ошибка: ' + res.error); return; }
    toast.success('Поставщик подключён');
    emit('connected', res.supplier);
    emit('close');
  } catch (e) {
    toast.error('Ошибка: ' + (e.message || e));
  } finally { saving.value = false; }
}

function tryClose() { emit('close'); }

onMounted(() => {
  ensureActiveTplEntity();
  loadAvailable();
});
</script>

<style scoped>
.scm-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.5); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 20px; }
.scm-modal { background: white; border-radius: 12px; width: min(900px, 100%); max-height: 92vh; display: flex; flex-direction: column; box-shadow: 0 10px 40px rgba(0,0,0,0.3); }

.scm-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 24px; border-bottom: 1px solid #eee; }
.scm-header h2 { margin: 0; font-size: 18px; color: #502314; }
.scm-close { background: none; border: none; font-size: 28px; cursor: pointer; color: #8b7355; line-height: 1; }
.scm-close:hover { color: #E76F51; }

.scm-steps { display: flex; gap: 0; padding: 12px 24px; border-bottom: 1px solid #eee; background: #fbf7f2; overflow-x: auto; }
.scm-step { flex: 1; min-width: 110px; display: flex; align-items: center; gap: 6px; font-size: 12px; color: #8b7355; padding: 6px 8px; position: relative; }
.scm-step-num { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: #e0d5c8; color: #fff; font-weight: 700; font-size: 12px; flex-shrink: 0; }
.scm-step.active .scm-step-num { background: #E76F51; }
.scm-step.done .scm-step-num { background: #16a34a; }
.scm-step.active { color: #502314; font-weight: 700; }
.scm-step-name { white-space: nowrap; }

.scm-body { flex: 1; min-height: 0; overflow-y: auto; padding: 20px 24px; }
.scm-pane { display: flex; flex-direction: column; gap: 12px; }
.scm-hint { font-size: 13px; color: #6b4f3a; margin: 0 0 4px; line-height: 1.5; }
.scm-loading, .scm-empty { padding: 24px; text-align: center; color: #8b7355; font-size: 13px; background: #fbf7f2; border: 2px dashed #e0d5c8; border-radius: 10px; }

/* Step 1 */
.scm-supplier-list { display: flex; flex-direction: column; gap: 6px; }
.scm-supplier-item { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border: 2px solid #e0d5c8; border-radius: 8px; cursor: pointer; background: white; transition: all 0.15s; }
.scm-supplier-item:hover { border-color: #E76F51; }
.scm-supplier-item.selected { border-color: #E76F51; background: #fff2e0; }
.scm-supplier-info { flex: 1; }
.scm-supplier-name { font-weight: 700; color: #502314; font-size: 14px; }
.scm-supplier-full { font-size: 12px; color: #8b7355; margin-top: 2px; }

/* Step 2: schedule grid */
.scm-sched-wrap { max-height: 420px; overflow: auto; border: 1px solid #e0d5c8; border-radius: 8px; }
.scm-sched-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.scm-sched-tbl th, .scm-sched-tbl td { padding: 8px 10px; border-bottom: 1px solid #f0e8de; text-align: center; }
.scm-sched-tbl th { background: #fbf7f2; color: #502314; font-weight: 700; position: sticky; top: 0; }
.scm-sched-rest { text-align: left; }
.scm-sched-rest b { color: #E76F51; margin-right: 8px; }
.scm-sched-cell { cursor: pointer; }
.scm-sched-cell:hover { background: #fff2e0; }

/* Step 3: templates */
.scm-tpl-le-row { display: flex; gap: 6px; }
.scm-tpl-le-btn { padding: 6px 14px; border: 1.5px solid #e0d5c8; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600; color: #502314; display: inline-flex; align-items: center; gap: 6px; }
.scm-tpl-le-btn input { margin: 0; }
.scm-tpl-le-btn.active { background: #E76F51; color: white; border-color: #E76F51; }
.scm-tpl-controls { position: relative; }
.scm-product-dropdown { position: absolute; top: 100%; left: 0; right: 0; z-index: 10; background: white; border: 2px solid #E76F51; border-radius: 8px; max-height: 240px; overflow-y: auto; margin-top: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.scm-product-item { padding: 8px 12px; cursor: pointer; display: flex; gap: 8px; align-items: center; border-bottom: 1px solid #f0e8de; }
.scm-product-item:hover { background: #fbf7f2; }
.scm-product-supplier { margin-left: auto; color: #8b7355; font-size: 11px; }
.scm-tpl-list { display: flex; flex-direction: column; gap: 8px; }
.scm-tpl-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.scm-tpl-tbl th, .scm-tpl-tbl td { padding: 6px 8px; border-bottom: 1px solid #f0e8de; text-align: left; }
.scm-tpl-tbl th { background: #fbf7f2; color: #502314; font-weight: 700; }
.scm-cell-input { width: 100%; padding: 4px 8px; border: 1px solid #e0d5c8; border-radius: 6px; font-size: 13px; background: white; box-sizing: border-box; }
.scm-cell-input.scm-num { text-align: right; }
.scm-cell-input:focus { border-color: #E76F51; outline: none; }
.scm-row-del { background: none; border: none; color: #8b7355; font-size: 18px; cursor: pointer; padding: 0 8px; }
.scm-row-del:hover { color: #E76F51; }

/* Step 4: deadlines */
.scm-deadlines { display: flex; flex-direction: column; gap: 10px; }
.scm-deadline-row { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border: 1.5px solid #e0d5c8; border-radius: 8px; background: #fbf7f2; }
.scm-deadline-label { display: flex; flex-direction: column; min-width: 140px; }
.scm-deadline-label b { color: #502314; font-size: 14px; }
.scm-deadline-label span { color: #8b7355; font-size: 11px; }
.scm-deadline-arrow { color: #8b7355; font-size: 16px; }
.scm-input { padding: 6px 10px; border: 1.5px solid #e0d5c8; border-radius: 6px; font-size: 13px; background: white; color: #502314; }
.scm-input:focus { border-color: #E76F51; outline: none; }
.scm-input-sm { min-width: 80px; }
.scm-switch { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: #502314; cursor: pointer; margin-left: auto; }
textarea.scm-input { width: 100%; font-family: inherit; resize: vertical; box-sizing: border-box; }

/* Step 5: acceptance */
.scm-switch-big { display: inline-flex; align-items: center; gap: 10px; font-size: 15px; font-weight: 600; color: #502314; cursor: pointer; padding: 12px 0; }
.scm-field { display: flex; flex-direction: column; gap: 6px; }
.scm-field label { font-size: 12px; font-weight: 700; color: #6b4f3a; text-transform: uppercase; letter-spacing: 0.3px; }
.scm-field small { font-weight: 400; text-transform: none; color: #8b7355; }

/* Step 6: notifications */
.scm-users { display: flex; flex-direction: column; gap: 6px; max-height: 340px; overflow-y: auto; padding: 8px; border: 1px solid #e0d5c8; border-radius: 8px; }
.scm-user-item { display: flex; align-items: center; gap: 10px; padding: 6px 8px; cursor: pointer; border-radius: 6px; font-size: 13px; }
.scm-user-item:hover { background: #fbf7f2; }
.scm-user-item small { color: #8b7355; font-weight: 400; }
.scm-user-no-tg { color: #d97706; margin-left: auto; font-size: 11px; }

.scm-footer { display: flex; gap: 10px; padding: 14px 24px; border-top: 1px solid #eee; background: #fbf7f2; border-radius: 0 0 12px 12px; }
.scm-footer button { padding: 9px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: inherit; border: 1.5px solid #e0d5c8; }
.scm-btn-outline { background: white; color: #502314; }
.scm-btn-outline:hover { border-color: #E76F51; color: #E76F51; }
.scm-btn-primary { background: #E76F51; color: white; border-color: #E76F51; margin-left: auto; }
.scm-btn-primary:hover:not(:disabled) { background: #b51e00; }
.scm-btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.scm-btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 6px; background: white; border: 1.5px solid #e0d5c8; color: #502314; cursor: pointer; font-family: inherit; align-self: flex-start; }
.scm-btn-sm:hover { border-color: #E76F51; }
</style>
