<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$GLOBALS['config'] = require __DIR__ . '/config.php';

date_default_timezone_set(config('timezone'));

if (config('debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/metrics.php';
require __DIR__ . '/charts.php';

function config(string $key, mixed $default = null): mixed
{
    return $GLOBALS['config'][$key] ?? $default;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            config('db_host'), config('db_port'), config('db_name'));
        try {
            $pdo = new PDO($dsn, config('db_user'), config('db_pass'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('DB connection failed: ' . $e->getMessage());
            exit(config('debug') ? 'Database connection failed: ' . e($e->getMessage())
                : 'The service is temporarily unavailable. Please try again later.');
        }
    }
    return $pdo;
}

if (PHP_SAPI !== 'cli') {
    session_name(config('session_name'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => (config('base_path') ?: '') . '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}
