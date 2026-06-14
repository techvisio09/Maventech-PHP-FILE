<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/seo-content.php';

$slug = $_GET['slug'] ?? '';
$product = $slug ? get_product($slug) : null;
if (!$product) {
    http_response_code(404);
    $pageTitle = 'Product Not Found | ' . SITE_BRAND;
    include __DIR__ . '/includes/header.php';
    echo '<div class="container py-5 text-center"><h1 class="h3 fw-bold">Product not found</h1><a href="shop.php" class="btn btn-primary rounded-pill mt-3">Browse Products</a></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

/* SEO: long-tail keyword-rich title.  We prepend the brand, append the
 * platform + the magic words ("Lifetime License Key") so the title
 * itself targets two-to-three additional intent variants. */
$_pTitleYear = '';
if (preg_match('/\b(20\d{2})\b/', $product['name'], $_m)) $_pTitleYear = ' ' . $_m[1];
$pageTitle = $product['name'] . ' — Lifetime License Key for ' . ($product['platform'] ?: 'Windows') . ' | ' . SITE_BRAND;
$preloadImage = $product['image'] ?? '';
/* SEO: description, OG image and Product structured data
 * Prefer the LLM-generated meta_description (refreshed daily by the SEO
 * bot at /cron.php — see includes/seo-bot.php) when present; otherwise
 * fall back to a deterministic generated line. */
$discountFlag = ($product['original_price'] && $product['original_price'] > $product['price']);
if (!empty($product['meta_description'])) {
    $pageDescription = (string)$product['meta_description'];
} else {
    $pageDescription = 'Buy ' . $product['name'] . ' — genuine lifetime license key for ' . format_price((float)$product['price'])
        . ($discountFlag ? ' (was ' . format_price((float)$product['original_price']) . ')' : '')
        . '. Instant email delivery, official download and 24/7 support from ' . SITE_BRAND . '.';
}
$ogImage = $product['image'];
$ogType = 'product';

// Brand auto-detection — match product name against the same catalog we use
// for installation_steps_for() so Bitdefender products show "Bitdefender",
// Norton shows "Norton", etc.  Falls back to "Microsoft" only for genuine
// Microsoft products.
$brandLookup = [
    'bitdefender' => 'Bitdefender', 'norton' => 'Norton', 'mcafee' => 'McAfee',
    'kaspersky'   => 'Kaspersky',   'eset'   => 'ESET',   'avast'  => 'Avast',
    'avg'         => 'AVG',         'webroot'=> 'Webroot','trend micro' => 'Trend Micro',
    'malwarebytes'=> 'Malwarebytes','adobe'  => 'Adobe',  'autocad'=> 'Autodesk',
    'autodesk'    => 'Autodesk',    'corel'  => 'Corel',  'parallels' => 'Parallels',
    'windows'     => 'Microsoft',   'office' => 'Microsoft','visio' => 'Microsoft',
    'project'     => 'Microsoft',   'microsoft' => 'Microsoft',
];
$_pName = strtolower($product['name']);
$detectedBrand = 'Microsoft';
foreach ($brandLookup as $kw => $br) {
    if (strpos($_pName, $kw) !== false) { $detectedBrand = $br; break; }
}

$availableNow = function_exists('available_keys_count') ? available_keys_count($product['slug']) : 0;
$availability = $availableNow > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';

$jsonLd = [
    '@context'    => 'https://schema.org',
    '@type'       => 'Product',
    'name'        => $product['name'],
    'image'       => $product['image'],
    'description' => $pageDescription,
    'sku'         => $product['slug'],
    'mpn'         => $product['slug'],
    'brand'       => ['@type' => 'Brand', 'name' => $detectedBrand],
    'category'    => ucfirst((string)($product['category'] ?? 'Software')),
    'offers'      => [
        '@type'         => 'Offer',
        'url'           => site_url() . '/product.php?slug=' . $product['slug'],
        'priceCurrency' => current_currency()['code'] ?? 'USD',
        'price'         => (string)$product['price'],
        'availability'  => $availability,
        'itemCondition' => 'https://schema.org/NewCondition',
        'priceValidUntil' => date('Y-12-31'),
        'seller'        => [
            '@type' => 'Organization',
            'name'  => SITE_BRAND,
            'url'   => site_url() . '/',
        ],
        'shippingDetails' => [
            '@type' => 'OfferShippingDetails',
            'shippingRate'    => ['@type' => 'MonetaryAmount', 'value' => '0', 'currency' => 'USD'],
            'shippingDestination' => ['@type' => 'DefinedRegion', 'addressCountry' => ['US','GB','CA','AU','IN','AE']],
            'deliveryTime'    => [
                '@type' => 'ShippingDeliveryTime',
                'handlingTime'  => ['@type' => 'QuantitativeValue', 'minValue' => 0, 'maxValue' => 0, 'unitCode' => 'HUR'],
                'transitTime'   => ['@type' => 'QuantitativeValue', 'minValue' => 0, 'maxValue' => 1, 'unitCode' => 'HUR'],
            ],
        ],
        'hasMerchantReturnPolicy' => [
            '@type'         => 'MerchantReturnPolicy',
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays'   => 30,
            'returnMethod'         => 'https://schema.org/ReturnByMail',
            'returnFees'           => 'https://schema.org/FreeReturn',
        ],
    ],
];
if ((float)$product['rating'] > 0 && (int)$product['reviews'] > 0) {
    $jsonLd['aggregateRating'] = [
        '@type'       => 'AggregateRating',
        'ratingValue' => (string)$product['rating'],
        'reviewCount' => (int)$product['reviews'],
        'bestRating'  => '5',
        'worstRating' => '1',
    ];
}
// Embed up to 5 real reviews inside the Product schema for richer rich-result eligibility.
$_reviewItems = product_review_items_jsonld($product, 5);
if ($_reviewItems) {
    $jsonLd['review'] = $_reviewItems;
}

// BreadcrumbList — surfaces the path Home → Category → Product in Google
// rich results AND helps AI search engines understand site hierarchy.
$jsonLdBreadcrumb = [
    '@context' => 'https://schema.org',
    '@type'    => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home',    'item' => site_url() . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Shop',    'item' => site_url() . '/shop.php'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => ucfirst((string)($product['category'] ?? 'Software')), 'item' => site_url() . '/category.php?slug=' . urlencode($product['category'] ?? '')],
        ['@type' => 'ListItem', 'position' => 4, 'name' => $product['name']],
    ],
];

// FAQPage — brand-aware Q&A pairs that AI search engines (ChatGPT,
// Perplexity, Google's AI Overviews, Bing Chat) quote verbatim AND that
// Google promotes in "People also ask" / "Things to know" panels.
require_once __DIR__ . '/includes/email.php';
$pageFaqs = product_faqs($product);
$jsonLdFaq = [
    '@context'   => 'https://schema.org',
    '@type'      => 'FAQPage',
    'speakable'  => [
        '@type'       => 'SpeakableSpecification',
        'cssSelector' => ['.pd-faq-accordion', '.pd-seo-copy'],
    ],
    'mainEntity' => array_map(function($f) {
        return [
            '@type'          => 'Question',
            'name'           => $f['question'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['answer']],
        ];
    }, $pageFaqs),
];
// HowTo schema — "How to activate <product>".  Google promotes HowTo
// rich results AND AI search engines parse them as authoritative
// step-by-step answers for activation queries.
$jsonLdHowTo = product_howto_jsonld($product);

$pageKeywords = product_long_tail_keywords($product);
$related = get_products([$product['category']]);
$related = array_values(array_filter($related, fn($r) => $r['slug'] !== $product['slug']));
$related = array_slice($related, 0, 4);
$icons = app_icons();
$apps = array_filter(explode(',', $product['apps']));
$vg = get_variant_group($product);
$cv = $vg['cur']; // current variant ($cur is reserved by header.php for currency)
$versionLabel = fn($v) => $cv['base'] === 'windows' ? "Windows $v" : (string)$v;
$discountPct = ($product['original_price'] && $product['original_price'] > $product['price'])
    ? round((1 - $product['price'] / $product['original_price']) * 100) : 0;

include __DIR__ . '/includes/header.php';
?>
<div class="container py-5">
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
      <li class="breadcrumb-item"><a href="index.php">Home</a></li>
      <li class="breadcrumb-item"><a href="category.php?slug=<?= esc($product['category']) ?>"><?= esc(category_title($product['category'])) ?></a></li>
      <li class="breadcrumb-item active"><?= esc($product['name']) ?></li>
    </ol>
  </nav>

  <div class="row g-4 g-lg-5 mt-1">
    <div class="col-lg-5">
      <div class="card border p-4 position-relative pd-360-card">
        <?php if ($product['badge']): ?><span class="badge text-bg-primary position-absolute top-0 start-0 m-3" style="z-index:3;"><?= esc($product['badge']) ?></span><?php endif; ?>
        <?php if ($discountPct): ?><span class="badge text-bg-danger position-absolute top-0 end-0 m-3" style="z-index:3;">-<?= $discountPct ?>%</span><?php endif; ?>
        <div class="pd-360-frame" data-testid="product-360-viewer">
          <span class="pd-360-ring" aria-hidden="true"></span>
          <span class="pd-360-podium" aria-hidden="true"></span>
          <div class="pd-360-stage">
            <img src="<?= esc($product['image']) ?>" alt="<?= esc(product_img_alt($product)) ?>" title="<?= esc($product['name']) ?>" class="pd-360-img" draggable="false" data-testid="product-360-img" fetchpriority="high" decoding="async" width="640" height="640">
          </div>
          <span class="pd-360-badge" data-testid="product-360-badge"><i class="bi bi-arrow-repeat me-1"></i>360° view · drag to spin</span>
        </div>
      </div>
    </div>
    <div class="col-lg-7">
      <?php $stockN = available_keys_count($product['slug']); ?>
      <div class="d-flex gap-2 flex-wrap mb-2">
        <span class="badge os-badge"><img src="assets/images/os/<?= $product['platform'] === 'Mac' ? 'macos' : 'windows' ?>.svg" alt="<?= esc($product['platform']) ?>" class="os-icon me-1"><?= esc($product['platform']) ?></span>
        <?php if ($stockN > 0): ?>
          <span class="badge text-bg-success" data-testid="stock-pill-in-<?= esc($product['slug']) ?>"><i class="bi bi-check-circle me-1"></i>In Stock</span>
        <?php else: ?>
          <span class="badge pd-stock-out-badge" data-testid="stock-pill-out-<?= esc($product['slug']) ?>"><i class="bi bi-x-octagon-fill me-1"></i>Out of Stock</span>
        <?php endif; ?>
        <span class="badge text-bg-info text-dark"><i class="bi bi-infinity me-1"></i>Lifetime License</span>
      </div>
      <h1 class="h3 fw-bold" data-testid="product-name"><?= esc($product['name']) ?></h1>
      <div class="mb-3"><?= render_stars((float)$product['rating']) ?> <span class="text-secondary small"><?= esc($product['rating']) ?> (<?= (int)$product['reviews'] ?> reviews)</span></div>

      <?php if ($apps): ?>
        <div class="mb-3">
          <small class="text-secondary d-block mb-1">Includes:</small>
          <?php foreach ($apps as $a): ?>
            <?php if (isset($icons[$a])): ?><img src="<?= esc($icons[$a]) ?>" alt="<?= esc($a) ?>" class="app-chip" style="width:30px;height:30px;"><?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?= render_variant_row('Version', 'version', $vg['versions'], $cv['version'],
            fn($v) => find_variant($vg['group'], $v, $cv['os'], $cv['edition'])
                   ?? find_variant($vg['group'], $v, $cv['os']),
            $versionLabel) ?>
      <?= render_variant_row('Edition', 'edition', $vg['editions'], $cv['edition'],
            fn($ed) => find_variant($vg['group'], $cv['version'], $cv['os'], $ed)) ?>
      <?= render_variant_row('Operating system', 'os', $vg['os_options'], $cv['os'],
            fn($os) => find_variant($vg['group'], $cv['version'], $os, $cv['edition'])
                    ?? find_variant($vg['group'], $cv['version'], $os)) ?>

      <div class="mb-4">
        <span class="display-6 fw-bold text-primary" data-testid="product-price"><?= format_price((float)$product['price']) ?></span>
        <?php if ($discountPct): ?>
          <span class="text-secondary text-decoration-line-through ms-2 fs-5"><?= format_price((float)$product['original_price']) ?></span>
          <span class="badge text-bg-danger ms-2">Save <?= $discountPct ?>%</span>
        <?php endif; ?>
      </div>

      <?php /* Stock status is shown as a chip near the title above; no duplicate label here. */ ?>

      <div class="d-flex gap-3 align-items-center mb-4 flex-wrap">
        <div class="input-group" style="width: 130px;">
          <button class="btn btn-outline-secondary" type="button" onclick="const q=document.getElementById('pd-qty'); q.value=Math.max(1, parseInt(q.value)-1)" <?= $stockN<=0?'disabled':'' ?>>−</button>
          <input id="pd-qty" type="number" class="form-control text-center" value="1" min="1" max="<?= max(1,$stockN) ?>" <?= $stockN<=0?'disabled':'' ?> data-testid="pd-qty-input">
          <button class="btn btn-outline-secondary" type="button" onclick="const q=document.getElementById('pd-qty'); q.value=Math.min(<?= max(1,$stockN) ?>, parseInt(q.value)+1)" <?= $stockN<=0?'disabled':'' ?>>+</button>
        </div>
        <?php if ($stockN > 0): ?>
          <button class="btn btn-orange-solid btn-lg rounded-pill px-4 add-to-cart-btn" data-slug="<?= esc($product['slug']) ?>" data-testid="pd-add-to-cart"><i class="bi bi-cart-plus me-2"></i>Add to Cart</button>
          <button class="btn btn-orange-outline btn-lg rounded-pill px-4 fw-bold buy-now-btn" data-slug="<?= esc($product['slug']) ?>" data-testid="pd-buy-now"><i class="bi bi-lightning-charge-fill me-1"></i>Buy Now</button>
        <?php else: ?>
          <button class="btn btn-secondary btn-lg rounded-pill px-4" disabled data-testid="pd-out-of-stock"><i class="bi bi-x-octagon me-2"></i>Out of Stock</button>
        <?php endif; ?>
      </div>

      <?php if ($stockN <= 0): ?>
        <!-- Notify When Available -->
        <div class="card border-0 shadow-sm mb-4" id="notify-card" data-testid="notify-card"
             style="background:linear-gradient(135deg,#0b1d4f 0%,#172554 55%,#1e3a8a 100%); color:#e0e7ff; border-radius:16px; position:relative; overflow:hidden;">
          <!-- Subtle radial accent -->
          <div style="position:absolute;top:-40px;right:-40px;width:180px;height:180px;background:radial-gradient(circle,rgba(96,165,250,.25) 0%,transparent 70%);pointer-events:none;"></div>
          <div class="card-body p-3 p-md-4 position-relative">
            <div class="d-flex align-items-start gap-3 mb-3">
              <div style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 6px 18px rgba(59,130,246,.45);">
                <i class="bi bi-bell-fill" style="font-size:20px;"></i>
              </div>
              <div>
                <h5 class="fw-bold mb-1" style="color:#ffffff;letter-spacing:.2px;">Notify When Available</h5>
                <p class="mb-0" style="color:#cbd5e1;font-size:13px;line-height:1.55;">Drop your email — we'll alert you the instant <strong style="color:#ffffff;"><?= esc($product['name']) ?></strong> is restocked. No spam, just one quick email.</p>
              </div>
            </div>
            <form id="notify-form" class="d-flex gap-2 flex-wrap" data-testid="notify-form" novalidate>
              <input type="hidden" name="product_slug" value="<?= esc($product['slug']) ?>">
              <input type="email" class="form-control rounded-pill px-3" name="email"
                     placeholder="your@email.com" required
                     data-testid="notify-email-input"
                     style="flex:1; min-width:220px; border:1px solid rgba(148,163,184,.35); background:rgba(255,255,255,.95); color:#0f172a; font-weight:500;">
              <button type="submit" class="btn rounded-pill px-4 fw-bold" data-testid="notify-submit-btn"
                      style="background:linear-gradient(135deg,#3b82f6,#1d4ed8); border:0; color:#fff; box-shadow:0 6px 18px rgba(29,78,216,.45);">
                <i class="bi bi-envelope-check me-1"></i> Notify Me
              </button>
            </form>
            <div id="notify-msg" class="small mt-2" data-testid="notify-msg" style="display:none;color:#cbd5e1;"></div>
          </div>
        </div>
        <script>
        (function(){
          var form = document.getElementById('notify-form');
          var msg  = document.getElementById('notify-msg');
          if (!form) return;
          form.addEventListener('submit', async function(e){
            e.preventDefault();
            var emailInput = form.querySelector('input[name="email"]');
            var btn = form.querySelector('button[type="submit"]');
            var email = (emailInput.value || '').trim();
            msg.style.display = 'none';
            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
              msg.style.display = 'block';
              msg.className = 'small mt-2';
              msg.style.color = '#fca5a5';
              msg.textContent = 'Please enter a valid email address.';
              return;
            }
            btn.disabled = true;
            var oldHtml = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';
            try {
              var res = await fetch('ajax/notify-stock.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                  product_slug: form.querySelector('input[name="product_slug"]').value,
                  email: email,
                }),
              });
              var data = await res.json();
              msg.style.display = 'block';
              if (data.ok) {
                msg.className = 'small mt-2 fw-semibold';
                msg.style.color = '#86efac';
                msg.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>' + (data.message || "You're on the list!");
                form.reset();
              } else {
                msg.className = 'small mt-2';
                msg.style.color = '#fca5a5';
                msg.textContent = data.error || 'Something went wrong. Please try again.';
              }
            } catch (err) {
              msg.style.display = 'block';
              msg.className = 'small mt-2';
              msg.style.color = '#fca5a5';
              msg.textContent = 'Network error. Please try again.';
            } finally {
              btn.disabled = false;
              btn.innerHTML = oldHtml;
            }
          });
        })();
        </script>
      <?php endif; ?>

      <div class="row g-3 small">
        <div class="col-sm-6"><i class="bi bi-lightning-charge-fill text-warning me-2"></i>Instant email delivery (15-30 min)</div>
        <div class="col-sm-6"><i class="bi bi-patch-check-fill text-success me-2"></i>Genuine Microsoft key</div>
        <div class="col-sm-6"><i class="bi bi-arrow-counterclockwise text-primary me-2"></i>Money-back guarantee</div>
        <div class="col-sm-6"><i class="bi bi-headset text-primary me-2"></i>Free installation support</div>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <ul class="nav nav-tabs mt-5" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-desc">Description</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-delivery">Delivery & Activation</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-volume">Volume Pricing</button></li>
  </ul>
  <div class="tab-content border border-top-0 rounded-bottom p-4">
    <div class="tab-pane fade show active" id="tab-desc">
      <p><?= esc($product['name']) ?> is a genuine, lifetime-license product. One-time purchase — no subscription, no recurring fees. Your license key activates the official software downloaded directly from Microsoft (or the vendor) and remains yours forever.</p>
      <ul class="small text-secondary">
        <li>Licensed for 1 <?= esc($product['platform']) ?> device</li>
        <li>Full official version — not a trial or shared account</li>
        <li>Includes free updates within its version</li>
        <li>Activation support included — 30-day money-back policy</li>
      </ul>
    </div>
    <div class="tab-pane fade" id="tab-delivery">
      <ol class="small">
        <li class="mb-2">Complete your purchase — your license key + download link arrive by email within 15-30 minutes.</li>
        <li class="mb-2">Download the official installer from the link provided.</li>
        <li class="mb-2">Enter your product key when prompted to activate.</li>
        <li>Need help? Our team offers free installation assistance: <?= SITE_PHONE ?> (<?= SITE_HOURS ?>).</li>
      </ol>
    </div>
    <div class="tab-pane fade" id="tab-volume">
      <p class="small">Buying for a team? We offer volume discounts on 5+ licenses with consolidated invoicing.</p>
      <a href="contact.php" class="btn btn-outline-primary rounded-pill btn-sm">Request a Volume Quote</a>
    </div>
  </div>

  <!-- Long-tail keyword SEO copy block — visible to humans, indexable by
       crawlers, quotable by AI search engines (Speakable schema attached). -->
  <?= product_seo_copy($product) ?>

  <!-- Real customer reviews snippet — social proof + indexable text -->
  <?php $_reviewRows = product_review_snippets(3); ?>
  <?php if ($_reviewRows): ?>
  <section class="pd-review-snippets mt-5" data-testid="product-review-snippets" aria-labelledby="pd-rev-heading">
    <div class="d-flex align-items-center gap-2 mb-3">
      <i class="bi bi-chat-quote-fill" style="font-size:22px;color:#2563eb;"></i>
      <h2 id="pd-rev-heading" class="fw-bold h4 mb-0">What buyers say about <?= esc($product['name']) ?></h2>
    </div>
    <div class="row g-3">
      <?php foreach ($_reviewRows as $rv): ?>
        <div class="col-md-4">
          <div class="card border h-100 p-3" data-testid="review-snippet-card">
            <div class="text-warning mb-1">
              <?php for ($i = 1; $i <= 5; $i++): ?>
                <i class="bi <?= $i <= (int)$rv['rating'] ? 'bi-star-fill' : 'bi-star' ?>"></i>
              <?php endfor; ?>
            </div>
            <p class="small text-secondary mb-2" style="font-style:italic;">&ldquo;<?= esc(mb_substr((string)$rv['comment'], 0, 220)) . (mb_strlen((string)$rv['comment']) > 220 ? '&hellip;' : '') ?>&rdquo;</p>
            <div class="small fw-semibold text-body mt-auto"><?= esc((string)($rv['reviewer_name'] ?? 'Verified Buyer')) ?></div>
            <?php if (!empty($rv['submitted_at'])): ?>
              <div class="small text-secondary"><?= esc(date('F j, Y', strtotime((string)$rv['submitted_at']))) ?></div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="mt-3"><a href="reviews.php" class="text-decoration-none fw-semibold small">Read all <?= (int)$product['reviews'] ?> reviews <i class="bi bi-arrow-right"></i></a></div>
  </section>
  <?php endif; ?>

  <!-- Ask AI — Claude Haiku 4.5 powered Q&A grounded on this product's
       facts, FAQs, and recent reviews.  Answers free-form questions in
       seconds and routes anything off-topic to live chat. -->
  <section class="mt-5 ask-ai-card" data-testid="ask-ai-section" data-slug="<?= esc($product['slug']) ?>">
    <div class="ask-ai-head">
      <div class="ask-ai-avatar"><i class="bi bi-stars"></i></div>
      <div class="ask-ai-meta">
        <div class="ask-ai-title">Ask AI about this product</div>
        <div class="ask-ai-sub">Powered by Claude · Instant answers about delivery, compatibility, activation &amp; more</div>
      </div>
      <span class="ask-ai-pill"><span class="ask-ai-dot"></span>Online</span>
    </div>
    <div class="ask-ai-suggestions" data-testid="ask-ai-suggestions">
      <button type="button" class="ask-ai-chip" data-q="How long does delivery take?">How long does delivery take?</button>
      <button type="button" class="ask-ai-chip" data-q="Will this work on my Mac?">Will this work on my Mac?</button>
      <button type="button" class="ask-ai-chip" data-q="Is this a one-time purchase or subscription?">One-time or subscription?</button>
      <button type="button" class="ask-ai-chip" data-q="What happens if the key does not activate?">What if it doesn't activate?</button>
    </div>
    <div id="ask-ai-thread" class="ask-ai-thread" data-testid="ask-ai-thread"></div>
    <form id="ask-ai-form" class="ask-ai-form" onsubmit="askAiSubmit(event)">
      <input type="text" id="ask-ai-input" class="form-control" placeholder="Ask anything about this product…" maxlength="400" autocomplete="off" data-testid="ask-ai-input" required>
      <button type="submit" class="ask-ai-send" data-testid="ask-ai-send"><i class="bi bi-send-fill"></i></button>
    </form>
    <div class="ask-ai-footer">
      AI answers are based on this product's specs — for personal questions or order help, use the chat bubble.
    </div>
  </section>

  <!-- Brand-aware FAQ accordion — visible to humans + structured for AI
       crawlers (FAQPage JSON-LD emitted via $jsonLdFaq).  Answers are
       quotable verbatim by ChatGPT / Perplexity / Google AI Overviews. -->
  <section class="mt-5 pd-faq" aria-labelledby="pd-faq-heading" data-testid="product-faq">
    <div class="d-flex align-items-center gap-2 mb-3">
      <i class="bi bi-patch-question-fill" style="font-size:22px;color:#2563eb;"></i>
      <h2 id="pd-faq-heading" class="fw-bold h4 mb-0">Questions about <?= esc($product['name']) ?></h2>
    </div>
    <p class="text-secondary small mb-3">Quick answers about delivery, activation and our guarantee — straight from our support team.</p>
    <div class="accordion pd-faq-accordion" id="pd-faq-accordion">
      <?php foreach ($pageFaqs as $idx => $f): $itemId = 'pd-faq-item-' . $idx; ?>
        <div class="accordion-item">
          <h3 class="accordion-header">
            <button class="accordion-button <?= $idx > 0 ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse"
                    data-bs-target="#<?= esc($itemId) ?>" aria-expanded="<?= $idx === 0 ? 'true' : 'false' ?>"
                    aria-controls="<?= esc($itemId) ?>" data-testid="pd-faq-q-<?= $idx ?>">
              <?= esc($f['question']) ?>
            </button>
          </h3>
          <div id="<?= esc($itemId) ?>" class="accordion-collapse collapse <?= $idx === 0 ? 'show' : '' ?>"
               data-bs-parent="#pd-faq-accordion">
            <div class="accordion-body" data-testid="pd-faq-a-<?= $idx ?>">
              <?= nl2br(esc($f['answer'])) ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- ===== Deep-link cluster: drives Google's PageRank graph + helps AI
       crawlers map this product into the wider topical neighbourhood.
       Uses descriptive anchor text (mid-tail keywords) for every link.
       ============================================================== -->
  <?php
    $sister = product_sibling_category($product);
    $catSlug = (string)($product['category'] ?? '');
    $catTitle = $catSlug ? category_title($catSlug) : '';
    $relCats = related_category_links($catSlug);
    $popular = popular_search_terms();
  ?>
  <section class="pd-deep-cluster mt-5" data-testid="product-deep-cluster" aria-labelledby="pd-cluster-heading">
    <h2 id="pd-cluster-heading" class="fw-bold h4 mb-3">Related categories &amp; popular searches</h2>
    <div class="row g-4">
      <div class="col-md-6">
        <div class="fw-bold small text-uppercase text-secondary mb-2"><i class="bi bi-collection me-1"></i>Browse related categories</div>
        <ul class="list-unstyled small">
          <?php if ($catTitle): ?>
            <li class="mb-2">&rsaquo; <a class="text-decoration-none" href="category.php?slug=<?= esc($catSlug) ?>" data-testid="cluster-parent-category">All <?= esc($catTitle) ?> &mdash; genuine license keys</a></li>
          <?php endif; ?>
          <?php if ($sister): ?>
            <li class="mb-2">&rsaquo; <a class="text-decoration-none" href="category.php?slug=<?= esc($sister['slug']) ?>" data-testid="cluster-sister-category"><?= esc($sister['title']) ?> &mdash; sister edition</a></li>
          <?php endif; ?>
          <?php foreach ($relCats as $rc): ?>
            <li class="mb-2">&rsaquo; <a class="text-decoration-none" href="category.php?slug=<?= esc($rc['slug']) ?>" data-testid="cluster-related-<?= esc($rc['slug']) ?>"><?= $rc['anchor'] ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="col-md-6">
        <div class="fw-bold small text-uppercase text-secondary mb-2"><i class="bi bi-search me-1"></i>Popular searches</div>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($popular as $term): ?>
            <a href="shop.php?q=<?= urlencode($term) ?>" class="badge text-decoration-none fw-normal" data-testid="cluster-popular-search" style="background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;padding:6px 10px;font-size:12px;"><?= esc($term) ?></a>
          <?php endforeach; ?>
        </div>
        <div class="fw-bold small text-uppercase text-secondary mt-4 mb-2"><i class="bi bi-journal-text me-1"></i>Helpful guides on the blog</div>
        <ul class="list-unstyled small mb-0">
          <?php foreach (product_related_articles($product, 3) as $ba): ?>
            <li class="mb-1">&rsaquo; <a class="text-decoration-none" href="blog-post.php?id=<?= urlencode((string)$ba['id']) ?>" data-testid="cluster-related-article"><?= esc($ba['title']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </section>

  <?php if ($related): ?>
    <h2 class="fw-bold h4 mt-5 mb-4">Related Products</h2>
    <div class="row g-4">
      <?php foreach ($related as $r): ?>
        <div class="col-xl-3 col-lg-4 col-sm-6"><?= render_product_card($r) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php
    /* ---- Internal-linking widget: blog articles that feature this product.
       Boosts on-page topical authority (Google's PageRank-style internal
       link graph) and gives buyers extra trust-building context. ----------- */
    $articlesAboutThis = [];
    try {
        $aap = db()->prepare("SELECT id, title, date, read_time, image, ai_generated
                                FROM blog_posts
                               WHERE product_id = ?
                               ORDER BY COALESCE(created_at, '1970-01-01') DESC, id DESC
                               LIMIT 3");
        $aap->execute([(int)$product['id']]);
        $articlesAboutThis = $aap->fetchAll();
    } catch (Throwable $e) { /* old schema — silent */ }
  ?>
  <?php if ($articlesAboutThis): ?>
    <h2 class="fw-bold h4 mt-5 mb-4" data-testid="articles-about-this-product"><i class="bi bi-journal-text me-1 text-primary"></i>Read more about <?= esc($product['name']) ?></h2>
    <div class="row g-3">
      <?php foreach ($articlesAboutThis as $bp): ?>
        <div class="col-md-4">
          <a href="blog-post.php?id=<?= urlencode($bp['id']) ?>" class="card h-100 text-decoration-none p-0" style="border:1px solid #e5e7eb;overflow:hidden;">
            <img src="<?= esc($bp['image']) ?>" alt="<?= esc($bp['title']) ?>" style="width:100%;height:140px;object-fit:cover;">
            <div class="p-3">
              <div class="small text-secondary"><i class="bi bi-calendar3 me-1"></i><?= esc($bp['date']) ?> · <?= esc($bp['read_time']) ?>
                <?php if (!empty($bp['ai_generated'])): ?> · <span style="color:#5b21b6;font-weight:600;"><i class="bi bi-stars"></i> AI</span><?php endif; ?>
              </div>
              <div class="fw-bold mt-1 text-body" style="font-size:14px;line-height:1.35;"><?= esc($bp['title']) ?></div>
              <div class="text-primary small fw-semibold mt-2">Read article <i class="bi bi-arrow-right"></i></div>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<style>
  /* Out-of-stock chip styling — soft red pill matching brand */
  .pd-stock-out-badge {
    background: #fee2e2;
    color: #b91c1c;
    border: 1px solid #fecaca;
    font-weight: 600;
  }
  [data-bs-theme="dark"] .pd-stock-out-badge {
    background: rgba(239,68,68,.15);
    color: #fca5a5;
    border-color: rgba(239,68,68,.35);
  }
  /* Product FAQ accordion — clean cards with subtle blue accent */
  .pd-faq-accordion .accordion-item {
    border: 1px solid #e2e8f0;
    border-radius: 12px !important;
    margin-bottom: 10px;
    overflow: hidden;
    background: #ffffff;
  }
  [data-bs-theme="dark"] .pd-faq-accordion .accordion-item {
    border-color: #334155;
    background: #1e293b;
  }
  .pd-faq-accordion .accordion-button {
    font-weight: 600;
    font-size: 15px;
    color: #0f172a;
    background: #ffffff;
    padding: 16px 20px;
  }
  [data-bs-theme="dark"] .pd-faq-accordion .accordion-button {
    background: #1e293b;
    color: #e2e8f0;
  }
  .pd-faq-accordion .accordion-button:not(.collapsed) {
    background: linear-gradient(135deg, #eff6ff, #dbeafe);
    color: #1e3a8a;
    box-shadow: none;
  }
  [data-bs-theme="dark"] .pd-faq-accordion .accordion-button:not(.collapsed) {
    background: linear-gradient(135deg, #1c2541, #1e293b);
    color: #93c5fd;
  }
  .pd-faq-accordion .accordion-button:focus { box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); }
  .pd-faq-accordion .accordion-body {
    font-size: 14px;
    color: #475569;
    line-height: 1.7;
    padding: 14px 20px 20px;
  }
  [data-bs-theme="dark"] .pd-faq-accordion .accordion-body { color: #cbd5e1; }

  /* Ask AI widget — premium card with Claude-branded "Powered by" feel */
  .ask-ai-card {
    background: linear-gradient(135deg, #faf5ff 0%, #f0f9ff 100%);
    border: 1px solid #e9d5ff;
    border-radius: 16px;
    padding: 22px 22px 18px;
    position: relative;
    overflow: hidden;
  }
  [data-bs-theme="dark"] .ask-ai-card {
    background: linear-gradient(135deg, #1c1638 0%, #0f1e3a 100%);
    border-color: #4c1d95;
  }
  .ask-ai-head { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; }
  .ask-ai-avatar {
    width: 42px; height: 42px; border-radius: 12px;
    background: linear-gradient(135deg, #a855f7, #6366f1);
    color: #fff; display: inline-flex; align-items: center; justify-content: center;
    font-size: 20px; box-shadow: 0 6px 18px rgba(168, 85, 247, 0.35);
  }
  .ask-ai-meta { flex: 1; min-width: 0; }
  .ask-ai-title { font-size: 16px; font-weight: 700; color: #1e1b4b; line-height: 1.2; }
  [data-bs-theme="dark"] .ask-ai-title { color: #ddd6fe; }
  .ask-ai-sub { font-size: 12px; color: #64748b; margin-top: 2px; }
  [data-bs-theme="dark"] .ask-ai-sub { color: #94a3b8; }
  .ask-ai-pill {
    display: inline-flex; align-items: center; gap: 6px;
    background: #d1fae5; color: #047857;
    padding: 4px 10px; border-radius: 999px;
    font-size: 11px; font-weight: 700;
  }
  .ask-ai-dot {
    width: 8px; height: 8px; border-radius: 50%; background: #10b981;
    animation: ask-ai-pulse 2s ease-in-out infinite;
  }
  @keyframes ask-ai-pulse { 0%,100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.4); opacity: 0.5; } }
  .ask-ai-suggestions { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
  .ask-ai-chip {
    background: #ffffff; border: 1px solid #e9d5ff;
    color: #6d28d9; padding: 6px 14px;
    border-radius: 999px; font-size: 12.5px; font-weight: 600;
    cursor: pointer; transition: all 0.14s ease;
  }
  .ask-ai-chip:hover { background: #6d28d9; color: #fff; border-color: #6d28d9; transform: translateY(-1px); }
  [data-bs-theme="dark"] .ask-ai-chip { background: #1e1b4b; color: #c4b5fd; border-color: #4c1d95; }
  [data-bs-theme="dark"] .ask-ai-chip:hover { background: #6d28d9; color: #fff; }
  .ask-ai-thread { display: flex; flex-direction: column; gap: 10px; margin-bottom: 12px; max-height: 480px; overflow-y: auto; }
  .ask-ai-thread:empty { display: none; }
  .ask-ai-msg {
    padding: 10px 14px; border-radius: 12px;
    font-size: 13.5px; line-height: 1.55; max-width: 88%;
    animation: ask-ai-fade-in 0.22s ease-out;
  }
  @keyframes ask-ai-fade-in { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
  .ask-ai-msg.is-q { align-self: flex-end; background: #6d28d9; color: #fff; border-bottom-right-radius: 4px; }
  .ask-ai-msg.is-a { align-self: flex-start; background: #ffffff; color: #1e1b4b; border: 1px solid #e9d5ff; border-bottom-left-radius: 4px; box-shadow: 0 1px 3px rgba(15,23,42,.05); }
  [data-bs-theme="dark"] .ask-ai-msg.is-a { background: #1c1638; color: #ddd6fe; border-color: #4c1d95; }
  .ask-ai-msg.is-err { align-self: flex-start; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
  .ask-ai-feedback { display: flex; gap: 6px; margin-top: 8px; font-size: 11px; color: #94a3b8; align-items: center; }
  .ask-ai-fb-btn { background: transparent; border: 0; cursor: pointer; padding: 2px 6px; border-radius: 6px; color: #94a3b8; }
  .ask-ai-fb-btn:hover { color: #6d28d9; background: rgba(168,85,247,.08); }
  .ask-ai-fb-btn.is-on { color: #10b981; }
  .ask-ai-typing { font-size: 12px; color: #94a3b8; padding: 8px 12px; }
  .ask-ai-typing span { animation: ask-ai-typing 1.2s ease-in-out infinite; }
  .ask-ai-typing span:nth-child(2) { animation-delay: 0.18s; }
  .ask-ai-typing span:nth-child(3) { animation-delay: 0.36s; }
  @keyframes ask-ai-typing { 0%, 100% { opacity: 0.3; } 50% { opacity: 1; } }
  .ask-ai-form { display: flex; gap: 8px; }
  .ask-ai-form input { flex: 1; border-radius: 999px; padding: 10px 18px; font-size: 14px; border: 1px solid #e9d5ff; }
  .ask-ai-form input:focus { border-color: #6d28d9; box-shadow: 0 0 0 3px rgba(109,40,217,.15); outline: none; }
  [data-bs-theme="dark"] .ask-ai-form input { background: #1c1638; color: #ddd6fe; border-color: #4c1d95; }
  .ask-ai-send {
    width: 42px; height: 42px; border-radius: 50%; border: 0;
    background: linear-gradient(135deg, #a855f7, #6366f1); color: #fff;
    font-size: 16px; cursor: pointer; transition: all 0.14s ease;
    box-shadow: 0 6px 16px rgba(99,102,241,.32);
  }
  .ask-ai-send:hover { transform: translateY(-1px) scale(1.05); box-shadow: 0 10px 22px rgba(99,102,241,.45); }
  .ask-ai-send:disabled { opacity: 0.55; cursor: not-allowed; transform: none; }
  .ask-ai-footer { font-size: 10.5px; color: #94a3b8; margin-top: 10px; text-align: center; }
</style>
<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
/* ============================================================
 * Ask AI — product page widget (Claude Haiku 4.5)
 * ============================================================ */
(function(){
  const section = document.querySelector('[data-testid="ask-ai-section"]');
  if (!section) return;
  const slug   = section.getAttribute('data-slug') || '';
  const thread = document.getElementById('ask-ai-thread');
  const input  = document.getElementById('ask-ai-input');
  const sendBtn = document.querySelector('.ask-ai-send');

  // One-click suggestion chips populate the input.
  document.querySelectorAll('.ask-ai-chip').forEach(chip => {
    chip.addEventListener('click', () => {
      input.value = chip.getAttribute('data-q') || '';
      input.focus();
    });
  });

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function appendMsg(type, text, chatId) {
    const div = document.createElement('div');
    div.className = 'ask-ai-msg is-' + type;
    div.innerHTML = escapeHtml(text);
    thread.appendChild(div);
    if (type === 'a' && chatId) {
      const fb = document.createElement('div');
      fb.className = 'ask-ai-feedback';
      fb.innerHTML = '<span>Was this helpful?</span>'
        + '<button type="button" class="ask-ai-fb-btn" data-helpful="1" data-id="' + chatId + '" data-testid="ask-ai-fb-up-' + chatId + '"><i class="bi bi-hand-thumbs-up"></i></button>'
        + '<button type="button" class="ask-ai-fb-btn" data-helpful="0" data-id="' + chatId + '" data-testid="ask-ai-fb-down-' + chatId + '"><i class="bi bi-hand-thumbs-down"></i></button>';
      thread.appendChild(fb);
    }
    thread.scrollTop = thread.scrollHeight;
  }
  function appendTyping() {
    const t = document.createElement('div');
    t.className = 'ask-ai-typing';
    t.id = 'ask-ai-typing-indicator';
    t.innerHTML = 'Thinking<span>.</span><span>.</span><span>.</span>';
    thread.appendChild(t);
    thread.scrollTop = thread.scrollHeight;
  }
  function removeTyping() {
    const t = document.getElementById('ask-ai-typing-indicator');
    if (t) t.remove();
  }

  window.askAiSubmit = async function(ev) {
    ev.preventDefault();
    const q = input.value.trim();
    if (!q || sendBtn.disabled) return;
    appendMsg('q', q);
    input.value = '';
    sendBtn.disabled = true;
    appendTyping();
    try {
      const r = await fetch('ajax/ask-ai.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ slug: slug, question: q }),
      });
      const j = await r.json();
      removeTyping();
      if (j && j.ok) {
        appendMsg('a', j.answer, j.chat_id);
      } else {
        appendMsg('err', (j && j.error) || 'Something went wrong. Please try the chat bubble in the corner.');
      }
    } catch (_) {
      removeTyping();
      appendMsg('err', 'Network hiccup — please retry, or use the chat bubble for live help.');
    } finally {
      sendBtn.disabled = false;
      input.focus();
    }
  };

  // Thumbs up/down feedback delegation.
  thread.addEventListener('click', async (e) => {
    const btn = e.target.closest('.ask-ai-fb-btn');
    if (!btn) return;
    const chatId  = btn.getAttribute('data-id');
    const helpful = btn.getAttribute('data-helpful') === '1' ? 1 : 0;
    btn.classList.add('is-on');
    btn.parentNode.querySelectorAll('.ask-ai-fb-btn').forEach(b => { if (b !== btn) b.style.opacity = '0.35'; });
    try {
      await fetch('ajax/ask-ai-feedback.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ chat_id: chatId, helpful: helpful }),
      });
    } catch (_) {}
  });
})();
</script>
