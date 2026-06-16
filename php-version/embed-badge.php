<?php
/* ===========================================================================
 *  EMBEDDABLE BADGE WIDGET  —  /embed/badge.js
 *  ---------------------------------------------------------------------------
 *  Returns a tiny, dependency-free JavaScript snippet that injects a styled
 *  "Buy from Maventech" badge on any third-party site.  Bloggers, affiliates
 *  and review sites can paste a single <script> tag and the badge appears in
 *  place — every visible badge contains a `<a href>` back to us, which means
 *  every install is a real, crawler-discoverable BACKLINK.
 *
 *  Usage on a partner page (HTML):
 *     <script src="https://yourdomain.com/embed/badge.js"
 *             data-product="microsoft-office-home-business-2024-pc"
 *             data-label="Buy Office 2024"
 *             data-theme="dark"
 *             async></script>
 *
 *  Attributes:
 *     data-product : product slug (optional — omit for a generic "Shop" link)
 *     data-label   : badge headline override (optional)
 *     data-theme   : 'dark' (default) or 'light'
 *
 *  The script self-locates its inject point (the <script> tag itself) and
 *  builds the badge DOM right next to it so the publisher controls where it
 *  appears (no popups, no iframes, no cookies — fully GDPR-friendly).
 *  =========================================================================== */

require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/javascript; charset=UTF-8');
header('Access-Control-Allow-Origin: *'); // allow cross-origin embed
header('Cache-Control: public, max-age=3600'); // 1h CDN cache

$site = rtrim(site_url(), '/');
$brand = function_exists('company_info') ? (company_info()['name'] ?? 'Maventech Software') : 'Maventech Software';

/* Pull the freshest deal info so the badge reflects current promo % off. */
$topDealPct = 0;
try {
    $row = db()->query("SELECT MAX(ROUND(100*(original_price - price)/original_price)) AS pct
                        FROM products WHERE original_price IS NOT NULL AND original_price > price")->fetch();
    if ($row && $row['pct']) $topDealPct = (int)$row['pct'];
} catch (Throwable $e) {}

/* Inject site + brand into JS template literals via json_encode for safety. */
$siteJs  = json_encode($site,  JSON_UNESCAPED_SLASHES);
$brandJs = json_encode($brand);
$pctJs   = (int)$topDealPct;
?>
/* Maventech embeddable badge — v1
 * Source: <?= $site ?>/embed/badge.js
 * Drop this <script> on your site to display a styled "Buy now" badge that
 * links back to <?= htmlspecialchars($brand, ENT_QUOTES, 'UTF-8') ?>.
 */
(function () {
  'use strict';
  var SITE  = <?= $siteJs ?>;
  var BRAND = <?= $brandJs ?>;
  var TOP_PCT = <?= $pctJs ?>;

  // Locate the script tag that loaded us so we know where to inject the badge.
  var me = document.currentScript ||
           (function () {
             var ss = document.getElementsByTagName('script');
             return ss[ss.length - 1];
           })();
  if (!me) return;
  if (me.dataset._mv_done) return; me.dataset._mv_done = '1';

  var product = (me.dataset.product || '').trim();
  var label   = (me.dataset.label   || '').trim();
  var theme   = (me.dataset.theme   || 'dark').toLowerCase();
  var width   = (me.dataset.width   || '').trim();

  // Build href back to us with UTM so the SEO source is attributable.
  var ref = '';
  try { ref = encodeURIComponent(location.hostname || 'embed'); } catch (e) {}
  var dest = product
    ? SITE + '/product.php?slug=' + encodeURIComponent(product)
    : SITE + '/shop.php';
  dest += (dest.indexOf('?') === -1 ? '?' : '&')
       + 'utm_source=badge&utm_medium=embed&utm_campaign=' + ref;

  var headline = label ||
    (TOP_PCT > 0 ? ('Save up to ' + TOP_PCT + '% on genuine software')
                 : 'Buy genuine software keys');

  var dark = (theme !== 'light');
  var styles = (
    '.mv-badge{display:inline-flex;align-items:center;gap:12px;padding:14px 18px;' +
    'border-radius:14px;text-decoration:none;font-family:-apple-system,BlinkMacSystemFont,' +
    '"Segoe UI",Roboto,sans-serif;line-height:1.2;border:1px solid;' +
    'box-shadow:0 2px 14px rgba(15,23,42,.08);transition:transform .18s ease,box-shadow .18s ease;' +
    'max-width:' + (width || '340px') + ';}' +
    '.mv-badge:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(15,23,42,.16);}' +
    '.mv-badge[data-th="dark"]{background:#0b1220;color:#fff;border-color:#1d2944;}' +
    '.mv-badge[data-th="light"]{background:#fff;color:#0b1220;border-color:#e2e8f0;}' +
    '.mv-badge__logo{width:38px;height:38px;border-radius:10px;display:inline-flex;align-items:center;' +
    'justify-content:center;font-weight:800;font-size:18px;flex:0 0 auto;' +
    'background:linear-gradient(135deg,#2563eb,#0ea5e9);color:#fff;}' +
    '.mv-badge__body{flex:1;min-width:0;}' +
    '.mv-badge__brand{font-size:11px;letter-spacing:.6px;text-transform:uppercase;opacity:.7;}' +
    '.mv-badge__title{font-size:14px;font-weight:700;}' +
    '.mv-badge__cta{font-size:11px;font-weight:600;color:#22c55e;margin-top:2px;}' +
    '.mv-badge__arrow{font-size:18px;opacity:.65;}'
  );

  // Inject the stylesheet once per page.
  if (!document.getElementById('mv-badge-style')) {
    var s = document.createElement('style');
    s.id = 'mv-badge-style';
    s.textContent = styles;
    document.head.appendChild(s);
  }

  var letter = (BRAND && BRAND.charAt(0).toUpperCase()) || 'M';
  var html =
    '<a class="mv-badge" data-th="' + (dark ? 'dark' : 'light') + '"' +
    ' href="' + dest + '" target="_blank" rel="noopener sponsored"' +
    ' aria-label="Buy genuine software from ' + BRAND + '">' +
      '<span class="mv-badge__logo" aria-hidden="true">' + letter + '</span>' +
      '<span class="mv-badge__body">' +
        '<span class="mv-badge__brand">' + BRAND + '</span>' +
        '<span class="mv-badge__title">' + headline + '</span>' +
        '<span class="mv-badge__cta">Genuine licenses · Instant delivery</span>' +
      '</span>' +
      '<span class="mv-badge__arrow" aria-hidden="true">›</span>' +
    '</a>';

  var wrap = document.createElement('span');
  wrap.innerHTML = html;
  // Insert just before the loader <script> so the publisher controls position.
  if (me.parentNode) me.parentNode.insertBefore(wrap.firstChild, me);
})();
