<template>
  <AuthShell>
    <!-- Проверяем ссылку -->
    <div v-if="checking" class="auth-state">
      <div class="auth-spinner-big"></div>
      <p>Проверяем ссылку…</p>
    </div>

    <!-- Ссылка не подошла -->
    <div v-else-if="!tokenValid">
      <AuthHeader title="Ссылка недействительна" :subtitle="invalidReasonText" tone="error">
        <template #icon>
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round">
            <circle cx="12" cy="12" r="10"/>
            <line x1="15" y1="9" x2="9" y2="15"/>
            <line x1="9" y1="9" x2="15" y2="15"/>
          </svg>
        </template>
      </AuthHeader>

      <router-link to="/staff-forgot-password" class="auth-btn">
        Запросить новую ссылку
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
      </router-link>

      <div class="auth-back">
        <router-link to="/">← Вернуться ко входу</router-link>
      </div>
    </div>

    <!-- Ввод нового пароля -->
    <div v-else>
      <AuthHeader title="Новый пароль" :subtitle="maskedEmail ? `Для аккаунта ${maskedEmail}` : 'Придумайте новый пароль'">
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
          placeholder="Минимум 8 символов"
          :minlength="8"
          :disabled="loading || success"
          with-eye
        />

        <AuthPasswordField
          v-model="confirmPassword"
          :visible="showPassword"
          label="Повторите пароль"
          placeholder="Повторите пароль"
          :disabled="loading || success"
        />

        <AuthAlert v-if="error">{{ error }}</AuthAlert>
        <AuthAlert v-if="success" type="success">Пароль успешно изменён. Перенаправляем на страницу входа…</AuthAlert>

        <button v-if="!success" type="submit" class="auth-btn" :disabled="loading || !newPassword || !confirmPassword || newPassword !== confirmPassword">
          <span v-if="loading" class="auth-spinner"></span>
          <template v-else>
            Сохранить пароль
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
          </template>
        </button>
      </form>

      <div v-if="!success" class="auth-back">
        <router-link to="/staff-forgot-password">← Запросить новую ссылку</router-link>
      </div>
    </div>
  </AuthShell>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { db } from '@/lib/apiClient.js';
import AuthShell from '@/components/auth/AuthShell.vue';
import AuthHeader from '@/components/auth/AuthHeader.vue';
import AuthAlert from '@/components/auth/AuthAlert.vue';
import AuthPasswordField from '@/components/auth/AuthPasswordField.vue';

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
