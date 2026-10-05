<?php
/**
 * Application configuration.
 *
 * Values are read, in order of precedence, from:
 *   1. app/config.local.php (optional, returns an array with the keys below)
 *   2. real environment variables
 *   3. the .env file in the project root (see .env.example)
 *   4. the defaults below
 */

/** Load KEY=VALUE pairs from a .env file without overriding real environment variables. */
function load_env_file(string $file): void
{
    if (!is_file($file) || !is_readable($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (str_starts_with($key, 'export ')) {
            $key = trim(substr($key, 7));
        }
        if (!preg_match('/^[A-Z0-9_]+$/i', $key) || getenv($key) !== false) {
            continue;
        }
        if (preg_match('/^(["\'])(.*)\1(?:\s+#.*)?$/', $value, $m)) {
            $value = $m[2]; // quoted value, optionally followed by a comment
        } elseif (($hash = strpos($value, ' #')) !== false) {
            $value = rtrim(substr($value, 0, $hash)); // inline comment
        }
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
    }
}

load_env_file(getenv('C2C_ENV_FILE') ?: dirname(__DIR__) . '/.env');

$env = fn(string $key, string $default = '') => ($v = getenv($key)) !== false && $v !== '' ? $v : $default;

$config = [
    'db_host'     => $env('C2C_DB_HOST', '127.0.0.1'),
    'db_port'     => (int) $env('C2C_DB_PORT', '3306'),
    'db_name'     => $env('C2C_DB_NAME', 'c2c_portal'),
    'db_user'     => $env('C2C_DB_USER', 'c2c'),
    'db_pass'     => $env('C2C_DB_PASS'),
    // URL path the app is served from, without trailing slash ('' when at the domain root, '/c2c' for a sub-folder)
    'base_path'   => rtrim($env('C2C_BASE_PATH'), '/'),
    'debug'       => filter_var($env('C2C_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
    'timezone'    => $env('C2C_TIMEZONE', 'Asia/Kolkata'),
    'upload_max_bytes' => (int) $env('C2C_UPLOAD_MAX_MB', '2') * 1024 * 1024,
    'session_name'     => $env('C2C_SESSION_NAME', 'c2c_session'),
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_merge($config, (array) require $local);
}

return $config;
