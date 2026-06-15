<?php
/**
 * Rewrite every gosoftwarebuy.com image URL to its locally-generated
 * /uploads/products/<slug>.png counterpart.
 *
 * Updates:
 *   - products.image            (37 rows)
 *   - blog_posts.image          (142 rows; uses the same source URLs)
 *   - email_outbox.html         (any historical message body — safe to rewrite
 *                                so re-renders / archive views also stop
 *                                hitting the external host)
 *   - email_templates.html      (the order delivery + review templates)
 *
 * Reads the URL → internal-path mapping from /tmp/img_remap_done.json.
 */
require_once '/app/php-version/includes/functions.php';

$mapPath = '/tmp/img_remap_done.json';
if (!file_exists($mapPath)) {
    fwrite(STDERR, "Missing $mapPath — run generate_product_images.py first.\n");
    exit(1);
}
$map = json_decode((string)file_get_contents($mapPath), true);
if (!is_array($map) || !$map) {
    fwrite(STDERR, "Empty/invalid map in $mapPath\n");
    exit(1);
}

$pdo = db();
$stats = ['products' => 0, 'blog_posts' => 0, 'email_outbox' => 0, 'email_templates' => 0];

// 1) products.image — direct equality match
foreach ($map as $src => $dst) {
    $n = $pdo->prepare('UPDATE products SET image = ? WHERE image = ?');
    $n->execute([$dst, $src]);
    $stats['products'] += $n->rowCount();
}

// 2) blog_posts.image — same direct equality match
foreach ($map as $src => $dst) {
    $n = $pdo->prepare('UPDATE blog_posts SET image = ? WHERE image = ?');
    $n->execute([$dst, $src]);
    $stats['blog_posts'] += $n->rowCount();
}

// 3) email_outbox.html — body-substring replace via REPLACE()
foreach ($map as $src => $dst) {
    $n = $pdo->prepare('UPDATE email_outbox SET html = REPLACE(html, ?, ?) WHERE html LIKE ?');
    $n->execute([$src, $dst, '%' . $src . '%']);
    $stats['email_outbox'] += $n->rowCount();
}

// 4) email_templates.html — same body-substring replace
try {
    foreach ($map as $src => $dst) {
        $n = $pdo->prepare('UPDATE email_templates SET html = REPLACE(html, ?, ?) WHERE html LIKE ?');
        $n->execute([$src, $dst, '%' . $src . '%']);
        $stats['email_templates'] += $n->rowCount();
    }
} catch (Throwable $e) { /* table may not have html col on older installs */ }

// 5) Safety sweep — any other product row still pointing at gosoftwarebuy.com
//    (e.g. cloned products) gets blanked so the storefront falls back to
//    the silhouette placeholder.
$leftover = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE image LIKE '%gosoftwarebuy.com%'")->fetchColumn();
if ($leftover > 0) {
    $pdo->exec("UPDATE products SET image = '' WHERE image LIKE '%gosoftwarebuy.com%'");
}

echo "Update summary:\n";
foreach ($stats as $t => $c) echo "  $t: $c row(s) updated\n";
echo "  leftover product rows blanked: $leftover\n";

// 6) Final sanity check
foreach (['products', 'blog_posts', 'email_outbox', 'email_templates'] as $t) {
    try {
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM $t WHERE COALESCE(" . ($t === 'products' || $t === 'blog_posts' ? 'image' : 'html') . ", '') LIKE '%gosoftwarebuy.com%'")->fetchColumn();
        echo "  remaining gosoftwarebuy refs in $t: $cnt\n";
    } catch (Throwable $e) {}
}
