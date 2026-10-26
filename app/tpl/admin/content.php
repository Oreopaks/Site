<?php
/** @var string $t @var array $fields @var array $labels @var array $rows @var string $err @var int $errId @var string $csrf */
$tabs = ['services' => 'Услуги', 'fleet_cards' => 'Техника: карточки', 'fleet_prices' => 'Техника: прайс', 'materials' => 'Материалы',
    'nav' => 'Меню', 'marquee' => 'Бегущая строка', 'stat_hero' => 'Цифры в шапке', 'stat_bar' => 'Цифры под шапкой',
    'why' => 'О компании', 'step' => 'Этапы'];
$kinds = ['fixed' => 'фиксированная', 'from' => 'от …', 'contract' => 'договорная'];
?>
<nav class="tabs">
<?php foreach ($tabs as $k => $label): ?>
  <a href="/admin/?p=content&amp;t=<?= $k ?>"<?= $k === $t ? ' class="on"' : '' ?>><?= e($label) ?></a>
<?php endforeach ?>
</nav>
<?php if ($err !== ''): ?><p class="err">Не сохранено (строка #<?= $errId ?>): <?= e($err) ?></p>
<?php elseif (isset($_GET['ok'])): ?><p class="ok">Сохранено.</p>
<?php endif ?>
<?php $n = count($rows); foreach ($rows as $i => $r):
    // ошибочная строка показывает присланные значения, чтобы правка не терялась
    if ((int)$r['id'] === $errId) foreach ($fields as $f => $_) $r[$f] = is_string($_POST[$f] ?? null) ? $_POST[$f] : ($_ === 'bool' ? '0' : ''); ?>
<div class="row">
  <form method="post" action="/admin/?p=content&amp;t=<?= $t ?>&amp;a=save" class="fields">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
<?php foreach ($fields as $f => $type): $v = (string)($r[$f] ?? ''); ?>
<?php if ($type === 'bool'): ?>
    <label class="chk"><input type="checkbox" name="<?= $f ?>" value="1"<?= $v === '1' ? ' checked' : '' ?>> <?= e($labels[$f]) ?></label>
<?php elseif ($type === 'kind'): ?>
    <label><?= e($labels[$f]) ?> <select name="<?= $f ?>">
<?php foreach ($kinds as $k => $kl): ?>
      <option value="<?= $k ?>"<?= $k === $v ? ' selected' : '' ?>><?= e($kl) ?></option>
<?php endforeach ?>
    </select></label>
<?php elseif ($type === 'area'): ?>
    <label class="wide"><?= e($labels[$f]) ?> <textarea name="<?= $f ?>" rows="3"><?= e($v) ?></textarea></label>
<?php else: ?>
    <label<?= $type === 'line' || $type === 'img' ? ' class="wide"' : '' ?>><?= e($labels[$f]) ?> <input name="<?= $f ?>" value="<?= e($v) ?>"<?= in_array($type, ['price', 'int'], true) ? ' inputmode="numeric"' : '' ?>></label>
<?php endif ?>
<?php endforeach ?>
    <button>Сохранить</button>
  </form>
  <form method="post" action="/admin/?p=content&amp;t=<?= $t ?>&amp;a=sort" class="sort">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
    <button name="dir" value="up" title="Выше"<?= $i === 0 ? ' disabled' : '' ?>>↑</button>
    <button name="dir" value="down" title="Ниже"<?= $i === $n - 1 ? ' disabled' : '' ?>>↓</button>
  </form>
</div>
<?php endforeach ?>
