# Бизнес-сценарии

Этот документ описывает основные сценарии работы системы для разработчика.

## Вход сотрудника

1. Пользователь вводит email и пароль на главной.
2. Фронтенд вызывает `db.rpc('check_user_password')`.
3. PHP проверяет пароль, rate limit и согласие с правилами.
4. Сервер создаёт session token.
5. Фронтенд сохраняет пользователя и токен в localStorage.
6. Router начинает пускать пользователя в доступные разделы.

Код:

- [src/stores/userStore.js](/var/www/bk-calc/src/stores/userStore.js)
- [src/lib/apiClient.js](/var/www/bk-calc/src/lib/apiClient.js)
- [api/includes/rpc.php](/var/www/bk-calc/api/includes/rpc.php)
- [api/includes/helpers.php](/var/www/bk-calc/api/includes/helpers.php)

## Основной заказ

1. Пользователь выбирает юрлицо, поставщика, дату и параметры расчёта.
2. Фронтенд загружает товары, остатки, аналитику, цены и сортировку.
3. Расчёты выполняются в `src/lib/calculations.js`.
4. Пользователь редактирует позиции.
5. `orderStore` хранит состояние и undo/redo.
6. Сохранение идёт через `src/lib/saveOrder.js`.
7. На сервере вызывается `create_order` или `update_order`.
8. Записываются `orders`, `order_items`, аудит и уведомления.

Проверять при изменениях:

- расчёты;
- ручные правки;
- сохранение нового заказа;
- обновление существующего заказа;
- права `order`;
- юрлицо;
- историю.

## История заказа

1. Страница истории вызывает REST-запросы к `orders` и `plans`.
2. Для удаления используется RPC `delete_order`.
3. Для редактирования заказ открывается в `OrderView`.
4. Для защиты от одновременного редактирования используются `check_order_lock`, `heartbeat`, `unlock_order`.

Код:

- [src/views/HistoryView.vue](/var/www/bk-calc/src/views/HistoryView.vue)
- [src/stores/historyStore.js](/var/www/bk-calc/src/stores/historyStore.js)
- [src/views/OrderView.vue](/var/www/bk-calc/src/views/OrderView.vue)

## Импорт аналитики, реализации и сроков годности

1. Пользователь загружает файл или вставляет данные.
2. Фронтенд парсит файл в `src/lib/*Import.js` или внутри страницы.
3. Данные отправляются в RPC:
   - `replace_analysis_data`;
   - `replace_restaurant_sales`;
   - `replace_stock_malling`;
   - `save_warehouse_cells`.
4. Сервер заменяет соответствующие данные.
5. Telegram может отправить уведомление об обновлении.

Важно: такие операции часто удаляют старые данные по юрлицу/периоду и записывают новые.

## Цены и ПСЦ

1. Пользователь создаёт или редактирует соглашение.
2. Файл ПСЦ загружается через `/api/upload/psc`.
3. Цены импортируются через `import_prices` или `import_deposit_prices`.
4. Соглашение утверждается через `approve_agreement`.
5. Текущие цены читаются через `get_current_prices`.
6. Изменения цен пишутся в историю.

Код:

- [src/views/PricingView.vue](/var/www/bk-calc/src/views/PricingView.vue)
- [api/includes/uploads.php](/var/www/bk-calc/api/includes/uploads.php)
- [api/includes/rpc.php](/var/www/bk-calc/api/includes/rpc.php)

## Ресторанный кабинет

1. Ресторан входит по номеру/паролю или Telegram-ссылке.
2. Сервер выдаёт `ro_token`.
3. Фронтенд сохраняет токен и отправляет его как `X-RO-Token`.
4. Ресторан видит свои данные, доступные даты, товары, заказы и важную информацию.
5. Заказ отправляется через `/api/ro/submit-order`.
6. Сервер проверяет сессию, дату, дедлайн, кратность и доступ ресторана.
7. Администратор видит заказы в менеджерской странице.

Код:

- [src/views/RestaurantOrderLoginView.vue](/var/www/bk-calc/src/views/RestaurantOrderLoginView.vue)
- [src/views/RestaurantCabinetView.vue](/var/www/bk-calc/src/views/RestaurantCabinetView.vue)
- [src/stores/restaurantOrderStore.js](/var/www/bk-calc/src/stores/restaurantOrderStore.js)
- [api/includes/restaurant_orders.php](/var/www/bk-calc/api/includes/restaurant_orders.php)

## Заявки поставщикам

1. Ресторан открывает список поставщиков.
2. Сервер возвращает поставщиков, график, ближайшие даты и статус дедлайна.
3. Ресторан выбирает поставщика и дату.
4. Товары берутся из шаблона поставщика.
5. Ресторан отправляет заявку через `/api/so/submit-order`.
6. Администратор управляет графиками, дедлайнами, шаблонами и сводками.

Код:

- [src/stores/supplierOrderStore.js](/var/www/bk-calc/src/stores/supplierOrderStore.js)
- [api/includes/supplier_orders.php](/var/www/bk-calc/api/includes/supplier_orders.php)

## Загрузка машин

1. Пользователь выбирает дату.
2. Модуль получает ресторанные заказы на дату через `/api/tl/orders`.
3. Пользователь добавляет машины или выбирает сохранённый план.
4. Позиции распределяются вручную или через auto-assign.
5. План сохраняется через `/api/tl/plan`.
6. План можно подтвердить или снять подтверждение.

Код:

- [src/views/TruckLoadingView.vue](/var/www/bk-calc/src/views/TruckLoadingView.vue)
- [src/stores/truckLoadingStore.js](/var/www/bk-calc/src/stores/truckLoadingStore.js)
- [api/includes/truck_loading.php](/var/www/bk-calc/api/includes/truck_loading.php)

## Сбор остатков

1. Сотрудник создаёт сбор остатков.
2. Система создаёт продукты сбора и токены.
3. Рестораны открывают публичную форму или кабинет.
4. Ресторан отправляет остатки.
5. Сотрудник видит собранные данные и может использовать их в дефиците.

Код:

- [src/views/StockCollectionView.vue](/var/www/bk-calc/src/views/StockCollectionView.vue)
- [src/views/StockFormView.vue](/var/www/bk-calc/src/views/StockFormView.vue)
- RPC `sc_*` в [api/includes/rpc.php](/var/www/bk-calc/api/includes/rpc.php)
- `/api/ro/stock-collection-*` в [api/includes/restaurant_orders.php](/var/www/bk-calc/api/includes/restaurant_orders.php)

## Дефицит

1. Сотрудник выбирает товар и складской остаток.
2. Система берёт данные ресторанов, потребления и графика.
3. `deficitAllocator.js` рассчитывает распределение.
4. Результаты сохраняются в таблицы `deficit_*`.
5. Рестораны могут отправлять данные через публичную форму.

## Telegram

1. Сотрудник или ресторан привязывает Telegram.
2. Бот получает команды и сообщения через webhook.
3. Уведомления отправляются helper-функциями из PHP.
4. Админка Telegram управляет webhook, подписками, рассылками и статистикой.
5. Cron-задачи отправляют периодические уведомления.

Код:

- [api/telegram_bot.php](/var/www/bk-calc/api/telegram_bot.php)
- [api/includes/helpers.php](/var/www/bk-calc/api/includes/helpers.php)
- [src/views/TelegramAdminView.vue](/var/www/bk-calc/src/views/TelegramAdminView.vue)
- [api/cron_telegram.php](/var/www/bk-calc/api/cron_telegram.php)

## Отчёты об ошибках

1. Глобальные JS-ошибки ловятся в `src/main.js`.
2. Фронтенд отправляет `log_frontend_error`.
3. Пользователь может создать bug report через кнопку.
4. Админ видит обращения в `AdminView`.

Код:

- [src/main.js](/var/www/bk-calc/src/main.js)
- [src/components/BugReportButton.vue](/var/www/bk-calc/src/components/BugReportButton.vue)
- [src/views/AdminView.vue](/var/www/bk-calc/src/views/AdminView.vue)
- RPC `create_bug_report`, `get_bug_reports`, `reply_bug_report`.
