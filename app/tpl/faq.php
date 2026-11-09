<?php /** @var array $faq вопрос → ответ (home.php: только отвеченные); @var ?array $pg. Посадочная — из landing.php, главная — из home.php между этапами и контактами (strategy Р3.5) */ ?>

  <!-- ================= FAQ (вопросы в коде, ответы — анкета) ================= -->
  <section data-cine style="background:<?= $pg ? '#fff' : '#f5f5f3' ?>;padding:90px 0">
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
