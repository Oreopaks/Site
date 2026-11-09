<?php
/** @var array $s settings; списки: $nav $marquee $statHero $statBar $services $fleet $prices $materials $why $steps; $pg — посадочная из landing() или null (главная) */
$pg ??= null;
$url = $s['base_url'] . ($pg ? $pg['slug'] . '/' : '');
$pt = $pg ? $s["pg_{$pg['id']}_title"] : null; // на посадочной title/og/twitter — из pg_*
$pd = $pg ? $s["pg_{$pg['id']}_desc"] : null;
$show = fn(string $b): bool => !$pg || in_array($b, $pg['blocks'], true); // секция главной на этой странице
$here = $pg ? '/' . $pg['slug'] . '/' : null;
$live = fn(string $h): bool => preg_match('~^/([a-z0-9-]+)/$~D', $h, $m) && landing($m[1]); // href ведёт на опубликованную посадочную
$biz = [
    '@type' => 'HomeAndConstructionBusiness',
    'name' => $s['ld_name'],
    'image' => $s['base_url'] . 'assets/logo-rds.webp',
    'url' => $s['base_url'],
    'telephone' => $s['phone_mobile'],
    'email' => $s['email'],
    'priceRange' => $s['ld_price_range'],
    'address' => [
        '@type' => 'PostalAddress',
        'addressLocality' => $s['ld_locality'],
        'addressRegion' => $s['ld_region'],
        'addressCountry' => 'RU',
    ],
    'geo' => [
        '@type' => 'GeoCoordinates',
        'latitude' => (float)$s['ld_geo_lat'],
        'longitude' => (float)$s['ld_geo_lon'],
    ],
    'areaServed' => $s['ld_area'],
    'sameAs' => array_values(array_filter([$s['telegram'], $s['whatsapp']])),
    'openingHoursSpecification' => [
        '@type' => 'OpeningHoursSpecification',
        'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
        'opens' => $s['ld_opens'],
        'closes' => $s['ld_closes'],
    ],
];
// FAQ посадочной: только вопросы с ответом владельца; тот же список — в видимом блоке (landing.php) и в FAQPage
$faq = [];
foreach ($pg['faq'] ?? [] as $q => $k) if (($a = trim($s[$k] ?? '')) !== '') $faq[$q] = $a;
$ld = !$pg ? ['@context' => 'https://schema.org'] + $biz : ['@context' => 'https://schema.org', '@graph' => array_merge([
    ['@type' => 'Service', 'name' => $s["pg_{$pg['id']}_h1"], 'serviceType' => $pg['name'], 'description' => $pd, 'url' => $url, 'areaServed' => $s['ld_area'], 'provider' => $biz],
    ['@type' => 'BreadcrumbList', 'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Главная', 'item' => $s['base_url']],
        ['@type' => 'ListItem', 'position' => 2, 'name' => $pg['name'], 'item' => $url],
    ]],
], $faq ? [['@type' => 'FAQPage', 'mainEntity' => array_map(fn($q, $a) => ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]], array_keys($faq), $faq)]] : [])];
// значение статистики: счётчик data-count или текст
$stat = fn(array $r): string => $r['num'] === null ? e($r['text'])
    : '<span data-count data-to="' . (int)$r['num'] . '" data-suffix="' . e($r['suffix']) . '">' . (int)$r['num'] . e($r['suffix']) . '</span>';
// колонок сетки: n ≤ max → n, иначе минимум рядов и ряды поровну (5 при max 4 → 3+2, а не 4+1)
$cols = fn(int $n, int $max): int => $n <= $max ? $n : (int)ceil($n / ceil($n / $max));
$badge = '<span style="display:inline-block;font-family:\'Oswald\';font-weight:600;font-size:13px;line-height:1.2;letter-spacing:1px;text-transform:uppercase;color:#FFC700;border:1px solid #FFC700;padding:5px 10px">договорная</span>';
// «Позвонить + WhatsApp» на экранах «Спасибо» и в блоке ошибки связи; WhatsApp — только если ссылка задана; ≥44 px, на узком — столбиком
$rb = 'flex:1 1 auto;padding:12px 18px;line-height:20px;text-align:center;font-family:\'Manrope\';font-weight:800;font-size:14px;letter-spacing:.3px;text-transform:uppercase;';
$reach = fn(bool $dark): string => '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:12px">'
    . '<a href="tel:' . e($s['phone_mobile']) . '" style="' . $rb . 'background:#FFC700;color:#0d0d0d">Позвонить <span style="white-space:nowrap">' . e($s['phone_mobile_fmt']) . '</span></a>'
    . ($s['whatsapp'] !== '' ? '<a href="' . e($s['whatsapp']) . '" style="' . $rb . ($dark ? 'border:1.5px solid rgba(255,255,255,.35);color:#fff' : 'background:#1f1f1f;color:#fff') . '">WhatsApp</a>' : '')
    . '</div>';
// WhatsApp с приветствием — одна ссылка для липкой панели и шапки (≥1024)
$waHi = $s['whatsapp'] . (str_contains($s['whatsapp'], '?') ? '&' : '?') . 'text=' . rawurlencode('Здравствуйте! Пишу с сайта regiondorstroy.com.');
// знак «заявка» в строке прайса — планшет кнопки «Заявка» липкой панели; nowrap — значок не уходит на строку один
$req = '<span style="white-space:nowrap">&nbsp;<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#FFC700" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-3px"><path d="M9 3h6v3H9z"/><path d="M15 4.5h2a2 2 0 0 1 2 2V19a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6.5a2 2 0 0 1 2-2h2M9 11h6M9 15h4"/></svg></span>';
$urgent = 'Срочно? Позвоните' . ($s['whatsapp'] !== '' ? ' или напишите' : '') . ':';
// блок ошибки отправки над кнопкой: тексты печатает сервер, JS берёт текст по коду и вставляет через textContent; tel/WhatsApp — при ошибке связи
$errBox = fn(bool $dark): string => '<div role="alert" data-err hidden data-invalid="В номере меньше 10 цифр. Проверьте, например: 8&nbsp;980&nbsp;000-00-00" data-fail="' . e($s['alert_fail']) . '" data-offline="' . e($s['alert_offline']) . '"'
    . ' style="margin-bottom:16px;padding:14px 16px;font-size:14px;line-height:1.5;' . ($dark ? 'background:#1a1608;border:1px solid #FFC700;color:#fff' : 'background:#fdf1f2;border:1px solid #b00020;color:#111') . '">'
    . '<p data-msg></p><div data-conn>' . $reach($dark) . '</div></div>';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<?php if ($pg): ?>
<base href="/">
<?php endif ?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pt ?? $s['seo_title']) ?></title>
<meta name="description" content="<?= e($pd ?? $s['seo_description']) ?>">
<meta name="theme-color" content="#0d0d0d">
<link rel="canonical" href="<?= e($url) ?>">
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<meta property="og:type" content="website">
<meta property="og:site_name" content="РегионДорСтрой">
<meta property="og:title" content="<?= e($pt ?? $s['seo_og_title']) ?>">
<meta property="og:description" content="<?= e($pd ?? $s['seo_og_description']) ?>">
<meta property="og:image" content="<?= e($s['base_url']) ?>assets/logo-rds.webp">
<meta property="og:image:width" content="256">
<meta property="og:image:height" content="256">
<meta property="og:image:alt" content="Логотип ООО РегионДорСтрой">
<meta property="og:url" content="<?= e($url) ?>">
<meta property="og:locale" content="ru_RU">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="<?= e($pt ?? $s['seo_tw_title']) ?>">
<meta name="twitter:description" content="<?= e($pd ?? $s['seo_tw_description']) ?>">
<meta name="twitter:image" content="<?= e($s['base_url']) ?>assets/logo-rds.webp">
<?php if (!$pg && $s['yandex_verification'] !== ''): ?>
<meta name="yandex-verification" content="<?= e($s['yandex_verification']) ?>">
<?php endif ?>
<?php if (!$pg && $s['google_verification'] !== ''): ?>
<meta name="google-site-verification" content="<?= e($s['google_verification']) ?>">
<?php endif ?>
<script type="application/ld+json">
<?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_PRETTY_PRINT) ?>

</script>
<?php if (!$pg): ?>
<link rel="preload" as="image" href="assets/hero-paving.webp" fetchpriority="high">
<?php endif ?>
<link rel="preload" as="font" type="font/woff2" href="assets/fonts/oswald-700-cyrillic.woff2" crossorigin>
<link rel="preload" as="font" type="font/woff2" href="assets/fonts/manrope-400-cyrillic.woff2" crossorigin>
<style>
@font-face{font-family:'Manrope';font-style:normal;font-weight:400;font-display:swap;src:url(assets/fonts/manrope-400-cyrillic-ext.woff2) format('woff2');unicode-range:U+0460-052F, U+1C80-1C8A, U+20B4, U+2DE0-2DFF, U+A640-A69F, U+FE2E-FE2F}
@font-face{font-family:'Manrope';font-style:normal;font-weight:400;font-display:swap;src:url(assets/fonts/manrope-400-cyrillic.woff2) format('woff2');unicode-range:U+0301, U+0400-045F, U+0490-0491, U+04B0-04B1, U+2116}
@font-face{font-family:'Manrope';font-style:normal;font-weight:400;font-display:swap;src:url(assets/fonts/manrope-400-latin-ext.woff2) format('woff2');unicode-range:U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF}
@font-face{font-family:'Manrope';font-style:normal;font-weight:400;font-display:swap;src:url(assets/fonts/manrope-400-latin.woff2) format('woff2');unicode-range:U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD}
@font-face{font-family:'Manrope';font-style:normal;font-weight:600;font-display:swap;src:url(assets/fonts/manrope-600-cyrillic-ext.woff2) format('woff2');unicode-range:U+0460-052F, U+1C80-1C8A, U+20B4, U+2DE0-2DFF, U+A640-A69F, U+FE2E-FE2F}
@font-face{font-family:'Manrope';font-style:normal;font-weight:600;font-display:swap;src:url(assets/fonts/manrope-600-cyrillic.woff2) format('woff2');unicode-range:U+0301, U+0400-045F, U+0490-0491, U+04B0-04B1, U+2116}
@font-face{font-family:'Manrope';font-style:normal;font-weight:600;font-display:swap;src:url(assets/fonts/manrope-600-latin-ext.woff2) format('woff2');unicode-range:U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF}
@font-face{font-family:'Manrope';font-style:normal;font-weight:600;font-display:swap;src:url(assets/fonts/manrope-600-latin.woff2) format('woff2');unicode-range:U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD}
@font-face{font-family:'Manrope';font-style:normal;font-weight:700;font-display:swap;src:url(assets/fonts/manrope-700-cyrillic-ext.woff2) format('woff2');unicode-range:U+0460-052F, U+1C80-1C8A, U+20B4, U+2DE0-2DFF, U+A640-A69F, U+FE2E-FE2F}
@font-face{font-family:'Manrope';font-style:normal;font-weight:700;font-display:swap;src:url(assets/fonts/manrope-700-cyrillic.woff2) format('woff2');unicode-range:U+0301, U+0400-045F, U+0490-0491, U+04B0-04B1, U+2116}
@font-face{font-family:'Manrope';font-style:normal;font-weight:700;font-display:swap;src:url(assets/fonts/manrope-700-latin-ext.woff2) format('woff2');unicode-range:U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF}
@font-face{font-family:'Manrope';font-style:normal;font-weight:700;font-display:swap;src:url(assets/fonts/manrope-700-latin.woff2) format('woff2');unicode-range:U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD}
@font-face{font-family:'Manrope';font-style:normal;font-weight:800;font-display:swap;src:url(assets/fonts/manrope-800-cyrillic-ext.woff2) format('woff2');unicode-range:U+0460-052F, U+1C80-1C8A, U+20B4, U+2DE0-2DFF, U+A640-A69F, U+FE2E-FE2F}
@font-face{font-family:'Manrope';font-style:normal;font-weight:800;font-display:swap;src:url(assets/fonts/manrope-800-cyrillic.woff2) format('woff2');unicode-range:U+0301, U+0400-045F, U+0490-0491, U+04B0-04B1, U+2116}
@font-face{font-family:'Manrope';font-style:normal;font-weight:800;font-display:swap;src:url(assets/fonts/manrope-800-latin-ext.woff2) format('woff2');unicode-range:U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF}
@font-face{font-family:'Manrope';font-style:normal;font-weight:800;font-display:swap;src:url(assets/fonts/manrope-800-latin.woff2) format('woff2');unicode-range:U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD}
@font-face{font-family:'Oswald';font-style:normal;font-weight:600;font-display:swap;src:url(assets/fonts/oswald-600-cyrillic-ext.woff2) format('woff2');unicode-range:U+0460-052F, U+1C80-1C8A, U+20B4, U+2DE0-2DFF, U+A640-A69F, U+FE2E-FE2F}
@font-face{font-family:'Oswald';font-style:normal;font-weight:600;font-display:swap;src:url(assets/fonts/oswald-600-cyrillic.woff2) format('woff2');unicode-range:U+0301, U+0400-045F, U+0490-0491, U+04B0-04B1, U+2116}
@font-face{font-family:'Oswald';font-style:normal;font-weight:600;font-display:swap;src:url(assets/fonts/oswald-600-latin-ext.woff2) format('woff2');unicode-range:U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF}
@font-face{font-family:'Oswald';font-style:normal;font-weight:600;font-display:swap;src:url(assets/fonts/oswald-600-latin.woff2) format('woff2');unicode-range:U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD}
@font-face{font-family:'Oswald';font-style:normal;font-weight:700;font-display:swap;src:url(assets/fonts/oswald-700-cyrillic-ext.woff2) format('woff2');unicode-range:U+0460-052F, U+1C80-1C8A, U+20B4, U+2DE0-2DFF, U+A640-A69F, U+FE2E-FE2F}
@font-face{font-family:'Oswald';font-style:normal;font-weight:700;font-display:swap;src:url(assets/fonts/oswald-700-cyrillic.woff2) format('woff2');unicode-range:U+0301, U+0400-045F, U+0490-0491, U+04B0-04B1, U+2116}
@font-face{font-family:'Oswald';font-style:normal;font-weight:700;font-display:swap;src:url(assets/fonts/oswald-700-latin-ext.woff2) format('woff2');unicode-range:U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF}
@font-face{font-family:'Oswald';font-style:normal;font-weight:700;font-display:swap;src:url(assets/fonts/oswald-700-latin.woff2) format('woff2');unicode-range:U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD}

  *{margin:0;padding:0;box-sizing:border-box}
  html{scroll-behavior:smooth}
  body{font-family:'Manrope',sans-serif;color:#111;background:#fff;-webkit-font-smoothing:antialiased}
  ::selection{background:#FFC700;color:#111}
  a{text-decoration:none;color:inherit}
  h1{overflow-wrap:anywhere} /* страховка: слово длиннее строки (правка владельца) переносится, а не вылезает за поля; кегли ниже подобраны так, что сид не рвётся */
  /* фокус: жёлтое кольцо + тёмная подложка 2px — видно и на светлом (#f4f4f2, жёлтый там 1.4:1), и на тёмном (#161616) */
  a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible,[tabindex]:focus-visible,[data-when] input:focus-visible+span{outline:3px solid #FFC700;outline-offset:2px;box-shadow:0 0 0 2px #0d0d0d}
  /* формы: видимые подписи над полями (13px), чипы «Когда нужно» — радио поверх всего чипа (тап-цель = чип, стрелки — нативные) */
  #modalForm label[for],#contacts label[for],[data-when] legend{display:block;margin-bottom:6px;padding:0;font-size:13px;font-weight:700;line-height:1.4;color:#3a3a3a}
  #contacts label[for]{color:#cfcfcf}
  #contacts ::placeholder{color:#8f8f8f;opacity:1} /* серый браузера #757575 на #161616 — 3.9:1, ниже AA */
  [data-when]{border:0;margin:0 0 14px;padding:0;min-width:0}
  [data-when] div{display:flex;gap:8px}
  [data-when] label{position:relative;flex:1;min-width:0}
  [data-when] input{position:absolute;inset:0;width:100%;height:100%;margin:0;opacity:0;cursor:pointer}
  [data-when] span{display:flex;align-items:center;justify-content:center;min-height:44px;padding:0 6px;background:#f4f4f2;border:1px solid #e2e2de;color:#111;font-size:15px;font-weight:700}
  [data-when] input:checked+span{background:#FFC700;border-color:#0d0d0d}
  /* окно на узком экране: меньше полей вокруг — поля и чипы шире (на 320 контент 256px вместо 200px) */
  @media (max-width:400px){#modalOverlay>div{padding:12px!important}[role=dialog]>div:last-child{padding:30px 20px 28px!important}}
  /* строка прайса = кнопка «в аренду»: имя ≥44 px по высоте без сдвига строки (поля компенсированы), тап по всей строке, включая цену.
     Узко (320–360): имя не уже своего самого длинного слова — цена уходит строкой ниже, справа, а не наезжает на имя */
  #fleet dl>div{position:relative;flex-wrap:wrap;row-gap:2px}
  #fleet dl dt{min-width:min-content!important}
  #fleet dl dd{margin-left:auto!important}
  #fleet dl button{display:block;width:100%;min-height:44px;margin:-12px 0;padding:12px 0;background:none;border:0;color:inherit;font:inherit;text-align:left;cursor:pointer}
  #fleet dl button::after{content:'';position:absolute;inset:0}
  #fleet dl [data-sub] button{margin:-6px 0 -19px;padding:6px 0 19px} /* подпозиция без верхнего поля: вверх цель заходит только в нижнее поле родительской строки (6px), не на её текст */
  #fleet dl [data-sub]{min-width:fit-content!important} /* «— с гидромолотом →» не рвётся на 320: не влезает целиком — цена уходит ниже */
  /* строка читается как одна цель: фон под курсором (где есть hover) и под пальцем; фон выходит за текст на 12px — поля блока прайса ≥16 */
  @media (hover:hover){#fleet dl>div:has(button:hover){background:#1f1f1f;box-shadow:-12px 0 0 #1f1f1f,12px 0 0 #1f1f1f}}
  #fleet dl>div:has(button:active){background:#1f1f1f;box-shadow:-12px 0 0 #1f1f1f,12px 0 0 #1f1f1f}
  [data-svc] h3 a{text-decoration:underline;text-decoration-color:#FFC700;text-decoration-thickness:2px;text-underline-offset:5px}
  [data-burger]{display:none}
  @keyframes kenburns{0%{transform:scale(1.03) translate(0,0)}100%{transform:scale(1.13) translate(-2%,-1.5%)}}
  [data-kenburns]{animation:kenburns 24s ease-in-out infinite alternate;transform-origin:center}
  @keyframes marquee{0%{transform:translateX(0)}100%{transform:translateX(-50%)}}
  [data-marquee]{animation:marquee 32s linear infinite;will-change:transform}
  [data-marquee-fast]{animation:marquee 22s linear infinite;will-change:transform}
  [data-progress]{position:fixed;top:0;left:0;height:3px;width:100%;background:#FFC700;z-index:200;transform:scaleX(0);transform-origin:0 50%;box-shadow:0 0 12px rgba(255,199,0,.6);will-change:transform}
  [data-lift]{transition:transform .35s cubic-bezier(.22,.61,.36,1),border-color .25s ease,box-shadow .35s ease}
  [data-lift]:hover{transform:translateY(-8px);box-shadow:0 24px 50px -20px rgba(0,0,0,.55)}
  [data-imgzoom]{overflow:hidden}
  [data-imgzoom] img{transition:transform .8s cubic-bezier(.22,.61,.36,1);display:block}
  [data-imgzoom]:hover img{transform:scale(1.08)}
  @media (prefers-reduced-motion:reduce){[data-kenburns],[data-marquee],[data-marquee-fast]{animation:none!important}}
  @media (max-width:1024px){
    [data-r="g4"]{grid-template-columns:repeat(2,1fr)!important}
    [data-r="g5"]{grid-template-columns:repeat(3,1fr)!important}
    #why [style*="gap:23px"]{gap:13px!important;padding-left:16px!important;padding-right:16px!important}
    #why img{width:48px!important;height:48px!important}
  }
  [data-hcall]{display:none;width:44px;height:44px;border:1.5px solid #3a3a3a;align-items:center;justify-content:center}
  @media (max-width:1120px){
    [data-hphone]{display:none!important}
    [data-hcall]{display:inline-flex!important}
  }
  /* шапка 1025–1170: лок-ап + 5 пунктов + кнопка + телефон не влезают при gap 28/24 — плотнее, пункты не переносятся (tests/pw/brand.js) */
  @media (max-width:1170px){
    header>div:first-child{gap:14px!important}
    nav[data-desknav]{gap:18px!important}
  }
  /* WhatsApp в липкой шапке ≥1024: ≥1121 — строкой под номером (шапку не расширяет); 1024 — с подписью рядом со значком звонка;
     1025–1120 — квадрат рядом со значком звонка (запас строки ~24 px, подпись не влезает), промежутки плотнее. ≤768 — нижняя панель, 769–1023 — меню */
  [data-hcontact]{display:flex;flex-direction:column;align-items:flex-start}
  [data-hwa]{display:none;align-items:center;gap:6px;min-height:24px;color:#FFC700;font-weight:700;font-size:13px;letter-spacing:.2px;white-space:nowrap}
  @media (min-width:1024px){[data-hwa]{display:inline-flex}}
  @media (max-width:1120px){
    [data-hcontact]{display:contents}
    [data-hwa]{min-height:44px;gap:8px;font-size:14.5px}
  }
  @media (min-width:1025px) and (max-width:1120px){
    header>div:first-child{gap:10px!important}
    header>div:first-child>div:last-child{gap:10px!important}
    nav[data-desknav]{gap:12px!important}
    button[data-desknav]{padding-left:15px!important;padding-right:15px!important}
    [data-hwa]{order:1;width:44px;justify-content:center;border:1.5px solid #3a3a3a}
    [data-hwa] svg{width:20px;height:20px}
    [data-hwa] span{display:none}
    [data-hcall]{order:2}
  }
  @media (max-width:1024px){
    [data-desknav]{display:none!important}
    [data-burger]{display:inline-flex!important}
    [data-tbhours]{display:none!important}
  }
  @media (max-width:900px){
    [data-r="two"]{grid-template-columns:1fr!important;gap:36px!important}
    [data-herowrap]{min-height:auto!important}
    [data-hero-content]{padding-top:92px!important;padding-bottom:52px!important}
    h1{font-size:44px!important}
    [data-paths]{flex-direction:column}
  }
  @media (max-width:768px){
    [data-r="g3"]{grid-template-columns:repeat(2,1fr)!important}
    [data-r="stats"]{grid-template-columns:repeat(2,1fr)!important;gap:30px 16px!important}
  }
  @media (max-width:640px){
    header>div:first-child{gap:10px!important}
    header>div:first-child>a{gap:11px!important}
    header>div:first-child>a img{width:40px!important;height:40px!important}
    header>div:first-child>a div div:first-child{font-size:18.5px!important}
    header>div:first-child>a div div+div{font-size:9.7px!important}
    header>div:first-child>div:last-child{gap:8px!important}
    [data-burger]{width:44px!important}
    [data-hero-cta] a{padding:13px 18px!important;font-size:14px!important} /* как [data-lp-cta] посадочных: «Позвонить 8 980 769-11-11» влезает в 256px на 320 */
    [data-pd]{display:block;font-size:14px} [data-psep]{display:none} /* путь — две строки: название / уточнение → (без висячей стрелки на 320–360) */
    #why img+div>div:first-child{font-size:18.5px!important}
    [data-r="g3"],[data-r="g4"],[data-r="g5"],[data-r="g2"],[data-r="foot"]{grid-template-columns:1fr!important}
    /* техника (T20): карточка — строка, фото 112×84 слева, имя/цена/кнопка справа; позиция ≤120 px — в экран 844 входят 5 (tests/pw/rent.js) */
    #fleet [data-r="g3"]{gap:12px!important} /* 360–389: «ЭКСКАВАТОРЫ-ПОГРУЗЧИКИ» в 2 строки (132 px) — 5 позиций всё равно входят и в 360×800 */
    #fleet [data-lift]{display:grid;grid-template-columns:112px minmax(0,1fr);column-gap:12px;align-items:center;padding:8px}
    #fleet [data-lift] img{width:112px!important;height:84px!important}
    #fleet [data-lift]>div{padding:0!important}
    #fleet [data-lift] h3{font-size:16px!important;line-height:1.15;overflow-wrap:anywhere} /* страховка: слово владельца длиннее колонки переносится, а не обрезается карточкой */
    #fleet [data-lift] h3+div{min-height:0!important;margin:2px 0 0!important}
    #fleet [data-lift] h3+div>span[style*="22px"]{font-size:20px!important;line-height:1.2} /* цена; «договорная» — свой кегль */
    #fleet [data-lift] button{margin-top:6px!important}
    h1{font-size:clamp(28px,9.2vw,44px)!important} /* «БЛАГОУСТРАИВАЕМ» в строку 256px на 320; посадочные — свои 28px ([data-lp-head] h1) */
    h2{font-size:32px!important}
    div[style*="padding:0 32px"]{padding-left:18px!important;padding-right:18px!important}
    div[style*="80px 32px 90px"]{padding-left:18px!important;padding-right:18px!important}
    div[style*="padding:9px 32px"]{padding-left:18px!important;padding-right:18px!important}
    /* верхняя полоса — одна строка 44px: адрес + номер (тап-цель 44); e-mail и WhatsApp — в мобильном меню, все каналы — в #contacts */
    [data-topbar]>div{padding-top:0!important;padding-bottom:0!important;flex-wrap:nowrap!important}
    [data-topbar]>div>div:last-child{margin-left:auto}
    [data-topbar] a:not([data-tbtel]){display:none!important}
    [data-tbtel]{display:inline-flex!important}
  }
  /* H1 главной в 4 строки на ≤400: отступы hero ужаты, подводка 17px — «Оставить заявку» в первом экране и на 320×640, 375×667 */
  @media (max-width:400px){
    [data-hero-content]{padding-top:64px!important}
    [data-hero-content]>div:first-child{margin-bottom:18px!important}
    [data-hero-content]>p{margin-bottom:24px!important;font-size:17px!important}
  }
  /* шапка <360: лок-ап ужат, чтобы рядом влезали иконка звонка и бургер; подпись = .525 кегля названия (ширины строк равны) */
  @media (max-width:359px){
    header>div:first-child>a{gap:10px!important}
    header>div:first-child>a img{width:36px!important;height:36px!important}
    header>div:first-child>a div div:first-child{font-size:14.8px!important}
    header>div:first-child>a div div+div{font-size:7.8px!important}
    #why img+div>div:first-child{font-size:14.8px!important}
    [data-topbar]>div>div:first-child{display:none!important} /* адрес (193px) + номер (111px) не влезают в 284px — остаётся номер */
    #fleet [data-lift] h3{font-size:14px!important;letter-spacing:0!important} /* колонка у фото 112 — 142px на 320; «АСФАЛЬТОУКЛАДЧИКИ» 16px = 162, 14px = 138 */
  }
  /* липкая панель «Позвонить + WhatsApp + Заявка» (≤768): видна без JS; JS ставит data-off, пока на экране CTA первого экрана, #contacts,
     окно или меню (CTA ниже экрана — тоже). z-index 80: под cookie-баром (90) и окном (100). Подвал удлинён на высоту панели — последняя строка не под ней */
  [data-cbar]{display:none}
  @media (max-width:768px){
    [data-cbar]{display:flex;position:fixed;left:0;right:0;bottom:0;z-index:80;background:#0d0d0d;padding-bottom:env(safe-area-inset-bottom);box-shadow:0 -6px 20px rgba(0,0,0,.28);transition:transform .25s cubic-bezier(.22,.61,.36,1),visibility .25s}
    [data-cbar] a{flex:1;min-width:0;height:56px;display:flex;align-items:center;justify-content:center;gap:10px;font-family:'Manrope';font-weight:800;font-size:15px;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap}
    [data-cbar] [role=button]{border-left:1px solid #333}
    [data-cbar][data-off]{transform:translateY(100%);visibility:hidden}
    footer{padding-bottom:calc(84px + env(safe-area-inset-bottom))!important}
  }
  /* три кнопки на узком (320 → по 106px): «Позвонить» с иконкой в строку не влезает и при 13px — иконка над подписью */
  @media (max-width:420px){[data-cbar] a{flex-direction:column;gap:4px;padding:0 4px;font-size:13px}}
  @media (prefers-reduced-motion:reduce){[data-cbar]{transition:none}}
  /* контакты: на ≤360 городской переносится под мобильный — номер прижат к низу своей цели, городской к верху: читаются парой, цели по 44 */
  @media (max-width:360px){[data-tels]>a:first-child{align-items:flex-end!important}[data-tels]>a+a{align-items:flex-start!important}}
  /* контакты карточки «Почему мы»: одна строка или ровно две — «г. Орёл · телефон» / почта (294 = ширина строки при 14px + 2px) */
  @container (max-width:294px){
    #why [data-csep]{display:none}
    #why [data-cmail]{display:block}
  }
  /* подвал на телефоне и тач-планшете: ссылки 17–19px по высоте → тап-зона ≥44px, ПК (мышь) без изменений */
  @media (max-width:640px),(pointer:coarse){
    footer [style*="flex-direction:column"]{gap:0!important}
    footer [style*="flex-direction:column"]>*{display:flex;align-items:center;min-height:44px}
    footer span>a{display:inline-flex;align-items:center;min-height:44px}
  }

</style>
</head>
<body>


<div style="overflow-x:hidden;overflow-x:clip">

  <div data-progress aria-hidden="true"></div>

  <!-- ================= TOP BAR ================= -->
  <aside aria-label="Контакты" data-topbar style="background:#0a0a0a;color:#b8b8b8;font-size:13px;border-bottom:1px solid #1c1c1c">
    <div style="max-width:1240px;margin:0 auto;padding:9px 32px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
      <div style="display:flex;gap:22px;align-items:center;flex-wrap:wrap">
        <span style="display:flex;align-items:center;gap:8px;white-space:nowrap"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#FFC700" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s7-6.3 7-11a7 7 0 1 0-14 0c0 4.7 7 11 7 11Z"/><circle cx="12" cy="10" r="2.4"/></svg><?= e($s['address_short']) ?></span>
        <span data-tbhours style="display:flex;align-items:center;gap:8px;white-space:nowrap"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#FFC700" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7.5V12l3 2"/></svg><?= e($s['hours_text']) ?></span>
      </div>
      <div style="display:flex;gap:20px;align-items:center">
        <a href="tel:<?= e($s['phone_mobile']) ?>" data-tbtel style="display:none;align-items:center;min-height:44px;font-family:'Oswald';font-weight:600;font-size:17px;letter-spacing:.3px;color:#FFC700;white-space:nowrap"><?= e($s['phone_mobile_fmt']) ?></a>
        <a href="mailto:<?= e($s['email']) ?>" style="color:#cfcfcf"><?= e($s['email']) ?></a>
<?php if ($s['whatsapp'] !== ''): ?>
        <a href="<?= e($s['whatsapp']) ?>" style="color:#FFC700;font-weight:600">WhatsApp</a>
<?php endif ?>
<?php if ($s['telegram'] !== ''): ?>
        <a href="<?= e($s['telegram']) ?>" style="color:#FFC700;font-weight:600">Telegram</a>
<?php endif ?>
      </div>
    </div>
  </aside>

  <!-- ================= HEADER ================= -->
  <header style="position:sticky;top:0;z-index:50;background:#0d0d0d;border-bottom:3px solid #FFC700">
    <div style="max-width:1240px;margin:0 auto;padding:0 32px;height:78px;display:flex;align-items:center;justify-content:space-between;gap:24px">
      <a href="#top" style="display:flex;align-items:center;gap:13px;flex-shrink:0">
        <img src="assets/logo-rds.webp" alt="Логотип ООО «РегионДорСтрой»" style="width:48px;height:48px;object-fit:contain;flex-shrink:0">

        <div style="line-height:1.35">
          <div style="font-family:'Oswald';font-weight:700;font-size:20px;letter-spacing:.06em;color:#fff;line-height:1;margin-bottom:.2em">РЕГИОНДОРСТРОЙ</div>
          <div style="font-size:10.5px;letter-spacing:.08em;color:#FFC700;font-weight:600">ДОРОГИ · БЛАГОУСТРОЙСТВО</div>
          <div style="font-size:10.5px;letter-spacing:.08em;color:#FFC700;font-weight:600">АРЕНДА СПЕЦТЕХНИКИ</div>
        </div>
      </a>

      <nav data-desknav style="display:flex;gap:28px;align-items:center;font-size:14.5px;font-weight:600;color:#d8d8d8">
<?php foreach ($nav as $n): ?>
        <a href="<?= e($n['href']) ?>" style="" style-hover="color:#FFC700"><?= e($n['title']) ?></a>
<?php endforeach ?>
      </nav>

      <div style="display:flex;align-items:center;gap:18px;flex-shrink:0">
        <div data-hcontact>
          <a href="tel:<?= e($s['phone_mobile']) ?>" data-hphone style="font-family:'Oswald';font-weight:600;font-size:18px;color:#fff;letter-spacing:.3px"><?= e($s['phone_mobile_fmt']) ?></a>
<?php if ($s['whatsapp'] !== ''): ?>
          <a href="<?= e($waHi) ?>" data-hwa aria-label="Написать в WhatsApp" style-hover="color:#fff"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#FFC700" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20l1.4-4.1A8 8 0 1 1 8.3 18.7Z"/></svg><span>WhatsApp</span></a>
<?php endif ?>
        </div>
        <button onclick="openModal()" data-desknav style="background:#FFC700;color:#0d0d0d;border:none;font-family:'Manrope';font-weight:800;font-size:13.5px;padding:13px 22px;cursor:pointer;letter-spacing:.3px;text-transform:uppercase" style-hover="background:#ffd633">Заказать звонок</button>
        <a href="tel:<?= e($s['phone_mobile']) ?>" data-hcall aria-label="Позвонить <?= e($s['phone_mobile_fmt']) ?>"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FFC700" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h3l2 5-2.2 1.3a12 12 0 0 0 5.7 5.7L16 15l5 2v3a2 2 0 0 1-2 2A17 17 0 0 1 4 5a2 2 0 0 1 2-2Z"/></svg></a>
        <button onclick="toggleMenu()" data-burger aria-label="Открыть меню" style="background:none;border:1.5px solid #3a3a3a;color:#fff;width:48px;height:44px;cursor:pointer;align-items:center;justify-content:center;flex-direction:column;gap:5px" style-hover="border-color:#FFC700">
          <span aria-hidden="true" style="display:block;width:20px;height:2px;background:#FFC700"></span>
          <span aria-hidden="true" style="display:block;width:20px;height:2px;background:#FFC700"></span>
          <span aria-hidden="true" style="display:block;width:20px;height:2px;background:#FFC700"></span>
        </button>
      </div>
    </div>
      <div id="mobileMenu" style="display:none">
        <div style="position:absolute;top:100%;left:0;right:0;background:#0d0d0d;border-bottom:3px solid #FFC700;padding:10px 24px 20px;display:flex;flex-direction:column;box-shadow:0 20px 40px rgba(0,0,0,.4)">
<?php foreach ($nav as $n): ?>
          <a onclick="closeMenu()" href="<?= e($n['href']) ?>" style="color:#fff;font-weight:600;font-size:16px;padding:14px 4px;border-bottom:1px solid #1c1c1c" style-hover="color:#FFC700"><?= e($n['title']) ?></a>
<?php endforeach ?>
          <a href="tel:<?= e($s['phone_mobile']) ?>" style="color:#FFC700;font-family:'Oswald';font-weight:600;font-size:22px;padding:16px 4px 6px"><?= e($s['phone_mobile_fmt']) ?></a>
          <div style="display:flex;flex-wrap:wrap;column-gap:24px">
            <a href="mailto:<?= e($s['email']) ?>" style="display:inline-flex;align-items:center;min-height:44px;padding:0 4px;color:#cfcfcf;font-size:15px"><?= e($s['email']) ?></a>
<?php if ($s['whatsapp'] !== ''): ?>
            <a href="<?= e($s['whatsapp']) ?>" style="display:inline-flex;align-items:center;min-height:44px;padding:0 4px;color:#FFC700;font-weight:700;font-size:15px">WhatsApp</a>
<?php endif ?>
          </div>
          <button onclick="openModalMobile()" style="margin-top:10px;background:#FFC700;color:#0d0d0d;border:none;font-family:'Manrope';font-weight:800;font-size:15px;padding:15px;cursor:pointer;text-transform:uppercase;letter-spacing:.3px">Заказать звонок</button>
        </div>
      </div>
  </header>

  <main>
<?php if ($pg): require __DIR__ . '/landing.php'; else: ?>
  <!-- ================= HERO (cinematic full-bleed) ================= -->
  <section id="top" data-herowrap style="background:#0d0d0d;position:relative;color:#fff;overflow:hidden;min-height:90vh;display:flex;align-items:flex-end">
    <!-- image layer with slow ken-burns drift -->
    <div data-kenburns style="position:absolute;inset:0;z-index:0">
      <img src="assets/hero-paving.webp" alt="Асфальтоукладчики и катки на укладке асфальта" loading="eager" fetchpriority="high" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:50% 42%">
    </div>
    <!-- cinematic scrims -->
    <div style="position:absolute;inset:0;z-index:1;background:linear-gradient(180deg,rgba(13,13,13,.72) 0%,rgba(13,13,13,.28) 34%,rgba(13,13,13,.5) 62%,rgba(13,13,13,.95) 100%)"></div>
    <div style="position:absolute;inset:0;z-index:1;background:linear-gradient(95deg,rgba(13,13,13,.9) 0%,rgba(13,13,13,.35) 52%,transparent 100%)"></div>

    <div style="position:relative;z-index:2;max-width:1240px;margin:0 auto;padding:128px 32px 62px;width:100%" data-hero-content>
      <div data-anim="fade-up" style="display:inline-flex;align-items:center;gap:9px;background:rgba(255,199,0,.14);border:1px solid rgba(255,199,0,.4);color:#FFC700;font-weight:700;font-size:13px;padding:8px 15px;letter-spacing:.5px;margin-bottom:26px"><span aria-hidden="true" style="width:7px;height:7px;background:#FFC700;flex-shrink:0"></span><?= e($s['hero_badge']) ?></div>
      <h1 data-anim="fade-up" style="font-family:'Oswald';font-weight:700;font-size:80px;line-height:.97;letter-spacing:.5px;text-transform:uppercase;margin-bottom:24px;max-width:920px;text-shadow:0 6px 44px rgba(0,0,0,.55)"><?= $s['hero_h1'] /* trusted_html, правит только owner */ ?></h1>
      <p data-anim="fade-up" style="font-size:19px;line-height:1.6;color:#dcdcdc;max-width:560px;margin-bottom:38px;text-shadow:0 2px 20px rgba(0,0,0,.5)"><?= e($s['hero_p']) ?></p>
      <div data-anim="fade-up" data-hero-cta style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:20px">
        <a href="#contacts" style="background:#FFC700;color:#0d0d0d;font-family:'Manrope';font-weight:800;font-size:15px;padding:17px 30px;text-transform:uppercase;letter-spacing:.3px;display:inline-flex;align-items:center" style-hover="background:#ffd633">Оставить заявку</a>
        <a href="tel:<?= e($s['phone_mobile']) ?>" style="background:rgba(255,255,255,.06);color:#fff;border:1.5px solid rgba(255,255,255,.28);font-weight:700;font-size:15px;padding:16px 30px;text-transform:uppercase;letter-spacing:.3px;display:inline-flex;align-items:center" style-hover="border-color:#FFC700">Позвонить&nbsp;<span style="font-family:'Oswald';letter-spacing:.3px;white-space:nowrap"><?= e($s['phone_mobile_fmt']) ?></span></a>
      </div>
      <!-- три пути (strategy Р2.2): без цен и не [data-lp-chips] — на / чипов нет (tests/pw/fold.js); «Дороги» → форма, пока посадочная asf не опубликована -->
      <ul data-anim="fade-up" data-paths aria-label="Направления" style="list-style:none;margin:0 0 44px;padding:0;display:flex;flex-wrap:wrap;column-gap:24px;text-shadow:0 2px 20px rgba(0,0,0,.5)">
<?php foreach ([['/arenda-spetstekhniki/', 'Аренда спецтехники', 'прайс по моделям'], ['/shcheben-pesok-grunt/', 'Щебень, песок, грунт', 'цены и доставка'],
    [page_gaps($s, 'asf') ? '#contacts' : '/asfaltirovanie/', 'Дороги и благоустройство', 'расчёт']] as [$ph, $pt, $pd]): ?>
        <li><a href="<?= e($ph) ?>" style="display:block;padding:11px 0;font-size:15px;line-height:22px;font-weight:700;color:#fff" style-hover="color:#FFC700"><?= e($pt) ?> <span data-pd style="font-weight:600;color:#c7c7c7;white-space:nowrap"><span data-psep>· </span><?= e($pd) ?>&nbsp;<span aria-hidden="true" style="color:#FFC700">→</span></span></a></li>
<?php endforeach ?>
      </ul>
      <div data-anim="fade-up" style="display:flex;gap:clamp(28px,5vw,56px);flex-wrap:wrap;border-top:1px solid rgba(255,255,255,.2);padding-top:26px;max-width:680px">
<?php foreach ($statHero as $r): ?>
        <div><div style="font-family:'Oswald';font-weight:700;font-size:40px;color:#FFC700;line-height:1"><?= $stat($r) ?></div><div style="font-size:13px;color:#c7c7c7;margin-top:2px"><?= e($r['title']) ?></div></div>
<?php endforeach ?>
      </div>
    </div>

    <div style="position:absolute;left:0;right:0;bottom:0;height:10px;z-index:3;background-image:repeating-linear-gradient(45deg,#FFC700 0 18px,#0d0d0d 18px 36px);pointer-events:none"></div>
  </section>

  <!-- ================= MARQUEE TICKER ================= -->
  <div style="background:#0d0d0d;overflow:hidden;padding:16px 0;border-top:1px solid #242424;border-bottom:1px solid #242424">
    <div data-marquee style="display:flex;align-items:center;white-space:nowrap;font-family:'Oswald';font-weight:700;font-size:19px;letter-spacing:1.5px;text-transform:uppercase;color:#efefef">
<?php foreach (['false', 'true'] as $hid): ?>
      <span style="display:flex;align-items:center;gap:20px;padding-right:20px" aria-hidden="<?= $hid ?>"><?php foreach ($marquee as $m): ?><?= e($m['title']) ?> <span style="color:#FFC700">◆</span> <?php endforeach ?></span>
<?php endforeach ?>
    </div>
  </div>
<?php endif ?>

<?php if (!$pg): // на посадочной вместо сетки — строка «Другие услуги» ($svc из landing.php, печатается после fleet/materials) ?>
  <!-- ================= SERVICES ================= -->
  <section id="services" data-cine style="background:#f5f5f3;padding:90px 0">
    <div style="max-width:1240px;margin:0 auto;padding:0 32px">
      <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:24px;margin-bottom:48px;flex-wrap:wrap">
        <div data-anim="slide-left">
          <div style="font-family:'Oswald';font-weight:600;font-size:14px;letter-spacing:3px;color:#8a6d00;margin-bottom:10px"><?= e($s['services_label']) ?></div>
          <h2 style="font-family:'Oswald';font-weight:700;font-size:46px;text-transform:uppercase;line-height:1.05;letter-spacing:.5px"><?= e($s['services_h2']) ?></h2>
        </div>
        <p data-anim="slide-right" style="max-width:380px;color:#5a5a5a;font-size:15.5px;line-height:1.6"><?= e($s['services_lead']) ?></p>
      </div>

      <div data-r="g3" style="display:grid;grid-template-columns:repeat(<?= $cols(count($services), 3) ?>,1fr);gap:2px;background:#e2e2de;border:1px solid #e2e2de">
<?php foreach ($services as $i => $r): ?>
          <div data-anim="rise" data-svc style="background:#fff;padding:38px 32px">
            <div data-svc-num style="font-family:'Oswald';font-weight:700;font-size:15px;color:#8a6d00;letter-spacing:1px;margin-bottom:18px;transition:color .3s ease"><?= sprintf('%02d', $i + 1) ?> /</div>
            <h3 style="font-family:'Oswald';font-weight:600;font-size:23px;text-transform:uppercase;margin-bottom:12px;line-height:1.15;color:inherit"><?= $r['href'] !== $here && $live($r['href']) ? '<a href="' . e($r['href']) . '">' . e($r['title']) . '</a>' : e($r['title']) ?></h3>
            <p style="color:#6a6a6a;font-size:14.5px;line-height:1.6"><?= e($r['text']) ?></p>
          </div>
<?php endforeach ?>
      </div>
    </div>
  </section>
<?php endif ?>

<?php if ($show('fleet')): ?>
  <!-- ================= FLEET ================= -->
  <section id="fleet" data-cine style="background:#0d0d0d;color:#fff;padding:90px 0;position:relative">
    <div style="max-width:1240px;margin:0 auto;padding:0 32px">
      <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:24px;margin-bottom:48px;flex-wrap:wrap">
        <div data-anim="fade-up">
          <div style="font-family:'Oswald';font-weight:600;font-size:14px;letter-spacing:3px;color:#FFC700;margin-bottom:10px"><?= e($s['fleet_label']) ?></div>
          <h2 style="font-family:'Oswald';font-weight:700;font-size:46px;text-transform:uppercase;line-height:1.05;letter-spacing:.5px"><?= e($s['fleet_h2']) ?></h2>
        </div>
        <p data-anim="fade-up" style="max-width:400px;color:#a8a8a8;font-size:15.5px;line-height:1.6"><?= e($s['fleet_lead']) ?></p>
      </div>

      <div data-r="g3" style="display:grid;grid-template-columns:repeat(<?= $cols(count($fleet), 3) ?>,1fr);gap:18px">
<?php foreach ($fleet as $r): ?>
          <div data-anim="tilt" data-lift data-imgzoom style="background:#161616;border:1px solid #262626" style-hover="border-color:#FFC700">
            <img src="<?= e($r['img']) ?>" alt="<?= e($r['alt']) ?>" loading="lazy" style="display:block;width:100%;height:150px;object-fit:cover;object-position:<?= e($r['img_pos']) ?>">
            <div style="padding:18px 18px 20px">
              <h3 style="font-family:'Oswald';font-weight:600;font-size:18px;text-transform:uppercase;letter-spacing:.3px"><?= e($r['title']) ?></h3>
              <div style="display:flex;align-items:baseline;flex-wrap:wrap;gap:5px;margin-top:10px;margin-bottom:4px;min-height:32px">
<?php if ($r['price_kind'] === 'contract'): ?>
                <?= $badge ?>

<?php else: ?>
                <span style="font-family:'Oswald';font-weight:700;font-size:22px;color:#fff"><?= e(price($r['price_kind'], $r['price'])) ?></span>
                <span style="font-size:14px;color:#a8a8a8">/ <?= e($r['unit']) ?></span>
<?php endif ?>
              </div>
              <button type="button" onclick="openRent(this)" aria-label="В аренду: <?= e($r['title']) ?>" style="display:flex;align-items:center;justify-content:center;width:100%;min-height:44px;margin-top:12px;padding:0 12px;background:none;border:1.5px solid #FFC700;cursor:pointer;font-family:'Manrope';font-size:13.5px;color:#FFC700;font-weight:800;letter-spacing:.5px" style-hover="background:#FFC700;color:#0d0d0d">В АРЕНДУ →</button>
            </div>
          </div>
<?php endforeach ?>
      </div>
<?php /* подпись к фото техники (V8 а): пустое значение — строки нет (снимается, когда будут фото владельца) */
if (($note = trim($s['fleet_photo_note'] ?? 'Фото для примера. Модель на снимке может отличаться от той, что в прайсе.')) !== ''): ?>
      <p data-photonote style="margin-top:16px;color:#a8a8a8;font-size:14px;line-height:1.5"><?= e($note) ?></p>
<?php endif ?>

      <div data-anim="fade-up" style="margin-top:40px;background:#161616;border:1px solid #262626;padding:28px clamp(16px,3vw,32px) 8px">
        <h3 style="font-family:'Oswald';font-weight:600;font-size:26px;text-transform:uppercase;letter-spacing:.3px;margin-bottom:14px"><?= e($s['fleet_prices_h3']) ?></h3>
        <dl style="margin:0;font-size:15px;line-height:1.4;border-top:1px solid #333">
<?php $n = count($prices); foreach ($prices as $i => $r):
    $pad = $r['is_sub'] ? 'padding:0 0 14px;border-bottom:1px solid #262626'
        : (!empty($prices[$i + 1]['is_sub']) ? 'padding:14px 0 6px'
        : ($i === $n - 1 ? 'padding:14px 0' : 'padding:14px 0;border-bottom:1px solid #262626')); ?>
          <div style="display:flex;justify-content:space-between;align-items:baseline;gap:16px;<?= $pad ?>">
<?php if ($r['is_sub']): ?>
            <dt data-sub style="flex:1;min-width:0;padding-left:12px;color:#a8a8a8;font-size:14px"><button type="button" onclick="openCalc('<?= jsattr($sub = trim(($tech ?? '') . ' ' . $r['name'])) ?>','Аренда спецтехники')" aria-label="В аренду: <?= e($sub) ?>" style-hover="color:#FFC700">— <?= e($r['name']) ?><?= $req ?></button></dt><?php /* $tech — родительская строка выше (подпозиция идёт сразу за ней) */ ?>
<?php else: ?>
            <dt style="flex:1;min-width:0;font-weight:600"><button type="button" onclick="openCalc('<?= jsattr($tech = trim($r['name'] . ' ' . $r['model'])) ?>','Аренда спецтехники')" aria-label="В аренду: <?= e($tech) ?>" style-hover="color:#FFC700"><?= e($r['name']) ?><?php if ($r['model'] !== ''): ?> <span style="color:#a8a8a8;font-weight:500;white-space:nowrap"><?= e($r['model']) ?></span><?php endif ?><?= $req ?></button></dt>
<?php endif ?>
<?php if ($r['price_kind'] === 'contract'): ?>
            <dd style="margin:0;white-space:nowrap"><?= $badge ?> <span style="font-size:14px;color:#a8a8a8">/ <?= e($r['unit']) ?></span></dd>
<?php else: ?>
            <dd style="margin:0;white-space:nowrap"><span style="font-family:'Oswald';font-weight:700;font-size:20px;color:#fff"><?= e(price($r['price_kind'], $r['price'])) ?></span> <span style="font-size:14px;color:#a8a8a8">/ <?= e($r['unit']) ?></span></dd>
<?php endif ?>
          </div>
<?php endforeach ?>
        </dl>
      </div>

      <div style="margin-top:36px;display:flex;align-items:center;justify-content:center;gap:16px;flex-wrap:wrap">
        <span style="color:#bfbfbf;font-size:15px"><?= e($s['fleet_cta_text']) ?></span>
        <button onclick="openModal()" style="background:#FFC700;color:#0d0d0d;border:none;font-family:'Manrope';font-weight:800;font-size:14px;padding:14px 26px;cursor:pointer;text-transform:uppercase;letter-spacing:.3px" style-hover="background:#ffd633">Узнать стоимость аренды</button>
      </div>
    </div>
  </section>
<?php endif ?>

<?php if ($show('materials')): ?>
  <!-- ================= MATERIALS / CATALOG ================= -->
  <section id="materials" data-cine style="background:#fff;padding:90px 0">
    <div style="max-width:1240px;margin:0 auto;padding:0 32px">
      <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:24px;margin-bottom:48px;flex-wrap:wrap">
        <div data-anim="slide-left">
          <div style="font-family:'Oswald';font-weight:600;font-size:14px;letter-spacing:3px;color:#8a6d00;margin-bottom:10px"><?= e($s['materials_label']) ?></div>
          <h2 style="font-family:'Oswald';font-weight:700;font-size:46px;text-transform:uppercase;line-height:1.05;letter-spacing:.5px"><?= e($s['materials_h2']) ?></h2>
        </div>
        <p data-anim="slide-right" style="max-width:400px;color:#5a5a5a;font-size:15.5px;line-height:1.6"><?= e($s['materials_lead']) ?></p>
      </div>

      <div data-r="g4" style="display:grid;grid-template-columns:repeat(<?= $cols(count($materials), 4) ?>,1fr);gap:20px">
<?php foreach ($materials as $r): ?>
          <div data-anim="rotate-rise" data-lift style="border:1px solid #e6e6e2;display:flex;flex-direction:column;background:#fff" style-hover="border-color:#FFC700">
            <div data-imgzoom style="position:relative">
              <img src="<?= e($r['img']) ?>" alt="<?= e($r['alt']) ?>" loading="lazy" style="display:block;width:100%;height:160px;object-fit:cover;object-position:50% 50%">
<?php if ($r['badge'] !== ''): ?>
                <span style="position:absolute;top:12px;left:12px;background:#FFC700;color:#0d0d0d;font-family:'Oswald';font-weight:700;font-size:11.5px;letter-spacing:1px;text-transform:uppercase;padding:6px 11px"><?= e($r['badge']) ?></span>
<?php endif ?>
            </div>
            <div style="padding:20px;display:flex;flex-direction:column;flex:1">
              <h3 style="font-family:'Oswald';font-weight:600;font-size:21px;text-transform:uppercase;margin-bottom:8px"><?= e($r['title']) ?></h3>
              <p style="color:#6a6a6a;font-size:13.5px;line-height:1.55;flex:1"><?= e($r['text']) ?></p>
              <div style="display:flex;align-items:baseline;gap:5px;margin:16px 0 14px">
<?php if ($r['price_kind'] === 'contract'): ?>
                <?= $badge ?>

<?php else: ?>
                <span style="font-family:'Oswald';font-weight:700;font-size:26px;color:#0d0d0d"><?= e(price($r['price_kind'], $r['price'])) ?></span>
                <span style="font-size:13px;color:#5f5f5f">/ <?= e($r['unit']) ?></span>
<?php endif ?>
              </div>
              <button onclick="openCalc('<?= jsattr($r['title']) ?>','','<?= jsattr($r['unit']) ?>')" aria-label="Запросить расчёт: <?= e($r['title']) ?>" style="background:#0d0d0d;color:#fff;border:none;font-family:'Manrope';font-weight:700;font-size:13.5px;padding:13px;cursor:pointer;text-transform:uppercase;letter-spacing:.5px;width:100%" style-hover="background:#FFC700;color:#0d0d0d">Запросить расчёт</button>
            </div>
          </div>
<?php endforeach ?>
      </div>
    </div>
  </section>
<?php endif; if ($pg) echo $svc ?? '' ?>

<?php if ($show('stats')): ?>
  <!-- ================= STATS BAND ================= -->
  <section data-cine style="background:#FFC700;padding:54px 0">
    <div data-r="stats" style="max-width:1240px;margin:0 auto;padding:0 32px;display:grid;grid-template-columns:repeat(<?= $cols(count($statBar), 4) ?>,1fr);gap:24px">
<?php $n = count($statBar); foreach ($statBar as $i => $r): ?>
      <div data-anim="pop" style="<?= $i === $n - 1 ? 'text-align:center' : 'text-align:center;border-right:1px solid rgba(0,0,0,.12)' ?>">
        <div style="font-family:'Oswald';font-weight:700;font-size:46px;color:#0d0d0d;line-height:1"><?= $stat($r) ?></div>
        <div style="font-weight:600;font-size:14px;color:#3a3000;margin-top:4px"><?= e($r['title']) ?></div>
      </div>
<?php endforeach ?>
    </div>
  </section>
<?php endif ?>

<?php if ($show('why')): ?>
  <!-- ================= WHY US ================= -->
  <section id="why" data-cine style="background:#f5f5f3;padding:90px 0">
    <div style="max-width:1240px;margin:0 auto;padding:0 32px">
      <div data-r="two" style="display:grid;grid-template-columns:.9fr 1.1fr;gap:56px;align-items:center">
        <div data-anim="slide-left">
          <div style="font-family:'Oswald';font-weight:600;font-size:14px;letter-spacing:3px;color:#8a6d00;margin-bottom:10px"><?= e($s['why_label']) ?></div>
          <h2 style="font-family:'Oswald';font-weight:700;font-size:44px;text-transform:uppercase;line-height:1.06;letter-spacing:.5px;margin-bottom:20px"><?= $s['why_h2'] /* trusted_html, правит только owner */ ?></h2>
          <p style="color:#5a5a5a;font-size:16px;line-height:1.65;margin-bottom:28px"><?= e($s['why_p']) ?></p>
          <div style="background:#fff;border:1px solid #e6e6e2;padding:22px 24px;display:flex;align-items:center;gap:23px">
            <img src="assets/logo-rds.webp" alt="ООО РегионДорСтрой" style="width:84px;height:84px;object-fit:contain;flex-shrink:0">
            <div style="flex:1;min-width:0;container-type:inline-size">
              <div style="font-family:'Oswald';font-weight:700;font-size:20px;text-transform:uppercase;letter-spacing:.06em;line-height:1;padding-top:.1em"><?= e($s['org_name']) ?></div>
              <div style="color:#6a6a6a;font-size:14px;margin-top:6px"><span style="white-space:nowrap">г. Орёл · <?= e($s['phone_mobile_fmt']) ?></span><span data-csep> · </span><span data-cmail><?= e($s['email']) ?></span></div>
            </div>
          </div>
        </div>

        <div data-r="g2" style="display:grid;grid-template-columns:<?= ($c = $cols(count($why), 2)) === 2 ? '1fr 1fr' : "repeat($c,1fr)" ?>;gap:18px">
<?php foreach ($why as $r): ?>
            <div data-anim="slide-right" style="background:#fff;border:1px solid #e6e6e2;border-top:3px solid #FFC700;padding:28px 24px">
              <h3 style="font-family:'Oswald';font-weight:600;font-size:21px;text-transform:uppercase;margin-bottom:10px;line-height:1.1"><?= e($r['title']) ?></h3>
              <p style="color:#6a6a6a;font-size:14px;line-height:1.6"><?= e($r['text']) ?></p>
            </div>
<?php endforeach ?>
        </div>
      </div>
    </div>
  </section>
<?php endif ?>

<?php if ($show('steps')): ?>
  <!-- ================= PROCESS ================= -->
  <section data-cine style="background:#0d0d0d;color:#fff;padding:84px 0">
    <div style="max-width:1240px;margin:0 auto;padding:0 32px">
      <div data-anim="fade-down" style="text-align:center;margin-bottom:50px">
        <div style="font-family:'Oswald';font-weight:600;font-size:14px;letter-spacing:3px;color:#FFC700;margin-bottom:10px"><?= e($s['steps_label']) ?></div>
        <h2 style="font-family:'Oswald';font-weight:700;font-size:44px;text-transform:uppercase;letter-spacing:.5px"><?= e($s['steps_h2']) ?></h2>
      </div>
      <div data-r="g5" style="display:grid;grid-template-columns:repeat(<?= $cols(count($steps), 5) ?>,1fr);gap:18px">
<?php foreach ($steps as $i => $r): ?>
          <div data-anim="fade-up" style="border-top:1px solid #2a2a2a;padding-top:22px">
            <div style="font-family:'Oswald';font-weight:700;font-size:40px;color:#FFC700;line-height:1;margin-bottom:14px"><?= sprintf('%02d', $i + 1) ?></div>
            <h3 style="font-family:'Oswald';font-weight:600;font-size:19px;text-transform:uppercase;margin-bottom:9px"><?= e($r['title']) ?></h3>
            <p style="color:#9a9a9a;font-size:13.5px;line-height:1.55"><?= e($r['text']) ?></p>
          </div>
<?php endforeach ?>
      </div>
    </div>
  </section>
<?php endif ?>

  <!-- ================= CONTACTS ================= -->
  <section id="contacts" data-cine style="background:#fff;padding:90px 0">
    <div style="max-width:1240px;margin:0 auto;padding:0 32px">
      <div data-r="two" style="display:grid;grid-template-columns:1fr 1fr;gap:48px">
        <div data-anim="slide-left">
          <div style="font-family:'Oswald';font-weight:600;font-size:14px;letter-spacing:3px;color:#8a6d00;margin-bottom:10px"><?= e($s['contacts_label']) ?></div>
          <h2 style="font-family:'Oswald';font-weight:700;font-size:44px;text-transform:uppercase;line-height:1.05;letter-spacing:.5px;margin-bottom:28px"><?= e($s['contacts_h2']) ?></h2>

          <div style="display:flex;flex-direction:column;gap:16px;margin-bottom:30px">
            <!-- два номера — две ссылки tel: ≥44 px (городской звонит на городской); в одну строку, на узком городской — строкой ниже -->
            <div style="display:flex;align-items:center;gap:14px">
              <span style="width:46px;height:46px;background:#0d0d0d;color:#FFC700;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FFC700" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h3l2 5-2.2 1.3a12 12 0 0 0 5.7 5.7L16 15l5 2v3a2 2 0 0 1-2 2A17 17 0 0 1 4 5a2 2 0 0 1 2-2Z"/></svg></span>
              <div data-tels style="display:flex;flex-wrap:wrap;align-items:center;column-gap:14px">
                <a href="tel:<?= e($s['phone_mobile']) ?>" style="display:flex;align-items:center;min-height:44px;font-family:'Oswald';font-weight:600;font-size:21px;white-space:nowrap"><?= e($s['phone_mobile_fmt']) ?></a>
<?php if ($s['phone_city'] !== ''): ?>
                <a href="tel:<?= e($s['phone_city']) ?>" style="display:flex;align-items:center;min-height:44px;font-size:13px;color:#5f5f5f;white-space:nowrap">городской:&nbsp;<span style="color:#111;font-weight:600"><?= e($s['phone_city_fmt']) ?></span></a>
<?php endif ?>
              </div>
            </div>
            <a href="mailto:<?= e($s['email']) ?>" style="display:flex;align-items:center;gap:14px">
              <span style="width:46px;height:46px;background:#0d0d0d;color:#FFC700;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FFC700" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="1.5"/><path d="m3.5 7 8.5 6 8.5-6"/></svg></span>
              <span><span style="display:block;font-family:'Oswald';font-weight:600;font-size:18px"><?= e($s['email']) ?></span><span style="font-size:13px;color:#5f5f5f">ответим <?= e(stub($s, 'reply_promise', 'в течение рабочего дня')) ?></span></span>
            </a>
            <div style="display:flex;align-items:center;gap:14px">
              <span style="width:46px;height:46px;background:#0d0d0d;color:#FFC700;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FFC700" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-6.3 7-11a7 7 0 1 0-14 0c0 4.7 7 11 7 11Z"/><circle cx="12" cy="10" r="2.4"/></svg></span>
              <span><span style="display:block;font-family:'Oswald';font-weight:600;font-size:18px"><?= e($s['address_short']) ?></span><span style="font-size:13px;color:#5f5f5f"><?= e($s['hours_text']) ?></span></span>
            </div>
          </div>

          <div style="display:flex;gap:12px;flex-wrap:wrap">
<?php if ($s['whatsapp'] !== ''): ?>
            <a href="<?= e($s['whatsapp']) ?>" style="display:inline-flex;align-items:center;gap:8px;background:#1f1f1f;color:#fff;padding:13px 20px;font-weight:700;font-size:14px" style-hover="background:#FFC700;color:#0d0d0d">WhatsApp</a>
<?php endif ?>
<?php if ($s['telegram'] !== ''): ?>
            <a href="<?= e($s['telegram']) ?>" style="display:inline-flex;align-items:center;gap:8px;background:#1f1f1f;color:#fff;padding:13px 20px;font-weight:700;font-size:14px" style-hover="background:#FFC700;color:#0d0d0d">Telegram</a>
<?php endif ?>
          </div>
        </div>

        <div data-anim="slide-right" style="background:#0d0d0d;padding:40px;color:#fff">
          <div id="formSuccess" style="display:none">
            <div style="height:100%;min-height:360px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:14px">
              <div style="width:64px;height:64px;background:#FFC700;color:#0d0d0d;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:32px">✓</div>
              <h3 tabindex="-1" style="font-family:'Oswald';font-weight:600;font-size:26px;text-transform:uppercase"><?= e($s['form_ok_title']) ?></h3>
              <p style="color:#a8a8a8;font-size:15px;max-width:300px;line-height:1.6"><?= e($s['form_ok_text']) ?></p>
              <div style="width:100%;max-width:420px;margin-top:10px"><p style="font-weight:700;font-size:14px"><?= e($urgent) ?></p><?= $reach(true) ?></div>
            </div>
          </div>
          <div id="formBox">
            <form onsubmit="submitForm(event)">
              <h3 style="font-family:'Oswald';font-weight:600;font-size:24px;text-transform:uppercase;margin-bottom:8px"><?= e($s['form_title']) ?></h3>
              <p style="color:#9a9a9a;font-size:14px;margin-bottom:24px"><?= e($s['form_lead']) ?></p>
              <label for="fName">Ваше имя</label>
              <input id="fName" required autocomplete="name" style="width:100%;background:#161616;border:1px solid #2c2c2c;color:#fff;padding:14px 16px;font-size:16px;font-family:'Manrope';margin-bottom:14px" style-focus="border-color:#FFC700">
              <label for="fTel">Телефон</label>
              <input id="fTel" required type="tel" autocomplete="tel" inputmode="tel" style="width:100%;background:#161616;border:1px solid #2c2c2c;color:#fff;padding:14px 16px;font-size:16px;font-family:'Manrope';margin-bottom:14px" style-focus="border-color:#FFC700">
              <label for="fSvc">Услуга</label>
              <select id="fSvc" style="width:100%;background:#161616;border:1px solid #2c2c2c;color:#cfcfcf;padding:14px 16px;font-size:16px;font-family:'Manrope';margin-bottom:14px" style-focus="border-color:#FFC700">
<?php foreach ($services as $r): if ($r['in_form']): ?>
                <option<?= $r['href'] === $here ? ' selected' : '' ?>><?= e($r['title']) ?></option>
<?php endif; endforeach ?>
              </select>
              <label for="fMsg">Комментарий</label>
              <textarea id="fMsg" placeholder="Объём, адрес, сроки" rows="3" maxlength="1000" style="display:block;width:100%;background:#161616;border:1px solid #2c2c2c;color:#fff;padding:14px 16px;font-size:16px;font-family:'Manrope';margin-bottom:12px;resize:vertical" style-focus="border-color:#FFC700"></textarea>
              <label style="display:flex;align-items:center;gap:10px;min-height:44px;margin-bottom:12px;cursor:pointer">
                <input type="checkbox" required aria-label="Согласие на обработку персональных данных" style="margin:0;width:22px;height:22px;accent-color:#FFC700;flex-shrink:0">
                <span style="color:#8f8f8f;font-size:13px;line-height:1.45">Даю <a href="consent.php" target="_blank" style="text-decoration:underline">согласие на обработку персональных данных</a><span data-chint aria-live="polite" style="display:block;color:#FFC700;font-weight:700"></span></span>
              </label>
              <p class="pd-policy" style="margin:-4px 0 16px 32px;color:#8f8f8f;font-size:13px;line-height:1.45"><a href="privacy.php" target="_blank" style="text-decoration:underline">Политика обработки персональных данных</a></p>
              <input name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0">
              <?= $errBox(true) ?>

              <button type="submit" style="width:100%;background:#FFC700;color:#0d0d0d;border:none;font-family:'Manrope';font-weight:800;font-size:15px;padding:16px;cursor:pointer;text-transform:uppercase;letter-spacing:.4px" style-hover="background:#ffd633">Получить расчёт</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </section>
  </main>

  <!-- ================= FOOTER ================= -->
  <footer style="background:#0a0a0a;color:#9a9a9a;padding:54px 0 28px;border-top:3px solid #FFC700">
    <div style="max-width:1240px;margin:0 auto;padding:0 32px">
      <div data-r="foot" style="display:grid;grid-template-columns:1.4fr 1fr 1fr;gap:40px;padding-bottom:36px;border-bottom:1px solid #1c1c1c">
        <div>
          <div style="display:flex;align-items:center;gap:15px;margin-bottom:16px">
            <img src="assets/logo-rds.webp" alt="" style="width:54px;height:54px;object-fit:contain">
            <div style="font-family:'Oswald';font-weight:700;font-size:20px;color:#fff;letter-spacing:.06em">РЕГИОНДОРСТРОЙ</div>
          </div>
          <p style="font-size:14px;line-height:1.65;max-width:340px"><?= e($s['footer_about']) ?></p>
        </div>
        <div>
          <div style="font-family:'Oswald';font-weight:600;font-size:15px;color:#fff;text-transform:uppercase;letter-spacing:1px;margin-bottom:16px">Услуги</div>
          <div style="display:flex;flex-direction:column;gap:10px;font-size:14px">
<?php foreach ($services as $r): if ($r['in_footer']): ?>
            <a href="<?= e(!preg_match('~^/[a-z0-9-]+/$~D', $r['href']) || $live($r['href']) ? $r['href'] : '#services') ?>" style-hover="color:#FFC700"><?= e($r['title']) ?></a>
<?php endif; endforeach ?>
          </div>
        </div>
        <div>
          <div style="font-family:'Oswald';font-weight:600;font-size:15px;color:#fff;text-transform:uppercase;letter-spacing:1px;margin-bottom:16px">Контакты</div>
          <div style="display:flex;flex-direction:column;gap:10px;font-size:14px">
            <a href="tel:<?= e($s['phone_mobile']) ?>" style-hover="color:#FFC700"><?= e($s['phone_mobile_fmt']) ?></a>
            <a href="tel:<?= e($s['phone_city']) ?>" style-hover="color:#FFC700"><?= e($s['phone_city_fmt']) ?> (город)</a>
            <a href="mailto:<?= e($s['email']) ?>" style-hover="color:#FFC700"><?= e($s['email']) ?></a>
            <span><?= e($s['address_short']) ?></span>
            <span><?= e($s['hours_text']) ?></span>
          </div>
        </div>
      </div>
      <div style="padding-top:22px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;font-size:12.5px;color:#8f8f8f">
        <span><?= e($s['copyright']) ?></span>
        <span><?= e(implode(' · ', array_filter([$s['org_name'], ids($s, ' '), trim($s['org_address'])]))) ?></span>
        <span><a href="privacy.php" style-hover="color:#FFC700">Политика конфиденциальности</a> · <a href="consent.php" style-hover="color:#FFC700">Согласие на обработку ПДн</a><?php if (trim($s['metrika_id']) !== ''): ?> · <a href="#" onclick="cookieSettings();return false" style-hover="color:#FFC700">Настройки cookie</a><?php endif ?></span>
      </div>
    </div>
  </footer>

  <!-- ================= STICKY CONTACT BAR (≤768) ================= -->
  <nav aria-label="Связаться" data-cbar>
    <a href="tel:<?= e($s['phone_mobile']) ?>" style="background:#FFC700;color:#0d0d0d"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0d0d0d" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h3l2 5-2.2 1.3a12 12 0 0 0 5.7 5.7L16 15l5 2v3a2 2 0 0 1-2 2A17 17 0 0 1 4 5a2 2 0 0 1 2-2Z"/></svg>Позвонить</a>
<?php if ($s['whatsapp'] !== ''): ?>
    <a href="<?= e($waHi) ?>" style="background:#1f1f1f;color:#fff"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FFC700" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20l1.4-4.1A8 8 0 1 1 8.3 18.7Z"/></svg>WhatsApp</a>
<?php endif ?>
    <a href="<?= e($here) ?>#contacts" role="button" aria-haspopup="dialog" onclick="openCalc();return false" onkeydown="if(event.key===' '){event.preventDefault();openCalc()}" style="background:#1f1f1f;color:#fff"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FFC700" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 3h6v3H9z"/><path d="M15 4.5h2a2 2 0 0 1 2 2V19a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6.5a2 2 0 0 1 2-2h2M9 11h6M9 15h4"/></svg>Заявка</a>
  </nav>

  <!-- ================= CALL MODAL ================= -->
  <div id="modalOverlay" style="display:none">
    <div onclick="closeModal()" style="position:fixed;inset:0;z-index:100;background:rgba(0,0,0,.7);display:flex;align-items:center;justify-content:center;padding:24px;overflow-y:auto">
      <div role="dialog" aria-modal="true" aria-labelledby="modalTitle" onclick="event.stopPropagation()" style="background:#fff;max-width:440px;width:100%;padding:0;position:relative;margin:auto">
        <div style="height:10px;background-image:repeating-linear-gradient(45deg,#FFC700 0 16px,#0d0d0d 16px 32px)"></div>
        <button type="button" onclick="closeModal()" aria-label="Закрыть" style="position:absolute;top:12px;right:6px;width:44px;height:44px;display:flex;align-items:center;justify-content:center;background:none;border:none;font-size:24px;cursor:pointer;color:#6a6a6a;line-height:1">×</button>
        <div style="padding:36px 36px 40px">
          <div id="modalSuccess" style="display:none">
            <div style="text-align:center;padding:20px 0">
              <div style="width:60px;height:60px;background:#FFC700;color:#0d0d0d;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:30px;margin:0 auto 16px">✓</div>
              <h3 tabindex="-1" style="font-family:'Oswald';font-weight:600;font-size:24px;text-transform:uppercase;margin-bottom:8px"><?= e($s['modal_ok_title']) ?></h3>
              <p style="color:#6a6a6a;font-size:15px;line-height:1.55"><?= e($s['modal_ok_text']) ?></p>
              <p style="margin-top:22px;font-weight:700;font-size:14px;color:#111"><?= e($urgent) ?></p>
              <?= $reach(false) ?>

              <button type="button" onclick="closeModal()" style="margin-top:8px;width:100%;min-height:44px;background:none;border:1px solid #d6d6d2;color:#3a3a3a;font-family:'Manrope';font-weight:700;font-size:14px;cursor:pointer;text-transform:uppercase;letter-spacing:.3px" style-hover="border-color:#0d0d0d">Закрыть</button>
            </div>
          </div>
          <div id="modalForm">
            <h3 style="font-family:'Oswald';font-weight:600;font-size:26px;text-transform:uppercase;margin-bottom:6px"><span id="modalTitle"><?= e($s['modal_call_title']) ?></span></h3>
            <p style="color:#6a6a6a;font-size:14px;margin-bottom:18px"><span id="modalDesc"><?= e($s['modal_call_text']) ?></span></p>
            <div id="modalMat" style="display:none">
              <div style="background:#f4f4f2;border-left:3px solid #FFC700;padding:11px 14px;margin-bottom:18px;font-size:14px;font-weight:700"><span id="modalMatLbl">Материал</span>: <span id="modalMatName"></span></div>
            </div>
            <form onsubmit="submitModal(event)">
              <label for="mName">Ваше имя</label>
              <input id="mName" required autocomplete="name" style="width:100%;background:#f4f4f2;border:1px solid #e2e2de;color:#111;padding:14px 16px;font-size:16px;font-family:'Manrope';margin-bottom:14px" style-focus="border-color:#FFC700">
              <label for="mTel">Телефон</label>
              <input id="mTel" required type="tel" autocomplete="tel" inputmode="tel" style="width:100%;background:#f4f4f2;border:1px solid #e2e2de;color:#111;padding:14px 16px;font-size:16px;font-family:'Manrope';margin-bottom:14px" style-focus="border-color:#FFC700">
              <div id="modalSvc" style="display:none">
                <label for="mSvc">Услуга</label>
                <select id="mSvc" style="width:100%;background:#f4f4f2;border:1px solid #e2e2de;color:#111;padding:14px 16px;font-size:16px;font-family:'Manrope';margin-bottom:14px" style-focus="border-color:#FFC700">
<?php foreach ($services as $r): if ($r['in_form']): ?>
                  <option<?= $r['href'] === $here ? ' selected' : '' ?>><?= e($r['title']) ?></option>
<?php endif; endforeach ?>
                </select>
              </div>
              <div id="modalTask" style="display:none">
                <fieldset data-when><legend>Когда нужно</legend><div>
                  <label><input type="radio" name="when" value="сегодня"><span>Сегодня</span></label>
                  <label><input type="radio" name="when" value="завтра"><span>Завтра</span></label>
                  <label><input type="radio" name="when" value="позже"><span>Позже</span></label>
                </div></fieldset>
                <label for="mMsg" id="modalTaskLbl">Объём и куда везти</label>
                <textarea id="mMsg" rows="3" maxlength="880" style="display:block;width:100%;background:#f4f4f2;border:1px solid #e2e2de;color:#111;padding:14px 16px;font-size:16px;font-family:'Manrope';margin-bottom:12px;resize:vertical" style-focus="border-color:#FFC700"></textarea>
              </div>
              <label style="display:flex;align-items:center;gap:10px;min-height:44px;margin-bottom:12px;cursor:pointer">
                <input type="checkbox" required aria-label="Согласие на обработку персональных данных" style="margin:0;width:22px;height:22px;accent-color:#FFC700;flex-shrink:0">
                <span style="color:#6a6a6a;font-size:13px;line-height:1.45">Даю <a href="consent.php" target="_blank" style="text-decoration:underline">согласие на обработку персональных данных</a><span data-chint aria-live="polite" style="display:block;color:#b00020;font-weight:700"></span></span>
              </label>
              <p class="pd-policy" style="margin:-4px 0 16px 32px;color:#6a6a6a;font-size:13px;line-height:1.45"><a href="privacy.php" target="_blank" style="text-decoration:underline">Политика обработки персональных данных</a></p>
              <input name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0">
              <?= $errBox(false) ?>

              <button type="submit" style="width:100%;background:#0d0d0d;color:#fff;border:none;font-family:'Manrope';font-weight:800;font-size:15px;padding:16px;cursor:pointer;text-transform:uppercase;letter-spacing:.4px" style-hover="background:#FFC700;color:#0d0d0d"><span id="modalBtn"><?= e($s['modal_call_btn']) ?></span></button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>

  </div>

<script>
(function(){
  // style-hover / style-focus (design used inline reactive styles)
  function shim(attr, on, off){
    document.querySelectorAll('['+attr+']').forEach(function(el){
      var base=el.getAttribute('style')||'', ex=el.getAttribute(attr);
      el.addEventListener(on, function(){ el.setAttribute('style', base+';'+ex); });
      el.addEventListener(off, function(){ el.setAttribute('style', base); });
    });
  }
  // hover только там, где он есть: на сенсорном экране mouseenter без mouseleave оставлял «наведённый» вид
  if(!window.matchMedia || matchMedia('(hover:hover)').matches) shim('style-hover','mouseenter','mouseleave');
  shim('style-focus','focusin','focusout');

  // state: mobile menu + modal + forms
  var menu=document.getElementById('mobileMenu'),
      overlay=document.getElementById('modalOverlay'),
      mForm=document.getElementById('modalForm'),
      mOk=document.getElementById('modalSuccess'),
      mMat=document.getElementById('modalMat');
  window.toggleMenu=function(){ if(menu) menu.style.display=(menu.style.display==='none'?'block':'none'); cbarSync(); };
  window.closeMenu=function(){ if(menu) menu.style.display='none'; cbarSync(); };
  // липкая панель: прячется, пока CTA первого экрана не пройден, пока на экране #contacts и пока открыто окно или меню; без IO — видна всегда
  var cbar=document.querySelector('[data-cbar]'), cin={};
  function cbarSync(){ if(cbar) cbar.toggleAttribute('data-off', !!(cin.cta || cin.ct || (overlay&&overlay.style.display==='block') || (menu&&menu.style.display==='block'))); }
  if(cbar && 'IntersectionObserver' in window){
    var cta=document.querySelector('[data-herowrap] a[href$="#contacts"]'), ct=document.getElementById('contacts');
    cin.cta=!!cta; cbarSync(); // до первого ответа IO считаем CTA видимым — без мигания панели при загрузке
    // CTA: зона наблюдения продлена вниз без конца — «на экране или ниже» (320×640: CTA ниже первого экрана); выход из неё = CTA пройден,
    // и событие приходит даже при прыжке по якорю через весь hero (без продления прыжок «ниже → выше» IO не замечает)
    var io1=function(k,m){ return new IntersectionObserver(function(es){ cin[k]=es[es.length-1].isIntersecting; cbarSync(); }, {rootMargin:m}); };
    if(cta) io1('cta','0px 0px 100000px 0px').observe(cta); if(ct) io1('ct','0px').observe(ct);
  }
  function setModal(t,d,b,mat,svc,unit){
    var T=document.getElementById('modalTitle'),D=document.getElementById('modalDesc'),B=document.getElementById('modalBtn');
    if(T)T.textContent=t; if(D)D.textContent=d; if(B)B.textContent=b;
    if(mMat){ mMat.setAttribute('data-svc',svc||''); var L=document.getElementById('modalMatLbl'); if(L)L.textContent=svc||'Материал';
      if(mat){ mMat.style.display=''; var n=document.getElementById('modalMatName'); if(n)n.textContent=mat; } else mMat.style.display='none'; }
    var gen=mat===undefined, S=document.getElementById('modalSvc'); if(S)S.style.display=gen?'':'none'; // openCalc() без позиции («Заявка» панели): услуга — в поле
    var X=document.getElementById('modalTask'); if(X)X.style.display=(mat||gen)?'block':'none'; // чипы и поле задачи — только в расчёте/аренде
    var TL=document.getElementById('modalTaskLbl'); if(TL)TL.textContent=gen?'Объём, адрес, сроки':svc?'Куда подать технику и на сколько смен или часов':(/^(тонн[аы]?|т)$/i.test(unit)?'Сколько тонн':/^(м³|м3|куб.*)$/i.test(unit)?'Сколько кубов':'Объём')+' и куда везти'; // единицы сида: тонна, м³
  }
  // окно — модальный диалог: фон inert (все соседи по пути от окна до body), фокус в первое поле, при закрытии — назад на открывашку
  var opener=null;
  function bg(on){ for(var n=overlay;n&&n!==document.body;n=n.parentElement) [].forEach.call(n.parentElement.children, function(x){ if(x!==n) x.inert=on; }); }
  function show(){ if(!overlay)return; if(overlay.style.display!=='block')opener=document.activeElement; overlay.style.display='block'; bg(true);
    if(mForm){ mForm.style.display=''; var f=mForm.querySelector('form'); if(f){ clearErr(f); f.querySelector('[autocomplete=name]').focus(); } } if(mOk)mOk.style.display='none'; document.body.style.overflow='hidden'; cbarSync(); }
  window.openModal=function(){ setModal('<?= jsstr($s['modal_call_title']) ?>','<?= jsstr($s['modal_call_text']) ?>','<?= jsstr($s['modal_call_btn']) ?>',null); show(); };
  window.openModalMobile=function(){ closeMenu(); window.openModal(); };
  // заголовок/подводка (и кнопка аренды) по режиму: 0 — «Заявка» без позиции (как нижняя форма), 1 — аренда (svc задан), 2 — материал
  window.openCalc=function(name,svc,unit){ var m=name===undefined?0:svc?1:2;
    setModal(['<?= jsstr($s['form_title']) ?>','<?= jsstr(stub($s, 'modal_rent_title', 'Заказать технику')) ?>','<?= jsstr($s['modal_calc_title']) ?>'][m],
      ['<?= jsstr($s['form_lead']) ?>','<?= jsstr(stub($s, 'modal_rent_text', 'Отметьте, когда нужна машина, и напишите адрес. Перезвоним и уточним время подачи.')) ?>','<?= jsstr($s['modal_calc_text']) ?>'][m],
      m===1?'<?= jsstr(stub($s, 'modal_rent_btn', 'Отправить заявку')) ?>':'<?= jsstr($s['modal_calc_btn']) ?>',name,svc,unit); show(); };
  // карточка техники: та же форма расчёта, услуга «Аренда спецтехники» + название из заголовка карточки
  window.openRent=function(btn){ var h=btn.closest('[data-lift]').querySelector('h3'); window.openCalc(h?h.textContent:'','Аренда спецтехники'); };
<?php if ($pg): ?>
  // «Другие услуги» посадочной: окно «Заявка» с этой услугой в поле; услуги нет в списке формы (in_form=0) — остаётся услуга страницы
  window.openSvc=function(t){ window.openCalc(); var S=document.getElementById('mSvc'); if(S&&[].some.call(S.options,function(o){ return o.text===t; }))S.value=t; };
<?php endif ?>
  window.closeModal=function(){ if(!overlay||overlay.style.display!=='block')return; overlay.style.display='none'; bg(false); document.body.style.overflow=''; cbarSync();
    var o=opener; opener=null; if(o&&!o.getClientRects().length)o=document.querySelector('[data-burger]'); // открывашка из мобильного меню скрыта — фокус на бургер
    if(o&&o.focus)o.focus(); };
  // галочка согласия: своя русская подсказка рядом (aria-live) и в нативном сообщении; снимается при отметке
  var HINT='Поставьте галочку согласия';
  function cbox(b, show){ b.setCustomValidity(b.checked?'':HINT); var h=b.closest('label').querySelector('[data-chint]'); if(h&&(b.checked||show))h.textContent=b.checked?'':HINT; }
  document.querySelectorAll('form input[type=checkbox][required]').forEach(function(b){ cbox(b);
    b.addEventListener('change', function(){ cbox(b); }); b.addEventListener('invalid', function(){ cbox(b, true); }); });
  // блок ошибки над кнопкой (role=alert): текст по коду — из data-атрибута (печатает сервер), в DOM только через textContent;
  // invalid — подсказка и фокус на телефон; rate/server/offline — ещё «Позвонить» и WhatsApp. Введённое в форме не трогаем
  function showErr(form, code){
    var b=form.querySelector('[data-err]'); if(!b) return;
    b.setAttribute('data-code', code);
    b.querySelector('[data-msg]').textContent=b.getAttribute('data-'+(code==='invalid'||code==='offline'?code:'fail'));
    b.querySelector('[data-conn]').hidden=code==='invalid';
    b.hidden=false;
    if(code==='invalid'){ var t=form.querySelector('[autocomplete=tel]'); if(t){ t.setAttribute('aria-invalid','true'); t.focus(); } }
    else if(b.scrollIntoView) b.scrollIntoView({block:'nearest'});
    window.goal&&goal('lead_error',{code:code});
  }
  function clearErr(form){ var b=form.querySelector('[data-err]'), t=form.querySelector('[autocomplete=tel]'); if(b)b.hidden=true; if(t)t.removeAttribute('aria-invalid'); }
  // заявка уходит только на свой сервер (send.php); без галочки согласия и без 10 цифр в номере запрос не отправляется.
  // g — цель Метрики (lead_*): только при ok===true и номере заявки n (без n — honeypot, send.php:25, цель не шлётся)
  function send(fd, form, g, onOk){
    var box=form.querySelector('input[type=checkbox]');
    if(!box||!box.checked){ if(box){ cbox(box, true); box.focus(); } return; }
    clearErr(form);
    if(val(form,'[autocomplete=tel]').replace(/\D/g,'').length<10) return showErr(form,'invalid');
    var hp=form.querySelector('[name=website]');
    fd.append('consent','1'); fd.append('website', hp?hp.value:'');
    var btn=form.querySelector('button[type=submit]'), old=btn?btn.innerHTML:'';
    function reset(){ if(btn){ btn.disabled=false; btn.style.opacity=''; btn.innerHTML=old; } }
    if(btn){ btn.disabled=true; btn.style.opacity='.6'; btn.textContent='Отправляем…'; }
    // обрыв сети → offline; затем статус (429 → rate, 5xx → server); затем JSON (invalid; не JSON и прочее → server)
    fetch('send.php',{ method:'POST', body:fd }).then(function(r){
      if(r.status===429) return 'rate';
      if(r.status>=500) return 'server';
      return r.json().then(function(d){ return d&&d.ok===true ? d : (d&&d.error==='invalid' ? 'invalid' : 'server'); }, function(){ return 'server'; });
    }, function(){ return 'offline'; }).then(function(d){
      reset();
      // ошибка: фокус возвращается на кнопку отправки (на время отправки она disabled, фокус падал на body и выходил из окна);
      // текст ошибки читалка объявит сама (role=alert), «Позвонить»/WhatsApp — сразу перед кнопкой (Shift+Tab), Enter — повторить
      if(typeof d==='string'){ showErr(form, d); if(btn&&!form.contains(document.activeElement))btn.focus(); return; } // фокус после показа блока — кнопка под ним остаётся в экране
      if(d.n) window.goal&&goal(g);
      try{ form.reset(); cbox(box); }catch(_){} onOk();
    });
  }
  function val(f,sel){ var el=f.querySelector(sel); return el?el.value.trim():''; }
  window.submitModal=function(e){ e.preventDefault(); var f=e.target, fd=new FormData();
    var mEl=document.getElementById('modalMat'), mat=(mEl&&mEl.style.display!=='none')?(((document.getElementById('modalMatName')||{}).textContent)||''):'';
    var svc=(mEl&&mat&&mEl.getAttribute('data-svc'))||'', gen=document.getElementById('modalSvc').style.display!=='none', task=mat||gen;
    // comment: «Техника: X» (аренда) → «Когда: завтра» (чип) → текст; пустые строки не добавляются
    var w=task&&f.querySelector('[name=when]:checked'), note=[svc?'Техника: '+mat:'', w?'Когда: '+w.value:'', task?val(f,'#modalTask textarea'):''].filter(Boolean).join('\n');
    if(svc||gen){ fd.append('source','calc'); fd.append('service',svc||val(f,'#mSvc')); fd.append('comment',note); }
    else { fd.append('source', mat?'material':'call'); fd.append('material', mat); if(note)fd.append('comment', note); }
    fd.append('name', val(f,'[autocomplete=name]')); fd.append('phone', val(f,'[autocomplete=tel]'));
    send(fd, f, svc?'lead_rent':gen?'lead_calc':mat?'lead_material':'lead_call', function(){ if(mForm)mForm.style.display='none'; if(mOk){ mOk.style.display=''; mOk.querySelector('h3').focus(); } });
  };
  window.submitForm=function(e){ e.preventDefault(); var f=e.target, fd=new FormData();
    fd.append('source','calc'); fd.append('name', val(f,'[autocomplete=name]')); fd.append('phone', val(f,'[autocomplete=tel]'));
    fd.append('service', val(f,'select')); fd.append('comment', val(f,'textarea'));
    send(fd, f, 'lead_calc', function(){ var box=document.getElementById('formBox'),ok=document.getElementById('formSuccess'); if(box)box.style.display='none'; if(ok){ ok.style.display=''; ok.querySelector('h3').focus(); } });
  };
  // цели «нажали на телефон / WhatsApp»: where — id ближайшего блока, иначе область страницы (header, aside — верхняя полоса, nav — липкая панель, footer, main)
  document.addEventListener('click', function(e){
    var a=window.goal&&e.target.closest&&e.target.closest('a[href^="tel:"],a[href*="wa.me"]'); if(!a) return;
    var w=a.closest('[id],header,footer,aside,nav,main');
    goal(a.getAttribute('href').indexOf('tel:')===0?'click_tel':'click_whatsapp', {where:w?(w.id||w.tagName.toLowerCase()):''});
  });
  // окно открыто: Esc закрывает; Tab/Shift+Tab ходят по кругу внутри окна
  document.addEventListener('keydown', function(e){
    if(!overlay||overlay.style.display!=='block') return;
    if(e.key==='Escape') return window.closeModal();
    if(e.key!=='Tab') return;
    var box=overlay.querySelector('[role=dialog]'), a=document.activeElement,
        q=[].filter.call(box.querySelectorAll('a[href],button,input,select,textarea'), function(x){ return x.tabIndex>=0 && !x.disabled && x.getClientRects().length; });
    if(q.length && (!box.contains(a) || a===q[e.shiftKey?0:q.length-1])){ e.preventDefault(); q[e.shiftKey?q.length-1:0].focus(); }
  });

  // count-up + scroll-progress. ponytail: scroll-reveal убран (2026-10-28) — [data-anim] никогда не прячется,
  // карточки видны сразу; при reduced-motion/без IntersectionObserver счётчики просто показывают итог
  document.addEventListener('DOMContentLoaded', function(){
    var reduce=window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function runCount(span){ if(span.__c)return; span.__c=true; var to=parseInt(span.getAttribute('data-to'),10)||0, sfx=span.getAttribute('data-suffix')||'', dur=1100, t0=performance.now();
      function tick(now){ var p=Math.min(1,(now-t0)/dur), e=1-Math.pow(1-p,3); span.textContent=Math.round(e*to)+sfx; if(p<1)requestAnimationFrame(tick); } requestAnimationFrame(tick); }
    var bar=document.querySelector('[data-progress]');
    if(bar){ var maxH=0,ticking=false;
      function measure(){ maxH=document.documentElement.scrollHeight-window.innerHeight; }
      function paint(){ ticking=false; bar.style.transform='scaleX('+(maxH>0?Math.min(1,window.scrollY/maxH):0)+')'; }
      function onScroll(){ if(!ticking){ ticking=true; requestAnimationFrame(paint); } }
      measure(); paint(); window.addEventListener('scroll',onScroll,{passive:true}); window.addEventListener('resize',function(){ measure(); paint(); },{passive:true}); }
    if(reduce || !('IntersectionObserver' in window)) return;
    var io=new IntersectionObserver(function(ents){ ents.forEach(function(e){ if(e.isIntersecting){ io.unobserve(e.target); runCount(e.target); } }); }, {threshold:0.15});
    Array.prototype.slice.call(document.querySelectorAll('[data-count]')).forEach(function(c){ io.observe(c); });
  });
})();
</script>
<?php if (trim($s['metrika_id']) !== ''): ?>
<div id="cookie-bar" hidden style="position:fixed;left:0;right:0;bottom:0;z-index:90;background:#0d0d0d;color:#cfcfcf;border-top:3px solid #FFC700;padding:16px 20px;font-family:'Manrope';font-size:13.5px;line-height:1.5">
  <div style="max-width:1240px;margin:0 auto;display:flex;flex-wrap:wrap;align-items:center;gap:12px 20px">
    <p style="flex:1 1 260px;min-width:0">Сайт может использовать cookie и сервис Яндекс Метрика для анализа посещаемости — только если вы нажмёте «Принять». Подробнее — в <a href="privacy.php" style="color:#FFC700;text-decoration:underline">политике конфиденциальности</a>.</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button type="button" id="cookie-accept" style="background:#FFC700;color:#0d0d0d;border:none;font-family:'Manrope';font-weight:800;font-size:13.5px;padding:11px 22px;cursor:pointer;text-transform:uppercase;letter-spacing:.4px">Принять</button>
      <button type="button" id="cookie-reject" style="background:#FFC700;color:#0d0d0d;border:none;font-family:'Manrope';font-weight:800;font-size:13.5px;padding:11px 22px;cursor:pointer;text-transform:uppercase;letter-spacing:.4px">Отклонить</button>
    </div>
  </div>
</div>
<script>
(function(){
  // Метрика грузится только после «Принять»; выбор хранится в localStorage (mtk = выбор:редакция текста)
  var ID=<?= (int)$s['metrika_id'] ?>, REV='<?= jsstr($s['cookie_text_rev']) ?>', K='mtk', bar=document.getElementById('cookie-bar'), c=null;
  function loadMetrika(){
    window.ym=window.ym||function(){ (window.ym.a=window.ym.a||[]).push(arguments); }; window.ym.l=1*new Date();
    var t=document.createElement('script'); t.defer=true; t.src='https://mc.yandex.ru/metrika/tag.js'; document.head.appendChild(t);
    window.ym(ID,'init',{webvisor:false,clickmap:true,accurateTrackBounce:true});
    window.goal=function(n,p){ if(window.ym) ym(ID,'reachGoal',n,p); }; // цели — только после «Принять»; до согласия и без metrika_id goal нет, вызовы молчат
  }
  function off(){ window['disableYaCounter'+ID]=true; }
  function choose(v){
    try{ localStorage.setItem(K, v+':'+REV); }catch(_){}
    bar.hidden=true;
    try{ navigator.sendBeacon('consent.php','c='+v+'&rev='+encodeURIComponent(REV)); }catch(_){}
    if(v==='accept') loadMetrika(); else off();
  }
  window.cookieSettings=function(){ try{ localStorage.removeItem(K); }catch(_){} location.reload(); };
  try{ c=localStorage.getItem(K); }catch(_){}
  if(c==='accept:'+REV) loadMetrika();
  else if(c==='reject:'+REV) off();
  else bar.hidden=false;
  document.getElementById('cookie-accept').addEventListener('click', function(){ choose('accept'); });
  document.getElementById('cookie-reject').addEventListener('click', function(){ choose('reject'); });
})();
</script>
<?php endif ?>
</body>
</html>
