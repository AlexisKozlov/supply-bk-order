/**
 * Единый справочник типов действий из audit_log → читаемый текст.
 * Используется в ленте «Активность команды» (HomeView) и на дашборде.
 * Стиль — глагольная фраза («создал заказ»), т.к. рядом стоит имя пользователя.
 *
 * Для админ-журнала (/admin?tab=audit) свой короткий «бейдж»-стиль
 * (AUDIT_ACTION_LABELS в AdminView.vue) — там метки вроде «Создан».
 * При добавлении нового типа действия в бэкенде дополняй ОБА места.
 */
export const ACTIVITY_LABELS = {
  // Заказы
  order_created: 'создал заказ',
  order_updated: 'изменил заказ',
  order_deleted: 'удалил заказ',
  orders_deleted: 'удалил заказы',
  received: 'принял поставку',
  reception_reverted: 'отменил приёмку',
  delivery_date_changed: 'перенёс доставку',
  // Планы
  plan_created: 'создал план',
  plan_updated: 'изменил план',
  plan_deleted: 'удалил план',
  plans_deleted: 'удалил планы',
  // Товары
  product_created: 'добавил товар',
  product_updated: 'изменил товар',
  products_deleted: 'удалил товар',
  // Расписание / рестораны
  schedule_updated: 'обновил расписание',
  restaurant_updated: 'обновил ресторан',
  // Цены, ПСЦ, курс, залог
  price_agreement_created: 'создал протокол цен',
  price_agreement_updated: 'изменил протокол цен',
  agreement_approved: 'согласовал протокол',
  agreement_archived: 'архивировал протокол',
  agreement_restored: 'восстановил протокол',
  agreement_deleted: 'удалил протокол',
  price_imported: 'импортировал цены',
  price_deleted: 'удалил цену',
  deposit_price_updated: 'обновил залоговую цену',
  deposit_prices_imported: 'импортировал залоговые цены',
  exchange_rate_updated: 'обновил курс валют',
  // Импорт данных
  data_imported: 'импортировал данные',
  recipe_imported: 'импортировал рецептуры',
  // Тендеры
  tender_created: 'создал тендер',
  tender_updated: 'изменил тендер',
  tender_deleted: 'удалил тендер',
  // Маркетинг
  marketing_created: 'создал активность',
  marketing_updated: 'изменил активность',
  marketing_deleted: 'удалил активность',
  marketing_auto_completed: 'завершил акцию по дате',
  // Корректировки
  correction_created: 'создал корректировку',
  correction_approved: 'подтвердил корректировку',
  correction_rejected: 'отклонил корректировку',
  correction_reviewed: 'рассмотрел корректировку',
  correction_submit_cabinet: 'подал корректировку',
  correction_deadline_changed: 'изменил дедлайн корректировок',
  // Сбор остатков
  stock_collection_created: 'создал сбор остатков',
  collection_created: 'создал сбор остатков',
  collection_closed: 'закрыл сбор остатков',
  collection_reopened: 'переоткрыл сбор остатков',
  collection_deadline_set: 'изменил срок сдачи остатков',
  stock_collection_cell_saved: 'заполнил остаток по ячейке',
  // Распределение
  distribution_created: 'создал распределение',
  // Заявки поставщикам (so_*)
  so_order_submitted: 'подал заявку поставщику',
  so_order_updated: 'обновил заявку поставщику',
  so_order_skipped: 'отметил «поставка не нужна»',
  so_order_edited: 'изменил заявку поставщику',
  so_order_deleted: 'удалил заявку поставщику',
  so_qty_adjusted: 'скорректировал количество',
  so_deadline_extended: 'продлил дедлайн заявок',
  so_day_closed: 'закрыл день заявок',
  so_day_reopened: 'переоткрыл день заявок',
  so_template_saved: 'сохранил шаблон заявок',
  // Напоминания о подаче заявок
  reminder_sub_toggled: 'настроил напоминания',
  reminder_main_toggled: 'настроил напоминания',
  // Пользователи / система
  user_created: 'добавил пользователя',
  user_updated: 'изменил пользователя',
  user_deleted: 'удалил пользователя',
  password_changed: 'сменил пароль',
  login: 'вошёл в систему',
  protocol_created: 'создал протокол',
  protocol_updated: 'изменил протокол',
  protocol_finalized: 'финализировал протокол',
  protocol_deleted: 'удалил протокол',
  broadcast_sent: 'отправил рассылку',
  session_terminated: 'завершил сессию',
  maintenance_toggled: 'переключил техработы',

  // Заявки поставщикам — дополнено по фактическому журналу: эти виды
  // попадали в ленту и в «Мои действия» английскими ключами.
  so_deadline_rules_updated: 'изменил правила дедлайнов',
  so_adhoc_created: 'создал внеплановую заявку',
  so_supplier_disconnected: 'отключил поставщика',
  so_supplier_reconnected: 'вернул поставщика',
  // Корректировки
  correction_taken: 'взял корректировку в работу',
  correction_approved_bot: 'согласовал корректировку в боте',
  correction_edit_cabinet: 'изменил корректировку из кабинета',
  correction_cancel_cabinet: 'отменил корректировку из кабинета',
  // Напоминания и справочники
  reminder_keg_toggled: 'переключил напоминания о кегах',
  suppliers_deleted: 'удалил поставщиков',
  restaurants_deleted: 'удалил рестораны',
  collection_deleted: 'удалил сбор остатков',
  // Овощи
  veg_session_created: 'создал сбор по овощам',
  veg_order_updated: 'изменил заказ овощей',
  // Загрузка машин
  tl_plan_created: 'создал план загрузки машин',
  tl_plan_updated: 'изменил план загрузки машин',
  tl_plan_confirmed: 'подтвердил план загрузки машин',
  tl_plan_reopened: 'вернул план загрузки машин в черновик',
  tl_plan_deleted: 'удалил план загрузки машин',
  tl_vehicle_created: 'добавил тип машины',
  tl_vehicle_updated: 'изменил тип машины',
  tl_vehicle_deleted: 'удалил тип машины',
  tl_direction_created: 'создал направление доставки',
  tl_direction_updated: 'изменил направление доставки',
  tl_direction_deleted: 'удалил направление доставки',
};

/**
 * Название объекта, к которому относится действие (audit_log.entity_type).
 * В журнале это технические имена таблиц — человеку они ничего не говорят.
 */
export const ENTITY_LABELS = {
  order: 'заказ',
  orders: 'заказы',
  supplier_order: 'заявка поставщику',
  product: 'товар',
  products: 'товары',
  product_prices: 'цены',
  supplier: 'поставщик',
  suppliers: 'поставщики',
  restaurant: 'ресторан',
  restaurants: 'рестораны',
  import: 'импорт данных',
  delivery_schedule: 'график доставки',
  supplier_schedule: 'график поставок',
  stock_collection: 'сбор остатков',
  marketing: 'маркетинг',
  plan: 'план',
  plans: 'планы',
  tender: 'тендер',
  user: 'сотрудник',
  users: 'сотрудники',
  correction: 'корректировка',
  order_corrections: 'корректировки заказов',
  restaurant_reminder_subscriptions: 'напоминания ресторану',
  restaurant_main_delivery_subscriptions: 'напоминания об основной поставке',
  restaurant_keg_return_subscriptions: 'напоминания о кегах',
  keg_return: 'возврат кег',
  marketing_activities: 'маркетинговые активности',
  veg: 'овощи',
  recipe: 'рецептура',
  protocol: 'протокол',
  system: 'система',
  settings: 'настройки',
  task: 'задача',
  handover: 'передача дел',
  truck_plan: 'план загрузки машин',
  truck_vehicle: 'тип машины',
  truck_direction: 'направление доставки',
};

// Человеческое имя объекта. Фолбэк — ключ без подчёркиваний.
export function entityLabel(type) {
  if (!type) return '';
  return ENTITY_LABELS[type] || String(type).replace(/_/g, ' ');
}

const _warned = new Set();

// Текст действия для ленты активности. Фолбэк — ключ без подчёркиваний.
// Второй словарь, для короткой плашки в журнале админки, лежит в
// src/lib/auditLabels.js — новое действие дописывайте в ОБА.
export function activityLabel(action) {
  if (!action) return '';
  if (ACTIVITY_LABELS[action]) return ACTIVITY_LABELS[action];
  if (import.meta.env?.DEV && !_warned.has(action)) {
    _warned.add(action);
    console.warn(
      `[лента активности] нет перевода для «${action}». ` +
      `Добавьте его в src/lib/auditActions.js (ACTIVITY_LABELS) и в src/lib/auditLabels.js.`,
    );
  }
  return String(action).replace(/_/g, ' ');
}
