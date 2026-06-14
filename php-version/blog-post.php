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
} else {
    http_response_code(404);
    $noIndex = true;
}

include __DIR__ . '/includes/header.php';
?>
<?php if (!empty($jsonLdArticle)): ?>
<script type="application/ld+json"><?= json_encode($jsonLdArticle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
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
    <p class="text-secondary small"><?= esc($post['date']) ?> · <?= esc($post['read_time']) ?></p>
    <img src="<?= esc($post['image']) ?>" class="img-fluid rounded mb-4 w-100 object-fit-cover" style="max-height:380px;" alt="<?= esc($post['title']) ?>">
    <div class="post-content"><?= $post['content'] /* trusted HTML seeded from database.sql */ ?></div>
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
