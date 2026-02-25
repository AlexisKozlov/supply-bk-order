<template>
  <div class="mnt">
    <div class="mnt-bg">
      <div class="mnt-orb mnt-orb-1"></div>
      <div class="mnt-orb mnt-orb-2"></div>
      <div class="mnt-orb mnt-orb-3"></div>
    </div>

    <div class="mnt-card">
      <div class="mnt-icon-wrap">
        <div class="mnt-icon-ring">
          <svg viewBox="0 0 64 64" width="40" height="40" fill="none">
            <path d="M32 8L32 36" stroke="#FDBD10" stroke-width="5" stroke-linecap="round">
              <animate attributeName="opacity" values="1;.4;1" dur="2s" repeatCount="indefinite"/>
            </path>
            <circle cx="32" cy="48" r="4" fill="#FDBD10">
              <animate attributeName="opacity" values="1;.4;1" dur="2s" repeatCount="indefinite"/>
            </circle>
          </svg>
        </div>
      </div>

      <h1 class="mnt-title">Технические работы</h1>

      <p class="mnt-message" v-if="userStore.maintenanceMessage">{{ userStore.maintenanceMessage }}</p>
      <p class="mnt-message" v-else>
        Система временно недоступна.<br>
        Мы проводим плановое обслуживание и скоро вернёмся.
      </p>

      <div class="mnt-divider"></div>

      <div class="mnt-info">
        <div class="mnt-info-item">
          <svg viewBox="0 0 20 20" width="16" height="16" fill="none"><circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.5"/><path d="M10 6v4l2.5 2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
          <span>Пожалуйста, попробуйте позже</span>
        </div>
        <div class="mnt-info-item">
          <svg viewBox="0 0 20 20" width="16" height="16" fill="none"><path d="M10 2a8 8 0 100 16 8 8 0 000-16z" stroke="currentColor" stroke-width="1.5"/><path d="M10 6v5M10 13.5v.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
          <span>При вопросах обратитесь к администратору</span>
        </div>
      </div>

      <button class="mnt-logout" @click="logout">
        <svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M7 17H4a2 2 0 01-2-2V5a2 2 0 012-2h3M14 14l4-4-4-4M8 10h10"/></svg>
        Выйти из аккаунта
      </button>
    </div>

    <div class="mnt-footer">
      Supply Department &middot; Портал закупок
    </div>
  </div>
</template>

<script setup>
import { useUserStore } from '@/stores/userStore.js';
import { useRouter } from 'vue-router';

const userStore = useUserStore();
const router = useRouter();

function logout() {
  userStore.logout();
  router.push({ name: 'home' });
}
</script>

<style scoped>
.mnt {
  position: fixed; inset: 0; z-index: 99999;
  background: #1A0E09;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  overflow: hidden;
}

/* ═══ Animated Background ═══ */
.mnt-bg { position: absolute; inset: 0; overflow: hidden; }
.mnt-orb {
  position: absolute; border-radius: 50%; filter: blur(80px);
  animation: orbFloat 8s ease-in-out infinite;
}
.mnt-orb-1 {
  width: 400px; height: 400px; top: -10%; left: -5%;
  background: radial-gradient(circle, rgba(214,39,0,.15) 0%, transparent 70%);
  animation-delay: 0s;
}
.mnt-orb-2 {
  width: 350px; height: 350px; bottom: -10%; right: -5%;
  background: radial-gradient(circle, rgba(245,166,35,.12) 0%, transparent 70%);
  animation-delay: -3s;
}
.mnt-orb-3 {
  width: 300px; height: 300px; top: 40%; left: 50%;
  background: radial-gradient(circle, rgba(253,189,16,.08) 0%, transparent 70%);
  animation-delay: -5s;
}
@keyframes orbFloat {
  0%, 100% { transform: translate(0, 0) scale(1); }
  25% { transform: translate(30px, -20px) scale(1.05); }
  50% { transform: translate(-20px, 30px) scale(0.95); }
  75% { transform: translate(15px, 15px) scale(1.02); }
}

/* ═══ Card ═══ */
.mnt-card {
  position: relative; z-index: 1;
  background: rgba(40, 22, 14, 0.8);
  backdrop-filter: blur(24px);
  border: 1px solid rgba(253, 189, 16, 0.12);
  border-radius: 24px;
  padding: 48px 40px 36px;
  max-width: 440px; width: 90%;
  text-align: center;
  box-shadow:
    0 0 0 1px rgba(0,0,0,.3),
    0 24px 80px rgba(0,0,0,.5),
    inset 0 1px 0 rgba(255,255,255,.04);
  animation: cardIn .6s ease;
}
@keyframes cardIn {
  from { opacity: 0; transform: translateY(20px) scale(.97); }
  to { opacity: 1; transform: none; }
}

/* ═══ Icon ═══ */
.mnt-icon-wrap { margin-bottom: 24px; }
.mnt-icon-ring {
  display: inline-flex; align-items: center; justify-content: center;
  width: 80px; height: 80px; border-radius: 50%;
  background: rgba(253, 189, 16, 0.06);
  border: 2px solid rgba(253, 189, 16, 0.2);
  animation: ringPulse 3s ease-in-out infinite;
}
@keyframes ringPulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(253,189,16,.1); }
  50% { box-shadow: 0 0 0 16px rgba(253,189,16,0); }
}

/* ═══ Text ═══ */
.mnt-title {
  font-family: 'Flame', 'Plus Jakarta Sans', sans-serif;
  font-size: 26px; font-weight: 700;
  color: #FDBD10;
  margin: 0 0 12px; letter-spacing: -.3px;
}
.mnt-message {
  font-size: 15px; line-height: 1.6;
  color: rgba(245, 230, 208, 0.65);
  margin: 0 0 24px;
}

/* ═══ Divider ═══ */
.mnt-divider {
  height: 1px; margin: 0 auto 20px;
  width: 60px;
  background: linear-gradient(90deg, transparent, rgba(253,189,16,.25), transparent);
}

/* ═══ Info ═══ */
.mnt-info { display: flex; flex-direction: column; gap: 10px; margin-bottom: 28px; }
.mnt-info-item {
  display: flex; align-items: center; justify-content: center; gap: 8px;
  font-size: 12px; color: rgba(245, 230, 208, 0.35);
}

/* ═══ Logout ═══ */
.mnt-logout {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 10px 28px; border-radius: 12px;
  border: 1.5px solid rgba(245, 230, 208, 0.15);
  background: rgba(245, 230, 208, 0.04);
  color: rgba(245, 230, 208, 0.5);
  font-size: 13px; font-weight: 600; font-family: inherit;
  cursor: pointer; transition: all .2s;
}
.mnt-logout:hover {
  border-color: rgba(253, 189, 16, 0.4);
  color: #FDBD10;
  background: rgba(253, 189, 16, 0.06);
}

/* ═══ Footer ═══ */
.mnt-footer {
  position: absolute; bottom: 24px;
  font-size: 11px; color: rgba(245, 230, 208, 0.15);
  letter-spacing: .5px;
}
</style>
