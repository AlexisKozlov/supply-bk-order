<template>
  <Teleport to="body">
    <div class="modal" @click.self="$emit('close')">
      <div class="modal-box kr-edit-modal">
        <div class="modal-header">
          <h2>Заявка на возврат кег</h2>
          <button class="modal-close" @click="$emit('close')">✕</button>
        </div>

        <div v-if="loading" class="kr-em-loading">Загрузка...</div>
        <div v-else-if="loadError" class="kr-em-error">{{ loadError }}</div>

        <template v-else-if="form">
          <div class="kr-em-body">
            <div class="kr-em-field">
              <span class="kr-em-label">Ресторан</span>
              <span class="kr-em-value">№{{ form.restaurant_number }} {{ form.restaurant_city }} {{ form.restaurant_address }}</span>
            </div>

            <div class="kr-em-field">
              <label class="kr-em-label" for="kr-return-date">Дата возврата</label>
              <input id="kr-return-date" v-model="form.return_date" type="date" :disabled="readonly" class="kr-em-input" />
            </div>

            <div class="kr-em-field">
              <span class="kr-em-label">Серия БСО</span>
              <div style="flex:1;display:flex;flex-direction:column;gap:3px">
                <input v-model="form.bso_series" type="text" maxlength="2" placeholder="АА" :disabled="readonly" class="kr-em-input kr-em-input-sm" @blur="validateBso" />
                <span v-if="bsoError" class="kr-em-field-error">{{ bsoError }}</span>
              </div>
            </div>

            <div class="kr-em-field">
              <span class="kr-em-label">Номер БСО</span>
              <div style="flex:1;display:flex;flex-direction:column;gap:3px">
                <input v-model="form.bso_number" type="text" placeholder="0000000" :disabled="readonly" class="kr-em-input kr-em-input-sm" @blur="validateBso" />
              </div>
            </div>

            <div v-if="(form.bso_history || []).length" class="kr-em-bso-history">
              <div class="kr-em-bso-history-head">
                <span class="kr-em-bso-history-tag">БСО заменён {{ form.bso_history.length }}×</span>
              </div>
              <ul class="kr-em-bso-history-list">
                <li v-for="h in form.bso_history" :key="h.id">
                  <span class="kr-em-bso-old">{{ h.old_series || '—' }} {{ h.old_number || '' }}</span>
                  <span class="kr-em-bso-arrow">→</span>
                  <span class="kr-em-bso-new">{{ h.new_series }} {{ h.new_number }}</span>
                  <span class="kr-em-bso-time">{{ fmtDateTime(h.changed_at) }}</span>
                  <span class="kr-em-bso-by">{{ h.changed_by_user || (h.changed_by_ru_user_id ? 'ресторан' : '') }}</span>
                  <div class="kr-em-bso-reason">{{ h.reason }}</div>
                </li>
              </ul>
            </div>

            <div class="kr-em-field">
              <span class="kr-em-label">Статус</span>
              <span :class="'kr-badge kr-badge-' + form.status">{{ statusLabel(form.status) }}</span>
            </div>

            <div class="kr-em-field">
              <span class="kr-em-label">Машина</span>
              <input v-model="form.vehicle" type="text" :disabled="readonly" class="kr-em-input" />
            </div>

            <div class="kr-em-field">
              <span class="kr-em-label">Водитель</span>
              <input v-model="form.driver" type="text" :disabled="readonly" class="kr-em-input" />
            </div>

            <div class="kr-em-field">
              <span class="kr-em-label">Сдал грузоотправитель</span>
              <input v-model="form.sender_position_name" type="text" :disabled="readonly" class="kr-em-input" />
            </div>

            <div class="kr-em-kegs-block">
              <div class="kr-em-kegs-title">Кеги</div>
              <div v-if="catalogLoading" class="kr-em-sub">Загрузка каталога...</div>
              <div v-else class="kr-em-catalog">
                <div v-for="keg in catalog" :key="keg.code" class="kr-em-keg-row">
                  <button
                    type="button"
                    class="kr-em-keg-thumb"
                    :class="{ 'has-photo': keg.photo_url }"
                    :disabled="!keg.photo_url"
                    :title="keg.photo_url ? 'Открыть фото' : 'Фото не загружено'"
                    @click="openKegPhoto(keg)"
                  >
                    <img v-if="keg.photo_url" :src="keg.photo_url" :alt="keg.name" />
                    <span v-else class="kr-em-keg-thumb-ph">🛢️</span>
                  </button>
                  <div class="kr-em-keg-info">
                    <div class="kr-em-keg-name">{{ keg.name }}</div>
                    <div class="kr-em-keg-code">{{ keg.code }}</div>
                  </div>
                  <input
                    type="number"
                    min="0"
                    :value="kegQty(keg.code)"
                    @change="setKegQty(keg.code, $event.target.value)"
                    :disabled="readonly"
                    class="kr-em-qty"
                  />
                </div>
              </div>
            </div>
          </div>

          <div v-if="saveError" class="kr-em-save-error">{{ saveError }}</div>

          <div class="modal-actions kr-em-actions">
            <button class="btn kr-em-icon-btn" @click="downloadExcel" :disabled="saving" title="Скачать Excel" aria-label="Скачать Excel">
              <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true">
                <rect x="3" y="3" width="18" height="18" rx="2.5" fill="#107C41"/>
                <path d="M9 8 L15 16 M15 8 L9 16" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/>
              </svg>
            </button>
            <button class="btn kr-em-icon-btn" @click="printTtn" :disabled="saving" title="Печать" aria-label="Печать">🖨️</button>
            <button v-if="!readonly" class="btn btn-danger" @click="cancelReturn" :disabled="saving">Отменить</button>
            <button v-if="form && form.status === 'ROUTED'" class="btn btn-warn" @click="unroute" :disabled="saving" title="Откатить статус в «Отправлена» и уведомить ресторан">Отменить маршрутизацию</button>
            <button v-if="form && form.status === 'ROUTED'" class="btn btn-danger" @click="markNotReturned" :disabled="saving" title="Ресторан не сдал кеги — уведомить бухгалтерию">Не сдана</button>
            <button v-if="form && form.status === 'NOT_RETURNED'" class="btn btn-warn" @click="revertNotReturned" :disabled="saving" title="Вернуть в «Маршрутизирована»">Вернуть «Не сдана»</button>
            <button class="btn btn-danger" @click="deleteRequest" :disabled="saving">Удалить</button>
            <button v-if="!readonly" class="btn" @click="save(false)" :disabled="saving">
              {{ saving && !routing ? 'Сохранение...' : 'Сохранить' }}
            </button>
            <button
              v-if="canRoute"
              class="btn primary"
              @click="save(true)"
              :disabled="saving || !routeReady"
              :title="routeReady ? 'Сохранить и маршрутизировать' : 'Заполните машину и водителя'"
            >
              {{ routing ? 'Маршрутизация...' : 'Маршрутизировать' }}
            </button>
          </div>
        </template>

        <!-- Полноэкранный просмотр фото кеги -->
        <div v-if="photoUrl" class="kr-em-photo-overlay" @click.self="photoUrl = ''">
          <div class="kr-em-photo-modal">
            <div class="kr-em-photo-head">
              <span>{{ photoName }}</span>
              <button class="kr-em-photo-close" @click="photoUrl = ''" aria-label="Закрыть">×</button>
            </div>
            <img :src="photoUrl" :alt="photoName" />
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { db } from '@/lib/apiClient.js';
import { appConfirm } from '@/lib/appDialogs.js';

function authHeaders(extra = {}) {
  const t = localStorage.getItem('bk_session_token') || '';
  const h = { ...extra };
  if (t) h['X-Session-Token'] = t;
  return h;
}

const props = defineProps({ id: { type: [Number, String], required: true } });
const emit = defineEmits(['close']);

const form = ref(null);
const catalog = ref([]);
const loading = ref(false);
const catalogLoading = ref(false);
const loadError = ref('');
const saving = ref(false);
const routing = ref(false);
const saveError = ref('');
const kegQties = ref({});
const bsoError = ref('');
const photoUrl = ref('');
const photoName = ref('');

function openKegPhoto(keg) {
  if (!keg.photo_url) return;
  photoUrl.value = keg.photo_url;
  photoName.value = keg.name;
}

const canRoute = computed(() => form.value && form.value.status === 'SUBMITTED');
const routeReady = computed(() => {
  const v = (form.value?.vehicle || '').trim();
  const d = (form.value?.driver || '').trim();
  return !!v && !!d;
});

function validateBso() {
  const s = (form.value?.bso_series || '').trim();
  const n = (form.value?.bso_number || '').trim();
  if (!s && !n) { bsoError.value = ''; return true; }
  if (!/^[А-ЯЁ]{2}$/u.test(s)) { bsoError.value = 'Серия — две заглавные кириллические буквы'; return false; }
  if (!/^\d{7}$/.test(n)) { bsoError.value = 'Номер — ровно 7 цифр'; return false; }
  bsoError.value = '';
  return true;
}

const readonly = computed(() => form.value && (form.value.status === 'ROUTED' || form.value.status === 'CANCELLED' || form.value.status === 'NOT_RETURNED'));

async function checkRoutedWarning() {
  const status = form.value?.status;
  if (status !== 'ROUTED' && status !== 'CANCELLED') {
    return await appConfirm('Заявка ещё не маршрутизирована. Водителя и автомобиль нужно будет вписать в накладную вручную. Продолжить?', { title: 'Скачивание накладной', okText: 'Продолжить' });
  }
  return true;
}

async function downloadExcel() {
  if (!(await checkRoutedWarning())) return;
  try {
    const res = await fetch(`/api/keg-returns/${props.id}/excel`, { credentials: 'include', headers: authHeaders() });
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      throw new Error(data.error || 'Ошибка скачивания');
    }
    const blob = await res.blob();
    const cd = res.headers.get('Content-Disposition') || '';
    const m = cd.match(/filename="?([^"]+)"?/);
    const filename = m ? m[1] : `TTN_${props.id}.xlsx`;
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
  } catch (e) {
    saveError.value = e.message;
  }
}

async function printTtn() {
  if (!(await checkRoutedWarning())) return;
  // window.open вызываем синхронно, иначе всплывают попап-блокеры.
  // Заполняем сначала about:blank, потом меняем location на ссылку с
  // одноразовым download-токеном (?dl=). Если токен не получили —
  // fallback на старый ?token=session_token.
  const win = window.open('about:blank', '_blank');
  try {
    const { data } = await db.rpc('create_download_token', { file_path: `keg-returns/${props.id}/print` });
    if (data?.token) {
      const url = `/api/keg-returns/${props.id}/print?dl=${encodeURIComponent(data.token)}`;
      if (win) win.location = url; else window.open(url, '_blank');
      return;
    }
  } catch (e) { /* fallback ниже */ }
  const t = localStorage.getItem('bk_session_token') || '';
  const url = `/api/keg-returns/${props.id}/print?token=${encodeURIComponent(t)}`;
  if (win) win.location = url; else window.open(url, '_blank');
}

async function deleteRequest() {
  if (!(await appConfirm('Удалить заявку? Это нельзя отменить.', { okText: 'Удалить', danger: true }))) return;
  saving.value = true; saveError.value = '';
  try {
    const res = await fetch(`/api/keg-returns/${props.id}`, { method: 'DELETE', credentials: 'include', headers: authHeaders() });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка');
    emit('close');
  } catch (e) { saveError.value = e.message; }
  finally { saving.value = false; }
}

async function loadData() {
  loading.value = true;
  loadError.value = '';
  try {
    const res = await fetch(`/api/keg-returns/${props.id}`, { credentials: 'include', headers: authHeaders() });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка загрузки');
    form.value = data;
    kegQties.value = {};
    for (const item of data.items || []) {
      kegQties.value[item.keg_code] = item.quantity;
    }
  } catch (e) {
    loadError.value = e.message;
  } finally {
    loading.value = false;
  }
}

async function loadCatalog() {
  catalogLoading.value = true;
  try {
    const res = await fetch('/api/keg-catalog', { credentials: 'include', headers: authHeaders() });
    const data = await res.json();
    catalog.value = Array.isArray(data) ? data : [];
  } catch {}
  catalogLoading.value = false;
}

function kegQty(code) {
  return kegQties.value[code] || 0;
}

function setKegQty(code, val) {
  const n = parseInt(val, 10);
  if (n > 0) kegQties.value[code] = n;
  else delete kegQties.value[code];
}

async function save(route = false) {
  if (!validateBso()) return;
  saving.value = true;
  routing.value = !!route;
  saveError.value = '';
  try {
    const items = Object.entries(kegQties.value)
      .filter(([, qty]) => qty > 0)
      .map(([keg_code, quantity]) => ({ keg_code, quantity: Number(quantity) }));
    const body = {
      return_date: form.value.return_date,
      bso_series: form.value.bso_series,
      bso_number: form.value.bso_number,
      vehicle: form.value.vehicle,
      driver: form.value.driver,
      sender_position_name: form.value.sender_position_name,
      items,
    };
    if (route) body._route = true;
    const res = await fetch(`/api/keg-returns/${props.id}`, {
      method: 'PATCH',
      credentials: 'include',
      headers: authHeaders({ 'Content-Type': 'application/json' }),
      body: JSON.stringify(body),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || (route ? 'Ошибка маршрутизации' : 'Ошибка сохранения'));
    emit('close');
  } catch (e) {
    saveError.value = e.message;
  } finally {
    saving.value = false;
    routing.value = false;
  }
}

async function unroute() {
  if (!(await appConfirm('Откатить маршрутизацию? Заявка вернётся в статус «Отправлена», ресторан получит уведомление в Telegram и push.', { title: 'Откатить маршрутизацию', okText: 'Откатить', danger: true }))) return;
  saving.value = true;
  saveError.value = '';
  try {
    const res = await fetch(`/api/keg-returns/${props.id}/unroute`, {
      method: 'POST',
      credentials: 'include',
      headers: authHeaders(),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка отмены маршрутизации');
    emit('close');
  } catch (e) {
    saveError.value = e.message;
  } finally {
    saving.value = false;
  }
}

async function markNotReturned() {
  if (!(await appConfirm('Отметить, что ресторан не сдал кеги? Заявка перейдёт в статус «Не сдана», бухгалтерия получит письмо (если указаны адреса).', { title: 'Кеги не сданы', okText: 'Не сдана', danger: true }))) return;
  saving.value = true;
  saveError.value = '';
  try {
    const res = await fetch(`/api/keg-returns/${props.id}/not-returned`, {
      method: 'POST',
      credentials: 'include',
      headers: authHeaders(),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка');
    emit('close');
  } catch (e) {
    saveError.value = e.message;
  } finally {
    saving.value = false;
  }
}

async function revertNotReturned() {
  if (!(await appConfirm('Вернуть заявку в «Маршрутизирована»?', { title: 'Вернуть статус', okText: 'Вернуть', danger: false }))) return;
  saving.value = true;
  saveError.value = '';
  try {
    const res = await fetch(`/api/keg-returns/${props.id}/revert-not-returned`, {
      method: 'POST',
      credentials: 'include',
      headers: authHeaders(),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка');
    emit('close');
  } catch (e) {
    saveError.value = e.message;
  } finally {
    saving.value = false;
  }
}

async function cancelReturn() {
  if (!(await appConfirm('Отменить заявку?', { okText: 'Отменить заявку', danger: true }))) return;
  saving.value = true;
  saveError.value = '';
  try {
    const res = await fetch(`/api/keg-returns/${props.id}/cancel`, {
      method: 'POST',
      credentials: 'include',
      headers: authHeaders(),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка');
    emit('close');
  } catch (e) {
    saveError.value = e.message;
  } finally {
    saving.value = false;
  }
}

function statusLabel(s) {
  const map = { DRAFT: 'Черновик', SUBMITTED: 'Отправлена', ROUTED: 'Маршрутизирована', CANCELLED: 'Отменена', NOT_RETURNED: 'Не сдана' };
  return map[s] || s;
}

function fmtDateTime(s) {
  if (!s) return '';
  const d = new Date(s.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return s;
  const date = d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
  const time = d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  return `${date} ${time}`;
}

onMounted(() => {
  loadData();
  loadCatalog();
});
</script>

<style scoped>
.kr-edit-modal { max-width: 560px; width: 100%; margin: auto; }
.kr-em-body { display: flex; flex-direction: column; gap: 12px; padding: 16px 0; max-height: 70vh; overflow-y: auto; }
.kr-em-field { display: flex; align-items: center; gap: 12px; }
.kr-em-label { width: 190px; flex-shrink: 0; font-size: 13px; color: var(--text-secondary, #666); }
.kr-em-value { font-size: 14px; }
.kr-em-input { flex: 1; padding: 6px 10px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 14px; background: var(--input-bg, #fff); color: inherit; }
.kr-em-input:disabled { background: var(--input-disabled-bg, #f5f5f5); }
.kr-em-input-sm { max-width: 100px; flex: none; }
.kr-em-kegs-block { display: flex; flex-direction: column; gap: 10px; margin-top: 6px; }
.kr-em-kegs-title { font-size: 13px; font-weight: 600; color: var(--text-secondary, #666); }
.kr-em-catalog { display: flex; flex-direction: column; gap: 8px; }
.kr-em-keg-row {
  display: grid; grid-template-columns: 56px 1fr 110px;
  gap: 14px; align-items: center;
  padding: 10px 12px; border: 1px solid var(--border-color, #eee);
  border-radius: 8px; background: var(--card, #fff);
}
.kr-em-keg-thumb {
  width: 48px; height: 48px; border-radius: 8px;
  border: 1px solid #ECE3D6; background: #FFF8F0;
  overflow: hidden; padding: 0; cursor: zoom-in;
  display: flex; align-items: center; justify-content: center;
  font-family: inherit;
}
.kr-em-keg-thumb:disabled { cursor: default; }
.kr-em-keg-thumb.has-photo:hover { border-color: #E76F51; }
.kr-em-keg-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.kr-em-keg-thumb-ph { font-size: 22px; color: #C7B9A7; }
.kr-em-keg-info { min-width: 0; }
.kr-em-keg-name { font-size: 14px; font-weight: 500; line-height: 1.3; color: #2C1A12; word-wrap: break-word; }
.kr-em-keg-code {
  font-size: 11px; color: #8B7355; margin-top: 2px;
  font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace;
  font-variant-numeric: tabular-nums;
}
.kr-em-qty { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 16px; font-weight: 600; text-align: center; background: var(--input-bg, #fff); color: inherit; }
.kr-em-photo-overlay {
  position: fixed; inset: 0; z-index: 1100;
  background: rgba(20,10,5,.72); backdrop-filter: blur(2px);
  display: flex; align-items: center; justify-content: center; padding: 20px;
}
.kr-em-photo-modal {
  background: #fff; border-radius: 14px; overflow: hidden;
  max-width: 100%; max-height: 100%;
  display: flex; flex-direction: column;
}
.kr-em-photo-head {
  display: flex; align-items: center; justify-content: space-between;
  gap: 16px; padding: 12px 14px; background: #2C1A12; color: #fff;
  font-size: 14px;
}
.kr-em-photo-close {
  background: none; border: none; color: #fff;
  font-size: 28px; line-height: 1; cursor: pointer; padding: 0 6px;
  font-family: inherit;
}
.kr-em-photo-modal img {
  display: block; max-width: 80vw; max-height: 78vh; object-fit: contain;
  background: #FAF6EF;
}
.kr-em-qty:disabled { background: var(--input-disabled-bg, #f5f5f5); }
.kr-em-sub { font-size: 13px; color: var(--text-secondary, #999); }
.kr-em-loading, .kr-em-error { padding: 24px; text-align: center; }
.kr-em-error { color: var(--danger, #e53935); }
.kr-em-save-error { color: var(--danger, #e53935); font-size: 13px; padding: 0 16px 8px; }
.kr-em-field-error { color: var(--danger, #e53935); font-size: 12px; }
.kr-em-actions { justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
.kr-em-icon-btn {
  padding: 6px 10px;
  font-size: 18px;
  line-height: 1;
  min-width: 38px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.kr-em-icon-btn svg { display: block; }
.kr-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: 600; }
.kr-badge-DRAFT { background: #e0e0e0; color: #555; }
.kr-badge-SUBMITTED { background: #fff3e0; color: #e65100; }
.kr-badge-ROUTED { background: #e8f5e9; color: #2e7d32; }
.kr-badge-CANCELLED { background: #fce4ec; color: #c62828; }
.kr-badge-NOT_RETURNED { background: #fde0dc; color: #b71c1c; }
.btn-danger { background: #fce4ec; color: #c62828; }
.btn-danger:hover { background: #f8bbd0; }
.btn-warn { background: #FFF3E0; color: #C16B4D; }
.btn-warn:hover { background: #FFE0B2; }

/* История замен БСО */
.kr-em-bso-history {
  background: #FFF8F0; border-left: 3px solid #F4A261; border-radius: 6px;
  padding: 10px 12px; margin: -2px 0 4px;
}
.kr-em-bso-history-head { margin-bottom: 6px; }
.kr-em-bso-history-tag {
  display: inline-block; padding: 2px 9px; border-radius: 999px;
  background: #FFE0B2; color: #C16B4D;
  font-size: 11px; font-weight: 700;
}
.kr-em-bso-history-list { list-style: none; padding: 0; margin: 0; }
.kr-em-bso-history-list li {
  padding: 6px 0; border-bottom: 1px dashed #E8DCC8;
  font-size: 13px;
  display: grid;
  grid-template-columns: auto auto auto 1fr auto;
  gap: 6px 8px;
  align-items: baseline;
}
.kr-em-bso-history-list li:last-child { border-bottom: none; }
.kr-em-bso-old { color: #8B7355; text-decoration: line-through; font-weight: 600; font-variant-numeric: tabular-nums; }
.kr-em-bso-arrow { color: #C16B4D; }
.kr-em-bso-new { font-weight: 700; color: #2C1A12; font-variant-numeric: tabular-nums; }
.kr-em-bso-time { font-size: 12px; color: #8B7355; white-space: nowrap; }
.kr-em-bso-by { font-size: 12px; color: #8B7355; }
.kr-em-bso-reason {
  grid-column: 1 / -1;
  font-size: 12.5px; color: #6B5344;
}
</style>
