<template>
  <div class="portal">
    <!-- Animated background canvas -->
    <canvas ref="bgCanvas" class="portal-canvas"></canvas>

    <!-- Header -->
    <header class="p-header">
      <div class="p-header-left">
        <div class="p-logo"><SupplyLogo :size="36"/></div>
        <div class="p-brand">
          <h1>Supply Department</h1>
          <small>Портал закупок</small>
        </div>
      </div>
      <div class="p-header-right">
        <span class="p-entity">{{ orderStore.settings.legalEntity }}</span>
        <div v-if="userStore.isAuthenticated" class="p-user" @click="showUserMenu = !showUserMenu">
          <div class="p-av">{{ userInitials }}</div>
          <span class="p-uname">{{ userStore.currentUser.name }}</span>
        </div>
        <button v-else class="p-login-btn" @click="openLogin">Войти</button>

        <!-- User dropdown -->
        <div v-if="showUserMenu" class="p-user-dropdown">
          <button @click="showUserMenu = false; showLogoutConfirm = true"><BkIcon name="redo" size="sm"/> Выйти из аккаунта</button>
        </div>
      </div>
    </header>

    <!-- Maintenance banner -->
    <div v-if="isMaintenance" class="p-maint-banner">
      <svg viewBox="0 0 20 20" width="16" height="16" fill="none"><circle cx="10" cy="10" r="8" stroke="#FDBD10" stroke-width="1.5"/><path d="M10 6v5" stroke="#FDBD10" stroke-width="2" stroke-linecap="round"/><circle cx="10" cy="13.5" r="1" fill="#FDBD10"/></svg>
      <span>{{ maintenanceBannerText }}</span>
      <span v-if="maintenanceCountdown" class="p-maint-timer"> &middot; {{ maintenanceCountdown }}</span>
    </div>

    <!-- Body — centered content -->
    <div class="p-body">
      <!-- Greeting -->
      <div class="p-greeting">
        <h2 v-if="userStore.isAuthenticated">Добрый день, <em>{{ firstName }}</em></h2>
        <h2 v-else>Портал <em>закупок</em></h2>
        <p>Управление поставками · Supply Department</p>
      </div>

      <!-- Dock -->
      <div class="p-dock">
        <div
          v-for="(m, i) in dockModules" :key="m.key"
          class="p-dock-slot"
          @mouseenter="hoveredIdx = i"
          @mouseleave="hoveredIdx = null"
          @click="m.dim ? stubModule(m.name) : m.public ? goPublic(m.key) : goTo(m.key, m.query)"
        >
          <div
            class="p-dock-item"
            :class="{
              'p-dock-dim': m.dim,
              'p-dock-hovered': hoveredIdx === i,
              'p-dock-neighbor': hoveredIdx !== null && hoveredIdx !== i && Math.abs(hoveredIdx - i) === 1,
            }"
          >
            <div class="p-dock-icon" v-html="m.svg"></div>
            <span class="p-dock-label">{{ m.name }}</span>
            <span v-if="m.dim" class="p-dock-tag">скоро</span>
          </div>
        </div>
      </div>

      <!-- Quick actions -->
      <div class="p-actions">
        <button class="p-btn p-btn-primary" @click="goTo('order')">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
          Новый заказ
        </button>
      </div>
    </div>

    <!-- Footer -->
    <footer class="p-footer">
      <span class="p-footer-ver">Supply Department Portal v1.0.0</span>
      <button v-if="userStore.isAuthenticated" class="p-footer-btn" @click="showLogoutConfirm = true">Выйти из аккаунта</button>
    </footer>

    <!-- ═══ LOGIN MODAL ═══ -->
    <Teleport to="body">
      <div v-if="showLoginModal" class="login-overlay" @click.self="showLoginModal = false">
        <div class="login-card">
          <div class="login-left">
            <div class="login-brand">
              <span class="login-brand-icon"><SupplyLogo :size="40"/></span>
              <div class="login-brand-title">Supply Department</div>
              <div class="login-brand-sub">Портал закупок</div>
            </div>
          </div>
          <div class="login-right">
            <button class="login-close" @click="showLoginModal = false"><BkIcon name="close" size="xs"/></button>
            <div class="login-form">
              <div class="login-form-title">Вход в систему</div>
              <div class="login-form-sub">Введите email и пароль</div>
              <div class="login-field">
                <label>Email</label>
                <input v-model="selectedUser" type="email" placeholder="Введите email" autocomplete="email" :disabled="loginLoading" @keydown.enter="$refs.passwordInput?.focus()" />
              </div>
              <div class="login-field">
                <label>Пароль</label>
                <input v-model="password" type="password" placeholder="Введите пароль" autocomplete="current-password" @keydown.enter="doLogin" :disabled="loginLoading" />
              </div>
              <div v-if="loginError" class="login-error">{{ loginError }}</div>
              <button class="login-submit" @click="doLogin" :disabled="loginLoading || !selectedUser">
                {{ loginLoading ? 'Вход...' : 'Войти' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Supply Loader -->
    <Teleport to="body">
      <Transition name="supply-loader">
        <div v-if="showLoader" class="supply-loader-overlay">
          <div class="supply-loader-icon">
            <svg viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" fill="none" width="80" height="80">
              <circle cx="16" cy="16" r="10" fill="#D62300" class="ldr-c ldr-c1"/>
              <circle cx="32" cy="16" r="10" fill="#F5A623" class="ldr-c ldr-c2"/>
              <circle cx="16" cy="32" r="10" fill="#FF8733" class="ldr-c ldr-c3"/>
              <circle cx="32" cy="32" r="10" fill="#FFD54F" class="ldr-c ldr-c4"/>
              <circle cx="24" cy="24" r="8.5" fill="#502314"/>
              <text x="24" y="29" text-anchor="middle" fill="white" font-size="14" font-weight="900" font-family="Arial, sans-serif">S</text>
            </svg>
          </div>
          <div class="supply-loader-text">{{ loaderText }}</div>
        </div>
      </Transition>
    </Teleport>

    <!-- Logout confirm -->
    <Teleport to="body">
      <div v-if="showLogoutConfirm" class="modal" @click.self="showLogoutConfirm = false">
        <div class="modal-box" style="max-width:380px;">
          <h3 style="margin-bottom:8px;">Выйти из аккаунта?</h3>
          <p style="color:var(--text-muted);font-size:14px;margin-bottom:20px;">Вы уверены, что хотите выйти?</p>
          <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button class="btn" @click="showLogoutConfirm = false">Отмена</button>
            <button class="btn primary" style="background:var(--error);" @click="confirmLogout">Выйти</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted, onBeforeUnmount } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useUserStore } from '@/stores/userStore.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useToastStore } from '@/stores/toastStore.js';
import { db } from '@/lib/apiClient.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import SupplyLogo from '@/components/ui/SupplyLogo.vue';


const router = useRouter();
const route = useRoute();
const userStore = useUserStore();
const orderStore = useOrderStore();
const toast = useToastStore();

const showLogoutConfirm = ref(false);
const showLoginModal = ref(false);
const showUserMenu = ref(false);
const loginRedirectTo = ref(null);
const hoveredIdx = ref(null);
const bgCanvas = ref(null);
let bgRaf = null;
let bgResize = null;
let _loaderTimers = [];

const selectedUser = ref('');
const password = ref('');
const loginError = ref('');
const loginLoading = ref(false);
const isMaintenance = ref(false);
const maintenanceBannerText = ref('');
const maintenanceEndTimeRaw = ref(null);
const maintenanceNow = ref(Date.now());
let maintenanceTickTimer = null;

const maintenanceCountdown = computed(() => {
  if (!maintenanceEndTimeRaw.value) return '';
  const end = new Date(maintenanceEndTimeRaw.value).getTime();
  if (isNaN(end) || end <= maintenanceNow.value) return '';
  const diff = Math.max(0, end - maintenanceNow.value);
  const totalSec = Math.floor(diff / 1000);
  const h = Math.floor(totalSec / 3600);
  const m = Math.floor((totalSec % 3600) / 60);
  const s = totalSec % 60;
  if (h > 0) return `${h}ч ${m}м`;
  if (m > 0) return `${m}м ${s}с`;
  return `${s}с`;
});

async function checkMaintenanceForHome() {
  try {
    const { data } = await db.rpc('check_maintenance');
    isMaintenance.value = !!data?.maintenance_mode;
    maintenanceBannerText.value = data?.maintenance_message || 'Ведутся технические работы. Вход может быть ограничен.';
    maintenanceEndTimeRaw.value = data?.maintenance_end_time || null;
    // Запускаем тик для обратного отсчёта
    if (data?.maintenance_end_time && !maintenanceTickTimer) {
      maintenanceTickTimer = setInterval(() => { maintenanceNow.value = Date.now(); }, 1000);
    }
  } catch { /* noop */ }
}

const svgIcons = {
  order: `<svg viewBox="0 0 32 32" fill="none"><rect x="4" y="6" width="24" height="22" rx="3" stroke="#502314" stroke-width="2"/><path d="M4 12h24" stroke="#502314" stroke-width="2"/><path d="M12 2v6M20 2v6" stroke="#502314" stroke-width="2" stroke-linecap="round"/><rect x="9" y="16" width="6" height="4" rx="1" fill="#D62700" opacity=".8"/><rect x="17" y="16" width="6" height="4" rx="1" fill="#FF8733" opacity=".6"/><rect x="9" y="22" width="6" height="3" rx="1" fill="#FF8733" opacity=".4"/></svg>`,
  planning: `<svg viewBox="0 0 32 32" fill="none"><line x1="4" y1="6" x2="4" y2="26" stroke="#502314" stroke-width="1.5" stroke-linecap="round" opacity=".3"/><rect x="6" y="7" width="14" height="4" rx="2" fill="#D62700"/><rect x="6" y="13" width="20" height="4" rx="2" fill="#FF8733"/><rect x="6" y="19" width="11" height="4" rx="2" fill="#D62700" opacity=".6"/><rect x="6" y="25" width="17" height="4" rx="2" fill="#D4C4B0"/><circle cx="20" cy="9" r="1.2" fill="#fff" opacity=".5"/><circle cx="26" cy="15" r="1.2" fill="#fff" opacity=".5"/></svg>`,
  calendar: `<svg viewBox="0 0 32 32" fill="none"><rect x="3" y="6" width="26" height="22" rx="3" stroke="#502314" stroke-width="2"/><path d="M3 13h26" stroke="#502314" stroke-width="1.5"/><path d="M10 3v5M22 3v5" stroke="#502314" stroke-width="2" stroke-linecap="round"/><circle cx="10" cy="19" r="2" fill="#D62700"/><circle cx="16" cy="19" r="2" fill="#FF8733" opacity=".6"/><circle cx="22" cy="19" r="2" fill="#D4C4B0"/><circle cx="10" cy="24" r="1.5" fill="#D4C4B0"/><circle cx="16" cy="24" r="1.5" fill="#D62700" opacity=".5"/></svg>`,
  analytics: `<svg viewBox="0 0 32 32" fill="none"><path d="M4 26L10 18L16 21L22 11L28 6" stroke="#D62700" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 26L10 18L16 21L22 11L28 6" stroke="#D62700" stroke-width="2.5" stroke-linecap="round" opacity=".15" transform="translate(0,2)"/><circle cx="10" cy="18" r="2.5" fill="#fff" stroke="#D62700" stroke-width="1.5"/><circle cx="22" cy="11" r="2.5" fill="#fff" stroke="#FF8733" stroke-width="1.5"/><circle cx="28" cy="6" r="2.5" fill="#fff" stroke="#D62700" stroke-width="1.5"/></svg>`,
  history: `<svg viewBox="0 0 32 32" fill="none"><rect x="5" y="3" width="22" height="26" rx="3" stroke="#502314" stroke-width="2"/><line x1="10" y1="10" x2="22" y2="10" stroke="#502314" stroke-width="1.5" stroke-linecap="round" opacity=".4"/><line x1="10" y1="15" x2="22" y2="15" stroke="#502314" stroke-width="1.5" stroke-linecap="round" opacity=".3"/><line x1="10" y1="20" x2="18" y2="20" stroke="#502314" stroke-width="1.5" stroke-linecap="round" opacity=".2"/><rect x="8" y="8" width="3" height="3" rx=".5" fill="#D62700" opacity=".7"/><rect x="8" y="13" width="3" height="3" rx=".5" fill="#FF8733" opacity=".5"/><rect x="8" y="18" width="3" height="3" rx=".5" fill="#D4C4B0" opacity=".5"/></svg>`,
  database: `<svg viewBox="0 0 32 32" fill="none"><ellipse cx="16" cy="8" rx="11" ry="4" stroke="#502314" stroke-width="2"/><path d="M5 8v7c0 2.2 4.9 4 11 4s11-1.8 11-4V8" stroke="#502314" stroke-width="2"/><path d="M5 15v7c0 2.2 4.9 4 11 4s11-1.8 11-4v-7" stroke="#502314" stroke-width="2"/><ellipse cx="16" cy="15" rx="11" ry="4" fill="#D62700" opacity=".1"/><ellipse cx="16" cy="22" rx="11" ry="4" fill="#FF8733" opacity=".1"/></svg>`,
  search: `<svg viewBox="0 0 32 32" fill="none"><circle cx="14" cy="14" r="9" stroke="#502314" stroke-width="2.5"/><line x1="21" y1="21" x2="28" y2="28" stroke="#502314" stroke-width="2.5" stroke-linecap="round"/><circle cx="14" cy="14" r="4" fill="#D62700" opacity=".12"/></svg>`,
  delivery: `<svg viewBox="0 0 32 32" fill="none"><rect x="2" y="10" width="18" height="12" rx="2" stroke="#502314" stroke-width="2"/><path d="M20 14h5l4 5v3h-9v-8z" stroke="#502314" stroke-width="2" stroke-linejoin="round"/><circle cx="9" cy="24" r="2.5" fill="#D62700" stroke="#502314" stroke-width="1.5"/><circle cx="24" cy="24" r="2.5" fill="#FF8733" stroke="#502314" stroke-width="1.5"/><rect x="5" y="13" width="6" height="3" rx="1" fill="#D62700" opacity=".2"/><rect x="5" y="17" width="4" height="2" rx=".5" fill="#FF8733" opacity=".2"/></svg>`,
  analysis: `<svg viewBox="0 0 32 32" fill="none"><rect x="4" y="4" width="24" height="24" rx="3" stroke="#502314" stroke-width="2"/><path d="M9 20l4-5 4 3 6-8" stroke="#D62700" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9" cy="20" r="1.5" fill="#D62700"/><circle cx="13" cy="15" r="1.5" fill="#FF8733"/><circle cx="17" cy="18" r="1.5" fill="#FF8733"/><circle cx="23" cy="10" r="1.5" fill="#D62700"/><rect x="8" y="23" width="3" height="3" rx=".5" fill="#502314" opacity=".2"/><rect x="13" y="22" width="3" height="4" rx=".5" fill="#502314" opacity=".25"/><rect x="18" y="21" width="3" height="5" rx=".5" fill="#502314" opacity=".3"/><rect x="23" y="23" width="3" height="3" rx=".5" fill="#502314" opacity=".15"/></svg>`,
};

const dockModules = [
  { key: 'order',     name: 'Новый заказ',     svg: svgIcons.order },
  { key: 'planning',  name: 'Планирование',    svg: svgIcons.planning },
  { key: 'calendar',  name: 'Календарь',       svg: svgIcons.calendar },
  { key: 'analytics', name: 'Аналитика',       svg: svgIcons.analytics },
  { key: 'history',   name: 'История',         svg: svgIcons.history },
  { key: 'database',  name: 'База данных',     svg: svgIcons.database },
  { key: 'delivery-schedule', name: 'Доставки в рестораны', svg: svgIcons.delivery },
  { key: 'search',    name: 'Поиск карточек',  svg: svgIcons.search, public: true },
  { key: 'analysis',  name: 'Анализ',          svg: svgIcons.analysis },
];

const userInitials = computed(() => {
  const name = userStore.currentUser?.name || '';
  return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase();
});
const firstName = computed(() => {
  const name = userStore.currentUser?.name || '';
  return name.split(' ').pop() || name.split(' ')[0] || '';
});

onMounted(async () => {
  if (route.query.showLogin === 'true' || route.query.redirect) {
    loginRedirectTo.value = route.query.redirect || null;
    openLogin();
    if (route.query.expired === 'true') {
      toast.warning('Сессия истекла', 'Войдите заново для продолжения работы');
    }
  }
  document.addEventListener('click', handleOutsideClick);
  initEmberGlow();
  checkMaintenanceForHome();
});
onBeforeUnmount(() => {
  showLoader.value = false;
});

onUnmounted(() => {
  document.removeEventListener('click', handleOutsideClick);
  if (bgRaf) cancelAnimationFrame(bgRaf);
  if (bgResize) window.removeEventListener('resize', bgResize);
  if (maintenanceTickTimer) clearInterval(maintenanceTickTimer);
  _loaderTimers.forEach(id => clearTimeout(id));
  _loaderTimers = [];
});

function initEmberGlow() {
  const c = bgCanvas.value;
  if (!c) return;
  const ctx = c.getContext('2d');
  let w, h;
  const resize = () => {
    const p = c.parentElement;
    if (!p) return;
    w = c.width = p.clientWidth;
    h = c.height = p.clientHeight;
  };
  resize();
  bgResize = resize;
  window.addEventListener('resize', bgResize);

  const orbs = [
    { x: 0.3, y: 0.7, r: 300, rgb: [180,30,0], sp: 0.4, ph: 0 },
    { x: 0.7, y: 0.65, r: 250, rgb: [200,60,0], sp: 0.3, ph: 2 },
    { x: 0.5, y: 0.8, r: 350, rgb: [160,40,0], sp: 0.5, ph: 4 },
    { x: 0.15, y: 0.85, r: 200, rgb: [220,80,0], sp: 0.35, ph: 1 },
    { x: 0.85, y: 0.75, r: 220, rgb: [190,50,0], sp: 0.45, ph: 3 },
  ];
  const sparks = Array.from({ length: 50 }, () => ({
    x: Math.random(), y: Math.random(),
    vy: -(0.0002 + Math.random() * 0.0008),
    vx: (Math.random() - 0.5) * 0.0003,
    r: 0.5 + Math.random() * 1.5,
    ph: Math.random() * Math.PI * 2,
    sp: 0.01 + Math.random() * 0.02,
    bright: Math.random(),
  }));

  let t = 0;
  const loop = () => {
    t += 0.016;
    if (w <= 0 || h <= 0) { bgRaf = requestAnimationFrame(loop); return; }

    // Rich dark base
    const bg = ctx.createLinearGradient(0, 0, 0, h);
    bg.addColorStop(0, '#110a05'); bg.addColorStop(0.4, '#1a0e07');
    bg.addColorStop(0.7, '#221309'); bg.addColorStop(1, '#2a180c');
    ctx.fillStyle = bg; ctx.fillRect(0, 0, w, h);

    // Breathing orbs
    for (const o of orbs) {
      const breath = 0.6 + 0.4 * Math.sin(t * o.sp + o.ph);
      const r = o.r * (0.9 + breath * 0.2);
      const px = o.x * w + Math.sin(t * 0.2 + o.ph) * 20;
      const py = o.y * h + Math.cos(t * 0.15 + o.ph) * 15;
      const g = ctx.createRadialGradient(px, py, 0, px, py, r);
      const a = 0.08 + breath * 0.06;
      g.addColorStop(0, `rgba(${o.rgb},${a * 1.5})`);
      g.addColorStop(0.3, `rgba(${o.rgb},${a * 0.8})`);
      g.addColorStop(0.7, `rgba(${o.rgb},${a * 0.2})`);
      g.addColorStop(1, `rgba(${o.rgb},0)`);
      ctx.fillStyle = g; ctx.fillRect(0, 0, w, h);
      // Hot center
      const g2 = ctx.createRadialGradient(px, py, 0, px, py, r * 0.15);
      g2.addColorStop(0, `rgba(255,200,120,${breath * 0.06})`);
      g2.addColorStop(1, 'rgba(255,100,0,0)');
      ctx.fillStyle = g2; ctx.fillRect(px - r * 0.2, py - r * 0.2, r * 0.4, r * 0.4);
    }

    // Floating sparks
    for (const s of sparks) {
      s.ph += s.sp;
      s.x += s.vx + Math.sin(s.ph) * 0.0002;
      s.y += s.vy;
      if (s.y < -0.05) { s.y = 1.05; s.x = Math.random(); }
      const px = s.x * w, py = s.y * h;
      const glow = 0.3 + 0.7 * (0.5 + 0.5 * Math.sin(s.ph * 2));
      ctx.globalAlpha = glow * (s.bright > 0.7 ? 0.8 : 0.4);
      ctx.shadowBlur = s.r * 6;
      ctx.shadowColor = s.bright > 0.7 ? '#FFB060' : '#D65000';
      ctx.beginPath(); ctx.arc(px, py, s.r * glow, 0, Math.PI * 2);
      ctx.fillStyle = s.bright > 0.7 ? '#FFD090' : '#FF8040';
      ctx.fill();
    }
    ctx.globalAlpha = 1; ctx.shadowBlur = 0;

    // Subtle grain
    ctx.globalAlpha = 0.012;
    for (let i = 0; i < 200; i++) {
      ctx.fillStyle = Math.random() > 0.5 ? '#F5E6D0' : '#000';
      ctx.fillRect(Math.random() * w, Math.random() * h, 1, 1);
    }
    ctx.globalAlpha = 1;

    bgRaf = requestAnimationFrame(loop);
  };
  loop();
}

function handleOutsideClick(e) {
  if (showUserMenu.value && !e.target.closest('.p-header-right')) showUserMenu.value = false;
}

function openLogin() {
  showLoginModal.value = true;
  loginError.value = '';
  password.value = '';
}

const showLoader = ref(false);
const loaderText = ref('Загрузка...');

const loaderTexts = ['Загрузка...', 'Подготовка данных...', 'Почти готово!'];

function _safeTimeout(fn, delay) {
  const id = setTimeout(fn, delay);
  _loaderTimers.push(id);
  return id;
}

function goTo(name, query) {
  if (!userStore.isAuthenticated) { loginRedirectTo.value = '/' + name; openLogin(); return; }
  showLoader.value = true;
  loaderText.value = loaderTexts[0];
  _safeTimeout(() => { loaderText.value = loaderTexts[1]; }, 500);
  _safeTimeout(() => { loaderText.value = loaderTexts[2]; }, 1000);
  _safeTimeout(() => {
    showLoader.value = false;
    router.push(query ? { name, query } : { name });
  }, 1400);
}

function stubModule(name) { toast.info('В разработке', `${name} — скоро будет доступен`); }

function goPublic(key) {
  const routes = { search: '/search-cards' };
  const target = routes[key] || '/' + key;
  showLoader.value = true;
  loaderText.value = loaderTexts[0];
  _safeTimeout(() => { loaderText.value = loaderTexts[1]; }, 500);
  _safeTimeout(() => { loaderText.value = loaderTexts[2]; }, 1000);
  _safeTimeout(() => { showLoader.value = false; router.push(target); }, 1400);
}

async function doLogin() {
  if (!selectedUser.value) return;
  loginError.value = '';
  loginLoading.value = true;
  try {
    await userStore.login(selectedUser.value, password.value);
    sessionStorage.setItem('bk_just_logged_in', '1');
    showLoginModal.value = false;
    const redirect = loginRedirectTo.value;
    loginRedirectTo.value = null;
    showLoader.value = true;
    loaderText.value = loaderTexts[0];
    _safeTimeout(() => { loaderText.value = loaderTexts[1]; }, 500);
    _safeTimeout(() => { loaderText.value = loaderTexts[2]; }, 1000);
    _safeTimeout(() => {
      showLoader.value = false;
      if (redirect && redirect !== '/' && redirect !== '/login') router.push(redirect);
      else router.push({ name: 'order' });
    }, 1400);
  } catch (e) { loginError.value = e.message || 'Неверный пароль'; }
  finally { loginLoading.value = false; }
}

function confirmLogout() {
  showLogoutConfirm.value = false;
  localStorage.removeItem('bk_draft');
  userStore.logout();
  nextTick(() => { toast.info('До свидания!', 'Вы вышли из аккаунта'); });
}
</script>

<style scoped>
.portal { position: fixed; inset: 0; display: flex; flex-direction: column; font-family: 'Sora', -apple-system, BlinkMacSystemFont, sans-serif; overflow: hidden; background: #110a05; }
.portal-canvas { position: absolute; inset: 0; width: 100%; height: 100%; z-index: 0; display: block; }

.p-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 36px; position: relative; z-index: 2; background: rgba(44,26,14,.5); backdrop-filter: blur(12px); border-bottom: 1px solid rgba(255,255,255,.04); flex-shrink: 0; }

/* Maintenance banner */
.p-maint-banner {
  position: relative; z-index: 2;
  display: flex; align-items: center; justify-content: center; gap: 10px;
  padding: 10px 24px;
  background: linear-gradient(90deg, rgba(253,189,16,.12), rgba(214,39,0,.1), rgba(253,189,16,.12));
  border-bottom: 1px solid rgba(253,189,16,.15);
  font-size: 13px; font-weight: 500; color: #FDBD10;
  backdrop-filter: blur(8px);
  animation: mntBannerIn .4s ease;
}
@keyframes mntBannerIn { from { opacity: 0; transform: translateY(-100%); } to { opacity: 1; transform: none; } }
.p-header-left { display: flex; align-items: center; gap: 14px; }
.p-logo { display: flex; align-items: center; justify-content: center; }
.p-brand h1 { font-size: 17px; font-weight: 400; color: #F5E6D0; font-family: 'Flame', 'Sora', sans-serif; }
.p-brand small { display: block; font-size: 9px; font-weight: 700; color: rgba(245,166,35,.7); text-transform: uppercase; letter-spacing: 2px; margin-top: 1px; }
.p-header-right { display: flex; align-items: center; gap: 14px; position: relative; }
.p-entity { font-size: 10px; font-weight: 700; color: rgba(245,230,208,.6); background: rgba(255,255,255,.07); padding: 5px 14px; border-radius: 8px; border: 1px solid rgba(255,255,255,.08); }
.p-user { display: flex; align-items: center; gap: 9px; cursor: pointer; padding: 5px 14px 5px 5px; border-radius: 24px; transition: .15s; }
.p-user:hover { background: rgba(255,255,255,.05); }
.p-av { width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #D62700, #FF8733); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 800; color: #fff; }
.p-uname { font-size: 12px; font-weight: 600; color: rgba(245,230,208,.65); }
.p-login-btn { padding: 8px 20px; border-radius: 10px; border: 2px solid rgba(214,39,0,.4); background: rgba(214,39,0,.15); color: rgba(255,200,160,.8); font-size: 12px; font-weight: 700; font-family: inherit; cursor: pointer; transition: .2s; }
.p-login-btn:hover { background: #D62700; color: #fff; box-shadow: 0 4px 16px rgba(214,39,0,.25); }
.p-user-dropdown { position: absolute; top: 100%; right: 0; margin-top: 6px; background: #2C1A0E; border: 1px solid rgba(255,255,255,.08); border-radius: 12px; padding: 6px; box-shadow: 0 8px 24px rgba(0,0,0,.4); z-index: 100; }
.p-user-dropdown button { display: block; width: 100%; padding: 8px 16px; border: none; background: none; color: rgba(245,230,208,.5); font-size: 12px; font-weight: 600; font-family: inherit; cursor: pointer; border-radius: 8px; text-align: left; white-space: nowrap; transition: .15s; }
.p-user-dropdown button:hover { background: rgba(255,255,255,.05); color: #F5E6D0; }

/* Body */
.p-body { flex: 1; display: flex; flex-direction: column; justify-content: center; align-items: center; position: relative; z-index: 1; padding: 0 36px; gap: 36px; min-height: 0; }
.p-greeting { text-align: center; flex-shrink: 0; }
.p-greeting h2 { font-size: 30px; font-weight: 900; color: #F5E6D0; line-height: 1.15; font-family: 'Flame', 'Sora', sans-serif; }
.p-greeting h2 em { font-style: normal; color: #FF8733; }
.p-greeting p { font-size: 12px; color: rgba(245,230,208,.45); margin-top: 6px; font-weight: 500; }

/* Dock — slot/item pattern for stable hover */
.p-dock { display: flex; gap: 0; padding: 16px 28px; background: rgba(255,255,255,.035); border-radius: 22px; border: 1px solid rgba(255,255,255,.05); align-items: flex-end; flex-shrink: 0; }
.p-dock-slot { width: 72px; display: flex; justify-content: center; flex-shrink: 0; position: relative; z-index: 1; }
.p-dock-slot:hover { z-index: 10; }
.p-dock-item { display: flex; flex-direction: column; align-items: center; gap: 5px; cursor: pointer; transition: transform .25s cubic-bezier(.2,1,.3,1); position: relative; transform-origin: bottom center; will-change: transform; }
.p-dock-item.p-dock-hovered { transform: scale(1.28) translateY(-10px); }
.p-dock-item.p-dock-neighbor { transform: scale(1.1) translateY(-4px); }
.p-dock-item.p-dock-dim { opacity: .35; }
.p-dock-item.p-dock-dim.p-dock-hovered { opacity: .55; }
.p-dock-item.p-dock-stub { opacity: .2; }
.p-dock-item.p-dock-stub.p-dock-hovered { opacity: .35; }
.p-dock-icon { width: 56px; height: 56px; border-radius: 16px; background: #FAF6EF; border: 1.5px solid rgba(80,35,20,.06); display: flex; align-items: center; justify-content: center; box-shadow: 0 3px 10px rgba(0,0,0,.12); transition: box-shadow .2s; padding: 10px; }
.p-dock-item.p-dock-hovered .p-dock-icon { box-shadow: 0 10px 28px rgba(0,0,0,.25); }
.p-dock-icon :deep(svg) { width: 100%; height: 100%; }
.p-dock-stub .p-dock-icon { background: rgba(250,246,239,.4); border-style: dashed; border-color: rgba(80,35,20,.08); }
.p-dock-label { position: absolute; top: 100%; left: 50%; transform: translateX(-50%); margin-top: 8px; font-size: 9px; font-weight: 700; color: rgba(245,230,208,.65); opacity: 0; transition: opacity .18s; white-space: nowrap; text-align: center; pointer-events: none; }
.p-dock-item.p-dock-hovered .p-dock-label { opacity: 1; }
.p-dock-tag { position: absolute; top: -6px; right: -4px; font-size: 7px; font-weight: 800; text-transform: uppercase; background: rgba(214,39,0,.12); color: #D62700; padding: 1px 5px; border-radius: 4px; letter-spacing: .3px; }

/* Actions */
.p-actions { display: flex; gap: 10px; flex-shrink: 0; }
.p-btn { padding: 11px 22px; border-radius: 12px; font-family: inherit; font-size: 13px; font-weight: 700; cursor: pointer; transition: .2s; border: 1.5px solid rgba(255,255,255,.1); background: rgba(255,255,255,.06); color: rgba(245,230,208,.7); display: flex; align-items: center; gap: 7px; }
.p-btn:hover { border-color: rgba(255,255,255,.15); color: #F5E6D0; background: rgba(255,255,255,.07); }
.p-btn-primary { border-color: rgba(214,39,0,.3); background: rgba(214,39,0,.12); color: rgba(255,200,160,.8); }
.p-btn-primary:hover { background: #D62700; color: #fff; box-shadow: 0 4px 20px rgba(214,39,0,.25); transform: translateY(-1px); }
.p-btn-primary svg { opacity: .8; }
.p-btn-primary:hover svg { opacity: 1; }

/* Footer */
.p-footer { padding: 14px 36px; display: flex; justify-content: space-between; align-items: center; position: relative; z-index: 1; border-top: 1px solid rgba(255,255,255,.04); flex-shrink: 0; }
.p-footer-ver { font-size: 9px; color: rgba(245,230,208,.3); }
.p-footer-btn { background: none; border: none; color: rgba(245,230,208,.4); font-size: 11px; font-family: inherit; cursor: pointer; font-weight: 500; }
.p-footer-btn:hover { color: rgba(245,230,208,.7); }

/* Login modal */
.login-overlay { position: fixed; inset: 0; z-index: 10000; background: rgba(0,0,0,.65); backdrop-filter: blur(6px); display: flex; align-items: center; justify-content: center; }
.login-card { display: flex; width: 620px; max-width: 95vw; border-radius: 16px; overflow: hidden; box-shadow: 0 24px 64px rgba(0,0,0,.4); }
.login-left { width: 200px; flex-shrink: 0; background: linear-gradient(160deg, #502314, #3A1A0C); display: flex; align-items: center; justify-content: center; padding: 32px 16px; }
.login-brand { text-align: center; }
.login-brand-icon { font-size: 36px; display: block; margin-bottom: 10px; }
.login-brand-title { font-size: 18px; font-weight: 400; color: #F5E6D0; font-family: 'Flame', 'Sora', sans-serif; }
.login-brand-sub { font-size: 9px; font-weight: 700; color: rgba(245,166,35,.5); text-transform: uppercase; letter-spacing: 2px; margin-top: 4px; }
.login-right { flex: 1; background: #FFFBF5; padding: 32px; position: relative; }
.login-close { position: absolute; top: 12px; right: 14px; background: none; border: none; font-size: 18px; color: #A08870; cursor: pointer; }
.login-form-title { font-size: 18px; font-weight: 800; color: #502314; margin-bottom: 4px; }
.login-form-sub { font-size: 12px; color: #A08870; margin-bottom: 20px; }
.login-field { margin-bottom: 12px; }
.login-field label { display: block; font-size: 10px; font-weight: 700; color: #8B7355; text-transform: uppercase; letter-spacing: .6px; margin-bottom: 4px; }
.login-field select, .login-field input { width: 100%; padding: 10px 12px; border: 1.5px solid #E8DDD0; border-radius: 8px; font-size: 13px; font-family: inherit; background: #fff; }
.login-field select:focus, .login-field input:focus { border-color: #D62700; outline: none; }
.login-error { color: #D62700; font-size: 12px; font-weight: 600; margin-bottom: 8px; }
.login-submit { width: 100%; padding: 11px; border: none; border-radius: 10px; background: #D62700; color: #fff; font-size: 14px; font-weight: 700; font-family: inherit; cursor: pointer; transition: .2s; }
.login-submit:hover { background: #B52200; }
.login-submit:disabled { opacity: .5; cursor: default; }
@media (max-width: 600px) { .login-left { display: none; } }

/* Responsive */
@media (max-width: 760px) {
  .p-header { padding: 12px 16px; }
  .p-dock { gap: 0; padding: 12px 16px; flex-wrap: wrap; justify-content: center; border-radius: 16px; }
  .p-dock-slot { width: 56px; }
  .p-dock-icon { width: 44px; height: 44px; border-radius: 12px; padding: 8px; }
  .p-dock-label { font-size: 8px; }
  .p-greeting h2 { font-size: 22px; }
  .p-actions { flex-wrap: wrap; justify-content: center; }
  .p-body { padding: 0 16px; gap: 24px; }
}
@media (max-width: 480px) {
  .p-header { padding: 10px 12px; }
  .p-header-left { gap: 8px; }
  .p-logo { }
  .p-brand h1 { font-size: 14px; }
  .p-entity { display: none; }
  .p-uname { display: none; }
  .p-body { padding: 0 10px; gap: 18px; }
  .p-greeting h2 { font-size: 18px; }
  .p-greeting p { font-size: 10px; }
  .p-dock { gap: 0; padding: 10px 8px; border-radius: 14px; }
  .p-dock-slot { width: 48px; }
  .p-dock-icon { width: 38px; height: 38px; border-radius: 10px; padding: 7px; }
  .p-dock-item.p-dock-hovered { transform: scale(1.15) translateY(-6px); }
  .p-dock-item.p-dock-neighbor { transform: scale(1.05) translateY(-2px); }
  .p-dock-label { font-size: 7px; }
  .p-actions { gap: 6px; }
  .p-btn { padding: 9px 14px; font-size: 12px; border-radius: 10px; }
  .p-footer { padding: 10px 12px; }
  .login-card { flex-direction: column; }
  .login-left { display: none; }
  .login-right { padding: 24px 16px; }
}
@media (max-height: 580px) {
  .p-body { gap: 18px; }
  .p-greeting h2 { font-size: 22px; }
  .p-dock-icon { width: 44px; height: 44px; padding: 8px; }
  .p-dock { padding: 10px 18px; gap: 0; }
  .p-dock-slot { width: 60px; }
}

/* ═══ SUPPLY LOADER ═══ */
.supply-loader-overlay {
  position: fixed;
  inset: 0;
  z-index: 99999;
  background: linear-gradient(135deg, #2C1810 0%, #502314 50%, #3D1500 100%);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 32px;
}

.supply-loader-icon {
  animation: ldr-pulse 2s ease-in-out infinite;
}

.ldr-c {
  animation: ldr-circle 1.6s ease-in-out infinite;
}
.ldr-c1 { animation-delay: 0s; }
.ldr-c2 { animation-delay: 0.2s; }
.ldr-c3 { animation-delay: 0.4s; }
.ldr-c4 { animation-delay: 0.6s; }

@keyframes ldr-pulse {
  0%, 100% { transform: scale(1); opacity: 0.9; }
  50% { transform: scale(1.05); opacity: 1; }
}

@keyframes ldr-circle {
  0%, 100% { opacity: 0.5; }
  50% { opacity: 1; }
}

.supply-loader-text {
  color: rgba(255, 255, 255, 0.8);
  font-size: 15px;
  font-weight: 600;
  letter-spacing: 0.5px;
  animation: ldr-text-pulse 1.5s ease-in-out infinite;
}

@keyframes ldr-text-pulse {
  0%, 100% { opacity: 0.5; }
  50% { opacity: 1; }
}

.supply-loader-enter-active { animation: loaderFadeIn 0.3s ease; }
.supply-loader-leave-active { animation: loaderFadeOut 0.3s ease; }
@keyframes loaderFadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes loaderFadeOut { from { opacity: 1; } to { opacity: 0; } }
</style>
