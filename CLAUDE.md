# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Язык

Проект на русском языке (UI, комментарии, данные). Всегда отвечай на русском.

## Стиль общения

Пользователь — не программист. Все пояснения, описания изменений и сводки пиши простым понятным языком без технического жаргона. Вместо «race condition в async функции» пиши «исправлена ситуация, когда два действия одновременно могли сломать данные». Вместо «null reference» — «ошибка при пустом поле». Коротко и по делу.

## Команды

```bash
npm run dev      # Vite dev-сервер (фронтенд)
npm run build    # Сборка в dist/
npm run preview  # Превью продакшн-сборки
```

Тестов и линтера нет. Бэкенд — PHP, работает через веб-сервер (nginx/apache), не запускается через npm.

## Архитектура

**Стек:** Vue 3 (Composition API, `<script setup>`) + Pinia + Vite (фронтенд), PHP + MariaDB/MySQL (бэкенд). Без TypeScript.

### Фронтенд (`src/`)

- **Точка входа:** `main.js` → `App.vue` → `RouterView`
- **Роутер** (`router/index.js`): авторизованные страницы обёрнуты в `AppLayout.vue`, guard проверяет `userStore.isAuthenticated`
- **Stores** (Pinia):
  - `userStore` — авторизация, сессия в `localStorage` (ключи с префиксом `bk_`)
  - `orderStore` — главный стор: настройки заказа (`settings`), позиции (`items`), undo/redo с History-классом, аудит-лог
  - `historyStore`, `draftStore`, `supplierStore`, `toastStore`
- **Ключевые модули** (`lib/`):
  - `apiClient.js` — REST-клиент с интерфейсом, имитирующим Supabase: `db.from('table').select().eq().order()` и `db.rpc('fn', params)`. API-ключ передаётся через заголовок `X-API-Key`
  - `calculations.js` — формула расчёта заказа: дневной расход → расход до поставки → потребность с учётом страховочных дней → округление по кратности
  - `saveOrder.js` — сохранение/обновление заказа с diff-аудитом
  - `utils.js` — бизнес-логика юр. лиц: «ООО "Бургер БК"» и «ООО "Воглия Матта"» — одна группа, «ООО "Пицца Стар"» — отдельная

### Бэкенд

Два файла `index.php`:
- **`/api/index.php`** (продакшн) — использует `.env` для DB-credentials, валидация колонок в SQL
- **`/index.php`** (корень) — legacy-версия с захардкоженными credentials

Бэкенд — единый PHP-файл с маршрутизацией:
- `search_products` — поиск товаров по SKU/имени с фильтром юр. лица
- `rpc/{fn}` — RPC-эндпоинты (авторизация, список пользователей, смена пароля) — доступны **без** API-ключа
- REST CRUD для таблиц: `products`, `suppliers`, `orders`, `order_items`, `plans`, `item_order`, `settings`, `audit_log`, `stock_1c`, `cards`, `users` и др. — требуют API-ключ
- Фильтрация через query-параметры в PostgREST-стиле: `eq.`, `neq.`, `gte.`, `in.()`, `ilike.`, `or`

### Единицы измерения

Заказ работает в двух режимах (`settings.unit`): `boxes` (коробки) и `pieces` (штуки). При сохранении всё конвертируется в физические коробки. `multiplicity` — кратность заказа, `qtyPerBox` — штук в коробке.

### Стили

Три CSS-файла в `src/assets/`: `style.css` (основной), `components.css` (компоненты), `compact.css`. Шрифт Flame (OTF в `public/`). Без CSS-препроцессоров и UI-фреймворков.
