<?php
// Public customer review submission page (token-based, no login required).
require_once __DIR__ . '/includes/functions.php';
$pdo = db();
$token = $_GET['t'] ?? '';
$review = null;
if ($token) {
    $r = $pdo->prepare('SELECT cr.*, p.name AS product_name, p.image AS product_image FROM customer_reviews cr LEFT JOIN products p ON p.slug=cr.product_slug WHERE cr.request_token=? LIMIT 1');
    $r->execute([$token]); $review = $r->fetch();
}

$saved = false;
if ($_SERVER['REQUEST_METHOD']==='POST' && $review) {
    $rating = max(1, min(5, (int)($_POST['rating'] ?? 5)));
    $comment = trim($_POST['comment'] ?? '');
    $ai = (int)($_POST['ai_generated'] ?? 0);
    $pdo->prepare('UPDATE customer_reviews SET rating=?, comment=?, ai_generated=?, status="published", submitted_at=NOW() WHERE request_token=?')
        ->execute([$rating, $comment, $ai, $token]);
    $saved = true;
}

$pageTitle = 'Share Your Feedback · ' . esc(SITE_BRAND);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= esc($pageTitle) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%); min-height:100vh; font-family:-apple-system,Segoe UI,Roboto,sans-serif; padding:30px 12px; }
.review-card { max-width:560px; margin:0 auto; background:#fff; border-radius:18px; padding:36px 30px; box-shadow:0 20px 60px rgba(0,0,0,.25); }
.stars { display:flex; gap:8px; justify-content:center; margin:20px 0; }
.stars label { cursor:pointer; font-size:42px; color:#e5e7eb; transition:all .15s; }
.stars input { display:none; }
.stars label.lit, .stars label:hover, .stars label:hover ~ label { color:#facc15; transform:scale(1.1); }
.stars { flex-direction:row-reverse; }  /* For hover-prev effect using ~ */
.btn-ai { background:linear-gradient(135deg,#8b5cf6 0%,#6d28d9 100%); color:#fff; border:none; font-weight:600; }
.btn-ai:hover { background:linear-gradient(135deg,#7c3aed 0%,#5b21b6 100%); color:#fff; }
.product-thumb { width:64px; height:64px; object-fit:contain; background:#f8fafc; border-radius:10px; padding:6px; }
</style>
</head>
<body>
<div class="review-card">
  <?php if (!$review): ?>
    <div class="text-center">
      <i class="bi bi-exclamation-triangle text-warning" style="font-size:48px;"></i>
      <h4 class="mt-3">Invalid Link</h4>
      <p class="text-muted small">This review link has expired or is invalid. Contact <a href="mailto:<?= esc(SITE_EMAIL) ?>"><?= esc(SITE_EMAIL) ?></a> for help.</p>
    </div>
  <?php elseif ($saved || $review['submitted_at']): ?>
    <div class="text-center">
      <i class="bi bi-check-circle-fill text-success" style="font-size:54px;"></i>
      <h3 class="mt-3">Thank you for your feedback!</h3>
      <p class="text-muted">Your review has been published and helps other customers find great software.</p>
      <a href="<?= esc(SITE_URL) ?>" class="btn btn-dark rounded-pill mt-3">Continue Shopping</a>
    </div>
  <?php else: ?>
    <div class="text-center mb-3">
      <div style="display:inline-flex;align-items:center;gap:10px;font-size:20px;font-weight:800;color:#0f172a;">
        <span style="width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:800;">M</span>
        <?= esc(SITE_BRAND) ?>
      </div>
    </div>
    <h3 class="text-center fw-bold">How was your purchase?</h3>
    <p class="text-center text-muted small">Hi <?= esc($review['customer_name']) ?>, we'd love your honest feedback on:</p>

    <?php if ($review['product_name']): ?>
      <div class="d-flex align-items-center gap-3 p-3 rounded mb-3" style="background:#f8fafc;">
        <?php if ($review['product_image']): ?><img src="<?= esc($review['product_image']) ?>" class="product-thumb"><?php endif; ?>
        <div>
          <div class="fw-bold"><?= esc($review['product_name']) ?></div>
          <small class="text-muted">Order #<?= esc($review['order_id'] ? 'MVT-'.$review['order_id'] : '—') ?></small>
        </div>
      </div>
    <?php endif; ?>

    <form method="post" id="reviewForm">
      <input type="hidden" name="ai_generated" id="aiFlag" value="0">

      <div class="stars" data-testid="star-rating">
        <?php for ($i=5; $i>=1; $i--): ?>
          <input type="radio" name="rating" id="r<?= $i ?>" value="<?= $i ?>" <?= $i==5?'checked':'' ?> onchange="onStarChange(<?= $i ?>)">
          <label for="r<?= $i ?>" class="<?= $i<=5?'lit':'' ?>"><i class="bi bi-star-fill"></i></label>
        <?php endfor; ?>
      </div>
      <div class="text-center small text-muted mb-3" id="ratingLabel">Excellent — 5 stars</div>

      <label class="form-label small fw-semibold">Your comment</label>
      <textarea class="form-control" name="comment" id="cmt" rows="4" placeholder="Tell other customers what you liked…" required></textarea>

      <button type="button" class="btn btn-ai w-100 mt-2 mb-3" onclick="aiWrite()" data-testid="ai-write-btn">
        <i class="bi bi-magic"></i> ✨ Help me write — generate based on my rating
      </button>

      <button class="btn btn-dark w-100 rounded-pill py-2 fw-bold" data-testid="submit-review">Submit Review</button>
      <p class="small text-muted text-center mt-3 mb-0">Your review will appear on our website to help other customers.</p>
    </form>

    <script>
    const labels = {1:'Poor — 1 star',2:'Below average — 2 stars',3:'Okay — 3 stars',4:'Good — 4 stars',5:'Excellent — 5 stars'};
    function onStarChange(n){
      document.getElementById('ratingLabel').textContent = labels[n];
      document.querySelectorAll('.stars label').forEach((l,i)=>{
        var val = 5-i; // reversed because of flex-direction:row-reverse
        l.classList.toggle('lit', val<=n);
      });
    }
    function getRating(){
      return parseInt(document.querySelector('input[name="rating"]:checked').value);
    }
    async function aiWrite() {
      var btn = event.currentTarget; btn.disabled=true; var orig=btn.innerHTML;
      btn.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Writing…';
      try {
        var r = await fetch('review-ai.php?rating=' + getRating() + '&product=' + encodeURIComponent('<?= esc(addslashes($review['product_name'] ?? 'this product')) ?>'));
        var d = await r.json();
        if (d.comment) {
          document.getElementById('cmt').value = d.comment;
          document.getElementById('aiFlag').value = '1';
        } else {
          alert('AI service unavailable — please type your comment manually.');
        }
      } catch (e) { alert('Network error: ' + e.message); }
      btn.disabled=false; btn.innerHTML=orig;
    }
    </script>
  <?php endif; ?>
</div>
</body>
</html>
