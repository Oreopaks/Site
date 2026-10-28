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
require dirname(__DIR__) . '/app/tpl/home.php';
