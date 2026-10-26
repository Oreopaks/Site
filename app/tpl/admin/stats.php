<?php
/** @var string $since @var array $byDay @var array $byPage @var array $byRef @var array $byUa @var array $consent */
$uaNames = ['desktop' => 'компьютер', 'mobile' => 'мобильное', 'bot' => 'робот', 'other' => 'не указано'];
// Таблица «ключ — хиты» с полосой <meter> (inline style запрещён CSP)
$table = function (string $head, array $rows, ?array $names = null): void {
    $max = max([1, ...array_map('intval', array_column($rows, 'n'))]);
    echo '<table class="grid stat"><thead><tr><th>', e($head), '</th><th>Просмотры</th><th></th></tr></thead><tbody>';
    if (!$rows) echo '<tr><td colspan="3">Нет данных</td></tr>';
    foreach ($rows as $r) {
        $k = $names[$r['k']] ?? ($r['k'] === '' ? '—' : $r['k']);
        echo '<tr><td>', e($k), '</td><td>', (int)$r['n'], '</td><td><meter min="0" max="', $max, '" value="', (int)$r['n'], '"></meter></td></tr>';
    }
    echo '</tbody></table>';
};
?>
<p>Просмотры страниц с <?= e($since) ?> (UTC) — хиты, а не уникальные посетители. IP, User-Agent и cookie не хранятся.</p>
<h2>По дням</h2>
<?php $table('День', $byDay) ?>
<h2>По страницам</h2>
<?php $table('Страница', $byPage) ?>
<h2>Источники переходов (top-20)</h2>
<?php $table('Домен', $byRef) ?>
<h2>Устройства</h2>
<?php $table('Тип', $byUa, $uaNames) ?>
<h2>Cookie-баннер</h2>
<table class="grid stat"><thead><tr><th>День</th><th>Принять</th><th>Отклонить</th></tr></thead><tbody>
<?php if (!$consent): ?><tr><td colspan="3">Нет данных</td></tr><?php endif ?>
<?php foreach ($consent as $r): ?>
<tr><td><?= e($r['day']) ?></td><td><?= (int)$r['acc'] ?></td><td><?= (int)$r['rej'] ?></td></tr>
<?php endforeach ?>
</tbody></table>
