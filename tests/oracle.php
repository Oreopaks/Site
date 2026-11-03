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
    // решения заказчика: посадочные, 2026-10-27
    "  [data-burger]{display:none}\n" => "  [data-svc] h3 a{text-decoration:underline;text-decoration-color:#FFC700;text-decoration-thickness:2px;text-underline-offset:5px}\n  [data-burger]{display:none}\n",
    'href="#fleet" style=""' => 'href="/arenda-spetstekhniki/" style=""',
    'href="#materials" style=""' => 'href="/shcheben-pesok-grunt/" style=""',
    'href="#fleet" style="color:#fff' => 'href="/arenda-spetstekhniki/" style="color:#fff',
    'href="#materials" style="color:#fff' => 'href="/shcheben-pesok-grunt/" style="color:#fff',
    'color:inherit">Аренда спецтехники</h3>' => 'color:inherit"><a href="/arenda-spetstekhniki/">Аренда спецтехники</a></h3>',
    'color:inherit">Поставка материалов</h3>' => 'color:inherit"><a href="/shcheben-pesok-grunt/">Поставка материалов</a></h3>',
    // мобильный первый экран, 2026-10-28: иконка tel: в шапке перед бургером (<=1120px, где скрыт data-hphone);
    // при <=640px шапка ужата, чтобы иконка и бургер влезали в 360px (tests/pw/call.js)
    "  @media (max-width:1120px){\n    [data-hphone]{display:none!important}\n  }"
        => "  [data-hcall]{display:none;width:44px;height:44px;border:1.5px solid #3a3a3a;align-items:center;justify-content:center}\n  @media (max-width:1120px){\n    [data-hphone]{display:none!important}\n    [data-hcall]{display:inline-flex!important}\n  }",
    "  @media (max-width:640px){\n"
        => "  @media (max-width:640px){\n    header>div:first-child{gap:10px!important}\n    header>div:first-child>a{gap:9px!important}\n    header>div:first-child>a img{width:40px!important;height:40px!important}\n    header>div:first-child>a div div:first-child{font-size:18px!important}\n    header>div:first-child>a div div+div{letter-spacing:1px!important}\n    header>div:first-child>div:last-child{gap:8px!important}\n    [data-burger]{width:44px!important}\n",
    '<button onclick="toggleMenu()" data-burger'
        => '<a href="tel:+79807691111" data-hcall aria-label="Позвонить 8 980 769-11-11"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FFC700" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h3l2 5-2.2 1.3a12 12 0 0 0 5.7 5.7L16 15l5 2v3a2 2 0 0 1-2 2A17 17 0 0 1 4 5a2 2 0 0 1 2-2Z"/></svg></a>' . "\n        " . '<button onclick="toggleMenu()" data-burger',
    // 2026-11-03: аккаунта t.me/regiondorstroy (из макета) нет — кнопки Telegram показываются, только если ссылка задана в настройках (в сиде пусто)
    "\n        <a href=\"https://t.me/regiondorstroy\" style=\"color:#FFC700;font-weight:600\">Telegram</a>" => '',
    "\n            <a href=\"https://t.me/regiondorstroy\" style=\"display:inline-flex;align-items:center;gap:8px;background:#1f1f1f;color:#fff;padding:13px 20px;font-weight:700;font-size:14px\" style-hover=\"background:#FFC700;color:#0d0d0d\">Telegram</a>" => '',
    '"sameAs": ["https://t.me/regiondorstroy", "https://wa.me/79807691111"]' => '"sameAs": ["https://wa.me/79807691111"]',
    // 2026-11-03: бургер шапки 48×42 → 48×44 (тап-зона ≥44, tests/pw/launch.js F14)
    'color:#fff;width:48px;height:42px;cursor:pointer' => 'color:#fff;width:48px;height:44px;cursor:pointer',
    // 2026-11-03: тап-зоны подвала ≥44px на телефоне и тач-планшете (tests/pw/launch.js F13)
    "  }\n\n</style>"
        => "  }\n  /* подвал на телефоне и тач-планшете: ссылки 17–19px по высоте → тап-зона ≥44px, ПК (мышь) без изменений */\n  @media (max-width:640px),(pointer:coarse){\n    footer [style*=\"flex-direction:column\"]{gap:0!important}\n    footer [style*=\"flex-direction:column\"]>*{display:flex;align-items:center;min-height:44px}\n    footer span>a{display:inline-flex;align-items:center;min-height:44px}\n  }\n\n</style>",
] as $from => $to) {
    if (substr_count($expected, $from) !== 1) { fwrite(STDERR, "FAIL: правка эталона не найдена ровно один раз: $from\n"); exit(1); }
    $expected = str_replace($from, $to, $expected);
}
// решение 2026-10-28: единый вид «/ ед.» у договорной цены в прайсе (как у числовых); пара повторяется — проверка числа вхождений
$expected = str_replace('договорная</span> <span style="font-size:14px;color:#a8a8a8;margin-left:4px">', 'договорная</span> <span style="font-size:14px;color:#a8a8a8">/ ', $expected, $c);
if ($c !== 6) { fwrite(STDERR, "FAIL: «договорная / ед.» — ожидалось 6 замен, сделано $c\n"); exit(1); }
// решение 2026-10-28: CTA карточек техники кликабельна — <button> открывает форму расчёта (аренда + техника); 9 карточек; подпись блока в модалке меняется скриптом
$expected = str_replace('<div style="margin-top:6px;font-size:12.5px;color:#FFC700;font-weight:700;letter-spacing:.5px">В АРЕНДУ →</div>', '<button type="button" onclick="openRent(this)" style="display:block;margin-top:0;padding:6px 0;background:none;border:none;cursor:pointer;font-family:inherit;font-size:12.5px;color:#FFC700;font-weight:700;letter-spacing:.5px">В АРЕНДУ →</button>', $expected, $c);
if ($c !== 9) { fwrite(STDERR, "FAIL: «В АРЕНДУ» — ожидалось 9 замен, сделано $c\n"); exit(1); }
$expected = str_replace('Материал: <span id="modalMatName">', '<span id="modalMatLbl">Материал</span>: <span id="modalMatName">', $expected, $c);
if ($c !== 1) { fwrite(STDERR, "FAIL: подпись «Материал:» — ожидалась 1 замена, сделано $c\n"); exit(1); }
// решение 2026-10-28 (рой): A6 — overflow-x:clip (hidden делал обёртку контейнером прокрутки, и sticky-шапка уезжала);
// A3 — «Оставить заявку» первого экрана ведёт к #contacts, как на посадочных; B6 — «Планировка участков» в списке услуг формы;
// A2 — необязательное поле «Объём, адрес, сроки» в окне расчёта/аренды (скрыто в «Заказать звонок»). tests/pw/tails.js
foreach ([
    '<div style="overflow-x:hidden">' => '<div style="overflow-x:hidden;overflow-x:clip">',
    '<button onclick="openModal()" style="background:#FFC700;color:#0d0d0d;border:none;font-family:\'Manrope\';font-weight:800;font-size:15px;padding:17px 30px;cursor:pointer;text-transform:uppercase;letter-spacing:.3px" style-hover="background:#ffd633">Оставить заявку</button>'
        => '<a href="#contacts" style="background:#FFC700;color:#0d0d0d;font-family:\'Manrope\';font-weight:800;font-size:15px;padding:17px 30px;text-transform:uppercase;letter-spacing:.3px;display:inline-flex;align-items:center" style-hover="background:#ffd633">Оставить заявку</a>',
    '<option>Поставка материалов</option>' => '<option>Поставка материалов</option><option>Планировка участков</option>',
    'margin-bottom:16px;outline:none" style-focus="border-color:#FFC700">'
        => 'margin-bottom:16px;outline:none" style-focus="border-color:#FFC700"><div id="modalTask" style="display:none"><textarea placeholder="Объём, адрес, сроки" aria-label="Объём, адрес, сроки" rows="3" maxlength="900" style="display:block;width:100%;background:#f4f4f2;border:1px solid #e2e2de;color:#111;padding:14px 16px;font-size:14.5px;font-family:\'Manrope\';margin-bottom:16px;outline:none;resize:vertical" style-focus="border-color:#FFC700"></textarea></div>',
    // 2026-11-03: комментарий нижней формы — maxlength как лимит send.php (1000), иначе 400 и заявка теряется. tests/pw/launch.js
    'aria-label="Комментарий" rows="3" style=' => 'aria-label="Комментарий" rows="3" maxlength="1000" style=',
] as $from => $to) {
    $expected = str_replace($from, $to, $expected, $c);
    if ($c !== 1) { fwrite(STDERR, "FAIL: правка роя — ожидалась 1 замена, сделано $c: $from\n"); exit(1); }
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
