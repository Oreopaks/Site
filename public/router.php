<?php
// Роутер только для `php -S 127.0.0.1:8080 -t public public/router.php` (dev/тесты; .htaccess там не работает).
$p = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
if ($p === '/sitemap.xml' && is_file(__DIR__ . '/sitemap.php')) {
    require __DIR__ . '/sitemap.php';
    return true;
}
$hidden = str_contains($p, '/.') || str_contains($p, '..') || $p === '/router.php';
if (!$hidden && ($p === '/' || is_file(__DIR__ . $p) || is_file(__DIR__ . rtrim($p, '/') . '/index.php'))) {
    return false; // отдаёт встроенный сервер (статику или .php)
}
http_response_code(404);
if (is_file(__DIR__ . '/404.php')) require __DIR__ . '/404.php';
else echo 'Not Found';
return true;
