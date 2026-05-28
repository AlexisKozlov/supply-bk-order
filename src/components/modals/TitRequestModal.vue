<template>
  <div class="trm-overlay" @click.self="tryClose">
    <div class="trm-modal">
      <header class="trm-head">
        <div>
          <h2>{{ req?.supplier_name || 'Новая заявка на пропуск' }}</h2>
          <p class="trm-sub">
            <span class="trm-status" :class="'trm-status-' + (req?.status || '').toLowerCase()">{{ statusLabel(req?.status) }}</span>
            <span v-if="req?.order_id" class="trm-sub-tag">привязана к заказу</span>
          </p>
        </div>
        <button class="trm-close" @click="tryClose" aria-label="Закрыть">✕</button>
      </header>

      <div class="trm-body" v-if="loaded">
        <!-- Карточка-шапка: поставщик / юрлицо / дата прямо тут (без отдельной модалки создания) -->
        <div v-if="req && req.status !== 'SENT' && req.status !== 'CANCELLED'" class="trm-section trm-basic">
          <h3>Основное</h3>
          <div class="trm-basic-grid">
            <label class="trm-basic-supplier">
              <span>Поставщик</span>
              <input v-if="!req.supplier_id" v-model="supplierSearch" placeholder="Начните вводить название…" @input="searchSuppliers" @focus="searchSuppliers" />
              <div v-else class="trm-supplier-selected">
                <b>{{ req.supplier_name || '(без названия)' }}</b>
                <button class="trm-btn-link" @click="clearSupplier">сменить</button>
              </div>
              <ul v-if="!req.supplier_id && supplierResults.length" class="trm-supplier-list">
                <li v-for="s in supplierResults" :key="s.id" @click="pickSupplier(s)">
                  <b>{{ s.short_name || s.full_name }}</b>
                  <span>{{ s.legal_entity }}</span>
                </li>
              </ul>
            </label>
            <label>
              <span>Юрлицо</span>
              <select v-model="basic.legal_entity" @change="saveBasic">
                <option v-for="le in legalEntityOptions" :key="le" :value="le">{{ le }}</option>
              </select>
            </label>
            <label>
              <span>Дата подачи</span>
              <input type="date" v-model="basic.delivery_date" @change="saveBasic" />
            </label>
          </div>
        </div>

        <div v-if="req && req.status !== 'SENT'" class="trm-section">
          <h3>Машины <span class="trm-count">{{ activeVehicles.length }}</span></h3>

          <div v-if="!activeVehicles.length" class="trm-empty-block">
            Поставщик ещё не прислал данные машины. Дождитесь ответа на email или добавьте вручную.
          </div>

          <div v-for="(v, i) in activeVehicles" :key="v.id" class="trm-vehicle">
            <div class="trm-vehicle-head">
              <span class="trm-vehicle-num">Машина {{ i + 1 }}</span>
              <span class="trm-source-badge" :class="'src-' + (v.source || '').toLowerCase()">{{ sourceLabel(v.source) }}</span>
              <span v-if="v.needs_review" class="trm-review-badge">Требует проверки</span>
              <span v-else class="trm-confirmed-badge">✓ Подтверждено</span>
            </div>

            <div class="trm-vehicle-grid">
              <label>
                <span>Номер машины</span>
                <input v-model="v.plate" @input="v.plate = autoUpper(v.plate)" maxlength="15" />
                <small v-if="v.plate_raw && v.plate_raw !== v.plate">прислано: «{{ v.plate_raw }}»</small>
              </label>
              <label>
                <span>Телефон водителя</span>
                <input v-model="v.phone" placeholder="375XXXXXXXXX" inputmode="numeric" maxlength="20" />
                <small v-if="v.phone_raw && v.phone_raw !== v.phone">прислано: «{{ v.phone_raw }}»</small>
              </label>
              <label>
                <span>Склад</span>
                <select v-model.number="v.warehouse">
                  <option :value="6">Прилесье 6 (сухой)</option>
                  <option :value="1">Прилесье 1 (холод/мороз)</option>
                </select>
                <small v-if="recommendedWarehouses.length && !recommendedWarehouses.includes(v.warehouse)">по составу заказа рекомендуется: {{ recommendedWarehouses.map(w => 'Прилесье ' + w).join(' и ') }}</small>
              </label>
              <label>
                <span>Тип</span>
                <select v-model.number="v.entry_kind">
                  <option :value="1">Выгрузка</option>
                  <option :value="2">Загрузка</option>
                </select>
              </label>
              <label>
                <span>Начало окна</span>
                <input type="datetime-local" v-model="v.start_time_local" />
              </label>
              <label>
                <span>Конец окна</span>
                <input type="datetime-local" v-model="v.end_time_local" />
              </label>
            </div>

            <div class="trm-vehicle-actions">
              <button class="trm-btn ghost sm" @click="deleteVehicle(v)">✕ Удалить</button>
              <button class="trm-btn primary sm" @click="saveVehicle(v, true)">{{ v.needs_review ? 'Подтвердить' : 'Сохранить' }}</button>
              <button v-if="!v.needs_review" class="trm-btn ghost sm" @click="saveVehicle(v, false)">Только сохранить</button>
            </div>
          </div>

          <div class="trm-vehicle-add">
            <button class="trm-btn ghost" @click="addVehicle">＋ Добавить машину</button>
            <button v-if="supplierDefaults?.last_plate" class="trm-btn ghost" @click="applyDefaults">
              Подставить прошлую: <code>{{ supplierDefaults.last_plate }}</code>
            </button>
          </div>
        </div>

        <details class="trm-section trm-emails" v-if="emails.length">
          <summary>
            История писем <span class="trm-count">{{ emails.length }}</span>
          </summary>
          <ul>
            <li v-for="e in emails" :key="e.id">
              <div class="trm-email-head">
                <b>{{ e.from_name || e.from_email }}</b>
                <span class="trm-email-meta">{{ formatDateTime(e.received_at) }} · {{ e.status }}</span>
              </div>
              <div class="trm-email-subj">{{ e.subject }}</div>
              <div v-if="e.body_excerpt" class="trm-email-body">{{ e.body_excerpt }}</div>
              <div v-if="e.parsed_plate || e.parsed_phone" class="trm-email-parsed">
                Распознано: <code>{{ e.parsed_plate || '—' }}</code> / <code>{{ e.parsed_phone || '—' }}</code> ({{ e.parsed_via }})
              </div>
            </li>
          </ul>
        </details>

        <div v-if="req?.status === 'SENT'" class="trm-sent-banner">
          ✓ Заявка отправлена охране. История машин зафиксирована.
        </div>
      </div>

      <div v-else-if="error" class="trm-error">⚠ {{ error }}</div>
      <div v-else class="trm-loading"><span class="trm-spinner"></span> Загружаем…</div>

      <footer class="trm-foot" v-if="loaded && req?.status !== 'SENT'">
        <button class="trm-btn ghost" @click="deleteRequest" title="Удалить заявку полностью (без следа в логах)">🗑 Удалить</button>
        <button v-if="req?.status !== 'CANCELLED'" class="trm-btn ghost" @click="cancelRequest" title="Отменить, но оставить в логе со статусом «Отменена»">Отменить</button>
        <div style="flex:1"></div>
        <template v-if="req?.status !== 'CANCELLED'">
          <button class="trm-btn ghost" @click="downloadXlsx" :disabled="!canSend">Скачать xlsx</button>
          <button class="trm-btn ghost" @click="markSent" :disabled="!canSend || markingSent" title="Отправил вручную через свою почту — пометить заявку как отправленную">{{ markingSent ? '…' : '✓ Отправлено' }}</button>
          <button class="trm-btn primary" @click="openPreview" :disabled="!canSend">Превью и отправить охране →</button>
        </template>
      </footer>
    </div>

    <TitSendModal v-if="previewOpen" :id="props.id" @close="previewOpen = false" @sent="onSent" />
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, defineAsyncComponent } from 'vue';
import { db } from '@/lib/apiClient.js';
import { normalizePlate, normalizePhone } from '@/lib/titNormalize.js';

const TitSendModal = defineAsyncComponent(() => import('@/components/modals/TitSendModal.vue'));

const props = defineProps({ id: { type: Number, required: true } });
const emit = defineEmits(['close', 'changed']);

const req = ref(null);
const vehicles = ref([]);
const emails = ref([]);
const supplierDefaults = ref(null);
const recommendedWarehouses = ref([6]);
const loaded = ref(false);
const error = ref('');
const previewOpen = ref(false);
const markingSent = ref(false);

// Редактирование «основного» (поставщик / юрлицо / дата) прямо в карточке.
const basic = reactive({ legal_entity: '', delivery_date: '' });
const legalEntityOptions = ref([]);
const supplierSearch = ref('');
const supplierResults = ref([]);
let supplierSearchTimer = null;

function searchSuppliers() {
  clearTimeout(supplierSearchTimer);
  if (!supplierSearch.value || supplierSearch.value.length < 2) {
    supplierResults.value = [];
    return;
  }
  supplierSearchTimer = setTimeout(async () => {
    try {
      const { data } = await db.from('suppliers')
        .select('id,short_name,full_name,legal_entity,legal_entity_group')
        .eq('is_active', 1)
        .ilike('short_name', '*' + supplierSearch.value + '*');
      const group = req.value?.legal_entity_group;
      supplierResults.value = (data || [])
        .filter(s => !group || s.legal_entity_group === group)
        .slice(0, 12);
    } catch (_) { supplierResults.value = []; }
  }, 300);
}

async function pickSupplier(s) {
  try {
    const { data, error: e } = await db.rpc('tit_update_basic', {
      id: props.id,
      supplier_id: s.id,
    });
    if (e || data?.error) throw new Error(e || data.error);
    supplierSearch.value = '';
    supplierResults.value = [];
    await reload();
    emit('changed');
  } catch (e) { alert('Не удалось выбрать поставщика: ' + (e.message || e)); }
}

async function clearSupplier() {
  try {
    await db.rpc('tit_update_basic', { id: props.id, supplier_id: '' });
    await reload();
  } catch (_) {}
}

async function saveBasic() {
  try {
    await db.rpc('tit_update_basic', {
      id: props.id,
      legal_entity: basic.legal_entity,
      delivery_date: basic.delivery_date,
    });
    emit('changed');
  } catch (e) { alert('Не удалось сохранить: ' + (e.message || e)); }
}

const activeVehicles = computed(() => vehicles.value.filter(v => !v.deleted_at));
const canSend = computed(() => activeVehicles.value.length > 0 && activeVehicles.value.every(v => !v.needs_review));

const statusLabel = (s) => ({
  WAITING: 'Ждём поставщика',
  DATA_RECEIVED: 'Получены данные',
  READY: 'Готово',
  SENT: 'Отправлено',
  CANCELLED: 'Отменена',
}[s] || s || '—');

const sourceLabel = (s) => ({
  EMAIL_TEXT: 'из письма',
  EMAIL_OCR:  'из накладной',
  MANUAL:     'вручную',
  SUGGESTION: 'подсказка',
}[s] || '');

const formatDate = (d) => d ? d.split('-').reverse().join('.') : '';
const formatDateTime = (dt) => {
  if (!dt) return '';
  const ts = new Date(dt.replace(' ', 'T')).getTime();
  if (!ts) return dt;
  const date = new Date(ts);
  const pad = (n) => String(n).padStart(2, '0');
  return `${pad(date.getDate())}.${pad(date.getMonth() + 1)}.${date.getFullYear()} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
};

const autoUpper = (s) => (s || '').toUpperCase();

async function reload() {
  error.value = '';
  try {
    const { data, error: e } = await db.rpc('tit_get', { id: props.id });
    if (e) throw new Error(e);
    if (data?.error) throw new Error(data.error);
    req.value = data.request;
    vehicles.value = (data.vehicles || []).map(v => ({
      ...v,
      start_time_local: dbDateTimeToLocal(v.start_time, req.value?.delivery_date, '09:00'),
      end_time_local:   dbDateTimeToLocal(v.end_time,   req.value?.delivery_date, '16:00'),
    }));
    emails.value = data.emails || [];
    supplierDefaults.value = data.supplier_defaults || null;
    recommendedWarehouses.value = Array.isArray(data.recommended_warehouses) ? data.recommended_warehouses : [6];
    // Базовые поля для редактирования (поставщик/юрлицо/дата)
    basic.legal_entity = req.value?.legal_entity || '';
    basic.delivery_date = req.value?.delivery_date || '';
    // Все три юрлица — закупщик может сменить даже на юрлицо из ДРУГОЙ
    // группы (например, заявку для BK переключить на PS). Группа на бэке
    // пересчитывается автоматически по выбранному юрлицу (см. tit_update_basic).
    legalEntityOptions.value = [
      'ООО "Бургер БК"',
      'ООО "Воглия Матта"',
      'ООО "Пицца Стар"',
    ];
    loaded.value = true;
  } catch (e) {
    error.value = e.message || 'Не удалось загрузить заявку';
  }
}

function dbDateTimeToLocal(dt, fallbackDate, fallbackTime) {
  if (dt) {
    const iso = dt.replace(' ', 'T').slice(0, 16);
    return iso;
  }
  if (fallbackDate) {
    return fallbackDate + 'T' + fallbackTime;
  }
  return '';
}

function localToDbDateTime(local) {
  if (!local) return '';
  return local.replace('T', ' ') + ':00';
}

function addVehicle() {
  const tmp = req.value?.delivery_date || '';
  vehicles.value.push({
    id: 0, plate: '', plate_raw: '', phone: '', phone_raw: '',
    warehouse: recommendedWarehouses.value[0] || 6,
    allow_company: (recommendedWarehouses.value[0] || 6) === 1 ? 32 : 8,
    entry_kind: 1,
    start_time_local: tmp ? tmp + 'T09:00' : '',
    end_time_local:   tmp ? tmp + 'T16:00' : '',
    source: 'MANUAL', needs_review: 1, deleted_at: null,
  });
}

async function saveVehicle(v, confirm) {
  try {
    const plateOk = normalizePlate(v.plate).valid;
    const phoneOk = normalizePhone(v.phone).valid;
    if (confirm && (!plateOk || !phoneOk)) {
      alert('Для подтверждения нужны валидный номер машины и телефон.');
      return;
    }
    const { data, error: e } = await db.rpc('tit_vehicle_save', {
      request_id: props.id,
      vehicle_id: v.id || 0,
      plate: v.plate, phone: v.phone,
      warehouse: v.warehouse, entry_kind: v.entry_kind,
      start_time: localToDbDateTime(v.start_time_local),
      end_time:   localToDbDateTime(v.end_time_local),
      confirm,
    });
    if (e || data?.error) throw new Error(e || data.error);
    await reload();
    emit('changed');
  } catch (e) {
    alert('Не удалось сохранить: ' + (e.message || e));
  }
}

async function deleteVehicle(v) {
  if (!confirm('Удалить эту машину из заявки?')) return;
  if (!v.id) {
    // ещё не сохранена — просто убираем из памяти
    vehicles.value = vehicles.value.filter(x => x !== v);
    return;
  }
  try {
    await db.rpc('tit_vehicle_delete', { vehicle_id: v.id, request_id: props.id });
    await reload();
    emit('changed');
  } catch (e) { alert('Ошибка: ' + e.message); }
}

async function applyDefaults() {
  try {
    await db.rpc('tit_apply_supplier_default', { request_id: props.id });
    await reload();
    emit('changed');
  } catch (e) { alert('Ошибка: ' + e.message); }
}

async function cancelRequest() {
  if (!confirm('Отменить заявку? Машины не удалятся, но в xlsx охране не уйдёт.')) return;
  try {
    await db.rpc('tit_cancel', { id: props.id });
    emit('changed');
    emit('close');
  } catch (e) { alert('Ошибка: ' + e.message); }
}

async function deleteRequest() {
  if (!confirm('Удалить заявку полностью? Запись исчезнет из списка, привязанные письма станут «непривязанными».')) return;
  try {
    await db.rpc('tit_delete', { id: props.id });
    emit('changed');
    emit('close');
  } catch (e) { alert('Не удалось удалить: ' + (e.message || e)); }
}

// Автоматически удаляем пустую заявку при закрытии: если за время сессии
// закупщик не выбрал поставщика, не добавил машин, в логе нет писем — заявка
// никакой ценности не несёт, не оставляем «привидение».
async function tryClose() {
  const isEmpty = !req.value?.supplier_id
    && (vehicles.value || []).filter(v => !v.deleted_at).length === 0
    && (emails.value || []).length === 0
    && req.value?.status !== 'SENT';
  if (isEmpty) {
    try { await db.rpc('tit_delete', { id: props.id }); emit('changed'); } catch (_) {}
  }
  emit('close');
}

async function downloadXlsx() {
  try {
    const { data } = await db.rpc('tit_download_xlsx', { id: props.id });
    if (!data?.content_b64) throw new Error('Пустой файл');
    const bin = atob(data.content_b64);
    const arr = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    const blob = new Blob([arr], { type: data.mime });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = data.filename || 'tit.xlsx'; a.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  } catch (e) { alert('Не удалось скачать: ' + (e.message || e)); }
}

function openPreview() { previewOpen.value = true; }
function onSent() { previewOpen.value = false; emit('changed'); emit('close'); }

async function markSent() {
  if (!confirm('Пометить заявку как отправленную? Письмо через сайт не пойдёт — используйте, когда отправили вручную через свою почту.')) return;
  markingSent.value = true;
  try {
    const { error: e } = await db.rpc('tit_mark_sent', { id: props.id });
    if (e) throw new Error(e.message || 'Ошибка');
    emit('changed');
    emit('close');
  } catch (e) {
    alert('Не удалось: ' + (e.message || e));
  } finally {
    markingSent.value = false;
  }
}

onMounted(reload);
</script>

<style scoped>
.trm-overlay { position: fixed; inset: 0; background: rgba(80,35,20,.45); z-index: 1000; display: flex; align-items: flex-start; justify-content: center; padding: 24px; overflow-y: auto; }
.trm-modal { background: #FFF8ED; border-radius: 14px; width: 100%; max-width: 760px; box-shadow: 0 12px 40px rgba(0,0,0,.25); display: flex; flex-direction: column; max-height: calc(100vh - 48px); }
.trm-head { padding: 18px 22px; border-bottom: 1px solid #EDE2D2; display: flex; justify-content: space-between; align-items: start; gap: 12px; background: #fff; border-radius: 14px 14px 0 0; }
.trm-head h2 { margin: 0; font-size: 18px; color: var(--bk-brown, #502314); }
.trm-sub { margin: 4px 0 0; font-size: 13px; color: #8C7B6E; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.trm-sub-tag { font-size: 11px; padding: 2px 8px; border-radius: 999px; background: #E0E7FF; color: #3730A3; font-weight: 600; }

.trm-basic { margin-bottom: 16px; }
.trm-basic-grid { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 10px; }
.trm-basic-grid label { display: flex; flex-direction: column; font-size: 12px; color: #6B4F00; position: relative; }
.trm-basic-grid label > span { font-weight: 600; margin-bottom: 4px; }
.trm-basic-grid input, .trm-basic-grid select { height: 38px; border-radius: 8px; border: 1.5px solid #E5DDD3; padding: 0 10px; font-family: inherit; font-size: 14px; background: #fff; color: var(--bk-brown, #502314); }
.trm-basic-grid input:focus, .trm-basic-grid select:focus { outline: 2px solid var(--bk-orange, #F4A261); outline-offset: -1px; }
.trm-supplier-selected { display: flex; align-items: center; justify-content: space-between; padding: 8px 10px; background: #E8F5E9; color: #1B5E20; border-radius: 8px; font-size: 14px; min-height: 38px; box-sizing: border-box; }
.trm-supplier-selected button { background: transparent; border: none; color: #1B5E20; text-decoration: underline; cursor: pointer; font-size: 12px; }
.trm-supplier-list { list-style: none; padding: 0; margin: 4px 0 0; background: #fff; border: 1px solid #E5DDD3; border-radius: 8px; max-height: 200px; overflow-y: auto; position: absolute; z-index: 20; width: 100%; top: 60px; box-shadow: 0 4px 12px rgba(0,0,0,.1); }
.trm-supplier-list li { padding: 8px 12px; cursor: pointer; display: flex; justify-content: space-between; font-size: 13px; color: var(--bk-brown, #502314); }
.trm-supplier-list li:hover { background: #FFF8ED; }
.trm-supplier-list li span { color: #8C7B6E; font-size: 11px; }

@media (max-width: 600px) {
  .trm-basic-grid { grid-template-columns: 1fr; }
}
.trm-status { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; margin-left: 4px; }
.trm-status-waiting { background: #FFF3E0; color: #C05621; }
.trm-status-data_received { background: #E3F2FD; color: #1565C0; }
.trm-status-ready { background: #E8F5E9; color: #2E7D32; }
.trm-status-sent { background: #E0E0E0; color: #424242; }
.trm-status-cancelled { background: #FCE4EC; color: #AD1457; }
.trm-close { background: transparent; border: none; font-size: 22px; cursor: pointer; color: #8C7B6E; padding: 4px 8px; }

.trm-body { padding: 16px 22px; overflow-y: auto; flex: 1; }
.trm-section { margin-bottom: 22px; }
.trm-section h3 { margin: 0 0 12px; font-size: 14px; font-weight: 700; color: var(--bk-brown, #502314); text-transform: uppercase; letter-spacing: 0.04em; }
.trm-count { display: inline-block; background: #F0E6D5; color: #6B4F00; padding: 1px 8px; border-radius: 999px; font-size: 12px; margin-left: 4px; }
.trm-empty-block { background: #fff; border: 1.5px dashed #D8C9B0; border-radius: 10px; padding: 14px; color: #8C7B6E; font-size: 13px; text-align: center; }

.trm-vehicle { background: #fff; border: 1.5px solid #EDE2D2; border-radius: 12px; padding: 14px; margin-bottom: 10px; }
.trm-vehicle-head { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; }
.trm-vehicle-num { font-weight: 700; color: var(--bk-brown, #502314); font-size: 14px; }
.trm-source-badge { font-size: 11px; padding: 2px 8px; border-radius: 999px; background: #F0E6D5; color: #6B4F00; font-weight: 600; }
.trm-source-badge.src-email_text { background: #DBEAFE; color: #1E40AF; }
.trm-source-badge.src-email_ocr { background: #FCE7F3; color: #9D174D; }
.trm-source-badge.src-manual { background: #F0E6D5; color: #6B4F00; }
.trm-source-badge.src-suggestion { background: #E0E7FF; color: #3730A3; }
.trm-review-badge { font-size: 11px; padding: 2px 8px; border-radius: 999px; background: #FFF3E0; color: #C05621; font-weight: 600; margin-left: auto; }
.trm-confirmed-badge { font-size: 11px; padding: 2px 8px; border-radius: 999px; background: #E8F5E9; color: #2E7D32; font-weight: 600; margin-left: auto; }

.trm-vehicle-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.trm-vehicle-grid label { display: flex; flex-direction: column; font-size: 12px; color: #6B4F00; }
.trm-vehicle-grid label span { font-weight: 600; margin-bottom: 4px; }
.trm-vehicle-grid input, .trm-vehicle-grid select { height: 38px; border-radius: 8px; border: 1.5px solid #E5DDD3; padding: 0 10px; font-family: inherit; font-size: 14px; background: #fff; color: var(--bk-brown, #502314); }
.trm-vehicle-grid input:focus, .trm-vehicle-grid select:focus { outline: 2px solid var(--bk-orange, #F4A261); outline-offset: -1px; }
.trm-vehicle-grid small { color: #B0A090; font-size: 11px; margin-top: 4px; font-style: italic; }

.trm-vehicle-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 12px; }
.trm-vehicle-add { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
.trm-vehicle-add code { font-family: ui-monospace, Menlo, monospace; }

.trm-emails summary { cursor: pointer; padding: 8px 0; font-weight: 700; color: var(--bk-brown, #502314); }
.trm-emails ul { list-style: none; padding: 0; margin: 8px 0 0; }
.trm-emails li { background: #fff; border: 1px solid #EDE2D2; border-radius: 8px; padding: 10px 12px; margin-bottom: 8px; }
.trm-email-head { display: flex; justify-content: space-between; font-size: 13px; }
.trm-email-meta { color: #8C7B6E; font-size: 12px; }
.trm-email-subj { color: var(--bk-brown, #502314); font-size: 13px; margin: 4px 0; font-weight: 600; }
.trm-email-body { color: #6B4F00; font-size: 12px; white-space: pre-wrap; max-height: 100px; overflow: hidden; line-height: 1.4; }
.trm-email-parsed { font-size: 12px; color: #6B4F00; margin-top: 6px; }

.trm-sent-banner { background: #E8F5E9; border: 1.5px solid #66BB6A; color: #1B5E20; padding: 14px; border-radius: 10px; text-align: center; }

.trm-loading, .trm-error { padding: 60px 20px; text-align: center; color: #8C7B6E; }
.trm-error { color: #B91C1C; }
.trm-spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid #E5DDD3; border-top-color: var(--bk-red, #E76F51); border-radius: 50%; animation: trm-spin .8s linear infinite; vertical-align: middle; }
@keyframes trm-spin { to { transform: rotate(360deg); } }

.trm-foot { padding: 14px 22px; border-top: 1px solid #EDE2D2; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; background: #fff; border-radius: 0 0 14px 14px; }
.trm-btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 16px; border-radius: 10px; border: 1.5px solid transparent; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; }
.trm-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.trm-btn.sm { padding: 6px 12px; font-size: 12px; }
.trm-btn.primary { background: var(--bk-red, #E76F51); color: #fff; border-color: var(--bk-red, #E76F51); }
.trm-btn.primary:hover:not(:disabled) { background: var(--bk-red-dark, #C85A3E); }
.trm-btn.ghost { background: transparent; color: var(--bk-brown, #502314); border-color: #E5DDD3; }
.trm-btn.ghost:hover:not(:disabled) { background: #FFF8ED; border-color: #C9BBA8; }

@media (max-width: 600px) {
  .trm-overlay { padding: 0; }
  .trm-modal { max-height: 100vh; border-radius: 0; }
  .trm-vehicle-grid { grid-template-columns: 1fr; }
}
</style>
