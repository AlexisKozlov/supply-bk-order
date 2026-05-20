<template>
  <div class="ro-login-page" :class="loginBrand.themeClass">
    <!-- Декоративный фон -->
    <div class="ro-bg-circles">
      <div class="ro-circle ro-circle-1"></div>
      <div class="ro-circle ro-circle-2"></div>
      <div class="ro-circle ro-circle-3"></div>
      <div class="ro-circle ro-circle-4"></div>
    </div>

    <div class="ro-login-content">
      <!-- Логотип -->
      <div class="ro-brand">
        <div class="ro-logo-wrap">
          <svg width="48" height="48" viewBox="5 5 38 38" xmlns="http://www.w3.org/2000/svg" fill="none">
            <circle cx="16" cy="16" r="10" fill="#E76F51"/>
            <circle cx="32" cy="16" r="10" fill="#F4A261"/>
            <circle cx="16" cy="32" r="10" fill="#F4A261"/>
            <circle cx="32" cy="32" r="10" fill="#FFD54F"/>
            <circle cx="24" cy="24" r="8.5" fill="#502314"/>
            <text x="24" y="29" text-anchor="middle" fill="white" font-size="14" font-weight="900" font-family="Arial, sans-serif">{{ loginBrand.logoLetter }}</text>
          </svg>
        </div>
        <div>
          <div class="ro-brand-title">{{ loginBrand.title }}</div>
          <div class="ro-brand-subtitle">{{ loginBrand.subtitle }}</div>
        </div>
      </div>

      <!-- Telegram auto-login -->
      <div v-if="pendingTgToken && !tgLoading" class="ro-login-card">
        <div class="ro-card-header">
          <div class="ro-card-icon">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#E76F51" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 4.5L2.5 12l7 2.5 2.5 7 9.5-17z"/><path d="M9.5 14.5l4-4"/></svg>
          </div>
          <div>
            <h1>Вход через Telegram</h1>
            <p>Подтвердите правила перед входом</p>
          </div>
        </div>
        <label class="ro-consent">
          <input v-model="acceptedDataRules" type="checkbox" />
          <span>Я ознакомлен с <router-link to="/data-rules" target="_blank">правилами использования и обработки данных</router-link></span>
        </label>
        <div v-if="error" class="ro-error">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
          {{ error }}
        </div>
        <button type="button" class="ro-submit-btn" :disabled="!acceptedDataRules" @click="confirmTgLogin">
          Войти через Telegram
        </button>
      </div>

      <div v-else-if="tgLoading" class="ro-login-card" style="text-align:center; padding:40px">
        <div class="ro-spinner" style="margin:0 auto 16px"></div>
        <p style="color:#502314; font-size:15px; font-weight:600; margin:0">Вход через Telegram...</p>
      </div>

      <!-- Карточка входа -->
      <div v-else class="ro-login-card">
        <div class="ro-card-header">
          <div class="ro-card-icon">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#E76F51" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
              <polyline points="10 17 15 12 10 7"/>
              <line x1="15" y1="12" x2="3" y2="12"/>
            </svg>
          </div>
          <div>
            <h1>Вход в систему</h1>
            <p>Введите данные вашего ресторана</p>
          </div>
        </div>

        <form @submit.prevent="handleLogin">
          <div class="ro-field">
            <label>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
              Номер ресторана или email
            </label>
            <div class="ro-input-wrap">
              <input
                v-model="restaurantNumber"
                type="text"
                inputmode="text"
                placeholder="24, PS01 или name@example.com"
                required
                autofocus
                :disabled="loading"
              />
              <span class="ro-input-icon">#</span>
            </div>
          </div>

          <div class="ro-field">
            <label>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              Пароль
            </label>
            <div class="ro-input-wrap">
              <input
                v-model="password"
                :type="showPassword ? 'text' : 'password'"
                placeholder="Введите пароль"
                required
                :disabled="loading"
              />
              <button type="button" class="ro-toggle-pass" @click="showPassword = !showPassword" tabindex="-1">
                {{ showPassword ? '🙈' : '👁' }}
              </button>
            </div>
            <div class="ro-forgot-row">
              <router-link :to="{ name: 'ForgotPassword' }" class="ro-forgot-link">Забыли пароль?</router-link>
            </div>
          </div>

          <div v-if="error" class="ro-error">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            {{ error }}
          </div>

          <label class="ro-consent">
            <input v-model="acceptedDataRules" type="checkbox" :disabled="loading" />
            <span>Я ознакомлен с <router-link to="/data-rules" target="_blank">правилами использования и обработки данных</router-link></span>
          </label>

          <label class="ro-consent ro-remember">
            <input v-model="rememberDevice" type="checkbox" :disabled="loading" />
            <span>Запомнить это устройство на 30 дней</span>
          </label>

          <button type="submit" class="ro-submit-btn" :disabled="loading || !restaurantNumber || !password || !acceptedDataRules">
            <span v-if="loading" class="ro-spinner"></span>
            <template v-else>
              Войти
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
            </template>
          </button>
        </form>
      </div>

      <!-- Подсказки -->
      <div class="ro-login-footer">
        {{ loginBrand.footer }}
        <span class="ro-help-sep">·</span>
        <a class="ro-help-link" href="https://t.me/alexiskozlov" target="_blank" rel="noopener">Нужна помощь?</a>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useRestaurantOrderStore } from '@/stores/restaurantOrderStore.js';
import { parseRestaurantInput } from '@/lib/legalEntities.js';

const router = useRouter();
const route = useRoute();
const store = useRestaurantOrderStore();

const restaurantNumber = ref('');
const password = ref('');
const loading = ref(false);
const error = ref('');
const showPassword = ref(false);
const tgLoading = ref(false);
const acceptedDataRules = ref(false);
const rememberDevice = ref(true);
const pendingTgToken = ref('');
const pendingTgRedirect = ref(null);

const loginBrand = computed(() => {
  const parsed = parseRestaurantInput(restaurantNumber.value);
  if (parsed?.group === 'PS') {
    return {
      title: 'Pizza Star Supply Portal',
      subtitle: 'Личный кабинет ресторана Pizza Star',
      footer: 'Pizza Star Supply Portal · Личный кабинет ресторана',
      logoLetter: 'P',
      themeClass: 'ro-theme-ps',
    };
  }
  if (parsed?.group === 'BK_VM') {
    return {
      title: 'Burger King Supply Portal',
      subtitle: 'Личный кабинет ресторана Burger King',
      footer: 'Burger King Supply Portal · Личный кабинет ресторана',
      logoLetter: 'B',
      themeClass: 'ro-theme-bk',
    };
  }
  return {
    title: 'Supply Portal',
    subtitle: 'Личный кабинет ресторана',
    footer: 'Supply Portal · Личный кабинет ресторана',
    logoLetter: 'S',
    themeClass: 'ro-theme-neutral',
  };
});

function safeRedirect(target) {
  // Разрешаем только локальные пути под /restaurant/ — защита от open-redirect
  if (typeof target === 'string' && /^\/restaurant(\/|$)/.test(target)) return target;
  return null;
}

async function handleTgLogin(tgToken, redirectTarget) {
  if (!acceptedDataRules.value) {
    pendingTgToken.value = tgToken;
    pendingTgRedirect.value = redirectTarget;
    return;
  }
  tgLoading.value = true;
  // Очищаем предыдущее состояние локально, не дергая серверный logout:
  // иначе убьётся session_token активной сессии того же ресторана на другом устройстве.
  store.logoutLocal();
  try {
    const result = await store.loginByTelegram(tgToken, acceptedDataRules.value);
    if (result.success) {
      if (redirectTarget) {
        router.replace(redirectTarget);
      } else {
        router.replace({ name: 'restaurant-cabinet' });
      }
      return;
    }
    error.value = result.error || 'Ссылка недействительна или истекла';
  } catch (e) {
    error.value = e.message || 'Ошибка входа через Telegram';
  } finally {
    tgLoading.value = false;
  }
  // Убираем токен из URL
  router.replace({ query: {} });
}

async function confirmTgLogin() {
  if (!acceptedDataRules.value) {
    error.value = 'Подтвердите согласие с правилами использования портала';
    return;
  }
  const token = pendingTgToken.value;
  const redirect = pendingTgRedirect.value;
  pendingTgToken.value = '';
  pendingTgRedirect.value = null;
  await handleTgLogin(token, redirect);
}

onMounted(async () => {
  // Автовход по токену из Telegram
  const tgToken = route.query.tg_token;
  const redirectTarget = safeRedirect(route.query.redirect);
  if (tgToken) {
    await handleTgLogin(tgToken, redirectTarget);
    return;
  }

  if (store.isAuthenticated) {
    const valid = await store.validate();
    if (valid) {
      if (redirectTarget) {
        router.replace(redirectTarget);
      } else {
        router.replace({ name: 'restaurant-cabinet' });
      }
      return;
    }
  }
});

// Если страница уже открыта (например, в Telegram WebApp), а URL поменялся —
// например, пользователь нажал «Через сайт» для другого ресторана — обработать новый токен
watch(() => route.query.tg_token, (newToken) => {
  if (newToken) {
    handleTgLogin(newToken, safeRedirect(route.query.redirect));
  }
});

// Преобразуем ввод вида '24' или 'PS01' в числовой номер для сервера
function parsedRestaurant() {
  return parseRestaurantInput(restaurantNumber.value);
}

async function handleLogin() {
  error.value = '';
  if (!acceptedDataRules.value) {
    error.value = 'Подтвердите согласие с правилами использования портала';
    return;
  }
  // Поле принимает либо номер ресторана, либо email. По @ определяем.
  const raw = (restaurantNumber.value || '').trim();
  let identifier, group = null;
  if (raw.includes('@')) {
    identifier = raw;
  } else {
    const parsed = parsedRestaurant();
    if (!parsed?.number) {
      error.value = 'Неверный номер ресторана или email. Пример: 24, PS01 или name@example.com';
      return;
    }
    identifier = parsed.number;
    group = parsed.group;
  }
  loading.value = true;
  try {
    const result = await store.login(identifier, password.value, group, rememberDevice.value, acceptedDataRules.value);
    if (result.success) {
      const redirectTarget = safeRedirect(route.query.redirect);
      router.push(redirectTarget || { name: 'restaurant-cabinet' });
    } else {
      error.value = result.error || 'Ошибка входа';
    }
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
  position: relative;
  overflow: hidden;
}

.ro-login-page.ro-theme-ps {
  background: linear-gradient(135deg, #3b1f12 0%, #8a2d12 45%, #f16a21 100%);
}

.ro-login-page.ro-theme-neutral {
  background: linear-gradient(135deg, #4b3a2f 0%, #7a5a45 45%, #c7773b 100%);
}

/* Декоративные круги */
.ro-bg-circles { position: absolute; inset: 0; pointer-events: none; overflow: hidden; }
.ro-circle {
  position: absolute;
  border-radius: 50%;
  opacity: 0.08;
  background: white;
}
.ro-circle-1 { width: 400px; height: 400px; top: -100px; right: -100px; }
.ro-circle-2 { width: 300px; height: 300px; bottom: -80px; left: -80px; }
.ro-circle-3 { width: 200px; height: 200px; top: 40%; left: 10%; opacity: 0.04; }
.ro-circle-4 { width: 150px; height: 150px; bottom: 20%; right: 15%; opacity: 0.06; }

.ro-login-content {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 100%;
  max-width: 420px;
}

/* Логотип */
.ro-brand {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 28px;
  color: white;
}
.ro-logo-wrap {
  display: flex;
  align-items: center;
  justify-content: center;
}
.ro-brand-title {
  font-size: 22px;
  font-weight: 800;
  letter-spacing: -0.5px;
  text-shadow: 0 2px 8px rgba(0,0,0,0.2);
}
.ro-brand-subtitle {
  font-size: 13px;
  opacity: 0.8;
  margin-top: 2px;
}

/* Карточка */
.ro-login-card {
  background: white;
  border-radius: 20px;
  padding: 32px;
  width: 100%;
  box-shadow:
    0 20px 60px rgba(80, 35, 20, 0.3),
    0 4px 16px rgba(0, 0, 0, 0.1);
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

/* Поля */
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
  padding-left: 42px;
  border: 2px solid #e8e0d6;
  border-radius: 12px;
  font-size: 16px;
  font-family: inherit;
  transition: all 0.2s;
  box-sizing: border-box;
  background: #faf8f5;
}
.ro-input-wrap input:focus {
  outline: none;
  border-color: #E76F51;
  background: white;
  box-shadow: 0 0 0 4px rgba(231, 111, 81, 0.08);
}
.ro-input-wrap input::placeholder { color: #c4b8a8; }
.ro-input-wrap input[type="number"] { text-align: left; -moz-appearance: textfield; }
.ro-input-wrap input[type="number"]::-webkit-inner-spin-button,
.ro-input-wrap input[type="number"]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }

.ro-input-icon {
  position: absolute;
  left: 14px;
  top: 50%;
  transform: translateY(-50%);
  color: #b0a090;
  font-size: 16px;
  font-weight: 700;
}
.ro-toggle-pass {
  position: absolute;
  right: 10px;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  cursor: pointer;
  font-size: 18px;
  padding: 4px 6px;
  border-radius: 6px;
  opacity: 0.6;
}
.ro-toggle-pass:hover { opacity: 1; }

/* Ошибка */
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
.ro-error svg { flex-shrink: 0; color: #dc2626; }

/* Кнопка входа */
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
.ro-consent {
  display: flex;
  align-items: flex-start;
  gap: 9px;
  margin: 2px 0 16px;
  color: #6f5948;
  font-size: 13px;
  line-height: 1.4;
  cursor: pointer;
}
.ro-consent input {
  width: 16px;
  height: 16px;
  margin-top: 1px;
  accent-color: #E76F51;
  flex-shrink: 0;
}
.ro-consent a {
  color: #b52200;
  font-weight: 700;
  text-decoration: none;
}
.ro-consent a:hover { text-decoration: underline; }
.ro-spinner {
  width: 20px; height: 20px;
  border: 3px solid rgba(255,255,255,0.3);
  border-top-color: white;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* Подсказки */
.ro-forgot-row { display: flex; justify-content: flex-end; margin-top: 6px; }
.ro-forgot-link {
  color: #8b7355;
  font-size: 12px;
  font-weight: 600;
  text-decoration: none;
}
.ro-forgot-link:hover { color: #E76F51; text-decoration: underline; }

/* Футер */
.ro-login-footer {
  margin-top: 20px;
  color: rgba(255,255,255,0.4);
  font-size: 12px;
  text-align: center;
}
.ro-help-sep { margin: 0 6px; opacity: 0.6; }
.ro-help-link {
  color: rgba(255,255,255,0.55);
  text-decoration: none;
  transition: color 0.2s;
}
.ro-help-link:hover {
  color: rgba(255,255,255,0.95);
  text-decoration: underline;
  text-underline-offset: 3px;
}

.ro-remember {
  margin-top: -4px;
}

/* Мобильная адаптация */
@media (max-width: 480px) {
  .ro-login-page { padding: 16px; }
  .ro-login-card { padding: 24px; border-radius: 16px; }
  .ro-brand-title { font-size: 18px; }
  .ro-card-header { flex-direction: column; text-align: center; gap: 10px; }
}
</style>
