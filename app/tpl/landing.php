<?php
/** @var array $s settings; @var array $pg посадочная из landing(); @var array $faq вопрос → ответ (home.php, тот же список, что в FAQPage). Внутри <main> home.php; якоря только абсолютные (/slug/#…): на странице <base href="/"> */
$wk = ['asf' => 'fact_asphalt_works', 'blago' => 'fact_blago_works'][$pg['id']] ?? null;
$works = $wk ? trim($s[$wk] ?? '') : ''; // «Виды работ» — текст владельца из анкеты
$lead = trim($s["pg_{$pg['id']}_lead"] ?? '');
$snt = $pg['id'] === 'asf' ? trim($s['fact_snt'] ?? '') : ''; // секция СНТ — только на asf и только с ответом владельца
// чипы цен в первом экране: те же строки, что в прайсе страницы; договорные и подпозиции пропускаем, одинаковые «цена / ед.» — один раз
$chips = [];
foreach (['rent' => $prices, 'mat' => $materials][$pg['id']] ?? [] as $r) {
    if ($r['price_kind'] === 'contract' || !empty($r['is_sub'])) continue;
    $v = price($r['price_kind'], $r['price']) . ' / ' . $r['unit'];
    if (in_array($v, array_column($chips, 1), true)) continue;
    $chips[] = [$r['name'] ?? $r['title'], $v];
    if (count($chips) === 3) break;
}
?>
  <style>
    @media (max-width:640px){
      [data-lp-lead]{font-size:16px!important;line-height:1.45!important;margin-bottom:18px!important}
      [data-lp-head]{padding-top:28px!important;padding-bottom:36px!important}
      [data-lp-head] nav{margin-bottom:12px!important}
      [data-lp-head] h1{font-size:28px!important;margin-bottom:14px!important}
      [data-lp-chips]{margin-bottom:18px!important}
      [data-lp-chips] li{font-size:13px!important;padding:6px 10px!important}
      [data-lp-cta] a{padding:13px 18px!important;font-size:14px!important}
    }
  </style>
  <!-- ================= LANDING HEAD ================= -->
  <section data-herowrap style="background:#0d0d0d;position:relative;color:#fff;overflow:hidden">
    <div style="position:relative;z-index:2;max-width:1240px;margin:0 auto;padding:96px 32px 62px;width:100%" data-hero-content data-lp-head>
      <nav aria-label="Хлебные крошки" style="font-size:14px;color:#a8a8a8;margin-bottom:22px"><a href="/" style="color:#FFC700;text-decoration:underline">Главная</a> / <span aria-current="page"><?= e($pg['name']) ?></span></nav>
      <h1 data-anim="fade-up" style="font-family:'Oswald';font-weight:700;font-size:60px;line-height:1;letter-spacing:.5px;text-transform:uppercase;margin-bottom:24px;max-width:920px"><?= e($s["pg_{$pg['id']}_h1"]) ?></h1>
<?php if ($lead !== ''): ?>
      <p data-anim="fade-up" data-lp-lead style="font-size:19px;line-height:1.6;color:#dcdcdc;max-width:720px;margin-bottom:38px"><?= e($lead) ?></p>
<?php endif ?>
<?php if ($chips): ?>
      <ul data-anim="fade-up" data-lp-chips aria-label="Цены" style="list-style:none;margin:0 0 32px;padding:0;display:flex;gap:8px;flex-wrap:wrap">
<?php foreach ($chips as [$k, $v]): ?>
        <li style="border:1px solid #3a3a3a;background:rgba(255,255,255,.04);padding:8px 14px;font-size:15px;color:#dcdcdc;white-space:nowrap"><?= e($k) ?> <b style="font-family:'Oswald';font-weight:700;color:#FFC700"><?= e($v) ?></b></li>
<?php endforeach ?>
      </ul>
<?php endif ?>
      <div data-anim="fade-up" data-lp-cta style="display:flex;gap:14px;flex-wrap:wrap">
        <a href="/<?= e($pg['slug']) ?>/#contacts" style="background:#FFC700;color:#0d0d0d;font-family:'Manrope';font-weight:800;font-size:15px;padding:17px 30px;text-transform:uppercase;letter-spacing:.3px;display:inline-flex;align-items:center" style-hover="background:#ffd633">Оставить заявку</a>
        <a href="tel:<?= e($s['phone_mobile']) ?>" style="background:rgba(255,255,255,.06);color:#fff;border:1.5px solid rgba(255,255,255,.28);font-weight:700;font-size:15px;padding:16px 30px;text-transform:uppercase;letter-spacing:.3px;display:inline-flex;align-items:center" style-hover="border-color:#FFC700">Позвонить&nbsp;<span style="font-family:'Oswald';letter-spacing:.3px;white-space:nowrap"><?= e($s['phone_mobile_fmt']) ?></span></a>
      </div>
    </div>
    <div style="position:absolute;left:0;right:0;bottom:0;height:10px;z-index:3;background-image:repeating-linear-gradient(45deg,#FFC700 0 18px,#0d0d0d 18px 36px);pointer-events:none"></div>
  </section>
<?php if ($works !== ''): ?>

  <!-- ================= WORKS (анкета) ================= -->
  <section data-cine style="background:#fff;padding:90px 0">
    <div style="max-width:1240px;margin:0 auto;padding:0 32px">
      <h2 data-anim="fade-up" style="font-family:'Oswald';font-weight:700;font-size:46px;text-transform:uppercase;line-height:1.05;letter-spacing:.5px;margin-bottom:28px">Виды работ</h2>
      <p data-anim="fade-up" style="font-size:17px;line-height:1.7;color:#333;max-width:860px;white-space:pre-line"><?= e($works) ?></p>
    </div>
  </section>
<?php endif ?>

<?php if ($snt !== ''): ?>

  <!-- ================= SNT (анкета) ================= -->
  <section data-cine style="background:#f4f4f4;padding:90px 0">
    <div style="max-width:1240px;margin:0 auto;padding:0 32px">
      <h2 data-anim="fade-up" style="font-family:'Oswald';font-weight:700;font-size:46px;text-transform:uppercase;line-height:1.05;letter-spacing:.5px;margin-bottom:28px">Щебёночные дороги и дороги в СНТ</h2>
      <p data-anim="fade-up" style="font-size:17px;line-height:1.7;color:#333;max-width:860px;white-space:pre-line"><?= e($snt) ?></p>
    </div>
  </section>
<?php endif ?>
<?php if ($faq): ?>

  <!-- ================= FAQ (вопросы в коде, ответы — анкета) ================= -->
  <section data-cine style="background:#fff;padding:90px 0">
    <div style="max-width:1240px;margin:0 auto;padding:0 32px">
      <h2 data-anim="fade-up" style="font-family:'Oswald';font-weight:700;font-size:46px;text-transform:uppercase;line-height:1.05;letter-spacing:.5px;margin-bottom:28px">Вопросы и ответы</h2>
<?php foreach ($faq as $q => $a): ?>
      <div data-anim="fade-up" style="max-width:860px;border-top:1px solid #e3e3e3;padding:22px 0">
        <h3 style="font-family:'Oswald';font-weight:600;font-size:21px;text-transform:uppercase;margin-bottom:10px;line-height:1.15"><?= e($q) ?></h3>
        <p style="font-size:17px;line-height:1.7;color:#333;white-space:pre-line"><?= e($a) ?></p>
      </div>
<?php endforeach ?>
    </div>
  </section>
<?php endif ?>
