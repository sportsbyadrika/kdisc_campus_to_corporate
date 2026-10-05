<?php
/**
 * Router for PHP's built-in development server (mirrors the .htaccess rules):
 *   php -S localhost:8000 router.php
 */
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$root = __DIR__;

if (preg_match('#^/(app|database|node_modules)(/|$)#', $path) || preg_match('#/\.#', $path)
    || preg_match('#^/(router\.php|package(-lock)?\.json)$#', $path)) {
    http_response_code(403);
    require $root . '/404.php';
    return true;
}

// Hide .php: redirect /page.php -> /page
if (preg_match('#^(.*?)(?:/index)?\.php$#', $path, $m)) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . ($m[1] === '' ? '/' : $m[1]) . ($qs !== '' ? '?' . $qs : ''), true, 301);
    return true;
}

if ($path === '/' || $path === '') {
    require $root . '/index.php';
    return true;
}

$file = $root . $path;
if (is_file($file)) {
    if (str_starts_with($path, '/uploads/') && preg_match('#\.(php\d?|phtml|phar)$#i', $path)) {
        http_response_code(403);
        return true;
    }
    return false; // serve static asset as-is
}

$php = $root . rtrim($path, '/') . '.php';
if (is_file($php) && realpath($php) && str_starts_with(realpath($php), $root)) {
    $_SERVER['SCRIPT_NAME'] = $path;
    require $php;
    return true;
}

http_response_code(404);
require $root . '/404.php';
return true;
