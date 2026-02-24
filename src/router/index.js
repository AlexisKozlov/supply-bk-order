import { createRouter, createWebHistory } from 'vue-router';
import { useUserStore } from '@/stores/userStore.js';

const routes = [
  {
    path: '/',
    name: 'home',
    component: () => import('@/views/HomeView.vue'),
  },
  {
    path: '/',
    component: () => import('@/layouts/AppLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      { path: 'order', name: 'order', component: () => import('@/views/OrderView.vue') },
      { path: 'history', name: 'history', component: () => import('@/views/HistoryView.vue') },
      { path: 'planning', name: 'planning', component: () => import('@/views/PlanningView.vue') },
      { path: 'analytics', name: 'analytics', component: () => import('@/views/AnalyticsView.vue') },
      { path: 'calendar', name: 'calendar', component: () => import('@/views/CalendarView.vue') },
      { path: 'database', name: 'database', component: () => import('@/views/DatabaseView.vue') },
      { path: 'suppliers', redirect: { name: 'database', query: { tab: 'suppliers' } } },
    ],
  },
  {
    path: '/login',
    name: 'login',
    redirect: (to) => ({ name: 'home', query: { showLogin: 'true', redirect: to.query.redirect || '' } }),
  },
  {
    path: '/goodbye',
    name: 'goodbye',
    redirect: { name: 'home' },
  },
  {
    path: '/share',
    name: 'share',
    component: () => import('@/views/ShareView.vue'),
  },
];

export const router = createRouter({
  history: createWebHistory(),
  routes,
});

router.beforeEach((to) => {
  const userStore = useUserStore();
  if (!userStore.currentUser) {
    userStore.restoreSession();
  }
  if (to.meta.requiresAuth && !userStore.isAuthenticated) {
    return { name: 'home', query: { showLogin: 'true', redirect: to.fullPath } };
  }
});
