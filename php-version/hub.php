<?php
/* =====================================================================
 *  Topic Cluster Hub  —  /hub/<topic-slug>
 *  ---------------------------------------------------------------------
 *  One PHP file, every topic.  Aggregates EVERY product, EVERY blog post
 *  and EVERY FAQ that touches a given topic onto a single deep,
 *  citation-friendly page.
 *
 *  Why this exists:
 *    - Google's topical-authority model rewards a clear "hub" that
 *      proves you cover an entire subject — not just a thin landing.
 *    - ChatGPT / Perplexity / Bing Copilot routinely cite hub pages
 *      because the structured H2/H3 hierarchy + visible Q&A makes them
 *      the easiest single URL to quote when asked "tell me everything
 *      about Microsoft Office".
 *
 *  Configuration lives in $TOPICS below.  Adding a new hub is one
 *  associative entry; no template changes required.
 * ===================================================================== */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/seo-content.php';
require_once __DIR__ . '/includes/email.php';

/* ---------- TOPIC CONFIG ----------
 *  Each topic declares:
 *    title       Human title used in the H1 + breadcrumb.
 *    headline    Quick-Answer 40-60 word AEO direct answer.
 *    audience    Who this is for (E-E-A-T signal).
 *    categories  category slugs whose products belong here.
 *    blogTags    LIKE patterns matched against blog_posts.title to pull
 *                relevant editorial pieces.
 *    keywords    long-tail keyword string (meta + on-page).
 *    aboutLink   shop/category URL for the primary CTA.
 *    color       brand accent for the hero block.
 * ---------------------------------- */
$TOPICS = [
    'microsoft-office' => [
        'title'      => 'Microsoft Office — the complete buying guide',
        'headline'   => 'Microsoft Office is a one-time-purchase office suite (Word, Excel, PowerPoint, Outlook, Publisher, Access) sold by ' . SITE_BRAND . ' at up to 81% below retail. Every licence is genuine, lifetime, activates inside the official Microsoft installer, delivered by email in 15-30 minutes, and protected by a 30-day money-back guarantee.',
        'audience'   => 'home users, students, freelancers and small-business owners choosing between Office 2024, 2021 and 2019 on Windows or Mac',
        'categories' => ['office-pc','office-mac','office-2024-pc','office-2021-pc','office-2019-pc','office-2024-mac','office-2021-mac','office-2019-mac','apps','microsoft-project','microsoft-visio'],
        'blogTags'   => ['%office%','%word%','%excel%','%powerpoint%','%outlook%','%microsoft 365%','%publisher%'],
        'keywords'   => 'Microsoft Office, Office 2024, Office 2021, Office 2019, Office for Mac, Office for PC, Office lifetime license, Office one time purchase, buy Microsoft Office key, Microsoft Office product key, Office Home and Student, Office Home and Business, Office Professional Plus, Microsoft Project, Microsoft Visio',
        'aboutLink'  => 'category.php?slug=apps',
        'color'      => '#dc2626',
    ],
    'windows' => [
        'title'      => 'Microsoft Windows — Windows 11, 10 and Pro buying guide',
        'headline'   => 'Microsoft Windows is the world\'s most-used desktop operating system. ' . SITE_BRAND . ' sells genuine Windows 11 and Windows 10 product keys (Home, Pro and Education) at up to 81% off retail. Pay once, activate inside the official Windows setup, and keep the licence for life — instant email delivery and 30-day guarantee.',
        'audience'   => 'self-builders, IT teams and home upgraders looking for a genuine Windows 11 Pro or Windows 10 product key',
        'categories' => ['windows-11','windows-10','windows','os'],
        'blogTags'   => ['%windows 11%','%windows 10%','%windows%'],
        'keywords'   => 'Microsoft Windows, Windows 11 Pro, Windows 11 Home, Windows 10 Pro, Windows 10 Home, Windows product key, buy Windows 11 key, Windows lifetime activation, Windows OEM key, Windows 11 vs 10, upgrade to Windows 11',
        'aboutLink'  => 'category.php?slug=windows-11',
        'color'      => '#0078d4',
    ],
    'antivirus' => [
        'title'      => 'Antivirus software — Bitdefender, McAfee &amp; internet-security buying guide',
        'headline'   => 'Modern antivirus software protects every device in your household from malware, ransomware and identity theft. ' . SITE_BRAND . ' carries genuine Bitdefender and McAfee licences for 1, 3, 5 and 10 devices at up to 81% off retail. Activates inside the official vendor installer, delivered by email, with our 30-day money-back guarantee.',
        'audience'   => 'home users, families and small businesses choosing between Bitdefender Total Security, McAfee Total Protection and other paid antivirus suites',
        'categories' => ['antivirus','bitdefender','mcafee','internet-security'],
        'blogTags'   => ['%bitdefender%','%mcafee%','%antivirus%','%malware%','%ransomware%','%internet security%'],
        'keywords'   => 'antivirus, Bitdefender Total Security, McAfee Total Protection, internet security, anti-malware, ransomware protection, family antivirus plans, best antivirus 2026, antivirus for Mac, multi-device antivirus',
        'aboutLink'  => 'category.php?slug=antivirus',
        'color'      => '#16a34a',
    ],
];

$topicSlug = strtolower(trim((string)($_GET['topic'] ?? '')));
$topic = $TOPICS[$topicSlug] ?? null;
if (!$topic) {
    http_response_code(404);
    $pageTitle = 'Topic Hub Not Found | ' . SITE_BRAND;
    include __DIR__ . '/includes/header.php';
    echo '<div class="container py-5 text-center">';
    echo '<h1 class="h3 fw-bold mb-3">Topic hub not found</h1>';
    echo '<p class="text-secondary">We don&rsquo;t have a hub for &ldquo;' . esc($topicSlug) . '&rdquo; yet.</p>';
    echo '<div class="d-flex gap-2 justify-content-center flex-wrap mt-4">';
    foreach ($TOPICS as $k => $t) {
        echo '<a class="btn btn-outline-primary rounded-pill" href="hub.php?topic=' . esc($k) . '">' . strip_tags(esc($t['title'])) . '</a>';
    }
    echo '</div></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle       = strip_tags($topic['title']) . ' (' . date('Y') . ') | ' . SITE_BRAND;
$pageDescription = strip_tags($topic['headline']);
$pageKeywords    = $topic['keywords'];

/* ---------- DATA AGGREGATION ---------- */
$pdo = db();

// 1) Products belonging to any of the topic's categories.
$hubProducts = [];
if ($topic['categories']) {
    try {
        $place = implode(',', array_fill(0, count($topic['categories']), '?'));
        $stP = $pdo->prepare(
            "SELECT id, name, slug, image, price, original_price, rating, reviews, platform, category
               FROM products
              WHERE category IN ($place)
           ORDER BY (rating * reviews) DESC, price ASC
              LIMIT 24"
        );
        $stP->execute($topic['categories']);
        $hubProducts = $stP->fetchAll();
    } catch (Throwable $e) {}
}

// 2) Blog posts whose title matches any of the topic's LIKE patterns.
$hubPosts = [];
if ($topic['blogTags']) {
    try {
        $whereLikes = implode(' OR ', array_fill(0, count($topic['blogTags']), 'LOWER(title) LIKE ?'));
        $stB = $pdo->prepare(
            "SELECT id, title, image, date, read_time, target_region, COALESCE(updated_at, created_at) AS sort_at, lead
               FROM blog_posts
              WHERE $whereLikes
           ORDER BY sort_at DESC, id DESC
              LIMIT 12"
        );
        $stB->execute(array_map('strtolower', $topic['blogTags']));
        $hubPosts = $stB->fetchAll();
    } catch (Throwable $e) {}
}

// 3) Aggregate FAQs — pull product FAQs from the top 4 products + a few
// hub-level Q&A.  Gives Google AND AI engines a single page that answers
// every common question about the topic.
$hubFaqs = [];
if ($hubProducts) {
    foreach (array_slice($hubProducts, 0, 4) as $p) {
        foreach (product_faqs($p) as $f) {
            $hubFaqs[] = $f;
            if (count($hubFaqs) >= 10) break 2;
        }
    }
}
$hubFaqs = array_slice(_hub_unique_faqs($hubFaqs), 0, 10);

/* ---------- STRUCTURED DATA ---------- */
// Set $jsonLd so the header.php main JSON-LD emit picks it up alongside
// the other auto-detected blocks (BreadcrumbList, ItemList, FAQPage).
$jsonLd = [
    '@context'   => 'https://schema.org',
    '@type'      => 'CollectionPage',
    '@id'        => site_url() . '/hub/' . $topicSlug . '#cluster',
    'url'        => site_url() . '/hub/' . $topicSlug,
    'name'       => strip_tags($topic['title']),
    'description'=> $pageDescription,
    'inLanguage' => 'en',
    'isPartOf'   => ['@id' => site_url() . '/#website'],
    'about'      => ['@type' => 'Thing', 'name' => strip_tags($topic['title'])],
    'audience'   => ['@type' => 'Audience', 'audienceType' => $topic['audience']],
    'keywords'   => $topic['keywords'],
    'dateModified' => date('c'),
];

$jsonLdBreadcrumb = [
    '@context' => 'https://schema.org',
    '@type'    => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home',   'item' => site_url() . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Topics', 'item' => site_url() . '/hub/' . $topicSlug],
        ['@type' => 'ListItem', 'position' => 3, 'name' => strip_tags($topic['title'])],
    ],
];

// Build an ItemList of products on the hub (strong topical signal).
$jsonLdItemList = category_itemlist_jsonld($hubProducts, strip_tags($topic['title']));

// Hub-wide FAQPage with Speakable selectors so AI assistants quote us verbatim.
$jsonLdFaq = $hubFaqs ? faq_to_jsonld($hubFaqs) : null;

// Mentions list (Google graph edge) — link every aggregated blog post + product to the hub.
$mentionsArr = [];
foreach (array_slice($hubProducts, 0, 12) as $p) {
    $mentionsArr[] = ['@type' => 'Product', 'name' => $p['name'], 'url' => site_url() . '/product.php?slug=' . urlencode($p['slug'])];
}
foreach (array_slice($hubPosts, 0, 6) as $bp) {
    $mentionsArr[] = ['@type' => 'Article', 'name' => $bp['title'], 'url' => site_url() . '/blog-post.php?id=' . urlencode((string)$bp['id'])];
}
if ($mentionsArr) {
    $jsonLd['mentions'] = $mentionsArr;
}

include __DIR__ . '/includes/header.php';
?>
<div class="container py-4 py-lg-5">

<?= render_breadcrumb_nav([
    ['name' => 'Home',   'url' => 'index.php'],
    ['name' => 'Topics', 'url' => 'hub.php?topic=' . urlencode($topicSlug)],
    ['name' => strip_tags($topic['title'])],
], 'hub-breadcrumb') ?>

<!-- Topic Hero -->
<section class="hub-hero rounded-4 mb-4" data-testid="hub-hero" style="background:linear-gradient(135deg,<?= esc($topic['color']) ?>1c,<?= esc($topic['color']) ?>08);border:1px solid <?= esc($topic['color']) ?>33;padding:32px 28px;">
  <div class="d-inline-flex align-items-center gap-2 mb-3" style="background:<?= esc($topic['color']) ?>;color:#fff;border-radius:999px;padding:6px 14px;font-size:11px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;">
    <i class="bi bi-collection-fill"></i>Topic Cluster Hub
  </div>
  <h1 class="fw-bold mb-2" data-testid="hub-h1" style="font-size:clamp(28px, 4vw, 44px);"><?= $topic['title'] ?></h1>
  <p class="lead text-secondary mb-3" style="max-width:780px;">For <?= esc($topic['audience']) ?>.</p>
  <div class="d-flex flex-wrap gap-3 align-items-center" data-testid="hub-stats">
    <span class="badge text-bg-light" style="padding:8px 12px;font-size:12px;border:1px solid #e2e8f0;"><i class="bi bi-box-seam me-1" style="color:<?= esc($topic['color']) ?>"></i><?= count($hubProducts) ?> products</span>
    <span class="badge text-bg-light" style="padding:8px 12px;font-size:12px;border:1px solid #e2e8f0;"><i class="bi bi-journal-text me-1" style="color:<?= esc($topic['color']) ?>"></i><?= count($hubPosts) ?> guides</span>
    <span class="badge text-bg-light" style="padding:8px 12px;font-size:12px;border:1px solid #e2e8f0;"><i class="bi bi-patch-question-fill me-1" style="color:<?= esc($topic['color']) ?>"></i><?= count($hubFaqs) ?> answers</span>
    <span class="badge text-bg-light" style="padding:8px 12px;font-size:12px;border:1px solid #e2e8f0;"><i class="bi bi-arrow-clockwise me-1" style="color:<?= esc($topic['color']) ?>"></i>Updated <?= date('M Y') ?></span>
    <a class="btn rounded-pill px-4 ms-auto" href="<?= esc($topic['aboutLink']) ?>" style="background:<?= esc($topic['color']) ?>;color:#fff;font-weight:600;" data-testid="hub-cta-primary"><i class="bi bi-cart-plus me-1"></i>Shop the full range</a>
  </div>
</section>

<!-- AEO Quick Answer — top of the page so AI Overviews + voice grab it. -->
<?= render_aeo_answer(
      'What is ' . strip_tags(explode(' — ', $topic['title'])[0]) . '?',
      $topic['headline'],
      'hub-quick-answer'
  ) ?>

<!-- Quick navigation chips — every section anchor for fast scroll + a11y -->
<nav class="d-flex flex-wrap gap-2 mb-4" aria-label="On this page" data-testid="hub-toc">
  <?php
    $tocChips = [
        ['#hub-products', '<i class="bi bi-grid-3x3-gap-fill"></i> Products', $hubProducts ? null : 'd-none'],
        ['#hub-guides',   '<i class="bi bi-journal-text"></i> Guides',         $hubPosts    ? null : 'd-none'],
        ['#hub-faqs',     '<i class="bi bi-patch-question-fill"></i> FAQs',    $hubFaqs     ? null : 'd-none'],
        ['#hub-related',  '<i class="bi bi-collection"></i> Related topics',   null],
    ];
    foreach ($tocChips as [$href, $label, $hide]):
      if ($hide) continue;
  ?>
    <a class="badge text-decoration-none" href="<?= esc($href) ?>" style="background:#f1f5f9;color:#1e293b;border:1px solid #e2e8f0;padding:8px 14px;font-size:12px;font-weight:600;"><?= $label ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($hubProducts): ?>
<!-- Products section — the heart of the hub. -->
<section id="hub-products" class="mb-5" aria-labelledby="hub-products-h2" data-testid="hub-products">
  <h2 id="hub-products-h2" class="fw-bold h3 mb-3"><?= count($hubProducts) ?> top picks in this topic</h2>
  <div class="row g-3">
    <?php foreach (array_slice($hubProducts, 0, 12) as $p): ?>
      <div class="col-md-6 col-lg-4">
        <a href="product.php?slug=<?= esc($p['slug']) ?>" class="card text-decoration-none h-100 hub-product-card" data-testid="hub-product-card" style="border:1px solid #e2e8f0;border-radius:12px;padding:14px;transition:all .15s;"
           onmouseover="this.style.borderColor='<?= esc($topic['color']) ?>';this.style.transform='translateY(-2px)';this.style.boxShadow='0 8px 20px rgba(15,23,42,.06)'"
           onmouseout="this.style.borderColor='#e2e8f0';this.style.transform='none';this.style.boxShadow='none'">
          <div class="d-flex align-items-center gap-3 mb-2">
            <img src="<?= esc($p['image']) ?>" alt="<?= esc($p['name']) ?>" style="width:64px;height:64px;object-fit:contain;flex-shrink:0;" loading="lazy" decoding="async">
            <div class="flex-grow-1" style="min-width:0;">
              <div class="fw-bold text-truncate" style="color:#0f172a;font-size:14px;" title="<?= esc($p['name']) ?>"><?= esc($p['name']) ?></div>
              <div class="d-flex align-items-center gap-2 mt-1">
                <span class="text-warning small"><?php for ($s=1;$s<=5;$s++) echo $s <= (int)round((float)$p['rating']) ? '★' : '☆'; ?></span>
                <span class="text-secondary" style="font-size:11px;"><?= number_format((float)$p['rating'], 1) ?> · <?= (int)$p['reviews'] ?> reviews</span>
              </div>
            </div>
          </div>
          <div class="d-flex align-items-baseline gap-2 mt-2">
            <span class="fw-bold" style="color:<?= esc($topic['color']) ?>;font-size:18px;"><?= esc(format_price((float)$p['price'])) ?></span>
            <?php if (!empty($p['original_price']) && (float)$p['original_price'] > (float)$p['price']): ?>
              <span class="text-secondary text-decoration-line-through small"><?= esc(format_price((float)$p['original_price'])) ?></span>
            <?php endif; ?>
            <span class="ms-auto small fw-semibold" style="color:<?= esc($topic['color']) ?>;">View &rsaquo;</span>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if (count($hubProducts) > 12): ?>
    <div class="text-center mt-3"><a href="<?= esc($topic['aboutLink']) ?>" class="btn btn-outline-secondary rounded-pill" data-testid="hub-products-view-all">View all <?= count($hubProducts) ?> products <i class="bi bi-arrow-right ms-1"></i></a></div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($hubPosts): ?>
<!-- Long-form guides aggregated from the blog. -->
<section id="hub-guides" class="mb-5" aria-labelledby="hub-guides-h2" data-testid="hub-guides">
  <h2 id="hub-guides-h2" class="fw-bold h3 mb-3">Editorial guides on this topic</h2>
  <p class="text-secondary mb-3" style="max-width:780px;">In-depth articles our editorial team has published about <?= esc(strip_tags(explode(' — ', $topic['title'])[0])) ?> &mdash; updated regularly so the dates you see reflect the freshest information.</p>
  <div class="row g-3">
    <?php foreach (array_slice($hubPosts, 0, 9) as $bp): ?>
      <div class="col-md-6 col-lg-4">
        <a href="blog-post.php?id=<?= urlencode((string)$bp['id']) ?>" class="card text-decoration-none h-100" data-testid="hub-guide-card" style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;transition:all .15s;"
           onmouseover="this.style.borderColor='<?= esc($topic['color']) ?>';this.style.transform='translateY(-2px)'"
           onmouseout="this.style.borderColor='#e2e8f0';this.style.transform='none'">
          <?php if (!empty($bp['image'])): ?>
            <img src="<?= esc($bp['image']) ?>" alt="<?= esc($bp['title']) ?>" style="width:100%;height:140px;object-fit:cover;" loading="lazy" decoding="async">
          <?php endif; ?>
          <div style="padding:14px;">
            <div class="fw-bold mb-2" style="color:#0f172a;font-size:14px;line-height:1.35;"><?= esc($bp['title']) ?></div>
            <?php if (!empty($bp['lead'])): ?>
              <p class="text-secondary small mb-2" style="display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;"><?= esc(mb_substr((string)$bp['lead'], 0, 200)) ?></p>
            <?php endif; ?>
            <div class="d-flex align-items-center gap-2 small text-secondary">
              <span><i class="bi bi-calendar-event"></i> <?= esc($bp['date']) ?></span>
              <?php if (!empty($bp['read_time'])): ?><span>·</span><span><?= esc($bp['read_time']) ?></span><?php endif; ?>
              <?php $rcb = (string)($bp['target_region'] ?? ''); if ($rcb !== '' && $rcb !== 'ALL'): ?>
                <span>·</span><span class="badge text-bg-light"><?= esc($rcb) ?></span>
              <?php endif; ?>
            </div>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($hubFaqs): ?>
<!-- AEO FAQs — visible Q&A serialised to FAQPage JSON-LD via $jsonLdFaq. -->
<section id="hub-faqs" class="mb-5" aria-labelledby="hub-faqs-h2" data-testid="hub-faqs">
  <h2 id="hub-faqs-h2" class="fw-bold h3 mb-3">Everything else people ask</h2>
  <div class="accordion pd-faq-accordion" id="hub-faq-accordion">
    <?php foreach ($hubFaqs as $idx => $f): $itemId = 'hub-faq-q-' . $idx; ?>
      <div class="accordion-item">
        <h3 class="accordion-header">
          <button class="accordion-button <?= $idx > 0 ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse"
                  data-bs-target="#<?= esc($itemId) ?>" aria-expanded="<?= $idx === 0 ? 'true' : 'false' ?>"
                  aria-controls="<?= esc($itemId) ?>" data-testid="hub-faq-q-<?= $idx ?>">
            <?= esc($f['question']) ?>
          </button>
        </h3>
        <div id="<?= esc($itemId) ?>" class="accordion-collapse collapse <?= $idx === 0 ? 'show' : '' ?>" data-bs-parent="#hub-faq-accordion">
          <div class="accordion-body" data-testid="hub-faq-a-<?= $idx ?>"><?= $f['answer'] ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- Related topic hubs — internal-link cluster across hubs. -->
<section id="hub-related" class="mb-4" aria-labelledby="hub-related-h2" data-testid="hub-related">
  <h2 id="hub-related-h2" class="fw-bold h3 mb-3">Other topic hubs you might explore</h2>
  <div class="row g-3">
    <?php foreach ($TOPICS as $otherSlug => $other): if ($otherSlug === $topicSlug) continue; ?>
      <div class="col-md-4">
        <a href="hub.php?topic=<?= esc($otherSlug) ?>" class="card text-decoration-none h-100" data-testid="hub-related-link" style="border:1px solid #e2e8f0;border-radius:12px;padding:16px;transition:all .15s;"
           onmouseover="this.style.borderColor='<?= esc($other['color']) ?>';this.style.transform='translateY(-2px)'"
           onmouseout="this.style.borderColor='#e2e8f0';this.style.transform='none'">
          <div class="d-inline-block mb-2" style="background:<?= esc($other['color']) ?>;color:#fff;border-radius:999px;padding:3px 10px;font-size:10px;font-weight:700;letter-spacing:.5px;">TOPIC HUB</div>
          <div class="fw-bold mb-1" style="color:#0f172a;font-size:14px;"><?= strip_tags($other['title']) ?></div>
          <div class="text-secondary small"><?= esc(mb_substr(strip_tags($other['headline']), 0, 110)) ?>&hellip;</div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
</section>

</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
<?php
/* ----- helpers -------------------------------------------------- */
function _hub_unique_faqs(array $faqs): array
{
    $seen = [];
    $out  = [];
    foreach ($faqs as $f) {
        $k = mb_strtolower(trim((string)($f['question'] ?? '')));
        if ($k === '' || isset($seen[$k])) continue;
        $seen[$k] = 1;
        $out[] = $f;
    }
    return $out;
}
