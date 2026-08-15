import { createRouter, createWebHistory } from 'vue-router';
import { useUserStore, loadRbacConfig } from '@/stores/userStore.js';
import { useRestaurantOrderStore } from '@/stores/restaurantOrderStore.js';
import { syncPwaIdentity } from '@/lib/pwaManifest.js';
import { isChunkLoadError } from '@/lib/appUpdateNotify.js';

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
      { path: 'reconciliation', name: 'reconciliation', component: () => import('@/views/ReconciliationView.vue'), meta: { title: 'Сверка 1С/УТ', module: 'reconciliation' } },
      // Помощник читает данные раздела «Анализ» — и меню, и сервер требуют модуль
      // analysis. Без module тут страница открывалась по прямой ссылке любому.
      { path: 'assistant', name: 'assistant', component: () => import('@/views/AssistantView.vue'), meta: { title: 'ИИ-помощник', module: 'analysis' } },
      { path: 'restaurant-sales', name: 'restaurant-sales', component: () => import('@/views/RestaurantSalesView.vue'), meta: { title: 'Реализация', module: 'restaurant-sales' } },
      { path: 'database', name: 'database', component: () => import('@/views/DatabaseView.vue'), meta: { title: 'База товаров', module: 'database' } },
      { path: 'delivery-schedule', name: 'delivery-schedule', component: () => import('@/views/DeliveryScheduleView.vue'), meta: { title: 'График доставки', module: 'delivery-schedule' } },
      { path: 'supplier-schedule', name: 'supplier-schedule', component: () => import('@/views/SupplierScheduleView.vue'), meta: { title: 'График поставок', module: 'supplier-schedule' } },
      { path: 'shelf-life', name: 'shelf-life', component: () => import('@/views/ShelfLifeView.vue'), meta: { title: 'Сроки годности', module: 'shelf-life' } },
      { path: 'shelf-life/analytics', name: 'shelf-life-analytics', component: () => import('@/views/ShelfLifeAnalyticsView.vue'), meta: { title: 'Аналитика ячеек', module: 'shelf-life' } },
      { path: 'pricing', name: 'pricing', component: () => import('@/views/PricingView.vue'), meta: { title: 'Цены и ПСЦ', module: 'pricing' } },
      { path: 'admin', name: 'admin', component: () => import('@/views/AdminView.vue'), meta: { requiresAdmin: true, title: 'Админ-панель' } },
      { path: 'ui-kit', name: 'ui-kit', component: () => import('@/views/UiKitView.vue'), meta: { requiresAdmin: true, title: 'UI Kit — дизайн-система' } },
      { path: 'telegram-admin', name: 'telegram-admin', component: () => import('@/views/TelegramAdminView.vue'), meta: { title: 'Telegram-бот', module: 'telegram' } },
      { path: 'suppliers', redirect: { name: 'database', query: { tab: 'suppliers' } } },
      { path: 'analogs', name: 'analogs', component: () => import('@/views/AnalogsView.vue'), meta: { title: 'Аналоги', module: 'analogs' } },
      { path: 'novelties', name: 'novelties', component: () => import('@/views/NoveltiesView.vue'), meta: { title: 'Новинки', module: 'novelties' } },
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
      { path: 'dashboard', name: 'dashboard', component: () => import('@/views/DashboardView.vue'), meta: { title: 'Дашборд', module: 'dashboard' } },
      { path: 'settings', name: 'user-settings', component: () => import('@/views/UserSettingsView.vue'), meta: { title: 'Настройки' } },
      { path: 'import', name: 'import', component: () => import('@/views/ImportView.vue'), meta: { title: 'Импорт данных', module: 'analysis' } },
      { path: 'corrections', name: 'corrections', component: () => import('@/views/CorrectionsView.vue'), meta: { title: 'Корректировки', module: 'corrections' } },
      { path: 'chat', name: 'chat', component: () => import('@/views/ChatView.vue'), meta: { title: 'Чат с ресторанами', module: 'chat' } },
      { path: 'restaurant-cabinet-manager', name: 'restaurant-cabinet-manager', component: () => import('@/views/RestaurantCabinetManagerView.vue'), meta: { title: 'Кабинеты ресторанов', module: 'restaurant-orders' } },
      { path: 'restaurant-orders', name: 'restaurant-orders', component: () => import('@/views/RestaurantOrdersManagerView.vue'), meta: { title: 'Заказы ресторанов', module: 'restaurant-orders' } },
      { path: 'supply-assistant', name: 'supply-assistant', component: () => import('@/views/SupplyAssistantManagerView.vue'), meta: { title: 'Сбор заказа осн. поставки', module: 'supply-assistant' } },
      { path: 'restaurant-report', name: 'restaurant-report', component: () => import('@/views/RestaurantReportView.vue'), meta: { title: 'Отчёт по заказам', module: 'restaurant-orders' } },
      { path: 'restaurant-unknown-barcodes', name: 'restaurant-unknown-barcodes', component: () => import('@/views/RestaurantUnknownBarcodesView.vue'), meta: { title: 'Штрихкоды', module: 'restaurant-orders' } },
      { path: 'surveys', name: 'surveys', component: () => import('@/views/SurveysView.vue'), meta: { title: 'Опросы', module: 'surveys' } },
      { path: 'supplier-orders', name: 'supplier-orders', component: () => import('@/views/SupplierOrdersHubView.vue'), meta: { title: 'Заявки поставщикам', module: 'supplier-orders' } },
      { path: 'own-production', name: 'own-production', component: () => import('@/views/OwnProductionView.vue'), meta: { title: 'Собственное производство', module: 'own-production' } },
      { path: 'cabinet-links', name: 'cabinet-links', component: () => import('@/views/CabinetLinksView.vue'), meta: { title: 'Ссылки кабинета', module: 'supplier-orders' } },
      { path: 'truck-loading', name: 'truck-loading', component: () => import('@/views/TruckLoadingView.vue'), meta: { title: 'Загрузка машин', module: 'truck-loading' } },
      { path: 'protocols', name: 'protocols', component: () => import('@/views/MeetingProtocolsView.vue'), meta: { title: 'Протоколы совещаний', module: 'protocols' } },
      { path: 'protocols/:id', name: 'protocol-detail', component: () => import('@/views/MeetingProtocolDetailView.vue'), meta: { title: 'Протокол', module: 'protocols' } },
      { path: 'tasks', name: 'tasks', component: () => import('@/views/TasksView.vue'), meta: { title: 'Задачи', module: 'tasks' } },
      { path: 'handover', name: 'handover', component: () => import('@/views/HandoverView.vue'), meta: { title: 'Передача дел', module: 'handover' } },
      { path: 'keg-returns', name: 'keg-returns', component: () => import('@/views/KegReturnsView.vue'), meta: { title: 'Возврат кег', module: 'keg-returns' } },
      { path: 'keg-returns/schedule', name: 'keg-returns-schedule', component: () => import('@/views/KegReturnsScheduleView.vue'), meta: { title: 'График возврата кег', module: 'keg-returns' } },
      { path: 'tit-requests', name: 'tit-requests', component: () => import('@/views/TitRequestsView.vue'), meta: { title: 'Заявка на пропуск', module: 'tit-requests' } },
      // Без module в meta — иначе страница отказа сама себя запретила бы.
      { path: 'no-access', name: 'no-access', component: () => import('@/views/NoAccessView.vue'), meta: { title: 'Нет доступа' } },
    ],
  },
  {
    path: '/deficit-form/:token',
    name: 'deficit-form',
    component: () => import('@/views/DeficitFormView.vue'),
    meta: { title: 'Остатки ресторана' },
  },
  {
    path: '/restaurant/login',
    name: 'restaurant-order-login',
    component: () => import('@/views/RestaurantOrderLoginView.vue'),
    meta: { title: 'Заказы — Вход' },
  },
  {
    path: '/restaurant/verify-email',
    name: 'restaurant-verify-email',
    component: () => import('@/views/RestaurantVerifyEmail.vue'),
    meta: { title: 'Подтверждение email' },
  },
  {
    path: '/restaurant/reset-password-by-email',
    name: 'restaurant-reset-password-by-email',
    component: () => import('@/views/RestaurantResetPasswordByEmail.vue'),
    meta: { title: 'Новый пароль ресторана' },
  },
  {
    path: '/forgot-password',
    name: 'ForgotPassword',
    component: () => import('@/views/ForgotPassword.vue'),
    meta: { title: 'Сброс пароля' },
  },
  {
    path: '/staff-forgot-password',
    name: 'StaffForgotPassword',
    component: () => import('@/views/StaffForgotPassword.vue'),
    meta: { title: 'Восстановление пароля' },
  },
  {
    path: '/staff-reset-password',
    name: 'StaffResetPassword',
    component: () => import('@/views/StaffResetPassword.vue'),
    meta: { title: 'Новый пароль' },
  },
  {
    path: '/verify-reset-code',
    name: 'VerifyResetCode',
    component: () => import('@/views/VerifyResetCode.vue'),
    meta: { title: 'Подтверждение кода' },
  },
  {
    path: '/reset-password',
    name: 'ResetPassword',
    component: () => import('@/views/ResetPassword.vue'),
    meta: { title: 'Новый пароль' },
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
      { path: 'orders/corrections', name: 'restaurant-orders-corrections', component: EmptySlot, meta: { title: 'Корректировки' } },
      { path: 'orders/assistant', name: 'restaurant-orders-assistant', component: EmptySlot, meta: { title: 'Сбор заказа' } },
      { path: 'orders/production', name: 'restaurant-orders-production', component: EmptySlot, meta: { title: 'Тесто (ПРЦ)' } },
      { path: 'info', name: 'restaurant-info', component: EmptySlot, meta: { title: 'Важная информация' } },
      { path: 'surveys', name: 'restaurant-surveys', component: EmptySlot, meta: { title: 'Опросы' } },
      { path: 'stock', name: 'restaurant-stock', component: EmptySlot, meta: { title: 'Сбор остатков' } },
      // Свой адрес у каждого сбора: ссылка из Telegram/push ведёт сразу в нужный,
      // а не в общий раздел, где ресторан видел только самый новый сбор.
      { path: 'stock/:collectionId', name: 'restaurant-stock-collection', component: EmptySlot, meta: { title: 'Сбор остатков' } },
      { path: 'warehouse-stock', name: 'restaurant-warehouse-stock', component: EmptySlot, meta: { title: 'Остатки склада' } },
      { path: 'novelties', name: 'restaurant-novelties', component: EmptySlot, meta: { title: 'Новинки' } },
      { path: 'guides', name: 'restaurant-guides', component: EmptySlot, meta: { title: 'Инструкции' } },
      // Своя ссылка у каждой инструкции — можно скинуть коллеге сразу на нужную тему.
      { path: 'guides/:guideId', name: 'restaurant-guide', component: EmptySlot, meta: { title: 'Инструкция' } },
      { path: 'contacts', name: 'restaurant-contacts', component: EmptySlot, meta: { title: 'Контакты поставщиков' } },
      { path: 'scanner', name: 'restaurant-scanner', component: EmptySlot, meta: { title: 'Сканер товаров' } },
      { path: 'profile', name: 'restaurant-profile', component: EmptySlot, meta: { title: 'Профиль' } },
      { path: 'keg-returns', name: 'restaurant-keg-returns', component: EmptySlot, meta: { title: 'Возврат кег' } },
      { path: 'reminders', name: 'restaurant-reminders', component: EmptySlot, meta: { title: 'Напоминания' } },
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
    meta: { title: 'Поиск карточек', requiresAnyAuth: true },
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

// При ошибке загрузки модулей после деплоя НЕ перезагружаем автоматически —
// это сбрасывает несохранённые данные пользователя. Показываем тост и баннер
// «Обновить», пользователь сам решит, когда перезагрузить.
router.onError((error) => {
  // Тексты ошибок разных браузеров держим в одном месте (appUpdateNotify.js).
  // Safari пишет просто «Load failed» — его ловит слушатель vite:preloadError
  // в main.js: здесь по такому тексту чанк от обрыва связи в guard не отличить.
  if (isChunkLoadError(error)) {
    // Импортируем динамически, чтобы не тащить toastStore в граф router'а
    import('@/lib/appUpdateNotify.js').then(m => m.notifyAppUpdateRequired()).catch(() => {});
  }
});

// Последние открытые разделы — их показывает главная под строкой поиска.
// Пишем здесь, а не в каждой странице: одно место, любой новый раздел
// попадает в список сам. Храним только имена маршрутов, не данные.
const RECENT_KEY = 'sd_recent_routes';
const RECENT_MAX = 8;

export function getRecentRoutes() {
  try {
    const raw = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]');
    return Array.isArray(raw) ? raw.filter(x => typeof x === 'string') : [];
  } catch { return []; }
}

function rememberRoute(name) {
  if (!name || name === 'home') return;
  try {
    const list = [name, ...getRecentRoutes().filter(n => n !== name)].slice(0, RECENT_MAX);
    localStorage.setItem(RECENT_KEY, JSON.stringify(list));
  } catch { /* приватный режим браузера — просто без истории */ }
}

router.afterEach((to) => {
  const pageTitle = to.meta.title;
  document.title = pageTitle ? `${pageTitle} - ${APP_TITLE}` : APP_TITLE;
  // Кабинет ресторана и портал закупок ставятся как разные приложения —
  // подставляем нужный манифест и иконку под текущий раздел.
  syncPwaIdentity(to.path);
  if (!to.path.startsWith('/restaurant')) rememberRoute(to.name);
});

router.beforeEach(async (to) => {
  const userStore = useUserStore();
  // Ждём первое восстановление сессии. Это надёжнее, чем гонка между
  // main.js и app.mount: useUserStore() здесь гарантированно использует
  // правильный pinia (injected через app.use), и await блокирует
  // навигацию до завершения validate_session.
  await userStore.restoreSession();
  // Подтягиваем актуальный RBAC-конфиг с сервера для всех авторизованных
  // сотрудников, а не только при открытии админки. Иначе у пользователя с
  // пустыми permissions доступ считается по устаревшему встроенному шаблону,
  // где нет выделенных модулей (keg-returns/reconciliation/tit-requests), и
  // они ошибочно недоступны. loadRbacConfig грузится один раз (кэш внутри).
  if (userStore.isAuthenticated) {
    await loadRbacConfig();
  }
  // Защита кабинета ресторана: пути /restaurant/* (кроме /restaurant/login)
  // требуют залогиненного ресторана. Сам токен живёт в HttpOnly-cookie и
  // из JS не виден, поэтому ориентируемся на кэш ресторана в сторе:
  // если ресторан сохранён — пускаем (cookie долетит к серверу), иначе
  // редиректим на форму входа. Серверная валидация делается в кабинете.
  if (to.path.startsWith('/restaurant/') && to.path !== '/restaurant/login' && to.path !== '/restaurant/reset-password' && to.path !== '/restaurant/verify-email' && to.path !== '/restaurant/reset-password-by-email') {
    const roStore = useRestaurantOrderStore();
    // Кэш ресторана мог пропасть (iOS чистит хранилище установленного
    // приложения), хотя сессия на сервере жива — спрашиваем сервер по cookie
    // и пускаем без повторного ввода пароля.
    if (!roStore.isAuthenticated && !(await roStore.restoreFromCookie())) {
      return { path: '/restaurant/login', query: { redirect: to.fullPath } };
    }
  } else if (to.path === '/restaurant') {
    const roStore = useRestaurantOrderStore();
    if (!roStore.isAuthenticated && !(await roStore.restoreFromCookie())) {
      return { path: '/restaurant/login' };
    }
  } else if (to.name === 'home' && !userStore.isAuthenticated && !to.query.showLogin && !to.query.redirect) {
    // Ресторан открыл главную портала, хотя вошёл как ресторан — уводим сразу
    // в кабинет. Так ведёт себя установленное приложение: оно всегда стартует
    // со своего адреса, и у тех, кто поставил на телефон портал (а не кабинет),
    // каждый запуск упирался в экран входа закупок.
    // Сотрудника не трогаем: у него userStore.isAuthenticated = true, а зайти
    // на портал специально можно по ссылке с ?showLogin=true.
    const roStore = useRestaurantOrderStore();
    if (roStore.isAuthenticated || await roStore.restoreFromCookie()) {
      return { path: '/restaurant' };
    }
  }
  if (to.meta.requiresAuth && !userStore.isAuthenticated) {
    return { name: 'home', query: { showLogin: 'true', redirect: to.fullPath } };
  }
  // Маршруты, доступные либо закупщику, либо ресторану. Анонимам — на главную закупок.
  if (to.meta.requiresAnyAuth) {
    const roStore = useRestaurantOrderStore();
    if (!userStore.isAuthenticated && !roStore.isAuthenticated) {
      return { name: 'home', query: { showLogin: 'true', redirect: to.fullPath } };
    }
  }
  // Нет прав — говорим об этом прямо. Раньше человека молча уносило на первый
  // доступный раздел: он жал ссылку из чата и оказывался на «Новом заказе»,
  // не понимая, портал сломался или так задумано.
  if (to.meta.requiresAdmin && userStore.currentUser?.role !== 'admin') {
    return { name: 'no-access', query: { section: 'admin' } };
  }
  // Модульная проверка прав
  if (to.meta.module && !userStore.hasAccess(to.meta.module, 'view')) {
    return { name: 'no-access', query: { section: to.meta.module } };
  }
});
