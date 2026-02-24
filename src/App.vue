<template>
  <RouterView />
  <ToastContainer />
</template>

<script setup>
import { onMounted } from 'vue';
import { useUserStore } from '@/stores/userStore.js';
import ToastContainer from '@/components/ui/ToastContainer.vue';

const userStore = useUserStore();

onMounted(() => {
  userStore.restoreSession();
});
</script>
