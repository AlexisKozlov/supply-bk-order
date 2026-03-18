<template>
  <div class="sf-page">
    <!-- Header -->
    <div class="sf-brand">
      <svg class="sf-logo" width="36" height="36" viewBox="5 5 38 38" xmlns="http://www.w3.org/2000/svg" fill="none">
        <circle cx="16" cy="16" r="10" fill="#D62300"/>
        <circle cx="32" cy="16" r="10" fill="#F5A623"/>
        <circle cx="16" cy="32" r="10" fill="#FF8733"/>
        <circle cx="32" cy="32" r="10" fill="#FFD54F"/>
        <circle cx="24" cy="24" r="8.5" fill="#502314"/>
        <text x="24" y="29" text-anchor="middle" fill="white" font-size="14" font-weight="900" font-family="Arial, sans-serif">S</text>
      </svg>
      <div class="sf-brand-text">Портал закупок</div>
    </div>

    <!-- Main form -->
    <div class="sf-card" v-if="!expired && !submitted">
      <div class="sf-header">
        <div class="sf-badge veg-badge">Заказ овощей</div>
        <h1>{{ info?.session_name || 'Загрузка...' }}</h1>
      </div>

      <div v-if="loading" class="sf-loading">
        <div class="sf-spinner"></div>
        <span>Загрузка...</span>
      </div>

      <template v-else-if="info">
        <!-- Step 1: Restaurant select -->
        <div class="sf-field">
          <label>
            <span class="sf-field-icon">1</span>
            Выберите ресторан
          </label>
          <select v-model="selectedRestaurant" class="sf-select" :class="{ filled: selectedRestaurant }" @change="onRestaurantChange">
            <option value="">Нажмите для выбора</option>
            <option v-for="r in restaurants" :key="r.number" :value="r.number">
              {{ r.number }} — {{ r.city ? r.city + ', ' : '' }}{{ r.address || '' }}
            </option>
          </select>
        </div>

        <!-- Step 2: Delivery schedule -->
        <template v-if="selectedRestaurant">
          <div v-if="scheduleLoading" class="sf-loading" style="padding: 16px 0;">
            <div class="sf-spinner"></div>
          </div>

          <div v-else-if="deliveries.length === 0" class="sf-no-schedule">
            Для этого ресторана не настроен график доставки овощей. Обратитесь к отделу закупок.
          </div>

          <template v-else>
            <!-- Delivery day tabs -->
            <div class="sf-field">
              <label>
                <span class="sf-field-icon">2</span>
                День доставки
              </label>
              <div class="veg-day-tabs">
                <button
                  v-for="(del, dIdx) in deliveries" :key="del.date"
                  class="veg-day-tab"
                  :class="{ active: activeDay === dIdx, expired: del.expired }"
                  @click="activeDay = dIdx"
                >
                  {{ formatDayShort(del.date) }}
                  <span v-if="del.expired" class="veg-tab-badge expired">!</span>
                  <span v-else-if="dayHasData(del.date)" class="veg-tab-badge filled">✓</span>
                </button>
              </div>
            </div>

            <!-- Active delivery day -->
            <div v-for="(del, dIdx) in deliveries" :key="del.date" v-show="activeDay === dIdx" class="veg-day-block" :class="{ 'veg-day-expired': del.expired }">
              <div class="veg-day-header">
                <div class="veg-day-title">{{ formatDeliveryDate(del.date) }}</div>
                <div v-if="del.expired" class="veg-deadline-badge expired">Дедлайн прошёл</div>
                <div v-else-if="del.deadline" class="veg-deadline-badge">до {{ formatDeadline(del.deadline) }}</div>
              </div>

              <!-- Expired: show previous order info -->
              <div v-if="del.expired && hasPrevData(del.date)" class="sf-prev-order-block">
                <div class="sf-prev-order-title">Заказ будет выполнен по предыдущей заявке:</div>
                <div v-for="prod in info.products" :key="'prev-' + prod.id + '-' + del.date" class="sf-prev-order-item">
                  <span class="sf-prev-order-name">{{ prod.product_name }}</span>
                  <strong v-if="prevInfo(del.date, prod)" class="sf-prev-order-qty">{{ prevInfo(del.date, prod).qty }} {{ unitShort(prod.unit) }}</strong>
                  <span v-else class="sf-prev-order-qty sf-prev-order-none">—</span>
                </div>
              </div>

              <template v-if="!del.expired">
              <div v-for="prod in info.products" :key="prod.id + '-' + del.date" class="sf-product" :class="{ 'sf-product-error': multError(del.date, prod) }">
                <div class="sf-product-top">
                  <div class="sf-product-name">{{ prod.product_name }}</div>
                  <div class="sf-product-unit">{{ unitLabel(prod.unit) }}</div>
                </div>
                <div class="sf-product-hints">
                  <span v-if="prod.multiplicity" class="sf-product-hint">кратно {{ prod.multiplicity }} {{ unitShort(prod.unit) }}</span>
                  <span v-if="prevInfo(del.date, prod)" class="sf-prev-hint">пред. заявка {{ fmtShort(prevInfo(del.date, prod).date) }}: {{ prevInfo(del.date, prod).qty }} {{ unitShort(prod.unit) }}</span>
                </div>
                <div class="sf-product-input-wrap">
                  <input
                    v-model="orderValues[del.date + '_' + prod.id]"
                    type="text"
                    inputmode="decimal"
                    placeholder="0"
                    class="sf-input"
                    :class="{ filled: orderValues[del.date + '_' + prod.id], error: multError(del.date, prod) }"
                    @focus="$event.target.select()"
                  />
                  <span class="sf-input-unit">{{ unitShort(prod.unit) }}</span>
                </div>
                <div v-if="multError(del.date, prod)" class="sf-mult-error">
                  Должно быть кратно {{ prod.multiplicity }}
                </div>
              </div>
              </template>
            </div>

            <div v-if="deliveries.length > 1 && activeDay === 0" class="veg-next-day-hint" @click="activeDay = 1">
              Также можно заполнить на {{ formatDayShort(deliveries[1]?.date) }} →
            </div>

            <!-- Submit -->
            <div v-if="hasMultErrors" class="sf-error">Исправьте количество — должно быть кратно указанному значению</div>
            <button
              class="sf-submit"
              :class="{ ready: canSubmit && !hasMultErrors }"
              :disabled="!canSubmit || submitting || hasMultErrors"
              @click="submit"
            >
              <template v-if="submitting">
                <span class="sf-btn-spinner"></span> Отправка...
              </template>
              <template v-else>Отправить заявку</template>
            </button>
          </template>
        </template>

        <p v-if="error" class="sf-error">{{ error }}</p>
      </template>
    </div>

    <!-- Success -->
    <div class="sf-card" v-else-if="submitted">
      <div class="sf-success" v-if="!editing">
        <div class="sf-success-icon">
          <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
            <circle cx="24" cy="24" r="24" fill="#E8F5E9"/>
            <path d="M14 24l7 7 13-13" stroke="#2E7D32" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <h2>Заявка отправлена!</h2>
        <div class="sf-success-rest">Ресторан {{ selectedRestaurant }}</div>
        <div class="sf-success-details">
          <template v-for="del in deliveries.filter(d => !d.expired)" :key="del.date">
            <div class="veg-success-day">
              {{ formatDeliveryDate(del.date) }}
              <span v-if="del.deadline" class="veg-success-deadline">до {{ formatDeadline(del.deadline) }}</span>
            </div>
            <template v-if="dayHasData(del.date)">
              <div v-for="prod in info.products" :key="prod.id + '-' + del.date" class="sf-success-item">
                <span>{{ prod.product_name }}</span>
                <strong>{{ orderValues[del.date + '_' + prod.id] || 0 }} {{ unitShort(prod.unit) }}</strong>
              </div>
            </template>
            <div v-else class="sf-success-empty">Не заказано</div>
          </template>
        </div>
        <button v-if="canEdit" class="sf-edit-btn" @click="editing = true">
          Изменить заявку
        </button>
        <p v-if="canEdit" class="sf-hint">Можно редактировать до дедлайна</p>
        <p v-else class="sf-hint">Дедлайн прошёл, редактирование недоступно</p>
        <p class="sf-hint" style="margin-top:4px;">Можно закрыть страницу</p>
      </div>

      <!-- Edit mode -->
      <div v-else>
        <div class="sf-header">
          <div class="sf-badge" style="background: #FF8733;">Редактирование</div>
          <h1>Изменить заявку</h1>
          <p class="sf-entity">Ресторан {{ selectedRestaurant }}</p>
        </div>

        <div v-for="del in deliveries.filter(d => !d.expired)" :key="del.date" class="veg-day-block">
          <div class="veg-day-header">
            <div class="veg-day-title">{{ formatDeliveryDate(del.date) }}</div>
            <div v-if="del.deadline" class="veg-deadline-badge">до {{ formatDeadline(del.deadline) }}</div>
          </div>
          <div v-for="prod in info.products" :key="prod.id + '-' + del.date" class="sf-product" :class="{ 'sf-product-error': multError(del.date, prod) }">
            <div class="sf-product-top">
              <div class="sf-product-name">{{ prod.product_name }}</div>
              <div class="sf-product-unit">{{ unitLabel(prod.unit) }}</div>
            </div>
            <div class="sf-product-hints">
              <span v-if="prod.multiplicity" class="sf-product-hint">кратно {{ prod.multiplicity }} {{ unitShort(prod.unit) }}</span>
              <span v-if="prevInfo(del.date, prod)" class="sf-prev-hint">пред. заявка {{ fmtShort(prevInfo(del.date, prod).date) }}: {{ prevInfo(del.date, prod).qty }} {{ unitShort(prod.unit) }}</span>
            </div>
            <div class="sf-product-input-wrap">
              <input
                v-model="orderValues[del.date + '_' + prod.id]"
                type="text" inputmode="decimal"
                class="sf-input"
                :class="{ error: multError(del.date, prod) }"
                @focus="$event.target.select()"
              />
              <span class="sf-input-unit">{{ unitShort(prod.unit) }}</span>
            </div>
            <div v-if="multError(del.date, prod)" class="sf-mult-error">
              Должно быть кратно {{ prod.multiplicity }}
            </div>
          </div>
        </div>

        <div v-if="hasMultErrors" class="sf-error">Исправьте количество — должно быть кратно указанному значению</div>
        <button class="sf-submit ready" :disabled="submitting || hasMultErrors" @click="submitEdit">
          <template v-if="submitting">
            <span class="sf-btn-spinner"></span> Сохранение...
          </template>
          <template v-else>Сохранить изменения</template>
        </button>
        <button class="sf-cancel-btn" @click="editing = false">Отмена</button>
        <p v-if="error" class="sf-error">{{ error }}</p>
      </div>
    </div>

    <!-- Expired -->
    <div class="sf-card" v-else>
      <div class="sf-expired">
        <div class="sf-expired-icon">
          <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
            <circle cx="24" cy="24" r="24" fill="#FFEBEE"/>
            <path d="M16 16l16 16M32 16l-16 16" stroke="#D62700" stroke-width="3" stroke-linecap="round"/>
          </svg>
        </div>
        <h2>Ссылка недействительна</h2>
        <p>Срок действия ссылки истёк или сессия была закрыта. Обратитесь к отделу закупок за новой ссылкой.</p>
      </div>
    </div>

    <!-- Footer -->
    <div class="sf-footer">Burger King Supply Portal</div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { db } from '@/lib/apiClient.js';

const route = useRoute();
const token = route.params.token;

const DAY_NAMES = { 1: 'Понедельник', 2: 'Вторник', 3: 'Среда', 4: 'Четверг', 5: 'Пятница', 6: 'Суббота', 7: 'Воскресенье' };

function unitShort(u) { return u === 'pcs' ? 'шт.' : 'кг'; }
function unitLabel(u) { return u === 'pcs' ? 'штуки' : 'килограммы'; }

function formatDeliveryDate(dateStr) {
  const d = new Date(dateStr + 'T00:00:00');
  const dow = d.getDay() || 7; // 1=пн ... 7=вс
  const dayName = DAY_NAMES[dow] || '';
  return `${dayName}, ${d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' })}`;
}

function formatDeadline(str) {
  if (!str) return '';
  const d = new Date(str.replace(' ', 'T'));
  const dow = d.getDay() || 7;
  const dayName = DAY_NAMES[dow] || '';
  const date = d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' });
  const time = d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  return `${dayName}, ${date}, ${time}`;
}

function formatDayShort(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr + 'T00:00:00');
  const dow = d.getDay() || 7;
  const shortNames = { 1: 'Пн', 2: 'Вт', 3: 'Ср', 4: 'Чт', 5: 'Пт', 6: 'Сб', 7: 'Вс' };
  return `${shortNames[dow]}, ${d.getDate()} ${d.toLocaleDateString('ru-RU', { month: 'short' })}`;
}

const loading = ref(true);
const expired = ref(false);
const submitted = ref(false);
const submitting = ref(false);
const error = ref('');
const info = ref(null);
const restaurants = ref([]);
const selectedRestaurant = ref('');
const orderValues = reactive({});
const allExistingOrders = ref([]);  // все заказы ресторана в текущей сессии
const prevSessionOrders = ref([]);  // заказы из предыдущей сессии
const scheduleLoading = ref(false);
const deliveries = ref([]);
const activeDay = ref(0);

const canSubmit = computed(() => {
  if (!selectedRestaurant.value || deliveries.value.length === 0) return false;
  if (!deliveries.value.some(d => !d.expired)) return false;
  // Хотя бы один день должен быть заполнен
  return deliveries.value.some(d => !d.expired && dayHasData(d.date));
});

// Edit until deadline (no 5-min timer)
const editing = ref(false);

const canEdit = computed(() => {
  return deliveries.value.some(d => !d.expired);
});

// Multiplicity validation
function multError(date, prod) {
  if (!prod.multiplicity || !prod.multiplicity > 0) return false;
  const key = date + '_' + prod.id;
  const val = parseFloat(String(orderValues[key] || '0').replace(',', '.')) || 0;
  if (val === 0) return false;
  const remainder = Math.abs(val % prod.multiplicity);
  return remainder > 0.001 && Math.abs(remainder - prod.multiplicity) > 0.001;
}

const hasMultErrors = computed(() => {
  if (!info.value) return false;
  for (const del of deliveries.value) {
    if (del.expired) continue;
    for (const prod of info.value.products) {
      if (multError(del.date, prod)) return true;
    }
  }
  return false;
});

function orderQty(order) {
  const adminQ = order.admin_qty !== null && order.admin_qty !== undefined ? parseFloat(order.admin_qty) : NaN;
  return !isNaN(adminQ) ? adminQ : parseFloat(order.quantity);
}

function prevInfo(date, prod) {
  // Ищем предыдущий день доставки в текущей сессии
  const allDates = [...new Set(allExistingOrders.value.map(o => o.delivery_date))].sort();
  const prevDates = allDates.filter(d => d < date);
  if (prevDates.length > 0) {
    const prevDate = prevDates[prevDates.length - 1];
    const order = allExistingOrders.value.find(o => o.delivery_date === prevDate && o.product_id === prod.id);
    if (order) {
      const q = orderQty(order);
      if (q > 0) return { date: prevDate, qty: q };
    }
  }
  // Если нет предыдущего дня в текущей сессии — берём из предыдущей сессии (последний день, по названию товара)
  const prevOrders = prevSessionOrders.value.filter(o => o.product_name === prod.product_name);
  if (prevOrders.length === 0) return null;
  const sorted = prevOrders.sort((a, b) => b.delivery_date.localeCompare(a.delivery_date));
  const q = orderQty(sorted[0]);
  return q > 0 ? { date: sorted[0].delivery_date, qty: q } : null;
}

function hasPrevData(date) {
  if (!info.value) return false;
  return info.value.products.some(p => prevInfo(date, p));
}

function fmtShort(dateStr) {
  const d = new Date(dateStr + 'T00:00:00');
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const yy = String(d.getFullYear()).slice(2);
  return `${dd}.${mm}.${yy}`;
}

function dayHasData(date) {
  if (!info.value) return false;
  return info.value.products.some(p => {
    const v = parseFloat(String(orderValues[date + '_' + p.id] || '0').replace(',', '.')) || 0;
    return v > 0;
  });
}

onMounted(async () => {
  try {
    const { data } = await db.rpc('veg_validate_token', { token_value: token });
    if (!data || data.error || data.expired) { expired.value = true; return; }
    info.value = data;
    // Init empty values
    for (const p of data.products) {
      // Will be filled per delivery day later
    }
    const { data: rd } = await db.rpc('veg_get_restaurants', {});
    restaurants.value = rd || [];
  } catch { expired.value = true; } finally { loading.value = false; }
});

async function onRestaurantChange() {
  deliveries.value = [];
  activeDay.value = 0;
  if (!selectedRestaurant.value) return;
  scheduleLoading.value = true;
  try {
    const [schedRes, ordRes, prevRes] = await Promise.all([
      db.rpc('veg_get_schedule', { restaurant_number: selectedRestaurant.value }),
      db.rpc('veg_get_existing_orders', { token_value: token, restaurant_number: selectedRestaurant.value }),
      db.rpc('veg_get_previous_orders', { token_value: token, restaurant_number: selectedRestaurant.value }),
    ]);
    deliveries.value = schedRes.data?.deliveries || [];
    // Init order values
    for (const del of deliveries.value) {
      for (const prod of (info.value?.products || [])) {
        orderValues[del.date + '_' + prod.id] = '';
      }
    }
    // Fill existing orders (admin_qty приоритетнее quantity)
    const existing = ordRes.data?.orders || [];
    allExistingOrders.value = existing;
    prevSessionOrders.value = prevRes.data?.orders || [];
    let hasScheduledOrders = false;
    if (existing.length > 0) {
      for (const o of existing) {
        const key = o.delivery_date + '_' + o.product_id;
        if (key in orderValues) {
          const adminQ = o.admin_qty !== null && o.admin_qty !== undefined ? parseFloat(o.admin_qty) : NaN;
          const q = !isNaN(adminQ) ? adminQ : parseFloat(o.quantity);
          orderValues[key] = q > 0 ? String(q) : '';
          hasScheduledOrders = true;  // есть заказ хотя бы на один день из расписания
        }
      }
      // Показываем "Заявка отправлена" только если ресторан реально отправлял
      // (не считаем записи админа за закрытые дни, которых нет в расписании)
      if (hasScheduledOrders) submitted.value = true;
    }
  } catch { error.value = 'Не удалось загрузить график'; }
  finally { scheduleLoading.value = false; }
}

function buildItems() {
  const items = [];
  for (const del of deliveries.value) {
    if (del.expired) continue;
    for (const prod of (info.value?.products || [])) {
      const key = del.date + '_' + prod.id;
      const qty = parseFloat(String(orderValues[key] || '0').replace(',', '.')) || 0;
      items.push({ product_id: prod.id, delivery_date: del.date, quantity: qty });
    }
  }
  return items;
}

async function submit() {
  error.value = '';
  if (!selectedRestaurant.value || !info.value) return;
  submitting.value = true;
  try {
    const { data } = await db.rpc('veg_submit_order', {
      token_value: token,
      restaurant_number: selectedRestaurant.value,
      items: buildItems(),
    });
    if (data?.error) { error.value = data.error === 'session_closed' ? 'Сессия закрыта' : data.error; }
    else { submitted.value = true; }
  } catch { error.value = 'Ошибка при отправке'; } finally { submitting.value = false; }
}

async function submitEdit() {
  error.value = '';
  if (!info.value) return;
  submitting.value = true;
  try {
    const { data } = await db.rpc('veg_submit_order', {
      token_value: token,
      restaurant_number: selectedRestaurant.value,
      items: buildItems(),
    });
    if (data?.error) { error.value = data.error; }
    else { editing.value = false; }
  } catch { error.value = 'Ошибка при сохранении'; } finally { submitting.value = false; }
}
</script>

<style scoped>
.sf-page {
  min-height: 100vh; min-height: 100dvh;
  display: flex; flex-direction: column; align-items: center;
  background: #502314;
  padding: 0 16px 32px;
  font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
}
.sf-brand {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 0 10px;
}
.sf-logo { flex-shrink: 0; }
.sf-brand-text {
  font-size: 14px; font-weight: 600; color: rgba(255,255,255,0.7);
  letter-spacing: 0.5px;
}
.sf-card {
  background: #fff; border-radius: 16px;
  box-shadow: 0 8px 40px rgba(0,0,0,0.25);
  padding: 20px 18px;
  width: 100%; max-width: 440px;
  animation: sf-slideUp 0.3s ease-out;
}
@keyframes sf-slideUp {
  from { opacity: 0; transform: translateY(16px); }
  to { opacity: 1; transform: translateY(0); }
}
.sf-header { text-align: center; margin-bottom: 14px; }
.sf-badge {
  display: inline-block;
  background: linear-gradient(135deg, #D62700, #FF8733);
  color: #fff; font-size: 10px; font-weight: 700;
  padding: 3px 10px; border-radius: 20px;
  text-transform: uppercase; letter-spacing: 0.5px;
  margin-bottom: 8px;
}
.veg-badge { background: linear-gradient(135deg, #388E3C, #66BB6A); }
.sf-header h1 {
  font-size: 18px; font-weight: 800; color: #502314;
  margin: 0 0 2px; line-height: 1.2;
}
.sf-entity { font-size: 12px; color: #8C7B6E; margin: 0; }
.sf-loading {
  text-align: center; padding: 40px 0; color: #8C7B6E;
  display: flex; flex-direction: column; align-items: center; gap: 12px;
}
.sf-spinner {
  width: 32px; height: 32px; border: 3px solid #EDE7DF;
  border-top-color: #388E3C; border-radius: 50%;
  animation: sf-spin 0.7s linear infinite;
}
@keyframes sf-spin { to { transform: rotate(360deg); } }
.sf-field { margin-bottom: 10px; }
.sf-field label {
  display: flex; align-items: center; gap: 6px;
  font-size: 13px; font-weight: 700; color: #502314;
  margin-bottom: 6px;
}
.sf-field-icon {
  width: 18px; height: 18px; border-radius: 50%;
  background: #502314; color: #fff;
  font-size: 10px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.sf-select {
  width: 100%; padding: 9px 12px; border: 1.5px solid #EDE7DF;
  border-radius: 10px; font-size: 14px; font-family: inherit;
  background: #fff; transition: all 0.15s; box-sizing: border-box;
  color: #8C7B6E; appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg width='12' height='8' viewBox='0 0 12 8' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1.5L6 6.5L11 1.5' stroke='%238C7B6E' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 12px center;
  padding-right: 32px;
}
.sf-select:focus { outline: none; border-color: #66BB6A; box-shadow: 0 0 0 2px rgba(102,187,106,0.15); }
.sf-select.filled { color: #502314; border-color: #A5D6A7; }

/* Day tabs */
.veg-day-tabs {
  display: flex; gap: 6px;
}
.veg-day-tab {
  flex: 1; padding: 8px 10px; border: 1.5px solid #EDE7DF; border-radius: 10px;
  background: #fff; color: #8C7B6E; font-size: 13px; font-weight: 600;
  font-family: inherit; cursor: pointer; transition: all 0.15s;
  display: flex; align-items: center; justify-content: center; gap: 4px;
}
.veg-day-tab.active { border-color: #66BB6A; background: #E8F5E9; color: #2E7D32; }
.veg-day-tab.expired { opacity: 0.5; }
.veg-tab-badge {
  width: 16px; height: 16px; border-radius: 50%; font-size: 10px;
  display: inline-flex; align-items: center; justify-content: center;
}
.veg-tab-badge.filled { background: #E8F5E9; color: #2E7D32; }
.veg-tab-badge.expired { background: #FFEBEE; color: #D62700; }
.veg-next-day-hint {
  text-align: center; font-size: 12px; color: #66BB6A; cursor: pointer;
  padding: 6px 0; font-weight: 600;
}
.veg-next-day-hint:hover { color: #2E7D32; }

/* Delivery day blocks */
.veg-day-block {
  margin-bottom: 12px; padding: 12px; border-radius: 12px;
  background: #FAFAFA; border: 1.5px solid #E8F5E9;
}
.veg-day-block.veg-day-expired {
  opacity: 0.5; border-color: #eee; background: #f5f5f5;
}
.veg-day-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 8px; gap: 8px;
}
.veg-day-title {
  display: flex; align-items: center; gap: 6px;
  font-size: 14px; font-weight: 700; color: #2E7D32;
}
.veg-deadline-badge {
  font-size: 10px; font-weight: 600; color: #FF8F00;
  background: #FFF8E1; padding: 2px 8px; border-radius: 10px;
  white-space: nowrap;
}
.veg-deadline-badge.expired {
  color: #D62700; background: #FFEBEE;
}
.sf-no-schedule {
  text-align: center; font-size: 13px; color: #FF8733;
  padding: 16px; background: #FFF3E0; border-radius: 10px;
  margin: 10px 0; line-height: 1.4;
}

/* Products */
.sf-product {
  background: #fff; border-radius: 10px;
  padding: 8px 10px; margin-bottom: 4px;
}
.sf-product-top {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 4px;
}
.sf-product-name { font-size: 13px; font-weight: 700; color: #502314; }
.sf-product-unit {
  font-size: 10px; font-weight: 600; color: #388E3C;
  background: #E8F5E9; padding: 1px 6px; border-radius: 5px;
}
.sf-product-hints {
  display: flex; align-items: center; gap: 8px; margin-bottom: 2px;
  min-height: 0;
}
.sf-product-hints:empty { display: none; }
.sf-product-hint {
  font-size: 11px; color: #888; font-style: italic;
}
.sf-prev-hint {
  font-size: 11px; color: #7E57C2; font-weight: 600;
}
.sf-prev-order-block {
  background: #FFF8E1; border-radius: 10px; padding: 12px 14px; margin-top: 4px;
}
.sf-prev-order-title {
  font-size: 13px; font-weight: 700; color: #F57F17; margin-bottom: 8px;
}
.sf-prev-order-item {
  display: flex; justify-content: space-between; align-items: center;
  padding: 4px 0; font-size: 14px; color: #333;
}
.sf-prev-order-name { font-weight: 500; }
.sf-prev-order-qty { color: #502314; }
.sf-prev-order-none { color: #bbb; }
.sf-product-error { background: #FFF5F5; }
.sf-mult-error {
  font-size: 11px; color: #D62700; margin-top: 2px; font-weight: 600;
}
.sf-input.error { border-color: #D62700 !important; background: #FFF5F5; }
.sf-product-input-wrap { position: relative; }
.sf-input {
  width: 100%; padding: 8px 44px 8px 12px; border: 1.5px solid #EDE7DF;
  border-radius: 8px; font-size: 16px; font-weight: 700; font-family: inherit;
  background: #fff; transition: all 0.15s; box-sizing: border-box;
  color: #502314;
}
.sf-input:focus { outline: none; border-color: #66BB6A; box-shadow: 0 0 0 2px rgba(102,187,106,0.15); }
.sf-input.filled { border-color: #A5D6A7; }
.sf-input:disabled { background: #f5f5f5; color: #bbb; cursor: not-allowed; }
.sf-input::placeholder { color: #ccc; font-weight: 400; }
.sf-input-unit {
  position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
  font-size: 12px; font-weight: 600; color: #8C7B6E; pointer-events: none;
}

/* Submit */
.sf-submit {
  width: 100%; padding: 12px; border: none; border-radius: 10px;
  background: #ccc; color: #fff;
  font-size: 15px; font-weight: 800; font-family: inherit;
  cursor: pointer; transition: all 0.2s;
  margin-top: 10px;
  display: flex; align-items: center; justify-content: center; gap: 8px;
}
.sf-submit.ready {
  background: linear-gradient(135deg, #2E7D32, #66BB6A);
  box-shadow: 0 4px 16px rgba(46,125,50,0.3);
}
.sf-submit.ready:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(46,125,50,0.4); }
.sf-submit.ready:active { transform: translateY(0); }
.sf-submit:disabled { cursor: not-allowed; }
.sf-btn-spinner {
  width: 18px; height: 18px; border: 2.5px solid rgba(255,255,255,0.3);
  border-top-color: #fff; border-radius: 50%;
  animation: sf-spin 0.7s linear infinite;
}
.sf-error {
  color: #D62700; font-size: 13px; text-align: center;
  margin-top: 12px; padding: 8px; background: #FFEBEE; border-radius: 8px;
}

/* Success */
.sf-success { text-align: center; padding: 12px 0; }
.sf-success-icon { margin-bottom: 16px; }
.sf-success h2 { font-size: 22px; font-weight: 800; color: #2E7D32; margin: 0 0 4px; }
.sf-success-rest {
  font-size: 15px; color: #502314; font-weight: 600;
  margin-bottom: 16px;
}
.sf-success-details {
  background: #F9F6F2; border-radius: 10px; padding: 12px 16px;
  margin-bottom: 16px; text-align: left;
}
.veg-success-day {
  font-size: 12px; font-weight: 700; color: #2E7D32;
  padding: 8px 0 4px; border-bottom: 1.5px solid #C8E6C9;
  margin-top: 4px;
}
.veg-success-day:first-child { margin-top: 0; padding-top: 0; }
.veg-success-deadline { font-size: 10px; font-weight: 500; color: #888; display: block; margin-top: 1px; }
.sf-success-empty { font-size: 13px; color: #aaa; font-style: italic; padding: 4px 0; }
.sf-success-item {
  display: flex; justify-content: space-between; align-items: center;
  padding: 5px 0; font-size: 13px; color: #555;
}
.sf-success-item strong { color: #502314; }
.sf-hint { color: #aaa; font-size: 12px; margin: 0; }
.sf-edit-btn {
  width: 100%; padding: 12px; border: 2px solid #EDE7DF;
  border-radius: 12px; background: #fff; color: #502314;
  font-size: 14px; font-weight: 700; font-family: inherit;
  cursor: pointer; transition: all 0.15s; margin-top: 12px;
}
.sf-edit-btn:hover { border-color: #66BB6A; background: #F1F8E9; }
.sf-cancel-btn {
  width: 100%; padding: 10px; border: none; background: none;
  color: #8C7B6E; font-size: 13px; font-weight: 600; font-family: inherit;
  cursor: pointer; margin-top: 8px;
}
.sf-cancel-btn:hover { color: #502314; }

/* Expired */
.sf-expired { text-align: center; padding: 12px 0; }
.sf-expired-icon { margin-bottom: 16px; }
.sf-expired h2 { font-size: 20px; font-weight: 800; color: #D62700; margin: 0 0 8px; }
.sf-expired p { color: #8C7B6E; font-size: 14px; margin: 0; line-height: 1.5; }

/* Footer */
.sf-footer {
  margin-top: 24px; font-size: 11px; color: rgba(255,255,255,0.3);
  letter-spacing: 1px; text-transform: uppercase;
}

@media (max-width: 480px) {
  .sf-page { padding: 0 10px 20px; }
  .sf-card { padding: 16px 14px; border-radius: 14px; }
  .sf-header h1 { font-size: 16px; }
}
</style>
