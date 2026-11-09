<?php
// Перенос текстов сида в рабочую БД по манифесту (docs/studio/content-manifest.json), шаг 14 docs/studio/task.md.
// php bin/studio-content.php [--db=путь] [--manifest=путь] [--apply]
// Значение пишется, только если в БД сейчас старый сид (old) — заказчик его не правил; иначе конфликт с обоими текстами.
// Без --apply — сухой прогон, БД не меняется. exit: 0 — без конфликтов, 2 — есть конфликты, 1 — ошибка.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

$opt = getopt('', ['db:', 'manifest:', 'apply']);
$path = $opt['db'] ?? (getenv('RDS_DB') ?: dirname(__DIR__) . '/var/site.sqlite'); // как app/db.php
$mpath = $opt['manifest'] ?? dirname(__DIR__) . '/docs/studio/content-manifest.json';
$apply = isset($opt['apply']);

// белый список таблиц и колонок списков (= db/schema.sql); имена в SQL — только отсюда
$cols = fn(string $t): array => match ($t) {
    'services' => ['sort', 'title', 'text', 'href', 'in_form', 'in_footer'],
    'fleet_cards' => ['sort', 'title', 'img', 'alt', 'img_pos', 'price_kind', 'price', 'unit'],
    'fleet_prices' => ['sort', 'name', 'model', 'price_kind', 'price', 'unit', 'is_sub'],
    'materials' => ['sort', 'title', 'text', 'img', 'alt', 'badge', 'price_kind', 'price', 'unit'],
    'items' => ['kind', 'sort', 'title', 'text', 'href', 'num', 'suffix'],
    default => throw new RuntimeException("манифест: таблица «{$t}» не из белого списка"),
};
$norm = fn($v): ?string => $v === null ? null : (string)$v; // INTEGER из БД и из JSON сравниваются как текст
$scalar = fn(array $e, string $k): bool => array_key_exists($k, $e) && ($e[$k] === null || is_string($e[$k]) || is_int($e[$k]));

$tx = false;
try {
    if (!is_file($mpath)) throw new RuntimeException("нет файла манифеста: $mpath");
    $m = json_decode(file_get_contents($mpath), true, 512, JSON_THROW_ON_ERROR);
    foreach ($m['settings'] ?? [] as $i => $e) {
        if (!is_string($e['key'] ?? null) || !is_string($e['new'] ?? null) || !array_key_exists('old', $e)
            || !(is_string($e['old']) || ($e['old'] === null && is_string($e['add']['grp'] ?? null) && is_string($e['add']['label'] ?? null))))
            throw new RuntimeException("манифест: settings[$i] — нужны key, new и old (строка или null вместе с add.grp, add.label)");
    }
    foreach ($m['rows'] ?? [] as $i => $e) {
        $c = $cols((string)($e['table'] ?? ''));
        if (!in_array($e['col'] ?? null, $c, true) || !is_array($e['match'] ?? null) || !$e['match']
            || array_diff(array_keys($e['match']), $c) || !$scalar($e, 'old') || !$scalar($e, 'new')
            || count(array_filter(array_keys($e['match']), fn($k) => !$scalar($e['match'], $k))))
            throw new RuntimeException("манифест: rows[$i] — нужны table, col и match из белого списка, old и new (строка, число или null)");
    }

    if (!is_file($path)) throw new RuntimeException("нет файла БД: $path"); // PDO создал бы пустой
    $db = new PDO("sqlite:$path", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA busy_timeout=5000');
    $q = function (string $sql, array $p = []) use ($db): PDOStatement {
        $st = $db->prepare($sql);
        $st->execute($p);
        return $st;
    };
    if ($apply) {
        $db->exec('BEGIN IMMEDIATE'); // одна транзакция: чтение и запись без чужих правок посередине
        $tx = true;
    }

    echo $apply ? "ЗАПИСЬ (--apply)" : "СУХОЙ ПРОГОН — БД не меняется, записать: --apply", "\nБД: $path\nманифест: $mpath\n\n";
    $sum = ['применено' => 0, 'уже новое' => 0, 'конфликт' => 0, 'добавлено' => 0];
    $note = function (string $s, string $ref, array $why = []) use (&$sum): void {
        $sum[$s]++;
        echo "$s\t$ref\n";
        foreach ($why as $k => $v) echo "    $k: ", $v === null ? '(нет)' : "«{$v}»", "\n";
    };

    foreach ($m['settings'] ?? [] as $e) {
        $ref = "settings.{$e['key']}";
        $cur = $q('SELECT value FROM settings WHERE key = ?', [$e['key']])->fetchColumn();
        if ($cur === false && isset($e['add'])) {
            $a = $e['add'];
            if ($apply) $q('INSERT INTO settings (key, value, grp, label, type, trusted_html, required, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$e['key'], $e['new'], $a['grp'], $a['label'], $a['type'] ?? 'text', (int)($a['trusted_html'] ?? 0), (int)($a['required'] ?? 0), (int)($a['sort'] ?? 0)]);
            $note('добавлено', $ref);
        } elseif ($cur === false) $note('конфликт', $ref, ['причина' => 'ключа нет в БД, а в манифесте нет add', 'новое (манифест)' => $e['new']]);
        elseif ($cur === $e['new']) $note('уже новое', $ref);
        elseif ($cur === $e['old']) {
            if ($apply) $q('UPDATE settings SET value = ? WHERE key = ?', [$e['new'], $e['key']]);
            $note('применено', $ref);
        } else $note('конфликт', $ref, ['старый сид' => $e['old'], 'сейчас в БД' => $cur, 'новое (манифест)' => $e['new']]);
    }

    // строки: записи с одинаковыми (table, match) — одна строка, применяются вместе или никак
    $groups = [];
    foreach ($m['rows'] ?? [] as $e) $groups[$e['table'] . json_encode($e['match'], JSON_UNESCAPED_UNICODE)][] = $e;
    foreach ($groups as $ref => $es) {
        $t = $es[0]['table'];
        $find = fn(array $mt) => $q("SELECT * FROM $t WHERE " . implode(' AND ', array_map(fn($c) => "$c IS ?", array_keys($mt))), array_values($mt))->fetchAll();
        $rows = $find($es[0]['match']);
        $mNew = $es[0]['match']; // строку уже перенесли: колонки из match теперь с новыми значениями
        foreach ($es as $e) if (array_key_exists($e['col'], $mNew)) $mNew[$e['col']] = $e['new'];
        if (!$rows && $mNew !== $es[0]['match']) $rows = $find($mNew);
        if (count($rows) !== 1) {
            $why = count($rows) ? 'строк: ' . count($rows) . ' — match не однозначен, правьте вручную в админке' : 'строк: 0 — строку удалили или переименовали в админке';
            foreach ($es as $e) $note('конфликт', "$ref.{$e['col']}", ['причина' => $why, 'старый сид' => $norm($e['old']), 'новое (манифест)' => $norm($e['new'])]);
            continue;
        }
        $res = [];
        foreach ($es as $e) {
            $cur = $norm($rows[0][$e['col']]);
            $res[] = [$cur === $norm($e['new']) ? 'уже новое' : ($cur === $norm($e['old']) ? 'применено' : 'конфликт'), $e, $cur];
        }
        $bad = in_array('конфликт', array_column($res, 0), true);
        $set = [];
        foreach ($res as [$s, $e, $cur]) {
            $r = "$ref.{$e['col']}";
            if ($s === 'конфликт') $note($s, $r, ['старый сид' => $norm($e['old']), 'сейчас в БД' => $cur, 'новое (манифест)' => $norm($e['new'])]);
            elseif ($s === 'применено' && $bad) $note('конфликт', $r, ['причина' => 'не применено: в этой же строке конфликт', 'старый сид' => $norm($e['old']), 'новое (манифест)' => $norm($e['new'])]);
            else {
                $note($s, $r);
                if ($s === 'применено') $set[$e['col']] = $e['new'];
            }
        }
        if ($apply && $set) $q("UPDATE $t SET " . implode(', ', array_map(fn($c) => "$c = ?", array_keys($set))) . ' WHERE id = ?', [...array_values($set), $rows[0]['id']]);
    }

    if ($apply) {
        $db->exec('COMMIT');
        $tx = false;
    }
    $d = $q('SELECT updated_at FROM drafts')->fetchColumn();
    if ($d !== false) echo "\nВНИМАНИЕ: в админке есть ЧЕРНОВИК списков (изменён $d). Его публикация вернёт в услуги, технику, материалы и цифры",
        " значения из черновика и затрёт эти правки. Опубликуйте или отмените черновик в админке и запустите скрипт ещё раз.\n";
    echo "\nИтог", $apply ? '' : ' сухого прогона («применено» и «добавлено» — будет записано с --apply)', ":\n";
    foreach ($sum as $k => $n) echo "  $k\t$n\n";
    exit($sum['конфликт'] ? 2 : 0);
} catch (Throwable $e) {
    if ($tx) $db->exec('ROLLBACK');
    fwrite(STDERR, 'ошибка: ' . $e->getMessage() . "\n");
    exit(1);
}
