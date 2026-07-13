<?php
/**
 * Утилиты модуля «Заявка на пропуск».
 */

require_once __DIR__ . '/helpers.php';

/**
 * Сопоставление склада с allow_company (значения нужны системе охраны):
 *   warehouse 1 (Прилесье 1, холод/мороз) → allow_company 32
 *   warehouse 6 (Прилесье 6, сухой)        → allow_company 8
 */
function titAllowCompanyForWarehouse(int $warehouse): int
{
    return $warehouse === 1 ? 32 : 8;
}

/**
 * Тип хранения (что выбирает закупщик) → номер склада (что уходит охране):
 *   DRY    — сухой, Прилесье 6
 *   COLD   — холод, Прилесье 1
 *   FROZEN — мороз, Прилесье 1
 * Для охраны холод и мороз неразличимы: оба идут складом 1 (код доступа 32).
 */
function titWarehouseForStorageKind(string $kind): int
{
    return in_array($kind, ['COLD', 'FROZEN'], true) ? 1 : 6;
}

/**
 * По составу заказа (категории товаров Сухой/Холод/Мороз) определяет
 * рекомендуемые типы хранения. Заказ бывает микс — тогда рекомендаций несколько,
 * и закупщик заводит по машине на каждый тип.
 *
 * @return array<string>  например ['DRY'] или ['COLD','FROZEN'] или ['DRY','COLD','FROZEN']
 */
function titDetectStorageKindsForOrder(PDO $pdo, string $orderId, string $legalEntity): array
{
    $kinds = [];
    try {
        $skuStmt = $pdo->prepare("SELECT DISTINCT sku FROM order_items WHERE order_id = ? AND sku IS NOT NULL AND sku <> ''");
        $skuStmt->execute([$orderId]);
        $skus = array_filter(array_map('strval', $skuStmt->fetchAll(PDO::FETCH_COLUMN)));
        if ($skus) {
            $grp = getEntityGroup($legalEntity);
            $entitiesInGroup = getEntitiesInGroup($grp);
            $phSku = implode(',', array_fill(0, count($skus), '?'));
            $phEnt = implode(',', array_fill(0, count($entitiesInGroup), '?'));
            $st = $pdo->prepare("SELECT DISTINCT category FROM products WHERE sku IN ($phSku) AND legal_entity IN ($phEnt)");
            $st->execute(array_merge($skus, $entitiesInGroup));
            foreach ($st->fetchAll() as $row) {
                $cat = (string)($row['category'] ?? '');
                if ($cat === 'Сухой') $kinds['DRY'] = true;
                if ($cat === 'Холод') $kinds['COLD'] = true;
                if ($cat === 'Мороз') $kinds['FROZEN'] = true;
            }
        }
    } catch (Throwable $e) {
        error_log('[tit_helpers] detectStorageKinds failed: ' . $e->getMessage());
    }

    if (!$kinds) return ['DRY']; // дефолт — сухой склад, если категорий не нашли
    // Порядок фиксируем: сухой, холод, мороз.
    return array_values(array_filter(['DRY', 'COLD', 'FROZEN'], fn($k) => isset($kinds[$k])));
}

/**
 * Те же рекомендации, но номерами складов — для мест, где нужен именно склад.
 *
 * @return array<int>  например [6] или [1] или [1, 6]
 */
function titDetectWarehousesForOrder(PDO $pdo, string $orderId, string $legalEntity): array
{
    $kinds = titDetectStorageKindsForOrder($pdo, $orderId, $legalEntity);
    $out = [];
    foreach ($kinds as $k) $out[titWarehouseForStorageKind($k)] = true;
    return array_keys($out);
}

/**
 * Идемпотентно создаёт «Заявку на пропуск» под основной заказ.
 * Вызывается при create_order/update_order/send_supplier_order_email.
 * Если для этого order_id уже есть запись — обновляем supplier/дату/юрлицо
 * (статус и outgoing_message_id не трогаем).
 *
 * Тихо проглатывает любые ошибки — это побочное действие, не должно
 * блокировать сохранение заказа.
 *
 * @param ?string $outgoingMessageId  если задан — сохраним в существующую запись
 *                                    (нужно для трекинга ответа email).
 * @return ?int  id записи tit_requests или null если что-то пошло не так.
 */
function titEnsureRequestForOrder(
    PDO $pdo,
    string $orderId,
    ?string $supplierId,
    string $supplierName,
    string $supplierEmail,
    string $legalEntity,
    string $deliveryDate,
    ?string $createdBy,
    ?string $outgoingMessageId = null
): ?int {
    if ($orderId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) return null;
    try {
        $group = function_exists('getEntityGroup') ? getEntityGroup($legalEntity) : 'BK_VM';
        $check = $pdo->prepare("SELECT id FROM tit_requests WHERE order_id = ? LIMIT 1");
        $check->execute([$orderId]);
        $existingId = $check->fetchColumn();
        if ($existingId) {
            $pdo->prepare("
                UPDATE tit_requests
                SET supplier_id = ?, supplier_name = ?, supplier_email = ?,
                    legal_entity = ?, legal_entity_group = ?, delivery_date = ?,
                    outgoing_message_id = COALESCE(?, outgoing_message_id),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([
                $supplierId, $supplierName, $supplierEmail,
                $legalEntity, $group, $deliveryDate,
                $outgoingMessageId, $existingId,
            ]);
            return (int)$existingId;
        }
        $pdo->prepare("
            INSERT INTO tit_requests
                (order_id, supplier_id, supplier_name, supplier_email,
                 legal_entity, legal_entity_group, delivery_date,
                 status, outgoing_message_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'WAITING', ?, ?)
        ")->execute([
            $orderId, $supplierId, $supplierName, $supplierEmail,
            $legalEntity, $group, $deliveryDate,
            $outgoingMessageId, $createdBy,
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('[titEnsureRequestForOrder] failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Подбирает supplier_id по имени поставщика и юрлицу — для случаев,
 * когда в заказе сохранено только имя (orders.supplier — varchar, не FK).
 */
function titFindSupplierIdByName(PDO $pdo, string $supplierName, string $legalEntity): ?string
{
    if ($supplierName === '') return null;
    try {
        $group = function_exists('getEntityGroup') ? getEntityGroup($legalEntity) : 'BK_VM';
        $s = $pdo->prepare("
            SELECT id FROM suppliers
            WHERE legal_entity_group = ?
              AND (short_name = ? OR full_name = ?)
              AND is_active = 1
            ORDER BY (legal_entity = ?) DESC, id
            LIMIT 1
        ");
        $s->execute([$group, $supplierName, $supplierName, $legalEntity]);
        $id = $s->fetchColumn();
        return $id ? (string)$id : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Запоминаем последнюю машину и водителя по поставщику для подсказки
 * «Подставить прошлую». Идемпотентно через REPLACE по supplier_id.
 */
function titRememberSupplierDefaults(PDO $pdo, ?string $supplierId, ?string $plate, ?string $phone): void
{
    if (!$supplierId || (!$plate && !$phone)) return;
    try {
        $pdo->prepare("
            INSERT INTO tit_supplier_defaults (supplier_id, last_plate, last_phone, last_used_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
              last_plate = COALESCE(VALUES(last_plate), last_plate),
              last_phone = COALESCE(VALUES(last_phone), last_phone),
              last_used_at = NOW()
        ")->execute([$supplierId, $plate ?: null, $phone ?: null]);
    } catch (Throwable $e) {
        error_log('[tit_helpers] remember defaults failed: ' . $e->getMessage());
    }
}

/**
 * Отправляет сотруднику Telegram-уведомление о событии по заявке.
 * Тихо игнорирует, если у сотрудника нет привязки к боту или нет токена.
 */
function titNotifyStaff(PDO $pdo, ?string $userName, string $message): void
{
    if (!$userName) return;
    $botToken = $_ENV['TG_BOT_TOKEN'] ?? ($_ENV['TELEGRAM_BOT_TOKEN'] ?? '');
    if (!$botToken) return;
    try {
        $st = $pdo->prepare("SELECT telegram_chat_id FROM users WHERE name = ? AND telegram_chat_id IS NOT NULL AND telegram_chat_id <> '' LIMIT 1");
        $st->execute([$userName]);
        $chatId = $st->fetchColumn();
        if (!$chatId) return;
        if (function_exists('sendTelegramMessage')) {
            sendTelegramMessage($botToken, $chatId, $message, 'HTML');
        }
    } catch (Throwable $e) {
        error_log('[tit_helpers] notify staff failed: ' . $e->getMessage());
    }
}
