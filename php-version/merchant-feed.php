<?php
/**
 * /merchant-feed.xml — Google Merchant Center / Bing Shopping feed.
 *
 * Google + Bing + Meta all consume RSS 2.0 with the `g:` namespace
 * (Google Shopping schema).  This single endpoint covers:
 *   - Google Merchant Center (Shopping ads + free listings)
 *   - Bing Shopping (Microsoft Advertising)
 *   - Facebook / Meta Catalog (same field names)
 *
 * Fields emitted per item:
 *   id, title, description, link, image_link, availability,
 *   price, sale_price (when discounted), brand, mpn, identifier_exists,
 *   condition, product_type, google_product_category, shipping (free
 *   digital download), shipping_weight, custom_label_0..2.
 *
 * Cached publicly for 1 hour so crawlers don't beat the DB.  Listed as
 * a Sitemap in robots.txt so Bing + Google discover it automatically.
 */
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
header('X-Robots-Tag: noindex, nofollow'); // the feed itself shouldn't be indexed

$site    = rtrim(site_url(), '/');
$ci      = company_info();
$brand   = $ci['name'] ?? (defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software');
$updated = gmdate('D, d M Y H:i:s') . ' GMT';
$linkRss = $site . '/merchant-feed.xml';

// Google product taxonomy mapper — uses the public English-US taxonomy
// (https://www.google.com/basepages/producttype/taxonomy.en-US.txt).
// Check the MOST SPECIFIC brand keywords first; "windows" appears in many
// Office titles (e.g. "Office 2024 for Windows") so we must hit "office"
// before the generic OS bucket.
function _gpc_for_category(string $hint): string {
    $h = strtolower($hint);
    if (str_contains($h, 'office') || str_contains($h, 'project') || str_contains($h, 'visio')) {
        return 'Software > Business & Productivity Software';
    }
    if (str_contains($h, 'antivirus') || str_contains($h, 'bitdefender') || str_contains($h, 'mcafee')
        || str_contains($h, 'norton') || str_contains($h, 'kaspersky') || str_contains($h, 'eset')
        || str_contains($h, 'webroot') || str_contains($h, 'avast') || str_contains($h, 'avg')) {
        return 'Software > Antivirus & Security Software';
    }
    if (str_contains($h, 'autocad') || str_contains($h, 'autodesk')) {
        return 'Software > Computer Software > Compilers & Programming Tools';
    }
    if (str_contains($h, 'adobe') || str_contains($h, 'acrobat')) {
        return 'Software > Business & Productivity Software';
    }
    if (str_contains($h, 'windows') || str_contains($h, 'server')) {
        return 'Software > Operating Systems';
    }
    return 'Software > Business & Productivity Software';
}

// Brand inference — fall back to the product name when DB brand is empty.
function _brand_from(string $explicit, string $name): string {
    if ($explicit !== '') return $explicit;
    if (stripos($name, 'bitdefender') !== false) return 'Bitdefender';
    if (stripos($name, 'mcafee') !== false)      return 'McAfee';
    if (stripos($name, 'norton') !== false)      return 'Norton';
    if (stripos($name, 'kaspersky') !== false)   return 'Kaspersky';
    if (stripos($name, 'eset') !== false)        return 'ESET';
    if (stripos($name, 'webroot') !== false)     return 'Webroot';
    if (stripos($name, 'avast') !== false)       return 'Avast';
    if (stripos($name, 'autocad') !== false || stripos($name, 'autodesk') !== false) return 'Autodesk';
    if (stripos($name, 'adobe') !== false || stripos($name, 'acrobat') !== false)    return 'Adobe';
    return 'Microsoft';
}

// XML-safe escape (round-trips UTF-8 cleanly).
function feed_xml_esc(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

// Currency + ISO country code per region.
$currencyByRegion = ['US' => 'USD', 'UK' => 'GBP', 'EU' => 'EUR', 'CA' => 'CAD', 'AU' => 'AUD', 'IN' => 'INR', 'AE' => 'AED'];
$countryByRegion  = ['US' => 'US',  'UK' => 'GB',  'EU' => 'DE',  'CA' => 'CA',  'AU' => 'AU',  'IN' => 'IN',  'AE' => 'AE'];

// Pull every active product in the regions currently switched on by the admin.
$pdo = db();
$products = $pdo->query(
    "SELECT id, slug, name, price, original_price, region, image, category,
            brand, badge, license_type, version, year, description, sku, platform
       FROM products
      WHERE is_active = 1 AND " . active_regions_sql_in('region') . "
      ORDER BY id ASC"
)->fetchAll();

// Pre-compute availability counts in one query (faster than per-item lookups).
$availCounts = [];
foreach ($pdo->query("SELECT product_slug, COUNT(*) c FROM license_keys WHERE status='available' GROUP BY product_slug") as $r) {
    $availCounts[$r['product_slug']] = (int)$r['c'];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
echo "  <channel>\n";
echo "    <title>" . feed_xml_esc($brand) . " — Software Product Feed</title>\n";
echo "    <link>" . feed_xml_esc($site) . "</link>\n";
echo "    <atom:link href=\"" . feed_xml_esc($linkRss) . "\" rel=\"self\" type=\"application/rss+xml\"/>\n";
echo "    <description>Genuine digital license keys delivered instantly by email — Microsoft Office, Windows, Bitdefender, Norton, McAfee, Adobe and more. " . feed_xml_esc($brand) . " is an authorised reseller.</description>\n";
echo "    <language>en-US</language>\n";
echo "    <lastBuildDate>" . feed_xml_esc($updated) . "</lastBuildDate>\n";

foreach ($products as $p) {
    $region   = strtoupper((string)($p['region'] ?: 'US'));
    $currency = $currencyByRegion[$region] ?? 'USD';
    $country  = $countryByRegion[$region] ?? 'US';

    $price    = number_format((float)$p['price'], 2, '.', '');
    $orig     = (float)($p['original_price'] ?? 0);
    $hasSale  = $orig > (float)$p['price'];

    $title    = trim((string)$p['name']);
    $brandPi  = _brand_from(trim((string)$p['brand']), $title);
    $catHint  = ((string)($p['category'] ?? '')) . ' ' . $title;
    $gpc      = _gpc_for_category($catHint);

    // Image must be absolute URL.  If relative, prepend the canonical host.
    $imageRaw = trim((string)$p['image']);
    $imageAbs = $imageRaw === '' ? '' : (preg_match('#^https?://#i', $imageRaw) ? $imageRaw : $site . '/' . ltrim($imageRaw, '/'));

    // Description — DB value if present, else a high-conviction synthesised
    // line that still mentions brand + product + delivery promise.
    $descRaw = trim((string)($p['description'] ?? ''));
    if ($descRaw === '') {
        $descRaw = sprintf(
            'Genuine %s license key for %s%s. Digital delivery by email within seconds of payment confirmation. Lifetime activation, 24/7 support and 30-day money-back guarantee — sold by %s, an authorised software reseller.',
            $brandPi,
            $title,
            $p['version'] ? ' ' . $p['version'] : '',
            $brand
        );
    }
    if (strlen($descRaw) > 5000) $descRaw = substr($descRaw, 0, 4997) . '...'; // Google limit

    $availability = (($availCounts[$p['slug']] ?? 0) > 0) ? 'in_stock' : 'out_of_stock';
    $productLink  = $site . '/product.php?slug=' . urlencode((string)$p['slug']);

    echo "    <item>\n";
    echo "      <g:id>"            . feed_xml_esc((string)$p['id']) . "</g:id>\n";
    echo "      <g:title>"         . feed_xml_esc($title) . "</g:title>\n";
    echo "      <g:description>"   . feed_xml_esc($descRaw) . "</g:description>\n";
    echo "      <g:link>"          . feed_xml_esc($productLink) . "</g:link>\n";
    if ($imageAbs !== '') {
        echo "      <g:image_link>" . feed_xml_esc($imageAbs) . "</g:image_link>\n";
    }
    echo "      <g:availability>"  . $availability . "</g:availability>\n";
    if ($hasSale) {
        echo "      <g:price>"     . feed_xml_esc(number_format($orig, 2, '.', '') . ' ' . $currency) . "</g:price>\n";
        echo "      <g:sale_price>" . feed_xml_esc($price . ' ' . $currency) . "</g:sale_price>\n";
    } else {
        echo "      <g:price>"     . feed_xml_esc($price . ' ' . $currency) . "</g:price>\n";
    }
    echo "      <g:brand>"         . feed_xml_esc($brandPi) . "</g:brand>\n";
    echo "      <g:mpn>"           . feed_xml_esc((string)($p['sku'] ?: $p['slug'])) . "</g:mpn>\n";
    echo "      <g:identifier_exists>no</g:identifier_exists>\n"; // digital download — no GTIN
    echo "      <g:condition>new</g:condition>\n";
    echo "      <g:product_type>"  . feed_xml_esc($gpc) . "</g:product_type>\n";
    echo "      <g:google_product_category>" . feed_xml_esc($gpc) . "</g:google_product_category>\n";
    // Digital download — instant delivery is always free.
    echo "      <g:shipping>\n";
    echo "        <g:country>"     . $country . "</g:country>\n";
    echo "        <g:service>Digital download (instant by email)</g:service>\n";
    echo "        <g:price>0.00 "  . $currency . "</g:price>\n";
    echo "      </g:shipping>\n";
    echo "      <g:shipping_weight>0 kg</g:shipping_weight>\n";
    // Custom labels — let the merchant slice campaigns by brand / region / badge.
    echo "      <g:custom_label_0>" . feed_xml_esc($brandPi) . "</g:custom_label_0>\n";
    echo "      <g:custom_label_1>" . feed_xml_esc($region) . "</g:custom_label_1>\n";
    if (!empty($p['badge'])) {
        echo "      <g:custom_label_2>" . feed_xml_esc((string)$p['badge']) . "</g:custom_label_2>\n";
    }
    echo "    </item>\n";
}

echo "  </channel>\n";
echo "</rss>\n";
