<?php
/** @var array $list @var array $ev @var int $total @var array $f @var string $qs @var array $statuses @var array $sources @var string $csrf */
$opts = function (array $map, string $cur, ?string $all): string {
    $o = $all === null ? '' : '<option value="">' . e($all) . '</option>';
    foreach ($map as $k => $l) $o .= '<option value="' . e($k) . '"' . ($k === $cur ? ' selected' : '') . '>' . e($l) . '</option>';
    return $o;
};
$act = fn(string $a) => '/admin/?p=leads&amp;a=' . $a . ($qs !== '' ? '&amp;' . e($qs) : '');
$hid = fn(array $r) => '<input type="hidden" name="_csrf" value="' . e($csrf) . '"><input type="hidden" name="id" value="' . (int)$r['id'] . '">';
?>
<?php if (isset($_GET['ok'])): ?><p class="ok"><?= $_GET['ok'] === 'deleted' ? 'Заявка удалена.' : 'Сохранено.' ?></p><?php endif ?>
<form method="get" action="/admin/" class="fields filter">
  <input type="hidden" name="p" value="leads">
  <label>Статус <select name="status"><?= $opts($statuses, $f['status'], 'все') ?></select></label>
  <label>Источник <select name="source"><?= $opts($sources, $f['source'], 'все') ?></select></label>
  <label>С (UTC) <input type="date" name="from" value="<?= e($f['from']) ?>"></label>
  <label>По (UTC) <input type="date" name="to" value="<?= e($f['to']) ?>"></label>
  <button>Показать</button>
  <a href="/admin/?p=leads">Сбросить</a>
  <a href="/admin/?p=leads&amp;a=csv<?= $qs !== '' ? '&amp;' . e($qs) : '' ?>">Скачать CSV</a>
</form>
<p>Найдено: <?= $total ?><?= $total > count($list) ? ' (показаны последние ' . count($list) . ', остальные — в CSV или через фильтр)' : '' ?>.</p>
<?php if ($list): ?>
<div class="hscroll"><table class="grid leads">
<thead><tr><th>№</th><th>Дата (UTC)</th><th>Источник</th><th>Имя</th><th>Телефон</th><th>Услуга / материал</th><th>Комментарий</th><th>Статус</th></tr></thead>
<tbody>
<?php foreach ($list as $r): ?>
<tr class="st-<?= e($r['status']) ?>">
  <td data-l="№"><?= (int)$r['id'] ?></td>
  <td data-l="Дата (UTC)"><?= e($r['created_at']) ?></td>
  <td data-l="Источник"><?= e($sources[$r['source']]) ?></td>
  <td data-l="Имя"><?= e($r['name']) ?></td>
  <td data-l="Телефон"><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $r['phone'])) ?>"><?= e($r['phone']) ?></a></td>
  <td data-l="Услуга / материал"><?= e(trim($r['service'] . ' ' . $r['material'])) ?></td>
  <td class="pre" data-l="Комментарий"><?= e($r['comment']) ?><?php if ($r['mail_error']): ?><br><span class="err">Письмо не отправлено: <?= e($r['mail_error']) ?></span><?php endif ?></td>
  <td data-l="Статус">
    <form method="post" action="<?= $act('status') ?>" class="inline"><?= $hid($r) ?><select name="status"><?= $opts($statuses, $r['status'], null) ?></select> <button>OK</button></form>
<?php foreach ($ev[$r['id']] ?? [] as $x): ?>
    <small class="ev"><?= e($x['at']) ?> <?= e($x['login'] ?? '—') ?>: <?= e($statuses[$x['from_status']] ?? $x['from_status']) ?> → <?= e($statuses[$x['to_status']] ?? $x['to_status']) ?></small>
<?php endforeach ?>
<?php if (can('leads_delete')): ?>
    <form method="post" action="<?= $act('delete') ?>" class="inline" data-confirm="Удалить заявку №<?= (int)$r['id'] ?> безвозвратно?"><?= $hid($r) ?><button class="danger">Удалить</button></form>
<?php endif ?>
  </td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
<?php endif ?>
