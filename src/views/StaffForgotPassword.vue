<template>
  <AuthShell>
    <AuthHeader title="Восстановление пароля" subtitle="Введите email — отправим ссылку для сброса">
      <template #icon>
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#E76F51" stroke-width="2" stroke-linecap="round">
          <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
          <polyline points="22,6 12,13 2,6"/>
        </svg>
      </template>
    </AuthHeader>

    <form @submit.prevent="handleRequest">
      <div class="auth-field">
        <label>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          Email
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
    </form>

    <div class="auth-back">
      <router-link to="/">← Вернуться ко входу</router-link>
    </div>
  </AuthShell>
</template>

<script setup>
import { ref } from 'vue';
import { db } from '@/lib/apiClient.js';
import AuthShell from '@/components/auth/AuthShell.vue';
import AuthHeader from '@/components/auth/AuthHeader.vue';
import AuthAlert from '@/components/auth/AuthAlert.vue';

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
