<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/regions.php';
ensure_admin();
$admin = require_admin();
$pdo = db();
$tab = $_GET['tab'] ?? 'dashboard';
$flash = $_GET['msg'] ?? '';
$rg = active_region();
$region_code = active_region_code();

// =========================================================================
// POST ACTIONS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_product') {
        $pdo->prepare('UPDATE products SET price=?, original_price=?, badge=? WHERE slug=?')
            ->execute([(float)$_POST['price'], $_POST['original_price']!==''?(float)$_POST['original_price']:null,
                       trim($_POST['badge'])?:null, $_POST['slug']]);
        header('Location: admin.php?tab=products&msg=Saved'); exit;

    } elseif ($action === 'update_order') {
        $pdo->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$_POST['status'], (int)$_POST['order_id']]);
        if ($_POST['status']==='paid') fulfill_order((int)$_POST['order_id']);
        header('Location: admin.php?tab=orders&msg=Order+updated'); exit;

    } elseif ($action === 'resend_email') {
        $pdo->prepare('UPDATE orders SET fulfilled=0 WHERE id=?')->execute([(int)$_POST['order_id']]);
        fulfill_order((int)$_POST['order_id']);
        header('Location: admin.php?tab=orders&msg=Email+resent'); exit;

    } elseif ($action === 'add_keys') {
        $keys = array_filter(array_map('trim', explode("\n", $_POST['keys'] ?? '')));
        $stmt = $pdo->prepare('INSERT INTO license_keys (product_slug, license_key, region) VALUES (?,?,?)');
        $n=0; foreach ($keys as $k) try { $stmt->execute([$_POST['product_slug'], $k, $region_code]); $n++; } catch (Exception $e) {}
        header('Location: admin.php?tab=keys&msg='.$n.'+key(s)+added'); exit;

    } elseif ($action === 'delete_key') {
        $pdo->prepare('DELETE FROM license_keys WHERE id=? AND status="available"')->execute([(int)$_POST['key_id']]);
        header('Location: admin.php?tab=keys&msg=Key+removed'); exit;

    } elseif ($action === 'save_template') {
        $tplId = (int)$_POST['tpl_id'];
        $tpl = $pdo->prepare('SELECT * FROM email_templates WHERE id=?');
        $tpl->execute([$tplId]); $cur = $tpl->fetch();
        if ($cur) {
            // Save version snapshot before overwrite
            $pdo->prepare('INSERT INTO email_template_versions (template_id, version_num, subject, html, edited_by_email) VALUES (?,?,?,?,?)')
                ->execute([$tplId, $cur['current_version'], $cur['subject'], $cur['html'], $admin['email']]);
            $newV = $cur['current_version'] + 1;
            $pdo->prepare('UPDATE email_templates SET subject=?, html=?, current_version=?, active=? WHERE id=?')
                ->execute([trim($_POST['subject']), $_POST['html'], $newV, isset($_POST['active'])?1:0, $tplId]);
        }
        header('Location: admin.php?tab=templates&edit='.$tplId.'&msg=Template+saved'); exit;

    } elseif ($action === 'restore_template_version') {
        $tplId = (int)$_POST['tpl_id']; $vId = (int)$_POST['version_id'];
        $v = $pdo->prepare('SELECT * FROM email_template_versions WHERE id=? AND template_id=?');
        $v->execute([$vId, $tplId]); $ver = $v->fetch();
        if ($ver) {
            $cur = $pdo->prepare('SELECT * FROM email_templates WHERE id=?'); $cur->execute([$tplId]); $c = $cur->fetch();
            $pdo->prepare('INSERT INTO email_template_versions (template_id, version_num, subject, html, edited_by_email) VALUES (?,?,?,?,?)')
                ->execute([$tplId, $c['current_version'], $c['subject'], $c['html'], $admin['email']]);
            $pdo->prepare('UPDATE email_templates SET subject=?, html=?, current_version=current_version+1 WHERE id=?')
                ->execute([$ver['subject'], $ver['html'], $tplId]);
        }
        header('Location: admin.php?tab=templates&edit='.$tplId.'&msg=Version+restored'); exit;

    } elseif ($action === 'save_api') {
        $gw = $_POST['gateway']; // card | paypal
        if ($gw==='card') {
            setting_set('gw_card_status',         $_POST['status']);
            setting_set('gw_card_provider',       trim($_POST['provider']));
            setting_set('gw_card_merchant_name',  trim($_POST['merchant_name']));
            if (!empty($_POST['public_key']))     setting_set('gw_card_public_key', trim($_POST['public_key']));
            if (!empty($_POST['secret_key']))     setting_set('gw_card_secret_key', trim($_POST['secret_key']));
            if (!empty($_POST['webhook_secret'])) setting_set('gw_card_webhook_secret', trim($_POST['webhook_secret']));
        } else {
            setting_set('gw_paypal_status',       $_POST['status']);
            setting_set('gw_paypal_account_name', trim($_POST['account_name']));
            if (!empty($_POST['client_id']))      setting_set('gw_paypal_client_id', trim($_POST['client_id']));
            if (!empty($_POST['secret']))         setting_set('gw_paypal_secret', trim($_POST['secret']));
            if (!empty($_POST['webhook_id']))     setting_set('gw_paypal_webhook_id', trim($_POST['webhook_id']));
            setting_set('paypal_enabled', $_POST['status']==='active' ? '1' : '0');
        }
        header('Location: admin.php?tab=api&msg=API+settings+saved'); exit;

    } elseif ($action === 'update_lead') {
        $lid = (int)$_POST['lead_id'];
        $pdo->prepare('UPDATE chat_leads SET status=?, assigned_to=?, requested_product=? WHERE id=?')
            ->execute([$_POST['status'], $_POST['assigned_to']?:null, $_POST['requested_product']?:null, $lid]);
        if (!empty($_POST['note'])) {
            $pdo->prepare('INSERT INTO lead_notes (lead_id, note, author_name) VALUES (?,?,?)')
                ->execute([$lid, trim($_POST['note']), $admin['email']]);
        }
        header('Location: admin.php?tab=leads&open='.$lid.'&msg=Lead+updated'); exit;

    } elseif ($action === 'save_settings') {
        setting_set('statement_name_card',   trim($_POST['statement_name_card']));
        setting_set('statement_name_paypal', trim($_POST['statement_name_paypal']));
        header('Location: admin.php?tab=settings&msg=Settings+saved'); exit;

    } elseif ($action === 'save_region') {
        $code = strtoupper($_POST['region_code']);
        $pdo->prepare('UPDATE regions SET name=?, currency=?, currency_symbol=?, tax_rate=?, active=? WHERE code=?')
            ->execute([trim($_POST['name']), trim($_POST['currency']), trim($_POST['currency_symbol']),
                       (float)$_POST['tax_rate'], isset($_POST['active'])?1:0, $code]);
        header('Location: admin.php?tab=regions&msg=Region+updated'); exit;
    }
}

// =========================================================================
// Notifications: new leads in last 24h (used in nav bell)
// =========================================================================
$newLeadCount = (int)$pdo->query("SELECT COUNT(*) FROM chat_leads WHERE status='new' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();

$pageTitle = 'Admin · ' . ucfirst($tab) . ' · ' . SITE_BRAND;
$adminActive = in_array($tab, ['template','settings'], true) ? $tab : (in_array($tab,['order-view'])?'orders':$tab);
include __DIR__ . '/includes/admin-shell.php';
?>

<?php if ($flash): ?><div class="alert alert-success py-2 small" data-testid="admin-flash"><?= esc($flash) ?></div><?php endif; ?>

<?php
// ============================================================================
// DASHBOARD
// ============================================================================
if ($tab === 'dashboard'):
    $rev = (float)$pdo->prepare("SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ('paid','delivered') AND region=?")->execute([$region_code]) ?: 0;
    $rev = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ('paid','delivered') AND region=".$pdo->quote($region_code))->fetchColumn();
    $ord = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE region=".$pdo->quote($region_code))->fetchColumn();
    $cust= (int)$pdo->query("SELECT COUNT(DISTINCT email) FROM orders WHERE region=".$pdo->quote($region_code))->fetchColumn();
    $kAv = (int)$pdo->query("SELECT COUNT(*) FROM license_keys WHERE status='available' AND region=".$pdo->quote($region_code))->fetchColumn();
    $kSo = (int)$pdo->query("SELECT COUNT(*) FROM license_keys WHERE status='sold' AND region=".$pdo->quote($region_code))->fetchColumn();
    $opens = (int)$pdo->query("SELECT COUNT(*) FROM email_outbox WHERE opened_at IS NOT NULL")->fetchColumn();

    // API status snapshot
    $cardStatus = setting_get('gw_card_status','inactive');
    $ppStatus   = setting_get('gw_paypal_status','inactive');
?>
  <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
      <h1 class="h4 fw-bold mb-1">Dashboard · <span class="text-muted fs-6"><?= esc($rg['name']) ?> region</span></h1>
      <small class="text-muted">Real-time overview · Showing data for <strong><?= esc($rg['code']) ?></strong></small>
    </div>
    <?php if ($newLeadCount): ?>
      <a href="admin.php?tab=leads" class="card-e px-3 py-2 text-decoration-none" style="border-left:4px solid var(--amber);">
        <i class="bi bi-bell-fill text-warning"></i> <strong><?= $newLeadCount ?></strong> new lead(s) in last 24h →
      </a>
    <?php endif; ?>
  </div>

  <div class="row g-3 mb-3">
    <?php
    $tiles = [
      ['Revenue',          region_money($rev), '#10b981'],
      ['Orders',           number_format($ord), '#3b82f6'],
      ['Customers',        number_format($cust), '#8b5cf6'],
      ['Keys Available',   number_format($kAv), '#10b981'],
      ['Keys Sold',        number_format($kSo), '#3b82f6'],
      ['Emails Opened',    number_format($opens), '#06b6d4'],
    ];
    foreach ($tiles as $t): ?>
      <div class="col-6 col-md-4 col-xl-2"><div class="card-e p-3">
        <small class="text-muted text-uppercase fw-semibold" style="font-size:10px;letter-spacing:1px;"><?= $t[0] ?></small>
        <div class="fs-4 fw-bold" style="color:<?= $t[2] ?>"><?= $t[1] ?></div>
      </div></div>
    <?php endforeach; ?>
  </div>

  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card-e p-4">
        <h6 class="fw-bold mb-3"><i class="bi bi-plug me-1 text-primary"></i> API Status Snapshot</h6>
        <div class="row g-3">
          <div class="col-md-6"><div class="d-flex align-items-center gap-3 p-3 rounded" style="background:var(--bg);">
            <i class="bi bi-credit-card-2-front fs-3 text-primary"></i>
            <div class="flex-grow-1"><div class="fw-bold">Card Payment Gateway</div><small class="text-muted"><?= esc(setting_get('gw_card_provider','Stripe')) ?></small></div>
            <span class="s-badge <?= $cardStatus==='active'?'paid':'failed' ?>"><?= $cardStatus ?></span>
          </div></div>
          <div class="col-md-6"><div class="d-flex align-items-center gap-3 p-3 rounded" style="background:var(--bg);">
            <i class="bi bi-paypal fs-3" style="color:#003087;"></i>
            <div class="flex-grow-1"><div class="fw-bold">PayPal</div><small class="text-muted"><?= esc(setting_get('gw_paypal_account_name')) ?></small></div>
            <span class="s-badge <?= $ppStatus==='active'?'paid':'failed' ?>"><?= $ppStatus ?></span>
          </div></div>
        </div>
        <a href="admin.php?tab=api" class="btn btn-soft-blue btn-sm mt-3"><i class="bi bi-gear me-1"></i> Manage API Settings</a>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card-e p-4">
        <h6 class="fw-bold mb-3"><i class="bi bi-bell text-primary me-1"></i> Recent Activity</h6>
        <ul class="list-unstyled small mb-0">
          <?php foreach ($pdo->query("SELECT 'order' AS t, order_number AS d, created_at FROM orders WHERE region=".$pdo->quote($region_code)." ORDER BY created_at DESC LIMIT 4") as $r): ?>
            <li class="d-flex gap-2 py-1"><i class="bi bi-receipt text-primary"></i><span class="flex-grow-1">Order <strong>#<?= esc($r['d']) ?></strong></span><span class="text-muted"><?= esc(date('M j', strtotime($r['created_at']))) ?></span></li>
          <?php endforeach; ?>
          <?php foreach ($pdo->query("SELECT 'lead' AS t, name AS d, created_at FROM chat_leads ORDER BY created_at DESC LIMIT 3") as $r): ?>
            <li class="d-flex gap-2 py-1"><i class="bi bi-person-plus text-warning"></i><span class="flex-grow-1">Lead from <strong><?= esc($r['d'] ?: 'Anonymous') ?></strong></span><span class="text-muted"><?= esc(date('M j', strtotime($r['created_at']))) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>

<?php
// ============================================================================
// PRODUCTS (region-filtered)
// ============================================================================
elseif ($tab === 'products'):
  $st = $pdo->prepare("SELECT p.*,
      (SELECT COUNT(*) FROM license_keys lk WHERE lk.product_slug=p.slug AND lk.status='available' AND lk.region=?) AS avail_keys,
      (SELECT COUNT(*) FROM license_keys lk WHERE lk.product_slug=p.slug AND lk.status='sold'      AND lk.region=?) AS sold_keys
    FROM products p WHERE p.region=? OR p.region IS NULL ORDER BY p.name");
  $st->execute([$region_code,$region_code,$region_code]);
  $products = $st->fetchAll();
?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold mb-0">Products <span class="text-muted fs-6">(<?= count($products) ?> in <?= esc($region_code) ?>)</span></h5>
    <a href="inventory.php" class="btn-add-glow" data-testid="add-product-btn"><i class="bi bi-plus-lg"></i></a>
  </div>
  <div class="row g-3" data-testid="products-grid">
    <?php foreach ($products as $p): ?>
      <div class="col-md-6 col-xl-4">
        <div class="card-e p-3 h-100">
          <div class="d-flex align-items-start gap-3 mb-3">
            <?php if ($p['image']): ?><img src="<?= esc($p['image']) ?>" alt="" style="width:60px;height:60px;object-fit:contain;background:var(--bg);border-radius:8px;padding:5px;"><?php endif; ?>
            <div class="flex-grow-1 min-width-0">
              <div class="fw-semibold small"><?= esc($p['name']) ?></div>
              <div class="small text-muted"><?= esc($p['platform']) ?> · <?= esc($p['category']) ?></div>
              <a href="inventory.php?view=product&slug=<?= esc($p['slug']) ?>&tab=keys" class="small">Manage keys →</a>
            </div>
          </div>
          <div class="key-stats mb-3">
            <div class="key-pill avail"><div class="num text-success"><?= (int)$p['avail_keys'] ?></div><div class="lbl">Available</div></div>
            <div class="key-pill sold"><div class="num" style="color:#3b82f6;"><?= (int)$p['sold_keys'] ?></div><div class="lbl">Sold</div></div>
          </div>
          <form method="post">
            <input type="hidden" name="action" value="update_product">
            <input type="hidden" name="slug" value="<?= esc($p['slug']) ?>">
            <div class="row g-2">
              <div class="col-6"><label class="form-label small mb-0">Price</label><input name="price" type="number" step="0.01" class="form-control form-control-sm" value="<?= esc($p['price']) ?>"></div>
              <div class="col-6"><label class="form-label small mb-0">Original</label><input name="original_price" type="number" step="0.01" class="form-control form-control-sm" value="<?= esc($p['original_price'] ?? '') ?>"></div>
              <div class="col-12"><input name="badge" class="form-control form-control-sm" value="<?= esc($p['badge'] ?? '') ?>" placeholder="Badge"></div>
            </div>
            <button class="btn btn-soft-blue btn-sm w-100 mt-2"><i class="bi bi-check2 me-1"></i>Save</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

<?php
// ============================================================================
// ORDERS (region-filtered, click → order-view.php)
// ============================================================================
elseif ($tab === 'orders'): ?>
  <h5 class="fw-bold mb-3">Orders <span class="text-muted fs-6">(<?= esc($rg['name']) ?>)</span></h5>
  <div class="tbl-e">
    <table class="table mb-0" data-testid="admin-orders-table">
      <thead><tr><th>Order#</th><th>Customer</th><th>Total</th><th>Payment</th><th>Status</th><th>Fulfill</th><th></th></tr></thead>
      <tbody>
        <?php
        $orderStmt = $pdo->prepare("SELECT * FROM orders WHERE region=? ORDER BY created_at DESC LIMIT 200");
        $orderStmt->execute([$region_code]);
        foreach ($orderStmt as $o): ?>
          <tr style="cursor:pointer;" onclick="location.href='order-view.php?id=<?= (int)$o['id'] ?>'">
            <td><strong>#<?= esc($o['order_number']) ?></strong><br><small class="text-muted"><?= esc(date('M j, Y', strtotime($o['created_at']))) ?></small></td>
            <td><?= esc($o['first_name'].' '.$o['last_name']) ?><br><small class="text-muted"><?= esc($o['email']) ?></small></td>
            <td class="fw-bold"><?= region_money((float)$o['total']) ?></td>
            <td><span class="s-badge sent text-capitalize"><?= esc($o['payment_method']) ?></span></td>
            <td><span class="s-badge <?= esc($o['status']) ?> text-capitalize"><?= esc($o['status']) ?></span></td>
            <td><?= $o['fulfilled'] ? '<span class="s-badge delivered">Fulfilled</span>' : '<span class="s-badge queued">Pending</span>' ?></td>
            <td onclick="event.stopPropagation()"><a class="btn btn-soft-blue btn-sm py-0 px-2" href="order-view.php?id=<?= (int)$o['id'] ?>" data-testid="open-order-<?= (int)$o['id'] ?>"><i class="bi bi-eye"></i></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php
// ============================================================================
// SALES DETAIL (full with email status)
// ============================================================================
elseif ($tab === 'sales'):
  $sales = $pdo->prepare("SELECT o.*, oi.name AS product_name, oi.product_slug, oi.price AS unit_price, oi.qty,
      lk.license_key, lk.assigned_at,
      em.status AS email_status, em.opened_at, em.opened_count, em.id AS email_id, em.delivered_at
    FROM orders o
    JOIN order_items oi ON oi.order_id=o.id
    LEFT JOIN license_keys lk ON lk.order_id=o.id AND lk.product_slug=oi.product_slug
    LEFT JOIN email_outbox em ON em.order_id=o.id
    WHERE o.status IN ('paid','delivered') AND o.region=?
    ORDER BY o.created_at DESC LIMIT 500");
  $sales->execute([$region_code]);
?>
  <h5 class="fw-bold mb-3">Sales Detail — <?= esc($rg['name']) ?></h5>
  <div class="tbl-e">
    <table class="table mb-0">
      <thead><tr><th>Date</th><th>Order#</th><th>Customer</th><th>Country</th><th>Product</th><th>Amount</th><th>License Key Sent</th><th>Email</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($sales as $s):
          $emStatus = $s['opened_at'] ? 'opened' : ($s['email_status'] ?: 'pending'); ?>
          <tr>
            <td><small><?= esc(date('M j, Y', strtotime($s['created_at']))) ?></small></td>
            <td><strong>#<?= esc($s['order_number']) ?></strong></td>
            <td><small><?= esc($s['first_name'].' '.$s['last_name']) ?><br><span class="text-muted"><?= esc($s['email']) ?></span></small></td>
            <td><small><?= esc($s['country'] ?: '—') ?></small></td>
            <td><small><?= esc($s['product_name']) ?> ×<?= (int)$s['qty'] ?></small></td>
            <td><strong><?= region_money((float)$s['unit_price']*(int)$s['qty']) ?></strong></td>
            <td><?php if ($s['license_key']): ?><code style="background:var(--blue-soft);color:var(--brand-dk);padding:3px 8px;border-radius:6px;font-size:12px;"><?= esc($s['license_key']) ?></code><?php else: ?><span class="text-muted small">—</span><?php endif; ?></td>
            <td><span class="s-badge <?= $emStatus ?>"><?= esc($emStatus) ?></span><?php if ($s['opened_at']): ?><br><small class="text-muted">opened <?= (int)$s['opened_count'] ?>×</small><?php endif; ?></td>
            <td>
              <a href="order-view.php?id=<?= (int)$s['id'] ?>" class="btn btn-soft-blue btn-sm py-0 px-2" title="Open order"><i class="bi bi-eye"></i></a>
              <?php if ($s['email_id']): ?><a href="email-view.php?id=<?= (int)$s['email_id'] ?>" target="_blank" class="btn btn-soft-gray btn-sm py-0 px-2" title="View email"><i class="bi bi-envelope"></i></a><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php
// ============================================================================
// LEAD MANAGEMENT
// ============================================================================
elseif ($tab === 'leads'):
  $open = (int)($_GET['open'] ?? 0);
  $statusFilter = $_GET['status'] ?? '';
  $w=''; $args=[];
  if ($statusFilter) { $w = ' WHERE status=?'; $args[]=$statusFilter; }
  $st = $pdo->prepare("SELECT * FROM chat_leads $w ORDER BY created_at DESC LIMIT 200");
  $st->execute($args);
  $leads = $st->fetchAll();
  $admins = $pdo->query('SELECT id, email FROM admins')->fetchAll();
?>
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="fw-bold mb-0">Lead Management</h5>
    <div class="d-flex gap-2">
      <?php foreach (['' => 'All', 'new'=>'New', 'contacted'=>'Contacted', 'qualified'=>'Qualified', 'converted'=>'Converted', 'lost'=>'Lost'] as $k=>$lbl): ?>
        <a class="adm-pill <?= $statusFilter===$k?'active':'' ?>" href="?tab=leads<?= $k?'&status='.$k:'' ?>"><?= esc($lbl) ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-<?= $open?'7':'12' ?>">
      <div class="tbl-e">
        <table class="table mb-0" data-testid="leads-table">
          <thead><tr><th>Name</th><th>Contact</th><th>Product</th><th>Status</th><th>Assigned</th><th>Date</th></tr></thead>
          <tbody>
            <?php if (empty($leads)): ?><tr><td colspan="6" class="text-center text-muted py-4">No leads found.</td></tr><?php endif; ?>
            <?php foreach ($leads as $l):
              $assignEmail = '';
              if ($l['assigned_to']) {
                foreach ($admins as $a) if ($a['id']==$l['assigned_to']) $assignEmail = $a['email'];
              }
            ?>
              <tr style="cursor:pointer;<?= $open==$l['id']?'background:var(--blue-soft);':'' ?>" onclick="location.href='?tab=leads&open=<?= $l['id'] ?>'">
                <td class="fw-semibold"><?= esc($l['name'] ?: 'Anonymous') ?><?php if ($l['callback_requested']): ?> <i class="bi bi-telephone-fill text-warning ms-1" title="Callback requested"></i><?php endif; ?></td>
                <td><small><?= esc($l['email'] ?: '—') ?><br><?= esc($l['phone'] ?: '') ?></small></td>
                <td><small><?= esc($l['requested_product'] ?: '—') ?></small></td>
                <td><span class="s-badge <?= esc($l['status']) ?>"><?= esc($l['status']) ?></span></td>
                <td><small><?= esc($assignEmail ?: '—') ?></small></td>
                <td><small class="text-muted"><?= esc(date('M j, Y', strtotime($l['created_at']))) ?></small></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($open):
      $lead = $pdo->prepare('SELECT * FROM chat_leads WHERE id=?'); $lead->execute([$open]); $lead = $lead->fetch();
      $notes = $pdo->prepare('SELECT * FROM lead_notes WHERE lead_id=? ORDER BY created_at DESC'); $notes->execute([$open]); $notes = $notes->fetchAll();
    ?>
    <div class="col-lg-5">
      <div class="card-e p-4 sticky-top" style="top:90px;">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <h6 class="fw-bold mb-0"><?= esc($lead['name'] ?: 'Anonymous Lead') ?></h6>
          <a href="?tab=leads" class="btn-close" style="font-size:12px;"></a>
        </div>
        <div class="row g-2 small mb-3">
          <div class="col-6"><span class="text-muted">Email:</span><br><strong><?= esc($lead['email'] ?: '—') ?></strong></div>
          <div class="col-6"><span class="text-muted">Phone:</span><br><strong><?= esc($lead['phone'] ?: '—') ?></strong></div>
          <div class="col-6"><span class="text-muted">Country:</span><br><strong><?= esc($lead['country'] ?: '—') ?></strong></div>
          <div class="col-6"><span class="text-muted">Created:</span><br><strong><?= esc(date('M j, Y H:i', strtotime($lead['created_at']))) ?></strong></div>
          <?php if ($lead['callback_requested']): ?><div class="col-12"><span class="s-badge new">Callback Requested</span></div><?php endif; ?>
          <?php if ($lead['message']): ?><div class="col-12 mt-2"><span class="text-muted">Message:</span><div class="p-2 mt-1 rounded" style="background:var(--bg);"><?= esc($lead['message']) ?></div></div><?php endif; ?>
        </div>

        <form method="post" class="border-top pt-3">
          <input type="hidden" name="action" value="update_lead">
          <input type="hidden" name="lead_id" value="<?= (int)$lead['id'] ?>">
          <div class="row g-2 mb-2">
            <div class="col-6"><label class="form-label small mb-0">Status</label>
              <select name="status" class="form-select form-select-sm">
                <?php foreach (['new','contacted','qualified','converted','lost'] as $s): ?>
                  <option value="<?= $s ?>" <?= $lead['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6"><label class="form-label small mb-0">Assigned to</label>
              <select name="assigned_to" class="form-select form-select-sm">
                <option value="">— Unassigned —</option>
                <?php foreach ($admins as $a): ?><option value="<?= $a['id'] ?>" <?= $lead['assigned_to']==$a['id']?'selected':'' ?>><?= esc($a['email']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-12"><label class="form-label small mb-0">Requested Product</label>
              <input class="form-control form-control-sm" name="requested_product" value="<?= esc($lead['requested_product']) ?>">
            </div>
            <div class="col-12"><label class="form-label small mb-0">Add Follow-up Note</label>
              <textarea name="note" class="form-control form-control-sm" rows="2" placeholder="Internal note (optional)"></textarea>
            </div>
          </div>
          <button class="btn btn-soft-blue btn-sm w-100"><i class="bi bi-check2 me-1"></i> Update Lead</button>
        </form>

        <?php if ($notes): ?>
          <h6 class="fw-bold mt-3 mb-2 small">Follow-up History</h6>
          <?php foreach ($notes as $n): ?>
            <div class="p-2 mb-2 rounded small" style="background:var(--bg);border-left:3px solid var(--brand);">
              <div><?= nl2br(esc($n['note'])) ?></div>
              <div class="text-muted mt-1" style="font-size:11px;"><?= esc($n['author_name'] ?: 'admin') ?> · <?= esc(date('M j, H:i', strtotime($n['created_at']))) ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

<?php
// ============================================================================
// KEY INVENTORY
// ============================================================================
elseif ($tab === 'keys'): ?>
  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card-e p-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <h6 class="fw-bold mb-0">Add License Keys to <?= esc($rg['code']) ?></h6>
          <button type="button" class="btn-add-glow" style="width:42px;height:42px;font-size:18px;" onclick="document.getElementById('keysTa').focus()"><i class="bi bi-plus-lg"></i></button>
        </div>
        <form method="post">
          <input type="hidden" name="action" value="add_keys">
          <select name="product_slug" required class="form-select mb-2">
            <option value="">Select product…</option>
            <?php foreach ($pdo->query('SELECT slug,name FROM products ORDER BY name') as $p): ?>
              <option value="<?= esc($p['slug']) ?>"><?= esc($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <textarea id="keysTa" name="keys" rows="7" required class="form-control font-monospace mb-2" placeholder="One key per line"></textarea>
          <button class="btn btn-soft-blue w-100"><i class="bi bi-plus-circle me-1"></i>Add to Inventory</button>
        </form>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="tbl-e" style="max-height:600px;overflow-y:auto;">
        <table class="table mb-0">
          <thead><tr><th>Key</th><th>Product</th><th>Region</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <?php
            $kSt = $pdo->prepare('SELECT lk.*, p.name FROM license_keys lk LEFT JOIN products p ON p.slug=lk.product_slug WHERE lk.region=? ORDER BY lk.created_at DESC LIMIT 300');
            $kSt->execute([$region_code]);
            foreach ($kSt as $k): ?>
              <tr>
                <td><code style="font-size:12px;"><?= esc($k['license_key']) ?></code></td>
                <td><small><?= esc($k['name'] ?: $k['product_slug']) ?></small></td>
                <td><small><?= esc($k['region']) ?></small></td>
                <td><span class="s-badge <?= $k['status']==='available'?'paid':($k['status']==='sold'?'sent':'refunded') ?>"><?= esc($k['status']) ?></span></td>
                <td><?php if ($k['status']==='available'): ?>
                  <form method="post" class="d-inline"><input type="hidden" name="action" value="delete_key"><input type="hidden" name="key_id" value="<?= (int)$k['id'] ?>">
                    <button class="btn btn-soft-red btn-sm py-0 px-2"><i class="bi bi-trash"></i></button></form>
                <?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

<?php
// ============================================================================
// EMAIL ACTIVITY CENTER
// ============================================================================
elseif ($tab === 'emails'):
  $c = $pdo->query("SELECT SUM(status='queued') q, SUM(status='sent') s, SUM(opened_at IS NOT NULL) o, SUM(status='failed') f, COUNT(*) t FROM email_outbox")->fetch();
?>
  <h5 class="fw-bold mb-1">Email Activity Center</h5>
  <p class="text-muted small mb-3">Every transactional email — with delivery, open and click tracking. Click <i class="bi bi-eye"></i> to view the exact email the customer received.</p>

  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="card-e p-3"><small class="text-muted text-uppercase fw-semibold" style="font-size:10px;letter-spacing:1px;">Sent</small><div class="fs-4 fw-bold" style="color:#3b82f6;"><?= (int)$c['s'] ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card-e p-3"><small class="text-muted text-uppercase fw-semibold" style="font-size:10px;letter-spacing:1px;">Opened</small><div class="fs-4 fw-bold text-success"><?= (int)$c['o'] ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card-e p-3"><small class="text-muted text-uppercase fw-semibold" style="font-size:10px;letter-spacing:1px;">Queued</small><div class="fs-4 fw-bold" style="color:#d97706;"><?= (int)$c['q'] ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card-e p-3"><small class="text-muted text-uppercase fw-semibold" style="font-size:10px;letter-spacing:1px;">Failed</small><div class="fs-4 fw-bold text-danger"><?= (int)$c['f'] ?></div></div></div>
  </div>

  <div class="tbl-e">
    <table class="table mb-0" data-testid="email-activity">
      <thead><tr><th>Recipient</th><th>Subject</th><th>Template</th><th>Order</th><th>Delivery</th><th>Open</th><th>Click</th><th>Sent</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($pdo->query("SELECT em.*, o.order_number FROM email_outbox em LEFT JOIN orders o ON o.id=em.order_id ORDER BY em.created_at DESC LIMIT 200") as $e):
          $ds = $e['status'];
          $os = $e['opened_at'] ? 'opened' : ($e['status']==='sent' ? 'delivered' : 'not viewed');
        ?>
          <tr>
            <td><small><?= esc($e['recipient']) ?></small></td>
            <td><small><?= esc(mb_strimwidth($e['subject'],0,40,'…')) ?></small></td>
            <td><small><code style="font-size:11px;"><?= esc($e['template_code'] ?: 'inline') ?></code></small></td>
            <td><?= $e['order_number'] ? '<a href="order-view.php?id='.(int)$e['order_id'].'"><code>#'.esc($e['order_number']).'</code></a>' : '—' ?></td>
            <td><span class="s-badge <?= esc($ds) ?>"><?= esc($ds) ?></span><?php if ($e['delivered_at']): ?><br><small class="text-muted"><?= esc(date('M j H:i', strtotime($e['delivered_at']))) ?></small><?php endif; ?></td>
            <td><?php if ($e['opened_at']): ?><span class="s-badge opened">Opened <?= (int)$e['opened_count'] ?>×</span><br><small class="text-muted"><?= esc(date('M j H:i', strtotime($e['opened_at']))) ?></small><?php else: ?><span class="text-muted small"><?= esc($os) ?></span><?php endif; ?></td>
            <td><?php if ($e['clicked_at']): ?><span class="s-badge opened">Clicked <?= (int)$e['click_count'] ?>×</span><?php else: ?><span class="text-muted small">—</span><?php endif; ?></td>
            <td><small class="text-muted"><?= esc(date('M j, Y H:i', strtotime($e['created_at']))) ?></small></td>
            <td><a class="btn btn-soft-blue btn-sm py-0 px-2" href="email-view.php?id=<?= (int)$e['id'] ?>" target="_blank" data-testid="view-email-<?= (int)$e['id'] ?>" title="View exact email sent"><i class="bi bi-eye"></i></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php
// ============================================================================
// EMAIL TEMPLATES (multiple + version history)
// ============================================================================
elseif ($tab === 'templates'):
  $editId = (int)($_GET['edit'] ?? 0);
  $tpls = $pdo->query('SELECT * FROM email_templates ORDER BY name')->fetchAll();
  $editing = null;
  if ($editId) {
    $s = $pdo->prepare('SELECT * FROM email_templates WHERE id=?'); $s->execute([$editId]); $editing = $s->fetch();
  }
?>
  <h5 class="fw-bold mb-3">Email Templates</h5>
  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card-e p-2">
        <?php foreach ($tpls as $t): ?>
          <a href="?tab=templates&edit=<?= (int)$t['id'] ?>" class="d-block p-3 rounded text-decoration-none mb-1" style="background:<?= $editId==$t['id']?'var(--blue-soft)':'transparent' ?>;color:var(--text);">
            <div class="d-flex justify-content-between">
              <strong><?= esc($t['name']) ?></strong>
              <?= $t['active']?'<span class="s-badge active">on</span>':'<span class="s-badge inactive">off</span>' ?>
            </div>
            <small class="text-muted"><code><?= esc($t['code']) ?></code> · v<?= (int)$t['current_version'] ?></small>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="col-lg-8">
      <?php if ($editing): ?>
        <?php
        $tplHtml = trim($editing['html']) === '' && $editing['code']==='order_delivery' ? default_email_template() : $editing['html'];
        $versions = $pdo->prepare('SELECT * FROM email_template_versions WHERE template_id=? ORDER BY version_num DESC LIMIT 10');
        $versions->execute([$editing['id']]); $versions = $versions->fetchAll();
        ?>
        <div class="card-e p-3 mb-3">
          <form method="post">
            <input type="hidden" name="action" value="save_template">
            <input type="hidden" name="tpl_id" value="<?= (int)$editing['id'] ?>">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                <strong><?= esc($editing['name']) ?></strong>
                <small class="text-muted ms-2">v<?= (int)$editing['current_version'] ?> · <code><?= esc($editing['code']) ?></code></small>
              </div>
              <div class="form-check form-switch mb-0">
                <input type="checkbox" class="form-check-input" name="active" id="actSw" <?= $editing['active']?'checked':'' ?>>
                <label class="form-check-label small" for="actSw">Active</label>
              </div>
            </div>
            <label class="form-label small fw-semibold">Subject</label>
            <input class="form-control mb-2" name="subject" value="<?= esc($editing['subject']) ?>">

            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label small fw-semibold">HTML</label>
                <textarea name="html" class="form-control font-monospace" rows="18" id="htmlEd" style="font-size:11.5px;"><?= esc($tplHtml) ?></textarea>
              </div>
              <div class="col-md-6">
                <label class="form-label small fw-semibold">Live Preview</label>
                <iframe id="prev" style="width:100%;height:430px;border:1px solid var(--border);border-radius:10px;background:#fff;"></iframe>
              </div>
            </div>
            <div class="d-flex gap-2 mt-2">
              <button class="btn btn-soft-blue btn-sm"><i class="bi bi-check2 me-1"></i> Save (creates v<?= (int)$editing['current_version']+1 ?>)</button>
              <button type="button" class="btn btn-soft-gray btn-sm" onclick="prevRender()"><i class="bi bi-arrow-clockwise me-1"></i> Refresh Preview</button>
            </div>
          </form>
        </div>

        <?php if ($versions): ?>
          <div class="card-e p-3">
            <h6 class="fw-bold mb-2"><i class="bi bi-clock-history me-1"></i> Version History</h6>
            <div class="tbl-e">
              <table class="table mb-0 small">
                <thead><tr><th>v#</th><th>Subject</th><th>Edited by</th><th>When</th><th></th></tr></thead>
                <tbody>
                  <?php foreach ($versions as $v): ?>
                    <tr>
                      <td><strong>v<?= (int)$v['version_num'] ?></strong></td>
                      <td><small><?= esc(mb_strimwidth($v['subject'],0,50,'…')) ?></small></td>
                      <td><small><?= esc($v['edited_by_email'] ?: 'system') ?></small></td>
                      <td><small class="text-muted"><?= esc(date('M j, Y H:i', strtotime($v['created_at']))) ?></small></td>
                      <td>
                        <form method="post" class="d-inline" onsubmit="return confirm('Restore this version?')">
                          <input type="hidden" name="action" value="restore_template_version">
                          <input type="hidden" name="tpl_id" value="<?= (int)$editing['id'] ?>">
                          <input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>">
                          <button class="btn btn-soft-gray btn-sm py-0 px-2"><i class="bi bi-arrow-counterclockwise"></i></button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

        <script>
        function prevRender(){
          var html=document.getElementById('htmlEd').value;
          var s={company_name:'<?= esc(SITE_BRAND) ?>',customer_name:'John Smith',customer_email:'john@example.com',order_number:'MVT-2026-0042',amount:'129.99',statement_name:'<?= esc(setting_get('statement_name_card','MAVENTECH SOFTWARE')) ?>',support_email:'<?= esc(SITE_EMAIL) ?>',support_phone:'<?= esc(SITE_PHONE) ?>',year:new Date().getFullYear(),installation_guide:'1. Download installer.<br>2. Run setup.<br>3. Enter license key.<br>4. Activate.',products_block:'<div style="border:1px solid #eef0f3;border-radius:12px;padding:14px;background:#fff;"><div style="font-weight:700;">Sample Product</div><div style="margin-top:10px;border:2px dashed #3b82f6;border-radius:10px;background:#eff6ff;padding:12px;text-align:center;"><div style="font-size:10px;color:#64748b;letter-spacing:1.5px;text-transform:uppercase;">License Key</div><div style="font-family:monospace;font-weight:bold;color:#1d4ed8;font-size:17px;">XXXXX-YYYYY-ZZZZZ-AAAAA</div></div></div>',tracking_pixel:''};
          Object.keys(s).forEach(function(k){ html=html.split('{{'+k+'}}').join(s[k]); });
          document.getElementById('prev').srcdoc=html;
        }
        prevRender();
        document.getElementById('htmlEd').addEventListener('input',function(){clearTimeout(window._tt);window._tt=setTimeout(prevRender,400);});
        </script>
      <?php else: ?>
        <div class="card-e p-5 text-center text-muted">Select a template on the left to edit.</div>
      <?php endif; ?>
    </div>
  </div>

<?php
// ============================================================================
// API MANAGEMENT (Card + PayPal)
// ============================================================================
elseif ($tab === 'api'):
  function mask($v) { if (!$v) return ''; $l = strlen($v); if ($l <= 8) return str_repeat('*', $l); return substr($v,0,4).str_repeat('*', $l-8).substr($v,-4); }
  $cardStatus = setting_get('gw_card_status','inactive');
  $cardProv   = setting_get('gw_card_provider','Stripe');
  $cardMerch  = setting_get('gw_card_merchant_name','Maventech Software');
  $cardPub    = setting_get('gw_card_public_key','');
  $cardSec    = setting_get('gw_card_secret_key','');
  $cardWh     = setting_get('gw_card_webhook_secret','');
  $cardWhUrl  = setting_get('gw_card_webhook_url','/stripe-webhook.php');

  $ppStatus   = setting_get('gw_paypal_status','inactive');
  $ppAcc      = setting_get('gw_paypal_account_name','Maventech Software LLC');
  $ppCid      = setting_get('gw_paypal_client_id','');
  $ppSec      = setting_get('gw_paypal_secret','');
  $ppWh       = setting_get('gw_paypal_webhook_id','');
  $ppWhUrl    = setting_get('gw_paypal_webhook_url','/paypal-webhook.php');

  $txCard = (int)$pdo->query("SELECT COUNT(*) FROM transaction_logs WHERE gateway='card'")->fetchColumn();
  $txPp   = (int)$pdo->query("SELECT COUNT(*) FROM transaction_logs WHERE gateway='paypal'")->fetchColumn();
?>
  <h5 class="fw-bold mb-1">API Management</h5>
  <p class="text-muted small mb-3">Configure payment gateway credentials and view live status. Changes apply instantly without code changes — credentials are stored in the <code>settings</code> table.</p>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card-e p-4 h-100" data-testid="api-card-gateway">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div>
            <h6 class="fw-bold mb-1"><i class="bi bi-credit-card-2-front text-primary me-1"></i> Card Payment API</h6>
            <small class="text-muted">Gateway: <?= esc($cardProv) ?></small>
          </div>
          <span class="s-badge <?= $cardStatus==='active'?'paid':'failed' ?>"><?= esc($cardStatus) ?></span>
        </div>
        <form method="post">
          <input type="hidden" name="action" value="save_api">
          <input type="hidden" name="gateway" value="card">
          <div class="row g-2 small mb-3">
            <div class="col-6"><label class="form-label small mb-0">API Status</label>
              <select name="status" class="form-select form-select-sm">
                <option value="active" <?= $cardStatus==='active'?'selected':'' ?>>Active</option>
                <option value="inactive" <?= $cardStatus!=='active'?'selected':'' ?>>Inactive</option>
              </select>
            </div>
            <div class="col-6"><label class="form-label small mb-0">Gateway Provider</label><input class="form-control form-control-sm" name="provider" value="<?= esc($cardProv) ?>"></div>
            <div class="col-12"><label class="form-label small mb-0">Merchant / Company Name</label><input class="form-control form-control-sm" name="merchant_name" value="<?= esc($cardMerch) ?>"></div>
            <div class="col-12"><label class="form-label small mb-0">Publishable Key <small class="text-muted"><?= esc(mask($cardPub)) ?></small></label><input class="form-control form-control-sm" name="public_key" type="password" placeholder="leave blank to keep current"></div>
            <div class="col-12"><label class="form-label small mb-0">Secret Key <small class="text-muted"><?= esc(mask($cardSec)) ?></small></label><input class="form-control form-control-sm" name="secret_key" type="password" placeholder="leave blank to keep current"></div>
            <div class="col-12"><label class="form-label small mb-0">Webhook Secret <small class="text-muted"><?= esc(mask($cardWh)) ?></small></label><input class="form-control form-control-sm" name="webhook_secret" type="password" placeholder="leave blank to keep current"></div>
            <div class="col-12"><label class="form-label small mb-0">Webhook URL</label><input class="form-control form-control-sm" readonly value="<?= esc(site_url().$cardWhUrl) ?>"></div>
          </div>
          <button class="btn btn-soft-blue btn-sm w-100"><i class="bi bi-check2 me-1"></i> Save Card API Settings</button>
        </form>
        <div class="mt-3 pt-3 border-top d-flex justify-content-between small">
          <span class="text-muted">Webhook Status</span>
          <span class="s-badge <?= $cardWh ? 'paid' : 'queued' ?>"><?= $cardWh ? 'configured' : 'not configured' ?></span>
        </div>
        <div class="d-flex justify-content-between small">
          <span class="text-muted">Transaction Logs</span>
          <strong><?= $txCard ?></strong>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card-e p-4 h-100" data-testid="api-paypal-gateway">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div>
            <h6 class="fw-bold mb-1"><i class="bi bi-paypal me-1" style="color:#003087;"></i> PayPal API</h6>
            <small class="text-muted">Business: <?= esc($ppAcc) ?></small>
          </div>
          <span class="s-badge <?= $ppStatus==='active'?'paid':'failed' ?>"><?= esc($ppStatus) ?></span>
        </div>
        <form method="post">
          <input type="hidden" name="action" value="save_api">
          <input type="hidden" name="gateway" value="paypal">
          <div class="row g-2 small mb-3">
            <div class="col-12"><label class="form-label small mb-0">API Status</label>
              <select name="status" class="form-select form-select-sm">
                <option value="active" <?= $ppStatus==='active'?'selected':'' ?>>Active</option>
                <option value="inactive" <?= $ppStatus!=='active'?'selected':'' ?>>Inactive</option>
              </select>
              <small class="text-muted">Toggling Active also reveals PayPal on the public checkout.</small>
            </div>
            <div class="col-12"><label class="form-label small mb-0">PayPal Business Account Name</label><input class="form-control form-control-sm" name="account_name" value="<?= esc($ppAcc) ?>"></div>
            <div class="col-12"><label class="form-label small mb-0">Client ID <small class="text-muted"><?= esc(mask($ppCid)) ?></small></label><input class="form-control form-control-sm" name="client_id" type="password" placeholder="leave blank to keep current"></div>
            <div class="col-12"><label class="form-label small mb-0">Client Secret <small class="text-muted"><?= esc(mask($ppSec)) ?></small></label><input class="form-control form-control-sm" name="secret" type="password" placeholder="leave blank to keep current"></div>
            <div class="col-12"><label class="form-label small mb-0">Webhook ID <small class="text-muted"><?= esc(mask($ppWh)) ?></small></label><input class="form-control form-control-sm" name="webhook_id" type="password" placeholder="leave blank to keep current"></div>
            <div class="col-12"><label class="form-label small mb-0">Webhook URL</label><input class="form-control form-control-sm" readonly value="<?= esc(site_url().$ppWhUrl) ?>"></div>
          </div>
          <button class="btn btn-soft-blue btn-sm w-100"><i class="bi bi-check2 me-1"></i> Save PayPal API Settings</button>
        </form>
        <div class="mt-3 pt-3 border-top d-flex justify-content-between small">
          <span class="text-muted">Webhook Status</span>
          <span class="s-badge <?= $ppWh ? 'paid' : 'queued' ?>"><?= $ppWh ? 'configured' : 'not configured' ?></span>
        </div>
        <div class="d-flex justify-content-between small">
          <span class="text-muted">Transaction Logs</span>
          <strong><?= $txPp ?></strong>
        </div>
      </div>
    </div>
  </div>

  <div class="card-e p-3 mt-3">
    <h6 class="fw-bold mb-2"><i class="bi bi-list-ul me-1"></i> Recent Transaction Logs</h6>
    <div class="tbl-e">
      <table class="table table-sm mb-0">
        <thead><tr><th>Gateway</th><th>Transaction</th><th>Order</th><th>Amount</th><th>Status</th><th>When</th></tr></thead>
        <tbody>
          <?php
          $logs = $pdo->query('SELECT tl.*, o.order_number FROM transaction_logs tl LEFT JOIN orders o ON o.id=tl.order_id ORDER BY tl.created_at DESC LIMIT 50');
          $any=false; foreach ($logs as $tl): $any=true; ?>
            <tr>
              <td><span class="s-badge sent"><?= esc($tl['gateway']) ?></span></td>
              <td><code style="font-size:11px;"><?= esc($tl['transaction_id']) ?></code></td>
              <td><?= $tl['order_number'] ? '<a href="order-view.php?id='.(int)$tl['order_id'].'"><code>#'.esc($tl['order_number']).'</code></a>' : '—' ?></td>
              <td><?= esc($tl['currency'].' '.number_format((float)$tl['amount'],2)) ?></td>
              <td><span class="s-badge <?= esc($tl['status']) ?>"><?= esc($tl['status']) ?></span></td>
              <td><small class="text-muted"><?= esc(date('M j, Y H:i', strtotime($tl['created_at']))) ?></small></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$any): ?><tr><td colspan="6" class="text-center text-muted py-3">No transactions logged yet — they'll appear here automatically as orders are processed.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php
// ============================================================================
// REGIONS
// ============================================================================
elseif ($tab === 'regions'):
  $regions = $pdo->query('SELECT * FROM regions ORDER BY code')->fetchAll();
?>
  <h5 class="fw-bold mb-1">Regions</h5>
  <p class="text-muted small mb-3">Each region maintains separate inventory, license keys, pricing and reports. Switch the active region via the globe icon in the topbar.</p>
  <div class="row g-3">
    <?php foreach ($regions as $r):
      $prodCount = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE region=".$pdo->quote($r['code']))->fetchColumn();
      $keysAv    = (int)$pdo->query("SELECT COUNT(*) FROM license_keys WHERE region=".$pdo->quote($r['code'])." AND status='available'")->fetchColumn();
      $rev       = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE region=".$pdo->quote($r['code'])." AND status IN ('paid','delivered')")->fetchColumn();
    ?>
      <div class="col-md-6">
        <div class="card-e p-4">
          <form method="post">
            <input type="hidden" name="action" value="save_region">
            <input type="hidden" name="region_code" value="<?= esc($r['code']) ?>">
            <div class="d-flex justify-content-between align-items-start mb-3">
              <div>
                <h6 class="fw-bold mb-0"><i class="bi bi-flag-fill me-1" style="color:var(--brand)"></i> <?= esc($r['code']) ?> · <?= esc($r['name']) ?></h6>
                <small class="text-muted"><?= esc($r['currency_symbol']) ?> <?= esc($r['currency']) ?> · Tax <?= number_format($r['tax_rate']*100,1) ?>%</small>
              </div>
              <div class="form-check form-switch mb-0">
                <input type="checkbox" class="form-check-input" name="active" id="rg_<?= esc($r['code']) ?>" <?= $r['active']?'checked':'' ?>>
                <label for="rg_<?= esc($r['code']) ?>" class="form-check-label small">Active</label>
              </div>
            </div>
            <div class="row g-2 small mb-3">
              <div class="col-12"><label class="form-label small mb-0">Region Name</label><input class="form-control form-control-sm" name="name" value="<?= esc($r['name']) ?>"></div>
              <div class="col-5"><label class="form-label small mb-0">Currency</label><input class="form-control form-control-sm" name="currency" value="<?= esc($r['currency']) ?>"></div>
              <div class="col-3"><label class="form-label small mb-0">Symbol</label><input class="form-control form-control-sm" name="currency_symbol" value="<?= esc($r['currency_symbol']) ?>"></div>
              <div class="col-4"><label class="form-label small mb-0">Tax Rate</label><input class="form-control form-control-sm" name="tax_rate" type="number" step="0.0001" value="<?= esc($r['tax_rate']) ?>"></div>
            </div>
            <div class="row g-2 small mb-3">
              <div class="col-4"><div class="p-2 rounded" style="background:var(--bg);"><div class="fw-bold"><?= $prodCount ?></div><small class="text-muted">Products</small></div></div>
              <div class="col-4"><div class="p-2 rounded" style="background:var(--bg);"><div class="fw-bold text-success"><?= $keysAv ?></div><small class="text-muted">Keys Avail</small></div></div>
              <div class="col-4"><div class="p-2 rounded" style="background:var(--bg);"><div class="fw-bold"><?= esc($r['currency_symbol']) ?><?= number_format($rev,0) ?></div><small class="text-muted">Revenue</small></div></div>
            </div>
            <button class="btn btn-soft-blue btn-sm w-100"><i class="bi bi-check2 me-1"></i> Save Region</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

<?php
// ============================================================================
// SETTINGS (statement names — moved here from old)
// ============================================================================
elseif ($tab === 'settings'):
  $stmtCard   = setting_get('statement_name_card','MAVENTECH SOFTWARE');
  $stmtPaypal = setting_get('statement_name_paypal','MAVENTECH SOFTWARE LLC');
?>
  <h5 class="fw-bold mb-1">Settings</h5>
  <p class="text-muted small mb-3">General settings. For payment credentials see <a href="admin.php?tab=api">API Management</a>.</p>

  <form method="post" class="card-e p-4" style="max-width:760px;">
    <input type="hidden" name="action" value="save_settings">
    <h6 class="fw-bold mb-3"><i class="bi bi-credit-card me-1"></i> Card Statement Names</h6>
    <p class="text-muted small mb-3">What customers see on their bank/card statement so they recognize the charge. This is also included in the order email.</p>
    <div class="row g-3 mb-3">
      <div class="col-md-6"><label class="form-label small fw-semibold">Card / Stripe statement name</label><input class="form-control" name="statement_name_card" value="<?= esc($stmtCard) ?>" maxlength="22"><small class="text-muted">Max 22 chars</small></div>
      <div class="col-md-6"><label class="form-label small fw-semibold">PayPal merchant name</label><input class="form-control" name="statement_name_paypal" value="<?= esc($stmtPaypal) ?>" maxlength="60"></div>
    </div>
    <button class="btn btn-soft-blue"><i class="bi bi-check2 me-1"></i> Save</button>
  </form>

<?php endif; ?>

<?php include __DIR__ . '/includes/admin-shell-end.php'; ?>
