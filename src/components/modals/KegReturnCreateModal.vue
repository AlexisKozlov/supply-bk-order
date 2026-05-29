<template>
  <Teleport to="body">
    <div class="modal" @click.self="closeIfClean">
      <div class="modal-box kr-create-modal">
        <div class="modal-header">
          <h2>Новая заявка на возврат кег</h2>
          <button class="modal-close" @click="closeIfClean">✕</button>
        </div>

        <div class="kr-cm-body">
          <!-- Ресторан -->
          <div class="kr-cm-field">
            <label class="kr-cm-label" for="kr-cm-rest">Ресторан</label>
            <select
              id="kr-cm-rest"
              v-model.number="form.restaurant_id"
              :disabled="saving"
              class="kr-cm-input"
              @change="onRestaurantChange"
            >
              <option :value="0">— выберите ресторан —</option>
              <option v-for="r in eligibleRestaurants" :key="r.id" :value="r.id">
                №{{ r.number }} {{ r.city || '' }}{{ r.address ? ', ' + r.address : '' }}
              </option>
            </select>
            <div v-if="!eligibleRestaurants.length" class="kr-cm-hint kr-cm-hint-warn">
              Возврат кег пока поддерживается только для группы «Бургер БК / Воглия Матта».
            </div>
          </div>

          <!-- Дата возврата -->
          <div class="kr-cm-field">
            <label class="kr-cm-label" for="kr-cm-date">Дата возврата</label>
            <select
              v-if="availableDates.length"
              id="kr-cm-date"
              v-model="form.return_date"
              :disabled="saving || !selectedRestaurant"
              class="kr-cm-input"
            >
              <option value="">— выберите дату —</option>
              <option v-for="d in availableDates" :key="d.iso" :value="d.iso">{{ d.label }}</option>
              <option value="__custom__">Другая дата (вне графика)…</option>
            </select>
            <input
              v-else-if="useCustomDate || (selectedRestaurant && !selectedRestaurant.pickup_weekdays)"
              v-model="form.return_date"
              type="date"
              :disabled="saving"
              class="kr-cm-input"
            />
            <div v-else-if="!selectedRestaurant" class="kr-cm-hint">Сначала выберите ресторан.</div>
            <div v-else class="kr-cm-hint kr-cm-hint-warn">
              У ресторана не настроен график возврата — будет введена произвольная дата.
            </div>
          </div>

          <!-- БСО -->
          <div class="kr-cm-field-row">
            <div class="kr-cm-field kr-cm-field-half">
              <label class="kr-cm-label" for="kr-cm-bso-s">Серия БСО</label>
              <input
                id="kr-cm-bso-s"
                :value="form.bso_series"
                @input="onSeriesInput"
                type="text"
                maxlength="2"
                placeholder="АА"
                :disabled="saving"
                class="kr-cm-input kr-cm-input-mono"
              />
            </div>
            <div class="kr-cm-field kr-cm-field-half">
              <label class="kr-cm-label" for="kr-cm-bso-n">Номер БСО</label>
              <input
                id="kr-cm-bso-n"
                :value="form.bso_number"
                @input="onNumberInput"
                type="text"
                inputmode="numeric"
                maxlength="7"
                placeholder="0000000"
                :disabled="saving"
                class="kr-cm-input kr-cm-input-mono"
              />
            </div>
          </div>

          <!-- Сдал грузоотправитель -->
          <div class="kr-cm-field">
            <label class="kr-cm-label" for="kr-cm-sender">Сдал грузоотправитель</label>
            <input
              id="kr-cm-sender"
              v-model="form.sender_position_name"
              type="text"
              placeholder="Управляющий рестораном Иванов И.И."
              :disabled="saving"
              class="kr-cm-input"
            />
          </div>

          <!-- Машина / водитель -->
          <div class="kr-cm-field-row">
            <div class="kr-cm-field kr-cm-field-half">
              <label class="kr-cm-label" for="kr-cm-veh">Машина</label>
              <input
                id="kr-cm-veh"
                v-model="form.vehicle"
                type="text"
                :disabled="saving"
                class="kr-cm-input"
              />
            </div>
            <div class="kr-cm-field kr-cm-field-half">
              <label class="kr-cm-label" for="kr-cm-drv">Водитель</label>
              <input
                id="kr-cm-drv"
                v-model="form.driver"
                type="text"
                :disabled="saving"
                class="kr-cm-input"
              />
            </div>
          </div>

          <!-- Кеги -->
          <div class="kr-cm-kegs-block">
            <div class="kr-cm-kegs-title">Кеги</div>
            <div v-if="catalogLoading" class="kr-cm-hint">Загрузка каталога…</div>
            <div v-else-if="!catalog.length" class="kr-cm-hint">Каталог пуст.</div>
            <div v-else class="kr-cm-catalog">
              <div v-for="keg in catalog" :key="keg.code" class="kr-cm-keg-row">
                <button
                  type="button"
                  class="kr-cm-keg-thumb"
                  :class="{ 'has-photo': keg.photo_url }"
                  :disabled="!keg.photo_url"
                  :title="keg.photo_url ? 'Открыть фото' : 'Фото не загружено'"
                  @click="openPhoto(keg)"
                >
                  <img v-if="keg.photo_url" :src="keg.photo_url" :alt="keg.name" />
                  <span v-else class="kr-cm-keg-thumb-ph">🛢️</span>
                </button>
                <div class="kr-cm-keg-info">
                  <div class="kr-cm-keg-name">{{ keg.name }}</div>
                  <div class="kr-cm-keg-code">{{ keg.code }}</div>
                </div>
                <input
                  type="number"
                  min="0"
                  :value="kegQty(keg.code)"
                  @input="setKegQty(keg.code, $event.target.value)"
                  :disabled="saving"
                  class="kr-cm-qty"
                />
              </div>
            </div>
          </div>
        </div>

        <div v-if="saveError" class="kr-cm-error">{{ saveError }}</div>

        <div class="modal-actions kr-cm-actions">
          <button class="btn" @click="closeIfClean" :disabled="saving">Отмена</button>
          <button
            class="btn"
            @click="submit(false)"
            :disabled="saving || !canSubmitDraft"
            :title="canSubmitDraft ? 'Сохранить как черновик' : 'Выберите ресторан и дату'"
          >
            {{ saving && !submitting ? 'Сохранение…' : 'Сохранить черновик' }}
          </button>
          <button
            class="btn primary"
            @click="submit(true)"
            :disabled="saving || !canSubmitFinal"
            :title="canSubmitFinal ? 'Создать заявку и отправить на маршрутизацию' : 'Заполните ресторан, дату, БСО, грузоотправителя и кеги'"
          >
            {{ submitting ? 'Отправка…' : 'Создать и отправить' }}
          </button>
        </div>

        <!-- Полноэкранный просмотр фото кеги -->
        <div v-if="photoUrl" class="kr-cm-photo-overlay" @click.self="photoUrl = ''">
          <div class="kr-cm-photo-modal">
            <div class="kr-cm-photo-head">
              <span>{{ photoName }}</span>
              <button class="kr-cm-photo-close" @click="photoUrl = ''" aria-label="Закрыть">×</button>
            </div>
            <img :src="photoUrl" :alt="photoName" />
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { buildAvailableDates, maskBsoSeries, maskBsoNumber } from '@/components/restaurant/keg/kegHelpers.js';
import { appConfirm } from '@/lib/appDialogs.js';

const props = defineProps({
  restaurants: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'created']);

function authHeaders(extra = {}) {
  const t = localStorage.getItem('bk_session_token') || '';
  const h = { ...extra };
  if (t) h['X-Session-Token'] = t;
  return h;
}

const form = reactive({
  restaurant_id: 0,
  return_date: '',
  bso_series: '',
  bso_number: '',
  sender_position_name: '',
  vehicle: '',
  driver: '',
});
const kegQties = reactive({});
const catalog = ref([]);
const catalogLoading = ref(false);
const saving = ref(false);
const submitting = ref(false);
const saveError = ref('');
const photoUrl = ref('');
const photoName = ref('');
const useCustomDate = ref(false);

// Сейчас бэк разрешает создавать заявки только для группы BK_VM.
// И прячем рестораны, у которых возврат кег отключён (keg_returns_enabled = 0):
// бэк всё равно их отклонит при отправке, а так не показываем тупиковые варианты.
// NULL/undefined трактуем как «включено» — это дефолт по схеме.
const eligibleRestaurants = computed(() =>
  props.restaurants.filter(r =>
    (r.legal_entity_group || 'BK_VM') === 'BK_VM' &&
    Number(r.keg_returns_enabled ?? 1) !== 0
  )
);

const selectedRestaurant = computed(
  () => eligibleRestaurants.value.find(r => r.id === form.restaurant_id) || null
);

const availableDates = computed(() => {
  if (!selectedRestaurant.value) return [];
  if (useCustomDate.value) return [];
  return buildAvailableDates(selectedRestaurant.value.pickup_weekdays || 0, '');
});

const totalKegs = computed(() =>
  Object.values(kegQties).reduce((s, n) => s + (Number(n) || 0), 0)
);

const canSubmitDraft = computed(() =>
  !!form.restaurant_id && !!form.return_date && form.return_date !== '__custom__'
);

const canSubmitFinal = computed(() => {
  if (!canSubmitDraft.value) return false;
  const s = (form.bso_series || '').trim();
  const n = (form.bso_number || '').trim();
  if (!/^[А-ЯЁ]{2}$/u.test(s)) return false;
  if (!/^\d{7}$/.test(n)) return false;
  if (!(form.sender_position_name || '').trim()) return false;
  if (totalKegs.value <= 0) return false;
  return true;
});

watch(() => form.return_date, val => {
  if (val === '__custom__') {
    useCustomDate.value = true;
    form.return_date = '';
  }
});

function onRestaurantChange() {
  const r = selectedRestaurant.value;
  form.return_date = '';
  useCustomDate.value = false;
  form.vehicle = r?.default_vehicle || '';
  form.driver = r?.default_driver || '';
}

function onSeriesInput(e) {
  const filtered = maskBsoSeries(e.target.value);
  form.bso_series = filtered;
  if (e.target.value !== filtered) e.target.value = filtered;
}
function onNumberInput(e) {
  const filtered = maskBsoNumber(e.target.value);
  form.bso_number = filtered;
  if (e.target.value !== filtered) e.target.value = filtered;
}

function kegQty(code) { return kegQties[code] || 0; }
function setKegQty(code, val) {
  const n = parseInt(val, 10);
  if (n > 0) kegQties[code] = n;
  else delete kegQties[code];
}

function openPhoto(keg) {
  if (!keg.photo_url) return;
  photoUrl.value = keg.photo_url;
  photoName.value = keg.name;
}

async function loadCatalog() {
  catalogLoading.value = true;
  try {
    const res = await fetch('/api/keg-catalog?legal_entity_group=BK_VM', {
      credentials: 'include', headers: authHeaders(),
    });
    const data = await res.json();
    catalog.value = Array.isArray(data) ? data : [];
  } catch { catalog.value = []; }
  catalogLoading.value = false;
}

function hasDirtyData() {
  if (form.restaurant_id) return true;
  if (form.bso_series || form.bso_number) return true;
  if (form.sender_position_name) return true;
  if (totalKegs.value > 0) return true;
  return false;
}

async function closeIfClean() {
  if (saving.value) return;
  if (hasDirtyData() && !(await appConfirm('Закрыть без сохранения?', { okText: 'Закрыть', danger: true }))) return;
  emit('close');
}

async function submit(thenSubmit) {
  if (!canSubmitDraft.value) return;
  if (thenSubmit && !canSubmitFinal.value) return;
  saving.value = true;
  submitting.value = thenSubmit;
  saveError.value = '';
  try {
    const items = Object.entries(kegQties)
      .filter(([, qty]) => qty > 0)
      .map(([keg_code, quantity]) => ({ keg_code, quantity: Number(quantity) }));

    const body = {
      restaurant_id: form.restaurant_id,
      return_date: form.return_date,
      bso_series: form.bso_series.trim() || null,
      bso_number: form.bso_number.trim() || null,
      sender_position_name: form.sender_position_name.trim(),
      vehicle: form.vehicle.trim(),
      driver: form.driver.trim(),
      items,
    };

    const createRes = await fetch('/api/keg-returns', {
      method: 'POST',
      credentials: 'include',
      headers: authHeaders({ 'Content-Type': 'application/json' }),
      body: JSON.stringify(body),
    });
    const created = await createRes.json();
    if (!createRes.ok) throw new Error(created.error || 'Ошибка создания заявки');

    if (thenSubmit) {
      const submitRes = await fetch(`/api/keg-returns/${created.id}/submit`, {
        method: 'POST',
        credentials: 'include',
        headers: authHeaders({ 'Content-Type': 'application/json' }),
      });
      const submitted = await submitRes.json();
      if (!submitRes.ok) throw new Error(submitted.error || 'Заявка создана, но не отправлена');
    }

    emit('created', created);
  } catch (e) {
    saveError.value = e.message;
  } finally {
    saving.value = false;
    submitting.value = false;
  }
}

onMounted(() => {
  loadCatalog();
});
</script>

<style scoped>
.kr-create-modal { max-width: 620px; width: 100%; margin: auto; }
.kr-cm-body {
  display: flex; flex-direction: column; gap: 12px;
  padding: 16px 0; max-height: 70vh; overflow-y: auto;
}
.kr-cm-field { display: flex; flex-direction: column; gap: 4px; }
.kr-cm-field-row { display: flex; gap: 12px; }
.kr-cm-field-half { flex: 1; min-width: 0; }
.kr-cm-label { font-size: 13px; color: var(--text-secondary, #666); }
.kr-cm-input {
  padding: 7px 10px; border: 1px solid var(--border-color, #ddd); border-radius: 6px;
  font-size: 14px; background: var(--input-bg, #fff); color: inherit; width: 100%; box-sizing: border-box;
}
.kr-cm-input:disabled { background: var(--input-disabled-bg, #f5f5f5); }
.kr-cm-input-mono { font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace; }
.kr-cm-hint { font-size: 12.5px; color: var(--text-secondary, #999); padding: 2px 0; }
.kr-cm-hint-warn { color: #C16B4D; }
.kr-cm-error { color: var(--danger, #e53935); font-size: 13px; padding: 4px 16px 0; }

.kr-cm-kegs-block { display: flex; flex-direction: column; gap: 8px; margin-top: 6px; }
.kr-cm-kegs-title { font-size: 13px; font-weight: 600; color: var(--text-secondary, #666); }
.kr-cm-catalog { display: flex; flex-direction: column; gap: 8px; }
.kr-cm-keg-row {
  display: grid; grid-template-columns: 56px 1fr 110px; gap: 12px; align-items: center;
  padding: 8px 10px; border: 1px solid var(--border-color, #eee); border-radius: 8px;
  background: var(--card, #fff);
}
.kr-cm-keg-thumb {
  width: 48px; height: 48px; border-radius: 8px;
  border: 1px solid #ECE3D6; background: #FFF8F0;
  overflow: hidden; padding: 0; cursor: zoom-in;
  display: flex; align-items: center; justify-content: center;
  font-family: inherit;
}
.kr-cm-keg-thumb:disabled { cursor: default; }
.kr-cm-keg-thumb.has-photo:hover { border-color: #E76F51; }
.kr-cm-keg-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.kr-cm-keg-thumb-ph { font-size: 22px; color: #C7B9A7; }
.kr-cm-keg-info { min-width: 0; }
.kr-cm-keg-name { font-size: 14px; font-weight: 500; line-height: 1.3; color: #2C1A12; }
.kr-cm-keg-code {
  font-size: 11px; color: #8B7355; font-variant-numeric: tabular-nums;
  font-family: 'JetBrains Mono', 'SF Mono', Menlo, Consolas, monospace;
  margin-top: 2px;
}
.kr-cm-qty {
  width: 100%; box-sizing: border-box; padding: 8px 10px;
  border: 1px solid var(--border-color, #ddd); border-radius: 6px;
  font-size: 16px; font-weight: 600; text-align: center;
  background: var(--input-bg, #fff); color: inherit;
}

.kr-cm-actions { justify-content: flex-end; gap: 8px; flex-wrap: wrap; }

.kr-cm-photo-overlay {
  position: fixed; inset: 0; z-index: 1100;
  background: rgba(20,10,5,.72); backdrop-filter: blur(2px);
  display: flex; align-items: center; justify-content: center; padding: 20px;
}
.kr-cm-photo-modal {
  background: #fff; border-radius: 14px; overflow: hidden;
  max-width: 100%; max-height: 100%;
  display: flex; flex-direction: column;
}
.kr-cm-photo-head {
  display: flex; align-items: center; justify-content: space-between;
  gap: 16px; padding: 12px 14px; background: #2C1A12; color: #fff;
  font-size: 14px;
}
.kr-cm-photo-close {
  background: none; border: none; color: #fff;
  font-size: 28px; line-height: 1; cursor: pointer; padding: 0 6px;
  font-family: inherit;
}
.kr-cm-photo-modal img {
  display: block; max-width: 80vw; max-height: 78vh; object-fit: contain;
  background: #FAF6EF;
}
</style>
