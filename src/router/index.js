import { createRouter, createWebHistory } from 'vue-router';
import { useUserStore } from '@/stores/userStore.js';

const APP_TITLE = 'Портал закупок';

// Пустой компонент-заглушка для child-маршрутов кабинета ресторана
// (родитель сам управляет отображением вкладок через свой state)
const EmptySlot = { name: 'EmptySlot', render: () => null };

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
      { path: 'order', name: 'order', component: () => import('@/views/OrderView.vue'), meta: { title: 'Новый заказ', module: 'order' } },
      { path: 'history', name: 'history', component: () => import('@/views/HistoryView.vue'), meta: { title: 'История', module: 'history' } },
      { path: 'plan-fact', name: 'plan-fact', component: () => import('@/views/PlanFactView.vue'), meta: { title: 'Поставки', module: 'plan-fact' } },
      { path: 'planning', name: 'planning', component: () => import('@/views/PlanningView.vue'), meta: { title: 'Планирование', module: 'planning' } },
      { path: 'analytics', name: 'analytics', component: () => import('@/views/AnalyticsView.vue'), meta: { title: 'Аналитика', module: 'analytics' } },
      { path: 'calendar', name: 'calendar', component: () => import('@/views/CalendarView.vue'), meta: { title: 'Календарь', module: 'calendar' } },
      { path: 'analysis', name: 'analysis', component: () => import('@/views/AnalysisView.vue'), meta: { title: 'Анализ', module: 'analysis' } },
      { path: 'restaurant-sales', name: 'restaurant-sales', component: () => import('@/views/RestaurantSalesView.vue'), meta: { title: 'Реализация', module: 'restaurant-sales' } },
      { path: 'database', name: 'database', component: () => import('@/views/DatabaseView.vue'), meta: { title: 'База товаров', module: 'database' } },
      { path: 'delivery-schedule', name: 'delivery-schedule', component: () => import('@/views/DeliveryScheduleView.vue'), meta: { title: 'График доставки', module: 'delivery-schedule' } },
      { path: 'shelf-life', name: 'shelf-life', component: () => import('@/views/ShelfLifeView.vue'), meta: { title: 'Сроки годности', module: 'shelf-life' } },
      { path: 'pricing', name: 'pricing', component: () => import('@/views/PricingView.vue'), meta: { title: 'Цены и ПСЦ', module: 'pricing' } },
      { path: 'admin', name: 'admin', component: () => import('@/views/AdminView.vue'), meta: { requiresAdmin: true, title: 'Админ-панель' } },
      { path: 'telegram-admin', name: 'telegram-admin', component: () => import('@/views/TelegramAdminView.vue'), meta: { title: 'Telegram-бот', module: 'telegram' } },
      { path: 'suppliers', redirect: { name: 'database', query: { tab: 'suppliers' } } },
      { path: 'deficit', name: 'deficit', component: () => import('@/views/DeficitView.vue'), meta: { title: 'Распределение дефицита', module: 'deficit' } },
      { path: 'stock-collection', name: 'stock-collection', component: () => import('@/views/StockCollectionView.vue'), meta: { title: 'Сбор остатков', module: 'stock-collection' } },
      { path: 'tenders', name: 'tenders', component: () => import('@/views/TendersView.vue'), meta: { title: 'Тендеры', module: 'tenders' } },
      { path: 'tenders/:id', name: 'tender-detail', component: () => import('@/views/TenderDetailView.vue'), meta: { title: 'Тендер', module: 'tenders' } },
      { path: 'marketing', name: 'marketing', component: () => import('@/views/MarketingView.vue'), meta: { title: 'Маркетинг', module: 'marketing' } },
      { path: 'marketing/new', name: 'marketing-new', component: () => import('@/views/MarketingDetailView.vue'), meta: { title: 'Новая активность', module: 'marketing' } },
      { path: 'marketing/:id', name: 'marketing-detail', component: () => import('@/views/MarketingDetailView.vue'), meta: { title: 'Маркетинговая активность', module: 'marketing' } },
      { path: 'distribution', name: 'distribution', component: () => import('@/views/DistributionView.vue'), meta: { title: 'Распределение', module: 'distribution' } },
      { path: 'pallet-calc', name: 'pallet-calc', component: () => import('@/views/PalletCalcView.vue'), meta: { title: 'Калькулятор паллет', module: 'pallet-calc' } },
      { path: 'pallet-storage', name: 'pallet-storage', component: () => import('@/views/PalletStorageView.vue'), meta: { title: 'Паллетовка склада', module: 'pallet-storage' } },
      { path: 'payments', name: 'payments', component: () => import('@/views/PaymentsView.vue'), meta: { title: 'Оплаты', module: 'plan-fact' } },
      { path: 'dashboard', name: 'dashboard', component: () => import('@/views/DashboardView.vue'), meta: { title: 'Дашборд', module: 'analytics' } },
      { path: 'settings', name: 'user-settings', component: () => import('@/views/UserSettingsView.vue'), meta: { title: 'Настройки' } },
      { path: 'import', name: 'import', component: () => import('@/views/ImportView.vue'), meta: { title: 'Импорт данных', module: 'analysis' } },
      { path: 'corrections', name: 'corrections', component: () => import('@/views/CorrectionsView.vue'), meta: { title: 'Корректировки', module: 'corrections' } },
      { path: 'chat', name: 'chat', component: () => import('@/views/ChatView.vue'), meta: { title: 'Чат с ресторанами', module: 'chat' } },
      { path: 'restaurant-cabinet-manager', name: 'restaurant-cabinet-manager', component: () => import('@/views/RestaurantCabinetManagerView.vue'), meta: { title: 'Кабинеты ресторанов', module: 'restaurant-orders' } },
      { path: 'restaurant-orders', name: 'restaurant-orders', component: () => import('@/views/RestaurantOrdersManagerView.vue'), meta: { title: 'Заказы ресторанов', module: 'restaurant-orders' } },
      { path: 'restaurant-report', name: 'restaurant-report', component: () => import('@/views/RestaurantReportView.vue'), meta: { title: 'Отчёт по заказам', module: 'restaurant-orders' } },
      { path: 'restaurant-unknown-barcodes', name: 'restaurant-unknown-barcodes', component: () => import('@/views/RestaurantUnknownBarcodesView.vue'), meta: { title: 'Неизвестные штрихкоды', module: 'restaurant-orders' } },
      { path: 'surveys', name: 'surveys', component: () => import('@/views/SurveysView.vue'), meta: { title: 'Опросы', module: 'surveys' } },
      { path: 'supplier-orders', name: 'supplier-orders', component: () => import('@/views/SupplierOrdersHubView.vue'), meta: { title: 'Заявки поставщикам', module: 'supplier-orders' } },
      { path: 'truck-loading', name: 'truck-loading', component: () => import('@/views/TruckLoadingView.vue'), meta: { title: 'Загрузка машин', module: 'truck-loading' } },
      { path: 'protocols', name: 'protocols', component: () => import('@/views/MeetingProtocolsView.vue'), meta: { title: 'Протоколы совещаний', module: 'protocols' } },
      { path: 'protocols/:id', name: 'protocol-detail', component: () => import('@/views/MeetingProtocolDetailView.vue'), meta: { title: 'Протокол', module: 'protocols' } },
      { path: 'tasks', name: 'tasks', component: () => import('@/views/TasksView.vue'), meta: { title: 'Задачи', module: 'tasks' } },
    ],
  },
  {
    path: '/deficit-form/:token',
    name: 'deficit-form',
    component: () => import('@/views/DeficitFormView.vue'),
    meta: { title: 'Остатки ресторана' },
  },
  {
    path: '/stock-form/:token',
    name: 'stock-form',
    component: () => import('@/views/StockFormView.vue'),
    meta: { title: 'Остатки ресторана' },
  },
  {
    path: '/restaurant/login',
    name: 'restaurant-order-login',
    component: () => import('@/views/RestaurantOrderLoginView.vue'),
    meta: { title: 'Заказы — Вход' },
  },
  {
    path: '/restaurant',
    component: () => import('@/views/RestaurantCabinetView.vue'),
    meta: { title: 'Личный кабинет' },
    children: [
      { path: '', name: 'restaurant-cabinet', redirect: { name: 'restaurant-dashboard' } },
      { path: 'dashboard', name: 'restaurant-dashboard', component: EmptySlot, meta: { title: 'Главная' } },
      { path: 'orders', name: 'restaurant-orders-tab', component: EmptySlot, meta: { title: 'Заказы' } },
      { path: 'orders/delivery', name: 'restaurant-orders-delivery', component: EmptySlot, meta: { title: 'Основная поставка' } },
      { path: 'orders/supplier/:supplierId', name: 'restaurant-orders-supplier', component: EmptySlot, meta: { title: 'Поставщик' } },
      { path: 'orders/history', name: 'restaurant-orders-history', component: EmptySlot, meta: { title: 'История заказов' } },
      { path: 'info', name: 'restaurant-info', component: EmptySlot, meta: { title: 'Важная информация' } },
      { path: 'surveys', name: 'restaurant-surveys', component: EmptySlot, meta: { title: 'Опросы' } },
      { path: 'stock', name: 'restaurant-stock', component: EmptySlot, meta: { title: 'Сбор остатков' } },
      { path: 'warehouse-stock', name: 'restaurant-warehouse-stock', component: EmptySlot, meta: { title: 'Остатки склада' } },
      { path: 'scanner', name: 'restaurant-scanner', component: EmptySlot, meta: { title: 'Сканер товаров' } },
      { path: 'profile', name: 'restaurant-profile', component: EmptySlot, meta: { title: 'Профиль' } },
    ],
  },
  {
    path: '/restaurant/cabinet',
    redirect: '/restaurant',
  },
  {
    path: '/restaurant/home',
    redirect: '/restaurant',
  },
  {
    path: '/restaurant/order',
    redirect: '/restaurant/orders/delivery',
  },
  {
    path: '/ro',
    redirect: '/restaurant/login',
  },
  {
    path: '/supplier-order',
    redirect: to => {
      const supplierId = to.query?.supplier;
      if (supplierId) {
        return { name: 'restaurant-orders-supplier', params: { supplierId: String(supplierId) } };
      }
      return { name: 'restaurant-orders-tab' };
    },
  },
  {
    path: '/search-cards',
    name: 'search-cards',
    component: () => import('@/views/CardsSearchView.vue'),
    meta: { title: 'Поиск карточек' },
  },
  {
    path: '/telegram-link',
    name: 'telegram-link',
    component: () => import('@/views/TelegramLinkView.vue'),
    meta: { title: 'Привязка Telegram' },
  },
  {
    path: '/data-rules',
    name: 'data-rules',
    component: () => import('@/views/DataRulesView.vue'),
    meta: { title: 'Правила использования и данные' },
  },
  {
    path: '/download',
    name: 'robot-download',
    component: () => import('@/views/RobotDownloadView.vue'),
    meta: { title: 'Скачать 1C Robot Pro' },
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
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/views/NotFoundView.vue'),
    meta: { title: 'Страница не найдена' },
  },
];

export const router = createRouter({
  history: createWebHistory(),
  routes,
});

// Перезагрузка страницы при ошибке загрузки модулей (после деплоя новой версии)
router.onError((error, to) => {
  const msg = error?.message || '';
  if (
    msg.includes('Failed to fetch dynamically imported module') ||
    msg.includes('Unable to preload CSS') ||
    msg.includes('Importing a module script failed') ||
    msg.includes('error loading dynamically imported module')
  ) {
    // Перезагружаем на целевую страницу, чтобы получить свежие файлы
    window.location.href = to?.fullPath || window.location.href;
  }
});

router.afterEach((to) => {
  const pageTitle = to.meta.title;
  document.title = pageTitle ? `${pageTitle} - ${APP_TITLE}` : APP_TITLE;
});

const NAV_MODULES = ['order', 'history', 'plan-fact', 'planning', 'analytics', 'calendar', 'analysis', 'restaurant-sales', 'database', 'delivery-schedule', 'shelf-life', 'pricing', 'tenders', 'marketing', 'pallet-calc', 'stock-collection', 'deficit', 'distribution', 'corrections', 'chat', 'restaurant-orders', 'surveys', 'supplier-orders', 'truck-loading', 'tasks'];

router.beforeEach((to) => {
  const userStore = useUserStore();
  if (!userStore.currentUser) {
    userStore.restoreSession();
  }
  if (to.meta.requiresAuth && !userStore.isAuthenticated) {
    return { name: 'home', query: { showLogin: 'true', redirect: to.fullPath } };
  }
  if (to.meta.requiresAdmin && userStore.currentUser?.role !== 'admin') {
    const first = NAV_MODULES.find(m => userStore.hasAccess(m, 'view'));
    return first ? { name: first } : { name: 'home' };
  }
  // Модульная проверка прав
  if (to.meta.module && !userStore.hasAccess(to.meta.module, 'view')) {
    // Редирект на первый доступный модуль
    const first = NAV_MODULES.find(m => userStore.hasAccess(m, 'view'));
    return first ? { name: first } : { name: 'home' };
  }
});
