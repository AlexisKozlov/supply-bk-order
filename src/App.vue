<template>
  <MaintenanceScreen v-if="showMaintenance"
    :message="userStore.maintenanceMessage"
    :end-time="userStore.maintenanceEndTime"
    :show-logout="true"
    @logout="logout"
  />
  <template v-else>
    <RouterView />
  </template>
  <Transition name="server-banner">
    <div v-if="serverDown" class="server-down-banner">
      <span class="server-down-icon">&#9888;</span>
      Сервер временно недоступен. Данные могут не загружаться. Пробуем восстановить связь...
    </div>
  </Transition>
  <Transition name="server-banner">
    <div v-if="restaurantSessionExpired" class="ro-session-banner" role="alert">
      <span class="server-down-icon">&#9888;</span>
      <span class="ro-session-text">Сессия завершена. Черновик заказа сохранён — войдите заново, чтобы продолжить.</span>
      <button class="ro-session-btn" @click="goToRestaurantLogin">Войти заново</button>
      <button class="ro-session-close" @click="restaurantSessionExpired = false" aria-label="Закрыть">×</button>
    </div>
  </Transition>
  <ToastContainer />
  <UpdatePrompt />
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useUserStore } from '@/stores/userStore.js';
import { useRouter, useRoute } from 'vue-router';
import { serverDown } from '@/lib/apiClient.js';
import ToastContainer from '@/components/ui/ToastContainer.vue';
import MaintenanceScreen from '@/components/MaintenanceScreen.vue';
import UpdatePrompt from '@/components/UpdatePrompt.vue';

const userStore = useUserStore();
const router = useRouter();
const route = useRoute();

// Публичные страницы, которые работают даже при тех. работах
const publicRoutes = ['search-cards', 'deficit-form'];

const showMaintenance = computed(() =>
  userStore.maintenanceMode && !userStore.isAdmin && !publicRoutes.includes(route.name)
);

function logout() {
  userStore.logout();
  router.push({ name: 'home' });
}

// Баннер «сессия завершена» для кабинета ресторана. Показывается, когда
// бэкенд вернул 401 на /api/ro/* — без авто-редиректа, чтобы пользователь
// успел сохранить данные перед повторным входом.
const restaurantSessionExpired = ref(false);
function onRestaurantSessionExpired() { restaurantSessionExpired.value = true; }
function goToRestaurantLogin() {
  restaurantSessionExpired.value = false;
  // Сохраняем текущий путь как redirect, чтобы после входа вернуться сюда же
  const cur = window.location.pathname + window.location.search;
  const redirect = cur && /^\/restaurant(\/|$)/.test(cur) ? cur : '/restaurant';
  window.location.href = '/restaurant/login?redirect=' + encodeURIComponent(redirect);
}

onMounted(async () => {
  window.addEventListener('bk:ro-session-expired', onRestaurantSessionExpired);
  await userStore.checkMaintenance();
});
onUnmounted(() => {
  window.removeEventListener('bk:ro-session-expired', onRestaurantSessionExpired);
});
</script>

<style scoped>
.server-down-banner {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 99999;
  background: #d32f2f;
  color: #fff;
  text-align: center;
  padding: 10px 16px;
  font-size: 14px;
  font-weight: 500;
  box-shadow: 0 2px 8px rgba(0,0,0,0.3);
}
.server-down-icon {
  margin-right: 6px;
  font-size: 16px;
}
.ro-session-banner {
  position: fixed;
  top: 0; left: 0; right: 0;
  z-index: 99998;
  background: #C16B4D;
  color: #fff;
  padding: 10px 14px;
  font-size: 14px;
  font-weight: 500;
  display: flex; align-items: center; justify-content: center; gap: 10px;
  flex-wrap: wrap;
  box-shadow: 0 2px 8px rgba(0,0,0,.25);
}
.ro-session-text { flex: 0 1 auto; }
.ro-session-btn {
  background: #fff; color: #C16B4D;
  border: none; border-radius: 8px;
  padding: 6px 14px;
  font: inherit; font-weight: 700;
  cursor: pointer;
  transition: background .15s;
}
.ro-session-btn:hover { background: #FFF1E0; }
.ro-session-close {
  background: transparent; color: #fff;
  border: none; cursor: pointer;
  padding: 4px 8px; font-size: 18px; line-height: 1;
  font-family: inherit;
  margin-left: 4px;
}
.ro-session-close:hover { opacity: .8; }
.server-banner-enter-active,
.server-banner-leave-active {
  transition: transform 0.3s ease, opacity 0.3s ease;
}
.server-banner-enter-from,
.server-banner-leave-to {
  transform: translateY(-100%);
  opacity: 0;
}
</style>
