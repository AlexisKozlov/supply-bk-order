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

            $mail->Subject = $subject;
            if ($isHtml) {
                $mail->isHTML(true);
                $mail->Body    = $body;
                $mail->AltBody = trim(strip_tags(preg_replace('#<br\s*/?>#i', "\n", $body)));
            } else {
                $mail->Body = $body;
            }

            $mail->send();
            return ['success' => true];
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
