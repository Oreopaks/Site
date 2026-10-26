<?php
// M2: только GET. POST (фиксация выбора cookie-баннера) — в M6.
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/stats.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}
$s = settings();
hit('/consent.php');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Robots-Tag: noindex');
require dirname(__DIR__) . '/app/tpl/consent.php';
