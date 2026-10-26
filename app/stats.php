<?php
// §7: своя статистика — только агрегаты (день, страница, домен-источник, класс устройства).
// Не пишем IP, UA целиком, cookie; уников нет («хиты, не люди»).
declare(strict_types=1);

function hit(string $page): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') return; // HEAD/POST не считаем
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $class = match (true) {
        $ua === '' => 'other',
        (bool)preg_match('/bot|crawl|spider|yandex|google/i', $ua) => 'bot',
        (bool)preg_match('/Mobile|Android|iPhone/i', $ua) => 'mobile',
        default => 'desktop',
    };
    $ref = strtolower((string)parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST));
    $own = strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    // ponytail: свой = текущий Host; www./без www. считаются разными доменами
    if ($ref === $own || !preg_match('/^[a-z0-9.-]{1,253}$/D', $ref)) $ref = '';
    try {
        db()->prepare('INSERT INTO stats (day, page, ref_domain, ua_class, hits) VALUES (?, ?, ?, ?, 1)
            ON CONFLICT(day, page, ref_domain, ua_class) DO UPDATE SET hits = hits + 1')
            ->execute([gmdate('Y-m-d'), $page, $ref, $class]);
    } catch (Throwable $e) {
        error_log('hit: ' . $e->getMessage()); // статистика не должна ронять страницу
    }
}
