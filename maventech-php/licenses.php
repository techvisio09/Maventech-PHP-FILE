<?php
require_once __DIR__ . '/auth_check.php';
$page_title = 'License Keys';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_single') {
        $pid = (int)$_POST['product_id'];
        $key = trim($_POST['license_key']) ?: generate_license_key();
        $stmt = $pdo->prepare('INSERT INTO license_keys (product_id, license_key, status) VALUES (?,?,"available")');
        try { $stmt->execute([$pid,$key]); flash_set('success','Key added.'); log_activity('create','license_key',(int)$pdo->lastInsertId()); }
        catch (Exception $e) { flash_set('error','Failed: duplicate key?'); }
        redirect('licenses.php');
    }

    if ($action === 'import') {
        $pid = (int)$_POST['product_id'];
        $raw = $_POST['keys'] ?? '';
        $keys = preg_split('/\s+/', trim($raw));
        $ins = $pdo->prepare('INSERT IGNORE INTO license_keys (product_id, license_key, status) VALUES (?,?,"available")');
        $count = 0;
        foreach ($keys as $k) {
            $k = trim($k);
            if ($k === '') continue;
            $ins->execute([$pid,$k]); $count += $ins->rowCount();
        }
        log_activity('import','license_key',null,"product=$pid count=$count");
        flash_set('success', "$count keys imported.");
        redirect('licenses.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare('DELETE FROM license_keys WHERE id=? AND status="available"')->execute([$id]);
        log_activity('delete','license_key',$id);
        flash_set('success','Key removed.');
        redirect('licenses.php');
    }

    if ($action === 'mark_expired') {
        $id = (int)$_POST['id'];
        $pdo->prepare('UPDATE license_keys SET status="expired" WHERE id=?')->execute([$id]);
        log_activity('update','license_key',$id,'mark_expired');
        flash_set('success','Marked expired.');
        redirect('licenses.php');
    }
}

// Filters
$q       = trim($_GET['q'] ?? '');
$status  = $_GET['status'] ?? '';
$product = $_GET['product'] ?? '';

$where = []; $args = [];
if ($q !== '')        { $where[]='(lk.license_key LIKE ? OR c.name LIKE ? OR c.email LIKE ? OR o.order_number LIKE ?)';
  $args = array_fill(0,4,"%$q%"); }
if ($status !== '')   { $where[]='lk.status = ?'; $args[]=$status; }
if ($product !== '')  { $where[]='lk.product_id = ?'; $args[]=(int)$product; }
$wsql = $where ? 'WHERE '.implode(' AND ',$where) : '';

$stmt = $pdo->prepare("
  SELECT lk.*, p.name AS product_name, c.name AS customer_name, c.email AS customer_email,
         o.order_number
  FROM license_keys lk
  JOIN products p ON p.id=lk.product_id
  LEFT JOIN customers c ON c.id=lk.customer_id
  LEFT JOIN orders o ON o.id=lk.order_id
  $wsql ORDER BY lk.created_at DESC LIMIT 500");
$stmt->execute($args);
$keys = $stmt->fetchAll();

$products = $pdo->query('SELECT id,name FROM products ORDER BY name')->fetchAll();

include __DIR__ . '/header.php';
include __DIR__ . '/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">License Keys</h4>
  <div>
    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#single"><i class="fa fa-plus"></i> Add One</button>
    <button class="btn btn-sm btn-accent" data-bs-toggle="modal" data-bs-target="#bulk"><i class="fa fa-upload"></i> Bulk Import</button>
  </div>
</div>

<form class="card p-3 mb-3" method="get">
  <div class="row g-2">
    <div class="col-md-4"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search key / customer / order#"></div>
    <div class="col-md-3">
      <select class="form-select" name="product">
        <option value="">All products</option>
        <?php foreach ($products as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $product==$p['id']?'selected':'' ?>><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <select class="form-select" name="status">
        <option value="">All statuses</option>
        <?php foreach (['available','assigned','sold','expired'] as $s): ?>
          <option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><button class="btn btn-primary w-100">Filter</button></div>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Key</th><th>Product</th><th>Status</th><th>Customer</th><th>Order</th><th>Assigned</th><th></th></tr></thead>
      <tbody>
        <?php if (empty($keys)): ?><tr><td colspan="7" class="text-center text-muted py-4">No keys.</td></tr><?php endif; ?>
        <?php foreach ($keys as $k): ?>
          <tr>
            <td><span class="kbd"><?= e($k['license_key']) ?></span></td>
            <td><?= e($k['product_name']) ?></td>
            <td><span class="badge bg-<?= e($k['status']) ?>"><?= e($k['status']) ?></span></td>
            <td><?= e($k['customer_name']) ?: '—' ?><?= $k['customer_email']?'<br><small class="text-muted">'.e($k['customer_email']).'</small>':'' ?></td>
            <td><?= $k['order_number'] ? '<a href="order_view.php?id='.$k['order_id'].'">'.e($k['order_number']).'</a>' : '—' ?></td>
            <td><?= $k['assigned_at']?e(date('M j, Y',strtotime($k['assigned_at']))):'—' ?></td>
            <td class="text-nowrap">
              <?php if ($k['status']==='available'): ?>
                <form method="post" class="d-inline" data-confirm="Delete this key?">
                  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $k['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="fa fa-trash"></i></button>
                </form>
              <?php endif; ?>
              <?php if ($k['status'] !== 'expired'): ?>
                <form method="post" class="d-inline" data-confirm="Mark this key as expired?">
                  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="mark_expired">
                  <input type="hidden" name="id" value="<?= $k['id'] ?>">
                  <button class="btn btn-sm btn-outline-warning"><i class="fa fa-clock"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modals -->
<div class="modal fade" id="single" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
<form method="post">
  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="action" value="add_single">
  <div class="modal-header"><h5 class="modal-title">Add a License Key</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <label class="form-label">Product</label>
    <select class="form-select mb-3" name="product_id" required>
      <option value="">-- Select --</option>
      <?php foreach($products as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
    </select>
    <label class="form-label">License Key (leave blank to auto-generate)</label>
    <input class="form-control" name="license_key" placeholder="MVT-XXXX-XXXX-XXXX-XXXX">
  </div>
  <div class="modal-footer"><button class="btn btn-accent">Add</button></div>
</form>
</div></div></div>

<div class="modal fade" id="bulk" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
<form method="post">
  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="action" value="import">
  <div class="modal-header"><h5 class="modal-title">Bulk Import License Keys</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <label class="form-label">Product</label>
    <select class="form-select mb-3" name="product_id" required>
      <option value="">-- Select --</option>
      <?php foreach($products as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
    </select>
    <label class="form-label">License keys (one per line)</label>
    <textarea class="form-control" name="keys" rows="10" placeholder="KEY-XXXX-XXXX-XXXX&#10;KEY-YYYY-YYYY-YYYY"></textarea>
  </div>
  <div class="modal-footer"><button class="btn btn-accent">Import</button></div>
</form>
</div></div></div>

<?php include __DIR__ . '/footer.php'; ?>
