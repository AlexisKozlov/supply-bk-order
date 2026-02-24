<template>
  <div class="login-overlay">
    <div class="login-box">
      <div class="login-logo">🍔</div>
      <h2>Калькулятор заказа</h2>
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
