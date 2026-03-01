<template>
  <Teleport to="body">
    <div class="toast-container">
      <TransitionGroup name="toast">
        <div v-for="toast in toastStore.toasts" :key="toast.id" class="toast" :class="`toast--${toast.type}`" @click="toastStore.remove(toast.id)">
          <div class="toast-icon">
            <span v-if="toast.type === 'success'">✓</span>
            <span v-else-if="toast.type === 'error'">✕</span>
            <span v-else-if="toast.type === 'warning'">!</span>
            <span v-else>i</span>
          </div>
          <div class="toast-body">
            <div class="toast-title">{{ toast.title }}</div>
            <div class="toast-message" v-if="toast.message">{{ toast.message }}</div>
          </div>
          <button class="toast-close" @click.stop="toastStore.remove(toast.id)">×</button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>
<script setup>
import { useToastStore } from '@/stores/toastStore.js';

const toastStore = useToastStore();
</script>
<style scoped>
.toast-container { position:fixed; top:16px; right:16px; z-index:9999999; display:flex; flex-direction:column; gap:8px; pointer-events:none; }
.toast { display:flex; align-items:flex-start; gap:10px; background:#fff; padding:12px 14px; border-radius:10px; min-width:280px; max-width:400px; pointer-events:all; cursor:pointer; box-shadow:0 4px 20px rgba(0,0,0,0.1),0 1px 3px rgba(0,0,0,0.06); border-left:4px solid #999; }
.toast--success { border-left-color:#34a853; } .toast--error { border-left-color:#ea4335; } .toast--warning { border-left-color:#f5a623; } .toast--info { border-left-color:#4285f4; }
.toast-icon { width:22px; height:22px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:800; color:#fff; flex-shrink:0; margin-top:1px; line-height:1; }
.toast--success .toast-icon { background:#34a853; } .toast--error .toast-icon { background:#ea4335; } .toast--warning .toast-icon { background:#f5a623; } .toast--info .toast-icon { background:#4285f4; }
.toast-body { flex:1; min-width:0; } .toast-title { font-weight:600; font-size:13px; color:#1a1a1a; line-height:1.3; } .toast-message { font-size:12px; color:#666; margin-top:2px; line-height:1.3; }
.toast-close { background:none; border:none; color:#bbb; font-size:14px; cursor:pointer; padding:0; line-height:1; flex-shrink:0; } .toast-close:hover { color:#666; }
.toast-enter-active,.toast-leave-active { transition:all 0.25s ease; } .toast-enter-from { opacity:0; transform:translateY(-12px); } .toast-leave-to { opacity:0; transform:translateX(40px); }
</style>
