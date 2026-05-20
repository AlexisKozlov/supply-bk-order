<template>
  <div class="staff-login-page">
    <div class="staff-login-content">
      <div class="staff-login-card">

        <!-- Состояние: проверка токена -->
        <div v-if="checking" class="staff-checking">
          <div class="staff-spinner-big"></div>
          <p>Проверяем ссылку…</p>
        </div>

        <!-- Состояние: токен невалиден -->
        <div v-else-if="!tokenValid">
          <div class="staff-card-header">
            <div class="staff-card-icon staff-card-icon-error">
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round">
                <circle cx="12" cy="12" r="10"/>
                <line x1="15" y1="9" x2="9" y2="15"/>
                <line x1="9" y1="9" x2="15" y2="15"/>
              </svg>
            </div>
            <div>
              <h1>Ссылка недействительна</h1>
              <p>{{ invalidReasonText }}</p>
            </div>
          </div>

          <router-link to="/staff-forgot-password" class="staff-submit-btn staff-submit-btn-link">
            Запросить новую ссылку
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
          </router-link>

          <div class="staff-back-link">
            <router-link to="/">← Вернуться ко входу</router-link>
          </div>
        </div>

        <!-- Состояние: ввод нового пароля -->
        <div v-else>
          <div class="staff-card-header">
            <div class="staff-card-icon">
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#E76F51" stroke-width="2" stroke-linecap="round">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
              </svg>
            </div>
            <div>
              <h1>Новый пароль</h1>
              <p v-if="maskedEmail">Для аккаунта {{ maskedEmail }}</p>
              <p v-else>Придумайте новый пароль</p>
            </div>
          </div>

          <form @submit.prevent="handleReset">
            <div class="staff-field">
              <label>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Новый пароль
              </label>
              <div class="staff-input-wrap">
                <input
                  v-model="newPassword"
                  :type="showPassword ? 'text' : 'password'"
                  placeholder="Минимум 8 символов"
                  required
                  minlength="8"
                  autocomplete="new-password"
                  :disabled="loading || success"
                />
                <button type="button" class="staff-toggle-pass" @click="showPassword = !showPassword" tabindex="-1">
                  {{ showPassword ? '🙈' : '👁' }}
                </button>
              </div>
            </div>

            <div class="staff-field">
              <label>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Повторите пароль
              </label>
              <div class="staff-input-wrap">
                <input
                  v-model="confirmPassword"
                  :type="showPassword ? 'text' : 'password'"
                  placeholder="Повторите пароль"
                  required
                  autocomplete="new-password"
                  :disabled="loading || success"
                />
              </div>
            </div>

            <div v-if="error" class="staff-error">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
              {{ error }}
            </div>

            <div v-if="success" class="staff-success">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
              Пароль успешно изменён. Перенаправляем на страницу входа…
            </div>

            <button v-if="!success" type="submit" class="staff-submit-btn" :disabled="loading || !newPassword || !confirmPassword || newPassword !== confirmPassword">
              <span v-if="loading" class="staff-spinner"></span>
              <template v-else>
                Сохранить пароль
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
              </template>
            </button>
          </form>

          <div v-if="!success" class="staff-back-link">
            <router-link to="/staff-forgot-password">← Запросить новую ссылку</router-link>
          </div>
        </div>

      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { db } from '@/lib/apiClient.js';

const router = useRouter();
const route = useRoute();

const token = computed(() => String(route.query.token || ''));
const checking = ref(true);
const tokenValid = ref(false);
const invalidReason = ref('');
const maskedEmail = ref('');

const newPassword = ref('');
const confirmPassword = ref('');
const showPassword = ref(false);
const loading = ref(false);
const error = ref('');
const success = ref(false);

const invalidReasonText = computed(() => {
  switch (invalidReason.value) {
    case 'expired': return 'Срок действия ссылки истёк (30 минут). Запросите новую.';
    case 'used':    return 'Эта ссылка уже была использована. Запросите новую, если нужно сменить пароль.';
    case 'invalid':
    default:        return 'Ссылка повреждена или не существует.';
  }
});

onMounted(async () => {
  if (!token.value) {
    tokenValid.value = false;
    invalidReason.value = 'invalid';
    checking.value = false;
    return;
  }
  try {
    const { data, error: rpcError } = await db.rpc('verify_staff_reset_token', { token: token.value });
    if (rpcError) {
      tokenValid.value = false;
      invalidReason.value = 'invalid';
    } else if (data?.valid) {
      tokenValid.value = true;
      maskedEmail.value = data.email || '';
    } else {
      tokenValid.value = false;
      invalidReason.value = data?.reason || 'invalid';
    }
  } catch (e) {
    tokenValid.value = false;
    invalidReason.value = 'invalid';
  } finally {
    checking.value = false;
  }
});

async function handleReset() {
  error.value = '';
  success.value = false;

  if (newPassword.value !== confirmPassword.value) {
    error.value = 'Пароли не совпадают';
    return;
  }
  if (newPassword.value.length < 8) {
    error.value = 'Пароль должен быть не менее 8 символов';
    return;
  }

  loading.value = true;
  try {
    const { data, error: rpcError } = await db.rpc('reset_staff_password', {
      token: token.value,
      new_password: newPassword.value,
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
    setTimeout(() => router.push('/'), 2000);
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

.staff-checking {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
  padding: 30px 0;
  color: #8b7355;
}

.staff-spinner-big {
  width: 40px;
  height: 40px;
  border: 4px solid #f0ebe4;
  border-top-color: #E76F51;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
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

.staff-card-icon-error { background: #fef2f2; }

.staff-card-header h1 {
  margin: 0;
  font-size: 20px;
  font-weight: 700;
  color: #502314;
}

.staff-card-header p {
  margin: 4px 0 0;
  color: #8b7355;
  font-size: 13px;
  line-height: 1.45;
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
  padding-right: 48px;
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

.staff-toggle-pass {
  position: absolute;
  right: 8px;
  top: 50%;
  transform: translateY(-50%);
  background: transparent;
  border: none;
  padding: 6px 10px;
  font-size: 18px;
  cursor: pointer;
  border-radius: 8px;
}
.staff-toggle-pass:hover { background: #f0ebe4; }

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
  text-decoration: none;
}

.staff-submit-btn-link { text-decoration: none; }

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
