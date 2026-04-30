# Справочник API

API находится под `/api`. Главная точка входа — [api/index.php](/var/www/bk-calc/api/index.php).

## Общие правила

Формат ответа обычно JSON.

Обычная авторизация сотрудника:

```text
X-Session-Token: ...
```

Авторизация кабинета ресторана:

```text
X-RO-Token: ...
```

Некоторые серверные сценарии могут использовать API-ключ:

```text
X-API-Key: ...
```

Фронтенд почти всегда обращается к API через [src/lib/apiClient.js](/var/www/bk-calc/src/lib/apiClient.js).

## REST CRUD

REST-запросы идут на `/api/{table}`.

Примеры:

```text
GET /api/products?legal_entity=eq....
GET /api/orders?id=eq....
POST /api/orders
PATCH /api/settings?key=eq.maintenance_mode
DELETE /api/order_items?id=eq....
```

Поддерживаются фильтры в стиле:

```text
eq, neq, gt, gte, lt, lte, in, is, not, ilike, or
```

Ограничения:

- доступ разрешён только к таблицам из `$allowed` в `crud.php`;
- поля фильтрации ограничены `$filterWhitelist`;
- поля записи ограничены `$writeWhitelist`;
- часть таблиц read-only;
- часть таблиц запрещает insert/delete;
- права проверяются по модулю и уровню доступа;
- для таблиц с юрлицами проверяется доступ к `legal_entity`.

## RPC

RPC-запросы идут на:

```text
POST /api/rpc/{name}
```

Тело — JSON.

Пример:

```json
{
  "legal_entity": "ООО \"Бургер БК\""
}
```

### Публичные RPC

Эти действия доступны без обычной сессии:

- `check_user_password` — вход сотрудника;
- `check_legacy_password` — legacy-вход;
- `health_check` — проверка доступности;
- `check_maintenance` — режим техработ;
- `guest_heartbeat`, `get_guest_count` — гостевая активность;
- `log_card_search`, `get_cards`, `get_cards_last_update`, `get_stock_skus` — публичный поиск карточек;
- `deficit_validate_token`, `deficit_get_restaurants`, `deficit_submit_stock` — публичная форма дефицита;
- `sc_validate_token`, `sc_get_restaurants`, `sc_submit_stock` — публичная форма сбора остатков;
- legacy `veg_*` публичные действия.

### Сессия и пользователь

- `validate_session`
- `logout`
- `save_hidden_modules`
- `get_rbac_config`
- `change_user_password`
- `get_user_list`
- `create_user`
- `update_user`
- `delete_user`
- `get_sessions`
- `terminate_session`

### Уведомления и присутствие

- `mark_notifications_read`
- `delete_notification_for_user`
- `delete_all_notifications_for_user`
- `heartbeat`
- `check_order_lock`
- `unlock_order`
- `get_online_users`
- `send_broadcast`
- `get_active_broadcasts`
- `delete_broadcast`
- `get_broadcast_history`

### Основной заказ и данные

- `create_order`
- `update_order`
- `delete_order`
- `replace_order_items`
- `replace_item_order`
- `batch_update_received_qty`
- `calculate_adu`
- `replace_analysis_data`
- `replace_restaurant_sales`
- `replace_stock_malling`
- `replace_restaurant_schedule`

### Цены и ПСЦ

- `import_prices`
- `import_deposit_prices`
- `approve_agreement`
- `archive_agreement`
- `restore_agreement`
- `delete_agreement`
- `get_current_prices`
- `update_exchange_rate`
- `get_deposit_prices`
- `set_deposit_price`
- `delete_price`
- `get_price_history`
- `get_products_without_prices`

### Склад, сроки годности и паллеты

- `save_warehouse_cells`
- `get_warehouse_cells_range`
- `get_warehouse_cells`
- `upsert_warehouse_cell`
- `import_pallet_reference`
- `get_pallet_reference`
- `update_pallet_field`
- `calc_pallet_occupancy`

### Тендеры, маркетинг, рецепты

- `save_tender`
- `get_tender`
- `delete_tender`
- `save_marketing_activity`
- `get_marketing_activity`
- `delete_marketing_activity`
- `import_recipes`
- `get_recipe_ingredients`
- `calc_dish_shares`
- `get_recipe_groups`
- `save_recipe_group`
- `delete_recipe_group`
- `get_recipe_groups_list`
- `find_recipes_by_names`

### Распределение, корректировки, оплаты

- `dist_get_sessions`
- `dist_create_session`
- `dist_delete_session`
- `dist_close_session`
- `dist_reopen_session`
- `dist_get_session_data`
- `dist_toggle_shipped`
- `dist_update_qty`
- `dist_add_products`
- `dist_remove_product`
- `dist_save_note`
- `dist_bulk_toggle`
- `correction_review`
- `correction_review_batch`
- `correction_delete`
- `correction_clear_all`
- `correction_clear_processed`
- `correction_get_settings`
- `correction_toggle_notification`
- `create_payment_if_needed`
- `update_payment`

### Чат, протоколы, опросы

- `chat_get_conversations`
- `chat_get_messages`
- `chat_send_message`
- `chat_close_conversation`
- `chat_reopen_conversation`
- `chat_unread_total`
- `chat_send_photo`
- `chat_get_photo`
- `get_protocols`
- `get_protocol`
- `save_protocol`
- `delete_protocol`
- `update_decision_status`
- `get_carryover_tasks`
- `get_protocol_series`
- `save_protocol_series`
- `delete_protocol_series`
- `surveys_list`
- `survey_get`
- `survey_save`
- `survey_send`
- `survey_close`
- `survey_response_delete`
- `survey_delete`

### Telegram admin

- `tg_admin_bot_info`
- `tg_admin_set_webhook`
- `tg_admin_delete_webhook`
- `tg_admin_recent_questions`
- `tg_admin_stats`
- `tg_admin_send_message`
- `tg_admin_broadcast_history`
- `tg_admin_send_restaurant_reminder`
- `tg_admin_toggle_setting`
- `tg_admin_toggle_rest_notif`
- `tg_admin_unlink_user`
- `tg_admin_unlink_expired`
- `get_telegram_link`
- `confirm_telegram_link`
- `get_user_tg_settings`

### Ошибки и обратная связь

- `log_frontend_error`
- `clear_error_logs`
- `create_bug_report`
- `get_bug_reports`
- `get_bug_report`
- `reply_bug_report`
- `update_bug_report_status`
- `delete_bug_report`
- `get_bug_reports_count`
- `get_changelog`
- `get_admin_stats`
- `dashboard_kpi`
- `dashboard_critical_stock`
- `get_pending_tasks_all`

## Ресторанный кабинет: `/api/ro/*`

Модуль: [api/includes/restaurant_orders.php](/var/www/bk-calc/api/includes/restaurant_orders.php).

Публичный/ресторанный вход:

- `POST /api/ro/login`
- `POST /api/ro/tg-auth`
- `POST /api/ro/validate`
- `POST /api/ro/logout`
- `POST /api/ro/change-password`

Данные ресторана:

- `GET /api/ro/my-info`
- `GET /api/ro/products`
- `GET /api/ro/scan-product`
- `POST /api/ro/report-missing-gtin`
- `GET /api/ro/my-order/{date}`
- `GET /api/ro/my-orders`
- `GET /api/ro/all-history`
- `GET /api/ro/history-order`
- `POST /api/ro/submit-order`
- `POST /api/ro/repeat-order`

Telegram и сообщения:

- `POST /api/ro/telegram-link`
- `POST /api/ro/telegram-unlink`
- `GET /api/ro/telegram-status`
- `GET /api/ro/telegram-links`
- `GET /api/ro/broadcasts`
- `POST /api/ro/broadcast-read`
- `GET /api/ro/cabinet-posts`
- `POST /api/ro/cabinet-post-read`

Опросы и остатки:

- `GET /api/ro/my-surveys`
- `GET /api/ro/my-survey/{id}`
- `POST /api/ro/submit-survey`
- `GET /api/ro/stock-collection-status`
- `GET /api/ro/stock-collection-data`
- `POST /api/ro/stock-collection-submit`

В этом же файле ниже находятся админские маршруты ресторанных заказов. Для поиска используйте `rg "admin-" api/includes/restaurant_orders.php` и вызовы из `restaurantOrderStore.js`.

## Заявки поставщикам: `/api/so/*`

Модуль: [api/includes/supplier_orders.php](/var/www/bk-calc/api/includes/supplier_orders.php).

Маршруты ресторана:

- `GET /api/so/suppliers`
- `GET /api/so/products/{supplierId}`
- `GET /api/so/my-order/{supplierId}/{deliveryDate}`
- `GET /api/so/my-orders`
- `POST /api/so/submit-order`

Админские маршруты:

- `GET /api/so/admin/suppliers`
- `GET /api/so/admin/available-suppliers`
- `POST /api/so/admin/register-supplier`
- `POST /api/so/admin/disconnect-supplier`
- `GET /api/so/admin/settings`
- `POST /api/so/admin/settings`
- `GET /api/so/admin/status`
- `GET /api/so/admin/orders`
- `GET /api/so/admin/order/{id}`
- `PATCH /api/so/admin/order/{id}`
- `DELETE /api/so/admin/order/{id}`
- `GET /api/so/admin/schedules`
- `POST /api/so/admin/schedules`
- `GET /api/so/admin/deadline-rules`
- `POST /api/so/admin/deadline-rules`
- `POST /api/so/admin/extend-deadline`
- `POST /api/so/admin/remove-deadline-override`
- `POST /api/so/admin/close-day`
- `GET /api/so/admin/templates`
- `POST /api/so/admin/templates`
- `POST /api/so/admin/update-qty`
- `POST /api/so/admin/send-summary`

## Загрузка машин: `/api/tl/*`

Модуль: [api/includes/truck_loading.php](/var/www/bk-calc/api/includes/truck_loading.php).

- `GET /api/tl/vehicles`
- `POST /api/tl/vehicles`
- `DELETE /api/tl/vehicles/{id}`
- `GET /api/tl/orders`
- `GET /api/tl/plan`
- `POST /api/tl/plan`
- `DELETE /api/tl/plan/{id}`
- `PATCH /api/tl/plan/{id}/status`
- `POST /api/tl/auto-assign`

## Upload/download

Модуль: [api/includes/uploads.php](/var/www/bk-calc/api/includes/uploads.php).

Загрузка:

- `POST /api/upload/act`
- `DELETE /api/upload/act`
- `POST /api/upload/psc`
- `POST /api/upload/tender-kp`
- `POST /api/upload/protocol-file`
- `POST /api/upload/marketing-file`
- `POST /api/upload/bug-screenshot`

Скачивание:

- `GET /api/uploads/acts/{file}`
- `GET /api/uploads/psc/{file}`
- `GET /api/uploads/tenders/{file}`
- `GET /api/uploads/protocols/{file}`
- `GET /api/uploads/marketing/{file}`
- `GET /api/uploads/bugs/{file}`
- `GET /api/uploads/restaurant_info/{file}`

## Search и OCR

- `GET /api/search_products`
- `POST /api/ocr`

`/api/ocr` принимает multipart/form-data с полем `image`.
