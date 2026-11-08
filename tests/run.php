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
$srv = proc_open([PHP_BINARY, '-d', 'upload_max_filesize=12M', '-d', 'post_max_size=13M', '-S', '127.0.0.1:8081', '-t', "$root/public", "$root/public/router.php"],
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
// multipart POST: поля + один файл photo → [код, тело, заголовки]
function reqFile(string $url, array $fields, string $bytes, string $mime, string $cookie = ''): array
{
    $b = bin2hex(random_bytes(8));
    $body = '';
    foreach ($fields as $k => $v) $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
    $body .= "--$b\r\nContent-Disposition: form-data; name=\"photo\"; filename=\"x\"\r\nContent-Type: $mime\r\n\r\n$bytes\r\n--$b--\r\n";
    $h = ["Content-Type: multipart/form-data; boundary=$b", ...($cookie !== '' ? ["Cookie: $cookie"] : [])];
    $res = file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST', 'header' => $h, 'content' => $body, 'ignore_errors' => true, 'follow_location' => 0]]));
    preg_match('~^HTTP/\S+ (\d+)~', $http_response_header[0] ?? '', $m);
    return [(int)($m[1] ?? 0), $res, $http_response_header ?? []];
}
$leads = fn() => (int)db()->query('SELECT COUNT(*) FROM leads')->fetchColumn();
$lead = ['source' => 'calc', 'name' => 'Тест', 'phone' => '+7 980 000-00-00', 'service' => 'Благоустройство', 'comment' => 'x', 'website' => ''];

// --- M2: снимок / до любых правок settings; посещение посадочных не меняет главную (эталон «до M2» — oracle.php)
[, $home0] = req("$base/");
[, $rent0] = req("$base/arenda-spetstekhniki/");
[, $mat0] = req("$base/shcheben-pesok-grunt/");
[, $home1] = req("$base/");
check('M2: / байт в байт тот же после посещения посадочных', $home0 !== '' && $home0 === $home1);
$at = fn(string $h, string $id): int|false => strpos($h, "id=\"$id\"");
check('порядок /: services < fleet < materials', $at($home0, 'services') !== false && $at($home0, 'services') < $at($home0, 'fleet') && $at($home0, 'fleet') < $at($home0, 'materials'));
check('порядок /arenda-spetstekhniki/: fleet < services', $at($rent0, 'fleet') !== false && $at($rent0, 'services') !== false && $at($rent0, 'fleet') < $at($rent0, 'services'));
check('порядок /shcheben-pesok-grunt/: materials < services', $at($mat0, 'materials') !== false && $at($mat0, 'services') !== false && $at($mat0, 'materials') < $at($mat0, 'services'));

// --- studio T01: маркер-гейт — в отрендеренном HTML публичных страниц (сид, asf/blago опубликованы фактом-заглушкой) нет маркеров черновика
$set = fn(string $k, string $v) => db()->prepare('UPDATE settings SET value = ? WHERE key = ?')->execute([$v, $k]);
$get = fn(string $k) => db()->query('SELECT value FROM settings WHERE key = ' . db()->quote($k))->fetchColumn();
$marks = fn(string $h): array => preg_match_all('~\[ПРОВЕРИТЬ|\[УКАЗАТЬ|\[NEED|TODO|(?i:lorem)|XXX~u', $h, $m) ? array_values(array_unique($m[0])) : [];
$f0 = [$get('fact_asphalt_works'), $get('fact_blago_works')];
$set('fact_asphalt_works', 'Дворы и проезды');
$set('fact_blago_works', 'Плитка');
$bad = [];
foreach (['/' => 200, '/arenda-spetstekhniki/' => 200, '/shcheben-pesok-grunt/' => 200, '/asfaltirovanie/' => 200, '/blagoustroystvo/' => 200,
    '/privacy.php' => 200, '/consent.php' => 200, '/nope/' => 404] as $u => $want) {
    [$code, $h] = req("$base$u");
    if ($code !== $want || $marks($h)) $bad[] = "$u $code " . implode(',', $marks($h));
}
check('маркер-гейт: /, обе посадочные, /asfaltirovanie/, /blagoustroystvo/, /privacy.php, /consent.php, 404 — без [ПРОВЕРИТЬ, [УКАЗАТЬ, [NEED, TODO, lorem, XXX', !$bad && $marks('[УКАЗАТЬ ИНН]') === ['[УКАЗАТЬ'], implode('; ', $bad));
$hp = $get('hero_p');
$set('hero_p', "$hp [ПРОВЕРИТЬ: тест]");
$caught = $marks(req("$base/")[1]);
$set('hero_p', $hp);
check('маркер-гейт: самопроверка — детектор поймал [ПРОВЕРИТЬ в hero_p, значение возвращено', $caught === ['[ПРОВЕРИТЬ'] && $get('hero_p') === $hp && !$marks(req("$base/")[1]), json_encode($caught, JSON_UNESCAPED_UNICODE));
$set('fact_asphalt_works', $f0[0]);
$set('fact_blago_works', $f0[1]);

// --- studio T40: реквизиты — заполненное выводится (подвал, privacy, consent); пустое — строки нет, заглушки нет, оператор ПДн назван
$rk = ['org_inn', 'org_ogrn', 'org_address', 'retention'];
$r0 = array_map($get, $rk);
$org = $get('org_name');
$legal = fn() => array_map(fn($u) => req("$base$u")[1], ['/' => '/', 'privacy' => '/privacy.php', 'consent' => '/consent.php']);
$set('retention', '1 год');
$full = $legal();
foreach ($rk as $k) $set($k, '');
$empty = $legal();
foreach ($rk as $i => $k) $set($k, $r0[$i]);
$bad = [];
foreach ($full as $u => $h) foreach (['5754023520', '1165749053689', 'Россия, 302023, г. Орёл, Силикатный пер., д. 1'] as $v) if (!str_contains($h, $v)) $bad[] = "сид $u: нет «{$v}»";
if (!str_contains($full['privacy'], 'хранятся 1 год, но не дольше')) $bad[] = 'privacy: срок хранения не выведен';
if (!str_contains($full['consent'], 'Согласие действует 1 год либо до его отзыва')) $bad[] = 'consent: срок не выведен';
foreach ($empty as $u => $h) {
    if ($m = $marks($h)) $bad[] = "пусто $u: " . implode(',', $m);
    foreach (['ИНН', 'ОГРН', 'Силикатный', 'дрес:'] as $v) if (str_contains($h, $v)) $bad[] = "пусто $u: есть «{$v}»";
}
if (!str_contains($empty['privacy'], "<strong>Оператор:</strong> $org<br>") || !str_contains($empty['privacy'], 'хранятся не дольше, чем')) $bad[] = 'пусто privacy: оператор не назван или фраза о сроке сломана';
if (!str_contains($empty['consent'], "<strong>$org</strong><br>") || !str_contains($empty['consent'], 'Согласие действует до достижения целей обработки либо до его отзыва')) $bad[] = 'пусто consent: оператор не назван или фраза о сроке сломана';
if (!str_contains($empty['/'], e($org))) $bad[] = 'пусто /: в подвале нет наименования';
check('T40 реквизиты: сид → ИНН/ОГРН/юр. адрес на /, privacy, consent; пустые ИНН/ОГРН/адрес/срок → строк нет, «[УКАЗАТЬ» нет, оператор назван; значения возвращены', !$bad && array_map($get, $rk) === $r0, implode('; ', $bad));

// --- studio T16+T18: в первом экране и цифрах — только класс A (strategy Р1.3–Р1.6); неподтверждённое снято с сида и всех публичных страниц
$hero = preg_match('~<section id="top".*?</section>~s', req("$base/")[1], $m) ? $m[0] : '';
$bad = [];
if ($hero === '' || str_contains($hero, 'data-count')) $bad[] = 'hero не найден или в нём счётчик data-count';
foreach (['Экскаватор-погрузчик от 3 500 ₽ в час, щебень и песок с доставкой, асфальт и благоустройство — смету считаем по объекту. Работаем в Орле и области.', '>от 3 500<', '>₽/ч, техника с НДС<', '>9<', '>видов техники<'] as $v)
    if (!str_contains($hero, $v)) $bad[] = "в hero нет «{$v}»";
foreach (['/', '/arenda-spetstekhniki/', '/shcheben-pesok-grunt/', '/privacy.php', '/consent.php'] as $u)
    if (preg_match_all('~15\+|[Бб]олее 15|5–10|Свой карьер~u', req("$base$u")[1], $m)) $bad[] = "$u: " . implode(',', array_unique($m[0]));
if ($n = (int)db()->query("SELECT COUNT(*) FROM items WHERE num = 15 AND suffix = '+'")->fetchColumn()) $bad[] = "items num=15 '+': $n";
check('T16+T18: hero без data-count, hero_p и цифры hero — факты класса A; «15+», «более 15», «5–10», «Свой карьер» нет на публичных страницах и в items', !$bad, implode('; ', $bad));

// --- send.php
[$code, $body] = req("$base/send.php", $lead);
check('lead without consent → 400', $code === 400 && json_decode($body, true) === ['ok' => false, 'error' => 'consent'], "$code $body");

[$code, $body] = req("$base/send.php", $lead + ['consent' => '1']);
$j = json_decode($body, true);
check('valid lead → count=1', $code === 200 && ($j['ok'] ?? null) === true && $leads() === 1, "$code $body");

[$code, $body] = req("$base/send.php", ['website' => 'http://spam'] + $lead + ['consent' => '1']);
check('honeypot → ok:true, count unchanged', $code === 200 && (json_decode($body, true)['ok'] ?? null) === true && $leads() === 1, "$code $body");

// --- экранирование (D5/D6)
db()->prepare('UPDATE materials SET title = ? WHERE sort = 1')->execute(["a');alert(1)//"]);
$fp = db()->query('SELECT name, model FROM fleet_prices WHERE sort = 1')->fetch();
db()->prepare('UPDATE fleet_prices SET name = ?, model = ? WHERE sort = 1')->execute(["b');alert(2)//", '']); // строка прайса — тоже openCalc (T13)
$set('ld_name', '</script><script>x');
$set('modal_call_text', "it's\nx");
[, $html] = req("$base/");

preg_match_all('~onclick="(openCalc\([^"]*)"~', $html, $m);
$ocs = array_map(fn($x) => html_entity_decode($x, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $m[1]);
$oc = implode(' | ', array_filter($ocs, fn($x) => str_contains($x, 'alert(')));
check("materials.title=\"a');alert(1)//\", fleet_prices.name=\"b');alert(2)//\" → html_entity_decode(onclick) contains \"openCalc('a\\x27);alert(1)//',\" and \"openCalc('b\\x27);alert(2)//',\", no openCalc onclick contains \"');\"",
    str_contains($oc, "openCalc('a\\x27);alert(1)//',") && str_contains($oc, "openCalc('b\\x27);alert(2)//',") && !array_filter($ocs, fn($x) => str_contains($x, "');")), $oc);
db()->prepare('UPDATE fleet_prices SET name = ?, model = ? WHERE sort = 1')->execute([$fp['name'], $fp['model']]);

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
check('Set-Cookie админки: path=/admin/, HttpOnly, SameSite=Lax (Strict ломает вход в iOS Safari из других приложений)',
    stripos($sc, 'path=/admin/') !== false && stripos($sc, 'HttpOnly') !== false && stripos($sc, 'SameSite=Lax') !== false, $sc);
check('owner login → 302, session id regenerated', $code === 302 && $boss !== '' && $boss !== $pre, "$code $pre → $boss");
[$code, , $hdr] = req("$base/admin/?p=leads", null, $boss);
$csp = implode("\n", preg_grep('~^Content-Security-Policy:~i', $hdr));
check("owner GET ?p=leads → 200, CSP default-src 'self'; frame-ancestors 'none'",
    $code === 200 && str_contains($csp, "default-src 'self'") && str_contains($csp, "frame-ancestors 'none'"), "$code $csp");
// T40: сид = прод, обязательные поля заполнены → баннера нет; пустой ИНН → баннер с «ИНН» и текстом «строки скрыты»
$inn0 = $get('org_inn');
$noBanner = !str_contains(req("$base/admin/", null, $boss)[1], 'Реквизиты не заполнены');
$set('org_inn', '');
$banner = req("$base/admin/", null, $boss)[1];
$set('org_inn', $inn0);
check('admin: баннер «Реквизиты не заполнены» — нет на полном сиде, есть при пустом ИНН (с «ИНН» и «строки с пустыми полями скрыты»)',
    $noBanner && preg_match('~Реквизиты не заполнены:</b> ИНН\.~u', $banner) && str_contains($banner, 'строки с пустыми полями скрыты') && $get('org_inn') === $inn0);

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
$lu = db()->query("SELECT locked_until FROM login_attempts WHERE login = 'victim'")->fetchColumn();
check('fails=5 (без locked_until) + верный пароль → 423 без verify, locked_until выставлен', $code === 423 && $lu !== null, "$code " . var_export($lu, true));
db()->exec("UPDATE login_attempts SET locked_until = datetime('now', '-1 second') WHERE login = 'victim'");
// заодно Ф4: хэш с устаревшим cost перехэшируется при входе, pw_ver не меняется
$h4 = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 4]);
db()->prepare("UPDATE users SET pass_hash = ? WHERE login = 'victim'")->execute([$h4]);
[$code] = $login('victim', $pw);
$vu = db()->query("SELECT pass_hash, pw_ver FROM users WHERE login = 'victim'")->fetch();
check('блокировка истекла → вход 302; cost 4 → перехэширован, pw_ver 0', $code === 302 && $vu['pass_hash'] !== $h4
    && !password_needs_rehash($vu['pass_hash'], PASSWORD_DEFAULT) && (int)$vu['pw_ver'] === 0, (string)$code);
[$c1] = $login('nobody2', "a\0b");
[$c2] = $login('boss', "a\0b");
check('password "a\\0b": неизвестный и известный логин → 200 «неверный», не 500', $c1 === 200 && $c2 === 200, "$c1 $c2");
check('create-user: пароль с NUL → отказ', $cli(['bin/create-user.php', 'nulluser', 'editor'], "correct horse\0battery\n") !== 0);
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

$inn0 = $val('org_inn');
[$code] = req("$base/admin/?p=settings&g=requisites&a=save", ['_csrf' => $csrfOf($ed), 'org_inn' => '1234567890'], $ed);
check('editor POST requisites → 403', $code === 403 && $val('org_inn') === $inn0, (string)$code);
[$code] = req("$base/admin/?p=settings&g=requisites", null, $ed);
check('editor GET requisites → 403', $code === 403, (string)$code);

$h1 = $val('hero_h1');
[$code] = req("$base/admin/?p=settings&g=hero&a=save", ['_csrf' => $csrfOf($mgr), 'hero_p' => 'x', 'hero_h1' => '<img src=x onerror=alert(1)>'], $mgr);
check('manager POST trusted key → 403', $code === 403 && $val('hero_h1') === $h1 && $val('hero_p') !== 'x', (string)$code);
[$code] = req("$base/admin/?p=settings&g=mail&a=save", ['_csrf' => $csrfOf($mgr), 'notify_email' => 'a@gmail.com'], $mgr);
check('manager POST notify_email → 403', $code === 403 && $val('notify_email') === '', (string)$code);
[$code] = req("$base/admin/?p=settings&g=contacts&a=save", ['_csrf' => $csrfOf($mgr), 'whatsapp' => 'javascript:alert(1)'], $mgr);
check('manager POST whatsapp "javascript:…" → 422', $code === 422 && $val('whatsapp') === 'https://wa.me/79807691111', (string)$code);

$drafts = fn() => (int)db()->query('SELECT COUNT(*) FROM drafts')->fetchColumn();
$lastAudit = fn() => db()->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
$publish = fn(string $c) => req("$base/admin/?p=draft&a=publish", ['_csrf' => $csrfOf($c)], $c);
$row = db()->query("SELECT * FROM fleet_prices WHERE sort = 1")->fetch();
$n0 = $audits();
[$code] = req("$base/admin/?p=content&t=fleet_prices&a=save", ['_csrf' => $csrfOf($boss), 'id' => $row['id'], 'name' => $row['name'],
    'model' => $row['model'], 'price_kind' => 'fixed', 'price' => '99999', 'unit' => $row['unit']], $boss);
check('owner save price → 302, в черновик: audit нет, на / прежняя цена', $code === 302 && $audits() === $n0 && $drafts() === 1
    && !str_contains(req("$base/")[1], '99 999 ₽'), (string)$code);
[$code] = $publish($boss);
$au = $lastAudit();
check('owner publish price → audit row', $code === 302 && $audits() === $n0 + 1 && $au['tbl'] === 'fleet_prices' && $au['row_key'] === (string)$row['id']
    && json_decode($au['old_value'], true) === ['price' => 36000] && json_decode($au['new_value'], true) === ['price' => '99999'], "$code " . json_encode($au, JSON_UNESCAPED_UNICODE));
check('price 99999 → на / «99 999 ₽»', str_contains(req("$base/")[1], '99 999 ₽'));

$sv = db()->query('SELECT * FROM services WHERE sort = 2')->fetch();
[$code] = req("$base/admin/?p=content&t=services&a=save", ['_csrf' => $csrfOf($ed), 'id' => $sv['id'], 'title' => $sv['title'], 'text' => $sv['text'],
    'href' => 'javascript:x', 'in_form' => '1', 'in_footer' => '1'], $ed);
check('href "javascript:x" через POST админки → 422', $code === 422
    && db()->query("SELECT href FROM services WHERE id = {$sv['id']}")->fetchColumn() === $sv['href'], (string)$code);
[$code] = req("$base/admin/?p=content&t=fleet_cards&a=save", ['_csrf' => $csrfOf($ed), 'id' => 1, 'title' => 'x', 'img' => 'assets/../../var/x.webp',
    'alt' => 'x', 'img_pos' => '50% 50%', 'price_kind' => 'contract', 'price' => '', 'unit' => ''], $ed);
check('img "assets/../../var/x.webp" → 422, черновик не создан', $code === 422 && $drafts() === 0, (string)$code);
$firstMarquee = fn() => db()->query("SELECT title FROM items WHERE kind='marquee' ORDER BY sort LIMIT 1")->fetchColumn();
[$code] = req("$base/admin/?p=content&t=marquee&a=sort", ['_csrf' => $csrfOf($ed), 'id' => db()->query("SELECT id FROM items WHERE kind='marquee' AND sort=2")->fetchColumn(), 'dir' => 'up'], $ed);
check('editor sort marquee #2 up → 302, в черновике первый, вживую нет', $code === 302 && $firstMarquee() === 'Строительство дорог'
    && preg_match('~value="([^"]+)"~', explode('name="title"', req("$base/admin/?p=content&t=marquee", null, $ed)[1])[1] ?? '', $m) && $m[1] === 'Благоустройство территорий', (string)$code);
$publish($mgr);
check('manager publish → marquee: «Благоустройство территорий» стал первым', $firstMarquee() === 'Благоустройство территорий' && $drafts() === 0);

// --- M8/M1: черновик
$oldDb = dirname($dbPath) . '/old-' . getmypid() . '.sqlite';
$o = new PDO("sqlite:$oldDb");
$o->exec(file_get_contents("$root/db/schema.sql"));
$o->exec('DROP TABLE drafts; DROP TABLE trash');
$o = null;
$p = proc_open([PHP_BINARY, '-r', "require 'app/bootstrap.php'; echo implode(',', array_column(rows(\"SELECT name FROM sqlite_master WHERE name IN ('drafts', 'trash', 'trash_at') ORDER BY name\"), 'name'));"],
    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, $root, ['RDS_DB' => $oldDb] + getenv());
$out = stream_get_contents($pp[1]);
proc_close($p);
array_map('unlink', glob("$oldDb*"));
check('миграция: старая БД без drafts/trash → drafts, trash, trash_at', $out === 'drafts,trash,trash_at', $out);

// --- ADR-0009 M1: ключи посадочных, анкета, коды вебмастеров
$keys = "SELECT (SELECT COUNT(*) FROM settings WHERE key LIKE 'pg\\_%' ESCAPE '\\' AND grp = 'pages') || ',' || (SELECT COUNT(*) FROM settings WHERE key LIKE 'fact\\_%' ESCAPE '\\' AND grp = 'facts' AND required = 0 AND value = '')"
    . " || ',' || (SELECT COUNT(*) FROM settings WHERE key IN ('yandex_verification', 'google_verification') AND grp = 'metrika' AND value = '')";
check('свежая БД: 16 pg_*, 13 fact_*, 2 *_verification', db()->query($keys)->fetchColumn() === '16,13,2', db()->query($keys)->fetchColumn());
$o = new PDO("sqlite:$oldDb");
$o->exec(file_get_contents("$root/db/schema.sql")); // схема без сида: settings пуста
$o = null;
$p = proc_open([PHP_BINARY, '-r', 'require "app/bootstrap.php"; echo db()->query($argv[1])->fetchColumn();', $keys],
    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, $root, ['RDS_DB' => $oldDb] + getenv());
$out = stream_get_contents($pp[1]);
proc_close($p);
array_map('unlink', glob("$oldDb*"));
check('миграция: старая БД без pg_* → 16 pg_*, 13 fact_*, 2 *_verification', $out === '16,13,2', $out);
$set('pg_rent_h1', 'Правка владельца');
$snap = fn() => json_encode(db()->query('SELECT * FROM settings ORDER BY key')->fetchAll(), JSON_UNESCAPED_UNICODE);
$s0 = $snap();
db()->exec(file_get_contents("$root/db/seed-pages.sql"));
check('повторный seed-pages.sql ничего не меняет (правка pg_rent_h1 цела)', $snap() === $s0 && str_contains($s0, 'Правка владельца'));
$set('pg_rent_h1', 'Аренда спецтехники в Орле и Орловской области');
[$code, $html] = req("$base/admin/?p=settings&g=pages", null, $ed);
check('editor g=pages → 200, статус «/asfaltirovanie/ не опубликована — заполните: …», rent опубликована', $code === 200
    && str_contains($html, '/asfaltirovanie/ не опубликована — заполните: Какие асфальтовые работы')
    && str_contains($html, '/arenda-spetstekhniki/ опубликована') && str_contains($html, '>Посадочные<') && !str_contains($html, '>Анкета<'), (string)$code);
[$code] = req("$base/admin/?p=settings&g=facts", null, $ed);
[$code2, $html] = req("$base/admin/?p=settings&g=facts", null, $mgr);
check('g=facts → 403 у editor, 200 у manager со статусом страниц', $code === 403 && $code2 === 200
    && str_contains($html, '/blagoustroystvo/ не опубликована — заполните: Что входит в благоустройство'), "$code $code2");
[$code] = req("$base/admin/?p=settings&g=metrika", null, $mgr);
[$code2] = req("$base/admin/?p=settings&g=metrika&a=save", ['_csrf' => $csrfOf($boss), 'yandex_verification' => 'a"b'], $boss);
$yv = fn() => db()->query("SELECT value FROM settings WHERE key = 'yandex_verification'")->fetchColumn();
check('g=metrika: manager → 403; owner yandex_verification=\'a"b\' → 422, не сохранено', $code === 403 && $code2 === 422 && $yv() === '', "$code $code2");
[$code] = req("$base/admin/?p=settings&g=metrika&a=save", ['_csrf' => $csrfOf($boss), 'yandex_verification' => 'abc_DEF-123'], $boss);
check('owner yandex_verification=abc_DEF-123 → 302, сохранено', $code === 302 && $yv() === 'abc_DEF-123', (string)$code);
$set('yandex_verification', '');
[$code] = req("$base/admin/?p=settings&g=facts&a=save", ['_csrf' => $csrfOf($mgr), 'fact_zone' => str_repeat('я', 501)], $mgr);
check('fact_zone 501 символ → 422', $code === 422 && db()->query("SELECT value FROM settings WHERE key = 'fact_zone'")->fetchColumn() === '', (string)$code);

$sv = db()->query('SELECT * FROM services ORDER BY sort, id LIMIT 1')->fetch();
$svPost = ['id' => $sv['id'], 'title' => 'M1 черновик', 'text' => $sv['text'], 'href' => $sv['href'], 'in_form' => $sv['in_form'], 'in_footer' => $sv['in_footer']];
[$code] = req("$base/admin/?p=content&t=services&a=save", ['_csrf' => $csrfOf($ed)] + $svPost, $ed);
[, $html] = req("$base/admin/?p=content", null, $ed);
check('editor save → 302, / без изменений, N=1, у editor нет «Опубликовать»', $code === 302 && !str_contains(req("$base/")[1], 'M1 черновик') && $drafts() === 1
    && str_contains($html, 'value="M1 черновик"') && str_contains($html, '<b>1 изменение</b> · redactor')
    && str_contains($html, 'Изменения ждут публикации — опубликует владелец или менеджер') && !str_contains($html, 'a=publish'), (string)$code);
[, $html] = req("$base/admin/?p=content", null, $boss);
check('owner видит «Опубликовать» и «Отменить всё» с подтверждением', str_contains($html, 'a=publish') && str_contains($html, 'data-confirm="Отменить все 1 изменение? Сайт останется как сейчас"'));
$n0 = $audits();
[$code] = $publish($ed);
check('editor publish (с CSRF) → 403, черновик цел, / без изменений', $code === 403 && $drafts() === 1 && $audits() === $n0 && !str_contains(req("$base/")[1], 'M1 черновик'), (string)$code);
[$code, , $hdr] = $publish($mgr);
$au = $lastAudit();
check('manager publish → 302 ok=published, / изменён, drafts пуст, audit', $code === 302 && in_array('Location: /admin/?p=content&ok=published', $hdr, true)
    && str_contains(req("$base/")[1], 'M1 черновик') && $drafts() === 0 && $audits() === $n0 + 1 && $au['tbl'] === 'services' && $au['row_key'] === (string)$sv['id']
    && json_decode($au['old_value'], true) === ['title' => $sv['title']] && json_decode($au['new_value'], true) === ['title' => 'M1 черновик'], "$code " . json_encode($au, JSON_UNESCAPED_UNICODE));
req("$base/admin/?p=content&t=services&a=save", ['_csrf' => $csrfOf($ed), 'title' => 'M1 отмена'] + $svPost, $ed);
[$code] = req("$base/admin/?p=draft&a=discard", ['_csrf' => $csrfOf($boss)], $boss);
$au = $lastAudit();
$home = req("$base/")[1];
check('owner discard → 302, / без изменений, drafts пуст, audit discard', $code === 302 && $drafts() === 0 && !str_contains($home, 'M1 отмена')
    && str_contains($home, 'M1 черновик') && $au['tbl'] === 'drafts' && $au['row_key'] === 'discard', "$code " . json_encode($au, JSON_UNESCAPED_UNICODE));

// --- M8/M5: превью черновика
[$code, , $hdr] = req("$base/admin/?p=preview");
check('превью без сессии → 302 на login', $code === 302 && in_array('Location: /admin/?p=login', $hdr, true), (string)$code);
req("$base/admin/?p=content&t=services&a=save", ['_csrf' => $csrfOf($ed), 'title' => 'M5 превью <b>x</b>'] + $svPost, $ed);
[$code, $html, $hdr] = req("$base/admin/?p=preview", null, $ed);
$csp = implode("\n", preg_grep('~^Content-Security-Policy:~i', $hdr));
check('превью editor → 200: заголовок из черновика (экранирован), <base href="/">, полоска, CSP script-src \'none\'', $code === 200
    && str_contains($html, 'M5 превью &lt;b&gt;x&lt;/b&gt;') && str_contains($html, '<head><base href="/">')
    && str_contains($html, 'Это черновик — посетители видят прежнюю версию') && str_contains($csp, "script-src 'none'") && str_contains($csp, "form-action 'none'"), "$code $csp");
check('/ не содержит заголовок черновика', !str_contains(req("$base/")[1], 'M5 превью'));
check('плашка: ссылка «Посмотреть страницу» на ?p=preview', str_contains(req("$base/admin/?p=content", null, $ed)[1], '<a href="/admin/?p=preview" target="_blank">Посмотреть страницу</a>'));
req("$base/admin/?p=draft&a=discard", ['_csrf' => $csrfOf($boss)], $boss);
check('превью без черновика → живые данные', str_contains(req("$base/admin/?p=preview", null, $ed)[1], 'M1 черновик') && $drafts() === 0);

// --- M8/M2: добавить/удалить, min/max, корзина, undo, revert
$dj = fn() => json_decode((string)db()->query('SELECT data FROM drafts')->fetchColumn(), true);
$loc = fn(array $h): string => trim(substr((string)current(preg_grep('~^Location:~i', $h) ?: ['']), 9));
$content = fn(string $t, string $a, array $p, string $c) => req("$base/admin/?p=content&t=$t&a=$a", ['_csrf' => $csrfOf($c)] + $p, $c);
$imgOf = fn(string $tbl) => db()->query("SELECT img FROM $tbl LIMIT 1")->fetchColumn();
// валидная новая строка для каждого из 10 списков (заголовок — уникальная метка)
$newRow = fn(string $t, string $title) => match ($t) {
    'services' => ['title' => $title, 'text' => 'Текст', 'href' => '#services', 'in_form' => '1', 'in_footer' => '0'],
    'fleet_cards' => ['title' => $title, 'img' => $imgOf('fleet_cards'), 'alt' => 'Фото', 'img_pos' => '50% 50%', 'price_kind' => 'contract', 'price' => '', 'unit' => ''],
    'fleet_prices' => ['name' => $title, 'model' => '', 'price_kind' => 'fixed', 'price' => '1000', 'unit' => 'смена', 'is_sub' => '0'],
    'materials' => ['title' => $title, 'text' => 'Текст', 'img' => $imgOf('materials'), 'alt' => 'Фото', 'badge' => '', 'price_kind' => 'contract', 'price' => '', 'unit' => ''],
    'nav' => ['title' => $title, 'href' => '#services'],
    'marquee' => ['title' => $title],
    'stat_hero', 'stat_bar' => ['title' => $title, 'text' => '', 'num' => '7', 'suffix' => ''],
    'why', 'step' => ['title' => $title, 'text' => 'Текст'],
};
$L = ['services', 'fleet_cards', 'fleet_prices', 'materials', 'nav', 'marquee', 'stat_hero', 'stat_bar', 'why', 'step'];
// M3: в сиде меню занимает 64 из 66 (правило шапки) — шестой пункт не влезет; укорачиваем живые названия до 4 символов
db()->exec("UPDATE items SET title = substr(title, 1, 4) WHERE kind = 'nav'");
$ids = [];
foreach ($L as $t) {
    $n0 = count($dj()[$t] ?? []) ?: count(db()->query("SELECT 1 FROM " . (in_array($t, ['services', 'fleet_cards', 'fleet_prices', 'materials'], true) ? $t : "items WHERE kind = '$t'"))->fetchAll());
    [$code, , $hdr] = $content($t, 'save', ['id' => ''] + $newRow($t, "M2 новое $t"), $ed);
    $l = $dj()[$t];
    $ids[$t] = (int)end($l)['id'];
    check("editor add $t → 302 &rev, в черновике N+1, последняя — новая", $code === 302 && preg_match("~ok=saved&rev=\d+$~", $loc($hdr)) && count($l) === $n0 + 1
        && (end($l)['title'] ?? end($l)['name']) === "M2 новое $t", "$code {$loc($hdr)} " . count($l) . " vs $n0");
}
$maxId = (int)db()->query('SELECT MAX(id) FROM items')->fetchColumn();
check('новые id items: уникальны по всем kind и > max живой', count(array_unique(array_intersect_key($ids, array_flip(['nav', 'marquee', 'stat_hero', 'stat_bar', 'why', 'step'])))) === 6
    && min($ids['nav'], $ids['why'], $ids['step']) > $maxId, json_encode($ids));
[, $html] = req("$base/admin/?p=content&t=services", null, $ed);
check('content: бейдж «новое», форма «Добавить», ссылка на корзину, удаление с data-confirm', str_contains($html, '<span class="badge">новое</span>')
    && str_contains($html, '>Добавить</button>') && str_contains($html, 'href="/admin/?p=trash"') && str_contains($html, 'data-confirm="Удалить «M2 новое services»?"'));
[$code] = $content('nav', 'save', ['id' => ''] + $newRow('nav', 'M2 седьмой'), $ed);
check('add nav сверх max 6 → 422, черновик не изменён', $code === 422 && count($dj()['nav']) === 6, (string)$code);
$publish($boss);
$home = req("$base/")[1];
check('publish → все 10 новых строк на /', array_filter($L, fn($t) => !str_contains($home, "M2 новое $t")) === [] && $drafts() === 0,
    implode(',', array_filter($L, fn($t) => !str_contains($home, "M2 новое $t"))));
foreach ($L as $t) {
    [$code] = $content($t, 'delete', ['id' => $ids[$t]], $ed);
    check("editor delete $t → 302, в черновике нет", $code === 302 && !in_array($ids[$t], array_map('intval', array_column($dj()[$t], 'id')), true), (string)$code);
}
[, $html] = req("$base/admin/?p=content&t=marquee", null, $ed);
check('content: удалённая строка с бейджем «удалено» и «Вернуть»', str_contains($html, '<span class="badge">удалено</span> <span class="fields">M2 новое marquee</span>') && str_contains($html, 'a=revert'));
check('до publish удалённые ещё на /', str_contains(req("$base/")[1], 'M2 новое why'));
$publish($mgr);
$home = req("$base/")[1];
$tr = array_column(db()->query('SELECT t, row FROM trash')->fetchAll(), 'row', 't');
check('publish delete → нет на /, строки в trash, audit(old, null)', array_filter($L, fn($t) => str_contains($home, "M2 новое $t")) === [] && count($tr) === 10
    && (int)json_decode($tr['why'], true)['id'] === $ids['why'] && $lastAudit()['new_value'] === null, implode(',', array_keys($tr)));
[$code] = $content('services', 'save', ['id' => ''] + $newRow('services', 'M2 после корзины'), $ed);
check('новый id не переиспользует id из корзины', (int)end($dj()['services'])['id'] > $ids['services']);
req("$base/admin/?p=draft&a=discard", ['_csrf' => $csrfOf($boss)], $boss);

// delete last → 422 (stat_hero: удаляем все, кроме одного)
$sh = array_map('intval', array_column($dj() ? $dj()['stat_hero'] : db()->query("SELECT id FROM items WHERE kind = 'stat_hero' ORDER BY sort")->fetchAll(), 'id'));
foreach (array_slice($sh, 1) as $id) $content('stat_hero', 'delete', ['id' => $id], $ed);
[$code, $html] = $content('stat_hero', 'delete', ['id' => $sh[0]], $ed);
check('delete последнего → 422 «хотя бы один», строка цела', $code === 422 && count($dj()['stat_hero']) === 1 && str_contains($html, 'хотя бы один'), (string)$code);
req("$base/admin/?p=draft&a=discard", ['_csrf' => $csrfOf($boss)], $boss);

// корзина: restore → discard → строка ещё в trash; restore дважды → одна копия; publish → на /, из trash удалено; -31 день → вычищено
$trWhy = (int)db()->query("SELECT id FROM trash WHERE t = 'why'")->fetchColumn();
$inTrash = fn() => (bool)db()->query("SELECT 1 FROM trash WHERE id = $trWhy")->fetchColumn();
$restore = fn() => req("$base/admin/?p=trash&a=restore", ['_csrf' => $csrfOf($ed), 'id' => $trWhy], $ed)[0];
[, $html] = req("$base/admin/?p=trash", null, $ed);
check('editor GET ?p=trash → «M2 новое why» с «Восстановить»', str_contains($html, 'M2 новое why') && str_contains($html, 'Восстановить'));
$code = $restore();
$au = $lastAudit();
check('restore из trash → 302, в конце черновика, в trash ещё есть, audit restore', $code === 302 && (int)end($dj()['why'])['id'] === $ids['why']
    && $inTrash() && $au['tbl'] === 'trash' && $au['row_key'] === 'restore', (string)$code);
req("$base/admin/?p=draft&a=discard", ['_csrf' => $csrfOf($boss)], $boss);
check('restore → «Отменить всё» → строка всё ещё в trash', $inTrash());
$restore();
$code = $restore();
[, $html] = req("$base/admin/?p=trash", null, $ed);
check('restore дважды → в черновике одна копия, в корзине «уже в черновике»', $code === 302
    && count(array_filter($dj()['why'], fn($r) => (int)$r['id'] === $ids['why'])) === 1 && str_contains($html, '<span class="badge">уже в черновике</span>'), (string)$code);
$content('why', 'save', ['id' => ''] + $newRow('why', 'M2 fix why'), $ed);
$nid = (int)end($dj()['why'])['id'];
check('restore без publish: новый id не совпадает с восстановленным', $nid > $ids['why'], "$nid vs {$ids['why']}");
$content('why', 'revert', ['id' => $nid], $ed);
[$code] = $publish($boss);
check('restore → publish → на /, строки в trash нет', str_contains(req("$base/")[1], 'M2 новое why') && !$inTrash(), "$code");
db()->exec("UPDATE trash SET deleted_at = datetime('now', '-31 days') WHERE t = 'step'");
db()->exec("UPDATE trash SET deleted_at = datetime('now', '-29 days') WHERE t = 'nav'");
req("$base/admin/?p=trash", null, $ed);
check('trash −31 день → вычищен при открытии корзины, −29 — остался', !db()->query("SELECT 1 FROM trash WHERE t = 'step'")->fetchColumn()
    && db()->query("SELECT 1 FROM trash WHERE t = 'nav'")->fetchColumn());
// «Вернуть» неопубликованное удаление из корзины
$st1 = db()->query("SELECT id, title FROM items WHERE kind = 'step' ORDER BY sort LIMIT 1")->fetch();
$content('step', 'delete', ['id' => $st1['id']], $ed);
[, $html] = req("$base/admin/?p=trash", null, $ed);
[$code] = req("$base/admin/?p=trash&a=restore", ['_csrf' => $csrfOf($ed), 't' => 'step', 'id' => $st1['id']], $ed);
check('корзина: удалённое в черновике → «Вернуть» → снова в черновике на своём месте', str_contains($html, e($st1['title'])) && $code === 302
    && (int)$dj()['step'][0]['id'] === (int)$st1['id']);
req("$base/admin/?p=draft&a=discard", ['_csrf' => $csrfOf($boss)], $boss);

// undo и revert
$mq = db()->query("SELECT * FROM items WHERE kind = 'marquee' ORDER BY sort LIMIT 1")->fetch();
[, , $hdr] = $content('marquee', 'save', ['id' => $mq['id'], 'title' => 'M2 undo-1'], $ed);
preg_match('~rev=(\d+)~', $loc($hdr), $m1);
[, , $hdr] = $content('marquee', 'save', ['id' => $mq['id'], 'title' => 'M2 undo-2'], $boss);
preg_match('~rev=(\d+)~', $loc($hdr), $m2);
[, $html] = req("$base/admin/?p=content&t=marquee&ok=saved&rev=$m2[1]", null, $boss);
check('тост «Сохранено» с формой «Отменить» (POST a=undo, rev)', str_contains($html, 'a=undo') && str_contains($html, "name=\"rev\" value=\"$m2[1]\"") && str_contains($html, '>Отменить</button>'));
[$code] = $content('marquee', 'undo', ['rev' => $m1[1]], $ed);
check('undo с устаревшим rev → 409, черновик не тронут', $code === 409 && $dj()['marquee'][0]['title'] === 'M2 undo-2', (string)$code);
[$code, , $hdr] = $content('marquee', 'undo', ['rev' => $m2[1]], $boss);
check('undo с текущим rev → 302, data = prev', $code === 302 && $dj()['marquee'][0]['title'] === 'M2 undo-1' && str_contains($loc($hdr), 'ok=undone'), (string)$code);
[$code] = $content('marquee', 'undo', ['rev' => $m2[1] + 1], $boss);
check('повторный undo (prev = NULL) → 409', $code === 409 && $dj()['marquee'][0]['title'] === 'M2 undo-1', (string)$code);
// F1: rev не повторяется после publish — старое «Отменить» не стирает правку, сделанную после публикации
$publish($boss); // чистый черновик: обе правки ниже — первые в своём черновике
[, , $hdr] = $content('marquee', 'save', ['id' => $mq['id'], 'title' => 'F1 до публикации'], $ed);
preg_match('~rev=(\d+)~', $loc($hdr), $f1);
$publish($boss);
[, , $hdr] = $content('marquee', 'save', ['id' => $mq['id'], 'title' => 'F1 после публикации'], $boss);
[$code] = $content('marquee', 'undo', ['rev' => $f1[1]], $ed);
check('undo со старым rev после publish → 409, новая правка цела', $code === 409 && $dj()['marquee'][0]['title'] === 'F1 после публикации', (string)$code);
$content('marquee', 'save', ['id' => $mq['id'], 'title' => $mq['title']], $boss);
$publish($boss);
$content('marquee', 'save', ['id' => $mq['id'], 'title' => 'M2 undo-1'], $ed); // состояние до блока F1
[, $html] = req("$base/admin/?p=content&t=marquee", null, $ed);
check('бейдж «изменено» и «Вернуть как было»', str_contains($html, '<span class="badge">изменено</span>') && str_contains($html, 'Вернуть как было'));
[$code] = $content('marquee', 'revert', ['id' => $mq['id']], $ed);
check('revert (editor) → 302, строка = опубликованная, N=0', $code === 302 && $dj()['marquee'][0]['title'] === $mq['title']
    && str_contains(req("$base/admin/?p=content&t=marquee", null, $ed)[1], '<b>0 изменений</b>'), (string)$code);
$content('marquee', 'save', ['id' => '', 'title' => 'M2 revert new'], $ed);
$nid = (int)end($dj()['marquee'])['id'];
$content('marquee', 'revert', ['id' => $nid], $ed);
check('revert новой строки → убрана из черновика', !in_array($nid, array_map('intval', array_column($dj()['marquee'], 'id')), true));
req("$base/admin/?p=draft&a=discard", ['_csrf' => $csrfOf($boss)], $boss);

// --- R2: «Что изменено» в плашке, автор строки (_by), «Опубликовать выбранное»
$edId = (int)db()->query("SELECT id FROM users WHERE login = 'redactor'")->fetchColumn();
$stp = db()->query("SELECT * FROM items WHERE kind = 'step' ORDER BY sort DESC, id DESC LIMIT 1")->fetch();
$content('step', 'save', ['id' => $stp['id'], 'title' => 'R2 этап', 'text' => $stp['text']], $ed);
$content('services', 'save', ['title' => 'R2 услуга'] + $svPost, $boss);
[, $html] = req("$base/admin/?p=content", null, $boss);
[, $edHtml] = req("$base/admin/?p=content", null, $ed);
check('(f) плашка owner: «Что изменено (2)», строки с авторами, чекбоксы, ссылка на строку; editor — без чекбоксов и публикации',
    str_contains($html, '<summary>Что изменено (2)</summary>') && str_contains($html, '>Этапы работы: R2 этап</a> — изменено · redactor')
    && str_contains($html, '>Услуги: R2 услуга</a> — изменено · boss') && str_contains($html, "name=\"pick[]\" value=\"step:{$stp['id']}\" checked")
    && str_contains($html, "name=\"pick[]\" value=\"services:{$sv['id']}\" checked") && str_contains($html, "href=\"/admin/?p=content&amp;t=step#r{$stp['id']}\"")
    && str_contains($html, '>Опубликовать выбранное</button>') && str_contains(req("$base/admin/?p=content&t=step", null, $boss)[1], "id=\"r{$stp['id']}\"")
    && str_contains($edHtml, '>Этапы работы: R2 этап</a> — изменено · redactor') && !str_contains($edHtml, 'pick[]') && !str_contains($edHtml, 'a=publish'));
check('П20: главная «Опубликовать» при чужой правке спрашивает подтверждение с автором и строкой',
    str_contains($html, 'action="/admin/?p=draft&amp;a=publish" data-confirm="Опубликовать и чужие правки: redactor — Этапы работы: R2 этап?'));
$pickPub = fn(string $c, array $pick) => req("$base/admin/?p=draft&a=publish", ['_csrf' => $csrfOf($c), 'picked' => '1', 'pick' => $pick], $c);
$n0 = $audits();
[$code] = $pickPub($ed, ["services:{$sv['id']}"]);
check('(e) editor publish с pick → 403, / без изменений', $code === 403 && !str_contains(req("$base/")[1], 'R2 услуга') && $audits() === $n0, (string)$code);
[$code, $html] = $pickPub($boss, ['services:999999', "step:{$stp['id']}x", ['x']]);
$home = req("$base/")[1];
check('(c) pick без совпадений → 422 «Нечего публиковать», / и черновик целы', $code === 422 && str_contains($html, 'Нечего публиковать')
    && !str_contains($home, 'R2 услуга') && !str_contains($home, 'R2 этап') && $audits() === $n0 && $dj()['step'] && $drafts() === 1, (string)$code);
[$code] = $pickPub($boss, []);
check('picked без единой галочки → 422, а не «опубликовать всё»', $code === 422 && !str_contains(req("$base/")[1], 'R2 услуга'), (string)$code);
[$code, , $hdr] = $pickPub($boss, ["services:{$sv['id']}"]);
$home = req("$base/")[1];
$au = $lastAudit();
[, $html] = req("$base/admin/?p=content", null, $boss);
check('(a) owner publish pick=[services] → / новая услуга, старый этап; черновик остался, 1 изменение, _by step = editor', $code === 302
    && str_contains($loc($hdr), 'ok=published') && str_contains($home, 'R2 услуга') && !str_contains($home, 'R2 этап') && str_contains($home, e($stp['title']))
    && $drafts() === 1 && str_contains($html, '<b>1 изменение</b>') && str_contains($html, '<summary>Что изменено (1)</summary>')
    && ($dj()['_by']['step'][$stp['id']] ?? null) === $edId && !isset($dj()['_by']['services'][$sv['id']])
    && $audits() === $n0 + 1 && $au['tbl'] === 'services' && $au['row_key'] === (string)$sv['id'], "$code " . json_encode($dj()['_by'] ?? null));
check('после выборочной публикации плашка подписана автором оставшейся правки (redactor), а не публиковавшим', str_contains($html, '<b>1 изменение</b> · redactor,'));
[$code] = $publish($boss);
check('(b) потом publish всего → / новый этап, drafts пуст', $code === 302 && str_contains(req("$base/")[1], 'R2 этап') && $drafts() === 0, (string)$code);
$content('services', 'save', ['title' => 'R2 своя'] + $svPost, $boss);
[, $html] = req("$base/admin/?p=content", null, $boss);
check('П20: только свои правки — «Опубликовать» без подтверждения', str_contains($html, '<form method="post" action="/admin/?p=draft&amp;a=publish">'));
req("$base/admin/?p=draft&a=discard", ['_csrf' => $csrfOf($boss)], $boss);
// (d) выборочная публикация удаления → строка в trash; неотмеченная правка остаётся черновиком
$wh = db()->query("SELECT * FROM items WHERE kind = 'why' ORDER BY sort DESC, id DESC LIMIT 1")->fetch();
$content('why', 'delete', ['id' => $wh['id']], $ed);
$content('marquee', 'save', ['id' => $mq['id'], 'title' => 'R2 строка'], $ed);
check('_by: delete и save помечают строки автором', ($dj()['_by']['why'][$wh['id']] ?? null) === $edId && ($dj()['_by']['marquee'][$mq['id']] ?? null) === $edId);
[$code] = $pickPub($mgr, ["why:{$wh['id']}"]);
$home = req("$base/")[1];
check('(d) publish pick=[удаление why] → нет на /, в trash; правка marquee — в черновике', $code === 302 && !str_contains($home, e($wh['title']))
    && (bool)db()->query("SELECT 1 FROM trash WHERE t = 'why' AND json_extract(row, '$.id') = {$wh['id']}")->fetchColumn()
    && !str_contains($home, 'R2 строка') && $dj()['marquee'][0]['title'] === 'R2 строка' && array_keys($dj()['_by']['why'] ?? []) === [], (string)$code);
$content('marquee', 'revert', ['id' => $mq['id']], $ed);
check('revert снимает автора строки', !isset($dj()['_by']['marquee'][$mq['id']]));
req("$base/admin/?p=draft&a=discard", ['_csrf' => $csrfOf($boss)], $boss);

// --- M8/M3: таблица полей — лимиты формы = лимиты сервера (значения — из ADR, независимо от lists()), ошибки у поля, повторная проверка при publish
$lim = [
    'services' => ['title' => 40, 'text' => 200, 'href' => 100],
    'fleet_cards' => ['title' => 40, 'img' => 100, 'alt' => 120, 'img_pos' => 9, 'price' => 6, 'unit' => 8],
    'fleet_prices' => ['name' => 40, 'model' => 30, 'price' => 6, 'unit' => 12],
    'materials' => ['title' => 30, 'text' => 150, 'img' => 100, 'alt' => 120, 'badge' => 14, 'price' => 6, 'unit' => 8],
    'nav' => ['title' => 16, 'href' => 100],
    'marquee' => ['title' => 40],
    'stat_hero' => ['title' => 22, 'text' => 8, 'num' => 4, 'suffix' => 3],
    'stat_bar' => ['title' => 22, 'text' => 7, 'num' => 4, 'suffix' => 3],
    'why' => ['title' => 30, 'text' => 160],
    'step' => ['title' => 24, 'text' => 120],
];
$num = ['price', 'num'];
// значение длины n: цифры для чисел, «#a…» для ссылки, «100% 1000%» для позиции, иначе кириллица
$fill = fn(string $f, int $n) => match (true) {
    in_array($f, $num, true) => str_repeat('9', $n),
    $f === 'href' => '#' . str_repeat('a', $n - 1),
    $f === 'img_pos' => $n === 9 ? '100% 100%' : '100% 1000%',
    $f === 'img' => 'assets/' . str_repeat('a', $n - 12) . '.webp',
    default => str_repeat('Ж', $n),
};
$first = fn(string $t) => (int)(($dj() ?: [])[$t][0]['id'] ?? db()->query("SELECT id FROM " . (in_array($t, ['services', 'fleet_cards', 'fleet_prices', 'materials'], true) ? $t : "items WHERE kind = '$t'") . ' ORDER BY sort, id LIMIT 1')->fetchColumn());
foreach ($lim as $t => $fl) {
    [, $html] = req("$base/admin/?p=content&t=$t", null, $ed);
    preg_match_all('~<(?:input|textarea|select)\b[^>]*\bname="([a-z_]+)"[^>]*>~', $html, $tags, PREG_SET_ORDER);
    $got = $bad = [];
    foreach ($tags as [$tag, $f]) {
        if ($f === '_csrf' || $f === 'id' || $f === 'rev' || $f === 'dir') continue;
        $got[$f] = 1;
        $ml = preg_match('~\bmaxlength="(\d+)"~', $tag, $mm) ? (int)$mm[1] : null;
        if ($ml !== ($fl[$f] ?? null)) $bad[] = "$f: maxlength=" . var_export($ml, true) . ' vs ' . var_export($fl[$f] ?? null, true);
    }
    check("$t: maxlength каждого поля в HTML == лимит ADR", !$bad && !array_diff_key($fl, $got), implode('; ', $bad) . ' нет: ' . implode(',', array_keys(array_diff_key($fl, $got))));
    $base0 = ['id' => $first($t)] + $newRow($t, 'М3');
    $tf = $t === 'fleet_prices' ? 'name' : 'title';
    [$code, $html] = $content($t, 'save', [$tf => ''] + $base0, $ed);
    check("$t: пустое «{$tf}» → 422 «Введите», id=\"err-$tf\", «Ошибка: » в title", $code === 422 && str_contains($html, "id=\"err-$tf\">Введите ")
        && str_contains($html, '<title>Ошибка: '), (string)$code);
    foreach ($fl as $f => $n) {
        $over = $fill($f, $n + 1);
        [$code, $html] = $content($t, 'save', [$f => $over] + $base0, $ed);
        $ok = $code === 422 && str_contains($html, "id=\"err-$f\"") && str_contains($html, "href=\"#f{$base0['id']}-$f\"") && str_contains($html, '<title>Ошибка: ');
        if ($f === 'img') { check("$t.$f: лимит+1 ($n+1) → 422, id=\"err-$f\"", $ok, (string)$code); continue; } // ровно лимит — только существующий файл
        [$code2] = $content($t, 'save', [$f => $fill($f, $n)] + $base0, $ed);
        check("$t.$f: лимит+1 ($n+1) → 422, id=\"err-$f\", ссылка в сводке; ровно $n → 302", $ok && $code2 === 302, "$code $code2");
    }
}
[$code, $html] = $content('services', 'save', ['title' => str_repeat('Ж', 41)] + ['id' => $first('services')] + $newRow('services', 'М3'), $ed);
check('422: присланное значение в поле сохранено, рамка, подсказка, aria-describedby', $code === 422 && str_contains($html, 'value="' . str_repeat('Ж', 41) . '"')
    && preg_match('~<input id="f\d+-title" name="title" aria-describedby="hf\d+-title err-title"[^>]*class="bad"[^>]* value="Ж{41}">~u', $html), (string)$code);
[, $html] = req("$base/admin/?p=content&t=fleet_cards", null, $ed);
check('форма: «обязательно», подсказка, data-max, inputmode=numeric, подпись alt', str_contains($html, '<span class="req">обязательно</span>')
    && str_contains($html, 'Описание фото для поисковиков и незрячих') && preg_match('~name="price"[^>]*inputmode="numeric"~', $html)
    && preg_match('~name="title"[^>]*maxlength="40" data-max="40"[^>]*required~', $html) && str_contains($html, '<p class="hint" id="hf'));
[$code] = $content('services', 'save', ['text' => str_repeat('Ж', 99) . "\r\n" . str_repeat('Ж', 100)] + ['id' => $first('services')] + $newRow('services', 'М3'), $ed);
check('textarea: 200 символов с переносом \r\n → 302 (перенос = 1 символ, как в maxlength)', $code === 302, (string)$code);
[$code, $html] = $content('fleet_prices', 'save', ['price_kind' => 'fixed', 'price' => ''] + ['id' => $first('fleet_prices')] + $newRow('fleet_prices', 'М3'), $ed);
check('цена не договорная и пустая → 422 у поля price', $code === 422 && str_contains($html, 'id="err-price">Введите цену'), (string)$code);
// R1: вставка сверх лимита (JS снимает maxlength) — сервер 422, присланный текст целиком в поле
$long = str_repeat('Ж', 562);
[$code, $html] = $content('services', 'save', ['text' => $long] + ['id' => $first('services')] + $newRow('services', 'М3'), $ed);
check('services.text 562 (лимит+362) → 422, текст целиком в поле, «сейчас 562», ссылка в сводке', $code === 422 && str_contains($html, ">$long</textarea>")
    && str_contains($html, 'Сократите до 200 символов — сейчас 562') && preg_match('~<a href="#f\d+-text">~', $html), (string)$code);
[$code] = $content('services', 'save', ['id' => '', 'at' => 'first'] + $newRow('services', 'R1 первая'), $ed);
$l = $dj()['services'];
[, $html] = req("$base/admin/?p=content&t=services", null, $ed);
check('add «в начало списка» → 302, строка первая в черновике и на странице', $code === 302 && $l[0]['title'] === 'R1 первая' && (int)$l[0]['sort'] < (int)$l[1]['sort']
    && preg_match('~name="title"[^>]*value="([^"]*)"~', $html, $mm) && $mm[1] === 'R1 первая', "$code " . ($l[0]['title'] ?? ''));
check('services: «Ссылка» помечена «обязательно» и required; ссылка «Настройки → Тексты»', preg_match('~for="f\d+-href">Ссылка <span class="req">обязательно</span>~', $html)
    && preg_match('~name="href"[^>]*required~', $html) && str_contains($html, 'href="/admin/?p=settings&amp;g=texts">Настройки → Тексты</a>'));
req("$base/admin/?p=draft&a=discard", ['_csrf' => $csrfOf($boss)], $boss);

// nav: сумма длин названий + 4·n ≤ 66; 5 пунктов: остальные 8+8+9+9=34, +20 → первый 11 = 65, 12 = 66, 13 = 67
$nv = array_map('intval', array_column(db()->query("SELECT id FROM items WHERE kind = 'nav' ORDER BY sort")->fetchAll(), 'id'));
foreach ([1 => 8, 2 => 8, 3 => 9, 4 => 9] as $i => $k) $content('nav', 'save', ['id' => $nv[$i], 'title' => str_repeat('Ж', $k), 'href' => '#services'], $ed);
$res = [];
foreach ([11, 12, 13] as $k) $res[$k] = $content('nav', 'save', ['id' => $nv[0], 'title' => str_repeat('М', $k), 'href' => '#services'], $ed);
check('nav: 5 пунктов, сумма 65 → 302, 66 → 302, 67 → 422 «Меню не помещается в шапку — сократите названия»', count($nv) === 5
    && $res[11][0] === 302 && $res[12][0] === 302 && $res[13][0] === 422 && str_contains($res[13][1], 'id="err-title">Меню не помещается в шапку — сократите названия')
    && $dj()['nav'][0]['title'] === str_repeat('М', 12), "{$res[11][0]} {$res[12][0]} {$res[13][0]}");
[, $html] = req("$base/admin/?p=content&t=nav", null, $ed);
check('nav: общий счётчик «В шапке осталось 0 символов», data-room=66', str_contains($html, 'data-room="66" aria-live="polite">В шапке осталось 0 символов'));
[$code] = $content('nav', 'save', ['id' => '', 'title' => 'Ж', 'href' => '#services'], $ed);
check('nav: добавить пункт сверх правила → 422, черновик не изменён', $code === 422 && count($dj()['nav']) === 5, (string)$code);
req("$base/admin/?p=draft&a=discard", ['_csrf' => $csrfOf($boss)], $boss);

// publish: картинка строки черновика пропала с диска → 422, ROLLBACK, / и черновик целы
$fc = $first('fleet_cards');
$content('fleet_cards', 'save', ['id' => $fc] + $newRow('fleet_cards', 'М3 пропало фото'), $ed);
$d0 = $dj();
$d0['fleet_cards'][0]['img'] = 'assets/m3-gone.webp';
db()->prepare('UPDATE drafts SET data = ?')->execute([json_encode($d0, JSON_UNESCAPED_UNICODE)]);
$raw0 = db()->query('SELECT data FROM drafts')->fetchColumn();
$n0 = $audits();
[$code, $html] = $publish($boss);
check('publish с удалённым img → 422 со списком, «Ошибка: », / без изменений, черновик цел, audit нет', $code === 422 && str_contains($html, '<title>Ошибка: ')
    && str_contains($html, 'Картинка не найдена на сервере') && str_contains($html, 'М3 пропало фото') && !str_contains(req("$base/")[1], 'М3 пропало фото')
    && db()->query('SELECT data FROM drafts')->fetchColumn() === $raw0 && $audits() === $n0, (string)$code);
req("$base/admin/?p=draft&a=discard", ['_csrf' => $csrfOf($boss)], $boss);

// --- M8/M4: загрузка фото → WebP в public/assets/uploads (созданные тестом файлы удаляются в конце блока)
$upDir = "$root/public/assets/uploads";
$hadUp = is_dir($upDir);
$made = [];
$up = function (string $bytes, string $mime, ?string $cookie = null, bool $csrf = true) use ($base, $ed, $csrfOf, &$made, $root): array {
    $c = $cookie ?? $ed;
    [$code, $body, $h] = reqFile("$base/admin/?p=content&t=fleet_cards&a=upload", $csrf ? ['_csrf' => $csrfOf($c)] : [], $bytes, $mime, $c);
    $j = json_decode((string)$body, true);
    if (is_string($j['img'] ?? null)) $made[] = "$root/public/" . $j['img'];
    return [$code, $j, $h, $body];
};
$gd = function (int $w, int $h, string $fmt): string {
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w / 2, $h, imagecolorallocate($im, 255, 199, 0));
    ob_start();
    $fmt === 'png' ? imagepng($im) : imagejpeg($im);
    return ob_get_clean();
};
[$code, $j, $h] = $up($gd(1600, 1000, 'png'), 'image/png');
$f = "$root/public/" . ($j['img'] ?? '-');
$gi = is_file($f) ? getimagesize($f) : [];
check('upload PNG (GD 1600×1000) → 200 JSON, img assets/uploads/<32hex>.webp, файл есть, image/webp, ширина 1200', $code === 200
    && preg_match('~^assets/uploads/[0-9a-f]{32}\.webp$~D', $j['img'] ?? '') && ($gi['mime'] ?? '') === 'image/webp' && $gi[0] === 1200 && $gi[1] === 750
    && in_array('Content-Type: application/json; charset=utf-8', $h, true), "$code " . json_encode($j));
$fc = $first('fleet_cards');
[$code] = $content('fleet_cards', 'save', ['id' => $fc, 'img' => $j['img'] ?? ''] + $newRow('fleet_cards', 'М4 фото'), $ed);
check('путь из ответа upload проходит проверку img при сохранении → 302', $code === 302, (string)$code);
req("$base/admin/?p=draft&a=discard", ['_csrf' => $csrfOf($boss)], $boss);
[$code, $j] = $up('просто текст', 'text/plain');
check('upload text/plain → 422 «Это не картинка. Подойдут JPG, PNG или WebP»', $code === 422 && ($j['error'] ?? '') === 'Это не картинка. Подойдут JPG, PNG или WebP', "$code " . json_encode($j));
[$code, $j] = $up($gd(400, 300, 'jpeg') . '<?php echo 1; ?>', 'image/jpeg');
$f = "$root/public/" . ($j['img'] ?? '-');
check('upload JPEG + "<?php" в хвосте → 200, файл .webp без "<?php"', $code === 200 && is_file($f) && !str_contains(file_get_contents($f), '<?php')
    && getimagesize($f)['mime'] === 'image/webp', "$code " . json_encode($j));
// EXIF Orientation=6 (телефон держали вертикально): 400×300 → 300×400
$tiff = "MM\0*" . pack('N', 8) . pack('n', 1) . pack('nnNnn', 0x0112, 3, 1, 6, 0) . pack('N', 0);
$jp = $gd(400, 300, 'jpeg');
[$code, $j] = $up(substr($jp, 0, 2) . "\xFF\xE1" . pack('n', 2 + 6 + strlen($tiff)) . "Exif\0\0" . $tiff . substr($jp, 2), 'image/jpeg');
$gi = getimagesize("$root/public/" . ($j['img'] ?? '-')) ?: [];
check('upload JPEG с EXIF Orientation=6 → повёрнут: 300×400', $code === 200 && ($gi[0] ?? 0) === 300 && ($gi[1] ?? 0) === 400, "$code " . json_encode($gi));
[$code, $j] = $up(random_bytes(11 << 20), 'image/jpeg');
check('upload 11 МБ → 422 «Файл больше 10 МБ»', $code === 422 && ($j['error'] ?? '') === 'Файл больше 10 МБ', "$code " . json_encode($j));
[$code, $j] = $up(random_bytes(14 << 20), 'image/jpeg');
check('upload 14 МБ (> post_max_size) → 413 JSON «Файл больше 10 МБ», не 403', $code === 413 && ($j['error'] ?? '') === 'Файл больше 10 МБ', "$code " . json_encode($j));
// 9000×9000: только заголовок PNG (IHDR) — getimagesize читает его, до декодирования дело не доходит
$chunk = fn(string $t, string $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
[$code, $j] = $up("\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 9000, 9000, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress('')) . $chunk('IEND', ''), 'image/png');
check('upload PNG 9000×9000 → 422 «Слишком большое разрешение»', $code === 422 && str_starts_with($j['error'] ?? '', 'Слишком большое разрешение'), "$code " . json_encode($j));
$n0 = count(glob("$upDir/*.webp"));
[$code] = $up($gd(100, 100, 'png'), 'image/png', null, false);
check('upload без CSRF → 403, файл не создан', $code === 403 && count(glob("$upDir/*.webp")) === $n0, (string)$code);
[$code, , $h] = reqFile("$base/admin/?p=content&t=fleet_cards&a=upload", [], $gd(100, 100, 'png'), 'image/png');
check('upload без сессии → 302 на login, файл не создан', $code === 302 && in_array('Location: /admin/?p=login', $h, true) && count(glob("$upDir/*.webp")) === $n0, (string)$code);
[, $html] = req("$base/admin/?p=content&t=materials", null, $ed);
check('форма: accept="image/jpeg,image/png,image/webp", подпись «JPG, PNG или WebP, до 10 МБ», живая карточка .pv-mat [data-pv]', str_contains($html, 'accept="image/jpeg,image/png,image/webp"')
    && str_contains($html, 'JPG, PNG или WebP, до 10 МБ') && str_contains($html, 'class="pv pv-mat"') && str_contains($html, 'data-pv="badge"') && !str_contains($html, 'style='));
foreach ($made as $m) @unlink($m);
if (!$hadUp) @rmdir($upDir);
check('файлы, созданные тестом, удалены', !array_filter($made, 'file_exists'), implode(' ', $made));

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
$inn0 = $get('org_inn');
$set('org_inn', '');
check('admin: баннер виден на ?p=content (пустой ИНН)', str_contains(req("$base/admin/?p=content", null, $ed)[1], 'Реквизиты не заполнены'));
$set('org_inn', $inn0);

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
// checkpoint делает сам обработчик удаления; байты читает cat: свой open/close файла БД снял бы POSIX-блокировки SQLite этого процесса
check('удалённая заявка: «Формула» нет ни в site.sqlite, ни в -wal (secure_delete + checkpoint)',
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

// --- M2: маршрутизация и шаблон посадочных
$loc = fn(array $h): string => (string)preg_replace('~^Location:\s*~i', '', (string)(preg_grep('~^Location:~i', $h) ?: [''])[array_key_first(preg_grep('~^Location:~i', $h) ?: [''])]);
$pageHits = fn(string $page) => (int)db()->query('SELECT COALESCE(SUM(hits), 0) FROM stats WHERE page = ' . db()->quote($page))->fetchColumn();
foreach (['arenda-spetstekhniki', 'shcheben-pesok-grunt'] as $slug) {
    $h0 = $pageHits("/$slug/");
    $t = microtime(true);
    [$code] = req("$base/$slug/");
    $dt = microtime(true) - $t;
    check("M2: /$slug/ → 200 за <1,0 с, hit('/$slug/')", $code === 200 && $dt < 1.0 && $pageHits("/$slug/") === $h0 + 1, sprintf('%d %.3f', $code, $dt));
    [$code, , $h] = req("$base/$slug?utm_source=x");
    check("M2: /$slug?utm_source=x → 301 Location /$slug/", $code === 301 && $loc($h) === "/$slug/", "$code " . $loc($h));
}
[$code] = req("$base/asfaltirovanie/");
check('M2: /asfaltirovanie/ без fact_asphalt_works → 404', $code === 404, (string)$code);
$set('fact_asphalt_works', 'x');
[$code] = req("$base/asfaltirovanie/");
check("M2: fact_asphalt_works='x' → /asfaltirovanie/ 200", $code === 200, (string)$code);
$bad = [];
foreach (['/nope/', '/Arenda-spetstekhniki/', '/a/b/', '/blagoustroystvo/', '/blagoustroystvo', '/arenda-spetstekhniki/x'] as $u) {
    [$code] = req("$base$u");
    if ($code !== 404) $bad[] = "$u $code";
}
check('M2: /nope/, /Arenda-spetstekhniki/, /a/b/, неопубликованная blago (со слэшем и без), /slug/x → 404', !$bad, implode(', ', $bad));
[$code, , $h] = req("$base/admin");
check('M2: /admin → 301 /admin/', $code === 301 && str_ends_with($loc($h), '/admin/'), "$code " . $loc($h));
$set('fact_blago_works', "Дворы\nПлощадки");
$s2 = db()->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$titles = $descs = $errs = [];
foreach (['' => null] + array_combine(array_column(PAGES, 'slug'), array_keys(PAGES)) as $slug => $id) {
    $u = $slug === '' ? '/' : "/$slug/";
    [$code, $html] = req("$base$u");
    preg_match('~<title>(.*?)</title>~s', $html, $m1);
    preg_match('~<meta name="description" content="([^"]*)">~', $html, $m2);
    $titles[] = $m1[1] ?? '';
    $descs[] = $m2[1] ?? '';
    $canon = '<link rel="canonical" href="' . e($s2['base_url'] . ($slug === '' ? '' : "$slug/")) . '">';
    $h1ok = substr_count($html, '<h1') === 1 && ($id === null || preg_match('~<h1[^>]*>' . preg_quote(e($s2["pg_{$id}_h1"]), '~') . '</h1>~', $html));
    $base0 = str_contains($html, '<meta charset="utf-8">' . "\n" . '<base href="/">');
    if ($code !== 200 || !$h1ok || !str_contains($html, $canon) || $base0 !== ($id !== null) || ($id !== null && str_contains($html, 'id="why"'))) $errs[] = "$u $code h1=" . (int)$h1ok . ' base=' . (int)$base0;
}
check('M2: 5 страниц → 200, ровно один <h1> (= pg_*_h1), canonical base_url+slug/, <base href="/"> только на посадочных, нет #why', !$errs, implode('; ', $errs));
check('M2: title и description уникальны среди 5 страниц', count(array_unique($titles)) === 5 && count(array_unique($descs)) === 5 && !in_array('', $titles, true), implode(' | ', $titles));
[, $html] = req("$base/blagoustroystvo/");
check('M2: «Виды работ» на blago из fact_blago_works', str_contains($html, '>Виды работ</h2>') && str_contains($html, "Дворы\nПлощадки"));
check('M2: в landing.php нет href="#', !str_contains(file_get_contents("$root/app/tpl/landing.php"), 'href="#'));
$set('fact_asphalt_works', '');
$set('fact_blago_works', '');
$n0 = $leads();
[$code, $body] = req("$base/send.php", ['service' => 'Аренда спецтехники'] + $lead + ['consent' => '1'], '', ["Referer: $base/arenda-spetstekhniki/"]);
$svc = db()->query('SELECT service FROM leads ORDER BY id DESC LIMIT 1')->fetchColumn();
check('M2: заявка с посадочной (send.php, service) → 200, в БД с услугой', $code === 200 && $leads() === $n0 + 1 && $svc === 'Аренда спецтехники', "$code $body");

// --- M3: JSON-LD @graph, крошки, FAQ, sitemap, коды вебмастеров
$s3 = db()->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$lds = fn(string $html): array => array_map(fn($j) => json_decode($j, true), preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m) ? $m[1] : []);
$graph = fn(string $html): array => array_column(array_merge(...array_map(fn($d) => $d['@graph'] ?? [], $lds($html))), null, '@type');
$sitemap = function () use ($base): ?array {
    $x = @simplexml_load_string(req("$base/sitemap.xml")[1]);
    if ($x === false) return null;
    $out = [];
    foreach ($x->url as $u) $out[] = (string)$u->loc;
    return $out;
};
$pub = fn() => array_keys(array_filter(PAGES, fn($id) => !page_gaps(db()->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR), $id), ARRAY_FILTER_USE_KEY));
$errs = [];
foreach (['' => null] + array_combine(array_column(PAGES, 'slug'), array_keys(PAGES)) as $slug => $id) {
    if ($id !== null && !in_array($id, $pub(), true)) continue;
    [, $html] = req($base . ($slug === '' ? '/' : "/$slug/"));
    $d = $lds($html);
    if (!$d || in_array(null, $d, true)) $errs[] = "$slug: ld+json не декодируется";
    if (str_contains($html, 'Review') || str_contains($html, 'AggregateRating')) $errs[] = "$slug: Review/AggregateRating";
    if ($id === null) continue;
    $canon = $s3['base_url'] . "$slug/";
    $g = $graph($html);
    $bc = array_column($g['BreadcrumbList']['itemListElement'] ?? [], 'item', 'position');
    if (!isset($g['Service'], $g['BreadcrumbList']) || $bc !== [1 => $s3['base_url'], 2 => $canon]) $errs[] = "$slug: @graph/крошки " . json_encode($bc);
    if (($g['Service']['url'] ?? '') !== $canon || ($g['Service']['name'] ?? '') !== $s3["pg_{$id}_h1"] || isset($g['Service']['provider']['@context'])) $errs[] = "$slug: Service";
    if (!preg_match('~<nav aria-label="Хлебные крошки"[^>]*><a href="/"[^>]*>Главная</a> / <span aria-current="page">' . preg_quote(e(PAGES[$id]['name']), '~') . '</span></nav>~', $html)) $errs[] = "$slug: видимые крошки";
    if (isset($g['FAQPage']) || str_contains($html, 'Вопросы и ответы')) $errs[] = "$slug: FAQ без фактов";
}
check('M3: ld+json декодируется, @graph Service+BreadcrumbList (1 base_url, 2 canonical), nav «Хлебные крошки», без фактов нет FAQ, нет Review/AggregateRating', !$errs, implode('; ', $errs));
$loc0 = $sitemap();
$want = fn() => array_merge([$s3['base_url']], array_map(fn($id) => $s3['base_url'] . PAGES[$id]['slug'] . '/', $pub()));
check('M3: sitemap — валидный XML, <loc> = главная + rent + mat, без /asfaltirovanie/', $loc0 === $want() && count($loc0) === 3 && !in_array($s3['base_url'] . 'asfaltirovanie/', $loc0, true), json_encode($loc0));
$set('fact_asphalt_works', 'Дворы и проезды');
$set('fact_blago_works', 'Плитка');
$set('fact_warranty', '2 года');
$loc1 = $sitemap();
check('M3: после факта /asfaltirovanie/ и /blagoustroystvo/ в sitemap', $loc1 === $want() && count($loc1) === 5 && in_array($s3['base_url'] . 'asfaltirovanie/', $loc1, true), json_encode($loc1));
$errs = [];
foreach (['asfaltirovanie', 'blagoustroystvo', 'arenda-spetstekhniki'] as $slug) {
    [, $html] = req("$base/$slug/");
    $f = $graph($html)['FAQPage'] ?? null;
    $q = $f['mainEntity'] ?? [];
    $vis = preg_match_all('~<h3[^>]*>Какая гарантия на работы\?</h3>\s*<p[^>]*>2 года</p>~', $html);
    if ($slug === 'arenda-spetstekhniki') { if ($f || $vis) $errs[] = "$slug: FAQ гарантии не на своей странице"; continue; }
    if (count($q) !== 1 || ($q[0]['@type'] ?? '') !== 'Question' || ($q[0]['acceptedAnswer']['text'] ?? '') !== '2 года' || $vis !== 1) $errs[] = "$slug: " . json_encode($f, JSON_UNESCAPED_UNICODE);
}
check("M3: fact_warranty='2 года' → FAQPage ровно с одним Question на asf и blago, ответ виден в HTML; на rent нет", !$errs, implode('; ', $errs));
$set('fact_snt', '');
[, $html] = req("$base/asfaltirovanie/");
$noSnt = !str_contains($html, 'дороги в СНТ');
$set('fact_snt', 'Подсыпка <b>щебнем</b>');
[, $html] = req("$base/asfaltirovanie/");
[, $hb] = req("$base/blagoustroystvo/");
check('M3: секция СНТ на asf только с fact_snt, текст экранирован, на blago нет', $noSnt && str_contains($html, '>Щебёночные дороги и дороги в СНТ</h2>') && str_contains($html, 'Подсыпка &lt;b&gt;щебнем&lt;/b&gt;') && !str_contains($hb, 'дороги в СНТ'));
foreach (['fact_asphalt_works', 'fact_blago_works', 'fact_warranty', 'fact_snt'] as $k) $set($k, '');
foreach (['yandex' => 'yandex-verification', 'google' => 'google-site-verification'] as $k => $tag) {
    $set("{$k}_verification", '');
    $none = !str_contains(req("$base/")[1], $tag);
    $set("{$k}_verification", 'Code_1-x');
    [, $html] = req("$base/");
    $head = explode('</head>', $html, 2)[0];
    $land = req("$base/arenda-spetstekhniki/")[1] . req("$base/shcheben-pesok-grunt/")[1];
    check("M3: {$k}_verification пуст → тега нет; задан → ровно один <meta name=\"$tag\"> в <head> главной, на посадочных нет",
        $none && substr_count($html, "<meta name=\"$tag\"") === 1 && str_contains($head, "<meta name=\"$tag\" content=\"Code_1-x\">") && !str_contains($land, $tag));
    $set("{$k}_verification", '');
}

// --- ADR-0009 M4: перелинковка
check('M4: valid_href /arenda-spetstekhniki/ → true, //evil/x.php и /a/b/ → false', valid_href('/arenda-spetstekhniki/') && !valid_href('//evil/x.php') && !valid_href('/a/b/'));
db()->exec("UPDATE services SET href = '/asfaltirovanie/' WHERE id = 1"); // выше тест D5 перезаписал href строки sort=1
$t1 = e(db()->query('SELECT title FROM services WHERE id = 1')->fetchColumn()); // название меняли тесты выше
$cut = fn(string $h, string $from, string $to) => explode($to, explode($from, $h, 2)[1] ?? '', 2)[0];
[, $html] = req("$base/");
$errs = [];
foreach (['arenda-spetstekhniki', 'shcheben-pesok-grunt'] as $slug) {
    if (substr_count($cut($html, '<header', '</header>'), "href=\"/$slug/\" style=\"") !== 2) $errs[] = "$slug: меню"; // меню = <header> (ПК + мобильное); пути hero (T06) тоже с style=
    if (!str_contains($cut($html, 'id="services"', '</section>'), "<a href=\"/$slug/\">")) $errs[] = "$slug: карточка";
    if (!str_contains($cut($html, '<footer', '</footer>'), "href=\"/$slug/\"")) $errs[] = "$slug: подвал";
}
$f0 = $cut($html, '<footer', '</footer>');
check('M4: на / ссылки на rent и mat в меню (2), в #services и в footer; asf (не опубл.) в подвале → #services, без ссылки в карточке', !$errs
    && str_contains($f0, "<a href=\"#services\" style-hover=\"color:#FFC700\">$t1</a>") && !str_contains($html, '/asfaltirovanie/'), implode('; ', $errs));
$set('fact_asphalt_works', 'Дворы и проезды');
[, $html] = req("$base/");
[, $ha] = req("$base/asfaltirovanie/");
check('M4: после факта asf → /asfaltirovanie/ в подвале и в карточке', str_contains($cut($html, '<footer', '</footer>'), "<a href=\"/asfaltirovanie/\" style-hover=\"color:#FFC700\">$t1</a>")
    && str_contains($cut($html, 'id="services"', '</section>'), "<a href=\"/asfaltirovanie/\">$t1</a>")
    && !str_contains($cut($ha, 'id="services"', '</section>'), '<a href="/asfaltirovanie/">') && str_contains($cut($ha, 'id="services"', '</section>'), '<a href="/arenda-spetstekhniki/">'));
$set('fact_asphalt_works', '');
[, $hr] = req("$base/arenda-spetstekhniki/");
$sv = $cut($hr, 'id="services"', '</section>');
// T39: «Услуга» есть и в нижней форме, и в окне «Заявка» — в каждом <select> выбрана одна опция, и это «Аренда спецтехники»
check('M4: на rent нет карточки со ссылкой на себя, есть на mat; в обоих <select> (форма, окно) выбрано «Аренда спецтехники» (по одной)', $sv !== '' && !str_contains($sv, '/arenda-spetstekhniki/')
    && str_contains($sv, '<a href="/shcheben-pesok-grunt/">') && substr_count($hr, '<select ') === 2 && substr_count($hr, ' selected>') === 2 && substr_count($hr, '<option selected>Аренда спецтехники</option>') === 2);
$o = new PDO("sqlite:$oldDb");
$o->exec(file_get_contents("$root/db/schema.sql") . file_get_contents("$root/db/seed.sql")); // прод до M1: сид есть, pg_* нет
$o->exec("UPDATE items SET href = '#why' WHERE kind = 'nav' AND title = 'Материалы'"); // правка владельца
$o = null;
$p = proc_open([PHP_BINARY, '-r', 'require "app/bootstrap.php"; echo implode(",", array_merge(array_column(rows("SELECT href FROM items WHERE kind = \'nav\' ORDER BY sort"), "href"), array_column(rows("SELECT href FROM services ORDER BY id"), "href")));'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, $root, ['RDS_DB' => $oldDb] + getenv());
$out = stream_get_contents($pp[1]);
proc_close($p);
array_map('unlink', glob("$oldDb*"));
check('M4: старая БД после миграции: nav #fleet → /arenda-spetstekhniki/, nav, изменённый вручную (#why), не тронут; services 1,2,3,5 → посадочные',
    $out === '#services,/arenda-spetstekhniki/,#why,#why,#contacts,/asfaltirovanie/,/blagoustroystvo/,/arenda-spetstekhniki/,#services,/shcheben-pesok-grunt/,#services', $out);

$ht = file_get_contents("$root/public/.htaccess");
$dirRule = strpos($ht, "RewriteCond %{REQUEST_FILENAME} -d\nRewriteCond %{HTTP_HOST} ^(?:www\\.)?(.+)$ [NC]\nRewriteRule [^/]$ https://%1%{REQUEST_URI}/ [R=301,L]");
check('T4: .htaccess — каталог без «/» → https://<без www>/…/ одним хопом, правило до www- и https-правил',
    $dirRule !== false && $dirRule < strpos($ht, '^www\\.(.+)$') && $dirRule < strpos($ht, '%{ENV:HTTPS}'));

// ключи fact_* собираются из кода и сида — новый ключ без пункта в OWNER-ACTIONS роняет тест
$facts = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/app", FilesystemIterator::SKIP_DOTS)) as $f)
    if (preg_match_all('/\bfact_[a-z0-9_]+/', file_get_contents($f->getPathname()), $m)) $facts = array_merge($facts, $m[0]);
preg_match_all('/\bfact_[a-z0-9_]+/', file_get_contents("$root/db/seed-pages.sql"), $m);
$facts = array_unique(array_merge($facts, $m[0]));
$oa = @file_get_contents("$root/docs/OWNER-ACTIONS.md") ?: '';
$miss = array_filter($facts, fn($k) => !str_contains($oa, "`$k`"));
check('T5: docs/OWNER-ACTIONS.md есть и упоминает все fact_* из app/ и db/seed-pages.sql', $oa !== '' && count($facts) >= 13 && !$miss, implode(', ', $miss));

// F8: 300 строк «ab» с \r\n — 1200 байт, но 900 символов по счёту maxlength формы → принимается
[$code, $body] = req("$base/send.php", ['comment' => str_repeat("ab\r\n", 300)] + $lead + ['consent' => '1']);
check('send.php: перенос \\r\\n считается за 1 символ (как maxlength) → 200', $code === 200, "$code $body");

// Telegram: ссылка пуста (сид) → ни кнопок, ни пустого sameAs; задана → обе кнопки и sameAs
$tg = fn(string $h) => [substr_count($h, '>Telegram</a>'), preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $h, $m) ? json_encode(json_decode($m[1], true)['sameAs'] ?? null) : '?'];
[, $h] = req("$base/");
[$n, $same] = $tg($h);
check("Telegram пуст → кнопок 0 (есть $n), sameAs без пустых: [$same]", $n === 0 && !str_contains($same, '""') && str_contains($same, 'wa.me'));
$set('telegram', 'https://t.me/rds_test');
[, $h] = req("$base/");
[$n, $same] = $tg($h);
check("Telegram задан → кнопок 2 (есть $n), в sameAs", $n === 2 && str_contains($same, 't.me\/rds_test'), $same);
$set('telegram', 'https://t.me/regiondorstroy');
[$code] = req("$base/admin/?p=settings&g=contacts&a=save", ['_csrf' => $csrfOf($boss), 'telegram' => ''], $boss);
$tv = db()->query("SELECT value FROM settings WHERE key = 'telegram'")->fetchColumn();
check("owner очищает «Ссылка Telegram» в админке → $code, сохранено пусто", $code === 302 && $tv === '', "$code [$tv]");
[$code] = req("$base/admin/?p=settings&g=contacts&a=save", ['_csrf' => $csrfOf($boss), 'telegram' => 'javascript:alert(1)'], $boss);
check("«Ссылка Telegram» javascript: → 422", $code === 422, (string)$code);

// публичные HTML-страницы нельзя встроить в чужой iframe (кликджекинг формы заявки)
foreach (['/', '/arenda-spetstekhniki/', '/privacy.php', '/consent.php'] as $u) {
    [$code, , $hs] = req("$base$u");
    check("$u: Content-Security-Policy: frame-ancestors 'self'", $code === 200 && in_array("Content-Security-Policy: frame-ancestors 'self'", $hs, true), "$code " . implode(' | ', preg_grep('/^Content-Security/i', $hs)));
}

// вход с устаревшим токеном (старая вкладка, без сессии) → форма заново с новым токеном, повтор входит
[$code, $html, $h] = req("$base/admin/?p=login", ['_csrf' => str_repeat('0', 64), 'login' => 'boss', 'password' => $pw]);
$c1 = $sid($h);
preg_match('~name="_csrf" value="([0-9a-f]+)"~', $html, $m);
check('вход с устаревшим токеном → 403 с формой и подсказкой, не голый «Доступ запрещён»', $code === 403 && str_contains($html, 'Страница входа устарела') && isset($m[1]) && $c1 !== '', (string)$code);
[$code] = req("$base/admin/?p=login", ['_csrf' => $m[1] ?? '', 'login' => 'boss', 'password' => $pw], $c1);
check('повтор с формы-подсказки → 302, вошёл', $code === 302, (string)$code);

exit($fail ? 1 : 0);
