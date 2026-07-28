// ═══════════════════════════════════════════════════════════════════════
// Единый список разделов портала.
//
// Раньше он был продублирован в трёх местах: меню (AppLayout), поиск по «/»
// (CommandPalette) и подпись «кто на какой странице» (heartbeat). Списки
// разъезжались — новые разделы («Аналоги», «Новинки», «Ссылки кабинета»)
// появлялись в меню, но не находились через поиск.
//
// ПРАВИЛО: новый раздел добавляется ТОЛЬКО сюда. Меню, поиск и heartbeat
// подхватят его сами.
//
// Поля пункта:
//   module   — ключ модуля для проверки прав (см. userStore.hasAccess).
//              Ключи в единственном числе: 'order', а не 'orders'.
//   route    — имя маршрута из router/index.js
//   icon     — имя иконки из lib/icons.js. ОБЯЗАН быть уникальным:
//              одинаковые иконки у разных разделов путают пользователя.
//   label    — подпись в меню, поиске и в статусе «кто где»
//   keywords — дополнительные слова для поиска (синонимы, сокращения)
// ═══════════════════════════════════════════════════════════════════════

// Верхняя часть меню — всегда развёрнута.
export const SIDEBAR_SECTIONS = [
  { title: 'Заказы', items: [
    { module: 'order', route: 'order', icon: 'package', label: 'Новый заказ', keywords: 'заказ new' },
    { module: 'planning', route: 'planning', icon: 'planning', label: 'Планирование' },
    { module: 'plan-fact', route: 'plan-fact', icon: 'delivery', label: 'Поставки', keywords: 'план факт' },
    { module: 'history', route: 'history', icon: 'history', label: 'История', keywords: 'история заказов' },
    { module: 'tasks', route: 'tasks', icon: 'clipboard', label: 'Задачи', keywords: 'таск доска канбан' },
    { module: 'handover', route: 'handover', icon: 'handover', label: 'Передача дел', keywords: 'отпуск замещение больничный передача' },
  ]},
  { title: 'Данные', items: [
    { module: 'database', route: 'database', icon: 'database', label: 'База данных', keywords: 'товары поставщики рестораны справочник база товаров' },
    { module: 'analogs', route: 'analogs', icon: 'copy', label: 'Аналоги', keywords: 'аналог замена взаимозаменяемые группы' },
    { module: 'novelties', route: 'novelties', icon: 'sparkle', label: 'Новинки', keywords: 'новый товар новинка старт продаж', newBadge: true },
    { module: 'pricing', route: 'pricing', icon: 'pricing', label: 'Цены и ПСЦ', keywords: 'прайс протокол псц цена' },
    { module: 'calendar', route: 'calendar', icon: 'calendar', label: 'Календарь' },
  ]},
];

// Раздел «Инструменты» — сворачиваемая часть меню.
export const TOOLS_GROUPS = [
  { title: 'Аналитика', items: [
    { module: 'dashboard', route: 'dashboard', icon: 'home', label: 'Дашборд' },
    { module: 'analytics', route: 'analytics', icon: 'analytics', label: 'Аналитика' },
    { module: 'analysis', route: 'analysis', icon: 'ruler', label: 'Анализ запасов' },
    { module: 'reconciliation', route: 'reconciliation', icon: 'arrowLeftRight', label: 'Сверка 1С/УТ', keywords: 'сверка ут 1с расхождения' },
    { module: 'analysis', route: 'assistant', icon: 'bulb', label: 'ИИ-помощник', keywords: 'ai ассистент нейросеть' },
    { module: 'marketing', route: 'marketing', icon: 'marketing', label: 'Маркетинг', keywords: 'акция промо' },
    { module: 'protocols', route: 'protocols', icon: 'document', label: 'Протоколы', keywords: 'совещание протокол' },
  ]},
  { title: 'Склад и логистика', items: [
    { module: 'shelf-life', route: 'shelf-life', icon: 'shelfLife', label: 'Сроки годности', keywords: 'срок просрочка ячейки' },
    { module: 'delivery-schedule', route: 'delivery-schedule', icon: 'schedule', label: 'График доставки' },
    { module: 'deficit', route: 'deficit', icon: 'deficit', label: 'Распределение дефицита' },
    { module: 'distribution', route: 'distribution', icon: 'distribute', label: 'Распределение' },
    { module: 'truck-loading', route: 'truck-loading', icon: 'truckLoad', label: 'Загрузка машин', keywords: 'фура грузовик паллеты' },
    { module: 'tit-requests', route: 'tit-requests', icon: 'key', label: 'Заявка на пропуск', keywords: 'пропуск тит въезд машина транспорт' },
    { module: 'pallet-calc', route: 'pallet-calc', icon: 'pallet', label: 'Калькулятор паллет' },
    { module: 'pallet-storage', route: 'pallet-storage', icon: 'warehouse', label: 'Паллетовка склада' },
  ]},
  { title: 'Поставщики', items: [
    { module: 'tenders', route: 'tenders', icon: 'tender', label: 'Тендеры' },
    { module: 'supplier-schedule', route: 'supplier-schedule', icon: 'truck', label: 'График поставок', keywords: 'расписание поставщиков дедлайн' },
    { module: 'supplier-orders', route: 'supplier-orders', icon: 'factory', label: 'Заявки поставщикам', keywords: 'камако овощи so планета' },
    { module: 'supplier-orders', route: 'cabinet-links', icon: 'link', label: 'Ссылки кабинета', keywords: 'ссылки кабинет ресторана лидское салатория' },
    { module: 'plan-fact', route: 'payments', icon: 'payments', label: 'Оплаты поставщиков', keywords: 'оплата казначей платёж' },
  ]},
  { title: 'Управление ресторанами', items: [
    { module: 'restaurant-orders', route: 'restaurant-cabinet-manager', icon: 'building', label: 'Кабинеты ресторанов', keywords: 'управление ресторанами важная информация кабинет' },
    { module: 'restaurant-orders', route: 'restaurant-orders', icon: 'restaurantOrders', label: 'Заказы ресторанов', keywords: 'рестораны ро' },
    { module: 'supply-assistant', route: 'supply-assistant', icon: 'order', label: 'Сбор заказа осн. поставки', keywords: 'помощник основная поставка sa' },
    { module: 'restaurant-orders', route: 'restaurant-unknown-barcodes', icon: 'warning', label: 'Штрихкоды', keywords: 'сканер неизвестные штрихкод barcode', badgeKey: 'scan-unknown' },
    { module: 'keg-returns', route: 'keg-returns', icon: 'kegReturn', label: 'Возврат кег', keywords: 'кеги ттн бсо' },
    { module: 'stock-collection', route: 'stock-collection', icon: 'stockCollection', label: 'Сбор остатков' },
    { module: 'surveys', route: 'surveys', icon: 'survey', label: 'Опросы', keywords: 'анкета опросник ответы рестораны' },
    { module: 'chat', route: 'chat', icon: 'chat', label: 'Чат с ресторанами' },
    { module: 'corrections', route: 'corrections', icon: 'edit', label: 'Корректировки', keywords: 'корректировки заказов' },
  ]},
];

// Страницы, которых нет в меню, но которые должны находиться поиском.
export const EXTRA_PAGES = [
  { module: 'restaurant-sales', route: 'restaurant-sales', icon: 'chartUp', label: 'Реализация ресторанов', keywords: 'продажи выручка' },
  { module: 'restaurant-orders', route: 'restaurant-report', icon: 'excel', label: 'Отчёт по заказам ресторанов', keywords: 'отчёт выгрузка' },
  { route: 'search-cards', icon: 'search', label: 'Поиск карточек', keywords: 'карточка sku' },
  { module: 'analysis', route: 'import', icon: 'import', label: 'Импорт данных' },
  { module: 'telegram', route: 'telegram-admin', icon: 'send', label: 'Telegram-бот', keywords: 'телеграм бот рассылка' },
  { route: 'admin', icon: 'gear', label: 'Админ-панель', keywords: 'админка права роли пользователи', requiresAdmin: true },
  { route: 'user-settings', icon: 'user', label: 'Настройки аккаунта', keywords: 'профиль пароль' },
];

// Все разделы одним списком — для поиска.
export const ALL_NAV_ITEMS = [
  ...SIDEBAR_SECTIONS.flatMap(s => s.items),
  ...TOOLS_GROUPS.flatMap(g => g.items),
  ...EXTRA_PAGES,
];

// route → подпись. Используется для статуса «кто на какой странице».
export const PAGE_NAMES = ALL_NAV_ITEMS.reduce((acc, item) => {
  acc[item.route] = item.label;
  return acc;
}, {
  // Дочерние страницы, которых нет в меню, но которые видно в статусе.
  'tender-detail': 'Тендер',
  'marketing-new': 'Новая активность',
  'marketing-detail': 'Маркетинговая активность',
  'protocol-detail': 'Протокол',
});

// Иконка обязана быть уникальной — одинаковые значки у разных разделов
// сбивают с толку. Проверяем при разработке, чтобы дубль не дожил до прода.
if (import.meta.env.DEV) {
  const seen = new Map();
  const dupes = [];
  for (const item of ALL_NAV_ITEMS) {
    if (seen.has(item.icon)) dupes.push(`«${seen.get(item.icon)}» и «${item.label}» → ${item.icon}`);
    else seen.set(item.icon, item.label);
  }
  if (dupes.length) {
    console.warn('[navSections] Одинаковые иконки у разделов:\n  ' + dupes.join('\n  ') +
      '\nВыберите разные значки из src/lib/icons.js.');
  }
}
