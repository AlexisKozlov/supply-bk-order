# Фронтенд

Фронтенд написан на Vue 3 без TypeScript. Состояние хранится в Pinia, страницы подключаются через Vue Router, сборка идёт через Vite.

## Вход в приложение

- [src/main.js](/var/www/bk-calc/src/main.js) — создаёт приложение, подключает Pinia, router, стили и глобальную обработку ошибок.
- [src/App.vue](/var/www/bk-calc/src/App.vue) — верхний контейнер приложения.
- [src/router/index.js](/var/www/bk-calc/src/router/index.js) — маршруты, заголовки страниц и проверка прав на вход.
- [src/layouts/AppLayout.vue](/var/www/bk-calc/src/layouts/AppLayout.vue) — основной каркас защищённой части портала.

## Страницы

Страницы лежат в `src/views/`.

Главные разделы:

- `HomeView.vue` — главная и вход сотрудников;
- `OrderView.vue` — новый основной заказ;
- `HistoryView.vue` — история заказов и планов;
- `PlanFactView.vue` и `PaymentsView.vue` — поставки и оплаты;
- `PlanningView.vue` — планирование;
- `AnalyticsView.vue`, `DashboardView.vue`, `AnalysisView.vue`, `CalendarView.vue` — аналитика и отчёты;
- `DatabaseView.vue` — база товаров, поставщиков и справочников;
- `DeliveryScheduleView.vue` — график доставки ресторанов;
- `PricingView.vue` — цены и ПСЦ;
- `TendersView.vue`, `TenderDetailView.vue` — тендеры;
- `MarketingView.vue`, `MarketingDetailView.vue` — маркетинг;
- `RestaurantCabinetView.vue` — кабинет ресторана;
- `RestaurantOrdersManagerView.vue` — управление заказами ресторанов;
- `SupplierOrdersHubView.vue` — заявки поставщикам;
- `TruckLoadingView.vue` — загрузка машин;
- `TelegramAdminView.vue`, `TelegramLinkView.vue` — Telegram;
- `AdminView.vue` — админ-панель.

Публичные страницы без обычной авторизации:

- `/search-cards` — поиск карточек;
- `/stock-form/:token` — форма сбора остатков;
- `/deficit-form/:token` — форма дефицита;
- `/restaurant/login` и `/restaurant` — вход и кабинет ресторана;
- `/telegram-link` — привязка Telegram;
- `/data-rules` — правила использования данных;
- `/download` — скачивание 1C Robot Pro.

## Stores

Stores лежат в `src/stores/`.

Важные stores:

- [orderStore.js](/var/www/bk-calc/src/stores/orderStore.js) — основной заказ, позиции, undo/redo, загрузка заказа в форму, аудит;
- [userStore.js](/var/www/bk-calc/src/stores/userStore.js) — пользователь, сессия, роли, права, техработы, скрытые модули;
- [historyStore.js](/var/www/bk-calc/src/stores/historyStore.js) — история заказов и планов;
- [restaurantOrderStore.js](/var/www/bk-calc/src/stores/restaurantOrderStore.js) — кабинет ресторанов и админские операции по ресторанным заказам;
- [supplierOrderStore.js](/var/www/bk-calc/src/stores/supplierOrderStore.js) — заявки поставщикам;
- [truckLoadingStore.js](/var/www/bk-calc/src/stores/truckLoadingStore.js) — загрузка машин;
- [supplierStore.js](/var/www/bk-calc/src/stores/supplierStore.js) — поставщики;
- [restaurantStore.js](/var/www/bk-calc/src/stores/restaurantStore.js) — рестораны и график доставки;
- [notificationStore.js](/var/www/bk-calc/src/stores/notificationStore.js) — уведомления и broadcast-сообщения;
- [draftStore.js](/var/www/bk-calc/src/stores/draftStore.js) — локальные черновики и очередь синхронизации;
- [toastStore.js](/var/www/bk-calc/src/stores/toastStore.js) — всплывающие сообщения.

## Общие библиотеки

Файлы лежат в `src/lib/`.

Ключевые:

- [apiClient.js](/var/www/bk-calc/src/lib/apiClient.js) — единый клиент API;
- [calculations.js](/var/www/bk-calc/src/lib/calculations.js) — формулы расчёта основного заказа;
- [saveOrder.js](/var/www/bk-calc/src/lib/saveOrder.js) — сохранение заказа, diff и уведомления;
- [legalEntities.js](/var/www/bk-calc/src/lib/legalEntities.js) — группы юрлиц на фронтенде;
- [analytics.js](/var/www/bk-calc/src/lib/analytics.js) — аналитика и прогнозы;
- [deficitAllocator.js](/var/www/bk-calc/src/lib/deficitAllocator.js) — распределение дефицита;
- [calendar.js](/var/www/bk-calc/src/lib/calendar.js) — данные календаря;
- [excelExport.js](/var/www/bk-calc/src/lib/excelExport.js) — Excel-выгрузки;
- [truckLoadingExport.js](/var/www/bk-calc/src/lib/truckLoadingExport.js) — экспорт загрузки машин;
- файлы `*Import.js` — импорты остатков, сроков годности, продаж и других данных.

## Компоненты

Компоненты лежат в `src/components/`.

Группы:

- `components/order/` — таблица и строки основного заказа;
- `components/modals/` — модальные окна;
- `components/ui/` — маленькие общие UI-компоненты;
- `components/restaurant/` — компоненты ресторанного кабинета;
- `components/marketing/` — маркетинговые виджеты.

## Стили

Основные CSS-файлы:

- [src/assets/style.css](/var/www/bk-calc/src/assets/style.css);
- [src/assets/components.css](/var/www/bk-calc/src/assets/components.css);
- [src/assets/compact.css](/var/www/bk-calc/src/assets/compact.css).

CSS-препроцессоров и UI-фреймворков нет.
