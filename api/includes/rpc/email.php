<?php
/**
 * RPC: отправка email поставщикам и одноразовые download-токены.
 *
 * Подключается из api/includes/rpc.php внутри блока приватных RPC.
 */

    if ($fn === 'send_supplier_order_email') {
        if (!$authUser) respond(['error' => 'Требуется авторизация'], 401);

        $rawTo       = trim((string)($body['to'] ?? ''));
        $bodyText    = (string)($body['body_text'] ?? '');
        $supplier    = trim((string)($body['supplier'] ?? ''));
        $legalEntity = trim((string)($body['legal_entity'] ?? ''));
        $delivery    = trim((string)($body['delivery_date'] ?? ''));
        $itemsCount  = (int)($body['items_count'] ?? 0);
        $orderId     = trim((string)($body['order_id'] ?? ''));

        if ($rawTo === '') respond(['error' => 'Не указан email получателя'], 400);
        if ($bodyText === '') respond(['error' => 'Пустое тело письма'], 400);

        // Парсим список адресов (запятая, точка с запятой, пробел).
        $recipients = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $rawTo)), function ($e) {
            return $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL);
        }));
        if (!$recipients) respond(['error' => 'Не указан корректный email получателя'], 400);
        if (count($recipients) > 10) respond(['error' => 'Слишком много адресов (максимум 10)'], 400);

        // В теме и заголовке письма используем полное наименование поставщика,
        // если оно заполнено в справочнике. Иначе — короткое (то, что пришло).
        // Заодно тянем cc_emails — постоянные получатели в копию.
        //
        // Ищем в пределах ГРУППЫ юрлиц (BK_VM / PS), а не точного юрлица:
        // карточки поставщиков обычно заведены только под одно юрлицо в группе
        // (как правило — основное), и заказ от другого юрлица группы должен
        // подтягивать тот же full_name. Без этого ВМ-заказы получали короткое
        // имя в теме, а БК-заказы — полное.
        $supplierDisplay = $supplier;
        $supplierCcRaw   = '';
        if ($supplier !== '') {
            $senderGroup = getEntityGroup($legalEntity);
            try {
                $sStmt = $pdo->prepare("
                    SELECT full_name, cc_emails
                    FROM suppliers
                    WHERE legal_entity_group = ?
                      AND (short_name = ? OR full_name = ?)
                      AND is_active = 1
                    ORDER BY (legal_entity = ?) DESC, id
                    LIMIT 1
                ");
                $sStmt->execute([$senderGroup, $supplier, $supplier, $legalEntity]);
                $sRow = $sStmt->fetch();
                if ($sRow) {
                    $full = trim((string)($sRow['full_name'] ?? ''));
                    if ($full !== '') $supplierDisplay = $full;
                    $supplierCcRaw = (string)($sRow['cc_emails'] ?? '');
                }
            } catch (Throwable $e) {}
        }

        $deliveryLabel = $delivery !== '' ? $delivery : '';
        // Тема: «Заказ от <юрлицо> для <supplier> на <дата>».
        // Юрлицо в теме важно — поставщик работает с несколькими нашими компаниями.
        $subjParts = ['Заказ'];
        if ($legalEntity !== '')    $subjParts[] = 'от ' . $legalEntity;
        if ($supplierDisplay !== '') $subjParts[] = 'для ' . $supplierDisplay;
        if ($deliveryLabel !== '')   $subjParts[] = 'на ' . $deliveryLabel;
        $subject = implode(' ', $subjParts);
        if (mb_strlen($subject) > 200) $subject = mb_substr($subject, 0, 200);

        require_once __DIR__ . '/../mail_send.php';
        require_once __DIR__ . '/../mail_templates.php';

        $siteUrl = rtrim($_ENV['SITE_URL'] ?? 'https://supply-department.online', '/');

        // Структурированные позиции для таблицы. Если фронт прислал items[] —
        // строим аккуратную таблицу, иначе fallback на текстовый body_text.
        $itemsRaw = isset($body['items']) && is_array($body['items']) ? $body['items'] : [];
        $esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
        $formatInt = function ($n) {
            $n = (float)$n;
            return number_format($n, ($n - floor($n) > 0.0001) ? 2 : 0, ',', ' ');
        };

        $itemsHtml = '';
        if (!empty($itemsRaw)) {
            // Outlook (Word-движок) игнорирует border-collapse/border-radius и многие CSS-свойства,
            // зато уважает border на каждой ячейке и mso-line-height-rule:exactly.
            // Поэтому: бордер у каждой td/th, фиксированный line-height в px против раздутых строк.
            $cellBase = 'padding:6px 10px;border:1px solid #d1d5db;line-height:18px;mso-line-height-rule:exactly;';
            $headBase = 'padding:6px 10px;border:1px solid #d1d5db;line-height:14px;mso-line-height-rule:exactly;background:#e9eef3;color:#1f2937;font-weight:700;font-size:12px;text-transform:uppercase;';
            $rowsHtml = '';
            $i = 0;
            foreach ($itemsRaw as $it) {
                if (!is_array($it)) continue;
                $i++;
                $sku    = $esc($it['sku']    ?? '');
                $name   = $esc($it['name']   ?? '');
                $boxes  = $formatInt($it['boxes']  ?? 0);
                $pieces = $formatInt($it['pieces'] ?? 0);
                $unit   = $esc($it['unit']   ?? 'шт');
                $rowBg  = ($i % 2 === 0) ? '#f6f8fa' : '#ffffff';
                $rowsHtml .=
                    '<tr>'
                  . '<td style="' . $cellBase . 'background:' . $rowBg . ';color:#6b7280;text-align:right;width:32px;">' . $i . '</td>'
                  . '<td style="' . $cellBase . 'background:' . $rowBg . ';color:#374151;white-space:nowrap;">' . $sku . '</td>'
                  . '<td style="' . $cellBase . 'background:' . $rowBg . ';color:#111827;">' . $name . '</td>'
                  . '<td style="' . $cellBase . 'background:' . $rowBg . ';color:#111827;text-align:right;white-space:nowrap;font-weight:700;">' . $boxes . ' кор.</td>'
                  . '<td style="' . $cellBase . 'background:' . $rowBg . ';color:#4b5563;text-align:right;white-space:nowrap;">' . $pieces . ' ' . $unit . '</td>'
                  . '</tr>';
            }
            $itemsHtml =
                '<table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;mso-table-lspace:0;mso-table-rspace:0;margin:24px 0 20px;font-family:Arial,sans-serif;font-size:14px;color:#1f2937;">'
              . '<thead><tr>'
              . '<th style="' . $headBase . 'text-align:right;">№</th>'
              . '<th style="' . $headBase . 'text-align:left;">Артикул</th>'
              . '<th style="' . $headBase . 'text-align:left;">Наименование</th>'
              . '<th style="' . $headBase . 'text-align:right;">Кол-во</th>'
              . '<th style="' . $headBase . 'text-align:right;">Штук</th>'
              . '</tr></thead>'
              . '<tbody>' . $rowsHtml . '</tbody>'
              . '</table>';
        } else {
            // fallback на старый текстовый блок — если items не прислали.
            $itemsHtml = '<div style="white-space:pre-wrap;margin-top:14px;font-size:14px;color:#1f2937;">'
                       . nl2br($esc($bodyText))
                       . '</div>';
        }

        // Минимализм без рамок и цветных блоков. Одна шапка-предложение,
        // таблица, две строки подписи. Дублирование заголовка убрано.
        $greetingLine = 'Здравствуйте!';
        $reqParts = ['Просьба отгрузить товар'];
        if ($legalEntity   !== '') $reqParts[] = 'для <strong>' . $esc($legalEntity) . '</strong>';
        if ($deliveryLabel !== '') $reqParts[] = 'с поставкой <strong>' . $esc($deliveryLabel) . '</strong>';
        $requestLine = implode(' ', $reqParts) . '.';

        // Адрес(а) разгрузки. Берём категории товаров из БД по списку SKU из items
        // в пределах группы юрлиц отправителя — это надёжнее, чем доверять фронту.
        // Сухой → склад №6, Холод/Мороз → склад №1. Если в заказе оба типа —
        // показываем оба адреса с пометкой категории.
        $ADDR_DRY  = 'Минский район, Луговослободский с/с, М4 18 км, Склад №6 ТЛК "Прилесье"';
        $ADDR_COLD = 'Минский р-н, Луговослободский с/с, М4 18-й км, 2А/1, ТЛК "Прилесье", склад №1';
        $hasDry = false; $hasCold = false;
        if (!empty($itemsRaw)) {
            $skuList = [];
            foreach ($itemsRaw as $it) {
                if (!is_array($it)) continue;
                $s = trim((string)($it['sku'] ?? ''));
                if ($s !== '') $skuList[$s] = true;
            }
            if (!empty($skuList)) {
                try {
                    $grp = getEntityGroup($legalEntity);
                    $entitiesInGroup = getEntitiesInGroup($grp);
                    $skus = array_keys($skuList);
                    $phSku = implode(',', array_fill(0, count($skus), '?'));
                    $phEnt = implode(',', array_fill(0, count($entitiesInGroup), '?'));
                    $st = $pdo->prepare("SELECT DISTINCT category FROM products WHERE sku IN ($phSku) AND legal_entity IN ($phEnt)");
                    $st->execute(array_merge($skus, $entitiesInGroup));
                    foreach ($st->fetchAll() as $row) {
                        $cat = (string)($row['category'] ?? '');
                        if ($cat === 'Сухой') $hasDry = true;
                        if ($cat === 'Холод' || $cat === 'Мороз') $hasCold = true;
                    }
                } catch (Throwable $e) {
                    error_log('[send_supplier_order_email] category lookup failed: ' . $e->getMessage());
                }
            }
        }
        $addressLines = [];
        if ($hasDry && $hasCold) {
            $addressLines[] = 'Адрес разгрузки (сухое): ' . $ADDR_DRY . '.';
            $addressLines[] = 'Адрес разгрузки (холод/мороз): ' . $ADDR_COLD . '.';
        } elseif ($hasDry) {
            $addressLines[] = 'Адрес разгрузки: ' . $ADDR_DRY . '.';
        } elseif ($hasCold) {
            $addressLines[] = 'Адрес разгрузки: ' . $ADDR_COLD . '.';
        }
        $addressHtml = '';
        foreach ($addressLines as $line) {
            $addressHtml .= '<div style="margin-top:6px;">' . $esc($line) . '</div>';
        }

        $hasAttachment = !empty($body['attachment']) && is_array($body['attachment']);
        $attachLine = $hasAttachment
            ? '<div style="margin-top:18px;color:#4b5563;">Подробности — во вложении (Excel).</div>'
            : '';

        // Просьба о скане накладной и данных машины — нужны модулю «Заявка
        // на пропуск». Шаблон редактируется через таблицу tit_settings,
        // чтобы менять текст без релиза. Если настройки нет — fallback.
        $titAskHtml = '';
        try {
            $titStmt = $pdo->prepare("SELECT setting_value FROM tit_settings WHERE setting_key = 'email_template_addition' LIMIT 1");
            $titStmt->execute();
            $titAskRaw = (string)($titStmt->fetchColumn() ?: '');
        } catch (Throwable $e) {
            $titAskRaw = '';
        }
        if ($titAskRaw === '') {
            $titAskRaw = "Перед отгрузкой, пожалуйста, пришлите скан накладной.\nВ ответ на это письмо укажите номер машины и телефон водителя.";
        }
        foreach (preg_split('/\r\n|\n|\r/', $titAskRaw) as $line) {
            $line = trim($line);
            if ($line !== '') $titAskHtml .= '<div style="margin-top:6px;">' . $esc($line) . '</div>';
        }

        $html =
            '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
          . '<body style="margin:0;padding:0;background:#ffffff;color:#1f2937;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;font-size:14px;line-height:1.55;">'
          . '<div style="padding:24px 28px;max-width:760px;font-size:14px;">'
          . '<div style="margin-bottom:6px;">' . $greetingLine . '</div>'
          . '<div>' . $requestLine . '</div>'
          . $addressHtml
          . $itemsHtml
          . $attachLine
          . ($titAskHtml ? '<div style="margin-top:18px;color:#1f2937;">' . $titAskHtml . '</div>' : '')
          . '<div style="margin-top:22px;color:#1f2937;">Спасибо!</div>'
          . '</div>'
          . '</body></html>';

        // Slать с заказного ящика order@, Reply-To — туда же.
        $orderEmail = $_ENV['SMTP_ORDER_USER'] ?? 'order@supply-department.online';
        // getSessionUser() не возвращает id в массиве — достаём id и email
        // одним запросом по уникальному name (для CC отправителю и аудита).
        $senderEmail = '';
        $senderUserId = null;
        try {
            $eStmt = $pdo->prepare("SELECT id, email FROM users WHERE name = ? LIMIT 1");
            $eStmt->execute([$authUserName]);
            $senderRow = $eStmt->fetch();
            if ($senderRow) {
                $senderEmail  = trim((string)($senderRow['email'] ?? ''));
                $senderUserId = $senderRow['id'] ?? null;
            }
        } catch (Throwable $e) {}

        // Финальный список CC. Если фронт прислал свой `cc` (после
        // предпросмотра/правки в модалке) — берём как итог. Иначе собираем
        // сами: отправитель + cc_emails поставщика.
        $parseEmails = function ($raw) {
            if ($raw === null || $raw === '') return [];
            if (is_array($raw)) {
                $list = $raw;
            } else {
                $list = preg_split('/[,;\s]+/', (string)$raw);
            }
            $out = [];
            foreach ($list as $item) {
                $e = trim((string)$item);
                if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = $e;
            }
            return $out;
        };

        if (array_key_exists('cc', $body)) {
            $ccList = $parseEmails($body['cc']);
        } else {
            $ccList = [];
            if ($senderEmail !== '' && filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
                $ccList[] = $senderEmail;
            }
            foreach ($parseEmails($supplierCcRaw) as $e) $ccList[] = $e;
        }
        // Дедупликация (case-insensitive) и исключение тех, кто уже в To.
        $toLower = array_map('strtolower', $recipients);
        $seen = array_flip($toLower);
        $ccFinal = [];
        foreach ($ccList as $e) {
            $key = strtolower($e);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $ccFinal[] = $e;
        }
        if (count($ccFinal) > 10) $ccFinal = array_slice($ccFinal, 0, 10);

        $opts = ['account' => 'order', 'reply_to' => $orderEmail];
        if (!empty($ccFinal)) $opts['cc'] = $ccFinal;

        // Вложение от фронта (например, xlsx-заявка). Жёсткие лимиты по
        // размеру и расширению — чтобы не дать прицепить что попало.
        if ($hasAttachment) {
            $att = $body['attachment'];
            $fname = trim((string)($att['filename'] ?? 'order.xlsx'));
            $b64   = (string)($att['content_b64'] ?? '');
            $mime  = trim((string)($att['mime'] ?? '')) ?: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            // Безопасное имя файла.
            $fname = preg_replace('/[^\p{L}\p{N}\.\-_ ]+/u', '_', $fname);
            if (mb_strlen($fname) > 120) $fname = mb_substr($fname, 0, 120);
            // Размер декода — не больше 4 МБ.
            $decoded = base64_decode($b64, true);
            if ($decoded !== false && strlen($decoded) > 0 && strlen($decoded) <= 4 * 1024 * 1024) {
                $opts['attachments'] = [[
                    'filename'    => $fname,
                    'content_b64' => $b64,
                    'mime'        => $mime,
                ]];
            }
        }

        $sendResult = sendEmail($recipients, $subject, $html, true, $opts);

        $userId = $senderUserId;
        try {
            $pdo->prepare("INSERT INTO order_email_log (sender_user_id, sender_user_name, recipients, cc_recipients, subject, supplier, legal_entity, delivery_date, items_count, success, error_message, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([
                    $userId,
                    $authUserName,
                    implode(', ', $recipients),
                    !empty($ccFinal) ? implode(', ', $ccFinal) : null,
                    $subject,
                    $supplier !== '' ? $supplier : null,
                    $legalEntity !== '' ? $legalEntity : null,
                    $delivery !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivery) ? $delivery : null,
                    $itemsCount ?: null,
                    $sendResult['success'] ? 1 : 0,
                    $sendResult['success'] ? null : mb_substr((string)($sendResult['error'] ?? ''), 0, 500),
                    $clientIp ?? null,
                ]);
        } catch (Throwable $e) {
            error_log('[send_supplier_order_email] log insert failed: ' . $e->getMessage());
        }

        if (!$sendResult['success']) {
            respond(['error' => 'Не удалось отправить письмо: ' . ($sendResult['error'] ?? 'неизвестная ошибка')], 500);
        }

        // Создаём/обновляем «Заявку на пропуск» под этот заказ.
        // Заказ на этот момент может быть ещё не сохранён (письмо часто шлют
        // раньше сохранения) — order_id тогда пустой, и это нормально: заявку
        // всё равно заводим, иначе Message-Id письма негде хранить и ответ
        // поставщика не с чем будет связать. Когда заказ сохранят,
        // titEnsureRequestForOrder найдёт эту заявку по поставщику и дате
        // поставки и проставит ей order_id.
        $titRequestId = null;
        if ($delivery !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivery)) {
            try {
                require_once __DIR__ . '/../tit_helpers.php';
                $supplierId = null;
                if ($supplier !== '') {
                    $sIdStmt = $pdo->prepare("
                        SELECT id FROM suppliers
                        WHERE legal_entity_group = ?
                          AND (short_name = ? OR full_name = ?)
                          AND is_active = 1
                        ORDER BY (legal_entity = ?) DESC, id
                        LIMIT 1
                    ");
                    $sIdStmt->execute([getEntityGroup($legalEntity), $supplier, $supplier, $legalEntity]);
                    $supplierId = $sIdStmt->fetchColumn() ?: null;
                }
                $titRequestId = titEnsureRequestForOrder(
                    $pdo,
                    $orderId,
                    $supplierId,
                    $supplierDisplay !== '' ? $supplierDisplay : $supplier,
                    $recipients[0] ?? '',
                    $legalEntity,
                    $delivery,
                    $authUserName,
                    $sendResult['message_id'] ?? null
                );
            } catch (Throwable $e) {
                // Не блокируем ответ — заявку можно создать вручную позже.
                error_log('[send_supplier_order_email] tit_request upsert failed: ' . $e->getMessage());
            }
        }

        respond(['success' => true, 'sent_to' => $recipients, 'cc' => $ccFinal, 'tit_request_id' => $titRequestId]);
    }

    if ($fn === 'send_supplier_plan_email') {
        if (!$authUser) respond(['error' => 'Требуется авторизация'], 401);

        $rawTo        = trim((string)($body['to'] ?? ''));
        $bodyText     = (string)($body['body_text'] ?? '');
        $supplier     = trim((string)($body['supplier'] ?? ''));
        $legalEntity  = trim((string)($body['legal_entity'] ?? ''));
        $periodLabels = isset($body['period_labels']) && is_array($body['period_labels'])
            ? array_values(array_filter(array_map(static function ($p) { return trim((string)$p); }, $body['period_labels'])))
            : [];
        $itemsCount   = (int)($body['items_count'] ?? 0);

        if ($rawTo === '') respond(['error' => 'Не указан email получателя'], 400);

        $recipients = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $rawTo)), function ($e) {
            return $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL);
        }));
        if (!$recipients) respond(['error' => 'Не указан корректный email получателя'], 400);
        if (count($recipients) > 10) respond(['error' => 'Слишком много адресов (максимум 10)'], 400);

        // Полное имя поставщика и его cc_emails (по группе юрлиц).
        $supplierDisplay = $supplier;
        $supplierCcRaw   = '';
        if ($supplier !== '') {
            $senderGroup = getEntityGroup($legalEntity);
            try {
                $sStmt = $pdo->prepare("
                    SELECT full_name, cc_emails
                    FROM suppliers
                    WHERE legal_entity_group = ?
                      AND (short_name = ? OR full_name = ?)
                      AND is_active = 1
                    ORDER BY (legal_entity = ?) DESC, id
                    LIMIT 1
                ");
                $sStmt->execute([$senderGroup, $supplier, $supplier, $legalEntity]);
                $sRow = $sStmt->fetch();
                if ($sRow) {
                    $full = trim((string)($sRow['full_name'] ?? ''));
                    if ($full !== '') $supplierDisplay = $full;
                    $supplierCcRaw = (string)($sRow['cc_emails'] ?? '');
                }
            } catch (Throwable $e) {}
        }

        // Метка периодов: «P1—Pn» если ≥2, иначе одна строка.
        $periodLabelText = '';
        if (count($periodLabels) >= 2) {
            $periodLabelText = $periodLabels[0] . '—' . $periodLabels[count($periodLabels) - 1];
        } elseif (count($periodLabels) === 1) {
            $periodLabelText = $periodLabels[0];
        }

        $subjParts = ['План'];
        if ($supplierDisplay !== '') $subjParts[] = 'для ' . $supplierDisplay;
        if ($legalEntity !== '')     $subjParts[] = 'от ' . $legalEntity;
        if ($periodLabelText !== '') $subjParts[] = 'на ' . $periodLabelText;
        $subject = implode(' ', $subjParts);
        if (mb_strlen($subject) > 200) $subject = mb_substr($subject, 0, 200);

        require_once __DIR__ . '/../mail_send.php';

        // HTML письма — минимализм, аналогично заявке. Без брендинга.
        $esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
        $greetingLine = 'Здравствуйте!';

        $intro = 'Направляем прогнозный план поставок';
        if ($supplierDisplay !== '') $intro .= ' для <strong>' . $esc($supplierDisplay) . '</strong>';
        if ($legalEntity !== '')     $intro .= ' от <strong>' . $esc($legalEntity) . '</strong>';
        $intro .= '.';

        $periodsHtml = '';
        if (!empty($periodLabels)) {
            $periodsHtml = '<div style="margin-top:10px;">Периоды: <strong>'
                . $esc(implode(', ', $periodLabels)) . '</strong>.</div>';
        }

        $hasAttachment = !empty($body['attachment']) && is_array($body['attachment']);
        $attachLine = $hasAttachment
            ? '<div style="margin-top:18px;color:#4b5563;">Детали — во вложении (Excel).</div>'
            : '';

        // Если фронт прислал текст — добавляем как «комментарий от отправителя».
        $extraText = '';
        if ($bodyText !== '') {
            $extraText = '<div style="white-space:pre-wrap;margin-top:18px;font-size:14px;color:#1f2937;">'
                       . nl2br($esc($bodyText)) . '</div>';
        }

        $html =
            '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
          . '<body style="margin:0;padding:0;background:#ffffff;color:#1f2937;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;font-size:14px;line-height:1.55;">'
          . '<div style="padding:24px 28px;max-width:760px;font-size:14px;">'
          . '<div style="margin-bottom:6px;">' . $greetingLine . '</div>'
          . '<div>' . $intro . '</div>'
          . $periodsHtml
          . $extraText
          . $attachLine
          . '<div style="margin-top:22px;color:#1f2937;">Спасибо!</div>'
          . '</div>'
          . '</body></html>';

        $orderEmail = $_ENV['SMTP_ORDER_USER'] ?? 'order@supply-department.online';
        $senderEmail = '';
        $senderUserId = null;
        try {
            $eStmt = $pdo->prepare("SELECT id, email FROM users WHERE name = ? LIMIT 1");
            $eStmt->execute([$authUserName]);
            $senderRow = $eStmt->fetch();
            if ($senderRow) {
                $senderEmail  = trim((string)($senderRow['email'] ?? ''));
                $senderUserId = $senderRow['id'] ?? null;
            }
        } catch (Throwable $e) {}

        $parseEmails = function ($raw) {
            if ($raw === null || $raw === '') return [];
            $list = is_array($raw) ? $raw : preg_split('/[,;\s]+/', (string)$raw);
            $out = [];
            foreach ($list as $item) {
                $e = trim((string)$item);
                if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = $e;
            }
            return $out;
        };

        if (array_key_exists('cc', $body)) {
            $ccList = $parseEmails($body['cc']);
        } else {
            $ccList = [];
            if ($senderEmail !== '' && filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
                $ccList[] = $senderEmail;
            }
            foreach ($parseEmails($supplierCcRaw) as $e) $ccList[] = $e;
        }
        $toLower = array_map('strtolower', $recipients);
        $seen = array_flip($toLower);
        $ccFinal = [];
        foreach ($ccList as $e) {
            $key = strtolower($e);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $ccFinal[] = $e;
        }
        if (count($ccFinal) > 10) $ccFinal = array_slice($ccFinal, 0, 10);

        $opts = ['account' => 'order', 'reply_to' => $orderEmail];
        if (!empty($ccFinal)) $opts['cc'] = $ccFinal;

        if ($hasAttachment) {
            $att = $body['attachment'];
            $fname = trim((string)($att['filename'] ?? 'plan.xlsx'));
            $b64   = (string)($att['content_b64'] ?? '');
            $mime  = trim((string)($att['mime'] ?? '')) ?: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            $fname = preg_replace('/[^\p{L}\p{N}\.\-_ ]+/u', '_', $fname);
            if (mb_strlen($fname) > 120) $fname = mb_substr($fname, 0, 120);
            $decoded = base64_decode($b64, true);
            if ($decoded !== false && strlen($decoded) > 0 && strlen($decoded) <= 4 * 1024 * 1024) {
                $opts['attachments'] = [[
                    'filename'    => $fname,
                    'content_b64' => $b64,
                    'mime'        => $mime,
                ]];
            }
        }

        $sendResult = sendEmail($recipients, $subject, $html, true, $opts);

        try {
            $pdo->prepare("INSERT INTO plan_email_log (sender_user_id, sender_user_name, recipients, cc_recipients, subject, supplier, legal_entity, period_labels, items_count, success, error_message, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([
                    $senderUserId,
                    $authUserName,
                    implode(', ', $recipients),
                    !empty($ccFinal) ? implode(', ', $ccFinal) : null,
                    $subject,
                    $supplier !== '' ? $supplier : null,
                    $legalEntity !== '' ? $legalEntity : null,
                    !empty($periodLabels) ? mb_substr(implode(', ', $periodLabels), 0, 500) : null,
                    $itemsCount ?: null,
                    $sendResult['success'] ? 1 : 0,
                    $sendResult['success'] ? null : mb_substr((string)($sendResult['error'] ?? ''), 0, 500),
                    $clientIp ?? null,
                ]);
        } catch (Throwable $e) {
            error_log('[send_supplier_plan_email] log insert failed: ' . $e->getMessage());
        }

        if (!$sendResult['success']) {
            respond(['error' => 'Не удалось отправить письмо: ' . ($sendResult['error'] ?? 'неизвестная ошибка')], 500);
        }
        respond(['success' => true, 'sent_to' => $recipients, 'cc' => $ccFinal]);
    }

    if ($fn === 'create_download_token') {
        $filePath = trim((string)($body['file_path'] ?? ''));
        if ($filePath === '' || mb_strlen($filePath) > 512) respond(['error' => 'invalid file_path'], 400);
        if (strpos($filePath, '..') !== false || strpos($filePath, "\0") !== false) respond(['error' => 'invalid file_path'], 400);

        // Если staff-сессия есть — берём её.
        $issueAs = $authUserName ?: '';
        if (!$authUser) {
            // Ресторан тоже может получать токены, но только для путей uploads/bugs/*
            // (чтобы не дать ему доступ к чужим файлам через дыру в авторизации).
            if (strpos($filePath, 'uploads/bugs/') !== 0) {
                respond(['error' => 'Требуется авторизация'], 401);
            }
            if (!function_exists('roGetRestaurantSession')) {
                require_once __DIR__ . '/../restaurant_orders.php';
            }
            $roSess = function_exists('roGetRestaurantSession') ? roGetRestaurantSession($pdo) : null;
            if (!$roSess) respond(['error' => 'Требуется авторизация'], 401);
            $issueAs = 'ro:' . ($roSess['restaurant_number'] ?? '');
        }

        // Ленивая чистка устаревших токенов: вместо отдельного cron-а удаляем
        // протухшие записи при каждом запросе. Дешёвая операция (индекс по expires_at).
        try { $pdo->prepare("DELETE FROM download_tokens WHERE expires_at < NOW() - INTERVAL 1 DAY")->execute(); } catch (Throwable $e) {}
        $token = bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO download_tokens (token, user_name, file_path, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL 15 MINUTE)")
            ->execute([$token, $issueAs, $filePath]);
        respond(['token' => $token, 'expires_in' => 15 * 60]);
    }
