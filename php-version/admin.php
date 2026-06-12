<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/email.php';
ensure_admin();
$admin = require_admin();
$pageTitle = 'Admin Panel | ' . SITE_BRAND;
$tab = $_GET['tab'] ?? 'dashboard';
$flash = '';
$pdo = db();

// =========================================================================
// POST ACTIONS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_product') {
        $stmt = $pdo->prepare('UPDATE products SET price=?, original_price=?, badge=? WHERE slug=?');
        $stmt->execute([(float)$_POST['price'],
            $_POST['original_price'] !== '' ? (float)$_POST['original_price'] : null,
            trim($_POST['badge']) !== '' ? trim($_POST['badge']) : null,
            $_POST['slug']]);
        $flash = 'Product updated.'; $tab='products';

    } elseif ($action === 'update_order') {
        $allowed = ['pending','paid','delivered','refunded','cancelled'];
        if (in_array($_POST['status'], $allowed, true)) {
            $pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$_POST['status'], (int)$_POST['order_id']]);
            if ($_POST['status']==='paid') fulfill_order((int)$_POST['order_id']);
            $flash = 'Order updated.';
        }
        $tab='orders';

    } elseif ($action === 'resend_email') {
        $oid = (int)$_POST['order_id'];
        $pdo->prepare('UPDATE orders SET fulfilled=0 WHERE id=?')->execute([$oid]);
        fulfill_order($oid);
        $flash = 'Email re-generated.'; $tab='orders';

    } elseif ($action === 'add_keys') {
        $keys = array_filter(array_map('trim', explode("\n", $_POST['keys'] ?? '')));
        $stmt = $pdo->prepare('INSERT INTO license_keys (product_slug, license_key) VALUES (?, ?)');
        foreach ($keys as $k) try { $stmt->execute([$_POST['product_slug'], $k]); } catch (Exception $e) {}
        $flash = count($keys).' key(s) added.'; $tab='keys';

    } elseif ($action === 'delete_key') {
        $pdo->prepare('DELETE FROM license_keys WHERE id=? AND status="available"')->execute([(int)$_POST['key_id']]);
        $flash='Key removed.'; $tab='keys';

    } elseif ($action === 'save_template') {
        setting_set('email_template_subject', trim($_POST['subject']));
        setting_set('email_template_html', $_POST['html']);
        $flash = 'Email template saved.'; $tab='template';

    } elseif ($action === 'reset_template') {
        setting_set('email_template_html', '');
        $flash = 'Template reset to default.'; $tab='template';

    } elseif ($action === 'save_settings') {
        setting_set('statement_name_card',   trim($_POST['statement_name_card']));
        setting_set('statement_name_paypal', trim($_POST['statement_name_paypal']));
        setting_set('paypal_enabled', isset($_POST['paypal_enabled']) ? '1' : '0');
        $flash = 'Settings saved.'; $tab='settings';
    }
}

// =========================================================================
// HEADER STATS
// =========================================================================
$stats = [
    'Products'   => $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
    'Orders'     => $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    'Paid'       => $pdo->query('SELECT COUNT(*) FROM orders WHERE status="paid"')->fetchColumn(),
    'Customers'  => $pdo->query('SELECT COUNT(DISTINCT email) FROM orders')->fetchColumn(),
    'Keys Avail' => $pdo->query('SELECT COUNT(*) FROM license_keys WHERE status="available"')->fetchColumn(),
    'Keys Sold'  => $pdo->query('SELECT COUNT(*) FROM license_keys WHERE status="sold"')->fetchColumn(),
];

// Dashboard data
$dash = null;
if ($tab === 'dashboard') {
    $rev = $pdo->query("SELECT COALESCE(SUM(total),0) AS revenue, COUNT(*) AS cnt FROM orders WHERE status IN ('paid','delivered')")->fetch();
    $rev7 = $pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ('paid','delivered') AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    $byDayRows = $pdo->query("SELECT DATE(created_at) AS d, SUM(total) AS revenue, COUNT(*) AS orders FROM orders WHERE status IN ('paid','delivered') AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(created_at)")->fetchAll();
    $dayMap = []; foreach ($byDayRows as $r) $dayMap[$r['d']] = $r;
    $days = [];
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $days[] = ['date'=>$d, 'revenue'=>(float)($dayMap[$d]['revenue'] ?? 0), 'orders'=>(int)($dayMap[$d]['orders'] ?? 0)];
    }
    $best = $pdo->query("SELECT oi.product_slug, oi.name, p.image, SUM(oi.qty) AS units, SUM(oi.price*oi.qty) AS revenue
        FROM order_items oi JOIN orders o ON o.id=oi.order_id LEFT JOIN products p ON p.slug=oi.product_slug
        WHERE o.status IN ('paid','delivered') GROUP BY oi.product_slug,oi.name,p.image ORDER BY units DESC LIMIT 8")->fetchAll();
    $dash = [
        'revenue'=>(float)$rev['revenue'], 'paid'=>(int)$rev['cnt'],
        'avg'=>$rev['cnt'] ? (float)$rev['revenue']/(int)$rev['cnt'] : 0,
        'rev7'=>(float)$rev7, 'days'=>$days, 'best'=>$best,
        'max_day'=>max(array_column($days,'revenue') ?: [0]),
    ];
}

$adminActive = $tab;
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/admin-sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <div>
    <h1 class="h4 fw-bold mb-1" data-testid="admin-page-title">
      <i class="bi bi-speedometer2 text-primary me-2"></i><?= esc(ucfirst($tab)) ?>
    </h1>
    <small class="text-secondary">Signed in as <?= esc($admin['email']) ?></small>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php foreach ($stats as $label => $value): ?>
      <div class="card-elegant px-3 py-2 text-center" style="min-width:90px;">
        <div class="fw-bold fs-5"><?= (int)$value ?></div>
        <small class="text-secondary" style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;"><?= esc($label) ?></small>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert-success py-2 small" data-testid="admin-flash"><?= esc($flash) ?></div><?php endif; ?>

<?php // ====================================================================
// DASHBOARD
// ===================================================================== ?>
<?php if ($tab === 'dashboard' && $dash): ?>
  <div class="row g-3 mb-3" data-testid="admin-dashboard">
    <div class="col-6 col-md-3"><div class="card-elegant p-3"><small class="text-secondary text-uppercase fw-semibold" style="font-size:11px;letter-spacing:1px;">Revenue</small><div class="fs-4 fw-bold text-success">$<?= number_format($dash['revenue'],2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card-elegant p-3"><small class="text-secondary text-uppercase fw-semibold" style="font-size:11px;letter-spacing:1px;">Paid Orders</small><div class="fs-4 fw-bold" style="color:#3b82f6"><?= $dash['paid'] ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card-elegant p-3"><small class="text-secondary text-uppercase fw-semibold" style="font-size:11px;letter-spacing:1px;">Avg Order</small><div class="fs-4 fw-bold">$<?= number_format($dash['avg'],2) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card-elegant p-3"><small class="text-secondary text-uppercase fw-semibold" style="font-size:11px;letter-spacing:1px;">Last 7 days</small><div class="fs-4 fw-bold">$<?= number_format($dash['rev7'],2) ?></div></div></div>
  </div>

  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card-elegant p-4">
        <h6 class="fw-bold mb-3">Revenue — Last 30 Days</h6>
        <div class="d-flex align-items-end gap-1" style="height:220px;">
          <?php foreach ($dash['days'] as $d):
            $h = $dash['max_day'] > 0 ? max(2, round($d['revenue']/$dash['max_day']*200)) : 2; ?>
            <div class="flex-grow-1 rounded-top" style="height:<?= $h ?>px;min-width:5px;background:#3b82f6;opacity:<?= $d['revenue']>0?'1':'.15' ?>;"
              title="<?= esc($d['date']) ?> — $<?= number_format($d['revenue'],2) ?>"></div>
          <?php endforeach; ?>
        </div>
        <div class="d-flex justify-content-between small text-secondary mt-2">
          <span><?= esc($dash['days'][0]['date']) ?></span><span><?= esc(end($dash['days'])['date']) ?></span>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card-elegant p-4">
        <h6 class="fw-bold mb-3">Top Sellers</h6>
        <?php if (!$dash['best']): ?><p class="text-secondary small mb-0">No paid orders yet.</p><?php endif; ?>
        <?php foreach ($dash['best'] as $i=>$b): ?>
          <div class="d-flex align-items-center gap-2 py-2 <?= $i?'border-top':'' ?>">
            <span class="badge rounded-pill" style="background:#dbeafe;color:#1d4ed8;"><?= $i+1 ?></span>
            <?php if ($b['image']): ?><img src="<?= esc($b['image']) ?>" style="width:28px;height:28px;object-fit:contain;"><?php endif; ?>
            <span class="small flex-grow-1"><?= esc($b['name']) ?></span>
            <span class="text-end small"><strong><?= (int)$b['units'] ?></strong><br><span class="text-success">$<?= number_format($b['revenue'],2) ?></span></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

<?php // ====================================================================
// PRODUCTS — glow + button + key stats per product
// ===================================================================== ?>
<?php elseif ($tab === 'products'):
  $products = $pdo->query("SELECT p.*,
      (SELECT COUNT(*) FROM license_keys lk WHERE lk.product_slug=p.slug AND lk.status='available') AS avail_keys,
      (SELECT COUNT(*) FROM license_keys lk WHERE lk.product_slug=p.slug AND lk.status='sold') AS sold_keys
    FROM products p ORDER BY p.name")->fetchAll();
?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold mb-0">All Products <span class="text-secondary fs-6">(<?= count($products) ?>)</span></h5>
    <a href="inventory.php" class="btn-add-glow" data-testid="add-product-btn" title="Manage products via Inventory">
      <i class="bi bi-plus-lg"></i>
    </a>
  </div>

  <div class="row g-3" data-testid="admin-products-grid">
    <?php foreach ($products as $p): $fid = 'pf-'.preg_replace('/[^a-z0-9]/i','_',$p['slug']); ?>
      <div class="col-md-6 col-xl-4">
        <div class="card-elegant p-3 h-100">
          <div class="d-flex align-items-start gap-3 mb-3">
            <?php if ($p['image']): ?>
              <img src="<?= esc($p['image']) ?>" alt="" style="width:64px;height:64px;object-fit:contain;background:#f8fafc;border-radius:8px;padding:6px;">
            <?php else: ?>
              <div style="width:64px;height:64px;background:#f1f5f9;border-radius:8px;"></div>
            <?php endif; ?>
            <div class="flex-grow-1 min-width-0">
              <div class="fw-semibold small text-truncate" title="<?= esc($p['name']) ?>"><?= esc($p['name']) ?></div>
              <div class="small text-secondary"><?= esc($p['platform']) ?> · <?= esc($p['category']) ?></div>
              <a href="inventory.php?view=product&slug=<?= esc($p['slug']) ?>&tab=keys" class="small" style="color:#3b82f6">Manage keys →</a>
            </div>
          </div>

          <!-- Key inventory pills -->
          <div class="key-stats mb-3">
            <div class="key-pill avail">
              <div class="num text-success"><?= (int)$p['avail_keys'] ?></div>
              <div class="lbl">Available</div>
            </div>
            <div class="key-pill sold">
              <div class="num" style="color:#3b82f6;"><?= (int)$p['sold_keys'] ?></div>
              <div class="lbl">Sold</div>
            </div>
          </div>

          <form method="post" id="<?= $fid ?>">
            <input type="hidden" name="action" value="update_product">
            <input type="hidden" name="slug" value="<?= esc($p['slug']) ?>">
            <div class="row g-2">
              <div class="col-6"><label class="form-label small mb-0">Price</label><input name="price" type="number" step="0.01" class="form-control form-control-sm" value="<?= esc($p['price']) ?>"></div>
              <div class="col-6"><label class="form-label small mb-0">Original</label><input name="original_price" type="number" step="0.01" class="form-control form-control-sm" value="<?= esc($p['original_price'] ?? '') ?>"></div>
              <div class="col-12"><label class="form-label small mb-0">Badge</label><input name="badge" class="form-control form-control-sm" value="<?= esc($p['badge'] ?? '') ?>" placeholder="e.g. Hot Pick"></div>
            </div>
            <button class="btn btn-soft-blue btn-sm w-100 mt-2"><i class="bi bi-check2 me-1"></i>Save</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

<?php // ====================================================================
// ORDERS — elegant
// ===================================================================== ?>
<?php elseif ($tab === 'orders'): ?>
  <div class="tbl-elegant">
    <table class="table mb-0" data-testid="admin-orders-table">
      <thead><tr><th>Order#</th><th>Customer</th><th>Total</th><th>Payment</th><th>Statement</th><th>Status</th><th>Fulfillment</th></tr></thead>
      <tbody>
        <?php foreach ($pdo->query('SELECT * FROM orders ORDER BY created_at DESC LIMIT 200') as $o): ?>
          <tr>
            <td><strong>#<?= esc($o['order_number']) ?></strong><br><small class="text-secondary"><?= esc(date('M j, Y', strtotime($o['created_at']))) ?></small></td>
            <td><?= esc($o['first_name'].' '.$o['last_name']) ?><br><small class="text-secondary"><?= esc($o['email']) ?></small></td>
            <td class="fw-bold">$<?= number_format((float)$o['total'],2) ?></td>
            <td><span class="s-badge sent"><?= esc($o['payment_method']) ?></span></td>
            <td><small><?= esc($o['card_statement_name'] ?: statement_name_for($o['payment_method'])) ?></small></td>
            <td>
              <form method="post"><input type="hidden" name="action" value="update_order"><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:120px;">
                  <?php foreach (['pending','paid','delivered','refunded','cancelled'] as $s): ?>
                    <option value="<?= $s ?>" <?= $o['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <td>
              <span class="s-badge <?= $o['fulfilled']?'delivered':'pending' ?>"><?= $o['fulfilled']?'Fulfilled':'Pending' ?></span>
              <form method="post" class="d-inline mt-1">
                <input type="hidden" name="action" value="resend_email"><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                <button class="btn btn-soft-gray btn-sm py-0 px-2"><i class="bi bi-envelope"></i> Resend</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php // ====================================================================
// SALES DETAIL — Customer + Product + License Key delivered
// ===================================================================== ?>
<?php elseif ($tab === 'sales'):
  $sales = $pdo->query("SELECT o.*, oi.name AS product_name, oi.product_slug, oi.price AS unit_price, oi.qty,
        lk.license_key, lk.assigned_at,
        em.status AS email_status, em.opened_at, em.opened_count, em.delivered_at AS email_delivered_at
      FROM orders o
      JOIN order_items oi ON oi.order_id = o.id
      LEFT JOIN license_keys lk ON lk.order_id = o.id AND lk.product_slug = oi.product_slug
      LEFT JOIN email_outbox em ON em.order_id = o.id
      WHERE o.status IN ('paid','delivered')
      ORDER BY o.created_at DESC LIMIT 500")->fetchAll();
?>
  <h5 class="fw-bold mb-3">Sales Detail — Customer · Product · License Key · Email Status</h5>
  <div class="tbl-elegant">
    <table class="table mb-0">
      <thead><tr>
        <th>Date</th><th>Order#</th><th>Customer</th><th>Phone / Country</th>
        <th>Product</th><th>Amount</th><th>License Key Sent</th><th>Email</th><th>Statement</th>
      </tr></thead>
      <tbody>
        <?php if (empty($sales)): ?>
          <tr><td colspan="9" class="text-center text-secondary py-4">No paid sales yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($sales as $s):
          $emStatus = $s['opened_at'] ? 'opened' : ($s['email_status'] ?: 'pending'); ?>
          <tr>
            <td><small><?= esc(date('M j, Y', strtotime($s['created_at']))) ?></small></td>
            <td><strong>#<?= esc($s['order_number']) ?></strong></td>
            <td>
              <div class="fw-semibold small"><?= esc($s['first_name'].' '.$s['last_name']) ?></div>
              <small class="text-secondary"><?= esc($s['email']) ?></small>
            </td>
            <td>
              <small><?= esc($s['phone'] ?: '—') ?><br>
              <span class="text-secondary"><?= esc($s['city']).', '.esc($s['country']) ?></span></small>
            </td>
            <td><small><?= esc($s['product_name']) ?> ×<?= (int)$s['qty'] ?></small></td>
            <td><strong>$<?= number_format((float)$s['unit_price']*(int)$s['qty'],2) ?></strong></td>
            <td>
              <?php if ($s['license_key']): ?>
                <code style="background:#eff6ff;color:#1d4ed8;padding:3px 8px;border-radius:6px;font-size:12px;"><?= esc($s['license_key']) ?></code>
                <br><small class="text-secondary"><?= esc(date('M j H:i', strtotime($s['assigned_at']))) ?></small>
              <?php else: ?><span class="text-secondary small">— pending —</span><?php endif; ?>
            </td>
            <td>
              <span class="s-badge <?= $emStatus ?>"><?= esc($emStatus) ?></span>
              <?php if ($s['opened_at']): ?>
                <br><small class="text-secondary">opened <?= (int)$s['opened_count'] ?>× · <?= esc(date('M j H:i', strtotime($s['opened_at']))) ?></small>
              <?php elseif ($s['email_delivered_at']): ?>
                <br><small class="text-secondary">delivered <?= esc(date('M j H:i', strtotime($s['email_delivered_at']))) ?></small>
              <?php endif; ?>
            </td>
            <td><small><?= esc($s['card_statement_name'] ?: statement_name_for($s['payment_method'])) ?></small></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php // ====================================================================
// LEADS
// ===================================================================== ?>
<?php elseif ($tab === 'leads'): ?>
  <div class="tbl-elegant">
    <table class="table mb-0" data-testid="admin-leads-table">
      <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Callback</th><th>Message</th><th>When</th></tr></thead>
      <tbody>
        <?php foreach ($pdo->query('SELECT * FROM chat_leads ORDER BY created_at DESC LIMIT 200') as $l): ?>
          <tr>
            <td class="fw-semibold"><?= esc($l['name'] ?: '—') ?></td>
            <td><small><?= esc($l['email'] ?: '—') ?></small></td>
            <td><small><?= esc($l['phone'] ?: '—') ?></small></td>
            <td><?= $l['callback_requested']?'<span class="s-badge sent">Callback</span>':'<span class="text-secondary small">No</span>' ?></td>
            <td class="text-secondary"><small><?= esc(mb_strimwidth((string)$l['message'],0,80,'…')) ?></small></td>
            <td><small class="text-secondary"><?= esc(date('M j, Y', strtotime($l['created_at']))) ?></small></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php // ====================================================================
// KEY INVENTORY  (with glow + button)
// ===================================================================== ?>
<?php elseif ($tab === 'keys'): ?>
  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card-elegant p-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <h6 class="fw-bold mb-0">Add License Keys</h6>
          <button type="button" class="btn-add-glow" style="width:42px;height:42px;font-size:18px;" onclick="document.getElementById('keysTextarea').focus()" data-testid="add-keys-glow"><i class="bi bi-plus-lg"></i></button>
        </div>
        <form method="post">
          <input type="hidden" name="action" value="add_keys">
          <select name="product_slug" required class="form-select mb-2">
            <option value="">Select a product…</option>
            <?php foreach ($pdo->query('SELECT slug,name FROM products ORDER BY name') as $p): ?>
              <option value="<?= esc($p['slug']) ?>"><?= esc($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <textarea id="keysTextarea" name="keys" rows="7" required class="form-control font-monospace mb-2" placeholder="One key per line&#10;XXXXX-XXXXX-XXXXX-XXXXX"></textarea>
          <button class="btn btn-soft-blue w-100"><i class="bi bi-plus-circle me-1"></i>Add to Inventory</button>
        </form>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="tbl-elegant" style="max-height:560px;overflow-y:auto;">
        <table class="table mb-0">
          <thead><tr><th>Key</th><th>Product</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($pdo->query('SELECT lk.*, p.name FROM license_keys lk LEFT JOIN products p ON p.slug=lk.product_slug ORDER BY lk.created_at DESC LIMIT 300') as $k): ?>
              <tr>
                <td><code style="font-size:12px;"><?= esc($k['license_key']) ?></code></td>
                <td><small><?= esc($k['name'] ?: $k['product_slug']) ?></small></td>
                <td><span class="s-badge <?= $k['status']==='available'?'paid':($k['status']==='sold'?'sent':'refunded') ?>"><?= esc($k['status']) ?></span></td>
                <td>
                  <?php if ($k['status']==='available'): ?>
                    <form method="post" class="d-inline"><input type="hidden" name="action" value="delete_key"><input type="hidden" name="key_id" value="<?= (int)$k['id'] ?>">
                      <button class="btn btn-soft-red btn-sm py-0 px-2"><i class="bi bi-trash"></i></button></form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

<?php // ====================================================================
// EMAILS — with tracking (sent / delivered / opened)
// ===================================================================== ?>
<?php elseif ($tab === 'emails'):
  $emCounts = $pdo->query("SELECT
      SUM(status='queued') q, SUM(status='sent') s,
      SUM(opened_at IS NOT NULL) o, SUM(status='failed') f,
      COUNT(*) t FROM email_outbox")->fetch();
?>
  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="card-elegant p-3"><small class="text-secondary text-uppercase fw-semibold" style="font-size:11px;letter-spacing:1px;">Sent</small><div class="fs-4 fw-bold" style="color:#3b82f6"><?= (int)$emCounts['s'] ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card-elegant p-3"><small class="text-secondary text-uppercase fw-semibold" style="font-size:11px;letter-spacing:1px;">Opened</small><div class="fs-4 fw-bold text-success"><?= (int)$emCounts['o'] ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card-elegant p-3"><small class="text-secondary text-uppercase fw-semibold" style="font-size:11px;letter-spacing:1px;">Queued</small><div class="fs-4 fw-bold" style="color:#d97706"><?= (int)$emCounts['q'] ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card-elegant p-3"><small class="text-secondary text-uppercase fw-semibold" style="font-size:11px;letter-spacing:1px;">Failed</small><div class="fs-4 fw-bold text-danger"><?= (int)$emCounts['f'] ?></div></div></div>
  </div>

  <div class="tbl-elegant">
    <table class="table mb-0" data-testid="admin-emails-table">
      <thead><tr><th>Recipient</th><th>Subject</th><th>Order</th><th>Send Status</th><th>Open Status</th><th>Date</th><th></th></tr></thead>
      <tbody>
        <?php
        $ems = $pdo->query("SELECT em.*, o.order_number FROM email_outbox em
          LEFT JOIN orders o ON o.id=em.order_id ORDER BY em.created_at DESC LIMIT 200");
        foreach ($ems as $e):
          $sendStatus = $e['status']; // queued / sent / failed
          $openStatus = $e['opened_at'] ? 'opened' : ($e['status']==='sent' ? 'delivered' : 'not viewed');
        ?>
          <tr>
            <td><small><?= esc($e['recipient']) ?></small></td>
            <td><small><?= esc($e['subject']) ?></small></td>
            <td><?= $e['order_number'] ? '<code>#'.esc($e['order_number']).'</code>' : '—' ?></td>
            <td><span class="s-badge <?= $sendStatus ?>"><?= esc($sendStatus) ?></span>
              <?php if ($e['note']): ?><br><small class="text-secondary"><?= esc($e['note']) ?></small><?php endif; ?>
            </td>
            <td>
              <?php if ($e['opened_at']): ?>
                <span class="s-badge opened">Opened <?= (int)$e['opened_count'] ?>×</span>
                <br><small class="text-secondary"><?= esc(date('M j H:i', strtotime($e['opened_at']))) ?></small>
              <?php elseif ($e['status']==='sent'): ?>
                <span class="s-badge delivered">Delivered</span>
                <br><small class="text-secondary">not viewed</small>
              <?php else: ?>
                <span class="text-secondary small">—</span>
              <?php endif; ?>
            </td>
            <td><small class="text-secondary"><?= esc(date('M j, Y H:i', strtotime($e['created_at']))) ?></small></td>
            <td><a class="btn btn-soft-gray btn-sm py-0 px-2" href="admin-email-preview.php?id=<?= (int)$e['id'] ?>" target="_blank"><i class="bi bi-eye"></i></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php // ====================================================================
// EMAIL TEMPLATE EDITOR
// ===================================================================== ?>
<?php elseif ($tab === 'template'):
  $tplSubject = setting_get('email_template_subject', 'Your Microsoft product key — Order #{{order_number}}');
  $tplHtml    = setting_get('email_template_html', '');
  if (trim($tplHtml) === '') $tplHtml = default_email_template();
?>
  <h5 class="fw-bold mb-1">Email Template</h5>
  <p class="text-secondary small mb-3">This template is sent automatically after a successful purchase. Use placeholders in double braces.</p>

  <div class="alert alert-info py-2 small mb-3">
    <strong>Available placeholders:</strong>
    <code>&#123;&#123;company_name&#125;&#125;</code>
    <code>&#123;&#123;customer_name&#125;&#125;</code>
    <code>&#123;&#123;customer_email&#125;&#125;</code>
    <code>&#123;&#123;order_number&#125;&#125;</code>
    <code>&#123;&#123;amount&#125;&#125;</code>
    <code>&#123;&#123;statement_name&#125;&#125;</code>
    <code>&#123;&#123;products_block&#125;&#125;</code>
    <code>&#123;&#123;installation_guide&#125;&#125;</code>
    <code>&#123;&#123;support_email&#125;&#125;</code>
    <code>&#123;&#123;support_phone&#125;&#125;</code>
    <code>&#123;&#123;year&#125;&#125;</code>
    <code>&#123;&#123;tracking_pixel&#125;&#125;</code>
  </div>

  <form method="post">
    <input type="hidden" name="action" value="save_template">
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label small fw-semibold">Email Subject</label>
        <input class="form-control" name="subject" value="<?= esc($tplSubject) ?>" data-testid="tpl-subject">
      </div>
      <div class="col-lg-7">
        <label class="form-label small fw-semibold">HTML Template</label>
        <textarea class="form-control font-monospace" name="html" rows="22" id="tplHtml" data-testid="tpl-html" style="font-size:12px;"><?= esc($tplHtml) ?></textarea>
      </div>
      <div class="col-lg-5">
        <label class="form-label small fw-semibold">Live Preview (sample data)</label>
        <iframe id="tplPreview" class="card-elegant" style="width:100%;height:500px;background:#fff;border:1px solid #eef0f3;border-radius:12px;"></iframe>
      </div>
    </div>
    <div class="d-flex gap-2 mt-3">
      <button class="btn btn-soft-blue"><i class="bi bi-check2 me-1"></i> Save Template</button>
      <button type="button" class="btn btn-soft-gray" onclick="renderPreview()"><i class="bi bi-arrow-clockwise me-1"></i> Refresh Preview</button>
      <button type="submit" name="action" value="reset_template" formnovalidate class="btn btn-soft-red ms-auto" onclick="return confirm('Reset template to default?')"><i class="bi bi-arrow-counterclockwise me-1"></i> Reset to Default</button>
    </div>
  </form>

  <script>
    function renderPreview() {
      var html = document.getElementById('tplHtml').value;
      var sample = {
        company_name: '<?= esc(SITE_BRAND) ?>',
        customer_name: 'John Smith',
        customer_email: 'john@example.com',
        order_number: 'MVT-2026-0042',
        amount: '129.99',
        statement_name: '<?= esc(setting_get('statement_name_card','MAVENTECH SOFTWARE')) ?>',
        support_email: '<?= esc(SITE_EMAIL) ?>',
        support_phone: '<?= esc(SITE_PHONE) ?>',
        year: new Date().getFullYear(),
        installation_guide: '1. Download installer.<br>2. Run setup.<br>3. Enter license key.<br>4. Activate.',
        products_block: '<div style="border:1px solid #eef0f3;border-radius:12px;padding:14px;background:#fff;"><div style="font-weight:700;color:#0f172a;">Sample Product — Office 2024 Pro Plus</div><div style="margin-top:10px;border:2px dashed #3b82f6;border-radius:10px;background:#eff6ff;padding:12px;text-align:center;"><div style="font-size:10px;color:#64748b;letter-spacing:1.5px;text-transform:uppercase;">License Key</div><div style="font-family:monospace;font-weight:bold;color:#1d4ed8;font-size:17px;letter-spacing:1.8px;">XXXXX-YYYYY-ZZZZZ-AAAAA</div></div></div>',
        tracking_pixel: ''
      };
      Object.keys(sample).forEach(function(k){ html = html.split('{{'+k+'}}').join(sample[k]); });
      document.getElementById('tplPreview').srcdoc = html;
    }
    renderPreview();
    document.getElementById('tplHtml').addEventListener('input', function(){ clearTimeout(window._t); window._t = setTimeout(renderPreview, 400); });
  </script>

<?php // ====================================================================
// SETTINGS — Statement names + PayPal toggle
// ===================================================================== ?>
<?php elseif ($tab === 'settings'):
  $stmtCard   = setting_get('statement_name_card','MAVENTECH SOFTWARE');
  $stmtPaypal = setting_get('statement_name_paypal','MAVENTECH SOFTWARE LLC');
  $paypalOn   = setting_get('paypal_enabled','0') === '1';
  $paypalKey  = getenv('PAYPAL_CLIENT_ID') ?: (defined('PAYPAL_CLIENT_ID') ? PAYPAL_CLIENT_ID : '');
?>
  <h5 class="fw-bold mb-1">Payment &amp; Statement Settings</h5>
  <p class="text-secondary small mb-3">Configure the company name shown on customer card statements, and toggle PayPal availability on the website.</p>

  <form method="post" class="card-elegant p-4" style="max-width:780px;">
    <input type="hidden" name="action" value="save_settings">

    <h6 class="fw-bold mb-3"><i class="bi bi-credit-card me-1"></i> Card Statement Names</h6>
    <p class="small text-secondary mb-3">These names appear on the customer's bank/card statement. The customer receives this in their email so they recognize the charge.</p>
    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <label class="form-label small fw-semibold">Card / Stripe statement name</label>
        <input class="form-control" name="statement_name_card" value="<?= esc($stmtCard) ?>" maxlength="22" data-testid="stmt-card">
        <small class="text-secondary">Max 22 chars. Shows on Visa/MC/Amex/Discover statements.</small>
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-semibold">PayPal merchant name</label>
        <input class="form-control" name="statement_name_paypal" value="<?= esc($stmtPaypal) ?>" maxlength="60" data-testid="stmt-paypal">
        <small class="text-secondary">Shows when customer pays via PayPal.</small>
      </div>
    </div>

    <hr>
    <h6 class="fw-bold mb-3"><i class="bi bi-toggles me-1"></i> PayPal Availability</h6>
    <div class="form-check form-switch mb-2">
      <input class="form-check-input" type="checkbox" name="paypal_enabled" id="ppEn" <?= $paypalOn?'checked':'' ?> data-testid="paypal-toggle">
      <label class="form-check-label" for="ppEn">Show PayPal option on checkout</label>
    </div>
    <div class="small <?= $paypalKey ? 'text-success' : 'text-danger' ?>">
      PayPal API status: <strong><?= $paypalKey ? 'PAYPAL_CLIENT_ID detected ✓' : 'PAYPAL_CLIENT_ID NOT configured ✗' ?></strong>
    </div>
    <div class="alert alert-warning py-2 small mt-2 mb-3">
      <i class="bi bi-info-circle"></i> If PayPal API key is missing OR this toggle is off, the PayPal option is automatically <strong>hidden on the public checkout page</strong>. Only card payment will be shown.
    </div>

    <button class="btn btn-soft-blue"><i class="bi bi-check2 me-1"></i> Save Settings</button>
  </form>

<?php endif; ?>

<?php include __DIR__ . '/includes/admin-sidebar-end.php'; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
