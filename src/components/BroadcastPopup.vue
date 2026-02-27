<template>
  <Teleport to="body">
    <Transition name="bc">
      <div v-if="broadcast" class="bc-overlay" @click.self>
        <div class="bc-card">
          <div class="bc-icon-wrap">
            <div class="bc-icon-ring">
              <svg viewBox="0 0 64 64" width="36" height="36" fill="none">
                <path d="M32 12C32 12 14 20 14 34c0 4 0 6 2 8h32c2-2 2-4 2-8 0-14-18-22-18-22z" fill="none" stroke="#FDBD10" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                  <animate attributeName="opacity" values="1;.5;1" dur="2s" repeatCount="indefinite"/>
                </path>
                <rect x="26" y="42" width="12" height="4" rx="2" fill="#FDBD10" opacity=".6"/>
                <path d="M28 46a4 4 0 008 0" stroke="#FDBD10" stroke-width="2.5" stroke-linecap="round">
                  <animate attributeName="opacity" values="1;.5;1" dur="2s" repeatCount="indefinite"/>
                </path>
                <circle cx="32" cy="8" r="2" fill="#FDBD10">
                  <animate attributeName="r" values="2;3;2" dur="1.5s" repeatCount="indefinite"/>
                </circle>
              </svg>
            </div>
          </div>

          <h1 class="bc-title">{{ broadcast.title || 'Важное сообщение' }}</h1>

          <p class="bc-message">{{ broadcast.message }}</p>

          <div class="bc-divider"></div>

          <div class="bc-meta">
            <svg viewBox="0 0 20 20" width="14" height="14" fill="none"><circle cx="10" cy="7" r="4" stroke="currentColor" stroke-width="1.5"/><path d="M2 18c0-4 3.5-7 8-7s8 3 8 7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            <span>{{ broadcast.created_by }}</span>
            <span class="bc-meta-dot">&middot;</span>
            <svg viewBox="0 0 20 20" width="14" height="14" fill="none"><circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.5"/><path d="M10 6v4l2.5 2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            <span>{{ formatTime(broadcast.created_at) }}</span>
          </div>

          <button class="bc-btn" @click="dismiss">Понятно</button>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { computed } from 'vue';
import { useNotificationStore } from '@/stores/notificationStore.js';
import { formatMoscowDateTime } from '@/lib/utils.js';

const notificationStore = useNotificationStore();

const broadcast = computed(() => notificationStore.currentBroadcast);

function dismiss() {
  if (broadcast.value) {
    notificationStore.dismissBroadcast(broadcast.value.id);
  }
}

const formatTime = formatMoscowDateTime;
</script>

<style scoped>
.bc-overlay {
  position: fixed; inset: 0; z-index: 99998;
  background: rgba(10, 5, 3, 0.75);
  backdrop-filter: blur(8px);
  display: flex; align-items: center; justify-content: center;
}

.bc-card {
  position: relative;
  background: rgba(40, 22, 14, 0.9);
  backdrop-filter: blur(24px);
  border: 1px solid rgba(253, 189, 16, 0.15);
  border-radius: 24px;
  padding: 44px 40px 36px;
  max-width: 460px; width: 90%;
  text-align: center;
  box-shadow:
    0 0 0 1px rgba(0,0,0,.3),
    0 24px 80px rgba(0,0,0,.5),
    inset 0 1px 0 rgba(255,255,255,.04);
}

/* ═══ Icon ═══ */
.bc-icon-wrap { margin-bottom: 20px; }
.bc-icon-ring {
  display: inline-flex; align-items: center; justify-content: center;
  width: 76px; height: 76px; border-radius: 50%;
  background: rgba(253, 189, 16, 0.06);
  border: 2px solid rgba(253, 189, 16, 0.2);
  animation: bcRingPulse 3s ease-in-out infinite;
}
@keyframes bcRingPulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(253,189,16,.12); }
  50% { box-shadow: 0 0 0 14px rgba(253,189,16,0); }
}

/* ═══ Text ═══ */
.bc-title {
  font-family: 'Flame', 'Plus Jakarta Sans', sans-serif;
  font-size: 24px; font-weight: 700;
  color: #FDBD10;
  margin: 0 0 14px; letter-spacing: -.3px;
}

.bc-message {
  font-size: 15px; line-height: 1.7;
  color: rgba(245, 230, 208, 0.75);
  margin: 0 0 20px;
  white-space: pre-line;
}

/* ═══ Divider ═══ */
.bc-divider {
  height: 1px; margin: 0 auto 16px;
  width: 60px;
  background: linear-gradient(90deg, transparent, rgba(253,189,16,.25), transparent);
}

/* ═══ Meta ═══ */
.bc-meta {
  display: flex; align-items: center; justify-content: center; gap: 6px;
  font-size: 12px; color: rgba(245, 230, 208, 0.35);
  margin-bottom: 28px;
}
.bc-meta-dot { color: rgba(245, 230, 208, 0.2); }

/* ═══ Button ═══ */
.bc-btn {
  display: inline-flex; align-items: center; justify-content: center;
  padding: 12px 40px; border-radius: 14px;
  border: none;
  background: linear-gradient(135deg, #FDBD10, #E8A410);
  color: #1A0E09;
  font-size: 15px; font-weight: 700; font-family: 'Flame', 'Plus Jakarta Sans', sans-serif;
  cursor: pointer; transition: all .2s;
  box-shadow: 0 4px 20px rgba(253, 189, 16, 0.25);
}
.bc-btn:hover {
  transform: translateY(-1px);
  box-shadow: 0 6px 28px rgba(253, 189, 16, 0.35);
}
.bc-btn:active {
  transform: translateY(0);
}

/* ═══ Transitions ═══ */
.bc-enter-active { transition: all .35s ease; }
.bc-enter-active .bc-card { animation: bcCardIn .4s ease; }
.bc-leave-active { transition: all .25s ease; }
.bc-enter-from, .bc-leave-to { opacity: 0; }
@keyframes bcCardIn {
  from { opacity: 0; transform: translateY(20px) scale(.95); }
  to { opacity: 1; transform: none; }
}
</style>
