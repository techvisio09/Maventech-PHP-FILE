<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/regions.php';
ensure_admin();
$admin = require_admin();
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$o = $pdo->prepare('SELECT * FROM orders WHERE id=?');
$o->execute([$id]); $o = $o->fetch();
if (!$o) { http_response_code(404); die('Order not found'); }

// Resend or status update
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (($_POST['action'] ?? '')==='resend_email') {
        $pdo->prepare('UPDATE orders SET fulfilled=0 WHERE id=?')->execute([$id]);
        fulfill_order($id);
        header('Location: order-view.php?id='.$id.'&msg=Email+resent'); exit;
    }
    if (($_POST['action'] ?? '')==='update_status') {
        $pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$_POST['status'], $id]);
        if ($_POST['status']==='paid') fulfill_order($id);
        header('Location: order-view.php?id='.$id.'&msg=Status+updated'); exit;
    }
}

$items = $pdo->prepare('SELECT oi.*, p.image, p.category, p.platform
  FROM order_items oi LEFT JOIN products p ON p.slug=oi.product_slug WHERE oi.order_id=?');
$items->execute([$id]); $items = $items->fetchAll();

$keys = $pdo->prepare('SELECT lk.*, oi.product_slug, oi.name AS product_name
  FROM license_keys lk JOIN order_items oi ON oi.product_slug=lk.product_slug AND oi.order_id=lk.order_id
  WHERE lk.order_id=?');
$keys->execute([$id]); $keys = $keys->fetchAll();
$keyMap = []; foreach ($keys as $k) $keyMap[$k['product_slug']][] = $k;

$em = $pdo->prepare('SELECT * FROM email_outbox WHERE order_id=? ORDER BY created_at DESC');
$em->execute([$id]); $emRows = $em->fetchAll();
$lastEmail = $emRows[0] ?? null;

$tl = json_decode($o['timeline'] ?? 'null', true) ?: [];

$pageTitle = 'Order #'.$o['order_number'].' · Admin';
$adminActive = 'orders';
include __DIR__ . '/includes/admin-shell.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <div>
    <a href="admin.php?tab=orders" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Back to orders</a>
    <h1 class="h4 fw-bold mb-0 mt-1" data-testid="order-title">Order #<?= esc($o['order_number']) ?></h1>
    <small class="text-muted">Placed <?= esc(date('M j, Y H:i', strtotime($o['created_at']))) ?> · Region <?= esc($o['region']) ?></small>
  </div>
  <div class="d-flex gap-2">
    <form method="post" class="d-inline">
      <input type="hidden" name="action" value="resend_email">
      <button class="btn btn-soft-blue btn-sm"><i class="bi bi-envelope me-1"></i> Resend Email</button>
    </form>
    <form method="post" class="d-inline">
      <input type="hidden" name="action" value="update_status">
      <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" style="width:130px;">
        <?php foreach (['pending','paid','delivered','refunded','cancelled'] as $s): ?>
          <option value="<?= $s ?>" <?= $o['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
</div>

<?php if (!empty($_GET['msg'])): ?><div class="alert alert-success py-2 small"><?= esc($_GET['msg']) ?></div><?php endif; ?>

<div class="row g-3">
  <!-- LEFT: Customer + Purchase + Payment -->
  <div class="col-lg-8">
    <div class="card-e p-4 mb-3" data-testid="customer-info">
      <h6 class="fw-bold mb-3"><i class="bi bi-person text-primary me-2"></i>Customer Information</h6>
      <div class="row g-2 small">
        <div class="col-6 col-md-4"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Full Name</div><div class="fw-semibold"><?= esc($o['first_name'].' '.$o['last_name']) ?></div></div>
        <div class="col-6 col-md-4"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Email</div><div class="fw-semibold"><?= esc($o['email']) ?></div></div>
        <div class="col-6 col-md-4"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Phone</div><div class="fw-semibold"><?= esc($o['phone'] ?: '—') ?></div></div>
        <div class="col-6 col-md-4"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Country</div><div class="fw-semibold"><?= esc($o['country'] ?: $o['billing_country'] ?: '—') ?></div></div>
        <div class="col-6 col-md-4"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">City / State</div><div class="fw-semibold"><?= esc($o['city'].', '.$o['state']) ?></div></div>
        <div class="col-6 col-md-4"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">IP Address</div><div class="fw-semibold"><?= esc($o['ip_address'] ?: '—') ?></div></div>
      </div>
    </div>

    <div class="card-e p-4 mb-3" data-testid="purchase-info">
      <h6 class="fw-bold mb-3"><i class="bi bi-box-seam text-primary me-2"></i>Purchase Information</h6>
      <?php foreach ($items as $it):
        $assigned = $keyMap[$it['product_slug']] ?? []; ?>
        <div class="d-flex gap-3 mb-3 pb-3 border-bottom">
          <?php if ($it['image']): ?><img src="<?= esc($it['image']) ?>" style="width:64px;height:64px;object-fit:contain;background:var(--bg);border-radius:8px;padding:6px;"><?php endif; ?>
          <div class="flex-grow-1">
            <div class="fw-bold"><?= esc($it['name']) ?></div>
            <div class="small text-muted"><?= esc($it['platform']) ?> · <?= esc($it['category']) ?> · Qty <?= (int)$it['qty'] ?> · <?= region_money((float)$it['price']) ?></div>
            <?php foreach ($assigned as $k): ?>
              <div class="mt-2"><span class="text-muted small">License Key:</span>
                <code style="background:var(--blue-soft);color:var(--brand-dk);padding:3px 10px;border-radius:6px;letter-spacing:1.2px;font-size:12.5px;"><?= esc($k['license_key']) ?></code>
                <span class="s-badge <?= $k['status']==='sold'?'paid':'queued' ?> ms-2"><?= esc($k['status']) ?></span>
              </div>
            <?php endforeach; ?>
            <?php if (!$assigned): ?><div class="mt-2 small text-muted">No key assigned yet.</div><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <div class="row small">
        <div class="col-6 col-md-3"><span class="text-muted">Purchase Date:</span><br><strong><?= esc(date('M j, Y H:i', strtotime($o['created_at']))) ?></strong></div>
        <div class="col-6 col-md-3"><span class="text-muted">Region:</span><br><strong><?= esc($o['region']) ?></strong></div>
        <div class="col-6 col-md-3"><span class="text-muted">Quantity:</span><br><strong><?= array_sum(array_column($items,'qty')) ?> item(s)</strong></div>
        <div class="col-6 col-md-3"><span class="text-muted">Total:</span><br><strong style="color:var(--green);font-size:16px;"><?= region_money((float)$o['total']) ?></strong></div>
      </div>
    </div>

    <div class="card-e p-4 mb-3" data-testid="payment-info">
      <h6 class="fw-bold mb-3"><i class="bi bi-credit-card text-primary me-2"></i>Payment Information</h6>
      <div class="row g-2 small">
        <div class="col-6 col-md-3"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Gateway</div><div class="fw-semibold text-capitalize"><?= esc($o['payment_method']) ?></div></div>
        <div class="col-6 col-md-3"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Card Brand</div><div class="fw-semibold"><?= esc($o['card_brand'] ?: '—') ?></div></div>
        <div class="col-6 col-md-3"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Card Type</div><div class="fw-semibold"><?= esc($o['card_type'] ?: '—') ?></div></div>
        <div class="col-6 col-md-3"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Currency</div><div class="fw-semibold"><?= esc($o['currency']) ?></div></div>
        <div class="col-12 col-md-6"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Transaction ID</div><div class="fw-semibold"><code><?= esc($o['transaction_id'] ?: '—') ?></code></div></div>
        <div class="col-6 col-md-3"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Payment Status</div><span class="s-badge <?= $o['status'] ?>"><?= esc($o['status']) ?></span></div>
        <div class="col-6 col-md-3"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Billing Country</div><div class="fw-semibold"><?= esc($o['billing_country'] ?: $o['country'] ?: '—') ?></div></div>
        <div class="col-12"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Card Statement Name</div><div class="fw-semibold"><?= esc($o['card_statement_name'] ?: statement_name_for($o['payment_method'])) ?></div></div>
      </div>
    </div>

    <div class="card-e p-4 mb-3" data-testid="fulfillment-info">
      <h6 class="fw-bold mb-3"><i class="bi bi-truck text-primary me-2"></i>Fulfillment Information</h6>
      <div class="row g-2 small">
        <div class="col-6 col-md-3"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">License Key Delivery</div>
          <span class="s-badge <?= !empty($keys)?'delivered':'queued' ?>"><?= !empty($keys)?'Assigned':'Pending' ?></span></div>
        <div class="col-6 col-md-3"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Email Delivery</div>
          <span class="s-badge <?= $lastEmail ? ($lastEmail['status']==='sent'?'delivered':$lastEmail['status']) : 'queued' ?>"><?= $lastEmail ? esc($lastEmail['status']) : 'not sent' ?></span></div>
        <div class="col-6 col-md-3"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Email Opened</div>
          <?= $lastEmail && $lastEmail['opened_at'] ? '<span class="s-badge opened">Opened '.(int)$lastEmail['opened_count'].'×</span>' : '<span class="text-muted">not viewed</span>' ?></div>
        <div class="col-6 col-md-3"><div class="text-muted text-uppercase" style="font-size:10px;letter-spacing:1px;">Install Guide Sent</div>
          <span class="s-badge <?= $lastEmail?'delivered':'queued' ?>">Embedded</span></div>
      </div>
      <?php if ($lastEmail): ?>
        <a href="email-view.php?id=<?= (int)$lastEmail['id'] ?>" target="_blank" class="btn btn-soft-gray btn-sm mt-3"><i class="bi bi-eye me-1"></i> Preview email exactly as customer received it</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- RIGHT: Order Timeline -->
  <div class="col-lg-4">
    <div class="card-e p-4 sticky-top" style="top: 90px;">
      <h6 class="fw-bold mb-3"><i class="bi bi-clock-history text-primary me-2"></i>Order Timeline</h6>
      <ul class="timeline" data-testid="order-timeline">
        <?php
        $stages = [
          ['order_created',     'Order Created',         'cart-check'],
          ['payment_completed', 'Payment Completed',     'credit-card-2-back-fill'],
          ['license_assigned',  'License Key Assigned',  'key-fill'],
          ['email_sent',        'Confirmation Email Sent','envelope-check'],
          ['email_delivered',   'Email Delivered',       'inbox-fill'],
          ['email_opened',      'Customer Opened Email', 'envelope-open'],
        ];
        // Hydrate from email tracking
        if ($lastEmail) {
          if ($lastEmail['delivered_at']) $tl['email_delivered'] = $tl['email_delivered'] ?? $lastEmail['delivered_at'];
          if ($lastEmail['opened_at'])    $tl['email_opened']    = $tl['email_opened']    ?? $lastEmail['opened_at'];
        }
        foreach ($stages as [$key,$label,$icon]):
          $done = !empty($tl[$key]);
        ?>
          <li class="<?= $done?'done':'' ?>">
            <div class="ttitle"><i class="bi bi-<?= $icon ?> me-1" style="color: <?= $done?'#10b981':'#94a3b8' ?>;"></i><?= esc($label) ?></div>
            <div class="tdate"><?= $done ? esc(date('M j, Y H:i', strtotime($tl[$key]))) : '— pending —' ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/admin-shell-end.php'; ?>
