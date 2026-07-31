<template>
  <div v-if="canInstall" class="iab">
    <button class="iab-btn" @click="onClick">
      <span class="iab-icon" aria-hidden="true">⬇</span>
      {{ label }}
    </button>
    <ol v-if="showSteps" class="iab-steps">
      <li>Нажмите <b>Поделиться</b> внизу экрана</li>
      <li>Пролистайте вниз и выберите <b>«На экран „Домой“»</b></li>
      <li>Нажмите <b>«Добавить»</b> — появится иконка</li>
    </ol>
    <div class="iab-hint">{{ hint }}</div>
  </div>
</template>

<script setup>
/**
 * Кнопка «Установить приложение» для профиля кабинета и настроек портала.
 * Нужна тем, кто закрыл всплывающее предложение и захотел установить позже.
 */
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { useInstallPrompt } from '@/composables/useInstallPrompt.js';

const route = useRoute();
const { canInstall, canInstallNative, install } = useInstallPrompt();
const showSteps = ref(false);

const isRestaurantArea = computed(() => String(route.path || '').startsWith('/restaurant'));

const label = computed(() => {
  if (canInstallNative.value) return isRestaurantArea.value ? 'Установить кабинет на телефон' : 'Установить приложение';
  return 'Как установить на телефон';
});

const hint = computed(() => (isRestaurantArea.value
  ? 'Иконка на экране телефона, вход без браузера и уведомления о заказах.'
  : 'Портал откроется в отдельном окне и будет запускаться с рабочего стола.'));

async function onClick() {
  if (canInstallNative.value) { await install(); return; }
  showSteps.value = !showSteps.value;
}
</script>

<style scoped>
.iab { margin: 8px 0; }
.iab-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  border: 1px solid #E5DCCF;
  background: #FFF7EE;
  color: #502314;
  border-radius: 10px;
  padding: 10px 16px;
  font: inherit;
  font-weight: 600;
  font-size: 14px;
  cursor: pointer;
  transition: background .15s;
}
.iab-btn:hover { background: #FDEBD9; }
.iab-icon { font-size: 15px; }
.iab-steps {
  margin: 10px 0 0;
  padding-left: 20px;
  font-size: 13px;
  color: #4A3A32;
  line-height: 1.6;
}
.iab-hint { margin-top: 6px; font-size: 12px; color: #8A7A70; }
</style>
