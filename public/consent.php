<?php
// GET — текст согласия; POST (sendBeacon из cookie-баннера, text/plain) — счётчик выбора в consent_stats.
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/stats.php';
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // только со своей страницы: Origin = свой хост или Sec-Fetch-Site: same-origin
    $o = $_SERVER['HTTP_ORIGIN'] ?? '';
    $oHost = parse_url($o, PHP_URL_HOST) . (($port = parse_url($o, PHP_URL_PORT)) ? ":$port" : '');
    $own = ($o !== '' && $oHost === ($_SERVER['HTTP_HOST'] ?? '')) || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'same-origin';
    if (!$own) { http_response_code(403); exit; }
    parse_str((string)file_get_contents('php://input', false, null, 0, 256), $p);
    $c = $p['c'] ?? '';
    $rev = $p['rev'] ?? '';
    if (!in_array($c, ['accept', 'reject'], true) || !is_string($rev) || !preg_match('/^[0-9A-Za-z.\-]{1,32}$/D', $rev)) {
        http_response_code(400);
        exit;
    }
    // ponytail: без лимита — счётчик агрегатный, накрутка искажает только статистику согласий
    try {
        db()->prepare('INSERT INTO consent_stats (day, choice, text_rev, n) VALUES (?, ?, ?, 1)
            ON CONFLICT (day, choice, text_rev) DO UPDATE SET n = n + 1')->execute([gmdate('Y-m-d'), $c, $rev]);
    } catch (Throwable $e) {
        error_log('consent.php: ' . $e->getMessage());
        http_response_code(500);
        exit;
    }
    http_response_code(204);
    exit;
}
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}
$s = settings();
hit('/consent.php');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Robots-Tag: noindex');
require dirname(__DIR__) . '/app/tpl/consent.php';
