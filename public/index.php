<?php
require dirname(__DIR__) . '/app/bootstrap.php';

$s = settings();
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

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
require dirname(__DIR__) . '/app/tpl/home.php';
