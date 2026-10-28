<?php
// P10: в sitemap главная и опубликованные посадочные (privacy/consent — noindex); без lastmod — честной даты изменения нет (ADR-0009 §5). Отдаётся и как /sitemap.xml (rewrite/router).
require dirname(__DIR__) . '/app/bootstrap.php';
$s = settings();
header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc><?= e($s['base_url']) ?></loc>
    <changefreq>monthly</changefreq>
    <priority>1.0</priority>
  </url>
<?php foreach (PAGES as $id => $p): if (page_gaps($s, $id)) continue ?>
  <url>
    <loc><?= e($s['base_url'] . $p['slug'] . '/') ?></loc>
    <changefreq>monthly</changefreq>
    <priority>0.8</priority>
  </url>
<?php endforeach ?>
</urlset>
