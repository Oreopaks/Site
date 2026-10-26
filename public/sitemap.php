<?php
// P10: в sitemap только главная (privacy/consent — noindex). Отдаётся и как /sitemap.xml (rewrite/router).
require dirname(__DIR__) . '/app/bootstrap.php';
header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc><?= e(settings()['base_url']) ?></loc>
    <changefreq>monthly</changefreq>
    <priority>1.0</priority>
  </url>
</urlset>
