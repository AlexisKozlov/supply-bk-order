import { createRouter, createWebHistory } from 'vue-router';
import { useUserStore } from '@/stores/userStore.js';

const APP_TITLE = 'Портал закупок';

const routes = [
  {
    path: '/',
    name: 'home',
    component: () => import('@/views/HomeView.vue'),
    meta: { title: 'Главная' },
  },
  {
    path: '/',
    component: () => import('@/layouts/AppLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      { path: 'order', name: 'order', component: () => import('@/views/OrderView.vue'), meta: { title: 'Новый заказ' } },
      { path: 'history', name: 'history', component: () => import('@/views/HistoryView.vue'), meta: { title: 'История' } },
      { path: 'plan-fact', name: 'plan-fact', component: () => import('@/views/PlanFactView.vue'), meta: { title: 'План-Факт' } },
      { path: 'planning', name: 'planning', component: () => import('@/views/PlanningView.vue'), meta: { title: 'Планирование' } },
      { path: 'analytics', name: 'analytics', component: () => import('@/views/AnalyticsView.vue'), meta: { title: 'Аналитика' } },
      { path: 'calendar', name: 'calendar', component: () => import('@/views/CalendarView.vue'), meta: { title: 'Календарь' } },
      { path: 'analysis', name: 'analysis', component: () => import('@/views/AnalysisView.vue'), meta: { title: 'Анализ' } },
      { path: 'database', name: 'database', component: () => import('@/views/DatabaseView.vue'), meta: { title: 'База товаров' } },
      { path: 'admin', name: 'admin', component: () => import('@/views/AdminView.vue'), meta: { requiresAdmin: true, title: 'Админ-панель' } },
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
    path: '/search-cards',
    name: 'search-cards',
    component: () => import('@/views/CardsSearchView.vue'),
    meta: { title: 'Поиск карточек' },
  },
];

export const router = createRouter({
  history: createWebHistory(),
  routes,
});

router.afterEach((to) => {
  const pageTitle = to.meta.title;
  document.title = pageTitle ? `${pageTitle} - ${APP_TITLE}` : APP_TITLE;
});

router.beforeEach((to) => {
  const userStore = useUserStore();
  if (!userStore.currentUser) {
    userStore.restoreSession();
  }
  if (to.meta.requiresAuth && !userStore.isAuthenticated) {
    return { name: 'home', query: { showLogin: 'true', redirect: to.fullPath } };
  }
  if (to.meta.requiresAdmin && userStore.currentUser?.role !== 'admin') {
    return { name: 'order' };
  }
});
