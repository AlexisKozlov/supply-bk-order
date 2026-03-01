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
  <ToastContainer />
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { useUserStore } from '@/stores/userStore.js';
import { useRouter } from 'vue-router';
import ToastContainer from '@/components/ui/ToastContainer.vue';
import MaintenanceScreen from '@/components/MaintenanceScreen.vue';

const userStore = useUserStore();
const router = useRouter();

const showMaintenance = computed(() =>
  userStore.isAuthenticated && userStore.maintenanceMode && !userStore.isAdmin
);

function logout() {
  userStore.logout();
  router.push({ name: 'home' });
}

onMounted(async () => {
  userStore.restoreSession();
  if (userStore.isAuthenticated) {
    await userStore.checkMaintenance();
  }
});
</script>
