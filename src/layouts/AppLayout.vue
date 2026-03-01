<template>
  <div class="app-layout" :class="{ 'sidebar-collapsed': sidebarCollapsed }">

    <!-- SIDEBAR -->
    <aside class="sidebar" :class="{ collapsed: sidebarCollapsed, open: sidebarOpen }">
      <div class="sidebar-brand">
        <router-link :to="{ name: 'home' }" class="sidebar-brand-icon" title="На главную"><svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" width="22" height="22"><path d="M8 30c0-12 10-20 24-20s24 8 24 20H8z" fill="#F5A623"/><path d="M8 30c0-12 10-20 24-20s24 8 24 20H8z" fill="url(#bt)"/><ellipse cx="20" cy="18" rx="2" ry="1.8" fill="#F9E4B7" opacity=".8"/><ellipse cx="30" cy="14" rx="1.8" ry="1.5" fill="#F9E4B7" opacity=".7"/><ellipse cx="40" cy="17" rx="2.2" ry="1.7" fill="#F9E4B7" opacity=".75"/><ellipse cx="26" cy="22" rx="1.5" ry="1.3" fill="#F9E4B7" opacity=".6"/><ellipse cx="36" cy="22" rx="1.6" ry="1.4" fill="#F9E4B7" opacity=".65"/><rect x="6" y="30" width="52" height="6" rx="3" fill="#4CAF50"/><path d="M6 33q3-3 6.5 0t6.5 0 6.5 0 6.5 0 6.5 0 6.5 0 6.5 0 6.5 0" stroke="#388E3C" stroke-width="1.5" fill="none"/><path d="M5 36l54 0" fill="none"/><path d="M7 36h50l2 5H5l2-5z" fill="#FDBD10"/><path d="M57 41l2 0-3 5H8l-3-5h2" fill="#FDBD10" opacity=".6"/><path d="M7 41h50v1q-3 4-6 4H13q-3 0-6-4v-1z" fill="#FDBD10" opacity=".3"/><rect x="6" y="42" width="52" height="9" rx="2" fill="#6D3A1F"/><rect x="6" y="42" width="52" height="9" rx="2" fill="url(#pt)"/><rect x="7" y="51" width="50" height="7" rx="4" fill="#D4881A"/><rect x="7" y="51" width="50" height="7" rx="4" fill="url(#bb)"/><defs><linearGradient id="bt" x1="32" y1="10" x2="32" y2="30" gradientUnits="userSpaceOnUse"><stop stop-color="#F5C547" offset="0"/><stop stop-color="#D4881A" offset="1"/></linearGradient><linearGradient id="pt" x1="32" y1="42" x2="32" y2="51" gradientUnits="userSpaceOnUse"><stop stop-color="#8B4513"/><stop stop-color="#4A2510" offset="1"/></linearGradient><linearGradient id="bb" x1="32" y1="51" x2="32" y2="58" gradientUnits="userSpaceOnUse"><stop stop-color="#E8A430"/><stop stop-color="#C47A15" offset="1"/></linearGradient></defs></svg></router-link>
        <div class="sidebar-brand-text" v-if="!sidebarCollapsed">
          Supply Department
          <small>Отдел закупок</small>
        </div>
        <button class="sidebar-collapse-btn" @click="toggleSidebar" title="Свернуть/Раскрыть">
          <BkIcon v-if="sidebarCollapsed" name="menu" size="sm" light/>
          <BkIcon v-else name="chevronLeft" size="sm" light/>
        </button>
      </div>

      <div class="sidebar-section" v-if="!sidebarCollapsed">Заказы</div>
      <div class="sidebar-nav-scroll">
      <nav class="sidebar-nav">
        <router-link :to="{ name: 'order' }" class="sidebar-item" :class="{ active: currentRoute === 'order' }">
          <span class="sidebar-icon"><BkIcon name="package" size="sm" light/></span>
          <span v-if="!sidebarCollapsed">Новый заказ</span>
        </router-link>
        <router-link :to="{ name: 'planning' }" class="sidebar-item" :class="{ active: currentRoute === 'planning' }">
          <span class="sidebar-icon"><BkIcon name="planning" size="sm" light/></span>
          <span v-if="!sidebarCollapsed">Планирование</span>
        </router-link>
        <router-link :to="{ name: 'plan-fact' }" class="sidebar-item" :class="{ active: currentRoute === 'plan-fact' }">
          <span class="sidebar-icon"><BkIcon name="delivery" size="sm" light/></span>
          <span v-if="!sidebarCollapsed">Поставки</span>
        </router-link>
        <router-link :to="{ name: 'history' }" class="sidebar-item" :class="{ active: currentRoute === 'history' }">
          <span class="sidebar-icon"><BkIcon name="history" size="sm" light/></span>
          <span v-if="!sidebarCollapsed">История</span>
        </router-link>
      </nav>

      <div class="sidebar-section" v-if="!sidebarCollapsed">Данные</div>
      <nav class="sidebar-nav">
        <router-link :to="{ name: 'database' }" class="sidebar-item" :class="{ active: currentRoute === 'database' }">
          <span class="sidebar-icon"><BkIcon name="database" size="sm" light/></span>
          <span v-if="!sidebarCollapsed">База данных</span>
        </router-link>
        <router-link :to="{ name: 'delivery-schedule' }" class="sidebar-item" :class="{ active: currentRoute === 'delivery-schedule' }">
          <span class="sidebar-icon"><BkIcon name="schedule" size="sm" light/></span>
          <span v-if="!sidebarCollapsed">График доставки</span>
        </router-link>
      </nav>

      <div class="sidebar-section" v-if="!sidebarCollapsed">Отчёты</div>
      <nav class="sidebar-nav">
        <router-link :to="{ name: 'analytics' }" class="sidebar-item" :class="{ active: currentRoute === 'analytics' }">
          <span class="sidebar-icon"><BkIcon name="analytics" size="sm" light/></span>
          <span v-if="!sidebarCollapsed">Аналитика</span>
        </router-link>
        <router-link :to="{ name: 'calendar' }" class="sidebar-item" :class="{ active: currentRoute === 'calendar' }">
          <span class="sidebar-icon"><BkIcon name="calendar" size="sm" light/></span>
          <span v-if="!sidebarCollapsed">Календарь</span>
        </router-link>
        <router-link :to="{ name: 'analysis' }" class="sidebar-item" :class="{ active: currentRoute === 'analysis' }">
          <span class="sidebar-icon"><BkIcon name="ruler" size="sm" light/></span>
          <span v-if="!sidebarCollapsed">Анализ</span>
        </router-link>
      </nav>

      <template v-if="userStore.isAdmin">
        <div class="sidebar-section" v-if="!sidebarCollapsed">Администрирование</div>
        <nav class="sidebar-nav">
          <router-link :to="{ name: 'admin' }" class="sidebar-item" :class="{ active: currentRoute === 'admin' }">
            <span class="sidebar-icon"><BkIcon name="gear" size="sm" light/></span>
            <span v-if="!sidebarCollapsed">Админ панель</span>
          </router-link>
        </nav>
      </template>
      </div>

      <!-- Юр. лицо (над пользователем, внизу) -->
      <div class="sidebar-entity-selector" v-if="!sidebarCollapsed">
        <label>Юр. лицо</label>
        <select :value="orderStore.settings.legalEntity" @change="onLegalEntityChange">
          <option v-for="le in availableEntities" :key="le" :value="le">{{ le }}</option>
        </select>
      </div>

      <!-- Уведомления -->
      <div class="sidebar-notifications" v-if="!sidebarCollapsed" @click="showNotifications = true">
        <BkIcon name="bell" size="sm" light/>
        <span>Уведомления</span>
        <span v-if="notificationStore.unreadCount" class="notification-badge-sidebar">{{ notificationStore.unreadCount }}</span>
      </div>
      <div v-else class="sidebar-notifications sidebar-notifications-collapsed" @click="showNotifications = true">
        <BkIcon name="bell" size="sm" light/>
        <span v-if="notificationStore.unreadCount" class="notification-badge-sidebar">{{ notificationStore.unreadCount }}</span>
      </div>

      <!-- User section at bottom -->
      <div class="sidebar-bottom" v-if="userStore.currentUser">
        <!-- Dropdown menu -->
        <div v-if="showUserMenu" class="user-dropdown" :class="{ 'user-dropdown-wide': sidebarCollapsed }">
          <button v-if="userStore.isAdmin" class="user-dropdown-btn" @click="goToAdmin">
            <BkIcon name="gear" size="sm" light/> Админ панель
          </button>
          <button class="user-dropdown-btn" @click="showChangePassword = true; showUserMenu = false;">
            <BkIcon name="key" size="sm" light/> Сменить пароль
          </button>
          <button class="user-dropdown-btn logout" @click="showLogoutConfirm = true; showUserMenu = false;">
            <BkIcon name="redo" size="sm"/> Выйти
          </button>
        </div>

        <div class="sidebar-user" @click="toggleUserMenu">
          <div class="user-avatar-letters">{{ userInitials }}</div>
          <div class="user-info" v-if="!sidebarCollapsed">
            <div class="user-name">{{ userStore.currentUser.name }}</div>
            <div class="user-role">{{ userStore.currentUser.display_role || 'Сотрудник' }}</div>
          </div>
        </div>
      </div>
    </aside>

    <!-- MAIN CONTENT -->
    <div class="main-wrapper">
      <!-- Мобильный оверлей -->
      <div class="sidebar-overlay" :class="{ visible: sidebarOpen }" @click="sidebarOpen = false"></div>

      <!-- Мобильный topbar -->
      <header class="topbar topbar-mobile-only">
        <button class="mobile-sidebar-toggle" @click="sidebarOpen = !sidebarOpen">☰</button>
        <button class="notification-bell-mobile" @click="showNotifications = true" title="Уведомления">
          <BkIcon name="bell" size="sm"/>
          <span v-if="notificationStore.unreadCount" class="notification-badge">{{ notificationStore.unreadCount }}</span>
        </button>
      </header>

      <!-- Оффлайн-баннер -->
      <div v-if="isOffline" class="offline-banner">
        <BkIcon name="warning" size="sm"/> Нет подключения к интернету
      </div>

      <!-- PAGE CONTENT -->
      <main class="content-area">
        <router-view v-slot="{ Component }">
          <Transition name="page" mode="out-in">
            <component :is="Component" :key="route.path" />
          </Transition>
        </router-view>
      </main>
    </div>

    <!-- Welcome overlay -->
    <div
      v-if="showWelcome"
      class="welcome-overlay"
      :class="{ 'welcome-visible': welcomeVisible, 'welcome-fade': welcomeFade }"
    >
      <canvas ref="welcomeCanvas" class="welcome-canvas"></canvas>
      <div class="welcome-content">
        <div class="welcome-avatar-ring">
          <div class="welcome-avatar">{{ userInitials }}</div>
        </div>
        <div class="welcome-greeting">{{ welcomeTimeGreeting }}</div>
        <div class="welcome-name">{{ userStore.currentUser?.name }}</div>
        <div class="welcome-entity">{{ orderStore.settings.legalEntity }}</div>
        <div class="welcome-line"></div>
      </div>
    </div>

    <!-- Change Password Modal -->
    <Teleport to="body">
      <div v-if="showChangePassword" class="modal" @click.self="showChangePassword = false">
        <div class="modal-box" style="max-width: 380px;">
          <h3 style="margin-bottom: 16px;">Сменить пароль</h3>
          <div style="display:flex;flex-direction:column;gap:10px;">
            <input v-model="pwdOld" type="password" placeholder="Текущий пароль" autocomplete="current-password" />
            <input v-model="pwdNew" type="password" placeholder="Новый пароль" autocomplete="new-password" />
            <input v-model="pwdConfirm" type="password" placeholder="Подтвердите новый пароль" autocomplete="new-password" />
            <div v-if="pwdError" style="color: var(--error); font-size: 13px;">{{ pwdError }}</div>
            <div v-if="pwdSuccess" style="color: var(--green); font-size: 13px;">{{ pwdSuccess }}</div>
          </div>
          <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <button class="btn" @click="showChangePassword = false; resetPwdForm()">Отмена</button>
            <button class="btn primary" @click="changePassword" :disabled="pwdLoading">
              {{ pwdLoading ? 'Сохранение...' : 'Сохранить' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Logout Confirm Modal -->
    <Teleport to="body">
      <div v-if="showLogoutConfirm" class="modal" @click.self="showLogoutConfirm = false">
        <div class="modal-box" style="max-width: 380px;">
          <h3 style="margin-bottom: 8px;">Выйти из аккаунта?</h3>
          <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 20px;">Вы уверены, что хотите выйти?</p>
          <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button class="btn" @click="showLogoutConfirm = false">Отмена</button>
            <button class="btn primary" style="background:var(--error);" @click="confirmLogout">Выйти</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Entity Change Confirm Modal -->
    <Teleport to="body">
      <div v-if="showEntityConfirm" class="modal" @click.self="cancelEntityChange">
        <div class="modal-box" style="max-width: 400px;">
          <h3 style="margin-bottom: 8px;">Сменить юридическое лицо?</h3>
          <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 6px;">
            Переход на <b>{{ pendingEntity }}</b>
          </p>
          <p style="color: var(--error); font-size: 13px; margin-bottom: 20px;">
            Текущие данные и заполненные параметры будут сброшены.
          </p>
          <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button class="btn" @click="cancelEntityChange">Отмена</button>
            <button class="btn primary" @click="confirmEntityChange">Сменить</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Notifications Modal -->
    <Teleport to="body">
      <div v-if="showNotifications" class="modal" @click.self="showNotifications = false">
        <div class="modal-box" style="max-width: 480px;">
          <div class="modal-header">
            <h2><BkIcon name="bell" size="sm"/> Уведомления</h2>
            <button class="modal-close" @click="showNotifications = false"><BkIcon name="close" size="sm"/></button>
          </div>
          <div class="notif-list">
            <div v-if="notificationStore.loading && !notificationStore.visibleNotifications.length" class="notif-empty">Загрузка...</div>
            <div v-else-if="!notificationStore.visibleNotifications.length" class="notif-empty">Нет уведомлений</div>
            <div v-else>
              <div v-for="n in notificationStore.visibleNotifications" :key="n.id" class="notif-item" :class="{ 'notif-unread': isUnread(n), 'notif-clickable': n.entity_id }">
                <div class="notif-icon-col" @click="goToNotifEntity(n)">
                  <div class="notif-icon" :class="n.entity_type === 'plan' ? 'notif-icon-plan' : 'notif-icon-order'">
                    <BkIcon :name="n.entity_type === 'plan' ? 'planning' : 'package'" size="sm"/>
                  </div>
                </div>
                <div class="notif-body" @click="goToNotifEntity(n)">
                  <div class="notif-title">{{ n.title }}</div>
                  <div v-if="n.message" class="notif-message">{{ n.message }}</div>
                  <div class="notif-meta">
                    {{ formatNotifDate(n.created_at) }}
                    <span v-if="n.entity_id" class="notif-link">Открыть →</span>
                  </div>
                </div>
                <button class="notif-delete-btn" @click.stop="notificationStore.deleteNotification(n.id)" title="Удалить">×</button>
              </div>
            </div>
          </div>
          <div v-if="notificationStore.unreadCount > 0 || notificationStore.visibleNotifications.length > 0" class="notif-actions">
            <button v-if="notificationStore.unreadCount > 0" class="btn primary" @click="notificationStore.markAllRead()">Прочитать все</button>
            <button class="btn notif-delete-all-btn" @click="notificationStore.deleteAll()">Удалить все</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Broadcast Popup -->
    <BroadcastPopup />

  </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useUserStore } from '@/stores/userStore.js';
import { useOrderStore } from '@/stores/orderStore.js';
import { useNotificationStore } from '@/stores/notificationStore.js';
import { db } from '@/lib/apiClient.js';
import BkIcon from '@/components/ui/BkIcon.vue';
import BroadcastPopup from '@/components/BroadcastPopup.vue';


const router = useRouter();
const route = useRoute();
const userStore = useUserStore();
const orderStore = useOrderStore();
const notificationStore = useNotificationStore();
const showNotifications = ref(false);
const isOffline = ref(!navigator.onLine);
function handleOnline() { isOffline.value = false; }
function handleOffline() { isOffline.value = true; }

const sidebarCollapsed = ref(localStorage.getItem('bk_sidebar_collapsed') === 'true');
const sidebarOpen = ref(false);

const showUserMenu = ref(false);
const showChangePassword = ref(false);
const showLogoutConfirm = ref(false);

const showWelcome = ref(false);
const welcomeVisible = ref(false);
const welcomeFade = ref(false);
const welcomeCanvas = ref(null);
let welcomeRaf = null;

const welcomeTimeGreeting = computed(() => {
  const h = new Date().getHours();
  if (h >= 5 && h < 12) return 'Доброе утро';
  if (h >= 12 && h < 17) return 'Добрый день';
  if (h >= 17 && h < 22) return 'Добрый вечер';
  return 'Доброй ночи';
});

function startWelcomeParticles() {
  const c = welcomeCanvas.value;
  if (!c) return;
  const ctx = c.getContext('2d');
  const dpr = window.devicePixelRatio || 1;
  const rect = c.parentElement.getBoundingClientRect();
  c.width = rect.width * dpr;
  c.height = rect.height * dpr;
  ctx.scale(dpr, dpr);
  const w = rect.width, h = rect.height;

  const sparks = Array.from({ length: 80 }, () => ({
    x: Math.random() * w,
    y: Math.random() * h,
    r: 0.5 + Math.random() * 2,
    vy: -(0.15 + Math.random() * 0.6),
    vx: (Math.random() - 0.5) * 0.3,
    a: 0.2 + Math.random() * 0.6,
    ph: Math.random() * Math.PI * 2,
  }));

  let t = 0;
  const loop = () => {
    t += 0.016;
    ctx.clearRect(0, 0, w, h);
    for (const s of sparks) {
      s.ph += 0.02;
      s.x += s.vx + Math.sin(s.ph) * 0.15;
      s.y += s.vy;
      if (s.y < -10) { s.y = h + 10; s.x = Math.random() * w; }
      const glow = 0.5 + 0.5 * Math.sin(s.ph * 2);
      ctx.globalAlpha = s.a * glow;
      ctx.shadowBlur = s.r * 4;
      ctx.shadowColor = '#FFB060';
      ctx.beginPath();
      ctx.arc(s.x, s.y, s.r, 0, Math.PI * 2);
      ctx.fillStyle = s.a > 0.5 ? '#FFD090' : '#FF8040';
      ctx.fill();
    }
    ctx.globalAlpha = 1;
    ctx.shadowBlur = 0;
    welcomeRaf = requestAnimationFrame(loop);
  };
  loop();
}

function stopWelcomeParticles() {
  if (welcomeRaf) { cancelAnimationFrame(welcomeRaf); welcomeRaf = null; }
}

const pwdOld = ref('');
const pwdNew = ref('');
const pwdConfirm = ref('');
const pwdError = ref('');
const pwdSuccess = ref('');
const pwdLoading = ref(false);

// ═══ Heartbeat (онлайн-присутствие) ═══
const pageNames = {
  order: 'Новый заказ',
  planning: 'Планирование',
  history: 'История',
  analytics: 'Аналитика',
  calendar: 'Календарь',
  analysis: 'Анализ',
  database: 'База данных',
  admin: 'Админ панель',
  'plan-fact': 'Поставки',
  'delivery-schedule': 'График доставки',
};

function sendHeartbeat() {
  if (isOffline.value) return;
  const name = userStore.currentUser?.name;
  if (!name) return;
  const page = pageNames[route.name] || route.name || '';
  const editingOrderId = (route.name === 'order' && route.query.orderId && route.query.mode === 'edit') ? route.query.orderId : null;
  db.rpc('heartbeat', { user_name: name, page, editing_order_id: editingOrderId }).catch(() => {});
}

let heartbeatTimer = null;
let maintenanceTimer = null;
let removeAfterEach = null;

removeAfterEach = router.afterEach(() => {
  sidebarOpen.value = false;
  if (!userStore.isAdmin) userStore.checkMaintenance();
  sendHeartbeat();
});

const currentRoute = computed(() => route.name);

const availableEntities = computed(() => {
  const allowed = userStore.getAllowedEntities();
  const all = ['ООО "Бургер БК"', 'ООО "Воглия Матта"', 'ООО "Пицца Стар"'];
  if (!allowed || allowed.length === 0) return all;
  return all.filter(e => allowed.includes(e));
});

const userInitials = computed(() => {
  const name = userStore.currentUser?.name || '';
  return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase();
});

function toggleSidebar() {
  sidebarCollapsed.value = !sidebarCollapsed.value;
  localStorage.setItem('bk_sidebar_collapsed', sidebarCollapsed.value);
}

onMounted(() => {
  const allowed = userStore.getAllowedEntities();
  if (allowed && allowed.length > 0 && !allowed.includes(orderStore.settings.legalEntity)) {
    orderStore.settings.legalEntity = allowed[0];
  }

  const justLoggedIn = sessionStorage.getItem('bk_just_logged_in');
  if (justLoggedIn) {
    sessionStorage.removeItem('bk_just_logged_in');
    showWelcome.value = true;
    requestAnimationFrame(() => {
      welcomeVisible.value = true;
      nextTick(() => startWelcomeParticles());
    });
    setTimeout(() => { welcomeFade.value = true; }, 2200);
    setTimeout(() => { showWelcome.value = false; welcomeVisible.value = false; welcomeFade.value = false; stopWelcomeParticles(); }, 2800);
  }

  document.addEventListener('click', handleOutsideClick);
  window.addEventListener('online', handleOnline);
  window.addEventListener('offline', handleOffline);

  notificationStore.startPolling();

  // Heartbeat — онлайн-присутствие (каждые 30 сек)
  sendHeartbeat();
  heartbeatTimer = setInterval(sendHeartbeat, 30000);

  // Периодическая проверка тех. работ (каждые 60 сек)
  if (!userStore.isAdmin) {
    userStore.checkMaintenance();
    maintenanceTimer = setInterval(() => userStore.checkMaintenance(), 60000);
  }
});

onUnmounted(() => {
  document.removeEventListener('click', handleOutsideClick);
  window.removeEventListener('online', handleOnline);
  window.removeEventListener('offline', handleOffline);
  if (heartbeatTimer) clearInterval(heartbeatTimer);
  if (maintenanceTimer) clearInterval(maintenanceTimer);
  if (removeAfterEach) removeAfterEach();
  notificationStore.stopPolling();
});

function isUnread(n) {
  const name = userStore.currentUser?.name;
  if (!name) return false;
  const readBy = Array.isArray(n.read_by) ? n.read_by : [];
  return !readBy.includes(name);
}

function goToNotifEntity(n) {
  if (!n.entity_id) return;
  showNotifications.value = false;
  if (!isUnread(n)) { /* уже прочитано */ } else {
    notificationStore.markRead([n.id]);
  }
  if (n.entity_type === 'plan') {
    router.push({ path: '/planning', query: { planId: n.entity_id, mode: 'view' } });
  } else {
    router.push({ name: 'order', query: { orderId: n.entity_id, mode: 'view' } });
  }
}

function formatNotifDate(str) {
  if (!str) return '';
  const d = new Date(str);
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' }) + ' ' +
         d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

function handleOutsideClick(e) {
  if (showUserMenu.value && !e.target.closest('.sidebar-bottom')) {
    showUserMenu.value = false;
  }
}

function toggleUserMenu() {
  showUserMenu.value = !showUserMenu.value;
}

function goToAdmin() {
  showUserMenu.value = false;
  router.push({ name: 'admin' });
}

const showEntityConfirm = ref(false);
const pendingEntity = ref('');

function onLegalEntityChange(e) {
  const le = e.target.value;
  const isOrderPage = route.name === 'order';
  const isPlanningPage = route.name === 'planning';
  // Если на странице заказа и есть данные — подтвердить
  if (isOrderPage && orderStore.items.some(i => i.consumptionPeriod > 0 || i.stock > 0 || i.finalOrder > 0)) {
    pendingEntity.value = le;
    showEntityConfirm.value = true;
    e.target.value = orderStore.settings.legalEntity;
    return;
  }
  // Если на странице планирования — предупредить (данные планирования сбросятся через watcher)
  if (isPlanningPage) {
    pendingEntity.value = le;
    showEntityConfirm.value = true;
    e.target.value = orderStore.settings.legalEntity;
    return;
  }
  applyEntityChange(le);
}

function confirmEntityChange() {
  showEntityConfirm.value = false;
  applyEntityChange(pendingEntity.value);
}

function cancelEntityChange() {
  showEntityConfirm.value = false;
  pendingEntity.value = '';
}

function applyEntityChange(le) {
  orderStore.settings.legalEntity = le;
  // Сброс данных если на странице заказа
  if (route.name === 'order') {
    orderStore.resetOrder();
  } else {
    orderStore.settings.supplier = '';
  }
}

function resetPwdForm() {
  pwdOld.value = '';
  pwdNew.value = '';
  pwdConfirm.value = '';
  pwdError.value = '';
  pwdSuccess.value = '';
}

async function changePassword() {
  pwdError.value = '';
  pwdSuccess.value = '';
  if (!pwdOld.value || !pwdNew.value) { pwdError.value = 'Заполните все поля'; return; }
  if (pwdNew.value !== pwdConfirm.value) { pwdError.value = 'Пароли не совпадают'; return; }
  if (pwdNew.value.length < 8) { pwdError.value = 'Минимум 8 символов'; return; }

  pwdLoading.value = true;
  try {
    const { data } = await db.rpc('change_user_password', {
      user_name: userStore.currentUser.name,
      old_password: pwdOld.value,
      new_password: pwdNew.value,
    });
    if (data?.success) {
      pwdSuccess.value = 'Пароль успешно изменён';
      setTimeout(() => { showChangePassword.value = false; resetPwdForm(); }, 1500);
    } else if (data?.error === 'wrong_password') {
      pwdError.value = 'Неверный текущий пароль';
    } else {
      pwdError.value = 'Ошибка при смене пароля';
    }
  } catch {
    pwdError.value = 'Ошибка соединения';
  } finally {
    pwdLoading.value = false;
  }
}

function confirmLogout() {
  showLogoutConfirm.value = false;
  localStorage.removeItem('bk_draft');
  userStore.logout();
  router.replace({ name: 'home' });
}
</script>

<style scoped>
/* Notification bell - mobile topbar */
.notification-bell-mobile {
  position: relative; background: none; border: none; cursor: pointer;
  padding: 4px 8px; margin-left: auto;
}
.notification-badge {
  position: absolute; top: -2px; right: 0;
  background: #D62700; color: #fff; font-size: 10px; font-weight: 700;
  min-width: 16px; height: 16px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  padding: 0 4px; line-height: 1;
}

/* Sidebar notifications */
.sidebar-notifications {
  display: flex; align-items: center; gap: 8px;
  padding: 8px 16px; cursor: pointer;
  color: rgba(255,255,255,0.7); font-size: 13px; font-weight: 500;
  transition: background 0.15s;
}
.sidebar-notifications:hover { background: rgba(255,255,255,0.08); }
.sidebar-notifications-collapsed {
  justify-content: center; padding: 8px;
  position: relative;
}
.notification-badge-sidebar {
  background: #D62700; color: #fff; font-size: 10px; font-weight: 700;
  min-width: 16px; height: 16px; border-radius: 8px;
  display: inline-flex; align-items: center; justify-content: center;
  padding: 0 4px; margin-left: auto; line-height: 1;
}

/* Notification modal items */
.notif-list { max-height: 420px; overflow-y: auto; padding: 0 20px 12px; }
.notif-empty { text-align: center; padding: 32px 0; color: var(--text-muted); font-size: 13px; }
.notif-actions { display: flex; justify-content: center; gap: 8px; padding: 8px 20px 16px; }
.notif-item {
  display: flex; gap: 10px; padding: 10px 0;
  border-bottom: 1px solid var(--border-light);
}
.notif-item:last-child { border-bottom: none; }
.notif-unread { background: #FFF8E1; margin: 0 -20px; padding: 10px 20px; border-radius: 6px; }
.notif-icon-col { flex-shrink: 0; padding-top: 1px; }
.notif-icon {
  width: 32px; height: 32px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
}
.notif-icon-order { background: #E3F2FD; color: #1565C0; }
.notif-icon-plan { background: #F3E5F5; color: #7B1FA2; }
.notif-body { flex: 1; min-width: 0; }
.notif-title { font-weight: 600; font-size: 13px; color: var(--text); line-height: 1.3; }
.notif-message {
  font-size: 12px; color: var(--text-secondary); margin-top: 4px;
  white-space: pre-line; line-height: 1.5;
  background: var(--bg-secondary, #f5f5f5); border-radius: 6px;
  padding: 6px 8px;
}
.notif-meta { font-size: 11px; color: var(--text-muted); margin-top: 4px; display: flex; align-items: center; gap: 8px; }
.notif-clickable { cursor: pointer; border-radius: 8px; transition: background 0.15s; }
.notif-clickable:hover { background: var(--bg-secondary, #f5f5f5); }
.notif-link { color: var(--bk-orange, #E87A1E); font-weight: 600; margin-left: auto; }

/* Кнопка удаления отдельного уведомления */
.notif-delete-btn {
  flex-shrink: 0; align-self: flex-start;
  background: none; border: none; cursor: pointer;
  color: var(--text-muted); font-size: 18px; line-height: 1;
  padding: 2px 6px; border-radius: 4px;
  opacity: 0; transition: opacity 0.15s, background 0.15s, color 0.15s;
}
.notif-item:hover .notif-delete-btn { opacity: 1; }
.notif-delete-btn:hover { background: rgba(214, 39, 0, 0.1); color: #D62700; }

/* Кнопка «Удалить все» */
.notif-delete-all-btn {
  color: #D62700 !important; border-color: #D62700 !important;
}
.notif-delete-all-btn:hover { background: rgba(214, 39, 0, 0.08) !important; }

/* Offline banner */
.offline-banner {
  background: #FF9800; color: #fff; text-align: center;
  padding: 6px 16px; font-size: 13px; font-weight: 600;
  display: flex; align-items: center; justify-content: center; gap: 6px;
}

/* ═══ Welcome overlay ═══ */
.welcome-overlay {
  position: fixed; inset: 0; z-index: 99999;
  background: linear-gradient(135deg, #2C1810 0%, #502314 40%, #8B4513 100%);
  display: flex; align-items: center; justify-content: center;
  opacity: 0; transition: opacity .4s ease;
  pointer-events: none;
}
.welcome-overlay.welcome-visible { opacity: 1; pointer-events: auto; }
.welcome-overlay.welcome-fade { opacity: 0; transition: opacity .6s ease; }
.welcome-canvas {
  position: absolute; inset: 0; width: 100%; height: 100%; z-index: 0;
}
.welcome-content {
  position: relative; z-index: 1;
  display: flex; flex-direction: column; align-items: center; gap: 12px;
  animation: welcomeSlideUp .6s cubic-bezier(.2,.8,.3,1) forwards;
}
@keyframes welcomeSlideUp {
  from { opacity: 0; transform: translateY(30px) scale(.95); }
  to { opacity: 1; transform: none; }
}
.welcome-avatar-ring {
  width: 80px; height: 80px; border-radius: 50%;
  background: conic-gradient(#FDBD10, #D62700, #FF8733, #FDBD10);
  display: flex; align-items: center; justify-content: center;
  animation: welcomeRingSpin 3s linear infinite;
  padding: 3px;
}
@keyframes welcomeRingSpin { to { transform: rotate(360deg); } }
.welcome-avatar {
  width: 100%; height: 100%; border-radius: 50%;
  background: linear-gradient(135deg, #D62700, #FF8733);
  display: flex; align-items: center; justify-content: center;
  font-size: 22px; font-weight: 900; color: #fff;
  letter-spacing: 1px;
}
.welcome-greeting {
  font-size: 14px; font-weight: 500; color: rgba(245,230,208,.6);
  text-transform: uppercase; letter-spacing: 3px;
  margin-top: 8px;
}
.welcome-name {
  font-size: 28px; font-weight: 900; color: #F5E6D0;
  font-family: 'Flame', 'Sora', sans-serif;
  text-align: center;
}
.welcome-entity {
  font-size: 12px; font-weight: 600; color: rgba(253,189,16,.7);
  background: rgba(253,189,16,.08); padding: 4px 16px;
  border-radius: 20px; border: 1px solid rgba(253,189,16,.15);
}
.welcome-line {
  width: 40px; height: 3px; border-radius: 2px;
  background: linear-gradient(90deg, #D62700, #FF8733);
  margin-top: 4px;
  animation: welcomeLineGrow .8s ease .3s both;
}
@keyframes welcomeLineGrow {
  from { width: 0; opacity: 0; }
  to { width: 40px; opacity: 1; }
}

@media (max-width: 480px) {
  .welcome-name { font-size: 22px; }
  .welcome-avatar-ring { width: 64px; height: 64px; }
  .welcome-avatar { font-size: 18px; }
}
</style>
