<template>
  <div class="login-overlay">
    <div class="login-box">
      <div class="login-logo"><svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" width="48" height="48"><path d="M8 30c0-12 10-20 24-20s24 8 24 20H8z" fill="#F5A623"/><path d="M8 30c0-12 10-20 24-20s24 8 24 20H8z" fill="url(#lvt)"/><ellipse cx="20" cy="18" rx="2" ry="1.8" fill="#F9E4B7" opacity=".8"/><ellipse cx="30" cy="14" rx="1.8" ry="1.5" fill="#F9E4B7" opacity=".7"/><ellipse cx="40" cy="17" rx="2.2" ry="1.7" fill="#F9E4B7" opacity=".75"/><ellipse cx="26" cy="22" rx="1.5" ry="1.3" fill="#F9E4B7" opacity=".6"/><ellipse cx="36" cy="22" rx="1.6" ry="1.4" fill="#F9E4B7" opacity=".65"/><rect x="6" y="30" width="52" height="6" rx="3" fill="#4CAF50"/><path d="M6 33q3-3 6.5 0t6.5 0 6.5 0 6.5 0 6.5 0 6.5 0 6.5 0 6.5 0" stroke="#388E3C" stroke-width="1.5" fill="none"/><path d="M7 36h50l2 5H5l2-5z" fill="#FDBD10"/><path d="M7 41h50v1q-3 4-6 4H13q-3 0-6-4v-1z" fill="#FDBD10" opacity=".3"/><rect x="6" y="42" width="52" height="9" rx="2" fill="#6D3A1F"/><rect x="6" y="42" width="52" height="9" rx="2" fill="url(#lvpt)"/><rect x="7" y="51" width="50" height="7" rx="4" fill="#D4881A"/><rect x="7" y="51" width="50" height="7" rx="4" fill="url(#lvbb)"/><defs><linearGradient id="lvt" x1="32" y1="10" x2="32" y2="30" gradientUnits="userSpaceOnUse"><stop stop-color="#F5C547" offset="0"/><stop stop-color="#D4881A" offset="1"/></linearGradient><linearGradient id="lvpt" x1="32" y1="42" x2="32" y2="51" gradientUnits="userSpaceOnUse"><stop stop-color="#8B4513"/><stop stop-color="#4A2510" offset="1"/></linearGradient><linearGradient id="lvbb" x1="32" y1="51" x2="32" y2="58" gradientUnits="userSpaceOnUse"><stop stop-color="#E8A430"/><stop stop-color="#C47A15" offset="1"/></linearGradient></defs></svg></div>
      <h2>Портал закупок</h2>
      <p>Выберите пользователя и введите пароль</p>

      <select v-model="selectedUser" :disabled="loading">
        <option value="">Выберите имя...</option>
        <option v-for="u in userList" :key="u.name" :value="u.name">{{ u.name }}</option>
      </select>

      <input
        v-model="password"
        type="password"
        placeholder="Пароль"
        autocomplete="current-password"
        @keydown.enter="doLogin"
        :disabled="loading"
      />

      <div v-if="error" class="login-error">{{ error }}</div>

      <button class="btn primary" @click="doLogin" :disabled="loading || !selectedUser">
        {{ loading ? 'Вход...' : 'Войти' }}
      </button>

      <button class="btn" style="width:100%;margin-top:6px;" @click="$router.push('/')">← На главную</button>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useUserStore } from '@/stores/userStore.js';

const router = useRouter();
const route = useRoute();
const userStore = useUserStore();

const selectedUser = ref('');
const password = ref('');
const error = ref('');
const loading = ref(false);
const userList = ref([]);

onMounted(async () => {
  userList.value = await userStore.fetchUserList();
});

async function doLogin() {
  if (!selectedUser.value) return;
  error.value = '';
  loading.value = true;
  try {
    await userStore.login(selectedUser.value, password.value);
    // Mark as just logged in for welcome screen
    sessionStorage.setItem('bk_just_logged_in', '1');
    // Redirect to intended page or default
    const redirect = route.query.redirect;
    if (redirect && redirect !== '/login') {
      router.push(redirect);
    } else {
      router.push({ name: 'order' });
    }
  } catch (e) {
    error.value = e.message || 'Неверный пароль';
  } finally {
    loading.value = false;
  }
}
</script>
