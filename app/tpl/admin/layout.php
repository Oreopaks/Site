<?php
/** @var string $title @var int $code @var string $main @var ?array $u @var string $csrf @var string[] $missing @var ?array $bar */
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
<title><?= $code === 422 ? 'Ошибка: ' : '' /* П6: экранный диктор сразу слышит ошибку */ ?><?= e($title) ?> — админка РегионДорСтрой</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
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
    <a href="/" target="_blank" rel="noopener">Сайт ↗</a>
  </nav>
  <form method="post" action="/admin/?p=logout" class="logout">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <span><?= e($u['login']) ?></span> <button>Выйти</button>
  </form>
<?php endif ?>
</header>
<main>
<?php if ($missing): ?>
  <div class="warn"><b>Реквизиты не заполнены:</b> <?= e(implode(', ', $missing)) ?>. На сайте строки с пустыми полями скрыты — заполните, чтобы они появились.</div>
<?php endif ?>
<?php if ($bar): $n = $bar['n'];
    $w = $n === null ? '' : ['изменение', 'изменения', 'изменений'][$n % 10 === 1 && $n % 100 !== 11 ? 0 : ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20) ? 1 : 2)]; ?>
  <div class="draft">
<?php if ($n === null): ?>
    <span><b>Черновик повреждён</b> — его можно только отменить</span>
<?php else: ?>
    <span><b><?= $n ?> <?= $w ?></b> · <?= e(implode(', ', array_unique(array_column($bar['items'], 5))) ?: '—') ?>, <?= e($bar['at']) ?> UTC</span>
    <a href="/admin/?p=preview" target="_blank">Посмотреть страницу</a>
<?php endif ?>
<?php if (can('publish')): ?>
<?php if ($n !== null):
    // П20: чужие правки уходят на сайт только после явного «да»; свои — без вопроса (рутина)
    $others = array_map(fn($x) => "$x[5] — $x[2]: $x[3]", array_filter($bar['items'], fn($x) => $x[5] !== $u['login'])); ?>
    <form method="post" action="/admin/?p=draft&amp;a=publish"<?= $others ? ' data-confirm="' . e('Опубликовать и чужие правки: ' . implode('; ', $others) . "?\nЧтобы выпустить только свои — «Отмена», затем снимите галочки в «Что изменено».") . '"' : '' ?>>
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button>Опубликовать</button>
    </form>
<?php endif ?>
    <form method="post" action="/admin/?p=draft&amp;a=discard" data-confirm="<?= e($n === null ? 'Отменить черновик? Сайт останется как сейчас' : "Отменить все $n $w? Сайт останется как сейчас") ?>">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><button class="danger">Отменить всё</button>
    </form>
<?php else: ?>
    <span>Изменения ждут публикации — опубликует владелец или менеджер</span>
<?php endif ?>
<?php if ($n): $pick = can('publish'); /* список — нативный <details>, без JS; у owner/manager — форма «Опубликовать выбранное» */ ?>
    <details class="changes">
      <summary>Что изменено (<?= $n ?>)</summary>
<?php if ($pick): ?>
      <form method="post" action="/admin/?p=draft&amp;a=publish">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="picked" value="1">
        <p class="hint">Снимите галочку — изменение останется в черновике</p>
<?php endif ?>
        <ul>
<?php foreach ($bar['items'] as [$t, $id, $block, $name, $st, $by]): $what = "$block: $name"; ?>
          <li><?php if ($pick): ?><label class="pk"><input type="checkbox" name="pick[]" value="<?= e("$t:$id") ?>" checked aria-label="<?= e("Опубликовать: $what") ?>"></label> <?php endif
            ?><a href="/admin/?p=content&amp;t=<?= e($t) ?>#r<?= (int)$id ?>"><?= e($what) ?></a> — <?= $st ?> · <?= e($by) ?></li>
<?php endforeach ?>
        </ul>
<?php if ($pick): ?>
        <button>Опубликовать выбранное</button>
      </form>
<?php endif ?>
    </details>
<?php endif ?>
  </div>
<?php endif ?>
  <h1><?= e($title) ?></h1>
<?= $main ?>
</main>
</body>
</html>
