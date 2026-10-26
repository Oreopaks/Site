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

// HTTP-запрос → [код, тело]
function req(string $url, ?array $post = null): array
{
    $opt = ['ignore_errors' => true];
    if ($post !== null) $opt += ['method' => 'POST', 'header' => 'Content-Type: application/x-www-form-urlencoded', 'content' => http_build_query($post)];
    $body = file_get_contents($url, false, stream_context_create(['http' => $opt]));
    preg_match('~^HTTP/\S+ (\d+)~', $http_response_header[0] ?? '', $m);
    return [(int)($m[1] ?? 0), $body];
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

exit($fail ? 1 : 0);
