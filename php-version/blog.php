<?php
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Blog | ' . SITE_BRAND;
$pageDescription = 'Guides, tips and comparisons for Microsoft Office, Windows and security software — installation help, feature breakdowns and buying advice from the ' . SITE_BRAND . ' team.';

$perPage = 10;
$page    = max(1, (int)($_GET['p'] ?? 1));
$q       = trim((string)($_GET['q'] ?? ''));
$region  = strtoupper(trim((string)($_GET['region'] ?? '')));
// Only accept known regions so the SQL stays safe.
$allowedRegions = ['US' => 'United States', 'UK' => 'United Kingdom', 'AU' => 'Australia', 'CA' => 'Canada'];
if ($region !== '' && !isset($allowedRegions[$region])) $region = '';

// ---- Detect whether target_region column actually exists (older DBs may not have it)
$hasRegionCol = false;
try {
    $cols = db()->query("SHOW COLUMNS FROM blog_posts")->fetchAll(PDO::FETCH_COLUMN);
    $hasRegionCol = in_array('target_region', $cols, true);
} catch (Throwable $e) {}

// ---- Build the WHERE clause for both COUNT and SELECT ----
$where  = [];
$params = [];
if ($q !== '') {
    // Search title + content (strip-tag matching via LIKE).  The MySQL LIKE
    // operator is case-insensitive on the default utf8mb4 collation.
    $where[]  = '(title LIKE ? OR content LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($region !== '' && $hasRegionCol) {
    // Show posts targeted at the selected region PLUS any "ALL" / NULL
    // (region-agnostic) posts.  Without this, picking a region returned 0
    // rows whenever seed posts hadn't been tagged with a region yet.
    $where[]  = "(target_region = ? OR target_region = 'ALL' OR target_region IS NULL)";
    $params[] = $region;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ---- Count ----
$cntStmt = db()->prepare("SELECT COUNT(*) c FROM blog_posts $whereSql");
$cntStmt->execute($params);
$total = (int)$cntStmt->fetch()['c'];

$pages  = max(1, (int)ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

// ---- Fetch page ----
$sql  = "SELECT * FROM blog_posts $whereSql ORDER BY STR_TO_DATE(date, '%b %e, %Y') DESC, id ASC LIMIT $perPage OFFSET $offset";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$posts = $stmt->fetchAll();

function blog_page_url(int $p, string $q, string $region): string
{
    $params = ['p' => $p];
    if ($q !== '')      $params['q']      = $q;
    if ($region !== '') $params['region'] = $region;
    return 'blog.php?' . http_build_query($params);
}

$hasFilter = ($q !== '' || $region !== '');

include __DIR__ . '/includes/header.php';
?>
<?= render_page_head(SITE_BRAND . ' Blog', 'Expert tips, guides, and insights to help you get the most out of your software.', ['Blog' => null]) ?>
<div class="container py-4 py-lg-5">

  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <form method="get" action="blog.php" class="d-flex flex-wrap gap-2 align-items-center" style="max-width:720px; width:100%;" data-testid="blog-filter-form">
      <div class="input-group" style="flex:1 1 220px; min-width:200px;">
        <span class="input-group-text bg-body border-end-0"><i class="bi bi-search text-secondary"></i></span>
        <input name="q" value="<?= esc($q) ?>" class="form-control border-start-0" placeholder="Search title or content..." data-testid="blog-search">
      </div>
      <?php if ($hasRegionCol): ?>
      <select name="region" class="form-select" style="max-width:170px;flex:0 0 auto;" data-testid="blog-region-filter">
        <option value="">All regions</option>
        <?php foreach ($allowedRegions as $code => $label): ?>
          <option value="<?= esc($code) ?>" <?= $region === $code ? 'selected' : '' ?>><?= esc($label) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <button class="btn btn-primary rounded-pill px-3 flex-shrink-0" data-testid="blog-search-btn"><i class="bi bi-funnel me-1"></i>Filter</button>
      <?php if ($hasFilter): ?>
        <a href="blog.php" class="btn btn-outline-secondary rounded-pill px-3 flex-shrink-0" data-testid="blog-clear-filter"><i class="bi bi-x-circle me-1"></i>Clear</a>
      <?php endif; ?>
    </form>
    <small class="text-secondary"><i class="bi bi-sort-down me-1"></i>Newest first · <strong class="text-body" data-testid="blog-total-count"><?= $total ?></strong> article<?= $total === 1 ? '' : 's' ?><?php if ($hasFilter): ?> matching filter<?php endif; ?></small>
  </div>

  <?php if (!$posts): ?>
    <div class="card p-5 text-center" data-testid="blog-no-results">
      <i class="bi bi-search fs-1 text-secondary"></i>
      <p class="text-secondary mt-2 mb-3">
        <?php if ($q !== '' && $region !== ''): ?>
          No articles match "<?= esc($q) ?>" in <?= esc($allowedRegions[$region]) ?>.
        <?php elseif ($q !== ''): ?>
          No articles match "<?= esc($q) ?>".
        <?php elseif ($region !== ''): ?>
          No articles are published for <?= esc($allowedRegions[$region]) ?> yet.
        <?php else: ?>
          No articles have been published yet.
        <?php endif; ?>
      </p>
      <a href="blog.php" class="btn btn-primary rounded-pill mx-auto px-4">View All Articles</a>
    </div>
  <?php else: ?>
    <div class="row g-4" data-testid="blog-grid">
      <?php foreach ($posts as $b): ?>
        <div class="col-lg-4 col-md-6">
          <a href="blog-post.php?id=<?= esc($b['id']) ?>" class="card h-100 text-decoration-none" data-testid="blog-card-<?= (int)$b['id'] ?>">
            <img src="<?= esc($b['image']) ?>" class="card-img-top object-fit-cover" style="height:190px;" alt="<?= esc($b['title']) ?>" loading="lazy">
            <div class="card-body">
              <small class="text-secondary"><i class="bi bi-calendar3 me-1"></i><?= esc($b['date']) ?> · <?= esc($b['read_time']) ?>
                <?php if ($hasRegionCol && !empty($b['target_region']) && isset($allowedRegions[$b['target_region']])): ?>
                  · <span class="badge bg-light text-secondary border" style="font-weight:500;"><?= esc($b['target_region']) ?></span>
                <?php endif; ?>
              </small>
              <h5 class="fw-bold mt-2 text-body h6"><?= esc($b['title']) ?></h5>
              <span class="text-primary small fw-semibold">Read more <i class="bi bi-arrow-right"></i></span>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <nav class="d-flex justify-content-center align-items-center gap-2 mt-5" data-testid="blog-pagination">
      <a class="btn btn-sm btn-outline-secondary rounded-pill px-3 <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= blog_page_url($page - 1, $q, $region) ?>">Previous</a>
      <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-outline-secondary' ?> rounded-circle" style="width:34px;height:34px;" href="<?= blog_page_url($i, $q, $region) ?>" data-testid="blog-page-<?= $i ?>"><?= $i ?></a>
      <?php endfor; ?>
      <a class="btn btn-sm btn-outline-secondary rounded-pill px-3 <?= $page >= $pages ? 'disabled' : '' ?>" href="<?= blog_page_url($page + 1, $q, $region) ?>">Next</a>
    </nav>
    <p class="text-center small text-secondary mt-2">Page <?= $page ?> of <?= $pages ?> (<?= $total ?> posts)</p>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
