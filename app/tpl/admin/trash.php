<?php
/** @var array $gone [[t, строка]] — удалено в черновике @var array $list строки trash + login @var array $titles t => заголовок списка @var array $drafted t => id строк черновика @var string $csrf */
?>
<?php if (isset($_GET['ok'])): ?><p class="ok">Возвращено в черновик. На сайте появится<?= can('publish') ? ' после «Опубликовать»' : ', когда опубликует владелец или менеджер' ?>.</p>
<?php endif ?>
<h2>Удалено в черновике (ещё на сайте)</h2>
<?php if (!$gone): ?><p>Нет.</p>
<?php endif ?>
<?php foreach ($gone as [$t, $r]): ?>
<div class="row off">
  <span class="fields"><?= e($titles[$t]) ?>: <b><?= e($r['title'] ?? $r['name']) ?></b></span>
  <form method="post" action="/admin/?p=trash&amp;a=restore" class="sort">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="t" value="<?= $t ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button>Вернуть</button>
  </form>
</div>
<?php endforeach ?>
<h2>Корзина — удалено с сайта (хранится 30 дней)</h2>
<?php if (!$list): ?><p>Пусто.</p>
<?php endif ?>
<?php foreach ($list as $x): $r = json_decode($x['row'], true) ?: []; ?>
<div class="row">
  <span class="fields"><?= e($titles[$x['t']] ?? $x['t']) ?>: <b><?= e($r['title'] ?? $r['name'] ?? '') ?></b> · удалил <?= e($x['login'] ?? '—') ?>, <?= e($x['deleted_at']) ?> UTC</span>
<?php if (in_array((int)($r['id'] ?? 0), $drafted[$x['t']] ?? [], true)): ?>
  <span class="badge">уже в черновике</span>
<?php else: ?>
  <form method="post" action="/admin/?p=trash&amp;a=restore" class="sort">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button>Восстановить</button>
  </form>
<?php endif ?>
</div>
<?php endforeach ?>
