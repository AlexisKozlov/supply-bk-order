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
        <div class="sf-badge">Сбор остатков</div>
        <h1>{{ info?.collection_name || 'Загрузка...' }}</h1>
        <p class="sf-entity" v-if="info">{{ info.legal_entity }}</p>
      </div>

      <div v-if="loading" class="sf-loading">
        <div class="sf-spinner"></div>
        <span>Загрузка...</span>
      </div>

      <template v-else-if="info">
        <!-- Restaurant select -->
        <div class="sf-field">
          <label>
            <span class="sf-field-icon">1</span>
            Выберите ресторан
          </label>
          <select v-model="selectedRestaurant" class="sf-select" :class="{ filled: selectedRestaurant }">
            <option value="">Нажмите для выбора</option>
            <option v-for="r in restaurants" :key="r.number" :value="r.number">
              {{ r.number }} — {{ r.address || r.city || '' }}
            </option>
          </select>
        </div>

        <!-- Products -->
        <div class="sf-field">
          <label>
            <span class="sf-field-icon">2</span>
            Укажите остатки
          </label>
        </div>

        <div v-for="(prod, idx) in info.products" :key="prod.id" class="sf-product">
          <div class="sf-product-top">
            <div class="sf-product-name">{{ prod.product_name }}</div>
            <div class="sf-product-unit">{{ prod.unit === 'boxes' ? 'коробки' : 'штуки' }}</div>
          </div>
          <div class="sf-product-input-wrap">
            <input
              v-model="stockValues[prod.id]"
              type="text"
              inputmode="decimal"
              placeholder="0"
              class="sf-input"
              :class="{ filled: stockValues[prod.id] }"
              @focus="$event.target.select()"
            />
            <span class="sf-input-unit">{{ prod.unit === 'boxes' ? 'кор.' : 'шт.' }}</span>
          </div>
        </div>

        <!-- Already submitted warning -->
        <div v-if="alreadySubmitted" class="sf-already">
          Ресторан {{ selectedRestaurant }} уже заполнил остатки по этому сбору.
        </div>

        <!-- Submit -->
        <button
          v-if="!alreadySubmitted"
          class="sf-submit"
          :class="{ ready: selectedRestaurant && !submitting }"
          :disabled="!selectedRestaurant || submitting"
          @click="submit"
        >
          <template v-if="submitting">
            <span class="sf-btn-spinner"></span> Отправка...
          </template>
          <template v-else>Отправить остатки</template>
        </button>

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
        <h2>Отправлено!</h2>
        <div class="sf-success-rest">Ресторан {{ selectedRestaurant }}</div>
        <div class="sf-success-details">
          <div v-for="prod in info.products" :key="prod.id" class="sf-success-item">
            <span>{{ prod.product_name }}</span>
            <strong>{{ stockValues[prod.id] || 0 }} {{ prod.unit === 'boxes' ? 'кор.' : 'шт.' }}</strong>
          </div>
        </div>
        <button v-if="editTimeLeft > 0" class="sf-edit-btn" @click="editing = true">
          Изменить ({{ editTimeFormatted }})
        </button>
        <p class="sf-hint">Можно закрыть страницу</p>
      </div>

      <!-- Edit mode -->
      <div v-else>
        <div class="sf-header">
          <div class="sf-badge" style="background: #FF8733;">Редактирование</div>
          <h1>Изменить остатки</h1>
          <p class="sf-entity">Ресторан {{ selectedRestaurant }}</p>
        </div>
        <div class="sf-edit-timer">Осталось {{ editTimeFormatted }}</div>
        <div v-for="prod in info.products" :key="prod.id" class="sf-product">
          <div class="sf-product-top">
            <div class="sf-product-name">{{ prod.product_name }}</div>
            <div class="sf-product-unit">{{ prod.unit === 'boxes' ? 'коробки' : 'штуки' }}</div>
          </div>
          <div class="sf-product-input-wrap">
            <input
              v-model="stockValues[prod.id]"
              type="text" inputmode="decimal"
              class="sf-input"
              @focus="$event.target.select()"
            />
            <span class="sf-input-unit">{{ prod.unit === 'boxes' ? 'кор.' : 'шт.' }}</span>
          </div>
        </div>
        <button
          class="sf-submit ready"
          :disabled="submitting"
          @click="submitEdit"
        >
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
        <p>Срок действия ссылки истёк или сбор был закрыт. Обратитесь к отделу закупок за новой ссылкой.</p>
      </div>
    </div>

    <!-- Footer -->
    <div class="sf-footer">Burger King Supply Portal</div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue';
import { useRoute } from 'vue-router';
import { db } from '@/lib/apiClient.js';

const route = useRoute();
const token = route.params.token;

const loading = ref(true);
const expired = ref(false);
const submitted = ref(false);
const submitting = ref(false);
const error = ref('');
const info = ref(null);
const restaurants = ref([]);
const selectedRestaurant = ref('');
const stockValues = reactive({});
const submittedRestaurants = ref([]);

const alreadySubmitted = computed(() =>
  selectedRestaurant.value && submittedRestaurants.value.includes(String(selectedRestaurant.value))
);

// Edit timer (5 min)
const editing = ref(false);
const submitTime = ref(0);
const editTimeLeft = ref(0);
let editTimer = null;

function startEditTimer() {
  submitTime.value = Date.now();
  editTimeLeft.value = 300;
  editTimer = setInterval(() => {
    const elapsed = Math.floor((Date.now() - submitTime.value) / 1000);
    editTimeLeft.value = Math.max(0, 300 - elapsed);
    if (editTimeLeft.value <= 0) { clearInterval(editTimer); editing.value = false; }
  }, 1000);
}
onUnmounted(() => { if (editTimer) clearInterval(editTimer); });

const editTimeFormatted = computed(() => {
  const m = Math.floor(editTimeLeft.value / 60);
  const s = editTimeLeft.value % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
});

onMounted(async () => {
  try {
    const { data } = await db.rpc('sc_validate_token', { token_value: token });
    if (!data || data.error || data.expired) { expired.value = true; return; }
    info.value = data;
    submittedRestaurants.value = (data.submitted_restaurants || []).map(String);
    for (const p of data.products) stockValues[p.id] = '';
    const { data: rd } = await db.rpc('sc_get_restaurants', { token_value: token });
    restaurants.value = rd || [];
  } catch { expired.value = true; } finally { loading.value = false; }
});

async function submit() {
  error.value = '';
  if (!selectedRestaurant.value || !info.value) return;
  submitting.value = true;
  try {
    const items = info.value.products.map(p => ({
      product_id: p.id,
      stock: parseFloat(String(stockValues[p.id] || '0').replace(',', '.')) || 0,
    }));
    const { data } = await db.rpc('sc_submit_stock', {
      token_value: token,
      restaurant_num: selectedRestaurant.value,
      items,
    });
    if (data?.error) { error.value = data.error; }
    else { submitted.value = true; startEditTimer(); }
  } catch { error.value = 'Ошибка при отправке'; } finally { submitting.value = false; }
}

async function submitEdit() {
  error.value = '';
  if (!info.value) return;
  submitting.value = true;
  try {
    const items = info.value.products.map(p => ({
      product_id: p.id,
      stock: parseFloat(String(stockValues[p.id] || '0').replace(',', '.')) || 0,
    }));
    const { data } = await db.rpc('sc_submit_stock', {
      token_value: token,
      restaurant_num: selectedRestaurant.value,
      items,
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

/* Brand header */
.sf-brand {
  display: flex; align-items: center; gap: 10px;
  padding: 20px 0 16px;
}
.sf-logo { flex-shrink: 0; }
.sf-brand-text {
  font-size: 14px; font-weight: 600; color: rgba(255,255,255,0.7);
  letter-spacing: 0.5px;
}

/* Card */
.sf-card {
  background: #fff; border-radius: 20px;
  box-shadow: 0 8px 40px rgba(0,0,0,0.25);
  padding: 28px 24px;
  width: 100%; max-width: 440px;
  animation: sf-slideUp 0.3s ease-out;
}
@keyframes sf-slideUp {
  from { opacity: 0; transform: translateY(16px); }
  to { opacity: 1; transform: translateY(0); }
}

/* Header */
.sf-header { text-align: center; margin-bottom: 24px; }
.sf-badge {
  display: inline-block;
  background: linear-gradient(135deg, #D62700, #FF8733);
  color: #fff; font-size: 11px; font-weight: 700;
  padding: 4px 14px; border-radius: 20px;
  text-transform: uppercase; letter-spacing: 0.5px;
  margin-bottom: 12px;
}
.sf-header h1 {
  font-size: 22px; font-weight: 800; color: #502314;
  margin: 0 0 4px; line-height: 1.2;
}
.sf-entity { font-size: 13px; color: #8C7B6E; margin: 0; }

/* Loading */
.sf-loading {
  text-align: center; padding: 40px 0; color: #8C7B6E;
  display: flex; flex-direction: column; align-items: center; gap: 12px;
}
.sf-spinner {
  width: 32px; height: 32px; border: 3px solid #EDE7DF;
  border-top-color: #D62700; border-radius: 50%;
  animation: sf-spin 0.7s linear infinite;
}
@keyframes sf-spin { to { transform: rotate(360deg); } }

/* Fields */
.sf-field { margin-bottom: 16px; }
.sf-field label {
  display: flex; align-items: center; gap: 8px;
  font-size: 14px; font-weight: 700; color: #502314;
  margin-bottom: 8px;
}
.sf-field-icon {
  width: 22px; height: 22px; border-radius: 50%;
  background: #502314; color: #fff;
  font-size: 11px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}

.sf-select {
  width: 100%; padding: 12px 14px; border: 2px solid #EDE7DF;
  border-radius: 12px; font-size: 15px; font-family: inherit;
  background: #fff; transition: all 0.15s; box-sizing: border-box;
  color: #8C7B6E; appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg width='12' height='8' viewBox='0 0 12 8' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1.5L6 6.5L11 1.5' stroke='%238C7B6E' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 14px center;
  padding-right: 36px;
}
.sf-select:focus { outline: none; border-color: #FF8733; box-shadow: 0 0 0 3px rgba(255,135,51,0.15); }
.sf-select.filled { color: #502314; border-color: #A5D6A7; }

/* Products */
.sf-product {
  background: #F9F6F2; border-radius: 12px;
  padding: 14px 16px; margin-bottom: 10px;
  transition: background 0.15s;
}
.sf-product-top {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 8px;
}
.sf-product-name { font-size: 14px; font-weight: 700; color: #502314; }
.sf-product-unit {
  font-size: 11px; font-weight: 600; color: #FF8733;
  background: #FFF3E0; padding: 2px 8px; border-radius: 6px;
}
.sf-product-input-wrap { position: relative; }
.sf-input {
  width: 100%; padding: 12px 50px 12px 14px; border: 2px solid #EDE7DF;
  border-radius: 10px; font-size: 18px; font-weight: 700; font-family: inherit;
  background: #fff; transition: all 0.15s; box-sizing: border-box;
  color: #502314;
}
.sf-input:focus { outline: none; border-color: #FF8733; box-shadow: 0 0 0 3px rgba(255,135,51,0.15); }
.sf-input.filled { border-color: #A5D6A7; }
.sf-input::placeholder { color: #ccc; font-weight: 400; }
.sf-input-unit {
  position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
  font-size: 13px; font-weight: 600; color: #8C7B6E; pointer-events: none;
}

/* Submit */
.sf-submit {
  width: 100%; padding: 15px; border: none; border-radius: 14px;
  background: #ccc; color: #fff;
  font-size: 16px; font-weight: 800; font-family: inherit;
  cursor: pointer; transition: all 0.2s;
  margin-top: 12px;
  display: flex; align-items: center; justify-content: center; gap: 8px;
}
.sf-submit.ready {
  background: linear-gradient(135deg, #D62700, #FF8733);
  box-shadow: 0 4px 16px rgba(214,39,0,0.3);
}
.sf-submit.ready:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(214,39,0,0.4); }
.sf-submit.ready:active { transform: translateY(0); }
.sf-submit:disabled { cursor: not-allowed; }
.sf-btn-spinner {
  width: 18px; height: 18px; border: 2.5px solid rgba(255,255,255,0.3);
  border-top-color: #fff; border-radius: 50%;
  animation: sf-spin 0.7s linear infinite;
}
.sf-already {
  text-align: center; font-size: 14px; font-weight: 600; color: #FF8733;
  padding: 14px; background: #FFF3E0; border-radius: 12px; margin-top: 12px;
  line-height: 1.4;
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
.sf-success-item {
  display: flex; justify-content: space-between; align-items: center;
  padding: 6px 0; font-size: 13px; color: #555;
}
.sf-success-item:not(:last-child) { border-bottom: 1px solid #EDE7DF; }
.sf-success-item strong { color: #502314; }
.sf-hint { color: #aaa; font-size: 12px; margin: 0; }
.sf-edit-btn {
  width: 100%; padding: 12px; border: 2px solid #EDE7DF;
  border-radius: 12px; background: #fff; color: #502314;
  font-size: 14px; font-weight: 700; font-family: inherit;
  cursor: pointer; transition: all 0.15s; margin-top: 12px;
}
.sf-edit-btn:hover { border-color: #FF8733; background: #FFF8F0; }
.sf-edit-timer {
  text-align: center; font-size: 12px; font-weight: 600;
  color: #FF8733; margin-bottom: 16px;
  padding: 6px 0; background: #FFF3E0; border-radius: 8px;
}
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
  .sf-page { padding: 0 12px 24px; }
  .sf-card { padding: 24px 18px; border-radius: 16px; }
  .sf-header h1 { font-size: 19px; }
}
</style>
