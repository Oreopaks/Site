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
function req(string $url, ?array $post = null, string $cookie = '', array $hdr = []): array
{
    $h = $cookie !== '' ? ["Cookie: $cookie", ...$hdr] : $hdr;
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
// SEC-1: счётчик растёт до password_verify — при fails=5 без locked_until верный пароль уже не проверяется
db()->exec("INSERT INTO login_attempts (login, fails) VALUES ('victim', 5)");
[$code] = $login('victim', $pw);
check('fails=5 (без locked_until) + верный пароль → 423, без verify', $code === 423, (string)$code);
$cli(['bin/unlock.php', 'victim']);
// SEC-1: исключение внутри login() не пишет пароль в лог (RDS_DB → каталог: PDO не откроет файл; -d — как PHP без php.ini)
$log = sys_get_temp_dir() . '/rds-log-' . getmypid() . '.txt';
@mkdir($dir = sys_get_temp_dir() . '/rds-dir-' . getmypid());
$p = proc_open([PHP_BINARY, '-d', 'zend.exception_ignore_args=0', '-d', 'zend.exception_string_param_max_len=15', '-r', "require 'app/bootstrap.php'; require 'app/auth.php'; ini_set('error_log', '$log'); login('boss', 'leak-me-secret-pass');"],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, $root, ['RDS_DB' => $dir] + getenv());
proc_close($p);
@rmdir($dir);
$lg = (string)@file_get_contents($log);
@unlink($log);
check('PDOException в login() → в логе нет пароля', str_contains($lg, 'PDOException') && str_contains($lg, 'login(') && !str_contains($lg, 'leak-me'), $lg);
// SEC-1: абсолютный срок сессии 8 ч — started сдвигаем прямо в файле сессии стенда
[, $old] = $login('redactor', $pw);
$sf = rtrim(ini_get('session.save_path') ?: sys_get_temp_dir(), '/') . '/sess_' . substr($old, strlen('rds_admin='));
file_put_contents($sf, preg_replace('~started\|i:\d+;~', 'started|i:' . (time() - 28801) . ';', (string)file_get_contents($sf), 1, $n));
[$code, , $hdr] = req("$base/admin/", null, $old);
check('сессия со started −8 ч −1 с → 302 на login', $n === 1 && $code === 302 && in_array('Location: /admin/?p=login', $hdr, true), "$n $code");

// --- M4: CRUD, права по группам, аудит
[, $boss] = $login('boss', $pw);
[, $ed] = $login('redactor', $pw);
[, $mgr] = $login('victim', $pw);
$audits = fn() => (int)db()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
$val = fn(string $k) => db()->query('SELECT value FROM settings WHERE key = ' . db()->quote($k))->fetchColumn();

[$code] = req("$base/admin/?p=settings&g=requisites&a=save", ['_csrf' => $csrfOf($ed), 'org_inn' => '1234567890'], $ed);
check('editor POST requisites → 403', $code === 403 && $val('org_inn') === '', (string)$code);
[$code] = req("$base/admin/?p=settings&g=requisites", null, $ed);
check('editor GET requisites → 403', $code === 403, (string)$code);

$h1 = $val('hero_h1');
[$code] = req("$base/admin/?p=settings&g=hero&a=save", ['_csrf' => $csrfOf($mgr), 'hero_p' => 'x', 'hero_h1' => '<img src=x onerror=alert(1)>'], $mgr);
check('manager POST trusted key → 403', $code === 403 && $val('hero_h1') === $h1 && $val('hero_p') !== 'x', (string)$code);
[$code] = req("$base/admin/?p=settings&g=mail&a=save", ['_csrf' => $csrfOf($mgr), 'notify_email' => 'a@gmail.com'], $mgr);
check('manager POST notify_email → 403', $code === 403 && $val('notify_email') === '', (string)$code);
[$code] = req("$base/admin/?p=settings&g=contacts&a=save", ['_csrf' => $csrfOf($mgr), 'whatsapp' => 'javascript:alert(1)'], $mgr);
check('manager POST whatsapp "javascript:…" → 422', $code === 422 && $val('whatsapp') === 'https://wa.me/79807691111', (string)$code);

$row = db()->query("SELECT * FROM fleet_prices WHERE sort = 1")->fetch();
$n0 = $audits();
[$code] = req("$base/admin/?p=content&t=fleet_prices&a=save", ['_csrf' => $csrfOf($boss), 'id' => $row['id'], 'name' => $row['name'],
    'model' => $row['model'], 'price_kind' => 'fixed', 'price' => '99999', 'unit' => $row['unit']], $boss);
$au = db()->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
check('owner save price → audit row', $code === 302 && $audits() === $n0 + 1 && $au['tbl'] === 'fleet_prices' && $au['row_key'] === (string)$row['id']
    && json_decode($au['old_value'], true) === ['price' => 36000] && json_decode($au['new_value'], true) === ['price' => '99999'], "$code " . json_encode($au, JSON_UNESCAPED_UNICODE));
check('price 99999 → на / «99 999 ₽»', str_contains(req("$base/")[1], '99 999 ₽'));

$sv = db()->query('SELECT * FROM services WHERE sort = 2')->fetch();
[$code] = req("$base/admin/?p=content&t=services&a=save", ['_csrf' => $csrfOf($ed), 'id' => $sv['id'], 'title' => $sv['title'], 'text' => $sv['text'],
    'href' => 'javascript:x', 'in_form' => '1', 'in_footer' => '1'], $ed);
check('href "javascript:x" через POST админки → 422', $code === 422
    && db()->query("SELECT href FROM services WHERE id = {$sv['id']}")->fetchColumn() === '#services', (string)$code);
[$code] = req("$base/admin/?p=content&t=fleet_cards&a=save", ['_csrf' => $csrfOf($ed), 'id' => 1, 'title' => 'x', 'img' => 'assets/../../var/x.webp',
    'alt' => 'x', 'img_pos' => '50% 50%', 'price_kind' => 'contract', 'price' => '', 'unit' => ''], $ed);
check('img "assets/../../var/x.webp" → 422', $code === 422, (string)$code);
[$code] = req("$base/admin/?p=content&t=marquee&a=sort", ['_csrf' => $csrfOf($ed), 'id' => db()->query("SELECT id FROM items WHERE kind='marquee' AND sort=2")->fetchColumn(), 'dir' => 'up'], $ed);
check('editor sort marquee #2 up → 302, стал первым', $code === 302
    && db()->query("SELECT title FROM items WHERE kind='marquee' ORDER BY sort LIMIT 1")->fetchColumn() === 'Благоустройство территорий', (string)$code);

[$code] = req("$base/admin/?p=users", null, $mgr);
check('manager GET ?p=users → 403', $code === 403, (string)$code);
$np = 'new secret password 42';
[$code] = req("$base/admin/?p=users&a=save", ['_csrf' => $csrfOf($boss), 'id' => '', 'login' => 'newbie', 'role' => 'editor', 'password' => 'first password 123'], $boss);
$uid = db()->query("SELECT id FROM users WHERE login = 'newbie'")->fetchColumn();
[, $nb] = $login('newbie', 'first password 123');
[$code2] = req("$base/admin/?p=users&a=password", ['_csrf' => $csrfOf($boss), 'id' => $uid, 'password' => $np], $boss);
$hash = db()->query("SELECT pass_hash FROM users WHERE id = $uid")->fetchColumn();
$dump = implode("\n", array_map(fn($r) => implode('|', $r), db()->query('SELECT * FROM audit_log')->fetchAll()));
check('owner password change → audit_log has no pass_hash', $code === 302 && $code2 === 302 && $login('newbie', $np)[0] === 302
    && !str_contains($dump, $hash) && !str_contains($dump, $np) && !str_contains($dump, 'first password') && !preg_match('~\$2y\$|\$argon|pass_hash~', $dump), "$code $code2");
[$code, , $hdr] = req("$base/admin/", null, $nb);
check('смена пароля → старая сессия 302 на login', $nb !== '' && $code === 302 && in_array('Location: /admin/?p=login', $hdr, true), (string)$code);
[, $nb] = $login('newbie', $np);
[$code] = req("$base/admin/?p=users&a=toggle", ['_csrf' => $csrfOf($boss), 'id' => $uid], $boss);
check('owner toggle → newbie не входит', $code === 302 && $login('newbie', $np)[0] === 200, (string)$code);
[$code] = req("$base/admin/?p=users&a=toggle", ['_csrf' => $csrfOf($boss), 'id' => $uid], $boss);
check('отключение + включение → старая сессия 302', $code === 302 && req("$base/admin/", null, $nb)[0] === 302 && $login('newbie', $np)[0] === 302, (string)$code);
check('admin: баннер виден на ?p=content', str_contains(req("$base/admin/?p=content", null, $ed)[1], 'Реквизиты не заполнены'));

// --- M5: заявки, CSV, статистика
[$code, $body] = req("$base/send.php", ['comment' => '=1+1', 'name' => 'Формула'] + $lead + ['consent' => '1']);
$lid = (int)(json_decode($body, true)['n'] ?? 0);
[$code, $csv, $hdr] = req("$base/admin/?p=leads&a=csv&source=calc", null, $boss);
$cells = [];
foreach (explode("\n", trim(substr($csv, 3))) as $l) if (str_starts_with($l, "$lid;")) $cells = str_getcsv($l, ';', '"', '');
check("csv '=1+1' → \"'=1+1\"", $code === 200 && str_starts_with($csv, "\xEF\xBB\xBF") && in_array("'=1+1", $cells, true)
    && in_array("'+7 980 000-00-00", $cells, true) && preg_grep('~^Content-Type: text/csv~i', $hdr), "$code " . json_encode($cells, JSON_UNESCAPED_UNICODE));
[$code] = req("$base/admin/?p=leads&a=csv", null, $ed);
check('editor GET ?p=leads&a=csv → 403', $code === 403, (string)$code);

$mgrId = (int)db()->query("SELECT id FROM users WHERE login = 'victim'")->fetchColumn();
[$code, , $hdr] = req("$base/admin/?p=leads&a=status&source=calc", ['_csrf' => $csrfOf($mgr), 'id' => $lid, 'status' => 'in_work'], $mgr);
$evr = db()->query("SELECT * FROM lead_events WHERE lead_id = $lid")->fetchAll();
check('status → lead_events row', $code === 302 && in_array('Location: /admin/?p=leads&source=calc&ok=1', $hdr, true)
    && db()->query("SELECT status FROM leads WHERE id = $lid")->fetchColumn() === 'in_work'
    && count($evr) === 1 && (int)$evr[0]['user_id'] === $mgrId && $evr[0]['from_status'] === 'new' && $evr[0]['to_status'] === 'in_work', "$code " . json_encode($evr));
[$code] = req("$base/admin/?p=leads&a=status", ['_csrf' => $csrfOf($mgr), 'id' => $lid, 'status' => 'bogus'], $mgr);
check('status "bogus" → 422', $code === 422, (string)$code);
$page = fn(string $q) => req("$base/admin/?p=leads&$q", null, $mgr)[1];
check('фильтр status=in_work показывает заявку, status=done — нет', str_contains($page('status=in_work'), 'Формула') && !str_contains($page('status=done'), 'Формула')
    && !str_contains($page('from=2000-01-01&to=2000-01-02'), 'Формула'));

[$code] = req("$base/admin/?p=leads&a=delete", ['_csrf' => $csrfOf($mgr), 'id' => $lid], $mgr);
check('manager POST delete → 403', $code === 403 && $leads() === 2, (string)$code);
[$code] = req("$base/admin/?p=leads&a=delete", ['_csrf' => $csrfOf($boss), 'id' => $lid], $boss);
$au = db()->query("SELECT * FROM audit_log WHERE tbl = 'leads' AND row_key = '$lid'")->fetch();
check('owner POST delete → 302, lead_events каскадом, в audit без ПДн', $code === 302 && $leads() === 1
    && db()->query("SELECT COUNT(*) FROM lead_events WHERE lead_id = $lid")->fetchColumn() == 0
    && $au && !str_contains($au['old_value'], 'Формула') && !str_contains($au['old_value'], '980'), "$code " . json_encode($au, JSON_UNESCAPED_UNICODE));
db()->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetchAll();
// байты читает cat: свой open/close файла БД снял бы POSIX-блокировки SQLite этого процесса
check('удалённая заявка: «Формула» нет в байтах БД после checkpoint (secure_delete)',
    !str_contains((string)shell_exec('cat ' . escapeshellarg($dbPath) . '*'), 'Формула'));

$hits = function () {
    $st = db()->prepare("SELECT hits FROM stats WHERE day = ? AND page = '/' AND ref_domain = 'ref-test.example' AND ua_class = 'mobile'");
    $st->execute([gmdate('Y-m-d')]);
    return (int)$st->fetchColumn();
};
$ua = ['Referer: https://ref-test.example/some/path?q=1', 'User-Agent: TestUA (iPhone; Mobile)'];
req("$base/", null, '', $ua);
req("$base/", null, '', $ua);
check('GET / twice → hits=2', $hits() === 2, (string)$hits());
req("$base/privacy.php", null, '', ['Referer: http://127.0.0.1:8081/', 'User-Agent: Googlebot/2.1']);
check('свой Referer → ref_domain \'\', Googlebot → bot', (int)db()->query("SELECT hits FROM stats WHERE page = '/privacy.php' AND ref_domain = '' AND ua_class = 'bot'")->fetchColumn() === 1);
$cols = array_column(db()->query('PRAGMA table_info(stats)')->fetchAll(), 'name');
check('table_info(stats) no ip', $cols === ['day', 'page', 'ref_domain', 'ua_class', 'hits'] && !preg_grep('~ip|agent|cookie~i', $cols), implode(',', $cols));
[$code, $html] = req("$base/admin/?p=stats", null, $ed);
check('editor GET ?p=stats → 200, ref-test.example в источниках', $code === 200 && str_contains($html, 'ref-test.example'), (string)$code);
[$code] = req("$base/admin/?p=audit", null, $ed);
[$code2, $html] = req("$base/admin/?p=audit", null, $mgr);
check('audit: editor → 403, manager → 200', $code === 403 && $code2 === 200 && str_contains($html, 'fleet_prices'), "$code $code2");

// --- M6: consent.php POST (sendBeacon, text/plain)
$beacon = function (string $body, array $hdr) use ($base): int {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'content' => $body,
        'header' => ['Content-Type: text/plain;charset=UTF-8', ...$hdr]]]);
    file_get_contents("$base/consent.php", false, $ctx);
    preg_match('~^HTTP/\S+ (\d+)~', $http_response_header[0] ?? '', $m);
    return (int)($m[1] ?? 0);
};
$consentN = fn() => (int)db()->query("SELECT COALESCE(SUM(n), 0) FROM consent_stats")->fetchColumn();
$code = $beacon('c=accept&rev=1', []);
check('consent.php POST без Origin → 403', $code === 403 && $consentN() === 0, (string)$code);
$code2 = $beacon('c=accept&rev=1', ['Origin: http://evil.example']);
$code = $beacon('c=accept&rev=1', ['Origin: http://127.0.0.1:8081']);
$st = db()->prepare("SELECT n FROM consent_stats WHERE day = ? AND choice = 'accept' AND text_rev = '1'");
$st->execute([gmdate('Y-m-d')]);
$n = (int)$st->fetchColumn();
$st = null; // не держать открытый курсор (блокирует запись в БД)
check('POST text/plain "c=accept&rev=1" с Origin → consent_stats n=1', $code2 === 403 && $code === 204 && $n === 1 && $consentN() === 1, "$code2 $code");
$code = $beacon('c=maybe&rev=1', ['Origin: http://127.0.0.1:8081']);
check('consent.php c=maybe → 400', $code === 400 && $consentN() === 1, (string)$code);
[, $html] = req("$base/");
check('metrika_id пуст → нет #cookie-bar, mc.yandex, «Настройки cookie»', !str_contains($html, 'cookie-bar') && !str_contains($html, 'mc.yandex') && !str_contains($html, 'Настройки cookie'));
$set('metrika_id', '12345');
[, $html] = req("$base/");
[, $priv] = req("$base/privacy.php");
check('metrika_id=12345 → #cookie-bar hidden, ссылка «Настройки cookie», раздел в политике', str_contains($html, '<div id="cookie-bar" hidden')
    && str_contains($html, 'Настройки cookie') && str_contains($priv, 'подключается только после нажатия «Принять»'));
$set('metrika_id', '');

exit($fail ? 1 : 0);
