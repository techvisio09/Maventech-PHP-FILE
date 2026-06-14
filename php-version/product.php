<?php
require_once __DIR__ . '/includes/functions.php';

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

$pageTitle = $product['name'] . ' | ' . SITE_BRAND;
/* SEO: description, OG image and Product structured data */
$pageDescription = 'Buy ' . $product['name'] . ' — genuine lifetime license key for ' . format_price((float)$product['price'])
    . ($discountFlag = ($product['original_price'] && $product['original_price'] > $product['price']) ? ' (was ' . format_price((float)$product['original_price']) . ')' : '')
    . '. Instant email delivery, official download and 24/7 support from ' . SITE_BRAND . '.';
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
            'name'  => $brandName,
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
$pageKeywords = product_keywords($product);
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
            <img src="<?= esc($product['image']) ?>" alt="<?= esc(product_img_alt($product)) ?>" title="<?= esc($product['name']) ?>" class="pd-360-img" draggable="false" data-testid="product-360-img">
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

  <?php if ($related): ?>
    <h2 class="fw-bold h4 mt-5 mb-4">Related Products</h2>
    <div class="row g-4">
      <?php foreach ($related as $r): ?>
        <div class="col-xl-3 col-lg-4 col-sm-6"><?= render_product_card($r) ?></div>
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
</style>
<?php include __DIR__ . '/includes/footer.php'; ?>
