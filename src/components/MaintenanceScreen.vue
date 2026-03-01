<template>
  <div class="mnt">
    <!-- Animated flame background -->
    <div class="mnt-bg">
      <div class="mnt-flame mnt-flame-1"></div>
      <div class="mnt-flame mnt-flame-2"></div>
      <div class="mnt-flame mnt-flame-3"></div>
      <div class="mnt-flame mnt-flame-4"></div>
      <div class="mnt-flame mnt-flame-5"></div>
    </div>

    <!-- Animated grid -->
    <div class="mnt-grid"></div>

    <!-- Floating embers -->
    <div class="mnt-embers">
      <div v-for="n in 15" :key="n" class="mnt-ember" :style="emberStyle(n)"></div>
    </div>

    <!-- Glow pulse behind content -->
    <div class="mnt-glow"></div>

    <!-- Main content -->
    <div class="mnt-content">
      <!-- Title -->
      <h1 class="mnt-title">
        <span class="mnt-title-line" style="animation-delay:0s">Готовим</span>
        <span class="mnt-title-line mnt-title-accent" style="animation-delay:.15s">обновление</span>
      </h1>

      <!-- Message -->
      <p class="mnt-msg" v-if="message">{{ message }}</p>
      <p class="mnt-msg" v-else>Мы проводим технические работы,<br>чтобы сделать систему ещё лучше.</p>

      <!-- Timer countdown -->
      <div v-if="hasTimer" class="mnt-timer">
        <div class="mnt-timer-digits">
          <div class="mnt-digit" v-if="remaining.hours > 0">
            <span class="mnt-digit-num">{{ pad(remaining.hours) }}</span>
            <span class="mnt-digit-label">час</span>
          </div>
          <span v-if="remaining.hours > 0" class="mnt-digit-sep">:</span>
          <div class="mnt-digit">
            <span class="mnt-digit-num">{{ pad(remaining.minutes) }}</span>
            <span class="mnt-digit-label">мин</span>
          </div>
          <span class="mnt-digit-sep">:</span>
          <div class="mnt-digit">
            <span class="mnt-digit-num mnt-digit-sec">{{ pad(remaining.seconds) }}</span>
            <span class="mnt-digit-label">сек</span>
          </div>
        </div>

        <!-- Fire progress bar -->
        <div class="mnt-progress">
          <div class="mnt-progress-track">
            <div class="mnt-progress-fill" :style="{ width: progressPercent + '%' }">
              <div class="mnt-progress-fire"></div>
            </div>
          </div>
          <div class="mnt-progress-info">
            <span class="mnt-progress-pct">{{ progressPercent }}%</span>
            <span class="mnt-progress-time">{{ endTimeFormatted }}</span>
          </div>
        </div>
      </div>

      <!-- Indeterminate progress (no timer) -->
      <div v-else class="mnt-progress mnt-progress-no-timer">
        <div class="mnt-progress-track">
          <div class="mnt-progress-fill mnt-progress-pulse"></div>
        </div>
      </div>

      <!-- Status blink -->
      <p class="mnt-status">
        <span class="mnt-status-dot"></span>
        <span v-if="hasTimer">осталось {{ remainingText }}</span>
        <span v-else>скоро вернёмся</span>
      </p>

      <!-- Buttons -->
      <div class="mnt-actions">
        <button v-if="showLogout" class="mnt-btn" @click="$emit('logout')">
          <svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M7 17H4a2 2 0 01-2-2V5a2 2 0 012-2h3M14 14l4-4-4-4M8 10h10"/></svg>
          Выйти
        </button>
        <router-link v-if="showHomeLink" to="/" class="mnt-btn">
          <svg viewBox="0 0 20 20" width="14" height="14" fill="none"><path d="M3 10L10 3L17 10M5 8.5V16.5H8.5V12H11.5V16.5H15V8.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          На главную
        </router-link>
      </div>
    </div>

    <div class="mnt-footer">Supply Department &middot; Портал закупок</div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';

const props = defineProps({
  message: { type: String, default: '' },
  endTime: { type: String, default: null },
  showLogout: { type: Boolean, default: false },
  showHomeLink: { type: Boolean, default: false },
});

const emit = defineEmits(['logout', 'expired']);

const now = ref(Date.now());
let tickTimer = null;
let expired = false;

function emberStyle(n) {
  const seed = n * 7.3;
  return {
    left: `${20 + (seed * 13.7) % 60}%`,
    animationDelay: `${(n * 0.5) % 5}s`,
    animationDuration: `${3 + (n % 4) * 1.5}s`,
    width: `${2 + (n % 3) * 2}px`,
    height: `${2 + (n % 3) * 2}px`,
  };
}

const endTimestamp = computed(() => {
  if (!props.endTime) return null;
  const ts = new Date(props.endTime).getTime();
  return isNaN(ts) ? null : ts;
});

const hasTimer = computed(() => endTimestamp.value && endTimestamp.value > now.value);

const totalDuration = computed(() => {
  if (!endTimestamp.value) return 0;
  const left = endTimestamp.value - now.value;
  const durations = [15 * 60000, 30 * 60000, 60 * 60000, 120 * 60000, 180 * 60000];
  for (const d of durations) { if (left <= d) return d; }
  return left;
});

const remaining = computed(() => {
  if (!hasTimer.value) return { hours: 0, minutes: 0, seconds: 0 };
  const diff = Math.max(0, endTimestamp.value - now.value);
  const totalSec = Math.floor(diff / 1000);
  return {
    hours: Math.floor(totalSec / 3600),
    minutes: Math.floor((totalSec % 3600) / 60),
    seconds: totalSec % 60,
  };
});

const remainingText = computed(() => {
  const r = remaining.value;
  const parts = [];
  if (r.hours > 0) parts.push(`${r.hours} ч`);
  if (r.minutes > 0) parts.push(`${r.minutes} мин`);
  if (r.hours === 0 && r.seconds > 0) parts.push(`${r.seconds} сек`);
  return parts.join(' ') || 'менее минуты';
});

const progressPercent = computed(() => {
  if (!hasTimer.value || !totalDuration.value) return 0;
  const left = Math.max(0, endTimestamp.value - now.value);
  const elapsed = totalDuration.value - left;
  return Math.min(100, Math.round((elapsed / totalDuration.value) * 100));
});

const endTimeFormatted = computed(() => {
  if (!endTimestamp.value) return '';
  const d = new Date(endTimestamp.value);
  return `до ${d.getHours().toString().padStart(2, '0')}:${d.getMinutes().toString().padStart(2, '0')}`;
});

function pad(n) { return String(n).padStart(2, '0'); }

// Когда таймер истекает — перезагружаем страницу (сервер сам выключит техработы)
watch(hasTimer, (val, oldVal) => {
  if (oldVal === true && val === false && endTimestamp.value && !expired) {
    expired = true;
    setTimeout(() => { window.location.reload(); }, 2000);
  }
});

onMounted(() => { tickTimer = setInterval(() => { now.value = Date.now(); }, 1000); });
onUnmounted(() => { if (tickTimer) clearInterval(tickTimer); });
</script>

<style scoped>
/* ═══ Fullscreen container ═══ */
.mnt {
  position: fixed; inset: 0; z-index: 99999;
  background: #1A0A04;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  overflow: hidden;
}

/* ═══ Flame background — rising fire effect ═══ */
.mnt-bg { position: absolute; inset: 0; overflow: hidden; pointer-events: none; }
.mnt-flame {
  position: absolute; bottom: -20%;
  border-radius: 50% 50% 40% 40%;
  filter: blur(60px);
  opacity: .6;
}
.mnt-flame-1 {
  width: 600px; height: 500px; left: 10%;
  background: radial-gradient(ellipse, rgba(214,35,0,.5) 0%, rgba(214,35,0,0) 70%);
  animation: flameRise 5s ease-in-out infinite;
}
.mnt-flame-2 {
  width: 400px; height: 450px; right: 15%;
  background: radial-gradient(ellipse, rgba(253,189,16,.3) 0%, rgba(253,189,16,0) 70%);
  animation: flameRise 6s ease-in-out infinite 1s;
}
.mnt-flame-3 {
  width: 500px; height: 400px; left: 40%;
  background: radial-gradient(ellipse, rgba(245,166,35,.35) 0%, rgba(245,166,35,0) 70%);
  animation: flameRise 4.5s ease-in-out infinite .5s;
}
.mnt-flame-4 {
  width: 350px; height: 350px; left: -5%;
  background: radial-gradient(ellipse, rgba(214,35,0,.3) 0%, transparent 70%);
  animation: flameRise 7s ease-in-out infinite 2s;
}
.mnt-flame-5 {
  width: 300px; height: 380px; right: -5%;
  background: radial-gradient(ellipse, rgba(139,69,19,.4) 0%, transparent 70%);
  animation: flameRise 5.5s ease-in-out infinite 1.5s;
}
@keyframes flameRise {
  0%, 100% { transform: translateY(0) scaleY(1); opacity: .5; }
  50% { transform: translateY(-30%) scaleY(1.3); opacity: .8; }
}

/* ═══ Subtle moving grid ═══ */
.mnt-grid {
  position: absolute; inset: -60px;
  background-image:
    linear-gradient(rgba(253,189,16,0.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(253,189,16,0.03) 1px, transparent 1px);
  background-size: 50px 50px;
  animation: gridDrift 25s linear infinite;
  pointer-events: none;
}
@keyframes gridDrift {
  from { transform: translate(0, 0) rotate(0deg); }
  to { transform: translate(50px, 50px) rotate(1deg); }
}

/* ═══ Rising embers ═══ */
.mnt-embers { position: absolute; inset: 0; pointer-events: none; overflow: hidden; }
.mnt-ember {
  position: absolute; bottom: -10px;
  border-radius: 50%;
  background: #FDBD10;
  box-shadow: 0 0 6px 2px rgba(253,189,16,.5);
  animation: emberFloat linear infinite;
}
@keyframes emberFloat {
  0% { transform: translateY(0) scale(1); opacity: 0; }
  5% { opacity: .8; }
  80% { opacity: .6; }
  100% { transform: translateY(-100vh) scale(.2); opacity: 0; }
}

/* ═══ Center glow pulse ═══ */
.mnt-glow {
  position: absolute;
  width: 500px; height: 500px;
  top: 50%; left: 50%;
  transform: translate(-50%, -50%);
  border-radius: 50%;
  background: radial-gradient(circle, rgba(214,35,0,.12) 0%, transparent 60%);
  animation: glowPulse 4s ease-in-out infinite;
  pointer-events: none;
}
@keyframes glowPulse {
  0%, 100% { transform: translate(-50%, -50%) scale(1); opacity: .6; }
  50% { transform: translate(-50%, -50%) scale(1.3); opacity: 1; }
}

/* ═══ Content ═══ */
.mnt-content {
  position: relative; z-index: 2;
  text-align: center;
  max-width: 500px; width: 92%;
  animation: contentIn .8s cubic-bezier(.16, 1, .3, 1);
}
@keyframes contentIn {
  from { opacity: 0; transform: translateY(40px) scale(.92); }
  to { opacity: 1; transform: none; }
}

/* ═══ Title ═══ */
.mnt-title {
  font-family: 'Flame', sans-serif;
  margin: 0 0 16px;
  line-height: 1.1;
}
.mnt-title-line {
  display: block;
  font-size: 44px; font-weight: 700;
  color: #F5E6D0;
  text-transform: uppercase;
  letter-spacing: 6px;
  animation: titleSlide .8s cubic-bezier(.16, 1, .3, 1) both;
}
.mnt-title-accent {
  font-size: 58px;
  background: linear-gradient(180deg, #FDBD10 20%, #D62300 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  letter-spacing: 3px;
  filter: drop-shadow(0 0 20px rgba(253,189,16,.3));
}
@keyframes titleSlide {
  from { opacity: 0; transform: translateY(24px); }
  to { opacity: 1; transform: none; }
}

/* ═══ Message ═══ */
.mnt-msg {
  font-size: 16px; line-height: 1.7;
  color: rgba(245,230,208,.55);
  margin: 0 auto 28px;
  max-width: 380px;
  animation: fadeUp 1s .3s both;
}
@keyframes fadeUp {
  from { opacity: 0; transform: translateY(12px); }
  to { opacity: 1; transform: none; }
}

/* ═══ Timer ═══ */
.mnt-timer {
  margin-bottom: 20px;
  animation: fadeUp 1s .4s both;
}
.mnt-timer-digits {
  display: flex; align-items: center; justify-content: center; gap: 6px;
  margin-bottom: 20px;
}
.mnt-digit { display: flex; flex-direction: column; align-items: center; gap: 4px; }
.mnt-digit-num {
  font-family: 'Flame', monospace;
  font-size: 48px; font-weight: 700; line-height: 1;
  color: #FDBD10;
  text-shadow: 0 0 20px rgba(253,189,16,.4), 0 0 40px rgba(253,189,16,.15);
  min-width: 64px;
  background: rgba(253,189,16,.05);
  border: 1px solid rgba(253,189,16,.15);
  border-radius: 14px;
  padding: 12px 8px;
  position: relative;
  overflow: hidden;
}
.mnt-digit-num::after {
  content: '';
  position: absolute; inset: 0;
  background: linear-gradient(180deg, rgba(253,189,16,.06) 0%, transparent 50%);
  pointer-events: none;
}
.mnt-digit-sec {
  animation: secPulse 1s ease-in-out infinite;
}
@keyframes secPulse {
  0%, 100% { text-shadow: 0 0 20px rgba(253,189,16,.4), 0 0 40px rgba(253,189,16,.15); }
  50% { text-shadow: 0 0 30px rgba(253,189,16,.7), 0 0 60px rgba(253,189,16,.3); }
}
.mnt-digit-label {
  font-size: 10px; font-weight: 700;
  color: rgba(245,230,208,.3);
  text-transform: uppercase; letter-spacing: 1.5px;
}
.mnt-digit-sep {
  font-size: 36px; font-weight: 700;
  color: rgba(253,189,16,.3);
  padding-bottom: 22px;
  animation: sepBlink 1s step-end infinite;
}
@keyframes sepBlink {
  0%, 100% { opacity: 1; }
  50% { opacity: .2; }
}

/* ═══ Progress bar with fire edge ═══ */
.mnt-progress {
  margin-bottom: 16px;
  animation: fadeUp 1s .5s both;
}
.mnt-progress-no-timer { animation: fadeUp 1s .4s both; }
.mnt-progress-track {
  height: 8px; border-radius: 4px;
  background: rgba(245,230,208,.06);
  overflow: hidden;
  position: relative;
  border: 1px solid rgba(253,189,16,.06);
}
.mnt-progress-fill {
  height: 100%; border-radius: 4px;
  background: linear-gradient(90deg, #D62300, #F5A623, #FDBD10);
  transition: width 1s linear;
  position: relative;
}
.mnt-progress-fire {
  position: absolute; right: -4px; top: -6px;
  width: 16px; height: 20px;
  background: radial-gradient(ellipse at bottom, #FDBD10 0%, rgba(214,35,0,.8) 40%, transparent 70%);
  border-radius: 50% 50% 30% 30%;
  filter: blur(2px);
  animation: fireFlicker .3s ease-in-out infinite alternate;
}
@keyframes fireFlicker {
  from { transform: scaleY(1) scaleX(.9); opacity: .8; }
  to { transform: scaleY(1.2) scaleX(1.1); opacity: 1; }
}
.mnt-progress-pulse {
  width: 35%;
  background: linear-gradient(90deg, transparent, #D62300, #FDBD10, #D62300, transparent);
  animation: progressPulse 2.5s ease-in-out infinite;
}
@keyframes progressPulse {
  0% { transform: translateX(-150%); }
  100% { transform: translateX(400%); }
}
.mnt-progress-info {
  display: flex; justify-content: space-between; margin-top: 8px;
  font-size: 12px; font-weight: 600;
}
.mnt-progress-pct {
  color: #FDBD10;
  text-shadow: 0 0 8px rgba(253,189,16,.3);
}
.mnt-progress-time { color: rgba(245,230,208,.3); }

/* ═══ Status line ═══ */
.mnt-status {
  display: flex; align-items: center; justify-content: center; gap: 8px;
  font-size: 12px; font-weight: 600;
  text-transform: uppercase; letter-spacing: 3px;
  color: rgba(253,189,16,.5);
  margin: 0 0 28px;
  animation: fadeUp 1s .6s both;
}
.mnt-status-dot {
  width: 6px; height: 6px; border-radius: 50%;
  background: #FDBD10;
  box-shadow: 0 0 8px rgba(253,189,16,.6);
  animation: dotPulse 2s ease-in-out infinite;
}
@keyframes dotPulse {
  0%, 100% { opacity: 1; box-shadow: 0 0 8px rgba(253,189,16,.6); }
  50% { opacity: .3; box-shadow: 0 0 4px rgba(253,189,16,.2); }
}

/* ═══ Action buttons ═══ */
.mnt-actions {
  display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;
  animation: fadeUp 1s .7s both;
}
.mnt-btn {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 11px 28px; border-radius: 50px;
  border: 1.5px solid rgba(253,189,16,.15);
  background: rgba(253,189,16,.04);
  color: rgba(245,230,208,.5);
  font-size: 13px; font-weight: 600; font-family: inherit;
  cursor: pointer; transition: all .3s cubic-bezier(.16, 1, .3, 1);
  text-decoration: none;
}
.mnt-btn:hover {
  border-color: #FDBD10;
  color: #FDBD10;
  background: rgba(253,189,16,.08);
  transform: translateY(-2px);
  box-shadow: 0 8px 30px rgba(253,189,16,.15);
}

/* ═══ Footer ═══ */
.mnt-footer {
  position: absolute; bottom: 20px;
  font-size: 11px; color: rgba(245,230,208,.12);
  letter-spacing: .5px;
}

/* ═══ Mobile ═══ */
@media (max-width: 480px) {
  .mnt-icon svg { width: 90px; height: 90px; }
  .mnt-title-line { font-size: 30px; letter-spacing: 3px; }
  .mnt-title-accent { font-size: 40px; }
  .mnt-digit-num { font-size: 36px; min-width: 50px; padding: 10px 6px; }
  .mnt-digit-sep { font-size: 28px; }
  .mnt-msg { font-size: 14px; }
  .mnt-flame { filter: blur(40px); }
}
</style>
