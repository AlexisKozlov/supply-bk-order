<template>
  <AuthShell centered>
    <!-- Проверяем ссылку -->
    <div v-if="checking" class="auth-state">
      <div class="auth-spinner-big"></div>
      <p>Подтверждаем email…</p>
    </div>

    <!-- Успех -->
    <div v-else-if="success" class="auth-state">
      <div class="auth-state-icon auth-state-icon--success">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round">
          <polyline points="20 6 9 17 4 12"/>
        </svg>
      </div>
      <h1>Email подтверждён</h1>
      <p>Теперь вы сможете восстанавливать пароль кабинета через email.</p>
      <router-link to="/restaurant" class="auth-btn auth-btn--inline">Перейти в кабинет</router-link>
    </div>

    <!-- Ошибки -->
    <div v-else class="auth-state">
      <div class="auth-state-icon auth-state-icon--error">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round">
          <circle cx="12" cy="12" r="10"/>
          <line x1="15" y1="9" x2="9" y2="15"/>
          <line x1="9" y1="9" x2="15" y2="15"/>
        </svg>
      </div>
      <h1>Ссылка недействительна</h1>
      <p>{{ reasonText }}</p>
      <router-link to="/restaurant" class="auth-btn auth-btn--inline auth-btn--ghost">Перейти в кабинет</router-link>
    </div>
  </AuthShell>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import AuthShell from '@/components/auth/AuthShell.vue';

const route = useRoute();

const checking = ref(true);
const success = ref(false);
const reason = ref('');

const reasonText = computed(() => {
  switch (reason.value) {
    case 'expired': return 'Срок действия ссылки истёк (24 часа). Откройте кабинет и запросите подтверждение заново.';
    case 'used':    return 'Эта ссылка уже была использована. Возможно, email уже подтверждён.';
    case 'invalid':
    default:        return 'Ссылка повреждена, не существует или email был изменён. Попробуйте запросить новое подтверждение в кабинете.';
  }
});

onMounted(async () => {
  const token = String(route.query.token || '');
  if (!token) {
    reason.value = 'invalid';
    checking.value = false;
    return;
  }
  try {
    const apiBase = `${window.location.origin}/api/ro`;
    const res = await fetch(`${apiBase}/verify-email`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token }),
    });
    const data = await res.json().catch(() => ({}));
    if (data && data.valid) {
      success.value = true;
    } else {
      reason.value = data?.reason || 'invalid';
    }
  } catch (e) {
    reason.value = 'invalid';
  } finally {
    checking.value = false;
  }
});
</script>
