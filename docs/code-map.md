# Карта кода

Этот документ помогает быстро понять, где искать нужную логику.

## Корень проекта

- [package.json](/var/www/bk-calc/package.json) — npm-команды и зависимости.
- [vite.config.js](/var/www/bk-calc/vite.config.js) — Vite, PWA, чанки сборки.
- [index.html](/var/www/bk-calc/index.html) — HTML-точка входа фронтенда.
- [AGENTS.md](/var/www/bk-calc/AGENTS.md) — рабочие инструкции для Codex.
- [README.md](/var/www/bk-calc/README.md) — вход в документацию.

## Фронтенд: вход и каркас

- [src/main.js](/var/www/bk-calc/src/main.js) — создание Vue-приложения, Pinia, router, глобальные ошибки.
- [src/App.vue](/var/www/bk-calc/src/App.vue) — главный контейнер, техработы, баннер недоступности сервера.
- [src/router/index.js](/var/www/bk-calc/src/router/index.js) — маршруты, заголовки страниц, проверка доступа.
- [src/layouts/AppLayout.vue](/var/www/bk-calc/src/layouts/AppLayout.vue) — основная оболочка авторизованной части.

## Фронтенд: состояние

- [src/stores/userStore.js](/var/www/bk-calc/src/stores/userStore.js) — пользователь, сессия, роли, права, техработы.
- [src/stores/orderStore.js](/var/www/bk-calc/src/stores/orderStore.js) — основной заказ.
- [src/stores/historyStore.js](/var/www/bk-calc/src/stores/historyStore.js) — история.
- [src/stores/restaurantOrderStore.js](/var/www/bk-calc/src/stores/restaurantOrderStore.js) — кабинет ресторанов.
- [src/stores/supplierOrderStore.js](/var/www/bk-calc/src/stores/supplierOrderStore.js) — заявки поставщикам.
- [src/stores/truckLoadingStore.js](/var/www/bk-calc/src/stores/truckLoadingStore.js) — загрузка машин.
- [src/stores/restaurantStore.js](/var/www/bk-calc/src/stores/restaurantStore.js) — рестораны и график доставки.
- [src/stores/supplierStore.js](/var/www/bk-calc/src/stores/supplierStore.js) — поставщики.
- [src/stores/notificationStore.js](/var/www/bk-calc/src/stores/notificationStore.js) — уведомления.
- [src/stores/draftStore.js](/var/www/bk-calc/src/stores/draftStore.js) — черновики и очередь синхронизации.

## Фронтенд: общие библиотеки

- [src/lib/apiClient.js](/var/www/bk-calc/src/lib/apiClient.js) — единая работа с API.
- [src/lib/calculations.js](/var/www/bk-calc/src/lib/calculations.js) — расчёты заказа.
- [src/lib/saveOrder.js](/var/www/bk-calc/src/lib/saveOrder.js) — создание/обновление заказа.
- [src/lib/legalEntities.js](/var/www/bk-calc/src/lib/legalEntities.js) — юрлица на фронтенде.
- [src/lib/analytics.js](/var/www/bk-calc/src/lib/analytics.js) — аналитика.
- [src/lib/deficitAllocator.js](/var/www/bk-calc/src/lib/deficitAllocator.js) — распределение дефицита.
- [src/lib/excelExport.js](/var/www/bk-calc/src/lib/excelExport.js) — Excel-выгрузки.
- [src/lib/truckLoadingExport.js](/var/www/bk-calc/src/lib/truckLoadingExport.js) — выгрузка загрузки машин.
- [src/lib/*Import.js](/var/www/bk-calc/src/lib) — импорты данных.

## Бэкенд: вход и общие функции

- [api/index.php](/var/www/bk-calc/api/index.php) — API-роутер.
- [api/includes/helpers.php](/var/www/bk-calc/api/includes/helpers.php) — авторизация, RBAC, Telegram, аудит, фильтры.
- [api/includes/legal_entities.php](/var/www/bk-calc/api/includes/legal_entities.php) — юрлица и группы.
- [api/includes/crud.php](/var/www/bk-calc/api/includes/crud.php) — REST CRUD.
- [api/includes/rpc.php](/var/www/bk-calc/api/includes/rpc.php) — RPC-действия.

## Бэкенд: специализированные модули

- [api/includes/restaurant_orders.php](/var/www/bk-calc/api/includes/restaurant_orders.php) — ресторанный кабинет.
- [api/includes/supplier_orders.php](/var/www/bk-calc/api/includes/supplier_orders.php) — заявки поставщикам.
- [api/includes/truck_loading.php](/var/www/bk-calc/api/includes/truck_loading.php) — загрузка машин.
- [api/includes/uploads.php](/var/www/bk-calc/api/includes/uploads.php) — загрузка/скачивание файлов.
- [api/includes/search.php](/var/www/bk-calc/api/includes/search.php) — поиск товаров.
- [api/includes/ocr.php](/var/www/bk-calc/api/includes/ocr.php) — распознавание изображений.

## Telegram

- [api/telegram_bot.php](/var/www/bk-calc/api/telegram_bot.php) — основной бот.
- `api/includes/bot_*.php` — части логики бота.
- [api/cron_telegram.php](/var/www/bk-calc/api/cron_telegram.php) — cron-задачи Telegram.
- [api/update_telegram_commands.php](/var/www/bk-calc/api/update_telegram_commands.php) — установка команд бота.

## 1C Robot

- [1C_Robot_Pro](/var/www/bk-calc/1C_Robot_Pro) — desktop-робот.
- [1c_robot_web](/var/www/bk-calc/1c_robot_web) — веб-сервис вокруг робота.

## Где искать по задаче

| Задача | Начать с |
| --- | --- |
| Не работает вход сотрудника | `userStore.js`, `apiClient.js`, `rpc.php`, `helpers.php` |
| Не виден раздел меню | `router/index.js`, `userStore.js`, `helpers.php`, `crud.php` |
| Не видны данные по юрлицу | `legalEntities.js`, `legal_entities.php`, SQL-фильтры |
| Ошибка основного заказа | `OrderView.vue`, `orderStore.js`, `calculations.js`, `saveOrder.js`, `rpc.php` |
| Ошибка ресторанного кабинета | `RestaurantCabinetView.vue`, `restaurantOrderStore.js`, `restaurant_orders.php` |
| Ошибка заявок поставщикам | `SupplierOrdersHubView.vue`, `supplierOrderStore.js`, `supplier_orders.php` |
| Ошибка загрузки машин | `TruckLoadingView.vue`, `truckLoadingStore.js`, `truck_loading.php` |
| Ошибка Telegram | `TelegramAdminView.vue`, `telegram_bot.php`, `helpers.php`, cron-файлы |
| Не загружается файл | `uploads.php`, права на `api/uploads/`, лимиты PHP |
| Нужно добавить поле в таблицу | `migrations/`, `crud.php`, нужная страница/store |
