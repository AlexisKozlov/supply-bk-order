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
  <ToastContainer />
  <UpdatePrompt />
</template>

<script setup>
import { computed, onMounted } from 'vue';
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

onMounted(async () => {
  await userStore.checkMaintenance();
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
