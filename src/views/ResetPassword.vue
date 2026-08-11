<template>
  <AuthShell>
    <AuthHeader title="Новый пароль" subtitle="Придумайте новый пароль для входа">
      <template #icon>
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#E76F51" stroke-width="2" stroke-linecap="round">
          <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
          <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        </svg>
      </template>
    </AuthHeader>

    <form @submit.prevent="handleReset">
      <AuthPasswordField
        v-model="newPassword"
        v-model:visible="showPassword"
        label="Новый пароль"
        placeholder="Минимум 6 символов"
        :minlength="6"
        :disabled="loading"
        with-eye
      />

      <AuthPasswordField
        v-model="confirmPassword"
        :visible="showPassword"
        label="Повторите пароль"
        placeholder="Повторите пароль"
        :disabled="loading"
      />

      <AuthAlert v-if="error">{{ error }}</AuthAlert>
      <AuthAlert v-if="success" type="success">{{ successMessage }}</AuthAlert>

      <button type="submit" class="auth-btn" :disabled="loading || !newPassword || !confirmPassword || newPassword !== confirmPassword">
        <span v-if="loading" class="auth-spinner"></span>
        <template v-else>
          Сохранить пароль
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </template>
      </button>
    </form>

    <div v-if="!success" class="auth-back">
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
import AuthPasswordField from '@/components/auth/AuthPasswordField.vue';

const router = useRouter();
const route = useRoute();

const resetToken = computed(() => route.query.token || '');
const newPassword = ref('');
const confirmPassword = ref('');
const showPassword = ref(false);
const loading = ref(false);
const error = ref('');
const success = ref(false);
const successMessage = ref('');

async function handleReset() {
  error.value = '';
  success.value = false;

  if (newPassword.value !== confirmPassword.value) {
    error.value = 'Пароли не совпадают';
    return;
  }

  if (newPassword.value.length < 6) {
    error.value = 'Пароль должен быть не менее 6 символов';
    return;
  }

  if (!resetToken.value) {
    error.value = 'Ошибка: не получен токен для сброса';
    return;
  }

  loading.value = true;

  try {
    const { data, error: rpcError } = await db.rpc('reset_password', {
      reset_token: resetToken.value,
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
    successMessage.value = 'Пароль успешно изменён! Теперь вы можете войти с новым паролем.';

    // Через 3 секунды переходим на страницу входа
    setTimeout(() => {
      router.push({ name: 'restaurant-order-login' });
    }, 3000);
  } catch (e) {
    error.value = e.message || 'Ошибка соединения с сервером';
  } finally {
    loading.value = false;
  }
}
</script>
