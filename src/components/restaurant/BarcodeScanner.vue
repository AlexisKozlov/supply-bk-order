<template>
  <div class="bs">
    <div v-if="!cameraStarted && !error" class="bs-prestart">
      <div class="bs-prestart-icon">
        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
          <path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/>
          <circle cx="12" cy="13" r="4"/>
        </svg>
      </div>
      <p class="bs-prestart-text">Нажмите кнопку, чтобы включить камеру и навести на штрихкод товара.</p>
      <button class="bs-btn primary" @click="startCamera">Включить камеру</button>
      <button class="bs-btn ghost" @click="openManual">Ввести код вручную</button>
    </div>

    <div v-if="error" class="bs-error">
      <div class="bs-error-icon">!</div>
      <div class="bs-error-text">{{ error }}</div>
      <button class="bs-btn ghost" @click="resetError">Попробовать снова</button>
      <button class="bs-btn ghost" @click="openManual">Ввести код вручную</button>
    </div>

    <div v-show="cameraStarted && !error" class="bs-camera">
      <video ref="videoEl" class="bs-video" playsinline muted autoplay></video>
      <div class="bs-overlay">
        <div class="bs-hint">Наведите на штрихкод</div>
        <div class="bs-frame"></div>
        <div class="bs-controls-spacer"></div>
      </div>
      <div class="bs-controls">
        <button v-if="torchAvailable" class="bs-ctl" :class="{ active: torchOn }" @click="toggleTorch" title="Фонарик">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 2h6l-1 7h2l-7 13 1-9H8z"/></svg>
        </button>
        <button class="bs-ctl" @click="openManual" title="Ввести вручную">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M7 10h.01M11 10h.01M15 10h.01M7 14h10"/></svg>
        </button>
        <button class="bs-ctl danger" @click="stopCamera" title="Остановить">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="6" width="12" height="12"/></svg>
        </button>
      </div>
    </div>

    <div v-if="manualOpen" class="bs-modal-overlay" @click.self="manualOpen = false">
      <div class="bs-modal">
        <div class="bs-modal-title">Введите штрихкод вручную</div>
        <input ref="manualInputEl" v-model="manualCode" type="tel" inputmode="numeric" pattern="[0-9]*"
               placeholder="Например: 4607012345678"
               @keydown.enter="submitManual" />
        <div class="bs-modal-actions">
          <button class="bs-btn ghost" @click="manualOpen = false">Отмена</button>
          <button class="bs-btn primary" :disabled="!manualCode.trim()" @click="submitManual">Найти</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onBeforeUnmount, nextTick } from 'vue';
import { BrowserMultiFormatReader } from '@zxing/browser';

const emit = defineEmits(['detected']);

const videoEl = ref(null);
const manualInputEl = ref(null);
const cameraStarted = ref(false);
const error = ref('');
const torchOn = ref(false);
const torchAvailable = ref(false);
const manualOpen = ref(false);
const manualCode = ref('');

let reader = null;
let controls = null;
let currentTrack = null;
let lastCode = '';
let lastCodeAt = 0;

async function startCamera() {
  error.value = '';
  try {
    if (!navigator.mediaDevices?.getUserMedia) {
      throw new Error('Камера недоступна в этом браузере');
    }
    cameraStarted.value = true;
    await nextTick();

    reader = new BrowserMultiFormatReader();

    // Запрашиваем заднюю камеру
    const constraints = {
      video: {
        facingMode: { ideal: 'environment' },
        width: { ideal: 1280 },
        height: { ideal: 720 },
      },
    };

    controls = await reader.decodeFromConstraints(constraints, videoEl.value, (result) => {
      if (!result) return;
      const text = String(result.getText() || '').trim();
      if (!text) return;
      const now = Date.now();
      // Защита от дублей: один и тот же код не чаще раз в 2 сек
      if (text === lastCode && now - lastCodeAt < 2000) return;
      lastCode = text;
      lastCodeAt = now;
      beep();
      vibrate();
      emit('detected', text);
    });

    // Проверяем поддержку фонарика
    const stream = videoEl.value?.srcObject;
    if (stream && stream.getVideoTracks) {
      currentTrack = stream.getVideoTracks()[0];
      const caps = currentTrack?.getCapabilities?.();
      if (caps && 'torch' in caps) torchAvailable.value = true;
    }
  } catch (e) {
    cameraStarted.value = false;
    if (e.name === 'NotAllowedError' || e.name === 'PermissionDeniedError') {
      error.value = 'Доступ к камере запрещён. Разрешите доступ в настройках браузера.';
    } else if (e.name === 'NotFoundError') {
      error.value = 'Камера не найдена на устройстве.';
    } else {
      error.value = e.message || 'Не удалось включить камеру';
    }
  }
}

function stopCamera() {
  try { controls?.stop?.(); } catch {}
  controls = null;
  reader = null;
  if (videoEl.value?.srcObject) {
    videoEl.value.srcObject.getTracks().forEach(t => t.stop());
    videoEl.value.srcObject = null;
  }
  currentTrack = null;
  torchOn.value = false;
  torchAvailable.value = false;
  cameraStarted.value = false;
}

async function toggleTorch() {
  if (!currentTrack) return;
  try {
    const next = !torchOn.value;
    await currentTrack.applyConstraints({ advanced: [{ torch: next }] });
    torchOn.value = next;
  } catch {}
}

function openManual() {
  manualOpen.value = true;
  nextTick(() => manualInputEl.value?.focus());
}

function submitManual() {
  const code = manualCode.value.trim();
  if (!code) return;
  manualOpen.value = false;
  manualCode.value = '';
  emit('detected', code);
}

function resetError() {
  error.value = '';
  startCamera();
}

function resetLastCode() {
  lastCode = '';
  lastCodeAt = 0;
}
defineExpose({ stopCamera, startCamera, resetLastCode });

function beep() {
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.frequency.value = 880;
    osc.connect(gain); gain.connect(ctx.destination);
    gain.gain.setValueAtTime(0.15, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.15);
    osc.start();
    osc.stop(ctx.currentTime + 0.15);
    setTimeout(() => ctx.close(), 200);
  } catch {}
}

function vibrate() {
  try { navigator.vibrate?.(60); } catch {}
}

onBeforeUnmount(() => stopCamera());
</script>

<style scoped>
.bs { width: 100%; }

.bs-prestart, .bs-error {
  display: flex; flex-direction: column; align-items: center; gap: 12px;
  padding: 32px 16px; text-align: center; background: #fff;
  border: 1px solid #e5dcd2; border-radius: 12px;
}
.bs-prestart-icon { color: #502314; opacity: 0.6; }
.bs-prestart-text { font-size: 14px; color: #5a4a3a; max-width: 320px; line-height: 1.5; margin: 0; }

.bs-error-icon {
  width: 48px; height: 48px; border-radius: 50%; background: #fee; color: #c0392b;
  font-size: 28px; font-weight: 700; display: flex; align-items: center; justify-content: center;
}
.bs-error-text { font-size: 14px; color: #c0392b; max-width: 320px; }

.bs-btn {
  border: none; padding: 11px 22px; border-radius: 8px; font-size: 14px;
  font-weight: 600; cursor: pointer; min-width: 180px;
  transition: opacity 0.15s, transform 0.05s;
}
.bs-btn:active { transform: translateY(1px); }
.bs-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.bs-btn.primary { background: #E76F51; color: #fff; }
.bs-btn.primary:hover:not(:disabled) { background: #d85d3f; }
.bs-btn.ghost { background: transparent; color: #502314; border: 1px solid #d5c8b8; }
.bs-btn.ghost:hover { background: #f5efe7; }

.bs-camera {
  position: relative; width: 100%; aspect-ratio: 4 / 3;
  background: #000; border-radius: 12px; overflow: hidden;
  max-width: 600px; margin: 0 auto;
}
.bs-video { width: 100%; height: 100%; object-fit: cover; display: block; }

.bs-overlay {
  position: absolute; inset: 0; pointer-events: none;
  display: flex; flex-direction: column; align-items: center; justify-content: space-between;
  padding: 16px 0;
}
.bs-controls-spacer { height: 72px; flex-shrink: 0; }
.bs-frame {
  width: 70%; max-width: 320px; aspect-ratio: 16 / 9;
  border: 2px solid rgba(255,255,255,0.85); border-radius: 8px;
  box-shadow: 0 0 0 9999px rgba(0,0,0,0.35);
  position: relative;
}
.bs-frame::before, .bs-frame::after {
  content: ''; position: absolute; left: 0; right: 0;
  height: 2px; background: rgba(231, 111, 81, 0.9);
  animation: bs-scan 2.4s linear infinite;
}
@keyframes bs-scan {
  0%, 100% { top: 0; opacity: 0; }
  10% { opacity: 1; }
  50% { top: calc(100% - 2px); opacity: 1; }
  90% { opacity: 0; }
}
.bs-hint {
  color: rgba(255,255,255,0.95); font-size: 13px;
  background: rgba(0,0,0,0.45); padding: 6px 12px; border-radius: 16px;
}

.bs-controls {
  position: absolute; bottom: 14px; left: 0; right: 0;
  display: flex; justify-content: center; gap: 14px;
}
.bs-ctl {
  width: 44px; height: 44px; border-radius: 50%;
  background: rgba(255,255,255,0.92); color: #333;
  border: none; display: flex; align-items: center; justify-content: center;
  cursor: pointer; box-shadow: 0 2px 6px rgba(0,0,0,0.25);
}
.bs-ctl.active { background: #FFD54F; color: #6b4500; }
.bs-ctl.danger { background: rgba(231, 111, 81, 0.95); color: #fff; }
.bs-ctl:active { transform: scale(0.95); }

.bs-modal-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,0.45);
  display: flex; align-items: center; justify-content: center; z-index: 9000;
  padding: 16px;
}
.bs-modal {
  background: #fff; border-radius: 12px; padding: 20px;
  width: 100%; max-width: 360px; box-shadow: 0 12px 40px rgba(0,0,0,0.25);
}
.bs-modal-title { font-size: 15px; font-weight: 600; margin-bottom: 12px; color: #2b1a0e; }
.bs-modal input {
  width: 100%; padding: 10px 12px; font-size: 16px;
  border: 1px solid #d5c8b8; border-radius: 8px; outline: none;
  font-family: inherit;
}
.bs-modal input:focus { border-color: #E76F51; }
.bs-modal-actions {
  display: flex; gap: 8px; justify-content: flex-end; margin-top: 14px;
}
.bs-modal-actions .bs-btn { min-width: 100px; padding: 9px 16px; }
</style>
