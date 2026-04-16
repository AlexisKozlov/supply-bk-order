<?php
// ═══ Чат ресторанов с отделом закупок ═══

// Шаг 1: выбор ресторана
function chatStart($chatId, $msgId) {
    global $pdo;
    @unlink(sys_get_temp_dir() . "/chat_{$chatId}.txt");

    $subs = botGetSubscribedRestaurants($pdo, $chatId);

    if (!$subs) {
        editMessage($chatId, $msgId, "💬 Сначала подпишитесь на ресторан.", ['inline_keyboard' => [
            [['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']],
        ]]);
        return;
    }

    if (count($subs) === 1) {
        chatInputMode($chatId, $msgId, $subs[0]['restaurant_number']);
        return;
    }

    $btns = [];
    foreach ($subs as $sub) {
        $addr = mb_substr($sub['address'] ?: $sub['city'], 0, 35);
        $label = botFormatSubscribedRestaurant($sub['restaurant_number'], $sub['legal_entity_group']);
        $btns[] = [['text' => "🏪 {$label} — {$addr}", 'callback_data' => "chat_rest_{$sub['restaurant_number']}"]];
    }
    $btns[] = [['text' => '◂ Назад', 'callback_data' => 'veg_my_subs']];
    editMessage($chatId, $msgId, "💬 <b>Написать в закупки</b>\n\nВыберите ресторан:", ['inline_keyboard' => $btns]);
}

// Шаг 2: режим ввода
function chatInputMode($chatId, $msgId, $restNum) {
    file_put_contents(sys_get_temp_dir() . "/chat_{$chatId}.txt", $restNum);

    $text = "💬 <b>Сообщение в отдел закупок</b>\n";
    $text .= "🏪 Ресторан <b>{$restNum}</b>\n";
    $text .= "─────────────────────\n";
    $text .= "Напишите сообщение. Можно отправить текст или фото.\n";
    $text .= "Каждое сообщение будет доставлено.";

    editMessage($chatId, $msgId, $text, ['inline_keyboard' => [
        [['text' => '◂ Выход', 'callback_data' => 'chat_cancel']],
    ]]);
}

// Обработка сообщения от ресторана
function chatProcessMessage($chatId, $text, $restNum, $userMsgId, $from, $photoFileId = null) {
    global $pdo, $BOT_TOKEN;

    if (!$text && !$photoFileId) return;

    // Имя отправителя
    $senderName = $from['first_name'] ?? '';
    if (!empty($from['username'])) $senderName = $senderName ?: ('@' . $from['username']);
    $senderName = $senderName ?: 'Ресторан';

    // Найти или создать открытый диалог
    $st = $pdo->prepare("SELECT id FROM chat_conversations WHERE restaurant_chat_id = ? AND restaurant_number = ? AND status = 'open' LIMIT 1");
    $st->execute([$chatId, $restNum]);
    $convId = $st->fetchColumn();

    if (!$convId) {
        // Определяем группу юрлиц ресторана — через таблицу restaurants.
        // Если записи нет (чат с неизвестным номером) — fallback по значению
        // номера: PS-рестораны живут в диапазоне 1000+.
        $restGroup = botGetRestaurantGroupByNumber($pdo, $restNum);

        $ins = $pdo->prepare("INSERT INTO chat_conversations (restaurant_number, restaurant_chat_id, restaurant_name, legal_entity_group, last_message_at) VALUES (?, ?, ?, ?, NOW())");
        $ins->execute([$restNum, $chatId, $senderName, $restGroup]);
        $convId = $pdo->lastInsertId();
    } else {
        $pdo->prepare("UPDATE chat_conversations SET last_message_at = NOW(), restaurant_name = ? WHERE id = ?")->execute([$senderName, $convId]);
    }

    // Сохраняем сообщение
    $ins = $pdo->prepare("INSERT INTO chat_messages (conversation_id, direction, sender_name, message_text, photo_file_id) VALUES (?, 'from_restaurant', ?, ?, ?)");
    $ins->execute([$convId, $senderName, $text ?: null, $photoFileId]);

    // Проверяем рабочее время (Москва, Пн-Пт 9:00-18:00)
    $tz = new DateTimeZone('Europe/Moscow');
    $now = new DateTime('now', $tz);
    $dow = (int)$now->format('N');
    $hour = (int)$now->format('H');
    $isWorkTime = ($dow <= 5 && $hour >= 9 && $hour < 18);

    $confirmText = "✅ Сообщение отправлено в отдел закупок.";
    if (!$isWorkTime) {
        $confirmText .= "\n\n⏰ <i>Сейчас нерабочее время (Пн-Пт 9:00-18:00).\nВаше сообщение будет обработано в ближайший рабочий день.</i>";
    }

    sendMessage($chatId, $confirmText, ['inline_keyboard' => [
        [['text' => '📜 История сообщений', 'callback_data' => "chat_history_{$restNum}"]],
        [['text' => '◂ Выход', 'callback_data' => 'chat_cancel']],
    ]]);

    // Уведомляем закупщиков
    chatNotifyPurchasers($pdo, $restNum, $senderName, $text ?: '📷 Фото');
}

// История чата для ресторана
function chatShowHistory($chatId, $msgId, $restNum) {
    global $pdo;

    $st = $pdo->prepare("SELECT id FROM chat_conversations WHERE restaurant_chat_id = ? AND restaurant_number = ? ORDER BY last_message_at DESC LIMIT 1");
    $st->execute([$chatId, $restNum]);
    $convId = $st->fetchColumn();

    if (!$convId) {
        editMessage($chatId, $msgId, "💬 <b>Ресторан {$restNum}</b>\n\nНет сообщений.", ['inline_keyboard' => [
            [['text' => '◂ Назад', 'callback_data' => 'chat_start']],
        ]]);
        return;
    }

    $st = $pdo->prepare("SELECT * FROM chat_messages WHERE conversation_id = ? ORDER BY created_at DESC LIMIT 20");
    $st->execute([$convId]);
    $msgs = array_reverse($st->fetchAll());

    $text = "💬 <b>Переписка: ресторан {$restNum}</b>\n";
    $text .= "─────────────────────\n\n";

    if (!$msgs) {
        $text .= "<i>Нет сообщений.</i>";
    } else {
        foreach ($msgs as $m) {
            $time = date('d.m H:i', strtotime($m['created_at']));
            if ($m['direction'] === 'from_restaurant') {
                $text .= "📤 <b>{$m['sender_name']}</b> <i>{$time}</i>\n";
            } else {
                $text .= "📨 <b>{$m['sender_name']}</b> <i>{$time}</i>\n";
            }
            if ($m['message_text']) $text .= "{$m['message_text']}\n";
            if ($m['photo_file_id']) $text .= "📷 Фото\n";
            $text .= "\n";
        }
    }

    if (mb_strlen($text) > 4000) $text = mb_substr($text, 0, 3990) . "\n…";

    editMessage($chatId, $msgId, $text, ['inline_keyboard' => [
        [['text' => '◂ Назад', 'callback_data' => 'chat_start']],
    ]]);
}

// Уведомление закупщикам
function chatNotifyPurchasers($pdo, $restNum, $senderName, $preview) {
    global $BOT_TOKEN;
    $st = $pdo->query("SELECT u.telegram_chat_id FROM telegram_settings ts JOIN users u ON u.name = ts.user_name WHERE ts.chat_notifications = 1 AND u.telegram_chat_id IS NOT NULL");
    $recipients = $st->fetchAll();
    if (!$recipients) return;

    $preview = mb_substr($preview, 0, 200);
    $text = "💬 Сообщение от ресторана <b>{$restNum}</b> ({$senderName}):\n{$preview}";

    foreach ($recipients as $r) {
        $payload = json_encode(['chat_id' => $r['telegram_chat_id'], 'text' => $text, 'parse_mode' => 'HTML']);
        $ch = curl_init("https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 5]);
        curl_exec($ch); curl_close($ch);
    }
}
