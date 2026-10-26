<?php
// Единственный фронт-контроллер админки (D3): ?p=<раздел>&a=<действие>.
// Порядок: auth (302) → роль (403) → CSRF (403).
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require dirname(__DIR__, 2) . '/app/auth.php';

header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

// cookie path=/admin/ — без завершающего слеша браузер её не пришлёт
if (!str_starts_with((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/admin/')) {
    header('Location: /admin/', true, 301);
    exit;
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', '28800'); // 8 ч (допущение §5)
session_name('rds_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/admin/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

function go(string $p): never
{
    header('Location: /admin/?p=' . $p, true, 302);
    exit;
}

// Страница через общий layout; $main — готовый HTML (всё пользовательское — через e())
function page(string $title, string $main, int $code = 200): never
{
    http_response_code($code);
    $u = user();
    $csrf = $_SESSION['csrf'];
    $missing = $u ? array_column(rows("SELECT label FROM settings WHERE required = 1 AND trim(value) = '' ORDER BY sort"), 'label') : [];
    require dirname(__DIR__, 2) . '/app/tpl/admin/layout.php';
    exit;
}

$p = is_string($_GET['p'] ?? null) ? $_GET['p'] : 'home';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$csrfOk = fn(): bool => is_string($_POST['_csrf'] ?? null) && hash_equals($_SESSION['csrf'], $_POST['_csrf']);

if ($p === 'login') {
    if (user()) go('home');
    $form = fn(string $msg) => ($msg ? '<p class="err">' . e($msg) . '</p>' : '') .
        '<form method="post" action="/admin/?p=login" class="login">
  <input type="hidden" name="_csrf" value="' . e($_SESSION['csrf']) . '">
  <label>Логин <input name="login" autocomplete="username" required autofocus></label>
  <label>Пароль <input name="password" type="password" autocomplete="current-password" required></label>
  <button>Войти</button>
</form>';
    if (!$post) page('Вход', $form(''));
    if (!$csrfOk()) deny();
    [$res, $uid] = login(is_string($_POST['login'] ?? null) ? $_POST['login'] : '', is_string($_POST['password'] ?? null) ? $_POST['password'] : '');
    if ($res === 'locked') page('Вход', $form('Слишком много неудачных попыток. Вход заблокирован на 15 минут.'), 423);
    if ($res === 'bad') page('Вход', $form('Неверный логин или пароль.'));
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    go('home');
}

if (!user()) go('login');

$perm = match ($p) {
    'home', 'logout' => null,
    'content', 'settings' => 'content', // ponytail: права по группам/полям — в обработчиках M4
    'leads' => 'leads',
    'stats' => 'stats',
    'users' => 'users',
    'audit' => 'audit',
    default => false,
};
if ($perm === false) page('Не найдено', '<p>Раздел не найден.</p>', 404);
if ($perm !== null && !can($perm)) deny();
if ($post && !$csrfOk()) deny();

if ($p === 'logout') {
    if (!$post) { header('Allow: POST'); page('Выход', '<p>Выход — кнопкой в шапке.</p>', 405); }
    $_SESSION = [];
    session_destroy();
    go('login');
}


// Раздел через шаблон app/tpl/admin/<tpl>.php; переменные шаблона — из $vars
function view(string $tpl, string $title, array $vars, int $code = 200): never
{
    extract($vars);
    $csrf = $_SESSION['csrf'];
    ob_start();
    require dirname(__DIR__, 2) . "/app/tpl/admin/$tpl.php";
    page($title, ob_get_clean(), $code);
}

// audit_log: одна строка на изменённую запись; old/new — JSON только изменённых полей (или сырое значение settings)
function audit(string $tbl, string $key, ?string $old, ?string $new): void
{
    db()->prepare('INSERT INTO audit_log (user_id, tbl, row_key, old_value, new_value) VALUES (?, ?, ?, ?, ?)')
        ->execute([user()['id'], $tbl, $key, $old, $new]);
}
$json = fn(array $a): string => json_encode($a, JSON_UNESCAPED_UNICODE);
$in = fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
$a = $post && is_string($_GET['a'] ?? null) ? $_GET['a'] : '';

if ($p === 'home') {
    page('Обзор', '<p>Вы вошли как <b>' . e(user()['login']) . '</b> (' . e(user()['role']) . ').</p>');
}

if ($p === 'content') {
    $t = is_string($_GET['t'] ?? null) ? $_GET['t'] : 'services';
    $stat = ['title' => 'line', 'text' => 'line0', 'num' => 'int', 'suffix' => 'line0'];
    $price = ['price_kind' => 'kind', 'price' => 'price', 'unit' => 'line0'];
    // t → [таблица, kind в items, заголовок, поля => тип]; тип задаёт и форму, и проверку
    [$tbl, $kind, $tTitle, $fields] = match ($t) {
        'services' => ['services', null, 'Услуги', ['title' => 'line', 'text' => 'area', 'href' => 'href', 'in_form' => 'bool', 'in_footer' => 'bool']],
        'fleet_cards' => ['fleet_cards', null, 'Техника: карточки', ['title' => 'line', 'img' => 'img', 'alt' => 'line', 'img_pos' => 'pos'] + $price],
        'fleet_prices' => ['fleet_prices', null, 'Техника: прайс', ['name' => 'line', 'model' => 'line0'] + $price + ['is_sub' => 'bool']],
        'materials' => ['materials', null, 'Материалы', ['title' => 'line', 'text' => 'area', 'img' => 'img', 'alt' => 'line', 'badge' => 'line0'] + $price],
        'nav' => ['items', 'nav', 'Меню', ['title' => 'line', 'href' => 'href']],
        'marquee' => ['items', 'marquee', 'Бегущая строка', ['title' => 'line']],
        'stat_hero' => ['items', 'stat_hero', 'Цифры в шапке', $stat],
        'stat_bar' => ['items', 'stat_bar', 'Цифры под шапкой', $stat],
        'why' => ['items', 'why', 'О компании: карточки', ['title' => 'line', 'text' => 'area']],
        'step' => ['items', 'step', 'Этапы работы', ['title' => 'line', 'text' => 'area']],
        default => [null, null, null, null],
    };
    if ($tbl === null) page('Не найдено', '<p>Список не найден.</p>', 404);
    $where = $kind === null ? '' : 'WHERE kind = ' . db()->quote($kind);
    $list = fn() => rows("SELECT * FROM $tbl $where ORDER BY sort, id");
    $labels = ['title' => 'Название', 'name' => 'Название', 'model' => 'Модель', 'text' => 'Текст', 'href' => 'Ссылка',
        'in_form' => 'В форме', 'in_footer' => 'В подвале', 'img' => 'Картинка (assets/….webp)', 'alt' => 'Alt картинки',
        'img_pos' => 'Позиция картинки (NN% NN%)', 'badge' => 'Бейдж', 'price_kind' => 'Цена', 'price' => 'Цена, ₽',
        'unit' => 'Единица', 'is_sub' => 'Подстрока', 'num' => 'Число', 'suffix' => 'Суффикс'];
    $vars = ['t' => $t, 'fields' => $fields, 'labels' => $labels, 'err' => '', 'errId' => 0];

    if ($a === 'save') {
        $id = (int)$in('id');
        $old = current(array_filter($list(), fn($r) => (int)$r['id'] === $id));
        if (!$old) page('Не найдено', '<p>Строка не найдена.</p>', 404);
        $new = [];
        $err = '';
        foreach ($fields as $f => $type) {
            $v = $in($f);
            $ok = match ($type) {
                'line' => preg_match('/^.{1,200}$/suD', $v),
                'line0' => preg_match('/^.{0,200}$/suD', $v),
                'area' => preg_match('/^.{0,2000}$/suD', $v),
                'href' => valid_href($v), // D5
                'img' => preg_match('~^assets/[a-z0-9_\-/]+\.(webp|svg)$~D', $v) && is_file(__DIR__ . '/../' . $v), // D10
                'pos' => preg_match('/^\d{1,3}% \d{1,3}%$/D', $v), // уходит в style — только «NN% NN%»
                'kind' => in_array($v, ['fixed', 'from', 'contract'], true),
                'price', 'int' => preg_match('/^\d{0,9}$/D', $v),
                'bool' => true,
            };
            if (!$ok) $err .= "Неверное значение: «{$labels[$f]}». ";
            $new[$f] = match ($type) {
                'bool' => $v === '1' ? '1' : '0',
                'price', 'int' => $v === '' ? null : (string)(int)$v,
                default => $v,
            };
        }
        if (isset($new['price_kind']) && $new['price_kind'] !== 'contract' && $new['price'] === null) $err .= 'Укажите цену или выберите «договорная». ';
        if ($err !== '') view('content', $tTitle, $vars + ['rows' => $list(), 'err' => $err, 'errId' => $id], 422);

        $o = $n = [];
        foreach ($new as $f => $v) {
            if (($old[$f] === null ? null : (string)$old[$f]) !== $v) { $o[$f] = $old[$f]; $n[$f] = $v; }
        }
        if ($n) {
            db()->beginTransaction();
            $set = implode(', ', array_map(fn($f) => "$f = ?", array_keys($n))); // имена полей — из $fields, не из запроса
            db()->prepare("UPDATE $tbl SET $set WHERE id = ?")->execute([...array_values($n), $id]);
            audit($tbl, (string)$id, $json($o), $json($n));
            db()->commit();
        }
        go("content&t=$t&ok=1");
    }

    if ($a === 'sort') {
        $l = $list();
        $i = array_search((int)$in('id'), array_map('intval', array_column($l, 'id')), true);
        $j = $i === false ? false : $i + ($in('dir') === 'up' ? -1 : 1);
        if ($i === false || !isset($l[$j])) page('Не найдено', '<p>Нельзя переместить.</p>', 404);
        [$l[$i], $l[$j]] = [$l[$j], $l[$i]];
        db()->beginTransaction();
        // ponytail: после обмена перенумеровываем весь список 1..n — лишние строки аудита только при «дырявом» sort
        foreach ($l as $k => $r) {
            if ((int)$r['sort'] === $k + 1) continue;
            db()->prepare("UPDATE $tbl SET sort = ? WHERE id = ?")->execute([$k + 1, $r['id']]);
            audit($tbl, (string)$r['id'], $json(['sort' => (int)$r['sort']]), $json(['sort' => $k + 1]));
        }
        db()->commit();
        go("content&t=$t&ok=1");
    }
    if ($post) page('Не найдено', '<p>Действие не найдено.</p>', 404);
    view('content', $tTitle, $vars + ['rows' => $list()]);
}

if ($p === 'settings') {
    // §3: группа → право; trusted_html (hero_h1, why_h2) — отдельно, только owner
    $grpPerm = ['seo' => 'content', 'ld' => 'content', 'hero' => 'content', 'texts' => 'content',
        'contacts' => 'contacts', 'requisites' => 'contacts', 'legal' => 'contacts', 'metrika' => 'owner_settings', 'mail' => 'owner_settings'];
    $g = is_string($_GET['g'] ?? null) ? $_GET['g'] : 'seo';
    if (!isset($grpPerm[$g])) page('Не найдено', '<p>Группа не найдена.</p>', 404);
    if (!can($grpPerm[$g])) deny();
    $list = rows('SELECT * FROM settings WHERE grp = ? ORDER BY sort, key', [$g]);
    $vars = ['g' => $g, 'groups' => array_keys(array_filter($grpPerm, 'can')), 'list' => $list, 'err' => ''];

    if ($a === 'save') {
        $new = [];
        $err = '';
        foreach ($list as $r) {
            $k = $r['key'];
            if (!array_key_exists($k, $_POST)) continue; // поле не прислано — не меняем (disabled trusted у не-владельца)
            if ($r['trusted_html'] && !can('trusted_html')) deny();
            $v = $in($k);
            $ok = preg_match('/^.{0,4000}$/suD', $v) && match ($k) {
                'base_url' => preg_match('~^https?://\S+/$~D', $v),
                'whatsapp', 'telegram' => valid_href($v),
                'phone_mobile', 'phone_city' => valid_href("tel:$v"),
                'email' => valid_href("mailto:$v"),
                'metrika_id' => preg_match('/^\d{0,12}$/D', $v),
                'notify_email' => preg_match('/^([^@\s]+@[^@\s]+\.[a-z]{2,})?$/iD', $v),
                'ld_geo_lat', 'ld_geo_lon' => preg_match('/^-?\d{1,3}(\.\d+)?$/D', $v),
                'ld_opens', 'ld_closes' => preg_match('/^[0-2]\d:[0-5]\d$/D', $v),
                default => true,
            };
            if (!$ok) $err .= "Неверное значение: «{$r['label']}». ";
            if ($v !== $r['value']) $new[$k] = [$r['value'], $v];
        }
        if ($err !== '') view('settings', 'Настройки', ['list' => array_map(fn($r) => ['value' => $in($r['key'])] + $r, $list), 'err' => $err] + $vars, 422);
        db()->beginTransaction();
        foreach ($new as $k => [$o, $v]) {
            db()->prepare('UPDATE settings SET value = ? WHERE key = ?')->execute([$v, $k]);
            audit('settings', $k, $o, $v);
        }
        db()->commit();
        go("settings&g=$g&ok=1");
    }
    if ($post) page('Не найдено', '<p>Действие не найдено.</p>', 404);
    view('settings', 'Настройки', $vars);
}

if ($p === 'users') {
    $me = (int)user()['id'];
    $one = function (int $id): array {
        $st = db()->prepare('SELECT id, login, role, is_active FROM users WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: page('Не найдено', '<p>Пользователь не найден.</p>', 404);
    };
    // MINOR-9: в аудит только login/role/is_active; pass_hash и пароль — никогда
    $pub = fn(array $u): array => ['login' => $u['login'], 'role' => $u['role'], 'is_active' => (int)$u['is_active']];
    $err = '';
    if ($a === 'save') {
        $role = $in('role');
        if (!isset(ROLE_PERMS[$role])) $err = 'Неверная роль.';
        elseif ($in('id') === '') { // новый пользователь
            $login = $in('login');
            $pass = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            if (!preg_match('/^[a-z0-9_.-]{3,32}$/D', $login)) $err = 'Логин: a-z 0-9 _ . - , 3–32 символа.';
            elseif (!preg_match('/^.{12,200}$/suD', $pass)) $err = 'Пароль — не короче 12 символов.';
            elseif (rows('SELECT 1 FROM users WHERE login = ?', [$login])) $err = 'Логин уже занят.';
            else {
                db()->beginTransaction();
                db()->prepare('INSERT INTO users (login, pass_hash, role) VALUES (?, ?, ?)')->execute([$login, password_hash($pass, PASSWORD_DEFAULT), $role]);
                $id = (int)db()->lastInsertId();
                audit('users', (string)$id, null, $json($pub($one($id))));
                db()->commit();
                go('users&ok=1');
            }
        } else {
            $u = $one((int)$in('id'));
            if ((int)$u['id'] === $me) $err = 'Свою роль менять нельзя.'; // иначе владелец может остаться без владельцев
            else {
                db()->beginTransaction();
                db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $u['id']]);
                audit('users', (string)$u['id'], $json($pub($u)), $json($pub(['role' => $role] + $u)));
                db()->commit();
                go('users&ok=1');
            }
        }
    } elseif ($a === 'password') {
        $u = $one((int)$in('id'));
        $pass = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        if (!preg_match('/^.{12,200}$/suD', $pass)) $err = 'Пароль — не короче 12 символов.';
        else {
            db()->beginTransaction();
            db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
            audit('users', (string)$u['id'], null, $json(['login' => $u['login'], 'password' => 'changed']));
            db()->commit();
            go('users&ok=1');
        }
    } elseif ($a === 'toggle') {
        $u = $one((int)$in('id'));
        if ((int)$u['id'] === $me) $err = 'Себя отключить нельзя.';
        else {
            db()->beginTransaction();
            db()->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$u['id']]);
            audit('users', (string)$u['id'], $json($pub($u)), $json($pub(['is_active' => 1 - (int)$u['is_active']] + $u)));
            db()->commit();
            go('users&ok=1');
        }
    } elseif ($post) page('Не найдено', '<p>Действие не найдено.</p>', 404);
    view('users', 'Пользователи', ['list' => rows('SELECT id, login, role, is_active, created_at FROM users ORDER BY id'), 'me' => $me, 'err' => $err], $err ? 422 : 200);
}

// §5 CSV: ячейка, начинающаяся с = + - @ TAB CR, — с префиксом ' (иначе Excel выполнит формулу)
function csv_cell($v): string
{
    $v = (string)$v;
    return $v !== '' && str_contains("=+-@\t\r", $v[0]) ? "'$v" : $v;
}

if ($p === 'leads') {
    $statuses = ['new' => 'новая', 'in_work' => 'в работе', 'done' => 'выполнена', 'spam' => 'спам'];
    $sources = ['calc' => 'расчёт', 'call' => 'звонок', 'material' => 'материал'];
    // фильтры — из GET и для списка, и для CSV, и для возврата после POST; неизвестные значения игнорируются
    $q = fn(string $k): string => is_string($_GET[$k] ?? null) ? $_GET[$k] : '';
    $date = fn(string $k): string => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $q($k)) ? $q($k) : '';
    $f = ['status' => isset($statuses[$q('status')]) ? $q('status') : '', 'source' => isset($sources[$q('source')]) ? $q('source') : '',
        'from' => $date('from'), 'to' => $date('to')];
    $w = $args = [];
    if ($f['status'] !== '') { $w[] = 'status = ?'; $args[] = $f['status']; }
    if ($f['source'] !== '') { $w[] = 'source = ?'; $args[] = $f['source']; }
    if ($f['from'] !== '') { $w[] = 'created_at >= ?'; $args[] = $f['from']; }
    if ($f['to'] !== '') { $w[] = "created_at < date(?, '+1 day')"; $args[] = $f['to']; }
    $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $qs = http_build_query(array_filter($f));
    $one = function (int $id): array {
        $st = db()->prepare('SELECT id, source, status, created_at FROM leads WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: page('Не найдено', '<p>Заявка не найдена.</p>', 404);
    };

    if ($a === 'status') {
        $l = $one((int)$in('id'));
        $to = $in('status');
        if (!isset($statuses[$to])) page('Заявки', '<p class="err">Неверный статус.</p>', 422);
        if ($to !== $l['status']) {
            db()->beginTransaction();
            db()->prepare('UPDATE leads SET status = ? WHERE id = ?')->execute([$to, $l['id']]);
            db()->prepare('INSERT INTO lead_events (lead_id, user_id, from_status, to_status) VALUES (?, ?, ?, ?)')
                ->execute([$l['id'], user()['id'], $l['status'], $to]);
            db()->commit();
        }
        go("leads&$qs&ok=1");
    }
    if ($a === 'delete') {
        if (!can('leads_delete')) deny();
        $l = $one((int)$in('id'));
        db()->beginTransaction();
        db()->prepare('DELETE FROM leads WHERE id = ?')->execute([$l['id']]); // lead_events — ON DELETE CASCADE
        // в журнал — без ПДн (имя/телефон после удаления храниться не должны)
        audit('leads', (string)$l['id'], $json($l), null);
        db()->commit();
        go("leads&$qs&ok=1");
    }
    if ($post) page('Не найдено', '<p>Действие не найдено.</p>', 404);

    $ga = $q('a');
    if ($ga === 'csv') {
        audit('leads', 'csv', null, $json($f)); // выгрузка ПДн — в журнал (только фильтры)
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="leads-' . gmdate('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM — чтобы Excel открыл UTF-8
        fputcsv($out, ['№', 'Дата (UTC)', 'Источник', 'Статус', 'Имя', 'Телефон', 'Услуга', 'Материал', 'Комментарий', 'Редакция согласия'], ';', '"', '');
        foreach (rows("SELECT * FROM leads $where ORDER BY id", $args) as $r) {
            fputcsv($out, array_map('csv_cell', [$r['id'], $r['created_at'], $sources[$r['source']], $statuses[$r['status']],
                $r['name'], $r['phone'], $r['service'], $r['material'], $r['comment'], $r['consent_rev']]), ';', '"', '');
        }
        exit;
    }
    if ($ga !== '') page('Не найдено', '<p>Действие не найдено.</p>', 404);

    // ponytail: без пагинации — последние 200 по фильтру; остальное — через фильтр или CSV
    $list = rows("SELECT * FROM leads $where ORDER BY id DESC LIMIT 200", $args);
    $ev = [];
    if ($list) {
        $ids = implode(',', array_map('intval', array_column($list, 'id')));
        foreach (rows("SELECT e.*, u.login FROM lead_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.lead_id IN ($ids) ORDER BY e.id") as $r) $ev[$r['lead_id']][] = $r;
    }
    view('leads', 'Заявки', ['list' => $list, 'ev' => $ev, 'total' => (int)rows("SELECT COUNT(*) n FROM leads $where", $args)[0]['n'],
        'f' => $f, 'qs' => $qs, 'statuses' => $statuses, 'sources' => $sources]);
}

if ($post) page('Не найдено', '<p>Действие не найдено.</p>', 404); // stats, audit — только чтение

if ($p === 'stats') {
    // ponytail: окно фиксировано — 30 дней; выбор периода — когда попросят
    $since = gmdate('Y-m-d', time() - 29 * 86400);
    $by = fn(string $col, string $extra = '') => rows("SELECT $col k, SUM(hits) n FROM stats WHERE day >= ? $extra GROUP BY $col ORDER BY n DESC, k LIMIT 20", [$since]);
    view('stats', 'Статистика', [
        'since' => $since,
        'byDay' => rows('SELECT day k, SUM(hits) n FROM stats WHERE day >= ? GROUP BY day ORDER BY day DESC', [$since]),
        'byPage' => $by('page'),
        'byRef' => $by('ref_domain', "AND ref_domain <> ''"), // top-20
        'byUa' => $by('ua_class'),
        'consent' => rows("SELECT day, SUM(CASE WHEN choice = 'accept' THEN n ELSE 0 END) acc, SUM(CASE WHEN choice = 'reject' THEN n ELSE 0 END) rej
            FROM consent_stats WHERE day >= ? GROUP BY day ORDER BY day DESC", [$since]),
    ]);
}

if ($p === 'audit') {
    // ponytail: последние 300 записей, без фильтров
    view('audit', 'Журнал изменений', ['list' => rows('SELECT a.*, u.login FROM audit_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 300')]);
}
