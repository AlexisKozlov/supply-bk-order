# Унификация напоминаний о заявках поставщикам — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development или superpowers:executing-plans для реализации по задачам. Шаги — чекбоксы (`- [ ]`).

**Goal:** Свести напоминания о подаче заявок к ОДНОЙ модели подписок для локальных и портальных поставщиков: ресторан сам выбирает вкл/выкл, дни поставки (маска), каналы и получателей Telegram; закупщик может выключать/включать напоминания ресторану по поставщику и по привязанным аккаунтам.

**Architecture:** Портальные (so_enabled=1) поставщики переходят на те же таблицы, что и локальные — `restaurant_reminder_subscriptions` (+ новая маска дней `reminder_days`) и `restaurant_reminder_tg_subscribers`. Крон `cron_telegram.php` (портальный блок) начинает читать эту подписку; при отсутствии подписки — прежнее поведение (обратная совместимость). Полное выключение со стороны закупок остаётся в `so_reminder_mutes` (уже сделано). Управление получателями по аккаунтам у закупщика — редактирование того же `restaurant_reminder_tg_subscribers`.

**Tech Stack:** PHP + MariaDB (backend, cron), Vue 3 `<script setup>` (кабинет ресторана, менеджер), Telegram Bot (`bot_rest.php`). Тестов/линтера в проекте нет — проверка через `php -l`, `npm run build`, SQL-проверки `php -r`, браузер (`browse`).

## Global Constraints

- Отвечать и писать UI-тексты на русском, простым языком (без жаргона).
- Юрлица/группы: рабочие данные фильтровать по ОДНОМУ юрлицу; справочники (products, suppliers) — по ГРУППЕ. Регионы в БД: «Минск», «Регионы».
- `so_reminder_mutes.supplier_id` — `utf8mb4_unicode_ci` (иначе «Illegal mix of collations» в подзапросах с `suppliers`/`supplier_schedules`).
- Обратная совместимость обязательна: **нет подписки у ресторана = напоминать по всем дням** (как сейчас). Никого нельзя молча отключить.
- Приоритет: ресторан настраивает сам; закупки могут выключить целиком (`so_reminder_mutes`) — это жёсткий выключатель поверх подписки.
- Два крона разделены по `so_enabled`: `cron_delivery_reminders.php` — локальные (=0), `cron_telegram.php` so_*-блок — портальные (=1). НЕ смешивать рассылку, только модель настроек.
- Миграции: `migrations/YYYYMMDD_<имя>.sql`, применяются вручную (Claude применяет сам).
- Сборка фронта: `npm run build`. Service worker кеширует — при браузер-проверке чистить SW+caches.

## Уже сделано в этой сессии (основа, НЕ переделывать)

- Таблица `so_reminder_mutes(supplier_id, restaurant_id)` + collation-фикс.
- Эндпоинт `admin/reminder-mute` (закупки, полное выкл на пару) и переключатель «Напом.» в `SupplierOrdersManagerView.vue` вкладка «Графики».
- Эндпоинт `restaurant-reminders/so-mute` (ресторан, полное выкл) — будет заменён/дополнен подписочной моделью в этом плане.
- Портальная карточка в `RestaurantRemindersTab.vue` (пока простой вкл/выкл) — будет заменена на полную карточку.
- Крон `cron_telegram.php` уже пропускает рестораны из `so_reminder_mutes` (жёсткий выключатель) — остаётся.

## Файловая структура (что и зачем меняется)

- `migrations/20260721_reminder_days_mask.sql` — новая колонка `reminder_days` в `restaurant_reminder_subscriptions`.
- `api/includes/restaurant_reminders.php` — портальные поставщики в `/list` (с подпиской, маской дней, получателями); сохранение маски дней; портальный `so-mute` остаётся как «полное выкл рестораном».
- `api/cron_telegram.php` — портальный so_*-блок читает подписку (вкл/выкл, маска дней, получатели) с backward-compat.
- `src/components/restaurant/RestaurantRemindersTab.vue` — портальная карточка = как локальная (дни, TG-дублирование, получатели).
- `src/views/SupplierOrdersManagerView.vue` — вкладка «Графики»: раскрытие ресторана → список привязанных аккаунтов с переключателями (закупщик).
- `api/includes/supplier_orders.php` — эндпоинты закупщика: получатели по (поставщик, ресторан) и их переключение.
- `api/includes/bot_rest.php` — пункт «Напоминания о заявках» в боте: пояснить, что это мастер-выключатель, тонкая настройка по поставщикам — в кабинете.

---

## Task 1: Маска дней в подписке (миграция + чтение)

**Files:**
- Create: `migrations/20260721_reminder_days_mask.sql`
- Modify: `api/includes/restaurant_reminders.php` (чтение `reminder_days`)

**Interfaces:**
- Produces: колонка `restaurant_reminder_subscriptions.reminder_days SMALLINT NULL` — битовая маска дней ДОСТАВКИ (бит 0 = Пн … бит 6 = Вс). `NULL` = все дни (обратная совместимость). Хелпер PHP `rrDayEnabled($mask, $deliveryDow)`.

- [ ] **Step 1: Миграция**

```sql
-- migrations/20260721_reminder_days_mask.sql
-- Маска дней доставки, по которым слать напоминание о заявке.
-- Бит (delivery_dow-1): 1=Пн ... 7=Вс. NULL = все дни (как было).
ALTER TABLE `restaurant_reminder_subscriptions`
  ADD COLUMN `reminder_days` SMALLINT DEFAULT NULL
  COMMENT 'Битовая маска дней доставки для напоминаний (NULL=все)' AFTER `telegram_enabled`;
```

- [ ] **Step 2: Применить и проверить**

```bash
php -r 'foreach(file("/var/www/bk-calc-secrets/.env") as $l){$l=trim($l);if(!$l||$l[0]=="#")continue;[$k,$v]=array_pad(explode("=",$l,2),2,"");$_ENV[$k]=trim($v," \"\x27");} echo shell_exec(sprintf("mysql -h%s -u%s -p%s %s < migrations/20260721_reminder_days_mask.sql 2>&1", escapeshellarg($_ENV["DB_HOST"]??"localhost"), escapeshellarg($_ENV["DB_USER"]), escapeshellarg($_ENV["DB_PASS"]), escapeshellarg($_ENV["DB_NAME"]))) ?: "ok\n";'
```
Expected: пусто/ok; `SHOW COLUMNS ... LIKE "reminder_days"` возвращает строку.

- [ ] **Step 3: Хелпер в restaurant_reminders.php**

В начало файла (рядом с прочими функциями) добавить:
```php
// Включён ли день доставки в маске напоминаний. NULL/0 маска → все дни.
function rrDayEnabled($mask, int $deliveryDow): bool {
    if ($mask === null || $mask === '') return true;
    $mask = (int)$mask;
    if ($mask === 0) return false; // явно снято всё
    return ($mask & (1 << ($deliveryDow - 1))) !== 0;
}
```

- [ ] **Step 4: php -l**

Run: `php -l api/includes/restaurant_reminders.php`
Expected: No syntax errors.

- [ ] **Step 5: Commit**

```bash
git add migrations/20260721_reminder_days_mask.sql api/includes/restaurant_reminders.php
git commit -m "so-reminders: маска дней доставки в подписке (reminder_days)"
```

---

## Task 2: Портальные поставщики в /list как полноценная подписка

**Files:**
- Modify: `api/includes/restaurant_reminders.php` (портальный блок в GET `/list`)

**Interfaces:**
- Consumes: `rrDayEnabled` (Task 1), `$rrRestPk` (int id ресторана), `$bySupplier` (map группировки).
- Produces: в ответе `/list` портальные группы теперь содержат: `so_enabled:true`, `days:[{order_day,delivery_day,deadline_override}]`, `subscription:{is_enabled,telegram_enabled,reminder_days}` (или null=дефолт «всё вкл»), `selected_tg_ids:[]`, `reminder_muted:bool` (жёсткий выкл закупок).

- [ ] **Step 1: Заменить упрощённый портальный блок**

Найти блок «Портальные поставщики (so_enabled=1)» (добавлен ранее) и заменить на полноценный: тянуть расписание портальных (по аналогии с локальным `$sched`, но `s.so_enabled = 1`), собрать `days`, подтянуть `restaurant_reminder_subscriptions` (is_enabled/telegram_enabled/reminder_days) и `restaurant_reminder_tg_subscribers` (selected_tg_ids), и `so_reminder_mutes` (reminder_muted). Дефолт при отсутствии подписки: `subscription=null` (фронт трактует как «вкл, все дни»).

Код (вставить вместо прежнего портального блока, после сборки локальных групп):
```php
    // ─── Портальные поставщики (so_enabled=1): та же модель подписок ───
    $portSched = $pdo->prepare("
        SELECT ss.supplier_id, ss.order_day, ss.delivery_day,
               s.short_name AS supplier_name,
               sd.deadline_time AS deadline_override
        FROM supplier_schedules ss
        JOIN suppliers s ON s.id = ss.supplier_id
        LEFT JOIN supplier_schedule_deadlines sd
               ON sd.supplier_id = ss.supplier_id AND sd.restaurant_id = ss.restaurant_id AND sd.order_day = ss.order_day
        WHERE ss.restaurant_id = ? AND ss.is_active = 1 AND s.is_active = 1 AND s.so_enabled = 1
        ORDER BY s.short_name, ss.order_day
    ");
    $portSched->execute([$rrRestPk]);
    $portRows = $portSched->fetchAll();
    $portIds = [];
    foreach ($portRows as $r) {
        $sid = $r['supplier_id'];
        if (!isset($bySupplier[$sid])) {
            $bySupplier[$sid] = [
                'supplier_id' => $sid, 'supplier_name' => $r['supplier_name'],
                'so_enabled' => true, 'days' => [], 'subscription' => null,
                'selected_tg_ids' => [], 'temp_period' => null, 'reminder_muted' => false,
            ];
            $portIds[] = $sid;
        }
        $bySupplier[$sid]['days'][] = [
            'order_day' => (int)$r['order_day'], 'delivery_day' => (int)$r['delivery_day'],
            'deadline_override' => $r['deadline_override'],
        ];
    }
    if ($portIds) {
        $ph = implode(',', array_fill(0, count($portIds), '?'));
        // подписки
        $subSt = $pdo->prepare("SELECT id, supplier_id, is_enabled, telegram_enabled, reminder_days
                                FROM restaurant_reminder_subscriptions
                                WHERE restaurant_id = ? AND supplier_id IN ($ph)");
        $subSt->execute(array_merge([$rrRestPk], $portIds));
        $subIdBySup = [];
        foreach ($subSt->fetchAll() as $r) {
            $bySupplier[$r['supplier_id']]['subscription'] = [
                'is_enabled' => (int)$r['is_enabled'] === 1,
                'telegram_enabled' => (int)$r['telegram_enabled'] === 1,
                'reminder_days' => $r['reminder_days'] === null ? null : (int)$r['reminder_days'],
            ];
            $subIdBySup[(int)$r['id']] = $r['supplier_id'];
        }
        if ($subIdBySup) {
            $sph = implode(',', array_fill(0, count($subIdBySup), '?'));
            $tgSt = $pdo->prepare("SELECT subscription_id, ro_tg_sub_id FROM restaurant_reminder_tg_subscribers
                                   WHERE subscription_id IN ($sph) AND is_active = 1");
            $tgSt->execute(array_keys($subIdBySup));
            foreach ($tgSt->fetchAll() as $r) {
                $sid = $subIdBySup[(int)$r['subscription_id']] ?? null;
                if ($sid) $bySupplier[$sid]['selected_tg_ids'][] = (int)$r['ro_tg_sub_id'];
            }
        }
        // жёсткий выключатель закупок
        $mSt = $pdo->prepare("SELECT supplier_id FROM so_reminder_mutes WHERE restaurant_id = ? AND supplier_id IN ($ph)");
        $mSt->execute(array_merge([$rrRestPk], $portIds));
        foreach ($mSt->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            if (isset($bySupplier[$sid])) $bySupplier[$sid]['reminder_muted'] = true;
        }
    }
```

- [ ] **Step 2: php -l**

Run: `php -l api/includes/restaurant_reminders.php`
Expected: No syntax errors.

- [ ] **Step 3: Проверка на данных**

```bash
php -r '... подключиться, вызвать логику вручную нельзя (нужна RO-сессия) ...'
```
Вместо этого: SQL-проверка, что для ресторана с портальным расписанием (напр. id=28) выборка `portSched` вернёт Камако/Планету, а объединения не падают по collation.

- [ ] **Step 4: Commit**

```bash
git add api/includes/restaurant_reminders.php
git commit -m "so-reminders: портальные поставщики в /list как подписка (дни, получатели, muted)"
```

---

## Task 3: Сохранение маски дней (restaurant set) + портальный set

**Files:**
- Modify: `api/includes/restaurant_reminders.php` (POST `/set`)

**Interfaces:**
- Consumes: существующий обработчик `set` (пишет `restaurant_reminder_subscriptions`).
- Produces: `set` принимает `reminder_days` (int|null) и сохраняет. Работает и для локальных, и для портальных (одна таблица).

- [ ] **Step 1: Расширить обработчик `set`**

В блоке `if ($subpoint === 'set' && $method === 'POST')` добавить чтение и запись `reminder_days`:
```php
    $reminderDays = array_key_exists('reminder_days', $body)
        ? ($body['reminder_days'] === null ? null : (int)$body['reminder_days'])
        : null; // если ключ не прислан — не трогаем (см. ниже UPSERT)
```
И в INSERT…ON DUPLICATE добавить колонку `reminder_days`. Если ключ не прислан — сохранять текущее (прочитать `$prevState['reminder_days']` в SELECT `$prev`). Проверка «у ресторана есть расписание с поставщиком» уже есть и покрывает портальных (supplier_schedules общий).

- [ ] **Step 2: php -l + Commit**

```bash
php -l api/includes/restaurant_reminders.php
git add api/includes/restaurant_reminders.php
git commit -m "so-reminders: /set сохраняет маску дней reminder_days"
```

---

## Task 4: Крон читает подписку для портальных (backward-compat)

**Files:**
- Modify: `api/cron_telegram.php` (портальный so_*-блок)

**Interfaces:**
- Consumes: `$mutedRests` (жёсткий выкл, уже есть), таблицы `restaurant_reminder_subscriptions`/`_tg_subscribers`.
- Produces: рассылка учитывает подписку ресторана: нет строки → прежнее поведение; есть → is_enabled/маска дней/получатели.

- [ ] **Step 1: Загрузить подписки портального поставщика (на поставщика)**

Рядом с `$mutedRests` в цикле по поставщику добавить карту подписок по restaurant_id: `is_enabled`, `reminder_days`, `telegram_enabled`, и множество выбранных `ro_tg_sub_id` (chat_id) на ресторан. Один запрос на поставщика (JOIN subscriptions + tg_subscribers + ro_telegram_subs для chat_id).

- [ ] **Step 2: Применить в рассылке**

В цикле по ресторану/дню:
- если у ресторана ЕСТЬ подписка и `is_enabled=0` → пропустить (ресторан выключил);
- если ЕСТЬ подписка и день не в маске (`rrDayEnabled` аналог на JS/PHP) → пропустить этот день;
- получатели TG: если у ресторана ЕСТЬ подписка с `telegram_enabled=1` и выбран список → слать ТОЛЬКО выбранным chat_id; если подписки НЕТ → прежнее поведение (все `notify_so_reminders=1`).
- `so_reminder_mutes` (жёсткий выкл) уже отсекает ресторан целиком раньше — оставить.

Код-скелет (вставить логику фильтра перед формированием $chatIds/отправкой):
```php
// $subByRest[$restId] = ['enabled'=>bool,'days'=>?int,'tg_enabled'=>bool,'chat_ids'=>[...]]
$sub = $subByRest[(int)$restId] ?? null;
if ($sub) {
    if (!$sub['enabled']) continue;                        // выключено рестораном
    if (!rrDayEnabledPhp($sub['days'], $nextDelivery['dow'])) continue; // день не выбран
    if ($sub['tg_enabled']) $chatIds = $sub['chat_ids'];   // только выбранные
    // если tg_enabled=0 — Telegram не шлём (пуш по настройке поставщика)
}
```
Добавить локальный `rrDayEnabledPhp($mask,$dow)` в cron_telegram (та же логика, что `rrDayEnabled`).

- [ ] **Step 3: php -l**

Run: `php -l api/cron_telegram.php` → No syntax errors.

- [ ] **Step 4: Прогон вхолостую (безопасно)**

Проверить, что при отсутствии подписок поведение не изменилось: временно `SELECT`-ом убедиться, что без строк в subscriptions ресторан по-прежнему в рассылке (логика fallback). Не слать реальные сообщения (проверять на тестовом ресторане №999 или логикой).

- [ ] **Step 5: Commit**

```bash
git add api/cron_telegram.php
git commit -m "so-reminders: крон учитывает подписку ресторана (вкл/дни/получатели), fallback как раньше"
```

---

## Task 5: Портальная карточка в кабинете = как локальная

**Files:**
- Modify: `src/components/restaurant/RestaurantRemindersTab.vue`

**Interfaces:**
- Consumes: `/list` (Task 2), `/set` (Task 3), `/tg-set` (существует), `/so-mute` (существует).
- Produces: портальная карточка с переключателем, галочками дней доставки, «Дублировать в Telegram» + выбор получателей.

- [ ] **Step 1: Разметка портальной карточки**

Заменить нынешнюю упрощённую портальную `<article>` на карточку по образцу локальной (`localGroups`), но:
- вкл/выкл пишет `set { is_enabled }` (как локальные);
- добавить строку галочек по дням доставки (`g.days` уникальные delivery_day) → собирать маску и слать `set { reminder_days }`;
- «Дублировать в Telegram» и выбор получателей — как у локальных (reuse `onChannelChange`, `toggleTg`);
- показать бейдж «выключено закупками», если `g.reminder_muted` (тогда переключатели неактивны с подсказкой «Отдел закупок выключил напоминания»).

- [ ] **Step 2: Логика маски дней**

Добавить функции: `dayMaskHas(g, dow)`, `toggleDay(g, dow)` (пересобирает `reminder_days`, шлёт `set`). День включён, если `subscription===null` (дефолт всё вкл) или бит установлен.

- [ ] **Step 3: Сборка**

Run: `npm run build` → `✓ built`, без ошибок.

- [ ] **Step 4: Браузер-проверка (чистить SW)**

Залогиниться рестораном (№999), открыть «Напоминания» → раздел портальных: переключить день, включить TG-дублирование, выбрать получателя; проверить запись в БД (`restaurant_reminder_subscriptions.reminder_days`, `restaurant_reminder_tg_subscribers`). Откатить тестовые данные.

- [ ] **Step 5: Commit**

```bash
git add src/views ... src/components/restaurant/RestaurantRemindersTab.vue
git commit -m "so-reminders: портальная карточка = как локальная (дни, TG, получатели)"
```

---

## Task 6: Закупщик — управление получателями по аккаунтам

**Files:**
- Modify: `api/includes/supplier_orders.php` (эндпоинты получателей)
- Modify: `src/views/SupplierOrdersManagerView.vue` (Графики: раскрытие ресторана → аккаунты)

**Interfaces:**
- Produces:
  - GET `admin/reminder-recipients?supplier_id&restaurant_id` → `{ accounts:[{ro_tg_sub_id,name,username,chat_id_present,selected}], subscription:{is_enabled,telegram_enabled} }` — привязанные Telegram-аккаунты ресторана и кто выбран для напоминаний этого поставщика.
  - POST `admin/reminder-recipient` `{supplier_id,restaurant_id,ro_tg_sub_id,selected}` → правит `restaurant_reminder_tg_subscribers` (общий с рестораном набор). При первом выборе создаёт `restaurant_reminder_subscriptions` (is_enabled=1, telegram_enabled=1) если её нет.

- [ ] **Step 1: Backend GET получателей**

Список аккаунтов из `ro_telegram_subs` по (restaurant_number, group) с флагом `selected` из `restaurant_reminder_tg_subscribers` для подписки (supplier+restaurant). Права: `soRequireAdminSupplierAccess`.

- [ ] **Step 2: Backend POST переключения аккаунта**

Найти/создать `restaurant_reminder_subscriptions` (supplier+restaurant), затем upsert/деактивировать строку `restaurant_reminder_tg_subscribers`. `updated_by = actor`.

- [ ] **Step 3: Store методы**

`src/stores/supplierOrderStore.js`: `adminGetReminderRecipients(supplierId, restaurantId)`, `adminSetReminderRecipient(supplierId, restaurantId, tgSubId, selected)`.

- [ ] **Step 4: UI — раскрытие строки ресторана**

В «Графики», в строке ресторана рядом со столбцом «Напом.» — кнопка «Аккаунты» (раскрывает подстроку со списком привязанных Telegram-аккаунтов и переключателями). Мгновенное сохранение.

- [ ] **Step 5: php -l + build + браузер + Commit**

```bash
php -l api/includes/supplier_orders.php
npm run build
git add api/includes/supplier_orders.php src/views/SupplierOrdersManagerView.vue src/stores/supplierOrderStore.js
git commit -m "so-reminders: закупщик управляет получателями по аккаунтам (по поставщику+ресторану)"
```

---

## Task 7: Telegram-бот — согласовать пункт «Напоминания о заявках»

**Files:**
- Modify: `api/includes/bot_rest.php` (меню настроек уведомлений)

**Interfaces:**
- Consumes: `restNotifSettings`, `restNotifToggle`.
- Produces: пункт «Напоминания о заявках» = МАСТЕР-выключатель (глобально по всем поставщикам, `notify_so_reminders`). Подпись/подсказка: тонкая настройка (по поставщикам, дням, получателям) — в кабинете, вкладка «Напоминания».

- [ ] **Step 1: Обновить подпись пункта**

В `restNotifSettings` добавить строку-пояснение под списком: «Тонкая настройка по поставщикам и дням — в кабинете ресторана → Напоминания». Поведение флага не менять (мастер-выключатель: если выкл — крон всё равно отсекает по notify_so_reminders в fallback-ветке; при активной подписке получатели берутся из выбранных, но аккаунт должен быть verified — оставить как есть).

- [ ] **Step 2: Согласованность крона**

Проверить, что fallback-ветка крона (нет подписки) по-прежнему требует `notify_so_reminders=1`, а ветка «есть подписка + выбраны получатели» шлёт выбранным независимо от глобального флага ИЛИ с его учётом — ЗАФИКСИРОВАТЬ решение в комментарии: выбранные получатели должны быть verified; `notify_so_reminders` в этом случае трактуем как мастер (если человек выключил глобально — не слать даже если выбран). Реализовать: при выборке `chat_ids` подписки джойнить `ro_telegram_subs` с `notify_so_reminders=1 AND verified`.

- [ ] **Step 3: php -l + Commit**

```bash
php -l api/includes/bot_rest.php
git add api/includes/bot_rest.php api/cron_telegram.php
git commit -m "so-reminders: бот — мастер-выключатель + пояснение; крон уважает verified/notify_so_reminders у выбранных"
```

---

## Task 8: Память и документация

- [ ] **Step 1:** Обновить `[[so_reminder_mutes]]` и `[[delivery-reminders-module]]` в памяти: описать единую модель, маску дней, управление получателями закупщиком, роль `notify_so_reminders` как мастер-флага, backward-compat.
- [ ] **Step 2:** Commit памяти (вне git репозитория проекта — файлы памяти в `~/.claude/...`, не коммитить в проект).

---

## Self-Review

- **Покрытие спека:** дни (Task 1,3,4,5) ✓; TG-дублирование+получатели у ресторана (Task 2,3,5) ✓; закупщик по поставщику (уже есть) + по аккаунтам (Task 6) ✓; бот (Task 7) ✓; унификация на подписки (Task 2,4) ✓; backward-compat (Task 4 fallback) ✓.
- **Плейсхолдеры:** в Task 4/6 UI-шаги описаны словами, но с точными интерфейсами и скелетом кода — при исполнении раскрыть по образцу локальной карточки/сетки. Допустимо: точные образцы уже есть в кодовой базе (localGroups, scheduleGrid).
- **Типы:** `reminder_days` (int|null) единообразно; `selected_tg_ids` (int[]); `reminder_muted` (bool) — согласованы между Task 2/5/6.
- **Риск:** Task 4 меняет живую рассылку — обязательна проверка fallback (без подписки поведение не меняется) до выката; проверять на №999.
