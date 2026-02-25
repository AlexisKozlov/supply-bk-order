<template>
  <MaintenanceScreen v-if="showMaintenance" />
  <template v-else>
    <RouterView />
  </template>
  <ToastContainer />
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { useUserStore } from '@/stores/userStore.js';
import ToastContainer from '@/components/ui/ToastContainer.vue';
import MaintenanceScreen from '@/components/MaintenanceScreen.vue';

const userStore = useUserStore();

const showMaintenance = computed(() =>
  userStore.isAuthenticated && userStore.maintenanceMode && !userStore.isAdmin
);

onMounted(async () => {
  userStore.restoreSession();
  if (userStore.isAuthenticated) {
    await userStore.checkMaintenance();
  }
});
</script>
