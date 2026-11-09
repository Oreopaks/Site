<?php
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/stats.php';

$s = settings();
// Маршрут по пути (ADR-0009 §4): / и /index.php → главная; /{slug}/ опубликованной → посадочная; /{slug} → 301; прочее → 404
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$pg = null;
if ($path !== '/' && $path !== '/index.php') {
    $pg = preg_match('~^/([a-z0-9-]+)(/?)$~D', $path, $m) ? landing($m[1]) : null;
    if ($pg === null) { require __DIR__ . '/404.php'; exit; }
    if ($m[2] === '') { header('Location: /' . $pg['slug'] . '/', true, 301); exit; }
}
$items = fn(string $kind) => rows('SELECT * FROM items WHERE kind = ? ORDER BY sort, id', [$kind]);
$nav = $items('nav');
$marquee = $items('marquee');
$statHero = $items('stat_hero');
$statBar = $items('stat_bar');
$why = $items('why');
$steps = $items('step');
$services = rows('SELECT * FROM services ORDER BY sort, id');
$fleet = rows('SELECT * FROM fleet_cards ORDER BY sort, id');
$prices = rows('SELECT * FROM fleet_prices ORDER BY sort, id');
$materials = rows('SELECT * FROM materials ORDER BY sort, id');

hit($pg ? '/' . $pg['slug'] . '/' : '/');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: frame-ancestors 'self'"); // чужой сайт не встроит страницу в iframe (кликджекинг формы); карте кликов Метрики понадобится её домен здесь
ob_start();
require dirname(__DIR__) . '/app/tpl/home.php';
$html = ob_get_clean();
// T33: страница — функция БД без поштучных токенов → ETag по телу; no-cache = кэшировать, но сверяться. Last-Modified нет: честной даты нет (ADR-0009)
$etag = md5($html);
header('Cache-Control: no-cache');
header("ETag: \"$etag\"");
// If-None-Match сравнивается слабо (RFC 9110 §13.1.2): W/ от gzip nginx и суффикс -gzip от mod_deflate — тот же тег
if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) && preg_match_all('~"([0-9a-f]{32})(?:-[a-z]+)?"~', $_SERVER['HTTP_IF_NONE_MATCH'] ?? '', $m) && in_array($etag, $m[1], true)) {
    http_response_code(304);
    exit;
}
echo $html;
