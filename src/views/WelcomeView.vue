<template>
  <div class="welcome-overlay" :class="{ 'welcome-visible': visible, 'welcome-fade': fading }">
    <div class="welcome-content">
      <div class="welcome-avatar">{{ initials }}</div>
      <div class="welcome-name">Добро пожаловать, {{ displayName }}!</div>
      <div class="welcome-entity">{{ entity }}</div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useUserStore } from '@/stores/userStore.js';

const router = useRouter();
const route = useRoute();
const userStore = useUserStore();

const visible = ref(false);
const fading = ref(false);

const displayName = computed(() => route.query.name || userStore.currentUser?.name || 'Пользователь');
const entity = computed(() => route.query.entity || '');
const initials = computed(() => {
  const name = displayName.value;
  return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase();
});

onMounted(() => {
  requestAnimationFrame(() => { visible.value = true; });
  setTimeout(() => {
    fading.value = true;
    setTimeout(() => {
      const redirect = route.query.redirect || '/';
      router.replace(redirect);
    }, 500);
  }, 1800);
});
</script>
