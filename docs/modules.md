# Бизнес-модули

Этот файл описывает основные разделы портала и где искать их код.

## Основной заказ

Назначение: формирование заказа поставщику с расчётами, буфером, остатками, транзитом, ручными правками, историей и аудитом.

Фронтенд:

- [src/views/OrderView.vue](/var/www/bk-calc/src/views/OrderView.vue)
- [src/stores/orderStore.js](/var/www/bk-calc/src/stores/orderStore.js)
- [src/components/order/OrderTable.vue](/var/www/bk-calc/src/components/order/OrderTable.vue)
- [src/components/order/OrderRow.vue](/var/www/bk-calc/src/components/order/OrderRow.vue)
- [src/lib/calculations.js](/var/www/bk-calc/src/lib/calculations.js)
- [src/lib/saveOrder.js](/var/www/bk-calc/src/lib/saveOrder.js)

Бэкенд:

- [api/includes/rpc.php](/var/www/bk-calc/api/includes/rpc.php)
- REST-таблицы `orders`, `order_items`, `plans`, `item_order`, `audit_log`.

## История и планирование

Назначение: просмотр сохранённых заказов, планов, повторное открытие, удаление, фильтры и пагинация.

Фронтенд:

- [src/views/HistoryView.vue](/var/www/bk-calc/src/views/HistoryView.vue)
- [src/views/PlanningView.vue](/var/www/bk-calc/src/views/PlanningView.vue)
- [src/stores/historyStore.js](/var/www/bk-calc/src/stores/historyStore.js)
- [src/stores/draftStore.js](/var/www/bk-calc/src/stores/draftStore.js)

## Поставки, оплаты и акты

Назначение: план-факт поставок, отметки получения, оплаты поставщикам, акты.

Фронтенд:

- [src/views/PlanFactView.vue](/var/www/bk-calc/src/views/PlanFactView.vue)
- [src/views/PaymentsView.vue](/var/www/bk-calc/src/views/PaymentsView.vue)

Бэкенд:

- REST-таблицы `orders`, `supplier_payments`;
- загрузка актов в [api/includes/uploads.php](/var/www/bk-calc/api/includes/uploads.php).

## База товаров и поставщиков

Назначение: управление товарами, поставщиками, карточками, справочниками и сортировками.

Фронтенд:

- [src/views/DatabaseView.vue](/var/www/bk-calc/src/views/DatabaseView.vue)
- [src/stores/supplierStore.js](/var/www/bk-calc/src/stores/supplierStore.js)

Бэкенд:

- REST-таблицы `products`, `suppliers`, `cards`, `item_order`;
- быстрый поиск [api/includes/search.php](/var/www/bk-calc/api/includes/search.php).

## Аналитика, календарь и отчёты

Назначение: аналитика заказов, прогнозы, сезонность, календарь поставок и отчётные страницы.

Фронтенд:

- [src/views/AnalyticsView.vue](/var/www/bk-calc/src/views/AnalyticsView.vue)
- [src/views/DashboardView.vue](/var/www/bk-calc/src/views/DashboardView.vue)
- [src/views/AnalysisView.vue](/var/www/bk-calc/src/views/AnalysisView.vue)
- [src/views/CalendarView.vue](/var/www/bk-calc/src/views/CalendarView.vue)
- [src/lib/analytics.js](/var/www/bk-calc/src/lib/analytics.js)
- [src/lib/calendar.js](/var/www/bk-calc/src/lib/calendar.js)

## Цены и ПСЦ

Назначение: ценовые соглашения, товары с ценами, НДС, валюта, история цен, файлы ПСЦ.

Фронтенд:

- [src/views/PricingView.vue](/var/www/bk-calc/src/views/PricingView.vue)

Бэкенд:

- REST-таблицы `price_agreements`, `product_prices`, `price_history`;
- файлы ПСЦ в [api/includes/uploads.php](/var/www/bk-calc/api/includes/uploads.php);
- миграции `20260410_deposit_prices.sql` и старые pricing-миграции в `api/migrations/`.

## Ресторанный кабинет и заказы ресторанов

Назначение: отдельный кабинет ресторана, заказы по датам, история, сканер, важная информация, опросы, сбор остатков.

Фронтенд:

- [src/views/RestaurantOrderLoginView.vue](/var/www/bk-calc/src/views/RestaurantOrderLoginView.vue)
- [src/views/RestaurantCabinetView.vue](/var/www/bk-calc/src/views/RestaurantCabinetView.vue)
- [src/views/RestaurantOrdersManagerView.vue](/var/www/bk-calc/src/views/RestaurantOrdersManagerView.vue)
- [src/views/RestaurantCabinetManagerView.vue](/var/www/bk-calc/src/views/RestaurantCabinetManagerView.vue)
- [src/views/RestaurantReportView.vue](/var/www/bk-calc/src/views/RestaurantReportView.vue)
- [src/views/RestaurantUnknownBarcodesView.vue](/var/www/bk-calc/src/views/RestaurantUnknownBarcodesView.vue)
- [src/stores/restaurantOrderStore.js](/var/www/bk-calc/src/stores/restaurantOrderStore.js)

Бэкенд:

- [api/includes/restaurant_orders.php](/var/www/bk-calc/api/includes/restaurant_orders.php)
- таблицы с префиксом `ro_`;
- миграции `20260405_restaurant_orders.sql`, `20260409_ro_stock_balances.sql`, `20260410_ro_audit_log.sql`, `20260423_ro_module_settings.sql`, `20260427_ro_cabinet_info.sql` и другие.

## Заявки поставщикам

Назначение: ресторан оформляет заявки отдельным поставщикам, администратор управляет поставщиками, графиком, дедлайнами, шаблонами и сводками.

Фронтенд:

- [src/views/SupplierOrdersHubView.vue](/var/www/bk-calc/src/views/SupplierOrdersHubView.vue)
- [src/views/SupplierOrderFormView.vue](/var/www/bk-calc/src/views/SupplierOrderFormView.vue)
- [src/views/SupplierOrdersManagerView.vue](/var/www/bk-calc/src/views/SupplierOrdersManagerView.vue)
- [src/stores/supplierOrderStore.js](/var/www/bk-calc/src/stores/supplierOrderStore.js)

Бэкенд:

- [api/includes/supplier_orders.php](/var/www/bk-calc/api/includes/supplier_orders.php)
- таблицы с префиксом `so_`;
- миграции `20260406_supplier_orders.sql`, `20260407_so_admin_qty.sql`, `20260416_so_close_delivery_day.sql`, `20260424_so_temporary_schedules.sql` и другие.

## Загрузка машин

Назначение: распределение заказов ресторанов по машинам, контроль паллет и веса, сохранение и подтверждение плана.

Фронтенд:

- [src/views/TruckLoadingView.vue](/var/www/bk-calc/src/views/TruckLoadingView.vue)
- [src/stores/truckLoadingStore.js](/var/www/bk-calc/src/stores/truckLoadingStore.js)
- [src/lib/truckLoadingExport.js](/var/www/bk-calc/src/lib/truckLoadingExport.js)

Бэкенд:

- [api/includes/truck_loading.php](/var/www/bk-calc/api/includes/truck_loading.php)
- таблицы с префиксом `tl_`;
- миграция `20260408_truck_loading.sql`.

## Сбор остатков и дефицит

Назначение: сбор остатков от ресторанов, расчёт потребности и распределение дефицитного товара.

Фронтенд:

- [src/views/StockCollectionView.vue](/var/www/bk-calc/src/views/StockCollectionView.vue)
- [src/views/StockFormView.vue](/var/www/bk-calc/src/views/StockFormView.vue)
- [src/views/DeficitView.vue](/var/www/bk-calc/src/views/DeficitView.vue)
- [src/views/DeficitFormView.vue](/var/www/bk-calc/src/views/DeficitFormView.vue)
- [src/lib/deficitAllocator.js](/var/www/bk-calc/src/lib/deficitAllocator.js)

Бэкенд:

- RPC в [api/includes/rpc.php](/var/www/bk-calc/api/includes/rpc.php);
- таблицы `stock_collections`, `stock_collection_*`, `deficit_*`.

## Тендеры, маркетинг, протоколы

Назначение: дополнительные рабочие процессы отдела закупок.

Фронтенд:

- `TendersView.vue`, `TenderDetailView.vue`;
- `MarketingView.vue`, `MarketingDetailView.vue`;
- `MeetingProtocolsView.vue`, `MeetingProtocolDetailView.vue`.

Бэкенд:

- REST-таблицы `tenders`, `tender_*`, `marketing_*`, `recipes`, `meeting_protocols`;
- загрузки файлов через [api/includes/uploads.php](/var/www/bk-calc/api/includes/uploads.php).

## Telegram

Назначение: уведомления, подписки, команды, привязка сотрудников и ресторанов.

Файлы:

- [api/telegram_bot.php](/var/www/bk-calc/api/telegram_bot.php)
- [api/includes/bot_*.php](/var/www/bk-calc/api/includes)
- [api/cron_telegram.php](/var/www/bk-calc/api/cron_telegram.php)
- [api/update_telegram_commands.php](/var/www/bk-calc/api/update_telegram_commands.php)
- [src/views/TelegramAdminView.vue](/var/www/bk-calc/src/views/TelegramAdminView.vue)
- [src/views/TelegramLinkView.vue](/var/www/bk-calc/src/views/TelegramLinkView.vue)

## 1C Robot

Есть два связанных подпроекта:

- [1C_Robot_Pro](/var/www/bk-calc/1C_Robot_Pro) — desktop-приложение/робот;
- [1c_robot_web](/var/www/bk-calc/1c_robot_web) — веб-сервис для подготовки и скачивания файлов.

Подробности лежат в их собственных README.
