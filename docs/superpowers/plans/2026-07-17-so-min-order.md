# Минимальный заказ у поставщика (жёсткий блок): Implementation Plan

> REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Шаги — чекбоксы.

**Goal:** У поставщика — настраиваемый минимальный заказ ПО СУММЕ всей заявки, с ЖЁСТКИМ блоком подачи, если итог ниже минимума. Единица настраивается: килограммы (по весам из справочника) ИЛИ количество (штуки, сумма количеств). Ресторан в кабинете видит минимум и живой итог. «Поставка не нужна» (пустой отказ) под блок не попадает.

**Контекст (проверено в коде):**
- Подача: `POST so/submit-order` в `api/includes/supplier_orders.php` (~1224). Уже есть блок валидации по каждому товару (кратность + min_qty из `so_templates`, ~1256–1288). Итоговый минимум добавляем ТУДА ЖЕ (после per-item, до сохранения). `$items` = массив `{sku, quantity, product_id, product_name}`; `$skipDelivery` (~1233) = пустой отказ; при `$skipDelivery` и пустых items итоговый минимум НЕ проверять.
- Настройки: `so_supplier_settings`; `soGetSupplierSettings` (~59–124, уже отдаёт reminder_*/weekly_*); эндпоинт `settings` POST (~1806+) ЧАСТИЧНО-БЕЗОПАСНЫЙ (читает текущую строку, обновляет только присутствующие ключи; базовые поля мержатся из $curRow). Раздел «⚙️ Настройки» — во `SupplierOrdersManagerView.vue` (вкладка `pageTab==='settings'`), рядом блоки «Напоминания», «Недельный режим».
- Веса: `products` (по ГРУППЕ юрлиц) имеет `qty_per_box`, `weight_netto`, `weight_brutto` (вес — на КОРОБКУ в ГРАММАХ), `boxes_per_pallet`. Кг позиции = `(quantity / qty_per_box) * weight_netto / 1000`. Единица quantity — ШТУКИ. Образец обогащения по SKU в группе есть в `soBuildSummaryXlsx` (~152–184).
- Кабинет-форма ресторана: `src/views/SupplierOrderFormView.vue`. Грузит товары `soStore.loadProducts(supplierId)` → `products` (массив с sku), количества — `quantities[sku]`. Отправка — `items = products.filter(qty>0).map({sku,quantity})`. Для живого итога в кг форме нужны `qty_per_box`/`weight_netto` у товаров (добавить в loadProducts) и минимум поставщика (value+unit).
- Решение пользователя: единица настраиваемая (кг ИЛИ штуки); блок жёсткий.

**Architecture:**
- Хранилище: 2 столбца в `so_supplier_settings` (миграция): `min_order_value` DECIMAL(10,2) NULL (NULL/0 = минимума нет), `min_order_unit` VARCHAR(8) NULL ('kg'|'pieces'; дефолт при заданном value — 'kg').
- Сервер (submit-order): при непустой заявке и заданном `min_order_value>0` — посчитать итог в единице: pieces = Σ quantity; kg = Σ (quantity/qty_per_box)*weight_netto/1000 по SKU (веса из products по ГРУППЕ юрлиц ресторана/поставщика). Если итог < минимума — вернуть 422 с понятным сообщением (минимум, текущий итог, сколько добавить). При `$skipDelivery` (пустой отказ) — не проверять. Товары без веса (0) в kg-режиме дают вклад 0 (не падать; при желании — учесть только считаемые).
- Настройки GET/POST: отдавать/сохранять `min_order_value`, `min_order_unit` (партиал-безопасно, как weekly_*).
- Админ-UI: блок «Минимальный заказ» в «Настройках» (единица + число).
- Ресторан-UI: `loadProducts` отдаёт веса (qty_per_box/weight_netto) и минимум поставщика (value+unit) доступен форме (через suppliers GET / available_dates или loadProducts-ответ); форма показывает «минимум X кг/шт» и живой итог; предупреждение и (мягко) блок кнопки при недоборе. Сервер — гарантия.

## Global Constraints
- **Регрессия — критерий №1:** поставщик без минимума (`min_order_value` NULL/0) — подача, форма, настройки работают как сейчас. Блок не срабатывает.
- «Поставка не нужна» (skip_delivery, пустая заявка) НЕ блокируется минимумом.
- Веса/справочник — по ГРУППЕ юрлиц (getEntitiesInGroup), НЕ по одному юрлицу.
- Русские сообщения, понятные (пользователь и ресторан — не программисты). Числа округлять разумно (кг — 1 знак, штуки — целые/по факту).
- Партиал-безопасность настроек: сохранение min_order_* не затирает приём/авто-*/reminder_*/weekly_*/notify_users и наоборот.
- Параметризация SQL; unit — белый список ('kg'|'pieces'); value ≥ 0.
- Не ломать подачу, per-item валидацию, дедлайны, недельный режим.

## File Structure
- `migrations/20260717_so_min_order.sql` — НОВАЯ миграция (2 столбца).
- `api/includes/supplier_orders.php` — `soGetSupplierSettings` (+min_order_*); `settings` POST (+min_order_*, партиал); submit-order (итоговый минимум-блок); `loadProducts`-эндпоинт (+веса qty_per_box/weight_netto) и прокинуть min_order поставщика в ресторанные ответы (suppliers GET / loadProducts).
- `src/views/SupplierOrdersManagerView.vue` — блок «Минимальный заказ» в «Настройках».
- `src/views/SupplierOrderFormView.vue` — показ минимума + живой итог + предупреждение.
- `src/stores/supplierOrderStore.js` — при необходимости проброс полей (loadProducts/suppliers/save).

---

## Task 1: Миграция + бэкенд (настройки + жёсткий блок на подаче)

**Files:** Create `migrations/20260717_so_min_order.sql`; modify `api/includes/supplier_orders.php`

- [ ] **Step 1 (миграция):** `ALTER TABLE so_supplier_settings ADD COLUMN min_order_value DECIMAL(10,2) NULL DEFAULT NULL, ADD COLUMN min_order_unit VARCHAR(8) NULL DEFAULT NULL;` Комментарий: value NULL/0 = минимума нет; unit 'kg'|'pieces'. Применить к БД.
- [ ] **Step 2 (чтение):** `soGetSupplierSettings` — SELECT и выдача `min_order_value` (float|null) и `min_order_unit` ('kg'|'pieces'|null; при заданном value и null unit → 'kg'). Обе ветки (строка есть/нет) — дефолты null.
- [ ] **Step 3 (сохранение):** `settings` POST — принять `min_order_value` (число ≥0; 0/''/null → NULL = выключить) и `min_order_unit` (белый список 'kg'|'pieces', иначе 'kg' при заданном value). ПАРТИАЛ-БЕЗОПАСНО (как weekly_*: array_key_exists → валидировать и писать, нет → текущее из БД). Не сломать существующий мерж.
- [ ] **Step 4 (жёсткий блок на подаче):** В `submit-order` в блоке валидации (после per-item, до транзакции) — если НЕ `$skipDelivery`, заявка непустая и у поставщика `min_order_value>0`: посчитать итог. pieces: Σ floatval(quantity). kg: подтянуть по SKU из products (группа юрлиц поставщика/ресторана) `qty_per_box`, `weight_netto`; Σ (qty/qty_per_box)*weight_netto/1000 (пропуская нулевые делители). Если итог < min_order_value − ε → soRespond 422 с сообщением: «Минимальный заказ у поставщика — {min} {ед}. В заявке {итог} {ед}. Добавьте ещё {diff} {ед}.» ({ед} = «кг»/«шт»). Округление: кг 1 знак, шт — по факту.

**Verification:** `php -l` чисто; миграция применена; GET/POST настроек читают/пишут min_order_* партиал-безопасно (не затирают weekly_*/reminder_*/базовые); подача ниже минимума в kg и в pieces → 422 с верным сообщением; подача ≥ минимума проходит; skip_delivery и поставщик без минимума — без блока; веса берутся по группе (без утечки юрлиц).

---

## Task 2: Админ-UI «Минимальный заказ» в «Настройках»

**Files:** Modify `src/views/SupplierOrdersManagerView.vue`, при необходимости `src/stores/supplierOrderStore.js`

- [ ] **Step 1:** В теле вкладки `pageTab==='settings'` — блок «Минимальный заказ»: переключатель (есть/нет минимума) ИЛИ просто поле значения (0/пусто = нет), выбор единицы (кг/штуки), число. Начальные значения из `settings.min_order_value`/`min_order_unit` (в loadSettings). Подсказка: «Если задан — заявку меньше минимума нельзя отправить».
- [ ] **Step 2:** Кнопка «Сохранить» (своя, по аналогии с saveWeekly/saveReminders) шлёт ТОЛЬКО `{ min_order_value, min_order_unit }` через `store.adminSaveSettings`. Выкл/0 → `min_order_value: null`. После ответа — обновить settings и refs, тост.
- [ ] **Step 3:** `npm run build`.

**Verification (после сборки):** блок виден; значение+единица сохраняются и остаются после перезагрузки; выключение (0/пусто) убирает минимум; сохранение минимума не сбрасывает прочие настройки; обычные поставщики без изменений.

---

## Task 3: Кабинет-форма ресторана — показ минимума + живой итог

**Files:** Modify `api/includes/supplier_orders.php` (loadProducts-эндпоинт + проброс min_order ресторану), `src/views/SupplierOrderFormView.vue`, при необходимости `src/stores/supplierOrderStore.js`

- [ ] **Step 1 (бэк, данные форме):** Эндпоинт, отдающий товары ресторанной форме (`soStore.loadProducts` → найти обработчик; вероятно возвращает шаблон/товары) — добавить в товары `qty_per_box`, `weight_netto` (по ГРУППЕ юрлиц), нужные для kg-итога. И сделать доступным форме минимум поставщика: `min_order_value`, `min_order_unit` — добавить в ответ loadProducts ИЛИ в объект поставщика в suppliers GET/available_dates (там, где форма берёт данные поставщика). Поля добавочные, существующее не трогать.
- [ ] **Step 2 (фронт форма):** В `SupplierOrderFormView.vue` — вычислять живой итог по `quantities`+`products`: pieces = Σ qty; kg = Σ (qty/qty_per_box)*weight_netto/1000. Показать строку: «Минимум: {min} {ед} · В заявке: {итог} {ед}» с подсветкой, когда ниже минимума. При недоборе — предупреждение и (мягко) дизейбл/блок кнопки отправки (сервер всё равно гарантирует). При отсутствии минимума у поставщика — ничего не показывать (регрессия).
- [ ] **Step 3:** `npm run build`.

**Verification (после сборки):** для поставщика с минимумом форма показывает минимум и живой итог, корректно считает кг и штуки; при недоборе — предупреждение/блок кнопки; при достижении — отправка проходит; поставщик без минимума — форма как раньше.

---

## Task 4: Миграция, сборка, ручная проверка, память

- [ ] **Step 1:** Применить миграцию (Claude); `npm run build`.
- [ ] **Step 2:** Ручная проверка: настройка минимума; подача ниже/выше в кг и штуках; skip_delivery; поставщик без минимума.
- [ ] **Step 3:** Обновить память (`supplier-orders-*`) и леджер.

**Verification:** миграция применена; сборка чистая; сценарии пройдены; регрессия (нет минимума = как раньше) подтверждена.

## Notes
- Итог kg зависит от заполненности весов в справочнике и связи товаров (обеспечена ранее). Товары без веса дают вклад 0 — при массовой проблеме показать это отдельно (вне объёма).
- Это ПОСЛЕДНИЙ пункт бэклога стадий модуля «Заявки поставщикам».
