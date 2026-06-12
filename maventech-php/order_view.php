<?php
require_once __DIR__ . '/auth_check.php';
$page_title = 'Order Details';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT o.*, c.name AS customer_name, c.email AS customer_email, c.phone, c.company, c.country
  FROM orders o JOIN customers c ON c.id=o.customer_id WHERE o.id=?');
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) { flash_set('error','Order not found'); redirect('orders.php'); }

// Actions
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        $payment_status = $_POST['payment_status'];
        $transaction_id = trim($_POST['transaction_id'] ?? '');
        $order_status   = $_POST['order_status'];
        $pdo->prepare('UPDATE orders SET payment_status=?, transaction_id=?, order_status=? WHERE id=?')
            ->execute([$payment_status, $transaction_id, $order_status, $id]);
        log_activity('update','order',$id,'status='.$payment_status);

        if ($payment_status === 'paid') {
            // Assign keys to any items without one
            $itms = $pdo->prepare('SELECT * FROM order_items WHERE order_id=? AND license_key_id IS NULL');
            $itms->execute([$id]);
            foreach ($itms->fetchAll() as $oi) {
                $k = assign_license_to_order($oi['product_id'], $id, $order['customer_id']);
                if ($k) $pdo->prepare('UPDATE order_items SET license_key_id=? WHERE id=?')->execute([$k['id'], $oi['id']]);
            }
        }
        flash_set('success','Order updated.');
        redirect('order_view.php?id='.$id);
    }

    if ($action === 'resend_email') {
        $ok = send_license_email($id);
        flash_set($ok?'success':'error', $ok?'Email re-sent.':'Email failed to send.');
        redirect('order_view.php?id='.$id);
    }
}

$items = $pdo->prepare('SELECT oi.*, lk.license_key, lk.status AS key_status
  FROM order_items oi LEFT JOIN license_keys lk ON lk.id = oi.license_key_id
  WHERE oi.order_id=?');
$items->execute([$id]);
$items = $items->fetchAll();

include __DIR__ . '/header.php';
include __DIR__ . '/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="mb-0">Order <?= e($order['order_number']) ?></h4>
    <small class="text-muted">Created <?= e(date('M j, Y H:i',strtotime($order['created_at']))) ?></small>
  </div>
  <div>
    <a href="orders.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left"></i> Back</a>
    <form method="post" class="d-inline">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="resend_email">
      <button class="btn btn-sm btn-accent"><i class="fa fa-envelope me-1"></i> Resend Email</button>
    </form>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">Line Items</div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>Product</th><th>Unit</th><th>Qty</th><th>License Key</th><th>Total</th></tr></thead>
          <tbody>
          <?php foreach ($items as $it): ?>
            <tr>
              <td><?= e($it['product_name']) ?></td>
              <td><?= money($it['unit_price']) ?></td>
              <td><?= (int)$it['quantity'] ?></td>
              <td>
                <?php if ($it['license_key']): ?>
                  <span class="kbd"><?= e($it['license_key']) ?></span>
                  <span class="badge bg-<?= e($it['key_status']) ?>"><?= e($it['key_status']) ?></span>
                <?php else: ?>
                  <span class="text-muted">— not assigned —</span>
                <?php endif; ?>
              </td>
              <td><?= money($it['line_total']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr><td colspan="4" class="text-end">Subtotal</td><td><?= money($order['subtotal']) ?></td></tr>
            <tr><td colspan="4" class="text-end">Tax</td><td><?= money($order['tax']) ?></td></tr>
            <tr class="fw-bold"><td colspan="4" class="text-end">Total</td><td><?= money($order['total']) ?> <?= e($order['currency']) ?></td></tr>
          </tfoot>
        </table>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header">Update Status</div>
      <form method="post" class="card-body">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="update_status">
        <div class="row g-2">
          <div class="col-md-4">
            <label class="form-label">Payment Status</label>
            <select class="form-select" name="payment_status">
              <?php foreach (['pending','paid','failed','refunded'] as $s): ?>
                <option <?= $order['payment_status']===$s?'selected':'' ?>><?= $s ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Order Status</label>
            <select class="form-select" name="order_status">
              <?php foreach (['processing','completed','cancelled'] as $s): ?>
                <option <?= $order['order_status']===$s?'selected':'' ?>><?= $s ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Transaction ID</label>
            <input class="form-control" name="transaction_id" value="<?= e($order['transaction_id']) ?>">
          </div>
        </div>
        <button class="btn btn-primary mt-3"><i class="fa fa-save me-1"></i> Save</button>
      </form>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">Customer</div>
      <div class="card-body">
        <h6 class="mb-1"><?= e($order['customer_name']) ?></h6>
        <p class="text-muted small mb-1"><?= e($order['customer_email']) ?></p>
        <p class="text-muted small mb-1"><?= e($order['phone']) ?></p>
        <p class="text-muted small mb-0"><?= e($order['company']) ?>, <?= e($order['country']) ?></p>
        <a href="customer_view.php?id=<?= (int)$order['customer_id'] ?>" class="small">View customer →</a>
      </div>
    </div>
    <div class="card mt-3">
      <div class="card-header">Payment</div>
      <div class="card-body">
        <table class="table table-sm mb-0">
          <tr><th>Method</th><td><?= e($order['payment_method']) ?></td></tr>
          <tr><th>Status</th><td><span class="badge bg-<?= e($order['payment_status']) ?>"><?= e($order['payment_status']) ?></span></td></tr>
          <tr><th>Txn ID</th><td><small><?= e($order['transaction_id']) ?: '—' ?></small></td></tr>
          <tr><th>Amount Paid</th><td class="fw-bold"><?= money($order['total']) ?></td></tr>
          <tr><th>Card statement</th><td><span class="badge bg-secondary"><?= e($order['card_statement_name'] ?: COMPANY_STATEMENT_NAME) ?></span></td></tr>
          <tr><th>Email sent</th><td><?= $order['email_sent']?'<span class="text-success">Yes</span>':'<span class="text-danger">No</span>' ?></td></tr>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
