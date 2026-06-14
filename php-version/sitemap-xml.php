<?php
// Dynamic XML sitemap for Google + Bing Search Console (served at /sitemap.xml via router.php).
// Uses the image extension namespace (xmlns:image) so Googlebot-Image can crawl
// every product image directly from the sitemap — accelerates Google Images / Lens
// discovery for newly added products.  Real `<lastmod>` per row uses the
// `updated_at` column when present so crawlers only re-crawl changed pages.
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

$base  = rtrim(site_url(), '/');
$today = date('Y-m-d');
$urls  = [];

// Core pages (these don't carry a `updated_at` — use today's date).
foreach ([
    ['/', '1.0', 'daily'],
    ['/shop.php', '0.9', 'daily'],
    ['/reviews.php', '0.7', 'weekly'],
    ['/blog.php', '0.7', 'weekly'],
    ['/about-us.php', '0.6', 'monthly'],
    ['/why-choose-us.php', '0.6', 'monthly'],
    ['/affiliate.php', '0.6', 'monthly'],
    ['/contact.php', '0.6', 'monthly'],
    ['/support.php', '0.6', 'monthly'],
    ['/returns.php', '0.5', 'monthly'],
    ['/sitemap.php', '0.4', 'monthly'],
    ['/order-history.php', '0.5', 'monthly'],
] as [$path, $pri, $freq]) {
    $urls[] = ['loc' => $base . $path, 'lastmod' => $today, 'freq' => $freq, 'pri' => $pri, 'images' => []];
}

// Categories (nav families + platform variants)
$catSlugs = ['office', 'office-pc', 'office-mac', 'office-2024-pc', 'office-2024-mac', 'office-2021-pc', 'office-2021-mac',
    'office-2019-pc', 'office-2019-mac', 'windows', 'windows-11', 'windows-10', 'project', 'visio', 'servers',
    'antivirus', 'bitdefender', 'mcafee'];
foreach ($catSlugs as $cs) {
    $urls[] = ['loc' => $base . '/category.php?slug=' . $cs, 'lastmod' => $today, 'freq' => 'weekly', 'pri' => '0.8', 'images' => []];
}

// Products — use real updated_at when present and emit the image URL so
// Google Images / Lens / Bing visual search can index it from the sitemap.
$prodCols = ['slug', 'name', 'image'];
try {
    $has = db()->query("SHOW COLUMNS FROM products LIKE 'updated_at'")->fetch();
    if ($has) $prodCols[] = 'updated_at';
} catch (Throwable $e) {}
$colsSql = implode(',', $prodCols);
foreach (db()->query("SELECT $colsSql FROM products WHERE is_active = 1 AND " . active_regions_sql_in('region')) as $r) {
    $lm = $today;
    if (!empty($r['updated_at'])) {
        $lm = substr((string)$r['updated_at'], 0, 10);
    }
    $imgRaw = trim((string)($r['image'] ?? ''));
    $imgAbs = $imgRaw === '' ? '' : (preg_match('#^https?://#i', $imgRaw) ? $imgRaw : $base . '/' . ltrim($imgRaw, '/'));
    $urls[] = [
        'loc'     => $base . '/product.php?slug=' . $r['slug'],
        'lastmod' => $lm,
        'freq'    => 'weekly',
        'pri'     => '0.8',
        'images'  => $imgAbs ? [['loc' => $imgAbs, 'title' => trim((string)$r['name'])]] : [],
    ];
}

// Blog posts
foreach (db()->query('SELECT id, date FROM blog_posts') as $r) {
    $urls[] = [
        'loc'     => $base . '/blog-post.php?id=' . $r['id'],
        'lastmod' => substr((string)$r['date'], 0, 10) ?: $today,
        'freq'    => 'monthly',
        'pri'     => '0.6',
        'images'  => [],
    ];
}

// Content / legal pages
foreach (db()->query('SELECT slug FROM pages') as $r) {
    $urls[] = ['loc' => $base . '/page.php?slug=' . $r['slug'], 'lastmod' => $today, 'freq' => 'monthly', 'pri' => '0.4', 'images' => []];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
echo '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
foreach ($urls as $u) {
    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($u['loc'], ENT_XML1) . "</loc>\n";
    echo "    <lastmod>" . $u['lastmod'] . "</lastmod>\n";
    echo "    <changefreq>" . $u['freq'] . "</changefreq>\n";
    echo "    <priority>" . $u['pri'] . "</priority>\n";
    foreach ($u['images'] as $img) {
        echo "    <image:image>\n";
        echo "      <image:loc>"   . htmlspecialchars($img['loc'], ENT_XML1) . "</image:loc>\n";
        if (!empty($img['title'])) {
            echo "      <image:title>" . htmlspecialchars($img['title'], ENT_XML1) . "</image:title>\n";
        }
        echo "    </image:image>\n";
    }
    echo "  </url>\n";
}
echo '</urlset>';
