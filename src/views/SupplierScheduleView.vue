<template>
  <div class="ssv-view" @click="onOutsideClick">
    <div class="ssv-top">
      <div class="ssv-top-left">
        <h1 class="ssv-title">График поставок</h1>
        <span v-if="activeSupplier" class="ssv-count">
          {{ filledCount }} из {{ allRestaurants.length }} настроено
          <span v-if="subscribedCount" class="ssv-count-sub">· подписано {{ subscribedCount }}</span>
        </span>
      </div>
      <div class="ssv-top-actions">
        <label class="ssv-supplier-pick">
          <span>Поставщик:</span>
          <select v-model="selectedSupplierId" class="ssv-select ssv-select-strong">
            <option value="">— выбрать —</option>
            <option v-for="s in suppliers" :key="s.id" :value="s.id">
              {{ s.short_name }}{{ s.so_enabled ? ' — через портал' : '' }}
            </option>
          </select>
        </label>
        <input v-model="searchQuery" type="text" class="ssv-search" placeholder="Поиск: №, город, адрес..." :disabled="!selectedSupplierId" />
        <button v-if="canEdit && activeSupplier" class="ssv-btn-ghost" @click="openDefaultsModal(activeSupplier)">
          Дефолты ({{ (defaults[activeSupplier.id] || []).length }})
        </button>
        <button v-if="canEdit && activeSupplier" class="ssv-btn-ghost" @click="openTempScheduleModal">
          Временный график
        </button>
      </div>
    </div>

    <div v-if="loading" class="ssv-loading"><BurgerSpinner text="Загрузка..." /></div>

    <div v-else-if="!selectedSupplierId" class="ssv-empty">
      <p>Выберите поставщика, чтобы увидеть и настроить расписание поставок.</p>
      <p v-if="!suppliers.length">Список поставщиков пуст — добавьте их в разделе «База → Поставщики».</p>
    </div>

    <div v-else-if="!filteredRestaurants.length" class="ssv-empty">
      <p>По запросу ничего не найдено.</p>
    </div>

    <div v-else class="ssv-board">
      <div class="ssv-supplier-bar">
        <span class="ssv-supplier-name">{{ activeSupplier.short_name }}</span>
        <span class="ssv-tag" :class="activeSupplier.so_enabled ? 'ssv-tag-so' : 'ssv-tag-local'">
          {{ activeSupplier.so_enabled ? 'через портал' : 'локальный' }}
        </span>
        <label class="ssv-order-url" title="Если задана — попадёт ссылкой «Оформить заявку» в Telegram-напоминание о подаче заявки">
          🔗 Ссылка на заявку:
          <input v-model="orderUrlDraft" type="url" placeholder="https://… (необязательно)"
                 @blur="saveOrderUrl" @keydown.enter="$event.target.blur()" />
        </label>
      </div>

      <table class="ssv-table">
        <thead>
          <tr>
            <th class="ssv-th-rest">Ресторан</th>
            <th v-for="wd in WEEKDAYS_NUM" :key="wd" class="ssv-th-day">
              <div class="ssv-th-name">{{ weekdayShort(wd) }}</div>
              <div class="ssv-th-sub">поставка</div>
            </th>
            <th class="ssv-th-trash"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="rest in filteredRestaurants" :key="rest.restaurant_id" :class="{ 'is-unset': !hasAnyDay(rest) }">
            <td class="ssv-td-rest">
              <div class="ssv-rest-row1">
                <span class="ssv-rest-num">№{{ rest.restaurant_number }}</span>
                <span v-if="subscriptionFor(rest)?.is_enabled"
                      class="ssv-rest-sub"
                      :class="{ 'has-tg': (subscriptionFor(rest)?.tg_names || []).length }"
                      :title="subscriptionTitle(rest)">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0 1 12 0v5l1.5 2.5h-15L6 14V9Z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>
                  <span v-if="(subscriptionFor(rest)?.tg_names || []).length" class="ssv-rest-sub-count">{{ subscriptionFor(rest).tg_names.length }}</span>
                </span>
              </div>
              <span class="ssv-rest-addr" :title="fullAddress(rest)">{{ shortAddress(rest) }}</span>
              <span class="ssv-rest-le" :title="rest.legal_entity">{{ shortLegalEntity(rest.legal_entity) }}</span>
            </td>
            <td v-for="wd in WEEKDAYS_NUM" :key="wd" class="ssv-td-cell"
                :class="cellClass(rest, wd)"
                @click.stop="onCellClick(activeSupplier, rest, wd, $event)">
              <template v-if="rest.days[wd]">
                <div class="ssv-cell-content">
                  <span class="ssv-cell-orderlabel">заявка</span>
                  <span class="ssv-cell-day">{{ weekdayShort(rest.days[wd].order_day) }}</span>
                  <span class="ssv-cell-time" :class="{ 'is-dim': !rest.days[wd].deadline_override }">
                    {{ effectiveDeadline(activeSupplier, rest.days[wd]) || '' }}
                  </span>
                </div>
              </template>
              <span v-else class="ssv-cell-empty">—</span>

              <div v-if="popover.key === cellKey(rest, wd)" class="ssv-popover" @click.stop>
                <div class="ssv-pop-title">№{{ rest.restaurant_number }} — поставка в {{ weekdayFull(wd) }}</div>
                <label class="ssv-pop-toggle">
                  <input type="checkbox" v-model="popover.is_active" />
                  <span>Поставка в этот день</span>
                </label>
                <div v-if="popover.is_active" class="ssv-pop-body">
                  <div class="ssv-pop-field">
                    <label>День подачи заявки</label>
                    <select v-model.number="popover.order_day">
                      <option v-for="d in ORDER_DAY_NUM" :key="d" :value="d">{{ weekdayFull(d) }}</option>
                    </select>
                  </div>
                  <div class="ssv-pop-field">
                    <label>Дедлайн заявки</label>
                    <input type="time" v-model="popover.deadline_time" :placeholder="defaultDeadlineHint(activeSupplier, wd) || '—'" />
                    <span class="ssv-pop-hint" v-if="defaultDeadlineHint(activeSupplier, wd)">
                      пусто = дефолт {{ defaultDeadlineHint(activeSupplier, wd) }}
                    </span>
                  </div>
                  <div class="ssv-pop-field ssv-pop-field-wide">
                    <label>Времена напоминаний</label>
                    <ReminderTimesEditor v-model="popover.reminder_times" :fallback="defaultReminderTimesFor(activeSupplier, wd)" />
                  </div>
                </div>
                <div class="ssv-pop-actions">
                  <button class="ssv-btn-ghost" @click="closePopover">Отмена</button>
                  <button class="ssv-btn-primary" :disabled="popover.saving" @click="savePopover(activeSupplier, rest, wd)">
                    {{ popover.saving ? 'Сохранение...' : 'Сохранить' }}
                  </button>
                </div>
              </div>
            </td>
            <td class="ssv-td-trash">
              <button v-if="canDelete && hasAnyDay(rest)" class="ssv-icon-btn ssv-icon-danger"
                      @click="onDeleteRestaurant(activeSupplier, rest)" title="Удалить расписание этого ресторана">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Модалка дефолтных дедлайнов поставщика -->
    <div v-if="defaultsModal.show" class="ssv-modal-backdrop" @click.self="closeDefaultsModal">
      <div class="ssv-modal ssv-modal-wide">
        <div class="ssv-modal-header">
          <h3>Дефолтные дедлайны — {{ defaultsModal.supplier_name }}</h3>
          <button class="ssv-modal-close" @click="closeDefaultsModal">×</button>
        </div>
        <div class="ssv-modal-body">
          <p class="ssv-modal-hint">
            Эти правила применяются если у конкретного ресторана нет переопределения. Формат:
            <strong>«при доставке в день X — крайний срок заявки до дня Y, время Z»</strong>.
          </p>
          <table class="ssv-defaults-table">
            <thead>
              <tr>
                <th>Доставка в</th>
                <th>Крайний срок (день)</th>
                <th>Время</th>
                <th>Времена напоминаний</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(rule, idx) in defaultsModal.rules" :key="'r'+idx">
                <td>
                  <select v-model.number="rule.delivery_dow">
                    <option v-for="d in WEEKDAYS_NUM" :key="d" :value="d">{{ weekdayFull(d) }}</option>
                  </select>
                </td>
                <td>
                  <select v-model.number="rule.deadline_dow">
                    <option v-for="d in WEEKDAYS_NUM" :key="d" :value="d">{{ weekdayFull(d) }}</option>
                  </select>
                </td>
                <td><input type="time" v-model="rule.deadline_time" /></td>
                <td class="ssv-td-reminders">
                  <ReminderTimesEditor v-model="rule.reminder_times" />
                </td>
                <td><button class="ssv-icon-btn ssv-icon-danger" @click="removeDefaultRule(idx)" title="Удалить правило">×</button></td>
              </tr>
              <tr v-if="!defaultsModal.rules.length">
                <td colspan="5" class="ssv-defaults-empty">Правил пока нет.</td>
              </tr>
            </tbody>
          </table>
          <button class="ssv-btn-ghost ssv-add-rule" @click="addDefaultRule">+ Добавить правило</button>
        </div>
        <div class="ssv-modal-footer">
          <button class="ssv-btn" @click="closeDefaultsModal">Отмена</button>
          <button class="ssv-btn ssv-btn-primary" :disabled="defaultsModal.saving" @click="saveDefaults">
            {{ defaultsModal.saving ? 'Сохранение...' : 'Сохранить' }}
          </button>
        </div>
      </div>
    </div>

    <ConfirmModal v-if="confirmModal.show" :title="confirmModal.title" :message="confirmModal.message"
      @confirm="onConfirm" @cancel="onCancel" />

    <SupplierTempScheduleModal
      v-if="tempScheduleOpen && activeSupplier"
      :supplier="activeSupplier"
      :restaurants="tempScheduleRestaurants"
      :main-schedules="tempScheduleMainItems"
      @saved="onTempScheduleSaved"
      @close="tempScheduleOpen = false"
    />
  </div>
</template>

<script setup>
import { ref, computed, defineAsyncComponent, onMounted, watch } from 'vue';
import { db } from '@/lib/apiClient.js';
import { useUserStore } from '@/stores/userStore.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { useConfirm } from '@/composables/useConfirm.js';
import { getEntityGroupCode } from '@/lib/legalEntities.js';

const ConfirmModal = defineAsyncComponent(() => import('@/components/modals/ConfirmModal.vue'));
const ReminderTimesEditor = defineAsyncComponent(() => import('@/components/ui/ReminderTimesEditor.vue'));
const SupplierTempScheduleModal = defineAsyncComponent(() => import('@/components/modals/SupplierTempScheduleModal.vue'));

const userStore = useUserStore();
const orderStore = useOrderStore();
const toast = useToastStore();
const { confirmModal, confirm, onConfirm, onCancel } = useConfirm();

// Активная группа юр.лиц (BK_VM / PS) — берётся из текущего юр.лица закупок
const currentGroup = computed(() => getEntityGroupCode(orderStore.settings.legalEntity));

const loading = ref(true);
const rows = ref([]);
const defaults = ref({});
const subscriptions = ref({}); // { supplier_id: { restaurant_id: { is_enabled, telegram_enabled, tg_names } } }
const suppliers = ref([]);
const restaurants = ref([]);

const searchQuery = ref('');
const selectedSupplierId = ref('');

const canEdit = computed(() => userStore.hasAccess('supplier-schedule', 'edit'));
const canDelete = computed(() => userStore.hasAccess('supplier-schedule', 'full'));

const tempScheduleOpen = ref(false);
const tempScheduleRestaurants = computed(() => {
  if (!activeSupplier.value) return [];
  const grp = activeSupplier.value.legal_entity_group;
  return restaurants.value
    .filter(r => r.legal_entity_group === grp)
    .map(r => ({
      id: r.id,
      number: r.number,
      city: r.city || '',
      address: r.address || '',
      legal_entity_group: r.legal_entity_group,
    }));
});
const tempScheduleMainItems = computed(() => {
  if (!activeSupplier.value) return [];
  return rows.value
    .filter(row => row.supplier_id === activeSupplier.value.id && row.is_active == 1)
    .map(row => {
      const rest = restaurants.value.find(r => r.id === row.restaurant_id);
      return {
        restaurant_number: rest ? rest.number : null,
        order_day: row.order_day,
        delivery_day: row.delivery_day,
        is_active: row.is_active,
      };
    })
    .filter(s => s.restaurant_number);
});

function openTempScheduleModal() {
  tempScheduleOpen.value = true;
}
async function onTempScheduleSaved(res) {
  if (res?.notified) {
    const n = res?.notify || {};
    const msg = `Уведомлено ${n.restaurants || 0} рест.: TG ${n.sent_tg || 0}, Push ${n.sent_push || 0}`;
    toast.success('Сохранено и уведомлено', msg);
  } else {
    toast.success('Сохранено', 'Временный график обновлён');
  }
  await loadData();
}

// Колонки таблицы = дни поставок (Пн..Сб). Воскресенья поставок не бывает.
const WEEKDAYS_NUM = [1,2,3,4,5,6];
// День заказа (когда подаётся заявка) может быть любым днём недели, включая Вс.
const ORDER_DAY_NUM = [1,2,3,4,5,6,7];
const WEEKDAYS_FULL = ['', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье'];
const WEEKDAYS_SHORT = ['', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
function weekdayFull(d) { return WEEKDAYS_FULL[d] || ''; }
function weekdayShort(d) { return WEEKDAYS_SHORT[d] || ''; }

function formatTime(t) { return t ? String(t).slice(0, 5) : ''; }

// Сокращение юрлица: «ООО "Бургер БК"» → «БК»; если в кавычках нет
// — берём первые буквы слов или весь оригинал, если короткий.
const LE_SHORT_MAP = {
  'ООО "Бургер БК"': 'БК',
  'ООО "Воглия Матта"': 'ВМ',
  'ООО "Пицца Стар"': 'ПС',
};
function shortLegalEntity(le) {
  if (!le) return '';
  if (LE_SHORT_MAP[le]) return LE_SHORT_MAP[le];
  // Универсально — кавычки «», " " или «»
  const m = le.match(/«([^»]+)»|"([^"]+)"/);
  if (m) return (m[1] || m[2]).trim();
  return le.length > 16 ? le.slice(0, 14) + '…' : le;
}

function shortAddress(rest) {
  const city = rest.restaurant_city || '';
  const addr = rest.restaurant_address || '';
  if (!city && !addr) return '';
  // Если адрес уже содержит город — не дублируем
  if (addr && city && addr.toLowerCase().includes(city.toLowerCase())) return addr;
  return [city, addr].filter(Boolean).join(', ');
}

function fullAddress(rest) {
  return [rest.restaurant_city, rest.restaurant_address].filter(Boolean).join(', ');
}

// Выбранный поставщик (объект)
const activeSupplier = computed(() => {
  if (!selectedSupplierId.value) return null;
  const s = suppliers.value.find(x => x.id === selectedSupplierId.value);
  return s || null;
});

// Опциональная ссылка на оформление заявки (для Telegram-напоминаний).
const orderUrlDraft = ref('');
watch(activeSupplier, (s) => { orderUrlDraft.value = s?.order_url || ''; }, { immediate: true });
async function saveOrderUrl() {
  const s = activeSupplier.value;
  if (!s) return;
  const val = orderUrlDraft.value.trim();
  if (val === (s.order_url || '')) return; // без изменений
  if (val && !/^https?:\/\//i.test(val)) { toast.error('Ссылка должна начинаться с http:// или https://', ''); return; }
  const { error } = await db.from('suppliers').update({ order_url: val || null }).eq('id', s.id);
  if (error) { toast.error('Не удалось сохранить ссылку', error.message || ''); return; }
  s.order_url = val || null; // локально, чтобы computed/watch были согласованы
  toast.success(val ? 'Ссылка сохранена' : 'Ссылка убрана', '');
}

// Все рестораны юр.лица (даже без расписания у выбранного поставщика).
// Расписание прикрепляется в days[].
const allRestaurants = computed(() => {
  if (!activeSupplier.value) return [];
  // Берём из общего списка ресторанов те, что в той же группе что и поставщик
  const supGroup = activeSupplier.value.legal_entity_group;
  const list = restaurants.value
    .filter(r => r.legal_entity_group === supGroup)
    .map(r => ({
      restaurant_id: r.id,
      restaurant_number: r.number,
      restaurant_city: r.city,
      restaurant_address: r.address || '',
      legal_entity: r.legal_entity,
      legal_entity_group: r.legal_entity_group,
      days: {},
    }));

  // Прикрепляем строки расписания (по выбранному поставщику)
  const byRid = new Map(list.map(r => [r.restaurant_id, r]));
  for (const row of rows.value) {
    if (row.supplier_id !== activeSupplier.value.id) continue;
    const rest = byRid.get(row.restaurant_id);
    if (rest) rest.days[row.delivery_day] = row;
  }

  list.sort((a, b) => (a.restaurant_number || 0) - (b.restaurant_number || 0));
  return list;
});

const filteredRestaurants = computed(() => {
  const q = searchQuery.value.trim().toLowerCase();
  if (!q) return allRestaurants.value;
  return allRestaurants.value.filter(r =>
    String(r.restaurant_number || '').includes(q)
    || (r.restaurant_city || '').toLowerCase().includes(q)
    || (r.restaurant_address || '').toLowerCase().includes(q)
  );
});

const filledCount = computed(() =>
  allRestaurants.value.filter(r => Object.keys(r.days || {}).length > 0).length
);

const subscribedCount = computed(() => {
  const sup = activeSupplier.value;
  if (!sup) return 0;
  const m = subscriptions.value[sup.id] || {};
  return Object.values(m).filter(s => s.is_enabled).length;
});

function subscriptionFor(rest) {
  const sup = activeSupplier.value;
  if (!sup) return null;
  const m = subscriptions.value[sup.id] || {};
  return m[rest.restaurant_id] || null;
}

function subscriptionTitle(rest) {
  const s = subscriptionFor(rest);
  if (!s || !s.is_enabled) return 'Ресторан не подписан на напоминания';
  const tg = (s.tg_names || []).length
    ? '\nTelegram: ' + s.tg_names.join(', ')
    : (s.telegram_enabled ? '\nTelegram: канал включён, но получатели не выбраны' : '');
  return 'Подписан на напоминания в кабинете' + tg;
}

function hasAnyDay(rest) {
  return Object.keys(rest.days || {}).length > 0;
}

function cellClass(rest, wd) {
  const row = rest.days[wd];
  if (!row) return 'is-empty';
  if (!Number(row.is_active)) return 'is-inactive';
  if (row.deadline_override) return 'is-override';
  return 'is-default';
}

function defaultsFor(supplier) {
  if (!supplier) return [];
  return defaults.value[supplier.id] || [];
}

function effectiveDeadline(supplier, row) {
  if (row.deadline_override) return formatTime(row.deadline_override);
  const dflt = defaultsFor(supplier).find(d => Number(d.delivery_dow) === Number(row.delivery_day));
  return dflt ? formatTime(dflt.deadline_time) : '';
}

function defaultDeadlineHint(supplier, deliveryDay) {
  const dflt = defaultsFor(supplier).find(d => Number(d.delivery_dow) === Number(deliveryDay));
  return dflt ? formatTime(dflt.deadline_time) : '';
}

function defaultReminderTimesFor(supplier, deliveryDay) {
  const dflt = defaultsFor(supplier).find(d => Number(d.delivery_dow) === Number(deliveryDay));
  return parseReminderTimes(dflt?.reminder_times);
}

// ─── Popover для ячейки ───
// Ключ ячейки = (ресторан, день поставки). В ячейке редактируется день заказа
// и дедлайн заявки. День поставки фиксирован выбранной колонкой.
const popover = ref({ key: null, supplier_id: '', restaurant_id: 0, delivery_day: 0, order_day: 1, original_order_day: null, is_active: true, deadline_time: '', reminder_times: [], saving: false });

function parseReminderTimes(raw) {
  if (!raw) return [];
  try {
    const arr = typeof raw === 'string' ? JSON.parse(raw) : raw;
    if (!Array.isArray(arr)) return [];
    return arr
      .filter(x => x && /^\d{1,2}:\d{2}/.test(x.time || ''))
      .map(x => ({ days_before: Number(x.days_before) | 0, time: String(x.time).slice(0, 5) }));
  } catch (e) { return []; }
}

function cellKey(rest, wd) { return `${rest.restaurant_id}-${wd}`; }

function onCellClick(supplier, rest, wd, event) {
  if (!canEdit.value || !supplier) return;
  if (popover.value.key === cellKey(rest, wd)) {
    closePopover();
    return;
  }
  const row = rest.days[wd];
  popover.value = {
    key: cellKey(rest, wd),
    supplier_id: supplier.id,
    restaurant_id: rest.restaurant_id,
    delivery_day: wd,
    order_day: row ? row.order_day : prevDay(wd),
    original_order_day: row ? row.order_day : null,
    is_active: row ? !!Number(row.is_active) : true,
    deadline_time: row && row.deadline_override ? formatTime(row.deadline_override) : '',
    reminder_times: parseReminderTimes(row?.reminder_times_override),
    saving: false,
  };
}

function prevDay(d) { return d <= 1 ? 7 : d - 1; }

function closePopover() { popover.value.key = null; }

function onOutsideClick() {
  if (popover.value.key) closePopover();
}

async function savePopover(supplier, rest, wd) {
  const row = rest.days[wd];
  popover.value.saving = true;

  // Сценарий «выключить»: убрать существующую запись
  if (!popover.value.is_active) {
    if (row) {
      const { error } = await db.rpc('delete_supplier_schedule_row', {
        supplier_id: supplier.id,
        restaurant_id: rest.restaurant_id,
        order_day: row.order_day,
      });
      popover.value.saving = false;
      if (error) { toast.error(error); return; }
      toast.success('Поставка удалена');
    } else {
      popover.value.saving = false;
    }
    closePopover();
    await loadData();
    return;
  }

  // Если поменялся order_day — старую запись удалить
  if (popover.value.original_order_day && popover.value.original_order_day !== popover.value.order_day) {
    const del = await db.rpc('delete_supplier_schedule_row', {
      supplier_id: supplier.id,
      restaurant_id: rest.restaurant_id,
      order_day: popover.value.original_order_day,
    });
    if (del.error) {
      popover.value.saving = false;
      toast.error(del.error);
      return;
    }
  }

  const { error } = await db.rpc('save_supplier_schedule_row', {
    supplier_id: supplier.id,
    restaurant_id: rest.restaurant_id,
    order_day: popover.value.order_day,
    delivery_day: wd,
    deadline_time: popover.value.deadline_time || null,
    reminder_times: popover.value.reminder_times || [],
    is_active: 1,
  });
  popover.value.saving = false;
  if (error) { toast.error(error); return; }
  toast.success('Сохранено');
  closePopover();
  await loadData();
}

// ─── Удалить все дни ресторана у выбранного поставщика ───
async function onDeleteRestaurant(supplier, rest) {
  const days = Object.values(rest.days || {});
  if (!days.length) return;
  const ok = await confirm(
    'Удаление расписания ресторана',
    `Удалить все ${days.length} ${dayWord(days.length)} расписания ресторана №${rest.restaurant_number} у поставщика «${supplier.short_name}»?`
  );
  if (!ok) return;
  for (const row of days) {
    await db.rpc('delete_supplier_schedule_row', {
      supplier_id: supplier.id,
      restaurant_id: rest.restaurant_id,
      order_day: row.order_day,
    });
  }
  toast.success('Расписание ресторана удалено');
  await loadData();
}
function dayWord(n) {
  const m100 = n % 100; const m10 = n % 10;
  if (m100 >= 11 && m100 <= 14) return 'дней';
  if (m10 === 1) return 'день';
  if (m10 >= 2 && m10 <= 4) return 'дня';
  return 'дней';
}

// ─── Дефолтные дедлайны (модалка) ───
const defaultsModal = ref({ show: false, supplier_id: '', supplier_name: '', rules: [], original: [], saving: false });

function openDefaultsModal(supplier) {
  if (!supplier) return;
  const rules = defaults.value[supplier.id] || [];
  defaultsModal.value = {
    show: true,
    supplier_id: supplier.id,
    supplier_name: supplier.short_name,
    rules: rules.map(d => ({
      delivery_dow: Number(d.delivery_dow),
      deadline_dow: Number(d.deadline_dow),
      deadline_time: formatTime(d.deadline_time),
      reminder_times: parseReminderTimes(d.reminder_times),
      _original_dow: Number(d.delivery_dow),
    })),
    original: rules.map(d => Number(d.delivery_dow)),
    saving: false,
  };
}
function closeDefaultsModal() { defaultsModal.value.show = false; }
function addDefaultRule() {
  const used = new Set(defaultsModal.value.rules.map(r => r.delivery_dow));
  const free = WEEKDAYS_NUM.find(d => !used.has(d)) || 1;
  defaultsModal.value.rules.push({ delivery_dow: free, deadline_dow: free, deadline_time: '14:00', reminder_times: [], _original_dow: null });
}
function removeDefaultRule(idx) {
  defaultsModal.value.rules.splice(idx, 1);
}

async function saveDefaults() {
  const dows = new Set();
  for (const r of defaultsModal.value.rules) {
    if (!r.delivery_dow || !r.deadline_dow || !r.deadline_time) {
      toast.error('Заполните все поля во всех правилах');
      return;
    }
    if (dows.has(r.delivery_dow)) {
      toast.error('Два правила для одного дня доставки');
      return;
    }
    dows.add(r.delivery_dow);
  }
  defaultsModal.value.saving = true;

  // Сначала удалить правила которых больше нет в списке
  const removed = defaultsModal.value.original.filter(d => !dows.has(d));
  for (const d of removed) {
    await db.rpc('delete_supplier_default_deadline', {
      supplier_id: defaultsModal.value.supplier_id,
      delivery_dow: d,
    });
  }
  // Если у правила сменился delivery_dow — старый тоже удалить
  for (const r of defaultsModal.value.rules) {
    if (r._original_dow && r._original_dow !== r.delivery_dow && !dows.has(r._original_dow)) {
      await db.rpc('delete_supplier_default_deadline', {
        supplier_id: defaultsModal.value.supplier_id,
        delivery_dow: r._original_dow,
      });
    }
  }
  // Затем сохранить актуальные
  for (const r of defaultsModal.value.rules) {
    const { error } = await db.rpc('save_supplier_default_deadline', {
      supplier_id: defaultsModal.value.supplier_id,
      delivery_dow: r.delivery_dow,
      deadline_dow: r.deadline_dow,
      deadline_time: r.deadline_time,
      reminder_times: r.reminder_times || [],
    });
    if (error) {
      toast.error(error);
      defaultsModal.value.saving = false;
      return;
    }
  }
  defaultsModal.value.saving = false;
  toast.success('Дефолтные дедлайны сохранены');
  closeDefaultsModal();
  await loadData();
}

async function loadData() {
  loading.value = true;
  const group = currentGroup.value;
  const [list, dir] = await Promise.all([
    db.rpc('list_supplier_schedules', { legal_entity_group: group }),
    db.rpc('list_supplier_schedule_directory', { legal_entity_group: group }),
  ]);
  if (list.data) {
    rows.value = list.data.rows || [];
    defaults.value = list.data.default_deadlines || {};
    subscriptions.value = list.data.subscriptions || {};
  }
  if (dir.data) {
    suppliers.value = dir.data.suppliers || [];
    restaurants.value = dir.data.restaurants || [];
  }
  loading.value = false;
}

// При смене текущего юр.лица перезагружаем данные
watch(currentGroup, () => { loadData(); });

onMounted(loadData);
</script>

<style scoped>
.ssv-view { padding: 16px 20px; }
.ssv-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
.ssv-top-left { display: flex; align-items: baseline; gap: 12px; }
.ssv-title { margin: 0; font-size: 22px; }
.ssv-count { font-size: 12px; color: #888; }
.ssv-top-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.ssv-search { padding: 7px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; width: 240px; }
.ssv-select { padding: 7px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; background: #fff; }
.ssv-btn { padding: 7px 14px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; cursor: pointer; background: #fff; }
.ssv-btn:hover { background: #f5f5f5; }
.ssv-btn-primary { padding: 7px 14px; border: 1px solid #E76F51; border-radius: 6px; font-size: 13px; cursor: pointer; background: #E76F51; color: #fff; }
.ssv-btn-primary:hover { background: #b52200; }
.ssv-btn-primary:disabled { opacity: 0.6; cursor: default; }
.ssv-btn-ghost { padding: 6px 12px; border: 1px solid #e2e2e2; border-radius: 6px; font-size: 12px; cursor: pointer; background: transparent; color: #555; }
.ssv-btn-ghost:hover { background: #fafafa; color: #E76F51; border-color: #E76F51; }

.ssv-loading { text-align: center; padding: 40px; color: #888; }
.ssv-empty { padding: 60px 24px; text-align: center; color: #888; background: #fafafa; border-radius: 8px; }

.ssv-supplier-pick { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #555; }
.ssv-supplier-pick > span { font-weight: 500; }
.ssv-select-strong { font-weight: 600; color: #2b2b2b; min-width: 220px; }

.ssv-board { background: #fff; border: 1px solid #dde3ea; border-radius: 10px; overflow: visible; box-shadow: 0 1px 3px rgba(16, 24, 40, 0.04); }
.ssv-supplier-bar { padding: 12px 16px; background: #fff7f3; border-bottom: 1px solid #f0d9cc; border-radius: 10px 10px 0 0; display: flex; align-items: center; gap: 10px; }
.ssv-supplier-name { font-size: 16px; font-weight: 700; color: #2b2b2b; }
.ssv-tag { font-size: 10px; padding: 2px 8px; border-radius: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
.ssv-tag-so { background: #e3f2fd; color: #1565c0; }
.ssv-tag-local { background: #fff4e0; color: #b35900; }
.ssv-order-url { margin-left: auto; display: flex; align-items: center; gap: 6px; font-size: 12px; color: #7a6a5f; }
.ssv-order-url input { width: 280px; max-width: 40vw; padding: 4px 8px; border: 1px solid #e0c6ac; border-radius: 6px; font-size: 12px; }
.ssv-order-url input:focus { outline: none; border-color: #d0a87c; }
@media (max-width: 640px) { .ssv-order-url { margin-left: 0; flex-basis: 100%; } .ssv-order-url input { flex: 1; max-width: none; } }

.ssv-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
/* Перебиваем глобальные стили проекта (thead на коричневом фоне с белым текстом) */
.ssv-table thead { color: #2b2b2b; background: #eef2f7; }
.ssv-table thead th {
  padding: 10px 6px;
  border-bottom: 2px solid #c9d3df;
  text-align: center;
  vertical-align: middle;
  text-transform: none;
  letter-spacing: 0;
  color: #2b2b2b;
  background: #eef2f7;
  font-size: 12px;
  font-weight: 600;
  position: static;
  white-space: normal;
}
.ssv-table thead th + th { border-left: 1px solid #d8dee5; }
.ssv-th-rest { width: 220px; text-align: left; padding-left: 14px; font-size: 12px; font-weight: 700; color: #2b2b2b; background: #e5ebf2; }
.ssv-th-day { width: auto; }
.ssv-th-name { font-size: 14px; font-weight: 700; color: #2b2b2b; line-height: 1.1; display: block; }
.ssv-th-sub { font-size: 10px; color: #888; font-weight: 500; line-height: 1; margin-top: 2px; display: block; text-transform: uppercase; letter-spacing: 0.04em; }
.ssv-th-trash { width: 44px; }
.ssv-table td { padding: 8px 6px; border-bottom: 1px solid #ebebeb; vertical-align: middle; font-size: 12px; color: #2b2b2b; text-align: center; }
.ssv-table td + td { border-left: 1px solid #f3f3f3; }
.ssv-table tbody tr td { background: #fff; }
.ssv-table tbody tr:nth-child(even) td { background: #fafbfc; }
.ssv-table tbody tr td.ssv-td-rest { background: #f7fafc; }
.ssv-table tbody tr:nth-child(even) td.ssv-td-rest { background: #f3f6f9; }
.ssv-table tbody tr:last-child td { border-bottom: none; }
.ssv-table tbody tr.is-unset td { background: #fdfdfd; color: #aaa; }
.ssv-table tbody tr.is-unset td.ssv-td-rest { background: #f7faf c; color: #888; }
.ssv-table tbody tr.is-unset td.ssv-td-rest { background: #f7fafc; color: #888; }
.ssv-table tbody tr:hover td { background: #fff8f3; }
.ssv-table tbody tr:hover td.ssv-td-rest { background: #fff1e8; }
.ssv-table tbody tr:hover td.ssv-td-cell:hover { background: #ffe4d2; }

.ssv-td-rest { display: flex; flex-direction: column; gap: 2px; padding: 9px 14px; text-align: left; max-width: 220px; }
.ssv-rest-num { font-weight: 700; font-size: 13px; color: #2b2b2b; }
.ssv-rest-addr { font-size: 11px; color: #555; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
.ssv-rest-le { font-size: 10px; color: #999; font-weight: 600; }

.ssv-td-cell { text-align: center; cursor: pointer; user-select: none; position: relative; transition: background 0.12s; }
.ssv-td-cell:hover { background: #fff8f5; }
.ssv-td-cell.is-empty { color: #ccc; }
/* "Неактивно" показываем рамкой, не размытием, чтобы день недели был чётко виден */
.ssv-td-cell.is-inactive .ssv-cell-content { border: 1px dashed #d0d0d0; border-radius: 4px; padding: 2px 4px; background: repeating-linear-gradient(45deg, #fafafa 0 4px, #f4f4f4 4px 8px); }
.ssv-td-cell.is-inactive .ssv-cell-day { color: #888; }
.ssv-cell-content { display: inline-flex; flex-direction: column; gap: 0; line-height: 1.15; align-items: center; }
.ssv-cell-arrow { display: none; }
.ssv-cell-orderlabel { font-size: 9px; color: #999; text-transform: uppercase; letter-spacing: 0.04em; line-height: 1; }
.ssv-cell-day { font-weight: 700; font-size: 12px; color: #2b2b2b; line-height: 1.2; }
.ssv-cell-time { font-size: 11px; font-weight: 600; color: #b35900; line-height: 1.1; }
.ssv-cell-time.is-dim { color: #aaa; font-weight: 400; }
.ssv-cell-empty { color: #ccc; font-size: 13px; }

.ssv-td-trash { text-align: center; }
.ssv-icon-btn { border: none; background: transparent; color: #c0c0c0; cursor: pointer; padding: 4px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; }
.ssv-icon-btn:hover { background: #f0f0f0; color: #333; }
.ssv-icon-btn.ssv-icon-danger:hover { background: #fde2e2; color: #c62828; }

/* Popover для ячейки */
.ssv-popover { position: absolute; top: 100%; left: 50%; transform: translateX(-50%); margin-top: 4px; background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,0.12); padding: 12px; min-width: 240px; z-index: 50; text-align: left; }
.ssv-pop-title { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 8px; }
.ssv-pop-toggle { display: flex; align-items: center; gap: 8px; padding: 4px 0; cursor: pointer; font-size: 13px; color: #2b2b2b; }
.ssv-pop-toggle input { margin: 0; }
.ssv-pop-body { display: flex; flex-direction: column; gap: 8px; padding-top: 8px; }
.ssv-pop-field { display: flex; flex-direction: column; gap: 3px; }
.ssv-pop-field-wide { grid-column: 1 / -1; }
.ssv-td-reminders { min-width: 280px; max-width: 340px; }
.ssv-td-reminders .rte-empty { font-size: 11px; padding: 6px 8px; }
.ssv-pop-field label { font-size: 10px; color: #888; font-weight: 500; text-transform: uppercase; letter-spacing: 0.03em; }
.ssv-pop-field select, .ssv-pop-field input[type="time"] { padding: 6px 8px; border: 1px solid #ddd; border-radius: 5px; font-size: 12px; background: #fff; }
.ssv-pop-hint { font-size: 10px; color: #999; }
.ssv-pop-actions { display: flex; justify-content: flex-end; gap: 6px; margin-top: 10px; }
.ssv-pop-actions .ssv-btn-primary { padding: 5px 11px; font-size: 12px; }
.ssv-pop-actions .ssv-btn-ghost { padding: 5px 11px; font-size: 12px; }

/* Модалки */
.ssv-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 1000; display: flex; align-items: center; justify-content: center; padding: 20px; }
.ssv-modal { background: #fff; border-radius: 10px; max-width: 540px; width: 100%; box-shadow: 0 10px 40px rgba(0,0,0,0.2); display: flex; flex-direction: column; max-height: 90vh; }
.ssv-modal-wide { max-width: 880px; }
.ssv-modal-header { padding: 14px 18px; border-bottom: 1px solid #ececec; display: flex; align-items: center; justify-content: space-between; }
.ssv-modal-header h3 { margin: 0; font-size: 16px; }
.ssv-modal-close { border: none; background: transparent; font-size: 22px; cursor: pointer; color: #999; padding: 0 4px; line-height: 1; }
.ssv-modal-close:hover { color: #c62828; }
.ssv-modal-body { padding: 16px 18px; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; }
.ssv-modal-hint { margin: 0; font-size: 12px; color: #666; line-height: 1.5; }
.ssv-modal-footer { padding: 12px 18px; border-top: 1px solid #ececec; display: flex; justify-content: flex-end; gap: 8px; }

.ssv-defaults-table { width: 100%; border-collapse: collapse; }
.ssv-defaults-table th { font-size: 10px; color: #888; font-weight: 600; text-align: left; padding: 6px 8px; border-bottom: 1px solid #ececec; text-transform: uppercase; letter-spacing: 0.04em; }
.ssv-defaults-table td { padding: 6px 8px; }
.ssv-defaults-table select, .ssv-defaults-table input[type="time"] { padding: 5px 7px; border: 1px solid #ddd; border-radius: 5px; font-size: 12px; background: #fff; width: 100%; box-sizing: border-box; }
.ssv-defaults-empty { color: #aaa; font-size: 12px; text-align: center; padding: 16px; }
.ssv-add-rule { align-self: flex-start; font-size: 12px; }

@media (max-width: 900px) {
  .ssv-th-rest { width: 160px; }
  .ssv-cell-day { font-size: 10px; }
  .ssv-cell-time { font-size: 10px; }
}
</style>
