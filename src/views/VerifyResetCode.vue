<template>
  <div class="ro-login-page">
    <div class="ro-login-content">
      <div class="ro-login-card">
        <div class="ro-card-header">
          <div class="ro-card-icon">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#E76F51" stroke-width="2" stroke-linecap="round">
              <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
              <polyline points="10 17 15 12 10 7"/>
              <line x1="15" y1="12" x2="3" y2="12"/>
            </svg>
          </div>
          <div>
            <h1>Подтверждение кода</h1>
            <p>Введите код из Telegram для ресторана <b>{{ restaurantNumber }}</b></p>
          </div>
        </div>

        <form @submit.prevent="handleVerify">
          <div class="ro-field">
            <label>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              Код из Telegram
            </label>
            <div class="ro-input-wrap">
              <input
                v-model="code"
                type="text"
                inputmode="numeric"
                placeholder="000000"
                maxlength="6"
                required
                autofocus
                :disabled="loading"
              />
            </div>
          </div>

          <div v-if="error" class="ro-error">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            {{ error }}
          </div>

          <button type="submit" class="ro-submit-btn" :disabled="loading || code.length !== 6">
            <span v-if="loading" class="ro-spinner"></span>
            <template v-else>
              Подтвердить
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
            </template>
          </button>
        </form>

        <div class="ro-back-link">
          <router-link :to="{ name: 'ForgotPassword' }">← Запросить новый код</router-link>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { db } from '@/lib/apiClient.js';

const router = useRouter();
const route = useRoute();

const restaurantNumber = computed(() => route.query.restaurant || '');
const code = ref('');
const loading = ref(false);
const error = ref('');

async function handleVerify() {
  error.value = '';
  loading.value = true;

  try {
    const { data, error: rpcError } = await db.rpc('verify_reset_code', {
      restaurant_number: restaurantNumber.value,
      code: code.value,
    });

    if (rpcError) {
      error.value = rpcError;
      return;
    }

    if (data?.error) {
      error.value = data.error;
      return;
    }

    if (!data?.reset_token) {
      error.value = 'Ошибка сервера: не получен токен';
      return;
    }

    router.push({
      name: 'ResetPassword',
      query: { token: data.reset_token },
    });
  } catch (e) {
    error.value = e.message || 'Ошибка соединения с сервером';
  } finally {
    loading.value = false;
  }
}
</script>

<style scoped>
.ro-login-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #502314 0%, #7a3a1e 40%, #E76F51 100%);
  padding: 20px;
  font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
}

.ro-login-content {
  width: 100%;
  max-width: 420px;
}

.ro-login-card {
  background: white;
  border-radius: 20px;
  padding: 32px;
  width: 100%;
  box-shadow: 0 20px 60px rgba(80, 35, 20, 0.3), 0 4px 16px rgba(0, 0, 0, 0.1);
  animation: cardAppear 0.4s ease-out;
}

@keyframes cardAppear {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}

.ro-card-header {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 28px;
  padding-bottom: 20px;
  border-bottom: 1px solid #f0ebe4;
}

.ro-card-icon {
  width: 48px;
  height: 48px;
  border-radius: 12px;
  background: #fff5f2;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.ro-card-header h1 {
  margin: 0;
  font-size: 20px;
  font-weight: 700;
  color: #502314;
}

.ro-card-header p {
  margin: 2px 0 0;
  color: #8b7355;
  font-size: 13px;
}

.ro-field {
  margin-bottom: 18px;
}

.ro-field label {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 600;
  color: #502314;
  margin-bottom: 8px;
}

.ro-field label svg { color: #8b7355; }

.ro-input-wrap {
  position: relative;
}

.ro-input-wrap input {
  width: 100%;
  padding: 14px 16px;
  border: 2px solid #e8e0d6;
  border-radius: 12px;
  font-size: 16px;
  font-family: inherit;
  transition: all 0.2s;
  box-sizing: border-box;
  background: #faf8f5;
  text-align: center;
  letter-spacing: 8px;
  font-weight: 700;
}

.ro-input-wrap input:focus {
  outline: none;
  border-color: #E76F51;
  background: white;
  box-shadow: 0 0 0 4px rgba(231, 111, 81, 0.08);
}

.ro-input-wrap input::placeholder { color: #c4b8a8; }

.ro-error {
  display: flex;
  align-items: center;
  gap: 8px;
  background: #fef2f2;
  color: #dc2626;
  padding: 12px 14px;
  border-radius: 10px;
  font-size: 13px;
  margin-bottom: 18px;
  border: 1px solid #fecaca;
  animation: shake 0.4s ease;
}

@keyframes shake {
  0%, 100% { transform: translateX(0); }
  20%, 60% { transform: translateX(-6px); }
  40%, 80% { transform: translateX(6px); }
}

.ro-submit-btn {
  width: 100%;
  padding: 15px;
  background: linear-gradient(135deg, #E76F51 0%, #e83a1a 100%);
  color: white;
  border: none;
  border-radius: 12px;
  font-size: 16px;
  font-weight: 700;
  font-family: inherit;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  transition: all 0.2s;
  box-shadow: 0 4px 12px rgba(231, 111, 81, 0.3);
}

.ro-submit-btn:hover:not(:disabled) {
  background: linear-gradient(135deg, #b81e00 0%, #E76F51 100%);
  box-shadow: 0 6px 20px rgba(231, 111, 81, 0.4);
  transform: translateY(-1px);
}

.ro-submit-btn:active:not(:disabled) {
  transform: translateY(0);
  box-shadow: 0 2px 8px rgba(231, 111, 81, 0.3);
}

.ro-submit-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
  box-shadow: none;
}

.ro-spinner {
  width: 20px;
  height: 20px;
  border: 3px solid rgba(255,255,255,0.3);
  border-top-color: white;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

.ro-back-link {
  margin-top: 20px;
  text-align: center;
}

.ro-back-link a {
  color: #8b7355;
  font-size: 14px;
  text-decoration: none;
}

.ro-back-link a:hover {
  color: #502314;
  text-decoration: underline;
}
</style>
