# Промпты для генерации иконок портала

Задача: заменить самодельный набор `src/lib/icons.js` на единый фирменный.

Сейчас в наборе 83 иконки и **12+ разных цветов** — бирюзовый, фиолетовый,
зелёный, голубой. Основной цвет иконок `#E76F51` не совпадает с фирменным
оранжевым портала `#E87A1E` из `src/styles/tokens.css`. Новый набор
приводит всё к одной палитре.

---

## 1. Чем генерировать

| Инструмент | Что на выходе | Годится |
|---|---|---|
| **Recraft** (recraft.ai) | настоящий **SVG-вектор**, есть режим Icon | **да, основной** |
| Midjourney v6+ | картинка PNG | только если потом трассировать |
| ChatGPT / DALL·E | картинка PNG | для проб стиля, не для финала |
| Figma + плагин Iconify AI | вектор | да, если работаете в Figma |

Нам нужен **вектор**, потому что иконки красятся в коде и должны быть
чёткими на любом экране. Картинку PNG придётся прогонять через
трассировку (vectorizer.ai, Illustrator Image Trace), а после неё контуры
получаются рваными.

**Рекомендация: Recraft.** В нём есть «Style» — можно один раз собрать
свой стиль на первых иконках и дальше генерировать в нём же. Это и даёт
одинаковость, ради которой всё затевается.

---

## 2. Порядок работы

1. Сгенерировать **три пробные иконки** (`package`, `truck`, `calendar`).
   Они разные по сложности — сразу видно, держит ли модель стиль.
2. Выбрать лучший вариант, сохранить как **Style / Style reference**.
3. Дальше гнать остальные 45 в этом стиле, по одной.
4. Скачать каждую как `имя.svg` — **имя строго из колонки «ключ»**.
5. Сложить в одну папку и отдать мне. Я перенесу в `src/lib/icons.js`
   и проверю, что нигде ничего не отвалилось.

Служебные значки (стрелки, галочка, крестик, плюс, шеврон, «карандаш»)
генерировать **не нужно** — это чистая геометрия, ИИ нарисует их хуже,
чем они есть. Меняем только смысловые иконки разделов.

---

## 3. Мастер-промпт стиля

Вставлять **без изменений** в каждый запрос. Меняется только последняя
строка — предмет.

```
Flat two-tone vector icon for a web app interface, single icon centered on a
plain white background, drawn on a 24x24 grid with even padding around it.

Style: friendly geometric shapes, uniform 1.8px outline, fully rounded corners,
rounded line caps and joins, closed silhouette, no perspective, straight-on
front view, no 3D, no gradient, no shadow, no texture, no outline glow.

Color: outline strictly #E87A1E. Large fill areas #FDF1E3 (very light warm
cream). Secondary accent fill #F6C58A (soft apricot). One dark detail allowed
in #3D382E only when it is essential for readability. No other colors at all.

Readability rule: the icon must stay clear at 16 pixels. Maximum 4 shapes.
No thin hairlines, no small details, no dense patterns.

Subject: <ПРЕДМЕТ ИЗ ТАБЛИЦЫ>
```

**Негативный промпт** (в Midjourney добавлять через `--no`, в Recraft — в
поле Negative):

```
text, letters, numbers, words, logo, watermark, signature, 3D render,
isometric, perspective, photo, realistic, gradient, drop shadow, glow,
outline stroke of varying width, background scene, frame, border, multiple
icons, sticker, emoji, rainbow colors, blue, green, purple, teal
```

Для Midjourney в конец добавлять: `--style raw --ar 1:1 --v 6`

---

## 4. Предметы по разделам

48 иконок. Колонка «ключ» — имя файла и имя в коде, менять нельзя.

| Ключ | Раздел в портале | Subject (в промпт) |
|---|---|---|
| `package` | Новый заказ | a closed cardboard shipping box, front view, with a small plus badge in the top right corner |
| `planning` | Планирование | three stacked horizontal bars of different lengths, like a Gantt chart |
| `delivery` | Поставки | a cardboard box with a downward arrow entering a warehouse doorway |
| `history` | История | a clock face with a counter-clockwise arrow curving around it |
| `clipboard` | Задачи | a clipboard with three checklist lines, the top one ticked |
| `handover` | Передача дел | two open hands passing a closed folder from one to the other |
| `database` | База данных | three stacked cylinder discs, database symbol |
| `copy` | Аналоги | two identical rounded squares, one overlapping behind the other |
| `sparkle` | Новинки | one large four-pointed sparkle with two smaller sparkles beside it |
| `pricing` | Цены и ПСЦ | a price tag with a round hole, tilted 45 degrees |
| `calendar` | Календарь | a calendar page with two rings on top and a grid of dots |
| `home` | Дашборд | a round speedometer gauge with a needle pointing to the upper right |
| `analytics` | Аналитика | a rising line chart with two round node dots on the line |
| `ruler` | Анализ запасов | a straight ruler tilted 45 degrees with measurement ticks |
| `arrowLeftRight` | Сверка 1С/УТ | two horizontal arrows stacked, pointing in opposite directions |
| `bulb` | ИИ-помощник | a light bulb with a filament and two small sparkles at the top |
| `marketing` | Маркетинг | a megaphone tilted upward with two curved sound waves |
| `document` | Протоколы | a sheet of paper with a folded top corner and three horizontal bars |
| `shelfLife` | Сроки годности | a food jar with a lid and a small clock badge in the corner |
| `schedule` | График доставки | a curved route line with three map pins along it |
| `deficit` | Распределение дефицита | an empty open box with a downward arrow above it |
| `distribute` | Распределение | one box at the top splitting into three arrows fanning downward |
| `truckLoad` | Загрузка машин | a truck trailer seen from the rear with boxes stacked inside |
| `key` | Заявка на пропуск | a key with a round bow and two teeth, tilted 45 degrees |
| `pallet` | Калькулятор паллет | a wooden pallet seen from the front with one box on top |
| `warehouse` | Паллетовка склада | a warehouse building with a wide roll-up door and a flat roof |
| `tender` | Тендеры | balance scales with two hanging pans |
| `truck` | График поставок | a delivery truck, side view, with a small clock badge |
| `factory` | Заявки поставщикам | a factory building with two chimneys |
| `workshop` | Собственное производство | a ball of dough on a tray with a rolling pin beside it |
| `link` | Ссылки кабинета | two interlocking chain links tilted 45 degrees |
| `payments` | Оплаты поставщиков | a bank card with a horizontal stripe and a round coin beside it |
| `building` | Кабинеты ресторанов | a small restaurant storefront with a striped awning and a door |
| `restaurantOrders` | Заказы ресторанов | a paper order ticket with a torn zigzag bottom edge |
| `order` | Сбор заказа осн. поставки | a shopping cart, side view, with one box inside |
| `barcode` | Штрихкоды | a barcode of vertical bars with a horizontal scan line across it |
| `kegReturn` | Возврат кег | a beer keg with a curved return arrow around it |
| `stockCollection` | Сбор остатков | a shelf with three boxes on it and tally marks beside |
| `survey` | Опросы | a speech bubble containing three round option dots in a column |
| `chat` | Чат с ресторанами | two overlapping rounded speech bubbles |
| `edit` | Корректировки | a pencil tilted 45 degrees writing on a short line |
| `chartUp` | Реализация ресторанов | three bar chart columns with an arrow rising above them |
| `excel` | Отчёт по заказам ресторанов | a spreadsheet sheet with a table grid and a folded corner |
| `search` | Поиск карточек | a magnifying glass over the corner of a card |
| `import` | Импорт данных | an arrow pointing down into an open tray |
| `send` | Telegram-бот | a paper plane tilted upward |
| `gear` | Админ-панель | a cog wheel with eight teeth and a round hole |
| `user` | Настройки аккаунта | a person bust silhouette inside a circle |

---

## 5. Что проверить перед сдачей

- [ ] Ни на одной иконке **нет букв и цифр**. Модели любят подписывать.
- [ ] Цвета только четыре: `#E87A1E`, `#FDF1E3`, `#F6C58A`, `#3D382E`.
- [ ] Толщина контура одинаковая у всех. Разнобой заметен, когда иконки
      стоят в меню столбиком.
- [ ] Уменьшите до 16 пикселей и посмотрите: предмет узнаётся?
      Половина иконок в портале живёт именно в этом размере.
- [ ] Все 48 разные. Одинаковые значки у разных разделов путают —
      при `npm run dev` консоль ругается на дубли.
- [ ] Файл называется ровно как ключ: `package.svg`, `truckLoad.svg`.
      Регистр важен.

---

## 6. Оговорка про «Штрихкоды»

В коде у раздела «Штрихкоды» стоит ключ `warning` — исторический хвост,
раздел получил чужой значок. В таблице выше он назван `barcode`. При
переносе я заведу новый ключ и переключу раздел, а `warning` останется
за настоящими предупреждениями.

---

## 7. Тёмный сайдбар

В `icons.js` есть второй набор — `iconsLight`, 48 штук, для тёмного
коричневого меню. Это те же иконки в осветлённом варианте.

Отдельно генерировать их **не надо**: когда придут основные, я сделаю
светлый вариант заменой цветов — контур на `#FDF1E3`, заливки на
полупрозрачный белый. Так они точно совпадут по форме с основными,
чего сейчас нет.
