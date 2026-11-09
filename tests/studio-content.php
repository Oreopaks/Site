<?php
// Тест bin/studio-content.php (шаг 14: перенос текстов на прод по манифесту). Подключается в конце tests/run.php:
// нужны $root, $dbPath, check(). Свои БД — рядом с $dbPath, свежие из сида (app/db.php), удаляются в конце.

// php bin/studio-content.php <args> → [exit, stdout+stderr]
$sc = function (array $args) use ($root): array {
    $p = proc_open(array_merge([PHP_BINARY, 'bin/studio-content.php'], $args), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pp, $root);
    $out = stream_get_contents($pp[1]);
    fclose($pp[1]);
    return [proc_close($p), $out];
};
$scDbs = [];
// свежая БД из сида тем же путём, что сайт (app/db.php); $old — откатить значения манифеста к старому сиду
$scFresh = function (string $name, bool $old) use ($root, $dbPath, &$scDbs): string {
    $p = "$dbPath-$name.sqlite";
    $scDbs[] = $p;
    $pr = proc_open([PHP_BINARY, '-r', 'require "app/bootstrap.php"; db();'], [], $pp, $root, ['RDS_DB' => $p] + getenv());
    proc_close($pr);
    if ($old) {
        $db = new PDO("sqlite:$p", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $m = json_decode(file_get_contents("$root/docs/studio/content-manifest.json"), true);
        foreach ($m['settings'] as $e) $e['old'] === null
            ? $db->prepare('DELETE FROM settings WHERE key = ?')->execute([$e['key']])
            : $db->prepare('UPDATE settings SET value = ? WHERE key = ?')->execute([$e['old'], $e['key']]);
        $g = [];
        foreach ($m['rows'] as $e) $g[$e['table'] . json_encode($e['match'])][] = $e;
        foreach ($g as $es) {
            $match = $es[0]['match'];
            foreach ($es as $e) if (array_key_exists($e['col'], $match)) $match[$e['col']] = $e['new']; // в свежей БД строка уже новая
            $w = implode(' AND ', array_map(fn($c) => "$c IS ?", array_keys($match)));
            $set = implode(', ', array_map(fn($e) => "{$e['col']} = ?", $es));
            $db->prepare("UPDATE {$es[0]['table']} SET $set WHERE $w")->execute([...array_column($es, 'old'), ...array_values($match)]);
        }
        $db = null; // закрыть: WAL сливается в основной файл до замера хеша
    }
    return $p;
};
$scQ = fn(string $p, string $sql) => (new PDO("sqlite:$p"))->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$scDump = fn(string $p) => array_map(fn($t) => $scQ($p, "SELECT * FROM $t ORDER BY " . ($t === 'settings' ? 'key' : 'id')), ['settings', 'services', 'fleet_cards', 'fleet_prices', 'materials', 'items']);
$scSum = fn(string $out) => preg_match_all('~^\s+(применено|уже новое|конфликт|добавлено)\s+(\d+)$~mu', $out, $mm) ? array_combine($mm[1], array_map('intval', $mm[2])) : [];

$m = json_decode(file_get_contents("$root/docs/studio/content-manifest.json"), true);
$nAdd = count(array_filter($m['settings'], fn($e) => $e['old'] === null));
$nUpd = count($m['settings']) - $nAdd + count($m['rows']);
$fresh = $scFresh('fresh', false);

[$code, $out] = $sc(["--db=$fresh"]);
check("studio-content: свежий сид → всё «уже новое» (манифест new = сид), exit 0", $code === 0 && $scSum($out) === ['применено' => 0, 'уже новое' => $nUpd + $nAdd, 'конфликт' => 0, 'добавлено' => 0], "$code $out");

// (1) откат к старому сиду: сухой прогон не пишет, --apply = свежий сид, повтор — 0 изменений
$p1 = $scFresh('old', true);
$h = sha1_file($p1);
[$code, $out] = $sc(["--db=$p1"]);
check("studio-content: сухой прогон → $nUpd к применению, $nAdd ключей добавить, exit 0", $code === 0 && $scSum($out) === ['применено' => $nUpd, 'уже новое' => 0, 'конфликт' => 0, 'добавлено' => $nAdd] && str_contains($out, 'СУХОЙ ПРОГОН'), "$code $out");
check('studio-content: сухой прогон не меняет файл БД (sha1)', sha1_file($p1) === $h);
[$code, $out] = $sc(["--db=$p1", '--apply']);
check("studio-content: --apply → exit 0, применено $nUpd, добавлено $nAdd", $code === 0 && $scSum($out) === ['применено' => $nUpd, 'уже новое' => 0, 'конфликт' => 0, 'добавлено' => $nAdd], "$code $out");
check('studio-content: после --apply тексты и списки = свежему сиду (значения, grp/label/sort новых ключей)', $scDump($p1) === $scDump($fresh));
[$code, $out] = $sc(["--db=$p1", '--apply']);
check('studio-content: повторный --apply → 0 изменений', $code === 0 && $scSum($out) === ['применено' => 0, 'уже новое' => $nUpd + $nAdd, 'конфликт' => 0, 'добавлено' => 0], "$code $out");

// (2) заказчик правил: ключ, колонку строки, удалил строку → конфликты с обоими текстами, их не трогаем, остальное применено
$p2 = $scFresh('edited', true);
$db = new PDO("sqlite:$p2");
$db->exec("UPDATE settings SET value = 'Правка заказчика в админке' WHERE key = 'hero_p'");
$db->exec("UPDATE items SET text = 'Свой текст заказчика' WHERE kind = 'why' AND title = 'Отличная репутация'");
$db->exec("DELETE FROM services WHERE title = 'Грузоперевозки'");
$db = null;
[$code, $out] = $sc(["--db=$p2", '--apply']);
$oldHero = array_values(array_filter($m['settings'], fn($e) => $e['key'] === 'hero_p'))[0]['old'];
check('studio-content: конфликт → exit 2, в отчёте старый сид и текущее значение', $code === 2 && str_contains($out, $oldHero) && str_contains($out, 'Правка заказчика в админке') && str_contains($out, 'Свой текст заказчика'), "$code $out");
check('studio-content: конфликты = 1 ключ + 2 колонки строки why + 1 строка services (0 строк)', ($scSum($out)['конфликт'] ?? -1) === 4 && str_contains($out, 'строк: 0'), $out);
$v = $scQ($p2, "SELECT (SELECT value FROM settings WHERE key = 'hero_p') h, (SELECT COUNT(*) FROM items WHERE kind = 'why' AND title = 'Отличная репутация' AND text = 'Свой текст заказчика') w")[0];
check('studio-content: ключ и строка с конфликтом не тронуты (вся строка: title тоже старый)', $v['h'] === 'Правка заказчика в админке' && (int)$v['w'] === 1, json_encode($v, JSON_UNESCAPED_UNICODE));
$d2 = $scDump($p2);
$df = $scDump($fresh);
$otherSet = fn($rows) => array_values(array_filter($rows, fn($r) => $r['key'] !== 'hero_p'));
$otherItems = fn($rows) => array_values(array_filter($rows, fn($r) => !($r['kind'] === 'why' && (int)$r['sort'] === 1)));
check('studio-content: остальные ключи и строки применены', $otherSet($d2[0]) === $otherSet($df[0]) && $d2[4] === $df[4] && $otherItems($d2[5]) === $otherItems($df[5]));

// (3) копия прода: реквизиты и retention уже заполнены → «уже новое», не «применено»; (4) черновик → предупреждение
$p3 = $scFresh('prod', true);
$db = new PDO("sqlite:$p3");
foreach (['org_inn' => '5754023520', 'org_ogrn' => '1165749053689', 'org_address' => 'Россия, 302023, г. Орёл, Силикатный пер., д. 1', 'retention' => '1 год', 'base_url' => 'https://regiondorstroy.com/'] as $k => $val)
    $db->prepare('UPDATE settings SET value = ? WHERE key = ?')->execute([$val, $k]);
[$code, $out] = $sc(["--db=$p3"]);
$st = [];
preg_match_all('~^(применено|уже новое|конфликт|добавлено)\s+settings\.(\w+)$~mu', $out, $mm, PREG_SET_ORDER);
foreach ($mm as [, $s, $k]) $st[$k] = $s;
check('studio-content: прод-реквизиты, retention, base_url → «уже новое»', $code === 0 && array_intersect_key($st, array_flip(['org_inn', 'org_ogrn', 'org_address', 'retention', 'base_url'])) == array_fill_keys(['org_inn', 'org_ogrn', 'org_address', 'retention', 'base_url'], 'уже новое'), json_encode($st, JSON_UNESCAPED_UNICODE));
check('studio-content: без черновика — без предупреждения', !str_contains($out, 'ЧЕРНОВИК'));
$db->exec("INSERT INTO drafts (id, data) VALUES (1, '{}')");
$db = null;
[$code, $out] = $sc(["--db=$p3"]);
check('studio-content: в drafts есть строка → предупреждение', str_contains($out, 'ЧЕРНОВИК'), $out);

// ошибка: БД нет → exit 1, файл не создан
[$code, $out] = $sc(["--db=$dbPath-none.sqlite"]);
check('studio-content: нет файла БД → exit 1, файл не создан', $code === 1 && !is_file("$dbPath-none.sqlite"), "$code $out");

foreach ($scDbs as $p) foreach (['', '-wal', '-shm'] as $x) @unlink("$p$x");
