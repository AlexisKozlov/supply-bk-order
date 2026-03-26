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
import { useRouter, useRoute } from 'vue-router';
import ToastContainer from '@/components/ui/ToastContainer.vue';
import MaintenanceScreen from '@/components/MaintenanceScreen.vue';

const userStore = useUserStore();
const router = useRouter();
const route = useRoute();

// Публичные страницы, которые работают даже при тех. работах
const publicRoutes = ['search-cards', 'veg-order-form', 'stock-form', 'deficit-form'];

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
