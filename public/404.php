<?php
// Apache ErrorDocument / nginx error_page / router.php. Без БД: страница статична.
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
require dirname(__DIR__) . '/app/tpl/404.php';
