# Stage 4 — Excel-опции (нулевые строки + паллеты/вес): Implementation Plan

> REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Шаги — чекбоксы.

**Goal:** Две опциональные функции Excel-отчёта заявок (по умолчанию выкл): убрать пустые строки ресторанов; добавить сводку по паллетам/весу/коробкам по каждому товару. Обе включаются галочками при экспорте закупщика.

**Architecture:** Флаги `hide_zero_rows`/`show_pallet_weight` идут от галочек UI → `adminGetExport` → эндпоинт `so/admin/export` → новый параметр `$options` в `soBuildSummaryXlsx()` → payload → генератор `build_so_order_xlsx.mjs`. При `show_pallet_weight` бэкенд обогащает товары полями `qty_per_box/boxes_per_pallet/weight_netto/weight_brutto` из `products` (по группе юрлиц). Без флагов — поведение и байты отчёта не меняются.

**Спецификация:** `docs/superpowers/specs/2026-07-16-so-excel-options-design.md`.

## Global Constraints

- Обе опции по умолчанию ВЫКЛ; при выкл отчёт идентичен нынешнему (регресс-защита — проверять).
- Существующие вызовы `soBuildSummaryXlsx` (send-summary, send-summary-email, cron) НЕ меняют поведения (дефолтный `$options`).
- Справочник `products` — по ГРУППЕ юрлиц поставщика (`getEntitiesInGroup`), как остальные справочники.
- Вес `weight_netto/brutto` — на ОДНУ КОРОБКУ в ГРАММАХ; `boxes_per_pallet` — коробок на паллету; `qty_per_box` — штук в коробке.
- Единицу количества `so_order_items` (штуки/коробки) ОПРЕДЕЛИТЬ из кода (как вводится/показывается заявка), НЕ угадывать; формула boxes зависит от этого.
- Русский UI/тексты. Бэкенд живой из `api/`; фронт — `npm run build`. Node-генератор запускается тем же способом (`exec node ...`). Миграций нет.

## File Structure

- `api/includes/supplier_orders.php` — `soBuildSummaryXlsx()` +$options +обогащение товаров; эндпоинт `export` читает query.
- `scripts/build_so_order_xlsx.mjs` — фильтр нулевых строк + сводные строки паллет/веса.
- `src/stores/supplierOrderStore.js` — `adminGetExport(supplierId, date, options)`.
- `src/views/SupplierOrdersManagerView.vue` — 2 галочки у экспорта.

---

## Task 1: Единица количества + обогащение товаров (бэкенд, подготовка)

**Files:** Modify `api/includes/supplier_orders.php` (+ разведка по коду).

- [ ] **Step 1: Определить единицу `so_order_items.quantity`.** Изучить, как ресторан вводит/видит количество (например `RestaurantCabinetView.vue`, `so/submit-order`, шаблоны `so_templates.multiplicity/min_qty`, отображение в панели). Записать вывод (штуки или коробки) в отчёт — это основа формулы boxes в Task 2. Если данные хранятся в штуках → `boxes = qty / qty_per_box`; если в коробках → `boxes = qty`.
- [ ] **Step 2: Обогащение товаров.** В `soBuildSummaryXlsx` добавить (только когда понадобится, т.е. при `show_pallet_weight`) выборку атрибутов из `products` по списку `sku` заказа, фильтр по `getEntitiesInGroup($supplierGroup)` (как справочники). Собрать map `sku → {qty_per_box, boxes_per_pallet, weight_netto, weight_brutto}`. Приложить эти поля к каждому элементу `products_map`/`$productsOut` (0/null если товара нет).

**Verification:** `php -l` чисто; в отчёте зафиксирована единица количества с обоснованием (ссылка на код); обогащение не ломает текущий payload (поля добавочные).

---

## Task 2: Параметр `$options` + прокидывание в payload/эндпоинт (бэкенд)

**Files:** Modify `api/includes/supplier_orders.php`

- [ ] **Step 1:** Сигнатура `soBuildSummaryXlsx($pdo, $supplierId, $deliveryDate, array $options = [])`. Из `$options` читать `hide_zero_rows` (bool), `show_pallet_weight` (bool). Обогащение товаров (Task 1 Step 2) выполнять только при `show_pallet_weight`.
- [ ] **Step 2:** В `$payload` добавить `options => ['hide_zero_rows'=>..., 'show_pallet_weight'=>...]` и обогащённые поля в `products`.
- [ ] **Step 3:** Эндпоинт `so/admin/export` (GET): читать `$_GET['hide_zero']`, `$_GET['pallet_weight']` (=== '1'), собрать `$options`, передать в `soBuildSummaryXlsx`. Остальные вызовы (send-summary, email, cron) оставить БЕЗ options (дефолт).

**Verification:** `php -l` чисто; без query-параметров экспорт зовёт с пустым options; существующие вызовы не тронуты.

---

## Task 3: Генератор — фильтр строк + сводные строки (node)

**Files:** Modify `scripts/build_so_order_xlsx.mjs`

- [ ] **Step 1:** Прочитать `data.options` и новые поля товаров.
- [ ] **Step 2 (hide_zero_rows):** при построении data-строк пропускать ресторан, у которого сумма количеств по всем товарам = 0. Города без оставшихся data-строк — не выводить (заголовок+подытог). Подытоги/«ИТОГО» — по оставшимся (текущая логика сумм уже это делает, если исключить строки из групп).
- [ ] **Step 3 (show_pallet_weight):** после «ИТОГО» добавить 4 строки: «Коробок», «Место на паллете (паллет)», «Вес нетто, кг», «Вес брутто, кг». Первая колонка — метка (как «ИТОГО»), далее по каждому столбцу-товару: boxes = (единица из Task 1); pallets = boxes/boxes_per_pallet; netto_kg = boxes*weight_netto/1000; brutto_kg = boxes*weight_brutto/1000. Округление: коробки/паллеты 2 знака, кг 1 знак. Пустое значение при отсутствии атрибутов. Стиль — как итоговые строки.
- [ ] **Step 4:** Без флагов — вывод байт-в-байт как раньше (не добавлять строки, не фильтровать).

**Verification:** запуск генератора на тест-JSON без опций = прежний файл; с `hide_zero_rows` — нет пустых строк; с `show_pallet_weight` — 4 строки снизу с ожидаемыми числами (сверить вручную на 1-2 товарах).

---

## Task 4: Фронтенд — галочки экспорта

**Files:** Modify `src/stores/supplierOrderStore.js`, `src/views/SupplierOrdersManagerView.vue`

- [ ] **Step 1 (стор):** `adminGetExport(supplierId, date, options = {})` — добавить в query `hide_zero=1`/`pallet_weight=1` по флагам `options.hideZero`/`options.palletWeight`; сохранить обратную совместимость (без options — как сейчас).
- [ ] **Step 2 (вью):** рядом с экспортом (вкладка «Статус») — 2 галочки: `exportHideZero`, `exportPalletWeight` (ref, по умолчанию false); при экспорте передавать их в `adminGetExport`. Русские подписи: «Убрать пустые строки», «Паллеты и вес».
- [ ] **Step 3:** `npm run build`.

**Verification:** галочки видны; экспорт с ними шлёт нужные query; без них — как раньше.

---

## Task 5: Сборка и ручная проверка

- [ ] `npm run build`; пройти сценарии из спецификации (раздел «Проверка», п.1–5). Зафиксировать в отчёте; значения паллет/веса свериться на реальном заказе (пользователь подтвердит).

## Notes

- Единица количества — критичный пункт Task 1; при сомнении показать пользователю пример расчёта.
- Округление и подписи колонок легко поменять после первого реального отчёта.
