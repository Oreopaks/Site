<?php
/** @var string $title @var string $main @var ?array $u @var string $csrf @var string[] $missing */
// Без inline JS/CSS: CSP default-src 'self'
$nav = ['home' => ['Обзор', null], 'content' => ['Контент', 'content'], 'settings' => ['Настройки', 'content'],
    'leads' => ['Заявки', 'leads'], 'stats' => ['Статистика', 'stats'], 'users' => ['Пользователи', 'users'], 'audit' => ['Журнал', 'audit']];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> — админка РегионДорСтрой</title>
<link rel="stylesheet" href="/admin/admin.css">
<script src="/admin/admin.js" defer></script>
</head>
<body>
<header class="top">
  <b class="brand">РегионДорСтрой · админка</b>
<?php if ($u): ?>
  <nav>
<?php foreach ($nav as $k => [$label, $perm]): if ($perm !== null && !can($perm)) continue; ?>
    <a href="/admin/?p=<?= $k ?>"<?= ($_GET['p'] ?? 'home') === $k ? ' class="on"' : '' ?>><?= e($label) ?></a>
<?php endforeach ?>
  </nav>
  <form method="post" action="/admin/?p=logout" class="logout">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <span><?= e($u['login']) ?></span> <button>Выйти</button>
  </form>
<?php endif ?>
</header>
<main>
<?php if ($missing): ?>
  <div class="warn"><b>Реквизиты не заполнены:</b> <?= e(implode(', ', $missing)) ?>. На сайте вместо них показаны заглушки «[УКАЗАТЬ …]».</div>
<?php endif ?>
  <h1><?= e($title) ?></h1>
<?= $main ?>
</main>
</body>
</html>
