# Связывание товаров поставщика со справочником: Implementation Plan

> REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Шаги — чекбоксы.

**Goal:** Надёжно связать позиции шаблонов поставщика (`so_templates`) с карточками каталога (`products`) через `so_templates.product_id`, чтобы из каталога подтягивались данные (вес, паллеты, единица) — фундамент для Excel-опций (паллеты/вес). Авто-привязка по ТОЧНОМУ SKU (в группе юрлиц); что не совпало — привязывается вручную через уже существующий поиск в панели.

**Контекст (проверено в коде/БД):**
- `so_templates` уже имеет `product_id CHAR(36)` (→ `products.id`). `adminSaveTemplates` его пишет, `adminGetTemplates` LEFT JOIN `products p ON p.id = t.product_id`.
- НО: у существующих строк `product_id` пуст (0/11 заполнено; по SKU совпадает 5/11). `adminSaveTemplates` НЕ подставляет product_id по SKU при пустом. Ручные строки (`addManualTemplateRow`) идут с `product_id=null`.
- Поиск товаров: `GET /api/search_products?q=&legal_entity=&supplier=&limit=` (возвращает карточки с `id`). Пикер `addTemplateProduct(p)` уже ставит `product_id = p.id`.
- Решения пользователя: авто-матч ТОЛЬКО по SKU (иначе вручную); UI — во вкладке «Шаблоны».

**Architecture:** (1) Бэкенд авто-резолвит `product_id` по SKU (в группе) при сохранении шаблона, когда он пуст. (2) Разовый backfill существующих строк по SKU (применяет Claude). (3) `adminGetTemplates` возвращает статус связи + атрибуты карточки (название/ед/вес/qty_per_box/boxes_per_pallet). (4) Во вкладке «Шаблоны» — индикатор ✅/⚠️ и кнопка «Привязать» через существующий поиск для непривязанных.

## Global Constraints
- Матч — по ТОЧНОМУ `sku`, справочник по ГРУППЕ юрлиц поставщика (`getEntitiesInGroup`); НЕ по одному юрлицу, НЕ по названию/коду.
- Не ломать текущее сохранение/загрузку шаблонов и подачу заявок.
- Русский UI. Бэкенд живой из `api/`; фронт — `npm run build`. Миграций схемы нет (колонка есть).
- Backfill — идемпотентный UPDATE (только там, где product_id пуст и SKU однозначно совпадает в группе).

## File Structure
- `api/includes/supplier_orders.php` — `adminSaveTemplates` (авто-резолв product_id по SKU); `adminGetTemplates` (вернуть статус связи + атрибуты карточки).
- `src/views/SupplierOrdersManagerView.vue` — вкладка «Шаблоны»: индикатор связи + кнопка «Привязать».
- (backfill — SQL, применяет Claude отдельно, не в коде.)

---

## Task 1: Бэкенд — авто-резолв product_id + статус связи в выдаче

**Files:** Modify `api/includes/supplier_orders.php`

- [ ] **Step 1 (авто-резолв при сохранении):** В `adminSaveTemplates` (обработчик `templates` POST): перед upsert для каждого item, если `product_id` пуст/непередан, а `sku` есть — найти `products.id` по `sku` в группе юрлиц (`WHERE sku=? AND legal_entity IN (<группа>)`, взять первую). Если нашлось — использовать этот id; иначе оставить null. Группу поставщика получить как в других местах (`legal_entity_group` поставщика → `getEntitiesInGroup`). Одна выборка на все sku (map sku→id) предпочтительнее N запросов.
- [ ] **Step 2 (статус связи в выдаче):** В `adminGetTemplates` (обработчик `templates` GET, LEFT JOIN products уже есть) — вернуть по каждой позиции: `product_id`, `linked` (bool: product_id не пуст И карточка найдена), и атрибуты карточки `catalog_name` (p.name), `unit_of_measure`, `weight_netto`, `weight_brutto`, `qty_per_box`, `boxes_per_pallet` (null если не связан). Существующие поля (sku, product_name, multiplicity, min_qty, sort_order) сохранить.

**Verification:** `php -l` чисто; сохранение шаблона с ручным SKU, совпадающим с каталогом, проставляет product_id; GET возвращает `linked` и атрибуты; группа юрлиц соблюдена; всё параметризовано.

---

## Task 2: Фронтенд — статус связи и кнопка «Привязать»

**Files:** Modify `src/views/SupplierOrdersManagerView.vue`

**Контекст:** вкладка «Шаблоны» (`pageTab==='templates'`, ~строки 455–540) уже показывает список `templates` с полями sku/product_name/multiplicity/min_qty и поиск `searchTemplateProducts`/`addTemplateProduct` (ставит product_id). `loadTemplates` грузит через `adminGetTemplates`.

- [ ] **Step 1:** По каждой строке шаблона показать статус связи: ✅ «привязан» (с подсказкой — название/ед./вес из каталога, из новых полей ответа) или ⚠️ «нет карточки». Аккуратно, в стиле вкладки.
- [ ] **Step 2:** Для непривязанной строки — действие «Привязать»: открыть/использовать существующий поиск по каталогу (`searchTemplateProducts`) и по выбору проставить строке `product_id` (+ подтянуть catalog-атрибуты в отображение), НЕ теряя заданные для строки multiplicity/min_qty. Реализовать как «привязку к существующей строке» (в отличие от `addTemplateProduct`, который добавляет новую) — например, режим привязки для конкретного индекса строки. Сохранение — существующей кнопкой «Сохранить» (product_id уйдёт в `adminSaveTemplates`).
- [ ] **Step 3:** `npm run build`.

**Verification (после сборки):** непривязанные помечены ⚠️; «Привязать» находит карточку и связывает; после «Сохранить» и перезагрузки статус ✅ с данными каталога.

---

## Task 3: Сборка и ручная проверка (+ backfill применяет Claude)

- [ ] **Step 1:** Claude применяет backfill (idempotent UPDATE product_id по SKU в группе) к БД и сообщает, сколько строк связано.
- [ ] **Step 2:** `npm run build`; проверить: у совпавших по SKU — ✅ и данные каталога; ручная привязка работает; заявки/сохранение шаблонов не сломаны.

**Verification:** backfill применён (число связанных); сборка чистая; сценарии пройдены.

## Notes
- external_code / название как доп. критерии — вне объёма (решение: только SKU).
- Это фундамент для последующего Excel-этапа (паллеты/вес на объединённом отчёте).
