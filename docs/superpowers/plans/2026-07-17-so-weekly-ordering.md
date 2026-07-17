# Недельный режим подачи заявок («неделя вперёд»): Implementation Plan

> REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Шаги — чекбоксы.

**Goal:** Дать поставщику опцию «недельный режим подачи»: ОДНА общая отсечка на всю неделю доставки (пн–вс), заданная как день недели + время на ПРЕДЫДУЩЕЙ неделе (напр. «среда 14:00» → вся следующая неделя закрывается в эту среду). В недельном режиме ресторан видит и подаёт ВСЕ дни доставки ближайшей открытой недели сразу (а не только 3 ближайшие даты). Обычный режим (дедлайн по дням) остаётся у прочих поставщиков без изменений.

**Контекст (проверено в коде):**
- Ядро расчёта дедлайна: `soCalculateDeadlineCore($override, $rule, $defaultDeadlineTime, $deliveryDate, $tz)` в `api/includes/so_deadline.php` (~39–93). Приоритет: forced_closed override → override с deadline_time → правило дня недели (`soDeadlineDateByRule`) → дефолт (delivery −1 день). `soDeadlineDateByRule` берёт БЛИЖАЙШИЙ предшествующий deadline-DOW (1..7 дней до доставки) — поэтому одним правилом «среда» четверг/пятница СЛЕДУЮЩЕЙ недели закрываются лишь на следующей среде (корень проблемы).
- DB-обёртка `soCalculateDeadline($pdo, $supplierId, $deliveryDate)` (so_deadline.php ~97–135) грузит override/rule/default из БД и зовёт ядро. Её вызывают ~8 мест (admin status ~145/310/612/2940/3035, бот bot_rest.php). Им недельный режим достанется автоматически, если обёртка будет грузить и передавать недельный конфиг.
- ПРЯМЫЕ вызовы ядра (в обход обёртки), которым тоже нужен недельный конфиг:
  - Ресторанный список дат: `api/includes/supplier_orders.php` ~1007–1012 (замыкание `$checkDeadline`, строит `$rulesMap/$overridesMap/$settingsMap`). Горизонт `rangeStart..rangeEnd` = сегодня..+21д (~982–983). Ресторану режется `array_slice($availableDates, 0, 3)` (~1070).
  - Крон `api/cron_telegram.php`: 4 вызова ядра (985 — напоминания; 1186 — авто-подача; 1377/1474 — прочее), каждый грузит `default_deadline_time` из so_supplier_settings.
- Настройки поставщика — `so_supplier_settings`; эндпоинт `settings` GET/POST уже ЧАСТИЧНО-БЕЗОПАСНЫЙ (обновляет только присутствующие в теле поля — читает текущую строку, отсутствующий ключ = текущее значение). `soGetSupplierSettings` (~59–115) отдаёт настройки. Раздел «⚙️ Настройки» уже есть во фронте (`SupplierOrdersManagerView.vue`, вкладка `pageTab==='settings'`).
- Решения пользователя: (1) недельная отсечка = день недели + время на ПРЕДЫДУЩЕЙ неделе; (2) в недельном режиме показывать ресторану всю открытую неделю целиком.

**Architecture:**
- Хранилище: 2 новых столбца в `so_supplier_settings` (миграция): `weekly_deadline_dow` TINYINT NULL (1..7; **NULL = недельный режим ВЫКЛ**), `weekly_deadline_time` TIME NULL (если dow задан, а time NULL → берём default_deadline_time / '14:00:00').
- Ядро: `soCalculateDeadlineCore(..., $weeklyDow = null, $weeklyTime = null)`. Приоритет: forced_closed → explicit per-date override (с deadline_time) → **недельный режим (если $weeklyDow задан)** → правило дня → дефолт. Недельный дедлайн: понедельник недели доставки `M = delivery − (N−1)`; дедлайн-дата = `(M − 7) + (weeklyDow − 1)` в `weeklyTime`. Одна дата на всю неделю доставки. `$weeklyDow=null` → ветка не активна → поведение как сейчас (регрессия).
- Обёртка `soCalculateDeadline`: грузит `weekly_deadline_dow`/`weekly_deadline_time` из so_supplier_settings и передаёт в ядро — покрывает все её вызовы разом.
- Прямые вызовы ядра: ресторанный `$checkDeadline` (settingsMap +weekly, передать в ядро) и 4 вызова в кроне (SELECT +weekly, передать). Регрессия: при NULL всё как раньше.
- Ресторанный показ: если у поставщика недельный режим (weekly_deadline_dow не NULL) — НЕ резать до 3 дат, показать все открытые даты в пределах горизонта (это и есть ближайшая открытая неделя целиком; недельная отсечка сама ограничивает набор). Обычный режим — `array_slice 0,3` как сейчас.
- UI: в «Настройки» блок «Недельный режим подачи»: переключатель + выбор дня недели + времени. Подсказка, что режим заменяет дедлайны по дням. Сохранение через уже частично-безопасный `settings` POST (только ключи weekly_*).

## Global Constraints
- **Регрессия — критерий №1:** при выключенном недельном режиме (weekly_deadline_dow = NULL у всех поставщиков) дедлайны, список дат ресторана, подача, крон и админ-экраны работают ПОБИТОВО как сейчас. Ядро с `$weeklyDow=null` не меняет ни одной ветки.
- Часовой пояс — Europe/Minsk (как в ядре). Все расчёты дат — через DateTime с этим tz.
- Русский UI, понятные подписи (пользователь не программист).
- Бэкенд/крон живые из `api/`; фронт — `npm run build`. Миграцию применяет Claude.
- Приоритет override сохранить: forced_closed и разовый per-date override (с deadline_time) по-прежнему ВЫШЕ недельного режима.
- Параметризация SQL; TINYINT/TIME валидировать (dow 1..7, time HH:MM). Инъекций нет.
- Не ломать подачу заявок и гейтинг дедлайна, авто-подачу, авто-письмо, напоминания.

## File Structure
- `migrations/20260717_so_weekly_ordering.sql` — НОВАЯ миграция (2 столбца).
- `api/includes/so_deadline.php` — `soCalculateDeadlineCore` (+weekly-ветка, новые необяз. параметры), `soCalculateDeadline` (грузит и передаёт weekly).
- `api/includes/supplier_orders.php` — `soGetSupplierSettings` (+weekly в выдачу); `settings` POST (+weekly, партиал-безопасно) и GET (через soGetSupplierSettings); ресторанный `$checkDeadline`/`$settingsMap` (+weekly) и снятие slice(0,3) для недельных.
- `api/cron_telegram.php` — 4 SELECT’а settings (+weekly) и 4 вызова ядра (+weekly).
- `src/views/SupplierOrdersManagerView.vue` — блок «Недельный режим» в «Настройках».
- `src/stores/supplierOrderStore.js` — при необходимости проброс weekly в сохранение настроек (adminSaveSettings уже принимает произвольный payload).

---

## Task 1: Миграция + ядро дедлайна + обёртка + настройки (GET/POST)

**Files:** Create `migrations/20260717_so_weekly_ordering.sql`; modify `api/includes/so_deadline.php`, `api/includes/supplier_orders.php`

- [ ] **Step 1 (миграция):** `ALTER TABLE so_supplier_settings ADD COLUMN weekly_deadline_dow TINYINT NULL DEFAULT NULL, ADD COLUMN weekly_deadline_time TIME NULL DEFAULT NULL;` Комментарий: dow NULL = недельный режим выключен; time NULL при заданном dow → фолбэк default_deadline_time. Применить к БД.
- [ ] **Step 2 (ядро):** В `soCalculateDeadlineCore` добавить необязательные параметры `$weeklyDow = null, $weeklyTime = null`. Вставить ветку МЕЖДУ override-с-deadline_time и правилом дня: если `$weeklyDow` задан (1..7) И нет активного per-date override с deadline_time И не forced_closed — считать недельный дедлайн: `deliveryObj`; `N = (int)format('N')`; `monday = clone deliveryObj modify('-(N-1) days')`; `deadlineDate = clone monday modify('-7 days') modify('+(weeklyDow-1) days')`; `deadlineTime = $weeklyTime ?: ($defaultDeadlineTime ?: '14:00:00')`. Далее как обычно (deadlineDT, is_closed = now>=deadlineDT). Приоритет строго: forced_closed → override(deadline_time) → weekly → rule → default. При `$weeklyDow=null` ветка неактивна (регрессия).
- [ ] **Step 3 (обёртка):** В `soCalculateDeadline` дочитать из so_supplier_settings `weekly_deadline_dow`, `weekly_deadline_time` (там уже читается default_deadline_time — добавить в тот же/соседний SELECT) и передать в ядро. Нормализовать dow к int|null, time к строке|null.
- [ ] **Step 4 (soGetSupplierSettings):** добавить в SELECT и в выдачу `weekly_deadline_dow` (int|null) и `weekly_deadline_time` (строка 'HH:MM'|null). Обе ветки функции (строка есть / нет) — с дефолтами null.
- [ ] **Step 5 (settings POST):** принять `weekly_deadline_dow` (int 1..7 или null/'' → выключить) и `weekly_deadline_time` (HH:MM|null) ПАРТИАЛ-БЕЗОПАСНО (как остальные поля: array_key_exists есть → применить с валидацией, нет → текущее из БД). Валидация: dow ∈ 1..7 иначе NULL; time по регулярке HH:MM → 'HH:MM:00', иначе NULL. Записать в тот же INSERT…ON DUPLICATE (расширить список полей и merge-логику) ИЛИ отдельным UPDATE по паттерну reminder_* — согласовать с уже существующей merge-логикой (не сломать её). Ответ POST/GET вернуть обновлённые настройки с weekly_*.

**Verification:** `php -l` чисто; миграция применена; при NULL dow ядро даёт прежние дедлайны (несколько дат — сверить с текущей формулой); при заданном dow=3 (среда) все дни следующей недели дают ОДНУ дату дедлайна = среда текущей недели; forced_closed и per-date override по-прежнему выигрывают; settings GET/POST читают/пишут weekly_* партиал-безопасно; частичное сохранение (только reminder_* или только базовые) не трогает weekly_* и наоборот.

---

## Task 2: Прокинуть недельный конфиг в прямые вызовы ядра (ресторан + крон) и показ всей недели

**Files:** Modify `api/includes/supplier_orders.php`, `api/cron_telegram.php`

- [ ] **Step 1 (ресторанный список дат):** В блоке ~972–1070 `supplier_orders.php`: `$settingsMap` уже грузит настройки поставщиков — добавить в него `weekly_deadline_dow`/`weekly_deadline_time` (проверить SELECT, что строит settingsMap; при необходимости расширить). Замыкание `$checkDeadline` — передавать weekly в `soCalculateDeadlineCore`. Затем: если у поставщика недельный режим (`weekly_deadline_dow` не NULL) — НЕ применять `array_slice($availableDates, 0, 3)` (показать все открытые даты в пределах горизонта); обычный режим — резать как сейчас. Дедуп по delivery_date и сортировку оставить.
- [ ] **Step 2 (крон):** В `cron_telegram.php` в 4 местах, где грузится `default_deadline_time` из so_supplier_settings (~856, 1158, 1354, 1404) — добавить в SELECT `weekly_deadline_dow`, `weekly_deadline_time`; в соответствующие 4 вызова `soCalculateDeadlineCore` (985, 1186, 1377, 1474) — передать weekly-параметры. Регрессия: при NULL всё как раньше. Не менять прочую логику блоков.

**Verification:** `php -l api/cron_telegram.php` и supplier_orders.php чисто; для недельного поставщика ресторан видит все дни ближайшей открытой недели (не 3); дедлайны в кроне (напоминания/авто-подача) считаются по недельной отсечке; при NULL — прежнее поведение и прежний показ 3 дат.

---

## Task 3: UI «Недельный режим» в разделе «Настройки»

**Files:** Modify `src/views/SupplierOrdersManagerView.vue`, при необходимости `src/stores/supplierOrderStore.js`

- [ ] **Step 1:** В теле вкладки `pageTab==='settings'` добавить блок «Недельный режим подачи»: переключатель (вкл/выкл), при вкл — выпадающий выбор дня недели (Пн..Вс = 1..7) и поле времени (HH:MM). Начальные значения из `settings.weekly_deadline_dow`/`weekly_deadline_time` (грузятся в loadSettings). Подсказка: «В недельном режиме дедлайны по дням не применяются: вся неделя доставки закрывается в выбранный день предыдущей недели».
- [ ] **Step 2:** Кнопка «Сохранить» (можно вместе с блоком напоминаний или отдельная) шлёт через `store.adminSaveSettings(currentSupplierId, { weekly_deadline_dow: <int|null>, weekly_deadline_time: <HH:MM|null> })` — ТОЛЬКО ключи weekly_* (партиал-безопасность на бэке гарантирует, что прочее не тронется). Выкл режима → слать `weekly_deadline_dow: null`. После сохранения — обновить `settings.value` из ответа, тост.
- [ ] **Step 3:** `npm run build`.

**Verification (после сборки):** блок виден в «Настройках»; включение/выбор дня+времени сохраняется и по перезагрузке остаётся; выключение возвращает обычный режим; сохранение недельного режима не сбрасывает приём/авто-*/напоминания/получателей (партиал-безопасность); обычные поставщики без изменений.

---

## Task 4: Миграция, сборка, ручная проверка, память

- [ ] **Step 1:** Применить миграцию (Claude); `npm run build`.
- [ ] **Step 2:** Ручная проверка: недельный поставщик — вся следующая неделя видна и закрывается одной отсечкой; обычный — как раньше; крон/подача уважают дедлайн.
- [ ] **Step 3:** Обновить память (`supplier-orders-*`) и леджер.

**Verification:** миграция применена; сборка чистая; сценарии пройдены; регрессия (режим выкл = как раньше) подтверждена.

## Notes
- Недельный режim ЗАМЕНЯЕТ дедлайны по дням (приоритет в ядре); правила по дням на «Графиках» остаются в БД и снова действуют, если недельный режим выключить.
- Горизонт показа в недельном режиме — все открытые даты в пределах +21д (может включать и неделю после следующей, если её отсечка ещё открыта); при желании ограничить одной неделей — отдельная правка.
- Минимум заказа по сумме — следующий бэклог.
