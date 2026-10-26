<?php
// Роли (§3), вход с блокировкой по логину (§5). Сессию стартует только public/admin/index.php.
declare(strict_types=1);

const ROLE_PERMS = [
    'owner'   => ['content', 'trusted_html', 'contacts', 'leads', 'leads_delete', 'stats', 'users', 'owner_settings', 'audit'],
    'manager' => ['content', 'contacts', 'leads', 'stats', 'audit'],
    'editor'  => ['content', 'stats'],
];
const LOGIN_MAX_FAILS = 5;
// Хэш случайной строки: для неизвестного логина password_verify идёт столько же, сколько для известного
const DUMMY_HASH = '$2y$10$UlT9sfevQ/8/4EwOEDlkKOe6J1XWa0dwfC.tXYCjC5uHXmdq8U.NG';

// Текущий пользователь; роль и is_active читаются из БД на каждый запрос (отключение действует сразу)
function user(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = null;
        if (isset($_SESSION['uid'])) {
            $st = db()->prepare('SELECT id, login, role FROM users WHERE id = ? AND is_active = 1');
            $st->execute([$_SESSION['uid']]);
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

// → ['ok', uid] | ['bad', null] | ['locked', null]
// ponytail: счётчик и для несуществующих логинов (иначе 423 выдаёт, какие логины есть); мусорные строки — копейки
function login(string $login, string $pass): array
{
    if (!preg_match('/^.{1,64}$/suD', $login)) return ['bad', null];
    $db = db();
    $db->prepare("DELETE FROM login_attempts WHERE login = ? AND locked_until <= datetime('now')")->execute([$login]);
    $st = $db->prepare("SELECT 1 FROM login_attempts WHERE login = ? AND locked_until > datetime('now')");
    $st->execute([$login]);
    if ($st->fetchColumn()) return ['locked', null];

    $st = $db->prepare('SELECT id, pass_hash FROM users WHERE login = ? AND is_active = 1');
    $st->execute([$login]);
    $u = $st->fetch();
    if (password_verify($pass, $u['pass_hash'] ?? DUMMY_HASH) && $u) {
        $db->prepare('DELETE FROM login_attempts WHERE login = ?')->execute([$login]);
        return ['ok', (int)$u['id']];
    }
    $st = $db->prepare('INSERT INTO login_attempts (login, fails) VALUES (?, 1)
        ON CONFLICT(login) DO UPDATE SET fails = fails + 1 RETURNING fails');
    $st->execute([$login]);
    if ((int)$st->fetchColumn() >= LOGIN_MAX_FAILS) {
        $db->prepare("UPDATE login_attempts SET locked_until = datetime('now', '+15 minutes') WHERE login = ?")->execute([$login]);
        return ['locked', null];
    }
    return ['bad', null];
}
