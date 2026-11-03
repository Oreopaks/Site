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

// JSON-ответ загрузки фото: admin.js показывает error у поля или кладёт img в поле
function jout(int $code, array $a): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

// Страница через общий layout; $main — готовый HTML (всё пользовательское — через e())
function page(string $title, string $main, int $code = 200): never
{
    http_response_code($code);
    $u = user();
    $csrf = $_SESSION['csrf'];
    $missing = $u ? array_column(rows("SELECT label FROM settings WHERE required = 1 AND trim(value) = '' ORDER BY sort"), 'label') : [];
    $bar = $u ? bar() : null;
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
    [$res, $uid, $pwVer] = login(is_string($_POST['login'] ?? null) ? $_POST['login'] : '', is_string($_POST['password'] ?? null) ? $_POST['password'] : '');
    if ($res === 'locked') page('Вход', $form('Слишком много неудачных попыток. Вход заблокирован на 15 минут.'), 423);
    if ($res === 'bad') page('Вход', $form('Неверный логин или пароль.'));
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    $_SESSION['pw_ver'] = $pwVer;
    $_SESSION['started'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    go('home');
}

if (!user()) {
    // отключён, сменён пароль или истёк срок — сессию не оставлять (иначе оживёт после реактивации)
    if (isset($_SESSION['uid'])) { $_SESSION = []; session_destroy(); }
    go('login');
}

$perm = match ($p) {
    'home', 'logout' => null,
    'content', 'settings' => 'content', // ponytail: права по группам/полям — в обработчиках M4
    'leads' => 'leads',
    'stats' => 'stats',
    'users' => 'users',
    'audit' => 'audit',
    'draft' => 'publish',
    'trash', 'preview' => 'content',
    default => false,
};
if ($perm === false) page('Не найдено', '<p>Раздел не найден.</p>', 404);
if ($perm !== null && !can($perm)) deny();
// тело больше post_max_size: PHP отбросил его целиком (и _csrf) — вместо 403 понятная причина; ничего не выполняется
if ($post && !$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) jout(413, ['error' => 'Файл больше 10 МБ']);
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

// Единая таблица полей — и для формы (maxlength, required, подсказки), и для сервера (check_row): лимиты не расходятся.
// t → [таблица, kind в items, заголовок, где на сайте, min, max строк, поля]; поле → [тип, maxlen, подпись, подсказка «где на сайте»].
// Тип: line, area1, img — обязательные; line0 — нет; int — только цифры, maxlen цифр (6 → ≤999999); bool, kind — без maxlen.
function lists(): array
{
    $title = fn(int $n, string $hint) => ['title' => ['line', $n, 'Название', $hint]];
    $text = fn(int $n, string $hint) => ['text' => ['area1', $n, 'Текст', $hint]];
    $photo = ['img' => ['img', 100, 'Путь к картинке', 'Фото карточки: файл assets/….webp или .svg'],
        'alt' => ['line', 120, 'Описание фото для поисковиков и незрячих', 'Что на фото, например «Дорожный каток в аренду»']];
    $price = fn(int $unit) => ['price_kind' => ['kind', 0, 'Цена', 'Фиксированная, «от …» или плашка «договорная»'],
        'price' => ['int', 6, 'Цена, ₽', 'Обязательно, если цена не договорная. Сумма на сайте, до 999999'],
        'unit' => ['line0', $unit, 'Единица', 'После цены через «/», например «смена»']];
    $stat = ['title' => ['line', 22, 'Подпись', 'Под числом, например «единиц спецтехники»'],
        'text' => ['line0', 8, 'Текст вместо числа', 'Показывается, если число пустое, например «Под ключ»'],
        'num' => ['int', 4, 'Число', 'Крупная цифра, до 9999'],
        'suffix' => ['line0', 3, 'После числа', 'Например «+»']];
    return [
        'services' => ['services', null, 'Услуги', 'Блок «Услуги компании»; названия — ещё в форме заявки и в подвале', 1, 12,
            $title(40, 'Заголовок карточки услуги') + $text(200, 'Описание под заголовком карточки') + [
            'href' => ['href', 100, 'Ссылка', 'Куда ведёт название в подвале и в карточке: #раздел, /страница/, https://…, tel: или mailto:'],
            'in_form' => ['bool', 0, 'В форме', 'Показать в списке услуг формы заявки'],
            'in_footer' => ['bool', 0, 'В подвале', 'Показать ссылкой в подвале сайта']]],
        'fleet_cards' => ['fleet_cards', null, 'Техника: карточки', 'Блок «Спецтехника в аренду» — карточки с фото', 1, 12,
            $title(40, 'Заголовок карточки техники') + $photo
            + ['img_pos' => ['pos', 9, 'Позиция картинки', 'Какая часть фото видна в рамке: «50% 50%» — центр']] + $price(8)],
        'fleet_prices' => ['fleet_prices', null, 'Техника: прайс', 'Блок «Спецтехника в аренду» — «Цены на аренду» под карточками', 1, 40,
            ['name' => ['line', 40, 'Название', 'Строка прайса слева'], 'model' => ['line0', 30, 'Модель', 'Серым после названия']] + $price(12)
            + ['is_sub' => ['bool', 0, 'Подстрока', 'Строка с «—» и отступом под предыдущей']]],
        'materials' => ['materials', null, 'Материалы', 'Блок «Сыпучие материалы» — карточки с фото и кнопкой расчёта', 1, 12,
            $title(30, 'Заголовок карточки материала') + $text(150, 'Описание под заголовком карточки') + $photo
            + ['badge' => ['line0', 14, 'Бейдж', 'Жёлтая плашка на фото; пусто — без плашки']] + $price(8)],
        'nav' => ['items', 'nav', 'Меню', 'Меню в шапке сайта и мобильное меню', 1, 6,
            $title(16, 'Пункт меню в шапке') + ['href' => ['href', 100, 'Ссылка', 'Куда ведёт пункт: #раздел страницы, https://…, tel: или /страница/ (неопубликованная → 404)']]],
        'marquee' => ['items', 'marquee', 'Бегущая строка', 'Бегущая строка под первым экраном', 1, 12,
            ['title' => ['line', 40, 'Текст', 'Фраза бегущей строки, между фразами — ◆']]],
        'stat_hero' => ['items', 'stat_hero', 'Цифры в шапке', 'Первый экран — цифры под кнопками', 1, 12, $stat],
        'stat_bar' => ['items', 'stat_bar', 'Цифры под шапкой', 'Жёлтая полоса с цифрами', 1, 12,
            array_replace($stat, ['text' => ['line0', 7, 'Текст вместо числа', 'Показывается, если число пустое, например «Пн–Сб»']])], // 8 букв одним словом не влезает @390 (ADR п.6)
        'why' => ['items', 'why', 'О компании: карточки', 'Блок «О компании» — карточки справа', 1, 12,
            $title(30, 'Заголовок карточки') + $text(160, 'Текст карточки')],
        'step' => ['items', 'step', 'Этапы работы', 'Блок «Этапы работы» — пронумерованные шаги', 1, 12,
            $title(24, 'Название этапа') + $text(120, 'Описание этапа')],
    ];
}

// Строка по таблице полей → [поле => что исправить]. Одна для save и для publish (там картинка могла пропасть с диска)
function check_row(array $fields, array $r): array
{
    $err = [];
    foreach ($fields as $f => [$type, $max, $label]) {
        $v = (string)($r[$f] ?? '');
        $e = match (true) {
            $v === '' && in_array($type, ['line', 'area1', 'img'], true) => 'Введите ' . mb_strtolower(mb_substr($label, 0, 1)) . mb_substr($label, 1),
            $type === 'int' => preg_match("/^\\d{0,$max}$/D", $v) ? null : 'Введите целое число от 0 до ' . str_repeat('9', $max),
            $max > 0 && !preg_match("/^.{0,$max}$/suD", $v) => "Сократите до $max символов — сейчас " . mb_strlen($v),
            $type === 'href' => valid_href($v) ? null : 'Введите ссылку вида #раздел, /страница/, https://…, tel:+7… или mailto:…', // D5
            $type === 'img' => !preg_match('~^assets/[a-z0-9_\-/]+\.(webp|svg)$~D', $v) ? 'Введите путь вида assets/имя.webp' // D10
                : (is_file(__DIR__ . '/../' . $v) ? null : 'Картинка не найдена на сервере — укажите другую'),
            $type === 'pos' => preg_match('/^\d{1,3}% \d{1,3}%$/D', $v) ? null : 'Введите позицию вида «50% 50%»', // уходит в style
            $type === 'kind' => in_array($v, ['fixed', 'from', 'contract'], true) ? null : 'Выберите вид цены',
            default => null,
        };
        if ($e !== null) $err[$f] = $e;
    }
    if (isset($fields['price_kind'], $r['price_kind']) && $r['price_kind'] !== 'contract' && (string)($r['price'] ?? '') === '') $err['price'] ??= 'Введите цену или выберите «договорная»';
    return $err;
}

// Меню в шапке на 1280 — впритык: сумма длин названий + 4 на пункт (отступы) ≤ NAV_ROOM; → сколько символов ещё влезет
const NAV_ROOM = 66;
function nav_left(array $rows): int
{
    return NAV_ROOM - array_sum(array_map(fn($r) => mb_strlen((string)$r['title']) + 4, $rows));
}

// Опубликованное: все 10 списков из живых таблиц
function snapshot(): array
{
    $d = [];
    foreach (lists() as $t => [$tbl, $kind]) {
        $d[$t] = rows("SELECT * FROM $tbl" . ($kind === null ? '' : ' WHERE kind = ?') . ' ORDER BY sort, id', $kind === null ? [] : [$kind]);
    }
    return $d;
}

// Строка drafts + логин последнего правившего; null — черновика нет
function draft(): ?array
{
    return rows('SELECT d.*, u.login FROM drafts d LEFT JOIN users u ON u.id = d.updated_by')[0] ?? null;
}

// Что видит админка: черновик, иначе опубликованное
function cur(): array
{
    $d = draft();
    return ($d ? json_decode($d['data'], true) : null) ?: snapshot();
}

// Единственное сравнение опубликованного и черновика (плашка, publish; в M2 — корзина, бейджи)
// → [t => ['add' => [id => строка], 'del' => [id => строка], 'chg' => [id => [старые поля, новые поля]]]]
function diff(array $live, array $draft): array
{
    $s = fn($v) => $v === null ? null : (string)$v; // живые из PDO — int, из формы — строки
    $out = [];
    foreach (lists() as $t => [, , , , , , $fields]) {
        $l = array_column($live[$t] ?? [], null, 'id');
        $d = array_column($draft[$t] ?? [], null, 'id');
        $chg = [];
        foreach (array_intersect_key($d, $l) as $id => $r) {
            $o = $n = [];
            foreach ([...array_keys($fields), 'sort'] as $f) {
                if ($s($l[$id][$f]) !== $s($r[$f] ?? null)) { $o[$f] = $l[$id][$f]; $n[$f] = $r[$f] ?? null; }
            }
            if ($n) $chg[$id] = [$o, $n];
        }
        $out[$t] = ['add' => array_diff_key($d, $l), 'del' => array_diff_key($l, $d), 'chg' => $chg];
    }
    return $out;
}

// Плашка: null — черновика нет; n = null — data не читается (поможет только «Отменить всё»);
// items — «Что изменено»: [t, id, блок, название, новое/изменено/удалено, логин автора строки или —]
function bar(): ?array
{
    $d = draft();
    if (!$d) return null;
    $data = json_decode($d['data'], true);
    if (!is_array($data)) return ['n' => null, 'items' => [], 'login' => $d['login'], 'at' => $d['updated_at']];
    $who = array_column(rows('SELECT id, login FROM users'), 'login', 'id');
    $items = [];
    foreach (diff(snapshot(), $data) as $t => $x) {
        $rows = array_column($data[$t] ?? [], null, 'id');
        foreach (['add' => 'новое', 'chg' => 'изменено', 'del' => 'удалено'] as $k => $st) foreach ($x[$k] as $id => $r) {
            $r = $rows[$id] ?? $r; // chg — название из черновика
            $items[] = [$t, $id, lists()[$t][2], (string)($r['title'] ?? $r['name'] ?? ''), $st, $who[$data['_by'][$t][$id] ?? 0] ?? '—'];
        }
    }
    return ['n' => count($items), 'items' => $items, 'login' => $d['login'], 'at' => $d['updated_at']];
}

// Автор правки строки — служебный ключ черновика _by: t → id → user_id (diff, publish и превью читают только списки lists())
function by(array $d, string $t, int $id): array
{
    $d['_by'][$t][$id] = (int)user()['id'];
    return $d;
}

// Правка черновика: $f(data) → новая data или null (нечего менять → ROLLBACK, null); иначе → новый rev.
// Первая правка снимает все списки с живых таблиц. exec('BEGIN IMMEDIATE'): beginTransaction() даёт DEFERRED,
// а commit() после exec-BEGIN бросает «no active transaction».
function draft_write(callable $f): ?int
{
    $db = db();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $d = draft();
        $data = ($d ? json_decode($d['data'], true) : null) ?: snapshot();
        $new = $f($data);
        if ($new === null) { $db->exec('ROLLBACK'); return null; }
        // стартовый rev случайный: после publish/discard строка удаляется, и rev=1 заново дал бы старому «Отменить» стереть чужую правку
        $st = $db->prepare("INSERT INTO drafts (id, data, prev, rev, updated_by) VALUES (1, ?, ?, ?, ?)
            ON CONFLICT(id) DO UPDATE SET prev = data, data = excluded.data, rev = rev + 1, updated_by = excluded.updated_by, updated_at = datetime('now')
            RETURNING rev");
        $st->execute([json_encode($new, JSON_UNESCAPED_UNICODE), json_encode($data, JSON_UNESCAPED_UNICODE), random_int(1, 1 << 48), user()['id']]);
        $rev = (int)$st->fetchColumn();
        $st->closeCursor();
        $db->exec('COMMIT');
        return $rev;
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

// Новый id: 1 + max по живой таблице, черновику (у items — все kind) и корзине той же таблицы — id не переиспользуются
function new_id(array $d, string $tbl): int
{
    $ts = array_keys(array_filter(lists(), fn($l) => $l[0] === $tbl));
    $ids = array_column(array_merge(...array_map(fn($t) => $d[$t], $ts)), 'id');
    $ids[] = rows("SELECT MAX(id) m FROM $tbl")[0]['m'];
    $ids[] = rows('SELECT MAX(json_extract(row, \'$.id\')) m FROM trash WHERE t IN (' . implode(',', array_fill(0, count($ts), '?')) . ')', $ts)[0]['m'];
    return 1 + max(array_map('intval', $ids));
}

// Строка в конец (или в начало, $first) списка t черновика; null — список уже полон (max)
function append(array $d, string $t, array $row, bool $first = false): ?array
{
    if (count($d[$t]) >= lists()[$t][5]) return null;
    $sorts = array_map('intval', array_column($d[$t], 'sort'));
    // ponytail: в начало — sort = min−1 (может стать ≤0), остальные строки не перенумеровываются и не получают «изменено»
    $row['sort'] = $first ? min([1, ...$sorts]) - 1 : 1 + max([0, ...$sorts]);
    if ($first) array_unshift($d[$t], $row);
    else $d[$t][] = $row;
    return $d;
}

// «Вернуть как было»: строка t/id черновика = опубликованная (новая убирается, удалённая встаёт на место по sort) → rev или null
function revert(string $t, int $id): ?int
{
    return draft_write(function (array $d) use ($t, $id) {
        $l = array_values(array_filter($d[$t], fn($r) => (int)$r['id'] !== $id));
        foreach (snapshot()[$t] as $r) if ((int)$r['id'] === $id) $l[] = $r;
        usort($l, fn($x, $y) => (int)$x['sort'] <=> (int)$y['sort']);
        if ($l === $d[$t]) return null;
        $d[$t] = $l;
        unset($d['_by'][$t][$id]);
        return $d;
    });
}

function trash_purge(): void
{
    db()->exec("DELETE FROM trash WHERE deleted_at < datetime('now', '-30 days')");
}
$in = fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
$a = $post && is_string($_GET['a'] ?? null) ? $_GET['a'] : '';

if ($p === 'home') {
    page('Обзор', '<p>Вы вошли как <b>' . e(user()['login']) . '</b> (' . e(user()['role']) . ').</p>');
}

if ($p === 'content') {
    $t = is_string($_GET['t'] ?? null) ? $_GET['t'] : 'services';
    [$tbl, $kind, $tTitle, $where, $min, $max, $fields] = lists()[$t] ?? [null, null, null, null, 0, 0, []];
    if ($tbl === null) page('Не найдено', '<p>Список не найден.</p>', 404);

    if ($a === 'upload') {
        // фото → своё имя .webp, перекодировано GD (полиглоты и EXIF не проходят); сырой файл PHP удалит сам
        // ponytail: без GC — неиспользованные файлы в uploads копятся; добавить чистку, когда каталог > 100 МБ
        $big = 'Файл больше 10 МБ';
        $notImg = 'Это не картинка. Подойдут JPG, PNG или WebP';
        $f = $_FILES['photo'] ?? null;
        $fe = is_array($f) && is_int($f['error'] ?? null) ? $f['error'] : UPLOAD_ERR_NO_FILE;
        if ($fe === UPLOAD_ERR_INI_SIZE || $fe === UPLOAD_ERR_FORM_SIZE) jout(413, ['error' => $big]);
        if ($fe !== UPLOAD_ERR_OK) jout(422, ['error' => 'Файл не загрузился — выберите его ещё раз']);
        if (filesize($f['tmp_name']) > 10 << 20) jout(422, ['error' => $big]);
        $info = @getimagesize($f['tmp_name']);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) jout(422, ['error' => $notImg]);
        // до декодирования: 24 Мп × 4 байта ≈ 100 МБ памяти, больше — бомба
        if ($info[0] * $info[1] > 24_000_000) jout(422, ['error' => 'Слишком большое разрешение — уменьшите фото до 24 Мп']);
        ini_set('memory_limit', '256M');
        $im = @imagecreatefromstring(file_get_contents($f['tmp_name']));
        if (!$im) jout(422, ['error' => $notImg]);
        // ponytail: только повороты (3, 6, 8) — зеркальные Orientation телефоны не пишут
        $o = $info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data') ? (int)(@exif_read_data($f['tmp_name'])['Orientation'] ?? 1) : 1;
        if (in_array($o, [3, 6, 8], true)) $im = imagerotate($im, [3 => 180, 6 => -90, 8 => 90][$o], 0);
        imagepalettetotruecolor($im); // imagewebp не пишет палитровые PNG
        if (imagesx($im) > 1200) $im = imagescale($im, 1200);
        $dir = dirname(__DIR__) . '/assets/uploads';
        $name = bin2hex(random_bytes(16)) . '.webp';
        if (!is_dir($dir)) mkdir($dir, 0755);
        if (!imagewebp($im, "$dir/$name", 80)) jout(500, ['error' => 'Не удалось сохранить фото — попробуйте ещё раз']);
        jout(200, ['img' => "assets/uploads/$name"]);
    }
    $list = fn() => cur()[$t]; // правки идут в черновик; посетители видят живые таблицы до «Опубликовать»
    // dif — бейджи строк: что в этом списке добавлено/удалено/изменено относительно опубликованного; err — [поле или '_' => текст]
    $vars = ['t' => $t, 'fields' => $fields, 'where' => $where, 'err' => [], 'errId' => 0, 'min' => $min, 'max' => $max,
        'dif' => diff(snapshot(), cur())[$t]];
    $full = "Не больше $max в списке «{$tTitle}» — удалите лишнее, чтобы добавить.";
    $wide = 'Меню не помещается в шапку — сократите названия';

    if ($a === 'save') {
        $id = (int)$in('id'); // 0 (пусто) — новая строка
        $old = $id ? current(array_filter($list(), fn($r) => (int)$r['id'] === $id)) : [];
        if ($id && !$old) page('Не найдено', '<p>Строка не найдена.</p>', 404);
        $new = [];
        foreach ($fields as $f => [$type]) {
            // textarea шлёт перенос как \r\n, а maxlength браузера считает его за 1 символ
            $new[$f] = $type === 'bool' ? ($in($f) === '1' ? '1' : '0') : str_replace("\r\n", "\n", $in($f));
        }
        $err = check_row($fields, $new);
        if ($err) view('content', $tTitle, ['rows' => $list(), 'err' => $err, 'errId' => $id] + $vars, 422);
        foreach ($fields as $f => [$type]) if ($type === 'int') $new[$f] = $new[$f] === '' ? null : (string)(int)$new[$f];
        // правило шапки — по всему меню после правки, внутри той же транзакции
        $tooWide = false;
        $fit = function (?array $d) use ($t, &$tooWide): ?array {
            if ($d !== null && $t === 'nav' && nav_left($d['nav']) < 0) { $tooWide = true; return null; }
            return $d;
        };

        if (!$id) {
            $rev = draft_write(function (array $d) use ($t, $tbl, $kind, $new, $fit, $in) {
                $nid = new_id($d, $tbl);
                $d = append($d, $t, ['id' => $nid] + ($kind === null ? [] : ['kind' => $kind]) + $new, $in('at') === 'first');
                return $d === null ? null : $fit(by($d, $t, $nid));
            });
            if ($rev === null) view('content', $tTitle, ['rows' => $list(), 'err' => $tooWide ? ['title' => $wide] : ['_' => $full], 'errId' => 0] + $vars, 422);
            go("content&t=$t&ok=saved&rev=$rev");
        }
        $n = [];
        foreach ($new as $f => $v) {
            if (($old[$f] === null ? null : (string)$old[$f]) !== $v) $n[$f] = $v;
        }
        if (!$n) go("content&t=$t&ok=same"); // ничего не изменилось — черновик не трогаем и «Сохранено» не пишем
        // в черновик пишутся только изменённые поля — параллельная правка других полей той же строки не затирается
        $rev = draft_write(function (array $d) use ($t, $id, $n, $fit) {
            foreach ($d[$t] as $k => $r) if ((int)$r['id'] === $id) { $d[$t][$k] = $n + $r; return $fit(by($d, $t, $id)); }
            return null;
        });
        if ($tooWide) view('content', $tTitle, ['rows' => $list(), 'err' => ['title' => $wide], 'errId' => $id] + $vars, 422);
        if ($rev === null) page('Не найдено', '<p>Строка не найдена.</p>', 404);
        go("content&t=$t&ok=saved&rev=$rev");
    }

    if ($a === 'sort') {
        $id = (int)$in('id');
        $up = $in('dir') === 'up';
        $rev = draft_write(function (array $d) use ($t, $id, $up) {
            $l = $d[$t];
            $i = array_search($id, array_map('intval', array_column($l, 'id')), true);
            $j = $i === false ? false : $i + ($up ? -1 : 1);
            if ($i === false || !isset($l[$j])) return null;
            [$l[$i], $l[$j]] = [$l[$j], $l[$i]];
            // ponytail: после обмена перенумеровываем весь список 1..n — лишние «изменено» только при «дырявом» sort
            foreach ($l as $k => $r) $l[$k]['sort'] = $k + 1;
            $d[$t] = $l;
            return by($d, $t, $id); // автор — у перемещённой строки, не у сдвинутых
        });
        if ($rev === null) page('Не найдено', '<p>Нельзя переместить.</p>', 404);
        go("content&t=$t&ok=saved&rev=$rev");
    }

    if ($a === 'delete') {
        $id = (int)$in('id');
        $last = false;
        $rev = draft_write(function (array $d) use ($t, $id, $min, &$last) {
            $l = array_values(array_filter($d[$t], fn($r) => (int)$r['id'] !== $id));
            if (count($l) === count($d[$t])) return null;
            if (count($l) < $min) { $last = true; return null; }
            $d[$t] = $l;
            return by($d, $t, $id);
        });
        if ($last) view('content', $tTitle, ['rows' => $list(), 'err' => ['_' => 'Не удалено: в списке должен остаться хотя бы один элемент.'], 'errId' => -1] + $vars, 422);
        if ($rev === null) page('Не найдено', '<p>Строка не найдена.</p>', 404);
        go("content&t=$t&ok=deleted&rev=$rev");
    }

    if ($a === 'revert') {
        $rev = revert($t, (int)$in('id'));
        go("content&t=$t" . ($rev === null ? '' : "&ok=reverted&rev=$rev"));
    }

    if ($a === 'undo') {
        // один шаг назад, только если после этой правки черновик никто не менял (rev совпал)
        $st = db()->prepare("UPDATE drafts SET data = prev, prev = NULL, rev = rev + 1, updated_by = ?, updated_at = datetime('now') WHERE rev = ? AND prev IS NOT NULL");
        $st->execute([user()['id'], (int)$in('rev')]);
        if (!$st->rowCount()) page('Не отменено', '<p class="err">Уже изменил кто-то другой — отменить нельзя.</p><p><a href="/admin/?p=content&amp;t=' . $t . '">К списку</a></p>', 409);
        go("content&t=$t&ok=undone");
    }
    if ($post) page('Не найдено', '<p>Действие не найдено.</p>', 404);
    view('content', $tTitle, $vars + ['rows' => $list()]);
}

if ($p === 'draft') {
    if ($a === 'discard') {
        // не парсит data: битый черновик тоже отменяется
        db()->beginTransaction();
        if (db()->exec('DELETE FROM drafts')) audit('drafts', 'discard', null, null);
        db()->commit();
        go('content&ok=discarded');
    }
    if ($a === 'publish') {
        $db = db();
        $db->exec('BEGIN IMMEDIATE');
        try {
            $d = draft();
            $data = $d ? json_decode($d['data'], true) : null;
            if (!is_array($data)) { $db->exec('ROLLBACK'); go('content'); }
            $lists = lists();
            // «Опубликовать выбранное» (picked): цель — живое + отмеченные pick[] «t:id» из diff; остальное остаётся черновиком
            $target = $data;
            if (isset($_POST['picked'])) {
                $pick = is_array($_POST['pick'] ?? null) ? $_POST['pick'] : [];
                $target = snapshot();
                $hit = 0;
                foreach (diff($target, $data) as $t => $x) {
                    $l = array_column($target[$t], null, 'id');
                    $dr = array_column($data[$t], null, 'id');
                    foreach (array_keys($x['add'] + $x['chg'] + $x['del']) as $id) {
                        if (!in_array("$t:$id", $pick, true)) continue;
                        $hit++;
                        if (isset($dr[$id])) $l[$id] = $dr[$id];
                        else unset($l[$id]);
                    }
                    $target[$t] = array_values($l);
                }
                if (!$hit) { $db->exec('ROLLBACK'); page('Не опубликовано', '<p class="err">Нечего публиковать — отметьте хотя бы одно изменение.</p>', 422); }
            }
            // каждая строка итогового состояния — заново той же check_row (картинку могли удалить с диска); ошибка → ничего не публикуется
            $bad = [];
            foreach ($lists as $t => [, , $tt, , $min, $max, $fields]) {
                $l = $target[$t] ?? [];
                $at = '<a href="/admin/?p=content&amp;t=' . $t . '">' . e($tt) . '</a>';
                foreach ($l as $r) foreach (check_row($fields, $r) as $f => $m) $bad[] = "$at · «" . e($r['title'] ?? $r['name'] ?? '') . '» · ' . e($fields[$f][2] . ': ' . $m);
                if (count($l) < $min || count($l) > $max) $bad[] = "$at · " . e("должно быть от $min до $max");
                if ($t === 'nav' && nav_left($l) < 0) $bad[] = "$at · Меню не помещается в шапку — сократите названия";
            }
            if ($bad) {
                $db->exec('ROLLBACK');
                page('Не опубликовано', '<p class="err">Не опубликовано — на сайте всё как было, черновик сохранён. Исправьте:</p><ul class="err-list"><li>'
                    . implode('</li><li>', $bad) . '</li></ul>', 422);
            }
            foreach (diff(snapshot(), $target) as $t => $x) {
                [$tbl, $kind, , , , , $fields] = $lists[$t];
                foreach ($x['del'] as $id => $o) {
                    $db->prepare('INSERT INTO trash (t, row, user_id) VALUES (?, ?, ?)')->execute([$t, $json($o), user()['id']]);
                    $db->prepare("DELETE FROM $tbl WHERE id = ?")->execute([$id]);
                    audit($tbl, (string)$id, $json($o), null);
                }
                foreach ($x['add'] as $id => $r) {
                    // столбцы — из lists(); не присланные (text у nav и т.п.) получают DEFAULT схемы
                    $v = ['id' => $id, 'sort' => (int)$r['sort']] + ($kind === null ? [] : ['kind' => $kind]) + array_intersect_key($r, $fields);
                    $db->prepare("INSERT INTO $tbl (" . implode(', ', array_keys($v)) . ') VALUES (' . implode(', ', array_fill(0, count($v), '?')) . ')')
                        ->execute(array_values($v));
                    audit($tbl, (string)$id, null, $json($v));
                }
                foreach ($x['chg'] as $id => [$o, $n]) {
                    $set = implode(', ', array_map(fn($f) => "$f = ?", array_keys($n))); // имена полей — из lists(), не из черновика
                    $db->prepare("UPDATE {$lists[$t][0]} SET $set WHERE id = ?")->execute([...array_values($n), $id]);
                    audit($lists[$t][0], (string)$id, $json($o), $json($n));
                }
            }
            // восстановленные из корзины опубликованы — их строки trash больше не нужны (id не переиспользуются, см. new_id)
            // ids — JSON-массивом: PDO шлёт параметры текстом, а json_extract отдаёт integer
            foreach (array_keys($lists) as $t) $db->prepare("DELETE FROM trash WHERE t = ? AND json_extract(row, '$.id') IN (SELECT value FROM json_each(?))")
                ->execute([$t, $json(array_map('intval', array_column($target[$t] ?? [], 'id')))]);
            // остаток (не отмеченное) — по-прежнему черновик, авторы — только у неопубликованных строк; undo через публикацию — нет
            $left = array_map(fn($x) => $x['add'] + $x['chg'] + $x['del'], diff(snapshot(), $data));
            if (!array_filter($left)) $db->exec('DELETE FROM drafts');
            else {
                foreach ($data['_by'] ?? [] as $t => $ids) $data['_by'][$t] = array_intersect_key($ids, $left[$t] ?? []);
                $db->prepare('UPDATE drafts SET data = ?, prev = NULL, rev = rev + 1')->execute([$json($data)]);
            }
            trash_purge();
            $db->exec('COMMIT');
        } catch (Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
        go('content&ok=published');
    }
    if (!$post) header('Allow: POST');
    page('Не найдено', '<p>Действие не найдено.</p>', $post ? 404 : 405);
}

if ($p === 'trash') {
    if ($a === 'restore') {
        if ($in('t') !== '') { // удалено в черновике, не опубликовано → строка из живой таблицы обратно в черновик
            if (!isset(lists()[$in('t')])) page('Не найдено', '<p>Список не найден.</p>', 404);
            $rev = revert($in('t'), (int)$in('id'));
        } else { // из корзины → на прежнее место (sort из строки корзины); строка trash остаётся до «Опубликовать» (после «Отменить всё» её можно восстановить снова)
            $id = (int)$in('id');
            $full = false;
            $rev = draft_write(function (array $d) use ($id, $json, &$full) {
                $tr = rows('SELECT * FROM trash WHERE id = ?', [$id])[0] ?? null;
                $row = $tr ? json_decode($tr['row'], true) : null;
                if (!is_array($row) || !isset($d[$tr['t']]) || in_array((int)$row['id'], array_map('intval', array_column($d[$tr['t']], 'id')), true)) return null;
                $t = $tr['t'];
                if (count($d[$t]) >= lists()[$t][5]) { $full = true; return null; }
                $d[$t][] = $row;
                usort($d[$t], fn($x, $y) => [(int)$x['sort'], (int)$x['id']] <=> [(int)$y['sort'], (int)$y['id']]); // как ORDER BY sort, id на сайте
                audit('trash', 'restore', null, $json(['t' => $t] + $row));
                return by($d, $t, (int)$row['id']);
            });
            if ($full) page('Корзина', '<p class="err">Не восстановлено: список уже полон — удалите лишнее.</p><p><a href="/admin/?p=trash">В корзину</a></p>', 422);
        }
        go('trash' . ($rev === null ? '' : '&ok=restored'));
    }
    if ($post) page('Не найдено', '<p>Действие не найдено.</p>', 404);
    trash_purge();
    $gone = [];
    foreach (diff(snapshot(), cur()) as $t => $x) foreach ($x['del'] as $r) $gone[] = [$t, $r];
    view('trash', 'Корзина', ['gone' => $gone, 'titles' => array_map(fn($l) => $l[2], lists()),
        'drafted' => array_map(fn($l) => array_map('intval', array_column($l, 'id')), cur()),
        'list' => rows('SELECT t.*, u.login FROM trash t LEFT JOIN users u ON u.id = t.user_id ORDER BY t.id DESC')]);
}

// Превью: home.php на черновике (или живых). Сборка переменных — как в public/index.php, без hit()
if ($p === 'preview') {
    if ($post) page('Не найдено', '<p>Действие не найдено.</p>', 404);
    $d = array_intersect_key(cur(), lists()); // без служебного _by
    foreach ($d as &$l) usort($l, fn($x, $y) => [(int)$x['sort'], (int)$x['id']] <=> [(int)$y['sort'], (int)$y['id']]); // ORDER BY sort, id
    unset($l);
    $s = settings();
    [$nav, $marquee, $statHero, $statBar, $why, $steps] = [$d['nav'], $d['marquee'], $d['stat_hero'], $d['stat_bar'], $d['why'], $d['step']];
    [$services, $fleet, $prices, $materials] = [$d['services'], $d['fleet_cards'], $d['fleet_prices'], $d['materials']];
    // скрипты сайта не нужны и запрещены (XSS в черновике не исполнится); пути сайта относительные → <base href="/">
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'none'; connect-src 'none'; form-action 'none'; frame-ancestors 'none'");
    ob_start(fn(string $h) => preg_replace(['~<head>~', '~<body>~'], ['<head><base href="/">',
        '<body><div style="position:fixed;left:0;right:0;bottom:0;z-index:1000;padding:10px 16px;background:#c00;color:#fff;font:600 15px/1.3 sans-serif;text-align:center;pointer-events:none">Это черновик — посетители видят прежнюю версию</div>'], $h, 1));
    require dirname(__DIR__, 2) . '/app/tpl/home.php';
    exit;
}

if ($p === 'settings') {
    // §3: группа → право; trusted_html (hero_h1, why_h2) — отдельно, только owner
    $grpPerm = ['seo' => 'content', 'ld' => 'content', 'hero' => 'content', 'texts' => 'content', 'pages' => 'content',
        'contacts' => 'contacts', 'facts' => 'contacts', 'requisites' => 'contacts', 'legal' => 'contacts', 'metrika' => 'owner_settings', 'mail' => 'owner_settings'];
    $g = is_string($_GET['g'] ?? null) ? $_GET['g'] : 'seo';
    if (!isset($grpPerm[$g])) page('Не найдено', '<p>Группа не найдена.</p>', 404);
    if (!can($grpPerm[$g])) deny();
    $list = rows('SELECT * FROM settings WHERE grp = ? ORDER BY sort, key', [$g]);
    $vars = ['g' => $g, 'groups' => array_keys(array_filter($grpPerm, 'can')), 'list' => $list, 'err' => '', 'status' => []];
    if ($g === 'pages' || $g === 'facts') { // строка «опубликована / заполните: …» по каждой посадочной
        $lbl = db()->query('SELECT key, label FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (PAGES as $id => $pg) $vars['status']["/{$pg['slug']}/"] = array_map(fn($k) => $lbl[$k] ?? $k, page_gaps(settings(), $id));
    }

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
                'yandex_verification', 'google_verification' => preg_match('/^[A-Za-z0-9_-]{0,100}$/D', $v),
                'notify_email' => preg_match('/^([^@\s]+@[^@\s]+\.[a-z]{2,})?$/iD', $v),
                'ld_geo_lat', 'ld_geo_lon' => preg_match('/^-?\d{1,3}(\.\d+)?$/D', $v),
                'ld_opens', 'ld_closes' => preg_match('/^[0-2]\d:[0-5]\d$/D', $v),
                default => !str_starts_with($k, 'fact_') || mb_strlen($v) <= 500,
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
            elseif (!preg_match('/^[^\0]{12,200}$/uD', $pass)) $err = 'Пароль — не короче 12 символов.';
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
        if (!preg_match('/^[^\0]{12,200}$/uD', $pass)) $err = 'Пароль — не короче 12 символов.';
        else {
            db()->beginTransaction();
            db()->prepare('UPDATE users SET pass_hash = ?, pw_ver = pw_ver + 1 WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
            if ((int)$u['id'] === $me) $_SESSION['pw_ver']++; // свою текущую сессию не выкидывать
            audit('users', (string)$u['id'], null, $json(['login' => $u['login'], 'password' => 'changed']));
            db()->commit();
            go('users&ok=1');
        }
    } elseif ($a === 'toggle') {
        $u = $one((int)$in('id'));
        if ((int)$u['id'] === $me) $err = 'Себя отключить нельзя.';
        else {
            db()->beginTransaction();
            db()->prepare('UPDATE users SET is_active = 1 - is_active, pw_ver = pw_ver + 1 WHERE id = ?')->execute([$u['id']]);
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
        // ponytail: best-effort — secure_delete затирает страницы в файле БД, но старые кадры с ПДн живут в -wal до checkpoint
        db()->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetchAll();
        go("leads&$qs&ok=deleted");
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
