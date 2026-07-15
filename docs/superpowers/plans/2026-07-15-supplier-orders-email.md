# Этап 1 — Почта поставщику: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Отправка Excel-сводки заявок поставщику по email — кнопкой вручную и автоматически в дедлайн (по поставщику, по умолчанию выключено).

**Architecture:** Переиспользуем существующую механику сборки сводки (`send-summary` в `api/includes/supplier_orders.php`, node-генератор `scripts/build_so_order_xlsx.mjs`) и почтовую инфраструктуру (`sendEmail()` c CC/вложениями, шаблон `renderMailHtml()`). Логику «данные за день → xlsx» выносим в общий хелпер, которым пользуются и Telegram-сводка, и новая email-отправка (эндпоинт для кнопки и цикл в кроне `cron_telegram.php`). Адреса берём из `suppliers.email` + `suppliers.cc_emails`. Защита авто-дублей — таблица-сторож с INSERT IGNORE (как `so_auto_submit_log`).

**Tech Stack:** PHP 8 + MariaDB (PDO), PHPMailer, Vue 3 (`<script setup>`) + Pinia, node (генератор xlsx). Тест-фреймворка в проекте нет — верификация через CLI-скрипты на PHP, curl и ручную проверку UI/почты.

## Global Constraints

- Язык проекта — русский; все тексты писем и UI на русском.
- Рабочие данные фильтровать по юрлицам ГРУППЫ поставщика (`getEntitiesInGroup(group)`), НЕ по одному юрлицу; справочник `suppliers` — по группе. (Правило проекта: рабочие данные по одному юрлицу, но сводка so по своей природе собирается по группе BK_VM/PS — как в существующем `send-summary`.)
- Бэкенд отдаётся напрямую из `api/` (не из `dist/api`) — правки PHP живые без сборки. Фронтенд требует `npm run build`.
- Почтовый аккаунт для писем поставщику — `account => 'order'`, Reply-To `order@supply-department.online` (как в отправке заявки/плана).
- Миграции применяются вручную (их применяет Claude сам).
- `so_email_log` — чистый лог без уникальных ключей; защита авто-дублей — отдельная таблица `so_email_auto_log` с `UNIQUE(supplier_id, delivery_date)`.
- Имя поставщика в теме/тексте письма — `suppliers.short_name` (как в Telegram-сводке).
- Лимит вложения — 4 МБ (как в существующих отправках).

---

## File Structure

- `migrations/20260715_so_email_summary.sql` — **создать**: колонка `auto_email_summary` + таблицы `so_email_log`, `so_email_auto_log`.
- `api/includes/supplier_orders.php` — **изменить**:
  - новый хелпер `soBuildSummaryXlsx()` (вынести сборку данных+xlsx из `send-summary`);
  - рефактор блока `send-summary` на этот хелпер;
  - новый хелпер `soSendSummaryEmail()` (письмо + лог);
  - новый эндпоинт `POST so/admin/send-summary-email`;
  - `soGetSupplierSettings()` и блок `settings` (GET/POST) — добавить `auto_email_summary`.
- `api/cron_telegram.php` — **изменить**: цикл авто-отправки писем после блока авто-подстановки.
- `src/stores/supplierOrderStore.js` — **изменить**: метод `adminSendSummaryEmail()`.
- `src/views/SupplierOrdersManagerView.vue` — **изменить**: кнопка «Отправить на почту», чекбокс авто-отправки, обработчики.

---

## Task 1: Миграция БД

**Files:**
- Create: `migrations/20260715_so_email_summary.sql`

**Interfaces:**
- Produces: колонка `so_supplier_settings.auto_email_summary TINYINT(1) DEFAULT 0`; таблицы `so_email_log`, `so_email_auto_log`.

- [ ] **Step 1: Написать миграцию**

Создать `migrations/20260715_so_email_summary.sql`:

```sql
-- Этап 1 апгрейда «Заявки поставщикам»: отправка сводки поставщику по email.
-- 1) Флаг авто-отправки письма в дедлайн (по поставщику, по умолчанию выкл).
-- 2) Журнал отправок писем (аудит, без уникальных ключей).
-- 3) Таблица-сторож для защиты авто-отправки от дублей (аналог so_auto_submit_log).

ALTER TABLE `so_supplier_settings`
  ADD COLUMN `auto_email_summary` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Слать сводку поставщику на email автоматически в дедлайн';

CREATE TABLE IF NOT EXISTS `so_email_log` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `supplier_id` CHAR(36) NOT NULL,
  `delivery_date` DATE NOT NULL,
  `legal_entity` VARCHAR(255) NULL DEFAULT NULL,
  `recipients` TEXT NULL,
  `cc_recipients` TEXT NULL,
  `subject` VARCHAR(255) NULL,
  `restaurants_count` INT NOT NULL DEFAULT 0,
  `items_count` INT NOT NULL DEFAULT 0,
  `trigger_type` ENUM('manual','auto') NOT NULL DEFAULT 'manual',
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `error_message` TEXT NULL,
  `sender_user_name` VARCHAR(255) NULL,
  `ip_address` VARCHAR(64) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_supplier_date` (`supplier_id`, `delivery_date`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `so_email_auto_log` (
  `supplier_id` CHAR(36) NOT NULL,
  `delivery_date` DATE NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_supplier_date` (`supplier_id`, `delivery_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 2: Применить миграцию**

Run:
```bash
php -r '$e=[];foreach(file("/var/www/bk-calc-secrets/.env",FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $l){if($l[0]==="#")continue;$p=explode("=",$l,2);if(count($p)==2)$e[trim($p[0])]=trim($p[1]);}$pdo=new PDO("mysql:host={$e["DB_HOST"]};dbname={$e["DB_NAME"]};charset=utf8mb4",$e["DB_USER"],$e["DB_PASS"]);$pdo->exec(file_get_contents("migrations/20260715_so_email_summary.sql"));echo "OK\n";'
```
Expected: `OK`

- [ ] **Step 3: Проверить схему**

Run:
```bash
php -r '$e=[];foreach(file("/var/www/bk-calc-secrets/.env",FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $l){if($l[0]==="#")continue;$p=explode("=",$l,2);if(count($p)==2)$e[trim($p[0])]=trim($p[1]);}$pdo=new PDO("mysql:host={$e["DB_HOST"]};dbname={$e["DB_NAME"]};charset=utf8mb4",$e["DB_USER"],$e["DB_PASS"]);foreach(["so_email_log","so_email_auto_log"] as $t){$pdo->query("SELECT 1 FROM $t LIMIT 1");echo "$t ok\n";}$c=$pdo->query("SHOW COLUMNS FROM so_supplier_settings LIKE \"auto_email_summary\"")->fetch();echo $c?"column ok\n":"COLUMN MISSING\n";'
```
Expected: `so_email_log ok` / `so_email_auto_log ok` / `column ok`

- [ ] **Step 4: Commit**

```bash
git add migrations/20260715_so_email_summary.sql
git commit -m "БД: флаг авто-письма поставщику + журналы отправки сводки"
```

---

## Task 2: Общий хелпер сборки сводки (данные + xlsx)

Выносим сборку «данные за день → payload → xlsx» из `send-summary` в отдельную функцию, чтобы её использовали и Telegram-сводка, и email. Telegram-сводка должна продолжать работать без изменений в поведении.

**Files:**
- Modify: `api/includes/supplier_orders.php` (добавить `soBuildSummaryXlsx()` рядом с прочими `so*`-хелперами ~строка 60–110; отрефакторить блок `send-summary` ~строки 2495–2641)

**Interfaces:**
- Produces:
  ```php
  // Возвращает сводку за день + готовый xlsx.
  // status: 'ok' | 'empty' (никто не подал) | 'closed' | 'no_schedule' | 'xlsx_error'
  soBuildSummaryXlsx(PDO $pdo, string $supplierId, string $deliveryDate): array
  // [
  //   'status' => string,
  //   'supplier' => ['short_name','legal_entity','legal_entity_group'] | null,
  //   'xlsx' => ?string,            // бинарник (только при status='ok')
  //   'filename' => string,         // "Заявка <short> на дд.мм.гггг.xlsx"
  //   'date_fmt' => string,         // "дд.мм.гггг"
  //   'restaurants_count' => int,   // ресторанов по графику
  //   'submitted_count' => int,     // подали
  //   'items_count' => int,         // строк-позиций с qty>0
  //   'error' => ?string,           // текст при xlsx_error
  // ]
  ```

- [ ] **Step 1: Написать хелпер `soBuildSummaryXlsx()`**

Вставить после `soGetSupplierSettings()` (около строки 75). Код (перенос логики из `send-summary`, строки 2464–2639, без Telegram-специфики):

```php
/**
 * Собирает сводку заявок поставщика за день и готовый xlsx-бинарник.
 * Общая логика для Telegram-сводки, ручной email-отправки и крона.
 */
function soBuildSummaryXlsx(PDO $pdo, string $supplierId, string $deliveryDate): array {
    $out = [
        'status' => 'ok', 'supplier' => null, 'xlsx' => null, 'filename' => '',
        'date_fmt' => '', 'restaurants_count' => 0, 'submitted_count' => 0,
        'items_count' => 0, 'error' => null,
    ];

    $supRow = $pdo->prepare("SELECT short_name, legal_entity, legal_entity_group FROM suppliers WHERE id = ?");
    $supRow->execute([$supplierId]);
    $sup = $supRow->fetch();
    if (!$sup) { $out['status'] = 'no_schedule'; return $out; }
    $out['supplier'] = $sup;
    $supName = $sup['short_name'];
    $supplierGroup = $sup['legal_entity_group'] ?: getEntityGroup($sup['legal_entity'] ?? '');
    $supplierEntities = getEntitiesInGroup($supplierGroup);
    $entityPh = implode(',', array_fill(0, count($supplierEntities), '?'));

    $deadlineState = soCalculateDeadline($pdo, $supplierId, $deliveryDate);
    if (!empty($deadlineState['forced_closed'])) { $out['status'] = 'closed'; return $out; }

    $expectedRests = array_values(array_filter(
        soGetEffectiveScheduleRows($pdo, $supplierId, $deliveryDate, null, true),
        fn($row) => soDeliveryDateMatchesDow($deliveryDate, (int)$row['delivery_day'])
            && (($row['legal_entity_group'] ?? '') === $supplierGroup)
    ));
    usort($expectedRests, function ($a, $b) {
        $regionCmp = strcmp((string)($a['region'] ?? ''), (string)($b['region'] ?? ''));
        if ($regionCmp !== 0) return $regionCmp;
        return (int)($a['restaurant_number'] ?? 0) <=> (int)($b['restaurant_number'] ?? 0);
    });
    if (!$expectedRests) { $out['status'] = 'no_schedule'; return $out; }

    $expectedNums = array_values(array_unique(array_map('strval', array_column($expectedRests, 'restaurant_number'))));
    $expectedPh = implode(',', array_fill(0, count($expectedNums), '?'));

    $subStmt = $pdo->prepare("
        SELECT restaurant_number FROM so_orders
        WHERE supplier_id = ? AND delivery_date = ? AND status != 'draft'
          AND legal_entity IN ({$entityPh}) AND restaurant_number IN ({$expectedPh})");
    $subStmt->execute(array_merge([$supplierId, $deliveryDate], $supplierEntities, $expectedNums));
    $submittedNums = array_flip($subStmt->fetchAll(PDO::FETCH_COLUMN));

    $ordStmt = $pdo->prepare("
        SELECT o.restaurant_number, oi.sku, oi.product_name,
               COALESCE(oi.admin_qty, oi.quantity) AS qty
        FROM so_orders o JOIN so_order_items oi ON oi.order_id = o.id
        WHERE o.supplier_id = ? AND o.delivery_date = ? AND o.status != 'draft'
          AND o.legal_entity IN ({$entityPh}) AND o.restaurant_number IN ({$expectedPh})
          AND COALESCE(oi.admin_qty, oi.quantity) > 0");
    $ordStmt->execute(array_merge([$supplierId, $deliveryDate], $supplierEntities, $expectedNums));
    $orderRows = $ordStmt->fetchAll();

    $productsOrdered = []; $pivot = [];
    foreach ($orderRows as $row) {
        $sku = $row['sku'];
        if (!isset($productsOrdered[$sku])) $productsOrdered[$sku] = ['sku' => $sku, 'name' => $row['product_name']];
        $rn = $row['restaurant_number'];
        if (!isset($pivot[$rn])) $pivot[$rn] = [];
        $pivot[$rn][$sku] = ($pivot[$rn][$sku] ?? 0) + (float)$row['qty'];
    }
    uasort($productsOrdered, fn($a, $b) => strcmp($a['name'], $b['name']));

    $dateFmt = (new DateTime($deliveryDate))->format('d.m.Y');
    $out['date_fmt'] = $dateFmt;
    $out['restaurants_count'] = count($expectedRests);
    $out['submitted_count'] = count(array_intersect($expectedNums, array_keys($submittedNums)));
    $out['items_count'] = count($orderRows);
    $out['filename'] = "Заявка {$supName} на {$dateFmt}.xlsx";

    if (!$productsOrdered) { $out['status'] = 'empty'; return $out; }

    $productsOut = array_values($productsOrdered);
    $restaurantsOut = [];
    foreach ($expectedRests as $rest) {
        $rn = (string)($rest['restaurant_number'] ?? '');
        if ($rn === '') continue;
        $restaurantsOut[] = [
            'number' => (int)$rn, 'city' => $rest['city'] ?: '', 'region' => $rest['region'] ?: '',
            'address' => $rest['address'] ?: '', 'submitted' => isset($submittedNums[$rn]),
        ];
    }
    $itemsOut = new stdClass();
    foreach ($pivot as $rn => $pmap) {
        foreach ($pmap as $sku => $qty) $itemsOut->{"{$rn}_{$sku}"} = ['qty' => (float)$qty, 'is_admin' => false];
    }
    $payload = [
        'supplier_name' => $supName, 'delivery_date_fmt' => $dateFmt, 'sheet_name' => $supName,
        'products' => $productsOut, 'restaurants' => $restaurantsOut, 'items' => $itemsOut,
    ];

    $tmpJson = tempnam(sys_get_temp_dir(), 'so_json_');
    $tmpXlsx = tempnam(sys_get_temp_dir(), 'so_xlsx_') . '.xlsx';
    file_put_contents($tmpJson, json_encode($payload, JSON_UNESCAPED_UNICODE));
    $scriptPath = escapeshellarg(__DIR__ . '/../../scripts/build_so_order_xlsx.mjs');
    $cmd = 'node ' . $scriptPath . ' ' . escapeshellarg($tmpJson) . ' ' . escapeshellarg($tmpXlsx) . ' 2>&1';
    exec($cmd, $outLines, $rc);
    @unlink($tmpJson);
    if ($rc !== 0 || !file_exists($tmpXlsx)) {
        @unlink($tmpXlsx);
        error_log('[soBuildSummaryXlsx] node failed (rc=' . $rc . '): ' . implode("\n", $outLines));
        $out['status'] = 'xlsx_error';
        $out['error'] = implode(' ', $outLines);
        return $out;
    }
    $out['xlsx'] = file_get_contents($tmpXlsx);
    @unlink($tmpXlsx);
    return $out;
}
```

- [ ] **Step 2: Проверить синтаксис файла**

Отдельный CLI-прогон `soBuildSummaryXlsx` требует бутстрапа всего приложения (helpers/зависимости) и хрупок, поэтому поведение хелпера проверяем сквозным регресс-тестом Telegram-сводки в Step 4 (он полностью прогоняет `soBuildSummaryXlsx`). Здесь — только синтаксис:

Run:
```bash
php -l /var/www/bk-calc/api/includes/supplier_orders.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: Отрефакторить `send-summary` на хелпер**

В блоке `if ($adminAction === 'send-summary' && $method === 'POST')` (строки ~2457–2678) заменить самостоятельную сборку данных/payload/xlsx (строки ~2464–2660) на вызов `soBuildSummaryXlsx()`, сохранив Telegram-специфику (подписчики, дедуп-ключ `tg_notification_log`, тексты caption). Итоговый блок:

```php
    if ($adminAction === 'send-summary' && $method === 'POST') {
        $supplierId = $body['supplier_id'] ?? '';
        $deliveryDate = $body['delivery_date'] ?? '';
        if (!$supplierId || !$deliveryDate) soRespond(['error' => 'Не указан поставщик или дата'], 400);
        soRequireAdminSupplierAccess($pdo, $sessionUser, $supplierId);

        // Подписчики Telegram
        $subsStmt = $pdo->prepare("
            SELECT u.name, u.telegram_chat_id FROM so_supplier_summary_subscribers sss
            JOIN users u ON u.name = sss.user_name
            WHERE sss.supplier_id = ? AND u.telegram_chat_id IS NOT NULL AND u.telegram_chat_id != ''");
        $subsStmt->execute([$supplierId]);
        $subs = $subsStmt->fetchAll();
        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        if (!$subs) soRespond(['error' => 'Нет подписчиков для этого поставщика'], 400);
        if (!$botToken) soRespond(['error' => 'Telegram Bot Token не настроен'], 500);

        $sum = soBuildSummaryXlsx($pdo, $supplierId, $deliveryDate);
        if ($sum['status'] === 'closed')      soRespond(['error' => 'Дата доставки закрыта'], 400);
        if ($sum['status'] === 'no_schedule') soRespond(['error' => 'Нет ресторанов в графике на этот день'], 400);
        if ($sum['status'] === 'xlsx_error')  soRespond(['error' => 'Не удалось сгенерировать Excel: ' . $sum['error']], 500);

        $supName = $sum['supplier']['short_name'];
        $dateFmt = $sum['date_fmt'];
        $deliveryDow = (int)(new DateTime($deliveryDate))->format('N');
        $dayNames = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'];
        $dayShort = $dayNames[$deliveryDow] ?? '';
        $dedupKey = "so_summary_{$supplierId}_{$deliveryDate}";
        $perUser = $pdo->prepare("INSERT INTO tg_notification_log (notification_type, legal_entity, chat_id, notification_key) VALUES (?, '', ?, ?)");

        if ($sum['status'] === 'empty') {
            $caption = "⚠️ <b>Никто не подал заявку</b>\n"
                . "📦 Поставщик: <b>" . htmlspecialchars($supName, ENT_QUOTES) . "</b>\n"
                . "📅 Доставка: <b>{$dateFmt} ({$dayShort})</b>\n"
                . "🏪 Ресторанов по графику: <b>{$sum['restaurants_count']}</b>";
            $sentCount = 0;
            foreach ($subs as $sub) {
                $ok = sendTelegramMessage($botToken, $sub['telegram_chat_id'], $caption);
                $perUser->execute([$ok ? 'so_summary_sent' : 'so_summary_fail', $sub['telegram_chat_id'], $dedupKey]);
                if ($ok) $sentCount++;
            }
            $pdo->prepare("INSERT INTO tg_notification_log (notification_type, legal_entity, chat_id, notification_key) VALUES ('so_summary', '', 0, ?)")->execute([$dedupKey]);
            soRespond(['success' => true, 'sent' => $sentCount, 'total_subs' => count($subs), 'mode' => 'text_only']);
        }

        $missingCount = $sum['restaurants_count'] - $sum['submitted_count'];
        $caption = "🧾 <b>Заказ поставщику</b> (повторная отправка)\n"
            . "📦 Поставщик: <b>" . htmlspecialchars($supName, ENT_QUOTES) . "</b>\n"
            . "📅 Доставка: <b>{$dateFmt} ({$dayShort})</b>\n\n"
            . "✅ Подали: <b>{$sum['submitted_count']}</b> из <b>{$sum['restaurants_count']}</b>\n";
        if ($missingCount > 0) $caption .= "❌ Не подали: <b>{$missingCount}</b>\n";

        $sentCount = 0;
        foreach ($subs as $sub) {
            $ok = sendTelegramDocument($botToken, $sub['telegram_chat_id'], $sum['filename'], $sum['xlsx'], $caption);
            $perUser->execute([$ok ? 'so_summary_sent' : 'so_summary_fail', $sub['telegram_chat_id'], $dedupKey]);
            if ($ok) $sentCount++;
        }
        $pdo->prepare("INSERT INTO tg_notification_log (notification_type, legal_entity, chat_id, notification_key) VALUES ('so_summary', '', 0, ?)")->execute([$dedupKey]);
        soRespond(['success' => true, 'sent' => $sentCount, 'total_subs' => count($subs)]);
    }
```

Примечание: убран блок «топ-5 товаров» в caption (он опирался на `$colTotals`). Если хочется сохранить — вернуть расчёт `col_totals` из хелпера; на согласовании решили упростить (детали в файле). Это единственное изменение поведения Telegram-сводки.

- [ ] **Step 4: Ручная проверка Telegram-сводки (регресс)**

В UI `/supplier-orders` выбрать поставщика Камако и день с заявками → «Отправить сводку в Telegram». Убедиться, что подписчик получил файл. Ожидается: xlsx приходит, текст-подпись корректна.

- [ ] **Step 5: Commit**

```bash
git add api/includes/supplier_orders.php
git commit -m "so: общий хелпер soBuildSummaryXlsx + рефактор send-summary"
```

---

## Task 3: Функция письма + эндпоинт ручной отправки

**Files:**
- Modify: `api/includes/supplier_orders.php` (хелпер `soSendSummaryEmail()` рядом с `soBuildSummaryXlsx()`; эндпоинт в блоке `if ($soAction === 'admin')`, рядом с `send-summary`)

**Interfaces:**
- Consumes: `soBuildSummaryXlsx()` (Task 2); `sendEmail()`, `renderMailHtml()`.
- Produces:
  ```php
  // Собирает и отправляет письмо со сводкой. Пишет в so_email_log.
  // Для 'auto' предварительно захватывает право через so_email_auto_log (INSERT IGNORE).
  soSendSummaryEmail(PDO $pdo, string $supplierId, string $deliveryDate,
                     string $triggerType, ?string $senderName = null, ?string $ip = null): array
  // ['success'=>bool, 'skipped'=>?string, 'error'=>?string,
  //  'restaurants_count'=>int, 'items_count'=>int]
  ```
- Эндпоинт: `POST /api/so/admin/send-summary-email` body `{supplier_id, delivery_date}`.

- [ ] **Step 1: Убедиться, что почтовые хелперы подключены**

Проверить наличие require в `api/index.php` (mail_send.php, mail_templates.php). Если `supplier_orders.php` вызывается там, где они уже загружены (index.php грузит их до роутинга) — ок. Иначе в начало `soSendSummaryEmail()` добавить:
```php
require_once __DIR__ . '/mail_send.php';
require_once __DIR__ . '/mail_templates.php';
```
Run (проверить, где грузятся):
```bash
grep -n "mail_send\|mail_templates" /var/www/bk-calc/api/index.php
```
Если не найдено — добавить оба require в `soSendSummaryEmail()` (безопасно, обёрнуты в `function_exists`).

- [ ] **Step 2: Написать `soSendSummaryEmail()`**

Вставить после `soBuildSummaryXlsx()`:

```php
/**
 * Отправляет сводку заявок поставщику на email + пишет в so_email_log.
 * trigger: 'manual' | 'auto'. Для 'auto' защита от дублей через so_email_auto_log.
 */
function soSendSummaryEmail(PDO $pdo, string $supplierId, string $deliveryDate, string $triggerType, ?string $senderName = null, ?string $ip = null): array {
    require_once __DIR__ . '/mail_send.php';
    require_once __DIR__ . '/mail_templates.php';

    // Захват права на авто-отправку (одно письмо на поставщика+день).
    if ($triggerType === 'auto') {
        $lock = $pdo->prepare("INSERT IGNORE INTO so_email_auto_log (supplier_id, delivery_date) VALUES (?, ?)");
        $lock->execute([$supplierId, $deliveryDate]);
        if ($lock->rowCount() === 0) return ['success' => false, 'skipped' => 'already_sent', 'restaurants_count' => 0, 'items_count' => 0];
    }

    $sum = soBuildSummaryXlsx($pdo, $supplierId, $deliveryDate);
    $rc = $sum['restaurants_count']; $ic = $sum['items_count'];
    if ($sum['status'] !== 'ok') {
        // Нет заявок / закрыто / нет графика / ошибка xlsx — не отправляем.
        return ['success' => false, 'skipped' => $sum['status'], 'error' => $sum['error'] ?? null, 'restaurants_count' => $rc, 'items_count' => $ic];
    }

    // Адреса
    $addr = $pdo->prepare("SELECT short_name, email, cc_emails FROM suppliers WHERE id = ?");
    $addr->execute([$supplierId]);
    $s = $addr->fetch();
    $toEmail = trim((string)($s['email'] ?? ''));
    if ($toEmail === '') return ['success' => false, 'skipped' => 'no_email', 'restaurants_count' => $rc, 'items_count' => $ic];
    $ccList = array_values(array_filter(array_map('trim', explode(',', (string)($s['cc_emails'] ?? '')))));

    $supName = $s['short_name'];
    $dateFmt = $sum['date_fmt'];
    $subject = "Заявки на {$dateFmt} — {$supName}";
    $bodyHtml = renderMailHtml([
        'title'   => 'Заявки ресторанов',
        'preview' => "Сводка заявок на {$dateFmt}",
        'intro'   => 'Здравствуйте!',
        'body'    => "<p>Направляем заявки ресторанов на доставку <b>{$dateFmt}</b>.</p>"
                   . "<p>Ресторанов: <b>{$sum['submitted_count']}</b> из <b>{$sum['restaurants_count']}</b>. Позиций: <b>{$ic}</b>.</p>"
                   . "<p>Подробности — в приложенном файле Excel.</p>",
        'footer'  => 'Это письмо сформировано автоматически системой отдела закупок.',
    ]);

    $attachB64 = base64_encode($sum['xlsx']);
    // Лимит вложения 4 МБ (в base64 ~ *1.34).
    if (strlen($attachB64) > 4 * 1024 * 1024 * 4 / 3) {
        soLogEmail($pdo, $supplierId, $deliveryDate, $sum, $toEmail, $ccList, $subject, $triggerType, false, 'attachment_too_large', $senderName, $ip);
        return ['success' => false, 'error' => 'attachment_too_large', 'restaurants_count' => $rc, 'items_count' => $ic];
    }

    $res = sendEmail($toEmail, $subject, $bodyHtml, true, [
        'account' => 'order',
        'reply_to' => 'order@supply-department.online',
        'cc' => $ccList,
        'attachments' => [['filename' => $sum['filename'], 'content_b64' => $attachB64,
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']],
    ]);
    $ok = !empty($res['success']);
    soLogEmail($pdo, $supplierId, $deliveryDate, $sum, $toEmail, $ccList, $subject, $triggerType, $ok, $ok ? null : ($res['error'] ?? 'send_failed'), $senderName, $ip);
    return ['success' => $ok, 'error' => $ok ? null : ($res['error'] ?? 'send_failed'), 'restaurants_count' => $rc, 'items_count' => $ic];
}

/** Пишет строку в so_email_log. */
function soLogEmail(PDO $pdo, string $supplierId, string $deliveryDate, array $sum, string $to, array $cc, string $subject, string $trigger, bool $success, ?string $err, ?string $senderName, ?string $ip): void {
    $le = $sum['supplier']['legal_entity'] ?? null;
    $pdo->prepare("INSERT INTO so_email_log
        (supplier_id, delivery_date, legal_entity, recipients, cc_recipients, subject, restaurants_count, items_count, trigger_type, success, error_message, sender_user_name, ip_address)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$supplierId, $deliveryDate, $le, $to, implode(',', $cc), mb_substr($subject, 0, 255),
            (int)$sum['restaurants_count'], (int)$sum['items_count'], $trigger, $success ? 1 : 0,
            $err ? mb_substr($err, 0, 1000) : null, $senderName, $ip]);
}
```

- [ ] **Step 3: Добавить эндпоинт `send-summary-email`**

Сразу после блока `send-summary` (после его закрывающей `}` ~строка 2678) вставить:

```php
    if ($adminAction === 'send-summary-email' && $method === 'POST') {
        $supplierId = $body['supplier_id'] ?? '';
        $deliveryDate = $body['delivery_date'] ?? '';
        if (!$supplierId || !$deliveryDate) soRespond(['error' => 'Не указан поставщик или дата'], 400);
        soRequireAdminSupplierAccess($pdo, $sessionUser, $supplierId);

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $senderName = $sessionUser['name'] ?? null;
        $r = soSendSummaryEmail($pdo, $supplierId, $deliveryDate, 'manual', $senderName, $ip);

        if (!empty($r['success'])) {
            soRespond(['success' => true, 'restaurants_count' => $r['restaurants_count'], 'items_count' => $r['items_count']]);
        }
        $map = [
            'no_email'    => 'У поставщика не указана почта',
            'empty'       => 'Нет заявок за этот день',
            'closed'      => 'Дата доставки закрыта',
            'no_schedule' => 'Нет ресторанов в графике на этот день',
        ];
        $skip = $r['skipped'] ?? null;
        $msg = $map[$skip] ?? ('Не удалось отправить письмо' . (!empty($r['error']) ? ': ' . $r['error'] : ''));
        soRespond(['error' => $msg], 400);
    }
```

- [ ] **Step 4: Проверить эндпоинт (сначала должен отдавать «нет почты» на поставщике без email)**

Убедиться, что у тестового поставщика нет email → эндпоинт вернёт «У поставщика не указана почта». Затем через БД проставить тестовый email и повторить.

Run (получить session-token тестового admin — см. memory `test_credentials.md`; ниже curl-шаблон):
```bash
# 1) без email → ожидаем 400 "У поставщика не указана почта"
curl -s -X POST 'https://supply-department.online/api/so/admin/send-summary-email' \
  -H 'X-Session-Token: <ADMIN_TOKEN>' -H 'Content-Type: application/json' \
  -d '{"supplier_id":"998f1395-e2be-4ddc-8b6f-211e010cb95a","delivery_date":"<ДАТА>"}'
```
Expected: `{"error":"У поставщика не указана почта"}` (или «Нет заявок…»/«Нет ресторанов…» в зависимости от даты).

- [ ] **Step 5: Проверить успешную отправку на тестовый email**

Проставить тестовому поставщику email на свой ящик и повторить curl. Проверить, что письмо пришло с вложением-Excel и корректной темой.
```bash
php -r '$e=[];foreach(file("/var/www/bk-calc-secrets/.env",FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $l){if($l[0]==="#")continue;$p=explode("=",$l,2);if(count($p)==2)$e[trim($p[0])]=trim($p[1]);}$pdo=new PDO("mysql:host={$e["DB_HOST"]};dbname={$e["DB_NAME"]};charset=utf8mb4",$e["DB_USER"],$e["DB_PASS"]);$pdo->prepare("UPDATE suppliers SET email=? WHERE id=?")->execute(["alexiskilironus@gmail.com","998f1395-e2be-4ddc-8b6f-211e010cb95a"]);echo "email set\n";'
```
Expected: письмо получено; в `so_email_log` строка `success=1, trigger_type=manual`.

- [ ] **Step 6: Commit**

```bash
git add api/includes/supplier_orders.php
git commit -m "so: письмо сводки поставщику + эндпоинт send-summary-email"
```

---

## Task 4: Настройка авто-отправки в settings (бэкенд)

**Files:**
- Modify: `api/includes/supplier_orders.php` — `soGetSupplierSettings()` (строка ~61) и блок `settings` POST (строка ~1276+)

**Interfaces:**
- Produces: поле `auto_email_summary` читается в GET settings и сохраняется в POST settings.

- [ ] **Step 1: Добавить колонку в `soGetSupplierSettings()`**

Заменить SELECT (строка 61):
```php
    $s = $pdo->prepare("SELECT supplier_id, is_accepting_orders, auto_submit_previous, auto_email_summary, default_deadline_time, pause_message FROM so_supplier_settings WHERE supplier_id = ?");
```
И в дефолтном массиве-фолбэке (когда строки нет) добавить `'auto_email_summary' => 0,`.

- [ ] **Step 2: Сохранять `auto_email_summary` в POST settings**

В блоке `if ($adminAction === 'settings' && $method === 'POST')` после `$autoSubmitPrev = ...` добавить:
```php
        $autoEmailSummary = !empty($body['auto_email_summary']) ? 1 : 0;
```
И расширить `INSERT ... ON DUPLICATE KEY UPDATE` (строки ~1287+), добавив колонку:
```php
        $pdo->prepare("INSERT INTO so_supplier_settings (supplier_id, is_accepting_orders, auto_submit_previous, auto_email_summary, default_deadline_time, pause_message, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              is_accepting_orders = VALUES(is_accepting_orders),
              auto_submit_previous = VALUES(auto_submit_previous),
              auto_email_summary = VALUES(auto_email_summary),
              default_deadline_time = VALUES(default_deadline_time),
              pause_message = VALUES(pause_message),
              updated_by = VALUES(updated_by)")
            ->execute([$supplierId, $isAccepting, $autoSubmitPrev, $autoEmailSummary, $defaultDl, $pauseMsg, $updatedBy]);
```
(Сверить точный текущий INSERT в файле и добавить только `auto_email_summary` в список колонок, VALUES и UPDATE.)

- [ ] **Step 3: Проверить сохранение**

Run:
```bash
curl -s -X POST 'https://supply-department.online/api/so/admin/settings' \
  -H 'X-Session-Token: <ADMIN_TOKEN>' -H 'Content-Type: application/json' \
  -d '{"supplier_id":"998f1395-e2be-4ddc-8b6f-211e010cb95a","is_accepting_orders":1,"auto_submit_previous":0,"auto_email_summary":1,"default_deadline_time":"14:00:00"}'
```
Then:
```bash
php -r '$e=[];foreach(file("/var/www/bk-calc-secrets/.env",FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $l){if($l[0]==="#")continue;$p=explode("=",$l,2);if(count($p)==2)$e[trim($p[0])]=trim($p[1]);}$pdo=new PDO("mysql:host={$e["DB_HOST"]};dbname={$e["DB_NAME"]};charset=utf8mb4",$e["DB_USER"],$e["DB_PASS"]);var_dump($pdo->query("SELECT auto_email_summary FROM so_supplier_settings WHERE supplier_id=\"998f1395-e2be-4ddc-8b6f-211e010cb95a\"")->fetchColumn());'
```
Expected: `string(1) "1"`. После проверки вернуть в 0.

- [ ] **Step 4: Commit**

```bash
git add api/includes/supplier_orders.php
git commit -m "so: сохранение флага auto_email_summary в настройках поставщика"
```

---

## Task 5: Авто-отправка в кроне

**Files:**
- Modify: `api/cron_telegram.php` — новый блок после закрытия цикла авто-подстановки (после `try {...} catch` блока, начинающегося на строке ~1067; вставить сразу за его закрывающей скобкой)

**Interfaces:**
- Consumes: `soSendSummaryEmail()`, `soCalculateDeadlineCore()`, `soGetEffectiveScheduleRows()`.

- [ ] **Step 1: Написать блок авто-отправки писем**

Вставить после блока авто-подстановки (после его `} catch (...) {...}`), самостоятельным `try`:

```php
// Авто-отправка сводки поставщику на email в дедлайн.
// Отдельно от auto_submit_previous: критерий — so_supplier_settings.auto_email_summary=1
// и непустой suppliers.email. Идём тем же окном дедлайна (0..15 мин после), одно
// письмо на (поставщик, день) — защита в soSendSummaryEmail через so_email_auto_log.
try {
    $tz = new DateTimeZone('Europe/Minsk');
    $now = new DateTime('now', $tz);
    $emailSuppliers = $pdo->query("
        SELECT s.id, COALESCE(sst.default_deadline_time, '14:00:00') AS default_deadline_time
        FROM suppliers s
        JOIN so_supplier_settings sst ON sst.supplier_id = s.id
        WHERE s.is_active = 1 AND s.so_enabled = 1
          AND sst.auto_email_summary = 1
          AND s.email IS NOT NULL AND s.email <> ''
    ")->fetchAll();

    foreach ($emailSuppliers as $sup) {
        $supId = $sup['id'];
        $defaultDl = $sup['default_deadline_time'];
        for ($iDay = 0; $iDay < 15; $iDay++) {
            $dObj = (clone $now)->setTime(0, 0, 0)->modify("+{$iDay} days");
            $deliveryDate = $dObj->format('Y-m-d');
            $deliveryDow = (int)$dObj->format('N');

            $ovStmt = $pdo->prepare("SELECT deadline_date, deadline_time, is_closed FROM so_deadline_overrides WHERE supplier_id = ? AND delivery_date = ?");
            $ovStmt->execute([$supId, $deliveryDate]);
            $ov = $ovStmt->fetch() ?: null;
            $rlStmt = $pdo->prepare("SELECT deadline_dow, deadline_time FROM supplier_default_deadlines WHERE supplier_id = ? AND delivery_dow = ?");
            $rlStmt->execute([$supId, $deliveryDow]);
            $rule = $rlStmt->fetch() ?: null;

            $r = soCalculateDeadlineCore($ov, $rule, $defaultDl, $deliveryDate, $tz);
            if (!empty($r['forced_closed']) || !$r['deadline_dt']) continue;
            $minutesSinceDeadline = ($now->getTimestamp() - $r['deadline_dt']->getTimestamp()) / 60;
            if ($minutesSinceDeadline < -1 || $minutesSinceDeadline > 15) continue;

            // Одно письмо на поставщика+день; skipped='already_sent' при повторе.
            $res = soSendSummaryEmail($pdo, $supId, $deliveryDate, 'auto', null, null);
            if (!empty($res['success'])) {
                error_log("[so auto-email] sent supplier={$supId} date={$deliveryDate} rests={$res['restaurants_count']}");
            } elseif (!empty($res['skipped']) && $res['skipped'] !== 'already_sent' && $res['skipped'] !== 'empty') {
                error_log("[so auto-email] skip supplier={$supId} date={$deliveryDate} reason={$res['skipped']}");
            }
        }
    }
} catch (Throwable $e) {
    error_log('[so auto-email] fatal: ' . $e->getMessage());
}
```

- [ ] **Step 2: Проверить синтаксис**

Run:
```bash
php -l /var/www/bk-calc/api/cron_telegram.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: Проверить срабатывание (имитация дедлайна)**

На тестовом поставщике: проставить `email` (свой ящик), `auto_email_summary=1`, и разовый override дедлайна `so_deadline_overrides` так, чтобы дедлайн для ближайшей даты доставки был «только что» (в окне 0..15 мин назад), затем прогнать крон:
```bash
php /var/www/bk-calc/api/cron_telegram.php
```
Expected: пришло письмо; в `so_email_auto_log` появилась строка; в `so_email_log` — `trigger_type=auto, success=1`.

- [ ] **Step 4: Проверить отсутствие дублей**

Прогнать крон повторно:
```bash
php /var/www/bk-calc/api/cron_telegram.php
```
Expected: письмо НЕ пришло второй раз; новых строк в `so_email_auto_log` нет. После теста убрать override и вернуть флаги.

- [ ] **Step 5: Commit**

```bash
git add api/cron_telegram.php
git commit -m "so: авто-отправка сводки поставщику на email в дедлайн (крон)"
```

---

## Task 6: Кнопка «Отправить на почту» (фронт)

**Files:**
- Modify: `src/stores/supplierOrderStore.js` (добавить метод рядом с `adminSendSummary`, строка ~228)
- Modify: `src/views/SupplierOrdersManagerView.vue` (кнопка в `rom-export-row` ~строка 129; обработчик рядом с `sendSummary`)

**Interfaces:**
- Consumes: эндпоинт `POST so/admin/send-summary-email` (Task 3).

- [ ] **Step 1: Метод в store**

После `adminSendSummary` (строка ~228) добавить:
```js
  async function adminSendSummaryEmail(supplierId, deliveryDate) {
    return apiFetch('/api/so/admin/send-summary-email', {
      method: 'POST',
      body: JSON.stringify({ supplier_id: supplierId, delivery_date: deliveryDate }),
    });
  }
```
И добавить `adminSendSummaryEmail` в `return { ... }` стора (там же, где экспортируется `adminSendSummary`).

Примечание: сверить имя функции обёртки запроса — в файле это строка 17 (`fetch(url, {...})`); использовать тот же приватный помощник, что и `adminSendSummary` (например `apiFetch`/`request`). Скопировать точную форму из тела `adminSendSummary`.

- [ ] **Step 2: Кнопка в шаблоне**

Рядом с кнопкой Telegram-сводки (строка ~129, `@click="sendSummary"`) добавить:
```html
            <button class="rom-btn" @click="sendSummaryEmail" :disabled="sendingSummaryEmail || !selectedDate"
              title="Сгенерировать Excel и отправить на почту поставщика">
              {{ sendingSummaryEmail ? 'Отправка…' : '✉️ На почту поставщику' }}
            </button>
```

- [ ] **Step 3: Обработчик и ref**

Рядом с `sendingSummary` (в `<script setup>`) добавить `const sendingSummaryEmail = ref(false);` и функцию:
```js
async function sendSummaryEmail() {
  if (!selectedDate.value || !currentSupplierId.value) return;
  sendingSummaryEmail.value = true;
  try {
    const r = await store.adminSendSummaryEmail(currentSupplierId.value, selectedDate.value);
    if (r?.error) { toast.warning('Не отправлено', r.error); }
    else { toast.success('Отправлено', `Сводка ушла на почту поставщика (ресторанов: ${r.restaurants_count ?? '—'})`); }
  } catch (e) {
    toast.error('Ошибка', e?.message || 'Не удалось отправить письмо');
  } finally {
    sendingSummaryEmail.value = false;
  }
}
```
(Сверить имена `selectedDate`, `currentSupplierId`, `toast`, `store` — они уже используются в файле, см. `sendSummary`.)

- [ ] **Step 4: Сборка и ручная проверка**

Run:
```bash
cd /var/www/bk-calc && npm run build 2>&1 | tail -5
```
Expected: `сборка завершена` без ошибок. Затем в UI: выбрать поставщика с проставленным тестовым email и день с заявками → «✉️ На почту поставщику» → тост «Отправлено», письмо пришло. Проверить поставщика без email → тост «У поставщика не указана почта».

- [ ] **Step 5: Commit**

```bash
git add src/stores/supplierOrderStore.js src/views/SupplierOrdersManagerView.vue
git commit -m "so-фронт: кнопка «Отправить на почту» в панели закупщика"
```

---

## Task 7: Чекбокс авто-отправки (фронт)

**Files:**
- Modify: `src/views/SupplierOrdersManagerView.vue` — рядом с чекбоксом `auto_submit_previous` (строка ~50), обработчик рядом с `toggleAutoSubmit` (строка ~858), payload `currentSettingsPayload` (строка ~835), дефолт `settings` (строки 601, 810)

**Interfaces:**
- Consumes: GET/POST `so/admin/settings` с полем `auto_email_summary` (Task 4).

- [ ] **Step 1: Дефолты settings**

В обоих местах, где инициализируется `settings` (строка 601 и фолбэк на 810), добавить `auto_email_summary: 0` в объект по умолчанию.

- [ ] **Step 2: Payload сохранения**

В `currentSettingsPayload` (строка ~835) в возвращаемый объект добавить:
```js
    auto_email_summary: settings.value.auto_email_summary ? 1 : 0,
```

- [ ] **Step 3: Чекбокс + обработчик**

Рядом с чекбоксом авто-подстановки (строка ~50) добавить:
```html
        <label style="font-size:12px;color:#666;display:inline-flex;align-items:center;gap:4px;margin-left:8px;"
          title="Если включено — после дедлайна система сама отправит сводку заявок на почту поставщика">
          <input type="checkbox" :checked="!!settings.auto_email_summary" @change="toggleAutoEmail" />
          Авто-письмо поставщику в дедлайн
        </label>
```
Рядом с `toggleAutoSubmit` (строка ~858) добавить:
```js
async function toggleAutoEmail(e) {
  const next = e.target.checked ? 1 : 0;
  try {
    settings.value.auto_email_summary = next;
    await store.adminSaveSettings(currentSupplierId.value, currentSettingsPayload({ auto_email_summary: next }));
    toast.success('Сохранено', next ? 'Авто-письмо включено' : 'Авто-письмо выключено');
  } catch (err) {
    settings.value.auto_email_summary = next ? 0 : 1;
    toast.error('Ошибка', err?.message || 'Не удалось сохранить');
  }
}
```
(Сверить сигнатуру `currentSettingsPayload` — если она принимает объект-переопределение, как в `toggleAutoSubmit` строка 860, использовать так же; иначе вызвать без аргумента после установки `settings.value.auto_email_summary`.)

- [ ] **Step 4: Сборка и проверка**

Run:
```bash
cd /var/www/bk-calc && npm run build 2>&1 | tail -5
```
Expected: сборка без ошибок. В UI: включить чекбокс у поставщика → перезагрузить страницу → чекбокс остался включён (значение сохранилось). Выключить обратно.

- [ ] **Step 5: Commit**

```bash
git add src/views/SupplierOrdersManagerView.vue
git commit -m "so-фронт: чекбокс авто-письма поставщику в настройках"
```

---

## Task 8: Сквозная проверка и очистка

**Files:** нет изменений кода (только проверка и уборка тестовых данных).

- [ ] **Step 1: Сквозной сценарий вручную**

1. Поставщику с тестовым email и днём с заявками — кнопка «На почту»: письмо с вложением пришло, тема «Заявки на дд.мм — <Поставщик>», в `so_email_log` запись `manual/success=1`.
2. Поставщик без email — кнопка даёт понятный тост.
3. Включить авто-письмо + имитировать дедлайн + прогон крона — письмо пришло, `auto/success=1`, повторный прогон не задваивает.
4. Telegram-сводка (регресс) по-прежнему работает.

- [ ] **Step 2: Убрать тестовые данные**

Run:
```bash
php -r '$e=[];foreach(file("/var/www/bk-calc-secrets/.env",FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $l){if($l[0]==="#")continue;$p=explode("=",$l,2);if(count($p)==2)$e[trim($p[0])]=trim($p[1]);}$pdo=new PDO("mysql:host={$e["DB_HOST"]};dbname={$e["DB_NAME"]};charset=utf8mb4",$e["DB_USER"],$e["DB_PASS"]);$pdo->exec("UPDATE suppliers SET email=\"\" WHERE id=\"998f1395-e2be-4ddc-8b6f-211e010cb95a\" AND email=\"alexiskilironus@gmail.com\"");$pdo->exec("UPDATE so_supplier_settings SET auto_email_summary=0 WHERE supplier_id=\"998f1395-e2be-4ddc-8b6f-211e010cb95a\"");echo "cleaned\n";'
```
Удалить тестовые строки из `so_email_auto_log`/`so_email_log`, если мешают.

- [ ] **Step 3: Финальный коммит (если остались правки)**

```bash
git add -A
git commit -m "so: завершение этапа 1 — почта поставщику" || echo "нечего коммитить"
```

---

## Развёртывание

Бэкенд-PHP уже живой из `api/`. Фронтенд задеплоится при `npm run build` (Задачи 6–7). Миграция применяется в Задаче 1. Отдельного деплой-шага не требуется; при желании — влить ветку `feature/supplier-orders-email` в `main` после ручной проверки.
