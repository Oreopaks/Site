<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__DIR__) . '/var/app.log');
ini_set('zend.exception_ignore_args', '1'); // аргументы (пароли) не попадают в трассировки в логе

require __DIR__ . '/db.php';

// HTML-текст и атрибуты
function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Литерал JS-строки ('...') внутри inline <script>: \ ' " < > & CR LF U+2028/2029 → \xNN/\uNNNN
function jsstr($v): string
{
    return preg_replace_callback('/[\\\\\'"<>&\r\n]|\x{2028}|\x{2029}/u', function ($m) {
        $c = $m[0];
        return strlen($c) === 1 ? sprintf('\\x%02x', ord($c)) : ($c === "\u{2028}" ? '\\u2028' : '\\u2029');
    }, (string)$v);
}

// JS-строка внутри HTML-атрибута (onclick="f('…')")
function jsattr($v): string
{
    return e(jsstr($v));
}

function settings(): array
{
    static $s = null;
    return $s ??= db()->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Пустое поле → подстановка по умолчанию, а не выдуманные данные
function stub(array $s, string $key, string $placeholder): string
{
    return trim($s[$key] ?? '') === '' ? $placeholder : $s[$key];
}

// ИНН и ОГРН для вывода — только заполненные: «ИНН{$sep}… · ОГРН{$sep}…» или '' (пустое поле — ни подписи, ни заглушки)
function ids(array $s, string $sep): string
{
    return implode(' · ', array_filter([
        trim($s['org_inn'] ?? '') === '' ? '' : 'ИНН' . $sep . trim($s['org_inn']),
        trim($s['org_ogrn'] ?? '') === '' ? '' : 'ОГРН' . $sep . trim($s['org_ogrn']),
    ]));
}

// T25: ' width="…" height="…"' по натуральному размеру картинки сайта (src от webroot, assets/…); нет файла или не растр (svg) — ''
function imgwh(string $src): string
{
    static $c = []; // ponytail: кэш на запрос — логотип трижды на странице; между запросами getimagesize читает только заголовок файла
    return $c[$src] ??= preg_match('~^assets/[a-z0-9_/-]+\.[a-z]+$~D', $src) && is_file($f = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/' . $src)
        && ($i = @getimagesize($f)) ? ' width="' . $i[0] . '" height="' . $i[1] . '"' : '';
}

// Строки списка по порядку
function rows(string $sql, array $args = []): array
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

// 28000 → «28 000 ₽», from → «от 3 500 ₽»
function price(string $kind, $price): string
{
    return ($kind === 'from' ? 'от ' : '') . number_format((int)$price, 0, '', ' ') . ' ₽';
}

// Посадочные (ADR-0009): id → slug, короткое имя (крошка и serviceType), секции главной на странице,
// поля анкеты, без которых страница не публикуется, и FAQ: вопрос → fact_* (ответ — только текст владельца, пустой — вопрос скрыт).
// У rent нет 'steps': этапы описывают стройку, не аренду (strategy Р3.4)
const FAQ_ZONE = ['Куда вы выезжаете и есть ли доплата за расстояние?' => 'fact_zone'];
const FAQ_SITE = [
    'Выезд на замер и смета — бесплатно?' => 'fact_measure',
    'Какая гарантия на работы?' => 'fact_warranty',
];
const PAGES = [
    'rent' => ['slug' => 'arenda-spetstekhniki', 'name' => 'Аренда спецтехники', 'blocks' => ['fleet', 'services'], 'need' => [],
        'faq' => ['Сколько часов в смене и что входит в аренду?' => 'fact_rent_shift', 'Как доставляется техника на объект и сколько это стоит?' => 'fact_rent_delivery'] + FAQ_ZONE],
    'mat' => ['slug' => 'shcheben-pesok-grunt', 'name' => 'Щебень, песок, грунт', 'blocks' => ['materials', 'services'], 'need' => [],
        'faq' => ['Цены на материалы указаны с НДС?' => 'fact_mat_vat', 'Сколько стоит доставка материалов?' => 'fact_mat_delivery', 'Какой минимальный заказ материалов?' => 'fact_mat_min'] + FAQ_ZONE],
    'asf' => ['slug' => 'asfaltirovanie', 'name' => 'Асфальтирование', 'blocks' => ['steps', 'services'], 'need' => ['fact_asphalt_works'],
        'faq' => ['Сколько стоит асфальтирование?' => 'fact_asphalt_price', 'Какая минимальная площадь асфальтирования?' => 'fact_asphalt_min'] + FAQ_SITE + FAQ_ZONE],
    'blago' => ['slug' => 'blagoustroystvo', 'name' => 'Благоустройство', 'blocks' => ['steps', 'services'], 'need' => ['fact_blago_works'],
        'faq' => FAQ_SITE + FAQ_ZONE],
];

// Пустые обязательные ключи страницы; [] → страница опубликована
function page_gaps(array $s, string $id): array
{
    return array_values(array_filter(["pg_{$id}_title", "pg_{$id}_desc", "pg_{$id}_h1", ...PAGES[$id]['need']], fn($k) => trim($s[$k] ?? '') === ''));
}

// Опубликованная посадочная по slug → PAGES[id] + id; неизвестная или неопубликованная → null
function landing(string $slug): ?array
{
    foreach (PAGES as $id => $p) if ($p['slug'] === $slug) return page_gaps(settings(), $id) ? null : $p + ['id' => $id];
    return null;
}

// D5: белый список href (якорь, своя .php, /страница/, http(s), tel, mailto); иначе сохранение → 422
function valid_href(string $h): bool
{
    return (bool)preg_match('~^(#[a-z0-9_-]+|[a-z0-9_-]+\.php|/[a-z0-9-]+/|https?://\S+|tel:\+?[0-9]+|mailto:\S+)$~D', $h);
}
