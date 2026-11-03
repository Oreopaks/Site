<?php
/** @var string $g @var string[] $groups @var array $list @var string $err @var string $csrf @var array $status */
$names = ['seo' => 'SEO', 'ld' => 'Разметка (JSON-LD)', 'hero' => 'Первый экран', 'texts' => 'Тексты', 'pages' => 'Посадочные', 'contacts' => 'Контакты',
    'facts' => 'Анкета', 'requisites' => 'Реквизиты', 'legal' => 'Юр. документы', 'metrika' => 'Метрика и вебмастера', 'mail' => 'Почта'];
?>
<nav class="tabs">
<?php foreach ($groups as $k): ?>
  <a href="/admin/?p=settings&amp;g=<?= $k ?>"<?= $k === $g ? ' class="on"' : '' ?>><?= e($names[$k]) ?></a>
<?php endforeach ?>
</nav>
<?php if ($err !== ''): ?><p class="err">Не сохранено: <?= e($err) ?></p>
<?php elseif (isset($_GET['ok'])): ?><p class="ok">Сохранено.</p>
<?php endif ?>
<?php foreach ($status as $url => $gaps): ?>
<p<?= $gaps ? '' : ' class="ok"' ?>><?= $gaps ? e($url) . ' не опубликована — заполните: ' . e(implode('; ', $gaps)) : '<a href="' . e($url) . '" target="_blank" rel="noopener">' . e($url) . ' опубликована</a>' ?></p>
<?php endforeach ?>
<form method="post" action="/admin/?p=settings&amp;g=<?= $g ?>&amp;a=save" class="fields settings">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
<?php foreach ($list as $r):
    $ro = $r['trusted_html'] && !can('trusted_html'); // disabled-поле не отправляется → не меняется
    $empty = $r['required'] && trim($r['value']) === ''; ?>
  <label class="wide<?= $empty ? ' missing' : '' ?>"><?= e($r['label']) ?><?= $empty ? ' — не заполнено' : '' ?>
<?php if ($r['type'] === 'textarea'): ?>
    <textarea name="<?= e($r['key']) ?>" rows="3"<?= $ro ? ' disabled' : '' ?>><?= e($r['value']) ?></textarea>
<?php else: ?>
    <input name="<?= e($r['key']) ?>" value="<?= e($r['value']) ?>"<?= $ro ? ' disabled' : '' ?>>
<?php endif ?>
  </label>
<?php if ($r['key'] === 'hours_text'): /* те же часы — в жёлтой полосе (items stat_bar): правятся отдельно */ ?>
  <p class="hint">Часы также показаны в жёлтой полосе под первым экраном: <a href="/admin/?p=content&amp;t=stat_bar">Контент → Цифры под шапкой</a></p>
<?php endif ?>
<?php endforeach ?>
  <button>Сохранить</button>
</form>
