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
  // Корректировки
  correction_created: 'создал корректировку',
  correction_approved: 'подтвердил корректировку',
  correction_rejected: 'отклонил корректировку',
  correction_reviewed: 'рассмотрел корректировку',
  correction_submit_cabinet: 'подал корректировку',
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
  protocol_finalized: 'финализировал протокол',
  broadcast_sent: 'отправил рассылку',
  session_terminated: 'завершил сессию',
  maintenance_toggled: 'переключил техработы',
};

// Текст действия для ленты активности. Фолбэк — ключ без подчёркиваний.
export function activityLabel(action) {
  if (!action) return '';
  return ACTIVITY_LABELS[action] || String(action).replace(/_/g, ' ');
}
