# Модель данных

Документ описывает основные сущности и связи. Полная структура задаётся миграциями в `migrations/` и `api/migrations/`.

## Юрлица

В проекте есть полные юрлица и группы:

| Группа | Юрлица |
| --- | --- |
| `BK_VM` | `ООО "Бургер БК"`, `ООО "Воглия Матта"` |
| `PS` | `ООО "Пицца Стар"` |

В старых и универсальных таблицах часто используется `legal_entity`. В новых модулях часто используется `legal_entity_group`.

Главные файлы:

- [src/lib/legalEntities.js](/var/www/bk-calc/src/lib/legalEntities.js)
- [api/includes/legal_entities.php](/var/www/bk-calc/api/includes/legal_entities.php)

## Пользователи и права

Основные таблицы:

- `users` — сотрудники портала;
- `sessions` или аналогичная таблица сессий, если создана миграциями;
- `portal_user_consents` — согласия с правилами;
- `settings` — системные настройки;
- `audit_log` — аудит действий.

В `users` важны:

- `email`;
- `name`;
- `password`;
- `role`;
- `display_role`;
- `legal_entities`;
- `permissions`;
- `telegram_chat_id`;
- `hidden_modules`.

Права описаны в [access-control.md](/var/www/bk-calc/docs/access-control.md).

## Основной заказ

Главные таблицы:

- `orders` — шапка заказа;
- `order_items` — позиции заказа;
- `plans` — планы;
- `item_order` — пользовательская сортировка товаров;
- `supplier_payments` — оплаты;
- `audit_log` — история действий.

Связь:

```text
orders.id -> order_items.order_id
orders.id -> supplier_payments.order_id
```

Важные поля `orders`:

- поставщик;
- юрлицо;
- дата доставки;
- автор;
- режим расчёта;
- файл акта;
- дата ТТН;
- дата получения.

## Товары, поставщики, рестораны

Справочные таблицы:

- `products`;
- `suppliers`;
- `restaurants`;
- `cards`;
- `delivery_schedule`;
- `hidden_analogs`;
- `product_adu`;
- `pallet_reference`.

Типовые связи:

```text
products.supplier -> suppliers.short_name/full_name
restaurants.legal_entity_group -> группа юрлиц
delivery_schedule.restaurant_id -> restaurants.id
```

Важно: в проекте встречается связь по текстовому названию поставщика, а не только по ID. При изменениях поставщиков нужно проверять старые данные.

## Аналитика и остатки

Таблицы:

- `analysis_data` — импортируемые аналитические данные;
- `stock_1c` — остатки из 1C;
- `stock_malling` — сроки годности/остатки Malling;
- `warehouse_cells` или связанные таблицы ячеек, созданные миграциями;
- `restaurant_sales` — реализация ресторанов;
- `report_exclusions` — исключения из отчётов.

Эти таблицы часто полностью заменяются импортом через RPC.

## Цены и ПСЦ

Таблицы:

- `price_agreements`;
- `product_prices`;
- `price_history`;
- файлы ПСЦ в `api/uploads/psc/`.

Связь:

```text
price_agreements.id -> product_prices.agreement_id
product_prices.id -> price_history.price_id
```

Типовой жизненный цикл соглашения:

```text
draft -> approved -> archived
```

## Ресторанный кабинет

Основные таблицы с префиксом `ro_`:

- `ro_users` — учётки ресторанов;
- `ro_sessions` — сессии/периоды заказа;
- `ro_session_dates` или связанные даты сессии;
- `ro_orders` — заказы ресторанов;
- `ro_order_items` — позиции заказов;
- `ro_templates` — шаблоны товаров;
- `ro_tg_tokens` — одноразовые Telegram-ссылки;
- `ro_telegram_subs` — подписки Telegram;
- `ro_audit_log` — аудит;
- `ro_stock_balances` — складские остатки для кабинета;
- `ro_cabinet_posts`, `ro_cabinet_post_files`, `ro_cabinet_post_restaurants` — важная информация в кабинете;
- таблицы неизвестных штрихкодов, созданные миграциями `ro_scan_unknown`.

Связь:

```text
ro_sessions.id -> ro_orders.session_id
ro_orders.id -> ro_order_items.order_id
ro_users.restaurant_number + legal_entity_group -> restaurants.number + legal_entity_group
```

## Заявки поставщикам

Основные таблицы с префиксом `so_`:

- `so_orders`;
- `so_order_items`;
- `so_supplier_settings`;
- `so_supplier_schedules`;
- `so_deadline_rules`;
- `so_deadline_overrides`;
- `so_supplier_summary_subscribers`;
- таблицы временных графиков из миграции `so_temporary_schedules`.

Связь:

```text
so_orders.id -> so_order_items.order_id
so_orders.supplier_id -> suppliers.id
so_supplier_schedules.supplier_id -> suppliers.id
so_supplier_schedules.restaurant_id -> restaurants.id
```

## Загрузка машин

Таблицы с префиксом `tl_`:

- `tl_vehicles`;
- `tl_plans`;
- `tl_plan_trucks`;
- `tl_plan_assignments`.

Связь:

```text
tl_plans.id -> tl_plan_trucks.plan_id
tl_plan_trucks.id -> tl_plan_assignments.truck_id
```

Загрузка машин строится на заказах ресторанов на выбранную дату.

## Сбор остатков

Таблицы:

- `stock_collections`;
- `stock_collection_products`;
- `stock_collection_data`;
- `stock_collection_tokens`.

Связь:

```text
stock_collections.id -> stock_collection_products.collection_id
stock_collections.id -> stock_collection_data.collection_id
stock_collections.id -> stock_collection_tokens.collection_id
```

## Дефицит

Таблицы:

- `deficit_sessions`;
- `deficit_results`;
- `deficit_tokens`;
- `deficit_restaurant_stock`.

Дефицит использует данные ресторанов, графиков доставки и складских остатков.

## Тендеры

Таблицы:

- `tenders`;
- `tender_items`;
- `tender_offers`;
- `tender_offer_prices`;
- `tender_files`.

Связь:

```text
tenders.id -> tender_items.tender_id
tenders.id -> tender_offers.tender_id
tender_offers.id -> tender_offer_prices.offer_id
tender_items.id -> tender_offer_prices.item_id
```

## Маркетинг и рецепты

Таблицы:

- `marketing_activities`;
- `marketing_activity_items`;
- `marketing_activity_files`;
- `recipes`;
- `recipe_ingredients`;
- `recipe_groups`;
- `recipe_group_items`.

Маркетинг может использовать рецепты для расчёта долей блюд и ингредиентов.

## Чат, протоколы, опросы

Таблицы:

- `chat_conversations`;
- `chat_messages`;
- таблицы протоколов совещаний из миграций `meeting_protocols`;
- таблицы опросов из миграции `20260416_surveys.sql`.

## Файлы

Файлы лежат в `api/uploads/`:

- `acts/`;
- `psc/`;
- `tenders/`;
- `protocols/`;
- `marketing/`;
- `bugs/`;
- `restaurant_info/`.

В базе обычно хранится путь или имя файла. Скачивание должно идти через API, чтобы сработали проверки доступа.
