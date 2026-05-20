<template>
  <div class="rve-page">
    <div class="rve-content">
      <div class="rve-card">
        <!-- Проверка токена -->
        <div v-if="checking" class="rve-state">
          <div class="rve-spinner-big"></div>
          <p>Подтверждаем email…</p>
        </div>

        <!-- Успех -->
        <div v-else-if="success" class="rve-state">
          <div class="rve-icon rve-icon-success">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round">
              <polyline points="20 6 9 17 4 12"/>
            </svg>
          </div>
          <h1>Email подтверждён</h1>
          <p>Теперь вы сможете восстанавливать пароль кабинета через email.</p>
          <router-link to="/restaurant" class="rve-btn">Перейти в кабинет</router-link>
        </div>

        <!-- Ошибки -->
        <div v-else class="rve-state">
          <div class="rve-icon rve-icon-error">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round">
              <circle cx="12" cy="12" r="10"/>
              <line x1="15" y1="9" x2="9" y2="15"/>
              <line x1="9" y1="9" x2="15" y2="15"/>
            </svg>
          </div>
          <h1>Ссылка недействительна</h1>
          <p>{{ reasonText }}</p>
          <router-link to="/restaurant" class="rve-btn rve-btn-ghost">Перейти в кабинет</router-link>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';

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

<style scoped>
.rve-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #502314 0%, #7a3a1e 40%, #E76F51 100%);
  padding: 20px;
  font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
}

.rve-content { width: 100%; max-width: 440px; }

.rve-card {
  background: white;
  border-radius: 20px;
  padding: 40px 32px;
  width: 100%;
  box-shadow: 0 20px 60px rgba(80, 35, 20, 0.3);
  box-sizing: border-box;
  animation: rve-appear 0.4s ease;
}

@keyframes rve-appear {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}

.rve-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 14px;
}

.rve-spinner-big {
  width: 50px;
  height: 50px;
  border: 5px solid #f0ebe4;
  border-top-color: #E76F51;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

.rve-icon {
  width: 72px;
  height: 72px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 4px;
}
.rve-icon-success { background: #f0fdf4; }
.rve-icon-error   { background: #fef2f2; }

.rve-state h1 {
  margin: 0;
  font-size: 22px;
  font-weight: 700;
  color: #502314;
}

.rve-state p {
  margin: 0;
  font-size: 14px;
  color: #8b7355;
  line-height: 1.55;
  max-width: 320px;
}

.rve-btn {
  margin-top: 14px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 12px 22px;
  border-radius: 10px;
  background: linear-gradient(135deg, #E76F51 0%, #e83a1a 100%);
  color: white;
  font-weight: 700;
  font-size: 14px;
  text-decoration: none;
  box-shadow: 0 4px 12px rgba(231, 111, 81, 0.3);
  transition: all 0.2s;
}
.rve-btn:hover {
  background: linear-gradient(135deg, #b81e00 0%, #E76F51 100%);
  box-shadow: 0 6px 20px rgba(231, 111, 81, 0.4);
}

.rve-btn-ghost {
  background: transparent;
  color: #8b7355;
  border: 1.5px solid #e8e0d6;
  box-shadow: none;
}
.rve-btn-ghost:hover {
  background: #f5f0e8;
  color: #502314;
  box-shadow: none;
}
</style>
