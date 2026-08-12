<template>
  <div class="aud">
      <!-- Переключатель: Аудит / Ошибки -->
      <div class="adm-audit-mode">
        <button class="adm-audit-mode-btn" :class="{ active: auditMode === 'audit' }" @click="auditMode = 'audit'">
          <BkIcon name="note" size="sm"/> Аудит
        </button>
        <button class="adm-audit-mode-btn" :class="{ active: auditMode === 'errors' }" @click="auditMode = 'errors'; loadErrorsIfNeeded()">
          <BkIcon name="error" size="sm"/> Ошибки
        </button>
      </div>

      <!-- Аудит -->
      <template v-if="auditMode === 'audit'">
        <div class="adm-audit-filters">
          <div class="adm-audit-filter-row">
            <div class="adm-audit-chips">
              <button v-for="cat in auditCategories" :key="cat.value" class="adm-audit-chip"
                :class="{ active: auditFilter.category === cat.value }" @click="auditFilter.category = cat.value; loadAudit(true)">
                {{ cat.label }}
              </button>
            </div>
            <div class="adm-audit-right-filters">
              <select v-model="auditFilter.user" @change="loadAudit(true)" class="adm-audit-select">
                <option value="">Все пользователи</option>
                <option v-for="u in auditUsers" :key="u" :value="u">{{ u }}</option>
              </select>
              <input type="date" v-model="auditFilter.dateFrom" @change="loadAudit(true)" class="adm-audit-date" />
              <input type="date" v-model="auditFilter.dateTo" @change="loadAudit(true)" class="adm-audit-date" />
            </div>
          </div>
        </div>

        <div v-if="auditLoading && !auditEntries.length" style="text-align:center;padding:48px;"><BurgerSpinner text="Загрузка журнала..." /></div>
        <UiEmptyState v-else-if="!auditEntries.length"
                      title="Записей нет"
                      description="Здесь появятся действия сотрудников за выбранный период. Попробуйте расширить период или снять фильтры.">
          <template #icon><BkIcon name="history" size="lg" /></template>
        </UiEmptyState>

        <div v-else class="adm-audit-list">
          <div v-for="log in auditEntries" :key="log.id" class="adm-audit-entry">
            <div class="adm-audit-head">
              <span class="adm-audit-badge" :class="auditBadgeClass(log.action)">{{ auditBadgeLabel(log.action) }}</span>
              <span class="adm-audit-entity-badge" :class="'adm-audit-et-' + log.entity_type">{{ auditEntityLabel(log.entity_type) }}</span>
              <span class="adm-audit-author">{{ authorLabel(log.user_name) }}</span>
              <span class="adm-audit-date-text">{{ formatAuditDate(log.created_at) }}</span>
            </div>

            <div v-if="log.details?.supplier" class="adm-audit-ctx">{{ log.details.supplier }}</div>
            <div v-if="log.details?.restaurant_number" class="adm-audit-ctx">Ресторан {{ formatRestaurantNumber(log.details.restaurant_number) }}</div>

            <div v-if="log.details?.param_changes?.length" class="adm-audit-params">
              <span v-for="(pc, pi) in log.details.param_changes" :key="pi" class="adm-audit-param-chip">
                {{ pc.label }}: {{ pc.from }} → {{ pc.to }}
              </span>
            </div>

            <div v-if="log.action === 'delivery_date_changed' && log.details?.old_date" class="adm-audit-delivery">
              {{ log.details.old_date }} → {{ log.details.new_date }}
            </div>

            <div v-if="log.action === 'received'" class="adm-audit-received">
              <span>{{ log.details?.items_count || 0 }} позиций</span>
              <span v-if="log.details?.discrepancies" class="adm-audit-disc">{{ log.details.discrepancies }} расхождений</span>
              <span v-else class="adm-audit-no-disc">без расхождений</span>
            </div>
            <div v-if="log.action === 'received' && log.details?.items_with_discrepancy?.length" class="adm-audit-changes">
              <span v-for="(item, i) in log.details.items_with_discrepancy" :key="i" class="adm-audit-ch adm-audit-ch-upd">
                {{ item.name }}: {{ item.ordered }} → {{ item.received }}
              </span>
            </div>

            <div v-if="log.action === 'reception_reverted' && log.details?.reverted_from" class="adm-audit-ctx" style="font-style:italic;">
              Отменена приёмка от {{ log.details.reverted_from }}
            </div>

            <div v-if="log.details?.full_schedule" class="adm-audit-sched-row">
              <span v-for="day in ['ПН','ВТ','СР','ЧТ','ПТ','СБ']" :key="day" class="adm-audit-sched-cell" :class="{ has: log.details.full_schedule[day] }">
                <span class="adm-audit-sched-day">{{ day }}</span>
                <span class="adm-audit-sched-time">{{ log.details.full_schedule[day] || '—' }}</span>
              </span>
            </div>

            <div v-if="log.details?.changes?.length" class="adm-audit-changes">
              <span v-for="(c, ci) in log.details.changes.slice(0, expandedAudit.has(log.id) ? 999 : 5)" :key="ci" class="adm-audit-ch" :class="{ 'adm-audit-ch-add': c.type==='added', 'adm-audit-ch-del': c.type==='removed', 'adm-audit-ch-upd': c.type==='changed' }">
                <template v-if="c.type === 'added'">+ {{ c.item }} {{ c.boxes }}кор</template>
                <template v-else-if="c.type === 'removed'">− {{ c.item }} {{ c.boxes }}кор</template>
                <template v-else>{{ c.item }}: {{ c.diffs?.join(', ') }}</template>
              </span>
              <button v-if="log.details.changes.length > 5 && !expandedAudit.has(log.id)" class="adm-audit-more" @click="expandedAudit.add(log.id)">
                ещё {{ log.details.changes.length - 5 }}...
              </button>
            </div>

            <div v-if="log.details?.items_count && log.action !== 'received' && !log.details?.changes?.length" class="adm-audit-meta">{{ log.details.items_count }} позиций</div>
            <div v-if="log.details?.name && log.entity_type === 'product'" class="adm-audit-ctx">{{ log.details.name }} <span v-if="log.details?.sku" style="opacity:.6;">({{ log.details.sku }})</span></div>
          </div>

          <div v-if="auditHasMore" style="text-align:center;padding:16px;">
            <button class="btn" @click="loadAudit(false)" :disabled="auditLoading">
              <BurgerSpinner v-if="auditLoading" size="xs" />
              <span>{{ auditLoading ? 'Загрузка...' : 'Показать ещё' }}</span>
            </button>
          </div>
        </div>
      </template>

      <!-- Ошибки -->
      <template v-if="auditMode === 'errors'">
        <div class="adm-audit-filters">
          <div class="adm-audit-filter-row">
            <div class="adm-audit-chips">
              <button v-for="l in errorLevelOptions" :key="l.value" class="adm-audit-chip"
                :class="{ active: errorFilter.level === l.value }" @click="errorFilter.level = l.value; loadErrors(true)">
                {{ l.label }}
              </button>
            </div>
            <div class="adm-audit-right-filters">
              <select v-model="errorFilter.source" @change="loadErrors(true)" class="adm-audit-select">
                <option value="">Все источники</option>
                <option value="frontend">Фронтенд</option>
                <option value="backend">Бэкенд</option>
              </select>
              <button class="btn" style="font-size:12px;padding:5px 12px;" @click="clearErrors" :disabled="errorsClearing">
                <BkIcon name="delete" size="sm"/> Очистить
              </button>
            </div>
          </div>
        </div>

        <div v-if="errorsLoading && !errorEntries.length" style="text-align:center;padding:48px;"><BurgerSpinner text="Загрузка..." /></div>
        <UiEmptyState v-else-if="!errorEntries.length"
                      title="Ошибок нет"
                      description="Портал не записал ни одной серверной ошибки за выбранный период. Это хорошая новость.">
          <template #icon><BkIcon name="success" size="lg" /></template>
        </UiEmptyState>

        <div v-else class="adm-audit-list">
          <div v-for="log in errorEntries" :key="log.id" class="adm-audit-entry adm-error-entry" @click="toggleErrorStack(log.id)">
            <div class="adm-audit-head">
              <span class="adm-audit-badge" :class="errorBadgeClass(log.level)">{{ log.level }}</span>
              <span class="adm-audit-entity-badge">{{ log.source }}</span>
              <span v-if="log.user_name" class="adm-audit-author">{{ log.user_name }}</span>
              <span class="adm-audit-date-text">{{ formatAuditDate(log.created_at) }}</span>
            </div>
            <div class="adm-error-message">{{ log.message }}</div>
            <div v-if="log.url" class="adm-error-url">{{ log.url }}</div>
            <div v-if="expandedErrors.has(log.id) && log.stack" class="adm-error-stack">{{ log.stack }}</div>
          </div>
          <div v-if="errorsHasMore" style="text-align:center;padding:16px;">
            <button class="btn" @click="loadErrors(false)" :disabled="errorsLoading">
              <BurgerSpinner v-if="errorsLoading" size="xs" />
              <span>{{ errorsLoading ? 'Загрузка...' : 'Показать ещё' }}</span>
            </button>
          </div>
        </div>
      </template>

    <ConfirmModal v-if="confirmModal.show" :title="confirmModal.title" :message="confirmModal.message"
      @confirm="onConfirmOk" @cancel="onConfirmCancel" />
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { db } from '@/lib/apiClient.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import BurgerSpinner from '@/components/ui/BurgerSpinner.vue';
import UiEmptyState from '@/components/ui/UiEmptyState.vue';
import { useToastStore } from '@/stores/toastStore.js';
import { useConfirm } from '@/composables/useConfirm.js';
import { formatMoscowDateTime } from '@/lib/utils.js';
import { formatRestaurantNumber } from '@/lib/legalEntities.js';
import ConfirmModal from '@/components/modals/ConfirmModal.vue';

// Счётчик у вкладки рисует админка.
const emit = defineEmits(['total']);

const toast = useToastStore();
const {
  confirmModal,
  confirm: confirmAction,
  onConfirm: onConfirmOk,
  onCancel: onConfirmCancel,
} = useConfirm();

// ═══ Аудит-лог ═══
const auditMode = ref('audit');
const AUDIT_PAGE_SIZE = 50;
const auditEntries = ref([]);
const auditLoading = ref(false);
const auditHasMore = ref(false);
const auditTotal = ref(0);
const expandedAudit = reactive(new Set());
const auditUsers = ref([]);
const auditFilter = reactive({ category: '', user: '', dateFrom: '', dateTo: '' });


const auditCategories = [
  { value: '', label: 'Все' },
  { value: 'order', label: 'Заказы' },
  { value: 'plan', label: 'Планы' },
  { value: 'product', label: 'Товары' },
  { value: 'delivery_schedule', label: 'Расписание' },
  { value: 'user', label: 'Пользователи' },
  { value: 'price_agreement', label: 'Цены и ПСЦ' },
  { value: 'tender', label: 'Тендеры' },
  { value: 'marketing', label: 'Маркетинг' },
  { value: 'correction', label: 'Корректировки' },
  { value: 'import', label: 'Импорт данных' },
  { value: 'veg', label: 'Планета Ресторанов' },
  { value: 'supplier_order', label: 'Заявки поставщикам' },
  { value: 'stock_collection', label: 'Сбор остатков' },
  { value: 'distribution', label: 'Распределение' },
  { value: 'system', label: 'Система' },
];

const AUDIT_ACTION_LABELS = {
  // Заказы
  order_created: 'Создан', order_updated: 'Изменён', order_deleted: 'Удалён', orders_deleted: 'Удалён',
  delivery_date_changed: 'Дата доставки', received: 'Принят', reception_reverted: 'Отмена приёмки',
  // Планы
  plan_created: 'Создан', plan_updated: 'Изменён', plan_deleted: 'Удалён', plans_deleted: 'Удалён',
  // Товары
  product_created: 'Создана', product_updated: 'Изменена', products_deleted: 'Удалена',
  // Расписание
  schedule_updated: 'График', restaurant_updated: 'Ресторан',
  // Пользователи
  user_created: 'Создан', user_updated: 'Изменён', user_deleted: 'Удалён', password_changed: 'Пароль изменён',
  // Цены и ПСЦ
  price_agreement_created: 'Создан', price_agreement_updated: 'Изменён',
  agreement_approved: 'Согласован', agreement_archived: 'Архивирован', agreement_restored: 'Восстановлен',
  agreement_deleted: 'Удалён', price_imported: 'Импорт цен', price_deleted: 'Цена удалена',
  exchange_rate_updated: 'Курс обновлён',
  // Тендеры
  tender_created: 'Создан', tender_updated: 'Изменён', tender_deleted: 'Удалён',
  // Маркетинг
  marketing_created: 'Создана', marketing_updated: 'Изменена', marketing_deleted: 'Удалена',
  marketing_auto_completed: 'Завершена по дате',
  // Корректировки
  correction_created: 'Создана', correction_approved: 'Подтверждена', correction_rejected: 'Отклонена',
  correction_reviewed: 'Рассмотрена',
  // Импорт
  data_imported: 'Импорт', recipe_imported: 'Импорт рецептур',
  // Овощи
  veg_session_created: 'Сессия создана', veg_order_updated: 'Заявка изменена', veg_order_submitted: 'Заявка подана',
  // Заявки поставщикам (so_*)
  so_order_submitted: 'Заявка подана', so_order_updated: 'Заявка обновлена',
  so_order_skipped: 'Поставка не нужна', so_order_edited: 'Изменена отделом закупок',
  so_order_deleted: 'Удалена', so_qty_adjusted: 'Правка количества',
  so_deadline_extended: 'Дедлайн продлён', so_day_closed: 'День закрыт',
  so_day_reopened: 'День открыт', so_template_saved: 'Шаблон сохранён',
  // Напоминания о подаче заявок
  reminder_sub_toggled: 'Напоминания', reminder_main_toggled: 'Напоминания',
  // Сбор остатков
  stock_collection_created: 'Создан', collection_created: 'Создан', collection_closed: 'Закрыт',
  collection_reopened: 'Переоткрыт', stock_collection_cell_saved: 'Ячейка остатков',
  collection_deadline_set: 'Срок сдачи',
  // Залоговые цены
  deposit_price_updated: 'Залоговая цена', deposit_prices_imported: 'Импорт залог. цен',
  // Распределение
  distribution_created: 'Создано',
  // Корректировки (кабинет ресторана)
  correction_submit_cabinet: 'Подана из кабинета',
  correction_deadline_changed: 'Дедлайн изменён',
  // Система
  broadcast_sent: 'Рассылка', session_terminated: 'Сессия завершена', maintenance_toggled: 'Тех. работы',
  // Заявки поставщикам и напоминания — появились уже после словаря.
  so_deadline_rules_updated: 'Дедлайны', so_adhoc_created: 'Внеплановая',
  so_supplier_disconnected: 'Поставщик отключён',
  reminder_keg_toggled: 'Напоминание о кегах', correction_taken: 'Взята в работу',
};
// Здесь лежат имена таблиц из БД — без перевода они и попадали на экран
// как «restaurant_main_delivery_subscriptions».
const AUDIT_ENTITY_LABELS = {
  order: 'Заказ', plan: 'План', product: 'Товар', delivery_schedule: 'Расписание',
  user: 'Пользователь', price_agreement: 'Протокол цен',
  marketing: 'Маркетинг', tender: 'Тендер',
  correction: 'Корректировка', distribution: 'Распределение', stock_collection: 'Сбор остатков',
  import: 'Импорт', supplier_order: 'Заявка поставщику', system: 'Система',
  supplier: 'Поставщик', suppliers: 'Поставщик',
  restaurant: 'Ресторан', restaurants: 'Ресторан',
  order_corrections: 'Корректировка', product_prices: 'Цена товара',
  restaurant_reminder_subscriptions: 'Напоминание поставщику',
  restaurant_main_delivery_subscriptions: 'Напоминание об основной поставке',
  restaurant_keg_return_subscriptions: 'Напоминание о возврате кег',
  ro_telegram_subs: 'Привязка Telegram',
  so_orders: 'Заявка поставщику', so_templates: 'Шаблон заявки',
};

function auditBadgeLabel(action) { return AUDIT_ACTION_LABELS[action] || action; }
function auditEntityLabel(et) { return AUDIT_ENTITY_LABELS[et] || et; }
function auditBadgeClass(action) {
  if (action === 'received') return 'adm-audit-b-received';
  if (action === 'reception_reverted') return 'adm-audit-b-reverted';
  if (action === 'delivery_date_changed') return 'adm-audit-b-delivery';
  if (action === 'schedule_updated' || action === 'restaurant_updated') return 'adm-audit-b-schedule';
  if (action.includes('imported') || action === 'data_imported') return 'adm-audit-b-schedule';
  if (action.includes('approved') || action.includes('restored') || action === 'broadcast_sent') return 'adm-audit-b-received';
  if (action.includes('archived') || action === 'session_terminated' || action === 'password_changed') return 'adm-audit-b-reverted';
  if (action.includes('rejected')) return 'adm-audit-b-deleted';
  if (action.includes('created')) return 'adm-audit-b-created';
  if (action.includes('updated') || action.includes('changed') || action.includes('reviewed')) return 'adm-audit-b-updated';
  if (action.includes('deleted') || action.includes('closed')) return 'adm-audit-b-deleted';
  return '';
}

const formatAuditDate = formatMoscowDateTime;

// В журнале автор записан как «Ресторан 1038» — в БД у Пицца Стар номера с
// 1001. Людям показываем привычное PS38, историю в базе не трогаем.
function authorLabel(name) {
  const s = String(name || '').trim();
  if (!s) return '—';
  // Служебные метки из кабинета и бота: ro:1038 → «Ресторан PS38».
  const service = s.match(/^(ro|tg):(\d{2,4})$/i);
  if (service) return 'Ресторан ' + formatRestaurantNumber(service[2]);
  if (/^auto:/i.test(s)) return 'Автоматически';
  return s.replace(/(Ресторан\s+)(\d{3,4})/gi, (_, prefix, num) => prefix + formatRestaurantNumber(num));
}

async function loadAudit(reset = true) {
  if (reset) {
    auditEntries.value = [];
    auditLoading.value = true;
  } else {
    auditLoading.value = true;
  }
  try {
    const offset = reset ? 0 : auditEntries.value.length;
    let query = db.from('audit_log').select('*').order('created_at', { ascending: false }).limit(AUDIT_PAGE_SIZE).offset(offset);
    if (auditFilter.category) query = query.eq('entity_type', auditFilter.category);
    if (auditFilter.user) query = query.eq('user_name', auditFilter.user);
    if (auditFilter.dateFrom) query = query.gte('created_at', auditFilter.dateFrom);
    if (auditFilter.dateTo) query = query.lte('created_at', auditFilter.dateTo + ' 23:59:59');

    const { data } = await query;
    const parsed = (data || []).map(e => {
      if (e.details && typeof e.details === 'string') {
        try { e.details = JSON.parse(e.details); } catch { e.details = null; }
      }
      return e;
    });

    if (reset) {
      auditEntries.value = parsed;
    } else {
      auditEntries.value.push(...parsed);
    }
    auditHasMore.value = parsed.length >= AUDIT_PAGE_SIZE;
    if (reset) {
      auditTotal.value = auditHasMore.value ? parsed.length + '+' : parsed.length;
      emit('total', auditTotal.value);
    }
  } catch (e) {
    toast.error('Ошибка', 'Не удалось загрузить журнал');
  } finally {
    auditLoading.value = false;
  }
}

async function loadAuditUsers() {
  try {
    const { data } = await db.from('users').select('name').order('name');
    auditUsers.value = (data || []).map(u => u.name).filter(Boolean);
  } catch { /* ok */ }
}

// ═══ Логи ошибок ═══
const ERROR_PAGE_SIZE = 50;
const errorEntries = ref([]);
const errorsLoading = ref(false);
const errorsHasMore = ref(false);
const errorsClearing = ref(false);
const expandedErrors = reactive(new Set());
const errorFilter = reactive({ level: '', source: '' });

const errorLevelOptions = [
  { value: '', label: 'Все' },
  { value: 'error', label: 'Ошибки' },
  { value: 'warning', label: 'Предупреждения' },
  { value: 'info', label: 'Информация' },
];

// Логи ошибок грузим только когда на них переключились.
function loadErrorsIfNeeded() {
  if (!errorEntries.value.length) loadErrors(true);
}

function errorBadgeClass(level) {
  if (level === 'error') return 'adm-audit-b-deleted';
  if (level === 'warning') return 'adm-audit-b-updated';
  return 'adm-audit-b-schedule';
}

function toggleErrorStack(id) {
  if (expandedErrors.has(id)) expandedErrors.delete(id);
  else expandedErrors.add(id);
}

async function loadErrors(reset = true) {
  if (reset) {
    errorEntries.value = [];
  }
  errorsLoading.value = true;
  try {
    const offset = reset ? 0 : errorEntries.value.length;
    let query = db.from('error_logs').select('*').order('created_at', { ascending: false }).limit(ERROR_PAGE_SIZE).offset(offset);
    if (errorFilter.level) query = query.eq('level', errorFilter.level);
    if (errorFilter.source) query = query.eq('source', errorFilter.source);
    const { data } = await query;
    const rows = data || [];
    if (reset) {
      errorEntries.value = rows;
    } else {
      errorEntries.value.push(...rows);
    }
    errorsHasMore.value = rows.length >= ERROR_PAGE_SIZE;
  } catch { toast.error('Ошибка', 'Не удалось загрузить логи'); }
  finally { errorsLoading.value = false; }
}

async function clearErrors() {
  const ok = await confirmAction('Очистить все ошибки?', 'Все записи логов ошибок будут удалены безвозвратно.');
  if (!ok) return;
  errorsClearing.value = true;
  try {
    const { data } = await db.rpc('clear_error_logs');
    if (data?.success) {
      errorEntries.value = [];
      toast.success('Очищено', 'Логи ошибок удалены');
    }
  } catch { toast.error('Ошибка', 'Не удалось очистить логи'); }
  finally { errorsClearing.value = false; }
}

onMounted(() => {
  loadAudit(true);
  loadAuditUsers();
});
</script>

<style scoped>
/* ═══ Audit Log ═══ */
.adm-audit-filters { margin-bottom: 16px; }
.adm-audit-filter-row {
  display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
}
.adm-audit-chips { display: flex; gap: 4px; flex-wrap: wrap; }
.adm-audit-chip {
  padding: 5px 14px; border-radius: 20px; font-size: 13px; font-weight: 600;
  font-family: inherit; cursor: pointer; transition: all .15s;
  border: 1.5px solid var(--border); background: var(--card); color: var(--text-muted);
}
.adm-audit-chip:hover { border-color: var(--bk-orange); color: var(--text); }
.adm-audit-chip.active { border-color: var(--bk-orange); background: #FFFBF5; color: var(--bk-brown); }

.adm-audit-right-filters { display: flex; gap: 6px; align-items: center; }
.adm-audit-select, .adm-audit-date {
  padding: 5px 10px; border: 1.5px solid var(--border); border-radius: 8px;
  font-size: 12px; font-family: inherit; background: var(--card); color: var(--text);
}
.adm-audit-select:focus, .adm-audit-date:focus { border-color: var(--bk-orange); outline: none; }
.adm-audit-date { width: 120px; }

.adm-audit-list { display: flex; flex-direction: column; gap: 2px; }
.adm-audit-entry {
  padding: 10px 14px; border-radius: 10px;
  background: var(--card); border: 1.5px solid transparent;
  transition: all .15s;
}
.adm-audit-entry:hover { border-color: var(--border-light); }

.adm-audit-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.adm-audit-badge {
  display: inline-block; padding: 1px 8px; border-radius: 10px;
  font-size: 10px; font-weight: 700;
}
.adm-audit-b-created { background: #E8F5E9; color: #2E7D32; }
.adm-audit-b-updated { background: #FFF3E0; color: #E65100; }
.adm-audit-b-deleted { background: #FFEBEE; color: #C62828; }
.adm-audit-b-received { background: #E0F2F1; color: #00695C; }
.adm-audit-b-reverted { background: #FFF3E0; color: #BF360C; }
.adm-audit-b-delivery { background: #E3F2FD; color: #1565C0; }
.adm-audit-b-schedule { background: #E8EAF6; color: #283593; }

.adm-audit-entity-badge {
  display: inline-block; padding: 1px 7px; border-radius: 4px;
  font-size: 10px; font-weight: 600; background: var(--bg); color: var(--text-muted);
}
.adm-audit-et-order { background: #FFF8E1; color: #E65100; }
.adm-audit-et-plan { background: #E8F5E9; color: #2E7D32; }
.adm-audit-et-product { background: #E3F2FD; color: #1565C0; }
.adm-audit-et-delivery_schedule { background: #E8EAF6; color: #283593; }

.adm-audit-author { font-weight: 600; font-size: 12px; color: var(--text); }
.adm-audit-date-text { font-size: 11px; color: var(--text-muted); margin-left: auto; white-space: nowrap; }

.adm-audit-ctx { font-size: 12px; color: var(--text-secondary); margin-top: 3px; }
.adm-audit-meta { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

.adm-audit-params { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px; }
.adm-audit-param-chip {
  display: inline-block; padding: 1px 7px; border-radius: 4px;
  font-size: 11px; background: #EDE7F6; color: #4A148C; font-weight: 500;
}

.adm-audit-delivery {
  display: inline-flex; margin-top: 5px; padding: 2px 8px; border-radius: 4px;
  font-size: 11px; font-weight: 600; background: #E3F2FD; color: #1565C0;
}
.adm-audit-received { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 5px; font-size: 11px; }
.adm-audit-disc { padding: 1px 7px; border-radius: 4px; background: #FFF8E1; color: #E65100; font-weight: 600; }
.adm-audit-no-disc { padding: 1px 7px; border-radius: 4px; background: #E8F5E9; color: #2E7D32; font-weight: 500; }

.adm-audit-changes { display: flex; flex-wrap: wrap; gap: 3px; margin-top: 5px; }
.adm-audit-ch {
  display: inline-block; padding: 1px 6px; border-radius: 4px;
  font-size: 10px; font-weight: 600; line-height: 1.5;
}
.adm-audit-ch-add { background: #E8F5E9; color: #2E7D32; }
.adm-audit-ch-del { background: #FFEBEE; color: #C62828; }
.adm-audit-ch-upd { background: #FFF8E1; color: #5D4037; }
.adm-audit-more {
  padding: 1px 8px; border-radius: 4px; font-size: 10px; font-weight: 600;
  background: var(--bg); color: var(--text-muted); border: 1px solid var(--border-light);
  cursor: pointer; font-family: inherit;
}
.adm-audit-more:hover { border-color: var(--bk-orange); color: var(--text); }

.adm-audit-sched-row { display: flex; gap: 3px; margin-top: 6px; flex-wrap: wrap; }
.adm-audit-sched-cell {
  display: flex; flex-direction: column; align-items: center;
  min-width: 48px; padding: 3px 4px; border-radius: 4px;
  background: #F5F5F5; border: 1px solid #E0E0E0;
}
.adm-audit-sched-cell.has { background: #E8F5E9; border-color: #A5D6A7; }
.adm-audit-sched-day { font-size: 9px; font-weight: 700; color: #888; }
.adm-audit-sched-cell.has .adm-audit-sched-day { color: #2E7D32; }
.adm-audit-sched-time { font-size: 10px; font-weight: 700; color: #BDBDBD; }
.adm-audit-sched-cell.has .adm-audit-sched-time { color: #1B5E20; }

@media (max-width: 600px) {
  /* min-width: 0 обязателен: без него флекс-элемент раздувается по
     содержимому, и поля уезжали на 1700 пикселей вправо. */
  .adm-audit-filter-row { flex-direction: column; align-items: stretch; min-width: 0; }
  .adm-audit-right-filters { flex-wrap: wrap; min-width: 0; width: 100%; }
  .adm-audit-date { flex: 1 1 45%; min-width: 0; width: auto; }
  .adm-audit-select { width: 100%; min-width: 0; }

  /* Шестнадцать категорий занимали семь строк, и до самих записей нужно было
     долго прокручивать. Прокручиваем ленту фильтров вбок. */
  .adm-audit-chips {
    flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch;
    padding-bottom: 4px; margin-bottom: 4px;
  }
  .adm-audit-chip { flex-shrink: 0; }

  /* Записи журнала: дата уходила вправо и жалась к краю */
  .adm-audit-head { gap: 6px; }
  .adm-audit-date-text { margin-left: 0; width: 100%; }
}

/* ═══ Audit Mode Toggle ═══ */
.adm-audit-mode {
  display: flex; gap: 0; margin-bottom: 14px;
  background: var(--bg); border-radius: 10px; padding: 3px;
  border: 1.5px solid var(--border-light); width: fit-content;
}
.adm-audit-mode-btn {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 6px 16px; border-radius: 8px; font-size: 13px; font-weight: 600;
  font-family: inherit; cursor: pointer; transition: all .15s;
  border: none; background: none; color: var(--text-muted);
}
.adm-audit-mode-btn.active {
  background: var(--card); color: var(--bk-brown);
  box-shadow: 0 1px 3px rgba(0,0,0,.08);
}
.adm-audit-mode-btn:hover:not(.active) { color: var(--text); }

/* ═══ Error Logs ═══ */
.adm-error-entry { cursor: pointer; }
.adm-error-entry:hover { border-color: var(--border); }
.adm-error-message { font-size: 13px; color: var(--text); margin-top: 4px; word-break: break-word; }
.adm-error-url { font-size: 11px; color: var(--text-muted); margin-top: 2px; word-break: break-all; }
.adm-error-stack {
  margin-top: 6px; padding: 8px 10px; border-radius: 6px;
  background: #F5F5F5; font-size: 11px; font-family: monospace;
  white-space: pre-wrap; word-break: break-all; color: #333;
  max-height: 200px; overflow-y: auto;
}
</style>
