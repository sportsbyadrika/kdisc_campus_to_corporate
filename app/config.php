<?php
/**
 * Application configuration.
 * Values can be overridden with environment variables or by creating
 * app/config.local.php that returns an array with the same keys.
 */
$config = [
    'db_host'     => getenv('C2C_DB_HOST') ?: '127.0.0.1',
    'db_port'     => (int) (getenv('C2C_DB_PORT') ?: 3306),
    'db_name'     => getenv('C2C_DB_NAME') ?: 'c2c_portal',
    'db_user'     => getenv('C2C_DB_USER') ?: 'c2c',
    'db_pass'     => getenv('C2C_DB_PASS') ?: '',
    // URL path the app is served from, without trailing slash ('' when at the domain root, '/c2c' for a sub-folder)
    'base_path'   => getenv('C2C_BASE_PATH') ?: '',
    'debug'       => (bool) (getenv('C2C_DEBUG') ?: false),
    'timezone'    => 'Asia/Kolkata',
    'upload_max_bytes' => 2 * 1024 * 1024,
    'session_name'     => 'c2c_session',
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_merge($config, (array) require $local);
}

return $config;
