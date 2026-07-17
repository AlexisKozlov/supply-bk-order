# Excel-опции в настройках поставщика + паллеты/вес по ресторанам: Implementation Plan

> REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Шаги — чекбоксы.

**Goal:** Перенести опции Excel-отчёта заявки в «Настройки» поставщика (убрать галочки при скачивании). Сделать «паллеты и вес» настраиваемыми — выбор показателей галочками (коробки / паллеты / вес нетто / вес брутто). Показывать паллеты/вес НЕ только сводкой снизу по товарам, но и СТОЛБЦАМИ справа у каждой строки ресторана (его итог по выбранным показателям). Сводка по товарам снизу остаётся.

**Решения пользователя:** (1) опции только в настройках, галочки при скачивании убрать; (2) «паллеты/вес» = выбор показателей галочками (числа из справочника); (3) у ресторана — столбцы справа + сводка по товарам снизу.

**Контекст (проверено в коде):**
- Общий движок листа: `src/lib/soOrderXlsx.js` (`buildSoOrderSheet(XLSX, opts)`). Сейчас `options = { dropEmptyRows, showPalletWeight }`. showPalletWeight добавляет ВНИЗУ 4 строки по каждому товару (Коробок / Паллет (доля) / Вес нетто / Вес брутто) — суммарно по товару (по totalPieces). Матрица: строки = рестораны (по городам, подытоги + ИТОГО), столбцы = товары, ячейки = штуки. Заголовок: ['№','Адрес', ...товары, 'Пометка'].
- Скачивание строит фронт `exportExcel` (`SupplierOrdersManagerView.vue`), читает 2 галочки `optDropEmptyRows`/`optShowPalletWeight` (~в блоке экспорта) и передаёт в builder; эти же значения шлёт в отправку (`sendSummary`/`sendSummaryEmail` → `drop_empty_rows`/`show_pallet_weight`).
- Отправка поставщику: `soBuildSummaryXlsx` (`api/includes/supplier_orders.php` ~83) → node `scripts/build_so_order_xlsx.mjs` → тот же движок. Опции приходят из эндпоинтов `send-summary`/`send-summary-email` (тело `drop_empty_rows`/`show_pallet_weight`). `soBuildSummaryXlsx($pdo, $supplierId, $deliveryDate, $options)`.
- Атрибуты товара: `qty_per_box`, `boxes_per_pallet`, `weight_netto`, `weight_brutto` (вес — на КОРОБКУ в ГРАММАХ). Формулы: коробки = qty/qty_per_box; паллеты(доля) = коробки/boxes_per_pallet; вес нетто кг = коробки*weight_netto/1000; вес брутто кг = коробки*weight_brutto/1000.
- Настройки: `so_supplier_settings`; `soGetSupplierSettings`, `settings` GET/POST (ЧАСТИЧНО-БЕЗОПАСНЫЙ, паттерн weekly_*/min_order_*). Раздел «⚙️ Настройки» — во `SupplierOrdersManagerView.vue`.

**Architecture:**
- Хранилище: 2 столбца в `so_supplier_settings` (миграция): `xlsx_drop_empty` TINYINT(1) NULL DEFAULT 0 (1 = убирать пустые строки); `xlsx_pallet_metrics` VARCHAR(60) NULL — CSV подмножества `boxes,pallets,netto,brutto` (NULL/пусто = паллеты/вес ВЫКЛ). Наличие хотя бы одного показателя = показывать паллеты/вес (столбцы справа + сводка снизу) по выбранным показателям.
- Движок `buildSoOrderSheet`: новый контракт `options = { dropEmptyRows = false, palletMetrics = [] }` (заменить showPalletWeight). Если palletMetrics непусто:
  - СТОЛБЦЫ СПРАВА (после товаров, перед 'Пометка'): по одному на выбранный показатель, метка «Коробок»/«Паллет»/«Вес нетто, кг»/«Вес брутто, кг». В строке ресторана — ЕГО итог по показателю (Σ по его товарам). В подытоге города и ИТОГО — суммы. Неподал/не нужна — как в матрице (0/пусто).
  - СВОДКА СНИЗУ по товарам — как сейчас, но только выбранные показатели (строки по palletMetrics), значения по totalPieces на товар.
  - dropEmptyRows — без изменений.
- Опции берутся ИЗ НАСТРОЕК поставщика в обоих путях: фронт `exportExcel` читает `xlsx_drop_empty`/`xlsx_pallet_metrics` из настроек поставщика (убрать галочки); сервер `soBuildSummaryXlsx` грузит их из so_supplier_settings и передаёт в payload/движок. Эндпоинты `send-summary`/`send-summary-email` больше НЕ принимают эти флаги из тела (берут из настроек).
- Админ-UI: блок «Отчёт Excel» в «Настройках»: тумблер «Убрать пустые строки» + чекбоксы показателей паллет/веса. Сохранение партиал-безопасное (только xlsx_* ключи).

## Global Constraints
- **Регрессия — критерий №1:** поставщик без настроек Excel (xlsx_drop_empty=0, xlsx_pallet_metrics NULL) → отчёт как СЕЙЧАС при обеих галочках ВЫКЛ (матрица без паллет/веса, без удаления пустых). Существующая вёрстка/стили/подсветка/подытоги не меняются.
- Числа паллет/веса — из справочника (по ГРУППЕ юрлиц на серверном пути; на фронте — из атрибутов, что уже приходят в products). Формулы и округления те же (коробки 2 знака, паллеты 2 знака, кг 1 знак; хвостовые нули убирать).
- Белый список показателей: `boxes,pallets,netto,brutto`. Настройки партиал-безопасны (не затирать weekly_*/reminder_*/min_order_*/базовые/notify_users).
- Русские подписи. Часть товаров без атрибутов — вклад 0 (не падать, не делить на 0).
- Не ломать: подачу, отправку сводок (TG/почта/крон), прочие настройки, скачивание при выключенных опциях.

## File Structure
- `migrations/20260717_so_xlsx_options.sql` — НОВАЯ миграция (2 столбца).
- `api/includes/supplier_orders.php` — `soGetSupplierSettings` (+xlsx_*); `settings` POST (+xlsx_*, партиал); `soBuildSummaryXlsx` (грузить xlsx_* из настроек, класть в payload options); эндпоинты `send-summary`/`send-summary-email` (перестать читать флаги из тела).
- `scripts/build_so_order_xlsx.mjs` — принять `options.palletMetrics` вместо showPalletWeight.
- `src/lib/soOrderXlsx.js` — новый контракт опций + столбцы справа + настраиваемая сводка снизу.
- `src/views/SupplierOrdersManagerView.vue` — убрать галочки экспорта; `exportExcel` читает опции из настроек; блок «Отчёт Excel» в «Настройках»; sendSummary/sendSummaryEmail без флагов.
- `src/stores/supplierOrderStore.js` — adminSendSummary/adminSendSummaryEmail без флагов (или игнор).

---

## Task 1: Миграция + бэкенд настроек Excel

**Files:** Create `migrations/20260717_so_xlsx_options.sql`; modify `api/includes/supplier_orders.php`

- [ ] **Step 1 (миграция):** `ALTER TABLE so_supplier_settings ADD COLUMN xlsx_drop_empty TINYINT(1) NULL DEFAULT 0, ADD COLUMN xlsx_pallet_metrics VARCHAR(60) NULL DEFAULT NULL;` Комментарий: drop_empty 1=убирать пустые строки; pallet_metrics CSV из boxes,pallets,netto,brutto (NULL/пусто = паллеты/вес выкл). Применить к БД.
- [ ] **Step 2 (чтение):** `soGetSupplierSettings` — SELECT и выдача `xlsx_drop_empty` (bool/int) и `xlsx_pallet_metrics` (МАССИВ из CSV ∩ белый список `['boxes','pallets','netto','brutto']` в этом порядке; NULL/пусто → []). Обе ветки (строка есть/нет) — дефолты (0 / []).
- [ ] **Step 3 (сохранение):** `settings` POST — принять `xlsx_drop_empty` (0/1) и `xlsx_pallet_metrics` (массив/CSV → валидировать по белому списку → CSV) ПАРТИАЛ-БЕЗОПАСНО (паттерн min_order_*/weekly_*). Не сломать существующий мерж.

**Verification:** `php -l` чисто; миграция применена; GET/POST читают/пишут xlsx_* партиал-безопасно (не трогают weekly_*/reminder_*/min_order_*/базовые); мусор в метриках отброшен; регрессия — без настроек отдаёт 0/[].

---

## Task 2: Движок листа — столбцы справа у ресторана + настраиваемая сводка снизу

**Files:** Modify `src/lib/soOrderXlsx.js`

- [ ] **Step 1 (контракт опций):** заменить `showPalletWeight` на `palletMetrics = []` (массив из `boxes,pallets,netto,brutto`). `dropEmptyRows` оставить. Пустой массив = паллеты/вес не показывать (регрессия к текущему «выкл»).
- [ ] **Step 2 (столбцы справа):** когда palletMetrics непусто — добавить в заголовок ПОСЛЕ товаров и ПЕРЕД 'Пометка' по столбцу на каждый выбранный показатель с метками: boxes→«Коробок», pallets→«Паллет», netto→«Вес нетто, кг», brutto→«Вес брутто, кг». Для КАЖДОЙ строки ресторана вычислить его итог по показателю по ЕГО товарам: коробки=Σ(qty/qty_per_box); паллеты=Σ(коробки_товара/boxes_per_pallet); нетто=Σ(коробки_товара*weight_netto/1000); брутто=Σ(коробки_товара*weight_brutto/1000). Товары без атрибутов пропускать. Округление как в сводке. Подытог города и ИТОГО — суммы этих столбцов. Неподал/не нужна — пусто/0 согласованно с матрицей. Стили ячеек — как у соседних (qty/subtotal/total), заголовки — как товарные. Учесть `!merges` заголовка (спан по всей ширине), `!cols` (ширины новых столбцов), индексы применения стилей (Пометка теперь дальше).
- [ ] **Step 3 (сводка снизу настраиваемая):** блок нижних строк по товарам показывать ТОЛЬКО для выбранных показателей (одна строка на показатель из palletMetrics), метки те же. Значения по totalPieces на товар (как сейчас). В новых правых столбцах у нижних строк — пусто.
- [ ] **Step 4:** регрессия при palletMetrics=[] — лист идентичен текущему «выкл» (нет правых столбцов, нет нижних строк).

**Verification:** node-smoke: собрать лист с palletMetrics=['boxes','netto'] — справа 2 столбца с верными итогами по ресторану, снизу 2 строки по товарам; deps паллет/вес считаются; деления на 0 нет; при [] — как раньше; индексы стилей/merges/cols не разъехались (файл открывается, значения на местах).

---

## Task 3: Прокинуть опции из настроек в оба пути (скачивание + отправка), убрать галочки

**Files:** Modify `api/includes/supplier_orders.php`, `scripts/build_so_order_xlsx.mjs`, `src/views/SupplierOrdersManagerView.vue`, `src/stores/supplierOrderStore.js`

- [ ] **Step 1 (сервер):** `soBuildSummaryXlsx` — грузить `xlsx_drop_empty`/`xlsx_pallet_metrics` из so_supplier_settings (или из soGetSupplierSettings) и класть в `$payload['options'] = ['dropEmptyRows'=>bool, 'palletMetrics'=>[...]]`. Эндпоинты `send-summary`/`send-summary-email` — БОЛЬШЕ НЕ читать `drop_empty_rows`/`show_pallet_weight` из тела (брать из настроек). node `build_so_order_xlsx.mjs` — принять `options.palletMetrics` (и dropEmptyRows), пробросить в движок; поддержать snake/camel как раньше.
- [ ] **Step 2 (фронт скачивание):** `exportExcel` — вместо галочек читать опции из настроек текущего поставщика (`settings.value.xlsx_drop_empty`, `settings.value.xlsx_pallet_metrics`); гарантировать, что настройки загружены для скачивания (если нет — подгрузить). Передать `{ dropEmptyRows, palletMetrics }` в builder. УБРАТЬ 2 галочки экспорта (`optDropEmptyRows`/`optShowPalletWeight`) из шаблона и логики.
- [ ] **Step 3 (фронт отправка):** `sendSummary`/`sendSummaryEmail` и стор `adminSendSummary`/`adminSendSummaryEmail` — перестать слать флаги опций (сервер берёт из настроек). Не сломать вызовы.
- [ ] **Step 4:** `npm run build`.

**Verification:** скачивание и отправка берут опции ИЗ настроек поставщика (одинаково); галочек при скачивании нет; поставщик без настроек — отчёт как раньше; сборка чистая; `php -l` чисто.

---

## Task 4: Админ-UI «Отчёт Excel» в «Настройках»

**Files:** Modify `src/views/SupplierOrdersManagerView.vue`, при необходимости `src/stores/supplierOrderStore.js`

- [ ] **Step 1:** В теле вкладки `pageTab==='settings'` — блок «Отчёт Excel»: тумблер «Убрать пустые строки» (`xlsx_drop_empty`), чекбоксы показателей «Коробки»(boxes)/«Паллеты»(pallets)/«Вес нетто»(netto)/«Вес брутто»(brutto) (`xlsx_pallet_metrics`). Инициализация из настроек в loadSettings/sync. Подсказка: «Показатели выводятся столбцами у каждого ресторана и сводкой по товарам внизу».
- [ ] **Step 2:** Кнопка «Сохранить» (по аналогии saveWeekly/saveMinOrder) шлёт ТОЛЬКО `{ xlsx_drop_empty, xlsx_pallet_metrics }` через `store.adminSaveSettings`. После ответа — обновить settings/refs, тост.
- [ ] **Step 3:** `npm run build`.

**Verification (после сборки):** блок виден; тумблер+чекбоксы сохраняются и остаются после перезагрузки; сохранение не сбрасывает прочие настройки; отчёт (скачивание/отправка) отражает выбранные показатели; поставщик без настроек — как раньше.

---

## Task 5: Миграция, сборка, ручная проверка, память

- [ ] **Step 1:** Применить миграцию (Claude); `npm run build`.
- [ ] **Step 2:** Ручная проверка: настроить показатели у поставщика; скачать и (тестово) сгенерировать серверный отчёт — столбцы справа у ресторанов + сводка снизу по выбранным показателям; «убрать пустые» работает; без настроек — как раньше.
- [ ] **Step 3:** Обновить память (`supplier-orders-*`) и леджер.

**Verification:** миграция применена; сборка чистая; сценарии пройдены; регрессия подтверждена.

## Notes
- Паллеты у ресторана — доля (сумма долей по товарам); это оценка занимаемого места, не число физических паллет.
- Ширина листа растёт на число выбранных показателей — приемлемо (обычно 1–4 столбца).
