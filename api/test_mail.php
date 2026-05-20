<?php
/**
 * Тестовый CLI-скрипт для проверки SMTP.
 *
 * Запуск:
 *   php api/test_mail.php your-email@example.com
 *
 * После того как убедимся, что SMTP работает — этот файл можно удалить.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Только CLI\n");
}

// Загрузка .env (тот же парсер, что в api/index.php)
$envFile = '/var/www/bk-calc-secrets/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($k, $v) = explode('=', $line, 2);
            $_ENV[trim($k)] = trim($v);
        }
    }
} else {
    echo "✗ Файл $envFile не найден\n";
    exit(1);
}

require_once __DIR__ . '/includes/mail_send.php';

$to = $argv[1] ?? null;
if (!$to) {
    echo "Использование: php api/test_mail.php <получатель@email>\n";
    exit(1);
}

echo "== Конфиг SMTP ==\n";
echo "  SMTP_HOST=" . ($_ENV['SMTP_HOST'] ?? '(не задан)') . "\n";
echo "  SMTP_PORT=" . ($_ENV['SMTP_PORT'] ?? '(не задан)') . "\n";
echo "  SMTP_USER=" . ($_ENV['SMTP_USER'] ?? '(не задан)') . "\n";
echo "  SMTP_PASS=" . (empty($_ENV['SMTP_PASS']) ? '(не задан) ✗' : '*** (задан)') . "\n";
echo "  SMTP_FROM_NAME=" . ($_ENV['SMTP_FROM_NAME'] ?? '(не задан)') . "\n";
echo "\n== Отправка тестового письма на $to ==\n";

$result = sendEmail(
    $to,
    'Тест почты Supply Department — ' . date('H:i:s'),
    '<p>Это <strong>тестовое письмо</strong> от системы Supply Department.</p>' .
    '<p>Если ты его читаешь — SMTP настроен и работает.</p>' .
    '<p>Время отправки: ' . date('Y-m-d H:i:s') . '</p>' .
    '<hr><p style="color:#999;font-size:12px">Это автоматическое сообщение, отвечать не нужно.</p>',
    true
);

if ($result['success']) {
    echo "✓ Письмо отправлено успешно\n";
    exit(0);
} else {
    echo "✗ Ошибка: " . $result['error'] . "\n";
    exit(1);
}
