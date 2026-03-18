<template>
  <div class="veg">
    <div class="veg-top">
      <h1 class="veg-title">Заказ овощей</h1>
      <button class="veg-btn fill" @click="showCreate = true">+ Новая сессия</button>
    </div>

    <!-- Tabs -->
    <div class="veg-tabs">
      <button class="veg-tab" :class="{ active: tab === 'sessions' }" @click="tab = 'sessions'">Сессии</button>
      <button class="veg-tab" :class="{ active: tab === 'schedule' }" @click="tab = 'schedule'; loadSchedule()">Расписание</button>
    </div>

    <!-- SESSIONS TAB -->
    <template v-if="tab === 'sessions'">
      <!-- Sessions list -->
      <div v-if="!activeSession" class="veg-list">
        <div v-if="loading" class="veg-empty">Загрузка...</div>
        <div v-else-if="!sessions.length" class="veg-empty">Нет сессий. Создайте первую.</div>
        <div
          v-for="s in sessions" :key="s.id"
          class="veg-card"
          :class="{ closed: s.status === 'closed' }"
          @click="openSession(s)"
        >
          <div class="veg-card-top">
            <div class="veg-card-name">{{ s.name }}</div>
            <span class="veg-tag" :class="s.status === 'active' ? 'green' : 'gray'">
              {{ s.status === 'active' ? 'Активна' : 'Закрыта' }}
            </span>
          </div>
          <div class="veg-card-meta">
            {{ s.created_by || '---' }} · {{ fmtDate(s.created_at) }}
          </div>
        </div>
      </div>

      <!-- Session detail -->
      <div v-if="activeSession" class="veg-detail">
        <div class="veg-detail-bar">
          <button class="veg-btn outline" @click="closeDetail">← Назад</button>
          <div class="veg-detail-info">
            <div class="veg-detail-name">{{ activeSession.name }}</div>
            <span class="veg-tag" :class="activeSession.status === 'active' ? 'green' : 'gray'">
              {{ activeSession.status === 'active' ? 'Активна' : 'Закрыта' }}
            </span>
          </div>
          <div class="veg-detail-actions">
            <button v-if="activeSession.status === 'active'" class="veg-btn outline" @click="openTokenModal">
              Ссылка для ресторанов
            </button>
            <button v-if="activeSession.status === 'active'" class="veg-btn outline red-text" @click="askCloseSession">
              Закрыть
            </button>
            <button v-if="activeSession.status === 'closed'" class="veg-btn outline" @click="reopenSession">
              Открыть заново
            </button>
            <button class="veg-btn outline red-text" @click="askDeleteSession">Удалить</button>
          </div>
        </div>

        <!-- Token -->
        <div v-if="tokenLink" class="veg-token">
          <div class="veg-token-label">Ссылка для ресторанов (7 дней)</div>
          <div class="veg-token-row">
            <input :value="tokenLink" readonly class="veg-token-input" @focus="$event.target.select()"/>
            <button class="veg-btn sm fill" @click="copyToken">{{ copied ? 'Скопировано' : 'Копировать' }}</button>
          </div>
        </div>

        <!-- Data -->
        <div v-if="sessionData" class="veg-data">
          <div class="veg-summary">
            <div class="veg-summary-item">
              <div class="veg-summary-num">{{ sessionData.products?.length || 0 }}</div>
              <div class="veg-summary-lbl">Товаров</div>
            </div>
            <div class="veg-summary-item">
              <div class="veg-summary-num">{{ allRestaurants.length - missingRestaurants.length }}</div>
              <div class="veg-summary-lbl">Ответов</div>
            </div>
            <div class="veg-summary-item">
              <div class="veg-summary-num">{{ missingRestaurants.length }}</div>
              <div class="veg-summary-lbl">Не ответили</div>
            </div>
            <div style="margin-left: auto; display: flex; gap: 6px;">
              <button class="veg-btn sm outline" @click="exportExcel">Excel</button>
              <button class="veg-btn sm outline" @click="refreshData">Обновить</button>
            </div>
          </div>

          <!-- Filter bar -->
          <div class="veg-filter-bar">
            <input v-model="filterText" type="text" class="veg-input veg-filter-input" placeholder="Поиск по ресторану, городу..." />
            <select v-model="filterRegion" class="veg-input veg-filter-select">
              <option value="">Все регионы</option>
              <option v-for="r in regions" :key="r" :value="r">{{ r }}</option>
            </select>
            <label class="veg-filter-check">
              <input type="checkbox" v-model="showMissing" /> Не ответившие
            </label>
          </div>

          <!-- Date tabs -->
          <div v-if="deliveryDates.length" class="veg-dates-row">
            <span class="veg-dates-label">Дата доставки:</span>
            <button
              v-for="dd in deliveryDates" :key="dd"
              class="veg-date-chip"
              :class="{ active: selectedDate === dd }"
              @click="selectedDate = dd"
            >{{ fmtShortDate(dd) }}</button>
            <button
              class="veg-date-chip"
              :class="{ active: selectedDate === 'all' }"
              @click="selectedDate = 'all'"
            >Все</button>
          </div>

          <!-- Main data table -->
          <div v-if="tableRows.length" class="veg-tbl-wrap">
            <table class="veg-tbl">
              <thead>
                <tr>
                  <th class="veg-th-rest">Ресторан</th>
                  <template v-for="dd in visibleDates" :key="dd">
                    <th v-for="prod in sessionData.products" :key="prod.id + dd" class="veg-th-qty">
                      <div class="veg-th-prod">{{ shortName(prod.product_name) }}</div>
                      <div class="veg-th-date">{{ fmtShortDate(dd) }}{{ prod.multiplicity ? ' (×' + prod.multiplicity + ')' : '' }}</div>
                    </th>
                  </template>
                  <th class="veg-th-note">Пометка</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in filteredRows" :key="row.number" :class="{ 'veg-row-missing': !row.hasData }">
                  <td class="veg-td-rest">
                    <span class="veg-rest-num">{{ row.number }}</span>
                    <span class="veg-rest-addr">{{ row.city }}{{ row.address ? ', ' + row.address : '' }}</span>
                    <span v-if="row.isVM" class="veg-vm-badge">ВМ</span>
                    <button v-if="row.hasData" class="veg-del-row" :title="selectedDate !== 'all' ? 'Удалить заявку на ' + fmtShortDate(selectedDate) : 'Удалить все заявки'" @click.stop="confirmDeleteOrders(row.number)">×</button>
                  </td>
                  <template v-for="dd in visibleDates" :key="dd">
                    <td
                      v-for="prod in sessionData.products" :key="prod.id + dd"
                      class="veg-td-qty"
                      :class="{ 'veg-td-no-delivery': !restHasDeliveryOnDate(row.number, dd) }"
                      @dblclick="startEdit(row.number, dd, prod.id)"
                    >
                      <template v-if="editCell === `${row.number}_${dd}_${prod.id}`">
                        <input
                          ref="editInput"
                          v-model="editValue"
                          type="text" inputmode="decimal"
                          class="veg-cell-input"
                          @keydown.enter="saveEdit"
                          @keydown.escape="editCell = ''"
                          @blur="saveEdit"
                        />
                      </template>
                      <template v-else>
                        <span v-if="getCellAdmin(row.number, dd, prod.id) !== null" class="veg-qty-admin" :title="'Исходное: ' + getCellQty(row.number, dd, prod.id)">
                          {{ getCellAdmin(row.number, dd, prod.id) }}
                        </span>
                        <span v-else-if="getCellQty(row.number, dd, prod.id)" class="veg-qty">
                          {{ getCellQty(row.number, dd, prod.id) }}
                        </span>
                        <span v-else class="veg-qty-empty">—</span>
                        <span v-if="getPrevDayQty(row.number, dd, prod.id)" class="veg-prev-day">{{ getPrevDayQty(row.number, dd, prod.id) }}</span>
                      </template>
                    </td>
                  </template>
                  <td class="veg-td-note">
                    <input
                      :value="row.note"
                      class="veg-note-input"
                      placeholder="—"
                      @change="saveNote(row.number, $event.target.value)"
                    />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Missing restaurants list -->
          <div v-if="showMissing && missingRestaurants.length" class="veg-missing">
            <h3>Не ответили ({{ missingRestaurants.length }})</h3>
            <div class="veg-missing-list">
              <span v-for="r in missingRestaurants" :key="r.number" class="veg-missing-item">
                {{ r.number }}{{ r.city ? ' (' + r.city + ')' : '' }}
              </span>
            </div>
          </div>

          <!-- Copy missing restaurants button -->
          <div v-if="missingRestaurants.length" class="veg-copy-missing">
            <button class="veg-btn outline" @click="copyMissingRestaurants">
              {{ copiedMissing ? 'Скопировано!' : `Скопировать не ответивших (${missingRestaurants.length})` }}
            </button>
          </div>
        </div>
        <div v-else class="veg-empty" style="padding: 40px 0;">Загрузка данных...</div>
      </div>
    </template>

    <!-- SCHEDULE TAB -->
    <template v-if="tab === 'schedule'">
      <div class="veg-schedule">
        <!-- Дедлайны -->
        <div class="veg-deadlines-block">
          <h3 class="veg-section-title">Дедлайны заказа</h3>
          <p class="veg-schedule-hint">Для каждого дня доставки — до какого дня и времени рестораны могут подать заявку.</p>
          <div class="veg-deadlines-grid">
            <div v-for="rule in deadlineRules" :key="rule.delivery_dow" class="veg-deadline-row">
              <span class="veg-dl-label">Доставка {{ dowName(rule.delivery_dow) }} →</span>
              <select v-model="rule.deadline_dow" class="veg-dl-select">
                <option v-for="d in 7" :key="d" :value="d">{{ dowNameShort(d) }}</option>
              </select>
              <input v-model="rule.deadline_time" type="time" class="veg-dl-time" />
              <button class="veg-product-del" @click="deadlineRules = deadlineRules.filter(r => r !== rule)" title="Удалить">×</button>
            </div>
          </div>
          <div class="veg-dl-actions">
            <button class="veg-btn outline sm" @click="addDeadlineRule">+ Добавить правило</button>
            <button class="veg-btn fill sm" @click="saveDeadlines" :disabled="deadlineSaving">
              {{ deadlineSaving ? 'Сохранение...' : 'Сохранить дедлайны' }}
            </button>
          </div>
        </div>

        <h3 class="veg-section-title" style="margin-top: 20px;">Дни доставки по ресторанам</h3>
        <p class="veg-schedule-hint">Отметьте дни недели, когда ресторан получает овощи.</p>
        <div v-if="scheduleLoading" class="veg-empty">Загрузка...</div>
        <template v-else>
          <div class="veg-schedule-filter">
            <input v-model="scheduleFilter" type="text" class="veg-input" placeholder="Поиск ресторана..." style="max-width: 300px;" />
            <button class="veg-btn fill sm" @click="saveScheduleAll" :disabled="scheduleSaving">
              {{ scheduleSaving ? 'Сохранение...' : 'Сохранить' }}
            </button>
          </div>
          <div class="veg-tbl-wrap">
            <table class="veg-tbl veg-schedule-tbl">
              <thead>
                <tr>
                  <th>Ресторан</th>
                  <th>Пн</th><th>Вт</th><th>Ср</th><th>Чт</th><th>Пт</th><th>Сб</th><th>Вс</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in filteredScheduleRestaurants" :key="r.number">
                  <td class="veg-td-rest-sch">
                    <span class="veg-rest-num">{{ r.number }}</span>
                    <span class="veg-rest-addr">{{ r.city }}{{ r.address ? ', ' + r.address : '' }}</span>
                  </td>
                  <td v-for="d in 7" :key="d" class="veg-td-check" @click="toggleScheduleDay(r.number, d)">
                    <input type="checkbox" :checked="scheduleMap[String(r.number)]?.includes(d)" @click.stop="toggleScheduleDay(r.number, d)" />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </div>
    </template>

    <!-- Create Session Modal -->
    <Teleport to="body">
      <div v-if="showCreate" class="modal" @click.self="showCreate = false">
        <div class="modal-box" style="max-width: 480px;">
          <h3 style="margin-bottom: 14px;">Новая сессия</h3>
          <div style="display:flex;flex-direction:column;gap:10px;">
            <input v-model="createName" type="text" class="veg-input" placeholder="Название, напр. 'Неделя 12 (17-23 марта)'" />
            <div style="font-size:13px;font-weight:600;color:#555;margin-top:4px;">Товары:</div>
            <div v-for="(p, idx) in createProducts" :key="idx" class="veg-product-block">
              <div class="veg-product-row">
                <input v-model="p.name" type="text" placeholder="Название товара" class="veg-product-name" />
                <select v-model="p.unit" class="veg-product-select">
                  <option value="kg">кг</option>
                  <option value="pcs">шт</option>
                </select>
                <input v-model="p.multiplicity" type="text" placeholder="×" class="veg-product-mult" inputmode="decimal" title="Кратность" />
                <button v-if="createProducts.length > 1" class="veg-product-del" @click="createProducts.splice(idx, 1)">×</button>
              </div>
            </div>
            <button class="veg-btn outline sm" @click="createProducts.push({ name: '', unit: 'kg', multiplicity: '' })" style="align-self:flex-start;">
              + Добавить товар
            </button>
          </div>
          <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <button class="btn" @click="showCreate = false">Отмена</button>
            <button class="btn primary" @click="createSession" :disabled="creating">
              {{ creating ? 'Создание...' : 'Создать' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Token Modal -->
    <Teleport to="body">
      <div v-if="showTokenModal" class="modal" @click.self="showTokenModal = false">
        <div class="modal-box" style="max-width: 480px;">
          <h3 style="margin-bottom: 14px;">Ссылка для ресторанов</h3>
          <div v-if="activeTokens.length" style="margin-bottom:12px;">
            <div style="font-size:12px;color:#888;margin-bottom:6px;">Существующие ссылки:</div>
            <div v-for="t in activeTokens" :key="t.token" class="veg-existing-token">
              <div class="veg-existing-token-meta">
                {{ t.created_by }} · до {{ fmtDate(t.expires_at) }}
              </div>
              <div class="veg-token-row" style="margin-top:4px;">
                <input :value="buildTokenLink(t.token)" readonly class="veg-token-input" @focus="$event.target.select()"/>
                <button class="veg-btn sm outline" @click="copyText(buildTokenLink(t.token))">Копировать</button>
              </div>
            </div>
          </div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <label style="font-size:12px;color:#555;font-weight:600;">Действует до:</label>
            <input v-model="tokenExpiresDate" type="date" class="veg-dl-time" style="width:150px;" />
            <button class="veg-btn fill" @click="generateToken" :disabled="generatingToken || !tokenExpiresDate">
              {{ generatingToken ? 'Генерация...' : 'Создать ссылку' }}
            </button>
          </div>
          <div style="display:flex;justify-content:flex-end;margin-top:12px;">
            <button class="btn" @click="showTokenModal = false">Закрыть</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Confirm Modal -->
    <Teleport to="body">
      <div v-if="confirmModal" class="modal" @click.self="confirmModal = null">
        <div class="modal-box" style="max-width: 380px;">
          <h3 style="margin-bottom: 8px;">{{ confirmModal.title }}</h3>
          <p style="color:var(--text-muted);font-size:14px;margin-bottom:20px;">{{ confirmModal.text }}</p>
          <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button class="btn" @click="confirmModal = null">Отмена</button>
            <button class="btn primary" :style="confirmModal.danger ? 'background:var(--error)' : ''" @click="confirmModal.action(); confirmModal = null;">
              {{ confirmModal.ok || 'Подтвердить' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, nextTick } from 'vue';
import { useUserStore } from '@/stores/userStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { db } from '@/lib/apiClient.js';

const userStore = useUserStore();
const toastStore = useToastStore();

const tab = ref('sessions');
const loading = ref(true);
const sessions = ref([]);
const activeSession = ref(null);
const sessionData = ref(null);

// Create
const showCreate = ref(false);
const createName = ref('');
const createProducts = ref([
  { name: 'Томат', unit: 'kg', multiplicity: 6 },
  { name: 'Лук репчатый', unit: 'kg', multiplicity: 1 },
  { name: 'Салат айсберг', unit: 'pcs', multiplicity: '' },
]);
const creating = ref(false);

// Token
const showTokenModal = ref(false);
const tokenLink = ref('');
const copied = ref(false);
const generatingToken = ref(false);
const tokenExpiresDate = ref('');

// Data filters
const filterText = ref('');
const filterRegion = ref('');
const showMissing = ref(false);

// Cell editing
const editCell = ref('');
const editValue = ref('');
const editInput = ref(null);

// Schedule
const scheduleLoading = ref(false);
const scheduleMap = reactive({}); // { restNum: [1,3,5] }
const scheduleFilter = ref('');
const scheduleSaving = ref(false);

// Deadlines
const deadlineRules = ref([]);
const deadlineSaving = ref(false);
const DOW_NAMES = { 1: 'Понедельник', 2: 'Вторник', 3: 'Среда', 4: 'Четверг', 5: 'Пятница', 6: 'Суббота', 7: 'Воскресенье' };
const DOW_SHORT = { 1: 'Пн', 2: 'Вт', 3: 'Ср', 4: 'Чт', 5: 'Пт', 6: 'Сб', 7: 'Вс' };
function dowName(d) { return DOW_NAMES[d] || d; }
function dowNameShort(d) { return DOW_SHORT[d] || d; }
function addDeadlineRule() {
  const used = new Set(deadlineRules.value.map(r => r.delivery_dow));
  const free = [1,2,3,4,5,6,7].find(d => !used.has(d));
  if (!free) { toast.show('Все дни уже добавлены', 'warning'); return; }
  deadlineRules.value.push({ delivery_dow: free, deadline_dow: free > 1 ? free - 1 : 7, deadline_time: '12:00' });
}
async function saveDeadlines() {
  deadlineSaving.value = true;
  try {
    const { data } = await db.rpc('veg_save_deadlines', { rules: deadlineRules.value });
    if (data?.error) toast.show(data.error, 'error');
    else toast.show('Дедлайны сохранены');
  } catch { toast.show('Ошибка сохранения', 'error'); }
  finally { deadlineSaving.value = false; }
}

// Confirm
const confirmModal = ref(null);

function fmtDate(str) {
  if (!str) return '';
  const d = new Date(str);
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' }) + ' ' +
         d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

function fmtShortDate(str) {
  if (!str) return '';
  const d = new Date(str + 'T00:00:00');
  const dayNames = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
  return dayNames[d.getDay()] + ' ' + d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
}

function shortName(name) {
  return name.length > 10 ? name.slice(0, 10) + '...' : name;
}

// ═══ Sessions ═══
onMounted(loadSessions);

async function loadSessions() {
  loading.value = true;
  try {
    const { data } = await db.from('veg_sessions').select('*').order('created_at.desc').limit(50);
    sessions.value = data || [];
  } catch (e) { console.warn('[veg] load sessions', e); }
  finally { loading.value = false; }
}

async function createSession() {
  const name = createName.value.trim();
  const prods = createProducts.value.filter(p => p.name.trim());
  if (!name || !prods.length) return;
  creating.value = true;
  try {
    const { data } = await db.rpc('veg_create_session', {
      name,
      products: prods.map(p => ({ name: p.name.trim(), unit: p.unit, multiplicity: p.multiplicity ? parseFloat(String(p.multiplicity).replace(',', '.')) : null })),
      user_name: userStore.currentUser?.name,
    });
    if (data?.id) {
      showCreate.value = false;
      createName.value = '';
      createProducts.value = [
        { name: 'Томат', unit: 'kg', multiplicity: 6 },
        { name: 'Лук репчатый', unit: 'kg', multiplicity: 1 },
        { name: 'Салат айсберг', unit: 'pcs', multiplicity: '' },
      ];
      await loadSessions();
      toastStore.show('Сессия создана');
    }
  } catch (e) { toastStore.show('Ошибка создания', 'error'); }
  finally { creating.value = false; }
}

async function openSession(s) {
  activeSession.value = s;
  sessionData.value = null;
  tokenLink.value = '';
  selectedDate.value = 'all';
  await refreshData();
}

function closeDetail() {
  activeSession.value = null;
  sessionData.value = null;
  filterText.value = '';
  filterRegion.value = '';
}

async function refreshData() {
  if (!activeSession.value) return;
  try {
    const { data } = await db.rpc('veg_get_session_data', { session_id: activeSession.value.id });
    sessionData.value = data;
  } catch (e) { console.warn('[veg] refresh', e); }
}

// ═══ Token ═══
const activeTokens = computed(() => {
  if (!sessionData.value?.tokens) return [];
  const now = new Date();
  return sessionData.value.tokens.filter(t => new Date(t.expires_at) > now);
});

function buildTokenLink(token) {
  return `${window.location.origin}/veg-order/${token}`;
}

function openTokenModal() {
  // По умолчанию — через 7 дней
  const d = new Date();
  d.setDate(d.getDate() + 7);
  tokenExpiresDate.value = d.toISOString().slice(0, 10);
  showTokenModal.value = true;
}

async function generateToken() {
  generatingToken.value = true;
  try {
    const { data } = await db.rpc('veg_create_token', {
      session_id: activeSession.value.id,
      user_name: userStore.currentUser?.name,
      expires_date: tokenExpiresDate.value,
    });
    if (data?.token) {
      tokenLink.value = buildTokenLink(data.token);
      await refreshData();
    }
  } catch (e) { toastStore.show('Ошибка генерации', 'error'); }
  finally { generatingToken.value = false; }
}

function copyToken() {
  navigator.clipboard.writeText(tokenLink.value);
  copied.value = true;
  setTimeout(() => { copied.value = false; }, 2000);
}

function copyText(txt) {
  navigator.clipboard.writeText(txt);
  toastStore.show('Скопировано');
}

// Copy missing restaurants
const copiedMissing = ref(false);
function copyMissingRestaurants() {
  const nums = missingRestaurants.value.map(r => r.number).join(', ');
  const text = `Нет заявок на овощи планеты ресторанов: ${nums}`;
  navigator.clipboard.writeText(text);
  copiedMissing.value = true;
  toastStore.show('Список скопирован');
  setTimeout(() => { copiedMissing.value = false; }, 2000);
}

// ═══ Close/Delete ═══
function askCloseSession() {
  confirmModal.value = {
    title: 'Закрыть сессию?',
    text: 'После закрытия рестораны не смогут отправлять заявки.',
    ok: 'Закрыть',
    danger: true,
    action: async () => {
      await db.rpc('veg_close_session', { session_id: activeSession.value.id });
      activeSession.value.status = 'closed';
      toastStore.show('Сессия закрыта');
    },
  };
}

async function reopenSession() {
  await db.rpc('veg_reopen_session', { session_id: activeSession.value.id });
  activeSession.value.status = 'active';
  toastStore.show('Сессия открыта');
}

function askDeleteSession() {
  confirmModal.value = {
    title: 'Удалить сессию?',
    text: 'Все заявки и данные этой сессии будут удалены безвозвратно.',
    ok: 'Удалить',
    danger: true,
    action: async () => {
      await db.from('veg_sessions').delete().eq('id', activeSession.value.id);
      activeSession.value = null;
      sessionData.value = null;
      await loadSessions();
      toastStore.show('Сессия удалена');
    },
  };
}

// ═══ Data table ═══
const selectedDate = ref('all');

const deliveryDates = computed(() => {
  if (!sessionData.value?.orders) return [];
  const dates = [...new Set(sessionData.value.orders.map(o => o.delivery_date))];
  dates.sort();
  return dates;
});

const visibleDates = computed(() => {
  if (selectedDate.value === 'all') return deliveryDates.value;
  return deliveryDates.value.filter(d => d === selectedDate.value);
});

const regions = computed(() => {
  if (!sessionData.value?.restaurants) return [];
  return [...new Set(sessionData.value.restaurants.map(r => r.region || r.city).filter(Boolean))].sort();
});

const allRestaurants = computed(() => sessionData.value?.restaurants || []);

// Рестораны, которые отправили заявку (с учётом выбранной даты)
function restHasDataForDates(restNum, dates) {
  if (!sessionData.value?.orders) return false;
  return sessionData.value.orders.some(o =>
    String(o.restaurant_number) === String(restNum) &&
    dates.includes(o.delivery_date) &&
    parseFloat(o.quantity) > 0
  );
}

const missingRestaurants = computed(() => {
  const dates = visibleDates.value;
  return allRestaurants.value.filter(r => !restHasDataForDates(r.number, dates));
});

// Build rows: all restaurants that have data, + missing if filter enabled
const tableRows = computed(() => {
  if (!sessionData.value) return [];
  const rests = allRestaurants.value;
  const dates = visibleDates.value;
  const notesMap = {};
  for (const n of (sessionData.value.notes || [])) {
    notesMap[n.restaurant_number] = n.note;
  }
  const rows = rests.map(r => ({
    number: String(r.number),
    city: r.city || '',
    address: r.address || '',
    region: r.region || r.city || '',
    isVM: String(r.number) === '3',
    hasData: restHasDataForDates(r.number, dates),
    note: notesMap[String(r.number)] || '',
  }));
  if (!showMissing.value) {
    return rows.filter(r => r.hasData);
  }
  return rows;
});

const filteredRows = computed(() => {
  let rows = tableRows.value;
  if (filterRegion.value) {
    rows = rows.filter(r => r.region === filterRegion.value);
  }
  if (filterText.value) {
    const q = filterText.value.toLowerCase();
    rows = rows.filter(r =>
      r.number.includes(q) ||
      r.city.toLowerCase().includes(q) ||
      r.address.toLowerCase().includes(q)
    );
  }
  // При фильтре по конкретной дате — показывать только рестораны, у которых доставка в этот день
  if (selectedDate.value !== 'all' && sessionData.value?.schedule) {
    const d = new Date(selectedDate.value + 'T00:00:00');
    const dow = d.getDay() || 7; // 1=пн..7=вс
    rows = rows.filter(r => {
      const days = sessionData.value.schedule[r.number] || [];
      return days.includes(dow);
    });
  }
  return rows;
});

// Order data lookup
const orderLookup = computed(() => {
  const map = {};
  for (const o of (sessionData.value?.orders || [])) {
    const key = `${o.restaurant_number}_${o.delivery_date}_${o.product_id}`;
    map[key] = o;
  }
  return map;
});

function getCellQty(restNum, date, prodId) {
  const o = orderLookup.value[`${restNum}_${date}_${prodId}`];
  if (!o) return '';
  const v = parseFloat(o.quantity);
  return v === 0 ? '' : (v === Math.floor(v) ? Math.floor(v) : v);
}

function getCellAdmin(restNum, date, prodId) {
  const o = orderLookup.value[`${restNum}_${date}_${prodId}`];
  if (!o || o.admin_qty === null || o.admin_qty === undefined) return null;
  const v = parseFloat(o.admin_qty);
  return v === Math.floor(v) ? Math.floor(v) : v;
}

function getPrevDayQty(restNum, date, prodId) {
  const prevDates = deliveryDates.value.filter(d => d < date);
  if (prevDates.length === 0) return null;
  const prevDate = prevDates[prevDates.length - 1];
  const o = orderLookup.value[`${restNum}_${prevDate}_${prodId}`];
  if (!o) return null;
  const adminQ = o.admin_qty !== null && o.admin_qty !== undefined ? parseFloat(o.admin_qty) : NaN;
  const q = !isNaN(adminQ) ? adminQ : parseFloat(o.quantity);
  if (q <= 0) return null;
  return q === Math.floor(q) ? Math.floor(q) : q;
}

function restHasDeliveryOnDate(restNum, dateStr) {
  if (!sessionData.value?.schedule) return true; // если расписание не загружено — разрешаем
  const d = new Date(dateStr + 'T00:00:00');
  const dow = d.getDay() || 7;
  const days = sessionData.value.schedule[String(restNum)] || [];
  return days.includes(dow);
}

function startEdit(restNum, date, prodId) {
  if (!restHasDeliveryOnDate(restNum, date)) return; // нет доставки в этот день
  const key = `${restNum}_${date}_${prodId}`;
  const o = orderLookup.value[key];
  editCell.value = key;
  editValue.value = o?.admin_qty !== null && o?.admin_qty !== undefined ? o.admin_qty : (o?.quantity || '');
  nextTick(() => {
    const el = document.querySelector('.veg-cell-input');
    if (el) { el.focus(); el.select(); }
  });
}

async function saveEdit() {
  if (!editCell.value) return;
  const match = editCell.value.match(/^(\d+)_(\d{4}-\d{2}-\d{2})_(\d+)$/);
  if (!match) { editCell.value = ''; return; }
  const [, restNum, date, prodId] = match;
  const key = `${restNum}_${date}_${prodId}`;
  const o = orderLookup.value[key];
  const val = parseFloat(String(editValue.value).replace(',', '.'));
  editCell.value = '';
  try {
    if (o) {
      // Обновляем существующую запись
      await db.rpc('veg_update_order', {
        order_id: o.id,
        admin_qty: isNaN(val) ? null : val,
      });
      o.admin_qty = isNaN(val) ? null : val;
    } else {
      // Создаём новую запись (админ заполняет за ресторан)
      await db.rpc('veg_update_order', {
        session_id: activeSession.value.id,
        restaurant_number: restNum,
        product_id: parseInt(prodId),
        delivery_date: date,
        admin_qty: isNaN(val) ? null : val,
      });
      await refreshData();
    }
  } catch { toastStore.show('Ошибка сохранения', 'error'); }
}

async function saveNote(restNum, note) {
  if (!activeSession.value) return;
  try {
    await db.rpc('veg_save_note', {
      session_id: activeSession.value.id,
      restaurant_number: restNum,
      note,
    });
  } catch { toastStore.show('Ошибка', 'error'); }
}

function confirmDeleteOrders(restNum) {
  const date = selectedDate.value !== 'all' ? selectedDate.value : null;
  const dateLabel = date ? ` на ${fmtShortDate(date)}` : '';
  confirmModal.value = {
    title: 'Удалить заявку',
    text: `Удалить заявк${date ? 'у' : 'и'} ресторана ${restNum}${dateLabel}?`,
    ok: 'Удалить',
    danger: true,
    action: async () => {
      try {
        await db.rpc('veg_delete_restaurant_orders', {
          session_id: activeSession.value.id,
          restaurant_number: restNum,
          delivery_date: date,
        });
        toastStore.show('Заявка удалена');
        await refreshData();
      } catch { toastStore.show('Ошибка удаления', 'error'); }
    },
  };
}

// ═══ Excel ═══
async function exportExcel() {
  if (!sessionData.value) return;
  const XLSX = await import('xlsx-js-style');
  const products = sessionData.value.products || [];
  const dates = visibleDates.value;

  const border = {
    top: { style: 'thin', color: { rgb: 'BDBDBD' } },
    bottom: { style: 'thin', color: { rgb: 'BDBDBD' } },
    left: { style: 'thin', color: { rgb: 'BDBDBD' } },
    right: { style: 'thin', color: { rgb: 'BDBDBD' } },
  };
  const headerStyle = {
    font: { bold: true, color: { rgb: 'FFFFFF' }, sz: 11, name: 'Calibri' },
    fill: { fgColor: { rgb: '2E7D32' } },
    alignment: { horizontal: 'center', vertical: 'center', wrapText: true },
    border,
  };
  const headerRestStyle = { ...headerStyle, alignment: { horizontal: 'left', vertical: 'center', wrapText: true } };
  const titleStyle = {
    font: { bold: true, sz: 14, name: 'Calibri', color: { rgb: '2E7D32' } },
    alignment: { horizontal: 'left' },
  };
  const restStyle = {
    font: { bold: true, sz: 11, name: 'Calibri' },
    alignment: { horizontal: 'left', vertical: 'center' },
    border,
  };
  const cityStyle = { font: { sz: 10, color: { rgb: '666666' }, name: 'Calibri' }, border, alignment: { vertical: 'center' } };
  const qtyStyle = {
    font: { sz: 11, name: 'Calibri' },
    alignment: { horizontal: 'center', vertical: 'center' },
    border,
  };
  const qtyFilledStyle = {
    ...qtyStyle,
    font: { bold: true, sz: 11, name: 'Calibri' },
    fill: { fgColor: { rgb: 'E8F5E9' } },
  };
  const adminQtyStyle = {
    ...qtyStyle,
    font: { bold: true, sz: 11, name: 'Calibri', color: { rgb: 'D62700' } },
    fill: { fgColor: { rgb: 'FFEBEE' } },
  };
  const noteStyle = { font: { sz: 10, italic: true, name: 'Calibri', color: { rgb: '555555' } }, border, alignment: { vertical: 'center' } };
  const vmStyle = {
    ...restStyle,
    fill: { fgColor: { rgb: 'E3F2FD' } },
  };
  const evenRowBg = { fgColor: { rgb: 'F5F5F5' } };

  // Title row
  const titleRow = [activeSession.value.name];

  // Header row
  const header = ['№', 'Город / Адрес'];
  for (const dd of dates) {
    for (const p of products) {
      header.push(`${p.product_name}\n${fmtShortDate(dd)}`);
    }
  }
  header.push('Пометка');

  const aoa = [titleRow, header];
  for (const row of filteredRows.value) {
    const vmLabel = row.isVM ? ` (ВМ)` : '';
    const r = [row.number + vmLabel, `${row.city}${row.address ? ', ' + row.address : ''}`];
    for (const dd of dates) {
      for (const p of products) {
        const admin = getCellAdmin(row.number, dd, p.id);
        const qty = admin !== null ? admin : getCellQty(row.number, dd, p.id);
        const num = parseFloat(qty);
        r.push(isNaN(num) ? '' : num);
      }
    }
    r.push(row.note || '');
    aoa.push(r);
  }

  // Totals row
  const totalRow = ['ИТОГО', ''];
  for (const dd of dates) {
    for (const p of products) {
      let sum = 0;
      for (const row of filteredRows.value) {
        const admin = getCellAdmin(row.number, dd, p.id);
        const qty = admin !== null ? admin : getCellQty(row.number, dd, p.id);
        const num = parseFloat(qty);
        if (!isNaN(num)) sum += num;
      }
      totalRow.push(sum || '');
    }
  }
  totalRow.push('');
  aoa.push(totalRow);

  const ws = XLSX.utils.aoa_to_sheet(aoa);

  // Style title
  const titleCell = ws[XLSX.utils.encode_cell({ r: 0, c: 0 })];
  if (titleCell) titleCell.s = titleStyle;
  // Merge title
  ws['!merges'] = [{ s: { r: 0, c: 0 }, e: { r: 0, c: header.length - 1 } }];

  // Style header (row 1)
  for (let c = 0; c < header.length; c++) {
    const cell = ws[XLSX.utils.encode_cell({ r: 1, c })];
    if (cell) cell.s = c === 0 || c === 1 ? headerRestStyle : headerStyle;
  }

  // Style data rows
  const dataRows = filteredRows.value;
  for (let ri = 0; ri < dataRows.length; ri++) {
    const row = dataRows[ri];
    const r = ri + 2; // offset for title + header
    const isEven = ri % 2 === 1;
    // Restaurant number
    const numCell = ws[XLSX.utils.encode_cell({ r, c: 0 })];
    if (numCell) numCell.s = row.isVM ? vmStyle : { ...restStyle, ...(isEven ? { fill: evenRowBg } : {}) };
    // City
    const cityCell = ws[XLSX.utils.encode_cell({ r, c: 1 })];
    if (cityCell) cityCell.s = { ...cityStyle, ...(isEven ? { fill: evenRowBg } : {}) };
    // Quantities
    for (let c = 2; c < header.length - 1; c++) {
      const cell = ws[XLSX.utils.encode_cell({ r, c })];
      if (!cell) continue;
      const val = cell.v;
      // Check if this is admin-modified
      const dateIdx = Math.floor((c - 2) / products.length);
      const prodIdx = (c - 2) % products.length;
      const dd = dates[dateIdx];
      const prod = products[prodIdx];
      const isAdmin = dd && prod && getCellAdmin(row.number, dd, prod.id) !== null;
      if (isAdmin) cell.s = adminQtyStyle;
      else if (val !== '' && val !== 0) cell.s = { ...qtyFilledStyle, ...(isEven ? { fill: { fgColor: { rgb: 'E8F5E9' } } } : {}) };
      else cell.s = { ...qtyStyle, ...(isEven ? { fill: evenRowBg } : {}) };
    }
    // Note
    const noteCell = ws[XLSX.utils.encode_cell({ r, c: header.length - 1 })];
    if (noteCell) noteCell.s = { ...noteStyle, ...(isEven ? { fill: evenRowBg } : {}) };
  }

  // Style totals row
  const totR = dataRows.length + 2;
  const totalStyle = {
    font: { bold: true, sz: 12, name: 'Calibri', color: { rgb: '2E7D32' } },
    fill: { fgColor: { rgb: 'C8E6C9' } },
    alignment: { horizontal: 'center', vertical: 'center' },
    border: { top: { style: 'medium', color: { rgb: '2E7D32' } }, bottom: { style: 'medium', color: { rgb: '2E7D32' } }, left: border.left, right: border.right },
  };
  for (let c = 0; c < header.length; c++) {
    const cell = ws[XLSX.utils.encode_cell({ r: totR, c })];
    if (cell) cell.s = c === 0 ? { ...totalStyle, alignment: { horizontal: 'left' } } : totalStyle;
  }

  // Column widths
  ws['!cols'] = [
    { wch: 14 }, { wch: 22 },
    ...Array(header.length - 3).fill({ wch: 14 }),
    { wch: 22 },
  ];
  ws['!rows'] = [{ hpx: 28 }, { hpx: 36 }];

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Овощи');
  XLSX.writeFile(wb, `Овощи_${activeSession.value.name.replace(/[^a-zA-Zа-яА-Я0-9]/g, '_')}.xlsx`);
}

// ═══ Schedule tab ═══
const allRestaurantsForSchedule = ref([]);

async function loadSchedule() {
  if (Object.keys(scheduleMap).length && allRestaurantsForSchedule.value.length) return;
  scheduleLoading.value = true;
  try {
    const [{ data: rests }, { data: sched }, { data: dlRules }] = await Promise.all([
      db.rpc('veg_get_restaurants', {}),
      db.rpc('veg_get_schedule_all', {}),
      db.rpc('veg_get_deadlines', {}),
    ]);
    allRestaurantsForSchedule.value = rests || [];
    for (const r of (rests || [])) {
      const rn = String(r.number);
      scheduleMap[rn] = sched?.[rn] || [];
    }
    // Deadline rules
    deadlineRules.value = (dlRules || []).map(r => ({
      delivery_dow: parseInt(r.delivery_dow),
      deadline_dow: parseInt(r.deadline_dow),
      deadline_time: (r.deadline_time || '12:00:00').slice(0, 5),
    }));
  } catch (e) { console.warn('[veg] schedule', e); }
  finally { scheduleLoading.value = false; }
}

const filteredScheduleRestaurants = computed(() => {
  let rests = allRestaurantsForSchedule.value;
  if (scheduleFilter.value) {
    const q = scheduleFilter.value.toLowerCase();
    rests = rests.filter(r =>
      String(r.number).includes(q) ||
      (r.city || '').toLowerCase().includes(q) ||
      (r.address || '').toLowerCase().includes(q)
    );
  }
  return rests;
});

function toggleScheduleDay(restNum, day) {
  const rn = String(restNum);
  if (!scheduleMap[rn]) scheduleMap[rn] = [];
  const idx = scheduleMap[rn].indexOf(day);
  if (idx >= 0) scheduleMap[rn].splice(idx, 1);
  else scheduleMap[rn].push(day);
}

async function saveScheduleAll() {
  scheduleSaving.value = true;
  try {
    const schedule = [];
    for (const [rn, days] of Object.entries(scheduleMap)) {
      schedule.push({ restaurant_number: rn, days });
    }
    await db.rpc('veg_save_schedule', { schedule });
    toastStore.show('Расписание сохранено');
  } catch { toastStore.show('Ошибка сохранения', 'error'); }
  finally { scheduleSaving.value = false; }
}
</script>

<style scoped>
.veg { padding: 0; color: var(--text, #333); }
.veg-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
.veg-title { font-size: 20px; font-weight: 800; color: var(--bk-brown, #502314); margin: 0; }

/* Tabs */
.veg-tabs {
  display: flex; gap: 0; margin-bottom: 16px;
  background: var(--bg, #f5f5f5); border-radius: 10px; padding: 3px;
  border: 1.5px solid var(--border-light, #eee); width: fit-content;
}
.veg-tab {
  padding: 7px 16px; border-radius: 8px; font-size: 13px; font-weight: 600;
  font-family: inherit; cursor: pointer; transition: all .15s;
  border: none; background: none; color: var(--text-muted, #999);
}
.veg-tab.active {
  background: var(--card, #fff); color: #2E7D32;
  box-shadow: 0 1px 3px rgba(0,0,0,.08);
}

/* Buttons */
.veg-btn {
  padding: 8px 14px; border-radius: 8px; font-size: 13px; font-weight: 600;
  font-family: inherit; cursor: pointer; transition: all .15s; border: 1.5px solid var(--border-light, #ddd);
  background: var(--card, #fff); color: var(--text, #333);
}
.veg-btn.fill { background: #2E7D32; color: #fff; border-color: #2E7D32; }
.veg-btn.fill:hover { background: #1B5E20; }
.veg-btn.outline:hover { background: var(--bg, #f5f5f5); }
.veg-btn.sm { padding: 5px 10px; font-size: 12px; }
.veg-btn.red-text { color: #D62700; }
.veg-btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* Sessions list */
.veg-list { display: flex; flex-direction: column; gap: 8px; }
.veg-empty { text-align: center; color: var(--text-muted, #999); padding: 32px 0; font-size: 14px; }
.veg-card {
  background: var(--card, #fff); border-radius: 10px; padding: 14px 16px;
  border: 1.5px solid var(--border-light, #eee); cursor: pointer; transition: all .15s;
}
.veg-card:hover { border-color: #A5D6A7; box-shadow: 0 2px 8px rgba(0,0,0,.05); }
.veg-card.closed { opacity: 0.6; }
.veg-card-top { display: flex; align-items: center; gap: 8px; }
.veg-card-name { font-size: 15px; font-weight: 700; color: var(--text, #333); }
.veg-card-meta { font-size: 12px; color: var(--text-muted, #999); margin-top: 4px; }

.veg-tag {
  font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 10px;
  text-transform: uppercase; letter-spacing: 0.3px;
}
.veg-tag.green { background: #E8F5E9; color: #2E7D32; }
.veg-tag.gray { background: #F5F5F5; color: #999; }

/* Detail bar */
.veg-detail-bar {
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
  margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1.5px solid var(--border-light, #eee);
}
.veg-detail-info { display: flex; align-items: center; gap: 8px; }
.veg-detail-name { font-size: 16px; font-weight: 700; color: var(--text, #333); }
.veg-detail-actions { display: flex; gap: 6px; margin-left: auto; flex-wrap: wrap; }

/* Token */
.veg-token { background: #E8F5E9; border-radius: 10px; padding: 12px; margin-bottom: 16px; }
.veg-token-label { font-size: 12px; font-weight: 600; color: #2E7D32; margin-bottom: 6px; }
.veg-token-row { display: flex; gap: 6px; }
.veg-token-input {
  flex: 1; padding: 6px 10px; border: 1px solid #C8E6C9; border-radius: 6px;
  font-size: 12px; font-family: monospace; background: #fff; min-width: 0; color: #333;
}
.veg-existing-token {
  background: #F1F8E9; border-radius: 8px; padding: 8px 10px; margin-bottom: 8px;
}
.veg-existing-token-meta { font-size: 11px; color: #666; }

/* Summary */
.veg-summary {
  display: flex; align-items: center; gap: 20px; flex-wrap: wrap;
  padding: 12px 0; border-bottom: 1.5px solid var(--border-light, #eee); margin-bottom: 12px;
}
.veg-summary-num { font-size: 20px; font-weight: 800; color: #2E7D32; }
.veg-summary-lbl { font-size: 11px; color: var(--text-muted, #999); }

/* Filter */
.veg-filter-bar { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; align-items: center; }
.veg-input {
  padding: 7px 10px; border: 1.5px solid var(--border-light, #ddd); border-radius: 8px;
  font-size: 13px; font-family: inherit; background: var(--card, #fff); color: var(--text, #333);
}
.veg-input:focus { outline: none; border-color: #66BB6A; }
.veg-filter-input { flex: 1; min-width: 180px; }
.veg-filter-select { min-width: 140px; }
.veg-filter-check { font-size: 13px; display: flex; align-items: center; gap: 4px; white-space: nowrap; cursor: pointer; }

/* Dates row */
.veg-dates-row { display: flex; align-items: center; gap: 6px; margin-bottom: 10px; flex-wrap: wrap; }
.veg-dates-label { font-size: 12px; font-weight: 600; color: #555; }
.veg-date-chip {
  font-size: 12px; font-weight: 600; padding: 5px 12px; border-radius: 8px;
  background: #f5f5f5; color: #555; border: 1.5px solid #ddd;
  cursor: pointer; font-family: inherit; transition: all 0.15s;
}
.veg-date-chip:hover { background: #E8F5E9; border-color: #A5D6A7; }
.veg-date-chip.active { background: #2E7D32; color: #fff; border-color: #2E7D32; }

/* Table */
.veg-tbl-wrap {
  overflow-x: auto; margin-bottom: 16px;
  border: 2px solid #C8E6C9; border-radius: 10px;
}
.veg-tbl {
  width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px;
  min-width: 600px; color: #333;
}
.veg-tbl th {
  background: #2E7D32; padding: 10px 8px; text-align: left; font-weight: 700;
  font-size: 11px; white-space: nowrap; color: #fff;
  position: sticky; top: 0; z-index: 1;
  border-bottom: 2px solid #1B5E20;
  border-right: 1px solid rgba(255,255,255,0.2);
}
.veg-tbl th:last-child { border-right: none; }
.veg-tbl td {
  padding: 7px 8px; border-bottom: 1px solid #E0E0E0;
  border-right: 1px solid #EEEEEE; vertical-align: middle; color: #333;
}
.veg-tbl td:last-child { border-right: none; }
.veg-tbl tbody tr:nth-child(even) { background: #F9FBF9; }
.veg-tbl tbody tr:hover { background: #E8F5E9; }
.veg-row-missing { opacity: 0.4; }

.veg-th-rest { min-width: 160px; }
.veg-th-note { min-width: 130px; }
.veg-th-qty { text-align: center; min-width: 75px; }
.veg-th-prod { font-size: 11px; font-weight: 700; color: #fff; }
.veg-th-date { font-size: 9px; color: rgba(255,255,255,0.7); font-weight: 400; }

.veg-td-rest { white-space: nowrap; border-right: 2px solid #C8E6C9 !important; position: relative; }
.veg-del-row {
  display: none; position: absolute; right: 4px; top: 50%; transform: translateY(-50%);
  width: 18px; height: 18px; border-radius: 4px; border: none;
  background: #FFEBEE; color: #D62700; font-size: 12px; cursor: pointer;
  align-items: center; justify-content: center; padding: 0; line-height: 1;
}
.veg-td-rest:hover .veg-del-row { display: flex; }
.veg-del-row:hover { background: #D62700; color: #fff; }
.veg-rest-num {
  font-weight: 800; margin-right: 6px; color: #2E7D32;
  display: inline-block; min-width: 24px;
}
.veg-rest-addr { font-size: 11px; color: #666; }
.veg-vm-badge {
  font-size: 9px; font-weight: 700; padding: 1px 5px; border-radius: 4px;
  background: #E3F2FD; color: #1565C0; margin-left: 6px;
}

.veg-td-note { min-width: 130px; border-left: 2px solid #C8E6C9 !important; }
.veg-note-input {
  width: 100%; padding: 4px 6px; border: 1px solid #ddd; border-radius: 5px;
  font-size: 12px; font-family: inherit; background: #FAFAFA; color: #333;
}
.veg-note-input:focus { border-color: #66BB6A; background: #fff; outline: none; box-shadow: 0 0 0 2px rgba(102,187,106,0.15); }

.veg-td-qty { text-align: center; cursor: pointer; min-width: 60px; }
.veg-td-no-delivery { background: #f0f0f0 !important; cursor: default !important; opacity: 0.4; }
.veg-qty { font-weight: 700; color: #333; }
.veg-qty-admin { font-weight: 800; color: #D62700; }
.veg-qty-empty { color: #ccc; font-size: 14px; }
.veg-prev-day { display: block; font-size: 10px; color: #7E57C2; font-weight: 600; line-height: 1; margin-top: 2px; white-space: nowrap; }
.veg-cell-input {
  width: 60px; padding: 4px 4px; border: 2px solid #66BB6A;
  border-radius: 6px; font-size: 13px; font-weight: 700; text-align: center;
  font-family: inherit; color: #333; background: #fff;
  box-shadow: 0 0 0 3px rgba(102,187,106,0.2);
}

/* Missing */
.veg-missing { margin-top: 16px; padding: 12px; background: #FFF8E1; border-radius: 10px; }
.veg-missing h3 { font-size: 14px; margin: 0 0 8px; color: #F57F17; }
.veg-missing-list { display: flex; flex-wrap: wrap; gap: 6px; }
.veg-missing-item {
  font-size: 12px; padding: 3px 8px; background: #fff; border-radius: 6px;
  border: 1px solid #FFE082; color: #555;
}
.veg-copy-missing { margin-top: 16px; text-align: center; padding-bottom: 20px; }

/* Schedule */
.veg-schedule { }
.veg-section-title { font-size: 15px; font-weight: 700; color: #333; margin: 0 0 4px; }
.veg-schedule-hint { font-size: 13px; color: var(--text-muted, #888); margin: 0 0 12px; }
.veg-deadlines-block {
  background: var(--card, #fff); border: 1.5px solid #E8F5E9; border-radius: 12px;
  padding: 14px; margin-bottom: 8px;
}
.veg-deadlines-grid { display: flex; flex-direction: column; gap: 6px; margin-bottom: 10px; }
.veg-deadline-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.veg-dl-label { font-size: 13px; font-weight: 600; color: #555; min-width: 160px; }
.veg-dl-select, .veg-dl-time {
  padding: 5px 8px; border: 1.5px solid #ddd; border-radius: 8px; font-size: 13px;
  font-family: inherit; background: #fff; color: #333;
}
.veg-dl-select { width: 70px; }
.veg-dl-time { width: 90px; }
.veg-dl-actions { display: flex; gap: 8px; align-items: center; }
.veg-schedule-filter { display: flex; gap: 8px; margin-bottom: 12px; align-items: center; }
.veg-schedule-tbl th { text-align: center; padding: 10px 6px; }
.veg-td-rest-sch { white-space: nowrap; min-width: 160px; border-right: 2px solid #C8E6C9 !important; }
.veg-td-check { text-align: center; cursor: pointer; padding: 8px 6px; }
.veg-td-check input { cursor: pointer; width: 16px; height: 16px; accent-color: #2E7D32; }

@media (max-width: 768px) {
  .veg-detail-bar { flex-direction: column; align-items: flex-start; }
  .veg-detail-actions { margin-left: 0; }
  .veg-summary { gap: 12px; }
}
</style>

<!-- Global styles for Teleport modals -->
<style>
/* --- Создание сессии: блок товара --- */
.veg-product-block { margin-bottom: 4px; }
.veg-product-row {
  display: grid !important;
  grid-template-columns: 1fr 48px 44px 24px;
  gap: 5px;
  align-items: center;
}
.veg-product-name,
.veg-product-select,
.veg-product-mult {
  padding: 7px 8px !important; border: 1.5px solid #ccc !important; border-radius: 8px !important;
  font-family: inherit !important; background: #fff !important; color: #333 !important;
  box-sizing: border-box !important; height: 34px !important; width: 100% !important;
}
.veg-product-name { font-size: 14px !important; }
.veg-product-select, .veg-product-mult { font-size: 12px !important; text-align: center; padding: 5px 2px !important; }
.veg-product-name:focus, .veg-product-select:focus, .veg-product-mult:focus {
  border-color: #66BB6A !important; outline: none; box-shadow: 0 0 0 2px rgba(102,187,106,0.2);
}
.veg-product-del {
  width: 24px; height: 24px; border-radius: 6px; border: 1px solid #ddd;
  background: #fff; color: #D62700; font-size: 14px; cursor: pointer;
  display: flex; align-items: center; justify-content: center; padding: 0;
}
.veg-product-del:hover { background: #FFEBEE; }

/* --- Общие стили для модальных окон veg --- */
.modal .veg-input {
  padding: 7px 10px !important; border: 1.5px solid #ddd !important; border-radius: 8px !important;
  font-size: 13px !important; font-family: inherit; background: #fff !important; color: #333 !important;
  box-sizing: border-box; width: 100%;
}
.modal .veg-input:focus { outline: none; border-color: #66BB6A !important; box-shadow: 0 0 0 2px rgba(102,187,106,0.2); }
.modal .veg-btn {
  padding: 8px 14px; border-radius: 8px; font-size: 13px; font-weight: 600;
  font-family: inherit; cursor: pointer; transition: all .15s; border: 1.5px solid #ddd;
  background: #fff; color: #333;
}
.modal .veg-btn.fill { background: #2E7D32; color: #fff; border-color: #2E7D32; }
.modal .veg-btn.fill:hover { background: #1B5E20; }
.modal .veg-btn.outline:hover { background: #f5f5f5; }
.modal .veg-btn.sm { padding: 5px 10px; font-size: 12px; }
.modal .veg-btn.red-text { color: #D62700; }
.modal .veg-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.modal .veg-token-row { display: flex; gap: 6px; }
.modal .veg-token-input {
  flex: 1; padding: 6px 10px; border: 1px solid #C8E6C9; border-radius: 6px;
  font-size: 12px; font-family: monospace; background: #fff; min-width: 0; color: #333;
}
.modal .veg-existing-token {
  background: #F1F8E9; border-radius: 8px; padding: 8px 10px; margin-bottom: 8px;
}
.modal .veg-existing-token-meta { font-size: 11px; color: #666; }
</style>
