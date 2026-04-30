# Диагностика проблем

Документ помогает разработчику быстро разбирать типовые ошибки.

## Не открывается приложение

Проверить:

```bash
npm run build
```

Если проблема после деплоя:

- открыть DevTools браузера;
- проверить ошибки загрузки JS/CSS;
- очистить PWA-кэш или обновить страницу;
- проверить, что `dist/` обновился полностью.

В коде уже есть обработка ошибок динамического импорта после деплоя в `src/main.js` и `src/router/index.js`.

## Сервер временно недоступен

Фронтенд показывает верхний красный баннер, если API несколько раз не ответил.

Проверить:

- доступен ли `/api/rpc/health_check`;
- работает ли PHP;
- доступна ли база;
- нет ли таймаутов долгих SQL-запросов;
- не закончились ли диск или память.

Связанный код:

- [src/lib/apiClient.js](/var/www/bk-calc/src/lib/apiClient.js)
- [api/includes/rpc.php](/var/www/bk-calc/api/includes/rpc.php)

## Пользователь не может войти

Проверить:

- правильный email;
- подтверждение правил использования данных;
- пароль;
- rate limit по IP;
- запись пользователя в `users`;
- хэш пароля;
- режим техработ;
- ответ `check_user_password`.

Код:

- [src/stores/userStore.js](/var/www/bk-calc/src/stores/userStore.js)
- [api/includes/rpc.php](/var/www/bk-calc/api/includes/rpc.php)
- [api/includes/helpers.php](/var/www/bk-calc/api/includes/helpers.php)

## Пользователя выбрасывает из системы

Проверить:

- сервер возвращает `401`;
- токен `bk_session_token` в localStorage;
- валидность сессии через `validate_session`;
- не удалена ли сессия админом;
- не изменился ли пользователь в базе.

Фронтенд при `401` вызывает logout и отправляет на главную.

## Не виден раздел меню

Проверить:

- маршрут в [src/router/index.js](/var/www/bk-calc/src/router/index.js);
- `meta.module` у маршрута;
- `MODULES`, `ROLE_TEMPLATES`, `ACCESS_LEVELS` в [src/stores/userStore.js](/var/www/bk-calc/src/stores/userStore.js);
- серверный `get_rbac_config`;
- `hidden_modules` пользователя;
- права в базе.

## Доступ запрещён на API

Проверить:

- есть ли `X-Session-Token`;
- какой пользователь вернулся из `getSessionUser`;
- роль пользователя;
- права на модуль;
- доступ к юрлицу;
- не read-only ли таблица;
- есть ли таблица/поле в whitelist `crud.php`.

Код:

- [api/includes/helpers.php](/var/www/bk-calc/api/includes/helpers.php)
- [api/includes/crud.php](/var/www/bk-calc/api/includes/crud.php)

## Не видны данные по юрлицу

Проверить:

- `legal_entity` или `legal_entity_group` в таблице;
- список доступных юрлиц пользователя;
- фильтры на фронтенде;
- SQL-фильтры на бэкенде;
- соответствие `BK_VM` и `PS`;
- номер ресторана: PS-рестораны могут храниться как `1001+`, а показываться как `PS01`.

Код:

- [src/lib/legalEntities.js](/var/www/bk-calc/src/lib/legalEntities.js)
- [api/includes/legal_entities.php](/var/www/bk-calc/api/includes/legal_entities.php)

## Не сохраняется основной заказ

Проверить:

- выбран поставщик и юрлицо;
- дата доставки;
- нет ли ошибок расчёта;
- `create_order` или `update_order`;
- права на модуль `order`;
- доступ к юрлицу;
- блокировку заказа;
- ошибки в `saveOrder.js`.

Код:

- [src/lib/saveOrder.js](/var/www/bk-calc/src/lib/saveOrder.js)
- [src/stores/orderStore.js](/var/www/bk-calc/src/stores/orderStore.js)
- [api/includes/rpc.php](/var/www/bk-calc/api/includes/rpc.php)

## Ресторан не может войти

Проверить:

- номер ресторана и группу `BK_VM`/`PS`;
- запись в `ro_users`;
- `is_active`;
- пароль;
- активную сессию другого пользователя;
- согласие с правилами;
- для Telegram-входа — `ro_tg_tokens`.

Код:

- [src/stores/restaurantOrderStore.js](/var/www/bk-calc/src/stores/restaurantOrderStore.js)
- [api/includes/restaurant_orders.php](/var/www/bk-calc/api/includes/restaurant_orders.php)

## Ресторан не видит дату заказа

Проверить:

- активную `ro_sessions`;
- открытую дату;
- дедлайн;
- график доставки ресторана;
- группу юрлица;
- есть ли товары в шаблоне;
- включён ли модуль ресторанных заказов для юрлица/группы.

## Не отправляется заявка поставщику

Проверить:

- поставщик включён для `so`;
- есть график `so_supplier_schedules`;
- дедлайн открыт;
- ресторан входит в нужную группу юрлиц;
- есть шаблон товаров;
- нет закрытия дня через override.

Код:

- [src/stores/supplierOrderStore.js](/var/www/bk-calc/src/stores/supplierOrderStore.js)
- [api/includes/supplier_orders.php](/var/www/bk-calc/api/includes/supplier_orders.php)

## Не работает загрузка машин

Проверить:

- есть ресторанные заказы на выбранную дату;
- статус заказов не draft;
- активная сессия ресторанных заказов;
- права `truck-loading`;
- есть активные типы машин `tl_vehicles`;
- план не подтверждён, если пытаетесь его менять.

Код:

- [src/stores/truckLoadingStore.js](/var/www/bk-calc/src/stores/truckLoadingStore.js)
- [api/includes/truck_loading.php](/var/www/bk-calc/api/includes/truck_loading.php)

## Не загружается файл

Проверить:

- размер файла;
- MIME-тип;
- права на модуль;
- доступ к юрлицу;
- права записи в `api/uploads/`;
- лимиты PHP `upload_max_filesize` и `post_max_size`;
- корректность записи пути в базу.

Код:

- [api/includes/uploads.php](/var/www/bk-calc/api/includes/uploads.php)

## Не работает OCR

Проверить:

- установлен ли Tesseract;
- доступна ли команда `tesseract`;
- установлены ли языки `rus` и `eng`;
- включён ли PHP GD;
- файл меньше 10 МБ;
- файл является изображением.

Код:

- [api/includes/ocr.php](/var/www/bk-calc/api/includes/ocr.php)

## Не работает Telegram

Проверить:

- Telegram Bot Token;
- webhook;
- доступность URL webhook снаружи;
- логи PHP;
- подписки пользователей/ресторанов;
- настройки уведомлений;
- cron-задачи.

Код:

- [api/telegram_bot.php](/var/www/bk-calc/api/telegram_bot.php)
- [api/includes/helpers.php](/var/www/bk-calc/api/includes/helpers.php)
- [api/cron_telegram.php](/var/www/bk-calc/api/cron_telegram.php)
- [src/views/TelegramAdminView.vue](/var/www/bk-calc/src/views/TelegramAdminView.vue)

## Миграция не применяется

Проверить:

- выбранную базу;
- уже существующие поля/индексы;
- права пользователя БД;
- синтаксис MariaDB/MySQL;
- зависимости от предыдущих миграций;
- не применяется ли старый файл из `api/migrations/` вместо нового из `migrations/`.

## После изменения поля фронтенд не видит данные

Проверить:

- добавлено ли поле в `$filterWhitelist`, если по нему фильтруют;
- добавлено ли поле в `$writeWhitelist`, если его записывают;
- отдаёт ли SQL это поле;
- парсится ли JSON в `apiClient.js`;
- не кэшируется ли старый ответ PWA.
