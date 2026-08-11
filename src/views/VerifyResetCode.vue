<template>
  <AuthShell>
    <AuthHeader title="Подтверждение кода">
      Введите код из Telegram для ресторана <b>{{ restaurantNumber }}</b>
      <template #icon>
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#E76F51" stroke-width="2" stroke-linecap="round">
          <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
          <polyline points="10 17 15 12 10 7"/>
          <line x1="15" y1="12" x2="3" y2="12"/>
        </svg>
      </template>
    </AuthHeader>

    <form @submit.prevent="handleVerify">
      <div class="auth-field">
        <label>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Код из Telegram
        </label>
        <div class="auth-input-wrap auth-input-wrap--code">
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

      <AuthAlert v-if="error">{{ error }}</AuthAlert>

      <button type="submit" class="auth-btn" :disabled="loading || code.length !== 6">
        <span v-if="loading" class="auth-spinner"></span>
        <template v-else>
          Подтвердить
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </template>
      </button>
    </form>

    <div class="auth-back">
      <router-link :to="{ name: 'ForgotPassword' }">← Запросить новый код</router-link>
    </div>
  </AuthShell>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { db } from '@/lib/apiClient.js';
import AuthShell from '@/components/auth/AuthShell.vue';
import AuthHeader from '@/components/auth/AuthHeader.vue';
import AuthAlert from '@/components/auth/AuthAlert.vue';

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
