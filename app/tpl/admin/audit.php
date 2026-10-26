<?php
/** @var array $list */
?>
<p>Последние <?= count($list) ?> записей (время UTC).</p>
<?php if ($list): ?>
<table class="grid">
<thead><tr><th>Когда</th><th>Кто</th><th>Таблица</th><th>Запись</th><th>Было</th><th>Стало</th></tr></thead>
<tbody>
<?php foreach ($list as $r): ?>
<tr>
  <td><?= e($r['at']) ?></td>
  <td><?= e($r['login'] ?? '#' . $r['user_id']) ?></td>
  <td><?= e($r['tbl']) ?></td>
  <td><?= e($r['row_key']) ?></td>
  <td class="pre"><?= e($r['old_value'] ?? '') ?></td>
  <td class="pre"><?= e($r['new_value'] ?? '') ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
<?php endif ?>
