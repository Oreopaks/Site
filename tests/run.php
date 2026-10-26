<?php
// Assert-тесты. Свой стенд php -S на :8081 и своя БД: RDS_DB (должен указывать на несуществующий файл) или временный файл.
// Запуск: RDS_DB=/путь/к/new.sqlite php tests/run.php → exit 0, если всё OK.

$root = dirname(__DIR__);
$dbPath = getenv('RDS_DB') ?: sys_get_temp_dir() . '/rds-test-' . getmypid() . '.sqlite';
if (is_file($dbPath)) { fwrite(STDERR, "RDS_DB=$dbPath уже существует — нужен новый файл (тесты пишут в БД)\n"); exit(2); }
putenv("RDS_DB=$dbPath");
require $root . '/app/bootstrap.php';
ini_set('display_errors', '1');
db(); // создать БД до старта стенда — без гонки двух процессов

$base = 'http://127.0.0.1:8081';
$srv = proc_open([PHP_BINARY, '-S', '127.0.0.1:8081', '-t', "$root/public", "$root/public/router.php"],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, getenv() + ['RDS_DB' => $dbPath]);
register_shutdown_function(fn() => proc_terminate($srv));
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', 8081); $i++) usleep(100000);

$fail = 0;
function check(string $name, bool $ok, string $why = ''): void
{
    global $fail;
    echo $name, $ok ? ' OK' : " FAIL $why", "\n";
    if (!$ok) $fail++;
}

// HTTP-запрос без редиректов → [код, тело, заголовки]; $cookie — «name=value» или ''
function req(string $url, ?array $post = null, string $cookie = ''): array
{
    $h = $cookie !== '' ? ["Cookie: $cookie"] : [];
    $opt = ['ignore_errors' => true, 'follow_location' => 0];
    if ($post !== null) {
        $h[] = 'Content-Type: application/x-www-form-urlencoded';
        $opt += ['method' => 'POST', 'content' => http_build_query($post)];
    }
    $opt['header'] = $h;
    $body = file_get_contents($url, false, stream_context_create(['http' => $opt]));
    preg_match('~^HTTP/\S+ (\d+)~', $http_response_header[0] ?? '', $m);
    return [(int)($m[1] ?? 0), $body, $http_response_header ?? []];
}
$leads = fn() => (int)db()->query('SELECT COUNT(*) FROM leads')->fetchColumn();
$lead = ['source' => 'calc', 'name' => 'Тест', 'phone' => '+7 980 000-00-00', 'service' => 'Благоустройство', 'comment' => 'x', 'website' => ''];

// --- send.php
[$code, $body] = req("$base/send.php", $lead);
check('lead without consent → 400', $code === 400 && json_decode($body, true) === ['ok' => false, 'error' => 'consent'], "$code $body");

[$code, $body] = req("$base/send.php", $lead + ['consent' => '1']);
$j = json_decode($body, true);
check('valid lead → count=1', $code === 200 && ($j['ok'] ?? null) === true && $leads() === 1, "$code $body");

[$code, $body] = req("$base/send.php", ['website' => 'http://spam'] + $lead + ['consent' => '1']);
check('honeypot → ok:true, count unchanged', $code === 200 && (json_decode($body, true)['ok'] ?? null) === true && $leads() === 1, "$code $body");

// --- экранирование (D5/D6)
$set = fn(string $k, string $v) => db()->prepare('UPDATE settings SET value = ? WHERE key = ?')->execute([$v, $k]);
db()->prepare('UPDATE materials SET title = ? WHERE sort = 1')->execute(["a');alert(1)//"]);
$set('ld_name', '</script><script>x');
$set('modal_call_text', "it's\nx");
[, $html] = req("$base/");

preg_match('~onclick="(openCalc\([^"]*)"~', $html, $m);
$oc = html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
check("materials.title=\"a');alert(1)//\" → html_entity_decode(onclick) contains \"openCalc('a\\x27);alert(1)//')\" and not contains \"');\"",
    str_contains($oc, "openCalc('a\\x27);alert(1)//')") && !str_contains($oc, "');"), $oc);

$a = strpos($html, '<script type="application/ld+json">');
$ld = substr($html, $a, strpos($html, '<link rel="preload"', $a) - $a);
$ldj = json_decode(substr($ld, strlen('<script type="application/ld+json">'), strrpos($ld, '</script>') - strlen('<script type="application/ld+json">')), true);
check('ld_name="</script><script>x" → substr_count(ld+json block, \'</script\') === 1',
    $a !== false && substr_count($ld, '</script') === 1 && ($ldj['name'] ?? null) === '</script><script>x', $ld);

$line = '';
foreach (explode("\n", $html) as $l) if (str_contains($l, 'window.openModal=function')) $line = $l;
check("modal_call_text=\"it's\\nx\" → inline script contains \"it\\x27s\\x0ax\", no raw newline in literal",
    str_contains($line, "'it\\x27s\\x0ax'"), $line);

// --- href (D5): обёртка сохранения до появления админки (M4): невалидный → 422, валидный → запись
$saveHref = function (string $href): int {
    if (!valid_href($href)) return 422;
    db()->prepare('UPDATE services SET href = ? WHERE sort = 1')->execute([$href]);
    return 200;
};
check('href "javascript:x" → 422', $saveHref('javascript:x') === 422);
check('href "//evil/x.php" → 422', $saveHref('//evil/x.php') === 422);
check('href "#fleet" → saved', $saveHref('#fleet') === 200 && db()->query('SELECT href FROM services WHERE sort = 1')->fetchColumn() === '#fleet');

// --- M3: админка, роли, CSRF, блокировка входа
$cli = function (array $args, string $stdin = '') use ($root, $dbPath): int {
    $p = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, $root, getenv() + ['RDS_DB' => $dbPath]);
    fwrite($pp[0], $stdin);
    fclose($pp[0]);
    return proc_close($p);
};
$pw = 'correct horse battery';
check('create-user: пароль < 12 символов → отказ', $cli(['bin/create-user.php', 'shorty', 'owner'], "short\n") !== 0);
foreach (['boss' => 'owner', 'redactor' => 'editor', 'victim' => 'manager'] as $l => $r) $cli(['bin/create-user.php', $l, $r], "$pw\n");
check('create-user: 3 пользователя', (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 3);

$setCookie = fn(array $hdr) => implode("\n", preg_grep('~^Set-Cookie:~i', $hdr));
$sid = fn(array $hdr) => preg_match('~^Set-Cookie: (rds_admin=[^;]+)~mi', implode("\n", $hdr), $m) ? $m[1] : '';
// GET формы (сессия + токен) → POST логина; → [код ответа на POST, cookie после входа, cookie до входа, заголовки GET]
$login = function (string $l, string $p) use ($base, $sid): array {
    [, $html, $h0] = req("$base/admin/?p=login");
    $c0 = $sid($h0);
    preg_match('~name="_csrf" value="([0-9a-f]+)"~', $html, $m);
    [$code, , $h] = req("$base/admin/?p=login", ['_csrf' => $m[1] ?? '', 'login' => $l, 'password' => $p], $c0);
    return [$code, $sid($h) ?: $c0, $c0, $h0];
};
$csrfOf = fn(string $c) => preg_match('~name="_csrf" value="([0-9a-f]+)"~', req("$base/admin/", null, $c)[1], $m) ? $m[1] : '';

[, , $hdr] = req("$base/");
check('curl -I / → нет Set-Cookie', $setCookie($hdr) === '', $setCookie($hdr));

[$code, $boss, $pre, $h0] = $login('boss', $pw);
$sc = $setCookie($h0);
check('Set-Cookie админки: path=/admin/, HttpOnly, SameSite=Strict',
    stripos($sc, 'path=/admin/') !== false && stripos($sc, 'HttpOnly') !== false && stripos($sc, 'SameSite=Strict') !== false, $sc);
check('owner login → 302, session id regenerated', $code === 302 && $boss !== '' && $boss !== $pre, "$code $pre → $boss");
[$code, , $hdr] = req("$base/admin/?p=leads", null, $boss);
$csp = implode("\n", preg_grep('~^Content-Security-Policy:~i', $hdr));
check("owner GET ?p=leads → 200, CSP default-src 'self'; frame-ancestors 'none'",
    $code === 200 && str_contains($csp, "default-src 'self'") && str_contains($csp, "frame-ancestors 'none'"), "$code $csp");
check('admin: баннер «Реквизиты не заполнены»', str_contains(req("$base/admin/", null, $boss)[1], 'Реквизиты не заполнены'));

[, $ed] = $login('redactor', $pw);
[$code] = req("$base/admin/?p=leads", null, $ed);
check('editor GET ?p=leads → 403', $code === 403, (string)$code);
[$code] = req("$base/admin/?p=leads&a=delete", ['_csrf' => $csrfOf($ed)], $ed);
check('editor POST ?p=leads (с CSRF) → 403', $code === 403, (string)$code);

[$code] = req("$base/admin/?p=logout", [], $boss);
check('logged-in POST without _csrf → 403', $code === 403 && req("$base/admin/", null, $boss)[0] === 200, (string)$code);
[$code, , $hdr] = req("$base/admin/?p=logout", []);
check('same POST without session → 302', $code === 302 && in_array('Location: /admin/?p=login', $hdr, true), (string)$code);
[$code] = req("$base/admin/?p=logout", ['_csrf' => $csrfOf($boss)], $boss);
check('logout POST с CSRF → 302, сессия закрыта', $code === 302 && req("$base/admin/", null, $boss)[0] === 302, (string)$code);

$codes = [];
for ($i = 0; $i < 5; $i++) $codes[] = $login('victim', 'wrong password!')[0];
check('5 wrong → 423', $codes === [200, 200, 200, 200, 423], implode(',', $codes));
[$code] = $login('victim', $pw);
check('6th correct still 423', $code === 423, (string)$code);
[$code] = $login('nobody', 'whatever password');
check('unknown login → 200 (не 302), строка в login_attempts', $code === 200
    && (int)db()->query("SELECT fails FROM login_attempts WHERE login = 'nobody'")->fetchColumn() === 1, (string)$code);
$cli(['bin/unlock.php', 'victim']);
[$code] = $login('victim', $pw);
check('unlock.php → login OK', $code === 302
    && db()->query("SELECT COUNT(*) FROM login_attempts WHERE login = 'victim'")->fetchColumn() == 0, (string)$code);

exit($fail ? 1 : 0);
