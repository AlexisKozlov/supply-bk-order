<template>
  <div class="goodbye-page">
    <div class="goodbye-bg"></div>
    <div class="goodbye-container">
      <div class="goodbye-icon"><BkIcon name="wave" size="sm"/></div>
      <h1 class="goodbye-title">До свидания!</h1>
      <p class="goodbye-text">Вы вышли из аккаунта. Надеемся увидеть вас снова!</p>
      <div class="goodbye-actions">
        <button class="home-btn home-btn-primary" @click="$router.push('/')">На главную</button>
        <button class="home-btn home-btn-secondary" @click="$router.push('/login')">Войти снова</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import BkIcon from '@/components/ui/BkIcon.vue';
</script>

<style scoped>
.goodbye-page {
  min-height: 100vh;
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40px 20px;
}

.goodbye-bg {
  position: fixed;
  inset: 0;
  background: linear-gradient(135deg, #2C1810 0%, #502314 40%, #8B5E34 100%);
  z-index: 0;
}

.goodbye-container {
  position: relative;
  z-index: 1;
  text-align: center;
  max-width: 420px;
}

.goodbye-icon {
  font-size: 64px;
  margin-bottom: 16px;
  animation: goodbyeWave 1.5s ease-in-out infinite;
}

@keyframes goodbyeWave {
  0%, 100% { transform: rotate(0deg); }
  25% { transform: rotate(20deg); }
  75% { transform: rotate(-10deg); }
}

.goodbye-title {
  font-size: 32px;
  font-weight: 800;
  color: white;
  margin-bottom: 8px;
}

.goodbye-text {
  font-size: 15px;
  color: rgba(255, 255, 255, 0.55);
  margin-bottom: 32px;
  line-height: 1.5;
}

.goodbye-actions {
  display: flex;
  gap: 12px;
  justify-content: center;
}

.home-btn {
  padding: 10px 28px;
  border-radius: 10px;
  font-size: 14px;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
  border: none;
  transition: all 0.15s;
}

.home-btn-primary {
  background: linear-gradient(135deg, #D62300, #FF8732);
  color: white;
  box-shadow: 0 4px 16px rgba(214, 35, 0, 0.3);
}

.home-btn-primary:hover {
  box-shadow: 0 6px 24px rgba(214, 35, 0, 0.4);
  transform: translateY(-1px);
}

.home-btn-secondary {
  background: rgba(255, 255, 255, 0.08);
  color: rgba(255, 255, 255, 0.6);
  border: 1px solid rgba(255, 255, 255, 0.12);
}

.home-btn-secondary:hover {
  background: rgba(255, 255, 255, 0.14);
  color: white;
}
</style>
