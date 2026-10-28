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
    $pdo->exec('PRAGMA secure_delete=ON'); // удалённые заявки затираются нулями, а не остаются в свободных страницах
    if ($fresh) {
        // ponytail: первый запрос создаёт БД из схемы+сида; гонка двух первых запросов
        // ловится исключением UNIQUE во втором — после деплоя сделать один GET вручную.
        chmod($path, 0600);
        $pdo->exec(file_get_contents(dirname(__DIR__) . '/db/schema.sql'));
        $pdo->beginTransaction();
        $pdo->exec(file_get_contents(dirname(__DIR__) . '/db/seed.sql'));
        $pdo->commit();
    } elseif (!$pdo->query("SELECT 1 FROM pragma_table_info('users') WHERE name = 'pw_ver'")->fetchColumn()) {
        // ponytail: миграция БД до SEC-1; убрать, когда все БД обновлены
        $pdo->exec('ALTER TABLE users ADD COLUMN pw_ver INTEGER NOT NULL DEFAULT 0');
    }
    if (!$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'trash'")->fetchColumn()) {
        // ponytail: миграция БД до M8 (черновик и корзина); DDL как в db/schema.sql; убрать, когда все БД обновлены
        $pdo->exec("CREATE TABLE IF NOT EXISTS drafts (id INTEGER PRIMARY KEY CHECK(id = 1), data TEXT NOT NULL, prev TEXT,
              rev INTEGER NOT NULL DEFAULT 1, updated_by INTEGER REFERENCES users(id), updated_at TEXT NOT NULL DEFAULT (datetime('now')));
            CREATE TABLE IF NOT EXISTS trash (id INTEGER PRIMARY KEY, t TEXT NOT NULL, row TEXT NOT NULL,
              deleted_at TEXT NOT NULL DEFAULT (datetime('now')), user_id INTEGER REFERENCES users(id));
            CREATE INDEX IF NOT EXISTS trash_at ON trash(deleted_at);");
    }
    if (!$pdo->query("SELECT 1 FROM settings WHERE key = 'pg_rent_title'")->fetchColumn()) {
        // ponytail: ADR-0009 — ключи посадочных и анкеты; файл идемпотентен, на свежей БД срабатывает сразу после seed.sql
        $pdo->beginTransaction();
        $pdo->exec(file_get_contents(dirname(__DIR__) . '/db/seed-pages.sql'));
        $pdo->commit();
    }
    $pdo->exec('PRAGMA foreign_keys=ON');
    return $pdo;
}
