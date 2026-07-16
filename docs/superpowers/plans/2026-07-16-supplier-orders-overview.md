# Этап 2 — Обзор по всем поставщикам: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Единый экран-обзор в панели закупщика: строка = поставщик, за выбранный день видны дедлайн с отсчётом, «подано X из Y», быстрые действия (Email/Telegram-сводка, продлить/закрыть день). Новая вкладка «Обзор» — первая и по умолчанию.

**Architecture:** Новый лёгкий серверный хелпер считает статус дня по одному поставщику (дедлайн + ожидается/подано) без генерации Excel, переиспользуя `soCalculateDeadline` и `soGetEffectiveScheduleRows`. Новый эндпоинт `GET so/admin/overview` возвращает этот статус по всем поставщикам группы за дату. Фронт добавляет вкладку-таблицу; действия строки переиспользуют уже существующие методы стора (`adminSendSummary`, `adminSendSummaryEmail`, `adminExtendDeadline`, `adminCloseDay`).

**Tech Stack:** PHP 8 + MariaDB (PDO), Vue 3 (`<script setup>`) + Pinia. Тест-фреймворка нет — верификация через CLI/PHP, curl и ручную проверку UI.

**Спецификация:** `docs/superpowers/specs/2026-07-16-supplier-orders-overview-design.md`.

## Global Constraints

- Язык проекта — русский; весь UI и тексты на русском.
- Рабочие данные (поданные заявки, графики) фильтровать по юрлицам ГРУППЫ поставщика (`getEntitiesInGroup($supplierGroup)`), как во всей панели SO. Справочник `suppliers` — тем же фильтром доступа `soAppendAllowedSupplierGroupFilter`, что и `so/admin/suppliers`.
- Бэкенд отдаётся напрямую из `api/` — правки PHP живые без сборки. Фронт требует `npm run build`.
- RBAC уже применяется блоком `if ($soAction === 'admin')` (GET → view, POST → edit) до маршрутизации `$adminAction` — отдельных проверок прав НЕ добавлять.
- Миграции НЕ требуются — новых таблиц/колонок нет.
- Таймзона: отсчёт до дедлайна крутит фронт. Сервер отдаёт целевой момент `deadline_at` в ISO-8601 с offset (из `deadline_dt`, где таймзона уже проставлена), НЕ считает «осталось секунд» на PHP. Известная гоча PHP(UTC)/MySQL(+03:00).
- Тяжёлую генерацию Excel в обзоре НЕ вызывать — только лёгкий подсчёт.
- Никаких новых зависимостей.
- Не ломать существующие вкладки (Статус/Список/Графики/Шаблоны) и вход по URL с конкретным `supplierId` (в этом случае открывать «Статус», не «Обзор»).

---

## File Structure

- `api/includes/supplier_orders.php` — **изменить**: новый хелпер `soGetDayStatusLight()`; новый маршрут `GET so/admin/overview`.
- `src/stores/supplierOrderStore.js` — **изменить**: метод `adminGetOverview(date, legalEntity)`.
- `src/views/SupplierOrdersManagerView.vue` — **изменить**: вкладка «Обзор» (первая, по умолчанию), таблица, выбор даты, загрузка, навигация в «Статус», действия строки.

---

## Task 1: Бэкенд — лёгкий статус дня + эндпоинт обзора

**Files:**
- Modify: `api/includes/supplier_orders.php`

**Interfaces:**
- Produces: функция `soGetDayStatusLight(PDO $pdo, string $supplierId, string $supplierGroup, string $deliveryDate): array`.
- Produces: маршрут `GET so/admin/overview?date=YYYY-MM-DD[&legal_entity=...]`.

**Контекст реализации (реальный код для переиспользования):**
- Подсчёт ожидаемых/поданных ресторанов уже есть в `soBuildSummaryXlsx()` (строки ~90–148): группа поставщика `getEntitiesInGroup($supplierGroup)`; ожидаемые — `soGetEffectiveScheduleRows($pdo,$supplierId,$deliveryDate,null,true)` с фильтром `soDeliveryDateMatchesDow(...)` и совпадением `legal_entity_group`; поданные — `SELECT restaurant_number FROM so_orders WHERE supplier_id=? AND delivery_date=? AND status!='draft' AND legal_entity IN (группа) AND restaurant_number IN (ожидаемые)`.
- Дедлайн: `soCalculateDeadline($pdo,$supplierId,$deliveryDate)` → `is_closed`, `forced_closed`, `deadline_dt` (DateTime|null), `deadline_str`, `deadline_time`, `status`.
- Выборка поставщиков: как в `so/admin/suppliers` (строки ~1249–1268) — `is_active=1 AND so_enabled=1` + `soAppendAllowedSupplierGroupFilter($sessionUser,$legalEntity,$where,$params)`; поля `id, short_name, full_name, legal_entity, legal_entity_group`, плюс `email` (для `has_email`) и `COALESCE(sst.is_accepting_orders,1)`.

- [ ] **Step 1: Хелпер `soGetDayStatusLight`**

Добавить рядом с `soGetSupplierSettings`/`soBuildSummaryXlsx` (за стражем `if ($endpoint !== 'so') return;`, т.е. функция доступна и в кроне при желании, но здесь используется только эндпоинтом). Сигнатура:

```
soGetDayStatusLight(PDO $pdo, string $supplierId, string $supplierGroup, string $deliveryDate): array
```

Возвращает массив:
- `deadline_time` (string|null), `deadline_str` (string|null)
- `is_closed` (bool), `forced_closed` (bool)
- `deadline_at` (string|null) — `deadline_dt->format(DateTime::ATOM)` либо `null`, если `deadline_dt` пуст (закрыт принудительно / нет правила)
- `has_schedule` (bool) — есть ли хоть один ожидаемый ресторан на день
- `expected_count` (int), `submitted_count` (int)

Логику ожидаемых/поданных взять из `soBuildSummaryXlsx` (строки ~103–123), НО без чтения `so_order_items`, без построения pivot/products, без xlsx. `$supplierGroup` приходит аргументом (в эндпоинте берём из строки поставщика — не делать лишний `SELECT` на каждого). Если `forced_closed` — вернуть `is_closed=true`, `has_schedule` всё равно посчитать (чтобы показать «X из Y» и на закрытый день). Обосновать в комментарии, ПОЧЕМУ отдельный лёгкий хелпер, а не рефактор `soBuildSummaryXlsx`: `soBuildSummaryXlsx` — критический путь Этапа 1 (Telegram+Email) без автотестов, его не трогаем; лёгкий подсчёт изолирован.

- [ ] **Step 2: Эндпоинт `GET so/admin/overview`**

Внутри блока `if ($soAction === 'admin')` добавить обработчик (например, рядом с `status`):

```
if ($adminAction === 'overview' && $method === 'GET') { ... }
```

- `$date = $_GET['date'] ?? ''`; если пусто/не `YYYY-MM-DD` — сегодня в таймзоне проекта (использовать ту же таймзону, что `soCalculateDeadline`; проще `(new DateTime('now', <tz проекта>))->format('Y-m-d')` — свериться, как берётся tz в `so_deadline.php`).
- `$legalEntity = $_GET['legal_entity'] ?? null`.
- Выбрать поставщиков выборкой из `so/admin/suppliers` + поля `s.email`, `COALESCE(sst.is_accepting_orders,1) AS is_accepting` (LEFT JOIN `so_supplier_settings sst`).
- Для каждого: `$group = $s['legal_entity_group'] ?: getEntityGroup($s['legal_entity'] ?? '')`; `$st = soGetDayStatusLight($pdo, $s['id'], $group, $date)`.
- Собрать строку: `{ id, short_name, full_name, legal_entity, is_accepting: (bool), has_email: ($s['email'] не пуст и валиден как в отправке), deadline_time, deadline_str, deadline_at, is_closed, forced_closed, has_schedule, expected_count, submitted_count }`.
- Ответ `soRespond(['date' => $date, 'suppliers' => $rows])`; сортировка по `short_name` (выборка уже `ORDER BY s.short_name`).

- [ ] **Step 3: Проверка (bash/curl)**
Синтаксис: `php -l api/includes/supplier_orders.php`. Ручной вызов эндпоинта авторизованным запросом (cookie/API-key) на реальную дату — сверить, что `expected_count`/`submitted_count` совпадают с тем, что показывает вкладка «Статус» того же поставщика (сверить хотя бы одного поставщика). Приложить вывод в отчёт.

**Verification:** `php -l` без ошибок; ответ эндпоинта содержит все поля; счётчики совпадают со «Статусом» по проверенному поставщику.

---

## Task 2: Стор — метод обзора

**Files:**
- Modify: `src/stores/supplierOrderStore.js`

**Interfaces:**
- Consumes: `GET so/admin/overview`.
- Produces: `adminGetOverview(date, legalEntity = null)` → `{ date, suppliers }`.

- [ ] **Step 1:** Добавить метод по образцу соседних admin-методов (`adminGetStatus`, `adminGetSuppliers`) — через приватный `api()`-хелпер, GET с query-параметрами `date` и (если задан) `legal_entity`. Экспортировать в `return {}`.

**Verification:** метод есть в возвращаемом объекте стора; формат вызова совпадает с соседними admin-методами.

---

## Task 3: Фронтенд — вкладка «Обзор» (таблица, дата, навигация)

**Files:**
- Modify: `src/views/SupplierOrdersManagerView.vue`

**Interfaces:**
- Consumes: `store.adminGetOverview(date, legalEntity)`.

**Контекст:** вкладки задаются через `pageTab` (сейчас `ref('status')`, строки ~10+ шаблон, ~598 скрипт). Даты/поставщики: `selectedDate` (~602), `allSuppliers` (~600), `currentSupplierId` (~601), `daysShort` (~596). Провалиться в статус — поставить `currentSupplierId.value = id; pageTab.value='status'; loadStatus()`.

- [ ] **Step 1: Вкладка и состояние**
- Добавить кнопку вкладки «Обзор» ПЕРВОЙ в ряду `.rom-page-tab`, `:class="{active: pageTab==='overview'}"`, по клику `pageTab='overview'; loadOverview()`.
- `pageTab` инициализировать `'overview'` по умолчанию; НО в `onMounted`/watch, если задан `props.supplierId` (вход по URL к конкретному поставщику) — оставить/переключить на `'status'`, чтобы не ломать существующие ссылки.
- Ref-ы: `overviewRows = ref([])`, `overviewLoading = ref(false)`, `overviewDate = ref('')` (по умолчанию сегодня — `new Date().toISOString().slice(0,10)`). Использовать отдельный `overviewDate`, чтобы не конфликтовать с `selectedDate` вкладки «Статус».

- [ ] **Step 2: Загрузка**
- `async function loadOverview()` — `overviewLoading=true`, `const r = await store.adminGetOverview(overviewDate.value || undefined, orderStore.settings.legalEntity)`, `overviewRows.value = r.suppliers || []`, снять loading в `finally`. Тост об ошибке как в соседних загрузчиках.
- Вызвать `loadOverview()` при активации вкладки и при смене `overviewDate` (`@change`).
- В `onMounted`: если стартовая вкладка — «Обзор», вызвать `loadOverview()`.

- [ ] **Step 3: Таблица (только отображение — без действий, они в Task 4)**
- `<template v-if="pageTab === 'overview'">`: выбор даты `<input type="date" v-model="overviewDate" @change="loadOverview">`, кнопка «Обновить» → `loadOverview()`.
- Таблица, `v-for` по `overviewRows`:
  - **Поставщик** — кликабельно, `@click` проваливает в «Статус» (см. контекст). Пометка «на паузе», если `!row.is_accepting`.
  - **Дедлайн** — если `forced_closed` → «День закрыт»; иначе `deadline_str` + компонент отсчёта из `deadline_at` (тикает; при наступлении — «Закрыт»). Отсчёт: локальный `setInterval` (1×/мин достаточно) с `computed`/`ref now`, разница `new Date(deadline_at) - now`; при отрицательной — «Закрыт». Приглушать/красить закрытые.
  - **Подано** — `has_schedule ? 'X из Y' : '— нет поставки'`; цвет: зелёный `submitted≥expected>0`, жёлтый `0<submitted<expected`, красный `submitted==0 && expected>0`.
  - **Действия** — колонка-заглушка (кнопки добавит Task 4), пока можно оставить пустую ячейку/плейсхолдер.
- Пустой список → строка «Нет поставщиков».
- Не забыть очистить `setInterval` в `onUnmounted`.

**Verification (ручная, после сборки в Task 5):** вкладка «Обзор» первая и открывается по умолчанию; таблица грузится за сегодня; смена даты пересчитывает; клик по поставщику проваливает в «Статус»; вход по URL с supplierId открывает «Статус».

---

## Task 4: Фронтенд — действия строки

**Files:**
- Modify: `src/views/SupplierOrdersManagerView.vue`

**Interfaces:**
- Consumes: `store.adminSendSummaryEmail(id, date)`, `store.adminSendSummary(id, date)`, `store.adminExtendDeadline(...)`, `store.adminCloseDay(id, date, isClosed)`.

**Контекст:** во вкладке «Статус» уже есть обработчики `sendSummary` (Telegram), `sendSummaryEmail` (Email), `handleExtendDeadline`, `handleToggleCloseDay` — но они завязаны на `currentSupplierId`/`selectedDate`. Для обзора нужны варианты, принимающие `supplierId` и `overviewDate`.

- [ ] **Step 1: Кнопки в колонке «Действия»**
Для каждой строки: ✉️ Email, ✈️ Telegram, ⏰ Продлить, 🔒 Закрыть/Открыть день (иконка/текст по `row.forced_closed`).
- Email — `:disabled="!row.has_email || <в процессе>"`; заголовок-подсказка «У поставщика не указана почта», если нет.
- Per-row состояние «в процессе»: хранить занятость по `row.id` (напр. `ref(new Set())` или поле в строке), чтобы крутилка была только у нажатой строки.

- [ ] **Step 2: Обработчики**
- `overviewSendEmail(row)` → `await store.adminSendSummaryEmail(row.id, overviewDate.value)`; тост успех/ошибка (как в `sendSummaryEmail`). Не дизейблить всю таблицу — только строку.
- `overviewSendTelegram(row)` → `await store.adminSendSummary(row.id, overviewDate.value)`; тост.
- `overviewExtend(row)` → переиспользовать существующую механику продления (модалка/ввод времени), но для `row.id` и `overviewDate`. Если текущая `handleExtendDeadline` жёстко на `currentSupplierId` — вынести общий вызов, принимающий `supplierId,date`, и звать его из обоих мест (не дублировать логику). После успеха — `loadOverview()`.
- `overviewToggleClose(row)` → `await store.adminCloseDay(row.id, overviewDate.value, !row.forced_closed)` c подтверждением (как `handleToggleCloseDay`); после — `loadOverview()`.
- Все обработчики снимают per-row занятость в `finally`.

**Verification (ручная, после сборки в Task 5):** Email/Telegram из строки шлют сводку нужному поставщику (тост; для Email — запись в `so_email_log`); продление/закрытие меняют строку после `loadOverview`; Email-кнопка неактивна у поставщика без почты; крутилка только у нажатой строки.

---

## Task 5: Сборка и ручная проверка

**Files:** —

- [ ] **Step 1:** `npm run build` (соберёт фронт; PHP уже живой). Учесть: сборка подменяет `dist` — прод обновится.
- [ ] **Step 2:** Пройти сценарии из спецификации (раздел «Проверка», п.1–8). Зафиксировать результат в отчёте. Живые отправки писем — на тестовый ящик/поставщика, чтобы не спамить реальных (эту часть может добить пользователь).

**Verification:** сборка без ошибок; сценарии проходят (или отмечено, что требует живого инбокса — передать пользователю).

---

## Notes / открытые детали (решить на реализации)

- Точное получение таймзоны проекта для «сегодня» и `deadline_at` — свериться с `so_deadline.php` (как там строится `$tz`).
- Валидность email для `has_email` — использовать тот же критерий, что в отправке (непустой + `filter_var(... FILTER_VALIDATE_EMAIL)`), чтобы кнопка не была активной при мусорном адресе.
- Частота отсчёта: 1 раз в минуту достаточно (дедлайны — минутная точность), не грузить страницу секундным таймером.
