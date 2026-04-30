# Архитектура

Проект состоит из Vue-приложения, PHP API, базы MariaDB/MySQL, Telegram-интеграций и вспомогательных 1C-инструментов.

## Общая схема

```text
Браузер
  |
  | Vue 3 + Pinia + Vue Router
  v
/api
  |
  | PHP index.php
  |-- includes/helpers.php
  |-- includes/rpc.php
  |-- includes/crud.php
  |-- includes/restaurant_orders.php
  |-- includes/supplier_orders.php
  |-- includes/truck_loading.php
  |-- includes/uploads.php
  |-- includes/search.php
  |-- includes/ocr.php
  v
MariaDB/MySQL
```

Отдельно работают:

- [api/telegram_bot.php](/var/www/bk-calc/api/telegram_bot.php) — Telegram-бот;
- [api/cron_telegram.php](/var/www/bk-calc/api/cron_telegram.php) — периодические Telegram-задачи;
- [api/cron_psc_expiry.php](/var/www/bk-calc/api/cron_psc_expiry.php) — проверки по ПСЦ;
- [api/cron_health.php](/var/www/bk-calc/api/cron_health.php) — контроль состояния;
- [1C_Robot_Pro](/var/www/bk-calc/1C_Robot_Pro) и [1c_robot_web](/var/www/bk-calc/1c_robot_web) — инструменты вокруг 1C.

## Фронтенд

Точки входа:

- [index.html](/var/www/bk-calc/index.html)
- [src/main.js](/var/www/bk-calc/src/main.js)
- [src/App.vue](/var/www/bk-calc/src/App.vue)
- [src/router/index.js](/var/www/bk-calc/src/router/index.js)

`main.js` подключает Vue, Pinia, маршруты, общие CSS-файлы, обработку ошибок и общий компонент загрузки.

`App.vue` показывает:

- режим техработ;
- текущую страницу через `RouterView`;
- баннер недоступности сервера;
- контейнер уведомлений;
- предложение обновить PWA.

## API

Главная точка входа API — [api/index.php](/var/www/bk-calc/api/index.php).

Он делает:

- отдаёт JSON;
- настраивает CORS и базовые security-заголовки;
- читает `api/.env`;
- подключается к базе через PDO;
- подключает серверные модули;
- проверяет авторизацию для обычного REST;
- передаёт запрос в CRUD-слой.

API использует два основных стиля:

- REST-подобная работа с таблицами: `/api/products`, `/api/orders`, `/api/settings`;
- RPC-действия: `/api/rpc/check_user_password`, `/api/rpc/save_hidden_modules` и другие сложные операции.

## Клиент API

Фронтенд ходит в API через [src/lib/apiClient.js](/var/www/bk-calc/src/lib/apiClient.js).

Он предоставляет:

- `db.from('table').select().eq().insert().update().delete()` для таблиц;
- `db.rpc('name', params)` для RPC;
- автоматическую передачу `X-Session-Token`;
- передачу `X-RO-Token` для кабинета ресторанов;
- таймаут 30 секунд;
- повтор сетевых ошибок;
- баннер при недоступности сервера;
- авторазлогин при ответе `401`.

## PWA

PWA настроена в [vite.config.js](/var/www/bk-calc/vite.config.js).

Особенности:

- приложение может работать как installed app;
- часть справочных API кэшируется;
- `/api/uploads/` не кэшируется;
- для `/api/` используется стратегия `NetworkFirst`;
- после деплоя приложение пытается обновиться и перезагрузиться при ошибках динамических модулей.

## Сборка

Команда:

```bash
npm run build
```

Делает:

1. удаляет `dist/`;
2. собирает фронтенд через Vite;
3. создаёт `dist/api`;
4. копирует туда PHP API: `api/*.php`, `api/includes`, `api/migrations`.

Править нужно исходники, а не `dist/`.
