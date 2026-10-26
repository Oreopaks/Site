<?php
// Создать пользователя админки (D9): php bin/create-user.php <login> <owner|manager|editor>
// Пароль (≥12 символов) — первой строкой из STDIN. На сервере: sudo -u www-data php bin/create-user.php …
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/auth.php';

[, $login, $role] = $argv + [null, '', ''];
if (!preg_match('/^[a-z0-9_.-]{3,32}$/D', $login) || !isset(ROLE_PERMS[$role])) {
    fwrite(STDERR, "usage: php bin/create-user.php <login a-z0-9_.- 3..32> <owner|manager|editor>  (пароль из STDIN)\n");
    exit(2);
}
$tty = stream_isatty(STDIN);
if ($tty) { fwrite(STDERR, 'Пароль: '); shell_exec('stty -echo'); }
$pass = rtrim((string)fgets(STDIN), "\r\n");
if ($tty) { shell_exec('stty echo'); fwrite(STDERR, "\n"); }
if (!preg_match('/^[^\0]{12,}$/uD', $pass)) { fwrite(STDERR, "пароль короче 12 символов\n"); exit(1); }

try {
    db()->prepare('INSERT INTO users (login, pass_hash, role) VALUES (?, ?, ?)')
        ->execute([$login, password_hash($pass, PASSWORD_DEFAULT), $role]);
} catch (PDOException $ex) {
    if ($ex->getCode() !== '23000') throw $ex;
    fwrite(STDERR, "логин «{$login}» уже занят\n");
    exit(1);
}
echo "создан {$login} ({$role})\n";
