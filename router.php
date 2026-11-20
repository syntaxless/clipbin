<?php
// Local development only: php -S localhost:8080 router.php
// Mirrors the rewrite rules in .htaccess.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/(config\.php|config\.sample\.php|router\.php|lib/|data/|\.git)#', $path)) {
    http_response_code(403);
    exit('Forbidden');
}
if (preg_match('#^/e/([A-Za-z0-9]{6,12})/?$#', $path, $m)) {
    $_GET['id'] = $m[1];
    $_SERVER['SCRIPT_NAME'] = '/embed.php';
    require __DIR__ . '/embed.php';
    return true;
}
if (preg_match('#^/oembed/?$#', $path)) {
    $_SERVER['SCRIPT_NAME'] = '/oembed.php';
    require __DIR__ . '/oembed.php';
    return true;
}
if (preg_match('#^/([A-Za-z0-9]{6,12})/?$#', $path, $m) && !is_file(__DIR__ . $path)) {
    $_GET['id'] = $m[1];
    $_SERVER['SCRIPT_NAME'] = '/watch.php';
    require __DIR__ . '/watch.php';
    return true;
}
if (str_ends_with($path, '.mjs')) {
    header('Content-Type: text/javascript');
    readfile(__DIR__ . $path);
    return true;
}
return false;
