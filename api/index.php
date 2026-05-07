<?php
/**
 * API роутер. Подключает модули из includes/.
 *
 * Структура:
 *   includes/helpers.php  — утилиты, авторизация, RBAC, фильтры
 *   includes/uploads.php  — загрузка/скачивание файлов
 *   includes/search.php   — поиск товаров
 *   includes/rpc.php      — RPC-эндпоинты (публичные + приватные)
 *   includes/crud.php     — REST CRUD для таблиц
 */

header('Content-Type: application/json; charset=utf-8');
$allowed_origin = getenv('CORS_ORIGIN') ?: ($_ENV['CORS_ORIGIN'] ?? '');
if ($allowed_origin) {
    header("Access-Control-Allow-Origin: $allowed_origin");
} else {
    // Fallback: разрешаем Origin только если он совпадает с Host (same-origin)
    // Добавляем проверку scheme для безопасности
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($origin && $host) {
        $parsed = parse_url($origin);
        $originHost = $parsed['host'] ?? '';
        $originScheme = $parsed['scheme'] ?? '';
        if ($originHost === $host && $originScheme === 'https') {
            header("Access-Control-Allow-Origin: $origin");
        }
    }
}
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-Session-Token');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// Load .env
$envFile = '/var/www/bk-calc-secrets/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($k, $v) = explode('=', $line, 2);
            $_ENV[trim($k)] = trim($v);
        }
    }
}

$DB_HOST = $_ENV['DB_HOST'] ?? 'localhost';
$DB_NAME = $_ENV['DB_NAME'] ?? 'supply_bk';
$DB_USER = $_ENV['DB_USER'] ?? 'siteuser';
$DB_PASS = $_ENV['DB_PASS'] ?? '';

try {
    $pdoOpts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ];
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, $pdoOpts);
    // Ограничиваем время выполнения запросов на стороне MySQL (30 сек)
    $pdo->exec("SET SESSION max_statement_time = 30");
} catch (PDOException $e) {
    http_response_code(500);
    error_log('DB connection error: ' . $e->getMessage());
    echo json_encode(['error' => 'Ошибка подключения к базе данных']);
    exit;
}

// Утилиты, авторизация, RBAC, фильтры
require_once __DIR__ . '/includes/helpers.php';

// Разбор URI
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = preg_replace('#^/api/#', '', $uri);
$uri = trim($uri, '/');
$parts = explode('/', $uri);
$endpoint = $parts[0] ?? '';
$subpoint = isset($parts[1]) ? urldecode($parts[1]) : null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$rawInput = file_get_contents('php://input');
$maxBodySize = 50 * 1024 * 1024; // 50 MB
if (strlen($rawInput) > $maxBodySize) {
    http_response_code(413);
    echo json_encode(['error' => 'Слишком большой запрос (макс. 50 МБ)']);
    exit;
}
$body = json_decode($rawInput, true) ?? [];
unset($rawInput);

// Загрузка/скачивание файлов
require_once __DIR__ . '/includes/uploads.php';

// Поиск товаров
require_once __DIR__ . '/includes/search.php';

// RPC-эндпоинты
require_once __DIR__ . '/includes/rpc.php';

// Заказы ресторанов (временный модуль, своя авторизация)
require_once __DIR__ . '/includes/restaurant_orders.php';

// Заявки поставщикам (универсальный модуль)
require_once __DIR__ . '/includes/supplier_orders.php';

// Загрузка машин
require_once __DIR__ . '/includes/truck_loading.php';

// Задачи (личные канбан-доски сотрудников)
require_once __DIR__ . '/includes/tasks.php';

// Возврат кег
require_once __DIR__ . '/includes/keg_returns.php';

// OCR (распознавание скриншотов)
require_once __DIR__ . '/includes/ocr.php';

// Авторизация для REST
if (!checkAuth($pdo)) { respond(['error'=>'Unauthorized'], 401); }

// REST CRUD
require_once __DIR__ . '/includes/crud.php';
