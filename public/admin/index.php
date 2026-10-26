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

// ponytail: заглушки разделов; CRUD — M4, дашборды заявок/статистики/аудита — M5
$titles = ['home' => 'Обзор', 'content' => 'Контент', 'settings' => 'Настройки', 'leads' => 'Заявки',
    'stats' => 'Статистика', 'users' => 'Пользователи', 'audit' => 'Журнал изменений'];
page($titles[$p], $p === 'home'
    ? '<p>Вы вошли как <b>' . e(user()['login']) . '</b> (' . e(user()['role']) . ').</p>'
    : '<p>Раздел в разработке.</p>');
