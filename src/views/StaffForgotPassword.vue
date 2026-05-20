<template>
  <div class="staff-login-page">
    <div class="staff-login-content">
      <div class="staff-login-card">
        <div class="staff-card-header">
          <div class="staff-card-icon">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#E76F51" stroke-width="2" stroke-linecap="round">
              <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
              <polyline points="22,6 12,13 2,6"/>
            </svg>
          </div>
          <div>
            <h1>Восстановление пароля</h1>
            <p>Введите email — отправим ссылку для сброса</p>
          </div>
        </div>

        <form @submit.prevent="handleRequest">
          <div class="staff-field">
            <label>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
              Email
            </label>
            <div class="staff-input-wrap">
              <input
                v-model="email"
                type="email"
                inputmode="email"
                autocomplete="email"
                placeholder="your-name@example.com"
                required
                autofocus
                :disabled="loading || success"
              />
              <span class="staff-input-icon">@</span>
            </div>
          </div>

          <div v-if="error" class="staff-error">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            {{ error }}
          </div>

          <div v-if="success" class="staff-success">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            {{ successMessage }}
          </div>

          <button v-if="!success" type="submit" class="staff-submit-btn" :disabled="loading || !email">
            <span v-if="loading" class="staff-spinner"></span>
            <template v-else>
              Отправить ссылку
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
            </template>
          </button>
        </form>

        <div class="staff-back-link">
          <router-link to="/">← Вернуться ко входу</router-link>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { db } from '@/lib/apiClient.js';

const email = ref('');
const loading = ref(false);
const error = ref('');
const success = ref(false);
const successMessage = ref('');

async function handleRequest() {
  error.value = '';
  success.value = false;
  loading.value = true;

  try {
    const { data, error: rpcError } = await db.rpc('request_staff_password_reset', {
      email: email.value.trim(),
    });

    if (rpcError) {
      error.value = rpcError;
      return;
    }

    if (data?.error) {
      error.value = data.error;
      return;
    }

    success.value = true;
    successMessage.value = 'Если email зарегистрирован — на него отправлена ссылка для сброса пароля. Проверьте папку «Входящие» и «Спам». Ссылка действительна 30 минут.';
  } catch (e) {
    error.value = e.message || 'Ошибка соединения с сервером';
  } finally {
    loading.value = false;
  }
}
</script>

<style scoped>
.staff-login-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #502314 0%, #7a3a1e 40%, #E76F51 100%);
  padding: 20px;
  font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
}

.staff-login-content {
  width: 100%;
  max-width: 440px;
}

.staff-login-card {
  background: white;
  border-radius: 20px;
  padding: 32px;
  width: 100%;
  box-shadow: 0 20px 60px rgba(80, 35, 20, 0.3), 0 4px 16px rgba(0, 0, 0, 0.1);
  animation: cardAppear 0.4s ease-out;
  box-sizing: border-box;
}

@keyframes cardAppear {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}

.staff-card-header {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 28px;
  padding-bottom: 20px;
  border-bottom: 1px solid #f0ebe4;
}

.staff-card-icon {
  width: 48px;
  height: 48px;
  border-radius: 12px;
  background: #fff5f2;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.staff-card-header h1 {
  margin: 0;
  font-size: 20px;
  font-weight: 700;
  color: #502314;
}

.staff-card-header p {
  margin: 2px 0 0;
  color: #8b7355;
  font-size: 13px;
}

.staff-field { margin-bottom: 18px; }

.staff-field label {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 600;
  color: #502314;
  margin-bottom: 8px;
}

.staff-field label svg { color: #8b7355; }

.staff-input-wrap { position: relative; }

.staff-input-wrap input {
  width: 100%;
  padding: 14px 16px;
  padding-left: 42px;
  border: 2px solid #e8e0d6;
  border-radius: 12px;
  font-size: 16px;
  font-family: inherit;
  transition: all 0.2s;
  box-sizing: border-box;
  background: #faf8f5;
}

.staff-input-wrap input:focus {
  outline: none;
  border-color: #E76F51;
  background: white;
  box-shadow: 0 0 0 4px rgba(231, 111, 81, 0.08);
}

.staff-input-wrap input:disabled { opacity: 0.6; }
.staff-input-wrap input::placeholder { color: #c4b8a8; }

.staff-input-icon {
  position: absolute;
  left: 14px;
  top: 50%;
  transform: translateY(-50%);
  color: #b0a090;
  font-size: 18px;
  font-weight: 700;
}

.staff-error,
.staff-success {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding: 12px 14px;
  border-radius: 10px;
  font-size: 13px;
  margin-bottom: 18px;
  line-height: 1.5;
}
.staff-error svg,
.staff-success svg { flex-shrink: 0; margin-top: 2px; }

.staff-error {
  background: #fef2f2;
  color: #dc2626;
  border: 1px solid #fecaca;
  animation: shake 0.4s ease;
}

.staff-success {
  background: #f0fdf4;
  color: #16a34a;
  border: 1px solid #bbf7d0;
}

@keyframes shake {
  0%, 100% { transform: translateX(0); }
  20%, 60% { transform: translateX(-6px); }
  40%, 80% { transform: translateX(6px); }
}

.staff-submit-btn {
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

.staff-submit-btn:hover:not(:disabled) {
  background: linear-gradient(135deg, #b81e00 0%, #E76F51 100%);
  box-shadow: 0 6px 20px rgba(231, 111, 81, 0.4);
  transform: translateY(-1px);
}

.staff-submit-btn:active:not(:disabled) {
  transform: translateY(0);
  box-shadow: 0 2px 8px rgba(231, 111, 81, 0.3);
}

.staff-submit-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
  box-shadow: none;
}

.staff-spinner {
  width: 20px;
  height: 20px;
  border: 3px solid rgba(255,255,255,0.3);
  border-top-color: white;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

.staff-back-link {
  margin-top: 20px;
  text-align: center;
}

.staff-back-link a {
  color: #8b7355;
  font-size: 14px;
  text-decoration: none;
}

.staff-back-link a:hover {
  color: #502314;
  text-decoration: underline;
}
</style>
