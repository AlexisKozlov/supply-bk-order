# База данных и миграции

База данных — MariaDB/MySQL. PHP подключается через PDO в [api/index.php](/var/www/bk-calc/api/index.php).

## Подключение

Настройки читаются из `api/.env`:

```text
DB_HOST=...
DB_NAME=...
DB_USER=...
DB_PASS=...
CORS_ORIGIN=...
```

Если переменные не заданы, в коде есть fallback-значения, но для реальной среды нужно использовать `.env`.

## Миграции

Основная папка миграций:

```text
migrations/
```

Старая папка:

```text
api/migrations/
```

`api/migrations/` всё ещё копируется в `dist/api/`, поэтому её нельзя просто удалить без проверки деплоя.

Миграции применяются вручную. Автоматического мигратора в проекте нет.

## Принцип добавления миграции

Если меняется структура базы, нужно добавить новый файл в `migrations/` с датой и понятным названием:

```text
migrations/YYYYMMDD_short_description.sql
```

Пример:

```text
migrations/20260430_add_example_flag.sql
```

Перед созданием миграции нужно проверить:

- нет ли уже похожей миграции;
- не существует ли поле/таблица в базе;
- не нужна ли обратная совместимость со старыми данными;
- не надо ли заполнить новое поле для существующих строк.

## Основные группы таблиц

Ниже перечислены логические группы. Это не полный список всех таблиц, но карта для поиска.

### Основной заказ

- `orders`
- `order_items`
- `plans`
- `item_order`
- `audit_log`

### Справочники

- `products`
- `suppliers`
- `restaurants`
- `cards`
- `settings`
- `users`

### Аналитика и остатки

- `analysis_data`
- `stock_1c`
- `product_adu`
- `stock_malling`
- `restaurant_sales`
- `report_exclusions`

### Цены и ПСЦ

- `price_agreements`
- `product_prices`
- `price_history`

### Тендеры

- `tenders`
- `tender_items`
- `tender_offers`
- `tender_offer_prices`
- `tender_files`

### Ресторанные заказы

Обычно имеют префикс `ro_`:

- `ro_users`
- `ro_sessions`
- `ro_orders`
- `ro_order_items`
- `ro_templates`
- `ro_tg_tokens`
- `ro_telegram_subs`
- `ro_audit_log`
- `ro_stock_balances`
- `ro_cabinet_posts`

### Заявки поставщикам

Обычно имеют префикс `so_`:

- `so_orders`
- `so_order_items`
- `so_supplier_settings`
- `so_supplier_schedules`
- `so_deadline_rules`
- `so_deadline_overrides`
- `so_supplier_summary_subscribers`

### Загрузка машин

Обычно имеют префикс `tl_`:

- `tl_vehicles`
- `tl_plans`
- `tl_plan_trucks`
- `tl_plan_assignments`

### Сбор остатков и дефицит

- `stock_collections`
- `stock_collection_products`
- `stock_collection_data`
- `stock_collection_tokens`
- `deficit_sessions`
- `deficit_results`
- `deficit_tokens`
- `deficit_restaurant_stock`

### Коммуникации и администрирование

- `notifications`
- `chat_conversations`
- `chat_messages`
- `bug_reports`
- `bug_report_replies`
- `error_logs`
- `changelog`
- `portal_user_consents`

## Юрлица в данных

В проекте одновременно используются два подхода:

- полное название юрлица в колонке `legal_entity`;
- группа юрлиц в колонке `legal_entity_group`.

Группы:

- `BK_VM`: `ООО "Бургер БК"` и `ООО "Воглия Матта"`;
- `PS`: `ООО "Пицца Стар"`.

Если модуль связан с юрлицами, нужно проверять и фронтенд, и бэкенд:

- [src/lib/legalEntities.js](/var/www/bk-calc/src/lib/legalEntities.js)
- [api/includes/legal_entities.php](/var/www/bk-calc/api/includes/legal_entities.php)

## REST-доступ к таблицам

REST-слой разрешает только таблицы из `$allowed` в [api/includes/crud.php](/var/www/bk-calc/api/includes/crud.php). Там же указаны:

- поля для фильтрации;
- поля для записи;
- таблицы только для чтения;
- таблицы только для добавления;
- проверки прав.

Если новая таблица должна быть доступна фронтенду через `db.from(...)`, её нужно добавить в `crud.php` во все нужные списки.
