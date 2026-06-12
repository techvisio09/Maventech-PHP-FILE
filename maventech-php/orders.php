<?php
require_once __DIR__ . '/auth_check.php';
$page_title = 'Orders';

// Create order
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='create') {
    verify_csrf();
    $customer_id   = (int)($_POST['customer_id'] ?? 0);
    $payment_method= $_POST['payment_method'] ?? 'card';
    $payment_status= $_POST['payment_status'] ?? 'pending';
    $transaction_id= trim($_POST['transaction_id'] ?? '');
    $items_in      = $_POST['items'] ?? [];

    if (!$customer_id || empty($items_in)) {
        flash_set('error','Customer and at least one product are required.');
        redirect('orders.php');
    }

    $pdo->beginTransaction();
    try {
        $subtotal = 0;
        foreach ($items_in as $it) {
            $p = $pdo->prepare('SELECT * FROM products WHERE id = ?');
            $p->execute([(int)$it['product_id']]);
            $prod = $p->fetch();
            if (!$prod) continue;
            $qty = max(1,(int)$it['quantity']);
            $subtotal += $qty * $prod['price'];
        }
        $tax   = round($subtotal * TAX_RATE, 2);
        $total = $subtotal + $tax;

        $stmt = $pdo->prepare('INSERT INTO orders
          (order_number, customer_id, subtotal, tax, total, currency, payment_method, payment_status, transaction_id, card_statement_name, order_status)
          VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $orderNum = generate_order_number();
        $stmt->execute([
            $orderNum, $customer_id, $subtotal, $tax, $total, CURRENCY,
            $payment_method, $payment_status, $transaction_id,
            COMPANY_STATEMENT_NAME,
            $payment_status==='paid' ? 'completed' : 'processing'
        ]);
        $orderId = (int)$pdo->lastInsertId();

        foreach ($items_in as $it) {
            $pid = (int)$it['product_id']; $qty = max(1,(int)$it['quantity']);
            $p = $pdo->prepare('SELECT * FROM products WHERE id=?'); $p->execute([$pid]);
            $prod = $p->fetch(); if (!$prod) continue;
            for ($i=0; $i<$qty; $i++) {
                $key = ($payment_status==='paid') ? assign_license_to_order($pid, $orderId, $customer_id) : null;
                $line = (float)$prod['price'];
                $ins = $pdo->prepare('INSERT INTO order_items (order_id, product_id, license_key_id, product_name, unit_price, quantity, line_total) VALUES (?,?,?,?,?,?,?)');
                $ins->execute([$orderId, $pid, $key['id'] ?? null, $prod['name'], $prod['price'], 1, $line]);
            }
        }
        $pdo->commit();
        log_activity('create','order',$orderId,$orderNum);

        if ($payment_status==='paid') {
            if (send_license_email($orderId)) flash_set('success','Order created and license email sent.');
            else flash_set('info','Order created. (Email delivery failed - check mail server.)');
        } else {
            flash_set('success','Order created.');
        }
        redirect('order_view.php?id='.$orderId);
    } catch (Exception $e) {
        $pdo->rollBack();
        flash_set('error','Order creation failed: '.$e->getMessage());
        redirect('orders.php');
    }
}

// Filters
$q       = trim($_GET['q'] ?? '');
$status  = $_GET['status'] ?? '';
$where = []; $args = [];
if ($q !== '') { $where[]='(o.order_number LIKE ? OR c.name LIKE ? OR c.email LIKE ? OR o.transaction_id LIKE ?)';
  $args = array_fill(0,4,"%$q%"); }
if ($status !== '') { $where[]='o.payment_status = ?'; $args[]=$status; }
$wsql = $where ? 'WHERE '.implode(' AND ',$where) : '';

$orders = $pdo->prepare("
  SELECT o.*, c.name AS customer_name, c.email AS customer_email
  FROM orders o JOIN customers c ON c.id=o.customer_id
  $wsql ORDER BY o.created_at DESC LIMIT 300");
$orders->execute($args);
$orders = $orders->fetchAll();

$customers = $pdo->query('SELECT id,name,email FROM customers ORDER BY name')->fetchAll();
$products  = $pdo->query('SELECT id,name,price FROM products WHERE is_active=1 ORDER BY name')->fetchAll();

include __DIR__ . '/header.php';
include __DIR__ . '/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">Orders</h4>
  <button class="btn btn-accent btn-sm" data-bs-toggle="modal" data-bs-target="#newOrder">
    <i class="fa fa-plus me-1"></i> New Order
  </button>
</div>

<form class="card p-3 mb-3" method="get">
  <div class="row g-2">
    <div class="col-md-5"><input class="form-control" name="q" placeholder="Search by order#, customer, email, transaction id" value="<?= e($q) ?>"></div>
    <div class="col-md-3">
      <select class="form-select" name="status">
        <option value="">All payment statuses</option>
        <?php foreach (['pending','paid','failed','refunded'] as $s): ?>
          <option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><button class="btn btn-primary w-100"><i class="fa fa-filter me-1"></i>Filter</button></div>
    <div class="col-md-2"><a class="btn btn-outline-secondary w-100" href="orders.php">Reset</a></div>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr>
        <th>Order#</th><th>Customer</th><th>Total</th><th>Payment</th>
        <th>Method</th><th>Txn ID</th><th>Date</th><th></th>
      </tr></thead>
      <tbody>
        <?php if(empty($orders)): ?><tr><td colspan="8" class="text-center text-muted py-4">No orders.</td></tr><?php endif; ?>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td class="fw-semibold"><?= e($o['order_number']) ?></td>
            <td><?= e($o['customer_name']) ?><br><small class="text-muted"><?= e($o['customer_email']) ?></small></td>
            <td><?= money($o['total']) ?></td>
            <td><span class="badge bg-<?= e($o['payment_status']) ?>"><?= e($o['payment_status']) ?></span></td>
            <td><?= e($o['payment_method']) ?></td>
            <td><small class="text-muted"><?= e($o['transaction_id']) ?: '—' ?></small></td>
            <td><?= e(date('M j, Y',strtotime($o['created_at']))) ?></td>
            <td><a class="btn btn-sm btn-outline-secondary" href="order_view.php?id=<?= (int)$o['id'] ?>"><i class="fa fa-eye"></i></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- New Order modal -->
<div class="modal fade" id="newOrder" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
<form method="post">
  <input type="hidden" name="action" value="create">
  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
  <div class="modal-header"><h5 class="modal-title">Create New Order</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Customer</label>
        <select class="form-select" name="customer_id" required>
          <option value="">-- Select customer --</option>
          <?php foreach ($customers as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?> (<?= e($c['email']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Payment Method</label>
        <select class="form-select" name="payment_method">
          <option>card</option><option>paypal</option><option>bank_transfer</option><option>crypto</option><option>manual</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Payment Status</label>
        <select class="form-select" name="payment_status">
          <option value="pending">Pending</option>
          <option value="paid" selected>Paid</option>
          <option value="failed">Failed</option>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Transaction ID</label>
        <input class="form-control" name="transaction_id" placeholder="e.g. ch_3PXXX">
      </div>
      <div class="col-md-6">
        <label class="form-label">Card statement appears as</label>
        <input class="form-control" value="<?= e(COMPANY_STATEMENT_NAME) ?>" disabled>
      </div>
    </div>
    <hr>
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h6 class="mb-0">Line items</h6>
      <button type="button" class="btn btn-sm btn-outline-primary" id="addItemBtn"><i class="fa fa-plus"></i> Add product</button>
    </div>
    <div id="items">
      <div class="row g-2 mb-2 item-row">
        <div class="col-md-8">
          <select class="form-select" name="items[0][product_id]" required>
            <option value="">-- Choose product --</option>
            <?php foreach ($products as $p): ?>
              <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (<?= money($p['price']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3"><input type="number" class="form-control" name="items[0][quantity]" min="1" value="1" required></div>
        <div class="col-md-1"><button type="button" class="btn btn-outline-danger w-100 rm-item"><i class="fa fa-times"></i></button></div>
      </div>
    </div>
    <small class="text-muted">If payment is marked Paid, a license key will be auto-assigned from stock and an email will be sent.</small>
  </div>
  <div class="modal-footer">
    <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
    <button class="btn btn-accent">Create Order</button>
  </div>
</form>
</div></div></div>

<script>
let itemIdx = 1;
document.getElementById('addItemBtn').addEventListener('click', function() {
  const tpl = document.querySelector('.item-row').cloneNode(true);
  tpl.querySelectorAll('select, input').forEach(el=>{
    el.name = el.name.replace(/items\[\d+\]/, 'items['+itemIdx+']');
    if (el.tagName==='SELECT') el.selectedIndex = 0;
    else el.value = 1;
  });
  document.getElementById('items').appendChild(tpl);
  itemIdx++;
});
document.addEventListener('click', function(e){
  if (e.target.closest('.rm-item')) {
    const rows = document.querySelectorAll('.item-row');
    if (rows.length>1) e.target.closest('.item-row').remove();
  }
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
