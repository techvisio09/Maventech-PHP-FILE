<?php
require_once __DIR__ . '/auth_check.php';
$page_title = 'Dashboard';

// ----- Key metrics
$totalSales    = (float)$pdo->query('SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status="paid"')->fetchColumn();
$totalOrders   = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$activeCustomers = (int)$pdo->query('SELECT COUNT(DISTINCT customer_id) FROM orders WHERE payment_status="paid"')->fetchColumn();
$totalCustomers  = (int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
$availableKeys = (int)$pdo->query('SELECT COUNT(*) FROM license_keys WHERE status="available"')->fetchColumn();
$soldKeys      = (int)$pdo->query('SELECT COUNT(*) FROM license_keys WHERE status="sold"')->fetchColumn();

// ----- Revenue last 12 months
$rev = $pdo->query('
  SELECT DATE_FORMAT(created_at,"%Y-%m") AS ym, SUM(total) AS total
  FROM orders
  WHERE payment_status="paid" AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
  GROUP BY ym ORDER BY ym
')->fetchAll();
$labels = []; $values = [];
foreach ($rev as $r) { $labels[] = $r['ym']; $values[] = (float)$r['total']; }

// ----- Recent orders
$recent = $pdo->query('
  SELECT o.*, c.name AS customer_name
  FROM orders o JOIN customers c ON c.id=o.customer_id
  ORDER BY o.created_at DESC LIMIT 8
')->fetchAll();

// ----- Low-stock products
$low = $pdo->query('
  SELECT p.id, p.name, p.low_stock_threshold,
    SUM(lk.status="available") AS available
  FROM products p
  LEFT JOIN license_keys lk ON lk.product_id=p.id
  WHERE p.is_active=1
  GROUP BY p.id
  HAVING available <= p.low_stock_threshold
  ORDER BY available ASC LIMIT 6
')->fetchAll();

include __DIR__ . '/header.php';
include __DIR__ . '/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="mb-0">Dashboard</h4>
    <small class="text-muted">Real-time business overview</small>
  </div>
  <div>
    <a href="<?= BASE_URL ?>/sales.php" class="btn btn-sm btn-primary"><i class="fa fa-chart-line me-1"></i> Sales reports</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-6 col-xl-3">
    <div class="card stat-card ok p-3">
      <div class="d-flex justify-content-between">
        <div>
          <div class="stat-label">Total Sales</div>
          <div class="stat-value"><?= money($totalSales) ?></div>
        </div>
        <div class="stat-icon"><i class="fa fa-sack-dollar"></i></div>
      </div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="card stat-card info p-3">
      <div class="d-flex justify-content-between">
        <div>
          <div class="stat-label">Total Orders</div>
          <div class="stat-value"><?= number_format($totalOrders) ?></div>
        </div>
        <div class="stat-icon"><i class="fa fa-receipt"></i></div>
      </div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="card stat-card p-3">
      <div class="d-flex justify-content-between">
        <div>
          <div class="stat-label">Active Customers</div>
          <div class="stat-value"><?= number_format($activeCustomers) ?></div>
          <small class="text-muted"><?= number_format($totalCustomers) ?> total</small>
        </div>
        <div class="stat-icon"><i class="fa fa-users"></i></div>
      </div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="card stat-card warn p-3">
      <div class="d-flex justify-content-between">
        <div>
          <div class="stat-label">Available Keys</div>
          <div class="stat-value"><?= number_format($availableKeys) ?></div>
          <small class="text-muted"><?= number_format($soldKeys) ?> sold</small>
        </div>
        <div class="stat-icon"><i class="fa fa-key"></i></div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-xl-8">
    <div class="card">
      <div class="card-header">Revenue (last 12 months)</div>
      <div class="card-body">
        <canvas id="revenueChart" height="110"></canvas>
      </div>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card">
      <div class="card-header d-flex justify-content-between">
        <span>Low Inventory Alerts</span>
        <a href="<?= BASE_URL ?>/inventory.php" class="small">View all</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($low)): ?>
          <p class="text-muted text-center py-4 mb-0">All products are well-stocked.</p>
        <?php else: ?>
          <ul class="list-group list-group-flush">
            <?php foreach ($low as $p): ?>
              <li class="list-group-item d-flex justify-content-between align-items-center">
                <div>
                  <div class="fw-semibold"><?= e($p['name']) ?></div>
                  <small class="text-muted">Threshold: <?= (int)$p['low_stock_threshold'] ?></small>
                </div>
                <span class="badge bg-danger"><?= (int)$p['available'] ?> left</span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="card mt-3">
  <div class="card-header d-flex justify-content-between">
    <span>Recent Orders</span>
    <a href="<?= BASE_URL ?>/orders.php" class="small">View all</a>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead>
        <tr>
          <th>Order #</th><th>Customer</th><th>Total</th>
          <th>Payment</th><th>Status</th><th>Date</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($recent)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No orders yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($recent as $o): ?>
          <tr>
            <td class="fw-semibold"><?= e($o['order_number']) ?></td>
            <td><?= e($o['customer_name']) ?></td>
            <td><?= money($o['total']) ?></td>
            <td><span class="badge bg-<?= e($o['payment_status']) ?>"><?= e($o['payment_status']) ?></span></td>
            <td><?= e($o['order_status']) ?></td>
            <td><?= e(date('M j, Y', strtotime($o['created_at']))) ?></td>
            <td><a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>/order_view.php?id=<?= (int)$o['id'] ?>"><i class="fa fa-eye"></i></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
const ctx = document.getElementById('revenueChart');
new Chart(ctx, {
  type: 'line',
  data: {
    labels: <?= json_encode($labels) ?>,
    datasets: [{
      label: 'Revenue',
      data: <?= json_encode($values) ?>,
      borderColor: '#0f172a',
      backgroundColor: 'rgba(250,204,21,.25)',
      tension: .35, fill: true, pointRadius: 4
    }]
  },
  options: {
    plugins:{legend:{display:false}},
    scales:{ y:{ beginAtZero:true, ticks:{ callback:v=>'<?= CURRENCY_SYMBOL ?>'+v }}}
  }
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
