# Этап 3 — Напоминания и сигналы ресторанам: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`).

**Goal:** Уведомления ресторанам (Telegram + Push) в модуле «Заявки поставщикам»: (1) кнопка закупщика «напомнить не подавшим», (2) авто-подтверждение при подаче заявки, (3) крон-сигналы «скоро дедлайн» и «приём закрыт» не подавшим. Всё уважает флаги настроек ресторана.

**Architecture:** Общий серверный хелпер рассылки ресторану (TG по `ro_telegram_subs` с фильтром группы + флага настройки, Push через `pushSendToRestaurant`) + хелпер списка не подавших. На них строятся: эндпоинт «напомнить», хук подтверждения в `so/submit-order`, крон-сигналы. Защита авто-дублей — таблица `so_signal_log` (INSERT IGNORE), как `so_email_auto_log`.

**Tech Stack:** PHP 8 + MariaDB (PDO), Vue 3 (`<script setup>`) + Pinia, Telegram Bot API + Web Push. Тест-фреймворка нет — верификация `php -l`, curl, ручная проверка.

**Спецификация:** `docs/superpowers/specs/2026-07-16-supplier-orders-signals-design.md`.

## Global Constraints

- Язык — русский; тексты уведомлений и UI на русском.
- Каналы — только Telegram + Push (НЕ email).
- Уважать флаги `ro_telegram_subs`: `notify_so_reminders` (напоминания+сигналы), `notify_confirmations` (подтверждение подачи). Имя колонки-флага брать ТОЛЬКО из белого списка в коде, не из ввода (иначе SQL-инъекция в идентификатор).
- Рассылка строго по `legal_entity_group` ресторана — не слать чужой группе.
- Ошибка уведомления НИКОГДА не роняет подачу заявки/ответ эндпоинта (try/catch, лог).
- Бэкенд живой из `api/` (без сборки). Фронт — `npm run build`.
- RBAC: эндпоинт «напомнить» в admin-блоке `if ($soAction === 'admin')` (POST → edit уже проверен). Хук подтверждения — в ресторанном `so/submit-order` (уже за сессией ресторана).
- Миграция `so_signal_log` применяется вручную (Claude сам).
- Токен бота — `$_ENV['TELEGRAM_BOT_TOKEN']` (в кроне уже `$BOT_TOKEN`).
- Крон: сигнал «закрыт» — ПОСЛЕ блока авто-подстановки заявок (чтобы подставленные считались поданными).
- Никаких изменений в `ro_telegram_subs`.

---

## File Structure

- `migrations/20260716_so_signal_log.sql` — **создать**: таблица-сторож сигналов.
- `api/includes/supplier_orders.php` — **изменить**: хелперы `soNotifyRestaurantOrders()`, `soGetUnsubmittedRestaurants()`; эндпоинт `POST so/admin/remind-unsubmitted`; хук подтверждения в `so/submit-order`.
- `api/cron_telegram.php` — **изменить**: сигналы «скоро дедлайн» и «приём закрыт».
- `src/stores/supplierOrderStore.js` — **изменить**: метод `adminRemindUnsubmitted()`.
- `src/views/SupplierOrdersManagerView.vue` — **изменить**: кнопка «🔔 Напомнить» в обзоре и на вкладке «Статус».

---

## Task 1: Миграция `so_signal_log`

**Files:** Create `migrations/20260716_so_signal_log.sql`

- [ ] **Step 1:** Создать таблицу-сторож:
```sql
-- Этап 3 «Заявки поставщикам»: защита авто-сигналов ресторанам от повторов.
CREATE TABLE IF NOT EXISTS `so_signal_log` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `supplier_id` CHAR(36) NOT NULL,
  `delivery_date` DATE NOT NULL,
  `signal_type` VARCHAR(32) NOT NULL,
  `restaurants_notified` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_signal` (`supplier_id`, `delivery_date`, `signal_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
**Verification:** файл создан; SQL синтаксически корректен; паттерн совпадает с `so_email_auto_log`.

---

## Task 2: Серверные хелперы (рассылка + не подавшие)

**Files:** Modify `api/includes/supplier_orders.php`

**Interfaces:**
- Produces `soNotifyRestaurantOrders(PDO $pdo, string $botToken, int $rNum, string $rGroup, string $tgHtml, array $push, string $notifyCol): int`.
- Produces `soGetUnsubmittedRestaurants(PDO $pdo, string $supplierId, string $supplierGroup, string $deliveryDate): array`.

**Контекст (реальный код):**
- Образец TG+Push ресторану — `supplier_orders.php` ~532–566 (TG-запрос по `ro_telegram_subs` с `restaurant_number`+`legal_entity_group`+`verified_at`, затем `sendTelegramMessage`; push через `pushSendToRestaurant`).
- Расчёт ожидаемых/поданных — `soGetDayStatusLight`/`soBuildSummaryXlsx` (~103–123): ожидаемые `soGetEffectiveScheduleRows($pdo,$supplierId,$deliveryDate,null,true)` + фильтр `soDeliveryDateMatchesDow` и `legal_entity_group===$supplierGroup`; поданные — `so_orders.status!='draft'` по группе.
- `restNotifySubscribers` (bot_rest.php:1813) — образец verified-условия с `must_reverify_by`; но он фильтрует по `notify_confirmations` и БЕЗ группы — НЕ переиспользуем напрямую, делаем свой запрос с группой и параметром-колонкой.

- [ ] **Step 1: `soNotifyRestaurantOrders`** (за стражем `if ($endpoint !== 'so') return;`, рядом с прочими хелперами)
  - Белый список колонок: `if (!in_array($notifyCol, ['notify_so_reminders','notify_confirmations'], true)) return 0;`
  - TG: `SELECT DISTINCT chat_id FROM ro_telegram_subs WHERE restaurant_number=? AND legal_entity_group=? AND {$notifyCol}=1 AND (verified_at IS NOT NULL OR (must_reverify_by IS NOT NULL AND must_reverify_by > NOW()))`, для каждого `sendTelegramMessage($botToken,$chatId,$tgHtml,'HTML')` с `usleep(50000)`; считать успехи.
  - Push: `try { pushSendToRestaurant($pdo,$rNum,$rGroup,$push); } catch (Throwable $e) { error_log(...); }`.
  - Вернуть число охваченных ресторанов (например 1, если был хоть один канал/подписчик — или суммарно; выбрать простую метрику и задокументировать в докблоке).
  - Токен пустой → TG пропустить, push всё равно попробовать.

- [ ] **Step 2: `soGetUnsubmittedRestaurants`**
  - Собрать ожидаемые (как в `soGetDayStatusLight`), собрать множество подавших `restaurant_number`, вернуть массив ожидаемых, чьих номеров нет среди подавших: `[{restaurant_number:int, legal_entity_group:string}]` (группа — из строки графика либо переданный `$supplierGroup`).

- [ ] **Step 3:** `php -l api/includes/supplier_orders.php`.
**Verification:** `php -l` чисто; докблоки описывают контракт; колонка-флаг из белого списка; запросы параметризованы.

---

## Task 3: Подтверждение подачи (хук в `so/submit-order`)

**Files:** Modify `api/includes/supplier_orders.php`

**Контекст:** обработчик `so/submit-order` (~1009+); успешный `commit()` внутри try (~1150+), после чего идёт успешный ответ. Доступно: `$rest` (`restaurant_number`, `legal_entity_group`), `$supplierId`, `$deliveryDate`, `$skipDelivery`, `$le`. Токен — `$_ENV['TELEGRAM_BOT_TOKEN']`.

- [ ] **Step 1:** ПОСЛЕ успешного `commit()` (до/рядом с формированием успешного ответа), в отдельном `try/catch` (ошибка не должна ломать ответ):
  - Получить короткое имя поставщика: `SELECT short_name FROM suppliers WHERE id=?`.
  - `$dateFmt = (new DateTime($deliveryDate))->format('d.m.Y')`.
  - Текст: обычная подача — «✅ Заявка <short_name> на <dateFmt> принята.»; `skip_delivery` — «✅ <short_name>, <dateFmt>: отмечено, что поставка не нужна.»
  - `soNotifyRestaurantOrders($pdo, $_ENV['TELEGRAM_BOT_TOKEN'] ?? '', (int)$rest['restaurant_number'], $rest['legal_entity_group'] ?? '', $tgHtml, ['title'=>"$short_name","body"=>$plain,'url'=>'/restaurant/supplier-orders','tag'=>"so-confirm-$supplierId-$deliveryDate"], 'notify_confirmations')`.
  - Весь блок в `try { } catch (Throwable $e) { error_log('[so submit confirm] '.$e->getMessage()); }`.
  - Проверить корректный путь кабинета ресторана для `url` (свериться с существующими ссылками ресторана; если нет точного — использовать корень кабинета).

**Verification:** `php -l` чисто; уведомление в try/catch; подача не зависит от результата уведомления; текст на русском; при `skip_delivery` — правильный вариант.

---

## Task 4: Эндпоинт «Напомнить не подавшим»

**Files:** Modify `api/includes/supplier_orders.php`

**Interfaces:** `POST so/admin/remind-unsubmitted { supplier_id, delivery_date }` → `{ reminded, total_unsubmitted }`.

**Контекст:** admin-блок (RBAC edit для POST уже проверен), `$adminAction`. Группа поставщика — `SELECT legal_entity_group ... FROM suppliers` или `getEntityGroup`.

- [ ] **Step 1:** Обработчик `if ($adminAction === 'remind-unsubmitted' && $method === 'POST')`:
  - Прочитать `supplier_id`, `delivery_date` из тела; валидация (непустые, дата `YYYY-MM-DD` + `checkdate`).
  - Определить группу поставщика; проверить, что приём открыт: `soCalculateDeadline` → если `is_closed`/`forced_closed` — вернуть `{ reminded:0, closed:true }` с понятным сообщением (или 200 + флаг), фронт покажет тост.
  - `$list = soGetUnsubmittedRestaurants(...)`; для каждого — `soNotifyRestaurantOrders(..., $tgHtml, $push, 'notify_so_reminders')`.
  - Текст: «🔔 <short_name>: не забудьте подать заявку на <ДД.ММ>. Дедлайн <ЧЧ:ММ>.» (время — из `soCalculateDeadline`).
  - Считать `reminded` (сколько ресторанов реально охвачено) и `total_unsubmitted`.
  - Ответ `soRespond(['reminded'=>..., 'total_unsubmitted'=>...])`.

**Verification:** `php -l` чисто; закрытый приём не рассылает; не подавшие берутся по группе; ответ содержит счётчики.

---

## Task 5: Фронт — кнопка «Напомнить»

**Files:** Modify `src/stores/supplierOrderStore.js`, `src/views/SupplierOrdersManagerView.vue`

**Interfaces:** `store.adminRemindUnsubmitted(supplierId, deliveryDate)`.

- [ ] **Step 1 (стор):** добавить `adminRemindUnsubmitted(supplierId, deliveryDate)` — POST на `admin/remind-unsubmitted` через `api()`, по образцу `adminSendSummary`/`adminSendSummaryEmail`; экспортировать.

- [ ] **Step 2 (обзор):** в колонке действий строки обзора добавить кнопку «🔔 Напомнить», активную когда `row.has_schedule && row.submitted_count < row.expected_count && !row.forced_closed` (и по возможности дедлайн не прошёл — `!overviewIsPassed(row)`); обработчик `overviewRemind(row)` → `await store.adminRemindUnsubmitted(row.id, overviewDate.value)`; тост «Напомнили N ресторанам» / «Приём уже закрыт» по ответу; per-row busy как у прочих кнопок.

- [ ] **Step 3 (Статус):** на вкладке «Статус» добавить кнопку «🔔 Напомнить не подавшим» рядом с `sendSummary`/`copyMissingRestaurants`, активную при `selectedDate`; обработчик `remindUnsubmitted()` → `store.adminRemindUnsubmitted(currentSupplierId.value, selectedDate.value)`; тост по ответу.

- [ ] **Step 4:** `npm run build`.
**Verification (ручная, после сборки в Task 7):** кнопки видны и активны по условиям; нажатие шлёт запрос; тост отражает число; при закрытом приёме — сообщение о закрытии.

---

## Task 6: Крон — авто-сигналы

**Files:** Modify `api/cron_telegram.php`

**Контекст:** крон уже грузит `$BOT_TOKEN`, `so_deadline.php` (дедлайны), и в блоке Этапа 1 проходит по поставщикам у дедлайна (авто-подстановка + авто-письмо). `supplier_orders.php` в кроне уже требуется (Этап 1) — хелперы `soNotifyRestaurantOrders`/`soGetUnsubmittedRestaurants` доступны. Изучить существующий блок авто-письма, чтобы взять тот же способ определения (поставщик, дата доставки, дедлайн) и окно 0..15 мин.

- [ ] **Step 1: «Скоро дедлайн» (`deadline_soon`)**
  - Константа `SO_SIGNAL_LEAD_MIN = 60` (в начале блока/файла, с комментарием «за сколько минут до дедлайна слать сигнал»).
  - Для поставщика/даты с открытым приёмом: если `0 < (deadline - now) <= LEAD` минут — `INSERT IGNORE INTO so_signal_log (supplier_id,delivery_date,signal_type) VALUES (?,?, 'deadline_soon')`; если `rowCount()>0` (первый раз) — разослать не подавшим `soNotifyRestaurantOrders(..., 'notify_so_reminders')` с текстом «⏰ <short_name>: приём заявок на <ДД.ММ> закрывается в <ЧЧ:ММ>. Подайте заявку.»; обновить `restaurants_notified`.

- [ ] **Step 2: «Приём закрыт» (`closed`)** — ПОСЛЕ блока авто-подстановки заявок:
  - Для поставщика/даты, где дедлайн только что прошёл (окно 0..15 мин после дедлайна, как авто-письмо) ИЛИ день принудительно закрыт: `INSERT IGNORE ... 'closed'`; если первый раз — разослать не подавшим «🔒 <short_name>: приём заявок на <ДД.ММ> закрыт.» (`notify_so_reminders`).

- [ ] **Step 3:** `php -l api/cron_telegram.php`; при возможности «сухой» прогон крона на тестовых данных (без реального дедлайна — проверить, что без подходящего окна ничего не шлётся и нет ошибок).
**Verification:** `php -l` чисто; сигналы под сторожем (один раз на тип); «закрыт» — после авто-подстановки; окно дедлайна взято из существующего блока; не подавшие — по группе.

---

## Task 7: Сборка и ручная проверка

- [ ] **Step 1:** применить миграцию `so_signal_log`; `npm run build`.
- [ ] **Step 2:** пройти сценарии из спецификации (раздел «Проверка», п.1–5). Живые рассылки — на тестовый ресторан/подписку, чтобы не спамить реальные (эту часть добивает пользователь).
**Verification:** миграция применена; сборка без ошибок; сценарии пройдены/помечены как требующие живой проверки.

---

## Notes / открытые детали

- Точный путь кабинета ресторана для `url` push — свериться с существующими ссылками ресторана (например `/restaurant/...`).
- `soGetEffectiveScheduleRows` уже в `so_deadline.php` (в кроне доступна).
- LEAD=60 мин — константа; настраиваемость per-supplier вне объёма.
- Метрика «reminded» — задокументировать в докблоке хелпера (охвачен ресторан, если был хоть один канал).
