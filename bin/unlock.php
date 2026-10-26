<?php
// Снять блокировку входа (5 неудач → 15 мин): php bin/unlock.php <login>
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/app/bootstrap.php';

if (($argv[1] ?? '') === '') { fwrite(STDERR, "usage: php bin/unlock.php <login>\n"); exit(2); }
$st = db()->prepare('DELETE FROM login_attempts WHERE login = ?');
$st->execute([$argv[1]]);
echo $st->rowCount() ? "разблокирован {$argv[1]}\n" : "блокировок для {$argv[1]} нет\n";
