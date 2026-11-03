<?php
/** @var string $t @var array $fields поле => [тип, maxlen, подпись, подсказка] (lists()) @var string $where @var array $rows
 * @var array $err [поле или '_' => текст] — ошибки строки $errId (0 — форма «Добавить», -1 — без строки) @var int $errId @var string $csrf
 * @var int $min @var int $max @var array $dif ['add'|'del'|'chg' => [id => …]] */
$tabs = ['services' => 'Услуги', 'fleet_cards' => 'Техника: карточки', 'fleet_prices' => 'Техника: прайс', 'materials' => 'Материалы',
    'nav' => 'Меню', 'marquee' => 'Бегущая строка', 'stat_hero' => 'Цифры в шапке', 'stat_bar' => 'Цифры под шапкой',
    'why' => 'О компании', 'step' => 'Этапы'];
$kinds = ['fixed' => 'фиксированная', 'from' => 'от …', 'contract' => 'договорная'];
?>
<?php /* сводка ошибок и тост — до вкладок: на телефоне 10 вкладок уводят их ниже первого экрана (П5, П24) */ if ($err): ?>
<div class="err-sum" role="alert">
  <h2><?= $errId > 0 ? "Не сохранено (строка #$errId) — исправьте:" : ($errId === 0 ? 'Не добавлено — исправьте:' : 'Не выполнено:') ?></h2>
  <ul>
<?php foreach ($err as $f => $m): ?>
    <li><?= isset($fields[$f]) ? '<a href="#f' . $errId . '-' . $f . '">' . e($fields[$f][2] . ': ' . $m) . '</a>' : e($m) ?></li>
<?php endforeach ?>
  </ul>
</div>
<?php elseif (isset($_GET['ok'])): $rev = (int)($_GET['rev'] ?? 0);
    $when = can('publish') ? ' после «Опубликовать»' : ', когда опубликует владелец или менеджер'; // у editor кнопки «Опубликовать» нет ?>
<div class="toast">
  <p class="ok"><?= match ($_GET['ok']) { 'published' => 'Опубликовано — изменения уже на сайте.', 'discarded' => 'Изменения отменены — на сайте всё как было.',
    'deleted' => "Удалено из черновика. С сайта исчезнет$when.", 'reverted' => 'Строка возвращена как на сайте.',
    'undone' => 'Последнее изменение отменено.', 'same' => 'Изменений нет — сохранять нечего.', default => "Сохранено в черновик. На сайте появится$when." } ?></p>
<?php if ($rev > 0): ?>
  <form method="post" action="/admin/?p=content&amp;t=<?= $t ?>&amp;a=undo">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="rev" value="<?= $rev ?>"><button>Отменить</button>
  </form>
<?php endif ?>
</div>
<?php endif ?>
<nav class="tabs">
<?php foreach ($tabs as $k => $label): ?>
  <a href="/admin/?p=content&amp;t=<?= $k ?>"<?= $k === $t ? ' class="on"' : '' ?>><?= e($label) ?></a>
<?php endforeach ?>
</nav>
<p><a href="/admin/?p=trash">Корзина</a> — удалённое за 30 дней</p>
<p class="hint">Где на сайте: <?= e($where) ?></p>
<?php if (in_array($t, ['services', 'fleet_cards', 'fleet_prices', 'materials', 'stat_hero', 'stat_bar'], true)): ?>
<p class="hint">Подзаголовок блока и другие тексты — в разделе <a href="/admin/?p=settings&amp;g=texts">Настройки → Тексты</a></p>
<?php endif ?>
<?php if ($t === 'nav'): ?><p class="count" id="nav-left" data-room="<?= NAV_ROOM ?>" aria-live="polite">В шапке осталось <?= nav_left($rows) ?> символов</p>
<?php endif ?>
<?php $n = count($rows);
// последняя «строка» с id 0 — форма «Добавить» (пока не достигнут max)
foreach ([...$rows, ...($n < $max ? [['id' => 0, 'img_pos' => '50% 50%']] : [])] as $i => $r):
    $id = (int)$r['id'];
    $badge = isset($dif['add'][$id]) ? 'новое' : (isset($dif['chg'][$id]) ? 'изменено' : '');
    // ошибочная строка показывает присланные значения, чтобы правка не терялась
    $rowErr = $err && $id === $errId ? $err : [];
    if ($rowErr) foreach ($fields as $f => [$type]) $r[$f] = is_string($_POST[$f] ?? null) ? $_POST[$f] : ($type === 'bool' ? '0' : ''); ?>
<?php if (!$id): ?><h2>Добавить</h2><?php endif ?>
<div class="row"<?= $id ? " id=\"r$id\"" : '' ?>>
<?php if ($badge): ?>  <span class="badge"><?= $badge ?></span>
<?php endif ?>
<?php if ($t === 'fleet_cards' || $t === 'materials'): /* живая карточка в стилях сайта: admin.js заполняет [data-pv] из полей формы */ ?>
  <div class="pv <?= $t === 'fleet_cards' ? 'pv-fleet' : 'pv-mat' ?>" aria-hidden="true">
    <div class="pv-i"><img data-pv="img" alt=""><?php if ($t === 'materials'): ?><span class="pv-badge" data-pv="badge"></span><?php endif ?></div>
    <div class="pv-b">
      <h3 data-pv="title"></h3>
<?php if ($t === 'materials'): ?>      <p data-pv="text"></p>
<?php endif ?>
      <div class="pv-p"><span class="pv-c" data-pv="contract">договорная</span><span data-pv="nc"><span class="pv-n" data-pv="price"></span> <span class="pv-u">/ <span data-pv="unit"></span></span></span></div>
      <span class="pv-a"><?= $t === 'fleet_cards' ? 'В АРЕНДУ →' : 'Запросить расчёт' ?></span>
    </div>
  </div>
<?php endif ?>
  <form method="post" action="/admin/?p=content&amp;t=<?= $t ?>&amp;a=save" class="fields">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="id" value="<?= $id ?: '' ?>">
<?php foreach ($fields as $f => [$type, $lim, $label, $hint]):
    $v = (string)($r[$f] ?? '');
    $fid = "f$id-$f";
    $bad = $rowErr[$f] ?? null;
    $need = in_array($type, ['line', 'area1', 'img', 'href'], true);
    // подпись → подсказка → ошибка → поле (П2); лимит и обязательность — из той же таблицы, что проверяет сервер
    $attr = ' id="' . $fid . '" name="' . $f . '" aria-describedby="h' . $fid . ($bad !== null ? " err-$f" : '') . '"'
        . ($lim ? " maxlength=\"$lim\"" : '') . (in_array($type, ['line', 'line0', 'area1'], true) ? " data-max=\"$lim\"" : '')
        . ($need ? ' required' : '') . ($type === 'int' ? ' inputmode="numeric"' : '') . ($bad !== null ? ' class="bad" aria-invalid="true"' : '')
        . ($t === 'nav' && $f === 'title' ? ' data-nav="' . ($id ? 'row' : 'new') . '"' : ''); ?>
    <div class="fld<?= in_array($type, ['line', 'img', 'area1', 'href'], true) ? ' wide' : '' ?>">
<?php if ($type === 'bool'): ?>
      <label class="chk"><input type="checkbox"<?= $attr ?> value="1"<?= $v === '1' ? ' checked' : '' ?>> <?= e($label) ?></label>
      <p class="hint" id="h<?= $fid ?>"><?= e($hint) ?></p>
<?php else: ?>
      <label for="<?= $fid ?>"><?= e($label) ?><?= $need ? ' <span class="req">обязательно</span>' : '' ?></label>
      <p class="hint" id="h<?= $fid ?>"><?= e($hint) ?></p>
<?php if ($bad !== null): ?>      <p class="err" id="err-<?= $f ?>"><?= e($bad) ?></p>
<?php endif ?>
<?php if ($type === 'kind'): ?>
      <select<?= $attr ?>>
<?php foreach ($kinds as $k => $kl): ?>
        <option value="<?= $k ?>"<?= $k === $v ? ' selected' : '' ?>><?= e($kl) ?></option>
<?php endforeach ?>
      </select>
<?php elseif ($type === 'area1'): ?>
      <textarea<?= $attr ?> rows="3"><?= e($v) ?></textarea>
<?php else: ?>
      <input<?= $attr ?> value="<?= e($v) ?>">
<?php endif ?>
<?php if ($type === 'img'): ?>
      <input type="file" accept="image/jpeg,image/png,image/webp" data-upload="/admin/?p=content&amp;t=<?= $t ?>&amp;a=upload" aria-label="Загрузить фото" aria-describedby="u<?= $fid ?>">
      <p class="hint" id="u<?= $fid ?>">JPG, PNG или WebP, до 10 МБ</p>
      <p class="count up-st" aria-live="polite"></p>
<?php endif ?>
<?php endif ?>
    </div>
<?php endforeach ?>
<?php if (!$id): ?>
    <div class="fld">
      <label for="f0-at">Куда</label>
      <select id="f0-at" name="at"><option value="last">в конец списка</option><option value="first"<?= ($_POST['at'] ?? '') === 'first' ? ' selected' : '' ?>>в начало списка</option></select>
    </div>
<?php endif ?>
    <button><?= $id ? 'Сохранить' : 'Добавить' ?></button>
  </form>
<?php if ($id): ?>
  <form method="post" action="/admin/?p=content&amp;t=<?= $t ?>&amp;a=sort" class="sort">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
    <button name="dir" value="up" title="Выше"<?= $i === 0 ? ' disabled' : '' ?>>↑</button>
    <button name="dir" value="down" title="Ниже"<?= $i === $n - 1 ? ' disabled' : '' ?>>↓</button>
  </form>
  <form method="post" action="/admin/?p=content&amp;t=<?= $t ?>&amp;a=delete" class="sort" data-confirm="<?= e('Удалить «' . ($r['title'] ?? $r['name']) . '»?') ?>">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <button class="danger"<?= $n <= $min ? ' disabled title="Последний элемент удалить нельзя"' : '' ?>>Удалить</button>
  </form>
<?php if ($badge): ?>
  <form method="post" action="/admin/?p=content&amp;t=<?= $t ?>&amp;a=revert" class="sort">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="id" value="<?= $id ?>"><button>Вернуть как было</button>
  </form>
<?php endif ?>
<?php endif ?>
</div>
<?php endforeach ?>
<?php if ($n >= $max): ?><p>В списке максимум — <?= $max ?>. Чтобы добавить, удалите лишнее.</p>
<?php endif ?>
<?php foreach ($dif['del'] as $id => $r): ?>
<div class="row off" id="r<?= (int)$id ?>">
  <span class="badge">удалено</span> <span class="fields"><?= e($r['title'] ?? $r['name']) ?></span>
  <form method="post" action="/admin/?p=content&amp;t=<?= $t ?>&amp;a=revert" class="sort">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="id" value="<?= (int)$id ?>"><button>Вернуть</button>
  </form>
</div>
<?php endforeach ?>
