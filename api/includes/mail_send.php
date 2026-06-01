<?php
/**
 * Отправка писем через SMTP (PHPMailer).
 *
 * Использование:
 *   require_once __DIR__ . '/mail_send.php';
 *   // обычное системное письмо (noreply@):
 *   sendEmail('user@example.com', 'Тема', '<p>HTML</p>');
 *   // письмо-заказ поставщику (order@):
 *   sendEmail('supplier@x.by', 'Заказ', '<p>...</p>', true, ['account' => 'order']);
 *
 * Аккаунты:
 *   default — noreply@ (SMTP_USER / SMTP_PASS / SMTP_FROM_NAME)
 *   order   — order@   (SMTP_ORDER_USER / SMTP_ORDER_PASS / SMTP_ORDER_FROM_NAME)
 * Host/port общие (SMTP_HOST / SMTP_PORT).
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

if (!function_exists('htmlEmailToPlainText')) {
    function htmlEmailToPlainText(string $html): string {
        $text = preg_replace('#<head[^>]*>.*?</head>#is', '', $html);
        $text = preg_replace('#<style[^>]*>.*?</style>#is', '', $text);
        // Скрытый preheader-блок (display:none) — не нужен в plain-варианте.
        $text = preg_replace('#<div[^>]*style="[^"]*display\s*:\s*none[^"]*"[^>]*>.*?</div>#is', '', $text);
        // Ссылку (включая кнопку-CTA) превращаем в «Текст: URL».
        $text = preg_replace_callback(
            '#<a\s[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is',
            function ($m) {
                $linkText = trim(strip_tags($m[2]));
                $url = trim($m[1]);
                if ($linkText === '' || strcasecmp($linkText, $url) === 0) return $url;
                return $linkText . ': ' . $url;
            },
            $text
        );
        // Блочные теги → перевод строки.
        $text = preg_replace('#</(p|div|h[1-6]|li|tr|table)\s*>#i', "\n", $text);
        $text = preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('#[ \t]+#', ' ', $text);
        $text = preg_replace('#[ \t]*\n[ \t]*#', "\n", $text);
        $text = preg_replace('#\n{3,}#', "\n\n", $text);
        return trim($text);
    }
}

if (!function_exists('sendEmail')) {
    /**
     * @param string|array $to       email или массив email-ов
     * @param string       $subject  тема письма
     * @param string       $body     тело (HTML или plain)
     * @param bool         $isHtml   HTML или plain text
     * @param array        $opts     {
     *   account?: 'default'|'order',
     *   reply_to?: string,            // переопределить Reply-To
     *   from_name?: string,           // переопределить отправителя
     *   cc?: string|array,            // адреса в копию
     *   attachments?: array,          // [{ filename, content_b64, mime? }, ...]
     * }
     * @return array{success: bool, error?: string}
     */
    function sendEmail($to, string $subject, string $body, bool $isHtml = true, array $opts = []): array {
        $account = $opts['account'] ?? 'default';

        $host = $_ENV['SMTP_HOST'] ?? '';
        $port = (int)($_ENV['SMTP_PORT'] ?? 465);
        if ($account === 'order') {
            $user = $_ENV['SMTP_ORDER_USER'] ?? '';
            $pass = $_ENV['SMTP_ORDER_PASS'] ?? '';
            $fromNameDefault = $_ENV['SMTP_ORDER_FROM_NAME'] ?? 'Supply Department';
        } elseif ($account === 'info') {
            // info@ — для системных уведомлений сторонним получателям
            // (охрана, поставщики и т.д.) от лица отдела закупок.
            $user = $_ENV['SMTP_INFO_USER'] ?? '';
            $pass = $_ENV['SMTP_INFO_PASS'] ?? '';
            $fromNameDefault = $_ENV['SMTP_INFO_FROM_NAME'] ?? 'Supply Department';
        } else {
            $user = $_ENV['SMTP_USER'] ?? '';
            $pass = $_ENV['SMTP_PASS'] ?? '';
            $fromNameDefault = $_ENV['SMTP_FROM_NAME'] ?? 'Supply Department';
        }
        if ($host === '' || $user === '' || $pass === '') {
            return ['success' => false, 'error' => 'SMTP не сконфигурирован для аккаунта ' . $account . ' (.env)'];
        }

        $fromName = $opts['from_name'] ?? $fromNameDefault;
        $replyTo  = $opts['reply_to'] ?? ($_ENV['SMTP_REPLY_TO'] ?? '');

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->Port       = $port;
            $mail->SMTPAuth   = true;
            $mail->Username   = $user;
            $mail->Password   = $pass;
            $mail->SMTPSecure = ($port === 465)
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->CharSet    = 'UTF-8';
            $mail->Timeout    = 15;

            $mail->setFrom($user, $fromName);
            if ($replyTo !== '') {
                $mail->addReplyTo($replyTo);
            }

            // PHPMailer по умолчанию ставит X-Mailer: PHPMailer X.Y.Z — некоторые
            // антиспам-фильтры это любят. Пробел отключает автодобавление.
            $mail->XMailer = ' ';

            // List-Unsubscribe + Auto-Submitted — только для дефолтного аккаунта
            // (системные уведомления вроде сброса пароля). Для order и info это
            // вредно: order — деловая переписка, info — уведомления получателям
            // (охрана и т.п.), которые MailCleaner и т.п. триггерят как рассылку
            // (MC_NEWS_HFRMNOREPLY / MC_NEWS_HLISTUNSUB1) и кладут в спам.
            if ($account !== 'order' && $account !== 'info') {
                $mail->addCustomHeader('List-Unsubscribe', '<mailto:' . $user . '?subject=unsubscribe>');
                $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
                $mail->addCustomHeader('Auto-Submitted', 'auto-generated');
            }

            $recipients = is_array($to) ? $to : [$to];
            foreach ($recipients as $addr) {
                $addr = trim((string)$addr);
                if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    $mail->addAddress($addr);
                }
            }
            if (empty($mail->getToAddresses())) {
                return ['success' => false, 'error' => 'Нет валидных получателей'];
            }

            // CC (копия) — если указано в опциях
            if (!empty($opts['cc'])) {
                $ccList = is_array($opts['cc']) ? $opts['cc'] : [$opts['cc']];
                foreach ($ccList as $ccAddr) {
                    $ccAddr = trim((string)$ccAddr);
                    if ($ccAddr !== '' && filter_var($ccAddr, FILTER_VALIDATE_EMAIL)) {
                        $mail->addCC($ccAddr);
                    }
                }
            }

            // Вложения — base64-строки в опциях.
            if (!empty($opts['attachments']) && is_array($opts['attachments'])) {
                foreach ($opts['attachments'] as $att) {
                    if (!is_array($att)) continue;
                    $fname = trim((string)($att['filename'] ?? ''));
                    $b64   = (string)($att['content_b64'] ?? '');
                    if ($fname === '' || $b64 === '') continue;
                    $decoded = base64_decode($b64, true);
                    if ($decoded === false) continue;
                    $mime = trim((string)($att['mime'] ?? '')) ?: 'application/octet-stream';
                    $mail->addStringAttachment($decoded, $fname, PHPMailer::ENCODING_BASE64, $mime);
                }
            }

            $mail->Subject = $subject;
            if ($isHtml) {
                $mail->isHTML(true);
                $mail->Body    = $body;
                $mail->AltBody = htmlEmailToPlainText($body);
            } else {
                $mail->Body = $body;
            }

            // Свой контролируемый Message-Id — нужен модулю «Заявка на пропуск»
            // для привязки входящих ответов через In-Reply-To. Если не задать,
            // PHPMailer сгенерит сам, но мы не сможем сохранить его до send().
            $msgHost = $_ENV['SMTP_HOST'] ?? 'supply-department.online';
            $mail->MessageID = '<' . bin2hex(random_bytes(12)) . '@' . preg_replace('/[^a-z0-9\.\-]/i', '', $msgHost) . '>';

            $mail->send();
            return ['success' => true, 'message_id' => $mail->MessageID];
        } catch (PHPMailerException $e) {
            $err = $mail->ErrorInfo ?: $e->getMessage();
            error_log('[mail_send] PHPMailer error (acc=' . $account . '): ' . $err);
            return ['success' => false, 'error' => $err];
        } catch (Throwable $e) {
            error_log('[mail_send] error (acc=' . $account . '): ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
