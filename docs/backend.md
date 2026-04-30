# Бэкенд и API

Бэкенд написан на PHP без фреймворка. Главная точка входа — [api/index.php](/var/www/bk-calc/api/index.php).

## Как проходит запрос

1. `api/index.php` настраивает JSON-ответы, CORS и security-заголовки.
2. Читает переменные из `api/.env`.
3. Подключается к MariaDB/MySQL через PDO.
4. Подключает `api/includes/helpers.php`.
5. Разбирает URI, метод и тело запроса.
6. Подключает специализированные обработчики.
7. Для обычного REST проверяет авторизацию.
8. Передаёт запрос в `api/includes/crud.php`.

## Основные серверные файлы

- [api/includes/helpers.php](/var/www/bk-calc/api/includes/helpers.php) — авторизация, сессии, Telegram-уведомления, RBAC, фильтры, аудит;
- [api/includes/legal_entities.php](/var/www/bk-calc/api/includes/legal_entities.php) — группы юрлиц и работа с номерами ресторанов;
- [api/includes/rpc.php](/var/www/bk-calc/api/includes/rpc.php) — большой набор RPC-действий;
- [api/includes/crud.php](/var/www/bk-calc/api/includes/crud.php) — REST CRUD для разрешённых таблиц;
- [api/includes/restaurant_orders.php](/var/www/bk-calc/api/includes/restaurant_orders.php) — кабинет ресторанов и заказы ресторанов;
- [api/includes/supplier_orders.php](/var/www/bk-calc/api/includes/supplier_orders.php) — заявки поставщикам;
- [api/includes/truck_loading.php](/var/www/bk-calc/api/includes/truck_loading.php) — загрузка машин;
- [api/includes/uploads.php](/var/www/bk-calc/api/includes/uploads.php) — загрузка и скачивание файлов;
- [api/includes/search.php](/var/www/bk-calc/api/includes/search.php) — быстрый поиск товаров;
- [api/includes/ocr.php](/var/www/bk-calc/api/includes/ocr.php) — OCR через Tesseract.

## REST CRUD

REST CRUD находится в [api/includes/crud.php](/var/www/bk-calc/api/includes/crud.php).

Он работает только с таблицами из белого списка `$allowed`. Там же есть:

- таблицы только для чтения;
- таблицы без вставки/удаления;
- append-only таблицы, например аудит;
- whitelist полей для фильтрации;
- whitelist полей для записи;
- проверка прав по модулю;
- проверка доступа к юрлицу.

Примеры REST-запросов:

```text
GET    /api/products?legal_entity=eq....
POST   /api/orders
PATCH  /api/settings?key=eq....
DELETE /api/order_items?id=eq....
```

Фронтенд обычно не пишет такие URL вручную, а использует `db.from(...)`.

## RPC

RPC находится в [api/includes/rpc.php](/var/www/bk-calc/api/includes/rpc.php). Это действия, которые сложнее обычного CRUD.

Публичные RPC:

- вход сотрудника;
- проверка техработ;
- health-check;
- гостевая активность;
- публичный поиск карточек;
- публичные формы дефицита/остатков.

Приватные RPC требуют сессию или API-ключ. Через них проходят сохранение заказов, админские действия, импорт, уведомления, настройки, права и многие бизнес-операции.

## Авторизация сотрудников

Сотрудник входит через `check_user_password`. Сервер:

- проверяет email и пароль;
- требует согласие с правилами использования данных;
- применяет rate limit;
- создаёт сессионный токен;
- возвращает пользователя, роль, права, доступные юрлица и скрытые модули.

Дальше фронтенд отправляет токен в заголовке:

```text
X-Session-Token: ...
```

## Авторизация ресторанов

Кабинет ресторанов использует отдельный токен:

```text
X-RO-Token: ...
```

Логика находится в [api/includes/restaurant_orders.php](/var/www/bk-calc/api/includes/restaurant_orders.php). Ресторан может входить по номеру/паролю или через Telegram-ссылку.

## Загрузки файлов

Файлы обрабатывает [api/includes/uploads.php](/var/www/bk-calc/api/includes/uploads.php).

Важные сценарии:

- акты поставок;
- файлы ПСЦ;
- файлы тендеров и маркетинга;
- вложения кабинета ресторанов.

Для скачивания ресторанных вложений есть отдельная проверка: файл должен быть доступен конкретному ресторану или сотруднику с правами на модуль ресторанных заказов.

## Telegram

Telegram-часть состоит из:

- [api/telegram_bot.php](/var/www/bk-calc/api/telegram_bot.php) — команды бота;
- [api/cron_telegram.php](/var/www/bk-calc/api/cron_telegram.php) — периодические задачи;
- [api/update_telegram_commands.php](/var/www/bk-calc/api/update_telegram_commands.php) — обновление команд;
- helper-функций в [api/includes/helpers.php](/var/www/bk-calc/api/includes/helpers.php);
- модулей `api/includes/bot_*.php`.

Бот умеет работать с заказами, остатками, ценами, ПСЦ, аналитикой, поставками, графиком, реализацией, корректировками и ресторанными уведомлениями.

## OCR

`POST /api/ocr` принимает изображение в multipart-поле `image`, подготавливает картинку и запускает серверный `tesseract` с языками `rus+eng`.

Нужны PHP GD и установленный Tesseract на сервере.
