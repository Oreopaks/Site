<?php
// Подключение к SQLite. Путь: env RDS_DB (тесты), иначе var/site.sqlite вне webroot.
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $path = getenv('RDS_DB') ?: dirname(__DIR__) . '/var/site.sqlite';
    $fresh = !is_file($path);
    if ($fresh && !is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout=5000');
    if ($fresh) {
        // ponytail: первый запрос создаёт БД из схемы+сида; гонка двух первых запросов
        // ловится исключением UNIQUE во втором — после деплоя сделать один GET вручную.
        chmod($path, 0600);
        $pdo->exec(file_get_contents(dirname(__DIR__) . '/db/schema.sql'));
        $pdo->beginTransaction();
        $pdo->exec(file_get_contents(dirname(__DIR__) . '/db/seed.sql'));
        $pdo->commit();
    }
    $pdo->exec('PRAGMA foreign_keys=ON');
    return $pdo;
}
