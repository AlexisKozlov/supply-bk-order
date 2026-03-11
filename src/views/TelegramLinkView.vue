<template>
  <div class="tgl-page">
    <canvas ref="bgCanvas" class="tgl-canvas"></canvas>

    <div class="tgl-center">
      <div class="tgl-card">
        <!-- Левая панель с логотипом -->
        <div class="tgl-left">
          <div class="tgl-brand">
            <span class="tgl-brand-icon"><SupplyLogo :size="40"/></span>
            <div class="tgl-brand-title">Supply Department</div>
            <div class="tgl-brand-sub">Telegram</div>
          </div>
        </div>

        <!-- Правая панель -->
        <div class="tgl-right">
          <!-- Загрузка -->
          <div v-if="loading" class="tgl-loading">
            <div class="tgl-spinner"></div>
            <div class="tgl-loading-text">Проверяем ссылку...</div>
          </div>

          <!-- Ошибка -->
          <div v-else-if="error" class="tgl-content">
            <div class="tgl-status tgl-status-error">
              <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#D62700" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg>
            </div>
            <div class="tgl-form-title">{{ error }}</div>
            <div class="tgl-form-sub">{{ errorDetail }}</div>
            <a href="https://t.me/supplyportal_bot" class="tgl-submit tgl-submit-outline">Открыть бота</a>
          </div>

          <!-- Успех -->
          <div v-else-if="success" class="tgl-content">
            <div class="tgl-status tgl-status-ok">
              <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#2e7d32" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
            </div>
            <div class="tgl-form-title">Telegram привязан</div>
            <div class="tgl-form-sub">
              Аккаунт <b>{{ linkedName }}</b> подключён к боту.
              <br>Вернитесь в Telegram и нажмите /start
            </div>
            <a href="https://t.me/supplyportal_bot" class="tgl-submit">Открыть бота</a>
          </div>

          <!-- Подтверждение (залогинен) -->
          <div v-else-if="userStore.isAuthenticated && tokenValid" class="tgl-content">
            <div class="tgl-form-title">Привязка Telegram</div>
            <div class="tgl-form-sub">
              Привязать Telegram
              <span v-if="tgUsername" class="tgl-tg-name">@{{ tgUsername }}</span>
              к аккаунту <b>{{ userStore.currentUser.name }}</b>?
            </div>
            <button class="tgl-submit" :disabled="confirming" @click="confirm">
              {{ confirming ? 'Привязка...' : 'Привязать' }}
            </button>
          </div>

          <!-- Вход -->
          <div v-else-if="tokenValid" class="tgl-content">
            <div class="tgl-form-title">Вход в систему</div>
            <div class="tgl-form-sub">Войдите в аккаунт для привязки Telegram</div>
            <form @submit.prevent="login">
              <div class="tgl-field">
                <label>Email</label>
                <input v-model="email" type="email" placeholder="Введите email" autofocus />
              </div>
              <div class="tgl-field">
                <label>Пароль</label>
                <input v-model="password" type="password" placeholder="Введите пароль" />
              </div>
              <div v-if="loginError" class="tgl-error">{{ loginError }}</div>
              <button type="submit" class="tgl-submit" :disabled="loggingIn || !email">
                {{ loggingIn ? 'Вход...' : 'Войти и привязать' }}
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { useRoute } from 'vue-router';
import { useUserStore } from '@/stores/userStore.js';
import { db } from '@/lib/apiClient.js';
import { useCanvasParticles } from '@/composables/useCanvasParticles.js';
import SupplyLogo from '@/components/ui/SupplyLogo.vue';

const route = useRoute();
const userStore = useUserStore();

const loading = ref(true);
const error = ref('');
const errorDetail = ref('');
const tokenValid = ref(false);
const tgUsername = ref('');
const success = ref(false);
const linkedName = ref('');
const confirming = ref(false);

const email = ref('');
const password = ref('');
const loggingIn = ref(false);
const loginError = ref('');

const bgCanvas = ref(null);
const { start: startBg, stop: stopBg } = useCanvasParticles(bgCanvas);

const token = route.query.token || '';

onMounted(async () => {
  startBg();

  if (!token) {
    error.value = 'Ссылка недействительна';
    errorDetail.value = 'Откройте бота и нажмите /start для получения новой ссылки.';
    loading.value = false;
    return;
  }

  if (!userStore.currentUser) {
    userStore.restoreSession();
  }

  try {
    const { data, error: err } = await db.rpc('get_telegram_link', { token });
    if (err || data?.error) {
      error.value = 'Ссылка устарела';
      errorDetail.value = 'Нажмите /start в боте, чтобы получить новую ссылку.';
      loading.value = false;
      return;
    }
    tokenValid.value = true;
    tgUsername.value = data.telegram_username || '';
  } catch (e) {
    error.value = 'Ошибка соединения';
    errorDetail.value = 'Попробуйте ещё раз.';
  }
  loading.value = false;
});

onUnmounted(() => {
  stopBg();
});

async function confirm() {
  confirming.value = true;
  try {
    const { data, error: err } = await db.rpc('confirm_telegram_link', { token });
    if (err || data?.error) {
      error.value = 'Не удалось привязать';
      errorDetail.value = data?.error === 'invalid_or_expired_token'
        ? 'Ссылка устарела. Нажмите /start в боте.'
        : 'Попробуйте ещё раз.';
      tokenValid.value = false;
      return;
    }
    success.value = true;
    linkedName.value = data.user_name || userStore.currentUser?.name || '';
    if (userStore.currentUser) {
      userStore.currentUser.telegram_connected = true;
    }
  } catch (e) {
    error.value = 'Ошибка привязки';
    errorDetail.value = 'Попробуйте ещё раз.';
  } finally {
    confirming.value = false;
  }
}

async function login() {
  if (!email.value || !password.value) {
    loginError.value = 'Заполните email и пароль';
    return;
  }
  loggingIn.value = true;
  loginError.value = '';
  try {
    await userStore.login(email.value, password.value);
    await confirm();
  } catch (e) {
    loginError.value = 'Неверный email или пароль';
  } finally {
    loggingIn.value = false;
  }
}

/* Анимация фона вынесена в useCanvasParticles */
</script>

<style scoped>
.tgl-page {
  position: fixed;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: 'Sora', -apple-system, BlinkMacSystemFont, sans-serif;
  overflow: hidden;
  background: #110a05;
}
.tgl-canvas {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  z-index: 0;
}
.tgl-center {
  position: relative;
  z-index: 1;
  width: 100%;
  max-width: 620px;
  padding: 16px;
}

/* Карточка — как login-card на главной */
.tgl-card {
  display: flex;
  width: 100%;
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 24px 64px rgba(0,0,0,.4);
}

/* Левая панель */
.tgl-left {
  width: 200px;
  flex-shrink: 0;
  background: linear-gradient(160deg, #502314, #3A1A0C);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 32px 16px;
}
.tgl-brand { text-align: center; }
.tgl-brand-icon { display: block; margin-bottom: 10px; }
.tgl-brand-title {
  font-size: 18px;
  font-weight: 400;
  color: #F5E6D0;
  font-family: 'Flame', 'Sora', sans-serif;
}
.tgl-brand-sub {
  font-size: 9px;
  font-weight: 700;
  color: rgba(245,166,35,.5);
  text-transform: uppercase;
  letter-spacing: 2px;
  margin-top: 4px;
}

/* Правая панель */
.tgl-right {
  flex: 1;
  background: #FFFBF5;
  padding: 32px;
  position: relative;
  min-height: 260px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.tgl-content {
  width: 100%;
}

/* Заголовки */
.tgl-form-title {
  font-size: 18px;
  font-weight: 800;
  color: #502314;
  margin-bottom: 4px;
}
.tgl-form-sub {
  font-size: 12px;
  color: #A08870;
  margin-bottom: 20px;
  line-height: 1.6;
}
.tgl-form-sub b { color: #502314; }
.tgl-tg-name { color: #2196F3; font-weight: 600; }

/* Статус-иконка */
.tgl-status {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 12px;
}
.tgl-status-error { background: rgba(214,39,0,.1); }
.tgl-status-ok { background: rgba(46,125,50,.1); }

/* Поля формы — как на главной */
.tgl-field { margin-bottom: 12px; }
.tgl-field label {
  display: block;
  font-size: 10px;
  font-weight: 700;
  color: #8B7355;
  text-transform: uppercase;
  letter-spacing: .6px;
  margin-bottom: 4px;
}
.tgl-field input {
  width: 100%;
  padding: 10px 12px;
  border: 1.5px solid #E8DDD0;
  border-radius: 8px;
  font-size: 13px;
  font-family: inherit;
  background: #fff;
  box-sizing: border-box;
  color: #502314;
}
.tgl-field input:focus {
  border-color: #D62700;
  outline: none;
}
.tgl-field input::placeholder { color: #C4B8A8; }

.tgl-error {
  color: #D62700;
  font-size: 12px;
  font-weight: 600;
  margin-bottom: 8px;
}

/* Кнопка — как login-submit */
.tgl-submit {
  display: block;
  width: 100%;
  padding: 11px;
  border: none;
  border-radius: 10px;
  background: #D62700;
  color: #fff;
  font-size: 14px;
  font-weight: 700;
  font-family: inherit;
  cursor: pointer;
  transition: .2s;
  text-align: center;
  text-decoration: none;
}
.tgl-submit:hover { background: #B52200; }
.tgl-submit:disabled { opacity: .5; cursor: default; }
.tgl-submit-outline {
  background: transparent;
  border: 1.5px solid #E8DDD0;
  color: #502314;
}
.tgl-submit-outline:hover { background: #F5EDE2; }

/* Загрузка */
.tgl-loading {
  text-align: center;
  padding: 20px 0;
}
.tgl-spinner {
  width: 28px;
  height: 28px;
  border: 3px solid #E8DDD0;
  border-top-color: #D62700;
  border-radius: 50%;
  animation: tgl-spin .7s linear infinite;
  margin: 0 auto 12px;
}
.tgl-loading-text {
  font-size: 12px;
  color: #A08870;
}
@keyframes tgl-spin { to { transform: rotate(360deg); } }

/* Мобилки */
@media (max-width: 600px) {
  .tgl-left { display: none; }
  .tgl-card { border-radius: 12px; }
  .tgl-right { padding: 24px 16px; }
}
</style>
