<?php /** @var array $s settings */ $rev = date('d.m.Y', strtotime($s['consent_rev'])); ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Согласие на обработку персональных данных — РегионДорСтрой</title>
<meta name="robots" content="noindex">
<meta name="theme-color" content="#0d0d0d">
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<style>
<?php require __DIR__ . '/fonts.php' ?>
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Manrope',sans-serif;color:#222;background:#f5f5f3;line-height:1.6}
  a{color:#8a6d00}
  .wrap{max-width:860px;margin:0 auto;padding:48px 24px 80px}
  h1{font-family:'Oswald',sans-serif;font-weight:700;font-size:clamp(22px,7.4vw,34px);text-transform:uppercase;color:#0d0d0d;margin-bottom:8px}
  h2{font-family:'Oswald',sans-serif;font-weight:600;font-size:21px;text-transform:uppercase;margin:30px 0 10px;color:#0d0d0d}
  p,li{font-size:15px;color:#444;margin-bottom:10px}
  ul{padding-left:22px}
  .muted{color:#6e6e6e;font-size:13px;margin-bottom:28px}
  .req{background:#fff;border-left:3px solid #FFC700;padding:14px 18px;margin:18px 0;font-size:14px;overflow-wrap:anywhere}
  .back{display:inline-block;margin:22px 0 -10px;padding:10px 0;font-weight:700;color:#0d0d0d} /* цель ≥44 px: поля 10 px компенсированы отступами — строка на месте */
</style>
</head>
<body>
<main class="wrap">
  <h1>Согласие на обработку персональных данных</h1>
  <p class="muted">В соответствии со ст. 9 Федерального закона № 152-ФЗ «О персональных данных».</p>

  <p>Отмечая галочку «Даю согласие на обработку персональных данных» в форме на сайте, я свободно, своей волей и в своём интересе даю согласие оператору:</p>
  <div class="req">
    <strong><?= e($s['org_name']) ?></strong><br>
<?php if (ids($s, ': ') !== ''): ?>
    <?= e(ids($s, ': ')) ?><br>
<?php endif ?>
<?php if (trim($s['org_address']) !== ''): ?>
    Адрес: <?= e($s['org_address']) ?><br>
<?php endif ?>
    E-mail: <?= e($s['email']) ?> · Тел.: <?= e($s['phone_mobile_fmt']) ?>
  </div>

  <h2>1. Перечень персональных данных</h2>
  <ul>
    <li>имя;</li>
    <li>номер телефона;</li>
    <li>сведения, указанные в заявке: выбранная услуга или материал, комментарий.</li>
  </ul>

  <h2>2. Цели обработки</h2>
  <ul>
    <li>обработка заявки и обратная связь со мной;</li>
    <li>подготовка коммерческого предложения, расчёта стоимости и сроков;</li>
    <li>заключение и исполнение договора на работы/поставку.</li>
  </ul>

  <h2>3. Действия с персональными данными</h2>
  <p>Сбор, запись, систематизация, накопление, хранение, уточнение (обновление, изменение), извлечение, использование, блокирование, удаление, уничтожение — с использованием средств автоматизации. Передача (распространение, предоставление) третьим лицам не осуществляется.</p>

  <h2>4. Место хранения</h2>
  <p>Персональные данные записываются и хранятся в базе данных на сервере, расположенном на территории Российской Федерации. Трансграничная передача не осуществляется.</p>

  <h2>5. Срок действия и отзыв</h2>
  <p>Согласие действует <?= e(stub($s, 'retention', 'до достижения целей обработки')) ?> либо до его отзыва. Согласие может быть отозвано в любой момент письменным обращением на e-mail оператора <?= e($s['email']) ?>. После отзыва персональные данные уничтожаются, если иное не предусмотрено законом.</p>

  <p>Порядок обработки описан в <a href="privacy.php">Политике конфиденциальности</a>.</p>

  <p class="muted" style="margin-top:24px">Редакция от <?= e($rev) ?>.</p>
  <a class="back" href="./">← Вернуться на сайт</a>
</main>
</body>
</html>
