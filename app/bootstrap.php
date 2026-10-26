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

// Обязательное поле без значения → видимая заглушка, а не выдуманные данные
function stub(array $s, string $key, string $placeholder): string
{
    return trim($s[$key] ?? '') === '' ? $placeholder : $s[$key];
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

// D5: белый список href (якорь, своя .php, http(s), tel, mailto); иначе сохранение → 422
function valid_href(string $h): bool
{
    return (bool)preg_match('~^(#[a-z0-9_-]+|[a-z0-9_-]+\.php|https?://\S+|tel:\+?[0-9]+|mailto:\S+)$~D', $h);
}
