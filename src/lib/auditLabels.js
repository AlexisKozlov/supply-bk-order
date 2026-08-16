// ═══════════════════════════════════════════════════════════════════════
// Переводы для журнала действий (admin?tab=audit) и карточек истории.
//
// Раньше словарь жил внутри AdminAuditTab.vue и отставал от кода: 10 действий
// и 5 типов записей выводились английскими кодами прямо на экран
// («products», «protocol_created», «suppliers_deleted»).
//
// ПРАВИЛО: добавили новое действие в auditLog() — добавьте перевод сюда.
// Если забыли, экран не сломается: сработает разбор по окончанию
// (`*_created` → «Создано»), а при `npm run dev` в консоли появится
// предупреждение с точным кодом действия.
// ═══════════════════════════════════════════════════════════════════════

export const AUDIT_ACTION_LABELS = {
  // ── Заказы поставщикам ──
  order_created: 'Создан',
  order_updated: 'Изменён',
  order_deleted: 'Удалён',
  orders_deleted: 'Удалены',
  delivery_date_changed: 'Дата доставки',
  received: 'Принят',
  reception_reverted: 'Приёмка отменена',

  // ── Планы ──
  plan_created: 'Создан',
  plan_updated: 'Изменён',
  plan_deleted: 'Удалён',
  plans_deleted: 'Удалены',

  // ── Товары ──
  product_created: 'Создана',
  product_updated: 'Изменена',
  products_deleted: 'Удалены',

  // ── Поставщики и рестораны ──
  suppliers_deleted: 'Удалены',
  restaurants_deleted: 'Удалены',
  restaurant_updated: 'Ресторан изменён',
  schedule_updated: 'График изменён',

  // ── Сотрудники ──
  user_created: 'Заведён',
  user_updated: 'Изменён',
  user_deleted: 'Удалён',
  password_changed: 'Пароль изменён',

  // ── Цены и ПСЦ ──
  price_agreement_created: 'Протокол создан',
  price_agreement_updated: 'Протокол изменён',
  agreement_approved: 'Согласован',
  agreement_archived: 'В архив',
  agreement_restored: 'Возвращён из архива',
  agreement_deleted: 'Удалён',
  price_imported: 'Цены загружены',
  price_deleted: 'Цена удалена',
  exchange_rate_updated: 'Курс валюты',
  deposit_price_updated: 'Залоговая цена',
  deposit_price_deleted: 'Залоговая цена удалена',
  deposit_prices_imported: 'Залоговые цены загружены',

  // ── Тендеры ──
  tender_created: 'Создан',
  tender_updated: 'Изменён',
  tender_deleted: 'Удалён',

  // ── Маркетинг ──
  marketing_created: 'Создана',
  marketing_updated: 'Изменена',
  marketing_deleted: 'Удалена',
  marketing_auto_completed: 'Завершена по дате',

  // ── Корректировки ──
  correction_created: 'Создана',
  correction_taken: 'Взята в работу',
  correction_reviewed: 'Рассмотрена',
  correction_approved: 'Принята',
  correction_rejected: 'Отклонена',
  correction_approved_bot: 'Принята в боте',
  correction_rejected_bot: 'Отклонена в боте',
  correction_submit_cabinet: 'Подана из кабинета',
  correction_edit_cabinet: 'Изменена в кабинете',
  correction_cancel_cabinet: 'Отменена в кабинете',
  correction_deadline_changed: 'Срок подачи изменён',
  corrections_cleared: 'Журнал очищен',

  // ── Импорт данных ──
  data_imported: 'Загружены данные',
  recipe_imported: 'Загружены рецептуры',

  // ── Заявки поставщикам ──
  so_order_submitted: 'Заявка подана',
  so_order_updated: 'Заявка изменена',
  so_order_edited: 'Правка закупок',
  so_order_skipped: 'Поставка не нужна',
  so_order_deleted: 'Заявка удалена',
  so_qty_adjusted: 'Правка количества',
  so_adhoc_created: 'Внеплановый довоз',
  so_deadline_extended: 'Срок продлён',
  so_deadline_rules_updated: 'Сроки подачи изменены',
  so_day_closed: 'День закрыт',
  so_day_reopened: 'День открыт',
  so_template_saved: 'Шаблон сохранён',
  so_supplier_disconnected: 'Поставщик отключён',
  so_supplier_reconnected: 'Поставщик подключён',

  // ── Напоминания ресторанам ──
  reminder_sub_toggled: 'Напоминание переключено',
  reminder_main_toggled: 'Напоминание переключено',
  reminder_keg_toggled: 'Напоминание переключено',

  // ── Сбор остатков ──
  collection_created: 'Сбор создан',
  stock_collection_created: 'Сбор создан',
  collection_closed: 'Сбор закрыт',
  collection_reopened: 'Сбор переоткрыт',
  collection_deleted: 'Сбор удалён',
  collection_deadline_set: 'Срок сдачи задан',
  stock_collection_cell_saved: 'Остаток внесён',

  // ── Распределение ──
  distribution_created: 'Создано',

  // ── Совещания ──
  protocol_created: 'Протокол создан',
  protocol_updated: 'Протокол изменён',
  protocol_finalized: 'Протокол финализирован',
  protocol_deleted: 'Протокол удалён',

  // ── Загрузка машин ──
  tl_plan_created: 'План создан',
  tl_plan_updated: 'План изменён',
  tl_plan_deleted: 'План удалён',
  tl_plan_confirmed: 'План подтверждён',
  tl_plan_reopened: 'Возвращён в черновик',
  tl_vehicle_created: 'Машина добавлена',
  tl_vehicle_updated: 'Машина изменена',
  tl_vehicle_deleted: 'Машина удалена',
  tl_direction_created: 'Направление создано',
  tl_direction_updated: 'Направление изменено',
  tl_direction_deleted: 'Направление удалено',

  // ── Планета Ресторанов ──
  veg_session_created: 'Сессия создана',
  veg_order_updated: 'Заявка изменена',
  veg_order_submitted: 'Заявка подана',

  // ── Система ──
  broadcast_sent: 'Рассылка отправлена',
  session_terminated: 'Сессия завершена',
  maintenance_toggled: 'Режим техработ',
  tg_unlink_expired: 'Telegram отвязан',
};

// Имена таблиц из БД. Без перевода они попадали на экран как
// «restaurant_main_delivery_subscriptions».
export const AUDIT_ENTITY_LABELS = {
  order: 'Заказ', orders: 'Заказы',
  plan: 'План', plans: 'Планы',
  product: 'Товар', products: 'Товары',
  supplier: 'Поставщик', suppliers: 'Поставщики',
  restaurant: 'Ресторан', restaurants: 'Рестораны',
  user: 'Сотрудник',
  delivery_schedule: 'График доставки',
  price_agreement: 'Протокол цен',
  product_prices: 'Цена товара',
  marketing: 'Маркетинг',
  tender: 'Тендер',
  protocol: 'Протокол совещания',
  correction: 'Корректировка',
  order_corrections: 'Корректировка',
  distribution: 'Распределение',
  stock_collection: 'Сбор остатков',
  import: 'Импорт',
  supplier_order: 'Заявка поставщику',
  so_orders: 'Заявка поставщику',
  so_templates: 'Шаблон заявки',
  restaurant_reminder_subscriptions: 'Напоминание о заявке',
  restaurant_main_delivery_subscriptions: 'Напоминание об основной поставке',
  restaurant_keg_return_subscriptions: 'Напоминание о возврате кег',
  ro_telegram_subs: 'Привязка Telegram',
  truck_plan: 'План загрузки машин',
  truck_vehicle: 'Тип машины',
  truck_direction: 'Направление доставки',
  veg: 'Планета Ресторанов',
  system: 'Система',
};

// Группы для фильтра. Покрывают ВСЕ типы записей: раньше 16 кнопок оставляли
// без доступа протоколы, поставщиков, рестораны, напоминания и загрузку машин.
export const AUDIT_GROUPS = [
  { value: 'orders',      label: 'Заказы и планы',      types: ['order', 'orders', 'plan', 'plans'] },
  { value: 'so',          label: 'Заявки поставщикам',  types: ['supplier_order', 'so_orders', 'so_templates', 'supplier', 'suppliers'] },
  { value: 'catalog',     label: 'Справочники',         types: ['product', 'products', 'restaurant', 'restaurants', 'import'] },
  { value: 'prices',      label: 'Цены',                types: ['price_agreement', 'product_prices'] },
  { value: 'logistics',   label: 'Логистика',           types: ['delivery_schedule', 'truck_plan', 'truck_vehicle', 'truck_direction'] },
  { value: 'restaurants', label: 'Рестораны',           types: ['correction', 'order_corrections', 'restaurant_reminder_subscriptions', 'restaurant_main_delivery_subscriptions', 'restaurant_keg_return_subscriptions', 'ro_telegram_subs'] },
  { value: 'collection',  label: 'Сбор остатков',       types: ['stock_collection'] },
  { value: 'people',      label: 'Люди и система',      types: ['user', 'system'] },
  { value: 'other',       label: 'Другое',              types: ['marketing', 'tender', 'protocol', 'distribution', 'veg'] },
];

const _warnedActions = new Set();

// Разбор по окончанию — страховка на случай нового действия без перевода.
function labelBySuffix(action) {
  const a = String(action || '');
  if (/(_created|_create)$/.test(a)) return 'Создано';
  if (/(_updated|_edited|_changed|_saved|_set)$/.test(a)) return 'Изменено';
  if (/_deleted$/.test(a)) return 'Удалено';
  if (/(_imported|_import)$/.test(a)) return 'Загружено';
  if (/(_approved|_confirmed)$/.test(a)) return 'Подтверждено';
  if (/_rejected$/.test(a)) return 'Отклонено';
  if (/_closed$/.test(a)) return 'Закрыто';
  if (/(_reopened|_restored)$/.test(a)) return 'Возвращено';
  if (/_toggled$/.test(a)) return 'Переключено';
  if (/_sent$/.test(a)) return 'Отправлено';
  return null;
}

export function auditActionLabel(action) {
  if (AUDIT_ACTION_LABELS[action]) return AUDIT_ACTION_LABELS[action];
  const guess = labelBySuffix(action);
  if (import.meta.env?.DEV && action && !_warnedActions.has(action)) {
    _warnedActions.add(action);
    console.warn(
      `[журнал действий] нет перевода для «${action}». ` +
      `Добавьте его в src/lib/auditLabels.js (AUDIT_ACTION_LABELS).`,
    );
  }
  return guess || String(action || '');
}

export function auditEntityLabel(entityType) {
  return AUDIT_ENTITY_LABELS[entityType] || String(entityType || '');
}

// Все типы записей, попадающие в выбранную группу фильтра.
export function auditGroupTypes(groupValue) {
  const g = AUDIT_GROUPS.find(x => x.value === groupValue);
  return g ? g.types : [];
}

// Цвет плашки действия.
export function auditBadgeClass(action) {
  const a = String(action || '');
  if (a === 'received') return 'adm-audit-b-received';
  if (a === 'reception_reverted') return 'adm-audit-b-reverted';
  if (a === 'delivery_date_changed') return 'adm-audit-b-delivery';
  if (a === 'schedule_updated' || a === 'restaurant_updated') return 'adm-audit-b-schedule';
  if (a.includes('imported') || a === 'data_imported') return 'adm-audit-b-schedule';
  if (a.includes('approved') || a.includes('restored') || a.includes('confirmed') || a === 'broadcast_sent') return 'adm-audit-b-received';
  if (a.includes('archived') || a === 'session_terminated' || a === 'password_changed') return 'adm-audit-b-reverted';
  if (a.includes('rejected')) return 'adm-audit-b-deleted';
  if (a.includes('created')) return 'adm-audit-b-created';
  if (a.includes('updated') || a.includes('changed') || a.includes('reviewed') || a.includes('edited') || a.includes('saved')) return 'adm-audit-b-updated';
  if (a.includes('deleted') || a.includes('closed')) return 'adm-audit-b-deleted';
  return '';
}
