<template>
  <div class="deficit-form-page">
    <div class="deficit-form-card" v-if="!expired && !submitted">
      <div class="deficit-form-header">
        <h1>Остатки товара</h1>
        <p class="deficit-form-product" v-if="tokenInfo">{{ tokenInfo.product_name }}</p>
        <p class="deficit-form-entity" v-if="tokenInfo">{{ tokenInfo.legal_entity }}</p>
      </div>

      <div v-if="loading" class="deficit-form-loading">Загрузка...</div>

      <template v-else-if="tokenInfo">
        <div class="deficit-form-field">
          <label>Номер ресторана</label>
          <select v-model="selectedRestaurant" class="deficit-form-select">
            <option value="">Выберите ресторан</option>
            <option v-for="r in restaurants" :key="r.number" :value="r.number">
              {{ r.number }} — {{ r.address || r.city || '' }}
            </option>
          </select>
        </div>

        <div class="deficit-form-field">
          <label>Остаток (шт)</label>
          <input
            v-model="stockValue"
            type="text"
            inputmode="decimal"
            placeholder="0"
            class="deficit-form-input"
          />
        </div>

        <button
          class="deficit-form-submit"
          :disabled="!selectedRestaurant || submitting"
          @click="submit"
        >
          {{ submitting ? 'Отправка...' : 'Отправить' }}
        </button>

        <p v-if="error" class="deficit-form-error">{{ error }}</p>
      </template>
    </div>

    <div class="deficit-form-card" v-else-if="submitted">
      <div class="deficit-form-success">
        <div class="deficit-form-check">&#10003;</div>
        <h2>Отправлено!</h2>
        <p>Ресторан {{ selectedRestaurant }} — остаток {{ stockValue || 0 }} шт</p>
        <p class="deficit-form-hint">Можно закрыть эту страницу</p>
      </div>
    </div>

    <div class="deficit-form-card" v-else>
      <div class="deficit-form-expired">
        <h2>Ссылка недействительна</h2>
        <p>Срок действия ссылки истёк или она уже не активна.</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { db } from '@/lib/apiClient.js';

const route = useRoute();
const token = route.params.token;

const loading = ref(true);
const expired = ref(false);
const submitted = ref(false);
const submitting = ref(false);
const error = ref('');
const tokenInfo = ref(null);
const restaurants = ref([]);
const selectedRestaurant = ref('');
const stockValue = ref('');

onMounted(async () => {
  try {
    const { data } = await db.rpc('deficit_validate_token', { token_value: token });
    if (!data || data.error || data.expired) {
      expired.value = true;
      return;
    }
    tokenInfo.value = data;
    // Загрузить список ресторанов
    const { data: restData } = await db.rpc('deficit_get_restaurants', { token_value: token });
    restaurants.value = restData || [];
  } catch {
    expired.value = true;
  } finally {
    loading.value = false;
  }
});

async function submit() {
  error.value = '';
  if (!selectedRestaurant.value) return;
  submitting.value = true;
  try {
    const { data } = await db.rpc('deficit_submit_stock', {
      token_value: token,
      restaurant_num: selectedRestaurant.value,
      stock_value: parseFloat(String(stockValue.value || '0').replace(',', '.')) || 0,
    });
    if (data?.error) {
      error.value = data.error;
    } else {
      submitted.value = true;
    }
  } catch (e) {
    error.value = 'Ошибка при отправке';
  } finally {
    submitting.value = false;
  }
}
</script>

<style scoped>
.deficit-form-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #FFF8F0 0%, #F5EDE5 100%);
  padding: 20px;
  font-family: 'Sora', system-ui, sans-serif;
}
.deficit-form-card {
  background: #fff;
  border-radius: 16px;
  box-shadow: 0 4px 24px rgba(80,35,20,0.1);
  padding: 32px;
  width: 100%;
  max-width: 420px;
}
.deficit-form-header { text-align: center; margin-bottom: 24px; }
.deficit-form-header h1 { font-size: 20px; color: #502314; margin: 0 0 8px; }
.deficit-form-product { font-size: 16px; font-weight: 600; color: #E87A1E; margin: 0 0 4px; }
.deficit-form-entity { font-size: 13px; color: #888; margin: 0; }
.deficit-form-loading { text-align: center; padding: 40px 0; color: #888; }
.deficit-form-field { margin-bottom: 16px; }
.deficit-form-field label { display: block; font-size: 13px; font-weight: 600; color: #502314; margin-bottom: 6px; }
.deficit-form-select, .deficit-form-input {
  width: 100%; padding: 10px 12px; border: 1.5px solid #E0D6CC;
  border-radius: 8px; font-size: 15px; font-family: inherit;
  background: #fff; transition: border-color 0.15s;
  box-sizing: border-box;
}
.deficit-form-select:focus, .deficit-form-input:focus {
  outline: none; border-color: #E87A1E;
}
.deficit-form-submit {
  width: 100%; padding: 12px; border: none; border-radius: 10px;
  background: linear-gradient(135deg, #D62700, #FF8733);
  color: #fff; font-size: 15px; font-weight: 700; font-family: inherit;
  cursor: pointer; transition: opacity 0.15s; margin-top: 8px;
}
.deficit-form-submit:disabled { opacity: 0.5; cursor: not-allowed; }
.deficit-form-submit:hover:not(:disabled) { opacity: 0.9; }
.deficit-form-error { color: #D62700; font-size: 13px; text-align: center; margin-top: 12px; }
.deficit-form-success { text-align: center; padding: 20px 0; }
.deficit-form-check {
  width: 56px; height: 56px; border-radius: 50%;
  background: #E8F5E9; color: #2E7D32; font-size: 28px;
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 16px;
}
.deficit-form-success h2 { font-size: 20px; color: #2E7D32; margin: 0 0 8px; }
.deficit-form-success p { color: #555; margin: 0 0 4px; font-size: 14px; }
.deficit-form-hint { color: #aaa !important; font-size: 12px !important; margin-top: 16px !important; }
.deficit-form-expired { text-align: center; padding: 20px 0; }
.deficit-form-expired h2 { font-size: 18px; color: #D62700; margin: 0 0 8px; }
.deficit-form-expired p { color: #888; font-size: 14px; margin: 0; }
</style>
