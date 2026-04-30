# Поддержка и безопасные изменения

В проекте много связанных модулей и нет автотестов, поэтому изменения нужно делать аккуратно и с ручной проверкой.

## Общий порядок работы

1. Найти страницу, store, библиотеку и серверный модуль.
2. Проверить, затрагиваются ли права доступа.
3. Проверить, затрагиваются ли юрлица или группы юрлиц.
4. Проверить, нужна ли миграция базы.
5. Внести минимальные изменения в исходники.
6. Собрать проект.
7. Ручно проверить основной сценарий и соседние сценарии.

## Где начинать поиск

Если задача про интерфейс:

- искать в `src/views/`;
- затем связанный store в `src/stores/`;
- затем API-вызовы через `db.from(...)` или `db.rpc(...)`.

Если задача про основной заказ:

- [src/views/OrderView.vue](/var/www/bk-calc/src/views/OrderView.vue);
- [src/stores/orderStore.js](/var/www/bk-calc/src/stores/orderStore.js);
- [src/lib/calculations.js](/var/www/bk-calc/src/lib/calculations.js);
- [src/lib/saveOrder.js](/var/www/bk-calc/src/lib/saveOrder.js);
- [api/includes/rpc.php](/var/www/bk-calc/api/includes/rpc.php).

Если задача про ресторанный кабинет:

- [src/views/RestaurantCabinetView.vue](/var/www/bk-calc/src/views/RestaurantCabinetView.vue);
- [src/stores/restaurantOrderStore.js](/var/www/bk-calc/src/stores/restaurantOrderStore.js);
- [api/includes/restaurant_orders.php](/var/www/bk-calc/api/includes/restaurant_orders.php).

Если задача про заявки поставщикам:

- [src/stores/supplierOrderStore.js](/var/www/bk-calc/src/stores/supplierOrderStore.js);
- [api/includes/supplier_orders.php](/var/www/bk-calc/api/includes/supplier_orders.php).

Если задача про загрузку машин:

- [src/views/TruckLoadingView.vue](/var/www/bk-calc/src/views/TruckLoadingView.vue);
- [src/stores/truckLoadingStore.js](/var/www/bk-calc/src/stores/truckLoadingStore.js);
- [api/includes/truck_loading.php](/var/www/bk-calc/api/includes/truck_loading.php).

Если задача про права:

- [src/stores/userStore.js](/var/www/bk-calc/src/stores/userStore.js);
- [src/router/index.js](/var/www/bk-calc/src/router/index.js);
- [api/includes/helpers.php](/var/www/bk-calc/api/includes/helpers.php);
- [api/includes/crud.php](/var/www/bk-calc/api/includes/crud.php);
- нужный RPC/модуль на PHP.

Если задача про юрлица:

- [src/lib/legalEntities.js](/var/www/bk-calc/src/lib/legalEntities.js);
- [api/includes/legal_entities.php](/var/www/bk-calc/api/includes/legal_entities.php);
- SQL-запросы с `legal_entity` и `legal_entity_group`.

## Зоны повышенного риска

- [api/includes/rpc.php](/var/www/bk-calc/api/includes/rpc.php) — большой файл с большим количеством сценариев.
- [api/includes/restaurant_orders.php](/var/www/bk-calc/api/includes/restaurant_orders.php) — много бизнес-логики, сессии ресторанов, Telegram, импорт, кабинет.
- Права доступа — часть логики на фронтенде, часть на бэкенде.
- Юрлица — логика продублирована между фронтом и бэком.
- Миграции — есть актуальная и старая папка.
- PWA-кэш — после деплоя возможны ошибки загрузки старых чанков, для этого уже есть обработчики перезагрузки.

## Проверка перед правкой базы

Перед изменением схемы:

- найти похожие миграции;
- проверить, какие PHP/JS-файлы читают таблицу;
- понять, нужно ли заполнить новое поле для старых строк;
- убедиться, что REST whitelist в `crud.php` обновлён, если поле должно быть доступно фронтенду;
- проверить права на новую таблицу или поле.

## Проверка перед правкой API

Перед изменением API:

- найти все вызовы `db.rpc('имя')` или `db.from('таблица')`;
- проверить, не используется ли тот же endpoint в нескольких страницах;
- проверить права и юрлица;
- проверить формат ошибок, который ожидает фронтенд;
- проверить лимиты файлов, если это upload.

## Проверка перед правкой интерфейса

Перед изменением страницы:

- найти её маршрут в `src/router/index.js`;
- проверить `meta.module`;
- найти store;
- проверить, какие данные приходят с API;
- проверить состояние загрузки и ошибок;
- проверить, не скрывается ли модуль пользователем в настройках.

## Минимальные команды проверки

```bash
npm run build
```

Для локальной разработки:

```bash
npm run dev
```

Автотестов и линтера в проекте нет, поэтому сборка и ручная проверка обязательны.
