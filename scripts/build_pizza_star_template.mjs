#!/usr/bin/env node
// Генерация шаблона Excel-файла для заполнения справочника Пицца Стар.
// Запуск: node scripts/build_pizza_star_template.mjs [путь_выхода]
// По умолчанию файл пишется в dist_templates/Пицца Стар — справочник для заполнения.xlsx

import XLSX from 'xlsx-js-style';
import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';

const outArg = process.argv[2];
const outPath = outArg
  ? resolve(process.cwd(), outArg)
  : resolve(process.cwd(), 'dist_templates/Пицца Стар — справочник для заполнения.xlsx');

mkdirSync(dirname(outPath), { recursive: true });

// ───────────────── Стили ─────────────────

const border = {
  top:    { style: 'thin', color: { rgb: 'BDBDBD' } },
  bottom: { style: 'thin', color: { rgb: 'BDBDBD' } },
  left:   { style: 'thin', color: { rgb: 'BDBDBD' } },
  right:  { style: 'thin', color: { rgb: 'BDBDBD' } },
};
const titleStyle = {
  font: { bold: true, sz: 14, name: 'Calibri', color: { rgb: 'D62300' } },
  alignment: { horizontal: 'left', vertical: 'center' },
};
const noteStyle = {
  font: { sz: 11, italic: true, name: 'Calibri', color: { rgb: '555555' } },
  alignment: { horizontal: 'left', vertical: 'center', wrapText: true },
};
const headerStyle = {
  font: { bold: true, color: { rgb: 'FFFFFF' }, sz: 11, name: 'Calibri' },
  fill: { fgColor: { rgb: 'D62300' } },
  alignment: { horizontal: 'center', vertical: 'center', wrapText: true },
  border,
};
const requiredHeaderStyle = {
  ...headerStyle,
  fill: { fgColor: { rgb: '8B0000' } },
};
const exampleStyle = {
  font: { sz: 11, name: 'Calibri', color: { rgb: '999999' }, italic: true },
  alignment: { horizontal: 'left', vertical: 'center' },
  border,
};

// ───────────────── Лист «Товары» ─────────────────

/**
 * Колонки в порядке импорта (из ImportCardsModal.vue → COLUMN_MAP):
 *   Артикул (обяз), Наименование (обяз), Поставщик, Шт/кор,
 *   Единица хранения, Кор/пал, Штук в блоке, Блоков в коробе,
 *   Кратность, Активная, Группа аналогов, Хранение
 */
const productCols = [
  { header: 'Артикул',                         width: 14, required: true,
    hint: 'Ваш артикул (может быть с буквами, напр. lok009).' },
  { header: 'Внешний код',                     width: 14, required: false,
    hint: 'Внутренний код — всегда 9 цифр (external_code в 1С).' },
  { header: 'Штрихкод',                        width: 16, required: false,
    hint: 'GTIN / EAN-13 со штрихкода упаковки.' },
  { header: 'Наименование',                    width: 48, required: true,
    hint: 'Полное название товара как в 1С.' },
  { header: 'Поставщик',                       width: 22, required: false,
    hint: 'Короткое имя поставщика (такое же, как в листе «Поставщики»).' },
  { header: 'Коэффициент единицы для отчетов', width: 14, required: false,
    hint: 'Штук в одной коробке/упаковке (например 12).' },
  { header: 'Единица хранения',                width: 12, required: false,
    hint: 'шт, кг, л, уп.' },
  { header: 'Количество кор. в паллете',       width: 14, required: false,
    hint: 'Сколько коробок помещается на одной паллете.' },
  { header: 'Количество штук в блоке',         width: 14, required: false,
    hint: 'Для кратности заказа: минимальное количество, которое можно заказать.' },
  { header: 'Количество блоков в коробе',      width: 14, required: false,
    hint: 'Используется для расчёта кратности, если «блок» крупнее «штуки».' },
  { header: 'Кратность',                       width: 12, required: false,
    hint: 'Можно указать напрямую. Если пусто — будет посчитана из блоков.' },
  { header: 'Вес нетто (кг)',                  width: 12, required: false,
    hint: 'Вес товара без упаковки, в килограммах.' },
  { header: 'Вес брутто (кг)',                 width: 12, required: false,
    hint: 'Вес товара с упаковкой, в килограммах.' },
  { header: 'Прослеживаемый',                  width: 14, required: false,
    hint: 'Да — если товар попадает под обязательную маркировку (мясо, молоко, алкоголь и т.п.).' },
  { header: 'Активная',                        width: 12, required: false,
    hint: 'Да / Нет. Неактивные товары при импорте пропускаются.' },
  { header: 'Группа аналогов',                 width: 22, required: false,
    hint: 'Название группы взаимозаменяемых товаров (если есть).' },
  { header: 'Хранение',                        width: 14, required: false,
    hint: 'Сухое / Холод / Заморозка.' },
];

const productExamples = [
  ['lok009',    '100000001', '4607034540012', 'Сыр Моцарелла 2.5 кг',     'Савушкин продукт', '4', 'кг', '60', '1', '1', '4', '2.5',  '2.65', 'Да',  'Да', '',        'Холод'],
  ['ket077',    '100000002', '4600605000232', 'Соус Кетчуп 3.8 кг',       'Кetchup House',    '6', 'шт', '40', '1', '1', '6', '3.8',  '3.95', 'Нет', 'Да', '',        'Сухое'],
  ['fl050',     '100000042', '4607023810011', 'Мука пшеничная в/с 50 кг', 'Молочный мир',     '1', 'кг', '20', '1', '1', '1', '50',   '50.5', 'Нет', 'Да', '',        'Сухое'],
];

const productsSheet = (() => {
  const aoa = [];
  aoa.push(['Справочник товаров ООО «Пицца Стар»']);
  aoa.push(['']);
  aoa.push(['Как заполнять:']);
  aoa.push(['1. Колонки «Артикул» и «Наименование» — обязательны. Остальные — если есть данные.']);
  aoa.push(['2. Первые три строки ниже заголовков — примеры, их можно удалить перед отправкой.']);
  aoa.push(['3. «Поставщик» должен совпадать с именем из листа «Поставщики».']);
  aoa.push(['4. Нельзя переименовывать колонки заголовков — импорт ищет их по названию.']);
  aoa.push(['']);
  aoa.push(productCols.map(c => c.header));
  aoa.push(productCols.map(c => c.hint));
  aoa.push(...productExamples);

  const ws = XLSX.utils.aoa_to_sheet(aoa);

  // Title
  ws[XLSX.utils.encode_cell({ r: 0, c: 0 })].s = titleStyle;
  ws['!merges'] = [{ s: { r: 0, c: 0 }, e: { r: 0, c: productCols.length - 1 } }];

  // Instructions (rows 2..6)
  for (let r = 2; r <= 6; r++) {
    const cell = ws[XLSX.utils.encode_cell({ r, c: 0 })];
    if (cell) cell.s = noteStyle;
  }

  // Header row (row 8)
  for (let c = 0; c < productCols.length; c++) {
    const cell = ws[XLSX.utils.encode_cell({ r: 8, c })];
    if (cell) cell.s = productCols[c].required ? requiredHeaderStyle : headerStyle;
  }

  // Hint row (row 9) — бледно
  for (let c = 0; c < productCols.length; c++) {
    const cell = ws[XLSX.utils.encode_cell({ r: 9, c })];
    if (cell) cell.s = {
      font: { sz: 9, italic: true, color: { rgb: '888888' }, name: 'Calibri' },
      alignment: { horizontal: 'left', vertical: 'top', wrapText: true },
      fill: { fgColor: { rgb: 'FFF8E1' } },
      border,
    };
  }

  // Example rows (10..12)
  for (let r = 10; r < 10 + productExamples.length; r++) {
    for (let c = 0; c < productCols.length; c++) {
      const cell = ws[XLSX.utils.encode_cell({ r, c })];
      if (cell) cell.s = exampleStyle;
    }
  }

  ws['!cols'] = productCols.map(c => ({ wch: c.width }));
  ws['!rows'] = [
    { hpx: 30 },  // title
    { hpx: 6  },  // spacer
    { hpx: 18 }, { hpx: 18 }, { hpx: 18 }, { hpx: 18 }, { hpx: 18 },  // instructions
    { hpx: 6  },  // spacer
    { hpx: 40 },  // headers
    { hpx: 50 },  // hints
  ];

  return ws;
})();

// ───────────────── Лист «Поставщики» ─────────────────

const supplierCols = [
  { header: 'Короткое имя',        width: 22, required: true,
    hint: 'Короткое название, которое будет отображаться в интерфейсе и указываться у товаров.' },
  { header: 'Полное наименование', width: 40, required: false,
    hint: 'Полное юридическое название (ООО «...»).' },
  { header: 'Страна',              width: 10, required: false,
    hint: 'BY, RU, KZ и т.п. По умолчанию BY.' },
  { header: 'Срок доставки (дней)',width: 14, required: false,
    hint: 'Через сколько дней после заказа приезжает товар (dlt).' },
  { header: 'Частота заказа (дней)', width: 14, required: false,
    hint: 'Как часто можно заказывать у этого поставщика (doc).' },
  { header: 'Отсрочка оплаты (дней)', width: 14, required: false,
    hint: 'Сколько дней на оплату после поставки.' },
  { header: 'Telegram',            width: 18, required: false,
    hint: '@username или номер для Telegram-заказов.' },
  { header: 'WhatsApp',            width: 18, required: false,
    hint: 'Номер для WhatsApp.' },
  { header: 'Viber',               width: 18, required: false,
    hint: 'Номер для Viber.' },
  { header: 'Email',               width: 26, required: false,
    hint: 'Почта для отправки заявок.' },
];

const supplierExamples = [
  ['Савушкин продукт', 'ОАО «Савушкин продукт»', 'BY', '2', '7', '14', '@savushkin_orders', '', '', 'orders@savushkin.by'],
  ['Kетчуп House',     'ООО «Kетчуп Хаус»',      'BY', '3', '14','30', '',                   '+375291111111', '', ''],
];

const suppliersSheet = (() => {
  const aoa = [];
  aoa.push(['Справочник поставщиков ООО «Пицца Стар»']);
  aoa.push(['']);
  aoa.push(['Как заполнять:']);
  aoa.push(['1. «Короткое имя» — обязательно, именно это имя нужно указывать у товара в листе «Товары».']);
  aoa.push(['2. Срок доставки и частота заказа важны для расчёта потребностей — постарайтесь заполнить.']);
  aoa.push(['3. Контакты (Telegram / WhatsApp / Viber / Email) — то, откуда отдел закупок будет отправлять заявку.']);
  aoa.push(['']);
  aoa.push(supplierCols.map(c => c.header));
  aoa.push(supplierCols.map(c => c.hint));
  aoa.push(...supplierExamples);

  const ws = XLSX.utils.aoa_to_sheet(aoa);

  ws[XLSX.utils.encode_cell({ r: 0, c: 0 })].s = titleStyle;
  ws['!merges'] = [{ s: { r: 0, c: 0 }, e: { r: 0, c: supplierCols.length - 1 } }];

  for (let r = 2; r <= 5; r++) {
    const cell = ws[XLSX.utils.encode_cell({ r, c: 0 })];
    if (cell) cell.s = noteStyle;
  }

  for (let c = 0; c < supplierCols.length; c++) {
    const cell = ws[XLSX.utils.encode_cell({ r: 7, c })];
    if (cell) cell.s = supplierCols[c].required ? requiredHeaderStyle : headerStyle;
  }
  for (let c = 0; c < supplierCols.length; c++) {
    const cell = ws[XLSX.utils.encode_cell({ r: 8, c })];
    if (cell) cell.s = {
      font: { sz: 9, italic: true, color: { rgb: '888888' }, name: 'Calibri' },
      alignment: { horizontal: 'left', vertical: 'top', wrapText: true },
      fill: { fgColor: { rgb: 'FFF8E1' } },
      border,
    };
  }
  for (let r = 9; r < 9 + supplierExamples.length; r++) {
    for (let c = 0; c < supplierCols.length; c++) {
      const cell = ws[XLSX.utils.encode_cell({ r, c })];
      if (cell) cell.s = exampleStyle;
    }
  }

  ws['!cols'] = supplierCols.map(c => ({ wch: c.width }));
  ws['!rows'] = [
    { hpx: 30 }, { hpx: 6 },
    { hpx: 18 }, { hpx: 18 }, { hpx: 18 }, { hpx: 18 },
    { hpx: 6 },
    { hpx: 40 }, { hpx: 50 },
  ];

  return ws;
})();

// ───────────────── Лист «Рестораны» ─────────────────

const restCols = [
  { header: 'Номер',   width: 10, required: true,
    hint: 'Внутренний номер ресторана (число). Может совпадать с БК — это нормально.' },
  { header: 'Регион',  width: 16, required: false,
    hint: 'Область/регион (например, Минск, Брест).' },
  { header: 'Город',   width: 18, required: false,
    hint: 'Город, в котором находится ресторан.' },
  { header: 'Адрес',   width: 40, required: true,
    hint: 'Полный адрес.' },
  { header: 'Telegram-ID', width: 18, required: false,
    hint: 'ID чата для уведомлений (если есть).' },
  { header: 'Примечания',   width: 30, required: false,
    hint: 'Любые заметки, которые полезно иметь отделу закупок.' },
];

const restExamples = [
  ['1', 'Минск',   'Минск',   'пр. Независимости, 12',         '', 'ТЦ «Замок»'],
  ['2', 'Минск',   'Минск',   'ул. Притыцкого, 156',            '', ''],
  ['3', 'Гомель',  'Гомель',  'пр. Ленина, 5',                   '', ''],
];

const restSheet = (() => {
  const aoa = [];
  aoa.push(['Справочник ресторанов ООО «Пицца Стар»']);
  aoa.push(['']);
  aoa.push(['Как заполнять:']);
  aoa.push(['1. «Номер» и «Адрес» — обязательны.']);
  aoa.push(['2. Номера могут совпадать с БК — они хранятся раздельно в группе PS.']);
  aoa.push(['3. Telegram-ID нужен, если вы хотите получать уведомления от бота в этом чате.']);
  aoa.push(['']);
  aoa.push(restCols.map(c => c.header));
  aoa.push(restCols.map(c => c.hint));
  aoa.push(...restExamples);

  const ws = XLSX.utils.aoa_to_sheet(aoa);

  ws[XLSX.utils.encode_cell({ r: 0, c: 0 })].s = titleStyle;
  ws['!merges'] = [{ s: { r: 0, c: 0 }, e: { r: 0, c: restCols.length - 1 } }];

  for (let r = 2; r <= 5; r++) {
    const cell = ws[XLSX.utils.encode_cell({ r, c: 0 })];
    if (cell) cell.s = noteStyle;
  }

  for (let c = 0; c < restCols.length; c++) {
    const cell = ws[XLSX.utils.encode_cell({ r: 7, c })];
    if (cell) cell.s = restCols[c].required ? requiredHeaderStyle : headerStyle;
  }
  for (let c = 0; c < restCols.length; c++) {
    const cell = ws[XLSX.utils.encode_cell({ r: 8, c })];
    if (cell) cell.s = {
      font: { sz: 9, italic: true, color: { rgb: '888888' }, name: 'Calibri' },
      alignment: { horizontal: 'left', vertical: 'top', wrapText: true },
      fill: { fgColor: { rgb: 'FFF8E1' } },
      border,
    };
  }
  for (let r = 9; r < 9 + restExamples.length; r++) {
    for (let c = 0; c < restCols.length; c++) {
      const cell = ws[XLSX.utils.encode_cell({ r, c })];
      if (cell) cell.s = exampleStyle;
    }
  }

  ws['!cols'] = restCols.map(c => ({ wch: c.width }));
  ws['!rows'] = [
    { hpx: 30 }, { hpx: 6 },
    { hpx: 18 }, { hpx: 18 }, { hpx: 18 }, { hpx: 18 },
    { hpx: 6 },
    { hpx: 40 }, { hpx: 50 },
  ];

  return ws;
})();

// ───────────────── Сборка книги ─────────────────

const wb = XLSX.utils.book_new();
XLSX.utils.book_append_sheet(wb, productsSheet,  'Товары');
XLSX.utils.book_append_sheet(wb, suppliersSheet, 'Поставщики');
XLSX.utils.book_append_sheet(wb, restSheet,      'Рестораны');

const buf = XLSX.write(wb, { type: 'buffer', bookType: 'xlsx' });
writeFileSync(outPath, buf);

console.log('OK', outPath);
