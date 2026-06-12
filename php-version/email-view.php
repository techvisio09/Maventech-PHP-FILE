<?php
// Render the EXACT HTML that was sent to the customer (admin view).
require_once __DIR__ . '/includes/functions.php';
ensure_admin();
$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$em = $pdo->prepare('SELECT * FROM email_outbox WHERE id=?');
$em->execute([$id]); $em = $em->fetch();
if (!$em) { http_response_code(404); die('Email not found'); }

if (($_GET['raw'] ?? '') === '1') {
    // Render the original HTML in an isolated iframe context
    header('Content-Type: text/html; charset=utf-8');
    echo $em['html'];
    exit;
}

$pageTitle = 'Email Preview · ' . esc($em['subject']);
$adminActive = 'emails';
include __DIR__ . '/includes/admin-shell.php';
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <div>
    <a href="admin.php?tab=emails" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to Email Activity</a>
    <h1 class="h5 fw-bold mb-0 mt-1" data-testid="email-preview-title">Email Preview</h1>
    <small class="text-muted">Exactly as <?= esc($em['recipient']) ?> received it</small>
  </div>
  <div class="d-flex gap-2">
    <span class="s-badge <?= esc($em['status']) ?>"><?= esc($em['status']) ?></span>
    <?php if ($em['opened_at']): ?><span class="s-badge opened">Opened <?= (int)$em['opened_count'] ?>×</span><?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card-e p-4">
      <h6 class="fw-bold mb-3"><i class="bi bi-info-circle text-primary me-2"></i>Email Metadata</h6>
      <table class="table table-sm mb-0" style="background:transparent;color:var(--text);">
        <tr><th>Subject Line</th><td><?= esc($em['subject']) ?></td></tr>
        <tr><th>Recipient</th><td><?= esc($em['recipient']) ?></td></tr>
        <tr><th>Template</th><td><?= esc($em['template_code'] ?: 'inline') ?></td></tr>
        <tr><th>Provider</th><td><?= esc($em['provider_id'] ?: '—') ?></td></tr>
        <tr><th>Sent</th><td><?= esc(date('M j, Y H:i', strtotime($em['created_at']))) ?></td></tr>
        <tr><th>Delivered</th><td><?= $em['delivered_at'] ? esc(date('M j, Y H:i', strtotime($em['delivered_at']))) : '—' ?></td></tr>
        <tr><th>Opened</th><td><?= $em['opened_at'] ? esc(date('M j, Y H:i', strtotime($em['opened_at']))) : 'not yet' ?></td></tr>
        <tr><th>Open Count</th><td><?= (int)$em['opened_count'] ?></td></tr>
      </table>
      <?php if ($em['order_id']): ?>
        <hr>
        <a href="order-view.php?id=<?= (int)$em['order_id'] ?>" class="btn btn-soft-blue btn-sm"><i class="bi bi-receipt me-1"></i>View Linked Order</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card-e p-0">
      <div class="p-3 border-bottom d-flex justify-content-between align-items-center" style="background:var(--bg);">
        <small class="text-muted">Subject: <strong style="color:var(--text);"><?= esc($em['subject']) ?></strong></small>
        <small class="text-muted">To: <strong style="color:var(--text);"><?= esc($em['recipient']) ?></strong></small>
      </div>
      <iframe src="email-view.php?id=<?= (int)$id ?>&raw=1" data-testid="email-iframe"
              style="width:100%;height:780px;border:none;background:#fff;border-radius:0 0 12px 12px;"></iframe>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/admin-shell-end.php'; ?>
