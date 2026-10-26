<?php
// Роли (§3), вход с блокировкой по логину (§5). Сессию стартует только public/admin/index.php.
declare(strict_types=1);

const ROLE_PERMS = [
    'owner'   => ['content', 'trusted_html', 'contacts', 'leads', 'leads_delete', 'stats', 'users', 'owner_settings', 'audit'],
    'manager' => ['content', 'contacts', 'leads', 'stats', 'audit'],
    'editor'  => ['content', 'stats'],
];
const LOGIN_MAX_FAILS = 5;
const SESSION_MAX_AGE = 28800; // абсолютный срок сессии, 8 ч (§5)

// Текущий пользователь; роль, is_active и pw_ver читаются из БД на каждый запрос (отключение и смена пароля действуют сразу)
function user(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = null;
        if (isset($_SESSION['uid']) && time() - ($_SESSION['started'] ?? 0) <= SESSION_MAX_AGE) {
            $st = db()->prepare('SELECT id, login, role FROM users WHERE id = ? AND is_active = 1 AND pw_ver = ?');
            $st->execute([$_SESSION['uid'], $_SESSION['pw_ver'] ?? -1]);
            $u = $st->fetch() ?: null;
        }
    }
    return $u;
}

function can(string $perm): bool
{
    $u = user();
    return $u !== null && in_array($perm, ROLE_PERMS[$u['role']] ?? [], true);
}

function deny(): never
{
    http_response_code(403);
    echo 'Доступ запрещён';
    exit;
}

// → ['ok', uid, pw_ver] | ['bad', null, null] | ['locked', null, null]
// ponytail: счётчик и для несуществующих логинов (иначе 423 выдаёт, какие логины есть); мусорные строки — копейки
function login(string $login, #[\SensitiveParameter] string $pass): array
{
    if (!preg_match('/^.{1,64}$/suD', $login)) return ['bad', null, null];
    $db = db();
    $db->prepare("DELETE FROM login_attempts WHERE login = ? AND locked_until <= datetime('now')")->execute([$login]);
    // попытка считается ДО password_verify одним оператором: параллельные запросы не проскочат лимит
    // сверх лимита без locked_until (сбой между попытками) — блокировка ставится здесь же
    $st = $db->prepare("INSERT INTO login_attempts (login, fails) VALUES (?, 1)
        ON CONFLICT(login) DO UPDATE SET fails = fails + 1,
            locked_until = CASE WHEN fails + 1 > " . LOGIN_MAX_FAILS . " AND locked_until IS NULL THEN datetime('now', '+15 minutes') ELSE locked_until END
        RETURNING fails, locked_until > datetime('now')");
    $st->execute([$login]);
    [$fails, $locked] = $st->fetch(PDO::FETCH_NUM);
    $st->closeCursor(); // отпустить запись в БД до долгого password_verify
    if ($locked || $fails > LOGIN_MAX_FAILS) return ['locked', null, null];

    $st = $db->prepare('SELECT id, pass_hash, pw_ver FROM users WHERE login = ? AND is_active = 1');
    $st->execute([$login]);
    $u = $st->fetch();
    // фиктивный хэш текущего PASSWORD_DEFAULT: неизвестный логин проверяется столько же, сколько известный.
    // ponytail: считается на каждой попытке (static живёт один запрос под php-fpm) — иначе неизвестный был бы вдвое дольше
    static $dummy;
    $dummy ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    // "\0" — обычная неудача (bcrypt на NUL бросает ValueError → было бы 500)
    if (!str_contains($pass, "\0") && password_verify($pass, $u['pass_hash'] ?? $dummy) && $u) {
        $db->prepare('DELETE FROM login_attempts WHERE login = ?')->execute([$login]);
        if (password_needs_rehash($u['pass_hash'], PASSWORD_DEFAULT)) { // pw_ver не трогаем: пароль тот же
            // AND pass_hash = старый: не затереть пароль, сменённый владельцем в ту же секунду
            $db->prepare('UPDATE users SET pass_hash = ? WHERE id = ? AND pass_hash = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id'], $u['pass_hash']]);
        }
        return ['ok', (int)$u['id'], (int)$u['pw_ver']];
    }
    if ($fails >= LOGIN_MAX_FAILS) {
        $db->prepare("UPDATE login_attempts SET locked_until = datetime('now', '+15 minutes') WHERE login = ?")->execute([$login]);
        return ['locked', null, null];
    }
    return ['bad', null, null];
}
