<?php
/**
 * Отправка писем через SMTP (PHPMailer).
 *
 * Использование:
 *   require_once __DIR__ . '/mail_send.php';
 *   $r = sendEmail('user@example.com', 'Тема', '<p>HTML-тело</p>');
 *   if (!$r['success']) error_log($r['error']);
 *
 * Конфиг берётся из $_ENV (см. /var/www/bk-calc-secrets/.env):
 *   SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_FROM_NAME, SMTP_REPLY_TO (опц.)
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
     * @return array{success: bool, error?: string}
     */
    function sendEmail($to, string $subject, string $body, bool $isHtml = true): array {
        $host = $_ENV['SMTP_HOST'] ?? '';
        $user = $_ENV['SMTP_USER'] ?? '';
        $pass = $_ENV['SMTP_PASS'] ?? '';
        if ($host === '' || $user === '' || $pass === '') {
            return ['success' => false, 'error' => 'SMTP не сконфигурирован (.env)'];
        }

        $port     = (int)($_ENV['SMTP_PORT'] ?? 465);
        $fromName = $_ENV['SMTP_FROM_NAME'] ?? 'Supply Department';
        $replyTo  = $_ENV['SMTP_REPLY_TO'] ?? '';

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
            error_log('[mail_send] PHPMailer error: ' . $err);
            return ['success' => false, 'error' => $err];
        } catch (Throwable $e) {
            error_log('[mail_send] error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
