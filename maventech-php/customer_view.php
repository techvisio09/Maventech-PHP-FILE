<?php
require_once __DIR__ . '/auth_check.php';
$page_title = 'Customer Profile';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM customers WHERE id=?');
$stmt->execute([$id]);
$c = $stmt->fetch();
if (!$c) { flash_set('error','Customer not found'); redirect('customers.php'); }

$orders = $pdo->prepare('SELECT * FROM orders WHERE customer_id=? ORDER BY created_at DESC');
$orders->execute([$id]); $orders = $orders->fetchAll();

$keys = $pdo->prepare('SELECT lk.*, p.name AS product_name, o.order_number
  FROM license_keys lk JOIN products p ON p.id=lk.product_id
  LEFT JOIN orders o ON o.id=lk.order_id
  WHERE lk.customer_id=? ORDER BY lk.assigned_at DESC');
$keys->execute([$id]); $keys = $keys->fetchAll();

$lifetime = array_sum(array_map(fn($o)=>$o['payment_status']==='paid'?(float)$o['total']:0, $orders));

include __DIR__ . '/header.php';
include __DIR__ . '/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div><h4 class="mb-0"><?= e($c['name']) ?></h4><small class="text-muted"><?= e($c['email']) ?></small></div>
  <a class="btn btn-sm btn-outline-secondary" href="customers.php"><i class="fa fa-arrow-left"></i> Back</a>
</div>

<div class="row g-3">
  <div class="col-md-4">
    <div class="card p-3">
      <table class="table table-sm mb-0">
        <tr><th>Phone</th><td><?= e($c['phone']) ?: '—' ?></td></tr>
        <tr><th>Country</th><td><?= e($c['country']) ?: '—' ?></td></tr>
        <tr><th>Company</th><td><?= e($c['company']) ?: '—' ?></td></tr>
        <tr><th>Joined</th><td><?= e(date('M j, Y',strtotime($c['created_at']))) ?></td></tr>
        <tr><th>Orders</th><td><?= count($orders) ?></td></tr>
        <tr><th>Lifetime Value</th><td class="fw-bold"><?= money($lifetime) ?></td></tr>
      </table>
      <?php if ($c['notes']): ?>
        <hr><small class="text-muted">Notes</small><div><?= nl2br(e($c['notes'])) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-md-8">
    <div class="card">
      <div class="card-header">Purchase History</div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>Order#</th><th>Total</th><th>Payment</th><th>Date</th><th></th></tr></thead>
          <tbody>
            <?php if (empty($orders)): ?><tr><td colspan="5" class="text-center text-muted py-3">No orders.</td></tr><?php endif; ?>
            <?php foreach ($orders as $o): ?>
              <tr>
                <td class="fw-semibold"><?= e($o['order_number']) ?></td>
                <td><?= money($o['total']) ?></td>
                <td><span class="badge bg-<?= e($o['payment_status']) ?>"><?= e($o['payment_status']) ?></span></td>
                <td><?= e(date('M j, Y',strtotime($o['created_at']))) ?></td>
                <td><a class="btn btn-sm btn-outline-secondary" href="order_view.php?id=<?= $o['id'] ?>"><i class="fa fa-eye"></i></a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header">Assigned License Keys</div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>Product</th><th>Key</th><th>Status</th><th>Order</th><th>Assigned</th></tr></thead>
          <tbody>
            <?php if (empty($keys)): ?><tr><td colspan="5" class="text-center text-muted py-3">No keys assigned.</td></tr><?php endif; ?>
            <?php foreach ($keys as $k): ?>
              <tr>
                <td><?= e($k['product_name']) ?></td>
                <td><span class="kbd"><?= e($k['license_key']) ?></span></td>
                <td><span class="badge bg-<?= e($k['status']) ?>"><?= e($k['status']) ?></span></td>
                <td><?= e($k['order_number']) ?: '—' ?></td>
                <td><?= $k['assigned_at']?e(date('M j, Y',strtotime($k['assigned_at']))):'—' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
