<?php
require_once __DIR__ . '/includes/functions.php';

$id = $_GET['id'] ?? '';
$post = null;
if ($id) {
    $stmt = db()->prepare('SELECT * FROM blog_posts WHERE id = ?');
    $stmt->execute([$id]);
    $post = $stmt->fetch();
}
$pageTitle = ($post ? $post['title'] : 'Post Not Found') . ' | ' . SITE_BRAND;
if ($post) {
    $pageDescription = trim(mb_substr(strip_tags($post['content']), 0, 155)) . '…';
    $ogType = 'article';
    $canonicalUrl = site_url() . '/blog-post.php?id=' . rawurlencode((string)$post['id']);
    if (!empty($post['image'])) $ogImage = $post['image'];

    // Article JSON-LD — lets Gemini, ChatGPT, Copilot, Perplexity and other
    // AI engines extract a clean Article schema and cite the post directly.
    $articleDate = '';
    if (!empty($post['created_at'])) {
        $articleDate = date('c', strtotime((string)$post['created_at']));
    } elseif (!empty($post['date'])) {
        $ts = strtotime((string)$post['date']);
        if ($ts) $articleDate = date('c', $ts);
    }
    $authorName = !empty($post['ai_generated'])
        ? (defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software') . ' AI Editorial Team'
        : (defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software');
    $jsonLdArticle = [
        '@context'      => 'https://schema.org',
        '@type'         => 'Article',
        'headline'      => $post['title'],
        'image'         => $post['image'] ? [$post['image']] : [],
        'datePublished' => $articleDate ?: date('c'),
        'dateModified'  => $articleDate ?: date('c'),
        'author'        => [
            '@type' => 'Organization',
            'name'  => $authorName,
            'url'   => site_url(),
        ],
        'publisher'     => [
            '@type' => 'Organization',
            'name'  => defined('SITE_BRAND') ? SITE_BRAND : 'Maventech Software',
            'logo'  => [
                '@type' => 'ImageObject',
                'url'   => site_url() . '/assets/images/badges/microsoft-verified.svg',
            ],
        ],
        'mainEntityOfPage' => [
            '@type' => 'WebPage',
            '@id'   => $canonicalUrl,
        ],
        'description'   => $pageDescription,
        'inLanguage'    => 'en',
        'isAccessibleForFree' => true,
    ];

    // AEO: Build FAQPage schema from stored FAQ data
    $jsonLdFaqPage = null;
    if (!empty($post['faq_json'])) {
        $faqItems = json_decode($post['faq_json'], true);
        if (is_array($faqItems) && count($faqItems) > 0) {
            $faqEntities = [];
            foreach ($faqItems as $fi) {
                if (!empty($fi['q']) && !empty($fi['a'])) {
                    $faqEntities[] = [
                        '@type' => 'Question',
                        'name'  => $fi['q'],
                        'acceptedAnswer' => [
                            '@type' => 'Answer',
                            'text'  => $fi['a'],
                        ],
                    ];
                }
            }
            if ($faqEntities) {
                $jsonLdFaqPage = [
                    '@context'   => 'https://schema.org',
                    '@type'      => 'FAQPage',
                    'mainEntity' => $faqEntities,
                ];
            }
        }
    }
} else {
    http_response_code(404);
    $noIndex = true;
}

include __DIR__ . '/includes/header.php';
?>
<?php if (!empty($jsonLdArticle)): ?>
<script type="application/ld+json"><?= json_encode($jsonLdArticle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
<?php if (!empty($jsonLdFaqPage)): ?>
<script type="application/ld+json"><?= json_encode($jsonLdFaqPage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
<div class="container py-5" style="max-width: 800px;">
  <?php if ($post): ?>
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="index.php">Home</a></li>
        <li class="breadcrumb-item"><a href="blog.php">Blog</a></li>
        <li class="breadcrumb-item active"><?= esc($post['title']) ?></li>
      </ol>
    </nav>
    <h1 class="fw-bold"><?= esc($post['title']) ?></h1>
    <p class="text-secondary small">
      <?= esc($post['date']) ?> · <?= esc($post['read_time']) ?>
      <?php if (!empty($post['ai_generated'])): ?>
        · <span style="background:#ede9fe;color:#5b21b6;border-radius:999px;padding:2px 9px;font-size:11px;font-weight:700;letter-spacing:.4px;" title="Written by the Maventech Software AI Editorial Team"><i class="bi bi-stars"></i> AI Editorial Team</span>
      <?php endif; ?>
    </p>
    <img src="<?= esc($post['image']) ?>" class="img-fluid rounded mb-4 w-100 object-fit-cover" style="max-height:380px;" alt="<?= esc($post['title']) ?>">
    <div class="post-content"><?= $post['content'] /* trusted HTML seeded from database.sql */ ?></div>

    <?php
      // -------- Internal "Related products" + "More articles" widgets --------
      // Internal linking is one of the single biggest SEO levers — Google uses
      // these intra-site anchors to understand topical authority and to crawl
      // deeper.  We pull (a) the featured product (if any), (b) 3 sibling
      // products in the same category, and (c) 3 newest blog posts excluding
      // the current one.
      $relatedProducts = [];
      $featuredProduct = null;
      if (!empty($post['product_id'])) {
          $fp = db()->prepare('SELECT id, slug, name, brand, category, price, image FROM products WHERE id = ? AND is_active = 1');
          $fp->execute([(int)$post['product_id']]);
          $featuredProduct = $fp->fetch();
      }
      if ($featuredProduct) {
          $rp = db()->prepare('SELECT slug, name, brand, price, image FROM products
                                WHERE category = ? AND id != ? AND is_active = 1
                                ORDER BY rating DESC, reviews DESC LIMIT 3');
          $rp->execute([$featuredProduct['category'], (int)$featuredProduct['id']]);
          $relatedProducts = $rp->fetchAll();
      }
      // Fallback if there's no featured product — pick top-rated overall.
      if (!$relatedProducts) {
          $relatedProducts = db()->query('SELECT slug, name, brand, price, image FROM products WHERE is_active = 1 ORDER BY rating DESC, reviews DESC LIMIT 3')->fetchAll();
      }
      $morePosts = db()->prepare("SELECT id, title, date, read_time, image FROM blog_posts WHERE id != ? ORDER BY COALESCE(created_at, '1970-01-01') DESC, id DESC LIMIT 3");
      $morePosts->execute([$post['id']]);
      $morePosts = $morePosts->fetchAll();
    ?>

    <?php if ($featuredProduct): ?>
      <hr class="my-4">
      <div class="card p-3 d-flex flex-row align-items-center gap-3" style="border-left:4px solid #4338ca;background:#fafaff;" data-testid="featured-product-card">
        <img src="<?= esc($featuredProduct['image']) ?>" alt="<?= esc($featuredProduct['name']) ?>" style="width:80px;height:80px;object-fit:cover;border-radius:8px;flex-shrink:0;">
        <div style="flex:1;min-width:0;">
          <div class="small text-uppercase text-secondary" style="letter-spacing:1px;font-weight:700;font-size:10px;color:#4338ca !important;">Article featured product</div>
          <div class="fw-bold" style="font-size:16px;color:#0f172a;line-height:1.3;"><?= esc($featuredProduct['name']) ?></div>
          <div class="small text-secondary mt-1"><?= esc($featuredProduct['brand'] ?: 'Genuine license') ?> · From $<?= number_format((float)$featuredProduct['price'], 2) ?></div>
        </div>
        <a href="product.php?slug=<?= urlencode($featuredProduct['slug']) ?>" class="btn btn-primary rounded-pill px-4 flex-shrink-0" data-testid="featured-product-link">View product →</a>
      </div>
    <?php endif; ?>

    <?php if ($relatedProducts): ?>
      <h3 class="fw-bold mt-5 h5">You might also like</h3>
      <div class="row g-3 mt-1" data-testid="related-products">
        <?php foreach ($relatedProducts as $rp): ?>
          <div class="col-md-4">
            <a href="product.php?slug=<?= urlencode($rp['slug']) ?>" class="card h-100 text-decoration-none p-2" style="border:1px solid #e5e7eb;">
              <img src="<?= esc($rp['image']) ?>" alt="<?= esc($rp['name']) ?>" class="rounded mb-2" style="width:100%;height:120px;object-fit:cover;">
              <div class="small fw-bold text-body" style="line-height:1.3;"><?= esc(mb_strimwidth($rp['name'], 0, 60, '…')) ?></div>
              <div class="small text-secondary mt-1"><?= esc($rp['brand']) ?> · $<?= number_format((float)$rp['price'], 2) ?></div>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($morePosts): ?>
      <h3 class="fw-bold mt-5 h5">More from the blog</h3>
      <div class="row g-3 mt-1" data-testid="more-posts">
        <?php foreach ($morePosts as $mp): ?>
          <div class="col-md-4">
            <a href="blog-post.php?id=<?= urlencode($mp['id']) ?>" class="card h-100 text-decoration-none p-2" style="border:1px solid #e5e7eb;">
              <img src="<?= esc($mp['image']) ?>" alt="<?= esc($mp['title']) ?>" class="rounded mb-2" style="width:100%;height:120px;object-fit:cover;">
              <div class="small fw-bold text-body" style="line-height:1.3;"><?= esc(mb_strimwidth($mp['title'], 0, 70, '…')) ?></div>
              <div class="small text-secondary mt-1"><i class="bi bi-calendar3 me-1"></i><?= esc($mp['date']) ?> · <?= esc($mp['read_time']) ?></div>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <hr class="my-4">
    <div class="card p-4 text-center">
      <h5 class="fw-bold">Ready to upgrade your software?</h5>
      <p class="small text-secondary">Genuine Microsoft licenses with instant delivery.</p>
      <a href="shop.php" class="btn btn-primary rounded-pill px-4 mx-auto">Shop Now</a>
    </div>
  <?php else: ?>
    <div class="text-center py-5">
      <h1 class="fw-bold">Post not found</h1>
      <a href="blog.php" class="btn btn-primary rounded-pill px-4 mt-3">Back to Blog</a>
    </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
