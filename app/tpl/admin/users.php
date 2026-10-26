<?php
/** @var array $list @var int $me @var string $err @var string $csrf */
$roles = ['owner' => 'владелец', 'manager' => 'менеджер', 'editor' => 'редактор'];
$sel = function (string $cur) use ($roles): string {
    $o = '';
    foreach ($roles as $k => $l) $o .= '<option value="' . $k . '"' . ($k === $cur ? ' selected' : '') . '>' . e($l) . '</option>';
    return '<select name="role">' . $o . '</select>';
};
?>
<?php if ($err !== ''): ?><p class="err"><?= e($err) ?></p>
<?php elseif (isset($_GET['ok'])): ?><p class="ok">Сохранено.</p>
<?php endif ?>
<?php foreach ($list as $u): $hid = '<input type="hidden" name="_csrf" value="' . e($csrf) . '"><input type="hidden" name="id" value="' . (int)$u['id'] . '">'; ?>
<div class="row<?= $u['is_active'] ? '' : ' off' ?>">
  <b class="who"><?= e($u['login']) ?><?= $u['is_active'] ? '' : ' (отключён)' ?></b>
<?php if ((int)$u['id'] === $me): ?>
  <span><?= e($roles[$u['role']]) ?> — это вы</span>
<?php else: ?>
  <form method="post" action="/admin/?p=users&amp;a=save" class="fields"><?= $hid ?><?= $sel($u['role']) ?> <button>Сменить роль</button></form>
  <form method="post" action="/admin/?p=users&amp;a=toggle" class="fields" data-confirm="<?= $u['is_active'] ? 'Отключить' : 'Включить' ?> пользователя?"><?= $hid ?><button><?= $u['is_active'] ? 'Отключить' : 'Включить' ?></button></form>
<?php endif ?>
  <form method="post" action="/admin/?p=users&amp;a=password" class="fields"><?= $hid ?><input name="password" type="password" autocomplete="new-password" minlength="12" required placeholder="новый пароль"> <button>Сменить пароль</button></form>
</div>
<?php endforeach ?>
<h2>Новый пользователь</h2>
<form method="post" action="/admin/?p=users&amp;a=save" class="fields">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <label>Логин <input name="login" pattern="[a-z0-9_.\-]{3,32}" required></label>
  <label>Роль <?= $sel('editor') ?></label>
  <label>Пароль <input name="password" type="password" autocomplete="new-password" minlength="12" required></label>
  <button>Создать</button>
</form>
