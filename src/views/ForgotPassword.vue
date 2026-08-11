<template>
  <AuthShell>
    <AuthHeader title="Сброс пароля" subtitle="Выберите, как восстановить пароль кабинета">
      <template #icon>
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#E76F51" stroke-width="2" stroke-linecap="round">
          <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
          <polyline points="10 17 15 12 10 7"/>
          <line x1="15" y1="12" x2="3" y2="12"/>
        </svg>
      </template>
    </AuthHeader>

    <!-- Переключатель способа -->
    <div class="auth-tabs">
      <button
        type="button"
        class="auth-tab"
        :class="{ active: method === 'email' }"
        @click="switchMethod('email')"
        :disabled="loading"
      >
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        По email
      </button>
      <button
        type="button"
        class="auth-tab"
        :class="{ active: method === 'telegram' }"
        @click="switchMethod('telegram')"
        :disabled="loading"
      >
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>
        По Telegram
      </button>
    </div>

    <!-- Email-форма -->
    <form v-if="method === 'email'" @submit.prevent="handleRequestByEmail">
      <div class="auth-field">
        <label>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          Email кабинета ресторана
        </label>
        <div class="auth-input-wrap auth-input-wrap--icon">
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
          <span class="auth-input-icon">@</span>
        </div>
      </div>

      <AuthAlert v-if="error">{{ error }}</AuthAlert>
      <AuthAlert v-if="success" type="success">{{ successMessage }}</AuthAlert>

      <button v-if="!success" type="submit" class="auth-btn" :disabled="loading || !email">
        <span v-if="loading" class="auth-spinner"></span>
        <template v-else>
          Отправить ссылку
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </template>
      </button>

      <p class="auth-hint">
        Email-способ работает, если адрес был указан и подтверждён в кабинете. Если нет — выберите «По Telegram».
      </p>
    </form>

    <!-- Telegram-форма -->
    <form v-else @submit.prevent="handleRequestByTelegram">
      <div class="auth-field">
        <label>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
          Номер ресторана
        </label>
        <div class="auth-input-wrap auth-input-wrap--icon">
          <input
            v-model="restaurantNumber"
            type="text"
            inputmode="text"
            autocapitalize="characters"
            placeholder="Например: 24 или PS01"
            required
            :disabled="loading"
          />
          <span class="auth-input-icon">#</span>
        </div>
      </div>

      <AuthAlert v-if="error">{{ error }}</AuthAlert>
      <AuthAlert v-if="success" type="success">{{ successMessage }}</AuthAlert>

      <button type="submit" class="auth-btn" :disabled="loading || !restaurantNumber">
        <span v-if="loading" class="auth-spinner"></span>
        <template v-else>
          Отправить код в Telegram
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </template>
      </button>

      <p class="auth-hint">
        Код придёт в Telegram-бот, к которому привязан ресторан. Если бот не подключён — выберите «По email» или обратитесь к закупщику.
      </p>
    </form>

    <div class="auth-back">
      <router-link to="/restaurant/login">← Вернуться ко входу</router-link>
    </div>
  </AuthShell>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { db } from '@/lib/apiClient.js';
import AuthShell from '@/components/auth/AuthShell.vue';
import AuthHeader from '@/components/auth/AuthHeader.vue';
import AuthAlert from '@/components/auth/AuthAlert.vue';

const router = useRouter();

const method = ref('email');
const email = ref('');
const restaurantNumber = ref('');
const loading = ref(false);
const error = ref('');
const success = ref(false);
const successMessage = ref('');

function switchMethod(m) {
  if (loading.value) return;
  method.value = m;
  error.value = '';
  success.value = false;
  successMessage.value = '';
}

async function handleRequestByEmail() {
  error.value = '';
  success.value = false;
  loading.value = true;
  try {
    const apiBase = `${window.location.origin}/api/ro`;
    const res = await fetch(`${apiBase}/request-password-reset-by-email`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: email.value.trim() }),
    });
    const data = await res.json().catch(() => ({}));
    if (data?.error) {
      error.value = data.error;
      return;
    }
    success.value = true;
    successMessage.value = 'Если email указан и подтверждён в кабинете — на него отправлена ссылка для сброса пароля. Проверьте папку «Входящие» и «Спам». Ссылка действительна 30 минут.';
  } catch (e) {
    error.value = e?.message || 'Ошибка соединения с сервером';
  } finally {
    loading.value = false;
  }
}

async function handleRequestByTelegram() {
  error.value = '';
  success.value = false;
  loading.value = true;
  try {
    const { data, error: rpcError } = await db.rpc('request_password_reset', {
      restaurant_number: restaurantNumber.value,
    });
    if (rpcError) { error.value = rpcError; return; }
    if (data?.error) { error.value = data.error; return; }
    success.value = true;
    successMessage.value = 'Если ресторан подписан на Telegram, код будет отправлен. Проверьте Telegram.';
    setTimeout(() => {
      router.push({
        name: 'VerifyResetCode',
        query: { restaurant: restaurantNumber.value },
      });
    }, 2000);
  } catch (e) {
    error.value = e.message || 'Ошибка соединения с сервером';
  } finally {
    loading.value = false;
  }
}
</script>
