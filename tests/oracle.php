<?php
// Оракул DOM: `/` на сид-данных vs эталон `git show static-final:index.html`.
// Запуск: стенд `php -S 127.0.0.1:8080 -t public public/router.php` (RDS_DB → чистая БД), затем `php tests/oracle.php`.
// Белый список (§8): P1/P2 — последний inline <script> в <body>; P3 — label с галочкой + honeypot/hidden source в <form>;
// P6 — <footer>; P7 — 3-я плашка в data-r=stats (наличие); решение заказчика — option «Благоустройство территории» → «Благоустройство».

if (!class_exists('DOMDocument')) { fwrite(STDERR, "BLOCKED: нет ext-dom (нужен только для тестов)\n"); exit(2); }

$root = dirname(__DIR__);
$url = getenv('ORACLE_URL') ?: 'http://127.0.0.1:8080/';
$expected = shell_exec('git -C ' . escapeshellarg($root) . ' show static-final:index.html');
$actual = @file_get_contents($url);
if (!$expected || !$actual) { fwrite(STDERR, "FAIL: нет эталона или ответа $url\n"); exit(1); }
// решения заказчика 2026-10-27 — точечные правки эталона; каждая обязана найтись ровно один раз
$tag = '<div style="font-size:10.5px;letter-spacing:3px;color:#FFC700;font-weight:600">';
$sany = "Sany SY245H</span></dt>\n            <dd style=\"margin:0;white-space:nowrap\">";
foreach ([
    // карточки услуг не перекрашиваются в чёрный при наведении/нажатии
    "  [data-svc]{transition:background-color .2s ease}\n" => '',
    "  [data-svc] h3,[data-svc] p,[data-svc-num]{transition:color .2s ease}\n" => '',
    "  [data-svc]:hover{background:#0d0d0d!important}\n" => '',
    "  [data-svc]:hover h3{color:#fff!important}\n" => '',
    "  [data-svc]:hover p{color:#d0d0d0!important}\n" => '',
    "  [data-svc]:hover [data-svc-num]{color:#FFC700!important}\n" => '',
    // вторая строка подписи под логотипом
    "{$tag}ДОРОГИ · БЛАГОУСТРОЙСТВО</div>" => "{$tag}ДОРОГИ · БЛАГОУСТРОЙСТВО</div>{$tag}АРЕНДА СПЕЦТЕХНИКИ</div>",
    // прайс: цены с НДС; Sany — 36 000 ₽ / смена вместо «договорная»
    '>Цены на аренду</h3>' => '>Цены на аренду (с НДС)</h3>',
    $sany . '<span style="display:inline-block;font-family:\'Oswald\';font-weight:600;font-size:13px;line-height:1.2;letter-spacing:1px;text-transform:uppercase;color:#FFC700;border:1px solid #FFC700;padding:5px 10px">договорная</span> <span style="font-size:14px;color:#a8a8a8;margin-left:4px">смена</span>'
        => $sany . '<span style="font-family:\'Oswald\';font-weight:700;font-size:20px;color:#fff">36 000 ₽</span> <span style="font-size:14px;color:#a8a8a8">/ смена</span>',
] as $from => $to) {
    if (substr_count($expected, $from) !== 1) { fwrite(STDERR, "FAIL: правка эталона не найдена ровно один раз: $from\n"); exit(1); }
    $expected = str_replace($from, $to, $expected);
}

function load(string $html): DOMDocument
{
    $d = new DOMDocument();
    $d->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);
    return $d;
}
$E = load($expected);
$A = load($actual);
$ex = new DOMXPath($E);
$ax = new DOMXPath($A);
$diffs = [];
$diff = function (string $path, string $e, string $a) use (&$diffs) { $diffs[] = "$path: $e | $a"; };

// smoke: парсер сохраняет нестандартные и boolean-атрибуты
$c = $ex->query('//*[@style-hover]')->length;
$n = $ex->query('//span[@data-count]')->item(0);
if ($c === 0 || !$n || $n->getAttribute('data-count') !== '') { fwrite(STDERR, "FAIL smoke: style-hover=$c, data-count не распознан\n"); exit(1); }

// P1/P2: последний <script> без type в <body> — проверяем содержимое отдельно и вырезаем из сравнения
$cut = function (DOMXPath $x): ?string {
    $l = $x->query('//body//script[not(@type)]');
    if (!$l->length) return null;
    $s = $l->item($l->length - 1);
    $s->parentNode->removeChild($s);
    return $s->textContent;
};
$cut($ex);
$js = $cut($ax);
if ($js === null) $diff('//body/script[last]', 'script', '(нет)');
else {
    foreach (['openModal', 'openCalc', 'closeModal', 'submitForm', 'submitModal'] as $f)
        if (!str_contains($js, $f)) $diff('//body/script[last]', "содержит $f", 'нет');
    if (preg_match('/api\.telegram\.org|bot[0-9]/', $js)) $diff('//body/script[last]', 'без Telegram Bot API', 'найден');
}

// P3: label с галочкой согласия — вырезаем из обоих; в actual внутри <form> разрешены honeypot website и hidden source
$le = $ex->query("//label[input[@type='checkbox']]")->length;
$la = $ax->query("//label[input[@type='checkbox']][.//a[@href='consent.php']][not(.//a[@href='privacy.php'])]")->length;
if ($le !== $la) $diff("//label[input[@type='checkbox']]", "$le со ссылкой на consent.php (без privacy.php)", (string)$la);
// P3: строка «Политика…» отдельно от label — только в actual, сразу после label с галочкой внутри form, ровно одна ссылка privacy.php
$pp = "//form/p[@class='pd-policy'][preceding-sibling::*[1][self::label[input[@type='checkbox']]]][count(*)=1][a[@href='privacy.php']]";
if ($ax->query($pp)->length !== $le || $ax->query("//*[@class='pd-policy']")->length !== $le)
    $diff($pp, (string)$le, (string)$ax->query("//*[@class='pd-policy']")->length);
foreach (iterator_to_array($ax->query($pp)) as $n) $n->parentNode->removeChild($n);
foreach ([$ex, $ax] as $x)
    foreach (iterator_to_array($x->query("//label[input[@type='checkbox']]")) as $n) $n->parentNode->removeChild($n);
foreach (iterator_to_array($ax->query("//form//input[@name='website'] | //form//input[@type='hidden'][@name='source']")) as $n)
    $n->parentNode->removeChild($n);
// P6: подвал целиком (реквизиты-заглушки, режим работы, ссылки на политику и согласие)
if (!$ax->query('//footer')->length) $diff('//footer', 'footer', '(нет)');
foreach ([$ex, $ax] as $x) foreach (iterator_to_array($x->query('//footer')) as $n) $n->parentNode->removeChild($n);
// P7: 3-я плашка статистики («100% качество работ» → текст заказчика) — только наличие
$p7 = "//div[@data-r='stats']/div[@data-anim='pop'][3]";
if (!$ax->query($p7)->length) $diff($p7, 'div', '(нет)');
foreach ([$ex, $ax] as $x) foreach (iterator_to_array($x->query($p7)) as $n) $n->parentNode->removeChild($n);

// инварианты
foreach (['data-r', 'data-anim', 'data-count', 'style-hover', 'style-focus'] as $at) {
    $q = "//*[@$at]";
    if ($ex->query($q)->length !== $ax->query($q)->length)
        $diff("count($q)", (string)$ex->query($q)->length, (string)$ax->query($q)->length);
}
$styles = fn(DOMXPath $x) => array_unique(array_map(fn($n) => $n->value, iterator_to_array($x->query('//@style'))));
foreach (array_diff($styles($ax), $styles($ex)) as $st) $diff('//@style', '(нет такого)', $st);
if ($ax->query('//*[@id="cookie-bar"]')->length) $diff('//*[@id="cookie-bar"]', '(нет)', 'есть');

// дети: элементы и непустые нормализованные тексты; комментарии выброшены, соседние тексты склеены
function kids(DOMNode $n): array
{
    $out = [];
    $txt = null;
    foreach ($n->childNodes as $c) {
        if ($c instanceof DOMComment) continue;
        if ($c instanceof DOMText) { $txt = ($txt ?? '') . $c->data; continue; }
        if ($txt !== null) { $out[] = $txt; $txt = null; }
        if ($c instanceof DOMElement) $out[] = $c;
    }
    if ($txt !== null) $out[] = $txt;
    $res = [];
    foreach ($out as $o) {
        if (is_string($o)) { $o = trim(preg_replace('/\s+/u', ' ', $o)); if ($o === '') continue; }
        $res[] = $o;
    }
    return $res;
}

function attrs(DOMElement $el): array
{
    $m = [];
    foreach ($el->attributes as $a) $m[$a->name] = $a->value;
    ksort($m);
    return $m;
}

function cmp(DOMElement $e, DOMElement $a, string $path, callable $diff): void
{
    if ($e->tagName !== $a->tagName) { $diff($path, "<$e->tagName>", "<$a->tagName>"); return; }
    $ea = attrs($e);
    $aa = attrs($a);
    if ($ea !== $aa) $diff("$path/@*", json_encode($ea, JSON_UNESCAPED_UNICODE), json_encode($aa, JSON_UNESCAPED_UNICODE));
    if ($e->tagName === 'script' && $e->getAttribute('type') === 'application/ld+json') {
        $je = json_decode($e->textContent, true);
        $ja = json_decode($a->textContent, true);
        if ($je === null || $je != $ja) $diff("$path/json", json_encode($je, JSON_UNESCAPED_UNICODE), json_encode($ja, JSON_UNESCAPED_UNICODE));
        return;
    }
    $ek = kids($e);
    $ak = kids($a);
    $cnt = [];
    for ($i = 0; $i < max(count($ek), count($ak)); $i++) {
        $x = $ek[$i] ?? null;
        $y = $ak[$i] ?? null;
        $name = $x instanceof DOMElement ? $x->tagName : ($y instanceof DOMElement ? $y->tagName : 'text()');
        $cnt[$name] = ($cnt[$name] ?? 0) + 1;
        $p = "$path/$name" . "[{$cnt[$name]}]";
        if ($x instanceof DOMElement && $y instanceof DOMElement) { cmp($x, $y, $p, $diff); continue; }
        if (is_string($x) && is_string($y)) {
            // решение заказчика 2026-10-26: единственная разрешённая правка текста option
            $ok = $x === $y || ($e->tagName === 'option' && $x === 'Благоустройство территории' && $y === 'Благоустройство');
            if (!$ok) $diff($p, $x, $y);
            continue;
        }
        $show = fn($v) => $v === null ? '(нет)' : ($v instanceof DOMElement ? "<$v->tagName>" : $v);
        $diff($p, $show($x), $show($y));
    }
}

cmp($E->documentElement, $A->documentElement, '/html', $diff);

if ($diffs) {
    foreach ($diffs as $d) echo $d, "\n";
    echo 'FAIL ', count($diffs), " diffs\n";
    exit(1);
}
echo "OK 0 diffs\n";
