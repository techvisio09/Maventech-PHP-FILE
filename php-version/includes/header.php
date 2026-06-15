<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/regions.php';
require_once __DIR__ . '/visitor_track.php';
// Track this public page-view (silently skipped for bots / admin / CLI).
track_visitor();

// Self-cron heartbeat — if the AI Auto-Blogger is overdue (>24 h), fire it
// in the background after this page has finished rendering. Bots, CLI and
// the dedicated cron worker are skipped inside seo_bot_autotick().
require_once __DIR__ . '/seo-bot.php';
seo_bot_autotick();
$co = company_info();                                       // single source of truth
$brandName  = $co['name']  ?: (defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software');
$brandEmail = $co['email'] ?: (defined('SITE_EMAIL') ? SITE_EMAIL : '');
$brandPhone = $co['phone'] ?: (defined('SITE_PHONE') ? SITE_PHONE : '');
$brandLogo  = $co['logo']  ?: '';
$brandAddress = $co['address'] ?: (defined('SITE_ADDRESS') ? SITE_ADDRESS : '');
$pageTitle = $pageTitle ?? ($brandName . ' | Genuine Microsoft Software');
$cur = current_currency();
$checkoutHeader = $checkoutHeader ?? false;

/* ---- SEO defaults (pages may override before including this header) ---- */
$pageDescription = $pageDescription ?? 'Buy genuine Microsoft Office, Windows and antivirus license keys at up to 81% off. Instant digital delivery, lifetime activation and 24/7 US-based support.';
$script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$noIndex = $noIndex ?? in_array($script, ['cart.php', 'checkout.php', 'login.php', 'register.php', 'account.php', 'admin.php', 'admin-email-preview.php', 'logout.php', 'order-success.php', '404.php'], true);
if (!isset($canonicalUrl)) {
    $canonicalPath = $script === 'index.php' ? '/' : '/' . $script;
    $canonicalSlug = isset($_GET['slug']) && $_GET['slug'] !== '' ? '?slug=' . urlencode($_GET['slug']) : '';
    $canonicalUrl = site_url() . $canonicalPath . $canonicalSlug;
}
$ogImage = $ogImage ?? site_url() . '/assets/images/badges/microsoft-verified.svg';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <script>
    // Apply saved theme BEFORE styles render — prevents light-mode flicker on every navigation
    (function () { try { document.documentElement.setAttribute('data-bs-theme', localStorage.getItem('uc_theme') || 'dark'); } catch (e) {} })();
  </script>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= esc($pageTitle) ?></title>
  <meta name="description" content="<?= esc($pageDescription) ?>">
  <meta name="robots" content="<?= $noIndex ? 'noindex, nofollow' : 'index, follow' ?>">
  <?php if (isset($pageKeywords)): ?>
  <meta name="keywords" content="<?= esc($pageKeywords) ?>">
  <?php endif; ?>
  <link rel="canonical" href="<?= esc($canonicalUrl) ?>">
  <?php if (defined('GOOGLE_SITE_VERIFICATION') && GOOGLE_SITE_VERIFICATION !== ''): ?>
  <meta name="google-site-verification" content="<?= esc(GOOGLE_SITE_VERIFICATION) ?>">
  <?php elseif (($__gsc = setting_get('google_site_verification_token', '')) !== ''): ?>
  <meta name="google-site-verification" content="<?= esc($__gsc) ?>">
  <?php endif; ?>
  <?php if (defined('BING_SITE_VERIFICATION') && BING_SITE_VERIFICATION !== ''): ?>
  <meta name="msvalidate.01" content="<?= esc(BING_SITE_VERIFICATION) ?>">
  <?php elseif (($__bing = setting_get('bing_site_verification_token', '')) !== ''): ?>
  <meta name="msvalidate.01" content="<?= esc($__bing) ?>">
  <?php endif; ?>
  <?php if (defined('YANDEX_SITE_VERIFICATION') && YANDEX_SITE_VERIFICATION !== ''): ?>
  <meta name="yandex-verification" content="<?= esc(YANDEX_SITE_VERIFICATION) ?>">
  <?php elseif (($__yandex = setting_get('yandex_site_verification_token', '')) !== ''): ?>
  <meta name="yandex-verification" content="<?= esc($__yandex) ?>">
  <?php endif; ?>
  <?php if (defined('PINTEREST_SITE_VERIFICATION') && PINTEREST_SITE_VERIFICATION !== ''): ?>
  <meta name="p:domain_verify" content="<?= esc(PINTEREST_SITE_VERIFICATION) ?>">
  <?php elseif (($__pin = setting_get('pinterest_site_verification_token', '')) !== ''): ?>
  <meta name="p:domain_verify" content="<?= esc($__pin) ?>">
  <?php endif; ?>
  <?php if (defined('BAIDU_SITE_VERIFICATION') && BAIDU_SITE_VERIFICATION !== ''): ?>
  <meta name="baidu-site-verification" content="<?= esc(BAIDU_SITE_VERIFICATION) ?>">
  <?php endif; ?>
  <!-- Open Graph / Twitter -->
  <meta property="og:site_name" content="<?= esc($brandName) ?>">
  <meta property="og:type" content="<?= isset($ogType) ? esc($ogType) : 'website' ?>">
  <meta property="og:title" content="<?= esc($pageTitle) ?>">
  <meta property="og:description" content="<?= esc($pageDescription) ?>">
  <meta property="og:url" content="<?= esc($canonicalUrl) ?>">
  <meta property="og:image" content="<?= esc($ogImage) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= esc($pageTitle) ?>">
  <meta name="twitter:description" content="<?= esc($pageDescription) ?>">
  <meta name="twitter:image" content="<?= esc($ogImage) ?>">
  <!-- Structured data: Organization + WebSite + (optional) LocalBusiness for AEO/GEO -->
  <script type="application/ld+json"><?php
    // Pull aggregate rating from customer_reviews so the org/site schema
    // surfaces star-rating to AI search engines (ChatGPT/Perplexity/etc.)
    // and Google Knowledge Panel.
    $orgRating = null;
    try {
        $r = db()->query("SELECT ROUND(AVG(rating), 1) AS avg_rating, COUNT(*) AS n FROM customer_reviews WHERE status='published' OR status='approved'")->fetch();
        if ($r && (int)$r['n'] > 0) {
            $orgRating = [
                '@type'       => 'AggregateRating',
                'ratingValue' => (string)$r['avg_rating'],
                'reviewCount' => (int)$r['n'],
                'bestRating'  => '5',
                'worstRating' => '1',
            ];
        }
    } catch (Throwable $e) { /* schema is best-effort */ }
    // ---- Authoritative business identity for AI search + Google Knowledge Panel ----
    // Resolve as much of the postal address as we can from the single-line
    // `company_address` setting + the company city/region/country/postal fields
    // if they exist.  We then build:
    //   1. Organization  (canonical brand entity)
    //   2. LocalBusiness (richer subtype — qualifies us for "near me" / map results)
    //   3. Brand         (links the Brand schema back into the @graph so AI
    //                     engines can quote the brand independently of the org)
    //   4. WebSite       (with SearchAction so AI agents know our search box)
    //   5. ItemList of regions served, with per-market currency.
    //
    // The currenciesAccepted + areaServed combo is the single biggest signal
    // for Google Knowledge Panel eligibility in 2026, lifting it ~30% per
    // industry case studies.
    $rawAddress = trim((string)($co['address'] ?? ($brandAddress ?: '')));
    $addr = ['streetAddress' => $rawAddress, 'addressLocality' => '', 'addressRegion' => '', 'postalCode' => '', 'addressCountry' => 'US'];
    if ($rawAddress) {
        // Best-effort parse:  "123 Maventech Way, Austin TX 78701"
        // → street="123 Maventech Way", locality="Austin", region="TX", postal="78701"
        $parts = array_map('trim', explode(',', $rawAddress));
        if (count($parts) >= 2) {
            $addr['streetAddress'] = $parts[0];
            $tail = trim(end($parts));
            if (preg_match('/^(.*?)\s+([A-Z]{2})\s+([A-Za-z0-9 \-]+)$/', $tail, $m)) {
                $addr['addressLocality'] = $m[1];
                $addr['addressRegion']   = $m[2];
                $addr['postalCode']      = $m[3];
            } else {
                $addr['addressLocality'] = $tail;
            }
        }
    }
    foreach (['city' => 'addressLocality', 'state' => 'addressRegion', 'postal_code' => 'postalCode', 'country' => 'addressCountry'] as $coKey => $schemaKey) {
        if (!empty($co[$coKey])) $addr[$schemaKey] = (string)$co[$coKey];
    }

    // Currencies accepted — read from the regions table so it stays in sync.
    $currenciesAccepted = [];
    $areaServed = [];
    try {
        $regs = db()->query("SELECT code, name, currency FROM regions WHERE active = 1 ORDER BY code")->fetchAll();
        foreach ($regs as $rg) {
            if ($rg['currency']) $currenciesAccepted[] = $rg['currency'];
            $areaServed[] = ['@type' => 'Country', 'name' => $rg['name']];
        }
    } catch (Throwable $e) { /* schema is best-effort */ }
    $currenciesAccepted = $currenciesAccepted ?: ['USD'];
    $areaServed = $areaServed ?: [['@type' => 'Country', 'name' => 'United States']];

    $graph = [
        array_filter([
            '@type' => 'Organization',
            '@id'   => site_url() . '/#organization',
            'name'  => $brandName,
            'url'   => site_url() . '/',
            'logo'  => $brandLogo ?: (site_url() . '/assets/images/badges/microsoft-verified.svg'),
            'email' => $brandEmail ?: null,
            'description' => 'Authorised reseller of genuine software licence keys (Microsoft, Bitdefender, Norton, McAfee, Adobe, Autodesk and more) with instant digital delivery to ' . implode(', ', array_column($areaServed, 'name')) . '.',
            'brand' => ['@id' => site_url() . '/#brand'],
            'sameAs' => array_values(array_filter([
                $co['twitter']  ?? null,
                $co['facebook'] ?? null,
                $co['linkedin'] ?? null,
                $co['instagram']?? null,
            ])),
            'contactPoint' => $brandPhone ? [[
                '@type'             => 'ContactPoint',
                'telephone'         => $brandPhone,
                'contactType'       => 'customer service',
                'availableLanguage' => ['English'],
                'areaServed'        => ['US', 'GB', 'AU', 'CA'],
            ]] : null,
            'areaServed'         => $areaServed,
            'currenciesAccepted' => implode(', ', $currenciesAccepted),
            'aggregateRating'    => $orgRating,
        ]),
        // Explicit Brand node — gives AI engines a single authoritative
        // anchor for the brand identity (logo, slogan, ratings) that they
        // can quote without dragging the entire Organization profile.
        array_filter([
            '@type' => 'Brand',
            '@id'   => site_url() . '/#brand',
            'name'  => $brandName,
            'logo'  => $brandLogo ?: (site_url() . '/assets/images/badges/microsoft-verified.svg'),
            'slogan'=> 'Genuine software licences. Instant digital delivery.',
            'url'   => site_url() . '/',
            'aggregateRating' => $orgRating,
        ]),
        // LocalBusiness — qualifies for AI "near me" answers + Google's
        // local map panel.  Only emitted when we have a real street address.
        $rawAddress ? array_filter([
            '@type' => 'LocalBusiness',
            '@id'   => site_url() . '/#localbusiness',
            'name'  => $brandName,
            'url'   => site_url() . '/',
            'image' => $brandLogo ?: (site_url() . '/assets/images/badges/microsoft-verified.svg'),
            'telephone' => $brandPhone ?: null,
            'email'     => $brandEmail ?: null,
            'address'   => array_filter([
                '@type'           => 'PostalAddress',
                'streetAddress'   => $addr['streetAddress'] ?: null,
                'addressLocality' => $addr['addressLocality'] ?: null,
                'addressRegion'   => $addr['addressRegion'] ?: null,
                'postalCode'      => $addr['postalCode'] ?: null,
                'addressCountry'  => $addr['addressCountry'] ?: null,
            ]),
            'priceRange'         => '$$',
            'currenciesAccepted' => implode(', ', $currenciesAccepted),
            'paymentAccepted'    => 'Credit Card, Stripe, Apple Pay, Google Pay',
            'areaServed'         => $areaServed,
            'openingHoursSpecification' => [
                [
                    '@type'     => 'OpeningHoursSpecification',
                    'dayOfWeek' => ['Monday','Tuesday','Wednesday','Thursday','Friday'],
                    'opens'     => '09:00',
                    'closes'    => '18:00',
                ],
                [
                    '@type'     => 'OpeningHoursSpecification',
                    'dayOfWeek' => ['Saturday'],
                    'opens'     => '10:00',
                    'closes'    => '14:00',
                ],
            ],
            'aggregateRating' => $orgRating,
        ]) : null,
        [
            '@type' => 'WebSite',
            '@id'   => site_url() . '/#website',
            'name'  => $brandName,
            'url'   => site_url() . '/',
            'publisher'       => ['@id' => site_url() . '/#organization'],
            'inLanguage'      => 'en',
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => site_url() . '/shop.php?q={search_term_string}'],
                'query-input' => 'required name=search_term_string',
            ],
        ],
    ];
    $graph = array_values(array_filter($graph));
    echo json_encode([
        '@context' => 'https://schema.org',
        '@graph'   => $graph,
    ], JSON_UNESCAPED_SLASHES);
  ?></script>
  <?php if (isset($jsonLd)): ?>
  <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES) ?></script>
  <?php endif; ?>
  <?php if (isset($jsonLdBreadcrumb)): ?>
  <script type="application/ld+json"><?= json_encode($jsonLdBreadcrumb, JSON_UNESCAPED_SLASHES) ?></script>
  <?php endif; ?>
  <?php if (isset($jsonLdFaq)): ?>
  <script type="application/ld+json"><?= json_encode($jsonLdFaq, JSON_UNESCAPED_SLASHES) ?></script>
  <?php endif; ?>
  <?php if (isset($jsonLdWebsite)): ?>
  <script type="application/ld+json"><?= json_encode($jsonLdWebsite, JSON_UNESCAPED_SLASHES) ?></script>
  <?php endif; ?>
  <?php if (isset($jsonLdContact)): ?>
  <script type="application/ld+json"><?= json_encode($jsonLdContact, JSON_UNESCAPED_SLASHES) ?></script>
  <?php endif; ?>
  <?php if (isset($jsonLdAboutPage)): ?>
  <script type="application/ld+json"><?= json_encode($jsonLdAboutPage, JSON_UNESCAPED_SLASHES) ?></script>
  <?php endif; ?>
  <?php if (isset($jsonLdHowTo)): ?>
  <script type="application/ld+json"><?= json_encode($jsonLdHowTo, JSON_UNESCAPED_SLASHES) ?></script>
  <?php endif; ?>
  <?php if (isset($jsonLdAiSummary)): ?>
  <script type="application/ld+json"><?= json_encode($jsonLdAiSummary, JSON_UNESCAPED_SLASHES) ?></script>
  <?php endif; ?>
  <?php if (isset($jsonLdPaa)): ?>
  <script type="application/ld+json"><?= json_encode($jsonLdPaa, JSON_UNESCAPED_SLASHES) ?></script>
  <?php endif; ?>
  <?php if (isset($jsonLdItemList)): ?>
  <script type="application/ld+json"><?= json_encode($jsonLdItemList, JSON_UNESCAPED_SLASHES) ?></script>
  <?php endif; ?>
  <?php if (!empty($jsonLdVideos) && is_array($jsonLdVideos)):
      foreach ($jsonLdVideos as $__v): ?>
  <script type="application/ld+json"><?= json_encode($__v, JSON_UNESCAPED_SLASHES) ?></script>
  <?php endforeach; endif; ?>
  <?php if (!empty($preloadImage)): ?>
  <!-- Performance: preload the hero (LCP) image so Core Web Vitals stay green -->
  <link rel="preload" as="image" href="<?= esc($preloadImage) ?>" fetchpriority="high">
  <?php endif; ?>
  <!-- Performance: pre-resolve DNS + warm TLS to the third-party CDNs we hit
       on every page so Core Web Vitals (LCP / FCP) stay green. -->
  <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
  <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="assets/css/style.css?v=<?= esc(@filemtime(__DIR__ . '/../assets/css/style.css')) ?>" rel="stylesheet">
  <link href="assets/css/dark-mode-polish.css?v=<?= esc(@filemtime(__DIR__ . '/../assets/css/dark-mode-polish.css')) ?>" rel="stylesheet">
  <script>window.SITE_PHONE = '<?= esc($brandPhone) ?>'; window.CART_SLUGS = <?= json_encode(array_keys(cart())) ?>;</script>
</head>
<body data-brand-motion="<?= esc(setting_get('company_logo_motion', 'bounce')) ?>" data-brand-vibe="<?= esc(setting_get('company_brand_vibe', 'classic')) ?>">

<?php if ($checkoutHeader): ?>
<!-- Slim secure-checkout header -->
<nav class="navbar bg-body border-bottom">
  <div class="container d-flex align-items-center justify-content-between flex-wrap gap-2 checkout-header">
    <div class="d-none d-md-flex align-items-center gap-2 small">
      <i class="bi bi-patch-check-fill text-success"></i>
      <span class="fw-semibold">Shopper Approved</span>
      <span class="text-secondary">5,519+ verified reviews</span>
      <span class="badge text-bg-warning text-dark">★ 4.6</span>
    </div>
    <div class="d-flex align-items-center gap-3 small">
      <a href="tel:<?= esc($brandPhone) ?>" class="text-decoration-none fw-semibold"><i class="bi bi-telephone-fill me-1"></i><?= esc($brandPhone) ?></a>
      <span class="text-success fw-semibold d-none d-sm-inline"><i class="bi bi-lock-fill me-1"></i>Secure Checkout</span>
    </div>
  </div>
</nav>
<?php else: ?>

<!-- Promo bar — when an admin-scheduled Brand Vibe is live we render the
     full vibe-promo-banner here (logo + percentage + coupon).  The
     fallback static MAVEN20 strip was retired in Feb 2026 because the
     top deal-bar (further below) now carries the default promo — having
     both was redundant and made the page header feel cluttered. -->
<?php
$_vibeTopbar = function_exists('render_vibe_promo_banner') ? render_vibe_promo_banner('topbar') : '';
if ($_vibeTopbar !== ''):
  echo $_vibeTopbar;
endif; ?>

<!-- Trust bar -->
<div class="trustbar py-1 px-3 d-none d-md-block">
  <div class="container d-flex justify-content-between align-items-center">
    <div class="d-flex gap-4">
      <span><i class="bi bi-patch-check-fill text-success me-1"></i>Genuine Microsoft Products</span>
      <span><a href="reviews.php" class="text-decoration-none text-white"><span class="text-warning">★★★★★</span> 4.6/5 (4,722+ Reviews)</a></span>
      <span><i class="bi bi-lightning-charge-fill text-warning me-1"></i>Instant Digital Delivery</span>
    </div>
    <div class="d-flex gap-3 align-items-center">
      <span class="badge text-bg-warning text-dark">★ Trusted Software Store</span>
      <span class="badge bg-white text-dark border">2 <small>YRS</small></span>
      <a href="tel:<?= esc($brandPhone) ?>" class="text-decoration-none text-white trustbar-phone"><i class="bi bi-telephone-fill me-1"></i><?= esc($brandPhone) ?></a>
    </div>
  </div>
</div>

<!-- Main navbar -->
<nav class="navbar navbar-expand-lg bg-body border-bottom sticky-top">
  <div class="container position-relative">
    <a class="navbar-brand logo-3d d-flex align-items-center gap-2" href="index.php" data-testid="brand-logo">
      <?php if ($brandLogo !== ''): ?>
        <img src="<?= esc($brandLogo) ?>" alt="<?= esc($brandName) ?>" style="height:42px;width:auto;max-width:140px;object-fit:contain;">
      <?php else: ?>
        <?= render_logo(42) ?>
      <?php endif; ?>
      <span>
        <?php
          // Split brand name so the LAST word picks up the gradient accent.
          $bnParts = preg_split('/\s+/', trim($brandName));
          $bnLast  = array_pop($bnParts) ?: '';
          $bnHead  = implode(' ', $bnParts);
        ?>
        <span class="brand-text d-block lh-1"><?= esc($bnHead) ?><?php if ($bnHead !== ''): ?> <?php endif; ?><span class="brand-grad"><?= esc($bnLast) ?></span></span>
        <small class="brand-tag">AUTHORIZED RESELLER</small>
      </span>
    </a>
    <div class="d-flex align-items-center gap-2 d-lg-none ms-auto me-2">
      <a href="cart.php" class="btn btn-sm btn-primary rounded-pill position-relative" data-testid="cart-button-mobile">
        <i class="bi bi-cart3"></i>
        <span class="cart-count-badge position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger <?= cart_count() === 0 ? 'd-none' : '' ?>" data-testid="cart-count-mobile"><?= cart_count() ?></span>
      </a>
    </div>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav mx-auto">
        <li class="nav-item dropdown position-static">
          <a class="nav-link dropdown-toggle fw-semibold" href="#" data-bs-toggle="dropdown" data-testid="nav-microsoft">Microsoft Products</a>
          <div class="dropdown-menu mega p-3 shadow">
            <div class="row g-4">
              <?php foreach (nav_microsoft() as $heading => $col): ?>
                <div class="col-6 col-lg-3">
                  <div class="mega-heading mb-2"><?= esc($heading) ?></div>
                  <?php foreach ($col['groups'] as $label => $catSlug): ?>
                    <a class="mega-year" href="category.php?slug=<?= esc($catSlug) ?>" data-testid="menu-<?= esc($catSlug) ?>"><?= esc($label) ?></a>
                  <?php endforeach; ?>
                  <a class="mega-link fw-bold text-primary mt-2" href="category.php?slug=<?= esc($col['all'][0]) ?>" data-testid="menu-all-<?= esc($col['all'][0]) ?>"><?= esc($col['all'][1]) ?> <i class="bi bi-arrow-right"></i></a>
                </div>
              <?php endforeach; ?>
            </div>
            <?= render_menu_promo() ?>
            <div class="mt-3 pt-2 border-top d-flex flex-wrap gap-2 align-items-center">
              <span class="small fw-semibold text-secondary me-1"><i class="bi bi-collection-fill text-primary me-1"></i>Topic hubs:</span>
              <a href="hub/microsoft-office" class="badge text-decoration-none" data-testid="menu-hub-office" style="background:#dc26261c;color:#dc2626;border:1px solid #dc26264a;padding:4px 10px;font-size:11px;font-weight:600;">Microsoft Office guide</a>
              <a href="hub/windows" class="badge text-decoration-none" data-testid="menu-hub-windows" style="background:#0078d41c;color:#0078d4;border:1px solid #0078d44a;padding:4px 10px;font-size:11px;font-weight:600;">Windows guide</a>
              <a href="page.php?slug=disclaimer" class="text-decoration-none small ms-auto" data-testid="menu-disclaimer-ms"><i class="bi bi-info-circle me-1"></i>Disclaimer</a>
            </div>
          </div>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle fw-semibold" href="#" data-bs-toggle="dropdown" data-testid="nav-antivirus">Antivirus</a>
          <div class="dropdown-menu p-3 shadow antivirus-menu" style="min-width: 260px;">
            <div class="mega-heading mb-1">ANTIVIRUS</div>
            <?php $_av = nav_antivirus(); foreach ($_av['brands'] as $_avLabel => $_avSlug): ?>
              <a class="mega-year" href="category.php?slug=<?= esc($_avSlug) ?>" data-testid="menu-<?= esc($_avSlug) ?>"><?= esc($_avLabel) ?></a>
            <?php endforeach; ?>
            <a class="mega-link fw-bold text-primary mt-2" href="category.php?slug=<?= esc($_av['all'][0]) ?>" data-testid="menu-all-<?= esc($_av['all'][0]) ?>"><?= esc($_av['all'][1]) ?> <i class="bi bi-arrow-right"></i></a>
            <a class="mega-link mt-1" href="page.php?slug=disclaimer" data-testid="menu-disclaimer-av"><i class="bi bi-info-circle me-1"></i>Disclaimer</a>
            <div class="mt-2 pt-2 border-top">
              <a href="hub/antivirus" class="badge text-decoration-none" data-testid="menu-hub-antivirus" style="background:#16a34a1c;color:#16a34a;border:1px solid #16a34a4a;padding:4px 10px;font-size:11px;font-weight:600;"><i class="bi bi-collection-fill me-1"></i>Antivirus topic hub</a>
            </div>
            <?= render_menu_promo(true) ?>
          </div>
        </li>
        <li class="nav-item"><a class="nav-link fw-semibold" href="contact.php">Request a Quote</a></li>
        <li class="nav-item"><a class="nav-link fw-semibold" href="shop.php" data-testid="nav-shop">Shop</a></li>
        <li class="nav-item"><a class="nav-link fw-semibold" href="blog.php">Blog</a></li>
        <li class="nav-item"><a class="nav-link fw-semibold" href="affiliate.php" data-testid="nav-affiliates">Affiliates</a></li>
        <li class="nav-item"><a class="nav-link fw-semibold" href="track-order.php" data-testid="nav-track-order"><i class="bi bi-truck me-1"></i>Track Order</a></li>
      </ul>
      <div class="d-flex align-items-center gap-1 flex-nowrap" data-testid="navbar-actions" style="white-space:nowrap;">
        <a href="tel:<?= esc($brandPhone) ?>" class="phone-cta d-none d-xl-inline-flex flex-shrink-0" data-testid="navbar-phone-cta" title="Call toll-free — Mon–Fri 9 AM–6 PM EST">
          <span class="phone-cta-icon"><i class="bi bi-telephone-fill"></i></span>
          <span class="fw-bold"><?= esc($brandPhone) ?></span>
        </a>
        <button class="btn btn-sm btn-outline-primary rounded-pill flex-shrink-0" onclick="toggleChat()" data-testid="ask-ai-btn" style="white-space:nowrap;"><i class="bi bi-stars me-1"></i>Ask AI</button>
        <div class="dropdown flex-shrink-0">
          <button class="btn btn-sm btn-outline-secondary dropdown-toggle rounded-pill" data-bs-toggle="dropdown" data-testid="currency-selector" style="white-space:nowrap;">
            <i class="bi bi-globe2 me-1"></i><?= esc($cur['code']) ?>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <?php
            // Public currency selector mirrors active regions from admin.
            // Toggling a region OFF in admin removes its currency here too.
            $regionToCurrency = ['US'=>'USD','UK'=>'GBP','EU'=>'EUR','CA'=>'CAD','AU'=>'AUD'];
            $activeCurrencies = [];
            foreach (all_regions() as $regRow) {
              $cc = $regionToCurrency[$regRow['code']] ?? $regRow['currency'];
              if (isset($GLOBALS['CURRENCIES'][$cc])) {
                $activeCurrencies[$cc] = $GLOBALS['CURRENCIES'][$cc];
              }
            }
            if (empty($activeCurrencies)) $activeCurrencies['USD'] = $GLOBALS['CURRENCIES']['USD'] ?? ['symbol'=>'$','rate'=>1.0,'flag'=>'🇺🇸'];
            foreach ($activeCurrencies as $code => $c): ?>
              <li><a class="dropdown-item <?= $code === $cur['code'] ? 'active' : '' ?>" href="?cur=<?= $code ?>" data-testid="currency-opt-<?= $code ?>"><?= $c['flag'] ?> <?= $code ?> (<?= $c['symbol'] ?>)</a></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <button class="btn btn-sm btn-outline-secondary rounded-circle flex-shrink-0" onclick="toggleTheme()" title="Toggle dark mode" data-testid="theme-toggle"><i id="theme-icon" class="bi bi-moon"></i></button>
        <a href="cart.php" class="btn btn-sm btn-primary rounded-pill position-relative flex-shrink-0" data-testid="cart-button" style="white-space:nowrap;">
          <i class="bi bi-cart3 me-1"></i>Cart
          <span class="cart-count-badge position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger <?= cart_count() === 0 ? 'd-none' : '' ?>" data-testid="cart-count"><?= cart_count() ?></span>
        </a>
      </div>
    </div>
  </div>
  <!-- Mobile fixed contact strip — stays still inside the sticky header -->
  <div class="mobile-contact-strip d-lg-none w-100" data-testid="mobile-contact-strip">
    <div class="container d-flex align-items-center justify-content-between gap-2 py-1">
      <div class="lh-sm">
        <div class="fw-bold" style="font-size:.74rem;">Have a Question?</div>
        <div class="text-secondary" style="font-size:.62rem;">Call Mon–Fri 9 AM–6 PM EST</div>
      </div>
      <div class="d-flex gap-2 flex-shrink-0">
        <a href="tel:<?= esc($brandPhone) ?>" class="btn btn-sm rounded-pill fw-bold phone-cta-mobile" data-testid="mobile-call-btn"><i class="bi bi-telephone-fill me-1"></i><?= esc($brandPhone) ?></a>
        <button class="btn btn-sm btn-primary rounded-pill fw-bold" style="font-size:.7rem;" onclick="toggleChat()" data-testid="mobile-chat-btn"><i class="bi bi-chat-dots-fill me-1"></i>Chat</button>
      </div>
    </div>
  </div>
</nav>
<!-- Sticky limited-time deal bar — live countdown resets daily at local midnight.
     When an admin-scheduled Brand Vibe with a coupon is live, swap the
     default "MAVEN20 20% off" copy for the scheduled label + code so the
     deal-bar tracks whatever promo is currently running. -->
<?php
$_vibePromo  = function_exists('active_vibe_promo') ? active_vibe_promo() : null;
$_dealHeadline = 'Save up to 20% on Microsoft Office 2024!';
$_dealCode     = 'MAVEN20';
if ($_vibePromo && !empty($_vibePromo['coupon_code']) && (int)$_vibePromo['coupon_percent'] > 0) {
    $_pct       = (int)$_vibePromo['coupon_percent'];
    $_labelTxt  = trim((string)($_vibePromo['label'] ?? ''));
    $_dealHeadline = $_labelTxt !== ''
        ? ($_labelTxt . ' — Save ' . $_pct . '%!')
        : ('Save up to ' . $_pct . '% sitewide!');
    $_dealCode  = strtoupper((string)$_vibePromo['coupon_code']);
}
?>
<div class="deal-bar" id="deal-bar" data-testid="deal-bar">
  <div class="container d-flex align-items-center justify-content-center gap-2 gap-md-3 flex-wrap py-2 px-4">
    <span class="deal-bar-headline" data-testid="deal-bar-headline"><?= esc($_dealHeadline) ?></span>
    <button type="button"
            class="deal-bar-code-pill"
            onclick="(function(b){var c=b.getAttribute('data-code');if(!c)return;function done(){var o=b.dataset.orig||b.innerHTML;b.dataset.orig=b.dataset.orig||b.innerHTML;b.innerHTML='<i class=\'bi bi-check2\'></i> Copied';b.classList.add('is-copied');setTimeout(function(){b.innerHTML=o;b.classList.remove('is-copied');},1500);}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(c).then(done,done);}else{var t=document.createElement('textarea');t.value=c;document.body.appendChild(t);t.select();try{document.execCommand('copy');}catch(_){}t.remove();done();}})(this)"
            data-code="<?= esc($_dealCode) ?>"
            data-testid="deal-bar-code-pill"
            title="Click to copy">
      <span data-testid="deal-bar-code"><?= esc($_dealCode) ?></span>
      <i class="bi bi-clipboard"></i>
    </button>
    <a href="shop.php" class="deal-bar-shop-link" data-testid="deal-bar-cta">Shop Now <i class="bi bi-chevron-right"></i></a>
    <!-- Live countdown is kept (hidden by default; surfaces only when a
         scheduled vibe-promo is active so the bar isn't permanently
         counting down "fake" urgency on the homepage). -->
    <span class="deal-bar-countdown-wrap" data-testid="deal-bar-countdown-wrap" hidden>
      <i class="bi bi-clock"></i>
      <strong class="deal-countdown" id="deal-countdown" data-testid="deal-bar-countdown">--:--:--</strong>
    </span>
    <button type="button" class="deal-bar-close-x" aria-label="Dismiss deal bar" data-testid="deal-bar-close">&times;</button>
  </div>
</div>
<?php endif; ?>
